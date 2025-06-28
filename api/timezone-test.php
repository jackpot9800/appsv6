<?php
// timezone-test.php - Script de test pour vérifier la configuration du fuseau horaire
header('Content-Type: text/html; charset=utf-8');

// Afficher les informations de fuseau horaire
echo "<h1>Test de configuration du fuseau horaire</h1>";

echo "<h2>Informations PHP</h2>";
echo "<ul>";
echo "<li>Fuseau horaire PHP configuré: " . date_default_timezone_get() . "</li>";
echo "<li>Heure PHP locale: " . date('Y-m-d H:i:s') . "</li>";
echo "<li>Heure PHP UTC: " . gmdate('Y-m-d H:i:s') . "</li>";
echo "</ul>";

// Connexion à la base de données
try {
    require_once('dbpdointranet.php');
    $dbpdointranet->exec("USE affichageDynamique");
    
    echo "<h2>Informations MySQL</h2>";
    $stmt = $dbpdointranet->query("SELECT @@global.time_zone as global_tz, @@session.time_zone as session_tz, NOW() as mysql_now, UTC_TIMESTAMP() as mysql_utc");
    $tzInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<ul>";
    echo "<li>Fuseau horaire MySQL global: " . $tzInfo['global_tz'] . "</li>";
    echo "<li>Fuseau horaire MySQL session: " . $tzInfo['session_tz'] . "</li>";
    echo "<li>Heure MySQL actuelle: " . $tzInfo['mysql_now'] . "</li>";
    echo "<li>Heure MySQL UTC: " . $tzInfo['mysql_utc'] . "</li>";
    echo "</ul>";
    
    // Tester l'insertion d'un enregistrement
    echo "<h2>Test d'insertion</h2>";
    
    // Insérer un log de test
    $stmt = $dbpdointranet->prepare("
        INSERT INTO logs_activite 
        (type_action, message, details, adresse_ip, date_action)
        VALUES ('maintenance', 'Test de fuseau horaire', ?, ?, NOW())
    ");
    
    $stmt->execute([
        json_encode(['test' => true, 'timestamp' => time(), 'timezone' => date_default_timezone_get()]),
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ]);
    
    $insertId = $dbpdointranet->lastInsertId();
    
    // Récupérer l'enregistrement inséré
    $stmt = $dbpdointranet->prepare("
        SELECT * FROM logs_activite WHERE id = ?
    ");
    $stmt->execute([$insertId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p>Enregistrement inséré avec succès (ID: $insertId)</p>";
    echo "<p>Date d'action: " . $log['date_action'] . "</p>";
    
    // Vérifier si l'heure est correcte
    $now = new DateTime();
    $logTime = new DateTime($log['date_action']);
    $diff = $now->getTimestamp() - $logTime->getTimestamp();
    
    if (abs($diff) < 10) { // Moins de 10 secondes de différence
        echo "<p style='color: green;'>L'heure enregistrée est correcte (différence de $diff secondes).</p>";
    } else {
        echo "<p style='color: red;'>L'heure enregistrée n'est pas correcte (différence de $diff secondes).</p>";
    }
    
    // Tester l'insertion dans la table appareils
    echo "<h2>Test d'insertion dans la table appareils</h2>";
    
    // Insérer un appareil de test
    $testDeviceId = 'test_device_' . uniqid();
    $stmt = $dbpdointranet->prepare("
        INSERT INTO appareils 
        (nom, type_appareil, identifiant_unique, adresse_ip, derniere_connexion, statut)
        VALUES (?, 'test', ?, ?, NOW(), 'actif')
    ");
    
    $stmt->execute([
        'Appareil de test',
        $testDeviceId,
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ]);
    
    // Récupérer l'appareil inséré
    $stmt = $dbpdointranet->prepare("
        SELECT * FROM appareils WHERE identifiant_unique = ?
    ");
    $stmt->execute([$testDeviceId]);
    $appareil = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p>Appareil inséré avec succès (ID: " . $appareil['id'] . ")</p>";
    echo "<p>Dernière connexion: " . $appareil['derniere_connexion'] . "</p>";
    
    // Vérifier si l'heure est correcte
    $now = new DateTime();
    $deviceTime = new DateTime($appareil['derniere_connexion']);
    $diff = $now->getTimestamp() - $deviceTime->getTimestamp();
    
    if (abs($diff) < 10) { // Moins de 10 secondes de différence
        echo "<p style='color: green;'>L'heure enregistrée est correcte (différence de $diff secondes).</p>";
    } else {
        echo "<p style='color: red;'>L'heure enregistrée n'est pas correcte (différence de $diff secondes).</p>";
    }
    
    // Supprimer l'appareil de test
    $stmt = $dbpdointranet->prepare("
        DELETE FROM appareils WHERE identifiant_unique = ?
    ");
    $stmt->execute([$testDeviceId]);
    
    // Tester la mise à jour d'un appareil existant
    echo "<h2>Test de mise à jour d'un appareil existant</h2>";
    
    // Récupérer un appareil existant
    $stmt = $dbpdointranet->query("
        SELECT * FROM appareils 
        ORDER BY derniere_connexion DESC 
        LIMIT 1
    ");
    $existingDevice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingDevice) {
        echo "<p>Appareil existant trouvé (ID: " . $existingDevice['id'] . ")</p>";
        echo "<p>Dernière connexion avant mise à jour: " . $existingDevice['derniere_connexion'] . "</p>";
        
        // Mettre à jour la dernière connexion
        $stmt = $dbpdointranet->prepare("
            UPDATE appareils 
            SET derniere_connexion = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$existingDevice['id']]);
        
        // Récupérer l'appareil mis à jour
        $stmt = $dbpdointranet->prepare("
            SELECT * FROM appareils WHERE id = ?
        ");
        $stmt->execute([$existingDevice['id']]);
        $updatedDevice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<p>Dernière connexion après mise à jour: " . $updatedDevice['derniere_connexion'] . "</p>";
        
        // Vérifier si l'heure est correcte
        $now = new DateTime();
        $deviceTime = new DateTime($updatedDevice['derniere_connexion']);
        $diff = $now->getTimestamp() - $deviceTime->getTimestamp();
        
        if (abs($diff) < 10) { // Moins de 10 secondes de différence
            echo "<p style='color: green;'>L'heure mise à jour est correcte (différence de $diff secondes).</p>";
        } else {
            echo "<p style='color: red;'>L'heure mise à jour n'est pas correcte (différence de $diff secondes).</p>";
        }
    } else {
        echo "<p>Aucun appareil existant trouvé.</p>";
    }
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>Erreur</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>

<h2>Liens utiles</h2>
<ul>
    <li><a href="timezone-fix.php">Diagnostic et correction du fuseau horaire</a></li>
    <li><a href="timezone-update.php">Mettre à jour le fuseau horaire</a></li>
    <li><a href="index.php">Retour à l'accueil</a></li>
</ul>