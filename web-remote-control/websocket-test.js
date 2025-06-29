// Script JavaScript simple pour tester la connexion WebSocket

/**
 * Classe simple pour tester la connexion WebSocket
 */
class WebSocketTester {
    /**
     * Constructeur
     * @param {string} url - URL du serveur WebSocket
     */
    constructor(url) {
        this.url = url;
        this.socket = null;
        this.isConnected = false;
        this.onMessageCallback = null;
        this.onConnectCallback = null;
        this.onDisconnectCallback = null;
        this.onErrorCallback = null;
    }
    
    /**
     * Se connecter au serveur WebSocket
     */
    connect() {
        console.log(`Tentative de connexion à ${this.url}...`);
        
        try {
            this.socket = new WebSocket(this.url);
            
            this.socket.onopen = () => {
                console.log('Connecté au serveur WebSocket');
                this.isConnected = true;
                
                if (this.onConnectCallback) {
                    this.onConnectCallback();
                }
            };
            
            this.socket.onmessage = (event) => {
                console.log('Message reçu:', event.data);
                
                if (this.onMessageCallback) {
                    this.onMessageCallback(event.data);
                }
            };
            
            this.socket.onclose = () => {
                console.log('Déconnecté du serveur WebSocket');
                this.isConnected = false;
                
                if (this.onDisconnectCallback) {
                    this.onDisconnectCallback();
                }
            };
            
            this.socket.onerror = (error) => {
                console.error('Erreur WebSocket:', error);
                
                if (this.onErrorCallback) {
                    this.onErrorCallback(error);
                }
            };
        } catch (error) {
            console.error('Erreur lors de la création de la connexion WebSocket:', error);
            
            if (this.onErrorCallback) {
                this.onErrorCallback(error);
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
    }
    
    /**
     * Envoyer un message au serveur WebSocket
     * @param {string|object} message - Message à envoyer
     * @returns {boolean} - Succès de l'envoi
     */
    send(message) {
        if (!this.isConnected || !this.socket) {
            console.warn('Impossible d\'envoyer le message: non connecté');
            return false;
        }
        
        try {
            // Si le message est un objet, le convertir en JSON
            if (typeof message === 'object') {
                message = JSON.stringify(message);
            }
            
            this.socket.send(message);
            console.log('Message envoyé:', message);
            return true;
        } catch (error) {
            console.error('Erreur lors de l\'envoi du message:', error);
            return false;
        }
    }
    
    /**
     * Définir le callback pour les messages reçus
     * @param {Function} callback - Fonction à appeler lors de la réception d'un message
     */
    onMessage(callback) {
        this.onMessageCallback = callback;
    }
    
    /**
     * Définir le callback pour la connexion
     * @param {Function} callback - Fonction à appeler lors de la connexion
     */
    onConnect(callback) {
        this.onConnectCallback = callback;
    }
    
    /**
     * Définir le callback pour la déconnexion
     * @param {Function} callback - Fonction à appeler lors de la déconnexion
     */
    onDisconnect(callback) {
        this.onDisconnectCallback = callback;
    }
    
    /**
     * Définir le callback pour les erreurs
     * @param {Function} callback - Fonction à appeler lors d'une erreur
     */
    onError(callback) {
        this.onErrorCallback = callback;
    }
}

// Exposer la classe pour une utilisation dans d'autres scripts
window.WebSocketTester = WebSocketTester;