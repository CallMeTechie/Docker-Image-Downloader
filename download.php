<?php
// Download-Handler für Docker Images

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
