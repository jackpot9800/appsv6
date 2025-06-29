<?php
// websocket-status.php - Script pour vérifier le statut du serveur WebSocket

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Vérifier si le serveur WebSocket est en cours d'exécution
function checkWebSocketServer($host = 'localhost', $port = 8080) {
    $connection = @fsockopen($host, $port, $errno, $errstr, 1);
    
    if (is_resource($connection)) {
        fclose($connection);
        return true;
    }
    
    return false;
}

// Récupérer le PID du serveur WebSocket
function getWebSocketPID() {
    $output = [];
    exec("ps aux | grep 'php.*websocket-server' | grep -v grep", $output);
    
    if (empty($output)) {
        return null;
    }
    
    // Extraire le PID (deuxième colonne)
    $parts = preg_split('/\s+/', trim($output[0]));
    return $parts[1] ?? null;
}

// Récupérer les dernières lignes du fichier de log
function getWebSocketLogs($lines = 20) {
    $logFile = __DIR__ . '/websocket.log';
    
    if (!file_exists($logFile)) {
        return ['Le fichier de log n\'existe pas'];
    }
    
    $logs = [];
    $file = new SplFileObject($logFile, 'r');
    $file->seek(PHP_INT_MAX); // Aller à la fin du fichier
    $totalLines = $file->key(); // Nombre total de lignes
    
    $startLine = max(0, $totalLines - $lines);
    
    $file->seek($startLine);
    
    while (!$file->eof()) {
        $logs[] = $file->fgets();
    }
    
    return $logs;
}

// Démarrer le serveur WebSocket
function startWebSocketServer() {
    $output = [];
    $command = "php " . __DIR__ . "/start-websocket-server-enhanced.php --daemon";
    exec($command, $output, $returnVar);
    
    return $returnVar === 0;
}

// Arrêter le serveur WebSocket
function stopWebSocketServer() {
    $pid = getWebSocketPID();
    
    if (!$pid) {
        return false;
    }
    
    exec("kill {$pid}", $output, $returnVar);
    return $returnVar === 0;
}

// Traiter les actions
$action = $_GET['action'] ?? '';
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'start') {
        if (startWebSocketServer()) {
            $message = 'Serveur WebSocket démarré avec succès';
            $messageType = 'success';
        } else {
            $message = 'Erreur lors du démarrage du serveur WebSocket';
            $messageType = 'error';
        }
    } elseif ($action === 'stop') {
        if (stopWebSocketServer()) {
            $message = 'Serveur WebSocket arrêté avec succès';
            $messageType = 'success';
        } else {
            $message = 'Erreur lors de l\'arrêt du serveur WebSocket';
            $messageType = 'error';
        }
    }
}

// Vérifier le statut du serveur
$isRunning = checkWebSocketServer();
$pid = getWebSocketPID();
$logs = getWebSocketLogs();

// Récupérer l'adresse IP publique
$publicIP = file_get_contents('https://api.ipify.org');
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statut du serveur WebSocket</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <meta http-equiv="refresh" content="30">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">
                <i class="fas fa-server text-blue-600"></i>
                Statut du serveur WebSocket
            </h1>
            
            <div class="flex space-x-4">
                <a href="dashboard.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-tachometer-alt mr-2"></i>
                    Tableau de bord
                </a>
                
                <a href="websocket-debug.html" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-bug mr-2"></i>
                    Diagnostic WebSocket
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                <div class="flex items-center">
                    <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                    <p><?= htmlspecialchars($message) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statut du serveur -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <div class="bg-<?= $isRunning ? 'green' : 'red' ?>-100 p-3 rounded-full">
                        <i class="fas fa-<?= $isRunning ? 'check' : 'times' ?> text-<?= $isRunning ? 'green' : 'red' ?>-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h2 class="text-xl font-bold text-gray-800">
                            Serveur WebSocket <?= $isRunning ? 'en cours d\'exécution' : 'arrêté' ?>
                        </h2>
                        <?php if ($pid): ?>
                            <p class="text-gray-600">PID: <?= $pid ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div>
                    <?php if ($isRunning): ?>
                        <form action="" method="post" class="inline">
                            <input type="hidden" name="action" value="stop">
                            <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-stop mr-2"></i>
                                Arrêter le serveur
                            </button>
                        </form>
                    <?php else: ?>
                        <form action="" method="post" class="inline">
                            <input type="hidden" name="action" value="start">
                            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-play mr-2"></i>
                                Démarrer le serveur
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Informations de connexion -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-info-circle text-blue-600"></i>
                Informations de connexion
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="font-medium text-gray-700 mb-2">Adresses WebSocket</h3>
                    <div class="space-y-2">
                        <div class="bg-gray-100 p-3 rounded-lg">
                            <p class="text-sm font-mono">ws://localhost:8080</p>
                            <p class="text-xs text-gray-500">Pour les connexions locales</p>
                        </div>
                        
                        <div class="bg-gray-100 p-3 rounded-lg">
                            <p class="text-sm font-mono">ws://<?= $publicIP ?>:8080</p>
                            <p class="text-xs text-gray-500">Pour les connexions externes (si le port est ouvert)</p>
                        </div>
                        
                        <div class="bg-gray-100 p-3 rounded-lg">
                            <p class="text-sm font-mono">ws://affichagedynamique.ca:8080</p>
                            <p class="text-xs text-gray-500">Pour les connexions via le nom de domaine (si configuré)</p>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-medium text-gray-700 mb-2">Configuration requise</h3>
                    <ul class="list-disc pl-6 space-y-2 text-sm text-gray-600">
                        <li>Port 8080 ouvert dans le pare-feu</li>
                        <li>Redirection de port configurée sur le routeur</li>
                        <li>Extension PHP sockets installée</li>
                        <li>Ratchet installé via Composer</li>
                        <li>Permissions d'exécution sur les scripts PHP</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Logs du serveur -->
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-file-alt text-blue-600"></i>
                Logs du serveur
            </h2>
            
            <div class="bg-gray-100 p-4 rounded-lg">
                <pre class="text-xs font-mono h-64 overflow-y-auto"><?= implode('', array_map('htmlspecialchars', $logs)) ?></pre>
            </div>
            
            <div class="mt-4 text-right">
                <a href="?action=refresh" class="text-blue-500 hover:text-blue-700">
                    <i class="fas fa-sync-alt mr-1"></i>
                    Actualiser les logs
                </a>
            </div>
        </div>
    </div>
</body>
</html>