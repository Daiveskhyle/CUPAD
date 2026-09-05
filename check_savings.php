<?php
$host = 'localhost';
$dbname = 'cupadnam_db';
$username = 'root';
$password = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $cnt = $pdo->query('SELECT COUNT(*) FROM saving_balances')->fetchColumn();
    echo "saving_balances count: $cnt\n";
        $stmt = $pdo->query('SELECT client_id, balance FROM saving_balances LIMIT 10');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['client_id'] . ' => ' . $row['balance'] . "\n";
    }
        $cnt2 = $pdo->query('SELECT COUNT(*) FROM saving_collections')->fetchColumn();
        echo "saving_collections count: $cnt2\n";
        $stmt2 = $pdo->query('SELECT transaction_id, client_id, amount FROM saving_collections LIMIT 10');
        while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            echo ($r['transaction_id'] ?? '') . ' | ' . ($r['client_id'] ?? '') . ' | ' . ($r['amount'] ?? '') . "\n";
        }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
