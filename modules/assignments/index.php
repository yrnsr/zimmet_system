<?php
// modules/assignments/index.php

ob_start();

$page_title = "Zimmet İşlemleri";
$page_description = "Tüm zimmet işlemlerinin yönetimi ve takibi";

require_once '../../includes/header.php';

// Sayfalama ayarları
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Arama ve filtreleme
$search = isset($_GET['search']) ? clean($_GET['search']) : '';
$personnel_filter = isset($_GET['personnel']) ? (int)$_GET['personnel'] : 0;
$status_filter = isset($_GET['status']) ? clean($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? clean($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? clean($_GET['date_to']) : '';

try {
    $db = getDB();
    
    // WHERE koşulları
    $where_conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(a.assignment_number LIKE ? OR i.name LIKE ? OR i.item_code LIKE ? OR CONCAT(p.name, ' ', p.surname) LIKE ? OR p.sicil_no LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    }
    
    if ($personnel_filter > 0) {
        $where_conditions[] = "a.personnel_id = ?";
        $params[] = $personnel_filter;
    }
    
    if ($status_filter !== '') {
        $where_conditions[] = "a.status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($date_from)) {
        $where_conditions[] = "a.assigned_date >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = "a.assigned_date <= ?";
        $params[] = $date_to;
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Toplam kayıt sayısı
    $count_sql = "
        SELECT COUNT(*) as total 
        FROM assignments a
        JOIN personnel p ON a.personnel_id = p.id
        JOIN items i ON a.item_id = i.id
        $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetch()['total'];
    
    // Zimmet listesi
    $sql = "
        SELECT 
            a.*,
            CONCAT(p.name, ' ', p.surname) as personnel_name,
            p.sicil_no,
            d.name as department_name,
            i.name as item_name,
            i.item_code,
            i.brand,
            i.model,
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
        $where_clause
        ORDER BY a.assigned_date DESC 
        LIMIT $records_per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $assignments_list = $stmt->fetchAll();
    
    // Personel listesi (filtre için)
    $personnel_stmt = $db->query("
        SELECT p.id, CONCAT(p.name, ' ', p.surname, ' (', p.sicil_no, ')') as full_name 
        FROM personnel p 
        WHERE p.is_active = 1 
        ORDER BY p.name, p.surname
    ");
    $personnel_list = $personnel_stmt->fetchAll();
    
    // Durum istatistikleri
    $stats_stmt = $db->query("
        SELECT 
            status,
            COUNT(*) as count
        FROM assignments 
        GROUP BY status
    ");
    $status_stats = [];
    while ($row = $stats_stmt->fetch()) {
        $status_stats[$row['status']] = $row['count'];
    }
    
    // Sayfalama bilgileri
    $pagination = paginate($total_records, $records_per_page, $current_page);
    
} catch(Exception $e) {
    setError("Zimmet listesi yüklenirken hata oluştu: " . $e->getMessage());
    $assignments_list = [];
    $personnel_list = [];
    $status_stats = [];
    $pagination = ['total_pages' => 0];
}

// Durum çeviri
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
?>

<!-- Durum İstatistikleri -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon text-success">
                <i class="fas fa-handshake"></i>
            </div>
            <div class="stats-number text-success"><?= $status_stats['active'] ?? 0 ?></div>
            <div class="stats-label">Aktif Zimmet</div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon text-secondary">
                <i class="fas fa-undo"></i>
            </div>
            <div class="stats-number text-secondary"><?= $status_stats['returned'] ?? 0 ?></div>
            <div class="stats-label">İade Edildi</div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon text-danger">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stats-number text-danger"><?= $status_stats['lost'] ?? 0 ?></div>
            <div class="stats-label">Kayıp</div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon text-warning">
                <i class="fas fa-tools"></i>
            </div>
            <div class="stats-number text-warning"><?= $status_stats['damaged'] ?? 0 ?></div>
            <div class="stats-label">Hasarlı</div>
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
                           placeholder="Zimmet no, personel, malzeme..." 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            
            <div class="col-md-3">
                <label for="personnel" class="form-label">Personel</label>
                <select class="form-select" id="personnel" name="personnel">
                    <option value="">Tüm Personel</option>
                    <?php foreach ($personnel_list as $person): ?>
                        <option value="<?= $person['id'] ?>" 
                                <?= $personnel_filter == $person['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($person['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label for="status" class="form-label">Durum</label>
                <select class="form-select" id="status" name="status">
                    <option value="">Tüm Durumlar</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="returned" <?= $status_filter === 'returned' ? 'selected' : '' ?>>İade Edildi</option>
                    <option value="lost" <?= $status_filter === 'lost' ? 'selected' : '' ?>>Kayıp</option>
                    <option value="damaged" <?= $status_filter === 'damaged' ? 'selected' : '' ?>>Hasarlı</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label for="date_from" class="form-label">Başlangıç</label>
                <input type="date" class="form-control" id="date_from" name="date_from" 
                       value="<?= htmlspecialchars($date_from) ?>">
            </div>
            
            <div class="col-md-2">
                <label for="date_to" class="form-label">Bitiş</label>
                <input type="date" class="form-control" id="date_to" name="date_to" 
                       value="<?= htmlspecialchars($date_to) ?>">
            </div>
            
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Filtrele
                </button>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fas fa-times"></i> Temizle
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Zimmet Listesi -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h5 class="card-title mb-0">
                <i class="fas fa-clipboard-list"></i> Zimmet İşlemleri
            </h5>
            <small class="text-muted">Toplam <?= $total_records ?> zimmet kaydı</small>
        </div>
        
        <?php if (hasPermission('manager')): ?>
        <div>
            <a href="add.php" class="btn btn-success">
                <i class="fas fa-plus"></i> Yeni Zimmet
            </a>
            <a href="search.php" class="btn btn-info">
                <i class="fas fa-search"></i> Gelişmiş Arama
            </a>
            <a href="export.php?<?= http_build_query($_GET) ?>" class="btn btn-secondary">
                <i class="fas fa-download"></i> Excel'e Aktar
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="card-body">
        <?php if (empty($assignments_list)): ?>
            <div class="text-center py-5">
                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">Zimmet kaydı bulunamadı</h5>
                <p class="text-muted">Arama kriterlerinizi değiştirin veya yeni zimmet verin.</p>
                <?php if (hasPermission('manager')): ?>
                    <a href="add.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> İlk Zimmeti Ver
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Zimmet No</th>
                            <th>Personel</th>
                            <th>Malzeme</th>
                            <th>Veriliş Tarihi</th>
                            <th>İade Tarihi</th>
                            <th>Süre</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments_list as $assignment): ?>
                        <tr>
                            <td>
                                <strong class="text-primary"><?= htmlspecialchars($assignment['assignment_number']) ?></strong>
                            </td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($assignment['personnel_name']) ?></strong><br>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($assignment['sicil_no']) ?>
                                        <?php if ($assignment['department_name']): ?>
                                            - <?= htmlspecialchars($assignment['department_name']) ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($assignment['item_name']) ?></strong><br>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($assignment['item_code']) ?>
                                        <?php if ($assignment['brand'] || $assignment['model']): ?>
                                            - <?= htmlspecialchars($assignment['brand'] . ' ' . $assignment['model']) ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </td>
                            <td><?= formatDate($assignment['assigned_date']) ?></td>
                            <td><?= formatDate($assignment['return_date']) ?></td>
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
                            <td>
                                <span class="badge <?= getAssignmentStatusBadgeClass($assignment['status']) ?>">
                                    <?= getAssignmentStatusText($assignment['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="view.php?id=<?= $assignment['id'] ?>" 
                                       class="btn btn-sm btn-info" title="Detayları Görüntüle">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <?php if (hasPermission('manager')): ?>
                                        <?php if ($assignment['status'] === 'active'): ?>
                                            <a href="return.php?id=<?= $assignment['id'] ?>" 
                                               class="btn btn-sm btn-warning" title="İade Al">
                                                <i class="fas fa-undo"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a href="edit.php?id=<?= $assignment['id'] ?>" 
                                           class="btn btn-sm btn-secondary" title="Düzenle">
                                            <i class="fas fa-edit"></i>
                                        </a>
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