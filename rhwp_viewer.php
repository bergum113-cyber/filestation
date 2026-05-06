<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * rhwp WASM 기반 HWP/HWPX 뷰어
 * 
 * Rust + WebAssembly (rhwp) 기반 고품질 HWP/HWPX 렌더링
 * https://github.com/edwardkim/rhwp
 * @rhwp_version 0.7.10
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
if ($_checkSid && $_checkPath) {
    $_checkDir = dirname($_checkPath);
    if ($_checkDir === '.') $_checkDir = '';
    if (!$storage->checkFolderPermission($_checkSid, $_checkDir ?: $_checkPath)) {
        http_response_code(403);
        $currentLang = $_SESSION['lang'] ?? 'ko';
        exit('<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:sans-serif;background:#f5f5f5;"><div style="text-align:center;"><h2 style="color:#d32f2f;">🚫 ' . ($currentLang === 'en' ? 'Access Denied' : '접근 권한이 없습니다') . '</h2><p style="color:#666;">' . ($currentLang === 'en' ? 'You do not have permission to access this folder.' : '이 폴더에 대한 접근 권한이 없습니다.') . '</p><a href="index.php" style="color:#1976d2;">' . ($currentLang === 'en' ? 'Go back' : '돌아가기') . '</a></div></body></html>');
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
    if (!$storage->checkFolderPermission($storageId, $fileDir ?: $filePath)) {
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
        if (preg_match('/\.\.[\\/\\\\]/', $filePath) || strpos($filePath, '..') !== false) {
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
        $tempFile = tempnam(sys_get_temp_dir(), 'rhwp_');
        $content = $adapter->read($filePath);
        $adapter->disconnect();
        
        if ($content === '' || $content === false) {
            @unlink($tempFile);
            http_response_code(404);
            exit('File not found');
        }
        
        file_put_contents($tempFile, $content);
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
        
        if (!$realBase || !$realTarget || strpos($realTarget, $realBase) !== 0) {
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
</head>
<body>
    <div class="viewer-header">
        <span class="title" id="header-title" title="<?php echo htmlspecialchars($fileName); ?>"><?php echo htmlspecialchars($fileName); ?></span>
        <div class="controls">
            <button id="btn-prev" disabled title="이전 페이지">◀</button>
            <span class="page-info" id="page-info">- / -</span>
            <button id="btn-next" disabled title="다음 페이지">▶</button>
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
            <a href="<?php echo htmlspecialchars($hwpSwitchUrl); ?>" class="switch-btn" title="기본 뷰어로 보기 (문서가 이상하게 보일 때)">🔄 hwp로 보기</a>
            <?php endif; ?>
            <?php if ($canDownload): ?>
            <button id="btn-download" title="다운로드">📥</button>
            <?php endif; ?>
            <button id="btn-close" title="닫기">✕</button>
        </div>
    </div>
    
    <div class="viewer-body" id="viewer-body"></div>
    
    <div class="loading-overlay" id="loading">
        <div class="spinner"></div>
        <div class="loading-text" id="loading-text">WASM 로드 중...</div>
    </div>

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
        fonts.forEach(f => {
            const format = f.format || 'woff2';
            const src = f.file.startsWith('http') ? f.file : f.file;
            const face = new FontFace(f.name, `url('${src}') format('${format}')`);
            face.load().then(loaded => document.fonts.add(loaded)).catch(() => {});
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
    import init, { HwpDocument, version } from './assets/rhwp/rhwp.js?v=<?php echo APP_VERSION; ?>';
    
    const STORAGE_ID = <?php echo $storageId; ?>;
    const FILE_PATH = <?php echo json_encode($filePath); ?>;
    const FILE_NAME = <?php echo json_encode($fileName); ?>;
    const FILE_EXT = <?php echo json_encode($fileExt); ?>;
    const VAULT_URL = <?php echo json_encode($vaultUrl); ?>;
    const APP_VER = <?php echo json_encode(APP_VERSION); ?>;
    
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
            loadingText.textContent = 'WASM 엔진 로드 중...';
            await init({ module_or_path: './assets/rhwp/rhwp_bg.wasm?v=' + encodeURIComponent(APP_VER) });
            // rhwp 버전 표시
            try { const verEl = document.getElementById('rhwp-version'); if (verEl) verEl.textContent = 'rhwp v' + version(); } catch(e) {}
            
            loadingText.textContent = '파일 다운로드 중...';
            const url = VAULT_URL 
                ? VAULT_URL 
                : `rhwp_viewer.php?action=stream&storage_id=${STORAGE_ID}&path=${encodeURIComponent(FILE_PATH)}`;
            const resp = await fetch(url);
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const data = new Uint8Array(await resp.arrayBuffer());
            
            loadingText.textContent = '문서 파싱 중...';
            doc = new HwpDocument(data);
            totalPages = doc.pageCount();
            
            if (totalPages === 0) throw new Error('페이지가 없습니다.');
            
            currentPage = 0;
            renderAllPages();
            updateNav();
            loading.classList.add('hidden');
        } catch (e) {
            loading.classList.add('hidden');
            const _errHwpUrl = VAULT_URL 
                ? `hwp_viewer.php?vault_url=${encodeURIComponent(VAULT_URL)}&name=${encodeURIComponent(FILE_NAME)}`
                : `hwp_viewer.php?storage_id=${STORAGE_ID}&path=${encodeURIComponent(FILE_PATH)}`;
            body.innerHTML = `<div class="error-msg">❌ 파일을 열 수 없습니다.<br><br>${escapeHtml(e.message)}<br><br><small>기존 뷰어로 열기: <a href="${_errHwpUrl}">hwp_viewer.php</a></small></div>`;
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
                errDiv.innerHTML = `<div class="error-msg">페이지 ${i + 1} 렌더링 실패: ${escapeHtml(e.message)}</div>`;
                body.appendChild(errDiv);
            }
        }
        pageInfo.textContent = `${totalPages} 페이지`;
    }
    
    function updateNav() {
        btnPrev.disabled = currentPage <= 0;
        btnNext.disabled = currentPage >= totalPages - 1;
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
</body>
</html>
