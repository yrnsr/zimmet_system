<?php
// modules/items/index.php

ob_start();

$page_title = "Stok Yönetimi";
$page_description = "Malzeme ve stok takip sistemi";

require_once '../../includes/header.php';

// Sayfalama ayarları
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Arama ve filtreleme
$search = isset($_GET['search']) ? clean($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$status_filter = isset($_GET['status']) ? clean($_GET['status']) : '';
$brand_filter = isset($_GET['brand']) ? clean($_GET['brand']) : '';

try {
    $db = getDB();
    
    // WHERE koşulları
    $where_conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(i.name LIKE ? OR i.item_code LIKE ? OR i.brand LIKE ? OR i.model LIKE ? OR i.serial_number LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    }
    
    if ($category_filter > 0) {
        $where_conditions[] = "i.category_id = ?";
        $params[] = $category_filter;
    }
    
    if ($status_filter !== '') {
        $where_conditions[] = "i.status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($brand_filter)) {
        $where_conditions[] = "i.brand LIKE ?";
        $params[] = "%$brand_filter%";
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Toplam kayıt sayısı
    $count_sql = "
        SELECT COUNT(*) as total 
        FROM items i 
        LEFT JOIN categories c ON i.category_id = c.id 
        $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetch()['total'];
    
    // Malzeme listesi
    $sql = "
        SELECT 
            i.*,
            c.name as category_name,
            CASE 
                WHEN i.status = 'assigned' THEN (
                    SELECT CONCAT(p.name, ' ', p.surname, ' (', p.sicil_no, ')')
                    FROM assignments a 
                    JOIN personnel p ON a.personnel_id = p.id 
                    WHERE a.item_id = i.id AND a.status = 'active' 
                    LIMIT 1
                )
                ELSE NULL 
            END as assigned_to
        FROM items i 
        LEFT JOIN categories c ON i.category_id = c.id 
        $where_clause
        ORDER BY i.created_at DESC 
        LIMIT $records_per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items_list = $stmt->fetchAll();
    
    // Kategori listesi (filtre için)
    $cat_stmt = $db->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $cat_stmt->fetchAll();
    
    // Marka listesi (filtre için)
    $brand_stmt = $db->query("SELECT DISTINCT brand FROM items WHERE brand IS NOT NULL AND brand != '' ORDER BY brand");
    $brands = $brand_stmt->fetchAll();
    
    // Durum istatistikleri
    $stats_stmt = $db->query("
        SELECT 
            status,
            COUNT(*) as count
        FROM items 
        GROUP BY status
    ");
    $status_stats = [];
    while ($row = $stats_stmt->fetch()) {
        $status_stats[$row['status']] = $row['count'];
    }
    
    // Sayfalama bilgileri
    $pagination = paginate($total_records, $records_per_page, $current_page);
    
} catch(Exception $e) {
    setError("Stok listesi yüklenirken hata oluştu: " . $e->getMessage());
    $items_list = [];
    $categories = [];
    $brands = [];
    $status_stats = [];
    $pagination = ['total_pages' => 0];
}

// Durum çeviri
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
?>

<!-- Durum İstatistikleri -->
<div class="row mb-4">
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-number text-success"><?= $status_stats['available'] ?? 0 ?></div>
            <div class="stats-label">Müsait</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-warning">
                <i class="fas fa-handshake"></i>
            </div>
            <div class="stats-number text-warning"><?= $status_stats['assigned'] ?? 0 ?></div>
            <div class="stats-label">Zimmetli</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-info">
                <i class="fas fa-tools"></i>
            </div>
            <div class="stats-number text-info"><?= $status_stats['maintenance'] ?? 0 ?></div>
            <div class="stats-label">Bakımda</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-danger">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stats-number text-danger"><?= $status_stats['broken'] ?? 0 ?></div>
            <div class="stats-label">Bozuk</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-dark">
                <i class="fas fa-question-circle"></i>
            </div>
            <div class="stats-number text-dark"><?= $status_stats['lost'] ?? 0 ?></div>
            <div class="stats-label">Kayıp</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-primary">
                <i class="fas fa-boxes"></i>
            </div>
            <div class="stats-number text-primary"><?= $total_records ?></div>
            <div class="stats-label">Toplam</div>
        </div>
    </div>
</div>

<!-- Filtre ve Arama -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label for="search" class="form-label">Arama</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" id="search" name="search" 
                           placeholder="Malzeme adı, kodu, marka..." 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            
            <div class="col-md-2">
                <label for="category" class="form-label">Kategori</label>
                <select class="form-select" id="category" name="category">
                    <option value="">Tüm Kategoriler</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" 
                                <?= $category_filter == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label for="status" class="form-label">Durum</label>
                <select class="form-select" id="status" name="status">
                    <option value="">Tüm Durumlar</option>
                    <option value="available" <?= $status_filter === 'available' ? 'selected' : '' ?>>Müsait</option>
                    <option value="assigned" <?= $status_filter === 'assigned' ? 'selected' : '' ?>>Zimmetli</option>
                    <option value="maintenance" <?= $status_filter === 'maintenance' ? 'selected' : '' ?>>Bakımda</option>
                    <option value="broken" <?= $status_filter === 'broken' ? 'selected' : '' ?>>Bozuk</option>
                    <option value="lost" <?= $status_filter === 'lost' ? 'selected' : '' ?>>Kayıp</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label for="brand" class="form-label">Marka</label>
                <select class="form-select" id="brand" name="brand">
                    <option value="">Tüm Markalar</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?= htmlspecialchars($brand['brand']) ?>" 
                                <?= $brand_filter === $brand['brand'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($brand['brand']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filtrele
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Malzeme Listesi -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h5 class="card-title mb-0">
                <i class="fas fa-boxes"></i> Malzeme Listesi
            </h5>
            <small class="text-muted">Toplam <?= $total_records ?> malzeme</small>
        </div>
        
        <?php if (hasPermission('manager')): ?>
        <div>
            <a href="add.php" class="btn btn-success">
                <i class="fas fa-plus"></i> Yeni Malzeme
            </a>
            <a href="categories.php" class="btn btn-info">
                <i class="fas fa-tags"></i> Kategoriler
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="card-body">
        <?php if (empty($items_list)): ?>
            <div class="text-center py-5">
                <i class="fas fa-boxes fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">Malzeme bulunamadı</h5>
                <p class="text-muted">Arama kriterlerinizi değiştirin veya yeni malzeme ekleyin.</p>
                <?php if (hasPermission('manager')): ?>
                    <a href="add.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> İlk Malzemeyi Ekle
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Malzeme Kodu</th>
                            <th>Malzeme Adı</th>
                            <th>Kategori</th>
                            <th>Marka/Model</th>
                            <th>Seri No</th>
                            <th>Durum</th>
                            <th>Zimmetli</th>
                            <th>Fiyat</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items_list as $item): ?>
                        <tr>
                            <td>
                                <strong class="text-primary"><?= htmlspecialchars($item['item_code']) ?></strong>
                            </td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($item['name']) ?></strong>
                                    <?php if ($item['description']): ?>
                                        <br>
                                        <small class="text-muted">
                                            <?= htmlspecialchars(substr($item['description'], 0, 50)) ?>
                                            <?= strlen($item['description']) > 50 ? '...' : '' ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-info">
                                    <?= htmlspecialchars($item['category_name'] ?: 'Kategorisiz') ?>
                                </span>
                            </td>
                            <td>
                                <div>
                                    <?php if ($item['brand']): ?>
                                        <strong><?= htmlspecialchars($item['brand']) ?></strong>
                                    <?php endif; ?>
                                    <?php if ($item['model']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($item['model']) ?></small>
                                    <?php endif; ?>
                                    <?php if (!$item['brand'] && !$item['model']): ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <small class="font-monospace">
                                    <?= htmlspecialchars($item['serial_number'] ?: '-') ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge <?= getStatusBadgeClass($item['status']) ?>">
                                    <?= getStatusText($item['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($item['status'] === 'assigned' && $item['assigned_to']): ?>
                                    <small class="text-info">
                                        <i class="fas fa-user"></i>
                                        <?= htmlspecialchars($item['assigned_to']) ?>
                                    </small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($item['purchase_price']): ?>
                                    <small class="text-success">
                                        ₺<?= number_format($item['purchase_price'], 2) ?>
                                    </small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="view.php?id=<?= $item['id'] ?>" 
                                       class="btn btn-sm btn-info" title="Detayları Görüntüle">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <?php if (hasPermission('manager')): ?>
                                        <a href="edit.php?id=<?= $item['id'] ?>" 
                                           class="btn btn-sm btn-warning" title="Düzenle">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <?php if (hasPermission('admin') && $item['status'] !== 'assigned'): ?>
                                            <a href="delete.php?id=<?= $item['id'] ?>" 
                                               class="btn btn-sm btn-danger" 
                                               title="Sil"
                                               onclick="return confirmDelete('Bu malzemeyi silmek istediğinizden emin misiniz?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Sayfalama -->
            <?php if ($pagination['total_pages'] > 1): ?>
                <nav aria-label="Sayfa navigasyonu">
                    <ul class="pagination justify-content-center">
                        <?php if ($pagination['has_previous']): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page - 1])) ?>">
                                    <i class="fas fa-chevron-left"></i> Önceki
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                            <?php if ($i == $current_page || $i <= 3 || $i > $pagination['total_pages'] - 3 || abs($i - $current_page) <= 2): ?>
                                <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php elseif ($i == 4 && $current_page > 6): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php elseif ($i == $pagination['total_pages'] - 3 && $current_page < $pagination['total_pages'] - 5): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($pagination['has_next']): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page + 1])) ?>">
                                    Sonraki <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>