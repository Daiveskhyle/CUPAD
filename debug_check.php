<?php
require_once __DIR__ . '/includes/config.php';
$pdo = getDbConnection();

echo "=== Orphaned clients (missing or invalid branch_id) ===\n";
$rows = $pdo->query("
    SELECT c.id, c.name, c.officer_username, c.branch_id, c.status,
           u.branch_id as officer_branch_id,
           b.name as officer_branch_name
    FROM clients c
    LEFT JOIN users u ON u.username = c.officer_username
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE c.branch_id IS NULL OR c.branch_id NOT IN (SELECT id FROM branches)
    LIMIT 50
")->fetchAll();

foreach ($rows as $r) {
    echo "Client: {$r['name']} | client_branch_id: {$r['branch_id']} | officer: {$r['officer_username']} | officer_branch_id: {$r['officer_branch_id']} | officer_branch: {$r['officer_branch_name']}\n";
}

echo "\n=== Summary ===\n";
echo "Total orphaned: " . count($rows) . "\n";

$fixable = array_filter($rows, fn($r) => !empty($r['officer_branch_id']));
$unfixable = array_filter($rows, fn($r) => empty($r['officer_branch_id']));
echo "Auto-fixable (officer has branch): " . count($fixable) . "\n";
echo "Needs manual fix (officer has no branch): " . count($unfixable) . "\n";

if (!empty($unfixable)) {
    echo "\n=== Unfixable clients (officer has no branch) ===\n";
    foreach ($unfixable as $r) {
        echo "Client: {$r['name']} | officer: {$r['officer_username']}\n";
    }
}
