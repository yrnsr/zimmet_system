<?php
// modules/auth/login.php

require_once '../../config/database.php';
require_once '../../includes/functions.php';

startSession();

// Eğer kullanıcı zaten giriş yapmışsa ana sayfaya yönlendir
if (isLoggedIn()) {
    header('Location: ../../index.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean($_POST['username']);
    $password = clean($_POST['password']);
    
    if (empty($username) || empty($password)) {
        $error = 'Kullanıcı adı ve şifre boş olamaz';
    } else {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, username, password, email, role, is_active FROM users WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && verifyPassword($password, $user['password'])) {
                // Giriş başarılı
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_email'] = $user['email'];
                
                // Log kaydet
                writeLog("User logged in: " . $username);
                
                // Ana sayfaya yönlendir
                header('Location: ../../index.php');
                exit();
            } else {
                $error = 'Geçersiz kullanıcı adı veya şifre';
                writeLog("Failed login attempt: " . $username, 'warning');
            }
        } catch(Exception $e) {
            $error = 'Sisteme giriş yapılamadı';
            writeLog("Login error: " . $e->getMessage(), 'error');
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zimmet Takip Sistemi - Giriş</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
    --primary-color: #c53535;      /* Daha açık kırmızı / bordo */
    --secondary-color: #4d4d4d;    /* Orta koyulukta gri */
    --sidebar-width: 250px;
}
        body {
            background: linear-gradient(135deg, #c53535 0%, #4d4d4d 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-container {
            max-width: 400px;
            margin: 0 auto;
        }
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            padding: 2rem;
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-logo {
    max-width: 120px;
    margin-bottom: 1rem;
    display: block;
    margin-left: auto;
    margin-right: auto;

        }
        .btn-login {
            width: 100%;
            padding: 12px;
            font-weight: 600;
            background: linear-gradient(135deg, #c53535 0%, #4d4d4d 100%);
            border: none;
            border-radius: 8px;
        }
        .form-control {
            border-radius: 8px;
            padding: 12px;
            border: 1px solid #ddd;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .alert {
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="login-card">
                <div class="login-header">
                    <img src="<?= url('assets/images/tumosan-logo.png') ?>" alt="Tümosan Logo" class="login-logo">
                    <h3>Zimmet Takip Sistemi</h3>
                    <p class="text-muted">Sisteme giriş yapın</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-triangle"></i> <?= $error ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <label for="username" class="form-label">Kullanıcı Adı</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-user"></i>
                            </span>
                            <input type="text" class="form-control" id="username" name="username" 
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Şifre</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-login">
                        <i class="fas fa-sign-in-alt"></i> Giriş Yap
                    </button>
                </form>
                
                <div class="text-center mt-3">
                    <small class="text-muted">
                        Test kullanıcıları: admin/admin, manager/manager, user/user
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>