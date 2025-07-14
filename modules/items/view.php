<?php
// modules/items/view.php

ob_start();

$page_title = "Malzeme Detayları";

require_once '../../includes/header.php';

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
    $stmt = $db->prepare("
        SELECT 
            i.*,
            c.name as category_name
        FROM items i 
        LEFT JOIN categories c ON i.category_id = c.id 
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    
    if (!$item) {
        setError("Malzeme bulunamadı");
        header('Location: index.php');
        exit();
    }
    
    // Malzemenin zimmet geçmişi
    $assignments_stmt = $db->prepare("
        SELECT 
            a.*,
            CONCAT(p.name, ' ', p.surname) as personnel_name,
            p.sicil_no,
            u1.username as assigned_by_user,
            u2.username as returned_by_user
        FROM assignments a
        JOIN personnel p ON a.personnel_id = p.id
        LEFT JOIN users u1 ON a.assigned_by = u1.id
        LEFT JOIN users u2 ON a.returned_by = u2.id
        WHERE a.item_id = ?
        ORDER BY a.assigned_date DESC
    ");
    $assignments_stmt->execute([$id]);
    $assignments = $assignments_stmt->fetchAll();
    
    // Aktif zimmet
    $active_assignment = null;
    foreach ($assignments as $assignment) {
        if ($assignment['status'] === 'active') {
            $active_assignment = $assignment;
            break;
        }
    }
    
    // İstatistikler
    $stats = [
        'total_assignments' => count($assignments),
        'total_days_assigned' => 0,
        'current_assignment_days' => 0
    ];
    
    // Toplam zimmet gün sayısı hesapla
    foreach ($assignments as $assignment) {
        $start = strtotime($assignment['assigned_date']);
        $end = $assignment['return_date'] ? strtotime($assignment['return_date']) : time();
        $days = floor(($end - $start) / (60 * 60 * 24));
        $stats['total_days_assigned'] += $days;
        
        if ($assignment['status'] === 'active') {
            $stats['current_assignment_days'] = $days;
        }
    }
    
} catch(Exception $e) {
    setError("Malzeme bilgileri yüklenirken hata oluştu: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

$page_description = $item['name'] . ' - ' . $item['item_code'];

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

function getStatusBadgeClass($status) {
    $classes = [
        'available' => 'bg-success',
        'assigned' => 'bg-warning',
        'maintenance' => 'bg-info',
        'broken' => 'bg-danger',
        'lost' => 'bg-dark'
    ];
    return $classes[$status] ?? 'bg-secondary';
}

function getStatusIcon($status) {
    $icons = [
        'available' => 'fas fa-check-circle',
        'assigned' => 'fas fa-handshake',
        'maintenance' => 'fas fa-tools',
        'broken' => 'fas fa-exclamation-triangle',
        'lost' => 'fas fa-question-circle'
    ];
    return $icons[$status] ?? 'fas fa-circle';
}
?>

<div class="row">
    <!-- Malzeme Bilgileri -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-box"></i> Malzeme Bilgileri
                </h5>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="<?= getStatusBadgeClass($item['status']) ?> text-white rounded-circle d-inline-flex align-items-center justify-content-center"
                         style="width: 80px; height: 80px; font-size: 2rem;">
                        <i class="<?= getStatusIcon($item['status']) ?>"></i>
                    </div>
                    <h4 class="mt-3 mb-1"><?= htmlspecialchars($item['name']) ?></h4>
                    <p class="text-muted mb-2">Kod: <?= htmlspecialchars($item['item_code']) ?></p>
                    <span class="badge <?= getStatusBadgeClass($item['status']) ?> fs-6">
                        <?= getStatusText($item['status']) ?>
                    </span>
                </div>
                
                <table class="table table-borderless">
                    <tr>
                        <td class="fw-bold text-muted">Kategori:</td>
                        <td>
                            <span class="badge bg-info">
                                <?= htmlspecialchars($item['category_name'] ?: 'Kategorisiz') ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Marka:</td>
                        <td><?= htmlspecialchars($item['brand'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Model:</td>
                        <td><?= htmlspecialchars($item['model'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Seri No:</td>
                        <td>
                            <code><?= htmlspecialchars($item['serial_number'] ?: '-') ?></code>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Konum:</td>
                        <td>
                            <i class="fas fa-map-marker-alt text-muted"></i>
                            <?= htmlspecialchars($item['location'] ?: '-') ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Satın Alma:</td>
                        <td><?= formatDate($item['purchase_date']) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Fiyat:</td>
                        <td>
                            <?php if ($item['purchase_price']): ?>
                                <span class="text-success fw-bold">
                                    ₺<?= number_format($item['purchase_price'], 2) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Garanti:</td>
                        <td>
                            <?php if ($item['warranty_end_date']): ?>
                                <?php 
                                $warranty_end = strtotime($item['warranty_end_date']);
                                $today = time();
                                $is_expired = $warranty_end < $today;
                                ?>
                                <span class="<?= $is_expired ? 'text-danger' : 'text-success' ?>">
                                    <?= formatDate($item['warranty_end_date']) ?>
                                    <?php if ($is_expired): ?>
                                        <i class="fas fa-exclamation-triangle" title="Garanti süresi dolmuş"></i>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Kayıt Tarihi:</td>
                        <td><?= formatDate($item['created_at']) ?></td>
                    </tr>
                </table>
                
                <?php if ($item['description']): ?>
                <div class="mt-3">
                    <h6 class="fw-bold text-muted">Açıklama:</h6>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($item['description'])) ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($item['notes']): ?>
                <div class="mt-3">
                    <h6 class="fw-bold text-muted">Notlar:</h6>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($item['notes'])) ?></p>
                </div>
                <?php endif; ?>
                
                <?php if (hasPermission('manager')): ?>
                <div class="d-grid gap-2 mt-4">
                    <a href="edit.php?id=<?= $item['id'] ?>" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Düzenle
                    </a>
                    
                    <?php if ($item['status'] === 'available'): ?>
                        <a href="../assignments/add.php?item_id=<?= $item['id'] ?>" class="btn btn-success">
                            <i class="fas fa-handshake"></i> Zimmet Ver
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Zimmet Bilgileri ve İstatistikler -->
    <div class="col-lg-8">
        <!-- İstatistikler -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-icon text-primary">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stats-number text-primary"><?= $stats['total_assignments'] ?></div>
                    <div class="stats-label">Toplam Zimmet</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-icon text-info">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stats-number text-info"><?= $stats['total_days_assigned'] ?></div>
                    <div class="stats-label">Toplam Gün</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-icon text-warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stats-number text-warning"><?= $stats['current_assignment_days'] ?></div>
                    <div class="stats-label">Aktif Gün</div>
                </div>
            </div>
        </div>
        
        <!-- Aktif Zimmet -->
        <?php if ($active_assignment): ?>
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="card-title mb-0">
                    <i class="fas fa-exclamation-circle"></i> Aktif Zimmet
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td class="fw-bold">Zimmet No:</td>
                                <td><?= htmlspecialchars($active_assignment['assignment_number']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Personel:</td>
                                <td>
                                    <strong><?= htmlspecialchars($active_assignment['personnel_name']) ?></strong><br>
                                    <small class="text-muted">Sicil: <?= htmlspecialchars($active_assignment['sicil_no']) ?></small>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Veriliş Tarihi:</td>
                                <td><?= formatDate($active_assignment['assigned_date']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Süre:</td>
                                <td>
                                    <span class="badge bg-primary"><?= $stats['current_assignment_days'] ?> gün</span>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td class="fw-bold">Veren:</td>
                                <td><?= htmlspecialchars($active_assignment['assigned_by_user']) ?></td>
                            </tr>
                            <?php if ($active_assignment['assignment_notes']): ?>
                            <tr>
                                <td class="fw-bold">Notlar:</td>
                                <td><?= nl2br(htmlspecialchars($active_assignment['assignment_notes'])) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                        
                        <?php if (hasPermission('manager')): ?>
                        <div class="mt-3">
                            <a href="../assignments/return.php?id=<?= $active_assignment['id'] ?>" class="btn btn-info">
                                <i class="fas fa-undo"></i> İade Al
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Zimmet Geçmişi -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-history"></i> Zimmet Geçmişi
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($assignments)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h6 class="text-muted">Henüz zimmet geçmişi bulunmuyor</h6>
                        <p class="text-muted">Bu malzeme henüz hiç zimmetlenmemiş.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Zimmet No</th>
                                    <th>Personel</th>
                                    <th>Veriliş Tarihi</th>
                                    <th>İade Tarihi</th>
                                    <th>Süre</th>
                                    <th>Durum</th>
                                    <th>Veren</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($assignment['assignment_number']) ?></strong>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?= htmlspecialchars($assignment['personnel_name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($assignment['sicil_no']) ?></small>
                                        </div>
                                    </td>
                                    <td><?= formatDate($assignment['assigned_date']) ?></td>
                                    <td><?= formatDate($assignment['return_date']) ?></td>
                                    <td>
                                        <?php 
                                        $start = strtotime($assignment['assigned_date']);
                                        $end = $assignment['return_date'] ? strtotime($assignment['return_date']) : time();
                                        $days = floor(($end - $start) / (60 * 60 * 24));
                                        $badge_class = $assignment['status'] === 'active' ? 'bg-warning' : 'bg-secondary';
                                        ?>
                                        <span class="badge <?= $badge_class ?>"><?= $days ?> gün</span>
                                    </td>
                                    <td>
                                        <?php
                                        $badge_class = '';
                                        $status_text = '';
                                        switch($assignment['status']) {
                                            case 'active':
                                                $badge_class = 'bg-success';
                                                $status_text = 'Aktif';
                                                break;
                                            case 'returned':
                                                $badge_class = 'bg-secondary';
                                                $status_text = 'İade Edildi';
                                                break;
                                            case 'lost':
                                                $badge_class = 'bg-danger';
                                                $status_text = 'Kayıp';
                                                break;
                                            case 'damaged':
                                                $badge_class = 'bg-warning';
                                                $status_text = 'Hasarlı';
                                                break;
                                        }
                                        ?>
                                        <span class="badge <?= $badge_class ?>"><?= $status_text ?></span>
                                    </td>
                                    <td>
                                        <small><?= htmlspecialchars($assignment['assigned_by_user']) ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="mt-4">
    <a href="index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Stok Listesine Dön
    </a>
    
    <?php if (hasPermission('manager')): ?>
        <a href="edit.php?id=<?= $item['id'] ?>" class="btn btn-warning">
            <i class="fas fa-edit"></i> Düzenle
        </a>
        
        <?php if ($item['status'] === 'available'): ?>
            <a href="../assignments/add.php?item_id=<?= $item['id'] ?>" class="btn btn-success">
                <i class="fas fa-handshake"></i> Zimmet Ver
            </a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>