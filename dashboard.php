<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
header('Location: ' . ($user['role'] === 'admin' ? 'admin.php' : 'farmer.php'));
exit;
