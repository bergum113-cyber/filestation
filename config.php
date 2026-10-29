<?php
/**
 * FileStation - 웹 파일 관리자
 * 시놀로지 파일스테이션 대체용 셀프호스팅 파일 관리 시스템
 *
 * @author   펜닐 (Pennil)
 * @version  5.8.12
 * @license  All rights reserved
 */

// PHP 에러 로그 (디버깅 시 주석 해제)
// ini_set('log_errors', 1);
// ini_set('error_log', __DIR__ . '/data/php_error.log');

// 버전 (수정 시 상단 @version 주석도 함께 업데이트할 것!)
define('APP_VERSION', '5.8.12');

// 압축 해제/목록 디버그 로그 스위치 (진단 시에만 true)
// true로 바꾸면 <DATA_PATH>/extract_debug.log 에 압축 처리 과정이 기록됨.
// 진단이 끝나면 반드시 false로 되돌릴 것 (로그 파일이 계속 커짐).
if (!defined('EXTRACT_DEBUG')) define('EXTRACT_DEBUG', false);

// 위험 확장자 목록 (전역 — 업로드/이름변경/게시판 공통)
// 서버 실행 가능 확장자 (모든 환경에서 항상 차단 — 업로드 설정과 무관)
define('SERVER_EXEC_EXTS', ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps',
    'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'htaccess', 'user.ini']);

// 파일 탐색기 위험 확장자 (편집 제한 등에 사용)
define('DANGEROUS_EXTS', ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps',
    'jsp', 'jspx', 'asp', 'aspx', 'exe', 'cgi', 'pl', 'py', 'rb', 'sh', 'bat', 'cmd', 'com',
    'htaccess', 'user.ini']);

// 게시판 전용 추가 차단 확장자 (XSS 벡터)
define('BOARD_DANGEROUS_EXTS', ['html', 'htm', 'shtml', 'xhtml', 'svg']);

/**
 * ★ (2026-10-28 18:22, 소스 점검 5단계) OnlyOffice 문서 키 등록부 — onlyoffice.php 가 편집기를 열 때 발급한 키만 인정.
 *   키 → {storage_id, path, mode(edit|view), exp}. 다운로드(onlyoffice_download)·저장 콜백(onlyoffice_callback)이 이 기록으로
 *   '그 파일에 대해 실제로 발급한 키인지' 확인(종전엔 형식만 맞으면 통과했고, 콜백은 서명만 보고 대상 파일과 묶지 않았음).
 *   저장은 edit 로 발급한 키만. 24시간 지나면 지움.
 */
function fsOoKeyFile(): string { return (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data')) . '/onlyoffice_keys.json'; }
function fsOoKeyRegister(string $key, int $storageId, string $path, string $mode): void {
    $f = fsOoKeyFile();
    $fp = @fopen($f, 'c+');
    if (!$fp) return;
    if (flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        $all = json_decode($raw ?: '[]', true) ?: [];
        $now = time();
        foreach ($all as $k => $v) { if (($v['exp'] ?? 0) < $now) unset($all[$k]); }
        $prev = $all[$key] ?? null;
        // 같은 키를 보기·편집 둘 다로 열었으면 편집 권한 기록을 유지
        $all[$key] = ['storage_id' => $storageId, 'path' => $path,
            'mode' => ($mode === 'edit' || (($prev['mode'] ?? '') === 'edit' && ($prev['storage_id'] ?? 0) === $storageId && ($prev['path'] ?? '') === $path)) ? 'edit' : 'view',
            'exp' => $now + 86400];
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($all, JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}
function fsOoKeyCheck(string $key, int $storageId, string $path, bool $needEdit = false): bool {
    if ($key === '' || !preg_match('/^[a-f0-9]{32}$/i', $key)) return false;
    // ★ (2026-10-28 19:55, 6차 점검) 등록(ftruncate→fwrite) 도중에 읽으면 빈 파일로 보여 저장 콜백이 거부될 수 있었음 → 공유 잠금으로 읽기
    $raw = '';
    $fp = @fopen(fsOoKeyFile(), 'r');
    if ($fp) { if (flock($fp, LOCK_SH)) { $raw = (string)stream_get_contents($fp); flock($fp, LOCK_UN); } fclose($fp); }
    $all = json_decode($raw ?: '[]', true) ?: [];
    $r = $all[$key] ?? null;
    if (!$r || ($r['exp'] ?? 0) < time()) return false;
    if ((int)($r['storage_id'] ?? 0) !== $storageId || (string)($r['path'] ?? '') !== $path) return false;
    if ($needEdit && ($r['mode'] ?? '') !== 'edit') return false;
    return true;
}

/**
 * ★ (2026-10-28 17:42, 소스 점검 3단계) 서버에서 실행될 수 있는 파일 이름인지 — 업로드·이름 바꾸기·내부 공유·파일 드롭·WebDAV 공통.
 *   실제로 저장될 이름 기준(FileManager::sanitizeFilename 과 같은 정리: 위험 문자 제거 + 앞뒤 점·공백 제거)으로
 *   마지막 확장자와 중간 확장자('x.php.jpg') 를 SERVER_EXEC_EXTS 와 비교. 막아야 하면 사유, 괜찮으면 null.
 *   (종전엔 경로마다 정리 전 이름으로 검사하거나 아예 검사가 없어 'x.p:hp' → 'x.php' 저장, 'a.txt' → 'a.php' 이름 바꾸기가 됐음)
 *   ★ (2026-10-28 19:55, 6차 점검) 종전엔 ':' 를 지운 이름만 봐서 'x.php::$DATA'(NTFS 에선 x.php 로 저장)가 'x.php$data' 로 읽혀 통과했고,
 *   앞 점을 지운 뒤 비교해 '.htaccess'·'.user.ini' 도 통과했음(받은 이름 그대로 쓰는 내부 공유·파일 드롭·WebDAV 에서 만들어짐).
 *   → ':' 를 지운 이름과 ':' 앞까지 자른 이름 둘 다 검사하고, .htaccess/.htpasswd/.user.ini 는 이름으로 막음
 */
function fsServerExecReason(string $name): ?string {
    $name = basename(str_replace('\\', '/', $name));
    $variants = [preg_replace('/[<>:"\/\\|?*\x00-\x1f]/', '', $name)];                       // 위험 문자를 지운 이름(일반 업로드·이름 바꾸기에 저장되는 이름)
    if (strpos($name, ':') !== false) {
        $variants[] = preg_replace('/[<>"\/\\|?*\x00-\x1f]/', '', explode(':', $name, 2)[0]); // ':' 앞까지(NTFS 스트림 'x.php::$DATA' → x.php)
    }
    $tr = function ($k, $d) { return function_exists('__') ? __($k, $d) : $d; };
    foreach ($variants as $v) {
        $low = strtolower($v);
        // ★ (2026-10-29 18:30, 22차 점검) IIS 의 같은 역할 파일 web.config 도 — 종전엔 빠져, IIS 에 설치하고 users/ 를 웹 루트 안에 두면 사용자가 올린 web.config 로 그 폴더의 차단 규칙을 풀 수 있었음
        if (in_array(rtrim(ltrim($low, ' '), '. '), ['.htaccess', '.htpasswd', '.user.ini', 'web.config'], true)) {
            return $tr('api_err_dangerous_filename', '보안상 허용되지 않는 파일명입니다');
        }
        $n = trim($low, '. ');
        if ($n === '') continue;
        $ext = pathinfo($n, PATHINFO_EXTENSION);
        if (in_array($ext, SERVER_EXEC_EXTS, true)) return $tr('api_err_dangerous_ext', '보안상 허용되지 않는 파일 형식입니다') . ": .$ext";
        $parts = explode('.', $n);
        for ($i = 1; $i < count($parts) - 1; $i++) {
            if (in_array($parts[$i], SERVER_EXEC_EXTS, true)) return $tr('api_err_dangerous_filename', '보안상 허용되지 않는 파일명입니다');
        }
    }
    return null;
}

// 에러 리포팅 (운영 모드)
error_reporting(0);
ini_set('display_errors', 0);

// PHP 업로드/메모리 제한 해제 (대용량 vault 파일 지원)
// 주의: upload_max_filesize, post_max_size는 ini_set으로 변경 불가 — .htaccess 또는 php.ini에서 설정 필요
@ini_set('upload_max_filesize', '10G');
@ini_set('post_max_size', '10G');
@ini_set('memory_limit', '-1');

// PHP 실행 시간 제한 해제
@ini_set('max_execution_time', 0);
@set_time_limit(0);

// 타임존 (3중 설정으로 Windows/Apache 환경에서 확실히 적용)
// 1) PHP date() 함수용
date_default_timezone_set('Asia/Seoul');
// 2) PHP ini 설정 (일부 함수가 참조)
@ini_set('date.timezone', 'Asia/Seoul');
// 3) 시스템 환경 변수 TZ (PHP 재초기화 되어도 유지됨)
@putenv('TZ=Asia/Seoul');

/**
 * 타임존 안전 날짜 포맷 함수
 * - date() 함수가 어떤 이유로든 타임존 설정을 못 읽어도 무조건 Asia/Seoul 기준으로 반환
 * - 파일명, 폴더명 등 "절대 타임존이 틀리면 안 되는" 곳에 사용
 * - DB의 created_at/updated_at 등 일반 로그는 기존 date() 사용해도 무방 (위 3중 설정으로 충분)
 */
function kstDate(string $format = 'Y-m-d H:i:s', ?int $timestamp = null): string {
    try {
        $dt = new \DateTime($timestamp !== null ? '@' . $timestamp : 'now');
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));
        return $dt->format($format);
    } catch (\Throwable $e) {
        return $timestamp !== null ? date($format, $timestamp) : date($format);
    }
}

// UTF-8 로케일 설정 (시놀로지/Linux에서 한글·일본어 파일명 처리에 필요)
setlocale(LC_ALL, 'en_US.UTF-8', 'C.UTF-8', 'UTF-8');

// 세션 설정 (세션 시작 전에만 설정)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax'); // Lax: 외부 네비게이션(홈 화면 바로가기, 앱 전환) 시에도 세션 쿠키 전송
    ini_set('session.gc_maxlifetime', 86400); // 24시간 (실제 타임아웃은 Auth에서 처리)
    // ★ (2026-10-30 00:44, 25차 — 펜닐 선택 '설정값에 맞춰 수명 늘림') 세션 유지 '제한 없음'(0)이면 PHP 세션 수명(redis TTL·파일 정리 기준)도 30일로 —
    //   종전엔 늘 24시간이라 '로그인 유지'를 안 고른 사용자는 '제한 없음'이어도 24시간 무활동이면 로그아웃됐음. 24시간 넘는 값이면 그 값으로(지금 화면 선택지는 최대 24시간).
    //   요청마다 JSON 전체를 풀지 않게 settings.json 에서 이 키만 읽음(못 읽으면 종전 24시간)
    $_fsSessSet = @file_get_contents(__DIR__ . '/data/settings.json');
    if (is_string($_fsSessSet) && preg_match('/"session_timeout"\s*:\s*"?(\d+)"?/', $_fsSessSet, $_fsSessM)) {
        $_fsSessMin = (int)$_fsSessM[1];
        if ($_fsSessMin === 0) ini_set('session.gc_maxlifetime', 2592000);
        elseif ($_fsSessMin * 60 > 86400) ini_set('session.gc_maxlifetime', min($_fsSessMin * 60, 31536000));
    }
    unset($_fsSessSet, $_fsSessM, $_fsSessMin);
    
    // HTTPS 자동 감지 (직접 HTTPS 또는 프록시 뒤 HTTPS)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    if ($isHttps) {
        ini_set('session.cookie_secure', 1);
    }
}

// ===== CSRF 토큰 관리 =====
/**
 * CSRF 토큰 생성 또는 기존 토큰 반환
 */
function getCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * CSRF 토큰 검증
 */
function validateCsrfToken(?string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * CSRF 토큰 재생성 (로그인 후 등)
 */
function regenerateCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

// 기본 설정
define('APP_NAME', 'FileStation');
define('BASE_PATH', __DIR__);
define('DATA_PATH', BASE_PATH . '/data');

// 사용자 폴더 설정 (동적 로드)
// home 타입 스토리지는 USER_FILES_ROOT/계정명 으로 자동 계산됨
// 
// ★★★ 보안 권고 ★★★
// 기본값은 웹 루트 내 폴더(/users, /shared)입니다.
// .htaccess로 직접 접근을 차단하지만, 더 안전하게 사용하려면
// 웹 루트 밖의 경로로 설정하세요:
//   예: C:\webhard_files\users 또는 /var/webhard/users
// storage_paths.json 파일에서 설정 가능합니다.
//
$_storageSettings = [];
$_storageSettingsFile = __DIR__ . '/data/storage_paths.json';
if (file_exists($_storageSettingsFile)) {
    $_storageSettings = json_decode(file_get_contents($_storageSettingsFile), true) ?: [];
}

// 개인 폴더 루트 (기본값: ./users)
$_userFilesRoot = $_storageSettings['user_files_root'] ?? '';
if (empty($_userFilesRoot)) {
    $_userFilesRoot = __DIR__ . '/users';
}
define('USER_FILES_ROOT', rtrim($_userFilesRoot, '/\\'));

// 공유 폴더 루트 (기본값: ./shared)
$_sharedFilesRoot = $_storageSettings['shared_files_root'] ?? '';
if (empty($_sharedFilesRoot)) {
    $_sharedFilesRoot = __DIR__ . '/shared';
}
define('SHARED_FILES_ROOT', rtrim($_sharedFilesRoot, '/\\'));

// 휴지통 경로 (기본값: ./data/trash_files)
$_trashPath = $_storageSettings['trash_path'] ?? '';
if (empty($_trashPath)) {
    $_trashPath = __DIR__ . '/data/trash_files';
}
define('TRASH_PATH', rtrim($_trashPath, '/\\'));

define('AUTO_CREATE_USER_FOLDER', true);  // 로그인 시 자동 생성

// 업로드 설정 (0 = 무제한)
define('MAX_UPLOAD_SIZE', 0); // 무제한
define('CHUNK_SIZE', 10 * 1024 * 1024); // 10MB 청크

// 공유 링크 설정
define('SHARE_LINK_LENGTH', 16);
define('SHARE_DEFAULT_EXPIRE_DAYS', 7);

// ===== 로그인 보안 설정 =====
// 로그인 유지 (Remember Me)
define('REMEMBER_ME_ENABLED', true);
define('REMEMBER_ME_DAYS', 3650);  // 쿠키 유효 기간 (10년 = 무제한)
define('REMEMBER_ME_TOKEN_LENGTH', 64);

// 브루트포스 방지
define('LOGIN_MAX_ATTEMPTS', 5);  // 최대 시도 횟수
define('LOGIN_LOCKOUT_MINUTES', 15);  // 잠금 시간

// 세션 관리
define('SESSION_TRACKING_ENABLED', true);
define('SESSION_MAX_CONCURRENT', 5);  // 동시 세션 최대 수

// 로그인 로그 설정
define('LOGIN_LOG_ENABLED', true);
// 로그 보관 기간 (0 = 무제한, 자동 삭제 없음 - 관리자가 수동으로 삭제 가능)
define('LOGIN_LOG_RETENTION_DAYS', 0);

// 2FA (TOTP) 설정 (동적 로드)
define('TOTP_ENABLED', true);  // 사용자별 2FA 활성화 허용

// TOTP 설정을 JSON 파일에서 로드 (관리자 설정 페이지에서 변경 가능)
$_totpSettingsFile = __DIR__ . '/data/totp_settings.json';
$_totpSettings = [];
if (file_exists($_totpSettingsFile)) {
    $_totpSettings = json_decode(file_get_contents($_totpSettingsFile), true) ?: [];
}

// TOTP_ISSUER: QR 코드에 표시될 발급자명
$_totpIssuer = $_totpSettings['issuer'] ?? '';
if (empty($_totpIssuer)) {
    $_totpIssuer = 'WebHard';
}
define('TOTP_ISSUER', $_totpIssuer);

// TOTP_ENCRYPTION_KEY: 시크릿 암호화 키
$_totpEncryptionKey = $_totpSettings['encryption_key'] ?? '';
if (empty($_totpEncryptionKey)) {
    // JSON에 키가 없는 경우: 2FA 사용자 여부 확인
    $_has2faUsers = false;
    $_usersFile = __DIR__ . '/data/users.json';
    if (file_exists($_usersFile)) {
        $_users = json_decode(file_get_contents($_usersFile), true) ?: [];
        foreach ($_users as $_u) {
            if (!empty($_u['2fa_enabled'])) {
                $_has2faUsers = true;
                break;
            }
        }
    }
    
    if ($_has2faUsers) {
        // 기존 2FA 사용자가 있으면 기본 키 사용 (호환성 유지)
        $_totpEncryptionKey = 'change-this-to-your-secret-key-32chars';
        // 보안 경고 로그 기록
        error_log('[FileStation] WARNING: TOTP encryption key is using default value. Please set a custom key in data/totp_settings.json');
    } else {
        // 2FA 사용자가 없으면 새 랜덤 키 생성 후 저장
        $_totpEncryptionKey = bin2hex(random_bytes(32));
        $_totpSettings['encryption_key'] = $_totpEncryptionKey;
        $_totpSettings['issuer'] = $_totpIssuer;
        $_totpSettings['generated_at'] = date('Y-m-d H:i:s');
        @file_put_contents($_totpSettingsFile, json_encode($_totpSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
define('TOTP_ENCRYPTION_KEY', $_totpEncryptionKey);

// ===== 이메일 설정 (비밀번호 찾기) =====
// SMTP 서버 설정 (선택사항, 비어있으면 PHP mail() 사용)
// define('SMTP_HOST', 'smtp.gmail.com');    // SMTP 서버 주소
// define('SMTP_PORT', 587);                  // 포트 (587: TLS, 465: SSL)
// define('SMTP_USER', 'your-email@gmail.com');
// define('SMTP_PASS', 'your-app-password');
// define('SMTP_FROM', 'your-email@gmail.com');
// define('SMTP_SECURE', 'tls');              // 'tls' 또는 'ssl'

// 사이트명 (이메일에 표시)
define('SITE_NAME', 'WebHard');

// IP/국가 제한 (빈 배열 = 제한 없음)
define('ALLOWED_IPS', []);  // 예: ['192.168.1.0/24', '10.0.0.1']
define('BLOCKED_IPS', []);
define('ALLOWED_COUNTRIES', []);  // 예: ['KR', 'US']
define('BLOCKED_COUNTRIES', []);

// 신뢰할 수 있는 리버스 프록시 IP (빈 배열 = 프록시 헤더 무시, REMOTE_ADDR만 사용)
// Cloudflare, Nginx 리버스 프록시 등 사용 시 프록시 IP를 등록하세요.
// 등록하지 않으면 X-Forwarded-For 등 헤더를 무시하여 IP 스푸핑을 방지합니다.
// 예: ['127.0.0.1', '::1', '173.245.48.0/20', '103.21.244.0/22']
define('TRUSTED_PROXIES', []);

// 썸네일 설정
define('THUMB_SIZE', 200);
define('THUMB_QUALITY', 80);

// 허용 미리보기 확장자
define('PREVIEW_EXTENSIONS', [
    'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'tiff', 'tif', 'heic', 'heif'],
    'video' => ['mp4', 'webm', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'ts', 'm2ts', 'mts', 'vob', 'rmvb', '3gp', 'mpg', 'mpeg', 'm4v'],
    'audio' => ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'wma', 'opus', 'ape', 'aiff', 'oga', 'm4b', 'weba'],   // 파일 종류(아이콘·필터). ★ (2026-10-08) oga·m4b·weba 추가 — 실제 재생 여부는 app.js previewExtensions
    'document' => ['pdf', 'txt', 'md', 'html', 'htm'],
    'code' => ['php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'json', 'xml', 'sql', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'go', 'rs', 'sh', 'bat', 'ps1', 'yaml', 'yml', 'ini', 'conf']
]);

// SVG 아이콘 정의 (문서류만)
// ★ (2026-10-26) 색 채운 그림 → 선 그림(assets/file-icons/*.svg 와 같은 모양·색, 흰 바탕·어두운 바탕 모두 대비 3 이상) — 펜닐: 아이콘 전부 선 그림으로
//   폴더만 테두리 + 노란 칠(펜닐: 윈도우 탐색기처럼 노란색)
define('SVG_ICONS', [
    'folder' => '<svg width="100%" height="100%" viewBox="0 0 48 48" stroke="#a8740a" stroke-width="2" stroke-linejoin="round"><path fill="#e9a922" d="M6 37V11a3 3 0 0 1 3-3h9l4 5h17a3 3 0 0 1 3 3v21a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3z"/><path fill="#ffcc4d" d="M6 19.5h36V37a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3z"/></svg>',
    'pdf' => '<svg width="100%" height="100%" viewBox="0 0 48 48" fill="none" stroke="#e53935" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13 5h15l10 10v26a2 2 0 0 1-2 2H13a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M28 5v10h10"/><path d="M21.01 30.53Q21.01 31.16 20.72 31.66Q20.44 32.16 19.9 32.43Q19.36 32.7 18.63 32.7H17V35H15.64V28.46H18.57Q19.75 28.46 20.38 29Q21.01 29.54 21.01 30.53ZM19.63 30.56Q19.63 29.53 18.42 29.53H17V31.65H18.46Q19.02 31.65 19.33 31.37Q19.63 31.08 19.63 30.56ZM27.8 31.68Q27.8 32.69 27.4 33.45Q27 34.2 26.28 34.6Q25.55 35 24.62 35H21.97V28.46H24.34Q25.99 28.46 26.89 29.3Q27.8 30.13 27.8 31.68ZM26.42 31.68Q26.42 30.63 25.87 30.08Q25.33 29.52 24.31 29.52H23.34V33.94H24.5Q25.38 33.94 25.9 33.33Q26.42 32.73 26.42 31.68ZM30.2 29.52V31.54H33.55V32.6H30.2V35H28.83V28.46H33.65V29.52Z" fill="#e53935" stroke="none"/></svg>',
    'word' => '<svg width="100%" height="100%" viewBox="0 0 48 48" fill="none" stroke="#1e6fd9" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13 5h15l10 10v26a2 2 0 0 1-2 2H13a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M28 5v10h10"/><path d="M20.41 31.68Q20.41 32.69 20.01 33.45Q19.61 34.2 18.89 34.6Q18.16 35 17.22 35H14.58V28.46H16.95Q18.6 28.46 19.5 29.3Q20.41 30.13 20.41 31.68ZM19.03 31.68Q19.03 30.63 18.48 30.08Q17.93 29.52 16.92 29.52H15.95V33.94H17.11Q17.99 33.94 18.51 33.33Q19.03 32.73 19.03 31.68ZM27.8 31.7Q27.8 32.72 27.39 33.5Q26.99 34.27 26.24 34.68Q25.49 35.09 24.48 35.09Q22.94 35.09 22.07 34.19Q21.19 33.28 21.19 31.7Q21.19 30.13 22.07 29.25Q22.94 28.37 24.49 28.37Q26.05 28.37 26.92 29.26Q27.8 30.15 27.8 31.7ZM26.4 31.7Q26.4 30.64 25.9 30.04Q25.4 29.44 24.49 29.44Q23.57 29.44 23.07 30.04Q22.57 30.64 22.57 31.7Q22.57 32.78 23.09 33.4Q23.6 34.02 24.48 34.02Q25.4 34.02 25.9 33.41Q26.4 32.81 26.4 31.7ZM31.88 34.02Q33.12 34.02 33.6 32.77L34.8 33.22Q34.41 34.17 33.67 34.63Q32.92 35.09 31.88 35.09Q30.31 35.09 29.44 34.2Q28.58 33.31 28.58 31.7Q28.58 30.09 29.41 29.23Q30.24 28.37 31.82 28.37Q32.97 28.37 33.7 28.83Q34.42 29.29 34.71 30.19L33.51 30.51Q33.35 30.02 32.91 29.73Q32.46 29.44 31.85 29.44Q30.92 29.44 30.44 30.02Q29.96 30.59 29.96 31.7Q29.96 32.83 30.46 33.42Q30.95 34.02 31.88 34.02Z" fill="#1e6fd9" stroke="none"/></svg>',
    'excel' => '<svg width="100%" height="100%" viewBox="0 0 48 48" fill="none" stroke="#21a165" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13 5h15l10 10v26a2 2 0 0 1-2 2H13a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M28 5v10h10"/><path d="M20.08 35 18.43 32.4 16.79 35H15.35L17.61 31.56L15.54 28.46H16.98L18.43 30.77L19.89 28.46H21.32L19.34 31.56L21.52 35ZM22.23 35V28.46H23.6V33.94H27.11V35ZM33.37 33.12Q33.37 34.08 32.65 34.58Q31.94 35.09 30.57 35.09Q29.31 35.09 28.59 34.65Q27.88 34.2 27.68 33.3L29 33.08Q29.13 33.6 29.52 33.83Q29.91 34.07 30.6 34.07Q32.04 34.07 32.04 33.2Q32.04 32.92 31.87 32.74Q31.71 32.56 31.41 32.43Q31.11 32.31 30.26 32.14Q29.53 31.97 29.24 31.87Q28.95 31.76 28.72 31.62Q28.49 31.48 28.32 31.28Q28.16 31.08 28.07 30.81Q27.98 30.54 27.98 30.19Q27.98 29.31 28.65 28.84Q29.31 28.37 30.58 28.37Q31.8 28.37 32.41 28.75Q33.02 29.13 33.2 30L31.87 30.19Q31.77 29.76 31.45 29.55Q31.14 29.34 30.56 29.34Q29.31 29.34 29.31 30.12Q29.31 30.37 29.44 30.53Q29.58 30.7 29.84 30.81Q30.1 30.92 30.89 31.09Q31.83 31.29 32.24 31.46Q32.64 31.63 32.88 31.86Q33.12 32.08 33.24 32.4Q33.37 32.71 33.37 33.12Z" fill="#21a165" stroke="none"/></svg>',
    'ppt' => '<svg width="100%" height="100%" viewBox="0 0 48 48" fill="none" stroke="#e8590c" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13 5h15l10 10v26a2 2 0 0 1-2 2H13a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M28 5v10h10"/><path d="M21.27 30.53Q21.27 31.16 20.99 31.66Q20.7 32.16 20.16 32.43Q19.63 32.7 18.89 32.7H17.27V35H15.9V28.46H18.83Q20.01 28.46 20.64 29Q21.27 29.54 21.27 30.53ZM19.9 30.56Q19.9 29.53 18.68 29.53H17.27V31.65H18.72Q19.28 31.65 19.59 31.37Q19.9 31.08 19.9 30.56ZM27.61 30.53Q27.61 31.16 27.32 31.66Q27.04 32.16 26.5 32.43Q25.96 32.7 25.23 32.7H23.6V35H22.23V28.46H25.17Q26.34 28.46 26.98 29Q27.61 29.54 27.61 30.53ZM26.23 30.56Q26.23 29.53 25.02 29.53H23.6V31.65H25.05Q25.62 31.65 25.93 31.37Q26.23 31.08 26.23 30.56ZM31.52 29.52V35H30.15V29.52H28.04V28.46H33.64V29.52Z" fill="#e8590c" stroke="none"/></svg>',
    'text' => '<svg width="100%" height="100%" viewBox="0 0 48 48" fill="none" stroke="#5f7d95" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13 5h15l10 10v26a2 2 0 0 1-2 2H13a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M28 5v10h10"/><line x1="17" y1="24" x2="32" y2="24"/><line x1="17" y1="29.5" x2="32" y2="29.5"/><line x1="17" y1="35" x2="27" y2="35"/></svg>',
    'code' => '<svg width="100%" height="100%" viewBox="0 0 48 48" fill="none" stroke="#6a6fb5" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13 5h15l10 10v26a2 2 0 0 1-2 2H13a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M28 5v10h10"/><polyline points="20 24 16 28.5 20 33"/><polyline points="29 24 33 28.5 29 33"/></svg>',
    'hwp' => '<svg width="100%" height="100%" viewBox="0 0 48 48" fill="none" stroke="#099bb4" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13 5h15l10 10v26a2 2 0 0 1-2 2H13a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M28 5v10h10"/><path d="M18.6 35V32.35H15.9V35H14.6V28.81H15.9V31.27H18.6V28.81H19.89V35ZM27.39 35H25.85L25.01 31.42Q24.86 30.79 24.75 30.1Q24.65 30.67 24.58 30.97Q24.51 31.27 23.64 35H22.1L20.51 28.81H21.82L22.72 32.81L22.92 33.77Q23.05 33.16 23.16 32.61Q23.28 32.05 24.04 28.81H25.49L26.27 32.1Q26.36 32.47 26.58 33.77L26.69 33.26L26.93 32.25L27.67 28.81H28.99ZM34.69 30.77Q34.69 31.37 34.42 31.84Q34.15 32.31 33.64 32.56Q33.13 32.82 32.43 32.82H30.89V35H29.6V28.81H32.38Q33.49 28.81 34.09 29.32Q34.69 29.83 34.69 30.77ZM33.39 30.79Q33.39 29.81 32.23 29.81H30.89V31.82H32.27Q32.81 31.82 33.1 31.56Q33.39 31.29 33.39 30.79Z" fill="#099bb4" stroke="none"/></svg>',
    'default' => '<svg width="100%" height="100%" viewBox="0 0 48 48" fill="none" stroke="#878d97" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13 5h15l10 10v26a2 2 0 0 1-2 2H13a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M28 5v10h10"/></svg>'
]);

// 확장자 → SVG 아이콘 타입 매핑 (문서/코드류만)
define('EXT_TO_SVG_TYPE', [
    // PDF
    'pdf' => 'pdf',
    // Word
    'doc' => 'word', 'docx' => 'word', 'odt' => 'word', 'rtf' => 'word',
    // Excel
    'xls' => 'excel', 'xlsx' => 'excel', 'ods' => 'excel', 'csv' => 'excel',
    // PowerPoint
    'ppt' => 'ppt', 'pptx' => 'ppt', 'odp' => 'ppt',
    // 텍스트
    'txt' => 'text', 'md' => 'text', 'markdown' => 'text', 'log' => 'text',
    // HWP
    'hwp' => 'hwp', 'hwpx' => 'hwp',
    // 코드
    'html' => 'code', 'htm' => 'code', 'css' => 'code', 'scss' => 'code', 'sass' => 'code', 'less' => 'code',
    'js' => 'code', 'jsx' => 'code', 'tsx' => 'code', 'vue' => 'code', 'svelte' => 'code',
    'php' => 'code', 'py' => 'code', 'rb' => 'code', 'java' => 'code', 'kt' => 'code', 'scala' => 'code',
    'go' => 'code', 'rs' => 'code', 'swift' => 'code', 'cs' => 'code', 'vb' => 'code',
    'c' => 'code', 'cpp' => 'code', 'cc' => 'code', 'cxx' => 'code', 'h' => 'code', 'hpp' => 'code',
    'sh' => 'code', 'bash' => 'code', 'zsh' => 'code', 'fish' => 'code',
    'ps1' => 'code', 'psm1' => 'code', 'bat' => 'code', 'cmd' => 'code',
    'json' => 'code', 'xml' => 'code', 'yaml' => 'code', 'yml' => 'code', 'toml' => 'code',
    'ini' => 'code', 'conf' => 'code', 'cfg' => 'code', 'env' => 'code',
    'sql' => 'code', 'db' => 'code', 'sqlite' => 'code'
]);

// 이모지 아이콘 (이미지/동영상/음악/압축/실행/폰트/링크)
define('EMOJI_ICONS', [
    // 이미지
    'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'gif' => '🖼️', 'webp' => '🖼️',
    'bmp' => '🖼️', 'svg' => '🖼️', 'ico' => '🖼️', 'tiff' => '🖼️', 'tif' => '🖼️',
    'raw' => '🖼️', 'psd' => '🖼️', 'ai' => '🖼️', 'eps' => '🖼️', 'heic' => '🖼️', 'heif' => '🖼️',
    // 동영상
    'mp4' => '🎬', 'mkv' => '🎬', 'avi' => '🎬', 'mov' => '🎬', 'wmv' => '🎬',
    'flv' => '🎬', 'webm' => '🎬', 'ts' => '🎬', 'm2ts' => '🎬', 'mts' => '🎬',
    'vob' => '🎬', 'rmvb' => '🎬', 'rm' => '🎬', '3gp' => '🎬', '3g2' => '🎬',
    'mpg' => '🎬', 'mpeg' => '🎬', 'm4v' => '🎬', 'f4v' => '🎬', 'asf' => '🎬',
    // 음악
    'mp3' => '🎵', 'wav' => '🎵', 'flac' => '🎵', 'aac' => '🎵', 'ogg' => '🎵',
    'm4a' => '🎵', 'wma' => '🎵', 'opus' => '🎵', 'ape' => '🎵', 'alac' => '🎵',
    'aiff' => '🎵', 'mid' => '🎵', 'midi' => '🎵', 'oga' => '🎵', 'm4b' => '🎵', 'weba' => '🎵',
    // 압축
    'zip' => '📦', 'rar' => '📦', '7z' => '📦', 'tar' => '📦', 'gz' => '📦',
    'xz' => '📦', 'bz2' => '📦', 'lz' => '📦', 'cab' => '📦', 'iso' => '📦',
    'tgz' => '📦', 'tbz2' => '📦', 'lzma' => '📦', 'z' => '📦', '001' => '📦',
    // 실행 파일
    'exe' => '⚙️', 'msi' => '⚙️', 'dll' => '⚙️', 'so' => '⚙️', 'dylib' => '⚙️',
    'apk' => '⚙️', 'app' => '⚙️', 'dmg' => '⚙️', 'deb' => '⚙️', 'rpm' => '⚙️',
    // 폰트
    'ttf' => '🔤', 'otf' => '🔤', 'woff' => '🔤', 'woff2' => '🔤', 'eot' => '🔤',
    // 링크
    'torrent' => '🔗', 'url' => '🔗', 'lnk' => '🔗'
]);

// PHP 버전 호환성 함수
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return 0 === strncmp($haystack, $needle, strlen($needle));
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return '' === $needle || ('' !== $haystack && 0 === substr_compare($haystack, $needle, -strlen($needle)));
    }
}

// 자동 로더
spl_autoload_register(function ($class) {
    $file = BASE_PATH . '/api/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// StorageAdapter 클래스들 로드 (FTP/SFTP/WebDAV/S3 원격 스토리지 지원)
require_once BASE_PATH . '/api/StorageAdapter.php';

// 다국어 시스템 로드
if (file_exists(BASE_PATH . '/lang.php')) {
    require_once BASE_PATH . '/lang.php';
}

// ===== 디버그 설정 =====
// 업로드 성능 디버그 (true: 로그 기록, false: 비활성화)
define('DEBUG_UPLOAD', false);
// 디버그 로그 파일 경로 (기본값: data/debug_upload.log)
// define('DEBUG_LOG_FILE', DATA_PATH . '/debug_upload.log');
// 디버그 로그 최대 크기 (기본값: 5MB)
// define('DEBUG_LOG_MAX_SIZE', 5 * 1024 * 1024);

// ===== API Rate Limiting 설정 =====
// 분당 최대 요청 수 (0 = 무제한)
define('API_RATE_LIMIT', 120);
// Rate Limit 윈도우 (초)
define('API_RATE_WINDOW', 60);

// 데이터 폴더 생성
if (!is_dir(DATA_PATH)) {
    mkdir(DATA_PATH, 0755, true);
}

// ===== 보안: 스토리지 폴더 보호 =====
// .htaccess 자동 생성 함수
function createStorageProtection(string $path): void {
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    
    $htaccessPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccessPath)) {
        $content = "# FileStation Storage Protection\n";
        $content .= "# URL 직접 접근 차단\n\n";
        $content .= "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n\n";
        $content .= "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n\n";
        $content .= "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule .* - [F,L]\n</IfModule>\n";
        @file_put_contents($htaccessPath, $content);
    }
}

// 기본 스토리지 폴더 보호
createStorageProtection(USER_FILES_ROOT);
createStorageProtection(SHARED_FILES_ROOT);
createStorageProtection(TRASH_PATH);
createStorageProtection(DATA_PATH);
createStorageProtection(DATA_PATH . '/board_files');

// ===== 공통 유틸리티 함수 =====
/**
 * 파일 크기를 읽기 쉬운 형식으로 변환
 * @param int|float $bytes 바이트 크기
 * @return string 포맷된 크기 문자열 (예: "1.5 GB")
 */
function formatFileSize($bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $bytes = (float)$bytes;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// ===== FileStation 로거 (정보성 로그는 파일로, 에러만 Apache로) =====
function fsLog(string $message, string $level = 'info'): void {
    $logFile = DATA_PATH . '/filestation.log';
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    // 로그 파일 크기 제한
    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        $bakFile = DATA_PATH . '/filestation.log.bak';
        @rename($logFile, $bakFile);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[{$timestamp}] [{$level}] {$message}\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    
    // error/warning만 Apache error.log에도 출력
    if ($level === 'error' || $level === 'warning') {
        error_log("[FileStation] {$message}");
    }
}

// ===== 경로 prefix 안전 비교 =====
function isSubPath(string $child, string $parent): bool {
    if (DIRECTORY_SEPARATOR === '\\') {
        return stripos($child . '\\', $parent . '\\') === 0
               || strcasecmp($child, $parent) === 0;
    }
    return strpos($child . '/', $parent . '/') === 0
           || $child === $parent;
}

// ===== 스토리지 config 암호화/복호화 =====
function encryptStorageConfig(array $config): string {
    $json = json_encode($config);
    $key = defined('TOTP_ENCRYPTION_KEY') ? TOTP_ENCRYPTION_KEY : 'default-storage-key-change-me!!';
    $key = hash('sha256', $key, true);
    $iv = random_bytes(12);
    $encrypted = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($encrypted === false) {
        error_log('[FileStation] WARNING: openssl_encrypt failed for storage config — falling back to base64 (NOT encrypted)');
        return base64_encode($json); // 폴백 (암호화 실패 시)
    }
    return 'ENC:' . base64_encode($iv . $tag . $encrypted);
}

function decryptStorageConfig(string $data): array {
    if (empty($data)) return [];
    // 암호화된 형식 (ENC: 접두사)
    if (strpos($data, 'ENC:') === 0) {
        $key = defined('TOTP_ENCRYPTION_KEY') ? TOTP_ENCRYPTION_KEY : 'default-storage-key-change-me!!';
        $key = hash('sha256', $key, true);
        $raw = base64_decode(substr($data, 4));
        if (strlen($raw) < 28) return []; // iv(12) + tag(16) = 28 최소
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $encrypted = substr($raw, 28);
        $json = openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($json === false) return [];
        return json_decode($json, true) ?: [];
    }
    // 레거시 base64 형식 (하위 호환)
    $decoded = @json_decode(@base64_decode($data), true);
    return is_array($decoded) ? $decoded : [];
}

// ===== 버전 마이그레이션 =====
// api.php에서 실행됨 (Storage/JsonDB 초기화 이후)
