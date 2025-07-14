<?php
// modules/assignments/add.php

ob_start();

$page_title = "Yeni Zimmet Ver";
$page_description = "Personele malzeme zimmeti verin";

require_once '../../includes/header.php';

// Yetki kontrolü
requirePermission('manager');

$errors = [];
$form_data = [];

// URL'den gelen parametreler
$pre_selected_personnel = isset($_GET['personnel_id']) ? (int)$_GET['personnel_id'] : 0;
$pre_selected_item = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Form verilerini al ve temizle
    $form_data = [
        'personnel_id' => !empty($_POST['personnel_id']) ? (int)$_POST['personnel_id'] : 0,
        'item_id' => !empty($_POST['item_id']) ? (int)$_POST['item_id'] : 0,
        'assigned_date' => clean($_POST['assigned_date']),
        'assignment_notes' => clean($_POST['assignment_notes'])
    ];
    
    // Validasyon
    if ($form_data['personnel_id'] <= 0) {
        $errors[] = "Personel seçmelisiniz";
    }
    
    if ($form_data['item_id'] <= 0) {
        $errors[] = "Malzeme seçmelisiniz";
    }
    
    if (empty($form_data['assigned_date'])) {
        $errors[] = "Zimmet tarihi boş olamaz";
    } elseif (!strtotime($form_data['assigned_date'])) {
        $errors[] = "Geçerli bir zimmet tarihi girin";
    }
    
    // Personel aktiflik kontrolü
    if ($form_data['personnel_id'] > 0 && empty($errors)) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT is_active FROM personnel WHERE id = ?");
            $stmt->execute([$form_data['personnel_id']]);
            $personnel = $stmt->fetch();
            
            if (!$personnel || !$personnel['is_active']) {
                $errors[] = "Seçilen personel aktif değil";
            }
        } catch(Exception $e) {
            $errors[] = "Personel kontrolü yapılamadı";
        }
    }
    
    // Malzeme müsaitlik kontrolü
    if ($form_data['item_id'] > 0 && empty($errors)) {
        try {
            $stmt = $db->prepare("SELECT status FROM items WHERE id = ?");
            $stmt->execute([$form_data['item_id']]);
            $item = $stmt->fetch();
            
            if (!$item) {
                $errors[] = "Seçilen malzeme bulunamadı";
            } elseif ($item['status'] !== 'available') {
                $errors[] = "Seçilen malzeme müsait değil (Durum: " . $item['status'] . ")";
            }
        } catch(Exception $e) {
            $errors[] = "Malzeme kontrolü yapılamadı";
        }
    }
    
    // Aynı malzemenin aktif zimmet kontrolü
    if ($form_data['item_id'] > 0 && empty($errors)) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignments WHERE item_id = ? AND status = 'active'");
            $stmt->execute([$form_data['item_id']]);
            $active_count = $stmt->fetch()['count'];
            
            if ($active_count > 0) {
                $errors[] = "Bu malzeme zaten başka bir personelde zimmetli";
            }
        } catch(Exception $e) {
            $errors[] = "Zimmet kontrolü yapılamadı";
        }
    }
    
    // Zimmet numarası oluştur
    $assignment_number = '';
    if (empty($errors)) {
        try {
            $prefix = getSetting('assignment_prefix', 'ZMT');
            $year = date('Y');
            
            // Bu yıl için son numara
            $stmt = $db->prepare("
                SELECT assignment_number 
                FROM assignments 
                WHERE assignment_number LIKE ? 
                ORDER BY assignment_number DESC 
                LIMIT 1
            ");
            $stmt->execute([$prefix . $year . '%']);
            $last_assignment = $stmt->fetch();
            
            if ($last_assignment) {
                $last_number = (int)substr($last_assignment['assignment_number'], -3);
                $new_number = $last_number + 1;
            } else {
                $new_number = 1;
            }
            
            $assignment_number = $prefix . $year . str_pad($new_number, 3, '0', STR_PAD_LEFT);
            
        } catch(Exception $e) {
            $errors[] = "Zimmet numarası oluşturulamadı";
        }
    }
    
    // Kaydetme işlemi
    if (empty($errors)) {
        try {
            // Transaction başlat
            $db->beginTransaction();
            
            // Zimmet kaydı oluştur
            $sql = "INSERT INTO assignments (assignment_number, personnel_id, item_id, assigned_date, assigned_by, status, assignment_notes) 
                    VALUES (?, ?, ?, ?, ?, 'active', ?)";
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $assignment_number,
                $form_data['personnel_id'],
                $form_data['item_id'],
                $form_data['assigned_date'],
                $_SESSION['user_id'],
                $form_data['assignment_notes']
            ]);
            
            if (!$result) {
                throw new Exception("Zimmet kaydı oluşturulamadı");
            }
            
            $assignment_id = $db->lastInsertId();
            
            // Malzeme durumunu 'assigned' yap
            $stmt = $db->prepare("UPDATE items SET status = 'assigned', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$form_data['item_id']]);
            
            // Zimmet geçmişi kaydet
            $stmt = $db->prepare("
                INSERT INTO assignment_history (assignment_id, action, new_status, notes, created_by) 
                VALUES (?, 'assigned', 'active', ?, ?)
            ");
            $stmt->execute([
                $assignment_id,
                'Zimmet verildi: ' . $form_data['assignment_notes'],
                $_SESSION['user_id']
            ]);
            
            // Transaction'ı tamamla
            $db->commit();
            
            writeLog("New assignment created: " . $assignment_number . " - Personnel: " . $form_data['personnel_id'] . " - Item: " . $form_data['item_id']);
            setSuccess("Zimmet başarıyla verildi (Zimmet No: $assignment_number)");
            header('Location: view.php?id=' . $assignment_id);
            exit();
            
        } catch(Exception $e) {
            // Transaction'ı geri al
            $db->rollback();
            $errors[] = "Zimmet kaydedilirken hata oluştu: " . $e->getMessage();
            writeLog("Error creating assignment: " . $e->getMessage(), 'error');
        }
    }
}

try {
    $db = getDB();
    
    // Aktif personel listesi
    $personnel_stmt = $db->query("
        SELECT p.id, p.sicil_no, p.name, p.surname, d.name as department_name,
               (SELECT COUNT(*) FROM assignments a WHERE a.personnel_id = p.id AND a.status = 'active') as active_assignments
        FROM personnel p 
        LEFT JOIN departments d ON p.department_id = d.id
        WHERE p.is_active = 1 
        ORDER BY p.name, p.surname
    ");
    $personnel_list = $personnel_stmt->fetchAll();
    
    // Müsait malzemeler
    $items_stmt = $db->query("
        SELECT i.id, i.item_code, i.name, i.brand, i.model, c.name as category_name
        FROM items i 
        LEFT JOIN categories c ON i.category_id = c.id
        WHERE i.status = 'available' 
        ORDER BY i.name, i.item_code
    ");
    $items_list = $items_stmt->fetchAll();
    
} catch(Exception $e) {
    $personnel_list = [];
    $items_list = [];
    setError("Form verileri yüklenemedi");
}

// Bugünün tarihi
$today = date('Y-m-d');
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-handshake"></i> Yeni Zimmet Bilgileri
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
                
                <?php if (empty($personnel_list)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Uyarı:</strong> Aktif personel bulunamadı. 
                        <a href="../personnel/add.php">Önce personel ekleyin</a>.
                    </div>
                <?php elseif (empty($items_list)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Uyarı:</strong> Müsait malzeme bulunamadı. 
                        <a href="../items/add.php">Önce malzeme ekleyin</a>.
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="assignmentForm">
                    <div class="row">
                        <!-- Sol Sütun -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="personnel_id" class="form-label">Personel <span class="text-danger">*</span></label>
                                <select class="form-select" id="personnel_id" name="personnel_id" required>
                                    <option value="">Personel Seçin</option>
                                    <?php foreach ($personnel_list as $person): ?>
                                        <option value="<?= $person['id'] ?>" 
                                                data-assignments="<?= $person['active_assignments'] ?>"
                                                <?= ($pre_selected_personnel == $person['id'] || (isset($form_data['personnel_id']) && $form_data['personnel_id'] == $person['id'])) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($person['name'] . ' ' . $person['surname']) ?> 
                                            (<?= htmlspecialchars($person['sicil_no']) ?>)
                                            <?php if ($person['department_name']): ?>
                                                - <?= htmlspecialchars($person['department_name']) ?>
                                            <?php endif; ?>
                                            <?php if ($person['active_assignments'] > 0): ?>
                                                - [<?= $person['active_assignments'] ?> aktif zimmet]
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Zimmet verilecek personeli seçin</div>
                                <div id="personnel-info" class="mt-2"></div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="assigned_date" class="form-label">Zimmet Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="assigned_date" name="assigned_date" 
                                       value="<?= htmlspecialchars($form_data['assigned_date'] ?? $today) ?>" 
                                       max="<?= $today ?>" required>
                                <div class="form-text">Zimmet verilme tarihi</div>
                            </div>
                        </div>
                        
                        <!-- Sağ Sütun -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="item_id" class="form-label">Malzeme <span class="text-danger">*</span></label>
                                <select class="form-select" id="item_id" name="item_id" required>
                                    <option value="">Malzeme Seçin</option>
                                    <?php foreach ($items_list as $item): ?>
                                        <option value="<?= $item['id'] ?>" 
                                                <?= ($pre_selected_item == $item['id'] || (isset($form_data['item_id']) && $form_data['item_id'] == $item['id'])) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($item['name']) ?> 
                                            (<?= htmlspecialchars($item['item_code']) ?>)
                                            <?php if ($item['brand'] || $item['model']): ?>
                                                - <?= htmlspecialchars($item['brand'] . ' ' . $item['model']) ?>
                                            <?php endif; ?>
                                            <?php if ($item['category_name']): ?>
                                                [<?= htmlspecialchars($item['category_name']) ?>]
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Zimmet verilecek malzemeyi seçin</div>
                                <div id="item-info" class="mt-2"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="assignment_notes" class="form-label">Zimmet Notları</label>
                        <textarea class="form-control" id="assignment_notes" name="assignment_notes" rows="4"
                                  placeholder="Zimmet ile ilgili özel notlar, kullanım amaçları, dikkat edilecek hususlar..."><?= htmlspecialchars($form_data['assignment_notes'] ?? '') ?></textarea>
                        <div class="form-text">Bu notlar zimmet kaydında görünecektir</div>
                    </div>
                    
                    <!-- Uyarı mesajları -->
                    <div id="warnings" class="mb-3"></div>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Geri Dön
                        </a>
                        
                        <div>
                            <button type="reset" class="btn btn-outline-secondary me-2">
                                <i class="fas fa-undo"></i> Temizle
                            </button>
                            <button type="submit" class="btn btn-success" id="submitBtn">
                                <i class="fas fa-handshake"></i> Zimmet Ver
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Önizleme Kartı -->
<div class="row justify-content-center mt-4" id="preview-card" style="display: none;">
    <div class="col-lg-8">
        <div class="card border-success">
            <div class="card-header bg-success text-white">
                <h6 class="card-title mb-0">
                    <i class="fas fa-eye"></i> Zimmet Önizlemesi
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Personel Bilgileri:</h6>
                        <div id="preview-personnel"></div>
                    </div>
                    <div class="col-md-6">
                        <h6>Malzeme Bilgileri:</h6>
                        <div id="preview-item"></div>
                    </div>
                </div>
                <div class="mt-3">
                    <h6>Zimmet Detayları:</h6>
                    <div id="preview-details"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('assignmentForm');
    const personnelSelect = document.getElementById('personnel_id');
    const itemSelect = document.getElementById('item_id');
    const personnelInfo = document.getElementById('personnel-info');
    const warnings = document.getElementById('warnings');
    const previewCard = document.getElementById('preview-card');
    const submitBtn = document.getElementById('submitBtn');
    
    // Form validasyonu
    form.addEventListener('submit', function(e) {
        let isValid = true;
        
        const requiredFields = ['personnel_id', 'item_id', 'assigned_date'];
        requiredFields.forEach(function(fieldName) {
            const field = document.getElementById(fieldName);
            if (!field.value) {
                field.classList.add('is-invalid');
                isValid = false;
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            alert('Lütfen tüm gerekli alanları doldurun.');
        }
    });
    
    // Personel değişikliği
    personnelSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const assignments = selectedOption.getAttribute('data-assignments');
        
        if (this.value && assignments > 0) {
            personnelInfo.innerHTML = '<div class="alert alert-info alert-sm"><i class="fas fa-info-circle"></i> Bu personelde ' + assignments + ' adet aktif zimmet bulunmaktadır.</div>';
        } else {
            personnelInfo.innerHTML = '';
        }
        
        updatePreview();
        checkFormValidity();
    });
    
    // Malzeme değişikliği
    itemSelect.addEventListener('change', function() {
        updatePreview();
        checkFormValidity();
    });
    
    // Tarih ve notlar değişikliği
    document.getElementById('assigned_date').addEventListener('change', updatePreview);
    document.getElementById('assignment_notes').addEventListener('input', updatePreview);
    
    // Önizleme güncelleme
    function updatePreview() {
        const personnelText = personnelSelect.options[personnelSelect.selectedIndex].text;
        const itemText = itemSelect.options[itemSelect.selectedIndex].text;
        const assignedDate = document.getElementById('assigned_date').value;
        const notes = document.getElementById('assignment_notes').value;
        
        if (personnelSelect.value && itemSelect.value) {
            document.getElementById('preview-personnel').innerHTML = '<p class="mb-1"><strong>' + personnelText + '</strong></p>';
            document.getElementById('preview-item').innerHTML = '<p class="mb-1"><strong>' + itemText + '</strong></p>';
            
            let detailsHtml = '<p class="mb-1"><strong>Tarih:</strong> ';
            if (assignedDate) {
                detailsHtml += new Date(assignedDate).toLocaleDateString('tr-TR');
            } else {
                detailsHtml += 'Belirtilmemiş';
            }
            detailsHtml += '</p>';
            detailsHtml += '<p class="mb-1"><strong>Veren:</strong> <?= htmlspecialchars($_SESSION['username']) ?></p>';
            if (notes) {
                detailsHtml += '<p class="mb-1"><strong>Notlar:</strong> ' + notes + '</p>';
            }
            
            document.getElementById('preview-details').innerHTML = detailsHtml;
            previewCard.style.display = 'block';
        } else {
            previewCard.style.display = 'none';
        }
    }
    
    // Form geçerliliği kontrolü
    function checkFormValidity() {
        const isValid = personnelSelect.value && itemSelect.value;
        submitBtn.disabled = !isValid;
        
        warnings.innerHTML = '';
        
        if (isValid) {
            const personnelOption = personnelSelect.options[personnelSelect.selectedIndex];
            const assignments = parseInt(personnelOption.getAttribute('data-assignments'));
            
            if (assignments >= 3) {
                warnings.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i><strong>Dikkat:</strong> Bu personelde ' + assignments + ' adet aktif zimmet bulunmaktadır. Yeni zimmet vermeden önce mevcut zimmetleri kontrol edin.</div>';
            }
        }
    }
    
    // Sayfa yüklendiğinde kontrol et
    checkFormValidity();
    if (personnelSelect.value && itemSelect.value) {
        updatePreview();
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>