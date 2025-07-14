# Şablon Dosyaları

Bu klasöre aşağıdaki dosyaları yerleştirin:

## 1. Zimmet Tutanağı.xlsx
- Excel şablon dosyasını bu klasöre kopyalayın
- Dosya adı tam olarak "Zimmet Tutanağı.xlsx" olmalıdır
- Hücreler şu şekilde kullanılacak:
  - D7: Personel Ad Soyad
  - D6: Sicil Numarası
  - D8: Görev
  - D9: Departman
  - E18: Marka
  - G18: Model
  - I18: Seri No + Açıklama
  - B18: Eşya Adı
  - H46: Personel Ad Soyad + Görev
  - A34: Personel Ad Soyad
  - A35: Görev
  - A10: Dinamik metin (RichText)

## Dosya Yapısı:
```
zimmet_system/
├── templates/
│   ├── Zimmet Tutanağı.xlsx (şablon dosyası)
│   └── readme.txt (bu dosya)
├── uploads/
│   └── zimmet_tutanaklari/ (oluşturulan dosyalar)
└── ...
```

## Kullanım:
- Excel tutanağı indirmek için: print_excel.php?id=1&action=download
- Sunucuya kaydetmek için: print_excel.php?id=1&action=save
- Tarayıcıda görüntülemek için: print_excel.php?id=1&action=view