<?php
require_once 'includes/config.php';
$pdo = getDbConnection();

try {
    $pdo->beginTransaction();
    
    // Update clients.branch_id from their officer's branch_id
    $stmt = $pdo->exec("
        UPDATE clients c
        INNER JOIN users u ON c.officer_username = u.username
        SET c.branch_id = u.branch_id
        WHERE c.officer_username IS NOT NULL
    ");
    echo "Updated $stmt clients with officer's branch_id<br>";
    
    // Update disbursements.branch_id from client's branch_id
    $stmt = $pdo->exec("
        UPDATE disbursements d
        INNER JOIN clients c ON d.client_id = c.id
        SET d.branch_id = c.branch_id
        WHERE c.branch_id IS NOT NULL
    ");
    echo "Updated $stmt disbursements with client's branch_id<br>";
    
    $pdo->commit();
    echo "<br><strong>Success! All branch IDs fixed.</strong>";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage();
}
?>
