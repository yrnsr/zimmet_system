<?php
// modules/auth/logout.php

require_once '../../config/database.php';
require_once '../../includes/functions.php';

startSession();

// Log kaydet
if (isLoggedIn()) {
    writeLog("User logged out: " . $_SESSION['username']);
}

// Tüm session verilerini temizle
session_destroy();

// Login sayfasına yönlendir
header('Location: login.php?message=logout');
exit();
?>