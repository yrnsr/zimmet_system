<?php
// modules/items/edit.php

ob_start();

$page_title = "Malzeme Düzenle";

require_once '../../includes/header.php';

// Yetki kontrolü
requirePermission('manager');

// ID kontrolü
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    setError("Geçersiz malzeme ID");
    header('Location: index.php');
    exit();
}

$errors = [];
$form_data = [];

try {
    $db = getDB();
    
    // Mevcut malzeme bilgilerini getir
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    
    if (!$item) {
        setError("Malzeme bulunamadı");
        header('Location: index.php');
        exit();
    }
    
    // Form data başlangıç değerleri
    $form_data = $item;
    
} catch(Exception $e) {
    setError("Malzeme bilgileri yüklenirken hata oluştu: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

$page_description = $item['name'] . ' bilgilerini düzenleyin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Form verilerini al ve temizle
    $form_data = [
        'item_code' => clean($_POST['item_code']),
        'name' => clean($_POST['name']),
        'description' => clean($_POST['description']),
        'category_id' => !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
        'brand' => clean($_POST['brand']),
        'model' => clean($_POST['model']),
        'serial_number' => clean($_POST['serial_number']),
        'purchase_date' => clean($_POST['purchase_date']),
        'purchase_price' => !empty($_POST['purchase_price']) ? (float)str_replace(',', '.', $_POST['purchase_price']) : null,
        'warranty_end_date' => clean($_POST['warranty_end_date']),
        'status' => clean($_POST['status']),
        'location' => clean($_POST['location']),
        'notes' => clean($_POST['notes'])
    ];
    
    // Validasyon
    if (empty($form_data['item_code'])) {
        $errors[] = "Malzeme kodu boş olamaz";
    }
    
    if (empty($form_data['name'])) {
        $errors[] = "Malzeme adı boş olamaz";
    }
    
    if (!empty($form_data['purchase_date']) && !strtotime($form_data['purchase_date'])) {
        $errors[] = "Geçerli bir satın alma tarihi girin";
    }
    
    if (!empty($form_data['warranty_end_date']) && !strtotime($form_data['warranty_end_date'])) {
        $errors[] = "Geçerli bir garanti bitiş tarihi girin";
    }
    
    if (!empty($form_data['purchase_price']) && $form_data['purchase_price'] < 0) {
        $errors[] = "Fiyat sıfırdan küçük olamaz";
    }
    
    // Malzeme kodu benzersizlik kontrolü (kendisi hariç)
    if (empty($errors)) {
        try {
            $stmt = $db->prepare("SELECT id FROM items WHERE item_code = ? AND id != ?");
            $stmt->execute([$form_data['item_code'], $id]);
            if ($stmt->fetch()) {
                $errors[] = "Bu malzeme kodu zaten kullanılıyor";
            }
        } catch(Exception $e) {
            $errors[] = "Malzeme kodu kontrolü yapılamadı";
        }
    }
    
    // Seri numarası benzersizlik kontrolü (kendisi hariç)
    if (!empty($form_data['serial_number']) && empty($errors)) {
        try {
            $stmt = $db->prepare("SELECT id FROM items WHERE serial_number = ? AND id != ?");
            $stmt->execute([$form_data['serial_number'], $id]);
            if ($stmt->fetch()) {
                $errors[] = "Bu seri numarası zaten kullanılıyor";
            }
        } catch(Exception $e) {
            $errors[] = "Seri numarası kontrolü yapılamadı";
        }
    }
    
    // Durum değişikliği kontrolü
    if ($item['status'] === 'assigned' && $form_data['status'] !== 'assigned') {
        // Aktif zimmet kontrolü
        try {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignments WHERE item_id = ? AND status = 'active'");
            $stmt->execute([$id]);
            $active_assignments = $stmt->fetch()['count'];
            
            if ($active_assignments > 0) {
                $errors[] = "Bu malzemede aktif zimmet bulunduğu için durumu değiştirilemez. Önce zimmeti iade alınız.";
            }
        } catch(Exception $e) {
            $errors[] = "Zimmet kontrolü yapılamadı";
        }
    }
    
    // Güncelleme işlemi
    if (empty($errors)) {
        try {
            $sql = "UPDATE items SET 
                    item_code = ?, name = ?, description = ?, category_id = ?, 
                    brand = ?, model = ?, serial_number = ?, purchase_date = ?, 
                    purchase_price = ?, warranty_end_date = ?, status = ?, 
                    location = ?, notes = ?, updated_at = NOW() 
                    WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $form_data['item_code'],
                $form_data['name'],
                $form_data['description'],
                $form_data['category_id'],
                $form_data['brand'],
                $form_data['model'],
                $form_data['serial_number'],
                $form_data['purchase_date'],
                $form_data['purchase_price'],
                $form_data['warranty_end_date'],
                $form_data['status'],
                $form_data['location'],
                $form_data['notes'],
                $id
            ]);
            
            if ($result) {
                writeLog("Item updated: " . $form_data['item_code'] . " - " . $form_data['name']);
                setSuccess("Malzeme bilgileri başarıyla güncellendi");
                header('Location: view.php?id=' . $id);
                exit();
            } else {
                $errors[] = "Malzeme güncellenirken hata oluştu";
            }
            
        } catch(Exception $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
            writeLog("Error updating item: " . $e->getMessage(), 'error');
        }
    }
}

// Kategori listesi
try {
    $cat_stmt = $db->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $cat_stmt->fetchAll();
} catch(Exception $e) {
    $categories = [];
    setError("Kategori listesi yüklenemedi");
}

// Aktif zimmet kontrolü
$has_active_assignment = false;
try {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignments WHERE item_id = ? AND status = 'active'");
    $stmt->execute([$id]);
    $has_active_assignment = $stmt->fetch()['count'] > 0;
} catch(Exception $e) {
    // Hata durumunda devam et
}

// Helper function to safely display values
function safeDisplay($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-edit"></i> Malzeme Bilgilerini Düzenle
                </h5>
            </div>
            
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h6><i class="fas fa-exclamation-triangle"></i> Aşağıdaki hataları düzeltin:</h6>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= safeDisplay($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if ($has_active_assignment): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Dikkat:</strong> Bu malzemede aktif zimmet bulunmaktadır. 
                        Durum değişikliklerinde dikkatli olunuz.
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="itemEditForm">
                    <div class="row">
                        <!-- Sol Sütun -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="item_code" class="form-label">Malzeme Kodu <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="item_code" name="item_code" 
                                       value="<?= safeDisplay($form_data['item_code']) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="name" class="form-label">Malzeme Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?= safeDisplay($form_data['name']) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?= safeDisplay($form_data['description']) ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="category_id" class="form-label">Kategori</label>
                                <select class="form-select" id="category_id" name="category_id">
                                    <option value="">Kategori Seçin</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" 
                                                <?= ($form_data['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                                            <?= safeDisplay($cat['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="brand" class="form-label">Marka</label>
                                        <input type="text" class="form-control" id="brand" name="brand" 
                                               value="<?= safeDisplay($form_data['brand']) ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="model" class="form-label">Model</label>
                                        <input type="text" class="form-control" id="model" name="model" 
                                               value="<?= safeDisplay($form_data['model']) ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="serial_number" class="form-label">Seri Numarası</label>
                                <input type="text" class="form-control" id="serial_number" name="serial_number" 
                                       value="<?= safeDisplay($form_data['serial_number']) ?>">
                                <div class="form-text">Benzersiz seri numarası</div>
                            </div>
                        </div>
                        
                        <!-- Sağ Sütun -->
                        <div class="col-md-6">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="purchase_date" class="form-label">Satın Alma Tarihi</label>
                                        <input type="date" class="form-control" id="purchase_date" name="purchase_date" 
                                               value="<?= safeDisplay($form_data['purchase_date']) ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="purchase_price" class="form-label">Satın Alma Fiyatı (₺)</label>
                                        <input type="number" step="0.01" class="form-control" id="purchase_price" name="purchase_price" 
                                               value="<?= safeDisplay($form_data['purchase_price']) ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="warranty_end_date" class="form-label">Garanti Bitiş Tarihi</label>
                                <input type="date" class="form-control" id="warranty_end_date" name="warranty_end_date" 
                                       value="<?= safeDisplay($form_data['warranty_end_date']) ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="status" class="form-label">Durum</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="available" <?= $form_data['status'] === 'available' ? 'selected' : '' ?>>
                                        Müsait
                                    </option>
                                    <option value="assigned" <?= $form_data['status'] === 'assigned' ? 'selected' : '' ?>>
                                        Zimmetli
                                    </option>
                                    <option value="maintenance" <?= $form_data['status'] === 'maintenance' ? 'selected' : '' ?>>
                                        Bakımda
                                    </option>
                                    <option value="broken" <?= $form_data['status'] === 'broken' ? 'selected' : '' ?>>
                                        Bozuk
                                    </option>
                                    <option value="lost" <?= $form_data['status'] === 'lost' ? 'selected' : '' ?>>
                                        Kayıp
                                    </option>
                                </select>
                                <?php if ($has_active_assignment): ?>
                                    <div class="form-text text-warning">
                                        <i class="fas fa-warning"></i> Bu malzemede aktif zimmet bulunmaktadır
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-3">
                                <label for="location" class="form-label">Konum</label>
                                <input type="text" class="form-control" id="location" name="location" 
                                       value="<?= safeDisplay($form_data['location']) ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label">Notlar</label>
                                <textarea class="form-control" id="notes" name="notes" rows="4"><?= safeDisplay($form_data['notes']) ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between">
                        <div>
                            <a href="view.php?id=<?= $id ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Geri Dön
                            </a>
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-list"></i> Stok Listesi
                            </a>
                        </div>
                        
                        <div>
                            <button type="reset" class="btn btn-outline-secondary me-2">
                                <i class="fas fa-undo"></i> Sıfırla
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Güncelle
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validasyonu
    const form = document.getElementById('itemEditForm');
    
    form.addEventListener('submit', function(e) {
        let isValid = true;
        
        // Gerekli alanları kontrol et
        const requiredFields = ['item_code', 'name'];
        requiredFields.forEach(function(fieldName) {
            const field = document.getElementById(fieldName);
            if (!field.value.trim()) {
                field.classList.add('is-invalid');
                isValid = false;
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        // Fiyat kontrolü
        const priceField = document.getElementById('purchase_price');
        if (priceField.value && parseFloat(priceField.value) < 0) {
            priceField.classList.add('is-invalid');
            isValid = false;
        } else {
            priceField.classList.remove('is-invalid');
        }
        
        if (!isValid) {
            e.preventDefault();
            alert('Lütfen gerekli alanları doğru şekilde doldurun.');
        }
    });
    
    // Durum değişikliği uyarısı
    const statusSelect = document.getElementById('status');
    const originalStatus = '<?= $item['status'] ?>';
    const hasActiveAssignment = <?= $has_active_assignment ? 'true' : 'false' ?>;
    
    statusSelect.addEventListener('change', function() {
        if (originalStatus === 'assigned' && this.value !== 'assigned' && hasActiveAssignment) {
            if (!confirm('Bu malzemede aktif zimmet bulunmaktadır. Durum değiştirmek istediğinizden emin misiniz?')) {
                this.value = originalStatus;
            }
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>