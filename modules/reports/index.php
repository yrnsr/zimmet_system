<?php
// modules/reports/index.php

ob_start();

$page_title = "Raporlama";
$page_description = "Kapsamlı zimmet ve stok raporları";

require_once '../../includes/header.php';

// Yetki kontrolü
requirePermission('manager');

try {
    $db = getDB();
    
    // Genel istatistikler
    $stats = [];
    
    // Toplam zimmet sayısı
    $stmt = $db->query("SELECT COUNT(*) as total FROM assignments");
    $stats['total_assignments'] = $stmt->fetch()['total'];
    
    // Aktif zimmet sayısı
    $stmt = $db->query("SELECT COUNT(*) as total FROM assignments WHERE status = 'active'");
    $stats['active_assignments'] = $stmt->fetch()['total'];
    
    // Toplam personel sayısı
    $stmt = $db->query("SELECT COUNT(*) as total FROM personnel WHERE is_active = 1");
    $stats['total_personnel'] = $stmt->fetch()['total'];
    
    // Toplam malzeme sayısı
    $stmt = $db->query("SELECT COUNT(*) as total FROM items");
    $stats['total_items'] = $stmt->fetch()['total'];
    
    // Müsait malzeme sayısı
    $stmt = $db->query("SELECT COUNT(*) as total FROM items WHERE status = 'available'");
    $stats['available_items'] = $stmt->fetch()['total'];
    
    // Bu ay verilen zimmet sayısı
    $stmt = $db->query("
        SELECT COUNT(*) as total 
        FROM assignments 
        WHERE MONTH(assigned_date) = MONTH(CURRENT_DATE) 
        AND YEAR(assigned_date) = YEAR(CURRENT_DATE)
    ");
    $stats['this_month_assignments'] = $stmt->fetch()['total'];
    
    // En çok zimmet alan personel
    $stmt = $db->query("
        SELECT 
            CONCAT(p.name, ' ', p.surname) as personnel_name,
            p.sicil_no,
            COUNT(a.id) as assignment_count
        FROM assignments a
        JOIN personnel p ON a.personnel_id = p.id
        GROUP BY a.personnel_id
        ORDER BY assignment_count DESC
        LIMIT 5
    ");
    $top_personnel = $stmt->fetchAll();
    
    // En çok zimmetlenen malzeme kategorileri
    $stmt = $db->query("
        SELECT 
            c.name as category_name,
            COUNT(a.id) as assignment_count
        FROM assignments a
        JOIN items i ON a.item_id = i.id
        JOIN categories c ON i.category_id = c.id
        GROUP BY c.id
        ORDER BY assignment_count DESC
        LIMIT 5
    ");
    $top_categories = $stmt->fetchAll();
    
    // Departman bazlı aktif zimmet dağılımı
    $stmt = $db->query("
        SELECT 
            d.name as department_name,
            COUNT(a.id) as active_assignments
        FROM assignments a
        JOIN personnel p ON a.personnel_id = p.id
        JOIN departments d ON p.department_id = d.id
        WHERE a.status = 'active'
        GROUP BY d.id
        ORDER BY active_assignments DESC
    ");
    $department_assignments = $stmt->fetchAll();
    
} catch(Exception $e) {
    setError("Rapor verileri yüklenirken hata oluştu: " . $e->getMessage());
    $stats = [];
    $top_personnel = [];
    $top_categories = [];
    $department_assignments = [];
}
?>

<!-- Genel İstatistikler -->
<div class="row mb-4">
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-primary">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stats-number text-primary"><?= $stats['total_assignments'] ?? 0 ?></div>
            <div class="stats-label">Toplam Zimmet</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-success">
                <i class="fas fa-handshake"></i>
            </div>
            <div class="stats-number text-success"><?= $stats['active_assignments'] ?? 0 ?></div>
            <div class="stats-label">Aktif Zimmet</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-info">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-number text-info"><?= $stats['total_personnel'] ?? 0 ?></div>
            <div class="stats-label">Aktif Personel</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-warning">
                <i class="fas fa-boxes"></i>
            </div>
            <div class="stats-number text-warning"><?= $stats['total_items'] ?? 0 ?></div>
            <div class="stats-label">Toplam Malzeme</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-secondary">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-number text-secondary"><?= $stats['available_items'] ?? 0 ?></div>
            <div class="stats-label">Müsait Malzeme</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-danger">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div class="stats-number text-danger"><?= $stats['this_month_assignments'] ?? 0 ?></div>
            <div class="stats-label">Bu Ay Zimmet</div>
        </div>
    </div>
</div>

<!-- Rapor Kartları -->
<div class="row">
    <!-- Zimmet Raporları -->
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-clipboard-list"></i> Zimmet Raporları
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="assignments.php" class="btn btn-outline-primary">
                        <i class="fas fa-list"></i> Tüm Zimmet Listesi
                    </a>
                    <a href="assignments.php?status=active" class="btn btn-outline-success">
                        <i class="fas fa-handshake"></i> Aktif Zimmetler
                    </a>
                    <a href="assignments.php?status=returned" class="btn btn-outline-info">
                        <i class="fas fa-undo"></i> İade Edilenler
                    </a>
                    <a href="assignments.php?status=lost" class="btn btn-outline-danger">
                        <i class="fas fa-exclamation-triangle"></i> Kayıp Zimmetler
                    </a>
                    <a href="assignments.php?period=monthly" class="btn btn-outline-warning">
                        <i class="fas fa-calendar"></i> Aylık Zimmet Raporu
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Personel Raporları -->
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-users"></i> Personel Raporları
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="personnel.php" class="btn btn-outline-primary">
                        <i class="fas fa-list"></i> Tüm Personel Listesi
                    </a>
                    <a href="personnel.php?type=with_assignments" class="btn btn-outline-success">
                        <i class="fas fa-user-check"></i> Zimmetli Personeller
                    </a>
                    <a href="personnel.php?type=without_assignments" class="btn btn-outline-secondary">
                        <i class="fas fa-user-times"></i> Zimmetsiz Personeller
                    </a>
                    <a href="personnel.php?type=by_department" class="btn btn-outline-info">
                        <i class="fas fa-building"></i> Departman Bazlı
                    </a>
                    <a href="personnel.php?type=performance" class="btn btn-outline-warning">
                        <i class="fas fa-chart-bar"></i> Performans Raporu
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Malzeme Raporları -->
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-boxes"></i> Malzeme Raporları
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="items.php" class="btn btn-outline-primary">
                        <i class="fas fa-list"></i> Tüm Malzeme Listesi
                    </a>
                    <a href="items.php?status=available" class="btn btn-outline-success">
                        <i class="fas fa-check-circle"></i> Müsait Malzemeler
                    </a>
                    <a href="items.php?status=assigned" class="btn btn-outline-warning">
                        <i class="fas fa-handshake"></i> Zimmetli Malzemeler
                    </a>
                    <a href="items.php?type=by_category" class="btn btn-outline-info">
                        <i class="fas fa-tags"></i> Kategori Bazlı
                    </a>
                    <a href="items.php?type=inventory" class="btn btn-outline-secondary">
                        <i class="fas fa-warehouse"></i> Envanter Raporu
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hızlı İstatistikler -->
<div class="row">
    <!-- En Çok Zimmet Alan Personeller -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="fas fa-trophy"></i> En Çok Zimmet Alanlar
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($top_personnel)): ?>
                    <p class="text-muted text-center">Veri bulunamadı</p>
                <?php else: ?>
                    <?php foreach ($top_personnel as $person): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <strong><?= htmlspecialchars($person['personnel_name']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($person['sicil_no']) ?></small>
                            </div>
                            <span class="badge bg-primary"><?= $person['assignment_count'] ?></span>
                        </div>
                        <?php if (!end($top_personnel) === $person): ?>
                            <hr class="my-2">
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="text-center mt-3">
                    <a href="personnel.php?type=performance" class="btn btn-sm btn-outline-primary">
                        Detaylı Rapor
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- En Çok Zimmetlenen Kategoriler -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="fas fa-chart-pie"></i> Popüler Kategoriler
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($top_categories)): ?>
                    <p class="text-muted text-center">Veri bulunamadı</p>
                <?php else: ?>
                    <?php foreach ($top_categories as $category): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span><?= htmlspecialchars($category['category_name']) ?></span>
                            <span class="badge bg-info"><?= $category['assignment_count'] ?></span>
                        </div>
                        <?php if (!end($top_categories) === $category): ?>
                            <hr class="my-2">
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="text-center mt-3">
                    <a href="items.php?type=by_category" class="btn btn-sm btn-outline-info">
                        Detaylı Rapor
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Departman Dağılımı -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="fas fa-building"></i> Departman Dağılımı
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($department_assignments)): ?>
                    <p class="text-muted text-center">Veri bulunamadı</p>
                <?php else: ?>
                    <?php foreach ($department_assignments as $dept): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span><?= htmlspecialchars($dept['department_name']) ?></span>
                            <span class="badge bg-warning"><?= $dept['active_assignments'] ?></span>
                        </div>
                        <?php if (!end($department_assignments) === $dept): ?>
                            <hr class="my-2">
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="text-center mt-3">
                    <a href="personnel.php?type=by_department" class="btn btn-sm btn-outline-warning">
                        Detaylı Rapor
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hızlı Eylemler -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-bolt"></i> Hızlı Rapor Eylemleri
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="d-grid">
                            <a href="custom.php" class="btn btn-primary">
                                <i class="fas fa-cog"></i><br>
                                <small>Özel Rapor Oluştur</small>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="d-grid">
                            <a href="exports.php" class="btn btn-success">
                                <i class="fas fa-file-excel"></i><br>
                                <small>Excel'e Toplu Aktar</small>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="d-grid">
                            <a href="analytics.php" class="btn btn-info">
                                <i class="fas fa-chart-line"></i><br>
                                <small>Trend Analizi</small>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="d-grid">
                            <a href="schedules.php" class="btn btn-warning">
                                <i class="fas fa-clock"></i><br>
                                <small>Otomatik Raporlar</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>