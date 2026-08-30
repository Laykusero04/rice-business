<?php

/**
 * Batch profit calculations (pesos over kg).
 * Direct sales + mix-attributed sales + write-off shrink.
 */

/**
 * @return list<array<string, mixed>>
 */
function fetchBatchProfitRows(PDO $pdo, ?string $from = null, ?string $to = null): array
{
    $lots = $pdo->query(
        'SELECT sl.*,
                p.name AS product_name,
                p.product_type,
                p.unit,
                p.kg_per_sack,
                s.name AS supplier_name
         FROM stock_lots sl
         INNER JOIN products p ON p.id = sl.product_id
         LEFT JOIN purchase_items pi ON pi.id = sl.purchase_item_id
         LEFT JOIN purchases pu ON pu.id = pi.purchase_id
         LEFT JOIN suppliers s ON s.id = pu.supplier_id
         ORDER BY sl.purchased_at DESC, sl.id DESC'
    )->fetchAll();

    if (count($lots) === 0) {
        return [];
    }

    $saleParams = [];
    $saleDateSql = '';
    if ($from !== null && $to !== null) {
        $saleDateSql = ' AND s.sale_date BETWEEN ? AND ?';
        $saleParams = [$from, $to];
    }

    $saleStmt = $pdo->prepare(
        'SELECT si.stock_lot_id,
                COALESCE(SUM(si.subtotal), 0) AS revenue,
                COALESCE(SUM(si.quantity * COALESCE(si.cost_price, p.buying_price)), 0) AS cogs,
                COALESCE(SUM(si.quantity), 0) AS qty_sold
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         INNER JOIN products p ON p.id = si.product_id
         WHERE si.stock_lot_id IS NOT NULL' . $saleDateSql . '
         GROUP BY si.stock_lot_id'
    );
    $saleStmt->execute($saleParams);
    $salesByLot = [];
    foreach ($saleStmt->fetchAll() as $row) {
        $salesByLot[(int) $row['stock_lot_id']] = $row;
    }

    $woParams = [];
    $woDateSql = '';
    if ($from !== null && $to !== null) {
        $woDateSql = ' WHERE DATE(created_at) BETWEEN ? AND ?';
        $woParams = [$from, $to];
    }

    $woStmt = $pdo->prepare(
        'SELECT stock_lot_id,
                COALESCE(SUM(cost_amount), 0) AS shrink_cost,
                COALESCE(SUM(quantity), 0) AS shrink_qty
         FROM stock_lot_writeoffs' . $woDateSql . '
         GROUP BY stock_lot_id'
    );
    $woStmt->execute($woParams);
    $woByLot = [];
    foreach ($woStmt->fetchAll() as $row) {
        $woByLot[(int) $row['stock_lot_id']] = $row;
    }

    $components = $pdo->query(
        'SELECT mix_lot_id, source_lot_id, cost_share, cost_amount, quantity_used
         FROM stock_lot_components'
    )->fetchAll();

    $componentsByMix = [];
    $mixIdsBySource = [];
    foreach ($components as $comp) {
        $mixId = (int) $comp['mix_lot_id'];
        $sourceId = (int) $comp['source_lot_id'];
        if (!isset($componentsByMix[$mixId])) {
            $componentsByMix[$mixId] = [];
        }
        $componentsByMix[$mixId][] = $comp;
        if (!isset($mixIdsBySource[$sourceId])) {
            $mixIdsBySource[$sourceId] = [];
        }
        $mixIdsBySource[$sourceId][] = $mixId;
    }

    $rows = [];
    foreach ($lots as $lot) {
        $lotId = (int) $lot['id'];
        $lotKind = (string) ($lot['lot_kind'] ?? 'purchase');

        // Mix output lots are shown separately; source attribution goes to parents.
        $direct = $salesByLot[$lotId] ?? ['revenue' => 0, 'cogs' => 0, 'qty_sold' => 0];
        $revenue = (float) $direct['revenue'];
        $cogs = (float) $direct['cogs'];
        $qtySold = (float) $direct['qty_sold'];

        // Attribute mix-child sales back to this source lot
        $attributedRevenue = 0.0;
        $attributedCogs = 0.0;
        $attributedQty = 0.0;
        if ($lotKind !== 'mix') {
            foreach ($mixIdsBySource[$lotId] ?? [] as $mixId) {
                $mixSales = $salesByLot[$mixId] ?? null;
                if (!$mixSales) {
                    continue;
                }
                $share = 0.0;
                foreach ($componentsByMix[$mixId] ?? [] as $comp) {
                    if ((int) $comp['source_lot_id'] === $lotId) {
                        $share = (float) $comp['cost_share'];
                        break;
                    }
                }
                if ($share <= 0) {
                    continue;
                }
                $attributedRevenue += (float) $mixSales['revenue'] * $share;
                $attributedCogs += (float) $mixSales['cogs'] * $share;
                $attributedQty += (float) $mixSales['qty_sold'] * $share;
            }
        }

        $soldRevenue = round($revenue + $attributedRevenue, 2);
        $soldCost = round($cogs + $attributedCogs, 2);
        $soldQty = round($qtySold + $attributedQty, 2);

        $shrink = $woByLot[$lotId] ?? ['shrink_cost' => 0, 'shrink_qty' => 0];
        $shrinkCost = round((float) $shrink['shrink_cost'], 2);
        $shrinkQty = round((float) $shrink['shrink_qty'], 2);

        $invested = $lot['total_cost'] !== null
            ? round((float) $lot['total_cost'], 2)
            : round((float) $lot['quantity_original'] * (float) $lot['buying_price'], 2);

        $remaining = round((float) $lot['quantity_remaining'], 2);
        $remainingValue = round($remaining * (float) $lot['buying_price'], 2);

        $realizedGp = round($soldRevenue - $soldCost, 2);
        $netAfterShrink = round($realizedGp - $shrinkCost, 2);
        $gpPct = $soldRevenue > 0 ? ($realizedGp / $soldRevenue) * 100 : 0.0;

        $isClosed = !empty($lot['closed_at']) || $remaining < LOT_NONE_THRESHOLD;
        $status = 'Open';
        if ($isClosed) {
            $status = 'Closed';
        } elseif (isLotUnsalable($remaining)) {
            $status = 'Crumb';
        } elseif (isLotLow($remaining)) {
            $status = 'Low';
        }

        $batchLabel = trim((string) ($lot['notes'] ?? ''));
        if ($batchLabel === '') {
            $batchLabel = '#' . $lotId;
        }

        $rows[] = [
            'id' => $lotId,
            'batch_label' => $batchLabel,
            'mill_name' => $lot['mill_name'] ?? null,
            'product_name' => $lot['product_name'],
            'product_type' => $lot['product_type'],
            'unit' => $lot['unit'],
            'kg_per_sack' => (float) ($lot['kg_per_sack'] ?? 25),
            'supplier_name' => $lot['supplier_name'] ?? null,
            'purchased_at' => $lot['purchased_at'],
            'lot_kind' => $lotKind,
            'buying_price' => (float) $lot['buying_price'],
            'invested' => $invested,
            'sold_revenue' => $soldRevenue,
            'sold_cost' => $soldCost,
            'sold_qty' => $soldQty,
            'realized_gp' => $realizedGp,
            'gp_pct' => $gpPct,
            'shrink_cost' => $shrinkCost,
            'shrink_qty' => $shrinkQty,
            'net_after_shrink' => $netAfterShrink,
            'remaining' => $remaining,
            'remaining_value' => $remainingValue,
            'status' => $status,
            'closed_at' => $lot['closed_at'] ?? null,
            'direct_revenue' => round($revenue, 2),
            'attributed_revenue' => round($attributedRevenue, 2),
            'is_mix' => $lotKind === 'mix',
        ];
    }

    return $rows;
}

/**
 * Inventory loss (write-offs / close-batch) in a date range.
 */
function fetchInventoryLossTotal(PDO $pdo, string $from, string $to): float
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(cost_amount), 0)
             FROM stock_lot_writeoffs
             WHERE DATE(created_at) BETWEEN ? AND ?'
        );
        $stmt->execute([$from, $to]);
        return round((float) $stmt->fetchColumn(), 2);
    } catch (PDOException $e) {
        return 0.0;
    }
}
