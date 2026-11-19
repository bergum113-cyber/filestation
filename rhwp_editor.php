<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * rhwp-studio HWP 에디터 래퍼
 * https://github.com/edwardkim/rhwp
 * @rhwp_version 0.9.1
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/api/FsIcons.php';   // ★ (2026-10-26) 선 그림 아이콘 — 아래 화면의 이모지를 바꿈

// ★ (2026-09-02) HTML 캐시 방지 — index.php 와 동일 취지.
//   이 페이지도 `?v=APP_VERSION` 으로 자산을 캐시버스팅하지만, **HTML 이 캐시되면
//   옛 버전 문자열을 참조한 채 남아** 새 자산을 요청하지 않는다.
//   HTML 만 매번 새로 받게 하고 자산 캐시는 그대로 둔다(트래픽 영향 없음).
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$storage = new Storage();
$auth = new Auth();

// ★ (2026-11-07 11:20, 로그인 유지 풀림 재검토) 세션이 없으면 로그인 유지 토큰으로 복구 시도(index.php·api.php 와 같게) — 종전엔 이 화면이 첫 요청이면(iPhone 이 탭을 정리한 뒤 뷰어 탭을 다시 읽을 때 등) 로그인 유지를 켜도 로그인 화면/401 로 갔음(재현)
if (!$auth->isLoggedIn() && !$auth->checkRememberToken()) { header('Location: index.php'); exit; }

$storageId = (int)($_GET['storage_id'] ?? 0);
if (isset($_GET['path']) && !is_string($_GET['path'])) { http_response_code(400); exit('Invalid path'); }   // ★ (2026-11-11 13:21, 74차) path[]= 배열이면 dirname()/basename() TypeError 로 500 이었음(api.php 는 56차에 400 으로 정리, 이 진입점은 앞단을 안 거침) → 400. 문자열 경로는 그대로
$filePath = $_GET['path'] ?? '';
$fileName = basename($filePath);
// ★ (2026-11-03 14:21, 51차 재검토) 로컬 스토리지도 원격처럼 '..'(또는 점·공백만 있는 '.. '·'...') 조각을 거부 — api.php 앞단 차단을 거치지 않는 진입점이라, 루트 위로 넘치는 '..'
//   ('../<스토리지 폴더>/secret/a.docx')나 Linux 의 '.. '·'...' 폴더를 끼운 경로가 폴더 권한·잠금 비교만 비켜 가 읽기 금지 폴더 읽기·쓰기 금지/잠긴 한글 파일 덮어쓰기가 됐음(재현)
if (preg_match('#(^|[\\\\/])[. ]*\.\.[. ]*($|[\\\\/])#', (string)$filePath)) { http_response_code(403); exit('Invalid path'); }

if ($storageId && !$storage->checkPermission($storageId, 'can_read')) {
    http_response_code(403); exit('No permission');
}

// ★ (2026-08-24) HWP 로딩 단계별 계측 수신 — data/hwp_timing.log 파일이 있을 때만 기록.
//   [왜] "문서 로딩이 매번 느리다"의 원인을 어림짐작하지 않고 실측으로 가르기 위한 관측 전용
//   엔드포인트다. 클라이언트가 단계별 소요(파일 수신 / WASM 준비 / 고정 대기 / 주입 / 렌더 완료)를
//   모아 한 번만 POST 한다. 로그 파일이 없으면 아무것도 하지 않으므로 평소에는 완전히 꺼진 상태다.
//   권한 검사(위 can_read)를 이미 통과한 뒤이며, 저장 내용은 파일명·소요시간·UA 앞 120자뿐이다.
if (isset($_GET['action']) && $_GET['action'] === 'timing_log') {
    $__tl = __DIR__ . '/data/hwp_timing.log';
    if (is_file($__tl)) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && strlen($raw) < 20000) {
            $d = json_decode($raw, true);
            if (is_array($d)) {
                // ★ (2026-11-01 01:11, 35차 전체 점검) 파일 이름·결과·단계 이름의 줄바꿈도 지움(UA 와 같게) — 가짜 로그 줄을 끼워 넣지 못하게
                $__nl = function ($v) { return preg_replace('/[\r\n]/', ' ', (string)$v); };
                $line = date('Y-m-d H:i:s') . ' | ' . $__nl(basename((string)($d['file'] ?? '?')))
                      . ' | result=' . $__nl($d['result'] ?? '?')
                      . ' | total=' . (int)($d['total_ms'] ?? 0) . 'ms';
                foreach (($d['marks'] ?? []) as $m) {
                    if (!is_array($m)) continue;
                    $line .= "\n    " . str_pad($__nl($m['label'] ?? '?'), 20)
                           . ' +' . (int)($m['delta_ms'] ?? 0) . 'ms'
                           . '  (누적 ' . (int)($m['total_ms'] ?? 0) . 'ms)'
                           . (isset($m['extra']) && $m['extra'] !== null ? '  ' . json_encode($m['extra'], JSON_UNESCAPED_UNICODE) : '');
                }
                $line .= "\n    UA: " . preg_replace('/[\r\n]/', ' ', (string)($d['ua'] ?? '')) . "\n";
                @file_put_contents($__tl, $line, FILE_APPEND | LOCK_EX);
            }
        }
    }
    http_response_code(204);
    exit;
}

// 파일 스트리밍
if (isset($_GET['action']) && $_GET['action'] === 'stream') {
    // 폴더별 권한 체크
    $fileDir = dirname($filePath);
    if ($fileDir === '.') $fileDir = '';
    if (!$storage->checkFolderPermission($storageId, ($fileDir !== '' ? $fileDir : $filePath))) { // ★ (2026-11-01 17:17, 40차 점검) 부모 폴더가 '0' 이면 ?: 가 빈 값으로 봐 항목 자신 규칙으로 검사했음 → '' 일 때만 항목 자신
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
        if (preg_match('#(^|[\\\\/])[. ]*\.\.[. ]*($|[\\\\/])#', $filePath)) { http_response_code(403); exit; } // ★ (2026-11-01 17:17, 40차 점검) 뷰어(38차)와 같은 '..' 조각 검사로 — 종전 식은 '..' 바로 뒤 구분자만 봐 'x/.. /a.hwp'(Windows 쪽 서버는 끝 점·공백을 버려 '..' 가 됨)를 통과시켰음
        require_once __DIR__ . '/api/StorageAdapter.php';
        $adapter = StorageAdapterFactory::create($storageInfo);
        if (!$adapter || !$adapter->connect()) { http_response_code(503); exit; }
        // ★ office_viewer.php 패턴 통일: read() + file_put_contents() (download() 메서드 없음)
        // ★ 잔류 정리 안전망 (v5.8.1c — readfile 중 abort 시 @unlink 못 함)
        foreach (@glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rhwpe_*') ?: [] as $_old) {
            if (is_file($_old) && filemtime($_old) < time() - 3600) @unlink($_old);
        }
        // ★ (2026-10-30 01:55, 26차 점검) 이름을 직접 만듦 — Windows 의 tempnam 은 접두어 앞 3글자만 써서(rhw….tmp) 위 정리(glob 'rhwpe_*')가 하나도 못 찾았음
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rhwpe_' . bin2hex(random_bytes(8)) . '.tmp';
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
        if (!$realBase || !$realTarget || !\isSubPath($realTarget, $realBase)) { http_response_code(403); exit; }
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
// ★ (2026-11-04 01:36, 54차 점검) 저장할 내용 백신 검사(53차 텍스트 저장과 같은 방식·같은 문구 — 'clean' 값만 봄. 백신 꺼짐·Defender 판단 불가는 clean:true 라 종전처럼 통과,
//   임시 파일을 못 만들면 검사 없이 통과(텍스트 저장과 같음)) — 종전엔 저장·다른 이름으로 저장으로 업로드에서 막히는 EICAR 내용을 그대로 만들 수 있었음(재현). 거부 문구를 돌려주고, 통과면 null
function rwAvRejectReason(string $rawData, string $name): ?string {
    $len = strlen($rawData);
    if ($len <= 0) return null;
    $tmp = @tempnam(sys_get_temp_dir(), 'fsrw');
    if ($tmp === false) return null;
    if (@file_put_contents($tmp, $rawData) !== $len) { @unlink($tmp); return null; }
    $av = (new FileManager())->scanForVirusPublic($tmp, $name, $len);
    @unlink($tmp);
    return empty($av['clean']) ? FileManager::avBlockMessage($av) : null;   // ★ (2026-11-04) 56차 '오류면 차단'은 감염과 다른 문구(FileManager::avBlockMessage, 판정 그대로 — 감염 문구는 종전과 같음)
}

if (isset($_GET['action']) && $_GET['action'] === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$storageId || !$filePath) {
        echo json_encode(['success' => false, 'error' => 'Missing storage_id or path']); exit;
    }
    // 확장자 검증: .hwp / .hwpx만 허용
    // (HWPX 저장은 rhwp studio가 0.7.18+ 에서 정식 지원 — 0.7.3 시절 손상 이슈로 두었던 차단 해제)
    if (!preg_match('/\\.(hwp|hwpx)$/i', $filePath)) {
        echo json_encode(['success' => false, 'error' => __('rhwp_save_hwp_only', '.hwp / .hwpx 파일만 저장할 수 있습니다')]); exit;   // ★ (2026-11-02 10:29, 42차 점검) 번역 함수 밖 한국어 고정이었음 → __()(한국어 기본 문구 그대로)
    }
    if (!$storage->checkPermission($storageId, 'can_write')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No write permission']); exit;
    }
    // 폴더별 쓰기 권한 체크
    $saveDir = dirname($filePath);
    if ($saveDir === '.') $saveDir = '';
    if (!$storage->checkFolderPermission($storageId, ($saveDir !== '' ? $saveDir : $filePath), 'can_write')) { // ★ (2026-11-01 17:17, 40차 점검) 부모 폴더가 '0' 이면 ?: 가 빈 값으로 봐 항목 자신 규칙으로 검사했음 → '' 일 때만 항목 자신
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No folder write permission']); exit;
    }
    // ★ (2026-10-29 10:25, 20차 점검) 잠긴 파일은 저장으로 덮어쓰지 않음(텍스트 저장과 같음) — 종전엔 그대로 덮어썼음(재현)
    if ((new FileManager())->isFileLockedPublic((int)$storageId, (string)$filePath)) {   // ★ (2026-10-31 12:03, 34차 전체 점검) 글자 그대로 비교라 'a//b'·'/a'·대소문자(윈도우) 표기로 잠금을 지났음 → 탐색기와 같은 정리 비교
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => __('exif_strip_locked', '잠긴 파일입니다.')]); exit;
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
    // ★ (2026-11-01 09:42, 38차 점검) 내용의 머리 바이트가 확장자와 맞는지 확인(.hwp = OLE D0CF11E0A1B11AE1, .hwpx = ZIP 'PK\x03\x04') — 종전엔 16바이트만 넘으면 무엇이든 받아,
    //   편집기에서 다른 .hwpx 를 열고 Ctrl+S 하면 ZIP 내용이 원본 .hwp 에 저장돼 확장자와 내용이 다른 깨진 파일이 됐음
    $rwIsHwpx = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'hwpx';
    if (strncmp($rawData, $rwIsHwpx ? "PK\x03\x04" : "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", $rwIsHwpx ? 4 : 8) !== 0) {
        echo json_encode(['success' => false, 'error' => __('rhwp_save_format_mismatch', '저장할 문서의 형식이 파일 확장자와 맞지 않아 저장하지 않았습니다. 편집기에서 다른 문서를 열었는지 확인하세요.')]); exit;
    }
    // ★ (2026-11-04 01:36, 54차 점검) 쓰기 전 백신 검사(로컬·원격 공통, 위 rwAvRejectReason) — 종전엔 '저장'으로 원본을 EICAR 내용으로 바꿀 수 있었음(재현)
    if (($rwAvErr = rwAvRejectReason($rawData, basename(str_replace('\\', '/', (string)$filePath)))) !== null) {
        echo json_encode(['success' => false, 'error' => $rwAvErr]); exit;
    }

    $storageInfo = $storage->getStorageById($storageId);
    if (!$storageInfo) {
        echo json_encode(['success' => false, 'error' => 'Storage not found']); exit;
    }
    
    $storageType = $storageInfo['storage_type'] ?? 'local';
    $isRemote = in_array($storageType, ['ftp', 'sftp', 'webdav', 's3', 'smb']);
    
    if ($isRemote) {
        // ★ (2026-11-01 17:17, 40차 점검) 뷰어(38차)와 같은 '..' 조각 검사로 — 종전 식은 '..' 바로 뒤 구분자만 봐 'x/.. /a.hwp'(Windows 쪽 서버는 끝 점·공백을 버려 '..' 가 됨)를 통과시켰음
        if (preg_match('#(^|[\\\\/])[. ]*\.\.[. ]*($|[\\\\/])#', $filePath)) {
            echo json_encode(['success' => false, 'error' => 'Invalid path']); exit;
        }
        // ★ (2026-10-29 18:47, 22차 점검) 서버 실행 이름 검사(다른 이름으로 저장·텍스트 저장과 같음) — 종전엔 원격 '저장'에만 빠져 'x.php.hwp' 같은 이름을 새로 만들 수 있었음
        // ★ (2026-11-02 08:57, 41차 점검) 예외를 실제 관리자만으로 — 종전 isAdminOrSubAdmin() 이라 관리 권한 없는 부관리자도 'x.php.hwp' 를 만들 수 있었음(텍스트 저장 39차와 같은 기준)
        if (!$auth->isAdmin() && ($rsExec = \fsServerExecReason(basename(str_replace('\\', '/', $filePath)))) !== null) {   // 실제 관리자는 '다른 이름으로 저장'과 같이 예외
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => $rsExec]); exit;
        }
        require_once __DIR__ . '/api/StorageAdapter.php';
        $adapter = StorageAdapterFactory::create($storageInfo);
        if (!$adapter || !$adapter->connect()) {
            echo json_encode(['success' => false, 'error' => 'Remote connection failed']); exit;
        }
        // ★ (2026-10-29 18:47, 22차 점검) '저장'은 있는 파일만 덮어씀(로컬 저장과 같음) — 종전엔 원격이면 없는 이름도 새로 만들었음
        if (!$adapter->exists($filePath)) {
            $adapter->disconnect();
            echo json_encode(['success' => false, 'error' => __('file_not_found', '파일을 찾을 수 없습니다.')]); exit;
        }
        // ★ (2026-11-01 07:18, 37차 기능 점검) 원격 '저장'에도 용량 확인·사용량 반영(로컬 24차·텍스트 저장 원격 분기와 같은 기준) — 종전엔 원격이면 둘 다 없어 한도를 넘기고 used_size 가 안 맞았음
        $rwOld = 0;
        try { $rwOld = max(0, (int)$adapter->getSize($filePath)); } catch (\Throwable $e) { $rwOld = 0; }
        // ★ (2026-11-03 23:38, 53차 점검) 내용이 있는 파일 덮어쓰기는 대량 덮어쓰기 감지에 셈(텍스트 저장·WebDAV PUT 과 같은 기록·한도)
        if ($rwOld > 0 && empty(($rwBulk = (new FileManager())->checkBulkOperationPublic('overwrite'))['allowed'])) {
            $adapter->disconnect(); echo json_encode(['success' => false, 'error' => (string)($rwBulk['reason'] ?? '')]); exit;
        }
        $rwNeed = strlen($rawData) - $rwOld;
        if ($rwNeed > 0) {
            $rwQ = (new FileManager())->checkQuotaPublic($storageId, $rwNeed);
            if (empty($rwQ['allowed'])) { $adapter->disconnect(); echo json_encode(['success' => false, 'error' => $rwQ['error'] ?? 'Quota exceeded']); exit; }
        }
        // ★ office_viewer.php 패턴 통일: write() 직접 호출 (upload() 메서드 없음)
        $ok = $adapter->write($filePath, $rawData);
        $adapter->disconnect();
        if ($ok && $rwNeed !== 0) $storage->updateUsedSize($storageId, $rwNeed);   // ★ (2026-11-01 07:18, 37차 기능 점검) 위와 같음
        if ($ok) { try { $rwFi = FileIndex::getInstance(); if ($rwFi->isAvailable()) { $rwFi->addFile((int)$storageId, trim(str_replace('\\', '/', (string)$filePath), '/'), ['is_dir' => 0, 'size' => strlen($rawData), 'modified' => date('Y-m-d H:i:s')]); } } catch (\Throwable $e) {} }   // ★ (2026-11-04) 55차 U4 — '저장' 뒤 색인 크기·날짜가 옛 값이었음(다른 이름으로 저장과 같은 갱신)
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
        if (!$realBase || !$realTarget || !\isSubPath($realTarget, $realBase)) {
            echo json_encode(['success' => false, 'error' => 'Path traversal denied']); exit;
        }
        // ★ (2026-10-29 21:46, 24차 점검) 커지면 용량 확인, 저장 뒤 크기 차이를 사용량에 반영(22차 텍스트 저장 file_text_save 와 같게) — 종전엔 둘 다 없었음(재현)
        $rwOld = (int)@filesize($realTarget);
        // ★ (2026-11-03 23:38, 53차 점검) 내용이 있는 파일 덮어쓰기는 대량 덮어쓰기 감지에 셈(텍스트 저장·WebDAV PUT 과 같은 기록·한도)
        if ($rwOld > 0 && empty(($rwBulk = (new FileManager())->checkBulkOperationPublic('overwrite'))['allowed'])) {
            echo json_encode(['success' => false, 'error' => (string)($rwBulk['reason'] ?? '')]); exit;
        }
        $rwNeed = strlen($rawData) - $rwOld;
        if ($rwNeed > 0) {
            $rwQ = (new FileManager())->checkQuotaPublic($storageId, $rwNeed);
            if (empty($rwQ['allowed'])) { echo json_encode(['success' => false, 'error' => $rwQ['error'] ?? 'Quota exceeded']); exit; }
        }
        // 원자적 쓰기: 임시파일 → rename
        $tempFile = $realTarget . '.rhwp_tmp_' . getmypid() . '_' . bin2hex(random_bytes(6));   // ★ (2026-11-01 01:11, 35차 전체 점검) 임의 꼬리 — Windows Apache 는 모든 요청이 같은 PID 라 동시 저장끼리 같은 임시 파일에 썼음
        $written = file_put_contents($tempFile, $rawData);
        if ($written === false) {
            @unlink($tempFile);
            echo json_encode(['success' => false, 'error' => 'Write failed']); exit;
        }
        // ★ (2026-11-01 09:42, 38차 점검) 덮어쓰기 직전 이전 버전 남기기(웹 덮어쓰기·WebDAV 와 같은 함수 — 버전 설정이 꺼져 있거나 제외 확장자면 안에서 그냥 돌아옴). 종전엔 편집기 저장만 버전이 안 남았음
        (new FileManager())->saveFileVersionPublic($realTarget, trim(str_replace('\\', '/', (string)$filePath), '/'), (int)$storageId);
        if (!rename($tempFile, $realTarget)) {
            @unlink($tempFile);
            echo json_encode(['success' => false, 'error' => 'Rename failed']); exit;
        }
        if ($written - $rwOld !== 0) $storage->updateUsedSize($storageId, $written - $rwOld);
        try { $rwFi = FileIndex::getInstance(); if ($rwFi->isAvailable()) { clearstatcache(true, $realTarget); $rwFi->addFile((int)$storageId, trim(str_replace('\\', '/', (string)$filePath), '/'), ['is_dir' => 0, 'size' => (int)$written, 'modified' => date('Y-m-d H:i:s', (int)(@filemtime($realTarget) ?: time()))]); } } catch (\Throwable $e) {}   // ★ (2026-11-04) 55차 U4 — '저장' 뒤 색인 크기·날짜가 옛 값이었음(다른 이름으로 저장과 같은 갱신)
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
    if (preg_match('/[\\/\\\\:*?"<>|\x00-\x1f]|\\.\\./', $newName)) {   // ★ (2026-11-01 09:42, 38차 점검) 제어 문자(줄바꿈·탭·\x00 등)도 거부(sanitizeFilename 과 같음) — 종전엔 줄바꿈이 든 파일 이름이 만들어지고, \x00 이면 500 이 났음
        echo json_encode(['success' => false, 'error' => __('rhwp_name_invalid_chars', '파일명에 사용할 수 없는 문자가 있습니다')]); exit;   // ★ (2026-11-02 10:29, 42차 점검) 번역 함수 밖 한국어 고정이었음 → __()(한국어 기본 문구 그대로)
    }
    if (strlen($newName) > 240) {
        echo json_encode(['success' => false, 'error' => __('rhwp_name_too_long', '파일명이 너무 깁니다')]); exit;   // ★ (2026-11-02 10:29, 42차 점검) 번역 함수 밖 한국어 고정이었음 → __()(한국어 기본 문구 그대로)
    }
    // 확장자 검증: 원본과 같은 형식만 허용 (.hwp → .hwp, .hwpx → .hwpx)
    // 내용은 원본 형식으로 저장되므로 확장자만 바꾸면 깨진 파일이 된다
    $srcExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (!in_array($srcExt, ['hwp', 'hwpx'], true)) {
        echo json_encode(['success' => false, 'error' => __('rhwp_save_hwp_only', '.hwp / .hwpx 파일만 저장할 수 있습니다')]); exit;   // ★ (2026-11-02 10:29, 42차 점검) 번역 함수 밖 한국어 고정이었음 → __()(한국어 기본 문구 그대로)
    }
    if (strtolower(pathinfo($newName, PATHINFO_EXTENSION)) !== $srcExt) {
        echo json_encode(['success' => false, 'error' => __f('rhwp_ext_must_be', ['ext' => $srcExt], '확장자는 .{ext} 여야 합니다')]); exit;   // ★ (2026-11-02 10:29, 42차 점검) 번역 함수 밖 한국어 고정이었음 → __()(한국어 기본 문구 그대로)
    }
    // ★ (2026-11-09 11:56, 57차 Z17-3) 다른 만들기 경로와 같은 이름 정리(FileManager::sanitizeFilename 과 같은 식 — 위험 문자 제거·앞뒤 점·공백 제거·빈 이름 'unnamed'·Windows 장치 이름 앞 '_') 뒤 그 이름으로 저장·응답 — 종전 '.hwp' 가 목록에 안 보이는 점 이름으로 저장됐음(재현). 정리로 확장자가 빠지면 새 문서 만들기(api.php)처럼 확장자를 붙임
    $newName = trim(preg_replace('/[<>:"\/\\\\|?*\x00-\x1f]/', '', $newName), '. '); if ($newName === '') $newName = 'unnamed'; if (strtolower(pathinfo($newName, PATHINFO_EXTENSION)) !== $srcExt) $newName .= '.' . $srcExt; if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $newName)) $newName = '_' . $newName;
    // ★ (2026-10-29 10:25, 20차 점검) 서버 실행 이름(x.php.hwp 처럼 가운데 확장자 포함) 거부 — 텍스트 저장(file_text_save)과 같은 기준(관리자 제외). 종전엔 그대로 만들어졌음(재현)
    // ★ (2026-11-02 08:57, 41차 점검) 예외를 실제 관리자만으로 — 종전 isAdminOrSubAdmin() 이라 세부 권한 없는 부관리자도 실행 이름을 만들 수 있었음(텍스트 저장 39차와 같게)
    if (!$auth->isAdmin() && function_exists('fsServerExecReason') && ($rwExec = fsServerExecReason($newName)) !== null) {
        echo json_encode(['success' => false, 'error' => $rwExec]); exit;
    }
    
    if (!$storage->checkPermission($storageId, 'can_write')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No write permission']); exit;
    }
    
    // 저장 폴더 = 현재 파일의 폴더
    $saveDir = dirname($filePath);
    if ($saveDir === '.') $saveDir = '';
    if (!$storage->checkFolderPermission($storageId, ($saveDir !== '' ? $saveDir : $newName), 'can_write')) { // ★ (2026-11-01 17:17, 40차 점검) 부모 폴더가 '0' 이면 ?: 가 빈 값으로 봐 항목 자신 규칙으로 검사했음 → '' 일 때만 항목 자신
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No folder write permission']); exit;
    }
    
    // 세션 락 해제: 원격 업로드 중 웹하드가 블로킹되지 않도록
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    // 새 파일 경로 조합
    $newFilePath = $saveDir === '' ? $newName : ($saveDir . '/' . $newName);
    // ★ (2026-10-29 10:25, 20차 점검) 덮어쓸 대상이 잠긴 파일이면 거부(덮어쓰기 확인 후 force=1 로 다시 와도)
    if ((new FileManager())->isFileLockedPublic((int)$storageId, (string)$newFilePath)) {   // ★ (2026-10-31 12:03, 34차 전체 점검) 글자 그대로 비교라 'a//b'·'/a'·대소문자(윈도우) 표기로 잠금을 지났음 → 탐색기와 같은 정리 비교
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => __('exif_strip_locked', '잠긴 파일입니다.')]); exit;
    }
    
    // POST body에서 바이너리 읽기
    $rawData = file_get_contents('php://input');
    if (!$rawData || strlen($rawData) < 16) {
        echo json_encode(['success' => false, 'error' => 'Empty or invalid data']); exit;
    }
    // ★ (2026-11-01 09:42, 38차 점검) 내용의 머리 바이트가 확장자와 맞는지 확인(.hwp = OLE D0CF11E0A1B11AE1, .hwpx = ZIP 'PK\x03\x04') — 종전엔 16바이트만 넘으면 무엇이든 받아,
    //   편집기에서 다른 .hwpx 를 열고 Ctrl+S 하면 ZIP 내용이 원본 .hwp 에 저장돼 확장자와 내용이 다른 깨진 파일이 됐음
    $rwIsHwpx = strtolower(pathinfo($newName, PATHINFO_EXTENSION)) === 'hwpx';
    if (strncmp($rawData, $rwIsHwpx ? "PK\x03\x04" : "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", $rwIsHwpx ? 4 : 8) !== 0) {
        echo json_encode(['success' => false, 'error' => __('rhwp_save_format_mismatch', '저장할 문서의 형식이 파일 확장자와 맞지 않아 저장하지 않았습니다. 편집기에서 다른 문서를 열었는지 확인하세요.')]); exit;
    }
    // ★ (2026-11-04 01:36, 54차 점검) 쓰기 전 백신 검사(로컬·원격 공통, 위 rwAvRejectReason) — 종전엔 '다른 이름으로 저장'으로 EICAR 내용의 새 파일을 만들 수 있었음(재현)
    if (($rwAvErr = rwAvRejectReason($rawData, $newName)) !== null) {
        echo json_encode(['success' => false, 'error' => $rwAvErr]); exit;
    }

    $storageInfo = $storage->getStorageById($storageId);
    if (!$storageInfo) {
        echo json_encode(['success' => false, 'error' => 'Storage not found']); exit;
    }
    
    $storageType = $storageInfo['storage_type'] ?? 'local';
    $isRemote = in_array($storageType, ['ftp', 'sftp', 'webdav', 's3', 'smb']);
    
    if ($isRemote) {
        // ★ (2026-09-24) 경로 탈출(..) 검사 정규식 수정 — 역슬래시가 한 번 더 들어가(87·168행 식을 두 번 이스케이프) 가운데 '/' 가
        //   식의 끝으로 읽혀 컴파일 오류 → preg_match 가 false → **검사가 항상 통과**했다. 이 줄은 '다른 이름으로 저장'의
        //   원격 저장소 경로에서 유일한 경로 검사다(로컬은 realpath 로 막음). 87·168행과 같은 식으로 바꾼다.
        // ★ (2026-11-01 17:17, 40차 점검) 뷰어(38차)와 같은 '..' 조각 검사로 — 종전 식은 '..' 바로 뒤 구분자만 봐 'x/.. /a.hwp'(Windows 쪽 서버는 끝 점·공백을 버려 '..' 가 됨)를 통과시켰음
        if (preg_match('#(^|[\\\\/])[. ]*\.\.[. ]*($|[\\\\/])#', $newFilePath)) {
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
                echo json_encode(['success' => false, 'error' => '같은 이름의 파일이 이미 있습니다', 'exists' => true, 'name' => $newName]); exit;   // ★ (2026-11-09 19:50, 60차 Z17) 정리된 실제 이름도 돌려줌 — 화면 덮어쓰기 확인창이 입력 이름('.보고서.hwp')을 보여 주고 실제로는 '보고서.hwp' 를 덮었음(재현)
            }
        }
        // ★ (2026-11-01 07:18, 37차 기능 점검) 원격 '다른 이름으로 저장'에도 용량 확인·사용량·인덱스 반영(로컬 24차와 같은 기준) — 종전엔 반복 저장으로 한도와 상관없이 새 파일이 생기고 used_size·검색 인덱스에 안 잡혔음
        $rwOld = 0;
        try { if ($adapter->exists($newFilePath)) $rwOld = max(0, (int)$adapter->getSize($newFilePath)); } catch (\Throwable $e) { $rwOld = 0; }
        // ★ (2026-11-04 00:19, 53차 재검토) '다른 이름으로 저장'의 덮어쓰기(force)도 대량 덮어쓰기 감지에 셈('저장'과 같음, 검토 발견)
        if ($rwOld > 0 && empty(($rwBulk = (new FileManager())->checkBulkOperationPublic('overwrite'))['allowed'])) {
            $adapter->disconnect(); echo json_encode(['success' => false, 'error' => (string)($rwBulk['reason'] ?? '')]); exit;
        }
        $rwNeed = strlen($rawData) - $rwOld;
        if ($rwNeed > 0) {
            $rwQ = (new FileManager())->checkQuotaPublic($storageId, $rwNeed);
            if (empty($rwQ['allowed'])) { $adapter->disconnect(); echo json_encode(['success' => false, 'error' => $rwQ['error'] ?? 'Quota exceeded']); exit; }
        }
        // ★ office_viewer.php 패턴 통일: write() 직접 호출 (upload() 메서드 없음)
        $ok = $adapter->write($newFilePath, $rawData);
        $adapter->disconnect();
        if ($ok) {   // ★ (2026-11-01 07:18, 37차 기능 점검) 위와 같음
            if ($rwNeed !== 0) $storage->updateUsedSize($storageId, $rwNeed);
            try { $rwFi = FileIndex::getInstance(); if ($rwFi->isAvailable()) { $rwFi->addFile((int)$storageId, trim(str_replace('\\', '/', (string)$newFilePath), '/'), ['is_dir' => 0, 'size' => strlen($rawData), 'modified' => date('Y-m-d H:i:s')]); } } catch (\Throwable $e) {}
        }
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
        $newRealFile = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $newFilePath);   // ★ (2026-11-01 09:42, 38차 점검) '\\\\'(역슬래시 두 개) → '\\'(한 개) — 저장·스트림(113·213행 근처)과 같게. 종전엔 Linux 에서 'A\\b.hwp' 를 저장과 다르게 풀었음
        // 경로 탈출 검증: 디렉토리만 realpath 후 base 안에 있는지 확인
        $newDir = dirname($newRealFile);
        $realNewDir = realpath($newDir);
        if (!$realNewDir || !\isSubPath($realNewDir, $realBase)) {
            echo json_encode(['success' => false, 'error' => 'Path traversal denied']); exit;
        }
        // 덮어쓰기 방지 (force=1 이면 덮어쓰기 허용)
        $forceOverwrite = !empty($_GET['force']);
        if (!$forceOverwrite && file_exists($newRealFile)) {
            echo json_encode(['success' => false, 'error' => '같은 이름의 파일이 이미 있습니다', 'exists' => true, 'name' => $newName]); exit;   // ★ (2026-11-09 19:50, 60차 Z17) 위 원격과 같음 — 정리된 실제 이름
        }
        // 덮어쓰기 시에도 파일이 base 디렉토리 내부인지 확인
        if ($forceOverwrite && file_exists($newRealFile)) {
            $realExisting = realpath($newRealFile);
            if (!$realExisting || !\isSubPath($realExisting, $realBase)) {
                echo json_encode(['success' => false, 'error' => 'Path traversal denied (overwrite target)']); exit;
            }
        }
        // ★ (2026-10-29 21:46, 24차 점검) 용량 확인·사용량 반영(위 '저장'과 같음) — 종전엔 '다른 이름으로 저장'을 반복하면 한도와 상관없이 새 파일이 계속 생겼음
        $rwOld = file_exists($newRealFile) ? (int)@filesize($newRealFile) : 0;
        // ★ (2026-11-04 00:19, 53차 재검토) '다른 이름으로 저장'의 덮어쓰기(force)도 대량 덮어쓰기 감지에 셈('저장'과 같음, 검토 발견)
        if ($rwOld > 0 && empty(($rwBulk = (new FileManager())->checkBulkOperationPublic('overwrite'))['allowed'])) {
            echo json_encode(['success' => false, 'error' => (string)($rwBulk['reason'] ?? '')]); exit;
        }
        $rwNeed = strlen($rawData) - $rwOld;
        if ($rwNeed > 0) {
            $rwQ = (new FileManager())->checkQuotaPublic($storageId, $rwNeed);
            if (empty($rwQ['allowed'])) { echo json_encode(['success' => false, 'error' => $rwQ['error'] ?? 'Quota exceeded']); exit; }
        }
        // 원자적 쓰기
        $tempFile = $newRealFile . '.rhwp_tmp_' . getmypid() . '_' . bin2hex(random_bytes(6));   // ★ (2026-11-01 01:11, 35차 전체 점검) 임의 꼬리(위와 같음)
        $written = file_put_contents($tempFile, $rawData);
        if ($written === false) {
            @unlink($tempFile);
            echo json_encode(['success' => false, 'error' => 'Write failed']); exit;
        }
        // ★ (2026-11-01 09:42, 38차 점검) 덮어쓰기('같은 이름 있음' 확인 후 force)일 때 이전 버전 남기기(위 '저장'과 같음)
        if ($forceOverwrite && file_exists($newRealFile)) (new FileManager())->saveFileVersionPublic($newRealFile, trim(str_replace('\\', '/', (string)$newFilePath), '/'), (int)$storageId);
        if (!rename($tempFile, $newRealFile)) {
            @unlink($tempFile);
            echo json_encode(['success' => false, 'error' => 'Rename failed']); exit;
        }
        if ($written - $rwOld !== 0) $storage->updateUsedSize($storageId, $written - $rwOld);
        // ★ (2026-10-29 23:24, 24차 점검) 파일 인덱스에도(다른 새 파일 경로와 같게) — 종전엔 다른 이름으로 저장한 파일이 인덱스 재구축 전까지 검색에 안 나왔음(3회차 재검토)
        try { $rwFi = FileIndex::getInstance(); if ($rwFi->isAvailable()) { clearstatcache(true, $newRealFile); $rwFi->addFile((int)$storageId, trim(str_replace('\\', '/', (string)$newFilePath), '/'), ['is_dir' => 0, 'size' => (int)$written, 'modified' => date('Y-m-d H:i:s', (int)@filemtime($newRealFile))]); } } catch (\Throwable $e) {}
        echo json_encode(['success' => true, 'size' => $written, 'path' => $newFilePath, 'name' => $newName]);
    }
    exit;
}
// ★ (2026-10-26) 화면 HTML 의 이모지 → 선 그림 아이콘(index.php 와 같은 방식). 여기(화면 출력 직전)부터만 — 위쪽 파일 전송·오류 응답은 이미 exit
ob_start('fs_icon_convert_html');
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
          // ★ (2026-11-10 07:24, 61차 E) 아래 import 는 studio 와 별개 모듈이라 WASM 이 초기화 안 돼 version() 이 던지고 catch 로 묻혀 칸이 늘 비었음 — studio 초기화 로그에 든 '(rhwp X.Y.Z)' 를 먼저 씀(없으면 종전대로 아래 시도)
          try { const _rv = /\(rhwp ([^)\s]+)\)/.exec(arguments[0]); const _re = document.getElementById('rhwp-editor-version'); if (_rv && _re) _re.textContent = 'rhwp v' + _rv[1]; } catch(e) {}
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
        // ★ (2026-11-04 01:55, 54차 점검) 실제로 문서를 불러왔다는 표시(studio 가 문서를 읽어 들일 때 찍는 로그, 암호 문서 포함). 아래 rhwpOpenFailedNow 에서 '열기 실패 신호'보다 우선함
        // ★ (2026-11-04 02:02, 54차 점검 재수정) 이 로그만으로 저장을 허용하던 것을 바꿈 — 로그 글자가 다음 rhwp 판에서 바뀌면 정상 저장이 전부 막힐 수 있어서, 이제는 확실한 실패 신호가 있을 때만 막음(판단 못 하면 저장)
        if (arguments[0].indexOf('[WasmBridge] 문서 로드') !== -1) window.__rhwpServerDocOpened = true;
        // ★ (2026-11-09 09:16, rhwp 0.9.0 업그레이드) 0.9.0 은 위 '[WasmBridge] 문서 로드' 로그를 없앰(문서 교체 함수에서 삭제) — 그대로 두면 '열기 실패 신호 뒤에 실제로 열린' 경우
        //   (암호를 늦게 넣어 재시도로 암호창이 2개 → 위 창에서 열고 남은 창을 닫음 등) 문서가 열려 있어도 저장이 막혔음(시험 사본 재현: 0.8.7 저장 진행 / 0.9.0 '문서가 열리지 않아…' 차단).
        //   같은 뜻의 신호로 '문서 초기화 마지막 단계([initDoc] … 완료, 폰트 단계 제외)'를 씀 — 초기화는 '열기'와 '새 문서'에서만 돌고, 새 문서는 바로 앞에 '[WasmBridge] 새 문서 생성'을 찍으므로 그때는 열림으로 치지 않음(0.8.7 판정과 같게)
        if (arguments[0].indexOf('[WasmBridge] 새 문서 생성') !== -1) window.__rhwpNewDocInit = true;
        if (arguments[0].indexOf('[initDoc]') !== -1 && arguments[0].indexOf('완료') !== -1 && arguments[0].indexOf('폰트') === -1) {
          if (window.__rhwpNewDocInit) window.__rhwpNewDocInit = false; else window.__rhwpServerDocOpened = true;
        }
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
  <script type="module" crossorigin src="index-BGsUSgOJ.js?v=<?php echo APP_VERSION; ?>"></script>
  <link rel="stylesheet" crossorigin href="index-Bqn-gcq0.css?v=<?php echo APP_VERSION; ?>">
<style>/* ★ (2026-10-26) 선 그림 아이콘(이모지 대신, 원본 api/FsIcons.php) — 글자색을 따름 */ .fs-ico{width:1.2em;height:1.2em;vertical-align:-0.2em;flex-shrink:0} .fs-ico-ok{color:#157347} .fs-ico-err{color:#c62828} .fs-ico-warn{color:#b45309} .tb-icon-text .fs-ico{display:block;width:18px;height:18px} .sb-highlight-icon .fs-ico{width:1em;height:1em}</style>
</head>
<body>
<?php /* ★ (2026-10-26) 선 그림 아이콘 묶음 + 스크립트가 넣는 이모지도 바꾸는 fs-icons.js (index.php·share.php 와 같음) */ echo fs_icon_sprite(); ?>
<script>window.FS_ICO_EMOJI = <?= fs_icon_map_json() ?>;</script>
<?php /* ★ (2026-10-29 23:42, 24차 점검) 위 <base href="assets/rhwp/studio/"> 때문에 상대 주소가 studio/assets/js/… 로 풀려 404 → 루트 기준 주소로 */ ?>
<script src="<?= htmlspecialchars(rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/')) ?>/assets/js/fs-icons.js?v=<?= substr(md5_file(__DIR__ . '/assets/js/fs-icons.js'), 0, 10) ?>"></script>
<?php /* ★ (2026-11-18 21:29, 349차 펜닐 요청 "웹 폰트가 아니라 일반 폰트 인식, 특정 경로에 넣어도 인식") 설치 글꼴 확인·서버 글꼴 폴더(rhwp_fonts.php) 도우미 — 위 <base> 때문에 루트 기준 주소 */ ?>
<script src="<?= htmlspecialchars(rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/')) ?>/assets/js/fs-rhwp-fonts.js?v=<?= substr(md5_file(__DIR__ . '/assets/js/fs-rhwp-fonts.js'), 0, 10) ?>"></script>
  <div id="studio-root">
    <!-- 메뉴바 -->
    <div id="menu-bar">
      <!-- ── 파일 ── -->
      <div class="menu-item" data-menu="file">
        <span class="menu-title">파일</span>
        <div class="menu-dropdown">
          <div class="md-item disabled" data-cmd="file:new-doc"><span class="md-icon icon-new-doc"></span><span class="md-label">새로 만들기</span></div>
          <div class="md-item" data-cmd="file:open"><span class="md-icon"></span><span class="md-label">열기</span></div><div class="md-sub" data-recent>
            <span class="md-icon"></span><span class="md-label">최근 문서</span><span class="md-arrow">▶</span>
            <div class="md-sub-panel" id="recent-docs-panel">
              <div class="md-item disabled"><span class="md-icon"></span><span class="md-label">(최근 문서 없음)</span></div>
            </div>
          </div>
          <div class="md-item" data-cmd="file:save"><span class="md-icon icon-save"></span><span class="md-label">저장</span><span class="md-shortcut">Ctrl+S</span></div>
          <div class="md-item" data-cmd="file:save-as"><span class="md-icon icon-save"></span><span class="md-label">다른 이름으로 저장</span><span class="md-shortcut">Ctrl+Shift+S</span></div><div class="md-item disabled" data-cmd="file:print-to-pdf" title="브라우저 인쇄 창에서 ‘대상 → PDF로 저장’을 선택합니다."><span class="md-icon icon-pdf"></span><span class="md-label">PDF로 저장…</span></div><div class="md-item disabled" data-cmd="file:save-as-hwp"><span class="md-icon"></span><span class="md-label">HWP 형식으로 저장...</span></div><div class="md-item disabled" data-cmd="file:save-as-hwpx"><span class="md-icon"></span><span class="md-label">HWPX 형식으로 저장...</span></div><div class="md-item disabled" data-cmd="file:export-html"><span class="md-icon"></span><span class="md-label">HTML로 내보내기</span></div><div class="md-item disabled" data-cmd="file:export-doc" title="Word가 열 수 있는 HTML 기반 .doc 문서로 내보냅니다."><span class="md-icon"></span><span class="md-label">Word 문서(.doc)로 내보내기</span></div>
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
          <div class="md-item disabled" data-cmd="edit:format-copy"><span class="md-icon icon-format-copy"></span><span class="md-label">모양 복사</span><span class="md-shortcut">Ctrl+Alt+C</span></div><div class="md-item disabled" data-cmd="edit:format-paste"><span class="md-icon icon-format-copy"></span><span class="md-label">모양 붙여넣기</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="edit:delete"><span class="md-icon icon-delete"></span><span class="md-label">지우기</span><span class="md-shortcut">Ctrl+E</span></div>
          <div class="md-item disabled" data-cmd="edit:select-all"><span class="md-icon icon-select-all"></span><span class="md-label">모두 선택</span><span class="md-shortcut">Ctrl+A</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="edit:find"><span class="md-icon icon-find"></span><span class="md-label">찾기(F)</span><span class="md-shortcut">Ctrl+F</span></div><div class="md-item" data-cmd="edit:compare-documents"><span class="md-icon"></span><span class="md-label">문서 비교</span><span class="md-shortcut">Alt+Shift+V</span></div><div class="md-item" data-cmd="edit:document-history"><span class="md-icon"></span><span class="md-label">문서 이력 관리</span><span class="md-shortcut">Ctrl+Shift+H</span></div>
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
          <div class="md-item" data-cmd="view:zoom-out"><span class="md-icon icon-zoom-menu-out"></span><span class="md-label">축소</span><span class="md-shortcut">Shift+Num -</span></div><div class="md-item" data-cmd="view:zoom-dialog"><span class="md-icon"></span><span class="md-label">화면 확대/축소...</span></div>
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
              <div class="md-item" data-cmd="view:zoom-300"><span class="md-icon"></span><span class="md-label">300%</span></div><div class="md-item" data-cmd="view:zoom-500"><span class="md-icon"></span><span class="md-label">500%</span></div>
            </div>
          </div>
          <div class="md-sep"></div><div class="md-sub">
            <span class="md-label">테마</span><span class="md-arrow">▶</span>
            <div class="md-sub-panel">
              <div class="md-item" role="menuitemradio" data-cmd="view:theme-system" data-theme-mode-choice="system"><span class="md-icon"></span><span class="md-label">시스템 설정</span></div>
              <div class="md-item" role="menuitemradio" data-cmd="view:theme-light" data-theme-mode-choice="light"><span class="md-icon"></span><span class="md-label">밝게</span></div>
              <div class="md-item" role="menuitemradio" data-cmd="view:theme-dark" data-theme-mode-choice="dark"><span class="md-icon"></span><span class="md-label">어둡게</span></div>
              <div class="md-sep"></div>
              <div class="md-item" role="menuitemradio" data-cmd="view:skin-oldschool" data-theme-skin-choice="oldschool"><span class="md-icon"></span><span class="md-label">올드스쿨</span></div>
              <div class="md-item" role="menuitemradio" data-cmd="view:skin-default" data-theme-skin-choice="default"><span class="md-icon"></span><span class="md-label">클래식</span></div>
              <div class="md-item" role="menuitemradio" data-cmd="view:skin-flat" data-theme-skin-choice="flat"><span class="md-icon"></span><span class="md-label">모던</span></div>
            </div>
          </div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="view:ctrl-mark"><span class="md-icon icon-ctrl-mark"></span><span class="md-label">조판 부호</span><span class="md-shortcut">Ctrl+G+C</span></div>
          <div class="md-item" data-cmd="view:para-mark"><span class="md-icon icon-para-mark"></span><span class="md-label">문단 부호</span></div>
          <div class="md-item" data-cmd="view:border-transparent"><span class="md-icon"></span><span class="md-label">투명 선</span></div>
          <div class="md-item" data-cmd="view:toggle-clip"><span class="md-icon"></span><span class="md-label">잘림 보기</span></div><div class="md-item" data-cmd="view:toggle-grid"><span class="md-icon icon-grid"></span><span class="md-label">격자 보기</span></div>
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
          <div class="md-item" data-cmd="insert:textbox"><span class="md-icon icon-textbox"></span><span class="md-label">글상자</span></div><div class="md-item" data-cmd="insert:equation"><span class="md-icon"></span><span class="md-label">수식</span><span class="md-shortcut">Ctrl+N,M</span></div>
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
          <div class="md-item disabled" data-cmd="insert:endnote"><span class="md-icon icon-endnote"></span><span class="md-label">미주</span></div><div class="md-item" data-cmd="insert:endnote-shape"><span class="md-icon icon-endnote"></span><span class="md-label">미주 모양</span></div>
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
        <div class="menu-dropdown"><div class="md-item" data-cmd="format:bold"><span class="md-icon"></span><span class="md-label">진하게</span><span class="md-shortcut">Ctrl+B</span></div><div class="md-item" data-cmd="format:italic"><span class="md-icon"></span><span class="md-label">기울임</span><span class="md-shortcut">Ctrl+I</span></div><div class="md-item" data-cmd="format:underline"><span class="md-icon"></span><span class="md-label">밑줄</span><span class="md-shortcut">Ctrl+U</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="format:char-shape"><span class="md-icon icon-char-shape"></span><span class="md-label">글자 모양</span><span class="md-shortcut">Alt+L</span></div>
          <div class="md-item disabled" data-cmd="format:para-shape"><span class="md-icon icon-para-shape"></span><span class="md-label">문단 모양</span><span class="md-shortcut">Alt+T</span></div>
          <div class="md-sep"></div>
          <div class="md-item disabled" data-cmd="format:para-num-shape"><span class="md-icon"></span><span class="md-label">문단 번호 모양</span></div>
          <div class="md-item disabled" data-cmd="format:bullet-shape"><span class="md-icon"></span><span class="md-label">글머리표 모양</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="format:level-increase"><span class="md-icon"></span><span class="md-label">한 수준 증가</span><span class="md-shortcut">Ctrl+Num -</span></div>
          <div class="md-item" data-cmd="format:level-decrease"><span class="md-icon"></span><span class="md-label">한 수준 감소</span><span class="md-shortcut">Ctrl+Num +</span></div>
          <!-- ★ (2026-11-15 03:58, 160차) 원본 0.9.0 index.html 순서·구분선대로 — 글자 크기 크게·왼쪽 정렬·줄 간격 늘림이 맨 아래로 밀리고 구분선 4개가 빠져 있었음(재현) -->
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="format:font-size-increase"><span class="md-icon"></span><span class="md-label">글자 크기 크게</span><span class="md-shortcut">Alt+Shift+E</span></div>
          <div class="md-item" data-cmd="format:font-size-decrease"><span class="md-icon"></span><span class="md-label">글자 크기 작게</span><span class="md-shortcut">Alt+Shift+R</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="format:align-left"><span class="md-icon"></span><span class="md-label">왼쪽 정렬</span><span class="md-shortcut">Ctrl+Shift+L</span></div>
          <div class="md-item" data-cmd="format:align-center"><span class="md-icon"></span><span class="md-label">가운데 정렬</span><span class="md-shortcut">Alt+Shift+C</span></div>
          <div class="md-item" data-cmd="format:align-right"><span class="md-icon"></span><span class="md-label">오른쪽 정렬</span><span class="md-shortcut">Alt+Shift+H</span></div>
          <div class="md-item" data-cmd="format:align-justify"><span class="md-icon"></span><span class="md-label">양쪽 정렬</span><span class="md-shortcut">Ctrl+Shift+M</span></div>
          <div class="md-item" data-cmd="format:align-distribute"><span class="md-icon"></span><span class="md-label">배분 정렬</span><span class="md-shortcut">Alt+Shift+D</span></div>
          <div class="md-sep"></div>
          <div class="md-item" data-cmd="format:line-spacing-increase"><span class="md-icon"></span><span class="md-label">줄 간격 늘림</span><span class="md-shortcut">Alt+Shift+Z</span></div>
          <div class="md-item" data-cmd="format:line-spacing-decrease"><span class="md-icon"></span><span class="md-label">줄 간격 줄임</span><span class="md-shortcut">Alt+Shift+A</span></div>
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
          <div class="md-item disabled" data-cmd="page:setup"><span class="md-icon icon-page-setup"></span><span class="md-label">편집 용지</span><span class="md-shortcut">F7</span></div><div class="md-item disabled" data-cmd="page:page-border"><span class="md-icon"></span><span class="md-label">쪽 테두리/배경</span></div>
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
          <div class="md-sep"></div><div class="md-item disabled" data-cmd="table:insert-row-col"><span class="md-icon"></span><span class="md-label">줄/칸 추가하기(I)...</span><span class="md-shortcut">Alt+Enter</span></div>
          <!-- ★ (2026-11-15 03:58, 160차) 원본 0.9.0 처럼 줄/칸 추가하기 바로 다음 — 각 셀마다/하나의 셀처럼 적용 옆에 잘못 들어가 있었음(재현) -->
          <div class="md-item disabled" data-cmd="table:delete-row-col"><span class="md-icon"></span><span class="md-label">줄/칸 지우기(E)...</span><span class="md-shortcut">Alt+Delete</span></div>
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
          <div class="md-item disabled" data-cmd="table:cell-width-equal"><span class="md-icon"></span><span class="md-label">셀 너비를 같게</span><span class="md-shortcut">W</span></div><div class="md-item disabled" data-cmd="table:transpose-copy"><span class="md-icon"></span><span class="md-label">행/열 바꿈 복사</span></div><div class="md-item disabled" data-cmd="table:transpose-paste"><span class="md-icon"></span><span class="md-label">행/열 바꿈 붙여넣기</span></div>
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
          <?php /* ★ (2026-11-18 21:53, 350차 펜닐 요청 "폰트 위치 설정 변경, 메뉴에 폰트 위치 지정(편집기에서 설정하면 뷰어는 자동)") 관리자(또는 '시스템 설정' 권한 부관리자)만 보임 — 처리는 아래 fsFontFolderDialog */ ?>
          <?php /* ★ (2026-11-18 22:17, 351차 펜닐 지적 "비활성화(회색)인데 클릭됨 / 어디어디에서 폰트를 가져다 쓰고 활성화 여부도 안 나옴") — rhwp 는 data-cmd 가 있는 메뉴 항목 중 자기가 모르는 명령을 회색(disabled)으로 바꿈 → data-fs-cmd 로 */ ?>
          <?php if ($auth->isAdmin() || ($auth->isSubAdmin() && $auth->hasAdminPerm('system_settings'))): ?><div class="md-item" data-fs-cmd="font-folders"><span class="md-icon"></span><span class="md-label"><?= htmlspecialchars(__('rf_menu', '글꼴 폴더 설정')) ?></span></div><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- 아이콘 툴바 -->
    <!-- 아이콘 툴바 -->
    <!-- ★ (2026-09-03) rhwp 0.8.6 studio 의 스크롤 뷰포트 구조로 감쌈.
         [왜 필수인가] 0.8.6 CSS 에서 #icon-toolbar 가
         `flex-wrap:wrap; height:auto; overflow:visible` → **`nowrap; height:56px; overflow:hidden`**
         으로 바뀌었다(전역 규칙, 되돌리는 오버라이드 없음). 0.8.6 studio 는
         .tb-scroll-viewport / .tb-scroll-track 과 «/» 버튼으로 가로 스크롤을 제공하는데
         이 마크업엔 그게 없어서, 한 줄에 약 1,700px(버튼 36개 min-width 44~56px + 구분자 10개)를
         요구하는 우리 툴바가 **그보다 좁은 화면에서 잘리고 스크롤 수단도 없는** 상태가 된다.
         0.8.4 까지는 wrap 이라 2줄로 접혀 문제가 없었다.
         또 JS 가 머리말/꼬리말 모드에서 숨길 대상을
         `#icon-toolbar .tb-scroll-track > .tb-group` 으로 고르므로 track 이 없으면 0개가 잡힌다.
         [display:none → hidden] 0.8.6 JS 는 그룹 표시를 `el.hidden = ...` 으로 제어한다.
         인라인 style 이 hidden 속성을 이기므로 style="display:none" 을 두면
         회전 도구·머리말/꼬리말 도구가 켜져도 영영 나타나지 않는다.
         [보존] 버튼 내용물은 한 줄도 바꾸지 않았다 — FileStation 이 일부러 뺀 data-cmd
         (모양 복사·격자 보기·미주), 축소한 찾기 메뉴, file-input 의 .hwp/.hwpx 제한 그대로.
         출처: github.com/edwardkim/rhwp v0.8.6 태그의 rhwp-studio/index.html -->
    <div id="icon-toolbar" role="region" aria-label="기본 도구 상자">
      <button type="button" id="icon-toolbar-prev" class="tb-scroll-nav" aria-controls="icon-toolbar-viewport" aria-label="이전 도구 그룹" title="이전 도구 그룹" hidden aria-disabled="true" aria-hidden="true" tabindex="-1">
        <span class="tb-scroll-nav-icon" aria-hidden="true">«</span>
      </button>
      <div id="icon-toolbar-viewport" class="tb-scroll-viewport" role="group" aria-label="기본 도구 상자 명령" tabindex="0">
        <div class="tb-scroll-track">
          <div class="tb-group">
            <button class="tb-btn" title="오려두기 (Ctrl+X)"><span class="tb-sprite icon-cut"></span><span class="tb-label">오려<br/>두기</span></button>
            <button class="tb-btn" title="복사하기 (Ctrl+C)"><span class="tb-sprite icon-copy"></span><span class="tb-label">복사<br/>하기</span></button>
            <button class="tb-btn tb-paste" title="붙이기 (Ctrl+V)">
              <span class="tb-sprite icon-paste"></span><span class="tb-label">붙이기</span>
            </button>
            <button class="tb-btn" data-cmd="edit:format-copy" title="모양 복사 (Alt+C)"><span class="tb-sprite icon-format-copy"></span><span class="tb-label">모양<br/>복사</span></button>
          </div>
          <span class="tb-sep"></span>
          <div class="tb-group">
            <button class="tb-btn" data-cmd="view:ctrl-mark" title="조판 부호"><span class="tb-sprite icon-ctrl-mark"></span><span class="tb-label">조판<br/>부호</span></button>
            <button class="tb-btn" data-cmd="view:para-mark" title="문단 부호"><span class="tb-sprite icon-para-mark"></span><span class="tb-label">문단<br/>부호</span></button>
            <button class="tb-btn" data-cmd="view:toggle-grid" title="격자 보기"><span class="tb-sprite icon-grid"></span><span class="tb-label">격자<br/>보기</span></button>
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
            <button class="tb-btn" data-cmd="insert:hyperlink" title="하이퍼링크"><span class="tb-sprite icon-hyperlink"></span><span class="tb-label">하이퍼<br/>링크</span></button>
          </div>
          <span class="tb-sep"></span>
          <div class="tb-group tb-rotate-group" hidden>
            <button class="tb-btn" data-cmd="insert:rotate-ccw" title="왼쪽 90° 회전"><span class="tb-icon-text">&#x21B6;</span><span class="tb-label">왼쪽<br/>회전</span></button>
            <button class="tb-btn" data-cmd="insert:rotate-cw" title="오른쪽 90° 회전"><span class="tb-icon-text">&#x21B7;</span><span class="tb-label">오른쪽<br/>회전</span></button>
            <button class="tb-btn" data-cmd="insert:flip-horz" title="좌우 대칭"><span class="tb-icon-text">&#x21C4;</span><span class="tb-label">좌우<br/>대칭</span></button>
            <button class="tb-btn" data-cmd="insert:flip-vert" title="상하 대칭"><span class="tb-icon-text">&#x21C5;</span><span class="tb-label">상하<br/>대칭</span></button>
            <!-- ★ (2026-11-15 03:46, 160차) 원본 rhwp-studio(0.9.0 index.html)처럼 이 구분선은 회전 묶음 안 — 밖에 있어 그림을 안 골랐을 때(묶음 숨김) 하이퍼링크와 머리말 사이에 구분선이 두 줄 보였음(재현) -->
            <span class="tb-sep"></span>
          </div>
          <div class="tb-group">
            <button class="tb-btn" data-cmd="page:header-create" title="머리말"><span class="tb-sprite icon-header"></span><span class="tb-label">머리말</span></button>
            <button class="tb-btn" data-cmd="page:footer-create" title="꼬리말"><span class="tb-sprite icon-footer"></span><span class="tb-label">꼬리말</span></button>
            <button class="tb-btn" data-cmd="insert:footnote" title="각주"><span class="tb-sprite icon-footnote"></span><span class="tb-label">각주</span></button>
            <button class="tb-btn" data-cmd="insert:endnote" title="미주"><span class="tb-sprite icon-endnote"></span><span class="tb-label">미주</span></button>
            <div class="tb-split">
              <button class="tb-btn tb-split-main" title="찾기 (Ctrl+F)" data-cmd="edit:find"><span class="tb-sprite icon-find"></span><span class="tb-label">찾기</span></button>
              <button class="tb-btn tb-split-arrow" title="찾기 메뉴"><span class="tb-arrow-icon">▾</span></button>
              <div class="tb-split-menu">
                <div class="tb-split-item" data-cmd="edit:find">찾기(F) <span class="tb-split-shortcut">Ctrl+F</span></div><button type="button" class="tb-split-item" role="menuitem" data-cmd="edit:compare-documents">문서 비교 <span class="tb-split-shortcut">Alt+Shift+V</span></button><button type="button" class="tb-split-item" role="menuitem" data-cmd="edit:document-history">문서 이력 관리 <span class="tb-split-shortcut">Ctrl+Shift+H</span></button>
                <div class="tb-split-item" data-cmd="edit:find-replace">찾아 바꾸기(E) <span class="tb-split-shortcut">Ctrl+F2</span></div>
                <div class="tb-split-item" data-cmd="edit:find-again">다시 찾기(X) <span class="tb-split-shortcut">Ctrl+L</span></div>
                <div class="tb-split-sep"></div>
                <div class="tb-split-item" data-cmd="edit:goto">찾아가기(G) <span class="tb-split-shortcut">Alt+G</span></div>
              </div>
            </div><button class="tb-btn" data-cmd="edit:document-history" title="문서 이력 관리 (Ctrl+Shift+H)"><span class="tb-icon-text">⧉</span><span class="tb-label">이력<br/>관리</span></button><button class="tb-btn" data-cmd="edit:compare-documents" title="문서 비교 (Alt+Shift+V)"><span class="tb-icon-text">≍</span><span class="tb-label">문서<br/>비교</span></button>
          </div>
          <!-- 머리말/꼬리말 편집 모드 전용 도구상자 (숨김) -->
          <div class="tb-group tb-headerfooter-group" hidden>
            <span class="tb-hf-label">머리말</span>
            <span id="hf-edit-status-live" class="visually-hidden" aria-live="polite"></span>
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
          </div><div class="tb-group tb-mode-group tb-note-group" hidden>
        <span class="tb-note-label">주석</span>
        <span class="tb-sep"></span>
        <button class="tb-btn" data-cmd="insert:footnote" title="각주"><span class="tb-sprite icon-footnote"></span><span class="tb-label">각주</span></button>
        <button class="tb-btn" data-cmd="insert:endnote" title="미주"><span class="tb-sprite icon-endnote"></span><span class="tb-label">미주</span></button>
        <button class="tb-btn" data-cmd="insert:endnote-shape" title="각주/미주 모양"><span class="tb-sprite icon-endnote"></span><span class="tb-label">각주/미주<br/>모양</span></button>
        <span class="tb-sep"></span>
        <button class="tb-btn tb-note-close" data-cmd="insert:note-close" title="닫기 (Shift+Esc)"><span class="tb-icon-text">×</span><span class="tb-label">닫기</span></button>
      </div>
        </div>
      </div>
      <button type="button" id="icon-toolbar-next" class="tb-scroll-nav" aria-controls="icon-toolbar-viewport" aria-label="다음 도구 그룹" title="다음 도구 그룹" hidden aria-disabled="true" aria-hidden="true" tabindex="-1">
        <span class="tb-scroll-nav-icon" aria-hidden="true">»</span>
      </button>
      <!-- 파일 열기 (숨김) -->
      <input type="file" id="file-input" accept=".hwp,.hwpx" style="display:none" />
    </div>

    <!-- 서식 도구 모음 (style bar) -->
    <!-- ★ (2026-08-24) rhwp 0.8.4 studio 의 style-bar 마크업으로 교체.
         [문제] 상단 서식 도구 모음이 2줄로 밀리고 그룹 라벨(글꼴 및 간격·글자 모양·색·문단)이
         사라져 보였다. 원인은 자산이 아니라 **마크업 불일치**였다 —
         0.8.4 studio 는 툴바를 rhwp-studio/index.html 에 하드코딩해 두는데,
         FileStation 은 그 파일을 쓰지 않고 이 PHP 가 자체 마크업을 만들어
         studio 의 JS·CSS 만 로드한다. 그래서 CSS 는 0.8.4 의 리본 구조
         (sb-ribbon-group / sb-field-grid / sb-command-band / sb-ribbon-label)를 전제하는데
         이 PHP 의 마크업은 옛 구조 그대로여서 스타일이 걸리지 않았다.
         [확인] 코어 자산은 정상 — rhwp.js·rhwp_bg.wasm 이 npm @rhwp/core@0.8.4 원본과 md5 일치.
         [안전성] 교체 전후 **요소 id 32개가 완전히 동일**(사라짐 0·신규 0)해 JS 바인딩 영향 없음.
         출처: github.com/edwardkim/rhwp v0.8.4 태그의 rhwp-studio/index.html
         ★ (2026-09-03) 0.8.6 로 올리며 .sb-command-track 래퍼와
         .sb-overflow-host > #btn-style-overflow + #style-overflow-panel 을 추가했다.
         정렬 버튼 6개는 순서·id·title 그대로 패널 안으로 옮기기만 했다(삭제·수정 0).
         CSS 상 이 호스트는 전역에서 display:contents 라 넓은 화면은 종전과 같은 평면 배치이고,
         (width<=459px) 와 (808px~961px) 구간에서만 실제 드롭다운이 된다. -->
    <div id="style-bar">
          <div class="sb-ribbon-group sb-field-ribbon-group">
            <div class="sb-field-grid">
              <!-- 스타일 -->
              <span class="sb-field">
                <label class="sb-field-label" for="style-name">스타일</label>
                <select id="style-name" class="sb-combo" title="스타일">
                  <option value="0">바탕글</option>
                </select>
              </span>
              <!-- 글꼴 언어 카테고리 -->
              <span class="sb-field">
                <label class="sb-field-label" for="font-lang">언어</label>
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
              </span>
              <!-- 글꼴 -->
              <span class="sb-field">
                <label class="sb-field-label" for="font-name">글꼴</label>
                <select id="font-name" class="sb-combo sb-font" title="글꼴">
                  <option value="함초롬바탕">함초롬바탕</option>
                  <option value="함초롬돋움">함초롬돋움</option>
                  <option value="맑은 고딕">맑은 고딕</option>
                  <option value="나눔고딕">나눔고딕</option>
                  <option value="바탕">바탕</option>
                  <option value="돋움">돋움</option>
                  <option value="궁서">궁서</option>
                </select>
              </span>
              <!-- 글자 크기 -->
              <span class="sb-field">
                <label class="sb-field-label" for="font-size">크기</label>
                <span class="sb-size-group">
                  <input id="font-size" type="text" class="sb-size" title="글자 크기 (pt)" value="10.0" />
                  <span class="sb-size-unit">pt</span>
                  <span class="sb-size-arrows">
                    <button id="btn-size-up" class="sb-arrow" title="크기 크게">▲</button>
                    <button id="btn-size-down" class="sb-arrow" title="크기 작게">▼</button>
                  </span>
                </span>
              </span>
              <!-- 줄 간격 -->
              <span class="sb-field">
                <label class="sb-field-label" for="linespacing-select">줄 간격</label>
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
              </span>
            </div>
            <span class="sb-ribbon-label">글꼴 및 간격</span>
          </div>

          <div class="sb-command-track">
          <div class="sb-command-band sb-character-band">
            <div class="sb-ribbon-group sb-character-ribbon-group">
              <div class="sb-command-group sb-character-group">
                <!-- 글자 서식 -->
                <button id="btn-bold" class="sb-btn" title="굵게 (Ctrl+B)"><span class="sb-ga sb-bold">가</span></button>
                <button id="btn-italic" class="sb-btn" title="기울임 (Ctrl+I)"><span class="sb-ga sb-italic">가</span></button>
                <button id="btn-underline" class="sb-btn" title="밑줄 (Ctrl+U)"><span class="sb-ga sb-underline">간</span></button>
                <button id="btn-strike" class="sb-btn" title="취소선"><span class="sb-ga sb-strike">가</span></button>
                <div class="sb-dropdown" id="charfx-dropdown">
                  <button class="sb-btn sb-has-arrow" id="btn-charfx" title="글자 효과">
                    <span class="sb-ga sb-effect-icon" id="charfx-icon">가</span><span class="sb-dd">▾</span>
                  </button>
                  <div class="sb-dropdown-menu" id="charfx-menu">
                    <button class="sb-dropdown-item" data-format="emboss"><span class="sb-ga sb-emboss">가</span>양각</button>
                    <button class="sb-dropdown-item" data-format="engrave"><span class="sb-ga sb-engrave">가</span>음각</button>
                    <button class="sb-dropdown-item" data-format="outline"><span class="sb-ga sb-outline">가</span>외곽선</button>
                    <button class="sb-dropdown-item" data-format="superscript"><span class="sb-ga sb-sup">가</span>위 첨자</button>
                    <button class="sb-dropdown-item" data-format="subscript"><span class="sb-ga sb-sub">가</span>아래 첨자</button>
                  </div>
                </div>
              </div>
              <span class="sb-ribbon-label">글자 모양</span>
            </div>
            <div class="sb-ribbon-group sb-color-ribbon-group">
              <div class="sb-command-group sb-color-group">
                <!-- 글자색 -->
                <span class="sb-color-wrap">
                  <button id="btn-text-color" class="sb-btn sb-has-arrow" title="글자 색">
                    <span class="sb-color-visual"><span class="sb-ga">가</span><span id="color-bar"></span></span>
                    <span class="sb-dd">▾</span>
                  </button>
                  <input id="text-color-picker" type="color" value="#000000" aria-label="글자 색 선택" />
                </span>
                <!-- 형광펜 -->
                <span id="highlight-dropdown" class="sb-dropdown">
                  <button id="btn-highlight" class="sb-btn sb-has-arrow" title="형광펜">
                    <span class="sb-highlight-visual"><span class="sb-highlight-icon">✏</span><span id="highlight-bar"></span></span>
                    <span class="sb-dd">▾</span>
                  </button>
                  <div id="highlight-palette" class="sb-hl-palette"></div>
                </span>
              </div>
              <span class="sb-ribbon-label">색</span>
            </div>
          </div>

          <div class="sb-overflow-host">
            <button type="button" id="btn-style-overflow" class="sb-btn sb-has-arrow" aria-controls="style-overflow-panel" aria-expanded="false" aria-label="문단 정렬 더보기" title="문단 정렬 더보기">
              <span id="style-overflow-current-icon" class="sb-align sb-al-left sb-overflow-current-icon" aria-hidden="true"></span><span class="sb-dd" aria-hidden="true">▾</span>
            </button>
            <div id="style-overflow-panel" class="sb-overflow-panel" role="group" aria-label="문단 정렬">
          <div class="sb-command-band sb-paragraph-band">
            <div class="sb-ribbon-group sb-paragraph-ribbon-group">
              <div class="sb-command-group sb-align-group">
                <!-- 문단 정렬 -->
                <button id="btn-align-left" class="sb-btn" title="왼쪽 정렬"><span class="sb-align sb-al-left"></span></button>
                <button id="btn-align-center" class="sb-btn" title="가운데 정렬"><span class="sb-align sb-al-center"></span></button>
                <button id="btn-align-right" class="sb-btn" title="오른쪽 정렬"><span class="sb-align sb-al-right"></span></button>
                <button id="btn-align-justify" class="sb-btn" title="양쪽 정렬"><span class="sb-align sb-al-justify"></span></button>
                <button id="btn-align-distribute" class="sb-btn" title="배분 정렬"><span class="sb-align sb-al-distribute"></span></button>
                <button id="btn-align-split" class="sb-btn" title="나눔 정렬"><span class="sb-align sb-al-split"></span></button>
              </div>
              <span class="sb-ribbon-label">문단</span>
            </div>
          </div>
            </div>
          </div>
          </div>
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
    <!-- 하단 상태 바 -->
    <!-- ★ (2026-09-03) rhwp 0.8.6 이 요구하는 최소 구조 추가 (#sb-cell-selection, 줌 클러스터).
         [왜 필수인가] 0.8.6 번들의 상태바 초기화 함수는
           r = getElementById('sb-zoom-range'); … r.addEventListener('input', …)
           getElementById('sb-zoom-display').addEventListener('click', …)
         를 **널가드 없이** 실행한다. 두 요소가 없으면 TypeError 로 **상태바 초기화가 통째로 중단**된다.
         #sb-cell-selection 도 cell-selection-phase-changed 핸들러에서 무가드 참조된다(표 셀 선택 시).
         [참고] 옛 .stb-zoom-val 클래스는 0.8.6 CSS 에서 규칙 자체가 삭제됐고,
         대신 #sb-zoom-val **id 규칙**이 스타일을 제공하므로 표시에는 영향이 없다. -->
    <div id="status-bar">
      <!-- ★ (2026-11-15 03:58, 160차) 원본 0.9.0 처럼 누를 수 있는 버튼(눌러 찾아가기·손가락 모양·키보드 초점) — span 이라 눌러지긴 해도 버튼 모양·키보드 이동이 없었음 -->
      <button id="sb-page" class="stb-item stb-page-button" type="button" title="찾아가기 (Alt+G)">1 / 1 쪽</button>
      <span class="stb-divider"></span>
      <span id="sb-section" class="stb-item">구역: 1 / 1</span>
      <span class="stb-divider"></span>
      <span id="sb-mode" class="stb-item">삽입</span>
      <span id="sb-cell-selection" class="stb-item stb-cell-selection" role="status" aria-live="polite" hidden></span>
      <span id="sb-field" class="stb-item" style="display:none"></span>
      <span id="sb-message" class="stb-message"></span>
      <span id="rhwp-editor-version" class="stb-item" style="font-size:10px;color:#888;"></span>
        <span class="stb-right">
        <button id="sb-zoom-fit-width" class="stb-icon-btn" title="폭 맞춤"><span class="tb-sprite icon-zoom-fit-width"></span></button>
        <button id="sb-zoom-fit" class="stb-icon-btn" title="쪽 맞춤"><span class="tb-sprite icon-zoom-fit"></span></button>
        <button id="sb-zoom-out" class="stb-icon-btn" title="축소"><span class="tb-sprite icon-zoom-out"></span></button>
        <span class="stb-zoom-range-wrap">
          <input id="sb-zoom-range" class="stb-zoom-range" type="range" min="0" max="1000" step="1" value="500" aria-label="화면 확대/축소 배율" aria-valuetext="100%">
          <span class="stb-zoom-neutral-mark" aria-hidden="true"></span>
        </span>
        <button id="sb-zoom-in" class="stb-icon-btn" title="확대"><span class="tb-sprite icon-zoom-in"></span></button>
        <button id="sb-zoom-display" class="stb-zoom-display" type="button" title="화면 확대/축소 설정">
          <svg class="stb-zoom-menu-icon" viewBox="0 0 18 18" aria-hidden="true" focusable="false">
            <circle cx="6.5" cy="6.5" r="4.5"></circle>
            <path d="M9.75 9.75 15.25 15.25"></path>
          </svg>
          <span id="sb-zoom-val">100%</span>
        </button>
      </span>
    </div>
  </div>
<script>
// FileStation 연동: 파일 자동 로드
(function() {
    const STREAM_URL = '<?php echo rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/"); ?>/rhwp_editor.php?action=stream&storage_id=<?php echo $storageId; ?>&path=<?php echo rawurlencode($filePath); ?>';
    const FILE_NAME = <?php echo json_encode($fileName, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    // ★ (2026-11-01 09:42, 38차 점검) 문서 받기 실패 안내 문구(아래 DOMContentLoaded 끝)
    // ★ (2026-11-18 21:29, 349차 펜닐 요청 "웹 폰트가 아니라 일반 폰트 인식, 특정 경로에 넣어도 인식")
    //   문서를 넘기기 전에 문서 글꼴 이름을 읽으려고 뷰어와 같은 엔진(rhwp.js + 편집기와 같은 wasm)을 따로 씀 — 서버 글꼴 폴더가 비어 있으면 부르지 않음
<?php
    $_reStudio = __DIR__ . '/assets/rhwp/studio';
    $_reW = glob($_reStudio . '/rhwp_bg-*.wasm') ?: [];
    if (count($_reW) > 1) {   // 업그레이드 잔재로 여러 개면 이 편집기 묶음이 부르는 것(뷰어·cleanup_rhwp_studio.php 와 같은 판정)
        $_reJs = preg_match('/index-[A-Za-z0-9_-]+\.js/', (string)@file_get_contents(__FILE__), $_reM) ? (string)@file_get_contents($_reStudio . '/' . $_reM[0]) : '';
        $_reW = (preg_match('/rhwp_bg-[A-Za-z0-9_-]+\.wasm/', $_reJs, $_reX) && is_file($_reStudio . '/' . $_reX[0])) ? [$_reStudio . '/' . $_reX[0]] : [];
    }
    $_reRoot = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $_reEngineJs = is_file(__DIR__ . '/assets/rhwp/rhwp.js') ? $_reRoot . '/assets/rhwp/rhwp.js?v=' . substr((string)md5_file(__DIR__ . '/assets/rhwp/rhwp.js'), 0, 10) : '';
    $_reEngineWasm = $_reW ? $_reRoot . '/assets/rhwp/studio/' . basename($_reW[0]) : '';
?>
    const FS_RHWP_ENGINE_JS = <?php echo json_encode($_reEngineJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;
    const FS_RHWP_ENGINE_WASM = <?php echo json_encode($_reEngineWasm, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;
    // ★ (2026-11-18 22:49, 353차 펜닐 "글꼴 감지는 자기 PC 것 — 그거 안 해도 글꼴 폴더 설정하면 서버에 폰트 넣어 놓고 운영할 수 있어야")
    //   [재현] 종전엔 처음 여는 Chrome·Edge 에서 rhwp '로컬 글꼴 감지' 창이 뜨고, [대체 글꼴로 보기]를 누르면 서버 글꼴을 받아 놓고도 대체 글꼴로만 그렸음
    //          (rhwp 는 브라우저 저장소 'rhwp-local-fonts' 기록에 있는 이름만 '있는 글꼴'로 씀 — 그 기록은 [로컬 글꼴 감지]를 눌러야 생김)
    //   [수정] 문서가 서버 글꼴 폴더의 글꼴을 쓰면, 그 이름들만 rhwp 형식으로 기록해 둠(fsServerFonts 표시). rhwp 묶음 파일은 그대로
    //          · 사용자가 직접 [로컬 글꼴 감지]로 만든 기록(fsServerFonts 표시 없음)은 절대 안 건드림
    //          · 문서가 서버 글꼴을 안 쓰면(또는 서버 폴더가 비면) 우리 기록만 지움 → 종전과 똑같이 감지 창이 뜸
    function fsRhwpSeedServerFonts(names) {
        const KEY = 'rhwp-local-fonts';
        try {
            let cur = null;
            try { cur = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { cur = null; }
            const ours = !!(cur && cur.fsServerFonts === true);
            if (cur && !ours) return;
            const fam = Array.from(new Set(names || [])).sort();
            if (!fam.length) { if (ours) localStorage.removeItem(KEY); return; }
            localStorage.setItem(KEY, JSON.stringify({ version: 3, source: 'font-presence-probe', detectedAt: new Date().toISOString(),
                families: fam, fontRecords: [], checkedFamilies: fam, probedFamilies: fam, fsServerFonts: true }));
        } catch (e) { /* 저장소를 못 쓰면 종전과 같이 */ }
    }
    async function fsRhwpPrepareFonts(blob) {
        let served = [];
        try {
            if (window.FsRhwpFonts && FS_RHWP_ENGINE_JS && FS_RHWP_ENGINE_WASM) {
                const fl = await FsRhwpFonts.list();
                if (fl.length) {   // 서버 글꼴 폴더가 비면 종전과 똑같이
                    const mod = await import(FS_RHWP_ENGINE_JS);
                    if (!window.__fsRhwpEngineInit) window.__fsRhwpEngineInit = mod.default({ module_or_path: FS_RHWP_ENGINE_WASM });
                    await window.__fsRhwpEngineInit;
                    const d = new mod.HwpDocument(new Uint8Array(await blob.arrayBuffer()));
                    let names = [];
                    try { names = FsRhwpFonts.namesFromPlan(d); } finally { try { d.free(); } catch (e) {} }
                    // 편집기는 문서를 열 때 '이미 받아 둔' 글꼴을 시스템 글꼴처럼 씀(화면 폭 비교) — 그래서 다 받을 때까지 기다린 뒤 문서를 넘김. 설치 글꼴이 있으면 서버 것은 안 받음
                    await FsRhwpFonts.ensure(names);
                    served = await FsRhwpFonts.served(names);
                }
            }
        } catch (e) { served = []; /* 글꼴 준비가 실패해도 문서는 그대로 엶 */ }
        fsRhwpSeedServerFonts(served);
    }

    const LOAD_FAIL_MSG = <?php echo json_encode(__('rhwp_load_failed', '문서를 불러오지 못했습니다'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const LOAD_FAIL_CLOSE = <?php echo json_encode(__('rhwp_load_failed_close', '눌러서 닫기'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    // ★ (2026-11-12 19:20, 102차 I18N-1) 로딩 문구도 화면 언어로(LOAD_FAIL_MSG 와 같은 방식, 한국어 값은 종전 문구 그대로) — 종전엔 영어 화면에서도 '문서 로딩 중...'(재현: pc_rhwp_editor.png)
    const LOADING_MSG_HTML = <?php echo json_encode(htmlspecialchars(__('rhwp_ed_loading', '문서 로딩 중...'), ENT_QUOTES, 'UTF-8'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const LOADING_RETRY_MSG = <?php echo json_encode(__('rhwp_ed_loading_retry', '문서 로딩 중... ({n}/{total})'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    
    if (!FILE_NAME) return; // 파일 경로 없으면 빈 에디터
    const TIMING_URL = '<?php echo rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/"); ?>/rhwp_editor.php?action=timing_log';

    // ★ (2026-08-24) HWP 문서 로딩 단계별 계측 — 순수 관측, 동작 변경 없음.
    //   [배경] "파일을 열면 매번 느리다"는 제보. 에디터 UI(자산)가 아니라 **문서 로딩**이 느리고,
    //   미리보기(뷰어)는 빠르다. 뷰어는 rhwp.js + rhwp_bg.wasm 만 쓰는 반면 에디터는 studio
    //   번들과 **CanvasKit(6.8MB)** 까지 거치므로 경로 자체가 다르다. 또 이 주입 절차에는
    //   문서 크기와 무관한 **고정 지연**(WASM 후 500ms 대기, 완료 감지 100ms 폴링)이 있어
    //   어디서 시간이 드는지 실측 없이는 알 수 없다.
    //   [기록] 브라우저 콘솔 + 서버 data/hwp_timing.log (그 파일이 있을 때만 서버 기록).
    const _t0 = performance.now();
    const _marks = [];
    function _mark(label, extra) {
        const t = performance.now();
        const prev = _marks.length ? _marks[_marks.length - 1].t : _t0;
        const row = { label: label, t: t, delta: Math.round(t - prev), total: Math.round(t - _t0) };
        if (extra) row.extra = extra;
        _marks.push(row);
        try { console.log('[hwp_timing] ' + label + '  +' + row.delta + 'ms  (누적 ' + row.total + 'ms)' + (extra ? '  ' + JSON.stringify(extra) : '')); } catch (e) {}
    }
    function _flushMarks(result) {
        try {
            const body = {
                file: FILE_NAME,
                result: result,
                total_ms: Math.round(performance.now() - _t0),
                marks: _marks.map(function (m) { return { label: m.label, delta_ms: m.delta, total_ms: m.total, extra: m.extra || null }; }),
                ua: navigator.userAgent.slice(0, 120)
            };
            // 서버 기록은 실패해도 무시 — 관측용이라 로딩에 영향을 주지 않는다.
            // ★ 상대경로는 새 탭의 주소 형태에 따라 어긋날 수 있어 STREAM_URL 과 동일하게
            //   서버가 계산한 스크립트 경로를 쓴다(그쪽은 정상 동작이 확인된 방식).
            fetch(TIMING_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                keepalive: true
            }).then(function (r) {
                // ★ 전송 결과를 콘솔에 남긴다 — 서버 로그가 안 생길 때 원인을 바로 알 수 있게.
                //   204 = 정상 수신. 그 외면 URL·권한·로그파일 존재 여부를 확인할 것.
                console.log('[hwp_timing] 서버 전송 status=' + r.status
                    + (r.status === 204 ? ' (정상)' : ' ★ data/hwp_timing.log 파일이 있는지 확인'));
            }).catch(function (e) {
                console.log('[hwp_timing] 서버 전송 실패:', e && e.message);
            });
        } catch (e) {}
    }
    
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
    // ★ (2026-08-24) 대기 상한 30초 → 2초. **문서 로딩이 매번 30초 걸리던 원인.**
    //   [계측으로 확인된 사실] hwp_timing.log 실측 —
    //     wasm_ready +30751ms {"ok":false}  ← 30초를 꽉 채우고 타임아웃
    //     그 뒤 파일 주입 → doc_rendered +502ms {"loaded":true}
    //   즉 **WASM·렌더는 0.5초면 끝나는데 대기에서만 30초를 버렸다**. 파일 크기(956KB)나
    //   렌더 성능과는 무관하며, 총 31.9초 중 96%가 이 헛기다림이었다.
    //   [원인] 이 대기는 studio 가 찍는 `[WasmBridge] WASM 초기화 완료` 콘솔 로그를 감지해
    //   플래그를 세우는 방식인데(상단 console.log 후킹), 0.8.x studio 의 해당 코드는
    //     async initialize(){ if(this.initialized) return; ... console.log('[WasmBridge] WASM 초기화 완료') }
    //   처럼 **문서를 열 때 비로소 호출되는 지연 초기화(lazy init)** 다.
    //   → 우리는 "초기화 로그"를 기다리고 studio 는 "파일 주입"을 기다리는 **교착**이라
    //     플래그가 영원히 서지 않고 상한까지 간다(예전 버전에서는 즉시 열리던 이유가 이것).
    //   [수정 방침] 대기 자체를 없애지 않고 **상한만 줄인다** — 파일 입력 요소나 studio 앱이
    //   아직 준비되지 않은 환경에서 곧바로 주입하면 실패할 수 있으므로 짧은 여유는 남긴다.
    //   그리고 실패하더라도 **기존 재시도 4회(1.0s·1.5s·2.0s 간격)가 그대로 받쳐준다** —
    //   즉 최악의 경우에도 종전과 동일하게 동작하며, 정상 경로에서는 28초가 사라진다.
    //   ※ 플래그가 실제로 서는 환경(다른 rhwp 버전 등)에서는 종전처럼 즉시 통과한다 —
    //     판정 조건·재시도·주입 절차는 하나도 바꾸지 않았고 상한 값만 조정했다.
    function waitForWasmReady() {
        return new Promise(resolve => {
            let attempts = 0;
            const maxAttempts = 20; // 최대 2초 (종전 300 = 30초)
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
        const _fs = performance.now();
        const resp = await fetch(STREAM_URL);
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const blob = await resp.blob();
        // ★ 서버에서 파일을 받아오는 데 걸린 시간과 크기 — 파싱/렌더와 구분하기 위한 계측.
        _mark('file_fetched', { bytes: blob.size, fetch_ms: Math.round(performance.now() - _fs) });
        await fsRhwpPrepareFonts(blob);   // ★ (2026-11-18 21:29, 349차 펜닐 요청 "웹 폰트가 아니라 일반 폰트 인식, 특정 경로에 넣어도 인식")
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
                // ★ (2026-10-29 22:09, 24차 점검) 처음 마크업 글자('1 / 1 쪽')와 달라졌을 때만 — 종전엔 처음 글자가 바로 이 조건에 맞아 첫 검사에서 늘 '불러옴'으로 끝나,
                //   첫 주입이 실패해도 재시도가 돌지 않았음(빈 편집기로 남음)
                const pageInfo = document.getElementById('sb-page');
                if (pageInfo && window.__rhwpSbInit === undefined) window.__rhwpSbInit = pageInfo.textContent;
                if (pageInfo && pageInfo.textContent && pageInfo.textContent !== window.__rhwpSbInit && /\d+\s*\/\s*[1-9]/.test(pageInfo.textContent)) {
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
        // HWP/HWPX 모두 뷰잉·저장 지원 (재시도 횟수 동일 적용)
        const maxRetries = 4;
        
        try {
            _mark('start');
            const fileInput = await waitForInput();
            _mark('input_ready');
            
            const wasmOk = await waitForWasmReady();
            _mark('wasm_ready', { ok: wasmOk });
            if (!wasmOk) {
                // WASM 타임아웃이어도 시도는 함
            }
            // WASM 초기화 후 내부 이벤트 바인딩 안정화 대기
            await new Promise(r => setTimeout(r, 500));
            _mark('fixed_wait_500ms');
            
            // 1차 시도
            // ★ (2026-11-01 09:42, 38차 점검) 1차 받기 실패(HTTP 403/404/503·네트워크)도 아래 재시도로 보냄 — 종전엔 바깥 catch 로 빠져 재시도 없이 빈 편집기로 끝났음
            let loaded = false, lastLoadErr = null;
            try {
                await injectFile(fileInput, 0);
                _mark('inject_done');
                loaded = await checkFileLoaded();
            } catch (e) { lastLoadErr = e; }
            _mark('doc_rendered', { loaded: loaded, retry: 0 });
            if (loaded) _flushMarks('ok_first_try');
            
            if (!loaded) {
                // 로딩 오버레이 표시
                const loadingDiv = document.createElement('div');
                loadingDiv.id = 'rhwp-loading-overlay';
                loadingDiv.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.85);display:flex;align-items:center;justify-content:center;z-index:99999;flex-direction:column;gap:12px;';
                loadingDiv.innerHTML = '<div style="font-size:32px;">📄</div><div id="rhwp-loading-msg" style="font-size:14px;color:#666;">' + LOADING_MSG_HTML + '</div>';   // ★ (2026-11-12 19:20, 102차 I18N-1)
                document.body.appendChild(loadingDiv);
                
                // 재시도 (2~4차) — 간격을 점점 늘림
                for (let retry = 1; retry < maxRetries; retry++) {
                    const msgEl = document.getElementById('rhwp-loading-msg');
                    if (msgEl) msgEl.textContent = LOADING_RETRY_MSG.replace('{n}', retry + 1).replace('{total}', maxRetries);   // ★ (2026-11-12 19:20, 102차 I18N-1)
                    await new Promise(r => setTimeout(r, 1000 + retry * 500));
                    try {
                        await injectFile(fileInput, retry);
                        lastLoadErr = null;   // ★ (2026-11-01 09:42, 38차 점검) 받기는 됐으면 앞선 오류는 지움(렌더 확인만 실패한 경우는 종전처럼 안내 없이 닫음)
                        _mark('inject_done', { retry: retry });
                        loaded = await checkFileLoaded();
                        _mark('doc_rendered', { loaded: loaded, retry: retry });
                        if (loaded) { _flushMarks('ok_retry_' + retry); break; }
                    } catch (e) {
                        // 재시도 실패
                        lastLoadErr = e;   // ★ (2026-11-01 09:42, 38차 점검) 마지막 오류를 아래 안내에 씀
                    }
                }
                
                if (loaded || !lastLoadErr) loadingDiv.remove();
                if (!loaded) _flushMarks('failed_all_retries');
                if (!loaded && lastLoadErr) {
                    window.__rhwpOpenFailSeen = true;   // ★ (2026-11-04 02:02, 54차 점검 재수정) 파일을 끝내 못 받음(HTTP 오류·네트워크) = 확실한 열기 실패 — 빈 편집기에서 저장해 원래 파일을 덮어쓰지 않게(렌더 확인만 실패한 경우는 판단 불가라 표시 안 함)
                    // ★ (2026-11-01 09:42, 38차 점검) 끝내 파일을 못 받으면 오버레이에 이유를 남김(눌러서 닫기) — 종전엔 오버레이만 지워 빈 문서처럼 보이고 이유(403 권한·404·503 연결)를 알 수 없었음
                    const msgEl = document.getElementById('rhwp-loading-msg');
                    if (msgEl) msgEl.textContent = LOAD_FAIL_MSG + (lastLoadErr && lastLoadErr.message ? ' (' + lastLoadErr.message + ')' : '') + ' — ' + LOAD_FAIL_CLOSE;
                    loadingDiv.style.cursor = 'pointer';
                    loadingDiv.addEventListener('click', () => loadingDiv.remove(), { once: true });
                }
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
    const FILE_NAME = <?php echo json_encode($fileName, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const HAS_FILE = !!(<?php echo $storageId; ?>) && !!FILE_NAME;
    // ★ (2026-11-12 19:20, 102차 I18N-1) 저장·취소·완료·실패·'다른 이름으로 저장' 창·덮어쓰기 확인 문구를 화면 언어로 — 종전엔 영어 화면에서도 한국어 고정(재현). 한국어 값은 종전 문구와 같음, *_html 은 innerHTML 용으로 이스케이프
    const SV_TXT = <?php echo json_encode([
        'save_failed' => __('err_save_failed', '저장 실패'),
        'save_failed_prefix' => __('rhwp_ed_save_failed_prefix', '저장 실패: '),
        'unknown' => __('unknown_error', '알 수 없는 오류'),
        'not_opened' => __('rhwp_ed_not_opened', '문서가 열리지 않아 서버에 저장하지 않았습니다.'),
        'saving_server' => __('rhwp_ed_saving_server', '서버에 저장 중...'),
        'saving_server_name' => __('rhwp_ed_saving_server_name', '서버에 저장 중... ({name})'),
        'cancelled' => __('rhwp_ed_cancelled', '취소되었습니다'),
        'server_saved' => __('rhwp_ed_server_saved', '{name} 서버 저장 완료 ({size}KB)'),
        'saved' => __('rhwp_ed_saved', '{name} 저장 완료 ({size}KB)'),
        'overwritten' => __('rhwp_ed_overwritten', '{name} 덮어쓰기 완료 ({size}KB)'),
        'blob_failed' => __('rhwp_ed_blob_failed', 'exportHwp 데이터 캡처 실패 (Blob 미생성)'),
        'extracting' => __('rhwp_ed_extracting', '문서 추출 중...'),
        'extract_failed' => __('rhwp_ed_extract_failed', '문서 데이터 추출 실패'),
        'saveas_title_html' => htmlspecialchars(__('rhwp_ed_saveas_title', '다른 이름으로 저장'), ENT_QUOTES, 'UTF-8'),
        'saveas_desc_html' => htmlspecialchars(__('rhwp_ed_saveas_desc', '저장할 파일명을 입력하세요. 같은 폴더에 저장됩니다.'), ENT_QUOTES, 'UTF-8'),
        'cancel_html' => htmlspecialchars(__('cancel', '취소'), ENT_QUOTES, 'UTF-8'),
        'save_html' => htmlspecialchars(__('save', '저장'), ENT_QUOTES, 'UTF-8'),
        'name_required' => __('rhwp_ed_name_required', '파일명을 입력하세요'),
        'name_bad_chars' => __('rhwp_ed_name_bad_chars', '사용할 수 없는 문자가 있습니다 (/ \\ : * ? " < > | ..)'),
        'name_too_long' => __('rhwp_name_too_long', '파일명이 너무 깁니다'),
        'ext_must_be' => __('rhwp_ext_must_be', '확장자는 .{ext} 여야 합니다'),
        'overwrite_confirm' => __('rhwp_ed_overwrite_confirm', "\"{name}\" 파일이 이미 있습니다.\n덮어쓰시겠습니까?"),
    ], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    function svFmt(str, vars) { return String(str).replace(/\{(\w+)\}/g, (m, k) => (Object.prototype.hasOwnProperty.call(vars, k) ? String(vars[k]) : m)); }   // ★ (2026-11-12 19:20, 102차 I18N-1) 자리 표시자 채우기(__f 와 같은 {이름} 모양, 파일 이름의 $ 등이 바뀌지 않게 함수로 바꿈)
    
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
        if (!resp.ok) {
            // ★ (2026-10-29 18:47, 22차 점검) 서버가 준 이유(잠긴 파일·권한 없음 등)를 보여 줌 — 종전엔 'HTTP 403' 만 보였음
            let _msg = 'HTTP ' + resp.status;
            try { const j = await resp.json(); if (j && j.error) _msg = j.error; } catch (e) {}
            throw new Error(_msg);
        }
        const result = await resp.json();
        if (!result.success) throw new Error(result.error || SV_TXT.save_failed);   // ★ (2026-11-12 19:20, 102차 I18N-1)
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
    // HWPX 저장 지원에 따라 hwp+zip(HWPX)도 함께 캡처 — studio가 원본 형식으로 내보낸다
    let lastExportData = null;
    const OrigBlob = window.Blob;
    window.Blob = function(parts, options) {
        const blob = new OrigBlob(parts, options);
        if (options && (options.type === 'application/x-hwp' || options.type === 'application/hwp+zip') && parts && parts.length > 0) {
            const data = parts[0];
            if (data instanceof Uint8Array && data.length > 16) {
                lastExportData = new Uint8Array(data);
            }
        }
        return blob;
    };
    window.Blob.prototype = OrigBlob.prototype;
    Object.setPrototypeOf(window.Blob, OrigBlob);
    // ★ (2026-11-04 01:38, 54차 점검) studio 의 '문서 암호 설정' 창(암호로 연 문서는 저장할 때마다 먼저 뜸)이 열려 있는지 — 창이 닫히면 studio 가 overlay 를 DOM 에서 지움
    //   아래 두 Blob 대기 루프가 창이 열린 동안은 2초 상한을 세지 않게 씀. 종전엔 2초 뒤 'Blob 미생성' 실패 + 가로채기가 풀려, 암호를 넣으면 PC 저장 창(진짜 showSaveFilePicker)이 떴음(재현)
    function rhwpPwDialogOpen() {
        return !!document.getElementById('hwp-save-password-input');
    }
    // ★ (2026-11-04 02:02, 54차 점검 재수정) 문서 열기가 실패·취소됐다는 '확실한 신호'가 있을 때만 true(그때만 서버 저장을 막음). 판단이 안 서면 false = 종전처럼 저장(fail-open).
    //   신호(실제 재현으로 확인): 상태줄 '문서 열기를 취소했습니다.'(암호 창 취소·틀린 암호 뒤 취소), '파일 로드 실패: …'(studio 열기 오류),
    //   열기 암호 창(#hwp-password-input)이 아직 떠 있음(아직 안 열림), FileStation 이 파일을 끝내 못 받음(위 로더가 __rhwpOpenFailSeen 표시).
    //   신호가 있었어도 '[WasmBridge] 문서 로드'(위 콘솔 감지)를 한 번이라도 봤으면 문서가 열린 것이므로 허용.
    //   종전엔 열기를 취소한 빈 편집기에서 Ctrl+S 하면 빈 문서가 서버의 원래 파일을 덮어썼음(재현: pw.hwp 7680 → 12800바이트 빈 문서)
    const RHWP_OPEN_FAIL_RE = /문서 열기를 취소했습니다|^\s*파일 로드 실패/;
    (function () {   // 상태줄 글자는 곧 다른 글자로 바뀔 수 있어, 신호가 나타나면 기억해 둠
        const sb = document.getElementById('sb-message');
        if (!sb || typeof MutationObserver === 'undefined') return;
        new MutationObserver(() => { if (RHWP_OPEN_FAIL_RE.test(sb.textContent || '')) window.__rhwpOpenFailSeen = true; })
            .observe(sb, { childList: true, characterData: true, subtree: true });
    })();
    function rhwpOpenFailedNow() {
        if (window.__rhwpServerDocOpened) return false;
        if (window.__rhwpOpenFailSeen) return true;
        if (document.getElementById('hwp-password-input')) return true;
        const sb = document.getElementById('sb-message');
        return !!(sb && RHWP_OPEN_FAIL_RE.test(sb.textContent || ''));
    }

    // ★ (2026-11-12 17:35, 101차 EDT-1) studio 의 변경 횟수(documentEpoch:changeSeq — 편집마다 1씩 늘어남)를 같은 창 요청 getSelectionContext 로 읽음(getDocumentState 는 문서 전체 해시를 만들어 2.9MB 문서에서 6초 넘게 걸려 쓰지 않음) — 못 읽으면 null(그때는 종전처럼 저장 뒤 수정 표시를 지움)
    function readStudioChangeSeq() {
        return new Promise(resolve => {
            const id = '__fsSeq' + Date.now() + '_' + Math.random();
            let done = false, tm = null;
            const finish = v => { if (done) return; done = true; window.removeEventListener('message', onMsg); clearTimeout(tm); resolve(v); };
            const onMsg = e => {
                const d = e.data;
                if (e.source !== window || !d || d.type !== 'rhwp-response' || d.id !== id) return;
                const r = d.result;
                finish(r && typeof r.changeSeq === 'number' ? r.documentEpoch + ':' + r.changeSeq : null);
            };
            window.addEventListener('message', onMsg);
            tm = setTimeout(() => finish(null), 1500);
            try { window.postMessage({ type: 'rhwp-request', id: id, method: 'getSelectionContext', params: {} }, window.location.origin); } catch (e) { finish(null); }
        });
    }
    // ★ (2026-11-12 17:35, 101차 EDT-1) 내보내기 전에 읽은 값과 지금 값이 같거나 둘 중 하나라도 못 읽었을 때만 수정 표시·초안을 지움 — 종전엔 서버 응답을 기다리는 동안 친 글자도 '저장됨'이 되어 닫을 때 경고 없이 사라지고 초안도 버려졌음(재현)
    async function markStudioSavedIfUnchanged(seqAtStart, fileName) {
        const seqNow = seqAtStart === null ? null : await readStudioChangeSeq();
        if (seqAtStart !== null && seqNow !== null && seqNow !== seqAtStart) return;
        await markStudioSaved(fileName);
    }

    async function exportAndSave() {
        if (saving) return; // 이미 저장 중이면 skip (중복 저장 방지)
        if (rhwpOpenFailedNow()) {   // ★ (2026-11-04 01:55, 54차 점검) 문서가 열리지 않은 상태(열기 취소·받기 실패)면 서버에 저장하지 않음 — 종전엔 빈 문서로 원래 파일을 덮어썼음(재현). ★ (2026-11-04 02:02, 54차 점검 재수정) 확실한 실패 신호일 때만(위 rhwpOpenFailedNow)
            const sbEl = document.getElementById('sb-message');
            if (sbEl) sbEl.textContent = SV_TXT.not_opened;   // ★ (2026-11-12 19:20, 102차 I18N-1)
            return;
        }
        saving = true;
        lastExportData = null;
        if (window.__rhwpSyncSuspend) window.__rhwpSyncSuspend();
        
        const statusEl = document.getElementById('sb-message');
        if (statusEl) statusEl.textContent = SV_TXT.saving_server;   // ★ (2026-11-12 19:20, 102차 I18N-1)
        // console.log('[rhwp_editor] exportAndSave 시작');
        
        // 프로토타입 훅은 try 밖에서 선언 + finally에서 반드시 복원
        // (try 내부에서 예외 발생 시 프로토타입 영구 오염 방지)
        const origClick = HTMLAnchorElement.prototype.click;
        const origPicker = window.showSaveFilePicker;
        const hadPicker = 'showSaveFilePicker' in window;
        
        try {
            const seqAtStart = await readStudioChangeSeq();   // ★ (2026-11-12 17:35, 101차 EDT-1) 내보내기 전 변경 횟수(아래 저장 완료 때 비교)
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
            let pwDialogSeen = false;   // ★ (2026-11-04 01:38, 54차 점검) 암호 창이 열린 동안은 대기 횟수를 세지 않음(위 rhwpPwDialogOpen) — 종전엔 암호 문서 저장이 늘 2초 뒤 실패했음(재현)
            for (let wait = 0; wait < 20; wait++) {
                await new Promise(r => setTimeout(r, 100));
                if (lastExportData && lastExportData.length > 16) {
                    // console.log('[rhwp_editor] Blob 캡처 성공 (' + (wait*100) + 'ms):', lastExportData.length, 'bytes');
                    break;
                }
                if (rhwpPwDialogOpen()) { pwDialogSeen = true; wait = -1; }   // ★ (2026-11-04 01:38, 54차 점검) 창이 닫힌 뒤부터 다시 2초
            }
            // ★ (2026-11-04 01:38, 54차 점검) 암호 창에서 '취소'하면 studio 가 내보내지 않으므로 실패 문구 대신 취소로 끝냄(암호 없는 문서는 창이 안 떠 종전과 같음)
            if (pwDialogSeen && !(lastExportData && lastExportData.length > 16)) {
                if (statusEl) statusEl.textContent = SV_TXT.cancelled;   // ★ (2026-11-12 19:20, 102차 I18N-1)
                return;
            }

            // 참고: studio는 원본 형식대로 저장한다 (HWP → x-hwp, HWPX → hwp+zip Blob).
            //       두 MIME 모두 위 Blob 훅에서 캡처된다.
            
            if (lastExportData && lastExportData.length > 16) {
                // console.log('[rhwp_editor] serverSave 호출, 크기:', lastExportData.length);
                const result = await serverSave(lastExportData);
                const sizeKB = (result.size / 1024).toFixed(1);
                if (statusEl) statusEl.textContent = svFmt(SV_TXT.server_saved, { name: FILE_NAME, size: sizeKB });   // ★ (2026-11-12 19:20, 102차 I18N-1)
                // console.log('[rhwp_editor] 서버 저장 완료:', FILE_NAME, result.size, 'bytes');
                // 부모 창(웹하드)에 파일 변경 알림 (목록 갱신용)
                notifyParentFileChanged(FILE_NAME, 'save');
                // 원본 파일에 덮어썼으므로 studio의 dirty 표시 해제
                await markStudioSavedIfUnchanged(seqAtStart, FILE_NAME);   // ★ (2026-11-12 17:35, 101차 EDT-1) 저장 중 고친 게 있으면 수정 표시·초안을 남김
            } else {
                console.error('[rhwp_editor] Blob 캡처 실패 - lastExportData:', lastExportData);
                throw new Error(SV_TXT.blob_failed);   // ★ (2026-11-12 19:20, 102차 I18N-1)
            }
        } catch (e) {
            console.error('[rhwp_editor] 서버 저장 실패:', e);
            if (statusEl) statusEl.textContent = SV_TXT.save_failed_prefix + ((e && e.message) || (typeof e === 'string' && e) || SV_TXT.unknown);   // ★ (2026-10-26) WASM 이 문자열을 던지면 'undefined' 가 나왔음. ★ (2026-11-12 19:20, 102차 I18N-1) 문구를 화면 언어로
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
    // studio에 '호스트가 저장을 마쳤다'고 통지해 수정됨(dirty) 표시를 해제한다.
    // 우리 서버 저장은 showSaveFilePicker를 AbortError로 막기 때문에 studio 입장에서는
    // '사용자가 저장을 취소함'으로 보여 내부 markClean에 도달하지 못한다. 그 결과 저장이
    // 끝났는데도 창을 닫을 때 브라우저의 '변경사항이 저장되지 않을 수 있습니다' 경고가 떴다.
    // window.rhwpStudio.notifySaved는 rhwp 0.8.0에서 추가된 호스트용 API로,
    // markClean과 자동 임시저장 초안 폐기를 함께 수행한다. 구버전에는 없으므로 존재 확인 후 호출.
    // fileName 인자를 넘기면 studio의 표시 파일명이 바뀌므로, 저장 대상이 바뀌지 않는 경우엔 생략한다.
    async function markStudioSaved(fileName) {
        try {
            const api = window.rhwpStudio;
            if (api && typeof api.notifySaved === 'function') {
                await api.notifySaved(fileName);
            }
        } catch (e) {
            console.warn('[rhwp_editor] notifySaved 실패(저장 자체는 완료됨):', e);
        }
    }
    
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
    
    // (현재 미사용) HWPX 저장을 다시 차단해야 할 경우를 위한 안내 헬퍼 — 호출부 없음
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
        // HWP/HWPX 모두 저장 지원 (studio가 원본 형식 그대로 저장)
        if (shift) {
            exportAndSaveAs();
        } else {
            exportAndSave();
        }
    };
    
    
    // ★ (2026-11-18 21:53, 350차 펜닐 요청 "폰트 위치 설정 변경, 메뉴에 폰트 위치 지정(편집기에서 설정하면 뷰어는 자동)")
    //   [도구 › 글꼴 폴더 설정] — rhwp 묶음 명령이 아니라 FileStation 창을 엶(묶음으로 가지 않게 먼저 가로챔)
    document.addEventListener('click', function(e) {
        // ★ (2026-11-18 22:17, 351차 펜닐 지적 "비활성화(회색)인데 클릭됨 / 어디어디에서 폰트를 가져다 쓰고 활성화 여부도 안 나옴")
        //   rhwp 메뉴는 data-cmd 항목을 눌렀을 때만 닫으므로, rhwp 의 '바깥 누르기' 닫기(문서 mousedown → closeAll)로 닫은 뒤 창을 엶
        //   (open 클래스만 지우면 rhwp 는 아직 열린 줄 알아 다른 메뉴 제목에 마우스만 올려도 열림)
        const it = e.target && e.target.closest && e.target.closest('.md-item[data-fs-cmd="font-folders"]');
        if (!it) return;
        e.preventDefault();
        try { document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, view: window })); } catch (er) {}
        setTimeout(() => { if (typeof window.fsFontFolderDialog === 'function') window.fsFontFolderDialog(); }, 0);
    }, true);

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
                '<div style="font-size:15px;font-weight:600;margin-bottom:12px;color:#222;">' + SV_TXT.saveas_title_html + '</div>' +   // ★ (2026-11-12 19:20, 102차 I18N-1)
                '<div style="font-size:13px;color:#555;margin-bottom:10px;">' + SV_TXT.saveas_desc_html + '</div>' +
                '<input type="text" id="__rhwpSaveAsInput" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:14px;" />' +
                '<div id="__rhwpSaveAsErr" style="font-size:12px;color:#d33;margin-top:6px;min-height:16px;"></div>' +
                '<div style="text-align:right;margin-top:14px;">' +
                '<button id="__rhwpSaveAsCancel" style="padding:6px 16px;margin-right:8px;background:#eee;border:1px solid #ccc;border-radius:4px;cursor:pointer;">' + SV_TXT.cancel_html + '</button>' +
                '<button id="__rhwpSaveAsOk" style="padding:6px 16px;background:#0066cc;color:#fff;border:1px solid #0066cc;border-radius:4px;cursor:pointer;">' + SV_TXT.save_html + '</button>' +
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
                if (!name) return SV_TXT.name_required;   // ★ (2026-11-12 19:20, 102차 I18N-1) 아래 검사 문구 4개도 화면 언어로
                if (/[\/\\:*?"<>|\x00-\x1f]|\.\./.test(name)) return SV_TXT.name_bad_chars;   // ★ (2026-11-01 09:42, 38차 점검) 제어 문자도 거부(서버 save-as 검사와 같게)
                if (name.length > 240) return SV_TXT.name_too_long;
                // 원본 형식과 같은 확장자만 허용 — 내용은 원본 형식 그대로 저장되므로
                // 확장자만 바꾸면 내용과 어긋난 깨진 파일이 된다
                const wantExt = IS_HWPX_DOC ? '.hwpx' : '.hwp';
                if (!name.toLowerCase().endsWith(wantExt)) return svFmt(SV_TXT.ext_must_be, { ext: wantExt.slice(1) });
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
            // ★ (2026-10-29 18:47, 22차 점검) 서버가 준 이유를 보여 줌 — 종전엔 자기가 던진 오류를 같은 catch 가 받아 늘 'HTTP 403' 으로 덮었음
            const txt = await resp.text();
            let _msg = 'HTTP ' + resp.status;
            try { const j = JSON.parse(txt); if (j && j.error) _msg = j.error; } catch(e) {}
            throw new Error(_msg);
        }
        const result = await resp.json();
        if (!result.success) {
            const err = new Error(result.error || SV_TXT.save_failed);   // ★ (2026-11-12 19:20, 102차 I18N-1)
            err.exists = !!result.exists;
            err.savedName = (typeof result.name === 'string' && result.name) ? result.name : '';   // ★ (2026-11-09 19:50, 60차 Z17) 서버가 정리한 실제 이름(아래 덮어쓰기 확인창 문구용)
            throw err;
        }
        return result;
    }
    
    async function exportAndSaveAs() {
        if (saving) return;
        if (rhwpOpenFailedNow()) {   // ★ (2026-11-04 02:02, 54차 점검 재수정) 확실한 실패 신호일 때만 막음(위 rhwpOpenFailedNow). ★ (2026-11-04 01:55, 54차 점검) 위 '저장'과 같음 — 열리지 않은 빈 문서를 다른 이름(덮어쓰기 포함)으로 서버에 쓰지 않음
            const sbEl = document.getElementById('sb-message');
            if (sbEl) sbEl.textContent = SV_TXT.not_opened;   // ★ (2026-11-12 19:20, 102차 I18N-1)
            return;
        }
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
            
            if (statusEl) statusEl.textContent = SV_TXT.extracting;   // ★ (2026-11-12 19:20, 102차 I18N-1)
            
            const seqAtStart = await readStudioChangeSeq();   // ★ (2026-11-12 17:35, 101차 EDT-1) 위 '저장'과 같음 — 추출 전 변경 횟수(이름 입력·서버 저장 동안 고친 게 있으면 수정 표시를 남김)
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
            let pwDialogSeen = false;   // ★ (2026-11-04 01:38, 54차 점검) 위 '저장'과 같음 — 암호 창이 열린 동안은 대기 횟수를 세지 않음(종전엔 2초 뒤 '문서 데이터 추출 실패', 재현)
            for (let wait = 0; wait < 20; wait++) {
                await new Promise(r => setTimeout(r, 100));
                if (lastExportData && lastExportData.length > 16) {
                    // console.log('[rhwp_editor] (save-as) Blob 캡처 성공 (' + (wait*100) + 'ms):', lastExportData.length, 'bytes');
                    break;
                }
                if (rhwpPwDialogOpen()) { pwDialogSeen = true; wait = -1; }   // ★ (2026-11-04 01:38, 54차 점검) 창이 닫힌 뒤부터 다시 2초
            }

            // 이 시점부터는 프로토타입/picker 훅이 필요 없으므로 조기 복원
            // (아래 파일명 다이얼로그와 서버 업로드는 일반적인 <a>.click()을 사용할 수 있음)
            HTMLAnchorElement.prototype.click = origClick;
            if (hadPicker) {
                window.showSaveFilePicker = origPicker;
            } else {
                try { delete window.showSaveFilePicker; } catch (_) { window.showSaveFilePicker = undefined; }
            }
            
            if (pwDialogSeen && (!lastExportData || lastExportData.length < 16)) {   // ★ (2026-11-04 01:38, 54차 점검) 암호 창 '취소'는 실패 문구 대신 취소로(위 '저장'과 같음)
                if (statusEl) statusEl.textContent = SV_TXT.cancelled;   // ★ (2026-11-12 19:20, 102차 I18N-1)
                return;
            }
            if (!lastExportData || lastExportData.length < 16) {
                throw new Error(SV_TXT.extract_failed);   // ★ (2026-11-12 19:20, 102차 I18N-1)
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
            if (statusEl) statusEl.textContent = svFmt(SV_TXT.saving_server_name, { name: newName });   // ★ (2026-11-12 19:20, 102차 I18N-1)
            try {
                const result = await serverSaveAs(extractedData, newName);
                const sizeKB = (result.size / 1024).toFixed(1);
                const savedName = (typeof result.name === 'string' && result.name) ? result.name : newName;   // ★ (2026-11-09 13:58, 58차 FIXD 결정) 서버가 이름을 정리하면('.hwp' → 'hwp.hwp') 실제 저장된 이름으로 표시·알림
                if (statusEl) statusEl.textContent = svFmt(SV_TXT.saved, { name: savedName, size: sizeKB });   // ★ (2026-11-12 19:20, 102차 I18N-1)
                // 부모 창(웹하드)에 파일 목록 갱신 신호 전송
                notifyParentFileChanged(savedName, 'save-as');
                await markStudioSavedIfUnchanged(seqAtStart);   // ★ (2026-11-12 17:35, 101차 EDT-1) 저장 중 고친 게 있으면 수정 표시·초안을 남김
            } catch (e) {
                if (e.exists) {
                    // 같은 이름 파일이 이미 있음 — 덮어쓰기 확인
                    if (confirm(svFmt(SV_TXT.overwrite_confirm, { name: (e.savedName || newName) }))) {   // ★ (2026-11-09 19:50, 60차 Z17) 실제로 덮어쓸 이름(서버 응답 name, 없으면 입력 이름). ★ (2026-11-12 19:20, 102차 I18N-1) 확인 문구를 화면 언어로
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
                            const savedName = (typeof j.name === 'string' && j.name) ? j.name : newName;   // ★ (2026-11-09 13:58, 58차 FIXD 결정) 위와 같음 — 실제 저장된 이름
                            if (statusEl) statusEl.textContent = svFmt(SV_TXT.overwritten, { name: savedName, size: sizeKB });   // ★ (2026-11-12 19:20, 102차 I18N-1)
                            // 부모 창에 파일 목록 갱신 신호 (덮어쓰기도 갱신 필요)
                            notifyParentFileChanged(savedName, 'save-as-overwrite');
                            // 사본에 저장했을 뿐 원본 파일은 그대로이나, studio 자체 save-as와
                            // 동일하게 clean 처리한다 (파일명 인자는 넘기지 않아 편집 대상은 원본 유지)
                            await markStudioSavedIfUnchanged(seqAtStart);   // ★ (2026-11-12 17:35, 101차 EDT-1) 위와 같음
                        } else {
                            throw new Error(j.error || SV_TXT.save_failed);   // ★ (2026-11-12 19:20, 102차 I18N-1)
                        }
                    } else {
                        if (statusEl) statusEl.textContent = SV_TXT.cancelled;   // ★ (2026-11-12 19:20, 102차 I18N-1)
                    }
                } else {
                    throw e;
                }
            }
        } catch (e) {
            console.error('[rhwp_editor] 다른 이름으로 저장 실패:', e);
            if (statusEl) statusEl.textContent = SV_TXT.save_failed_prefix + ((e && e.message) || (typeof e === 'string' && e) || SV_TXT.unknown);   // ★ (2026-10-26) WASM 이 문자열을 던지면 'undefined' 가 나왔음. ★ (2026-11-12 19:20, 102차 I18N-1) 문구를 화면 언어로
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
    // HWP/HWPX 공통: file:save, file:save-as 모두 활성화
    //   file:save는 studio가 canExecute로 관리, file:save-as는 FileStation 커스텀이라 직접 관리
    
    // 저장 중에는 동기화 일시 중지 (저장 실행 중 rhwp가 file:save를 순간 disabled 처리하는 것을 무시)
    let syncSuspended = false;
    
    function syncSaveAsMenuState() {
        if (syncSuspended) return; // 저장 중에는 동기화 스킵
        
        const saveItem = document.querySelector('.md-item[data-cmd="file:save"]');
        const saveAsItem = document.querySelector('.md-item[data-cmd="file:save-as"]');
        if (!saveAsItem) return;
        
        // file:save-as는 FileStation 커스텀 메뉴이므로 studio가 관리하지 않음
        // 문서 로드 여부와 관계없이 항상 활성 유지 (HTML 초기값도 활성)
        // studio가 자체적으로 disabled를 추가했을 경우에만 제거
        // (HWPX도 저장을 지원하므로 HWP와 동일하게 처리)
        if (saveAsItem.classList.contains('disabled')) {
            saveAsItem.classList.remove('disabled');
        }
        if (saveAsItem.getAttribute('title')) saveAsItem.removeAttribute('title');
        // file:save의 title 제거 (disabled 자체는 studio가 관리)
        if (saveItem && saveItem.getAttribute('title')) {
            saveItem.removeAttribute('title');
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
    // (HWP/HWPX 동일 처리 — 예전에는 HWPX일 때 observer를 건너뛰었으나 저장을 지원하면서 통일)
    const observeSaveMenus = () => {
        const saveItem = document.querySelector('.md-item[data-cmd="file:save"]');
        const saveAsItem = document.querySelector('.md-item[data-cmd="file:save-as"]');
        if (!saveItem || !saveAsItem) return false;
        
        // 초기 상태 한 번 정리
        syncSaveAsMenuState();
        
        
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
<?php /* ★ (2026-11-18 21:53, 350차 펜닐 요청 "폰트 위치 설정 변경, 메뉴에 폰트 위치 지정(편집기에서 설정하면 뷰어는 자동)")
   ★ (2026-11-18 22:17, 351차 펜닐 지적 "비활성화(회색)인데 클릭됨 / 어디어디에서 폰트를 가져다 쓰고 활성화 여부도 안 나옴")
   [도구 › 글꼴 폴더 설정] 창 — 관리자만. 서버(data/rhwp_font_settings.json)에 저장하므로 뷰어도 같은 폴더를 씀.
   351차: 글꼴을 어디서 가져다 쓰는지 4곳(① 이 PC 설치 ② 서버 글꼴 폴더 ③ rhwp 기본 ④ 인터넷)과 각각 사용 여부를 보여 주고,
          서버 폴더는 폴더마다 켜기/끄기·지우기·추가(텍스트 칸 대신 줄 목록).
   모달 원칙: [저장]은 창을 닫지 않고 '저장됨'만, 닫기는 [닫기]·[X]·Esc. 좁고 세로로 긴 모양 */ ?>
<?php if ($auth->isAdmin() || ($auth->isSubAdmin() && $auth->hasAdminPerm('system_settings'))): ?>
<script>
(function () {
    const ROOT = <?php echo json_encode(rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;
    const CSRF = <?php echo json_encode(getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const T = <?php echo json_encode([
        'title' => __('rf_title', '글꼴 폴더 설정'),
        'intro' => __('rf_intro', '문서를 열 때 위에서부터 차례로 글꼴을 찾습니다. 앞에서 찾으면 뒤의 것은 쓰지 않습니다.'),
        's1' => __('rf_s1', '이 PC에 설치된 글꼴'),
        's1_viewer' => __('rf_s1_viewer', '뷰어: 문서에 적힌 글꼴이 이 PC에 있으면 바로 씀(항상)'),
        's1_editor_ok' => __('rf_s1_editor_ok', '편집기: 로컬 글꼴 감지 {n}개 ({when})'),
        's1_editor_partial' => __('rf_s1_editor_partial', '편집기: 문서에 필요한 글꼴만 확인 ({when})'),
        's1_editor_none' => __('rf_s1_editor_none', '편집기: 아직 감지 안 함 — 도구 › 환경 설정 › 글꼴에서 [로컬 글꼴 감지]'),
        's1_editor_server' => __('rf_s1_editor_server', '편집기: 로컬 글꼴 감지 안 함 — 서버 글꼴만 사용 (이 PC 글꼴도 쓰려면 도구 › 환경 설정 › 글꼴에서 [로컬 글꼴 감지])'),
        's1_editor_unsup' => __('rf_s1_editor_unsup', '편집기: 이 브라우저는 전체 감지를 지원하지 않음(문서에 필요한 글꼴만 확인)'),
        's1_perm_denied' => __('rf_s1_perm_denied', '브라우저에서 글꼴 사용 권한이 거부됨'),
        's2' => __('rf_s2', '서버 글꼴 폴더'),
        's2_desc' => __('rf_s2_desc', 'TTF·OTF·TTC·WOFF·WOFF2, 하위 폴더 3단계까지 — 모든 사용자에게 적용'),
        's2_add_ph' => __('rf_s2_add_ph', '폴더 경로 (예: C:/Windows/Fonts)'),
        's2_add' => __('rf_s2_add', '추가'),
        's2_del' => __('rf_s2_del', '목록에서 빼기'),
        's2_use' => __('rf_s2_use', '사용'),
        's3' => __('rf_s3', 'rhwp 기본 글꼴'),
        's3_desc' => __('rf_s3_desc', '서버 assets/rhwp/studio/fonts · 파일 {n}개 — 맑은 고딕·굴림·바탕·HY 등이 없을 때 대신 씀'),
        's4' => __('rf_s4', '인터넷 글꼴'),
        's4_desc' => __('rf_s4_desc', '{names} (cdn.jsdelivr.net) — 인터넷에 연결돼 있어야 함'),
        'b_on' => __('rf_b_on', '사용 중'),
        'b_on_n' => __('rf_b_on_n', '사용 중 · 글꼴 {n}개'),
        'b_empty' => __('rf_b_empty', '켜짐 · 글꼴 없음'),
        'b_off' => __('rf_b_off', '꺼짐'),
        'b_check' => __('rf_b_check', '확인 필요'),
        'fonts_n' => __('rf_fonts_n', '글꼴 {n}개'),
        'names_n' => __('rf_names_n', '인식한 글꼴 이름 {n}개'),
        'none' => __('rf_none', '지정한 폴더가 없습니다(서버 글꼴을 쓰지 않음)'),
        'note' => __('rf_note', '저장하면 다음에 여는 문서부터 적용됩니다(뷰어도 같이). 상용 글꼴은 사용 허락 범위를 확인하세요.'),
        'dirty' => __('rf_dirty', '바꾼 내용이 아직 저장되지 않았습니다'),
        'rescan' => __('rf_rescan', '다시 읽기'),
        'save' => __('rf_save', '저장'),
        'close' => __('rf_close', '닫기'),
        'saved' => __('rf_saved', '저장됨'),
        'scanned' => __('rf_scanned', '다시 읽음'),
        'loading' => __('rf_loading', '읽는 중…'),
        'failed' => __('rf_failed', '처리하지 못했습니다'),
        'e_not_found' => __('rf_e_not_found', '폴더가 없습니다'),
        'e_not_dir' => __('rf_e_not_dir', '폴더가 아닙니다'),
        'e_not_readable' => __('rf_e_not_readable', '읽을 수 없습니다(권한)'),
        'e_invalid' => __('rf_e_invalid', '쓸 수 없는 글자가 있습니다'),
        'e_duplicate' => __('rf_e_duplicate', '위와 같은 폴더'),
        'e_too_many' => __('rf_e_too_many', '폴더는 10개까지입니다'),
        'e_deferred' => __('rf_e_deferred', '글꼴 {n}개는 아직 못 읽었습니다 — [다시 읽기]를 누르면 이어서 읽습니다'),
        'e_csrf' => __('rf_e_csrf', '보안 토큰이 만료됐습니다. 편집기를 새로 고쳐 주세요'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmt = (s, o) => String(s).replace(/\{(\w+)\}/g, (m, k) => (k in o ? o[k] : m));
    const errText = (code, n) => T['e_' + code] ? fmt(T['e_' + code], { n: n || 0 }) : String(code || '');
    const badge = (cls, text) => '<span class="rf-badge rf-' + cls + '">' + esc(text) + '</span>';
    let box = null, data = null, rows = [], rowErr = {}, dirty = false;

    function close() { if (box) { try { box.remove(); } catch (e) {} box = null; document.removeEventListener('keydown', onKey, true); } }
    function onKey(e) { if (e.key === 'Escape' && box) { e.preventDefault(); e.stopPropagation(); close(); } }

    // ① 이 PC — rhwp 편집기의 로컬 글꼴 감지 결과(브라우저 저장소 'rhwp-local-fonts', rhwp 가 쓰는 형식)를 읽어서 보여 줌(바꾸지 않음)
    async function localInfo() {
        let rec = null;
        try { const raw = localStorage.getItem('rhwp-local-fonts'); if (raw) rec = JSON.parse(raw); } catch (e) {}
        let perm = '';
        try { if (navigator.permissions) perm = (await navigator.permissions.query({ name: 'local-fonts' })).state; } catch (e) {}
        const when = (iso) => { try { const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleString(); } catch (e) { return ''; } };
        let line;
        if (rec && rec.fsServerFonts === true) {   // ★ (2026-11-18 22:49, 353차) FileStation 이 서버 글꼴로 적어 둔 기록
            line = T.s1_editor_server;
        } else if (rec && rec.source === 'local-font-access') {
            const n = Array.isArray(rec.fontRecords) && rec.fontRecords.length ? new Set(rec.fontRecords.map(r => r && (r.family || r.name)).filter(Boolean)).size : (Array.isArray(rec.families) ? rec.families.length : 0);
            line = fmt(T.s1_editor_ok, { n, when: when(rec.detectedAt) });
        } else if (rec && rec.source === 'font-presence-probe') {
            line = fmt(T.s1_editor_partial, { when: when(rec.detectedAt) });
        } else if (typeof window.queryLocalFonts !== 'function') {
            line = T.s1_editor_unsup;
        } else {
            line = T.s1_editor_none;
        }
        return { line, denied: perm === 'denied' };
    }

    function folderStatus(r) {
        if (!r.enabled) return '<span class="rf-off">' + esc(T.b_off) + '</span>';
        if (rowErr[r.path]) return '<span class="rf-bad">✗ ' + esc(errText(rowErr[r.path])) + '</span>';
        if (r.saved === false) return '<span class="rf-warn">' + esc(T.dirty) + '</span>';
        if (r.ok === false) return '<span class="rf-bad">✗ ' + esc(errText(r.error)) + '</span>';
        if (r.ok) return '<span class="rf-ok">✓ ' + esc(fmt(T.fonts_n, { n: r.faces })) + '</span>';
        return '';
    }

    async function render(msg, isErr) {
        if (!box || !data) return;
        // ①
        const li = await localInfo();
        box.querySelector('[data-s="1"] .rf-badge-wrap').innerHTML = badge('on', T.b_on);
        box.querySelector('[data-s="1"] .rf-sub').innerHTML = '<div>' + esc(T.s1_viewer) + '</div><div>' + esc(li.line) + '</div>' + (li.denied ? '<div class="rf-bad">' + esc(T.s1_perm_denied) + '</div>' : '');
        // ②
        const en = rows.filter(r => r.enabled);
        const total = rows.reduce((a, r) => a + (r.enabled && r.ok ? (r.faces || 0) : 0), 0);
        box.querySelector('[data-s="2"] .rf-badge-wrap').innerHTML = !en.length ? badge('off', T.b_off) : (en.some(r => r.ok === false || rowErr[r.path]) ? badge('warn', T.b_check) : (total > 0 ? badge('on', fmt(T.b_on_n, { n: total })) : badge('warn', T.b_empty)));
        const list = box.querySelector('.rf-folders');
        list.innerHTML = rows.length ? rows.map((r, i) =>
            '<div class="rf-frow"><label class="rf-chk" title="' + esc(T.s2_use) + '"><input type="checkbox" data-i="' + i + '"' + (r.enabled ? ' checked' : '') + '></label>' +
            '<div class="rf-fmain"><div class="rf-fpath">' + esc(r.path) + '</div><div class="rf-fst">' + folderStatus(r) + '</div></div>' +
            '<button type="button" class="rf-del" data-del="' + i + '" title="' + esc(T.s2_del) + '" aria-label="' + esc(T.s2_del) + '">✕</button></div>').join('')
            : '<div class="rf-row rf-off">' + esc(T.none) + '</div>';
        list.querySelectorAll('input[type=checkbox]').forEach(cb => cb.addEventListener('change', () => { const r = rows[+cb.dataset.i]; r.enabled = cb.checked; r.saved = false; delete rowErr[r.path]; dirty = true; render(); }));
        list.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => { rows.splice(+b.dataset.del, 1); dirty = true; render(); }));
        const sum = box.querySelector('.rf-names summary');
        sum.textContent = fmt(T.names_n, { n: data.names_total });
        box.querySelector('.rf-names div').textContent = data.names.join(', ') + (data.names_total > data.names.length ? ' …' : '');
        box.querySelector('.rf-deferred').textContent = data.deferred ? errText('deferred', data.deferred) : '';
        // ③ ④
        box.querySelector('[data-s="3"] .rf-badge-wrap').innerHTML = badge('on', T.b_on);
        box.querySelector('[data-s="3"] .rf-sub').textContent = fmt(T.s3_desc, { n: data.builtin });
        box.querySelector('[data-s="4"] .rf-badge-wrap').innerHTML = badge('on', T.b_on);
        box.querySelector('[data-s="4"] .rf-sub').textContent = fmt(T.s4_desc, { names: (data.cdn || []).join('·') });
        const m = box.querySelector('.rf-msg');
        if (msg !== undefined) { m.textContent = msg || ''; m.className = 'rf-msg' + (isErr ? ' rf-bad' : ' rf-ok'); }
        else if (dirty) { m.textContent = T.dirty; m.className = 'rf-msg rf-warn'; }
    }

    async function call(action, body) {
        const opt = { credentials: 'same-origin', headers: {} };
        if (body !== undefined) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; opt.headers['X-CSRF-Token'] = CSRF; opt.body = JSON.stringify(body); }
        const r = await fetch(ROOT + '/rhwp_fonts.php?action=' + action, opt);
        let j = null; try { j = await r.json(); } catch (e) {}
        return j || { success: false, error: 'http_' + r.status };
    }
    function busy(on) { if (!box) return; box.querySelectorAll('button[data-act]').forEach(b => { b.disabled = on; }); if (on) { const m = box.querySelector('.rf-msg'); m.textContent = T.loading; m.className = 'rf-msg'; } }
    function take(j, okMsg) {
        if (j && j.success) {
            data = j; rowErr = {}; dirty = false;
            rows = (j.folders || []).map(f => ({ path: f.path, enabled: !!f.enabled, ok: f.ok, error: f.error, faces: f.faces }));
            try { window.FsRhwpFonts && FsRhwpFonts.reset && FsRhwpFonts.reset(); } catch (e) {}
            render(okMsg, false);
        } else if (j && j.error === 'invalid_paths') {
            rowErr = {}; (j.errors || []).forEach(x => { rowErr[x.path] = x.error; });
            if ((j.errors || []).some(x => !x.path)) render(T.failed + ' — ' + errText('too_many'), true); else render(T.failed, true);
        } else render(j && j.csrf_error ? T.e_csrf : (T.failed + (j && j.error ? ' (' + j.error + ')' : '')), true);
    }

    window.fsFontFolderDialog = async function () {
        if (box) return;
        box = document.createElement('div');
        box.className = 'rf-mask';
        const sec = (n, title, extra) => '<div class="rf-sec" data-s="' + n + '"><div class="rf-sh"><span class="rf-num">' + n + '</span><b>' + esc(title) + '</b><span class="rf-badge-wrap"></span></div>' + (extra || '<div class="rf-sub"></div>') + '</div>';
        box.innerHTML =
            '<div class="rf-box" role="dialog" aria-modal="true" aria-label="' + esc(T.title) + '">' +
            '<div class="rf-head"><b>' + esc(T.title) + '</b><button type="button" class="rf-x" data-x aria-label="' + esc(T.close) + '">✕</button></div>' +
            '<p class="rf-desc">' + esc(T.intro) + '</p>' +
            sec(1, T.s1) +
            sec(2, T.s2, '<div class="rf-sub">' + esc(T.s2_desc) + '</div><div class="rf-folders"></div>' +
                '<div class="rf-add"><input type="text" class="rf-add-in" spellcheck="false" autocomplete="off" placeholder="' + esc(T.s2_add_ph) + '"><button type="button" data-add>' + esc(T.s2_add) + '</button></div>' +
                '<div class="rf-deferred rf-warn"></div><details class="rf-names"><summary></summary><div></div></details>') +
            sec(3, T.s3) +
            sec(4, T.s4) +
            '<p class="rf-note">' + esc(T.note) + '</p>' +
            '<div class="rf-msg"></div>' +
            '<div class="rf-btns"><button type="button" data-act="rescan">' + esc(T.rescan) + '</button><span></span>' +
            '<button type="button" data-x>' + esc(T.close) + '</button><button type="button" data-act="save" class="rf-primary">' + esc(T.save) + '</button></div>' +
            '</div>';
        document.body.appendChild(box);
        document.addEventListener('keydown', onKey, true);
        box.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', close));
        const addIn = box.querySelector('.rf-add-in');
        const doAdd = () => {
            const v = addIn.value.trim().replace(/\\/g, '/');
            if (!v) return;
            if (!rows.some(r => r.path === v)) rows.push({ path: v, enabled: true, saved: false });
            addIn.value = ''; dirty = true; render();
        };
        box.querySelector('[data-add]').addEventListener('click', doAdd);
        addIn.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } });
        box.querySelector('[data-act="save"]').addEventListener('click', async () => {
            if (addIn.value.trim()) doAdd();
            busy(true);
            try { take(await call('settings_save', { folders: rows.map(r => ({ path: r.path, enabled: r.enabled })) }), T.saved); } catch (e) { render(T.failed, true); }
            busy(false);
        });
        box.querySelector('[data-act="rescan"]').addEventListener('click', async () => {
            busy(true);
            try { take(await call('rescan', {}), T.scanned); } catch (e) { render(T.failed, true); }
            busy(false);
        });
        busy(true);
        try { take(await call('settings'), ''); } catch (e) { render(T.failed, true); }
        busy(false);
    };
})();
</script>
<style>
.rf-mask{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:100000;display:flex;align-items:center;justify-content:center;padding:12px}
.rf-box{background:#fff;color:#222;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,.25);width:440px;max-width:100%;max-height:calc(100vh - 24px);overflow:auto;padding:14px 16px;font:13px/1.45 -apple-system,BlinkMacSystemFont,'Segoe UI','Malgun Gothic',sans-serif;box-sizing:border-box}
.rf-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;font-size:15px}
.rf-x{border:0;background:none;font-size:16px;cursor:pointer;color:#666;padding:2px 6px}
.rf-desc{margin:0 0 8px;color:#555;font-size:12px}
.rf-sec{border:1px solid #e3e3e3;border-radius:6px;padding:7px 9px;margin-bottom:6px}
.rf-sh{display:flex;align-items:center;gap:6px}
.rf-sh b{flex:1;font-size:13px}
.rf-num{display:inline-flex;width:18px;height:18px;border-radius:50%;background:#e8eefc;color:#0b57d0;font-size:11px;font-weight:700;align-items:center;justify-content:center;flex-shrink:0}
.rf-sub{font-size:12px;color:#666;margin-top:3px;word-break:break-all}
.rf-badge{font-size:11px;padding:1px 7px;border-radius:9px;white-space:nowrap;font-weight:600}
.rf-badge.rf-on{background:#e6f4ea;color:#1a7f37}.rf-badge.rf-off{background:#eee;color:#777}.rf-badge.rf-warn{background:#fff4e0;color:#a15c00}
.rf-folders{margin-top:5px}
.rf-frow{display:flex;align-items:flex-start;gap:6px;padding:4px 0;border-top:1px dashed #eee}
.rf-frow:first-child{border-top:0}
.rf-chk{padding-top:1px}.rf-chk input{width:15px;height:15px;cursor:pointer;margin:0}
.rf-fmain{flex:1;min-width:0;display:flex;flex-wrap:wrap;align-items:baseline;column-gap:8px}
.rf-fpath{font:12.5px/1.35 Consolas,'D2Coding',monospace;word-break:break-all;flex:1 1 auto;min-width:0}
.rf-fst{font-size:12px;white-space:nowrap}
.rf-del{border:0;background:none;color:#999;cursor:pointer;font-size:13px;padding:0 4px}
.rf-del:hover{color:#c62828}
.rf-add{display:flex;gap:5px;margin-top:5px}
.rf-add-in{flex:1;min-width:0;font:12.5px Consolas,'D2Coding',monospace;padding:4px 6px;border:1px solid #bbb;border-radius:4px}
.rf-add button{padding:4px 10px;border:1px solid #bbb;background:#f5f5f5;border-radius:4px;cursor:pointer;font:inherit;font-size:12px}
.rf-deferred{font-size:12px;margin-top:3px}
.rf-names{margin-top:4px;font-size:12px}.rf-names summary{cursor:pointer;color:#0b57d0}.rf-names div{color:#555;margin-top:3px;max-height:80px;overflow:auto;word-break:break-all}
.rf-row{padding:2px 0}
.rf-ok{color:#1a7f37}.rf-bad{color:#c62828}.rf-warn{color:#a15c00}.rf-off{color:#888}
.rf-note{margin:6px 0 0;font-size:11.5px;color:#777}
.rf-msg{min-height:17px;font-weight:600;margin-top:4px;font-size:12.5px}
.rf-btns{display:flex;gap:6px;align-items:center;margin-top:6px;position:sticky;bottom:-14px;background:#fff;padding:6px 0 0}.rf-btns span{flex:1}
/* ★ (2026-11-18 22:23, 351차) 폰·낮은 화면: 설명 줄 숨기고 간격 줄여 스크롤 없이(버튼 줄은 늘 아래에 보이게 sticky) */
@media (max-width:520px),(max-height:720px){.rf-box{padding:10px 12px;font-size:12.5px}.rf-desc,.rf-sec[data-s="2"]>.rf-sub{display:none}.rf-sec{padding:5px 8px;margin-bottom:5px}.rf-sub{margin-top:1px;line-height:1.35}.rf-frow{padding:2px 0}.rf-note{margin-top:3px;line-height:1.35}.rf-head{font-size:14px;margin-bottom:2px}.rf-btns{bottom:-10px}.rf-btns button{padding:5px 12px}}
.rf-btns button{padding:6px 14px;border:1px solid #bbb;background:#f5f5f5;border-radius:4px;cursor:pointer;font:inherit}
.rf-btns button.rf-primary{background:#0b57d0;border-color:#0b57d0;color:#fff}
.rf-btns button:disabled{opacity:.55;cursor:default}
</style>
<?php endif; ?>
</body>
</html>
