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
require_once __DIR__ . '/api/FsIcons.php';   // ★ (2026-10-26) 선 그림 아이콘 — 아래 화면의 이모지를 바꿈

$currentLang = getLang();
$storage = new Storage();
$auth = new Auth();

// ★ (2026-11-07 11:20, 로그인 유지 풀림 재검토) 세션이 없으면 로그인 유지 토큰으로 복구 시도(index.php·api.php 와 같게) — 종전엔 이 화면이 첫 요청이면(iPhone 이 탭을 정리한 뒤 뷰어 탭을 다시 읽을 때 등) 로그인 유지를 켜도 로그인 화면/401 로 갔음(재현)
if (!$auth->isLoggedIn() && !$auth->checkRememberToken()) {
    header('Location: index.php');
    exit;
}
// ★ (2026-10-29 11:16, 20차 점검) 보관함 미리보기 연장(touch)·정리(cleanup) 요청에 붙일 보안 토큰 — 종전엔 토큰 없이(본문에 빈 값) 보내 늘 403 이라
//   열어 둔 사이 연장이 안 되고 닫을 때 임시 파일도 지워지지 않았음(재현). 서버는 POST 토큰을 머리글·폼·주소에서 읽음
$fsViewerCsrf = getCsrfToken();

// 스토리지 읽기 권한 체크
$_checkSid = (int)($_GET['storage_id'] ?? 0);
if (isset($_GET['path']) && !is_string($_GET['path'])) { http_response_code(400); exit('Invalid path'); }   // ★ (2026-11-11 13:21, 74차) path[]= 배열이면 dirname()/basename() TypeError 로 500 이었음(api.php 는 56차에 400 으로 정리, 이 진입점은 앞단을 안 거침) → 400. 문자열 경로는 그대로
$_checkPath = $_GET['path'] ?? '';
// ★ (2026-11-03 14:21, 51차 재검토) 로컬 스토리지도 원격처럼 '..'(또는 점·공백만 있는 '.. '·'...') 조각을 거부 — api.php 앞단 차단을 거치지 않는 진입점이라, 루트 위로 넘치는 '..'
//   ('../<스토리지 폴더>/secret/a.docx')나 Linux 의 '.. '·'...' 폴더를 끼운 경로가 폴더 권한·잠금 비교만 비켜 가 읽기 금지 폴더 읽기·쓰기 금지/잠긴 한글 파일 덮어쓰기가 됐음(재현)
if (preg_match('#(^|[\\\\/])[. ]*\.\.[. ]*($|[\\\\/])#', (string)$_checkPath)) { http_response_code(403); exit('Invalid path'); }
if ($_checkSid && !$storage->checkPermission($_checkSid, 'can_read')) {
    http_response_code(403);
    $currentLang = getLang();   // ★ (2026-11-02 10:28, 42차 점검) 403 화면 언어를 세션 값만 봐서, 쿠키·브라우저 언어로 정해진 영어 사용자(세션에 lang 없음)에게 한국어가 나왔음 → lang.php 판정(getLang) 사용
    exit('<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;"><h2 style="color:#d32f2f;">' . fsi_inline('ban') . ' ' . ($currentLang === 'en' ? 'Access Denied' : '접근 권한이 없습니다') . '</h2><p style="color:#666;">' . ($currentLang === 'en' ? 'You do not have permission to access this file.' : '이 파일에 대한 읽기 권한이 없습니다.') . '</p><a href="index.php" style="color:#1976d2;">' . ($currentLang === 'en' ? 'Go back' : '돌아가기') . '</a></div></body></html>');
}
// 폴더별 읽기 권한 체크
if ($_checkSid && $_checkPath) {
    $_checkDir = dirname($_checkPath);
    if ($_checkDir === '.') $_checkDir = '';
    if (!$storage->checkFolderPermission($_checkSid, ($_checkDir !== '' ? $_checkDir : $_checkPath))) { // ★ (2026-11-01 17:17, 40차 점검) 부모 폴더가 '0' 이면 ?: 가 빈 값으로 봐 항목 자신 규칙으로 검사했음 → '' 일 때만 항목 자신
        http_response_code(403);
        $currentLang = getLang();   // ★ (2026-11-02 10:28, 42차 점검) 403 화면 언어를 세션 값만 봐서, 쿠키·브라우저 언어로 정해진 영어 사용자(세션에 lang 없음)에게 한국어가 나왔음 → lang.php 판정(getLang) 사용
        exit('<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;"><h2 style="color:#d32f2f;">' . fsi_inline('ban') . ' ' . ($currentLang === 'en' ? 'Access Denied' : '접근 권한이 없습니다') . '</h2><p style="color:#666;">' . ($currentLang === 'en' ? 'You do not have permission to access this folder.' : '이 폴더에 대한 접근 권한이 없습니다.') . '</p><a href="index.php" style="color:#1976d2;">' . ($currentLang === 'en' ? 'Go back' : '돌아가기') . '</a></div></body></html>');
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
    if (!$storage->checkFolderPermission($storageId, ($fileDir !== '' ? $fileDir : $filePath))) { // ★ (2026-11-01 17:17, 40차 점검) 부모 폴더가 '0' 이면 ?: 가 빈 값으로 봐 항목 자신 규칙으로 검사했음 → '' 일 때만 항목 자신
        http_response_code(403);
        exit('No permission');
    }
    // ★ (2026-11-01 09:41, 38차 점검) 세션 잠금 풀기(rhwp_viewer 와 같음) — 종전엔 잠금을 쥔 채 원격 파일 전체를 받아, 그동안 같은 사용자의 다른 요청(메인 탭 api.php)이 기다렸음
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
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
        // ★ (2026-11-01 09:41, 38차 점검) 경로 조각이 '..'(또는 점·공백만으로 된 '.. ' 같은 조각 — Windows 쪽 서버는 끝 점·공백을 버림)일 때만 막음.
        //   종전엔 strpos 로 '..' 가 어디 있든 막아 '보고서..hwp'·'v1..2/a.hwp' 같은 정상 이름이 403 으로 열리지 않았음
        if (preg_match('#(^|[\\\\/])[. ]*\.\.[. ]*($|[\\\\/])#', $filePath)) {
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
        
        // ★ (2026-11-01 01:11, 35차 전체 점검) 요청마다 임의 이름(office_viewer 34차 후속과 같게) — 종전엔 같은 파일이면 이름이 같아, 동시에 열면 먼저 끝난 요청이 다른 요청의 임시 파일을 지웠음
        // ★ (2026-11-04 01:46, 54차 점검) 임시 이름은 임의 꼬리 + 확장자만(rhwp_viewer 와 같은 생각, 원격 분기에서 이 이름은 화면에 안 쓰임) — 종전엔 원래 이름을 통째로 붙여(앞에 53바이트)
        //   한글 약 67자를 넘는 정상 이름은 파일 이름 한도(255바이트)를 넘어 쓰기 실패 → 503 'Temp write failed'(재현), Windows 에서 원격 이름의 : * ? 등도 같은 실패
        $base_file = sys_get_temp_dir() . '/fs_hwp_' . md5($storageId . $filePath) . '_' . bin2hex(random_bytes(6)) . '.' . strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (file_put_contents($base_file, $content) === false) {   // ★ (2026-11-01 09:41, 38차 점검) 임시 파일 쓰기 실패(임시 폴더 권한·디스크 가득) 확인 — 종전엔 filesize() 가 false 라 경고와 빈 본문을 보내 화면엔 '파싱 실패'만 보였음
            @unlink($base_file);
            http_response_code(503);
            exit('Temp write failed');
        }
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
    header('Cache-Control: private, no-cache');   // ★ (2026-10-29 11:17, 20차 점검) public → private — 로그인한 사람의 파일이라 중간 공유 캐시(리버스 프록시 등)에 남지 않게(브라우저 캐시는 그대로)
    // ★ (2026-11-01 09:41, 38차 점검) max-age=86400 → no-cache(rhwp_viewer 와 같게) — 주소가 파일마다 늘 같아, 편집기로 고치거나 다시 올린 뒤에도 하루 동안 옛 내용이 보였음
    
    // Range 요청 처리
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        if (preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
            $start = $matches[1] !== '' ? intval($matches[1]) : 0;
            $end = $matches[2] !== '' ? intval($matches[2]) : $filesize - 1;
            if ($matches[1] === '' && $matches[2] !== '') {   // ★ (2026-11-01 09:41, 38차 점검) 'bytes=-N' 은 마지막 N 바이트 — 종전엔 앞에서부터 0~N 을 보냈음(N=0 이면 아래에서 416)
                $start = max(0, $filesize - intval($matches[2]));
                $end = $filesize - 1;
            }
            $end = min($end, $filesize - 1);   // ★ (2026-11-01 01:11, 35차 전체 점검) 끝값이 파일보다 크면 파일 끝까지로(종전엔 Content-Range·Content-Length 가 실제보다 커 받는 쪽이 끝없이 기다렸음)
            
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
if ((isset($_GET['vault_url']) && !is_string($_GET['vault_url'])) || (isset($_GET['name']) && !is_string($_GET['name']))) { http_response_code(400); exit('Invalid parameter'); }   // ★ (2026-11-16 21:38, 269차 점검) 74차 path[] 와 같음 — vault_url[]·name[] 배열이면 strpos·preg_match·htmlspecialchars TypeError 로 빈 500 화면이었음(재현)
$vaultUrl = $_GET['vault_url'] ?? '';
$vaultName = $_GET['name'] ?? '';

// vault_url 화이트리스트 검증 (SSRF 방지)
// ★ (2026-10-29 09:10, 20차) 앞부분만이 아니라 전체 형식까지 — 화면이 만드는 주소(encodeURIComponent 값)에 쓰이는 글자만 허용.
//   종전엔 앞부분만 봐서 뒤에 '"><img onerror=…>' 를 붙인 링크를 열면 오류 화면의 다운로드 링크(innerHTML)로 스크립트가 실행됐음(반사형 XSS)
if ($vaultUrl && !preg_match("#^api\\.php\\?action=vault_preview_serve&[A-Za-z0-9_\\-.!~*'()%=&]*$#", $vaultUrl)) {
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
// ★ (2026-10-29 09:10, 20차) download.php 는 없는 파일(404) — rhwp_viewer.php 와 같이 api.php 내려받기로
$download_url = "api.php?action=download&storage_id=" . rawurlencode($storageId) . "&path=" . rawurlencode($filePath);

} // end else (non-vault mode)

// h() 함수 정의 (없을 경우)
if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}
// ★ (2026-10-26) 화면 HTML 의 이모지 → 선 그림 아이콘(index.php 와 같은 방식). 여기(화면 출력 직전)부터만 — 위쪽 파일 전송·오류 응답은 이미 exit
ob_start('fs_icon_convert_html');
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
            /* ★ (2026-11-12 06:03, 94차 DOC-7) 휴대폰에서 파일 이름 자리 확보 — 확장자 표(이름에 이미 있음)를 숨기고 단추 사이를 좁힘. 종전엔 이름 폭이 0~7px 로 이름이 안 보였음(재현, iPhone 13) */
            .file-badge { display: none; }
            .toolbar { gap: 5px; }
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
            .header, .loading-overlay { display: none !important; }
            .viewer-container { padding: 0; overflow: visible; background: #fff; }
            #hwp-viewer { box-shadow: none; max-width: none; transform: none !important; }
            html, body { background: #fff; overflow: visible; }
            /* ★ (2026-11-12 21:53, 105차 PRT-3) 어두운 모드에서 인쇄할 때만 색 값을 밝은 모드 값으로 — 종전엔 본문 글자가 rgb(224,224,224) 옅은 회색, 배경 그래픽을 켜면 본문 상자가 검은 바탕으로 인쇄됐음(재현) */
            [data-theme="dark"] { --bg-color: #ffffff; --text-color: #333333; --header-bg: #f8f9fa; --border-color: #dee2e6; --btn-bg: #e9ecef; --btn-hover: #dee2e6; --viewer-bg: #e9ecef; }
            [data-theme="dark"] #hwp-viewer { box-shadow: none; }   /* 어두운 모드 그림자 규칙(459)이 위 인쇄 규칙보다 우선해 마지막 쪽에 회색 테두리가 찍혔음(재현) */
            /* 페이지 나눔: hwp 엔진이 페이지마다 .hwp-page 또는 .HWPPage 생성 (legacy/hwpx 공통) */
            .hwp-page, .HWPPage { page-break-after: always; break-after: page; }
            .hwp-page:last-child, .HWPPage:last-child { page-break-after: auto; break-after: auto; }
            @page { margin: 10mm; }
        }
    </style>
<style>/* ★ (2026-10-26) 선 그림 아이콘(이모지 대신, 원본 api/FsIcons.php) — 글자색을 따름 */ .fs-ico{width:1.2em;height:1.2em;vertical-align:-0.2em;flex-shrink:0} .fs-ico-ok{color:#157347} .fs-ico-err{color:#c62828} .fs-ico-warn{color:#b45309}</style>
</head>
<body>
<?php /* ★ (2026-10-26) 선 그림 아이콘 묶음 + 스크립트가 넣는 이모지도 바꾸는 fs-icons.js (index.php·share.php 와 같음) */ echo fs_icon_sprite(); ?>
<script>window.FS_ICO_EMOJI = <?= fs_icon_map_json() ?>;</script>
<script src="assets/js/fs-icons.js?v=<?= substr(md5_file(__DIR__ . '/assets/js/fs-icons.js'), 0, 10) ?>"></script>
    <div class="header">
        <div class="header-left">
            <button class="back-btn" onclick="window.close()">✕ <?php echo __('hwp_close'); ?></button>
            <span class="filename fs-noico" title="<?php echo h($filename); ?>"><?php echo h($filename); ?></span>
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
            <?php /* ★ (2026-11-02 10:28, 42차 점검) 번역 함수 밖 한국어 고정이었음 → __() 로(한국어 기본 문구는 그대로) */ ?>
            <a href="<?php echo h($rhwpUrl); ?>" class="back-btn" title="<?php echo h(__('hwp_switch_rhwp_title', 'rhwp 뷰어로 보기 (문서가 이상하게 보일 때)')); ?>" style="text-decoration:none;">🔄 <?php echo h(__('hwp_switch_rhwp', 'rhwp로 보기')); ?></a>
            <?php endif; ?>
            <button class="back-btn" onclick="printDocument()" title="<?php echo __('viewer_print'); ?> (Ctrl+P)">🖨️</button>
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
    <script src="assets/vendor/jszip.min.js?v=3.10.2"></script>
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
    <script src="assets/vendor/cfb.min.js?v=1.2.2"></script>
    <script src="assets/vendor/pako.min.js?v=3.0.3"></script><!-- ★ (2026-10-30 11:30) pako 2 → 3.0.3(inflate·inflateRaw·windowBits·오류 때 예외 동작 같음 확인), cfb 1.2.2 는 이미 최신 -->
    <script src="assets/vendor/hwpjs.js?v=20261114b"></script><!-- ★ (2026-11-14 00:53, 122차) 글머리표 'undefined' 수정본을 브라우저가 옛 파일 대신 새로 받게 표시만 붙임 · 126차 기호 글꼴 글머리표 짝 추가로 b -->
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
        const fileUrl = <?php echo json_encode($file_url, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const downloadUrl = <?php echo json_encode($download_url, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const filename = <?php echo json_encode($filename, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const HWP_I18N = {
            close: <?php echo json_encode(__('hwp_close')); ?>,
            download: <?php echo json_encode(__('hwp_download')); ?>,
            fileLoadFailed: <?php echo json_encode(__('hwp_file_load_failed')); ?>,
            parseIncomplete: <?php echo json_encode(__('hwp_parse_incomplete')); ?>,
            image: <?php echo json_encode(__('hwp_image')); ?>,
            switchRhwp: <?php echo json_encode(__('hwp_switch_rhwp_long', 'rhwp 뷰어로 보기'), JSON_HEX_TAG | JSON_HEX_AMP); ?>   // ★ (2026-11-02 10:28, 42차 점검) 오류 화면 단추 문구(종전 한국어 고정)
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

                // ★ (2026-11-12 06:02, 94차 DOC-6) 본문 section 은 있는데 문단·표를 하나도 못 읽었으면(암호 걸린 HWPX — section 이 암호화돼 XML 이 아님) rhwp 뷰어로 자동 전환
                //   (HWP 쪽 showUnsupportedError 와 같은 주소 규칙·보관함 임시 파일 유지). rhwp 가 '암호가 걸린 문서' 를 안내함. 종전엔 안내 없이 빈 쪽만 보였음(재현: HWP5-password-123456.hwpx)
                if (html.trim() && !/class="hwpx-(para|table)"/.test(html)) {
                    window.__fsVaultKeepTemp = true;
                    window.location.replace(window.location.href.replace('hwp_viewer.php', 'rhwp_viewer.php'));
                    return;
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

        // ★ (2026-11-09 13:58, 58차 G2) hwpjs 생성자를 Worker 에서 시간 제한 안에 먼저 돌려 봄 — 시간 초과일 때만 true(그 밖·Worker 못 만듦은 false → 종전 흐름). 제한 15초+1MB당 10초: 정상 샘플 14개 중 최장 kps-ai.hwp(486KB) 0.69초, 멈춤 문서는 안 끝나고 메모리만 늘어남(80초 2GB)
        function hwpjsProbeTimedOut(arrayBuffer) {
            return new Promise((resolve) => {
                let worker = null, url = null, timer = null;
                const done = (timedOut) => {
                    if (timer) clearTimeout(timer);
                    if (worker) worker.terminate();
                    if (url) URL.revokeObjectURL(url);
                    resolve(timedOut);
                };
                try {
                    const base = new URL('assets/vendor/', window.location.href).href;
                    const src = 'self.Image=function(){};' +
                        'importScripts(' + JSON.stringify(base + 'cfb.min.js?v=1.2.2') + ',' + JSON.stringify(base + 'pako.min.js?v=3.0.3') + ',' + JSON.stringify(base + 'hwpjs.js') + ');' +
                        'hwpjs.prototype.createStyle=function(){};' +
                        'self.onmessage=function(e){try{new hwpjs(e.data);}catch(x){} self.postMessage(1);};';
                    url = URL.createObjectURL(new Blob([src], { type: 'text/javascript' }));
                    worker = new Worker(url);
                    worker.onmessage = () => done(false);
                    worker.onerror = (ev) => { ev.preventDefault(); done(false); };
                    timer = setTimeout(() => done(true), 15000 + Math.ceil(arrayBuffer.byteLength / 1048576 * 10000));
                    worker.postMessage(arrayBuffer);   // 복사(아래 본 해석에 그대로 씀)
                } catch (e) {
                    done(false);
                }
            });
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
                if (await hwpjsProbeTimedOut(arrayBuffer)) {   // ★ (2026-11-09 13:58, 58차 G2) 해석이 끝나지 않는 문서는 기존 자동 전환과 같게 rhwp 로(종전엔 탭째 멈춤)
                    showUnsupportedError();
                    return;
                }
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
                        <a href="${escapeHtml(downloadUrl).replace(/"/g, '&quot;')}" class="error-btn">📥 <?= $currentLang === 'en' ? 'Download' : '다운로드' ?></a>
                        <button class="error-btn" onclick="window.close()"><?= $currentLang === 'en' ? 'Close' : '닫기' ?></button>
                    </div>
                </div>
            `;
            
            // 3초 후 자동 리다이렉트
            setTimeout(() => {
                window.__fsVaultKeepTemp = true;   // ★ (2026-10-31 13:41, 34차 후속 개선) rhwp_viewer 가 같은 보관함 임시 파일을 쓰므로 pagehide 정리를 건너뜀(아래 보관함 정리 참고)
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
                        <a href="${rhwpUrl}" class="error-btn">🔄 ${escapeHtml(HWP_I18N.switchRhwp)}</a>
                        <a href="${escapeHtml(downloadUrl).replace(/"/g, '&quot;')}" class="error-btn">📥 ${HWP_I18N.download}</a>
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
        
        // 인쇄 (브라우저 기본 인쇄 → 사용자가 "PDF로 저장" 선택 가능)
        //   @media print CSS로 툴바/배경 숨김 + 본문만 인쇄
        function printDocument() {
            window.print();
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
                const _touch = function() {
                    fetch('api.php?action=vault_preview_touch&csrf_token=' + encodeURIComponent(<?php echo json_encode($fsViewerCsrf); ?>), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ temp_id: tempId, storage_id: parseInt(storageId), path: path || '' })
                    }).catch(function(){});
                };
                let keepAlive = setInterval(_touch, 120000);
                
                // 탭 닫기 감지 → cleanup
                // ★ (2026-10-31 13:41, 34차 후속 개선) 종전엔 visibilitychange 로 '5초 가려지면' 정리해, 다른 탭·다른 앱으로 잠깐 옮겨도 임시 복호화 파일이 지워지고
                //   연장 타이머도 멈춰 돌아와서 📥·'rhwp로 보기'가 404 였음 → 실제로 페이지를 떠날 때(pagehide)만 정리.
                //   단 같은 탭에서 rhwp_viewer(같은 vault_url 사용)로 넘어갈 때는 지우지 않음(종전에도 이동 시엔 타이머가 사라져 지우지 않았음 — 남은 것은 서버 정리)
                document.addEventListener('click', function(e) {
                    const a = e.target && e.target.closest ? e.target.closest('a[href*="rhwp_viewer.php"]') : null;
                    if (a) window.__fsVaultKeepTemp = true;
                }, true);
                // ★ (2026-10-31 14:09, 34차 후속 개선) 브라우저가 페이지를 캐시해 두는 경우(뒤로 가기 캐시, e.persisted)는 지우지 않고 타이머만 멈춤 →
                //   돌아오면(pageshow persisted) 바로 연장하고 타이머·'유지' 표시를 되살림(종전엔 뒤로 오면 📥 가 404, 표시가 남아 닫아도 안 지웠음)
                window.addEventListener('pageshow', function(e) {
                    if (!e.persisted) return;
                    window.__fsVaultKeepTemp = false;
                    clearInterval(keepAlive); _touch(); keepAlive = setInterval(_touch, 120000);
                });
                window.addEventListener('pagehide', function(e) {
                    clearInterval(keepAlive);
                    if (e.persisted || window.__fsVaultKeepTemp) return;
                    navigator.sendBeacon('api.php?action=vault_preview_cleanup&csrf_token=' + encodeURIComponent(<?php echo json_encode($fsViewerCsrf); ?>),
                        JSON.stringify({ temp_id: tempId, storage_id: parseInt(storageId), path: path || '', csrf_token: '' }));
                });
            }
        } catch(e) {}
    })();
    </script>
<?php endif; ?>
</body>
</html>
