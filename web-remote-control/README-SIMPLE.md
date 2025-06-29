# Guide d'utilisation du WebSocket simplifié

Ce guide explique comment faire fonctionner le serveur WebSocket simplifié en local pour tester l'application Fire TV.

## Prérequis

- PHP 7.4+ installé sur votre machine locale
- Composer installé
- Extension PHP sockets activée

## Installation

1. Installez les dépendances avec Composer :

```bash
cd web-remote-control
composer install
```

2. Vérifiez que l'extension PHP sockets est activée :

```bash
php -m | grep sockets
```

Si vous ne voyez pas "sockets" dans la liste, vous devez activer cette extension dans votre fichier php.ini.

## Démarrage du serveur WebSocket

1. Ouvrez un terminal et naviguez vers le dossier web-remote-control
2. Exécutez la commande suivante :

```bash
php start-websocket-simple.php
```

3. Vous devriez voir un message indiquant que le serveur WebSocket est démarré sur 0.0.0.0:8080

## Test du serveur WebSocket

1. Ouvrez le fichier `websocket-simple.html` dans votre navigateur
2. Assurez-vous que l'URL du serveur est `ws://localhost:8080`
3. Cliquez sur le bouton "Connecter"
4. Si la connexion réussit, vous verrez "Connecté" en vert
5. Vous pouvez maintenant envoyer des messages au serveur WebSocket

## Dépannage

### Le serveur ne démarre pas

- Vérifiez que le port 8080 n'est pas déjà utilisé par un autre programme
- Vérifiez que l'extension PHP sockets est activée
- Vérifiez que vous avez les permissions nécessaires pour exécuter le script

### Impossible de se connecter au serveur

- Vérifiez que le serveur est bien démarré
- Vérifiez que vous utilisez l'URL correcte (ws://localhost:8080)
- Vérifiez que votre navigateur supporte WebSocket
- Vérifiez les logs du serveur pour voir s'il y a des erreurs

## Adaptation pour votre serveur OVH

Une fois que vous avez testé et validé le fonctionnement en local, vous pouvez adapter la configuration pour votre serveur OVH :

1. Assurez-vous que le port 8080 est ouvert dans le pare-feu de votre serveur OVH
2. Configurez la redirection de port sur votre routeur si nécessaire
3. Utilisez l'URL `ws://affichagedynamique.ca:8080` ou `ws://107.159.146.143:8080` pour vous connecter au serveur

## Alternatives au WebSocket

Si vous ne parvenez pas à faire fonctionner le WebSocket sur votre serveur OVH, vous pouvez utiliser l'API REST comme alternative :

- Utilisez `remote-control-api.php` pour envoyer des commandes
- Utilisez `heartbeat-receiver.php` pour recevoir les statuts des appareils
- Configurez un polling régulier pour simuler les notifications en temps réel