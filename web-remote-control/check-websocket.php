<?php
// check-websocket.php - Script pour vérifier si le serveur WebSocket est accessible

header('Content-Type: application/json');

// Fonction pour vérifier si le serveur WebSocket est accessible
function checkWebSocketServer($host, $port, $timeout = 2) {
    $errno = 0;
    $errstr = '';
    
    // Essayer d'ouvrir une connexion TCP
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    
    if (!$fp) {
        return [
            'success' => false,
            'error' => "Erreur de connexion: $errstr ($errno)"
        ];
    }
    
    // Fermer la connexion
    fclose($fp);
    
    return [
        'success' => true,
        'message' => "Le serveur WebSocket est accessible sur $host:$port"
    ];
}

// Récupérer les paramètres
$host = $_GET['host'] ?? 'localhost';
$port = (int)($_GET['port'] ?? 8080);

// Vérifier le serveur
$result = checkWebSocketServer($host, $port);

// Ajouter des informations supplémentaires
$result['host'] = $host;
$result['port'] = $port;
$result['timestamp'] = date('Y-m-d H:i:s');
$result['server_ip'] = $_SERVER['SERVER_ADDR'] ?? 'unknown';
$result['client_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Retourner le résultat
echo json_encode($result, JSON_PRETTY_PRINT);