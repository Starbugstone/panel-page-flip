<?php
// Show all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check where logs are being written
echo "Error log path: " . ini_get('error_log') . "<br>";

// Output PHP info
phpinfo();
