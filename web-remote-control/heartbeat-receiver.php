<?php
// heartbeat-receiver.php - Récepteur des heartbeats des appareils Fire TV
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Device-ID, X-Device-Type');

// Gestion des requêtes OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Vérifier que la méthode est POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

// Récupérer les données du heartbeat
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON invalide']);
    exit;
}

// Vérifier les champs requis
$deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
if (empty($deviceId)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID appareil requis']);
    exit;
}

// Connexion à la base de données
try {
    require_once('dbpdointranet.php');
    $dbpdointranet->exec("USE affichageDynamique");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion à la base de données']);
    exit;
}

// Vérifier si l'appareil existe
$stmt = $dbpdointranet->prepare("SELECT id FROM appareils WHERE identifiant_unique = ?");
$stmt->execute([$deviceId]);
$appareil = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appareil) {
    // L'appareil n'existe pas, on l'enregistre automatiquement
    try {
        $stmt = $dbpdointranet->prepare("
            INSERT INTO appareils 
            (nom, type_appareil, identifiant_unique, adresse_ip, date_enregistrement, statut)
            VALUES (?, ?, ?, ?, NOW(), 'actif')
        ");
        
        $deviceName = $data['device_name'] ?? 'Fire TV ' . substr($deviceId, -6);
        $deviceType = $data['device_type'] ?? 'firetv';
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        
        $stmt->execute([$deviceName, $deviceType, $deviceId, $ipAddress]);
        $appareilId = $dbpdointranet->lastInsertId();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de l\'enregistrement de l\'appareil']);
        exit;
    }
} else {
    $appareilId = $appareil['id'];
}

// Mettre à jour le statut de l'appareil
try {
    $stmt = $dbpdointranet->prepare("
        UPDATE appareils 
        SET 
            derniere_connexion = NOW(),
            statut_temps_reel = ?,
            presentation_courante_id = ?,
            presentation_courante_nom = ?,
            slide_courant_index = ?,
            total_slides = ?,
            mode_boucle = ?,
            lecture_automatique = ?,
            uptime_secondes = ?,
            utilisation_memoire = ?,
            force_wifi = ?,
            version_app = ?,
            message_erreur = ?,
            adresse_ip = ?
        WHERE identifiant_unique = ?
    ");

    $stmt->execute([
        $data['status'] ?? 'online',
        $data['current_presentation_id'] ?? null,
        $data['current_presentation_name'] ?? null,
        $data['current_slide_index'] ?? null,
        $data['total_slides'] ?? null,
        $data['is_looping'] ? 1 : 0,
        $data['auto_play'] ? 1 : 0,
        $data['uptime_seconds'] ?? null,
        $data['memory_usage'] ?? null,
        $data['wifi_strength'] ?? null,
        $data['app_version'] ?? null,
        $data['error_message'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $deviceId
    ]);

    // Enregistrer un log d'activité
    $stmt = $dbpdointranet->prepare("
        INSERT INTO logs_activite 
        (type_action, appareil_id, identifiant_appareil, message, details, adresse_ip)
        VALUES ('connexion', ?, ?, 'Heartbeat reçu', ?, ?)
    ");
    $stmt->execute([
        $appareilId,
        $deviceId,
        json_encode($data),
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);

    // Réponse avec les commandes en attente
    $stmt = $dbpdointranet->prepare("
        SELECT * FROM commandes_distantes 
        WHERE identifiant_appareil = ? 
        AND statut = 'en_attente'
        ORDER BY date_creation ASC
    ");
    $stmt->execute([$deviceId]);
    $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'message' => 'Heartbeat reçu',
        'commands' => $commandes
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur lors de la mise à jour du statut']);
    exit;
}
?>