<?php

require_once __DIR__ . '/backend/auth.php';

if (isLoggedIn()) {
    header('Location: /rice-business/frontend/dashboard.php');
} else {
    header('Location: /rice-business/frontend/login.php');
}
exit;
