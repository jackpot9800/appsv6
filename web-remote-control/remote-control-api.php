<?php
// remote-control-api.php - API pour le contrôle à distance des appareils

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Device-ID, X-Device-Type, X-Device-Name, X-Local-IP, X-External-IP');

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Gestion des requêtes OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Fonction pour générer une réponse JSON
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Fonction pour logger les erreurs
function logError($message, $context = []) {
    $logMessage = date('Y-m-d H:i:s') . ' - ' . $message;
    if (!empty($context)) {
        $logMessage .= ' - Context: ' . json_encode($context);
    }
    error_log($logMessage);
}

// Récupérer les données de la requête
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    jsonResponse(['success' => false, 'message' => 'JSON invalide dans le corps de la requête'], 400);
}

// Vérifier l'action demandée
$action = $data['action'] ?? '';

// Connexion à la base de données
try {
    require_once('dbpdointranet.php');
    $dbpdointranet->exec("USE affichageDynamique");
} catch (Exception $e) {
    logError('Connexion à la base de données échouée', ['error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => 'Erreur de connexion à la base de données'], 500);
}

// Traiter l'action demandée
switch ($action) {
    case 'send_command':
        // Envoyer une commande à l'appareil
        $deviceId = $data['device_id'] ?? '';
        $command = $data['command'] ?? '';
        $parameters = $data['parameters'] ?? [];
        
        if (empty($deviceId)) {
            jsonResponse(['success' => false, 'message' => 'ID appareil requis'], 400);
        }
        
        if (empty($command)) {
            jsonResponse(['success' => false, 'message' => 'Commande requise'], 400);
        }
        
        // Valider la commande
        $valid_commands = ['play', 'pause', 'stop', 'restart', 'next_slide', 'prev_slide', 'goto_slide', 'assign_presentation', 'reboot', 'update_app'];
        if (!in_array($command, $valid_commands)) {
            jsonResponse(['success' => false, 'message' => 'Commande invalide'], 400);
        }
        
        // Validation spécifique pour certaines commandes
        if ($command === 'goto_slide' && !isset($parameters['slide_index'])) {
            jsonResponse(['success' => false, 'message' => 'Index de slide requis'], 400);
        }
        
        if ($command === 'assign_presentation' && !isset($parameters['presentation_id'])) {
            jsonResponse(['success' => false, 'message' => 'ID de présentation requis'], 400);
        }
        
        // Vérifier que l'appareil existe
        $stmt = $dbpdointranet->prepare("SELECT id, adresse_ip, adresse_ip_externe FROM appareils WHERE identifiant_unique = ?");
        $stmt->execute([$deviceId]);
        $appareil = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appareil) {
            jsonResponse(['success' => false, 'message' => 'Appareil non trouvé'], 404);
        }
        
        try {
            // Insérer la commande dans la base de données
            $stmt = $dbpdointranet->prepare("
                INSERT INTO commandes_distantes 
                (identifiant_appareil, commande, parametres, statut, date_creation, adresse_ip_cible)
                VALUES (?, ?, ?, 'en_attente', NOW(), ?)
            ");
            
            $stmt->execute([
                $deviceId,
                $command,
                json_encode($parameters),
                $appareil['adresse_ip_externe'] ?? $appareil['adresse_ip'] ?? null
            ]);
            
            $command_id = $dbpdointranet->lastInsertId();
            
            // Enregistrer un log d'activité
            $stmt = $dbpdointranet->prepare("
                INSERT INTO logs_activite 
                (type_action, appareil_id, identifiant_appareil, message, details, adresse_ip, adresse_ip_externe, date_action)
                VALUES ('commande_distante', ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $appareil['id'],
                $deviceId,
                "Commande distante envoyée: {$command}",
                json_encode([
                    'command' => $command,
                    'parameters' => $parameters,
                    'command_id' => $command_id
                ]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            
            jsonResponse([
                'success' => true, 
                'message' => 'Commande envoyée avec succès',
                'command_id' => $command_id
            ]);
            
        } catch (Exception $e) {
            logError('Erreur lors de l\'envoi de la commande', ['error' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => 'Erreur lors de l\'envoi de la commande'], 500);
        }
        break;
        
    case 'get_status':
        // Récupérer le statut de l'appareil
        $deviceId = $data['device_id'] ?? '';
        
        if (empty($deviceId)) {
            jsonResponse(['success' => false, 'message' => 'ID appareil requis'], 400);
        }
        
        try {
            $stmt = $dbpdointranet->prepare("
                SELECT 
                    a.*,
                    p.nom as presentation_courante_nom
                FROM appareils a
                LEFT JOIN presentations p ON a.presentation_courante_id = p.id
                WHERE a.identifiant_unique = ?
            ");
            $stmt->execute([$deviceId]);
            $appareil = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appareil) {
                jsonResponse(['success' => false, 'message' => 'Appareil non trouvé'], 404);
            }
            
            // Convertir les dates en heure locale
            if ($appareil['derniere_connexion']) {
                $appareil['derniere_connexion'] = convertToLocalTime($appareil['derniere_connexion']);
            }
            
            // Déterminer le statut de connexion
            if ($appareil['derniere_connexion']) {
                $last_seen = new DateTime($appareil['derniere_connexion']);
                $now = new DateTime();
                $diff = $now->getTimestamp() - $last_seen->getTimestamp();
                
                // Si pas de mise à jour depuis plus de 2 minutes, considérer comme hors ligne
                if ($diff > 120 && $appareil['statut_temps_reel'] !== 'offline') {
                    $appareil['statut_temps_reel'] = 'offline';
                    
                    // Mettre à jour le statut dans la base de données
                    $stmt = $dbpdointranet->prepare("
                        UPDATE appareils 
                        SET statut_temps_reel = 'offline' 
                        WHERE identifiant_unique = ?
                    ");
                    $stmt->execute([$deviceId]);
                }
            }
            
            jsonResponse([
                'success' => true,
                'data' => $appareil
            ]);
            
        } catch (Exception $e) {
            logError('Erreur lors de la récupération du statut', ['error' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => 'Erreur lors de la récupération du statut'], 500);
        }
        break;
        
    case 'get_command_history':
        // Récupérer l'historique des commandes
        $deviceId = $data['device_id'] ?? '';
        
        if (empty($deviceId)) {
            jsonResponse(['success' => false, 'message' => 'ID appareil requis'], 400);
        }
        
        try {
            $stmt = $dbpdointranet->prepare("
                SELECT * FROM commandes_distantes 
                WHERE identifiant_appareil = ? 
                ORDER BY date_creation DESC 
                LIMIT 10
            ");
            $stmt->execute([$deviceId]);
            $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convertir les dates en heure locale
            foreach ($commandes as &$commande) {
                $commande['date_creation'] = convertToLocalTime($commande['date_creation']);
                if ($commande['date_execution']) {
                    $commande['date_execution'] = convertToLocalTime($commande['date_execution']);
                }
            }
            
            jsonResponse([
                'success' => true,
                'data' => $commandes
            ]);
            
        } catch (Exception $e) {
            logError('Erreur lors de la récupération de l\'historique', ['error' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => 'Erreur lors de la récupération de l\'historique'], 500);
        }
        break;
        
    case 'assign_presentation':
        // Assigner une présentation à l'appareil
        $deviceId = $data['device_id'] ?? '';
        $presentationId = $data['presentation_id'] ?? 0;
        $autoPlay = $data['auto_play'] ?? true;
        $loopMode = $data['loop_mode'] ?? true;
        
        if (empty($deviceId)) {
            jsonResponse(['success' => false, 'message' => 'ID appareil requis'], 400);
        }
        
        if (empty($presentationId)) {
            jsonResponse(['success' => false, 'message' => 'ID de présentation requis'], 400);
        }
        
        try {
            // Vérifier que l'appareil existe
            $stmt = $dbpdointranet->prepare("SELECT id, adresse_ip, adresse_ip_externe FROM appareils WHERE identifiant_unique = ?");
            $stmt->execute([$deviceId]);
            $appareil = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appareil) {
                jsonResponse(['success' => false, 'message' => 'Appareil non trouvé'], 404);
            }
            
            // Vérifier que la présentation existe
            $stmt = $dbpdointranet->prepare("SELECT id FROM presentations WHERE id = ?");
            $stmt->execute([$presentationId]);
            if (!$stmt->fetch()) {
                jsonResponse(['success' => false, 'message' => 'Présentation non trouvée'], 404);
            }
            
            // Créer une diffusion pour cet appareil
            $stmt = $dbpdointranet->prepare("
                INSERT INTO diffusions 
                (presentation_id, appareil_id, identifiant_appareil, lecture_automatique, mode_boucle, statut, priorite, date_creation)
                VALUES (?, ?, ?, ?, ?, 'active', 2, NOW())
                ON DUPLICATE KEY UPDATE
                    presentation_id = VALUES(presentation_id),
                    lecture_automatique = VALUES(lecture_automatique),
                    mode_boucle = VALUES(mode_boucle),
                    statut = 'active',
                    date_creation = NOW()
            ");
            
            $stmt->execute([
                $presentationId,
                $appareil['id'],
                $deviceId,
                $autoPlay ? 1 : 0,
                $loopMode ? 1 : 0
            ]);
            
            // Envoyer une commande pour lancer la présentation
            $stmt = $dbpdointranet->prepare("
                INSERT INTO commandes_distantes 
                (identifiant_appareil, commande, parametres, statut, date_creation, adresse_ip_cible)
                VALUES (?, 'assign_presentation', ?, 'en_attente', NOW(), ?)
            ");
            
            $parameters = json_encode([
                'presentation_id' => (int)$presentationId,
                'auto_play' => (bool)$autoPlay,
                'loop_mode' => (bool)$loopMode
            ]);
            
            $stmt->execute([
                $deviceId, 
                $parameters,
                $appareil['adresse_ip_externe'] ?? $appareil['adresse_ip'] ?? null
            ]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Présentation assignée et commande envoyée'
            ]);
            
        } catch (Exception $e) {
            logError('Erreur lors de l\'assignation de la présentation', ['error' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => 'Erreur lors de l\'assignation de la présentation'], 500);
        }
        break;
        
    case 'send_command_to_group':
        // Envoyer une commande à tous les appareils d'un groupe (même IP externe)
        $externalIP = $data['external_ip'] ?? '';
        $command = $data['command'] ?? '';
        $parameters = $data['parameters'] ?? [];
        
        if (empty($externalIP)) {
            jsonResponse(['success' => false, 'message' => 'Adresse IP externe requise'], 400);
        }
        
        if (empty($command)) {
            jsonResponse(['success' => false, 'message' => 'Commande requise'], 400);
        }
        
        // Valider la commande
        $valid_commands = ['play', 'pause', 'stop', 'restart', 'reboot'];
        if (!in_array($command, $valid_commands)) {
            jsonResponse(['success' => false, 'message' => 'Commande invalide pour un groupe'], 400);
        }
        
        try {
            // Récupérer tous les appareils avec cette IP externe
            $stmt = $dbpdointranet->prepare("
                SELECT id, identifiant_unique, nom 
                FROM appareils 
                WHERE adresse_ip_externe = ? 
                AND statut = 'actif'
            ");
            $stmt->execute([$externalIP]);
            $appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($appareils) === 0) {
                jsonResponse(['success' => false, 'message' => 'Aucun appareil trouvé avec cette IP externe'], 404);
            }
            
            // Envoyer la commande à chaque appareil
            $commandsCount = 0;
            foreach ($appareils as $appareil) {
                $stmt = $dbpdointranet->prepare("
                    INSERT INTO commandes_distantes 
                    (identifiant_appareil, commande, parametres, statut, date_creation, adresse_ip_cible)
                    VALUES (?, ?, ?, 'en_attente', NOW(), ?)
                ");
                
                $stmt->execute([
                    $appareil['identifiant_unique'],
                    $command,
                    json_encode($parameters),
                    $externalIP
                ]);
                
                $commandsCount++;
            }
            
            // Enregistrer un log d'activité
            $stmt = $dbpdointranet->prepare("
                INSERT INTO logs_activite 
                (type_action, message, details, adresse_ip, adresse_ip_externe, date_action)
                VALUES ('commande_distante', ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                "Commande groupée envoyée: {$command} à {$commandsCount} appareil(s)",
                json_encode([
                    'command' => $command,
                    'parameters' => $parameters,
                    'external_ip' => $externalIP,
                    'devices_count' => $commandsCount,
                    'devices' => array_column($appareils, 'nom')
                ]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            
            jsonResponse([
                'success' => true, 
                'message' => "Commande {$command} envoyée à {$commandsCount} appareil(s)",
                'devices_count' => $commandsCount
            ]);
            
        } catch (Exception $e) {
            logError('Erreur lors de l\'envoi de la commande groupée', ['error' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => 'Erreur lors de l\'envoi de la commande groupée'], 500);
        }
        break;
        
    default:
        jsonResponse(['success' => false, 'message' => 'Action non reconnue'], 400);
}
?>