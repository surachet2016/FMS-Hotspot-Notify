<?php
require_once __DIR__ . '/../lib/auth.php';
startAdminSession();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
