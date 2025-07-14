<?php
// modules/reports/assignments.php

ob_start();

$page_title = "Zimmet Raporları";
$page_description = "Detaylı zimmet analizi ve raporları";

require_once '../../includes/header.php';

// Yetki kontrolü
requirePermission('manager');

// Filtre parametreleri
$status_filter = isset($_GET['status']) ? clean($_GET['status']) : '';
$period_filter = isset($_GET['period']) ? clean($_GET['period']) : '';
$department_filter = isset($_GET['department']) ? (int)$_GET['department'] : 0;
$date_from = isset($_GET['date_from']) ? clean($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? clean($_GET['date_to']) : '';
$export = isset($_GET['export']) ? clean($_GET['export']) : '';

// Tarih aralığı ayarla
if ($period_filter === 'monthly') {
    $date_from = date('Y-m-01'); // Bu ayın başı
    $date_to = date('Y-m-t');    // Bu ayın sonu
} elseif ($period_filter === 'yearly') {
    $date_from = date('Y-01-01'); // Bu yılın başı
    $date_to = date('Y-12-31');   // Bu yılın sonu
} elseif ($period_filter === 'last_month') {
    $date_from = date('Y-m-01', strtotime('last month'));
    $date_to = date('Y-m-t', strtotime('last month'));
}

try {
    $db = getDB();
    
    // WHERE koşulları
    $where_conditions = [];
    $params = [];
    
    if ($status_filter) {
        $where_conditions[] = "a.status = ?";
        $params[] = $status_filter;
    }
    
    if ($department_filter > 0) {
        $where_conditions[] = "p.department_id = ?";
        $params[] = $department_filter;
    }
    
    if ($date_from) {
        $where_conditions[] = "a.assigned_date >= ?";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $where_conditions[] = "a.assigned_date <= ?";
        $params[] = $date_to;
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Ana sorgu
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
            i.purchase_price,
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
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $assignments = $stmt->fetchAll();
    
    // İstatistikler
    $stats = [
        'total_count' => count($assignments),
        'total_value' => 0,
        'avg_assignment_days' => 0,
        'active_count' => 0,
        'returned_count' => 0,
        'lost_count' => 0,
        'damaged_count' => 0
    ];
    
    $total_days = 0;
    foreach ($assignments as $assignment) {
        if ($assignment['purchase_price']) {
            $stats['total_value'] += $assignment['purchase_price'];
        }
        $total_days += $assignment['assignment_days'];
        
        switch ($assignment['status']) {
            case 'active': $stats['active_count']++; break;
            case 'returned': $stats['returned_count']++; break;
            case 'lost': $stats['lost_count']++; break;
            case 'damaged': $stats['damaged_count']++; break;
        }
    }
    
    if ($stats['total_count'] > 0) {
        $stats['avg_assignment_days'] = round($total_days / $stats['total_count']);
    }
    
    // Departman listesi
    $dept_stmt = $db->query("SELECT id, name FROM departments ORDER BY name");
    $departments = $dept_stmt->fetchAll();
    
} catch(Exception $e) {
    setError("Rapor verileri yüklenirken hata oluştu: " . $e->getMessage());
    $assignments = [];
    $stats = [];
    $departments = [];
}

// Excel export
if ($export === 'excel' && !empty($assignments)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zimmet_raporu_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV başlıkları
    fputcsv($output, [
        'Zimmet No',
        'Personel',
        'Sicil No',
        'Departman',
        'Malzeme',
        'Kategori',
        'Marka/Model',
        'Zimmet Tarihi',
        'İade Tarihi',
        'Süre (Gün)',
        'Durum',
        'Değer (TL)',
        'Zimmet Veren'
    ]);
    
    // Veri satırları
    foreach ($assignments as $assignment) {
        fputcsv($output, [
            $assignment['assignment_number'],
            $assignment['personnel_name'],
            $assignment['sicil_no'],
            $assignment['department_name'],
            $assignment['item_name'],
            $assignment['category_name'],
            $assignment['brand'] . ' ' . $assignment['model'],
            formatDate($assignment['assigned_date']),
            formatDate($assignment['return_date']),
            $assignment['assignment_days'],
            getAssignmentStatusText($assignment['status']),
            $assignment['purchase_price'] ? number_format($assignment['purchase_price'], 2) : '',
            $assignment['assigned_by_user']
        ]);
    }
    
    fclose($output);
    exit();
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

<!-- Rapor Başlığı -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4>
            <?php
            if ($status_filter) {
                echo getAssignmentStatusText($status_filter) . ' Zimmet Raporu';
            } elseif ($period_filter === 'monthly') {
                echo 'Aylık Zimmet Raporu (' . date('F Y') . ')';
            } elseif ($period_filter === 'yearly') {
                echo 'Yıllık Zimmet Raporu (' . date('Y') . ')';
            } else {
                echo 'Zimmet Raporu';
            }
            ?>
        </h4>
        <small class="text-muted">
            Rapor Tarihi: <?= date('d.m.Y H:i') ?>
            <?php if ($date_from || $date_to): ?>
                | Tarih Aralığı: <?= formatDate($date_from) ?> - <?= formatDate($date_to) ?>
            <?php endif; ?>
        </small>
    </div>
    
    <div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Raporlara Dön
        </a>
        <?php if (!empty($assignments)): ?>
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
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stats-number text-primary"><?= $stats['total_count'] ?></div>
            <div class="stats-label">Toplam Kayıt</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-success">
                <i class="fas fa-handshake"></i>
            </div>
            <div class="stats-number text-success"><?= $stats['active_count'] ?></div>
            <div class="stats-label">Aktif</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-secondary">
                <i class="fas fa-undo"></i>
            </div>
            <div class="stats-number text-secondary"><?= $stats['returned_count'] ?></div>
            <div class="stats-label">İade Edildi</div>
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
            <div class="stats-icon text-warning">
                <i class="fas fa-tools"></i>
            </div>
            <div class="stats-number text-warning"><?= $stats['damaged_count'] ?></div>
            <div class="stats-label">Hasarlı</div>
        </div>
    </div>
    
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon text-info">
                <i class="fas fa-calculator"></i>
            </div>
            <div class="stats-number text-info">₺<?= number_format($stats['total_value']) ?></div>
            <div class="stats-label">Toplam Değer</div>
        </div>
    </div>
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
                <label for="period" class="form-label">Dönem</label>
                <select class="form-select" id="period" name="period">
                    <option value="">Özel Tarih</option>
                    <option value="monthly" <?= $period_filter === 'monthly' ? 'selected' : '' ?>>Bu Ay</option>
                    <option value="last_month" <?= $period_filter === 'last_month' ? 'selected' : '' ?>>Geçen Ay</option>
                    <option value="yearly" <?= $period_filter === 'yearly' ? 'selected' : '' ?>>Bu Yıl</option>
                </select>
            </div>
            
            <div class="col-md-2">
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
            
            <div class="col-md-2">
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

<!-- Rapor Verileri -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">
            <i class="fas fa-table"></i> Zimmet Detayları
        </h6>
        <span class="badge bg-primary"><?= count($assignments) ?> Kayıt</span>
    </div>
    
    <div class="card-body">
        <?php if (empty($assignments)): ?>
            <div class="text-center py-5">
                <i class="fas fa-search fa-3x text-muted mb-3"></i>
                <h6 class="text-muted">Filtrelere uygun kayıt bulunamadı</h6>
                <p class="text-muted">Filtre kriterlerinizi değiştirip tekrar deneyin.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Zimmet No</th>
                            <th>Personel</th>
                            <th>Departman</th>
                            <th>Malzeme</th>
                            <th>Kategori</th>
                            <th>Zimmet Tarihi</th>
                            <th>İade Tarihi</th>
                            <th>Süre</th>
                            <th>Durum</th>
                            <th>Değer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $assignment): ?>
                        <tr>
                            <td>
                                <a href="../assignments/view.php?id=<?= $assignment['id'] ?>" target="_blank" class="text-decoration-none">
                                    <?= htmlspecialchars($assignment['assignment_number']) ?>
                                </a>
                            </td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($assignment['personnel_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($assignment['sicil_no']) ?></small>
                                </div>
                            </td>
                            <td>
                                <small><?= htmlspecialchars($assignment['department_name']) ?></small>
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
                            <td>
                                <span class="badge bg-info">
                                    <?= htmlspecialchars($assignment['category_name']) ?>
                                </span>
                            </td>
                            <td>
                                <small><?= formatDate($assignment['assigned_date']) ?></small>
                            </td>
                            <td>
                                <small><?= formatDate($assignment['return_date']) ?></small>
                            </td>
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
                                <?php if ($assignment['purchase_price']): ?>
                                    <small class="text-success">
                                        ₺<?= number_format($assignment['purchase_price'], 2) ?>
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
                            <h6>Rapor Özeti</h6>
                            <ul class="list-unstyled mb-0">
                                <li><strong>Toplam Kayıt:</strong> <?= $stats['total_count'] ?></li>
                                <li><strong>Toplam Değer:</strong> ₺<?= number_format($stats['total_value'], 2) ?></li>
                                <li><strong>Ortalama Süre:</strong> <?= $stats['avg_assignment_days'] ?> gün</li>
                                <li><strong>Aktif Oran:</strong> %<?= $stats['total_count'] > 0 ? round(($stats['active_count'] / $stats['total_count']) * 100) : 0 ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6>Durum Dağılımı</h6>
                            <ul class="list-unstyled mb-0">
                                <li><span class="badge bg-success me-2"><?= $stats['active_count'] ?></span> Aktif</li>
                                <li><span class="badge bg-secondary me-2"><?= $stats['returned_count'] ?></span> İade Edildi</li>
                                <li><span class="badge bg-danger me-2"><?= $stats['lost_count'] ?></span> Kayıp</li>
                                <li><span class="badge bg-warning me-2"><?= $stats['damaged_count'] ?></span> Hasarlı</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Dönem seçildiğinde tarih alanlarını gizle/göster
    const periodSelect = document.getElementById('period');
    const dateFromField = document.getElementById('date_from');
    const dateToField = document.getElementById('date_to');
    
    periodSelect.addEventListener('change', function() {
        if (this.value && this.value !== '') {
            dateFromField.disabled = true;
            dateToField.disabled = true;
            dateFromField.value = '';
            dateToField.value = '';
        } else {
            dateFromField.disabled = false;
            dateToField.disabled = false;
        }
    });
    
    // Sayfa yüklendiğinde kontrol et
    if (periodSelect.value && periodSelect.value !== '') {
        dateFromField.disabled = true;
        dateToField.disabled = true;
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>