<?php
require_once __DIR__ . '/includes/app_config.php';
header('Location: ' . app_path('login.php'), true, 302);
exit;
