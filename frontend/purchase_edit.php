<?php
$query = $_GET;
$target = '/rice-business/frontend/purchase_new.php';
if ($query !== []) {
    $target .= '?' . http_build_query($query);
}
header('Location: ' . $target);
exit;
