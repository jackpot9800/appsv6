<?php
// timezone-fix.php - Script pour diagnostiquer et corriger les problèmes de fuseau horaire
header('Content-Type: text/html; charset=utf-8');

// Afficher les informations de fuseau horaire
echo "<h1>Diagnostic et correction du fuseau horaire</h1>";

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
    
    // Vérifier si le fuseau horaire est correctement configuré
    $correctTimezone = 'America/New_York'; // Remplacez par votre fuseau horaire souhaité
    
    echo "<h2>Correction du fuseau horaire</h2>";
    
    // Définir le fuseau horaire PHP
    date_default_timezone_set($correctTimezone);
    echo "<p>Fuseau horaire PHP défini à: " . date_default_timezone_get() . "</p>";
    echo "<p>Nouvelle heure PHP locale: " . date('Y-m-d H:i:s') . "</p>";
    
    // Définir le fuseau horaire MySQL pour la session
    $offset = date('P'); // Obtenir le décalage horaire au format +HH:MM
    $dbpdointranet->exec("SET time_zone = '$offset'");
    
    // Vérifier que le changement a été appliqué
    $stmt = $dbpdointranet->query("SELECT @@session.time_zone, NOW()");
    $newTzInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p>Nouveau fuseau horaire MySQL session: " . $newTzInfo['@@session.time_zone'] . "</p>";
    echo "<p>Nouvelle heure MySQL: " . $newTzInfo['NOW()'] . "</p>";
    
    // Mettre à jour le fichier de configuration du fuseau horaire
    $configFile = 'timezone-config.php';
    if (file_exists($configFile)) {
        $configContent = file_get_contents($configFile);
        
        // Remplacer la ligne de configuration du fuseau horaire
        $configContent = preg_replace(
            "/date_default_timezone_set\('.*?'\);/",
            "date_default_timezone_set('$correctTimezone');",
            $configContent
        );
        
        // Sauvegarder le fichier
        file_put_contents($configFile, $configContent);
        echo "<p>Fichier de configuration du fuseau horaire mis à jour.</p>";
    } else {
        // Créer le fichier de configuration
        $configContent = "<?php
// timezone-config.php - Configuration du fuseau horaire pour l'application
// Inclure ce fichier au début de tous les scripts PHP pour assurer une cohérence du fuseau horaire

// Définir le fuseau horaire par défaut pour l'application
date_default_timezone_set('$correctTimezone');

// Fonction pour convertir une date/heure UTC en fuseau horaire local
function convertToLocalTime(\$utcTime, \$format = 'Y-m-d H:i:s') {
    if (empty(\$utcTime)) return null;
    
    \$dt = new DateTime(\$utcTime, new DateTimeZone('UTC'));
    \$dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return \$dt->format(\$format);
}

// Fonction pour convertir une date/heure locale en UTC pour stockage en base de données
function convertToUTC(\$localTime, \$format = 'Y-m-d H:i:s') {
    if (empty(\$localTime)) return null;
    
    \$dt = new DateTime(\$localTime, new DateTimeZone(date_default_timezone_get()));
    \$dt->setTimezone(new DateTimeZone('UTC'));
    return \$dt->format(\$format);
}
?>";
        
        file_put_contents($configFile, $configContent);
        echo "<p>Fichier de configuration du fuseau horaire créé.</p>";
    }
    
    // Mettre à jour le fichier dbpdointranet.php pour inclure la configuration du fuseau horaire
    $dbFile = 'dbpdointranet.php';
    if (file_exists($dbFile)) {
        $dbContent = file_get_contents($dbFile);
        
        // Vérifier si le fichier inclut déjà timezone-config.php
        if (strpos($dbContent, 'timezone-config.php') === false) {
            // Ajouter l'inclusion au début du fichier
            $dbContent = "<?php
// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

" . substr($dbContent, 6); // Enlever le <?php initial
            
            // Sauvegarder le fichier
            file_put_contents($dbFile, $dbContent);
            echo "<p>Fichier dbpdointranet.php mis à jour pour inclure la configuration du fuseau horaire.</p>";
        }
        
        // Vérifier si le fichier configure déjà le fuseau horaire MySQL
        if (strpos($dbContent, 'time_zone') === false) {
            // Ajouter la configuration du fuseau horaire MySQL
            $dbContent = str_replace(
                "PDO::MYSQL_ATTR_INIT_COMMAND => \"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci\"",
                "PDO::MYSQL_ATTR_INIT_COMMAND => \"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SET time_zone = '$offset';\"",
                $dbContent
            );
            
            // Sauvegarder le fichier
            file_put_contents($dbFile, $dbContent);
            echo "<p>Fichier dbpdointranet.php mis à jour pour configurer le fuseau horaire MySQL.</p>";
        }
    }
    
    echo "<h2>Test d'insertion avec le nouveau fuseau horaire</h2>";
    
    // Tester l'insertion d'un enregistrement avec le nouveau fuseau horaire
    try {
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
    } catch (Exception $e) {
        echo "<p style='color: red;'>Erreur lors du test d'insertion : " . $e->getMessage() . "</p>";
    }
    
    echo "<h2>Correction des enregistrements existants</h2>";
    
    // Proposer de corriger les enregistrements existants
    if (isset($_GET['fix']) && $_GET['fix'] === 'true') {
        try {
            // Mettre à jour les dernières connexions des appareils
            $stmt = $dbpdointranet->query("
                UPDATE appareils
                SET derniere_connexion = NOW()
                WHERE derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            ");
            
            $count = $stmt->rowCount();
            echo "<p>$count enregistrements mis à jour dans la table appareils.</p>";
            
            echo "<p style='color: green;'>Correction terminée avec succès !</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>Erreur lors de la correction des enregistrements : " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p>Pour corriger les enregistrements existants, <a href='?fix=true'>cliquez ici</a>.</p>";
    }
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>Erreur</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>

<h2>Liens utiles</h2>
<ul>
    <li><a href="timezone-test.php">Test du fuseau horaire</a></li>
    <li><a href="update-timezone.php">Mettre à jour le fuseau horaire</a></li>
    <li><a href="index.php">Retour à l'accueil</a></li>
</ul>