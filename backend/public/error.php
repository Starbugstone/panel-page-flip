<?php
// Show all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Get server information
$server_info = [];
$server_info['SERVER_SOFTWARE'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$server_info['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown';
$server_info['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_FILENAME'] ?? 'Unknown';
$server_info['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? 'Unknown';
$server_info['PHP_VERSION'] = PHP_VERSION;

// Check for Symfony
$symfony_installed = file_exists(__DIR__ . '/../vendor/symfony/framework-bundle/FrameworkBundle.php');

// Check for React assets
$assets_dir = __DIR__ . '/assets';
$assets_exist = is_dir($assets_dir);
$assets_files = [];
if ($assets_exist) {
    $files = scandir($assets_dir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            $assets_files[] = $file;
        }
    }
}

// Check for .htaccess
$htaccess_exists = file_exists(__DIR__ . '/.htaccess');

// Check for index.html
$index_html_exists = file_exists(__DIR__ . '/index.html');

// Check for index.php
$index_php_exists = file_exists(__DIR__ . '/index.php');

// Check for FrontendController
$frontend_controller_exists = file_exists(__DIR__ . '/../src/Controller/FrontendController.php');

// Output as HTML
header('Content-Type: text/html');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostic Page - Comic Book Reader</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }
        h2 {
            color: #3498db;
            margin-top: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .card {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .success {
            color: green;
        }
        .error {
            color: red;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table, th, td {
            border: 1px solid #ddd;
        }
        th, td {
            padding: 10px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Comic Book Reader - Diagnostic Page</h1>
        
        <div class="card">
            <h2>Server Information</h2>
            <table>
                <tr>
                    <th>Key</th>
                    <th>Value</th>
                </tr>
                <?php foreach ($server_info as $key => $value): ?>
                <tr>
                    <td><?php echo htmlspecialchars($key); ?></td>
                    <td><?php echo htmlspecialchars($value); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        
        <div class="card">
            <h2>Symfony Check</h2>
            <p>
                Symfony installed: 
                <span class="<?php echo $symfony_installed ? 'success' : 'error'; ?>">
                    <?php echo $symfony_installed ? 'Yes' : 'No'; ?>
                </span>
            </p>
        </div>
        
        <div class="card">
            <h2>React Assets Check</h2>
            <p>
                Assets directory exists: 
                <span class="<?php echo $assets_exist ? 'success' : 'error'; ?>">
                    <?php echo $assets_exist ? 'Yes' : 'No'; ?>
                </span>
            </p>
            <?php if ($assets_exist && count($assets_files) > 0): ?>
            <h3>Asset Files:</h3>
            <ul>
                <?php foreach ($assets_files as $file): ?>
                <li><?php echo htmlspecialchars($file); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>Critical Files Check</h2>
            <table>
                <tr>
                    <th>File</th>
                    <th>Status</th>
                </tr>
                <tr>
                    <td>.htaccess</td>
                    <td class="<?php echo $htaccess_exists ? 'success' : 'error'; ?>">
                        <?php echo $htaccess_exists ? 'Exists' : 'Missing'; ?>
                    </td>
                </tr>
                <tr>
                    <td>index.html</td>
                    <td class="<?php echo $index_html_exists ? 'success' : 'error'; ?>">
                        <?php echo $index_html_exists ? 'Exists' : 'Missing'; ?>
                    </td>
                </tr>
                <tr>
                    <td>index.php</td>
                    <td class="<?php echo $index_php_exists ? 'success' : 'error'; ?>">
                        <?php echo $index_php_exists ? 'Exists' : 'Missing'; ?>
                    </td>
                </tr>
                <tr>
                    <td>FrontendController.php</td>
                    <td class="<?php echo $frontend_controller_exists ? 'success' : 'error'; ?>">
                        <?php echo $frontend_controller_exists ? 'Exists' : 'Missing'; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
