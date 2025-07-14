<?php
// modules/personnel/index.php

$page_title = "Personel Yönetimi";
$page_description = "Şirket personellerinin listesi ve yönetimi";

require_once '../../includes/header.php';

// Sayfalama ayarları
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Arama ve filtreleme
$search = isset($_GET['search']) ? clean($_GET['search']) : '';
$department_filter = isset($_GET['department']) ? (int)$_GET['department'] : 0;
$status_filter = isset($_GET['status']) ? clean($_GET['status']) : '';

try {
    $db = getDB();
    
    // WHERE koşulları
    $where_conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(p.name LIKE ? OR p.surname LIKE ? OR p.sicil_no LIKE ? OR p.email LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    if ($department_filter > 0) {
        $where_conditions[] = "p.department_id = ?";
        $params[] = $department_filter;
    }
    
    if ($status_filter !== '') {
        $where_conditions[] = "p.is_active = ?";
        $params[] = ($status_filter === 'active') ? 1 : 0;
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Toplam kayıt sayısı
    $count_sql = "
        SELECT COUNT(*) as total 
        FROM personnel p 
        LEFT JOIN departments d ON p.department_id = d.id 
        $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetch()['total'];
    
    // Personel listesi
    $sql = "
        SELECT 
            p.*,
            d.name as department_name,
            (SELECT COUNT(*) FROM assignments a WHERE a.personnel_id = p.id AND a.status = 'active') as active_assignments
        FROM personnel p 
        LEFT JOIN departments d ON p.department_id = d.id 
        $where_clause
        ORDER BY p.created_at DESC 
        LIMIT $records_per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $personnel_list = $stmt->fetchAll();
    
    // Departman listesi (filtre için)
    $dept_stmt = $db->query("SELECT id, name FROM departments ORDER BY name");
    $departments = $dept_stmt->fetchAll();
    
    // Sayfalama bilgileri
    $pagination = paginate($total_records, $records_per_page, $current_page);
    
} catch(Exception $e) {
    setError("Personel listesi yüklenirken hata oluştu: " . $e->getMessage());
    $personnel_list = [];
    $departments = [];
    $pagination = ['total_pages' => 0];
}
?>

<!-- Filtre ve Arama -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label for="search" class="form-label">Arama</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" id="search" name="search" 
                           placeholder="Ad, soyad, sicil no veya email..." 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            
            <div class="col-md-3">
                <label for="department" class="form-label">Departman</label>
                <select class="form-select" id="department" name="department">
                    <option value="">Tüm Departmanlar</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" 
                                <?= $department_filter == $dept['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label for="status" class="form-label">Durum</label>
                <select class="form-select" id="status" name="status">
                    <option value="">Tüm Durumlar</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Pasif</option>
                </select>
            </div>
            
            <div class="col-md-2">
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

<!-- Personel Listesi -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h5 class="card-title mb-0">
                <i class="fas fa-users"></i> Personel Listesi
            </h5>
            <small class="text-muted">Toplam <?= $total_records ?> personel</small>
        </div>
        
        <?php if (hasPermission('manager')): ?>
        <div>
            <a href="add.php" class="btn btn-success">
                <i class="fas fa-plus"></i> Yeni Personel
            </a>
            <a href="export.php?<?= http_build_query($_GET) ?>" class="btn btn-info">
                <i class="fas fa-download"></i> Excel'e Aktar
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="card-body">
        <?php if (empty($personnel_list)): ?>
            <div class="text-center py-5">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">Personel bulunamadı</h5>
                <p class="text-muted">Arama kriterlerinizi değiştirin veya yeni personel ekleyin.</p>
                <?php if (hasPermission('manager')): ?>
                    <a href="add.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> İlk Personeli Ekle
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Sicil No</th>
                            <th>Ad Soyad</th>
                            <th>Departman</th>
                            <th>Görev</th>
                            <th>İletişim</th>
                            <th>Aktif Zimmet</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($personnel_list as $person): ?>
                        <tr>
                            <td>
                                <strong class="text-primary"><?= htmlspecialchars($person['sicil_no']) ?></strong>
                            </td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($person['name'] . ' ' . $person['surname']) ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        İşe Başlama: <?= formatDate($person['hire_date']) ?>
                                    </small>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-info">
                                    <?= htmlspecialchars($person['department_name'] ?: 'Belirtilmemiş') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($person['position'] ?: '-') ?></td>
                            <td>
                                <div>
                                    <?php if ($person['email']): ?>
                                        <i class="fas fa-envelope text-muted"></i>
                                        <a href="mailto:<?= htmlspecialchars($person['email']) ?>">
                                            <?= htmlspecialchars($person['email']) ?>
                                        </a><br>
                                    <?php endif; ?>
                                    
                                    <?php if ($person['phone']): ?>
                                        <i class="fas fa-phone text-muted"></i>
                                        <a href="tel:<?= htmlspecialchars($person['phone']) ?>">
                                            <?= htmlspecialchars($person['phone']) ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $person['active_assignments'] > 0 ? 'bg-warning' : 'bg-secondary' ?>">
                                    <?= $person['active_assignments'] ?> Zimmet
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $person['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                    <?= $person['is_active'] ? 'Aktif' : 'Pasif' ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="view.php?id=<?= $person['id'] ?>" 
                                       class="btn btn-sm btn-info" title="Detayları Görüntüle">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <?php if (hasPermission('manager')): ?>
                                        <a href="edit.php?id=<?= $person['id'] ?>" 
                                           class="btn btn-sm btn-warning" title="Düzenle">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <?php if (hasPermission('admin') && $person['active_assignments'] == 0): ?>
                                            <a href="delete.php?id=<?= $person['id'] ?>" 
                                               class="btn btn-sm btn-danger" 
                                               title="Sil"
                                               onclick="return confirmDelete('Bu personeli silmek istediğinizden emin misiniz?')">
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