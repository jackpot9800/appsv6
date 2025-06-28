-- Script SQL pour mettre à jour les tables pour le monitoring à distance des appareils Fire TV
-- Ce script ajoute des colonnes pour le suivi en temps réel et la gestion des appareils à travers Internet

-- 1. Mise à jour de la table appareils pour le suivi en temps réel
ALTER TABLE appareils 
ADD COLUMN IF NOT EXISTS statut_temps_reel ENUM('online', 'offline', 'playing', 'paused', 'error') DEFAULT 'offline',
ADD COLUMN IF NOT EXISTS presentation_courante_id INT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS presentation_courante_nom VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS slide_courant_index INT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS total_slides INT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS mode_boucle TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS lecture_automatique TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS uptime_secondes INT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS utilisation_memoire INT DEFAULT NULL COMMENT 'Pourcentage d\'utilisation mémoire',
ADD COLUMN IF NOT EXISTS force_wifi INT DEFAULT NULL COMMENT 'Force du signal WiFi en pourcentage',
ADD COLUMN IF NOT EXISTS message_erreur TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS adresse_ip_externe VARCHAR(45) DEFAULT NULL COMMENT 'Adresse IP externe de l\'appareil',
ADD COLUMN IF NOT EXISTS adresse_ip_locale VARCHAR(45) DEFAULT NULL COMMENT 'Adresse IP locale de l\'appareil',
ADD COLUMN IF NOT EXISTS derniere_verification_ip TIMESTAMP NULL DEFAULT NULL;

-- 2. Création d'index pour améliorer les performances des requêtes de monitoring
CREATE INDEX IF NOT EXISTS idx_appareils_statut_temps_reel ON appareils(statut_temps_reel);
CREATE INDEX IF NOT EXISTS idx_appareils_presentation_courante ON appareils(presentation_courante_id);
CREATE INDEX IF NOT EXISTS idx_appareils_derniere_connexion ON appareils(derniere_connexion);
CREATE INDEX IF NOT EXISTS idx_appareils_adresse_ip_externe ON appareils(adresse_ip_externe);

-- 3. Mise à jour de la table commandes_distantes pour améliorer le suivi des commandes
ALTER TABLE commandes_distantes
ADD COLUMN IF NOT EXISTS adresse_ip_source VARCHAR(45) DEFAULT NULL COMMENT 'Adresse IP de la source de la commande',
ADD COLUMN IF NOT EXISTS tentatives INT DEFAULT 0 COMMENT 'Nombre de tentatives d\'exécution',
ADD COLUMN IF NOT EXISTS derniere_tentative TIMESTAMP NULL DEFAULT NULL,
ADD COLUMN IF NOT EXISTS priorite INT DEFAULT 1 COMMENT '1=normale, 2=haute, 3=urgente';

-- 4. Création d'une table pour les sessions de diffusion (pour tracking détaillé)
CREATE TABLE IF NOT EXISTS sessions_diffusion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appareil_id INT NOT NULL,
    identifiant_appareil VARCHAR(255) NOT NULL,
    presentation_id INT NOT NULL,
    date_debut TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_fin TIMESTAMP NULL DEFAULT NULL,
    duree_totale_secondes INT DEFAULT NULL,
    slides_vues INT DEFAULT 0,
    boucles_completees INT DEFAULT 0,
    interruptions INT DEFAULT 0,
    statut_final ENUM('complete', 'interrompue', 'erreur') DEFAULT 'complete',
    adresse_ip VARCHAR(45) DEFAULT NULL,
    
    -- Clés étrangères
    FOREIGN KEY (appareil_id) REFERENCES appareils(id) ON DELETE CASCADE,
    FOREIGN KEY (presentation_id) REFERENCES presentations(id) ON DELETE CASCADE,
    
    -- Index pour améliorer les performances
    INDEX idx_sessions_appareil (appareil_id),
    INDEX idx_sessions_presentation (presentation_id),
    INDEX idx_sessions_date_debut (date_debut),
    INDEX idx_sessions_statut (statut_final),
    INDEX idx_sessions_identifiant_appareil (identifiant_appareil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Création d'une table pour les métriques de performance
CREATE TABLE IF NOT EXISTS metriques_performance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appareil_id INT NOT NULL,
    identifiant_appareil VARCHAR(255) NOT NULL,
    date_mesure TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    utilisation_cpu DECIMAL(5,2) DEFAULT NULL COMMENT 'Pourcentage d\'utilisation CPU',
    utilisation_memoire DECIMAL(5,2) DEFAULT NULL COMMENT 'Pourcentage d\'utilisation mémoire',
    utilisation_stockage DECIMAL(5,2) DEFAULT NULL COMMENT 'Pourcentage d\'utilisation stockage',
    force_wifi INT DEFAULT NULL COMMENT 'Force du signal WiFi (0-100)',
    temperature_cpu DECIMAL(5,2) DEFAULT NULL COMMENT 'Température CPU en Celsius',
    fps_moyen DECIMAL(5,2) DEFAULT NULL COMMENT 'FPS moyen de l\'affichage',
    latence_reseau INT DEFAULT NULL COMMENT 'Latence réseau en ms',
    adresse_ip VARCHAR(45) DEFAULT NULL,
    
    -- Clé étrangère
    FOREIGN KEY (appareil_id) REFERENCES appareils(id) ON DELETE CASCADE,
    
    -- Index pour améliorer les performances
    INDEX idx_metriques_appareil (appareil_id),
    INDEX idx_metriques_date (date_mesure),
    INDEX idx_metriques_appareil_date (appareil_id, date_mesure),
    INDEX idx_metriques_identifiant_appareil (identifiant_appareil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Création d'une table pour les alertes et notifications
CREATE TABLE IF NOT EXISTS alertes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appareil_id INT NULL,
    identifiant_appareil VARCHAR(255) NULL,
    type_alerte ENUM('offline', 'error', 'performance', 'security', 'system') NOT NULL,
    niveau_severite ENUM('info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    details JSON,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_resolution TIMESTAMP NULL DEFAULT NULL,
    statut ENUM('active', 'resolved', 'ignored') NOT NULL DEFAULT 'active',
    
    -- Clé étrangère
    FOREIGN KEY (appareil_id) REFERENCES appareils(id) ON DELETE SET NULL,
    
    -- Index pour améliorer les performances
    INDEX idx_alertes_appareil (appareil_id),
    INDEX idx_alertes_type (type_alerte),
    INDEX idx_alertes_statut (statut),
    INDEX idx_alertes_date (date_creation),
    INDEX idx_alertes_identifiant_appareil (identifiant_appareil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Procédure stockée pour nettoyer les anciennes commandes et métriques
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS CleanupOldData()
BEGIN
    -- Supprimer les commandes exécutées de plus de 7 jours
    DELETE FROM commandes_distantes 
    WHERE statut IN ('executee', 'echouee') 
    AND date_creation < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- Marquer comme expirées les commandes en attente de plus de 1 heure
    UPDATE commandes_distantes 
    SET statut = 'expiree', date_expiration = NOW()
    WHERE statut = 'en_attente' 
    AND date_creation < DATE_SUB(NOW(), INTERVAL 1 HOUR);
    
    -- Supprimer les anciennes métriques (garder seulement 30 jours)
    DELETE FROM metriques_performance 
    WHERE date_mesure < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- Archiver les sessions de diffusion terminées de plus de 30 jours
    -- (Dans une version future, on pourrait créer une table d'archives)
    DELETE FROM sessions_diffusion
    WHERE date_fin IS NOT NULL
    AND date_fin < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- Résoudre automatiquement les alertes anciennes
    UPDATE alertes
    SET statut = 'resolved', date_resolution = NOW()
    WHERE statut = 'active'
    AND date_creation < DATE_SUB(NOW(), INTERVAL 7 DAY);
END //
DELIMITER ;

-- 8. Événement pour nettoyer automatiquement les anciennes données
CREATE EVENT IF NOT EXISTS cleanup_old_data
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
  CALL CleanupOldData();

-- 9. Procédure pour détecter les appareils hors ligne
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS DetectOfflineDevices()
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
            'device_name', a.nom
        )
    FROM appareils a
    WHERE a.statut = 'actif'
    AND a.derniere_connexion < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    AND a.identifiant_unique NOT IN (
        SELECT identifiant_appareil FROM alertes 
        WHERE type_alerte = 'offline' 
        AND statut = 'active'
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
END //
DELIMITER ;

-- 10. Événement pour détecter les appareils hors ligne
CREATE EVENT IF NOT EXISTS detect_offline_devices
ON SCHEDULE EVERY 5 MINUTE
STARTS CURRENT_TIMESTAMP
DO
  CALL DetectOfflineDevices();

-- 11. Ajout d'une colonne pour le suivi des adresses IP multiples
ALTER TABLE logs_activite
ADD COLUMN IF NOT EXISTS adresse_ip_externe VARCHAR(45) DEFAULT NULL COMMENT 'Adresse IP externe de l\'appareil';

-- 12. Ajout d'une colonne pour le suivi des NAT/routeurs
ALTER TABLE appareils
ADD COLUMN IF NOT EXISTS derriere_nat TINYINT(1) DEFAULT 0 COMMENT 'Indique si l\'appareil est derrière un NAT',
ADD COLUMN IF NOT EXISTS identifiant_reseau VARCHAR(100) DEFAULT NULL COMMENT 'Identifiant unique du réseau';

-- 13. Ajout d'une table pour le suivi des réseaux
CREATE TABLE IF NOT EXISTS reseaux (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifiant VARCHAR(100) NOT NULL UNIQUE,
    nom VARCHAR(255) DEFAULT NULL,
    adresse_ip_externe VARCHAR(45) DEFAULT NULL,
    plage_ip_locale VARCHAR(100) DEFAULT NULL,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    derniere_mise_a_jour TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Index pour améliorer les performances
    INDEX idx_reseaux_identifiant (identifiant),
    INDEX idx_reseaux_adresse_ip (adresse_ip_externe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Ajout d'une table pour les tentatives de connexion
CREATE TABLE IF NOT EXISTS tentatives_connexion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifiant_appareil VARCHAR(255) NOT NULL,
    adresse_ip VARCHAR(45) NOT NULL,
    date_tentative TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('success', 'failure') NOT NULL,
    details JSON,
    
    -- Index pour améliorer les performances
    INDEX idx_tentatives_appareil (identifiant_appareil),
    INDEX idx_tentatives_ip (adresse_ip),
    INDEX idx_tentatives_date (date_tentative),
    INDEX idx_tentatives_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Ajout d'une procédure pour détecter les changements d'adresse IP
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS DetectIPChanges()
BEGIN
    -- Identifier les appareils qui ont changé d'adresse IP
    INSERT INTO alertes (appareil_id, identifiant_appareil, type_alerte, niveau_severite, message, details)
    SELECT 
        a.id,
        a.identifiant_unique,
        'security',
        'warning',
        CONCAT('Changement d\'adresse IP détecté pour l\'appareil ', a.nom),
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
    AND a.identifiant_unique NOT IN (
        SELECT identifiant_appareil FROM alertes 
        WHERE type_alerte = 'security' 
        AND message LIKE 'Changement d\'adresse IP%'
        AND statut = 'active'
    );
    
    -- Mettre à jour l'adresse IP
    UPDATE appareils
    SET adresse_ip = adresse_ip_externe
    WHERE adresse_ip != adresse_ip_externe
    AND adresse_ip_externe IS NOT NULL;
END //
DELIMITER ;

-- 16. Événement pour détecter les changements d'adresse IP
CREATE EVENT IF NOT EXISTS detect_ip_changes
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO
  CALL DetectIPChanges();

-- Afficher confirmation
SELECT 'Tables mises à jour avec succès pour le monitoring à distance des appareils Fire TV' as message;