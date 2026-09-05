<?php
session_start();
// Ensure only authorized users can access this
if (!isset($_SESSION['user_role']) || ($_SESSION['user_role'] !== 'co' && $_SESSION['user_role'] !== 'admin')) {
    header('Location: ../index.php');
    exit();
}

$base_path = '../';

// --- CONFIGURATION ---
$savings_file = $base_path . 'data/savings.json';
$clients_file = $base_path . 'data/clients.json';
$balance_file = $base_path . 'data/Saving_Balance.json';
// If you have a separate withdrawals file, uncomment below:
// $withdrawals_file = $base_path . 'data/withdrawals.json'; 

$message = "";
$updated_count = 0;

// --- SYNC LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync'])) {
    
    // 1. Load Data
    $savings = file_exists($savings_file) ? json_decode(file_get_contents($savings_file), true) ?: [] : [];
    $clients = file_exists($clients_file) ? json_decode(file_get_contents($clients_file), true) ?: [] : [];
    
    // Map existing balances if you want to preserve 'last_updated' dates, otherwise we rebuild fresh
    $current_balances = file_exists($balance_file) ? json_decode(file_get_contents($balance_file), true) ?: [] : [];
    
    $new_balances = [];

    // 2. Initialize all clients with 0
    foreach ($clients as $client) {
        $id = $client['id'];
        $new_balances[$id] = [
            'balance' => 0,
            'last_updated' => date('Y-m-d H:i:s')
        ];
    }

    // 3. Sum up Savings (Deposits)
    foreach ($savings as $entry) {
        if (isset($entry['client_id'], $entry['amount'])) {
            $cid = $entry['client_id'];
            $amt = floatval($entry['amount']);
            
            // Only add if client still exists in system
            if (isset($new_balances[$cid])) {
                $new_balances[$cid]['balance'] += $amt;
            } else {
                // Optional: Handle orphaned records (clients deleted but history remains)
                // $new_balances[$cid] = ['balance' => $amt, 'last_updated' => date('Y-m-d H:i:s')];
            }
        }
    }

    /* 
    // 4. (Optional) Subtract Withdrawals 
    // Uncomment this block if you have a separate withdrawals file
    if (isset($withdrawals_file) && file_exists($withdrawals_file)) {
        $withdrawals = json_decode(file_get_contents($withdrawals_file), true) ?: [];
        foreach ($withdrawals as $entry) {
            if (isset($entry['client_id'], $entry['amount'])) {
                $cid = $entry['client_id'];
                $amt = floatval($entry['amount']);
                if (isset($new_balances[$cid])) {
                    $new_balances[$cid]['balance'] -= $amt;
                }
            }
        }
    }
    */

    // 5. Save to File
    if (file_put_contents($balance_file, json_encode($new_balances, JSON_PRETTY_PRINT))) {
        $updated_count = count($new_balances);
        $message = "Successfully synchronized balances for $updated_count clients.";
    } else {
        $message = "Error: Could not write to Saving_Balance.json. Check permissions.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Balances | CUPAD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 text-gray-800">

    <div class="max-w-3xl mx-auto px-4 py-12">
        
        <div class="mb-8 text-center">
            <h1 class="text-3xl font-bold text-gray-900">Balance Synchronization</h1>
            <p class="text-gray-500 mt-2">Calculates totals from history and updates <code class="bg-gray-100 px-2 py-1 rounded text-sm text-pink-600">Saving_Balance.json</code></p>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl <?php echo strpos($message, 'Error') !== false ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'; ?> flex items-center gap-3">
                <i class="fas <?php echo strpos($message, 'Error') !== false ? 'fa-exclamation-circle' : 'fa-check-circle'; ?> text-xl"></i>
                <span class="font-medium"><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 text-center">
            <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-sync-alt text-2xl"></i>
            </div>
            
            <h2 class="text-xl font-semibold mb-2">Run Synchronization</h2>
            <p class="text-gray-500 mb-6 text-sm">
                This will read all transaction history from <b>savings.json</b> and overwrite the current balances. 
                <br>Use this if your balances seem incorrect or for older clients.
            </p>

            <form method="POST">
                <button type="submit" name="sync" class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-xl text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 shadow-lg shadow-blue-500/30 transition-all active:scale-95">
                    <i class="fas fa-play mr-2"></i> Synchronize All Balances
                </button>
            </form>
        </div>

        <div class="mt-8 text-center">
            <a href="savings_collection.php" class="text-gray-500 hover:text-gray-900 text-sm font-medium">
                <i class="fas fa-arrow-left mr-1"></i> Back to Collection
            </a>
        </div>

    </div>

</body>
</html>