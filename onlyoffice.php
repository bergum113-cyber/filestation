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
// ★ (2026-11-07 11:20, 로그인 유지 풀림 재검토) 세션이 없으면 로그인 유지 토큰으로 복구 시도(index.php·api.php 와 같게) — 종전엔 이 화면이 첫 요청이면(iPhone 이 탭을 정리한 뒤 뷰어 탭을 다시 읽을 때 등) 로그인 유지를 켜도 로그인 화면/401 로 갔음(재현)
if (!$auth->isLoggedIn() && !$auth->checkRememberToken()) {
    header('Location: index.php');
    exit;
}
$auth->isLoggedIn();   // ★ (2026-11-09 14:12, 58차) 로그인 유지 토큰으로 복구한 첫 요청도 세션 목록에 기록(index.php 와 같게 — 다른 뷰어는 뒤에서 확인을 다시 불러 원래 기록됨, 이 파일만 안 됐음(원본 대조로 확인). 결과는 쓰지 않음)

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
if (isset($_GET['path']) && !is_string($_GET['path'])) { http_response_code(400); exit('Invalid path'); }   // ★ (2026-11-11 13:21, 74차) path[]= 배열이면 dirname()/basename() TypeError 로 500 이었음(api.php 는 56차에 400 으로 정리, 이 진입점은 앞단을 안 거침) → 400. 문자열 경로는 그대로
$filePath = $_GET['path'] ?? '';
// ★ (2026-11-03 14:21, 51차 재검토) 로컬 스토리지도 원격처럼 '..'(또는 점·공백만 있는 '.. '·'...') 조각을 거부 — api.php 앞단 차단을 거치지 않는 진입점이라, 루트 위로 넘치는 '..'
//   ('../<스토리지 폴더>/secret/a.docx')나 Linux 의 '.. '·'...' 폴더를 끼운 경로가 폴더 권한·잠금 비교만 비켜 가 읽기 금지 폴더 읽기·쓰기 금지/잠긴 한글 파일 덮어쓰기가 됐음(재현)
if (preg_match('#(^|[\\\\/])[. ]*\.\.[. ]*($|[\\\\/])#', (string)$filePath)) { http_response_code(403); exit('Invalid path'); }
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
if (!$storage->checkFolderPermission($storageId, ($ooFileDir !== '' ? $ooFileDir : $filePath))) { // ★ (2026-11-01 17:17, 40차 점검) 부모 폴더가 '0' 이면 ?: 가 빈 값으로 봐 항목 자신 규칙으로 검사했음 → '' 일 때만 항목 자신
    die('<h2>' . __('oo_no_permission') . '</h2><p>' . __('oo_no_permission_desc') . '</p><a href="index.php">' . __('oo_go_back') . '</a>');
}

// 쓰기 모드일 때 쓰기 권한 확인
if ($mode === 'edit' && (!$storage->checkPermission($storageId, 'can_write') || !$storage->checkFolderPermission($storageId, ($ooFileDir !== '' ? $ooFileDir : $filePath), 'can_write'))) { // ★ (2026-11-01 17:17, 40차 점검) 부모 폴더가 '0' 이면 ?: 가 빈 값으로 봐 항목 자신 규칙으로 검사했음 → '' 일 때만 항목 자신
    $mode = 'view'; // 쓰기 권한 없으면 보기 모드로 전환
}
// ★ (2026-10-29 10:26, 20차 점검) 잠긴 파일은 보기 모드로 — 종전엔 편집으로 열려 저장(콜백)되면 잠긴 파일을 덮어썼음(재현). 텍스트·한글 편집기 저장과 같은 기준
if ($mode === 'edit' && (new FileManager())->isFileLockedPublic((int)$storageId, (string)$filePath)) {   // ★ (2026-10-31 12:03, 34차 전체 점검) 글자 그대로 비교라 'a//b'·'/a'·대소문자(윈도우) 표기로 잠금을 지났음 → 탐색기와 같은 정리 비교
    $mode = 'view';
}

// 파일 정보 가져오기
$storageInfo = $storage->getStorageById($storageId);
if (!$storageInfo) {
    die('<h2>' . __('oo_storage_not_found') . '</h2><p>' . __('oo_storage_not_found_desc') . '</p><a href="index.php">' . __('oo_go_back') . '</a>');
}

$basePath = $storageInfo['path'] ?? '';
// ★ (2026-10-29 22:39, 24차 점검) 기준 경로가 비면(원격 스토리지) 거부 — realpath('') 가 앱 폴더라 앱 폴더 아래 파일을 편집기로 열고 저장할 수 있었음(api.php resolveOnlyOfficePath 와 같음)
if (trim((string)$basePath) === '') {
    die('<h2>' . __('oo_invalid_path') . '</h2><p>' . __('oo_invalid_path_desc') . '</p><a href="index.php">' . __('oo_go_back') . '</a>');
}

// 디버그: 경로 정보 확인
$fullPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath), '/\\');

// 경로 안전성 검사
$realBasePath = realpath($basePath);
$realFullPath = realpath($fullPath);

// ★ (2026-10-29 11:16, 20차 점검) 접두사 비교(strpos) → 경로 단위 포함 검사(isSubPath) — 종전엔 '../store2/x.docx' 처럼 스토리지 폴더 이름으로 시작하는
//   옆 폴더의 문서도 통과해 편집기가 열렸음(재현. 이 페이지엔 api.php 의 '..' 차단이 없음)
if (!$realBasePath || !$realFullPath || !isSubPath($realFullPath, $realBasePath)) {
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
// ★ (2026-10-28 18:22, 소스 점검 5단계) 발급한 키 등록 — 다운로드·저장 콜백이 이 파일에 대해 발급한 키인지 확인함(config.php fsOoKeyRegister)
fsOoKeyRegister($documentKey, (int)$storageId, (string)$filePath, $mode);

// 콜백 URL (OnlyOffice에서 저장 시 호출)
// ★ (2026-11-02 10:26, 42차 점검) 자동값이 scheme://HTTP_HOST 뿐이라 하위 폴더(/fs/) 설치면 OnlyOffice 가 /api.php 를 불러 404, HTTPS 를 끝내는
//   프록시 뒤에선 http:// 가 나갔음(재현) → 공유 URL(ShareManager getShareUrl)처럼 dirname(SCRIPT_NAME) 을 붙이고, X-Forwarded-Proto/SSL 은
//   신뢰 프록시(TRUSTED_PROXIES)에서 온 요청일 때만 믿음. 관리자가 넣은 콜백 URL 은 그대로 씀
$ooFromTrustedProxy = (function () {
    $ra = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $bin = @inet_pton($ra);
    if ($bin === false) return false;
    foreach ((defined('TRUSTED_PROXIES') ? (array)TRUSTED_PROXIES : []) as $p) {
        $p = (string)$p;
        if (strpos($p, '/') === false) { if ($ra === $p) return true; continue; }
        [$net, $bits] = explode('/', $p, 2);
        $nb = @inet_pton($net);
        if ($nb === false || strlen($nb) !== strlen($bin) || !ctype_digit($bits)) continue;
        $bits = (int)$bits; $bytes = intdiv($bits, 8); $rem = $bits % 8;
        if ($bits > strlen($bin) * 8) continue;
        if (substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) continue;
        if ($rem === 0 || ((ord($bin[$bytes]) ^ ord($nb[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) return true;
    }
    return false;
})();
$ooIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    || ($ooFromTrustedProxy && (
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')));
$ooDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
// 사용자 브라우저 기준 주소('돌아가기' 링크용) — 콜백 URL 설정(서버끼리 쓰는 내부 주소일 수 있음)과 상관없음
$ooBrowserBaseUrl = ($ooIsHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $ooDir;
$callbackBaseUrl = $ONLYOFFICE_CALLBACK_URL ?: $ooBrowserBaseUrl;
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
        'lang' => (function_exists('getLang') && getLang() === 'en') ? 'en' : 'ko',   // ★ (2026-11-02 10:35, 42차 점검) 'ko' 고정이라 영어 사용자도 편집기 화면이 한국어였음(나머지 파일 담당 보고) — 앱 언어를 따름
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
                'url' => $ooBrowserBaseUrl . '/index.php'   // ★ (2026-11-02 10:26, 42차 점검) 종전엔 콜백 URL 설정값을 써서, 화면 안내대로 Docker 내부 주소(http://172.17.0.1)를 넣으면 '돌아가기'가 사용자를 그 주소로 보냈음
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

// ★ (2026-10-29 18:47, 22차 점검) <script> 안에 넣으므로 < > & ' 를 \u 로(JSON_HEX_*) — 종전엔 리눅스에서 파일 이름에 '</script>' 가 있으면 스크립트 구간이 끊겨 주입될 수 있었음.
//   JSON 값은 그대로라 OnlyOffice 설정·JWT 서명(위에서 이미 만든 값)에는 영향 없음
$configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
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
