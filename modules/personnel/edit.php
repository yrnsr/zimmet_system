<?php
// modules/personnel/edit.php

ob_start();

$page_title = "Personel Düzenle";

require_once '../../includes/header.php';

// Yetki kontrolü
requirePermission('manager');

// ID kontrolü
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    setError("Geçersiz personel ID");
    header('Location: index.php');
    exit();
}

$errors = [];
$form_data = [];

try {
    $db = getDB();
    
    // Mevcut personel bilgilerini getir
    $stmt = $db->prepare("SELECT * FROM personnel WHERE id = ?");
    $stmt->execute([$id]);
    $person = $stmt->fetch();
    
    if (!$person) {
        setError("Personel bulunamadı");
        header('Location: index.php');
        exit();
    }
    
    // Form data başlangıç değerleri
    $form_data = $person;
    
} catch(Exception $e) {
    setError("Personel bilgileri yüklenirken hata oluştu: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

$page_description = $person['name'] . ' ' . $person['surname'] . ' bilgilerini düzenleyin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Form verilerini al ve temizle
    $form_data = [
        'sicil_no' => clean($_POST['sicil_no']),
        'name' => clean($_POST['name']),
        'surname' => clean($_POST['surname']),
        'department_id' => !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null,
        'position' => clean($_POST['position']),
        'email' => clean($_POST['email']),
        'phone' => clean($_POST['phone']),
        'address' => clean($_POST['address']),
        'hire_date' => clean($_POST['hire_date']),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];
    
    // Validasyon
    if (empty($form_data['sicil_no'])) {
        $errors[] = "Sicil numarası boş olamaz";
    }
    
    if (empty($form_data['name'])) {
        $errors[] = "Ad boş olamaz";
    }
    
    if (empty($form_data['surname'])) {
        $errors[] = "Soyad boş olamaz";
    }
    
    if (!empty($form_data['email']) && !filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Geçerli bir email adresi girin";
    }
    
    if (!empty($form_data['hire_date']) && !strtotime($form_data['hire_date'])) {
        $errors[] = "Geçerli bir işe başlama tarihi girin";
    }
    
    // Sicil numarası benzersizlik kontrolü (kendisi hariç)
    if (empty($errors)) {
        try {
            $stmt = $db->prepare("SELECT id FROM personnel WHERE sicil_no = ? AND id != ?");
            $stmt->execute([$form_data['sicil_no'], $id]);
            if ($stmt->fetch()) {
                $errors[] = "Bu sicil numarası zaten kullanılıyor";
            }
        } catch(Exception $e) {
            $errors[] = "Sicil numarası kontrolü yapılamadı";
        }
    }
    
    // Email benzersizlik kontrolü (kendisi hariç)
    if (!empty($form_data['email']) && empty($errors)) {
        try {
            $stmt = $db->prepare("SELECT id FROM personnel WHERE email = ? AND id != ?");
            $stmt->execute([$form_data['email'], $id]);
            if ($stmt->fetch()) {
                $errors[] = "Bu email adresi zaten kullanılıyor";
            }
        } catch(Exception $e) {
            $errors[] = "Email kontrolü yapılamadı";
        }
    }
    
    // Güncelleme işlemi
    if (empty($errors)) {
        try {
            $sql = "UPDATE personnel SET 
                    sicil_no = ?, name = ?, surname = ?, department_id = ?, 
                    position = ?, email = ?, phone = ?, address = ?, 
                    hire_date = ?, is_active = ?, updated_at = NOW() 
                    WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $form_data['sicil_no'],
                $form_data['name'],
                $form_data['surname'],
                $form_data['department_id'],
                $form_data['position'],
                $form_data['email'],
                $form_data['phone'],
                $form_data['address'],
                $form_data['hire_date'],
                $form_data['is_active'],
                $id
            ]);
            
            if ($result) {
                writeLog("Personnel updated: " . $form_data['sicil_no'] . " - " . $form_data['name'] . " " . $form_data['surname']);
                setSuccess("Personel bilgileri başarıyla güncellendi");
                header('Location: view.php?id=' . $id);
                exit();
            } else {
                $errors[] = "Personel güncellenirken hata oluştu";
            }
            
        } catch(Exception $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
            writeLog("Error updating personnel: " . $e->getMessage(), 'error');
        }
    }
}

// Departman listesi
try {
    $dept_stmt = $db->query("SELECT id, name FROM departments ORDER BY name");
    $departments = $dept_stmt->fetchAll();
} catch(Exception $e) {
    $departments = [];
    setError("Departman listesi yüklenemedi");
}
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-user-edit"></i> Personel Bilgilerini Düzenle
                </h5>
            </div>
            
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h6><i class="fas fa-exclamation-triangle"></i> Aşağıdaki hataları düzeltin:</h6>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="personnelEditForm">
                    <div class="row">
                        <!-- Sol Sütun -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="sicil_no" class="form-label">Sicil Numarası <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="sicil_no" name="sicil_no" 
                                       value="<?= htmlspecialchars($form_data['sicil_no']) ?>" required>
                                <div class="form-text">Benzersiz sicil numarası</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="name" class="form-label">Ad <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?= htmlspecialchars($form_data['name']) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="surname" class="form-label">Soyad <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="surname" name="surname" 
                                       value="<?= htmlspecialchars($form_data['surname']) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="department_id" class="form-label">Departman</label>
                                <select class="form-select" id="department_id" name="department_id">
                                    <option value="">Departman Seçin</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?= $dept['id'] ?>" 
                                                <?= ($form_data['department_id'] == $dept['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dept['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="position" class="form-label">Görev/Pozisyon</label>
                                <input type="text" class="form-control" id="position" name="position" 
                                       value="<?= htmlspecialchars($form_data['position']) ?>">
                            </div>
                        </div>
                        
                        <!-- Sağ Sütun -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">E-posta</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?= htmlspecialchars($form_data['email']) ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label">Telefon</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?= htmlspecialchars($form_data['phone']) ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="hire_date" class="form-label">İşe Başlama Tarihi</label>
                                <input type="date" class="form-control" id="hire_date" name="hire_date" 
                                       value="<?= $form_data['hire_date'] ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="address" class="form-label">Adres</label>
                                <textarea class="form-control" id="address" name="address" rows="3"><?= htmlspecialchars($form_data['address']) ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                           <?= $form_data['is_active'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_active">
                                        Aktif Personel
                                    </label>
                                    <div class="form-text">İşten ayrılan personeller için pasif yapın</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Uyarı mesajı -->
                    <?php 
                    // Aktif zimmet kontrolü
                    $active_assignments_count = 0;
                    try {
                        $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignments WHERE personnel_id = ? AND status = 'active'");
                        $stmt->execute([$id]);
                        $active_assignments_count = $stmt->fetch()['count'];
                    } catch(Exception $e) {
                        // Hata durumunda devam et
                    }
                    ?>
                    
                    <?php if ($active_assignments_count > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Dikkat:</strong> Bu personelde <?= $active_assignments_count ?> adet aktif zimmet bulunmaktadır. 
                            Personeli pasif yapmadan önce zimmetlerini iade alınız.
                        </div>
                    <?php endif; ?>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between">
                        <div>
                            <a href="view.php?id=<?= $id ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Geri Dön
                            </a>
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-list"></i> Personel Listesi
                            </a>
                        </div>
                        
                        <div>
                            <button type="reset" class="btn btn-outline-secondary me-2">
                                <i class="fas fa-undo"></i> Sıfırla
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Güncelle
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validasyonu
    const form = document.getElementById('personnelEditForm');
    
    form.addEventListener('submit', function(e) {
        let isValid = true;
        
        // Gerekli alanları kontrol et
        const requiredFields = ['sicil_no', 'name', 'surname'];
        requiredFields.forEach(function(fieldName) {
            const field = document.getElementById(fieldName);
            if (!field.value.trim()) {
                field.classList.add('is-invalid');
                isValid = false;
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        // Email formatı kontrolü
        const emailField = document.getElementById('email');
        if (emailField.value && !emailField.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
            emailField.classList.add('is-invalid');
            isValid = false;
        } else {
            emailField.classList.remove('is-invalid');
        }
        
        if (!isValid) {
            e.preventDefault();
            alert('Lütfen gerekli alanları doğru şekilde doldurun.');
        }
    });
    
    // Aktif durum değişikliği uyarısı
    const activeCheckbox = document.getElementById('is_active');
    const originalActiveState = <?= $person['is_active'] ? 'true' : 'false' ?>;
    const activeAssignmentsCount = <?= $active_assignments_count ?>;
    
    activeCheckbox.addEventListener('change', function() {
        if (originalActiveState && !this.checked && activeAssignmentsCount > 0) {
            if (!confirm('Bu personelde aktif zimmet bulunmaktadır. Yine de pasif yapmak istiyor musunuz?')) {
                this.checked = true;
            }
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>