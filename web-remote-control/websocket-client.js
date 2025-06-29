// websocket-client.js - Client WebSocket pour le contrôle à distance des appareils Fire TV

/**
 * Classe de gestion de la connexion WebSocket pour les appareils Fire TV
 */
class DeviceWebSocketClient {
    /**
     * Constructeur
     * @param {Object} options - Options de configuration
     * @param {string} options.serverUrl - URL du serveur WebSocket
     * @param {boolean} options.isAdmin - Indique si le client est un administrateur
     * @param {string} options.deviceId - ID de l'appareil (requis si !isAdmin)
     * @param {boolean} options.autoReconnect - Reconnexion automatique en cas de déconnexion
     * @param {number} options.reconnectInterval - Intervalle de reconnexion en ms
     * @param {number} options.pingInterval - Intervalle de ping en ms
     */
    constructor(options = {}) {
        this.serverUrl = options.serverUrl || 'ws://localhost:8080';
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
    
    /**
     * Se connecter au serveur WebSocket
     */
    connect() {
        if (this.socket) {
            this.disconnect();
        }
        
        console.log(`[WebSocket] Connexion au serveur: ${this.serverUrl}`);
        
        try {
            this.socket = new WebSocket(this.serverUrl);
            
            this.socket.onopen = () => {
                console.log('[WebSocket] Connexion établie');
                this.isConnected = true;
                
                // Enregistrer le client
                if (this.isAdmin) {
                    this.sendMessage({
                        type: 'register_admin',
                        timestamp: new Date().toISOString()
                    });
                } else if (this.deviceId) {
                    this.sendMessage({
                        type: 'register_device',
                        device_id: this.deviceId,
                        device_name: `Fire TV ${this.deviceId.substring(0, 8)}`,
                        timestamp: new Date().toISOString()
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
                    console.error('[WebSocket] Erreur de parsing du message:', error);
                }
            };
            
            this.socket.onclose = () => {
                console.log('[WebSocket] Connexion fermée');
                this.isConnected = false;
                
                // Arrêter le ping
                this.stopPing();
                
                // Exécuter les callbacks de déconnexion
                this.onDisconnectCallbacks.forEach(callback => callback());
                
                // Reconnecter automatiquement si activé
                if (this.autoReconnect) {
                    console.log(`[WebSocket] Tentative de reconnexion dans ${this.reconnectInterval / 1000} secondes...`);
                    this.reconnectTimer = setTimeout(this.reconnect, this.reconnectInterval);
                }
            };
            
            this.socket.onerror = (error) => {
                console.error('[WebSocket] Erreur:', error);
            };
        } catch (error) {
            console.error('[WebSocket] Erreur lors de la création de la connexion:', error);
            
            // Reconnecter automatiquement si activé
            if (this.autoReconnect) {
                console.log(`[WebSocket] Tentative de reconnexion dans ${this.reconnectInterval / 1000} secondes...`);
                this.reconnectTimer = setTimeout(this.reconnect, this.reconnectInterval);
            }
        }
    }
    
    /**
     * Se déconnecter du serveur WebSocket
     */
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
    
    /**
     * Se reconnecter au serveur WebSocket
     */
    reconnect() {
        this.connect();
    }
    
    /**
     * Envoyer un message au serveur WebSocket
     * @param {Object} message - Message à envoyer
     * @returns {boolean} - Succès de l'envoi
     */
    sendMessage(message) {
        if (!this.isConnected || !this.socket) {
            console.warn('[WebSocket] Impossible d\'envoyer le message: non connecté');
            return false;
        }
        
        try {
            this.socket.send(JSON.stringify(message));
            return true;
        } catch (error) {
            console.error('[WebSocket] Erreur lors de l\'envoi du message:', error);
            return false;
        }
    }
    
    /**
     * Envoyer une commande à un appareil (admin uniquement)
     * @param {string} deviceId - ID de l'appareil cible
     * @param {string} command - Commande à envoyer
     * @param {Object} parameters - Paramètres de la commande
     * @returns {boolean} - Succès de l'envoi
     */
    sendCommand(deviceId, command, parameters = {}) {
        if (!this.isAdmin) {
            console.warn('[WebSocket] Seuls les administrateurs peuvent envoyer des commandes');
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
    
    /**
     * Envoyer le statut de l'appareil (appareil uniquement)
     * @param {Object} status - Statut de l'appareil
     * @returns {boolean} - Succès de l'envoi
     */
    sendStatus(status) {
        if (this.isAdmin || !this.deviceId) {
            console.warn('[WebSocket] Seuls les appareils peuvent envoyer leur statut');
            return false;
        }
        
        return this.sendMessage({
            type: 'device_status',
            device_id: this.deviceId,
            ...status,
            timestamp: new Date().toISOString()
        });
    }
    
    /**
     * Envoyer un ping pour maintenir la connexion active
     * @returns {boolean} - Succès de l'envoi
     */
    ping() {
        return this.sendMessage({
            type: 'ping',
            timestamp: new Date().toISOString()
        });
    }
    
    /**
     * Démarrer le ping périodique
     */
    startPing() {
        this.stopPing();
        this.pingTimer = setInterval(this.ping, this.pingInterval);
    }
    
    /**
     * Arrêter le ping périodique
     */
    stopPing() {
        if (this.pingTimer) {
            clearInterval(this.pingTimer);
            this.pingTimer = null;
        }
    }
    
    /**
     * Gérer les messages reçus
     * @param {Object} data - Message reçu
     */
    handleMessage(data) {
        console.log('[WebSocket] Message reçu:', data);
        
        switch (data.type) {
            case 'command':
                // Commande reçue (pour les appareils)
                this.onCommandCallbacks.forEach(callback => callback(data.command, data.parameters || {}));
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
                console.log('[WebSocket] Pong reçu du serveur');
                break;
        }
    }
    
    /**
     * Ajouter un callback pour la connexion
     * @param {Function} callback - Fonction à appeler lors de la connexion
     * @returns {DeviceWebSocketClient} - Instance pour chaînage
     */
    onConnect(callback) {
        this.onConnectCallbacks.push(callback);
        return this;
    }
    
    /**
     * Ajouter un callback pour la déconnexion
     * @param {Function} callback - Fonction à appeler lors de la déconnexion
     * @returns {DeviceWebSocketClient} - Instance pour chaînage
     */
    onDisconnect(callback) {
        this.onDisconnectCallbacks.push(callback);
        return this;
    }
    
    /**
     * Ajouter un callback pour les commandes (appareils)
     * @param {Function} callback - Fonction à appeler lors de la réception d'une commande
     * @returns {DeviceWebSocketClient} - Instance pour chaînage
     */
    onCommand(callback) {
        this.onCommandCallbacks.push(callback);
        return this;
    }
    
    /**
     * Ajouter un callback pour les mises à jour de statut (admins)
     * @param {Function} callback - Fonction à appeler lors de la mise à jour du statut d'un appareil
     * @returns {DeviceWebSocketClient} - Instance pour chaînage
     */
    onDeviceStatus(callback) {
        this.onDeviceStatusCallbacks.push(callback);
        return this;
    }
    
    /**
     * Ajouter un callback pour les connexions d'appareils (admins)
     * @param {Function} callback - Fonction à appeler lors de la connexion d'un appareil
     * @returns {DeviceWebSocketClient} - Instance pour chaînage
     */
    onDeviceConnected(callback) {
        this.onDeviceConnectedCallbacks.push(callback);
        return this;
    }
    
    /**
     * Ajouter un callback pour les déconnexions d'appareils (admins)
     * @param {Function} callback - Fonction à appeler lors de la déconnexion d'un appareil
     * @returns {DeviceWebSocketClient} - Instance pour chaînage
     */
    onDeviceDisconnected(callback) {
        this.onDeviceDisconnectedCallbacks.push(callback);
        return this;
    }
}

// Exposer la classe pour une utilisation dans d'autres scripts
window.DeviceWebSocketClient = DeviceWebSocketClient;