/**
 * Docker Image Downloader - Client-Side JavaScript
 */

// DOM Ready
document.addEventListener('DOMContentLoaded', function() {
    initDeleteConfirmation();
    initFormValidation();
});

/**
 * Bestätigungsdialog für Lösch-Aktionen
 */
function initDeleteConfirmation() {
    const deleteLinks = document.querySelectorAll('.btn-delete');
    
    deleteLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            const fileName = this.getAttribute('data-filename');
            const message = fileName 
                ? 'Image "' + fileName + '" wirklich löschen?' 
                : 'Wirklich löschen?';
            
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });
}

/**
 * Formular-Validierung
 */
function initFormValidation() {
    const form = document.querySelector('form[method="POST"]');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            const imageName = document.getElementById('image_name');
            
            if (imageName && !imageName.value.trim()) {
                alert('Bitte geben Sie einen Image-Namen ein.');
                imageName.focus();
                e.preventDefault();
                return false;
            }
        });
    }
}

/**
 * Auto-Refresh für Download-Status (optional)
 */
function checkDownloadStatus() {
    // Könnte verwendet werden um Status via AJAX zu aktualisieren
    // Aktuell nicht implementiert
}
