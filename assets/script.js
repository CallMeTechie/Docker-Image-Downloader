/**
 * Docker Image Downloader - Client-Side JavaScript
 */

// DOM Ready
document.addEventListener('DOMContentLoaded', function() {
    initDeleteConfirmation();
    initFormValidation();
    initProgressTracking();
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
 * Progress Tracking System
 */
let progressInterval = null;
let progressCheckCount = 0;
const MAX_PROGRESS_CHECKS = 1200; // 10 Minuten bei 500ms Intervall

function initProgressTracking() {
    // Prüfe initial ob ein Download läuft
    checkProgress();

    // Starte Polling
    startProgressPolling();
}

function startProgressPolling() {
    if (progressInterval) {
        clearInterval(progressInterval);
    }

    progressInterval = setInterval(function() {
        checkProgress();
        progressCheckCount++;

        // Sicherheits-Timeout nach 10 Minuten
        if (progressCheckCount > MAX_PROGRESS_CHECKS) {
            stopProgressPolling();
            hideProgress();
        }
    }, 500); // Alle 500ms prüfen
}

function stopProgressPolling() {
    if (progressInterval) {
        clearInterval(progressInterval);
        progressInterval = null;
    }
    progressCheckCount = 0;
}

function checkProgress() {
    fetch('progress.php', {
        method: 'GET',
        cache: 'no-cache'
    })
    .then(response => response.json())
    .then(data => {
        if (data.active) {
            updateProgress(data);
        } else {
            // Kein aktiver Download mehr
            const container = document.getElementById('progress-container');
            if (container && container.classList.contains('active')) {
                // Download wurde gerade beendet - Seite neu laden
                setTimeout(function() {
                    window.location.reload();
                }, 1000);
            }
        }
    })
    .catch(error => {
        console.log('Progress check failed:', error);
        // Bei Fehler weitermachen, nicht abbrechen
    });
}

function updateProgress(data) {
    const container = document.getElementById('progress-container');
    const bar = document.getElementById('progress-bar');
    const percentage = document.getElementById('progress-percentage');
    const message = document.getElementById('progress-message');
    const stage = document.getElementById('progress-stage');

    if (!container || !bar || !percentage || !message || !stage) {
        return;
    }

    // Container anzeigen
    container.classList.add('active');

    // Fortschrittsbalken aktualisieren
    bar.style.width = data.percent + '%';

    // Prozentanzeige aktualisieren
    percentage.textContent = data.percent + '%';

    // Nachricht aktualisieren
    message.textContent = data.message;

    // Stage Badge aktualisieren
    stage.className = 'progress-stage ' + data.stage;

    // Stage Text
    const stageTexts = {
        'init': 'Vorbereitung',
        'download': 'Download',
        'tar': 'Archivierung'
    };
    stage.textContent = stageTexts[data.stage] || data.stage;
}

function hideProgress() {
    const container = document.getElementById('progress-container');
    if (container) {
        container.classList.remove('active');
    }
}

/**
 * Auto-Refresh für Download-Status
 */
function checkDownloadStatus() {
    // Diese Funktion wird jetzt durch initProgressTracking() ersetzt
    checkProgress();
}
