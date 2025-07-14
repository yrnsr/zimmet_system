<?php
// modules/personnel/delete.php

ob_start();

require_once '../../config/database.php';
require_once '../../includes/functions.php';

startSession();

// Yetki kontrolü - Sadece admin silebilir
requirePermission('admin');

// ID kontrolü
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    setError("Geçersiz personel ID");
    header('Location: index.php');
    exit();
}

try {
    $db = getDB();
    
    // Personel bilgilerini getir
    $stmt = $db->prepare("SELECT * FROM personnel WHERE id = ?");
    $stmt->execute([$id]);
    $person = $stmt->fetch();
    
    if (!$person) {
        setError("Personel bulunamadı");
        header('Location: index.php');
        exit();
    }
    
    // Aktif zimmet kontrolü
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignments WHERE personnel_id = ? AND status = 'active'");
    $stmt->execute([$id]);
    $active_assignments = $stmt->fetch()['count'];
    
    if ($active_assignments > 0) {
        setError("Bu personelde aktif zimmet bulunduğu için silinemez. Önce zimmetleri iade alınız.");
        header('Location: view.php?id=' . $id);
        exit();
    }
    
    // Silme işlemi onayı
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
        
        // Transaction başlat
        $db->beginTransaction();
        
        try {
            // Önce zimmet geçmişini sil
            $stmt = $db->prepare("DELETE FROM assignment_history WHERE assignment_id IN (SELECT id FROM assignments WHERE personnel_id = ?)");
            $stmt->execute([$id]);
            
            // Sonra zimmet kayıtlarını sil
            $stmt = $db->prepare("DELETE FROM assignments WHERE personnel_id = ?");
            $stmt->execute([$id]);
            
            // Son olarak personeli sil
            $stmt = $db->prepare("DELETE FROM personnel WHERE id = ?");
            $stmt->execute([$id]);
            
            // Transaction'ı tamamla
            $db->commit();
            
            writeLog("Personnel deleted: " . $person['sicil_no'] . " - " . $person['name'] . " " . $person['surname']);
            setSuccess("Personel başarıyla silindi");
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
    writeLog("Error deleting personnel: " . $e->getMessage(), 'error');
    header('Location: index.php');
    exit();
}

// Eğer buraya geldiyse, silme onay sayfasını göster
$page_title = "Personel Sil";
$page_description = "Personel silme işlemini onaylayın";

require_once '../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-exclamation-triangle"></i> Personel Silme Onayı
                </h5>
            </div>
            
            <div class="card-body">
                <div class="alert alert-danger">
                    <h6><i class="fas fa-warning"></i> DİKKAT!</h6>
                    <p class="mb-0">Bu işlem geri alınamaz. Personel silindiğinde:</p>
                    <ul class="mt-2 mb-0">
                        <li>Personel bilgileri tamamen silinecek</li>
                        <li>Zimmet geçmişi silinecek</li>
                        <li>Bu veri kurtarılamayacak</li>
                    </ul>
                </div>
                
                <div class="row">
                    <div class="col-md-4 text-center">
                        <div class="bg-danger text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                             style="width: 80px; height: 80px; font-size: 2rem;">
                            <i class="fas fa-user"></i>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <h5>Silinecek Personel:</h5>
                        <table class="table table-borderless">
                            <tr>
                                <td class="fw-bold">Sicil No:</td>
                                <td><?= htmlspecialchars($person['sicil_no']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Ad Soyad:</td>
                                <td><?= htmlspecialchars($person['name'] . ' ' . $person['surname']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Durum:</td>
                                <td>
                                    <span class="badge <?= $person['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= $person['is_active'] ? 'Aktif' : 'Pasif' ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Kayıt Tarihi:</td>
                                <td><?= formatDate($person['created_at']) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <hr>
                
                <form method="POST" id="deleteForm">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirm_check" required>
                        <label class="form-check-label" for="confirm_check">
                            <strong>Bu personeli kalıcı olarak silmek istediğimi onaylıyorum</strong>
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
    const deleteBtn = document.getElementById('deleteBtn');
    const deleteForm = document.getElementById('deleteForm');
    
    // Checkbox durumuna göre buton aktivasyonu
    confirmCheck.addEventListener('change', function() {
        deleteBtn.disabled = !this.checked;
    });
    
    // Form gönderimi onayı
    deleteForm.addEventListener('submit', function(e) {
        if (!confirmCheck.checked) {
            e.preventDefault();
            alert('Silme işlemini onaylamanız gerekiyor.');
            return;
        }
        
        if (!confirm('Son kez soruyorum: Bu personeli kalıcı olarak silmek istediğinizden emin misiniz?')) {
            e.preventDefault();
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>