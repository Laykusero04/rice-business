<?php
$query = $_GET;
$query['tab'] = 'batch';
header('Location: /rice-business/frontend/reports.php?' . http_build_query($query));
exit;
