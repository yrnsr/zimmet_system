<?php
// index.php

$page_title = "Ana Sayfa";
$page_description = "Zimmet takip sistemi özet bilgileri";

require_once 'includes/header.php';

// Dashboard istatistikleri
try {
    $db = getDB();
    
    // Toplam personel sayısı
    $stmt = $db->query("SELECT COUNT(*) as total FROM personnel WHERE is_active = 1");
    $total_personnel = $stmt->fetch()['total'];
    
    // Toplam malzeme sayısı
    $stmt = $db->query("SELECT COUNT(*) as total FROM items");
    $total_items = $stmt->fetch()['total'];
    
    // Aktif zimmet sayısı
    $stmt = $db->query("SELECT COUNT(*) as total FROM assignments WHERE status = 'active'");
    $active_assignments = $stmt->fetch()['total'];
    
    // Müsait malzeme sayısı
    $stmt = $db->query("SELECT COUNT(*) as total FROM items WHERE status = 'available'");
    $available_items = $stmt->fetch()['total'];
    
    // Son zimmet işlemleri
    $stmt = $db->query("
        SELECT 
            a.assignment_number,
            CONCAT(p.name, ' ', p.surname) as personnel_name,
            p.sicil_no,
            i.name as item_name,
            a.assigned_date,
            a.status,
            u.username as assigned_by_user
        FROM assignments a
        JOIN personnel p ON a.personnel_id = p.id
        JOIN items i ON a.item_id = i.id
        JOIN users u ON a.assigned_by = u.id
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $recent_assignments = $stmt->fetchAll();
    
    // Kategori bazında malzeme dağılımı
    $stmt = $db->query("
        SELECT 
            c.name as category_name,
            COUNT(i.id) as total_items,
            SUM(CASE WHEN i.status = 'available' THEN 1 ELSE 0 END) as available_items,
            SUM(CASE WHEN i.status = 'assigned' THEN 1 ELSE 0 END) as assigned_items
        FROM categories c
        LEFT JOIN items i ON c.id = i.category_id
        GROUP BY c.id, c.name
        ORDER BY total_items DESC
    ");
    $category_stats = $stmt->fetchAll();
    
    // Departman bazında zimmet dağılımı
    $stmt = $db->query("
        SELECT 
            d.name as department_name,
            COUNT(DISTINCT p.id) as total_personnel,
            COUNT(a.id) as active_assignments
        FROM departments d
        LEFT JOIN personnel p ON d.id = p.department_id AND p.is_active = 1
        LEFT JOIN assignments a ON p.id = a.personnel_id AND a.status = 'active'
        GROUP BY d.id, d.name
        ORDER BY active_assignments DESC
    ");
    $department_stats = $stmt->fetchAll();
    
} catch(Exception $e) {
    setError("Dashboard yüklenirken hata oluştu: " . $e->getMessage());
    $total_personnel = $total_items = $active_assignments = $available_items = 0;
    $recent_assignments = $category_stats = $department_stats = [];
}
?>

<!-- Dashboard Stats -->
<div class="row">
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon text-primary">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-number text-primary"><?= $total_personnel ?></div>
            <div class="stats-label">Toplam Personel</div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon text-info">
                <i class="fas fa-boxes"></i>
            </div>
            <div class="stats-number text-info"><?= $total_items ?></div>
            <div class="stats-label">Toplam Malzeme</div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon text-warning">
                <i class="fas fa-handshake"></i>
            </div>
            <div class="stats-number text-warning"><?= $active_assignments ?></div>
            <div class="stats-label">Aktif Zimmet</div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon text-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-number text-success"><?= $available_items ?></div>
            <div class="stats-label">Müsait Malzeme</div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Son Zimmet İşlemleri -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="fas fa-history"></i> Son Zimmet İşlemleri
                </h5>
                <a href="<?= url('modules/assignments/index.php') ?>" class="btn btn-sm btn-primary">
                    Tümünü Görüntüle
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($recent_assignments)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-inbox fa-3x mb-3"></i>
                        <p>Henüz zimmet işlemi bulunmuyor.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Zimmet No</th>
                                    <th>Personel</th>
                                    <th>Malzeme</th>
                                    <th>Tarih</th>
                                    <th>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_assignments as $assignment): ?>
                                <tr>
                                    <td><?= htmlspecialchars($assignment['assignment_number']) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($assignment['personnel_name']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($assignment['sicil_no']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($assignment['item_name']) ?></td>
                                    <td><?= formatDate($assignment['assigned_date']) ?></td>
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
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Hızlı İşlemler -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-bolt"></i> Hızlı İşlemler
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <?php if (hasPermission('manager')): ?>
                    <a href="<?= url('modules/assignments/add.php') ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Yeni Zimmet Ver
                    </a>
                    
                    <a href="<?= url('modules/personnel/add.php') ?>" class="btn btn-info">
                        <i class="fas fa-user-plus"></i> Personel Ekle
                    </a>
                    
                    <a href="<?= url('modules/items/add.php') ?>" class="btn btn-success">
                        <i class="fas fa-box"></i> Malzeme Ekle
                    </a>
                    <?php endif; ?>
                    
                    <a href="<?= url('modules/assignments/search.php') ?>" class="btn btn-warning">
                        <i class="fas fa-search"></i> Zimmet Ara
                    </a>
                    
                    <?php if (hasPermission('manager')): ?>
                    <a href="<?= url('modules/reports/index.php') ?>" class="btn btn-secondary">
                        <i class="fas fa-chart-bar"></i> Raporlar
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Kategori İstatistikleri -->
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-chart-pie"></i> Kategori İstatistikleri
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($category_stats)): ?>
                    <p class="text-muted">Veri bulunamadı.</p>
                <?php else: ?>
                    <?php foreach ($category_stats as $category): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold"><?= htmlspecialchars($category['category_name']) ?></span>
                            <span class="badge bg-primary"><?= $category['total_items'] ?></span>
                        </div>
                        <div class="row">
                            <div class="col-6">
                                <small class="text-success">
                                    <i class="fas fa-check"></i> Müsait: <?= $category['available_items'] ?>
                                </small>
                            </div>
                            <div class="col-6">
                                <small class="text-warning">
                                    <i class="fas fa-hand-holding"></i> Zimmetli: <?= $category['assigned_items'] ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Departman İstatistikleri -->
<?php if (hasPermission('manager')): ?>
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-building"></i> Departman Bazında Zimmet Dağılımı
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($department_stats)): ?>
                    <p class="text-muted text-center">Departman verisi bulunamadı.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Departman</th>
                                    <th>Toplam Personel</th>
                                    <th>Aktif Zimmet</th>
                                    <th>Zimmet Oranı</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($department_stats as $dept): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($dept['department_name']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?= $dept['total_personnel'] ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning"><?= $dept['active_assignments'] ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        $ratio = $dept['total_personnel'] > 0 ? 
                                                ($dept['active_assignments'] / $dept['total_personnel']) * 100 : 0;
                                        ?>
                                        <div class="progress" style="width: 100px;">
                                            <div class="progress-bar" role="progressbar" 
                                                 style="width: <?= min($ratio, 100) ?>%"
                                                 aria-valuenow="<?= $ratio ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                <?= number_format($ratio, 1) ?>%
                                            </div>
                                        </div>
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
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>