<?php
// timezone-test.php - Script de test pour vérifier la configuration du fuseau horaire

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Connexion à la base de données
require_once('dbpdointranet.php');
$dbpdointranet->exec("USE affichageDynamique");

// Afficher les informations de fuseau horaire
echo "<h1>Test de configuration du fuseau horaire</h1>";

echo "<h2>Informations PHP</h2>";
echo "<ul>";
echo "<li>Fuseau horaire PHP configuré: " . date_default_timezone_get() . "</li>";
echo "<li>Heure PHP locale: " . date('Y-m-d H:i:s') . "</li>";
echo "<li>Heure PHP UTC: " . gmdate('Y-m-d H:i:s') . "</li>";
echo "</ul>";

echo "<h2>Informations MySQL</h2>";
$stmt = $dbpdointranet->query("SELECT @@global.time_zone as global_tz, @@session.time_zone as session_tz, NOW() as mysql_now, UTC_TIMESTAMP() as mysql_utc");
$tzInfo = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<ul>";
echo "<li>Fuseau horaire MySQL global: " . $tzInfo['global_tz'] . "</li>";
echo "<li>Fuseau horaire MySQL session: " . $tzInfo['session_tz'] . "</li>";
echo "<li>Heure MySQL actuelle: " . $tzInfo['mysql_now'] . "</li>";
echo "<li>Heure MySQL UTC: " . $tzInfo['mysql_utc'] . "</li>";
echo "</ul>";

echo "<h2>Test de conversion</h2>";
$localTime = date('Y-m-d H:i:s');
$utcTime = convertToUTC($localTime);
$backToLocal = convertToLocalTime($utcTime);

echo "<ul>";
echo "<li>Heure locale: " . $localTime . "</li>";
echo "<li>Convertie en UTC: " . $utcTime . "</li>";
echo "<li>Reconvertie en locale: " . $backToLocal . "</li>";
echo "</ul>";

echo "<h2>Test d'insertion et récupération</h2>";

// Insérer un enregistrement de test avec l'heure actuelle
try {
    // Créer une table temporaire pour le test
    $dbpdointranet->exec("
        CREATE TEMPORARY TABLE IF NOT EXISTS test_timezone (
            id INT AUTO_INCREMENT PRIMARY KEY,
            description VARCHAR(255),
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_creation_utc TIMESTAMP NULL
        )
    ");
    
    // Insérer avec l'heure locale
    $localTime = date('Y-m-d H:i:s');
    $utcTime = convertToUTC($localTime);
    
    $stmt = $dbpdointranet->prepare("
        INSERT INTO test_timezone (description, date_creation, date_creation_utc)
        VALUES (?, ?, ?)
    ");
    $stmt->execute(['Test timezone', $localTime, $utcTime]);
    $insertId = $dbpdointranet->lastInsertId();
    
    // Récupérer l'enregistrement
    $stmt = $dbpdointranet->prepare("
        SELECT * FROM test_timezone WHERE id = ?
    ");
    $stmt->execute([$insertId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<ul>";
    echo "<li>ID: " . $record['id'] . "</li>";
    echo "<li>Description: " . $record['description'] . "</li>";
    echo "<li>Date création (stockée): " . $record['date_creation'] . "</li>";
    echo "<li>Date création UTC (stockée): " . $record['date_creation_utc'] . "</li>";
    echo "<li>Date création (convertie): " . convertToLocalTime($record['date_creation_utc']) . "</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur: " . $e->getMessage() . "</p>";
}

echo "<h2>Dernières entrées dans la table appareils</h2>";

// Afficher les dernières entrées de la table appareils
try {
    $stmt = $dbpdointranet->query("
        SELECT id, nom, identifiant_unique, derniere_connexion 
        FROM appareils 
        ORDER BY derniere_connexion DESC 
        LIMIT 5
    ");
    $appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Nom</th><th>Identifiant</th><th>Dernière connexion</th><th>Convertie en local</th></tr>";
    
    foreach ($appareils as $appareil) {
        echo "<tr>";
        echo "<td>" . $appareil['id'] . "</td>";
        echo "<td>" . $appareil['nom'] . "</td>";
        echo "<td>" . $appareil['identifiant_unique'] . "</td>";
        echo "<td>" . $appareil['derniere_connexion'] . "</td>";
        echo "<td>" . convertToLocalTime($appareil['derniere_connexion']) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur: " . $e->getMessage() . "</p>";
}
?>