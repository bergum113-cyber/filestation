<?php
/**
 * FileStation - 웹 파일 관리자
 * 시놀로지 파일스테이션 대체용 셀프호스팅 파일 관리 시스템
 *
 * @author   펜닐 (Pennil)
 * @version  5.8.2a
 * @license  All rights reserved
 */

// PHP 에러 로그 (디버깅 시 주석 해제)
// ini_set('log_errors', 1);
// ini_set('error_log', __DIR__ . '/data/php_error.log');

// 버전 (수정 시 상단 @version 주석도 함께 업데이트할 것!)
define('APP_VERSION', '5.8.2b');

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
    'audio' => ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'wma', 'opus', 'ape', 'aiff'],
    'document' => ['pdf', 'txt', 'md', 'html', 'htm'],
    'code' => ['php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'json', 'xml', 'sql', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'go', 'rs', 'sh', 'bat', 'ps1', 'yaml', 'yml', 'ini', 'conf']
]);

// SVG 아이콘 정의 (문서류만)
define('SVG_ICONS', [
    'folder' => '<svg viewBox="0 0 48 48" width="100%" height="100%"><path fill="#FFC107" d="M40 12H22l-4-4H8c-2.2 0-4 1.8-4 4v8h40v-4c0-2.2-1.8-4-4-4z"/><path fill="#FFCA28" d="M40 12H8c-2.2 0-4 1.8-4 4v20c0 2.2 1.8 4 4 4h32c2.2 0 4-1.8 4-4V16c0-2.2-1.8-4-4-4z"/></svg>',
    'pdf' => '<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M12 4h18l10 10v30c0 1.1-.9 2-2 2H12c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" fill="#F44336"/><path d="M28 4v10h10" fill="#FFCDD2"/><text x="24" y="34" text-anchor="middle" fill="#fff" font-size="10" font-weight="bold" font-family="Arial">PDF</text></svg>',
    'word' => '<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M12 4h18l10 10v30c0 1.1-.9 2-2 2H12c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" fill="#2196F3"/><path d="M28 4v10h10" fill="#BBDEFB"/><text x="24" y="34" text-anchor="middle" fill="#fff" font-size="8" font-weight="bold" font-family="Arial">DOC</text></svg>',
    'excel' => '<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M12 4h18l10 10v30c0 1.1-.9 2-2 2H12c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" fill="#4CAF50"/><path d="M28 4v10h10" fill="#C8E6C9"/><text x="24" y="34" text-anchor="middle" fill="#fff" font-size="8" font-weight="bold" font-family="Arial">XLS</text></svg>',
    'ppt' => '<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M12 4h18l10 10v30c0 1.1-.9 2-2 2H12c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" fill="#FF9800"/><path d="M28 4v10h10" fill="#FFE0B2"/><text x="24" y="34" text-anchor="middle" fill="#fff" font-size="8" font-weight="bold" font-family="Arial">PPT</text></svg>',
    'text' => '<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M12 4h18l10 10v30c0 1.1-.9 2-2 2H12c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" fill="#607D8B"/><path d="M28 4v10h10" fill="#CFD8DC"/><line x1="16" y1="24" x2="32" y2="24" stroke="#fff" stroke-width="2"/><line x1="16" y1="30" x2="32" y2="30" stroke="#fff" stroke-width="2"/><line x1="16" y1="36" x2="26" y2="36" stroke="#fff" stroke-width="2"/></svg>',
    'code' => '<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M12 4h18l10 10v30c0 1.1-.9 2-2 2H12c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" fill="#FFC107"/><path d="M28 4v10h10" fill="#FFECB3"/><text x="24" y="32" text-anchor="middle" fill="#333" font-size="7" font-weight="bold" font-family="Arial">&lt;/&gt;</text></svg>',
    'hwp' => '<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M12 4h18l10 10v30c0 1.1-.9 2-2 2H12c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" fill="#00BCD4"/><path d="M28 4v10h10" fill="#B2EBF2"/><text x="24" y="34" text-anchor="middle" fill="#fff" font-size="8" font-weight="bold" font-family="Arial">HWP</text></svg>',
    'default' => '<svg viewBox="0 0 48 48" width="100%" height="100%"><path d="M12 4h18l10 10v30c0 1.1-.9 2-2 2H12c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" fill="#9E9E9E"/><path d="M28 4v10h10" fill="#E0E0E0"/></svg>'
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
    'aiff' => '🎵', 'mid' => '🎵', 'midi' => '🎵',
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
