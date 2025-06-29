<?php
// websocket-server-simple.php - Version simplifiée du serveur WebSocket
// Utilise Ratchet pour implémenter un serveur WebSocket

// Assurez-vous d'installer Ratchet via Composer:
// composer require cboden/ratchet

require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

// Classe de gestion des WebSockets simplifiée
class SimpleWebSocketServer implements MessageComponentInterface {
    protected $clients;
    protected $logFile;

    public function __construct($logFile = null) {
        $this->clients = new \SplObjectStorage;
        $this->logFile = $logFile ?: __DIR__ . '/websocket-simple.log';
        
        $this->log("Serveur WebSocket démarré");
        echo "[" . date('Y-m-d H:i:s') . "] Serveur WebSocket démarré\n";
        echo "Logs écrits dans: {$this->logFile}\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        // Stocker la nouvelle connexion
        $this->clients->attach($conn);
        
        // Récupérer l'adresse IP du client
        $address = $conn->remoteAddress;
        
        $this->log("Nouvelle connexion! ({$conn->resourceId}) depuis {$address}");
        echo "[" . date('Y-m-d H:i:s') . "] Nouvelle connexion! ({$conn->resourceId}) depuis {$address}\n";
        
        // Envoyer un message de bienvenue
        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => 'Bienvenue sur le serveur WebSocket!',
            'timestamp' => date('c')
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        try {
            $this->log("Message reçu de {$from->resourceId}: {$msg}");
            echo "[" . date('Y-m-d H:i:s') . "] Message reçu de {$from->resourceId}\n";
            
            // Essayer de parser le JSON
            $data = json_decode($msg, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                // Si c'est un ping, répondre avec un pong
                if (isset($data['type']) && $data['type'] === 'ping') {
                    $from->send(json_encode([
                        'type' => 'pong',
                        'timestamp' => date('c')
                    ]));
                    
                    $this->log("Pong envoyé à {$from->resourceId}");
                } else {
                    // Sinon, simplement renvoyer le message à tous les clients
                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            $client->send($msg);
                        }
                    }
                }
            } else {
                // Si ce n'est pas du JSON valide, simplement renvoyer le message tel quel
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'JSON invalide',
                    'timestamp' => date('c')
                ]));
                
                $this->log("JSON invalide reçu de {$from->resourceId}");
            }
        } catch (\Exception $e) {
            $this->log("Erreur lors du traitement du message: " . $e->getMessage());
            echo "[" . date('Y-m-d H:i:s') . "] Erreur: " . $e->getMessage() . "\n";
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // Détacher la connexion
        $this->clients->detach($conn);
        
        $this->log("Connexion {$conn->resourceId} fermée");
        echo "[" . date('Y-m-d H:i:s') . "] Connexion {$conn->resourceId} fermée\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $this->log("Erreur: {$e->getMessage()}");
        echo "[" . date('Y-m-d H:i:s') . "] Erreur: {$e->getMessage()}\n";
        $conn->close();
    }
    
    // Écrire dans le fichier de log
    protected function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
        
        // Écrire dans le fichier de log
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
}

// Récupérer les arguments de la ligne de commande
$port = 8080; // Port par défaut
$interface = '0.0.0.0'; // Écouter sur toutes les interfaces par défaut
$logFile = __DIR__ . '/websocket-simple.log';

// Traiter les arguments
foreach ($argv as $arg) {
    if (strpos($arg, '--port=') === 0) {
        $port = (int)substr($arg, 7);
    } elseif (strpos($arg, '--interface=') === 0) {
        $interface = substr($arg, 12);
    } elseif (strpos($arg, '--log=') === 0) {
        $logFile = substr($arg, 6);
    }
}

// Afficher les informations de démarrage
echo "[" . date('Y-m-d H:i:s') . "] Démarrage du serveur WebSocket sur {$interface}:{$port}\n";
echo "Logs écrits dans: {$logFile}\n";

// Démarrer le serveur WebSocket
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new SimpleWebSocketServer($logFile)
        )
    ),
    $port,
    $interface
);

echo "[" . date('Y-m-d H:i:s') . "] Serveur WebSocket démarré sur {$interface}:{$port}\n";
echo "Appuyez sur Ctrl+C pour arrêter le serveur\n";

$server->run();