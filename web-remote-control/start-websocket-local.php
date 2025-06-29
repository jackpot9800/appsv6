<?php
// start-websocket-local.php - Script pour démarrer le serveur WebSocket local
// Exécutez ce script en ligne de commande: php start-websocket-local.php

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

// Vérifier si le port 8080 est déjà utilisé
$connection = @fsockopen('127.0.0.1', 8080, $errno, $errstr, 1);
if (is_resource($connection)) {
    fclose($connection);
    die("Erreur: Le port 8080 est déjà utilisé. Arrêtez le service qui l'utilise ou choisissez un autre port.\n");
}

// Inclure le serveur WebSocket local
require __DIR__ . '/websocket-server-local.php';

// Le serveur WebSocket est démarré dans websocket-server-local.php