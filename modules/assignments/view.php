<?php
// modules/assignments/view.php

ob_start();

$page_title = "Zimmet Detayları";

require_once '../../includes/header.php';

// ID kontrolü
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    setError("Geçersiz zimmet ID");
    header('Location: index.php');
    exit();
}

try {
    $db = getDB();
    
    // Zimmet bilgilerini getir
    $stmt = $db->prepare("
        SELECT 
            a.*,
            CONCAT(p.name, ' ', p.surname) as personnel_name,
            p.sicil_no,
            p.email as personnel_email,
            p.phone as personnel_phone,
            d.name as department_name,
            i.name as item_name,
            i.item_code,
            i.brand,
            i.model,
            i.serial_number,
            i.purchase_price,
            c.name as category_name,
            u1.username as assigned_by_user,
            u2.username as returned_by_user,
            DATEDIFF(COALESCE(a.return_date, CURRENT_DATE), a.assigned_date) as assignment_days
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
    
    // Zimmet geçmişi
    $history_stmt = $db->prepare("
        SELECT 
            ah.*,
            u.username as created_by_user
        FROM assignment_history ah
        LEFT JOIN users u ON ah.created_by = u.id
        WHERE ah.assignment_id = ?
        ORDER BY ah.created_at DESC
    ");
    $history_stmt->execute([$id]);
    $history = $history_stmt->fetchAll();
    
} catch(Exception $e) {
    setError("Zimmet bilgileri yüklenirken hata oluştu: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

$page_description = $assignment['assignment_number'] . ' - ' . $assignment['personnel_name'];

// Durum çeviri fonksiyonları
function getAssignmentStatusText($status) {
    $statuses = [
        'active' => 'Aktif',
        'returned' => 'İade Edildi',
        'lost' => 'Kayıp',
        'damaged' => 'Hasarlı'
    ];
    return $statuses[$status] ?? $status;
}

function getAssignmentStatusBadgeClass($status) {
    $classes = [
        'active' => 'bg-success',
        'returned' => 'bg-secondary',
        'lost' => 'bg-danger',
        'damaged' => 'bg-warning'
    ];
    return $classes[$status] ?? 'bg-secondary';
}

function getAssignmentStatusIcon($status) {
    $icons = [
        'active' => 'fas fa-handshake',
        'returned' => 'fas fa-undo',
        'lost' => 'fas fa-exclamation-triangle',
        'damaged' => 'fas fa-tools'
    ];
    return $icons[$status] ?? 'fas fa-circle';
}
?>

<div class="row">
    <!-- Zimmet Genel Bilgileri -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-clipboard-list"></i> Zimmet Bilgileri
                </h5>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="<?= getAssignmentStatusBadgeClass($assignment['status']) ?> text-white rounded-circle d-inline-flex align-items-center justify-content-center"
                         style="width: 80px; height: 80px; font-size: 2rem;">
                        <i class="<?= getAssignmentStatusIcon($assignment['status']) ?>"></i>
                    </div>
                    <h4 class="mt-3 mb-1"><?= htmlspecialchars($assignment['assignment_number']) ?></h4>
                    <span class="badge <?= getAssignmentStatusBadgeClass($assignment['status']) ?> fs-6">
                        <?= getAssignmentStatusText($assignment['status']) ?>
                    </span>
                </div>
                
                <table class="table table-borderless">
                    <tr>
                        <td class="fw-bold text-muted">Veriliş Tarihi:</td>
                        <td><?= formatDate($assignment['assigned_date']) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">İade Tarihi:</td>
                        <td><?= formatDate($assignment['return_date']) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Süre:</td>
                        <td>
                            <?php 
                            $badge_class = '';
                            if ($assignment['assignment_days'] > 365) {
                                $badge_class = 'bg-danger';
                            } elseif ($assignment['assignment_days'] > 180) {
                                $badge_class = 'bg-warning';
                            } else {
                                $badge_class = 'bg-success';
                            }
                            ?>
                            <span class="badge <?= $badge_class ?>"><?= $assignment['assignment_days'] ?> gün</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Veren:</td>
                        <td><?= htmlspecialchars($assignment['assigned_by_user']) ?></td>
                    </tr>
                    <?php if ($assignment['returned_by_user']): ?>
                    <tr>
                        <td class="fw-bold text-muted">İade Alan:</td>
                        <td><?= htmlspecialchars($assignment['returned_by_user']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="fw-bold text-muted">Kayıt Tarihi:</td>
                        <td><?= formatDate($assignment['created_at']) ?></td>
                    </tr>
                </table>
                
                <?php if ($assignment['assignment_notes']): ?>
                <div class="mt-3">
                    <h6 class="fw-bold text-muted">Zimmet Notları:</h6>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($assignment['assignment_notes'])) ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($assignment['return_notes']): ?>
                <div class="mt-3">
                    <h6 class="fw-bold text-muted">İade Notları:</h6>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($assignment['return_notes'])) ?></p>
                </div>
                <?php endif; ?>
                
                <?php if (hasPermission('manager')): ?>
                <div class="d-grid gap-2 mt-4">
                    <?php if ($assignment['status'] === 'active'): ?>
                        <a href="return.php?id=<?= $assignment['id'] ?>" class="btn btn-warning">
                            <i class="fas fa-undo"></i> İade Al
                        </a>
                    <?php endif; ?>
                    
                    <a href="edit.php?id=<?= $assignment['id'] ?>" class="btn btn-info">
                        <i class="fas fa-edit"></i> Düzenle
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Personel ve Malzeme Bilgileri -->
    <div class="col-lg-8">
        <!-- Personel Bilgileri -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-user"></i> Personel Bilgileri
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td class="fw-bold text-muted">Ad Soyad:</td>
                                <td>
                                    <strong><?= htmlspecialchars($assignment['personnel_name']) ?></strong>
                                    <a href="../personnel/view.php?id=<?= $assignment['personnel_id'] ?>" class="btn btn-sm btn-outline-primary ms-2">
                                        <i class="fas fa-eye"></i> Detay
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Sicil No:</td>
                                <td><?= htmlspecialchars($assignment['sicil_no']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Departman:</td>
                                <td>
                                    <span class="badge bg-info">
                                        <?= htmlspecialchars($assignment['department_name'] ?: 'Belirtilmemiş') ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td class="fw-bold text-muted">E-posta:</td>
                                <td>
                                    <?php if ($assignment['personnel_email']): ?>
                                        <a href="mailto:<?= htmlspecialchars($assignment['personnel_email']) ?>">
                                            <?= htmlspecialchars($assignment['personnel_email']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Telefon:</td>
                                <td>
                                    <?php if ($assignment['personnel_phone']): ?>
                                        <a href="tel:<?= htmlspecialchars($assignment['personnel_phone']) ?>">
                                            <?= htmlspecialchars($assignment['personnel_phone']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Malzeme Bilgileri -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-box"></i> Malzeme Bilgileri
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td class="fw-bold text-muted">Malzeme Adı:</td>
                                <td>
                                    <strong><?= htmlspecialchars($assignment['item_name']) ?></strong>
                                    <a href="../items/view.php?id=<?= $assignment['item_id'] ?>" class="btn btn-sm btn-outline-primary ms-2">
                                        <i class="fas fa-eye"></i> Detay
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Malzeme Kodu:</td>
                                <td><code><?= htmlspecialchars($assignment['item_code']) ?></code></td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Kategori:</td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?= htmlspecialchars($assignment['category_name'] ?: 'Kategorisiz') ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Marka/Model:</td>
                                <td>
                                    <?= htmlspecialchars($assignment['brand']) ?>
                                    <?= $assignment['model'] ? ' / ' . htmlspecialchars($assignment['model']) : '' ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td class="fw-bold text-muted">Seri No:</td>
                                <td>
                                    <code><?= htmlspecialchars($assignment['serial_number'] ?: '-') ?></code>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Değer:</td>
                                <td>
                                    <?php if ($assignment['purchase_price']): ?>
                                        <span class="text-success fw-bold">
                                            ₺<?= number_format($assignment['purchase_price'], 2) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Zimmet Geçmişi -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-history"></i> İşlem Geçmişi
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($history)): ?>
                    <div class="text-center py-3">
                        <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                        <p class="text-muted">Henüz işlem geçmişi bulunmuyor.</p>
                    </div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($history as $entry): ?>
                            <div class="timeline-item mb-3">
                                <div class="timeline-marker">
                                    <?php
                                    $action_icon = '';
                                    $action_class = '';
                                    switch($entry['action']) {
                                        case 'assigned':
                                            $action_icon = 'fas fa-handshake';
                                            $action_class = 'text-success';
                                            break;
                                        case 'returned':
                                            $action_icon = 'fas fa-undo';
                                            $action_class = 'text-info';
                                            break;
                                        case 'updated':
                                            $action_icon = 'fas fa-edit';
                                            $action_class = 'text-warning';
                                            break;
                                        case 'cancelled':
                                            $action_icon = 'fas fa-times';
                                            $action_class = 'text-danger';
                                            break;
                                        default:
                                            $action_icon = 'fas fa-circle';
                                            $action_class = 'text-secondary';
                                    }
                                    ?>
                                    <i class="<?= $action_icon ?> <?= $action_class ?>"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1">
                                                <?php
                                                switch($entry['action']) {
                                                    case 'assigned': echo 'Zimmet Verildi'; break;
                                                    case 'returned': echo 'İade Alındı'; break;
                                                    case 'updated': echo 'Güncellendi'; break;
                                                    case 'cancelled': echo 'İptal Edildi'; break;
                                                    default: echo ucfirst($entry['action']);
                                                }
                                                ?>
                                            </h6>
                                            <?php if ($entry['old_status'] && $entry['new_status']): ?>
                                                <p class="mb-1 text-muted">
                                                    <small>
                                                        <?= htmlspecialchars($entry['old_status']) ?> 
                                                        → <?= htmlspecialchars($entry['new_status']) ?>
                                                    </small>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($entry['notes']): ?>
                                                <p class="mb-0 text-muted">
                                                    <small><?= htmlspecialchars($entry['notes']) ?></small>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= formatDate($entry['created_at']) ?><br>
                                            <strong><?= htmlspecialchars($entry['created_by_user']) ?></strong>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="mt-4">
    <a href="index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Zimmet Listesine Dön
    </a>
    
    <?php if (hasPermission('manager')): ?>
        <?php if ($assignment['status'] === 'active'): ?>
            <a href="return.php?id=<?= $assignment['id'] ?>" class="btn btn-warning">
                <i class="fas fa-undo"></i> İade Al
            </a>
        <?php endif; ?>
        
        <a href="edit.php?id=<?= $assignment['id'] ?>" class="btn btn-info">
            <i class="fas fa-edit"></i> Düzenle
        </a>
        
        <a href="print.php?id=<?= $assignment['id'] ?>" target="_blank" class="btn btn-success">
            <i class="fas fa-print"></i> HTML Tutanak
        </a>
        
        <div class="btn-group">
            <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="fas fa-file-excel"></i> Excel Tutanak
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="print_excel.php?id=<?= $assignment['id'] ?>&action=download">
                    <i class="fas fa-download"></i> İndir
                </a></li>
                <li><a class="dropdown-item" href="print_excel.php?id=<?= $assignment['id'] ?>&action=view" target="_blank">
                    <i class="fas fa-eye"></i> Görüntüle
                </a></li>
                <li><a class="dropdown-item" href="#" onclick="saveExcelToServer(<?= $assignment['id'] ?>)">
                    <i class="fas fa-save"></i> Sunucuya Kaydet
                </a></li>
            </ul>
        </div>
        
        <button onclick="printDocument()" class="btn btn-outline-secondary">
            <i class="fas fa-file-pdf"></i> Hızlı Yazdır
        </button>
    <?php endif; ?>
</div>

<script>
function printDocument() {
    window.open('print.php?id=<?= $assignment['id'] ?>&auto_print=1&auto_close=1', '_blank', 'width=800,height=600');
}

function saveExcelToServer(assignmentId) {
    fetch('print_excel.php?id=' + assignmentId + '&action=save')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Excel tutanağı başarıyla sunucuya kaydedildi!\n\nDosya: ' + data.filename);
            } else {
                alert('Hata: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Excel tutanağı kaydedilirken hata oluştu.');
        });
}

// Yeni zimmet oluşturulmuşsa yazdırma popup'ı göster
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('new_assignment') === '1') {
        setTimeout(function() {
            const choice = confirm('Zimmet başarıyla oluşturuldu!\n\nTutanak formatını seçin:\nTamam = HTML Yazdır\nİptal = Excel İndir');
            if (choice) {
                printDocument();
            } else {
                window.open('print_excel.php?id=<?= $assignment['id'] ?>&action=download', '_blank');
            }
        }, 1000);
    }
});
</script>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
}

.timeline-marker {
    position: absolute;
    left: -35px;
    top: 5px;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    border: 2px solid #dee2e6;
    border-radius: 50%;
    font-size: 10px;
}

.timeline-content {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    border-left: 3px solid #007bff;
}

.timeline::before {
    content: '';
    position: absolute;
    left: -26px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

@media print {
    .btn, .card-header, .timeline-marker {
        display: none !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>