<?php
require_once __DIR__ . '/includes/config.php';

try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT id, username, role, password FROM users LIMIT 10");
    $users = $stmt->fetchAll();
    
    echo "<h2>Users in Database:</h2>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>Password Hash</th></tr>";
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($user['id']) . "</td>";
        echo "<td>" . htmlspecialchars($user['username']) . "</td>";
        echo "<td>" . htmlspecialchars($user['role']) . "</td>";
        echo "<td>" . htmlspecialchars(substr($user['password'], 0, 30)) . "...</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
