<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * rhwp WASM 기반 HWP/HWPX 뷰어
 * 
 * Rust + WebAssembly (rhwp) 기반 고품질 HWP/HWPX 렌더링
 * https://github.com/edwardkim/rhwp
 * @rhwp_version 0.9.0
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/api/FsIcons.php';   // ★ (2026-10-26) 선 그림 아이콘 — 아래 화면의 이모지를 바꿈

$currentLang = getLang();
// ★ (2026-09-02) HTML 캐시 방지 — index.php 와 동일 취지.
//   이 페이지도 `?v=APP_VERSION` 으로 자산을 캐시버스팅하지만, **HTML 이 캐시되면
//   옛 버전 문자열을 참조한 채 남아** 새 자산을 요청하지 않는다.
//   HTML 만 매번 새로 받게 하고 자산 캐시는 그대로 둔다(트래픽 영향 없음).
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$rhwpAssetKey = substr((string)@md5_file(__DIR__ . '/assets/rhwp/rhwp.js'), 0, 10) ?: APP_VERSION;   // ★ (2026-11-09 09:37) 아래 rhwp.js·wasm 캐시 키(파일을 못 읽으면 종전 값)
// ★ (2026-11-15 03:18, 159차 펜닐 요청 '같은 엔진 파일 2개 중 1개 빼기') 엔진(wasm)은 편집기(studio)와 같은 파일을 씀 — 종전엔 뷰어용 assets/rhwp/rhwp_bg.wasm 과
//   편집기 빌드가 만든 assets/rhwp/studio/rhwp_bg-<해시>.wasm 이 바이트까지 같은 파일(26.8MB, npm 원본과 md5 같음)로 두 벌 배포돼 묶음이 약 9.9MB 컸음.
//   studio 폴더의 엔진을 쓰되, 업그레이드 잔재로 여러 개면 편집기 index js 가 부르는 것(cleanup_rhwp_studio.php 와 같은 판정), 하나도 없으면 종전 위치
$rhwpWasmRel = 'assets/rhwp/rhwp_bg.wasm';
$_rvStudioDir = __DIR__ . '/assets/rhwp/studio';
$_rvWasm = glob($_rvStudioDir . '/rhwp_bg-*.wasm') ?: [];
if (count($_rvWasm) > 1) {
    $_rvPick = null;
    $_rvEd = (string)@file_get_contents(__DIR__ . '/rhwp_editor.php');
    if (preg_match('/index-[A-Za-z0-9_-]+\.js/', $_rvEd, $_rvM) && ($_rvJs = @file_get_contents($_rvStudioDir . '/' . $_rvM[0])) !== false
        && preg_match('/rhwp_bg-[A-Za-z0-9_-]+\.wasm/', $_rvJs, $_rvW) && is_file($_rvStudioDir . '/' . $_rvW[0])) $_rvPick = $_rvStudioDir . '/' . $_rvW[0];
    if ($_rvPick === null) { usort($_rvWasm, function ($x, $y) { return (int)@filemtime($y) - (int)@filemtime($x); }); $_rvPick = $_rvWasm[0]; }
    $_rvWasm = [$_rvPick];
    unset($_rvEd, $_rvJs, $_rvM, $_rvW, $_rvPick);
}
if ($_rvWasm) $rhwpWasmRel = 'assets/rhwp/studio/' . basename($_rvWasm[0]);
unset($_rvStudioDir, $_rvWasm);
$storage = new Storage();
$auth = new Auth();

// ★ (2026-11-07 11:20, 로그인 유지 풀림 재검토) 세션이 없으면 로그인 유지 토큰으로 복구 시도(index.php·api.php 와 같게) — 종전엔 이 화면이 첫 요청이면(iPhone 이 탭을 정리한 뒤 뷰어 탭을 다시 읽을 때 등) 로그인 유지를 켜도 로그인 화면/401 로 갔음(재현)
if (!$auth->isLoggedIn() && !$auth->checkRememberToken()) {
    header('Location: index.php');
    exit;
}

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

// ============================================================
// 파일 스트리밍 API
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
    
    // 세션 락 해제: 대용량 파일 스트리밍 중 같은 사용자의 다른 요청이 
    // 세션 대기로 무한 로딩되는 현상 방지
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    $storageInfo = $storage->getStorageById($storageId);
    if (!$storageInfo) {
        http_response_code(403);
        exit('Invalid storage');
    }
    
    $storageType = $storageInfo['storage_type'] ?? 'local';
    $remoteTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
    $isRemote = in_array($storageType, $remoteTypes);
    
    if ($isRemote) {
        // ★ (2026-11-01 09:41, 38차 점검) 경로 조각이 '..'(또는 점·공백만으로 된 '.. ' 같은 조각 — Windows 쪽 서버는 끝 점·공백을 버림)일 때만 막음.
        //   종전엔 strpos 로 '..' 가 어디 있든 막아 '보고서..hwp'·'v1..2/a.hwp' 같은 정상 이름이 403 으로 열리지 않았음
        if (preg_match('#(^|[\\\\/])[. ]*\.\.[. ]*($|[\\\\/])#', $filePath)) {
            http_response_code(403);
            exit('Invalid path');
        }
        require_once __DIR__ . '/api/StorageAdapter.php';
        $adapter = StorageAdapterFactory::create($storageInfo);
        if (!$adapter || !$adapter->connect()) {
            http_response_code(503);
            exit('Storage connection failed');
        }
        
        // ★ office_viewer.php 패턴 통일: read() + file_put_contents() (download() 메서드 없음)
        // ★ (2026-10-30 01:55, 26차 점검) 남은 임시 파일 정리(편집기와 같은 안전망 — 내려받는 중 창을 닫으면 아래 unlink 전에 끝남) + 이름 직접 만들기(Windows tempnam 은 접두어 3글자만 씀)
        foreach (@glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rhwpv_*') ?: [] as $_old) {
            if (is_file($_old) && filemtime($_old) < time() - 3600) @unlink($_old);
        }
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rhwpv_' . bin2hex(random_bytes(8)) . '.tmp';
        $content = $adapter->read($filePath);
        $adapter->disconnect();
        
        if ($content === '' || $content === false) {
            @unlink($tempFile);
            http_response_code(404);
            exit('File not found');
        }
        
        if (file_put_contents($tempFile, $content) === false) {   // ★ (2026-11-01 09:41, 38차 점검) 임시 파일 쓰기 실패(임시 폴더 권한·디스크 가득) 확인 — 종전엔 'Content-Length: ' 가 비고 readfile 경고만 남아 화면엔 '파싱 실패'만 보였음
            @unlink($tempFile);
            http_response_code(503);
            exit('Temp write failed');
        }
        $realFile = $tempFile;
        $cleanup = true;
    } else {
        $basePath = $storage->getRealPath($storageId);
        if (!$basePath) {
            http_response_code(403);
            exit('Invalid storage');
        }
        
        $realFile = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        $realBase = realpath($basePath);
        $realTarget = realpath($realFile);
        
        if (!$realBase || !$realTarget || !\isSubPath($realTarget, $realBase)) {
            http_response_code(403);
            exit('Invalid path');
        }
        
        if (!file_exists($realTarget)) {
            http_response_code(404);
            exit('File not found');
        }
        
        $realFile = $realTarget;
        $cleanup = false;
    }
    
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mime = ($ext === 'hwpx') ? 'application/hwp+zip' : 'application/x-hwp';
    
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($realFile));
    header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
    header('Cache-Control: no-cache');
    
    readfile($realFile);
    
    if ($cleanup) @unlink($realFile);
    exit;
}

// ============================================================
// 뷰어 HTML
// ============================================================
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
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $storageInfo = null;
    $storageType = '';
    $isRemote = false;
    $canDownload = true;
} else {
    $fileName = basename($filePath);
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    // 스토리지 정보
    $storageInfo = $storage->getStorageById($storageId);
    $storageType = $storageInfo['storage_type'] ?? 'local';
    $remoteTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
    $isRemote = in_array($storageType, $remoteTypes);
    
    // 다운로드 권한
    $canDownload = $storage->checkPermission($storageId, 'can_download');
}
// ★ (2026-10-26) 화면 HTML 의 이모지 → 선 그림 아이콘(index.php 와 같은 방식). 여기(화면 출력 직전)부터만 — 위쪽 파일 전송·오류 응답은 이미 exit
ob_start('fs_icon_convert_html');
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($fileName); ?> - HWP Viewer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        
        /* 헤더 */
        .viewer-header { display: flex; align-items: center; justify-content: space-between; padding: 8px 16px; background: #fff; border-bottom: 1px solid #ddd; min-height: 48px; flex-shrink: 0; }
        .viewer-header .title { font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 40%; cursor: pointer; user-select: none; }
        .viewer-header .title.scrolling { 
            overflow-x: auto !important; 
            overflow: auto !important;
            text-overflow: clip !important; 
            -webkit-overflow-scrolling: touch; 
            scrollbar-width: none;
        }
        .viewer-header .title.scrolling::-webkit-scrollbar { display: none; }
        .viewer-header .controls { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .viewer-header button { padding: 6px 12px; border: 1px solid #ccc; border-radius: 4px; background: #fff; cursor: pointer; font-size: 13px; flex-shrink: 0; }
        .viewer-header button:hover { background: #f0f0f0; }
        .viewer-header button:disabled { opacity: 0.4; cursor: default; }
        .viewer-header .page-info { font-size: 13px; color: #666; min-width: 60px; text-align: center; }
        .viewer-header select { padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        /* 뷰어 전환 버튼 (a 태그) — button과 동일한 스타일 + 가로 정렬 보장 */
        .viewer-header .switch-btn { 
            padding: 6px 12px; border: 1px solid #ccc; border-radius: 4px; background: #fff; 
            cursor: pointer; font-size: 13px; text-decoration: none; color: #333; 
            display: inline-flex; align-items: center; white-space: nowrap; flex-shrink: 0;
        }
        .viewer-header .switch-btn:hover { background: #f0f0f0; }
        
        /* 뷰어 본체 */
        .viewer-body { flex: 1; overflow: auto; padding: 20px; display: flex; flex-direction: column; align-items: center; gap: 16px; background: #e8e8e8; -webkit-overflow-scrolling: touch; }
        .page-container { background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.15); max-width: 100%; }
        .page-container svg { display: block; height: auto; }
        /* ★ (2026-11-10 07:24, 61차 E) PC '화면 맞춤' — 쪽 상자가 내용 폭을 따르는데 svg 는 width 속성을 지우고 100% 만 받아 서로 기대다 기본폭 300px 로 떨어졌음 → 맞춤일 때만 쪽 상자를 본문 폭으로(화면에서만 — 인쇄 규칙은 종전 그대로) */
        @media screen { .page-container.fit-width { width: 100%; } }
        
        /* 로딩 */
        .loading-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.9); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 1000; }
        .loading-overlay.hidden { display: none; }
        .spinner { width: 40px; height: 40px; border: 4px solid #ddd; border-top-color: #4a90d9; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-text { margin-top: 12px; font-size: 14px; color: #666; }
        
        /* 에러 */
        .error-msg { padding: 40px; text-align: center; color: #d32f2f; font-size: 15px; }
        
        /* 모바일 */
        @media (max-width: 768px) {
            .viewer-header { padding: 4px 8px; min-height: 38px; }
            .viewer-header .title { font-size: 12px; max-width: 60%; }
            .viewer-header button { padding: 3px 6px; font-size: 11px; }
            .viewer-header .switch-btn { padding: 3px 6px; font-size: 11px; white-space: nowrap; }
            .viewer-header select { font-size: 11px; padding: 2px 3px; }
            .viewer-header .page-info { font-size: 11px; min-width: 50px; }
            /* 모바일에서는 페이지 이동/정보, rhwp 버전, 줌 선택 숨김 
               (손가락 스크롤/핀치 줌으로 대체 — 공간 절약) */
            .viewer-header #btn-prev,
            .viewer-header #btn-next,
            .viewer-header .page-info,
            .viewer-header #rhwp-version,
            .viewer-header #zoom-select { display: none !important; }
            /* 본문: 패딩/갭 제거, 배경을 흰색으로 → 문서가 화면 전체 */
            .viewer-body { padding: 0; gap: 2px; background: #fff; }
            .page-container { box-shadow: none; border-bottom: 1px solid #e0e0e0; width: 100%; }
            .page-container:last-child { border-bottom: none; }
            /* SVG를 화면 너비에 꽉 맞춤 */
            .page-container svg { width: 100% !important; height: auto !important; }
        }
        /* ★ 인쇄용 스타일 (펜닐 v5.8.1e) — 사용자가 인쇄/PDF 저장 시 본문만 출력 */
        @media print {
            .viewer-header { display: none !important; }
            .viewer-body { padding: 0; gap: 0; background: #fff; overflow: visible; height: auto; }
            html, body { background: #fff; overflow: visible; height: auto; }
            .page-container { box-shadow: none; page-break-after: always; break-after: page; margin: 0; padding: 0; }
            .page-container:last-child { page-break-after: auto; break-after: auto; }
            .page-container svg { display: block; width: 100% !important; height: auto !important; max-width: 100%; }
            /* ★ (2026-11-10 13:19, 63차 E) '화면 맞춤'으로 svg width 속성이 지워진 뒤엔 위 100% 가 쪽 상자와 서로 기대 300px 로 인쇄됐음 → 지우기 전 폭(--rv-pw)이 있으면 그 폭으로(없으면 종전 100%) */
            .page-container svg { width: var(--rv-pw, 100%) !important; }
            @page { margin: 10mm; }
        }
    </style>
    <script>
    // WASM에서 호출하는 텍스트 폭 측정 함수
    (function() {
        let ctx = null;
        let lastFont = '';
        globalThis.measureTextWidth = function(font, text) {
            if (!ctx) ctx = document.createElement('canvas').getContext('2d');
            if (font !== lastFont) { ctx.font = font; lastFont = font; }
            return ctx.measureText(text).width;
        };
    })();
    </script>
<style>/* ★ (2026-10-26) 선 그림 아이콘(이모지 대신, 원본 api/FsIcons.php) — 글자색을 따름 */ .fs-ico{width:1.2em;height:1.2em;vertical-align:-0.2em;flex-shrink:0} .fs-ico-ok{color:#157347} .fs-ico-err{color:#c62828} .fs-ico-warn{color:#b45309}</style>
</head>
<body>
<?php /* ★ (2026-10-26) 선 그림 아이콘 묶음 + 스크립트가 넣는 이모지도 바꾸는 fs-icons.js (index.php·share.php 와 같음) */ echo fs_icon_sprite(); ?>
<script>window.FS_ICO_EMOJI = <?= fs_icon_map_json() ?>;</script>
<script src="assets/js/fs-icons.js?v=<?= substr(md5_file(__DIR__ . '/assets/js/fs-icons.js'), 0, 10) ?>"></script>
    <div class="viewer-header">
        <span class="title fs-noico" id="header-title" title="<?php echo htmlspecialchars($fileName); ?>"><?php echo htmlspecialchars($fileName); ?></span>
        <div class="controls">
            <?php /* ★ (2026-11-02 10:28, 42차 점검) 이 화면 문구가 번역 함수 밖 한국어 고정이라 영어 화면에도 한국어가 나왔음 → __() 로(한국어 기본 문구는 그대로) */ ?>
            <button id="btn-prev" disabled title="<?php echo htmlspecialchars(__('rhwp_prev_page', '이전 페이지')); ?>">◀</button>
            <span class="page-info" id="page-info">- / -</span>
            <button id="btn-next" disabled title="<?php echo htmlspecialchars(__('rhwp_next_page', '다음 페이지')); ?>">▶</button>
            <span id="rhwp-version" style="font-size:10px;color:#999;margin-left:4px;"></span>
            <select id="zoom-select">
                <option value="fit"><?php echo $currentLang === 'en' ? 'Fit Width' : '화면 맞춤'; ?></option>
                <option value="0.5">50%</option>
                <option value="0.75">75%</option>
                <option value="1">100%</option>
                <option value="1.25">125%</option>
                <option value="1.5">150%</option>
                <option value="2">200%</option>
            </select>
            <?php 
            // hwp_viewer로 전환 버튼 (vault 모드에서도 지원 — hwp_viewer가 vault_url 받음)
            if ($vaultUrl && $vaultName) {
                $hwpSwitchUrl = 'hwp_viewer.php?vault_url=' . rawurlencode($vaultUrl) . '&name=' . rawurlencode($vaultName);
            } elseif ($storageId && $filePath) {
                $hwpSwitchUrl = 'hwp_viewer.php?storage_id=' . rawurlencode($storageId) . '&path=' . rawurlencode($filePath);
            } else {
                $hwpSwitchUrl = '';
            }
            if ($hwpSwitchUrl):
            ?>
            <a href="<?php echo htmlspecialchars($hwpSwitchUrl); ?>" class="switch-btn" title="<?php echo htmlspecialchars(__('rhwp_switch_hwp_title', '기본 뷰어로 보기 (문서가 이상하게 보일 때)')); ?>">🔄 <?php echo htmlspecialchars(__('rhwp_switch_hwp', 'hwp로 보기')); ?></a>
            <?php endif; ?>
            <?php if ($canDownload): ?>
            <button id="btn-download" title="<?php echo htmlspecialchars(__('hwp_download', '다운로드')); ?>">📥</button>
            <?php endif; ?>
            <button id="btn-print" title="<?php echo __('viewer_print'); ?> (Ctrl+P)">🖨️</button>
            <button id="btn-close" title="<?php echo htmlspecialchars(__('hwp_close', '닫기')); ?>">✕</button>
        </div>
    </div>
    
    <div class="viewer-body" id="viewer-body"></div>
    
    <div class="loading-overlay" id="loading">
        <div class="spinner"></div>
        <div class="loading-text" id="loading-text"><?php echo htmlspecialchars(__('rhwp_loading_wasm', 'WASM 로드 중...')); ?></div>
    </div>

    <?php /* ★ (2026-11-18 21:29, 349차 펜닐 요청 "웹 폰트가 아니라 일반 폰트 인식, 특정 경로에 넣어도 인식") 설치 글꼴 확인·서버 글꼴 폴더(rhwp_fonts.php) 도우미 */ ?>
    <script src="assets/js/fs-rhwp-fonts.js?v=<?= substr(md5_file(__DIR__ . '/assets/js/fs-rhwp-fonts.js'), 0, 10) ?>"></script>
    <!-- 한글 폰트 사전 로드 -->
    <script>
    (function() {
        const CDN_HAMCHOB_R = 'https://cdn.jsdelivr.net/gh/projectnoonnu/noonfonts_2104@1.0/HANBatang.woff';
        const CDN_HAMCHOB_B = 'https://cdn.jsdelivr.net/gh/projectnoonnu/noonfonts_2104@1.0/HANBatangB.woff';
        const CDN_HAMCHOD_R = 'https://cdn.jsdelivr.net/gh/projectnoonnu/noonfonts_four@1.0/HCRDotum.woff';
        const base = 'assets/rhwp/studio/';
        const fonts = [
            { name: '함초롬돋움', file: CDN_HAMCHOD_R, format: 'woff' },
            { name: '함초롬바탕', file: CDN_HAMCHOB_R, format: 'woff' },
            { name: '함초롱돋움', file: CDN_HAMCHOD_R, format: 'woff' },
            { name: '함초롱바탕', file: CDN_HAMCHOB_R, format: 'woff' },
            { name: '한컴돋움', file: CDN_HAMCHOD_R, format: 'woff' },
            { name: '한컴바탕', file: CDN_HAMCHOB_R, format: 'woff' },
            { name: '새돋움', file: CDN_HAMCHOD_R, format: 'woff' },
            { name: '새바탕', file: CDN_HAMCHOB_R, format: 'woff' },
            { name: 'HY헤드라인M', file: base + 'fonts/NotoSansKR-Bold.woff2' },
            { name: 'HY견고딕', file: base + 'fonts/NotoSansKR-Bold.woff2' },
            { name: 'HY그래픽', file: base + 'fonts/NotoSansKR-Regular.woff2' },
            { name: 'HY견명조', file: base + 'fonts/NotoSerifKR-Bold.woff2' },
            { name: 'HY신명조', file: base + 'fonts/NotoSerifKR-Regular.woff2' },
            { name: 'HY중고딕', file: base + 'fonts/NotoSansKR-Regular.woff2' },
            { name: 'Malgun Gothic', file: base + 'fonts/Pretendard-Regular.woff2' },
            { name: '맑은 고딕', file: base + 'fonts/Pretendard-Regular.woff2' },
            { name: '돋움', file: base + 'fonts/NotoSansKR-Regular.woff2' },
            { name: '돋움체', file: base + 'fonts/NotoSansKR-Regular.woff2' },
            { name: '굴림', file: base + 'fonts/NotoSansKR-Regular.woff2' },
            { name: '굴림체', file: base + 'fonts/D2Coding-Regular.woff2' },
            { name: '바탕', file: base + 'fonts/NotoSerifKR-Regular.woff2' },
            { name: '바탕체', file: base + 'fonts/D2Coding-Regular.woff2' },
            { name: '궁서', file: base + 'fonts/GowunBatang-Regular.woff2' },
            { name: '궁서체', file: base + 'fonts/GowunBatang-Regular.woff2' },
            { name: '나눔고딕', file: base + 'fonts/NanumGothic-Regular.woff2' },
            { name: '나눔명조', file: base + 'fonts/NanumMyeongjo-Regular.woff2' },
            { name: '나눔고딕코딩', file: base + 'fonts/NanumGothicCoding-Regular.woff2' },
        ];
        // ★ (2026-11-18 21:29, 349차 펜닐 요청 "웹 폰트가 아니라 일반 폰트 인식, 특정 경로에 넣어도 인식")
        //   ① 이 기기에 같은 이름 글꼴이 설치돼 있으면 대체 웹 글꼴을 등록하지 않음(설치 글꼴이 그대로 쓰임 — 종전엔 대체 글꼴이 설치 글꼴을 가렸음)
        //   ② 서버 글꼴 폴더에 같은 이름이 있으면 문서를 열 때 대체 글꼴을 빼고 그 글꼴로 바꿈(아래 start 의 FsRhwpFonts.ensure) — 그래서 이름별로 기억해 둠
        //   설치 확인은 대체 글꼴을 넣기 '전'에 해야 함(넣은 뒤엔 대체 글꼴을 재게 됨). 설치된 게 없으면 종전과 똑같음
        const subFaces = window.__rvSubFaces = {};
        fonts.forEach(f => {
            if (window.FsRhwpFonts && FsRhwpFonts.installed(f.name)) return;
            const format = f.format || 'woff2';
            const src = f.file.startsWith('http') ? f.file : f.file;
            const face = new FontFace(f.name, `url('${src}') format('${format}')`);
            const rec = subFaces[f.name.toLowerCase()] = { cancelled: false, face: face };
            face.load().then(loaded => { if (!rec.cancelled) document.fonts.add(loaded); }).catch(() => {});
        });
    })();
    </script>

    <script>
    // rhwp 내부 콘솔 로그 완전 억제
    (function(){
      const _origLog = console.log, _origWarn = console.warn;
      console.log = function(){
        if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].charAt(0) === '[') return;
        _origLog.apply(console, arguments);
      };
      console.warn = function(){
        if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].charAt(0) === '[') return;
        _origWarn.apply(console, arguments);
      };
    })();
    </script>

    <script type="module">
    // ★ (2026-11-09 09:37, rhwp 0.9.0 업그레이드) 캐시 키를 FileStation 버전 대신 rhwp.js 내용으로 — FileStation 버전을 올리지 않고 rhwp 만 바꾸면 브라우저가
    //   옛 rhwp.js 또는 옛 wasm 하나만 캐시에서 써 JS·wasm 판이 어긋날 수 있었음(같은 페이지 fs-icons.js 와 같은 방식, rhwp.js 는 wasm 과 늘 함께 바뀜)
    import init, { HwpDocument, version } from './assets/rhwp/rhwp.js?v=<?php echo $rhwpAssetKey; ?>';
    
    const STORAGE_ID = <?php echo $storageId; ?>;
    const FILE_PATH = <?php echo json_encode($filePath, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const FILE_NAME = <?php echo json_encode($fileName, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const FILE_EXT = <?php echo json_encode($fileExt, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const VAULT_URL = <?php echo json_encode($vaultUrl, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const APP_VER = <?php echo json_encode(APP_VERSION); ?>;
    // ★ (2026-11-02 10:28, 42차 점검) 스크립트 문구도 번역(종전엔 한국어 고정). 한국어 기본 문구는 그대로
    const RV_T = <?php echo json_encode([
        'engine' => __('rhwp_loading_engine', 'WASM 엔진 로드 중...'),
        'download' => __('rhwp_downloading', '파일 다운로드 중...'),
        'parse' => __('rhwp_parsing', '문서 파싱 중...'),
        'noPages' => __('rhwp_no_pages', '페이지가 없습니다.'),
        'openFailed' => __('rhwp_open_failed', '파일을 열 수 없습니다.'),
        'openBasic' => __('rhwp_open_in_basic', '기존 뷰어로 열기'),
        'renderFailed' => __('rhwp_page_render_failed', '페이지 {n} 렌더링 실패'),
        'pageCount' => __('rhwp_page_count', '{n} 페이지'),
        'unreadable' => __('rhwp_unreadable_format', '문서 형식을 읽지 못했습니다.'),
        'pwRequired' => __('rhwp_password_required', '암호가 걸린 문서라 이 뷰어에서는 열 수 없습니다.'),   // ★ (2026-11-10 19:35, 67차 점검) 암호 문서 오류 원문(한국어·개발자 함수 이름) 대신
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
    let doc = null;
    let currentPage = 0;
    let totalPages = 0;
    const isMobile = window.innerWidth <= 768;
    let currentZoom = isMobile ? 'fit' : 1;
    
    // 모바일이면 zoom-select 기본값 변경
    if (isMobile) {
        const zs = document.getElementById('zoom-select');
        if (zs) zs.value = 'fit';
    } else {
        const zs = document.getElementById('zoom-select');
        if (zs) zs.value = '1';
    }
    
    const body = document.getElementById('viewer-body');
    const loading = document.getElementById('loading');
    const loadingText = document.getElementById('loading-text');
    const pageInfo = document.getElementById('page-info');
    const btnPrev = document.getElementById('btn-prev');
    const btnNext = document.getElementById('btn-next');
    const zoomSelect = document.getElementById('zoom-select');
    
    async function start() {
        try {
            loadingText.textContent = RV_T.engine;
            await init({ module_or_path: './' + <?php echo json_encode($rhwpWasmRel); ?> + '?v=' + encodeURIComponent(<?php echo json_encode($rhwpAssetKey); ?>) });   // ★ (2026-11-15 03:18, 159차) 편집기와 같은 엔진 파일(위 $rhwpWasmRel)
            // rhwp 버전 표시
            try { const verEl = document.getElementById('rhwp-version'); if (verEl) verEl.textContent = 'rhwp v' + version(); } catch(e) {}
            
            loadingText.textContent = RV_T.download;
            const url = VAULT_URL 
                ? VAULT_URL 
                : `rhwp_viewer.php?action=stream&storage_id=${STORAGE_ID}&path=${encodeURIComponent(FILE_PATH)}`;
            const resp = await fetch(url);
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const data = new Uint8Array(await resp.arrayBuffer());
            
            loadingText.textContent = RV_T.parse;
            doc = new HwpDocument(data);
            // ★ (2026-11-18 21:29, 349차 펜닐 요청 "웹 폰트가 아니라 일반 폰트 인식, 특정 경로에 넣어도 인식")
            //   문서에 적힌 글꼴 중 이 기기에 없고 서버 글꼴 폴더(data/fonts)에 있는 것을 받아 등록한 뒤, 글자 폭을 그 글꼴로 다시 재도록 문서를 한 번 더 읽음
            //   (쪽 나누기·줄 바꿈이 글자 폭으로 정해짐). 서버 폴더가 비었거나 맞는 글꼴이 없으면 아무것도 안 함(종전과 같음)
            try {
                if (window.FsRhwpFonts) {
                    const added = await FsRhwpFonts.ensure(FsRhwpFonts.namesFromPlan(doc), { beforeAdd: (n) => {
                        const rec = window.__rvSubFaces && window.__rvSubFaces[String(n).trim().toLowerCase()];
                        if (rec) { rec.cancelled = true; try { document.fonts.delete(rec.face); } catch (e) {} }
                    } });
                    if (added.length) { try { doc.free(); } catch (e) {} doc = new HwpDocument(data); }
                }
            } catch (e) { /* 글꼴 폴더 문제로 문서 열기가 막히지 않게 */ }
            totalPages = doc.pageCount();
            
            if (totalPages === 0) throw new Error(RV_T.noPages);
            
            currentPage = 0;
            renderAllPages();
            updateNav();
            loading.classList.add('hidden');
        } catch (e) {
            loading.classList.add('hidden');
            const _errHwpUrl = VAULT_URL 
                ? `hwp_viewer.php?vault_url=${encodeURIComponent(VAULT_URL)}&name=${encodeURIComponent(FILE_NAME)}`
                : `hwp_viewer.php?storage_id=${STORAGE_ID}&path=${encodeURIComponent(FILE_PATH)}`;
            body.innerHTML = `<div class="error-msg">❌ ${escapeHtml(RV_T.openFailed)}<br><br>${escapeHtml(errText(e))}<br><br><small>${escapeHtml(RV_T.openBasic)}: <a href="${_errHwpUrl}">hwp_viewer.php</a></small></div>`;
        }
    }
    
    function renderAllPages() {
        body.innerHTML = '';
        
        for (let i = 0; i < totalPages; i++) {
            try {
                const svg = doc.renderPageSvg(i);
                const container = document.createElement('div');
                container.className = 'page-container';
                container.dataset.page = i;
                container.innerHTML = svg;
                
                // SVG 크기 조정
                const svgEl = container.querySelector('svg');
                if (svgEl) {
                    if (currentZoom === 'fit') {
                        // 화면 너비에 맞춤 — CSS가 처리
                        container.classList.add('fit-width');
                        svgEl.style.width = '100%';
                        svgEl.style.height = 'auto';
                        { const aw = parseFloat(svgEl.getAttribute('width')); if (aw > 0) svgEl.style.setProperty('--rv-pw', aw + 'px'); } // ★ (2026-11-10 13:19, 63차 E) 인쇄용 본래 폭 보관
                        svgEl.removeAttribute('width');
                        svgEl.removeAttribute('height');
                    } else {
                        container.classList.remove('fit-width');
                        const vb = svgEl.getAttribute('viewBox');
                        if (vb) {
                            const parts = vb.split(/\s+/);
                            const w = parseFloat(parts[2]) * currentZoom;
                            svgEl.style.width = w + 'px';
                            svgEl.style.height = 'auto';
                        }
                    }
                }
                
                body.appendChild(container);
            } catch (e) {
                const errDiv = document.createElement('div');
                errDiv.className = 'page-container';
                errDiv.innerHTML = `<div class="error-msg">${escapeHtml(RV_T.renderFailed.replace('{n}', i + 1))}: ${escapeHtml(errText(e))}</div>`;
                body.appendChild(errDiv);
            }
        }
        pageInfo.textContent = RV_T.pageCount.replace('{n}', totalPages);
    }
    
    function updateNav() {
        btnPrev.disabled = currentPage <= 0;
        btnNext.disabled = currentPage >= totalPages - 1;
    }
    
    // ★ (2026-10-26) 오류 문구 — rhwp(WASM)는 Error 가 아닌 문자열·값을 던지기도 해 e.message 가 없으면 화면에 'undefined' 가 나왔음
    function errText(e) {
        try { const _raw = (e && typeof e.message === 'string') ? e.message : String(e); if (/password|비밀번호/i.test(_raw)) return RV_T.pwRequired; } catch (_) {}   // ★ (2026-11-10 19:35, 67차 점검) 암호 문서만 번역 문구로 — 그 밖의 오류는 종전대로 원문
        if (e && typeof e.message === 'string' && e.message) return e.message;
        if (typeof e === 'string' && e) return e;
        if (e != null) { const s = String(e); if (s && s !== '[object Object]' && s !== 'undefined') return s; }
        return RV_T.unreadable;
    }
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }
    
    // 이벤트
    btnPrev.addEventListener('click', () => {
        if (currentPage > 0) {
            currentPage--;
            const target = body.querySelector(`.page-container[data-page="${currentPage}"]`);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            updateNav();
        }
    });
    
    btnNext.addEventListener('click', () => {
        if (currentPage < totalPages - 1) {
            currentPage++;
            const target = body.querySelector(`.page-container[data-page="${currentPage}"]`);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            updateNav();
        }
    });
    
    // 스크롤 시 현재 페이지 감지
    body.addEventListener('scroll', () => {
        const pages = body.querySelectorAll('.page-container');
        const scrollTop = body.scrollTop;
        const bodyRect = body.getBoundingClientRect();
        
        for (let i = 0; i < pages.length; i++) {
            const rect = pages[i].getBoundingClientRect();
            if (rect.top >= bodyRect.top - rect.height / 2) {
                currentPage = i;
                pageInfo.textContent = `${i + 1} / ${totalPages}`;
                updateNav();
                break;
            }
            // ★ (2026-11-12 06:02, 94차 DOC-5) 맨 끝까지 내렸는데 맞는 쪽이 없으면(마지막 쪽이 화면 높이의 두 배보다 클 때 — 화면 맞춤·150%·200%) 마지막 쪽으로.
            //   종전엔 번호가 앞 값('1 / 24')에 멈추고 ▶ 를 누르면 3쪽으로 되돌아갔음(재현, PC 1400×850)
            if (i === pages.length - 1) {
                currentPage = i;
                pageInfo.textContent = `${i + 1} / ${totalPages}`;
                updateNav();
            }
        }
    });
    
    zoomSelect.addEventListener('change', () => {
        const val = zoomSelect.value;
        if (val === 'fit') {
            currentZoom = 'fit';
            body.querySelectorAll('.page-container').forEach(c => {
                c.classList.add('fit-width');
                const svg = c.querySelector('svg');
                if (svg) {
                    svg.style.width = '100%';
                    svg.style.height = 'auto';
                    { const aw = parseFloat(svg.getAttribute('width')); if (aw > 0) svg.style.setProperty('--rv-pw', aw + 'px'); } // ★ (2026-11-10 13:19, 63차 E) 인쇄용 본래 폭 보관
                    svg.removeAttribute('width');
                    svg.removeAttribute('height');
                }
            });
        } else {
            currentZoom = parseFloat(val);
            body.querySelectorAll('.page-container').forEach(c => {
                c.classList.remove('fit-width');
                const svg = c.querySelector('svg');
                if (svg) {
                    const vb = svg.getAttribute('viewBox');
                    if (vb) {
                        const w = parseFloat(vb.split(/\s+/)[2]) * currentZoom;
                        svg.style.width = w + 'px';
                        svg.style.height = 'auto';
                    }
                }
            });
        }
        // ★ (2026-11-12 06:02, 94차 DOC-5) 배율을 바꾼 뒤 보던 쪽으로 — 종전엔 스크롤 값만 남아 다른 쪽이 보이고 번호는 그대로였음(재현: 4쪽에서 200% → 표시 4, 화면 2쪽)
        { const _kp = body.querySelector(`.page-container[data-page="${currentPage}"]`); if (_kp) _kp.scrollIntoView({ block: 'start' }); }
    });
    
    // 다운로드
    document.getElementById('btn-download')?.addEventListener('click', () => {
        const a = document.createElement('a');
        a.href = VAULT_URL 
            ? VAULT_URL 
            : `api.php?action=download&storage_id=${STORAGE_ID}&path=${encodeURIComponent(FILE_PATH)}`;
        a.download = FILE_NAME;
        a.click();
    });
    
    // 인쇄 (브라우저 기본 인쇄 → 사용자가 "PDF로 저장" 선택 가능)
    //   rhwp가 SVG로 렌더한 페이지를 브라우저가 인쇄 미리보기에 그대로 표시
    document.getElementById('btn-print')?.addEventListener('click', () => {
        window.print();
    });
    
    // 닫기
    document.getElementById('btn-close').addEventListener('click', () => {
        window.close();
        // 팝업이 아니면 뒤로가기
        setTimeout(() => history.back(), 100);
    });
    
    // 키보드
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { window.close(); setTimeout(() => history.back(), 100); }
    });
    
    // 제목 탭 시 좌우 스크롤 토글 (모바일에서 긴 파일명 보기)
    // hwp_viewer.php의 scrollable-name 로직과 동일한 측정 방식
    (function() {
        const titleEl = document.getElementById('header-title');
        if (!titleEl) return;
        titleEl.addEventListener('click', function(e) {
            e.stopPropagation();
            if (this.classList.contains('scrolling')) {
                this.scrollLeft = 0;
                this.classList.remove('scrolling');
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
                    if (isOver2) this.classList.add('scrolling');
                } else {
                    this.classList.add('scrolling');
                }
            }
        });
    })();
    
    start();
    </script>
<?php if ($vaultUrl): /* ★ (2026-10-30 01:56, 26차 점검) 보관함 모드면 열어 둔 동안 2분마다 임시 복호화 파일 연장(hwp_viewer·office_viewer 와 같음) — 종전엔 이 뷰어만 없어 5분 뒤 서버 정리로 지워져 📥 내려받기가 오류 응답을 받았음.
                            닫을 때 정리는 hwp_viewer 로 되돌아갈 때 같은 파일을 쓰므로 하지 않음(서버의 5분 자동 정리가 맡음) */ ?>
    <script>
    (function() {
        try {
            const vu = <?php echo json_encode($vaultUrl, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            const ps = new URLSearchParams(vu.split('?')[1] || '');
            const tempId = ps.get('temp_id'), storageId = ps.get('storage_id'), path = ps.get('path');
            if (tempId && storageId) {
                setInterval(function() {
                    fetch('api.php?action=vault_preview_touch&csrf_token=' + encodeURIComponent(<?php echo json_encode(getCsrfToken()); ?>), {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ temp_id: tempId, storage_id: parseInt(storageId), path: path || '' })
                    }).catch(function(){});
                }, 120000);
            }
        } catch (e) {}
    })();
    </script>
<?php endif; ?>
</body>
</html>
