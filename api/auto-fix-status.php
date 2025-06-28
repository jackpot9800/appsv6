<?php
// auto-fix-status.php - Script pour corriger automatiquement le statut des appareils
// Ce script peut être exécuté via un cron job ou manuellement

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Fonction pour logger les actions
function logAction($message, $details = []) {
    $logFile = __DIR__ . '/logs/status-fix.log';
    $logDir = dirname($logFile);
    
    // Créer le répertoire de logs s'il n'existe pas
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $detailsStr = !empty($details) ? ' - ' . json_encode($details) : '';
    $logMessage = "[{$timestamp}] {$message}{$detailsStr}" . PHP_EOL;
    
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Connexion à la base de données
try {
    require_once('dbpdointranet.php');
    $dbpdointranet->exec("USE affichageDynamique");
    
    logAction("Connexion à la base de données réussie");
} catch (Exception $e) {
    logAction("Erreur de connexion à la base de données", ['error' => $e->getMessage()]);
    die("Erreur de connexion à la base de données: " . $e->getMessage());
}

// Identifier les appareils avec statut incohérent
try {
    $stmt = $dbpdointranet->query("
        SELECT 
            a.*,
            TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()) as minutes_depuis_derniere_connexion
        FROM appareils a
        WHERE a.statut = 'actif'
        AND a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        AND (a.statut_temps_reel = 'offline' OR a.statut_temps_reel IS NULL)
    ");
    $appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    logAction("Appareils avec statut incohérent identifiés", ['count' => count($appareils)]);
    
    if (count($appareils) > 0) {
        // Corriger les statuts
        $stmt = $dbpdointranet->query("
            UPDATE appareils 
            SET 
                statut_temps_reel = 'online',
                statut = 'actif'
            WHERE statut = 'actif'
            AND derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
            AND (statut_temps_reel = 'offline' OR statut_temps_reel IS NULL)
        ");
        
        $count = $stmt->rowCount();
        
        // Enregistrer un log d'activité dans la base de données
        if ($count > 0) {
            $stmt = $dbpdointranet->prepare("
                INSERT INTO logs_activite 
                (type_action, message, details, adresse_ip)
                VALUES ('maintenance', ?, ?, ?)
            ");
            $stmt->execute([
                "Correction automatique du statut de {$count} appareil(s)",
                json_encode([
                    'action' => 'auto_fix_status',
                    'devices_count' => $count,
                    'timestamp' => date('Y-m-d H:i:s')
                ]),
                '127.0.0.1' // Adresse IP locale car c'est un script automatique
            ]);
        }
        
        logAction("Statuts corrigés", ['fixed_count' => $count]);
        
        // Afficher les appareils corrigés
        foreach ($appareils as $appareil) {
            logAction("Appareil corrigé", [
                'id' => $appareil['id'],
                'nom' => $appareil['nom'],
                'identifiant' => $appareil['identifiant_unique'],
                'derniere_connexion' => $appareil['derniere_connexion'],
                'minutes_depuis' => $appareil['minutes_depuis_derniere_connexion'],
                'ancien_statut' => $appareil['statut_temps_reel']
            ]);
        }
        
        echo "Statut corrigé pour {$count} appareil(s)\n";
    } else {
        logAction("Aucun appareil à corriger");
        echo "Aucun appareil à corriger\n";
    }
} catch (Exception $e) {
    logAction("Erreur lors de la correction des statuts", ['error' => $e->getMessage()]);
    die("Erreur lors de la correction des statuts: " . $e->getMessage());
}
?>