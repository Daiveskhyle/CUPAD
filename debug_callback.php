<?php
require_once 'session_config.php';
echo "GET state: " . ($_GET['state'] ?? 'NOT SET') . "<br>";
echo "SESSION state: " . ($_SESSION['google_oauth_state'] ?? 'NOT SET') . "<br>";
echo "COOKIE state: " . ($_COOKIE['google_oauth_state'] ?? 'NOT SET') . "<br>";
echo "Session ID: " . session_id() . "<br>";
echo "<pre>";
print_r($_GET);
print_r($_SESSION);
print_r($_COOKIE);
echo "</pre>";
?>
