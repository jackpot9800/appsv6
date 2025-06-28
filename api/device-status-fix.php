<?php
// device-status-fix.php - Script pour corriger le statut des appareils
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
$action = $_GET['action'] ?? 'check';
$deviceId = $_GET['device_id'] ?? '';

// Traiter l'action demandée
switch ($action) {
    case 'check':
        // Vérifier le statut d'un appareil spécifique ou de tous les appareils
        try {
            if (!empty($deviceId)) {
                // Vérifier un appareil spécifique
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
                
                jsonResponse([
                    'success' => true,
                    'device' => $appareil,
                    'status_mismatch' => ($appareil['statut'] === 'actif' && $appareil['statut_connexion_calcule'] === 'online' && $appareil['statut_temps_reel'] !== 'online' && $appareil['statut_temps_reel'] !== 'playing')
                ]);
            } else {
                // Vérifier tous les appareils avec statut incohérent
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
                    'count' => count($appareils)
                ]);
            }
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur lors de la vérification du statut: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'fix':
        // Corriger le statut d'un appareil spécifique ou de tous les appareils
        try {
            if (!empty($deviceId)) {
                // Corriger un appareil spécifique
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
                    VALUES ('maintenance', ?, 'Correction manuelle du statut', ?, ?)
                ");
                $stmt->execute([
                    $deviceId,
                    json_encode([
                        'action' => 'fix_status',
                        'old_status' => 'offline/inactif',
                        'new_status' => 'online/actif',
                        'timestamp' => date('Y-m-d H:i:s')
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                jsonResponse([
                    'success' => true,
                    'message' => 'Statut de l\'appareil corrigé avec succès'
                ]);
            } else {
                // Corriger tous les appareils avec statut incohérent
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
                        "Correction automatique du statut de {$count} appareil(s)",
                        json_encode([
                            'action' => 'fix_status_batch',
                            'devices_count' => $count,
                            'timestamp' => date('Y-m-d H:i:s')
                        ]),
                        $_SERVER['REMOTE_ADDR'] ?? ''
                    ]);
                }
                
                jsonResponse([
                    'success' => true,
                    'message' => "Statut corrigé pour {$count} appareil(s)"
                ]);
            }
        } catch (Exception $e) {
            jsonResponse(['error' => 'Erreur lors de la correction du statut: ' . $e->getMessage()], 500);
        }
        break;
        
    default:
        jsonResponse(['error' => 'Action non reconnue'], 400);
}
?>