import { Platform } from 'react-native';
import { apiService } from './ApiService';
import { statusService, RemoteCommand } from './StatusService';
import AsyncStorage from '@react-native-async-storage/async-storage';

// Interface pour les options du client WebSocket
interface WebSocketOptions {
    serverUrl: string;
    deviceId: string;
    deviceName: string;
    autoReconnect?: boolean;
    reconnectInterval?: number;
    pingInterval?: number;
}

// Interface pour les messages WebSocket
interface WebSocketMessage {
    type: string;
    [key: string]: any;
}

// Classe de gestion de la connexion WebSocket
export class WebSocketService {
    private serverUrl: string;
    private deviceId: string;
    private deviceName: string;
    private autoReconnect: boolean;
    private reconnectInterval: number;
    private pingInterval: number;
    
    private socket: WebSocket | null = null;
    private isConnected: boolean = false;
    private reconnectTimer: NodeJS.Timeout | null = null;
    private pingTimer: NodeJS.Timeout | null = null;
    private connectionAttempts: number = 0;
    private maxConnectionAttempts: number = 5;
    
    private onConnectCallbacks: (() => void)[] = [];
    private onDisconnectCallbacks: (() => void)[] = [];
    private onCommandCallbacks: ((command: string, parameters: any) => void)[] = [];
    
    constructor(options: WebSocketOptions) {
        this.serverUrl = options.serverUrl;
        this.deviceId = options.deviceId;
        this.deviceName = options.deviceName;
        this.autoReconnect = options.autoReconnect !== false;
        this.reconnectInterval = options.reconnectInterval || 5000;
        this.pingInterval = options.pingInterval || 30000;
        
        // Lier les méthodes au contexte actuel
        this.connect = this.connect.bind(this);
        this.disconnect = this.disconnect.bind(this);
        this.reconnect = this.reconnect.bind(this);
        this.sendMessage = this.sendMessage.bind(this);
        this.sendStatus = this.sendStatus.bind(this);
        this.ping = this.ping.bind(this);
        this.handleCommand = this.handleCommand.bind(this);
    }
    
    // Se connecter au serveur WebSocket
    public connect(): void {
        if (this.socket) {
            this.disconnect();
        }
        
        console.log(`[WebSocket] Connexion au serveur: ${this.serverUrl}`);
        
        try {
            // Vérifier si l'URL est valide
            if (!this.serverUrl.startsWith('ws://') && !this.serverUrl.startsWith('wss://')) {
                console.error('[WebSocket] URL invalide, doit commencer par ws:// ou wss://');
                this.notifyDisconnect();
                return;
            }
            
            this.socket = new WebSocket(this.serverUrl);
            
            this.socket.onopen = () => {
                console.log('[WebSocket] Connexion établie');
                this.isConnected = true;
                this.connectionAttempts = 0;
                
                // Enregistrer l'appareil
                this.sendMessage({
                    type: 'register_device',
                    device_id: this.deviceId,
                    device_name: this.deviceName,
                    timestamp: new Date().toISOString()
                });
                
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
            
            this.socket.onclose = (event) => {
                console.log('[WebSocket] Connexion fermée', event.code, event.reason);
                this.isConnected = false;
                
                // Arrêter le ping
                this.stopPing();
                
                // Notifier de la déconnexion
                this.notifyDisconnect();
                
                // Reconnecter automatiquement si activé
                if (this.autoReconnect && this.connectionAttempts < this.maxConnectionAttempts) {
                    this.connectionAttempts++;
                    console.log(`[WebSocket] Tentative de reconnexion ${this.connectionAttempts}/${this.maxConnectionAttempts} dans ${this.reconnectInterval / 1000} secondes...`);
                    this.reconnectTimer = setTimeout(this.reconnect, this.reconnectInterval);
                } else if (this.connectionAttempts >= this.maxConnectionAttempts) {
                    console.log(`[WebSocket] Nombre maximum de tentatives atteint (${this.maxConnectionAttempts}), abandon des tentatives de reconnexion`);
                }
            };
            
            this.socket.onerror = (error) => {
                console.error('[WebSocket] Erreur:', error);
            };
        } catch (error) {
            console.error('[WebSocket] Erreur lors de la création de la connexion:', error);
            
            // Notifier de la déconnexion
            this.notifyDisconnect();
            
            // Reconnecter automatiquement si activé
            if (this.autoReconnect && this.connectionAttempts < this.maxConnectionAttempts) {
                this.connectionAttempts++;
                console.log(`[WebSocket] Tentative de reconnexion ${this.connectionAttempts}/${this.maxConnectionAttempts} dans ${this.reconnectInterval / 1000} secondes...`);
                this.reconnectTimer = setTimeout(this.reconnect, this.reconnectInterval);
            }
        }
    }
    
    // Notifier de la déconnexion
    private notifyDisconnect(): void {
        // Exécuter les callbacks de déconnexion
        this.onDisconnectCallbacks.forEach(callback => callback());
    }
    
    // Se déconnecter du serveur WebSocket
    public disconnect(): void {
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
        
        // Réinitialiser le compteur de tentatives
        this.connectionAttempts = 0;
    }
    
    // Se reconnecter au serveur WebSocket
    private reconnect(): void {
        this.connect();
    }
    
    // Envoyer un message au serveur WebSocket
    private sendMessage(message: WebSocketMessage): boolean {
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
    
    // Envoyer le statut de l'appareil
    public sendStatus(status: any): boolean {
        return this.sendMessage({
            type: 'device_status',
            device_id: this.deviceId,
            ...status,
            timestamp: new Date().toISOString()
        });
    }
    
    // Envoyer un ping pour maintenir la connexion active
    private ping(): void {
        this.sendMessage({
            type: 'ping',
            device_id: this.deviceId,
            timestamp: new Date().toISOString()
        });
    }
    
    // Démarrer le ping périodique
    private startPing(): void {
        this.stopPing();
        this.pingTimer = setInterval(this.ping, this.pingInterval);
    }
    
    // Arrêter le ping périodique
    private stopPing(): void {
        if (this.pingTimer) {
            clearInterval(this.pingTimer);
            this.pingTimer = null;
        }
    }
    
    // Gérer les messages reçus
    private handleMessage(data: WebSocketMessage): void {
        console.log('[WebSocket] Message reçu:', data);
        
        switch (data.type) {
            case 'registration_success':
                console.log('[WebSocket] Enregistrement réussi');
                break;
                
            case 'command':
                console.log(`[WebSocket] Commande reçue: ${data.command}`);
                this.handleCommand(data.command, data.parameters || {});
                break;
                
            case 'pong':
                console.log('[WebSocket] Pong reçu');
                break;
                
            case 'welcome':
                console.log('[WebSocket] Message de bienvenue reçu');
                break;
        }
    }
    
    // Gérer une commande reçue
    private handleCommand(command: string, parameters: any): void {
        console.log(`[WebSocket] Exécution de la commande: ${command}`, parameters);
        
        // Exécuter les callbacks de commande
        this.onCommandCallbacks.forEach(callback => callback(command, parameters));
        
        // Créer une commande pour le StatusService
        const remoteCommand: RemoteCommand = {
            command: command as any,
            device_id: this.deviceId,
            parameters: parameters
        };
        
        // Envoyer la commande au StatusService
        statusService.handleRemoteCommand(remoteCommand);
        
        // Envoyer le résultat de la commande
        this.sendMessage({
            type: 'command_result',
            device_id: this.deviceId,
            command: command,
            result: {
                success: true,
                message: `Commande ${command} exécutée avec succès`,
                timestamp: new Date().toISOString()
            },
            timestamp: new Date().toISOString()
        });
    }
    
    // Ajouter un callback pour la connexion
    public onConnect(callback: () => void): WebSocketService {
        this.onConnectCallbacks.push(callback);
        return this;
    }
    
    // Ajouter un callback pour la déconnexion
    public onDisconnect(callback: () => void): WebSocketService {
        this.onDisconnectCallbacks.push(callback);
        return this;
    }
    
    // Ajouter un callback pour les commandes
    public onCommand(callback: (command: string, parameters: any) => void): WebSocketService {
        this.onCommandCallbacks.push(callback);
        return this;
    }
    
    // Vérifier si le service est connecté
    public isConnectedToServer(): boolean {
        return this.isConnected;
    }
    
    // Obtenir l'URL du serveur WebSocket
    public getServerUrl(): string {
        return this.serverUrl;
    }
}

// Instance unique du service WebSocket
let webSocketServiceInstance: WebSocketService | null = null;

// Fonction pour initialiser le service WebSocket
export const initWebSocketService = async (): Promise<WebSocketService> => {
    // Si une instance existe déjà, la déconnecter
    if (webSocketServiceInstance) {
        webSocketServiceInstance.disconnect();
    }
    
    // Vérifier si le WebSocket est activé
    const webSocketEnabled = await AsyncStorage.getItem('websocket_enabled');
    if (webSocketEnabled === 'false') {
        throw new Error('WebSocket désactivé dans les paramètres');
    }
    
    // Récupérer l'URL du serveur WebSocket
    let serverUrl = await AsyncStorage.getItem('websocket_url');
    
    // Si pas d'URL configurée, essayer de la générer à partir de l'URL de l'API
    if (!serverUrl) {
        const apiUrl = apiService.getServerUrl();
        if (apiUrl) {
            serverUrl = apiUrl.replace(/^http/, 'ws').replace(/\/index\.php$/, '/websocket');
            await AsyncStorage.setItem('websocket_url', serverUrl);
        } else {
            throw new Error('URL du serveur WebSocket non configurée');
        }
    }
    
    // Créer l'instance du service
    webSocketServiceInstance = new WebSocketService({
        serverUrl,
        deviceId: apiService.getDeviceId(),
        deviceName: apiService.getDeviceName() || `Fire TV ${apiService.getDeviceId().substring(0, 8)}`,
        autoReconnect: true,
        reconnectInterval: 5000,
        pingInterval: 30000
    });
    
    // Configurer les callbacks
    webSocketServiceInstance.onConnect(() => {
        console.log('[WebSocketService] Connecté au serveur WebSocket');
        
        // Envoyer le statut actuel
        const currentStatus = statusService.getCurrentStatusSync();
        if (currentStatus) {
            webSocketServiceInstance?.sendStatus(currentStatus);
        }
    });
    
    webSocketServiceInstance.onDisconnect(() => {
        console.log('[WebSocketService] Déconnecté du serveur WebSocket');
    });
    
    webSocketServiceInstance.onCommand((command, parameters) => {
        console.log(`[WebSocketService] Commande reçue: ${command}`, parameters);
    });
    
    // Connecter au serveur
    webSocketServiceInstance.connect();
    
    return webSocketServiceInstance;
};

// Fonction pour obtenir l'instance du service WebSocket
export const getWebSocketService = (): WebSocketService | null => {
    return webSocketServiceInstance;
};

// Fonction pour envoyer le statut via WebSocket
export const sendStatusViaWebSocket = (status: any): boolean => {
    if (!webSocketServiceInstance || !webSocketServiceInstance.isConnectedToServer()) {
        return false;
    }
    
    return webSocketServiceInstance.sendStatus(status);
};