<?php
// websocket-server-simple.php - Version ultra simplifiée du serveur WebSocket
// Utilise Ratchet pour implémenter un serveur WebSocket

// Assurez-vous d'installer Ratchet via Composer:
// composer require cboden/ratchet

require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

// Classe de gestion des WebSockets ultra simplifiée
class SimpleWebSocketServer implements MessageComponentInterface {
    protected $clients;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        echo "[" . date('Y-m-d H:i:s') . "] Serveur WebSocket démarré\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        // Stocker la nouvelle connexion
        $this->clients->attach($conn);
        
        // Récupérer l'adresse IP du client
        $address = $conn->remoteAddress;
        
        echo "[" . date('Y-m-d H:i:s') . "] Nouvelle connexion! ({$conn->resourceId}) depuis {$address}\n";
        
        // Envoyer un message de bienvenue
        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => 'Bienvenue sur le serveur WebSocket!',
            'timestamp' => date('c')
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        echo "[" . date('Y-m-d H:i:s') . "] Message reçu de {$from->resourceId}: {$msg}\n";
        
        // Simplement renvoyer le message à tous les clients
        foreach ($this->clients as $client) {
            $client->send($msg);
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // Détacher la connexion
        $this->clients->detach($conn);
        
        echo "[" . date('Y-m-d H:i:s') . "] Connexion {$conn->resourceId} fermée\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Erreur: {$e->getMessage()}\n";
        $conn->close();
    }
}

// Démarrer le serveur WebSocket sur localhost:8080
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new SimpleWebSocketServer()
        )
    ),
    8080,
    '0.0.0.0' // Écouter sur toutes les interfaces
);

echo "[" . date('Y-m-d H:i:s') . "] Serveur WebSocket démarré sur 0.0.0.0:8080\n";
echo "Appuyez sur Ctrl+C pour arrêter le serveur\n";

$server->run();