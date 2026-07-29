<?php
session_start();
require_once __DIR__ . '/../app/auth.php';
header('Location: ' . (auth_user() ? 'dashboard.php' : 'login.php'));
exit;
