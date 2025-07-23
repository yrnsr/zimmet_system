<?php
// modules/personnel/bulk_import.php

$page_title = "Toplu Personel Ekleme";
$page_description = "Excel/CSV dosyası ile toplu personel ekleme sistemi";

require_once '../../includes/header.php';
require_once '../../includes/functions.php';  // Yol projenin yapısına göre değişebilir

// Sadece yetkili kullanıcılar erişebilir
if (!hasPermission('manager')) {
    header("Location: ../../index.php");
    exit;
}

$errors = [];
$warnings = [];
$success_count = 0;
$imported_personnel = [];
$preview_data = [];
$show_preview = false;
$validation_results = [];

// Departmanları çek
try {
    $db = getDB();
    $dept_stmt = $db->query("SELECT id, name FROM departments ORDER BY name");
    $departments = $dept_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $departments = [];
    $errors[] = "Departmanlar yüklenemedi: " . $e->getMessage();
}

// Form işleme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Önizleme işlemi
    if (isset($_POST['preview']) && isset($_FILES['personnel_file'])) {
        $file = $_FILES['personnel_file'];
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (in_array($file_extension, ['csv', 'xlsx', 'xls'])) {
                try {
                    // Dosya boyutu kontrolü (10MB)
                    if ($file['size'] > 10 * 1024 * 1024) {
                        throw new Exception("Dosya boyutu 10MB'dan büyük olamaz.");
                    }
                    
                    if ($file_extension === 'csv') {
                        $preview_data = parseCSVFile($file['tmp_name']);
                    } else {
                        $preview_data = parseExcelFile($file['tmp_name']);
                    }
                    
                    if (!empty($preview_data)) {
                        // Veri doğrulama
                        $validation_results = validateBulkData($preview_data, $departments);
                        $show_preview = true;
                        
                        // Geçici dosyayı kaydet
                        $temp_dir = '../../temp/';
                        if (!is_dir($temp_dir)) {
                            mkdir($temp_dir, 0755, true);
                        }
                        $temp_file = $temp_dir . uniqid() . '_' . basename($file['name']);
                        move_uploaded_file($file['tmp_name'], $temp_file);
                        $_SESSION['temp_file'] = $temp_file;
                        $_SESSION['file_extension'] = $file_extension;
                    } else {
                        $errors[] = "Dosyada geçerli veri bulunamadı.";
                    }
                } catch (Exception $e) {
                    $errors[] = "Dosya işlenirken hata: " . $e->getMessage();
                }
            } else {
                $errors[] = "Sadece CSV, XLS ve XLSX dosyaları kabul edilir.";
            }
        } else {
            $errors[] = getUploadError($file['error']);
        }
    }
    
    // Kaydetme işlemi
    if (isset($_POST['import']) && isset($_SESSION['temp_file'])) {
        try {
            $file_extension = $_SESSION['file_extension'];
            
            if ($file_extension === 'csv') {
                $data = parseCSVFile($_SESSION['temp_file']);
            } else {
                $data = parseExcelFile($_SESSION['temp_file']);
            }
            
            $db = getDB();
            $db->beginTransaction();
            
            $success_count = 0;
            $imported_personnel = [];
            $skipped_count = 0;
            
            foreach ($data as $index => $row) {
                try {
                    // Veri doğrulama
                    $validation_errors = validatePersonnelRow($row, $departments);
                    
                    if (!empty($validation_errors)) {
                        $errors[] = "Satır " . ($index + 2) . ": " . implode(", ", $validation_errors);
                        $skipped_count++;
                        continue;
                    }
                    
                    // Sicil numarası kontrolü
                    $check_stmt = $db->prepare("SELECT id, name, surname FROM personnel WHERE sicil_no = ?");
                    $check_stmt->execute([$row['sicil_no']]);
                    $existing = $check_stmt->fetch();
                    
                    if ($existing) {
                        if (isset($_POST['update_existing'])) {
                            // Mevcut personeli güncelle
                            updatePersonnel($db, $existing['id'], $row, $departments);
                            $warnings[] = "Sicil No: {$row['sicil_no']} - {$existing['name']} {$existing['surname']} güncellendi";
                        } else {
                            $errors[] = "Satır " . ($index + 2) . ": Sicil numarası zaten mevcut (" . $row['sicil_no'] . ") - Güncelleme işaretlenmemiş";
                            $skipped_count++;
                            continue;
                        }
                    } else {
                        // Yeni personel ekle
                        insertPersonnel($db, $row, $departments);
                    }
                    
                    $success_count++;
                    $imported_personnel[] = $row['sicil_no'] . ' - ' . $row['name'] . ' ' . $row['surname'];
                    
                } catch (Exception $e) {
                    $errors[] = "Satır " . ($index + 2) . ": " . $e->getMessage();
                    $skipped_count++;
                }
            }
            
            if ($success_count > 0) {
                $db->commit();
                $message = "$success_count personel başarıyla eklendi/güncellendi.";
                if ($skipped_count > 0) {
                    $message .= " $skipped_count satır atlandı.";
                }
                setSuccess($message);
                
                // Log kaydı
                logActivity('personnel_bulk_import', "Toplu personel ekleme: $success_count personel eklendi, $skipped_count atlandı");
                
            } else {
                $db->rollBack();
                $errors[] = "Hiç personel eklenemedi.";
            }
            
            // Geçici dosyayı sil
            if (file_exists($_SESSION['temp_file'])) {
                unlink($_SESSION['temp_file']);
            }
            unset($_SESSION['temp_file'], $_SESSION['file_extension']);
            
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = "Genel hata: " . $e->getMessage();
        }
    }
}



// Excel parsing fonksiyonu (PHPSpreadsheet kullanımı)
function parseExcelFile($file_path) {
    // PHPSpreadsheet kurulu değilse basit XML parsing
    try {
        if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            
            $data = [];
            $headers = [];
            $row_index = 0;
            
            foreach ($rows as $row) {
                if ($row_index === 0) {
                    foreach ($row as $cell) {
                        $headers[] = mapHeaderToField($cell);
                    }
                } else {
                    $personnel_data = [];
                    $cell_index = 0;
                    foreach ($row as $cell) {
                        $header = $headers[$cell_index] ?? null;
                        if ($header) {
                            $personnel_data[$header] = trim($cell);
                        }
                        $cell_index++;
                    }
                    
                    if (!empty($personnel_data['sicil_no']) || !empty($personnel_data['name'])) {
                        $data[] = $personnel_data;
                    }
                }
                $row_index++;
            }
            
            return $data;
        } else {
            throw new Exception("Excel dosyaları için PHPSpreadsheet kütüphanesi gerekli. Lütfen CSV formatı kullanın.");
        }
    } catch (Exception $e) {
        throw new Exception("Excel dosyası okunamadı: " . $e->getMessage());
    }
}

// Geliştirilmiş CSV parsing fonksiyonu
function parseCSVFile($file_path) {
    $data = [];
    
    // Dosya var mı kontrol et
    if (!file_exists($file_path)) {
        throw new Exception("Dosya bulunamadı: " . $file_path);
    }
    
    // Dosya boyutu kontrol et
    if (filesize($file_path) == 0) {
        throw new Exception("Dosya boş.");
    }
    
    // Dosyanın encoding'ini tespit et
    $content = file_get_contents($file_path);
    if ($content === false) {
        throw new Exception("Dosya okunamadı.");
    }

        // **Buraya ekle bu kodu**
    $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII'], true);

    if ($encoding && $encoding !== 'UTF-8') {
        // Sadece içeriği UTF-8'e dönüştür
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }
    
    // Debug için dosya içeriğinin ilk 500 karakterini logla
    error_log("Dosya içeriği (ilk 500 karakter): " . substr($content, 0, 500));
    
    
    
    // Farklı ayırıcıları dene
    $delimiters = [',', ';', '\t', '|'];
    $best_delimiter = ',';
    $max_columns = 0;
    
    foreach ($delimiters as $delimiter) {
        $test_line = explode("\n", $content)[0] ?? '';
        $columns = str_getcsv($test_line, $delimiter);
        if (count($columns) > $max_columns) {
            $max_columns = count($columns);
            $best_delimiter = $delimiter;
        }
    }
    
    error_log("Seçilen ayırıcı: " . $best_delimiter . " - Sütun sayısı: " . $max_columns);
    
    // CSV'yi satır satır parse et
    $lines = explode("\n", $content);
    $headers = [];
    $row_index = 0;
    
    foreach ($lines as $line_num => $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $row = str_getcsv($line, $best_delimiter);
        
        if ($row_index === 0) {
            // Header satırı
            $headers = array_map('trim', $row);
            error_log("Bulunan başlıklar: " . implode(', ', $headers));
        } else {
            // Veri satırı
            $personnel_data = [];
            foreach ($headers as $index => $header) {
                $key = mapHeaderToField($header);
                $value = isset($row[$index]) ? trim($row[$index]) : '';
                $personnel_data[$key] = $value;
            }
            
            // En az sicil_no VEYA name dolu olan satırları al
            if (!empty($personnel_data['sicil_no']) || !empty($personnel_data['name'])) {
                $data[] = $personnel_data;
                error_log("Satır " . ($row_index + 1) . " eklendi: " . json_encode($personnel_data));
            }
        }
        $row_index++;
    }
    
    error_log("Toplam işlenen satır: " . count($data));
    
    if (empty($data)) {
        throw new Exception("Dosyada geçerli veri bulunamadı. Kontrol edilecekler: 1) İlk satır başlık satırı olmalı 2) En az 'Sicil No' veya 'Ad' sütunu dolu olmalı");
    }
    
    return $data;
}

// Geliştirilmiş header mapping
function mapHeaderToField($header) {
    $header = strtolower(trim($header));
    
    // Türkçe karakterleri düzelt
    $header = str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç'], ['i', 'g', 'u', 's', 'o', 'c'], $header);
    
    // Özel karakterleri temizle
    $header = preg_replace('/[^a-z0-9\s]/', '', $header);
    $header = preg_replace('/\s+/', ' ', $header);
    $header = trim($header);
    
    $mapping = [
        // Sicil No variations
        'sicil no' => 'sicil_no',
        'sicil_no' => 'sicil_no',
        'sicilno' => 'sicil_no',
        'sicil' => 'sicil_no',
        'employee_id' => 'sicil_no',
        'id' => 'sicil_no',
        'personel no' => 'sicil_no',
        'personel_no' => 'sicil_no',
        'no' => 'sicil_no',
        
        // Name variations
        'ad' => 'name',
        'name' => 'name',
        'isim' => 'name',
        'adi' => 'name',
        'first_name' => 'name',
        'firstname' => 'name',
        
        // Surname variations
        'soyad' => 'surname',
        'surname' => 'surname',
        'soyadi' => 'surname',
        'last_name' => 'surname',
        'lastname' => 'surname',
        
        // Email variations
        'email' => 'email',
        'e-mail' => 'email',
        'eposta' => 'email',
        'e_mail' => 'email',
        'mail' => 'email',
        'e posta' => 'email',
        
        // Phone variations
        'telefon' => 'phone',
        'phone' => 'phone',
        'tel' => 'phone',
        'mobile' => 'phone',
        'gsm' => 'phone',
        'cep' => 'phone',
        'cep telefonu' => 'phone',
        
        // Department variations
        'departman' => 'department',
        'department' => 'department',
        'dept' => 'department',
        'bolum' => 'department',
        'birim' => 'department',
        'bolumu' => 'department',
        
        // Position variations
        'pozisyon' => 'position',
        'position' => 'position',
        'gorev' => 'position',
        'unvan' => 'position',
        'title' => 'position',
        'job_title' => 'position',
        'meslek' => 'position',
        'gorevi' => 'position',
        
        // Hire date variations
        'ise baslama' => 'hire_date',
        'hire_date' => 'hire_date',
        'baslama tarihi' => 'hire_date',
        'start_date' => 'hire_date',
        'giris tarihi' => 'hire_date',
        'ise baslama tarihi' => 'hire_date',
        'baslama' => 'hire_date',

        // Address variations
        'adres' => 'address',
        'address' => 'address',
        'ev adresi' => 'address',
        
        // Notes variations
        'not' => 'notes',
        'notes' => 'notes',
        'notlar' => 'notes',
        'aciklama' => 'notes',
        'description' => 'notes',
        'aciklamalar' => 'notes'
    ];
    
    $mapped = $mapping[$header] ?? $header;
    error_log("Header mapping: '$header' -> '$mapped'");
    
    return $mapped;
}

// Departman ID bulma - geliştirilmiş
function findDepartmentId($department_name, $departments) {
    if (empty($department_name)) return null;
    
    $department_name = trim($department_name);
    
    // Tam eşleşme kontrolü
    foreach ($departments as $id => $name) {
        if (strcasecmp($name, $department_name) === 0) {
            return $id;
        }
    }
    
    // Kısmi eşleşme kontrolü
    foreach ($departments as $id => $name) {
        if (stripos($name, $department_name) !== false || stripos($department_name, $name) !== false) {
            return $id;
        }
    }
    
    return null;
}

// Toplu veri doğrulama
function validateBulkData($data, $departments) {
    $results = [
        'valid_count' => 0,
        'invalid_count' => 0,
        'warnings' => [],
        'errors' => []
    ];
    
    $sicil_numbers = [];
    
    foreach ($data as $index => $row) {
        $row_errors = validatePersonnelRow($row, $departments);
        
        if (!empty($row_errors)) {
            $results['invalid_count']++;
            $results['errors'][] = "Satır " . ($index + 2) . ": " . implode(", ", $row_errors);
        } else {
            $results['valid_count']++;
        }
        
        // Sicil numarası tekrar kontrolü
        if (!empty($row['sicil_no'])) {
            if (in_array($row['sicil_no'], $sicil_numbers)) {
                $results['warnings'][] = "Dosyada tekrarlanan sicil numarası: " . $row['sicil_no'];
            } else {
                $sicil_numbers[] = $row['sicil_no'];
            }
        }
    }
    
    return $results;
}

// Tek satır veri doğrulama
function validatePersonnelRow($data, $departments) {
    $errors = [];
    
    // Zorunlu alanlar
    if (empty($data['sicil_no'])) {
        $errors[] = "Sicil numarası zorunlu";
    } elseif (!preg_match('/^[A-Za-z0-9]+$/', $data['sicil_no'])) {
        $errors[] = "Sicil numarası sadece harf ve rakam içerebilir";
    }
    
    if (empty($data['name'])) {
        $errors[] = "Ad zorunlu";
    }
    
    if (empty($data['surname'])) {
        $errors[] = "Soyad zorunlu";
    }
    
    // Email doğrulama
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Geçersiz email formatı";
    }
    
    // Telefon doğrulama
    if (!empty($data['phone'])) {
        $phone = preg_replace('/[^0-9]/', '', $data['phone']);
        if (strlen($phone) < 10 || strlen($phone) > 11) {
            $errors[] = "Geçersiz telefon numarası";
        }
    }
    
    // Tarih doğrulama
    if (!empty($data['hire_date'])) {
        if (!validateDate($data['hire_date'])) {
            $errors[] = "Geçersiz işe başlama tarihi formatı (YYYY-MM-DD, DD.MM.YYYY veya DD/MM/YYYY bekleniyor)";
        }
    }
    
    return $errors;
}

// Tarih doğrulama
function validateDate($date_string) {
    $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y'];
    
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $date_string);
        if ($dt && $dt->format($format) === $date_string) {
            return true;
        }
    }
    
    return false;
}

// Tarih formatlama
function formatDate($date_string) {
    if (empty($date_string)) return null;
    
    $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y'];
    
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $date_string);
        if ($dt && $dt->format($format) === $date_string) {
            return $dt->format('Y-m-d');
        }
    }
    
    return null;
}

// Personel ekleme
function insertPersonnel($db, $data, $departments) {
    $sql = "INSERT INTO personnel (
        sicil_no, name, surname, email, phone, 
        department_id, position, hire_date, 
        address, is_active, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())";

    $dept_id = findDepartmentId($data['department'] ?? '', $departments);
    $hire_date = formatDate($data['hire_date'] ?? '');

    $params = [
        $data['sicil_no'],
        $data['name'],
        $data['surname'],
        $data['email'] ?? '',
        $data['phone'] ?? '',
        $dept_id,
        $data['position'] ?? '',
        $hire_date,
        $data['address'] ?? '',
    ];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}


function updatePersonnel($db, $personnel_id, $data, $departments) {
    $sql = "UPDATE personnel SET 
        name = ?, surname = ?, email = ?, phone = ?, 
        department_id = ?, position = ?, hire_date = ?, 
        address = ?, updated_at = NOW()
        WHERE id = ?";

    $dept_id = findDepartmentId($data['department'] ?? '', $departments);
    $hire_date = formatDate($data['hire_date'] ?? '');

    $params = [
        $data['name'] ?? '',
        $data['surname'] ?? '',
        $data['email'] ?? null,
        $data['phone'] ?? null,
        $dept_id,
        $data['position'] ?? null,
        $hire_date,
        $data['address'] ?? null,
        $personnel_id
    ];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}


// Upload hatası mesajları
function getUploadError($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "Dosya boyutu çok büyük.";
        case UPLOAD_ERR_PARTIAL:
            return "Dosya kısmen yüklendi.";
        case UPLOAD_ERR_NO_FILE:
            return "Dosya seçilmedi.";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Geçici dizin bulunamadı.";
        case UPLOAD_ERR_CANT_WRITE:
            return "Dosya yazılamadı.";
        case UPLOAD_ERR_EXTENSION:
            return "Dosya uzantısı engellendi.";
        default:
            return "Bilinmeyen yükleme hatası.";
    }
}

// Örnek CSV oluşturma fonksiyonu
function generateSampleCSV() {
    $csv_content = "Sicil No,Ad,Soyad,Email,Telefon,Departman,Pozisyon,İşe Başlama,Adres,Notlar\n";
    $csv_content .= "001,Ahmet,Yılmaz,ahmet@sirket.com,05551234567,Bilgi İşlem,Yazılım Geliştirici,2024-01-15,İstanbul,Kıdemli personel\n";
    $csv_content .= "002,Ayşe,Demir,ayse@sirket.com,05551234568,İnsan Kaynakları,İK Uzmanı,2024-02-01,Ankara,\n";
    $csv_content .= "003,Mehmet,Kaya,mehmet@sirket.com,05551234569,Muhasebe,Mali Müşavir,2024-01-20,İzmir,CPA sertifikalı\n";
    
    return $csv_content;
}
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4><i class="fas fa-users-cog"></i> <?= $page_title ?></h4>
                    <p class="text-muted"><?= $page_description ?></p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Geri Dön
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Hata ve Başarı Mesajları -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <h6><i class="fas fa-exclamation-triangle"></i> Hatalar:</h6>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($warnings)): ?>
        <div class="alert alert-warning">
            <h6><i class="fas fa-exclamation-circle"></i> Uyarılar:</h6>
            <ul class="mb-0">
                <?php foreach ($warnings as $warning): ?>
                    <li><?= htmlspecialchars($warning) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success_count > 0): ?>
        <div class="alert alert-success">
            <h6><i class="fas fa-check-circle"></i> Başarıyla Tamamlandı:</h6>
            <p><strong><?= $success_count ?></strong> personel sisteme eklendi/güncellendi:</p>
            <ul class="mb-0">
                <?php foreach ($imported_personnel as $person): ?>
                    <li><?= htmlspecialchars($person) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <!-- Dosya Yükleme Formu -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-file-upload"></i> Dosya Yükleme</h5>
                </div>
                <div class="card-body">
                    <?php if (!$show_preview): ?>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="personnel_file" class="form-label">Personel Dosyası</label>
                                <input type="file" class="form-control" id="personnel_file" 
                                       name="personnel_file" accept=".csv,.xlsx,.xls" required>
                                <div class="form-text">
                                    Desteklenen formatlar: CSV, XLS, XLSX (Maksimum 10MB)
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" name="preview" class="btn btn-info">
                                    <i class="fas fa-eye"></i> Önizleme Yap ve Doğrula
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <!-- Doğrulama Sonuçları -->
                        <?php if (!empty($validation_results)): ?>
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <div class="card bg-success text-white">
                                        <div class="card-body text-center">
                                            <h3><?= $validation_results['valid_count'] ?></h3>
                                            <small>Geçerli Kayıt</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-danger text-white">
                                        <div class="card-body text-center">
                                            <h3><?= $validation_results['invalid_count'] ?></h3>
                                            <small>Hatalı Kayıt</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-info text-white">
                                        <div class="card-body text-center">
                                            <h3><?= count($preview_data) ?></h3>
                                            <small>Toplam Kayıt</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Önizleme Tablosu -->
                        <div class="mb-3">
                            <h6><i class="fas fa-table"></i> Veri Önizlemesi</h6>
                            <p class="text-muted">İlk 50 kayıt gösteriliyor:</p>
                        </div>
                        
                        <div class="table-responsive mb-3" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>#</th>
                                        <th>Sicil No</th>
                                        <th>Ad</th>
                                        <th>Soyad</th>
                                        <th>Email</th>
                                        <th>Telefon</th>
                                        <th>Departman</th>
                                        <th>Pozisyon</th>
                                        <th>Durum</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($preview_data, 0, 50) as $index => $row): ?>
                                        <?php 
                                        $row_errors = validatePersonnelRow($row, $departments);
                                        $has_errors = !empty($row_errors);
                                        ?>
                                        <tr class="<?= $has_errors ? 'table-danger' : 'table-success' ?>">
                                            <td><?= $index + 1 ?></td>
                                            <td><?= htmlspecialchars($row['sicil_no'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['name'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['surname'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['email'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['department'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['position'] ?? '') ?></td>
                                            <td>
                                                <?php if ($has_errors): ?>
                                                    <span class="badge bg-danger" title="<?= implode(', ', $row_errors) ?>">
                                                        <i class="fas fa-times"></i> Hatalı
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check"></i> Geçerli
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <form method="POST">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="update_existing" id="update_existing">
                                <label class="form-check-label" for="update_existing">
                                    <i class="fas fa-sync-alt"></i> Mevcut sicil numaralarını güncelle
                                </label>
                                <div class="form-text">
                                    İşaretlenirse, aynı sicil numarasına sahip personel bilgileri güncellenir.
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" name="import" class="btn btn-success" 
                                        <?= $validation_results['valid_count'] == 0 ? 'disabled' : '' ?>>
                                    <i class="fas fa-download"></i> 
                                    Personelleri İçe Aktar (<?= $validation_results['valid_count'] ?> geçerli kayıt)
                                </button>
                                
                                <a href="bulk_import.php" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Yeni Dosya Seç
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Yardım ve Bilgi -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle"></i> Kullanım Kılavuzu</h5>
                </div>
                <div class="card-body">
                    <h6><i class="fas fa-file-csv"></i> Dosya Formatı</h6>
                    <ul class="mb-3">
                        <li>CSV, XLS veya XLSX formatında</li>
                        <li>Maksimum dosya boyutu: 10MB</li>
                        <li>İlk satır başlık satırı olmalı</li>
                        <li>UTF-8 encoding önerilir</li>
                    </ul>

                    <h6><i class="fas fa-columns"></i> Gerekli Sütunlar</h6>
                    <ul class="mb-3">
                        <li><strong>Sicil No:</strong> Zorunlu, benzersiz</li>
                        <li><strong>Ad:</strong> Zorunlu</li>
                        <li><strong>Soyad:</strong> Zorunlu</li>
                        <li><strong>Email:</strong> Opsiyonel, geçerli format</li>
                        <li><strong>Telefon:</strong> Opsiyonel</li>
                        <li><strong>Departman:</strong> Opsiyonel</li>
                        <li><strong>Pozisyon:</strong> Opsiyonel</li>
                        <li><strong>İşe Başlama:</strong> YYYY-MM-DD formatında</li>
                    </ul>

                    <h6><i class="fas fa-lightbulb"></i> İpuçları</h6>
                    <ul class="mb-3">
                        <li>Türkçe karakter kullanabilirsiniz</li>
                        <li>Sütun başlıkları esnek (örn: "Ad", "Name", "İsim")</li>
                        <li>Boş satırlar otomatik atlanır</li>
                        <li>Önizleme yaparak hataları kontrol edin</li>
                    </ul>

                    <div class="d-grid">
                        <a href="?download_sample=1" class="btn btn-outline-primary">
                            <i class="fas fa-download"></i> Örnek CSV İndir
                        </a>
                    </div>
                </div>
            </div>

    <!-- Detaylı Hata Raporu -->
    <?php if ($show_preview && !empty($validation_results['errors'])): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-exclamation-triangle text-danger"></i> Detaylı Hata Raporu</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle"></i>
                            <strong><?= $validation_results['invalid_count'] ?></strong> satırda hata bulundu. 
                            Bu kayıtlar içe aktarım sırasında atlanacak.
                        </div>
                        
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Hata</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($validation_results['errors'] as $error): ?>
                                        <tr>
                                            <td class="text-danger">
                                                <i class="fas fa-times-circle"></i>
                                                <?= htmlspecialchars($error) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Uyarılar -->
    <?php if ($show_preview && !empty($validation_results['warnings'])): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-exclamation-circle text-warning"></i> Uyarılar</h5>
                    </div>
                    <div class="card-body">
                        <ul class="mb-0">
                            <?php foreach ($validation_results['warnings'] as $warning): ?>
                                <li class="text-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <?= htmlspecialchars($warning) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Loading Modal -->
<div class="modal fade" id="loadingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Yükleniyor...</span>
                </div>
                <h5>Dosya İşleniyor</h5>
                <p class="text-muted mb-0">Lütfen bekleyiniz...</p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form submit olduğunda loading göster
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'));
            loadingModal.show();
        });
    });

    // Dosya seçildiğinde boyut kontrolü
    const fileInput = document.getElementById('personnel_file');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                const maxSize = 10 * 1024 * 1024; // 10MB
                if (file.size > maxSize) {
                    alert('Dosya boyutu 10MB\'dan büyük olamaz!');
                    this.value = '';
                    return false;
                }
                
                // Dosya türü kontrolü
                const allowedTypes = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                if (!allowedTypes.includes(file.type) && !file.name.match(/\.(csv|xls|xlsx)$/i)) {
                    alert('Sadece CSV, XLS ve XLSX dosyaları kabul edilir!');
                    this.value = '';
                    return false;
                }
            }
        });
    }

    // Tooltip'leri etkinleştir
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php


require_once '../../includes/footer.php';
?>