<?php
/**
 * Archive Manager - ZIP & GZIP Utility
 * 
 * A comprehensive web-based archive management tool that provides functionality
 * for extracting and compressing archive files. This utility supports both ZIP
 * and GZIP formats with a user-friendly web interface.
 * 
 * Features:
 * - Extract ZIP archives to specified directories
 * - Extract GZIP compressed files
 * - Compress files to ZIP format
 * - Compress files to GZIP format
 * - Automatic folder name suggestions based on archive name
 * - Directory permission checking and automatic fixing
 * - Disk space validation before extraction
 * - Real-time compression ratio reporting
 * - Environment information display (PHP version, extensions, permissions)
 * 
 * Requirements:
 * - PHP 5.6+ (PHP 7.0+ recommended)
 * - ZipArchive extension (for ZIP operations)
 * - Zlib extension (for GZIP operations)
 * - Write permissions on the directory where the script is located
 * 
 * Security Features:
 * - Input validation and sanitization
 * - Directory traversal protection
 * - Filename character restrictions (alphanumeric, dots, underscores, hyphens only)
 * - File size and name length validation
 * 
 * Usage:
 * 1. Place this file in the directory containing your archive files
 * 2. Access via web browser
 * 3. Select extraction or compression mode
 * 4. Choose files and provide destination folder/output filename
 * 5. Process the archive
 * 
 * @package    ArchiveManager
 * @category   Utility
 * @author     Sagar G C
 * @authorURI  https://tulipstechnologies.com
 * @company    Tulips Technologies Pvt. Ltd.
 * @version    1.0
 * @copyright  Copyright (c) 2024 Tulips Technologies Pvt. Ltd.
 * @license    MIT License
 */

// Set content type and styles for UI
header('Content-Type: text/html; charset=utf-8');

// Set execution time limit for large ZIP files
set_time_limit(300); // 5 minutes

// Set memory limit for large extractions
ini_set('memory_limit', '256M');

// Disable output buffering for real-time feedback
if (ob_get_level()) {
    ob_end_clean();
}

// Function to get all archive files (ZIP and GZIP) in current directory
function getArchiveFiles() {
    $archiveFiles = [];
    $files = scandir(__DIR__);
    foreach ($files as $file) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($extension, ['zip', 'gz'])) {
            $archiveFiles[] = $file;
        }
    }
    return $archiveFiles;
}

// Function to get existing directories
function getExistingDirectories() {
    $directories = [];
    $items = scandir(__DIR__);
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..' && is_dir(__DIR__ . DIRECTORY_SEPARATOR . $item)) {
            $directories[] = $item;
        }
    }
    return $directories;
}

// Function to get files that can be compressed
function getCompressibleFiles() {
    $files = [];
    $items = scandir(__DIR__);
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..' && is_file(__DIR__ . DIRECTORY_SEPARATOR . $item)) {
            $extension = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            // Exclude already compressed files and PHP files
            if (!in_array($extension, ['zip', 'gz', 'php', 'html', 'css', 'js'])) {
                $files[] = $item;
            }
        }
    }
    return $files;
}

// Function to extract GZIP file
function extractGzipFile($gzFile, $extractDir) {
    $gz = gzopen($gzFile, 'rb');
    if (!$gz) {
        return false;
    }
    
    // Determine output filename (remove .gz extension)
    $outputFile = $extractDir . DIRECTORY_SEPARATOR . basename($gzFile, '.gz');
    
    $output = fopen($outputFile, 'wb');
    if (!$output) {
        gzclose($gz);
        return false;
    }
    
    while (!gzeof($gz)) {
        fwrite($output, gzread($gz, 8192));
    }
    
    gzclose($gz);
    fclose($output);
    return true;
}

// Function to create GZIP file
function createGzipFile($sourceFile, $gzFile) {
    $fp_in = fopen($sourceFile, 'rb');
    if (!$fp_in) {
        return false;
    }
    
    $fp_out = gzopen($gzFile, 'wb9');
    if (!$fp_out) {
        fclose($fp_in);
        return false;
    }
    
    while (!feof($fp_in)) {
        gzwrite($fp_out, fread($fp_in, 8192));
    }
    
    fclose($fp_in);
    gzclose($fp_out);
    return true;
}

// Function to fix directory permissions recursively
function fixDirectoryPermissions($dir, $permissions = 0755) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $success = true;
    
    // Fix current directory
    if (!@chmod($dir, $permissions)) {
        $success = false;
    }
    
    // Fix subdirectories recursively
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..') {
            $itemPath = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                if (!fixDirectoryPermissions($itemPath, $permissions)) {
                    $success = false;
                }
            }
        }
    }
    
    return $success;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Archive Manager - ZIP & GZIP Utility</title>
<style>
  body { font-family: Arial, sans-serif; background: #f9f9f9; color: #333; padding: 20px; }
  .container { max-width: 700px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1);}
  h1 { color: #2c3e50; }
  .success { color: #27ae60; font-weight: bold; }
  .error { color: #c0392b; font-weight: bold; }
  .info { color: #2980b9; }
  label { display: block; margin-top: 15px; font-weight: bold; }
  input[type=text], select { width: 100%; padding: 8px; margin-top: 5px; box-sizing: border-box; border: 1px solid #ddd; border-radius: 4px; }
  input[type=submit] { margin-top: 20px; padding: 10px 15px; font-size: 16px; cursor: pointer; background: #3498db; color: white; border: none; border-radius: 4px; }
  input[type=submit]:hover { background: #2980b9; }
  pre { background: #eee; padding: 10px; border-radius: 4px; overflow-x: auto; }
  .mode-selector { margin-bottom: 20px; }
  .mode-selector input[type=radio] { margin-right: 10px; }
  .mode-selector label { display: inline; margin-right: 20px; font-weight: normal; }
  .form-group { margin-bottom: 20px; }
  .form-row { display: flex; gap: 10px; }
  .form-row .form-group { flex: 1; }
  .suggestion { font-size: 12px; color: #666; margin-top: 5px; }
  .existing-folders { margin-top: 10px; }
  .folder-option { padding: 5px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 3px; margin: 2px 0; cursor: pointer; }
  .folder-option:hover { background: #e9ecef; }
  .file-option { padding: 5px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 3px; margin: 2px 0; cursor: pointer; }
  .file-option:hover { background: #e9ecef; }
  .compressible-files { margin-top: 10px; }
</style>
<script>
function updateFolderSuggestion() {
    const archiveSelect = document.getElementById('archivefile');
    const folderInput = document.getElementById('extractfolder');
    const suggestionDiv = document.getElementById('suggestion');
    
    if (archiveSelect.value) {
        // Remove extension and suggest folder name
        const suggestedName = archiveSelect.value.replace(/\.(zip|gz)$/i, '');
        folderInput.value = suggestedName;
        suggestionDiv.innerHTML = `💡 Suggested folder name: <strong>${suggestedName}</strong>`;
    } else {
        folderInput.value = '';
        suggestionDiv.innerHTML = '';
    }
}

function selectExistingFolder(folderName) {
    document.getElementById('extractfolder').value = folderName;
}

function toggleMode() {
    const mode = document.querySelector('input[name="mode"]:checked').value;
    const archiveSection = document.getElementById('archive-section');
    const compressSection = document.getElementById('compress-section');
    
    if (mode === 'extract') {
        archiveSection.style.display = 'block';
        compressSection.style.display = 'none';
        // Set required attributes for extract mode
        document.getElementById('archivefile').required = true;
        document.getElementById('extractfolder').required = true;
        // Remove required attributes from compress mode
        document.getElementById('sourcefile').required = false;
        document.getElementById('outputfile').required = false;
    } else {
        archiveSection.style.display = 'none';
        compressSection.style.display = 'block';
        // Remove required attributes from extract mode
        document.getElementById('archivefile').required = false;
        document.getElementById('extractfolder').required = false;
        // Set required attributes for compress mode
        document.getElementById('sourcefile').required = true;
        document.getElementById('outputfile').required = true;
    }
}

function selectFileToCompress(fileName) {
    document.getElementById('sourcefile').value = fileName;
}

// Form validation function
function validateForm() {
    const mode = document.querySelector('input[name="mode"]:checked').value;
    
    if (mode === 'extract') {
        const archiveFile = document.getElementById('archivefile').value;
        const extractFolder = document.getElementById('extractfolder').value;
        
        if (!archiveFile) {
            alert('Please select an archive file to extract.');
            return false;
        }
        if (!extractFolder) {
            alert('Please enter an extraction folder name.');
            return false;
        }
    } else if (mode === 'compress') {
        const sourceFile = document.getElementById('sourcefile').value;
        const outputFile = document.getElementById('outputfile').value;
        
        if (!sourceFile) {
            alert('Please select a file to compress.');
            return false;
        }
        if (!outputFile) {
            alert('Please enter an output file name.');
            return false;
        }
    }
    
    return true;
}

// Initialize form when page loads
document.addEventListener('DOMContentLoaded', function() {
    toggleMode(); // Set initial required attributes
    
    // Add form validation on submit
    const form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        if (!validateForm()) {
            e.preventDefault();
        }
    });
});
</script>
</head>
<body>
<div class="container">
<h1>Archive Manager - ZIP & GZIP Utility</h1>

<?php
function showMessage($msg, $type = 'info') {
    echo "<p class=\"$type\">$msg</p>";
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $mode = $_POST['mode'] ?? 'extract';
    
    if ($mode === 'extract') {
        // EXTRACTION MODE
    if (!class_exists('ZipArchive')) {
        showMessage('❌ <strong>ZipArchive</strong> PHP extension is <strong>not enabled</strong> on this server.', 'error');
        exit;
    }

    // Sanitize input values
        $archiveFileInput = trim($_POST['archivefile'] ?? '');
    $extractFolderInput = trim($_POST['extractfolder'] ?? '');

    // Enhanced validation: no empty, no directory traversal, and safe characters only
        if ($archiveFileInput === '' || $extractFolderInput === '') {
            showMessage('❌ Please provide both archive file name and target extraction folder name.', 'error');
        } elseif (strpos($archiveFileInput, '..') !== false || strpos($extractFolderInput, '..') !== false) {
        showMessage('❌ Invalid input: directory traversal is not allowed.', 'error');
        } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $archiveFileInput) || !preg_match('/^[a-zA-Z0-9._-]+$/', $extractFolderInput)) {
        showMessage('❌ Invalid input: only letters, numbers, dots, underscores, and hyphens are allowed.', 'error');
        } elseif (strlen($archiveFileInput) > 255 || strlen($extractFolderInput) > 255) {
        showMessage('❌ Invalid input: file and folder names must be less than 255 characters.', 'error');
    } else {
        // Prepare full paths relative to this script folder
            $archiveFile = __DIR__ . DIRECTORY_SEPARATOR . $archiveFileInput;
        $extractDir = __DIR__ . DIRECTORY_SEPARATOR . $extractFolderInput;

            // Check archive file existence
            if (!file_exists($archiveFile)) {
                showMessage("❌ Archive file <code>{$archiveFileInput}</code> not found.", 'error');
                echo '<p>Please upload the archive file in the same folder as this script.</p>';
        } else {
                showMessage("📦 Found archive file: <code>{$archiveFileInput}</code>", 'info');

            // Check or create extraction folder
            if (!is_dir($extractDir)) {
                if (@mkdir($extractDir, 0755, true)) {
                    showMessage("✅ Created extraction directory: <code>{$extractFolderInput}</code>", 'success');
                } else {
                    showMessage("❌ Failed to create extraction directory <code>{$extractFolderInput}</code>. Check permissions.", 'error');
                    exit;
                }
            } else {
                showMessage("ℹ️ Extraction directory <code>{$extractFolderInput}</code> already exists.", 'info');
            }

            // Check writable and attempt to fix permissions if needed
            if (!is_writable($extractDir)) {
                showMessage("⚠️ Extraction directory <code>{$extractFolderInput}</code> is not writable by PHP.", 'error');
                showMessage("🔧 Attempting to fix permissions...", 'info');
                
                // Try to fix permissions recursively
                if (fixDirectoryPermissions($extractDir, 0755)) {
                    showMessage("✅ Successfully fixed permissions for <code>{$extractFolderInput}</code> and subdirectories.", 'success');
                } else {
                    showMessage("❌ Failed to fix permissions. Please manually set folder permissions to 755 or contact hosting support.", 'error');
                    echo '<p><strong>Manual fix:</strong> Set folder permissions to 755 (read/write/execute for owner, read/execute for group and others).</p>';
                    echo '<p><strong>Command line:</strong> <code>chmod -R 755 ' . htmlspecialchars($extractFolderInput) . '</code></p>';
                exit;
                }
            } else {
                showMessage("✅ Extraction directory <code>{$extractFolderInput}</code> is writable.", 'success');
            }

                // Extract archive with better error handling
                $extension = strtolower(pathinfo($archiveFileInput, PATHINFO_EXTENSION));
                
                if ($extension === 'zip') {
                    // Handle ZIP extraction
            $zip = new ZipArchive;
                    $res = $zip->open($archiveFile);
            if ($res === TRUE) {
                $fileCount = $zip->numFiles;
                showMessage("📊 ZIP contains {$fileCount} files/folders", 'info');
                
                // Check available disk space
                $freeSpace = disk_free_space($extractDir);
                        $archiveSize = filesize($archiveFile);
                        if ($freeSpace !== false && $freeSpace < ($archiveSize * 2)) {
                            showMessage("⚠️ Warning: Low disk space. Archive size: " . formatBytes($archiveSize) . ", Available: " . formatBytes($freeSpace), 'error');
                }
                
                if ($zip->extractTo($extractDir)) {
                    $zip->close();
                            showMessage("🎉 Successfully extracted <code>{$archiveFileInput}</code> into <code>{$extractFolderInput}/</code>.", 'success');
                            showMessage("You can delete the archive file if you want to save space.", 'info');
                } else {
                    $zip->close();
                    showMessage("❌ Extraction failed. Possibly permission issue or disk space.", 'error');
                }
            } else {
                $errorMessages = [
                    ZipArchive::ER_OK => 'No error',
                    ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
                    ZipArchive::ER_RENAME => 'Renaming temporary file failed',
                    ZipArchive::ER_CLOSE => 'Closing zip archive failed',
                    ZipArchive::ER_SEEK => 'Seek error',
                    ZipArchive::ER_READ => 'Read error',
                    ZipArchive::ER_WRITE => 'Write error',
                    ZipArchive::ER_CRC => 'CRC error',
                    ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
                    ZipArchive::ER_NOENT => 'No such file',
                    ZipArchive::ER_EXISTS => 'File already exists',
                    ZipArchive::ER_OPEN => 'Can\'t open file',
                    ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
                    ZipArchive::ER_ZLIB => 'Zlib error',
                    ZipArchive::ER_MEMORY => 'Memory allocation failure',
                    ZipArchive::ER_CHANGED => 'Entry has been changed',
                    ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
                    ZipArchive::ER_EOF => 'Premature EOF',
                    ZipArchive::ER_INVAL => 'Invalid argument',
                    ZipArchive::ER_NOZIP => 'Not a zip archive',
                    ZipArchive::ER_INTERNAL => 'Internal error',
                    ZipArchive::ER_INCONS => 'Zip archive inconsistent',
                    ZipArchive::ER_REMOVE => 'Can\'t remove file',
                    ZipArchive::ER_DELETED => 'Entry has been deleted'
                ];
                
                $errorMsg = isset($errorMessages[$res]) ? $errorMessages[$res] : "Unknown error";
                showMessage("❌ Failed to open ZIP file. Error: {$errorMsg} (Code: {$res})", 'error');
                    }
                } elseif ($extension === 'gz') {
                    // Handle GZIP extraction
                    if (extractGzipFile($archiveFile, $extractDir)) {
                        showMessage("🎉 Successfully extracted <code>{$archiveFileInput}</code> into <code>{$extractFolderInput}/</code>.", 'success');
                        showMessage("You can delete the GZIP file if you want to save space.", 'info');
                    } else {
                        showMessage("❌ GZIP extraction failed. Possibly permission issue or corrupted file.", 'error');
                    }
                } else {
                    showMessage("❌ Unsupported archive format. Only ZIP and GZIP files are supported.", 'error');
                }
            }
        }
    } elseif ($mode === 'compress') {
        // COMPRESSION MODE
        $sourceFileInput = trim($_POST['sourcefile'] ?? '');
        $outputFileInput = trim($_POST['outputfile'] ?? '');
        $compressionType = $_POST['compressiontype'] ?? 'gzip';
        
        // Validation
        if ($sourceFileInput === '' || $outputFileInput === '') {
            showMessage('❌ Please provide both source file name and output file name.', 'error');
        } elseif (strpos($sourceFileInput, '..') !== false || strpos($outputFileInput, '..') !== false) {
            showMessage('❌ Invalid input: directory traversal is not allowed.', 'error');
        } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $sourceFileInput) || !preg_match('/^[a-zA-Z0-9._-]+$/', $outputFileInput)) {
            showMessage('❌ Invalid input: only letters, numbers, dots, underscores, and hyphens are allowed.', 'error');
        } else {
            $sourceFile = __DIR__ . DIRECTORY_SEPARATOR . $sourceFileInput;
            $outputFile = __DIR__ . DIRECTORY_SEPARATOR . $outputFileInput;
            
            if (!file_exists($sourceFile)) {
                showMessage("❌ Source file <code>{$sourceFileInput}</code> not found.", 'error');
            } else {
                showMessage("📁 Found source file: <code>{$sourceFileInput}</code>", 'info');
                
                if ($compressionType === 'gzip') {
                    if (createGzipFile($sourceFile, $outputFile)) {
                        $originalSize = filesize($sourceFile);
                        $compressedSize = filesize($outputFile);
                        $ratio = round((1 - $compressedSize / $originalSize) * 100, 1);
                        showMessage("🎉 Successfully compressed <code>{$sourceFileInput}</code> to <code>{$outputFileInput}</code>.", 'success');
                        showMessage("📊 Compression ratio: {$ratio}% (Original: " . formatBytes($originalSize) . ", Compressed: " . formatBytes($compressedSize) . ")", 'info');
                    } else {
                        showMessage("❌ GZIP compression failed. Check permissions and disk space.", 'error');
                    }
                } elseif ($compressionType === 'zip') {
                    if (!class_exists('ZipArchive')) {
                        showMessage('❌ <strong>ZipArchive</strong> PHP extension is <strong>not enabled</strong> on this server.', 'error');
                    } else {
                        $zip = new ZipArchive;
                        if ($zip->open($outputFile, ZipArchive::CREATE) === TRUE) {
                            $zip->addFile($sourceFile, basename($sourceFile));
                            $zip->close();
                            $originalSize = filesize($sourceFile);
                            $compressedSize = filesize($outputFile);
                            $ratio = round((1 - $compressedSize / $originalSize) * 100, 1);
                            showMessage("🎉 Successfully created ZIP <code>{$outputFileInput}</code> from <code>{$sourceFileInput}</code>.", 'success');
                            showMessage("📊 Compression ratio: {$ratio}% (Original: " . formatBytes($originalSize) . ", Compressed: " . formatBytes($compressedSize) . ")", 'info');
                        } else {
                            showMessage("❌ Failed to create ZIP file. Check permissions and disk space.", 'error');
                        }
                    }
                }
            }
        }
    }

    echo '<hr><p><a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">Process another file</a></p>';

} else {
    // Show environment info and input form
    ?>
    <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
        <h3 style="margin-top: 0;">Environment Information</h3>
        <p><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></p>
        <p><strong>ZipArchive:</strong> <?php echo class_exists('ZipArchive') ? '✅ Available' : '❌ Not Available'; ?></p>
        <p><strong>GZIP Support:</strong> <?php echo function_exists('gzopen') ? '✅ Available' : '❌ Not Available'; ?></p>
        <p><strong>Memory Limit:</strong> <?php echo ini_get('memory_limit'); ?></p>
        <p><strong>Max Execution Time:</strong> <?php echo ini_get('max_execution_time'); ?> seconds</p>
        <p><strong>Current Directory:</strong> <code><?php echo __DIR__; ?></code></p>
        <p><strong>Free Disk Space:</strong> <?php 
            $freeSpace = disk_free_space(__DIR__);
            echo $freeSpace !== false ? formatBytes($freeSpace) : 'Unknown';
        ?></p>
        <p><strong>Current Directory Permissions:</strong> <?php 
            $perms = fileperms(__DIR__);
            echo substr(sprintf('%o', $perms), -4);
        ?> (<?php echo is_writable(__DIR__) ? '✅ Writable' : '❌ Not Writable'; ?>)</p>
        
        <?php if (!is_writable(__DIR__)): ?>
        <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 10px; margin-top: 10px;">
            <strong>⚠️ Permission Issue Detected:</strong><br>
            The current directory is not writable. This may cause extraction issues.<br>
            <strong>Solutions:</strong>
            <ul style="margin: 5px 0; padding-left: 20px;">
                <li>Contact your hosting provider to fix permissions</li>
                <li>Use FTP/cPanel to set directory permissions to 755</li>
                <li>Run: <code>chmod 755 <?php echo basename(__DIR__); ?></code></li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
    
    <form method="post" action="">
        <div class="mode-selector">
            <label><input type="radio" name="mode" value="extract" checked onchange="toggleMode()"> Extract Archive</label>
            <label><input type="radio" name="mode" value="compress" onchange="toggleMode()"> Compress File</label>
        </div>

        <div id="archive-section">
            <div class="form-group">
                <label for="archivefile">Select archive file:</label>
                <select name="archivefile" id="archivefile" onchange="updateFolderSuggestion()" required>
                    <option value="">-- Select an archive file --</option>
                    <?php
                    $archiveFiles = getArchiveFiles();
                    if (empty($archiveFiles)) {
                        echo '<option value="" disabled>No archive files found in directory</option>';
                    } else {
                        foreach ($archiveFiles as $archiveFile) {
                            $fileSize = file_exists($archiveFile) ? formatBytes(filesize($archiveFile)) : 'Unknown size';
                            echo "<option value=\"{$archiveFile}\">{$archiveFile} ({$fileSize})</option>";
                        }
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
        <label for="extractfolder">Extraction folder name:</label>
                <input type="text" name="extractfolder" id="extractfolder" placeholder="Enter folder name" required />
                <div id="suggestion" class="suggestion"></div>
            </div>

            <?php
            $existingDirs = getExistingDirectories();
            if (!empty($existingDirs)) {
                echo '<div class="existing-folders">';
                echo '<label>Or select existing folder:</label>';
                echo '<div style="max-height: 150px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; padding: 10px; background: #f8f9fa;">';
                foreach ($existingDirs as $dir) {
                    echo "<div class=\"folder-option\" onclick=\"selectExistingFolder('{$dir}')\">📁 {$dir}</div>";
                }
                echo '</div>';
                echo '</div>';
            }
            ?>
        </div>

        <div id="compress-section" style="display: none;">
            <div class="form-group">
                <label for="sourcefile">Select file to compress:</label>
                <select name="sourcefile" id="sourcefile">
                    <option value="">-- Select a file to compress --</option>
                    <?php
                    $compressibleFiles = getCompressibleFiles();
                    if (empty($compressibleFiles)) {
                        echo '<option value="" disabled>No compressible files found in directory</option>';
                    } else {
                        foreach ($compressibleFiles as $file) {
                            $fileSize = file_exists($file) ? formatBytes(filesize($file)) : 'Unknown size';
                            echo "<option value=\"{$file}\">{$file} ({$fileSize})</option>";
                        }
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label for="compressiontype">Compression type:</label>
                <select name="compressiontype" id="compressiontype">
                    <option value="gzip">GZIP (.gz)</option>
                    <option value="zip">ZIP (.zip)</option>
                </select>
            </div>

            <div class="form-group">
                <label for="outputfile">Output file name:</label>
                <input type="text" name="outputfile" id="outputfile" placeholder="Enter output filename" />
            </div>

            <?php
            if (!empty($compressibleFiles)) {
                echo '<div class="compressible-files">';
                echo '<label>Or click a file to select:</label>';
                echo '<div style="max-height: 150px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; padding: 10px; background: #f8f9fa;">';
                foreach ($compressibleFiles as $file) {
                    $fileSize = file_exists($file) ? formatBytes(filesize($file)) : 'Unknown size';
                    echo "<div class=\"file-option\" onclick=\"selectFileToCompress('{$file}')\">📄 {$file} ({$fileSize})</div>";
                }
                echo '</div>';
                echo '</div>';
            }
            ?>
        </div>

        <input type="submit" value="Process File" />
    </form>
    <?php
}
?>

</div>
</body>
</html>