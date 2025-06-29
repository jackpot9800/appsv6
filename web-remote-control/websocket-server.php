<?php
// websocket-server.php - Serveur WebSocket pour les notifications push et le contrôle à distance
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

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        echo "Serveur WebSocket démarré\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        // Stocker la nouvelle connexion
        $this->clients->attach($conn);
        
        echo "Nouvelle connexion! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        
        if (!$data || !isset($data['type'])) {
            echo "Message invalide reçu\n";
            return;
        }
        
        echo "Message reçu: {$data['type']}\n";
        
        switch ($data['type']) {
            case 'register_device':
                // Enregistrer un appareil
                if (isset($data['device_id'])) {
                    $deviceId = $data['device_id'];
                    $this->deviceClients[$deviceId] = $from;
                    $from->deviceId = $deviceId;
                    $from->clientType = 'device';
                    
                    echo "Appareil enregistré: {$deviceId}\n";
                    
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
                
                echo "Administrateur enregistré: {$from->resourceId}\n";
                
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
                    
                    echo "Statut mis à jour pour l'appareil: {$deviceId}\n";
                    
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
                        echo "Envoi de la commande {$data['command']} à l'appareil {$deviceId}\n";
                        
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
                    
                    echo "Résultat de commande reçu de l'appareil {$deviceId}\n";
                    
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
                $from->send(json_encode([
                    'type' => 'pong',
                    'timestamp' => date('c')
                ]));
                break;
                
            case 'wake_on_lan':
                // Envoyer un paquet Wake-on-LAN à un appareil
                if (isset($data['mac_address']) && isset($from->clientType) && $from->clientType === 'admin') {
                    $macAddress = $data['mac_address'];
                    $broadcastIP = $data['broadcast_ip'] ?? '255.255.255.255';
                    
                    echo "Envoi d'un paquet Wake-on-LAN à {$macAddress}\n";
                    
                    // Envoyer le paquet Wake-on-LAN
                    $result = $this->sendWakeOnLan($macAddress, $broadcastIP);
                    
                    // Informer l'administrateur
                    $from->send(json_encode([
                        'type' => 'wake_on_lan_result',
                        'mac_address' => $macAddress,
                        'success' => $result,
                        'timestamp' => date('c')
                    ]));
                }
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // Détacher la connexion
        $this->clients->detach($conn);
        
        // Si c'était un appareil, le supprimer de la liste
        if (isset($conn->clientType) && $conn->clientType === 'device' && isset($conn->deviceId)) {
            $deviceId = $conn->deviceId;
            unset($this->deviceClients[$deviceId]);
            
            echo "Appareil déconnecté: {$deviceId}\n";
            
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
            echo "Administrateur déconnecté: {$conn->resourceId}\n";
        }
        
        echo "Connexion {$conn->resourceId} fermée\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Une erreur est survenue: {$e->getMessage()}\n";
        $conn->close();
    }
    
    // Notifier tous les administrateurs
    protected function notifyAdmins($message) {
        foreach ($this->adminClients as $client) {
            $client->send(json_encode($message));
        }
    }
    
    // Envoyer un paquet Wake-on-LAN
    protected function sendWakeOnLan($macAddress, $broadcastIP = '255.255.255.255', $port = 9) {
        // Nettoyer l'adresse MAC
        $macAddress = str_replace([':', '-', '.'], '', $macAddress);
        
        // Vérifier que l'adresse MAC est valide
        if (strlen($macAddress) != 12) {
            return false;
        }
        
        // Créer le "magic packet"
        $header = str_repeat(chr(0xff), 6);
        $data = '';
        
        // Répéter l'adresse MAC 16 fois
        for ($i = 0; $i < 16; $i++) {
            for ($j = 0; $j < 12; $j += 2) {
                $data .= chr(hexdec(substr($macAddress, $j, 2)));
            }
        }
        
        // Créer le paquet complet
        $packet = $header . $data;
        
        // Ouvrir un socket
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$socket) {
            return false;
        }
        
        // Configurer le socket pour le broadcast
        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        
        // Envoyer le paquet
        $result = socket_sendto($socket, $packet, strlen($packet), 0, $broadcastIP, $port);
        socket_close($socket);
        
        return $result !== false;
    }
}

// Démarrer le serveur WebSocket
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new DeviceNotificationServer()
        )
    ),
    8080 // Port du serveur WebSocket
);

echo "Serveur WebSocket démarré sur le port 8080\n";
$server->run();
?>