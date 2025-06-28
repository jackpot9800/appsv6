<?php
// command-ack.php - Confirmation d'exécution des commandes
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Device-ID, X-Device-Type, X-Device-Name, X-Local-IP, X-External-IP, X-App-Version');

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

// Récupérer les données
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON invalide']);
    exit;
}

// Vérifier les champs requis
$deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
$commandId = $data['command_id'] ?? '';
$status = $data['status'] ?? 'executee';
$result = $data['result'] ?? '';

if (empty($deviceId) || empty($commandId)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID appareil et ID commande requis']);
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
    $dbpdointranet->exec("USE affichageDynamique");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion à la base de données: ' . $e->getMessage()]);
    exit;
}

// Vérifier si la commande existe
$stmt = $dbpdointranet->prepare("
    SELECT * FROM commandes_distantes 
    WHERE id = ? AND identifiant_appareil = ?
");
$stmt->execute([$commandId, $deviceId]);
$commande = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$commande) {
    http_response_code(404);
    echo json_encode(['error' => 'Commande non trouvée']);
    exit;
}

// Mettre à jour le statut de la commande
try {
    $stmt = $dbpdointranet->prepare("
        UPDATE commandes_distantes 
        SET statut = ?, date_execution = NOW(), resultat_execution = ?
        WHERE id = ? AND identifiant_appareil = ?
    ");
    $stmt->execute([$status, $result, $commandId, $deviceId]);

    // Enregistrer un log d'activité
    $stmt = $dbpdointranet->prepare("
        INSERT INTO logs_activite 
        (type_action, identifiant_appareil, message, details, adresse_ip, adresse_ip_externe)
        VALUES ('commande_distante', ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $deviceId,
        "Commande {$commande['commande']} " . ($status === 'executee' ? 'exécutée' : 'échouée'),
        json_encode([
            'command_id' => $commandId,
            'command' => $commande['commande'],
            'status' => $status,
            'result' => $result,
            'local_ip' => $localIP,
            'external_ip' => $externalIP,
            'device_name' => $deviceName,
            'app_version' => $appVersion
        ]),
        $_SERVER['REMOTE_ADDR'] ?? '',
        $externalIP
    ]);

    echo json_encode([
        'success' => true, 
        'message' => 'Statut de la commande mis à jour',
        'server_time' => date('Y-m-d H:i:s')
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur lors de la mise à jour du statut de la commande: ' . $e->getMessage()]);
    exit;
}
?>