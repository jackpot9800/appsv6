-- Script SQL pour créer des procédures stockées pour le monitoring des appareils Fire TV
-- Ces procédures facilitent la gestion et la maintenance des appareils

-- 1. Procédure pour nettoyer les anciennes données
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_CleanupOldData()
BEGIN
    -- Supprimer les commandes exécutées de plus de 7 jours
    DELETE FROM commandes_distantes 
    WHERE statut IN ('executee', 'echouee', 'expiree') 
    AND date_creation < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- Marquer comme expirées les commandes en attente de plus de 1 heure
    UPDATE commandes_distantes 
    SET statut = 'expiree', date_expiration = NOW()
    WHERE statut = 'en_attente' 
    AND date_creation < DATE_SUB(NOW(), INTERVAL 1 HOUR);
    
    -- Supprimer les anciennes métriques (garder seulement 30 jours)
    DELETE FROM metriques_performance 
    WHERE date_mesure < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- Supprimer les logs de connexion de plus de 30 jours
    DELETE FROM logs_activite 
    WHERE type_action = 'connexion' 
    AND date_action < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- Supprimer les logs d'erreur de plus de 90 jours
    DELETE FROM logs_activite 
    WHERE type_action = 'erreur' 
    AND date_action < DATE_SUB(NOW(), INTERVAL 90 DAY);
    
    -- Supprimer les autres logs de plus de 60 jours
    DELETE FROM logs_activite 
    WHERE type_action NOT IN ('connexion', 'erreur') 
    AND date_action < DATE_SUB(NOW(), INTERVAL 60 DAY);
    
    -- Supprimer les tentatives de connexion de plus de 30 jours
    DELETE FROM tentatives_connexion
    WHERE date_tentative < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- Résoudre automatiquement les alertes anciennes
    UPDATE alertes
    SET statut = 'resolved', date_resolution = NOW()
    WHERE statut = 'active'
    AND date_creation < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- Supprimer les alertes résolues de plus de 30 jours
    DELETE FROM alertes
    WHERE statut = 'resolved'
    AND date_resolution < DATE_SUB(NOW(), INTERVAL 30 DAY);
END //
DELIMITER ;

-- 2. Procédure pour détecter les appareils hors ligne
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_DetectOfflineDevices()
BEGIN
    -- Identifier les appareils qui n'ont pas envoyé de heartbeat depuis plus de 10 minutes
    INSERT INTO alertes (appareil_id, identifiant_appareil, type_alerte, niveau_severite, message, details)
    SELECT 
        a.id,
        a.identifiant_unique,
        'offline',
        CASE 
            WHEN TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()) > 60 THEN 'critical'
            WHEN TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()) > 30 THEN 'error'
            ELSE 'warning'
        END,
        CONCAT('Appareil hors ligne depuis ', TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()), ' minutes'),
        JSON_OBJECT(
            'last_seen', a.derniere_connexion,
            'minutes_offline', TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()),
            'device_name', a.nom,
            'ip_address', a.adresse_ip
        )
    FROM appareils a
    WHERE a.statut = 'actif'
    AND a.derniere_connexion < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    AND NOT EXISTS (
        SELECT 1 FROM alertes 
        WHERE type_alerte = 'offline' 
        AND statut = 'active'
        AND identifiant_appareil = a.identifiant_unique
    );
    
    -- Résoudre automatiquement les alertes pour les appareils revenus en ligne
    UPDATE alertes al
    JOIN appareils a ON al.identifiant_appareil = a.identifiant_unique
    SET 
        al.statut = 'resolved',
        al.date_resolution = NOW()
    WHERE al.type_alerte = 'offline'
    AND al.statut = 'active'
    AND a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 5 MINUTE);
    
    -- Mettre à jour le statut des appareils hors ligne
    UPDATE appareils
    SET statut_temps_reel = 'offline'
    WHERE derniere_connexion < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    AND statut_temps_reel != 'offline';
END //
DELIMITER ;

-- 3. Procédure pour détecter les problèmes de performance
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_DetectPerformanceIssues()
BEGIN
    -- Identifier les appareils avec une utilisation mémoire élevée
    INSERT INTO alertes (appareil_id, identifiant_appareil, type_alerte, niveau_severite, message, details)
    SELECT 
        a.id,
        a.identifiant_unique,
        'performance',
        CASE 
            WHEN a.utilisation_memoire > 90 THEN 'critical'
            WHEN a.utilisation_memoire > 80 THEN 'error'
            ELSE 'warning'
        END,
        CONCAT('Utilisation mémoire élevée: ', a.utilisation_memoire, '%'),
        JSON_OBJECT(
            'memory_usage', a.utilisation_memoire,
            'device_name', a.nom,
            'timestamp', NOW()
        )
    FROM appareils a
    WHERE a.statut = 'actif'
    AND a.utilisation_memoire > 80
    AND a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    AND NOT EXISTS (
        SELECT 1 FROM alertes 
        WHERE type_alerte = 'performance' 
        AND message LIKE 'Utilisation mémoire élevée%'
        AND statut = 'active'
        AND identifiant_appareil = a.identifiant_unique
    );
    
    -- Identifier les appareils avec un signal WiFi faible
    INSERT INTO alertes (appareil_id, identifiant_appareil, type_alerte, niveau_severite, message, details)
    SELECT 
        a.id,
        a.identifiant_unique,
        'performance',
        CASE 
            WHEN a.force_wifi < 30 THEN 'error'
            WHEN a.force_wifi < 50 THEN 'warning'
            ELSE 'info'
        END,
        CONCAT('Signal WiFi faible: ', a.force_wifi, '%'),
        JSON_OBJECT(
            'wifi_strength', a.force_wifi,
            'device_name', a.nom,
            'timestamp', NOW()
        )
    FROM appareils a
    WHERE a.statut = 'actif'
    AND a.force_wifi < 50
    AND a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    AND NOT EXISTS (
        SELECT 1 FROM alertes 
        WHERE type_alerte = 'performance' 
        AND message LIKE 'Signal WiFi faible%'
        AND statut = 'active'
        AND identifiant_appareil = a.identifiant_unique
    );
END //
DELIMITER ;

-- 4. Procédure pour détecter les changements d'adresse IP
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_DetectIPChanges()
BEGIN
    -- Identifier les appareils qui ont changé d'adresse IP externe
    INSERT INTO alertes (appareil_id, identifiant_appareil, type_alerte, niveau_severite, message, details)
    SELECT 
        a.id,
        a.identifiant_unique,
        'security',
        'warning',
        CONCAT('Changement d\'adresse IP externe pour l\'appareil ', a.nom),
        JSON_OBJECT(
            'previous_ip', a.adresse_ip,
            'new_ip', a.adresse_ip_externe,
            'device_name', a.nom,
            'timestamp', NOW()
        )
    FROM appareils a
    WHERE a.adresse_ip != a.adresse_ip_externe
    AND a.adresse_ip_externe IS NOT NULL
    AND a.adresse_ip IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM alertes 
        WHERE type_alerte = 'security' 
        AND message LIKE CONCAT('Changement d\'adresse IP externe pour l\'appareil ', a.nom, '%')
        AND statut = 'active'
        AND identifiant_appareil = a.identifiant_unique
    );
    
    -- Mettre à jour l'adresse IP
    UPDATE appareils
    SET 
        adresse_ip = adresse_ip_externe,
        derniere_verification_ip = NOW()
    WHERE adresse_ip != adresse_ip_externe
    AND adresse_ip_externe IS NOT NULL;
END //
DELIMITER ;

-- 5. Procédure pour envoyer des commandes de redémarrage aux appareils hors ligne
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_RestartOfflineDevices()
BEGIN
    -- Envoyer une commande de redémarrage aux appareils hors ligne depuis plus de 30 minutes
    INSERT INTO commandes_distantes (identifiant_appareil, commande, parametres, statut, date_creation, priorite)
    SELECT 
        a.identifiant_unique,
        'reboot',
        '{}',
        'en_attente',
        NOW(),
        3 -- Priorité urgente
    FROM appareils a
    WHERE a.statut = 'actif'
    AND a.derniere_connexion < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    AND NOT EXISTS (
        -- Vérifier qu'il n'y a pas déjà une commande de redémarrage en attente
        SELECT 1 FROM commandes_distantes 
        WHERE identifiant_appareil = a.identifiant_unique
        AND commande = 'reboot'
        AND statut = 'en_attente'
    )
    AND NOT EXISTS (
        -- Vérifier qu'il n'y a pas eu de commande de redémarrage récente
        SELECT 1 FROM commandes_distantes 
        WHERE identifiant_appareil = a.identifiant_unique
        AND commande = 'reboot'
        AND date_creation > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    );
    
    -- Enregistrer un log pour chaque commande de redémarrage
    INSERT INTO logs_activite (type_action, appareil_id, identifiant_appareil, message, details, adresse_ip)
    SELECT 
        'maintenance',
        a.id,
        a.identifiant_unique,
        'Redémarrage automatique programmé pour appareil hors ligne',
        JSON_OBJECT(
            'minutes_offline', TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()),
            'last_seen', a.derniere_connexion,
            'auto_restart', true
        ),
        '127.0.0.1' -- Adresse IP locale car c'est un redémarrage automatique
    FROM appareils a
    JOIN commandes_distantes c ON a.identifiant_unique = c.identifiant_appareil
    WHERE c.commande = 'reboot'
    AND c.date_creation > DATE_SUB(NOW(), INTERVAL 1 MINUTE);
END //
DELIMITER ;

-- 6. Procédure pour générer des statistiques quotidiennes
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_GenerateDailyStats()
BEGIN
    -- Statistiques des appareils
    SELECT 
        COUNT(*) as total_appareils,
        SUM(CASE WHEN derniere_connexion > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as appareils_actifs_24h,
        SUM(CASE WHEN derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 1 ELSE 0 END) as appareils_en_ligne,
        SUM(CASE WHEN statut_temps_reel = 'playing' THEN 1 ELSE 0 END) as appareils_en_diffusion,
        AVG(utilisation_memoire) as memoire_moyenne,
        AVG(force_wifi) as wifi_moyen
    FROM appareils;
    
    -- Statistiques des présentations
    SELECT 
        COUNT(DISTINCT presentation_courante_id) as presentations_actives,
        (SELECT COUNT(*) FROM presentations WHERE statut = 'actif') as presentations_disponibles
    FROM appareils
    WHERE statut_temps_reel = 'playing';
    
    -- Statistiques des commandes
    SELECT 
        COUNT(*) as total_commandes,
        SUM(CASE WHEN statut = 'executee' THEN 1 ELSE 0 END) as commandes_executees,
        SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as commandes_en_attente,
        SUM(CASE WHEN statut = 'echouee' THEN 1 ELSE 0 END) as commandes_echouees,
        AVG(TIMESTAMPDIFF(SECOND, date_creation, date_execution)) as temps_execution_moyen
    FROM commandes_distantes
    WHERE date_creation > DATE_SUB(NOW(), INTERVAL 24 HOUR);
    
    -- Statistiques des alertes
    SELECT 
        COUNT(*) as total_alertes,
        SUM(CASE WHEN statut = 'active' THEN 1 ELSE 0 END) as alertes_actives,
        SUM(CASE WHEN niveau_severite = 'critical' AND statut = 'active' THEN 1 ELSE 0 END) as alertes_critiques,
        SUM(CASE WHEN type_alerte = 'offline' AND statut = 'active' THEN 1 ELSE 0 END) as appareils_hors_ligne
    FROM alertes;
END //
DELIMITER ;

-- 7. Procédure pour identifier les appareils derrière le même NAT/routeur
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_IdentifyNetworks()
BEGIN
    -- Identifier les réseaux en fonction des adresses IP externes
    INSERT INTO reseaux (identifiant, nom, adresse_ip_externe, date_creation)
    SELECT 
        DISTINCT CONCAT('network_', REPLACE(a.adresse_ip_externe, '.', '_')),
        CONCAT('Réseau ', a.adresse_ip_externe),
        a.adresse_ip_externe,
        NOW()
    FROM appareils a
    WHERE a.adresse_ip_externe IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM reseaux 
        WHERE adresse_ip_externe = a.adresse_ip_externe
    );
    
    -- Associer les appareils à leur réseau
    UPDATE appareils a
    JOIN reseaux r ON a.adresse_ip_externe = r.adresse_ip_externe
    SET 
        a.identifiant_reseau = r.identifiant,
        a.derriere_nat = 1
    WHERE a.identifiant_reseau IS NULL
    AND a.adresse_ip_externe IS NOT NULL;
    
    -- Identifier les appareils derrière le même NAT
    SELECT 
        r.identifiant as identifiant_reseau,
        r.adresse_ip_externe,
        COUNT(a.id) as nombre_appareils,
        GROUP_CONCAT(a.nom SEPARATOR ', ') as liste_appareils
    FROM reseaux r
    JOIN appareils a ON r.identifiant = a.identifiant_reseau
    GROUP BY r.identifiant, r.adresse_ip_externe
    HAVING COUNT(a.id) > 1;
END //
DELIMITER ;

-- 8. Procédure pour envoyer une commande à tous les appareils d'un réseau
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_SendCommandToNetwork(
    IN p_network_id VARCHAR(100),
    IN p_command VARCHAR(50),
    IN p_parameters JSON
)
BEGIN
    -- Envoyer la commande à tous les appareils du réseau
    INSERT INTO commandes_distantes (identifiant_appareil, commande, parametres, statut, date_creation)
    SELECT 
        a.identifiant_unique,
        p_command,
        p_parameters,
        'en_attente',
        NOW()
    FROM appareils a
    WHERE a.identifiant_reseau = p_network_id
    AND a.statut = 'actif';
    
    -- Enregistrer un log pour chaque commande
    INSERT INTO logs_activite (type_action, appareil_id, identifiant_appareil, message, details, adresse_ip)
    SELECT 
        'commande_distante',
        a.id,
        a.identifiant_unique,
        CONCAT('Commande réseau envoyée: ', p_command),
        JSON_OBJECT(
            'command', p_command,
            'parameters', p_parameters,
            'network_id', p_network_id,
            'sent_by', 'system'
        ),
        '127.0.0.1'
    FROM appareils a
    WHERE a.identifiant_reseau = p_network_id
    AND a.statut = 'actif';
    
    -- Retourner le nombre d'appareils ciblés
    SELECT COUNT(*) as appareils_cibles
    FROM appareils
    WHERE identifiant_reseau = p_network_id
    AND statut = 'actif';
END //
DELIMITER ;

-- 9. Procédure pour vérifier la santé globale du système
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_CheckSystemHealth()
BEGIN
    -- Vérifier les appareils hors ligne
    SELECT COUNT(*) as appareils_hors_ligne
    FROM appareils
    WHERE statut = 'actif'
    AND derniere_connexion < DATE_SUB(NOW(), INTERVAL 10 MINUTE);
    
    -- Vérifier les commandes en attente depuis longtemps
    SELECT COUNT(*) as commandes_en_attente_longue
    FROM commandes_distantes
    WHERE statut = 'en_attente'
    AND date_creation < DATE_SUB(NOW(), INTERVAL 30 MINUTE);
    
    -- Vérifier les erreurs récentes
    SELECT COUNT(*) as erreurs_recentes
    FROM logs_activite
    WHERE type_action = 'erreur'
    AND date_action > DATE_SUB(NOW(), INTERVAL 24 HOUR);
    
    -- Vérifier les alertes critiques
    SELECT COUNT(*) as alertes_critiques
    FROM alertes
    WHERE niveau_severite = 'critical'
    AND statut = 'active';
    
    -- Vérifier l'espace disque utilisé par les tables
    SELECT 
        table_name,
        ROUND(((data_length + index_length) / 1024 / 1024), 2) as taille_mb
    FROM information_schema.TABLES 
    WHERE table_schema = DATABASE()
    ORDER BY (data_length + index_length) DESC
    LIMIT 10;
END //
DELIMITER ;

-- Afficher confirmation
SELECT 'Procédures stockées créées avec succès pour le monitoring des appareils Fire TV' as message;