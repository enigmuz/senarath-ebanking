<?php
/* GET /api/logout.php — end session. LAB ENVIRONMENT. */
require_once __DIR__ . '/../helpers.php';
logout();
header('Location: /index.php');
