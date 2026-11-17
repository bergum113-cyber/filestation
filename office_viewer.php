<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * 웹하드 Office 문서 뷰어
 * 
 * 지원 형식:
 * - DOCX (Microsoft Word) - docx-preview
 * - XLSX (Microsoft Excel) - SheetJS
 * - XLS·ODS (Excel 97-2003·OpenDocument) - SheetJS (★ 2026-10-30 11:30 추가)
 * - PPTX (Microsoft PowerPoint) - pptx-preview (★ 2026-10-30 11:30 — 종전엔 글자만 뽑아 보여 줬음)
 * - DOC/PPT (레거시) - 안내 메시지
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/api/FsIcons.php';   // ★ (2026-10-26) 선 그림 아이콘 — 아래 화면의 이모지를 바꿈

$currentLang = getLang();

// 인증 확인
$auth = new Auth();
// ★ (2026-11-07 11:20, 로그인 유지 풀림 재검토) 세션이 없으면 로그인 유지 토큰으로 복구 시도(index.php·api.php 와 같게) — 종전엔 이 화면이 첫 요청이면(iPhone 이 탭을 정리한 뒤 뷰어 탭을 다시 읽을 때 등) 로그인 유지를 켜도 로그인 화면/401 로 갔음(재현)
if (!$auth->isLoggedIn() && !$auth->checkRememberToken()) {
    header('HTTP/1.1 401 Unauthorized');
    exit(__('api_login_required'));
}
// ★ (2026-10-29 11:16, 20차 점검) 보관함 미리보기 연장(touch)·정리(cleanup) 요청에 붙일 보안 토큰 — 종전엔 토큰 없이(본문에 빈 값) 보내 늘 403 이라
//   열어 둔 사이 연장이 안 되고 닫을 때 임시 파일도 지워지지 않았음(재현). 서버는 POST 토큰을 머리글·폼·주소에서 읽음
$fsViewerCsrf = getCsrfToken();

$storage = new Storage();

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
if ($_checkSid && $_checkPath) {
    $_checkDir = dirname($_checkPath);
    if ($_checkDir === '.') $_checkDir = '';
    if (!$storage->checkFolderPermission($_checkSid, ($_checkDir !== '' ? $_checkDir : $_checkPath))) { // ★ (2026-11-01 17:17, 40차 점검) 부모 폴더가 '0' 이면 ?: 가 빈 값으로 봐 항목 자신 규칙으로 검사했음 → '' 일 때만 항목 자신
        http_response_code(403);
        $currentLang = getLang();   // ★ (2026-11-02 10:28, 42차 점검) 403 화면 언어를 세션 값만 봐서, 쿠키·브라우저 언어로 정해진 영어 사용자(세션에 lang 없음)에게 한국어가 나왔음 → lang.php 판정(getLang) 사용
        exit('<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;"><h2 style="color:#d32f2f;">' . fsi_inline('ban') . ' ' . ($currentLang === 'en' ? 'Access Denied' : '접근 권한이 없습니다') . '</h2><p style="color:#666;">' . ($currentLang === 'en' ? 'You do not have permission to access this folder.' : '이 폴더에 대한 접근 권한이 없습니다.') . '</p><a href="index.php" style="color:#1976d2;">' . ($currentLang === 'en' ? 'Go back' : '돌아가기') . '</a></div></body></html>');
    }
}
// ★ (2026-11-02 10:28, 42차 점검) 세션 잠금 풀기(hwp_viewer·rhwp_viewer 와 같음) — 이 파일만 닫지 않아, 원격 문서를 최대 50MB 받는 동안과 readfile 하는 동안
//   같은 사용자의 다른 요청(메인 탭 api.php)이 기다렸음(파일 세션). 이 아래로는 세션을 읽기만 함(CSRF 토큰은 위에서 이미 만듦)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// 파라미터 확인
$storageId = (int)($_GET['storage_id'] ?? 0);
$filePath = $_GET['path'] ?? '';
if ((isset($_GET['vault_url']) && !is_string($_GET['vault_url'])) || (isset($_GET['name']) && !is_string($_GET['name']))) { http_response_code(400); exit('Invalid parameter'); }   // ★ (2026-11-16 21:38, 269차 점검) 74차 path[] 와 같음 — vault_url[]·name[] 배열이면 strpos·preg_match·htmlspecialchars TypeError 로 빈 500 화면이었음(재현)
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
    $isLegacy = in_array($ext, ['doc', 'ppt']);   // ★ (2026-10-30 11:30) xls 는 SheetJS 로 열림
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
    // ★ (2026-11-01 09:41, 38차 점검) 경로 조각이 '..'(또는 점·공백만으로 된 '.. ' 같은 조각 — Windows 쪽 서버는 끝 점·공백을 버림)일 때만 막음.
    //   종전엔 strpos 로 '..' 가 어디 있든 막아 '보고서..최종.docx' 같은 정상 이름이 원격 스토리지에선 '잘못된 경로'로 열리지 않았음
    if (preg_match('#(^|[\\\\/])[. ]*\.\.[. ]*($|[\\\\/])#', $filePath)) {
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
    if (!preg_match('/\.(docx?|xlsx?|pptx?|ods)$/i', $filePath)) {
        $adapter->disconnect();
        exit(__('office_file_not_found'));
    }
    
    // ★ (2026-11-13 16:59, 113차 DEF-1) 1시간 넘은 남은 임시 폴더 정리(hwp_viewer·rhwp_viewer 와 같은 기준 — 이 접두어만, 1시간 넘은 것만) — 종전엔 읽는 중 php 작업자가 죽으면(Apache 재시작 등) 아래 종료 정리가 돌지 않아
    //   fs_office_* 폴더가 계속 남았음(재현: 원격 GET 중 작업자 kill -9). 지금 쓰는 폴더(1시간 안)는 건드리지 않고, 지우지 못한 파일(Windows 에서 열린 파일)은 @ 로 조용히 넘어감(폴더도 비지 않으면 남음)
    foreach (@glob(sys_get_temp_dir() . '/fs_office_*', GLOB_ONLYDIR) ?: [] as $_old) {
        $_oldM = @filemtime($_old);
        if ($_oldM === false || $_oldM >= time() - 3600) continue;   // 시각을 못 읽으면(다른 요청이 막 지움) 건드리지 않음
        foreach (@glob($_old . '/*') ?: [] as $_oldF) { $_oldFM = @filemtime($_oldF); if ($_oldFM !== false && $_oldFM < time() - 3600 && is_file($_oldF)) @unlink($_oldF); }
        @rmdir($_old);
    }
    // 임시 파일로 다운로드
    // ★ (2026-10-31 13:41, 34차 후속 개선) 종전엔 '세션 폴더 + 파일 이름'이라 같은 이름 문서를 동시에 열면(화면·stream 요청끼리) 서로 덮어쓰거나 지워 내용이 섞였음
    //   → 요청마다 임의 이름 폴더(파일 이름은 화면 제목에 쓰이므로 그대로 둠). 이 요청 안에서만 쓰고 종료 때 지움(아래)
    $tempDir = sys_get_temp_dir() . '/fs_office_' . bin2hex(random_bytes(12));
    if (!is_dir($tempDir)) @mkdir($tempDir, 0700, true);
    $tempFile = $tempDir . '/doc.' . strtolower(pathinfo($filePath, PATHINFO_EXTENSION));   // ★ (2026-11-04 01:46, 54차 점검) 임시 이름은 확장자만 유지(화면 이름은 아래 $filename 에서 원래 이름으로) — 종전엔 원래 이름 그대로라 255바이트를 넘는 이름(WebDAV·S3)이나 Windows 에서 : * ? " < > | 든 원격 이름은 쓰기가 실패했음(재현)
    
    // ★ (2026-11-01 09:41, 38차 점검) 읽기 전에 크기 먼저 확인(아래 50MB 제한과 같은 값) — 종전엔 수백 MB 파일도 통째로 메모리에 읽은 뒤에 검사해 안내 대신 메모리 한도 500 이 났음.
    //   크기를 못 얻으면(0) 종전처럼 읽은 뒤 아래에서 검사
    $rmSize = 0;
    try { $rmSize = (int)$adapter->getSize($filePath); } catch (\Throwable $e) { $rmSize = 0; }
    if ($rmSize > 50 * 1024 * 1024) {
        $adapter->disconnect();
        @rmdir($tempDir);
        exit(__('office_file_too_large'));
    }
    $content = $adapter->read($filePath);
    $adapter->disconnect();
    
    if ($content === '' || $content === false) {
        @rmdir($tempDir);   // ★ (2026-10-31 13:41, 34차 후속 개선) 요청마다 새 폴더라 실패 때 빈 폴더가 쌓이지 않게 지움
        exit(__('office_file_not_found'));
    }

    if (file_put_contents($tempFile, $content) === false) {   // ★ (2026-11-04 01:46, 54차 점검) 쓰기 실패 확인(hwp_viewer 38차와 같은 응답) — 종전엔 확인하지 않아 빈 파일·filesize 경고로 그대로 진행했음(재현: 257바이트 이름 → false)
        @unlink($tempFile);
        @rmdir($tempDir);
        http_response_code(503);
        exit('Temp write failed');
    }
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
    if (!file_exists($fullPath) || !preg_match('/\.(docx?|xlsx?|pptx?|ods)$/i', $fullPath)) {
        exit(__('office_file_not_found'));
    }
}

$filename = $isRemote ? basename($filePath) : basename($fullPath);   // ★ (2026-11-04 01:46, 54차 점검) 원격은 임시 이름이 'doc.확장자' 라 화면 이름·내려받기 이름은 원래 경로에서(종전엔 임시 파일 이름 = 원래 이름이었음 — 표시는 종전과 같음)
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$title = preg_replace('/\.(docx?|xlsx?|pptx?|ods)$/i', '', $filename);
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
    'xls' => ['name' => 'Excel 97-2003', 'color' => '#217346', 'icon' => 'X'],   // ★ (2026-10-30 11:30) 이제 미리보기 됨
    'xlsx' => ['name' => 'Excel', 'color' => '#217346', 'icon' => 'X'],
    'ods' => ['name' => 'OpenDocument', 'color' => '#217346', 'icon' => 'X'],
    'ppt' => ['name' => __('office_ppt_legacy'), 'color' => '#d24726', 'icon' => 'P'],
    'pptx' => ['name' => 'PowerPoint', 'color' => '#d24726', 'icon' => 'P'],
];
$fileInfo = $fileTypes[$ext] ?? ['name' => 'Office', 'color' => '#666', 'icon' => 'O'];
$isSheet = in_array($ext, ['xlsx', 'xls', 'ods']);   // ★ (2026-10-30 11:30) 표 계산 문서(SheetJS 로 여는 형식)
if (!isset($isLegacy)) $isLegacy = in_array($ext, ['doc', 'ppt']);   // ★ (2026-10-30 11:30) xls 는 SheetJS 로 열림
if (!isset($fileName)) $fileName = basename($filePath);
if (!isset($filename)) $filename = $fileName;
$title = pathinfo($fileName, PATHINFO_FILENAME);

// 파일 스트리밍 API
if (isset($_GET['action']) && $_GET['action'] === 'stream') {
    // 폴더별 권한 체크
    $fileDir = dirname($filePath);
    if ($fileDir === '.') $fileDir = '';
    if ($storageId && !$storage->checkFolderPermission($storageId, ($fileDir !== '' ? $fileDir : $filePath))) { // ★ (2026-11-01 17:17, 40차 점검) 부모 폴더가 '0' 이면 ?: 가 빈 값으로 봐 항목 자신 규칙으로 검사했음 → '' 일 때만 항목 자신
        http_response_code(403);
        exit('No permission');
    }
    
    // MIME 타입
    $mimeTypes = [
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $filesize);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-cache');   // ★ (2026-11-01 10:18, 38차 점검) hwp_viewer 와 같게 — 편집기·OnlyOffice 로 고쳐 저장하거나 다시 올린 뒤에도 최대 24시간 옛 문서가 보였음.   // ★ (2026-10-29 11:17, 20차 점검) public → private — 로그인한 사람의 파일이라 중간 공유 캐시(리버스 프록시 등)에 남지 않게(브라우저 캐시는 그대로)
    
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

// ★ (2026-10-26) 화면 HTML 의 이모지 → 선 그림 아이콘(index.php 와 같은 방식). 여기(화면 출력 직전)부터만 — 위쪽 파일 전송·오류 응답은 이미 exit
ob_start('fs_icon_convert_html');
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
        
        /* ★ (2026-10-30 11:30) 엑셀처럼 — 원본 열 너비(최소 너비로 — 글자가 길면 열이 넓어짐, 2026-10-30 13:37 28차 재검토: 너비 고정은 숫자·날짜가 '…' 로 잘려 오히려 읽기 어려워 쓰지 않음), 행·열 머리글 고정, 숫자 오른쪽 정렬, 큰 시트 나눠 그리기 */
        .sheet-scroll { overflow: auto; max-height: calc(100vh - 150px); border: 1px solid var(--border-color); background: var(--card-bg); }
        .xlsx-table {
            border-collapse: separate;
            border-spacing: 0;
            background: var(--card-bg);
            font-size: 13px;
        }
        
        .xlsx-table th, .xlsx-table td {
            border-right: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
            padding: 4px 6px;
            text-align: left;
            white-space: nowrap;
        }
        .xlsx-table td.num { text-align: right; }
        
        .xlsx-table thead th {
            position: sticky; top: 0; z-index: 2;
            background: var(--header-bg);
            font-weight: 600; text-align: center; color: var(--text-color);
        }
        .xlsx-table th.xlsx-rh {
            position: sticky; left: 0; z-index: 1;
            background: var(--header-bg);
            font-weight: 500; text-align: center; color: var(--text-color);
        }
        .xlsx-table thead th.xlsx-corner { left: 0; z-index: 3; }
        .sheet-tab-hidden { opacity: 0.7; font-size: 12px; }
        .sheet-empty, .sheet-more { padding: 16px; text-align: center; color: var(--text-color); }
        .sheet-more-btn { padding: 8px 18px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--btn-bg); color: var(--text-color); cursor: pointer; font-size: 13px; }
        .sheet-more-btn:hover { background: var(--btn-hover); }
        
        /* PPTX 스타일 */
        .pptx-container {
            max-width: 1280px;
            margin: 20px auto;
            transform-origin: top center;
        }
        /* ★ (2026-10-30 11:30) pptx-preview 목록 보기 — 슬라이드 사이 간격·그림자 */
        .pptx-container .pptx-preview-wrapper { background: transparent !important; margin: 0 auto; height: auto !important; overflow: visible !important; }   /* 라이브러리가 슬라이드 한 장 높이의 안쪽 스크롤 상자를 만들어 스크롤이 겹쳤음 */
        .pptx-container .pptx-preview-slide-wrapper { margin: 0 auto 20px !important; box-shadow: 0 4px 16px rgba(0,0,0,0.15); }
        
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
            /* ★ (2026-11-12 21:53, 105차 PRT-1) 엑셀 인쇄 때만 표 상자의 높이 상한·안쪽 스크롤을 풀고 시트 탭을 숨김(지금 보는 시트만 인쇄) — 종전엔 400행 시트가 1쪽·1~34행만 나오고 '시트1 시트2' 탭이 찍혔음(재현) */
            .sheet-scroll { max-height: none; overflow: visible; }
            .sheet-tabs { display: none; }
            /* ★ (2026-11-12 21:53, 105차 PRT-2) 파워포인트 인쇄 때만 슬라이드가 쪽 경계에서 갈리지 않게 — 종전엔 5장이 3쪽으로 나오며 슬라이드 2·4 제목과 본문이 다른 쪽에 찍혔음(재현) */
            .pptx-container .pptx-preview-slide-wrapper { break-inside: avoid; page-break-inside: avoid; }
            /* ★ (2026-11-12 21:53, 105차 PRT-3) 어두운 모드에서 인쇄할 때만 색 값을 밝은 모드 값으로 — 종전엔 엑셀 글자가 rgb(224,224,224) 옅은 회색, 배경 그래픽을 켜면 검은 바탕으로 인쇄됐음(재현) */
            [data-theme="dark"] { --bg-color: #ffffff; --text-color: #333333; --header-bg: #f8f9fa; --border-color: #dee2e6; --btn-bg: #e9ecef; --btn-hover: #dee2e6; --viewer-bg: #f5f5f5; --card-bg: #ffffff; }
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
            <button class="back-btn" onclick="window.close()" title="<?php _e('viewer_close'); ?>">✕</button>
            <span class="filename fs-noico"><?php echo htmlspecialchars($filename); ?></span>
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
    <script src="assets/vendor/jszip.min.js?v=3.10.2"></script>
    
    <?php if ($ext === 'docx'): ?>
    <!-- DOCX: docx-preview 0.4.1 (★ 2026-10-30 11:30 올림) -->
    <script src="assets/vendor/docx-preview.min.js?v=0.4.1"></script>
    <?php elseif ($isSheet): ?>
    <!-- XLSX·XLS·ODS: SheetJS 0.20.3 (★ 2026-10-30 11:30 0.18.5 에서 올림 — 조작한 파일 읽기 취약점 CVE-2023-30533·CVE-2024-22363 고친 판) -->
    <script src="assets/vendor/xlsx.full.min.js?v=0.20.3"></script>
    <?php elseif ($ext === 'pptx'): ?>
    <!-- PPTX: pptx-preview 1.0.7 (★ 2026-10-30 11:30 — 슬라이드 모양·그림·도형·표·차트를 그림. npm 배포본, 자유 사용 허용·소스 비공개) -->
    <script src="assets/vendor/pptx-preview.umd.js?v=1.0.7"></script>
    <?php endif; ?>
    
    <script>
        const fileUrl = <?php echo json_encode($fileUrl, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const fileExt = <?php echo json_encode($ext, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const OFC_I18N = {
            fileLoadFailed: <?php echo json_encode(__('office_file_load_failed')); ?>,
            slideNotFound: <?php echo json_encode(__('office_slide_not_found')); ?>,
            noText: <?php echo json_encode(__('office_no_text')); ?>,
            download: <?php echo json_encode(__('office_download_btn')); ?>,
            close: <?php echo json_encode(__('office_close')); ?>,
            emptySheet: <?php echo json_encode($currentLang === 'en' ? 'This sheet is empty.' : '빈 시트입니다.'); ?>,
            moreRows: <?php echo json_encode($currentLang === 'en' ? 'Show {n} more rows ({shown} / {total})' : '다음 {n}행 더 보기 ({shown} / {total}행)'); ?>,
            hiddenSheet: <?php echo json_encode($currentLang === 'en' ? 'hidden' : '숨김'); ?>,
            colsCut: <?php echo json_encode($currentLang === 'en' ? 'Only the first {n} columns are shown.' : '앞쪽 {n}열까지만 보여 줍니다.'); ?>
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
                    renderAltChunks: false,   // ★ (2026-10-30 13:32, 28차 재검토) 문서 안에 넣은 HTML 조각(altChunk)을 그리지 않음 — 막지 않은 iframe(srcdoc)으로 그려져 그 안의 스크립트가 이 사이트 권한으로 실행됐음(재현: 종전 판부터, 이 페이지엔 CSP 없음)
                    debug: false
                });
                
                // ★ (2026-10-30 13:32, 28차 재검토) 문서 속 링크 중 http·https·mailto·문서 안 이동(#)이 아닌 주소(javascript: 등)는 주소를 뺌 — 누르면 이 사이트 권한으로 실행됐음(재현)
                viewerEl.querySelectorAll('a[href]').forEach(a => {
                    if (!/^\s*(https?:|mailto:|#)/i.test(a.getAttribute('href') || '')) a.removeAttribute('href');
                    else if (/^\s*https?:/i.test(a.getAttribute('href'))) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
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
        
        <?php if ($isSheet): ?>
        // ★ (2026-10-30 11:30) 표를 직접 그림 — 종전 sheet_to_html 은 열 너비·행/열 머리글이 없고 5천 행 시트도 한 번에 다 그렸음.
        //   열 너비(!cols)·숨긴 행/열·병합(!merges)·숫자 오른쪽 정렬·머리글 고정, 큰 시트는 2천 행씩 나눠 그림. 글자는 모두 escape
        let workbook = null;
        let sheetNames = [];
        let currentSheetIdx = 0;
        const SHEET_ROW_STEP = 2000;
        const SHEET_MAX_COLS = 256;
        let sheetRowLimit = SHEET_ROW_STEP;
        
        function escHtml(v) {
            return String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        }
        
        async function loadDocument() {
            try {
                const response = await fetch(fileUrl, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error(OFC_I18N.fileLoadFailed + ' (HTTP ' + response.status + ')');
                }
                
                const arrayBuffer = await response.arrayBuffer();
                // cellStyles: 열 너비·행 높이·숨김(!cols·!rows)을 읽음(없으면 xlsx 는 너비를 안 줌), sheetStubs: 값 없이 수식만 있는 칸도 받음
                workbook = XLSX.read(arrayBuffer, { type: 'array', cellStyles: true, sheetStubs: true });
                sheetNames = workbook.SheetNames;
                const wbSheets = (workbook.Workbook && workbook.Workbook.Sheets) || [];
                
                viewerEl.className = 'xlsx-container';
                let tabsHtml = '';
                if (sheetNames.length > 1) {
                    tabsHtml = '<div class="sheet-tabs">';
                    sheetNames.forEach((name, idx) => {
                        const hidden = wbSheets[idx] && wbSheets[idx].Hidden ? ' <span class="sheet-tab-hidden">(' + escHtml(OFC_I18N.hiddenSheet) + ')</span>' : '';
                        // ODS 는 SheetJS 가 시트 이름의 &lt; 같은 표기를 풀지 않고 넘겨 '&amp;lt;' 처럼 보였음 → 풀고 나서 escape
                        const label = fileExt === 'ods' ? String(name).replace(/&(lt|gt|amp|quot|apos|#\d+|#x[0-9a-f]+);/gi, (m, e) => ({ lt: '<', gt: '>', amp: '&', quot: '"', apos: "'" })[e.toLowerCase()] || (e[0] === '#' ? (() => { const cp = e[1].toLowerCase() === 'x' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10); return cp >= 0 && cp <= 0x10FFFF ? String.fromCodePoint(cp) : m; })() : m)) : name;   // ★ (2026-10-30 13:21, 28차 재검토) 범위 밖 숫자 표기는 그대로 — 종전엔 오류로 파일 전체가 안 열렸음(재현)
                        tabsHtml += `<button class="sheet-tab ${idx === 0 ? 'active' : ''}" onclick="showSheet(${idx})">${escHtml(label)}${hidden}</button>`;
                    });
                    tabsHtml += '</div>';
                }
                viewerEl.innerHTML = tabsHtml + '<div id="sheetContent" class="sheet-scroll"></div>';
                
                // 처음엔 숨기지 않은 첫 시트
                let first = 0;
                for (let i = 0; i < sheetNames.length; i++) { if (!(wbSheets[i] && wbSheets[i].Hidden)) { first = i; break; } }
                showSheet(first);
                
                loadingOverlay.style.display = 'none';
            } catch (error) {
                showError(error.message || OFC_I18N.fileLoadFailed);
            }
        }
        
        function showSheet(idx) {
            currentSheetIdx = idx;
            sheetRowLimit = SHEET_ROW_STEP;
            renderSheet();
            const sc = document.getElementById('sheetContent');
            if (sc) { sc.scrollTop = 0; sc.scrollLeft = 0; }
            document.querySelectorAll('.sheet-tab').forEach((tab, i) => {
                tab.classList.toggle('active', i === idx);
            });
        }
        
        function moreSheetRows() {
            sheetRowLimit += SHEET_ROW_STEP;
            renderSheet();
        }
        
        function renderSheet() {
            const host = document.getElementById('sheetContent');
            const ws = workbook.Sheets[sheetNames[currentSheetIdx]];
            if (!ws || !ws['!ref']) { host.innerHTML = '<div class="sheet-empty">' + escHtml(OFC_I18N.emptySheet) + '</div>'; return; }
            const range = XLSX.utils.decode_range(ws['!ref']);
            const r0 = range.s.r, c0 = range.s.c;
            const rEnd = Math.min(range.e.r, r0 + sheetRowLimit - 1);
            const cEnd = Math.min(range.e.c, c0 + SHEET_MAX_COLS - 1);
            const colInfo = ws['!cols'] || [], rowInfo = ws['!rows'] || [];
            const cols = [];
            for (let c = c0; c <= cEnd; c++) {
                const ci = colInfo[c] || {};
                if (ci.hidden) continue;
                let w = ci.wpx || (ci.wch ? Math.round(ci.wch * 7 + 10) : (ci.width ? Math.round(ci.width * 7 + 5) : 80));
                cols.push({ c, w: Math.max(24, Math.min(w, 800)) });
            }
            // 병합: 보이는 첫 칸에서 시작해 보이는 행·열 수만큼 — ★ (2026-10-30 13:33, 28차 재검토) 시작 칸이 숨긴 행·열에 있으면 시작 칸은 안 그리고 덮인 칸만 건너뛰어
            //   뒤 칸이 왼쪽·위로 밀렸음(재현: A1:B2 병합 + 1행 숨김 → 2행에 C2 가 A 열 자리). 값은 원래 시작 칸(왼쪽 위) 것
            const rowHidden = r => !!(rowInfo[r] && rowInfo[r].hidden), colHidden = c => !!(colInfo[c] && colInfo[c].hidden);
            const mStart = {}, covered = {};
            (ws['!merges'] || []).forEach(m => {
                if (m.s.r > rEnd || m.s.c > cEnd) return;
                const er = Math.min(m.e.r, rEnd), ec = Math.min(m.e.c, cEnd);
                let vr = -1, vc = -1, rs = 0, cs = 0;
                for (let r = m.s.r; r <= er; r++) if (!rowHidden(r)) { if (vr < 0) vr = r; rs++; }
                for (let c = m.s.c; c <= ec; c++) if (!colHidden(c)) { if (vc < 0) vc = c; cs++; }
                for (let r = m.s.r; r <= er; r++) for (let c = m.s.c; c <= ec; c++) covered[r + ',' + c] = 1;
                if (vr >= 0 && vc >= 0) { delete covered[vr + ',' + vc]; mStart[vr + ',' + vc] = { rs, cs, src: { r: m.s.r, c: m.s.c } }; }
            });
            const out = [];
            out.push('<table class="xlsx-table"><colgroup><col style="width:46px">');
            cols.forEach(k => out.push('<col style="width:' + k.w + 'px">'));
            out.push('</colgroup><thead><tr><th class="xlsx-corner"></th>');
            cols.forEach(k => out.push('<th>' + XLSX.utils.encode_col(k.c) + '</th>'));
            out.push('</tr></thead><tbody>');
            for (let r = r0; r <= rEnd; r++) {
                const ri = rowInfo[r] || {};
                if (ri.hidden) continue;
                const h = ri.hpx ? ' style="height:' + Math.round(Math.min(ri.hpx, 400)) + 'px"' : '';
                out.push('<tr' + h + '><th class="xlsx-rh">' + (r + 1) + '</th>');
                for (const k of cols) {
                    const key = r + ',' + k.c;
                    if (covered[key]) continue;
                    const m = mStart[key];
                    const cell = ws[XLSX.utils.encode_cell(m ? m.src : { r, c: k.c })];
                    let txt = '';
                    if (cell) txt = cell.t === 'z' ? (cell.f ? '=' + cell.f : '') : (cell.w != null ? cell.w : (cell.v == null ? '' : String(cell.v)));   // 계산 결과가 저장되지 않은 수식(빈 칸 표시 't:z')은 수식 그대로
                    const cls = cell && (cell.t === 'n' || cell.t === 'd') ? ' class="num"' : '';
                    // 칸 채우기 색(SheetJS 공개판이 읽어 주는 단색 채우기만 — 글꼴·테두리는 공개판이 안 읽음). 어두운 색이면 글자를 흰색으로
                    let bg = '';
                    const fs = cell && cell.s;
                    if (fs && fs.patternType === 'solid' && fs.fgColor && /^[0-9a-f]{6}$/i.test(fs.fgColor.rgb || '')) {
                        const hx = fs.fgColor.rgb, L = (parseInt(hx.slice(0, 2), 16) * 299 + parseInt(hx.slice(2, 4), 16) * 587 + parseInt(hx.slice(4, 6), 16) * 114) / 1000;
                        bg = ' style="background:#' + hx + (L < 140 ? ';color:#fff' : ';color:#111') + '"';
                    }
                    let span = '';
                    if (m) {
                        if (m.cs > 1) span += ' colspan="' + m.cs + '"';
                        if (m.rs > 1) span += ' rowspan="' + m.rs + '"';   // 보이는 행만 셈(2026-10-30 13:21 — 병합 안의 숨긴 행만큼 아래로 넘치던 것)
                    }
                    out.push('<td' + cls + span + bg + '>' + escHtml(txt) + '</td>');
                }
                out.push('</tr>');
            }
            out.push('</tbody></table>');
            const total = range.e.r - r0 + 1, shown = rEnd - r0 + 1;
            if (shown < total) {
                const n = Math.min(SHEET_ROW_STEP, total - shown);
                out.push('<div class="sheet-more"><button type="button" class="sheet-more-btn" onclick="moreSheetRows()">' + escHtml(OFC_I18N.moreRows.replace('{n}', n.toLocaleString()).replace('{shown}', shown.toLocaleString()).replace('{total}', total.toLocaleString())) + '</button></div>');
            }
            if (cEnd < range.e.c) out.push('<div class="sheet-more">' + escHtml(OFC_I18N.colsCut.replace('{n}', SHEET_MAX_COLS)) + '</div>');
            host.innerHTML = out.join('');
        }
        <?php endif; ?>
        
        // ============================================================
        // PPTX 렌더링
        // ============================================================
        
        <?php if ($ext === 'pptx'): ?>
        // ★ (2026-10-30 11:30) pptx-preview 로 실제 슬라이드 모양(배경·도형·그림·표·차트·글꼴 크기)을 그림 — 종전엔 글자만 뽑아 첫 줄을 제목처럼 보여 줬음.
        //   보안 확인(시험): 제목·글머리표·도형·표 칸·묶음·메모·하이퍼링크(javascript:)에 넣은 '<img onerror>'·'<svg onload>' 가 모두 글자로만 보이고
        //   실행 0·이벤트 속성 0 — 라이브러리가 글자를 escape 함(소스 비공개라 판을 바꾸면 다시 시험할 것)
        //   차트 제목이 없을 때 라이브러리가 넣던 중국어 '图表标题'(차트 제목)는 vendor 파일에서 빈 글자로 바꿈
        let previewer = null;
        
        async function readSlideSize(arrayBuffer) {
            try {
                const zip = await JSZip.loadAsync(arrayBuffer);
                const pres = zip.file('ppt/presentation.xml');
                if (!pres) return null;
                const m = (await pres.async('string')).match(/<p:sldSz[^>]*\bcx="(\d+)"[^>]*\bcy="(\d+)"/);
                return m ? { cx: +m[1], cy: +m[2] } : null;
            } catch (e) { return null; }
        }
        
        function slideElements() {
            const c = document.getElementById('pptx-container');
            if (!c) return [];
            const w = c.querySelector('.pptx-preview-wrapper') || c.firstElementChild;
            return w ? Array.from(w.children).filter(el => el.offsetHeight > 20) : [];
        }
        
        async function loadDocument() {
            try {
                const response = await fetch(fileUrl, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error(OFC_I18N.fileLoadFailed + ' (HTTP ' + response.status + ')');
                }
                const arrayBuffer = await response.arrayBuffer();
                const size = await readSlideSize(arrayBuffer);
                
                viewerEl.innerHTML = '<div class="pptx-container" id="pptx-container"></div>';
                const container = document.getElementById('pptx-container');
                const avail = Math.max(320, (viewerContainer.clientWidth || window.innerWidth) - 40);
                const width = Math.min(1280, avail);
                const ratio = size && size.cx ? size.cy / size.cx : 9 / 16;
                previewer = pptxPreview.init(container, { width: width, height: Math.round(width * ratio), mode: 'list' });
                await previewer.preview(arrayBuffer);
                
                totalSlides = previewer.slideCount || slideElements().length;
                if (!totalSlides) throw new Error(OFC_I18N.slideNotFound);
                currentSlide = 0;
                document.getElementById('slideNav').style.display = 'flex';
                updateSlideInfo();
                viewerContainer.addEventListener('scroll', onSlideScroll, { passive: true });
                
                loadingOverlay.style.display = 'none';
            } catch (error) {
                showError(error.message || OFC_I18N.fileLoadFailed);
            }
        }
        
        function updateSlideInfo() {
            document.getElementById('slideInfo').textContent = `${currentSlide + 1} / ${totalSlides}`;
            updateNavButtons();
        }
        
        let _slideScrollT = null;
        function onSlideScroll() {
            clearTimeout(_slideScrollT);
            _slideScrollT = setTimeout(() => {
                const els = slideElements();
                const top = viewerContainer.getBoundingClientRect().top;
                let best = 0;
                els.forEach((el, i) => { if (el.getBoundingClientRect().top - top < viewerContainer.clientHeight / 3) best = i; });
                if (best !== currentSlide && best < totalSlides) { currentSlide = best; updateSlideInfo(); }
            }, 80);
        }
        
        function goSlide(idx) {
            const els = slideElements();
            if (idx < 0 || idx >= totalSlides || !els[idx]) return;
            currentSlide = idx;
            els[idx].scrollIntoView({ block: 'start' });
            updateSlideInfo();
        }
        
        function prevSlide() { goSlide(currentSlide - 1); }
        function nextSlide() { goSlide(currentSlide + 1); }
        
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
            // ★ (2026-11-03 13:48, 51차 점검) 암호 걸린 문서·zip 이 아닌 파일은 라이브러리의 영어 문구('is this a zip file?'·'Unsupported ZIP encryption'·'File is password-protected')가
            //   그대로 보여 사용자가 무슨 뜻인지 알 수 없었음(재현) → 그런 경우만 번역된 안내로
            // ★ (2026-11-16 14:58, 243차 점검) 0바이트 docx 는 'End of data reached … Corrupted zip ?' 영어 문구가 그대로 보였음(재현) → 같은 안내로
            if (/central directory|is this a zip|corrupted zip|end of data reached|password|encrypt/i.test(String(message))) message = <?php echo json_encode(__('office_open_locked_or_broken', '문서를 열 수 없습니다. 암호가 걸렸거나 손상된 파일일 수 있습니다.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
            viewerContainer.innerHTML = `
                <div class="error-container">
                    <div class="error-icon">📄</div>
                    <div class="error-message">${escapeHtml(String(message))}</div><!-- ★ (2026-10-29 21:20, 24차 점검) 오류 문구 escape(hwp_viewer 와 같게) — 라이브러리 오류에 파일 안 경로 등 문서 내용이 들어갈 수 있음 -->
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
            <?php elseif ($isSheet): ?>
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
                //   연장 타이머도 멈춰 돌아와서 받기(레거시 doc/ppt 다운로드)가 404 였음 → 실제로 페이지를 떠날 때(pagehide)만 정리
                // ★ (2026-10-31 14:09, 34차 후속 개선) 뒤로 가기 캐시(e.persisted)면 지우지 않고 타이머만 멈췄다가 돌아오면(pageshow) 연장하고 다시 켬
                window.addEventListener('pageshow', function(e) {
                    if (!e.persisted) return;
                    clearInterval(keepAlive); _touch(); keepAlive = setInterval(_touch, 120000);
                });
                window.addEventListener('pagehide', function(e) {
                    clearInterval(keepAlive);
                    if (e.persisted) return;
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
