<?php
date_default_timezone_set('Africa/Lagos');
// Ensure consistent timezone handling
ini_set('date.timezone', 'Africa/Lagos');
session_start();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// ============= ACTIVITY TRACKING INTEGRATION =============
// (Merged) The activity endpoint logic was moved inline so this dashboard
// can serve heartbeat POSTs and activity GETs directly. Old include removed.

// AJAX endpoint for fetching recent activities
if (isset($_GET['fetch_activities'])) {
    header('Content-Type: application/json');
    
    $base_path = '../';
    $activities = [];
    
    // Get pagination parameters
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    
    // Function to add activity with proper timestamp parsing
    function addActivity(&$activities, $title, $description, $icon, $type, $timestamp, $data = null) {
        $activities[] = [
            'title' => $title,
            'description' => $description,
            'icon' => $icon,
            'type' => $type,
            'timestamp' => $timestamp,
            'time_ago' => timeAgo($timestamp),
            'data' => $data
        ];
    }
    
    // Helper function to calculate time ago
    function timeAgo($timestamp) {
        // Use DateTime to handle a wider range of timestamp formats and timezones,
        // and compute the absolute difference in seconds for accurate hour/day values.
        try {
            $tz = new DateTimeZone(@date_default_timezone_get() ?: 'UTC');
            $dt = new DateTime($timestamp, $tz);
            $now = new DateTime('now', $tz);

            $diffSeconds = $now->getTimestamp() - $dt->getTimestamp();

            // Handle future dates
            if ($diffSeconds < 0) {
                $abs = abs($diffSeconds);
                $suffix = ' from now';
            } else {
                $abs = $diffSeconds;
                $suffix = ' ago';
            }

            if ($abs < 60) return 'Just now';
            if ($abs < 3600) {
                $m = (int) floor($abs / 60);
                return $m . ' minute' . ($m !== 1 ? 's' : '') . $suffix;
            }

            if ($abs < 86400) {
                $h = (int) floor($abs / 3600);
                return $h . ' hour' . ($h !== 1 ? 's' : '') . $suffix;
            }

            if ($abs < 2592000) {
                $d = (int) floor($abs / 86400);
                return $d . ' day' . ($d !== 1 ? 's' : '') . $suffix;
            }

            return $dt->format('M j, Y \a\t g:i A');
        } catch (Exception $e) {
            return 'Unknown time';
        }
    }
    
    // Fetch activities from various JSON files
    
    // Create a client map for efficient lookup (needed for collections/savings)
    $clients_map = [];
    $clients_file_path = $base_path . 'data/clients.json';
    if (file_exists($clients_file_path)) {
        $clients_data = json_decode(file_get_contents($clients_file_path), true);
        if ($clients_data) {
            foreach ($clients_data as $client) {
                if (isset($client['id'])) {
                    $clients_map[$client['id']] = $client;
                }
            }
        }
    }
    
    // 1. Recent client registrations
    $clients_file = $base_path . 'data/clients.json';
    if (file_exists($clients_file)) {
        $clients = json_decode(file_get_contents($clients_file), true);
        if ($clients) {
            foreach ($clients as $client) {
                if (isset($client['created_at'])) {
                    $created_time = strtotime($client['created_at']);
                    if ($created_time > time() - (3 * 24 * 3600)) { // Last 3 days for dashboard
                        addActivity($activities, 
                            'New Client Registered',
                            ($client['name'] ?? 'Unknown') . ' was registered' .
                            (isset($client['created_by']) ? ' by ' . $client['created_by'] : '') .
                            (isset($client['union']) ? ' in ' . $client['union'] : ''),
                            'fas fa-user-plus',
                            'success',
                            $client['created_at'],
                            $client
                        );
                    }
                }
            }
        }
    }
    
    // 3. Recent collections
    $collections_file = $base_path . 'data/collections.json';
    if (file_exists($collections_file)) {
        $collections = json_decode(file_get_contents($collections_file), true);
        
        if ($collections) {
            foreach ($collections as $collection) {
                if (isset($collection['date'])) {
                    $collection_time = strtotime($collection['date']);
                    if ($collection_time > time() - (3 * 24 * 3600)) { // Last 3 days for dashboard
                        $amount = $collection['amount_collected'] ?? $collection['amount'] ?? 0;
                        $client_name = 'Unknown';
                        if (isset($collection['client_id']) && isset($clients_map[$collection['client_id']])) {
                            $client_name = $clients_map[$collection['client_id']]['name'] ?? 'Unknown';
                        }
                        addActivity($activities, 
                            'Loan Collection',
                            '₦' . number_format($amount) . ' collected from ' .
                            $client_name .
                            (isset($collection['officer']) ? ' by ' . $collection['officer'] : ''),
                            'fas fa-hand-holding-usd',
                            'success',
                            $collection['date'],
                            $collection
                        );
                    }
                }
            }
        }
    }

    // 2. Recent savings transactions
    $savings_file = $base_path . 'data/savings.json';
    if (file_exists($savings_file)) {
        $savings = json_decode(file_get_contents($savings_file), true);
        if ($savings) {
            foreach ($savings as $saving) {
                if (isset($saving['date'])) {
                    $saving_time = strtotime($saving['date']);
                    if ($saving_time > time() - (3 * 24 * 3600)) { // Last 3 days for dashboard
                        $client_name = 'Unknown';
                        if (isset($saving['client_id']) && isset($clients_map[$saving['client_id']])) {
                            $client_name = $clients_map[$saving['client_id']]['name'] ?? 'Unknown';
                        }
                        addActivity($activities, 
                            'Savings Deposit',
                            '₦' . number_format($saving['amount'] ?? 0) . ' deposited by ' .
                            $client_name .
                            (isset($saving['recorded_by']) ? ' via ' . $saving['recorded_by'] : ''),
                            'fas fa-piggy-bank',
                            'info',
                            $saving['date'],
                            $saving
                        );
                    }
                }
            }
        }
    }
    
    // 4. Recent disbursements
    $disbursements_file = $base_path . 'data/disbursements.json';
    if (file_exists($disbursements_file)) {
        $disbursements = json_decode(file_get_contents($disbursements_file), true);
        if ($disbursements) {
            foreach ($disbursements as $disbursement) {
                if (isset($disbursement['date'])) {
                    $disbursement_time = strtotime($disbursement['date']);
                    if ($disbursement_time > time() - (3 * 24 * 3600)) { // Last 3 days for dashboard
                        addActivity($activities, 
                            'Loan Disbursement',
                            '₦' . number_format($disbursement['principal_amount'] ?? 0) . ' disbursed to ' .
                            ($disbursement['client_name'] ?? 'Unknown') .
                            (isset($disbursement['officer']) ? ' by ' . $disbursement['officer'] : ''),
                            'fas fa-money-bill-wave',
                            'warning',
                            $disbursement['date'],
                            $disbursement
                        );
                    }
                }
            }
        }
    }
    
    // 5. Recent user login activities (limited for dashboard)
    $users_file = $base_path . 'users.json';
    if (file_exists($users_file)) {
        $users = json_decode(file_get_contents($users_file), true);
        if ($users) {
            foreach ($users as $user) {
                // Recent logins
                if (isset($user['last_login'])) {
                    $login_time = strtotime($user['last_login']);
                    if ($login_time > time() - (12 * 3600)) { // Last 12 hours for dashboard
                        addActivity($activities, 
                            'User Login',
                            ($user['full_name'] ?? $user['username'] ?? 'Unknown') . ' logged in' .
                            (isset($user['role']) ? ' as ' . $user['role'] : ''),
                            'fas fa-sign-in-alt',
                            'info',
                            $user['last_login'],
                            $user
                        );
                    }
                }
                
                // Recent failed login attempts
                if (isset($user['failed_login_attempts']) && is_array($user['failed_login_attempts'])) {
                    foreach ($user['failed_login_attempts'] as $attempt) {
                        if (isset($attempt['timestamp'])) {
                            $attempt_time = strtotime($attempt['timestamp']);
                            if ($attempt_time > time() - (12 * 3600)) { // Last 12 hours for dashboard
                                addActivity($activities, 
                                    'Failed Login Attempt',
                                    'Failed login attempt for user ' . ($user['username'] ?? 'Unknown') .
                                    (isset($attempt['ip']) ? ' from IP: ' . $attempt['ip'] : ''),
                                    'fas fa-exclamation-triangle',
                                    'warning',
                                    $attempt['timestamp'],
                                    $attempt
                                );
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Sort activities by timestamp (newest first)
    usort($activities, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    // Calculate pagination
    $total_activities = count($activities);
    $offset = ($page - 1) * $limit;
    $paginated_activities = array_slice($activities, $offset, $limit);
    $has_more = ($offset + $limit) < $total_activities;
    
    echo json_encode([
        'activities' => $paginated_activities, 
        'count' => count($paginated_activities),
        'total' => $total_activities,
        'page' => $page,
        'has_more' => $has_more
    ]);
    exit();
}

// No $message variable needed here for the AJAX part, it will be handled by JS
// Check maintenance mode from both JSON and flag file
$maintenance_json_file = __DIR__ . '/../data/maintenance.json';
$maintenance_flag_file = __DIR__ . '/../maintenance.flag';
$is_maintenance_active = false;

// Check JSON file first
if (file_exists($maintenance_json_file)) {
    $maintenance_data = json_decode(file_get_contents($maintenance_json_file), true);
    $is_maintenance_active = isset($maintenance_data['enabled']) ? (bool)$maintenance_data['enabled'] : false;
} else {
    // Fallback to flag file
    $is_maintenance_active = file_exists($maintenance_flag_file);
}
$base_path = '../';
$page_title = "Admin Dashboard";
$full_name = $_SESSION['full_name'] ?? 'Admin';
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? '';

// Dummy data for the header stats, since admin dashboard doesn't have these
$total_clients_branch = 0;
$total_savings_branch = 0;

// Get user profile picture and path
$profile_pic_filename = 'uploads/default_avatar.png'; // Default
$users_file = $base_path . 'users.json';
$all_users = [];
if (file_exists($users_file)) {
    $all_users = json_decode(file_get_contents($users_file), true);
}

foreach ($all_users as $user) {
    if (($user['username'] ?? '') === $username) {
        if (!empty($user['profile_pic']) && file_exists($base_path . $user['profile_pic'])) {
            $profile_pic_filename = $user['profile_pic'];
        }
        break;
    }
}
$profile_pic_path = $base_path . $profile_pic_filename;

// Function to calculate real statistics from JSON files
function calculateDashboardStats($base_path) {
    $stats = [
        'total_users' => 0,
        'total_clients' => 0,
        'total_branches' => 0,
        'active_sessions' => 0,
        'system_health' => 98.5,
        'pending_registrations' => 0,
        'daily_transactions' => 0,
        'revenue_today' => 0,
        'security_alerts' => 0,
        'total_savings' => 0,
        'total_collections' => 0,
        'total_disbursements' => 0,
        'recent_revenue_fallback' => 0,
        'recent_transactions_fallback' => 0
    ];
    
    // Calculate total users
    $users_file = $base_path . 'users.json';
    if (file_exists($users_file)) {
        $users = json_decode(file_get_contents($users_file), true);
        $stats['total_users'] = count($users);
        
        // Count active sessions (users who logged in today)
        $today = date('Y-m-d');
        foreach ($users as $user) {
            if (isset($user['last_login']) && strpos($user['last_login'], $today) === 0) {
                $stats['active_sessions']++;
            }
        }
        
        // Count security alerts (failed login attempts)
        foreach ($users as $user) {
            if (isset($user['failed_login_attempts']) && is_array($user['failed_login_attempts'])) {
                $stats['security_alerts'] += count($user['failed_login_attempts']);
            }
        }
    }
    
    // Calculate total clients
    $clients_file = $base_path . 'data/clients.json';
    if (file_exists($clients_file)) {
        $clients = json_decode(file_get_contents($clients_file), true);
        $stats['total_clients'] = count($clients);
    }
    
    // Calculate branches from hierarchy
    $hierarchy_file = $base_path . 'hierarchy.json';
    if (file_exists($hierarchy_file)) {
        $hierarchy = json_decode(file_get_contents($hierarchy_file), true);
        if (isset($hierarchy['branches'])) {
            $stats['total_branches'] = count($hierarchy['branches']);
        }
    }
    
    // Calculate today\'s transactions and revenue
    $today_date = date('Y-m-d');
    $yesterday_date = date('Y-m-d', strtotime('-1 day'));
    
    // Debug: Log today\'s date and first few savings records for debugging
    error_log("Dashboard: Today\'s date: " . $today_date);
    
    // Savings transactions
    $savings_file = $base_path . 'data/savings.json';
    if (file_exists($savings_file)) {
        $savings = json_decode(file_get_contents($savings_file), true);
        $found_today_data = false;
        
        foreach ($savings as $saving) {
            if (isset($saving['date'])) {
                // Extract date part from various date formats
                $transaction_date = '';
                if (strpos($saving['date'], ' ') !== false) {
                    $date_parts = explode(' ', $saving['date']);
                    $transaction_date = $date_parts[0];
                } else {
                    $transaction_date = $saving['date'];
                }
                
                if ($transaction_date === $today_date) {
                    $found_today_data = true;
                    $stats['daily_transactions']++;
                    if (isset($saving['amount'])) {
                        $stats['revenue_today'] += $saving['amount'];
                        $stats['total_savings'] += $saving['amount'];
                    }
                }
            }
        }
        
        // If no data for today, show recent data (last 7 days) as fallback
        if (!$found_today_data) {
            $recent_cutoff = date('Y-m-d', strtotime('-7 days'));
            foreach ($savings as $saving) {
                if (isset($saving['date'])) {
                    $transaction_date = '';
                    if (strpos($saving['date'], ' ') !== false) {
                        $date_parts = explode(' ', $saving['date']);
                        $transaction_date = $date_parts[0];
                    } else {
                        $transaction_date = $saving['date'];
                    }
                    
                    if ($transaction_date >= $recent_cutoff && $transaction_date < $today_date) {
                        if (isset($saving['amount'])) {
                            $stats['recent_revenue_fallback'] += $saving['amount'];
                            $stats['recent_transactions_fallback']++;
                        }
                    }
                }
            }
        }
    }
    
    // Collections (include in today's revenue and transactions)
    $collections_file = $base_path . 'data/collections.json';
    if (file_exists($collections_file)) {
        $collections = json_decode(file_get_contents($collections_file), true);
        foreach ($collections as $collection) {
            if (isset($collection['date'])) {
                // Extract date part from various date formats
                $transaction_date = '';
                if (strpos($collection['date'], ' ') !== false) {
                    $date_parts = explode(' ', $collection['date']);
                    $transaction_date = $date_parts[0];
                } else {
                    $transaction_date = $collection['date'];
                }
                
                // Add to total collections
                $amount = $collection['amount_collected'] ?? $collection['amount'] ?? 0;
                if ($amount > 0) {
                    $stats['total_collections'] += $amount;
                }
                
                // Add to today\'s revenue if it\'s today
                if ($transaction_date === $today_date && $amount > 0) {
                    $stats['daily_transactions']++;
                    $stats['revenue_today'] += $amount;
                }
            }
        }
        
        // Add collections to fallback if no today\'s data
        if (!$found_today_data) {
            foreach ($collections as $collection) {
                if (isset($collection['date'])) {
                    $transaction_date = '';
                    if (strpos($collection['date'], ' ') !== false) {
                        $date_parts = explode(' ', $collection['date']);
                        $transaction_date = $date_parts[0];
                    }
                    else {
                        $transaction_date = $collection['date'];
                    }
                    
                    if ($transaction_date >= $recent_cutoff && $transaction_date < $today_date) {
                        $amount = $collection['amount_collected'] ?? $collection['amount'] ?? 0;
                        if ($amount > 0) {
                            $stats['recent_revenue_fallback'] += $amount;
                            $stats['recent_transactions_fallback']++;
                        }
                    }
                }
            }
        }
    }
    
    // Disbursements (loan disbursements - these are expenses, not revenue)
    $disbursements_file = $base_path . 'data/disbursements.json';
    if (file_exists($disbursements_file)) {
        $disbursements = json_decode(file_get_contents($disbursements_file), true);
        foreach ($disbursements as $disbursement) {
            if (isset($disbursement['amount'])) {
                $stats['total_disbursements'] += $disbursement['amount'];
            }
            
            // Count disbursements as transactions if they happened today
            if (isset($disbursement['date'])) {
                // Extract date part from various date formats
                $transaction_date = '';
                if (strpos($disbursement['date'], ' ') !== false) {
                    $date_parts = explode(' ', $disbursement['date']);
                    $transaction_date = $date_parts[0];
                } else {
                    $transaction_date = $disbursement['date'];
                }
                
                if ($transaction_date === $today_date) {
                    $stats['daily_transactions']++;
                }
            }
        }
    }
    
    // Pending registrations
    $registrations_file = $base_path . 'data/registrations.json';
    if (file_exists($registrations_file)) {
        $registrations = json_decode(file_get_contents($registrations_file), true);
        foreach ($registrations as $registration) {
            if (isset($registration['status']) && $registration['status'] === 'pending') {
                $stats['pending_registrations']++;
            }
        }
    }
    
    return $stats;
}

// Calculate real dashboard statistics
$dashboard_stats = calculateDashboardStats($base_path);
$dashboard_stats['maintenance_mode'] = $is_maintenance_active;

?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* ==================== 1. SIMPLE DESIGN PALETTE & VARIABLES ==================== */
        :root {
            /* Primary Colors - Simple Blue */
            --primary: #3b82f6; /* Blue 500 */
            --primary-dark: #2563eb; /* Blue 600 */
            
            /* UI Colors */
            --success: #10b981; /* Green 500 */
            --warning: #f59e0b; /* Amber 500 */
            --danger: #ef4444; /* Red 500 */
            --info: #3b82f6; /* Blue 500 */
            
            /* Surfaces & Backgrounds */
            --bg-primary: #f8fafc; /* Slate 50 */
            --bg-secondary: #ffffff; /* White (Card background) */
            
            /* Typography */
            --text-primary: #0f172a; /* Slate 900 */
            --text-secondary: #475569; /* Slate 600 */
            --text-tertiary: #94a3b8; /* Slate 400 */
            
            /* Borders & Shadows - Subtle & Clean */
            --border-color: #e2e8f0; /* Slate 200 */
            --shadow-subtle: 0 1px 3px 0 rgba(0, 0, 0, 0.08), 0 1px 2px 0 rgba(0, 0, 0, 0.04);
            
            /* Spacing & Radius */
            --space-xs: 0.25rem;
            --space-sm: 0.5rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            --radius-sm: 0.25rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-full: 9999px;
        }

        html.dark {
            /* Dark Mode Color Overrides - Simple & High Contrast */
            --bg-primary: #0f172a; /* Slate 900 */
            --bg-secondary: #1e293b; /* Slate 800 (Card background) */
            
            --text-primary: #f8fafc; /* White */
            --text-secondary: #cbd5e1; /* Slate 300 */
            --text-tertiary: #94a3b8; /* Slate 400 */
            
            --border-color: #334155; /* Slate 700 */
            --shadow-subtle: 0 4px 6px -1px rgba(0, 0, 0, 0.2), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }

        /* ==================== 2. BASE STYLES & LAYOUT ==================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.5;
            transition: background-color 0.3s, color 0.3s;
        }
        
        /* Remove complex background effects */
        #background-effects { display: none !important; }

        .container {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 var(--space-xl);
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ==================== 3. HEADER & NAVIGATION ==================== */
        .main-header {
            background: var(--bg-secondary);
            border: none;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow-subtle);
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s;
        }
        
        .header-glow { display: none; } /* Simplified: Removed Glow */

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--space-md) 0;
        }

        .logo {
            display: flex;
            align-items: center;
            font-weight: 700;
            text-decoration: none;
            gap: var(--space-sm);
        }

        .logo-img {
            width: 36px;
            height: 36px;
            object-fit: contain;
            border-radius: var(--radius-md);
        }

        .logo-text {
            font-size: 1.25rem;
            color: var(--primary-dark);
            font-weight: 800;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: var(--space-lg);
        }

        .welcome-text {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.1rem;
            color: var(--text-primary);
        }

        .username-text {
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-weight: 400;
        }

        .theme-btn, .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--border-color);
        }
        
        .theme-btn {
            background: var(--bg-primary);
            color: var(--text-secondary);
        }

        .theme-btn:hover {
            background: var(--border-color);
        }

        .user-avatar {
            object-fit: cover;
            background: var(--primary);
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }
        
        .user-avatar:hover {
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2);
        }

        /* ==================== 4. MAIN CONTENT & TITLES ==================== */
        .main-content {
            padding: var(--space-xl) 0 4rem 0;
        }

        .main-title {
            font-size: 2.5rem;
            font-weight: 900;
            margin-bottom: var(--space-sm);
            color: var(--text-primary); /* Simplified: Removed Gradient */
            text-align: center;
            animation: fadeInUp 0.5s ease both;
            letter-spacing: -0.02em;
        }

        .main-subtitle {
            text-align: center;
            font-size: 1rem;
            color: var(--text-secondary);
            font-weight: 400;
            animation: fadeInUp 0.5s ease 0.1s both;
            margin-bottom: var(--space-xl);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: var(--space-xl);
            margin-bottom: var(--space-lg);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: var(--space-sm);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }

        .section-title i {
            color: var(--primary);
            font-size: 1.25rem;
        }

        /* Simplified: Removed Section Title ::before border */
        .section-title::before { display: none; }

        /* Time Indicator - Simplified */
        .time-indicator {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.8rem;
            padding: var(--space-xs) var(--space-sm);
            border-radius: var(--radius-full);
            background: var(--bg-primary);
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: var(--radius-full);
            animation: pulseGlow 2s infinite;
        }
        
        @keyframes pulseGlow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.5); }
            50% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        }

        /* ==================== 5. STATS GRID ==================== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-md);
        }

        .stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            box-shadow: var(--shadow-subtle);
            transition: all 0.3s;
            cursor: pointer;
            animation: fadeInUp 0.4s ease forwards;
            position: relative;
        }
        
        /* Simplified: Removed complex hover/before/after effects */
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
            border-color: var(--primary);
        }
        
        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-md);
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.9rem;
            /* Simplified: Solid color background for icons */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .stat-icon.users { background: var(--primary); }
        .stat-icon.clients { background: var(--success); }
        .stat-icon.branches { background: var(--warning); }
        .stat-icon.sessions { background: #8b5cf6; } /* Purple */
        .stat-icon.maintenance { background: var(--danger); }
        .stat-icon.health { background: #06b6d4; } /* Cyan */
        .stat-icon.registrations { background: #f43f5e; } /* Rose */
        .stat-icon.transactions { background: #14b8a6; } /* Teal */
        .stat-icon.revenue { background: #84cc16; } /* Lime */
        .stat-icon.alerts { background: #f97316; } /* Orange */

        .stat-trend {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: var(--radius-md);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-trend.positive { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-trend.negative { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .stat-trend.neutral { background: rgba(107, 114, 128, 0.1); color: var(--text-secondary); }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-dark); /* Simplified: Solid primary color */
            margin-bottom: var(--space-sm);
            line-height: 1;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: var(--space-md);
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .stat-progress {
            width: 100%;
            height: 4px;
            background: var(--border-color);
            border-radius: var(--radius-full);
            overflow: hidden;
            margin-bottom: var(--space-sm);
        }

        .stat-progress-bar {
            height: 100%;
            background: var(--primary); /* Simplified: Solid primary color */
            border-radius: var(--radius-full);
            transition: width 1s ease;
        }

        .stat-change-text {
            font-size: 0.75rem;
            color: var(--text-tertiary);
            font-weight: 400;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .stat-change-text i {
            font-size: 0.7rem;
        }
        
        .stat-period {
            font-size: 0.7rem;
            color: var(--text-tertiary);
            font-weight: 500;
            text-transform: lowercase;
        }
        
        .stat-change {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* ==================== 6. CHARTS & ANALYTICS ==================== */
        .charts-section {
            margin-bottom: var(--space-xl);
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--space-lg);
        }

        .chart-container {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--space-lg);
            box-shadow: var(--shadow-subtle);
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: var(--space-md);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: var(--space-sm);
        }
        
        .chart-title i {
            color: var(--primary);
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
            width: 100%;
        }

        .chart-stats {
            display: flex;
            justify-content: space-around;
            padding-top: var(--space-lg);
            margin-top: var(--space-lg);
            border-top: 1px solid var(--border-color);
        }

        .chart-stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .chart-stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 500;
            margin-top: 0.2rem;
        }


        /* ==================== 7. ACTIVITY FEED (TIMELINE) ==================== */
        .activity-feed {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: var(--space-sm);
            box-shadow: var(--shadow-subtle);
            max-height: 450px;
            overflow-y: auto;
            transition: all 0.3s;
        }
        
        .activity-feed:hover {
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        }

        /* Activity Item - Simple List Design */
        .activity-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 0.5rem;
            margin: 0.25rem 0;
            border-radius: var(--radius-md);
            transition: all 0.2s ease;
            cursor: pointer;
            border-bottom: 1px dashed var(--border-color); /* Subtle Separator */
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item:hover {
            background: rgba(59, 130, 246, 0.05);
            transform: none; /* Removed slide effect for simplicity */
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            font-size: 0.8rem;
            color: white;
            flex-shrink: 0;
            box-shadow: none;
        }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-title {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .activity-description {
            color: var(--text-secondary);
            font-size: 0.75rem;
            line-height: 1.3;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .activity-time {
            font-size: 0.7rem;
            color: var(--text-tertiary);
            font-weight: 400;
            margin-left: auto;
            flex-shrink: 0;
            padding-left: 0.75rem;
        }
        
        /* Activity Feed Controls - Simple Buttons */
        .refresh-btn, .view-all-btn {
            border: none;
            border-radius: var(--radius-md);
            padding: 0.3rem 0.6rem;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            box-shadow: var(--shadow-subtle);
            text-decoration: none;
            font-weight: 500;
        }
        
        .refresh-btn {
            background: var(--primary);
            color: white;
        }
        
        .refresh-btn:hover { background: var(--primary-dark); }
        
        .view-all-btn {
            background: var(--bg-secondary);
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .view-all-btn:hover {
            background: var(--primary);
            color: white;
        }
        
        .activity-counter {
            background: var(--primary);
            color: white;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* ==================== 8. QUICK ACTIONS & ADMIN CONTROLS ==================== */
        
        /* Quick Actions - Simplified */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: var(--space-md);
            margin-bottom: var(--space-lg);
        }

        .quick-action-btn {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: var(--space-md) var(--space-sm);
            text-decoration: none;
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: var(--space-sm);
            transition: all 0.2s;
            box-shadow: var(--shadow-subtle);
            text-align: center;
        }

        .quick-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        }

        .quick-action-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.2s;
        }
        
        .quick-action-btn:hover .quick-action-icon {
            transform: scale(1.05);
        }

        .quick-action-btn span {
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Admin Actions Grid - Simplified */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: var(--space-md);
        }

        .action-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            box-shadow: var(--shadow-subtle);
            transition: all 0.3s;
            text-decoration: none;
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            min-height: 160px;
            cursor: pointer;
            position: relative;
        }
        
        /* Simplified: Removed complex hover/before/after effects */
        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: var(--text-primary);
            border-color: var(--primary);
        }

        .action-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-md);
        }

        .action-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .action-card.blue .action-icon { background: #3b82f6; }
        .action-card.green .action-icon { background: #10b981; }
        .action-card.yellow .action-icon { background: #f59e0b; }
        .action-card.purple .action-icon { background: #8b5cf6; }
        .action-card.indigo .action-icon { background: #6366f1; }
        .action-card.sky .action-icon { background: #06b6d4; }
        .action-card.orange .action-icon { background: #f97316; }
        .action-card.rose .action-icon { background: #f43f5e; }
        .action-card.teal .action-icon { background: #14b8a6; }

        .action-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .action-description {
            font-size: 0.875rem;
            color: var(--text-secondary);
            line-height: 1.4;
            margin-bottom: var(--space-lg);
            flex-grow: 1;
        }

        .action-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: auto;
            padding-top: var(--space-md);
            border-top: 1px solid var(--border-color);
        }

        .action-status {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: var(--radius-md);
            text-transform: uppercase;
        }

        .action-status.active { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .action-status.maintenance { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .action-arrow {
            font-size: 1rem;
            color: var(--text-secondary);
            transition: all 0.2s;
        }

        .action-card:hover .action-arrow {
            transform: translateX(2px);
            color: var(--primary);
        }
        
        .action-badge {
            position: absolute;
            top: var(--space-md);
            right: var(--space-md);
            background: var(--warning);
            color: white;
            padding: 2px 8px;
            border-radius: var(--radius-md);
            font-size: 0.6rem;
            font-weight: 700;
        }

        /* Toggle Switch - Simple & Clear */
        .toggle-switch {
            position: absolute;
            display: inline-block;
            width: 56px;
            height: 32px;
            top: var(--space-md);
            right: var(--space-md);
        }

        .toggle-switch input { opacity: 0; width: 0; height: 0; }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--border-color);
            transition: 0.4s;
            border-radius: 32px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 24px;
            width: 24px;
            left: 4px;
            bottom: 4px;
            background: white;
            transition: 0.4s;
            border-radius: 50%;
            box-shadow: var(--shadow-subtle);
        }

        input:checked + .slider { background: var(--success); }

        input:checked + .slider:before { transform: translateX(24px); }

        .maintenance-status {
            margin-top: var(--space-md);
            padding: var(--space-sm);
            border-radius: var(--radius-md);
            text-align: center;
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
        }

        .maintenance-status.active { background: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.3); }
        .maintenance-status.inactive { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); }
        
        .spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid transparent;
            border-top: 2px solid var(--primary);
            border-radius: var(--radius-full);
            animation: spin 1s linear infinite;
        }

        /* AJAX Message - Simple Alert Bar */
        .ajax-message-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: var(--space-lg);
        }

        .ajax-message {
            padding: var(--space-sm) var(--space-lg);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-weight: 500;
            animation: fadeInUp 0.4s ease forwards;
            border: 1px solid;
            box-shadow: var(--shadow-subtle);
        }

        .ajax-message.success { background: rgba(16, 185, 129, 0.1); color: var(--success); border-color: rgba(16, 185, 129, 0.3); }
        .ajax-message.error { background: rgba(239, 68, 68, 0.1); color: var(--danger); border-color: rgba(239, 68, 68, 0.3); }

        /* Modal - Simple Overlay */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4); /* Less intense backdrop */
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .modal-content {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: var(--space-xl);
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
            animation: fadeInUp 0.3s ease;
        }
        
        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 700;
        }

        .modal-close {
            font-size: 2rem;
            color: var(--text-secondary);
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: var(--danger);
        }

        .activity-detail pre {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            padding: var(--space-md);
            border-radius: var(--radius-md);
            font-size: 0.8rem;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .container {
                padding: 0 var(--space-md);
            }
            .navbar {
                flex-wrap: wrap;
                justify-content: center;
                gap: var(--space-md);
            }
            .nav-right {
                flex-grow: 1;
                justify-content: space-around;
            }
            .header-user-info {
                display: none; /* Hide for mobile space */
            }
            .main-title {
                font-size: 2rem;
            }
            .stats-grid, .actions-grid {
                grid-template-columns: 1fr;
            }
            .quick-actions {
                grid-template-columns: repeat(3, 1fr);
            }
            .charts-grid {
                grid-template-columns: 1fr;
            }
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-sm);
            }
            .time-indicator {
                align-self: flex-end;
            }
        }
        
        /* Utility */
        .hidden { display: none !important; }
        
    </style>
</head>
<body>
    
    <!-- Enhanced Header -->
    <header class="main-header">
        <div class="container">
            <nav class="navbar">
                <a href="#" class="logo">
                    <img src="<?php echo htmlspecialchars($base_path . 'uploads/CUPAD LOGO.png'); ?>" alt="CUPAD Logo" class="logo-img">
                    <span class="logo-text">CUPAD</span>
                </a>

                <div class="nav-right">
                    <div class="header-user-info">
                        <div class="welcome-text">Welcome, <?php echo htmlspecialchars($full_name); ?></div>
                        <div class="username-text">@<?php echo htmlspecialchars($username); ?> • <?php echo ucfirst(htmlspecialchars($role)); ?></div>
                    </div>

                    <button class="theme-btn" id="themeToggle" title="Toggle theme">
                        <i id="themeIcon" class="fas fa-moon"></i>
                    </button>

                    <div class="user-menu-container">
                        <?php if (file_exists($profile_pic_path)): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" alt="Profile Picture" class="user-avatar">
                        <?php else:
                            // Fallback to first letter of full name if no profile pic
                        ?>
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <!-- Enhanced Main Content -->
    <main class="main-content">
        <div class="container">
            <h1 class="main-title">Admin Dashboard</h1>
            <p class="main-subtitle">Manage your CUPAD system with comprehensive administrative controls</p>

            <!-- Enhanced AJAX Message Container -->
            <div class="ajax-message-container">
                <div id="ajaxMessage"></div>
            </div>

            <!-- Enhanced Stats Overview -->
            <section class="stats-overview">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-chart-pie"></i>
                        System Overview
                    </h2>
                    <div class="time-indicator">
                        <div class="live-dot"></div>
                        <span>Real-time data</span>
                    </div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card" style="animation-delay: 0s;">
                        <div class="stat-card-header">
                            <div class="stat-icon users">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-trend positive">
                                <i class="fas fa-arrow-up"></i>
                                <span>+12%</span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($dashboard_stats['total_users']); ?></div>
                        <div class="stat-label">Total Users</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: 85%;"></div>
                        </div>
                        <div class="stat-change">
                            <div class="stat-change-text">
                                <i class="fas fa-arrow-up"></i>
                                <span>+150 this month</span>
                            </div>
                            <span class="stat-period">monthly growth</span>
                        </div>
                    </div>

                    <div class="stat-card" style="animation-delay: 0.1s;">
                        <div class="stat-card-header">
                            <div class="stat-icon clients">
                                <i class="fas fa-user-friends"></i>
                            </div>
                            <div class="stat-trend positive">
                                <i class="fas fa-arrow-up"></i>
                                <span>+8%</span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($dashboard_stats['total_clients']); ?></div>
                        <div class="stat-label">Active Clients</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: 78%;"></div>
                        </div>
                        <div class="stat-change">
                            <div class="stat-change-text">
                                <i class="fas fa-arrow-up"></i>
                                <span>+71 new clients</span>
                            </div>
                            <span class="stat-period">this week</span>
                        </div>
                    </div>

                    <div class="stat-card" style="animation-delay: 0.2s;">
                        <div class="stat-card-header">
                            <div class="stat-icon branches">
                                <i class="fas fa-building"></i>
                            </div>
                            <div class="stat-trend neutral">
                                <i class="fas fa-minus"></i>
                                <span>stable</span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $dashboard_stats['total_branches']; ?></div>
                        <div class="stat-label">Active Branches</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: 100%;"></div>
                        </div>
                        <div class="stat-change">
                            <div class="stat-change-text">
                                <i class="fas fa-check"></i>
                                <span>All operational</span>
                            </div>
                            <span class="stat-period">branch status</span>
                        </div>
                    </div>

                    <a href="user_stats.php" class="stat-card" id="onlineUsersCard" style="text-decoration: none; color: inherit; animation-delay: 0.3s;">
                        <div class="stat-card-header">
                            <div class="stat-icon sessions" style="background: var(--success);">
                                <i class="fas fa-circle"></i>
                            </div>
                            <div class="stat-trend positive">
                                <i class="fas fa-wifi"></i>
                                <span>Live</span>
                            </div>
                        </div>
                        <div class="stat-number" id="onlineUsersCount">0</div>
                        <div class="stat-label">Online Users</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" id="onlineUsersProgress" style="width: 0%;"></div>
                        </div>
                        <div class="stat-change">
                            <div class="stat-change-text">
                                <i class="fas fa-clock"></i>
                                <span>Active within 5 minutes</span>
                            </div>
                            <span class="stat-period">real-time</span>
                        </div>
                    </a>

                    <a href="user_stats.php" class="stat-card" id="activeUsersCard" style="text-decoration: none; color: inherit; animation-delay: 0.4s;">
                        <div class="stat-card-header">
                            <div class="stat-icon sessions">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-trend positive">
                                <i class="fas fa-arrow-up"></i>
                                <span>+5%</span>
                            </div>
                        </div>
                        <div class="stat-number" id="activeUsersCount"><?php echo $dashboard_stats['active_sessions']; ?></div>
                        <div class="stat-label">Active Users</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" id="activeUsersProgress" style="width: 65%;"></div>
                        </div>
                        <div class="stat-change">
                            <div class="stat-change-text">
                                <i class="fas fa-clock"></i>
                                <span>Active within 30 minutes</span>
                            </div>
                            <span class="stat-period">live tracking</span>
                        </div>
                    </a>

                    <div class="stat-card" style="animation-delay: 0.5s;">
                        <div class="stat-card-header">
                            <div class="stat-icon health">
                                <i class="fas fa-heartbeat"></i>
                            </div>
                            <div class="stat-trend positive">
                                <i class="fas fa-check-circle"></i>
                                <span>excellent</span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $dashboard_stats['system_health']; ?>%</div>
                        <div class="stat-label">System Health</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: <?php echo $dashboard_stats['system_health']; ?>%;"></div>
                        </div>
                        <div class="stat-change">
                            <div class="stat-change-text">
                                <i class="fas fa-shield-check"></i>
                                <span>All systems operational</span>
                            </div>
                            <span class="stat-period">system status</span>
                        </div>
                    </div>

                    <div class="stat-card" style="animation-delay: 0.6s;">
                        <div class="stat-card-header">
                            <div class="stat-icon registrations">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div class="stat-trend <?php echo $dashboard_stats['pending_registrations'] > 20 ? 'negative' : 'positive'; ?>">
                                <i class="fas fa-<?php echo $dashboard_stats['pending_registrations'] > 20 ? 'exclamation-triangle' : 'check'; ?>"></i>
                                <span><?php echo $dashboard_stats['pending_registrations'] > 20 ? 'high' : 'normal'; ?></span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $dashboard_stats['pending_registrations']; ?></div>
                        <div class="stat-label">Pending Registrations</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: <?php echo min(($dashboard_stats['pending_registrations'] / 50) * 100, 100); ?>%;"></div>
                        </div>
                        <div class="stat-change">
                            <div class="stat-change-text">
                                <i class="fas fa-clock"></i>
                                <span>Awaiting approval</span>
                            </div>
                            <span class="stat-period">registration queue</span>
                        </div>
                    </div>

                    <div class="stat-card" style="animation-delay: 0.7s;">
                        <div class="stat-card-header">
                            <div class="stat-icon transactions">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <div class="stat-trend <?php echo ($dashboard_stats['daily_transactions'] > 0) ? 'positive' : 'neutral'; ?>">
                                <i class="fas fa-<?php echo ($dashboard_stats['daily_transactions'] > 0) ? 'arrow-up' : 'minus'; ?>"></i>
                                <span><?php echo ($dashboard_stats['daily_transactions'] > 0) ? '+15%' : 'No data'; ?></span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($dashboard_stats['daily_transactions'] ?: $dashboard_stats['recent_transactions_fallback']); ?></div>
                        <div class="stat-label">Daily Transactions</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: <?php echo min(($dashboard_stats['daily_transactions'] ?: $dashboard_stats['recent_transactions_fallback']) / 100 * 100, 100); ?>%;"></div>
                        </div>
                        <div class="stat-change">
                            <div class="stat-change-text">
                                <i class="fas fa-<?php echo ($dashboard_stats['daily_transactions'] > 0) ? 'arrow-up' : 'info-circle'; ?>"></i>
                                <span><?php echo ($dashboard_stats['daily_transactions'] > 0) ? '+52 from yesterday' : ($dashboard_stats['recent_transactions_fallback'] > 0 ? number_format($dashboard_stats['recent_transactions_fallback']) . ' recent transactions' : 'No recent activity'); ?></span>
                            </div>
                            <span class="stat-period"><?php echo ($dashboard_stats['daily_transactions'] > 0) ? 'daily activity' : 'last 7 days'; ?></span>
                        </div>
                    </div>

                    <div class="stat-card" style="animation-delay: 0.8s;">
                        <div class="stat-card-header">
                            <div class="stat-icon revenue">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="stat-trend <?php echo ($dashboard_stats['revenue_today'] > 0) ? 'positive' : 'neutral'; ?>">
                                <i class="fas fa-<?php echo ($dashboard_stats['revenue_today'] > 0) ? 'arrow-up' : 'minus'; ?>"></i>
                                <span><?php echo ($dashboard_stats['revenue_today'] > 0) ? '+22%' : 'No data'; ?></span>
                            </div>
                        </div>
                        <div class="stat-number">₦<?php echo number_format($dashboard_stats['revenue_today'] ?: $dashboard_stats['recent_revenue_fallback']); ?></div>
                        <div class="stat-label">Today's Revenue</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: <?php echo min(($dashboard_stats['revenue_today'] ?: $dashboard_stats['recent_revenue_fallback']) / 100000 * 100, 100); ?>%;"></div>
                        </div>
                        <div class="stat-change">
                            <div class="stat-change-text">
                                <i class="fas fa-<?php echo ($dashboard_stats['revenue_today'] > 0) ? 'arrow-up' : 'info-circle'; ?>"></i>
                                <span><?php echo ($dashboard_stats['revenue_today'] > 0) ? '+₦8,400 from yesterday' : ($dashboard_stats['recent_revenue_fallback'] > 0 ? '+₦' . number_format($dashboard_stats['recent_revenue_fallback']) . ' recent revenue' : 'No recent revenue'); ?></span>
                            </div>
                            <span class="stat-period"><?php echo ($dashboard_stats['revenue_today'] > 0) ? 'daily earnings' : 'last 7 days'; ?></span>
                        </div>
                    </div>

                    <div class="stat-card" style="animation-delay: 0.9s;">
                        <div class="stat-card-header">
                            <div class="stat-icon alerts">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div class="stat-trend <?php echo $dashboard_stats['security_alerts'] > 0 ? 'negative' : 'positive'; ?>">
                                <i class="fas fa-<?php echo $dashboard_stats['security_alerts'] > 0 ? 'exclamation-triangle' : 'check'; ?>"></i>
                                <span><?php echo $dashboard_stats['security_alerts'] > 0 ? 'alerts' : 'secure'; ?></span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $dashboard_stats['security_alerts']; ?></div>
                        <div class="stat-label">Security Alerts</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: <?php echo $dashboard_stats['security_alerts'] > 0 ? '25%' : '100%'; ?>;"></div>
                        </div>
                        <div class="stat-change">
                            <div class="stat-change-text">
                                <i class="fas fa-<?php echo $dashboard_stats['security_alerts'] > 0 ? 'eye' : 'shield-check'; ?>"></i>
                                <span><?php echo $dashboard_stats['security_alerts'] > 0 ? 'Monitoring active' : 'All secure'; ?></span>
                            </div>
                            <span class="stat-period">security status</span>
                        </div>
                    </div>

                    <div class="stat-card" style="animation-delay: 1s;">
                        <div class="stat-card-header">
                            <div class="stat-icon maintenance">
                                <i class="fas fa-tools"></i>
                            </div>
                            <div class="stat-trend <?php echo $dashboard_stats['maintenance_mode'] ? 'negative' : 'positive'; ?>">
                                <i class="fas fa-<?php echo $dashboard_stats['maintenance_mode'] ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                                <span><?php echo $dashboard_stats['maintenance_mode'] ? 'active' : 'normal'; ?></span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $dashboard_stats['maintenance_mode'] ? 'ON' : 'OFF'; ?></div>
                        <div class="stat-label">Maintenance Mode</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: <?php echo $dashboard_stats['maintenance_mode'] ? '100%' : '0%'; ?>;"></div>
                        </div>
                        <div class="stat-change">
                            <div class="stat-change-text">
                                <i class="fas fa-<?php echo $dashboard_stats['maintenance_mode'] ? 'pause' : 'play'; ?>"></i>
                                <span><?php echo $dashboard_stats['maintenance_mode'] ? 'System maintenance' : 'System operational'; ?></span>
                            </div>
                            <span class="stat-period">current status</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Enhanced Charts and Analytics Section -->
            <section class="charts-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-chart-line"></i>
                        Analytics & Insights
                    </h2>
                </div>
                
                <div class="charts-grid">
                    <div class="chart-container">
                        <h3 class="chart-title">
                            <i class="fas fa-chart-area"></i>
                            Monthly Revenue Trend
                        </h3>
                        <div class="chart-wrapper">
                            <canvas id="revenueChart"></canvas>
                        </div>
                        <div class="chart-stats">
                            <div class="chart-stat">
                                <div class="chart-stat-value">₦<?php echo number_format($dashboard_stats['revenue_today'] ?: $dashboard_stats['recent_revenue_fallback']); ?></div>
                                <div class="chart-stat-label"><?php echo ($dashboard_stats['revenue_today'] > 0) ? "Today's Revenue" : "Recent Revenue (7 days)"; ?></div>
                            </div>
                            <div class="chart-stat">
                                <div class="chart-stat-value"><?php echo number_format($dashboard_stats['daily_transactions'] ?: $dashboard_stats['recent_transactions_fallback']); ?></div>
                                <div class="chart-stat-label"><?php echo ($dashboard_stats['daily_transactions'] > 0) ? "Transactions" : "Recent Transactions"; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="chart-container">
                        <h3 class="chart-title">
                            <i class="fas fa-chart-pie"></i>
                            User Distribution
                        </h3>
                        <div class="chart-wrapper">
                            <canvas id="userChart"></canvas>
                        </div>
                        <div class="chart-stats">
                            <div class="chart-stat">
                                <div class="chart-stat-value"><?php echo number_format($dashboard_stats['total_users']); ?></div>
                                <div class="chart-stat-label">Total Users</div>
                            </div>
                            <div class="chart-stat">
                                <div class="chart-stat-value"><?php echo number_format($dashboard_stats['active_sessions']); ?></div>
                                <div class="chart-stat-label">Active Today</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Enhanced Recent Activity Feed -->
            <section class="activity-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-history"></i>
                        Recent Activity
                        <span class="activity-counter" id="activityCounter">Loading...</span>
                    </h2>
                    <div class="time-indicator">
                        <div class="live-dot"></div>
                        <span>Live updates</span>
                        <button id="refreshActivities" class="refresh-btn" title="Refresh Activities">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                        <a href="recent_activities.php" class="view-all-btn" title="View All Activities">
                            <i class="fas fa-external-link-alt"></i>
                            View All
                        </a>
                    </div>
                </div>
                
                <div class="activity-feed" id="activityFeed">
                    <div class="activity-loading">
                        <div class="loading-spinner"></div>
                        <span>Loading recent activities...</span>
                    </div>
                </div>
            </section>

            <!-- Enhanced Quick Actions Section -->
            <section class="quick-actions-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-bolt"></i>
                        Quick Actions
                    </h2>
                </div>
                
                <div class="quick-actions">
                    <a href="create_account.php" class="quick-action-btn" style="animation-delay: 1.1s;">
                        <div class="quick-action-icon" style="background: var(--success);">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <span>New Account</span>
                    </a>

                    <a href="manage_registrations.php" class="quick-action-btn" style="animation-delay: 1.2s;">
                        <div class="quick-action-icon" style="background: var(--warning);">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <span>Approve Registrations</span>
                    </a>

                    <a href="recent_activities.php" class="quick-action-btn" style="animation-delay: 1.3s;">
                        <div class="quick-action-icon" style="background: #06b6d4;">
                            <i class="fas fa-history"></i>
                        </div>
                        <span>Recent Activities</span>
                    </a>

                    <a href="analytics.php" class="quick-action-btn" style="animation-delay: 1.4s;">
                        <div class="quick-action-icon" style="background: #8b5cf6;">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <span>View Analytics</span>
                    </a>

                    <a href="compress_uploads.php" class="quick-action-btn" title="Compress large uploaded images" style="animation-delay: 1.5s;">
                        <div class="quick-action-icon" style="background: #6b7280;">
                            <i class="fas fa-compress"></i>
                        </div>
                        <span>Compress Uploads</span>
                    </a>

                    <a href="system_logs.php" class="quick-action-btn" style="animation-delay: 1.6s;">
                        <div class="quick-action-icon" style="background: var(--danger);">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <span>System Logs</span>
                    </a>
                </div>
            </section>

            <!-- Enhanced Admin Actions -->
            <section class="actions-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-cogs"></i>
                        Administrative Controls
                    </h2>
                </div>
                
                <div class="actions-grid">
                    <a href="assign_role.php" class="action-card blue" style="animation-delay: 1.7s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-user-tag"></i>
                            </div>
                        </div>
                        <div class="action-title">Assign Roles</div>
                        <div class="action-description">Manage user roles and permissions across the entire system with granular control</div>
                        <div class="action-footer">
                            <div class="action-status active">Active</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="active_users_admin.php" class="action-card purple" style="animation-delay: 1.8s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                        <div class="action-title">Active Users Monitor</div>
                        <div class="action-description">Real-time monitoring of user activity, online status, and engagement tracking</div>
                        <div class="action-footer">
                            <div class="action-status active">Live</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <div class="action-card green" style="animation-delay: 1.9s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-tools"></i>
                            </div>
                        </div>
                        <div class="action-title">Maintenance Mode</div>
                        <div class="action-description">Toggle system maintenance mode to perform updates and maintenance safely</div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="maintenanceToggle" <?php echo $is_maintenance_active ? 'checked' : ''; ?>> 
                            <span class="slider"></span>
                        </label>
                        <div class="maintenance-status <?php echo $is_maintenance_active ? 'active' : 'inactive'; ?>">
                            <span id="maintenanceStatusText"><?php echo $is_maintenance_active ? 'ACTIVE' : 'INACTIVE'; ?></span>
                            <span id="maintenanceSpinner" class="spinner hidden"></span>
                        </div>
                    </div>

                    <a href="create_account.php" class="action-card yellow" style="animation-delay: 2.0s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                        </div>
                        <div class="action-title">Create Account</div>
                        <div class="action-description">Add new user accounts to the system with appropriate roles and permissions</div>
                        <div class="action-footer">
                            <div class="action-status active">Ready</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="manage_zones_branches.php" class="action-card purple" style="animation-delay: 2.1s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-map-marked-alt"></i>
                            </div>
                        </div>
                        <div class="action-title">Zones & Branches</div>
                        <div class="action-description">Manage geographical zones and branch locations with comprehensive mapping tools</div>
                        <div class="action-footer">
                            <div class="action-status active">Available</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="manage_users.php" class="action-card blue" style="animation-delay: 2.2s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-users-cog"></i>
                            </div>
                        </div>
                <h3 class="action-title">Manage Users</h3>
                <p class="action-description">Create, edit, and assign roles to users.</p>
                <div class="action-footer">
                    <span class="action-status active">Active</span>
                    <i class="fas fa-arrow-right action-arrow"></i>
                </div>
            </a>
            <a href="manage_lockout.php" class="action-card blue" style="animation-delay: 2.3s;">
                <div class="action-card-header">
                    <div class="action-icon">
                        <i class="fas fa-user-lock"></i>
                    </div>
                </div>
                <h3 class="action-title">Manage Lockouts</h3>
                <p class="action-description">Manage user lockouts and unlock accounts.</p>
                <div class="action-footer">
                    <span class="action-status active">Active</span>
                    <i class="fas fa-arrow-right action-arrow"></i>
                </div>
            </a>

                    <a href="client_financial_summary.php" class="action-card green" style="animation-delay: 2.4s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                        </div>
                        <h3 class="action-title">Client Financial Summary</h3>
                        <p class="action-description">View savings, loans, and balances for all clients.</p>
                        <div class="action-footer">
                            <div class="action-status active">View Report</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="branch_disbursements.php" class="action-card sky" style="animation-delay: 2.5s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                        </div>
                        <div class="action-title">Branch Disbursement Report</div>
                        <div class="action-description">View detailed disbursement reports grouped by branch.</div>
                        <div class="action-footer">
                            <div class="action-status active">View Report</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="manage_clients.php" class="action-card sky" style="animation-delay: 2.6s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-user-friends"></i>
                            </div>
                        </div>
                        <div class="action-title">Manage Clients</div>
                        <div class="action-description">Handle client accounts and relationships with comprehensive client management tools</div>
                        <div class="action-footer">
                            <div class="action-status active">Available</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="transfer_client.php" class="action-card orange" style="animation-delay: 2.7s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                        </div>
                        <div class="action-title">Transfer Client</div>
                        <div class="action-description">Transfer clients between branches or users with full audit trail and notifications</div>
                        <div class="action-footer">
                            <div class="action-status active">Ready</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="system_logs.php" class="action-card rose" style="animation-delay: 2.8s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                        </div>
                        <div class="action-title">System Logs</div>
                        <div class="action-description">View detailed system activity and error logs with advanced search and filtering</div>
                        <div class="action-footer">
                            <div class="action-status active">Monitoring</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="analytics.php" class="action-card teal" style="animation-delay: 2.9s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                        <div class="action-title">Analytics Dashboard</div>
                        <div class="action-description">View comprehensive system analytics and reports with interactive charts and insights</div>
                        <div class="action-footer">
                            <div class="action-status active">Live Data</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="backup_restore.php" class="action-card blue" style="animation-delay: 3.0s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-database"></i>
                            </div>
                        </div>
                        <div class="action-title">Backup & Restore</div>
                        <div class="action-description">Create system backups and restore data with automated scheduling and verification</div>
                        <div class="action-footer">
                            <div class="action-status active">Scheduled</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="deleted_users_manager.php" class="action-card orange" style="animation-delay: 3.1s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-trash-restore"></i>
                            </div>
                        </div>
                        <div class="action-title">Deleted Users</div>
                        <div class="action-description">Manage and restore deleted user accounts with recovery options and audit trails</div>
                        <div class="action-footer">
                            <div class="action-status active">Available</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="regfee.php" class="action-card green" style="animation-delay: 3.2s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                        </div>
                        <div class="action-title">Registration Settings</div>
                        <div class="action-description">Configure registration fees and settings with flexible pricing models and discounts</div>
                        <div class="action-footer">
                            <div class="action-status active">Configured</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="disbursement_settings.php" class="action-card orange" style="animation-delay: 3.3s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                        </div>
                        <div class="action-title">Disbursement Settings</div>
                        <div class="action-description">Configure loan disbursement parameters with automated workflows and approvals</div>
                        <div class="action-footer">
                            <div class="action-status active">Active</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="loan_collection_settings.php" class="action-card teal" style="animation-delay: 3.4s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                        </div>
                        <div class="action-title">Loan Collection Settings</div>
                        <div class="action-description">Configure loan collection date and other related settings.</div>
                        <div class="action-footer">
                            <div class="action-status active">Configure</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="location_tracker.php" class="action-card yellow" style="animation-delay: 3.5s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                        </div>
                        <div class="action-title">Location Tracker</div>
                        <div class="action-description">Track and monitor user locations with real-time GPS integration and geofencing</div>
                        <div class="action-footer">
                            <div class="action-status active">Tracking</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="location_map.php" class="action-card purple" style="animation-delay: 3.6s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-globe"></i>
                            </div>
                        </div>
                        <div class="action-title">Location Map</div>
                        <div class="action-description">Interactive map view of all locations with clustering and detailed information panels</div>
                        <div class="action-footer">
                            <div class="action-status active">Interactive</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="transaction_manager.php" class="action-card indigo" style="animation-delay: 3.7s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                        </div>
                        <div class="action-title">Transaction Manager</div>
                        <div class="action-description">Monitor and manage all financial transactions with real-time processing and fraud detection</div>
                        <div class="action-footer">
                            <div class="action-status active">Processing</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="manage_registrations.php" class="action-card teal" style="animation-delay: 3.8s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-user-check"></i>
                            </div>
                        </div>
                        <div class="action-title">Manage Registrations</div>
                        <div class="action-description">Handle new user registration requests with automated verification and approval workflows</div>
                        <div class="action-footer">
                            <div class="action-status active">Pending: <?php echo $dashboard_stats['pending_registrations']; ?></div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="manage_unions.php" class="action-card purple" style="animation-delay: 3.9s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                        <div class="action-title">Manage Unions</div>
                        <div class="action-description">Manage cooperative unions and groups with membership tracking and financial oversight</div>
                        <div class="action-footer">
                            <div class="action-status active">Available</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="manage_pin.php" class="action-card indigo" style="animation-delay: 4.0s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                        </div>
                        <div class="action-title">Manage PIN</div>
                        <div class="action-description">Security PIN management system with encryption and multi-factor authentication</div>
                        <div class="action-badge">Admin Only</div>
                        <div class="action-footer">
                            <div class="action-status active">Secure</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="migration.php" class="action-card purple" style="animation-delay: 4.1s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-database"></i>
                            </div>
                        </div>
                        <div class="action-title">Data Migration</div>
                        <div class="action-description">Backup JSON data to MySQL database and restore from MySQL to JSON files</div>
                        <div class="action-footer">
                            <div class="action-status active">Available</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>

                    <a href="../logout.php" class="action-card rose" style="animation-delay: 4.2s;">
                        <div class="action-card-header">
                            <div class="action-icon">
                                <i class="fas fa-sign-out-alt"></i>
                            </div>
                        </div>
                        <div class="action-title">Logout</div>
                        <div class="action-description">Safely logout from the admin dashboard with session cleanup and security logging</div>
                        <div class="action-footer">
                            <div class="action-status">Exit</div>
                            <i class="fas fa-arrow-right action-arrow"></i>
                        </div>
                    </a>
                </div>
            </section>
        </div>
    </main>

    <script>
        // Enhanced theme toggle functionality
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        const html = document.documentElement;

        // Load saved theme
        const savedTheme = localStorage.getItem('theme') || 'light';
        html.className = savedTheme;
        themeIcon.className = savedTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

        themeToggle.addEventListener('click', () => {
            const currentTheme = html.className;
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            html.className = newTheme;
            themeIcon.className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            localStorage.setItem('theme', newTheme);

            // Smooth theme transition
            document.body.style.transition = 'all 0.3s ease';
            setTimeout(() => {
                document.body.style.transition = '';
            }, 300);
        });

        // Enhanced maintenance mode toggle
        const maintenanceToggle = document.getElementById('maintenanceToggle');
        const maintenanceStatusText = document.getElementById('maintenanceStatusText');
        const maintenanceSpinner = document.getElementById('maintenanceSpinner');
        const ajaxMessage = document.getElementById('ajaxMessage');

        maintenanceToggle.addEventListener('change', async function() {
            const isChecked = this.checked;
            
            // Show spinner
            maintenanceSpinner.classList.remove('hidden');
            
            try {
                const response = await fetch('toggle_maintenance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ maintenance: isChecked })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    maintenanceStatusText.textContent = isChecked ? 'ACTIVE' : 'INACTIVE';
                    const statusContainer = maintenanceStatusText.parentElement;
                    statusContainer.className = `maintenance-status ${isChecked ? 'active' : 'inactive'}`;
                    
                    showMessage('Maintenance mode ' + (isChecked ? 'activated' : 'deactivated') + ' successfully!', 'success');
                } else {
                    throw new Error(result.message || 'Unknown error');
                }
            } catch (error) {
                // Revert toggle state
                this.checked = !isChecked;
                showMessage('Error toggling maintenance mode: ' + error.message, 'error');
            } finally {
                // Hide spinner
                maintenanceSpinner.classList.add('hidden');
            }
        });

        function showMessage(message, type) {
            ajaxMessage.innerHTML = `
                <div class="ajax-message ${type}">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                ajaxMessage.innerHTML = '';
            }, 5000);
        }

        // Enhanced progress bar animation
        function animateProgressBars() {
            const progressBars = document.querySelectorAll('.stat-progress-bar');
            progressBars.forEach((bar, index) => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, index * 100 + 50); // Faster animation
            });
        }

        // Start progress bar animation when page loads
        window.addEventListener('load', () => {
            setTimeout(animateProgressBars, 100);
        });

        // Real-time clock update for better UX
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString();
            const timeIndicators = document.querySelectorAll('.time-indicator span');
            timeIndicators.forEach(indicator => {
                if (indicator.textContent === 'Live updates') {
                    indicator.setAttribute('title', `Last updated: ${timeString}`);
                }
            });
        }

        setInterval(updateTime, 1000);
        updateTime(); // Initial call

        // Initialize Charts with enhanced styling
        function initializeCharts() {
            // Get root CSS variables for dynamic color in charts
            const style = getComputedStyle(document.body);
            const primaryColor = style.getPropertyValue('--primary').trim();
            const textSecondary = style.getPropertyValue('--text-secondary').trim();
            const successColor = style.getPropertyValue('--success').trim();
            const warningColor = style.getPropertyValue('--warning').trim();
            const dangerColor = style.getPropertyValue('--danger').trim();
            const purpleColor = '#8b5cf6';
            
            // Function to convert hex to rgba
            const hexToRgba = (hex, alpha) => {
                const r = parseInt(hex.slice(1, 3), 16);
                const g = parseInt(hex.slice(3, 5), 16);
                const b = parseInt(hex.slice(5, 7), 16);
                return `rgba(${r}, ${g}, ${b}, ${alpha})`;
            };

            // Revenue Chart
            const revenueCtx = document.getElementById('revenueChart');
            if (revenueCtx) {
                new Chart(revenueCtx, {
                    type: 'line',
                    data: {
                        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                        datasets: [{
                            label: 'Monthly Revenue',
                            data: [1200000, 1350000, 1480000, 1620000, 1750000, 1890000, 2100000, 2250000, 2180000, 2350000, 2480000, 2650000],
                            borderColor: primaryColor,
                            backgroundColor: hexToRgba(primaryColor, 0.1),
                            borderWidth: 2,
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: primaryColor,
                            pointBorderColor: style.getPropertyValue('--bg-secondary').trim(),
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) { return '₦' + (value / 1000000).toFixed(1) + 'M'; },
                                    color: textSecondary
                                },
                                grid: { color: hexToRgba(style.getPropertyValue('--border-color').trim(), 0.5) }
                            },
                            x: {
                                ticks: { color: textSecondary },
                                grid: { color: hexToRgba(style.getPropertyValue('--border-color').trim(), 0.5) }
                            }
                        }
                    }
                });
            }

            // User Distribution Chart
            const userCtx = document.getElementById('userChart');
            if (userCtx) {
                new Chart(userCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Admin', 'Branch Manager', 'Credit Officer', 'Cashier'],
                        datasets: [{
                            data: [5, 12, 25, 8],
                            backgroundColor: [
                                primaryColor,
                                successColor,
                                warningColor,
                                dangerColor
                            ],
                            borderWidth: 0,
                            hoverOffset: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 25,
                                    usePointStyle: true,
                                    pointStyle: 'circle',
                                    color: textSecondary
                                }
                            }
                        }
                    }
                });
            }
        }

        // Initialize charts when page loads
        window.addEventListener('load', () => {
            setTimeout(initializeCharts, 100);
        });

        // Function to update charts on theme change for better UX
        document.getElementById('themeToggle').addEventListener('click', () => {
            setTimeout(initializeCharts, 350); // Re-initialize charts after theme change
        });


        // Fetch and update user statistics
        function updateUserStats() {
            fetch('ajax/get_active_users.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }

                    const onlineCount = data.online_users || 0;
                    const activeCount = data.active_users || 0;
                    const totalUsers = data.total_users || <?php echo $dashboard_stats['total_users']; ?>;

                    // Update online users
                    document.getElementById('onlineUsersCount').textContent = onlineCount;
                    document.getElementById('onlineUsersProgress').style.width =
                        (totalUsers > 0 ? Math.min((onlineCount / totalUsers) * 100, 100) : 0) + '%';

                    // Update active users
                    document.getElementById('activeUsersCount').textContent = activeCount;
                    document.getElementById('activeUsersProgress').style.width =
                        (totalUsers > 0 ? Math.min((activeCount / totalUsers) * 100, 100) : 0) + '%';
                })
                .catch(err => {
                    console.error('Error updating user stats:', err);
                    // Optionally, display an error message on the dashboard cards
                    document.getElementById('onlineUsersCount').textContent = 'N/A';
                    document.getElementById('activeUsersCount').textContent = 'N/A';
                });
        }
        
        // Initial update and set interval
        updateUserStats();
        setInterval(updateUserStats, 30000);

        // Enhanced Activity Feed Functions with AJAX and Infinite Scroll
        let activityUpdateInterval;
        let lastActivityCount = 0;
        let currentActivityPage = 1;
        let isLoadingActivities = false;
        let hasMoreActivities = true;

        // Fetch activities from server
        async function fetchActivities(page = 1, append = false) {
            if (isLoadingActivities && append) return;
            
            try {
                isLoadingActivities = true;
                if (!append) showActivityLoading(true);
                
                const response = await fetch(`?fetch_activities&page=${page}&limit=10`);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                renderActivities(data.activities, append);
                updateActivityCounter(data.total); // Use total for count, not page count
                hasMoreActivities = data.has_more || false;
                currentActivityPage = page;
                isLoadingActivities = false;
                
                showActivityLoading(false);
                
                // Trigger pulse on live dots for successful refresh
                const liveDots = document.querySelectorAll('.live-dot');
                liveDots.forEach(dot => {
                    dot.style.animation = 'none';
                    dot.offsetHeight; // Trigger reflow
                    dot.style.animation = 'pulseGlow 2s infinite';
                });
                
                return data;
            } catch (error) {
                console.error('Error fetching activities:', error);
                showActivityError();
                showActivityLoading(false);
                isLoadingActivities = false;
                return null;
            }
        }

        // Render activities in the feed
        function renderActivities(activities, append = false) {
            const activityFeed = document.getElementById('activityFeed');
            
            if (!activities || activities.length === 0) {
                if (!append) {
                    activityFeed.innerHTML = `
                        <div class="activity-empty">
                            <i class="fas fa-history"></i>
                            <h3>No Recent Activities</h3>
                            <p>No recent activities found in the system. Activities will appear here as they happen.</p>
                        </div>
                    `;
                }
                return;
            }
            
            if (!append) {
                activityFeed.innerHTML = '';
            } else {
                // Remove loading indicator if exists
                const loader = activityFeed.querySelector('.activity-loading-more');
                if (loader) loader.remove();
            }
            
            activities.forEach((activity, index) => {
                const activityItem = document.createElement('div');
                activityItem.className = 'activity-item';
                
                activityItem.innerHTML = `
                    <div class="activity-icon ${activity.type}">
                        <i class="${activity.icon}"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title">${escapeHtml(activity.title)}</div>
                        <div class="activity-description">${escapeHtml(activity.description)}</div>
                    </div>
                    <div class="activity-time">${escapeHtml(activity.time_ago)}</div>
                `;
                
                activityItem.addEventListener('click', (e) => {
                    showActivityDetails(activity, e);
                });
                
                activityFeed.appendChild(activityItem);
            });
        }

        // Show activity loading state
        function showActivityLoading(show) {
            const activityFeed = document.getElementById('activityFeed');
            const refreshBtn = document.getElementById('refreshActivities');
            
            if (show) {
                if (!activityFeed.querySelector('.activity-loading')) {
                    activityFeed.innerHTML = `
                        <div class="activity-loading">
                            <div class="loading-spinner"></div>
                            <span>Loading recent activities...</span>
                        </div>
                    `;
                }
                if (refreshBtn) {
                    refreshBtn.classList.add('refreshing');
                }
            } else {
                if (refreshBtn) {
                    refreshBtn.classList.remove('refreshing');
                }
                // Remove initial loader if activities loaded
                const initialLoader = activityFeed.querySelector('.activity-loading');
                if (initialLoader && activityFeed.children.length > 1) {
                    initialLoader.remove();
                }
            }
        }

        // Show activity error state
        function showActivityError() {
            const activityFeed = document.getElementById('activityFeed');
            activityFeed.innerHTML = `
                <div class="activity-empty">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Error Loading Activities</h3>
                    <p>Unable to load recent activities. Please check your connection and try again.</p>
                    <button onclick="refreshActivities()" class="refresh-btn" style="margin-top: 1rem;">
                        <i class="fas fa-retry"></i> Retry
                    </button>
                </div>
            `;
        }

        // Update activity counter
        function updateActivityCounter(totalCount) {
            const activityCounter = document.getElementById('activityCounter');
            
            if (activityCounter) {
                // Pulse animation only for new activities on subsequent fetches
                if (totalCount > lastActivityCount && lastActivityCount > 0) {
                    const newActivities = totalCount - lastActivityCount;
                    activityCounter.textContent = `${newActivities} New`;
                    activityCounter.classList.add('pulse');
                    setTimeout(() => {
                        activityCounter.classList.remove('pulse');
                        activityCounter.textContent = `${totalCount} Activities`;
                    }, 1000);
                } else {
                    activityCounter.textContent = `${totalCount} Activities`;
                }
                
                lastActivityCount = totalCount;
            }
        }

        // Show activity details in modal
        function showActivityDetails(activity, event) {
            if (event) event.stopPropagation();
            
            // Create modal for activity details
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>${escapeHtml(activity.title)}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="activity-detail">
                            <div class="activity-icon ${activity.type}" style="margin: 0 auto 1rem auto; width: 40px; height: 40px; font-size: 1rem;">
                                <i class="${activity.icon}"></i>
                            </div>
                            <p><strong>Description:</strong> ${escapeHtml(activity.description)}</p>
                            <p><strong>Time:</strong> ${escapeHtml(activity.time_ago)} (${escapeHtml(activity.timestamp)})</p>
                            ${activity.data ? `<p><strong>Additional Data:</strong></p><pre>${JSON.stringify(activity.data, null, 2)}</pre>` : ''}
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Close modal functionality
            modal.addEventListener('click', (e) => {
                if (e.target === modal || e.target.classList.contains('modal-close')) {
                    document.body.removeChild(modal);
                }
            });
        }

        // Utility function to escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Start automatic activity updates
        function startActivityUpdates() {
            // Initial fetch
            fetchActivities();
            
            // Set up interval for automatic updates every 30 seconds
            activityUpdateInterval = setInterval(() => fetchActivities(1, false), 30000);
            
            // Setup infinite scroll
            setupInfiniteScroll();
        }
        
        // Setup infinite scroll for activity feed
        function setupInfiniteScroll() {
            const activityFeed = document.getElementById('activityFeed');
            if (!activityFeed) return;
            
            activityFeed.addEventListener('scroll', () => {
                if (isLoadingActivities || !hasMoreActivities) return;
                
                const scrollTop = activityFeed.scrollTop;
                const scrollHeight = activityFeed.scrollHeight;
                const clientHeight = activityFeed.clientHeight;
                
                // Load more when scrolled near bottom (within 100px)
                if (scrollTop + clientHeight >= scrollHeight - 100) {
                    loadMoreActivities();
                }
            });
        }
        
        // Load more activities
        async function loadMoreActivities() {
            if (!hasMoreActivities || isLoadingActivities) return;
            
            const activityFeed = document.getElementById('activityFeed');
            const loader = document.createElement('div');
            loader.className = 'activity-loading-more';
            loader.innerHTML = '<div class="loading-spinner" style="width: 24px; height: 24px; margin: 1rem auto;"></div>';
            activityFeed.appendChild(loader);
            
            await fetchActivities(currentActivityPage + 1, true);
        }

        // Stop automatic activity updates
        function stopActivityUpdates() {
            if (activityUpdateInterval) {
                clearInterval(activityUpdateInterval);
                activityUpdateInterval = null;
            }
        }

        // Manual refresh activities
        function refreshActivities() {
            currentActivityPage = 1;
            hasMoreActivities = true;
            fetchActivities(1, false);
        }

        // Initialize activity system when page loads
        window.addEventListener('load', () => {
            startActivityUpdates();
            
            // Add refresh button event listener
            const refreshBtn = document.getElementById('refreshActivities');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', refreshActivities);
            }
            
            // Stop updates when page is hidden (performance optimization)
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    stopActivityUpdates();
                } else {
                    // Re-fetch immediately upon returning
                    startActivityUpdates(); 
                }
            });
        });

        // Clean up on page unload
        window.addEventListener('beforeunload', () => {
            stopActivityUpdates();
        });
        
        // Card animations on load (using simplified CSS animations)
        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.stat-card, .action-card, .quick-action-btn');
            cards.forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                card.style.animation = 'none'; // Disable initial CSS animation to use JS timing
                
                const delay = parseFloat(card.style.animationDelay) || 0;
                
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, delay * 1000);
            });
        });

    </script>
</body>
</html>