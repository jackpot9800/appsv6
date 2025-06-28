<?php
// fix-logs-table.php - Script pour réparer la table logs_activite
header('Content-Type: application/json');

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Fonction pour générer une réponse JSON
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Connexion à la base de données
try {
    require_once('dbpdointranet.php');
    $dbpdointranet->exec("USE affichageDynamique");
} catch (Exception $e) {
    jsonResponse(['error' => 'Erreur de connexion à la base de données: ' . $e->getMessage()], 500);
}

// Vérifier si la table existe
try {
    $stmt = $dbpdointranet->query("SHOW TABLES LIKE 'logs_activite'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        // Créer la table
        $dbpdointranet->exec("
            CREATE TABLE logs_activite (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type_action ENUM('connexion', 'diffusion', 'erreur', 'maintenance', 'commande_distante') NOT NULL,
                appareil_id INT,
                identifiant_appareil VARCHAR(255),
                presentation_id INT,
                message TEXT,
                details JSON,
                adresse_ip VARCHAR(45),
                adresse_ip_externe VARCHAR(45),
                user_agent TEXT,
                date_action TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_logs_type_action (type_action),
                INDEX idx_logs_appareil_id (appareil_id),
                INDEX idx_logs_identifiant_appareil (identifiant_appareil),
                INDEX idx_logs_date_action (date_action),
                INDEX idx_logs_presentation_id (presentation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        jsonResponse([
            'success' => true,
            'message' => 'Table logs_activite créée avec succès',
            'action' => 'create_table'
        ]);
    }
    
    // Vérifier la structure de la table
    $stmt = $dbpdointranet->query("DESCRIBE logs_activite");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $columnNames = array_column($columns, 'Field');
    $actions = [];
    
    // Vérifier si la colonne adresse_ip_externe existe
    if (!in_array('adresse_ip_externe', $columnNames)) {
        $dbpdointranet->exec("
            ALTER TABLE logs_activite
            ADD COLUMN adresse_ip_externe VARCHAR(45) DEFAULT NULL AFTER adresse_ip
        ");
        
        $actions[] = 'add_column_adresse_ip_externe';
    }
    
    // Vérifier si la clé primaire est auto_increment
    $primaryKeyColumn = null;
    foreach ($columns as $column) {
        if ($column['Key'] === 'PRI') {
            $primaryKeyColumn = $column;
            break;
        }
    }
    
    if ($primaryKeyColumn) {
        if (strpos($primaryKeyColumn['Extra'], 'auto_increment') === false) {
            // Modifier la clé primaire pour ajouter auto_increment
            $dbpdointranet->exec("
                ALTER TABLE logs_activite
                MODIFY COLUMN {$primaryKeyColumn['Field']} INT AUTO_INCREMENT
            ");
            
            $actions[] = 'add_auto_increment';
        }
    } else {
        // Ajouter une clé primaire
        if (in_array('id', $columnNames)) {
            $dbpdointranet->exec("
                ALTER TABLE logs_activite
                MODIFY COLUMN id INT AUTO_INCREMENT,
                ADD PRIMARY KEY (id)
            ");
        } else {
            $dbpdointranet->exec("
                ALTER TABLE logs_activite
                ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST
            ");
        }
        
        $actions[] = 'add_primary_key';
    }
    
    // Vérifier s'il y a des entrées avec ID = 0
    $stmt = $dbpdointranet->query("SELECT COUNT(*) FROM logs_activite WHERE id = 0");
    $zeroIdCount = $stmt->fetchColumn();
    
    if ($zeroIdCount > 0) {
        // Supprimer les entrées avec ID = 0
        $dbpdointranet->exec("DELETE FROM logs_activite WHERE id = 0");
        $actions[] = 'delete_zero_id_entries';
    }
    
    // Vérifier s'il y a des entrées dupliquées
    $stmt = $dbpdointranet->query("
        SELECT id, COUNT(*) as count
        FROM logs_activite
        GROUP BY id
        HAVING count > 1
    ");
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($duplicates) > 0) {
        // Supprimer les entrées dupliquées
        foreach ($duplicates as $duplicate) {
            $dbpdointranet->exec("
                DELETE FROM logs_activite 
                WHERE id = {$duplicate['id']} 
                LIMIT " . ($duplicate['count'] - 1)
            );
        }
        
        $actions[] = 'delete_duplicate_entries';
    }
    
    // Tester l'insertion d'un log
    $stmt = $dbpdointranet->prepare("
        INSERT INTO logs_activite 
        (type_action, message, details, adresse_ip, date_action)
        VALUES ('maintenance', 'Test de réparation', ?, ?, NOW())
    ");
    
    $stmt->execute([
        json_encode(['test' => true, 'timestamp' => time()]),
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ]);
    
    $insertId = $dbpdointranet->lastInsertId();
    
    jsonResponse([
        'success' => true,
        'message' => 'Table logs_activite vérifiée et réparée avec succès',
        'actions' => $actions,
        'test_insert_id' => $insertId
    ]);
    
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'error' => 'Erreur lors de la réparation de la table: ' . $e->getMessage()
    ], 500);
}
?>