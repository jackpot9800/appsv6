-- Script SQL pour améliorer la compatibilité avec OVH et la gestion des appareils derrière NAT
-- Ces modifications permettent de mieux gérer les appareils qui partagent la même adresse IP externe

-- 1. Ajout de colonnes pour la gestion des appareils derrière NAT/routeur
ALTER TABLE appareils 
ADD COLUMN IF NOT EXISTS adresse_ip_externe VARCHAR(45) DEFAULT NULL COMMENT 'Adresse IP externe (visible depuis Internet)',
ADD COLUMN IF NOT EXISTS adresse_ip_locale VARCHAR(45) DEFAULT NULL COMMENT 'Adresse IP locale (sur le réseau local)',
ADD COLUMN IF NOT EXISTS port_externe INT DEFAULT NULL COMMENT 'Port externe pour le NAT traversal',
ADD COLUMN IF NOT EXISTS port_local INT DEFAULT NULL COMMENT 'Port local pour le NAT traversal',
ADD COLUMN IF NOT EXISTS identifiant_reseau VARCHAR(100) DEFAULT NULL COMMENT 'Identifiant du réseau (pour grouper les appareils)',
ADD COLUMN IF NOT EXISTS derriere_nat TINYINT(1) DEFAULT 0 COMMENT 'Indique si l\'appareil est derrière un NAT',
ADD COLUMN IF NOT EXISTS derniere_verification_ip TIMESTAMP NULL DEFAULT NULL COMMENT 'Date de dernière vérification IP';

-- 2. Ajout d'index pour améliorer les performances des requêtes
CREATE INDEX IF NOT EXISTS idx_appareils_adresse_ip_externe ON appareils(adresse_ip_externe);
CREATE INDEX IF NOT EXISTS idx_appareils_identifiant_reseau ON appareils(identifiant_reseau);

-- 3. Modification de la table commandes_distantes pour améliorer la fiabilité
ALTER TABLE commandes_distantes
ADD COLUMN IF NOT EXISTS tentatives INT DEFAULT 0 COMMENT 'Nombre de tentatives d\'exécution',
ADD COLUMN IF NOT EXISTS derniere_tentative TIMESTAMP NULL DEFAULT NULL COMMENT 'Date de dernière tentative',
ADD COLUMN IF NOT EXISTS priorite INT DEFAULT 1 COMMENT '1=normale, 2=haute, 3=urgente',
ADD COLUMN IF NOT EXISTS adresse_ip_cible VARCHAR(45) DEFAULT NULL COMMENT 'Adresse IP cible pour la commande';

-- 4. Ajout d'une table pour le suivi des réseaux
CREATE TABLE IF NOT EXISTS reseaux (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifiant VARCHAR(100) NOT NULL UNIQUE,
    nom VARCHAR(255) DEFAULT NULL,
    adresse_ip_externe VARCHAR(45) DEFAULT NULL,
    plage_ip_locale VARCHAR(100) DEFAULT NULL,
    nombre_appareils INT DEFAULT 0,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    derniere_mise_a_jour TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Index pour améliorer les performances
    INDEX idx_reseaux_identifiant (identifiant),
    INDEX idx_reseaux_adresse_ip (adresse_ip_externe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Ajout d'une table pour les tentatives de connexion
CREATE TABLE IF NOT EXISTS tentatives_connexion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifiant_appareil VARCHAR(255) NOT NULL,
    adresse_ip VARCHAR(45) NOT NULL,
    adresse_ip_externe VARCHAR(45) DEFAULT NULL,
    date_tentative TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('success', 'failure') NOT NULL,
    details JSON,
    
    -- Index pour améliorer les performances
    INDEX idx_tentatives_appareil (identifiant_appareil),
    INDEX idx_tentatives_ip (adresse_ip),
    INDEX idx_tentatives_date (date_tentative),
    INDEX idx_tentatives_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Procédure pour identifier les appareils derrière le même NAT
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_IdentifyNATDevices()
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
    
    -- Mettre à jour le nombre d'appareils par réseau
    UPDATE reseaux r
    SET nombre_appareils = (
        SELECT COUNT(*) 
        FROM appareils a 
        WHERE a.identifiant_reseau = r.identifiant
    ),
    derniere_mise_a_jour = NOW();
END //
DELIMITER ;

-- 7. Procédure pour mettre à jour les adresses IP externes
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_UpdateExternalIPs()
BEGIN
    -- Mettre à jour l'adresse IP externe à partir des heartbeats récents
    UPDATE appareils a
    SET 
        a.adresse_ip_externe = a.adresse_ip,
        a.derniere_verification_ip = NOW()
    WHERE a.adresse_ip IS NOT NULL
    AND (a.adresse_ip_externe IS NULL OR a.derniere_verification_ip < DATE_SUB(NOW(), INTERVAL 1 DAY));
    
    -- Identifier les appareils qui ont changé d'adresse IP externe
    INSERT INTO logs_activite (type_action, appareil_id, identifiant_appareil, message, details, adresse_ip)
    SELECT 
        'maintenance',
        a.id,
        a.identifiant_unique,
        CONCAT('Changement d\'adresse IP externe détecté: ', a.adresse_ip_externe, ' -> ', a.adresse_ip),
        JSON_OBJECT(
            'previous_ip', a.adresse_ip_externe,
            'new_ip', a.adresse_ip,
            'timestamp', NOW()
        ),
        a.adresse_ip
    FROM appareils a
    WHERE a.adresse_ip != a.adresse_ip_externe
    AND a.adresse_ip_externe IS NOT NULL
    AND a.adresse_ip IS NOT NULL;
    
    -- Mettre à jour l'adresse IP externe
    UPDATE appareils
    SET 
        adresse_ip_externe = adresse_ip,
        derniere_verification_ip = NOW()
    WHERE adresse_ip != adresse_ip_externe
    AND adresse_ip_externe IS NOT NULL
    AND adresse_ip IS NOT NULL;
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
    INSERT INTO commandes_distantes (identifiant_appareil, commande, parametres, statut, date_creation, priorite)
    SELECT 
        a.identifiant_unique,
        p_command,
        p_parameters,
        'en_attente',
        NOW(),
        2 -- Priorité haute
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

-- 9. Procédure pour détecter les problèmes de connectivité OVH
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_DetectOVHConnectivityIssues()
BEGIN
    -- Identifier les réseaux avec des problèmes de connectivité
    SELECT 
        r.identifiant as identifiant_reseau,
        r.adresse_ip_externe,
        COUNT(a.id) as nombre_appareils_total,
        SUM(CASE WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END) as appareils_en_ligne,
        (COUNT(a.id) - SUM(CASE WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END)) as appareils_hors_ligne,
        CASE 
            WHEN (COUNT(a.id) - SUM(CASE WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END)) = COUNT(a.id) THEN 'Tous les appareils hors ligne'
            WHEN (COUNT(a.id) - SUM(CASE WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END)) > 0 THEN 'Certains appareils hors ligne'
            ELSE 'Tous les appareils en ligne'
        END as statut_reseau
    FROM reseaux r
    JOIN appareils a ON r.identifiant = a.identifiant_reseau
    WHERE a.statut = 'actif'
    GROUP BY r.identifiant, r.adresse_ip_externe
    HAVING COUNT(a.id) > 1 -- Au moins 2 appareils dans le réseau
    AND (COUNT(a.id) - SUM(CASE WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END)) > 0; -- Au moins un appareil hors ligne
    
    -- Créer une alerte si tous les appareils d'un réseau sont hors ligne (problème de connectivité OVH)
    INSERT INTO alertes (type_alerte, niveau_severite, message, details)
    SELECT 
        'network',
        'critical',
        CONCAT('Problème de connectivité réseau détecté pour ', r.adresse_ip_externe),
        JSON_OBJECT(
            'network_id', r.identifiant,
            'external_ip', r.adresse_ip_externe,
            'total_devices', COUNT(a.id),
            'offline_devices', COUNT(a.id),
            'timestamp', NOW()
        )
    FROM reseaux r
    JOIN appareils a ON r.identifiant = a.identifiant_reseau
    WHERE a.statut = 'actif'
    GROUP BY r.identifiant, r.adresse_ip_externe
    HAVING COUNT(a.id) > 1 -- Au moins 2 appareils dans le réseau
    AND SUM(CASE WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END) = 0 -- Tous les appareils hors ligne
    AND NOT EXISTS (
        SELECT 1 FROM alertes 
        WHERE type_alerte = 'network' 
        AND message LIKE CONCAT('Problème de connectivité réseau détecté pour ', r.adresse_ip_externe, '%')
        AND statut = 'active'
    );
END //
DELIMITER ;

-- 10. Procédure pour gérer les tentatives de reconnexion
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_ManageReconnectionAttempts()
BEGIN
    -- Identifier les appareils qui tentent de se reconnecter fréquemment
    SELECT 
        identifiant_appareil,
        COUNT(*) as nombre_tentatives,
        MAX(date_tentative) as derniere_tentative
    FROM tentatives_connexion
    WHERE date_tentative > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    GROUP BY identifiant_appareil
    HAVING COUNT(*) > 10; -- Plus de 10 tentatives en 1 heure
    
    -- Créer une alerte pour les appareils avec trop de tentatives de reconnexion
    INSERT INTO alertes (appareil_id, identifiant_appareil, type_alerte, niveau_severite, message, details)
    SELECT 
        a.id,
        tc.identifiant_appareil,
        'system',
        'warning',
        CONCAT('Tentatives de reconnexion fréquentes: ', COUNT(*), ' en 1 heure'),
        JSON_OBJECT(
            'attempts_count', COUNT(*),
            'last_attempt', MAX(tc.date_tentative),
            'device_name', a.nom,
            'timestamp', NOW()
        )
    FROM tentatives_connexion tc
    JOIN appareils a ON tc.identifiant_appareil = a.identifiant_unique
    WHERE tc.date_tentative > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    GROUP BY tc.identifiant_appareil, a.id, a.nom
    HAVING COUNT(*) > 10 -- Plus de 10 tentatives en 1 heure
    AND NOT EXISTS (
        SELECT 1 FROM alertes 
        WHERE type_alerte = 'system' 
        AND message LIKE 'Tentatives de reconnexion fréquentes%'
        AND statut = 'active'
        AND identifiant_appareil = tc.identifiant_appareil
    );
END //
DELIMITER ;

-- Afficher confirmation
SELECT 'Tables mises à jour avec succès pour la compatibilité OVH et la gestion des appareils derrière NAT' as message;