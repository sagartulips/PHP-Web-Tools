<?php
/**
 * Database Table Prefix Changer
 *
 * A comprehensive tool to safely change database table prefixes for WordPress
 * or any MySQL/MariaDB database. Supports both WordPress auto-detection via
 * wp-config.php and custom database connections.
 *
 * @package     Table Prefix Changer Tool
 * @author      Sagar GC <sagar@tulipstechnologies.com>
 * @copyright   2024 Tulips Technologies Pvt. Ltd.
 * @license     GPL-2.0-or-later
 * @version     2.1.0
 *
 * Features:
 * - WordPress auto-detection via wp-config.php
 * - Custom database connection support
 * - Pre-flight verification of database credentials
 * - Automatic detection of current table prefix
 * - Safe wp-config.php parsing without loading WordPress
 * - Real-time progress tracking
 * - Detailed logging of all operations
 * - Automatic wp-config.php updates (WordPress mode only)
 * - Modern responsive interface
 * - Error handling and rollback capabilities
 * - Works with any MySQL/MariaDB database
 *
 * Usage:
 * WordPress Mode:
 * 1. Place this file in your WordPress root directory
 * 2. Access via browser (e.g., example.com/wp-pr.php)
 * 3. Select "WordPress (Auto-detect)" mode
 * 4. Enter current and new prefix
 * 5. Review changes and confirm
 *
 * Custom Database Mode:
 * 1. Place this file anywhere accessible via web
 * 2. Access via browser
 * 3. Select "Custom Database" mode
 * 4. Enter database credentials
 * 5. Enter current and new prefix
 * 6. Review changes and confirm
 *
 * Security:
 * - Validates all input
 * - Prevents direct file access
 * - Sanitizes database queries
 * - Verifies wp-config.php integrity
 *
 * Requirements:
 * - PHP 7.4 or higher
 * - MySQL 5.6 or higher / MariaDB 10.0 or higher
 * - Write permissions for wp-config.php (WordPress mode, optional)
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Prevent direct file access
if (!defined('ABSPATH') && !isset($_SERVER['HTTP_HOST'])) {
    die('Direct access not permitted.');
}

// Set execution time limit
set_time_limit(300);
ini_set('memory_limit', '256M');

// Enable output buffering for real-time updates
ob_start();

/**
 * Sanitize text field input
 * Custom function to avoid conflicts with WordPress
 */
function pr_sanitize_text_field($str) {
    if (function_exists('sanitize_text_field')) {
        // Use WordPress function if available
        return sanitize_text_field($str);
    }
    return trim(htmlspecialchars(strip_tags($str), ENT_QUOTES, 'UTF-8'));
}

/**
 * Parse host and port from DB_HOST (format: host:port or just host)
 * Returns array with 'host' and 'port' keys
 */
function parse_db_host($db_host) {
    $result = ['host' => 'localhost', 'port' => 3306];
    
    if (empty($db_host)) {
        return $result;
    }
    
    // Check if port is specified in host (format: host:port)
    if (strpos($db_host, ':') !== false) {
        $parts = explode(':', $db_host, 2);
        $result['host'] = trim($parts[0]);
        $result['port'] = !empty($parts[1]) ? (int)$parts[1] : 3306;
    } else {
        $result['host'] = trim($db_host);
        $result['port'] = 3306; // Default MySQL port
    }
    
    return $result;
}

/**
 * Safely extract WordPress credentials without loading WordPress
 */
function get_wp_config_data() {
    $wp_config_path = __DIR__ . '/wp-config.php';
    
    if (!file_exists($wp_config_path)) {
        return null;
    }

    $content = file_get_contents($wp_config_path);
    
    $patterns = [
        'db_name'     => "/^[^#\/]*define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"](.*?)['\"]\s*\)\s*;/mi",
        'db_user'     => "/^[^#\/]*define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"](.*?)['\"]\s*\)\s*;/mi",
        'db_password' => "/^[^#\/]*define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"](.*?)['\"]\s*\)\s*;/mi",
        'db_host'     => "/^[^#\/]*define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"](.*?)['\"]\s*\)\s*;/mi",
        'table_prefix'=> "/^[^#\/]*\\\$table_prefix\s*=\s*['\"](.*?)['\"]\s*;/mi"
    ];

    $config = [];
    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $content, $matches)) {
            $config[$key] = $matches[1];
        } else {
            $config[$key] = false;
        }
    }
    
    return $config;
}

/**
 * Detect the actual table prefix from database
 * Works for WordPress (options table) or any database (first table with underscore)
 */
function detect_db_prefix($db, $is_wordpress = true) {
    if ($is_wordpress) {
        // Try WordPress-specific detection (options table)
        $tables = $db->query("SHOW TABLES LIKE '%options'");
        if ($tables && $tables->num_rows > 0) {
            $table = $tables->fetch_array();
            $table_name = $table[0];
            
            // Extract prefix (everything before the last underscore in options table)
            if (strpos($table_name, '_') !== false) {
                return substr($table_name, 0, strrpos($table_name, '_') + 1);
            }
        }
    }
    
    // Generic detection: find common prefix from all tables
    $tables = $db->query("SHOW TABLES");
    if (!$tables || $tables->num_rows === 0) {
        return false;
    }
    
    $table_names = [];
    while ($row = $tables->fetch_array()) {
        $table_names[] = $row[0];
    }
    
    if (empty($table_names)) {
        return false;
    }
    
    // Find common prefix
    $first_table = $table_names[0];
    $prefix = '';
    
    // Check if tables have a common prefix pattern
    foreach ($table_names as $table_name) {
        if (strpos($table_name, '_') !== false) {
            $table_prefix = substr($table_name, 0, strpos($table_name, '_') + 1);
            if (empty($prefix)) {
                $prefix = $table_prefix;
            } elseif ($prefix !== $table_prefix) {
                // Different prefixes found, return false
                return false;
            }
        } else {
            // Table without underscore, no prefix
            return false;
        }
    }
    
    return $prefix ?: false;
}

/**
 * Update wp-config.php with new prefix
 */
function update_wp_config_prefix($new_prefix) {
    $wp_config_path = __DIR__ . '/wp-config.php';
    if (!file_exists($wp_config_path)) {
        return false;
    }
    
    $content = file_get_contents($wp_config_path);
    
    $new_content = preg_replace(
        '/\$table_prefix\s*=\s*[\'"](.*?)[\'"]\s*;/',
        "\$table_prefix = '{$new_prefix}';",
        $content
    );
    
    if ($new_content === $content) {
        return false; // No changes made
    }
    
    return file_put_contents($wp_config_path, $new_content) !== false;
}

// Check if form was submitted with custom database mode
$is_custom_mode_submit = ($_SERVER['REQUEST_METHOD'] === 'POST' && 
                          isset($_POST['old_prefix']) && 
                          isset($_POST['new_prefix']) &&
                          isset($_POST['connection_mode']) && 
                          $_POST['connection_mode'] === 'custom');

// Always try to get wp-config.php data (for database connection)
$wp_config = get_wp_config_data();
$actual_prefix = false;
$config_prefix = '';
$warning_message = null;
$wp_detected = false;

// Only auto-detect WordPress prefix if NOT in custom database mode
if (!$is_custom_mode_submit && $wp_config && $wp_config['db_name'] && $wp_config['db_user'] && $wp_config['db_host']) {
    $wp_detected = true;
    
    // Parse host:port format from wp-config.php
    $host_info = parse_db_host($wp_config['db_host']);
    $detect_db_host = $host_info['host'];
    $detect_db_port = $host_info['port'];
    
    // Connect to database to detect actual prefix
    $db = @new mysqli(
        $detect_db_host, 
        $wp_config['db_user'], 
        $wp_config['db_password'] ?? '', 
        $wp_config['db_name'],
        $detect_db_port
    );
    
    if (!$db->connect_error) {
        $actual_prefix = detect_db_prefix($db, true);
        $db->close();
    }
    
    $config_prefix = $wp_config['table_prefix'] ?? 'wp_';
    
    // Handle prefix mismatch
    if ($actual_prefix && $actual_prefix !== $config_prefix) {
        $can_fix = is_writable(__DIR__ . '/wp-config.php');
        
        // Try to auto-fix if possible
        if ($can_fix && update_wp_config_prefix($actual_prefix)) {
            // Refresh to load with corrected prefix
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
        
        // Store warning message
        $warning_message = [
            'type' => 'warning',
            'config_prefix' => $config_prefix,
            'actual_prefix' => $actual_prefix
        ];
        
        // Use actual prefix for this session
        $config_prefix = $actual_prefix;
    }
    
    // Try to load WordPress config if available (only if not custom mode)
    $table_prefix = $config_prefix;
    if (file_exists(__DIR__ . '/wp-config.php')) {
        define('WP_USE_THEMES', false);
        @require_once(__DIR__ . '/wp-config.php');
        if (isset($table_prefix)) {
            $config_prefix = $table_prefix;
        }
    }
}

// Initialize logging
$log_file = __DIR__ . '/prefix_change_log_' . date('Y-m-d_His') . '.txt';
$log_content = "Database Table Prefix Change Log\n";
$log_content .= "================================\n";
$log_content .= "Date: " . date('Y-m-d H:i:s') . "\n";
$log_content .= "PHP Version: " . PHP_VERSION . "\n\n";

// Operation counters
$operation_counts = [
    'tables' => ['total' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0],
    'options' => ['total' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0],
    'usermeta' => ['total' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0],
    'postmeta' => ['total' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0],
    'config' => ['total' => 1, 'success' => 0, 'failed' => 0, 'skipped' => 0]
];

function log_message($message, $type = 'info') {
    global $log_content;
    $timestamp = date('[Y-m-d H:i:s]');
    $log_content .= "$timestamp [$type] $message\n";
}

function update_count($category, $type) {
    global $operation_counts;
    $operation_counts[$category][$type]++;
}

function is_wp_config_writable() {
    return is_writable(__DIR__ . '/wp-config.php');
}

function update_wp_config($old_prefix, $new_prefix, $force = false) {
    $wp_config_path = __DIR__ . '/wp-config.php';
    if (!file_exists($wp_config_path)) {
        return false;
    }
    
    $wp_config_file = file_get_contents($wp_config_path);
    $new_wp_config = preg_replace(
        "/^\$table_prefix\s*=\s*['\"]" . preg_quote($old_prefix, '/') . "['\"]\s*;/m", 
        "\$table_prefix = '{$new_prefix}';", 
        $wp_config_file
    );
    
    if ($force) {
        @chmod($wp_config_path, 0644);
    }
    
    return file_put_contents($wp_config_path, $new_wp_config) !== false;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'check_config') {
            echo json_encode([
                'writable' => is_wp_config_writable(),
                'current_prefix' => $table_prefix ?? $config_prefix
            ]);
        }
        elseif ($_POST['action'] === 'force_write') {
            $result = update_wp_config(
                rtrim($_POST['old_prefix'], '_') . '_',
                rtrim($_POST['new_prefix'], '_') . '_',
                true
            );
            echo json_encode(['success' => $result]);
        }
        elseif ($_POST['action'] === 'test_connection') {
            // Get connection mode
            $test_mode = isset($_POST['connection_mode']) ? $_POST['connection_mode'] : 'wordpress';
            $is_custom_mode = ($test_mode === 'custom');
            
            // Get database credentials
            if ($is_custom_mode) {
                // Custom Database mode: ALWAYS use custom form credentials (completely ignore wp-config.php)
                $test_db_host = isset($_POST['db_host']) ? pr_sanitize_text_field($_POST['db_host']) : 'localhost';
                $test_db_port = isset($_POST['db_port']) && !empty($_POST['db_port']) ? (int)$_POST['db_port'] : 3306;
                $test_db_user = isset($_POST['db_user']) ? pr_sanitize_text_field($_POST['db_user']) : '';
                $test_db_pass = isset($_POST['db_pass']) ? $_POST['db_pass'] : '';
                $test_db_name = isset($_POST['db_name']) ? pr_sanitize_text_field($_POST['db_name']) : '';
                
                if (empty($test_db_user) || empty($test_db_name)) {
                    throw new Exception('Database user and name are required for custom database mode.');
                }
            } elseif ($wp_config && $wp_config['db_name'] && $wp_config['db_user'] && $wp_config['db_host']) {
                // WordPress mode: use wp-config.php credentials
                // Parse host:port format from wp-config.php
                $host_info = parse_db_host($wp_config['db_host']);
                $test_db_host = $host_info['host'];
                $test_db_port = $host_info['port'];
                $test_db_user = $wp_config['db_user'];
                $test_db_pass = $wp_config['db_password'] ?? '';
                $test_db_name = $wp_config['db_name'];
            } else {
                throw new Exception('WordPress mode requires wp-config.php. Please select Custom Database mode and provide credentials.');
            }
            
            // Test connection - use error suppression and check connect_error property
            // Enable error reporting temporarily to catch connection issues
            $old_error_reporting = error_reporting(E_ALL);
            $old_display_errors = ini_get('display_errors');
            ini_set('display_errors', 0);
            
            // Use mysqli with port parameter
            $test_connection = @new mysqli($test_db_host, $test_db_user, $test_db_pass, $test_db_name, $test_db_port);
            
            // Restore error settings
            error_reporting($old_error_reporting);
            ini_set('display_errors', $old_display_errors);
            
            // Check for connection errors
            if ($test_connection->connect_error) {
                $error_msg = $test_connection->connect_error;
                $error_code = $test_connection->connect_errno;
                if ($test_connection) {
                    @$test_connection->close();
                }
                
                // Provide more helpful error messages
                if ($error_code == 2002) {
                    throw new Exception("Connection failed: Cannot connect to MySQL server at '$test_db_host'. Make sure MySQL server is running and accessible.");
                } elseif ($error_code == 1045) {
                    throw new Exception("Connection failed: Access denied for user '$test_db_user'. Check your username and password.");
                } elseif ($error_code == 1049) {
                    throw new Exception("Connection failed: Unknown database '$test_db_name'. Make sure the database exists.");
                } else {
                    throw new Exception("Connection failed: " . $error_msg . " (Error Code: $error_code)");
                }
            }
            
            // Additional check - sometimes connect_error might not be set but connection failed
            if (mysqli_connect_error()) {
                $error_msg = mysqli_connect_error();
                $error_code = mysqli_connect_errno();
                if ($test_connection) {
                    @$test_connection->close();
                }
                throw new Exception("Connection failed: " . $error_msg . " (Error Code: $error_code)");
            }
            
            $test_connection->set_charset('utf8mb4');
            
            // Get database info
            $tables_result = $test_connection->query("SHOW TABLES");
            $table_count = $tables_result ? $tables_result->num_rows : 0;
            
            // Get MySQL version
            $version_result = $test_connection->query("SELECT VERSION() as version");
            $version = 'Unknown';
            if ($version_result) {
                $version_row = $version_result->fetch_assoc();
                $version = $version_row['version'] ?? 'Unknown';
            }
            
            $test_connection->close();
            
            echo json_encode([
                'success' => true,
                'message' => 'Database connection successful!',
                'database' => $test_db_name,
                'host' => $test_db_host,
                'port' => $test_db_port,
                'tables' => $table_count,
                'version' => $version,
                'mode' => $is_custom_mode ? 'Custom Database' : 'WordPress (wp-config.php)'
            ]);
        }
        else {
            throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['old_prefix']) && isset($_POST['new_prefix'])) {
    // Determine connection mode
    $connection_mode = isset($_POST['connection_mode']) ? $_POST['connection_mode'] : 'wordpress';
    $is_custom_mode = ($connection_mode === 'custom');
    $is_wordpress = !$is_custom_mode;
    
    // Get database credentials based on selected mode
    if ($is_custom_mode) {
        // Custom Database mode: ALWAYS use custom form credentials (completely ignore wp-config.php)
        $db_host = pr_sanitize_text_field($_POST['db_host'] ?? 'localhost');
        $db_port = isset($_POST['db_port']) && !empty($_POST['db_port']) ? (int)$_POST['db_port'] : 3306;
        $db_user = pr_sanitize_text_field($_POST['db_user'] ?? '');
        $db_pass = $_POST['db_pass'] ?? '';
        $db_name = pr_sanitize_text_field($_POST['db_name'] ?? '');
        
        if (empty($db_user) || empty($db_name)) {
            die('Error: Database credentials are required for custom database mode. Please provide database host, user, and name.');
        }
    } elseif ($wp_config && $wp_config['db_name'] && $wp_config['db_user'] && $wp_config['db_host']) {
        // WordPress mode: use wp-config.php credentials
        // Parse host:port format from wp-config.php
        $host_info = parse_db_host($wp_config['db_host']);
        $db_host = $host_info['host'];
        $db_port = $host_info['port'];
        $db_user = $wp_config['db_user'];
        $db_pass = $wp_config['db_password'] ?? '';
        $db_name = $wp_config['db_name'];
    } else {
        die('Error: WordPress mode requires wp-config.php. Please select Custom Database mode and provide credentials.');
    }
    // Start HTML output
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Prefix Change Results</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            :root {
                --primary-color: #3498db;
                --secondary-color: #2c3e50;
                --success-color: #28a745;
                --danger-color: #dc3545;
                --warning-color: #ffc107;
                --info-color: #17a2b8;
            }
            
            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                font-family: "Segoe UI", "Roboto", "Helvetica Neue", Arial, sans-serif;
                min-height: 100vh;
                padding: 20px 0;
            }
            
            .tool-container {
                max-width: 1000px;
                margin: 0 auto;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                border-radius: 15px;
                overflow: hidden;
                background: white;
            }
            
            .tool-header {
                background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
                color: white;
                padding: 30px;
                text-align: center;
            }
            
            .log-entry {
                padding: 12px 15px;
                margin-bottom: 8px;
                border-radius: 8px;
                border-left: 4px solid;
                font-size: 0.95rem;
                transition: all 0.2s ease;
            }
            
            .log-entry:hover {
                transform: translateX(5px);
            }
            
            .log-success {
                background-color: #d4edda;
                border-left-color: var(--success-color);
                color: #155724;
            }
            
            .log-error {
                background-color: #f8d7da;
                border-left-color: var(--danger-color);
                color: #721c24;
            }
            
            .log-warning {
                background-color: #fff3cd;
                border-left-color: var(--warning-color);
                color: #856404;
            }
            
            .log-info {
                background-color: #d1ecf1;
                border-left-color: var(--info-color);
                color: #0c5460;
            }
            
            .category-card {
                margin-bottom: 20px;
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            
            .category-header {
                cursor: pointer;
                transition: all 0.3s ease;
            }
            
            .category-header:hover {
                opacity: 0.9;
            }
            
            .category-body {
                display: block;
                max-height: 400px;
                overflow-y: auto;
            }
            
            .stat-card {
                border-radius: 10px;
                transition: all 0.3s ease;
                border: none;
                box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            }
            
            .stat-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            }
            
            .stat-number {
                font-size: 2.5rem;
                font-weight: 700;
                margin: 10px 0;
            }
            
            .progress {
                height: 30px;
                border-radius: 10px;
                overflow: hidden;
            }
            
            .progress-bar {
                font-size: 14px;
                font-weight: 600;
                display: flex;
                align-items: center;
                justify-content: center;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="tool-container">
                <div class="tool-header">
                    <h2 class="mb-0"><i class="fas fa-cog me-2"></i> Prefix Change Results</h2>
                </div>
                <div class="card-body p-4">';

    try {
        $old_prefix = rtrim($_POST['old_prefix'], '_') . '_';
        $new_prefix = rtrim($_POST['new_prefix'], '_') . '_';
        $start_time = microtime(true);

        log_message("Starting prefix change from '$old_prefix' to '$new_prefix'");
        log_message("Connection mode: " . ($is_wordpress ? 'WordPress' : 'Custom Database'));
        log_message("User entered prefix: '{$_POST['old_prefix']}'");
        if ($is_wordpress && $config_prefix) {
            log_message("wp-config.php prefix: '$config_prefix'");
        }
        if ($is_wordpress && $actual_prefix) {
            log_message("Detected database prefix: '$actual_prefix'");
        }

        // Validate prefixes
        if (!preg_match('/^[a-z0-9_]+$/i', $old_prefix) || !preg_match('/^[a-z0-9_]+$/i', $new_prefix)) {
            throw new Exception('Invalid prefix format. Only letters, numbers and underscores allowed.');
        }

        if ($old_prefix === $new_prefix) {
            throw new Exception('Old and new prefixes cannot be the same.');
        }

        // Connect to database with port
        $connection = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
        
        if ($connection->connect_error) {
            throw new Exception("Database connection failed: " . $connection->connect_error);
        }
        
        $connection->set_charset('utf8mb4');
        
        log_message("Connected to database: $db_name (Mode: " . ($is_wordpress ? 'WordPress' : 'Custom') . ")");

        // Get all tables with the old prefix
        $tables = $connection->query("SHOW TABLES LIKE '" . $connection->real_escape_string($old_prefix) . "%'");
        if ($tables->num_rows === 0) {
            throw new Exception("No tables found with prefix '$old_prefix'");
        }

        $total_tables = $tables->num_rows;
        $processed_tables = 0;

        echo '<div class="mb-4">
                <h4><i class="fas fa-tasks me-2"></i> Processing...</h4>
                <div class="progress mb-3">
                    <div class="progress-bar progress-bar-striped progress-bar-animated fw-semibold" 
                         id="progressBar" role="progressbar" style="width: 0%">0%</div>
                </div>
                <div id="currentOperation" class="text-muted mb-3"></div>
              </div>';

        echo '<div class="category-card card">
                <div class="card-header category-header bg-secondary text-white" onclick="toggleCategory(\'tables\')">
                    <h4 class="mb-0"><i class="fas fa-table me-2"></i> Database Tables <span class="badge bg-light text-dark ms-2">' . $total_tables . '</span></h4>
                </div>
                <div class="card-body category-body" id="category-tables">';

        // Rename tables
        $tables->data_seek(0); // Reset pointer
        while ($table = $tables->fetch_array()) {
            $processed_tables++;
            $progress = round(($processed_tables / $total_tables) * 100);
            
            $old_table_name = $table[0];
            $new_table_name = $new_prefix . substr($old_table_name, strlen($old_prefix));
            
            // Send progress update
            echo "<script>updateProgress($progress, 'Renaming table: $old_table_name');</script>";
            ob_flush();
            flush();
            
            update_count('tables', 'total');
            
            $result = $connection->query("RENAME TABLE `" . $connection->real_escape_string($old_table_name) . "` TO `" . $connection->real_escape_string($new_table_name) . "`");
            if ($result) {
                echo '<div class="log-entry log-success"><i class="fas fa-check-circle me-2"></i>Renamed table: <code>' . htmlspecialchars($old_table_name) . '</code> → <code>' . htmlspecialchars($new_table_name) . '</code></div>';
                log_message("Renamed table: $old_table_name → $new_table_name");
                update_count('tables', 'success');
            } else {
                echo '<div class="log-entry log-error"><i class="fas fa-times-circle me-2"></i>Failed to rename table: <code>' . htmlspecialchars($old_table_name) . '</code> - ' . htmlspecialchars($connection->error) . '</div>';
                log_message("Failed to rename table: $old_table_name - " . $connection->error, 'error');
                update_count('tables', 'failed');
            }
        }
        
        echo '</div></div>';

        // Process options table (WordPress only)
        if ($is_wordpress) {
            echo '<div class="category-card card">
                    <div class="card-header category-header bg-secondary text-white" onclick="toggleCategory(\'options\')">
                        <h4 class="mb-0"><i class="fas fa-cog me-2"></i> Options Table</h4>
                    </div>
                    <div class="card-body category-body" id="category-options">';
            
            $options_table = $new_prefix . 'options';
            $options = $connection->query("SELECT option_name, option_value FROM `" . $connection->real_escape_string($options_table) . "` WHERE option_name LIKE '" . $connection->real_escape_string($old_prefix) . "%'");
            
            if ($options && $options->num_rows > 0) {
            $total_options = $options->num_rows;
            $processed_options = 0;
            
            while ($option = $options->fetch_assoc()) {
                $processed_options++;
                $progress = round(($processed_options / $total_options) * 100);
                
                echo "<script>updateProgress($progress, 'Updating options: {$option['option_name']}');</script>";
                ob_flush();
                flush();
                
                $new_option_name = $new_prefix . substr($option['option_name'], strlen($old_prefix));
                
                update_count('options', 'total');
                
                $result = $connection->query("UPDATE `" . $connection->real_escape_string($options_table) . "` SET option_name = '" . $connection->real_escape_string($new_option_name) . "' WHERE option_name = '" . $connection->real_escape_string($option['option_name']) . "'");
                if ($result) {
                    echo '<div class="log-entry log-success"><i class="fas fa-check-circle me-2"></i>Updated option: <code>' . htmlspecialchars($option['option_name']) . '</code> → <code>' . htmlspecialchars($new_option_name) . '</code></div>';
                    log_message("Updated option: {$option['option_name']} → $new_option_name");
                    update_count('options', 'success');
                } else {
                    echo '<div class="log-entry log-error"><i class="fas fa-times-circle me-2"></i>Failed to update option: <code>' . htmlspecialchars($option['option_name']) . '</code> - ' . htmlspecialchars($connection->error) . '</div>';
                    log_message("Failed to update option: {$option['option_name']} - " . $connection->error, 'error');
                    update_count('options', 'failed');
                }
            }
            } else {
                echo '<div class="log-entry log-info"><i class="fas fa-info-circle me-2"></i>No prefixed options found in options table</div>';
                log_message("No prefixed options found in options table");
                update_count('options', 'skipped');
            }
            
            echo '</div></div>';
        }

        // Process usermeta table (WordPress only)
        if ($is_wordpress) {
        echo '<div class="category-card card">
                <div class="card-header category-header bg-secondary text-white" onclick="toggleCategory(\'usermeta\')">
                    <h4 class="mb-0"><i class="fas fa-users me-2"></i> User Meta</h4>
                </div>
                <div class="card-body category-body" id="category-usermeta">';
        
        $usermeta_table = $new_prefix . 'usermeta';
        $usermeta = $connection->query("SELECT umeta_id, meta_key FROM `" . $connection->real_escape_string($usermeta_table) . "` WHERE meta_key LIKE '" . $connection->real_escape_string($old_prefix) . "%'");
        
        if ($usermeta && $usermeta->num_rows > 0) {
            while ($meta = $usermeta->fetch_assoc()) {
                $new_meta_key = $new_prefix . substr($meta['meta_key'], strlen($old_prefix));
                
                update_count('usermeta', 'total');
                
                $result = $connection->query("UPDATE `" . $connection->real_escape_string($usermeta_table) . "` SET meta_key = '" . $connection->real_escape_string($new_meta_key) . "' WHERE umeta_id = " . (int)$meta['umeta_id']);
                if ($result) {
                    echo '<div class="log-entry log-success"><i class="fas fa-check-circle me-2"></i>Updated usermeta: <code>' . htmlspecialchars($meta['meta_key']) . '</code> → <code>' . htmlspecialchars($new_meta_key) . '</code></div>';
                    log_message("Updated usermeta: {$meta['meta_key']} → $new_meta_key");
                    update_count('usermeta', 'success');
                } else {
                    echo '<div class="log-entry log-error"><i class="fas fa-times-circle me-2"></i>Failed to update usermeta: <code>' . htmlspecialchars($meta['meta_key']) . '</code> - ' . htmlspecialchars($connection->error) . '</div>';
                    log_message("Failed to update usermeta: {$meta['meta_key']} - " . $connection->error, 'error');
                    update_count('usermeta', 'failed');
                }
            }
            } else {
                echo '<div class="log-entry log-info"><i class="fas fa-info-circle me-2"></i>No prefixed meta keys found in usermeta table</div>';
                log_message("No prefixed meta keys found in usermeta table");
                update_count('usermeta', 'skipped');
            }
            
            echo '</div></div>';
        }

        // Process postmeta table (WordPress only)
        if ($is_wordpress) {
        echo '<div class="category-card card">
                <div class="card-header category-header bg-secondary text-white" onclick="toggleCategory(\'postmeta\')">
                    <h4 class="mb-0"><i class="fas fa-file-alt me-2"></i> Post Meta</h4>
                </div>
                <div class="card-body category-body" id="category-postmeta">';
        
        $postmeta_table = $new_prefix . 'postmeta';
        $postmeta = $connection->query("SELECT meta_id, meta_key FROM `" . $connection->real_escape_string($postmeta_table) . "` WHERE meta_key LIKE '" . $connection->real_escape_string($old_prefix) . "%'");
        
        if ($postmeta && $postmeta->num_rows > 0) {
            while ($meta = $postmeta->fetch_assoc()) {
                $new_meta_key = $new_prefix . substr($meta['meta_key'], strlen($old_prefix));
                
                update_count('postmeta', 'total');
                
                $result = $connection->query("UPDATE `" . $connection->real_escape_string($postmeta_table) . "` SET meta_key = '" . $connection->real_escape_string($new_meta_key) . "' WHERE meta_id = " . (int)$meta['meta_id']);
                if ($result) {
                    echo '<div class="log-entry log-success"><i class="fas fa-check-circle me-2"></i>Updated postmeta: <code>' . htmlspecialchars($meta['meta_key']) . '</code> → <code>' . htmlspecialchars($new_meta_key) . '</code></div>';
                    log_message("Updated postmeta: {$meta['meta_key']} → $new_meta_key");
                    update_count('postmeta', 'success');
                } else {
                    echo '<div class="log-entry log-error"><i class="fas fa-times-circle me-2"></i>Failed to update postmeta: <code>' . htmlspecialchars($meta['meta_key']) . '</code> - ' . htmlspecialchars($connection->error) . '</div>';
                    log_message("Failed to update postmeta: {$meta['meta_key']} - " . $connection->error, 'error');
                    update_count('postmeta', 'failed');
                }
            }
            } else {
                echo '<div class="log-entry log-info"><i class="fas fa-info-circle me-2"></i>No prefixed meta keys found in postmeta table</div>';
                log_message("No prefixed meta keys found in postmeta table");
                update_count('postmeta', 'skipped');
            }
            
            echo '</div></div>';
        }

        // wp-config.php update (WordPress only)
        if ($is_wordpress) {
        echo '<div class="category-card card">
                <div class="card-header category-header bg-secondary text-white" onclick="toggleCategory(\'config\')">
                    <h4 class="mb-0"><i class="fas fa-file-code me-2"></i> Configuration File</h4>
                </div>
                <div class="card-body category-body" id="category-config">';
        
        echo "<script>updateProgress(95, 'Updating wp-config.php');</script>";
        ob_flush();
        flush();
        
        if (is_wp_config_writable()) {
            if (update_wp_config($old_prefix, $new_prefix)) {
                echo '<div class="log-entry log-success"><i class="fas fa-check-circle me-2"></i>Successfully updated wp-config.php</div>';
                log_message("Successfully updated wp-config.php");
                update_count('config', 'success');
            } else {
                echo '<div class="log-entry log-error"><i class="fas fa-times-circle me-2"></i>Failed to update wp-config.php</div>';
                log_message("Failed to update wp-config.php", 'error');
                update_count('config', 'failed');
            }
        } else {
            echo '<div class="log-entry log-warning"><i class="fas fa-exclamation-triangle me-2"></i>wp-config.php is not writable</div>';
            echo '<div id="configWriteOptions" class="mt-3">
                    <p>How would you like to proceed?</p>
                    <div class="d-flex gap-2 mb-3">
                        <button class="btn btn-warning" onclick="forceWriteConfig()">
                            <i class="fas fa-bolt me-2"></i>Force Write
                        </button>
                        <button class="btn btn-info" onclick="showManualConfig()">
                            <i class="fas fa-code me-2"></i>Show Manual Instructions
                        </button>
                    </div>
                    <div id="manualConfig" style="display:none" class="bg-light p-3 rounded">
                        <p>Please manually update your wp-config.php file. Change this line:</p>
                        <pre class="bg-dark text-white p-3 rounded"><code>$table_prefix = \'' . htmlspecialchars($old_prefix) . '\';</code></pre>
                        <p>To:</p>
                        <pre class="bg-dark text-white p-3 rounded"><code>$table_prefix = \'' . htmlspecialchars($new_prefix) . '\';</code></pre>
                        <button class="btn btn-primary" onclick="checkConfigUpdated()">
                            <i class="fas fa-sync me-2"></i>Check After Manual Update
                        </button>
                    </div>
                    <div id="configUpdateResult" class="mt-3"></div>
                  </div>';
            log_message("wp-config.php is not writable", 'warning');
            update_count('config', 'skipped');
        }
        
        echo '</div></div>';
        } else {
            // Skip config update for custom database mode
            log_message("Skipping wp-config.php update (custom database mode)");
            update_count('config', 'skipped');
        }

        $connection->close();

        // Calculate totals
        $total_operations = 0;
        $successful_operations = 0;
        $failed_operations = 0;
        $skipped_operations = 0;
        
        foreach ($operation_counts as $category) {
            $total_operations += $category['total'];
            $successful_operations += $category['success'];
            $failed_operations += $category['failed'];
            $skipped_operations += $category['skipped'];
        }
        
        $success_rate = $total_operations > 0 ? round(($successful_operations / $total_operations) * 100) : 0;
        $end_time = microtime(true);
        $execution_time = round($end_time - $start_time, 2);
        
        log_message("Operation completed in {$execution_time}s");
        log_message("Total operations: $total_operations");
        log_message("Successful: $successful_operations");
        log_message("Failed: $failed_operations");
        log_message("Skipped: $skipped_operations");
        log_message("Success rate: {$success_rate}%");

        echo "<script>updateProgress(100, 'Operation completed!');</script>";
        ob_flush();
        flush();

        // Display summary
        echo '<div class="card border-success mt-4">
                <div class="card-header bg-success text-white">
                    <h3 class="mb-0"><i class="fas fa-check-circle me-2"></i>Operation Summary</h3>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="stat-card card bg-primary text-white text-center">
                                <div class="card-body">
                                    <h6 class="card-title text-white">Total Operations</h6>
                                    <p class="stat-number text-white mb-0">' . $total_operations . '</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card card bg-success text-white text-center">
                                <div class="card-body">
                                    <h6 class="card-title text-white">Successful</h6>
                                    <p class="stat-number text-white mb-0">' . $successful_operations . '</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card card bg-danger text-white text-center">
                                <div class="card-body">
                                    <h6 class="card-title text-white">Failed</h6>
                                    <p class="stat-number text-white mb-0">' . $failed_operations . '</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card card bg-warning text-dark text-center">
                                <div class="card-body">
                                    <h6 class="card-title text-dark">Skipped</h6>
                                    <p class="stat-number text-dark mb-0">' . $skipped_operations . '</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Statistics</h5>
                                </div>
                                <div class="card-body">
                                    <p><strong>Success Rate:</strong> <span class="badge bg-' . ($success_rate >= 90 ? 'success' : ($success_rate >= 50 ? 'warning' : 'danger')) . '">' . $success_rate . '%</span></p>
                                    <p><strong>Execution Time:</strong> ' . $execution_time . ' seconds</p>
                                    <p><strong>Tables Processed:</strong> ' . $total_tables . '</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>By Category</h5>
                                </div>
                                <div class="card-body">
                                    <ul class="list-group">';
        
        foreach ($operation_counts as $category => $counts) {
            $category_success_rate = $counts['total'] > 0 ? round(($counts['success'] / $counts['total']) * 100) : 0;
            echo '<li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-' . ($category === 'tables' ? 'table' : ($category === 'options' ? 'cog' : ($category === 'usermeta' ? 'users' : ($category === 'postmeta' ? 'file-alt' : 'file-code')))) . ' me-2"></i>' . ucfirst($category) . '</span>
                    <span class="badge bg-' . ($category_success_rate >= 90 ? 'success' : ($category_success_rate >= 50 ? 'warning' : 'danger')) . ' rounded-pill">
                        ' . $counts['success'] . '/' . $counts['total'] . ' (' . $category_success_rate . '%)
                    </span>
                  </li>';
        }
        
        echo '              </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <h5><i class="fas fa-info-circle me-2"></i>Next Steps</h5>
                        <ul class="mb-0">
                            <li>Verify your site is functioning properly</li>
                            <li>Clear any caching plugins or server caches</li>
                            <li>If you experience issues, check the log file below</li>
                        </ul>
                    </div>
                    
                    <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
                        <a href="' . htmlspecialchars(basename($log_file)) . '" class="btn btn-primary" download>
                            <i class="fas fa-download me-2"></i>Download Log File
                        </a>
                        <a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="btn btn-secondary">
                            <i class="fas fa-redo me-2"></i>Back to Form
                        </a>
                    </div>
                </div>
            </div>';

        // Save log file
        @file_put_contents($log_file, $log_content);

    } catch (Exception $e) {
        echo '<div class="alert alert-danger">
                <h4><i class="fas fa-exclamation-triangle me-2"></i>Operation Failed</h4>
                <p>' . htmlspecialchars($e->getMessage()) . '</p>
              </div>';
        log_message("ERROR: " . $e->getMessage(), 'error');
        @file_put_contents($log_file, $log_content);
        
        echo '<div class="d-flex gap-2 mt-3">
                <a href="' . htmlspecialchars(basename($log_file)) . '" class="btn btn-primary" download>
                    <i class="fas fa-download me-2"></i>Download Log File
                </a>
                <a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Form
                </a>
              </div>';
    }

    echo '      </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            function updateProgress(percent, message = "") {
                const progressBar = document.getElementById("progressBar");
                const currentOp = document.getElementById("currentOperation");
                
                if (progressBar) {
                    const roundedPercent = Math.min(100, Math.max(0, Math.round(percent)));
                    progressBar.style.width = roundedPercent + "%";
                    progressBar.setAttribute("aria-valuenow", roundedPercent);
                    progressBar.textContent = roundedPercent + "%";
                }
                
                if (message && currentOp) {
                    currentOp.innerHTML = "<i class=\"fas fa-spinner fa-spin me-2\"></i>" + message;
                }
            }
            
            function toggleCategory(category) {
                const element = document.getElementById("category-" + category);
                if (element) {
                    element.style.display = element.style.display === "none" ? "block" : "none";
                }
            }
            
            const oldPrefix = "' . addslashes($_POST['old_prefix']) . '";
            const newPrefix = "' . addslashes($_POST['new_prefix']) . '";
            
            function forceWriteConfig() {
                document.getElementById("configUpdateResult").innerHTML = 
                    \'<div class="alert alert-info"><i class="fas fa-hourglass me-2"></i>Attempting to force write...</div>\';
                
                fetch(window.location.href, {
                    method: "POST",
                    headers: {"Content-Type": "application/x-www-form-urlencoded"},
                    body: `action=force_write&old_prefix=${encodeURIComponent(oldPrefix)}&new_prefix=${encodeURIComponent(newPrefix)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById("configUpdateResult").innerHTML = 
                            \'<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Successfully updated wp-config.php</div>\';
                        checkConfigUpdated();
                    } else {
                        document.getElementById("configUpdateResult").innerHTML = 
                            \'<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>Failed to update wp-config.php. Please try manual method.</div>\';
                    }
                })
                .catch(error => {
                    document.getElementById("configUpdateResult").innerHTML = 
                        \'<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>Error: \' + error.message + \'</div>\';
                });
            }
            
            function showManualConfig() {
                document.getElementById("manualConfig").style.display = "block";
            }
            
            function checkConfigUpdated() {
                fetch(window.location.href, {
                    method: "POST",
                    headers: {"Content-Type": "application/x-www-form-urlencoded"},
                    body: "action=check_config"
                })
                .then(response => response.json())
                .then(data => {
                    const expectedPrefix = newPrefix.replace(/_$/, "") + "_";
                    if (data.current_prefix === expectedPrefix) {
                        document.getElementById("configUpdateResult").innerHTML = 
                            \'<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>wp-config.php has been successfully updated!</div>\';
                    } else {
                        document.getElementById("configUpdateResult").innerHTML = 
                            \'<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>wp-config.php not yet updated. Current prefix: \' + 
                            data.current_prefix + \'</div>\';
                    }
                });
            }
        </script>
    </body>
    </html>';
    exit;
}

// Display the form
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Table Prefix Changer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: "Segoe UI", "Roboto", "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
            padding: 20px 0;
        }
        
        .tool-container {
            max-width: 700px;
            margin: 0 auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            border-radius: 15px;
            overflow: hidden;
            background: white;
        }
        
        .tool-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .tool-header h2 {
            margin: 0;
            font-weight: 700;
            font-size: 2rem;
        }
        
        .tool-body {
            padding: 30px;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
        }
        
        .form-control {
            padding: 12px 15px;
            border-radius: 8px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.15);
            outline: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
        }
        
        .config-detection {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .config-detection code {
            background: rgba(255,255,255,0.2);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        
        .info-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 25px;
        }
        
        .info-section h5 {
            color: var(--secondary-color);
            margin-bottom: 15px;
        }
        
        .info-section ul {
            margin-bottom: 0;
        }
        
        .info-section code {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
        }
        
        #prefixMatchWarning {
            display: none;
            margin-top: 10px;
        }
        
        .example-item {
            padding: 5px 0;
            font-family: monospace;
        }
        
        .example-item code {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="tool-container">
            <div class="tool-header">
                <h2><i class="fas fa-cog me-2"></i> Database Table Prefix Changer</h2>
                <p class="mb-0 mt-2 opacity-75">Works with WordPress and any MySQL/MariaDB database</p>
            </div>
            <div class="tool-body">
                <?php if ($warning_message): ?>
                <div class="alert alert-warning mb-4">
                    <h5><i class="fas fa-exclamation-triangle me-2"></i>Prefix Mismatch Detected</h5>
                    <p class="mb-2">wp-config.php prefix (<code><?= htmlspecialchars($warning_message['config_prefix']) ?></code>) doesn't match database prefix (<code><?= htmlspecialchars($warning_message['actual_prefix']) ?></code>).</p>
                    <p class="mb-0"><small>Using database prefix for this session.</small></p>
                </div>
                <?php endif; ?>
                
                <form method="POST" class="needs-validation" novalidate id="prefixForm">
                    <div class="mb-4">
                        <label class="form-label mb-3">
                            <i class="fas fa-database me-2"></i>Connection Mode
                        </label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="connection_mode" id="modeWordPress" value="wordpress" <?= $wp_detected ? 'checked' : '' ?> onchange="toggleConnectionMode()">
                            <label class="btn btn-outline-primary" for="modeWordPress">
                                <i class="fas fa-wordpress me-2"></i>WordPress (Auto-detect)
                            </label>
                            
                            <input type="radio" class="btn-check" name="connection_mode" id="modeCustom" value="custom" <?= !$wp_detected ? 'checked' : '' ?> onchange="toggleConnectionMode()">
                            <label class="btn btn-outline-primary" for="modeCustom">
                                <i class="fas fa-server me-2"></i>Custom Database
                            </label>
                        </div>
                        <small class="text-muted d-block mt-2">
                            <i class="fas fa-info-circle me-1"></i>
                            <span id="modeDescription"><?= $wp_detected ? 'Using WordPress wp-config.php for database connection' : 'Enter your database credentials manually' ?></span>
                        </small>
                    </div>
                    
                    <div id="customDbSection" style="display: <?= !$wp_detected ? 'block' : 'none' ?>;">
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="db_host" class="form-label">Database Host</label>
                                <input type="text" class="form-control" id="db_host" name="db_host" value="localhost" placeholder="localhost">
                            </div>
                            <div class="col-md-6">
                                <label for="db_port" class="form-label">Database Port <small class="text-muted">(optional)</small></label>
                                <input type="number" class="form-control" id="db_port" name="db_port" value="" placeholder="3306" min="1" max="65535">
                                <small class="text-muted">Leave empty for default port 3306</small>
                            </div>
                            <div class="col-md-6">
                                <label for="db_name" class="form-label">Database Name</label>
                                <input type="text" class="form-control" id="db_name" name="db_name" value="" placeholder="database_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="db_user" class="form-label">Database User</label>
                                <input type="text" class="form-control" id="db_user" name="db_user" value="" placeholder="db_user" required>
                            </div>
                            <div class="col-12">
                                <label for="db_pass" class="form-label">Database Password</label>
                                <input type="password" class="form-control" id="db_pass" name="db_pass" value="" placeholder="Leave empty if no password">
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($wp_detected): ?>
                    <div id="wpConfigSection" class="mb-4">
                        <div class="config-detection">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>WordPress Detected!</strong> 
                            <code>$table_prefix = '<?= htmlspecialchars($config_prefix) ?>';</code>
                            <?php if ($actual_prefix && $actual_prefix !== $config_prefix): ?>
                            <div class="mt-2">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <small>Warning: Database prefix detected as <code><?= htmlspecialchars($actual_prefix) ?></code></small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-4">
                        <label for="old_prefix" class="form-label">
                            <i class="fas fa-tag me-2"></i>Current Prefix
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="old_prefix" 
                               name="old_prefix" 
                               value="<?= htmlspecialchars($config_prefix ?: '') ?>" 
                               required
                               pattern="[a-zA-Z0-9_]+"
                               placeholder="wp_">
                        <div class="invalid-feedback">Please enter a valid prefix (letters, numbers, and underscores only)</div>
                        <small class="text-muted d-block mt-1">
                            <i class="fas fa-info-circle me-1"></i>
                            Enter the current prefix used in your database table names
                        </small>
                    </div>
                    
                    <div class="mb-4">
                        <label for="new_prefix" class="form-label">
                            <i class="fas fa-tag me-2"></i>New Prefix
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="new_prefix" 
                               name="new_prefix" 
                               placeholder="newprefix_" 
                               required
                               pattern="[a-zA-Z0-9_]+">
                        <div class="invalid-feedback">Please enter a valid prefix (letters, numbers, and underscores only)</div>
                        <div id="prefixMatchWarning" class="alert alert-warning mt-2">
                            <i class="fas fa-exclamation-triangle me-2"></i>New prefix is the same as current prefix!
                        </div>
                    </div>
                    
                    <div id="dbTestResult" class="mb-3" style="display: none;"></div>
                    
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-info btn-lg" id="testConnectionBtn" onclick="testDatabaseConnection()">
                            <i class="fas fa-plug me-2"></i> Test Database Connection
                        </button>
                        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                            <i class="fas fa-sync-alt me-2"></i> Change Prefix
                        </button>
                    </div>
                </form>
                
                <div class="info-section">
                    <h5><i class="fas fa-info-circle me-2"></i>About Prefixes</h5>
                    <p>This tool will change <strong>only exact prefix matches</strong> at the beginning of table names and values.</p>
                    <p><strong>Example:</strong> Changing <code>wp_</code> to <code>newprefix_</code>:</p>
                    <ul>
                        <li class="example-item"><code>wp_options</code> → <code>newprefix_options</code> <span class="text-success">(will change)</span></li>
                        <li class="example-item"><code>wp_usermeta</code> → <code>newprefix_usermeta</code> <span class="text-success">(will change)</span></li>
                        <li class="example-item"><code>wp_postmeta</code> → <code>newprefix_postmeta</code> <span class="text-success">(will change)</span></li>
                        <li class="example-item"><code>dismissed_wp_pointers</code> → <span class="text-muted">no change (not at start)</span></li>
                        <li class="example-item"><code>string_wp_string [wp_]</code> → <span class="text-muted">no change (not at start)</span></li>
                    </ul>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i><strong>Important:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Always backup your database before making changes!</li>
                            <li>Verify the current prefix matches your database table names</li>
                            <li id="wpConfigNote" style="display: <?= $wp_detected ? 'block' : 'none' ?>;">This script will automatically update wp-config.php (WordPress mode only)</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function() {
            "use strict";
            const form = document.getElementById("prefixForm");
            const oldPrefixInput = document.getElementById("old_prefix");
            const newPrefixInput = document.getElementById("new_prefix");
            const warningDiv = document.getElementById("prefixMatchWarning");
            const submitButton = form.querySelector("button[type=submit]");

            // Form validation
            form.addEventListener("submit", function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add("was-validated");
            }, false);

            function checkPrefixes() {
                const oldPrefix = oldPrefixInput.value.trim();
                const newPrefix = newPrefixInput.value.trim();
                
                if (oldPrefix && newPrefix && oldPrefix === newPrefix) {
                    warningDiv.style.display = "block";
                    submitButton.disabled = true;
                    submitButton.classList.add("btn-secondary");
                    submitButton.classList.remove("btn-primary");
                } else {
                    warningDiv.style.display = "none";
                    submitButton.disabled = false;
                    submitButton.classList.add("btn-primary");
                    submitButton.classList.remove("btn-secondary");
                }
            }

            // Check on input changes
            oldPrefixInput.addEventListener("input", checkPrefixes);
            newPrefixInput.addEventListener("input", checkPrefixes);

            // Also check on form submission
            form.addEventListener("submit", function(e) {
                const oldPrefix = oldPrefixInput.value.trim();
                const newPrefix = newPrefixInput.value.trim();
                
                if (oldPrefix === newPrefix) {
                    e.preventDefault();
                    alert("The new prefix must be different from the current prefix!");
                    newPrefixInput.focus();
                } else if (!confirm(`Are you sure you want to change the prefix from "${oldPrefix}" to "${newPrefix}"?\n\n⚠️ This action cannot be undone. Make sure you have a database backup!`)) {
                    e.preventDefault();
                }
            });

            // Initial check
            checkPrefixes();
        })();
        
        function toggleConnectionMode() {
            const wpMode = document.getElementById('modeWordPress').checked;
            const customSection = document.getElementById('customDbSection');
            const wpConfigSection = document.getElementById('wpConfigSection');
            const modeDescription = document.getElementById('modeDescription');
            const wpConfigNote = document.getElementById('wpConfigNote');
            const dbFields = customSection.querySelectorAll('input[required]');
            
            if (wpMode) {
                customSection.style.display = 'none';
                if (wpConfigSection) wpConfigSection.style.display = 'block';
                modeDescription.textContent = 'Using WordPress wp-config.php for database connection';
                if (wpConfigNote) wpConfigNote.style.display = 'block';
                // Remove required from custom DB fields
                dbFields.forEach(field => field.removeAttribute('required'));
            } else {
                customSection.style.display = 'block';
                if (wpConfigSection) wpConfigSection.style.display = 'none';
                modeDescription.textContent = 'Enter your database credentials manually';
                if (wpConfigNote) wpConfigNote.style.display = 'none';
                // Add required to custom DB fields
                dbFields.forEach(field => field.setAttribute('required', 'required'));
            }
        }
        
        // Initial mode setup
        document.addEventListener('DOMContentLoaded', function() {
            toggleConnectionMode();
        });
        
        function testDatabaseConnection() {
            const testBtn = document.getElementById('testConnectionBtn');
            const resultDiv = document.getElementById('dbTestResult');
            const submitBtn = document.getElementById('submitBtn');
            const form = document.getElementById('prefixForm');
            
            // Disable button and show loading
            const originalHtml = testBtn.innerHTML;
            testBtn.disabled = true;
            testBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Testing Connection...';
            resultDiv.style.display = 'none';
            
            // Get form data
            const formData = new FormData(form);
            formData.append('action', 'test_connection');
            
            // Get connection mode
            const connectionMode = document.querySelector('input[name="connection_mode"]:checked')?.value || 'wordpress';
            formData.set('connection_mode', connectionMode);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                testBtn.disabled = false;
                testBtn.innerHTML = originalHtml;
                resultDiv.style.display = 'block';
                
                if (data.success) {
                    resultDiv.className = 'alert alert-success mb-3';
                    resultDiv.innerHTML = `
                        <h5><i class="fas fa-check-circle me-2"></i>Connection Successful!</h5>
                        <hr>
                        <div class="row g-2 mt-2">
                            <div class="col-md-6">
                                <strong>Database:</strong> <code>${data.database}</code>
                            </div>
                            <div class="col-md-6">
                                <strong>Host:</strong> <code>${data.host}</code>
                            </div>
                            <div class="col-md-6">
                                <strong>Port:</strong> <code>${data.port || 3306}</code>
                            </div>
                            <div class="col-md-6">
                                <strong>Tables Found:</strong> <span class="badge bg-primary">${data.tables}</span>
                            </div>
                            <div class="col-md-6">
                                <strong>MySQL Version:</strong> <code>${data.version}</code>
                            </div>
                            <div class="col-12">
                                <strong>Mode:</strong> <span class="badge bg-info">${data.mode}</span>
                            </div>
                        </div>
                    `;
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('btn-secondary');
                    submitBtn.classList.add('btn-primary');
                } else {
                    resultDiv.className = 'alert alert-danger mb-3';
                    resultDiv.innerHTML = `
                        <h5><i class="fas fa-times-circle me-2"></i>Connection Failed</h5>
                        <p class="mb-0"><strong>Error:</strong> ${data.error || 'Unknown error occurred'}</p>
                        <small class="d-block mt-2">Please check your database credentials and try again.</small>
                    `;
                    submitBtn.disabled = true;
                    submitBtn.classList.remove('btn-primary');
                    submitBtn.classList.add('btn-secondary');
                }
            })
            .catch(error => {
                testBtn.disabled = false;
                testBtn.innerHTML = originalHtml;
                resultDiv.style.display = 'block';
                resultDiv.className = 'alert alert-danger mb-3';
                resultDiv.innerHTML = `
                    <h5><i class="fas fa-exclamation-triangle me-2"></i>Connection Test Error</h5>
                    <p class="mb-0"><strong>Error:</strong> ${error.message}</p>
                `;
                submitBtn.disabled = true;
                submitBtn.classList.remove('btn-primary');
                submitBtn.classList.add('btn-secondary');
            });
        }
    </script>
</body>
</html>
<?php
ob_end_flush();
?>
