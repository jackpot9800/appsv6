<?php
// timezone-config.php - Configuration du fuseau horaire pour l'application
// Inclure ce fichier au début de tous les scripts PHP pour assurer une cohérence du fuseau horaire

// Définir le fuseau horaire par défaut pour l'application
// Remplacez 'Europe/Paris' par votre fuseau horaire si différent
date_default_timezone_set('America/New_York');

// Fonction pour convertir une date/heure UTC en fuseau horaire local
function convertToLocalTime($utcTime, $format = 'Y-m-d H:i:s') {
    if (empty($utcTime)) return null;
    
    $dt = new DateTime($utcTime, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $dt->format($format);
}

// Fonction pour convertir une date/heure locale en UTC pour stockage en base de données
function convertToUTC($localTime, $format = 'Y-m-d H:i:s') {
    if (empty($localTime)) return null;
    
    $dt = new DateTime($localTime, new DateTimeZone(date_default_timezone_get()));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format($format);
}

// Vérifier que le fuseau horaire est correctement configuré
$currentTimezone = date_default_timezone_get();
$serverTime = date('Y-m-d H:i:s');
$utcTime = gmdate('Y-m-d H:i:s');

// Log pour debug (optionnel)
// error_log("Fuseau horaire configuré: $currentTimezone | Heure locale: $serverTime | Heure UTC: $utcTime");
?>