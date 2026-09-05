<?php
// Load disbursements data
$disbursements_file = 'data/disbursements.json';
$disbursements = json_decode(file_get_contents($disbursements_file), true) ?: [];

// Get first 6 and last 6 transaction IDs
$total_count = count($disbursements);
$first_6 = array_slice($disbursements, 0, 6);
$last_6 = array_slice($disbursements, -6);

echo "=== FIRST 6 TRANSACTION IDs ===\n";
foreach ($first_6 as $index => $transaction) {
    echo ($index + 1) . ". " . $transaction['transaction_id'] . "\n";
}

echo "\n=== LAST 6 TRANSACTION IDs ===\n";
$start_index = max(0, $total_count - 6);
foreach ($last_6 as $index => $transaction) {
    echo ($start_index + $index + 1) . ". " . $transaction['transaction_id'] . "\n";
}

echo "\nTotal transactions: " . $total_count . "\n";
?>