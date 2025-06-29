<?php
// start-websocket-simple.php - Script ultra simplifié pour démarrer le serveur WebSocket
// Exécutez ce script en ligne de commande: php start-websocket-simple.php

// Vérifier si le script est exécuté en ligne de commande
if (php_sapi_name() !== 'cli') {
    die("Ce script doit être exécuté en ligne de commande.\n");
}

// Vérifier si Ratchet est installé
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die("Ratchet n'est pas installé. Exécutez 'composer install' pour installer les dépendances.\n");
}

// Vérifier si l'extension sockets est activée
if (!extension_loaded('sockets')) {
    die("L'extension PHP 'sockets' n'est pas activée. Activez-la dans votre php.ini.\n");
}

// Vérifier si le port 8080 est déjà utilisé
$connection = @fsockopen('127.0.0.1', 8080, $errno, $errstr, 1);
if (is_resource($connection)) {
    fclose($connection);
    die("Erreur: Le port 8080 est déjà utilisé. Arrêtez le service qui l'utilise ou choisissez un autre port.\n");
}

echo "Démarrage du serveur WebSocket...\n";

// Inclure le serveur WebSocket simplifié
require __DIR__ . '/websocket-server-simple.php';

// Le serveur WebSocket est démarré dans websocket-server-simple.php