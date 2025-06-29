<?php
// websocket-server-local.php - Serveur WebSocket local simplifié
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
class LocalWebSocketServer implements MessageComponentInterface {
    protected $clients;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        echo "[" . date('Y-m-d H:i:s') . "] Serveur WebSocket local démarré\n";
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
            'message' => 'Bienvenue sur le serveur WebSocket local!',
            'timestamp' => date('c')
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        echo "[" . date('Y-m-d H:i:s') . "] Message reçu de {$from->resourceId}: {$msg}\n";
        
        // Essayer de parser le JSON
        $data = json_decode($msg, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            // Si c'est un ping, répondre avec un pong
            if (isset($data['type']) && $data['type'] === 'ping') {
                $from->send(json_encode([
                    'type' => 'pong',
                    'timestamp' => date('c')
                ]));
                
                echo "[" . date('Y-m-d H:i:s') . "] Pong envoyé à {$from->resourceId}\n";
            } 
            // Si c'est un enregistrement d'appareil
            else if (isset($data['type']) && $data['type'] === 'register_device' && isset($data['device_id'])) {
                $deviceId = $data['device_id'];
                
                echo "[" . date('Y-m-d H:i:s') . "] Appareil enregistré: {$deviceId} ({$from->resourceId})\n";
                
                // Envoyer une confirmation
                $from->send(json_encode([
                    'type' => 'registration_success',
                    'device_id' => $deviceId,
                    'timestamp' => date('c')
                ]));
                
                // Notifier les autres clients
                foreach ($this->clients as $client) {
                    if ($from !== $client) {
                        $client->send(json_encode([
                            'type' => 'device_connected',
                            'device_id' => $deviceId,
                            'timestamp' => date('c')
                        ]));
                    }
                }
            }
            // Si c'est un enregistrement d'admin
            else if (isset($data['type']) && $data['type'] === 'register_admin') {
                echo "[" . date('Y-m-d H:i:s') . "] Administrateur enregistré: {$from->resourceId}\n";
                
                // Envoyer une confirmation
                $from->send(json_encode([
                    'type' => 'registration_success',
                    'admin_id' => $from->resourceId,
                    'timestamp' => date('c')
                ]));
            }
            // Sinon, simplement renvoyer le message à tous les clients
            else {
                foreach ($this->clients as $client) {
                    if ($from !== $client) {
                        $client->send($msg);
                    }
                }
            }
        } else {
            // Si ce n'est pas du JSON valide, envoyer une erreur
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'JSON invalide',
                'timestamp' => date('c')
            ]));
            
            echo "[" . date('Y-m-d H:i:s') . "] JSON invalide reçu de {$from->resourceId}\n";
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
            new LocalWebSocketServer()
        )
    ),
    8080,
    '127.0.0.1' // Écouter uniquement sur localhost
);

echo "[" . date('Y-m-d H:i:s') . "] Serveur WebSocket démarré sur 127.0.0.1:8080\n";
echo "Appuyez sur Ctrl+C pour arrêter le serveur\n";

$server->run();