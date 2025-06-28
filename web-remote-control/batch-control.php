<?php
// batch-control.php - Interface pour contrôler plusieurs appareils en même temps

// Configuration de la base de données
require_once('dbpdointranet.php');
$dbpdointranet->exec("USE affichageDynamique");

// Récupérer tous les appareils
$stmt = $dbpdointranet->query("
    SELECT 
        a.*,
        CASE 
            WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'online'
            WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'idle'
            ELSE 'offline'
        END as statut_connexion
    FROM appareils a
    WHERE a.statut = 'actif'
    ORDER BY a.nom
");
$appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer toutes les présentations
$stmt = $dbpdointranet->query("
    SELECT id, nom, description 
    FROM presentations 
    WHERE statut = 'actif' 
    ORDER BY nom
");
$presentations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Traiter le formulaire d'assignation
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'assign_presentation') {
        $presentationId = $_POST['presentation_id'] ?? 0;
        $deviceIds = $_POST['device_ids'] ?? [];
        $autoPlay = isset($_POST['auto_play']);
        $loopMode = isset($_POST['loop_mode']);
        
        if (empty($presentationId) || empty($deviceIds)) {
            $message = 'Veuillez sélectionner une présentation et au moins un appareil';
            $messageType = 'error';
        } else {
            try {
                // Vérifier que la présentation existe
                $stmt = $dbpdointranet->prepare("SELECT id FROM presentations WHERE id = ?");
                $stmt->execute([$presentationId]);
                if (!$stmt->fetch()) {
                    throw new Exception('Présentation non trouvée');
                }
                
                $successCount = 0;
                
                foreach ($deviceIds as $deviceId) {
                    // Récupérer l'ID de l'appareil
                    $stmt = $dbpdointranet->prepare("SELECT id FROM appareils WHERE identifiant_unique = ?");
                    $stmt->execute([$deviceId]);
                    $appareil = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$appareil) {
                        continue;
                    }
                    
                    // Créer une diffusion
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
                    
                    // Envoyer une commande
                    $stmt = $dbpdointranet->prepare("
                        INSERT INTO commandes_distantes 
                        (identifiant_appareil, commande, parametres, statut, date_creation)
                        VALUES (?, 'assign_presentation', ?, 'en_attente', NOW())
                    ");
                    
                    $parameters = json_encode([
                        'presentation_id' => (int)$presentationId,
                        'auto_play' => (bool)$autoPlay,
                        'loop_mode' => (bool)$loopMode
                    ]);
                    
                    $stmt->execute([$deviceId, $parameters]);
                    
                    $successCount++;
                }
                
                $message = "Présentation assignée à {$successCount} appareil(s) avec succès";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Erreur: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'send_command') {
        $command = $_POST['command'] ?? '';
        $deviceIds = $_POST['device_ids'] ?? [];
        
        if (empty($command) || empty($deviceIds)) {
            $message = 'Veuillez sélectionner une commande et au moins un appareil';
            $messageType = 'error';
        } else {
            try {
                $successCount = 0;
                
                foreach ($deviceIds as $deviceId) {
                    // Envoyer une commande
                    $stmt = $dbpdointranet->prepare("
                        INSERT INTO commandes_distantes 
                        (identifiant_appareil, commande, parametres, statut, date_creation)
                        VALUES (?, ?, '{}', 'en_attente', NOW())
                    ");
                    
                    $stmt->execute([$deviceId, $command]);
                    $successCount++;
                }
                
                $message = "Commande '{$command}' envoyée à {$successCount} appareil(s) avec succès";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Erreur: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contrôle par lot - Fire TV</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-indicator {
            width: 10px;
            height: 10px;
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
                <i class="fas fa-layer-group text-blue-600"></i>
                Contrôle par lot
            </h1>
            
            <div class="flex space-x-4">
                <a href="dashboard.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-tachometer-alt mr-2"></i>
                    Tableau de bord
                </a>
                
                <a href="device-list.php" class="bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-tv mr-2"></i>
                    Liste des appareils
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                <div class="flex items-center">
                    <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                    <p><?= htmlspecialchars($message) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Assignation de présentation -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-desktop text-green-600"></i>
                    Assigner une présentation
                </h2>
                
                <form action="" method="post" class="space-y-4">
                    <input type="hidden" name="action" value="assign_presentation">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Sélectionner une présentation
                        </label>
                        <select name="presentation_id" class="w-full border border-gray-300 rounded-lg px-4 py-2" required>
                            <option value="">-- Choisir une présentation --</option>
                            <?php foreach ($presentations as $presentation): ?>
                            <option value="<?= $presentation['id'] ?>">
                                <?= htmlspecialchars($presentation['nom']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex space-x-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="auto_play" checked class="mr-2">
                            <span class="text-sm">Lecture automatique</span>
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="loop_mode" checked class="mr-2">
                            <span class="text-sm">Mode boucle</span>
                        </label>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Sélectionner les appareils
                        </label>
                        <div class="border border-gray-300 rounded-lg p-4 max-h-60 overflow-y-auto">
                            <div class="mb-2">
                                <label class="flex items-center">
                                    <input type="checkbox" id="select-all-devices" class="mr-2">
                                    <span class="font-medium">Sélectionner tous</span>
                                </label>
                            </div>
                            <div class="space-y-2">
                                <?php foreach ($appareils as $appareil): ?>
                                <label class="flex items-center">
                                    <input type="checkbox" name="device_ids[]" value="<?= $appareil['identifiant_unique'] ?>" class="mr-2 device-checkbox">
                                    <span class="status-indicator status-<?= $appareil['statut_connexion'] ?>"></span>
                                    <span><?= htmlspecialchars($appareil['nom']) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="w-full bg-green-500 hover:bg-green-600 text-white py-2 px-4 rounded-lg">
                        <i class="fas fa-paper-plane mr-2"></i>
                        Assigner et lancer
                    </button>
                </form>
            </div>

            <!-- Commandes groupées -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-terminal text-purple-600"></i>
                    Envoyer une commande groupée
                </h2>
                
                <form action="" method="post" class="space-y-4">
                    <input type="hidden" name="action" value="send_command">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Sélectionner une commande
                        </label>
                        <select name="command" class="w-full border border-gray-300 rounded-lg px-4 py-2" required>
                            <option value="">-- Choisir une commande --</option>
                            <option value="play">Lecture</option>
                            <option value="pause">Pause</option>
                            <option value="stop">Arrêt</option>
                            <option value="restart">Redémarrer présentation</option>
                            <option value="reboot">Redémarrer appareil</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Sélectionner les appareils
                        </label>
                        <div class="border border-gray-300 rounded-lg p-4 max-h-60 overflow-y-auto">
                            <div class="mb-2">
                                <label class="flex items-center">
                                    <input type="checkbox" id="select-all-devices-cmd" class="mr-2">
                                    <span class="font-medium">Sélectionner tous</span>
                                </label>
                            </div>
                            <div class="space-y-2">
                                <?php foreach ($appareils as $appareil): ?>
                                <label class="flex items-center">
                                    <input type="checkbox" name="device_ids[]" value="<?= $appareil['identifiant_unique'] ?>" class="mr-2 device-checkbox-cmd">
                                    <span class="status-indicator status-<?= $appareil['statut_connexion'] ?>"></span>
                                    <span><?= htmlspecialchars($appareil['nom']) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="w-full bg-purple-500 hover:bg-purple-600 text-white py-2 px-4 rounded-lg">
                        <i class="fas fa-terminal mr-2"></i>
                        Envoyer la commande
                    </button>
                </form>
            </div>
        </div>

        <!-- Filtres et groupes -->
        <div class="mt-8 bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-filter text-blue-600"></i>
                Filtres et groupes
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <h3 class="font-medium text-gray-700 mb-2">Filtrer par statut</h3>
                    <div class="space-y-2">
                        <button onclick="filterDevices('all')" class="w-full bg-gray-200 hover:bg-gray-300 py-2 px-4 rounded-lg text-left">
                            <i class="fas fa-globe mr-2"></i>
                            Tous les appareils
                        </button>
                        <button onclick="filterDevices('online')" class="w-full bg-green-100 hover:bg-green-200 py-2 px-4 rounded-lg text-left">
                            <i class="fas fa-wifi mr-2 text-green-600"></i>
                            Appareils en ligne
                        </button>
                        <button onclick="filterDevices('offline')" class="w-full bg-gray-100 hover:bg-gray-200 py-2 px-4 rounded-lg text-left">
                            <i class="fas fa-power-off mr-2 text-gray-600"></i>
                            Appareils hors ligne
                        </button>
                        <button onclick="filterDevices('playing')" class="w-full bg-blue-100 hover:bg-blue-200 py-2 px-4 rounded-lg text-left">
                            <i class="fas fa-play mr-2 text-blue-600"></i>
                            Appareils en diffusion
                        </button>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-medium text-gray-700 mb-2">Groupes d'appareils</h3>
                    <div class="space-y-2">
                        <?php
                        // Récupérer les groupes uniques
                        $stmt = $dbpdointranet->query("
                            SELECT DISTINCT groupe_appareil 
                            FROM appareils 
                            WHERE groupe_appareil IS NOT NULL AND groupe_appareil != ''
                            ORDER BY groupe_appareil
                        ");
                        $groupes = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        if (count($groupes) > 0):
                            foreach ($groupes as $groupe):
                        ?>
                            <button onclick="filterDevicesByGroup('<?= htmlspecialchars($groupe) ?>')" class="w-full bg-indigo-100 hover:bg-indigo-200 py-2 px-4 rounded-lg text-left">
                                <i class="fas fa-layer-group mr-2 text-indigo-600"></i>
                                <?= htmlspecialchars($groupe) ?>
                            </button>
                        <?php 
                            endforeach;
                        else:
                        ?>
                            <p class="text-gray-500 text-sm">Aucun groupe défini</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-medium text-gray-700 mb-2">Localisation</h3>
                    <div class="space-y-2">
                        <?php
                        // Récupérer les localisations uniques
                        $stmt = $dbpdointranet->query("
                            SELECT DISTINCT localisation 
                            FROM appareils 
                            WHERE localisation IS NOT NULL AND localisation != ''
                            ORDER BY localisation
                        ");
                        $localisations = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        if (count($localisations) > 0):
                            foreach ($localisations as $localisation):
                        ?>
                            <button onclick="filterDevicesByLocation('<?= htmlspecialchars($localisation) ?>')" class="w-full bg-yellow-100 hover:bg-yellow-200 py-2 px-4 rounded-lg text-left">
                                <i class="fas fa-map-marker-alt mr-2 text-yellow-600"></i>
                                <?= htmlspecialchars($localisation) ?>
                            </button>
                        <?php 
                            endforeach;
                        else:
                        ?>
                            <p class="text-gray-500 text-sm">Aucune localisation définie</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sélectionner tous les appareils (formulaire d'assignation)
            const selectAllDevices = document.getElementById('select-all-devices');
            const deviceCheckboxes = document.querySelectorAll('.device-checkbox');
            
            selectAllDevices.addEventListener('change', function() {
                const isChecked = this.checked;
                deviceCheckboxes.forEach(checkbox => {
                    checkbox.checked = isChecked;
                });
            });
            
            // Sélectionner tous les appareils (formulaire de commande)
            const selectAllDevicesCmd = document.getElementById('select-all-devices-cmd');
            const deviceCheckboxesCmd = document.querySelectorAll('.device-checkbox-cmd');
            
            selectAllDevicesCmd.addEventListener('change', function() {
                const isChecked = this.checked;
                deviceCheckboxesCmd.forEach(checkbox => {
                    checkbox.checked = isChecked;
                });
            });
        });
        
        // Filtrer les appareils par statut
        function filterDevices(status) {
            const deviceCheckboxes = document.querySelectorAll('.device-checkbox, .device-checkbox-cmd');
            
            deviceCheckboxes.forEach(checkbox => {
                const statusIndicator = checkbox.nextElementSibling;
                const deviceRow = checkbox.parentElement;
                
                if (status === 'all') {
                    deviceRow.style.display = 'flex';
                    return;
                }
                
                const deviceStatus = statusIndicator.classList.contains(`status-${status}`);
                deviceRow.style.display = deviceStatus ? 'flex' : 'none';
            });
        }
        
        // Filtrer les appareils par groupe
        function filterDevicesByGroup(group) {
            // Implémenter la logique de filtrage par groupe
            alert(`Filtrage par groupe: ${group} (à implémenter)`);
        }
        
        // Filtrer les appareils par localisation
        function filterDevicesByLocation(location) {
            // Implémenter la logique de filtrage par localisation
            alert(`Filtrage par localisation: ${location} (à implémenter)`);
        }
    </script>
</body>
</html>