<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * OnlyOffice 문서 편집기 페이지
 * 
 * 사용법: onlyoffice.php?storage_id=1&path=/documents/file.docx&mode=edit
 * 
 * 지원 파일:
 * - 문서: docx, doc, odt, txt, rtf, html, epub
 * - 스프레드시트: xlsx, xls, ods, csv
 * - 프레젠테이션: pptx, ppt, odp
 */

require_once __DIR__ . '/config.php';

// 세션 시작
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/api/JsonDB.php';
require_once __DIR__ . '/api/Auth.php';
require_once __DIR__ . '/api/Storage.php';

$db = JsonDB::getInstance();
$auth = new Auth();
$storage = new Storage();

// 로그인 확인
if (!$auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// OnlyOffice 설정 로드 (settings.json에서)
$settings = $db->load('settings');
$onlyofficeSettings = $settings['onlyoffice'] ?? [];
$ONLYOFFICE_SERVER = $onlyofficeSettings['server'] ?? '';
$ONLYOFFICE_SECRET = $onlyofficeSettings['secret'] ?? '';
$ONLYOFFICE_CALLBACK_URL = $onlyofficeSettings['callback_url'] ?? '';

// OnlyOffice 설정 확인
if (empty($onlyofficeSettings['enabled']) || empty($ONLYOFFICE_SERVER)) {
    die('<h2>' . __('oo_config_error') . '</h2><p>' . __('oo_config_guide') . '</p><a href="index.php">' . __('oo_go_back') . '</a>');
}

// OnlyOffice 서버 URL에서 도메인 추출하여 CSP에 추가
$parsedUrl = parse_url($ONLYOFFICE_SERVER);
$onlyofficeHost = '';
if ($parsedUrl) {
    $scheme = $parsedUrl['scheme'] ?? 'http';
    $host = $parsedUrl['host'] ?? '';
    $port = $parsedUrl['port'] ?? '';
    
    if ($host) {
        $onlyofficeHost = $scheme . '://' . $host;
        if ($port) {
            $onlyofficeHost .= ':' . $port;
        }
    }
}

// CSP 헤더 설정 (OnlyOffice 서버 허용)
if ($onlyofficeHost) {
    header("Content-Security-Policy: default-src 'self' {$onlyofficeHost}; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: {$onlyofficeHost}; style-src 'self' 'unsafe-inline' {$onlyofficeHost}; img-src 'self' data: blob: {$onlyofficeHost}; font-src 'self' {$onlyofficeHost} data:; connect-src 'self' {$onlyofficeHost}; frame-src 'self' {$onlyofficeHost}; frame-ancestors 'self'; worker-src 'self' blob:;");
}

// 파라미터 가져오기
$storageId = (int)($_GET['storage_id'] ?? 0);
$filePath = $_GET['path'] ?? '';
$mode = $_GET['mode'] ?? 'edit'; // edit, view
if (!in_array($mode, ['edit', 'view'])) $mode = 'edit';

if (!$storageId || !$filePath) {
    die('<h2>' . __('oo_invalid_request') . '</h2><p>' . __('oo_invalid_request_desc') . '</p><a href="index.php">' . __('oo_go_back') . '</a>');
}

// 스토리지 권한 확인
if (!$storage->checkPermission($storageId, 'can_read')) {
    die('<h2>' . __('oo_no_permission') . '</h2><p>' . __('oo_no_permission_desc') . '</p><a href="index.php">' . __('oo_go_back') . '</a>');
}

// 폴더별 권한 체크
$ooFileDir = dirname($filePath);
if ($ooFileDir === '.') $ooFileDir = '';
if (!$storage->checkFolderPermission($storageId, $ooFileDir ?: $filePath)) {
    die('<h2>' . __('oo_no_permission') . '</h2><p>' . __('oo_no_permission_desc') . '</p><a href="index.php">' . __('oo_go_back') . '</a>');
}

// 쓰기 모드일 때 쓰기 권한 확인
if ($mode === 'edit' && (!$storage->checkPermission($storageId, 'can_write') || !$storage->checkFolderPermission($storageId, $ooFileDir ?: $filePath, 'can_write'))) {
    $mode = 'view'; // 쓰기 권한 없으면 보기 모드로 전환
}

// 파일 정보 가져오기
$storageInfo = $storage->getStorageById($storageId);
if (!$storageInfo) {
    die('<h2>' . __('oo_storage_not_found') . '</h2><p>' . __('oo_storage_not_found_desc') . '</p><a href="index.php">' . __('oo_go_back') . '</a>');
}

$basePath = $storageInfo['path'] ?? '';

// 디버그: 경로 정보 확인
$fullPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath), '/\\');

// 경로 안전성 검사
$realBasePath = realpath($basePath);
$realFullPath = realpath($fullPath);

if (!$realBasePath || !$realFullPath || strpos($realFullPath, $realBasePath) !== 0) {
    die('<h2>' . __('oo_invalid_path') . '</h2><p>' . __('oo_invalid_path_desc') . '</p><a href="index.php">' . __('oo_go_back') . '</a>');
}

if (!file_exists($fullPath) || !is_file($fullPath)) {
    die('<h2>' . __('oo_file_not_found') . '</h2><p>' . __('oo_file_not_found_desc') . '</p><a href="index.php">' . __('oo_go_back') . '</a>');
}

// 파일 정보
$fileName = basename($fullPath);
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$fileSize = filesize($fullPath);
$fileModified = filemtime($fullPath);

// OnlyOffice 문서 타입 결정
$documentTypes = [
    'word' => ['docx', 'doc', 'odt', 'txt', 'rtf', 'html', 'htm', 'epub', 'docm', 'dot', 'dotx', 'dotm', 'hwp', 'hwpx'],
    'cell' => ['xlsx', 'xls', 'ods', 'csv', 'xlsm', 'xlt', 'xltx', 'xltm'],
    'slide' => ['pptx', 'ppt', 'odp', 'pptm', 'pot', 'potx', 'potm', 'pps', 'ppsx', 'ppsm'],
    // PDF 편집 (OnlyOffice Docs 8.1+ PDF 에디터). 낮은 버전은 프론트에서 라우팅 차단됨.
    'pdf' => ['pdf', 'djvu', 'oxps', 'xps']
];

$documentType = null;
foreach ($documentTypes as $type => $extensions) {
    if (in_array($fileExt, $extensions)) {
        $documentType = $type;
        break;
    }
}

if (!$documentType) {
    die('<h2>' . __('oo_unsupported_file') . '</h2><p>' . __('oo_unsupported_file_desc') . ': .' . htmlspecialchars($fileExt) . '</p><a href="index.php">' . __('oo_go_back') . '</a>');
}

// 사용자 정보
$user = $auth->getUser();
$userId = $user['id'] ?? 0;
$userName = $user['username'] ?? 'Anonymous';

// 문서 고유 키 (파일 변경 시 갱신되어야 함)
$documentKey = md5($storageId . ':' . $filePath . ':' . $fileModified . ':' . $fileSize);

// 콜백 URL (OnlyOffice에서 저장 시 호출)
$callbackBaseUrl = $ONLYOFFICE_CALLBACK_URL ?: (
    ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') .
    '://' . $_SERVER['HTTP_HOST']
);
$callbackBaseUrl = rtrim($callbackBaseUrl, '/');
$callbackUrl = $callbackBaseUrl . '/api.php?action=onlyoffice_callback';

// 파일 다운로드 URL
$fileUrl = $callbackBaseUrl . '/api.php?action=onlyoffice_download&storage_id=' . $storageId . '&path=' . rawurlencode($filePath) . '&key=' . $documentKey;

// 디버그 로그 (펜닐 v5.8.1e — 파일 존재 시에만 활성)
$ooDataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
if (file_exists($ooDataDir . '/onlyoffice_debug.log')) {
    @file_put_contents($ooDataDir . '/onlyoffice_debug.log', date('H:i:s') . " OPEN file=$filePath fileUrl=$fileUrl callbackBase=$callbackBaseUrl mode=$mode\n", FILE_APPEND);
}

// OnlyOffice 설정
$config = [
    'document' => [
        'fileType' => $fileExt,
        'key' => $documentKey,
        'title' => $fileName,
        'url' => $fileUrl,
        'permissions' => [
            'download' => true,
            'edit' => ($mode === 'edit'),
            'print' => true,
            'review' => ($mode === 'edit'),
            'comment' => ($mode === 'edit'),
        ]
    ],
    'documentType' => $documentType,
    'editorConfig' => [
        'callbackUrl' => $callbackUrl . '&storage_id=' . $storageId . '&path=' . rawurlencode($filePath) . '&key=' . $documentKey,
        'lang' => 'ko',
        'mode' => $mode,
        'user' => [
            'id' => (string)$userId,
            'name' => $userName
        ],
        'customization' => [
            'autosave' => true,
            'chat' => true,
            'comments' => true,
            'compactHeader' => false,
            'compactToolbar' => false,
            'feedback' => false,
            'forcesave' => true,
            'goback' => [
                'url' => $callbackBaseUrl . '/index.php'
            ],
            'help' => false,
            'hideRightMenu' => false,
            'toolbarNoTabs' => false
        ]
    ],
    'type' => 'desktop', // desktop, mobile, embedded
    'width' => '100%',
    'height' => '100%'
];

// JWT 토큰 생성 (보안)
$token = '';
if (!empty($ONLYOFFICE_SECRET)) {
    // Base64 URL-safe 인코딩 함수
    $base64UrlEncode = function($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };
    
    $header = $base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = $base64UrlEncode(json_encode($config));
    $signature = $base64UrlEncode(hash_hmac('sha256', "$header.$payload", $ONLYOFFICE_SECRET, true));
    $token = "$header.$payload.$signature";
    $config['token'] = $token;
}

$configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($fileName) ?> - OnlyOffice</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        html, body {
            height: 100%;
            overflow: hidden;
        }
        #placeholder {
            height: 100%;
        }
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #666;
        }
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 15px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .error-message {
            display: none;
            text-align: center;
            padding: 50px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .error-message h2 {
            color: #e74c3c;
            margin-bottom: 15px;
        }
        .error-message p {
            color: #666;
            margin-bottom: 20px;
        }
        .error-message a {
            color: #3498db;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div id="placeholder">
        <div class="loading">
            <div class="loading-spinner"></div>
            <span><?= __('oo_loading') ?></span>
        </div>
    </div>
    
    <div id="error-message" class="error-message">
        <h2><?= __('oo_connection_error') ?></h2>
        <p><?= __('oo_connection_error_desc') ?></p>
        <p><?= __('oo_server_url') ?>: <?= htmlspecialchars($ONLYOFFICE_SERVER) ?></p>
        <a href="index.php">← <?= __('oo_go_back') ?></a>
    </div>

    <script src="<?= htmlspecialchars($ONLYOFFICE_SERVER) ?>/web-apps/apps/api/documents/api.js"></script>
    <script>
        var config = <?= $configJson ?>;
        
        // OnlyOffice API 로드 확인
        if (typeof DocsAPI === 'undefined') {
            document.getElementById('placeholder').style.display = 'none';
            document.getElementById('error-message').style.display = 'block';
        } else {
            // 편집기 초기화
            var docEditor = new DocsAPI.DocEditor("placeholder", config);
        }
    </script>
</body>
</html>
