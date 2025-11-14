<?php
/**
 * Login Test & Debug Script
 */
session_start();
require_once __DIR__ . '/config.php';

echo "<h1>Login Debug Test</h1>";
echo "<hr>";

echo "<h2>Session Status:</h2>";
echo "<pre>";
echo "Session gestartet: " . (session_status() === PHP_SESSION_ACTIVE ? 'Ja' : 'Nein') . "\n";
echo "Session ID: " . session_id() . "\n";
echo "Eingeloggt: " . (isLoggedIn() ? 'Ja' : 'Nein') . "\n";
if (isLoggedIn()) {
    echo "Benutzername: " . getCurrentUser() . "\n";
}
echo "</pre>";

echo "<h2>Session Daten:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>Credential Test:</h2>";
echo "<pre>";
$testUser = 'admin';
$testPass = 'docker123';
echo "Test-Login mit: $testUser / $testPass\n";
echo "Ergebnis: " . (checkCredentials($testUser, $testPass) ? 'ERFOLGREICH ✓' : 'FEHLGESCHLAGEN ✗') . "\n";
echo "</pre>";

echo "<h2>Konfiguration:</h2>";
echo "<pre>";
echo "AUTH_USERNAME: " . AUTH_USERNAME . "\n";
echo "AUTH_PASSWORD: " . AUTH_PASSWORD . "\n";
echo "</pre>";

echo "<hr>";
echo "<p><a href='index.php'>← Zurück zum Dashboard</a></p>";
?>
