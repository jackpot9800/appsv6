<?php
// check-logs-table.php - Script pour vérifier et corriger la structure de la table logs_activite
header('Content-Type: text/html; charset=utf-8');

// Inclure la configuration du fuseau horaire
require_once('timezone-config.php');

// Connexion à la base de données
try {
    require_once('dbpdointranet.php');
    $dbpdointranet->exec("USE affichageDynamique");
    
    echo "<h1>Vérification de la table logs_activite</h1>";
    
    // Vérifier si la table existe
    $stmt = $dbpdointranet->query("SHOW TABLES LIKE 'logs_activite'");
    if ($stmt->rowCount() === 0) {
        echo "<p style='color: red;'>La table logs_activite n'existe pas !</p>";
        
        // Créer la table
        echo "<p>Création de la table logs_activite...</p>";
        
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
        
        echo "<p style='color: green;'>Table logs_activite créée avec succès !</p>";
    } else {
        echo "<p style='color: green;'>La table logs_activite existe.</p>";
        
        // Vérifier la structure de la table
        $stmt = $dbpdointranet->query("DESCRIBE logs_activite");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $columnNames = array_column($columns, 'Field');
        
        echo "<h2>Structure actuelle de la table</h2>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Colonne</th><th>Type</th><th>Null</th><th>Clé</th><th>Défaut</th><th>Extra</th></tr>";
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>{$column['Field']}</td>";
            echo "<td>{$column['Type']}</td>";
            echo "<td>{$column['Null']}</td>";
            echo "<td>{$column['Key']}</td>";
            echo "<td>{$column['Default']}</td>";
            echo "<td>{$column['Extra']}</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        // Vérifier si la colonne adresse_ip_externe existe
        if (!in_array('adresse_ip_externe', $columnNames)) {
            echo "<p>Ajout de la colonne adresse_ip_externe...</p>";
            
            $dbpdointranet->exec("
                ALTER TABLE logs_activite
                ADD COLUMN adresse_ip_externe VARCHAR(45) DEFAULT NULL AFTER adresse_ip
            ");
            
            echo "<p style='color: green;'>Colonne adresse_ip_externe ajoutée avec succès !</p>";
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
            echo "<p>Clé primaire : {$primaryKeyColumn['Field']} ({$primaryKeyColumn['Extra']})</p>";
            
            if (strpos($primaryKeyColumn['Extra'], 'auto_increment') === false) {
                echo "<p style='color: orange;'>La clé primaire n'est pas auto_increment !</p>";
                
                // Modifier la clé primaire pour ajouter auto_increment
                echo "<p>Modification de la clé primaire pour ajouter auto_increment...</p>";
                
                $dbpdointranet->exec("
                    ALTER TABLE logs_activite
                    MODIFY COLUMN {$primaryKeyColumn['Field']} INT AUTO_INCREMENT
                ");
                
                echo "<p style='color: green;'>Clé primaire modifiée avec succès !</p>";
            }
        } else {
            echo "<p style='color: red;'>Aucune clé primaire trouvée !</p>";
            
            // Ajouter une clé primaire
            echo "<p>Ajout d'une clé primaire...</p>";
            
            // Vérifier si la colonne id existe
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
            
            echo "<p style='color: green;'>Clé primaire ajoutée avec succès !</p>";
        }
    }
    
    // Vérifier les dernières entrées
    $stmt = $dbpdointranet->query("
        SELECT * FROM logs_activite 
        ORDER BY date_action DESC 
        LIMIT 10
    ");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Dernières entrées dans la table</h2>";
    
    if (count($logs) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr>";
        foreach (array_keys($logs[0]) as $key) {
            echo "<th>{$key}</th>";
        }
        echo "</tr>";
        
        foreach ($logs as $log) {
            echo "<tr>";
            foreach ($log as $value) {
                echo "<td>" . (is_null($value) ? 'NULL' : htmlspecialchars($value)) . "</td>";
            }
            echo "</tr>";
        }
        
        echo "</table>";
    } else {
        echo "<p>Aucune entrée trouvée dans la table.</p>";
    }
    
    echo "<h2>Test d'insertion</h2>";
    
    // Tester l'insertion d'un log
    try {
        $stmt = $dbpdointranet->prepare("
            INSERT INTO logs_activite 
            (type_action, message, details, adresse_ip, date_action)
            VALUES ('maintenance', 'Test d\'insertion', ?, ?, NOW())
        ");
        
        $stmt->execute([
            json_encode(['test' => true, 'timestamp' => time()]),
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        $insertId = $dbpdointranet->lastInsertId();
        
        echo "<p style='color: green;'>Insertion réussie ! ID : {$insertId}</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>Erreur lors de l'insertion : " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<h1 style='color: red;'>Erreur</h1>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>

<p><a href="index.php">Retour à l'accueil</a></p>