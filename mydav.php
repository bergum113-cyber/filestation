<?php
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
    ini_set('session.use_cookies', 0);
    ini_set('session.use_only_cookies', 0);
    ini_set('session.cache_limiter', '');
}
// 세션이 이미 시작되었으면 닫고 $_SESSION만 메모리에 유지
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
// $_SESSION 배열은 메모리에서 사용 가능 (Storage/Auth에서 참조)

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
