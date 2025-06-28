<?php
// Désactiver complètement l'affichage des erreurs pour l'API
error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Headers CORS améliorés
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-ID, X-Device-Type, X-Enrollment-Token, X-Device-Registered, X-App-Version, User-Agent, X-Device-Name, X-Local-IP, X-External-IP');

// Gestion des requêtes OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Fonction pour générer une réponse JSON propre
function jsonResponse($data, $status = 200) {
    // S'assurer qu'aucune sortie n'a été envoyée avant
    if (ob_get_level()) {
        ob_clean();
    }
    
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Fonction pour logger les erreurs sans les afficher
function logError($message, $context = []) {
    $logMessage = date('Y-m-d H:i:s') . ' - ' . $message;
    if (!empty($context)) {
        $logMessage .= ' - Context: ' . json_encode($context);
    }
    error_log($logMessage);
}

// Récupérer la méthode HTTP et le chemin
$method = $_SERVER['REQUEST_METHOD'];

// Récupérer le chemin de la requête de manière plus robuste
$path = '';
if (isset($_SERVER['PATH_INFO'])) {
    $path = $_SERVER['PATH_INFO'];
} elseif (isset($_SERVER['REQUEST_URI'])) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $basePath = dirname($scriptName);
    
    // Gérer le cas où le script est dans un sous-dossier
    if ($basePath !== '/') {
        $path = substr($uri, strlen($basePath));
    } else {
        $path = $uri;
    }
    
    // Enlever le nom du script si présent
    $scriptBasename = basename($scriptName);
    if (strpos($path, $scriptBasename) === 1) {
        $path = substr($path, strlen($scriptBasename) + 1);
    }
}

$path = trim($path, '/');

// Log pour debug (sera dans les logs du serveur, pas affiché)
logError("API Request", [
    'method' => $method,
    'path' => $path,
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'script_name' => $_SERVER['SCRIPT_NAME'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'device_id' => $_SERVER['HTTP_X_DEVICE_ID'] ?? ''
]);

// Si pas de chemin, retourner les informations de base
if (empty($path)) {
    jsonResponse([
        'status' => 'API is running',
        'version' => '2.0',
        'timestamp' => date('c'),
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
        'endpoints' => [
            'GET /version' => 'Get API version',
            'GET /presentations' => 'List all presentations',
            'GET /presentation/{id}' => 'Get presentation details',
            'POST /device/register' => 'Register a device',
            'POST /appareil/enregistrer' => 'Enregistrer appareil (alias)',
            'GET /device/assigned-presentation' => 'Get assigned presentation for device',
            'GET /appareil/presentation-assignee' => 'Présentation assignée (alias)',
            'GET /device/default-presentation' => 'Get default presentation for device',
            'GET /appareil/presentation-defaut' => 'Présentation par défaut (alias)'
        ]
    ]);
}

// Connexion à la base de données
try {
    require_once('dbpdointranet.php');
    
    // Vérifier si la connexion a réussi
    if (!$dbpdointranet) {
        throw new Exception("Connexion à la base de données échouée");
    }
    
    // Pas besoin de sélectionner la base de données car déjà fait dans dbpdointranet.php
} catch (Exception $e) {
    logError("Database connection failed", ['error' => $e->getMessage()]);
    jsonResponse(['error' => 'Connexion à la base de données échouée: ' . $e->getMessage()], 500);
}

// Mapper les chemins pour la compatibilité entre les deux versions d'API
$pathMapping = [
    'device/register' => 'appareil/enregistrer',
    'appareil/enregistrer' => 'appareil/enregistrer',
    'device/assigned-presentation' => 'appareil/presentation-assignee',
    'appareil/presentation-assignee' => 'appareil/presentation-assignee',
    'device/default-presentation' => 'appareil/presentation-defaut',
    'appareil/presentation-defaut' => 'appareil/presentation-defaut',
    'device/heartbeat' => 'appareil/heartbeat',
    'appareil/heartbeat' => 'appareil/heartbeat'
];

// Vérifier si le chemin demandé a un mapping
if (isset($pathMapping[$path])) {
    $path = $pathMapping[$path];
}

// Router les requêtes
switch ($path) {
    case 'version':
        jsonResponse([
            'version' => '2.0',
            'api_status' => 'running',
            'database' => 'affichageDynamique',
            'timestamp' => date('c'),
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'php_version' => PHP_VERSION,
            'features' => [
                'presentations_avancees',
                'gestion_appareils',
                'diffusions_programmees',
                'presentations_defaut',
                'lecture_automatique',
                'mode_boucle',
                'logs_activite',
                'debug_complet'
            ]
        ]);
        break;
        
    case 'presentations':
        if ($method === 'GET') {
            try {
                $stmt = $dbpdointranet->query("
                    SELECT 
                        p.*,
                        COUNT(pm.media_id) as slide_count,
                        SUM(pm.duree_affichage) as duree_totale_calculee
                    FROM presentations p
                    LEFT JOIN presentation_medias pm ON p.id = pm.presentation_id
                    WHERE p.statut = 'actif'
                    GROUP BY p.id
                    ORDER BY p.date_creation DESC
                ");
                $presentations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Nettoyer et valider les données
                foreach ($presentations as &$pres) {
                    $pres['slide_count'] = (int)($pres['slide_count'] ?? 0);
                    $pres['duree_totale'] = (int)($pres['duree_totale_calculee'] ?? 0);
                    
                    // Support pour les deux APIs - mapping des noms de colonnes
                    $pres['name'] = $pres['nom'] ?? $pres['name'] ?? 'Présentation sans nom';
                    $pres['created_at'] = $pres['date_creation'] ?? $pres['created_at'] ?? date('c');
                    
                    if (empty($pres['description'])) {
                        $pres['description'] = 'Aucune description disponible';
                    }
                    
                    // Générer l'URL de prévisualisation
                    $pres['preview_url'] = sprintf(
                        'http://%s/mods/livetv/chromecast_display.php?presentation=%d&key=android_%s',
                        $_SERVER['HTTP_HOST'],
                        $pres['id'],
                        uniqid()
                    );
                    
                    // Nettoyer les champs non nécessaires
                    unset($pres['duree_totale_calculee']);
                }
                
                logError("Presentations fetched", ['count' => count($presentations)]);
                jsonResponse(['presentations' => $presentations]);
                
            } catch (PDOException $e) {
                logError("Database error in presentations", ['error' => $e->getMessage()]);
                jsonResponse(['error' => 'Erreur base de données lors de la récupération des présentations'], 500);
            }
        } else {
            jsonResponse(['error' => 'Méthode non autorisée'], 405);
        }
        break;

    case 'appareil/enregistrer':
        if ($method === 'POST') {
            try {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    jsonResponse(['error' => 'JSON invalide dans le corps de la requête'], 400);
                }
                
                // Validation des champs requis
                if (empty($data['device_id']) || empty($data['name'])) {
                    jsonResponse(['error' => 'Champs requis manquants: device_id et name'], 400);
                }
                
                // Récupérer les informations supplémentaires des headers
                $deviceName = $_SERVER['HTTP_X_DEVICE_NAME'] ?? $data['name'];
                $deviceType = $_SERVER['HTTP_X_DEVICE_TYPE'] ?? $data['type'] ?? 'firetv';
                $localIP = $_SERVER['HTTP_X_LOCAL_IP'] ?? null;
                $externalIP = $_SERVER['HTTP_X_EXTERNAL_IP'] ?? null;
                $appVersion = $_SERVER['HTTP_X_APP_VERSION'] ?? $data['version'] ?? '1.0.0';
                
                // Si pas d'IP externe dans les headers, utiliser l'IP de la requête
                if (empty($externalIP)) {
                    $externalIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
                }
                
                // Enregistrer ou mettre à jour l'appareil
                $stmt = $dbpdointranet->prepare("
                    INSERT INTO appareils 
                    (nom, type_appareil, identifiant_unique, adresse_ip, adresse_ip_externe, adresse_ip_locale, capacites, version_app, statut)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'actif')
                    ON DUPLICATE KEY UPDATE 
                        derniere_connexion = NOW(),
                        nom = VALUES(nom),
                        adresse_ip = VALUES(adresse_ip),
                        adresse_ip_externe = VALUES(adresse_ip_externe),
                        adresse_ip_locale = VALUES(adresse_ip_locale),
                        capacites = VALUES(capacites),
                        version_app = VALUES(version_app),
                        statut = 'actif',
                        statut_temps_reel = 'online'
                ");
                
                $capabilities = json_encode($data['capabilities'] ?? []);
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                
                $stmt->execute([
                    $deviceName, 
                    $deviceType, 
                    $data['device_id'], 
                    $ip,
                    $externalIP,
                    $localIP,
                    $capabilities,
                    $appVersion
                ]);
                
                // Récupérer l'ID de l'appareil
                $appareilId = $dbpdointranet->lastInsertId();
                if (!$appareilId) {
                    // L'appareil existait déjà, récupérer son ID
                    $stmt = $dbpdointranet->prepare("SELECT id FROM appareils WHERE identifiant_unique = ?");
                    $stmt->execute([$data['device_id']]);
                    $appareilId = $stmt->fetchColumn();
                }
                
                // Insérer un log de connexion
                $stmt = $dbpdointranet->prepare("
                    INSERT INTO logs_activite 
                    (type_action, appareil_id, identifiant_appareil, message, details, adresse_ip, adresse_ip_externe)
                    VALUES ('connexion', ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $appareilId,
                    $data['device_id'],
                    "Appareil enregistré avec succès",
                    json_encode([
                        'name' => $deviceName,
                        'type' => $deviceType,
                        'local_ip' => $localIP,
                        'external_ip' => $externalIP,
                        'app_version' => $appVersion
                    ]),
                    $ip,
                    $externalIP
                ]);
                
                logError("Device registered", [
                    'device_id' => $data['device_id'],
                    'name' => $deviceName,
                    'type' => $deviceType
                ]);
                
                jsonResponse([
                    'success' => true,
                    'message' => 'Appareil enregistré avec succès',
                    'device_id' => $data['device_id'],
                    'token' => 'enrolled_' . uniqid(),
                    'server_time' => date('Y-m-d H:i:s')
                ]);
                
            } catch (PDOException $e) {
                logError("Database error in device registration", ['error' => $e->getMessage()]);
                jsonResponse(['error' => 'Erreur base de données lors de l\'enregistrement: ' . $e->getMessage()], 500);
            }
        } else {
            jsonResponse(['error' => 'Méthode non autorisée'], 405);
        }
        break;

    case 'appareil/heartbeat':
        if ($method === 'POST') {
            try {
                $deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
                
                if (empty($deviceId)) {
                    jsonResponse(['error' => 'ID appareil requis'], 400);
                }

                $input = file_get_contents('php://input');
                $statusData = json_decode($input, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    jsonResponse(['error' => 'JSON invalide'], 400);
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

                // Mettre à jour le statut de l'appareil
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
                        adresse_ip = ?,
                        adresse_ip_externe = ?,
                        adresse_ip_locale = ?,
                        nom = COALESCE(?, nom)
                    WHERE identifiant_unique = ?
                ");

                $stmt->execute([
                    $statusData['status'] ?? 'online',
                    $statusData['current_presentation_id'] ?? null,
                    $statusData['current_presentation_name'] ?? null,
                    $statusData['current_slide_index'] ?? null,
                    $statusData['total_slides'] ?? null,
                    $statusData['is_looping'] ? 1 : 0,
                    $statusData['auto_play'] ? 1 : 0,
                    $statusData['uptime_seconds'] ?? null,
                    $statusData['memory_usage'] ?? null,
                    $statusData['wifi_strength'] ?? null,
                    $appVersion ?? $statusData['app_version'] ?? null,
                    $statusData['error_message'] ?? null,
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $externalIP,
                    $localIP,
                    $deviceName,
                    $deviceId
                ]);

                // Insérer un log d'activité
                $stmt = $dbpdointranet->prepare("
                    INSERT INTO logs_activite 
                    (type_action, identifiant_appareil, message, details, adresse_ip, adresse_ip_externe)
                    VALUES ('connexion', ?, 'Heartbeat reçu', ?, ?, ?)
                ");
                $stmt->execute([
                    $deviceId,
                    json_encode($statusData),
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $externalIP
                ]);

                // Récupérer les commandes en attente
                $stmt = $dbpdointranet->prepare("
                    SELECT * FROM commandes_distantes 
                    WHERE identifiant_appareil = ? 
                    AND statut = 'en_attente'
                    ORDER BY priorite DESC, date_creation ASC
                    LIMIT 5
                ");
                $stmt->execute([$deviceId]);
                $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                jsonResponse([
                    'success' => true, 
                    'message' => 'Heartbeat reçu',
                    'server_time' => date('Y-m-d H:i:s'),
                    'commands' => $commandes,
                    'device_id' => $deviceId,
                    'external_ip' => $externalIP,
                    'local_ip' => $localIP
                ]);

            } catch (Exception $e) {
                logError("Erreur heartbeat", ['error' => $e->getMessage()]);
                jsonResponse(['error' => 'Erreur lors du traitement du heartbeat: ' . $e->getMessage()], 500);
            }
        } else {
            jsonResponse(['error' => 'Méthode non autorisée'], 405);
        }
        break;

    case 'appareil/presentation-assignee':
        if ($method === 'GET') {
            try {
                $deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
                
                if (empty($deviceId)) {
                    jsonResponse(['error' => 'ID appareil requis'], 400);
                }
                
                logError("Checking assigned presentation", ['device_id' => $deviceId]);
                
                // Chercher une diffusion active pour cet appareil
                $stmt = $dbpdointranet->prepare("
                    SELECT 
                        d.*,
                        p.nom as presentation_name, 
                        p.description as presentation_description
                    FROM diffusions d
                    JOIN presentations p ON d.presentation_id = p.id
                    WHERE d.identifiant_appareil = ? 
                    AND d.statut = 'active'
                    AND (d.date_fin IS NULL OR d.date_fin > NOW())
                    AND (d.date_debut IS NULL OR d.date_debut <= NOW())
                    ORDER BY d.priorite DESC, d.date_creation DESC
                    LIMIT 1
                ");
                $stmt->execute([$deviceId]);
                $assignedPresentation = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($assignedPresentation) {
                    // Convertir les noms de colonnes pour compatibilité
                    $result = [
                        'id' => $assignedPresentation['id'],
                        'presentation_id' => $assignedPresentation['presentation_id'],
                        'presentation_name' => $assignedPresentation['presentation_name'],
                        'presentation_description' => $assignedPresentation['presentation_description'],
                        'auto_play' => (bool)$assignedPresentation['lecture_automatique'],
                        'loop_mode' => (bool)$assignedPresentation['mode_boucle'],
                        'created_at' => $assignedPresentation['date_creation']
                    ];
                    
                    logError("Assigned presentation found", [
                        'device_id' => $deviceId,
                        'presentation_id' => $result['presentation_id']
                    ]);
                    
                    jsonResponse(['assigned_presentation' => $result]);
                } else {
                    logError("No assigned presentation", ['device_id' => $deviceId]);
                    jsonResponse(['assigned_presentation' => null]);
                }
                
            } catch (PDOException $e) {
                logError("Database error in assigned presentation", ['error' => $e->getMessage()]);
                jsonResponse(['error' => 'Erreur base de données'], 500);
            }
        } else {
            jsonResponse(['error' => 'Méthode non autorisée'], 405);
        }
        break;

    case 'appareil/presentation-defaut':
        if ($method === 'GET') {
            try {
                $deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
                
                if (empty($deviceId)) {
                    jsonResponse(['error' => 'ID appareil requis'], 400);
                }
                
                logError("Checking default presentation", ['device_id' => $deviceId]);
                
                // Chercher la présentation par défaut de cet appareil
                $stmt = $dbpdointranet->prepare("
                    SELECT 
                        a.presentation_defaut_id,
                        a.identifiant_unique,
                        a.nom as nom_appareil,
                        p.id as presentation_id,
                        p.nom as presentation_name, 
                        p.description as presentation_description,
                        COUNT(pm.media_id) as slide_count
                    FROM appareils a
                    LEFT JOIN presentations p ON a.presentation_defaut_id = p.id
                    LEFT JOIN presentation_medias pm ON p.id = pm.presentation_id
                    WHERE a.identifiant_unique = ? 
                    AND a.presentation_defaut_id > 0
                    AND p.statut = 'actif'
                    GROUP BY a.id, p.id
                ");
                $stmt->execute([$deviceId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && $result['presentation_defaut_id'] > 0 && $result['presentation_id']) {
                    $defaultPresentation = [
                        'presentation_id' => (int)$result['presentation_id'],
                        'presentation_name' => $result['presentation_name'],
                        'presentation_description' => $result['presentation_description'] ?: 'Présentation par défaut',
                        'slide_count' => (int)($result['slide_count'] ?: 0),
                        'is_default' => true
                    ];
                    
                    logError("Default presentation found", ['default_presentation' => $defaultPresentation]);
                    jsonResponse(['default_presentation' => $defaultPresentation]);
                } else {
                    logError("No default presentation", ['device_id' => $deviceId]);
                    jsonResponse(['default_presentation' => null]);
                }
                
            } catch (PDOException $e) {
                logError("Database error in default presentation", ['error' => $e->getMessage()]);
                jsonResponse(['error' => 'Erreur base de données'], 500);
            }
        } else {
            jsonResponse(['error' => 'Méthode non autorisée'], 405);
        }
        break;

    case 'appareil/commandes':
        if ($method === 'GET') {
            try {
                $deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
                
                if (empty($deviceId)) {
                    jsonResponse(['error' => 'ID appareil requis'], 400);
                }

                // Récupérer les commandes en attente pour cet appareil
                $stmt = $dbpdointranet->prepare("
                    SELECT * FROM commandes_distantes 
                    WHERE identifiant_appareil = ? 
                    AND statut = 'en_attente'
                    ORDER BY priorite DESC, date_creation ASC
                ");
                $stmt->execute([$deviceId]);
                $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);

                jsonResponse([
                    'success' => true,
                    'commands' => $commands,
                    'server_time' => date('Y-m-d H:i:s')
                ]);

            } catch (Exception $e) {
                logError("Erreur récupération commandes", ['error' => $e->getMessage()]);
                jsonResponse(['error' => 'Erreur lors de la récupération des commandes'], 500);
            }
        } else {
            jsonResponse(['error' => 'Méthode non autorisée'], 405);
        }
        break;

    default:
        // Vérifier si c'est une requête de présentation spécifique
        if (preg_match('/^presentation\/(\d+)$/', $path, $matches)) {
            if ($method === 'GET') {
                $presentationId = (int)$matches[1];
                
                try {
                    // Récupérer les informations de la présentation
                    $stmt = $dbpdointranet->prepare("
                        SELECT 
                            p.*,
                            COUNT(pm.media_id) as slide_count
                        FROM presentations p
                        LEFT JOIN presentation_medias pm ON p.id = pm.presentation_id
                        WHERE p.id = ? AND p.statut = 'actif'
                        GROUP BY p.id
                    ");
                    $stmt->execute([$presentationId]);
                    $presentation = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$presentation) {
                        logError("Presentation not found", ['id' => $presentationId]);
                        jsonResponse(['error' => 'Présentation non trouvée'], 404);
                    }
                    
                    // Récupérer les médias avec leurs durées
                    $stmt = $dbpdointranet->prepare("
                        SELECT 
                            m.id,
                            m.nom as name,
                            m.titre as title,
                            m.type_media,
                            m.chemin_fichier as image_path,
                            m.chemin_fichier as media_path,
                            pm.duree_affichage as duration,
                            pm.effet_transition as transition_type,
                            pm.ordre_affichage as position,
                            m.date_creation as created_at
                        FROM presentation_medias pm
                        JOIN medias m ON pm.media_id = m.id
                        WHERE pm.presentation_id = ? AND m.statut = 'actif'
                        ORDER BY pm.ordre_affichage ASC, m.id ASC
                    ");
                    $stmt->execute([$presentationId]);
                    $slides = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Traiter les slides
                    $validSlides = [];
                    foreach ($slides as $slide) {
                        $imagePath = $slide['media_path'] ?? $slide['image_path'] ?? '';
                        
                        // Construire l'URL de l'image
                        if (!empty($imagePath)) {
                            $slide['image_url'] = sprintf(
                                'http://%s/mods/livetv/%s',
                                $_SERVER['HTTP_HOST'],
                                $imagePath
                            );
                        } else {
                            $slide['image_url'] = sprintf(
                                'http://%s/mods/livetv/assets/placeholder.jpg',
                                $_SERVER['HTTP_HOST']
                            );
                        }
                        
                        // Valider la durée
                        $duration = (int)($slide['duration'] ?? 5);
                        if ($duration < 1) $duration = 5;
                        if ($duration > 300) $duration = 300;
                        
                        $processedSlide = [
                            'id' => (int)$slide['id'],
                            'name' => $slide['name'] ?? $slide['title'] ?? "Slide {$slide['id']}",
                            'title' => $slide['title'] ?? $slide['name'] ?? "Slide {$slide['id']}",
                            'image_path' => $imagePath,
                            'media_path' => $slide['media_path'] ?? $imagePath,
                            'image_url' => $slide['image_url'],
                            'duration' => $duration,
                            'transition_type' => $slide['transition_type'] ?? 'fade',
                            'position' => (int)($slide['position'] ?? 0),
                            'created_at' => $slide['created_at']
                        ];
                        
                        $validSlides[] = $processedSlide;
                    }
                    
                    // Préparer la réponse
                    $presentation['slide_count'] = count($validSlides);
                    $presentation['slides'] = $validSlides;
                    $presentation['description'] = $presentation['description'] ?? 'Aucune description disponible';
                    
                    // Convertir les noms de colonnes pour compatibilité
                    $presentation['name'] = $presentation['nom'] ?? $presentation['name'] ?? 'Présentation';
                    $presentation['created_at'] = $presentation['date_creation'] ?? $presentation['created_at'] ?? date('c');
                    
                    // Générer l'URL de prévisualisation
                    $presentation['preview_url'] = sprintf(
                        'http://%s/mods/livetv/chromecast_display.php?presentation=%d&key=android_%s',
                        $_SERVER['HTTP_HOST'],
                        $presentation['id'],
                        uniqid()
                    );
                    
                    logError("Presentation fetched", [
                        'id' => $presentationId,
                        'name' => $presentation['name'],
                        'slides_count' => count($validSlides)
                    ]);
                    
                    jsonResponse(['presentation' => $presentation]);
                    
                } catch (PDOException $e) {
                    logError("Database error in presentation fetch", [
                        'id' => $presentationId,
                        'error' => $e->getMessage()
                    ]);
                    jsonResponse(['error' => 'Erreur base de données'], 500);
                }
            } else {
                jsonResponse(['error' => 'Méthode non autorisée'], 405);
            }
        }
        else {
            // Endpoint non trouvé
            jsonResponse([
                'error' => 'Endpoint non trouvé',
                'requested_path' => $path,
                'method' => $method,
                'available_endpoints' => [
                    'GET /' => 'Informations API',
                    'GET /version' => 'Version API',
                    'GET /presentations' => 'Liste des présentations',
                    'GET /presentation/{id}' => 'Détails présentation',
                    'POST /device/register' => 'Register a device',
                    'POST /appareil/enregistrer' => 'Enregistrer appareil (alias)',
                    'GET /device/assigned-presentation' => 'Get assigned presentation for device',
                    'GET /appareil/presentation-assignee' => 'Présentation assignée (alias)',
                    'GET /device/default-presentation' => 'Get default presentation for device',
                    'GET /appareil/presentation-defaut' => 'Présentation par défaut (alias)',
                    'POST /device/heartbeat' => 'Send device heartbeat',
                    'POST /appareil/heartbeat' => 'Envoyer heartbeat (alias)',
                    'GET /device/commands' => 'Get pending commands',
                    'GET /appareil/commandes' => 'Récupérer commandes (alias)'
                ]
            ], 404);
        }
}
?>