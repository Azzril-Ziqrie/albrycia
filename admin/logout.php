<?php
require_once __DIR__ . '/_auth.php';
adminLogout();
header('Location: login.php');
exit;
