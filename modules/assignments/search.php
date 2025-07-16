<?php
// modules/assignments/search.php

$page_title = "Zimmet Ara";
$page_description = "Zimmet kayıtlarını arayın";

require_once '../../includes/header.php';

// Arama parametrelerini al
$search_query = $_GET['q'] ?? '';
$search_type = $_GET['type'] ?? 'all';
$status_filter = $_GET['status'] ?? '';

$results = [];
$search_performed = false;

// Arama işlemi
if (!empty($search_query)) {
    $search_performed = true;
    
    try {
        $db = getDB();
        
        // Arama sorgusu
        $sql = "
            SELECT 
                a.id,
                a.assignment_number,
                a.assigned_date,
                a.return_date,
                a.status,
                a.notes,
                p.name as personnel_name,
                p.surname as personnel_surname,
                p.sicil_no,
                d.name as department_name,
                i.name as item_name,
                i.inventory_no,
                i.serial_no,
                c.name as category_name,
                u.username as assigned_by_user
            FROM assignments a
            JOIN personnel p ON a.personnel_id = p.id
            JOIN departments d ON p.department_id = d.id
            JOIN items i ON a.item_id = i.id
            JOIN categories c ON i.category_id = c.id
            JOIN users u ON a.assigned_by = u.id
            WHERE 1=1
        ";
        
        $params = [];
        
        // Arama tipine göre filtreleme
        switch($search_type) {
            case 'assignment_no':
                $sql .= " AND a.assignment_number LIKE :query";
                $params[':query'] = '%' . $search_query . '%';
                break;
                
            case 'personnel':
                $sql .= " AND (p.name LIKE :query OR p.surname LIKE :query OR p.sicil_no LIKE :query)";
                $params[':query'] = '%' . $search_query . '%';
                break;
                
            case 'item':
                $sql .= " AND (i.name LIKE :query OR i.inventory_no LIKE :query OR i.serial_no LIKE :query)";
                $params[':query'] = '%' . $search_query . '%';
                break;
                
            case 'all':
            default:
                $sql .= " AND (
                    a.assignment_number LIKE :query OR
                    p.name LIKE :query OR 
                    p.surname LIKE :query OR 
                    p.sicil_no LIKE :query OR
                    i.name LIKE :query OR 
                    i.inventory_no LIKE :query OR
                    i.serial_no LIKE :query
                )";
                $params[':query'] = '%' . $search_query . '%';
                break;
        }
        
        // Durum filtresi
        if (!empty($status_filter)) {
            $sql .= " AND a.status = :status";
            $params[':status'] = $status_filter;
        }
        
        $sql .= " ORDER BY a.created_at DESC LIMIT 100";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
    } catch(Exception $e) {
        setError("Arama sırasında hata oluştu: " . $e->getMessage());
    }
}
?>

<!-- Arama Formu -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-search"></i> Zimmet Arama
        </h5>
    </div>
    <div class="card-body">
        <form method="GET" action="">
            <div class="row">
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="q" class="form-label">Arama Metni</label>
                        <input type="text" class="form-control" id="q" name="q" 
                               value="<?= htmlspecialchars($search_query) ?>" 
                               placeholder="Zimmet no, personel adı, malzeme adı...">
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="mb-3">
                        <label for="type" class="form-label">Arama Tipi</label>
                        <select class="form-select" id="type" name="type">
                            <option value="all" <?= $search_type === 'all' ? 'selected' : '' ?>>Tümü</option>
                            <option value="assignment_no" <?= $search_type === 'assignment_no' ? 'selected' : '' ?>>Zimmet No</option>
                            <option value="personnel" <?= $search_type === 'personnel' ? 'selected' : '' ?>>Personel</option>
                            <option value="item" <?= $search_type === 'item' ? 'selected' : '' ?>>Malzeme</option>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="mb-3">
                        <label for="status" class="form-label">Durum</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Tümü</option>
                            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Aktif</option>
                            <option value="returned" <?= $status_filter === 'returned' ? 'selected' : '' ?>>İade Edildi</option>
                            <option value="lost" <?= $status_filter === 'lost' ? 'selected' : '' ?>>Kayıp</option>
                            <option value="damaged" <?= $status_filter === 'damaged' ? 'selected' : '' ?>>Hasarlı</option>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="mb-3">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Ara
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Arama Sonuçları -->
<?php if ($search_performed): ?>
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-list"></i> Arama Sonuçları
            <?php if (count($results) > 0): ?>
                <span class="badge bg-primary"><?= count($results) ?> kayıt bulundu</span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($results)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-search fa-3x mb-3"></i>
                <p>Arama kriterlerinize uygun kayıt bulunamadı.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Zimmet No</th>
                            <th>Personel</th>
                            <th>Departman</th>
                            <th>Malzeme</th>
                            <th>Kategori</th>
                            <th>Zimmet Tarihi</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($result['assignment_number']) ?></strong>
                            </td>
                            <td>
                                <?= htmlspecialchars($result['personnel_name'] . ' ' . $result['personnel_surname']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($result['sicil_no']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($result['department_name']) ?></td>
                            <td>
                                <?= htmlspecialchars($result['item_name']) ?><br>
                                <small class="text-muted">
                                    <?php if ($result['inventory_no']): ?>
                                        Env: <?= htmlspecialchars($result['inventory_no']) ?>
                                    <?php endif; ?>
                                    <?php if ($result['serial_no']): ?>
                                        | Seri: <?= htmlspecialchars($result['serial_no']) ?>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td><?= htmlspecialchars($result['category_name']) ?></td>
                            <td><?= formatDate($result['assigned_date']) ?></td>
                            <td>
                                <?php
                                $badge_class = '';
                                $status_text = '';
                                switch($result['status']) {
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
                                <a href="<?= url('modules/assignments/view.php?id=' . $result['id']) ?>" 
                                   class="btn btn-sm btn-info" title="Görüntüle">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (hasPermission('manager') && $result['status'] === 'active'): ?>
                                <a href="<?= url('modules/assignments/return.php?id=' . $result['id']) ?>" 
                                   class="btn btn-sm btn-warning" title="İade Al">
                                    <i class="fas fa-undo"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Hızlı Filtreler -->
<div class="mt-4">
    <h6>Hızlı Aramalar:</h6>
    <div class="btn-group" role="group">
        <a href="?type=all&status=active" class="btn btn-sm btn-outline-success">
            <i class="fas fa-check"></i> Aktif Zimmetler
        </a>
        <a href="?type=all&status=returned" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-undo"></i> İade Edilenler
        </a>
        <a href="?type=all&status=lost" class="btn btn-sm btn-outline-danger">
            <i class="fas fa-exclamation-triangle"></i> Kayıp Malzemeler
        </a>
        <a href="?type=all&status=damaged" class="btn btn-sm btn-outline-warning">
            <i class="fas fa-tools"></i> Hasarlı Malzemeler
        </a>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>