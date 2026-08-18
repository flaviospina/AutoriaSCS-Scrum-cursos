<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
header('Location: ' . (auth_user() ? 'dashboard.php' : 'login.php'));
exit;
