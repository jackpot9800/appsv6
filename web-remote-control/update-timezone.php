<?php
// update-timezone.php - Script pour mettre à jour la configuration du fuseau horaire MySQL

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Connexion à la base de données
require_once('dbpdointranet.php');

// Vérifier si l'utilisateur est administrateur (à implémenter selon votre système d'authentification)
// Pour cet exemple, on suppose que c'est le cas

// Récupérer le fuseau horaire demandé
$timezone = $_POST['timezone'] ?? 'Europe/Paris';

// Liste des fuseaux horaires valides
$validTimezones = DateTimeZone::listIdentifiers();

// Vérifier que le fuseau horaire est valide
if (!in_array($timezone, $validTimezones)) {
    die('Fuseau horaire invalide');
}

try {
    // Mettre à jour le fuseau horaire de la session MySQL
    $dbpdointranet->exec("SET time_zone = '" . $timezone . "'");
    
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
    echo "<p><a href='dashboard.php'>Retour au tableau de bord</a></p>";
    
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
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen p-8">
    <div class="max-w-md mx-auto bg-white rounded-lg shadow-lg p-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">Configuration du fuseau horaire</h1>
        
        <form action="" method="post" class="space-y-4">
            <div>
                <label for="timezone" class="block text-sm font-medium text-gray-700 mb-2">
                    Sélectionner un fuseau horaire
                </label>
                <select name="timezone" id="timezone" class="w-full border border-gray-300 rounded-lg px-4 py-2">
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
            
            <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white py-2 px-4 rounded-lg">
                Mettre à jour le fuseau horaire
            </button>
        </form>
        
        <div class="mt-6 pt-6 border-t border-gray-200">
            <h2 class="text-lg font-semibold text-gray-700 mb-2">Informations actuelles</h2>
            <ul class="space-y-2 text-sm text-gray-600">
                <li><strong>Fuseau horaire PHP :</strong> <?= date_default_timezone_get() ?></li>
                <li><strong>Heure PHP locale :</strong> <?= date('Y-m-d H:i:s') ?></li>
                <li><strong>Heure PHP UTC :</strong> <?= gmdate('Y-m-d H:i:s') ?></li>
                <?php
                $stmt = $dbpdointranet->query("SELECT @@session.time_zone, NOW()");
                $tzInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                ?>
                <li><strong>Fuseau horaire MySQL :</strong> <?= $tzInfo['@@session.time_zone'] ?></li>
                <li><strong>Heure MySQL :</strong> <?= $tzInfo['NOW()'] ?></li>
            </ul>
        </div>
    </div>
</body>
</html>