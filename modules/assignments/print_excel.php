<?php
// modules/assignments/print_excel.php

require_once '../../config/database.php';
require_once '../../includes/functions.php';

// PhpSpreadsheet kütüphanesi
require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

startSession();

// Giriş kontrolü
if (!isLoggedIn()) {
    header('Location: ' . url('modules/auth/login.php'));
    exit();
}

// ID kontrolü
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    die("Geçersiz zimmet ID");
}

try {
    $db = getDB();
    
    // Zimmet bilgilerini getir
    $stmt = $db->prepare("
        SELECT 
            a.*,
            CONCAT(p.name, ' ', p.surname) as personnel_name,
            p.name as personnel_first_name,
            p.surname as personnel_last_name,
            p.sicil_no,
            p.position,
            p.email as personnel_email,
            p.phone as personnel_phone,
            d.name as department_name,
            i.name as item_name,
            i.item_code,
            i.brand,
            i.model,
            i.serial_number,
            i.description,
            i.purchase_price,
            i.purchase_date,
            c.name as category_name,
            u1.username as assigned_by_user,
            u2.username as returned_by_user
        FROM assignments a
        JOIN personnel p ON a.personnel_id = p.id
        LEFT JOIN departments d ON p.department_id = d.id
        JOIN items i ON a.item_id = i.id
        LEFT JOIN categories c ON i.category_id = c.id
        LEFT JOIN users u1 ON a.assigned_by = u1.id
        LEFT JOIN users u2 ON a.returned_by = u2.id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $assignment = $stmt->fetch();
    
    if (!$assignment) {
        die("Zimmet kaydı bulunamadı");
    }
    
} catch(Exception $e) {
    die("Zimmet bilgileri yüklenirken hata oluştu: " . $e->getMessage());
}

// Excel şablonunu yükle
try {
    $templatePath = '../../templates/Zimmet Tutanağı.xlsx';
    
    // Şablon dosyası kontrolü
    if (!file_exists($templatePath)) {
        die("Excel şablonu bulunamadı: " . $templatePath);
    }
    
    $spreadsheet = IOFactory::load($templatePath);
    $sheet = $spreadsheet->getActiveSheet();
    
    // Veri dizisi hazırla
    $data = [
        'PersonelAd' => $assignment['personnel_first_name'],
        'PersonelSoyad' => $assignment['personnel_last_name'], 
        'Sicil' => $assignment['sicil_no'],
        'Gorev' => $assignment['position'] ?: 'Belirtilmemiş',
        'Departman' => $assignment['department_name'] ?: 'Belirtilmemiş',
        'EsyaAdi' => $assignment['item_name'],
        'Marka' => $assignment['brand'] ?: '',
        'Model' => $assignment['model'] ?: '',
        'SeriNo' => $assignment['serial_number'] ?: '',
        'Aciklama' => $assignment['description'] ?: '',
        'ZimmetNo' => $assignment['assignment_number'],
        'ZimmetTarihi' => formatDate($assignment['assigned_date']),
        'ZimmetVeren' => $assignment['assigned_by_user']
    ];
    
    // Hücrelere veri yazma
    $sheet->setCellValue('D7', $data['PersonelAd'] . ' ' . $data['PersonelSoyad']);
    $sheet->setCellValue('D6', $data['Sicil']);
    $sheet->setCellValue('D8', $data['Gorev']);
    $sheet->setCellValue('D9', $data['Departman']);
    $sheet->setCellValue('E18', $data['Marka']);
    $sheet->setCellValue('G18', $data['Model']);
    $sheet->setCellValue('I18', $data['SeriNo'] . ' ' . $data['Aciklama']);
    $sheet->setCellValue('B18', $data['EsyaAdi']);
    $sheet->setCellValue('H46', $data['PersonelAd'] . ' ' . $data['PersonelSoyad'] . ' ' . $data['Gorev']);
    $sheet->setCellValue('A34', $data['PersonelAd'] . ' ' . $data['PersonelSoyad']);
    $sheet->setCellValue('A35', $data['Gorev']);
    
    // RichText ile dinamik metin oluştur
    $isimSoyisim = $data['PersonelAd'] . ' ' . $data['PersonelSoyad'];
    $tarih = $data['ZimmetTarihi'];
    
    // RichText nesnesi oluştur
    $richText = new RichText();
    
    // İlk normal metin
    $normal1 = $richText->createTextRun("Aşağıda tanımı ve özellikleri belirtilen şirket demirbaşı, ");
    
    // Tarihi kalın yaz
    $tarihRun = $richText->createTextRun($tarih);
    $tarihRun->getFont()->setBold(true);
    
    // Devam metni
    $normal2 = $richText->createTextRun(" tarihinde, şirket çalışanı ");
    
    // İsim soyisim kalın yaz
    $isimRun = $richText->createTextRun($isimSoyisim);
    $isimRun->getFont()->setBold(true);
    
    // Son metin
    $normal3 = $richText->createTextRun("'a teslim edilmiştir.");
    
    // Hücreye ekle
    $sheet->getCell('A10')->setValue($richText);
    
    // Ek bilgiler (eğer hücreler varsa)
    try {
        // Zimmet numarası
        $sheet->setCellValue('A1', 'Zimmet No: ' . $data['ZimmetNo']);
        
        // Zimmet veren bilgisi
        $sheet->setCellValue('A40', 'Zimmet Veren: ' . $data['ZimmetVeren']);
        
        // Tarih bilgisi
        $sheet->setCellValue('A41', 'Tarih: ' . $data['ZimmetTarihi']);
        
    } catch(Exception $e) {
        // Hücreler yoksa devam et
    }
    
    // Dosya adı oluştur
    $fileName = 'Zimmet_Tutanagi_' . $data['ZimmetNo'] . '_' . date('Ymd_His') . '.xlsx';
    
    // Download veya görüntüleme modunu kontrol et
    $action = isset($_GET['action']) ? $_GET['action'] : 'download';
    
    if ($action === 'download') {
        // İndir
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        
    } elseif ($action === 'save') {
        // Sunucuya kaydet
        $saveDir = '../../uploads/zimmet_tutanaklari/';
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0777, true);
        }
        
        $savePath = $saveDir . $fileName;
        $writer = new Xlsx($spreadsheet);
        $writer->save($savePath);
        
        // Log kaydet
        writeLog("Excel zimmet tutanağı oluşturuldu: " . $fileName);
        
        // JSON response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Tutanak başarıyla oluşturuldu',
            'filename' => $fileName,
            'path' => $savePath
        ]);
        
    } else {
        // Tarayıcıda görüntüle
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: inline;filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
    }
    
    // Belleği temizle
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    
} catch(Exception $e) {
    die("Excel dosyası oluşturulurken hata oluştu: " . $e->getMessage());
}
?>