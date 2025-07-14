<?php
// modules/items/delete.php

ob_start();

require_once '../../config/database.php';
require_once '../../includes/functions.php';

startSession();

// Yetki kontrolü - Sadece admin silebilir
requirePermission('admin');

// ID kontrolü
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    setError("Geçersiz malzeme ID");
    header('Location: index.php');
    exit();
}

try {
    $db = getDB();
    
    // Malzeme bilgilerini getir
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    
    if (!$item) {
        setError("Malzeme bulunamadı");
        header('Location: index.php');
        exit();
    }
    
    // Aktif zimmet kontrolü
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignments WHERE item_id = ? AND status = 'active'");
    $stmt->execute([$id]);
    $active_assignments = $stmt->fetch()['count'];
    
    if ($active_assignments > 0) {
        setError("Bu malzemede aktif zimmet bulunduğu için silinemez. Önce zimmeti iade alınız.");
        header('Location: view.php?id=' . $id);
        exit();
    }
    
    // Zimmet geçmişi kontrolü
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignments WHERE item_id = ?");
    $stmt->execute([$id]);
    $total_assignments = $stmt->fetch()['count'];
    
    // Silme işlemi onayı
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
        
        // Transaction başlat
        $db->beginTransaction();
        
        try {
            // Önce zimmet geçmişini sil
            $stmt = $db->prepare("DELETE FROM assignment_history WHERE assignment_id IN (SELECT id FROM assignments WHERE item_id = ?)");
            $stmt->execute([$id]);
            
            // Sonra zimmet kayıtlarını sil
            $stmt = $db->prepare("DELETE FROM assignments WHERE item_id = ?");
            $stmt->execute([$id]);
            
            // Son olarak malzemeyi sil
            $stmt = $db->prepare("DELETE FROM items WHERE id = ?");
            $stmt->execute([$id]);
            
            // Transaction'ı tamamla
            $db->commit();
            
            writeLog("Item deleted: " . $item['item_code'] . " - " . $item['name']);
            setSuccess("Malzeme başarıyla silindi");
            header('Location: index.php');
            exit();
            
        } catch(Exception $e) {
            // Transaction'ı geri al
            $db->rollback();
            throw $e;
        }
    }
    
} catch(Exception $e) {
    setError("İşlem sırasında hata oluştu: " . $e->getMessage());
    writeLog("Error deleting item: " . $e->getMessage(), 'error');
    header('Location: index.php');
    exit();
}

// Durum çeviri fonksiyonları
function getStatusText($status) {
    $statuses = [
        'available' => 'Müsait',
        'assigned' => 'Zimmetli',
        'maintenance' => 'Bakımda',
        'broken' => 'Bozuk',
        'lost' => 'Kayıp'
    ];
    return $statuses[$status] ?? $status;
}

// Eğer buraya geldiyse, silme onay sayfasını göster
$page_title = "Malzeme Sil";
$page_description = "Malzeme silme işlemini onaylayın";

require_once '../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-exclamation-triangle"></i> Malzeme Silme Onayı
                </h5>
            </div>
            
            <div class="card-body">
                <div class="alert alert-danger">
                    <h6><i class="fas fa-warning"></i> DİKKAT!</h6>
                    <p class="mb-0">Bu işlem geri alınamaz. Malzeme silindiğinde:</p>
                    <ul class="mt-2 mb-0">
                        <li>Malzeme bilgileri tamamen silinecek</li>
                        <li>Zimmet geçmişi silinecek</li>
                        <li>Bu veri kurtarılamayacak</li>
                    </ul>
                </div>
                
                <div class="row">
                    <div class="col-md-4 text-center">
                        <div class="bg-danger text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                             style="width: 80px; height: 80px; font-size: 2rem;">
                            <i class="fas fa-box"></i>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <h5>Silinecek Malzeme:</h5>
                        <table class="table table-borderless">
                            <tr>
                                <td class="fw-bold">Malzeme Kodu:</td>
                                <td><?= htmlspecialchars($item['item_code']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Malzeme Adı:</td>
                                <td><?= htmlspecialchars($item['name']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Marka/Model:</td>
                                <td>
                                    <?= htmlspecialchars($item['brand']) ?>
                                    <?= $item['model'] ? ' / ' . htmlspecialchars($item['model']) : '' ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Durum:</td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?= getStatusText($item['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Zimmet Geçmişi:</td>
                                <td>
                                    <span class="badge bg-info"><?= $total_assignments ?> kayıt</span>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Kayıt Tarihi:</td>
                                <td><?= formatDate($item['created_at']) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <?php if ($total_assignments > 0): ?>
                <div class="alert alert-warning mt-3">
                    <i class="fas fa-info-circle"></i>
                    <strong>Bilgi:</strong> Bu malzemenin <?= $total_assignments ?> adet zimmet geçmişi bulunmaktadır. 
                    Malzeme silindiğinde bu geçmiş de kaybolacaktır.
                </div>
                <?php endif; ?>
                
                <hr>
                
                <form method="POST" id="deleteForm">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirm_check" required>
                        <label class="form-check-label" for="confirm_check">
                            <strong>Bu malzemeyi kalıcı olarak silmek istediğimi onaylıyorum</strong>
                        </label>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="understand_check" required>
                        <label class="form-check-label" for="understand_check">
                            <strong>Zimmet geçmişinin de silineceğini anlıyorum</strong>
                        </label>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="view.php?id=<?= $id ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> İptal Et
                        </a>
                        
                        <button type="submit" name="confirm_delete" class="btn btn-danger" id="deleteBtn" disabled>
                            <i class="fas fa-trash"></i> Evet, Sil
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const confirmCheck = document.getElementById('confirm_check');
    const understandCheck = document.getElementById('understand_check');
    const deleteBtn = document.getElementById('deleteBtn');
    const deleteForm = document.getElementById('deleteForm');
    
    // Checkbox durumuna göre buton aktivasyonu
    function updateButtonState() {
        deleteBtn.disabled = !(confirmCheck.checked && understandCheck.checked);
    }
    
    confirmCheck.addEventListener('change', updateButtonState);
    understandCheck.addEventListener('change', updateButtonState);
    
    // Form gönderimi onayı
    deleteForm.addEventListener('submit', function(e) {
        if (!confirmCheck.checked || !understandCheck.checked) {
            e.preventDefault();
            alert('Silme işlemini onaylamanız gerekiyor.');
            return;
        }
        
        if (!confirm('Son kez soruyorum: Bu malzemeyi kalıcı olarak silmek istediğinizden emin misiniz?')) {
            e.preventDefault();
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>