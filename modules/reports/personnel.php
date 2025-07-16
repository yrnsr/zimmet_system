<?php
// modules/reports/personnel.php

$page_title = "Personel Raporları";
$page_description = "Personel bazlı detaylı raporlar";

require_once '../../includes/header.php';

// Yetki kontrolü
requirePermission('manager');

// Rapor tipi
$report_type = $_GET['type'] ?? 'all';
$department_filter = $_GET['department'] ?? '';

try {
    $db = getDB();
    
    // Departman listesi
    $stmt = $db->query("SELECT id, name FROM departments ORDER BY name");
    $departments = $stmt->fetchAll();
    
    // Rapor sorgusu
    $sql = "
        SELECT 
            p.id,
            p.sicil_no,
            p.name,
            p.surname,
            p.email,
            p.phone,
            d.name as department_name,
            COUNT(DISTINCT a.id) as total_assignments,
            COUNT(DISTINCT CASE WHEN a.status = 'active' THEN a.id END) as active_assignments,
            COUNT(DISTINCT CASE WHEN a.status = 'returned' THEN a.id END) as returned_assignments,
            COUNT(DISTINCT CASE WHEN a.status = 'lost' THEN a.id END) as lost_assignments,
            COUNT(DISTINCT CASE WHEN a.status = 'damaged' THEN a.id END) as damaged_assignments
        FROM personnel p
        LEFT JOIN departments d ON p.department_id = d.id
        LEFT JOIN assignments a ON p.id = a.personnel_id
        WHERE p.is_active = 1
    ";
    
    $params = [];
    
    // Departman filtresi
    if (!empty($department_filter)) {
        $sql .= " AND p.department_id = :department_id";
        $params[':department_id'] = $department_filter;
    }
    
    // Rapor tipine göre filtreleme
    switch($report_type) {
        case 'with_assignments':
            $sql .= " AND EXISTS (SELECT 1 FROM assignments WHERE personnel_id = p.id AND status = 'active')";
            break;
            
        case 'without_assignments':
            $sql .= " AND NOT EXISTS (SELECT 1 FROM assignments WHERE personnel_id = p.id AND status = 'active')";
            break;
            
        case 'performance':
            // Performans raporu için özel sıralama
            break;
    }
    
    $sql .= " GROUP BY p.id";
    
    // Sıralama
    if ($report_type == 'performance') {
        $sql .= " ORDER BY total_assignments DESC";
    } else {
        $sql .= " ORDER BY d.name, p.name, p.surname";
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $personnel = $stmt->fetchAll();
    
    // Özet istatistikler
    $stats = [
        'total_personnel' => count($personnel),
        'with_assignments' => 0,
        'without_assignments' => 0,
        'total_active_assignments' => 0
    ];
    
    foreach ($personnel as $person) {
        if ($person['active_assignments'] > 0) {
            $stats['with_assignments']++;
        } else {
            $stats['without_assignments']++;
        }
        $stats['total_active_assignments'] += $person['active_assignments'];
    }
    
} catch(Exception $e) {
    setError("Rapor oluşturulurken hata: " . $e->getMessage());
    $personnel = [];
    $stats = [];
}
?>

<!-- Filtreler -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-filter"></i> Rapor Filtreleri
        </h5>
    </div>
    <div class="card-body">
        <form method="GET" action="">
            <div class="row">
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="type" class="form-label">Rapor Tipi</label>
                        <select class="form-select" id="type" name="type">
                            <option value="all" <?= $report_type == 'all' ? 'selected' : '' ?>>Tüm Personel</option>
                            <option value="with_assignments" <?= $report_type == 'with_assignments' ? 'selected' : '' ?>>Zimmetli Personeller</option>
                            <option value="without_assignments" <?= $report_type == 'without_assignments' ? 'selected' : '' ?>>Zimmetsiz Personeller</option>
                            <option value="performance" <?= $report_type == 'performance' ? 'selected' : '' ?>>Performans Raporu</option>
                            <option value="by_department" <?= $report_type == 'by_department' ? 'selected' : '' ?>>Departman Bazlı</option>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="department" class="form-label">Departman</label>
                        <select class="form-select" id="department" name="department">
                            <option value="">Tüm Departmanlar</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>" <?= $department_filter == $dept['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Rapor Oluştur
                            </button>
                            <button type="button" onclick="window.print();" class="btn btn-secondary">
                                <i class="fas fa-print"></i> Yazdır
                            </button>
                            <button type="button" onclick="exportToExcel();" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Özet İstatistikler -->
<?php if (!empty($stats)): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-icon text-primary">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-number text-primary"><?= $stats['total_personnel'] ?></div>
            <div class="stats-label">Toplam Personel</div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-icon text-success">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="stats-number text-success"><?= $stats['with_assignments'] ?></div>
            <div class="stats-label">Zimmetli Personel</div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-icon text-secondary">
                <i class="fas fa-user-times"></i>
            </div>
            <div class="stats-number text-secondary"><?= $stats['without_assignments'] ?></div>
            <div class="stats-label">Zimmetsiz Personel</div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-icon text-warning">
                <i class="fas fa-handshake"></i>
            </div>
            <div class="stats-number text-warning"><?= $stats['total_active_assignments'] ?></div>
            <div class="stats-label">Toplam Aktif Zimmet</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Rapor Tablosu -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-list"></i> Personel Listesi
            <?php if ($report_type == 'performance'): ?>
                <small class="text-muted">(Zimmet sayısına göre sıralı)</small>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($personnel)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-user-slash fa-3x mb-3"></i>
                <p>Kriterlere uygun personel bulunamadı.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover" id="personnelTable">
                    <thead>
                        <tr>
                            <th>Sicil No</th>
                            <th>Ad Soyad</th>
                            <th>Departman</th>
                            <th>İletişim</th>
                            <th class="text-center">Toplam Zimmet</th>
                            <th class="text-center">Aktif</th>
                            <th class="text-center">İade</th>
                            <th class="text-center">Kayıp</th>
                            <th class="text-center">Hasarlı</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($personnel as $person): ?>
                        <tr>
                            <td><?= htmlspecialchars($person['sicil_no']) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($person['name'] . ' ' . $person['surname']) ?></strong>
                            </td>
                            <td><?= htmlspecialchars($person['department_name']) ?></td>
                            <td>
                                <?php if ($person['email']): ?>
                                    <small><i class="fas fa-envelope"></i> <?= htmlspecialchars($person['email']) ?></small><br>
                                <?php endif; ?>
                                <?php if ($person['phone']): ?>
                                    <small><i class="fas fa-phone"></i> <?= htmlspecialchars($person['phone']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary"><?= $person['total_assignments'] ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($person['active_assignments'] > 0): ?>
                                    <span class="badge bg-success"><?= $person['active_assignments'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($person['returned_assignments'] > 0): ?>
                                    <span class="badge bg-secondary"><?= $person['returned_assignments'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($person['lost_assignments'] > 0): ?>
                                    <span class="badge bg-danger"><?= $person['lost_assignments'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($person['damaged_assignments'] > 0): ?>
                                    <span class="badge bg-warning"><?= $person['damaged_assignments'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url('modules/personnel/view.php?id=' . $person['id']) ?>" 
                                   class="btn btn-sm btn-info" title="Detay">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($person['active_assignments'] > 0): ?>
                                    <a href="<?= url('modules/assignments/index.php?personnel_id=' . $person['id']) ?>" 
                                       class="btn btn-sm btn-warning" title="Zimmetlerini Gör">
                                        <i class="fas fa-list"></i>
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

<script>
function exportToExcel() {
    // Basit Excel export
    var table = document.getElementById('personnelTable');
    var html = table.outerHTML;
    var url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    var downloadLink = document.createElement("a");
    downloadLink.href = url;
    downloadLink.download = "personel_raporu_<?= date('Y-m-d') ?>.xls";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once '../../includes/footer.php'; ?>