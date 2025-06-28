<?php
// timezone-update.php - Script pour mettre à jour le fuseau horaire du serveur
header('Content-Type: text/html; charset=utf-8');

// Récupérer le fuseau horaire demandé
$timezone = $_POST['timezone'] ?? '';
$updateDb = isset($_POST['update_db']) && $_POST['update_db'] === 'yes';
$updateFiles = isset($_POST['update_files']) && $_POST['update_files'] === 'yes';

// Liste des fuseaux horaires valides
$validTimezones = DateTimeZone::listIdentifiers();

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($timezone)) {
    // Vérifier que le fuseau horaire est valide
    if (!in_array($timezone, $validTimezones)) {
        $error = 'Fuseau horaire invalide';
    } else {
        // Mettre à jour le fuseau horaire PHP
        date_default_timezone_set($timezone);
        
        // Mettre à jour le fichier de configuration
        if ($updateFiles) {
            $configFile = 'timezone-config.php';
            if (file_exists($configFile)) {
                $configContent = file_get_contents($configFile);
                
                // Remplacer la ligne de configuration du fuseau horaire
                $configContent = preg_replace(
                    "/date_default_timezone_set\('.*?'\);/",
                    "date_default_timezone_set('$timezone');",
                    $configContent
                );
                
                // Sauvegarder le fichier
                file_put_contents($configFile, $configContent);
                $configUpdated = true;
            } else {
                // Créer le fichier de configuration
                $configContent = "<?php
// timezone-config.php - Configuration du fuseau horaire pour l'application
// Inclure ce fichier au début de tous les scripts PHP pour assurer une cohérence du fuseau horaire

// Définir le fuseau horaire par défaut pour l'application
date_default_timezone_set('$timezone');

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
                $configCreated = true;
            }
        }
        
        // Mettre à jour la base de données
        if ($updateDb) {
            try {
                require_once('dbpdointranet.php');
                $dbpdointranet->exec("USE affichisebastien");
                
                // Définir le fuseau horaire MySQL pour la session
                $offset = date('P'); // Obtenir le décalage horaire au format +HH:MM
                $dbpdointranet->exec("SET time_zone = '$offset'");
                
                // Mettre à jour le fichier dbpdointranet.php pour inclure la configuration du fuseau horaire
                $dbFile = 'dbpdointranet.php';
                if (file_exists($dbFile) && $updateFiles) {
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
                    }
                }
                
                $dbUpdated = true;
            } catch (Exception $e) {
                $dbError = $e->getMessage();
            }
        }
        
        $success = true;
    }
}

// Récupérer le fuseau horaire actuel
$currentTimezone = date_default_timezone_get();

// Récupérer les informations MySQL si possible
try {
    require_once('dbpdointranet.php');
    $dbpdointranet->exec("USE affichisebastien");
    
    $stmt = $dbpdointranet->query("SELECT @@global.time_zone as global_tz, @@session.time_zone as session_tz, NOW() as mysql_now, UTC_TIMESTAMP() as mysql_utc");
    $tzInfo = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $dbConnectionError = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mise à jour du fuseau horaire</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        h2 {
            color: #444;
            margin-top: 20px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        form {
            margin-top: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        select, button {
            padding: 8px 12px;
            margin: 10px 0;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        select {
            width: 100%;
        }
        button {
            background-color: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        .checkbox-group {
            margin: 10px 0;
        }
        .checkbox-group label {
            font-weight: normal;
            display: inline;
            margin-left: 5px;
        }
        ul {
            list-style-type: none;
            padding: 0;
        }
        li {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        li strong {
            display: inline-block;
            width: 200px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Mise à jour du fuseau horaire</h1>
        
        <?php if (isset($success)): ?>
            <div class="success">
                <p>Le fuseau horaire a été mis à jour avec succès à : <?= htmlspecialchars($timezone) ?></p>
                <?php if (isset($configUpdated)): ?>
                    <p>Le fichier de configuration a été mis à jour.</p>
                <?php endif; ?>
                <?php if (isset($configCreated)): ?>
                    <p>Le fichier de configuration a été créé.</p>
                <?php endif; ?>
                <?php if (isset($dbUpdated)): ?>
                    <p>Le fuseau horaire de la base de données a été mis à jour.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error">
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>
        
        <h2>Configuration actuelle</h2>
        <ul>
            <li><strong>Fuseau horaire PHP :</strong> <?= htmlspecialchars($currentTimezone) ?></li>
            <li><strong>Heure PHP locale :</strong> <?= date('Y-m-d H:i:s') ?></li>
            <li><strong>Heure PHP UTC :</strong> <?= gmdate('Y-m-d H:i:s') ?></li>
            <?php if (isset($tzInfo)): ?>
                <li><strong>Fuseau horaire MySQL global :</strong> <?= htmlspecialchars($tzInfo['global_tz']) ?></li>
                <li><strong>Fuseau horaire MySQL session :</strong> <?= htmlspecialchars($tzInfo['session_tz']) ?></li>
                <li><strong>Heure MySQL actuelle :</strong> <?= htmlspecialchars($tzInfo['mysql_now']) ?></li>
                <li><strong>Heure MySQL UTC :</strong> <?= htmlspecialchars($tzInfo['mysql_utc']) ?></li>
            <?php elseif (isset($dbConnectionError)): ?>
                <li><strong>Erreur de connexion à la base de données :</strong> <?= htmlspecialchars($dbConnectionError) ?></li>
            <?php endif; ?>
        </ul>
        
        <h2>Mettre à jour le fuseau horaire</h2>
        <form action="" method="post">
            <div>
                <label for="timezone">Sélectionner un fuseau horaire</label>
                <select name="timezone" id="timezone">
                    <?php
                    $regions = [
                        'Africa' => DateTimeZone::AFRICA,
                        'America' => DateTimeZone::AMERICA,
                        'Antarctica' => DateTimeZone::ANTARCTICA,
                        'Arctic' => DateTimeZone::ARCTIC,
                        'Asia' => DateTimeZone::ASIA,
                        'Atlantic' => DateTimeZone::ATLANTIC,
                        'Australia' => DateTimeZone::AUSTRALIA,
                        'Europe' => DateTimeZone::EUROPE,
                        'Indian' => DateTimeZone::INDIAN,
                        'Pacific' => DateTimeZone::PACIFIC
                    ];
                    
                    foreach ($regions as $region => $mask) {
                        $timezones = DateTimeZone::listIdentifiers($mask);
                        echo "<optgroup label='$region'>";
                        
                        foreach ($timezones as $tz) {
                            $selected = ($tz === $currentTimezone) ? 'selected' : '';
                            echo "<option value='$tz' $selected>$tz</option>";
                        }
                        
                        echo "</optgroup>";
                    }
                    ?>
                </select>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" id="update_db" name="update_db" value="yes" checked>
                <label for="update_db">Mettre à jour la base de données</label>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" id="update_files" name="update_files" value="yes" checked>
                <label for="update_files">Mettre à jour les fichiers de configuration</label>
            </div>
            
            <button type="submit">Mettre à jour le fuseau horaire</button>
        </form>
        
        <h2>Liens utiles</h2>
        <ul>
            <li><a href="timezone-test.php">Test du fuseau horaire</a></li>
            <li><a href="timezone-fix.php">Diagnostic et correction du fuseau horaire</a></li>
            <li><a href="index.php">Retour à l'accueil</a></li>
        </ul>
    </div>
</body>
</html>