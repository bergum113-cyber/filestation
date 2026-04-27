<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * Webhard HWP/HWPX 통합 뷰어
 * 
 * @version 3.0
 * @date 2026-01-24
 * 
 * 지원 형식:
 * - HWP (한글 5.0, ohah/hwpjs legacy)
 * - HWPX (한글 2014+, JSZip + XML 파싱)
 * 
 * 사용 라이브러리:
 * - HWP: cfb.js, pako.js, hwpjs.js (ohah/hwpjs legacy)
 * - HWPX: JSZip
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';

$currentLang = getLang();
$storage = new Storage();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// 스토리지 읽기 권한 체크
$_checkSid = (int)($_GET['storage_id'] ?? 0);
$_checkPath = $_GET['path'] ?? '';
if ($_checkSid && !$storage->checkPermission($_checkSid, 'can_read')) {
    http_response_code(403);
    $currentLang = $_SESSION['lang'] ?? 'ko';
    exit('<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;"><h2 style="color:#d32f2f;">🚫 ' . ($currentLang === 'en' ? 'Access Denied' : '접근 권한이 없습니다') . '</h2><p style="color:#666;">' . ($currentLang === 'en' ? 'You do not have permission to access this file.' : '이 파일에 대한 읽기 권한이 없습니다.') . '</p><a href="index.php" style="color:#1976d2;">' . ($currentLang === 'en' ? 'Go back' : '돌아가기') . '</a></div></body></html>');
}
// 폴더별 읽기 권한 체크
if ($_checkSid && $_checkPath) {
    $_checkDir = dirname($_checkPath);
    if ($_checkDir === '.') $_checkDir = '';
    if (!$storage->checkFolderPermission($_checkSid, $_checkDir ?: $_checkPath)) {
        http_response_code(403);
        $currentLang = $_SESSION['lang'] ?? 'ko';
        exit('<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;"><h2 style="color:#d32f2f;">🚫 ' . ($currentLang === 'en' ? 'Access Denied' : '접근 권한이 없습니다') . '</h2><p style="color:#666;">' . ($currentLang === 'en' ? 'You do not have permission to access this folder.' : '이 폴더에 대한 접근 권한이 없습니다.') . '</p><a href="index.php" style="color:#1976d2;">' . ($currentLang === 'en' ? 'Go back' : '돌아가기') . '</a></div></body></html>');
    }
}

$hwp_settings = [
    'max_file_size' => 50 * 1024 * 1024,
    'default_zoom' => 100,
];

// ============================================================
// 파일 스트리밍 API (HWP/HWPX 공통)
// ============================================================

if (isset($_GET['action']) && $_GET['action'] === 'stream') {
    $storageId = (int)($_GET['storage_id'] ?? 0);
    $filePath = $_GET['path'] ?? '';
    
    // 폴더별 권한 체크
    $fileDir = dirname($filePath);
    if ($fileDir === '.') $fileDir = '';
    if (!$storage->checkFolderPermission($storageId, $fileDir ?: $filePath)) {
        http_response_code(403);
        exit('No permission');
    }
    
    $storageInfo = $storage->getStorageById($storageId);
    if (!$storageInfo) {
        http_response_code(403);
        echo __('hwp_invalid_storage');
        exit;
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
            http_response_code(503);
            echo __('hwp_invalid_path');
            exit;
        }
        
        if (!preg_match('/\.hwpx?$/i', $filePath)) {
            $adapter->disconnect();
            http_response_code(404);
            echo __('hwp_file_not_found');
            exit;
        }
        
        $content = $adapter->read($filePath);
        $adapter->disconnect();
        
        if ($content === '' || $content === false) {
            http_response_code(404);
            echo __('hwp_file_not_found');
            exit;
        }
        
        $base_file = sys_get_temp_dir() . '/fs_hwp_' . md5($storageId . $filePath) . '_' . basename($filePath);
        file_put_contents($base_file, $content);
        register_shutdown_function(function() use ($base_file) { @unlink($base_file); });
        
        // 1시간 이상 된 잔류 임시 파일 정리 (OOM 등으로 shutdown 미실행 시)
        foreach (@glob(sys_get_temp_dir() . '/fs_hwp_*') ?: [] as $_old) {
            if (is_file($_old) && filemtime($_old) < time() - 3600) @unlink($_old);
        }
    } else {
        // 로컬/SMB 스토리지
        $base_file = $storageInfo['path'] . '/' . $filePath;
        $base_file = realpath($base_file);
        
        // 경로 검증
        if (!$base_file || !\isSubPath($base_file, realpath($storageInfo['path']))) {
            http_response_code(403);
            echo __('hwp_invalid_path');
            exit;
        }
        
        // HWP 또는 HWPX 파일 확인
        if (!file_exists($base_file) || !preg_match('/\.hwpx?$/i', $base_file)) {
            http_response_code(404);
            echo __('hwp_file_not_found');
            exit;
        }
    }
    
    $filesize = filesize($base_file);
    
    if ($filesize > $hwp_settings['max_file_size']) {
        http_response_code(413);
        echo __('hwp_file_too_large');
        exit;
    }
    
    header('Content-Type: application/octet-stream');
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
            
            $fp = fopen($base_file, 'rb');
            fseek($fp, $start);
            echo fread($fp, $length);
            fclose($fp);
            exit;
        }
    }
    
    readfile($base_file);
    exit;
}

// ============================================================
// 메인 페이지
// ============================================================

$storageId = (int)($_GET['storage_id'] ?? 0);
$filePath = $_GET['path'] ?? '';
$vaultUrl = $_GET['vault_url'] ?? '';
$vaultName = $_GET['name'] ?? '';

// vault_url 화이트리스트 검증 (SSRF 방지)
if ($vaultUrl && strpos($vaultUrl, 'api.php?action=vault_preview_serve&') !== 0) {
    $vaultUrl = '';
}

// vault 모드: vault_preview_serve URL로 직접 제공
if ($vaultUrl && $vaultName) {
    $filename = $vaultName;
    $is_hwpx = preg_match('/\.hwpx$/i', $filename);
    $file_ext = $is_hwpx ? 'hwpx' : 'hwp';
    $title = preg_replace('/\.hwpx?$/i', '', $filename);
    $filesize = 0;
    $file_url = $vaultUrl;
    $download_url = $vaultUrl;
    $darkmode = $_COOKIE['darkmode'] ?? 'light';
} else {

if (!$storageId || !$filePath) {
    echo __('hwp_no_file_info');
    exit;
}

$storageInfo = $storage->getStorageById($storageId);
if (!$storageInfo) {
    echo __('hwp_storage_not_found');
    exit;
}

$storageType = $storageInfo['storage_type'] ?? 'local';
$remoteTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
$isRemote = in_array($storageType, $remoteTypes);

if ($isRemote) {
    // 원격 스토리지: 파일 존재 여부 및 메타정보만 확인 (실제 다운로드는 stream에서)
    if (!preg_match('/\.hwpx?$/i', $filePath)) {
        echo __('hwp_file_not_found');
        exit;
    }
    $base_file = $filePath; // 원격은 상대 경로 그대로
    $filename = basename($filePath);
    $is_hwpx = preg_match('/\.hwpx$/i', $filePath);
    $file_ext = $is_hwpx ? 'hwpx' : 'hwp';
    $title = preg_replace('/\.hwpx?$/i', '', $filename);
    $filesize = 0; // 원격은 크기 알 수 없음 (stream에서 처리)
} else {
    // 로컬/SMB 스토리지
    $base_file = $storageInfo['path'] . '/' . $filePath;
    $base_file = realpath($base_file);
    
    // 경로 검증
    if (!$base_file || !\isSubPath($base_file, realpath($storageInfo['path']))) {
        echo __('hwp_invalid_path');
        exit;
    }
    
    // HWP 또는 HWPX 파일 확인
    if (!file_exists($base_file) || !preg_match('/\.hwpx?$/i', $base_file)) {
        echo __('hwp_file_not_found');
        exit;
    }
    
    $filename = basename($base_file);
    $is_hwpx = preg_match('/\.hwpx$/i', $base_file);
    $file_ext = $is_hwpx ? 'hwpx' : 'hwp';
    $title = preg_replace('/\.hwpx?$/i', '', $filename);
    $filesize = filesize($base_file);
}

if ($filesize > 0 && $filesize > $hwp_settings['max_file_size']) {
    echo __('hwp_file_too_large') . " (" . round($hwp_settings['max_file_size'] / 1024 / 1024) . "MB)";
    exit;
}

$darkmode = $_COOKIE['darkmode'] ?? 'light';
$file_url = "hwp_viewer.php?action=stream&storage_id=" . rawurlencode($storageId) . "&path=" . rawurlencode($filePath);
$download_url = "download.php?storage_id=" . rawurlencode($storageId) . "&path=" . rawurlencode($filePath);

} // end else (non-vault mode)

// h() 함수 정의 (없을 경우)
if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>" data-theme="<?php echo h($darkmode); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo h($title); ?> - <?php echo $is_hwpx ? 'HWPX' : 'HWP'; ?> <?php echo __('hwp_viewer_title'); ?></title>
    
    <style>
        html{opacity:0;transition:opacity .15s ease-in}
        html.ready{opacity:1}
        html.leaving{opacity:0;transition:opacity .1s ease-out}
    </style>
    
    <style>
        :root {
            --bg-color: #ffffff;
            --text-color: #333333;
            --header-bg: #f8f9fa;
            --border-color: #dee2e6;
            --btn-bg: #e9ecef;
            --btn-hover: #dee2e6;
            --viewer-bg: #e9ecef;
        }
        
        [data-theme="dark"] {
            --bg-color: #1e1e1e;
            --text-color: #e0e0e0;
            --header-bg: #2d2d2d;
            --border-color: #404040;
            --btn-bg: #3d3d3d;
            --btn-hover: #4d4d4d;
            --viewer-bg: #252525;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Malgun Gothic", "맑은 고딕", sans-serif;
            background: var(--viewer-bg);
            color: var(--text-color);
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .header {
            background: var(--header-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 8px 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 10px;
            overflow: hidden;
        }
        
        .back-btn {
            background: var(--btn-bg);
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            color: var(--text-color);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .back-btn:hover { background: var(--btn-hover); }
        
        .filename {
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 300px;
            cursor: pointer;
            user-select: none;
        }
        
        .file-badge {
            background: <?php echo $is_hwpx ? '#28a745' : '#007bff'; ?>;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .zoom-controls {
            display: flex;
            align-items: center;
            gap: 5px;
            background: var(--btn-bg);
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .zoom-controls button {
            width: 28px;
            height: 28px;
            border: none;
            background: transparent;
            cursor: pointer;
            color: var(--text-color);
            border-radius: 4px;
            font-size: 16px;
        }
        
        .zoom-controls button:hover { background: var(--btn-hover); }
        .zoom-value { font-size: 12px; min-width: 40px; text-align: center; }
        
        .viewer-container {
            flex: 1;
            overflow: auto;
            background: var(--viewer-bg);
            padding: 20px;
        }
        
        #hwp-viewer {
            background: var(--bg-color);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20mm;
            margin: 0 auto;
            max-width: 250mm;
            min-height: 297mm;
            transform-origin: top center;
            line-height: 1.8;
            word-break: keep-all;
            overflow-wrap: break-word;
        }
        
        [data-theme="dark"] #hwp-viewer {
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        
        /* HWP/HWPX 컨텐츠 스타일 */
        #hwp-viewer p { margin: 0.5em 0; }
        #hwp-viewer table { border-collapse: collapse; width: 100%; margin: 1em 0; }
        #hwp-viewer td, #hwp-viewer th { border: 1px solid var(--border-color); padding: 8px; vertical-align: top; }
        #hwp-viewer img { max-width: 100%; height: auto; display: inline-block; margin: 0.5em 0; }
        #hwp-viewer .hwpx-section { margin-bottom: 2em; }
        #hwp-viewer .hwpx-para { margin: 0.3em 0; min-height: 1em; }
        #hwp-viewer .hwpx-table { margin: 1em 0; }
        
        /* hwpjs 스타일 오버라이드 */
        #hwp-viewer .hwpjs { line-height: 1.8; }
        #hwp-viewer .hwpjs p { margin: 0.3em 0; }
        
        /* hwpjs 이미지 강제 스타일 */
        #hwp-viewer img {
            max-width: 100% !important;
            width: auto !important;
            height: auto !important;
            display: block !important;
            margin: 10px auto !important;
            visibility: visible !important;
            opacity: 1 !important;
            position: relative !important;
            z-index: 10 !important;
            min-width: 50px !important;
            min-height: 50px !important;
        }
        
        /* hwpjs shape 컨테이너 */
        #hwp-viewer [class*="shape"], 
        #hwp-viewer [class*="image"],
        #hwp-viewer [class*="pic"],
        #hwp-viewer [class*="container"],
        #hwp-viewer div[style*="position: absolute"],
        #hwp-viewer div[style*="position:absolute"] {
            position: relative !important;
            width: auto !important;
            height: auto !important;
            max-width: 100% !important;
            overflow: visible !important;
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            transform: none !important;
            left: auto !important;
            top: auto !important;
        }
        
        #hwp-viewer *[style*="width:"][style*="height:"] {
            width: auto !important;
            height: auto !important;
            max-width: 100% !important;
            overflow: visible !important;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .loading-text { color: #fff; margin-top: 15px; font-size: 14px; }
        
        .error-container {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-color);
            border-radius: 8px;
            max-width: 500px;
            margin: 40px auto;
        }
        .error-icon { font-size: 64px; margin-bottom: 20px; }
        .error-message { font-size: 16px; margin-bottom: 20px; color: var(--text-color); }
        .error-btn {
            display: inline-block;
            padding: 10px 20px;
            background: var(--btn-bg);
            color: var(--text-color);
            text-decoration: none;
            border-radius: 5px;
            margin: 5px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            white-space: nowrap;
        }
        .error-btn:hover { background: var(--btn-hover); }
        
        @media (max-width: 768px) {
            .header { gap: 5px; padding: 8px 10px; }
            .header-left { flex: 1 1 auto; min-width: 0; overflow: hidden; }
            .toolbar { flex-shrink: 0; }
            .viewer-container { padding: 5px; overflow-x: hidden; }
            #hwp-viewer {
                padding: 4mm;
                max-width: 100%;
                box-sizing: border-box;
                min-height: auto;
                word-break: break-word;
                overflow-wrap: break-word;
                overflow-x: hidden;
                font-size: 14px !important;
            }
            #hwp-viewer * {
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            #hwp-viewer h1, #hwp-viewer h2, #hwp-viewer h3,
            #hwp-viewer p, #hwp-viewer span, #hwp-viewer div {
                word-break: break-word !important;
                overflow-wrap: break-word !important;
                white-space: normal !important;
            }
            #hwp-viewer table { display: block; overflow-x: auto; max-width: 100%; }
            #hwp-viewer img { max-width: 100% !important; height: auto !important; }
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
            .zoom-controls { display: none; }
        }
        
        /* 모바일/태블릿 가로모드: 파일명 스크롤 */
        @media (max-width: 1024px) and (orientation: landscape) {
            .header { gap: 5px; padding: 8px 10px; }
            .header-left { flex: 1 1 auto; min-width: 0; overflow: hidden; }
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
            .header, .loading-overlay { display: none; }
            .viewer-container { padding: 0; overflow: visible; }
            #hwp-viewer { box-shadow: none; max-width: none; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <button class="back-btn" onclick="window.close()">✕ <?php echo __('hwp_close'); ?></button>
            <span class="filename" title="<?php echo h($filename); ?>"><?php echo h($filename); ?></span>
            <span class="file-badge"><?php echo strtoupper($file_ext); ?></span>
        </div>
        <div class="toolbar">
            <div class="zoom-controls">
                <button onclick="zoomOut()" title="<?php echo __('hwp_zoom_out'); ?>">−</button>
                <span class="zoom-value" id="zoomValue">100%</span>
                <button onclick="zoomIn()" title="<?php echo __('hwp_zoom_in'); ?>">+</button>
            </div>
            <?php 
            // rhwp 뷰어로 전환 버튼 (vault 모드도 지원 — rhwp_viewer가 vault_url 받음)
            if ($vaultUrl && $vaultName) {
                $rhwpUrl = 'rhwp_viewer.php?vault_url=' . rawurlencode($vaultUrl) . '&name=' . rawurlencode($vaultName);
            } elseif ($storageId && $filePath) {
                $rhwpUrl = 'rhwp_viewer.php?storage_id=' . rawurlencode($storageId) . '&path=' . rawurlencode($filePath);
            } else {
                $rhwpUrl = '';
            }
            if ($rhwpUrl):
            ?>
            <a href="<?php echo h($rhwpUrl); ?>" class="back-btn" title="rhwp 뷰어로 보기 (문서가 이상하게 보일 때)" style="text-decoration:none;">🔄 rhwp로 보기</a>
            <?php endif; ?>
            <a href="<?php echo h($download_url); ?>" class="back-btn" title="<?php echo __('hwp_download'); ?>">📥</a>
        </div>
    </div>
    
    <div class="viewer-container" id="viewerContainer">
        <div id="hwp-viewer"></div>
    </div>
    
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-text"><?php echo $is_hwpx ? 'HWPX' : 'HWP'; ?> <?php echo __('hwp_loading_file'); ?></div>
    </div>

<?php if ($is_hwpx): ?>
    <!-- HWPX용: JSZip -->
    <script src="assets/vendor/jszip.min.js"></script>
<?php else: ?>
    <!-- HWP용: ohah/hwpjs legacy -->
    <script>
        // hwpjs 라이브러리 로그 완전 억제
        (function() {
            const noop = function() {};
            window._originalConsole = { log: console.log, warn: console.warn, error: console.error };
            console.log = noop;
            console.warn = noop;
        })();
    </script>
    <script src="assets/vendor/cfb.min.js"></script>
    <script src="assets/vendor/pako.min.js"></script>
    <script src="assets/vendor/hwpjs.js"></script>
    <script>
        // 콘솔 복원 (hwpjs/CFB 관련 출력만 차단)
        if (window._originalConsole) {
            console.log = window._originalConsole.log;
            console.warn = window._originalConsole.warn;
            console.error = window._originalConsole.error;
        }
    </script>
<?php endif; ?>

    <script>
        const fileUrl = <?php echo json_encode($file_url); ?>;
        const downloadUrl = <?php echo json_encode($download_url); ?>;
        const filename = <?php echo json_encode($filename); ?>;
        const HWP_I18N = {
            close: <?php echo json_encode(__('hwp_close')); ?>,
            download: <?php echo json_encode(__('hwp_download')); ?>,
            fileLoadFailed: <?php echo json_encode(__('hwp_file_load_failed')); ?>,
            parseIncomplete: <?php echo json_encode(__('hwp_parse_incomplete')); ?>,
            image: <?php echo json_encode(__('hwp_image')); ?>
        };
        
        const viewerContainer = document.getElementById('viewerContainer');
        const viewerEl = document.getElementById('hwp-viewer');
        const loadingOverlay = document.getElementById('loadingOverlay');
        
        let currentZoom = <?php echo $hwp_settings['default_zoom']; ?>;
        
        // 페이지 전환 애니메이션
        document.documentElement.classList.add('ready');
        
<?php if ($is_hwpx): ?>
        // ============================================================
        // HWPX 파일 로드 (ZIP + XML)
        // ============================================================
        async function loadDocument() {
            try {
                const response = await fetch(fileUrl, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error(HWP_I18N.fileLoadFailed + ' (HTTP ' + response.status + ')');
                }
                
                const arrayBuffer = await response.arrayBuffer();
                const zip = await JSZip.loadAsync(arrayBuffer);
                
                // 이미지 추출 (BinData 폴더)
                const images = {};
                const imageFiles = [];
                
                zip.forEach((relativePath, file) => {
                    if (!file.dir && relativePath.toLowerCase().startsWith('bindata/')) {
                        imageFiles.push({ path: relativePath, file: file });
                    }
                });
                
                for (const item of imageFiles) {
                    try {
                        const data = await item.file.async('base64');
                        const filename = item.path.split('/').pop();
                        const ext = filename.split('.').pop().toLowerCase();
                        let mimeType = 'image/png';
                        if (ext === 'jpg' || ext === 'jpeg') mimeType = 'image/jpeg';
                        else if (ext === 'gif') mimeType = 'image/gif';
                        else if (ext === 'bmp') mimeType = 'image/bmp';
                        else if (ext === 'emf' || ext === 'wmf') mimeType = 'image/' + ext;
                        
                        // 여러 방식으로 매핑
                        images[filename] = `data:${mimeType};base64,${data}`;
                        images[filename.toLowerCase()] = `data:${mimeType};base64,${data}`;
                        // 확장자 제거한 이름으로도 매핑
                        const baseName = filename.replace(/\.[^.]+$/, '');
                        images[baseName] = `data:${mimeType};base64,${data}`;
                    } catch (e) {
                        // 이미지 로드 실패 무시
                    }
                }
                
                // section XML 파일들 찾기
                const sections = [];
                zip.forEach((relativePath, file) => {
                    if (relativePath.match(/Contents\/section\d+\.xml$/i)) {
                        sections.push({ path: relativePath, file: file });
                    }
                });
                
                // section 순서대로 정렬
                sections.sort((a, b) => {
                    const numA = parseInt(a.path.match(/section(\d+)/i)[1]);
                    const numB = parseInt(b.path.match(/section(\d+)/i)[1]);
                    return numA - numB;
                });
                
                let html = '';
                
                if (sections.length === 0) {
                    // Contents 폴더가 없는 경우 다른 구조 시도
                    zip.forEach((relativePath, file) => {
                        if (relativePath.match(/section\d*\.xml$/i)) {
                            sections.push({ path: relativePath, file: file });
                        }
                    });
                    sections.sort((a, b) => {
                        const numA = parseInt((a.path.match(/(\d+)/) || [0,0])[1]);
                        const numB = parseInt((b.path.match(/(\d+)/) || [0,0])[1]);
                        return numA - numB;
                    });
                }
                
                for (const section of sections) {
                    const xmlContent = await section.file.async('text');
                    html += parseHwpxSection(xmlContent, images);
                }
                
                if (!html.trim()) {
                    // XML 파싱 실패 시 텍스트 추출 시도
                    html = '<div class="info-notice">' + HWP_I18N.parseIncomplete + '</div>';
                    for (const section of sections) {
                        const xmlContent = await section.file.async('text');
                        const textOnly = xmlContent.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                        if (textOnly) {
                            html += '<p>' + escapeHtml(textOnly) + '</p>';
                        }
                    }
                }
                
                viewerEl.innerHTML = html;
                loadingOverlay.style.display = 'none';
                
                
            } catch (error) {
                showError(error.message || HWP_I18N.fileLoadFailed);
            }
        }
        
        // HWPX XML 파싱
        function parseHwpxSection(xmlContent, images) {
            const parser = new DOMParser();
            const doc = parser.parseFromString(xmlContent, 'text/xml');
            
            // 파싱 에러 체크
            const parseError = doc.querySelector('parsererror');
            
            let html = '<div class="hwpx-section">';
            
            // 네임스페이스 무시하고 모든 p 요소 찾기
            const allElements = doc.getElementsByTagName('*');
            const paragraphs = [];
            
            for (let i = 0; i < allElements.length; i++) {
                const el = allElements[i];
                const localName = el.localName || el.nodeName.split(':').pop();
                if (localName === 'p' || localName === 'P') {
                    paragraphs.push(el);
                }
            }
            
            paragraphs.forEach(para => {
                let paraText = '';
                let paraImages = [];
                
                // 재귀적으로 텍스트와 이미지 수집
                function extractContent(node) {
                    if (node.nodeType === Node.TEXT_NODE) {
                        paraText += node.textContent;
                    } else if (node.nodeType === Node.ELEMENT_NODE) {
                        const localName = node.localName || node.nodeName.split(':').pop();
                        
                        // 이미지 처리
                        if (localName === 'img' || localName === 'pic' || localName === 'picture') {
                            // binId 또는 binaryItemIDRef 속성 찾기
                            const binId = node.getAttribute('binaryItemIDRef') || 
                                         node.getAttribute('binId') ||
                                         node.getAttribute('hp:binaryItemIDRef');
                            if (binId && images[binId]) {
                                paraImages.push(images[binId]);
                            }
                            // 하위 요소에서도 찾기
                            const binItem = node.querySelector('[binaryItemIDRef], [binId]');
                            if (binItem) {
                                const ref = binItem.getAttribute('binaryItemIDRef') || binItem.getAttribute('binId');
                                if (ref && images[ref]) {
                                    paraImages.push(images[ref]);
                                }
                            }
                        }
                        
                        // 자식 노드 탐색
                        for (let i = 0; i < node.childNodes.length; i++) {
                            extractContent(node.childNodes[i]);
                        }
                    }
                }
                
                extractContent(para);
                
                // HTML 생성
                let paraHtml = '<div class="hwpx-para">';
                if (paraText.trim()) {
                    paraHtml += escapeHtml(paraText);
                }
                paraImages.forEach(imgSrc => {
                    paraHtml += `<img src="${imgSrc}" alt="${HWP_I18N.image}">`;
                });
                if (!paraText.trim() && paraImages.length === 0) {
                    paraHtml += '&nbsp;'; // 빈 문단
                }
                paraHtml += '</div>';
                html += paraHtml;
            });
            
            // 테이블 처리
            for (let i = 0; i < allElements.length; i++) {
                const el = allElements[i];
                const localName = el.localName || el.nodeName.split(':').pop();
                if (localName === 'tbl' || localName === 'table') {
                    html += parseTable(el);
                }
            }
            
            html += '</div>';
            return html;
        }
        
        function parseTable(tableEl) {
            let html = '<table class="hwpx-table"><tbody>';
            const allElements = tableEl.getElementsByTagName('*');
            let currentRow = null;
            
            for (let i = 0; i < allElements.length; i++) {
                const el = allElements[i];
                const localName = el.localName || el.nodeName.split(':').pop();
                
                if (localName === 'tr') {
                    if (currentRow) html += '</tr>';
                    html += '<tr>';
                    currentRow = el;
                } else if (localName === 'tc' || localName === 'td' || localName === 'cell') {
                    html += '<td>' + escapeHtml(el.textContent || '') + '</td>';
                }
            }
            if (currentRow) html += '</tr>';
            
            html += '</tbody></table>';
            return html;
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
<?php else: ?>
        // ============================================================
        // HWP 파일 로드 (ohah/hwpjs + CFB 직접 이미지 추출)
        // ============================================================
        
        // CFB에서 BinData 이미지 직접 추출
        function extractImagesFromCFB(arrayBuffer) {
            const images = {};
            try {
                const cfb = CFB.read(arrayBuffer, { type: 'array' });
                
                // 모든 파일 탐색
                cfb.FileIndex.forEach((entry, idx) => {
                    const fullPath = cfb.FullPaths[idx] || '';
                    
                    // BinData 폴더 내 파일 찾기
                    if (fullPath.toLowerCase().includes('bindata') && entry.content && entry.content.length > 0) {
                        let content = new Uint8Array(entry.content);
                        let mimeType = null;
                        
                        // 1. 먼저 원본 데이터의 시그니처 확인
                        mimeType = detectImageType(content);
                        
                        // 2. 이미지가 아니면 다양한 압축 해제 시도
                        if (!mimeType) {
                            // zlib 압축 (78 xx)
                            if (content[0] === 0x78) {
                                try {
                                    const decompressed = pako.inflate(content);
                                    mimeType = detectImageType(decompressed);
                                    if (mimeType) content = decompressed;
                                } catch (e) {}
                            }
                            
                            // deflate raw 압축 (헤더 없음) - HWP에서 주로 사용
                            if (!mimeType) {
                                try {
                                    const decompressed = pako.inflateRaw(content);
                                    mimeType = detectImageType(decompressed);
                                    if (mimeType) content = decompressed;
                                } catch (e) {}
                            }
                            
                            // 오프셋 시도 (앞부분에 헤더가 있을 수 있음)
                            if (!mimeType) {
                                for (let offset = 1; offset <= 4; offset++) {
                                    try {
                                        const sliced = content.slice(offset);
                                        const decompressed = pako.inflateRaw(sliced);
                                        mimeType = detectImageType(decompressed);
                                        if (mimeType) {
                                            content = decompressed;
                                            break;
                                        }
                                    } catch (e) {}
                                }
                            }
                        }
                        
                        if (mimeType) {
                            const base64 = uint8ArrayToBase64(content);
                            const binMatch = fullPath.match(/BIN(\d+)/i);
                            const binId = binMatch ? binMatch[1] : (idx + 1).toString();
                            images[binId] = `data:${mimeType};base64,${base64}`;
                        }
                    }
                });
            } catch (e) {}
            return images;
        }
        
        // 이미지 타입 감지
        function detectImageType(data) {
            if (!data || data.length < 4) return null;
            
            // PNG: 89 50 4E 47
            if (data[0] === 0x89 && data[1] === 0x50 && data[2] === 0x4E && data[3] === 0x47) {
                return 'image/png';
            }
            // JPEG: FF D8 FF
            if (data[0] === 0xFF && data[1] === 0xD8 && data[2] === 0xFF) {
                return 'image/jpeg';
            }
            // GIF: 47 49 46 38
            if (data[0] === 0x47 && data[1] === 0x49 && data[2] === 0x46) {
                return 'image/gif';
            }
            // BMP: 42 4D
            if (data[0] === 0x42 && data[1] === 0x4D) {
                return 'image/bmp';
            }
            // WEBP: 52 49 46 46 ... 57 45 42 50
            if (data[0] === 0x52 && data[1] === 0x49 && data[2] === 0x46 && data[3] === 0x46) {
                return 'image/webp';
            }
            return null;
        }
        
        // Uint8Array를 Base64로 변환 (대용량 지원)
        function uint8ArrayToBase64(uint8Array) {
            let binary = '';
            const chunkSize = 32768;
            for (let i = 0; i < uint8Array.length; i += chunkSize) {
                const chunk = uint8Array.subarray(i, Math.min(i + chunkSize, uint8Array.length));
                binary += String.fromCharCode.apply(null, chunk);
            }
            return btoa(binary);
        }
        
        // 이미지 플레이스홀더를 실제 이미지로 교체
        function replaceImagePlaceholders(html, images) {
            // hwpjs가 생성하는 이미지 참조 패턴 처리
            
            const binIds = Object.keys(images).sort((a, b) => parseInt(a) - parseInt(b));
            if (binIds.length === 0) return html;
            
            // 1. blob: URL을 가진 img 태그를 찾아서 순서대로 교체
            let imgIndex = 0;
            html = html.replace(/<img([^>]*?)src=["']blob:[^"']+["']([^>]*?)>/gi, (match, before, after) => {
                if (imgIndex < binIds.length) {
                    const binId = binIds[imgIndex];
                    imgIndex++;
                    return `<img${before}src="${images[binId]}"${after}>`;
                }
                return match;
            });
            
            // 2. BIN0001 형식의 참조도 교체
            binIds.forEach(binId => {
                const patterns = [
                    new RegExp(`BIN${binId.padStart(4, '0')}`, 'gi'),
                    new RegExp(`bindata/${binId}`, 'gi'),
                    new RegExp(`data-binid=["']${binId}["']`, 'gi'),
                ];
                patterns.forEach(pattern => {
                    if (html.match(pattern)) {
                        html = html.replace(pattern, `src="${images[binId]}"`);
                    }
                });
            });
            
            return html;
        }
        
        async function loadDocument() {
            try {
                const response = await fetch(fileUrl, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error(HWP_I18N.fileLoadFailed + ' (HTTP ' + response.status + ')');
                }
                
                const arrayBuffer = await response.arrayBuffer();
                
                // 1. CFB에서 이미지 먼저 추출
                let extractedImages = {};
                try {
                    extractedImages = extractImagesFromCFB(new Uint8Array(arrayBuffer));
                } catch (e) {
                    // console.log('이미지 추출 실패 (무시):', e);
                }
                
                // 2. hwpjs로 HTML 변환
                let html = '';
                try {
                    const hwp = new hwpjs(arrayBuffer);
                    html = hwp.getHtml();
                } catch (hwpError) {
                    // console.error('hwpjs 파싱 오류:', hwpError);
                    showUnsupportedError();
                    return;
                }
                
                // HTML이 비어있거나 유효하지 않은 경우
                if (!html || html.trim() === '' || html.trim() === '<div></div>') {
                    showUnsupportedError();
                    return;
                }
                
                // 3. 이미지 플레이스홀더 교체
                html = replaceImagePlaceholders(html, extractedImages);
                
                // 4. 이미지 처리
                const extractedCount = Object.keys(extractedImages).length;
                
                viewerEl.innerHTML = html;
                
                // 이미지 스타일 강제 적용
                if (extractedCount > 0) {
                    viewerEl.querySelectorAll('img').forEach((img) => {
                        img.style.cssText = 'max-width: 100% !important; width: auto !important; height: auto !important; display: block !important; margin: 10px auto !important; visibility: visible !important; opacity: 1 !important; position: relative !important; min-width: 100px !important; min-height: 100px !important;';
                        img.removeAttribute('width');
                        img.removeAttribute('height');
                        
                        // 부모 요소들의 position: absolute 제거
                        let parent = img.parentElement;
                        for (let i = 0; i < 15 && parent && parent !== viewerEl; i++) {
                            const computedStyle = window.getComputedStyle(parent);
                            if (computedStyle.position === 'absolute') {
                                parent.style.position = 'relative';
                                parent.style.left = 'auto';
                                parent.style.top = 'auto';
                            }
                            parent.style.width = 'auto';
                            parent.style.height = 'auto';
                            parent.style.maxWidth = '100%';
                            parent.style.overflow = 'visible';
                            parent.style.visibility = 'visible';
                            parent.style.opacity = '1';
                            parent.style.display = 'block';
                            parent = parent.parentElement;
                        }
                    });
                }
                
                loadingOverlay.style.display = 'none';
                
                
            } catch (error) {
                // console.error('HWP 로드 오류:', error);
                showError(error.message || HWP_I18N.fileLoadFailed);
            }
        }
        
        // 지원하지 않는 HWP 형식 에러 → rhwp_viewer 자동 fallback
        function showUnsupportedError() {
            loadingOverlay.style.display = 'none';
            
            // rhwp_viewer URL 생성
            const currentUrl = new URL(window.location.href);
            const rhwpUrl = currentUrl.href.replace('hwp_viewer.php', 'rhwp_viewer.php');
            
            // 자동 리다이렉트 (3초 후)
            viewerContainer.innerHTML = `
                <div class="error-container">
                    <div class="error-icon">🔄</div>
                    <div class="error-message">
                        <?= $currentLang === 'en' ? 'This HWP format is not supported by HWP Viewer.' : '이 HWP 파일은 HWP 뷰어에서 지원하지 않는 형식입니다.' ?><br>
                        <small style="color:#888;margin-top:8px;display:block;">
                            <?= $currentLang === 'en' ? 'Redirecting to RHWP Viewer...' : 'RHWP 뷰어로 자동 전환합니다...' ?>
                        </small>
                    </div>
                    <div style="display:flex;gap:10px;justify-content:center;margin-top:10px;flex-wrap:nowrap;">
                        <a href="${rhwpUrl}" class="error-btn" style="background:#00897B;color:white;">📗 RHWP <?= $currentLang === 'en' ? 'Viewer' : '뷰어' ?></a>
                        <a href="${downloadUrl}" class="error-btn">📥 <?= $currentLang === 'en' ? 'Download' : '다운로드' ?></a>
                        <button class="error-btn" onclick="window.close()"><?= $currentLang === 'en' ? 'Close' : '닫기' ?></button>
                    </div>
                </div>
            `;
            
            // 3초 후 자동 리다이렉트
            setTimeout(() => {
                window.location.href = rhwpUrl;
            }, 2000);
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
<?php endif; ?>
        
        function showError(message) {
            loadingOverlay.style.display = 'none';
            // rhwp_viewer fallback URL 생성
            const rhwpUrl = window.location.href.replace('hwp_viewer.php', 'rhwp_viewer.php');
            viewerContainer.innerHTML = `
                <div class="error-container">
                    <div class="error-icon">📄</div>
                    <div class="error-message">${escapeHtml(message)}</div>
                    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:nowrap;">
                        <a href="${rhwpUrl}" class="error-btn">🔄 rhwp 뷰어로 보기</a>
                        <a href="${downloadUrl}" class="error-btn">📥 ${HWP_I18N.download}</a>
                        <button class="error-btn" onclick="window.close()">${HWP_I18N.close}</button>
                    </div>
                </div>
            `;
        }
        
        // 줌 기능
        function zoomIn() {
            if (currentZoom < 200) {
                currentZoom += 10;
                updateZoom();
            }
        }
        
        function zoomOut() {
            if (currentZoom > 50) {
                currentZoom -= 10;
                updateZoom();
            }
        }
        
        function updateZoom() {
            document.getElementById('zoomValue').textContent = currentZoom + '%';
            viewerEl.style.transform = `scale(${currentZoom / 100})`;
        }
        
        // 문서 로드 시작
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
                        // 잘림 여부 측정: hidden 상태에서 scrollWidth와 clientWidth 비교
                        const isOver = this.scrollWidth > this.clientWidth + 2;
                        if (!isOver) {
                            // hidden 상태에서 측정 실패 시 임시 해제 후 재측정
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

    <!-- ★ ESC 키로 뷰어 탭 닫기 (rhwp_viewer.php와 동일 패턴) -->
    <script>
    (function() {
        document.addEventListener('keydown', function(e) {
            // 입력 필드(찾기 등)에 포커스 있으면 무시 (입력 ESC는 입력 취소용)
            if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable)) {
                return;
            }
            if (e.key === 'Escape') {
                window.close();
                // 팝업이 아니면 뒤로가기
                setTimeout(function() { history.back(); }, 100);
            }
        });
    })();
    </script>
<?php if ($vaultUrl): ?>
    <script>
    (function() {
        const vaultUrl = <?php echo json_encode($vaultUrl); ?>;
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
