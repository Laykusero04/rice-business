<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_lots.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/products.php');
    exit;
}

$productId = (int) ($_POST['product_id'] ?? 0);
$buyingPriceSack = (float) ($_POST['buying_price_sack'] ?? 0);
$batchLabel = trim((string) ($_POST['batch_label'] ?? ''));
$sacks = (float) ($_POST['sacks'] ?? 0);
$sourcePurchaseId = (int) ($_POST['source_purchase_id'] ?? 0);
$costAmount = isset($_POST['cost_amount']) ? (float) $_POST['cost_amount'] : null;
$redirect = trim((string) ($_POST['redirect'] ?? ''));
if ($redirect === '') {
    $redirect = '/rice-business/frontend/products.php';
}

if ($productId <= 0) {
    header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'error=invalid');
    exit;
}

if ($sacks <= 0) {
    header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'error=sacks');
    exit;
}

ensureSourcePurchaseColumn($pdo);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT id, name, product_type, kg_per_sack, status FROM products WHERE id = ? FOR UPDATE"
    );
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product || ($product['status'] ?? '') !== 'active') {
        throw new RuntimeException('product');
    }

    $kgPerSack = (float) ($product['kg_per_sack'] ?? 25);
    if ($kgPerSack <= 0) {
        $kgPerSack = 25;
    }

    $isRice = ($product['product_type'] ?? 'RICE') === 'RICE';
    $quantity = $isRice
        ? round($sacks * $kgPerSack, 2)
        : round($sacks, 2);

    $totalCost = 0.0;
    $buyingPerKg = 0.0;
    $label = 'Old stock';
    $purchaseDate = date('Y-m-d');
    $linkedPurchaseId = null;

    if ($sourcePurchaseId > 0) {
        $purchaseStmt = $pdo->prepare(
            "SELECT p.id, p.total, p.batch_label, p.purchase_date,
                    COALESCE((
                      SELECT SUM(sl.total_cost)
                      FROM stock_lots sl
                      WHERE sl.source_purchase_id = p.id
                        AND sl.total_cost IS NOT NULL
                    ), 0) AS allocated
             FROM purchases p
             WHERE p.id = ?
             FOR UPDATE"
        );
        $purchaseStmt->execute([$sourcePurchaseId]);
        $purchase = $purchaseStmt->fetch();
        if (!$purchase) {
            throw new RuntimeException('purchase');
        }

        $purchaseTotal = round((float) $purchase['total'], 2);
        $allocated = round((float) $purchase['allocated'], 2);
        $remainingCost = round(max(0, $purchaseTotal - $allocated), 2);

        if ($costAmount !== null && $costAmount > 0) {
            $totalCost = round($costAmount, 2);
        } elseif ($remainingCost > 0) {
            $totalCost = $remainingCost;
        } elseif ($buyingPriceSack > 0) {
            $totalCost = round($sacks * $buyingPriceSack, 2);
        } else {
            $totalCost = $purchaseTotal;
        }

        if ($quantity > 0 && $totalCost > 0) {
            $buyingPerKg = round($totalCost / $quantity, 4);
        } elseif ($buyingPriceSack > 0 && $isRice) {
            $buyingPerKg = round($buyingPriceSack / $kgPerSack, 4);
            if ($totalCost <= 0) {
                $totalCost = round($sacks * $buyingPriceSack, 2);
            }
        }

        $purchaseBatchLabel = trim((string) ($purchase['batch_label'] ?? ''));
        if ($purchaseBatchLabel !== '') {
            $label = $purchaseBatchLabel;
        } elseif ($batchLabel !== '') {
            $label = $batchLabel;
        } else {
            $label = allocateNextBatchLabel($pdo);
        }
        $purchaseDate = $purchase['purchase_date'] ?? $purchaseDate;
        $linkedPurchaseId = $sourcePurchaseId;
    } else {
        if ($batchLabel !== '' && strcasecmp($batchLabel, 'Old stock') !== 0) {
            $label = $batchLabel;
        }
        if ($buyingPriceSack > 0 && $isRice) {
            $buyingPerKg = round($buyingPriceSack / $kgPerSack, 4);
            $totalCost = round($sacks * $buyingPriceSack, 2);
        } elseif ($costAmount !== null && $costAmount > 0) {
            $totalCost = round($costAmount, 2);
            $buyingPerKg = $quantity > 0 ? round($totalCost / $quantity, 4) : 0.0;
        }
    }

    createSellBatch(
        $pdo,
        $productId,
        $buyingPerKg,
        $label,
        $purchaseDate,
        $quantity,
        $totalCost,
        $linkedPurchaseId
    );

    if (preg_match('/^Batch-(\d+)$/', $label, $m)) {
        $usedNo = (int) $m[1];
        $pdo->prepare(
            'UPDATE batch_counters SET next_batch_no = GREATEST(next_batch_no, ?) WHERE id = 1'
        )->execute([$usedNo + 1]);
    }

    $pdo->commit();
    header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'success=batch_added');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'error=save');
    exit;
}
