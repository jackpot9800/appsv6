<?php
// status-monitor.php - Script pour surveiller le statut des appareils en temps réel
// Ce script peut être utilisé comme page web ou comme API

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Connexion à la base de données
require_once('dbpdointranet.php');
$dbpdointranet->exec("USE affichageDynamique");

// Déterminer si c'est une requête API ou une page web
$isApi = isset($_GET['format']) && $_GET['format'] === 'json';

if ($isApi) {
    // Mode API
    header('Content-Type: application/json');
    
    // Récupérer tous les appareils avec leur statut
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
            ORDER BY a.derniere_connexion DESC
        ");
        $appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Identifier les appareils avec statut incohérent
        $appareilsAvecProblemes = [];
        foreach ($appareils as $appareil) {
            if ($appareil['statut'] === 'actif' && 
                $appareil['statut_connexion_calcule'] === 'online' && 
                $appareil['statut_temps_reel'] !== 'online' && 
                $appareil['statut_temps_reel'] !== 'playing') {
                $appareilsAvecProblemes[] = $appareil;
            }
        }
        
        // Récupérer les statistiques
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
        
        echo json_encode([
            'success' => true,
            'devices' => $appareils,
            'devices_with_issues' => $appareilsAvecProblemes,
            'issues_count' => count($appareilsAvecProblemes),
            'statistics' => $stats,
            'server_time' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Erreur lors de la récupération des données: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Mode page web
// Récupérer tous les appareils avec leur statut
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
        ORDER BY a.derniere_connexion DESC
    ");
    $appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Identifier les appareils avec statut incohérent
    $appareilsAvecProblemes = [];
    foreach ($appareils as $appareil) {
        if ($appareil['statut'] === 'actif' && 
            $appareil['statut_connexion_calcule'] === 'online' && 
            $appareil['statut_temps_reel'] !== 'online' && 
            $appareil['statut_temps_reel'] !== 'playing') {
            $appareilsAvecProblemes[] = $appareil;
        }
    }
    
    // Récupérer les statistiques
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
} catch (Exception $e) {
    die("Erreur lors de la récupération des données: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moniteur de statut des appareils</title>
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
        
        .blink {
            animation: blink 1s linear infinite;
        }
        
        @keyframes blink {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">
                <i class="fas fa-heartbeat text-red-600"></i>
                Moniteur de statut des appareils
            </h1>
            
            <div class="flex space-x-4">
                <button id="refresh-button" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-sync-alt mr-2"></i>
                    Actualiser
                </button>
                
                <a href="device-status-check.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-magic mr-2"></i>
                    Corriger les statuts
                </a>
                
                <a href="index.php" class="bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-home mr-2"></i>
                    Accueil
                </a>
            </div>
        </div>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-tv text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">Appareils totaux</p>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['total_appareils'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-wifi text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">Appareils en ligne</p>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['appareils_en_ligne'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="bg-indigo-100 p-3 rounded-full">
                        <i class="fas fa-play text-indigo-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">En diffusion</p>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['appareils_en_diffusion'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="bg-red-100 p-3 rounded-full">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">Problèmes de statut</p>
                        <p class="text-2xl font-bold text-gray-800"><?= count($appareilsAvecProblemes) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Appareils avec problèmes -->
        <?php if (count($appareilsAvecProblemes) > 0): ?>
            <div class="bg-red-50 rounded-lg shadow-lg p-6 mb-8 border border-red-200">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold text-red-800">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                        Appareils avec statut incohérent
                    </h2>
                    
                    <form action="device-status-fix.php" method="get" target="_blank">
                        <input type="hidden" name="action" value="fix">
                        <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-magic mr-2"></i>
                            Corriger tous
                        </button>
                    </form>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-red-200">
                        <thead class="bg-red-100">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase tracking-wider">
                                    Appareil
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase tracking-wider">
                                    Statut actuel
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase tracking-wider">
                                    Statut calculé
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase tracking-wider">
                                    Dernière connexion
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-red-700 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-red-200">
                            <?php foreach ($appareilsAvecProblemes as $appareil): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 bg-red-100 rounded-full flex items-center justify-center">
                                                <i class="fas fa-tv text-red-500"></i>
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
                                            <span class="status-indicator status-<?= $appareil['statut_connexion_calcule'] ?> blink"></span>
                                            <span class="text-sm font-medium text-green-700"><?= ucfirst($appareil['statut_connexion_calcule']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('d/m/Y H:i:s', strtotime($appareil['derniere_connexion'])) ?>
                                        <div class="text-xs text-gray-400">
                                            Il y a <?= $appareil['minutes_depuis_derniere_connexion'] ?> minute(s)
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="device-status-fix.php?action=fix&device_id=<?= urlencode($appareil['identifiant_unique']) ?>" target="_blank" class="text-green-600 hover:text-green-900">
                                            <i class="fas fa-magic mr-1"></i>
                                            Corriger
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tous les appareils -->
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-tv text-blue-600"></i>
                Tous les appareils
            </h2>
            
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
                                Présentation
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Problème
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($appareils as $appareil): ?>
                            <?php 
                            $hasIssue = ($appareil['statut'] === 'actif' && 
                                         $appareil['statut_connexion_calcule'] === 'online' && 
                                         $appareil['statut_temps_reel'] !== 'online' && 
                                         $appareil['statut_temps_reel'] !== 'playing');
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
                                        <span class="status-indicator status-<?= $appareil['statut_connexion_calcule'] ?> <?= $hasIssue ? 'blink' : '' ?>"></span>
                                        <span class="text-sm"><?= ucfirst($appareil['statut_connexion_calcule']) ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= date('d/m/Y H:i:s', strtotime($appareil['derniere_connexion'])) ?>
                                    <div class="text-xs text-gray-400">
                                        Il y a <?= $appareil['minutes_depuis_derniere_connexion'] ?> minute(s)
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= $appareil['presentation_courante_nom'] ?? 'Aucune' ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php if ($hasIssue): ?>
                                        <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs">
                                            Statut incohérent
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs">
                                            OK
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Actualiser la page automatiquement toutes les 30 secondes
        setInterval(function() {
            location.reload();
        }, 30000);
        
        // Bouton d'actualisation manuelle
        document.getElementById('refresh-button').addEventListener('click', function() {
            location.reload();
        });
    </script>
</body>
</html>