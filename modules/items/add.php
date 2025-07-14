<?php
// modules/items/add.php

ob_start();

$page_title = "Yeni Malzeme Ekle";
$page_description = "Stok sistemine yeni malzeme ekleyin";

require_once '../../includes/header.php';

// Yetki kontrolü
requirePermission('manager');

$errors = [];
$form_data = [];

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
    
    // Malzeme kodu benzersizlik kontrolü
    if (empty($errors)) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT id FROM items WHERE item_code = ?");
            $stmt->execute([$form_data['item_code']]);
            if ($stmt->fetch()) {
                $errors[] = "Bu malzeme kodu zaten kullanılıyor";
            }
        } catch(Exception $e) {
            $errors[] = "Malzeme kodu kontrolü yapılamadı";
        }
    }
    
    // Seri numarası benzersizlik kontrolü (eğer girilmişse)
    if (!empty($form_data['serial_number']) && empty($errors)) {
        try {
            $stmt = $db->prepare("SELECT id FROM items WHERE serial_number = ?");
            $stmt->execute([$form_data['serial_number']]);
            if ($stmt->fetch()) {
                $errors[] = "Bu seri numarası zaten kullanılıyor";
            }
        } catch(Exception $e) {
            $errors[] = "Seri numarası kontrolü yapılamadı";
        }
    }
    
    // Kaydetme işlemi
    if (empty($errors)) {
        try {
            $sql = "INSERT INTO items (item_code, name, description, category_id, brand, model, serial_number, 
                    purchase_date, purchase_price, warranty_end_date, status, location, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
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
                $form_data['notes']
            ]);
            
            if ($result) {
                writeLog("New item added: " . $form_data['item_code'] . " - " . $form_data['name']);
                setSuccess("Malzeme başarıyla eklendi");
                header('Location: index.php');
                exit();
            } else {
                $errors[] = "Malzeme eklenirken hata oluştu";
            }
            
        } catch(Exception $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
            writeLog("Error adding item: " . $e->getMessage(), 'error');
        }
    }
}

// Kategori listesi
try {
    $db = getDB();
    $cat_stmt = $db->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $cat_stmt->fetchAll();
} catch(Exception $e) {
    $categories = [];
    setError("Kategori listesi yüklenemedi");
}
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-box"></i> Yeni Malzeme Bilgileri
                </h5>
            </div>
            
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h6><i class="fas fa-exclamation-triangle"></i> Aşağıdaki hataları düzeltin:</h6>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="itemForm">
                    <div class="row">
                        <!-- Sol Sütun -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="item_code" class="form-label">Malzeme Kodu <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="item_code" name="item_code" 
                                       value="<?= htmlspecialchars($form_data['item_code'] ?? '') ?>" 
                                       placeholder="örn: BLG001" required>
                                <div class="form-text">Benzersiz malzeme kodu girin</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="name" class="form-label">Malzeme Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?= htmlspecialchars($form_data['name'] ?? '') ?>" 
                                       placeholder="örn: Masaüstü Bilgisayar" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="description" name="description" rows="3"
                                          placeholder="Malzeme hakkında detaylı bilgi"><?= htmlspecialchars($form_data['description'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="category_id" class="form-label">Kategori</label>
                                <select class="form-select" id="category_id" name="category_id">
                                    <option value="">Kategori Seçin</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" 
                                                <?= (isset($form_data['category_id']) && $form_data['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="brand" class="form-label">Marka</label>
                                        <input type="text" class="form-control" id="brand" name="brand" 
                                               value="<?= htmlspecialchars($form_data['brand'] ?? '') ?>"
                                               placeholder="örn: Dell">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="model" class="form-label">Model</label>
                                        <input type="text" class="form-control" id="model" name="model" 
                                               value="<?= htmlspecialchars($form_data['model'] ?? '') ?>"
                                               placeholder="örn: OptiPlex 3070">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="serial_number" class="form-label">Seri Numarası</label>
                                <input type="text" class="form-control" id="serial_number" name="serial_number" 
                                       value="<?= htmlspecialchars($form_data['serial_number'] ?? '') ?>"
                                       placeholder="Benzersiz seri numarası">
                                <div class="form-text">Cihazın üzerindeki seri numarası</div>
                            </div>
                        </div>
                        
                        <!-- Sağ Sütun -->
                        <div class="col-md-6">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="purchase_date" class="form-label">Satın Alma Tarihi</label>
                                        <input type="date" class="form-control" id="purchase_date" name="purchase_date" 
                                               value="<?= htmlspecialchars($form_data['purchase_date'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="purchase_price" class="form-label">Satın Alma Fiyatı (₺)</label>
                                        <input type="number" step="0.01" class="form-control" id="purchase_price" name="purchase_price" 
                                               value="<?= htmlspecialchars($form_data['purchase_price'] ?? '') ?>"
                                               placeholder="0.00">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="warranty_end_date" class="form-label">Garanti Bitiş Tarihi</label>
                                <input type="date" class="form-control" id="warranty_end_date" name="warranty_end_date" 
                                       value="<?= htmlspecialchars($form_data['warranty_end_date'] ?? '') ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="status" class="form-label">Durum</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="available" <?= (!isset($form_data['status']) || $form_data['status'] === 'available') ? 'selected' : '' ?>>
                                        Müsait
                                    </option>
                                    <option value="maintenance" <?= (isset($form_data['status']) && $form_data['status'] === 'maintenance') ? 'selected' : '' ?>>
                                        Bakımda
                                    </option>
                                    <option value="broken" <?= (isset($form_data['status']) && $form_data['status'] === 'broken') ? 'selected' : '' ?>>
                                        Bozuk
                                    </option>
                                </select>
                                <div class="form-text">Yeni eklenen malzemeler genellikle "Müsait" durumunda olur</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="location" class="form-label">Konum</label>
                                <input type="text" class="form-control" id="location" name="location" 
                                       value="<?= htmlspecialchars($form_data['location'] ?? 'Depo') ?>"
                                       placeholder="örn: Depo, 1. Kat, IT Odası">
                            </div>
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label">Notlar</label>
                                <textarea class="form-control" id="notes" name="notes" rows="4"
                                          placeholder="Ek notlar, özel durumlar..."><?= htmlspecialchars($form_data['notes'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Geri Dön
                        </a>
                        
                        <div>
                            <button type="reset" class="btn btn-outline-secondary me-2">
                                <i class="fas fa-undo"></i> Temizle
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Kaydet
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
    const form = document.getElementById('itemForm');
    
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
    
    // Malzeme kodu otomatik oluşturma
    document.getElementById('item_code').addEventListener('focus', function() {
        if (!this.value) {
            const categorySelect = document.getElementById('category_id');
            const selectedOption = categorySelect.options[categorySelect.selectedIndex];
            let prefix = 'ITM';
            
            if (selectedOption.text) {
                // İlk 3 harfi al
                prefix = selectedOption.text.substring(0, 3).toUpperCase();
            }
            
            const randomNum = Math.floor(Math.random() * 900) + 100;
            this.value = prefix + randomNum;
        }
    });
    
    // Kategori değiştiğinde kod önekini güncelle
    document.getElementById('category_id').addEventListener('change', function() {
        const codeField = document.getElementById('item_code');
        if (codeField.value) {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                let prefix = selectedOption.text.substring(0, 3).toUpperCase();
                const currentCode = codeField.value;
                const numberPart = currentCode.replace(/[A-Z]/g, '');
                codeField.value = prefix + numberPart;
            }
        }
    });
    
    // Garanti bitiş tarihi otomatik hesaplama
    document.getElementById('purchase_date').addEventListener('change', function() {
        const purchaseDate = new Date(this.value);
        const warrantyField = document.getElementById('warranty_end_date');
        
        if (purchaseDate && !warrantyField.value) {
            // 2 yıl garanti ekle
            purchaseDate.setFullYear(purchaseDate.getFullYear() + 2);
            warrantyField.value = purchaseDate.toISOString().split('T')[0];
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>