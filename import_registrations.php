<?php
require_once 'includes/config.php';

$pdo = getDbConnection();

// Create registrations table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS `registrations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` VARCHAR(50) NOT NULL,
  `client_name` VARCHAR(255) NOT NULL,
  `union` VARCHAR(100) DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `date` DATETIME NOT NULL,
  `officer` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_client` (`client_id`),
  KEY `idx_officer` (`officer`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Read JSON file
$json = file_get_contents('data/registrations.json');
$registrations = json_decode($json, true);

$stmt = $pdo->prepare("INSERT INTO registrations (client_id, client_name, `union`, amount, date, officer) 
                       VALUES (?, ?, ?, ?, ?, ?)");

$inserted = 0;
foreach ($registrations as $reg) {
    try {
        $stmt->execute([
            $reg['client_id'],
            $reg['client_name'],
            $reg['union'],
            $reg['amount'],
            $reg['date'],
            $reg['officer']
        ]);
        $inserted++;
    } catch (PDOException $e) {
        echo "Error inserting {$reg['client_name']}: " . $e->getMessage() . "\n";
    }
}

echo "Successfully imported $inserted registrations to database.\n";
