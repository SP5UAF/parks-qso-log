<?php
// Temporary diagnostic file - DELETE after fixing!
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>PHP Diagnostics</h2>";

// PHP Version
echo "<p>PHP Version: " . PHP_VERSION . "</p>";

// Test config
echo "<h3>Testing config.php...</h3>";
require_once 'config.php';
echo "<p>Config loaded OK</p>";
echo "<p>DB_HOST: " . DB_HOST . "</p>";
echo "<p>DB_NAME: " . DB_NAME . "</p>";
echo "<p>DB_USER: " . DB_USER . "</p>";

// Test DB connection
echo "<h3>Testing Database Connection...</h3>";
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    echo "<p style='color:red'>DB Connection FAILED: " . $db->connect_error . "</p>";
} else {
    echo "<p style='color:green'>DB Connection OK!</p>";
}

// Test if table exists
echo "<h3>Testing Table...</h3>";
$result = $db->query("SHOW TABLES LIKE 'qso_log'");
if ($result->num_rows > 0) {
    echo "<p style='color:green'>Table qso_log EXISTS</p>";
} else {
    echo "<p style='color:red'>Table qso_log does NOT exist - run the SQL first!</p>";
}

// Test uploads directory
echo "<h3>Testing Uploads Directory...</h3>";
if (!is_dir('uploads/')) {
    if (mkdir('uploads/', 0755, true)) {
        echo "<p style='color:green'>Uploads directory CREATED</p>";
    } else {
        echo "<p style='color:red'>CANNOT create uploads directory - check permissions!</p>";
    }
} else {
    echo "<p style='color:green'>Uploads directory EXISTS</p>";
    if (is_writable('uploads/')) {
        echo "<p style='color:green'>Uploads directory is WRITABLE</p>";
    } else {
        echo "<p style='color:red'>Uploads directory is NOT writable - check permissions!</p>";
    }
}

// Test PHP extensions
echo "<h3>Testing PHP Extensions...</h3>";
$extensions = ['mysqli', 'fileinfo', 'mbstring'];
foreach ($extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<p style='color:green'>Extension '$ext': LOADED</p>";
    } else {
        echo "<p style='color:red'>Extension '$ext': MISSING</p>";
    }
}

// Test upload settings
echo "<h3>PHP Upload Settings...</h3>";
echo "<p>upload_max_filesize: " . ini_get('upload_max_filesize') . "</p>";
echo "<p>post_max_size: " . ini_get('post_max_size') . "</p>";
echo "<p>max_execution_time: " . ini_get('max_execution_time') . "</p>";
echo "<p>memory_limit: " . ini_get('memory_limit') . "</p>";
?>
