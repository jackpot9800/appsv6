<?php
// update-timezone.php - Script pour mettre à jour la configuration du fuseau horaire MySQL

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Connexion à la base de données
require_once('dbpdointranet.php');

// Vérifier si l'utilisateur est administrateur (à implémenter selon votre système d'authentification)
// Pour cet exemple, on suppose que c'est le cas

// Récupérer le fuseau horaire demandé
$timezone = $_POST['timezone'] ?? 'America/New_York';

// Liste des fuseaux horaires valides
$validTimezones = DateTimeZone::listIdentifiers();

// Vérifier que le fuseau horaire est valide
if (!in_array($timezone, $validTimezones)) {
    die('Fuseau horaire invalide');
}

try {
    // Mettre à jour le fuseau horaire de la session MySQL
    $dbpdointranet->exec("SET time_zone = '" . date('P') . "'");
    
    // Vérifier que le changement a été appliqué
    $stmt = $dbpdointranet->query("SELECT @@session.time_zone, NOW()");
    $tzInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Mettre à jour le fichier de configuration
    $configFile = 'timezone-config.php';
    $configContent = file_get_contents($configFile);
    
    // Remplacer la ligne de configuration du fuseau horaire
    $configContent = preg_replace(
        "/date_default_timezone_set\('.*?'\);/",
        "date_default_timezone_set('$timezone');",
        $configContent
    );
    
    // Sauvegarder le fichier
    file_put_contents($configFile, $configContent);
    
    // Afficher un message de succès
    echo "<h1>Configuration du fuseau horaire mise à jour</h1>";
    echo "<p>Le fuseau horaire a été mis à jour avec succès à : $timezone</p>";
    echo "<p>Fuseau horaire MySQL : " . $tzInfo['@@session.time_zone'] . "</p>";
    echo "<p>Heure MySQL actuelle : " . $tzInfo['NOW()'] . "</p>";
    echo "<p>Heure PHP actuelle : " . date('Y-m-d H:i:s') . "</p>";
    
    echo "<p><a href='timezone-test.php'>Tester la configuration</a></p>";
    echo "<p><a href='index.php'>Retour à l'accueil</a></p>";
    
} catch (Exception $e) {
    echo "<h1>Erreur</h1>";
    echo "<p>Une erreur est survenue lors de la mise à jour du fuseau horaire : " . $e->getMessage() . "</p>";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration du fuseau horaire</title>
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
        select, button {
            padding: 8px 12px;
            margin: 10px 0;
            border-radius: 4px;
            border: 1px solid #ddd;
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
        <h1>Configuration du fuseau horaire</h1>
        
        <form action="" method="post">
            <div>
                <label for="timezone">Sélectionner un fuseau horaire</label><br>
                <select name="timezone" id="timezone" style="width: 100%;">
                    <?php
                    $currentTimezone = date_default_timezone_get();
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
            
            <button type="submit">Mettre à jour le fuseau horaire</button>
        </form>
        
        <div>
            <h2>Informations actuelles</h2>
            <ul>
                <li><strong>Fuseau horaire PHP :</strong> <?= date_default_timezone_get() ?></li>
                <li><strong>Heure PHP locale :</strong> <?= date('Y-m-d H:i:s') ?></li>
                <li><strong>Heure PHP UTC :</strong> <?= gmdate('Y-m-d H:i:s') ?></li>
                <?php
                try {
                    $stmt = $dbpdointranet->query("SELECT @@session.time_zone, NOW()");
                    $tzInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <li><strong>Fuseau horaire MySQL :</strong> <?= $tzInfo['@@session.time_zone'] ?></li>
                    <li><strong>Heure MySQL :</strong> <?= $tzInfo['NOW()'] ?></li>
                <?php
                } catch (Exception $e) {
                    echo "<li><strong>Erreur MySQL :</strong> " . $e->getMessage() . "</li>";
                }
                ?>
            </ul>
        </div>
    </div>
</body>
</html>