</div> <!-- content-area -->
    </div> <!-- main-content -->

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        // Sidebar toggle function
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        }
        
        // DataTables varsayılan ayarları
        $(document).ready(function() {
            $('.datatable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.11.5/i18n/tr.json"
                },
                "pageLength": 25,
                "responsive": true,
                "order": [[0, "desc"]]
            });
        });
        
        // Confirm dialog
        function confirmDelete(message = 'Bu kaydı silmek istediğinizden emin misiniz?') {
            return confirm(message);
        }
        
        // Auto-hide alerts
        setTimeout(function() {
            $('.alert').fadeOut('slow');
        }, 5000);
        
        // Form validation
        function validateForm(formId) {
            const form = document.getElementById(formId);
            if (form) {
                const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
                let isValid = true;
                
                inputs.forEach(function(input) {
                    if (!input.value.trim()) {
                        input.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        input.classList.remove('is-invalid');
                    }
                });
                
                return isValid;
            }
            return true;
        }
        
        // Number formatting
        function formatNumber(num) {
            return new Intl.NumberFormat('tr-TR').format(num);
        }
        
        // Date formatting
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('tr-TR');
        }
        
        // Loading spinner
        function showLoading(element) {
            if (element) {
                element.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Yükleniyor...';
                element.disabled = true;
            }
        }
        
        function hideLoading(element, originalText) {
            if (element) {
                element.innerHTML = originalText;
                element.disabled = false;
            }
        }
        
        // AJAX error handler
        function handleAjaxError(xhr, status, error) {
            console.error('AJAX Error:', error);
            alert('Bir hata oluştu. Lütfen tekrar deneyin.');
        }
        
        // Print function
        function printPage() {
            window.print();
        }
        
        // Export table to CSV
        function exportTableToCSV(tableId, filename = 'export.csv') {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const cols = row.querySelectorAll('td, th');
                let csvRow = [];
                
                for (let j = 0; j < cols.length; j++) {
                    csvRow.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
                }
                
                csv.push(csvRow.join(','));
            }
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
        
        // Auto-complete for search inputs
        function setupAutoComplete(inputId, dataUrl) {
            const input = document.getElementById(inputId);
            if (!input) return;
            
            input.addEventListener('input', function() {
                const query = this.value;
                if (query.length < 2) return;
                
                fetch(dataUrl + '?q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        // Handle autocomplete results
                        console.log(data);
                    })
                    .catch(error => console.error('Error:', error));
            });
        }
    </script>
    
    <?php if (isset($extra_js)): ?>
        <?= $extra_js ?>
    <?php endif; ?>

</body>
</html>

<?php
// Output buffer'ı temizle
if (ob_get_level()) {
    ob_end_flush();
}
?>