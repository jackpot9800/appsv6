<?php
// start-websocket-server.php - Script pour démarrer le serveur WebSocket
// Exécutez ce script en ligne de commande: php start-websocket-server.php

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Vérifier si le script est exécuté en ligne de commande
if (php_sapi_name() !== 'cli') {
    die("Ce script doit être exécuté en ligne de commande.\n");
}

// Vérifier si Ratchet est installé
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die("Ratchet n'est pas installé. Exécutez 'composer install' pour installer les dépendances.\n");
}

// Inclure l'autoloader de Composer
require __DIR__ . '/vendor/autoload.php';

// Inclure le serveur WebSocket
require __DIR__ . '/websocket-server.php';

// Le serveur WebSocket est démarré dans websocket-server.php
?>