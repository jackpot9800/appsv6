<?php
// device-monitor-realtime.php - Interface de surveillance en temps réel des appareils avec WebSocket

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Configuration de la base de données
require_once('dbpdointranet.php');
$dbpdointranet->exec("USE affichageDynamique");

// Récupérer les statistiques initiales
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

// Récupérer les appareils
$stmt = $dbpdointranet->query("
    SELECT 
        a.*,
        CASE 
            WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'online'
            WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'idle'
            ELSE 'offline'
        END as statut_connexion,
        TIMESTAMPDIFF(SECOND, a.derniere_connexion, NOW()) as secondes_depuis_derniere_connexion
    FROM appareils a
    WHERE a.statut = 'actif'
    ORDER BY a.derniere_connexion DESC
");
$appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Configuration WebSocket
$wsServerUrl = 'ws://localhost:8080'; // Remplacer par l'URL de votre serveur WebSocket
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surveillance en temps réel - Fire TV</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="websocket-client.js"></script>
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
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .ws-connected {
            color: #10b981;
        }
        
        .ws-disconnected {
            color: #ef4444;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">
                <i class="fas fa-satellite-dish text-blue-600"></i>
                Surveillance en temps réel
            </h1>
            
            <div class="flex space-x-4">
                <div id="ws-status" class="flex items-center bg-gray-200 px-4 py-2 rounded-lg">
                    <i id="ws-icon" class="fas fa-plug mr-2 ws-disconnected"></i>
                    <span id="ws-text">Déconnecté</span>
                </div>
                
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
                        <p id="total-devices" class="text-2xl font-bold text-gray-800"><?= $stats['total_appareils'] ?></p>
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
                        <p id="online-devices" class="text-2xl font-bold text-gray-800"><?= $stats['appareils_en_ligne'] ?></p>
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
                        <p id="playing-devices" class="text-2xl font-bold text-gray-800"><?= $stats['appareils_en_diffusion'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="device-card bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-500 text-sm">Connexions WebSocket</p>
                        <p id="ws-connections" class="text-2xl font-bold text-gray-800">0</p>
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

            <!-- Activité en temps réel -->
            <div class="device-card bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-heartbeat text-red-600"></i>
                    Activité en temps réel
                </h2>
                
                <div id="activity-feed" class="space-y-4 max-h-64 overflow-y-auto">
                    <div class="text-center py-4 text-gray-500">
                        <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                        <p>En attente d'activité...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Liste des appareils en temps réel -->
        <div class="mt-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">
                <i class="fas fa-tv text-blue-600"></i>
                Appareils connectés
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
                                    WebSocket
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody id="devices-table-body" class="bg-white divide-y divide-gray-200">
                            <?php foreach ($appareils as $appareil): ?>
                            <tr id="device-row-<?= htmlspecialchars($appareil['identifiant_unique']) ?>" data-device-id="<?= htmlspecialchars($appareil['identifiant_unique']) ?>">
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
                                        <span class="text-sm"><?= ucfirst($appareil['statut_temps_reel'] ?? 'Offline') ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-sm text-gray-900"><?= htmlspecialchars($appareil['presentation_courante_nom'] ?? 'Aucune') ?></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= date('d/m/Y H:i:s', strtotime($appareil['derniere_connexion'])) ?>
                                    <div class="text-xs text-gray-400">
                                        Il y a <?= floor($appareil['secondes_depuis_derniere_connexion'] / 60) ?> minute(s)
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="ws-status px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800">
                                        Non connecté
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <button onclick="sendCommand('<?= htmlspecialchars($appareil['identifiant_unique']) ?>', 'play')" 
                                                class="text-green-600 hover:text-green-900">
                                            <i class="fas fa-play"></i>
                                        </button>
                                        <button onclick="sendCommand('<?= htmlspecialchars($appareil['identifiant_unique']) ?>', 'pause')" 
                                                class="text-yellow-600 hover:text-yellow-900">
                                            <i class="fas fa-pause"></i>
                                        </button>
                                        <button onclick="sendCommand('<?= htmlspecialchars($appareil['identifiant_unique']) ?>', 'stop')" 
                                                class="text-red-600 hover:text-red-900">
                                            <i class="fas fa-stop"></i>
                                        </button>
                                        <button onclick="sendCommand('<?= htmlspecialchars($appareil['identifiant_unique']) ?>', 'restart')" 
                                                class="text-blue-600 hover:text-blue-900">
                                            <i class="fas fa-redo"></i>
                                        </button>
                                        <button onclick="sendCommand('<?= htmlspecialchars($appareil['identifiant_unique']) ?>', 'reboot')" 
                                                class="text-purple-600 hover:text-purple-900">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
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
        let wsClient = null;
        let statusChart = null;
        let connectedDevices = new Set();
        
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Initialiser le graphique
            initStatusChart();
            
            // Connecter au serveur WebSocket
            connectWebSocket();
            
            // Bouton d'actualisation
            document.getElementById('refresh-button').addEventListener('click', function() {
                location.reload();
            });
        });
        
        // Initialiser le graphique de statut
        function initStatusChart() {
            const ctx = document.getElementById('status-chart').getContext('2d');
            
            const onlineCount = <?= $stats['appareils_en_ligne'] - $stats['appareils_en_diffusion'] ?>;
            const playingCount = <?= $stats['appareils_en_diffusion'] ?>;
            const offlineCount = <?= $stats['total_appareils'] - $stats['appareils_en_ligne'] ?>;
            
            statusChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['En ligne', 'En diffusion', 'Hors ligne'],
                    datasets: [{
                        data: [onlineCount, playingCount, offlineCount],
                        backgroundColor: [
                            '#10b981', // Vert - En ligne
                            '#3b82f6', // Bleu - En diffusion
                            '#6b7280'  // Gris - Hors ligne
                        ],
                        borderWidth: 0
                    }]
                },
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
        
        // Connecter au serveur WebSocket
        function connectWebSocket() {
            wsClient = new DeviceWebSocketClient({
                serverUrl: '<?= $wsServerUrl ?>',
                isAdmin: true,
                autoReconnect: true
            });
            
            // Événement de connexion
            wsClient.onConnect(function() {
                updateWebSocketStatus(true);
                showNotification('Connexion WebSocket', 'Connecté au serveur de surveillance en temps réel', 'success');
            });
            
            // Événement de déconnexion
            wsClient.onDisconnect(function() {
                updateWebSocketStatus(false);
                connectedDevices.clear();
                updateDeviceWebSocketStatus();
                showNotification('Déconnexion WebSocket', 'Déconnecté du serveur de surveillance en temps réel', 'error');
            });
            
            // Événement de connexion d'un appareil
            wsClient.onDeviceConnected(function(deviceId) {
                connectedDevices.add(deviceId);
                updateDeviceWebSocketStatus(deviceId, true);
                addActivityLog(`Appareil connecté: ${deviceId}`, 'success');
                showNotification('Appareil connecté', `L'appareil ${deviceId} s'est connecté`, 'info');
            });
            
            // Événement de déconnexion d'un appareil
            wsClient.onDeviceDisconnected(function(deviceId) {
                connectedDevices.delete(deviceId);
                updateDeviceWebSocketStatus(deviceId, false);
                addActivityLog(`Appareil déconnecté: ${deviceId}`, 'error');
                showNotification('Appareil déconnecté', `L'appareil ${deviceId} s'est déconnecté`, 'warning');
            });
            
            // Événement de mise à jour du statut d'un appareil
            wsClient.onDeviceStatus(function(deviceId, status) {
                updateDeviceStatus(deviceId, status);
                addActivityLog(`Mise à jour du statut: ${deviceId} - ${status.status || 'online'}`, 'info');
            });
            
            // Démarrer la connexion
            wsClient.connect();
        }
        
        // Mettre à jour le statut de la connexion WebSocket
        function updateWebSocketStatus(connected) {
            const wsStatus = document.getElementById('ws-status');
            const wsIcon = document.getElementById('ws-icon');
            const wsText = document.getElementById('ws-text');
            
            if (connected) {
                wsIcon.className = 'fas fa-plug mr-2 ws-connected';
                wsText.textContent = 'Connecté';
                wsStatus.className = 'flex items-center bg-green-100 px-4 py-2 rounded-lg';
            } else {
                wsIcon.className = 'fas fa-plug mr-2 ws-disconnected';
                wsText.textContent = 'Déconnecté';
                wsStatus.className = 'flex items-center bg-red-100 px-4 py-2 rounded-lg';
            }
            
            // Mettre à jour le compteur de connexions
            document.getElementById('ws-connections').textContent = connectedDevices.size;
        }
        
        // Mettre à jour le statut WebSocket d'un appareil
        function updateDeviceWebSocketStatus(deviceId = null, connected = false) {
            if (deviceId) {
                // Mettre à jour un appareil spécifique
                const row = document.getElementById(`device-row-${deviceId}`);
                if (row) {
                    const wsStatusCell = row.querySelector('.ws-status');
                    if (wsStatusCell) {
                        if (connected) {
                            wsStatusCell.className = 'ws-status px-2 py-1 text-xs rounded-full bg-green-100 text-green-800';
                            wsStatusCell.textContent = 'Connecté';
                        } else {
                            wsStatusCell.className = 'ws-status px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800';
                            wsStatusCell.textContent = 'Non connecté';
                        }
                    }
                }
            } else {
                // Mettre à jour tous les appareils
                const rows = document.querySelectorAll('#devices-table-body tr');
                rows.forEach(row => {
                    const deviceId = row.dataset.deviceId;
                    const wsStatusCell = row.querySelector('.ws-status');
                    if (wsStatusCell) {
                        if (connectedDevices.has(deviceId)) {
                            wsStatusCell.className = 'ws-status px-2 py-1 text-xs rounded-full bg-green-100 text-green-800';
                            wsStatusCell.textContent = 'Connecté';
                        } else {
                            wsStatusCell.className = 'ws-status px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800';
                            wsStatusCell.textContent = 'Non connecté';
                        }
                    }
                });
            }
            
            // Mettre à jour le compteur de connexions
            document.getElementById('ws-connections').textContent = connectedDevices.size;
        }
        
        // Mettre à jour le statut d'un appareil
        function updateDeviceStatus(deviceId, status) {
            const row = document.getElementById(`device-row-${deviceId}`);
            if (!row) return;
            
            // Mettre à jour le statut
            const statusCell = row.querySelector('.status-indicator').parentNode;
            if (statusCell) {
                const statusIndicator = statusCell.querySelector('.status-indicator');
                const statusText = statusCell.querySelector('span:not(.status-indicator)');
                
                if (statusIndicator && statusText) {
                    // Supprimer les anciennes classes
                    statusIndicator.className = 'status-indicator';
                    
                    // Ajouter la nouvelle classe
                    statusIndicator.classList.add(`status-${status.status || 'online'}`);
                    
                    // Mettre à jour le texte
                    statusText.textContent = ucfirst(status.status || 'Online');
                }
            }
            
            // Mettre à jour la présentation
            const presentationCell = row.querySelector('td:nth-child(3)');
            if (presentationCell && status.current_presentation_name) {
                presentationCell.querySelector('span').textContent = status.current_presentation_name;
            }
            
            // Mettre à jour les statistiques globales
            updateStatistics();
        }
        
        // Mettre à jour les statistiques globales
        function updateStatistics() {
            // Compter les appareils par statut
            let onlineCount = 0;
            let playingCount = 0;
            let offlineCount = 0;
            
            const rows = document.querySelectorAll('#devices-table-body tr');
            const totalDevices = rows.length;
            
            rows.forEach(row => {
                const statusIndicator = row.querySelector('.status-indicator');
                if (statusIndicator) {
                    if (statusIndicator.classList.contains('status-playing')) {
                        playingCount++;
                    } else if (statusIndicator.classList.contains('status-online')) {
                        onlineCount++;
                    } else if (statusIndicator.classList.contains('status-offline')) {
                        offlineCount++;
                    }
                }
            });
            
            // Mettre à jour les compteurs
            document.getElementById('total-devices').textContent = totalDevices;
            document.getElementById('online-devices').textContent = onlineCount + playingCount;
            document.getElementById('playing-devices').textContent = playingCount;
            
            // Mettre à jour le graphique
            if (statusChart) {
                statusChart.data.datasets[0].data = [onlineCount, playingCount, offlineCount];
                statusChart.update();
            }
        }
        
        // Ajouter une entrée au journal d'activité
        function addActivityLog(message, type = 'info') {
            const activityFeed = document.getElementById('activity-feed');
            
            // Supprimer le message d'attente si présent
            if (activityFeed.querySelector('.text-center')) {
                activityFeed.innerHTML = '';
            }
            
            // Créer la nouvelle entrée
            const entry = document.createElement('div');
            entry.className = 'flex items-center p-3 rounded-lg';
            
            // Définir la couleur selon le type
            switch (type) {
                case 'success':
                    entry.classList.add('bg-green-50');
                    break;
                case 'error':
                    entry.classList.add('bg-red-50');
                    break;
                case 'warning':
                    entry.classList.add('bg-yellow-50');
                    break;
                default:
                    entry.classList.add('bg-blue-50');
            }
            
            // Ajouter l'icône selon le type
            let icon = '';
            switch (type) {
                case 'success':
                    icon = '<i class="fas fa-check-circle text-green-500"></i>';
                    break;
                case 'error':
                    icon = '<i class="fas fa-exclamation-circle text-red-500"></i>';
                    break;
                case 'warning':
                    icon = '<i class="fas fa-exclamation-triangle text-yellow-500"></i>';
                    break;
                default:
                    icon = '<i class="fas fa-info-circle text-blue-500"></i>';
            }
            
            // Ajouter le contenu
            entry.innerHTML = `
                <div class="mr-3">
                    ${icon}
                </div>
                <div class="flex-grow">
                    <p class="text-sm">${message}</p>
                    <p class="text-xs text-gray-500">${new Date().toLocaleTimeString()}</p>
                </div>
            `;
            
            // Ajouter au début du flux
            activityFeed.insertBefore(entry, activityFeed.firstChild);
            
            // Limiter le nombre d'entrées
            const entries = activityFeed.querySelectorAll('div.flex');
            if (entries.length > 10) {
                activityFeed.removeChild(entries[entries.length - 1]);
            }
        }
        
        // Envoyer une commande à un appareil
        function sendCommand(deviceId, command, parameters = {}) {
            if (!wsClient || !wsClient.isConnected) {
                showNotification('Erreur', 'Non connecté au serveur WebSocket', 'error');
                return;
            }
            
            wsClient.sendCommand(deviceId, command, parameters);
            addActivityLog(`Commande envoyée: ${command} à ${deviceId}`, 'info');
            showNotification('Commande envoyée', `La commande ${command} a été envoyée à l'appareil ${deviceId}`, 'success');
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
        
        // Première lettre en majuscule
        function ucfirst(string) {
            return string.charAt(0).toUpperCase() + string.slice(1);
        }
    </script>
</body>
</html>