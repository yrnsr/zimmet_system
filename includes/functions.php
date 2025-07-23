<?php
// includes/functions.php

// Session başlat
function startSession() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
}

// Güvenli string temizleme
function clean($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Kullanıcı giriş kontrolü
function isLoggedIn() {
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Yetki kontrolü
function hasPermission($required_role) {
    startSession();
    if (!isLoggedIn()) {
        return false;
    }
    
    $user_role = $_SESSION['user_role'];
    
    $role_hierarchy = [
        'admin' => 3,
        'manager' => 2,
        'user' => 1
    ];
    
    return $role_hierarchy[$user_role] >= $role_hierarchy[$required_role];
}

// Yetki kontrolü ve yönlendirme
function requirePermission($required_role) {
    if (!hasPermission($required_role)) {
        header('Location: /zimmet_system/modules/auth/login.php');
        exit();
    }
}

// Şifre hash'leme
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Şifre doğrulama
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Benzersiz kod oluşturma
function generateUniqueCode($prefix = '', $length = 6) {
    $numbers = str_pad(mt_rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    return $prefix . $numbers;
}

if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'd.m.Y') {
        if (empty($date) || $date == '0000-00-00') {
            return '-';
        }
        return date($format, strtotime($date));
    }
}
// logActivity Fonksiyonu
function logActivity($user_id, $action) {
    global $db;  // $db PDO bağlantısı global olmalı

    $stmt = $db->prepare("INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$user_id, $action]);
}


// Türkçe tarih formatı
function formatDateTurkish($date) {
    if (empty($date) || $date == '0000-00-00') {
        return '-';
    }
    
    $months = [
        1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
        5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
        9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
    ];
    
    $timestamp = strtotime($date);
    $day = date('d', $timestamp);
    $month = $months[date('n', $timestamp)];
    $year = date('Y', $timestamp);
    
    return $day . ' ' . $month . ' ' . $year;
}

// Alert mesajları
function setAlert($type, $message) {
    startSession();
    $_SESSION['alert'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getAlert() {
    startSession();
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        unset($_SESSION['alert']);
        return $alert;
    }
    return null;
}

// Başarı mesajı
function setSuccess($message) {
    setAlert('success', $message);
}

// Hata mesajı
function setError($message) {
    setAlert('error', $message);
}

// Uyarı mesajı
function setWarning($message) {
    setAlert('warning', $message);
}

// Bilgi mesajı
function setInfo($message) {
    setAlert('info', $message);
}

// Sayfalama
function paginate($total_records, $records_per_page = 20, $current_page = 1) {
    $total_pages = ceil($total_records / $records_per_page);
    $offset = ($current_page - 1) * $records_per_page;
    
    return [
        'total_records' => $total_records,
        'records_per_page' => $records_per_page,
        'current_page' => $current_page,
        'total_pages' => $total_pages,
        'offset' => $offset,
        'has_previous' => $current_page > 1,
        'has_next' => $current_page < $total_pages
    ];
}

// URL oluşturma
function url($path = '') {
    $base_url = 'http://' . $_SERVER['HTTP_HOST'] . '/zimmet_system';
    return $base_url . '/' . ltrim($path, '/');
}

// Aktif sayfa kontrolü
function isActivePage($page) {
    $current_page = basename($_SERVER['PHP_SELF']);
    return $current_page === $page ? 'active' : '';
}

// Dosya yükleme
function uploadFile($file, $upload_dir = 'uploads/', $allowed_types = ['jpg', 'jpeg', 'png', 'pdf']) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Dosya yüklenemedi'];
    }
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_types)) {
        return ['success' => false, 'message' => 'Geçersiz dosya türü'];
    }
    
    $new_filename = uniqid() . '.' . $file_extension;
    $upload_path = $upload_dir . $new_filename;
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        return ['success' => true, 'filename' => $new_filename, 'path' => $upload_path];
    } else {
        return ['success' => false, 'message' => 'Dosya kaydedilemedi'];
    }
}

// Log yazma
function writeLog($message, $type = 'info') {
    $log_dir = 'logs/';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    
    $log_file = $log_dir . 'system_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $user_id = $_SESSION['user_id'] ?? 'unknown';
    
    $log_entry = "[$timestamp] [$type] [User: $user_id] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Sistem ayarı alma
function getSetting($key, $default = '') {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        return $result ? $result['setting_value'] : $default;
    } catch(Exception $e) {
        return $default;
    }
}

// Debug fonksiyonu
function debug($data) {
    echo '<pre>';
    print_r($data);
    echo '</pre>';
}

?>