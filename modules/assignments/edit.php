<?php
// modules/assignments/edit.php

ob_start();

$page_title = "Zimmet Düzenle";

require_once '../../includes/header.php';

// Yetki kontrolü
requirePermission('manager');

// ID kontrolü
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    setError("Geçersiz zimmet ID");
    header('Location: index.php');
    exit();
}

$errors = [];
$form_data = [];

try {
    $db = getDB();
    
    // Mevcut zimmet bilgilerini getir
    $stmt = $db->prepare("
        SELECT 
            a.*,
            CONCAT(p.name, ' ', p.surname) as personnel_name,
            p.sicil_no,
            d.name as department_name,
            i.name as item_name,
            i.item_code,
            i.brand,
            i.model,
            c.name as category_name,
            u1.username as assigned_by_user,
            u2.username as returned_by_user
        FROM assignments a
        JOIN personnel p ON a.personnel_id = p.id
        LEFT JOIN departments d ON p.department_id = d.id
        JOIN items i ON a.item_id = i.id
        LEFT JOIN categories c ON i.category_id = c.id
        LEFT JOIN users u1 ON a.assigned_by = u1.id
        LEFT JOIN users u2 ON a.returned_by = u2.id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $assignment = $stmt->fetch();
    
    if (!$assignment) {
        setError("Zimmet kaydı bulunamadı");
        header('Location: index.php');
        exit();
    }
    
    // Form data başlangıç değerleri
    $form_data = $assignment;
    
} catch(Exception $e) {
    setError("Zimmet bilgileri yüklenirken hata oluştu: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

$page_description = $assignment['assignment_number'] . ' zimmet kaydını düzenleyin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Form verilerini al ve temizle
    $form_data = [
        'assigned_date' => clean($_POST['assigned_date']),
        'assignment_notes' => clean($_POST['assignment_notes']),
        'return_date' => clean($_POST['return_date']),
        'return_notes' => clean($_POST['return_notes']),
        'status' => clean($_POST['status'])
    ];
    
    // Mevcut verileri koru
    $form_data = array_merge($assignment, $form_data);
    
    // Validasyon
    if (empty($form_data['assigned_date'])) {
        $errors[] = "Zimmet tarihi boş olamaz";
    } elseif (!strtotime($form_data['assigned_date'])) {
        $errors[] = "Geçerli bir zimmet tarihi girin";
    }
    
    // İade tarihi kontrolü (eğer girilmişse)
    if (!empty($form_data['return_date'])) {
        if (!strtotime($form_data['return_date'])) {
            $errors[] = "Geçerli bir iade tarihi girin";
        } elseif (strtotime($form_data['return_date']) < strtotime($form_data['assigned_date'])) {
            $errors[] = "İade tarihi zimmet tarihinden önce olamaz";
        }
    }
    
    // Durum kontrolü
    if (!in_array($form_data['status'], ['active', 'returned', 'lost', 'damaged'])) {
        $errors[] = "Geçerli bir durum seçin";
    }
    
    // İade tarihi ve durum uyumluluğu
    if ($form_data['status'] === 'active' && !empty($form_data['return_date'])) {
        $errors[] = "Aktif zimmetlerde iade tarihi olamaz";
    } elseif ($form_data['status'] !== 'active' && empty($form_data['return_date'])) {
        $errors[] = "İade edilmiş zimmetlerde iade tarihi zorunludur";
    }
    
    // Güncelleme işlemi
    if (empty($errors)) {
        try {
            // Transaction başlat
            $db->beginTransaction();
            
            // Zimmet kaydını güncelle
            $sql = "UPDATE assignments SET 
                    assigned_date = ?, 
                    assignment_notes = ?, 
                    return_date = ?, 
                    return_notes = ?, 
                    status = ?, 
                    updated_at = NOW() 
                    WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $form_data['assigned_date'],
                $form_data['assignment_notes'],
                $form_data['return_date'] ?: null,
                $form_data['return_notes'],
                $form_data['status'],
                $id
            ]);
            
            if (!$result) {
                throw new Exception("Zimmet kaydı güncellenemedi");
            }
            
            // Malzeme durumunu güncelle
            $new_item_status = 'available'; // Varsayılan
            switch($form_data['status']) {
                case 'active':
                    $new_item_status = 'assigned';
                    break;
                case 'returned':
                    $new_item_status = 'available';
                    break;
                case 'damaged':
                    $new_item_status = 'broken';
                    break;
                case 'lost':
                    $new_item_status = 'lost';
                    break;
            }
            
            $stmt = $db->prepare("UPDATE items SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_item_status, $assignment['item_id']]);
            
            // Zimmet geçmişi kaydet
            $history_note = 'Zimmet kaydı güncellendi';
            if ($assignment['status'] !== $form_data['status']) {
                $history_note .= ' - Durum değişti: ' . $assignment['status'] . ' → ' . $form_data['status'];
            }
            
            $stmt = $db->prepare("
                INSERT INTO assignment_history (assignment_id, action, old_status, new_status, notes, created_by) 
                VALUES (?, 'updated', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id,
                $assignment['status'],
                $form_data['status'],
                $history_note,
                $_SESSION['user_id']
            ]);
            
            // Transaction'ı tamamla
            $db->commit();
            
            writeLog("Assignment updated: " . $assignment['assignment_number'] . " - Status: " . $form_data['status']);
            setSuccess("Zimmet kaydı başarıyla güncellendi");
            header('Location: view.php?id=' . $id);
            exit();
            
        } catch(Exception $e) {
            // Transaction'ı geri al
            $db->rollback();
            $errors[] = "Güncelleme işlemi sırasında hata oluştu: " . $e->getMessage();
            writeLog("Error updating assignment: " . $e->getMessage(), 'error');
        }
    }
}

// Bugünün tarihi
$today = date('Y-m-d');
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <!-- Zimmet Bilgi Kartı -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-info-circle"></i> Düzenlenecek Zimmet
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Zimmet Bilgileri:</h6>
                        <table class="table table-borderless table-sm">
                            <tr>
                                <td class="fw-bold">Zimmet No:</td>
                                <td><?= htmlspecialchars($assignment['assignment_number']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Personel:</td>
                                <td><?= htmlspecialchars($assignment['personnel_name']) ?> (<?= htmlspecialchars($assignment['sicil_no']) ?>)</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Departman:</td>
                                <td><?= htmlspecialchars($assignment['department_name'] ?: 'Belirtilmemiş') ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Malzeme Bilgileri:</h6>
                        <table class="table table-borderless table-sm">
                            <tr>
                                <td class="fw-bold">Malzeme:</td>
                                <td><?= htmlspecialchars($assignment['item_name']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Kod:</td>
                                <td><?= htmlspecialchars($assignment['item_code']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Marka/Model:</td>
                                <td><?= htmlspecialchars($assignment['brand'] . ' ' . $assignment['model']) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Düzenleme Formu -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-edit"></i> Zimmet Bilgilerini Düzenle
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
                
                <form method="POST" id="editForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="assigned_date" class="form-label">Zimmet Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="assigned_date" name="assigned_date" 
                                       value="<?= htmlspecialchars($form_data['assigned_date']) ?>" 
                                       max="<?= $today ?>" required>
                                <div class="form-text">Malzemenin zimmet verildiği tarih</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="status" class="form-label">Zimmet Durumu <span class="text-danger">*</span></label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" <?= $form_data['status'] === 'active' ? 'selected' : '' ?>>
                                        Aktif - Personelde
                                    </option>
                                    <option value="returned" <?= $form_data['status'] === 'returned' ? 'selected' : '' ?>>
                                        İade Edildi - Normal iade
                                    </option>
                                    <option value="damaged" <?= $form_data['status'] === 'damaged' ? 'selected' : '' ?>>
                                        Hasarlı İade - Bozuk durumda
                                    </option>
                                    <option value="lost" <?= $form_data['status'] === 'lost' ? 'selected' : '' ?>>
                                        Kayıp - Bulunamadı
                                    </option>
                                </select>
                            </div>
                            
                            <div class="mb-3" id="return_date_field">
                                <label for="return_date" class="form-label">İade Tarihi</label>
                                <input type="date" class="form-control" id="return_date" name="return_date" 
                                       value="<?= htmlspecialchars($form_data['return_date'] ?? '') ?>"
                                       min="<?= $form_data['assigned_date'] ?>"
                                       max="<?= $today ?>">
                                <div class="form-text">İade edilmiş zimmetler için zorunlu</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="assignment_notes" class="form-label">Zimmet Notları</label>
                                <textarea class="form-control" id="assignment_notes" name="assignment_notes" rows="4"
                                          placeholder="Zimmet verirken yazılan notlar..."><?= htmlspecialchars($form_data['assignment_notes'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="return_notes" class="form-label">İade Notları</label>
                                <textarea class="form-control" id="return_notes" name="return_notes" rows="4"
                                          placeholder="İade ile ilgili notlar, hasar durumu..."><?= htmlspecialchars($form_data['return_notes'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Durum bilgilendirmesi -->
                    <div id="status-info" class="mb-3"></div>
                    
                    <!-- Uyarı -->
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Dikkat:</strong> Zimmet durumu değiştirildiğinde malzeme durumu da otomatik olarak güncellenecektir.
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between">
                        <a href="view.php?id=<?= $id ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Geri Dön
                        </a>
                        
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
    const form = document.getElementById('editForm');
    const statusField = document.getElementById('status');
    const returnDateField = document.getElementById('return_date');
    const returnDateContainer = document.getElementById('return_date_field');
    const statusInfo = document.getElementById('status-info');
    
    // Form validasyonu
    form.addEventListener('submit', function(e) {
        let isValid = true;
        
        // Gerekli alanları kontrol et
        const requiredFields = ['assigned_date', 'status'];
        requiredFields.forEach(function(fieldName) {
            const field = document.getElementById(fieldName);
            if (!field.value) {
                field.classList.add('is-invalid');
                isValid = false;
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        // Durum ve iade tarihi uyumluluğu
        if (statusField.value === 'active' && returnDateField.value) {
            alert('Aktif zimmetlerde iade tarihi olamaz!');
            isValid = false;
        } else if (statusField.value !== 'active' && !returnDateField.value) {
            alert('İade edilmiş zimmetlerde iade tarihi zorunludur!');
            returnDateField.classList.add('is-invalid');
            isValid = false;
        }
        
        if (!isValid) {
            e.preventDefault();
        }
    });
    
    // Durum değişikliği
    statusField.addEventListener('change', function() {
        updateStatusInfo();
        updateReturnDateField();
    });
    
    // Durum bilgilendirmesi güncelle
    function updateStatusInfo() {
        let infoHtml = '';
        
        switch(statusField.value) {
            case 'active':
                infoHtml = '<div class="alert alert-success"><i class="fas fa-handshake"></i> <strong>Aktif Zimmet:</strong> Malzeme personelde kalacak ve "Zimmetli" durumuna geçecek.</div>';
                break;
            case 'returned':
                infoHtml = '<div class="alert alert-info"><i class="fas fa-undo"></i> <strong>Normal İade:</strong> Malzeme "Müsait" durumuna geçecek ve tekrar zimmet verilebilecek.</div>';
                break;
            case 'damaged':
                infoHtml = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <strong>Hasarlı İade:</strong> Malzeme "Bozuk" durumuna geçecek.</div>';
                break;
            case 'lost':
                infoHtml = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> <strong>Kayıp:</strong> Malzeme "Kayıp" durumuna geçecek.</div>';
                break;
        }
        
        statusInfo.innerHTML = infoHtml;
    }
    
    // İade tarihi alanını güncelle
    function updateReturnDateField() {
        if (statusField.value === 'active') {
            returnDateField.required = false;
            returnDateField.value = '';
            returnDateContainer.style.opacity = '0.5';
        } else {
            returnDateField.required = true;
            returnDateContainer.style.opacity = '1';
            
            // Eğer iade tarihi yoksa bugünü varsayılan yap
            if (!returnDateField.value) {
                returnDateField.value = '<?= $today ?>';
            }
        }
    }
    
    // Sayfa yüklendiğinde mevcut durumu kontrol et
    updateStatusInfo();
    updateReturnDateField();
});
</script>

<?php require_once '../../includes/footer.php'; ?>