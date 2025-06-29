// websocket-client.js - Client WebSocket pour le contrôle à distance des appareils Fire TV

// Configuration
const WS_SERVER_URL = 'ws://localhost:8080'; // Remplacer par l'URL de votre serveur WebSocket

// Classe de gestion de la connexion WebSocket
class DeviceWebSocketClient {
    constructor(options = {}) {
        this.serverUrl = options.serverUrl || WS_SERVER_URL;
        this.isAdmin = options.isAdmin || false;
        this.deviceId = options.deviceId || null;
        this.autoReconnect = options.autoReconnect !== false;
        this.reconnectInterval = options.reconnectInterval || 5000;
        this.pingInterval = options.pingInterval || 30000;
        
        this.socket = null;
        this.isConnected = false;
        this.reconnectTimer = null;
        this.pingTimer = null;
        
        this.onConnectCallbacks = [];
        this.onDisconnectCallbacks = [];
        this.onCommandCallbacks = [];
        this.onDeviceStatusCallbacks = [];
        this.onDeviceConnectedCallbacks = [];
        this.onDeviceDisconnectedCallbacks = [];
        
        // Lier les méthodes au contexte actuel
        this.connect = this.connect.bind(this);
        this.disconnect = this.disconnect.bind(this);
        this.reconnect = this.reconnect.bind(this);
        this.sendMessage = this.sendMessage.bind(this);
        this.sendCommand = this.sendCommand.bind(this);
        this.sendStatus = this.sendStatus.bind(this);
        this.ping = this.ping.bind(this);
    }
    
    // Se connecter au serveur WebSocket
    connect() {
        if (this.socket) {
            this.disconnect();
        }
        
        console.log(`Connexion au serveur WebSocket: ${this.serverUrl}`);
        
        try {
            this.socket = new WebSocket(this.serverUrl);
            
            this.socket.onopen = () => {
                console.log('Connexion WebSocket établie');
                this.isConnected = true;
                
                // Enregistrer le client
                if (this.isAdmin) {
                    this.sendMessage({
                        type: 'register_admin'
                    });
                } else if (this.deviceId) {
                    this.sendMessage({
                        type: 'register_device',
                        device_id: this.deviceId
                    });
                }
                
                // Démarrer le ping périodique
                this.startPing();
                
                // Exécuter les callbacks de connexion
                this.onConnectCallbacks.forEach(callback => callback());
            };
            
            this.socket.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    this.handleMessage(data);
                } catch (error) {
                    console.error('Erreur de parsing du message WebSocket:', error);
                }
            };
            
            this.socket.onclose = () => {
                console.log('Connexion WebSocket fermée');
                this.isConnected = false;
                
                // Arrêter le ping
                this.stopPing();
                
                // Exécuter les callbacks de déconnexion
                this.onDisconnectCallbacks.forEach(callback => callback());
                
                // Reconnecter automatiquement si activé
                if (this.autoReconnect) {
                    console.log(`Tentative de reconnexion dans ${this.reconnectInterval / 1000} secondes...`);
                    this.reconnectTimer = setTimeout(this.reconnect, this.reconnectInterval);
                }
            };
            
            this.socket.onerror = (error) => {
                console.error('Erreur WebSocket:', error);
            };
        } catch (error) {
            console.error('Erreur lors de la création de la connexion WebSocket:', error);
            
            // Reconnecter automatiquement si activé
            if (this.autoReconnect) {
                console.log(`Tentative de reconnexion dans ${this.reconnectInterval / 1000} secondes...`);
                this.reconnectTimer = setTimeout(this.reconnect, this.reconnectInterval);
            }
        }
    }
    
    // Se déconnecter du serveur WebSocket
    disconnect() {
        if (this.socket) {
            this.socket.close();
            this.socket = null;
        }
        
        this.isConnected = false;
        
        // Arrêter le ping
        this.stopPing();
        
        // Arrêter la reconnexion automatique
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
    }
    
    // Se reconnecter au serveur WebSocket
    reconnect() {
        this.connect();
    }
    
    // Envoyer un message au serveur WebSocket
    sendMessage(message) {
        if (!this.isConnected) {
            console.warn('Impossible d\'envoyer le message: non connecté');
            return false;
        }
        
        try {
            this.socket.send(JSON.stringify(message));
            return true;
        } catch (error) {
            console.error('Erreur lors de l\'envoi du message:', error);
            return false;
        }
    }
    
    // Envoyer une commande à un appareil (admin uniquement)
    sendCommand(deviceId, command, parameters = {}) {
        if (!this.isAdmin) {
            console.warn('Seuls les administrateurs peuvent envoyer des commandes');
            return false;
        }
        
        return this.sendMessage({
            type: 'admin_command',
            device_id: deviceId,
            command: command,
            parameters: parameters,
            timestamp: new Date().toISOString()
        });
    }
    
    // Envoyer le statut de l'appareil (appareil uniquement)
    sendStatus(status) {
        if (this.isAdmin || !this.deviceId) {
            console.warn('Seuls les appareils peuvent envoyer leur statut');
            return false;
        }
        
        return this.sendMessage({
            type: 'device_status',
            device_id: this.deviceId,
            ...status,
            timestamp: new Date().toISOString()
        });
    }
    
    // Envoyer un ping pour maintenir la connexion active
    ping() {
        return this.sendMessage({
            type: 'ping',
            timestamp: new Date().toISOString()
        });
    }
    
    // Démarrer le ping périodique
    startPing() {
        this.stopPing();
        this.pingTimer = setInterval(this.ping, this.pingInterval);
    }
    
    // Arrêter le ping périodique
    stopPing() {
        if (this.pingTimer) {
            clearInterval(this.pingTimer);
            this.pingTimer = null;
        }
    }
    
    // Gérer les messages reçus
    handleMessage(data) {
        console.log('Message WebSocket reçu:', data);
        
        switch (data.type) {
            case 'command':
                // Commande reçue (pour les appareils)
                this.onCommandCallbacks.forEach(callback => callback(data.command, data.parameters));
                break;
                
            case 'device_status_update':
                // Mise à jour du statut d'un appareil (pour les admins)
                this.onDeviceStatusCallbacks.forEach(callback => callback(data.device_id, data.status));
                break;
                
            case 'device_connected':
                // Un appareil s'est connecté (pour les admins)
                this.onDeviceConnectedCallbacks.forEach(callback => callback(data.device_id));
                break;
                
            case 'device_disconnected':
                // Un appareil s'est déconnecté (pour les admins)
                this.onDeviceDisconnectedCallbacks.forEach(callback => callback(data.device_id));
                break;
                
            case 'pong':
                // Réponse à un ping
                console.log('Pong reçu du serveur');
                break;
        }
    }
    
    // Ajouter un callback pour la connexion
    onConnect(callback) {
        this.onConnectCallbacks.push(callback);
        return this;
    }
    
    // Ajouter un callback pour la déconnexion
    onDisconnect(callback) {
        this.onDisconnectCallbacks.push(callback);
        return this;
    }
    
    // Ajouter un callback pour les commandes (appareils)
    onCommand(callback) {
        this.onCommandCallbacks.push(callback);
        return this;
    }
    
    // Ajouter un callback pour les mises à jour de statut (admins)
    onDeviceStatus(callback) {
        this.onDeviceStatusCallbacks.push(callback);
        return this;
    }
    
    // Ajouter un callback pour les connexions d'appareils (admins)
    onDeviceConnected(callback) {
        this.onDeviceConnectedCallbacks.push(callback);
        return this;
    }
    
    // Ajouter un callback pour les déconnexions d'appareils (admins)
    onDeviceDisconnected(callback) {
        this.onDeviceDisconnectedCallbacks.push(callback);
        return this;
    }
    
    // Envoyer un paquet Wake-on-LAN (admin uniquement)
    sendWakeOnLan(macAddress, broadcastIP = '255.255.255.255') {
        if (!this.isAdmin) {
            console.warn('Seuls les administrateurs peuvent envoyer des paquets Wake-on-LAN');
            return false;
        }
        
        return this.sendMessage({
            type: 'wake_on_lan',
            mac_address: macAddress,
            broadcast_ip: broadcastIP,
            timestamp: new Date().toISOString()
        });
    }
}

// Exporter la classe pour une utilisation dans d'autres scripts
window.DeviceWebSocketClient = DeviceWebSocketClient;