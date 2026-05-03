<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * rhwp-studio HWP 에디터 래퍼
 * https://github.com/edwardkim/rhwp
 * @rhwp_version 0.7.9
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';

$storage = new Storage();
$auth = new Auth();

if (!$auth->isLoggedIn()) { header('Location: index.php'); exit; }

$storageId = (int)($_GET['storage_id'] ?? 0);
$filePath = $_GET['path'] ?? '';
$fileName = basename($filePath);

if ($storageId && !$storage->checkPermission($storageId, 'can_read')) {
    http_response_code(403); exit('No permission');
}

// 파일 스트리밍
if (isset($_GET['action']) && $_GET['action'] === 'stream') {
    // 폴더별 권한 체크
    $fileDir = dirname($filePath);
    if ($fileDir === '.') $fileDir = '';
    if (!$storage->checkFolderPermission($storageId, $fileDir ?: $filePath)) {
        http_response_code(403);
        exit('No permission');
    }
    
    // 세션 락 해제: 큰 파일 스트리밍 중 다른 요청(같은 사용자의 웹하드 등)이 
    // 세션 대기로 무한 로딩처럼 보이는 현상 방지
    // (권한 체크 이후이므로 인증/권한 정보는 이미 확인 완료)
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    $storageInfo = $storage->getStorageById($storageId);
    if (!$storageInfo) { http_response_code(403); exit; }
    $storageType = $storageInfo['storage_type'] ?? 'local';
    $isRemote = in_array($storageType, ['ftp', 'sftp', 'webdav', 's3', 'smb']);
    
    if ($isRemote) {
        if (preg_match('/\.\.[\\/\\\\]/', $filePath)) { http_response_code(403); exit; }
        require_once __DIR__ . '/api/StorageAdapter.php';
        $adapter = StorageAdapterFactory::create($storageInfo);
        if (!$adapter || !$adapter->connect()) { http_response_code(503); exit; }
        // ★ office_viewer.php 패턴 통일: read() + file_put_contents() (download() 메서드 없음)
        // ★ 잔류 정리 안전망 (v5.8.1c — readfile 중 abort 시 @unlink 못 함)
        foreach (@glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rhwpe_*') ?: [] as $_old) {
            if (is_file($_old) && filemtime($_old) < time() - 3600) @unlink($_old);
        }
        $tempFile = tempnam(sys_get_temp_dir(), 'rhwpe_');
        $content = $adapter->read($filePath);
        $adapter->disconnect();
        if ($content === '' || $content === false) {
            @unlink($tempFile);
            http_response_code(404);
            exit;
        }
        file_put_contents($tempFile, $content);
        $realFile = $tempFile; $cleanup = true;
    } else {
        $basePath = $storage->getRealPath($storageId);
        if (!$basePath) { http_response_code(403); exit; }
        $realFile = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        $realBase = realpath($basePath); $realTarget = realpath($realFile);
        if (!$realBase || !$realTarget || strpos($realTarget, $realBase) !== 0) { http_response_code(403); exit; }
        $realFile = $realTarget; $cleanup = false;
    }
    if (!file_exists($realFile)) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($ext === 'hwpx' ? 'application/hwp+zip' : 'application/x-hwp'));
    header('Content-Length: ' . filesize($realFile));
    header('Cache-Control: no-cache');
    readfile($realFile);
    if ($cleanup) @unlink($realFile);
    exit;
}

// 서버 저장 (rhwp 에디터 → 원본 파일 덮어쓰기)
if (isset($_GET['action']) && $_GET['action'] === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$storageId || !$filePath) {
        echo json_encode(['success' => false, 'error' => 'Missing storage_id or path']); exit;
    }
    // HWPX 차단 (rhwp 0.7.3 엔진의 exportHwpx가 베타 단계로 데이터 손상 위험)
    if (preg_match('/\\.hwpx$/i', $filePath)) {
        echo json_encode(['success' => false, 'error' => 'HWPX 저장은 형식 손상 위험으로 지원하지 않습니다']); exit;
    }
    if (!$storage->checkPermission($storageId, 'can_write')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No write permission']); exit;
    }
    // 폴더별 쓰기 권한 체크
    $saveDir = dirname($filePath);
    if ($saveDir === '.') $saveDir = '';
    if (!$storage->checkFolderPermission($storageId, $saveDir ?: $filePath, 'can_write')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No folder write permission']); exit;
    }
    
    // 세션 락 해제: 원격 스토리지 업로드 중 웹하드가 블로킹되지 않도록
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    // POST body에서 바이너리 읽기
    $rawData = file_get_contents('php://input');
    if (!$rawData || strlen($rawData) < 16) {
        echo json_encode(['success' => false, 'error' => 'Empty or invalid data']); exit;
    }
    
    $storageInfo = $storage->getStorageById($storageId);
    if (!$storageInfo) {
        echo json_encode(['success' => false, 'error' => 'Storage not found']); exit;
    }
    
    $storageType = $storageInfo['storage_type'] ?? 'local';
    $isRemote = in_array($storageType, ['ftp', 'sftp', 'webdav', 's3', 'smb']);
    
    if ($isRemote) {
        if (preg_match('/\.\.[\\/\\\\]/', $filePath)) {
            echo json_encode(['success' => false, 'error' => 'Invalid path']); exit;
        }
        require_once __DIR__ . '/api/StorageAdapter.php';
        $adapter = StorageAdapterFactory::create($storageInfo);
        if (!$adapter || !$adapter->connect()) {
            echo json_encode(['success' => false, 'error' => 'Remote connection failed']); exit;
        }
        // ★ office_viewer.php 패턴 통일: write() 직접 호출 (upload() 메서드 없음)
        $ok = $adapter->write($filePath, $rawData);
        $adapter->disconnect();
        echo json_encode(['success' => (bool)$ok, 'size' => strlen($rawData)]);
    } else {
        $basePath = $storage->getRealPath($storageId);
        if (!$basePath) {
            echo json_encode(['success' => false, 'error' => 'Storage path not found']); exit;
        }
        $realFile = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        $realBase = realpath($basePath);
        // 원본 파일이 존재해야 덮어쓰기 (경로 검증)
        $realTarget = realpath($realFile);
        if (!$realBase || !$realTarget || strpos($realTarget, $realBase) !== 0) {
            echo json_encode(['success' => false, 'error' => 'Path traversal denied']); exit;
        }
        // 원자적 쓰기: 임시파일 → rename
        $tempFile = $realTarget . '.rhwp_tmp_' . getmypid();
        $written = file_put_contents($tempFile, $rawData);
        if ($written === false) {
            @unlink($tempFile);
            echo json_encode(['success' => false, 'error' => 'Write failed']); exit;
        }
        if (!rename($tempFile, $realTarget)) {
            @unlink($tempFile);
            echo json_encode(['success' => false, 'error' => 'Rename failed']); exit;
        }
        echo json_encode(['success' => true, 'size' => $written]);
    }
    exit;
}

// 서버 저장 (다른 이름으로 저장 — 현재 파일과 같은 폴더에 새 파일명으로 저장)
if (isset($_GET['action']) && $_GET['action'] === 'save-as' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$storageId || !$filePath) {
        echo json_encode(['success' => false, 'error' => 'Missing storage_id or path']); exit;
    }
    
    // 새 파일명 (요청 파라미터)
    $newName = trim($_GET['new_name'] ?? '');
    if (!$newName) {
        echo json_encode(['success' => false, 'error' => 'Missing new_name']); exit;
    }
    // 파일명 검증: 경로 구분자/상대경로 문자 불가
    if (preg_match('/[\\/\\\\:*?"<>|]|\\.\\./', $newName)) {
        echo json_encode(['success' => false, 'error' => '파일명에 사용할 수 없는 문자가 있습니다']); exit;
    }
    if (strlen($newName) > 240) {
        echo json_encode(['success' => false, 'error' => '파일명이 너무 깁니다']); exit;
    }
    // 확장자 검증: .hwp만 허용 (.hwpx는 rhwp 0.7.3 엔진 베타로 인해 데이터 손상 위험이 있어 차단)
    if (!preg_match('/\\.hwp$/i', $newName) || preg_match('/\\.hwpx$/i', $newName)) {
        echo json_encode(['success' => false, 'error' => '.hwp 확장자만 지원합니다 (HWPX는 저장 미지원)']); exit;
    }
    
    if (!$storage->checkPermission($storageId, 'can_write')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No write permission']); exit;
    }
    
    // 저장 폴더 = 현재 파일의 폴더
    $saveDir = dirname($filePath);
    if ($saveDir === '.') $saveDir = '';
    if (!$storage->checkFolderPermission($storageId, $saveDir ?: $newName, 'can_write')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No folder write permission']); exit;
    }
    
    // 세션 락 해제: 원격 업로드 중 웹하드가 블로킹되지 않도록
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    // 새 파일 경로 조합
    $newFilePath = $saveDir === '' ? $newName : ($saveDir . '/' . $newName);
    
    // POST body에서 바이너리 읽기
    $rawData = file_get_contents('php://input');
    if (!$rawData || strlen($rawData) < 16) {
        echo json_encode(['success' => false, 'error' => 'Empty or invalid data']); exit;
    }
    
    $storageInfo = $storage->getStorageById($storageId);
    if (!$storageInfo) {
        echo json_encode(['success' => false, 'error' => 'Storage not found']); exit;
    }
    
    $storageType = $storageInfo['storage_type'] ?? 'local';
    $isRemote = in_array($storageType, ['ftp', 'sftp', 'webdav', 's3', 'smb']);
    
    if ($isRemote) {
        if (preg_match('/\\.\\.[\\\\/\\\\\\\\]/', $newFilePath)) {
            echo json_encode(['success' => false, 'error' => 'Invalid path']); exit;
        }
        require_once __DIR__ . '/api/StorageAdapter.php';
        $adapter = StorageAdapterFactory::create($storageInfo);
        if (!$adapter || !$adapter->connect()) {
            echo json_encode(['success' => false, 'error' => 'Remote connection failed']); exit;
        }
        // 덮어쓰기 방지: 같은 이름 파일 존재 확인 (force=1 이면 덮어쓰기 허용)
        $forceOverwrite = !empty($_GET['force']);
        if (!$forceOverwrite) {
            $exists = false;
            try { $exists = $adapter->exists($newFilePath); } catch (Exception $e) { $exists = false; }
            if ($exists) {
                $adapter->disconnect();
                echo json_encode(['success' => false, 'error' => '같은 이름의 파일이 이미 있습니다', 'exists' => true]); exit;
            }
        }
        // ★ office_viewer.php 패턴 통일: write() 직접 호출 (upload() 메서드 없음)
        $ok = $adapter->write($newFilePath, $rawData);
        $adapter->disconnect();
        echo json_encode(['success' => (bool)$ok, 'size' => strlen($rawData), 'path' => $newFilePath, 'name' => $newName]);
    } else {
        $basePath = $storage->getRealPath($storageId);
        if (!$basePath) {
            echo json_encode(['success' => false, 'error' => 'Storage path not found']); exit;
        }
        $realBase = realpath($basePath);
        if (!$realBase) {
            echo json_encode(['success' => false, 'error' => 'Storage path invalid']); exit;
        }
        // 새 파일 전체 경로 (아직 존재하지 않을 수 있음 → realpath 대신 조합)
        $newRealFile = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\\\'], DIRECTORY_SEPARATOR, $newFilePath);
        // 경로 탈출 검증: 디렉토리만 realpath 후 base 안에 있는지 확인
        $newDir = dirname($newRealFile);
        $realNewDir = realpath($newDir);
        if (!$realNewDir || strpos($realNewDir, $realBase) !== 0) {
            echo json_encode(['success' => false, 'error' => 'Path traversal denied']); exit;
        }
        // 덮어쓰기 방지 (force=1 이면 덮어쓰기 허용)
        $forceOverwrite = !empty($_GET['force']);
        if (!$forceOverwrite && file_exists($newRealFile)) {
            echo json_encode(['success' => false, 'error' => '같은 이름의 파일이 이미 있습니다', 'exists' => true]); exit;
        }
        // 덮어쓰기 시에도 파일이 base 디렉토리 내부인지 확인
        if ($forceOverwrite && file_exists($newRealFile)) {
            $realExisting = realpath($newRealFile);
            if (!$realExisting || strpos($realExisting, $realBase) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Path traversal denied (overwrite target)']); exit;
            }
        }
        // 원자적 쓰기
        $tempFile = $newRealFile . '.rhwp_tmp_' . getmypid();
        $written = file_put_contents($tempFile, $rawData);
        if ($written === false) {
            @unlink($tempFile);
            echo json_encode(['success' => false, 'error' => 'Write failed']); exit;
        }
        if (!rename($tempFile, $newRealFile)) {
            @unlink($tempFile);
            echo json_encode(['success' => false, 'error' => 'Rename failed']); exit;
        }
        echo json_encode(['success' => true, 'size' => $written, 'path' => $newFilePath, 'name' => $newName]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <base href="assets/rhwp/studio/" />
  <link rel="icon" href="favicon.ico" />
  <title><?php echo htmlspecialchars($fileName ?: 'rhwp-studio'); ?></title>
  <script>
  // rhwp-studio 내부 콘솔 로그 억제 + WASM 초기화 감지
  window.__rhwpWasmReady = false;
  window.__rhwpDocLoaded = false;
  (function(){
    const _origLog = console.log, _origWarn = console.warn, _origError = console.error;
    console.log = function(){
      if (arguments[0] && typeof arguments[0] === 'string') {
        // WASM 초기화 완료 감지
        if (arguments[0].indexOf('[WasmBridge] WASM') !== -1) {
          window.__rhwpWasmReady = true;
          // rhwp 버전 표시
          try {
            import('../rhwp.js').then(m => {
              const el = document.getElementById('rhwp-editor-version');
              if (el && m.version) el.textContent = 'rhwp v' + m.version();
            }).catch(() => {});
          } catch(e) {}
        }
        // 문서 로드 완료 감지
        if (arguments[0].indexOf('[CanvasView]') !== -1 && arguments[0].indexOf('페이지 로드') !== -1) window.__rhwpDocLoaded = true;
        if (arguments[0].indexOf('[initDoc]') !== -1 && arguments[0].indexOf('완료') !== -1) window.__rhwpDocLoaded = true;
        // 우리 커스텀 로그는 통과시키고, studio 내부 [태그] 로그만 억제
        if (arguments[0].indexOf('[rhwp_editor]') === 0) {
          _origLog.apply(console, arguments);
          return;
        }
        if (arguments[0].charAt(0) === '[') return;
      }
      _origLog.apply(console, arguments);
    };
    console.warn = function(){
      if (arguments[0] && typeof arguments[0] === 'string') {
        if (arguments[0].indexOf('[rhwp_editor]') === 0) {
          _origWarn.apply(console, arguments);
          return;
        }
        if (arguments[0].charAt(0) === '[') return;
      }
      _origWarn.apply(console, arguments);
    };
    // console.error는 원래 건드리지 않았지만, studio 내부 에러 중 일부가 의미 있을 수 있어 그대로 둠
    // 단, [rhwp_editor] 에러는 우리 것이므로 명시적으로 통과 보장
  })();
  </script>
  <script>
  // ============================================================
  // [중요] Ctrl+S / Ctrl+Shift+S 선등록 핸들러
  // ============================================================
  // studio JS(index-*.js)는 type="module"이므로 defer처럼 동작해 inline script
  // 뒤에 실행됩니다. 여기서 keydown을 먼저 등록하면 studio 내부 dispatcher보다
  // 이벤트 큐에서 우선 실행됩니다 (같은 phase 내에서는 등록 순서대로 실행).
  //
  // 이 단계에서는 exportAndSave/exportAndSaveAs가 아직 정의 안 됐으므로 플래그만 세팅.
  // 본 처리는 하단 <script>의 dispatch 함수에서 수행합니다.
  //
  // 이렇게 하는 이유: studio JS 내 `[{key:'s',ctrl:!0},'file:save']` 매핑을
  // 패치 J1으로 제거했더라도, 미처 못 잡은 다른 경로(예: type="module" 로드 타이밍
  // 차이, 다른 매핑 테이블)가 있을 수 있어 capture phase 선등록이 가장 안전합니다.
  window.__rhwpEarlyKeydown = function earlyKeydown(e) {
      if (!(e.ctrlKey || e.metaKey)) return;
      if (e.code !== 'KeyS') return;
      // 동일 이벤트가 window/document × capture/bubble 4곳에서 중복 호출되는 것 방지
      if (e.__rhwpHandled) return;
      e.__rhwpHandled = true;
      // 이 시점엔 exportAndSave가 없을 수 있으므로 현재 이벤트는 막고 큐에 저장
      e.preventDefault();
      e.stopPropagation();
      if (e.stopImmediatePropagation) e.stopImmediatePropagation();
      // console.log('[rhwp_editor] early Ctrl+S captured, shift=' + e.shiftKey);
      // 이후 처리는 메인 스크립트의 window.__rhwpHandleSave가 받아서 수행
      if (typeof window.__rhwpHandleSave === 'function') {
          window.__rhwpHandleSave(e.shiftKey);
      } else {
          // 아직 준비 안 됨 - 짧게 대기 후 재시도 (WASM 초기화 중일 수 있음)
          const pending = { shift: e.shiftKey, ts: Date.now() };
          const retry = () => {
              if (typeof window.__rhwpHandleSave === 'function') {
                  window.__rhwpHandleSave(pending.shift);
              } else if (Date.now() - pending.ts < 5000) {
                  setTimeout(retry, 50);
              }
          };
          setTimeout(retry, 50);
      }
      return false;
  };
  // window/document 양쪽 capture + bubble 모두 등록 (최대한 먼저 잡히도록)
  window.addEventListener('keydown', window.__rhwpEarlyKeydown, true);
  document.addEventListener('keydown', window.__rhwpEarlyKeydown, true);
  window.addEventListener('keydown', window.__rhwpEarlyKeydown, false);
  document.addEventListener('keydown', window.__rhwpEarlyKeydown, false);
  </script>
  <script type="module" crossorigin src="index-CCef3-Zl.js?v=<?php echo APP_VERSION; ?>"></script>
  <link rel="stylesheet" crossorigin href="index-ro3nVBB2.css?v=<?php echo APP_VERSION; ?>">
</head>
<body>
  <div id="studio-root">
    <!-- 메뉴바 -->
    <div id="menu-bar">
      <!-- ── 파일 ── -->
      <div class="menu-item" data-menu="file">
        <span class="menu-title">파일</span>
        <div class="menu-dropdown">
          <div class="md-item disabled" data-cmd="file:new-doc"><span class="md-icon icon-new-doc"></span><span class="md-label">새로 만들기</span></div>
          <div class="md-item" data-cmd="file:open"><span class="md-icon"></span><span class="md-label">열기</span></div>
          <div class="md-item" data-cmd="file:save"><span class="md-icon icon-save"></span><span class="md-label">저장</span><span class="md-shortcut">Ctrl+S</span></div>
          <div class="md-item" data-cmd="file:save-as"><span class="md-icon icon-save"></span><span class="md-label">다른 이름으로 저장</span><span class="md-shortcut">Ctrl+Shift+S</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="file:page-setup"><span class="md-icon icon-page-setup"></span><span class="md-label">편집 용지</span><span class="md-shortcut">F7</span></div>
          <div class="md-item disabled" data-cmd="file:print"><span class="md-icon icon-print"></span><span class="md-label">인쇄</span><span class="md-shortcut">Ctrl+P</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="file:about"><span class="md-icon icon-help"></span><span class="md-label">제품 정보</span></div>
        </div>
      </div>
      <!-- ── 편집 ── -->
      <div class="menu-item" data-menu="edit">
        <span class="menu-title">편집</span>
        <div class="menu-dropdown">
          <div class="md-item" data-cmd="edit:undo"><span class="md-icon icon-undo"></span><span class="md-label">되돌리기</span><span class="md-shortcut">Ctrl+Z</span></div>
          <div class="md-item" data-cmd="edit:redo"><span class="md-icon icon-redo"></span><span class="md-label">다시 실행</span><span class="md-shortcut">Ctrl+Shift+Z</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="edit:cut"><span class="md-icon icon-cut"></span><span class="md-label">오려 두기</span><span class="md-shortcut">Ctrl+X</span></div>
          <div class="md-item" data-cmd="edit:copy"><span class="md-icon icon-copy"></span><span class="md-label">복사하기</span><span class="md-shortcut">Ctrl+C</span></div>
          <div class="md-item" data-cmd="edit:paste"><span class="md-icon icon-paste"></span><span class="md-label">붙이기</span><span class="md-shortcut">Ctrl+V</span></div>
          <div class="md-item disabled" data-cmd="edit:format-copy"><span class="md-icon icon-format-copy"></span><span class="md-label">모양 복사</span><span class="md-shortcut">Ctrl+Alt+C</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="edit:delete"><span class="md-icon icon-delete"></span><span class="md-label">지우기</span><span class="md-shortcut">Ctrl+E</span></div>
          <div class="md-item disabled" data-cmd="edit:select-all"><span class="md-icon icon-select-all"></span><span class="md-label">모두 선택</span><span class="md-shortcut">Ctrl+A</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="edit:find"><span class="md-icon icon-find"></span><span class="md-label">찾기(F)</span><span class="md-shortcut">Ctrl+F</span></div>
          <div class="md-item" data-cmd="edit:find-replace"><span class="md-icon icon-find-replace"></span><span class="md-label">찾아 바꾸기(E)</span><span class="md-shortcut">Ctrl+F2</span></div>
          <div class="md-item" data-cmd="edit:find-again"><span class="md-icon"></span><span class="md-label">다시 찾기(X)</span><span class="md-shortcut">Ctrl+L</span></div>
          <div class="md-item" data-cmd="edit:goto"><span class="md-icon"></span><span class="md-label">찾아가기(G)</span><span class="md-shortcut">Alt+G</span></div>
        </div>
      </div>
      <!-- ── 보기 ── -->
      <div class="menu-item" data-menu="view">
        <span class="menu-title">보기</span>
        <div class="menu-dropdown">
          <div class="md-item" data-cmd="view:zoom-in"><span class="md-icon icon-zoom-menu-in"></span><span class="md-label">확대</span><span class="md-shortcut">Shift+Num +</span></div>
          <div class="md-item" data-cmd="view:zoom-out"><span class="md-icon icon-zoom-menu-out"></span><span class="md-label">축소</span><span class="md-shortcut">Shift+Num -</span></div>
          <div class="md-sep"></div>
          <div class="md-sub">
            <span class="md-label">배율</span><span class="md-arrow">▶</span>
            <div class="md-sub-panel">
              <div class="md-item" data-cmd="view:zoom-fit-page"><span class="md-icon"></span><span class="md-label">쪽 맞춤</span></div>
              <div class="md-item" data-cmd="view:zoom-fit-width"><span class="md-icon"></span><span class="md-label">폭 맞춤</span></div>
              <div class="md-sep"></div>
              <div class="md-item" data-cmd="view:zoom-50"><span class="md-icon"></span><span class="md-label">50%</span></div>
              <div class="md-item" data-cmd="view:zoom-75"><span class="md-icon"></span><span class="md-label">75%</span></div>
              <div class="md-item" data-cmd="view:zoom-100"><span class="md-icon"></span><span class="md-label">100%</span></div>
              <div class="md-item" data-cmd="view:zoom-125"><span class="md-icon"></span><span class="md-label">125%</span></div>
              <div class="md-item" data-cmd="view:zoom-150"><span class="md-icon"></span><span class="md-label">150%</span></div>
              <div class="md-item" data-cmd="view:zoom-200"><span class="md-icon"></span><span class="md-label">200%</span></div>
              <div class="md-item" data-cmd="view:zoom-300"><span class="md-icon"></span><span class="md-label">300%</span></div>
            </div>
          </div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="view:ctrl-mark"><span class="md-icon icon-ctrl-mark"></span><span class="md-label">조판 부호</span><span class="md-shortcut">Ctrl+G+C</span></div>
          <div class="md-item" data-cmd="view:para-mark"><span class="md-icon icon-para-mark"></span><span class="md-label">문단 부호</span></div>
          <div class="md-item" data-cmd="view:border-transparent"><span class="md-icon"></span><span class="md-label">투명 선</span></div>
          <div class="md-item" data-cmd="view:toggle-clip"><span class="md-icon"></span><span class="md-label">잘림 보기</span></div>
          <div class="md-item" data-cmd="view:grid-settings"><span class="md-icon icon-grid"></span><span class="md-label">격자 설정</span></div>
          <div class="md-sep"></div>
          <div class="md-sub">
            <span class="md-label">도구 상자</span><span class="md-arrow">▶</span>
            <div class="md-sub-panel">
              <div class="md-item disabled" data-cmd="view:toolbox-basic"><span class="md-label">기본</span></div>
              <div class="md-item disabled" data-cmd="view:toolbox-format"><span class="md-label">서식</span></div>
            </div>
          </div>
        </div>
      </div>
      <!-- ── 입력 ── -->
      <div class="menu-item" data-menu="insert">
        <span class="menu-title">입력</span>
        <div class="menu-dropdown">
          <div class="md-item" data-cmd="insert:shape"><span class="md-icon icon-shape"></span><span class="md-label">도형</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="insert:image"><span class="md-icon icon-image"></span><span class="md-label">그림</span></div>
          <div class="md-item" data-cmd="insert:textbox"><span class="md-icon icon-textbox"></span><span class="md-label">글상자</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="insert:field"><span class="md-icon"></span><span class="md-label">필드 입력</span><span class="md-shortcut">Ctrl+K+E</span></div>
          <div class="md-sep"></div>
          <div class="md-sub disabled">
            <span class="md-label">캡션 넣기</span><span class="md-arrow">▶</span>
            <div class="md-sub-panel">
              <div class="md-item disabled" data-cmd="insert:caption-top"><span class="md-icon"></span><span class="md-label">위</span></div>
              <div class="md-item disabled" data-cmd="insert:caption-lt"><span class="md-icon"></span><span class="md-label">왼쪽 위</span></div>
              <div class="md-item disabled" data-cmd="insert:caption-lm"><span class="md-icon"></span><span class="md-label">왼쪽 가운데</span></div>
              <div class="md-item disabled" data-cmd="insert:caption-lb"><span class="md-icon"></span><span class="md-label">왼쪽 아래</span></div>
              <div class="md-item disabled" data-cmd="insert:caption-rt"><span class="md-icon"></span><span class="md-label">오른쪽 위</span></div>
              <div class="md-item disabled" data-cmd="insert:caption-rm"><span class="md-icon"></span><span class="md-label">오른쪽 가운데</span></div>
              <div class="md-item disabled" data-cmd="insert:caption-rb"><span class="md-icon"></span><span class="md-label">오른쪽 아래</span></div>
              <div class="md-item disabled" data-cmd="insert:caption-bottom"><span class="md-icon"></span><span class="md-label">아래</span></div>
              <div class="md-item disabled" data-cmd="insert:caption-none"><span class="md-icon"></span><span class="md-label">캡션 없음</span></div>
            </div>
          </div>
          <div class="md-item disabled" data-cmd="insert:para-band"><span class="md-icon"></span><span class="md-label">문단 띠</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="insert:comment"><span class="md-icon icon-comment"></span><span class="md-label">주석</span></div>
          <div class="md-item" data-cmd="insert:footnote"><span class="md-icon icon-footnote"></span><span class="md-label">각주</span></div>
          <div class="md-item disabled" data-cmd="insert:endnote"><span class="md-icon icon-endnote"></span><span class="md-label">미주</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="insert:symbols"><span class="md-icon icon-symbols"></span><span class="md-label">문자표</span><span class="md-shortcut">Alt+F10</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="insert:hyperlink"><span class="md-icon icon-hyperlink"></span><span class="md-label">하이퍼링크</span><span class="md-shortcut">Ctrl+K+H</span></div>
          <div class="md-item" data-cmd="insert:bookmark"><span class="md-icon"></span><span class="md-label">책갈피</span><span class="md-shortcut">Ctrl+K,B</span></div>
          <div class="md-sep"></div>
          <div class="md-sub">
            <span class="md-label">회전/대칭</span><span class="md-arrow">&#x25B6;</span>
            <div class="md-sub-panel">
              <div class="md-item" data-cmd="insert:rotate-cw"><span class="md-icon"></span><span class="md-label">오른쪽 90° 회전</span></div>
              <div class="md-item" data-cmd="insert:rotate-ccw"><span class="md-icon"></span><span class="md-label">왼쪽 90° 회전</span></div>
              <div class="md-sep"></div>
              <div class="md-item" data-cmd="insert:flip-horz"><span class="md-icon"></span><span class="md-label">좌우 대칭</span></div>
              <div class="md-item" data-cmd="insert:flip-vert"><span class="md-icon"></span><span class="md-label">상하 대칭</span></div>
            </div>
          </div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="insert:picture-props"><span class="md-icon icon-obj-props"></span><span class="md-label">개체 속성</span></div>
          <div class="md-item" data-cmd="insert:picture-delete"><span class="md-icon icon-delete"></span><span class="md-label">개체 지우기</span></div>
        </div>
      </div>
      <!-- ── 서식 ── -->
      <div class="menu-item" data-menu="format">
        <span class="menu-title">서식</span>
        <div class="menu-dropdown">
          <div class="md-item disabled" data-cmd="format:char-shape"><span class="md-icon icon-char-shape"></span><span class="md-label">글자 모양</span><span class="md-shortcut">Alt+L</span></div>
          <div class="md-item disabled" data-cmd="format:para-shape"><span class="md-icon icon-para-shape"></span><span class="md-label">문단 모양</span><span class="md-shortcut">Alt+T</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="format:para-num-shape"><span class="md-icon"></span><span class="md-label">문단 번호 모양</span></div>
          <div class="md-item disabled" data-cmd="format:bullet-shape"><span class="md-icon"></span><span class="md-label">글머리표 모양</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="format:level-increase"><span class="md-icon"></span><span class="md-label">한 수준 증가</span><span class="md-shortcut">Ctrl+Num -</span></div>
          <div class="md-item" data-cmd="format:level-decrease"><span class="md-icon"></span><span class="md-label">한 수준 감소</span><span class="md-shortcut">Ctrl+Num +</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="format:style-dialog"><span class="md-icon"></span><span class="md-label">스타일</span><span class="md-shortcut">F6</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="format:object-properties"><span class="md-icon icon-obj-props"></span><span class="md-label">개체 속성</span><span class="md-shortcut">P</span></div>
        </div>
      </div>
      <!-- ── 쪽 ── -->
      <div class="menu-item" data-menu="page">
        <span class="menu-title">쪽</span>
        <div class="menu-dropdown">
          <div class="md-item disabled" data-cmd="page:setup"><span class="md-icon icon-page-setup"></span><span class="md-label">편집 용지</span><span class="md-shortcut">F7</span></div>
          <div class="md-sep"></div>
          <!-- ── 머리말 (부모 서브메뉴) ── -->
          <div class="md-sub disabled">
            <span class="md-icon icon-header"></span><span class="md-label">머리말</span><span class="md-arrow">▶</span>
            <div class="md-sub-panel">
              <!-- 양쪽 -->
              <div class="md-sub">
                <span class="md-label">양쪽</span><span class="md-arrow">▶</span>
                <div class="md-sub-panel md-hf-template-panel">
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="0" data-template-id="0"><span class="md-label">빈 머리말</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="0" data-template-id="1"><span class="md-label">왼쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="0" data-template-id="2"><span class="md-label">가운데 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="0" data-template-id="3"><span class="md-label">오른쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="0" data-template-id="4"><span class="md-label">쪽 번호 + 파일 이름</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="0" data-template-id="5"><span class="md-label">파일 이름 + 쪽 번호</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="0" data-template-id="6"><span class="md-label"><b>왼쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="0" data-template-id="7"><span class="md-label"><b>가운데 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="0" data-template-id="8"><span class="md-label"><b>오른쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="0" data-template-id="9"><span class="md-label"><b>쪽 번호 + 파일 이름</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="0" data-template-id="10"><span class="md-label"><b>파일 이름 + 쪽 번호</b></span></div>
                </div>
              </div>
              <!-- 홀수 쪽 -->
              <div class="md-sub">
                <span class="md-label">홀수 쪽</span><span class="md-arrow">▶</span>
                <div class="md-sub-panel md-hf-template-panel">
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="2" data-template-id="0"><span class="md-label">빈 머리말</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="2" data-template-id="1"><span class="md-label">왼쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="2" data-template-id="2"><span class="md-label">가운데 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="2" data-template-id="3"><span class="md-label">오른쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="2" data-template-id="4"><span class="md-label">쪽 번호 + 파일 이름</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="2" data-template-id="5"><span class="md-label">파일 이름 + 쪽 번호</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="2" data-template-id="6"><span class="md-label"><b>왼쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="2" data-template-id="7"><span class="md-label"><b>가운데 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="2" data-template-id="8"><span class="md-label"><b>오른쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="2" data-template-id="9"><span class="md-label"><b>쪽 번호 + 파일 이름</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="2" data-template-id="10"><span class="md-label"><b>파일 이름 + 쪽 번호</b></span></div>
                </div>
              </div>
              <!-- 짝수 쪽 -->
              <div class="md-sub">
                <span class="md-label">짝수 쪽</span><span class="md-arrow">▶</span>
                <div class="md-sub-panel md-hf-template-panel">
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="1" data-template-id="0"><span class="md-label">빈 머리말</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="1" data-template-id="1"><span class="md-label">왼쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="1" data-template-id="2"><span class="md-label">가운데 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="1" data-template-id="3"><span class="md-label">오른쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="1" data-template-id="4"><span class="md-label">쪽 번호 + 파일 이름</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="1" data-template-id="5"><span class="md-label">파일 이름 + 쪽 번호</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="1" data-template-id="6"><span class="md-label"><b>왼쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="1" data-template-id="7"><span class="md-label"><b>가운데 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="1" data-template-id="8"><span class="md-label"><b>오른쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="1" data-template-id="9"><span class="md-label"><b>쪽 번호 + 파일 이름</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="true" data-apply-to="1" data-template-id="10"><span class="md-label"><b>파일 이름 + 쪽 번호</b></span></div>
                </div>
              </div>
              <div class="md-sep"></div>
              <div class="md-item" data-cmd="page:header-create"><span class="md-icon"></span><span class="md-label">머리말 편집...</span></div>
            </div>
          </div>
          <!-- ── 꼬리말 (부모 서브메뉴) ── -->
          <div class="md-sub disabled">
            <span class="md-icon icon-footer"></span><span class="md-label">꼬리말</span><span class="md-arrow">▶</span>
            <div class="md-sub-panel">
              <!-- 양쪽 -->
              <div class="md-sub">
                <span class="md-label">양쪽</span><span class="md-arrow">▶</span>
                <div class="md-sub-panel md-hf-template-panel">
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="0" data-template-id="0"><span class="md-label">빈 꼬리말</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="0" data-template-id="1"><span class="md-label">왼쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="0" data-template-id="2"><span class="md-label">가운데 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="0" data-template-id="3"><span class="md-label">오른쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="0" data-template-id="4"><span class="md-label">쪽 번호 + 파일 이름</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="0" data-template-id="5"><span class="md-label">파일 이름 + 쪽 번호</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="0" data-template-id="6"><span class="md-label"><b>왼쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="0" data-template-id="7"><span class="md-label"><b>가운데 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="0" data-template-id="8"><span class="md-label"><b>오른쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="0" data-template-id="9"><span class="md-label"><b>쪽 번호 + 파일 이름</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="0" data-template-id="10"><span class="md-label"><b>파일 이름 + 쪽 번호</b></span></div>
                </div>
              </div>
              <!-- 홀수 쪽 -->
              <div class="md-sub">
                <span class="md-label">홀수 쪽</span><span class="md-arrow">▶</span>
                <div class="md-sub-panel md-hf-template-panel">
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="2" data-template-id="0"><span class="md-label">빈 꼬리말</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="2" data-template-id="1"><span class="md-label">왼쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="2" data-template-id="2"><span class="md-label">가운데 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="2" data-template-id="3"><span class="md-label">오른쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="2" data-template-id="4"><span class="md-label">쪽 번호 + 파일 이름</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="2" data-template-id="5"><span class="md-label">파일 이름 + 쪽 번호</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="2" data-template-id="6"><span class="md-label"><b>왼쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="2" data-template-id="7"><span class="md-label"><b>가운데 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="2" data-template-id="8"><span class="md-label"><b>오른쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="2" data-template-id="9"><span class="md-label"><b>쪽 번호 + 파일 이름</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="2" data-template-id="10"><span class="md-label"><b>파일 이름 + 쪽 번호</b></span></div>
                </div>
              </div>
              <!-- 짝수 쪽 -->
              <div class="md-sub">
                <span class="md-label">짝수 쪽</span><span class="md-arrow">▶</span>
                <div class="md-sub-panel md-hf-template-panel">
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="1" data-template-id="0"><span class="md-label">빈 꼬리말</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="1" data-template-id="1"><span class="md-label">왼쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="1" data-template-id="2"><span class="md-label">가운데 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="1" data-template-id="3"><span class="md-label">오른쪽 쪽 번호</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="1" data-template-id="4"><span class="md-label">쪽 번호 + 파일 이름</span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="1" data-template-id="5"><span class="md-label">파일 이름 + 쪽 번호</span></div>
                  <div class="md-sep"></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="1" data-template-id="6"><span class="md-label"><b>왼쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="1" data-template-id="7"><span class="md-label"><b>가운데 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="1" data-template-id="8"><span class="md-label"><b>오른쪽 쪽 번호</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="1" data-template-id="9"><span class="md-label"><b>쪽 번호 + 파일 이름</b></span></div>
                  <div class="md-item" data-cmd="page:apply-hf-template" data-is-header="false" data-apply-to="1" data-template-id="10"><span class="md-label"><b>파일 이름 + 쪽 번호</b></span></div>
                </div>
              </div>
              <div class="md-sep"></div>
              <div class="md-item" data-cmd="page:footer-create"><span class="md-icon"></span><span class="md-label">꼬리말 편집...</span></div>
            </div>
          </div>
          <div class="md-item disabled" data-cmd="page:new-page-num"><span class="md-icon"></span><span class="md-label">새 번호로 시작</span></div>
          <div class="md-item disabled" data-cmd="page:hide-current"><span class="md-icon"></span><span class="md-label">현재 쪽만 감추기</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="page:break"><span class="md-icon"></span><span class="md-label">쪽 나누기</span><span class="md-shortcut">Ctrl+Enter</span></div>
          <div class="md-item disabled" data-cmd="page:column-break"><span class="md-icon"></span><span class="md-label">단 나누기</span><span class="md-shortcut">Ctrl+Shift+Enter</span></div>
          <div class="md-sep"></div>
          <div class="md-sub">
            <span class="md-label">단</span><span class="md-arrow">▶</span>
            <div class="md-sub-panel">
              <div class="md-item" data-cmd="page:col-1"><span class="md-icon"></span><span class="md-label">하나</span></div>
              <div class="md-item" data-cmd="page:col-2"><span class="md-icon"></span><span class="md-label">둘</span></div>
              <div class="md-item" data-cmd="page:col-3"><span class="md-icon"></span><span class="md-label">셋</span></div>
              <div class="md-item" data-cmd="page:col-left"><span class="md-icon"></span><span class="md-label">왼쪽</span></div>
              <div class="md-item" data-cmd="page:col-right"><span class="md-icon"></span><span class="md-label">오른쪽</span></div>
              <div class="md-sep"></div>
              <div class="md-item disabled" data-cmd="page:col-settings"><span class="md-icon"></span><span class="md-label">다단 설정</span><span class="md-shortcut">Ctrl+Alt+Enter</span></div>
            </div>
          </div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="page:section-settings"><span class="md-icon"></span><span class="md-label">구역 설정</span></div>
        </div>
      </div>
      <!-- ── 표 ── -->
      <div class="menu-item" data-menu="table">
        <span class="menu-title">표</span>
        <div class="menu-dropdown">
          <div class="md-item disabled" data-cmd="table:create"><span class="md-icon icon-table"></span><span class="md-label">표 만들기</span></div>
          <div class="md-item disabled" data-cmd="table:cell-props"><span class="md-icon"></span><span class="md-label">표/셀 속성</span></div>
          <div class="md-sep"></div>
          <div class="md-sub disabled">
            <span class="md-label">셀 테두리/배경</span><span class="md-arrow">▶</span>
            <div class="md-sub-panel">
              <div class="md-item disabled" data-cmd="table:border-each"><span class="md-icon"></span><span class="md-label">각 셀마다 적용</span></div>
              <div class="md-item disabled" data-cmd="table:border-one"><span class="md-icon"></span><span class="md-label">하나의 셀처럼 적용</span></div>
            </div>
          </div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="table:insert-row-above"><span class="md-icon"></span><span class="md-label">위쪽에 줄 추가하기</span></div>
          <div class="md-item disabled" data-cmd="table:insert-row-below"><span class="md-icon"></span><span class="md-label">아래쪽에 줄 추가하기</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="table:insert-col-left"><span class="md-icon"></span><span class="md-label">왼쪽에 칸 추가하기</span><span class="md-shortcut">Alt+Insert</span></div>
          <div class="md-item disabled" data-cmd="table:insert-col-right"><span class="md-icon"></span><span class="md-label">오른쪽에 칸 추가하기</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="table:delete-row"><span class="md-icon"></span><span class="md-label">줄 지우기</span></div>
          <div class="md-item disabled" data-cmd="table:delete-col"><span class="md-icon"></span><span class="md-label">칸 지우기</span><span class="md-shortcut">Alt+Delete</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="table:cell-split"><span class="md-icon"></span><span class="md-label">셀 나누기</span><span class="md-shortcut">S</span></div>
          <div class="md-item disabled" data-cmd="table:cell-merge"><span class="md-icon"></span><span class="md-label">셀 합치기</span><span class="md-shortcut">M</span></div>
          <div class="md-item disabled" data-cmd="table:cell-height-equal"><span class="md-icon"></span><span class="md-label">셀 높이를 같게</span><span class="md-shortcut">H</span></div>
          <div class="md-item disabled" data-cmd="table:cell-width-equal"><span class="md-icon"></span><span class="md-label">셀 너비를 같게</span><span class="md-shortcut">W</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="table:block-formula"><span class="md-icon"></span><span class="md-label">블록 계산식</span></div>
          <div class="md-item disabled" data-cmd="table:block-sum"><span class="md-icon"></span><span class="md-label">블록 합계</span><span class="md-shortcut">Ctrl+Shift+S</span></div>
          <div class="md-item disabled" data-cmd="table:block-avg"><span class="md-icon"></span><span class="md-label">블록 평균</span><span class="md-shortcut">Ctrl+Shift+A</span></div>
          <div class="md-item disabled" data-cmd="table:block-product"><span class="md-icon"></span><span class="md-label">블록 곱</span><span class="md-shortcut">Ctrl+Shift+P</span></div>
          <div class="md-item disabled" data-cmd="table:thousand-sep"><span class="md-icon"></span><span class="md-label">1,000 단위 구분 쉼표</span></div>
          <div class="md-item disabled" data-cmd="table:decimal-add"><span class="md-icon"></span><span class="md-label">자릿점 넣기</span></div>
          <div class="md-item disabled" data-cmd="table:decimal-remove"><span class="md-icon"></span><span class="md-label">자릿점 빼기</span></div>
        </div>
      </div>
      <!-- ── 도구 ── -->
      <div class="menu-item" data-menu="tool">
        <span class="menu-title">도구</span>
        <div class="menu-dropdown">
          <div class="md-item" data-cmd="tool:options"><span class="md-icon"></span><span class="md-label">환경 설정</span></div>
        </div>
      </div>
    </div>

    <!-- 아이콘 툴바 -->
    <div id="icon-toolbar">
      <div class="tb-group">
        <button class="tb-btn" title="오려두기 (Ctrl+X)"><span class="tb-sprite icon-cut"></span><span class="tb-label">오려<br/>두기</span></button>
        <button class="tb-btn" title="복사하기 (Ctrl+C)"><span class="tb-sprite icon-copy"></span><span class="tb-label">복사<br/>하기</span></button>
        <button class="tb-btn tb-paste" title="붙이기 (Ctrl+V)">
          <span class="tb-sprite icon-paste"></span><span class="tb-label">붙이기</span>
        </button>
        <button class="tb-btn" title="모양 복사"><span class="tb-sprite icon-format-copy"></span><span class="tb-label">모양<br/>복사</span></button>
      </div>
      <span class="tb-sep"></span>
      <div class="tb-group">
        <button class="tb-btn" data-cmd="view:ctrl-mark" title="조판 부호"><span class="tb-sprite icon-ctrl-mark"></span><span class="tb-label">조판<br/>부호</span></button>
        <button class="tb-btn" data-cmd="view:para-mark" title="문단 부호"><span class="tb-sprite icon-para-mark"></span><span class="tb-label">문단<br/>부호</span></button>
        <button class="tb-btn" title="격자 보기"><span class="tb-sprite icon-grid"></span><span class="tb-label">격자<br/>보기</span></button>
      </div>
      <span class="tb-sep"></span>
      <div class="tb-group">
        <button class="tb-btn" id="tb-char-format" data-cmd="format:char-shape" title="글자 모양"><span class="tb-sprite icon-char-shape"></span><span class="tb-label">글자<br/>모양</span></button>
        <button class="tb-btn" data-cmd="format:para-shape" title="문단 모양"><span class="tb-sprite icon-para-shape"></span><span class="tb-label">문단<br/>모양</span></button>
      </div>
      <span class="tb-sep"></span>
      <div class="tb-group">
        <button class="tb-btn" id="tb-numbering" data-cmd="format:toggle-numbering" title="문단 번호"><span class="tb-icon-text">1.</span><span class="tb-label">문단<br/>번호</span></button>
        <button class="tb-btn" id="tb-bullet" title="글머리표"><span class="tb-icon-text">●</span><span class="tb-label">글머리<br/>표</span></button>
        <button class="tb-btn" id="tb-level-up" data-cmd="format:level-increase" title="한 수준 증가"><span class="tb-icon-text">⇤</span><span class="tb-label">수준▲</span></button>
        <button class="tb-btn" id="tb-level-down" data-cmd="format:level-decrease" title="한 수준 감소"><span class="tb-icon-text">⇥</span><span class="tb-label">수준▼</span></button>
      </div>
      <span class="tb-sep"></span>
      <div class="tb-group">
        <button class="tb-btn" data-cmd="table:create" title="표"><span class="tb-sprite icon-table"></span><span class="tb-label">표 ▾</span></button>
        <button class="tb-btn" id="tb-shape" data-cmd="insert:shape" title="도형"><span class="tb-sprite icon-shape"></span><span class="tb-label">도형 ▾</span></button>
        <button class="tb-btn" data-cmd="insert:image" title="그림"><span class="tb-sprite icon-image"></span><span class="tb-label">그림</span></button>
      </div>
      <span class="tb-sep"></span>
      <div class="tb-group">
        <button class="tb-btn" title="개체 속성 (P)" data-cmd="format:object-properties"><span class="tb-sprite icon-obj-props"></span><span class="tb-label">개체<br/>속성</span></button>
        <button class="tb-btn" title="문자표 (Alt+F10)" data-cmd="insert:symbols"><span class="tb-sprite icon-symbols"></span><span class="tb-label">문자표</span></button>
        <button class="tb-btn" title="하이퍼링크"><span class="tb-sprite icon-hyperlink"></span><span class="tb-label">하이퍼<br/>링크</span></button>
      </div>
      <span class="tb-sep"></span>
      <div class="tb-group tb-rotate-group" style="display:none">
        <button class="tb-btn" data-cmd="insert:rotate-ccw" title="왼쪽 90° 회전"><span class="tb-icon-text">&#x21B6;</span><span class="tb-label">왼쪽<br/>회전</span></button>
        <button class="tb-btn" data-cmd="insert:rotate-cw" title="오른쪽 90° 회전"><span class="tb-icon-text">&#x21B7;</span><span class="tb-label">오른쪽<br/>회전</span></button>
        <button class="tb-btn" data-cmd="insert:flip-horz" title="좌우 대칭"><span class="tb-icon-text">&#x21C4;</span><span class="tb-label">좌우<br/>대칭</span></button>
        <button class="tb-btn" data-cmd="insert:flip-vert" title="상하 대칭"><span class="tb-icon-text">&#x21C5;</span><span class="tb-label">상하<br/>대칭</span></button>
      </div>
      <span class="tb-sep"></span>
      <div class="tb-group">
        <button class="tb-btn" data-cmd="page:header-create" title="머리말"><span class="tb-sprite icon-header"></span><span class="tb-label">머리말</span></button>
        <button class="tb-btn" data-cmd="page:footer-create" title="꼬리말"><span class="tb-sprite icon-footer"></span><span class="tb-label">꼬리말</span></button>
        <button class="tb-btn" data-cmd="insert:footnote" title="각주"><span class="tb-sprite icon-footnote"></span><span class="tb-label">각주</span></button>
        <button class="tb-btn" title="미주"><span class="tb-sprite icon-endnote"></span><span class="tb-label">미주</span></button>
        <div class="tb-split">
          <button class="tb-btn tb-split-main" title="찾기 (Ctrl+F)" data-cmd="edit:find"><span class="tb-sprite icon-find"></span><span class="tb-label">찾기</span></button>
          <button class="tb-btn tb-split-arrow" title="찾기 메뉴"><span class="tb-arrow-icon">▾</span></button>
          <div class="tb-split-menu">
            <div class="tb-split-item" data-cmd="edit:find">찾기(F) <span class="tb-split-shortcut">Ctrl+F</span></div>
            <div class="tb-split-item" data-cmd="edit:find-replace">찾아 바꾸기(E) <span class="tb-split-shortcut">Ctrl+F2</span></div>
            <div class="tb-split-item" data-cmd="edit:find-again">다시 찾기(X) <span class="tb-split-shortcut">Ctrl+L</span></div>
            <div class="tb-split-sep"></div>
            <div class="tb-split-item" data-cmd="edit:goto">찾아가기(G) <span class="tb-split-shortcut">Alt+G</span></div>
          </div>
        </div>
      </div>
      <!-- 머리말/꼬리말 편집 모드 전용 도구상자 (숨김) -->
      <div class="tb-group tb-headerfooter-group" style="display:none">
        <span class="tb-hf-label">머리말</span>
        <span class="tb-sep"></span>
        <button class="tb-btn" data-cmd="page:headerfooter-prev" title="이전"><span class="tb-icon-text">◀</span><span class="tb-label">이전</span></button>
        <button class="tb-btn" data-cmd="page:headerfooter-next" title="다음"><span class="tb-icon-text">▶</span><span class="tb-label">다음</span></button>
        <span class="tb-sep"></span>
        <button class="tb-btn" data-cmd="page:headerfooter-close" title="닫기"><span class="tb-icon-text">✕</span><span class="tb-label">닫기</span></button>
        <button class="tb-btn tb-hf-delete" data-cmd="page:headerfooter-delete" title="지우기"><span class="tb-icon-text">🗑</span><span class="tb-label">지우기</span></button>
        <span class="tb-sep"></span>
        <button class="tb-btn" data-cmd="page:insert-field-pagenum" title="쪽 번호 삽입"><span class="tb-icon-text">#</span><span class="tb-label">쪽번호</span></button>
        <button class="tb-btn" data-cmd="page:insert-field-totalpage" title="총 쪽수 삽입"><span class="tb-icon-text">##</span><span class="tb-label">총쪽수</span></button>
        <button class="tb-btn" data-cmd="page:insert-field-filename" title="파일 이름 삽입"><span class="tb-icon-text">F</span><span class="tb-label">파일명</span></button>
      </div>
      <!-- 파일 열기 (숨김) -->
      <input type="file" id="file-input" accept=".hwp,.hwpx" style="display:none" />
    </div>

    <!-- 서식 도구 모음 (style bar) -->
    <div id="style-bar">
      <!-- 스타일 -->
      <select id="style-name" class="sb-combo" title="스타일">
      </select>
      <!-- 글꼴 언어 카테고리 -->
      <select id="font-lang" class="sb-combo sb-font-lang" title="글꼴 적용 언어">
        <option value="all">대표</option>
        <option value="0">한글</option>
        <option value="1">영문</option>
        <option value="2">한자</option>
        <option value="3">일어</option>
        <option value="4">외국어</option>
        <option value="5">기호</option>
        <option value="6">사용자</option>
      </select>
      <!-- 글꼴 -->
      <select id="font-name" class="sb-combo sb-font" title="글꼴">
        <option value="함초롬바탕">함초롬바탕</option>
        <option value="함초롬돋움">함초롬돋움</option>
        <option value="맑은 고딕">맑은 고딕</option>
        <option value="나눔고딕">나눔고딕</option>
        <option value="바탕">바탕</option>
        <option value="돋움">돋움</option>
        <option value="궁서">궁서</option>
      </select>
      <!-- 글자 크기 -->
      <span class="sb-size-group">
        <input id="font-size" type="text" class="sb-size" title="글자 크기 (pt)" value="10.0" />
        <span class="sb-size-unit">pt</span>
        <span class="sb-size-arrows">
          <button id="btn-size-up" class="sb-arrow" title="크기 크게">▲</button>
          <button id="btn-size-down" class="sb-arrow" title="크기 작게">▼</button>
        </span>
      </span>
      <span class="sb-sep"></span>
      <!-- 글자 서식: 가 -->
      <button id="btn-bold" class="sb-btn" title="굵게 (Ctrl+B)"><span class="sb-ga sb-bold">가</span></button>
      <button id="btn-italic" class="sb-btn" title="기울임 (Ctrl+I)"><span class="sb-ga sb-italic">가</span></button>
      <button id="btn-underline" class="sb-btn" title="밑줄 (Ctrl+U)"><span class="sb-ga sb-underline">간</span></button>
      <button id="btn-strike" class="sb-btn sb-has-arrow" title="취소선"><span class="sb-ga sb-strike">가</span><span class="sb-dd">▾</span></button>
      <div class="sb-dropdown" id="charfx-dropdown">
        <button class="sb-btn sb-has-arrow" id="btn-charfx" title="글자 효과">
          <span class="sb-ga" id="charfx-icon">가</span><span class="sb-dd">▾</span>
        </button>
        <div class="sb-dropdown-menu" id="charfx-menu">
          <button class="sb-dropdown-item" data-format="emboss"><span class="sb-ga sb-emboss">가</span>양각</button>
          <button class="sb-dropdown-item" data-format="engrave"><span class="sb-ga sb-engrave">가</span>음각</button>
          <button class="sb-dropdown-item" data-format="outline"><span class="sb-ga sb-outline">가</span>외곽선</button>
          <button class="sb-dropdown-item" data-format="superscript"><span class="sb-ga sb-sup">가</span>위 첨자</button>
          <button class="sb-dropdown-item" data-format="subscript"><span class="sb-ga sb-sub">가</span>아래 첨자</button>
        </div>
      </div>
      <!-- 글자색 -->
      <span class="sb-color-wrap">
        <button id="btn-text-color" class="sb-btn sb-has-arrow" title="글자 색">
          <span class="sb-ga">간</span><span id="color-bar"></span><span class="sb-dd">▾</span>
        </button>
        <input id="text-color-picker" type="color" value="#000000" />
      </span>
      <span class="sb-sep"></span>
      <!-- 형광펜 -->
      <span id="highlight-dropdown" class="sb-dropdown">
        <button id="btn-highlight" class="sb-btn sb-has-arrow" title="형광펜">
          <span class="sb-highlight-icon">✏</span><span id="highlight-bar"></span><span class="sb-dd">▾</span>
        </button>
        <div id="highlight-palette" class="sb-hl-palette"></div>
      </span>
      <span class="sb-sep"></span>
      <!-- 문단 정렬 -->
      <button id="btn-align-left" class="sb-btn" title="왼쪽 정렬"><span class="sb-align sb-al-left"></span></button>
      <button id="btn-align-center" class="sb-btn" title="가운데 정렬"><span class="sb-align sb-al-center"></span></button>
      <button id="btn-align-right" class="sb-btn" title="오른쪽 정렬"><span class="sb-align sb-al-right"></span></button>
      <button id="btn-align-justify" class="sb-btn" title="양쪽 정렬"><span class="sb-align sb-al-justify"></span></button>
      <button id="btn-align-distribute" class="sb-btn" title="배분 정렬"><span class="sb-align sb-al-distribute"></span></button>
      <button id="btn-align-split" class="sb-btn" title="나눔 정렬"><span class="sb-align sb-al-split"></span></button>
      <span class="sb-sep"></span>
      <!-- 줄 간격 -->
      <span class="sb-ls-group" id="linespacing-group">
        <select id="linespacing-select" class="sb-ls-select" title="줄 간격">
          <option value="100">100 %</option>
          <option value="130">130 %</option>
          <option value="160" selected>160 %</option>
          <option value="180">180 %</option>
          <option value="200">200 %</option>
          <option value="300">300 %</option>
        </select>
        <span class="sb-ls-arrows">
          <button id="btn-ls-up" class="sb-arrow" title="줄 간격 증가 (+5%)">▲</button>
          <button id="btn-ls-down" class="sb-arrow" title="줄 간격 감소 (-5%)">▼</button>
        </span>
      </span>
    </div>

    <!-- 에디터 영역 (눈금자 포함) -->
    <div id="editor-area">
      <div id="ruler-corner"></div>
      <canvas id="h-ruler"></canvas>
      <canvas id="v-ruler"></canvas>
      <div id="scroll-container">
        <div id="scroll-content"></div>
      </div>
    </div>

    <!-- 하단 상태 바 -->
    <div id="status-bar">
      <span id="sb-page" class="stb-item">1 / 1 쪽</span>
      <span class="stb-divider"></span>
      <span id="sb-section" class="stb-item">구역: 1 / 1</span>
      <span class="stb-divider"></span>
      <span id="sb-mode" class="stb-item">삽입</span>
      <span id="sb-field" class="stb-item" style="display:none"></span>
      <span id="sb-message" class="stb-message"></span>
      <span id="rhwp-editor-version" class="stb-item" style="font-size:10px;color:#888;"></span>
        <span class="stb-right">
        <button id="sb-zoom-fit-width" class="stb-icon-btn" title="폭 맞춤"><span class="tb-sprite icon-zoom-fit-width"></span></button>
        <button id="sb-zoom-fit" class="stb-icon-btn" title="쪽 맞춤"><span class="tb-sprite icon-zoom-fit"></span></button>
        <span class="stb-divider"></span>
        <span id="sb-zoom-val" class="stb-zoom-val">100%</span>
        <button id="sb-zoom-out" class="stb-icon-btn" title="축소"><span class="tb-sprite icon-zoom-out"></span></button>
        <button id="sb-zoom-in" class="stb-icon-btn" title="확대"><span class="tb-sprite icon-zoom-in"></span></button>
      </span>
    </div>
  </div>
<script>
// FileStation 연동: 파일 자동 로드
(function() {
    const STREAM_URL = '<?php echo rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/"); ?>/rhwp_editor.php?action=stream&storage_id=<?php echo $storageId; ?>&path=<?php echo rawurlencode($filePath); ?>';
    const FILE_NAME = <?php echo json_encode($fileName); ?>;
    
    if (!FILE_NAME) return; // 파일 경로 없으면 빈 에디터
    
    function waitForInput() {
        return new Promise(resolve => {
            const check = () => {
                const el = document.getElementById('file-input');
                if (el) return resolve(el);
                setTimeout(check, 100);
            };
            check();
        });
    }
    
    // WASM 초기화 완료 대기: 콘솔 로그 감지 플래그 사용
    function waitForWasmReady() {
        return new Promise(resolve => {
            let attempts = 0;
            const maxAttempts = 300; // 최대 30초
            const check = () => {
                attempts++;
                // 1순위: 콘솔 로그에서 감지한 플래그 (가장 정확)
                if (window.__rhwpWasmReady) {
                    resolve(true);
                    return;
                }
                // 2순위: DOM 기반 fallback
                const scrollContent = document.getElementById('scroll-content');
                if (scrollContent && scrollContent.querySelector('canvas')) {
                    resolve(true);
                    return;
                }
                if (attempts >= maxAttempts) {
                    resolve(false);
                    return;
                }
                setTimeout(check, 100);
            };
            check();
        });
    }
    
    // 파일 주입 함수 (재시도 가능)
    async function injectFile(fileInput, retryCount) {
        // console.log(`[rhwp_editor] 파일 로드 시작 (시도 ${retryCount + 1}):`, FILE_NAME);
        const resp = await fetch(STREAM_URL);
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const blob = await resp.blob();
        const ext = FILE_NAME.split('.').pop().toLowerCase();
        const mime = ext === 'hwpx' ? 'application/hwp+zip' : 'application/x-hwp';
        const file = new File([blob], FILE_NAME, { type: mime });
        const dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        // console.log('[rhwp_editor] 파일 주입 완료:', FILE_NAME, blob.size, 'bytes');
    }
    
    // 파일 주입 성공 확인 (문서가 실제로 렌더링되었는지)
    function checkFileLoaded() {
        return new Promise(resolve => {
            let checks = 0;
            const maxChecks = 50; // 최대 5초+500ms
            window.__rhwpDocLoaded = false; // 재시도 시 리셋
            const check = () => {
                checks++;
                // 1순위: 콘솔 감지 플래그 (가장 정확)
                if (window.__rhwpDocLoaded) {
                    resolve(true);
                    return;
                }
                // 2순위: scroll-content 안에 canvas가 있고 크기가 있으면 문서 렌더링 완료
                const scrollContent = document.getElementById('scroll-content');
                if (scrollContent) {
                    const canvases = scrollContent.querySelectorAll('canvas');
                    if (canvases.length > 0 && canvases[0].width > 100) {
                        resolve(true);
                        return;
                    }
                }
                // 3순위: 상태바 페이지 정보
                const pageInfo = document.getElementById('sb-page');
                if (pageInfo && pageInfo.textContent && /\d+\s*\/\s*[1-9]/.test(pageInfo.textContent)) {
                    resolve(true);
                    return;
                }
                if (checks >= maxChecks) {
                    resolve(false);
                    return;
                }
                setTimeout(check, 100);
            };
            setTimeout(check, 500);
        });
    }
    
    window.addEventListener('DOMContentLoaded', async () => {
        // HWPX도 rhwp 0.7.3에서 뷰잉은 정상 지원됨 (저장만 차단)
        // 재시도 횟수는 HWP와 동일하게 적용
        const maxRetries = 4;
        
        try {
            const fileInput = await waitForInput();
            
            const wasmOk = await waitForWasmReady();
            if (!wasmOk) {
                // WASM 타임아웃이어도 시도는 함
            }
            // WASM 초기화 후 내부 이벤트 바인딩 안정화 대기
            await new Promise(r => setTimeout(r, 500));
            
            // 1차 시도
            await injectFile(fileInput, 0);
            let loaded = await checkFileLoaded();
            
            if (!loaded) {
                // 로딩 오버레이 표시
                const loadingDiv = document.createElement('div');
                loadingDiv.id = 'rhwp-loading-overlay';
                loadingDiv.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.85);display:flex;align-items:center;justify-content:center;z-index:99999;flex-direction:column;gap:12px;';
                loadingDiv.innerHTML = '<div style="font-size:32px;">📄</div><div id="rhwp-loading-msg" style="font-size:14px;color:#666;">문서 로딩 중...</div>';
                document.body.appendChild(loadingDiv);
                
                // 재시도 (2~4차) — 간격을 점점 늘림
                for (let retry = 1; retry < maxRetries; retry++) {
                    const msgEl = document.getElementById('rhwp-loading-msg');
                    if (msgEl) msgEl.textContent = `문서 로딩 중... (${retry + 1}/${maxRetries})`;
                    await new Promise(r => setTimeout(r, 1000 + retry * 500));
                    try {
                        await injectFile(fileInput, retry);
                        loaded = await checkFileLoaded();
                        if (loaded) break;
                    } catch (e) {
                        // 재시도 실패
                    }
                }
                
                loadingDiv.remove();
            }
        } catch (e) {
            // 파일 로드 실패
            console.error('[rhwp_editor] 파일 로드 예외:', e);
        }
    });
})();
</script>
<script>
// FileStation 서버 저장: Ctrl+S → WASM exportHwp() 직접 호출 → POST 업로드
(function() {
    const SAVE_URL = '<?php echo rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/"); ?>/rhwp_editor.php?action=save&storage_id=<?php echo $storageId; ?>&path=<?php echo rawurlencode($filePath); ?>';
    const FILE_NAME = <?php echo json_encode($fileName); ?>;
    const HAS_FILE = !!(<?php echo $storageId; ?>) && !!FILE_NAME;
    
    if (!HAS_FILE) {
        // 파일 컨텍스트가 없어도 Ctrl+S 무한 대기 방지를 위해 no-op 핸들러 등록
        // (early keydown 핸들러가 5초 재시도 타임아웃 돌지 않게)
        window.__rhwpHandleSave = function() { /* no-op: 저장할 파일 없음 */ };
        return;
    }
    
    let saving = false;
    
    async function serverSave(hwpData) {
        const resp = await fetch(SAVE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/octet-stream' },
            body: hwpData
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const result = await resp.json();
        if (!result.success) throw new Error(result.error || '저장 실패');
        return result;
    }
    
    // rhwp-studio WasmBridge 인스턴스 찾기
    function findWasmBridge() {
        // rhwp-studio의 전역 앱 인스턴스에서 wasm bridge 탐색
        // 방법 1: window.__rhwpApp 또는 비슷한 전역 변수
        if (window.__rhwpApp && window.__rhwpApp.wasm) return window.__rhwpApp.wasm;
        
        // 방법 2: Blob 패치로 캡처 (fallback)
        return null;
    }
    
    // Blob 생성자 패치: application/x-hwp Blob 데이터만 캡처 (HWP 저장 전용)
    // HWPX는 서버 저장 미지원이므로 hwp+zip은 캡처 안 함 (데이터 손상 위험)
    let lastExportData = null;
    const OrigBlob = window.Blob;
    window.Blob = function(parts, options) {
        const blob = new OrigBlob(parts, options);
        if (options && options.type === 'application/x-hwp' && parts && parts.length > 0) {
            const data = parts[0];
            if (data instanceof Uint8Array && data.length > 16) {
                lastExportData = new Uint8Array(data);
            }
        }
        return blob;
    };
    window.Blob.prototype = OrigBlob.prototype;
    Object.setPrototypeOf(window.Blob, OrigBlob);
    
    async function exportAndSave() {
        if (saving) return; // 이미 저장 중이면 skip (중복 저장 방지)
        saving = true;
        lastExportData = null;
        if (window.__rhwpSyncSuspend) window.__rhwpSyncSuspend();
        
        const statusEl = document.getElementById('sb-message');
        if (statusEl) statusEl.textContent = '서버에 저장 중...';
        // console.log('[rhwp_editor] exportAndSave 시작');
        
        // 프로토타입 훅은 try 밖에서 선언 + finally에서 반드시 복원
        // (try 내부에서 예외 발생 시 프로토타입 영구 오염 방지)
        const origClick = HTMLAnchorElement.prototype.click;
        const origPicker = window.showSaveFilePicker;
        const hadPicker = 'showSaveFilePicker' in window;
        
        try {
            // <a>.click() 가로채서 로컬 다운로드 차단
            HTMLAnchorElement.prototype.click = function() {
                if (this.download && this.href && this.href.startsWith('blob:')) {
                    // console.log('[rhwp_editor] <a>.click() 로컬 다운로드 차단:', this.download, this.href.substring(0, 50));
                    setTimeout(() => URL.revokeObjectURL(this.href), 100);
                    return;
                }
                return origClick.call(this);
            };
            
            // showSaveFilePicker 임시 차단 (origPicker는 try 밖에서 이미 선언됨)
            window.showSaveFilePicker = async function() {
                // console.log('[rhwp_editor] showSaveFilePicker 차단');
                throw new DOMException('Blocked for server save', 'AbortError');
            };
            
            // file:save 메뉴를 내부적으로 트리거 (capture 가로채기 우회)
            const saveItem = document.querySelector('.md-item[data-cmd="file:save"]');
            // console.log('[rhwp_editor] file:save 메뉴 요소:', saveItem ? 'found' : 'NOT FOUND', 
                        // saveItem ? 'disabled=' + saveItem.classList.contains('disabled') : '');
            if (saveItem) {
                // ⚠️ 중요: studio가 canExecute에서 hasDocument를 확인해도 우리 커스텀 HTML 메뉴는
                // studio의 상태 관리와 연동되지 않아 항상 'disabled' 클래스로 남음.
                // studio click 핸들러는 disabled 클래스를 감지하면 execute를 호출하지 않으므로
                // click 직전에만 임시로 disabled를 제거하고, 직후 원복한다.
                const wasDisabled = saveItem.classList.contains('disabled');
                if (wasDisabled) {
                    // console.log('[rhwp_editor] file:save disabled 임시 제거');
                    saveItem.classList.remove('disabled');
                }
                // console.log('[rhwp_editor] file:save 메뉴 click() 호출');
                window.__rhwpServerSaveTrigger = true;
                try {
                    saveItem.click();
                } finally {
                    // click은 동기적으로 리스너 모두 실행 후 반환됨
                    setTimeout(() => { 
                        window.__rhwpServerSaveTrigger = false; 
                        if (wasDisabled) {
                            saveItem.classList.add('disabled');
                            // console.log('[rhwp_editor] file:save disabled 원복');
                        }
                    }, 0);
                }
            } else {
                // console.warn('[rhwp_editor] .md-item[data-cmd="file:save"] 요소를 찾을 수 없음');
            }
            
            // Blob 캡처 대기 (최대 2초)
            // console.log('[rhwp_editor] Blob 캡처 대기 시작');
            for (let wait = 0; wait < 20; wait++) {
                await new Promise(r => setTimeout(r, 100));
                if (lastExportData && lastExportData.length > 16) {
                    // console.log('[rhwp_editor] Blob 캡처 성공 (' + (wait*100) + 'ms):', lastExportData.length, 'bytes');
                    break;
                }
            }
            
            // 참고: rhwp 0.7.3+ 는 canExecute에서 HWPX 저장을 차단하지만
            // 빌드 시 studio JS 패치로 canExecute 우회 → execute() 정상 실행됨
            
            if (lastExportData && lastExportData.length > 16) {
                // console.log('[rhwp_editor] serverSave 호출, 크기:', lastExportData.length);
                const result = await serverSave(lastExportData);
                const sizeKB = (result.size / 1024).toFixed(1);
                if (statusEl) statusEl.textContent = FILE_NAME + ' 서버 저장 완료 (' + sizeKB + 'KB)';
                // console.log('[rhwp_editor] 서버 저장 완료:', FILE_NAME, result.size, 'bytes');
                // 부모 창(웹하드)에 파일 변경 알림 (목록 갱신용)
                notifyParentFileChanged(FILE_NAME, 'save');
            } else {
                console.error('[rhwp_editor] Blob 캡처 실패 - lastExportData:', lastExportData);
                throw new Error('exportHwp 데이터 캡처 실패 (Blob 미생성)');
            }
        } catch (e) {
            console.error('[rhwp_editor] 서버 저장 실패:', e);
            if (statusEl) statusEl.textContent = '저장 실패: ' + e.message;
        } finally {
            // 프로토타입/전역 함수 반드시 복원 (try 중 예외 발생해도 실행됨)
            HTMLAnchorElement.prototype.click = origClick;
            if (hadPicker) {
                window.showSaveFilePicker = origPicker;
            } else {
                try { delete window.showSaveFilePicker; } catch (_) { window.showSaveFilePicker = undefined; }
            }
            saving = false;
            lastExportData = null;
            if (window.__rhwpSyncResume) window.__rhwpSyncResume();
        }
    }
    
    // 부모 창(웹하드)에 파일 변경 사실을 알려 자동 갱신 유도
    // 에디터는 window.open으로 열리므로 window.opener로 접근 가능
    // - save: 기존 파일 덮어쓰기 (크기/수정시간 변경)
    // - save-as: 새 파일 생성
    // - save-as-overwrite: 기존 파일을 새 이름으로 덮어쓰기
    function notifyParentFileChanged(fileName, action) {
        try {
            const opener = window.opener;
            if (!opener || opener.closed) {
                // console.log('[rhwp_editor] opener 없음 - 갱신 알림 skip');
                return;
            }
            const message = {
                type: 'rhwp-file-changed',
                action: action,           // 'save' | 'save-as' | 'save-as-overwrite'
                fileName: fileName,
                storageId: <?php echo (int)$storageId; ?>,
                // 저장된 파일이 속한 폴더 경로 (POSIX 스타일로 정규화)
                // PHP dirname은 Windows에서 '\' 섞일 수 있고, 루트 파일이면 '.' 반환
                path: <?php 
                    $dir = dirname($filePath);
                    // Windows 경로 구분자 → POSIX, 앞뒤 슬래시 정리
                    $dir = str_replace('\\', '/', $dir);
                    $dir = trim($dir, '/');
                    // dirname이 '.' 반환하면 (루트) 빈 문자열로
                    if ($dir === '.' || $dir === '') $dir = '';
                    echo json_encode($dir); 
                ?>,
                timestamp: Date.now()
            };
            // console.log('[rhwp_editor] 부모 창에 파일 변경 알림 전송:', message);
            // postMessage: 동일 origin이므로 location.origin 지정 (보안 강화)
            opener.postMessage(message, window.location.origin);
        } catch (e) {
            // console.warn('[rhwp_editor] 부모 창 알림 실패:', e);
        }
    }
    
    // HWPX 파일 판별 (파일명 기반)
    const IS_HWPX_DOC = /\.hwpx$/i.test(FILE_NAME);
    
    // HWPX에서 저장 시도 시 사용자 안내
    function notifyHwpxNotSupported() {
        const statusEl = document.getElementById('sb-message');
        const msg = 'HWPX 저장은 형식 손상 위험으로 지원하지 않습니다 (rhwp 엔진 베타 제약)';
        if (statusEl) {
            statusEl.textContent = msg;
            setTimeout(() => { if (statusEl.textContent === msg) statusEl.textContent = ''; }, 4000);
        }
        // console.log('[rhwp_editor]', msg);
    }
    
    // Ctrl+S / Ctrl+Shift+S 가로채기 
    // e.code 사용 이유: e.key는 Shift나 입력 언어(한글)에 따라 달라짐
    //   - Ctrl+S: e.key='s', e.code='KeyS'
    //   - Ctrl+Shift+S: e.key='S' (대문자!), e.code='KeyS'
    //   - 한글 입력 중 Ctrl+S: e.key='ㄴ' (또는 빈 문자열), e.code='KeyS'
    // e.code는 키 물리 위치 기반이라 모든 경우 'KeyS'로 일관됨
    // Ctrl+S / Ctrl+Shift+S 처리 - head의 early 핸들러에서 호출됨
    // e.code === 'KeyS' 매칭은 early 핸들러에서 이미 수행함
    window.__rhwpHandleSave = function(shift) {
        // console.log('[rhwp_editor] handleSave called, shift=' + shift + ', HWPX=' + IS_HWPX_DOC);
        // HWPX는 저장/다른이름저장 모두 차단 (데이터 손상 방지)
        if (IS_HWPX_DOC) {
            notifyHwpxNotSupported();
            return;
        }
        if (shift) {
            exportAndSaveAs();
        } else {
            exportAndSave();
        }
    };
    
    // file:save / file:save-as 메뉴 클릭 가로채기
    // - e.__serverSave (synthetic dispatchEvent용 마커, 레거시)
    // - window.__rhwpServerSaveTrigger (element.click() 재귀 방지용 플래그)
    document.addEventListener('click', function(e) {
        if (e.__serverSave) return; // 서버 저장에서 트리거한 이벤트는 통과
        if (window.__rhwpServerSaveTrigger) {
            // console.log('[rhwp_editor] click 리스너: 서버 저장 트리거 플래그 감지 - 통과');
            return; // exportAndSave()가 element.click()으로 트리거한 이벤트
        }
        const saveItem = e.target.closest('.md-item[data-cmd="file:save"]');
        if (saveItem) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            // HWPX 먼저 체크 (disabled 상태라도 사용자에게 이유를 알려주기 위함)
            if (IS_HWPX_DOC) {
                notifyHwpxNotSupported();
                return;
            }
            // disabled 상태면 아무것도 안 함 (HWP에서 문서 로드 전 등)
            if (saveItem.classList.contains('disabled')) return;
            // console.log('[rhwp_editor] 사용자 마우스 클릭: file:save → exportAndSave 호출');
            exportAndSave();
            return;
        }
        const saveAsItem = e.target.closest('.md-item[data-cmd="file:save-as"]');
        if (saveAsItem) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            // HWPX 먼저 체크 (disabled 상태라도 사용자에게 이유를 알려주기 위함)
            if (IS_HWPX_DOC) {
                notifyHwpxNotSupported();
                return;
            }
            if (saveAsItem.classList.contains('disabled')) return;
            // console.log('[rhwp_editor] 사용자 마우스 클릭: file:save-as → exportAndSaveAs 호출');
            exportAndSaveAs();
            return;
        }
    }, true);
    
    // ===== 다른 이름으로 저장 =====
    const SAVE_AS_URL_BASE = '<?php echo rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/"); ?>/rhwp_editor.php?action=save-as&storage_id=<?php echo $storageId; ?>&path=<?php echo rawurlencode($filePath); ?>';
    
    // 간단한 프롬프트 다이얼로그 (prompt() 대체 — 에디터 UI와 잘 섞이게)
    function askNewFileName(defaultName) {
        return new Promise((resolve) => {
            const mask = document.createElement('div');
            mask.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:99998;display:flex;align-items:center;justify-content:center;';
            const box = document.createElement('div');
            box.style.cssText = 'background:#fff;border-radius:8px;padding:20px 24px;min-width:360px;max-width:90vw;box-shadow:0 10px 40px rgba(0,0,0,0.2);font-family:sans-serif;';
            box.innerHTML = 
                '<div style="font-size:15px;font-weight:600;margin-bottom:12px;color:#222;">다른 이름으로 저장</div>' +
                '<div style="font-size:13px;color:#555;margin-bottom:10px;">저장할 파일명을 입력하세요. 같은 폴더에 저장됩니다.</div>' +
                '<input type="text" id="__rhwpSaveAsInput" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:14px;" />' +
                '<div id="__rhwpSaveAsErr" style="font-size:12px;color:#d33;margin-top:6px;min-height:16px;"></div>' +
                '<div style="text-align:right;margin-top:14px;">' +
                '<button id="__rhwpSaveAsCancel" style="padding:6px 16px;margin-right:8px;background:#eee;border:1px solid #ccc;border-radius:4px;cursor:pointer;">취소</button>' +
                '<button id="__rhwpSaveAsOk" style="padding:6px 16px;background:#0066cc;color:#fff;border:1px solid #0066cc;border-radius:4px;cursor:pointer;">저장</button>' +
                '</div>';
            mask.appendChild(box);
            document.body.appendChild(mask);
            
            const input = document.getElementById('__rhwpSaveAsInput');
            const errEl = document.getElementById('__rhwpSaveAsErr');
            input.value = defaultName;
            // 확장자 앞까지만 선택
            setTimeout(() => {
                input.focus();
                const dotIdx = defaultName.lastIndexOf('.');
                if (dotIdx > 0) input.setSelectionRange(0, dotIdx);
                else input.select();
            }, 50);
            
            const cleanup = () => { try { document.body.removeChild(mask); } catch(e){} };
            const validate = (name) => {
                if (!name) return '파일명을 입력하세요';
                if (/[\/\\:*?"<>|]|\.\./.test(name)) return '사용할 수 없는 문자가 있습니다 (/ \\ : * ? " < > | ..)';
                if (name.length > 240) return '파일명이 너무 깁니다';
                if (!/\.hwp$/i.test(name) || /\.hwpx$/i.test(name)) return '확장자는 .hwp 여야 합니다 (.hwpx 미지원)';
                return null;
            };
            
            const submit = () => {
                const name = input.value.trim();
                const err = validate(name);
                if (err) { errEl.textContent = err; return; }
                cleanup();
                resolve(name);
            };
            const cancel = () => { cleanup(); resolve(null); };
            
            document.getElementById('__rhwpSaveAsOk').addEventListener('click', submit);
            document.getElementById('__rhwpSaveAsCancel').addEventListener('click', cancel);
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); submit(); }
                else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
            });
            mask.addEventListener('click', (e) => { if (e.target === mask) cancel(); });
        });
    }
    
    async function serverSaveAs(hwpData, newName) {
        const url = SAVE_AS_URL_BASE + '&new_name=' + encodeURIComponent(newName);
        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/octet-stream' },
            body: hwpData
        });
        if (!resp.ok) {
            // 파일 중복(409 대신 JSON로 처리되지만 HTTP 에러일 수도)
            const txt = await resp.text();
            try {
                const j = JSON.parse(txt);
                throw new Error(j.error || ('HTTP ' + resp.status));
            } catch(e) {
                throw new Error('HTTP ' + resp.status);
            }
        }
        const result = await resp.json();
        if (!result.success) {
            const err = new Error(result.error || '저장 실패');
            err.exists = !!result.exists;
            throw err;
        }
        return result;
    }
    
    async function exportAndSaveAs() {
        if (saving) return;
        saving = true;
        lastExportData = null;
        if (window.__rhwpSyncSuspend) window.__rhwpSyncSuspend();
        
        const statusEl = document.getElementById('sb-message');
        
        // 프로토타입 훅은 try 밖에서 선언 + finally에서 반드시 복원
        // (try 내부에서 예외 발생 시 프로토타입 영구 오염 방지)
        const origClick = HTMLAnchorElement.prototype.click;
        const origPicker = window.showSaveFilePicker;
        const hadPicker = 'showSaveFilePicker' in window;
        
        try {
            // 1) 먼저 현재 문서 바이트 추출 (Blob 캡처 방식 — save 로직과 동일)
            HTMLAnchorElement.prototype.click = function() {
                if (this.download && this.href && this.href.startsWith('blob:')) {
                    setTimeout(() => URL.revokeObjectURL(this.href), 100);
                    return;
                }
                return origClick.call(this);
            };
            window.showSaveFilePicker = async function() {
                throw new DOMException('Blocked for server save', 'AbortError');
            };
            
            if (statusEl) statusEl.textContent = '문서 추출 중...';
            
            const saveItem = document.querySelector('.md-item[data-cmd="file:save"]');
            // console.log('[rhwp_editor] (save-as) file:save 메뉴 요소:', saveItem ? 'found' : 'NOT FOUND');
            if (saveItem) {
                // disabled 임시 제거 (HTML 초기값이 disabled이고 studio가 관리 안 함)
                const wasDisabled = saveItem.classList.contains('disabled');
                if (wasDisabled) saveItem.classList.remove('disabled');
                // console.log('[rhwp_editor] (save-as) file:save 메뉴 click() 호출');
                window.__rhwpServerSaveTrigger = true;
                try {
                    saveItem.click();
                } finally {
                    setTimeout(() => { 
                        window.__rhwpServerSaveTrigger = false; 
                        if (wasDisabled) saveItem.classList.add('disabled');
                    }, 0);
                }
            }
            // console.log('[rhwp_editor] (save-as) Blob 캡처 대기 시작');
            for (let wait = 0; wait < 20; wait++) {
                await new Promise(r => setTimeout(r, 100));
                if (lastExportData && lastExportData.length > 16) {
                    // console.log('[rhwp_editor] (save-as) Blob 캡처 성공 (' + (wait*100) + 'ms):', lastExportData.length, 'bytes');
                    break;
                }
            }
            
            // 이 시점부터는 프로토타입/picker 훅이 필요 없으므로 조기 복원
            // (아래 파일명 다이얼로그와 서버 업로드는 일반적인 <a>.click()을 사용할 수 있음)
            HTMLAnchorElement.prototype.click = origClick;
            if (hadPicker) {
                window.showSaveFilePicker = origPicker;
            } else {
                try { delete window.showSaveFilePicker; } catch (_) { window.showSaveFilePicker = undefined; }
            }
            
            if (!lastExportData || lastExportData.length < 16) {
                throw new Error('문서 데이터 추출 실패');
            }
            
            const extractedData = lastExportData; // 프롬프트 중 상태 변경 대비
            
            // 2) 파일명 입력 프롬프트
            // 기본값: "원본이름_copy.확장자"
            const dotIdx = FILE_NAME.lastIndexOf('.');
            const baseName = dotIdx > 0 ? FILE_NAME.substring(0, dotIdx) : FILE_NAME;
            const ext = dotIdx > 0 ? FILE_NAME.substring(dotIdx) : '.hwp';
            const defaultName = baseName + '_copy' + ext;
            
            const newName = await askNewFileName(defaultName);
            if (!newName) {
                if (statusEl) statusEl.textContent = '';
                return;
            }
            
            // 3) 서버 저장 (중복 시 사용자에게 확인)
            if (statusEl) statusEl.textContent = '서버에 저장 중... (' + newName + ')';
            try {
                const result = await serverSaveAs(extractedData, newName);
                const sizeKB = (result.size / 1024).toFixed(1);
                if (statusEl) statusEl.textContent = newName + ' 저장 완료 (' + sizeKB + 'KB)';
                // 부모 창(웹하드)에 파일 목록 갱신 신호 전송
                notifyParentFileChanged(newName, 'save-as');
            } catch (e) {
                if (e.exists) {
                    // 같은 이름 파일이 이미 있음 — 덮어쓰기 확인
                    if (confirm('"' + newName + '" 파일이 이미 있습니다.\n덮어쓰시겠습니까?')) {
                        // overwrite 파라미터로 재시도 (서버는 기본 덮어쓰기 금지지만, 
                        // 사용자 확인 후에는 save 엔드포인트로 덮어쓰는 게 더 안전함)
                        // 단, save 엔드포인트는 현재 편집 중인 원본에만 덮어쓰므로
                        // 여기서는 force 파라미터 추가
                        const url = SAVE_AS_URL_BASE + '&new_name=' + encodeURIComponent(newName) + '&force=1';
                        const resp = await fetch(url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/octet-stream' },
                            body: extractedData
                        });
                        const j = await resp.json();
                        if (j.success) {
                            const sizeKB = (j.size / 1024).toFixed(1);
                            if (statusEl) statusEl.textContent = newName + ' 덮어쓰기 완료 (' + sizeKB + 'KB)';
                            // 부모 창에 파일 목록 갱신 신호 (덮어쓰기도 갱신 필요)
                            notifyParentFileChanged(newName, 'save-as-overwrite');
                        } else {
                            throw new Error(j.error || '저장 실패');
                        }
                    } else {
                        if (statusEl) statusEl.textContent = '취소되었습니다';
                    }
                } else {
                    throw e;
                }
            }
        } catch (e) {
            console.error('[rhwp_editor] 다른 이름으로 저장 실패:', e);
            if (statusEl) statusEl.textContent = '저장 실패: ' + e.message;
        } finally {
            // 안전장치: 조기 복원 전 예외 발생 가능성에 대비해 최종 한 번 더 복원
            // (이미 복원된 상태면 같은 값을 덮어쓰는 것이라 멱등적, no-op에 가까움)
            if (HTMLAnchorElement.prototype.click !== origClick) {
                HTMLAnchorElement.prototype.click = origClick;
            }
            if (window.showSaveFilePicker && typeof window.showSaveFilePicker === 'function' 
                && window.showSaveFilePicker.toString().indexOf('Blocked for server save') !== -1) {
                if (hadPicker) {
                    window.showSaveFilePicker = origPicker;
                } else {
                    try { delete window.showSaveFilePicker; } catch (_) { window.showSaveFilePicker = undefined; }
                }
            }
            saving = false;
            lastExportData = null;
            if (window.__rhwpSyncResume) window.__rhwpSyncResume();
        }
    }
    
    window.__rhwpServerSaveAs = exportAndSaveAs;
    
    // ===== 저장 메뉴 상태 관리 =====
    // HWP: file:save, file:save-as 모두 활성화
    // HWPX: 둘 다 비활성화 (rhwp 엔진이 file:save를 자동 비활성화하므로 그 상태 존중)
    //       → 업스트림 canExecute가 이미 처리하지만, save-as는 커스텀이므로 직접 관리
    
    // 저장 중에는 동기화 일시 중지 (저장 실행 중 rhwp가 file:save를 순간 disabled 처리하는 것을 무시)
    let syncSuspended = false;
    
    function syncSaveAsMenuState() {
        if (syncSuspended) return; // 저장 중에는 동기화 스킵
        
        const saveItem = document.querySelector('.md-item[data-cmd="file:save"]');
        const saveAsItem = document.querySelector('.md-item[data-cmd="file:save-as"]');
        if (!saveAsItem) return;
        
        if (IS_HWPX_DOC) {
            // HWPX: 저장/다른이름저장 모두 비활성화 + 툴팁으로 이유 안내
            // ⚠️ HWPX에서는 MutationObserver가 이 함수를 계속 호출하므로
            //    실제 변경이 필요할 때만 DOM을 건드려 루프 방지 (멱등성 체크)
            const tooltip = 'HWPX 저장은 형식 손상 위험으로 지원하지 않습니다';
            if (!saveAsItem.classList.contains('disabled')) {
                saveAsItem.classList.add('disabled');
            }
            if (saveAsItem.title !== tooltip) {
                saveAsItem.title = tooltip;
            }
            if (saveItem) {
                if (!saveItem.classList.contains('disabled')) {
                    saveItem.classList.add('disabled');
                }
                if (saveItem.title !== tooltip) {
                    saveItem.title = tooltip;
                }
            }
        } else {
            // HWP: file:save-as는 FileStation 커스텀 메뉴이므로 studio가 관리하지 않음
            // 문서 로드 여부와 관계없이 항상 활성 유지 (HTML 초기값도 활성)
            // studio가 자체적으로 disabled를 추가했을 경우에만 제거
            if (saveAsItem.classList.contains('disabled')) {
                saveAsItem.classList.remove('disabled');
            }
            if (saveAsItem.getAttribute('title')) saveAsItem.removeAttribute('title');
            // file:save의 title은 HWP 상태에서는 제거 (studio가 disabled는 관리)
            if (saveItem && saveItem.getAttribute('title')) {
                saveItem.removeAttribute('title');
            }
        }
    }
    
    // 저장 작업 전/후 호출하는 헬퍼 (exportAndSave, exportAndSaveAs에서 사용)
    window.__rhwpSyncSuspend = () => { syncSuspended = true; };
    window.__rhwpSyncResume = () => {
        syncSuspended = false;
        // 저장 완료 후 강제 재동기화 (rhwp가 메뉴 상태를 건드렸을 수 있으므로)
        setTimeout(syncSaveAsMenuState, 100);
        setTimeout(syncSaveAsMenuState, 500); // 2차 재동기화 (rhwp가 뒤늦게 건드릴 수도 있음)
    };
    
    // 1) DOMContentLoaded 이후 초기 상태 동기화
    window.addEventListener('DOMContentLoaded', () => {
        setTimeout(syncSaveAsMenuState, 1000);
    });
    
    // 2) MutationObserver로 file:save 상태 변경 감시 → file:save-as도 동기화
    // ⚠️ HWPX 문서일 때는 observer 사용 금지:
    //    - HWPX는 초기 setup으로 한 번만 disabled+tooltip 설정하면 끝
    //    - studio가 HWPX에서 메뉴 상태를 주기적으로 업데이트하면 
    //      observer → syncSaveAsMenuState → classList/title 변경 → observer 재트리거 루프 위험
    //    - HWPX에서는 저장 자체가 차단되므로 실시간 동기화 불필요
    const observeSaveMenus = () => {
        const saveItem = document.querySelector('.md-item[data-cmd="file:save"]');
        const saveAsItem = document.querySelector('.md-item[data-cmd="file:save-as"]');
        if (!saveItem || !saveAsItem) return false;
        
        // 초기 상태 한 번 정리
        syncSaveAsMenuState();
        
        // HWPX는 observer 사용 안 함 (무한 루프 방지)
        if (IS_HWPX_DOC) {
            // HWPX에서도 혹시 studio가 나중에 상태를 건드릴 경우 대비해 
            // 1회성 재동기화만 타이머로 수행
            setTimeout(syncSaveAsMenuState, 2000);
            setTimeout(syncSaveAsMenuState, 5000);
            return true;
        }
        
        // HWP에서만 실시간 observer
        const observer = new MutationObserver(() => {
            syncSaveAsMenuState();
        });
        // file:save의 class 변경(disabled 토글)을 감시해서 save-as도 따라가게
        observer.observe(saveItem, { attributes: true, attributeFilter: ['class'] });
        // file:save-as 자체도 감시 (studio가 커스텀 메뉴로 판단해서 disabled 넣는 경우 대응)
        observer.observe(saveAsItem, { attributes: true, attributeFilter: ['class'] });
        
        return true;
    };
    
    // 메뉴 DOM이 늦게 생길 수 있으므로 재시도
    (function waitForSaveMenus(tries) {
        if (tries <= 0) return;
        if (!observeSaveMenus()) {
            setTimeout(() => waitForSaveMenus(tries - 1), 500);
        }
    })(20);
    
    window.__rhwpServerSave = exportAndSave;
})();
</script>
</body>
</html>
