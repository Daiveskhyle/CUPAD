<!DOCTYPE html>
<html>
<head>
    <title>Fix Duplicate IDs</title>
    <style>body{font-family:Arial;padding:20px}button{padding:10px 20px;font-size:16px;cursor:pointer}pre{background:#f4f4f4;padding:10px;border-radius:5px}</style>
</head>
<body>
    <h2>Fix Duplicate IDs in JSON Files</h2>
    <button onclick="if(confirm('This will modify JSON files. Continue?')) location.href='?run=1'">Run Fix</button>
    <pre><?php
if (isset($_GET['run']) && $_GET['run'] === '1') {
    $dataDir = __DIR__ . '/data/';
    
    if (!is_dir($dataDir)) {
        echo "Error: Data directory not found.\n";
        return;
    }
    
    $jsonFiles = glob($dataDir . '*.json');
    
    if (empty($jsonFiles)) {
        echo "No JSON files found in data directory.\n";
        return;
    }

    foreach ($jsonFiles as $file) {
        $content = file_get_contents($file);
        if ($content === false) {
            echo "Error reading file: " . basename($file) . "\n";
            continue;
        }
        
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            echo "Error parsing JSON in: " . basename($file) . " - " . json_last_error_msg() . "\n";
            continue;
        }
        
        $ids = [];
        $modified = false;
        
        foreach ($data as &$item) {
            if (!isset($item['id'])) continue;
            
            if (in_array($item['id'], $ids)) {
                $item['id'] = uniqid('client_', true);
                $modified = true;
                echo "Fixed duplicate ID in " . basename($file) . ": " . $item['id'] . "\n";
            } else {
                $ids[] = $item['id'];
            }
        }
        
        if ($modified) {
            $result = file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($result === false) {
                echo "Error writing file: " . basename($file) . "\n";
            } else {
                echo "Updated: " . basename($file) . "\n\n";
            }
        }
    }
    echo "Done!\n";
}
    ?></pre>
</body>
</html>
