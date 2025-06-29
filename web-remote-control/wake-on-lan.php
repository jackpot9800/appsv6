<?php
// wake-on-lan.php - Script pour envoyer un paquet Wake-on-LAN à un appareil

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Fonction pour envoyer un paquet Wake-on-LAN
function sendWakeOnLan($macAddress, $broadcastIP = '255.255.255.255', $port = 9) {
    // Nettoyer l'adresse MAC
    $macAddress = str_replace([':', '-', '.'], '', $macAddress);
    
    // Vérifier que l'adresse MAC est valide
    if (strlen($macAddress) != 12) {
        return [
            'success' => false,
            'error' => 'Adresse MAC invalide'
        ];
    }
    
    // Créer le "magic packet"
    $header = str_repeat(chr(0xff), 6);
    $data = '';
    
    // Répéter l'adresse MAC 16 fois
    for ($i = 0; $i < 16; $i++) {
        for ($j = 0; $j < 12; $j += 2) {
            $data .= chr(hexdec(substr($macAddress, $j, 2)));
        }
    }
    
    // Créer le paquet complet
    $packet = $header . $data;
    
    // Ouvrir un socket
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$socket) {
        return [
            'success' => false,
            'error' => 'Impossible de créer le socket: ' . socket_strerror(socket_last_error())
        ];
    }
    
    // Configurer le socket pour le broadcast
    socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
    
    // Envoyer le paquet
    $result = socket_sendto($socket, $packet, strlen($packet), 0, $broadcastIP, $port);
    socket_close($socket);
    
    if ($result === false) {
        return [
            'success' => false,
            'error' => 'Erreur lors de l\'envoi du paquet: ' . socket_strerror(socket_last_error())
        ];
    }
    
    return [
        'success' => true,
        'bytes_sent' => $result,
        'mac_address' => $macAddress,
        'broadcast_ip' => $broadcastIP,
        'port' => $port
    ];
}

// Traiter la requête
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer les paramètres
    $macAddress = $_POST['mac_address'] ?? '';
    $broadcastIP = $_POST['broadcast_ip'] ?? '255.255.255.255';
    $deviceId = $_POST['device_id'] ?? '';
    
    // Connexion à la base de données
    try {
        require_once('dbpdointranet.php');
        $dbpdointranet->exec("USE affichageDynamique");
        
        // Si un device_id est fourni, récupérer l'adresse MAC associée
        if (!empty($deviceId) && empty($macAddress)) {
            $stmt = $dbpdointranet->prepare("
                SELECT adresse_mac FROM appareils WHERE identifiant_unique = ?
            ");
            $stmt->execute([$deviceId]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($device && !empty($device['adresse_mac'])) {
                $macAddress = $device['adresse_mac'];
            }
        }
        
        // Vérifier que l'adresse MAC est fournie
        if (empty($macAddress)) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Adresse MAC requise'
            ]);
            exit;
        }
        
        // Envoyer le paquet Wake-on-LAN
        $result = sendWakeOnLan($macAddress, $broadcastIP);
        
        // Enregistrer un log d'activité
        if ($result['success']) {
            $stmt = $dbpdointranet->prepare("
                INSERT INTO logs_activite 
                (type_action, identifiant_appareil, message, details, adresse_ip)
                VALUES ('maintenance', ?, 'Wake-on-LAN envoyé', ?, ?)
            ");
            
            $stmt->execute([
                $deviceId,
                json_encode([
                    'mac_address' => $macAddress,
                    'broadcast_ip' => $broadcastIP,
                    'bytes_sent' => $result['bytes_sent'],
                    'timestamp' => date('Y-m-d H:i:s')
                ]),
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        }
        
        // Retourner le résultat
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erreur de base de données: ' . $e->getMessage()
        ]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wake-on-LAN - Fire TV</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">
                <i class="fas fa-power-off text-red-600"></i>
                Wake-on-LAN
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

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Formulaire Wake-on-LAN -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-power-off text-red-600"></i>
                    Envoyer un paquet Wake-on-LAN
                </h2>
                
                <form id="wol-form" class="space-y-4">
                    <div>
                        <label for="mac-address" class="block text-sm font-medium text-gray-700 mb-1">
                            Adresse MAC
                        </label>
                        <input type="text" id="mac-address" name="mac_address" placeholder="00:11:22:33:44:55" 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2" required>
                        <p class="text-xs text-gray-500 mt-1">
                            Format: 00:11:22:33:44:55 ou 00-11-22-33-44-55
                        </p>
                    </div>
                    
                    <div>
                        <label for="broadcast-ip" class="block text-sm font-medium text-gray-700 mb-1">
                            Adresse IP de broadcast
                        </label>
                        <input type="text" id="broadcast-ip" name="broadcast_ip" value="255.255.255.255" 
                               class="w-full border border-gray-300 rounded-lg px-4 py-2">
                        <p class="text-xs text-gray-500 mt-1">
                            Laissez la valeur par défaut pour un broadcast global
                        </p>
                    </div>
                    
                    <button type="submit" class="w-full bg-red-500 hover:bg-red-600 text-white py-2 px-4 rounded-lg">
                        <i class="fas fa-power-off mr-2"></i>
                        Envoyer le paquet Wake-on-LAN
                    </button>
                </form>
                
                <div id="wol-result" class="mt-4 p-4 rounded-lg hidden">
                    <!-- Le résultat sera affiché ici -->
                </div>
            </div>

            <!-- Appareils avec adresse MAC -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-tv text-blue-600"></i>
                    Appareils avec adresse MAC
                </h2>
                
                <?php
                // Récupérer les appareils avec adresse MAC
                try {
                    $stmt = $dbpdointranet->query("
                        SELECT 
                            a.*,
                            CASE 
                                WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'online'
                                WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'idle'
                                ELSE 'offline'
                            END as statut_connexion
                        FROM appareils a
                        WHERE a.adresse_mac IS NOT NULL AND a.adresse_mac != ''
                        ORDER BY a.nom
                    ");
                    $appareils = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (count($appareils) > 0):
                ?>
                <div class="space-y-4">
                    <?php foreach ($appareils as $appareil): ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center">
                            <span class="status-indicator status-<?= $appareil['statut_connexion'] ?>"></span>
                            <div>
                                <p class="font-medium text-gray-800"><?= htmlspecialchars($appareil['nom']) ?></p>
                                <p class="text-xs text-gray-500">
                                    MAC: <?= htmlspecialchars($appareil['adresse_mac']) ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    ID: <?= htmlspecialchars($appareil['identifiant_unique']) ?>
                                </p>
                            </div>
                        </div>
                        
                        <button onclick="wakeDevice('<?= htmlspecialchars($appareil['adresse_mac']) ?>', '<?= htmlspecialchars($appareil['identifiant_unique']) ?>')" 
                                class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm">
                            <i class="fas fa-power-off mr-1"></i>
                            Réveiller
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-info-circle text-blue-500 text-4xl mb-4"></i>
                    <p class="text-lg">Aucun appareil avec adresse MAC</p>
                    <p class="text-sm mt-2">
                        Ajoutez des adresses MAC aux appareils pour pouvoir utiliser Wake-on-LAN
                    </p>
                </div>
                <?php 
                    endif;
                } catch (Exception $e) {
                    echo "<p class='text-red-500'>Erreur: " . htmlspecialchars($e->getMessage()) . "</p>";
                }
                ?>
            </div>
        </div>

        <!-- Informations sur Wake-on-LAN -->
        <div class="mt-8 bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-info-circle text-blue-600"></i>
                Informations sur Wake-on-LAN
            </h2>
            
            <div class="space-y-4">
                <p>
                    <strong>Wake-on-LAN (WoL)</strong> est une norme Ethernet qui permet d'allumer à distance un ordinateur ou un appareil via un message réseau spécial.
                </p>
                
                <h3 class="text-lg font-semibold text-gray-700">Prérequis pour les appareils Fire TV</h3>
                <ul class="list-disc pl-6 space-y-2">
                    <li>L'appareil doit être connecté à l'alimentation</li>
                    <li>L'appareil doit être configuré pour supporter Wake-on-LAN</li>
                    <li>L'adresse MAC de l'appareil doit être connue</li>
                    <li>L'appareil doit être sur le même réseau local que le serveur</li>
                </ul>
                
                <h3 class="text-lg font-semibold text-gray-700">Configuration des appareils Fire TV</h3>
                <ol class="list-decimal pl-6 space-y-2">
                    <li>Activez le mode développeur sur votre Fire TV</li>
                    <li>Installez l'application "Wake On Lan" depuis le store</li>
                    <li>Configurez l'application pour activer le support WoL</li>
                    <li>Notez l'adresse MAC de l'appareil (dans Paramètres > À propos > Réseau)</li>
                    <li>Ajoutez l'adresse MAC dans la base de données pour cet appareil</li>
                </ol>
                
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                <strong>Note :</strong> Tous les modèles de Fire TV ne supportent pas Wake-on-LAN. 
                                Vérifiez la compatibilité de votre appareil avant de configurer cette fonctionnalité.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Soumettre le formulaire
        document.getElementById('wol-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const macAddress = document.getElementById('mac-address').value;
            const broadcastIP = document.getElementById('broadcast-ip').value;
            
            sendWakeOnLan(macAddress, broadcastIP);
        });
        
        // Envoyer un paquet Wake-on-LAN
        function sendWakeOnLan(macAddress, broadcastIP = '255.255.255.255', deviceId = '') {
            // Préparer les données
            const formData = new FormData();
            formData.append('mac_address', macAddress);
            formData.append('broadcast_ip', broadcastIP);
            if (deviceId) {
                formData.append('device_id', deviceId);
            }
            
            // Envoyer la requête
            fetch('wake-on-lan.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Afficher le résultat
                const resultDiv = document.getElementById('wol-result');
                resultDiv.classList.remove('hidden', 'bg-green-100', 'bg-red-100');
                
                if (data.success) {
                    resultDiv.classList.add('bg-green-100');
                    resultDiv.innerHTML = `
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-green-500 mr-2"></i>
                            <div>
                                <p class="font-medium text-green-800">Paquet envoyé avec succès</p>
                                <p class="text-sm text-green-700">
                                    Adresse MAC: ${macAddress}<br>
                                    Broadcast IP: ${broadcastIP}<br>
                                    Octets envoyés: ${data.bytes_sent}
                                </p>
                            </div>
                        </div>
                    `;
                } else {
                    resultDiv.classList.add('bg-red-100');
                    resultDiv.innerHTML = `
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
                            <div>
                                <p class="font-medium text-red-800">Erreur lors de l'envoi du paquet</p>
                                <p class="text-sm text-red-700">${data.error}</p>
                            </div>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                
                // Afficher l'erreur
                const resultDiv = document.getElementById('wol-result');
                resultDiv.classList.remove('hidden', 'bg-green-100', 'bg-red-100');
                resultDiv.classList.add('bg-red-100');
                resultDiv.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
                        <div>
                            <p class="font-medium text-red-800">Erreur lors de la requête</p>
                            <p class="text-sm text-red-700">${error.message}</p>
                        </div>
                    </div>
                `;
            });
        }
        
        // Réveiller un appareil spécifique
        function wakeDevice(macAddress, deviceId) {
            sendWakeOnLan(macAddress, '255.255.255.255', deviceId);
        }
    </script>
</body>
</html>