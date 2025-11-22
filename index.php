<!-- index.php -->
<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Configuration
define('UPLOAD_DIR', 'uploads/');
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB
define('MAX_STORAGE', 400 * 1024 * 1024); // 400MB
define('MAX_DOWNLOADS', 3);
define('EXPIRY_HOURS_LARGE', 48);      // Files >= 2MB
define('EXPIRY_HOURS_MEDIUM', 168);    // Files < 2MB (1 week)
define('EXPIRY_HOURS_SMALL', 720);     // Files < 1MB (1 month)

// Create uploads directory if it doesn't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

// Database file
$db_file = 'files.json';
if (!file_exists($db_file)) {
    file_put_contents($db_file, json_encode([]));
}

// Load files database
function loadDB() {
    global $db_file;
    return json_decode(file_get_contents($db_file), true);
}

// Save files database
function saveDB($data) {
    global $db_file;
    file_put_contents($db_file, json_encode($data, JSON_PRETTY_PRINT));
}

// Get expiry hours based on file size
function getExpiryHours($fileSize) {
    if ($fileSize < 1024 * 1024) { // < 1MB
        return EXPIRY_HOURS_SMALL;
    } elseif ($fileSize < 2 * 1024 * 1024) { // < 2MB
        return EXPIRY_HOURS_MEDIUM;
    } else { // >= 2MB
        return EXPIRY_HOURS_LARGE;
    }
}

// Clean expired files
function cleanExpiredFiles() {
    $files = loadDB();
    $now = time();
    $changed = false;
    
    foreach ($files as $id => $file) {
        // Skip permanent files
        if (isset($file['permanent']) && $file['permanent'] === true) {
            continue;
        }
        
        $should_delete = false;
        
        if ($file['downloads'] >= MAX_DOWNLOADS) {
            $should_delete = true;
        }
        
        $expiryHours = getExpiryHours($file['size']);
        $last_activity = max($file['uploaded'], $file['last_download']);
        if (($now - $last_activity) > ($expiryHours * 3600)) {
            $should_delete = true;
        }
        
        if ($should_delete) {
            @unlink(UPLOAD_DIR . $file['filename']);
            unset($files[$id]);
            $changed = true;
        }
    }
    
    if ($changed) {
        saveDB($files);
    }
}

// Get total storage used
function getTotalStorage() {
    $total = 0;
    $files = glob(UPLOAD_DIR . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            $total += filesize($file);
        }
    }
    return $total;
}

// Generate short ID
function generateID() {
    return substr(md5(uniqid(rand(), true)), 0, 8);
}

// Get MIME type
function getMimeType($filepath) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filepath);
    finfo_close($finfo);
    return $mime;
}

// Clean expired files on every request
cleanExpiredFiles();

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $response = ['success' => false];
    
    if ($_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $fileSize = $_FILES['file']['size'];
        
        if ($fileSize > MAX_FILE_SIZE) {
            $response['error'] = 'File is too fucking big! Max 100MB.';
        } else {
            $currentStorage = getTotalStorage();
            if (($currentStorage + $fileSize) > MAX_STORAGE) {
                $response['error'] = 'Storage is full as shit! Come back later when some files expire.';
            } else {
                $id = generateID();
                $original_name = $_FILES['file']['name'];
                $filename = $id . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $original_name);
                $filepath = UPLOAD_DIR . $filename;
                
                if (move_uploaded_file($_FILES['file']['tmp_name'], $filepath)) {
                    $files = loadDB();
                    $files[$id] = [
                        'id' => $id,
                        'filename' => $filename,
                        'original_name' => $original_name,
                        'size' => $fileSize,
                        'mime' => getMimeType($filepath),
                        'uploaded' => time(),
                        'last_download' => time(),
                        'downloads' => 0,
                        'permanent' => false // Can be manually set to true in files.json
                    ];
                    saveDB($files);
                    
                    $response['success'] = true;
                    $response['id'] = $id;
                    $response['url'] = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?v=' . $id;
                }
            }
        }
    } else {
        $response['error'] = 'Upload failed. Try again, dumbass.';
    }
    
    header('Content-Type: application/json');
    $debug = ob_get_contents(); // capture warnings
    ob_end_clean();
    
    $response['debug'] = $debug;  // send them in JSON
    echo json_encode($response);
    exit;
}

// Handle file preview/download
if (isset($_GET['v'])) {
    $id = $_GET['v'];
    $files = loadDB();
    $download = isset($_GET['dl']);
    
    if (isset($files[$id])) {
        $file = $files[$id];
        $filepath = UPLOAD_DIR . $file['filename'];
        
        if (file_exists($filepath)) {
            if ($download) {
            
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                ini_set('display_errors', 0);
                
                // Update database
                $files[$id]['downloads']++;
                $files[$id]['last_download'] = time();
                saveDB($files);
                
                // Send file
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
                header('Content-Length: ' . filesize($filepath));
                header('Pragma: public');
                header('Cache-Control: must-revalidate');
                
                readfile($filepath);
                exit;
            }
            // Otherwise show preview page
            $previewMode = true;
            $fileData = $file;
        } else {
            $errorMode = true;
            $errorMessage = 'File not found or expired, motherfucker!';
        }
    } else {
        $errorMode = true;
        $errorMessage = 'Invalid link or file expired!';
    }
}

// Get storage info
$storageUsed = getTotalStorage();
$storagePercent = ($storageUsed / MAX_STORAGE) * 100;

// If preview or error mode, show that page
if (isset($previewMode) || isset($errorMode)):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FileFucker - <?php echo isset($errorMode) ? 'Error' : htmlspecialchars($fileData['original_name']); ?></title>
    
    <!-- Open Graph / Social Media Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo isset($errorMode) ? 'FileFucker - Error' : 'FileFucker - ' . htmlspecialchars($fileData['original_name']); ?>">
    <meta property="og:description" content="<?php echo isset($errorMode) ? 'Fuck! Something went wrong.' : 'Download this fucking file before it expires! ' . round($fileData['size'] / 1024 / 1024, 2) . 'MB • ' . $fileData['downloads'] . '/' . MAX_DOWNLOADS . ' downloads used'; ?>">
    <meta property="og:site_name" content="FileFucker">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?php echo isset($errorMode) ? 'FileFucker - Error' : 'FileFucker - ' . htmlspecialchars($fileData['original_name']); ?>">
    <meta name="twitter:description" content="<?php echo isset($errorMode) ? 'Fuck! Something went wrong.' : 'Download this fucking file before it expires!'; ?>">
    
    <link rel="stylesheet" href="https://www.nerdfonts.com/assets/css/webfont.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <canvas id="stars"></canvas>
    
    <div class="container">
        <h1 class="main-title"><i class="nf nf-fa-skull"></i> FileFucker</h1>
        
        <?php if (isset($errorMode)): ?>
            <div class="result error">
                <h3><i class="nf nf-fa-times_circle"></i> Fuck!</h3>
                <p><?php echo htmlspecialchars($errorMessage); ?></p>
                <a href="/" class="btn">Go Back Home</a>
            </div>
        <?php else: ?>
            <div class="preview-container">
                <h2><i class="nf nf-fa-eye"></i> File Preview</h2>
                
                <div class="file-info">
                    <p><strong>Filename:</strong> <?php echo htmlspecialchars($fileData['original_name']); ?></p>
                    <p><strong>Size:</strong> <?php echo round($fileData['size'] / 1024 / 1024, 2); ?> MB</p>
                    <p><strong>Downloads:</strong> <?php echo $fileData['downloads']; ?> / <?php echo MAX_DOWNLOADS; ?></p>
                    <p><strong>Expires:</strong> <?php 
                        if (isset($fileData['permanent']) && $fileData['permanent'] === true) {
                            echo 'Never (Permanent)';
                        } else {
                            $expiryHours = getExpiryHours($fileData['size']);
                            $remaining = $expiryHours * 3600 - (time() - max($fileData['uploaded'], $fileData['last_download']));
                            if ($remaining > 86400) {
                                echo round($remaining / 86400, 1) . ' days';
                            } else {
                                echo round($remaining / 3600, 1) . ' hours';
                            }
                        }
                    ?></p>
                </div>
                
                <div class="preview-area">
                    <?php
                    $mime = $fileData['mime'];
                    $filepath = UPLOAD_DIR . $fileData['filename'];
                    
                    if (strpos($mime, 'image/') === 0): ?>
                        <img src="<?php echo $filepath; ?>" alt="Preview">
                    <?php elseif (strpos($mime, 'video/') === 0): ?>
                        <video controls>
                            <source src="<?php echo $filepath; ?>" type="<?php echo $mime; ?>">
                        </video>
                    <?php elseif (strpos($mime, 'audio/') === 0): ?>
                        <audio controls>
                            <source src="<?php echo $filepath; ?>" type="<?php echo $mime; ?>">
                        </audio>
                    <?php elseif ($mime === 'application/pdf'): ?>
                        <iframe src="<?php echo $filepath; ?>"></iframe>
                    <?php elseif (strpos($mime, 'text/') === 0): ?>
                        <pre><?php echo htmlspecialchars(file_get_contents($filepath)); ?></pre>
                    <?php else: ?>
                        <div class="no-preview">
                            <i class="nf nf-fa-file"></i>
                            <p>Preview not available for this file type</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <a href="?v=<?php echo $fileData['id']; ?>&dl=1" class="btn download-btn" id="downloadBtn" 
                   data-size="<?php echo $fileData['size']; ?>" 
                   data-filename="<?php echo htmlspecialchars($fileData['original_name']); ?>">
                    <i class="nf nf-fa-download"></i> Download File
                </a>
                
                <!-- Download Progress -->
                <div id="downloadProgress" class="download-progress" style="display: none;">
                    <div class="progress-info">
                        <span id="downloadSize">0 MB / 0 MB</span>
                        <span id="downloadTime">Calculating...</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="downloadProgressFill"></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="footer">
            <p>Site by MalikHw</p>
            <div class="social-links">
                <a href="https://youtube.com/@MalikHw47" target="_blank" title="YouTube">
                    <i class="nf nf-fa-youtube"></i>
                </a>
                <a href="https://twitch.tv/MalikHw47" target="_blank" title="Twitch">
                    <i class="nf nf-fa-twitch"></i>
                </a>
                <a href="https://github.com/malikhw" target="_blank" title="GitHub">
                    <i class="nf nf-cod-github"></i>
                </a>
            </div>
        </div>
    </div>
    
    <script src="script.js"></script>
</body>
</html>
<?php
exit;
endif;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FileFucker - Drop Your Shit Here</title>
    
    <!-- Open Graph / Social Media Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="FileFucker - Fast Temporary File Sharing">
    <meta property="og:description" content="Fast, temporary, no bullshit file sharing. Upload files up to 100MB. Auto-deletes after 3 downloads or when expired. No registration, no tracking, just fucking file sharing.">
    <meta property="og:site_name" content="FileFucker">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="FileFucker - Fast Temporary File Sharing">
    <meta name="twitter:description" content="Fast, temporary, no bullshit file sharing. Drop your files and share the link!">
    
    <link rel="stylesheet" href="https://www.nerdfonts.com/assets/css/webfont.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <canvas id="stars"></canvas>
    
    <div class="container">
        <h1 class="main-title"><i class="nf nf-fa-skull"></i> FileFucker</h1>
        <p class="subtitle">Fast, temporary, no bullshit file sharing</p>
        
        <div class="upload-area" id="uploadArea">
            <div class="upload-icon"><i class="nf nf-fa-folder_open"></i></div>
            <div class="upload-text">Click or drag your fucking file here</div>
            <div class="upload-subtext">Max 100MB • Auto-deletes after 3 downloads or expires</div>
        </div>
        
        <input type="file" id="fileInput">
        
        <!-- Upload Progress -->
        <div class="upload-progress" id="uploadProgress" style="display: none;">
            <div class="progress-info">
                <span>Uploading...</span>
                <span id="uploadPercent">0%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" id="uploadProgressFill"></div>
            </div>
        </div>
        
        <div class="loader" id="loader"></div>
        
        <div class="result" id="result"></div>
        
        <div class="storage-info">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="font-weight: bold;"><i class="nf nf-fa-hdd_o"></i> Storage Status</span>
                <span class="storage-text"><?php echo round($storageUsed / 1024 / 1024, 1); ?>MB / <?php echo round(MAX_STORAGE / 1024 / 1024); ?>MB</span>
            </div>
            <div class="storage-bar">
                <div class="storage-fill" style="width: <?php echo min(100, $storagePercent); ?>%"></div>
            </div>
            <?php if ($storagePercent > 90): ?>
            <p class="storage-text" style="color: #ff006e;"><i class="nf nf-fa-warning"></i> Storage is almost full! Files may not upload.</p>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <p>Site by MalikHw</p>
            <div class="social-links">
                <a href="https://youtube.com/@MalikHw47" target="_blank" title="YouTube">
                    <i class="nf nf-fa-youtube"></i>
                </a>
                <a href="https://twitch.tv/MalikHw47" target="_blank" title="Twitch">
                    <i class="nf nf-fa-twitch"></i>
                </a>
                <a href="https://github.com/malikhw" target="_blank" title="GitHub">
                    <i class="nf nf-cod-github"></i>
                </a>
            </div>
        </div>
    </div>
    
    <script src="script.js"></script>
</body>
</html>