<?php
/**
 * CORS Proxy for CUPAD API
 *
 * Upload this file to your cupad.name.ng server root directory.
 * Then update the app's API_BASE_URL to point to this proxy.
 *
 * Example: If you upload to https://cupad.name.ng/api-proxy.php
 * Then set API_BASE_URL = 'https://cupad.name.ng'
 * The app will use this proxy file for all API calls.
 */

// Enable CORS for all origins (change '*' to specific domain for production)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get the endpoint from query parameter
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';

// Build the target URL
$baseUrl = 'https://cupad.name.ng';
$targetUrl = $baseUrl . '/' . ltrim($endpoint, '/');

// Initialize cURL
$ch = curl_init();

// Determine request method
$method = $_SERVER['REQUEST_METHOD'];

// Handle POST/PUT data
if ($method === 'POST' || $method === 'PUT') {
    $input = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $input);

    // Set content type based on input
    $contentType = 'application/json';
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $contentType = $_SERVER['CONTENT_TYPE'];
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: ' . $contentType,
        'Accept: application/json'
    ]);
}

// Configure cURL options
curl_setopt_array($ch, [
    CURLOPT_URL => $targetUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CUSTOMREQUEST => $method,
]);

// Execute request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Return response
if ($error) {
    http_response_code(500);
    echo json_encode(['error' => 'CURL Error: ' . $error]);
} else {
    http_response_code($httpCode);
    echo $response;
}
?>