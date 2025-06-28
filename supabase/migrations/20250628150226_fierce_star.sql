-- Script SQL pour créer des vues pour le monitoring des appareils Fire TV
-- Ces vues facilitent l'analyse et le reporting des appareils connectés

-- 1. Vue des appareils avec leur statut actuel
CREATE OR REPLACE VIEW vue_appareils_statut AS
SELECT 
    a.id,
    a.nom,
    a.identifiant_unique,
    a.type_appareil,
    a.adresse_ip,
    a.adresse_ip_externe,
    a.derniere_connexion,
    a.statut_temps_reel,
    a.presentation_courante_id,
    a.presentation_courante_nom,
    a.slide_courant_index,
    a.total_slides,
    a.mode_boucle,
    a.lecture_automatique,
    a.utilisation_memoire,
    a.force_wifi,
    a.version_app,
    a.message_erreur,
    CASE 
        WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'online'
        WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'idle'
        ELSE 'offline'
    END as statut_connexion,
    TIMESTAMPDIFF(SECOND, a.derniere_connexion, NOW()) as secondes_depuis_derniere_connexion,
    TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()) as minutes_depuis_derniere_connexion,
    p.nom as nom_presentation_defaut,
    (SELECT COUNT(*) FROM commandes_distantes WHERE identifiant_appareil = a.identifiant_unique AND statut = 'en_attente') as commandes_en_attente,
    (SELECT COUNT(*) FROM logs_activite WHERE identifiant_appareil = a.identifiant_unique AND type_action = 'erreur') as nombre_erreurs,
    (SELECT COUNT(*) FROM logs_activite WHERE identifiant_appareil = a.identifiant_unique) as nombre_logs_total
FROM 
    appareils a
LEFT JOIN 
    presentations p ON a.presentation_defaut_id = p.id;

-- 2. Vue des appareils hors ligne
CREATE OR REPLACE VIEW vue_appareils_hors_ligne AS
SELECT 
    a.*,
    TIMESTAMPDIFF(MINUTE, a.derniere_connexion, NOW()) as minutes_hors_ligne
FROM 
    appareils a
WHERE 
    a.statut = 'actif'
    AND a.derniere_connexion < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
ORDER BY 
    a.derniere_connexion ASC;

-- 3. Vue des présentations en cours de diffusion
CREATE OR REPLACE VIEW vue_presentations_en_diffusion AS
SELECT 
    p.id,
    p.nom,
    p.description,
    COUNT(DISTINCT a.id) as nombre_appareils,
    GROUP_CONCAT(DISTINCT a.nom SEPARATOR ', ') as liste_appareils
FROM 
    presentations p
JOIN 
    appareils a ON a.presentation_courante_id = p.id
WHERE 
    a.statut_temps_reel = 'playing'
GROUP BY 
    p.id, p.nom, p.description;

-- 4. Vue des statistiques de diffusion par présentation
CREATE OR REPLACE VIEW vue_statistiques_diffusion AS
SELECT 
    p.id,
    p.nom,
    COUNT(DISTINCT d.appareil_id) as nombre_appareils_total,
    COUNT(DISTINCT CASE WHEN d.statut = 'active' THEN d.appareil_id END) as nombre_diffusions_actives,
    SUM(d.nombre_lectures) as nombre_lectures_total,
    MAX(d.date_creation) as derniere_diffusion
FROM 
    presentations p
LEFT JOIN 
    diffusions d ON p.id = d.presentation_id
GROUP BY 
    p.id, p.nom;

-- 5. Vue des statistiques d'utilisation par appareil
CREATE OR REPLACE VIEW vue_statistiques_appareils AS
SELECT 
    a.id,
    a.nom,
    a.identifiant_unique,
    a.type_appareil,
    COUNT(DISTINCT d.presentation_id) as nombre_presentations_diffusees,
    SUM(d.nombre_lectures) as nombre_lectures_total,
    MAX(d.date_creation) as derniere_diffusion,
    COUNT(DISTINCT CASE WHEN l.type_action = 'erreur' THEN l.id END) as nombre_erreurs,
    COUNT(DISTINCT CASE WHEN l.type_action = 'connexion' THEN l.id END) as nombre_connexions,
    COUNT(DISTINCT CASE WHEN l.type_action = 'commande_distante' THEN l.id END) as nombre_commandes,
    MAX(l.date_action) as derniere_activite
FROM 
    appareils a
LEFT JOIN 
    diffusions d ON a.id = d.appareil_id
LEFT JOIN 
    logs_activite l ON a.identifiant_unique = l.identifiant_appareil
GROUP BY 
    a.id, a.nom, a.identifiant_unique, a.type_appareil;

-- 6. Vue des commandes récentes
CREATE OR REPLACE VIEW vue_commandes_recentes AS
SELECT 
    c.*,
    a.nom as nom_appareil,
    TIMESTAMPDIFF(SECOND, c.date_creation, COALESCE(c.date_execution, NOW())) as temps_execution_secondes
FROM 
    commandes_distantes c
JOIN 
    appareils a ON c.identifiant_appareil = a.identifiant_unique
ORDER BY 
    c.date_creation DESC;

-- 7. Vue des alertes actives
CREATE OR REPLACE VIEW vue_alertes_actives AS
SELECT 
    al.*,
    a.nom as nom_appareil
FROM 
    alertes al
LEFT JOIN 
    appareils a ON al.appareil_id = a.id
WHERE 
    al.statut = 'active'
ORDER BY 
    al.niveau_severite DESC, al.date_creation DESC;

-- 8. Vue des métriques de performance moyennes par appareil
CREATE OR REPLACE VIEW vue_metriques_moyennes AS
SELECT 
    appareil_id,
    identifiant_appareil,
    AVG(utilisation_cpu) as cpu_moyen,
    AVG(utilisation_memoire) as memoire_moyenne,
    AVG(force_wifi) as wifi_moyen,
    AVG(temperature_cpu) as temperature_moyenne,
    AVG(fps_moyen) as fps_moyen,
    AVG(latence_reseau) as latence_moyenne,
    COUNT(*) as nombre_mesures,
    MAX(date_mesure) as derniere_mesure
FROM 
    metriques_performance
WHERE 
    date_mesure > DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY 
    appareil_id, identifiant_appareil;

-- 9. Vue des appareils par réseau
CREATE OR REPLACE VIEW vue_appareils_par_reseau AS
SELECT 
    r.id as reseau_id,
    r.nom as nom_reseau,
    r.adresse_ip_externe,
    COUNT(a.id) as nombre_appareils,
    COUNT(CASE WHEN a.derniere_connexion > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 END) as appareils_en_ligne,
    GROUP_CONCAT(DISTINCT a.nom SEPARATOR ', ') as liste_appareils
FROM 
    reseaux r
LEFT JOIN 
    appareils a ON a.identifiant_reseau = r.identifiant
GROUP BY 
    r.id, r.nom, r.adresse_ip_externe;

-- 10. Vue des sessions de diffusion récentes
CREATE OR REPLACE VIEW vue_sessions_recentes AS
SELECT 
    s.*,
    a.nom as nom_appareil,
    p.nom as nom_presentation,
    TIMESTAMPDIFF(SECOND, s.date_debut, COALESCE(s.date_fin, NOW())) as duree_secondes
FROM 
    sessions_diffusion s
JOIN 
    appareils a ON s.appareil_id = a.id
JOIN 
    presentations p ON s.presentation_id = p.id
WHERE 
    s.date_debut > DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY 
    s.date_debut DESC;

-- Afficher confirmation
SELECT 'Vues créées avec succès pour le monitoring des appareils Fire TV' as message;