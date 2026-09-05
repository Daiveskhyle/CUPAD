<?php
/**
 * Cleanup Inactive Users
 * Mark users as offline if no activity for 5 minutes
 * Run this via cron job every 5 minutes OR include in heartbeat
 */
require_once __DIR__ . '/includes/config.php';

try {
    $pdo = getDbConnection();
    
    // Mark users offline if no activity for 5 minutes
    $stmt = $pdo->prepare("
        UPDATE users 
        SET is_online = 0 
        WHERE is_online = 1 
        AND last_activity < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute();
    
    $affected = $stmt->rowCount();
    error_log("Cleanup: Marked $affected users as offline");
    
} catch (Exception $e) {
    error_log("Cleanup error: " . $e->getMessage());
}
