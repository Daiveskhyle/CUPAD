<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);
ini_set('memory_limit', '512M');

$host = 'localhost';
$dbname = 'cupadnam_db';
$username = 'root';
$password = '';
$dataDir = __DIR__ . '/data/';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $results = [];
    $logErrorFile = __DIR__ . '/sql/migration_errors.log';
    $logSkipFile = __DIR__ . '/sql/migration_skipped.log';

    function log_migration_error($file, $table, $message, $context = null) {
        $entry = date('c') . " - $table - $message" . (is_null($context) ? "" : " - " . json_encode($context)) . PHP_EOL;
        file_put_contents($file, $entry, FILE_APPEND);
    }

    function safe_execute(PDOStatement $stmt, array $params, $table, $logFile) {
        try {
            $stmt->execute($params);
        } catch (Exception $e) {
            log_migration_error($logFile, $table, $e->getMessage(), $params);
        }
    }

    // Ensure clients table has `plan_id` column (nullable)
    try {
        $colCheck = $pdo->prepare("SHOW COLUMNS FROM `clients` LIKE 'plan_id'");
        $colCheck->execute();
        if ($colCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `clients` ADD COLUMN `plan_id` varchar(50) DEFAULT NULL AFTER `client_type`");
        }
    } catch (Exception $e) {
        // Log but continue
        file_put_contents(__DIR__ . '/sql/migration_schema.log', date('c') . " - plan_id check error: " . $e->getMessage() . "\n", FILE_APPEND);
    }

    // Zones
    if (file_exists($dataDir . 'zones.json')) {
        $data = json_decode(file_get_contents($dataDir . 'zones.json'), true) ?: [];
        $stmt = $pdo->prepare("INSERT INTO zones (id, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)");
        $inserted = 0; $skipped = 0;
        foreach ($data as $item) {
            $pk = $item['id'] ?? null;
            if (empty($pk)) {
                log_migration_error($logSkipFile, 'zones', 'missing id', $item);
                $skipped++;
                continue;
            }
            safe_execute($stmt, [$pk, $item['name'] ?? null], 'zones', $logErrorFile);
            $inserted++;
        }
        $results['zones'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Areas
    if (file_exists($dataDir . 'areas.json')) {
        $data = json_decode(file_get_contents($dataDir . 'areas.json'), true) ?: [];
        $stmt = $pdo->prepare("INSERT INTO areas (id, name, zone_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), zone_id = VALUES(zone_id)");
        $inserted = 0; $skipped = 0;
        foreach ($data as $item) {
            $pk = $item['id'] ?? null;
            if (empty($pk)) {
                log_migration_error($logSkipFile, 'areas', 'missing id', $item);
                $skipped++;
                continue;
            }
            safe_execute($stmt, [$pk, $item['name'] ?? null, $item['zone_id'] ?? null], 'areas', $logErrorFile);
            $inserted++;
        }
        $results['areas'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Branches
    if (file_exists($dataDir . 'branches.json')) {
        $data = json_decode(file_get_contents($dataDir . 'branches.json'), true) ?: [];
        $stmt = $pdo->prepare("INSERT INTO branches (id, name, address, zone_id, area_id, status) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), address = VALUES(address), zone_id = VALUES(zone_id), area_id = VALUES(area_id), status = VALUES(status)");
        $inserted = 0; $skipped = 0;
        foreach ($data as $item) {
            $pk = $item['id'] ?? null;
            if (empty($pk)) {
                log_migration_error($logSkipFile, 'branches', 'missing id', $item);
                $skipped++;
                continue;
            }
            safe_execute($stmt, [$pk, $item['name'] ?? null, $item['address'] ?? null, $item['zone_id'] ?? null, $item['area_id'] ?? null, $item['status'] ?? 'active'], 'branches', $logErrorFile);
            $inserted++;
        }
        $results['branches'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Unions/Groups
    if (file_exists($dataDir . 'groups.json')) {
        $data = json_decode(file_get_contents($dataDir . 'groups.json'), true) ?: [];
        $stmt = $pdo->prepare("INSERT INTO unions (id, name, branch_id, description, status) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), branch_id = VALUES(branch_id), description = VALUES(description), status = VALUES(status)");
        $inserted = 0; $skipped = 0;
        foreach ($data as $item) {
            $pk = $item['id'] ?? null;
            if (empty($pk)) {
                log_migration_error($logSkipFile, 'unions', 'missing id', $item);
                $skipped++;
                continue;
            }
            safe_execute($stmt, [$pk, $item['name'] ?? null, $item['branch_id'] ?? null, $item['description'] ?? null, $item['status'] ?? 'active'], 'unions', $logErrorFile);
            $inserted++;
        }
        $results['unions'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Assignments
    if (file_exists($dataDir . 'assignments.json')) {
        $data = json_decode(file_get_contents($dataDir . 'assignments.json'), true) ?: [];
        $stmt = $pdo->prepare("INSERT INTO assignments (co, `union`, branch, assigned_date) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE assigned_date = VALUES(assigned_date)");
        $inserted = 0; $skipped = 0;
        foreach ($data as $item) {
            // require at least co and union to identify assignment
            if (empty($item['co']) || empty($item['union'])) {
                log_migration_error($logSkipFile, 'assignments', 'missing co or union', $item);
                $skipped++;
                continue;
            }
            safe_execute($stmt, [$item['co'] ?? null, $item['union'] ?? null, $item['branch'] ?? null, $item['assigned_at'] ?? null], 'assignments', $logErrorFile);
            $inserted++;
        }
        $results['assignments'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Clients
    if (file_exists($dataDir . 'clients.json')) {
        $data = json_decode(file_get_contents($dataDir . 'clients.json'), true) ?: [];
        $stmt = $pdo->prepare("INSERT INTO clients (id, name, phone, email, address, `union`, branch_id, officer_username, client_type, plan_id, status, photo, id_type, id_number, guarantor_name, guarantor_phone, guarantor_address, date_registered) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), phone = VALUES(phone), email = VALUES(email), address = VALUES(address), `union` = VALUES(`union`), branch_id = VALUES(branch_id), officer_username = VALUES(officer_username), client_type = VALUES(client_type), plan_id = VALUES(plan_id), status = VALUES(status), photo = VALUES(photo), id_type = VALUES(id_type), id_number = VALUES(id_number), guarantor_name = VALUES(guarantor_name), guarantor_phone = VALUES(guarantor_phone), guarantor_address = VALUES(guarantor_address), date_registered = VALUES(date_registered), updated_at = NOW()");
        $inserted = 0; $skipped = 0;
        foreach ($data as $item) {
            $pk = $item['id'] ?? null;
            if (empty($pk)) {
                log_migration_error($logSkipFile, 'clients', 'missing id', $item);
                $skipped++;
                continue;
            }
            safe_execute($stmt, [$pk, $item['name'] ?? null, $item['phone'] ?? null, $item['email'] ?? null, $item['address'] ?? null, $item['union'] ?? null, $item['branch_id'] ?? null, $item['officer_username'] ?? null, $item['client_type'] ?? null, $item['plan_id'] ?? null, $item['status'] ?? 'active', $item['photo'] ?? null, $item['id_type'] ?? null, $item['id_number'] ?? null, $item['guarantor_name'] ?? null, $item['guarantor_phone'] ?? null, $item['guarantor_address'] ?? null, $item['date_registered'] ?? null], 'clients', $logErrorFile);
            $inserted++;
        }
        $results['clients'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Closed Clients
    if (file_exists($dataDir . 'closed_client.json')) {
        $data = json_decode(file_get_contents($dataDir . 'closed_client.json'), true) ?: [];
            $stmt = $pdo->prepare("INSERT INTO clients (id, name, phone, email, address, `union`, branch_id, officer_username, client_type, plan_id, status, photo, id_type, id_number, guarantor_name, guarantor_phone, guarantor_address, date_registered) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'inactive', ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), phone = VALUES(phone), email = VALUES(email), address = VALUES(address), `union` = VALUES(`union`), branch_id = VALUES(branch_id), officer_username = VALUES(officer_username), client_type = VALUES(client_type), plan_id = VALUES(plan_id), photo = VALUES(photo), id_type = VALUES(id_type), id_number = VALUES(id_number), guarantor_name = VALUES(guarantor_name), guarantor_phone = VALUES(guarantor_phone), guarantor_address = VALUES(guarantor_address), date_registered = VALUES(date_registered), status = 'inactive', updated_at = NOW()");
        $inserted = 0; $skipped = 0;
        foreach ($data as $item) {
            $pk = $item['id'] ?? null;
            if (empty($pk)) {
                log_migration_error($logSkipFile, 'closed_clients', 'missing id', $item);
                $skipped++;
                continue;
            }
                safe_execute($stmt, [$pk, $item['name'] ?? null, $item['phone'] ?? null, $item['email'] ?? null, $item['address'] ?? null, $item['union'] ?? null, $item['branch_id'] ?? null, $item['officer_username'] ?? null, $item['client_type'] ?? null, $item['plan_id'] ?? null, $item['photo'] ?? null, $item['id_type'] ?? null, $item['id_number'] ?? null, $item['guarantor_name'] ?? null, $item['guarantor_phone'] ?? null, $item['guarantor_address'] ?? null, $item['date_registered'] ?? null], 'closed_clients', $logErrorFile);
            $inserted++;
        }
            $results['closed_clients'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Disbursements
    if (file_exists($dataDir . 'disbursements.json')) {
        $data = json_decode(file_get_contents($dataDir . 'disbursements.json'), true) ?: [];
        $stmt = $pdo->prepare("INSERT INTO disbursements (id, client_id, client_name, officer, branch_id, principal, interest_rate, total_payable, remaining_balance, num_installments, loan_term_type, client_type, date, due_date, payoff_date, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE officer = VALUES(officer), principal = VALUES(principal), interest_rate = VALUES(interest_rate), total_payable = VALUES(total_payable), remaining_balance = VALUES(remaining_balance), num_installments = VALUES(num_installments), loan_term_type = VALUES(loan_term_type), client_type = VALUES(client_type), date = VALUES(date), due_date = VALUES(due_date), payoff_date = VALUES(payoff_date), status = VALUES(status), notes = VALUES(notes), updated_at = NOW()");
        $inserted = 0; $skipped = 0;
        foreach ($data as $item) {
            $pk = $item['transaction_id'] ?? $item['id'] ?? null;
            if (empty($pk)) {
                log_migration_error($logSkipFile, 'disbursements', 'missing id/transaction_id', $item);
                $skipped++;
                continue;
            }
            safe_execute($stmt, [$pk, $item['client_id'] ?? null, $item['client_name'] ?? null, $item['officer'] ?? null, $item['branch_id'] ?? null, $item['principal_amount'] ?? $item['principal'] ?? 0, $item['interest_rate'] ?? 10, $item['total_payable'] ?? 0, $item['remaining_balance'] ?? 0, $item['num_installments'] ?? 23, $item['loan_term_type'] ?? 'Standard', $item['client_type'] ?? null, $item['date'] ?? null, $item['due_date'] ?? null, $item['payoff_date'] ?? null, $item['status'] ?? 'active', $item['notes'] ?? null], 'disbursements', $logErrorFile);
            $inserted++;
        }
        $results['disbursements'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Loan Collections
    if (file_exists($dataDir . 'collections.json')) {
        $data = json_decode(file_get_contents($dataDir . 'collections.json'), true) ?: [];
        $stmt = $pdo->prepare("INSERT INTO loan_collections (transaction_id, client_id, disbursement_id, amount_collected, date, officer, type, disbursement_date, remaining_balance, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), disbursement_id = VALUES(disbursement_id), amount_collected = VALUES(amount_collected), date = VALUES(date), officer = VALUES(officer), type = VALUES(type), disbursement_date = VALUES(disbursement_date), remaining_balance = VALUES(remaining_balance), notes = VALUES(notes)");
        $inserted = 0; $skipped = 0;
        foreach ($data as $item) {
            $pk = $item['transaction_id'] ?? null;
            if (empty($pk)) {
                log_migration_error($logSkipFile, 'loan_collections', 'missing transaction_id', $item);
                $skipped++;
                continue;
            }
            safe_execute($stmt, [$pk, $item['client_id'] ?? null, $item['disbursement_id'] ?? null, $item['amount_collected'] ?? 0, $item['date'] ?? null, $item['officer'] ?? null, $item['type'] ?? 'repayment', $item['disbursement_date'] ?? null, $item['remaining_balance'] ?? null, $item['notes'] ?? null], 'loan_collections', $logErrorFile);
            $inserted++;
        }
        $results['loan_collections'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Saving Balances (migrate BEFORE collections to populate balance first)
    if (file_exists($dataDir . 'Saving_Balance.json')) {
        $data = json_decode(file_get_contents($dataDir . 'Saving_Balance.json'), true) ?: [];
        $updateStmt = $pdo->prepare("UPDATE saving_balances SET balance = ?, updated_at = NOW() WHERE client_id = ?");
        $insertStmt = $pdo->prepare("INSERT INTO saving_balances (client_id, balance, last_updated) VALUES (?, ?, NOW())");
        $inserted = 0; $skipped = 0;
        foreach ($data as $clientId => $item) {
            if (empty($clientId)) {
                log_migration_error($logSkipFile, 'saving_balances', 'missing clientId', $clientId);
                $skipped++;
                continue;
            }
            $balance = is_array($item) ? ($item['balance'] ?? 0) : $item;
            try {
                $updateStmt->execute([$balance, $clientId]);
                if ($updateStmt->rowCount() === 0) {
                    $insertStmt->execute([$clientId, $balance]);
                }
            } catch (Exception $e) {
                log_migration_error($logErrorFile, 'saving_balances', $e->getMessage());
            }
            $inserted++;
        }
        $results['saving_balances'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Saving Collections (transaction history)
    if (file_exists($dataDir . 'savings.json')) {
        $data = json_decode(file_get_contents($dataDir . 'savings.json'), true) ?: [];
        $stmt = $pdo->prepare("INSERT INTO saving_collections (transaction_id, client_id, savings_id, amount, type, date, officer, balance_after, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), savings_id = VALUES(savings_id), amount = VALUES(amount), type = VALUES(type), date = VALUES(date), officer = VALUES(officer), balance_after = VALUES(balance_after), notes = VALUES(notes)");
        $inserted = 0; $skipped = 0;
        foreach ($data as $item) {
            $pk = $item['transaction_id'] ?? $item['id'] ?? null;
            if (empty($pk)) {
                log_migration_error($logSkipFile, 'saving_collections', 'missing transaction_id', $item);
                $skipped++;
                continue;
            }
            $amount = isset($item['amount']) ? $item['amount'] : 0;
            $type = $item['type'] ?? ($amount < 0 ? 'withdrawal' : 'deposit');
            $notes = $item['notes'] ?? ($item['note'] ?? null);
            safe_execute($stmt, [$pk, $item['client_id'] ?? null, $item['savings_id'] ?? null, $amount, $type, $item['date'] ?? null, $item['officer'] ?? null, $item['balance_after'] ?? null, $notes], 'saving_collections', $logErrorFile);
            $inserted++;
        }
        $results['saving_collections'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Payments (additional saving collections)
    if (file_exists($dataDir . 'payments.json')) {
        $data = json_decode(file_get_contents($dataDir . 'payments.json'), true) ?: [];
        $stmt = $pdo->prepare("INSERT INTO saving_collections (transaction_id, client_id, savings_id, amount, type, date, officer, balance_after, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), savings_id = VALUES(savings_id), amount = VALUES(amount), type = VALUES(type), date = VALUES(date), officer = VALUES(officer), balance_after = VALUES(balance_after), notes = VALUES(notes)");
        $inserted = 0; $skipped = 0;
        foreach ($data as $item) {
            $pk = $item['transaction_id'] ?? null;
            if (empty($pk)) {
                log_migration_error($logSkipFile, 'saving_collections_payments', 'missing transaction_id', $item);
                $skipped++;
                continue;
            }
            safe_execute($stmt, [$pk, $item['client_id'] ?? null, $item['savings_id'] ?? null, $item['amount'] ?? 0, $item['type'] ?? 'deposit', $item['date'] ?? null, $item['officer'] ?? null, $item['balance_after'] ?? null, $item['notes'] ?? null], 'saving_collections', $logErrorFile);
            $inserted++;
        }
        $results['saving_collections_payments'] = $inserted . ' (skipped:' . $skipped . ')';
    }

    // Compute saving balances from collections (if Saving_Balance.json didn't exist)
    if (!file_exists($dataDir . 'Saving_Balance.json')) {
        try {
            $balancesStmt = $pdo->prepare("SELECT client_id, COALESCE(SUM(CASE WHEN type='deposit' THEN amount WHEN type='withdrawal' THEN -amount ELSE amount END),0) AS balance FROM saving_collections GROUP BY client_id");
            $balancesStmt->execute();
            $updateStmt = $pdo->prepare("UPDATE saving_balances SET balance = ?, updated_at = NOW() WHERE client_id = ?");
            $insertStmt = $pdo->prepare("INSERT INTO saving_balances (client_id, balance, last_updated) VALUES (?, ?, NOW())");
            $updated = 0;
            while ($row = $balancesStmt->fetch(PDO::FETCH_ASSOC)) {
                if (empty($row['client_id'])) continue;
                try {
                    $updateStmt->execute([$row['balance'], $row['client_id']]);
                    if ($updateStmt->rowCount() === 0) {
                        $insertStmt->execute([$row['client_id'], $row['balance']]);
                    }
                } catch (Exception $e) {
                    log_migration_error($logErrorFile, 'saving_balances_compute', $e->getMessage());
                }
                $updated++;
            }
            $results['computed_saving_balances'] = $updated;
        } catch (Exception $e) {
            log_migration_error($logErrorFile, 'saving_balances_compute', $e->getMessage());
        }
    }

    // Notifications
    if (file_exists($dataDir . 'notifications.json')) {
        $data = json_decode(file_get_contents($dataDir . 'notifications.json'), true) ?: [];
        $stmt = $pdo->prepare("INSERT INTO notifications (user, title, message, type, is_read) VALUES (?, ?, ?, ?, ?)");
        foreach ($data as $item) {
            $stmt->execute([$item['user'] ?? null, $item['title'] ?? '', $item['message'] ?? null, $item['type'] ?? 'info', $item['is_read'] ?? 0]);
        }
        $results['notifications'] = count($data);
    }

    // Registrations (Client registrations - can be merged into clients or stored separately)
    if (file_exists($dataDir . 'registrations.json')) {
        $data = json_decode(file_get_contents($dataDir . 'registrations.json'), true) ?: [];
        $results['registrations'] = count($data) . ' (skipped - merged with clients)';
    }

    // Deleted Transactions
    if (file_exists($dataDir . 'deleted_transactions.json')) {
        $data = json_decode(file_get_contents($dataDir . 'deleted_transactions.json'), true) ?: [];
        $results['deleted_transactions'] = count($data) . ' (skipped)';
    }

    // Deleted Users
    if (file_exists($dataDir . 'deleted_users.json')) {
        $data = json_decode(file_get_contents($dataDir . 'deleted_users.json'), true) ?: [];
        $results['deleted_users'] = count($data) . ' (skipped)';
    }

    // User Locations
    if (file_exists($dataDir . 'user_locations.json')) {
        $data = json_decode(file_get_contents($dataDir . 'user_locations.json'), true) ?: [];
        $results['user_locations'] = count($data) . ' (skipped - no table)';
    }

    // Email Schedules
    if (file_exists($dataDir . 'email_schedules.json')) {
        $data = json_decode(file_get_contents($dataDir . 'email_schedules.json'), true) ?: [];
        $results['email_schedules'] = count($data) . ' (skipped - no table)';
    }

    // Transfer Log
    if (file_exists($dataDir . 'transfer_log.json')) {
        $data = json_decode(file_get_contents($dataDir . 'transfer_log.json'), true) ?: [];
        $results['transfer_log'] = count($data) . ' (skipped - no table)';
    }

    // Maintenance
    if (file_exists($dataDir . 'maintenance.json')) {
        $data = json_decode(file_get_contents($dataDir . 'maintenance.json'), true) ?: [];
        $results['maintenance'] = count($data) . ' (skipped - no table)';
    }

    echo "<h2>Migration Completed Successfully!</h2>";
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Table</th><th>Records</th></tr>";
    foreach ($results as $table => $count) {
        echo "<tr><td>$table</td><td>$count</td></tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    die("<h2>Error:</h2> " . $e->getMessage());
}
