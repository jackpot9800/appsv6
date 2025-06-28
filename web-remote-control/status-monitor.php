<?php
// status-monitor.php - Système de surveillance en temps réel des appareils Fire TV
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Device-ID, X-Device-Type');

// Gestion des requêtes OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuration de la base de données
require_once('dbpdointranet.php');
$dbpdointranet->exec("USE affichageDynamique");

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

// Récupérer la méthode HTTP et le chemin
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Router les requêtes
switch ($action) {
    case 'get_all_devices':
        // Récupérer tous les appareils avec leur statut
        try {
            $stmt = $dbpdointranet->query("
                SELECT 
                    a.*,
                    p.nom as presentation_courante_nom,
                    CASE 
                        WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'online'
                        WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'idle'
                        ELSE 'offline'
                    END as statut_connexion,
                    TIMESTAMPDIFF(SECOND, a.derniere_connexion, NOW()) as secondes_depuis_derniere_connexion
                FROM appareils a
                LEFT JOIN presentations p ON a.presentation_courante_id = p.id
                ORDER BY a.derniere_connexion DESC
            ");
            $appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Formater les données pour l'affichage
            foreach ($appareils as &$appareil) {
                $appareil['derniere_connexion_formatee'] = date('d/m/Y H:i:s', strtotime($appareil['derniere_connexion']));
                $appareil['uptime_formate'] = $appareil['uptime_secondes'] ? gmdate('H:i:s', $appareil['uptime_secondes']) : null;
                $appareil['capacites'] = json_decode($appareil['capacites'] ?? '[]', true);
            }
            
            jsonResponse(['success' => true, 'devices' => $appareils]);
        } catch (Exception $e) {
            logError("Erreur récupération appareils", ['error' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => 'Erreur lors de la récupération des appareils'], 500);
        }
        break;
        
    case 'get_device_status':
        // Récupérer le statut d'un appareil spécifique
        $deviceId = $_GET['device_id'] ?? '';
        
        if (empty($deviceId)) {
            jsonResponse(['success' => false, 'message' => 'ID appareil requis'], 400);
        }
        
        try {
            $stmt = $dbpdointranet->prepare("
                SELECT 
                    a.*,
                    p.nom as presentation_courante_nom,
                    CASE 
                        WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'online'
                        WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'idle'
                        ELSE 'offline'
                    END as statut_connexion,
                    TIMESTAMPDIFF(SECOND, a.derniere_connexion, NOW()) as secondes_depuis_derniere_connexion
                FROM appareils a
                LEFT JOIN presentations p ON a.presentation_courante_id = p.id
                WHERE a.identifiant_unique = ?
            ");
            $stmt->execute([$deviceId]);
            $appareil = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appareil) {
                jsonResponse(['success' => false, 'message' => 'Appareil non trouvé'], 404);
            }
            
            // Formater les données
            $appareil['derniere_connexion_formatee'] = date('d/m/Y H:i:s', strtotime($appareil['derniere_connexion']));
            $appareil['uptime_formate'] = $appareil['uptime_secondes'] ? gmdate('H:i:s', $appareil['uptime_secondes']) : null;
            $appareil['capacites'] = json_decode($appareil['capacites'] ?? '[]', true);
            
            jsonResponse(['success' => true, 'device' => $appareil]);
        } catch (Exception $e) {
            logError("Erreur récupération statut appareil", ['error' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => 'Erreur lors de la récupération du statut'], 500);
        }
        break;
        
    case 'get_statistics':
        // Récupérer les statistiques globales
        try {
            $stmt = $dbpdointranet->query("
                SELECT 
                    COUNT(*) as total_appareils,
                    SUM(CASE WHEN derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 1 ELSE 0 END) as appareils_en_ligne,
                    SUM(CASE WHEN statut_temps_reel = 'playing' THEN 1 ELSE 0 END) as appareils_en_diffusion,
                    COUNT(DISTINCT presentation_courante_id) as presentations_actives
                FROM appareils
                WHERE statut = 'actif'
            ");
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Récupérer les commandes en attente
            $stmt = $dbpdointranet->query("
                SELECT COUNT(*) as commandes_en_attente
                FROM commandes_distantes 
                WHERE statut = 'en_attente'
            ");
            $commandes = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['commandes_en_attente'] = $commandes['commandes_en_attente'];
            
            // Récupérer les présentations les plus diffusées
            $stmt = $dbpdointranet->query("
                SELECT 
                    p.id,
                    p.nom,
                    COUNT(DISTINCT a.id) as nombre_appareils
                FROM presentations p
                JOIN appareils a ON a.presentation_courante_id = p.id
                WHERE a.statut_temps_reel = 'playing'
                GROUP BY p.id, p.nom
                ORDER BY nombre_appareils DESC
                LIMIT 5
            ");
            $presentations_populaires = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse([
                'success' => true, 
                'statistics' => $stats,
                'popular_presentations' => $presentations_populaires
            ]);
        } catch (Exception $e) {
            logError("Erreur récupération statistiques", ['error' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => 'Erreur lors de la récupération des statistiques'], 500);
        }
        break;
        
    case 'force_restart':
        // Forcer le redémarrage d'un appareil
        $deviceId = $_GET['device_id'] ?? '';
        
        if (empty($deviceId)) {
            jsonResponse(['success' => false, 'message' => 'ID appareil requis'], 400);
        }
        
        try {
            // Vérifier si l'appareil existe
            $stmt = $dbpdointranet->prepare("SELECT id FROM appareils WHERE identifiant_unique = ?");
            $stmt->execute([$deviceId]);
            $appareil = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appareil) {
                jsonResponse(['success' => false, 'message' => 'Appareil non trouvé'], 404);
            }
            
            // Envoyer une commande de redémarrage
            $stmt = $dbpdointranet->prepare("
                INSERT INTO commandes_distantes 
                (identifiant_appareil, commande, parametres, statut, date_creation)
                VALUES (?, 'reboot', '{}', 'en_attente', NOW())
            ");
            $stmt->execute([$deviceId]);
            
            $commandId = $dbpdointranet->lastInsertId();
            
            // Enregistrer un log d'activité
            $stmt = $dbpdointranet->prepare("
                INSERT INTO logs_activite 
                (type_action, appareil_id, identifiant_appareil, message, details, adresse_ip, user_agent)
                VALUES ('maintenance', ?, ?, 'Redémarrage forcé', ?, ?, ?)
            ");
            $stmt->execute([
                $appareil['id'],
                $deviceId,
                json_encode(['command_id' => $commandId]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            jsonResponse([
                'success' => true, 
                'message' => 'Commande de redémarrage envoyée',
                'command_id' => $commandId
            ]);
        } catch (Exception $e) {
            logError("Erreur redémarrage appareil", ['error' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => 'Erreur lors de l\'envoi de la commande de redémarrage'], 500);
        }
        break;
        
    case 'check_offline_devices':
        // Vérifier les appareils hors ligne et envoyer des alertes
        try {
            $stmt = $dbpdointranet->query("
                SELECT 
                    a.*,
                    TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()) as minutes_offline
                FROM appareils a
                WHERE a.statut = 'actif'
                AND a.derniere_connexion < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                ORDER BY a.derniere_connexion ASC
            ");
            $appareils_hors_ligne = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $alertes = [];
            foreach ($appareils_hors_ligne as $appareil) {
                $alertes[] = [
                    'device_id' => $appareil['identifiant_unique'],
                    'name' => $appareil['nom'],
                    'minutes_offline' => $appareil['minutes_offline'],
                    'last_seen' => $appareil['derniere_connexion']
                ];
                
                // Enregistrer un log d'alerte
                $stmt = $dbpdointranet->prepare("
                    INSERT INTO logs_activite 
                    (type_action, appareil_id, identifiant_appareil, message, details, adresse_ip)
                    VALUES ('erreur', ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $appareil['id'],
                    $appareil['identifiant_unique'],
                    "Appareil hors ligne depuis {$appareil['minutes_offline']} minutes",
                    json_encode(['minutes_offline' => $appareil['minutes_offline']]),
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
            }
            
            jsonResponse([
                'success' => true, 
                'offline_devices' => $alertes,
                'count' => count($alertes)
            ]);
        } catch (Exception $e) {
            logError("Erreur vérification appareils hors ligne", ['error' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => 'Erreur lors de la vérification des appareils hors ligne'], 500);
        }
        break;
        
    case 'get_device_logs':
        // Récupérer les logs d'un appareil spécifique
        $deviceId = $_GET['device_id'] ?? '';
        $limit = intval($_GET['limit'] ?? 50);
        
        if (empty($deviceId)) {
            jsonResponse(['success' => false, 'message' => 'ID appareil requis'], 400);
        }
        
        try {
            $stmt = $dbpdointranet->prepare("
                SELECT * FROM logs_activite
                WHERE identifiant_appareil = ?
                ORDER BY date_action DESC
                LIMIT ?
            ");
            $stmt->execute([$deviceId, $limit]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Formater les données
            foreach ($logs as &$log) {
                $log['details'] = json_decode($log['details'] ?? '{}', true);
                $log['date_formatee'] = date('d/m/Y H:i:s', strtotime($log['date_action']));
            }
            
            jsonResponse(['success' => true, 'logs' => $logs]);
        } catch (Exception $e) {
            logError("Erreur récupération logs appareil", ['error' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => 'Erreur lors de la récupération des logs'], 500);
        }
        break;
        
    default:
        jsonResponse(['success' => false, 'message' => 'Action non reconnue'], 400);
}
?>