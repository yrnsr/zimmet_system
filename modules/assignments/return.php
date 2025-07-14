<?php
// modules/assignments/return.php

ob_start();

$page_title = "Zimmet İade Al";

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
    
    // Zimmet bilgilerini getir
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
            i.serial_number,
            c.name as category_name,
            u.username as assigned_by_user,
            DATEDIFF(CURRENT_DATE, a.assigned_date) as assignment_days
        FROM assignments a
        JOIN personnel p ON a.personnel_id = p.id
        LEFT JOIN departments d ON p.department_id = d.id
        JOIN items i ON a.item_id = i.id
        LEFT JOIN categories c ON i.category_id = c.id
        LEFT JOIN users u ON a.assigned_by = u.id
        WHERE a.id = ? AND a.status = 'active'
    ");
    $stmt->execute([$id]);
    $assignment = $stmt->fetch();
    
    if (!$assignment) {
        setError("Aktif zimmet kaydı bulunamadı");
        header('Location: index.php');
        exit();
    }
    
} catch(Exception $e) {
    setError("Zimmet bilgileri yüklenirken hata oluştu: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

$page_description = $assignment['assignment_number'] . ' - ' . $assignment['personnel_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Form verilerini al ve temizle
    $form_data = [
        'return_date' => clean($_POST['return_date']),
        'return_status' => clean($_POST['return_status']),
        'return_notes' => clean($_POST['return_notes'])
    ];
    
    // Validasyon
    if (empty($form_data['return_date'])) {
        $errors[] = "İade tarihi boş olamaz";
    } elseif (!strtotime($form_data['return_date'])) {
        $errors[] = "Geçerli bir iade tarihi girin";
    } elseif (strtotime($form_data['return_date']) < strtotime($assignment['assigned_date'])) {
        $errors[] = "İade tarihi zimmet tarihinden önce olamaz";
    } elseif (strtotime($form_data['return_date']) > time()) {
        $errors[] = "İade tarihi gelecek bir tarih olamaz";
    }
    
    if (empty($form_data['return_status'])) {
        $errors[] = "İade durumunu seçmelisiniz";
    }
    
    // İade işlemi
    if (empty($errors)) {
        try {
            // Transaction başlat
            $db->beginTransaction();
            
            // Zimmet kaydını güncelle
            $sql = "UPDATE assignments SET 
                    return_date = ?, 
                    returned_by = ?, 
                    status = ?, 
                    return_notes = ?, 
                    updated_at = NOW() 
                    WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $form_data['return_date'],
                $_SESSION['user_id'],
                $form_data['return_status'],
                $form_data['return_notes'],
                $id
            ]);
            
            if (!$result) {
                throw new Exception("Zimmet kaydı güncellenemedi");
            }
            
            // Malzeme durumunu güncelle
            $new_item_status = 'available'; // Varsayılan
            switch($form_data['return_status']) {
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
            $history_note = '';
            switch($form_data['return_status']) {
                case 'returned':
                    $history_note = 'İade alındı - Normal iade';
                    break;
                case 'damaged':
                    $history_note = 'İade alındı - Hasarlı durumda';
                    break;
                case 'lost':
                    $history_note = 'Kayıp olarak işaretlendi';
                    break;
            }
            
            if ($form_data['return_notes']) {
                $history_note .= ' - ' . $form_data['return_notes'];
            }
            
            $stmt = $db->prepare("
                INSERT INTO assignment_history (assignment_id, action, old_status, new_status, notes, created_by) 
                VALUES (?, 'returned', 'active', ?, ?, ?)
            ");
            $stmt->execute([
                $id,
                $form_data['return_status'],
                $history_note,
                $_SESSION['user_id']
            ]);
            
            // Transaction'ı tamamla
            $db->commit();
            
            writeLog("Assignment returned: " . $assignment['assignment_number'] . " - Status: " . $form_data['return_status']);
            setSuccess("Zimmet başarıyla iade alındı");
            header('Location: view.php?id=' . $id);
            exit();
            
        } catch(Exception $e) {
            // Transaction'ı geri al
            $db->rollback();
            $errors[] = "İade işlemi sırasında hata oluştu: " . $e->getMessage();
            writeLog("Error returning assignment: " . $e->getMessage(), 'error');
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
            <div class="card-header bg-warning text-dark">
                <h5 class="card-title mb-0">
                    <i class="fas fa-undo"></i> İade Alınacak Zimmet
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Zimmet Bilgileri:</h6>
                        <table class="table table-borderless table-sm">
                            <tr>
                                <td class="fw-bold">Malzeme:</td>
                                <td>
                                    <?= htmlspecialchars($assignment['item_name']) ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($assignment['item_code']) ?></small>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Marka/Model:</td>
                                <td>
                                    <?= htmlspecialchars($assignment['brand']) ?>
                                    <?= $assignment['model'] ? ' / ' . htmlspecialchars($assignment['model']) : '' ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <?php if ($assignment['assignment_notes']): ?>
                <div class="mt-3">
                    <h6>Zimmet Notları:</h6>
                    <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($assignment['assignment_notes'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- İade Formu -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-clipboard-check"></i> İade Bilgileri
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
                
                <form method="POST" id="returnForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="return_date" class="form-label">İade Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="return_date" name="return_date" 
                                       value="<?= htmlspecialchars($form_data['return_date'] ?? $today) ?>" 
                                       min="<?= $assignment['assigned_date'] ?>"
                                       max="<?= $today ?>" required>
                                <div class="form-text">
                                    Malzemenin iade alındığı tarih 
                                    (<?= formatDate($assignment['assigned_date']) ?> - <?= formatDate($today) ?> arası)
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="return_status" class="form-label">İade Durumu <span class="text-danger">*</span></label>
                                <select class="form-select" id="return_status" name="return_status" required>
                                    <option value="">İade durumunu seçin</option>
                                    <option value="returned" <?= (isset($form_data['return_status']) && $form_data['return_status'] === 'returned') ? 'selected' : '' ?>>
                                        Normal İade - Malzeme sağlam
                                    </option>
                                    <option value="damaged" <?= (isset($form_data['return_status']) && $form_data['return_status'] === 'damaged') ? 'selected' : '' ?>>
                                        Hasarlı İade - Malzeme bozuk
                                    </option>
                                    <option value="lost" <?= (isset($form_data['return_status']) && $form_data['return_status'] === 'lost') ? 'selected' : '' ?>>
                                        Kayıp - Malzeme bulunamadı
                                    </option>
                                </select>
                                <div class="form-text">Malzemenin iade durumunu belirtin</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="return_notes" class="form-label">İade Notları</label>
                        <textarea class="form-control" id="return_notes" name="return_notes" rows="4"
                                  placeholder="İade ile ilgili özel durumlar, hasar açıklaması, kayıp nedeni..."><?= htmlspecialchars($form_data['return_notes'] ?? '') ?></textarea>
                        <div class="form-text">
                            Özellikle hasarlı veya kayıp durumunda detay bilgi verin
                        </div>
                    </div>
                    
                    <!-- Durum bilgilendirmesi -->
                    <div id="status-info" class="mb-3"></div>
                    
                    <!-- Onay kutusu -->
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirm_return" required>
                        <label class="form-check-label" for="confirm_return">
                            <strong>Bu zimmetin iade alındığını onaylıyorum</strong>
                        </label>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between">
                        <a href="view.php?id=<?= $id ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Geri Dön
                        </a>
                        
                        <div>
                            <button type="reset" class="btn btn-outline-secondary me-2">
                                <i class="fas fa-undo"></i> Temizle
                            </button>
                            <button type="submit" class="btn btn-warning" id="submitBtn">
                                <i class="fas fa-check"></i> İade Al
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Özet Kartı -->
<div class="row justify-content-center mt-4" id="summary-card" style="display: none;">
    <div class="col-lg-8">
        <div class="card border-info">
            <div class="card-header bg-info text-white">
                <h6 class="card-title mb-0">
                    <i class="fas fa-info-circle"></i> İade Özeti
                </h6>
            </div>
            <div class="card-body">
                <div id="summary-content"></div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('returnForm');
    const returnDateField = document.getElementById('return_date');
    const returnStatusField = document.getElementById('return_status');
    const returnNotesField = document.getElementById('return_notes');
    const statusInfo = document.getElementById('status-info');
    const summaryCard = document.getElementById('summary-card');
    const summaryContent = document.getElementById('summary-content');
    const confirmCheck = document.getElementById('confirm_return');
    const submitBtn = document.getElementById('submitBtn');
    
    // Form validasyonu
    form.addEventListener('submit', function(e) {
        let isValid = true;
        
        // Gerekli alanları kontrol et
        const requiredFields = ['return_date', 'return_status'];
        requiredFields.forEach(function(fieldName) {
            const field = document.getElementById(fieldName);
            if (!field.value) {
                field.classList.add('is-invalid');
                isValid = false;
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        // Onay kutusu kontrolü
        if (!confirmCheck.checked) {
            isValid = false;
            alert('İade işlemini onaylamanız gerekiyor.');
        }
        
        // Hasarlı/kayıp durumunda not kontrolü
        if (returnStatusField.value === 'damaged' || returnStatusField.value === 'lost') {
            if (!returnNotesField.value.trim()) {
                returnNotesField.classList.add('is-invalid');
                isValid = false;
                alert('Hasarlı veya kayıp durumunda açıklama yazmanız zorunludur.');
            }
        }
        
        if (!isValid) {
            e.preventDefault();
        } else {
            // Son onay
            const statusText = returnStatusField.options[returnStatusField.selectedIndex].text;
            if (!confirm('Zimmet "' + statusText + '" durumunda iade alınacak. Emin misiniz?')) {
                e.preventDefault();
            }
        }
    });
    
    // Durum değişikliği
    returnStatusField.addEventListener('change', function() {
        updateStatusInfo();
        updateSummary();
        
        // Hasarlı/kayıp durumunda not alanını zorunlu yap
        if (this.value === 'damaged' || this.value === 'lost') {
            returnNotesField.required = true;
            returnNotesField.placeholder = 'Bu durum için açıklama zorunludur...';
        } else {
            returnNotesField.required = false;
            returnNotesField.placeholder = 'İade ile ilgili özel durumlar, notlar...';
        }
    });
    
    // Tarih ve notlar değişikliği
    returnDateField.addEventListener('change', updateSummary);
    returnNotesField.addEventListener('input', updateSummary);
    
    // Durum bilgilendirmesi güncelle
    function updateStatusInfo() {
        let infoHtml = '';
        
        switch(returnStatusField.value) {
            case 'returned':
                infoHtml = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> <strong>Normal İade:</strong> Malzeme "Müsait" durumuna geçecek ve tekrar zimmet verilebilecek.</div>';
                break;
            case 'damaged':
                infoHtml = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <strong>Hasarlı İade:</strong> Malzeme "Bozuk" durumuna geçecek ve tamirden sonra kullanılabilir.</div>';
                break;
            case 'lost':
                infoHtml = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> <strong>Kayıp:</strong> Malzeme "Kayıp" durumuna geçecek ve envanter dışı kalacak.</div>';
                break;
            default:
                infoHtml = '';
        }
        
        statusInfo.innerHTML = infoHtml;
    }
    
    // Özet güncelle
    function updateSummary() {
        if (returnDateField.value && returnStatusField.value) {
            const assignedDate = new Date('<?= $assignment['assigned_date'] ?>');
            const returnDate = new Date(returnDateField.value);
            const daysDiff = Math.ceil((returnDate - assignedDate) / (1000 * 60 * 60 * 24));
            const statusText = returnStatusField.options[returnStatusField.selectedIndex].text;
            
            let summaryHtml = '<div class="row">';
            summaryHtml += '<div class="col-md-6">';
            summaryHtml += '<p class="mb-1"><strong>İade Tarihi:</strong> ' + returnDate.toLocaleDateString('tr-TR') + '</p>';
            summaryHtml += '<p class="mb-1"><strong>Toplam Süre:</strong> ' + daysDiff + ' gün</p>';
            summaryHtml += '</div>';
            summaryHtml += '<div class="col-md-6">';
            summaryHtml += '<p class="mb-1"><strong>İade Durumu:</strong> ' + statusText + '</p>';
            summaryHtml += '<p class="mb-1"><strong>İade Alan:</strong> <?= htmlspecialchars($_SESSION['username']) ?></p>';
            summaryHtml += '</div>';
            summaryHtml += '</div>';
            
            if (returnNotesField.value) {
                summaryHtml += '<hr><p class="mb-0"><strong>Notlar:</strong> ' + returnNotesField.value + '</p>';
            }
            
            summaryContent.innerHTML = summaryHtml;
            summaryCard.style.display = 'block';
        } else {
            summaryCard.style.display = 'none';
        }
    }
    
    // Sayfa yüklendiğinde mevcut değerleri kontrol et
    if (returnStatusField.value) {
        updateStatusInfo();
        updateSummary();
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
                                <td class="fw-bold">Zimmet No:</td>
                                <td><?= htmlspecialchars($assignment['assignment_number']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Veriliş Tarihi:</td>
                                <td><?= formatDate($assignment['assigned_date']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Süre:</td>
                                <td>
                                    <span class="badge bg-info"><?= $assignment['assignment_days'] ?> gün</span>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Veren:</td>
                                <td><?= htmlspecialchars($assignment['assigned_by_user']) ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Personel ve Malzeme:</h6>
                        <table class="table table-borderless table-sm">
                            <tr>
                                <td class="fw-bold">Personel:</td>
                                <td>
                                    <?= htmlspecialchars($assignment['personnel_name']) ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($assignment['sicil_no']) ?></small>
                                </td>
                            </tr>
                            <tr>