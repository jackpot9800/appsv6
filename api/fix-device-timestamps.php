<?php
// fix-device-timestamps.php - Script pour corriger les horodatages des appareils
header('Content-Type: text/html; charset=utf-8');

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Connexion à la base de données
try {
    require_once('dbpdointranet.php');
    $dbpdointranet->exec("USE affichisebastien");
    
    echo "<h1>Correction des horodatages des appareils</h1>";
    
    // Vérifier si une action est demandée
    $action = $_GET['action'] ?? '';
    
    if ($action === 'fix_all') {
        // Mettre à jour tous les appareils avec l'heure actuelle
        $stmt = $dbpdointranet->query("
            UPDATE appareils
            SET derniere_connexion = NOW()
            WHERE derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        
        $count = $stmt->rowCount();
        echo "<p style='color: green;'>$count appareils mis à jour avec l'heure actuelle.</p>";
        
        // Enregistrer un log d'activité
        try {
            $stmt = $dbpdointranet->prepare("
                INSERT INTO logs_activite 
                (type_action, message, details, adresse_ip, date_action)
                VALUES ('maintenance', 'Correction des horodatages des appareils', ?, ?, NOW())
            ");
            
            $stmt->execute([
                json_encode([
                    'action' => 'fix_all_timestamps',
                    'devices_count' => $count,
                    'timestamp' => time(),
                    'timezone' => date_default_timezone_get()
                ]),
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } catch (PDOException $e) {
            echo "<p style='color: orange;'>Note: Log d'activité non enregistré: " . $e->getMessage() . "</p>";
        }
    } elseif ($action === 'fix_device' && !empty($_GET['device_id'])) {
        // Mettre à jour un appareil spécifique
        $deviceId = $_GET['device_id'];
        
        $stmt = $dbpdointranet->prepare("
            UPDATE appareils
            SET derniere_connexion = NOW()
            WHERE identifiant_unique = ?
        ");
        
        $stmt->execute([$deviceId]);
        
        $count = $stmt->rowCount();
        if ($count > 0) {
            echo "<p style='color: green;'>Appareil $deviceId mis à jour avec l'heure actuelle.</p>";
            
            // Enregistrer un log d'activité
            try {
                $stmt = $dbpdointranet->prepare("
                    INSERT INTO logs_activite 
                    (type_action, identifiant_appareil, message, details, adresse_ip, date_action)
                    VALUES ('maintenance', ?, 'Correction de l\'horodatage de l\'appareil', ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $deviceId,
                    json_encode([
                        'action' => 'fix_device_timestamp',
                        'device_id' => $deviceId,
                        'timestamp' => time(),
                        'timezone' => date_default_timezone_get()
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);
            } catch (PDOException $e) {
                echo "<p style='color: orange;'>Note: Log d'activité non enregistré: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p style='color: red;'>Appareil $deviceId non trouvé ou non mis à jour.</p>";
        }
    }
    
    // Afficher les appareils récents
    $stmt = $dbpdointranet->query("
        SELECT 
            a.*,
            TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()) as minutes_depuis_derniere_connexion
        FROM appareils a
        WHERE a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ORDER BY a.derniere_connexion DESC
    ");
    $appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Appareils récemment connectés</h2>";
    
    if (count($appareils) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Nom</th><th>Identifiant</th><th>Dernière connexion</th><th>Minutes depuis</th><th>Actions</th></tr>";
        
        foreach ($appareils as $appareil) {
            echo "<tr>";
            echo "<td>" . $appareil['id'] . "</td>";
            echo "<td>" . htmlspecialchars($appareil['nom']) . "</td>";
            echo "<td>" . htmlspecialchars($appareil['identifiant_unique']) . "</td>";
            echo "<td>" . $appareil['derniere_connexion'] . "</td>";
            echo "<td>" . $appareil['minutes_depuis_derniere_connexion'] . "</td>";
            echo "<td><a href='?action=fix_device&device_id=" . urlencode($appareil['identifiant_unique']) . "'>Corriger</a></td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        echo "<p><a href='?action=fix_all'>Mettre à jour tous ces appareils</a></p>";
    } else {
        echo "<p>Aucun appareil récemment connecté trouvé.</p>";
    }
    
    // Afficher les informations de fuseau horaire
    echo "<h2>Informations de fuseau horaire</h2>";
    echo "<ul>";
    echo "<li>Fuseau horaire PHP : " . date_default_timezone_get() . "</li>";
    echo "<li>Heure PHP locale : " . date('Y-m-d H:i:s') . "</li>";
    echo "<li>Heure PHP UTC : " . gmdate('Y-m-d H:i:s') . "</li>";
    
    $stmt = $dbpdointranet->query("SELECT @@global.time_zone as global_tz, @@session.time_zone as session_tz, NOW() as mysql_now, UTC_TIMESTAMP() as mysql_utc");
    $tzInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<li>Fuseau horaire MySQL global : " . $tzInfo['global_tz'] . "</li>";
    echo "<li>Fuseau horaire MySQL session : " . $tzInfo['session_tz'] . "</li>";
    echo "<li>Heure MySQL actuelle : " . $tzInfo['mysql_now'] . "</li>";
    echo "<li>Heure MySQL UTC : " . $tzInfo['mysql_utc'] . "</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<h1 style='color: red;'>Erreur</h1>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>

<h2>Liens utiles</h2>
<ul>
    <li><a href="timezone-fix.php">Diagnostic et correction du fuseau horaire</a></li>
    <li><a href="timezone-test.php">Test du fuseau horaire</a></li>
    <li><a href="timezone-update.php">Mettre à jour le fuseau horaire</a></li>
    <li><a href="index.php">Retour à l'accueil</a></li>
</ul>