<?php
// modules/reports/items.php

ob_start();

$page_title = "Malzeme Raporları";
$page_description = "Malzeme ve envanter raporları";

require_once '../../includes/header.php';

// Yetki kontrolü
requirePermission('manager');

// Filtre parametreleri
$status_filter = isset($_GET['status']) ? clean($_GET['status']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$report_type = isset($_GET['type']) ? clean($_GET['type']) : '';
$export = isset($_GET['export']) ? clean($_GET['export']) : '';

try {
    $db = getDB();
    
    // WHERE koşulları
    $where_conditions = [];
    $params = [];
    
    if ($status_filter) {
        $where_conditions[] = "i.status = ?";
        $params[] = $status_filter;
    }
    
    if ($category_filter > 0) {
        $where_conditions[] = "i.category_id = ?";
        $params[] = $category_filter;
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Ana sorgu
    $sql = "
        SELECT 
            i.*,
            c.name as category_name,
            CONCAT(p.name, ' ', p.surname) as assigned_to,
            p.sicil_no,
            d.name as department_name,
            a.assignment_number,
            a.assigned_date,
            a.status as assignment_status
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.id
        LEFT JOIN assignments a ON i.id = a.item_id AND a.status = 'active'
        LEFT JOIN personnel p ON a.personnel_id = p.id
        LEFT JOIN departments d ON p.department_id = d.id
        $where_clause
        ORDER BY i.name ASC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    
    // İstatistikler
    $stats = [
        'total_count' => count($items),
        'total_value' => 0,
        'available_count' => 0,
        'assigned_count' => 0,
        'maintenance_count' => 0,
        'lost_count' => 0,
        'scrapped_count' => 0,
        'under_warranty' => 0
    ];
    
    foreach ($items as $item) {
        if ($item['purchase_price']) {
            $stats['total_value'] += $item['purchase_price'];
        }
        
        // Garanti kontrolü
        if (isset($item['warranty_end']) && $item['warranty_end'] && strtotime($item['warranty_end']) > time()) {
            $stats['under_warranty']++;
        }
        
        switch ($item['status']) {
            case 'available': $stats['available_count']++; break;
            case 'assigned': $stats['assigned_count']++; break;
            case 'maintenance': $stats['maintenance_count']++; break;
            case 'lost': $stats['lost_count']++; break;
            case 'scrapped': $stats['scrapped_count']++; break;
        }
    }
    
    // Kategori listesi
    $cat_stmt = $db->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $cat_stmt->fetchAll();
    
    // Kategori bazlı özet (eğer by_category seçiliyse)
    if ($report_type === 'by_category') {
        $category_stats_sql = "
            SELECT 
                c.name as category_name,
                COUNT(i.id) as item_count,
                SUM(CASE WHEN i.status = 'available' THEN 1 ELSE 0 END) as available_count,
                SUM(CASE WHEN i.status = 'assigned' THEN 1 ELSE 0 END) as assigned_count,
                SUM(i.purchase_price) as category_value
            FROM categories c
            LEFT JOIN items i ON c.id = i.category_id
            GROUP BY c.id
            ORDER BY c.name
        ";
        $cat_stmt = $db->query($category_stats_sql);
        $category_stats = $cat_stmt->fetchAll();
    }
    
} catch(Exception $e) {
    setError("Rapor verileri yüklenirken hata oluştu: " . $e->getMessage());
    $items = [];
    $stats = [];
    $categories = [];
    $category_stats = [];
}

// Excel export
if ($export === 'excel' && !empty($items)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="malzeme_raporu_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV başlıkları
    fputcsv($output, [
        'Malzeme Kodu',
        'Malzeme Adı',
        'Kategori',
        'Marka',
        'Model',
        'Seri No',
        'Durum',
        'Zimmetli Personel',
        'Departman',
        'Alım Tarihi',
        'Garanti Bitiş',
        'Değer (TL)'
    ]);
    
    // Veri satırları
    foreach ($items as $item) {
        fputcsv($output, [
            $item['item_code'],
            $item['name'],
            $item['category_name'],
            $item['brand'],
            $item['model'],
            $item['serial_no'],
            getItemStatusText($item['status']),
            $item['assigned_to'],
            $item['department_name'],
            formatDate($item['purchase_date']),
            formatDate($item['warranty_end']),
            $item['purchase_price'] ? number_format($item['purchase_price'], 2) : ''
        ]);
    }
    
    fclose($output);
    exit();
}

// Durum çeviri
function getItemStatusText($status) {
    $statuses = [
        'available' => 'Müsait',
        'assigned' => 'Zimmetli',
        'maintenance' => 'Bakımda',
        'lost' => 'Kayıp',
        'scrapped' => 'Hurda'
    ];
    return $statuses[$status] ?? $status;
}

function getItemStatusBadgeClass($status) {
    $classes = [
        'available' => 'bg-success',
        'assigned' => 'bg-warning',
        'maintenance' => 'bg-info',
        'lost' => 'bg-danger',
        'scrapped' => 'bg-secondary'
    ];
    return $classes[$status] ?? 'bg-secondary';
}
?>

<!-- Rapor Başlığı -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4>
            <?php
            if ($status_filter) {
                echo getItemStatusText($status_filter) . ' Malzemeler';
            } elseif ($report_type === 'by_category') {
                echo 'Kategori Bazlı Malzeme Raporu';
            } elseif ($report_type === 'inventory') {
                echo 'Envanter Raporu';
            } else {
                echo 'Malzeme Raporu';
            }
            ?>
        </h4>
        <small class="text-muted">
            Rapor Tarihi: <?= date('d.m.Y H:i') ?>
        </small>
    </div>
    
    <div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Raporlara Dön
        </a>
        <?php if (!empty($items)): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-success">
                <i class="fas fa-file-excel"></i> Excel'e Aktar
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Rapor İstatistikleri -->
<div class="row mb-4">
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-primary">
                <i class="fas fa-boxes"></i>
            </div>
            <div class="stats-number text-primary"><?= $stats['total_count'] ?></div>
            <div class="stats-label">Toplam Malzeme</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-number text-success"><?= $stats['available_count'] ?></div>
            <div class="stats-label">Müsait</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-warning">
                <i class="fas fa-handshake"></i>
            </div>
            <div class="stats-number text-warning"><?= $stats['assigned_count'] ?></div>
            <div class="stats-label">Zimmetli</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-info">
                <i class="fas fa-tools"></i>
            </div>
            <div class="stats-number text-info"><?= $stats['maintenance_count'] ?></div>
            <div class="stats-label">Bakımda</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-danger">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stats-number text-danger"><?= $stats['lost_count'] ?></div>
            <div class="stats-label">Kayıp</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-secondary">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div class="stats-number text-secondary"><?= $stats['under_warranty'] ?></div>
            <div class="stats-label">Garantili</div>
        </div>
    </div>
</div>

<!-- Toplam Değer -->
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i> <strong>Toplam Envanter Değeri:</strong> ₺<?= number_format($stats['total_value'], 2) ?>
</div>

<!-- Filtreler -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="fas fa-filter"></i> Rapor Filtreleri
        </h6>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label for="type" class="form-label">Rapor Tipi</label>
                <select class="form-select" id="type" name="type">
                    <option value="">Standart Liste</option>
                    <option value="by_category" <?= $report_type === 'by_category' ? 'selected' : '' ?>>Kategori Bazlı</option>
                    <option value="inventory" <?= $report_type === 'inventory' ? 'selected' : '' ?>>Envanter Raporu</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label for="status" class="form-label">Durum</label>
                <select class="form-select" id="status" name="status">
                    <option value="">Tüm Durumlar</option>
                    <option value="available" <?= $status_filter === 'available' ? 'selected' : '' ?>>Müsait</option>
                    <option value="assigned" <?= $status_filter === 'assigned' ? 'selected' : '' ?>>Zimmetli</option>
                    <option value="maintenance" <?= $status_filter === 'maintenance' ? 'selected' : '' ?>>Bakımda</option>
                    <option value="lost" <?= $status_filter === 'lost' ? 'selected' : '' ?>>Kayıp</option>
                    <option value="scrapped" <?= $status_filter === 'scrapped' ? 'selected' : '' ?>>Hurda</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label for="category" class="form-label">Kategori</label>
                <select class="form-select" id="category" name="category">
                    <option value="">Tüm Kategoriler</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $category_filter == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filtrele
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Kategori Bazlı Özet (eğer by_category seçiliyse) -->
<?php if ($report_type === 'by_category' && !empty($category_stats)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="fas fa-chart-pie"></i> Kategori Özeti
        </h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Kategori</th>
                        <th class="text-center">Toplam</th>
                        <th class="text-center">Müsait</th>
                        <th class="text-center">Zimmetli</th>
                        <th class="text-end">Toplam Değer</th>
                        <th class="text-center">Doluluk Oranı</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($category_stats as $cat_stat): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($cat_stat['category_name']) ?></strong></td>
                        <td class="text-center"><?= $cat_stat['item_count'] ?></td>
                        <td class="text-center">
                            <span class="badge bg-success"><?= $cat_stat['available_count'] ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-warning"><?= $cat_stat['assigned_count'] ?></span>
                        </td>
                       <td class="text-end">₺<?= number_format($cat_stat['category_value'] ?? 0, 2) ?></td>
                        <td class="text-center">
                            <?php 
                            $occupancy_rate = $cat_stat['item_count'] > 0 ? 
                                round(($cat_stat['assigned_count'] / $cat_stat['item_count']) * 100) : 0;
                            ?>
                            <div class="progress" style="width: 100px; margin: 0 auto;">
                                <div class="progress-bar <?= $occupancy_rate > 80 ? 'bg-danger' : ($occupancy_rate > 60 ? 'bg-warning' : 'bg-success') ?>" 
                                     role="progressbar" style="width: <?= $occupancy_rate ?>%">
                                    <?= $occupancy_rate ?>%
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Malzeme Listesi -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">
            <i class="fas fa-list"></i> Malzeme Listesi
        </h6>
        <span class="badge bg-primary"><?= count($items) ?> Kayıt</span>
    </div>
    
    <div class="card-body">
        <?php if (empty($items)): ?>
            <div class="text-center py-5">
                <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                <h6 class="text-muted">Filtrelere uygun malzeme bulunamadı</h6>
                <p class="text-muted">Filtre kriterlerinizi değiştirip tekrar deneyin.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Malzeme Kodu</th>
                            <th>Malzeme Adı</th>
                            <th>Kategori</th>
                            <th>Marka/Model</th>
                            <th>Seri No</th>
                            <th>Durum</th>
                            <th>Zimmetli Personel</th>
                            <th>Departman</th>
                            <th>Garanti</th>
                            <th>Değer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <a href="../items/view.php?id=<?= $item['id'] ?>" target="_blank" class="text-decoration-none">
                                    <?= htmlspecialchars($item['item_code']) ?>
                                </a>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($item['name']) ?></strong>
                            </td>
                            <td>
                                <span class="badge bg-info">
                                    <?= htmlspecialchars($item['category_name']) ?>
                                </span>
                            </td>
                            <td>
                                <small>
                                    <?= htmlspecialchars($item['brand'] . ' ' . $item['model']) ?>
                                </small>
                            </td>
                            <td>
                                <small><?= htmlspecialchars(isset($item['serial_no']) ? ($item['serial_no'] ?: '-') : '-') ?></small>
                            </td>
                            <td>
                                <span class="badge <?= getItemStatusBadgeClass($item['status']) ?>">
                                    <?= getItemStatusText($item['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($item['assigned_to']): ?>
                                    <div>
                                        <strong><?= htmlspecialchars($item['assigned_to']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($item['sicil_no']) ?></small>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">-</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?= htmlspecialchars($item['department_name'] ?: '-') ?></small>
                            </td>
                            <td>
                                <?php if (isset($item['warranty_end']) && $item['warranty_end'] && strtotime($item['warranty_end']) > time()): ?>
                                    <span class="badge bg-success" title="<?= formatDate($item['warranty_end']) ?>">
                                        <i class="fas fa-shield-alt"></i> Var
                                    </span>
                                <?php else: ?>
                                    <small class="text-muted">-</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($item['purchase_price']): ?>
                                    <small class="text-success">
                                        ₺<?= number_format($item['purchase_price'], 2) ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted">-</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Özet Bilgiler -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6>Durum Dağılımı</h6>
                            <div class="row">
                                <div class="col-6">
                                    <ul class="list-unstyled mb-0">
                                        <li><span class="badge bg-success me-2"><?= $stats['available_count'] ?></span> Müsait</li>
                                        <li><span class="badge bg-warning me-2"><?= $stats['assigned_count'] ?></span> Zimmetli</li>
                                        <li><span class="badge bg-info me-2"><?= $stats['maintenance_count'] ?></span> Bakımda</li>
                                    </ul>
                                </div>
                                <div class="col-6">
                                    <ul class="list-unstyled mb-0">
                                        <li><span class="badge bg-danger me-2"><?= $stats['lost_count'] ?></span> Kayıp</li>
                                        <li><span class="badge bg-secondary me-2"><?= $stats['scrapped_count'] ?></span> Hurda</li>
                                        <li><span class="badge bg-primary me-2"><?= $stats['under_warranty'] ?></span> Garantili</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6>Değer Analizi</h6>
                            <ul class="list-unstyled mb-0">
                                <li><strong>Toplam Değer:</strong> ₺<?= number_format($stats['total_value'], 2) ?></li>
                                <li><strong>Ortalama Değer:</strong> ₺<?= $stats['total_count'] > 0 ? number_format($stats['total_value'] / $stats['total_count'], 2) : '0.00' ?></li>
                                <li><strong>Doluluk Oranı:</strong> %<?= $stats['total_count'] > 0 ? round(($stats['assigned_count'] / $stats['total_count']) * 100) : 0 ?></li>
                                <li><strong>Müsaitlik Oranı:</strong> %<?= $stats['total_count'] > 0 ? round(($stats['available_count'] / $stats['total_count']) * 100) : 0 ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>