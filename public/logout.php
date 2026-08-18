<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
session_destroy();
header("Location: login.php");
exit;
