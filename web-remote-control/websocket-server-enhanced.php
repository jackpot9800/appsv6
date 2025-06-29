<?php
// websocket-server-enhanced.php - Serveur WebSocket amélioré pour OVH
// Utilise Ratchet pour implémenter un serveur WebSocket

// Assurez-vous d'installer Ratchet via Composer:
// composer require cboden/ratchet

require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Classe de gestion des WebSockets
class DeviceNotificationServer implements MessageComponentInterface {
    protected $clients;
    protected $deviceClients = []; // Clients par device_id
    protected $adminClients = []; // Clients administrateurs
    protected $deviceStatus = []; // Statut des appareils
    protected $logFile;

    public function __construct($logFile = null) {
        $this->clients = new \SplObjectStorage;
        $this->logFile = $logFile ?: __DIR__ . '/websocket.log';
        
        $this->log("Serveur WebSocket démarré");
        echo "Serveur WebSocket démarré\n";
        echo "Logs écrits dans: {$this->logFile}\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        // Stocker la nouvelle connexion
        $this->clients->attach($conn);
        
        // Récupérer l'adresse IP du client
        $address = $conn->remoteAddress;
        
        $this->log("Nouvelle connexion! ({$conn->resourceId}) depuis {$address}");
        echo "Nouvelle connexion! ({$conn->resourceId}) depuis {$address}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        try {
            $data = json_decode($msg, true);
            
            if (!$data || !isset($data['type'])) {
                $this->log("Message invalide reçu de {$from->resourceId}");
                return;
            }
            
            $this->log("Message reçu de {$from->resourceId}: {$data['type']}");
            
            switch ($data['type']) {
                case 'register_device':
                    // Enregistrer un appareil
                    if (isset($data['device_id'])) {
                        $deviceId = $data['device_id'];
                        $this->deviceClients[$deviceId] = $from;
                        $from->deviceId = $deviceId;
                        $from->clientType = 'device';
                        
                        $this->log("Appareil enregistré: {$deviceId} ({$from->resourceId})");
                        
                        // Envoyer une confirmation
                        $from->send(json_encode([
                            'type' => 'registration_success',
                            'device_id' => $deviceId,
                            'timestamp' => date('c')
                        ]));
                        
                        // Notifier les administrateurs
                        $this->notifyAdmins([
                            'type' => 'device_connected',
                            'device_id' => $deviceId,
                            'timestamp' => date('c')
                        ]);
                    }
                    break;
                    
                case 'register_admin':
                    // Enregistrer un administrateur
                    $from->clientType = 'admin';
                    $this->adminClients[$from->resourceId] = $from;
                    
                    $this->log("Administrateur enregistré: {$from->resourceId}");
                    
                    // Envoyer la liste des appareils connectés
                    $connectedDevices = array_keys($this->deviceClients);
                    $from->send(json_encode([
                        'type' => 'connected_devices',
                        'devices' => $connectedDevices,
                        'timestamp' => date('c')
                    ]));
                    break;
                    
                case 'device_status':
                    // Mise à jour du statut d'un appareil
                    if (isset($data['device_id']) && isset($from->clientType) && $from->clientType === 'device') {
                        $deviceId = $data['device_id'];
                        $this->deviceStatus[$deviceId] = $data;
                        
                        $this->log("Statut mis à jour pour l'appareil: {$deviceId}");
                        
                        // Notifier les administrateurs
                        $this->notifyAdmins([
                            'type' => 'device_status_update',
                            'device_id' => $deviceId,
                            'status' => $data,
                            'timestamp' => date('c')
                        ]);
                    }
                    break;
                    
                case 'admin_command':
                    // Commande d'un administrateur vers un appareil
                    if (isset($data['device_id']) && isset($data['command']) && isset($from->clientType) && $from->clientType === 'admin') {
                        $deviceId = $data['device_id'];
                        
                        // Vérifier si l'appareil est connecté
                        if (isset($this->deviceClients[$deviceId])) {
                            $this->log("Envoi de la commande {$data['command']} à l'appareil {$deviceId}");
                            
                            // Envoyer la commande à l'appareil
                            $this->deviceClients[$deviceId]->send(json_encode([
                                'type' => 'command',
                                'command' => $data['command'],
                                'parameters' => $data['parameters'] ?? [],
                                'timestamp' => date('c')
                            ]));
                            
                            // Confirmer à l'administrateur
                            $from->send(json_encode([
                                'type' => 'command_sent',
                                'device_id' => $deviceId,
                                'command' => $data['command'],
                                'timestamp' => date('c')
                            ]));
                        } else {
                            // Appareil non connecté
                            $this->log("Appareil non connecté: {$deviceId}");
                            
                            $from->send(json_encode([
                                'type' => 'command_error',
                                'device_id' => $deviceId,
                                'error' => 'Appareil non connecté',
                                'timestamp' => date('c')
                            ]));
                        }
                    }
                    break;
                    
                case 'command_result':
                    // Résultat d'une commande exécutée par un appareil
                    if (isset($data['device_id']) && isset($data['command']) && isset($data['result']) && isset($from->clientType) && $from->clientType === 'device') {
                        $deviceId = $data['device_id'];
                        
                        $this->log("Résultat de commande reçu de l'appareil {$deviceId}");
                        
                        // Notifier les administrateurs
                        $this->notifyAdmins([
                            'type' => 'command_result',
                            'device_id' => $deviceId,
                            'command' => $data['command'],
                            'result' => $data['result'],
                            'timestamp' => date('c')
                        ]);
                    }
                    break;
                    
                case 'ping':
                    // Ping pour maintenir la connexion active
                    $this->log("Ping reçu de {$from->resourceId}");
                    
                    $from->send(json_encode([
                        'type' => 'pong',
                        'timestamp' => date('c')
                    ]));
                    break;
            }
        } catch (\Exception $e) {
            $this->log("Erreur lors du traitement du message: " . $e->getMessage());
            echo "Erreur lors du traitement du message: " . $e->getMessage() . "\n";
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // Détacher la connexion
        $this->clients->detach($conn);
        
        // Si c'était un appareil, le supprimer de la liste
        if (isset($conn->clientType) && $conn->clientType === 'device' && isset($conn->deviceId)) {
            $deviceId = $conn->deviceId;
            unset($this->deviceClients[$deviceId]);
            
            $this->log("Appareil déconnecté: {$deviceId}");
            
            // Notifier les administrateurs
            $this->notifyAdmins([
                'type' => 'device_disconnected',
                'device_id' => $deviceId,
                'timestamp' => date('c')
            ]);
        }
        
        // Si c'était un administrateur, le supprimer de la liste
        if (isset($conn->clientType) && $conn->clientType === 'admin') {
            unset($this->adminClients[$conn->resourceId]);
            $this->log("Administrateur déconnecté: {$conn->resourceId}");
        }
        
        $this->log("Connexion {$conn->resourceId} fermée");
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $this->log("Erreur: {$e->getMessage()}");
        echo "Erreur: {$e->getMessage()}\n";
        $conn->close();
    }
    
    // Notifier tous les administrateurs
    protected function notifyAdmins($message) {
        foreach ($this->adminClients as $client) {
            $client->send(json_encode($message));
        }
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
$logFile = __DIR__ . '/websocket.log';

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
echo "Démarrage du serveur WebSocket sur {$interface}:{$port}\n";
echo "Logs écrits dans: {$logFile}\n";

// Démarrer le serveur WebSocket
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new DeviceNotificationServer($logFile)
        )
    ),
    $port,
    $interface
);

echo "Serveur WebSocket démarré sur {$interface}:{$port}\n";
echo "Appuyez sur Ctrl+C pour arrêter le serveur\n";

$server->run();