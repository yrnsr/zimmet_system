<?php
// includes/header.php

// Output buffering başlat
ob_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

startSession();

// Giriş kontrolü
if (!isLoggedIn()) {
    header('Location: ' . url('modules/auth/login.php'));
    exit();
}

$user_role = $_SESSION['user_role'];
$username = $_SESSION['username'];
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? $page_title . ' - ' : '' ?>Zimmet Takip Sistemi</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #c53535;      /* Daha açık kırmızı / bordo */
            --secondary-color: #4d4d4d;    /* Orta koyulukta gri */
            --sidebar-width: 250px;
        }

        body {
            background-color: #f8f9fa;
        }
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            z-index: 1000;
            transition: all 0.3s;
        }
        
        .sidebar-header {
            padding: 1rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header h4 {
            color: white;
            margin: 0;
            font-size: 1.1rem;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-menu li {
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-menu a {
            display: block;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background-color: rgba(255,255,255,0.1);
            padding-left: 30px;
        }
        .login-logo {
        max-width: 120px;
        margin-bottom: 0.5rem;
        display: block;
        margin-left: auto;
        margin-right: auto;
        }
        .sidebar-menu i {
            width: 20px;
            margin-right: 10px;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 0;
        }
        
        .top-navbar {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 0.5rem 1rem;
            margin-bottom: 2rem;
        }
        
        .content-area {
            padding: 0 2rem 2rem 2rem;
        }
        
        .page-header {
            background: white;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .page-header h1 {
            margin: 0;
            color: #333;
            font-size: 1.5rem;
        }
        
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border: none;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
        }
        
        .stats-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .stats-card .stats-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .stats-card .stats-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .stats-card .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="<?= url('assets/images/tumosan.jpg') ?>" alt="Tümosan Logo" class="login-logo">
        </div>
        
        <ul class="sidebar-menu">
            <li>
                <a href="<?= url('index.php') ?>" class="<?= isActivePage('index.php') ?>">
                    <i class="fas fa-home"></i> Ana Sayfa
                </a>
            </li>
            
            <li>
                <a href="<?= url('modules/personnel/index.php') ?>" class="<?= isActivePage('index.php') ?>">
                    <i class="fas fa-users"></i> Personel Yönetimi
                </a>
            </li>
            
            <li>
                <a href="<?= url('modules/items/index.php') ?>" class="<?= isActivePage('index.php') ?>">
                    <i class="fas fa-boxes"></i> Stok Yönetimi
                </a>
            </li>
            
            <li>
                <a href="<?= url('modules/assignments/index.php') ?>" class="<?= isActivePage('index.php') ?>">
                    <i class="fas fa-handshake"></i> Zimmet İşlemleri
                </a>
            </li>
            
            <?php if (hasPermission('manager')): ?>
            <li>
                <a href="<?= url('modules/reports/index.php') ?>" class="<?= isActivePage('index.php') ?>">
                    <i class="fas fa-chart-bar"></i> Raporlar
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (hasPermission('admin')): ?>
            <li>
                <a href="<?= url('admin/users.php') ?>" class="<?= isActivePage('users.php') ?>">
                    <i class="fas fa-user-cog"></i> Kullanıcı Yönetimi
                </a>
            </li>
            
            <?php endif; ?>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <nav class="navbar navbar-expand-lg top-navbar">
            <div class="container-fluid">
                <button class="btn btn-link d-md-none" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                
                <div class="navbar-nav ms-auto">
                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?= htmlspecialchars($username) ?>
                            <span class="badge bg-primary ms-1"><?= strtoupper($user_role) ?></span>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= url('modules/auth/profile.php') ?>">
                                <i class="fas fa-user-edit"></i> Profil
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= url('modules/auth/logout.php') ?>">
                                <i class="fas fa-sign-out-alt"></i> Çıkış
                            </a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>
        
        <!-- Content Area -->
        <div class="content-area">
            <?php 
            // Alert mesajlarını göster
            $alert = getAlert();
            if ($alert): 
            ?>
                <div class="alert alert-<?= $alert['type'] ?> alert-dismissible fade show" role="alert">
                    <?php
                    $icon = '';
                    switch($alert['type']) {
                        case 'success': $icon = 'fas fa-check-circle'; break;
                        case 'error': $icon = 'fas fa-exclamation-triangle'; break;
                        case 'warning': $icon = 'fas fa-exclamation-circle'; break;
                        case 'info': $icon = 'fas fa-info-circle'; break;
                    }
                    ?>
                    <i class="<?= $icon ?>"></i> <?= htmlspecialchars($alert['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($page_title)): ?>
            <div class="page-header">
                <h1><?= htmlspecialchars($page_title) ?></h1>
                <?php if (isset($page_description)): ?>
                    <p class="text-muted mb-0"><?= htmlspecialchars($page_description) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>