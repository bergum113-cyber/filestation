<?php
/**
 * 웹하드 Office 문서 뷰어
 * 
 * 지원 형식:
 * - DOCX (Microsoft Word) - docx-preview
 * - XLSX (Microsoft Excel) - SheetJS
 * - PPTX (Microsoft PowerPoint) - JSZip + XML 파싱
 * - DOC/XLS/PPT (레거시) - 안내 메시지
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';

$currentLang = getLang();

// 인증 확인
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('HTTP/1.1 401 Unauthorized');
    exit(__('api_login_required'));
}

$storage = new Storage();

// 스토리지 읽기 권한 체크
$_checkSid = (int)($_GET['storage_id'] ?? 0);
$_checkPath = $_GET['path'] ?? '';
if ($_checkSid && !$storage->checkPermission($_checkSid, 'can_read')) {
    http_response_code(403);
    $currentLang = $_SESSION['lang'] ?? 'ko';
    exit('<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;"><h2 style="color:#d32f2f;">🚫 ' . ($currentLang === 'en' ? 'Access Denied' : '접근 권한이 없습니다') . '</h2><p style="color:#666;">' . ($currentLang === 'en' ? 'You do not have permission to access this file.' : '이 파일에 대한 읽기 권한이 없습니다.') . '</p><a href="index.php" style="color:#1976d2;">' . ($currentLang === 'en' ? 'Go back' : '돌아가기') . '</a></div></body></html>');
}
if ($_checkSid && $_checkPath) {
    $_checkDir = dirname($_checkPath);
    if ($_checkDir === '.') $_checkDir = '';
    if (!$storage->checkFolderPermission($_checkSid, $_checkDir ?: $_checkPath)) {
        http_response_code(403);
        $currentLang = $_SESSION['lang'] ?? 'ko';
        exit('<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;"><h2 style="color:#d32f2f;">🚫 ' . ($currentLang === 'en' ? 'Access Denied' : '접근 권한이 없습니다') . '</h2><p style="color:#666;">' . ($currentLang === 'en' ? 'You do not have permission to access this folder.' : '이 폴더에 대한 접근 권한이 없습니다.') . '</p><a href="index.php" style="color:#1976d2;">' . ($currentLang === 'en' ? 'Go back' : '돌아가기') . '</a></div></body></html>');
    }
}

// 파라미터 확인
$storageId = (int)($_GET['storage_id'] ?? 0);
$filePath = $_GET['path'] ?? '';
$vaultUrl = $_GET['vault_url'] ?? '';
$vaultName = $_GET['name'] ?? '';

// vault_url 화이트리스트 검증 (SSRF 방지)
if ($vaultUrl && strpos($vaultUrl, 'api.php?action=vault_preview_serve&') !== 0) {
    $vaultUrl = '';
}

// vault 모드
if ($vaultUrl && $vaultName) {
    $fileName = $vaultName;
    $filename = $vaultName;
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $isLegacy = in_array($ext, ['doc', 'xls', 'ppt']);
    $fileUrl = $vaultUrl;
    $darkmode = $_COOKIE['darkmode'] ?? 'light';
    goto render_page;
}

if (!$storageId || !$filePath) {
    exit(__('office_invalid_request'));
}

// 스토리지 권한 확인
$storageInfo = $storage->getStorageById($storageId);
if (!$storageInfo) {
    exit(__('office_storage_not_found'));
}

// 파일 경로 생성
$basePath = $storageInfo['path'] ?? '';
if (empty($basePath) && !in_array($storageInfo['storage_type'] ?? '', ['ftp', 'sftp', 'webdav', 's3', 'smb'])) {
    http_response_code(500);
    exit('Storage path error');
}
$storageType = $storageInfo['storage_type'] ?? 'local';
$remoteTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
$isRemote = in_array($storageType, $remoteTypes);

if ($isRemote) {
    // 경로 탐색 방지 (원격 스토리지)
    if (preg_match('/\.\.[\\/\\\\]/', $filePath) || strpos($filePath, '..') !== false) {
        http_response_code(403);
        exit('Invalid path');
    }
    // 원격 스토리지: 어댑터를 통해 임시 파일로 다운로드
    require_once __DIR__ . '/api/StorageAdapter.php';
    $adapter = StorageAdapterFactory::create($storageInfo);
    if (!$adapter || !$adapter->connect()) {
        $err = ($adapter && method_exists($adapter, 'getLastError')) ? $adapter->getLastError() : '';
        exit(__('office_invalid_path') . ($err ? " ({$err})" : ''));
    }
    
    // 오피스 파일 확인
    if (!preg_match('/\.(docx?|xlsx?|pptx?)$/i', $filePath)) {
        $adapter->disconnect();
        exit(__('office_file_not_found'));
    }
    
    // 임시 파일로 다운로드
    $tempDir = sys_get_temp_dir() . '/fs_office_' . session_id();
    if (!is_dir($tempDir)) @mkdir($tempDir, 0700, true);
    $tempFile = $tempDir . '/' . basename($filePath);
    
    $content = $adapter->read($filePath);
    $adapter->disconnect();
    
    if ($content === '' || $content === false) {
        exit(__('office_file_not_found'));
    }
    
    file_put_contents($tempFile, $content);
    $fullPath = $tempFile;
    
    // 스크립트 종료 시 임시 파일 삭제
    register_shutdown_function(function() use ($tempFile, $tempDir) {
        @unlink($tempFile);
        @rmdir($tempDir);
    });
} else {
    // 로컬/SMB 스토리지
    $fullPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath), '/\\');
    
    // 경로 안전성 검사
    $realBasePath = realpath($basePath);
    $realFullPath = realpath($fullPath);
    
    if (!$realBasePath || !$realFullPath || !\isSubPath($realFullPath, $realBasePath)) {
        exit(__('office_invalid_path'));
    }
    
    // Office 파일 확인
    if (!file_exists($fullPath) || !preg_match('/\.(docx?|xlsx?|pptx?)$/i', $fullPath)) {
        exit(__('office_file_not_found'));
    }
}

$filename = basename($fullPath);
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$title = preg_replace('/\.(docx?|xlsx?|pptx?)$/i', '', $filename);
$filesize = filesize($fullPath);

// 파일 크기 제한 (50MB)
$maxFileSize = 50 * 1024 * 1024;
if ($filesize > $maxFileSize) {
    exit(__('office_file_too_large'));
}

// 파일 타입 정보
render_page:

$fileTypes = [
    'doc' => ['name' => __('office_word_legacy'), 'color' => '#2b579a', 'icon' => 'W'],
    'docx' => ['name' => 'Word', 'color' => '#2b579a', 'icon' => 'W'],
    'xls' => ['name' => __('office_excel_legacy'), 'color' => '#217346', 'icon' => 'X'],
    'xlsx' => ['name' => 'Excel', 'color' => '#217346', 'icon' => 'X'],
    'ppt' => ['name' => __('office_ppt_legacy'), 'color' => '#d24726', 'icon' => 'P'],
    'pptx' => ['name' => 'PowerPoint', 'color' => '#d24726', 'icon' => 'P'],
];
$fileInfo = $fileTypes[$ext] ?? ['name' => 'Office', 'color' => '#666', 'icon' => 'O'];
if (!isset($isLegacy)) $isLegacy = in_array($ext, ['doc', 'xls', 'ppt']);
if (!isset($fileName)) $fileName = basename($filePath);
if (!isset($filename)) $filename = $fileName;
$title = pathinfo($fileName, PATHINFO_FILENAME);

// 파일 스트리밍 API
if (isset($_GET['action']) && $_GET['action'] === 'stream') {
    // 폴더별 권한 체크
    $fileDir = dirname($filePath);
    if ($fileDir === '.') $fileDir = '';
    if ($storageId && !$storage->checkFolderPermission($storageId, $fileDir ?: $filePath)) {
        http_response_code(403);
        exit('No permission');
    }
    
    // MIME 타입
    $mimeTypes = [
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $filesize);
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=86400');
    
    // Range 요청 처리
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        if (preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
            $start = $matches[1] !== '' ? intval($matches[1]) : 0;
            $end = $matches[2] !== '' ? intval($matches[2]) : $filesize - 1;
            
            if ($start > $end || $start >= $filesize) {
                http_response_code(416);
                header("Content-Range: bytes */$filesize");
                exit;
            }
            
            $length = $end - $start + 1;
            
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$filesize");
            header("Content-Length: $length");
            
            $fp = fopen($fullPath, 'rb');
            fseek($fp, $start);
            echo fread($fp, $length);
            fclose($fp);
            exit;
        }
    }
    
    readfile($fullPath);
    exit;
}

// 파일 URL (vault 모드가 아닐 때만)
if (!isset($vaultUrl) || !$vaultUrl) {
    $fileUrl = "office_viewer.php?storage_id={$storageId}&path=" . rawurlencode($filePath) . "&action=stream";
}

// 다크모드
if (!isset($darkmode)) $darkmode = $_COOKIE['darkmode'] ?? 'light';

?>
<!DOCTYPE html>
<html lang="ko" data-theme="<?php echo htmlspecialchars($darkmode); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title><?php echo htmlspecialchars($title); ?> - <?php echo htmlspecialchars($fileInfo['name']); ?> <?php echo __('office_viewer_title'); ?></title>
    <link rel="shortcut icon" href="favicon.ico">
    
    <style>
        :root {
            --bg-color: #ffffff;
            --text-color: #333333;
            --header-bg: #f8f9fa;
            --border-color: #dee2e6;
            --btn-bg: #e9ecef;
            --btn-hover: #dee2e6;
            --viewer-bg: #f5f5f5;
            --card-bg: #ffffff;
        }
        
        [data-theme="dark"] {
            --bg-color: #1a1a1a;
            --text-color: #e0e0e0;
            --header-bg: #2d2d2d;
            --border-color: #404040;
            --btn-bg: #3d3d3d;
            --btn-hover: #4d4d4d;
            --viewer-bg: #252525;
            --card-bg: #2d2d2d;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Malgun Gothic", sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        @supports (height: 100dvh) {
            body { height: 100dvh; }
        }
        
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 15px;
            background: var(--header-bg);
            border-bottom: 1px solid var(--border-color);
            min-height: 50px;
            flex-shrink: 0;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 0;
        }
        
        .back-btn, .toolbar-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: var(--btn-bg);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-color);
            text-decoration: none;
            flex-shrink: 0;
            white-space: nowrap;
        }
        
        .back-btn:hover, .toolbar-btn:hover { background: var(--btn-hover); }
        
        .filename {
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .file-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            padding: 0 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            background: <?php echo preg_match('/^#[0-9a-fA-F]{3,6}$/', $fileInfo['color']) ? $fileInfo['color'] : '#666'; ?>;
            color: white;
            flex-shrink: 0;
        }
        
        .toolbar {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .zoom-control {
            display: flex;
            align-items: center;
            gap: 3px;
            margin-right: 5px;
        }
        
        .zoom-value {
            font-size: 12px;
            min-width: 40px;
            text-align: center;
        }
        
        .slide-nav {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-right: 10px;
        }
        
        .slide-nav span {
            font-size: 12px;
            min-width: 60px;
            text-align: center;
        }
        
        .viewer-container {
            flex: 1;
            overflow: auto;
            background: var(--viewer-bg);
        }
        
        /* DOCX 스타일 */
        .docx {
            max-width: 900px;
            margin: 20px auto;
            background: var(--card-bg);
            padding: 40px 50px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            transform-origin: top center;
        }
        
        /* XLSX 스타일 */
        .xlsx-container {
            padding: 20px;
        }
        
        .sheet-tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .sheet-tab {
            padding: 8px 16px;
            background: var(--btn-bg);
            border: none;
            border-radius: 6px;
            cursor: pointer;
            color: var(--text-color);
            font-size: 13px;
        }
        
        .sheet-tab:hover { background: var(--btn-hover); }
        .sheet-tab.active { background: #217346; color: white; }
        
        .xlsx-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card-bg);
            font-size: 13px;
        }
        
        .xlsx-table th, .xlsx-table td {
            border: 1px solid var(--border-color);
            padding: 8px 10px;
            text-align: left;
            white-space: nowrap;
        }
        
        .xlsx-table th {
            background: var(--header-bg);
            font-weight: 600;
        }
        
        .xlsx-table tr:nth-child(even) {
            background: rgba(0,0,0,0.02);
        }
        
        [data-theme="dark"] .xlsx-table tr:nth-child(even) {
            background: rgba(255,255,255,0.02);
        }
        
        /* PPTX 스타일 */
        .pptx-container {
            max-width: 960px;
            margin: 30px auto;
            transform-origin: top center;
        }
        
        .slide-content {
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 40px;
            min-height: 400px;
            aspect-ratio: 16/9;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .slide-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 20px;
            color: <?php echo preg_match('/^#[0-9a-fA-F]{3,6}$/', $fileInfo['color']) ? $fileInfo['color'] : '#666'; ?>;
        }
        
        .slide-body {
            font-size: 18px;
            line-height: 1.8;
        }
        
        .slide-body p { margin-bottom: 0.5em; }
        
        /* 공통 */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--bg-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border-color);
            border-top-color: <?php echo preg_match('/^#[0-9a-fA-F]{3,6}$/', $fileInfo['color']) ? $fileInfo['color'] : '#666'; ?>;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .loading-text {
            margin-top: 15px;
            font-size: 14px;
            color: var(--text-color);
        }
        
        .error-container, .legacy-notice {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            padding: 40px;
            text-align: center;
        }
        
        .error-icon, .legacy-icon { font-size: 64px; margin-bottom: 20px; }
        .error-message, .legacy-message { font-size: 16px; color: #666; margin-bottom: 20px; }
        
        .error-btn, .legacy-btn {
            padding: 10px 20px;
            background: var(--btn-bg);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-color);
            text-decoration: none;
            margin: 5px;
        }
        .error-btn:hover, .legacy-btn:hover { background: var(--btn-hover); }
        
        .legacy-btn.primary {
            background: <?php echo preg_match('/^#[0-9a-fA-F]{3,6}$/', $fileInfo['color']) ? $fileInfo['color'] : '#666'; ?>;
            color: white;
        }
        .legacy-btn.primary:hover { opacity: 0.9; }
        
        @media (max-width: 768px) {
            .docx { padding: 0; margin: 5px; box-sizing: border-box; box-shadow: none; }
            .docx-wrapper { overflow-x: hidden; padding-bottom: calc(60px + env(safe-area-inset-bottom, 0px)); }
            .slide-content { padding: 20px; }
            .slide-title { font-size: 20px; }
            .slide-body { font-size: 14px; }
            .zoom-control { display: none; }
            .filename { max-width: 50vw; }
            .filename.scrollable-name {
                overflow-x: auto !important;
                overflow: auto !important;
                text-overflow: clip !important;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }
            .filename.scrollable-name::-webkit-scrollbar { display: none; }
            .header-left.scroll-active { overflow: visible !important; }
        }
        
        /* 모바일/태블릿 가로모드: 파일명 스크롤 */
        @media (max-width: 1024px) and (orientation: landscape) {
            .header { gap: 5px; padding: 8px 10px; }            .header-left { flex: 1 1 auto; min-width: 0; overflow: hidden; }
            .filename { max-width: 50vw; }
            .filename.scrollable-name {
                overflow-x: auto !important;
                overflow: auto !important;
                text-overflow: clip !important;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }
            .filename.scrollable-name::-webkit-scrollbar { display: none; }
            .header-left.scroll-active { overflow: visible !important; }
        }
        
        @media print {
            .header { display: none; }
            .viewer-container { overflow: visible; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <button class="back-btn" onclick="window.close()" title="<?php _e('viewer_close'); ?>">✕</button>
            <span class="filename"><?php echo htmlspecialchars($filename); ?></span>
            <span class="file-badge"><?php echo htmlspecialchars($fileInfo['icon']); ?></span>
        </div>
        <div class="toolbar">
            <?php if ($ext === 'pptx'): ?>
            <div class="slide-nav" id="slideNav" style="display:none;">
                <button class="toolbar-btn" onclick="prevSlide()" title="<?php _e('viewer_prev_slide'); ?>">◀</button>
                <span id="slideInfo">1 / 1</span>
                <button class="toolbar-btn" onclick="nextSlide()" title="<?php _e('viewer_next_slide'); ?>">▶</button>
            </div>
            <?php endif; ?>
            
            <?php if (!$isLegacy): ?>
            <div class="zoom-control">
                <button class="toolbar-btn" onclick="zoomOut()" title="<?php _e('viewer_zoom_out'); ?>">−</button>
                <span class="zoom-value" id="zoomValue">100%</span>
                <button class="toolbar-btn" onclick="zoomIn()" title="<?php _e('viewer_zoom_in'); ?>">+</button>
            </div>
            <?php endif; ?>
            
            <button class="toolbar-btn" onclick="printDocument()" title="<?php _e('viewer_print'); ?>">🖨️</button>
            <button class="toolbar-btn" onclick="toggleDarkMode()" title="<?php _e('viewer_darkmode'); ?>">🌓</button>
        </div>
    </div>
    
    <?php if (!$isLegacy): ?>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-text"><?php _e('viewer_loading'); ?></div>
    </div>
    <?php endif; ?>
    
    <div class="viewer-container" id="viewerContainer">
        <?php if ($isLegacy): ?>
        <!-- 레거시 형식 안내 -->
        <div class="legacy-notice">
            <div class="legacy-icon">📄</div>
            <div class="legacy-message">
                <strong><?php echo htmlspecialchars($fileInfo['name']); ?></strong> <?php echo __('office_not_supported_prefix'); ?><br>
                <?php _e('viewer_legacy_msg'); ?>
            </div>
            <div>
                <a href="<?php echo htmlspecialchars($fileUrl); ?>" download="<?php echo htmlspecialchars($filename); ?>" class="legacy-btn primary"><?php echo __('office_download_btn'); ?></a>
                <button class="legacy-btn" onclick="window.close()"><?php _e('viewer_close'); ?></button>
            </div>
        </div>
        <?php else: ?>
        <div id="viewer"></div>
        <?php endif; ?>
    </div>
    
    <?php if (!$isLegacy): ?>
    <!-- 라이브러리 로드 -->
    <script src="assets/vendor/jszip.min.js"></script>
    
    <?php if ($ext === 'docx'): ?>
    <!-- DOCX: docx-preview -->
    <script src="assets/vendor/docx-preview.min.js"></script>
    <?php elseif ($ext === 'xlsx'): ?>
    <!-- XLSX: SheetJS -->
    <script src="assets/vendor/xlsx.full.min.js"></script>
    <?php endif; ?>
    
    <script>
        const fileUrl = <?php echo json_encode($fileUrl, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const fileExt = <?php echo json_encode($ext, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const OFC_I18N = {
            fileLoadFailed: <?php echo json_encode(__('office_file_load_failed')); ?>,
            slideNotFound: <?php echo json_encode(__('office_slide_not_found')); ?>,
            noText: <?php echo json_encode(__('office_no_text')); ?>,
            download: <?php echo json_encode(__('office_download_btn')); ?>,
            close: <?php echo json_encode(__('office_close')); ?>
        };
        
        const loadingOverlay = document.getElementById('loadingOverlay');
        const viewerContainer = document.getElementById('viewerContainer');
        const viewerEl = document.getElementById('viewer');
        
        let currentZoom = 100;
        
        <?php if ($ext === 'pptx'): ?>
        let slides = [];
        let currentSlide = 0;
        let totalSlides = 0;
        <?php endif; ?>
        
        // ============================================================
        // DOCX 렌더링
        // ============================================================
        
        <?php if ($ext === 'docx'): ?>
        async function loadDocument() {
            try {
                const response = await fetch(fileUrl, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error(OFC_I18N.fileLoadFailed + ' (HTTP ' + response.status + ')');
                }
                
                const blob = await response.blob();
                
                // docx-preview 렌더링
                const isMobile = window.innerWidth <= 768;
                await docx.renderAsync(blob, viewerEl, null, {
                    className: 'docx',
                    inWrapper: true,
                    ignoreWidth: false,
                    ignoreHeight: false,
                    ignoreFonts: false,
                    breakPages: true,
                    ignoreLastRenderedPageBreak: false,
                    experimental: true,
                    trimXmlDeclaration: true,
                    useBase64URL: true,
                    renderHeaders: true,
                    renderFooters: true,
                    renderFootnotes: true,
                    renderEndnotes: true,
                    debug: false
                });
                
                // 모바일: 원본 크기 유지 + scale 축소
                if (isMobile) {
                    setTimeout(() => {
                        const wrapper = viewerEl.querySelector('.docx-wrapper');
                        if (wrapper) {
                            const sections = wrapper.querySelectorAll('section');
                            const containerWidth = window.innerWidth;
                            sections.forEach(section => {
                                // docx-preview는 section에 인라인 style로 width를 pt 단위로 설정
                                // getBoundingClientRect로 실제 렌더링된 너비 추출
                                const rect = section.getBoundingClientRect();
                                const sectionWidth = rect.width;
                                if (sectionWidth > containerWidth + 5) {
                                    const scale = containerWidth / sectionWidth;
                                    section.style.transformOrigin = 'top center';
                                    section.style.transform = `scale(${scale})`;
                                    // 세로 보정
                                    const sectionHeight = rect.height;
                                    section.style.marginBottom = `-${sectionHeight * (1 - scale)}px`;
                                }
                            });
                        }
                    }, 300);
                }
                
                loadingOverlay.style.display = 'none';
            } catch (error) {
                showError(error.message || OFC_I18N.fileLoadFailed);
            }
        }
        <?php endif; ?>
        
        // ============================================================
        // XLSX 렌더링
        // ============================================================
        
        <?php if ($ext === 'xlsx'): ?>
        let workbook = null;
        let sheetNames = [];
        
        async function loadDocument() {
            try {
                console.log('[Office XLSX] Fetching:', fileUrl);
                const response = await fetch(fileUrl, { credentials: 'same-origin' });
                console.log('[Office XLSX] Response:', response.status, response.headers.get('content-type'));
                if (!response.ok) {
                    throw new Error(OFC_I18N.fileLoadFailed + ' (HTTP ' + response.status + ')');
                }
                
                const arrayBuffer = await response.arrayBuffer();
                console.log('[Office XLSX] Data size:', arrayBuffer.byteLength);
                workbook = XLSX.read(arrayBuffer, { type: 'array' });
                sheetNames = workbook.SheetNames;
                
                // 컨테이너 생성
                viewerEl.className = 'xlsx-container';
                
                // 시트 탭 생성
                if (sheetNames.length > 1) {
                    let tabsHtml = '<div class="sheet-tabs">';
                    sheetNames.forEach((name, idx) => {
                        tabsHtml += `<button class="sheet-tab ${idx === 0 ? 'active' : ''}" onclick="showSheet(${idx})">${escapeHtml(name)}</button>`;
                    });
                    tabsHtml += '</div>';
                    viewerEl.innerHTML = tabsHtml + '<div id="sheetContent"></div>';
                } else {
                    viewerEl.innerHTML = '<div id="sheetContent"></div>';
                }
                
                // 첫 번째 시트 표시
                showSheet(0);
                
                loadingOverlay.style.display = 'none';
            } catch (error) {
                showError(error.message || OFC_I18N.fileLoadFailed);
            }
        }
        
        function showSheet(idx) {
            const sheetName = sheetNames[idx];
            const sheet = workbook.Sheets[sheetName];
            
            // HTML 테이블 생성
            const html = XLSX.utils.sheet_to_html(sheet, { editable: false });
            
            // 테이블 스타일 적용
            const styledHtml = html.replace('<table>', '<table class="xlsx-table">');
            document.getElementById('sheetContent').innerHTML = styledHtml;
            
            // 탭 활성화
            document.querySelectorAll('.sheet-tab').forEach((tab, i) => {
                tab.classList.toggle('active', i === idx);
            });
        }
        <?php endif; ?>
        
        // ============================================================
        // PPTX 렌더링
        // ============================================================
        
        <?php if ($ext === 'pptx'): ?>
        async function loadDocument() {
            try {
                const response = await fetch(fileUrl, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error(OFC_I18N.fileLoadFailed + ' (HTTP ' + response.status + ')');
                }
                
                const arrayBuffer = await response.arrayBuffer();
                const zip = await JSZip.loadAsync(arrayBuffer);
                
                // 슬라이드 파싱
                slides = [];
                let slideNum = 1;
                
                while (true) {
                    const slideFile = zip.file(`ppt/slides/slide${slideNum}.xml`);
                    if (!slideFile) break;
                    
                    const slideXml = await slideFile.async('string');
                    const slideData = parseSlideXml(slideXml);
                    slides.push(slideData);
                    slideNum++;
                }
                
                if (slides.length === 0) {
                    throw new Error(OFC_I18N.slideNotFound);
                }
                
                totalSlides = slides.length;
                currentSlide = 0;
                
                // 뷰어 초기화
                viewerEl.innerHTML = '<div class="pptx-container" id="pptx-container"></div>';
                
                // 슬라이드 네비게이션 표시
                document.getElementById('slideNav').style.display = 'flex';
                
                // 첫 번째 슬라이드 표시
                renderSlide(0);
                
                loadingOverlay.style.display = 'none';
            } catch (error) {
                showError(error.message || OFC_I18N.fileLoadFailed);
            }
        }
        
        function parseSlideXml(xml) {
            const parser = new DOMParser();
            const doc = parser.parseFromString(xml, 'text/xml');
            
            const texts = [];
            
            // 모든 텍스트 추출
            const textElements = doc.querySelectorAll('a\\:t, t');
            textElements.forEach(el => {
                const text = el.textContent?.trim();
                if (text) {
                    texts.push(text);
                }
            });
            
            return { texts };
        }
        
        function renderSlide(idx) {
            currentSlide = idx;
            const slide = slides[idx];
            
            // 슬라이드 정보 업데이트
            document.getElementById('slideInfo').textContent = `${idx + 1} / ${totalSlides}`;
            
            const container = document.getElementById('pptx-container');
            let html = '<div class="slide-content">';
            
            if (slide.texts.length > 0) {
                // 첫 번째 텍스트는 제목으로
                html += `<div class="slide-title">${escapeHtml(slide.texts[0])}</div>`;
                
                if (slide.texts.length > 1) {
                    html += '<div class="slide-body">';
                    slide.texts.slice(1).forEach(text => {
                        html += `<p>${escapeHtml(text)}</p>`;
                    });
                    html += '</div>';
                }
            } else {
                html += '<div style="text-align:center;color:#888;">' + OFC_I18N.noText + '</div>';
            }
            
            html += '</div>';
            container.innerHTML = html;
            
            updateNavButtons();
        }
        
        function prevSlide() {
            if (currentSlide > 0) {
                renderSlide(currentSlide - 1);
            }
        }
        
        function nextSlide() {
            if (currentSlide < totalSlides - 1) {
                renderSlide(currentSlide + 1);
            }
        }
        
        function updateNavButtons() {
            const nav = document.getElementById('slideNav');
            const buttons = nav.querySelectorAll('button');
            buttons[0].disabled = currentSlide === 0;
            buttons[1].disabled = currentSlide === totalSlides - 1;
        }
        <?php endif; ?>
        
        // ============================================================
        // 공통 함수
        // ============================================================
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function showError(message) {
            loadingOverlay.style.display = 'none';
            viewerContainer.innerHTML = `
                <div class="error-container">
                    <div class="error-icon">📄</div>
                    <div class="error-message">${message}</div>
                    <button class="error-btn" onclick="window.close()"><?php _e('viewer_close'); ?></button>
                </div>
            `;
        }
        
        function zoomIn() {
            if (currentZoom < 200) {
                currentZoom += 10;
                applyZoom();
            }
        }
        
        function zoomOut() {
            if (currentZoom > 50) {
                currentZoom -= 10;
                applyZoom();
            }
        }
        
        function applyZoom() {
            <?php if ($ext === 'docx'): ?>
            const docxWrapper = document.querySelector('.docx');
            if (docxWrapper) {
                docxWrapper.style.transform = `scale(${currentZoom / 100})`;
                docxWrapper.style.transformOrigin = 'top center';
            }
            <?php elseif ($ext === 'xlsx'): ?>
            if (viewerEl) {
                viewerEl.style.transform = `scale(${currentZoom / 100})`;
                viewerEl.style.transformOrigin = 'top left';
            }
            <?php elseif ($ext === 'pptx'): ?>
            const pptxContainer = document.getElementById('pptx-container');
            if (pptxContainer) {
                pptxContainer.style.transform = `scale(${currentZoom / 100})`;
                pptxContainer.style.transformOrigin = 'top center';
            }
            <?php endif; ?>
            
            document.getElementById('zoomValue').textContent = currentZoom + '%';
        }
        
        function printDocument() {
            window.print();
        }
        
        function toggleDarkMode() {
            const html = document.documentElement;
            const current = html.getAttribute('data-theme');
            const next = current === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            document.cookie = `darkmode=${next};path=/;max-age=31536000`;
        }
        
        // 키보드 단축키
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomIn(); }
                else if (e.key === '-') { e.preventDefault(); zoomOut(); }
                else if (e.key === 'p') { e.preventDefault(); printDocument(); }
            }
            if (e.key === 'Escape') window.close();
            
            <?php if ($ext === 'pptx'): ?>
            // PPTX 슬라이드 네비게이션
            if (totalSlides > 0) {
                if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    prevSlide();
                } else if (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === ' ') {
                    e.preventDefault();
                    nextSlide();
                }
            }
            <?php endif; ?>
        });
        
        // 시작
        loadDocument();
        
        // PC+모바일: 잘린 파일명 클릭/터치 시 가로 스크롤
        {
            const fnEl = document.querySelector('.filename');
            if (fnEl) {
                fnEl.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const headerLeft = this.closest('.header-left');
                    if (this.classList.contains('scrollable-name')) {
                        this.scrollLeft = 0;
                        this.classList.remove('scrollable-name');
                        if (headerLeft) headerLeft.classList.remove('scroll-active');
                    } else {
                        const isOver = this.scrollWidth > this.clientWidth + 2;
                        if (!isOver) {
                            const origO = this.style.overflow;
                            const origT = this.style.textOverflow;
                            this.style.overflow = 'visible';
                            this.style.textOverflow = 'clip';
                            const isOver2 = this.scrollWidth > this.clientWidth + 2;
                            this.style.overflow = origO;
                            this.style.textOverflow = origT;
                            if (isOver2) {
                                this.classList.add('scrollable-name');
                                if (headerLeft) headerLeft.classList.add('scroll-active');
                            }
                        } else {
                            this.classList.add('scrollable-name');
                            if (headerLeft) headerLeft.classList.add('scroll-active');
                        }
                    }
                });
            }
        }
    </script>
    <?php endif; ?>
<?php if (!empty($vaultUrl)): ?>
    <script>
    (function() {
        const vaultUrl = <?php echo json_encode($vaultUrl, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        try {
            const params = new URLSearchParams(vaultUrl.split('?')[1] || '');
            const tempId = params.get('temp_id');
            const storageId = params.get('storage_id');
            const path = params.get('path');
            if (tempId && storageId) {
                // 뷰어 열린 동안 2분마다 touch (서버 정리에서 제외)
                const keepAlive = setInterval(function() {
                    fetch('api.php?action=vault_preview_touch', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ temp_id: tempId, storage_id: parseInt(storageId), path: path || '' })
                    }).catch(function(){});
                }, 120000);
                
                // 탭 닫기 감지: hidden 5초 유지 → cleanup
                let cleanupTimer = null;
                document.addEventListener('visibilitychange', function() {
                    if (document.visibilityState === 'hidden') {
                        cleanupTimer = setTimeout(function() {
                            clearInterval(keepAlive);
                            navigator.sendBeacon('api.php?action=vault_preview_cleanup',
                                JSON.stringify({ temp_id: tempId, storage_id: parseInt(storageId), path: path || '', csrf_token: '' }));
                        }, 5000);
                    } else {
                        if (cleanupTimer) { clearTimeout(cleanupTimer); cleanupTimer = null; }
                    }
                });
            }
        } catch(e) {}
    })();
    </script>
<?php endif; ?>
</body>
</html>
