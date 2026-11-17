<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * WebDAV 엔드포인트
 * 
 * Windows 네트워크 드라이브 연결:
 *   net use Z: http://서버주소/mydav.php
 * 또는 탐색기에서:
 *   \\서버주소@SSL\mydav.php (HTTPS)
 *   \\서버주소\mydav.php (HTTP)
 * ★ (2026-11-01 09:44, 38차 점검) 안내의 파일 이름을 실제 엔드포인트(mydav.php)로 고침 — 종전엔 없는 webdav.php 로 적혀 있었음
 */

// 디버그 모드
$DEBUG = false;

if ($DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/data/webdav_error.log');
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// 성능 최적화 설정
// ★ (2026-11-01 09:44, 38차 점검) 참고 — 아래 세 줄은 실제로는 효과가 없음(동작은 그대로 둠): max_execution_time·memory_limit 는 바로 다음 config.php 가 0·-1(제한 없음)로
//   다시 정하고, output_buffering 은 실행 중에 바꿀 수 없는 설정(PHP_INI_PERDIR)이라 ini_set 이 늘 실패함(php.ini·.htaccess 에서만 바뀜). 디버그 모드의 error_reporting 도 config.php 가 0 으로 덮음
ini_set('max_execution_time', 3600);       // 대용량 파일 전송 시간 확보
ini_set('memory_limit', '256M');           // 메모리 제한
ini_set('output_buffering', 'Off');        // 출력 버퍼링 비활성화 (스트리밍)

// 설정 파일 로드 (세션은 config.php에서 시작)
require_once __DIR__ . '/config.php';

// WebDAV: 세션 쿠키 발행 안 함 (클라이언트 호환), 인메모리 $_SESSION만 사용
// config.php에서 세션 쿠키 설정이 되지만, 실제 세션 시작 전에 쿠키 비활성화
if (session_status() === PHP_SESSION_NONE) {
    // ★ (2026-10-30 01:10, 25차 재검토) WebDAV 는 쿠키 없이 요청마다 새 세션이라, 세션 유지 '제한 없음'에서 config.php 가 늘린 수명(30일)을 쓰지 않고 종전처럼 24시간 —
    //   그대로 두면 WebDAV 요청마다 만든 1회성 세션이 30일씩 세션 저장소(redis 등)에 쌓였음
    ini_set('session.gc_maxlifetime', 86400);
    ini_set('session.gc_probability', 0);   // ★ (2026-10-30 01:50, 26차 점검) WebDAV 요청에서는 세션 정리를 돌리지 않음 — 파일 세션 저장소는 정리를 돌린 요청의 수명(24시간)으로 폴더 전체를 지워, '제한 없음'(30일) 웹 세션까지 지웠음(정리는 웹 요청이 30일 기준으로)
    ini_set('session.use_cookies', 0);
    // ★ (2026-11-01 01:53, 36차 전체 점검) 1 로 — 0 이면 주소·POST 의 ?PHPSESSID= 를 세션 ID 로 받아, 남(또는 자기 브라우저)의 웹 세션 ID 를 넣고 WebDAV(앱 비밀번호)로
    //   인증하면 그 웹 세션이 로그인 상태로 저장됐음(재현: 2FA 코드 없이 웹 로그인). 쿠키도 안 쓰므로(use_cookies=0) 어디서도 ID 를 받지 않고 늘 새 세션
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_trans_sid', 0);
    ini_set('session.cache_limiter', '');
    // ★ (2026-11-01 07:21, 37차 기능 점검) WebDAV 세션은 이 요청의 메모리에만 둠 — 종전엔 Auth 생성자의 session_start 로 요청마다 새 세션이 만들어져
    //   $_SESSION['user'](비밀번호 해시·2FA 비밀키·앱 비밀번호 해시가 든 사용자 행 전체)가 PUT 의 session_write_close·요청 끝에 저장소(파일·redis)에 기록되고 쌓였음(재현).
    //   메모리 저장 처리기라 한 요청 안의 session_write_close → 다시 session_start(FileManager 의 new Auth)는 종전처럼 같은 값을 읽음
    session_set_save_handler(new class implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
        private $mem = [];
        public function open($p, $n): bool { return true; }
        public function close(): bool { return true; }
        public function read($id): string|false { return $this->mem[$id] ?? ''; }
        public function write($id, $data): bool { $this->mem[$id] = $data; return true; }
        public function destroy($id): bool { unset($this->mem[$id]); return true; }
        public function gc($max): int|false { return 0; }
        public function validateId($id): bool { return isset($this->mem[$id]); }
        public function updateTimestamp($id, $data): bool { return true; }
    }, true);
}
// 세션이 이미 시작되었으면 닫고 $_SESSION만 메모리에 유지
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
// $_SESSION 배열은 메모리에서 사용 가능 (Storage/Auth에서 참조)

// ★ (2026-10-29 09:14, 20차) WebDAV 요청 표시 — 쿠키 없는 요청이라 매번 새 세션 ID 가 되므로 세션 목록(동시 세션 제한)에 기록하지 않게(Auth::recordSession).
//   종전엔 WebDAV 요청마다 세션이 하나씩 기록돼, 동시 세션 수를 넘으면 16차부터 같은 사용자의 웹 화면 세션이 강제로 끊겼음(재현)
if (!defined('FS_WEBDAV_REQUEST')) define('FS_WEBDAV_REQUEST', true);

require_once __DIR__ . '/api/JsonDB.php';
require_once __DIR__ . '/api/Auth.php';
require_once __DIR__ . '/api/Storage.php';
require_once __DIR__ . '/api/WebDAV.php';

// 인스턴스 생성
$db = JsonDB::getInstance();
$auth = new Auth();
$storage = new Storage();

// WebDAV 처리
$webdav = new WebDAV($db, $auth, $storage);
$webdav->handleRequest();
