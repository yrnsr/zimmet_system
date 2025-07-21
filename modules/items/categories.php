<?php
// modules/items/categories.php

ob_start();

$page_title = "Kategori Yönetimi";
$page_description = "Malzeme kategorilerini yönetin";

require_once '../../includes/header.php';

// Yetki kontrolü
if (!hasPermission('manager')) {
    setError("Bu sayfaya erişim yetkiniz bulunmamaktadır.");
    header("Location: ../../index.php");
    exit;
}

// İşlem türü kontrolü
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    $db = getDB();
    
    // Form işlemleri
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_category'])) {
            // Yeni kategori ekleme
            $name = clean($_POST['name']);
            $description = clean($_POST['description']);
            
            if (empty($name)) {
                setError("Kategori adı boş olamaz.");
            } else {
                // Aynı isimde kategori var mı kontrol et
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE name = ?");
                $check_stmt->execute([$name]);
                
                if ($check_stmt->fetchColumn() > 0) {
                    setError("Bu isimde bir kategori zaten mevcut.");
                } else {
                    $stmt = $db->prepare("INSERT INTO categories (name, description, created_at) VALUES (?, ?, NOW())");
                    $stmt->execute([$name, $description]);
                    setSuccess("Kategori başarıyla eklendi.");
                }
            }
        }
        
        if (isset($_POST['update_category'])) {
            // Kategori güncelleme
            $name = clean($_POST['name']);
            $description = clean($_POST['description']);
            $id = (int)$_POST['id'];
            
            if (empty($name)) {
                setError("Kategori adı boş olamaz.");
            } else {
                // Aynı isimde başka kategori var mı kontrol et
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE name = ? AND id != ?");
                $check_stmt->execute([$name, $id]);
                
                if ($check_stmt->fetchColumn() > 0) {
                    setError("Bu isimde bir kategori zaten mevcut.");
                } else {
                    $stmt = $db->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
                    $stmt->execute([$name, $description, $id]);
                    setSuccess("Kategori başarıyla güncellendi.");
                    $action = 'list';
                }
            }
        }
        
        if (isset($_POST['delete_category'])) {
            // Kategori silme
            $id = (int)$_POST['id'];
            
            // Bu kategoriye ait malzeme var mı kontrol et
            $check_stmt = $db->prepare("SELECT COUNT(*) FROM items WHERE category_id = ?");
            $check_stmt->execute([$id]);
            $item_count = $check_stmt->fetchColumn();
            
            if ($item_count > 0) {
                setError("Bu kategoriye ait $item_count malzeme bulunmaktadır. Önce bu malzemeleri başka kategorilere taşıyın.");
            } else {
                $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->execute([$id]);
                setSuccess("Kategori başarıyla silindi.");
                $action = 'list';
            }
        }
    }
    
    // Silme işlemi (GET ile)
    if ($action === 'delete' && $category_id > 0) {
        // Bu kategoriye ait malzeme var mı kontrol et
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM items WHERE category_id = ?");
        $check_stmt->execute([$category_id]);
        $item_count = $check_stmt->fetchColumn();
        
        if ($item_count > 0) {
            setError("Bu kategoriye ait $item_count malzeme bulunmaktadır. Önce bu malzemeleri başka kategorilere taşıyın.");
        } else {
            $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$category_id]);
            setSuccess("Kategori başarıyla silindi.");
        }
        $action = 'list';
    }
    
    // Kategori listesi
    $categories_stmt = $db->query("
        SELECT 
            c.*,
            COUNT(i.id) as item_count
        FROM categories c
        LEFT JOIN items i ON c.id = i.category_id
        GROUP BY c.id
        ORDER BY c.name
    ");
    $categories = $categories_stmt->fetchAll();
    
    // Düzenleme için kategori bilgisi
    $edit_category = null;
    if ($action === 'edit' && $category_id > 0) {
        $stmt = $db->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$category_id]);
        $edit_category = $stmt->fetch();
        if (!$edit_category) {
            setError("Kategori bulunamadı.");
            $action = 'list';
        }
    }
    
} catch(Exception $e) {
    setError("Kategori işlemi sırasında hata oluştu: " . $e->getMessage());
    $categories = [];
}
?>

<div class="row">
    <!-- Kategori Ekleme/Düzenleme Formu -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-plus"></i> 
                    <?= $action === 'edit' ? 'Kategori Düzenle' : 'Yeni Kategori' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="id" value="<?= $edit_category['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">Kategori Adı <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?= htmlspecialchars($edit_category['name'] ?? '') ?>" 
                               required maxlength="100">
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="description" name="description" 
                                  rows="3" maxlength="255"><?= htmlspecialchars($edit_category['description'] ?? '') ?></textarea>
                        <div class="form-text">İsteğe bağlı. Kategorinin kısa açıklaması.</div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" name="<?= $action === 'edit' ? 'update_category' : 'add_category' ?>" 
                                class="btn btn-<?= $action === 'edit' ? 'warning' : 'success' ?>">
                            <i class="fas fa-<?= $action === 'edit' ? 'edit' : 'plus' ?>"></i>
                            <?= $action === 'edit' ? 'Güncelle' : 'Ekle' ?>
                        </button>
                        
                        <?php if ($action === 'edit'): ?>
                            <a href="categories.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> İptal
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Kategori Listesi -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="card-title mb-0">
                        <i class="fas fa-tags"></i> Kategori Listesi
                    </h5>
                    <small class="text-muted">Toplam <?= count($categories) ?> kategori</small>
                </div>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Malzeme Listesine Dön
                </a>
            </div>
            
            <div class="card-body">
                <?php if (empty($categories)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Henüz kategori bulunmamaktadır</h5>
                        <p class="text-muted">Sol taraftaki formu kullanarak ilk kategoriyi ekleyin.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Kategori Adı</th>
                                    <th>Açıklama</th>
                                    <th>Malzeme Sayısı</th>
                                    <th>Oluşturulma Tarihi</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td>
                                        <strong class="text-primary">
                                            <?= htmlspecialchars($category['name']) ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php if ($category['description']): ?>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($category['description']) ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $category['item_count'] > 0 ? 'success' : 'secondary' ?>">
                                            <?= $category['item_count'] ?> malzeme
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= date('d.m.Y H:i', strtotime($category['created_at'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="?action=edit&id=<?= $category['id'] ?>" 
                                               class="btn btn-sm btn-warning" title="Düzenle">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <?php if ($category['item_count'] == 0): ?>
                                                <a href="?action=delete&id=<?= $category['id'] ?>" 
                                                   class="btn btn-sm btn-danger" 
                                                   title="Sil"
                                                   onclick="return confirmDelete('<?= htmlspecialchars($category['name']) ?> kategorisini silmek istediğinizden emin misiniz?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-danger" 
                                                        title="Bu kategoriye ait malzemeler var, silinemez" 
                                                        disabled>
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
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

<!-- Kategori İstatistikleri -->
<?php if (!empty($categories)): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-chart-bar"></i> Kategori İstatistikleri
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($categories as $category): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-3">
                        <div class="stats-card">
                            <div class="stats-icon text-info">
                                <i class="fas fa-tag"></i>
                            </div>
                            <div class="stats-number text-info"><?= $category['item_count'] ?></div>
                            <div class="stats-label">
                                <?= htmlspecialchars($category['name']) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function confirmDelete(message) {
    return confirm(message);
}
</script>

<?php require_once '../../includes/footer.php'; ?>