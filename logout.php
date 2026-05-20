<?php
require_once __DIR__ . '/inc/auth.php';
tr_auth_logout();
header('Location: /login.php');
exit;
