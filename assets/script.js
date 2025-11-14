/**
 * Docker Image Downloader - Enhanced Client-Side JavaScript
 * Velzon Inspired Dashboard with Dark Mode Support
 */

// DOM Ready
document.addEventListener('DOMContentLoaded', function() {
    initThemeToggle();
    initSidebarToggle();
    initDeleteConfirmation();
    initFormValidation();
    initProgressTracking();
    updateStats();
});

/**
 * Theme Toggle - Dark/Light Mode
 */
function initThemeToggle() {
    const themeToggle = document.getElementById('theme-toggle');
    const themeIcon = document.getElementById('theme-icon');

    // Load saved theme or default to light
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme, themeIcon);

    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            updateThemeIcon(newTheme, themeIcon);
        });
    }
}

function updateThemeIcon(theme, iconElement) {
    if (!iconElement) return;

    if (theme === 'dark') {
        iconElement.textContent = '☀️';
    } else {
        iconElement.textContent = '🌙';
    }
}

/**
 * Sidebar Toggle for Mobile
 */
function initSidebarToggle() {
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });

        // Close sidebar when clicking outside on mobile
        if (mainContent) {
            mainContent.addEventListener('click', function() {
                if (window.innerWidth <= 768 && sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                }
            });
        }
    }
}

/**
 * Delete Confirmation Dialog
 */
function initDeleteConfirmation() {
    const deleteLinks = document.querySelectorAll('.btn-delete, [data-action="delete"]');

    deleteLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            const fileName = this.getAttribute('data-filename');
            const message = fileName
                ? 'Möchten Sie das Image "' + fileName + '" wirklich löschen?'
                : 'Wirklich löschen?';

            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });
}

/**
 * Form Validation
 */
function initFormValidation() {
    // Target only the download form, not the login form
    const form = document.getElementById('download-form');

    if (form) {
        form.addEventListener('submit', function(e) {
            const imageName = document.getElementById('image_name');

            if (imageName && !imageName.value.trim()) {
                alert('Bitte geben Sie einen Image-Namen ein.');
                imageName.focus();
                e.preventDefault();
                return false;
            }

            // Show progress card when form is submitted
            const progressCard = document.querySelector('.progress-card');
            if (progressCard) {
                progressCard.classList.add('active');
            }
        });
    }
}

/**
 * Progress Tracking System
 */
let progressInterval = null;
let progressCheckCount = 0;
const MAX_PROGRESS_CHECKS = 1200; // 10 minutes at 500ms interval

function initProgressTracking() {
    // Check initially if download is running
    checkProgress();

    // Start polling
    startProgressPolling();
}

function startProgressPolling() {
    if (progressInterval) {
        clearInterval(progressInterval);
    }

    progressInterval = setInterval(function() {
        checkProgress();
        progressCheckCount++;

        // Safety timeout after 10 minutes
        if (progressCheckCount > MAX_PROGRESS_CHECKS) {
            stopProgressPolling();
            hideProgress();
        }
    }, 500); // Check every 500ms
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
            // No active download
            const container = document.querySelector('.progress-card');
            if (container && container.classList.contains('active')) {
                // Download just finished - reload page
                setTimeout(function() {
                    window.location.reload();
                }, 1000);
            }
        }
    })
    .catch(error => {
        console.log('Progress check failed:', error);
        // Continue on error, don't stop polling
    });
}

function updateProgress(data) {
    const container = document.querySelector('.progress-card');
    const bar = document.getElementById('progress-bar');
    const percentage = document.getElementById('progress-percentage');
    const message = document.getElementById('progress-message');
    const stage = document.getElementById('progress-stage');

    if (!container || !bar || !percentage || !message || !stage) {
        return;
    }

    // Show container
    container.classList.add('active');

    // Update progress bar
    bar.style.width = data.percent + '%';

    // Update percentage
    percentage.textContent = data.percent + '%';

    // Update message
    message.textContent = data.message;

    // Update stage badge
    stage.className = 'progress-stage ' + data.stage;

    // Stage text
    const stageTexts = {
        'init': 'Vorbereitung',
        'download': 'Download',
        'tar': 'Archivierung'
    };
    stage.textContent = stageTexts[data.stage] || data.stage;
}

function hideProgress() {
    const container = document.querySelector('.progress-card');
    if (container) {
        container.classList.remove('active');
    }
}

/**
 * Update Dashboard Statistics
 */
function updateStats() {
    const totalImagesElem = document.getElementById('total-images');
    const totalSizeElem = document.getElementById('total-size');

    if (totalImagesElem) {
        // Count is already set by PHP, but we can animate it
        animateCounter(totalImagesElem);
    }

    if (totalSizeElem) {
        // Size is already set by PHP
        animateCounter(totalSizeElem);
    }
}

/**
 * Animate Counter Numbers
 */
function animateCounter(element) {
    const target = parseFloat(element.textContent);
    if (isNaN(target)) return;

    const duration = 1000;
    const steps = 30;
    const increment = target / steps;
    let current = 0;
    let step = 0;

    const timer = setInterval(function() {
        step++;
        current += increment;

        if (step >= steps) {
            clearInterval(timer);
            element.textContent = target.toFixed(element.dataset.decimals || 0);
        } else {
            element.textContent = current.toFixed(element.dataset.decimals || 0);
        }
    }, duration / steps);
}

/**
 * Table Search/Filter (if needed in future)
 */
function initTableSearch() {
    const searchInput = document.getElementById('table-search');
    const table = document.querySelector('.table');

    if (!searchInput || !table) return;

    searchInput.addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(function(row) {
            const text = row.textContent.toLowerCase();
            if (text.indexOf(searchTerm) > -1) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
}

/**
 * Tooltips (Simple Implementation)
 */
function initTooltips() {
    const tooltipTriggers = document.querySelectorAll('[data-tooltip]');

    tooltipTriggers.forEach(function(trigger) {
        trigger.addEventListener('mouseenter', function() {
            const tooltipText = this.getAttribute('data-tooltip');
            showTooltip(this, tooltipText);
        });

        trigger.addEventListener('mouseleave', function() {
            hideTooltip();
        });
    });
}

function showTooltip(element, text) {
    // Simple tooltip implementation
    const tooltip = document.createElement('div');
    tooltip.className = 'tooltip';
    tooltip.textContent = text;
    tooltip.style.position = 'absolute';
    tooltip.style.background = '#333';
    tooltip.style.color = '#fff';
    tooltip.style.padding = '5px 10px';
    tooltip.style.borderRadius = '4px';
    tooltip.style.fontSize = '12px';
    tooltip.style.zIndex = '9999';

    document.body.appendChild(tooltip);

    const rect = element.getBoundingClientRect();
    tooltip.style.top = (rect.top - tooltip.offsetHeight - 5) + 'px';
    tooltip.style.left = (rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)) + 'px';
}

function hideTooltip() {
    const tooltip = document.querySelector('.tooltip');
    if (tooltip) {
        tooltip.remove();
    }
}

/**
 * Copy to Clipboard Functionality
 */
function copyToClipboard(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            showNotification('In Zwischenablage kopiert', 'success');
        }).catch(function() {
            fallbackCopyToClipboard(text);
        });
    } else {
        fallbackCopyToClipboard(text);
    }
}

function fallbackCopyToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    document.body.appendChild(textArea);
    textArea.select();

    try {
        document.execCommand('copy');
        showNotification('In Zwischenablage kopiert', 'success');
    } catch (err) {
        showNotification('Kopieren fehlgeschlagen', 'danger');
    }

    document.body.removeChild(textArea);
}

/**
 * Simple Notification System
 */
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = 'notification notification-' + type;
    notification.textContent = message;
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.padding = '15px 20px';
    notification.style.borderRadius = '8px';
    notification.style.zIndex = '10000';
    notification.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    notification.style.animation = 'slideInRight 0.3s ease';

    // Style based on type
    const colors = {
        success: { bg: '#0ab39c', color: '#fff' },
        danger: { bg: '#f06548', color: '#fff' },
        warning: { bg: '#f7b84b', color: '#333' },
        info: { bg: '#299cdb', color: '#fff' }
    };

    const color = colors[type] || colors.info;
    notification.style.backgroundColor = color.bg;
    notification.style.color = color.color;

    document.body.appendChild(notification);

    setTimeout(function() {
        notification.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(function() {
            notification.remove();
        }, 300);
    }, 3000);
}

// Add CSS for animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
