# 🔄 Système de contrôle à distance pour Fire TV

Ce système permet de contrôler à distance les appareils Fire TV exécutant l'application Presentation Kiosk.

## 🚀 Fonctionnalités

- **WebSocket** : Communication bidirectionnelle en temps réel
- **Contrôle à distance** : Envoi de commandes aux appareils
- **Surveillance en temps réel** : Suivi du statut des appareils
- **Wake-on-LAN** : Réveil des appareils à distance
- **Interface web** : Tableau de bord de gestion
- **API REST** : Pour l'intégration avec d'autres systèmes

## 📋 Structure des fichiers

### Serveur WebSocket
- `websocket-server.php` : Serveur WebSocket basé sur Ratchet
- `start-websocket-server.php` : Script pour démarrer le serveur
- `composer.json` : Configuration des dépendances

### Interface web
- `device-monitor-realtime.php` : Interface de surveillance en temps réel
- `wake-on-lan.php` : Interface pour envoyer des paquets Wake-on-LAN
- `websocket-client.html` : Client de test WebSocket

### API
- `remote-control-api.php` : API REST pour le contrôle à distance
- `heartbeat-receiver.php` : Récepteur des heartbeats des appareils
- `command-ack.php` : Confirmation d'exécution des commandes

### Utilitaires
- `websocket-client.js` : Bibliothèque client WebSocket
- `timezone-config.php` : Configuration du fuseau horaire

## 🔧 Installation

### Prérequis
- PHP 7.4+
- Composer
- Extension PHP sockets

### Installation des dépendances
```bash
composer install
```

### Configuration
1. Assurez-vous que le port 8080 est ouvert pour les connexions WebSocket
2. Configurez le fuseau horaire dans `timezone-config.php`
3. Ajustez l'URL du serveur WebSocket dans `websocket-client.js`

## 🚀 Démarrage

### Démarrer le serveur WebSocket
```bash
php start-websocket-server.php
```

### Accéder à l'interface web
Ouvrez votre navigateur et accédez à :
- `http://votre-serveur/device-monitor-realtime.php` pour la surveillance en temps réel
- `http://votre-serveur/wake-on-lan.php` pour le Wake-on-LAN
- `http://votre-serveur/websocket-client.html` pour tester le WebSocket

## 📱 Intégration avec l'application Fire TV

L'application Fire TV doit être configurée pour se connecter au serveur WebSocket :

1. Activez l'option WebSocket dans les paramètres de l'application
2. L'application se connectera automatiquement au serveur WebSocket
3. L'application enverra périodiquement son statut au serveur
4. L'application recevra et exécutera les commandes envoyées par le serveur

## 🔄 Protocole WebSocket

### Messages du client vers le serveur

#### Enregistrement d'un appareil
```json
{
  "type": "register_device",
  "device_id": "firetv_123",
  "device_name": "Fire TV Salon",
  "timestamp": "2025-06-28T12:34:56Z"
}
```

#### Enregistrement d'un administrateur
```json
{
  "type": "register_admin",
  "timestamp": "2025-06-28T12:34:56Z"
}
```

#### Statut d'un appareil
```json
{
  "type": "device_status",
  "device_id": "firetv_123",
  "status": "playing",
  "current_presentation_id": 5,
  "current_presentation_name": "Présentation Produits 2025",
  "current_slide_index": 2,
  "total_slides": 10,
  "is_looping": true,
  "auto_play": true,
  "timestamp": "2025-06-28T12:34:56Z"
}
```

#### Commande d'un administrateur
```json
{
  "type": "admin_command",
  "device_id": "firetv_123",
  "command": "play",
  "parameters": {},
  "timestamp": "2025-06-28T12:34:56Z"
}
```

### Messages du serveur vers le client

#### Confirmation d'enregistrement
```json
{
  "type": "registration_success",
  "device_id": "firetv_123",
  "timestamp": "2025-06-28T12:34:56Z"
}
```

#### Liste des appareils connectés
```json
{
  "type": "connected_devices",
  "devices": ["firetv_123", "firetv_456"],
  "timestamp": "2025-06-28T12:34:56Z"
}
```

#### Commande pour un appareil
```json
{
  "type": "command",
  "command": "play",
  "parameters": {},
  "timestamp": "2025-06-28T12:34:56Z"
}
```

#### Mise à jour du statut d'un appareil
```json
{
  "type": "device_status_update",
  "device_id": "firetv_123",
  "status": {
    "status": "playing",
    "current_presentation_name": "Présentation Produits 2025"
  },
  "timestamp": "2025-06-28T12:34:56Z"
}
```

## 🔒 Sécurité

Ce système ne dispose pas d'authentification intégrée. Il est fortement recommandé de :

1. Limiter l'accès au serveur WebSocket aux réseaux de confiance
2. Utiliser un proxy inverse avec authentification
3. Configurer un pare-feu pour restreindre l'accès au port WebSocket
4. Implémenter une authentification par token dans le protocole WebSocket

## 🔍 Dépannage

### Le serveur WebSocket ne démarre pas
- Vérifiez que l'extension PHP sockets est activée
- Vérifiez que le port 8080 n'est pas déjà utilisé
- Exécutez le script avec les droits suffisants

### Les appareils ne se connectent pas
- Vérifiez que l'URL du serveur WebSocket est correcte
- Vérifiez que le pare-feu autorise les connexions sur le port 8080
- Vérifiez que les appareils sont sur le même réseau que le serveur

### Les commandes ne sont pas exécutées
- Vérifiez que l'appareil est bien connecté au serveur WebSocket
- Vérifiez que l'ID de l'appareil est correct
- Vérifiez les logs du serveur WebSocket pour les erreurs

## 📞 Support

Pour toute question ou problème, veuillez contacter l'équipe de support.