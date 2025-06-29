<?php
// start-websocket-server-enhanced.php - Script amélioré pour démarrer le serveur WebSocket
// Exécutez ce script en ligne de commande: php start-websocket-server-enhanced.php

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

// Récupérer les arguments de la ligne de commande
$port = 8080; // Port par défaut
$interface = '0.0.0.0'; // Écouter sur toutes les interfaces par défaut
$logFile = __DIR__ . '/websocket.log';
$daemon = false; // Mode démon par défaut désactivé

// Traiter les arguments
foreach ($argv as $arg) {
    if (strpos($arg, '--port=') === 0) {
        $port = (int)substr($arg, 7);
    } elseif (strpos($arg, '--interface=') === 0) {
        $interface = substr($arg, 12);
    } elseif (strpos($arg, '--log=') === 0) {
        $logFile = substr($arg, 6);
    } elseif ($arg === '--daemon') {
        $daemon = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php start-websocket-server-enhanced.php [options]\n";
        echo "Options:\n";
        echo "  --port=PORT       Port d'écoute (défaut: 8080)\n";
        echo "  --interface=IP    Interface d'écoute (défaut: 0.0.0.0)\n";
        echo "  --log=FILE        Fichier de log (défaut: ./websocket.log)\n";
        echo "  --daemon          Exécuter en arrière-plan\n";
        echo "  --help, -h        Afficher cette aide\n";
        exit(0);
    }
}

// Vérifier si le port est déjà utilisé
$checkPort = @fsockopen($interface, $port, $errno, $errstr, 1);
if ($checkPort) {
    fclose($checkPort);
    die("Erreur: Le port {$port} est déjà utilisé. Choisissez un autre port ou arrêtez le service qui l'utilise.\n");
}

// Mode démon
if ($daemon) {
    echo "Démarrage du serveur WebSocket en mode démon...\n";
    
    // Détacher du terminal
    $pid = pcntl_fork();
    
    if ($pid == -1) {
        die("Impossible de passer en mode démon\n");
    } else if ($pid) {
        // Parent
        echo "Serveur WebSocket démarré en arrière-plan (PID: {$pid})\n";
        exit(0);
    }
    
    // Enfant
    posix_setsid();
    
    // Rediriger les sorties standard
    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);
    
    $STDIN = fopen('/dev/null', 'r');
    $STDOUT = fopen($logFile, 'ab');
    $STDERR = fopen($logFile, 'ab');
}

// Inclure le serveur WebSocket amélioré
require __DIR__ . '/websocket-server-enhanced.php';

// Le serveur WebSocket est démarré dans websocket-server-enhanced.php