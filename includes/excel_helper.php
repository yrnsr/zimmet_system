<?php
// includes/excel_helper.php

/**
 * Excel dosyalarını okumak için yardımcı fonksiyonlar
 * Bu dosya SimpleXLSX kütüphanesi olmadan basit Excel okuma sağlar
 */

// Basit Excel (XLSX) okuma fonksiyonu
function parseExcelFile($file_path) {
    $data = [];
    
    // Dosya uzantısını kontrol et
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    
    if ($extension === 'xlsx') {
        $data = parseXLSX($file_path);
    } elseif ($extension === 'xls') {
        // XLS desteği için farklı yaklaşım gerekli
        throw new Exception("XLS formatı henüz desteklenmiyor. Lütfen XLSX formatını kullanın.");
    } elseif ($extension === 'csv') {
        $data = parseCSVFile($file_path);
    }
    
    return $data;
}

// XLSX dosyasını parse et (ZIP arşivi içindeki XML'leri okuyarak)
function parseXLSX($file_path) {
    if (!class_exists('ZipArchive')) {
        throw new Exception("ZipArchive sınıfı bulunamadı. PHP zip eklentisi gerekli.");
    }
    
    $zip = new ZipArchive();
    
    if ($zip->open($file_path) !== TRUE) {
        throw new Exception("Excel dosyası açılamadı.");
    }
    
    // Shared strings'i oku
    $sharedStrings = [];
    $sharedStringsXML = $zip->getFromName('xl/sharedStrings.xml');
    
    if ($sharedStringsXML) {
        $sharedStringsData = simplexml_load_string($sharedStringsXML);
        if ($sharedStringsData && isset($sharedStringsData->si)) {
            foreach ($sharedStringsData->si as $si) {
                $sharedStrings[] = (string)$si->t;
            }
        }
    }
    
    // Worksheet'i oku
    $worksheetXML = $zip->getFromName('xl/worksheets/sheet1.xml');
    
    if (!$worksheetXML) {
        $zip->close();
        throw new Exception("Excel çalışma sayfası okunamadı.");
    }
    
    $worksheetData = simplexml_load_string($worksheetXML);
    $zip->close();
    
    if (!$worksheetData || !isset($worksheetData->sheetData)) {
        throw new Exception("Excel verisi okunamadı.");
    }
    
    $data = [];
    $headers = [];
    $rowIndex = 0;
    
    foreach ($worksheetData->sheetData->row as $row) {
        $rowData = [];
        $colIndex = 0;
        $maxCol = 0;
        
        // Önce maksimum sütun sayısını bul
        foreach ($row->c as $cell) {
            $cellRef = (string)$cell['r'];
            $colNum = getColumnNumber($cellRef);
            if ($colNum > $maxCol) {
                $maxCol = $colNum;
            }
        }
        
        // Sütunları sırala ve boş hücreleri doldur
        for ($i = 1; $i <= $maxCol; $i++) {
            $rowData[$i] = '';
        }
        
        foreach ($row->c as $cell) {
            $cellRef = (string)$cell['r'];
            $colNum = getColumnNumber($cellRef);
            $value = '';
            
            // Hücre tipi kontrolü
            $cellType = (string)$cell['t'];
            
            if ($cellType === 's') {
                // Shared string
                $stringIndex = (int)$cell->v;
                $value = isset($sharedStrings[$stringIndex]) ? $sharedStrings[$stringIndex] : '';
            } else {
                // Direkt değer
                $value = (string)$cell->v;
            }
            
            $rowData[$colNum] = trim($value);
        }
        
        // Array'i yeniden indeksle
        $rowData = array_values($rowData);
        
        if ($rowIndex === 0) {
            // İlk satır başlık
            $headers = $rowData;
        } else {
            // Veri satırı
            if (!empty(array_filter($rowData))) {
                $personnelData = [];
                foreach ($headers as $index => $header) {
                    $key = mapHeaderToField($header);
                    $personnelData[$key] = isset($rowData[$index]) ? $rowData[$index] : '';
                }
                $data[] = $personnelData;
            }
        }
        
        $rowIndex++;
    }
    
    return $data;
}

// Excel hücre referansından sütun numarası çıkar (A=1, B=2, etc.)
function getColumnNumber($cellRef) {
    preg_match('/([A-Z]+)/', $cellRef, $matches);
    if (!isset($matches[1])) return 1;
    
    $columnName = $matches[1];
    $columnNumber = 0;
    
    for ($i = 0; $i < strlen($columnName); $i++) {
        $columnNumber = $columnNumber * 26 + (ord($columnName[$i]) - ord('A') + 1);
    }
    
    return $columnNumber;
}

// CSV dosyasını gelişmiş şekilde parse et
function parseCSVFile($file_path) {
    $data = [];
    
    // Dosya encoding'ini tespit et
    $content = file_get_contents($file_path);
    $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1254', 'ISO-8859-9']);
    
    if ($encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        file_put_contents($file_path, $content);
    }
    
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        $headers = [];
        $rowIndex = 0;
        
        // Farklı delimiter'ları dene
        $delimiters = [',', ';', "\t", '|'];
        $sample = fgets($handle);
        rewind($handle);
        
        $delimiter = ',';
        $maxCols = 0;
        
        foreach ($delimiters as $del) {
            $cols = count(str_getcsv($sample, $del));
            if ($cols > $maxCols) {
                $maxCols = $cols;
                $delimiter = $del;
            }
        }
        
        while (($row = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
            if ($rowIndex === 0) {
                $headers = array_map('trim', $row);
            } else {
                $personnelData = [];
                foreach ($headers as $index => $header) {
                    $key = mapHeaderToField($header);
                    $personnelData[$key] = isset($row[$index]) ? trim($row[$index]) : '';
                }
                
                if (!empty(array_filter($personnelData))) {
                    $data[] = $personnelData;
                }
            }
            $rowIndex++;
        }
        
        fclose($handle);
    }
    
    return $data;
}

// Header'ları field'lara map et (bulk_add.php'deki fonksiyonla aynı)
function mapHeaderToField($header) {
    $header = strtolower(trim($header));
    
    // Türkçe karakterleri dönüştür
    $header = str_replace(
        ['ç', 'ğ', 'ı', 'ş', 'ö', 'ü'],
        ['c', 'g', 'i', 's', 'o', 'u'],
        $header
    );
    
    $mapping = [
        'sicil no' => 'sicil_no',
        'sicil_no' => 'sicil_no',
        'sicilno' => 'sicil_no',
        'sicil numarasi' => 'sicil_no',
        'employee_id' => 'sicil_no',
        'id' => 'sicil_no',
        'personel no' => 'sicil_no',
        'personel_no' => 'sicil_no',
        'calisan no' => 'sicil_no',
        
        'ad' => 'name',
        'name' => 'name',
        'isim' => 'name',
        'first_name' => 'name',
        'adi' => 'name',
        'personel adi' => 'name',
        'calisan adi' => 'name',
        
        'soyad' => 'surname',
        'surname' => 'surname',
        'soyadi' => 'surname',
        'last_name' => 'surname',
        'family_name' => 'surname',
        'personel soyadi' => 'surname',
        
        'email' => 'email',
        'e-mail' => 'email',
        'e_mail' => 'email',
        'eposta' => 'email',
        'e-posta' => 'email',
        'mail' => 'email',
        'mail adresi' => 'email',
        'elektronik posta' => 'email',
        
        'telefon' => 'phone',
        'phone' => 'phone',
        'tel' => 'phone',
        'mobile' => 'phone',
        'gsm' => 'phone',
        'cep' => 'phone',
        'cep telefonu' => 'phone',
        'telefon no' => 'phone',
        'telefon numarasi' => 'phone',
        
        'departman' => 'department',
        'department' => 'department',
        'dept' => 'department',
        'bolum' => 'department',
        'birim' => 'department',
        'unit' => 'department',
        'calisma birimi' => 'department',
        'organizasyon' => 'department',
        'sektor' => 'department',
        
        'pozisyon' => 'position',
        'position' => 'position',
        'gorev' => 'position',
        'unvan' => 'position',
        'title' => 'position',
        'job_title' => 'position',
        'is unvani' => 'position',
        'calisma pozisyonu' => 'position',
        'rol' => 'position',
        'gorev tanimi' => 'position',
        
        'baslama tarihi' => 'start_date',
        'start_date' => 'start_date',
        'ise baslama' => 'start_date',
        'giris tarihi' => 'start_date',
        'hire_date' => 'start_date',
        'employment_date' => 'start_date',
        
        'maas' => 'salary',
        'salary' => 'salary',
        'ucret' => 'salary',
        'aylik' => 'salary',
        'gelir' => 'salary',
        'wage' => 'salary',
        
        'aktif' => 'is_active',
        'active' => 'is_active',
        'durum' => 'is_active',
        'status' => 'is_active',
        'calisma durumu' => 'is_active',
        'personel durumu' => 'is_active'
    ];
    
    // Eğer mapping'de varsa o field'ı döndür, yoksa orijinal header'ı temizlenmiş haliyle döndür
    if (isset($mapping[$header])) {
        return $mapping[$header];
    }
    
    // Boşlukları underscore yap ve özel karakterleri temizle
    $cleanHeader = preg_replace('/[^a-z0-9_]/', '_', $header);
    $cleanHeader = preg_replace('/_+/', '_', $cleanHeader);
    $cleanHeader = trim($cleanHeader, '_');
    
    return $cleanHeader;
}

// Excel dosyasını validate et
function validateExcelData($data) {
    $errors = [];
    $warnings = [];
    
    if (empty($data)) {
        $errors[] = "Excel dosyası boş veya okunamadı.";
        return ['errors' => $errors, 'warnings' => $warnings];
    }
    
    foreach ($data as $index => $row) {
        $rowNumber = $index + 2; // Excel'de header 1. satır, data 2. satırdan başlar
        
        // Zorunlu alanları kontrol et
        if (empty($row['sicil_no'])) {
            $errors[] = "Satır {$rowNumber}: Sicil numarası boş olamaz.";
        }
        
        if (empty($row['name']) && empty($row['surname'])) {
            $errors[] = "Satır {$rowNumber}: Ad veya soyad boş olamaz.";
        }
        
        // Email formatını kontrol et
        if (!empty($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $warnings[] = "Satır {$rowNumber}: Geçersiz email formatı ({$row['email']}).";
        }
        
        // Telefon numarası formatını kontrol et (basit kontrol)
        if (!empty($row['phone']) && !preg_match('/^[0-9\s\+\-\(\)]+$/', $row['phone'])) {
            $warnings[] = "Satır {$rowNumber}: Geçersiz telefon formatı ({$row['phone']}).";
        }
        
        // Sicil numarası benzersizlik kontrolü (array içinde)
        $sicilNos = array_column($data, 'sicil_no');
        $duplicates = array_count_values($sicilNos);
        
        if ($duplicates[$row['sicil_no']] > 1) {
            $errors[] = "Satır {$rowNumber}: Sicil numarası ({$row['sicil_no']}) tekrar ediyor.";
        }
    }
    
    return ['errors' => $errors, 'warnings' => $warnings];
}

// Personel verilerini normalize et
function normalizePersonnelData($data) {
    foreach ($data as &$row) {
        // Sicil numarası
        if (isset($row['sicil_no'])) {
            $row['sicil_no'] = trim($row['sicil_no']);
        }
        
        // İsim alanları
        if (isset($row['name'])) {
            $row['name'] = ucwords(strtolower(trim($row['name'])));
        }
        
        if (isset($row['surname'])) {
            $row['surname'] = ucwords(strtolower(trim($row['surname'])));
        }
        
        // Email
        if (isset($row['email'])) {
            $row['email'] = strtolower(trim($row['email']));
        }
        
        // Telefon
        if (isset($row['phone'])) {
            $row['phone'] = preg_replace('/[^0-9]/', '', $row['phone']);
        }
        
        // Departman ve pozisyon
        if (isset($row['department'])) {
            $row['department'] = trim($row['department']);
        }
        
        if (isset($row['position'])) {
            $row['position'] = trim($row['position']);
        }
        
        // Aktif durumu normalize et
        if (isset($row['is_active'])) {
            $activeValue = strtolower(trim($row['is_active']));
            if (in_array($activeValue, ['1', 'true', 'aktif', 'active', 'evet', 'yes'])) {
                $row['is_active'] = 1;
            } else {
                $row['is_active'] = 0;
            }
        } else {
            $row['is_active'] = 1; // Varsayılan aktif
        }
    }
    
    return $data;
}

// Desteklenen Excel formatlarını kontrol et
function getSupportedFormats() {
    return ['xlsx', 'csv'];
}

// Dosya boyutu kontrolü (MB)
function checkFileSize($file_path, $maxSizeMB = 10) {
    $fileSizeBytes = filesize($file_path);
    $fileSizeMB = $fileSizeBytes / (1024 * 1024);
    
    return $fileSizeMB <= $maxSizeMB;
}

?>