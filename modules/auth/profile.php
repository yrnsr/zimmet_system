<?php
// modules/auth/profile.php

ob_start();

$page_title = "Profilim";
$page_description = "Hesap bilgilerinizi yönetin";

require_once '../../includes/header.php';

$errors = [];
$success = '';

// Kullanıcı bilgilerini getir
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        setError("Kullanıcı bilgileri bulunamadı");
        header('Location: ../../index.php');
        exit();
    }
    
    // Kullanıcının istatistikleri
    $stats_stmt = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM assignments WHERE assigned_by = ?) as assigned_count,
            (SELECT COUNT(*) FROM assignments WHERE returned_by = ?) as returned_count,
            (SELECT COUNT(*) FROM assignments WHERE assigned_by = ? AND status = 'active') as active_assignments
    ");
    $stats_stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $stats = $stats_stmt->fetch();
    
    // Son işlemler
    $activity_stmt = $db->prepare("
        SELECT 
            'assignment' as type,
            a.assignment_number as reference,
            CONCAT(p.name, ' ', p.surname) as person_name,
            i.name as item_name,
            a.assigned_date as date,
            'Zimmet Verildi' as action
        FROM assignments a
        JOIN personnel p ON a.personnel_id = p.id
        JOIN items i ON a.item_id = i.id
        WHERE a.assigned_by = ?
        
        UNION ALL
        
        SELECT 
            'return' as type,
            a.assignment_number as reference,
            CONCAT(p.name, ' ', p.surname) as person_name,
            i.name as item_name,
            a.return_date as date,
            'Zimmet İade Alındı' as action
        FROM assignments a
        JOIN personnel p ON a.personnel_id = p.id
        JOIN items i ON a.item_id = i.id
        WHERE a.returned_by = ? AND a.return_date IS NOT NULL
        
        ORDER BY date DESC
        LIMIT 10
    ");
    $activity_stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $recent_activity = $activity_stmt->fetchAll();
    
} catch(Exception $e) {
    setError("Profil bilgileri yüklenirken hata oluştu");
    $user = [];
    $stats = ['assigned_count' => 0, 'returned_count' => 0, 'active_assignments' => 0];
    $recent_activity = [];
}

// Profil güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean($_POST['action']);
    
    if ($action === 'update_profile') {
        $profile_data = [
            'username' => clean($_POST['username']),
            'email' => clean($_POST['email'])
        ];
        
        // Validasyon
        if (empty($profile_data['username'])) {
            $errors[] = "Kullanıcı adı boş olamaz";
        }
        
        if (empty($profile_data['email']) || !filter_var($profile_data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Geçerli bir email adresi girin";
        }
        
        // Kullanıcı adı benzersizlik kontrolü
        if (empty($errors)) {
            try {
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$profile_data['username'], $_SESSION['user_id']]);
                if ($stmt->fetch()) {
                    $errors[] = "Bu kullanıcı adı zaten kullanılıyor";
                }
            } catch(Exception $e) {
                $errors[] = "Kullanıcı adı kontrolü yapılamadı";
            }
        }
        
        // Email benzersizlik kontrolü
        if (empty($errors)) {
            try {
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$profile_data['email'], $_SESSION['user_id']]);
                if ($stmt->fetch()) {
                    $errors[] = "Bu email adresi zaten kullanılıyor";
                }
            } catch(Exception $e) {
                $errors[] = "Email kontrolü yapılamadı";
            }
        }
        
        // Güncelle
        if (empty($errors)) {
            try {
                $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, updated_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$profile_data['username'], $profile_data['email'], $_SESSION['user_id']]);
                
                if ($result) {
                    // Session'ı güncelle
                    $_SESSION['username'] = $profile_data['username'];
                    $_SESSION['user_email'] = $profile_data['email'];
                    
                    // User array'ini güncelle
                    $user['username'] = $profile_data['username'];
                    $user['email'] = $profile_data['email'];
                    
                    writeLog("Profile updated by user: " . $profile_data['username']);
                    setSuccess("Profil bilgileriniz başarıyla güncellendi");
                } else {
                    $errors[] = "Profil güncellenemedi";
                }
                
            } catch(Exception $e) {
                $errors[] = "Veritabanı hatası: " . $e->getMessage();
                writeLog("Error updating profile: " . $e->getMessage(), 'error');
            }
        }
        
    } elseif ($action === 'change_password') {
        $current_password = clean($_POST['current_password']);
        $new_password = clean($_POST['new_password']);
        $confirm_password = clean($_POST['confirm_password']);
        
        // Validasyon
        if (empty($current_password)) {
            $errors[] = "Mevcut şifrenizi girin";
        } elseif (!verifyPassword($current_password, $user['password'])) {
            $errors[] = "Mevcut şifre yanlış";
        }
        
        if (empty($new_password)) {
            $errors[] = "Yeni şifre boş olamaz";
        } elseif (strlen($new_password) < 6) {
            $errors[] = "Yeni şifre en az 6 karakter olmalı";
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = "Yeni şifreler uyuşmuyor";
        }
        
        // Şifre güncelle
        if (empty($errors)) {
            try {
                $hashed_password = hashPassword($new_password);
                $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$hashed_password, $_SESSION['user_id']]);
                
                if ($result) {
                    writeLog("Password changed by user: " . $user['username']);
                    setSuccess("Şifreniz başarıyla değiştirildi");
                } else {
                    $errors[] = "Şifre değiştirilemedi";
                }
                
            } catch(Exception $e) {
                $errors[] = "Veritabanı hatası: " . $e->getMessage();
                writeLog("Error changing password: " . $e->getMessage(), 'error');
            }
        }
    }
}
?>

<div class="row">
    <!-- Profil Bilgileri -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body text-center">
                <div class="mb-4">
                    <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center"
                         style="width: 100px; height: 100px; font-size: 3rem;">
                        <i class="fas fa-user"></i>
                    </div>
                </div>
                
                <h4><?= htmlspecialchars($user['username']) ?></h4>
                <p class="text-muted"><?= htmlspecialchars($user['email']) ?></p>
                
                <span class="badge bg-<?= $user['role'] === 'admin' ? 'danger' : ($user['role'] === 'manager' ? 'warning' : 'info') ?> fs-6">
                    <?= strtoupper($user['role']) ?>
                </span>
                
                <hr>
                
                <div class="row text-center">
                    <div class="col-4">
                        <h5 class="text-primary"><?= $stats['assigned_count'] ?></h5>
                        <small class="text-muted">Verilen<br>Zimmet</small>
                    </div>
                    <div class="col-4">
                        <h5 class="text-success"><?= $stats['returned_count'] ?></h5>
                        <small class="text-muted">Alınan<br>Zimmet</small>
                    </div>
                    <div class="col-4">
                        <h5 class="text-warning"><?= $stats['active_assignments'] ?></h5>
                        <small class="text-muted">Aktif<br>Zimmet</small>
                    </div>
                </div>
                
                <hr>
                
                <p class="text-muted mb-1">
                    <i class="fas fa-calendar-alt"></i> 
                    Kayıt: <?= formatDate($user['created_at']) ?>
                </p>
                
                <?php if ($user['updated_at'] && $user['updated_at'] !== $user['created_at']): ?>
                <p class="text-muted mb-0">
                    <i class="fas fa-edit"></i> 
                    Güncelleme: <?= formatDate($user['updated_at']) ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Profil Düzenleme -->
    <div class="col-lg-8">
        <div class="row">
            <!-- Profil Bilgileri Düzenleme -->
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user-edit"></i> Profil Bilgileri
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <h6><i class="fas fa-exclamation-triangle"></i> Hatalar:</h6>
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Kullanıcı Adı</label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?= htmlspecialchars($user['username']) ?>" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?= htmlspecialchars($user['email']) ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Bilgileri Güncelle
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Şifre Değiştirme -->
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-lock"></i> Şifre Değiştir
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="passwordForm">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Mevcut Şifre</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">Yeni Şifre</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" 
                                               minlength="6" required>
                                        <div class="form-text">En az 6 karakter olmalı</div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Yeni Şifre (Tekrar)</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                               minlength="6" required>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-key"></i> Şifre Değiştir
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Son İşlemler -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history"></i> Son İşlemlerim
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_activity)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <h6 class="text-muted">Henüz işlem geçmişi bulunmuyor</h6>
                                <p class="text-muted">Zimmet verme veya iade alma işlemleri burada görünecek.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>İşlem</th>
                                            <th>Zimmet No</th>
                                            <th>Personel</th>
                                            <th>Malzeme</th>
                                            <th>Tarih</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_activity as $activity): ?>
                                        <tr>
                                            <td>
                                                <?php if ($activity['type'] === 'assignment'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-handshake"></i> Zimmet Verildi
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-info">
                                                        <i class="fas fa-undo"></i> İade Alındı
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <code><?= htmlspecialchars($activity['reference']) ?></code>
                                            </td>
                                            <td><?= htmlspecialchars($activity['person_name']) ?></td>
                                            <td><?= htmlspecialchars($activity['item_name']) ?></td>
                                            <td><?= formatDate($activity['date']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="text-center mt-3">
                                <a href="../assignments/index.php" class="btn btn-outline-primary">
                                    <i class="fas fa-list"></i> Tüm İşlemleri Görüntüle
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Şifre formu validasyonu
    const passwordForm = document.getElementById('passwordForm');
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    
    passwordForm.addEventListener('submit', function(e) {
        if (newPassword.value !== confirmPassword.value) {
            e.preventDefault();
            alert('Yeni şifreler uyuşmuyor!');
            confirmPassword.focus();
        }
    });
    
    // Şifre uyumluluğu kontrolü
    confirmPassword.addEventListener('input', function() {
        if (this.value && newPassword.value !== this.value) {
            this.setCustomValidity('Şifreler uyuşmuyor');
        } else {
            this.setCustomValidity('');
        }
    });
    
    newPassword.addEventListener('input', function() {
        if (confirmPassword.value && this.value !== confirmPassword.value) {
            confirmPassword.setCustomValidity('Şifreler uyuşmuyor');
        } else {
            confirmPassword.setCustomValidity('');
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>