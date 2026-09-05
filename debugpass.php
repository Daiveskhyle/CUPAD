<?php
// passkey_manager.php - Register and test passkeys
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['webauthn_challenge'])) {
    $_SESSION['webauthn_challenge'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = getDbConnection();

// Get user info
$stmt = $pdo->prepare("SELECT id, username, name FROM users WHERE username = ?");
$stmt->execute([$_SESSION['username']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('User not found');
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'register') {
        $credId = $_POST['credentialId'] ?? '';
        
        if ($credId && $user['id']) {
            // Delete old passkeys
            $stmt = $pdo->prepare("DELETE FROM user_passkeys WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            
            // Insert new passkey
            $stmt = $pdo->prepare("INSERT INTO user_passkeys (user_id, credential_id, created_at) VALUES (?, ?, NOW())");
            if ($stmt->execute([$user['id'], $credId])) {
                echo json_encode(['success' => true, 'message' => 'Fingerprint registered!', 'cred_id' => $credId, 'length' => strlen($credId)]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid data']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'test') {
        $credId = $_POST['credentialId'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM user_passkeys WHERE user_id = ? AND credential_id = ?");
        $stmt->execute([$user['id'], $credId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => $match !== false,
            'message' => $match ? 'Match found!' : 'No match',
            'cred_id' => $credId,
            'length' => strlen($credId)
        ]);
        exit;
    }
}

// Get current passkeys
$stmt = $pdo->prepare("SELECT * FROM user_passkeys WHERE user_id = ?");
$stmt->execute([$user['id']]);
$passkeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Passkey Manager</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f3f4f6; }
        .card { background: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #1f2937; margin-top: 0; }
        h2 { color: #374151; font-size: 18px; margin-top: 0; }
        button { padding: 12px 24px; font-size: 14px; cursor: pointer; border: none; border-radius: 8px; font-weight: 600; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-success { background: #059669; color: white; }
        pre { background: #f3f4f6; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 12px; }
        .success { color: #059669; font-weight: 600; }
        .error { color: #dc2626; font-weight: 600; }
        .info { background: #dbeafe; color: #1e40af; padding: 12px; border-radius: 6px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f9fafb; font-weight: 600; }
    </style>
</head>
<body>
    <h1>🔐 Passkey Manager</h1>
    
    <div class="card">
        <h2>User: <?= htmlspecialchars($user['username']) ?> (ID: <?= $user['id'] ?>)</h2>
    </div>
    
    <div class="card">
        <h2>Current Passkeys (<?= count($passkeys) ?>)</h2>
        <?php if (count($passkeys) > 0): ?>
            <table>
                <tr><th>ID</th><th>Credential ID</th><th>Length</th><th>Created</th></tr>
                <?php foreach ($passkeys as $pk): ?>
                <tr>
                    <td><?= $pk['id'] ?></td>
                    <td style="font-family: monospace; font-size: 11px; word-break: break-all;"><?= htmlspecialchars($pk['credential_id']) ?></td>
                    <td><?= strlen($pk['credential_id']) ?></td>
                    <td><?= $pk['created_at'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No passkeys registered yet.</p>
        <?php endif; ?>
    </div>
    
    <div class="card">
        <h2>Step 1: Register Fingerprint</h2>
        <p>This will delete any existing passkeys and register a new one.</p>
        <button onclick="registerPasskey()" class="btn-primary">📝 Register New Fingerprint</button>
        <div id="registerResult"></div>
    </div>
    
    <div class="card">
        <h2>Step 2: Test Fingerprint</h2>
        <p>Test if your registered fingerprint can be found in the database.</p>
        <button onclick="testPasskey()" class="btn-success">🧪 Test Fingerprint</button>
        <div id="testResult"></div>
    </div>
    
    <script>
        const bufferToBase64 = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
        const base64ToBuffer = (base64) => {
            const padding = "=".repeat((4 - (base64.length % 4)) % 4);
            const base64Safe = (base64 + padding).replace(/-/g, "+").replace(/_/g, "/");
            const rawData = atob(base64Safe);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) { outputArray[i] = rawData.charCodeAt(i); }
            return outputArray.buffer;
        };
        
        const challengeStr = "<?= $_SESSION['webauthn_challenge'] ?>";
        const username = "<?= $user['username'] ?>";
        
        async function registerPasskey() {
            const resultDiv = document.getElementById('registerResult');
            resultDiv.innerHTML = '<p>Requesting fingerprint...</p>';
            
            try {
                const userIdBuffer = new TextEncoder().encode(username);
                
                const credential = await navigator.credentials.create({
                    publicKey: {
                        challenge: base64ToBuffer(challengeStr),
                        rp: { name: "CUPAD" },
                        user: { id: userIdBuffer, name: username, displayName: username },
                        pubKeyCredParams: [{ alg: -7, type: "public-key" }, { alg: -257, type: "public-key" }],
                        authenticatorSelection: { 
                            authenticatorAttachment: "platform", 
                            userVerification: "required", 
                            residentKey: "required", 
                            requireResidentKey: true 
                        },
                        timeout: 60000
                    }
                });
                
                const credId = bufferToBase64(credential.rawId);
                
                const formData = new FormData();
                formData.append('action', 'register');
                formData.append('credentialId', credId);
                
                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    resultDiv.innerHTML = '<p class="success">✓ ' + data.message + '</p>';
                    resultDiv.innerHTML += '<div class="info"><strong>Saved Credential ID:</strong><br><pre>' + data.cred_id + '</pre><strong>Length:</strong> ' + data.length + '</div>';
                    setTimeout(() => location.reload(), 2000);
                } else {
                    resultDiv.innerHTML = '<p class="error">✗ ' + data.message + '</p>';
                }
            } catch (e) {
                resultDiv.innerHTML = '<p class="error">✗ Error: ' + e.message + '</p>';
                console.error(e);
            }
        }
        
        async function testPasskey() {
            const resultDiv = document.getElementById('testResult');
            resultDiv.innerHTML = '<p>Requesting fingerprint...</p>';
            
            try {
                const credential = await navigator.credentials.get({
                    publicKey: {
                        challenge: base64ToBuffer(challengeStr),
                        userVerification: "preferred"
                    }
                });
                
                const credId = bufferToBase64(credential.rawId);
                
                const formData = new FormData();
                formData.append('action', 'test');
                formData.append('credentialId', credId);
                
                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    resultDiv.innerHTML = '<p class="success">✓ ' + data.message + '</p>';
                    resultDiv.innerHTML += '<div class="info"><strong>Tested Credential ID:</strong><br><pre>' + data.cred_id + '</pre><strong>Length:</strong> ' + data.length + '</div>';
                    resultDiv.innerHTML += '<p class="success">Your fingerprint login will work! Try it on the login page.</p>';
                } else {
                    resultDiv.innerHTML = '<p class="error">✗ ' + data.message + '</p>';
                    resultDiv.innerHTML += '<div class="info"><strong>Tested Credential ID:</strong><br><pre>' + data.cred_id + '</pre><strong>Length:</strong> ' + data.length + '</div>';
                    resultDiv.innerHTML += '<p class="error">The credential ID from your fingerprint does not match what\'s in the database. Try registering again.</p>';
                }
            } catch (e) {
                resultDiv.innerHTML = '<p class="error">✗ Error: ' + e.message + '</p>';
                console.error(e);
            }
        }
    </script>
    
    <hr style="margin: 30px 0;">
    <p><a href="index.php">← Back to Login</a> | <a href="logout.php">Logout</a></p>
</body>
</html>
