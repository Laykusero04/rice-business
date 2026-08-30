<?php

/**
 * GCash cash-in / cash-out fee helpers (shop rate card).
 */

function fetchGcashFeeTiers(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, min_amount, max_amount, fee, sort_order
         FROM gcash_fee_tiers
         ORDER BY sort_order ASC, min_amount ASC'
    )->fetchAll();
}

/**
 * Suggested fee for an amount. Returns null if outside rate card.
 */
function lookupGcashSuggestedFee(PDO $pdo, float $amount): ?float
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT fee FROM gcash_fee_tiers
         WHERE ? BETWEEN min_amount AND max_amount
         ORDER BY sort_order ASC
         LIMIT 1'
    );
    $stmt->execute([$amount]);
    $fee = $stmt->fetchColumn();

    if ($fee === false) {
        return null;
    }

    return round((float) $fee, 2);
}

/**
 * @return list<array{min:float,max:float,fee:float}>
 */
function gcashFeeTiersForJs(PDO $pdo): array
{
    $tiers = fetchGcashFeeTiers($pdo);
    $out = [];
    foreach ($tiers as $tier) {
        $out[] = [
            'min' => (float) $tier['min_amount'],
            'max' => (float) $tier['max_amount'],
            'fee' => (float) $tier['fee'],
        ];
    }
    return $out;
}

function normalizeGcashTxnType(string $type): string
{
    return $type === 'cash_out' ? 'cash_out' : 'cash_in';
}

/**
 * Cash-in: customer pays amount + fee in cash; you load amount to their GCash.
 * Cash-out: you give amount in cash; amount + fee is deducted from their GCash (you keep fee).
 */
function gcashMoneySummary(string $txnType, float $amount, float $fee): array
{
    $amount = round($amount, 2);
    $fee = round($fee, 2);
    $txnType = normalizeGcashTxnType($txnType);

    if ($txnType === 'cash_out') {
        return [
            'txn_type' => 'cash_out',
            'amount' => $amount,
            'fee' => $fee,
            'cash_to_customer' => $amount,
            'deduct_from_gcash' => round($amount + $fee, 2),
            'collect_from_customer' => 0.0,
            'total_collected' => round($amount + $fee, 2),
            'fee_income' => $fee,
        ];
    }

    return [
        'txn_type' => 'cash_in',
        'amount' => $amount,
        'fee' => $fee,
        'cash_to_customer' => 0.0,
        'deduct_from_gcash' => 0.0,
        'collect_from_customer' => round($amount + $fee, 2),
        'total_collected' => round($amount + $fee, 2),
        'fee_income' => $fee,
    ];
}
