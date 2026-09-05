<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$name = trim((string) ($_POST['name'] ?? ''));
$kgPerSack = (float) ($_POST['kg_per_sack'] ?? 25);

if ($name === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'required']);
    exit;
}

if (mb_strlen($name) > 100) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid']);
    exit;
}

if ($kgPerSack <= 0) {
    $kgPerSack = 25;
}
$kgPerSack = round($kgPerSack, 2);

try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rice_names (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            kg_per_sack DECIMAL(10, 2) NOT NULL DEFAULT 25.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_rice_name (name)
         ) ENGINE=InnoDB'
    );

    $find = $pdo->prepare(
        'SELECT id, name, kg_per_sack FROM rice_names WHERE LOWER(name) = LOWER(?) LIMIT 1'
    );
    $find->execute([$name]);
    $existing = $find->fetch();

    if ($existing) {
        $pdo->prepare('UPDATE rice_names SET kg_per_sack = ? WHERE id = ?')
            ->execute([$kgPerSack, (int) $existing['id']]);
        echo json_encode([
            'ok' => true,
            'name' => $existing['name'],
            'kg_per_sack' => $kgPerSack,
            'created' => false,
        ]);
        exit;
    }

    $insert = $pdo->prepare(
        'INSERT INTO rice_names (name, kg_per_sack) VALUES (?, ?)'
    );
    $insert->execute([$name, $kgPerSack]);

    echo json_encode([
        'ok' => true,
        'name' => $name,
        'kg_per_sack' => $kgPerSack,
        'created' => true,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save']);
}

exit;
