<?php
// dbpdointranet.php - Connexion à la base de données avec configuration du fuseau horaire

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Paramètres de connexion à la base de données
$db_host = 'localhost';
$db_name = 'affichageDynamique';
$db_user = 'root';
$db_pass = '';

try {
    // Créer une connexion PDO avec configuration du fuseau horaire
    $dbpdointranet = new PDO(
        "mysql:host=$db_host;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SET time_zone = '+01:00';" // Ajustez le fuseau horaire selon vos besoins
        ]
    );
    
    // Vérifier que le fuseau horaire MySQL est correctement configuré
    $stmt = $dbpdointranet->query("SELECT @@session.time_zone, NOW()");
    $tzInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Log pour debug (optionnel)
    // error_log("MySQL timezone: " . $tzInfo['@@session.time_zone'] . " | MySQL time: " . $tzInfo['NOW()']);
    
} catch (PDOException $e) {
    // En cas d'erreur, afficher un message et arrêter le script
    die('Erreur de connexion à la base de données : ' . $e->getMessage());
}
?>