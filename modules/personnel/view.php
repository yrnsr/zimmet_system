<?php
// modules/personnel/view.php

ob_start();

$page_title = "Personel Detayları";

require_once '../../includes/header.php';

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
    $stmt = $db->prepare("
        SELECT 
            p.*,
            d.name as department_name
        FROM personnel p 
        LEFT JOIN departments d ON p.department_id = d.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    $person = $stmt->fetch();
    
    if (!$person) {
        setError("Personel bulunamadı");
        header('Location: index.php');
        exit();
    }
    
    // Personelin zimmet geçmişi
    $assignments_stmt = $db->prepare("
        SELECT 
            a.*,
            i.name as item_name,
            i.item_code,
            i.brand,
            i.model,
            i.serial_number,
            c.name as category_name,
            u1.username as assigned_by_user,
            u2.username as returned_by_user
        FROM assignments a
        JOIN items i ON a.item_id = i.id
        LEFT JOIN categories c ON i.category_id = c.id
        LEFT JOIN users u1 ON a.assigned_by = u1.id
        LEFT JOIN users u2 ON a.returned_by = u2.id
        WHERE a.personnel_id = ?
        ORDER BY a.assigned_date DESC
    ");
    $assignments_stmt->execute([$id]);
    $assignments = $assignments_stmt->fetchAll();
    
    // Aktif zimmetler
    $active_assignments = array_filter($assignments, function($a) {
        return $a['status'] === 'active';
    });
    
    // İstatistikler
    $stats = [
        'total_assignments' => count($assignments),
        'active_assignments' => count($active_assignments),
        'returned_assignments' => count(array_filter($assignments, function($a) {
            return $a['status'] === 'returned';
        })),
        'lost_assignments' => count(array_filter($assignments, function($a) {
            return $a['status'] === 'lost';
        }))
    ];
    
} catch(Exception $e) {
    setError("Personel bilgileri yüklenirken hata oluştu: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

$page_description = $person['name'] . ' ' . $person['surname'] . ' - ' . $person['sicil_no'];
?>

<div class="row">
    <!-- Personel Bilgileri -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-user"></i> Personel Bilgileri
                </h5>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center"
                         style="width: 80px; height: 80px; font-size: 2rem;">
                        <i class="fas fa-user"></i>
                    </div>
                    <h4 class="mt-3 mb-1"><?= htmlspecialchars($person['name'] . ' ' . $person['surname']) ?></h4>
                    <p class="text-muted mb-0">Sicil No: <?= htmlspecialchars($person['sicil_no']) ?></p>
                    <span class="badge <?= $person['is_active'] ? 'bg-success' : 'bg-danger' ?> mt-2">
                        <?= $person['is_active'] ? 'Aktif' : 'Pasif' ?>
                    </span>
                </div>
                
                <table class="table table-borderless">
                    <tr>
                        <td class="fw-bold text-muted">Departman:</td>
                        <td><?= htmlspecialchars($person['department_name'] ?: 'Belirtilmemiş') ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Görev:</td>
                        <td><?= htmlspecialchars($person['position'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">E-posta:</td>
                        <td>
                            <?php if ($person['email']): ?>
                                <a href="mailto:<?= htmlspecialchars($person['email']) ?>">
                                    <?= htmlspecialchars($person['email']) ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Telefon:</td>
                        <td>
                            <?php if ($person['phone']): ?>
                                <a href="tel:<?= htmlspecialchars($person['phone']) ?>">
                                    <?= htmlspecialchars($person['phone']) ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">İşe Başlama:</td>
                        <td><?= formatDate($person['hire_date']) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-muted">Kayıt Tarihi:</td>
                        <td><?= formatDate($person['created_at']) ?></td>
                    </tr>
                </table>
                
                <?php if ($person['address']): ?>
                <div class="mt-3">
                    <h6 class="fw-bold text-muted">Adres:</h6>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($person['address'])) ?></p>
                </div>
                <?php endif; ?>
                
                <?php if (hasPermission('manager')): ?>
                <div class="d-grid gap-2 mt-4">
                    <a href="edit.php?id=<?= $person['id'] ?>" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Düzenle
                    </a>
                    
                    <?php if (count($active_assignments) == 0): ?>
                        <a href="../assignments/add.php?personnel_id=<?= $person['id'] ?>" class="btn btn-success">
                            <i class="fas fa-handshake"></i> Zimmet Ver
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Zimmet İstatistikleri ve Geçmişi -->
    <div class="col-lg-8">
        <!-- İstatistikler -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-primary">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stats-number text-primary"><?= $stats['total_assignments'] ?></div>
                    <div class="stats-label">Toplam Zimmet</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-success">
                        <i class="fas fa-handshake"></i>
                    </div>
                    <div class="stats-number text-success"><?= $stats['active_assignments'] ?></div>
                    <div class="stats-label">Aktif Zimmet</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-info">
                        <i class="fas fa-undo"></i>
                    </div>
                    <div class="stats-number text-info"><?= $stats['returned_assignments'] ?></div>
                    <div class="stats-label">İade Edildi</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stats-number text-danger"><?= $stats['lost_assignments'] ?></div>
                    <div class="stats-label">Kayıp</div>
                </div>
            </div>
        </div>
        
        <!-- Aktif Zimmetler -->
        <?php if (!empty($active_assignments)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-exclamation-circle text-warning"></i> Aktif Zimmetler
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Zimmet No</th>
                                <th>Malzeme</th>
                                <th>Kategori</th>
                                <th>Veriliş Tarihi</th>
                                <th>Gün Sayısı</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_assignments as $assignment): ?>
                            <tr>
                                <td>
                                    <strong class="text-primary">
                                        <?= htmlspecialchars($assignment['assignment_number']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= htmlspecialchars($assignment['item_name']) ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($assignment['brand'] . ' ' . $assignment['model']) ?>
                                        </small>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        <?= htmlspecialchars($assignment['category_name']) ?>
                                    </span>
                                </td>
                                <td><?= formatDate($assignment['assigned_date']) ?></td>
                                <td>
                                    <?php 
                                    $days = floor((time() - strtotime($assignment['assigned_date'])) / (60 * 60 * 24));
                                    $badge_class = $days > 365 ? 'bg-danger' : ($days > 180 ? 'bg-warning' : 'bg-success');
                                    ?>
                                    <span class="badge <?= $badge_class ?>"><?= $days ?> gün</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                        <p class="text-muted">Bu personele henüz hiç zimmet verilmemiş.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover datatable">
                            <thead>
                                <tr>
                                    <th>Zimmet No</th>
                                    <th>Malzeme</th>
                                    <th>Veriliş Tarihi</th>
                                    <th>İade Tarihi</th>
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
                                            <strong><?= htmlspecialchars($assignment['item_name']) ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($assignment['item_code']) ?> - 
                                                <?= htmlspecialchars($assignment['brand'] . ' ' . $assignment['model']) ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td><?= formatDate($assignment['assigned_date']) ?></td>
                                    <td><?= formatDate($assignment['return_date']) ?></td>
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
        <i class="fas fa-arrow-left"></i> Personel Listesine Dön
    </a>
    
    <?php if (hasPermission('manager')): ?>
        <a href="edit.php?id=<?= $person['id'] ?>" class="btn btn-warning">
            <i class="fas fa-edit"></i> Düzenle
        </a>
        
        <a href="../assignments/add.php?personnel_id=<?= $person['id'] ?>" class="btn btn-success">
            <i class="fas fa-handshake"></i> Yeni Zimmet Ver
        </a>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>