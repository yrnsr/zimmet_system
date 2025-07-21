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

<!-- Kategori İstatistikleri -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-chart-pie"></i> Kategori Bazında Malzeme Dağılımı
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($category_stats)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-tags fa-3x mb-3"></i>
                        <p>Kategori verisi bulunamadı.</p>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($category_stats as $category): ?>
                        <div class="col-lg-3 col-md-4 col-sm-6 mb-4">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body text-center">
                                    <div class="mb-3">
                                        <i class="fas fa-tag fa-2x text-secondary"></i>
                                    </div>
                                    <h6 class="card-title text-secondary fw-bold">
                                        <?= htmlspecialchars($category['category_name']) ?>
                                    </h6>
                                    <div class="row text-center">
                                        <div class="col-12 mb-2">
                                            <span class="badge bg-secondary fs-6 px-3 py-2">
                                                Toplam: <?= $category['total_items'] ?>
                                            </span>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-success fw-bold">
                                                <i class="fas fa-check-circle"></i>
                                                <br>Müsait
                                                <br><span class="badge bg-success"><?= $category['available_items'] ?></span>
                                            </small>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-warning fw-bold">
                                                <i class="fas fa-hand-holding"></i>
                                                <br>Zimmetli
                                                <br><span class="badge bg-warning"><?= $category['assigned_items'] ?></span>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <!-- İlerleme Çubuğu -->
                                    <?php if ($category['total_items'] > 0): ?>
                                    <div class="mt-3">
                                        <?php 
                                        $usage_ratio = ($category['assigned_items'] / $category['total_items']) * 100;
                                        ?>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar bg-warning" role="progressbar" 
                                                 style="width: <?= $usage_ratio ?>%"
                                                 title="Kullanım Oranı: <?= number_format($usage_ratio, 1) ?>%">
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            Kullanım: <?= number_format($usage_ratio, 1) ?>%
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Özet Tablo -->
                    <div class="mt-4">
                        <h6 class="mb-3">
                            <i class="fas fa-table"></i> Detaylı Kategori Tablosu
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Kategori</th>
                                        <th>Toplam Malzeme</th>
                                        <th>Müsait</th>
                                        <th>Zimmetli</th>
                                        <th>Kullanım Oranı</th>
                                        <th>Durumu</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($category_stats as $category): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-secondary">
                                                <i class="fas fa-tag"></i>
                                                <?= htmlspecialchars($category['category_name']) ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= $category['total_items'] ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?= $category['available_items'] ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning"><?= $category['assigned_items'] ?></span>
                                        </td>
                                        <td>
                                            <?php 
                                            $usage_ratio = $category['total_items'] > 0 ? 
                                                    ($category['assigned_items'] / $category['total_items']) * 100 : 0;
                                            ?>
                                            <div class="progress" style="width: 80px;">
                                                <div class="progress-bar <?= $usage_ratio > 80 ? 'bg-danger' : ($usage_ratio > 60 ? 'bg-warning' : 'bg-success') ?>" 
                                                     role="progressbar" 
                                                     style="width: <?= $usage_ratio ?>%"
                                                     title="<?= number_format($usage_ratio, 1) ?>%">
                                                </div>
                                            </div>
                                            <small><?= number_format($usage_ratio, 1) ?>%</small>
                                        </td>
                                        <td>
                                            <?php if ($usage_ratio > 80): ?>
                                                <span class="badge bg-danger">Yoğun Kullanım</span>
                                            <?php elseif ($usage_ratio > 60): ?>
                                                <span class="badge bg-warning">Orta Kullanım</span>
                                            <?php elseif ($usage_ratio > 0): ?>
                                                <span class="badge bg-success">Düşük Kullanım</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Kullanılmıyor</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>