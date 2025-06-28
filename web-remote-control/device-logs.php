<?php
// device-logs.php - Affichage des logs d'un appareil spécifique

// Configuration de la base de données
require_once('dbpdointranet.php');
$dbpdointranet->exec("USE affichageDynamique");

// Récupérer l'ID de l'appareil depuis l'URL
$device_id = $_GET['device_id'] ?? '';

if (empty($device_id)) {
    die('ID d\'appareil requis');
}

// Récupérer les informations de l'appareil
$stmt = $dbpdointranet->prepare("
    SELECT a.* 
    FROM appareils a
    WHERE a.identifiant_unique = ?
");
$stmt->execute([$device_id]);
$appareil = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appareil) {
    die('Appareil non trouvé');
}

// Récupérer les logs de l'appareil
$limit = intval($_GET['limit'] ?? 100);
$stmt = $dbpdointranet->prepare("
    SELECT * FROM logs_activite
    WHERE identifiant_appareil = ?
    ORDER BY date_action DESC
    LIMIT ?
");
$stmt->execute([$device_id, $limit]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les statistiques des logs
$stmt = $dbpdointranet->prepare("
    SELECT 
        COUNT(*) as total_logs,
        COUNT(CASE WHEN type_action = 'connexion' THEN 1 END) as connexion_logs,
        COUNT(CASE WHEN type_action = 'diffusion' THEN 1 END) as diffusion_logs,
        COUNT(CASE WHEN type_action = 'erreur' THEN 1 END) as erreur_logs,
        COUNT(CASE WHEN type_action = 'maintenance' THEN 1 END) as maintenance_logs,
        COUNT(CASE WHEN type_action = 'commande_distante' THEN 1 END) as commande_logs,
        MIN(date_action) as first_log,
        MAX(date_action) as last_log
    FROM logs_activite
    WHERE identifiant_appareil = ?
");
$stmt->execute([$device_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs - <?= htmlspecialchars($appareil['nom']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .log-type-connexion { border-left-color: #10b981; }
        .log-type-diffusion { border-left-color: #3b82f6; }
        .log-type-erreur { border-left-color: #ef4444; }
        .log-type-maintenance { border-left-color: #f59e0b; }
        .log-type-commande_distante { border-left-color: #8b5cf6; }
        
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">
                <i class="fas fa-history text-blue-600"></i>
                Logs - <?= htmlspecialchars($appareil['nom']) ?>
            </h1>
            
            <div class="flex space-x-4">
                <a href="device-control.php?device_id=<?= urlencode($device_id) ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-gamepad mr-2"></i>
                    Contrôler
                </a>
                
                <a href="device-list.php" class="bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-tv mr-2"></i>
                    Liste des appareils
                </a>
            </div>
        </div>

        <!-- Informations de l'appareil -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
            <div class="flex items-center space-x-4">
                <div class="bg-blue-100 p-3 rounded-full">
                    <i class="fas fa-tv text-blue-600 text-2xl"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($appareil['nom']) ?></h2>
                    <p class="text-gray-600">ID: <?= htmlspecialchars($appareil['identifiant_unique']) ?></p>
                </div>
            </div>
        </div>

        <!-- Statistiques des logs -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="bg-gray-100 p-2 rounded-full">
                        <i class="fas fa-clipboard-list text-gray-600"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-xs text-gray-500">Total logs</p>
                        <p class="text-lg font-bold text-gray-800"><?= $stats['total_logs'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="bg-green-100 p-2 rounded-full">
                        <i class="fas fa-wifi text-green-600"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-xs text-gray-500">Connexions</p>
                        <p class="text-lg font-bold text-gray-800"><?= $stats['connexion_logs'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full">
                        <i class="fas fa-play text-blue-600"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-xs text-gray-500">Diffusions</p>
                        <p class="text-lg font-bold text-gray-800"><?= $stats['diffusion_logs'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="bg-red-100 p-2 rounded-full">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-xs text-gray-500">Erreurs</p>
                        <p class="text-lg font-bold text-gray-800"><?= $stats['erreur_logs'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="bg-purple-100 p-2 rounded-full">
                        <i class="fas fa-terminal text-purple-600"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-xs text-gray-500">Commandes</p>
                        <p class="text-lg font-bold text-gray-800"><?= $stats['commande_logs'] ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <form action="" method="get" class="flex flex-wrap items-center gap-4">
                <input type="hidden" name="device_id" value="<?= htmlspecialchars($device_id) ?>">
                
                <div class="flex-grow">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de logs</label>
                    <select name="limit" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50 derniers logs</option>
                        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100 derniers logs</option>
                        <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200 derniers logs</option>
                        <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500 derniers logs</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type de log</label>
                    <div class="flex space-x-2">
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="type[]" value="connexion" checked class="mr-1">
                            <span class="text-xs">Connexion</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="type[]" value="diffusion" checked class="mr-1">
                            <span class="text-xs">Diffusion</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="type[]" value="erreur" checked class="mr-1">
                            <span class="text-xs">Erreur</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="type[]" value="maintenance" checked class="mr-1">
                            <span class="text-xs">Maintenance</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="type[]" value="commande_distante" checked class="mr-1">
                            <span class="text-xs">Commande</span>
                        </label>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">&nbsp;</label>
                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-filter mr-2"></i>
                        Filtrer
                    </button>
                </div>
            </form>
        </div>

        <!-- Liste des logs -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <?php if (count($logs) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Date
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Type
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Message
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Détails
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= date('d/m/Y H:i:s', strtotime($log['date_action'])) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs rounded-full 
                                        <?php 
                                        switch($log['type_action']) {
                                            case 'connexion': echo 'bg-green-100 text-green-800'; break;
                                            case 'diffusion': echo 'bg-blue-100 text-blue-800'; break;
                                            case 'erreur': echo 'bg-red-100 text-red-800'; break;
                                            case 'maintenance': echo 'bg-yellow-100 text-yellow-800'; break;
                                            case 'commande_distante': echo 'bg-purple-100 text-purple-800'; break;
                                            default: echo 'bg-gray-100 text-gray-800';
                                        }
                                        ?>">
                                        <?= ucfirst($log['type_action']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?= htmlspecialchars($log['message']) ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <?php if (!empty($log['details'])): ?>
                                        <button class="text-blue-500 hover:text-blue-700" 
                                                onclick="toggleDetails('details-<?= $log['id'] ?>')">
                                            <i class="fas fa-info-circle"></i>
                                            Voir détails
                                        </button>
                                        <div id="details-<?= $log['id'] ?>" class="hidden mt-2 p-2 bg-gray-50 rounded text-xs">
                                            <pre><?= htmlspecialchars($log['details']) ?></pre>
                                        </div>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-8 text-center text-gray-500">
                    <i class="fas fa-info-circle text-blue-500 text-4xl mb-4"></i>
                    <p class="text-lg">Aucun log trouvé pour cet appareil</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Afficher/masquer les détails d'un log
        function toggleDetails(id) {
            const details = document.getElementById(id);
            if (details.classList.contains('hidden')) {
                details.classList.remove('hidden');
            } else {
                details.classList.add('hidden');
            }
        }
    </script>
</body>
</html>