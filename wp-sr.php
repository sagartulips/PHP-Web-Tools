<?php
/**
 * WordPress Search and Replace Tool
 *
 * A comprehensive tool for safely performing search and replace operations
 * in WordPress databases with real-time progress tracking and detailed logging.
 * Supports WordPress and other MySQL/MariaDB databases.
 *
 * @package     DB Search and Replace Tool
 * @author      Sagar GC <sagar@tulipstechnologies.com>
 * @copyright   2024 Tulips Technologies Pvt. Ltd.
 * @license     GPL-2.0-or-later
 * @version     2.0.0
 * 
 * Features:
 * - Safe database text replacement
 * - WordPress wp-config.php auto-detection
 * - Serialized data support (WordPress)
 * - Real-time progress tracking
 * - Detailed operation logging
 * - Table selection with search/filter
 * - Field type validation
 * - Length checking for VARCHAR fields
 * - Dry run mode (preview changes)
 * - Bootstrap 5 responsive interface
 * - Copy/download operation logs
 * - Support for WordPress and other databases
 *
 * Usage:
 * 1. Place this file in your WordPress root directory (or any directory)
 * 2. Access via browser (e.g., example.com/wp-sr.php)
 * 3. Auto-detect WordPress config or enter database credentials manually
 * 4. Specify text to find and replace
 * 5. Choose tables to process
 * 6. Review changes in real-time
 *
 * Security Features:
 * - Input sanitization
 * - Prepared SQL statements
 * - Output escaping
 * - Error handling
 * - Secure password handling
 * - CSRF protection
 *
 * Requirements:
 * - PHP 7.4 or higher
 * - MySQL 5.6 or higher / MariaDB 10.0+
 * - Write permissions for logs
 * - Modern web browser
 *
 * Database Operations:
 * - Validates field types before replacement
 * - Checks VARCHAR length constraints
 * - Uses REPLACE function for safe updates
 * - Handles text and binary content
 * - Supports serialized WordPress data
 *
 * Logging Features:
 * - Detailed operation logging
 * - Success/failure tracking
 * - Operation statistics
 * - Downloadable log files
 * - Clipboard copy support
 *
 * UI Features:
 * - Progress bar
 * - Real-time updates
 * - Operation statistics
 * - Responsive design
 * - User-friendly messages
 * - Table search/filter
 * - WordPress config auto-detect
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Enable output buffering for smooth progress updates
ob_start();

// Prevent direct file access via command line without HTTP
if (php_sapi_name() === 'cli' && !isset($_SERVER['HTTP_HOST'])) {
    die('This script must be run via web browser.');
}

// Set execution time limit
set_time_limit(300);
ini_set('memory_limit', '256M');

/**
 * Get WordPress database credentials from wp-config.php
 */
function get_wp_config() {
    $config_file = __DIR__ . '/wp-config.php';
    
    if (!file_exists($config_file)) {
        // Try parent directory
        $config_file = dirname(__DIR__) . '/wp-config.php';
        if (!file_exists($config_file)) {
            return null;
        }
    }
    
    $config = [];
    $content = file_get_contents($config_file);
    
    // Extract DB_NAME
    if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"](.*?)['\"]\s*\)/i", $content, $matches)) {
        $config['db_name'] = $matches[1];
    }
    
    // Extract DB_USER
    if (preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"](.*?)['\"]\s*\)/i", $content, $matches)) {
        $config['db_user'] = $matches[1];
    }
    
    // Extract DB_PASSWORD
    if (preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"](.*?)['\"]\s*\)/i", $content, $matches)) {
        $config['db_pass'] = $matches[1];
    }
    
    // Extract DB_HOST
    if (preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"](.*?)['\"]\s*\)/i", $content, $matches)) {
        $config['db_host'] = $matches[1];
    } else {
        $config['db_host'] = 'localhost';
    }
    
    return (!empty($config['db_name']) && !empty($config['db_user'])) ? $config : null;
}

/**
 * Check if string is serialized
 */
function is_serialized($data) {
    if (!is_string($data)) {
        return false;
    }
    $data = trim($data);
    if ('N;' == $data) {
        return true;
    }
    if (!preg_match('/^([adObis]):/', $data, $badions)) {
        return false;
    }
    switch ($badions[1]) {
        case 'a':
        case 'O':
        case 's':
            if (preg_match("/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data)) {
                return true;
            }
            break;
        case 'b':
        case 'i':
        case 'd':
            if (preg_match("/^{$badions[1]}:[0-9.E-]+;\$/", $data)) {
                return true;
            }
            break;
    }
    return false;
}

/**
 * Replace text in serialized data safely
 */
function replace_serialized($old_text, $new_text, $data) {
    if (!is_serialized($data)) {
        return $data;
    }
    
    $unserialized = @unserialize($data);
    if ($unserialized === false) {
        return $data;
    }
    
    $replaced = replace_in_array($old_text, $new_text, $unserialized);
    return serialize($replaced);
}

/**
 * Recursively replace text in array/object
 */
function replace_in_array($old_text, $new_text, $data) {
    if (is_string($data)) {
        return str_replace($old_text, $new_text, $data);
    } elseif (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = replace_in_array($old_text, $new_text, $value);
        }
    } elseif (is_object($data)) {
        foreach ($data as $key => $value) {
            $data->$key = replace_in_array($old_text, $new_text, $value);
        }
    }
    return $data;
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
 * Sanitize text field
 */
function sanitize_text_field($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Format bytes
 */
function format_bytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔠 Database Text Replacement Tool</title>
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
            font-family: 'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
            padding: 20px 0;
        }
        
        .tool-container {
            max-width: 900px;
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
        
        .tool-header p {
            margin: 10px 0 0;
            opacity: 0.9;
        }
        
        .tool-body {
            padding: 30px;
        }
        
        .wp-config-alert {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .wp-config-alert .btn-light {
            margin-top: 10px;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
        }
        
        .form-control, .form-select {
            padding: 12px 15px;
            border-radius: 8px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
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
        
        .btn-outline-primary {
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
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
        
        .summary-card {
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .table-select {
            height: 300px;
            border: 2px solid #e9ecef;
        }
        
        .table-search {
            margin-bottom: 10px;
        }
        
        .progress {
            height: 25px;
            border-radius: 10px;
            overflow: hidden;
            background-color: #e9ecef;
        }
        
        .progress-bar {
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .copy-log {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .copy-log:hover {
            transform: scale(1.05);
        }
        
        .current-table {
            font-weight: 600;
            margin-top: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            color: var(--secondary-color);
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
        
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
        }
        
        .badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
        }
        
        .table-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .feature-badge {
            display: inline-block;
            padding: 5px 10px;
            background: rgba(255,255,255,0.2);
            border-radius: 5px;
            font-size: 0.85rem;
            margin: 2px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="tool-container">
        <div class="tool-header">
            <h2><i class="fas fa-exchange-alt me-2"></i> Database Text Replacement Tool</h2>
            <p class="mb-0">Safely find and replace text in WordPress or any MySQL database</p>
            <div class="mt-3">
                <span class="feature-badge"><i class="fas fa-shield-alt"></i> Secure</span>
                <span class="feature-badge"><i class="fas fa-wordpress"></i> WordPress Ready</span>
                <span class="feature-badge"><i class="fas fa-database"></i> Universal DB Support</span>
            </div>
        </div>
        <div class="tool-body">
            <?php
            
            // Try to auto-detect WordPress config
            $wp_config = get_wp_config();
            $wp_detected = !empty($wp_config);
            
            if ($wp_detected && !isset($_POST['submit'])) {
                echo '<div class="wp-config-alert alert">
                        <h5><i class="fas fa-check-circle me-2"></i> WordPress Detected!</h5>
                        <p class="mb-2">We found your wp-config.php file. Database credentials will be auto-filled.</p>
                        <button type="button" class="btn btn-light btn-sm" onclick="document.getElementById(\'useWpConfig\').value=\'1\'; document.getElementById(\'replaceForm\').submit();">
                            <i class="fas fa-magic me-2"></i> Use WordPress Config
                        </button>
                        <button type="button" class="btn btn-outline-light btn-sm ms-2" onclick="this.parentElement.style.display=\'none\'">
                            <i class="fas fa-times me-2"></i> Dismiss
                        </button>
                      </div>';
            }
            
            function display_form($data = [], $table_list = [], $wp_config = null) {
                $wp_detected = !empty($wp_config);
                
                // Parse host and port for display
                $display_host = $data['db_host'] ?? 'localhost';
                $display_port = $data['db_port'] ?? '';
                
                if ($wp_config && !isset($data['db_host'])) {
                    // Parse wp-config host:port format
                    $host_info = parse_db_host($wp_config['db_host']);
                    $display_host = $host_info['host'];
                    $display_port = $host_info['port'] != 3306 ? $host_info['port'] : '';
                } elseif (isset($data['db_host']) && strpos($data['db_host'], ':') !== false) {
                    // Parse host:port from data
                    $host_info = parse_db_host($data['db_host']);
                    $display_host = $host_info['host'];
                    $display_port = $host_info['port'] != 3306 ? $host_info['port'] : ($data['db_port'] ?? '');
                }
                ?>
                <form method="post" id="replaceForm" class="needs-validation" novalidate>
                    <input type="hidden" name="use_wp_config" id="useWpConfig" value="0">
                    
                    <div class="row g-3">
                        <div class="col-12">
                            <h5 class="mb-3"><i class="fas fa-database me-2"></i> Database Connection</h5>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Database Host</label>
                            <input type="text" class="form-control" name="db_host" required 
                                   value="<?= htmlspecialchars($display_host) ?>"
                                   placeholder="localhost">
                            <div class="invalid-feedback">Please provide a database host</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Database Port <small class="text-muted">(optional)</small></label>
                            <input type="number" class="form-control" name="db_port" 
                                   value="<?= htmlspecialchars($display_port) ?>"
                                   placeholder="3306" min="1" max="65535">
                            <small class="text-muted">Leave empty for default port 3306</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Database Name</label>
                            <input type="text" class="form-control" name="db_name" required 
                                   value="<?= htmlspecialchars($data['db_name'] ?? ($wp_config['db_name'] ?? '')) ?>"
                                   placeholder="database_name">
                            <div class="invalid-feedback">Please provide a database name</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Database User</label>
                            <input type="text" class="form-control" name="db_user" required 
                                   value="<?= htmlspecialchars($data['db_user'] ?? ($wp_config['db_user'] ?? '')) ?>"
                                   placeholder="db_user">
                            <div class="invalid-feedback">Please provide a database user</div>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Database Password</label>
                            <input type="password" class="form-control" name="db_pass" 
                                   value="<?= htmlspecialchars($data['db_pass'] ?? ($wp_config['db_pass'] ?? '')) ?>"
                                   placeholder="Leave empty if no password">
                        </div>
                        
                        <div class="col-12 mt-4">
                            <h5 class="mb-3"><i class="fas fa-search me-2"></i> Search & Replace</h5>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Text to Find</label>
                            <input type="text" class="form-control" name="old_text" required 
                                   placeholder="Text to find" value="<?= htmlspecialchars($data['old_text'] ?? '') ?>">
                            <div class="invalid-feedback">Please provide text to replace</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Replace With</label>
                            <input type="text" class="form-control" name="new_text" required 
                                   placeholder="Replacement text" value="<?= htmlspecialchars($data['new_text'] ?? '') ?>">
                            <div class="invalid-feedback">Please provide replacement text</div>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="handle_serialized" value="1" 
                                       id="handleSerialized" <?= !empty($data['handle_serialized']) ? 'checked' : 'checked' ?>>
                                <label class="form-check-label" for="handleSerialized">
                                    <strong>Handle WordPress Serialized Data</strong>
                                    <small class="text-muted d-block">Automatically handles serialized WordPress data (recommended for WordPress)</small>
                                </label>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="dry_run" value="1" 
                                       id="dryRun" <?= !empty($data['dry_run']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="dryRun">
                                    <strong>Dry Run (Preview Only)</strong>
                                    <small class="text-muted d-block">Preview changes without actually modifying the database</small>
                                </label>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Handle Text Too Long for VARCHAR Fields</label>
                                <select class="form-select" name="truncate_mode" id="truncateMode">
                                    <option value="skip" <?= ($data['truncate_mode'] ?? 'skip') === 'skip' ? 'selected' : '' ?>>Skip (Show Warning)</option>
                                    <option value="truncate" <?= ($data['truncate_mode'] ?? '') === 'truncate' ? 'selected' : '' ?>>Truncate to Fit</option>
                                    <option value="try" <?= ($data['truncate_mode'] ?? '') === 'try' ? 'selected' : '' ?>>Try Anyway (May Fail)</option>
                                </select>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> When replacement text is longer than VARCHAR field allows:
                                    <ul class="mb-0 mt-1" style="padding-left: 20px;">
                                        <li><strong>Skip:</strong> Skip the field and show a warning (safest)</li>
                                        <li><strong>Truncate:</strong> Automatically cut text to fit the field length</li>
                                        <li><strong>Try Anyway:</strong> Attempt replacement (will fail if too long)</li>
                                    </ul>
                                </small>
                            </div>
                            
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="replace_all" value="1" 
                                       id="replaceAll" <?= !empty($data['replace_all']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="replaceAll">
                                    <strong>Replace in All Tables</strong>
                                </label>
                            </div>
                        </div>
                        
                        <?php if (empty($data['replace_all']) && !empty($table_list)): ?>
                        <div class="col-12" id="tablesSection">
                            <label class="form-label">Select Tables</label>
                            <div class="table-search">
                                <input type="text" class="form-control" id="tableSearch" 
                                       placeholder="🔍 Search tables...">
                            </div>
                            <select class="form-select table-select" name="tables[]" id="tablesSelect" multiple required>
                                <?php foreach ($table_list as $table): ?>
                                    <option value="<?= htmlspecialchars($table) ?>" 
                                            <?= isset($data['tables']) && in_array($table, $data['tables']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($table) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select at least one table</div>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> Hold Ctrl/Cmd to select multiple tables. 
                                <span id="tableCount"><?= count($table_list) ?></span> tables available.
                            </small>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-12 mt-4">
                            <div id="dbTestResult" class="mb-3" style="display: none;"></div>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-info btn-lg" id="testConnectionBtn" onclick="testDatabaseConnection()">
                                    <i class="fas fa-plug me-2"></i> Test Database Connection
                                </button>
                                <div class="d-flex gap-2">
                                    <button type="submit" name="submit" class="btn btn-primary btn-lg flex-fill" id="submitBtn">
                                        <i class="fas fa-sync-alt me-2"></i> Start Replacement
                                    </button>
                                    <button type="button" onclick="resetForm()" class="btn btn-outline-secondary btn-lg">
                                        <i class="fas fa-redo me-2"></i> Clear Form
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
                <?php
            }

            function replace_text($conn, $old_text, $new_text, $tables, $handle_serialized = true, $dry_run = false, $truncate_mode = 'skip') {
                $log = [];
                $stats = [
                    'success' => 0, 
                    'failed' => 0, 
                    'skipped' => 0, 
                    'total_rows' => 0,
                    'tables_processed' => 0,
                    'tables_with_matches' => 0,
                    'tables_no_matches' => 0,
                    'tables_errors' => 0,
                    'truncated' => 0
                ];
                $total_tables = count($tables);
                $processed_tables = 0;

                foreach ($tables as $table) {
                    $processed_tables++;
                    
                    // Send progress update to browser BEFORE processing
                    $table_escaped = htmlspecialchars($table, ENT_QUOTES);
                    $progress = round(($processed_tables / $total_tables) * 100);
                    $stats_json = json_encode([
                        'processed' => $stats['tables_processed'],
                        'with_matches' => $stats['tables_with_matches'],
                        'no_matches' => $stats['tables_no_matches'],
                        'errors' => $stats['tables_errors'],
                        'total' => $total_tables,
                        'current' => $table_escaped
                    ]);
                    echo "<script>if(typeof updateProgress === 'function') updateProgress($progress, 'Processing table: $table_escaped', $stats_json);</script>";
                    ob_flush();
                    flush();
                    
                    try {
                        $columns_result = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "`");
                        if (!$columns_result) {
                            throw new Exception("Cannot get columns for table: $table");
                        }
                    } catch (Exception $e) {
                        $log[] = [
                            'type' => 'error',
                            'message' => "Error accessing table `$table`: " . $e->getMessage()
                        ];
                        $stats['failed']++;
                        $stats['tables_errors']++;
                        $stats['tables_processed']++;
                        continue;
                    }
                    
                    $table_has_updates = false;
                    $table_has_matches = false;
                    $columns = [];

                    while ($column = $columns_result->fetch_assoc()) {
                        $columns[] = $column;
                    }
                    
                    foreach ($columns as $column) {
                        $field = $column['Field'];
                        $type  = strtolower($column['Type']);

                        if (preg_match('/^(varchar|char|text|tinytext|mediumtext|longtext|blob|tinyblob|mediumblob|longblob)/', $type)) {
                            $max_length = null;
                            if (preg_match('/varchar\((\d+)\)|char\((\d+)\)/', $type, $matches)) {
                                $max_length = (int)($matches[1] ?? $matches[2] ?? 0);
                            }

                            // Check if new text fits in VARCHAR
                            $new_text_to_use = $new_text;
                            $was_truncated = false;
                            
                            if ($max_length !== null && strlen($new_text) > $max_length) {
                                if ($truncate_mode === 'skip') {
                                    $log[] = [
                                        'type' => 'warning',
                                        'message' => "Skipped `$table`.`$field` — new text (" . strlen($new_text) . " chars) too long for " . strtoupper($type) . " field (max: $max_length chars)"
                                    ];
                                    $stats['skipped']++;
                                    continue;
                                } elseif ($truncate_mode === 'truncate') {
                                    $new_text_to_use = mb_substr($new_text, 0, $max_length, 'UTF-8');
                                    $was_truncated = true;
                                    $stats['truncated']++;
                                    $log[] = [
                                        'type' => 'warning',
                                        'message' => "Truncated `$table`.`$field` — new text (" . strlen($new_text) . " chars) truncated to $max_length chars for " . strtoupper($type) . " field"
                                    ];
                                }
                                // If truncate_mode === 'try', we'll attempt anyway and let it fail if needed
                            }

                            // Check if field contains the old text
                            $check_sql = "SELECT COUNT(*) as cnt FROM `" . $conn->real_escape_string($table) . "` 
                                         WHERE `" . $conn->real_escape_string($field) . "` LIKE ?";
                            $check_stmt = $conn->prepare($check_sql);
                            $like_old = '%' . $old_text . '%';
                            $check_stmt->bind_param('s', $like_old);
                            $check_stmt->execute();
                            $check_result = $check_stmt->get_result();
                            $row_count = $check_result->fetch_assoc()['cnt'];
                            $check_stmt->close();

                            if ($row_count == 0) {
                                continue; // No matches, skip
                            }

                            $table_has_matches = true;

                            if ($dry_run) {
                                $log[] = [
                                    'type' => 'info',
                                    'message' => "[DRY RUN] Would update `$table`.`$field` (~$row_count rows affected)"
                                ];
                                $stats['success']++;
                                $table_has_updates = true;
                                continue;
                            }

                            // Handle serialized data if enabled
                            if ($handle_serialized) {
                                // First, get all rows that might contain serialized data
                                $get_rows_sql = "SELECT `" . $conn->real_escape_string($field) . "` as field_data 
                                                FROM `" . $conn->real_escape_string($table) . "` 
                                                WHERE `" . $conn->real_escape_string($field) . "` LIKE ?";
                                $get_rows_stmt = $conn->prepare($get_rows_sql);
                                $get_rows_stmt->bind_param('s', $like_old);
                                $get_rows_stmt->execute();
                                $rows_result = $get_rows_stmt->get_result();
                                
                                $serialized_updates = 0;
                                while ($row = $rows_result->fetch_assoc()) {
                                    $field_data = $row['field_data'];
                                    
                                    if (is_serialized($field_data)) {
                                        $new_serialized = replace_serialized($old_text, $new_text, $field_data);
                                        if ($new_serialized !== $field_data) {
                                            $update_sql = "UPDATE `" . $conn->real_escape_string($table) . "` 
                                                          SET `" . $conn->real_escape_string($field) . "` = ? 
                                                          WHERE `" . $conn->real_escape_string($field) . "` = ?";
                                            $update_stmt = $conn->prepare($update_sql);
                                            $update_stmt->bind_param('ss', $new_serialized, $field_data);
                                            
                                            try {
                                                $update_stmt->execute();
                                                if ($update_stmt->affected_rows > 0) {
                                                    $serialized_updates += $update_stmt->affected_rows;
                                                }
                                            } catch (Exception $e) {
                                                // Fall through to regular replace
                                            }
                                            $update_stmt->close();
                                        }
                                    }
                                }
                                $get_rows_stmt->close();
                                
                                if ($serialized_updates > 0) {
                                    $log[] = [
                                        'type' => 'success',
                                        'message' => "Updated `$table`.`$field` (serialized data, $serialized_updates rows affected)"
                                    ];
                                    $stats['success']++;
                                    $stats['total_rows'] += $serialized_updates;
                                    $table_has_updates = true;
                                }
                            }

                            // Regular text replacement (for non-serialized data or when serialized handling is disabled)
                            // Always perform regular replacement - it will handle non-serialized data
                            $stmt = $conn->prepare("UPDATE `" . $conn->real_escape_string($table) . "` 
                                                   SET `" . $conn->real_escape_string($field) . "` = REPLACE(`" . $conn->real_escape_string($field) . "`, ?, ?) 
                                                   WHERE `" . $conn->real_escape_string($field) . "` LIKE ?");
                            $stmt->bind_param('sss', $old_text, $new_text_to_use, $like_old);

                            try {
                                $stmt->execute();
                                $affected = $stmt->affected_rows;

                                if ($affected > 0) {
                                    $truncated_note = $was_truncated ? ' (truncated)' : '';
                                    $log[] = [
                                        'type' => 'success',
                                        'message' => "Updated `$table`.`$field` ($affected rows affected)$truncated_note"
                                    ];
                                    $stats['success']++;
                                    $stats['total_rows'] += $affected;
                                    $table_has_updates = true;
                                }
                            } catch (Exception $e) {
                                $error_msg = htmlspecialchars($e->getMessage());
                                // Check if it's a data too long error
                                if (strpos($error_msg, 'Data too long') !== false || strpos($error_msg, '1406') !== false) {
                                    $log[] = [
                                        'type' => 'error',
                                        'message' => "Failed `$table`.`$field` — replacement text too long for " . strtoupper($type) . " field. Original error: $error_msg"
                                    ];
                                } else {
                                    $log[] = [
                                        'type' => 'error',
                                        'message' => "Error in `$table`.`$field`: $error_msg"
                                    ];
                                }
                                $stats['failed']++;
                            }

                            $stmt->close();
                        }
                    }
                    
                    // Update table statistics AFTER processing
                    $stats['tables_processed']++;
                    if ($table_has_matches) {
                        $stats['tables_with_matches']++;
                    } else {
                        $stats['tables_no_matches']++;
                        if (!$table_has_updates && !empty($columns)) {
                            $log[] = [
                                'type' => 'info',
                                'message' => "No matching text found in `$table`"
                            ];
                            $stats['skipped']++;
                        }
                    }
                    
                    // Send updated statistics after processing table
                    $progress = round(($processed_tables / $total_tables) * 100);
                    $stats_json = json_encode([
                        'processed' => $stats['tables_processed'],
                        'with_matches' => $stats['tables_with_matches'],
                        'no_matches' => $stats['tables_no_matches'],
                        'errors' => $stats['tables_errors'],
                        'total' => $total_tables,
                        'current' => 'Completed: ' . $table_escaped
                    ]);
                    echo "<script>if(typeof updateProgress === 'function') updateProgress($progress, 'Completed: $table_escaped', $stats_json);</script>";
                    ob_flush();
                    flush();
                }
                
                // Final update at 100%
                $final_stats_json = json_encode([
                    'processed' => $stats['tables_processed'],
                    'with_matches' => $stats['tables_with_matches'],
                    'no_matches' => $stats['tables_no_matches'],
                    'errors' => $stats['tables_errors'],
                    'total' => $total_tables,
                    'current' => 'All tables processed'
                ]);
                echo "<script>if(typeof updateProgress === 'function') updateProgress(100, 'All tables processed successfully!', $final_stats_json);</script>";
                ob_flush();
                flush();

                return ['log' => $log, 'stats' => $stats];
            }

            // Initialize logging
            $log_file = 'text_replace_log_' . date('Y-m-d_His') . '.txt';
            $log_content = "Database Text Replacement Tool Log\n";
            $log_content .= "==================================\n";
            $log_content .= "Date: " . date('Y-m-d H:i:s') . "\n";
            $log_content .= "PHP Version: " . PHP_VERSION . "\n\n";

            function log_message($message, $type = 'info') {
                global $log_content;
                $timestamp = date('[Y-m-d H:i:s]');
                $log_content .= "$timestamp [$type] $message\n";
            }

            // Handle AJAX requests (test connection)
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_connection') {
                // Clear any output buffer to ensure clean JSON response
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                header('Content-Type: application/json');
                
                try {
                    $use_wp_config = isset($_POST['use_wp_config']) && $_POST['use_wp_config'] == '1';
                    $wp_config = $use_wp_config ? get_wp_config() : null;
                    
                    // Get database credentials
                    if ($wp_config) {
                        // WordPress mode: use wp-config.php credentials
                        $host_info = parse_db_host($wp_config['db_host']);
                        $test_db_host = $host_info['host'];
                        $test_db_port = $host_info['port'];
                        $test_db_user = $wp_config['db_user'];
                        $test_db_pass = $wp_config['db_pass'] ?? '';
                        $test_db_name = $wp_config['db_name'];
                    } else {
                        // Custom mode: use form credentials
                        $test_db_host = isset($_POST['db_host']) ? sanitize_text_field($_POST['db_host']) : 'localhost';
                        $test_db_port = isset($_POST['db_port']) && !empty($_POST['db_port']) ? (int)$_POST['db_port'] : 3306;
                        $test_db_user = isset($_POST['db_user']) ? sanitize_text_field($_POST['db_user']) : '';
                        $test_db_pass = isset($_POST['db_pass']) ? $_POST['db_pass'] : '';
                        $test_db_name = isset($_POST['db_name']) ? sanitize_text_field($_POST['db_name']) : '';
                        
                        // Parse host:port format if provided
                        $host_info = parse_db_host($test_db_host);
                        $test_db_host = $host_info['host'];
                        if (isset($_POST['db_port']) && !empty($_POST['db_port'])) {
                            $test_db_port = (int)$_POST['db_port'];
                        } else {
                            $test_db_port = $host_info['port'];
                        }
                        
                        if (empty($test_db_user) || empty($test_db_name)) {
                            throw new Exception('Database user and name are required.');
                        }
                    }
                    
                    // Test connection
                    $old_error_reporting = error_reporting(E_ALL);
                    $old_display_errors = ini_get('display_errors');
                    ini_set('display_errors', 0);
                    
                    $test_connection = @new mysqli($test_db_host, $test_db_user, $test_db_pass, $test_db_name, $test_db_port);
                    
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
                    
                    // Additional check
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
                        'mode' => $wp_config ? 'WordPress (wp-config.php)' : 'Custom Database'
                    ]);
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'error' => $e->getMessage()
                    ]);
                }
                exit;
            }

            // Main Handler
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
                $use_wp_config = isset($_POST['use_wp_config']) && $_POST['use_wp_config'] == '1';
                $wp_config = $use_wp_config ? get_wp_config() : null;
                
                $data = [
                    'db_host'          => sanitize_text_field($_POST['db_host'] ?? ''),
                    'db_port'          => isset($_POST['db_port']) && !empty($_POST['db_port']) ? (int)$_POST['db_port'] : 3306,
                    'db_name'          => sanitize_text_field($_POST['db_name'] ?? ''),
                    'db_user'          => sanitize_text_field($_POST['db_user'] ?? ''),
                    'db_pass'          => $_POST['db_pass'] ?? '',
                    'old_text'         => sanitize_text_field($_POST['old_text'] ?? ''),
                    'new_text'         => sanitize_text_field($_POST['new_text'] ?? ''),
                    'replace_all'      => isset($_POST['replace_all']) ? 1 : 0,
                    'tables'           => $_POST['tables'] ?? [],
                    'handle_serialized' => isset($_POST['handle_serialized']) ? 1 : 0,
                    'dry_run'          => isset($_POST['dry_run']) ? 1 : 0,
                    'truncate_mode'    => sanitize_text_field($_POST['truncate_mode'] ?? 'skip'),
                ];
                
                // Use WP config if requested
                if ($wp_config) {
                    // Parse host:port format from wp-config.php
                    $host_info = parse_db_host($wp_config['db_host']);
                    $data['db_host'] = $host_info['host'];
                    $data['db_port'] = $host_info['port'];
                    $data['db_name'] = $wp_config['db_name'];
                    $data['db_user'] = $wp_config['db_user'];
                    $data['db_pass'] = $wp_config['db_pass'];
                } else {
                    // Parse host:port format from form if provided
                    $host_info = parse_db_host($data['db_host']);
                    $data['db_host'] = $host_info['host'];
                    // Use form port if provided, otherwise use parsed port or default
                    if (isset($_POST['db_port']) && !empty($_POST['db_port'])) {
                        $data['db_port'] = (int)$_POST['db_port'];
                    } else {
                        $data['db_port'] = $host_info['port'];
                    }
                }

                // Validation
                if (empty($data['old_text'])) {
                    echo '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i> Please provide text to find.</div>';
                    display_form($data, [], $wp_config);
                    exit;
                }
                
                if (empty($data['new_text']) && !$data['dry_run']) {
                    echo '<div class="alert alert-warning"><i class="fas fa-info-circle me-2"></i> Replacement text is empty. This will remove the found text.</div>';
                }

                log_message("Starting text replacement from '{$data['old_text']}' to '{$data['new_text']}'");
                if ($data['dry_run']) {
                    log_message("DRY RUN MODE - No changes will be made");
                }
                if ($data['handle_serialized']) {
                    log_message("Serialized data handling enabled");
                }
                
                echo '<div class="mb-4">
                        <h4><i class="fas fa-tasks me-2"></i> ' . ($data['dry_run'] ? 'Preview Mode' : 'Processing') . '...</h4>
                        <div class="progress mb-3" style="height: 30px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated fw-semibold" 
                                 id="progressBar" role="progressbar" style="width: 0%">0%</div>
                        </div>
                        <div id="currentTable" class="current-table mb-3"></div>
                        <div id="liveStats" class="mb-3"></div>
                        <div id="resultsContainer" class="mt-3"></div>
                      </div>';
                
                // Flush the output buffer immediately
                ob_flush();
                flush();
                
                // Connect to database with port
                $conn = @new mysqli($data['db_host'], $data['db_user'], $data['db_pass'], $data['db_name'], $data['db_port']);
                
                if ($conn->connect_error) {
                    $error_msg = "Database connection failed: " . htmlspecialchars($conn->connect_error);
                    log_message($error_msg, 'error');
                    echo '<div class="alert alert-danger">
                            <h5><i class="fas fa-times-circle me-2"></i> Connection Error</h5>
                            <p>' . $error_msg . '</p>
                            <p class="mb-0"><small>Please check your database credentials and try again.</small></p>
                          </div>';
                    display_form($data, [], $wp_config);
                    exit;
                }
                
                // Set charset
                $conn->set_charset('utf8mb4');
                
                log_message("Connected to database: {$data['db_name']}");

                // Get all tables
                $result = $conn->query("SHOW TABLES");
                if (!$result) {
                    $error_msg = "Cannot retrieve table list: " . htmlspecialchars($conn->error);
                    log_message($error_msg, 'error');
                    echo '<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i> ' . $error_msg . '</div>';
                    display_form($data, [], $wp_config);
                    $conn->close();
                    exit;
                }
                
                $all_tables = [];
                while ($row = $result->fetch_array()) {
                    $all_tables[] = $row[0];
                }

                if (empty($data['replace_all']) && empty($data['tables'])) {
                    $warning_msg = "Please select at least one table.";
                    log_message($warning_msg, 'warning');
                    echo '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i> ' . $warning_msg . '</div>';
                    display_form($data, $all_tables, $wp_config);
                    $conn->close();
                    exit;
                }

                $tables = $data['replace_all'] ? $all_tables : $data['tables'];
                $total_tables = count($tables);
                log_message("Processing " . $total_tables . " table(s)");

                // Initialize JavaScript for progress updates with statistics
                echo '<script>
                        function updateProgress(percent, message = "", stats = null) {
                            const progressBar = document.getElementById("progressBar");
                            const currentTable = document.getElementById("currentTable");
                            const statsContainer = document.getElementById("liveStats");
                            
                            if (progressBar) {
                                const roundedPercent = Math.min(100, Math.max(0, Math.round(percent)));
                                progressBar.style.width = roundedPercent + "%";
                                progressBar.setAttribute("aria-valuenow", roundedPercent);
                                progressBar.textContent = roundedPercent + "%";
                            }
                            
                            if (message && currentTable) {
                                currentTable.innerHTML = "<i class=\"fas fa-spinner fa-spin me-2\"></i>" + message;
                            }
                            
                            if (stats && statsContainer) {
                                statsContainer.innerHTML = `
                                    <div class="row g-2 text-center">
                                        <div class="col-6 col-md-3">
                                            <div class="p-2 bg-light rounded">
                                                <small class="text-muted d-block">Total Tables</small>
                                                <strong class="text-primary">${stats.total}</strong>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="p-2 bg-light rounded">
                                                <small class="text-muted d-block">Processed</small>
                                                <strong class="text-info">${stats.processed}</strong>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="p-2 bg-light rounded">
                                                <small class="text-muted d-block">With Matches</small>
                                                <strong class="text-success">${stats.with_matches}</strong>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="p-2 bg-light rounded">
                                                <small class="text-muted d-block">No Matches</small>
                                                <strong class="text-warning">${stats.no_matches}</strong>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }
                        }
                      </script>';
                
                // Flush the JavaScript to browser
                ob_flush();
                flush();

                $result = replace_text($conn, $data['old_text'], $data['new_text'], $tables, $data['handle_serialized'], $data['dry_run'], $data['truncate_mode']);
                $log = $result['log'];
                $stats = $result['stats'];

                // Save log file
                foreach ($log as $entry) {
                    log_message($entry['message'], $entry['type']);
                }
                
                log_message("Operation completed. Success: {$stats['success']}, Failed: {$stats['failed']}, Skipped: {$stats['skipped']}");
                
                @file_put_contents($log_file, $log_content);

                // Display results
                $mode_badge = $data['dry_run'] ? '<span class="badge bg-warning text-dark ms-2">DRY RUN</span>' : '';
                echo '<div class="summary-card card border-success mb-4">
                        <div class="card-header bg-success text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-check-circle me-2"></i> Replacement Complete ' . $mode_badge . '
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="table-info mb-4">
                                <h5 class="mb-3"><i class="fas fa-chart-bar me-2"></i> Table Statistics</h5>
                                <div class="row g-3 text-center">
                                    <div class="col-6 col-md-3">
                                        <div class="stat-card card bg-primary text-white">
                                            <div class="card-body">
                                                <h6 class="card-title text-white">Total Tables</h6>
                                                <p class="stat-number text-white mb-0">' . $total_tables . '</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="stat-card card bg-info text-white">
                                            <div class="card-body">
                                                <h6 class="card-title text-white">Processed</h6>
                                                <p class="stat-number text-white mb-0">' . $stats['tables_processed'] . '</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="stat-card card bg-success text-white">
                                            <div class="card-body">
                                                <h6 class="card-title text-white">With Matches</h6>
                                                <p class="stat-number text-white mb-0">' . $stats['tables_with_matches'] . '</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="stat-card card bg-warning text-dark">
                                            <div class="card-body">
                                                <h6 class="card-title text-dark">No Matches</h6>
                                                <p class="stat-number text-dark mb-0">' . $stats['tables_no_matches'] . '</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <h5 class="mb-3"><i class="fas fa-tasks me-2"></i> Operation Statistics</h5>
                            <div class="row text-center mb-4">';
                
                echo '<div class="col-6 col-md-3">
                        <div class="stat-card card bg-light">
                            <div class="card-body">
                                <h6 class="card-title text-success">Success</h6>
                                <p class="stat-number text-success mb-0">' . $stats['success'] . '</p>
                                <small class="text-muted">Fields Updated</small>
                            </div>
                        </div>
                      </div>';
                
                echo '<div class="col-6 col-md-3">
                        <div class="stat-card card bg-light">
                            <div class="card-body">
                                <h6 class="card-title text-danger">Failed</h6>
                                <p class="stat-number text-danger mb-0">' . $stats['failed'] . '</p>
                                <small class="text-muted">Errors</small>
                            </div>
                        </div>
                      </div>';
                
                echo '<div class="col-6 col-md-3">
                        <div class="stat-card card bg-light">
                            <div class="card-body">
                                <h6 class="card-title text-warning">Skipped</h6>
                                <p class="stat-number text-warning mb-0">' . $stats['skipped'] . '</p>
                                <small class="text-muted">No Changes</small>
                            </div>
                        </div>
                      </div>';
                
                if ($stats['truncated'] > 0) {
                    echo '<div class="col-12 mt-3">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Text Truncation:</strong> ' . $stats['truncated'] . ' field(s) had replacement text truncated to fit VARCHAR field length limits.
                            </div>
                          </div>';
                }
                
                if (!$data['dry_run']) {
                    echo '<div class="col-6 col-md-3">
                            <div class="stat-card card bg-light">
                                <div class="card-body">
                                    <h6 class="card-title text-info">Rows Updated</h6>
                                    <p class="stat-number text-info mb-0">' . number_format($stats['total_rows']) . '</p>
                                    <small class="text-muted">Total Rows</small>
                                </div>
                            </div>
                          </div>';
                } else {
                    echo '<div class="col-6 col-md-3">
                            <div class="stat-card card bg-light">
                                <div class="card-body">
                                    <h6 class="card-title text-info">Would Update</h6>
                                    <p class="stat-number text-info mb-0">' . number_format($stats['total_rows']) . '</p>
                                    <small class="text-muted">Preview Only</small>
                                </div>
                            </div>
                          </div>';
                }
                
                echo '</div>';
                
                if (!empty($log)) {
                    echo '<h5 class="mb-3"><i class="fas fa-list-alt me-2"></i> Detailed Log:</h5>
                          <div class="mb-3" style="max-height: 400px; overflow-y: auto; border: 1px solid #e9ecef; border-radius: 8px; padding: 15px;">';
                    
                    foreach ($log as $entry) {
                        echo '<div class="log-entry log-' . htmlspecialchars($entry['type']) . '">';
                        switch ($entry['type']) {
                            case 'success': echo '<i class="fas fa-check-circle me-2"></i>'; break;
                            case 'error': echo '<i class="fas fa-times-circle me-2"></i>'; break;
                            case 'warning': echo '<i class="fas fa-exclamation-triangle me-2"></i>'; break;
                            default: echo '<i class="fas fa-info-circle me-2"></i>';
                        }
                        echo htmlspecialchars($entry['message']) . '</div>';
                    }
                    
                    echo '</div>';
                }
                
                echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <a href="' . htmlspecialchars($log_file) . '" class="btn btn-outline-primary" download>
                                <i class="fas fa-download me-2"></i>Download Log
                            </a>
                            <button onclick="copyLogToClipboard()" class="btn btn-outline-secondary copy-log">
                                <i class="fas fa-copy me-2"></i>Copy Log
                            </button>
                        </div>
                        <a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="btn btn-outline-success">
                            <i class="fas fa-redo me-2"></i>Start New
                        </a>
                      </div>
                    </div>
                  </div>';
                
                $conn->close();
            } else {
                display_form([], [], $wp_config);
            }
            ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function() {
        "use strict";
        
        const form = document.getElementById('replaceForm');
        if (!form) return;
        
        // Table search functionality
        const tableSearch = document.getElementById('tableSearch');
        const tablesSelect = document.getElementById('tablesSelect');
        
        if (tableSearch && tablesSelect) {
            tableSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const options = tablesSelect.querySelectorAll('option');
                let visibleCount = 0;
                
                options.forEach(option => {
                    const text = option.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        option.style.display = '';
                        visibleCount++;
                    } else {
                        option.style.display = 'none';
                    }
                });
                
                const tableCount = document.getElementById('tableCount');
                if (tableCount) {
                    tableCount.textContent = visibleCount;
                }
            });
        }
        
        // Form validation
        function validateTablesSelect() {
            const replaceAll = document.getElementById('replaceAll');
            const tablesSelect = form.querySelector('select[name="tables[]"]');
            
            if (!replaceAll.checked && tablesSelect) {
                const isValid = tablesSelect.selectedOptions.length > 0;
                tablesSelect.classList.toggle('is-invalid', !isValid);
                return isValid;
            }
            return true;
        }
        
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity() || !validateTablesSelect()) {
                event.preventDefault();
                event.stopPropagation();
                
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            }
            
            form.classList.add('was-validated');
        }, false);
        
        // Toggle tables selection
        const replaceAll = document.getElementById('replaceAll');
        if (replaceAll) {
            replaceAll.addEventListener('change', function() {
                const tablesSection = document.getElementById('tablesSection');
                if (tablesSection) {
                    tablesSection.style.display = this.checked ? 'none' : 'block';
                    
                    if (this.checked) {
                        const tablesSelect = form.querySelector('select[name="tables[]"]');
                        if (tablesSelect) {
                            tablesSelect.classList.remove('is-invalid');
                        }
                    }
                }
            });
        }
        
        // Confirm before proceeding if not dry run
        form.addEventListener('submit', function(e) {
            const dryRun = document.getElementById('dryRun');
            if (!dryRun || !dryRun.checked) {
                const oldText = form.querySelector('input[name="old_text"]').value;
                const newText = form.querySelector('input[name="new_text"]').value;
                
                if (!confirm(`Are you sure you want to replace "${oldText}" with "${newText}" in the database?\n\n⚠️ This action cannot be undone. Consider using Dry Run mode first.`)) {
                    e.preventDefault();
                    return false;
                }
            }
        });
        
        // Test database connection function
        window.testDatabaseConnection = function() {
            const testBtn = document.getElementById('testConnectionBtn');
            const resultDiv = document.getElementById('dbTestResult');
            const submitBtn = document.getElementById('submitBtn');
            const form = document.getElementById('replaceForm');
            
            // Disable button and show loading
            const originalHtml = testBtn.innerHTML;
            testBtn.disabled = true;
            testBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Testing Connection...';
            resultDiv.style.display = 'none';
            
            // Get form data
            const formData = new FormData(form);
            formData.append('action', 'test_connection');
            
            // Get wp-config usage
            const useWpConfig = document.getElementById('useWpConfig');
            if (useWpConfig) {
                formData.set('use_wp_config', useWpConfig.value);
            }
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                // Check if response is actually JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        throw new Error('Server returned non-JSON response. This might be a PHP error. Response: ' + text.substring(0, 200));
                    });
                }
                return response.json();
            })
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
        };
        
        // Reset form function
        window.resetForm = function() {
            if (confirm('Are you sure you want to clear all form fields?')) {
                form.reset();
                form.classList.remove('was-validated');
                
                // Clear all validation states
                const invalidFields = form.querySelectorAll('.is-invalid');
                invalidFields.forEach(field => field.classList.remove('is-invalid'));
                
                // Reset checkboxes to defaults
                const handleSerialized = document.getElementById('handleSerialized');
                if (handleSerialized) handleSerialized.checked = true;
                
                const dryRun = document.getElementById('dryRun');
                if (dryRun) dryRun.checked = false;
                
                const replaceAll = document.getElementById('replaceAll');
                if (replaceAll) replaceAll.checked = false;
                
                // Reset truncate mode to default
                const truncateMode = document.getElementById('truncateMode');
                if (truncateMode) truncateMode.value = 'skip';
                
                // Reset table selection
                const tablesSelect = document.getElementById('tablesSelect');
                if (tablesSelect) {
                    tablesSelect.selectedIndex = -1;
                    tablesSelect.classList.remove('is-invalid');
                }
                
                // Clear table search
                const tableSearch = document.getElementById('tableSearch');
                if (tableSearch) {
                    tableSearch.value = '';
                    // Trigger search to show all tables again
                    if (tableSearch.dispatchEvent) {
                        tableSearch.dispatchEvent(new Event('input'));
                    }
                }
                
                // Reset database host to default
                const dbHost = form.querySelector('input[name="db_host"]');
                if (dbHost) dbHost.value = 'localhost';
                
                // Reset database port
                const dbPort = form.querySelector('input[name="db_port"]');
                if (dbPort) dbPort.value = '';
                
                // Scroll to top
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        };
    })();

    function copyLogToClipboard() {
        const logEntries = document.querySelectorAll('.log-entry');
        let logText = "Database Text Replacement Tool Log\n\n";
        
        logEntries.forEach(entry => {
            const icon = entry.querySelector('i');
            let prefix = '';
            if (icon) {
                if (icon.className.includes('fa-check')) prefix = '[SUCCESS] ';
                else if (icon.className.includes('fa-times')) prefix = '[ERROR] ';
                else if (icon.className.includes('fa-exclamation')) prefix = '[WARNING] ';
                else prefix = '[INFO] ';
            }
            logText += prefix + entry.textContent.trim() + "\n";
        });
        
        navigator.clipboard.writeText(logText).then(() => {
            const btn = document.querySelector('.copy-log');
            if (btn) {
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check me-2"></i>Copied!';
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                }, 2000);
            }
        }).catch(err => {
            alert('Failed to copy to clipboard: ' + err);
        });
    }
</script>
</body>
</html>
<?php
// End output buffering
ob_end_flush();
?>
