<?php
// device-status-check.php - Interface web pour vérifier et corriger le statut des appareils

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Connexion à la base de données
require_once('dbpdointranet.php');
$dbpdointranet->exec("USE affichageDynamique");

// Récupérer les appareils avec statut incohérent
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
    AND a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ORDER BY a.derniere_connexion DESC
");
$appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Traiter la demande de correction
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'fix_all') {
        try {
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
                    "Correction manuelle du statut de {$count} appareil(s)",
                    json_encode([
                        'action' => 'fix_status_batch',
                        'devices_count' => $count,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
            }
            
            $message = "Statut corrigé pour {$count} appareil(s)";
            $messageType = 'success';
            
            // Rediriger pour actualiser la liste
            header('Location: device-status-check.php?success=' . urlencode($message));
            exit;
        } catch (Exception $e) {
            $message = 'Erreur lors de la correction du statut: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'fix_device' && !empty($_POST['device_id'])) {
        try {
            $deviceId = $_POST['device_id'];
            
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
                $message = 'Appareil non trouvé ou déjà à jour';
                $messageType = 'warning';
            } else {
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
                
                $message = 'Statut de l\'appareil corrigé avec succès';
                $messageType = 'success';
                
                // Rediriger pour actualiser la liste
                header('Location: device-status-check.php?success=' . urlencode($message));
                exit;
            }
        } catch (Exception $e) {
            $message = 'Erreur lors de la correction du statut: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'configure_auto_fix') {
        try {
            $enabled = isset($_POST['auto_fix_enabled']);
            
            // Vérifier si l'événement existe déjà
            $stmt = $dbpdointranet->query("
                SELECT * FROM information_schema.EVENTS 
                WHERE EVENT_SCHEMA = 'affichageDynamique' 
                AND EVENT_NAME = 'auto_fix_device_status'
            ");
            $eventExists = $stmt->rowCount() > 0;
            
            if ($enabled) {
                // Créer ou remplacer l'événement
                $dbpdointranet->exec("
                    CREATE EVENT IF NOT EXISTS auto_fix_device_status
                    ON SCHEDULE EVERY 1 MINUTE
                    DO
                        UPDATE appareils 
                        SET 
                            statut_temps_reel = 'online',
                            statut = 'actif'
                        WHERE statut = 'actif'
                        AND derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                        AND (statut_temps_reel = 'offline' OR statut_temps_reel IS NULL)
                ");
                
                // Activer l'événement s'il existe déjà
                if ($eventExists) {
                    $dbpdointranet->exec("ALTER EVENT auto_fix_device_status ENABLE");
                }
                
                $message = 'Correction automatique des statuts activée';
                $messageType = 'success';
            } else {
                // Désactiver l'événement
                if ($eventExists) {
                    $dbpdointranet->exec("ALTER EVENT auto_fix_device_status DISABLE");
                    
                    $message = 'Correction automatique des statuts désactivée';
                    $messageType = 'success';
                } else {
                    $message = 'L\'événement de correction automatique n\'existe pas';
                    $messageType = 'warning';
                }
            }
        } catch (Exception $e) {
            $message = 'Erreur lors de la configuration de la correction automatique: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Récupérer le message de succès de l'URL
if (empty($message) && isset($_GET['success'])) {
    $message = $_GET['success'];
    $messageType = 'success';
}

// Vérifier si la correction automatique est activée
$autoFixEnabled = false;
try {
    $stmt = $dbpdointranet->query("
        SELECT * FROM information_schema.EVENTS 
        WHERE EVENT_SCHEMA = 'affichageDynamique' 
        AND EVENT_NAME = 'auto_fix_device_status'
        AND STATUS = 'ENABLED'
    ");
    $autoFixEnabled = $stmt->rowCount() > 0;
} catch (Exception $e) {
    // Ignorer l'erreur
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification du statut des appareils</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        
        .status-online { background-color: #10b981; }
        .status-idle { background-color: #f59e0b; }
        .status-offline { background-color: #6b7280; }
        .status-playing { background-color: #3b82f6; }
        .status-paused { background-color: #f59e0b; }
        .status-error { background-color: #ef4444; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">
                <i class="fas fa-heartbeat text-red-600"></i>
                Vérification du statut des appareils
            </h1>
            
            <div class="flex space-x-4">
                <a href="index.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-home mr-2"></i>
                    Accueil
                </a>
                
                <a href="device-list.php" class="bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-tv mr-2"></i>
                    Liste des appareils
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : ($messageType === 'warning' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') ?>">
                <div class="flex items-center">
                    <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : ($messageType === 'warning' ? 'fa-exclamation-circle' : 'fa-exclamation-triangle') ?> mr-2"></i>
                    <p><?= htmlspecialchars($message) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Configuration de la correction automatique -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-cogs text-blue-600"></i>
                Configuration de la correction automatique
            </h2>
            
            <form action="" method="post" class="space-y-4">
                <input type="hidden" name="action" value="configure_auto_fix">
                
                <div class="flex items-center">
                    <input type="checkbox" name="auto_fix_enabled" id="auto_fix_enabled" class="mr-2" <?= $autoFixEnabled ? 'checked' : '' ?>>
                    <label for="auto_fix_enabled" class="text-gray-700">
                        Activer la correction automatique des statuts
                    </label>
                </div>
                
                <p class="text-sm text-gray-600">
                    Lorsque cette option est activée, le système vérifie automatiquement toutes les minutes si des appareils
                    ont un statut incohérent et les corrige automatiquement.
                </p>
                
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-save mr-2"></i>
                    Enregistrer la configuration
                </button>
            </form>
        </div>

        <!-- Liste des appareils -->
        <div class="bg-white rounded-lg shadow-lg p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-tv text-blue-600"></i>
                    Liste des appareils
                </h2>
                
                <form action="" method="post">
                    <input type="hidden" name="action" value="fix_all">
                    <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-magic mr-2"></i>
                        Corriger tous les statuts
                    </button>
                </form>
            </div>
            
            <?php if (count($appareils) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Appareil
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Statut actuel
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Statut calculé
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Dernière connexion
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Problème
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($appareils as $appareil): ?>
                                <?php 
                                $hasIssue = ($appareil['statut'] === 'actif' && $appareil['statut_connexion_calcule'] === 'online' && $appareil['statut_temps_reel'] !== 'online' && $appareil['statut_temps_reel'] !== 'playing');
                                ?>
                                <tr class="<?= $hasIssue ? 'bg-red-50' : '' ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 bg-gray-100 rounded-full flex items-center justify-center">
                                                <i class="fas fa-tv text-gray-500"></i>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($appareil['nom']) ?></div>
                                                <div class="text-xs text-gray-500"><?= htmlspecialchars($appareil['identifiant_unique']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <span class="status-indicator status-<?= $appareil['statut_temps_reel'] ?? 'offline' ?>"></span>
                                            <span class="text-sm"><?= ucfirst($appareil['statut_temps_reel'] ?? 'Non défini') ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <span class="status-indicator status-<?= $appareil['statut_connexion_calcule'] ?>"></span>
                                            <span class="text-sm"><?= ucfirst($appareil['statut_connexion_calcule']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('d/m/Y H:i:s', strtotime($appareil['derniere_connexion'])) ?>
                                        <div class="text-xs text-gray-400">
                                            Il y a <?= $appareil['minutes_depuis_derniere_connexion'] ?> minute(s)
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php if ($hasIssue): ?>
                                            <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs">
                                                Statut incohérent
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs">
                                                Aucun problème
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <?php if ($hasIssue): ?>
                                            <form action="" method="post" class="inline">
                                                <input type="hidden" name="action" value="fix_device">
                                                <input type="hidden" name="device_id" value="<?= htmlspecialchars($appareil['identifiant_unique']) ?>">
                                                <button type="submit" class="text-green-600 hover:text-green-900">
                                                    <i class="fas fa-magic mr-1"></i>
                                                    Corriger
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-gray-400">
                                                <i class="fas fa-check mr-1"></i>
                                                OK
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-info-circle text-blue-500 text-4xl mb-4"></i>
                    <p class="text-lg">Aucun appareil trouvé</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>