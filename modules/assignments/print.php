<?php
// modules/assignments/print.php

require_once '../../config/database.php';
require_once '../../includes/functions.php';

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
    
    // Şirket bilgileri
    $company_name = getSetting('company_name', 'Firma Adı A.Ş.');
    
} catch(Exception $e) {
    die("Zimmet bilgileri yüklenirken hata oluştu: " . $e->getMessage());
}

// Durum çeviri
function getStatusTextTurkish($status) {
    $statuses = [
        'active' => 'Aktif',
        'returned' => 'İade Edildi',
        'lost' => 'Kayıp',
        'damaged' => 'Hasarlı'
    ];
    return $statuses[$status] ?? $status;
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zimmet Tutanağı - <?= htmlspecialchars($assignment['assignment_number']) ?></title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            margin: 20px;
            color: #000;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
        }
        
        .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .document-title {
            font-size: 16px;
            font-weight: bold;
            margin-top: 15px;
        }
        
        .info-section {
            margin-bottom: 20px;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .info-table td {
            padding: 8px;
            border: 1px solid #000;
            vertical-align: top;
        }
        
        .info-table .label {
            background-color: #f0f0f0;
            font-weight: bold;
            width: 25%;
        }
        
        .signatures {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-box {
            width: 45%;
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 60px;
            padding-top: 5px;
        }
        
        .terms {
            margin-top: 30px;
            font-size: 10px;
            border: 1px solid #000;
            padding: 10px;
            background-color: #f9f9f9;
        }
        
        .terms h4 {
            margin-top: 0;
            font-size: 12px;
        }
        
        .terms ul {
            margin: 5px 0;
            padding-left: 20px;
        }
        
        .terms li {
            margin-bottom: 3px;
        }
        
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 72px;
            color: rgba(0,0,0,0.1);
            z-index: -1;
            pointer-events: none;
        }
        
        .no-print {
            background: #fff;
            padding: 10px;
            border: 1px solid #ddd;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .print-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            margin: 0 5px;
            cursor: pointer;
            border-radius: 4px;
        }
        
        .print-btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <?php if ($assignment['status'] !== 'active'): ?>
        <div class="watermark"><?= getStatusTextTurkish($assignment['status']) ?></div>
    <?php endif; ?>
    
    <!-- Yazdırma Kontrolleri -->
    <div class="no-print">
        <button class="print-btn" onclick="window.print()">
            🖨️ Yazdır
        </button>
        <button class="print-btn" onclick="window.close()" style="background: #6c757d;">
            ❌ Kapat
        </button>
        <button class="print-btn" onclick="history.back()" style="background: #28a745;">
            ↩️ Geri Dön
        </button>
    </div>
    
    <!-- Tutanak İçeriği -->
    <div class="header">
        <div class="company-name"><?= htmlspecialchars($company_name) ?></div>
        <div class="document-title">ZİMMET TUTANAĞI</div>
        <div style="margin-top: 10px;">
            <strong>Zimmet No:</strong> <?= htmlspecialchars($assignment['assignment_number']) ?> &nbsp;&nbsp;&nbsp;
            <strong>Tarih:</strong> <?= formatDate($assignment['assigned_date']) ?>
        </div>
    </div>
    
    <!-- Personel Bilgileri -->
    <div class="info-section">
        <h3>PERSONEL BİLGİLERİ</h3>
        <table class="info-table">
            <tr>
                <td class="label">Ad Soyad:</td>
                <td><?= htmlspecialchars($assignment['personnel_name']) ?></td>
                <td class="label">Sicil No:</td>
                <td><?= htmlspecialchars($assignment['sicil_no']) ?></td>
            </tr>
            <tr>
                <td class="label">Departman:</td>
                <td><?= htmlspecialchars($assignment['department_name'] ?: 'Belirtilmemiş') ?></td>
                <td class="label">Görev:</td>
                <td><?= htmlspecialchars($assignment['position'] ?: 'Belirtilmemiş') ?></td>
            </tr>
            <tr>
                <td class="label">E-posta:</td>
                <td><?= htmlspecialchars($assignment['personnel_email'] ?: '-') ?></td>
                <td class="label">Telefon:</td>
                <td><?= htmlspecialchars($assignment['personnel_phone'] ?: '-') ?></td>
            </tr>
        </table>
    </div>
    
    <!-- Malzeme Bilgileri -->
    <div class="info-section">
        <h3>MALZEME BİLGİLERİ</h3>
        <table class="info-table">
            <tr>
                <td class="label">Malzeme Adı:</td>
                <td colspan="3"><?= htmlspecialchars($assignment['item_name']) ?></td>
            </tr>
            <tr>
                <td class="label">Malzeme Kodu:</td>
                <td><?= htmlspecialchars($assignment['item_code']) ?></td>
                <td class="label">Kategori:</td>
                <td><?= htmlspecialchars($assignment['category_name'] ?: 'Kategorisiz') ?></td>
            </tr>
            <tr>
                <td class="label">Marka:</td>
                <td><?= htmlspecialchars($assignment['brand'] ?: '-') ?></td>
                <td class="label">Model:</td>
                <td><?= htmlspecialchars($assignment['model'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="label">Seri No:</td>
                <td><?= htmlspecialchars($assignment['serial_number'] ?: '-') ?></td>
                <td class="label">Satın Alma Tarihi:</td>
                <td><?= formatDate($assignment['purchase_date']) ?></td>
            </tr>
            <?php if ($assignment['purchase_price']): ?>
            <tr>
                <td class="label">Değer:</td>
                <td colspan="3"><?= number_format($assignment['purchase_price'], 2) ?> TL</td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
    
    <!-- Zimmet Detayları -->
    <div class="info-section">
        <h3>ZİMMET DETAYLARI</h3>
        <table class="info-table">
            <tr>
                <td class="label">Zimmet Veriliş Tarihi:</td>
                <td><?= formatDate($assignment['assigned_date']) ?></td>
                <td class="label">Zimmet Veren:</td>
                <td><?= htmlspecialchars($assignment['assigned_by_user']) ?></td>
            </tr>
            <tr>
                <td class="label">Durum:</td>
                <td><?= getStatusTextTurkish($assignment['status']) ?></td>
                <td class="label">İade Tarihi:</td>
                <td><?= formatDate($assignment['return_date']) ?></td>
            </tr>
            <?php if ($assignment['assignment_notes']): ?>
            <tr>
                <td class="label">Zimmet Notları:</td>
                <td colspan="3"><?= nl2br(htmlspecialchars($assignment['assignment_notes'])) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($assignment['return_notes']): ?>
            <tr>
                <td class="label">İade Notları:</td>
                <td colspan="3"><?= nl2br(htmlspecialchars($assignment['return_notes'])) ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
    
    <!-- Koşullar ve Sorumluluklar -->
    <div class="terms">
        <h4>ZİMMET KOŞULLARI VE SORUMLULUKLAR</h4>
        <ul>
            <li>Zimmet verilen malzeme, yalnızca işle ilgili amaçlar için kullanılacaktır.</li>
            <li>Malzeme kaybı, hasarı veya çalınması durumunda derhal üst amire bilgi verilecektir.</li>
            <li>Personel, zimmet aldığı malzemeden tam olarak sorumludur.</li>
            <li>İş ilişkisi sona erdiğinde, tüm zimmet malzemeler eksiksiz olarak iade edilecektir.</li>
            <li>Malzeme üzerinde yapılacak değişiklik ve modifikasyonlar için önceden izin alınacaktır.</li>
            <li>Bu tutanak iki nüsha düzenlenmiş olup, bir nüshası personelde, diğeri şirket arşivinde kalacaktır.</li>
        </ul>
    </div>
    
    <!-- İmza Alanları -->
    <div class="signatures">
        <div class="signature-box">
            <div><strong>ZİMMET ALAN</strong></div>
            <div style="margin-top: 10px;">
                <div>Ad Soyad: <?= htmlspecialchars($assignment['personnel_name']) ?></div>
                <div>Sicil No: <?= htmlspecialchars($assignment['sicil_no']) ?></div>
            </div>
            <div class="signature-line">İmza</div>
        </div>
        
        <div class="signature-box">
            <div><strong>ZİMMET VEREN</strong></div>
            <div style="margin-top: 10px;">
                <div>Yetkili: <?= htmlspecialchars($assignment['assigned_by_user']) ?></div>
                <div>Tarih: <?= formatDate($assignment['assigned_date']) ?></div>
            </div>
            <div class="signature-line">İmza & Kaşe</div>
        </div>
    </div>
    
    <!-- İade Bölümü (Eğer iade edilmişse) -->
    <?php if ($assignment['status'] === 'returned' && $assignment['return_date']): ?>
    <div class="page-break"></div>
    
    <div class="header">
        <div class="company-name"><?= htmlspecialchars($company_name) ?></div>
        <div class="document-title">ZİMMET İADE TUTANAĞI</div>
        <div style="margin-top: 10px;">
            <strong>Zimmet No:</strong> <?= htmlspecialchars($assignment['assignment_number']) ?> &nbsp;&nbsp;&nbsp;
            <strong>İade Tarihi:</strong> <?= formatDate($assignment['return_date']) ?>
        </div>
    </div>
    
    <div class="info-section">
        <h3>İADE BİLGİLERİ</h3>
        <table class="info-table">
            <tr>
                <td class="label">İade Tarihi:</td>
                <td><?= formatDate($assignment['return_date']) ?></td>
                <td class="label">İade Alan:</td>
                <td><?= htmlspecialchars($assignment['returned_by_user']) ?></td>
            </tr>
            <tr>
                <td class="label">İade Durumu:</td>
                <td colspan="3"><?= getStatusTextTurkish($assignment['status']) ?></td>
            </tr>
            <?php if ($assignment['return_notes']): ?>
            <tr>
                <td class="label">İade Notları:</td>
                <td colspan="3"><?= nl2br(htmlspecialchars($assignment['return_notes'])) ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
    
    <div class="signatures">
        <div class="signature-box">
            <div><strong>ZİMMET İADE EDEN</strong></div>
            <div style="margin-top: 10px;">
                <div>Ad Soyad: <?= htmlspecialchars($assignment['personnel_name']) ?></div>
                <div>Sicil No: <?= htmlspecialchars($assignment['sicil_no']) ?></div>
            </div>
            <div class="signature-line">İmza</div>
        </div>
        
        <div class="signature-box">
            <div><strong>ZİMMET TESLİM ALAN</strong></div>
            <div style="margin-top: 10px;">
                <div>Yetkili: <?= htmlspecialchars($assignment['returned_by_user']) ?></div>
                <div>Tarih: <?= formatDate($assignment['return_date']) ?></div>
            </div>
            <div class="signature-line">İmza & Kaşe</div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Sayfa Alt Bilgi -->
    <div style="position: fixed; bottom: 20px; right: 20px; font-size: 10px; color: #666; text-align: right;" class="no-print">
        Zimmet Takip Sistemi<br>
        Yazdırma Tarihi: <?= date('d.m.Y H:i') ?>
    </div>

    <script>
        // Sayfa yüklendiğinde otomatik yazdırma seçeneği
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('auto_print') === '1') {
                setTimeout(function() {
                    window.print();
                }, 1000);
            }
        });
        
        // Yazdırma sonrası sayfa kapama
        window.addEventListener('afterprint', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('auto_close') === '1') {
                setTimeout(function() {
                    window.close();
                }, 2000);
            }
        });
    </script>
</body>
</html>