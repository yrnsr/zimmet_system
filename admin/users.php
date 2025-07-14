<?php
// admin/users.php

ob_start();

$page_title = "Kullanıcı Yönetimi";
$page_description = "Sistem kullanıcılarının yönetimi";

require_once '../includes/header.php';

// Yetki kontrolü - Sadece admin
requirePermission('admin');

$errors = [];
$success = '';

// Kullanıcı ekleme/düzenleme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean($_POST['action']);
    
    if ($action === 'add' || $action === 'edit') {
        $user_data = [
            'username' => clean($_POST['username']),
            'email' => clean($_POST['email']),
            'role' => clean($_POST['role']),
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];
        
        $user_id = $action === 'edit' ? (int)$_POST['user_id'] : 0;
        
        // Validasyon
        if (empty($user_data['username'])) {
            $errors[] = "Kullanıcı adı boş olamaz";
        }
        
        if (empty($user_data['email']) || !filter_var($user_data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Geçerli bir email adresi girin";
        }
        
        if (!in_array($user_data['role'], ['admin', 'manager', 'user'])) {
            $errors[] = "Geçerli bir rol seçin";
        }
        
        // Kullanıcı adı benzersizlik kontrolü
        if (empty($errors)) {
            try {
                $db = getDB();
                if ($action === 'add') {
                    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$user_data['username']]);
                } else {
                    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                    $stmt->execute([$user_data['username'], $user_id]);
                }
                
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
                if ($action === 'add') {
                    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$user_data['email']]);
                } else {
                    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$user_data['email'], $user_id]);
                }
                
                if ($stmt->fetch()) {
                    $errors[] = "Bu email adresi zaten kullanılıyor";
                }
            } catch(Exception $e) {
                $errors[] = "Email kontrolü yapılamadı";
            }
        }
        
        // Kaydet/Güncelle
        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    // Varsayılan şifre
                    $default_password = 'user123';
                    $hashed_password = hashPassword($default_password);
                    
                    $sql = "INSERT INTO users (username, password, email, role, is_active) VALUES (?, ?, ?, ?, ?)";
                    $stmt = $db->prepare($sql);
                    $result = $stmt->execute([
                        $user_data['username'],
                        $hashed_password,
                        $user_data['email'],
                        $user_data['role'],
                        $user_data['is_active']
                    ]);
                    
                    if ($result) {
                        writeLog("New user created: " . $user_data['username']);
                        setSuccess("Kullanıcı başarıyla eklendi. Varsayılan şifre: $default_password");
                    }
                } else {
                    $sql = "UPDATE users SET username = ?, email = ?, role = ?, is_active = ?, updated_at = NOW() WHERE id = ?";
                    $stmt = $db->prepare($sql);
                    $result = $stmt->execute([
                        $user_data['username'],
                        $user_data['email'],
                        $user_data['role'],
                        $user_data['is_active'],
                        $user_id
                    ]);
                    
                    if ($result) {
                        writeLog("User updated: " . $user_data['username']);
                        setSuccess("Kullanıcı başarıyla güncellendi");
                    }
                }
                
                if (!$result) {
                    $errors[] = "Kullanıcı kaydedilemedi";
                }
                
            } catch(Exception $e) {
                $errors[] = "Veritabanı hatası: " . $e->getMessage();
                writeLog("Error managing user: " . $e->getMessage(), 'error');
            }
        }
    } elseif ($action === 'delete') {
        $user_id = (int)$_POST['user_id'];
        
        // Kendi hesabını silmeye çalışıyor mu?
        if ($user_id == $_SESSION['user_id']) {
            $errors[] = "Kendi hesabınızı silemezsiniz";
        } else {
            try {
                $db = getDB();
                
                // Kullanıcının zimmet geçmişi var mı kontrol et
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignments WHERE assigned_by = ? OR returned_by = ?");
                $stmt->execute([$user_id, $user_id]);
                $assignment_count = $stmt->fetch()['count'];
                
                if ($assignment_count > 0) {
                    // Soft delete
                    $stmt = $db->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$user_id]);
                    setSuccess("Kullanıcı pasif yapıldı (zimmet geçmişi olduğu için tamamen silinmedi)");
                } else {
                    // Hard delete
                    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    setSuccess("Kullanıcı başarıyla silindi");
                }
                
                writeLog("User deleted/deactivated: ID " . $user_id);
                
            } catch(Exception $e) {
                $errors[] = "Kullanıcı silinirken hata oluştu: " . $e->getMessage();
                writeLog("Error deleting user: " . $e->getMessage(), 'error');
            }
        }
    } elseif ($action === 'reset_password') {
        $user_id = (int)$_POST['user_id'];
        $new_password = 'user123';
        
        try {
            $db = getDB();
            $hashed_password = hashPassword($new_password);
            
            $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $result = $stmt->execute([$hashed_password, $user_id]);
            
            if ($result) {
                writeLog("Password reset for user ID: " . $user_id);
                setSuccess("Şifre başarıyla sıfırlandı. Yeni şifre: $new_password");
            } else {
                $errors[] = "Şifre sıfırlanamadı";
            }
            
        } catch(Exception $e) {
            $errors[] = "Şifre sıfırlanırken hata oluştu: " . $e->getMessage();
            writeLog("Error resetting password: " . $e->getMessage(), 'error');
        }
    }
}

// Kullanıcı listesi
try {
    $db = getDB();
    $stmt = $db->query("
        SELECT 
            u.*,
            (SELECT COUNT(*) FROM assignments a WHERE a.assigned_by = u.id) as assigned_count,
            (SELECT COUNT(*) FROM assignments a WHERE a.returned_by = u.id) as returned_count
        FROM users u 
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll();
    
} catch(Exception $e) {
    $users = [];
    setError("Kullanıcı listesi yüklenemedi");
}
?>

<!-- Kullanıcı Listesi -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h5 class="card-title mb-0">
                <i class="fas fa-users"></i> Sistem Kullanıcıları
            </h5>
            <small class="text-muted">Toplam <?= count($users) ?> kullanıcı</small>
        </div>
        
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openUserModal('add')">
            <i class="fas fa-plus"></i> Yeni Kullanıcı
        </button>
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
        
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Kullanıcı Adı</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Durum</th>
                        <th>Zimmet İşlemleri</th>
                        <th>Kayıt Tarihi</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($user['username']) ?></strong>
                            <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                <span class="badge bg-primary ms-1">Siz</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td>
                            <span class="badge <?= $user['role'] === 'admin' ? 'bg-danger' : ($user['role'] === 'manager' ? 'bg-warning' : 'bg-info') ?>">
                                <?= strtoupper($user['role']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $user['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $user['is_active'] ? 'Aktif' : 'Pasif' ?>
                            </span>
                        </td>
                        <td>
                            <small class="text-muted">
                                Verilen: <?= $user['assigned_count'] ?><br>
                                Alınan: <?= $user['returned_count'] ?>
                            </small>
                        </td>
                        <td><?= formatDate($user['created_at']) ?></td>
                        <td>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-sm btn-warning" 
                                        onclick="openUserModal('edit', <?= htmlspecialchars(json_encode($user)) ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                
                                <button type="button" class="btn btn-sm btn-info" 
                                        onclick="resetPassword(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                    <i class="fas fa-key"></i>
                                </button>
                                
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <button type="button" class="btn btn-sm btn-danger" 
                                            onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Kullanıcı Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">Kullanıcı Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="userForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" id="userAction" value="add">
                    <input type="hidden" name="user_id" id="userId" value="">
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">Kullanıcı Adı <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label">Rol <span class="text-danger">*</span></label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="user">USER - Sadece görüntüleme</option>
                            <option value="manager">MANAGER - Zimmet işlemleri</option>
                            <option value="admin">ADMIN - Tam yetki</option>
                        </select>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                        <label class="form-check-label" for="is_active">
                            Aktif Kullanıcı
                        </label>
                    </div>
                    
                    <div id="passwordInfo" class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i>
                        Yeni kullanıcı için varsayılan şifre: <strong>user123</strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="userSubmitBtn">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Forms -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="deleteUserId">
</form>

<form id="resetPasswordForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="user_id" id="resetUserId">
</form>

<script>
function openUserModal(action, userData = null) {
    const modal = document.getElementById('userModal');
    const title = document.getElementById('userModalTitle');
    const form = document.getElementById('userForm');
    const actionInput = document.getElementById('userAction');
    const userIdInput = document.getElementById('userId');
    const passwordInfo = document.getElementById('passwordInfo');
    const submitBtn = document.getElementById('userSubmitBtn');
    
    // Form temizle
    form.reset();
    
    if (action === 'add') {
        title.textContent = 'Yeni Kullanıcı Ekle';
        actionInput.value = 'add';
        userIdInput.value = '';
        passwordInfo.style.display = 'block';
        submitBtn.textContent = 'Kullanıcı Ekle';
    } else {
        title.textContent = 'Kullanıcı Düzenle';
        actionInput.value = 'edit';
        userIdInput.value = userData.id;
        passwordInfo.style.display = 'none';
        submitBtn.textContent = 'Güncelle';
        
        // Formu doldur
        document.getElementById('username').value = userData.username;
        document.getElementById('email').value = userData.email;
        document.getElementById('role').value = userData.role;
        document.getElementById('is_active').checked = userData.is_active == 1;
    }
}

function deleteUser(userId, username) {
    if (confirm('Kullanıcı "' + username + '" silinecek. Emin misiniz?')) {
        document.getElementById('deleteUserId').value = userId;
        document.getElementById('deleteForm').submit();
    }
}

function resetPassword(userId, username) {
    if (confirm('Kullanıcı "' + username + '" şifresi sıfırlanacak. Emin misiniz?')) {
        document.getElementById('resetUserId').value = userId;
        document.getElementById('resetPasswordForm').submit();
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>