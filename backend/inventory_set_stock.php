<?php

$_POST['mode'] = $_POST['mode'] ?? 'count';
if (!isset($_POST['quantity']) && isset($_POST['physical_qty'])) {
    $_POST['quantity'] = $_POST['physical_qty'];
}

require __DIR__ . '/inventory_adjust.php';
