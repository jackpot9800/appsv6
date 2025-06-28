-- Script SQL pour créer des déclencheurs (triggers) pour le monitoring des appareils Fire TV
-- Ces déclencheurs automatisent certaines actions lors des modifications de données

-- 1. Trigger pour mettre à jour les statistiques de présentation lors de l'assignation
DELIMITER //
CREATE TRIGGER IF NOT EXISTS after_diffusion_insert
AFTER INSERT ON diffusions
FOR EACH ROW
BEGIN
    -- Mettre à jour le nombre de diffusions de la présentation
    UPDATE presentations
    SET nombre_diffusions = (
        SELECT COUNT(*) 
        FROM diffusions 
        WHERE presentation_id = NEW.presentation_id
    )
    WHERE id = NEW.presentation_id;
    
    -- Enregistrer un log d'activité
    INSERT INTO logs_activite (
        type_action, 
        appareil_id, 
        identifiant_appareil, 
        presentation_id, 
        message, 
        details
    )
    SELECT 
        'diffusion',
        a.id,
        NEW.identifiant_appareil,
        NEW.presentation_id,
        CONCAT('Nouvelle diffusion assignée: ', p.nom),
        JSON_OBJECT(
            'presentation_id', NEW.presentation_id,
            'presentation_name', p.nom,
            'auto_play', NEW.lecture_automatique,
            'loop_mode', NEW.mode_boucle,
            'priority', NEW.priorite
        )
    FROM appareils a
    JOIN presentations p ON p.id = NEW.presentation_id
    WHERE a.identifiant_unique = NEW.identifiant_appareil;
END //
DELIMITER ;

-- 2. Trigger pour enregistrer les changements de statut des appareils
DELIMITER //
CREATE TRIGGER IF NOT EXISTS after_appareil_update
AFTER UPDATE ON appareils
FOR EACH ROW
BEGIN
    -- Si le statut temps réel a changé
    IF NEW.statut_temps_reel != OLD.statut_temps_reel THEN
        INSERT INTO logs_activite (
            type_action, 
            appareil_id, 
            identifiant_appareil, 
            message, 
            details
        )
        VALUES (
            'connexion',
            NEW.id,
            NEW.identifiant_unique,
            CONCAT('Changement de statut: ', OLD.statut_temps_reel, ' -> ', NEW.statut_temps_reel),
            JSON_OBJECT(
                'old_status', OLD.statut_temps_reel,
                'new_status', NEW.statut_temps_reel,
                'timestamp', NOW()
            )
        );
    END IF;
    
    -- Si la présentation courante a changé
    IF (NEW.presentation_courante_id != OLD.presentation_courante_id OR 
        (NEW.presentation_courante_id IS NOT NULL AND OLD.presentation_courante_id IS NULL) OR
        (NEW.presentation_courante_id IS NULL AND OLD.presentation_courante_id IS NOT NULL)) THEN
        
        INSERT INTO logs_activite (
            type_action, 
            appareil_id, 
            identifiant_appareil, 
            presentation_id,
            message, 
            details
        )
        VALUES (
            'diffusion',
            NEW.id,
            NEW.identifiant_unique,
            NEW.presentation_courante_id,
            CONCAT('Changement de présentation: ', 
                   IFNULL(OLD.presentation_courante_nom, 'Aucune'), 
                   ' -> ', 
                   IFNULL(NEW.presentation_courante_nom, 'Aucune')),
            JSON_OBJECT(
                'old_presentation_id', OLD.presentation_courante_id,
                'new_presentation_id', NEW.presentation_courante_id,
                'old_presentation_name', OLD.presentation_courante_nom,
                'new_presentation_name', NEW.presentation_courante_nom,
                'timestamp', NOW()
            )
        );
    END IF;
    
    -- Si l'adresse IP a changé
    IF NEW.adresse_ip != OLD.adresse_ip AND NEW.adresse_ip IS NOT NULL THEN
        INSERT INTO logs_activite (
            type_action, 
            appareil_id, 
            identifiant_appareil, 
            message, 
            details
        )
        VALUES (
            'maintenance',
            NEW.id,
            NEW.identifiant_unique,
            CONCAT('Changement d\'adresse IP: ', 
                   IFNULL(OLD.adresse_ip, 'Non définie'), 
                   ' -> ', 
                   NEW.adresse_ip),
            JSON_OBJECT(
                'old_ip', OLD.adresse_ip,
                'new_ip', NEW.adresse_ip,
                'timestamp', NOW()
            )
        );
    END IF;
END //
DELIMITER ;

-- 3. Trigger pour enregistrer les exécutions de commandes
DELIMITER //
CREATE TRIGGER IF NOT EXISTS after_commande_update
AFTER UPDATE ON commandes_distantes
FOR EACH ROW
BEGIN
    -- Si le statut de la commande a changé à 'executee' ou 'echouee'
    IF (NEW.statut = 'executee' OR NEW.statut = 'echouee') AND OLD.statut = 'en_attente' THEN
        INSERT INTO logs_activite (
            type_action, 
            identifiant_appareil, 
            message, 
            details
        )
        VALUES (
            'commande_distante',
            NEW.identifiant_appareil,
            CONCAT('Commande ', NEW.commande, ' ', 
                   CASE WHEN NEW.statut = 'executee' THEN 'exécutée' ELSE 'échouée' END),
            JSON_OBJECT(
                'command_id', NEW.id,
                'command', NEW.commande,
                'parameters', NEW.parametres,
                'status', NEW.statut,
                'execution_time', TIMESTAMPDIFF(SECOND, NEW.date_creation, NEW.date_execution),
                'result', NEW.resultat_execution
            )
        );
    END IF;
END //
DELIMITER ;

-- 4. Trigger pour enregistrer les nouvelles alertes
DELIMITER //
CREATE TRIGGER IF NOT EXISTS after_alerte_insert
AFTER INSERT ON alertes
FOR EACH ROW
BEGIN
    -- Enregistrer un log pour chaque nouvelle alerte
    INSERT INTO logs_activite (
        type_action, 
        appareil_id, 
        identifiant_appareil, 
        message, 
        details
    )
    VALUES (
        CASE 
            WHEN NEW.type_alerte = 'offline' THEN 'erreur'
            WHEN NEW.type_alerte = 'error' THEN 'erreur'
            WHEN NEW.type_alerte = 'performance' THEN 'maintenance'
            WHEN NEW.type_alerte = 'security' THEN 'maintenance'
            ELSE 'maintenance'
        END,
        NEW.appareil_id,
        NEW.identifiant_appareil,
        CONCAT('Alerte ', 
               CASE NEW.niveau_severite
                   WHEN 'info' THEN 'information'
                   WHEN 'warning' THEN 'avertissement'
                   WHEN 'error' THEN 'erreur'
                   WHEN 'critical' THEN 'critique'
               END,
               ': ', NEW.message),
        JSON_OBJECT(
            'alert_id', NEW.id,
            'alert_type', NEW.type_alerte,
            'severity', NEW.niveau_severite,
            'details', NEW.details
        )
    );
END //
DELIMITER ;

-- 5. Trigger pour enregistrer les nouvelles tentatives de connexion
DELIMITER //
CREATE TRIGGER IF NOT EXISTS after_tentative_insert
AFTER INSERT ON tentatives_connexion
FOR EACH ROW
BEGIN
    -- Enregistrer un log pour chaque tentative de connexion échouée
    IF NEW.statut = 'failure' THEN
        INSERT INTO logs_activite (
            type_action, 
            identifiant_appareil, 
            message, 
            details,
            adresse_ip
        )
        VALUES (
            'erreur',
            NEW.identifiant_appareil,
            'Tentative de connexion échouée',
            NEW.details,
            NEW.adresse_ip
        );
    END IF;
END //
DELIMITER ;

-- 6. Trigger pour mettre à jour les statistiques de réseau
DELIMITER //
CREATE TRIGGER IF NOT EXISTS after_appareil_network_update
AFTER UPDATE ON appareils
FOR EACH ROW
BEGIN
    -- Si l'identifiant de réseau a changé
    IF NEW.identifiant_reseau != OLD.identifiant_reseau OR 
       (NEW.identifiant_reseau IS NOT NULL AND OLD.identifiant_reseau IS NULL) OR
       (NEW.identifiant_reseau IS NULL AND OLD.identifiant_reseau IS NOT NULL) THEN
        
        -- Mettre à jour le nombre d'appareils pour l'ancien réseau
        IF OLD.identifiant_reseau IS NOT NULL THEN
            UPDATE reseaux
            SET nombre_appareils = (
                SELECT COUNT(*) 
                FROM appareils 
                WHERE identifiant_reseau = OLD.identifiant_reseau
            ),
            derniere_mise_a_jour = NOW()
            WHERE identifiant = OLD.identifiant_reseau;
        END IF;
        
        -- Mettre à jour le nombre d'appareils pour le nouveau réseau
        IF NEW.identifiant_reseau IS NOT NULL THEN
            UPDATE reseaux
            SET nombre_appareils = (
                SELECT COUNT(*) 
                FROM appareils 
                WHERE identifiant_reseau = NEW.identifiant_reseau
            ),
            derniere_mise_a_jour = NOW()
            WHERE identifiant = NEW.identifiant_reseau;
        END IF;
    END IF;
END //
DELIMITER ;

-- 7. Trigger pour enregistrer les nouvelles sessions de diffusion
DELIMITER //
CREATE TRIGGER IF NOT EXISTS after_session_insert
AFTER INSERT ON sessions_diffusion
FOR EACH ROW
BEGIN
    -- Enregistrer un log pour chaque nouvelle session de diffusion
    INSERT INTO logs_activite (
        type_action, 
        appareil_id, 
        identifiant_appareil, 
        presentation_id,
        message, 
        details
    )
    SELECT 
        'diffusion',
        NEW.appareil_id,
        NEW.identifiant_appareil,
        NEW.presentation_id,
        CONCAT('Nouvelle session de diffusion: ', p.nom),
        JSON_OBJECT(
            'session_id', NEW.id,
            'presentation_id', NEW.presentation_id,
            'presentation_name', p.nom,
            'start_time', NEW.date_debut
        )
    FROM presentations p
    WHERE p.id = NEW.presentation_id;
END //
DELIMITER ;

-- 8. Trigger pour enregistrer la fin des sessions de diffusion
DELIMITER //
CREATE TRIGGER IF NOT EXISTS after_session_update
AFTER UPDATE ON sessions_diffusion
FOR EACH ROW
BEGIN
    -- Si la session est terminée
    IF NEW.date_fin IS NOT NULL AND OLD.date_fin IS NULL THEN
        INSERT INTO logs_activite (
            type_action, 
            appareil_id, 
            identifiant_appareil, 
            presentation_id,
            message, 
            details
        )
        SELECT 
            'diffusion',
            NEW.appareil_id,
            NEW.identifiant_appareil,
            NEW.presentation_id,
            CONCAT('Fin de session de diffusion: ', p.nom),
            JSON_OBJECT(
                'session_id', NEW.id,
                'presentation_id', NEW.presentation_id,
                'presentation_name', p.nom,
                'start_time', NEW.date_debut,
                'end_time', NEW.date_fin,
                'duration_seconds', NEW.duree_totale_secondes,
                'slides_viewed', NEW.slides_vues,
                'loops_completed', NEW.boucles_completees,
                'status', NEW.statut_final
            )
        FROM presentations p
        WHERE p.id = NEW.presentation_id;
    END IF;
END //
DELIMITER ;

-- Afficher confirmation
SELECT 'Triggers créés avec succès pour le monitoring des appareils Fire TV' as message;