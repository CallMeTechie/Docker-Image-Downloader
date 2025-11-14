<?php
// Docker Image Downloader - Finale Version mit Architekturauswahl
session_start();
set_time_limit(600);
ini_set('max_execution_time', 600);

// Konfiguration
define('DOWNLOAD_DIR', __DIR__ . '/downloads');
define('LOG_FILE', __DIR__ . '/download.log');

// Download-Verzeichnis erstellen
if (!file_exists(DOWNLOAD_DIR)) {
    mkdir(DOWNLOAD_DIR, 0755, true);
}

// Logging-Funktion
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
    error_log("[$timestamp] $message");
}

// Funktion zum Normalisieren der Docker Config
function normalizeDockerConfig($config) {
    logMessage("=== Config-Normalisierung gestartet ===");
    
    // Hilfsfunktion zum Normalisieren von Port/Volume-Maps
    $normalizeMap = function($map, $fieldName) {
        if (is_array($map) || is_object($map)) {
            $normalized = [];
            foreach ($map as $key => $value) {
                $normalized[$key] = (object)[];
            }
            logMessage("✓ $fieldName normalisiert: " . count($normalized) . " Einträge, alle Values → {}");
            return $normalized;
        }
        return $map;
    };
    
    // WICHTIG: Normalisiere BEIDE Stellen - config UND container_config!
    
    // 1. config.ExposedPorts
    if (isset($config['config']['ExposedPorts'])) {
        $portsContent = json_encode($config['config']['ExposedPorts']);
        logMessage("Original config.ExposedPorts: $portsContent");
        $config['config']['ExposedPorts'] = $normalizeMap($config['config']['ExposedPorts'], 'config.ExposedPorts');
    }
    
    // 2. container_config.ExposedPorts (oft vergessen!)
    if (isset($config['container_config']['ExposedPorts'])) {
        $portsContent = json_encode($config['container_config']['ExposedPorts']);
        logMessage("Original container_config.ExposedPorts: $portsContent");
        $config['container_config']['ExposedPorts'] = $normalizeMap($config['container_config']['ExposedPorts'], 'container_config.ExposedPorts');
    }
    
    // 3. config.Volumes
    if (isset($config['config']['Volumes'])) {
        $config['config']['Volumes'] = $normalizeMap($config['config']['Volumes'], 'config.Volumes');
    }
    
    // 4. container_config.Volumes
    if (isset($config['container_config']['Volumes'])) {
        $config['container_config']['Volumes'] = $normalizeMap($config['container_config']['Volumes'], 'container_config.Volumes');
    }
    
    // 5. Labels in config
    if (isset($config['config']['Labels'])) {
        if (is_array($config['config']['Labels']) && empty($config['config']['Labels'])) {
            $config['config']['Labels'] = (object)[];
            logMessage("✓ config.Labels normalisiert: leeres Array → {}");
        }
    } else {
        $config['config']['Labels'] = (object)[];
    }
    
    // 6. Labels in container_config
    if (isset($config['container_config']['Labels'])) {
        if (is_array($config['container_config']['Labels']) && empty($config['container_config']['Labels'])) {
            $config['container_config']['Labels'] = (object)[];
            logMessage("✓ container_config.Labels normalisiert");
        }
    }
    
    // 7. OnBuild
    if (!isset($config['config']['OnBuild']) || $config['config']['OnBuild'] === null) {
        $config['config']['OnBuild'] = [];
    }
    
    // Debug: Zeige finale Struktur
    if (isset($config['config']['ExposedPorts'])) {
        $portsContent = json_encode($config['config']['ExposedPorts']);
        logMessage("Final config.ExposedPorts: $portsContent");
    }
    if (isset($config['container_config']['ExposedPorts'])) {
        $portsContent = json_encode($config['container_config']['ExposedPorts']);
        logMessage("Final container_config.ExposedPorts: $portsContent");
    }
    
    logMessage("=== Config-Normalisierung abgeschlossen ===");
    
    return $config;
}

// Funktion zum Erstellen eines Docker-kompatiblen TAR-Archivs
function createDockerTar($imageDir, $outputTar, $imageName, $imageTag, $manifestData, $configData) {
    try {
        logMessage("Erstelle Docker TAR-Archiv...");
        
        // Config normalisieren für Docker Load Format
        $configData = normalizeDockerConfig($configData);
        
        // Config-Hash berechnen mit korrekter JSON-Codierung
        $configJson = json_encode($configData, JSON_UNESCAPED_SLASHES);
        $configHash = hash('sha256', $configJson);
        $configFilename = $configHash . '.json';
        
        // Config-Datei mit korrektem Namen erstellen
        file_put_contents($imageDir . '/' . $configFilename, $configJson);
        logMessage("Config-Datei erstellt: $configFilename");
        
        // diffIDs aus der Config lesen (unkomprimierte Layer-Hashes)
        $diffIds = $configData['rootfs']['diff_ids'] ?? [];
        logMessage("Config enthält " . count($diffIds) . " diffIDs");
        
        if (empty($diffIds)) {
            logMessage("FEHLER: Keine diffIDs in Config gefunden!");
            return false;
        }
        
        // Layer entpacken und korrekt benennen - direkt im imageDir!
        $layerFiles = [];
        $compressedLayers = glob($imageDir . '/*.tar.gz');
        
        // WICHTIG: Sortiere alphabetisch - durch Index-Präfix ist das die richtige Reihenfolge!
        sort($compressedLayers);
        
        logMessage("Gefundene komprimierte Layer: " . count($compressedLayers));
        logMessage("Layer-Reihenfolge: " . implode(', ', array_map('basename', $compressedLayers)));
        
        foreach ($compressedLayers as $index => $compressedLayer) {
            if (!isset($diffIds[$index])) {
                logMessage("WARNUNG: Kein diffID für Layer $index");
                continue;
            }
            
            // diffID extrahieren (Format: sha256:xxxxx)
            $diffId = $diffIds[$index];
            $diffIdHash = str_replace('sha256:', '', $diffId);
            
            logMessage("Layer $index: diffID=" . substr($diffIdHash, 0, 12) . "... Datei=" . basename($compressedLayer));
            
            // Layer-Verzeichnis direkt im imageDir erstellen
            $layerDir = $imageDir . '/' . $diffIdHash;
            if (!file_exists($layerDir)) {
                mkdir($layerDir, 0755, true);
            }
            
            // Layer entpacken
            $layerTar = $layerDir . '/layer.tar';
            logMessage("Entpacke Layer " . ($index + 1) . "...");
            
            // Gzip dekomprimieren
            $gzFile = @gzopen($compressedLayer, 'rb');
            if (!$gzFile) {
                logMessage("FEHLER: Konnte $compressedLayer nicht öffnen");
                return false;
            }
            
            $tarFile = fopen($layerTar, 'wb');
            if (!$tarFile) {
                logMessage("FEHLER: Konnte $layerTar nicht erstellen");
                gzclose($gzFile);
                return false;
            }
            
            while (!gzeof($gzFile)) {
                $buffer = gzread($gzFile, 8192);
                if ($buffer === false) break;
                fwrite($tarFile, $buffer);
            }
            
            gzclose($gzFile);
            fclose($tarFile);
            
            // Verifiziere den Hash
            if (file_exists($layerTar)) {
                $actualHash = hash_file('sha256', $layerTar);
                $layerSize = filesize($layerTar);
                
                if ($actualHash === $diffIdHash) {
                    logMessage("✓ Layer $index verifiziert (" . round($layerSize / 1024 / 1024, 2) . " MB)");
                } else {
                    logMessage("⚠ Layer $index Hash-Mismatch (verwende trotzdem)");
                }
                
                $layerFiles[] = $diffIdHash . '/layer.tar';
            }
        }
        
        if (empty($layerFiles)) {
            logMessage("FEHLER: Keine Layer-Dateien erstellt!");
            return false;
        }
        
        // Docker Manifest erstellen
        $dockerManifest = [[
            'Config' => $configFilename,
            'RepoTags' => [$imageName . ':' . $imageTag],
            'Layers' => $layerFiles
        ]];
        
        file_put_contents($imageDir . '/manifest.json', json_encode($dockerManifest));
        logMessage("Manifest erstellt mit " . count($layerFiles) . " Layern");
        
        // TAR-Archiv erstellen
        $oldDir = getcwd();
        chdir($imageDir);
        
        $filesToTar = [$configFilename, 'manifest.json'];
        foreach ($layerFiles as $layerFile) {
            if (file_exists($layerFile)) {
                $filesToTar[] = $layerFile;
                logMessage("  Packe: $layerFile");
            } else {
                logMessage("  FEHLT: $layerFile");
            }
        }
        
        logMessage("Erstelle TAR mit " . count($filesToTar) . " Dateien");
        
        $filesList = implode(' ', array_map('escapeshellarg', $filesToTar));
        $outputTarEscaped = escapeshellarg($outputTar);
        
        exec("tar -cf $outputTarEscaped $filesList 2>&1", $output, $returnCode);
        
        chdir($oldDir);
        
        if ($returnCode === 0) {
            $tarSize = filesize($outputTar);
            logMessage("✓ TAR-Archiv erstellt: " . round($tarSize / 1024 / 1024, 2) . " MB");
            exec("rm -rf " . escapeshellarg($imageDir));
            return true;
        } else {
            logMessage("✗ TAR-Erstellung fehlgeschlagen: " . implode("\n", $output));
            return false;
        }
    } catch (Exception $e) {
        logMessage("EXCEPTION: " . $e->getMessage());
        return false;
    }
}

// Docker Registry API Client
class DockerRegistryClient {
    private $registry = 'https://registry-1.docker.io';
    private $authService = 'https://auth.docker.io';
    private $token = null;
    
    public function authenticate($repository) {
        logMessage("Authentifiziere für Repository: $repository");
        $url = $this->authService . '/token?service=registry.docker.io&scope=repository:' . $repository . ':pull';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $this->token = $data['token'] ?? null;
            if ($this->token) {
                logMessage("✓ Authentifizierung erfolgreich");
                return true;
            }
        }
        
        logMessage("✗ Authentifizierung fehlgeschlagen");
        return false;
    }
    
    public function getManifest($repository, $tag = 'latest') {
        if (!$this->token && !$this->authenticate($repository)) {
            return false;
        }
        
        $url = $this->registry . '/v2/' . $repository . '/manifests/' . $tag;
        logMessage("Lade Manifest von: $url");
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.docker.distribution.manifest.v2+json',
            'Accept: application/vnd.docker.distribution.manifest.list.v2+json',
            'Accept: application/vnd.oci.image.manifest.v1+json',
            'Accept: application/vnd.oci.image.index.v1+json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $manifest = json_decode($response, true);
            if ($manifest) {
                $mediaType = $manifest['mediaType'] ?? 'unknown';
                logMessage("✓ Manifest geladen: $mediaType");
                
                // Prüfe ob es ein Manifest List ist
                if ($mediaType === 'application/vnd.docker.distribution.manifest.list.v2+json' ||
                    $mediaType === 'application/vnd.oci.image.index.v1+json') {
                    
                    logMessage("→ Manifest List erkannt");
                    return ['type' => 'list', 'data' => $manifest];
                }
                
                return ['type' => 'single', 'data' => $manifest];
            }
        }
        
        logMessage("✗ Manifest-Abruf fehlgeschlagen");
        return false;
    }
    
    public function resolveManifestList($repository, $manifestList, $preferredArch, $preferredOS = 'linux') {
        $manifests = $manifestList['manifests'] ?? [];
        logMessage("Manifest List enthält " . count($manifests) . " Architekturen");
        
        // Suche nach bevorzugter Architektur
        $selectedManifest = null;
        foreach ($manifests as $manifest) {
            $platform = $manifest['platform'] ?? [];
            $arch = $platform['architecture'] ?? '';
            $os = $platform['os'] ?? '';
            
            logMessage("  - Verfügbar: $os/$arch");
            
            if ($os === $preferredOS && $arch === $preferredArch) {
                $selectedManifest = $manifest;
                logMessage("✓ Gewählte Architektur: $os/$arch");
                break;
            }
        }
        
        // Fallback: Nimm das erste Manifest
        if (!$selectedManifest && !empty($manifests)) {
            $selectedManifest = $manifests[0];
            $platform = $selectedManifest['platform'] ?? [];
            logMessage("⚠ Fallback auf: " . ($platform['os'] ?? 'unknown') . '/' . ($platform['architecture'] ?? 'unknown'));
        }
        
        if (!$selectedManifest) {
            logMessage("✗ Keine passende Architektur gefunden");
            return false;
        }
        
        // Lade das spezifische Manifest für diese Architektur
        $digest = $selectedManifest['digest'];
        logMessage("Lade spezifisches Manifest: $digest");
        
        $url = $this->registry . '/v2/' . $repository . '/manifests/' . $digest;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.docker.distribution.manifest.v2+json',
            'Accept: application/vnd.oci.image.manifest.v1+json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $specificManifest = json_decode($response, true);
            if ($specificManifest) {
                $layerCount = count($specificManifest['layers'] ?? []);
                $hasConfig = isset($specificManifest['config']['digest']);
                logMessage("✓ Spezifisches Manifest geladen mit $layerCount Layern");
                logMessage("  Config vorhanden: " . ($hasConfig ? 'Ja (' . $specificManifest['config']['digest'] . ')' : 'Nein'));
                
                if (!$hasConfig) {
                    logMessage("✗ FEHLER: Spezifisches Manifest enthält keine Config!");
                    logMessage("  Manifest-Struktur: " . json_encode(array_keys($specificManifest)));
                }
                
                return $specificManifest;
            }
        }
        
        logMessage("✗ Spezifisches Manifest konnte nicht geladen werden (HTTP $httpCode)");
        return false;
    }
    
    public function getConfig($repository, $digest) {
        if (!$this->token && !$this->authenticate($repository)) {
            return false;
        }
        
        $url = $this->registry . '/v2/' . $repository . '/blobs/' . $digest;
        logMessage("Lade Config Blob von: $url");
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // WICHTIG: Folge Redirects!
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            logMessage("✗ CURL Fehler: $curlError");
            return false;
        }
        
        logMessage("Config Blob Response Code: $httpCode");
        
        if ($httpCode === 200) {
            $config = json_decode($response, true);
            if ($config) {
                $diffIdCount = count($config['rootfs']['diff_ids'] ?? []);
                logMessage("✓ Config geladen mit $diffIdCount diffIDs");
                return $config;
            } else {
                logMessage("✗ Config JSON konnte nicht geparst werden");
                logMessage("  Response (erste 200 Zeichen): " . substr($response, 0, 200));
            }
        } else {
            logMessage("✗ Config-Abruf fehlgeschlagen: HTTP $httpCode");
        }
        
        return false;
    }
    
    public function downloadBlob($repository, $digest, $outputPath) {
        if (!$this->token && !$this->authenticate($repository)) {
            return false;
        }
        
        $url = $this->registry . '/v2/' . $repository . '/blobs/' . $digest;
        
        $ch = curl_init($url);
        $fp = @fopen($outputPath, 'w+');
        
        if (!$fp) {
            logMessage("✗ Konnte Ausgabedatei nicht öffnen: $outputPath");
            return false;
        }
        
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token
        ]);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        fclose($fp);
        
        if ($httpCode === 200) {
            return true;
        }
        
        @unlink($outputPath);
        return false;
    }
}

// Image herunterladen
if (isset($_POST['download'])) {
    $imageName = trim($_POST['image_name']);
    $imageTag = trim($_POST['image_tag']) ?: 'latest';
    $preferredArch = $_POST['architecture'] ?? 'amd64';
    
    if (empty($imageName)) {
        $error = "Bitte geben Sie einen Image-Namen ein.";
    } else {
        if (strpos($imageName, '/') === false) {
            $repository = 'library/' . $imageName;
        } else {
            $repository = $imageName;
        }
        
        logMessage("=== Download gestartet: $repository:$imageTag (Architektur: $preferredArch) ===");
        $_SESSION['download_status'] = "Download läuft...";
        $_SESSION['download_progress'] = [
            'active' => true,
            'current' => 0,
            'total' => 0,
            'percent' => 0,
            'message' => 'Vorbereitung...',
            'stage' => 'init'
        ];
        session_write_close(); // Session freigeben damit progress.php zugreifen kann

        $client = new DockerRegistryClient();
        
        if ($client->authenticate($repository)) {
            $manifestResult = $client->getManifest($repository, $imageTag);
            
            if ($manifestResult) {
                $manifest = null;
                
                if ($manifestResult['type'] === 'list') {
                    logMessage("Löse Manifest List auf für Architektur: $preferredArch");
                    $manifest = $client->resolveManifestList($repository, $manifestResult['data'], $preferredArch);
                } else {
                    $manifest = $manifestResult['data'];
                }
                
                if ($manifest && isset($manifest['config']['digest'])) {
                    $safeName = preg_replace('/[^a-z0-9_-]/i', '_', $imageName . '_' . $imageTag . '_' . $preferredArch);
                    $imageDir = DOWNLOAD_DIR . '/' . $safeName . '_tmp_' . time();
                    
                    mkdir($imageDir, 0755, true);
                    
                    $configDigest = $manifest['config']['digest'];
                    logMessage("Lade Config mit Digest: $configDigest");
                    
                    $configData = $client->getConfig($repository, $configDigest);
                    
                    if ($configData) {
                        $layers = $manifest['layers'] ?? [];
                        $totalLayers = count($layers);
                        logMessage("Starte Download von $totalLayers Layern");

                        session_start(); // Session wieder öffnen
                        $_SESSION['download_progress'] = [
                            'active' => true,
                            'current' => 0,
                            'total' => $totalLayers,
                            'percent' => 0,
                            'message' => "Download von $totalLayers Layern wird gestartet...",
                            'stage' => 'download'
                        ];
                        session_write_close(); // Session freigeben

                        $downloadSuccess = true;
                        foreach ($layers as $index => $layer) {
                            $digest = $layer['digest'];
                            // WICHTIG: Index-Präfix um Reihenfolge zu erhalten!
                            $layerFile = $imageDir . '/' . sprintf('%03d', $index) . '_' . str_replace(':', '_', $digest) . '.tar.gz';

                            $currentLayer = $index + 1;
                            $percent = round(($currentLayer / $totalLayers) * 100);

                            logMessage("Lade Layer $currentLayer/$totalLayers");
                            session_start(); // Session wieder öffnen
                            $_SESSION['download_status'] = "Layer $currentLayer/$totalLayers wird heruntergeladen...";
                            $_SESSION['download_progress'] = [
                                'active' => true,
                                'current' => $currentLayer,
                                'total' => $totalLayers,
                                'percent' => $percent,
                                'message' => "Layer $currentLayer von $totalLayers wird heruntergeladen...",
                                'stage' => 'download'
                            ];
                            session_write_close(); // Session freigeben damit progress.php zugreifen kann

                            if (!$client->downloadBlob($repository, $digest, $layerFile)) {
                                $downloadSuccess = false;
                                $error = "Layer $index konnte nicht heruntergeladen werden.";
                                break;
                            }

                            $size = filesize($layerFile);
                            logMessage("✓ Layer $currentLayer heruntergeladen: " . round($size / 1024 / 1024, 2) . " MB");
                        }
                        
                        if ($downloadSuccess) {
                            session_start(); // Session wieder öffnen
                            $_SESSION['download_status'] = "Erstelle TAR-Archiv...";
                            $_SESSION['download_progress'] = [
                                'active' => true,
                                'current' => $totalLayers,
                                'total' => $totalLayers,
                                'percent' => 100,
                                'message' => 'TAR-Archiv wird erstellt...',
                                'stage' => 'tar'
                            ];
                            session_write_close(); // Session freigeben

                            $tarFile = DOWNLOAD_DIR . '/' . $safeName . '.tar';

                            if (createDockerTar($imageDir, $tarFile, $imageName, $imageTag, $manifest, $configData)) {
                                $size = filesize($tarFile);
                                logMessage("=== Download erfolgreich: " . round($size / 1024 / 1024, 2) . " MB ===");
                                $success = "Image erfolgreich heruntergeladen: $safeName.tar (" . round($size / 1024 / 1024, 2) . " MB)";
                                session_start(); // Session öffnen um zu löschen
                                unset($_SESSION['download_status']);
                                unset($_SESSION['download_progress']);
                                session_write_close();
                            } else {
                                $error = "Fehler beim Erstellen des TAR-Archivs.";
                                session_start(); // Session öffnen um zu löschen
                                unset($_SESSION['download_progress']);
                                session_write_close();
                            }
                        }
                    } else {
                        $error = "Config konnte nicht geladen werden. Prüfen Sie das Log.";
                    }
                } else {
                    $error = "Manifest konnte nicht aufgelöst werden oder enthält keine Config.";
                }
            } else {
                $error = "Manifest konnte nicht geladen werden.";
            }
        } else {
            $error = "Authentifizierung fehlgeschlagen.";
        }

        session_start(); // Session öffnen um zu löschen
        unset($_SESSION['download_status']);
        unset($_SESSION['download_progress']);
        session_write_close();
    }
}

// Image löschen
if (isset($_GET['delete'])) {
    $fileName = basename($_GET['delete']);
    $filePath = DOWNLOAD_DIR . '/' . $fileName;
    
    if (file_exists($filePath) && is_file($filePath)) {
        unlink($filePath);
        $success = "Image gelöscht: $fileName";
    }
}

// Log anzeigen
if (isset($_GET['showlog'])) {
    header('Content-Type: text/plain; charset=utf-8');
    if (file_exists(LOG_FILE)) {
        readfile(LOG_FILE);
    } else {
        echo "Keine Log-Datei vorhanden.";
    }
    exit;
}

// Verfügbare Downloads auflisten
function getDownloadedImages() {
    $images = [];
    if (is_dir(DOWNLOAD_DIR)) {
        $files = scandir(DOWNLOAD_DIR);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'tar') {
                $images[] = [
                    'name' => $file,
                    'size' => filesize(DOWNLOAD_DIR . '/' . $file),
                    'date' => filemtime(DOWNLOAD_DIR . '/' . $file)
                ];
            }
        }
        usort($images, function($a, $b) {
            return $b['date'] - $a['date'];
        });
    }
    return $images;
}

$downloadedImages = getDownloadedImages();

// Calculate statistics
$totalImages = count($downloadedImages);
$totalSize = 0;
foreach ($downloadedImages as $image) {
    $totalSize += $image['size'];
}
$totalSizeGB = round($totalSize / 1024 / 1024 / 1024, 2);
$avgSizeMB = $totalImages > 0 ? round($totalSize / $totalImages / 1024 / 1024, 2) : 0;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Docker Image Downloader - Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="layout-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <span class="sidebar-brand-icon">🐳</span>
                <span class="sidebar-brand-text">Docker Downloader</span>
            </div>

            <nav class="sidebar-menu">
                <div class="menu-title">Hauptmenü</div>
                <a href="index.php" class="menu-item active">
                    <svg class="menu-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                    <span>Dashboard</span>
                </a>
                <a href="#download-section" class="menu-item" onclick="document.getElementById('download-section').scrollIntoView({behavior: 'smooth'}); return false;">
                    <svg class="menu-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    <span>Download</span>
                </a>
                <a href="#images-section" class="menu-item" onclick="document.getElementById('images-section').scrollIntoView({behavior: 'smooth'}); return false;">
                    <svg class="menu-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                    </svg>
                    <span>Images</span>
                </a>

                <div class="menu-title">System</div>
                <a href="?showlog" target="_blank" class="menu-item">
                    <svg class="menu-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <span>Logs</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Topbar -->
            <header class="topbar">
                <div class="topbar-start">
                    <button id="sidebar-toggle" class="theme-toggle">
                        <span>☰</span>
                    </button>
                    <h1 class="page-title">Dashboard</h1>
                </div>
                <div class="topbar-end">
                    <button id="theme-toggle" class="theme-toggle">
                        <span id="theme-icon">🌙</span>
                    </button>
                </div>
            </header>

            <!-- Page Content -->
            <main class="page-content">
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <h2 class="dashboard-title">Docker Image Downloader</h2>
                    <p class="dashboard-subtitle">Multi-Architektur-Unterstützung für Synology NAS und andere Plattformen</p>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-info">
                                <div class="stat-card-title">Gesamt Images</div>
                                <div class="stat-card-value" id="total-images"><?php echo $totalImages; ?></div>
                            </div>
                            <div class="stat-card-icon primary">
                                <span>📦</span>
                            </div>
                        </div>
                        <div class="stat-card-trend">
                            Heruntergeladene Docker Images
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-info">
                                <div class="stat-card-title">Gesamtgröße</div>
                                <div class="stat-card-value"><?php echo $totalSizeGB; ?> GB</div>
                            </div>
                            <div class="stat-card-icon success">
                                <span>💾</span>
                            </div>
                        </div>
                        <div class="stat-card-trend">
                            Speicherplatz verwendet
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-info">
                                <div class="stat-card-title">Ø Größe</div>
                                <div class="stat-card-value"><?php echo $avgSizeMB; ?> MB</div>
                            </div>
                            <div class="stat-card-icon info">
                                <span>📊</span>
                            </div>
                        </div>
                        <div class="stat-card-trend">
                            Durchschnittliche Image-Größe
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-info">
                                <div class="stat-card-title">Status</div>
                                <div class="stat-card-value">
                                    <?php echo isset($_SESSION['download_status']) ? '⏳' : '✅'; ?>
                                </div>
                            </div>
                            <div class="stat-card-icon warning">
                                <span>⚡</span>
                            </div>
                        </div>
                        <div class="stat-card-trend">
                            <?php echo isset($_SESSION['download_status']) ? 'Download läuft' : 'Bereit'; ?>
                        </div>
                    </div>
                </div>

                <!-- Progress Card -->
                <div class="progress-card" id="progress-container">
                    <div class="progress-header">
                        <div class="progress-info">
                            <span class="progress-title">Download Fortschritt</span>
                            <span id="progress-stage" class="progress-stage init">Vorbereitung</span>
                        </div>
                        <span id="progress-percentage" class="progress-percentage">0%</span>
                    </div>
                    <div class="progress-wrapper">
                        <div id="progress-bar" class="progress-bar progress-bar-animated" style="width: 0%"></div>
                    </div>
                    <div id="progress-message" class="progress-message">Warte auf Download...</div>
                </div>

                <!-- Alerts -->
                <?php if (isset($_SESSION['download_status'])): ?>
                    <div class="alert alert-info">
                        <span class="alert-icon">⏳</span>
                        <div class="alert-content"><?php echo htmlspecialchars($_SESSION['download_status']); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success">
                        <span class="alert-icon">✅</span>
                        <div class="alert-content"><?php echo htmlspecialchars($success); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <span class="alert-icon">❌</span>
                        <div class="alert-content">
                            <?php echo htmlspecialchars($error); ?>
                            <br><small><a href="?showlog" target="_blank" style="color: inherit; text-decoration: underline;">→ Log anzeigen für Details</a></small>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Download Form Card -->
                <div class="card" id="download-section">
                    <div class="card-header">
                        <h3 class="card-title">Neues Image herunterladen</h3>
                        <div class="card-header-actions">
                            <a href="?showlog" target="_blank" class="btn btn-sm btn-outline-primary">
                                <svg style="width: 16px; height: 16px; margin-right: 4px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                Logs
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="form-group">
                                <label class="form-label" for="image_name">Image Name</label>
                                <input type="text"
                                       id="image_name"
                                       name="image_name"
                                       class="form-control"
                                       placeholder="z.B. nginx, postgres, linuxserver/paperless-ngx"
                                       value="<?php echo isset($_POST['image_name']) ? htmlspecialchars($_POST['image_name']) : ''; ?>"
                                       required>
                                <div class="form-text">
                                    Offizielle Images: nginx, mysql, postgres, redis |
                                    Community: linuxserver/paperless-ngx, portainer/portainer-ce
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="image_tag">Tag / Version</label>
                                <input type="text"
                                       id="image_tag"
                                       name="image_tag"
                                       class="form-control"
                                       placeholder="latest"
                                       value="<?php echo isset($_POST['image_tag']) ? htmlspecialchars($_POST['image_tag']) : 'latest'; ?>">
                                <div class="form-text">
                                    Beispiele: latest, alpine, 1.8.0, stable, v2.0
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="architecture">Architektur</label>
                                <select id="architecture" name="architecture" class="form-select">
                                    <option value="amd64" <?php echo (!isset($_POST['architecture']) || $_POST['architecture'] === 'amd64') ? 'selected' : ''; ?>>
                                        amd64 (Intel/AMD x86_64) - Standard für die meisten Synology Modelle
                                    </option>
                                    <option value="arm64" <?php echo (isset($_POST['architecture']) && $_POST['architecture'] === 'arm64') ? 'selected' : ''; ?>>
                                        arm64 (ARM 64-bit)
                                    </option>
                                    <option value="arm" <?php echo (isset($_POST['architecture']) && $_POST['architecture'] === 'arm') ? 'selected' : ''; ?>>
                                        arm (ARM 32-bit)
                                    </option>
                                </select>
                                <div class="form-text">
                                    Die meisten Synology NAS verwenden amd64. Bei Multi-Arch Images wird automatisch die richtige Version geladen.
                                </div>
                            </div>

                            <button type="submit" name="download" class="btn btn-primary btn-icon">
                                <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                </svg>
                                Image herunterladen
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Images Table Card -->
                <div class="card" id="images-section">
                    <div class="card-header">
                        <h3 class="card-title">Heruntergeladene Images (<?php echo $totalImages; ?>)</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($downloadedImages)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">📦</div>
                                <div class="empty-state-title">Keine Images vorhanden</div>
                                <div class="empty-state-text">Laden Sie Ihr erstes Docker Image herunter, um zu beginnen.</div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Dateiname</th>
                                            <th>Größe</th>
                                            <th>Datum</th>
                                            <th>Status</th>
                                            <th>Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($downloadedImages as $image): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($image['name']); ?></strong>
                                                </td>
                                                <td><?php echo number_format($image['size'] / 1024 / 1024, 2); ?> MB</td>
                                                <td><?php echo date('d.m.Y H:i', $image['date']); ?></td>
                                                <td>
                                                    <span class="badge badge-soft-success">Bereit</span>
                                                </td>
                                                <td>
                                                    <a href="download.php?file=<?php echo urlencode($image['name']); ?>"
                                                       class="btn btn-sm btn-success">
                                                        <svg style="width: 14px; height: 14px; display: inline;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                                        </svg>
                                                    </a>
                                                    <a href="?delete=<?php echo urlencode($image['name']); ?>"
                                                       class="btn btn-sm btn-danger btn-delete"
                                                       data-filename="<?php echo htmlspecialchars($image['name']); ?>">
                                                        <svg style="width: 14px; height: 14px; display: inline;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="assets/script.js"></script>
</body>
</html>
