<?php
// Download-Handler für Docker Images
session_start();
require_once __DIR__ . '/config.php';

// Prüfe Authentifizierung
if (!isLoggedIn()) {
    header('HTTP/1.0 403 Forbidden');
    die('Zugriff verweigert. Bitte melden Sie sich an.');
}

define('DOWNLOAD_DIR', __DIR__ . '/downloads');

if (!isset($_GET['file'])) {
    die('Keine Datei angegeben.');
}

$fileName = basename($_GET['file']);
$filePath = DOWNLOAD_DIR . '/' . $fileName;

if (!file_exists($filePath) || !is_file($filePath)) {
    die('Datei nicht gefunden.');
}

// Content-Type und Headers setzen
header('Content-Type: application/x-tar');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Datei ausgeben
readfile($filePath);
exit;
?>
