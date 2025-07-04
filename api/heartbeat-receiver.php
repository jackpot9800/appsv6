<?php
// heartbeat-receiver.php - Récepteur des heartbeats des appareils Fire TV
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Device-ID, X-Device-Type, X-Device-Name, X-Local-IP, X-External-IP, X-App-Version');

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

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

// Récupérer les informations supplémentaires des headers
$deviceName = $_SERVER['HTTP_X_DEVICE_NAME'] ?? null;
$deviceType = $_SERVER['HTTP_X_DEVICE_TYPE'] ?? 'firetv';
$localIP = $_SERVER['HTTP_X_LOCAL_IP'] ?? null;
$externalIP = $_SERVER['HTTP_X_EXTERNAL_IP'] ?? null;
$appVersion = $_SERVER['HTTP_X_APP_VERSION'] ?? null;

// Si pas d'IP externe dans les headers, utiliser l'IP de la requête
if (empty($externalIP)) {
    $externalIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
}

// Connexion à la base de données
try {
    require_once('dbpdointranet.php');
    $dbpdointranet->exec("USE affichisebastien");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion à la base de données: ' . $e->getMessage()]);
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
            (nom, type_appareil, identifiant_unique, adresse_ip, adresse_ip_externe, adresse_ip_locale, date_enregistrement, statut)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 'actif')
        ");
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        
        $stmt->execute([
            $deviceName ?? 'Fire TV ' . substr($deviceId, -6),
            $deviceType,
            $deviceId,
            $ipAddress,
            $externalIP,
            $localIP
        ]);
        $appareilId = $dbpdointranet->lastInsertId();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de l\'enregistrement de l\'appareil: ' . $e->getMessage()]);
        exit;
    }
} else {
    $appareilId = $appareil['id'];
}

// Mettre à jour le statut de l'appareil avec le fuseau horaire correct
try {
    // Utiliser NOW() avec le fuseau horaire correct
    $currentTime = date('Y-m-d H:i:s');
    
    $stmt = $dbpdointranet->prepare("
        UPDATE appareils 
        SET 
            derniere_connexion = ?,
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
            adresse_ip = ?,
            adresse_ip_externe = ?,
            adresse_ip_locale = ?,
            nom = COALESCE(?, nom)
        WHERE identifiant_unique = ?
    ");

    $stmt->execute([
        $currentTime, // Utiliser l'heure locale correcte
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
        $appVersion ?? $data['app_version'] ?? null,
        $data['error_message'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $externalIP,
        $localIP,
        $deviceName,
        $deviceId
    ]);

    // Enregistrer un log d'activité
    try {
        $stmt = $dbpdointranet->prepare("
            INSERT INTO logs_activite 
            (type_action, appareil_id, identifiant_appareil, message, details, adresse_ip, adresse_ip_externe, date_action)
            VALUES ('connexion', ?, ?, 'Heartbeat reçu', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $appareilId,
            $deviceId,
            json_encode([
                'status' => $data['status'] ?? 'online',
                'current_presentation' => $data['current_presentation_name'] ?? null,
                'local_ip' => $localIP,
                'external_ip' => $externalIP,
                'device_name' => $deviceName
            ]),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $externalIP,
            $currentTime // Utiliser l'heure locale correcte
        ]);
    } catch (PDOException $e) {
        // Si l'erreur est liée à une clé primaire dupliquée, on l'ignore simplement
        if (strpos($e->getMessage(), 'Duplicate entry') === false) {
            // Si c'est une autre erreur, on la propage
            throw $e;
        }
    }

    // Réponse avec les commandes en attente
    $stmt = $dbpdointranet->prepare("
        SELECT * FROM commandes_distantes 
        WHERE identifiant_appareil = ? 
        AND statut = 'en_attente'
        ORDER BY priorite DESC, date_creation ASC
        LIMIT 5
    ");
    $stmt->execute([$deviceId]);
    $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mettre à jour les tentatives pour ces commandes
    if (!empty($commandes)) {
        $commandIds = array_column($commandes, 'id');
        $placeholders = implode(',', array_fill(0, count($commandIds), '?'));
        
        $stmt = $dbpdointranet->prepare("
            UPDATE commandes_distantes 
            SET tentatives = tentatives + 1, derniere_tentative = NOW()
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($commandIds);
    }

    echo json_encode([
        'success' => true, 
        'message' => 'Heartbeat reçu',
        'server_time' => $currentTime, // Renvoyer l'heure du serveur pour synchronisation
        'commands' => $commandes,
        'device_id' => $deviceId,
        'external_ip' => $externalIP,
        'local_ip' => $localIP
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur lors de la mise à jour du statut: ' . $e->getMessage()]);
    exit;
}
?>