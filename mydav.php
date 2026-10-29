<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * WebDAV 엔드포인트
 * 
 * Windows 네트워크 드라이브 연결:
 *   net use Z: http://서버주소/webdav.php
 * 또는 탐색기에서:
 *   \\서버주소@SSL\webdav.php (HTTPS)
 *   \\서버주소\webdav.php (HTTP)
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
    ini_set('session.use_only_cookies', 0);
    ini_set('session.cache_limiter', '');
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
