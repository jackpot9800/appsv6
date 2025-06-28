<?php
// device-monitor.php - Interface de surveillance en temps réel des appareils

// Configuration de la base de données
require_once('dbpdointranet.php');
$dbpdointranet->exec("USE affichageDynamique");

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

// Récupérer les commandes en attente
$stmt = $dbpdointranet->query("
    SELECT COUNT(*) as commandes_en_attente
    FROM commandes_distantes 
    WHERE statut = 'en_attente'
");
$commandes = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['commandes_en_attente'] = $commandes['commandes_en_attente'];

// Récupérer les appareils hors ligne
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
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surveillance des appareils Fire TV</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .device-card {
            transition: all 0.3s ease;
        }
        
        .device-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .alert-pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
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
                <i class="fas fa-desktop text-blue-600"></i>
                Surveillance des appareils Fire TV
            </h1>
            
            <div class="flex space-x-4">
                <button id="refresh-button" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-sync-alt mr-2"></i>
                    Actualiser
                </button>
                
                <a href="dashboard.php" class="bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-tachometer-alt mr-2"></i>
                    Tableau de bord
                </a>
            </div>
        </div>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="device-card bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-tv text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">Appareils totaux</p>
                        <p class="text-2xl font-bold text-gray-800" id="total-devices"><?= $stats['total_appareils'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="device-card bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-wifi text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">Appareils en ligne</p>
                        <p class="text-2xl font-bold text-gray-800" id="online-devices"><?= $stats['appareils_en_ligne'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="device-card bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="bg-indigo-100 p-3 rounded-full">
                        <i class="fas fa-play text-indigo-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">En diffusion</p>
                        <p class="text-2xl font-bold text-gray-800" id="playing-devices"><?= $stats['appareils_en_diffusion'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="device-card bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="bg-red-100 p-3 rounded-full">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">Appareils hors ligne</p>
                        <p class="text-2xl font-bold text-gray-800" id="offline-devices"><?= count($appareils_hors_ligne) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Graphique de statut -->
            <div class="device-card bg-white rounded-lg shadow-lg p-6 lg:col-span-2">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-chart-pie text-purple-600"></i>
                    Statut des appareils
                </h2>
                
                <div class="h-64">
                    <canvas id="status-chart"></canvas>
                </div>
            </div>

            <!-- Alertes -->
            <div class="device-card bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-exclamation-circle text-red-600"></i>
                    Alertes
                </h2>
                
                <div id="alerts-container" class="space-y-4">
                    <?php if (count($appareils_hors_ligne) > 0): ?>
                        <?php foreach ($appareils_hors_ligne as $appareil): ?>
                            <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg border border-red-200 alert-pulse">
                                <div class="flex items-center">
                                    <span class="status-indicator status-offline"></span>
                                    <div>
                                        <p class="font-medium text-gray-800"><?= htmlspecialchars($appareil['nom']) ?></p>
                                        <p class="text-xs text-gray-500">
                                            Hors ligne depuis <?= $appareil['minutes_offline'] ?> minutes
                                        </p>
                                    </div>
                                </div>
                                
                                <button onclick="forceRestart('<?= $appareil['identifiant_unique'] ?>')" 
                                        class="text-white bg-red-500 hover:bg-red-600 px-2 py-1 rounded text-xs">
                                    <i class="fas fa-power-off mr-1"></i>
                                    Redémarrer
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-gray-500">
                            <i class="fas fa-check-circle text-green-500 text-2xl mb-2"></i>
                            <p>Aucune alerte active</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Liste des appareils en temps réel -->
        <div class="mt-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">
                <i class="fas fa-tv text-blue-600"></i>
                Statut en temps réel
            </h2>
            
            <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Appareil
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Statut
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Présentation
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Dernière connexion
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Mémoire
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    WiFi
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody id="devices-table-body" class="bg-white divide-y divide-gray-200">
                            <!-- Les données seront chargées dynamiquement -->
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                                    <i class="fas fa-spinner fa-spin mr-2"></i>
                                    Chargement des données...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification -->
    <div id="notification" class="fixed top-4 right-4 max-w-md bg-white rounded-lg shadow-lg p-4 transform translate-x-full transition-transform duration-300 z-50">
        <div id="notification-content" class="flex items-center">
            <div id="notification-icon" class="flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full mr-3">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="flex-grow">
                <h4 id="notification-title" class="text-sm font-medium"></h4>
                <p id="notification-message" class="text-xs text-gray-500"></p>
            </div>
            <button onclick="hideNotification()" class="flex-shrink-0 ml-2 text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <script>
        // Variables globales
        let statusChart = null;
        let refreshInterval = null;
        
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Charger les données initiales
            loadDevicesData();
            
            // Configurer l'actualisation automatique
            refreshInterval = setInterval(loadDevicesData, 30000); // Toutes les 30 secondes
            
            // Bouton d'actualisation manuelle
            document.getElementById('refresh-button').addEventListener('click', loadDevicesData);
        });
        
        // Charger les données des appareils
        async function loadDevicesData() {
            try {
                const response = await fetch('status-monitor.php?action=get_all_devices');
                const data = await response.json();
                
                if (data.success) {
                    updateDevicesTable(data.devices);
                    updateStatistics(data.devices);
                    updateStatusChart(data.devices);
                } else {
                    showNotification('Erreur', data.message, 'error');
                }
            } catch (error) {
                console.error('Erreur lors du chargement des données:', error);
                showNotification('Erreur de connexion', 'Impossible de charger les données des appareils', 'error');
            }
        }
        
        // Mettre à jour le tableau des appareils
        function updateDevicesTable(devices) {
            const tableBody = document.getElementById('devices-table-body');
            
            if (devices.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                            Aucun appareil trouvé
                        </td>
                    </tr>
                `;
                return;
            }
            
            let html = '';
            
            devices.forEach(device => {
                // Déterminer la classe de statut
                let statusClass = 'status-offline';
                let statusText = 'Hors ligne';
                
                if (device.statut_temps_reel === 'playing') {
                    statusClass = 'status-playing';
                    statusText = 'En diffusion';
                } else if (device.statut_temps_reel === 'paused') {
                    statusClass = 'status-paused';
                    statusText = 'En pause';
                } else if (device.statut_temps_reel === 'error') {
                    statusClass = 'status-error';
                    statusText = 'Erreur';
                } else if (device.statut_connexion === 'online') {
                    statusClass = 'status-online';
                    statusText = 'En ligne';
                } else if (device.statut_connexion === 'idle') {
                    statusClass = 'status-idle';
                    statusText = 'Inactif';
                }
                
                html += `
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 h-10 w-10 bg-gray-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-tv text-gray-500"></i>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900">${device.nom}</div>
                                    <div class="text-xs text-gray-500">${device.identifiant_unique}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <span class="status-indicator ${statusClass}"></span>
                                <span class="text-sm">${statusText}</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm text-gray-900">${device.presentation_courante_nom || 'Aucune'}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            ${device.derniere_connexion_formatee}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            ${device.utilisation_memoire ? 
                                `<div class="w-24 bg-gray-200 rounded-full h-2.5">
                                    <div class="bg-blue-600 h-2.5 rounded-full" style="width: ${device.utilisation_memoire}%"></div>
                                </div>
                                <span class="text-xs text-gray-500">${device.utilisation_memoire}%</span>` : 
                                '<span class="text-xs text-gray-500">-</span>'
                            }
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            ${device.force_wifi ? 
                                `<div class="w-24 bg-gray-200 rounded-full h-2.5">
                                    <div class="bg-green-600 h-2.5 rounded-full" style="width: ${device.force_wifi}%"></div>
                                </div>
                                <span class="text-xs text-gray-500">${device.force_wifi}%</span>` : 
                                '<span class="text-xs text-gray-500">-</span>'
                            }
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <div class="flex space-x-2">
                                <a href="device-control.php?device_id=${device.identifiant_unique}" 
                                   class="text-indigo-600 hover:text-indigo-900">
                                    <i class="fas fa-gamepad"></i>
                                </a>
                                <button onclick="forceRestart('${device.identifiant_unique}')" 
                                        class="text-red-600 hover:text-red-900">
                                    <i class="fas fa-power-off"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tableBody.innerHTML = html;
        }
        
        // Mettre à jour les statistiques
        function updateStatistics(devices) {
            const totalDevices = devices.length;
            const onlineDevices = devices.filter(d => d.statut_connexion === 'online').length;
            const playingDevices = devices.filter(d => d.statut_temps_reel === 'playing').length;
            const offlineDevices = devices.filter(d => d.statut_connexion === 'offline').length;
            
            document.getElementById('total-devices').textContent = totalDevices;
            document.getElementById('online-devices').textContent = onlineDevices;
            document.getElementById('playing-devices').textContent = playingDevices;
            document.getElementById('offline-devices').textContent = offlineDevices;
            
            // Mettre à jour les alertes
            updateAlerts(devices.filter(d => d.statut_connexion === 'offline'));
        }
        
        // Mettre à jour le graphique de statut
        function updateStatusChart(devices) {
            const onlineCount = devices.filter(d => d.statut_connexion === 'online' && d.statut_temps_reel !== 'playing').length;
            const playingCount = devices.filter(d => d.statut_temps_reel === 'playing').length;
            const pausedCount = devices.filter(d => d.statut_temps_reel === 'paused').length;
            const offlineCount = devices.filter(d => d.statut_connexion === 'offline').length;
            const errorCount = devices.filter(d => d.statut_temps_reel === 'error').length;
            
            const chartData = {
                labels: ['En ligne', 'En diffusion', 'En pause', 'Hors ligne', 'Erreur'],
                datasets: [{
                    data: [onlineCount, playingCount, pausedCount, offlineCount, errorCount],
                    backgroundColor: [
                        '#10b981', // Vert - En ligne
                        '#3b82f6', // Bleu - En diffusion
                        '#f59e0b', // Jaune - En pause
                        '#6b7280', // Gris - Hors ligne
                        '#ef4444'  // Rouge - Erreur
                    ],
                    borderWidth: 0
                }]
            };
            
            if (statusChart) {
                statusChart.data = chartData;
                statusChart.update();
            } else {
                const ctx = document.getElementById('status-chart').getContext('2d');
                statusChart = new Chart(ctx, {
                    type: 'doughnut',
                    data: chartData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    font: {
                                        size: 12
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `${label}: ${value} (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        cutout: '70%'
                    }
                });
            }
        }
        
        // Mettre à jour les alertes
        function updateAlerts(offlineDevices) {
            const alertsContainer = document.getElementById('alerts-container');
            
            if (offlineDevices.length === 0) {
                alertsContainer.innerHTML = `
                    <div class="text-center py-4 text-gray-500">
                        <i class="fas fa-check-circle text-green-500 text-2xl mb-2"></i>
                        <p>Aucune alerte active</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            
            offlineDevices.forEach(device => {
                html += `
                    <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg border border-red-200 alert-pulse">
                        <div class="flex items-center">
                            <span class="status-indicator status-offline"></span>
                            <div>
                                <p class="font-medium text-gray-800">${device.nom}</p>
                                <p class="text-xs text-gray-500">
                                    Hors ligne depuis ${device.secondes_depuis_derniere_connexion > 60 ? 
                                        Math.floor(device.secondes_depuis_derniere_connexion / 60) + ' minutes' : 
                                        device.secondes_depuis_derniere_connexion + ' secondes'}
                                </p>
                            </div>
                        </div>
                        
                        <button onclick="forceRestart('${device.identifiant_unique}')" 
                                class="text-white bg-red-500 hover:bg-red-600 px-2 py-1 rounded text-xs">
                            <i class="fas fa-power-off mr-1"></i>
                            Redémarrer
                        </button>
                    </div>
                `;
            });
            
            alertsContainer.innerHTML = html;
        }
        
        // Forcer le redémarrage d'un appareil
        async function forceRestart(deviceId) {
            if (!confirm('Êtes-vous sûr de vouloir forcer le redémarrage de cet appareil ?')) {
                return;
            }
            
            try {
                const response = await fetch(`status-monitor.php?action=force_restart&device_id=${deviceId}`);
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Redémarrage', 'Commande de redémarrage envoyée avec succès', 'success');
                    // Recharger les données après un court délai
                    setTimeout(loadDevicesData, 2000);
                } else {
                    showNotification('Erreur', data.message, 'error');
                }
            } catch (error) {
                console.error('Erreur lors du redémarrage:', error);
                showNotification('Erreur de connexion', 'Impossible d\'envoyer la commande de redémarrage', 'error');
            }
        }
        
        // Afficher une notification
        function showNotification(title, message, type = 'info') {
            const notification = document.getElementById('notification');
            const notificationTitle = document.getElementById('notification-title');
            const notificationMessage = document.getElementById('notification-message');
            const notificationIcon = document.getElementById('notification-icon');
            
            // Définir le contenu
            notificationTitle.textContent = title;
            notificationMessage.textContent = message;
            
            // Définir le style selon le type
            notification.className = 'fixed top-4 right-4 max-w-md bg-white rounded-lg shadow-lg p-4 z-50 transform transition-transform duration-300';
            
            switch (type) {
                case 'success':
                    notificationIcon.className = 'flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full mr-3 bg-green-100 text-green-500';
                    notificationIcon.innerHTML = '<i class="fas fa-check"></i>';
                    break;
                case 'error':
                    notificationIcon.className = 'flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full mr-3 bg-red-100 text-red-500';
                    notificationIcon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                    break;
                case 'warning':
                    notificationIcon.className = 'flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full mr-3 bg-yellow-100 text-yellow-500';
                    notificationIcon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
                    break;
                default:
                    notificationIcon.className = 'flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full mr-3 bg-blue-100 text-blue-500';
                    notificationIcon.innerHTML = '<i class="fas fa-info-circle"></i>';
            }
            
            // Afficher la notification
            notification.style.transform = 'translateX(0)';
            
            // Masquer après 5 secondes
            setTimeout(hideNotification, 5000);
        }
        
        // Masquer la notification
        function hideNotification() {
            const notification = document.getElementById('notification');
            notification.style.transform = 'translateX(100%)';
        }
        
        // Nettoyer l'intervalle quand on quitte la page
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    </script>
</body>
</html>