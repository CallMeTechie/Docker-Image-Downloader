<?php
/**
 * Docker Image Downloader - Configuration Example
 * Login Credentials (ohne Datenbank)
 *
 * INSTALLATIONSANLEITUNG:
 * 1. Kopieren Sie diese Datei zu "config.php"
 * 2. Ändern Sie die Zugangsdaten unten
 * 3. Speichern Sie die Datei
 */

// WICHTIG: Ändern Sie diese Zugangsdaten!
define('AUTH_USERNAME', 'admin');
define('AUTH_PASSWORD', 'docker123'); // Bitte ändern Sie dieses Passwort!

// Optional: Mehrere Benutzer
// Fügen Sie weitere Benutzer im Format 'username' => 'password' hinzu
$AUTH_USERS = [
    'admin' => 'docker123',
    // 'user2' => 'password2',
    // 'synology' => 'mein-sicheres-passwort',
];

/**
 * Prüft ob die Login-Credentials korrekt sind
 */
function checkCredentials($username, $password) {
    global $AUTH_USERS;

    // Prüfe gegen Benutzer-Array
    if (isset($AUTH_USERS[$username]) && $AUTH_USERS[$username] === $password) {
        return true;
    }

    // Fallback auf einzelne Credentials
    if ($username === AUTH_USERNAME && $password === AUTH_PASSWORD) {
        return true;
    }

    return false;
}

/**
 * Prüft ob der Benutzer eingeloggt ist
 */
function isLoggedIn() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

/**
 * Benutzer einloggen
 */
function loginUser($username) {
    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = $username;
    $_SESSION['login_time'] = time();
}

/**
 * Benutzer ausloggen
 */
function logoutUser() {
    $_SESSION['authenticated'] = false;
    unset($_SESSION['username']);
    unset($_SESSION['login_time']);
    session_destroy();
}

/**
 * Holt den aktuellen Benutzernamen
 */
function getCurrentUser() {
    return isset($_SESSION['username']) ? $_SESSION['username'] : null;
}
?>
