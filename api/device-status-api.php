<?php
// device-status-api.php - API pour vérifier et corriger le statut des appareils
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Fonction pour générer une réponse JSON
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Connexion à la base de données
try {
    require_once('dbpdointranet.php');
    $dbpdointranet->exec("USE affichageDynamique");
} catch (Exception $e) {
    jsonResponse(['error' => 'Erreur de connexion à la base de données: ' . $e->getMessage()], 500);
}

// Récupérer l'action demandée
$action = $_GET['action'] ?? '';
$deviceId = $_GET['device_id'] ?? '';

// Traiter l'action demandée
switch ($action) {
    case 'get_status':
        // Récupérer le statut d'un appareil
        if (empty($deviceId)) {
            jsonResponse(['error' => 'ID appareil requis'], 400);
        }
        
        try {
            $stmt = $dbpdointranet->prepare("
                SELECT 
                    a.*,
                    TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()) as minutes_depuis_derniere_connexion,
                    CASE 
                        WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'online'
                        WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'idle'
                        ELSE 'offline'
                    END as statut_connexion_calcule
                FROM appareils a
                WHERE a.identifiant_unique = ?
            ");
            $stmt->execute([$deviceId]);
            $appareil = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appareil) {
                jsonResponse(['error' => 'Appareil non trouvé'], 404);
            }
            
            // Vérifier si le statut est incohérent
            $hasIssue = ($appareil['statut'] === 'actif' && 
                         $appareil['statut_connexion_calcule'] === 'online' && 
                         $appareil['statut_temps_reel'] !== 'online' && 
                         $appareil['statut_temps_reel'] !== 'playing');
            
            jsonResponse([
                'success' => true,
                'device' => $appareil,
                'has_issue' => $hasIssue,
                'server_time' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur lors de la récupération du statut: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'fix_status':
        // Corriger le statut d'un appareil
        if (empty($deviceId)) {
            jsonResponse(['error' => 'ID appareil requis'], 400);
        }
        
        try {
            $stmt = $dbpdointranet->prepare("
                UPDATE appareils 
                SET 
                    statut_temps_reel = 'online',
                    statut = 'actif'
                WHERE identifiant_unique = ?
                AND derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            ");
            $stmt->execute([$deviceId]);
            
            if ($stmt->rowCount() === 0) {
                jsonResponse(['error' => 'Appareil non trouvé ou déjà à jour'], 404);
            }
            
            // Enregistrer un log d'activité
            $stmt = $dbpdointranet->prepare("
                INSERT INTO logs_activite 
                (type_action, identifiant_appareil, message, details, adresse_ip)
                VALUES ('maintenance', ?, 'Correction du statut via API', ?, ?)
            ");
            $stmt->execute([
                $deviceId,
                json_encode([
                    'action' => 'fix_status_api',
                    'old_status' => 'offline/inactif',
                    'new_status' => 'online/actif',
                    'timestamp' => date('Y-m-d H:i:s')
                ]),
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Statut de l\'appareil corrigé avec succès',
                'server_time' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur lors de la correction du statut: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'get_all_issues':
        // Récupérer tous les appareils avec statut incohérent
        try {
            $stmt = $dbpdointranet->query("
                SELECT 
                    a.*,
                    TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()) as minutes_depuis_derniere_connexion,
                    CASE 
                        WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'online'
                        WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'idle'
                        ELSE 'offline'
                    END as statut_connexion_calcule
                FROM appareils a
                WHERE a.statut = 'actif'
                AND a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                AND (a.statut_temps_reel = 'offline' OR a.statut_temps_reel IS NULL)
                ORDER BY a.derniere_connexion DESC
            ");
            $appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse([
                'success' => true,
                'devices_with_issues' => $appareils,
                'count' => count($appareils),
                'server_time' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur lors de la récupération des appareils: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'fix_all_issues':
        // Corriger tous les appareils avec statut incohérent
        try {
            $stmt = $dbpdointranet->query("
                UPDATE appareils 
                SET 
                    statut_temps_reel = 'online',
                    statut = 'actif'
                WHERE statut = 'actif'
                AND derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                AND (statut_temps_reel = 'offline' OR statut_temps_reel IS NULL)
            ");
            
            $count = $stmt->rowCount();
            
            // Enregistrer un log d'activité
            if ($count > 0) {
                $stmt = $dbpdointranet->prepare("
                    INSERT INTO logs_activite 
                    (type_action, message, details, adresse_ip)
                    VALUES ('maintenance', ?, ?, ?)
                ");
                $stmt->execute([
                    "Correction automatique du statut de {$count} appareil(s) via API",
                    json_encode([
                        'action' => 'fix_all_status_api',
                        'devices_count' => $count,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
            }
            
            jsonResponse([
                'success' => true,
                'message' => "Statut corrigé pour {$count} appareil(s)",
                'count' => $count,
                'server_time' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur lors de la correction des statuts: ' . $e->getMessage()], 500);
        }
        break;
        
    default:
        jsonResponse([
            'error' => 'Action non reconnue',
            'available_actions' => [
                'get_status' => 'Récupérer le statut d\'un appareil (device_id requis)',
                'fix_status' => 'Corriger le statut d\'un appareil (device_id requis)',
                'get_all_issues' => 'Récupérer tous les appareils avec statut incohérent',
                'fix_all_issues' => 'Corriger tous les appareils avec statut incohérent'
            ]
        ], 400);
}
?>