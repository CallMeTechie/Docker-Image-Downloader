<?php
/**
 * Progress API - Returns current download progress
 */
session_start();
header('Content-Type: application/json');

$progress = isset($_SESSION['download_progress']) ? $_SESSION['download_progress'] : null;

if ($progress) {
    echo json_encode($progress);
} else {
    echo json_encode([
        'active' => false,
        'message' => 'Kein aktiver Download'
    ]);
}
