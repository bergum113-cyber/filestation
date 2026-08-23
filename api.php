<?php
require_once __DIR__ . '/php_version_check.php';
require_once __DIR__ . '/api/RarNative.php';
/**
 * API Router - REST API 엔드포인트
 */
set_time_limit(120);

// 오류를 JSON으로 반환하도록 설정
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// 운영 환경 여부 (개발 시 true로 변경)
define('DEBUG_MODE', false);

set_exception_handler(function($e) {
    header('Content-Type: application/json; charset=utf-8');
    if (DEBUG_MODE) {
        echo json_encode([
            'success' => false, 
            'error' => 'Server Error: ' . $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]);
    } else {
        // 운영 환경: 상세 정보 숨김
        error_log('API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode([
            'success' => false, 
            'error' => __('api_err_server', '서버 오류가 발생했습니다.')
        ]);
    }
    exit;
});

require_once __DIR__ . '/config.php';

// phpseclib3 autoloader
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}
require_once __DIR__ . '/lang.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// 보안 헤더
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
// CSP 헤더 (frame-ancestors 'self'로 PDF/이미지 미리보기 허용)
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self';");

// CORS 설정 (같은 도메인 또는 지정된 도메인만 허용)
$allowedOrigins = []; // 필요시 허용할 도메인 추가: ['https://yourdomain.com']
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (empty($allowedOrigins)) {
    // 같은 도메인만 허용 (Origin 헤더 없는 경우 = 같은 도메인)
    if (!empty($origin)) {
        $serverHost = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                      . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        if ($origin === $serverHost) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }
    }
} elseif (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 클래스 로드
$db = JsonDB::getInstance();
$auth = new Auth();
$storage = new Storage();
$fileManager = new FileManager();
$shareManager = new ShareManager();

// 활동 로그
require_once __DIR__ . '/api/ActivityLog.php';
require_once __DIR__ . '/api/JobManager.php';
$activityLog = new ActivityLog($db, $auth);

// ===== 클라이언트 IP 감지 =====
/**
 * 클라이언트 IP를 안전하게 감지
 * TRUSTED_PROXIES에 등록된 IP에서 온 요청만 프록시 헤더 신뢰
 * 그 외에는 REMOTE_ADDR만 사용 (헤더 조작 방지)
 */

/**
 * 압축 비밀번호를 안전하게 명령행 -p 인자로 변환 (FileManager::buildPasswordArg와 동일 규칙).
 *
 * ⚠️ 비밀번호 문자를 제거하면 안 된다. 과거 $ ` " \ 를 위험문자라며 지워서
 *   "12#$" 입력이 "12#"로 잘려 7z/UnRAR이 "비밀번호 틀림"을 내던 버그가 있었다(로그 확정).
 *   제어문자만 제거하고 셸 인용은 플랫폼 규칙대로 한다.
 *   - Windows(bat -p"..."): % → %%, " 제거(인용 불가), 나머지 보존
 *   - Linux: escapeshellarg가 전부 안전 처리
 * 빈 비번이면 -p"" (헤더 암호화 프롬프트 멈춤 방지).
 */
function fs_build_password_arg(string $password): string {
    $pw = preg_replace('/[\x00-\x1F\x7F]/', '', $password);
    if ($pw === '') {
        return (DIRECTORY_SEPARATOR === '\\') ? ' -p""' : " -p''";
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        $pw = str_replace('%', '%%', $pw);
        $pw = str_replace('"', '', $pw);
        return ' -p"' . $pw . '"';
    }
    return ' -p' . escapeshellarg($pw);
}

/**
 * 7-Zip/UnRAR 등 외부 압축 도구의 출력 파일명을 환경(로케일/콘솔)과 무관하게 UTF-8로 받기 위한
 * 명령행 prefix를 반환한다.
 *
 * 배경: 7z 네이티브 포맷은 파일명을 UTF-16(유니코드)로 저장하지만, CLI가 stdout에 쓸 때의 인코딩은
 *   실행 환경에 의존한다.
 *   - Linux/시놀로지 도커 등 p7zip: LANG/LC_CTYPE 로케일을 따른다. 로케일이 UTF-8이 아니면(C, POSIX 등)
 *     한글 등 비ASCII 파일명이 깨진다. → LANG/LC_ALL을 UTF-8로 강제하면 항상 UTF-8 출력.
 *   - Windows: chcp 65001(호출부에서 처리) + 출력 파일 리다이렉트로 처리하므로 여기선 prefix 불필요.
 *
 * 다양한 서버 환경(Windows+Apache, 시놀로지 도커, 일반 리눅스)에서 일관되게 동작하도록 하는 핵심.
 *
 * @return string Linux: "LANG=C.UTF-8 LC_ALL=C.UTF-8 " (뒤 공백 포함) / Windows: "" (빈 문자열)
 */
function fs_utf8_env_prefix(): string {
    if (DIRECTORY_SEPARATOR === '\\') return '';
    return 'LANG=C.UTF-8 LC_ALL=C.UTF-8 ';
}

function getClientIP(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    // 신뢰할 수 있는 프록시 목록 (config.php에서 정의)
    $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : [];
    
    if (empty($trustedProxies)) {
        // 신뢰 프록시 미설정: REMOTE_ADDR만 사용 (가장 안전)
        return $remoteAddr;
    }
    
    // 현재 연결이 신뢰할 수 있는 프록시에서 온 것인지 확인
    $isTrusted = false;
    foreach ($trustedProxies as $proxy) {
        if (strpos($proxy, '/') !== false) {
            // CIDR 표기 (예: 173.245.48.0/20)
            if (ipInCidr($remoteAddr, $proxy)) {
                $isTrusted = true;
                break;
            }
        } else {
            if ($remoteAddr === $proxy) {
                $isTrusted = true;
                break;
            }
        }
    }
    
    if (!$isTrusted) {
        return $remoteAddr;
    }
    
    // 신뢰할 수 있는 프록시 → 프록시 헤더에서 원본 IP 추출
    // Cloudflare 전용 헤더 우선
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // 첫 번째 IP가 원본 클라이언트
        $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        return $ips[0];
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    
    return $remoteAddr;
}

/**
 * IP가 CIDR 범위에 포함되는지 확인
 */
function ipInCidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) return $ip === $cidr;
    list($subnet, $bits) = explode('/', $cidr);
    $bits = (int)$bits;
    
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) return false;
    if (strlen($ipBin) !== strlen($subnetBin)) return false; // IPv4 vs IPv6 혼합 방지
    
    $byteLen = strlen($ipBin);
    $fullBytes = intdiv($bits, 8);
    $remainBits = $bits % 8;
    
    // 전체 바이트 비교
    for ($i = 0; $i < $fullBytes && $i < $byteLen; $i++) {
        if ($ipBin[$i] !== $subnetBin[$i]) return false;
    }
    // 나머지 비트 비교
    if ($remainBits > 0 && $fullBytes < $byteLen) {
        $mask = 0xFF << (8 - $remainBits) & 0xFF;
        if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) return false;
    }
    return true;
}

// ===== OnlyOffice 경로 해석 헬퍼 =====
/**
 * OnlyOffice용 스토리지 경로 해석 (다운로드/콜백 공통)
 * @return array{basePath: string, fullPath: string}|null 실패 시 null
 */
function resolveOnlyOfficePath(JsonDB $db, int $storageId, string $filePath): ?array {
    $allStorages = $db->load('storages');
    $storageInfo = null;
    foreach ($allStorages as $s) {
        if (($s['id'] ?? 0) == $storageId) {
            $storageInfo = $s;
            break;
        }
    }
    if (!$storageInfo) return null;
    
    if (($storageInfo['storage_type'] ?? '') === 'home') {
        $ownerId = $storageInfo['owner_id'] ?? 0;
        $user = $db->find('users', ['id' => $ownerId]);
        $username = $user['username'] ?? 'unknown';
        $storageInfo['path'] = USER_FILES_ROOT . DIRECTORY_SEPARATOR . $username;
    }
    if (($storageInfo['storage_type'] ?? '') === 'shared') {
        $storageInfo['path'] = SHARED_FILES_ROOT;
    }
    
    $basePath = $storageInfo['path'] ?? '';
    $fullPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath), '/\\');
    
    // 경로 탐색 방지
    if (preg_match('#(^|[\\/])\\.\\.($|[\\/])#', $filePath)) {
        return null;
    }
    
    // 경로 안전성 검사
    $realBasePath = realpath($basePath);
    if (!$realBasePath) return null;
    
    $realFullPath = realpath($fullPath);
    if ($realFullPath !== false) {
        $cmp = \isSubPath($realFullPath, $realBasePath);
        if (!$cmp) return null;
    } else {
        // realpath 실패 (특수문자 파일명) — dirname 기반 확인
        $realParent = realpath(dirname($fullPath));
        if (!$realParent) return null;
        $cmp = \isSubPath($realParent, $realBasePath);
        if (!$cmp) return null;
    }
    
    return ['basePath' => $basePath, 'fullPath' => $fullPath];
}

// ===== API Rate Limiting =====
/**
 * 간단한 파일 기반 Rate Limiting
 * 분당 요청 수 제한 (config.php의 API_RATE_LIMIT, API_RATE_WINDOW 참조)
 */
function checkRateLimit(string $ip, int $maxRequests = 120, int $windowSeconds = 60): bool {
    $rateLimitDir = DATA_PATH . '/rate_limits';
    if (!is_dir($rateLimitDir)) {
        @mkdir($rateLimitDir, 0755, true);
    }
    
    // IP 기반 파일명 (해시로 안전하게)
    $file = $rateLimitDir . '/' . md5($ip) . '.json';
    $now = time();
    
    // 파일 핸들 + 배타적 락으로 읽기→쓰기 원자성 보장
    $handle = @fopen($file, 'c+');
    if (!$handle) {
        return true; // 파일 열기 실패 시 허용
    }
    
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return true;
    }
    
    $content = '';
    $stat = fstat($handle);
    if ($stat['size'] > 0) {
        $content = fread($handle, $stat['size']);
    }
    $data = @json_decode($content, true) ?: [];
    
    // 윈도우 시간 초과된 요청 제거
    $data = array_filter($data, fn($t) => ($now - $t) < $windowSeconds);
    
    // 제한 초과 체크
    if (count($data) >= $maxRequests) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return false;  // Rate limit exceeded
    }
    
    // 현재 요청 기록
    $data[] = $now;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode(array_values($data)));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    
    // 1% 확률로 오래된 파일 정리
    if (mt_rand(1, 100) === 1) {
        cleanupRateLimitFiles($rateLimitDir, 3600);
    }
    
    return true;
}

/**
 * 오래된 rate limit 파일 정리
 */
function cleanupRateLimitFiles(string $dir, int $maxAge = 3600): void {
    if (!is_dir($dir)) return;
    
    $now = time();
    foreach (glob($dir . '/*.json') as $file) {
        if (($now - filemtime($file)) > $maxAge) {
            @unlink($file);
        }
    }
}

// Rate Limiting 적용 (자주 호출되는 액션은 제외)
$action = $_GET['action'] ?? '';
$rateLimitExclude = [
    // 파일 읽기/다운로드 (대량 연속 요청)
    'download', 'share_download', 'files', 'locked_files_get',
    // 업로드 (청크 단위 연속 요청 — 제한하면 대용량 업로드 불가)
    'upload', 'upload_chunk', 'board_post_upload', 'board_post_upload_chunk', 'board_comment_upload_chunk', 'internal_share_chunk_upload', 'filedrop_chunk_upload',
    // 인증 및 초기화 (페이지 로드 시 즉시 호출)
    'me', 'logout', 'signup_status', 'csrf_token',
    // 초기화 시 호출되는 읽기 전용 API
    'storages', 'storages_all', 'storages_for_index', 'favorites', 'recent_files', 'trash_list',
    'favorites_get', 'recent_files_get', 'settings', 'site_settings_get',
    'storage_quota', 'storage_recalc', 'storage_get', 'storage_permissions',
    'board_list_all', 'board_posts', 'board_manage_list', 'board_notifications',
    'board_unread_count', 'board_post_view',
    'share_count', 'shares', 'share_check', 'notice_active',
    'user_preferences_get', 'pending_users_count', 'qos_user',
    // 사용자/관리 조회 (읽기 전용)
    'users', 'roles', 'sessions', 'server_stats', 'system_info',
    // 로그/통계 조회
    'activity_logs', 'activity_stats', 'login_logs', 'access_stats',
    // 파일 정보 조회
    'check_quota', 'info', 'detailed_info', 'size', 'search', 'search_all',
    // 즐겨찾기/최근파일 (빠른 연속 클릭)
    'favorites_add', 'favorites_remove', 'recent_files_add', 'recent_files_add_batch',
    // 내부 공유 조회
    'internal_shares_by_me', 'internal_shares_with_me', 'internal_share_count',
    'internal_share_list_folder',
    // 진행률 폴링 (자동 반복 요청)
    'copy_progress', 'delete_progress', 'count_items',
    'job_status', 'job_list',
    // 인덱스 (주기적 폴링)
    'index_sync', 'index_stats', 'index_lookup', 'index_rebuild_stream',
    // 썸네일 (파일 목록에서 대량 요청)
    'thumbnail',
    // 오디오 커버 (플레이리스트 각 트랙당 1회씩 요청)
    'audio_cover',
    // 오디오 가사 (LRC > USLT > TXT 우선순위)
    'audio_lyrics',
    // 세션 keepalive (5분마다 호출되지만 여러 탭 동시 사용 시 겹칠 수 있음)
    'session_ping',
    // 클라이언트 상태 보고 (디버깅용 — session_debug.log 있을 때만 실제 기록)
    'client_state',
    // OnlyOffice (외부 서버 콜백)
    'onlyoffice_download', 'onlyoffice_callback', 'onlyoffice_config',
    // E2E Vault (청크 업로드/다운로드 — 대량 연속 요청)
    'vault_upload', 'vault_download', 'vault_list', 'vault_info',
    'vault_convert_file', 'vault_convert_delete_original', 'vault_convert_init',
    'vault_decrypt_save', 'vault_decrypt_save_chunk', 'vault_delete', 'vault_server_copy',
    'vault_preview_temp', 'vault_preview_serve', 'vault_preview_cleanup', 'vault_preview_touch',
    // 스트리밍 (세그먼트 연속 요청)
    'transcode', 'hls_stream',
    // ZIP 내부 목록 조회
    'zip_list', 'archive_list', 'archive_preview'
];
if (!in_array($action, $rateLimitExclude)) {
    $clientIP = getClientIP();
    if (strpos($clientIP, ',') !== false) {
        $clientIP = trim(explode(',', $clientIP)[0]);
    }
    
    // config.php에 정의된 상수 사용 (API_RATE_LIMIT=120, API_RATE_WINDOW=60)
    $rateLimit = defined('API_RATE_LIMIT') ? API_RATE_LIMIT : 120;
    $rateWindow = defined('API_RATE_WINDOW') ? API_RATE_WINDOW : 60;
    
    if (!checkRateLimit($clientIP, $rateLimit, $rateWindow)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => __('api_err_too_many_requests', '요청이 너무 많습니다. 잠시 후 다시 시도하세요.')]);
        exit;
    }
}

// 업로드 계열 별도 Rate Limit (분당 1000회 — 폴더 드래그 + 청크 업로드 고려)
$uploadRateLimitActions = ['upload', 'upload_chunk', 'filedrop_upload', 'filedrop_chunk_upload', 'internal_share_chunk_upload'];
if (in_array($action, $uploadRateLimitActions)) {
    $clientIP = getClientIP();
    if (strpos($clientIP, ',') !== false) {
        $clientIP = trim(explode(',', $clientIP)[0]);
    }
    if (!checkRateLimit($clientIP . '_upload', 1000, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => __('api_err_too_many_requests', '요청이 너무 많습니다. 잠시 후 다시 시도하세요.')]);
        exit;
    }
}

// 쓰기 액션 별도 Rate Limit (분당 120회 — 즐겨찾기/최근파일 등)
$writeRateLimitActions = ['favorites_add', 'favorites_remove', 'recent_files_add', 'recent_files_add_batch'];
if (in_array($action, $writeRateLimitActions)) {
    $clientIP = getClientIP();
    if (strpos($clientIP, ',') !== false) {
        $clientIP = trim(explode(',', $clientIP)[0]);
    }
    if (!checkRateLimit($clientIP . '_write', 120, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => __('api_err_too_many_requests', '요청이 너무 많습니다. 잠시 후 다시 시도하세요.')]);
        exit;
    }
}

// ===== CSRF 토큰 검증 =====
$csrfExclude = [
    // 인증 관련
    'server_config', 'login', 'logout', 'csrf_token', 'signup', 'signup_status', 
    'password_reset_request', 'find_username',
    // 2FA 관련 (읽기 전용만 제외, 상태 변경은 CSRF 필요)
    '2fa_status', '2fa_verify',
    // 공유 링크 (외부 접근)
    'share_access', 'share_download', 'filedrop_upload', 'filedrop_chunk_upload',
    // 파일 다운로드 (GET 요청 또는 세션만으로 보호)
    'download', 'temp_download',
    // 압축 스트리밍 (SSE)
    'extract_stream', 'compress_stream', 'folder_download_stream', 'multi_download_stream', 'index_rebuild_stream',
    // OnlyOffice (외부 서버 콜백 - JWT로 보호)
    'onlyoffice_download', 'onlyoffice_callback', 'onlyoffice_config',
    // 읽기 전용 API (세션 인증으로 보호됨)
    'list', 'files', 'search', 'search_all', 'search_advanced', 'search_realtime',
    'storages', 'storages_all', 'storages_for_index', 'storage_permissions',
    'users', 'user', 'me', 'pending_users_count',
    'sessions', 'login_logs', 'roles',
    'settings', 'security_settings',
    'index_stats', 'index_lookup', 'index_sync',
    'activity_logs', 'activity_stats',
    'shares', 'share_check',
    'check_quota', 'size', 'info', 'detailed_info',
    'trash_list',
    'server_stats', 'system_info',
    'network_share_detect', 'smb_detect',
    // 오디오 duration 조회 (POST 바디에 파일 목록, 읽기 전용)
    'audio_durations', 'media_info',
    // 썸네일 (GET 요청)
    'thumbnail',
    // 오디오 커버 (GET 요청, ID3v2 APIC 추출)
    'audio_cover',
    // 오디오 가사 (GET 요청, LRC > USLT > TXT)
    'audio_lyrics',
    // 읽기 전용 즐겨찾기 목록
    'favorites',
    // E2E Vault (GET 요청 — 읽기/다운로드)
    'vault_info', 'vault_list', 'vault_download', 'vault_convert_file',
    // E2E Vault cleanup (sendBeacon에서 CSRF 토큰 없이 호출)
    'vault_preview_cleanup',
    // 디버그 로그 (v5.8.1g 임시 진단 도구 — 인증/CSRF 무관 동작)
    'debug_log',
    // SSO 콜백 (외부 IdP에서 리다이렉트)
    'sso_config', 'sso_ldap_login', 'sso_oidc_auth', 'sso_oidc_callback', 'sso_oidc_silent_auth',
    'sso_saml_auth', 'sso_saml_callback', 'sso_saml_metadata',
    // 스트리밍 (GET 기반)
    'transcode', 'hls_stream',
    // ZIP 내부 목록 (GET 기반)
    'zip_list', 'archive_list', 'archive_preview',
    // 미디어 재생 중 세션 유지용 heartbeat (세션 인증만 있음, 상태 변경 없음)
    'session_ping',
    // 클라이언트 상태 보고 (디버깅용 — 세션 인증만 있음, 상태 변경 없음)
    'client_state',
    // ★ HLS 진단 로그 (펜닐님 임시 진단용 — 세션 인증만 있음, 다음 디버깅 위해 보존)
    'hls_diag_log',
    // ★ 커버 진단 로그 (펜닐님 임시 진단용 — iOS 음악 썸네일 누락 디버깅)
    'cover_diag_log'
];

// _get으로 끝나는 읽기 전용 API — 명시적 목록 (자동 와일드카드 제거)
$csrfExcludeGet = [
    'favorites_get', 'locked_files_get', 'qos_get', 'recent_files_get',
    'site_settings_get', 'sso_settings_get', 'storage_get', 'storage_paths_get',
    'user_preferences_get'
];
$isCsrfExcluded = in_array($action, $csrfExclude) || in_array($action, $csrfExcludeGet);

// POST 요청이고 CSRF 검증 필요한 액션인 경우
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isCsrfExcluded) {
    // 헤더 또는 POST/GET 데이터에서 토큰 확인
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? null;
    
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error' => __('api_err_invalid_csrf', '보안 토큰이 유효하지 않습니다. 페이지를 새로고침 해주세요.'),
            'csrf_error' => true
        ]);
        exit;
    }
}

// ===== QoS 헬퍼 함수 =====
/**
 * QoS 설정 파일 로드
 */
function loadQosSettings(): array {
    $qosFile = __DIR__ . '/data/qos_settings.json';
    return file_exists($qosFile) 
        ? json_decode(file_get_contents($qosFile), true) ?: []
        : [];
}

/**
 * 사용자의 QoS 속도 제한 계산
 * @param array $user 사용자 정보
 * @param string $type 'download' 또는 'upload'
 * @return int 속도 제한 (0 = 무제한)
 */
function getUserQosLimit(array $user, string $type = 'download'): int {
    $qosSettings = loadQosSettings();
    $limit = 0;
    
    // 역할 기본값
    $role = $user['role'] ?? 'user';
    if (isset($qosSettings['roles'][$role][$type])) {
        $limit = (int)$qosSettings['roles'][$role][$type];
    }
    
    // 사용자 개별 설정 (우선 적용)
    $userId = $user['id'];
    if (isset($qosSettings['users'][$userId][$type]) && $qosSettings['users'][$userId][$type] !== null) {
        $limit = (int)$qosSettings['users'][$userId][$type];
    }
    
    return $limit;
}

/**
 * HTML 새니타이징 - 게시판 콘텐츠용
 * 허용 태그 화이트리스트 + 위험 속성 제거
 */
/**
 * EXIF orientation 보정 (사진 90도 회전 현상 수정)
 * 라이믹스 스타일: 업로드된 이미지의 EXIF 회전 정보를 읽어 자동으로 올바른 방향으로 회전
 */
function fixImageOrientation(string $filePath): bool {
    if (!file_exists($filePath)) return false;
    
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    // JPEG만 EXIF 정보를 가짐 (PNG, WebP, GIF는 해당 없음)
    if (!in_array($ext, ['jpg', 'jpeg'])) return false;
    
    // exif 확장 필요
    if (!function_exists('exif_read_data')) return false;
    
    $exif = @exif_read_data($filePath);
    if (!$exif || !isset($exif['Orientation'])) return false;
    
    $orientation = (int)$exif['Orientation'];
    // 1 = 정상, 나머지는 회전/반전 필요
    if ($orientation <= 1 || $orientation > 8) return false;
    
    $src = @imagecreatefromjpeg($filePath);
    if (!$src) return false;
    
    $rotated = null;
    switch ($orientation) {
        case 2: // 좌우 반전
            imageflip($src, IMG_FLIP_HORIZONTAL);
            $rotated = $src;
            break;
        case 3: // 180도 회전
            $rotated = imagerotate($src, 180, 0);
            break;
        case 4: // 상하 반전
            imageflip($src, IMG_FLIP_VERTICAL);
            $rotated = $src;
            break;
        case 5: // 좌우 반전 + 시계 270도
            imageflip($src, IMG_FLIP_HORIZONTAL);
            $rotated = imagerotate($src, 270, 0);
            break;
        case 6: // 시계 270도 (= 반시계 90도)
            $rotated = imagerotate($src, -90, 0);
            break;
        case 7: // 좌우 반전 + 시계 90도
            imageflip($src, IMG_FLIP_HORIZONTAL);
            $rotated = imagerotate($src, 90, 0);
            break;
        case 8: // 시계 90도 (= 반시계 270도)
            $rotated = imagerotate($src, 90, 0);
            break;
    }
    
    if (!$rotated) {
        imagedestroy($src);
        return false;
    }
    
    // 원본 파일에 덮어쓰기
    $result = imagejpeg($rotated, $filePath, 92);
    
    if ($rotated !== $src) imagedestroy($src);
    imagedestroy($rotated);
    
    return $result;
}

/**
 * data-video-src 플레이스홀더를 <video> 태그로 변환 (공통 헬퍼)
 */
function convertVideoSrcPlaceholders(string $content): string {
    return preg_replace_callback(
        '/<img([^>]*?)data-video-src="([^"]*)"([^>]*?)\/?>/i',
        function($m) {
            $attrs = $m[1] . $m[3];
            $videoSrc = $m[2];
            $style = 'max-width:100%;border-radius:6px;';
            if (preg_match('/style="([^"]*)"/', $attrs, $sm)) {
                $parts = [];
                if (preg_match('/width\s*:\s*[^;]+/', $sm[1], $wm)) $parts[] = $wm[0];
                if (preg_match('/height\s*:\s*[^;]+/', $sm[1], $hm)) $parts[] = $hm[0];
                if ($parts) $style = implode(';', $parts) . ';max-width:100%;border-radius:6px;';
            }
            $wAttr = $hAttr = '';
            if (preg_match('/\bwidth="(\d+)"/', $attrs, $wm2)) $wAttr = ' width="' . $wm2[1] . '"';
            if (preg_match('/\bheight="(\d+)"/', $attrs, $hm2)) $hAttr = ' height="' . $hm2[1] . '"';
            if (!preg_match('#^(https?://|api\.php\?)#i', $videoSrc)) $videoSrc = '';
            return '<video controls playsinline src="' . $videoSrc . '"' . $wAttr . $hAttr . ' style="' . $style . '"></video>';
        },
        $content
    );
}

function sanitizeHtml(string $html): string {
    if (empty(trim($html))) return '';
    
    // ===== DOMDocument 기반 화이트리스트 새니타이저 =====
    // 허용 태그 화이트리스트
    $allowedTags = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'del', 'strike', 'sub', 'sup',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'a', 'img', 'figure', 'figcaption', 'div', 'span',
        'video', 'source', 'audio',
        'dl', 'dt', 'dd', 'details', 'summary'
    ];
    
    // 태그별 허용 속성 화이트리스트
    $allowedAttrs = [
        'a'       => ['href', 'title', 'target', 'rel'],
        'img'     => ['src', 'alt', 'title', 'width', 'height', 'style', 'loading', 'data-video-src', 'data-video-pending', 'data-pending-name'],
        'video'   => ['src', 'controls', 'width', 'height', 'preload', 'poster', 'playsinline', 'data-pending-name'],
        'audio'   => ['src', 'controls', 'preload'],
        'source'  => ['src', 'type'],
        'td'      => ['colspan', 'rowspan', 'style'],
        'th'      => ['colspan', 'rowspan', 'style'],
        'col'     => ['span', 'style'],
        'colgroup'=> ['span'],
        'ol'      => ['start', 'type'],
        'table'   => ['style'],
        'div'     => ['style', 'class'],
        'span'    => ['style', 'class'],
        'p'       => ['style'],
        'h1'      => ['style'], 'h2' => ['style'], 'h3' => ['style'],
        'h4'      => ['style'], 'h5' => ['style'], 'h6' => ['style'],
        'blockquote' => ['style'],
        'pre'     => ['class'],
        'code'    => ['class'],
        'details' => ['open'],
    ];
    
    // style 속성에서 허용할 CSS 속성 화이트리스트
    $allowedCssProperties = [
        'color', 'background-color', 'background',
        'font-size', 'font-weight', 'font-style', 'font-family',
        'text-align', 'text-decoration', 'text-indent',
        'line-height', 'letter-spacing', 'word-spacing',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-style', 'border-width', 'border-collapse', 'border-spacing',
        'width', 'height', 'max-width', 'max-height', 'min-width', 'min-height',
        'vertical-align', 'white-space', 'word-break', 'overflow-wrap',
        'display', 'float', 'clear', 'list-style-type', 'list-style',
        'opacity', 'visibility',
    ];
    
    // DOMDocument로 파싱
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    // UTF-8 인코딩 보장 + fragment 파싱
    $wrapped = '<div id="__sanitize_root__">' . $html . '</div>';
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><html><body>' . $wrapped . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR
    );
    libxml_clear_errors();
    
    // 허용되지 않은 태그 제거 (자식 노드는 보존)
    $xpath = new DOMXPath($doc);
    
    // 반복적으로 처리 (DOM 변경 시 안전하게)
    do {
        $changed = false;
        $allElements = $xpath->query('//*');
        foreach ($allElements as $el) {
            $tagName = strtolower($el->nodeName);
            // 파싱용 래퍼 태그 및 기본 HTML 구조는 건너뜀
            if (in_array($tagName, ['html', 'head', 'body', 'div']) && $el->getAttribute('id') !== '__sanitize_root__') {
                if ($tagName === 'div' && !in_array('div', $allowedTags)) {
                    // div가 허용 태그에 없으면 제거
                } else {
                    continue;
                }
            }
            if ($el->getAttribute('id') === '__sanitize_root__') continue;
            
            if (!in_array($tagName, $allowedTags)) {
                // 허용되지 않은 태그: 자식 노드를 부모로 이동 후 태그 제거
                $parent = $el->parentNode;
                if ($parent) {
                    while ($el->firstChild) {
                        $parent->insertBefore($el->firstChild, $el);
                    }
                    $parent->removeChild($el);
                    $changed = true;
                    break; // DOM이 변경되었으므로 다시 순회
                }
            }
        }
    } while ($changed);
    
    // 허용되지 않은 속성 제거 + 속성값 검증
    $allElements = $xpath->query('//*');
    foreach ($allElements as $el) {
        $tagName = strtolower($el->nodeName);
        if (in_array($tagName, ['html', 'head', 'body']) || $el->getAttribute('id') === '__sanitize_root__') continue;
        
        $tagAllowed = $allowedAttrs[$tagName] ?? [];
        
        // 모든 속성 순회 (역순으로 제거)
        $attrsToRemove = [];
        for ($i = 0; $i < $el->attributes->length; $i++) {
            $attr = $el->attributes->item($i);
            $attrName = strtolower($attr->name);
            
            // on* 이벤트 핸들러 무조건 제거 (모든 태그)
            if (preg_match('/^on/i', $attrName)) {
                $attrsToRemove[] = $attr->name;
                continue;
            }
            
            // 허용 목록에 없는 속성 제거
            if (!in_array($attrName, $tagAllowed)) {
                $attrsToRemove[] = $attr->name;
                continue;
            }
            
            // href/src 속성: URL 프로토콜 화이트리스트 검증
            if (in_array($attrName, ['href', 'src', 'poster', 'background', 'action'])) {
                $url = $attr->value;
                // HTML 엔티티 디코딩 (엔티티 우회 방지)
                $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // 제어 문자 제거 (탭/개행으로 javascript: 우회 방지)
                $cleaned = preg_replace('/[\x00-\x20]+/', '', $decoded);
                
                if (preg_match('/^([a-zA-Z][a-zA-Z0-9+\-.]*):/', $cleaned, $scheme)) {
                    $proto = strtolower($scheme[1]);
                    $allowedProtocols = ($attrName === 'href') 
                        ? ['http', 'https', 'mailto', 'tel'] 
                        : ['http', 'https', 'data'];
                    
                    if (!in_array($proto, $allowedProtocols)) {
                        $attrsToRemove[] = $attr->name;
                        continue;
                    }
                    
                    // data: URI는 이미지만 허용, 크기 제한 (64KB)
                    if ($proto === 'data') {
                        if (!preg_match('/^data:image\/(png|jpeg|jpg|gif|webp)/i', $cleaned)) {
                            $attrsToRemove[] = $attr->name;
                            continue;
                        }
                        if (strlen($url) > 65536) {
                            $attrsToRemove[] = $attr->name;
                            continue;
                        }
                    }
                }
            }
            
            // style 속성: CSS 속성 화이트리스트 검증
            if ($attrName === 'style') {
                $attr->value = sanitizeCssStyle($attr->value, $allowedCssProperties);
            }
            
            // class 속성: 안전한 접두사만 허용 (UI 교란 방지)
            if ($attrName === 'class') {
                $classes = preg_split('/\s+/', trim($attr->value));
                $safeClasses = array_filter($classes, function($cls) {
                    // 에디터/코드 관련 접두사만 허용
                    return preg_match('/^(ql-|hljs-|code-|language-|board-|text-|align-|font-|color-|bg-|indent-|size-)/', $cls);
                });
                $attr->value = implode(' ', $safeClasses);
                if (empty($attr->value)) {
                    $attrsToRemove[] = $attr->name;
                }
            }
        }
        
        foreach ($attrsToRemove as $name) {
            $el->removeAttribute($name);
        }
        
        // a 태그: target="_blank" + rel="noopener noreferrer" 강제
        if ($tagName === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }
    
    // 결과 추출 (래퍼 div 내부만)
    $root = $doc->getElementById('__sanitize_root__');
    if (!$root) return '';
    
    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= $doc->saveHTML($child);
    }
    
    return $output;
}

/**
 * CSS style 속성값을 안전하게 필터링
 * 허용된 CSS 속성만 남기고, expression()/url(javascript:) 등 위험 패턴 제거
 */
function sanitizeCssStyle(string $style, array $allowedProperties): string {
    // null byte, CSS escape sequences (\xx, \xxxxxx) 사전 제거
    $style = str_replace("\0", '', $style);
    $style = preg_replace('/\\\\[0-9a-fA-F]{1,6}\s?/', '', $style);
    
    // expression(), url(javascript:), url(vbscript:), -moz-binding 등 위험 패턴 즉시 거부
    $dangerous = '/expression\s*\(|url\s*\(\s*["\']?\s*(javascript|vbscript|data\s*:(?!image))/i';
    if (preg_match($dangerous, $style)) {
        return '';
    }
    // -moz-binding (Firefox XBL 공격)
    if (stripos($style, '-moz-binding') !== false) {
        return '';
    }
    // behavior (IE HTC 공격)
    if (stripos($style, 'behavior') !== false) {
        return '';
    }
    
    $safe = [];
    // CSS 속성 파싱 (세미콜론 분리)
    $declarations = explode(';', $style);
    foreach ($declarations as $decl) {
        $decl = trim($decl);
        if (empty($decl)) continue;
        
        $parts = explode(':', $decl, 2);
        if (count($parts) !== 2) continue;
        
        $prop = strtolower(trim($parts[0]));
        $val = trim($parts[1]);
        
        if (in_array($prop, $allowedProperties)) {
            // 값에서 url() 패턴 차단 (외부 리소스 로딩 및 CSS injection 방지)
            // data:image만 허용, 나머지 url()은 모두 제거
            $valCleaned = preg_replace('/[\x00-\x20]+/', '', $val);
            if (preg_match('/url\s*\(/i', $valCleaned)) {
                if (!preg_match('/^[^;]*url\s*\(\s*["\']?\s*data:image\/(png|jpeg|jpg|gif|webp)/i', $valCleaned)) {
                    continue;
                }
            }
            $safe[] = $prop . ': ' . $val;
        }
    }
    
    return implode('; ', $safe);
}

/**
 * 사이트 설정 파일 로드
 */
function loadSiteSettings(): array {
    $settingsFile = __DIR__ . '/data/site_settings.json';
    return file_exists($settingsFile) 
        ? json_decode(file_get_contents($settingsFile), true) ?: []
        : [];
}

/**
 * 사이트 설정 파일 저장
 */
function saveSiteSettings(array $settings): bool {
    $settingsFile = __DIR__ . '/data/site_settings.json';
    return file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

/**
 * 사용자의 WebDAV 접근 가능 여부 체크
 */
function checkWebDAVEnabled(int $userId): bool {
    $db = JsonDB::getInstance();
    $permissions = $db->findAll('permissions', ['user_id' => $userId]);
    foreach ($permissions as $perm) {
        $davVisible = $perm['can_visible_webdav'] ?? null;
        if ($davVisible !== null && (int)$davVisible) {
            return true;
        }
    }
    return false;
}

/**
 * SMTP 이메일 발송
 * @return true|string 성공 시 true, 실패 시 오류 메시지
 */
function sendSmtpEmail(array $smtp, string $to, string $subject, string $message) {
    $host = $smtp['host'] ?? '';
    $port = (int)($smtp['port'] ?? 587);
    $user = $smtp['user'] ?? '';
    $pass = $smtp['pass'] ?? '';
    $from = $smtp['from'] ?? $user;
    $secure = $smtp['secure'] ?? 'tls';
    
    if (empty($host) || empty($user)) {
        return __('smtp_no_server_info');
    }
    
    // SMTP 인젝션 방지: 이메일 주소에서 줄바꿈/제어문자 제거 + 형식 검증
    $from = preg_replace('/[\r\n\x00-\x1f]/', '', $from);
    $to = preg_replace('/[\r\n\x00-\x1f]/', '', $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return __('smtp_recipient_error', '잘못된 수신자 이메일 형식입니다.');
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return __('smtp_sender_error', '잘못된 발신자 이메일 형식입니다.');
    }
    
    try {
        $socket = @fsockopen(
            ($secure === 'ssl' ? 'ssl://' : '') . $host,
            $port,
            $errno,
            $errstr,
            10
        );
        
        if (!$socket) {
            return __f('smtp_connect_fail', ['errstr' => $errstr, 'errno' => $errno]);
        }
        
        // 응답 읽기 함수
        $readResponse = function($socket) {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            return $response;
        };
        
        // 명령 전송 함수
        $sendCommand = function($socket, $command) use ($readResponse) {
            fwrite($socket, $command . "\r\n");
            return $readResponse($socket);
        };
        
        // 초기 응답
        $readResponse($socket);
        
        // EHLO
        $ehloHost = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $response = $sendCommand($socket, "EHLO " . $ehloHost);
        
        // STARTTLS (if tls)
        if ($secure === 'tls') {
            $response = $sendCommand($socket, "STARTTLS");
            if (strpos($response, '220') !== 0) {
                fclose($socket);
                return __('smtp_starttls_fail') . trim($response);
            }
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $sendCommand($socket, "EHLO " . $ehloHost);
        }
        
        // AUTH LOGIN
        if ($user && $pass) {
            $response = $sendCommand($socket, "AUTH LOGIN");
            if (strpos($response, '334') !== 0) {
                fclose($socket);
                return __('smtp_auth_start_fail') . trim($response);
            }
            
            $response = $sendCommand($socket, base64_encode($user));
            if (strpos($response, '334') !== 0) {
                fclose($socket);
                return __('smtp_username_error') . trim($response);
            }
            
            $response = $sendCommand($socket, base64_encode($pass));
            if (strpos($response, '235') !== 0) {
                fclose($socket);
                return __('smtp_password_error') . trim($response);
            }
        }
        
        // MAIL FROM
        $response = $sendCommand($socket, "MAIL FROM:<{$from}>");
        if (strpos($response, '250') !== 0) {
            fclose($socket);
            return __('smtp_sender_error') . trim($response);
        }
        
        // RCPT TO
        $response = $sendCommand($socket, "RCPT TO:<{$to}>");
        if (strpos($response, '250') !== 0) {
            fclose($socket);
            return __('smtp_recipient_error') . trim($response);
        }
        
        // DATA
        $response = $sendCommand($socket, "DATA");
        if (strpos($response, '354') !== 0) {
            fclose($socket);
            return __('smtp_data_error') . trim($response);
        }
        
        // Message
        $data = "From: {$from}\r\n";
        $data .= "To: {$to}\r\n";
        $data .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $data .= "\r\n";
        $data .= str_replace("\n.", "\n..", $message) . "\r\n";
        $data .= ".";
        
        $response = $sendCommand($socket, $data);
        if (strpos($response, '250') !== 0) {
            fclose($socket);
            return __('smtp_send_error') . trim($response);
        }
        
        // QUIT
        $sendCommand($socket, "QUIT");
        
        fclose($socket);
        return true;
        
    } catch (Exception $e) {
        return __('smtp_error_prefix') . $e->getMessage();
    }
}

// ===== 세션 락 디버그 로그 (활성화됨) =====
// data/session_debug.log 파일이 존재하면 자동 활성화
// 비활성화: 파일 삭제
$GLOBALS['_SESSION_DEBUG_START'] = microtime(true);
$GLOBALS['_SESSION_DEBUG_ENABLED'] = false;
$GLOBALS['_SESSION_DEBUG_FILE'] = '';
$GLOBALS['_SESSION_DEBUG_SID'] = '';

(function() {
    $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
    $logFile = $dataDir . '/session_debug.log';
    // ★ (2026-08-17) debug_logs 폴더가 있으면 이 로그도 함께 켠다.
    //   기존에는 스위치가 두 개(클라이언트 이벤트는 data/debug_logs/ 폴더, 서버 단계·pending 요청은
    //   data/session_debug.log 파일)여서, 폴더만 만들면 pending 요청 추적(client_state)이 켜지지 않았다.
    //   폴더가 있을 때 로그 파일이 없으면 만들어 준다(진단 켠 의도가 명확한 상황이므로).
    //   폴더가 없으면 아래 조건은 기존과 완전히 동일하게 파일 존재 여부만 본다 → 평상시 동작 불변.
    if (!is_file($logFile) && is_dir($dataDir . '/debug_logs') && is_writable($dataDir)) {
        @file_put_contents($logFile, "# session debug enabled by debug_logs/ presence\n", FILE_APPEND);
    }
    if (is_file($logFile) && is_writable($logFile)) {
        $GLOBALS['_SESSION_DEBUG_ENABLED'] = true;
        $GLOBALS['_SESSION_DEBUG_FILE'] = $logFile;
        // 세션 ID 축약 (앞 8자리만 로그에 사용 - privacy)
        $sid = session_id();
        if (!$sid && session_status() !== PHP_SESSION_NONE) {
            $sid = session_id();
        }
        $GLOBALS['_SESSION_DEBUG_SID'] = $sid ? substr($sid, 0, 8) : 'nosession';
    }
})();

function _sessionDebugLog(string $phase, string $extra = ''): void {
    if (empty($GLOBALS['_SESSION_DEBUG_ENABLED'])) return;
    $elapsed = (microtime(true) - $GLOBALS['_SESSION_DEBUG_START']) * 1000;
    $ts = date('Y-m-d H:i:s');
    $ms = sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
    $pid = getmypid();
    $sid = $GLOBALS['_SESSION_DEBUG_SID'] ?: (session_id() ? substr(session_id(), 0, 8) : 'nosession');
    $action = $GLOBALS['_SESSION_DEBUG_ACTION'] ?? '?';
    $status = session_status();
    $statusStr = ['disabled', 'none', 'active'][$status] ?? 'unknown';
    $line = sprintf(
        "[%s.%s] pid=%d sid=%s action=%s phase=%s elapsed=%.0fms session=%s %s\n",
        $ts, $ms, $pid, $sid, $action, $phase, $elapsed, $statusStr, $extra
    );
    @file_put_contents($GLOBALS['_SESSION_DEBUG_FILE'], $line, FILE_APPEND | LOCK_EX);
}

// 요청이 정상 경로로 끝나지 않을 경우(exit/die/fatal/timeout) SHUTDOWN 기록
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        _sessionDebugLog('FATAL', "msg=" . substr($err['message'], 0, 200) . " file=" . basename($err['file'] ?? '') . ':' . ($err['line'] ?? ''));
    } else {
        _sessionDebugLog('SHUTDOWN', '');
    }
});

// 요청 파싱
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$GLOBALS['_SESSION_DEBUG_ACTION'] = $action;
_sessionDebugLog('ENTRY', "method=$method uri=" . ($_SERVER['REQUEST_URI'] ?? ''));
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// POST 데이터 병합
if ($method === 'POST' && empty($input)) {
    $input = $_POST;
}

// GET 데이터 병합
if ($method === 'GET') {
    $input = array_merge($input, $_GET);
}

try {
    $result = ['success' => false, 'error' => __('invalid_request')];
    
    // 해킹시도 감지 (경로 조작 시도) - 강화 버전
    // 주의: json_encode는 유니코드를 \uXXXX로 이스케이프하므로 '..' + 한글이 '..\u'로 변환되어 오탐 발생
    // 따라서 개별 파라미터 값을 직접 검사
    $suspiciousPatterns = [
        '<script', '</script', 'javascript:',           // XSS 기본
        'onclick', 'onerror', 'onload', 'onmouseover',  // 이벤트 핸들러
        'onfocus', 'onblur', 'onsubmit', 'onchange',
        'ontoggle', 'onstart', 'onpageshow', 'onresize', // 추가 이벤트
        'onanimationend', 'onbeforeunload', 'onhashchange',
        'oninput', 'onkeydown', 'onkeyup', 'onkeypress',
        'onmousedown', 'onmouseup', 'onmousemove',
        'ondrag', 'ondrop', 'onpaste', 'oncopy',
        'onpointerdown', 'onpointerup', 'ontouchstart',
        'oncontextmenu', 'onwheel', 'onscroll',
        'expression(', 'eval(', 'alert(',               // JS 실행
        'data:text/html', 'data:application',           // 데이터 URI
        'vbscript:',                                    // VBScript
        '<?php', '<?=',                                 // PHP 인젝션 시도
        'base64_decode', 'exec(', 'system(',            // 위험 함수
        '<svg', '<math', '<iframe', '<embed',           // 위험 태그
        '<object', '<applet', '<form', '<meta',
        '<link', '<base', '<isindex',
        'srcdoc', 'xlink:href',                         // 추가 속성 벡터
    ];
    
    $hackDetected = false;
    $hackReason = '';
    
    // 개별 파라미터 값을 검사 (json_encode 오탐 방지)
    // content 필드는 게시판 HTML 에디터 출력으로 별도 sanitizeHtml()로 처리
    $skipPatternKeys = ['content', 'salt', 'password_validator', 'enc_meta', 'log'];
    foreach ($input as $key => $val) {
        if (!is_string($val)) continue;
        if (in_array($key, $skipPatternKeys)) continue;
        $valLower = strtolower(urldecode($val));
        foreach ($suspiciousPatterns as $pattern) {
            if (strpos($valLower, strtolower($pattern)) !== false) {
                $hackDetected = true;
                $hackReason = __f('hack_pattern_detected', ['pattern' => $pattern]);
                break 2;
            }
        }
    }
    
    // 경로 파라미터에서 상위 디렉토리 접근 시도 감지 (이중 인코딩 대응)
    $pathParams = ['path', 'source', 'dest', 'file_path', 'paths', 'zip_name', 'new_name'];
    foreach ($pathParams as $param) {
        $value = $input[$param] ?? null;
        if ($value === null) continue;
        
        // 배열인 경우 처리
        $values = is_array($value) ? $value : [$value];
        foreach ($values as $v) {
            if (!is_string($v)) continue;
            
            // URL 디코딩 (이중 인코딩 대응)
            $decoded = urldecode(urldecode($v));
            
            if (preg_match('/(?:^|[\\/\\\\])\.\.(?:$|[\\/\\\\])|%2e%2e/i', $decoded)) {
                $hackDetected = true;
                $hackReason = __f('hack_path_traversal', ['param' => $param]);
                break 2;
            }
        }
    }
    
    if ($hackDetected) {
        $activityLog->log(ActivityLog::TYPE_HACK_ATTEMPT, [
            'filename' => $action,
            'details' => $hackReason . ' | Input: ' . substr(json_encode(array_diff_key($input, array_flip(['password','current_password','new_password','password_validator','salt'])), JSON_UNESCAPED_UNICODE), 0, 200)
        ]);
        // 해킹 시도 시 즉시 차단
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => __('invalid_request')]);
        exit;
    }
    
    // Remember Me 토큰으로 자동 로그인 시도 (로그인 안 된 상태에서)
    $noAuthActions = ['login', 'signup', 'signup_status', 'csrf_token', 'terms', 'server_config', '2fa_verify', 'password_reset_request', 'find_username', 'share_access', 'share_download', 'filedrop_upload', 'filedrop_chunk_upload', 'sso_config', 'sso_ldap_login', 'sso_oidc_auth', 'sso_oidc_callback', 'sso_oidc_silent_auth', 'sso_saml_auth', 'sso_saml_callback', 'sso_saml_metadata', 'debug_log'];
    if (!$auth->isLoggedIn() && !in_array($action, $noAuthActions)) {
        if (method_exists($auth, 'checkRememberToken')) {
            $auth->checkRememberToken();
        }
    }
    
    // Vault 경로 해석 유틸리티 (12곳 반복 코드 통합)
    // 반환: ['basePath' => ..., 'vaultDir' => ...] 또는 실패 시 null
    function resolveVaultPath($storage, int $storageId, string $path): ?array {
        $basePath = $storage->getRealPath($storageId);
        if (!$basePath) return null;
        
        $relative = trim($path, '/\\');
        $vaultDir = $basePath . ($relative ? DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative) : '');
        
        // 경로 이탈 방지: vaultDir이 basePath 하위인지 확인
        $realBase = realpath($basePath);
        $realVault = realpath($vaultDir) ?: realpath(dirname($vaultDir));
        if (!$realBase || !$realVault) return null;
        if (!\isSubPath($realVault, $realBase)) return null;
        
        return ['basePath' => $basePath, 'vaultDir' => $vaultDir];
    }
    
    // 탈퇴/삭제 사용자 자동 정리 (하루 1회)
    function autoPurgeDeletedUsers($db) {
        try {
            $settings = $db->load('settings') ?: [];
            if (empty($settings['auto_purge_deleted'])) return;
            
            $lastPurge = $settings['auto_purge_last'] ?? '';
            $today = date('Y-m-d');
            if ($lastPurge === $today) return;
            
            $purgeDays = max(1, (int)($settings['auto_purge_days'] ?? 90));
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$purgeDays} days"));
            $deletedUsers = $db->load('deleted_users') ?: [];
            $purged = false;
            foreach ($deletedUsers as $idx => $du) {
                $deletedAt = $du['deleted_at'] ?? '';
                if ($deletedAt && $deletedAt < $cutoff) {
                    unset($deletedUsers[$idx]);
                    $purged = true;
                }
            }
            if ($purged) {
                $db->save('deleted_users', array_values($deletedUsers));
            }
            $settings['auto_purge_last'] = $today;
            $db->save('settings', $settings);
        } catch (\Throwable $e) {}
    }
    
    
    switch ($action) {
        // ===== CSRF 토큰 =====
        case 'csrf_token':
            // 현재 토큰 반환 (없으면 새로 생성)
            $result = [
                'success' => true,
                'token' => getCsrfToken()
            ];
            break;
        
        case 'ping':
            // PATH_INFO 지원 테스트용 (PDF 미리보기 파일명 표시)
            $result = ['success' => true];
            break;
        
        case 'debug_log':
            // ★ 클라이언트 진단 로그 수신 (펜닐 v5.8.1g 임시 진단 도구)
            //   목적: 모바일 30분+ 백그라운드 복귀 시 "스토리지를 선택하세요" 멈춤 증상 진단
            //   인증 무관 동작 — 세션 만료 케이스도 진단 가능 (noAuthActions에 포함)
            //   파일: data/debug_logs/YYYY-MM-DD.log (날짜별)
            //   영구 보존 (수동 제거)
            //   보안 조치:
            //     - 메시지 크기 제한 (4KB)
            //     - 로그 파일 크기 제한 (날짜당 10MB, 초과 시 새 쓰기 거부)
            //     - data/.htaccess로 외부 직접 접근 차단됨 (기존 보호)
            try {
                $logDir = DATA_PATH . '/debug_logs';
                // ★ 펜닐님 ON/OFF 제어 방식: 폴더 존재 여부로 디버그 모드 결정
                //   - 폴더 있음 → 로그 쌓임 (디버그 모드 ON)
                //   - 폴더 없음 → 즉시 종료 (디버그 모드 OFF, 성능 영향 0)
                //   펜닐님이 mkdir/rmdir로 직접 제어
                if (!is_dir($logDir)) {
                    $result = ['success' => true, 'disabled' => true];
                    break;
                }
                // 입력 파싱 — JSON body
                $raw = file_get_contents('php://input');
                if (strlen($raw) > 4096) {
                    $result = ['success' => false, 'error' => 'payload_too_large'];
                    break;
                }
                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    $result = ['success' => false, 'error' => 'invalid_json'];
                    break;
                }
                
                // 로그 파일 경로 (날짜별)
                $logFile = $logDir . '/' . date('Y-m-d') . '.log';
                $logSize = file_exists($logFile) ? filesize($logFile) : 0;
                if ($logSize > 10 * 1024 * 1024) {  // 10MB 초과 시 거부
                    $result = ['success' => false, 'error' => 'log_full'];
                    break;
                }
                
                // 로그 줄 생성
                // ★ 부작용 방지: isLoggedIn() 대신 세션 직접 체크 사용
                //   isLoggedIn()은 세션 활동 시간 갱신 + recordSession 호출 부작용 있음
                //   디버그 로그는 순수 조회용이라 활동 갱신 안 해야 함
                $_isAuthed = isset($_SESSION['user_id']);
                $_username = $_SESSION['username'] ?? null;
                $logEntry = [
                    'ts' => date('Y-m-d H:i:s'),
                    'ts_ms' => round(microtime(true) * 1000),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '?',
                    'ua_short' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
                    'authed' => $_isAuthed ? 'Y' : 'N',
                    'user' => $_isAuthed ? ($_username ?? '?') : '-',
                    'event' => $data['event'] ?? 'unknown',
                    'detail' => $data['detail'] ?? null,
                ];
                // 한 줄 JSON으로 append (tail -f 친화적)
                $line = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
                
                $result = ['success' => true];
            } catch (\Throwable $e) {
                // 디버그 도구 자체 에러는 조용히 무시 (본 기능에 영향 없게)
                $result = ['success' => false];
            }
            break;
        
        case 'session_ping':
            // 미디어 재생 중 세션 유지용 (자동 로그아웃 방지)
            // isLoggedIn() 호출 자체가 $_SESSION['last_activity']를 갱신함
            // 인증 체크는 상단 미들웨어에서 이미 완료됨 (noAuthActions에 없으므로)
            $result = ['success' => true, 'timestamp' => time()];
            break;
        
        case 'client_state':
            // 클라이언트 측 상태 보고 (디버깅용)
            // 프론트가 주기적으로 또는 무한로딩 감지 시 pending 요청 목록 등을 POST
            // session_debug.log 파일이 있을 때만 기록 (평상시 오버헤드 0)
            session_write_close();
            if (!empty($GLOBALS['_SESSION_DEBUG_ENABLED'])) {
                $reason = substr((string)($input['reason'] ?? 'heartbeat'), 0, 32);
                $pending = $input['pending'] ?? [];
                $pendingCount = is_array($pending) ? count($pending) : 0;
                
                // 요약 한 줄
                _sessionDebugLog('CLIENT:' . $reason, 'pending_count=' . $pendingCount
                    . ' visibility=' . substr((string)($input['visibility'] ?? '?'), 0, 10)
                    . ' online=' . (!empty($input['online']) ? '1' : '0')
                    . ' url=' . substr((string)($input['url'] ?? ''), 0, 100));
                
                // pending 요청 상세 (각 요청별 한 줄씩, 최대 20개)
                if (is_array($pending)) {
                    $i = 0;
                    foreach ($pending as $p) {
                        if ($i++ >= 20) break;
                        $age = isset($p['age_ms']) ? (int)$p['age_ms'] : 0;
                        $url = substr((string)($p['url'] ?? ''), 0, 150);
                        _sessionDebugLog('CLIENT:pending', sprintf('age=%dms url=%s', $age, $url));
                    }
                }
            }
            $result = ['success' => true];
            break;
        
        // ===== OnlyOffice 연동 =====
        case 'onlyoffice_download':
            // OnlyOffice Document Server에서 파일을 가져갈 때 사용
            $ooDbgDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
            $ooDbgAuth = '';
            // 보안: JWT 토큰 또는 로그인 세션 필요
            $settings = $db->load('settings');
            $onlyofficeSecret = $settings['onlyoffice']['secret'] ?? '';
            $authenticated = false;
            
            if (!empty($onlyofficeSecret)) {
                // JWT 토큰 검증 (OnlyOffice 서버에서 호출 시)
                $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
                $jwtToken = null;
                if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                    $jwtToken = $matches[1];
                }
                if (!$jwtToken) {
                    $jwtToken = $_GET['token'] ?? null;
                }
                if ($jwtToken) {
                    $parts = explode('.', $jwtToken);
                    if (count($parts) === 3) {
                        list($header, $payload, $signature) = $parts;
                        $expectedSig = rtrim(strtr(base64_encode(
                            hash_hmac('sha256', "$header.$payload", $onlyofficeSecret, true)
                        ), '+/', '-_'), '=');
                        if (hash_equals($expectedSig, $signature)) {
                            $authenticated = true;
                        } else {
                            // ★ JWT 검증 실패 디버그 (펜닐 v5.8.1e — 파일 존재 시만)
                            if (file_exists($ooDbgDir . '/onlyoffice_debug.log')) {
                                @file_put_contents($ooDbgDir . '/onlyoffice_debug.log', date('H:i:s') . " JWT_FAIL sig_mismatch expected=" . substr($expectedSig, 0, 20) . "... got=" . substr($signature, 0, 20) . "... secret_len=" . strlen($onlyofficeSecret) . "\n", FILE_APPEND);
                            }
                        }
                    }
                } else {
                    // JWT 토큰이 없는 경우 — OnlyOffice 서버가 Authorization 헤더를 보내지 않을 수 있음
                    // (Apache 프록시가 헤더를 제거하거나, OnlyOffice 버전에 따라 다름)
                    // 세션 fallback 대신 secret이 설정돼 있으면 key 파라미터로 간접 인증
                    $docKey = $_GET['key'] ?? '';
                    if ($docKey && strlen($docKey) === 32 && ctype_xdigit($docKey)) {
                        $authenticated = true; // document key가 유효하면 허용
                        if (file_exists($ooDbgDir . '/onlyoffice_debug.log')) {
                            @file_put_contents($ooDbgDir . '/onlyoffice_debug.log', date('H:i:s') . " JWT_BYPASS key=$docKey\n", FILE_APPEND);
                        }
                    } else {
                        if (file_exists($ooDbgDir . '/onlyoffice_debug.log')) {
                            @file_put_contents($ooDbgDir . '/onlyoffice_debug.log', date('H:i:s') . " JWT_FAIL no_token authHeader=" . ($authHeader ? 'present' : 'empty') . "\n", FILE_APPEND);
                        }
                    }
                }
            } else {
                if (file_exists($ooDbgDir . '/onlyoffice_debug.log')) {
                    @file_put_contents($ooDbgDir . '/onlyoffice_debug.log', date('H:i:s') . " JWT_FAIL no_secret\n", FILE_APPEND);
                }
            }
            
            // JWT 인증 실패 시 세션 로그인 필요
            if (!$authenticated) {
                $ooDbgAuth = 'SESSION';
                $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            } else {
                $ooDbgAuth = 'JWT';
            }
            
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $filePath = $_GET['path'] ?? '';
            
            // ★ DOWNLOAD 디버그 (펜닐 v5.8.1e — Document Server 요청 확인용, 파일 존재 시만)
            if (file_exists($ooDbgDir . '/onlyoffice_debug.log')) {
                @file_put_contents($ooDbgDir . '/onlyoffice_debug.log', date('H:i:s') . " DOWNLOAD storageId=$storageId path=$filePath auth=$ooDbgAuth IP=" . ($_SERVER['REMOTE_ADDR'] ?? '') . " UA=" . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 80) . "\n", FILE_APPEND);
            }
            
            // 세션 인증 fallback인 경우 스토리지 읽기 권한 확인
            if (!$authenticated && !$storage->checkPermission($storageId, 'can_read')) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                exit('No permission');
            }
            
            if (!$storageId || !$filePath) {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
                exit('Invalid parameters');
            }
            
            $resolved = resolveOnlyOfficePath($db, $storageId, $filePath);
            if (!$resolved) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                exit('Invalid path');
            }
            $fullPath = $resolved['fullPath'];
            
            if (!file_exists($fullPath) || !is_file($fullPath)) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                exit('File not found');
            }
            
            // 파일 전송
            $fileName = basename($fullPath);
            $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
            $isInline = isset($_GET['inline']) && $_GET['inline'] == '1';
            
            // 파일명 인코딩 (RFC 5987)
            $fileNameEncoded = rawurlencode($fileName);
            $fileNameAscii = preg_replace('/[^\x20-\x7E]|["\\]/', '_', $fileName);
            
            header('Content-Type: ' . $mimeType);
            if ($isInline) {
                // inline: 브라우저에서 직접 표시 (PDF 미리보기 등)
                // 최소 CSP 유지 — iframe 내 표시 허용하되 스크립트 차단
                header("Content-Security-Policy: frame-ancestors 'self'; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline';");
                header("X-Frame-Options: SAMEORIGIN");
                header("Content-Disposition: inline; filename=\"{$fileNameAscii}\"; filename*=UTF-8''{$fileNameEncoded}");
            } else {
                header("Content-Disposition: attachment; filename=\"{$fileNameAscii}\"; filename*=UTF-8''{$fileNameEncoded}");
            }
            header('Content-Length: ' . filesize($fullPath));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            
            readfile($fullPath);
            exit;
        
        case 'onlyoffice_callback':
            // OnlyOffice Document Server에서 문서 저장 시 호출
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $filePath = $_GET['path'] ?? '';
            $key = $_GET['key'] ?? '';
            
            // POST 데이터 읽기
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                echo json_encode(['error' => 0]);
                exit;
            }
            
            // JWT 시크릿이 설정되어 있지 않으면 콜백 차단 (SSRF 방지)
            $settings = $db->load('settings');
            $onlyofficeSecret = $settings['onlyoffice']['secret'] ?? '';
            if (empty($onlyofficeSecret)) {
                error_log('OnlyOffice callback: rejected - no secret configured');
                echo json_encode(['error' => 1]);
                exit;
            }
            // JWT 검증 (시크릿이 확인됨 - 위에서 빈 시크릿은 차단)
            $jwtToken = null;
            
            // Authorization 헤더에서 Bearer 토큰 확인
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $jwtToken = $matches[1];
            }
            
            // body의 token 필드 확인
            if (!$jwtToken && !empty($input['token'])) {
                $jwtToken = $input['token'];
            }
            
            if (!$jwtToken) {
                error_log('OnlyOffice callback: JWT token missing');
                echo json_encode(['error' => 1]);
                exit;
            }
            
            // JWT 검증 (HMAC-SHA256)
            $parts = explode('.', $jwtToken);
            if (count($parts) !== 3) {
                error_log('OnlyOffice callback: Invalid JWT format');
                echo json_encode(['error' => 1]);
                exit;
            }
            
            list($header, $payload, $signature) = $parts;
            $expectedSignature = rtrim(strtr(base64_encode(
                hash_hmac('sha256', "$header.$payload", $onlyofficeSecret, true)
            ), '+/', '-_'), '=');
            
            if (!hash_equals($expectedSignature, $signature)) {
                error_log('OnlyOffice callback: JWT signature verification failed');
                echo json_encode(['error' => 1]);
                exit;
            }
            
            $status = $input['status'] ?? 0;
            
            // 디버그 로그 (펜닐 v5.8.1e — 파일 존재 시만 기록, 운영 부담 방지)
            $ooDataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
            if (file_exists($ooDataDir . '/onlyoffice_debug.log')) {
                @file_put_contents($ooDataDir . '/onlyoffice_debug.log', date('H:i:s') . " CALLBACK status=$status key=$key storage=$storageId path=$filePath url=" . ($input['url'] ?? '(none)') . "\n", FILE_APPEND);
            }
            
            // status 코드:
            // 0 - 문서 편집 중
            // 1 - 문서 저장 준비됨
            // 2 - 문서 저장됨 (다운로드 URL 제공)
            // 3 - 문서 저장 오류
            // 4 - 문서 닫힘 (변경 없음)
            // 6 - 강제 저장 요청
            // 7 - 강제 저장 오류
            
            if ($status == 2 || $status == 6) {
                // 문서 저장
                $downloadUrl = $input['url'] ?? '';
                
                if (!$downloadUrl || !$storageId || !$filePath) {
                    echo json_encode(['error' => 1]);
                    exit;
                }
                
                // SSRF 방지: 내부 네트워크 URL 차단 + DNS rebinding 방어
                $parsedUrl = parse_url($downloadUrl);
                $dlHost = $parsedUrl['host'] ?? '';
                $blockedHosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
                if (in_array($dlHost, $blockedHosts)) {
                    error_log("[FileStation] OnlyOffice SSRF blocked (host): {$downloadUrl}");
                    echo json_encode(['error' => 1]);
                    exit;
                }
                // DNS resolve 후 실제 IP 검증 (DNS rebinding 방어)
                $resolvedIp = gethostbyname($dlHost);
                if ($resolvedIp === $dlHost) {
                    // DNS 해석 실패 — 차단
                    error_log("[FileStation] OnlyOffice SSRF blocked (DNS fail): {$downloadUrl}");
                    echo json_encode(['error' => 1]);
                    exit;
                }
                // IPv6 매핑 주소 정규화 (::ffff:127.0.0.1 → 127.0.0.1)
                $normalizedIp = $resolvedIp;
                if (function_exists('inet_pton') && function_exists('inet_ntop')) {
                    $packed = @inet_pton($resolvedIp);
                    if ($packed !== false) {
                        $normalizedIp = inet_ntop($packed);
                        // IPv4-mapped IPv6 → IPv4 추출
                        if (strpos($normalizedIp, '::ffff:') === 0) {
                            $normalizedIp = substr($normalizedIp, 7);
                        }
                    }
                }
                // octal/decimal IP 정규화 (0177.0.0.1, 2130706433 등)
                $longIp = ip2long($normalizedIp);
                if ($longIp !== false) {
                    $normalizedIp = long2ip($longIp);
                }
                if (filter_var($normalizedIp, FILTER_VALIDATE_IP) !== false &&
                    filter_var($normalizedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    error_log("[FileStation] OnlyOffice SSRF blocked (private IP {$normalizedIp}, original: {$resolvedIp}): {$downloadUrl}");
                    if (file_exists($ooDataDir . '/onlyoffice_debug.log')) {
                        @file_put_contents($ooDataDir . '/onlyoffice_debug.log', date('H:i:s') . " SSRF BLOCKED: ip=$normalizedIp resolved=$resolvedIp url=$downloadUrl\n", FILE_APPEND);
                    }
                    echo json_encode(['error' => 1]);
                    exit;
                }
                
                // 경로 해석
                $resolved = resolveOnlyOfficePath($db, $storageId, $filePath);
                if (!$resolved) {
                    echo json_encode(['error' => 1]);
                    exit;
                }
                $fullPath = $resolved['fullPath'];
                
                // OnlyOffice에서 파일 다운로드 (SSRF TOCTOU 방어: resolved IP 고정)
                $dlPort = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80);
                $ch = curl_init($downloadUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_RESOLVE => [$dlHost . ':' . $dlPort . ':' . $normalizedIp],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ]);
                $newContent = curl_exec($ch);
                $curlErr = curl_errno($ch);
                $curlErrMsg = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($newContent === false || $curlErr) {
                    if (file_exists($ooDataDir . '/onlyoffice_debug.log')) {
                        @file_put_contents($ooDataDir . '/onlyoffice_debug.log', date('H:i:s') . " CURL FAIL: err=$curlErr msg=$curlErrMsg http=$httpCode url=$downloadUrl\n", FILE_APPEND);
                    }
                    echo json_encode(['error' => 1]);
                    exit;
                }
                if (file_exists($ooDataDir . '/onlyoffice_debug.log')) {
                    @file_put_contents($ooDataDir . '/onlyoffice_debug.log', date('H:i:s') . " DOWNLOAD OK: " . strlen($newContent) . " bytes, http=$httpCode\n", FILE_APPEND);
                }
                
                // 파일 저장
                if (@file_put_contents($fullPath, $newContent) === false) {
                    echo json_encode(['error' => 1]);
                    exit;
                }
            }
            
            echo json_encode(['error' => 0]);
            exit;
        
        case 'onlyoffice_config':
            // OnlyOffice 설정 정보 반환 (로그인 불필요 - 초기화 시 필요)
            $settings = $db->load('settings');
            $onlyoffice = $settings['onlyoffice'] ?? [];
            // PDF 편집 가능 여부: 저장된 서버 버전이 8.1 이상이어야 함 (없으면 false)
            $ooPdfEditable = !empty($onlyoffice['pdf_editable']);
            $ooExtensions = [
                'word' => ['docx', 'doc', 'odt', 'rtf', 'hwp', 'hwpx'],
                'cell' => ['xlsx', 'xls', 'ods', 'csv'],
                'slide' => ['pptx', 'ppt', 'odp']
            ];
            // 버전 충족 시에만 pdf를 편집 대상에 노출 (낮은 버전은 pdf.js 미리보기 유지)
            if ($ooPdfEditable) {
                $ooExtensions['pdf'] = ['pdf'];
            }
            $result = [
                'success' => true,
                'enabled' => !empty($onlyoffice['enabled']) && !empty($onlyoffice['server']),
                'server' => $onlyoffice['server'] ?? '',
                'pdf_enabled' => $ooPdfEditable,
                'extensions' => $ooExtensions
            ];
            break;
        
        // ===== 서버 설정 =====
        case 'server_config':
            // 로그인 불필요 - 업로드 전 청크 크기 결정에 필요
            $uploadMax = ini_get('upload_max_filesize');
            $postMax = ini_get('post_max_size');
            
            // 바이트로 변환하는 함수
            $toBytes = function($val) {
                $val = trim($val);
                $last = strtolower($val[strlen($val)-1]);
                $val = (int)$val;
                switch($last) {
                    case 'g': $val *= 1024 * 1024 * 1024; break;
                    case 'm': $val *= 1024 * 1024; break;
                    case 'k': $val *= 1024; break;
                }
                return $val;
            };
            
            $uploadMaxBytes = $toBytes($uploadMax);
            $postMaxBytes = $toBytes($postMax);
            
            // 더 작은 값 사용, 안전하게 80%로 설정
            $maxChunkSize = (int)(min($uploadMaxBytes, $postMaxBytes) * 0.8);
            
            // 최소 1MB, 최대 50MB
            $maxChunkSize = max(1 * 1024 * 1024, min($maxChunkSize, 50 * 1024 * 1024));
            
            $isLoggedIn = false;
            try { $isLoggedIn = $auth->isLoggedIn(); } catch (\Throwable $e) {}
            
            if ($isLoggedIn) {
                $result = [
                    'success' => true,
                    'version' => defined('APP_VERSION') ? APP_VERSION : '',
                    'upload_max_filesize' => $uploadMax,
                    'post_max_size' => $postMax,
                    'max_chunk_size' => $maxChunkSize,
                    'copyright' => loadSiteSettings()['copyright'] ?? ''
                ];
            } else {
                // 비로그인: 최소 정보만 반환
                $result = [
                    'success' => true,
                    'version' => defined('APP_VERSION') ? APP_VERSION : '',
                    'copyright' => loadSiteSettings()['copyright'] ?? ''
                ];
            }
            break;
        
        // ===== SSO 인증 =====
        
        case 'sso_config':
            // 로그인 페이지에서 활성화된 SSO 방식 표시용
            $ssoAuth = new SSOAuth();
            $result = ['success' => true, 'sso' => $ssoAuth->getPublicConfig()];
            break;
        
        case 'sso_ldap_login':
            // LDAP/AD 로그인
            $username = $input['username'] ?? '';
            $password = $input['password'] ?? '';
            $remember = !empty($input['remember']);
            if (empty($username) || empty($password)) {
                $result = ['success' => false, 'error' => __('login_required_fields', '사용자명과 비밀번호를 입력하세요.')];
                break;
            }
            try {
                $ssoAuth = new SSOAuth();
                $ldapUser = $ssoAuth->ldapAuth($username, $password);
                if (!$ldapUser) {
                    $result = ['success' => false, 'error' => __('ldap_auth_failed', 'LDAP 인증 실패: 사용자명 또는 비밀번호가 올바르지 않습니다.')];
                    break;
                }
                // FileStation 계정 매핑/생성
                $fsUser = $ssoAuth->mapOrCreateUser($ldapUser, 'ldap');
                
                // 세션 설정
                session_regenerate_id(true);
                $_SESSION['user_id'] = $fsUser['id'];
                $_SESSION['username'] = $fsUser['username'];
                $_SESSION['role'] = $fsUser['role'] ?? 'user';
                $_SESSION['last_activity'] = time();
                if ($remember) $_SESSION['remember_me'] = true;
                
                // remember token
                if ($remember) {
                    $auth->setRememberToken($fsUser['id']);
                }
                
                $safeUser = $auth->getUser();
                $result = [
                    'success' => true,
                    'user' => $safeUser,
                    'csrf_token' => regenerateCsrfToken(),
                    'sso_provider' => 'ldap'
                ];
            } catch (Exception $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            }
            break;
        
        case 'sso_oidc_auth':
            // OIDC 인증 URL 리다이렉트
            try {
                $ssoAuth = new SSOAuth();
                $authUrl = $ssoAuth->oidcGetAuthUrl();
                $result = ['success' => true, 'redirect_url' => $authUrl];
            } catch (Exception $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            }
            break;
        
        case 'sso_oidc_callback':
            // OIDC 콜백 (IdP에서 리다이렉트)
            $code = $_GET['code'] ?? $input['code'] ?? '';
            $state = $_GET['state'] ?? $input['state'] ?? '';
            $error = $_GET['error'] ?? '';
            
            if ($error) {
                // 에러 시 로그인 페이지로
                header('Location: index.php?sso_error=' . urlencode($error));
                exit;
            }
            
            try {
                $ssoAuth = new SSOAuth();
                $oidcUser = $ssoAuth->oidcCallback($code, $state);
                if (!$oidcUser) throw new Exception('OIDC 인증 실패');
                
                $fsUser = $ssoAuth->mapOrCreateUser($oidcUser, 'oidc');
                
                session_regenerate_id(true);
                $_SESSION['user_id'] = $fsUser['id'];
                $_SESSION['username'] = $fsUser['username'];
                $_SESSION['role'] = $fsUser['role'] ?? 'user';
                $_SESSION['last_activity'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
                // 로그인 페이지로 리다이렉트 (JS에서 감지)
                header('Location: index.php?sso_login=success');
                exit;
            } catch (Exception $e) {
                fsLog("SSO OIDC callback error: " . $e->getMessage());
                header('Location: index.php?sso_error=' . urlencode(__('sso_auth_failed', 'SSO 인증에 실패했습니다.')));
                exit;
            }
            break;
        
        case 'sso_saml_auth':
            // SAML 인증 URL 리다이렉트
            try {
                $ssoAuth = new SSOAuth();
                $authUrl = $ssoAuth->samlGetAuthUrl();
                $result = ['success' => true, 'redirect_url' => $authUrl];
            } catch (Exception $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            }
            break;
        
        case 'sso_saml_callback':
            // SAML Response 처리 (IdP에서 POST)
            $samlResponse = $_POST['SAMLResponse'] ?? '';
            if (empty($samlResponse)) {
                header('Location: index.php?sso_error=' . urlencode('SAML Response 없음'));
                exit;
            }
            try {
                $ssoAuth = new SSOAuth();
                $samlUser = $ssoAuth->samlCallback($samlResponse);
                if (!$samlUser) throw new Exception('SAML 인증 실패');
                
                $fsUser = $ssoAuth->mapOrCreateUser($samlUser, 'saml');
                
                session_regenerate_id(true);
                $_SESSION['user_id'] = $fsUser['id'];
                $_SESSION['username'] = $fsUser['username'];
                $_SESSION['role'] = $fsUser['role'] ?? 'user';
                $_SESSION['last_activity'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
                header('Location: index.php?sso_login=success');
                exit;
            } catch (Exception $e) {
                fsLog("SSO SAML callback error: " . $e->getMessage());
                header('Location: index.php?sso_error=' . urlencode(__('sso_auth_failed', 'SSO 인증에 실패했습니다.')));
                exit;
            }
            break;
        
        case 'sso_saml_metadata':
            // SAML SP 메타데이터
            $ssoAuth = new SSOAuth();
            header('Content-Type: application/xml');
            echo $ssoAuth->samlGetMetadata();
            exit;
        
        case 'sso_oidc_discover':
            // OIDC Well-Known 자동 설정
            $auth->requireRealAdmin();
            try {
                $ssoAuth = new SSOAuth();
                $endpoints = $ssoAuth->oidcDiscover($input['well_known_url'] ?? '');
                $result = ['success' => true, 'endpoints' => $endpoints];
            } catch (Exception $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            }
            break;
        
        case 'sso_oidc_silent_auth':
            // OIDC 자동 로그인 (prompt=none)
            try {
                $ssoAuth = new SSOAuth();
                $silentUrl = $ssoAuth->oidcGetSilentAuthUrl();
                if ($silentUrl) {
                    $result = ['success' => true, 'redirect_url' => $silentUrl];
                } else {
                    $result = ['success' => false];
                }
            } catch (Exception $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            }
            break;
        
        case 'sso_ldap_test':
            // LDAP 연결 테스트
            $auth->requireRealAdmin();
            $ssoAuth = new SSOAuth();
            $result = $ssoAuth->ldapTest($input);
            break;
        
        case 'sso_settings_get':
            // SSO 설정 조회 (관리자 전용)
            $auth->requireRealAdmin();
            $settings = $db->load('settings') ?: [];
            
            // 서버 환경 감지
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $hasLdap = function_exists('ldap_connect');
            $hasCurl = function_exists('curl_init');
            $hasOpenssl = extension_loaded('openssl');
            $serverSw = $_SERVER['SERVER_SOFTWARE'] ?? '';
            $isApache = stripos($serverSw, 'apache') !== false;
            $isIIS = stripos($serverSw, 'iis') !== false || stripos($serverSw, 'microsoft') !== false;
            $isNginx = stripos($serverSw, 'nginx') !== false;
            $isDocker = file_exists('/.dockerenv') || (is_readable('/proc/1/cgroup') && strpos(@file_get_contents('/proc/1/cgroup'), 'docker') !== false);
            
            // Synology DSM 감지
            $isSynology = false;
            if (!$isWindows) {
                $isSynology = file_exists('/etc/synoinfo.conf') || file_exists('/etc.defaults/synoinfo.conf') || is_dir('/volume1');
            }
            
            // QNAP 감지
            $isQNAP = false;
            if (!$isWindows) {
                $isQNAP = file_exists('/etc/config/qpkg.conf') || is_dir('/share/CACHEDEV1_DATA');
            }
            
            // 웹서버 이름
            $webServer = 'Unknown';
            if ($isApache) $webServer = 'Apache';
            elseif ($isIIS) $webServer = 'IIS';
            elseif ($isNginx) $webServer = 'Nginx';
            elseif (stripos($serverSw, 'litespeed') !== false) $webServer = 'LiteSpeed';
            elseif (stripos($serverSw, 'caddy') !== false) $webServer = 'Caddy';
            elseif ($serverSw) $webServer = $serverSw;
            
            // 플랫폼 이름 조합
            $platform = $isWindows ? 'Windows' : PHP_OS;
            if ($isSynology) $platform = 'Synology DSM';
            elseif ($isQNAP) $platform = 'QNAP';
            if ($isDocker) $platform .= ' (Docker)';
            
            // Kerberos 모듈 감지
            $hasKerberos = false;
            $kerberosDetail = '';
            if ($isIIS) {
                $hasKerberos = true;
                $kerberosDetail = 'IIS Windows Authentication';
            } elseif ($isApache && !$isWindows) {
                if (function_exists('apache_get_modules')) {
                    $mods = apache_get_modules();
                    if (in_array('auth_gssapi', $mods) || in_array('mod_auth_gssapi', $mods)) {
                        $hasKerberos = true;
                        $kerberosDetail = 'mod_auth_gssapi';
                    } elseif (in_array('auth_kerb', $mods) || in_array('mod_auth_kerb', $mods)) {
                        $hasKerberos = true;
                        $kerberosDetail = 'mod_auth_kerb';
                    }
                }
                // 모듈 파일 직접 확인
                if (!$hasKerberos) {
                    $modDirs = ['/usr/lib64/httpd/modules', '/usr/lib/apache2/modules', '/etc/httpd/modules'];
                    foreach ($modDirs as $dir) {
                        if (file_exists("$dir/mod_auth_gssapi.so")) { $hasKerberos = true; $kerberosDetail = 'mod_auth_gssapi (file found)'; break; }
                        if (file_exists("$dir/mod_auth_kerb.so")) { $hasKerberos = true; $kerberosDetail = 'mod_auth_kerb (file found)'; break; }
                    }
                }
            }
            
            $env = [
                'platform' => $platform,
                'os' => $isWindows ? 'Windows' : PHP_OS,
                'web_server' => $webServer,
                'web_server_full' => $serverSw,
                'php_version' => PHP_VERSION,
                'php_ldap' => $hasLdap,
                'php_curl' => $hasCurl,
                'php_openssl' => $hasOpenssl,
                'kerberos_available' => $hasKerberos,
                'kerberos_detail' => $kerberosDetail,
                'is_windows' => $isWindows,
                'is_iis' => $isIIS,
                'is_docker' => $isDocker,
                'is_synology' => $isSynology,
                'is_qnap' => $isQNAP,
            ];
            
            // 각 방식별 사용 가능 여부 판정 (다국어)
            $lang = Lang::getInstance();
            $isEn = ($lang->getLang() ?? 'ko') === 'en';
            
            $krbReason = '';
            if ($hasKerberos) {
                $krbReason = $kerberosDetail . ' - ' . ($isEn ? 'Available' : '사용 가능');
            } elseif ($isWindows && $isApache) {
                $krbReason = $isEn ? 'Windows Apache does not support Kerberos module. Use IIS or Linux Apache + mod_auth_gssapi' : 'Windows Apache는 Kerberos 모듈 미지원. IIS로 변경하거나 Linux Apache + mod_auth_gssapi 필요';
            } elseif ($isWindows && $isNginx) {
                $krbReason = $isEn ? 'Windows Nginx does not support Kerberos. Switch to IIS' : 'Windows Nginx는 Kerberos 미지원. IIS로 변경 필요';
            } elseif ($isNginx) {
                $krbReason = $isEn ? 'Nginx does not support SPNEGO. Use Apache + mod_auth_gssapi as reverse proxy' : 'Nginx는 SPNEGO 미지원. Apache + mod_auth_gssapi로 리버스 프록시 구성 필요';
            } elseif ($isSynology) {
                $krbReason = $isDocker 
                    ? ($isEn ? 'Kerberos setup is complex in Docker. LDAP/OIDC recommended' : 'Docker 컨테이너에서 Kerberos 설정이 복잡함. LDAP/OIDC 권장')
                    : ($isEn ? 'Install mod_auth_gssapi on Synology DSM' : 'Synology DSM에서 mod_auth_gssapi 설치 필요');
            } elseif ($isApache) {
                $krbReason = $isEn ? 'Install mod_auth_gssapi: yum install mod_auth_gssapi (RHEL) or apt install libapache2-mod-auth-gssapi (Debian)' : 'mod_auth_gssapi 설치 필요: yum install mod_auth_gssapi (RHEL) 또는 apt install libapache2-mod-auth-gssapi (Debian)';
            } else {
                $krbReason = $isEn ? 'Unsupported server environment' : '지원하지 않는 서버 환경';
            }
            
            $oidcReason = '';
            if ($hasCurl && $hasOpenssl) {
                $oidcReason = ($isEn ? 'Available' : '사용 가능') . ($isDocker ? ($isEn ? ' (check container network access)' : ' (컨테이너 외부 네트워크 접근 확인 필요)') : ($isEn ? ' (server must access external internet)' : ' (서버가 외부 인터넷에 접근 가능해야 함)'));
            } elseif (!$hasCurl) {
                $oidcReason = $isEn ? 'PHP curl extension required: enable extension=curl in php.ini' : 'PHP curl 확장 필요: php.ini에서 extension=curl 활성화';
            } else {
                $oidcReason = $isEn ? 'PHP openssl extension required' : 'PHP openssl 확장 필요';
            }
            
            $env['methods'] = [
                'kerberos' => [
                    'available' => $hasKerberos,
                    'reason' => $krbReason
                ],
                'ldap' => [
                    'available' => $hasLdap,
                    'reason' => $hasLdap 
                        ? ($isEn ? 'PHP LDAP extension enabled — enter AD/LDAP server info to start' : 'PHP LDAP 확장 활성화됨 — AD/LDAP 서버 정보만 입력하면 바로 사용 가능')
                        : ($isEn ? 'PHP LDAP extension required: enable extension=ldap in php.ini and restart web server' : 'PHP LDAP 확장 필요: php.ini에서 extension=ldap 활성화 후 웹서버 재시작')
                ],
                'oidc' => [
                    'available' => $hasCurl && $hasOpenssl,
                    'reason' => $oidcReason
                ],
                'saml' => [
                    'available' => $hasOpenssl,
                    'reason' => $hasOpenssl 
                        ? (($isEn ? 'Available' : '사용 가능') . ($isDocker ? ($isEn ? ' (check container network access)' : ' (컨테이너 외부 네트워크 접근 확인 필요)') : ($isEn ? ' (must be able to communicate with IdP)' : ' (IdP와 통신 가능해야 함)')))
                        : ($isEn ? 'PHP openssl extension required' : 'PHP openssl 확장 필요')
                ],
            ];
            
            $result = ['success' => true, 'sso' => $settings['sso'] ?? [], 'env' => $env];
            break;
        
        case 'sso_settings_update':
            // SSO 설정 저장
            $auth->requireRealAdmin();
            $settings = $db->load('settings') ?: [];
            $sso = $settings['sso'] ?? [];
            
            $provider = $input['provider'] ?? '';
            $config = $input['config'] ?? [];
            
            if (in_array($provider, ['kerberos', 'ldap', 'oidc', 'saml'])) {
                $sso[$provider] = $config;
                $settings['sso'] = $sso;
                $db->save('settings', $settings);
                $result = ['success' => true];
            } else {
                $result = ['success' => false, 'error' => __('api_err_invalid_sso_provider', '잘못된 SSO 제공자')];
            }
            break;
        
        // ===== 인증 =====
        case 'login':
            $remember = isset($input['remember']) && $input['remember'];
            $username = $input['username'] ?? '';
            $result = $auth->login($username, $input['password'] ?? '', $remember);
            
            // 로그인 결과에 따른 활동 로그
            if ($result['success'] ?? false) {
                // CSRF 토큰 재생성 (세션 고정 공격 방지)
                $result['csrf_token'] = regenerateCsrfToken();
                
                // WebDAV 접근 가능 여부 추가
                $loggedInUser = $result['user'] ?? [];
                if (!empty($loggedInUser['id'])) {
                    $result['user']['webdav_enabled'] = checkWebDAVEnabled((int)$loggedInUser['id']);
                }
                $activityLog->log(ActivityLog::TYPE_LOGIN, [
                    'user_id' => $loggedInUser['id'] ?? 0,
                    'username' => $loggedInUser['username'] ?? $username,
                    'display_name' => $loggedInUser['display_name'] ?? $username,
                    'filename' => $loggedInUser['username'] ?? $username,
                    'details' => 'User-Agent: ' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100)
                ]);
                
                // 탈퇴/삭제 사용자 자동 정리 (하루 1회)
                autoPurgeDeletedUsers($db);
            } else {
                // 로그인 실패 기록 (해킹 시도 감지용) - username 명시적 전달
                $activityLog->log(ActivityLog::TYPE_LOGIN_FAIL, [
                    'user_id' => 0,
                    'username' => $username,
                    'display_name' => $username,
                    'filename' => $username,
                    'details' => $result['error'] ?? __('login_failed')
                ]);
            }
            break;
            
        case 'logout':
            // 로그아웃 전에 사용자 정보 저장
            $logoutUser = $auth->getUser();
            $auth->logout();
            
            // 로그아웃 로그 - 사용자 정보 직접 전달
            if ($logoutUser) {
                $activityLog->log(ActivityLog::TYPE_LOGOUT, [
                    'user_id' => $logoutUser['id'] ?? 0,
                    'username' => $logoutUser['username'] ?? '',
                    'display_name' => $logoutUser['display_name'] ?? $logoutUser['username'] ?? '',
                    'filename' => $logoutUser['username'] ?? ''
                ]);
            }
            $result = ['success' => true];
            break;
            
        case 'me':
            // Remember Me 토큰 체크
            if (!$auth->isLoggedIn() && method_exists($auth, 'checkRememberToken')) {
                $auth->checkRememberToken();
            }
            // Kerberos/SPNEGO 자동 로그인 체크
            if (!$auth->isLoggedIn()) {
                try {
                    $ssoAuth = new SSOAuth();
                    $kerberosUser = $ssoAuth->kerberosAutoLogin();
                    if ($kerberosUser) {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $kerberosUser['id'];
                        $_SESSION['username'] = $kerberosUser['username'];
                        $_SESSION['role'] = $kerberosUser['role'] ?? 'user';
                        $_SESSION['last_activity'] = time();
                        $_SESSION['sso_provider'] = 'kerberos';
                    }
                } catch (Exception $e) {
                    // Kerberos 실패는 무시 — 일반 로그인 폼으로 넘어감
                }
            }
            $user = $auth->getUser();
            if ($user) {
                // WebDAV 접근 가능 여부 체크
                $user['webdav_enabled'] = checkWebDAVEnabled((int)$user['id']);
                $result = ['success' => true, 'user' => $user];
                
                // 탈퇴/삭제 사용자 자동 정리 (하루 1회)
                autoPurgeDeletedUsers($db);
            } else {
                $result = ['success' => false, 'error' => __('login_required')];
            }
            break;
            
        case 'change_password':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $result = $auth->changePassword($input['current_password'] ?? '', $input['new_password'] ?? '');
            break;
        
        case 'app_password_list':
            $auth->requireLogin();
            $user = $auth->getUser();
            $users = $db->load('users');
            $appPasswords = [];
            foreach ($users as $u) {
                if ($u['id'] === $user['id']) {
                    $appPasswords = $u['app_passwords'] ?? [];
                    break;
                }
            }
            // 비밀번호 해시는 제외하고 메타데이터만 반환
            $list = [];
            foreach ($appPasswords as $ap) {
                $list[] = [
                    'id' => $ap['id'],
                    'label' => $ap['label'],
                    'created' => $ap['created'],
                    'last_used' => $ap['last_used'] ?? null,
                    'prefix' => $ap['prefix'] ?? ''
                ];
            }
            $result = ['success' => true, 'passwords' => $list];
            break;
        
        case 'app_password_create':
            $auth->requireLogin();
            $user = $auth->getUser();
            $label = trim($input['label'] ?? '');
            if ($label === '') $label = 'WebDAV';
            if (mb_strlen($label) > 50) $label = mb_substr($label, 0, 50);
            
            // 랜덤 앱 비밀번호 생성 (20자: 대소문자+숫자, 혼동 문자 제외)
            // 제외: 0/O/o, 1/I/i/l/L, 2/Z/z, 5/S/s, 8/B/b
            $chars = 'ACDEFGHJKMNPQRTUVWXYacdefghjkmnpqrtuvwxy34679';
            $raw = '';
            for ($i = 0; $i < 20; $i++) {
                $raw .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $plainPassword = $raw; // 하이픈 없이 그대로
            $hashedPassword = password_hash($raw, PASSWORD_BCRYPT);
            
            $appEntry = [
                'id' => bin2hex(random_bytes(8)),
                'label' => $label,
                'hash' => $hashedPassword,
                'prefix' => substr($raw, 0, 6) . '**********',
                'created' => date('Y-m-d H:i:s'),
                'last_used' => null
            ];
            
            $users = $db->load('users');
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    if (!isset($u['app_passwords'])) $u['app_passwords'] = [];
                    if (count($u['app_passwords']) >= 10) {
                        $result = ['success' => false, 'error' => __('app_password_limit', '앱 비밀번호는 최대 10개까지 생성할 수 있습니다.')];
                        break 2;
                    }
                    $u['app_passwords'][] = $appEntry;
                    break;
                }
            }
            unset($u);
            $db->save('users', $users);
            $result = ['success' => true, 'password' => $plainPassword, 'id' => $appEntry['id'], 'label' => $label];
            break;
        
        case 'app_password_delete':
            $auth->requireLogin();
            $user = $auth->getUser();
            $deleteId = $input['id'] ?? '';
            if (empty($deleteId)) {
                $result = ['success' => false, 'error' => 'ID required'];
                break;
            }
            $users = $db->load('users');
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $u['app_passwords'] = array_values(array_filter($u['app_passwords'] ?? [], function($ap) use ($deleteId) {
                        return $ap['id'] !== $deleteId;
                    }));
                    break;
                }
            }
            unset($u);
            $db->save('users', $users);
            $result = ['success' => true];
            break;
        
        case 'change_display_name':
            $auth->requireLogin();
            $newName = trim($input['display_name'] ?? '');
            if ($newName === '') {
                $result = ['success' => false, 'error' => __('err_empty_display_name', '표시 이름을 입력하세요.')];
                break;
            }
            if (mb_strlen($newName) > 30) {
                $result = ['success' => false, 'error' => __('err_display_name_too_long', '표시 이름은 30자 이내로 입력하세요.')];
                break;
            }
            $user = $auth->getUser();
            $users = $db->load('users');
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $u['display_name'] = $newName;
                    break;
                }
            }
            unset($u);
            $db->save('users', $users);
            $result = ['success' => true, 'display_name' => $newName];
            break;
        
        case 'change_email':
            $auth->requireLogin();
            $newEmail = trim($input['email'] ?? '');
            if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $result = ['success' => false, 'error' => __('err_invalid_email', '올바른 이메일을 입력하세요.')];
                break;
            }
            $user = $auth->getUser();
            $users = $db->load('users');
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $u['email'] = $newEmail;
                    break;
                }
            }
            unset($u);
            $db->save('users', $users);
            $result = ['success' => true, 'email' => $newEmail];
            break;

        // ===== 내 계정 정보 =====
        case 'my_account_info':
            $auth->requireLogin();
            $user = $auth->getUser();
            $result = [
                'success' => true,
                'username' => $user['username'] ?? '',
                'display_name' => $user['display_name'] ?? '',
                'email' => $user['email'] ?? '',
                'created_at' => $user['created_at'] ?? '',
                'ip' => $auth->getCurrentIP(),
                'country' => $auth->getCurrentCountry()
            ];
            break;
        
        // ===== 사용자 개인 설정 =====
        case 'user_preferences_get':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $user = $auth->getUser();
            $result = [
                'success' => true,
                'preferences' => $user['preferences'] ?? []
            ];
            break;
        
        case 'user_preferences_save':
            $auth->requireLogin();
            $user = $auth->getUser();
            $users = $db->load('users');
            
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    if (!isset($u['preferences'])) {
                        $u['preferences'] = [];
                    }
                    // 개별 설정 업데이트
                    if (isset($input['clock_style'])) {
                        $u['preferences']['clock_style'] = $input['clock_style'];
                    }
                    if (isset($input['dismissed_notifications'])) {
                        $u['preferences']['dismissed_notifications'] = $input['dismissed_notifications'];
                    }
                    if (isset($input['sort_settings'])) {
                        $u['preferences']['sort_settings'] = $input['sort_settings'];
                    }
                    if (isset($input['view_settings'])) {
                        $u['preferences']['view_settings'] = $input['view_settings'];
                    }
                    break;
                }
            }
            
            $db->save('users', $users);
            $result = ['success' => true];
            break;
        
        // ===== 회원 탈퇴 =====
        case 'withdraw_account':
            $auth->requireLogin();
            $result = $auth->withdrawAccount($input['password'] ?? '');
            break;
        
        // ===== 2FA (TOTP) =====
        case '2fa_status':
            $auth->requireLogin();
            $result = $auth->get2FAStatus();
            break;
            
        case '2fa_setup':
            $auth->requireLogin();
            $result = $auth->setup2FA();
            break;
            
        case '2fa_enable':
            $auth->requireLogin();
            $result = $auth->enable2FA($input['code'] ?? '');
            break;
            
        case '2fa_disable':
            $auth->requireLogin();
            $result = $auth->disable2FA($input['password'] ?? '', $input['code'] ?? '');
            break;
            
        case '2fa_verify':
            // 로그인 2단계 - 로그인 안 된 상태
            $result = $auth->verify2FA($input['code'] ?? '');
            
            // 2FA 검증 성공 시 활동 로그 + WebDAV 권한
            if ($result['success'] ?? false) {
                $verifiedUser = $result['user'] ?? [];
                if (!empty($verifiedUser['id'])) {
                    $result['user']['webdav_enabled'] = checkWebDAVEnabled((int)$verifiedUser['id']);
                }
                $activityLog->log(ActivityLog::TYPE_LOGIN, [
                    'user_id' => $verifiedUser['id'] ?? 0,
                    'username' => $verifiedUser['username'] ?? '',
                    'display_name' => $verifiedUser['display_name'] ?? $verifiedUser['username'] ?? '',
                    'filename' => $verifiedUser['username'] ?? '',
                    'details' => __('twofa_verified')
                ]);
            }
            break;
            
        case '2fa_regenerate_backup':
            $auth->requireLogin();
            $result = $auth->regenerateBackupCodes($input['password'] ?? '');
            break;
        
        // ===== 세션 관리 =====
        case 'sessions':
            $auth->requireLogin();
            $result = ['success' => true, 'sessions' => $auth->getSessions()];
            break;
            
        case 'terminate_session':
            $auth->requireLogin();
            $result = $auth->terminateSession($input['session_id'] ?? '');
            break;
            
        case 'terminate_all_sessions':
            $auth->requireLogin();
            $result = $auth->terminateAllOtherSessions();
            break;
        
        // ===== 로그인 로그 =====
        case 'login_logs':
            $auth->requireLogin();
            $all = isset($_GET['all']) && $_GET['all'] === 'true';
            $page = (int)($_GET['page'] ?? 1);
            $perPage = (int)($_GET['per_page'] ?? 20);
            $data = $auth->getLoginLogs($page, $perPage, $all);
            $result = ['success' => true, ...$data];
            break;
        
        case 'login_logs_delete':
            $auth->requireAdminPerm('logins');
            $ids = $input['ids'] ?? [];
            $result = $auth->deleteLoginLogs($ids);
            break;
        
        case 'login_logs_delete_all':
            $auth->requireAdminPerm('logins');
            $result = $auth->deleteAllLoginLogs();
            break;
        
        case 'login_logs_delete_old':
            $auth->requireAdminPerm('logins');
            $days = (int)($input['days'] ?? 30);
            $result = $auth->deleteOldLoginLogs($days);
            break;
        
        case 'access_stats':
            $auth->requireAdminPerm('logins');
            $result = $auth->getAccessStats();
            break;
        
        // ===== 사용자 관리 (관리자) =====
        case 'pending_users_count':
            $auth->requireAdminPerm('users');
            session_write_close(); // 세션 락 해제
            $users = $db->load('users');
            $pending = array_values(array_filter($users, fn($u) => ($u['status'] ?? 'active') === 'pending'));
            $pendingList = array_map(fn($u) => [
                'username' => $u['username'],
                'display_name' => $u['display_name'] ?? $u['username'],
                'created_at' => $u['created_at'] ?? ''
            ], $pending);
            $result = ['success' => true, 'count' => count($pending), 'pending_users' => $pendingList];
            break;
        
        case 'users':
            $auth->requireAdminPerm('users');
            $result = ['success' => true, 'users' => $auth->getUsers()];
            break;
            
        case 'user_create':
            $auth->requireAdminPerm('users');
            // 부관리자는 admin 역할의 사용자 생성 불가 (권한 상승 방지)
            if (!$auth->isAdmin()) {
                $requestedRole = $input['role'] ?? 'user';
                if ($requestedRole === 'admin') {
                    $result = ['success' => false, 'error' => __('api_err_no_permission_role', '관리자 역할의 사용자를 생성할 권한이 없습니다.')];
                    break;
                }
            }
            // 용량이 미지정(0)이면 기본 용량 적용
            $settings = $db->load('settings') ?: [];
            if (empty($input['quota']) && !empty($settings['default_quota'])) {
                $input['quota'] = (int)$settings['default_quota'];
            }
            $result = $auth->createUser($input);
            // 스토리지별 기본 권한 적용
            if (!empty($result['success']) && !empty($result['id'])) {
                $storages = $db->load('storages') ?: [];
                foreach ($storages as $s) {
                    $sid = $s['id'] ?? 0;
                    if (!$sid) continue;
                    $defPerms = $s['default_permissions'] ?? [];
                    if (empty($defPerms)) continue;
                    $db->insert('permissions', [
                        'storage_id' => $sid,
                        'user_id' => $result['id'],
                        'can_visible' => (int)($defPerms['can_visible'] ?? 0),
                        'can_visible_webdav' => (int)($defPerms['can_visible_webdav'] ?? 0),
                        'can_read' => (int)($defPerms['can_read'] ?? 0),
                        'can_download' => (int)($defPerms['can_download'] ?? 0),
                        'can_write' => (int)($defPerms['can_write'] ?? 0),
                        'can_delete' => (int)($defPerms['can_delete'] ?? 0),
                        'can_share' => (int)($defPerms['can_share'] ?? 0),
                    ]);
                }
            }
            break;
            
        case 'user_update':
            $auth->requireAdminPerm('users');
            // 부관리자는 admin 역할로 변경 불가 (권한 상승 방지)
            if (!$auth->isAdmin()) {
                $requestedRole = $input['role'] ?? null;
                if ($requestedRole === 'admin') {
                    $result = ['success' => false, 'error' => __('api_err_no_permission_role', '관리자 역할의 사용자를 생성할 권한이 없습니다.')];
                    break;
                }
            }
            $result = $auth->updateUser((int)($input['id'] ?? 0), $input);
            break;
            
        case 'user_delete':
            $auth->requireAdminPerm('users');
            $result = $auth->deleteUser((int)($input['id'] ?? 0));
            break;
        
        // ===== 삭제된 사용자 =====
        case 'deleted_users':
            $auth->requireAdminPerm('users');
            $result = ['success' => true, 'users' => $auth->getDeletedUsers()];
            break;
        
        case 'deleted_user_purge':
            $auth->requireAdminPerm('users');
            $deleteFiles = !empty($input['delete_files']);
            $result = $auth->purgeDeletedUser($input['id'] ?? '', $deleteFiles);
            break;
        
        case 'auto_purge_settings':
            $auth->requireAdminPerm('users');
            $settings = $db->load('settings') ?: [];
            $settings['auto_purge_deleted'] = (bool)($input['auto_purge_deleted'] ?? false);
            $settings['auto_purge_days'] = max(1, (int)($input['auto_purge_days'] ?? 90));
            $db->save('settings', $settings);
            $result = ['success' => true];
            break;
        
        case 'restore_deleted_user':
            $auth->requireAdminPerm('users');
            session_write_close(); // 세션 락 해제
            $username = trim($input['username'] ?? '');
            if (empty($username)) {
                $result = ['success' => false, 'error' => __('api_err_username_required', '아이디를 입력하세요')];
                break;
            }
            
            // deleted_users에서 찾기
            $deletedUsers = $db->load('deleted_users') ?: [];
            $foundIndex = -1;
            $foundUser = null;
            foreach ($deletedUsers as $idx => $du) {
                if (strtolower(trim($du['username'] ?? '')) === strtolower($username)) {
                    $foundIndex = $idx;
                    $foundUser = $du;
                    break;
                }
            }
            
            if ($foundIndex === -1 || !$foundUser) {
                $result = ['success' => false, 'error' => __('deleted_user_not_found', '탈퇴/삭제 기록에서 해당 사용자를 찾을 수 없습니다.')];
                break;
            }
            
            // 현재 users에 같은 아이디 있는지 확인
            $existing = $db->find('users', ['username' => $username]);
            if ($existing) {
                $result = ['success' => false, 'error' => __('username_already_exists', '이미 사용 중인 아이디입니다.')];
                break;
            }
            
            // 임시 비밀번호 생성
            $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#';
            $tempPassword = '';
            for ($i = 0; $i < 10; $i++) {
                $tempPassword .= $chars[random_int(0, strlen($chars) - 1)];
            }
            
            // users 테이블에 복원
            $restoredUser = [
                'username' => $foundUser['username'],
                'password' => password_hash($tempPassword, PASSWORD_DEFAULT),
                'display_name' => $foundUser['display_name'] ?? $foundUser['username'],
                'email' => $foundUser['email'] ?? '',
                'role' => ($foundUser['role'] ?? 'user') === 'admin' ? 'user' : ($foundUser['role'] ?? 'user'),
                'status' => 'active',
                'created_at' => $foundUser['created_at'] ?? date('Y-m-d H:i:s'),
                'restored_at' => date('Y-m-d H:i:s')
            ];
            $newId = $db->insert('users', $restoredUser);
            
            // 스토리지별 기본 권한 적용
            if ($newId) {
                $storages = $db->load('storages') ?: [];
                foreach ($storages as $s) {
                    $sid = $s['id'] ?? 0;
                    if (!$sid) continue;
                    $defPerms = $s['default_permissions'] ?? [];
                    if (empty($defPerms)) continue;
                    $db->insert('permissions', [
                        'storage_id' => $sid,
                        'user_id' => $newId,
                        'can_visible' => (int)($defPerms['can_visible'] ?? 0),
                        'can_visible_webdav' => (int)($defPerms['can_visible_webdav'] ?? 0),
                        'can_read' => (int)($defPerms['can_read'] ?? 0),
                        'can_download' => (int)($defPerms['can_download'] ?? 0),
                        'can_write' => (int)($defPerms['can_write'] ?? 0),
                        'can_delete' => (int)($defPerms['can_delete'] ?? 0),
                        'can_share' => (int)($defPerms['can_share'] ?? 0),
                    ]);
                }
                
                // 개인폴더 생성 (없으면)
                $userFolder = USER_FILES_ROOT . '/' . $foundUser['username'];
                if (!is_dir($userFolder)) {
                    @mkdir($userFolder, 0755, true);
                }
            }
            
            // deleted_users에서 제거
            array_splice($deletedUsers, $foundIndex, 1);
            $db->save('deleted_users', array_values($deletedUsers));
            
            $result = [
                'success' => true,
                'message' => __('restore_user_success', '계정이 복원되었습니다.'),
                'temp_password' => $tempPassword
            ];
            break;
        
        // ===== 약관 관리 =====
        case 'terms':
            // 약관 조회 (비로그인 가능)
            $terms = $db->load('terms') ?: ['enabled' => false, 'content' => '', 'updated_at' => ''];
            $result = ['success' => true, 'terms' => $terms];
            break;
        
        case 'terms_save':
            $auth->requireAdminPerm('users');
            $terms = [
                'enabled' => !empty($input['enabled']),
                'content' => sanitizeHtml($input['content'] ?? ''),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            $db->save('terms', $terms);
            $result = ['success' => true];
            break;
        
        // ===== 공지 설정 =====
        case 'notice_settings':
            $auth->requireAdminPerm('users');
            $notice = $db->load('notice') ?: ['popups' => [], 'banner' => [], 'settings' => []];
            $result = [
                'success' => true, 
                'popups' => $notice['popups'] ?? [], 
                'banner' => $notice['banner'] ?? [],
                'settings' => $notice['settings'] ?? [
                    'layout' => 'horizontal',
                    'defaultWidth' => 350,
                    'defaultHeight' => 250,
                    'gap' => 20,
                    'startX' => 20,
                    'startY' => 80
                ]
            ];
            break;
        
        case 'notice_save':
            $auth->requireAdminPerm('users');
            session_write_close(); // 세션 락 해제
            $popups = $input['popups'] ?? [];
            // 팝업 필드 새니타이징
            foreach ($popups as &$popup) {
                if (isset($popup['content'])) {
                    $popup['content'] = sanitizeHtml($popup['content']);
                }
                // 텍스트 필드 strip_tags
                if (isset($popup['title'])) $popup['title'] = strip_tags($popup['title']);
                // URL 프로토콜 화이트리스트 (link, image)
                if (isset($popup['link']) && !preg_match('#^(https?://|/)#i', $popup['link'])) {
                    $popup['link'] = '';
                }
                if (isset($popup['image']) && !preg_match('#^(https?://|/|data:image/)#i', $popup['image'])) {
                    $popup['image'] = '';
                }
                // position 화이트리스트
                if (isset($popup['position']) && !in_array($popup['position'], ['center', 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'custom'])) {
                    $popup['position'] = 'center';
                }
            }
            unset($popup);
            $banner = $input['banner'] ?? [];
            // 배너 content는 plain text (프론트에서 textContent로 렌더링)
            if (isset($banner['content'])) {
                $banner['content'] = strip_tags($banner['content']);
            }
            // settings 화이트리스트 검증
            $rawSettings = $input['settings'] ?? [];
            $allowedSettingsKeys = ['layout', 'defaultWidth', 'defaultHeight', 'gap', 'startX', 'startY'];
            $settings = [];
            foreach ($allowedSettingsKeys as $sk) {
                if (isset($rawSettings[$sk])) {
                    $settings[$sk] = is_numeric($rawSettings[$sk]) ? (int)$rawSettings[$sk] : (string)$rawSettings[$sk];
                }
            }
            
            $notice = [
                'popups' => $popups,
                'banner' => $banner,
                'settings' => $settings,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            $db->save('notice', $notice);
            $result = ['success' => true];
            break;
        
        case 'notice_active':
            // 활성화된 공지 조회 (로그인 사용자만)
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $notice = $db->load('notice') ?: ['popups' => [], 'banner' => [], 'settings' => []];
            $today = date('Y-m-d');
            
            // 활성 팝업 필터링
            $activePopups = [];
            foreach (($notice['popups'] ?? []) as $popup) {
                if (empty($popup['enabled'])) continue;
                if (!empty($popup['start_date']) && $popup['start_date'] > $today) continue;
                if (!empty($popup['end_date']) && $popup['end_date'] < $today) continue;
                $activePopups[] = $popup;
            }
            
            // 활성 배너 필터링
            $banner = $notice['banner'] ?? [];
            $activeBanner = null;
            if (!empty($banner['enabled']) && !empty($banner['content'])) {
                $showBanner = true;
                if (!empty($banner['start_date']) && $banner['start_date'] > $today) $showBanner = false;
                if (!empty($banner['end_date']) && $banner['end_date'] < $today) $showBanner = false;
                if ($showBanner) $activeBanner = $banner;
            }
            
            // 배치 설정
            $settings = $notice['settings'] ?? [
                'layout' => 'horizontal',
                'defaultWidth' => 350,
                'defaultHeight' => 250,
                'gap' => 20,
                'startX' => 20,
                'startY' => 80
            ];
            
            $result = ['success' => true, 'popups' => $activePopups, 'banner' => $activeBanner, 'settings' => $settings];
            break;
        
        // ===== 게시판 =====
        case 'board_list_all':
            // 게시판 목록 (사이드바용 - 로그인 사용자)
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $boards = $db->load('boards') ?: [];
            $user = $auth->getUser();
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
            
            // 모든 게시글의 최신 시간을 게시판별로 계산
            $allPosts = $db->load('board_posts') ?: [];
            $latestPostMap = [];
            $postCountMap = [];
            foreach ($allPosts as $p) {
                $bid = $p['board_id'] ?? 0;
                $postCountMap[$bid] = ($postCountMap[$bid] ?? 0) + 1;
                $pTime = $p['created_at'] ?? '';
                if (!isset($latestPostMap[$bid]) || $pTime > $latestPostMap[$bid]) {
                    $latestPostMap[$bid] = $pTime;
                }
            }
            
            $visible = [];
            foreach ($boards as $b) {
                if (!($b['enabled'] ?? true)) continue;
                $permList = $b['perm_list'] ?? 'all';
                if ($permList === 'admin' && !$isAdmin) continue;
                if ($permList === 'none') continue;
                $bid = $b['id'] ?? 0;
                $b['post_count'] = $postCountMap[$bid] ?? 0;
                $b['latest_post_at'] = $latestPostMap[$bid] ?? null;
                $visible[] = $b;
            }
            usort($visible, fn($a, $b) => ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0));
            $result = ['success' => true, 'boards' => $visible];
            break;

        case 'board_manage_list':
            // 게시판 관리 목록 (관리자)
            $auth->requireAdmin();
            $boards = $db->load('boards') ?: [];
            usort($boards, fn($a, $b) => ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0));
            foreach ($boards as &$b) {
                $b['post_count'] = count($db->findAll('board_posts', ['board_id' => $b['id']]));
            }
            $result = ['success' => true, 'boards' => $boards];
            break;

        case 'board_save':
            // 게시판 추가/수정 (관리자)
            $auth->requireAdmin();
            session_write_close(); // 세션 락 해제
            $boardId = $input['id'] ?? null;
            $boards = $db->load('boards') ?: [];
            
            $boardData = [
                'name' => trim($input['name'] ?? ''),
                'icon' => trim($input['icon'] ?? '📋'),
                'description' => trim($input['description'] ?? ''),
                'perm_list' => $input['perm_list'] ?? 'all',
                'perm_read' => $input['perm_read'] ?? 'all',
                'perm_write' => $input['perm_write'] ?? 'all',
                'allow_comment' => !empty($input['allow_comment']),
                'allowed_ext' => trim($input['allowed_ext'] ?? ''),
                'posts_per_page' => max(1, min(100, (int)($input['posts_per_page'] ?? 20))),
                'page_nav' => max(1, min(20, (int)($input['page_nav'] ?? 10))),
                'comments_per_page' => max(1, min(100, (int)($input['comments_per_page'] ?? 20))),
                'comment_nav' => max(1, min(20, (int)($input['comment_nav'] ?? 10))),
                'new_badge_hours' => max(0, min(720, (int)($input['new_badge_hours'] ?? 24))),
                'enabled' => true,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if (empty($boardData['name'])) {
                $result = ['success' => false, 'error' => __('api_err_board_name_required', '게시판 이름을 입력하세요.')];
                break;
            }

            if ($boardId) {
                // 수정
                foreach ($boards as &$b) {
                    if ($b['id'] == $boardId) {
                        $b = array_merge($b, $boardData);
                        break;
                    }
                }
                unset($b);
            } else {
                // 추가
                $maxId = 0;
                foreach ($boards as $b) { if (($b['id'] ?? 0) > $maxId) $maxId = $b['id']; }
                $boardData['id'] = $maxId + 1;
                $boardData['created_at'] = date('Y-m-d H:i:s');
                $boardData['sort_order'] = count($boards);
                $boards[] = $boardData;
            }

            $db->save('boards', $boards);
            $result = ['success' => true];
            break;

        case 'board_delete':
            // 게시판 삭제 (관리자)
            $auth->requireAdmin();
            $boardId = (int)($input['id'] ?? 0);
            $boards = $db->load('boards') ?: [];
            $boards = array_values(array_filter($boards, fn($b) => ($b['id'] ?? 0) != $boardId));
            $db->save('boards', $boards);
            // 해당 게시판의 글/댓글도 삭제
            $posts = $db->load('board_posts') ?: [];
            $posts = array_values(array_filter($posts, fn($p) => ($p['board_id'] ?? 0) != $boardId));
            $db->save('board_posts', $posts);
            $comments = $db->load('board_comments') ?: [];
            $comments = array_values(array_filter($comments, fn($c) => ($c['board_id'] ?? 0) != $boardId));
            $db->save('board_comments', $comments);
            // 첨부파일 폴더 삭제
            $boardFilesDir = __DIR__ . '/data/board_files/' . $boardId;
            if ($boardId > 0 && is_dir($boardFilesDir)) {
                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($boardFilesDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iter as $f) {
                    if ($f->isDir()) @rmdir($f->getPathname());
                    else @unlink($f->getPathname());
                }
                @rmdir($boardFilesDir);
            }
            $result = ['success' => true];
            break;

        case 'board_posts':
            // 게시글 목록
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $boardId = (int)($input['board_id'] ?? 0);
            $page = max(1, (int)($input['page'] ?? 1));
            $search = trim($input['search'] ?? '');
            
            // 권한 확인
            $boards = $db->load('boards') ?: [];
            $board = null;
            foreach ($boards as $b) { if (($b['id'] ?? 0) == $boardId) { $board = $b; break; } }
            if (!$board) { $result = ['success' => false, 'error' => __('api_err_board_not_found', '게시판을 찾을 수 없습니다.')]; break; }
            
            $perPage = $board['posts_per_page'] ?? 20;
            
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
            $permList = $board['perm_list'] ?? 'all';
            if ($permList === 'none' && !$isAdmin) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
            }
            if ($permList === 'admin' && !$isAdmin) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
            }
            
            $allPosts = $db->findAll('board_posts', ['board_id' => $boardId]);
            
            // 검색 (검색 조건: title_content(기본)/title/content/comment/author)
            if ($search) {
                $searchType = $input['search_type'] ?? 'title_content';
                if ($searchType === 'comment') {
                    // 댓글내용 검색: 같은 게시판의 댓글에서 일치하는 글 id를 모아 그 글만 남긴다.
                    // (삭제된 댓글은 content가 비워지므로 제외)
                    $cmts = $db->load('board_comments') ?: [];
                    $hitPostIds = [];
                    foreach ($cmts as $c) {
                        if ((int)($c['board_id'] ?? 0) !== (int)$boardId) continue;
                        if (!empty($c['is_deleted'])) continue;
                        if (stripos($c['content'] ?? '', $search) !== false) {
                            $hitPostIds[(int)($c['post_id'] ?? 0)] = true;
                        }
                    }
                    $allPosts = array_filter($allPosts, function($p) use ($hitPostIds) {
                        return isset($hitPostIds[(int)($p['id'] ?? 0)]);
                    });
                } else {
                    $allPosts = array_filter($allPosts, function($p) use ($search, $searchType) {
                        switch ($searchType) {
                            case 'title':   return stripos($p['title'] ?? '', $search) !== false;
                            case 'content': return stripos($p['content'] ?? '', $search) !== false;
                            case 'author':  return stripos($p['author_name'] ?? '', $search) !== false;
                            default:        return stripos($p['title'] ?? '', $search) !== false
                                                || stripos($p['content'] ?? '', $search) !== false;
                        }
                    });
                }
                $allPosts = array_values($allPosts);
            }
            
            // 고정글 + 일반글 분리
            $pinned = array_filter($allPosts, fn($p) => !empty($p['is_pinned']));
            $normal = array_filter($allPosts, fn($p) => empty($p['is_pinned']));
            usort($pinned, fn($a, $b) => strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? ''));
            usort($normal, fn($a, $b) => strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? ''));
            
            $total = count($normal);
            $totalPages = max(1, ceil($total / $perPage));
            $offset = ($page - 1) * $perPage;
            $normalSlice = array_slice($normal, $offset, $perPage);
            
            // 댓글 수 추가
            $comments = $db->load('board_comments') ?: [];
            $commentCounts = [];
            foreach ($comments as $c) {
                $pid = $c['post_id'] ?? 0;
                $commentCounts[$pid] = ($commentCounts[$pid] ?? 0) + 1;
            }
            
            $posts = array_merge($page === 1 ? array_values($pinned) : [], array_values($normalSlice));
            
            // 번호 매기기 (일반글만 역순 번호, 공지글은 '공지')
            $normalTotal = count($normal);
            $normalNumMap = [];
            foreach (array_values($normal) as $idx => $sp) {
                $normalNumMap[$sp['id'] ?? 0] = $normalTotal - $idx;
            }
            
            // 사용자 목록 로드 (표시이름 변환용)
            $allUsers = $db->load('users') ?: [];
            $userMap = [];
            foreach ($allUsers as $u) {
                $userMap[$u['id'] ?? 0] = $u['display_name'] ?? $u['username'] ?? '';
            }
            
            foreach ($posts as &$p) {
                $p['comment_count'] = $commentCounts[$p['id'] ?? 0] ?? 0;
                // author_id로 최신 표시이름 반영
                if (!empty($p['author_id']) && isset($userMap[$p['author_id']])) {
                    $p['author_name'] = $userMap[$p['author_id']];
                }
                if (!empty($p['is_pinned'])) {
                    $p['num'] = 0; // 공지글은 0 (프론트에서 '공지'로 표시)
                } else {
                    $p['num'] = $normalNumMap[$p['id'] ?? 0] ?? 0;
                }
            }
            unset($p);
            
            $result = [
                'success' => true,
                'posts' => $posts,
                'total' => $total + count($pinned),
                'page' => $page,
                'total_pages' => $totalPages,
                'board' => $board
            ];
            break;

        case 'board_post_view':
            // 게시글 읽기
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $postId = (int)($input['post_id'] ?? 0);
            
            // 조회수 증가 (atomic)
            $post = null;
            $db->atomicUpdate('board_posts', function($posts) use ($postId, &$post) {
                foreach ($posts as &$p) {
                    if (($p['id'] ?? 0) == $postId) {
                        $p['views'] = ($p['views'] ?? 0) + 1;
                        $post = $p;
                        break;
                    }
                }
                unset($p);
                return $posts;
            });
            
            // 댓글
            $comments = $db->findAll('board_comments', ['post_id' => $postId]);
            usort($comments, fn($a, $b) => strtotime($a['created_at'] ?? '') - strtotime($b['created_at'] ?? ''));
            
            // 게시판 정보
            $boards = $db->load('boards') ?: [];
            $board = null;
            foreach ($boards as $b) { if (($b['id'] ?? 0) == ($post['board_id'] ?? 0)) { $board = $b; break; } }
            
            // 읽기 권한 확인
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
            $permRead = $board['perm_read'] ?? 'all';
            if (($permRead === 'none' || $permRead === 'admin') && !$isAdmin) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
            }
            
            // 사용자 목록 (표시이름 변환용)
            $allUsers = $db->load('users') ?: [];
            $userMap = [];
            foreach ($allUsers as $u) {
                $userMap[$u['id'] ?? 0] = $u['display_name'] ?? $u['username'] ?? '';
            }
            
            // 게시글 작성자 표시이름 반영
            if ($post && !empty($post['author_id']) && isset($userMap[$post['author_id']])) {
                $post['author_name'] = $userMap[$post['author_id']];
            }
            // 댓글 작성자 표시이름 반영
            foreach ($comments as &$c) {
                if (!empty($c['author_id']) && isset($userMap[$c['author_id']])) {
                    $c['author_name'] = $userMap[$c['author_id']];
                }
            }
            unset($c);
            
            $result = ['success' => true, 'post' => $post, 'comments' => $comments, 'board' => $board];
            break;

        case 'board_post_save':
            // 게시글 작성/수정
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $user = $auth->getUser();
            $boardId = (int)($input['board_id'] ?? 0);
            $postId = $input['post_id'] ?? null;
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
            
            // 권한 확인
            $boards = $db->load('boards') ?: [];
            $board = null;
            foreach ($boards as $b) { if (($b['id'] ?? 0) == $boardId) { $board = $b; break; } }
            if (!$board) { $result = ['success' => false, 'error' => __('api_err_board_not_found', '게시판을 찾을 수 없습니다.')]; break; }
            $permWrite = $board['perm_write'] ?? 'all';
            if (($permWrite === 'none' || $permWrite === 'admin') && !$isAdmin) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
            }
            
            $title = trim($input['title'] ?? '');
            // sanitize는 파일 URL 치환 후 실행 (data: URL 64KB 제한 방지)
            $content = trim($input['content'] ?? '');
            if (empty($title)) { $result = ['success' => false, 'error' => __('api_err_title_required', '제목을 입력하세요.')]; break; }
            
            // data-video-src 플레이스홀더를 video 태그로 변환
            $content = convertVideoSrcPlaceholders($content);
            // data-video-pending 플레이스홀더 → data-pending-name video로 변환 (업로드 시 URL 치환됨)
            $content = preg_replace_callback(
                '/<img([^>]*?)data-video-pending="([^"]*)"([^>]*?)\/?>/i',
                function($m) {
                    $pendingName = $m[2];
                    $attrs = $m[1] . $m[3];
                    $style = 'max-width:100%;border-radius:6px;';
                    if (preg_match('/style="([^"]*)"/', $attrs, $sm)) {
                        $parts = [];
                        if (preg_match('/width\s*:\s*[^;]+/', $sm[1], $wm)) $parts[] = $wm[0];
                        if (preg_match('/height\s*:\s*[^;]+/', $sm[1], $hm)) $parts[] = $hm[0];
                        if ($parts) $style = implode(';', $parts) . ';max-width:100%;border-radius:6px;';
                    }
                    return '<video controls playsinline data-pending-name="' . htmlspecialchars($pendingName, ENT_QUOTES) . '" style="' . $style . '"></video>';
                },
                $content
            );

            // 플레이스홀더 치환 완료 후 sanitize 실행
            $content = sanitizeHtml($content);

            $posts = $db->load('board_posts') ?: [];
            
            if ($postId) {
                // 수정
                $permDenied = false;
                $db->atomicUpdate('board_posts', function($posts) use ($postId, $title, $content, $input, $isAdmin, $user, &$permDenied) {
                    foreach ($posts as &$p) {
                        if (($p['id'] ?? 0) == $postId) {
                            if ($p['author_id'] != $user['id'] && !$isAdmin) {
                                $permDenied = true;
                                return $posts;
                            }
                            $p['title'] = $title;
                            $p['content'] = $content;
                            $p['is_pinned'] = !empty($input['is_pinned']) && $isAdmin;
                            $p['is_notice'] = !empty($input['is_notice']) && $isAdmin;
                            $p['notice_color'] = (!empty($input['is_notice']) && $isAdmin && !empty($input['notice_color'])) ? preg_replace('/[^#a-fA-F0-9]/', '', $input['notice_color']) : null;
                            $p['updated_at'] = date('Y-m-d H:i:s');
                            // 공지로 변경 시 읽음 상태 초기화
                            if (!empty($input['is_notice']) && $isAdmin) {
                                $p['read_by'] = [];
                            }
                            break;
                        }
                    }
                    unset($p);
                    return $posts;
                });
                if ($permDenied) {
                    $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
                }
            } else {
                // 작성
                $db->atomicUpdate('board_posts', function($posts) use (&$postId, $boardId, $title, $content, $input, $isAdmin, $user) {
                    $maxId = 0;
                    foreach ($posts as $p) { if (($p['id'] ?? 0) > $maxId) $maxId = $p['id']; }
                    $posts[] = [
                        'id' => $maxId + 1,
                        'board_id' => $boardId,
                        'title' => $title,
                        'content' => $content,
                        'author_id' => $user['id'],
                        'author_name' => $user['display_name'] ?? $user['username'] ?? '',
                        'is_pinned' => !empty($input['is_pinned']) && $isAdmin,
                        'is_notice' => !empty($input['is_notice']) && $isAdmin,
                        'notice_color' => (!empty($input['is_notice']) && $isAdmin && !empty($input['notice_color'])) ? preg_replace('/[^#a-fA-F0-9]/', '', $input['notice_color']) : null,
                        'attachments' => [],
                        'read_by' => [],
                        'views' => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ];
                    $postId = $maxId + 1;
                    return $posts;
                });
            }
            
            $result = ['success' => true, 'post_id' => $postId];
            break;

        case 'board_post_upload':
            // 게시글 첨부파일 업로드
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $postId = (int)($_POST['post_id'] ?? 0);
            $boardId = (int)($_POST['board_id'] ?? 0);
            $user = $auth->getUser();
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
            
            if (empty($_FILES['file'])) {
                $result = ['success' => false, 'error' => __('api_err_no_file', '파일이 없습니다.')]; break;
            }
            
            // 확장자 검증
            $boards = $db->load('boards') ?: [];
            $board = null;
            foreach ($boards as $b) { if (($b['id'] ?? 0) == $boardId) { $board = $b; break; } }
            
            // 게시판 쓰기 권한 체크
            if ($board) {
                $permWrite = $board['perm_write'] ?? 'all';
                if ($permWrite === 'admin' && !$isAdmin) {
                    $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
                }
            }
            
            $allowedExt = trim($board['allowed_ext'] ?? '');
            $boardAllowedExts = [];
            if (!empty($allowedExt)) {
                $boardAllowedExts = array_map('trim', array_map('strtolower', explode(',', $allowedExt)));
                $fileExt = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                if (!in_array($fileExt, $boardAllowedExts)) {
                    $result = ['success' => false, 'error' => __('api_err_ext_not_allowed', '허용되지 않는 파일 형식입니다.') . ' (' . $allowedExt . ')']; break;
                }
            }
            
            // 저장 경로
            $uploadDir = __DIR__ . '/data/board_files/' . $boardId . '/' . $postId;
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $file = $_FILES['file'];
            $origName = basename($file['name']);
            
            // 서버 실행 확장자는 게시판 허용 설정과 무관하게 절대 차단
            $serverExecExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps',
                'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'htaccess', 'user.ini'];
            $finalExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (in_array($finalExt, $serverExecExts)) {
                $result = ['success' => false, 'error' => __('api_err_dangerous_ext', '보안상 허용되지 않는 파일 형식입니다.')]; break;
            }
            
            // 이중 확장자 차단 (예: evil.php.jpg, shell.phtml.png)
            $nameParts = explode('.', strtolower($origName));
            if (count($nameParts) > 2) {
                for ($i = 1; $i < count($nameParts) - 1; $i++) {
                    if (in_array($nameParts[$i], $serverExecExts)) {
                        $result = ['success' => false, 'error' => __('api_err_dangerous_filename', '보안상 허용되지 않는 파일명입니다.')]; break 2;
                    }
                }
            }
            
            // 서버 실행 확장자만 절대 차단 — 나머지는 게시판 허용 설정에 따름
            
            // MIME 타입 검증 (fileinfo 확장 사용 가능 시)
            if (function_exists('finfo_open') && !empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $realMime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                // 서버 실행 가능한 MIME 타입만 차단 (PHP, 쉘스크립트)
                $blockedMimes = [
                    'application/x-httpd-php', 'application/x-php', 'text/x-php',
                    'application/x-shellscript', 'text/x-shellscript',
                ];
                if (in_array($realMime, $blockedMimes)) {
                    $result = ['success' => false, 'error' => __('api_err_dangerous_file', '보안상 허용되지 않는 파일입니다.')]; break;
                }
            }
            
            $cleanName = preg_replace('/[^a-zA-Z0-9가-힣._-]/u', '_', $origName);
            if (empty(trim($cleanName, '_.'))) $cleanName = 'unnamed';
            $safeName = time() . '_' . $cleanName;
            $destPath = $uploadDir . '/' . $safeName;
            
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                $result = ['success' => false, 'error' => __('api_err_upload_failed', '업로드에 실패했습니다.')]; break;
            }
            
            // EXIF orientation 보정 (사진 90도 회전 현상 수정)
            fixImageOrientation($destPath);
            
            // 게시글에 첨부파일 정보 추가
            $posts = $db->load('board_posts') ?: [];
            foreach ($posts as &$p) {
                if (($p['id'] ?? 0) == $postId) {
                    if (!isset($p['attachments'])) $p['attachments'] = [];
                    $p['attachments'][] = [
                        'name' => $origName,
                        'file' => $safeName,
                        'size' => $file['size'],
                        'uploaded_at' => date('Y-m-d H:i:s'),
                    ];
                    break;
                }
            }
            unset($p);
            $db->save('board_posts', $posts);
            $result = ['success' => true, 'name' => $origName, 'file' => $safeName, 'size' => $file['size']];
            break;

        case 'board_post_upload_chunk':
            // 게시글 첨부파일 청크 업로드 (대용량 파일 지원)
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $postId = (int)($_POST['post_id'] ?? 0);
            $boardId = (int)($_POST['board_id'] ?? 0);
            $filename = basename($_POST['filename'] ?? '');
            $chunkIndex = (int)($_POST['chunk_index'] ?? 0);
            $totalChunks = (int)($_POST['total_chunks'] ?? 1);
            $totalSize = (int)($_POST['total_size'] ?? 0);
            $uploadId = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['upload_id'] ?? '');
            
            if (!$postId || !$boardId || !$filename || !$uploadId) {
                $result = ['success' => false, 'error' => __('api_err_missing_params', '필수 매개변수가 누락되었습니다.')]; break;
            }
            
            if (empty($_FILES['chunk'])) {
                $result = ['success' => false, 'error' => __('api_err_no_file', '파일이 없습니다.')]; break;
            }
            
            // 게시판 권한 체크
            $boards = $db->load('boards') ?: [];
            $board = null;
            foreach ($boards as $b) { if (($b['id'] ?? 0) == $boardId) { $board = $b; break; } }
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
            if ($board) {
                $permWrite = $board['perm_write'] ?? 'all';
                if ($permWrite === 'admin' && !$isAdmin) {
                    $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
                }
            }
            
            // 첫 청크에서 확장자 검증
            if ($chunkIndex === 0) {
                $allowedExt = trim($board['allowed_ext'] ?? '');
                if (!empty($allowedExt)) {
                    $exts = array_map('trim', array_map('strtolower', explode(',', $allowedExt)));
                    $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if (!in_array($fileExt, $exts)) {
                        $result = ['success' => false, 'error' => __('api_err_ext_not_allowed', '허용되지 않는 파일 형식입니다.') . ' (' . $allowedExt . ')']; break;
                    }
                }
                
                // 서버 실행 확장자 절대 차단
                $serverExecExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps',
                    'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'htaccess', 'user.ini'];
                $finalExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($finalExt, $serverExecExts)) {
                    $result = ['success' => false, 'error' => __('api_err_dangerous_ext', '보안상 허용되지 않는 파일 형식입니다.')]; break;
                }
                
                // 이중 확장자 차단
                $nameParts = explode('.', strtolower($filename));
                if (count($nameParts) > 2) {
                    for ($i = 1; $i < count($nameParts) - 1; $i++) {
                        if (in_array($nameParts[$i], $serverExecExts)) {
                            $result = ['success' => false, 'error' => __('api_err_dangerous_filename', '보안상 허용되지 않는 파일명입니다.')]; break 2;
                        }
                    }
                }
            }
            
            // 임시 청크 저장 디렉토리
            $chunkDir = __DIR__ . '/data/board_chunks/' . $uploadId;
            if (!is_dir($chunkDir)) mkdir($chunkDir, 0755, true);
            
            $chunkPath = $chunkDir . '/chunk_' . str_pad($chunkIndex, 5, '0', STR_PAD_LEFT);
            if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath)) {
                $result = ['success' => false, 'error' => __('api_err_upload_failed', '업로드에 실패했습니다.')]; break;
            }
            
            // 마지막 청크가 아니면 진행 상태만 반환
            if ($chunkIndex < $totalChunks - 1) {
                $result = ['success' => true, 'chunk' => $chunkIndex, 'total' => $totalChunks];
                break;
            }
            
            // === 마지막 청크: 병합 ===
            $uploadDir = __DIR__ . '/data/board_files/' . $boardId . '/' . $postId;
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $origName = basename($filename);
            $cleanName = preg_replace('/[^a-zA-Z0-9가-힣._-]/u', '_', $origName);
            if (empty(trim($cleanName, '_.'))) $cleanName = 'unnamed';
            $safeName = time() . '_' . $cleanName;
            $destPath = $uploadDir . '/' . $safeName;
            
            $fp = fopen($destPath, 'wb');
            if (!$fp) {
                $result = ['success' => false, 'error' => __('api_err_upload_failed', '업로드에 실패했습니다.')]; break;
            }
            
            for ($ci = 0; $ci < $totalChunks; $ci++) {
                $cp = $chunkDir . '/chunk_' . str_pad($ci, 5, '0', STR_PAD_LEFT);
                if (file_exists($cp)) {
                    fwrite($fp, file_get_contents($cp));
                    @unlink($cp);
                }
            }
            fclose($fp);
            @rmdir($chunkDir);
            
            // MIME 검증
            if (function_exists('finfo_open') && is_file($destPath)) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $realMime = finfo_file($finfo, $destPath);
                finfo_close($finfo);
                $blockedMimes = [
                    'application/x-httpd-php', 'application/x-php', 'text/x-php',
                    'application/x-shellscript', 'text/x-shellscript',
                ];
                if (in_array($realMime, $blockedMimes)) {
                    @unlink($destPath);
                    $result = ['success' => false, 'error' => __('api_err_dangerous_file', '보안상 허용되지 않는 파일입니다.')]; break;
                }
            }
            
            // EXIF orientation 보정
            fixImageOrientation($destPath);
            
            // 게시글에 첨부파일 정보 추가
            $posts = $db->load('board_posts') ?: [];
            foreach ($posts as &$p) {
                if (($p['id'] ?? 0) == $postId) {
                    if (!isset($p['attachments'])) $p['attachments'] = [];
                    $p['attachments'][] = [
                        'name' => $origName,
                        'file' => $safeName,
                        'size' => filesize($destPath),
                        'uploaded_at' => date('Y-m-d H:i:s'),
                    ];
                    break;
                }
            }
            unset($p);
            $db->save('board_posts', $posts);
            $result = ['success' => true, 'complete' => true, 'name' => $origName, 'file' => $safeName, 'size' => filesize($destPath)];
            break;

        case 'board_post_download':
            // 첨부파일 다운로드
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $boardId = (int)($input['board_id'] ?? 0);
            $postId = (int)($input['post_id'] ?? 0);
            $fileName = basename($input['file'] ?? '');
            
            // 게시판 읽기 권한 체크
            { $boards = $db->load('boards') ?: [];
              $board = null;
              foreach ($boards as $b) { if (($b['id'] ?? 0) == $boardId) { $board = $b; break; } }
              if ($board) {
                  $permRead = $board['perm_read'] ?? 'all';
                  $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
                  if (($permRead === 'none' || $permRead === 'admin') && !$isAdmin) {
                      $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
                  }
              }
            }
            
            $filePath = __DIR__ . '/data/board_files/' . $boardId . '/' . $postId . '/' . $fileName;
            if (!file_exists($filePath)) {
                $result = ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')]; break;
            }
            
            // 원본 파일명 찾기
            $posts = $db->load('board_posts') ?: [];
            $origName = $fileName;
            foreach ($posts as $p) {
                if (($p['id'] ?? 0) == $postId) {
                    foreach (($p['attachments'] ?? []) as $att) {
                        if ($att['file'] === $fileName) { $origName = $att['name']; break; }
                    }
                    break;
                }
            }
            
            header('Content-Type: application/octet-stream');
            $origNameSafe = preg_replace('/[^\x20-\x7E]|["\\]/', '_', $origName);
            $origNameEncoded = rawurlencode($origName);
            header("Content-Disposition: attachment; filename=\"{$origNameSafe}\"; filename*=UTF-8''{$origNameEncoded}");
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;

        case 'board_attach_from_storage':
            // 스토리지 파일을 게시판 첨부로 복사
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $user = $auth->getUser();
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
            $boardId = (int)($input['board_id'] ?? 0);
            $postId = (int)($input['post_id'] ?? 0);
            $storageId = (int)($input['storage_id'] ?? 0);
            $srcFilePath = $input['file_path'] ?? '';
            $srcFileName = $input['file_name'] ?? '';
            
            if (!$boardId || !$storageId || !$srcFilePath) {
                $result = ['success' => false, 'error' => __('api_err_missing_params', '필수 매개변수가 누락되었습니다.')]; break;
            }
            
            // 확장자 검증
            $boards = $db->load('boards') ?: [];
            $board = null;
            foreach ($boards as $b) { if (($b['id'] ?? 0) == $boardId) { $board = $b; break; } }
            
            // 서버 실행 확장자만 절대 차단
            $serverExecExts2 = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps',
                'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'htaccess', 'user.ini'];
            $fileExt = strtolower(pathinfo($srcFileName, PATHINFO_EXTENSION));
            if (in_array($fileExt, $serverExecExts2)) {
                $result = ['success' => false, 'error' => __('api_err_dangerous_ext', '보안상 허용되지 않는 파일 형식입니다.')]; break;
            }
            // 이중 확장자 검사 (evil.php.jpg)
            $nameParts = explode('.', strtolower($srcFileName));
            if (count($nameParts) > 2) {
                for ($i = 1; $i < count($nameParts) - 1; $i++) {
                    if (in_array($nameParts[$i], $serverExecExts2)) {
                        $result = ['success' => false, 'error' => __('api_err_dangerous_filename', '보안상 허용되지 않는 파일명입니다.')]; break 2;
                    }
                }
            }
            
            $allowedExt = trim($board['allowed_ext'] ?? '');
            if (!empty($allowedExt)) {
                $exts = array_map('trim', array_map('strtolower', explode(',', $allowedExt)));
                if (!in_array($fileExt, $exts)) {
                    $result = ['success' => false, 'error' => __('api_err_ext_not_allowed', '허용되지 않는 파일 형식입니다.')]; break;
                }
            }
            
            // 스토리지에서 파일 경로 확인
            $basePath = $storage->getRealPath($storageId);
            
            if (!$basePath) {
                // 원격 스토리지인 경우 어댑터로 처리
                if ($fileManager->isRemoteStorage($storageId)) {
                    $adapter = $fileManager->getAdapter($storageId);
                    if (!$adapter) {
                        $result = ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')]; break;
                    }
                    // 원격 파일 읽기 → 임시 파일 → 복사
                    $tmpFile = tempnam(sys_get_temp_dir(), 'board_att_');
                    $remoteContent = $adapter->read($srcFilePath);
                    if ($remoteContent === false) {
                        @unlink($tmpFile);
                        $result = ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')]; break;
                    }
                    file_put_contents($tmpFile, $remoteContent);
                    
                    $targetPostId = $postId ?: 'temp_' . session_id();
                    $uploadDir = __DIR__ . '/data/board_files/' . $boardId . '/' . $targetPostId;
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    
                    $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9가-힣._-]/u', '_', $srcFileName);
                    $destPath = $uploadDir . '/' . $safeName;
                    
                    rename($tmpFile, $destPath);
                    $fileSize = filesize($destPath);
                    
                    if ($postId) {
                        $posts = $db->load('board_posts') ?: [];
                        foreach ($posts as &$p) {
                            if (($p['id'] ?? 0) == $postId) {
                                if (!isset($p['attachments'])) $p['attachments'] = [];
                                $p['attachments'][] = [
                                    'name' => $srcFileName,
                                    'file' => $safeName,
                                    'size' => $fileSize,
                                    'uploaded_at' => date('Y-m-d H:i:s'),
                                ];
                                break;
                            }
                        }
                        unset($p);
                        $db->save('board_posts', $posts);
                    }
                    
                    $result = ['success' => true, 'file' => $safeName, 'name' => $srcFileName, 'size' => $fileSize];
                    break;
                }
                $result = ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')]; break;
            }
            
            $srcFull = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $srcFilePath);
            // 보안: 경로 탈출 방지
            $realBase = realpath($basePath);
            $realSrc = realpath($srcFull);
            // 보안: isSubPath로 정확한 하위 경로 검증 (strpos prefix 확장 공격 방어)
            if (!$realSrc || !$realBase || !\isSubPath($realSrc, $realBase)) {
                $result = ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')]; break;
            }
            if (!file_exists($realSrc) || is_dir($realSrc)) {
                $result = ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')]; break;
            }
            
            // 게시판 파일 디렉토리에 복사
            $targetPostId = $postId ?: 'temp_' . session_id();
            $uploadDir = __DIR__ . '/data/board_files/' . $boardId . '/' . $targetPostId;
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9가-힣._-]/u', '_', $srcFileName);
            $destPath = $uploadDir . '/' . $safeName;
            
            if (!copy($realSrc, $destPath)) {
                $result = ['success' => false, 'error' => 'Copy failed']; break;
            }
            
            $fileSize = filesize($destPath);
            
            // postId가 있으면 첨부파일 DB에 등록
            if ($postId) {
                $posts = $db->load('board_posts') ?: [];
                foreach ($posts as &$p) {
                    if (($p['id'] ?? 0) == $postId) {
                        if (!isset($p['attachments'])) $p['attachments'] = [];
                        $p['attachments'][] = [
                            'name' => $srcFileName,
                            'file' => $safeName,
                            'size' => $fileSize,
                            'uploaded_at' => date('Y-m-d H:i:s'),
                        ];
                        break;
                    }
                }
                unset($p);
                $db->save('board_posts', $posts);
            }
            
            $result = ['success' => true, 'file' => $safeName, 'name' => $srcFileName, 'size' => $fileSize];
            break;

        case 'board_move_temp_files':
            // 새 글 저장 후 스토리지에서 첨부한 임시 파일을 실제 postId로 이동
            $auth->requireLogin();
            $boardId = (int)($input['board_id'] ?? 0);
            $postId = (int)($input['post_id'] ?? 0);
            $files = $input['files'] ?? [];
            if (!$boardId || !$postId || empty($files)) {
                $result = ['success' => false, 'error' => 'Missing params']; break;
            }
            
            $tempDir = __DIR__ . '/data/board_files/' . $boardId . '/temp_' . session_id();
            $realDir = __DIR__ . '/data/board_files/' . $boardId . '/' . $postId;
            if (!is_dir($realDir)) mkdir($realDir, 0755, true);
            
            $posts = $db->load('board_posts') ?: [];
            foreach ($files as $f) {
                $safeName = basename($f['file'] ?? '');
                if (!$safeName) continue;
                $src = $tempDir . '/' . $safeName;
                $dst = $realDir . '/' . $safeName;
                if (file_exists($src)) {
                    rename($src, $dst);
                    // DB에 첨부파일 등록
                    foreach ($posts as &$p) {
                        if (($p['id'] ?? 0) == $postId) {
                            if (!isset($p['attachments'])) $p['attachments'] = [];
                            $p['attachments'][] = [
                                'name' => $f['name'] ?? $safeName,
                                'file' => $safeName,
                                'size' => $f['size'] ?? filesize($dst),
                                'uploaded_at' => date('Y-m-d H:i:s'),
                            ];
                            break;
                        }
                    }
                    unset($p);
                }
            }
            $db->save('board_posts', $posts);
            // 임시 디렉토리 정리
            if (is_dir($tempDir)) @rmdir($tempDir);
            $result = ['success' => true];
            break;

        case 'board_post_delete_file':
            // 첨부파일 삭제
            $auth->requireLogin();
            $user = $auth->getUser();
            $postId = (int)($input['post_id'] ?? 0);
            $fileName = $input['file'] ?? '';
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
            
            $posts = $db->load('board_posts') ?: [];
            foreach ($posts as &$p) {
                if (($p['id'] ?? 0) == $postId) {
                    if ($p['author_id'] != $user['id'] && !$isAdmin) {
                        $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break 2;
                    }
                    $boardId = $p['board_id'] ?? 0;
                    $p['attachments'] = array_values(array_filter($p['attachments'] ?? [], function($a) use ($fileName) {
                        return $a['file'] !== $fileName;
                    }));
                    // 파일 삭제
                    $filePath = __DIR__ . '/data/board_files/' . $boardId . '/' . $postId . '/' . basename($fileName);
                    if (file_exists($filePath)) unlink($filePath);
                    break;
                }
            }
            unset($p);
            $db->save('board_posts', $posts);
            $result = ['success' => true];
            break;

        case 'board_mark_read':
            // 공지 읽음 처리
            $auth->requireLogin();
            $userId = $auth->getUserId();
            $postId = (int)($input['post_id'] ?? 0);
            
            $db->atomicUpdate('board_posts', function($posts) use ($postId, $userId) {
                foreach ($posts as &$p) {
                    if (($p['id'] ?? 0) == $postId) {
                        if (!isset($p['read_by'])) $p['read_by'] = [];
                        if (!in_array($userId, $p['read_by'])) {
                            $p['read_by'][] = $userId;
                        }
                        break;
                    }
                }
                unset($p);
                return $posts;
            });
            $result = ['success' => true];
            break;

        case 'board_unread_count':
            // 안 읽은 공지 수
            $auth->requireLogin();
            $userId = $auth->getUserId();
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
            
            $boards = $db->load('boards') ?: [];
            $posts = $db->load('board_posts') ?: [];
            
            $counts = []; // board_id => unread count
            foreach ($posts as $p) {
                if (empty($p['is_notice'])) continue;
                $bid = $p['board_id'] ?? 0;
                // 권한 확인
                $board = null;
                foreach ($boards as $b) { if (($b['id'] ?? 0) == $bid) { $board = $b; break; } }
                if (!$board || (!($board['enabled'] ?? true))) continue;
                $pl = $board['perm_list'] ?? 'all';
                if (($pl === 'admin' || $pl === 'none') && !$isAdmin) continue;
                
                $readBy = $p['read_by'] ?? [];
                if (!in_array($userId, $readBy)) {
                    $counts[$bid] = ($counts[$bid] ?? 0) + 1;
                }
            }
            
            $result = ['success' => true, 'counts' => $counts];
            break;

        case 'board_post_delete':
            // 게시글 삭제 (작성자 본인 또는 관리자)
            $auth->requireLogin();
            $user = $auth->getUser();
            $postId = (int)($input['post_id'] ?? 0);
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();

            // 디렉터리 재귀 삭제 헬퍼 (게시글 첨부 / 댓글 첨부 모두에 사용)
            $rmDirTree = function($dir) {
                if (!is_dir($dir)) return;
                try {
                    $iter = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($iter as $f) {
                        if ($f->isDir()) @rmdir($f->getPathname());
                        else @unlink($f->getPathname());
                    }
                    @rmdir($dir);
                } catch (\Throwable $_e) { /* 첨부 정리 실패는 삭제 자체를 되돌리지 않음 */ }
            };

            $posts = $db->load('board_posts') ?: [];
            $target = null;
            foreach ($posts as $p) { if (($p['id'] ?? 0) == $postId) { $target = $p; break; } }
            if (!$target) {
                $result = ['success' => false, 'error' => __('api_err_post_not_found', '게시글을 찾을 수 없습니다.')];
                break;
            }
            // ★ 권한 없으면 조용히 무시하지 말고 명확히 알린다 (이전에는 success=true를 돌려줘 삭제된 것처럼 보였음)
            if (($target['author_id'] ?? null) != $user['id'] && !$isAdmin) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                break;
            }

            $deletedBoardId = (int)($target['board_id'] ?? 0);
            $posts = array_values(array_filter($posts, fn($p) => ($p['id'] ?? 0) != $postId));
            $db->save('board_posts', $posts);

            // 댓글 삭제 — 지워질 댓글 id를 먼저 모아 첨부 폴더까지 정리한다
            $comments = $db->load('board_comments') ?: [];
            $delCommentIds = [];
            foreach ($comments as $c) {
                if (($c['post_id'] ?? 0) == $postId) $delCommentIds[] = (int)($c['id'] ?? 0);
            }
            $comments = array_values(array_filter($comments, fn($c) => ($c['post_id'] ?? 0) != $postId));
            $db->save('board_comments', $comments);

            // ★ 첨부 정리: 게시글 첨부와 댓글 첨부는 저장 경로가 '형제' 관계라 각각 지워야 한다.
            //     게시글 → data/board_files/{board}/{post}
            //     댓글   → data/board_files/{board}/comments/{comment}
            //   (이전에는 게시글 폴더만 지워 댓글 첨부파일이 고아로 남았음)
            $rmDirTree(__DIR__ . '/data/board_files/' . $deletedBoardId . '/' . $postId);
            foreach ($delCommentIds as $cid) {
                if ($cid > 0) $rmDirTree(__DIR__ . '/data/board_files/' . $deletedBoardId . '/comments/' . $cid);
            }

            $result = ['success' => true];
            break;

        case 'board_posts_delete':
            // ★ 게시글 일괄 삭제 (게시판관리 — 선택 삭제)
            //   단건 board_post_delete와 동일한 권한/정리 규칙을 쓰되, board_posts JSON을
            //   호출마다 전체 재작성하지 않도록 한 번의 load/save로 처리한다.
            //   (단건 액션을 N번 호출하면 전체 파일을 N번 다시 쓰게 되어 느리고 위험)
            $auth->requireLogin();
            $user = $auth->getUser();
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();

            $rawIds = $input['post_ids'] ?? [];
            if (!is_array($rawIds)) {
                $result = ['success' => false, 'error' => 'invalid post_ids'];
                break;
            }
            $ids = [];
            foreach ($rawIds as $v) {
                $n = (int)$v;
                if ($n > 0) $ids[$n] = true;   // 중복 제거 + 정수화
            }
            if (!$ids) {
                $result = ['success' => false, 'error' => 'no posts selected'];
                break;
            }
            if (count($ids) > 500) {
                $result = ['success' => false, 'error' => 'too many posts (max 500)'];
                break;
            }

            $posts = $db->load('board_posts') ?: [];
            $scopeBoardId = (int)($input['board_id'] ?? 0);   // 0이면 범위 제한 없음
            $deleted = [];   // postId => boardId
            $kept    = [];
            $skipped = 0;    // 권한 없음/공지글/다른 게시판으로 건너뛴 수
            foreach ($posts as $p) {
                $pid = (int)($p['id'] ?? 0);
                if ($pid <= 0 || !isset($ids[$pid])) { $kept[] = $p; continue; }

                // 범위 가드: 요청한 게시판의 글만 삭제 (오래된 화면에서 다른 게시판 ID가 섞여 오는 것 방지)
                if ($scopeBoardId > 0 && (int)($p['board_id'] ?? 0) !== $scopeBoardId) { $kept[] = $p; $skipped++; continue; }

                // 권한: 작성자 본인 또는 관리자만 (단건 삭제와 동일 규칙)
                if (($p['author_id'] ?? null) != $user['id'] && !$isAdmin) { $kept[] = $p; $skipped++; continue; }

                // 공지글은 일괄 삭제 대상에서 제외 (실수 방지 — 클라이언트도 체크박스를 주지 않음).
                // 공지 삭제가 필요하면 글 상세에서 개별 삭제로 진행.
                if (!empty($p['is_notice']) || !empty($p['is_pinned'])) { $kept[] = $p; $skipped++; continue; }

                $deleted[$pid] = (int)($p['board_id'] ?? 0);
            }

            if ($deleted) {
                $db->save('board_posts', array_values($kept));

                // 삭제된 글의 댓글 정리 — 지워질 댓글 id를 먼저 모아 첨부까지 함께 정리
                $comments = $db->load('board_comments') ?: [];
                $beforeCnt = count($comments);
                $delCommentIds = [];   // commentId => boardId
                foreach ($comments as $c) {
                    $pid = (int)($c['post_id'] ?? 0);
                    if (isset($deleted[$pid])) $delCommentIds[(int)($c['id'] ?? 0)] = $deleted[$pid];
                }
                $comments = array_values(array_filter($comments, function($c) use ($deleted) {
                    return !isset($deleted[(int)($c['post_id'] ?? 0)]);
                }));
                if (count($comments) !== $beforeCnt) $db->save('board_comments', $comments);

                // 첨부파일 폴더 정리 (재귀)
                $rmDirTree = function($dir) {
                    if (!is_dir($dir)) return;
                    try {
                        $iter = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                            \RecursiveIteratorIterator::CHILD_FIRST
                        );
                        foreach ($iter as $f) {
                            if ($f->isDir()) @rmdir($f->getPathname());
                            else @unlink($f->getPathname());
                        }
                        @rmdir($dir);
                    } catch (\Throwable $_e) {
                        // 첨부 정리 실패는 삭제 자체를 되돌리지 않음 (글/댓글은 이미 제거됨)
                    }
                };
                // 게시글 첨부
                foreach ($deleted as $pid => $bid) {
                    $rmDirTree(__DIR__ . '/data/board_files/' . $bid . '/' . $pid);
                }
                // ★ 댓글 첨부는 경로가 형제 관계(data/board_files/{board}/comments/{comment})라 따로 지워야 한다
                foreach ($delCommentIds as $cid => $bid) {
                    if ($cid > 0) $rmDirTree(__DIR__ . '/data/board_files/' . $bid . '/comments/' . $cid);
                }
            }

            $result = ['success' => true, 'deleted' => count($deleted), 'skipped' => $skipped];
            break;

        case 'board_comment_save':
            // 댓글/대댓글 작성 (첨부파일 지원)
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $user = $auth->getUser();
            $postId = (int)($input['post_id'] ?? 0);
            $boardId = (int)($input['board_id'] ?? 0);
            $parentId = (int)($input['parent_id'] ?? 0);
            // sanitize는 파일 URL 치환 후 실행 (data: URL이 64KB 초과 시 삭제 방지)
            $content = trim($input['content'] ?? '');
            
            // 첨부파일이 있으면 텍스트 없어도 허용
            $hasFiles = !empty($_FILES['comment_files']['name'][0]) || !empty($input['has_pending_files']);
            if (empty($content) && !$hasFiles) { $result = ['success' => false, 'error' => __('api_err_content_required', '내용을 입력하세요.')]; break; }
            
            $comments = $db->load('board_comments') ?: [];
            $maxId = 0;
            foreach ($comments as $c) { if (($c['id'] ?? 0) > $maxId) $maxId = $c['id']; }
            $newId = $maxId + 1;
            
            // 첨부파일 저장 (게시판 설정의 허용 확장자 따름)
            $attachments = [];
            if ($hasFiles) {
                // 게시판 허용 확장자 확인
                $boards = $db->load('boards') ?: [];
                $board = null;
                foreach ($boards as $b) { if (($b['id'] ?? 0) == $boardId) { $board = $b; break; } }
                $boardAllowedExt = trim($board['allowed_ext'] ?? '');
                $allowedExts = !empty($boardAllowedExt) 
                    ? array_map('trim', array_map('strtolower', explode(',', $boardAllowedExt)))
                    : []; // 비어있으면 모두 허용
                
                $uploadDir = __DIR__ . '/data/board_files/' . $boardId . '/comments/' . $newId;
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                
                foreach ($_FILES['comment_files']['name'] as $idx => $name) {
                    if ($_FILES['comment_files']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    // 허용 확장자가 설정되어 있으면 체크, 비어있으면 모두 허용
                    if (!empty($allowedExts) && !in_array($ext, $allowedExts)) continue;
                    
                    // 서버 실행 확장자만 절대 차단
                    $commentServerExecExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps',
                        'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'htaccess', 'user.ini'];
                    if (in_array($ext, $commentServerExecExts)) continue;
                    // 이중 확장자 차단
                    $commentNameParts = explode('.', strtolower($name));
                    $skipFile = false;
                    if (count($commentNameParts) > 2) {
                        for ($di = 1; $di < count($commentNameParts) - 1; $di++) {
                            if (in_array($commentNameParts[$di], $commentServerExecExts)) { $skipFile = true; break; }
                        }
                    }
                    if ($skipFile) continue;
                    
                    // MIME 타입 검증 (실행 가능 파일 차단)
                    $commentTmpFile = $_FILES['comment_files']['tmp_name'][$idx];
                    if (function_exists('finfo_open') && !empty($commentTmpFile) && is_uploaded_file($commentTmpFile)) {
                        $commentFinfo = finfo_open(FILEINFO_MIME_TYPE);
                        $commentRealMime = finfo_file($commentFinfo, $commentTmpFile);
                        finfo_close($commentFinfo);
                        $commentBlockedMimes = [
                            'application/x-httpd-php', 'application/x-php', 'text/x-php',
                            'application/x-shellscript', 'text/x-shellscript',
                        ];
                        if (in_array($commentRealMime, $commentBlockedMimes)) continue;
                    }
                    
                    $safeName = basename(preg_replace('/[^a-zA-Z0-9가-힣._\-]/', '_', $name));
                    $destPath = $uploadDir . '/' . $safeName;
                    // 동일 이름 존재 시 번호 추가
                    if (file_exists($destPath)) {
                        $base = pathinfo($safeName, PATHINFO_FILENAME);
                        $safeName = $base . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
                        $destPath = $uploadDir . '/' . $safeName;
                    }
                    if (move_uploaded_file($_FILES['comment_files']['tmp_name'][$idx], $destPath)) {
                        // EXIF orientation 보정 (사진 90도 회전 현상 수정)
                        fixImageOrientation($destPath);
                        $attachments[] = [
                            'name' => $name,
                            'file' => $safeName,
                            'size' => filesize($destPath),
                            'type' => $ext,
                        ];
                        // content 내 data URL을 실제 URL로 치환
                        $fileUrl = 'api.php?action=board_comment_download&amp;board_id=' . $boardId . '&amp;comment_id=' . $newId . '&amp;file=' . urlencode($safeName);
                        $fileUrlRaw = 'api.php?action=board_comment_download&board_id=' . $boardId . '&comment_id=' . $newId . '&file=' . urlencode($safeName);
                        $escapedName = htmlspecialchars($name, ENT_QUOTES);
                        // data-pending-name 속성이 있는 태그에서 src의 data URL 치환 (속성 순서 무관)
                        $content = preg_replace_callback(
                            '/<(img|video|source)([^>]*?)data-pending-name="' . preg_quote($escapedName, '/') . '"([^>]*?)>/i',
                            function($m) use ($fileUrlRaw) {
                                $tag = $m[1];
                                $before = $m[2];
                                $after = $m[3];
                                $attrs = $before . $after;
                                // data: src 치환
                                $attrs = preg_replace('/src="data:[^"]*"/', 'src="' . $fileUrlRaw . '"', $attrs);
                                // data-pending-name 제거
                                $attrs = preg_replace('/\s*data-pending-name="[^"]*"/', '', $attrs);
                                return '<' . $tag . $attrs . '>';
                            },
                            $content
                        );
                    }
                }
            }
            
            // 남아있는 data-pending-name 속성 정리
            $content = preg_replace('/\s*data-pending-name="[^"]*"/', '', $content);
            
            // 동영상 플레이스홀더 img를 video 태그로 변환
            // data-video-pending: 새 파일 (서버 URL로 변환)
            foreach ($attachments as $att) {
                $vExt = strtolower(pathinfo($att['file'], PATHINFO_EXTENSION));
                if (in_array($vExt, ['mp4','webm','ogg','mov','avi','mkv'])) {
                    $vUrl = 'api.php?action=board_comment_download&board_id=' . $boardId . '&comment_id=' . $newId . '&file=' . urlencode($att['file']);
                    $escapedVName = htmlspecialchars($att['name'], ENT_QUOTES);
                    $content = preg_replace_callback(
                        '/<img([^>]*?)data-video-pending="' . preg_quote($escapedVName, '/') . '"([^>]*?)\/?>/i',
                        function($m) use ($vUrl) {
                            $attrs = $m[1] . $m[2];
                            $style = 'max-width:100%;border-radius:6px;';
                            if (preg_match('/style="([^"]*)"/', $attrs, $sm)) {
                                $parts = [];
                                if (preg_match('/width\s*:\s*[^;]+/', $sm[1], $wm)) $parts[] = $wm[0];
                                if (preg_match('/height\s*:\s*[^;]+/', $sm[1], $hm)) $parts[] = $hm[0];
                                if ($parts) $style = implode(';', $parts) . ';max-width:100%;border-radius:6px;';
                            }
                            $wAttr = $hAttr = '';
                            if (preg_match('/\bwidth="(\d+)"/', $attrs, $wm2)) $wAttr = ' width="' . $wm2[1] . '"';
                            if (preg_match('/\bheight="(\d+)"/', $attrs, $hm2)) $hAttr = ' height="' . $hm2[1] . '"';
                            if (!preg_match('#^(https?://|api\.php\?)#i', $vUrl)) $vUrl = '';
                            return '<video controls playsinline src="' . $vUrl . '"' . $wAttr . $hAttr . ' style="' . $style . '"></video>';
                        },
                        $content
                    );
                }
            }
            // 남은 data-video-pending 플레이스홀더 정리
            $content = preg_replace('/<img[^>]*data-video-pending="[^"]*"[^>]*\/?>/i', '', $content);
            
            // data-video-src 플레이스홀더도 video로 변환 (기존 파일)
            $content = convertVideoSrcPlaceholders($content);
            
            // 파일 URL 치환 완료 후 sanitize 실행
            $content = sanitizeHtml($content);
            
            $comments[] = [
                'id' => $newId,
                'board_id' => $boardId,
                'post_id' => $postId,
                'parent_id' => $parentId ?: null,
                'author_id' => $user['id'],
                'author_name' => $user['display_name'] ?? $user['username'] ?? '',
                'content' => $content,
                'attachments' => $attachments ?: null,
                'is_deleted' => false,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $db->save('board_comments', $comments);
            
            // === 댓글 알림 생성 ===
            $notifications = $db->load('board_notifications') ?: [];
            $commenterName = $user['display_name'] ?? $user['username'] ?? '사용자';
            $commenterId = $user['id'];
            
            // 게시글 정보 조회 (작성자 확인용)
            $posts = $db->load('board_posts') ?: [];
            $post = null;
            foreach ($posts as $p) { if (($p['id'] ?? 0) == $postId) { $post = $p; break; } }
            
            // 게시판 이름 조회
            if (!isset($boards)) $boards = $db->load('boards') ?: [];
            $boardName = '';
            foreach ($boards as $b) { if (($b['id'] ?? 0) == $boardId) { $boardName = $b['name'] ?? ''; break; } }
            
            $notifyTargets = []; // [user_id => reason]
            
            // 1. 게시글 작성자에게 알림 (자기 글에 자기가 댓글 달면 제외)
            if ($post && !empty($post['author_id']) && $post['author_id'] != $commenterId) {
                $notifyTargets[$post['author_id']] = 'post_comment';
            }
            
            // 2. 대댓글인 경우: 부모 댓글 작성자에게 알림 (자기 댓글에 자기가 답글 달면 제외)
            if ($parentId) {
                foreach ($comments as $c) {
                    if (($c['id'] ?? 0) == $parentId && !empty($c['author_id']) && $c['author_id'] != $commenterId) {
                        $notifyTargets[$c['author_id']] = 'reply';
                        break;
                    }
                }
            }
            
            // 알림 생성
            if (!empty($notifyTargets)) {
                $maxNId = 0;
                foreach ($notifications as $n) { if (($n['id'] ?? 0) > $maxNId) $maxNId = $n['id']; }
                $postTitle = $post ? mb_substr($post['title'] ?? '', 0, 30) : '';
                
                // 댓글 내용 미리보기 (HTML 태그 제거, 30자)
                $contentPreview = mb_substr(strip_tags($content), 0, 30);
                if (mb_strlen(strip_tags($content)) > 30) $contentPreview .= '…';
                
                // 대댓글인 경우 부모 댓글 내용
                $parentPreview = '';
                if ($parentId) {
                    foreach ($comments as $c) {
                        if (($c['id'] ?? 0) == $parentId) {
                            $parentPreview = mb_substr(strip_tags($c['content'] ?? ''), 0, 30);
                            if (mb_strlen(strip_tags($c['content'] ?? '')) > 30) $parentPreview .= '…';
                            break;
                        }
                    }
                }
                
                foreach ($notifyTargets as $targetUserId => $reason) {
                    $maxNId++;
                        
                    $notifications[] = [
                        'id' => $maxNId,
                        'user_id' => $targetUserId,
                        'type' => 'board_comment',
                        'reason' => $reason,
                        'board_id' => $boardId,
                        'post_id' => $postId,
                        'comment_id' => $newId,
                        'from_user_id' => $commenterId,
                        'from_user_name' => $commenterName,
                        'content_preview' => $contentPreview,
                        'parent_preview' => $parentPreview,
                        'board_name' => $boardName,
                        'post_title' => $postTitle,
                        'is_read' => false,
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                }
                
                // 30일 이상 된 알림 자동 삭제
                $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
                $notifications = array_values(array_filter($notifications, function($n) use ($cutoff) {
                    return ($n['created_at'] ?? '9999') > $cutoff;
                }));
                
                $db->save('board_notifications', $notifications);
            }
            
            $result = ['success' => true, 'comment_id' => $newId];
            break;

        case 'board_comment_upload_chunk':
            // 댓글 첨부파일 청크 업로드 (대용량 파일 지원)
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $user = $auth->getUser();
            $commentId = (int)($_POST['comment_id'] ?? 0);
            $boardId = (int)($_POST['board_id'] ?? 0);
            $filename = basename($_POST['filename'] ?? '');
            $chunkIndex = (int)($_POST['chunk_index'] ?? 0);
            $totalChunks = (int)($_POST['total_chunks'] ?? 1);
            $uploadId = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['upload_id'] ?? '');
            
            if (!$commentId || !$boardId || !$filename || !$uploadId) {
                $result = ['success' => false, 'error' => __('api_err_missing_params', '필수 매개변수가 누락되었습니다.')]; break;
            }
            if (empty($_FILES['chunk'])) {
                $result = ['success' => false, 'error' => __('api_err_no_file', '파일이 없습니다.')]; break;
            }
            
            // 댓글 소유자 또는 관리자만 파일 업로드 허용
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
            $comments = $db->load('board_comments') ?: [];
            $cmtOwnerOk = false;
            foreach ($comments as $cc) {
                if (($cc['id'] ?? 0) == $commentId) {
                    if ((string)($cc['author_id'] ?? '') === (string)$user['id'] || $isAdmin) $cmtOwnerOk = true;
                    break;
                }
            }
            if (!$cmtOwnerOk) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
            }
            
            // 첫 청크에서 확장자 검증
            if ($chunkIndex === 0) {
                $serverExecExts = ['php','phtml','php3','php4','php5','php7','phar','phps','jsp','jspx','asp','aspx','cgi','htaccess','user.ini'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, $serverExecExts)) {
                    $result = ['success' => false, 'error' => __('api_err_dangerous_ext', '보안상 허용되지 않는 파일 형식입니다.')]; break;
                }
                $boards = $db->load('boards') ?: [];
                $board = null;
                foreach ($boards as $b) { if (($b['id'] ?? 0) == $boardId) { $board = $b; break; } }
                $allowedExt = trim($board['allowed_ext'] ?? '');
                if (!empty($allowedExt)) {
                    $exts = array_map('trim', array_map('strtolower', explode(',', $allowedExt)));
                    if (!in_array($ext, $exts)) {
                        $result = ['success' => false, 'error' => __('api_err_ext_not_allowed', '허용되지 않는 파일 형식입니다.')]; break;
                    }
                }
            }
            
            // 임시 청크 저장
            $chunkDir = __DIR__ . '/data/board_chunks/cmt_' . $uploadId;
            if (!is_dir($chunkDir)) mkdir($chunkDir, 0755, true);
            $chunkPath = $chunkDir . '/chunk_' . str_pad($chunkIndex, 5, '0', STR_PAD_LEFT);
            if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath)) {
                $result = ['success' => false, 'error' => __('api_err_upload_failed', '업로드에 실패했습니다.')]; break;
            }
            
            if ($chunkIndex < $totalChunks - 1) {
                $result = ['success' => true, 'chunk' => $chunkIndex]; break;
            }
            
            // === 마지막 청크: 병합 ===
            $uploadDir = __DIR__ . '/data/board_files/' . $boardId . '/comments/' . $commentId;
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $safeName = basename(preg_replace('/[^a-zA-Z0-9가-힣._\-]/u', '_', $filename));
            if (file_exists($uploadDir . '/' . $safeName)) {
                $base = pathinfo($safeName, PATHINFO_FILENAME);
                $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
                $safeName = $base . '_' . time() . '.' . $ext;
            }
            $destPath = $uploadDir . '/' . $safeName;
            
            $fp = fopen($destPath, 'wb');
            if (!$fp) { $result = ['success' => false, 'error' => __('api_err_upload_failed', '업로드에 실패했습니다.')]; break; }
            for ($ci = 0; $ci < $totalChunks; $ci++) {
                $cp = $chunkDir . '/chunk_' . str_pad($ci, 5, '0', STR_PAD_LEFT);
                if (file_exists($cp)) { fwrite($fp, file_get_contents($cp)); @unlink($cp); }
            }
            fclose($fp);
            @rmdir($chunkDir);
            
            // MIME 검증
            if (function_exists('finfo_open') && is_file($destPath)) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $rm = finfo_file($fi, $destPath);
                finfo_close($fi);
                if (in_array($rm, ['application/x-httpd-php','application/x-php','text/x-php','application/x-shellscript','text/x-shellscript'])) {
                    @unlink($destPath);
                    $result = ['success' => false, 'error' => __('api_err_dangerous_file', '보안상 허용되지 않는 파일입니다.')]; break;
                }
            }
            
            fixImageOrientation($destPath);
            
            // 댓글에 첨부파일 추가
            $comments = $db->load('board_comments') ?: [];
            foreach ($comments as &$c) {
                if (($c['id'] ?? 0) == $commentId) {
                    if (!isset($c['attachments']) || !is_array($c['attachments'])) $c['attachments'] = [];
                    $c['attachments'][] = [
                        'name' => $filename,
                        'file' => $safeName,
                        'size' => filesize($destPath),
                        'type' => strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
                    ];
                    
                    // content 내 data URL을 실제 URL로 치환
                    $cmtContent = $c['content'] ?? '';
                    $fileUrlRaw = 'api.php?action=board_comment_download&board_id=' . $boardId . '&comment_id=' . $commentId . '&file=' . urlencode($safeName);
                    $escapedName = htmlspecialchars($filename, ENT_QUOTES);
                    $cmtContent = preg_replace_callback(
                        '/<(img|video|source)([^>]*?)data-pending-name="' . preg_quote($escapedName, '/') . '"([^>]*?)>/i',
                        function($m) use ($fileUrlRaw) {
                            $attrs = $m[2] . $m[3];
                            $attrs = preg_replace('/src="data:[^"]*"/', 'src="' . $fileUrlRaw . '"', $attrs);
                            $attrs = preg_replace('/\s*data-pending-name="[^"]*"/', '', $attrs);
                            return '<' . $m[1] . $attrs . '>';
                        },
                        $cmtContent
                    );
                    $c['content'] = sanitizeHtml($cmtContent);
                    break;
                }
            }
            unset($c);
            $db->save('board_comments', $comments);
            $result = ['success' => true, 'complete' => true, 'name' => $filename, 'file' => $safeName, 'size' => filesize($destPath)];
            break;

        case 'board_comment_edit':
            // 댓글 수정
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $user = $auth->getUser();
            $commentId = (int)($input['comment_id'] ?? 0);
            // sanitize는 파일 URL 치환 후 실행
            $content = trim($input['content'] ?? '');
            // 빈 내용 체크 (태그 제거 후, 단 img/video 태그 또는 첨부파일이 있으면 허용)
            $hasMedia = preg_match('/<(img|video)\b/i', $content);
            $hasEditFiles = !empty($_FILES['comment_files']['name'][0]);
            if (empty(strip_tags($content)) && !$hasMedia && !$hasEditFiles) { $result = ['success' => false, 'error' => __('api_err_content_required', '내용을 입력하세요.')]; break; }
            
            $comments = $db->load('board_comments') ?: [];
            $found = false;
            $permError = false;
            foreach ($comments as &$c) {
                if ((int)$c['id'] === $commentId) {
                    $isAdmin = in_array($user['role'] ?? '', ['admin', 'sub_admin']);
                    if ((string)$c['author_id'] !== (string)$user['id'] && !$isAdmin) {
                        $permError = true;
                        break;
                    }
                    $c['content'] = $content;
                    
                    $boardId = (int)($c['board_id'] ?? 0);
                    
                    // 새 첨부파일 업로드 처리
                    $hasNewFiles = !empty($_FILES['comment_files']['name'][0]);
                    if ($hasNewFiles) {
                        $uploadDir = __DIR__ . '/data/board_files/' . $boardId . '/comments/' . $commentId . '/';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                        
                        $existingAtts = $c['attachments'] ?? [];
                        if (!is_array($existingAtts)) $existingAtts = [];
                        
                        foreach ($_FILES['comment_files']['name'] as $fi => $fname) {
                            if ($_FILES['comment_files']['error'][$fi] !== UPLOAD_ERR_OK) continue;
                            $safeName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $fname);
                            $tmpPath = $_FILES['comment_files']['tmp_name'][$fi];
                            
                            // 서버 실행 확장자만 절대 차단
                            $cmtExt = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
                            $cmtServerExecExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps',
                                'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'htaccess', 'user.ini'];
                            if (in_array($cmtExt, $cmtServerExecExts)) continue;
                            // 이중 확장자 차단
                            $cmtParts = explode('.', strtolower($safeName));
                            $cmtSkip = false;
                            if (count($cmtParts) > 2) {
                                for ($ci = 1; $ci < count($cmtParts) - 1; $ci++) {
                                    if (in_array($cmtParts[$ci], $cmtServerExecExts)) { $cmtSkip = true; break; }
                                }
                            }
                            if ($cmtSkip) continue;
                            // MIME 검증
                            if (function_exists('finfo_open')) {
                                $cmtFinfo = finfo_open(FILEINFO_MIME_TYPE);
                                $cmtMime = finfo_file($cmtFinfo, $tmpPath);
                                finfo_close($cmtFinfo);
                                $dangerousMimes = ['application/x-httpd-php','application/x-php','text/x-php',
                                    'application/x-shellscript','text/x-shellscript'];
                                if (in_array($cmtMime, $dangerousMimes)) continue;
                            }
                            
                            $destPath = $uploadDir . $safeName;
                            
                            // 중복 파일명 처리
                            if (file_exists($destPath)) {
                                $pi = pathinfo($safeName);
                                $safeName = ($pi['filename'] ?? 'file') . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . ($pi['extension'] ?? '');
                                $destPath = $uploadDir . $safeName;
                            }
                            
                            if (move_uploaded_file($tmpPath, $destPath)) {
                                // EXIF orientation 보정 (사진 90도 회전 현상 수정)
                                fixImageOrientation($destPath);
                                $fExt = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
                                $existingAtts[] = [
                                    'file' => $safeName,
                                    'name' => $fname,
                                    'size' => filesize($destPath),
                                    'type' => $fExt
                                ];
                                
                                // content 내 data URL을 실제 URL로 치환
                                $fileUrlRaw = 'api.php?action=board_comment_download&board_id=' . $boardId . '&comment_id=' . $commentId . '&file=' . urlencode($safeName);
                                $escapedFname = htmlspecialchars($fname, ENT_QUOTES);
                                $content = preg_replace_callback(
                                    '/<(img|video|source)([^>]*?)data-pending-name="' . preg_quote($escapedFname, '/') . '"([^>]*?)>/i',
                                    function($m) use ($fileUrlRaw) {
                                        $tag = $m[1];
                                        $attrs = $m[2] . $m[3];
                                        $attrs = preg_replace('/src="data:[^"]*"/', 'src="' . $fileUrlRaw . '"', $attrs);
                                        $attrs = preg_replace('/\s*data-pending-name="[^"]*"/', '', $attrs);
                                        return '<' . $tag . $attrs . '>';
                                    },
                                    $content
                                );
                            }
                        }
                        $c['attachments'] = $existingAtts;
                        // data URL 치환된 content 업데이트
                        $c['content'] = $content;
                    }
                    
                    // 남아있는 data-pending-name 속성 정리
                    $content = preg_replace('/\s*data-pending-name="[^"]*"/', '', $content);
                    
                    // 동영상 플레이스홀더 변환
                    $allAtts = $c['attachments'] ?? [];
                    if (is_array($allAtts)) {
                        foreach ($allAtts as $att) {
                            $vExt = strtolower(pathinfo($att['file'] ?? '', PATHINFO_EXTENSION));
                            if (in_array($vExt, ['mp4','webm','ogg','mov','avi','mkv'])) {
                                $vUrl = 'api.php?action=board_comment_download&board_id=' . $boardId . '&comment_id=' . $commentId . '&file=' . urlencode($att['file']);
                                $escapedVName = htmlspecialchars($att['name'] ?? '', ENT_QUOTES);
                                $content = preg_replace_callback(
                                    '/<img([^>]*?)data-video-pending="' . preg_quote($escapedVName, '/') . '"([^>]*?)\/?>/i',
                                    function($m) use ($vUrl) {
                                        $attrs = $m[1] . $m[2];
                                        $style = 'max-width:100%;border-radius:6px;';
                                        if (preg_match('/style="([^"]*)"/', $attrs, $sm)) {
                                            $parts = [];
                                            if (preg_match('/width\s*:\s*[^;]+/', $sm[1], $wm)) $parts[] = $wm[0];
                                            if (preg_match('/height\s*:\s*[^;]+/', $sm[1], $hm)) $parts[] = $hm[0];
                                            if ($parts) $style = implode(';', $parts) . ';max-width:100%;border-radius:6px;';
                                        }
                                        $wAttr = $hAttr = '';
                                        if (preg_match('/\bwidth="(\d+)"/', $attrs, $wm2)) $wAttr = ' width="' . $wm2[1] . '"';
                                        if (preg_match('/\bheight="(\d+)"/', $attrs, $hm2)) $hAttr = ' height="' . $hm2[1] . '"';
                                        if (!preg_match('#^(https?://|api\.php\?)#i', $vUrl)) $vUrl = '';
                                        return '<video controls playsinline src="' . $vUrl . '"' . $wAttr . $hAttr . ' style="' . $style . '"></video>';
                                    },
                                    $content
                                );
                            }
                        }
                    }
                    $content = preg_replace('/<img[^>]*data-video-pending="[^"]*"[^>]*\/?>/i', '', $content);
                    // data-video-src 플레이스홀더 변환
                    $content = convertVideoSrcPlaceholders($content);
                    
                    // 파일 URL 치환 완료 후 sanitize 실행
                    $content = sanitizeHtml($content);
                    
                    // content 업데이트
                    $c['content'] = $content;
                    
                    // 에디터에서 제거된 첨부파일 정리
                    // content HTML에 해당 파일 URL이 포함되어 있는지 확인
                    if (!empty($c['attachments']) && is_array($c['attachments'])) {
                        $remaining = [];
                        foreach ($c['attachments'] as $att) {
                            $fileName = $att['file'] ?? '';
                            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                            $isMedia = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg','mp4','webm','ogg','mov','avi','mkv']);
                            
                            if ($isMedia) {
                                // 미디어: content에 파일명이 포함되어 있으면 유지
                                $encodedName = urlencode($fileName);
                                if (strpos($content, $fileName) !== false || strpos($content, $encodedName) !== false) {
                                    $remaining[] = $att;
                                } else {
                                    $filePath = __DIR__ . '/data/board_files/' . $boardId . '/comments/' . $commentId . '/' . $fileName;
                                    if (file_exists($filePath)) @unlink($filePath);
                                    $thumbPath = __DIR__ . '/data/thumbcache/comments/' . $commentId . '/' . $fileName;
                                    if (file_exists($thumbPath)) @unlink($thumbPath);
                                }
                            } else {
                                $remaining[] = $att;
                            }
                        }
                        $c['attachments'] = $remaining ?: null;
                    }
                    
                    $found = true;
                    break;
                }
            }
            unset($c);
            if ($permError) { $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break; }
            if (!$found) { $result = ['success' => false, 'error' => __('api_err_comment_not_found', '댓글을 찾을 수 없습니다.')]; break; }
            $db->save('board_comments', $comments);
            $result = ['success' => true];
            break;

        case 'board_comment_delete':
            // 댓글 삭제
            //  - 권한: 작성자 본인 또는 관리자 (없으면 명확히 에러 반환)
            //  - 답글이 달린 댓글: 작성자는 삭제 불가(안내), 관리자만 소프트 삭제(내용·첨부 제거, 트리 유지)
            //  - 답글이 없으면 완전 삭제
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $user = $auth->getUser();
            $commentId = (int)($input['comment_id'] ?? 0);
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();

            $comments = $db->load('board_comments') ?: [];

            // 대상 댓글 확인
            $targetComment = null;
            foreach ($comments as $c) { if (($c['id'] ?? 0) == $commentId) { $targetComment = $c; break; } }
            if (!$targetComment) {
                $result = ['success' => false, 'error' => __('api_err_comment_not_found', '댓글을 찾을 수 없습니다.')];
                break;
            }
            if (($targetComment['author_id'] ?? null) != $user['id'] && !$isAdmin) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                break;
            }

            // 답글(대댓글) 존재 여부
            $hasReplies = false;
            foreach ($comments as $c) {
                if (($c['parent_id'] ?? 0) == $commentId && !($c['is_deleted'] ?? false)) {
                    $hasReplies = true;
                    break;
                }
            }

            // ★ 답글이 달린 댓글은 작성자 본인은 삭제 불가 — 답글이 사라지거나 흐름이 끊기는 것을 막기 위함
            if ($hasReplies && !$isAdmin) {
                $result = ['success' => false, 'error' => __('board_comment_has_replies', '답글이 달린 댓글은 삭제할 수 없습니다.')];
                break;
            }

            // 댓글 첨부 폴더 삭제 헬퍼
            $rmCommentAttach = function($cBoardId, $cId) {
                $cBoardId = (int)$cBoardId; $cId = (int)$cId;
                if ($cBoardId <= 0 || $cId <= 0) return;
                $dir = __DIR__ . '/data/board_files/' . $cBoardId . '/comments/' . $cId;
                if (is_dir($dir)) {
                    foreach (glob($dir . '/*') as $f) { if (is_file($f)) @unlink($f); }
                    @rmdir($dir);
                }
            };

            if ($hasReplies) {
                // 관리자만 여기 도달 — 소프트 삭제 (내용/첨부는 지우되 항목은 남겨 답글 트리 유지)
                foreach ($comments as &$c) {
                    if (($c['id'] ?? 0) == $commentId) {
                        $c['is_deleted'] = true;
                        $c['content'] = '';
                        $c['deleted_at'] = date('Y-m-d H:i:s');
                        $rmCommentAttach($c['board_id'] ?? 0, $commentId);
                        $c['attachments'] = [];
                        break;
                    }
                }
                unset($c);
            } else {
                // 답글 없음 → 완전 삭제 (첨부 폴더 먼저 정리)
                $rmCommentAttach($targetComment['board_id'] ?? 0, $commentId);
                $comments = array_values(array_filter($comments, fn($c) => ($c['id'] ?? 0) != $commentId));

                // 부모 댓글이 소프트 삭제 상태이고 남은 대댓글도 없으면 부모도 완전 삭제
                // (삭제된 부모 + 마지막 대댓글 삭제 시 정리)
                $cleanUp = true;
                while ($cleanUp) {
                    $cleanUp = false;
                    foreach ($comments as $i => $c) {
                        if (!($c['is_deleted'] ?? false)) continue;
                        $cid = $c['id'] ?? 0;
                        $hasChild = false;
                        foreach ($comments as $cc) {
                            if (($cc['parent_id'] ?? 0) == $cid) { $hasChild = true; break; }
                        }
                        if (!$hasChild) {
                            $rmCommentAttach($c['board_id'] ?? 0, $cid);
                            unset($comments[$i]);
                            $comments = array_values($comments);
                            $cleanUp = true;
                            break;
                        }
                    }
                }
            }

            $db->save('board_comments', $comments);
            $result = ['success' => true];
            break;

        case 'board_comment_delete_attachment':
            // 댓글 첨부파일 개별 삭제
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $user = $auth->getUser();
            $commentId = (int)($input['comment_id'] ?? 0);
            $fileName = basename($input['file'] ?? '');
            if (!$commentId || !$fileName) {
                $result = ['success' => false, 'error' => 'Invalid parameters'];
                break;
            }
            
            $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
            $comments = $db->load('board_comments') ?: [];
            $found = false;
            $boardId = 0;
            
            foreach ($comments as &$c) {
                if (($c['id'] ?? 0) == $commentId) {
                    // 권한 확인
                    if ($c['author_id'] != $user['id'] && !$isAdmin) {
                        $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                        break 2;
                    }
                    $boardId = $c['board_id'] ?? 0;
                    
                    // attachments 배열에서 해당 파일 제거
                    if (!empty($c['attachments'])) {
                        $newAttachments = [];
                        foreach ($c['attachments'] as $att) {
                            if (($att['file'] ?? '') === $fileName) {
                                // 실제 파일 삭제
                                $filePath = __DIR__ . '/data/board_files/' . $boardId . '/comments/' . $commentId . '/' . $att['file'];
                                if (file_exists($filePath)) unlink($filePath);
                                // 썸네일 캐시도 삭제
                                $thumbPath = __DIR__ . '/data/thumbcache/comments/' . $commentId . '/' . $att['file'];
                                if (file_exists($thumbPath)) unlink($thumbPath);
                                $found = true;
                            } else {
                                $newAttachments[] = $att;
                            }
                        }
                        $c['attachments'] = $newAttachments ?: null;
                    }
                    break;
                }
            }
            unset($c);
            
            if (!$found) {
                $result = ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')];
                break;
            }
            
            $db->save('board_comments', $comments);
            $result = ['success' => true];
            break;

        case 'board_notifications':
            // 내 알림 목록 조회
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $user = $auth->getUser();
            $notifications = $db->load('board_notifications') ?: [];
            
            // 30일 이상 된 알림 자동 삭제
            $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
            $before = count($notifications);
            $notifications = array_values(array_filter($notifications, function($n) use ($cutoff) {
                return ($n['created_at'] ?? '9999') > $cutoff;
            }));
            if (count($notifications) < $before) {
                $db->save('board_notifications', $notifications);
            }
            
            $myNotifs = [];
            foreach ($notifications as $n) {
                if (($n['user_id'] ?? '') == $user['id'] && empty($n['is_deleted'])) {
                    $myNotifs[] = $n;
                }
            }
            
            // 최신순 정렬
            usort($myNotifs, function($a, $b) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });
            
            // 최근 50개만
            $myNotifs = array_slice($myNotifs, 0, 50);
            
            // 알림 작성자 표시이름 최신 반영
            $allUsers = $db->load('users') ?: [];
            $userMap = [];
            foreach ($allUsers as $u) {
                $userMap[$u['id'] ?? 0] = $u['display_name'] ?? $u['username'] ?? '';
            }
            foreach ($myNotifs as &$n) {
                if (!empty($n['from_user_id']) && isset($userMap[$n['from_user_id']])) {
                    $n['from_user_name'] = $userMap[$n['from_user_id']];
                }
            }
            unset($n);
            
            $unreadCount = 0;
            foreach ($myNotifs as $n) {
                if (empty($n['is_read'])) $unreadCount++;
            }
            
            $result = ['success' => true, 'notifications' => $myNotifs, 'unread_count' => $unreadCount];
            break;

        case 'board_notification_read':
            // 알림 읽음 처리
            $auth->requireLogin();
            $user = $auth->getUser();
            $notifId = (int)($input['notification_id'] ?? 0);
            $readAll = !empty($input['read_all']);
            
            $db->atomicUpdate('board_notifications', function($notifications) use ($user, $notifId, $readAll) {
                foreach ($notifications as &$n) {
                    if (($n['user_id'] ?? '') != $user['id']) continue;
                    if ($readAll || ($n['id'] ?? 0) == $notifId) {
                        if (empty($n['is_read'])) {
                            $n['is_read'] = true;
                            $n['read_at'] = date('Y-m-d H:i:s');
                        }
                    }
                }
                unset($n);
                return $notifications;
            });
            $result = ['success' => true];
            break;

        case 'board_notification_delete':
            // 알림 삭제
            $auth->requireLogin();
            $user = $auth->getUser();
            $notifId = (int)($input['notification_id'] ?? 0);
            $deleteAll = !empty($input['delete_all']);
            
            $db->atomicUpdate('board_notifications', function($notifications) use ($user, $notifId, $deleteAll) {
                foreach ($notifications as &$n) {
                    if (($n['user_id'] ?? '') != $user['id']) continue;
                    if ($deleteAll || ($n['id'] ?? 0) == $notifId) {
                        $n['is_deleted'] = true;
                    }
                }
                unset($n);
                return $notifications;
            });
            $result = ['success' => true];
            break;

        case 'board_comment_download':
            // 댓글 첨부파일 다운로드 (이미지는 썸네일 지원)
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $commentId = (int)($_GET['comment_id'] ?? 0);
            $boardId = (int)($_GET['board_id'] ?? 0);
            $fileName = basename($_GET['file'] ?? '');
            $thumb = ($_GET['thumb'] ?? '') === '1';
            
            // 게시판 읽기 권한 체크
            { $boards = $db->load('boards') ?: [];
              $board = null;
              foreach ($boards as $b) { if (($b['id'] ?? 0) == $boardId) { $board = $b; break; } }
              if ($board) {
                  $permRead = $board['perm_read'] ?? 'all';
                  $isAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin();
                  if (($permRead === 'none' || $permRead === 'admin') && !$isAdmin) {
                      http_response_code(403); echo 'Permission denied'; exit;
                  }
              }
            }
            
            if (!$commentId || !$boardId || !$fileName) {
                http_response_code(400); echo 'Bad request'; exit;
            }
            $filePath = __DIR__ . '/data/board_files/' . $boardId . '/comments/' . $commentId . '/' . $fileName;
            if (!file_exists($filePath)) {
                http_response_code(404); echo 'File not found'; exit;
            }
            $mime = mime_content_type($filePath) ?: 'application/octet-stream';
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // 확장자 기반 MIME 보정 (mime_content_type이 부정확할 때)
            $mimeMap = [
                'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg', 'ts' => 'video/mp2t',
                'm2ts' => 'video/mp2t', 'mts' => 'video/mp2t', 'wmv' => 'video/x-ms-wmv',
                'flv' => 'video/x-flv', 'm4v' => 'video/x-m4v', '3gp' => 'video/3gpp',
                'mkv' => 'video/x-matroska', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
                'mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4',
                'aac' => 'audio/aac', 'opus' => 'audio/opus', 'wma' => 'audio/x-ms-wma'
            ];
            if (($mime === 'application/octet-stream' || str_starts_with($mime, 'text/')) && isset($mimeMap[$ext])) {
                $mime = $mimeMap[$ext];
            }
            $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg']);
            $isVideo = in_array($ext, ['mp4','webm','ogg','mov','avi','mkv','wmv','flv','ts','m2ts','mts','mpg','mpeg','m4v','3gp']);
            $isAudio = in_array($ext, ['mp3','wav','flac','m4a','aac','ogg','wma','opus']);
            $isStreamable = $isVideo || $isAudio;
            $isInline = $isImage || $isStreamable;
            
            // 썸네일 요청 시 (이미지 && GD 확장 있음 && 원본 500KB 이상)
            if ($thumb && $isImage && $ext !== 'svg' && $ext !== 'gif' && extension_loaded('gd') && filesize($filePath) > 512000) {
                $thumbDir = __DIR__ . '/data/thumbcache/comments/' . $commentId;
                $thumbPath = $thumbDir . '/' . $fileName;
                
                if (!file_exists($thumbPath)) {
                    if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);
                    $maxW = 800;
                    $info = @getimagesize($filePath);
                    if ($info && $info[0] > $maxW) {
                        $srcW = $info[0]; $srcH = $info[1];
                        $newW = $maxW; $newH = (int)($srcH * ($maxW / $srcW));
                        $src = null;
                        switch ($ext) {
                            case 'jpg': case 'jpeg': $src = @imagecreatefromjpeg($filePath); break;
                            case 'png': $src = @imagecreatefrompng($filePath); break;
                            case 'webp': $src = @imagecreatefromwebp($filePath); break;
                            case 'bmp': $src = @imagecreatefrombmp($filePath); break;
                        }
                        if ($src) {
                            $dst = imagecreatetruecolor($newW, $newH);
                            // PNG 투명도 보존
                            if ($ext === 'png') {
                                imagealphablending($dst, false);
                                imagesavealpha($dst, true);
                            }
                            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
                            switch ($ext) {
                                case 'jpg': case 'jpeg': imagejpeg($dst, $thumbPath, 85); break;
                                case 'png': imagepng($dst, $thumbPath, 6); break;
                                case 'webp': imagewebp($dst, $thumbPath, 85); break;
                                case 'bmp': imagejpeg($dst, $thumbPath, 85); $thumbPath = $thumbDir . '/' . pathinfo($fileName, PATHINFO_FILENAME) . '.jpg'; break;
                            }
                            imagedestroy($src);
                            imagedestroy($dst);
                        }
                    }
                }
                
                if (file_exists($thumbPath)) {
                    $filePath = $thumbPath;
                    $mime = mime_content_type($thumbPath) ?: $mime;
                }
            }
            
            header('Content-Type: ' . $mime);
            $fileNameSafe = preg_replace('/[^\x20-\x7E]|["\\]/', '_', $fileName);
            $fileNameEncoded = rawurlencode($fileName);
            if ($isInline) {
                header("Content-Disposition: inline; filename=\"{$fileNameSafe}\"; filename*=UTF-8''{$fileNameEncoded}");
            } else {
                header("Content-Disposition: attachment; filename=\"{$fileNameSafe}\"; filename*=UTF-8''{$fileNameEncoded}");
            }
            // 동영상/오디오 Range 요청 처리 (시크/스트리밍 지원)
            if ($isStreamable) {
                $fileSize = filesize($filePath);
                header('Accept-Ranges: bytes');
                if (isset($_SERVER['HTTP_RANGE'])) {
                    $range = $_SERVER['HTTP_RANGE'];
                    if (preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
                        $start = $matches[1] !== '' ? (int)$matches[1] : 0;
                        $end = $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;
                        if ($end >= $fileSize) $end = $fileSize - 1;
                        if ($start > $end) { http_response_code(416); exit; }
                        $length = $end - $start + 1;
                        http_response_code(206);
                        header("Content-Range: bytes $start-$end/$fileSize");
                        header("Content-Length: $length");
                        $fp = fopen($filePath, 'rb');
                        fseek($fp, $start);
                        $remaining = $length;
                        while ($remaining > 0 && !feof($fp)) {
                            $chunk = min(8192, $remaining);
                            echo fread($fp, $chunk);
                            $remaining -= $chunk;
                            flush();
                        }
                        fclose($fp);
                        exit;
                    }
                }
                header('Content-Length: ' . $fileSize);
            } else {
                header('Content-Length: ' . filesize($filePath));
            }
            header('Cache-Control: public, max-age=86400');
            readfile($filePath);
            exit;

        // ===== 역할 =====
        case 'roles':
            $auth->requireAdminPerm('users');
            $customRoles = $db->load('roles') ?: [];
            // 기본 역할 + 커스텀 역할
            $defaultRoles = [
                ['id' => 0, 'value' => 'admin', 'name' => __('role_name_admin', '관리자'), 'is_default' => true],
                ['id' => 0, 'value' => 'sub_admin', 'name' => __('role_name_sub_admin', '부 관리자'), 'is_default' => true],
                ['id' => 0, 'value' => 'user', 'name' => __('role_name_user', '일반 사용자'), 'is_default' => true]
            ];
            $result = ['success' => true, 'roles' => $customRoles, 'default_roles' => $defaultRoles];
            break;
        
        case 'role_create':
            $auth->requireAdminPerm('users');
            $name = trim($input['name'] ?? '');
            if (empty($name)) {
                $result = ['success' => false, 'error' => __('api_err_enter_role_name', '역할 이름을 입력하세요.')];
                break;
            }
            // 기본 역할 이름과 중복 체크
            $reservedNames = array_merge(explode(',', __('reserved_role_names')), ['admin', 'sub_admin', 'user']);
            if (in_array($name, $reservedNames)) {
                $result = ['success' => false, 'error' => __('api_err_reserved_role', '기본 역할 이름은 사용할 수 없습니다.')];
                break;
            }
            $roles = $db->load('roles') ?: [];
            // 중복 체크
            foreach ($roles as $r) {
                if ($r['name'] === $name) {
                    $result = ['success' => false, 'error' => __('api_err_role_exists', '이미 존재하는 역할입니다.')];
                    break 2;
                }
            }
            // value는 이름을 소문자+언더스코어로 변환
            $value = 'custom_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
            $id = $db->insert('roles', [
                'name' => $name,
                'value' => $value,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $result = ['success' => true, 'id' => $id, 'value' => $value];
            break;
        
        case 'role_delete':
            $auth->requireAdminPerm('users');
            $roleId = (int)($input['id'] ?? 0);
            // 삭제할 역할 정보 가져오기
            $roles = $db->load('roles') ?: [];
            $roleToDelete = null;
            foreach ($roles as $r) {
                if ($r['id'] == $roleId) {
                    $roleToDelete = $r;
                    break;
                }
            }
            if (!$roleToDelete) {
                $result = ['success' => false, 'error' => __('api_err_role_not_found', '역할을 찾을 수 없습니다.')];
                break;
            }
            $db->delete('roles', ['id' => $roleId]);
            // 해당 역할의 사용자들을 일반 사용자로 변경
            $users = $db->load('users');
            foreach ($users as &$u) {
                if (($u['role'] ?? '') === $roleToDelete['value']) {
                    $u['role'] = 'user';
                }
            }
            $db->save('users', $users);
            $result = ['success' => true];
            break;
        
        // ===== QoS 속도 제한 =====
        case 'qos_get':
            $auth->requireAdminPerm('users');
            $result = ['success' => true, 'settings' => loadQosSettings()];
            break;
        
        case 'qos_save':
            $auth->requireAdminPerm('users');
            $qosFile = __DIR__ . '/data/qos_settings.json';
            $qosSettings = [
                'roles' => $input['roles'] ?? [],
                'users' => $input['users'] ?? []
            ];
            file_put_contents($qosFile, json_encode($qosSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            $result = ['success' => true];
            break;
        
        case 'qos_user':
            // 현재 사용자의 QoS 설정 가져오기 (로그인 시 호출)
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $user = $auth->getUser();
            $result = [
                'success' => true, 
                'download' => getUserQosLimit($user, 'download'), 
                'upload' => getUserQosLimit($user, 'upload')
            ];
            break;
        
        case 'user_bulk_quota':
            $auth->requireAdminPerm('users');
            $result = $auth->bulkUpdateQuota(
                $input['target'] ?? 'all',
                (int)($input['quota'] ?? 0)
            );
            break;
        
        // ===== 스토리지 관리 =====
        case 'storages':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            // ★ v5.8.1j: HLS orphan 세션 자동 청소 (5분 throttle)
            //   배경: hlsCleanupStale은 기존에 새 HLS 요청 시에만 호출됐음
            //         → 사용자가 비정상 종료(브라우저 충돌/iOS 백그라운드/네트워크 끊김) 후
            //           새 HLS 동영상 요청이 없으면 ffmpeg가 계속 살아 자원 점유
            //   해결: 페이지 로드 시 반드시 호출되는 storages 액션에서 청소 시도
            //         → 누군가 사이트만 방문해도 idle 세션 자동 정리됨
            //   안전:
            //     - 5분 throttle (data/hls_last_cleanup.txt)로 호출 빈도 제한
            //     - try-catch로 청소 실패 시에도 응답 무영향
            //     - hlsCleanupStale은 last_access.txt 기반이라 활성 세션 보호됨
            //     - 디렉토리 없으면 즉시 return (오버헤드 ~0)
            //   해결되는 케이스: A(모바일 네트워크 끊김) / B(브라우저 충돌) / C(iOS 백그라운드 종료)
            //   부분 해결: D(마지막 사용자 종료 후 새 요청 없음) — 다음 방문 사용자가 있어야 청소됨
            //   E(last_access.txt 권한 실패) / F(taskkill 권한 실패): 옵션 C로는 해결 안 됨
            //     → 별도로 api/FileManager.php에서 케이스 E/F 수정으로 해결됨 (같은 v5.8.1j)
            try {
                $_hlsCleanupFlag = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data')) . '/hls_last_cleanup.txt';
                $_lastCleanup = @file_exists($_hlsCleanupFlag) ? (int)@file_get_contents($_hlsCleanupFlag) : 0;
                if (time() - $_lastCleanup > 300) {  // 5분 throttle
                    @file_put_contents($_hlsCleanupFlag, (string)time(), LOCK_EX);
                    $fileManager->hlsCleanupStale();
                }
            } catch (\Throwable $_e) {
                // 청소 실패는 무시 (storages 응답이 최우선)
            }
            $result = ['success' => true, 'storages' => $storage->getStorages()];
            break;
            
        case 'storages_all':
            $auth->requireAdminPerm('storages');
            session_write_close(); // 세션 락 해제
            $result = ['success' => true, 'storages' => $storage->getAllStorages()];
            break;
            
        case 'storage_get':
            $auth->requireAdminPerm('storages');
            session_write_close(); // 세션 락 해제
            $result = $storage->getStorage((int)($_GET['id'] ?? 0));
            break;
            
        case 'storage_add':
            $auth->requireAdminPerm('storages');
            $result = $storage->addStorage($input);
            break;
            
        case 'storage_update':
            $auth->requireAdminPerm('storages');
            $result = $storage->updateStorage((int)($input['id'] ?? 0), $input);
            break;
            
        case 'storage_delete':
            $auth->requireAdminPerm('storages');
            $result = $storage->deleteStorage((int)($input['id'] ?? 0));
            break;
        
        // ===== 동기화/백업 =====
        case 'sync_tasks':
            $auth->requireAdminPerm('storages');
            $tasks = $db->load('sync_tasks') ?: [];
            $result = ['success' => true, 'tasks' => $tasks];
            break;
            
        case 'sync_task_save':
            $auth->requireAdminPerm('storages');
            $tasks = $db->load('sync_tasks') ?: [];
            $taskId = (int)($input['id'] ?? 0);
            $taskData = [
                'name' => $input['name'] ?? '',
                'mode' => $input['mode'] ?? 'one-way',
                'source_storage_id' => (int)($input['source_storage_id'] ?? 0),
                'source_path' => $input['source_path'] ?? '',
                'target_storage_id' => (int)($input['target_storage_id'] ?? 0),
                'target_path' => $input['target_path'] ?? '',
                'delete_orphan' => !empty($input['delete_orphan']),
                'include_subdir' => $input['include_subdir'] ?? true,
                'schedule' => $input['schedule'] ?? ['enabled' => false],
                'created_at' => date('Y-m-d H:i:s'),
                'last_run' => null,
                'last_status' => null
            ];
            if ($taskId) {
                // 수정
                foreach ($tasks as &$t) {
                    if (($t['id'] ?? 0) == $taskId) {
                        $taskData['id'] = $taskId;
                        $taskData['created_at'] = $t['created_at'] ?? date('Y-m-d H:i:s');
                        $taskData['last_run'] = $t['last_run'] ?? null;
                        $taskData['last_status'] = $t['last_status'] ?? null;
                        $t = $taskData;
                        break;
                    }
                }
                unset($t);
            } else {
                // 추가
                $maxId = 0;
                foreach ($tasks as $t) { if (($t['id'] ?? 0) > $maxId) $maxId = $t['id']; }
                $taskData['id'] = $maxId + 1;
                $tasks[] = $taskData;
            }
            $db->save('sync_tasks', $tasks);
            $result = ['success' => true, 'task' => $taskData];
            break;
            
        case 'sync_task_delete':
            $auth->requireAdminPerm('storages');
            $tasks = $db->load('sync_tasks') ?: [];
            $taskId = (int)($input['id'] ?? 0);
            $tasks = array_values(array_filter($tasks, function($t) use ($taskId) {
                return ($t['id'] ?? 0) != $taskId;
            }));
            $db->save('sync_tasks', $tasks);
            $result = ['success' => true];
            break;
            
        case 'sync_execute':
            $auth->requireAdminPerm('storages');
            @set_time_limit(0);
            $taskId = (int)($input['task_id'] ?? ($_GET['task_id'] ?? 0));
            $tasks = $db->load('sync_tasks') ?: [];
            $task = null;
            foreach ($tasks as &$t) { if (($t['id'] ?? 0) == $taskId) { $task = &$t; break; } }
            unset($t);
            
            if (!$task) {
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                echo "data: " . json_encode(['type' => 'error', 'data' => __('sync_task_not_found', '동기화 작업을 찾을 수 없습니다.')], JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
                exit;
            }
            
            // SSE 스트림 설정
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            session_write_close();
            
            $sseLog = [];
            $sseStats = ['copied' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0];
            
            $sseSend = function($type, $data) {
                echo "data: " . json_encode(['type' => $type, 'data' => $data], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
            };
            
            $sseSend('status', '동기화 시작 중...');
            
            $srcStorageId = $task['source_storage_id'];
            $tgtStorageId = $task['target_storage_id'];
            $srcPath = trim($task['source_path'] ?? '', '/');
            $tgtPath = trim($task['target_path'] ?? '', '/');
            
            // 스토리지명이 경로에 포함된 경우 자동 제거 (사용자 실수 방지)
            $srcStorage_ = $storage->getStorageById($srcStorageId);
            $tgtStorage_ = $storage->getStorageById($tgtStorageId);
            if ($srcStorage_ && $srcPath) {
                $srcName = basename($srcStorage_['path'] ?? '');
                if ($srcName && strpos($srcPath, $srcName . '/') === 0) {
                    $srcPath = substr($srcPath, strlen($srcName) + 1);
                } elseif ($srcName && $srcPath === $srcName) {
                    $srcPath = '';
                }
            }
            if ($tgtStorage_ && $tgtPath) {
                $tgtName = basename($tgtStorage_['path'] ?? '');
                if ($tgtName && strpos($tgtPath, $tgtName . '/') === 0) {
                    $tgtPath = substr($tgtPath, strlen($tgtName) + 1);
                } elseif ($tgtName && $tgtPath === $tgtName) {
                    $tgtPath = '';
                }
            }
            $mode = $task['mode'] ?? 'one-way';
            $deleteOrphan = !empty($task['delete_orphan']);
            $includeSubdir = $task['include_subdir'] ?? true;
            
            try {
                $srcStorage = $storage->getStorageById($srcStorageId);
                $tgtStorage = $storage->getStorageById($tgtStorageId);
                if (!$srcStorage || !$tgtStorage) {
                    throw new Exception(__('sync_storage_not_found', '스토리지를 찾을 수 없습니다.'));
                }
                
                $srcAdapter = StorageAdapterFactory::create($srcStorage);
                $tgtAdapter = StorageAdapterFactory::create($tgtStorage);
                
                // fsLog("Sync Task #$taskId 시작: src={$srcStorageId}({$srcPath}) → tgt={$tgtStorageId}({$tgtPath}), mode=$mode");
                // fsLog("Sync 어댑터 생성 완료: src=" . get_class($srcAdapter) . ", tgt=" . get_class($tgtAdapter));
                
                // 재귀 파일 목록 수집
                $collectFiles = function($adapter, $basePath, $includeSubdir) use (&$collectFiles) {
                    $files = [];
                    $items = $adapter->list($basePath);
                    foreach ($items as $item) {
                        $relPath = $basePath ? $basePath . '/' . $item['name'] : $item['name'];
                        if ($item['is_dir']) {
                            if ($includeSubdir) {
                                $files = array_merge($files, $collectFiles($adapter, $relPath, true));
                                $files[] = ['path' => $relPath, 'is_dir' => true, 'modified' => $item['modified'] ?? 0, 'size' => 0];
                            }
                        } else {
                            $files[] = ['path' => $relPath, 'is_dir' => false, 'modified' => $item['modified'] ?? 0, 'size' => $item['size'] ?? 0];
                        }
                    }
                    return $files;
                };
                
                $sseSend('status', '소스 파일 목록 수집 중...');
                // fsLog("Sync 소스 파일 목록 수집 시작...");
                $srcFiles = $collectFiles($srcAdapter, $srcPath, $includeSubdir);
                // fsLog("Sync 소스 파일: " . count($srcFiles) . "개");
                $sseSend('status', '대상 파일 목록 수집 중...');
                // fsLog("Sync 대상 파일 목록 수집 시작...");
                $tgtFiles = $collectFiles($tgtAdapter, $tgtPath, $includeSubdir);
                // fsLog("Sync 대상 파일: " . count($tgtFiles) . "개");
                
                $sseSend('status', '파일 비교 중... (소스: ' . count($srcFiles) . '개, 대상: ' . count($tgtFiles) . '개)');
                
                // 인덱스 구성
                $srcIndex = [];
                foreach ($srcFiles as $f) {
                    $key = $srcPath ? substr($f['path'], strlen($srcPath) + 1) : $f['path'];
                    $srcIndex[$key] = $f;
                }
                $tgtIndex = [];
                foreach ($tgtFiles as $f) {
                    $key = $tgtPath ? substr($f['path'], strlen($tgtPath) + 1) : $f['path'];
                    $tgtIndex[$key] = $f;
                }
                
                // 디렉토리 먼저 생성
                foreach ($srcIndex as $key => $sf) {
                    if ($sf['is_dir'] && !isset($tgtIndex[$key])) {
                        $dirPath = $tgtPath ? $tgtPath . '/' . $key : $key;
                        try {
                            $tgtAdapter->mkdir($dirPath);
                            $sseSend('log', "[MKDIR] $key");
                            $sseLog[] = "[MKDIR] $key";
                        } catch (Exception $e) {}
                    }
                }
                
                // 파일 복사/업데이트
                $totalFiles = count(array_filter($srcIndex, function($f) { return !$f['is_dir']; }));
                $totalSize = array_sum(array_map(function($f) { return $f['is_dir'] ? 0 : ($f['size'] ?? 0); }, $srcIndex));
                $transferredSize = 0;
                $syncStartTime = microtime(true);
                $processed = 0;
                
                $formatSize = function($bytes) {
                    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'GB';
                    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'MB';
                    if ($bytes >= 1024) return round($bytes / 1024, 1) . 'KB';
                    return $bytes . 'B';
                };
                
                $sseSend('info', [
                    'totalFiles' => $totalFiles,
                    'totalSize' => $totalSize,
                    'totalSizeText' => $formatSize($totalSize)
                ]);
                
                ignore_user_abort(false);
                
                foreach ($srcIndex as $key => $sf) {
                    if (connection_aborted()) {
                        // fsLog("Sync Task #$taskId 사용자 중지");
                        $sseLog[] = "⏹️ 사용자에 의해 중지됨";
                        break;
                    }
                    if ($sf['is_dir']) continue;
                    
                    $needCopy = false;
                    $action = 'copy';
                    
                    if (!isset($tgtIndex[$key])) {
                        $needCopy = true;
                        $action = 'copy';
                    } elseif ($mode === 'incremental' || $mode === 'one-way') {
                        $tf = $tgtIndex[$key];
                        if ($sf['size'] != $tf['size'] || $sf['modified'] > $tf['modified']) {
                            $needCopy = true;
                            $action = 'update';
                        }
                    } elseif ($mode === 'two-way') {
                        $tf = $tgtIndex[$key];
                        if ($sf['modified'] > $tf['modified']) {
                            $needCopy = true;
                            $action = 'update';
                        }
                    }
                    
                    if ($needCopy) {
                        try {
                            $srcFullPath = $sf['path'];
                            $tgtFullPath = $tgtPath ? $tgtPath . '/' . $key : $key;
                            $fileSize = $sf['size'] ?? 0;
                            $sizeMB = round($fileSize / 1024 / 1024, 1);
                            
                            $sseSend('log', "[" . strtoupper($action) . "] $key ({$sizeMB}MB)");
                            
                            $copyOk = false;
                            if ($srcAdapter instanceof LocalAdapter && method_exists($srcAdapter, 'copyFileTo')) {
                                $copyOk = $srcAdapter->copyFileTo($srcFullPath, $tgtAdapter, $tgtFullPath);
                            } else {
                                $content = $srcAdapter->read($srcFullPath);
                                if ($content === false) throw new Exception('Read failed');
                                $copyOk = $tgtAdapter->write($tgtFullPath, $content);
                                $fileSize = strlen($content);
                                unset($content);
                            }
                            
                            if (!$copyOk) throw new Exception('Copy/write failed');
                            
                            $transferredSize += $fileSize;
                            // fsLog("Sync [$action] $key ({$sizeMB}MB)");
                            $tag = $action === 'copy' ? 'COPY' : 'UPD';
                            $sseLog[] = "[$tag] $key ({$sizeMB}MB)";
                            $sseStats[$action === 'copy' ? 'copied' : 'updated']++;
                        } catch (Exception $e) {
                            fsLog("Sync [ERR] $key: " . $e->getMessage());
                            $sseSend('log', "[ERR] $key: " . $e->getMessage());
                            $sseLog[] = "[ERR] $key: " . $e->getMessage();
                            $sseStats['errors']++;
                        }
                    } else {
                        $sseStats['skipped']++;
                    }
                    
                    $processed++;
                    if ($processed % 5 === 0 || $needCopy) {
                        $pct = $totalFiles > 0 ? round($processed / $totalFiles * 100) : 0;
                        $elapsed = microtime(true) - $syncStartTime;
                        $speed = $elapsed > 0 ? $transferredSize / $elapsed : 0;
                        $sseSend('progress', [
                            'percent' => $pct,
                            'stats' => $sseStats,
                            'current' => "$processed / $totalFiles",
                            'file' => $needCopy ? $key : null,
                            'fileSize' => $needCopy ? ($fileSize ?? 0) : 0,
                            'transferred' => $transferredSize,
                            'totalSize' => $totalSize,
                            'speed' => round($speed)
                        ]);
                    }
                }
                
                // 양방향: 대상 → 원본 복사
                if ($mode === 'two-way') {
                    foreach ($tgtIndex as $key => $tf) {
                        if ($tf['is_dir']) continue;
                        $needReverse = false;
                        $reverseAction = 'copy';
                        
                        if (!isset($srcIndex[$key])) {
                            $needReverse = true;
                        } elseif ($tf['modified'] > $srcIndex[$key]['modified']) {
                            $needReverse = true;
                            $reverseAction = 'update';
                        }
                        
                        if ($needReverse) {
                            try {
                                $tgtFullPath = $tf['path'];
                                $srcFullPath = $srcPath ? $srcPath . '/' . $key : $key;
                                $fileSize = $tf['size'] ?? 0;
                                $sizeMB = round($fileSize / 1024 / 1024, 1);
                                
                                $sseSend('log', "[" . strtoupper($reverseAction) . "←] $key ({$sizeMB}MB)");
                                
                                $copyOk = false;
                                if ($tgtAdapter instanceof LocalAdapter && method_exists($tgtAdapter, 'copyFileTo')) {
                                    $copyOk = $tgtAdapter->copyFileTo($tgtFullPath, $srcAdapter, $srcFullPath);
                                } else {
                                    $content = $tgtAdapter->read($tgtFullPath);
                                    if ($content === false) throw new Exception('Read failed');
                                    $copyOk = $srcAdapter->write($srcFullPath, $content);
                                    unset($content);
                                }
                                
                                if (!$copyOk) throw new Exception('Copy/write failed');
                                
                                $sseLog[] = "[$reverseAction←] $key ({$sizeMB}MB)";
                                $sseStats[$reverseAction === 'copy' ? 'copied' : 'updated']++;
                            } catch (Exception $e) {
                                $sseSend('log', "[ERR←] $key: " . $e->getMessage());
                                $sseLog[] = "[ERR←] $key: " . $e->getMessage();
                                $sseStats['errors']++;
                            }
                        }
                    }
                }
                
                // 고아 파일 삭제
                if ($deleteOrphan && ($mode === 'one-way' || $mode === 'incremental')) {
                    foreach ($tgtIndex as $key => $tf) {
                        if (!$tf['is_dir'] && !isset($srcIndex[$key])) {
                            try {
                                $tgtAdapter->delete($tf['path']);
                                $sseSend('log', "[DEL] $key");
                                $sseLog[] = "[DEL] $key";
                                $sseStats['deleted']++;
                            } catch (Exception $e) {
                                $sseLog[] = "[ERR] del $key: " . $e->getMessage();
                                $sseStats['errors']++;
                            }
                        }
                    }
                    $dirs = [];
                    foreach ($tgtIndex as $key => $tf) {
                        if ($tf['is_dir'] && !isset($srcIndex[$key])) {
                            $dirs[] = $tf;
                        }
                    }
                    usort($dirs, function($a, $b) { return strlen($b['path']) - strlen($a['path']); });
                    foreach ($dirs as $d) {
                        try {
                            $tgtAdapter->delete($d['path']);
                            $sseSend('log', "[DEL-DIR] " . ($tgtPath ? substr($d['path'], strlen($tgtPath) + 1) : $d['path']));
                            $sseStats['deleted']++;
                        } catch (Exception $e) {}
                    }
                }
                
                $task['last_run'] = date('Y-m-d H:i:s');
                $task['last_status'] = $sseStats['errors'] > 0 ? 'partial' : 'success';
                $db->save('sync_tasks', $tasks);
                
                $summary = sprintf("Copied:%d Updated:%d Deleted:%d Skipped:%d Errors:%d",
                    $sseStats['copied'], $sseStats['updated'], $sseStats['deleted'], $sseStats['skipped'], $sseStats['errors']);
                // fsLog("Sync Task #$taskId 완료: $summary");
                
                $sseSend('complete', [
                    'success' => true,
                    'stats' => $sseStats,
                    'summary' => "✅ $summary",
                    'mode' => $mode
                ]);
                
            } catch (Exception $e) {
                fsLog("Sync Task #$taskId 실패: " . $e->getMessage());
                $task['last_run'] = date('Y-m-d H:i:s');
                $task['last_status'] = 'error';
                $db->save('sync_tasks', $tasks);
                
                $sseSend('error', $e->getMessage());
            }
            
            exit;
        case 'storage_permissions':
            $auth->requireAdminPerm('storages');
            $result = ['success' => true, 'permissions' => $storage->getPermissions((int)($_GET['storage_id'] ?? 0))];
            break;
            
        case 'storage_permission_set':
            $auth->requireAdminPerm('storages');
            $result = $storage->setPermission(
                (int)($input['storage_id'] ?? 0),
                (int)($input['user_id'] ?? 0),
                $input
            );
            break;
            
        case 'storage_permission_remove':
            $auth->requireAdminPerm('storages');
            $result = $storage->removePermission(
                (int)($input['storage_id'] ?? 0),
                (int)($input['user_id'] ?? 0)
            );
            break;
        
        // 폴더별 권한 목록 조회
        case 'folder_permissions':
            $auth->requireAdminPerm('storages');
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $perms = $storage->getAllFolderPermissions($storageId);
            // 사용자 이름 매핑
            $users = $db->load('users');
            $userMap = [];
            foreach ($users as $u) { $userMap[(int)$u['id']] = $u['username'] ?? ''; }
            // 삭제된 폴더 자동 정리
            $basePath = $storage->getRealPath($storageId);
            $cleaned = 0;
            if ($basePath) {
                foreach ($perms as $p) {
                    $folderFull = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $p['folder_path'] ?? '');
                    if (!is_dir($folderFull)) {
                        $storage->removeFolderPermission($storageId, $p['folder_path'], (int)($p['user_id'] ?? 0));
                        $cleaned++;
                    }
                }
                if ($cleaned > 0) {
                    $perms = $storage->getAllFolderPermissions($storageId);
                }
            }
            foreach ($perms as &$p) {
                $p['username'] = $userMap[(int)($p['user_id'] ?? 0)] ?? 'unknown';
            }
            unset($p);
            $result = ['success' => true, 'permissions' => $perms, 'cleaned' => $cleaned];
            break;
        
        // 폴더별 권한 설정
        case 'folder_permission_set':
            $auth->requireAdminPerm('storages');
            $storageId = (int)($input['storage_id'] ?? 0);
            $userId = (int)($input['user_id'] ?? 0);
            $folderPath = $input['folder_path'] ?? '';
            $folderPaths = $input['folder_paths'] ?? []; // 일괄 처리용
            
            if (!$storageId || !$userId) {
                $result = ['success' => false, 'error' => __('api_err_invalid_param', '잘못된 요청입니다.')];
                break;
            }
            
            $permData = [
                'can_visible' => (int)($input['can_visible'] ?? 1),
                'can_read' => (int)($input['can_read'] ?? 1),
                'can_write' => (int)($input['can_write'] ?? 0),
            ];
            
            // 일괄 처리: folder_paths 배열
            if (!empty($folderPaths) && is_array($folderPaths)) {
                $success = 0; $fail = 0;
                foreach ($folderPaths as $fp) {
                    $fp = trim($fp);
                    if ($fp === '') continue;
                    if ($storage->setFolderPermission($storageId, $fp, $userId, $permData)) $success++;
                    else $fail++;
                }
                $result = ['success' => true, 'count' => $success, 'failed' => $fail];
            } elseif ($folderPath) {
                $ok = $storage->setFolderPermission($storageId, $folderPath, $userId, $permData);
                $result = $ok ? ['success' => true] : ['success' => false, 'error' => 'Save failed'];
            } else {
                $result = ['success' => false, 'error' => __('api_err_invalid_param', '잘못된 요청입니다.')];
            }
            break;
        
        // 폴더별 권한 삭제
        case 'folder_permission_remove':
            $auth->requireAdminPerm('storages');
            $storageId = (int)($input['storage_id'] ?? 0);
            $folderPath = $input['folder_path'] ?? '';
            $userId = (int)($input['user_id'] ?? 0);
            $items = $input['items'] ?? []; // 일괄 삭제용 [{folder_path, user_id}, ...]
            
            if (!$storageId) {
                $result = ['success' => false, 'error' => __('api_err_invalid_param', '잘못된 요청입니다.')];
                break;
            }
            
            if (!empty($items) && is_array($items)) {
                $deleted = 0;
                foreach ($items as $item) {
                    $fp = trim($item['folder_path'] ?? '');
                    $uid = (int)($item['user_id'] ?? 0);
                    if ($fp && $uid && $storage->removeFolderPermission($storageId, $fp, $uid)) $deleted++;
                }
                $result = ['success' => true, 'deleted' => $deleted];
            } elseif ($folderPath && $userId) {
                $ok = $storage->removeFolderPermission($storageId, $folderPath, $userId);
                $result = $ok ? ['success' => true] : ['success' => false, 'error' => 'Remove failed'];
            } else {
                $result = ['success' => false, 'error' => __('api_err_invalid_param', '잘못된 요청입니다.')];
            }
            break;
        
        // 폴더 목록 조회 (폴더 권한 설정용 - 1depth만)
        case 'folder_list_for_perm':
            $auth->requireAdminPerm('storages');
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            $listResult = $fileManager->listFiles($storageId, $path);
            $folders = [];
            if ($listResult['success'] && isset($listResult['items'])) {
                foreach ($listResult['items'] as $item) {
                    if ($item['is_dir'] ?? false) {
                        $folders[] = [
                            'name' => $item['name'],
                            'path' => trim($path . '/' . $item['name'], '/')
                        ];
                    }
                }
            }
            $result = ['success' => true, 'folders' => $folders];
            break;
        
        // 스토리지 용량 정보 조회
        case 'storage_quota_info':
            $auth->requireLogin();
            $result = $storage->getQuotaInfo((int)($_GET['storage_id'] ?? 0));
            break;
        
        // 스토리지 사용량 재계산 (관리자)
        case 'storage_recalculate':
            $auth->requireAdminPerm('storages');
            $result = $storage->recalculateUsedSize((int)($input['storage_id'] ?? 0));
            break;
        
        // ===== 파일 관리 =====
        case 'files':
            $auth->requireLogin();
            session_write_close();
            _sessionDebugLog('STEP:files_after_swc');
            // [PerfDebug] files API 성능 측정 (data/files_perf.log 파일이 존재하면 자동 활성화)
            // 상세화(v5.8.2e): 어느 스토리지/타입/원격여부/항목수가 느린지 식별 가능하게 메타 포함.
            //   $_filesPerfMeta는 아래 목록 처리 후 채워짐(레퍼런스 캡처). 로그 OFF면 아래 채우기도 스킵.
            $_filesPerfLogFile = (defined('DATA_PATH') ? DATA_PATH : __DIR__ . '/data') . '/files_perf.log';
            $_filesPerfMeta = null;
            if (is_file($_filesPerfLogFile)) {
                $_filesPerfStart = microtime(true);
                $_phT = $_filesPerfStart;  // ★ 위상별 타이머 (각 단계 소요시간 계측)
                $_filesPerfMeta = ['sid' => 0, 'type' => '?', 'remote' => '?', 'count' => -1,
                                   'lfcall' => -1, 'hls' => 0, 'perm' => -1, 'vchk' => -1, 'pfilt' => -1, 'shr' => -1];
                register_shutdown_function(function() use ($_filesPerfStart, $_filesPerfLogFile, &$_filesPerfMeta) {
                    $_elapsed = (microtime(true) - $_filesPerfStart) * 1000;
                    $_path = $_GET['path'] ?? '';
                    @file_put_contents($_filesPerfLogFile,
                        date('H:i:s') . sprintf(' %7.1fms sid=%d type=%-6s remote=%s count=%d [hls=%d perm=%d lfcall=%d vchk=%d pfilt=%d shr=%d] path=%s',
                            $_elapsed, $_filesPerfMeta['sid'], $_filesPerfMeta['type'],
                            $_filesPerfMeta['remote'], $_filesPerfMeta['count'],
                            $_filesPerfMeta['hls'], $_filesPerfMeta['perm'], $_filesPerfMeta['lfcall'],
                            $_filesPerfMeta['vchk'], $_filesPerfMeta['pfilt'], $_filesPerfMeta['shr'], $_path) . "\n",
                        FILE_APPEND);
                });
            }
            // HLS 세션 자동 정리 (1% 확률 — 새로고침/뒤로가기로 stop 안 간 경우 대비)
            if (mt_rand(1, 100) === 1) {
                _sessionDebugLog('STEP:files_hls_cleanup_start');
                $fileManager->hlsCleanupStale();
                _sessionDebugLog('STEP:files_hls_cleanup_done');
                if ($_filesPerfMeta !== null) { $_filesPerfMeta['hls'] = (int)round((microtime(true) - $_phT) * 1000); $_phT = microtime(true); }
            }
            // vault 임시 파일 자동 정리는 공통 영역에서 처리 (아래 참조)
            @set_time_limit(120); // 원격 스토리지 타임아웃 안전장치
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $requestPath = $_GET['path'] ?? '';
            
            // 스토리지 보기 권한 체크: 웹 표시 권한 없으면 접근 불가
            if (!$storage->checkPermission($storageId, 'can_visible')) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                break;
            }
            
            // 폴더별 보기 권한 체크: URL로 직접 접근 방지
            if ($requestPath !== '' && !$storage->checkFolderPermission($storageId, $requestPath, 'can_visible')) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                break;
            }
            // 폴더별 읽기 권한 체크: 읽기 권한 없으면 파일 목록도 차단
            if ($requestPath !== '' && !$storage->checkFolderPermission($storageId, $requestPath, 'can_read')) {
                $result = ['success' => false, 'error' => __('no_read_permission', '이 폴더에 대한 읽기 권한이 없습니다.')];
                break;
            }
            _sessionDebugLog('STEP:files_perm_ok', 'sid=' . $storageId . ' path=' . substr($requestPath, 0, 80));
            if ($_filesPerfMeta !== null) { $_filesPerfMeta['perm'] = (int)round((microtime(true) - $_phT) * 1000); $_phT = microtime(true); }
            
            $_lfCallT0 = ($_filesPerfMeta !== null) ? microtime(true) : 0;  // listFiles 호출 전체 시간(iterator 前 is_dir/realpath 포함)
            $result = $fileManager->listFiles(
                $storageId,
                $requestPath
            );
            _sessionDebugLog('STEP:files_list_done', 'count=' . (isset($result['items']) ? count($result['items']) : 0));
            // files_perf.log 활성 시에만 메타 수집 (OFF면 비용 0)
            if ($_filesPerfMeta !== null) {
                $_fpInfo = $storage->getStorageById($storageId);
                $_filesPerfMeta['sid'] = $storageId;
                $_filesPerfMeta['type'] = $_fpInfo['storage_type'] ?? '?';
                $_filesPerfMeta['remote'] = $fileManager->isRemoteStorage($storageId) ? 'Y' : 'N';
                $_filesPerfMeta['count'] = isset($result['items']) ? count($result['items']) : -1;
                $_filesPerfMeta['lfcall'] = (int)round((microtime(true) - $_lfCallT0) * 1000);
                $_phT = microtime(true);  // 위상타이머 정렬 (이후 vchk 계측 기준)
            }
            
            // 1% 확률로 오래된 청크 정리
            if (mt_rand(1, 100) === 1) {
                $fileManager->cleanupOldChunks();
            }            // 정렬 적용
            if ($result['success'] && isset($result['items'])) {
                // 현재 폴더가 vault인지 확인
                $currentPath = $_GET['path'] ?? '';
                $realBasePath = $storage->getRealPath($storageId);
                if ($realBasePath && $currentPath) {
                    $currentFullPath = $realBasePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $currentPath);
                    if (file_exists($currentFullPath . DIRECTORY_SEPARATOR . '.vault.json')) {
                        $result['is_vault_folder'] = true;
                    }
                }
                
                if ($_filesPerfMeta !== null) { $_filesPerfMeta['vchk'] = (int)round((microtime(true) - $_phT) * 1000); $_phT = microtime(true); }
                
                // 폴더별 권한 필터링
                $currentPath = $_GET['path'] ?? '';
                $result['items'] = $storage->filterByFolderPermission($storageId, $currentPath, $result['items']);
                if ($_filesPerfMeta !== null) { $_filesPerfMeta['pfilt'] = (int)round((microtime(true) - $_phT) * 1000); $_phT = microtime(true); }
                
                $sortBy = $_GET['sort'] ?? 'name';
                $sortOrder = $_GET['order'] ?? 'asc';
                $result['items'] = $fileManager->sortFiles($result['items'], $sortBy, $sortOrder);
                
                // 공유 정보 추가
                $shares = $db->load('shares');
                $sharedPaths = [];
                // ★ (2026-08-15) 만료·다운로드 초과 공유는 '공유됨'으로 보지 않는다.
                //   기존에는 is_active만 봐서, 만료된 공유도 목록에서 shared=true로 내려가
                //   파일에 🔗 배지가 계속 붙었다(새로고침해도 유지). 실제 삭제는
                //   ShareManager::cleanupExpiredShares()가 getShares() 호출 시에만 돌기 때문에
                //   '내 공유 링크' 모달을 열기 전까지는 유령 배지가 남는 구조였다.
                //   판정 기준은 share_count(위 case)와 동일하게 맞춘다.
                $_shNow = time();
                foreach ($shares as $share) {
                    if (!empty($share['expire_at']) && strtotime($share['expire_at']) < $_shNow) continue;
                    if (!empty($share['max_downloads']) && ($share['download_count'] ?? 0) >= $share['max_downloads']) continue;
                    if (($share['storage_id'] ?? 0) == $storageId && ($share['is_active'] ?? 0)) {
                        $sharedPaths[$share['file_path']] = [
                            'token' => $share['token'],
                            'share_type' => $share['share_type'] ?? 'download',
                            'expire_at' => $share['expire_at'] ?? null
                        ];
                    }
                }
                
                foreach ($result['items'] as &$item) {
                    $relativePath = ltrim($item['path'] ?? '', '/');
                    if (isset($sharedPaths[$relativePath])) {
                        $item['shared'] = true;
                        $item['share_token'] = $sharedPaths[$relativePath]['token'];
                        $item['share_type'] = $sharedPaths[$relativePath]['share_type'];
                        $item['share_expire'] = $sharedPaths[$relativePath]['expire_at'];
                    }
                }
                unset($item);
                if ($_filesPerfMeta !== null) { $_filesPerfMeta['shr'] = (int)round((microtime(true) - $_phT) * 1000); }
            }
            break;
        
        // ===== 용량 체크 =====
        case 'check_quota':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $size = (int)($input['size'] ?? 0);
            $result = $fileManager->checkQuotaPublic($storageId, $size);
            break;
            
        case 'upload':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            // 폴더별 쓰기 권한 체크
            { $upSid = (int)($_POST['storage_id'] ?? 0); $upPath = $_POST['path'] ?? '';
              if (!$storage->checkFolderPermission($upSid, $upPath, 'can_write')) {
                  $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')]; break; }
            }
            if (empty($_FILES['file'])) {
                $result = ['success' => false, 'error' => __('api_err_no_file', '파일이 없습니다.')];
            } else {
                $result = $fileManager->upload(
                    (int)($_POST['storage_id'] ?? 0),
                    $_POST['path'] ?? '',
                    $_FILES['file']
                );
            }
            break;
            
        case 'upload_chunk':
            $auth->requireLogin();
            session_write_close();
            
            // 폴더별 쓰기 권한 체크
            { $ucSid = (int)($_POST['storage_id'] ?? 0); $ucPath = $_POST['path'] ?? '';
              if (!$storage->checkFolderPermission($ucSid, $ucPath, 'can_write')) {
                  $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')]; break; }
            }
            
            if (empty($_FILES['chunk'])) {
                $result = ['success' => false, 'error' => __('api_err_no_chunks', '청크가 없습니다.')];
            } else {
                $result = $fileManager->uploadChunk(
                    (int)($_POST['storage_id'] ?? 0),
                    $_POST['path'] ?? '',
                    [
                        'filename' => $_POST['filename'] ?? '',
                        'chunkIndex' => $_POST['chunkIndex'] ?? 0,
                        'totalChunks' => $_POST['totalChunks'] ?? 1,
                        'totalSize' => $_POST['totalSize'] ?? 0,
                        'uploadId' => $_POST['uploadId'] ?? '',
                        'lastModified' => $_POST['lastModified'] ?? 0,
                        'relativePath' => $_POST['relativePath'] ?? null,
                        'duplicateAction' => $_POST['duplicateAction'] ?? 'rename',
                        'file' => $_FILES['chunk']
                    ]
                );
                
                // 업로드 완료 시 로그 및 인덱스 갱신
                if (($result['success'] ?? false) && ($result['complete'] ?? false)) {
                    $storageInfo = $storage->getStorageById((int)($_POST['storage_id'] ?? 0));
                    
                    $activityLog->log(ActivityLog::TYPE_UPLOAD, [
                        'storage_id' => (int)($_POST['storage_id'] ?? 0),
                        'storage_name' => $storageInfo['name'] ?? '',
                        'path' => ($_POST['path'] ?? '') . '/' . ($_POST['filename'] ?? ''),
                        'filename' => $_POST['filename'] ?? '',
                        'size' => (int)($_POST['totalSize'] ?? 0)
                    ]);
                    
                    // 자동 인덱스 갱신 (uploadChunk 내부에서 이미 처리됨 - 중복 방지)
                    // uploadChunk가 fileIndex->addFile을 올바른 경로로 호출하므로 여기서는 생략
                    
                    // 1% 확률로 오래된 청크 정리 (24시간 이상)
                    if (mt_rand(1, 100) === 1) {
                        $fileManager->cleanupOldChunks();
                    }
                    
                }
            }
            break;
            
        case 'download':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제 (Range 요청 병렬 처리)
            _sessionDebugLog('STEP:download_after_swc');
            $inline = isset($_GET['inline']) && $_GET['inline'] === '1';
            
            // 폴더별 권한 체크
            { $dlSid = (int)($_GET['storage_id'] ?? 0); $dlPath = $_GET['path'] ?? '';
              $dlDir = dirname($dlPath); if ($dlDir === '.') $dlDir = '';
              if (!$storage->checkFolderPermission($dlSid, $dlDir ?: $dlPath)) {
                  http_response_code(403); exit(__('no_permission_dot', '권한이 없습니다.')); }
            }
            _sessionDebugLog('STEP:download_perm_ok');
            
            // 다운로드 로그 기록 (미리보기 제외)
            if (!$inline) {
                $storageId = (int)($_GET['storage_id'] ?? 0);
                $path = $_GET['path'] ?? '';
                $storageInfo = $storage->getStorageById($storageId);
                $realPath = $storage->getRealPath($storageId);
                $fullPath = $realPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
                $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;
                
                $activityLog->log(ActivityLog::TYPE_DOWNLOAD, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $path,
                    'filename' => basename($path),
                    'size' => $fileSize
                ]);
                _sessionDebugLog('STEP:download_activitylog_done');
            }
            
            // QoS 다운로드 속도 제한 가져오기
            $user = $auth->getUser();
            $downloadLimit = getUserQosLimit($user, 'download');
            _sessionDebugLog('STEP:download_before_transfer', 'inline=' . ($inline ? '1' : '0'));
            
            $fileManager->download(
                (int)($_GET['storage_id'] ?? 0),
                $_GET['path'] ?? '',
                $inline,
                $downloadLimit
            );
            _sessionDebugLog('STEP:download_transfer_done');
            break;
        
        case 'transcode':
            $auth->requireLogin();
            session_write_close();
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            // 폴더별 권한 체크
            $tcDir = dirname($path);
            if ($tcDir === '.') $tcDir = '';
            if (!$storage->checkFolderPermission($storageId, $tcDir ?: $path)) {
                http_response_code(403);
                echo json_encode(['error' => 'No permission']);
                break;
            }
            $fileManager->transcodeStream($storageId, $path);
            break;
        
        case 'convert_h264':
            // 동영상을 H264/MP4로 영구 변환 (SSE 진행률)
            $auth->requireLogin();
            session_write_close();
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            // 폴더별 권한 체크 — 변환은 파일 생성(+원본 휴지통 이동)하는 쓰기 작업이므로 can_write 필요
            // (compress/rename 등 다른 쓰기 작업과 동일. transcode는 읽기 스트리밍이라 can_read였음)
            $cvDir = dirname($path);
            if ($cvDir === '.') $cvDir = '';
            if (!$storage->checkFolderPermission($storageId, $cvDir ?: $path, 'can_write')) {
                http_response_code(403);
                echo json_encode(['error' => 'No permission']);
                break;
            }
            // deleteOriginal: 원본 휴지통 이동 여부 (1=이동). 기본 false(안전)
            $cvDelete = isset($_GET['delete_original']) && $_GET['delete_original'] === '1';
            $fileManager->convertToH264Mp4($storageId, $path, $cvDelete);
            break;
        
        case 'media_info':
            // 동영상 코덱 정보 조회 (네이티브 재생 가능 여부 판단용)
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            _sessionDebugLog('STEP:mediainfo_after_swc');
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            // 폴더별 권한 체크
            $miDir = dirname($path);
            if ($miDir === '.') $miDir = '';
            if (!$storage->checkFolderPermission($storageId, $miDir ?: $path)) {
                $result = ['success' => false, 'error' => 'No permission'];
                break;
            }
            _sessionDebugLog('STEP:mediainfo_before_probe');
            $result = $fileManager->getMediaInfo($storageId, $path);
            _sessionDebugLog('STEP:mediainfo_probe_done');
            break;
        
        case 'audio_durations':
            // 오디오 파일 duration 일괄 조회 (플레이리스트 로딩 최적화)
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? $_GET['storage_id'] ?? 0);
            $folderPath = $input['path'] ?? $_GET['path'] ?? '';
            if (!$storage->checkFolderPermission($storageId, $folderPath)) {
                $result = ['success' => false, 'error' => 'No permission'];
                break;
            }
            // 원격 스토리지 최적화: 클라이언트가 이미 가진 파일 목록 재사용
            $clientFiles = [];
            $filesData = $input['files'] ?? '';
            if (is_string($filesData) && $filesData) {
                $decoded = @json_decode($filesData, true);
                if (is_array($decoded)) $clientFiles = $decoded;
            } else if (is_array($filesData)) {
                $clientFiles = $filesData;
            }
            // 세션 락 해제 (장시간 파싱 중 다른 요청 블로킹 방지)
            @session_write_close();
            $result = $fileManager->getAudioDurations($storageId, $folderPath, $clientFiles);
            break;
        
        case 'save_audio_durations':
            // 클라이언트가 측정한 duration을 캐시에 저장 (원격 스토리지용)
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? $_GET['storage_id'] ?? 0);
            $itemsData = $input['items'] ?? '';
            if (!$storage->checkPermission($storageId, 'can_read')) {
                $result = ['success' => false, 'error' => 'No permission'];
                break;
            }
            // 세션 락 해제
            @session_write_close();
            $items = [];
            if (is_string($itemsData) && $itemsData) {
                $decoded = @json_decode($itemsData, true);
                if (is_array($decoded)) $items = $decoded;
            } else if (is_array($itemsData)) {
                $items = $itemsData;
            }
            $result = $fileManager->saveAudioDurations($storageId, $items);
            break;
        
        case 'hls_stream':
            $auth->requireLogin();
            session_write_close();
            _sessionDebugLog('STEP:hls_after_swc', 'hls_action=' . ($_GET['hls_action'] ?? 'start'));
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            // 폴더별 권한 체크
            $hlsDir = dirname($path);
            if ($hlsDir === '.') $hlsDir = '';
            if (!$storage->checkFolderPermission($storageId, $hlsDir ?: $path)) {
                http_response_code(403);
                echo json_encode(['error' => 'No permission']);
                break;
            }
            // 오래된 HLS 세션 정리 (stop 요청 시 항상, 그 외 20% 확률)
            $hlsAction = $_GET['hls_action'] ?? 'start';
            if ($hlsAction === 'stop' || mt_rand(1, 5) === 1) {
                _sessionDebugLog('STEP:hls_cleanup_start');
                $fileManager->hlsCleanupStale();
                _sessionDebugLog('STEP:hls_cleanup_done');
            }
            _sessionDebugLog('STEP:hls_before_stream');
            $fileManager->hlsStream($storageId, $path);
            _sessionDebugLog('STEP:hls_stream_done');
            break;
        
        case 'transcode_log':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
            $logFile = $dataDir . '/ffmpeg_pipe_stderr.log';
            header('Content-Type: text/plain; charset=utf-8');
            if (is_file($logFile)) {
                echo file_get_contents($logFile);
            } else {
                echo 'No log file';
            }
            break;
        
        case 'mms_debug_log':
            $auth->requireAdmin();
            $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
            $logFile = $dataDir . '/mms_debug.log';
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                try {
                    $log = $_POST['log'] ?? ($input['log'] ?? '');
                    if ($log) {
                        if (is_file($logFile) && filesize($logFile) > 1048576) {
                            file_put_contents($logFile, '');
                        }
                        @file_put_contents($logFile, $log . "\n", FILE_APPEND | LOCK_EX);
                    }
                } catch (Exception $e) {}
                $result = ['success' => true]; break;
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                echo is_file($logFile) ? file_get_contents($logFile) : 'No MMS log';
                exit;
            }
            break;
        
        // ★ 펜닐님 진단용 (임시 — 멀티오디오 회귀 추적): 다음 디버깅 위해 보존
        //    동영상 폴더 재생 + 음악 공유 폴더 + HLS + 멀티오디오 이벤트 모두 한 로그에 기록
        //    클라이언트에서 `App._diagLog('event', {...})` 호출 시 서버 파일에 시간순 누적
        //    diag_log_clear=1 GET 파라미터로 로그 비우기 가능
        case 'hls_diag_log':
            $auth->requireLogin();
            $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
            $logFile = $dataDir . '/hls_diag.log';
            if (!empty($_GET['clear']) && $_GET['clear'] === '1') {
                @file_put_contents($logFile, '');
                $result = ['success' => true, 'cleared' => true]; break;
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                try {
                    $event = $_POST['event'] ?? ($input['event'] ?? '');
                    $data = $_POST['data'] ?? ($input['data'] ?? '');
                    if ($event !== '') {
                        // 로그 5MB 초과 시 자동 비움 (펜닐님 룰: 무한 누적 방지)
                        if (is_file($logFile) && filesize($logFile) > 5242880) {
                            @file_put_contents($logFile, '');
                        }
                        $line = '[' . date('Y-m-d H:i:s') . '] ' . $event;
                        if ($data !== '') $line .= ' | ' . $data;
                        @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
                    }
                } catch (Exception $e) {}
                $result = ['success' => true]; break;
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                echo is_file($logFile) ? file_get_contents($logFile) : 'No diag log';
                exit;
            }
            break;
        
        // ★ 커버 진단 로그 (펜닐님 임시 진단용 — iOS 음악 썸네일 누락 디버깅)
        //   GET ?clear=1 : 로그 비우기
        //   GET (기본): 로그 내용 반환 (text/plain)
        //   POST: event + data 기록
        case 'cover_diag_log':
            $auth->requireLogin();
            $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
            $logFile = $dataDir . '/cover_diag.log';
            if (!empty($_GET['clear']) && $_GET['clear'] === '1') {
                @file_put_contents($logFile, '');
                $result = ['success' => true, 'cleared' => true]; break;
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                try {
                    $event = $_POST['event'] ?? ($input['event'] ?? '');
                    $data = $_POST['data'] ?? ($input['data'] ?? '');
                    if ($event !== '') {
                        // 로그 10MB 초과 시 자동 비움
                        if (is_file($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
                            @file_put_contents($logFile, '');
                        }
                        $line = '[' . date('Y-m-d H:i:s.') . substr((string)microtime(true), -4) . '] ' . $event;
                        if ($data !== '') $line .= ' | ' . $data;
                        @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
                    }
                } catch (Exception $e) {}
                $result = ['success' => true]; break;
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                echo is_file($logFile) ? file_get_contents($logFile) : 'No cover diag log';
                exit;
            }
            break;
        
        case 'thumbnail':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제 (그리드 뷰 썸네일 병렬 로딩)
            _sessionDebugLog('STEP:thumb_after_swc');
            // 썸네일 활성화 체크
            $thumbSettings = loadSiteSettings();
            if (isset($thumbSettings['thumbnail_enabled']) && $thumbSettings['thumbnail_enabled'] === false) {
                http_response_code(404);
                exit;
            }
            // 썸네일은 이미지 바이너리 출력이므로 에러 핸들러 변경
            restore_error_handler();
            set_error_handler(function($errno, $errstr) { return true; }); // 에러 무시
            set_exception_handler(function($e) {
                @file_put_contents(__DIR__ . '/data/thumbcache/last_error.log', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
                http_response_code(500);
                exit;
            });
            $thumbSize = $thumbSettings['thumbnail_size'] ?? ((int)($_GET['size'] ?? 200));
            // Base64 인코딩된 경로 디코딩
            $thumbPath = $_GET['path'] ?? '';
            if (isset($_GET['enc']) && $_GET['enc'] === 'b64') {
                $thumbPath = base64_decode($thumbPath);
                if ($thumbPath === false) {
                    http_response_code(400);
                    exit;
                }
            }
            // 폴더별 권한 체크
            $thumbDir = dirname($thumbPath);
            if ($thumbDir === '.') $thumbDir = '';
            if (!$storage->checkFolderPermission((int)($_GET['storage_id'] ?? 0), $thumbDir ?: $thumbPath)) {
                http_response_code(403);
                exit;
            }
            _sessionDebugLog('STEP:thumb_before_generate');
            $fileManager->thumbnail(
                (int)($_GET['storage_id'] ?? 0),
                $thumbPath,
                $thumbSize
            );
            _sessionDebugLog('STEP:thumb_generate_done');
            break;
        
        case 'audio_cover':
            // MP3 파일의 ID3v2 APIC 프레임에서 커버 이미지 추출
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제 (플레이리스트 병렬 로딩 시)
            
            // ★ 출력 버퍼 정리 + PHP/api.php 자동 캐시 헤더 제거
            //    api.php 시작에서 'Cache-Control: no-cache, no-store, must-revalidate' 설정됨
            //    session_start()도 자동으로 'Cache-Control: max-age=0, private, must-revalidate' 추가
            //    이 헤더들이 audioCover의 우리 캐시 헤더를 덮어쓰므로 명시 제거
            while (ob_get_level()) ob_end_clean();
            header_remove('Cache-Control');
            header_remove('Expires');
            header_remove('Pragma');
            
            // 에러 핸들러 변경 (바이너리 응답이므로)
            restore_error_handler();
            set_error_handler(function($errno, $errstr) { return true; });
            set_exception_handler(function($e) {
                // 관리자 디버그용 로그 (thumbnail과 동일한 형식/위치/덮어쓰기 방식)
                // 덮어쓰기: 가장 최근 에러만 유지 (로그 파일 무한 증가 방지)
                @file_put_contents(
                    __DIR__ . '/data/thumbcache/last_error.log',
                    date('Y-m-d H:i:s') . ' [audio_cover] ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString()
                );
                http_response_code(500);
                exit;
            });
            // Base64 인코딩된 경로 디코딩 (썸네일 패턴과 동일)
            $coverPath = $_GET['path'] ?? '';
            if (isset($_GET['enc']) && $_GET['enc'] === 'b64') {
                $coverPath = base64_decode($coverPath);
                if ($coverPath === false) {
                    http_response_code(400);
                    exit;
                }
            }
            // 폴더별 권한 체크
            $coverDir = dirname($coverPath);
            if ($coverDir === '.') $coverDir = '';
            if (!$storage->checkFolderPermission((int)($_GET['storage_id'] ?? 0), $coverDir ?: $coverPath)) {
                http_response_code(403);
                exit;
            }
            $fileManager->audioCover(
                (int)($_GET['storage_id'] ?? 0),
                $coverPath
            );
            break;
            
        case 'audio_lyrics':
            // 오디오 가사 (LRC > USLT > TXT 우선순위)
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제 (병렬 트랙 로딩 시)
            
            // Base64 인코딩된 경로 디코딩 (audio_cover 패턴 동일)
            $lyricsPath = $_GET['path'] ?? '';
            if (isset($_GET['enc']) && $_GET['enc'] === 'b64') {
                $lyricsPath = base64_decode($lyricsPath);
                if ($lyricsPath === false) {
                    http_response_code(400);
                    exit;
                }
            }
            
            // 폴더별 권한 체크
            $lyricsDir = dirname($lyricsPath);
            if ($lyricsDir === '.') $lyricsDir = '';
            if (!$storage->checkFolderPermission((int)($_GET['storage_id'] ?? 0), $lyricsDir ?: $lyricsPath)) {
                http_response_code(403);
                exit;
            }
            
            $lyricsResult = $fileManager->getAudioLyrics(
                (int)($_GET['storage_id'] ?? 0),
                $lyricsPath
            );
            
            // 출력 버퍼 정리 + 캐시 헤더 제거
            while (ob_get_level()) ob_end_clean();
            header_remove('Cache-Control');
            header_remove('Expires');
            header_remove('Pragma');
            
            if (!$lyricsResult) {
                http_response_code(204);  // 가사 없음 (조용히 fallback)
                header('Cache-Control: public, max-age=300');
                exit;
            }
            
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            echo json_encode([
                'source' => $lyricsResult['source'],
                'synced' => $lyricsResult['synced'],
                'text' => $lyricsResult['text'],
                'language' => $lyricsResult['language'] ?? null,
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'mkdir':
            $auth->requireLogin();
            // 폴더별 쓰기 권한 체크
            if (!$storage->checkFolderPermission((int)($input['storage_id'] ?? 0), $input['path'] ?? '', 'can_write')) {
                $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')]; break;
            }
            $result = $fileManager->createFolder(
                (int)($input['storage_id'] ?? 0),
                $input['path'] ?? '',
                $input['name'] ?? ''
            );
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById((int)($input['storage_id'] ?? 0));
                $activityLog->log(ActivityLog::TYPE_CREATE_FOLDER, [
                    'storage_id' => (int)($input['storage_id'] ?? 0),
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => ($input['path'] ?? '') . '/' . ($input['name'] ?? ''),
                    'filename' => $input['name'] ?? ''
                ]);
                
                // 인덱스 갱신
                $fileIndex = FileIndex::getInstance();
                if ($fileIndex->isAvailable()) {
                        $storageId = (int)($input['storage_id'] ?? 0);
                        $filepath = trim(($input['path'] ?? '') . '/' . ($input['name'] ?? ''), '/');
                        $fileIndex->addFile($storageId, $filepath, [
                            'name' => $input['name'] ?? '',
                            'size' => 0,
                            'modified' => time(),
                            'is_dir' => true
                        ]);
                }
            }
            break;
            
        // ★ 새 파일 만들기 — 서버 템플릿 복사 (펜닐 v5.8.1e)
        //   docx/xlsx/pptx/hwp 빈 템플릿 복사 (파일 내용 자체는 templates/ 디렉토리)
        //   txt/md는 클라이언트에서 빈 Blob → upload API 사용 (이 액션 X)
        case 'create_from_template':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $relativePath = $input['path'] ?? '';
            $fileName = $input['name'] ?? '';
            $template = $input['template'] ?? '';
            
            // 폴더별 쓰기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $relativePath, 'can_write')) {
                $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')]; break;
            }
            
            // 허용된 템플릿만 처리 (whitelist)
            $allowedTemplates = ['docx', 'xlsx', 'pptx', 'hwp'];
            if (!in_array($template, $allowedTemplates, true)) {
                $result = ['success' => false, 'error' => 'Invalid template type']; break;
            }
            
            // 파일명 보안 검사
            if (empty($fileName) || preg_match('/[\\\\\\/:\*\?"<>\|]/', $fileName) || strpos($fileName, '..') !== false || $fileName[0] === '.') {
                $result = ['success' => false, 'error' => __('invalid_filename', '파일 이름이 잘못되었습니다.')]; break;
            }
            
            // 확장자 일치 검사
            $expectedExt = '.' . $template;
            if (substr(strtolower($fileName), -strlen($expectedExt)) !== $expectedExt) {
                $fileName .= $expectedExt;
            }
            
            // 템플릿 파일 경로 확인
            $templatePath = __DIR__ . '/templates/empty.' . $template;
            if (!is_file($templatePath)) {
                $result = ['success' => false, 'error' => 'Template file not found: ' . $template]; break;
            }
            
            // ★ Quota 검사 (펜닐 v5.8.1e — 일반 업로드와 동일하게 한도 체크)
            //   템플릿 파일 사이즈는 4~36KB로 작지만, 한도 도달 사용자가 무한 생성 못하도록 차단
            $_templateSize = filesize($templatePath);
            if ($_templateSize !== false) {
                $_quotaCheck = $fileManager->checkQuotaPublic($storageId, $_templateSize);
                if (!($_quotaCheck['allowed'] ?? true)) {
                    $result = ['success' => false, 'error' => $_quotaCheck['error'] ?? __('quota_exceeded', '용량 한도를 초과했습니다.')]; break;
                }
            }
            
            // 대상 경로 결정
            $basePath = $storage->getRealPath($storageId);
            if (empty($basePath) && !$fileManager->isRemoteStorage($storageId)) {
                $result = ['success' => false, 'error' => __('storage_path_invalid', '스토리지 경로가 잘못되었습니다.')]; break;
            }
            
            // 원격 스토리지면 어댑터 사용 (어댑터의 write 메서드)
            if ($fileManager->isRemoteStorage($storageId)) {
                $storageInfo = $storage->getStorageById($storageId);
                $adapter = StorageAdapterFactory::create($storageInfo);
                if (!$adapter || !$adapter->connect()) {
                    $result = ['success' => false, 'error' => __('adapter_connect_failed', '원격 스토리지 연결 실패')]; break;
                }
                // ★ Windows 탐색기 방식: 중복 시 (2), (3) 자동 증가 (펜닐 v5.8.1e)
                $remoteFullPath = ltrim($relativePath . '/' . $fileName, '/');
                if ($adapter->exists($remoteFullPath)) {
                    // 확장자 분리해서 (n) 추가
                    $dotPos = strrpos($fileName, '.');
                    $namePart = $dotPos !== false ? substr($fileName, 0, $dotPos) : $fileName;
                    $extPart  = $dotPos !== false ? substr($fileName, $dotPos) : '';
                    // 이미 (n) 패턴 있으면 그 부분 제거 후 새로 시도
                    $namePart = preg_replace('/ \(\d+\)$/u', '', $namePart);
                    for ($n = 2; $n <= 999; $n++) {
                        $tryName = $namePart . ' (' . $n . ')' . $extPart;
                        $tryRemote = ltrim($relativePath . '/' . $tryName, '/');
                        if (!$adapter->exists($tryRemote)) {
                            $fileName = $tryName;
                            $remoteFullPath = $tryRemote;
                            break;
                        }
                    }
                    if ($adapter->exists($remoteFullPath)) {
                        $adapter->disconnect();
                        $result = ['success' => false, 'error' => __('file_already_exists', '같은 이름의 파일이 너무 많습니다.')]; break;
                    }
                }
                $templateContent = file_get_contents($templatePath);
                $writeOk = $adapter->write($remoteFullPath, $templateContent);
                $adapter->disconnect();
                if (!$writeOk) {
                    $result = ['success' => false, 'error' => __('error_create_file', '파일 생성에 실패했습니다.')]; break;
                }
                $result = ['success' => true, 'name' => $fileName];
            } else {
                // 로컬 (또는 UNC + local 등록): 직접 복사
                $targetDir = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
                $targetFullPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
                
                // path traversal 방어
                $realBase = realpath($basePath);
                $realTargetDir = realpath($targetDir);
                if ($realBase && $realTargetDir && strpos($realTargetDir, $realBase) !== 0) {
                    $result = ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')]; break;
                }
                
                // ★ Windows 탐색기 방식: 중복 시 (2), (3) 자동 증가 (펜닐 v5.8.1e)
                if (file_exists($targetFullPath)) {
                    $dotPos = strrpos($fileName, '.');
                    $namePart = $dotPos !== false ? substr($fileName, 0, $dotPos) : $fileName;
                    $extPart  = $dotPos !== false ? substr($fileName, $dotPos) : '';
                    $namePart = preg_replace('/ \(\d+\)$/u', '', $namePart);
                    for ($n = 2; $n <= 999; $n++) {
                        $tryName = $namePart . ' (' . $n . ')' . $extPart;
                        $tryFull = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tryName;
                        if (!file_exists($tryFull)) {
                            $fileName = $tryName;
                            $targetFullPath = $tryFull;
                            break;
                        }
                    }
                    if (file_exists($targetFullPath)) {
                        $result = ['success' => false, 'error' => __('file_already_exists', '같은 이름의 파일이 너무 많습니다.')]; break;
                    }
                }
                
                if (!@copy($templatePath, $targetFullPath)) {
                    $result = ['success' => false, 'error' => __('error_create_file', '파일 생성에 실패했습니다.')]; break;
                }
                
                $result = ['success' => true, 'name' => $fileName];
            }
            
            // 활동 로그 + 인덱스 갱신
            if ($result['success'] ?? false) {
                $storageInfo2 = $storage->getStorageById($storageId);
                $activityLog->log(ActivityLog::TYPE_UPLOAD ?? 'upload', [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo2['name'] ?? '',
                    'path' => trim($relativePath . '/' . $fileName, '/'),
                    'filename' => $fileName,
                    'note' => 'created from template: ' . $template
                ]);
                
                $fileIndex2 = FileIndex::getInstance();
                if ($fileIndex2->isAvailable()) {
                    $filepath2 = trim($relativePath . '/' . $fileName, '/');
                    $fileIndex2->addFile($storageId, $filepath2, [
                        'name' => $fileName,
                        'size' => filesize($templatePath),
                        'modified' => time(),
                        'is_dir' => false
                    ]);
                }
            }
            break;
            
        case 'delete':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            
            // 빈 경로 삭제 차단 (스토리지 루트 삭제 방지)
            if (empty($path) || $path === '/' || $path === '\\') {
                $result = ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
                break;
            }
            
            // 폴더별 삭제 권한 체크
            { $delDir = dirname($path); if ($delDir === '.') $delDir = '';
              if (!$storage->checkFolderPermission($storageId, $delDir ?: $path, 'can_delete')) {
                  $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break; }
            }
            
            $storageInfo = $storage->getStorageById($storageId);
            
            // 삭제 전에 폴더인지 확인
            $realPath = $storage->getRealPath($storageId);
            $fullPath = $realPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            $wasDir = is_dir($fullPath);
            
            session_write_close(); // 세션 락 해제
            $permanent = !empty($input['permanent']);
            $result = $fileManager->delete($storageId, $path, '', $permanent);
            
            if ($result['success'] ?? false) {
                $activityLog->log(ActivityLog::TYPE_DELETE, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $path,
                    'filename' => basename($path)
                ]);
                
                // 삭제된 폴더의 폴더별 권한 레코드 정리
                if ($wasDir) {
                    $folderPerms = $storage->getAllFolderPermissions($storageId);
                    $cleanPath = trim(str_replace('\\', '/', $path), '/');
                    $changed = false;
                    foreach ($folderPerms as $idx => $fp) {
                        $fpPath = trim($fp['folder_path'] ?? '', '/');
                        // 정확히 일치하거나 하위 경로인 경우 제거
                        if ($fpPath === $cleanPath || strpos($fpPath . '/', $cleanPath . '/') === 0 || strpos($fpPath, $cleanPath . '/') === 0) {
                            $storage->removeFolderPermission($storageId, $fp['folder_path'], (int)($fp['user_id'] ?? 0));
                            $changed = true;
                        }
                    }
                }
                
                // 인덱스 갱신
                $fileIndex = FileIndex::getInstance();
                if ($fileIndex->isAvailable()) {
                        if ($wasDir) {
                            $fileIndex->removeFolder($storageId, $path);
                        } else {
                            $fileIndex->removeFile($storageId, $path);
                        }
                }
            }
            break;
        
        case 'file_text_save':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            // 폴더별 쓰기 권한 체크
            { $ftsDir = dirname($path); if ($ftsDir === '.') $ftsDir = '';
              if (!$storage->checkFolderPermission($storageId, $ftsDir ?: $path, 'can_write')) {
                  $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')]; break; }
            }
            $content = $input['content'] ?? ''; // 클라이언트에서 항상 UTF-8로 전송
            $encoding = $input['encoding'] ?? 'utf-8';
            
            // 인코딩 변환 (클라이언트는 UTF-8로 전송, 서버에서 원본 인코딩으로 복원)
            $allowedEncodings = ['utf-8', 'utf-8-bom', 'utf-16le', 'utf-16be', 'euc-kr'];
            if (!in_array($encoding, $allowedEncodings)) $encoding = 'utf-8';
            
            switch ($encoding) {
                case 'utf-8-bom':
                    $content = "\xEF\xBB\xBF" . $content;
                    break;
                case 'utf-16le':
                    $content = "\xFF\xFE" . mb_convert_encoding($content, 'UTF-16LE', 'UTF-8');
                    break;
                case 'utf-16be':
                    $content = "\xFE\xFF" . mb_convert_encoding($content, 'UTF-16BE', 'UTF-8');
                    break;
                case 'euc-kr':
                    $content = mb_convert_encoding($content, 'EUC-KR', 'UTF-8');
                    break;
                // utf-8: 변환 불필요
            }
            
            if (!$storage->checkPermission($storageId, 'can_write')) {
                $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')];
                break;
            }
            
            // 텍스트 편집 가능 확장자 제한
            $editableExts = ['txt', 'md', 'json', 'xml', 'xsl', 'yaml', 'yml', 'ini', 'conf', 'cfg',
                'sh', 'bash', 'bat', 'ps1', 'cmd', 'vbs', 'py', 'js', 'ts', 'tsx', 'jsx', 'vue',
                'css', 'scss', 'less', 'html', 'htm', 'php', 'rb', 'go', 'rs', 'swift', 'lua', 'pas', 'asm',
                'java', 'c', 'cc', 'cpp', 'cxx', 'cs', 'h', 'hpp', 'kt', 'gradle', 'makefile', 'dockerfile',
                'sql', 'log', 'csv', 'tsv', 'env', 'properties', 'gitignore', 'htaccess',
                'toml', 'reg', 'inf', 'srt', 'vtt', 'ass', 'ssa', 'smi', 'sub',
                'nfo', 'cue', 'm3u8', 'lst', 'rpt', '1st', 'text', 'key'];
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, $editableExts)) {
                $result = ['success' => false, 'error' => __('file_not_editable', '편집할 수 없는 파일 형식입니다.')];
                break;
            }
            
            // 위험 확장자 이중 체크
            $dangerousExts = DANGEROUS_EXTS;
            if (in_array($ext, $dangerousExts) && !$auth->isAdminOrSubAdmin()) {
                $result = ['success' => false, 'error' => __('file_edit_admin_only', '이 파일 형식은 관리자만 편집할 수 있습니다.')];
                break;
            }
            
            if ($fileManager->isRemoteStorage($storageId)) {
                $adapter = $fileManager->getAdapter($storageId);
                if ($adapter && $adapter->write($path, $content)) {
                    $result = ['success' => true, 'message' => __('file_saved', '파일이 저장되었습니다.')];
                } else {
                    $result = ['success' => false, 'error' => __('file_save_failed', '파일 저장에 실패했습니다.')];
                }
            } else {
                $basePath = $storage->getRealPath($storageId);
                if (!$basePath) {
                    $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                    break;
                }
                $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
                $dirPath = realpath(dirname($fullPath));
                $realBase = realpath($basePath);
                if (!$dirPath || !$realBase || strpos($dirPath, $realBase) !== 0) {
                    $result = ['success' => false, 'error' => __('api_err_path_escape')];
                    break;
                }
                if (file_put_contents($fullPath, $content) !== false) {
                    $result = ['success' => true, 'message' => __('file_saved', '파일이 저장되었습니다.')];
                } else {
                    $result = ['success' => false, 'error' => __('file_save_failed', '파일 저장에 실패했습니다.')];
                }
            }
            break;
            
        case 'rename':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $oldPath = $input['path'] ?? '';
            $newName = $input['new_name'] ?? '';
            // 폴더별 쓰기 권한 체크
            { $rnDir = dirname($oldPath); if ($rnDir === '.') $rnDir = '';
              if (!$storage->checkFolderPermission($storageId, $rnDir ?: $oldPath, 'can_write')) {
                  $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break; }
            }
            
            $result = $fileManager->rename($storageId, $oldPath, $newName);
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $activityLog->log(ActivityLog::TYPE_RENAME, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $oldPath,
                    'filename' => basename($oldPath),
                    'details' => '→ ' . $newName
                ]);
                
                // 인덱스 갱신
                $fileIndex = FileIndex::getInstance();
                if ($fileIndex->isAvailable()) {
                        $parentPath = dirname($oldPath);
                        $newPath = ($parentPath === '.' ? '' : $parentPath . '/') . $newName;
                        $fileIndex->moveFile($storageId, $oldPath, $newPath);
                }
            }
            break;
            
        case 'move':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $destStorageId = (int)($input['dest_storage_id'] ?? $storageId);
            $source = $input['source'] ?? '';
            $dest = $input['dest'] ?? '';
            
            // 폴더별 권한 체크 (소스: 읽기, 대상: 쓰기)
            $srcDir = dirname($source); if ($srcDir === '.') $srcDir = '';
            if (!$storage->checkFolderPermission($storageId, $srcDir ?: $source, 'can_read')
                || !$storage->checkFolderPermission($destStorageId, $dest, 'can_write')) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                break;
            }
            
            session_write_close(); // 세션 락 해제 (진행률 폴링 블로킹 방지)
            $result = $fileManager->move($storageId, $source, $destStorageId, $dest, $input['duplicate_action'] ?? 'overwrite');
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $destStorageInfo = $storage->getStorageById($destStorageId);
                $activityLog->log(ActivityLog::TYPE_MOVE, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $source,
                    'filename' => basename($source),
                    'details' => '→ ' . ($destStorageInfo['name'] ?? '') . ':' . $dest
                ]);
                
                // 인덱스 갱신
                $fileIndex = FileIndex::getInstance();
                if ($fileIndex->isAvailable()) {
                        $newPath = trim($dest . '/' . basename($source), '/');
                        // 다른 스토리지로 이동 시 인덱스 처리
                        if ($storageId !== $destStorageId) {
                            $fileIndex->removeFile($storageId, $source);
                            // 파일 정보 수집
                            $destBasePath = $storage->getRealPath($destStorageId);
                            $fullNewPath = $destBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPath);
                            $fileInfo = [
                                'is_dir' => is_dir($fullNewPath) ? 1 : 0,
                                'size' => is_file($fullNewPath) ? filesize($fullNewPath) : 0,
                                'modified' => date('Y-m-d H:i:s', filemtime($fullNewPath) ?: time())
                            ];
                            $fileIndex->addFile($destStorageId, $newPath, $fileInfo);
                        } else {
                            $fileIndex->moveFile($storageId, $source, $newPath);
                        }
                }
            }
            break;
        
        // ===== 압축/압축해제 =====
        // ZIP 내부 파일 목록 조회
        // 아카이브 내부 파일 목록 조회 (7-zip 설치 시 40개+ 형식, 미설치 시 zip/tar/gz/bz2)
        case 'zip_list':
        case 'archive_list':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            @set_time_limit(300); // 대용량 아카이브 처리
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            $vaultTempId = $_GET['vault_temp_id'] ?? '';
            
            // Vault 임시파일 모드
            if ($vaultTempId) {
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $vaultTempId)) {
                    $result = ['success' => false, 'error' => 'Invalid temp ID']; break;
                }
                $tempBaseDir = DATA_PATH . DIRECTORY_SEPARATOR . 'vault_temp';
                // 확장자를 path에서 추출
                $vaultExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $tempFilePath = $tempBaseDir . DIRECTORY_SEPARATOR . $vaultTempId . '.' . $vaultExt;
                if (!file_exists($tempFilePath)) {
                    $result = ['success' => false, 'error' => 'Temp file not found']; break;
                }
                $archiveFullPath = $tempFilePath;
                $archiveSize = @filesize($archiveFullPath) ?: 0;
            } else {
            // 일반 모드
            { $zlDir = dirname($path); if ($zlDir === '.') $zlDir = '';
              if (!$storage->checkFolderPermission($storageId, $zlDir ?: $path)) {
                  $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break; }
            }
            $basePath = $storage->getRealPath($storageId);
            if (!$basePath) {
                $result = ['success' => false, 'error' => 'Invalid storage']; break;
            }
            // 디버그 로그 파일
            
            $archiveFullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            
            // 경로 정규화 (realpath는 대용량 파일에서 실패할 수 있음)
            $archiveRealPath = realpath($archiveFullPath);
            
            if (!$archiveRealPath) {
                $archiveDir = dirname($archiveFullPath);
                $archiveBase = basename($archiveFullPath);
                if (is_dir($archiveDir)) {
                    $dirFiles = @scandir($archiveDir);
                    $found = $dirFiles && in_array($archiveBase, $dirFiles);
                    if ($found) {
                        $archiveRealPath = $archiveDir . DIRECTORY_SEPARATOR . $archiveBase;
                    }
                }
                if (!$archiveRealPath) {
                    $result = ['success' => false, 'error' => 'File not found (realpath failed)']; break;
                }
            }
            // 경로 트래버설 방지
            $normalBase = str_replace('\\', '/', realpath($basePath) ?: $basePath);
            $normalArchive = str_replace('\\', '/', $archiveRealPath);
            if (strpos($normalArchive, $normalBase) !== 0) {
                $result = ['success' => false, 'error' => 'Invalid path']; break;
            }
            // 디렉토리가 아닌지 확인 (파일인지)
            if (is_dir($archiveRealPath)) {
                $result = ['success' => false, 'error' => 'Not a file']; break;
            }
            $archiveFullPath = $archiveRealPath;
            $archiveSize = @filesize($archiveFullPath) ?: 0;
            } // end 일반 모드
            
            $archiveExt = strtolower(pathinfo($archiveFullPath, PATHINFO_EXTENSION));
            $items = [];
            $isEncrypted = false;
            $method = 'none';
            $needPassword = false; // 헤더 암호화로 목록을 못 읽을 때 true
            $archivePassword = (string)($_GET['password'] ?? '');
            
            // 디버그 로그 (config.php EXTRACT_DEBUG=true 시 기록)
            $alDbgOn = (defined('EXTRACT_DEBUG') && EXTRACT_DEBUG);
            $alDbgLog = (defined('DATA_PATH') ? DATA_PATH : sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'extract_debug.log';
            $alDbg = function($msg) use ($alDbgOn, $alDbgLog) {
                if ($alDbgOn) @file_put_contents($alDbgLog, date('Y-m-d H:i:s') . ' [archive_list] ' . $msg . "\n", FILE_APPEND);
            };
            $alDbg("");
            $alDbg("======== archive_list 시작 ========");
            $alDbg("archive=$archiveFullPath / ext=$archiveExt / password=" . ($archivePassword !== '' ? '[있음 len=' . strlen($archivePassword) . ']' : '[없음]'));
            
            // 7-zip 바이너리 탐색
            $sevenZipBin = null;
            $sevenZipPaths = [
                'C:\\Program Files\\7-Zip\\7z.exe',
                'C:\\Program Files (x86)\\7-Zip\\7z.exe',
                '/usr/bin/7z', '/usr/local/bin/7z', '/usr/bin/7za', '/usr/bin/7zz', '/usr/local/bin/7zz', '/usr/bin/7zzs', '/usr/local/bin/7zzs', '/usr/bin/7zip', '/bin/7zz', '/bin/7z'
            ];
            foreach ($sevenZipPaths as $_7zp) {
                if (file_exists($_7zp)) { $sevenZipBin = $_7zp; break; }
            }
            
            // UnRAR 바이너리 탐색 (rar 전용 — 7-zip이 못 읽는 rar 처리)
            $unrarBin = null;
            $unrarPaths = [
                'C:\\Program Files\\WinRAR\\UnRAR.exe',
                'C:\\Program Files (x86)\\WinRAR\\UnRAR.exe',
                'C:\\Program Files\\WinRAR\\Rar.exe',
                'C:\\Program Files (x86)\\WinRAR\\Rar.exe',
                '/usr/bin/unrar', '/usr/local/bin/unrar', '/usr/bin/unrar-nonfree'
            ];
            foreach ($unrarPaths as $_urp) {
                if (file_exists($_urp)) { $unrarBin = $_urp; break; }
            }
            
            // PHP 내장으로 처리 가능한 형식은 PHP 우선 (안정적)
            $phpNativeFormats = ['zip', 'tar', 'gz', 'tgz', 'bz2', 'tbz2'];
            
            // 방법 1: PHP 내장 (zip, tar, gz, bz2)
            if (in_array($archiveExt, $phpNativeFormats)) {
                if ($archiveExt === 'zip') {
                    $zip = new ZipArchive();
                    if ($zip->open($archiveFullPath) === true) {
                        $method = 'php-zip';
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $stat = $zip->statIndex($i);
                            if ($stat === false) continue;
                            $name = $stat['name'];
                            $isDir = substr($name, -1) === '/';
                            $entryEncrypted = (($stat['encryption_method'] ?? 0) !== 0);
                            // fallback: comp_method 비트 체크 (PHP < 8.0)
                            if (!$entryEncrypted && !$isDir && ($stat['comp_size'] ?? 0) > 0 && ($stat['size'] ?? 0) > 0) {
                                // General purpose bit flag의 bit 0이 1이면 암호화
                                // ZipArchive::statIndex에서는 직접 접근 불가하므로 encryption_method로만 판별
                            }
                            if ($entryEncrypted) $isEncrypted = true;
                            $items[] = [
                                'name' => $isDir ? rtrim($name, '/') : $name,
                                'size' => $stat['size'],
                                'compressed' => $stat['comp_size'],
                                'modified' => date('Y-m-d H:i:s', $stat['mtime']),
                                'is_dir' => $isDir,
                                'encrypted' => $entryEncrypted,
                                'index' => $i
                            ];
                        }
                        $zip->close();
                    }
                } elseif (in_array($archiveExt, ['tar', 'gz', 'tgz', 'bz2', 'tbz2'])) {
                    try {
                        $phar = new PharData($archiveFullPath);
                        $method = 'php-phar';
                        $iterator = new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::SELF_FIRST);
                        foreach ($iterator as $entry) {
                            $entryPath = str_replace('\\', '/', $entry->getPathname());
                            $relativePath = preg_replace('#^phar://.*?\.(?:tar|gz|tgz|bz2|tbz2)/(.*)$#i', '$1', $entryPath);
                            $items[] = [
                                'name' => $relativePath,
                                'size' => $entry->isDir() ? 0 : $entry->getSize(),
                                'compressed' => 0,
                                'modified' => date('Y-m-d H:i:s', $entry->getMTime()),
                                'is_dir' => $entry->isDir(),
                                'index' => count($items)
                            ];
                        }
                    } catch (Exception $e) {}
                }
            }
            
            // 방법 2: 7-zip (PHP 내장 미지원 형식 또는 PHP 처리 실패)
            if (empty($items) && $method === 'none' && $sevenZipBin) {
                $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_7z_' . md5($archiveFullPath) . '.txt';
                // 보안: escapeshellarg로 경로 이스케이프 (command injection 방지)
                $cmd = escapeshellarg($sevenZipBin) . ' l -slt -sccUTF-8';
                // 헤더 암호화(-mhe) 7z 등: 비밀번호를 안전하게 -p로 전달 (없으면 -p"")
                // 제어문자만 제거하고 $ # ` 등 정상 문자 보존 (과거 $ 제거로 비번 잘림 버그 수정)
                $cmd .= fs_build_password_arg($archivePassword);
                $cmd .= ' ' . escapeshellarg($archiveFullPath);
                $alDbg("7z 목록 비번 hex=" . ($archivePassword !== '' ? bin2hex(substr($archivePassword,0,30)) : '[없음]') . " / 비번인자=" . fs_build_password_arg($archivePassword));
                $alDbg("7z 목록 cmd=$cmd");
                // Windows: 한글/일본어/중국어 등 유니코드 파일명 정확 처리를 위해 UTF-8 콘솔(chcp 65001). stdin 차단으로 비번 프롬프트 멈춤 방지.
                // ★ 핵심: stdout을 파이프(shell_exec 반환값)로 받으면 Windows 콘솔이 표현 못 하는 한글을
                //   글자당 '?'로 치환해버려 일부 환경에서 파일명이 깨진다(콘솔 코드페이지/폰트 의존).
                //   출력을 파일로 직접 리다이렉트(> tmpFile)하면 콘솔 표시 단계를 거치지 않아 7-Zip이
                //   내부 UTF-8 바이트를 그대로 파일에 기록 → 환경과 무관하게 파일명 보존.
                $escapedTmp = escapeshellarg($tmpFile);
                if (DIRECTORY_SEPARATOR === '\\') {
                    @shell_exec('chcp 65001 >nul && ' . $cmd . ' > ' . $escapedTmp . ' 2>&1 < nul');
                } else {
                    @shell_exec(fs_utf8_env_prefix() . $cmd . ' > ' . $escapedTmp . ' 2>&1 < /dev/null');
                }
                $shellOutput = @file_exists($tmpFile) ? @file_get_contents($tmpFile) : null;
                // 헤더 암호화 감지: 목록을 못 뽑고 암호 오류/프롬프트가 나온 경우
                if ($shellOutput !== null && (
                        stripos($shellOutput, 'Wrong password') !== false ||
                        stripos($shellOutput, 'Enter password') !== false ||
                        stripos($shellOutput, 'Cannot open encrypted archive') !== false ||
                        (stripos($shellOutput, 'headers') !== false && stripos($shellOutput, 'crypt') !== false)
                    )) {
                    $isEncrypted = true;
                    $needPassword = true;
                }
                $alDbg("7z 목록: needPassword=" . ($needPassword ? 'YES' : 'NO') . " / 출력앞200=" . substr((string)$shellOutput, 0, 200));
                // 출력은 이미 위에서 $tmpFile로 직접 리다이렉트됨 (파일명 인코딩 보존). 별도 기록 불필요.
                unset($shellOutput); // 메모리 해제
                
                $debugLines = [];
                $retCode = 0;
                
                $debugLines = [];
                
                
                if (file_exists($tmpFile) && ($retCode === 0 || $retCode === 1)) {
                    $method = '7zip';
                    $inFileList = false;
                    $currentEntry = [];
                    $lineCount = 0;
                    $maxItems = 50000;
                    
                    $saveEntry = function() use (&$currentEntry, &$items, &$isEncrypted, $maxItems, $alDbg) {
                        if (empty($currentEntry['Path']) || count($items) >= $maxItems) return;
                        $isDir = (!empty($currentEntry['Folder']) && $currentEntry['Folder'] === '+');
                        if (!$isDir && isset($currentEntry['Attributes']) && strpos($currentEntry['Attributes'], 'D') === 0) $isDir = true;
                        $entryName = str_replace('\\', '/', $currentEntry['Path']);
                        // [진단] 7z 원본 출력의 파일명 바이트 확인 (이미 ?로 깨졌는지 / CP949인지 / UTF-8인지)
                        $alDbg("  [목록항목] 원본 path=" . $entryName . " / hex=" . bin2hex(substr($entryName, 0, 40)) . " / UTF-8유효=" . (mb_check_encoding($entryName, 'UTF-8') ? 'YES' : 'NO'));
                        // Windows 7-zip 출력이 CP949/EUC-KR일 수 있음 → UTF-8 변환
                        if (!mb_check_encoding($entryName, 'UTF-8')) {
                            $converted = @mb_convert_encoding($entryName, 'UTF-8', 'CP949');
                            if ($converted) $entryName = $converted;
                        }
                        $entryEncrypted = (!empty($currentEntry['Encrypted']) && $currentEntry['Encrypted'] === '+');
                        if ($entryEncrypted) $isEncrypted = true;
                        $items[] = [
                            'name' => $isDir ? rtrim($entryName, '/') : $entryName,
                            'size' => (int)($currentEntry['Size'] ?? 0),
                            'compressed' => (int)($currentEntry['Packed Size'] ?? 0),
                            'modified' => preg_replace('/\.\d+$/', '', $currentEntry['Modified'] ?? ''),
                            'is_dir' => $isDir,
                            'encrypted' => $entryEncrypted,
                            'index' => count($items)
                        ];
                    };
                    
                    $fh = @fopen($tmpFile, 'r');
                    if ($fh) {
                        while (($line = fgets($fh)) !== false) {
                            $lineCount++;
                            $trimmed = trim($line);
                            
                            if ($lineCount <= 50) $debugLines[] = $trimmed;
                            
                            if ($trimmed === '----------') {
                                $inFileList = true;
                                $currentEntry = [];
                                continue;
                            }
                            
                            if (!$inFileList) continue;
                            
                            if ($trimmed === '') {
                                $saveEntry();
                                $currentEntry = [];
                                continue;
                            }
                            
                            $eqPos = strpos($trimmed, ' = ');
                            if ($eqPos !== false) {
                                $key = substr($trimmed, 0, $eqPos);
                                $val = substr($trimmed, $eqPos + 3);
                                $currentEntry[$key] = $val;
                            }
                            
                            if (count($items) >= $maxItems) break;
                        }
                        $saveEntry();
                        fclose($fh);
                    }
                    
                    
                    if (strpos(implode("\n", $debugLines), 'Encrypted = +') !== false) $isEncrypted = true;
                }
                
                // 목록을 성공적으로 읽었으면(items 채워짐) 비번이 맞은 것 → needPassword 해제.
                //   (비번 없이 첫 시도에서 needPassword=true가 됐어도, 비번 주고 재요청해 목록을 읽으면 해제되어야
                //    프론트가 비번창을 다시 띄우지 않음. 헤더 암호화 7z의 무한 비번창 버그 수정.)
                if (!empty($items)) {
                    $needPassword = false;
                }
                $alDbg("7z 목록 파싱 후: items=" . count($items) . ", needPassword=" . ($needPassword ? 'YES' : 'NO'));
                
                // 임시 파일 삭제
                @unlink($tmpFile);
                
                // 디버그용
                $output = $debugLines;
            }
            
            // 방법 2.5: PHP 네이티브 RAR 파싱 (최우선 — 환경/콘솔 인코딩과 무관, 반디집 방식)
            // 7-Zip/UnRAR CLI는 Windows 콘솔 코드페이지에 의존해 한글/유니코드 파일명이 깨질 수 있다.
            // RAR 헤더를 직접 읽으면 LANG/chcp 설정과 무관하게 항상 정확하다.
            // 성공하면 7-Zip 목록을 교체하고 아래 UnRAR 폴백은 건너뛴다.
            $nativeRarUsed = false;
            if ($archiveExt === 'rar') {
                $nat = @fs_rar_native_list($archiveFullPath);
                $alDbg("RAR 네이티브 파싱: " . ($nat ? ('항목 ' . count($nat['items'] ?? []) . '개, fmt=' . ($nat['fmt'] ?? '?')) : 'null(실패)'));
                if ($nat && !empty($nat['items'])) {
                    $natItems = [];
                    $natEncrypted = false;
                    $maxItemsN = 50000;
                    foreach ($nat['items'] as $ni) {
                        if (count($natItems) >= $maxItemsN) break;
                        $nm = $ni['name'];
                        // RAR4 비유니코드 이름은 OEM(CP949 등)일 수 있음 → UTF-8 아니면 CP949 변환
                        if (!mb_check_encoding($nm, 'UTF-8')) {
                            $conv = @mb_convert_encoding($nm, 'UTF-8', 'CP949');
                            if ($conv !== false && $conv !== '') $nm = $conv;
                        }
                        if ($ni['encrypted']) $natEncrypted = true;
                        $natItems[] = [
                            'name'       => $ni['is_dir'] ? rtrim($nm, '/') : $nm,
                            'size'       => (int)$ni['size'],
                            'compressed' => 0,
                            'modified'   => $ni['modified'] ?? '',
                            'is_dir'     => $ni['is_dir'],
                            'encrypted'  => $ni['encrypted'],
                            'index'      => count($natItems),
                        ];
                    }
                    $alDbg("RAR 네이티브 암호감지=" . ($natEncrypted ? 'YES' : 'NO') . " / 항목샘플: " . implode(' | ', array_map(function($x){ return ($x['is_dir']?'[D]':'[F]') . $x['name'] . ($x['encrypted']?'🔒':''); }, array_slice($natItems, 0, 5))));
                    if (!empty($natItems)) {
                        $items = $natItems;
                        $method = 'rar';
                        if ($natEncrypted) $isEncrypted = true;
                        $nativeRarUsed = true;
                    }
                }
            }

            // 방법 3: UnRAR 폴백 (네이티브 파싱이 실패했을 때만 — 7-zip의 rar 지원 한계 보완)
            // rar이고 UnRAR 있으면: 7-Zip이 rar 한글/유니코드 파일명을 깨뜨릴 수 있으므로 UnRAR 목록을 우선 사용 (RAR 공식 도구가 정확). UnRAR이 목록을 못 만들면 7-Zip 결과 유지(자동 폴백).
            if ($archiveExt === 'rar' && $unrarBin && !$nativeRarUsed) {
                // unrar lt: technical list. stdin 차단으로 비밀번호 프롬프트 멈춤 방지.
                // 보안: escapeshellarg로 command injection 방지
                $urCmd = escapeshellarg($unrarBin) . ' lt ' . escapeshellarg($archiveFullPath);
                if (DIRECTORY_SEPARATOR === '\\') {
                    $urOut = @shell_exec('chcp 65001 >nul && ' . $urCmd . ' 2>&1 < nul');
                } else {
                    $urOut = @shell_exec(fs_utf8_env_prefix() . $urCmd . ' 2>&1 < /dev/null');
                }
                if ($urOut) {
                    $cur = [];
                    $maxItems = 50000;
                    $urItems = [];      // UnRAR 결과 임시 (성공 시 7-Zip 목록 교체)
                    $urEncrypted = false;
                    // unrar lt 출력은 "Key: value" 형식, 항목은 빈 줄로 구분
                    $saveUr = function() use (&$cur, &$urItems, &$urEncrypted, $maxItems) {
                        if (empty($cur['Name']) || count($urItems) >= $maxItems) return;
                        $isDir = (isset($cur['Type']) && stripos($cur['Type'], 'Directory') !== false);
                        $nm = str_replace('\\', '/', $cur['Name']);
                        // Windows unrar 출력이 CP949일 수 있음 → UTF-8 변환
                        if (!mb_check_encoding($nm, 'UTF-8')) {
                            $conv = @mb_convert_encoding($nm, 'UTF-8', 'CP949');
                            if ($conv) $nm = $conv;
                        }
                        $enc = (isset($cur['Flags']) && stripos($cur['Flags'], 'encrypt') !== false);
                        if ($enc) $urEncrypted = true;
                        $urItems[] = [
                            'name' => $isDir ? rtrim($nm, '/') : $nm,
                            'size' => (int)preg_replace('/[^0-9]/', '', $cur['Size'] ?? '0'),
                            'compressed' => (int)preg_replace('/[^0-9]/', '', $cur['Packed size'] ?? '0'),
                            'modified' => preg_replace('/\.\d+$/', '', $cur['mtime'] ?? ''),
                            'is_dir' => $isDir,
                            'encrypted' => $enc,
                            'index' => count($urItems)
                        ];
                    };
                    foreach (explode("\n", $urOut) as $ln) {
                        $tl = trim($ln);
                        if ($tl === '') { $saveUr(); $cur = []; continue; }
                        $cp = strpos($tl, ': ');
                        if ($cp !== false) {
                            $cur[substr($tl, 0, $cp)] = substr($tl, $cp + 2);
                        }
                    }
                    $saveUr();
                    // UnRAR이 목록을 만들었으면 7-Zip 결과를 교체 (rar 한글 파일명 정확)
                    if (!empty($urItems)) {
                        $items = $urItems;
                        $method = 'unrar';
                        if ($urEncrypted) $isEncrypted = true;
                    }
                }
            }
            
            // 헤더 암호화로 목록을 못 읽은 경우: 비밀번호 요청 (반디집처럼 비번 입력창 유도)
            if (empty($items) && $needPassword) {
                $alDbg("→ 결과: need_password=true, wrong_password=" . ($archivePassword !== '' ? 'true' : 'false') . " (헤더암호, 목록 못읽음)");
                $result = [
                    'success' => true,
                    'items' => [],
                    'total' => 0,
                    'archive_size' => (int)($archiveSize ?: 0),
                    'encrypted' => true,
                    'need_password' => true,
                    'wrong_password' => ($archivePassword !== ''), // 비번 줬는데도 실패 = 틀린 비번
                    'method' => $method !== 'none' ? $method : '7zip'
                ];
                break;
            }
            
            // 모든 방법 실패
            if (empty($items) && $method === 'none') {
                $alDbg("→ 결과: 실패 (items 비고 method=none)");
                $result = [
                    'success' => false, 
                    'error' => $sevenZipBin 
                        ? __('archive_read_failed', '아카이브를 읽을 수 없습니다.')
                        : __('archive_not_supported', '이 형식은 지원하지 않습니다. 7-Zip을 설치하면 더 많은 형식을 지원합니다.')
                ];
                break;
            }
            
            $alDbg("→ 결과: success, total=" . count($items) . ", encrypted=" . ($isEncrypted?'YES':'NO') . ", need_password=" . ($needPassword?'YES':'NO') . ", method=$method");
            $result = [
                'success' => true,
                'items' => $items,
                'total' => count($items),
                'archive_size' => (int)($archiveSize ?: 0),
                'encrypted' => $isEncrypted,
                'need_password' => $needPassword,
                'method' => $method
            ];
            break;
        
        // 아카이브 내부 파일 미리보기 (이미지)
        case 'archive_preview':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            @set_time_limit(60);
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';           // 아카이브 파일 경로
            $entryName = $_GET['entry'] ?? '';      // 아카이브 내부 파일 경로
            $vaultTempId2 = $_GET['vault_temp_id'] ?? '';
            $previewPassword = (string)($_GET['password'] ?? ''); // 암호화 아카이브 미리보기용
            // 디버그 로그 (config.php EXTRACT_DEBUG=true 시)
            $pvDbgOn = (defined('EXTRACT_DEBUG') && EXTRACT_DEBUG);
            $pvDbgLog = (defined('DATA_PATH') ? DATA_PATH : sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'extract_debug.log';
            $pvDbg = function($msg) use ($pvDbgOn, $pvDbgLog) {
                if ($pvDbgOn) @file_put_contents($pvDbgLog, date('Y-m-d H:i:s') . ' [archive_preview] ' . $msg . "\n", FILE_APPEND);
            };
            $pvDbg("entry=$entryName / password=" . ($previewPassword !== '' ? '[있음 len=' . strlen($previewPassword) . ' hex=' . bin2hex(substr($previewPassword,0,20)) . ']' : '[없음]'));
            
            if (!$path || !$entryName) {
                http_response_code(400); exit;
            }
            
            // Vault 임시파일 모드
            if ($vaultTempId2) {
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $vaultTempId2)) {
                    http_response_code(400); exit;
                }
                $vaultExt2 = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $archiveReal = DATA_PATH . DIRECTORY_SEPARATOR . 'vault_temp' . DIRECTORY_SEPARATOR . $vaultTempId2 . '.' . $vaultExt2;
                if (!file_exists($archiveReal)) { http_response_code(404); exit; }
            } else {
                // 일반 모드
                if (!$storageId) { http_response_code(400); exit; }
                
                // 폴더별 읽기 권한 체크
                { $apDir = dirname($path); if ($apDir === '.') $apDir = '';
                  if (!$storage->checkFolderPermission($storageId, $apDir ?: $path)) {
                      http_response_code(403); exit; }
                }
                
                $basePath = $storage->getRealPath($storageId);
                if (!$basePath) { http_response_code(404); exit; }
                
                $archivePath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
                $archiveReal = realpath($archivePath);
                if (!$archiveReal) {
                    // 대용량 파일 대응
                    $aDir = dirname($archivePath);
                    $aBase = basename($archivePath);
                    if (is_dir($aDir)) {
                        $dFiles = @scandir($aDir);
                        if ($dFiles && in_array($aBase, $dFiles)) $archiveReal = $aDir . DIRECTORY_SEPARATOR . $aBase;
                    }
                }
                if (!$archiveReal) { http_response_code(404); exit; }
            }
            
            // 이미지 확장자 체크
            $entryExt = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
            $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
            if (!in_array($entryExt, $imageExts)) {
                http_response_code(400);
                echo 'Not an image file';
                exit;
            }
            // 경로 트래버설 방지
            if (strpos($entryName, '..') !== false) {
                http_response_code(400);
                echo 'Invalid entry name';
                exit;
            }
            
            // 크기 제한 (10MB)
            $maxPreviewSize = 10 * 1024 * 1024;
            $archiveExt2 = strtolower(pathinfo($archiveReal, PATHINFO_EXTENSION));
            $fileData = null;
            
            // ZIP: PHP 내장으로 메모리에서 읽기
            if ($archiveExt2 === 'zip') {
                $zip2 = new ZipArchive();
                if ($zip2->open($archiveReal) === true) {
                    $stat2 = $zip2->statName($entryName);
                    if ($stat2 && $stat2['size'] <= $maxPreviewSize) {
                        $fileData = $zip2->getFromName($entryName);
                    }
                    $zip2->close();
                }
            }
            
            // 7-zip: 개별 파일 추출
            if ($fileData === null) {
                $szBin2 = null;
                $szPaths2 = ['C:\\Program Files\\7-Zip\\7z.exe', 'C:\\Program Files (x86)\\7-Zip\\7z.exe', '/usr/bin/7z', '/usr/local/bin/7z', '/usr/bin/7za', '/usr/bin/7zz', '/usr/local/bin/7zz', '/usr/bin/7zzs', '/usr/local/bin/7zzs', '/usr/bin/7zip', '/bin/7zz', '/bin/7z'];
                foreach ($szPaths2 as $_p) { if (file_exists($_p)) { $szBin2 = $_p; break; } }
                
                if ($szBin2) {
                    // 임시 디렉토리로 추출 후 읽기 (stdout 파이프 미사용).
                    //   이유: Windows에서 shell_exec로 바이너리(이미지)를 stdout(-so)으로 받으면
                    //         cmd 파이프가 바이너리를 깨뜨려 추출 실패함(목록=텍스트는 정상, 이미지=바이너리만 실패).
                    //         파일로 추출 후 file_get_contents로 읽으면 바이너리 안전 + 크로스플랫폼.
                    // Windows 7-zip은 백슬래시 경로
                    $entryPath = $entryName;
                    if (DIRECTORY_SEPARATOR === '\\') {
                        $entryPath = str_replace('/', '\\', $entryPath);
                    }
                    // 동시 요청 충돌 방지용 고유 임시 디렉토리
                    $extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_7zext_' . md5($archiveReal . $entryName . microtime(true) . mt_rand());
                    @mkdir($extractDir, 0700, true);
                    // 7z e: flat 추출(경로 무시하고 파일명만), -y: 덮어쓰기, -o: 출력 디렉토리
                    // 보안: escapeshellarg로 command injection 방지
                    $cmd2 = escapeshellarg($szBin2) . ' e -y -sccUTF-8 ' . escapeshellarg($archiveReal)
                          . ' ' . escapeshellarg($entryPath)
                          . ' -o' . escapeshellarg($extractDir);
                    // 암호화 아카이브: 비밀번호 전달 (없으면 빈 비번으로 프롬프트 멈춤 방지)
                    // 제어문자만 제거하고 $ # ` 등 정상 문자 보존
                    $cmd2 .= fs_build_password_arg($previewPassword);
                    $pvDbg("7z e cmd=$cmd2");
                    if (DIRECTORY_SEPARATOR === '\\') {
                        // Windows: 한글/유니코드 경로 대응 위해 UTF-8 콘솔(chcp 65001) 후 실행. stdin 차단으로 프롬프트 멈춤 방지.
                        if ($pvDbgOn) {
                            $pvOut = @shell_exec('chcp 65001 >nul && ' . $cmd2 . ' 2>&1 < nul');
                            $pvDbg("7z e 출력=" . substr((string)$pvOut, 0, 500));
                        } else {
                            @shell_exec('chcp 65001 >nul && ' . $cmd2 . ' 2>nul < nul');
                        }
                    } else {
                        @shell_exec(fs_utf8_env_prefix() . $cmd2 . ' 2>/dev/null < /dev/null');
                    }
                    // flat 추출이므로 basename으로 읽음
                    $extractedFile = $extractDir . DIRECTORY_SEPARATOR . basename($entryName);
                    $pvDbg("추출파일 경로=$extractedFile / 존재=" . (is_file($extractedFile) ? 'YES (' . @filesize($extractedFile) . 'B)' : 'NO'));
                    if (is_file($extractedFile)) {
                        if (@filesize($extractedFile) <= $maxPreviewSize) {
                            $fileData = @file_get_contents($extractedFile);
                        }
                        @unlink($extractedFile);
                    }
                    @rmdir($extractDir);
                    if ($fileData === false) $fileData = null;
                }
            }
            
            // UnRAR 폴백 (rar인데 7-zip이 추출 실패한 경우)
            if ($fileData === null && $archiveExt2 === 'rar') {
                $unrarBin2 = null;
                $unrarPaths2 = ['C:\\Program Files\\WinRAR\\UnRAR.exe', 'C:\\Program Files (x86)\\WinRAR\\UnRAR.exe', 'C:\\Program Files\\WinRAR\\Rar.exe', 'C:\\Program Files (x86)\\WinRAR\\Rar.exe', '/usr/bin/unrar', '/usr/local/bin/unrar', '/usr/bin/unrar-nonfree'];
                foreach ($unrarPaths2 as $_up) { if (file_exists($_up)) { $unrarBin2 = $_up; break; } }
                if ($unrarBin2) {
                    // 임시 디렉토리로 추출 후 읽기 (7z와 동일하게 바이너리 안전 방식)
                    $extractDir2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_urext_' . md5($archiveReal . $entryName . microtime(true) . mt_rand());
                    @mkdir($extractDir2, 0700, true);
                    // unrar e: flat 추출, -y: 자동 yes, -inul: 메시지 억제. 출력 디렉토리는 끝 구분자 필요.
                    // 보안: escapeshellarg로 command injection 방지. stdin 차단으로 비밀번호 프롬프트 멈춤 방지.
                    $urDest = rtrim($extractDir2, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    $urCmd2 = escapeshellarg($unrarBin2) . ' e -y -inul ' . escapeshellarg($archiveReal)
                            . ' ' . escapeshellarg($entryName) . ' ' . escapeshellarg($urDest);
                    // 암호화 rar: 비밀번호 전달 (unrar는 -p<pwd>) — 제어문자만 제거, 정상 문자 보존
                    if ($previewPassword !== '') {
                        $urCmd2 .= fs_build_password_arg($previewPassword);
                    }
                    if (DIRECTORY_SEPARATOR === '\\') {
                        @shell_exec('chcp 65001 >nul && ' . $urCmd2 . ' 2>nul < nul');
                    } else {
                        @shell_exec(fs_utf8_env_prefix() . $urCmd2 . ' 2>/dev/null < /dev/null');
                    }
                    // flat 추출이므로 basename으로 읽음
                    $extractedFile2 = $extractDir2 . DIRECTORY_SEPARATOR . basename($entryName);
                    if (is_file($extractedFile2)) {
                        if (@filesize($extractedFile2) <= $maxPreviewSize) {
                            $fileData = @file_get_contents($extractedFile2);
                        }
                        @unlink($extractedFile2);
                    }
                    @rmdir($extractDir2);
                    if ($fileData === false) $fileData = null;
                }
            }
            
            if ($fileData === null || $fileData === false || strlen($fileData) === 0) {
                http_response_code(404);
                echo 'Cannot extract file';
                exit;
            }
            
            // MIME 타입 결정
            $mimeTypes = [
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
                'svg' => 'image/svg+xml', 'ico' => 'image/x-icon'
            ];
            $mime = $mimeTypes[$entryExt] ?? 'application/octet-stream';
            
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . strlen($fileData));
            header('Cache-Control: private, max-age=3600');
            echo $fileData;
            exit;
        
        // 아카이브 암호화 여부 사전 체크 (7-Zip 형식용)
        case 'archive_check_password':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            
            $basePath = $storage->getRealPath($storageId);
            $fullPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
            $realBase = realpath($basePath);
            $realTarget = realpath($fullPath);
            
            // 보안: isSubPath로 정확한 하위 경로 검증
            if (!$realBase || !$realTarget || !\isSubPath($realTarget, $realBase) || !file_exists($fullPath)) {
                $result = ['success' => true, 'encrypted' => false];
                break;
            }
            
            // 7-Zip 바이너리 탐색
            $sevenZipBin = null;
            $szPaths = ['C:\\Program Files\\7-Zip\\7z.exe', 'C:\\Program Files (x86)\\7-Zip\\7z.exe', '/usr/bin/7z', '/usr/local/bin/7z', '/usr/bin/7za', '/usr/bin/7zz', '/usr/local/bin/7zz', '/usr/bin/7zzs', '/usr/local/bin/7zzs', '/usr/bin/7zip', '/bin/7zz', '/bin/7z'];
            foreach ($szPaths as $p) { if (file_exists($p)) { $sevenZipBin = $p; break; } }
            
            if (!$sevenZipBin) {
                $result = ['success' => true, 'encrypted' => false];
                break;
            }
            
            // 보안: escapeshellarg로 command injection 방지. 헤더암호 프롬프트 멈춤 방지(-p"" + stdin 차단)
            $checkCmd = escapeshellarg($sevenZipBin) . ' l -slt -sccUTF-8 -p"" ' . escapeshellarg($fullPath);
            if (DIRECTORY_SEPARATOR === '\\') {
                $checkOutput = @shell_exec('chcp 65001 >nul && ' . $checkCmd . ' 2>&1 < nul');
            } else {
                $checkOutput = @shell_exec(fs_utf8_env_prefix() . $checkCmd . ' 2>&1 < /dev/null');
            }
            
            $encrypted = false;
            if ($checkOutput) {
                if (preg_match('/Encrypted\s*=\s*\+/i', $checkOutput) || 
                    preg_match('/Method\s*=.*AES/i', $checkOutput) ||
                    stripos($checkOutput, 'Enter password') !== false ||
                    stripos($checkOutput, 'Cannot open encrypted archive') !== false) {
                    $encrypted = true;
                }
            }
            
            $result = ['success' => true, 'encrypted' => $encrypted];
            break;
        
        case 'extract':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            // 폴더별 쓰기 권한 체크
            { $exDir = dirname($path); if ($exDir === '.') $exDir = '';
              if (!$storage->checkFolderPermission($storageId, $exDir ?: $path, 'can_write')) {
                  $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')]; break; }
            }
            
            $extractMode = ($input['mode'] ?? 'folder') === 'here' ? 'here' : 'folder';
            $result = $fileManager->extractZip($storageId, $path, $input['dest'] ?? '', null, $input['password'] ?? '', $extractMode);
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $activityLog->log(ActivityLog::TYPE_EXTRACT, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $path,
                    'filename' => basename($path),
                    'details' => '→ ' . ($result['extracted_to'] ?? '')
                ]);
                
                // 인덱스 갱신
                $fileIndex = FileIndex::getInstance();
                if ($fileIndex->isAvailable() && !empty($result['extracted_to'])) {
                    $basePath = $storage->getRealPath($storageId);
                    $fileIndex->indexFolder($storageId, $basePath, $result['extracted_to']);
                }
            }
            break;
        
        // SSE 스트리밍: 압축 해제
        case 'extract_stream':
            $auth->requireLogin();
            
            session_write_close(); // 세션 락 해제
            // SSE 헤더 설정
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            
            @ini_set('output_buffering', 'Off');
            @ini_set('zlib.output_compression', 'Off');
            while (ob_get_level()) ob_end_clean();
            
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            
            // 폴더별 쓰기 권한 체크
            { $exDir = dirname($path); if ($exDir === '.') $exDir = '';
              if (!$storage->checkFolderPermission($storageId, $exDir ?: $path, 'can_write')) {
                  echo "data: " . json_encode(['type' => 'error', 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')]) . "\n\n"; flush(); exit;
              }
            }
            
            $progressCallback = function($current, $total, $filename) {
                $percent = $total > 0 ? round(($current / $total) * 100) : 0;
                echo "data: " . json_encode([
                    'type' => 'progress',
                    'current' => $current,
                    'total' => $total,
                    'percent' => $percent,
                    'filename' => basename($filename)
                ]) . "\n\n";
                flush();
            };
            
            $zipPassword = $_GET['password'] ?? '';
            $extractMode = ($_GET['mode'] ?? 'folder') === 'here' ? 'here' : 'folder';
            $result = $fileManager->extractZip($storageId, $path, '', $progressCallback, $zipPassword, $extractMode);
            
            // 완료 이벤트
            echo "data: " . json_encode([
                'type' => 'complete',
                'success' => $result['success'] ?? false,
                'extracted_to' => $result['extracted_to'] ?? '',
                'file_count' => $result['file_count'] ?? 0,
                'error' => $result['error'] ?? null,
                'need_password' => $result['need_password'] ?? false
            ]) . "\n\n";
            flush();
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $activityLog->log(ActivityLog::TYPE_EXTRACT, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $path,
                    'filename' => basename($path),
                    'details' => '→ ' . ($result['extracted_to'] ?? '')
                ]);
                
                // 인덱스 갱신
                $fileIndex = FileIndex::getInstance();
                if ($fileIndex->isAvailable() && !empty($result['extracted_to'])) {
                    $basePath = $storage->getRealPath($storageId);
                    $fileIndex->indexFolder($storageId, $basePath, $result['extracted_to']);
                }
            }
            exit;
            
        case 'compress':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            @set_time_limit(0);
            $storageId = (int)($input['storage_id'] ?? 0);
            $paths = $input['paths'] ?? [];
            $zipPassword = $input['password'] ?? '';
            $splitSizeMB = (int)($input['split_size'] ?? 0);
            
            // 폴더별 읽기 권한 체크 (첫 번째 파일의 디렉토리 기준)
            if (!empty($paths)) {
                $cmpDir = dirname($paths[0]); if ($cmpDir === '.') $cmpDir = '';
                if (!$storage->checkFolderPermission($storageId, $cmpDir ?: $paths[0], 'can_read')) {
                    $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
                }
            }
            
            if ($splitSizeMB > 0) {
                $result = $fileManager->createSplitZip($storageId, $paths, $input['zip_name'] ?? '', $splitSizeMB, null, $zipPassword);
            } else {
                $result = $fileManager->createZip($storageId, $paths, $input['zip_name'] ?? '', null, $zipPassword);
            }
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $activityLog->log(ActivityLog::TYPE_COMPRESS, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => implode(', ', array_map('basename', $paths)),
                    'filename' => $result['zip_name'] ?? '',
                    'details' => __f('n_items', ['count' => count($paths)])
                ]);
                
                // 인덱스 갱신: 생성된 zip 파일
                if (!empty($result['zip_name'])) {
                    $fileIndex = FileIndex::getInstance();
                    if ($fileIndex->isAvailable()) {
                        $zipPath = trim(($input['base_path'] ?? '') . '/' . $result['zip_name'], '/');
                        $basePath = $storage->getRealPath($storageId);
                        $fullZipPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $zipPath);
                        $fileIndex->addFile($storageId, $zipPath, [
                            'name' => $result['zip_name'],
                            'size' => file_exists($fullZipPath) ? filesize($fullZipPath) : 0,
                            'modified' => time(),
                            'is_dir' => false
                        ]);
                    }
                }
            }
            break;
        
        // SSE 스트리밍: 압축
        case 'compress_stream':
            $auth->requireLogin();
            
            session_write_close(); // 세션 락 해제
            // SSE 헤더 설정
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            
            @ini_set('output_buffering', 'Off');
            @ini_set('zlib.output_compression', 'Off');
            while (ob_get_level()) ob_end_clean();
            
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $paths = isset($_GET['paths']) ? json_decode($_GET['paths'], true) : [];
            $zipName = $_GET['zip_name'] ?? '';
            
            // 폴더별 읽기 권한 체크
            if (!empty($paths)) {
                $cmpDir = dirname($paths[0]); if ($cmpDir === '.') $cmpDir = '';
                if (!$storage->checkFolderPermission($storageId, $cmpDir ?: $paths[0], 'can_read')) {
                    echo "data: " . json_encode(['type' => 'error', 'error' => __('no_permission_dot', '권한이 없습니다.')]) . "\n\n"; flush(); exit;
                }
            }
            
            $progressCallback = function($current, $total, $filename) {
                $percent = $total > 0 ? round(($current / $total) * 100) : 0;
                echo "data: " . json_encode([
                    'type' => 'progress',
                    'current' => $current,
                    'total' => $total,
                    'percent' => $percent,
                    'filename' => basename($filename)
                ]) . "\n\n";
                flush();
            };
            
            $zipPassword = $_GET['password'] ?? '';
            $splitSizeMB = (int)($_GET['split_size'] ?? 0);
            
            // 분할 압축 (7-Zip 필요)
            if ($splitSizeMB > 0) {
                $result = $fileManager->createSplitZip($storageId, $paths, $zipName, $splitSizeMB, $progressCallback, $zipPassword);
            } else {
                $result = $fileManager->createZip($storageId, $paths, $zipName, $progressCallback, $zipPassword);
            }
            
            // 완료 이벤트
            echo "data: " . json_encode([
                'type' => 'complete',
                'success' => $result['success'] ?? false,
                'zip_name' => $result['zip_name'] ?? '',
                'file_count' => $result['file_count'] ?? 0,
                'error' => $result['error'] ?? null
            ]) . "\n\n";
            flush();
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $activityLog->log(ActivityLog::TYPE_COMPRESS, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => implode(', ', array_map('basename', $paths)),
                    'filename' => $result['zip_name'] ?? '',
                    'details' => __f('n_items', ['count' => count($paths)])
                ]);
                
                // 인덱스 갱신
                if (!empty($result['zip_name'])) {
                    $fileIndex = FileIndex::getInstance();
                    if ($fileIndex->isAvailable()) {
                        $zipPath = $result['zip_name'];
                        $basePath = $storage->getRealPath($storageId);
                        $fullZipPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $zipPath);
                        $fileIndex->addFile($storageId, $zipPath, [
                            'name' => $result['zip_name'],
                            'size' => file_exists($fullZipPath) ? filesize($fullZipPath) : 0,
                            'modified' => time(),
                            'is_dir' => false
                        ]);
                    }
                }
            }
            exit;
            
        // SSE 스트리밍: 다중 항목 다운로드 (압축 진행률)
        case 'multi_download_stream':
            $auth->requireLogin();
            
            session_write_close(); // 세션 락 해제
            // SSE 헤더 설정
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            
            @ini_set('output_buffering', 'Off');
            @ini_set('zlib.output_compression', 'Off');
            @set_time_limit(0);
            while (ob_get_level()) ob_end_clean();
            
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $paths = isset($_GET['paths']) ? json_decode($_GET['paths'], true) : [];
            $zipName = $_GET['zip_name'] ?? '';
            $zipPassword = $_GET['password'] ?? '';
            
            // 폴더별 읽기 권한 체크
            if (!empty($paths)) {
                $mdDir = dirname($paths[0]); if ($mdDir === '.') $mdDir = '';
                if (!$storage->checkFolderPermission($storageId, $mdDir ?: $paths[0], 'can_read')) {
                    echo "data: " . json_encode(['type' => 'error', 'error' => __('no_permission_dot', '권한이 없습니다.')]) . "\n\n"; flush(); exit;
                }
            }
            
            if (empty($paths)) {
                echo "data: " . json_encode([
                    'type' => 'error',
                    'error' => __('api_err_no_download_items', '다운로드할 항목이 없습니다.')
                ]) . "\n\n";
                flush();
                exit;
            }
            
            $basePath = $storage->getRealPath($storageId);
            
            // ZIP 파일명 결정
            if (empty($zipName)) {
                if (count($paths) === 1) {
                    $zipName = basename($paths[0]) . '.zip';
                } else {
                    $zipName = 'download_' . kstDate('Ymd_His') . '.zip';
                }
            }
            
            $tempId = uniqid('multi_download_');
            $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempId . '.zip';
            
            // 파일 목록 수집
            $fileList = [];
            foreach ($paths as $relativePath) {
                $fullPath = $basePath . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
                
                if (!file_exists($fullPath)) continue;
                
                if (is_dir($fullPath)) {
                    $folderName = basename($fullPath);
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    
                    foreach ($iterator as $file) {
                        $fileList[] = [
                            'full' => $file->getPathname(),
                            'relative' => $folderName . DIRECTORY_SEPARATOR . $iterator->getSubPathname(),
                            'isDir' => $file->isDir()
                        ];
                    }
                    
                    // 빈 폴더인 경우
                    if (empty($fileList) || $fileList[count($fileList) - 1]['relative'] !== $folderName) {
                        $fileList[] = [
                            'full' => $fullPath,
                            'relative' => $folderName,
                            'isDir' => true
                        ];
                    }
                } else {
                    $fileList[] = [
                        'full' => $fullPath,
                        'relative' => basename($fullPath),
                        'isDir' => false
                    ];
                }
            }
            
            $totalFiles = count($fileList);
            
            // 시작 이벤트
            echo "data: " . json_encode([
                'type' => 'start',
                'total' => $totalFiles,
                'zip_name' => $zipName
            ]) . "\n\n";
            flush();
            
            // ZIP 생성
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                echo "data: " . json_encode([
                    'type' => 'error',
                    'error' => __('zip_create_fail')
                ]) . "\n\n";
                flush();
                exit;
            }
            
            // 암호 설정
            if (!empty($zipPassword)) {
                $zip->setPassword($zipPassword);
            }
            
            if ($totalFiles === 0) {
                // 빈 항목
                $zip->addEmptyDir('empty');
                echo "data: " . json_encode([
                    'type' => 'progress',
                    'current' => 1,
                    'total' => 1,
                    'percent' => 100,
                    'filename' => 'empty'
                ]) . "\n\n";
                flush();
            } else {
                // 진행률 업데이트 간격
                $updateInterval = max(1, min(50, (int)($totalFiles * 0.05)));
                $lastUpdate = -$updateInterval;
                
                foreach ($fileList as $idx => $item) {
                    // 클라이언트 취소 감지
                    if (connection_aborted()) {
                        $zip->close();
                        @unlink($zipPath);
                        exit;
                    }
                    
                    if ($item['isDir']) {
                        $zip->addEmptyDir($item['relative']);
                    } else {
                        $fileSize = @filesize($item['full']) ?: 0;
                        if ($fileSize < 50 * 1024 * 1024) {
                            // 50MB 미만: addFromString으로 즉시 압축 (실시간 진행률)
                            $content = @file_get_contents($item['full']);
                            if ($content !== false) {
                                $zip->addFromString($item['relative'], $content);
                                unset($content);
                            }
                        } else {
                            // 50MB 이상: addFile + 무압축(STORE) - 메모리/CPU 절약
                            $zip->addFile($item['full'], $item['relative']);
                            $zip->setCompressionName($item['relative'], ZipArchive::CM_STORE);
                        }
                    }
                    
                    // 진행률 업데이트
                    if ($idx - $lastUpdate >= $updateInterval || $idx === $totalFiles - 1) {
                        $percent = round((($idx + 1) / $totalFiles) * 100);
                        echo "data: " . json_encode([
                            'type' => 'progress',
                            'current' => $idx + 1,
                            'total' => $totalFiles,
                            'percent' => $percent,
                            'filename' => basename($item['relative'])
                        ]) . "\n\n";
                        flush();
                        $lastUpdate = $idx;
                    }
                }
            }
            
            @set_time_limit(0);
            $closeResult = $zip->close();
            if (!$closeResult || !file_exists($zipPath)) {
                echo "data: " . json_encode([
                    'type' => 'error',
                    'error' => __('zip_create_fail', 'ZIP 파일 생성에 실패했습니다.')
                ]) . "\n\n";
                flush();
                @unlink($zipPath);
                exit;
            }
            
            $zipSize = filesize($zipPath);
            
            // 임시 파일 정보 저장 (세션에)
            // 주의: 앞서 session_write_close() 호출로 세션이 닫혔으므로 재오픈 필요
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            if (!isset($_SESSION['temp_downloads'])) {
                $_SESSION['temp_downloads'] = [];
            }
            $_SESSION['temp_downloads'][$tempId] = [
                'path' => $zipPath,
                'name' => $zipName,
                'size' => $zipSize,
                'created' => time()
            ];
            @session_write_close(); // 저장 즉시 다시 닫기
            
            // 완료 이벤트
            echo "data: " . json_encode([
                'type' => 'complete',
                'success' => true,
                'temp_id' => $tempId,
                'zip_name' => $zipName,
                'zip_size' => $zipSize,
                'file_count' => $totalFiles
            ]) . "\n\n";
            flush();
            exit;
        
        // SSE 스트리밍: 폴더 다운로드 (압축 진행률)
        case 'folder_download_stream':
            $auth->requireLogin();
            
            session_write_close(); // 세션 락 해제
            // SSE 헤더 설정
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            
            @ini_set('output_buffering', 'Off');
            @ini_set('zlib.output_compression', 'Off');
            @set_time_limit(0);
            while (ob_get_level()) ob_end_clean();
            
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $folderPath = $_GET['path'] ?? '';
            $zipPassword = $_GET['password'] ?? '';
            
            // 폴더별 읽기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $folderPath, 'can_read')) {
                echo "data: " . json_encode(['type' => 'error', 'error' => __('no_permission_dot', '권한이 없습니다.')]) . "\n\n"; flush(); exit;
            }
            
            // 폴더 정보 확인
            $basePath = $storage->getRealPath($storageId);
            $fullPath = $basePath . DIRECTORY_SEPARATOR . ltrim($folderPath, '/\\');
            
            if (!is_dir($fullPath)) {
                echo "data: " . json_encode([
                    'type' => 'error',
                    'error' => __('folder_not_found')
                ]) . "\n\n";
                flush();
                exit;
            }
            
            $folderName = basename($fullPath);
            $zipName = !empty($_GET['zip_name']) ? basename($_GET['zip_name']) : $folderName . '.zip';
            $tempId = uniqid('folder_download_');
            $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempId . '.zip';
            
            // 파일 목록 수집
            $fileList = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                $relativePath = $folderName . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
                $fileList[] = [
                    'full' => $file->getPathname(),
                    'relative' => $relativePath,
                    'isDir' => $file->isDir()
                ];
            }
            
            $totalFiles = count($fileList);
            
            // 시작 이벤트
            echo "data: " . json_encode([
                'type' => 'start',
                'total' => $totalFiles,
                'folder_name' => $folderName
            ]) . "\n\n";
            flush();
            
            // ZIP 생성
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                echo "data: " . json_encode([
                    'type' => 'error',
                    'error' => __('zip_create_fail')
                ]) . "\n\n";
                flush();
                exit;
            }
            
            // 암호 설정
            if (!empty($zipPassword)) {
                $zip->setPassword($zipPassword);
            }
            
            // 빈 폴더인 경우
            if ($totalFiles === 0) {
                $zip->addEmptyDir($folderName);
                echo "data: " . json_encode([
                    'type' => 'progress',
                    'current' => 1,
                    'total' => 1,
                    'percent' => 100,
                    'filename' => $folderName
                ]) . "\n\n";
                flush();
            } else {
                // 진행률 업데이트 간격
                $updateInterval = max(1, min(50, (int)($totalFiles * 0.05)));
                $lastUpdate = -$updateInterval;
                
                foreach ($fileList as $idx => $item) {
                    // 클라이언트 취소 감지
                    if (connection_aborted()) {
                        $zip->close();
                        @unlink($zipPath);
                        exit;
                    }
                    
                    if ($item['isDir']) {
                        $zip->addEmptyDir($item['relative']);
                    } else {
                        $fileSize = @filesize($item['full']) ?: 0;
                        if ($fileSize < 50 * 1024 * 1024) {
                            $content = @file_get_contents($item['full']);
                            if ($content !== false) {
                                $zip->addFromString($item['relative'], $content);
                                unset($content);
                            }
                        } else {
                            $zip->addFile($item['full'], $item['relative']);
                            $zip->setCompressionName($item['relative'], ZipArchive::CM_STORE);
                        }
                        // 암호화 적용
                        if (!empty($zipPassword)) {
                            $zip->setEncryptionName($item['relative'], ZipArchive::EM_AES_256);
                        }
                    }
                    
                    // 진행률 업데이트
                    if ($idx - $lastUpdate >= $updateInterval || $idx === $totalFiles - 1) {
                        $percent = round((($idx + 1) / $totalFiles) * 100);
                        echo "data: " . json_encode([
                            'type' => 'progress',
                            'current' => $idx + 1,
                            'total' => $totalFiles,
                            'percent' => $percent,
                            'filename' => basename($item['relative'])
                        ]) . "\n\n";
                        flush();
                        $lastUpdate = $idx;
                    }
                }
            }
            
            @set_time_limit(0);
            $closeResult = $zip->close();
            if (!$closeResult || !file_exists($zipPath)) {
                echo "data: " . json_encode([
                    'type' => 'error',
                    'error' => __('zip_create_fail', 'ZIP 파일 생성에 실패했습니다.')
                ]) . "\n\n";
                flush();
                @unlink($zipPath);
                exit;
            }
            
            $zipSize = filesize($zipPath);
            
            // 임시 파일 정보 저장 (세션에)
            // 주의: 앞서 session_write_close() 호출로 세션이 닫혔으므로 재오픈 필요
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            if (!isset($_SESSION['temp_downloads'])) {
                $_SESSION['temp_downloads'] = [];
            }
            $_SESSION['temp_downloads'][$tempId] = [
                'path' => $zipPath,
                'name' => $zipName,
                'size' => $zipSize,
                'created' => time()
            ];
            @session_write_close(); // 저장 즉시 다시 닫기
            
            // 완료 이벤트
            echo "data: " . json_encode([
                'type' => 'complete',
                'success' => true,
                'temp_id' => $tempId,
                'zip_name' => $zipName,
                'zip_size' => $zipSize,
                'file_count' => $totalFiles
            ]) . "\n\n";
            flush();
            exit;
        
        // 임시 파일 다운로드
        case 'temp_download':
            $auth->requireLogin();
            
            $tempId = $_GET['temp_id'] ?? '';
            
            if (empty($tempId) || !isset($_SESSION['temp_downloads'][$tempId])) {
                http_response_code(404);
                exit(__('file_not_found'));
            }
            
            $tempInfo = $_SESSION['temp_downloads'][$tempId];
            $zipPath = $tempInfo['path'];
            $zipName = $tempInfo['name'];
            
            if (!file_exists($zipPath)) {
                unset($_SESSION['temp_downloads'][$tempId]);
                http_response_code(404);
                exit(__('file_expired'));
            }
            
            @set_time_limit(0);
            @ini_set('output_buffering', 'Off');
            @ini_set('zlib.output_compression', 'Off');
            while (ob_get_level()) ob_end_clean();
            
            $zipSize = filesize($zipPath);
            
            // RFC 5987 형식으로 파일명 인코딩
            $zipNameSafe = preg_replace('/[^\x20-\x7E]|["\\]/', '_', $zipName);
            $zipNameEncoded = rawurlencode($zipName);
            
            header('Content-Type: application/zip');
            header("Content-Disposition: attachment; filename=\"{$zipNameSafe}\"; filename*=UTF-8''{$zipNameEncoded}");
            header('Content-Length: ' . $zipSize);
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Accel-Buffering: no');
            
            // 1MB 청크로 전송
            $handle = fopen($zipPath, 'rb');
            while (!feof($handle)) {
                echo fread($handle, 1048576);
                flush();
            }
            fclose($handle);
            
            // 임시 파일 삭제
            @unlink($zipPath);
            unset($_SESSION['temp_downloads'][$tempId]);
            exit;
            
        case 'copy':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $destStorageId = (int)($input['dest_storage_id'] ?? $storageId);
            $source = $input['source'] ?? '';
            $dest = $input['dest'] ?? '';
            
            // 폴더별 권한 체크 (소스: 읽기, 대상: 쓰기)
            { $cpSrcDir = dirname($source); if ($cpSrcDir === '.') $cpSrcDir = '';
              if (!$storage->checkFolderPermission($storageId, $cpSrcDir ?: $source, 'can_read')
                  || !$storage->checkFolderPermission($destStorageId, $dest, 'can_write')) {
                  $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break; }
            }
            
            session_write_close(); // 세션 락 해제 (진행률 폴링 블로킹 방지)
            $result = $fileManager->copy($storageId, $source, $destStorageId, $dest, $input['duplicate_action'] ?? 'overwrite');
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $destStorageInfo = $storage->getStorageById($destStorageId);
                $activityLog->log(ActivityLog::TYPE_COPY, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $source,
                    'filename' => basename($source),
                    'details' => '→ ' . ($destStorageInfo['name'] ?? '') . ':' . $dest
                ]);
                
                // 인덱스 갱신
                $fileIndex = FileIndex::getInstance();
                if ($fileIndex->isAvailable()) {
                        $destBasePath = $storage->getRealPath($destStorageId);
                        $newPath = trim($dest . '/' . basename($source), '/');
                        $fullPath = $destBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPath);
                        if (is_dir($fullPath)) {
                            // 폴더 복사: 하위 전체 인덱싱
                            $fileIndex->indexFolder($destStorageId, $destBasePath, $newPath);
                        } else {
                            $fileIndex->addFile($destStorageId, $newPath, [
                                'name' => basename($source),
                                'size' => filesize($fullPath) ?: 0,
                                'modified' => filemtime($fullPath) ?: time(),
                                'is_dir' => false
                            ]);
                        }
                }
            }
            break;
        
        case 'copy_progress':
            $auth->requireLogin();
            session_write_close(); // 세션 락 즉시 해제
            $sourcePath = $input['source'] ?? ($_GET['source'] ?? '');
            $progressId = session_id() ?: '';
            $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
            $progressFile = $dataDir . '/copy_progress_' . md5($progressId . $sourcePath) . '.tmp';
            if (is_file($progressFile)) {
                $data = @json_decode(@file_get_contents($progressFile), true);
                $result = ['success' => true, 'progress' => $data ?: ['copied' => 0, 'total' => 0, 'percent' => 0]];
            } else {
                $result = ['success' => true, 'progress' => null]; // 아직 시작 안 했거나 완료됨
            }
            break;
        
        case 'delete_progress':
            $auth->requireLogin();
            session_write_close();
            $id = $input['id'] ?? ($_GET['id'] ?? '');
            $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
            $progressFile = $dataDir . '/delete_progress_' . md5($id) . '.tmp';
            if (is_file($progressFile)) {
                $data = @json_decode(@file_get_contents($progressFile), true);
                $result = ['success' => true, 'progress' => $data ?: null];
            } else {
                $result = ['success' => true, 'progress' => null];
            }
            break;
            
        case 'count_items':
            $auth->requireLogin();
            session_write_close();
            @set_time_limit(0);
            $storageId = (int)($input['storage_id'] ?? ($_GET['storage_id'] ?? 0));
            $paths = $input['paths'] ?? (isset($_GET['paths']) ? json_decode($_GET['paths'], true) : []);
            $totalFiles = 0;
            $totalSize = 0;
            $itemDetails = [];
            $basePath = $storage->getRealPath($storageId);
            if ($basePath && is_array($paths)) {
                foreach ($paths as $p) {
                    $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
                    if (is_dir($fullPath)) {
                        $fc = $fileManager->countAllFiles($fullPath);
                        $sz = $fileManager->getDirectorySize($fullPath);
                        $totalFiles += $fc;
                        $totalSize += $sz;
                        $itemDetails[] = ['path' => $p, 'files' => $fc, 'size' => $sz];
                    } else if (is_file($fullPath)) {
                        $sz = filesize($fullPath) ?: 0;
                        $totalFiles++;
                        $totalSize += $sz;
                        $itemDetails[] = ['path' => $p, 'files' => 1, 'size' => $sz];
                    }
                }
            }
            $result = ['success' => true, 'total_files' => $totalFiles, 'total_size' => $totalSize, 'items' => $itemDetails];
            break;
            
        case 'search':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $result = $fileManager->search(
                (int)($_GET['storage_id'] ?? 0),
                $_GET['query'] ?? '',
                $_GET['path'] ?? ''
            );
            break;
        
        case 'search_all':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $result = $fileManager->searchAll($_GET['query'] ?? '');
            break;
        
        // ===== 검색 인덱스 =====
        case 'index_stats':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $fileIndex = FileIndex::getInstance();
            $result = [
                'success' => true,
                'stats' => $fileIndex->getStats()
            ];
            break;
        
        case 'storages_for_index':
            // 인덱스 재구축용: home 포함 전체 스토리지 목록
            //   타입별로 그룹화 정렬 (사용자가 보기 쉽게)
            //   순서: home (내 파일) → shared (공유) → local → 원격 (FTP/SFTP/WebDAV/S3/SMB)
            //   동일 타입 내에선 이름 가나다순/알파벳 순
            $auth->requireRealAdmin();
            $allStorages = $db->load('storages');
            $remoteAdapterTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
            $indexStorages = [];
            
            foreach ($allStorages as $s) {
                $sType = $s['storage_type'] ?? 'local';
                
                // home 타입: 경로 동적 생성
                if ($sType === 'home') {
                    $owner = $db->find('users', ['id' => ($s['owner_id'] ?? 0)]);
                    // 소유자가 삭제/탈퇴된 home 스토리지는 인덱스 대상에서 제외
                    //   (storages DB에 잔존 항목이 있을 수 있음 — 과거 삭제 시 정리 안 됐던 경우)
                    if (!$owner) {
                        continue;
                    }
                    $ownerName = $owner['username'] ?? 'unknown';
                    $s['name'] = __('api_home_storage_name', '내 파일') . ' (' . $ownerName . ')';
                }
                
                $indexStorages[] = [
                    'id' => $s['id'],
                    'name' => $s['name'] ?? '',
                    'storage_type' => $sType,
                    'is_remote' => in_array($sType, $remoteAdapterTypes)
                ];
            }
            
            // 타입별 정렬 우선순위 (작은 숫자가 먼저)
            $typeOrder = [
                'home'    => 1,  // 내 파일 (사용자별)
                'shared'  => 2,  // 공유 폴더
                'local'   => 3,  // 로컬 스토리지
                'ftp'     => 4,  // 원격 (이름순으로 그 안에서 정렬)
                'sftp'    => 4,
                'webdav'  => 4,
                's3'      => 4,
                'smb'     => 4,
            ];
            usort($indexStorages, function($a, $b) use ($typeOrder) {
                $oa = $typeOrder[$a['storage_type']] ?? 99;
                $ob = $typeOrder[$b['storage_type']] ?? 99;
                if ($oa !== $ob) return $oa - $ob;
                // 같은 타입 그룹 내에선 이름순 (사용자/스토리지 이름 가나다 알파벳)
                return strnatcasecmp($a['name'], $b['name']);
            });
            
            $result = ['success' => true, 'storages' => $indexStorages];
            break;
        
        case 'index_rebuild':
            $auth->requireRealAdmin();
            @set_time_limit(600);
            @ini_set('memory_limit', '512M');
            session_write_close();
            $fileIndex = FileIndex::getInstance();
            
            if (!$fileIndex->isAvailable()) {
                $result = ['success' => false, 'error' => __('api_index_unavailable', '인덱스 DB 사용 불가. 인덱스 초기화 후 재시도하세요.')];
                break;
            }
            
            // 모든 스토리지 정보 가져오기
            $allStorages = $db->load('storages');
            $storagesWithPath = [];
            $remoteStorages = [];
            $remoteAdapterTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
            
            foreach ($allStorages as $s) {
                $sType = $s['storage_type'] ?? 'local';
                if (in_array($sType, $remoteAdapterTypes)) {
                    $remoteStorages[] = $s;
                } else {
                    $path = $storage->getRealPath($s['id']);
                    if ($path && is_dir($path)) {
                        $storagesWithPath[] = [
                            'id' => $s['id'],
                            'name' => $s['name'],
                            'path' => $path
                        ];
                    }
                }
            }
            
            // 로컬 재구축
            $results = $fileIndex->rebuildAll($storagesWithPath);
            $totalCount = array_sum(array_column($results, 'count'));
            
            // 원격 스토리지 재구축 (개별 타임아웃)
            foreach ($remoteStorages as $rs) {
                try {
                    @set_time_limit(600); // 스토리지당 10분 (대용량 FTP 고려)
                    require_once __DIR__ . '/api/StorageAdapter.php';
                    $adapter = StorageAdapterFactory::create($rs);
                    if ($adapter->connect()) {
                        $count = $fileIndex->rebuildStorageRemote($rs['id'], $adapter);
                        $adapter->disconnect();
                        $results[$rs['id']] = ['name' => $rs['name'], 'count' => $count];
                        $totalCount += $count;
                    } else {
                        $results[$rs['id']] = ['name' => $rs['name'], 'count' => 0, 'error' => $adapter->getLastError() ?: 'Connection failed'];
                    }
                } catch (Throwable $e) {
                    $results[$rs['id']] = ['name' => $rs['name'], 'count' => 0, 'error' => $e->getMessage()];
                }
            }
            
            $result = [
                'success' => true,
                'message' => __f('index_rebuild_complete', ['count' => $totalCount]),
                'details' => $results,
                'stats' => $fileIndex->getStats()
            ];
            break;
        
        case 'index_rebuild_storage':
            $auth->requireRealAdmin();
            @set_time_limit(600); // 대용량 원격 스토리지 고려 10분
            @ini_set('memory_limit', '512M');
            session_write_close();
            $storageId = (int)($input['storage_id'] ?? 0);
            
            if (!$storageId) {
                $result = ['success' => false, 'error' => __('api_err_storage_id_required', '스토리지 ID가 필요합니다.')];
                break;
            }
            
            $storageInfo = $storage->getStorageById($storageId);
            if (!$storageInfo) {
                $result = ['success' => false, 'error' => __('storage_not_found', '스토리지를 찾을 수 없습니다.')];
                break;
            }
            $storageType = $storageInfo['storage_type'] ?? 'local';
            $remoteAdapterTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
            $fileIndex = FileIndex::getInstance();
            
            if (!$fileIndex->isAvailable()) {
                $result = ['success' => false, 'error' => __('api_index_unavailable', '인덱스 DB 사용 불가. 인덱스 초기화 후 재시도하세요.')];
                break;
            }
            
            if (in_array($storageType, $remoteAdapterTypes)) {
                // 원격 스토리지: 어댑터 기반 인덱싱
                try {
                    require_once __DIR__ . '/api/StorageAdapter.php';
                    $config = decryptStorageConfig($storageInfo['config'] ?? '');
                    $adapter = StorageAdapterFactory::create($storageInfo);
                    $connectStart = microtime(true);
                    if (!$adapter->connect()) {
                        $elapsed = round(microtime(true) - $connectStart, 2);
                        $result = ['success' => false, 'error' => $adapter->getLastError() ?: __('storage_connect_failed', '스토리지 연결 실패')];
                        break;
                    }
                    $elapsed = round(microtime(true) - $connectStart, 2);
                    
                    // 연결 테스트: 루트 목록 조회
                    $listStart = microtime(true);
                    $testList = $adapter->list('');
                    $listElapsed = round(microtime(true) - $listStart, 2);
                    if (count($testList) === 0) {
                        // 원격 스토리지 빈 목록 - 인덱싱 계속 진행
                    }
                    
                    $rebuildStart = microtime(true);
                    $count = $fileIndex->rebuildStorageRemote($storageId, $adapter);
                    $rebuildElapsed = round(microtime(true) - $rebuildStart, 2);
                    $adapter->disconnect();
                } catch (Throwable $e) {
                    $result = ['success' => false, 'error' => $e->getMessage()];
                    break;
                }
            } else {
                // 로컬: 파일시스템 기반 인덱싱
                $path = $storage->getRealPath($storageId);
                if (!$path || !is_dir($path)) {
                    $result = ['success' => false, 'error' => __('storage_path_not_found')];
                    break;
                }
                $rebuildStart = microtime(true);
                $count = $fileIndex->rebuildStorage($storageId, $path);
                $rebuildElapsed = round(microtime(true) - $rebuildStart, 2);
            }
            
            $result = [
                'success' => true,
                'message' => __f('index_rebuild_complete', ['count' => $count]),
                'count' => $count
            ];
            break;
        
        case 'index_rebuild_stream':
            // SSE 기반 인덱스 재구축 (진행률 실시간 보고 — 대용량 원격 스토리지 타임아웃 방지)
            $auth->requireRealAdmin();
            @set_time_limit(0); // 무제한
            @ini_set('memory_limit', '512M');
            session_write_close();
            
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            @ini_set('output_buffering', 'Off');
            @ini_set('zlib.output_compression', 'Off');
            while (ob_get_level()) ob_end_clean();
            
            $storageId = (int)($_GET['storage_id'] ?? 0);
            if (!$storageId) {
                echo "data: " . json_encode(['type' => 'error', 'error' => 'Storage ID required']) . "\n\n";
                flush(); exit;
            }
            
            $storageInfo = $storage->getStorageById($storageId);
            if (!$storageInfo) {
                echo "data: " . json_encode(['type' => 'error', 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')]) . "\n\n";
                flush(); exit;
            }
            
            $fileIndex = FileIndex::getInstance();
            if (!$fileIndex->isAvailable()) {
                echo "data: " . json_encode(['type' => 'error', 'error' => __('api_index_unavailable')]) . "\n\n";
                flush(); exit;
            }
            
            $storageType = $storageInfo['storage_type'] ?? 'local';
            $remoteAdapterTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
            
            $progressCb = function($count, $currentPath) {
                echo "data: " . json_encode([
                    'type' => 'progress',
                    'count' => $count,
                    'current_path' => mb_substr(basename($currentPath), 0, 50)
                ]) . "\n\n";
                flush();
            };
            
            try {
                if (in_array($storageType, $remoteAdapterTypes)) {
                    require_once __DIR__ . '/api/StorageAdapter.php';
                    $adapter = StorageAdapterFactory::create($storageInfo);
                    if (!$adapter->connect()) {
                        echo "data: " . json_encode(['type' => 'error', 'error' => $adapter->getLastError() ?: 'Connection failed']) . "\n\n";
                        flush(); exit;
                    }
                    echo "data: " . json_encode(['type' => 'progress', 'count' => 0, 'current_path' => 'Connected...']) . "\n\n";
                    flush();
                    $count = $fileIndex->rebuildStorageRemote($storageId, $adapter, $progressCb);
                    $adapter->disconnect();
                } else {
                    $path = $storage->getRealPath($storageId);
                    if (!$path || !is_dir($path)) {
                        echo "data: " . json_encode(['type' => 'error', 'error' => 'Storage path not found']) . "\n\n";
                        flush(); exit;
                    }
                    // Nginx + PHP-FPM 환경에서 SSE 응답이 너무 빨리 끝나면 (빈 폴더 등)
                    //   첫 청크가 'complete' 하나뿐이라 버퍼링되어 클라이언트 전달 실패 가능성
                    //   → 인위적 progress 1개 먼저 송출해서 SSE 스트림 초기 플러시 유도 (원격 'Connected...'와 동일 패턴)
                    echo "data: " . json_encode(['type' => 'progress', 'count' => 0, 'current_path' => '']) . "\n\n";
                    flush();
                    $count = $fileIndex->rebuildStorage($storageId, $path);
                }
                
                echo "data: " . json_encode([
                    'type' => 'complete',
                    'success' => true,
                    'count' => $count
                ]) . "\n\n";
                flush();
            } catch (Throwable $e) {
                echo "data: " . json_encode(['type' => 'error', 'error' => $e->getMessage()]) . "\n\n";
                flush();
            }
            exit;
        
        case 'index_clear':
            $auth->requireRealAdmin();
            $fileIndex = FileIndex::getInstance();
            $fileIndex->clearAll();
            $result = ['success' => true, 'message' => __('api_index_cleared', '인덱스가 초기화되었습니다.')];
            break;
        
        case 'index_sync':
            // 증분 동기화: 파일시스템 변경분 감지 (auto_index 활성 시 주기적 호출)
            $auth->requireLogin();
            @set_time_limit(0);
            session_write_close();
            
            // ★ 전역 뮤텍스 락 — 여러 worker가 동시에 index_sync 돌리는 것 방지
            // 5분마다 클라이언트가 호출하므로 이전 호출이 아직 진행 중일 수 있음 → DB 경쟁/중복 작업 방지
            // ★ 락 파일 생성/열기 실패해도 기능은 계속 작동 (권한 문제 등으로 기능 전체가 죽으면 안 됨)
            $_syncLockDir = defined('DATA_PATH') ? DATA_PATH : __DIR__ . '/data';
            $_syncLockFile = $_syncLockDir . '/index_sync.lock';
            $_syncLockFp = @fopen($_syncLockFile, 'c');
            $_syncLockAcquired = false;
            if ($_syncLockFp) {
                // Non-blocking 락 시도 — 이미 다른 worker가 쥐고 있으면 skip
                if (@flock($_syncLockFp, LOCK_EX | LOCK_NB)) {
                    $_syncLockAcquired = true;
                } else {
                    // 다른 worker가 이미 실행 중 — skip
                    @fclose($_syncLockFp);
                    $_syncLockFp = null;
                    $result = ['success' => true, 'skipped' => true, 'message' => 'Already running in another worker'];
                    break;
                }
            }
            // $_syncLockFp가 false/null이어도 계속 진행 (권한 없는 환경에서도 기능 유지)
            // 단, 이 경우 중복 실행 방어 없음 — 극히 드문 상황이고 부작용은 DB 경쟁(성능 저하)뿐
            
            try {
                $appSettings = $db->load('settings');
                if (empty($appSettings['auto_index'])) {
                    $result = ['success' => false, 'error' => 'Auto index disabled'];
                } else {
                    $fileIndex = FileIndex::getInstance();
                    if (!$fileIndex->isAvailable()) {
                        $result = ['success' => false, 'error' => 'Index not available'];
                    } else {
                        // 동기화 주기: 시스템설정에서 지정 (분 단위, 기본 24시간=1440분)
                        // 파일 업로드/삭제 시 addFile/removeFile이 즉시 반영하므로 주기 스캔은 외부 변경 감지용
                        $indexIntervalMin = (int)($appSettings['index_sync_interval_minutes'] ?? 1440);
                        if ($indexIntervalMin < 1) $indexIntervalMin = 1440;
                        $lastSync = $fileIndex->getMeta('last_sync_time') ?: '0';
                        
                        // ★ 체크포인트가 하나라도 있으면 interval 무시하고 계속 진행
                        // (23TB+ 대용량 스토리지는 한 번에 못 끝나므로 여러 번 나눠서 스캔)
                        $hasCheckpoint = false;
                        $_dataDirChk = defined('DATA_PATH') ? DATA_PATH : __DIR__ . '/data';
                        foreach (glob($_dataDirChk . '/index_sync_checkpoint_*.json') as $cp) {
                            $hasCheckpoint = true;
                            break;
                        }
                        
                        if (!$hasCheckpoint && time() - (int)$lastSync < $indexIntervalMin * 60) {
                            $result = ['success' => true, 'skipped' => true, 'message' => 'Too soon'];
                        } else {
                            $fileIndex->setMeta('last_sync_time', (string)time());
                            
                            $allStorages = $db->load('storages');
                            $totalAdded = 0;
                            $totalRemoved = 0;
                            $totalUpdated = 0;
                            $skipTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
                            $indexSyncOverallStart = microtime(true);
                            $indexPerfLog = DATA_PATH . '/scan_perf.log';
                            $indexPerfEnabled = is_file($indexPerfLog);
                            
                            if ($indexPerfEnabled) {
                                // 5MB 초과 시 뒤쪽 50%만 유지
                                if (filesize($indexPerfLog) > 5 * 1024 * 1024) {
                                    $c = @file_get_contents($indexPerfLog);
                                    if ($c !== false) @file_put_contents($indexPerfLog, substr($c, strlen($c) / 2));
                                }
                                @file_put_contents($indexPerfLog,
                                    sprintf("[%s] index_sync start (interval=%dmin)\n",
                                        date('Y-m-d H:i:s'), $indexIntervalMin),
                                    FILE_APPEND | LOCK_EX);
                            }
                            
                            foreach ($allStorages as $s) {
                                $sType = $s['storage_type'] ?? 'local';
                                if (in_array($sType, $skipTypes)) continue;
                                
                                $path = $storage->getRealPath($s['id']);
                                if ($path && is_dir($path)) {
                                    _sessionDebugLog('STEP:indexsync_storage_begin', 'sid=' . $s['id']);
                                    $syncStart = microtime(true);
                                    $syncResult = $fileIndex->syncStorage($s['id'], $path);
                                    $elapsed = round(microtime(true) - $syncStart, 1);
                                    _sessionDebugLog('STEP:indexsync_storage_done', sprintf('sid=%d elapsed=%.1fs +%d ~%d -%d', $s['id'], $elapsed, $syncResult['added'], $syncResult['updated'], $syncResult['removed']));
                                    $totalAdded += $syncResult['added'];
                                    $totalRemoved += $syncResult['removed'];
                                    $totalUpdated += $syncResult['updated'];
                                    
                                    if ($indexPerfEnabled) {
                                        // 체크포인트 정보도 같이 기록
                                        $cpInfo = '';
                                        if (!empty($syncResult['resumed'])) {
                                            $cpInfo .= ' [resumed]';
                                        }
                                        if (isset($syncResult['remaining_dirs']) && $syncResult['remaining_dirs'] > 0) {
                                            $cpInfo .= ' [paused remaining=' . $syncResult['remaining_dirs'] . ']';
                                        } elseif (isset($syncResult['processed_dirs']) && $syncResult['processed_dirs'] > 0) {
                                            $cpInfo .= ' [complete]';
                                        }
                                        @file_put_contents($indexPerfLog,
                                            sprintf("[%s] index_sync storage storage_id=%d name=%s elapsed=%.1fs +%d ~%d -%d%s\n",
                                                date('Y-m-d H:i:s'), $s['id'],
                                                substr($s['name'] ?? '?', 0, 30),
                                                $elapsed, $syncResult['added'], $syncResult['updated'], $syncResult['removed'],
                                                $cpInfo),
                                            FILE_APPEND | LOCK_EX);
                                    }
                                }
                            }
                            
                            if ($indexPerfEnabled) {
                                $overallElapsed = round(microtime(true) - $indexSyncOverallStart, 1);
                                @file_put_contents($indexPerfLog,
                                    sprintf("[%s] index_sync done total_elapsed=%.1fs +%d ~%d -%d\n",
                                        date('Y-m-d H:i:s'), $overallElapsed,
                                        $totalAdded, $totalUpdated, $totalRemoved),
                                    FILE_APPEND | LOCK_EX);
                            }
                            
                            $result = [
                                'success' => true,
                                'added' => $totalAdded,
                                'removed' => $totalRemoved,
                                'updated' => $totalUpdated
                            ];
                        }
                    }
                }
            } catch (Throwable $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            } finally {
                // ★ 반드시 락 해제 (예외/정상 모두) — acquired된 경우에만
                if (isset($_syncLockFp) && $_syncLockFp && !empty($_syncLockAcquired)) {
                    @flock($_syncLockFp, LOCK_UN);
                }
                if (isset($_syncLockFp) && $_syncLockFp) {
                    @fclose($_syncLockFp);
                }
            }
            break;
        
        // 인덱스 데이터 조회 (디버깅용)
        case 'index_lookup':
            $auth->requireRealAdmin();
            $filepath = $input['filepath'] ?? '';
            $fileIndex = FileIndex::getInstance();
            
            if (!$fileIndex->isAvailable()) {
                $result = ['success' => false, 'error' => __('api_index_unavailable', '인덱스 사용 불가')];
                break;
            }
            
            // 파일명으로 검색하여 인덱스 데이터 확인
            $filename = basename($filepath);
            $stmt = $fileIndex->getDb()->prepare('SELECT * FROM files WHERE filename = :filename');
            $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
            $res = $stmt->execute();
            
            $records = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $records[] = $row;
            }
            
            $result = ['success' => true, 'records' => $records, 'searched_filename' => $filename];
            break;
            
        case 'info':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            { $_infoPath = $_GET['path'] ?? ''; $_infoDir = dirname($_infoPath); if ($_infoDir === '.') $_infoDir = '';
              if (!$storage->checkFolderPermission((int)($_GET['storage_id'] ?? 0), $_infoDir ?: $_infoPath)) {
                  $result = ['success' => false, 'error' => 'No permission']; break; }
            }
            $result = $fileManager->getInfo(
                (int)($_GET['storage_id'] ?? 0),
                $_GET['path'] ?? ''
            );
            break;
        
        // ===== 상세 정보 (EXIF 포함) =====
        case 'detailed_info':
            $auth->requireLogin();
            session_write_close();
            { $_diPath = $_GET['path'] ?? ''; $_diDir = dirname($_diPath); if ($_diDir === '.') $_diDir = '';
              if (!$storage->checkFolderPermission((int)($_GET['storage_id'] ?? 0), $_diDir ?: $_diPath)) {
                  $result = ['success' => false, 'error' => 'No permission']; break; }
            }
            @set_time_limit(15);
            $result = $fileManager->getDetailedInfo(
                (int)($_GET['storage_id'] ?? 0),
                $_GET['path'] ?? ''
            );
            break;
        
        // ===== 드래그앤드롭 =====
        case 'drag_drop':
            $auth->requireLogin();
            // 폴더별 권한 체크 (대상 폴더 쓰기)
            $ddDest = $input['dest'] ?? '';
            $ddSid = (int)($input['storage_id'] ?? 0);
            if (!$storage->checkFolderPermission($ddSid, $ddDest, 'can_write')) {
                $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')]; break;
            }
            $result = $fileManager->dragDrop(
                (int)($input['storage_id'] ?? 0),
                $input['sources'] ?? [],
                $input['dest'] ?? '',
                $input['action'] ?? 'move'
            );
            break;
        
        // ===== 공유 =====
        case 'shares':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $result = ['success' => true, 'shares' => $shareManager->getShares()];
            break;
        
        case 'share_clear_upload_notify':
            $auth->requireLogin();
            $cu = $auth->getUser();
            $result = $shareManager->clearShareUploadNotify(
                (int)$cu['id'],
                isset($input['share_id']) ? (int)$input['share_id'] : null,
                isset($input['storage_id']) ? (int)$input['storage_id'] : null,
                $input['folder_path'] ?? null,
                !empty($input['is_internal']),
                !empty($input['clear_all'])
            );
            break;
        
        case 'share_upload_notify_list':
            $auth->requireLogin();
            session_write_close();
            $cu = $auth->getUser();
            $result = $shareManager->getShareUploadNotifications((int)$cu['id']);
            break;
        
        case 'share_count':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $shares = $db->load('shares');
            $userId = $auth->getUserId();
            $now = time();
            $myCount = 0;
            $totalCount = 0;
            foreach ($shares as $s) {
                // 만료된 공유 제외
                if (!empty($s['expire_at']) && strtotime($s['expire_at']) < $now) continue;
                // 다운로드 제한 초과 제외
                if (!empty($s['max_downloads']) && ($s['download_count'] ?? 0) >= $s['max_downloads']) continue;
                $totalCount++;
                if ((int)($s['created_by'] ?? 0) === (int)$userId) $myCount++;
            }
            $result = ['success' => true, 'my_count' => $myCount, 'total_count' => $totalCount];
            break;
            
        case 'share_check':
            $auth->requireLogin();
            $result = $shareManager->checkShare(
                (int)($input['storage_id'] ?? 0),
                $input['path'] ?? ''
            );
            break;
            
        case 'share_create':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            // 폴더별 읽기 권한 체크
            { $shDir = dirname($path); if ($shDir === '.') $shDir = '';
              if (!$storage->checkFolderPermission($storageId, $shDir ?: $path, 'can_read')) {
                  $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break; }
            }
            
            $result = $shareManager->createShare($storageId, $path, $input);
            
            if ($result['success'] ?? false) {
                $storageInfo = $storage->getStorageById($storageId);
                $activityLog->log(ActivityLog::TYPE_SHARE_CREATE, [
                    'storage_id' => $storageId,
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $path,
                    'filename' => basename($path),
                    'details' => $result['token'] ?? ''
                ]);
            }
            break;
            
        case 'share_update_type':
            $auth->requireLogin();
            $shareId = (int)($input['id'] ?? 0);
            $newType = ($input['share_type'] ?? 'download');
            if (!in_array($newType, ['download', 'stream', 'filedrop'])) {
                $result = ['success' => false, 'error' => 'Invalid share type'];
                break;
            }
            // 권한 체크: 본인 공유 또는 관리자
            $shares = $db->load('shares');
            $canUpdate = false;
            foreach ($shares as $s) {
                if (($s['id'] ?? 0) == $shareId) {
                    if ((int)($s['created_by'] ?? 0) === (int)$auth->getUserId() || $auth->isAdmin()) {
                        $canUpdate = true;
                    }
                    break;
                }
            }
            if (!$canUpdate) {
                $result = ['success' => false, 'error' => __('no_permission', '권한이 없습니다')];
                break;
            }
            $updated = $db->update('shares', ['id' => $shareId], ['share_type' => $newType]);
            $result = $updated ? ['success' => true] : ['success' => false, 'error' => 'Share not found'];
            break;
        
        case 'share_delete':
            $auth->requireLogin();
            $shareId = (int)($input['id'] ?? 0);
            
            // 삭제 전 정보 가져오기
            $shares = $db->load('shares');
            $shareInfo = null;
            foreach ($shares as $s) {
                if (($s['id'] ?? 0) == $shareId) {
                    $shareInfo = $s;
                    break;
                }
            }
            
            $result = $shareManager->deleteShare($shareId);
            
            if (($result['success'] ?? false) && $shareInfo) {
                $activityLog->log(ActivityLog::TYPE_SHARE_DELETE, [
                    'storage_id' => $shareInfo['storage_id'] ?? 0,
                    'path' => $shareInfo['file_path'] ?? '',
                    'filename' => basename($shareInfo['file_path'] ?? ''),
                    'details' => $shareInfo['token'] ?? ''
                ]);
            }
            break;
            
        case 'share_update':
            $auth->requireLogin();
            $result = $shareManager->updateShare((int)($input['id'] ?? 0), $input);
            break;
            
        case 'share_access':
            // 로그인 불필요
            $token = $_GET['token'] ?? '';
            $result = $shareManager->accessShare($token, $input['password'] ?? null);
            
            // 공유 접근 로그
            if ($result['success'] ?? false) {
                $activityLog->log(ActivityLog::TYPE_SHARE_ACCESS, [
                    'storage_id' => $result['share']['storage_id'] ?? 0,
                    'path' => $result['share']['file_path'] ?? '',
                    'filename' => $result['share']['file_name'] ?? basename($result['share']['file_path'] ?? ''),
                    'details' => 'Token: ' . $token
                ]);
            }
            break;
            
        case 'share_download':
            // 로그인 불필요 — 세션 인증 플래그로 확인 (비밀번호 미전달)
            $shareManager->downloadShare(
                $_GET['token'] ?? '',
                null
            );
            break;
        
        // ===== 내부 사용자 간 공유 =====
        case 'internal_share_create':
            $auth->requireLogin();
            $result = $shareManager->createInternalShare(
                (int)($input['storage_id'] ?? 0),
                $input['path'] ?? '',
                (int)($input['target_user_id'] ?? 0),
                $input
            );
            if ($result['success'] ?? false) {
                $targetUser = $db->find('users', ['id' => (int)($input['target_user_id'] ?? 0)]);
                $storageInfo = $storage->getStorageById((int)($input['storage_id'] ?? 0));
                $activityLog->log(ActivityLog::TYPE_SHARE_CREATE, [
                    'storage_id' => (int)($input['storage_id'] ?? 0),
                    'storage_name' => $storageInfo['name'] ?? '',
                    'path' => $input['path'] ?? '',
                    'filename' => basename($input['path'] ?? ''),
                    'details' => 'Internal → ' . ($targetUser['username'] ?? '?')
                ]);
            }
            break;
        
        case 'internal_shares_by_me':
            $auth->requireLogin();
            $result = ['success' => true, 'shares' => $shareManager->getMyInternalShares()];
            break;
        
        case 'internal_shares_with_me':
            $auth->requireLogin();
            $result = ['success' => true, 'shares' => $shareManager->getSharedWithMe()];
            break;
        
        case 'internal_shares_all':
            $auth->requireLogin();
            $auth->requireAdmin();
            $result = ['success' => true, 'shares' => $shareManager->getAllInternalShares()];
            break;
        
        case 'internal_share_delete':
            $auth->requireLogin();
            $result = $shareManager->deleteInternalShare((int)($input['id'] ?? 0));
            break;
        
        case 'internal_share_update':
            $auth->requireLogin();
            $result = $shareManager->updateInternalShare(
                (int)($input['id'] ?? 0),
                $input['permission'] ?? 'read'
            );
            break;
        
        case 'internal_share_download':
            $auth->requireLogin();
            session_write_close();
            $shareManager->downloadInternalShare((int)($_GET['id'] ?? 0), $_GET['sub_path'] ?? '');
            break;
        
        case 'internal_share_list_folder':
            $auth->requireLogin();
            $result = $shareManager->listInternalShareFolder(
                (int)($input['id'] ?? 0),
                $input['sub_path'] ?? ''
            );
            break;
        
        case 'internal_share_upload':
            $auth->requireLogin();
            if (empty($_FILES['file'])) {
                $result = ['success' => false, 'error' => __('api_err_no_file', '파일이 없습니다.')];
            } else {
                $result = $shareManager->uploadToInternalShare(
                    (int)($_POST['share_id'] ?? 0),
                    $_POST['sub_path'] ?? '',
                    $_FILES['file'],
                    $_POST['duplicateAction'] ?? 'rename'
                );
            }
            break;
        
        case 'internal_share_chunk_upload':
            $auth->requireLogin();
            if (empty($_FILES['chunk'])) {
                $result = ['success' => false, 'error' => __('api_err_no_file', '파일이 없습니다.')];
            } else {
                $result = $shareManager->uploadToInternalShareChunk(
                    (int)($_POST['share_id'] ?? 0),
                    $_POST['sub_path'] ?? '',
                    [
                        'filename' => $_POST['filename'] ?? '',
                        'chunkIndex' => $_POST['chunkIndex'] ?? 0,
                        'totalChunks' => $_POST['totalChunks'] ?? 1,
                        'totalSize' => $_POST['totalSize'] ?? 0,
                        'uploadId' => $_POST['uploadId'] ?? '',
                        'lastModified' => $_POST['lastModified'] ?? 0,
                        'relativePath' => $_POST['relativePath'] ?? null,
                        'duplicateAction' => $_POST['duplicateAction'] ?? 'rename',
                        'file' => $_FILES['chunk'],
                    ]
                );
            }
            break;
        
        case 'internal_share_delete_file':
            $auth->requireLogin();
            $result = $shareManager->deleteInInternalShare(
                (int)($input['id'] ?? 0),
                $input['sub_path'] ?? ''
            );
            break;
        
        case 'internal_share_rename':
            $auth->requireLogin();
            $result = $shareManager->renameInInternalShare(
                (int)($input['id'] ?? 0),
                $input['sub_path'] ?? '',
                $input['new_name'] ?? ''
            );
            break;
        
        case 'internal_share_mkdir':
            $auth->requireLogin();
            $result = $shareManager->createFolderInInternalShare(
                (int)($input['id'] ?? 0),
                $input['sub_path'] ?? '',
                $input['folder_name'] ?? ''
            );
            break;
        
        case 'internal_share_count':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $userId = $auth->getUserId();
            $allShares = $db->load('internal_shares');
            $withMeCount = 0;
            $byMeCount = 0;
            $totalCount = 0;
            foreach (is_array($allShares) ? $allShares : [] as $s) {
                $totalCount++;
                if ((int)($s['shared_with'] ?? 0) === $userId) $withMeCount++;
                if ((int)($s['shared_by'] ?? 0) === $userId) $byMeCount++;
            }
            $result = [
                'success' => true,
                'with_me' => $withMeCount,
                'by_me' => $byMeCount,
                'total' => $totalCount
            ];
            break;
        
        case 'user_search':
            $auth->requireLogin();
            $result = ['success' => true, 'users' => $shareManager->searchUsers($input['query'] ?? '')];
            break;
        
        // ===== 파일 드롭 업로드 =====
        case 'filedrop_upload':
            // 로그인 불필요
            if (empty($_FILES['file'])) {
                $result = ['success' => false, 'error' => __('api_err_no_file', '파일이 없습니다.')];
            } else {
                $fdToken = $_POST['token'] ?? '';
                $fdPassword = $_POST['password'] ?? null;
                // 세션 인증 완료 시 비밀번호 불필요 (accessShare에서 세션 체크)
                $result = $shareManager->uploadToFileDrop(
                    $fdToken,
                    $_FILES['file'],
                    $fdPassword
                );
            }
            break;
        
        case 'filedrop_chunk_upload':
            // 로그인 불필요 (외부 링크 청크 업로드 — 대용량 지원)
            if (empty($_FILES['chunk'])) {
                $result = ['success' => false, 'error' => __('api_err_no_file', '파일이 없습니다.')];
            } else {
                $result = $shareManager->uploadToFileDropChunk(
                    $_POST['token'] ?? '',
                    [
                        'filename' => $_POST['filename'] ?? '',
                        'chunkIndex' => $_POST['chunkIndex'] ?? 0,
                        'totalChunks' => $_POST['totalChunks'] ?? 1,
                        'totalSize' => $_POST['totalSize'] ?? 0,
                        'uploadId' => $_POST['uploadId'] ?? '',
                        'lastModified' => $_POST['lastModified'] ?? 0,
                        'relativePath' => null,
                        'duplicateAction' => 'rename',
                        'file' => $_FILES['chunk'],
                    ],
                    $_POST['password'] ?? null
                );
            }
            break;
        
        // ===== 활동 로그 =====
        case 'activity_logs':
            $auth->requireAdminPerm('logins');
            $filters = [
                'user_id' => $input['user_id'] ?? null,
                'type' => $input['type'] ?? null,
                'storage_id' => $input['storage_id'] ?? null,
                'date_from' => $input['date_from'] ?? null,
                'date_to' => $input['date_to'] ?? null,
                'search' => $input['search'] ?? null
            ];
            $page = (int)($input['page'] ?? 1);
            $limit = (int)($input['limit'] ?? 50);
            $result = $activityLog->getLogs($filters, $page, $limit);
            break;
            
        case 'activity_logs_clear':
            $auth->requireAdminPerm('logins');
            $beforeDate = $input['before_date'] ?? null;
            $result = $activityLog->clearLogs($beforeDate);
            break;
            
        case 'activity_stats':
            $auth->requireLogin();
            $user = $auth->getUser();
            $targetUserId = ($user['role'] ?? '') === 'admin' && isset($input['user_id']) 
                ? (int)$input['user_id'] 
                : $user['id'];
            $result = ['success' => true, 'stats' => $activityLog->getUserStats($targetUserId)];
            break;
        
        // ===== 설정 백업 =====
        case 'config_backup':
            try {
            $auth->requireAdminPerm('system_settings');
            
            session_write_close(); // 세션 락 해제
            $dataPath = DATA_PATH;
            
            if (!is_dir($dataPath) || !is_readable($dataPath)) {
                throw new \Exception('DATA_PATH not accessible');
            }
            
            // 메모리 제한 늘리기
            @ini_set('memory_limit', '512M');
            @set_time_limit(300);
            
            // 제외 패턴
            $excludeFiles = ['file_index.db', '.htaccess', '.htpasswd', 'Thumbs.db'];
            $excludeExtensions = ['lock', 'log', 'db', 'sqlite', 'tmp'];
            $excludeDirs = ['trash_files', 'thumbcache', 'cache'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB 이상 파일 스킵
            
            // 백업 데이터 수집
            $backupData = [
                'backup_info' => [
                    'version' => defined('APP_VERSION') ? APP_VERSION : 'unknown',
                    'created_at' => date('Y-m-d H:i:s'),
                    'php_version' => PHP_VERSION,
                    'server' => PHP_OS
                ],
                'files' => []
            ];
            
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dataPath, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
            } catch (\Throwable $dirErr) {
                throw new \Exception('Cannot read data directory');
            }
            
            foreach ($iterator as $file) {
                try {
                    $relativePath = str_replace($dataPath . DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $relativePath = str_replace('\\', '/', $relativePath);
                    
                    // 디렉토리 제외
                    $skip = false;
                    foreach ($excludeDirs as $exDir) {
                        if (strpos($relativePath, $exDir . '/') === 0 || $relativePath === $exDir) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip) continue;
                    
                    if (!$file->isFile() || !$file->isReadable()) continue;
                    
                    $fileName = $file->getFilename();
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    
                    // 파일 제외
                    if (in_array($fileName, $excludeFiles)) continue;
                    if (in_array($ext, $excludeExtensions)) continue;
                    
                    // 큰 바이너리 파일 스킵 (JSON은 항상 포함)
                    $fileSize = $file->getSize();
                    if ($ext !== 'json' && $fileSize > $maxFileSize) continue;
                    
                    $content = @file_get_contents($file->getPathname());
                    if ($content === false) continue;
                    
                    if ($ext === 'json') {
                        $decoded = json_decode($content, true);
                        $backupData['files'][$relativePath] = ($decoded !== null) ? $decoded : $content;
                    } else {
                        $backupData['files'][$relativePath] = ['_binary' => true, '_data' => base64_encode($content)];
                    }
                } catch (\Throwable $fileErr) {
                    continue; // 개별 파일 에러는 스킵
                }
            }
            
            $backupName = 'FileStation_config_' . date('Y-m-d_His') . '.json';
            $backupJson = json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            if ($backupJson === false) {
                throw new \Exception('JSON encode failed: ' . json_last_error_msg());
            }
            
            // 파일 다운로드 - header_remove 안전 처리
            if (function_exists('header_remove')) {
                @header_remove('Content-Type');
                @header_remove('Content-Security-Policy');
            }
            header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename=\"{$backupName}\"; filename*=UTF-8''" . rawurlencode($backupName));
            header('Content-Length: ' . strlen($backupJson));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            echo $backupJson;
            exit;
            } catch (\Throwable $e) {
                http_response_code(500);
                if (function_exists('header_remove')) @header_remove();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => __('config_backup_failed', '백업 생성에 실패했습니다.')]);
                exit;
            }
            break;
        
        // ===== 설정 복원 =====
        case 'config_restore':
            $auth->requireAdminPerm('system_settings');
            
            session_write_close(); // 세션 락 해제
            if (empty($_FILES['backup_file'])) {
                $result = ['success' => false, 'error' => __('config_restore_no_file', '백업 파일을 선택하세요.')];
                break;
            }
            
            // 파일 크기 제한 (10MB) — DoS 방지
            if ($_FILES['backup_file']['size'] > 10 * 1024 * 1024) {
                $result = ['success' => false, 'error' => __('config_restore_too_large', '백업 파일이 너무 큽니다. (최대 10MB)')];
                break;
            }
            
            $uploadedFile = $_FILES['backup_file']['tmp_name'];
            $content = file_get_contents($uploadedFile);
            $backupData = json_decode($content, true);
            
            if (!$backupData || !isset($backupData['backup_info']) || !isset($backupData['files'])) {
                $result = ['success' => false, 'error' => __('config_restore_invalid_backup', 'FileStation 백업 파일이 아닙니다.')];
                break;
            }
            
            $dataPath = DATA_PATH;
            $restoredCount = 0;
            
            foreach ($backupData['files'] as $relativePath => $fileData) {
                $targetPath = $dataPath . '/' . $relativePath;
                
                // 보안: path traversal 차단
                if (strpos($relativePath, '..') !== false) continue;
                $realDataPath = realpath($dataPath);
                $parentDir = dirname($targetPath);
                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0755, true);
                }
                $realParent = realpath($parentDir);
                // 보안: isSubPath로 정확한 하위 경로 검증
                if ($realParent === false || !\isSubPath($realParent, $realDataPath)) continue;
                
                // 파일 복원
                // 보안: 허용 확장자만 복원 (PHP 등 실행 가능 파일 차단)
                $restoreExt = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
                $allowedRestoreExts = ['json', 'txt', 'ini', 'conf', 'cfg', 'log', 'ico', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
                if (!in_array($restoreExt, $allowedRestoreExts)) continue;
                
                if (is_array($fileData) && isset($fileData['_binary'])) {
                    // 바이너리 파일
                    file_put_contents($targetPath, base64_decode($fileData['_data']));
                } else {
                    // JSON 파일 — users.json 복원 시 보안 검증
                    if (basename($relativePath) === 'users.json' && is_array($fileData)) {
                        $validRoles = ['admin', 'sub_admin', 'user'];
                        $currentUserId = $_SESSION['user_id'] ?? null;
                        foreach ($fileData as &$_u) {
                            if (isset($_u['role']) && !in_array($_u['role'], $validRoles)) {
                                $_u['role'] = 'user';
                            }
                            // 현재 로그인한 관리자 계정은 복원에서 제외 (비밀번호 해시 보호)
                            if ($currentUserId && ($_u['id'] ?? null) == $currentUserId) {
                                $existingUsers = $db->load('users') ?: [];
                                foreach ($existingUsers as $eu) {
                                    if (($eu['id'] ?? null) == $currentUserId) {
                                        $_u['password'] = $eu['password'];
                                        $_u['role'] = $eu['role'];
                                        break;
                                    }
                                }
                            }
                        }
                        unset($_u);
                    }
                    file_put_contents($targetPath, json_encode($fileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
                $restoredCount++;
            }
            
            $backupInfo = $backupData['backup_info'];
            $result = [
                'success' => true,
                'message' => __f('config_restore_success', ['count' => $restoredCount], "{$restoredCount}개 설정 파일이 복원되었습니다. 페이지를 새로고침하세요."),
                'backup_version' => $backupInfo['version'] ?? 'unknown',
                'restored_files' => $restoredCount
            ];
            break;
        
        // ===== 시스템 =====
        case 'system_info':
            $auth->requireAdminPerm('system_info');
            
            session_write_close(); // 세션 락 해제
            // 사용자 통계
            $users = $db->load('users');
            $totalUsers = count($users);
            
            // 세션 통계
            $sessions = $db->load('sessions');
            $activeSessions = count(array_filter($sessions, function($s) {
                return ($s['expires_at'] ?? 0) > time();
            }));
            
            // 스토리지 통계
            $storages = $db->load('storages');
            $totalStorages = count($storages);
            
            // 공유 통계
            $shares = $db->load('shares');
            $totalShares = count(array_filter($shares, function($s) {
                return empty($s['expires_at']) || strtotime($s['expires_at']) > time();
            }));
            
            // PHP 확장 모듈 체크
            $extensions = [
                'sqlite3' => [
                    'loaded' => extension_loaded('sqlite3'),
                    'required' => false,
                    'desc' => __('ext_desc_sqlite3', '빠른 검색 기능 (선택)')
                ],
                'zip' => [
                    'loaded' => extension_loaded('zip'),
                    'required' => true,
                    'desc' => __('ext_desc_zip', '압축/해제 기능')
                ],
                'gd' => [
                    'loaded' => extension_loaded('gd'),
                    'required' => false,
                    'desc' => __('ext_desc_gd', '이미지 처리 (썸네일)')
                ],
                'exif' => [
                    'loaded' => extension_loaded('exif'),
                    'required' => false,
                    'desc' => __('ext_desc_exif', '이미지 EXIF 정보')
                ],
                'mbstring' => [
                    'loaded' => extension_loaded('mbstring'),
                    'required' => true,
                    'desc' => __('ext_desc_mbstring', '다국어 문자열 처리')
                ],
                'json' => [
                    'loaded' => extension_loaded('json'),
                    'required' => true,
                    'desc' => __('ext_desc_json', 'JSON 처리')
                ],
                'curl' => [
                    'loaded' => extension_loaded('curl'),
                    'required' => false,
                    'desc' => __('ext_desc_curl', '외부 API 호출')
                ],
                'fileinfo' => [
                    'loaded' => extension_loaded('fileinfo'),
                    'required' => false,
                    'desc' => __('ext_desc_fileinfo', '파일 MIME 타입 감지')
                ]
            ];
            
            // 폴더 권한 체크
            $folders = [
                'data' => [
                    'path' => DATA_PATH,
                    'writable' => is_writable(DATA_PATH),
                    'desc' => __('dir_data_storage', '데이터 저장')
                ],
                'users' => [
                    'path' => USER_FILES_ROOT,
                    'writable' => is_dir(USER_FILES_ROOT) && is_writable(USER_FILES_ROOT),
                    'desc' => __('dir_user_files', '사용자 파일')
                ],
                'shared' => [
                    'path' => SHARED_FILES_ROOT,
                    'writable' => is_dir(SHARED_FILES_ROOT) && is_writable(SHARED_FILES_ROOT),
                    'desc' => __('dir_shared_folder', '공유 폴더')
                ],
                'trash' => [
                    'path' => TRASH_PATH,
                    'writable' => is_dir(TRASH_PATH) && is_writable(TRASH_PATH),
                    'desc' => __('dir_trash', '휴지통')
                ],
                'chunks' => [
                    'path' => DATA_PATH . '/chunks',
                    'writable' => is_dir(DATA_PATH . '/chunks') && is_writable(DATA_PATH . '/chunks'),
                    'desc' => __('dir_upload_temp', '업로드 임시파일')
                ]
            ];
            
            // 디스크 공간 (data 폴더 기준)
            $diskFree = @disk_free_space(DATA_PATH);
            $diskTotal = @disk_total_space(DATA_PATH);
            
            // 검색 인덱스 상태
            $fileIndex = FileIndex::getInstance();
            $indexStats = $fileIndex->getStats();
            
            // 검색 인덱스 상세 정보 추가
            $indexDbPath = DATA_PATH . '/file_index.db';
            $indexStats['db_path'] = $indexDbPath;
            $indexStats['db_exists'] = file_exists($indexDbPath);
            $indexStats['db_size'] = file_exists($indexDbPath) ? filesize($indexDbPath) : 0;
            $indexStats['db_modified'] = file_exists($indexDbPath) ? date('Y-m-d H:i:s', filemtime($indexDbPath)) : null;
            
            // 스토리지별 인덱스 통계
            $storageStats = [];
            foreach ($storages as $sid => $st) {
                $stats = $fileIndex->getStorageStats((int)$sid);
                $storageStats[$sid] = [
                    'name' => $st['name'] ?? __f('storage_default_name', ['sid' => $sid]),
                    'type' => $st['type'] ?? 'unknown',
                    'total' => $stats['total'],
                    'files' => $stats['files'],
                    'folders' => $stats['folders']
                ];
            }
            $indexStats['storage_stats'] = $storageStats;
            
            // 세션 정보
            $sessionInfo = [
                'save_handler' => ini_get('session.save_handler'),
                'save_path' => ini_get('session.save_path') ?: __('default_value'),
                'gc_maxlifetime' => ini_get('session.gc_maxlifetime'),
                'cookie_lifetime' => ini_get('session.cookie_lifetime'),
                'cookie_secure' => (bool)ini_get('session.cookie_secure'),
                'cookie_httponly' => (bool)ini_get('session.cookie_httponly'),
                'cookie_samesite' => ini_get('session.cookie_samesite') ?: 'None'
            ];
            
            // OPcache 상태
            $opcacheInfo = ['enabled' => false];
            if (function_exists('opcache_get_status')) {
                $opcStatus = @opcache_get_status(false);
                if ($opcStatus) {
                    $memory = $opcStatus['memory_usage'] ?? [];
                    $stats = $opcStatus['opcache_statistics'] ?? [];
                    $used = ($memory['used_memory'] ?? 0) + ($memory['wasted_memory'] ?? 0);
                    $total = $used + ($memory['free_memory'] ?? 0);
                    $hitRate = isset($stats['hits'], $stats['misses']) && ($stats['hits'] + $stats['misses']) > 0
                        ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 1) : 0;
                    
                    $opcacheInfo = [
                        'enabled' => $opcStatus['opcache_enabled'] ?? false,
                        'memory_total' => $total,
                        'memory_used' => $used,
                        'memory_free' => $memory['free_memory'] ?? 0,
                        'hit_rate' => $hitRate,
                        'cached_scripts' => $stats['num_cached_scripts'] ?? 0,
                        'hits' => $stats['hits'] ?? 0,
                        'misses' => $stats['misses'] ?? 0
                    ];
                }
            }
            
            // APCu 상태
            $apcuInfo = ['enabled' => false];
            if (function_exists('apcu_cache_info')) {
                $apcuCacheInfo = @apcu_cache_info(true);
                $apcuSma = @apcu_sma_info(true);
                if ($apcuCacheInfo) {
                    $hitRate = isset($apcuCacheInfo['num_hits'], $apcuCacheInfo['num_misses']) 
                        && ($apcuCacheInfo['num_hits'] + $apcuCacheInfo['num_misses']) > 0
                        ? round($apcuCacheInfo['num_hits'] / ($apcuCacheInfo['num_hits'] + $apcuCacheInfo['num_misses']) * 100, 1) : 0;
                    
                    $apcuInfo = [
                        'enabled' => true,
                        'memory_total' => $apcuSma['seg_size'] ?? 0,
                        'memory_used' => ($apcuSma['seg_size'] ?? 0) - ($apcuSma['avail_mem'] ?? 0),
                        'memory_free' => $apcuSma['avail_mem'] ?? 0,
                        'hit_rate' => $hitRate,
                        'entries' => $apcuCacheInfo['num_entries'] ?? 0
                    ];
                }
            }
            
            // 보안 체크리스트
            $securityChecks = [
                'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'display_errors' => !ini_get('display_errors'),
                'cookie_httponly' => (bool)ini_get('session.cookie_httponly'),
                'cookie_secure' => (bool)ini_get('session.cookie_secure'),
                'expose_php' => !ini_get('expose_php'),
                'allow_url_include' => !ini_get('allow_url_include')
            ];
            
            // PHP 상세 정보
            $_nowKst = null;
            try { $_nowKst = new \DateTime('now', new \DateTimeZone('Asia/Seoul')); } catch (\Throwable $e) {}
            $phpInfo = [
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'timezone' => date_default_timezone_get(),
                'current_time' => date('Y-m-d H:i:s'),
                // 진단: 다양한 방식으로 시간 구해서 비교 (불일치 시 문제 감지)
                'current_time_kst_explicit' => $_nowKst ? $_nowKst->format('Y-m-d H:i:s') : 'ERROR',
                'current_time_utc' => gmdate('Y-m-d H:i:s'),
                'server_unix_timestamp' => time(),
                'tz_env' => getenv('TZ') ?: 'not set',
                'tz_ini' => ini_get('date.timezone') ?: 'not set',
                'sapi' => php_sapi_name(),
                'zend_version' => zend_version()
            ];
            
            // ========== 서버 리소스 모니터 ==========
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $isDocker = file_exists('/.dockerenv') || (is_readable('/proc/1/cgroup') && strpos(@file_get_contents('/proc/1/cgroup'), 'docker') !== false);
            $serverResources = [
                'is_windows' => $isWindows,
                'is_docker' => $isDocker,
                'hostname' => @gethostname() ?: 'Unknown',
                'cpu' => ['model' => 'Unknown', 'cores' => 0, 'threads' => 0, 'usage' => 0],
                'memory' => ['total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0],
                'network' => ['interfaces' => []],
                'traffic' => ['total_rx' => 0, 'total_tx' => 0, 'interfaces' => []],
                'webserver' => ['processes' => []],
                'uptime' => '',
                'disk_io' => ['read' => 0, 'write' => 0],
                'private_ip' => '',
                'public_ip' => __('check_unavailable')
            ];
            
            // Private IP
            $serverResources['private_ip'] = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : @gethostbyname(@gethostname());
            
            if ($isWindows) {
                // Windows CPU
                $cpuInfo = @shell_exec('wmic cpu get name,numberofcores,numberoflogicalprocessors /format:csv 2>nul');
                if ($cpuInfo) {
                    $lines = array_filter(explode("\n", trim($cpuInfo)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 4) {
                            $serverResources['cpu']['model'] = trim($parts[1]);
                            $serverResources['cpu']['cores'] = (int)$parts[2];
                            $serverResources['cpu']['threads'] = (int)$parts[3];
                        }
                    }
                }
                
                // Windows CPU Usage
                $cpuLoad = @shell_exec('wmic cpu get loadpercentage /format:csv 2>nul');
                if ($cpuLoad) {
                    $lines = array_filter(explode("\n", trim($cpuLoad)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 2) {
                            $serverResources['cpu']['usage'] = (int)$parts[1];
                        }
                    }
                }
                
                // Windows Memory
                $memInfo = @shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /format:csv 2>nul');
                if ($memInfo) {
                    $lines = array_filter(explode("\n", trim($memInfo)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 3) {
                            $freeKB = (int)$parts[1];
                            $totalKB = (int)$parts[2];
                            $serverResources['memory']['total'] = $totalKB * 1024;
                            $serverResources['memory']['free'] = $freeKB * 1024;
                            $serverResources['memory']['used'] = ($totalKB - $freeKB) * 1024;
                            if ($totalKB > 0) {
                                $serverResources['memory']['percent'] = round((($totalKB - $freeKB) / $totalKB) * 100, 1);
                            }
                        }
                    }
                }
                
                // Windows Network Traffic
                $trafficInfo = @shell_exec('wmic path Win32_PerfRawData_Tcpip_NetworkInterface get Name,BytesReceivedPersec,BytesSentPersec /format:csv 2>nul');
                if ($trafficInfo) {
                    $lines = array_filter(explode("\n", trim($trafficInfo)));
                    if (count($lines) > 1) {
                        array_shift($lines);
                        $totalRx = 0;
                        $totalTx = 0;
                        foreach ($lines as $line) {
                            $parts = str_getcsv($line);
                            if (count($parts) >= 4 && !empty(trim($parts[3]))) {
                                $rx = (int)$parts[1];
                                $tx = (int)$parts[2];
                                $totalRx += $rx;
                                $totalTx += $tx;
                                $serverResources['traffic']['interfaces'][] = [
                                    'name' => trim($parts[3]),
                                    'rx' => $rx,
                                    'tx' => $tx
                                ];
                            }
                        }
                        $serverResources['traffic']['total_rx'] = $totalRx;
                        $serverResources['traffic']['total_tx'] = $totalTx;
                    }
                }
                
                // Windows Disk I/O
                $diskIO = @shell_exec('wmic path Win32_PerfRawData_PerfDisk_PhysicalDisk where "Name=\'_Total\'" get DiskReadBytesPersec,DiskWriteBytesPersec /format:csv 2>nul');
                if ($diskIO) {
                    $lines = array_filter(explode("\n", trim($diskIO)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 3) {
                            $serverResources['disk_io']['read'] = (int)$parts[1];
                            $serverResources['disk_io']['write'] = (int)$parts[2];
                        }
                    }
                }
                
                // Windows Webserver Processes
                $webProcesses = [];
                $procs = ['httpd.exe' => 'Apache', 'nginx.exe' => 'Nginx', 'w3wp.exe' => 'IIS'];
                foreach ($procs as $proc => $name) {
                    $info = @shell_exec("wmic process where \"name='{$proc}'\" get processid,workingsetsize /format:csv 2>nul");
                    if ($info) {
                        $lines = array_filter(explode("\n", trim($info)));
                        if (count($lines) > 1) {
                            array_shift($lines);
                            $count = 0;
                            $totalMem = 0;
                            foreach ($lines as $line) {
                                $parts = str_getcsv($line);
                                if (count($parts) >= 3) {
                                    $count++;
                                    $totalMem += (int)$parts[2];
                                }
                            }
                            if ($count > 0) {
                                $webProcesses[] = ['name' => $name, 'count' => $count, 'memory' => $totalMem, 'icon' => '🌐'];
                            }
                        }
                    }
                }
                $serverResources['webserver']['processes'] = $webProcesses;
                
                // Windows Uptime
                $uptimeInfo = @shell_exec('wmic os get lastbootuptime /format:csv 2>nul');
                if ($uptimeInfo) {
                    $lines = array_filter(explode("\n", trim($uptimeInfo)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 2) {
                            $bootTime = $parts[1];
                            if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $bootTime, $m)) {
                                $bootTimestamp = mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
                                $uptimeSecs = time() - $bootTimestamp;
                                $days = floor($uptimeSecs / 86400);
                                $hours = floor(($uptimeSecs % 86400) / 3600);
                                $mins = floor(($uptimeSecs % 3600) / 60);
                                $serverResources['uptime'] = __f('uptime_format', ['days' => $days, 'hours' => $hours, 'mins' => $mins]);
                            }
                        }
                    }
                }
            } else {
                // Linux CPU
                if (is_readable('/proc/cpuinfo')) {
                    $cpuinfo = @file_get_contents('/proc/cpuinfo');
                    if ($cpuinfo) {
                        if (preg_match('/model name\s*:\s*(.+)/i', $cpuinfo, $m)) {
                            $serverResources['cpu']['model'] = trim($m[1]);
                        }
                        $serverResources['cpu']['threads'] = substr_count($cpuinfo, 'processor');
                        $serverResources['cpu']['cores'] = $serverResources['cpu']['threads'];
                    }
                }
                
                // Linux CPU Usage
                $load = @sys_getloadavg();
                if ($load !== false && $serverResources['cpu']['threads'] > 0) {
                    $serverResources['cpu']['usage'] = min(100, round(($load[0] / $serverResources['cpu']['threads']) * 100, 1));
                }
                
                // Linux Memory
                if (is_readable('/proc/meminfo')) {
                    $meminfo = @file_get_contents('/proc/meminfo');
                    if ($meminfo) {
                        preg_match('/MemTotal:\s*(\d+)/i', $meminfo, $total);
                        preg_match('/MemAvailable:\s*(\d+)/i', $meminfo, $available);
                        if (!empty($total[1])) {
                            $totalKB = (int)$total[1];
                            $availKB = isset($available[1]) ? (int)$available[1] : 0;
                            $serverResources['memory']['total'] = $totalKB * 1024;
                            $serverResources['memory']['free'] = $availKB * 1024;
                            $serverResources['memory']['used'] = ($totalKB - $availKB) * 1024;
                            if ($totalKB > 0) {
                                $serverResources['memory']['percent'] = round((($totalKB - $availKB) / $totalKB) * 100, 1);
                            }
                        }
                    }
                }
                
                // Linux Network Traffic
                if (is_readable('/proc/net/dev')) {
                    $netdev = @file_get_contents('/proc/net/dev');
                    if ($netdev) {
                        $lines = explode("\n", $netdev);
                        $totalRx = 0;
                        $totalTx = 0;
                        foreach ($lines as $line) {
                            if (preg_match('/^\s*(\w+):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                                $iface = $m[1];
                                $rx = (int)$m[2];
                                $tx = (int)$m[3];
                                if ($iface !== 'lo') {
                                    $totalRx += $rx;
                                    $totalTx += $tx;
                                    $serverResources['traffic']['interfaces'][] = [
                                        'name' => $iface,
                                        'rx' => $rx,
                                        'tx' => $tx
                                    ];
                                }
                            }
                        }
                        $serverResources['traffic']['total_rx'] = $totalRx;
                        $serverResources['traffic']['total_tx'] = $totalTx;
                    }
                }
                
                // Linux Disk I/O
                if (is_readable('/proc/diskstats')) {
                    $diskstats = @file_get_contents('/proc/diskstats');
                    if ($diskstats) {
                        $totalRead = 0;
                        $totalWrite = 0;
                        foreach (explode("\n", $diskstats) as $line) {
                            if (preg_match('/^\s*\d+\s+\d+\s+(sd[a-z]|nvme\d+n\d+|vd[a-z])\s+\d+\s+\d+\s+(\d+)\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                                $totalRead += (int)$m[2] * 512;
                                $totalWrite += (int)$m[3] * 512;
                            }
                        }
                        $serverResources['disk_io']['read'] = $totalRead;
                        $serverResources['disk_io']['write'] = $totalWrite;
                    }
                }
                
                // Linux Uptime
                if (is_readable('/proc/uptime')) {
                    $uptime = @file_get_contents('/proc/uptime');
                    if ($uptime) {
                        $uptimeSecs = (int)floatval($uptime);
                        $days = floor($uptimeSecs / 86400);
                        $hours = floor(($uptimeSecs % 86400) / 3600);
                        $mins = floor(($uptimeSecs % 3600) / 60);
                        $serverResources['uptime'] = __f('uptime_format', ['days' => $days, 'hours' => $hours, 'mins' => $mins]);
                    }
                }
            }
            
            // Public IP (캐시 사용)
            $publicIpCache = DATA_PATH . '/public_ip_cache.json';
            $publicIp = null;
            
            if (file_exists($publicIpCache)) {
                $cache = @json_decode(@file_get_contents($publicIpCache), true);
                if ($cache && isset($cache['ip']) && isset($cache['time']) && (time() - $cache['time']) < 3600) {
                    $publicIp = $cache['ip'];
                }
            }
            
            if (!$publicIp && function_exists('curl_init')) {
                $ipServices = ['http://ip-api.com/line/?fields=query', 'http://checkip.amazonaws.com'];
                foreach ($ipServices as $service) {
                    $ch = @curl_init();
                    if ($ch) {
                        curl_setopt_array($ch, [
                            CURLOPT_URL => $service,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 3,
                            CURLOPT_CONNECTTIMEOUT => 2
                        ]);
                        $ip = @curl_exec($ch);
                        @curl_close($ch);
                        if ($ip) {
                            $ip = trim($ip);
                            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                                $publicIp = $ip;
                                @file_put_contents($publicIpCache, json_encode(['ip' => $publicIp, 'time' => time()]));
                                break;
                            }
                        }
                    }
                }
            }
            
            if ($publicIp) {
                $serverResources['public_ip'] = $publicIp;
            }
            
            
            // 플랫폼 상세 감지
            $isSynology = !$isWindows && (file_exists('/etc/synoinfo.conf') || file_exists('/etc.defaults/synoinfo.conf') || is_dir('/volume1'));
            $isQNAP = !$isWindows && (file_exists('/etc/config/qpkg.conf') || is_dir('/share/CACHEDEV1_DATA'));
            $serverSw = $_SERVER['SERVER_SOFTWARE'] ?? '';
            $isApache = stripos($serverSw, 'apache') !== false;
            $isIIS = stripos($serverSw, 'iis') !== false || stripos($serverSw, 'microsoft') !== false;
            $isNginx = stripos($serverSw, 'nginx') !== false;
            $webServer = 'Unknown';
            if ($isApache) $webServer = 'Apache';
            elseif ($isIIS) $webServer = 'IIS';
            elseif ($isNginx) $webServer = 'Nginx';
            elseif (stripos($serverSw, 'litespeed') !== false) $webServer = 'LiteSpeed';
            elseif (stripos($serverSw, 'caddy') !== false) $webServer = 'Caddy';
            elseif ($serverSw) $webServer = $serverSw;
            
            $platformName = $isWindows ? 'Windows' : PHP_OS;
            if ($isSynology) $platformName = 'Synology DSM';
            elseif ($isQNAP) $platformName = 'QNAP';
            if ($isDocker) $platformName .= ' (Docker)';
            
            // 7-Zip 설치 상태
            $sevenZipInfo = ['installed' => false, 'path' => '', 'version' => ''];
            $sevenZipSearchPaths = [
                'C:\\Program Files\\7-Zip\\7z.exe',
                'C:\\Program Files (x86)\\7-Zip\\7z.exe',
                '/usr/bin/7z', '/usr/local/bin/7z', '/usr/bin/7za', '/usr/bin/7zz', '/usr/local/bin/7zz', '/usr/bin/7zzs', '/usr/local/bin/7zzs', '/usr/bin/7zip', '/bin/7zz', '/bin/7z'
            ];
            foreach ($sevenZipSearchPaths as $_7zp) {
                if (file_exists($_7zp)) {
                    $sevenZipInfo['installed'] = true;
                    $sevenZipInfo['path'] = $_7zp;
                    // 버전 확인
                    $verOutput = @shell_exec(escapeshellarg($_7zp) . ' 2>&1');
                    if ($verOutput && preg_match('/7-Zip\s+([\d.]+)/', $verOutput, $m)) {
                        $sevenZipInfo['version'] = $m[1];
                    }
                    break;
                }
            }

            // UnRAR 설치 상태 (rar 전용 — 7-Zip이 못 읽는 rar 처리 보완)
            $unrarInfo = ['installed' => false, 'path' => '', 'version' => ''];
            $unrarSearchPaths = [
                'C:\\Program Files\\WinRAR\\UnRAR.exe',
                'C:\\Program Files (x86)\\WinRAR\\UnRAR.exe',
                'C:\\Program Files\\WinRAR\\Rar.exe',
                'C:\\Program Files (x86)\\WinRAR\\Rar.exe',
                '/usr/bin/unrar', '/usr/local/bin/unrar', '/usr/bin/unrar-nonfree'
            ];
            foreach ($unrarSearchPaths as $_urp) {
                if (file_exists($_urp)) {
                    $unrarInfo['installed'] = true;
                    $unrarInfo['path'] = $_urp;
                    // 버전 확인
                    $urVer = @shell_exec(escapeshellarg($_urp) . ' 2>&1');
                    if ($urVer && preg_match('/UNRAR\s+([\d.]+)/i', $urVer, $um)) {
                        $unrarInfo['version'] = $um[1];
                    }
                    break;
                }
            }
            
            $result = [
                'success' => true,
                'platform' => $platformName,
                'web_server' => $webServer,
                'is_docker' => $isDocker,
                'is_synology' => $isSynology,
                'is_qnap' => $isQNAP,
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
                'server_software' => $serverSw ?: 'Unknown',
                'upload_max' => ini_get('upload_max_filesize'),
                'post_max' => ini_get('post_max_size'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'total_users' => $totalUsers,
                'active_sessions' => $activeSessions,
                'total_storages' => $totalStorages,
                'total_shares' => $totalShares,
                'extensions' => $extensions,
                'folders' => $folders,
                'disk_free' => $diskFree,
                'disk_total' => $diskTotal,
                'index_stats' => $indexStats,
                'session_info' => $sessionInfo,
                'opcache_info' => $opcacheInfo,
                'apcu_info' => $apcuInfo,
                'security_checks' => $securityChecks,
                'php_info' => $phpInfo,
                'server_resources' => $serverResources,
                'seven_zip' => $sevenZipInfo,
                'unrar' => $unrarInfo
            ];
            break;
        
        case 'server_stats':
            // 실시간 모니터용 - 가벼운 데이터만 반환
            $auth->requireAdmin();
            
            session_write_close(); // 세션 락 해제
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $stats = [
                'time' => date('Y-m-d H:i:s'),
                'cpu' => 0,
                'memory' => ['used' => 0, 'total' => 0, 'percent' => 0],
                'network' => ['rx' => 0, 'tx' => 0],
                'disk' => ['read' => 0, 'write' => 0]
            ];
            
            // CPU 사용률
            if ($isWindows) {
                $cpuUsage = @shell_exec('wmic cpu get loadpercentage 2>nul');
                if ($cpuUsage && preg_match('/(\d+)/', $cpuUsage, $m)) {
                    $stats['cpu'] = (int)$m[1];
                }
            } else {
                $load = @sys_getloadavg();
                $cores = 1;
                if (file_exists('/proc/cpuinfo')) {
                    $cpuinfo = @file_get_contents('/proc/cpuinfo');
                    $cores = max(1, substr_count($cpuinfo, 'processor'));
                }
                if ($load) {
                    $stats['cpu'] = min(100, round($load[0] / $cores * 100));
                }
            }
            
            // 메모리
            if ($isWindows) {
                $memInfo = @shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /value 2>nul');
                if ($memInfo) {
                    $total = 0; $free = 0;
                    if (preg_match('/TotalVisibleMemorySize=(\d+)/i', $memInfo, $m)) $total = (int)$m[1] * 1024;
                    if (preg_match('/FreePhysicalMemory=(\d+)/i', $memInfo, $m)) $free = (int)$m[1] * 1024;
                    $stats['memory'] = [
                        'total' => $total,
                        'used' => $total - $free,
                        'percent' => $total > 0 ? round(($total - $free) / $total * 100, 1) : 0
                    ];
                }
            } else {
                if (file_exists('/proc/meminfo')) {
                    $meminfo = @file_get_contents('/proc/meminfo');
                    $total = 0; $free = 0;
                    if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $m)) $total = (int)$m[1] * 1024;
                    if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $m)) $free = (int)$m[1] * 1024;
                    elseif (preg_match('/MemFree:\s+(\d+)\s+kB/i', $meminfo, $m)) $free = (int)$m[1] * 1024;
                    $stats['memory'] = [
                        'total' => $total,
                        'used' => $total - $free,
                        'percent' => $total > 0 ? round(($total - $free) / $total * 100, 1) : 0
                    ];
                }
            }
            
            // 네트워크 트래픽
            if ($isWindows) {
                // Windows wmic 사용 (admin.php ajax_resources 방식)
                $trafficInfo = @shell_exec('wmic path Win32_PerfRawData_Tcpip_NetworkInterface get Name,BytesReceivedPersec,BytesSentPersec /format:csv 2>nul');
                if ($trafficInfo) {
                    $lines = array_filter(explode("\n", trim($trafficInfo)));
                    if (count($lines) > 1) {
                        array_shift($lines);
                        $totalRx = 0; $totalTx = 0;
                        $interfaces = [];
                        foreach ($lines as $line) {
                            $parts = str_getcsv($line);
                            // CSV: Node, BytesReceivedPersec, BytesSentPersec, Name
                            if (count($parts) >= 4 && !empty(trim($parts[3]))) {
                                $rx = (int)$parts[1];
                                $tx = (int)$parts[2];
                                $totalRx += $rx;
                                $totalTx += $tx;
                                $interfaces[] = ['name' => trim($parts[3]), 'rx' => $rx, 'tx' => $tx];
                            }
                        }
                        $stats['network'] = ['rx' => $totalRx, 'tx' => $totalTx, 'interfaces' => $interfaces];
                    }
                }
            } else {
                if (file_exists('/proc/net/dev')) {
                    $netDev = @file_get_contents('/proc/net/dev');
                    $totalRx = 0; $totalTx = 0;
                    $interfaces = [];
                    foreach (explode("\n", $netDev) as $line) {
                        if (preg_match('/^\s*(\w+):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                            if ($m[1] !== 'lo') {
                                $rx = (int)$m[2];
                                $tx = (int)$m[3];
                                $totalRx += $rx;
                                $totalTx += $tx;
                                $interfaces[] = ['name' => $m[1], 'rx' => $rx, 'tx' => $tx];
                            }
                        }
                    }
                    $stats['network'] = ['rx' => $totalRx, 'tx' => $totalTx, 'interfaces' => $interfaces];
                }
            }
            
            // 디스크 I/O
            if ($isWindows) {
                // Windows wmic 사용
                $diskIO = @shell_exec('wmic path Win32_PerfRawData_PerfDisk_PhysicalDisk where "Name=\'_Total\'" get DiskReadBytesPersec,DiskWriteBytesPersec /format:csv 2>nul');
                if ($diskIO) {
                    $lines = array_filter(explode("\n", trim($diskIO)));
                    if (count($lines) > 1) {
                        $parts = str_getcsv(end($lines));
                        if (count($parts) >= 3) {
                            $stats['disk'] = ['read' => (int)$parts[1], 'write' => (int)$parts[2]];
                        }
                    }
                }
            } else {
                if (file_exists('/proc/diskstats')) {
                    $diskstats = @file_get_contents('/proc/diskstats');
                    $totalRead = 0; $totalWrite = 0;
                    foreach (explode("\n", $diskstats) as $line) {
                        // sda, nvme0n1 등 주요 디스크만 (파티션 제외)
                        if (preg_match('/^\s*\d+\s+\d+\s+(sd[a-z]|nvme\d+n\d+|vd[a-z])\s+\d+\s+\d+\s+(\d+)\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                            $totalRead += (int)$m[2] * 512;
                            $totalWrite += (int)$m[3] * 512;
                        }
                    }
                    $stats['disk'] = ['read' => $totalRead, 'write' => $totalWrite];
                }
            }
            
            $result = ['success' => true, 'stats' => $stats];
            break;
        
        case 'settings':
            $auth->requireLogin();  // 모든 로그인 사용자가 설정 읽기 가능
            $settings = $db->load('settings');
            // 기본값 설정
            if (empty($settings)) {
                $settings = ['home_share_enabled' => true];
            }
            // SMTP 비밀번호는 클라이언트에 전송하지 않음
            if (isset($settings['smtp'])) {
                $settings['smtp']['has_password'] = !empty($settings['smtp']['pass']);
                unset($settings['smtp']['pass']);
            }
            // OnlyOffice 시크릿 키는 마스킹 (설정 여부만 표시)
            if (isset($settings['onlyoffice'])) {
                $settings['onlyoffice']['has_secret'] = !empty($settings['onlyoffice']['secret']);
                unset($settings['onlyoffice']['secret']);
            }
            $result = ['success' => true, 'settings' => $settings];
            break;
        
        case 'settings_update':
            $auth->requireRealAdmin();  // 실제 관리자만 시스템 설정 변경 가능
            $settings = $db->load('settings');
            if (empty($settings)) {
                $settings = [];
            }
            // 설정 업데이트
            if (isset($input['home_share_enabled'])) {
                $settings['home_share_enabled'] = (bool)$input['home_share_enabled'];
            }
            if (isset($input['signup_enabled'])) {
                $settings['signup_enabled'] = (bool)$input['signup_enabled'];
            }
            if (isset($input['auto_approve'])) {
                $settings['auto_approve'] = (bool)$input['auto_approve'];
            }
            if (isset($input['external_url'])) {
                $settings['external_url'] = trim($input['external_url']);
            }
            if (isset($input['auto_index'])) {
                $settings['auto_index'] = (bool)$input['auto_index'];
            }
            if (isset($input['storage_recalc_interval_minutes'])) {
                $val = (int)$input['storage_recalc_interval_minutes'];
                // 범위: 1분 ~ 1440분(24시간). 범위 밖이면 기본값 60
                if ($val < 1 || $val > 1440) $val = 60;
                $settings['storage_recalc_interval_minutes'] = $val;
            }
            if (isset($input['index_sync_interval_minutes'])) {
                $val = (int)$input['index_sync_interval_minutes'];
                if ($val < 1 || $val > 1440) $val = 1440;
                $settings['index_sync_interval_minutes'] = $val;
            }
            if (isset($input['index_rebuild_notify_days'])) {
                $settings['index_rebuild_notify_days'] = max(0, (int)$input['index_rebuild_notify_days']);
            }
            if (isset($input['index_rebuild_notify_dismissed'])) {
                $settings['index_rebuild_notify_dismissed'] = $input['index_rebuild_notify_dismissed'];
            }
            if (isset($input['password_reset_enabled'])) {
                $settings['password_reset_enabled'] = (bool)$input['password_reset_enabled'];
            }
            if (isset($input['session_timeout'])) {
                $settings['session_timeout'] = max(0, (int)$input['session_timeout']);
            }
            if (isset($input['max_concurrent_jobs'])) {
                $settings['max_concurrent_jobs'] = max(0, (int)$input['max_concurrent_jobs']);
            }
            if (isset($input['default_quota'])) {
                $settings['default_quota'] = max(0, (int)$input['default_quota']);
            }
            if (isset($input['auto_purge_deleted'])) {
                $settings['auto_purge_deleted'] = (bool)$input['auto_purge_deleted'];
            }
            if (isset($input['auto_purge_days'])) {
                $settings['auto_purge_days'] = max(1, (int)$input['auto_purge_days']);
            }
            
            // SMTP 설정 저장
            if (isset($input['smtp'])) {
                $smtp = $input['smtp'];
                $smtpSettings = [
                    'enabled' => (bool)($smtp['enabled'] ?? false),
                    'host' => trim($smtp['host'] ?? ''),
                    'port' => (int)($smtp['port'] ?? 587),
                    'secure' => $smtp['secure'] ?? 'tls',
                    'user' => trim($smtp['user'] ?? ''),
                    'from' => trim($smtp['from'] ?? ''),
                    'from_name' => trim($smtp['from_name'] ?? '')
                ];
                
                // 비밀번호가 전달된 경우에만 업데이트
                if (!empty($smtp['pass'])) {
                    $smtpSettings['pass'] = $smtp['pass'];
                    $smtpSettings['has_password'] = true;
                } elseif (isset($settings['smtp']['pass'])) {
                    // 기존 비밀번호 유지
                    $smtpSettings['pass'] = $settings['smtp']['pass'];
                    $smtpSettings['has_password'] = true;
                } else {
                    $smtpSettings['has_password'] = false;
                }
                
                $settings['smtp'] = $smtpSettings;
            }
            
            // OnlyOffice 설정 저장
            if (isset($input['onlyoffice'])) {
                $onlyoffice = $input['onlyoffice'];
                $onlyofficeSettings = [
                    'enabled' => (bool)($onlyoffice['enabled'] ?? false),
                    'server' => trim($onlyoffice['server'] ?? ''),
                    'callback_url' => trim($onlyoffice['callback_url'] ?? '')
                ];
                
                // 시크릿 키가 전달된 경우에만 업데이트
                if (!empty($onlyoffice['secret'])) {
                    $onlyofficeSettings['secret'] = $onlyoffice['secret'];
                    $onlyofficeSettings['has_secret'] = true;
                } elseif (isset($settings['onlyoffice']['secret'])) {
                    // 기존 시크릿 유지
                    $onlyofficeSettings['secret'] = $settings['onlyoffice']['secret'];
                    $onlyofficeSettings['has_secret'] = true;
                } else {
                    $onlyofficeSettings['has_secret'] = false;
                }
                
                // 서버 버전 정보(version/pdf_editable) 보존:
                // 서버 URL이 그대로면 이전에 감지한 버전을 유지하고, 바뀌었으면 초기화(재확인 유도)
                $prevServer = trim($settings['onlyoffice']['server'] ?? '');
                if ($prevServer !== '' && $prevServer === $onlyofficeSettings['server']) {
                    if (isset($settings['onlyoffice']['version'])) {
                        $onlyofficeSettings['version'] = $settings['onlyoffice']['version'];
                    }
                    if (isset($settings['onlyoffice']['pdf_editable'])) {
                        $onlyofficeSettings['pdf_editable'] = $settings['onlyoffice']['pdf_editable'];
                    }
                }
                
                $settings['onlyoffice'] = $onlyofficeSettings;
            }
            
            $db->save('settings', $settings);
            $result = ['success' => true];
            break;
        
        // ===== SMTP 테스트 =====
        case 'smtp_test':
            $auth->requireRealAdmin();
            
            $smtp = $input['smtp'] ?? [];
            $testEmail = $input['test_email'] ?? '';
            
            if (empty($testEmail)) {
                $result = ['success' => false, 'error' => __('api_err_smtp_test_email', '테스트 이메일 주소가 필요합니다.')];
                break;
            }
            
            // 비밀번호가 없으면 저장된 비밀번호 사용
            if (empty($smtp['pass'])) {
                $settings = $db->load('settings');
                $savedSmtp = $settings['smtp'] ?? [];
                if (!empty($savedSmtp['pass'])) {
                    $smtp['pass'] = $savedSmtp['pass'];
                } else {
                    $result = ['success' => false, 'error' => __('smtp_no_password')];
                    break;
                }
            }
            
            // 테스트 이메일 발송
            $siteName = defined('SITE_NAME') ? SITE_NAME : 'WebHard';
            $subject = __f('smtp_test_subject', ['siteName' => $siteName]);
            $message = __('smtp_test_body') . "\n\n";
            $message .= __('smtp_test_server') . ($smtp['host'] ?? '') . "\n";
            $message .= __('smtp_test_port') . ($smtp['port'] ?? '') . "\n";
            $message .= __('smtp_test_secure') . ($smtp['secure'] ?? '') . "\n";
            $message .= __('smtp_test_from') . ($smtp['from'] ?? '') . "\n";
            $message .= "\n" . __('smtp_test_time') . date('Y-m-d H:i:s');
            
            $sent = sendSmtpEmail($smtp, $testEmail, $subject, $message);
            
            if ($sent === true) {
                $result = ['success' => true];
            } else {
                $result = ['success' => false, 'error' => $sent];
            }
            break;
        
        // ===== OnlyOffice 연결 테스트 =====
        case 'onlyoffice_test':
            $auth->requireRealAdmin();
            
            $serverUrl = trim($input['server_url'] ?? '');
            
            if (empty($serverUrl)) {
                $result = ['success' => false, 'error' => __('api_err_onlyoffice_url', 'Document Server URL이 필요합니다.')];
                break;
            }
            
            // URL 형식 검증
            if (!filter_var($serverUrl, FILTER_VALIDATE_URL)) {
                $result = ['success' => false, 'error' => __('api_err_invalid_url', '잘못된 URL 형식입니다.')];
                break;
            }
            
            // 끝의 슬래시 제거
            $serverUrl = rtrim($serverUrl, '/');
            
            // 여러 엔드포인트 테스트
            $testUrls = [
                $serverUrl . '/healthcheck',
                $serverUrl . '/web-apps/apps/api/documents/api.js',
                $serverUrl . '/welcome/'
            ];
            
            $connected = false;
            $errorMsg = '';
            $testedUrl = '';
            
            // cURL 사용
            if (function_exists('curl_init')) {
                foreach ($testUrls as $testUrl) {
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $testUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_USERAGENT => 'WebHard OnlyOffice Test'
                    ]);
                    
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    
                    $testedUrl = $testUrl;
                    
                    if ($httpCode >= 200 && $httpCode < 400) {
                        $connected = true;
                        break;
                    }
                    
                    if ($curlError) {
                        $errorMsg = $curlError;
                    } else {
                        $errorMsg = "HTTP {$httpCode}";
                    }
                }
            } else {
                // cURL 없으면 file_get_contents 사용
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 10,
                        'ignore_errors' => true
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false
                    ]
                ]);
                
                foreach ($testUrls as $testUrl) {
                    $testedUrl = $testUrl;
                    $response = @file_get_contents($testUrl, false, $context);
                    
                    if ($response !== false) {
                        $connected = true;
                        break;
                    }
                    
                    $errorMsg = __('connect_fail');
                }
            }
            
            if ($connected) {
                $result = [
                    'success' => true,
                    'message' => __('api_onlyoffice_connected', 'OnlyOffice Document Server에 연결되었습니다.'),
                    'tested_url' => $testedUrl
                ];
            } else {
                $result = [
                    'success' => false,
                    'error' => __f('connect_fail_detail', ['msg' => $errorMsg]),
                    'tested_url' => $testedUrl
                ];
            }
            break;
        
        case 'onlyoffice_version':
            // OnlyOffice Document Server 버전 조회 (Command Service). 관리자 전용.
            // 조회한 버전을 settings에 저장하고 PDF 편집 가능 여부(8.1+)를 함께 반환.
            $auth->requireRealAdmin();
            
            // PDF 편집 최소 버전 (major.minor)
            $ooPdfMinMajor = 8;
            $ooPdfMinMinor = 1;
            
            $settings = $db->load('settings');
            $ooSaved = $settings['onlyoffice'] ?? [];
            
            // 서버 URL: 입력값 우선, 없으면 저장된 값
            $serverUrl = trim($input['server_url'] ?? '');
            if ($serverUrl === '') {
                $serverUrl = trim($ooSaved['server'] ?? '');
            }
            if ($serverUrl === '' || !filter_var($serverUrl, FILTER_VALIDATE_URL)) {
                $result = ['success' => false, 'error' => __('api_err_onlyoffice_url', 'Document Server URL이 필요합니다.')];
                break;
            }
            $serverUrl = rtrim($serverUrl, '/');
            $secret = $ooSaved['secret'] ?? '';
            
            $commandUrl = $serverUrl . '/coauthoring/CommandService.ashx';
            
            // 요청 payload
            $payload = ['c' => 'version'];
            $bearer = '';
            if (!empty($secret)) {
                // HS256 JWT 서명 (payload 자체를 서명 → token 필드 + Authorization 헤더 둘 다 전송)
                $b64u = function($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); };
                $jwtHeader = $b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
                $jwtPayload = $b64u(json_encode($payload));
                $jwtSig = $b64u(hash_hmac('sha256', "$jwtHeader.$jwtPayload", $secret, true));
                $bearer = "$jwtHeader.$jwtPayload.$jwtSig";
                $payload['token'] = $bearer;
            }
            $bodyJson = json_encode($payload);
            
            $rawResp = false;
            $errMsg = '';
            $httpCode = 0;
            if (function_exists('curl_init')) {
                $ch = curl_init();
                $headers = ['Content-Type: application/json'];
                if ($bearer !== '') { $headers[] = 'Authorization: Bearer ' . $bearer; }
                curl_setopt_array($ch, [
                    CURLOPT_URL => $commandUrl,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $bodyJson,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_USERAGENT => 'FileStation OnlyOffice VersionCheck'
                ]);
                $rawResp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $errMsg = curl_error($ch);
                curl_close($ch);
            } else {
                $hdr = "Content-Type: application/json\r\n";
                if ($bearer !== '') { $hdr .= 'Authorization: Bearer ' . $bearer . "\r\n"; }
                $context = stream_context_create([
                    'http' => ['method' => 'POST', 'header' => $hdr, 'content' => $bodyJson, 'timeout' => 10, 'ignore_errors' => true],
                    'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
                ]);
                $rawResp = @file_get_contents($commandUrl, false, $context);
                if ($rawResp === false) { $errMsg = __('connect_fail'); }
            }
            
            if ($rawResp === false || $rawResp === '') {
                $result = ['success' => false, 'error' => __f('connect_fail_detail', ['msg' => $errMsg ?: ('HTTP ' . $httpCode)])];
                break;
            }
            
            $respData = json_decode($rawResp, true);
            // Command Service 응답: { "error": 0, "version": "9.3.1" }
            if (!is_array($respData) || !isset($respData['version']) || (isset($respData['error']) && (int)$respData['error'] !== 0)) {
                $ooErrCode = is_array($respData) && isset($respData['error']) ? (int)$respData['error'] : -1;
                // error 6 = Invalid token (JWT 시크릿 불일치)
                $hint = ($ooErrCode === 6)
                    ? __('oo_ver_err_token', 'JWT 시크릿 키가 서버와 일치하지 않습니다.')
                    : (__('oo_ver_err_code', 'OnlyOffice 버전 조회에 실패했습니다.') . ' (code: ' . $ooErrCode . ')');
                $result = ['success' => false, 'error' => $hint];
                break;
            }
            
            $verStr = (string)$respData['version'];
            $verParts = explode('.', $verStr);
            $verMajor = (int)($verParts[0] ?? 0);
            $verMinor = (int)($verParts[1] ?? 0);
            $pdfEditable = ($verMajor > $ooPdfMinMajor) || ($verMajor === $ooPdfMinMajor && $verMinor >= $ooPdfMinMinor);
            
            // settings에 저장 (다른 onlyoffice 필드 유지)
            if (!isset($settings['onlyoffice']) || !is_array($settings['onlyoffice'])) {
                $settings['onlyoffice'] = [];
            }
            $settings['onlyoffice']['version'] = $verStr;
            $settings['onlyoffice']['pdf_editable'] = $pdfEditable;
            $db->save('settings', $settings);
            
            $result = [
                'success' => true,
                'version' => $verStr,
                'pdf_editable' => $pdfEditable,
                'min_version' => $ooPdfMinMajor . '.' . $ooPdfMinMinor
            ];
            break;
        
        // ===== 사이트 설정 (로고, 배경 등) =====
        case 'site_settings_get':
            $settings = loadSiteSettings();
            
            // 서버가 실제 지원하는 썸네일 확장자 목록 생성
            $thumbFormats = [];
            if (extension_loaded('gd')) {
                array_push($thumbFormats, 'jpg','jpeg','png','gif','webp','bmp');
            }
            // ffmpeg 확인
            $ffmpegPath = trim($settings['ffmpeg_path'] ?? '');
            $ffmpegFound = false;
            $ffmpegPaths = $ffmpegPath ? [$ffmpegPath] : [];
            if (PHP_OS_FAMILY === 'Windows') {
                array_push($ffmpegPaths, 'ffmpeg', 'C:\\ffmpeg\\bin\\ffmpeg.exe', 'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe');
            } else {
                array_push($ffmpegPaths, 'ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/local/ffmpeg/bin/ffmpeg', '/volume1/@appstore/ffmpeg/bin/ffmpeg', '/volume1/@appstore/MediaServer/bin/ffmpeg', '/var/packages/ffmpeg/target/bin/ffmpeg', '/var/packages/MediaServer/target/bin/ffmpeg');
            }
            foreach ($ffmpegPaths as $fp) {
                $out = [];
                @exec(escapeshellarg($fp) . ' -version 2>&1', $out, $ret);
                if ($ret === 0) { $ffmpegFound = true; break; }
            }
            if ($ffmpegFound) {
                array_push($thumbFormats, 'mp4','webm','mkv','avi','mov','wmv','flv','ts','m2ts','mts','mpg','mpeg','m4v','3gp');
            }
            // MP3 썸네일 지원 (ID3v2 APIC 프레임에서 추출, GD/Imagick로 리사이즈)
            // PHP 내장 기능만 사용하므로 별도 의존성 없음 (GD 없어도 원본 이미지 반환 가능)
            $thumbFormats[] = 'mp3';
            if (extension_loaded('imagick') && class_exists('Imagick')) {
                // Imagick PDF 지원 여부 캐시 체크
                $pdfCapFile = __DIR__ . '/data/thumbcache/.pdf_capable';
                $pdfCapFail = __DIR__ . '/data/thumbcache/.pdf_incapable';
                
                if (is_file($pdfCapFile) && (time() - filemtime($pdfCapFile)) < 86400) {
                    $thumbFormats[] = 'pdf';
                } elseif (is_file($pdfCapFail) && (time() - filemtime($pdfCapFail)) < 86400) {
                    // Imagick 실패 캐시 — 아래 CLI 도구 체크로 이동
                } else {
                    $canPdf = false;
                    try {
                        $testIm = new \Imagick();
                        $testIm->setResolution(72, 72);
                        $minPdf = "%PDF-1.0\n1 0 obj<</Pages 2 0 R>>endobj 2 0 obj<</Kids[3 0 R]/Count 1>>endobj 3 0 obj<</MediaBox[0 0 1 1]>>endobj\ntrailer<</Root 1 0 R>>";
                        $testIm->readImageBlob($minPdf);
                        $testIm->setImageFormat('jpeg');
                        $testIm->getImageBlob();
                        $canPdf = true;
                        $testIm->clear();
                        $testIm->destroy();
                    } catch (\Throwable $e) {
                        $canPdf = false;
                    }
                    
                    $thumbCacheDir = __DIR__ . '/data/thumbcache';
                    if (!is_dir($thumbCacheDir)) @mkdir($thumbCacheDir, 0755, true);
                    if ($canPdf) {
                        @file_put_contents($pdfCapFile, '1');
                        @unlink($pdfCapFail);
                        $thumbFormats[] = 'pdf';
                    } else {
                        @file_put_contents($pdfCapFail, '0');
                        @unlink($pdfCapFile);
                    }
                }
            }
            
            // Imagick으로 PDF 안 되면 CLI 도구 체크 (pdftoppm, mutool, gs)
            if (!in_array('pdf', $thumbFormats)) {
                $pdfTools = [];
                if (PHP_OS_FAMILY === 'Windows') {
                    $pdfTools = ['pdftoppm', 'mutool', 'gswin64c', 'gswin32c'];
                } else {
                    $pdfTools = ['pdftoppm', 'mutool', 'gs'];
                }
                foreach ($pdfTools as $tool) {
                    $out = [];
                    @exec(escapeshellarg($tool) . ' --version 2>&1', $out, $ret);
                    // pdftoppm은 --version이 없으면 -v 시도
                    if ($ret !== 0 && $tool === 'pdftoppm') {
                        @exec(escapeshellarg($tool) . ' -v 2>&1', $out, $ret);
                    }
                    if ($ret === 0) {
                        $thumbFormats[] = 'pdf';
                        break;
                    }
                }
            }
            
            $settings['thumb_formats'] = $thumbFormats;
            
            // 7-Zip 설치 여부
            $sevenZipInstalled = false;
            $szPaths2 = ['C:\\Program Files\\7-Zip\\7z.exe', 'C:\\Program Files (x86)\\7-Zip\\7z.exe', '/usr/bin/7z', '/usr/local/bin/7z', '/usr/bin/7za', '/usr/bin/7zz', '/usr/local/bin/7zz', '/usr/bin/7zzs', '/usr/local/bin/7zzs', '/usr/bin/7zip', '/bin/7zz', '/bin/7z'];
            foreach ($szPaths2 as $szp) { if (file_exists($szp)) { $sevenZipInstalled = true; break; } }
            $settings['seven_zip_installed'] = $sevenZipInstalled;
            
            // 비관리자에게는 서버 경로/도구 정보 숨기기
            $isSettingsAdmin = false;
            $isLoggedIn = false;
            try { $isSettingsAdmin = $auth->isAdmin() || $auth->isAdminOrSubAdmin(); } catch (\Throwable $e) {}
            try { $isLoggedIn = $auth->isLoggedIn(); } catch (\Throwable $e) {}
            
            if (!$isLoggedIn) {
                // 비로그인: 테마/로고/사이트명만 반환 (서버 기능 정보 숨김)
                $publicKeys = ['site_name', 'copyright', 'theme', 'login_bg', 'login_bg_type', 'bg_filter_preset', 'bg_fit', 'favicon', 'custom_logo', 'login_notice'];
                $filtered = [];
                foreach ($publicKeys as $k) {
                    if (isset($settings[$k])) $filtered[$k] = $settings[$k];
                }
                $result = ['success' => true, 'settings' => $filtered];
                break;
            }
            
            if (!$isSettingsAdmin) {
                unset($settings['ffmpeg_path'], $settings['ffprobe_path'], $settings['pdf_tool_path']);
                unset($settings['onlyoffice']);
            } else {
                // TOTP 기본 키 경고 (관리자만)
                if (defined('TOTP_ENCRYPTION_KEY') && TOTP_ENCRYPTION_KEY === 'change-this-to-your-secret-key-32chars') {
                    $settings['_totp_default_key_warning'] = true;
                }
            }
            
            $result = ['success' => true, 'settings' => $settings];
            break;
        
        case 'site_settings_update':
            $auth->requireRealAdmin();
            $siteSettings = loadSiteSettings();
            
            // 사이트 이름 업데이트
            if (isset($input['site_name'])) {
                $siteSettings['site_name'] = trim($input['site_name']);
            }
            
            // 카피라이트 업데이트
            if (array_key_exists('copyright', $input)) {
                $siteSettings['copyright'] = trim($input['copyright']);
            }
            
            // 배경 필터 프리셋 업데이트
            $allowedPresets = ['none','dark','blur','cinema','purple','blue','warm','bw','vintage','cool','sunset','neon'];
            if (isset($input['bg_filter_preset']) && in_array($input['bg_filter_preset'], $allowedPresets)) {
                $siteSettings['bg_filter_preset'] = $input['bg_filter_preset'];
            }
            
            // 배경 배치 방식 업데이트
            $allowedFits = ['cover','contain','fill','tile','center','span'];
            if (isset($input['bg_fit']) && in_array($input['bg_fit'], $allowedFits)) {
                $siteSettings['bg_fit'] = $input['bg_fit'];
            }
            
            // 썸네일 설정 업데이트
            if (isset($input['thumbnail_enabled'])) {
                $siteSettings['thumbnail_enabled'] = (bool)$input['thumbnail_enabled'];
            }
            if (isset($input['thumbnail_size'])) {
                $siteSettings['thumbnail_size'] = max(50, min(400, (int)$input['thumbnail_size']));
            }
            if (array_key_exists('ffmpeg_path', $input)) {
                $siteSettings['ffmpeg_path'] = trim($input['ffmpeg_path']);
            }
            if (array_key_exists('ffprobe_path', $input)) {
                $siteSettings['ffprobe_path'] = trim($input['ffprobe_path']);
            }
            if (array_key_exists('pdf_tool', $input)) {
                $siteSettings['pdf_tool'] = trim($input['pdf_tool']);
            }
            if (array_key_exists('pdf_tool_path', $input)) {
                $siteSettings['pdf_tool_path'] = trim($input['pdf_tool_path']);
            }
            
            saveSiteSettings($siteSettings);
            $result = ['success' => true];
            break;
        
        case 'clear_thumbcache':
            $auth->requireRealAdmin();
            $cacheDir = __DIR__ . '/data/thumbcache';
            $count = 0;
            $freedBytes = 0;
            if (is_dir($cacheDir)) {
                // 루트 디렉토리 파일 삭제 (일반 이미지/비디오 썸네일, MP3 nocover 마커)
                // glob('/*')는 .으로 시작하는 숨김 파일을 못 잡으므로 scandir 사용
                $files = scandir($cacheDir);
                foreach ($files as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $filePath = $cacheDir . DIRECTORY_SEPARATOR . $f;
                    if (is_file($filePath)) {
                        $freedBytes += filesize($filePath);
                        @unlink($filePath);
                        $count++;
                    }
                }
                // MP3 ID3 커버 캐시 (audio_cover API용)
                // 주의: comments/ 등 다른 하위 디렉토리는 건드리지 않음 (게시판 댓글 썸네일)
                $audioCacheDir = $cacheDir . DIRECTORY_SEPARATOR . 'audio';
                if (is_dir($audioCacheDir) && !is_link($audioCacheDir)) {
                    $audioFiles = @scandir($audioCacheDir);
                    if ($audioFiles !== false) {
                        foreach ($audioFiles as $f) {
                            if ($f === '.' || $f === '..') continue;
                            $filePath = $audioCacheDir . DIRECTORY_SEPARATOR . $f;
                            if (is_file($filePath) && !is_link($filePath)) {
                                $fsize = @filesize($filePath);
                                if ($fsize !== false) $freedBytes += $fsize;
                                @unlink($filePath);
                                $count++;
                            }
                        }
                    }
                }
            }
            $result = [
                'success' => true,
                'deleted' => $count,
                'freed' => $freedBytes
            ];
            break;
        
        case 'thumb_debug':
            $auth->requireRealAdmin();
            $debugPath = $_GET['path'] ?? ($input['path'] ?? '');
            $debugStorageId = (int)($_GET['storage_id'] ?? ($input['storage_id'] ?? 0));
            if (isset($_GET['enc']) && $_GET['enc'] === 'b64') {
                $debugPath = base64_decode($debugPath);
            }
            $debugInfo = ['path' => $debugPath, 'storage_id' => $debugStorageId];
            
            // 스토리지 경로 확인
            $basePath = $storage->getRealPath($debugStorageId);
            $debugInfo['base_path'] = $basePath;
            $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($debugPath, '/\\'));
            $debugInfo['full_path'] = $fullPath;
            $debugInfo['file_exists'] = is_file($fullPath);
            $debugInfo['is_readable'] = is_readable($fullPath);
            
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $debugInfo['extension'] = $ext;
            
            // 캐시 확인
            $cacheDir = __DIR__ . '/data/thumbcache';
            $mtime = @filemtime($fullPath);
            $cacheKey = md5($fullPath . '|' . $mtime . '|' . 200);
            $cachePath = $cacheDir . '/' . $cacheKey . '.jpg';
            $failMarker = $cachePath . '.fail';
            $debugInfo['cache_key'] = $cacheKey;
            $debugInfo['cache_exists'] = is_file($cachePath);
            $debugInfo['fail_marker_exists'] = is_file($failMarker);
            if (is_file($failMarker)) {
                $debugInfo['fail_marker_content'] = file_get_contents($failMarker);
            }
            
            // ffmpeg 확인
            $siteSettings = loadSiteSettings();
            $ffmpegPath = $siteSettings['ffmpeg_path'] ?? '';
            $debugInfo['ffmpeg_configured'] = $ffmpegPath;
            if ($ffmpegPath) {
                $out = [];
                @exec(escapeshellarg($ffmpegPath) . ' -version 2>&1', $out, $ret);
                $debugInfo['ffmpeg_test_ret'] = $ret;
                $debugInfo['ffmpeg_test_output'] = $out[0] ?? '';
            }
            
            // 동영상이면 ffmpeg 테스트 실행
            $videoExts = ['mp4','webm','mkv','avi','mov','wmv','flv','ts','m2ts','mts','mpg','mpeg','m4v','3gp'];
            if (in_array($ext, $videoExts) && $ffmpegPath && is_file($fullPath)) {
                $tempTest = $cacheDir . '/debug_test.jpg';
                $escPath = (PHP_OS_FAMILY === 'Windows') ? '"' . str_replace('"', '""', $fullPath) . '"' : escapeshellarg($fullPath);
                $escTemp = (PHP_OS_FAMILY === 'Windows') ? '"' . str_replace('"', '""', $tempTest) . '"' : escapeshellarg($tempTest);
                $thumbSize = 200;
                $vf = "crop='min(iw,ih)':'min(iw,ih)',scale={$thumbSize}:{$thumbSize}:flags=fast_bilinear";
                $cmd = escapeshellarg($ffmpegPath) . ' -ss 00:00:03 -i ' . $escPath . " -vframes 1 -vf \"{$vf}\" -q:v 4 " . $escTemp . ' -y 2>&1';
                $output = [];
                exec($cmd, $output, $ret);
                $debugInfo['ffmpeg_cmd'] = $cmd;
                $debugInfo['ffmpeg_ret'] = $ret;
                $debugInfo['ffmpeg_output'] = implode("\n", array_slice($output, -5));
                $debugInfo['ffmpeg_result_exists'] = is_file($tempTest);
                @unlink($tempTest);
            }
            
            // clear_fail 파라미터가 있으면 fail marker 삭제
            if (isset($_GET['clear_fail']) || isset($input['clear_fail'])) {
                if (is_file($failMarker)) {
                    @unlink($failMarker);
                    $debugInfo['fail_marker_cleared'] = true;
                    $debugInfo['fail_marker_exists'] = false;
                }
            }
            
            $result = ['success' => true, 'debug' => $debugInfo];
            break;
        
        case 'thumb_capabilities':
            $auth->requireRealAdmin();
            
            // GD 체크
            $gdLoaded = extension_loaded('gd');
            $gdInfo = $gdLoaded ? gd_info() : [];
            
            // ffmpeg 체크 - 설정된 경로 우선
            $thumbSettings = loadSiteSettings();
            $ffmpegAvailable = false;
            $ffmpegVersion = '';
            $ffmpegDetectedPath = '';
            
            $configuredPath = trim($thumbSettings['ffmpeg_path'] ?? '');
            $ffmpegPaths = [];
            if ($configuredPath) {
                $ffmpegPaths[] = $configuredPath;
            }
            if (PHP_OS_FAMILY === 'Windows') {
                array_push($ffmpegPaths, 'ffmpeg', 'C:\\ffmpeg\\bin\\ffmpeg.exe', 'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe');
            } else {
                array_push($ffmpegPaths, 'ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/local/ffmpeg/bin/ffmpeg', '/volume1/@appstore/ffmpeg/bin/ffmpeg', '/volume1/@appstore/MediaServer/bin/ffmpeg', '/var/packages/ffmpeg/target/bin/ffmpeg', '/var/packages/MediaServer/target/bin/ffmpeg');
            }
            foreach ($ffmpegPaths as $fp) {
                $out = [];
                @exec(escapeshellarg($fp) . ' -version 2>&1', $out, $ret);
                if ($ret === 0) {
                    $ffmpegAvailable = true;
                    $ffmpegDetectedPath = $fp;
                    $firstLine = $out[0] ?? '';
                    if (preg_match('/ffmpeg version (\S+)/', $firstLine, $m)) {
                        $ffmpegVersion = $m[1];
                    }
                    break;
                }
            }
            
            // Imagick 체크
            $imagickLoaded = extension_loaded('imagick');
            $imagickVersion = $imagickLoaded && class_exists('Imagick') ? \Imagick::getVersion()['versionString'] ?? '' : '';
            
            // ffprobe 체크
            $ffprobeAvailable = false;
            $ffprobeVersion = '';
            $ffprobeDetectedPath = '';
            $configuredProbePath = trim($thumbSettings['ffprobe_path'] ?? '');
            $ffprobePaths = [];
            if ($configuredProbePath) {
                $ffprobePaths[] = $configuredProbePath;
            }
            if (PHP_OS_FAMILY === 'Windows') {
                array_push($ffprobePaths, 'ffprobe', 'C:\\ffmpeg\\bin\\ffprobe.exe', 'C:\\Program Files\\ffmpeg\\bin\\ffprobe.exe');
            } else {
                array_push($ffprobePaths, 'ffprobe', '/usr/bin/ffprobe', '/usr/local/bin/ffprobe',
                    '/usr/local/ffmpeg/bin/ffprobe', '/volume1/@appstore/ffmpeg/bin/ffprobe',
                    '/volume1/@appstore/MediaServer/bin/ffprobe', '/var/packages/ffmpeg/target/bin/ffprobe',
                    '/var/packages/MediaServer/target/bin/ffprobe');
            }
            foreach ($ffprobePaths as $fp2) {
                $out2 = [];
                @exec(escapeshellarg($fp2) . ' -version 2>&1', $out2, $ret2);
                if ($ret2 === 0) {
                    $ffprobeAvailable = true;
                    $ffprobeDetectedPath = $fp2;
                    $firstLine2 = $out2[0] ?? '';
                    if (preg_match('/ffprobe version (\S+)/', $firstLine2, $m2)) {
                        $ffprobeVersion = $m2[1];
                    }
                    break;
                }
            }
            
            // 캐시 상태 (루트 *.jpg + MP3 커버용 audio/ 하위 - clear_thumbcache와 동일 범위)
            $cacheDir = __DIR__ . '/data/thumbcache';
            $cacheCount = 0;
            $cacheSize = 0;
            if (is_dir($cacheDir)) {
                // 루트의 일반 썸네일 (기존 동작)
                $files = glob($cacheDir . '/*.jpg');
                $cacheCount = count($files);
                foreach ($files as $f) {
                    $cacheSize += filesize($f);
                }
                // MP3 ID3 커버 캐시 (audio/ 하위)
                $audioCacheDir = $cacheDir . DIRECTORY_SEPARATOR . 'audio';
                if (is_dir($audioCacheDir) && !is_link($audioCacheDir)) {
                    $audioFiles = @scandir($audioCacheDir);
                    if ($audioFiles !== false) {
                        foreach ($audioFiles as $f) {
                            if ($f === '.' || $f === '..') continue;
                            $filePath = $audioCacheDir . DIRECTORY_SEPARATOR . $f;
                            if (is_file($filePath) && !is_link($filePath)) {
                                $fsize = @filesize($filePath);
                                if ($fsize !== false) {
                                    $cacheSize += $fsize;
                                    $cacheCount++;
                                }
                            }
                        }
                    }
                }
            }
            
            $result = [
                'success' => true,
                'gd' => [
                    'available' => $gdLoaded,
                    'jpeg' => !empty($gdInfo['JPEG Support']),
                    'png' => !empty($gdInfo['PNG Support']),
                    'gif' => !empty($gdInfo['GIF Read Support']),
                    'webp' => !empty($gdInfo['WebP Support']),
                ],
                'ffmpeg' => [
                    'available' => $ffmpegAvailable,
                    'version' => $ffmpegVersion,
                    'path' => $ffmpegDetectedPath,
                    'configured_path' => $configuredPath,
                ],
                'ffprobe' => [
                    'available' => $ffprobeAvailable,
                    'version' => $ffprobeVersion,
                    'path' => $ffprobeDetectedPath,
                    'configured_path' => $configuredProbePath,
                ],
                'proc_open' => [
                    'available' => function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', @ini_get('disable_functions') ?: ''))),
                ],
                'imagick' => [
                    'available' => $imagickLoaded,
                    'version' => $imagickVersion,
                ],
                'pdf_tools' => (function() {
                    $tools = [];
                    // pdftoppm
                    $out = []; @exec('pdftoppm -v 2>&1', $out, $ret);
                    if ($ret === 0) $tools[] = 'pdftoppm';
                    // mutool
                    $out = []; @exec('mutool --version 2>&1', $out, $ret);
                    if ($ret === 0) $tools[] = 'mutool';
                    // gs
                    $gsName = PHP_OS_FAMILY === 'Windows' ? 'gswin64c' : 'gs';
                    $out = []; @exec(escapeshellarg($gsName) . ' --version 2>&1', $out, $ret);
                    if ($ret === 0) $tools[] = $gsName;
                    return $tools;
                })(),
                'cache' => [
                    'count' => $cacheCount,
                    'size' => $cacheSize,
                ]
            ];
            break;
        
        case 'test_ffmpeg':
            $auth->requireRealAdmin();
            $testPath = trim($input['path'] ?? '');
            if (!$testPath) {
                $result = ['success' => false, 'error' => __('api_err_path_required', '경로를 입력해주세요.')];
                break;
            }
            $out = [];
            @exec(escapeshellarg($testPath) . ' -version 2>&1', $out, $ret);
            if ($ret === 0) {
                $ver = '';
                $firstLine = $out[0] ?? '';
                if (preg_match('/ffmpeg version (\S+)/', $firstLine, $m)) {
                    $ver = $m[1];
                }
                $result = ['success' => true, 'version' => $ver, 'output' => $firstLine];
            } else {
                $result = ['success' => false, 'error' => __('api_err_exec_failed', '실행 실패: ') . ($out[0] ?? __('api_err_file_not_found', '파일을 찾을 수 없습니다.'))];
            }
            break;
        
        case 'test_ffprobe':
            $auth->requireRealAdmin();
            $testPath = trim($input['path'] ?? '');
            if (!$testPath) {
                $result = ['success' => false, 'error' => __('api_err_path_required', '경로를 입력해주세요.')];
                break;
            }
            $out = [];
            @exec(escapeshellarg($testPath) . ' -version 2>&1', $out, $ret);
            if ($ret === 0) {
                $ver = '';
                $firstLine = $out[0] ?? '';
                if (preg_match('/ffprobe version (\S+)/', $firstLine, $m)) {
                    $ver = $m[1];
                }
                $result = ['success' => true, 'version' => $ver, 'output' => $firstLine];
            } else {
                $result = ['success' => false, 'error' => __('api_err_exec_failed', '실행 실패: ') . ($out[0] ?? __('api_err_file_not_found', '파일을 찾을 수 없습니다.'))];
            }
            break;
        
        case 'test_pdftool':
            $auth->requireRealAdmin();
            $tool = trim($input['tool'] ?? '');
            $testPath = trim($input['path'] ?? '');
            
            if (!$tool && !$testPath) {
                $result = ['success' => false, 'error' => __('api_err_tool_or_path_required', '도구를 선택하거나 경로를 입력해주세요.')];
                break;
            }
            
            $execPath = $testPath ?: $tool;
            $out = [];
            
            // 도구별로 버전 확인 명령이 다름
            if (strpos($execPath, 'pdftoppm') !== false) {
                @exec(escapeshellarg($execPath) . ' -v 2>&1', $out, $ret);
            } elseif (strpos($execPath, 'mutool') !== false) {
                @exec(escapeshellarg($execPath) . ' --version 2>&1', $out, $ret);
            } else {
                // gs, gswin64c 등
                @exec(escapeshellarg($execPath) . ' --version 2>&1', $out, $ret);
            }
            
            if ($ret === 0) {
                $firstLine = trim($out[0] ?? '');
                $result = ['success' => true, 'version' => $firstLine, 'output' => implode("\n", array_slice($out, 0, 3))];
            } else {
                $result = ['success' => false, 'error' => __('api_err_exec_failed', '실행 실패: ') . trim($out[0] ?? __('api_err_file_not_found', '파일을 찾을 수 없습니다.'))];
            }
            break;
        
        case 'site_image_upload':
            $auth->requireRealAdmin();
            
            $type = $input['type'] ?? $_POST['type'] ?? '';  // 'logo' or 'bg'
            if (!in_array($type, ['logo', 'bg'])) {
                $result = ['success' => false, 'error' => __('api_err_invalid_image_type', '잘못된 이미지 타입')];
                break;
            }
            
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $result = ['success' => false, 'error' => __('api_err_file_upload_failed', '파일 업로드 실패')];
                break;
            }
            
            $file = $_FILES['image'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            if (!in_array($file['type'], $allowedTypes)) {
                $result = ['success' => false, 'error' => __('api_err_unsupported_image', '지원하지 않는 이미지 형식')];
                break;
            }
            
            // 이미지 저장 폴더
            $uploadDir = __DIR__ . '/data/site_images/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // 기존 이미지 삭제
            $siteSettings = loadSiteSettings();
            
            $oldImageKey = $type === 'logo' ? 'logo_image' : 'bg_image';
            if (!empty($siteSettings[$oldImageKey])) {
                $oldPath = __DIR__ . '/' . $siteSettings[$oldImageKey];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            
            // 새 파일명 생성
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newFilename = $type . '_' . time() . '.' . $ext;
            $newPath = $uploadDir . $newFilename;
            
            if (!move_uploaded_file($file['tmp_name'], $newPath)) {
                $result = ['success' => false, 'error' => __('api_err_file_save_failed_2', '파일 저장 실패')];
                break;
            }
            
            // 설정 업데이트
            $relativePath = 'data/site_images/' . $newFilename;
            $siteSettings[$oldImageKey] = $relativePath;
            saveSiteSettings($siteSettings);
            
            $result = ['success' => true, 'path' => $relativePath];
            break;
        
        case 'site_image_delete':
            $auth->requireRealAdmin();
            
            $type = $input['type'] ?? '';  // 'logo' or 'bg'
            if (!in_array($type, ['logo', 'bg'])) {
                $result = ['success' => false, 'error' => __('api_err_invalid_image_type', '잘못된 이미지 타입')];
                break;
            }
            
            $siteSettings = loadSiteSettings();
            
            $imageKey = $type === 'logo' ? 'logo_image' : 'bg_image';
            if (!empty($siteSettings[$imageKey])) {
                $imagePath = __DIR__ . '/' . $siteSettings[$imageKey];
                if (file_exists($imagePath)) {
                    @unlink($imagePath);
                }
                unset($siteSettings[$imageKey]);
                saveSiteSettings($siteSettings);
            }
            
            $result = ['success' => true];
            break;
        
        // ===== 스토리지 경로 설정 =====
        case 'storage_paths_get':
            $auth->requireRealAdmin();
            $pathsFile = __DIR__ . '/data/storage_paths.json';
            $paths = file_exists($pathsFile) ? json_decode(file_get_contents($pathsFile), true) : [];
            
            // 현재 적용된 경로 (상수값)
            $result = [
                'success' => true,
                'paths' => [
                    'user_files_root' => $paths['user_files_root'] ?? '',
                    'shared_files_root' => $paths['shared_files_root'] ?? '',
                    'trash_path' => $paths['trash_path'] ?? ''
                ],
                'current' => [
                    'user_files_root' => USER_FILES_ROOT,
                    'shared_files_root' => SHARED_FILES_ROOT,
                    'trash_path' => TRASH_PATH
                ],
                'defaults' => [
                    'user_files_root' => __DIR__ . '/users',
                    'shared_files_root' => __DIR__ . '/shared',
                    'trash_path' => __DIR__ . '/data/trash_files'
                ]
            ];
            break;
        
        case 'storage_paths_update':
            $auth->requireRealAdmin();
            
            $userPath = trim($input['user_files_root'] ?? '');
            $sharedPath = trim($input['shared_files_root'] ?? '');
            $trashPath = trim($input['trash_path'] ?? '');
            
            // 경로 유효성 검사 (비어있으면 기본값 사용)
            $errors = [];
            
            if (!empty($userPath)) {
                // 경로 정규화
                $userPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $userPath);
                $userPath = rtrim($userPath, DIRECTORY_SEPARATOR);
                
                // 폴더 존재 확인 또는 생성 시도
                if (!is_dir($userPath)) {
                    if (!@mkdir($userPath, 0755, true)) {
                        $errors[] = __('personal_folder_create_fail') . $userPath;
                    }
                }
                if (is_dir($userPath) && !is_writable($userPath)) {
                    $errors[] = __('personal_folder_no_write') . $userPath;
                }
            }
            
            if (!empty($sharedPath)) {
                // 경로 정규화
                $sharedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sharedPath);
                $sharedPath = rtrim($sharedPath, DIRECTORY_SEPARATOR);
                
                // 폴더 존재 확인 또는 생성 시도
                if (!is_dir($sharedPath)) {
                    if (!@mkdir($sharedPath, 0755, true)) {
                        $errors[] = __('shared_folder_create_fail') . $sharedPath;
                    }
                }
                if (is_dir($sharedPath) && !is_writable($sharedPath)) {
                    $errors[] = __('shared_folder_no_write') . $sharedPath;
                }
            }
            
            if (!empty($trashPath)) {
                // 경로 정규화
                $trashPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trashPath);
                $trashPath = rtrim($trashPath, DIRECTORY_SEPARATOR);
                
                // 폴더 존재 확인 또는 생성 시도
                if (!is_dir($trashPath)) {
                    if (!@mkdir($trashPath, 0755, true)) {
                        $errors[] = __('trash_folder_create_fail') . $trashPath;
                    }
                }
                if (is_dir($trashPath) && !is_writable($trashPath)) {
                    $errors[] = __('trash_folder_no_write') . $trashPath;
                }
            }
            
            if (!empty($errors)) {
                $result = ['success' => false, 'error' => implode("\n", $errors)];
                break;
            }
            
            // 설정 저장
            $pathsFile = __DIR__ . '/data/storage_paths.json';
            $oldPaths = file_exists($pathsFile) ? json_decode(file_get_contents($pathsFile), true) : [];
            $paths = [
                'user_files_root' => $userPath,
                'shared_files_root' => $sharedPath,
                'trash_path' => $trashPath,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            file_put_contents($pathsFile, json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            
            // 경로가 실제로 변경되었는지 확인
            $changed = false;
            if (($oldPaths['user_files_root'] ?? '') !== $userPath) $changed = true;
            if (($oldPaths['shared_files_root'] ?? '') !== $sharedPath) $changed = true;
            if (($oldPaths['trash_path'] ?? '') !== $trashPath) $changed = true;
            
            $result = ['success' => true];
            if ($changed) {
                $result['message'] = __('path_settings_saved');
            }
            break;
        
        // ===== 회원가입 설정 확인 (공개) =====
        case 'signup_status':
            $settings = $db->load('settings');
            $users = $db->load('users') ?: [];
            
            // 사용자가 없으면 첫 관리자 가입을 위해 자동 허용
            $isFirstUser = empty($users);
            $signupEnabled = $isFirstUser || ($settings['signup_enabled'] ?? false);
            
            $result = [
                'success' => true, 
                'signup_enabled' => $signupEnabled,
                'is_first_user' => $isFirstUser,
                'password_reset_enabled' => $settings['password_reset_enabled'] ?? false
            ];
            break;
        
        // ===== 비밀번호 찾기 (이메일) =====
        case 'find_username':
            $email = trim($input['email'] ?? '');
            
            if (empty($email)) {
                $result = ['success' => false, 'error' => __('api_err_enter_email', '이메일을 입력해주세요.')];
                break;
            }
            
            $result = $auth->findUsername($email);
            break;
        
        case 'password_reset_request':
            $username = trim($input['username'] ?? '');
            $email = trim($input['email'] ?? '');
            
            if (empty($username) || empty($email)) {
                $result = ['success' => false, 'error' => __('api_err_enter_id_email', '아이디와 이메일을 입력해주세요.')];
                break;
            }
            
            $result = $auth->requestPasswordReset($username, $email);
            break;
        
        // ===== 회원가입 =====
        case 'signup':
            $settings = $db->load('settings');
            $users = $db->load('users') ?: [];
            
            // 사용자가 없으면 첫 관리자 가입 허용
            $isFirstUser = empty($users);
            
            // 회원가입 허용 여부 확인 (첫 사용자는 항상 허용)
            if (!$isFirstUser && !($settings['signup_enabled'] ?? false)) {
                $result = ['success' => false, 'error' => __('api_signup_disabled', '회원가입이 비활성화되어 있습니다.')];
                break;
            }
            
            $username = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            $displayName = trim($input['display_name'] ?? '');
            $email = trim($input['email'] ?? '');
            $termsAgreed = $input['terms_agreed'] ?? false;
            
            // 약관이 활성화되어 있고 내용이 있으면 동의 필수 (첫 사용자는 예외)
            $terms = $db->load('terms');
            if (!$isFirstUser && !empty($terms['enabled']) && !empty($terms['content']) && !$termsAgreed) {
                $result = ['success' => false, 'error' => __('api_err_agree_terms', '이용약관에 동의해주세요.')];
                break;
            }
            
            // 유효성 검사
            if (empty($username) || empty($password)) {
                $result = ['success' => false, 'error' => __('api_err_required_id_pw', '아이디와 비밀번호는 필수입니다.')];
                break;
            }
            if (strlen($username) < 3 || strlen($username) > 20) {
                $result = ['success' => false, 'error' => __('api_err_username_length', '아이디는 3~20자여야 합니다.')];
                break;
            }
            if (strlen($password) < 8 || strlen($password) > 72) {
                $result = ['success' => false, 'error' => __('api_err_password_length', '비밀번호는 8~72자여야 합니다.')];
                break;
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                $result = ['success' => false, 'error' => __('api_err_username_chars', '아이디는 영문, 숫자, 밑줄만 사용 가능합니다.')];
                break;
            }
            if ($displayName !== '' && mb_strlen($displayName) > 30) {
                $result = ['success' => false, 'error' => __('err_display_name_too_long', '표시 이름은 30자 이내로 입력하세요.')];
                break;
            }
            
            // 중복 체크
            $existing = $db->find('users', ['username' => $username]);
            if ($existing) {
                $result = ['success' => false, 'error' => __('username_exists')];
                break;
            }
            
            // 탈퇴/삭제된 사용자 중복 체크
            $deletedUsers = $db->load('deleted_users') ?: [];
            foreach ($deletedUsers as $du) {
                if (strtolower(trim($du['username'] ?? '')) === strtolower(trim($username))) {
                    $result = ['success' => false, 'error' => __('username_was_withdrawn', '탈퇴 또는 삭제된 아이디입니다. 다른 아이디를 사용해주세요.')];
                    break 2;
                }
            }
            
            // 첫 번째 사용자는 관리자로, 이후는 설정에 따라
            if ($isFirstUser) {
                $role = 'admin';
                $status = 'active';
            } else {
                $role = 'user';
                $autoApprove = $settings['auto_approve'] ?? false;
                $status = $autoApprove ? 'active' : 'pending';
            }
            
            // 기본 용량 설정
            $defaultQuota = (int)($settings['default_quota'] ?? 0);
            
            // 사용자 생성
            $id = $db->insert('users', [
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => $displayName ?: $username,
                'email' => $email,
                'role' => $role,
                'status' => $status,
                'quota' => $isFirstUser ? 0 : $defaultQuota,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'terms_agreed_at' => $termsAgreed ? date('Y-m-d H:i:s') : null,
                'last_login' => null
            ]);
            
            // 스토리지별 기본 권한 적용 (첫 사용자 제외)
            if (!$isFirstUser && $id) {
                $storages = $db->load('storages') ?: [];
                foreach ($storages as $s) {
                    $sid = $s['id'] ?? 0;
                    if (!$sid) continue;
                    $defPerms = $s['default_permissions'] ?? [];
                    if (empty($defPerms)) continue;
                    $db->insert('permissions', [
                        'storage_id' => $sid,
                        'user_id' => $id,
                        'can_visible' => (int)($defPerms['can_visible'] ?? 0),
                        'can_visible_webdav' => (int)($defPerms['can_visible_webdav'] ?? 0),
                        'can_read' => (int)($defPerms['can_read'] ?? 0),
                        'can_download' => (int)($defPerms['can_download'] ?? 0),
                        'can_write' => (int)($defPerms['can_write'] ?? 0),
                        'can_delete' => (int)($defPerms['can_delete'] ?? 0),
                        'can_share' => (int)($defPerms['can_share'] ?? 0),
                    ]);
                }
            }
            
            if ($isFirstUser) {
                $result = ['success' => true, 'message' => __('admin_created')];
            } elseif ($status === 'active') {
                $result = ['success' => true, 'message' => __('signup_complete_login')];
            } else {
                $result = ['success' => true, 'message' => __('signup_pending_approval')];
            }
            break;
        
        // ===== 용량 =====
        case 'storage_quota':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $storageId = (int)($_GET['storage_id'] ?? 0);
            
            // 스토리지 정보 먼저 가져오기
            $storageInfo = $storage->getStorageById($storageId);
            $storageType = $storageInfo['storage_type'] ?? 'local';
            $remoteTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
            
            // 원격/SMB 스토리지: 실제 연결하지 않고 DB 캐시 사용 (블로킹 방지)
            if (in_array($storageType, $remoteTypes)) {
                $used = (int)($storageInfo['used_size'] ?? 0);
                $quota = (int)($storageInfo['quota'] ?? 0);
                
                if ($quota > 0) {
                    $total = $quota;
                    $free = max(0, $quota - $used);
                } elseif ($quota === -1) {
                    // 디스크 용량 표시: 인덱스 기반 사용량
                    $total = 0;
                    $free = 0;
                    // used_size가 없으면 인덱스에서 계산
                    if ($used === 0) {
                        $fileIndex = FileIndex::getInstance();
                        if ($fileIndex->isAvailable()) {
                            $used = $fileIndex->getStorageTotalSize($storageId);
                        }
                    }
                } else {
                    $total = 0;
                    $free = 0;
                }
                
                $lastCalc = (int)($storageInfo['used_size_updated_at'] ?? 0);
                $result = [
                    'success' => true,
                    'used' => $used,
                    'total' => $total,
                    'free' => $free,
                    'quota_set' => $quota > 0,
                    'used_formatted' => $fileManager->formatSize($used),
                    'total_formatted' => $total > 0 ? $fileManager->formatSize($total) : __('unlimited'),
                    'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
                    'needs_recalc' => false
                ];
                break;
            }
            
            $basePath = $storage->getRealPath($storageId);
            
            if ($basePath) {
                
                // home 타입이면 사용자 quota 사용
                $quota = 0;
                if ($storageType === 'home') {
                    // 폴더 사용량 계산 (home은 작으므로 직접 계산, 숨김파일 제외)
                    $used = $fileManager->getDirectorySize($basePath, true);
                    
                    // 캐시 우회하여 DB에서 직접 quota 조회
                    $userId = $auth->getUserId();
                    $freshUser = $db->find('users', ['id' => $userId]);
                    $userQuota = (int)($freshUser['quota'] ?? 0);
                    
                    if ($userQuota > 0) {
                        $quota = $userQuota;
                        $total = $userQuota;
                        $free = max(0, $total - $used);
                    } else {
                        // 무제한인 경우
                        $total = 0;
                        $free = 0;
                    }
                } else {
                    // shared/local 타입: DB 캐싱된 used_size 사용 (빠름!)
                    $used = (int)($storageInfo['used_size'] ?? 0);
                    $quota = (int)($storageInfo['quota'] ?? 0);
                    
                    if ($quota > 0) {
                        // 용량 직접 설정
                        $total = $quota;
                        $free = max(0, $quota - $used);
                    } else if ($quota === -1) {
                        // 디스크 용량 표시 모드
                        $diskTotal = @disk_total_space($basePath);
                        $diskFree = @disk_free_space($basePath);
                        if ($diskTotal !== false && $diskTotal > 0) {
                            $total = $diskTotal;
                            $free = $diskFree ?: 0;
                            $used = $diskTotal - ($diskFree ?: 0);
                        } else {
                            $total = 0;
                            $free = 0;
                        }
                    } else {
                        // 무제한 (quota=0)
                        $total = 0;
                        $free = 0;
                    }
                }
                
                $result = [
                    'success' => true,
                    'used' => $used,
                    'total' => $total,
                    'free' => $free,
                    'quota_set' => ($quota ?? 0) > 0,
                    'used_formatted' => $fileManager->formatSize($used),
                    'total_formatted' => $total > 0 ? $fileManager->formatSize($total) : __('unlimited'),
                    'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0
                ];
                
                // 백그라운드 사용량 재계산이 필요한지 플래그 전달
                if ($storageType !== 'home') {
                    $lastCalc = (int)($storageInfo['used_size_updated_at'] ?? 0);
                    $result['needs_recalc'] = ($lastCalc === 0 || (time() - $lastCalc) >= 60);
                }
            } else {
                $result = ['success' => false, 'error' => __('no_storage')];
            }
            break;
        
        // 스토리지 사용량 백그라운드 재계산 (JS에서 비동기 호출)
        case 'storage_recalc':
            $auth->requireLogin();
            session_write_close();
            @set_time_limit(30);
            $storageId = (int)($_GET['storage_id'] ?? 0);
            
            try {
                $recalcStorageInfo = $storage->getStorageById($storageId);
                if (!$recalcStorageInfo) {
                    $result = ['success' => true, 'recalculated' => false];
                    break;
                }
                $recalcType = $recalcStorageInfo['storage_type'] ?? '';
                // home, shared, local만 용량 계산 (나머지 전부 건너뛰기)
                if (!in_array($recalcType, ['home', 'shared', 'local'])) {
                    $result = ['success' => true, 'recalculated' => false];
                    break;
                }
                
                ignore_user_abort(true);
                // 재계산 주기: 시스템설정에서 지정 (분 단위, 기본 60분)
                // 업로드/삭제 시 updateUsedSize()가 즉시 반영하므로 주기 재계산은 보정 역할
                $recalcSettings = $db->load('settings') ?: [];
                $recalcIntervalMin = (int)($recalcSettings['storage_recalc_interval_minutes'] ?? 60);
                if ($recalcIntervalMin < 1) $recalcIntervalMin = 60;
                
                $recalcStart = microtime(true);
                $recalcUsedBefore = (int)($recalcStorageInfo['used_size'] ?? 0);
                $recalcResult = $storage->backgroundRecalcIfNeeded($storageId, $recalcIntervalMin * 60);
                $recalcElapsed = microtime(true) - $recalcStart;
                
                // 성능 로그: data/scan_perf.log 파일 있을 때만 기록
                // 실제 계산이 돈 경우(recalcResult=true)만 기록
                if ($recalcResult) {
                    $perfLog = DATA_PATH . '/scan_perf.log';
                    if (is_file($perfLog)) {
                        // 5MB 초과 시 뒤쪽 50%만 유지 (로그 파일 비대화 방지)
                        if (filesize($perfLog) > 5 * 1024 * 1024) {
                            $c = @file_get_contents($perfLog);
                            if ($c !== false) @file_put_contents($perfLog, substr($c, strlen($c) / 2));
                        }
                        $updatedStorage = $storage->getStorageById($storageId);
                        $usedAfter = (int)($updatedStorage['used_size'] ?? 0);
                        $diff = $usedAfter - $recalcUsedBefore;
                        $sizeGb = round($usedAfter / 1024 / 1024 / 1024, 2);
                        $diffMb = round($diff / 1024 / 1024, 2);
                        @file_put_contents($perfLog,
                            sprintf("[%s] storage_recalc done storage_id=%d name=%s elapsed=%.1fs size=%sGB delta=%sMB\n",
                                date('Y-m-d H:i:s'), $storageId,
                                substr($recalcStorageInfo['name'] ?? '?', 0, 30),
                                $recalcElapsed, $sizeGb, $diffMb),
                            FILE_APPEND | LOCK_EX);
                    }
                }
                $result = ['success' => true, 'recalculated' => $recalcResult];
            } catch (\Throwable $e) {
                $result = ['success' => true, 'recalculated' => false];
            }
            break;
        
        // ===== 휴지통 =====
        case 'trash_list':
            $auth->requireLogin();
            $user = $auth->getUser();
            session_write_close(); // 세션 락 해제
            $all = isset($_GET['all']) && $_GET['all'] === 'true' && ($user['role'] ?? '') === 'admin';
            
            $userId = $all ? null : $user['id'];
            $result = $fileManager->getTrashList($userId);
            break;
            
        case 'trash_restore':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $user = $auth->getUser();
            $id = $input['id'] ?? '';
            
            // 권한 확인
            $trash = $db->load('trash');
            $item = null;
            foreach ($trash as $t) {
                if ($t['id'] === $id) {
                    $item = $t;
                    break;
                }
            }
            
            if (!$item) {
                $result = ['success' => false, 'error' => __('item_not_found')];
                break;
            }
            
            // 관리자이거나 본인이 삭제한 것만 복원 가능
            if (($user['role'] ?? '') !== 'admin' && ($item['deleted_by'] ?? 0) !== $user['id']) {
                $result = ['success' => false, 'error' => __('no_permission')];
                break;
            }
            
            $result = $fileManager->restoreFromTrash($id);
            
            // 복원 로그
            if ($result['success'] ?? false) {
                $activityLog->log(ActivityLog::TYPE_RESTORE, [
                    'storage_id' => $item['storage_id'] ?? 0,
                    'storage_name' => $item['storage_name'] ?? '',
                    'path' => $item['original_path'] ?? '',
                    'filename' => $item['name'] ?? ''
                ]);
                
                // 인덱스 갱신
                $fileIndex = FileIndex::getInstance();
                if ($fileIndex->isAvailable()) {
                        $restoredStorageId = (int)($item['storage_id'] ?? 0);
                        $restoredPath = $item['original_path'] ?? '';
                        $basePath = $storage->getRealPath($restoredStorageId);
                        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $restoredPath);
                        if (is_dir($fullPath)) {
                            $fileIndex->indexFolder($restoredStorageId, $basePath, $restoredPath);
                        } else {
                            $fileIndex->addFile($restoredStorageId, $restoredPath, [
                                'name' => $item['name'] ?? basename($restoredPath),
                                'size' => file_exists($fullPath) ? filesize($fullPath) : 0,
                                'modified' => time(),
                                'is_dir' => false
                            ]);
                        }
                }
            }
            break;
            
        case 'trash_delete':
            $auth->requireLogin();
            $user = $auth->getUser();
            $id = $input['id'] ?? '';
            
            // 권한 확인
            $trash = $db->load('trash');
            $item = null;
            foreach ($trash as $t) {
                if ($t['id'] === $id) {
                    $item = $t;
                    break;
                }
            }
            
            if (!$item) {
                $result = ['success' => false, 'error' => __('item_not_found')];
                break;
            }
            
            // 권한 확인
            if (($user['role'] ?? '') !== 'admin' && ($item['deleted_by'] ?? 0) !== $user['id']) {
                $result = ['success' => false, 'error' => __('no_permission')];
                break;
            }
            
            session_write_close();
            @set_time_limit(0);
            
            // 폴더인 경우 진행률 파일 생성
            $progressFile = '';
            if (!empty($item['is_dir'])) {
                $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
                $progressFile = $dataDir . '/delete_progress_' . md5($id) . '.tmp';
            }
            
            $result = $fileManager->deleteFromTrash($id, $progressFile);
            break;
            
        case 'trash_empty':
            $auth->requireLogin();
            $user = $auth->getUser();
            $all = isset($input['all']) && $input['all'] === true && ($user['role'] ?? '') === 'admin';
            
            $userId = $all ? null : $user['id'];
            $result = $fileManager->emptyTrash($userId);
            break;
        
        // ===== 조건부 일괄 삭제 =====
        case 'bulk_search':
            $auth->requireAdminPerm('storages');
            $patterns = $input['patterns'] ?? [];
            if (is_string($patterns)) {
                $patterns = array_filter(array_map('trim', explode("\n", $patterns)));
            }
            $result = $fileManager->bulkSearch(
                (int)($input['storage_id'] ?? 0),
                $input['path'] ?? '',
                $patterns,
                $input['scope'] ?? 'recursive',
                $input['type'] ?? 'all'
            );
            break;
            
        case 'bulk_delete':
            $auth->requireAdminPerm('storages');
            $result = $fileManager->bulkDelete(
                (int)($input['storage_id'] ?? 0),
                $input['paths'] ?? []
            );
            break;
            
        // ===== 백신 설정 (관리자) =====
        case 'antivirus_settings':
            $auth->requireRealAdmin();
            $settings = $db->load('antivirus_settings');
            if (empty($settings)) {
                $settings = [
                    'engine' => 'disabled',
                    'max_size' => 100,
                    'clamav_path' => '',
                    'defender_path' => '',
                    'block_on_error' => false
                ];
            }
            
            $result = [
                'success' => true,
                'settings' => $settings
            ];
            break;
            
        case 'antivirus_settings_save':
            $auth->requireRealAdmin();
            
            $settings = [
                'engine' => in_array($input['engine'] ?? 'disabled', ['disabled', 'clamav', 'defender']) 
                    ? ($input['engine'] ?? 'disabled') : 'disabled',
                'max_size' => max(0, (int)($input['max_size'] ?? 100)),
                'clamav_path' => trim($input['clamav_path'] ?? ''),
                'defender_path' => trim($input['defender_path'] ?? ''),
                'block_on_error' => (bool)($input['block_on_error'] ?? false)
            ];
            
            $db->save('antivirus_settings', $settings);
            
            // config용 JSON 파일도 저장 (FileManager에서 로드)
            @file_put_contents(DATA_PATH . '/antivirus_config.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            
            $result = ['success' => true];
            break;
            
        case 'antivirus_test':
            $auth->requireRealAdmin();
            $fm = new FileManager();
            $result = $fm->testAntivirus();
            break;
            
        case 'antivirus_logs':
            $auth->requireRealAdmin();
            
            $page = max(1, (int)($input['page'] ?? 1));
            $perPage = min(100, max(10, (int)($input['per_page'] ?? 50)));
            $dateFrom = $input['date_from'] ?? '';
            $dateTo = $input['date_to'] ?? '';
            
            $logFile = DATA_PATH . '/antivirus_scan_logs.json';
            $allLogs = [];
            if (file_exists($logFile)) {
                $allLogs = json_decode(file_get_contents($logFile), true) ?: [];
            }
            
            // 날짜 필터링
            $filteredLogs = [];
            $cleanCount = 0;
            $threatCount = 0;
            
            foreach ($allLogs as $log) {
                $logDate = substr($log['time'] ?? '', 0, 10);
                
                if ($dateFrom && $logDate < $dateFrom) continue;
                if ($dateTo && $logDate > $dateTo) continue;
                
                $filteredLogs[] = $log;
                if ($log['clean'] ?? true) {
                    $cleanCount++;
                } else {
                    $threatCount++;
                }
            }
            
            // 역순 정렬 (최신순)
            $filteredLogs = array_reverse($filteredLogs);
            
            $total = count($filteredLogs);
            $offset = ($page - 1) * $perPage;
            $logs = array_slice($filteredLogs, $offset, $perPage);
            
            $result = [
                'success' => true,
                'logs' => $logs,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'clean_count' => $cleanCount,
                'threat_count' => $threatCount
            ];
            break;
            
        case 'antivirus_logs_delete':
            $auth->requireRealAdmin();
            
            $dateFrom = $input['date_from'] ?? '';
            $dateTo = $input['date_to'] ?? '';
            
            if (empty($dateFrom) && empty($dateTo)) {
                $result = ['success' => false, 'error' => __('api_err_specify_delete_date', '삭제할 날짜 범위를 지정하세요.')];
                break;
            }
            
            $logFile = DATA_PATH . '/antivirus_scan_logs.json';
            $allLogs = [];
            if (file_exists($logFile)) {
                $allLogs = json_decode(file_get_contents($logFile), true) ?: [];
            }
            
            $deletedCount = 0;
            $remainingLogs = [];
            
            foreach ($allLogs as $log) {
                $logDate = substr($log['time'] ?? '', 0, 10);
                $shouldDelete = true;
                
                if ($dateFrom && $logDate < $dateFrom) $shouldDelete = false;
                if ($dateTo && $logDate > $dateTo) $shouldDelete = false;
                
                if ($shouldDelete) {
                    $deletedCount++;
                } else {
                    $remainingLogs[] = $log;
                }
            }
            
            file_put_contents($logFile, json_encode($remainingLogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $result = ['success' => true, 'deleted' => $deletedCount];
            break;
            
        case 'antivirus_logs_clear':
            $auth->requireRealAdmin();
            
            $logFile = DATA_PATH . '/antivirus_scan_logs.json';
            file_put_contents($logFile, json_encode([], JSON_PRETTY_PRINT));
            
            $result = ['success' => true];
            break;
            
        // ===== 랜섬웨어 방지 (관리자) =====
        case 'get_upload_settings':
            $auth->requireRealAdmin();
            $settings = $db->load('upload_settings');
            if (empty($settings)) {
                $settings = [
                    'mode' => 'all',
                    'allowed_extensions' => '',
                    'blocked_extensions' => '',
                    'mime_check' => true,
                    'max_filesize' => 0
                ];
            }
            $result = ['success' => true, 'settings' => $settings];
            break;
        
        case 'save_upload_settings':
            $auth->requireRealAdmin();
            $settings = [
                'mode' => in_array($input['mode'] ?? '', ['all', 'allow', 'block']) ? $input['mode'] : 'all',
                'allowed_extensions' => trim($input['allowed_extensions'] ?? ''),
                'blocked_extensions' => trim($input['blocked_extensions'] ?? ''),
                'mime_check' => (bool)($input['mime_check'] ?? true),
                'max_filesize' => max(0, (int)($input['max_filesize'] ?? 0))
            ];
            $db->save('upload_settings', $settings);
            $result = ['success' => true];
            break;
        
        case 'ransomware_settings':
            $auth->requireRealAdmin();
            $settings = $db->load('ransomware_settings');
            if (empty($settings)) {
                $settings = [
                    'block_extensions' => false,
                    'blocked_extensions' => '.encrypted, .locked, .crypto, .crypt, .locky, .cerber, .zepto, .thor, .aaa, .zzz',
                    'block_random_ext' => false,
                    'bulk_protection' => false,
                    'bulk_time' => 60,
                    'bulk_delete_limit' => 50,
                    'bulk_overwrite_limit' => 50,
                    'block_duration' => 30,
                    'versioning' => false,
                    'version_days' => 7,
                    'max_versions' => 10,
                    'version_exclude' => '.tmp, .log, .bak'
                ];
            }
            
            $result = [
                'success' => true,
                'settings' => $settings
            ];
            break;
            
        case 'ransomware_settings_save':
            $auth->requireRealAdmin();
            
            $settings = [
                'block_extensions' => (bool)($input['block_extensions'] ?? false),
                'blocked_extensions' => trim($input['blocked_extensions'] ?? ''),
                'block_random_ext' => (bool)($input['block_random_ext'] ?? false),
                'bulk_protection' => (bool)($input['bulk_protection'] ?? false),
                'bulk_time' => max(10, (int)($input['bulk_time'] ?? 60)),
                'bulk_delete_limit' => max(5, (int)($input['bulk_delete_limit'] ?? 50)),
                'bulk_overwrite_limit' => max(5, (int)($input['bulk_overwrite_limit'] ?? 50)),
                'block_duration' => max(1, (int)($input['block_duration'] ?? 30)),
                'versioning' => (bool)($input['versioning'] ?? false),
                'version_days' => max(1, (int)($input['version_days'] ?? 7)),
                'max_versions' => max(1, (int)($input['max_versions'] ?? 10)),
                'version_exclude' => trim($input['version_exclude'] ?? ''),
                // 파일 내용 검사
                'content_check' => (bool)($input['content_check'] ?? false),
                'entropy_check' => (bool)($input['entropy_check'] ?? false),
                'entropy_threshold' => max(6.0, min(8.0, (float)($input['entropy_threshold'] ?? 7.5))),
                'signature_check' => (bool)($input['signature_check'] ?? false),
                'block_signature_mismatch' => (bool)($input['block_signature_mismatch'] ?? false)
            ];
            
            $db->save('ransomware_settings', $settings);
            
            // config용 JSON 파일도 저장 (FileManager에서 로드)
            @file_put_contents(DATA_PATH . '/ransomware_config.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            
            $result = ['success' => true];
            break;
            
        case 'ransomware_logs':
            $auth->requireRealAdmin();
            
            $logFile = DATA_PATH . '/ransomware_logs.json';
            $logs = [];
            if (file_exists($logFile)) {
                $logs = json_decode(file_get_contents($logFile), true) ?: [];
            }
            
            // 역순 정렬 (최신순)
            $logs = array_reverse($logs);
            
            // 최대 100개만
            $logs = array_slice($logs, 0, 100);
            
            $blockedCount = 0;
            foreach ($logs as $log) {
                if (($log['type'] ?? '') === 'blocked') {
                    $blockedCount++;
                }
            }
            
            $result = [
                'success' => true,
                'logs' => $logs,
                'total' => count($logs),
                'blocked_count' => $blockedCount
            ];
            break;
            
        case 'ransomware_logs_clear':
            $auth->requireRealAdmin();
            
            $logFile = DATA_PATH . '/ransomware_logs.json';
            file_put_contents($logFile, json_encode([], JSON_PRETTY_PRINT));
            
            $result = ['success' => true];
            break;
            
        case 'file_versions':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            // 폴더별 읽기 권한 체크
            { $fvDir = dirname($path); if ($fvDir === '.') $fvDir = '';
              if (!$storage->checkFolderPermission($storageId, $fvDir ?: $path)) {
                  $result = ['success' => false, 'error' => 'No permission']; break; }
            }
            
            $fm = new FileManager();
            $result = $fm->getFileVersions($storageId, $path);
            break;
            
        case 'file_version_restore':
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            $versionId = $input['version_id'] ?? '';
            // 폴더별 쓰기 권한 체크
            { $vrDir = dirname($path); if ($vrDir === '.') $vrDir = '';
              if (!$storage->checkFolderPermission($storageId, $vrDir ?: $path, 'can_write')) {
                  $result = ['success' => false, 'error' => 'No permission']; break; }
            }
            
            $fm = new FileManager();
            $result = $fm->restoreFileVersion($storageId, $path, $versionId);
            
            // 인덱스 갱신
            if ($result['success'] ?? false) {
                $fileIndex = FileIndex::getInstance();
                if ($fileIndex->isAvailable()) {
                    $basePath = $storage->getRealPath($storageId);
                    $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                    $fileIndex->addFile($storageId, $path, [
                        'name' => basename($path),
                        'size' => file_exists($fullPath) ? filesize($fullPath) : 0,
                        'modified' => time(),
                        'is_dir' => false
                    ]);
                }
            }
            break;
            
        case 'cleanup_old_versions':
            $auth->requireRealAdmin();
            $fm = new FileManager();
            $result = $fm->cleanupOldVersions();
            break;
            
        case 'file_version_download':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            $versionId = $_GET['version_id'] ?? '';
            
            if (empty($versionId)) {
                http_response_code(400);
                die('Invalid version ID');
            }
            
            // 경로 조작 방지: basename으로 디렉토리 구분자 제거 + 안전 문자만 허용
            $versionId = basename($versionId);
            if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $versionId)) {
                http_response_code(400);
                die('Invalid version ID format');
            }
            
            $versionDir = DATA_PATH . '/file_versions';
            $fileHash = md5($storageId . '/' . $path);
            $versionFile = $versionDir . '/' . $fileHash . '/' . $versionId;
            
            // 경로가 버전 디렉토리 내에 있는지 재확인
            $realVersionDir = realpath($versionDir . '/' . $fileHash);
            $realVersionFile = realpath($versionFile);
            // 보안: isSubPath 사용 (Windows 대소문자 + prefix 확장 방어)
            if (!$realVersionDir || !$realVersionFile || !\isSubPath($realVersionFile, $realVersionDir)) {
                http_response_code(403);
                die('Invalid path');
            }
            
            if (!file_exists($versionFile)) {
                http_response_code(404);
                die('Version not found');
            }
            
            $filename = basename($path);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
            $downloadName = $nameWithoutExt . '_v' . date('Ymd_His', (int)explode('_', $versionId)[0]) . '.' . $ext;
            
            // RFC 5987 안전한 Content-Disposition
            $downloadNameSafe = preg_replace('/[^\x20-\x7E]|["\\]/', '_', $downloadName);
            $downloadNameEncoded = rawurlencode($downloadName);
            
            header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename=\"{$downloadNameSafe}\"; filename*=UTF-8''{$downloadNameEncoded}");
            header('Content-Length: ' . filesize($versionFile));
            header('Cache-Control: no-cache');
            
            readfile($versionFile);
            exit;
            
        // ===== 보안 설정 (관리자) =====
        case 'security_settings':
            $auth->requireRealAdmin();  // 실제 관리자만
            $settings = $db->load('security_settings');
            if (empty($settings)) {
                $settings = [
                    'enabled' => false,
                    'block_country' => false,
                    'allow_country_only' => false,
                    'block_ip' => false,
                    'allow_ip_only' => false,
                    'allowed_ips' => [],
                    'blocked_ips' => [],
                    'allowed_countries' => [],
                    'blocked_countries' => [],
                    'admin_ips' => [],
                    'block_message' => __('api_err_access_blocked', '접근이 차단되었습니다.'),
                    'cache_hours' => 24,
                    'log_enabled' => false,
                    'max_attempts' => 5,
                    'attempt_reset_minutes' => 30,
                    'lockout_minutes' => 15,
                    'lockout_permanent' => false
                ];
            }
            $result = [
                'success' => true, 
                'settings' => $settings,
                'current_ip' => $auth->getCurrentIP(),
                'current_country' => $auth->getCurrentCountry()
            ];
            break;
            
        case 'security_settings_save':
            $auth->requireRealAdmin();  // 실제 관리자만
            
            $settings = [
                'enabled' => !empty($input['enabled']),
                'block_country' => !empty($input['block_country']),
                'allow_country_only' => !empty($input['allow_country_only']),
                'block_ip' => !empty($input['block_ip']),
                'allow_ip_only' => !empty($input['allow_ip_only']),
                'allowed_ips' => array_filter(array_map('trim', $input['allowed_ips'] ?? [])),
                'blocked_ips' => array_filter(array_map('trim', $input['blocked_ips'] ?? [])),
                'allowed_countries' => array_filter(array_map('trim', $input['allowed_countries'] ?? [])),
                'blocked_countries' => array_filter(array_map('trim', $input['blocked_countries'] ?? [])),
                'admin_ips' => array_filter(array_map('trim', $input['admin_ips'] ?? [])),
                'block_message' => trim($input['block_message'] ?? __('api_err_access_blocked', '접근이 차단되었습니다.')),
                'cache_hours' => max(1, min(168, (int)($input['cache_hours'] ?? 24))),
                'log_enabled' => !empty($input['log_enabled']),
                'max_attempts' => max(0, (int)($input['max_attempts'] ?? 5)),
                'attempt_reset_minutes' => max(1, (int)($input['attempt_reset_minutes'] ?? 30)),
                'lockout_minutes' => max(0, (int)($input['lockout_minutes'] ?? 15)),
                'lockout_permanent' => !empty($input['lockout_permanent'])
            ];
            
            $db->save('security_settings', $settings);
            $result = ['success' => true];
            break;
            
        case 'security_test':
            $auth->requireRealAdmin();  // 실제 관리자만
            $result = array_merge(['success' => true], $auth->testIPRestriction());
            break;
        
        // 차단 로그 조회
        case 'security_block_logs':
            $auth->requireRealAdmin();
            $logs = $db->load('security_block_logs');
            
            // 날짜 필터
            $dateFrom = $input['date_from'] ?? '';
            $dateTo = $input['date_to'] ?? '';
            if ($dateFrom) {
                $dateFrom = $dateFrom . ' 00:00:00';
                $logs = array_filter($logs, fn($l) => ($l['created_at'] ?? '') >= $dateFrom);
            }
            if ($dateTo) {
                $dateTo = $dateTo . ' 23:59:59';
                $logs = array_filter($logs, fn($l) => ($l['created_at'] ?? '') <= $dateTo);
            }
            
            // ID 추가 (인덱스 기반)
            $logs = array_values($logs);
            foreach ($logs as $i => &$log) {
                $log['id'] = $i;
            }
            unset($log);
            
            // 최신순 정렬
            usort($logs, function($a, $b) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });
            
            // 페이지네이션
            $page = max(1, intval($input['page'] ?? 1));
            $perPage = min(100, max(1, intval($input['per_page'] ?? 20)));
            $total = count($logs);
            $totalPages = max(1, ceil($total / $perPage));
            $offset = ($page - 1) * $perPage;
            $logs = array_slice($logs, $offset, $perPage);
            
            $result = [
                'success' => true, 
                'logs' => $logs,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages
            ];
            break;
        
        // 차단 로그 삭제
        case 'security_block_logs_clear':
            $auth->requireRealAdmin();
            $db->save('security_block_logs', []);
            $result = ['success' => true, 'message' => __('api_block_log_deleted', '차단 로그가 삭제되었습니다.')];
            break;
        
        // 차단 로그 선택 삭제
        case 'security_block_logs_delete':
            $auth->requireRealAdmin();
            $ids = $input['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                $result = ['success' => false, 'error' => __('api_err_specify_delete_ids', '삭제할 ID를 지정하세요.')];
                break;
            }
            $logs = $db->load('security_block_logs');
            $logs = array_values(array_filter($logs, function($l, $i) use ($ids) {
                return !in_array($i, $ids);
            }, ARRAY_FILTER_USE_BOTH));
            $db->save('security_block_logs', $logs);
            $result = ['success' => true, 'message' => __f('n_logs_deleted_be', ['count' => count($ids)])];
            break;
        
        // 브루트포스 로그 조회
        case 'bruteforce_logs':
            $auth->requireRealAdmin();
            $logs = $db->load('login_attempts');
            
            // 날짜 필터
            $dateFrom = $input['date_from'] ?? '';
            $dateTo = $input['date_to'] ?? '';
            if ($dateFrom) {
                $dateFrom = $dateFrom . ' 00:00:00';
                $logs = array_filter($logs, fn($l) => ($l['last_attempt'] ?? '') >= $dateFrom);
            }
            if ($dateTo) {
                $dateTo = $dateTo . ' 23:59:59';
                $logs = array_filter($logs, fn($l) => ($l['last_attempt'] ?? '') <= $dateTo);
            }
            
            $logs = array_values($logs);
            
            // 시도 횟수 높은 순 정렬
            usort($logs, function($a, $b) {
                return ($b['attempts'] ?? 0) - ($a['attempts'] ?? 0);
            });
            
            // 페이지네이션
            $page = max(1, intval($input['page'] ?? 1));
            $perPage = min(100, max(1, intval($input['per_page'] ?? 20)));
            $total = count($logs);
            $totalPages = max(1, ceil($total / $perPage));
            $offset = ($page - 1) * $perPage;
            $logs = array_slice($logs, $offset, $perPage);
            
            $result = [
                'success' => true, 
                'logs' => $logs,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages
            ];
            break;
        
        // 브루트포스 로그 삭제 (특정 IP)
        case 'bruteforce_logs_clear':
            $auth->requireRealAdmin();
            $ip = $input['ip'] ?? '';
            if ($ip) {
                // 특정 IP만 삭제
                $logs = $db->load('login_attempts');
                $logs = array_filter($logs, fn($l) => ($l['ip'] ?? '') !== $ip);
                $db->save('login_attempts', array_values($logs));
                $result = ['success' => true, 'message' => __f('ip_unlocked', ['ip' => $ip])];
            } else {
                // 전체 삭제
                $db->save('login_attempts', []);
                $result = ['success' => true, 'message' => __('api_bruteforce_logs_deleted', '브루트포스 로그가 삭제되었습니다.')];
            }
            break;
        
        // 브루트포스 로그 선택 삭제
        case 'bruteforce_logs_delete':
            $auth->requireRealAdmin();
            $ips = $input['ips'] ?? [];
            if (!is_array($ips) || empty($ips)) {
                $result = ['success' => false, 'error' => __('api_err_specify_delete_ips', '삭제할 IP를 지정하세요.')];
                break;
            }
            $logs = $db->load('login_attempts');
            $logs = array_values(array_filter($logs, fn($l) => !in_array($l['ip'] ?? '', $ips)));
            $db->save('login_attempts', $logs);
            $result = ['success' => true, 'message' => __f('n_ip_unlocked_be', ['count' => count($ips)])];
            break;
        
        // ===== TOTP (2FA) 설정 =====
        case 'totp_settings':
            $auth->requireRealAdmin();
            $totpFile = __DIR__ . '/data/totp_settings.json';
            $totpSettings = [];
            if (file_exists($totpFile)) {
                $totpSettings = json_decode(file_get_contents($totpFile), true) ?: [];
            }
            // 암호화 키는 마스킹하여 반환 (보안)
            $maskedKey = '';
            $hasCustomKey = !empty($totpSettings['encryption_key']);
            if ($hasCustomKey) {
                $key = $totpSettings['encryption_key'];
                $maskedKey = substr($key, 0, 8) . '...' . substr($key, -8);
            } else {
                // 기본 키 사용 중
                $maskedKey = __('totp_default_key_warning');
            }
            $result = [
                'success' => true,
                'settings' => [
                    'issuer' => $totpSettings['issuer'] ?? 'WebHard',
                    'encryption_key_masked' => $maskedKey,
                    'has_custom_key' => $hasCustomKey,
                    'generated_at' => $totpSettings['generated_at'] ?? null
                ]
            ];
            break;
        
        case 'totp_settings_save':
            $auth->requireRealAdmin();
            $totpFile = __DIR__ . '/data/totp_settings.json';
            
            // 기존 설정 로드
            $totpSettings = [];
            if (file_exists($totpFile)) {
                $totpSettings = json_decode(file_get_contents($totpFile), true) ?: [];
            }
            
            // issuer 업데이트
            $newIssuer = trim($input['issuer'] ?? '');
            if (!empty($newIssuer)) {
                $totpSettings['issuer'] = $newIssuer;
            }
            
            // 암호화 키 재생성 요청
            if (!empty($input['regenerate_key'])) {
                // 기존 2FA 사용자가 있는지 확인
                $users = $db->load('users');
                $has2faUsers = false;
                foreach ($users as $user) {
                    if (!empty($user['2fa_enabled'])) {
                        $has2faUsers = true;
                        break;
                    }
                }
                
                if ($has2faUsers) {
                    $result = [
                        'success' => false, 
                        'error' => __('totp_users_exist')
                    ];
                    break;
                }
                
                $totpSettings['encryption_key'] = bin2hex(random_bytes(32));
                $totpSettings['generated_at'] = date('Y-m-d H:i:s');
            }
            
            // 저장
            if (file_put_contents($totpFile, json_encode($totpSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX)) {
                $result = ['success' => true, 'message' => __('totp_settings_saved')];
            } else {
                $result = ['success' => false, 'error' => __('settings_file_save_fail')];
            }
            break;
        
        // ===== 즐겨찾기 =====
        case 'favorites_get':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            session_write_close(); // 세션 락 해제
            $favorites = $db->findAll('favorites', ['user_id' => $userId]);
            $result = ['success' => true, 'favorites' => array_values($favorites)];
            break;
            
        case 'favorites_add':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            $name = $input['name'] ?? basename($path);
            $isDir = (bool)($input['is_dir'] ?? false);
            
            // 이미 존재하는지 확인
            $existing = $db->find('favorites', [
                'user_id' => $userId,
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            if ($existing) {
                $result = ['success' => false, 'error' => __('api_err_already_favorite', '이미 즐겨찾기에 추가되어 있습니다.')];
            } else {
                $db->insert('favorites', [
                    'user_id' => $userId,
                    'storage_id' => $storageId,
                    'path' => $path,
                    'name' => $name,
                    'is_dir' => $isDir ? 1 : 0,
                    'added_at' => date('Y-m-d H:i:s')
                ]);
                
                // 오래된 기록 정리 (100개 초과 시)
                $allFavorites = $db->findAll('favorites', ['user_id' => $userId]);
                if (count($allFavorites) > 100) {
                    usort($allFavorites, function($a, $b) {
                        return strtotime($b['added_at'] ?? 0) - strtotime($a['added_at'] ?? 0);
                    });
                    $toDelete = array_slice($allFavorites, 100);
                    foreach ($toDelete as $item) {
                        $db->delete('favorites', ['id' => $item['id']]);
                    }
                }
                
                $result = ['success' => true, 'message' => __('api_favorite_added', '즐겨찾기에 추가되었습니다.')];
            }
            break;
            
        case 'favorites_remove':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            
            $count = $db->delete('favorites', [
                'user_id' => $userId,
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            $result = $count > 0 
                ? ['success' => true, 'message' => __('api_favorite_removed', '즐겨찾기에서 제거되었습니다.')]
                : ['success' => false, 'error' => __('api_err_favorite_not_found', '즐겨찾기를 찾을 수 없습니다.')];
            break;
        
        // 즐겨찾기 전체 삭제
        case 'favorites_clear':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            
            $allFavorites = $db->findAll('favorites', ['user_id' => $userId]);
            foreach ($allFavorites as $item) {
                $db->delete('favorites', ['id' => $item['id']]);
            }
            
            $result = ['success' => true, 'message' => __('api_favorites_cleared', '즐겨찾기가 모두 삭제되었습니다.')];
            break;
        
        // ===== 최근 파일 =====
        case 'recent_files_get':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            session_write_close(); // 세션 락 해제
            $limit = min((int)($input['limit'] ?? 50), 100);
            
            $recentFiles = $db->findAll('recent_files', ['user_id' => $userId]);
            // 최신순 정렬
            usort($recentFiles, function($a, $b) {
                return strtotime($b['accessed_at'] ?? 0) - strtotime($a['accessed_at'] ?? 0);
            });
            // 제한
            $recentFiles = array_slice($recentFiles, 0, $limit);
            
            $result = ['success' => true, 'files' => array_values($recentFiles)];
            break;
            
        case 'recent_files_add':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            $name = $input['name'] ?? basename($path);
            $action = $input['action'] ?? 'view'; // view, download, upload
            
            // 기존 기록 삭제 (중복 방지)
            $db->delete('recent_files', [
                'user_id' => $userId,
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            // 새로 추가
            $db->insert('recent_files', [
                'user_id' => $userId,
                'storage_id' => $storageId,
                'path' => $path,
                'name' => $name,
                'action' => $action,
                'accessed_at' => date('Y-m-d H:i:s')
            ]);
            
            // 오래된 기록 정리 (100개 초과 시)
            $allRecent = $db->findAll('recent_files', ['user_id' => $userId]);
            if (count($allRecent) > 100) {
                usort($allRecent, function($a, $b) {
                    return strtotime($b['accessed_at'] ?? 0) - strtotime($a['accessed_at'] ?? 0);
                });
                $toDelete = array_slice($allRecent, 100);
                foreach ($toDelete as $item) {
                    $db->delete('recent_files', ['id' => $item['id']]);
                }
            }
            
            $result = ['success' => true];
            break;
        
        // 최근 파일 배치 추가 (여러 파일 한번에)
        case 'recent_files_add_batch':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            _sessionDebugLog('STEP:recent_after_swc');
            $userId = $_SESSION['user_id'];
            $items = $input['items'] ?? [];
            
            if (!is_array($items) || empty($items)) {
                $result = ['success' => true];
                break;
            }
            
            _sessionDebugLog('STEP:recent_before_write', 'items=' . count($items));
            foreach ($items as $item) {
                $storageId = (int)($item['storage_id'] ?? 0);
                $path = $item['path'] ?? '';
                $name = $item['name'] ?? basename($path);
                $action = $item['action'] ?? 'view';
                
                if (empty($path)) continue;
                
                // 기존 기록 삭제 (중복 방지)
                $db->delete('recent_files', [
                    'user_id' => $userId,
                    'storage_id' => $storageId,
                    'path' => $path
                ]);
                
                // 새로 추가
                $db->insert('recent_files', [
                    'user_id' => $userId,
                    'storage_id' => $storageId,
                    'path' => $path,
                    'name' => $name,
                    'action' => $action,
                    'accessed_at' => date('Y-m-d H:i:s')
                ]);
            }
            _sessionDebugLog('STEP:recent_write_done');
            
            // 오래된 기록 정리 (100개 초과 시)
            $allRecent = $db->findAll('recent_files', ['user_id' => $userId]);
            if (count($allRecent) > 100) {
                usort($allRecent, function($a, $b) {
                    return strtotime($b['accessed_at'] ?? 0) - strtotime($a['accessed_at'] ?? 0);
                });
                $toDelete = array_slice($allRecent, 100);
                foreach ($toDelete as $item) {
                    $db->delete('recent_files', ['id' => $item['id']]);
                }
            }
            _sessionDebugLog('STEP:recent_cleanup_done');
            
            $result = ['success' => true, 'count' => count($items)];
            break;
            
        case 'recent_files_clear':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            
            $allRecent = $db->findAll('recent_files', ['user_id' => $userId]);
            foreach ($allRecent as $item) {
                $db->delete('recent_files', ['id' => $item['id']]);
            }
            
            $result = ['success' => true, 'message' => __('api_recent_cleared', '최근 파일 기록이 삭제되었습니다.')];
            break;
        
        // 최근 파일 개별 삭제
        case 'recent_files_remove':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            
            $db->delete('recent_files', [
                'user_id' => $userId,
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            $result = ['success' => true];
            break;
        
        // ===== 파일 잠금 =====
        case 'file_lock':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            // 폴더별 쓰기 권한 체크
            { $_flDir = dirname($path); if ($_flDir === '.') $_flDir = '';
              if (!$storage->checkFolderPermission($storageId, $_flDir ?: $path, 'can_write')) {
                  $result = ['success' => false, 'error' => 'No permission']; break; }
            }
            
            // 이미 잠겨있는지 확인
            $existing = $db->find('locked_files', [
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            if ($existing) {
                $result = ['success' => false, 'error' => __('api_err_file_already_locked', '이미 잠긴 파일입니다.')];
            } else {
                $db->insert('locked_files', [
                    'user_id' => $userId,
                    'storage_id' => $storageId,
                    'path' => $path,
                    'locked_at' => date('Y-m-d H:i:s')
                ]);
                $result = ['success' => true, 'message' => __('api_file_locked', '파일이 잠겼습니다.')];
            }
            break;
            
        case 'file_unlock':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            // 폴더별 쓰기 권한 체크
            { $_fuDir = dirname($path); if ($_fuDir === '.') $_fuDir = '';
              if (!$storage->checkFolderPermission($storageId, $_fuDir ?: $path, 'can_write')) {
                  $result = ['success' => false, 'error' => 'No permission']; break; }
            }
            
            // 본인이 잠근 파일인지 또는 관리자인지 확인
            $locked = $db->find('locked_files', [
                'storage_id' => $storageId,
                'path' => $path
            ]);
            
            if (!$locked) {
                $result = ['success' => false, 'error' => __('api_err_file_not_locked', '잠긴 파일이 아닙니다.')];
            } else if ($locked['user_id'] != $userId && !$auth->isAdmin()) {
                $result = ['success' => false, 'error' => __('api_err_only_owner_unlock', '본인이 잠근 파일만 해제할 수 있습니다.')];
            } else {
                $db->delete('locked_files', ['id' => $locked['id']]);
                $result = ['success' => true, 'message' => __('api_file_unlocked', '파일 잠금이 해제되었습니다.')];
            }
            break;
            
        case 'locked_files_get':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $storageId = (int)($input['storage_id'] ?? 0);
            
            $lockedFiles = $db->findAll('locked_files', ['storage_id' => $storageId]);
            // path만 배열로 반환 (빠른 조회용)
            $lockedPaths = array_column($lockedFiles, 'path');
            
            $result = ['success' => true, 'locked_paths' => $lockedPaths, 'locked_files' => array_values($lockedFiles)];
            break;
        
        // ===== 통합 검색 =====
        case 'search_advanced':
            $auth->requireLogin();
            $userId = $_SESSION['user_id'];
            session_write_close(); // 세션 락 해제
            $storageId = (int)($input['storage_id'] ?? 0);
            $query = trim($input['query'] ?? '');
            $filters = $input['filters'] ?? [];
            $page = max(1, (int)($input['page'] ?? 1));
            $perPage = min(100, max(20, (int)($input['per_page'] ?? 50)));
            
            // 검색어가 비어있으면 빈 결과 반환
            if (empty($query)) {
                $result = [
                    'success' => true,
                    'results' => [],
                    'total' => 0,
                    'page' => 1,
                    'per_page' => $perPage,
                    'total_pages' => 0
                ];
                break;
            }
            
            // FileIndex 초기화
            $fileIndex = FileIndex::getInstance();
            if (!$fileIndex->isAvailable()) {
                $result = ['success' => false, 'error' => __('api_err_search_index_unavailable', '검색 인덱스를 사용할 수 없습니다. 관리자에게 문의하세요.')];
                break;
            }
            
            // 필터 옵션
            $fileType = $filters['type'] ?? ''; // image, video, audio, document, archive, all
            $dateFrom = $filters['date_from'] ?? '';
            $dateTo = $filters['date_to'] ?? '';
            $sizeMin = (int)($filters['size_min'] ?? 0); // bytes
            $sizeMax = (int)($filters['size_max'] ?? 0); // 0 = 무제한
            $searchPath = $filters['path'] ?? ''; // 특정 폴더 내 검색
            
            // 스토리지 접근 권한 확인
            if ($storageId > 0) {
                $storageInfo = $storage->getStorage($storageId);
                if (!$storageInfo) {
                    $result = ['success' => false, 'error' => __('storage_not_found')];
                    break;
                }
            }
            
            // FileIndex 사용 - 제한 없이 전체 검색
            $searchResults = [];
            $isRealtimeSearch = false;
            
            if ($storageId > 0) {
                // 특정 스토리지 검색
                // 해당 스토리지에 인덱스 데이터가 있는지 확인
                $indexedIds = $fileIndex->getIndexedStorageIds();
                
                if (in_array($storageId, $indexedIds)) {
                    // 인덱스 있음 → DB 검색
                    $results = $fileIndex->search($query, $storageId, 0);
                    $sName = $storageInfo['name'] ?? '';
                    foreach ($results as $item) {
                        $item['storage_id'] = $storageId;
                        $item['storage_name'] = $sName;
                        $searchResults[] = $item;
                    }
                } else {
                    // 인덱스 없음 → 실시간 검색 fallback
                    @set_time_limit(120);
                    @ini_set('memory_limit', '512M');
                    $isRealtimeSearch = true;
                    $fullStorageInfo = $storage->getStorageById($storageId);
                    $sType = $fullStorageInfo['storage_type'] ?? 'local';
                    $remoteAdapterTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
                    $sName = $fullStorageInfo['name'] ?? '';
                    $realtimeResults = [];
                    
                    if (in_array($sType, $remoteAdapterTypes)) {
                        try {
                            $adapter = StorageAdapterFactory::create($fullStorageInfo);
                            if ($adapter->connect()) {
                                $fileManager->_searchStartTime = time();
                                $fileManager->searchRemoteRecursive($adapter, '', $query, $realtimeResults, 200);
                                $adapter->disconnect();
                            }
                        } catch (Throwable $e) {}
                    } else {
                        $storagePath = $storage->getRealPath($storageId);
                        if ($storagePath && is_dir($storagePath)) {
                            $fileManager->searchRecursivePublic($storagePath, $query, $storagePath, $realtimeResults, 200);
                        }
                    }
                    
                    foreach ($realtimeResults as $item) {
                        $item['storage_id'] = $storageId;
                        $item['storage_name'] = $sName;
                        $searchResults[] = $item;
                    }
                }
            } else {
                // 전체 스토리지 검색 (사용자 접근 가능한 스토리지만, 무제한)
                $userStorages = $storage->getStorages();
                
                // 스토리지 ID -> 이름 매핑 생성
                $storageNames = [];
                $allowedStorageIds = [];
                foreach ($userStorages as $category => $storageList) {
                    // getStorages()는 카테고리별로 반환 (home, public, shared)
                    if (is_array($storageList)) {
                        foreach ($storageList as $st) {
                            $sid = (int)($st['id'] ?? 0);
                            if ($sid > 0) {
                                $storageNames[$sid] = $st['name'] ?? '';
                                $allowedStorageIds[] = $sid;
                            }
                        }
                    }
                }
                
                // 허용된 스토리지에서만 검색 (FileIndex가 storage_id 반환)
                if (!empty($allowedStorageIds)) {
                    $results = $fileIndex->search($query, $allowedStorageIds, 0);
                    foreach ($results as $item) {
                        // FileIndex가 반환한 storage_id 사용
                        $sid = (int)($item['storage_id'] ?? 0);
                        $item['storage_name'] = $storageNames[$sid] ?? '';
                        $searchResults[] = $item;
                    }
                }
            }
            
            // 필터 적용
            if (!empty($fileType) && $fileType !== 'all') {
                $typeExtensions = [
                    'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'],
                    'video' => ['mp4', 'webm', 'avi', 'mkv', 'mov', 'wmv', 'flv'],
                    'audio' => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'],
                    'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt'],
                    'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2']
                ];
                
                if (isset($typeExtensions[$fileType])) {
                    $allowedExts = $typeExtensions[$fileType];
                    $searchResults = array_filter($searchResults, function($item) use ($allowedExts) {
                        $ext = strtolower(pathinfo($item['filepath'] ?? '', PATHINFO_EXTENSION));
                        return in_array($ext, $allowedExts);
                    });
                }
            }
            
            // 날짜 필터
            if (!empty($dateFrom)) {
                $fromTime = strtotime($dateFrom);
                $searchResults = array_filter($searchResults, function($item) use ($fromTime) {
                    return strtotime($item['modified'] ?? 0) >= $fromTime;
                });
            }
            if (!empty($dateTo)) {
                $toTime = strtotime($dateTo . ' 23:59:59');
                $searchResults = array_filter($searchResults, function($item) use ($toTime) {
                    return strtotime($item['modified'] ?? 0) <= $toTime;
                });
            }
            
            // 크기 필터
            if ($sizeMin > 0) {
                $searchResults = array_filter($searchResults, function($item) use ($sizeMin) {
                    return ($item['size'] ?? 0) >= $sizeMin;
                });
            }
            if ($sizeMax > 0) {
                $searchResults = array_filter($searchResults, function($item) use ($sizeMax) {
                    return ($item['size'] ?? 0) <= $sizeMax;
                });
            }
            
            // 경로 필터
            if (!empty($searchPath)) {
                $searchResults = array_filter($searchResults, function($item) use ($searchPath) {
                    return strpos($item['filepath'] ?? '', $searchPath) === 0;
                });
            }
            
            // 폴더별 권한 필터링 (검색 결과에서 접근 불가 폴더 제외 - 관리자 포함)
            $searchResults = array_filter($searchResults, function($item) use ($storage) {
                $sid = (int)($item['storage_id'] ?? 0);
                $filepath = $item['filepath'] ?? '';
                $dirPath = dirname($filepath);
                if ($dirPath === '.' || $dirPath === '') return true;
                return $storage->checkFolderPermission($sid, $dirPath);
            });
            $searchResults = array_values($searchResults);
            
            // 정렬 적용
            $sortBy = $input['sort_by'] ?? 'name';
            $sortOrder = $input['sort_order'] ?? 'asc';
            
            usort($searchResults, function($a, $b) use ($sortBy, $sortOrder) {
                // 폴더 우선
                $aIsDir = $a['is_dir'] ?? 0;
                $bIsDir = $b['is_dir'] ?? 0;
                if ($aIsDir != $bIsDir) {
                    return $bIsDir - $aIsDir; // 폴더가 먼저
                }
                
                // 정렬 기준에 따라
                switch ($sortBy) {
                    case 'size':
                        $cmp = ($a['size'] ?? 0) - ($b['size'] ?? 0);
                        break;
                    case 'date':
                        $cmp = strtotime($a['modified'] ?? '0') - strtotime($b['modified'] ?? '0');
                        break;
                    case 'type':
                        $extA = strtolower(pathinfo($a['filepath'] ?? '', PATHINFO_EXTENSION));
                        $extB = strtolower(pathinfo($b['filepath'] ?? '', PATHINFO_EXTENSION));
                        $cmp = strcmp($extA, $extB);
                        break;
                    case 'name':
                    default:
                        $nameA = basename($a['filepath'] ?? '');
                        $nameB = basename($b['filepath'] ?? '');
                        $cmp = strcasecmp($nameA, $nameB);
                        break;
                }
                
                return $sortOrder === 'desc' ? -$cmp : $cmp;
            });
            
            // 페이지네이션 적용
            $searchResults = array_values($searchResults);
            $total = count($searchResults);
            $totalPages = ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
            $pagedResults = array_slice($searchResults, $offset, $perPage);
            
            $result = [
                'success' => true, 
                'results' => $pagedResults,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages
            ];
            
            // 실시간 검색 여부 표시
            if ($isRealtimeSearch) {
                $result['realtime'] = true;
            }
            
            // 전체 검색일 때: 인덱스 재구축을 한 번도 안 한 경우에만 unindexed 안내
            if ($storageId === 0 && !$fileIndex->getMeta('last_rebuild')) {
                $unindexed = [];
                if (!empty($allowedStorageIds)) {
                    if (!isset($indexedIds)) {
                        $indexedIds = $fileIndex->getIndexedStorageIds();
                    }
                    foreach ($allowedStorageIds as $sid) {
                        if (!in_array($sid, $indexedIds)) {
                            $unindexed[] = [
                                'id' => $sid,
                                'name' => $storageNames[$sid] ?? ''
                            ];
                        }
                    }
                }
                if (!empty($unindexed)) {
                    $result['unindexed_storages'] = $unindexed;
                }
            }
            break;
        
        // ========== 네트워크 공유 (SMB) ==========
        case 'search_realtime':
            // 인덱스 없는 스토리지에서 실시간 폴더 순회 검색
            $auth->requireLogin();
            session_write_close();
            @set_time_limit(120);
            @ini_set('memory_limit', '512M');
            try {
                $storageId = (int)($input['storage_id'] ?? 0);
                $query = trim($input['query'] ?? '');
                
                if (!$storageId || empty($query)) {
                    $result = ['success' => false, 'error' => __('api_err_invalid_param', '잘못된 요청입니다.')];
                    break;
                }
                
                if (!$storage->checkPermission($storageId, 'can_read')) {
                    $result = ['success' => false, 'error' => __('api_err_no_read_perm', '읽기 권한이 없습니다.')];
                    break;
                }
                
                $storageInfo = $storage->getStorageById($storageId);
                $sType = $storageInfo['storage_type'] ?? 'local';
                $remoteAdapterTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
                $results = [];
                $searchLimit = 200;
                
                if (in_array($sType, $remoteAdapterTypes)) {
                    $adapter = StorageAdapterFactory::create($storageInfo);
                    if (!$adapter->connect()) {
                        $result = ['success' => false, 'error' => $adapter->getLastError() ?: __('api_err_connect_failed', '연결 실패')];
                        break;
                    }
                    $fileManager->_searchStartTime = time();
                    $fileManager->searchRemoteRecursive($adapter, '', $query, $results, $searchLimit);
                    $adapter->disconnect();
                } else {
                    // 로컬/SMB: 폴더 순회
                    $storagePath = $storage->getRealPath($storageId);
                    if ($storagePath && is_dir($storagePath)) {
                        $fileManager->searchRecursivePublic($storagePath, $query, $storagePath, $results, $searchLimit);
                    }
                }
                
                foreach ($results as &$r) {
                    $r['storage_id'] = $storageId;
                    $r['storage_name'] = $storageInfo['name'] ?? '';
                }
                
                $result = ['success' => true, 'results' => $results, 'total' => count($results), 'realtime' => true];
            } catch (Throwable $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            }
            break;
        
        case 'smb_detect':
        case 'network_share_detect':
            $auth->requireAdminPerm('storages');
            
            session_write_close(); // 세션 락 해제
            $os = PHP_OS_FAMILY;
            $serverIp = $_SERVER['SERVER_ADDR'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
            
            $env = [
                'os' => $os,
                'is_docker' => false,
                'hostname' => @gethostname() ?: 'unknown',
                'ip' => $serverIp,
                'exec_enabled' => false,
                'smb_available' => ($os === 'Windows'),
                'existing_shares' => [],
                'storages' => [],
                'share_settings' => [],
            ];
            
            // exec check
            $disabledStr = @ini_get('disable_functions') ?: '';
            $disabledArr = array_map('trim', explode(',', $disabledStr));
            $canExec = function_exists('exec') && !in_array('exec', $disabledArr);
            $env['exec_enabled'] = $canExec;
            
            // Windows: net share (에러 핸들러 비활성)
            if ($canExec && $os === 'Windows') {
                $shareOutput = [];
                $rc = -1;
                set_error_handler(function() {}, E_ALL);
                exec('chcp 65001 >nul 2>&1 & net share 2>&1', $shareOutput, $rc);
                restore_error_handler();
                $shares = [];
                foreach ($shareOutput as $line) {
                    // CP949 → UTF-8 변환
                    if (!mb_check_encoding($line, 'UTF-8')) {
                        $line = @mb_convert_encoding($line, 'UTF-8', 'CP949');
                    }
                    $trimmed = trim($line);
                    if (preg_match('/^([A-Za-z0-9_\-]+)\s+([A-Z]:\\\\.*)$/i', $trimmed, $m)) {
                        $name = $m[1];
                        if (!preg_match('/^(IPC|ADMIN|[A-Z]|print)\$$/i', $name)) {
                            $shares[] = ['name' => $name, 'path' => trim($m[2])];
                        }
                    }
                }
                $env['existing_shares'] = $shares;
            }
            
            // Linux: Samba
            if ($canExec && $os === 'Linux') {
                $smbdOut = [];
                set_error_handler(function() {}, E_ALL);
                exec('which smbd 2>/dev/null', $smbdOut, $smbdRc);
                restore_error_handler();
                $env['smb_available'] = ($smbdRc === 0);
                if ($smbdRc === 0) {
                    $svcOut = [];
                    set_error_handler(function() {}, E_ALL);
                    exec('systemctl is-active smbd 2>/dev/null', $svcOut, $svcRc);
                    restore_error_handler();
                    $env['smb_running'] = ($svcRc === 0);
                    if (@is_readable('/etc/samba/smb.conf')) {
                        $conf = @file_get_contents('/etc/samba/smb.conf') ?: '';
                        $shares = [];
                        preg_match_all('/\[([^\]]+)\]\s*\n(?:[^\[]*?path\s*=\s*(.+))/m', $conf, $matches, PREG_SET_ORDER);
                        foreach ($matches as $m) {
                            $name = trim($m[1]);
                            if (!in_array(strtolower($name), ['global', 'homes', 'printers'])) {
                                $shares[] = ['name' => $name, 'path' => trim($m[2])];
                            }
                        }
                        $env['existing_shares'] = $shares;
                    }
                } else {
                    $env['smb_running'] = false;
                }
            }
            
            // 스토리지
            $storages = $db->load('storages');
            $storageList = [];
            if (is_array($storages)) {
                foreach ($storages as $sid => $s) {
                    $path = $s['path'] ?? '';
                    $name = $s['name'] ?? "Storage $sid";
                    // UTF-8 보장
                    if (!mb_check_encoding($path, 'UTF-8')) {
                        $path = @mb_convert_encoding($path, 'UTF-8', 'CP949');
                    }
                    if (!mb_check_encoding($name, 'UTF-8')) {
                        $name = @mb_convert_encoding($name, 'UTF-8', 'CP949');
                    }
                    $storageList[] = [
                        'id' => (string)$sid,
                        'name' => $name,
                        'path' => $path,
                        'exists' => !empty($path) && @is_dir($path),
                    ];
                }
            }
            $env['storages'] = $storageList;
            
            // 공유 설정
            $shareSettingsFile = DATA_PATH . '/network_share_settings.json';
            if (@file_exists($shareSettingsFile)) {
                $raw = @file_get_contents($shareSettingsFile);
                if ($raw) {
                    $decoded = @json_decode($raw, true);
                    if (is_array($decoded)) $env['share_settings'] = $decoded;
                }
            }
            
            $result = ['success' => true, 'env' => $env];
            break;
            
        case 'network_share_create':
            $auth->requireAdminPerm('storages');
            
            session_write_close(); // 세션 락 해제
            $storageId = $input['storage_id'] ?? '';
            $shareName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['share_name'] ?? '');
            $readOnly = !empty($input['read_only']);
            $guestAccess = !empty($input['guest_access']);
            
            if (!$storageId || !$shareName) {
                $result = ['success' => false, 'error' => 'Storage ID and share name required'];
                break;
            }
            
            $storages = $db->load('storages');
            $storageData = null;
            foreach ($storages as $s) {
                if (($s['id'] ?? 0) == $storageId) { $storageData = $s; break; }
            }
            if (!$storageData || empty($storageData['path']) || !is_dir($storageData['path'])) {
                $result = ['success' => false, 'error' => 'Invalid storage or path not found'];
                break;
            }
            
            $storagePath = realpath($storageData['path']);
            // SMB 설정 파일 인젝션 방지 (줄바꿈/제어문자 제거)
            $storagePath = preg_replace('/[\r\n\x00-\x1f]/', '', $storagePath);
            $os = PHP_OS_FAMILY;
            $output = [];
            $success = false;
            
            if ($os === 'Windows') {
                $perm = '';
                if ($guestAccess) $perm = $readOnly ? ' /GRANT:Everyone,READ' : ' /GRANT:Everyone,FULL';
                $cmd = 'net share ' . escapeshellarg($shareName . '=' . $storagePath) . $perm;
                
                // 설정 저장 (exec 성공 여부와 무관)
                $shareSettingsFile = DATA_PATH . '/network_share_settings.json';
                $shareSettings = @file_exists($shareSettingsFile) ? (@json_decode(@file_get_contents($shareSettingsFile), true) ?: []) : [];
                $shareSettings[$shareName] = [
                    'storage_id' => $storageId,
                    'share_name' => $shareName,
                    'path' => $storagePath,
                    'read_only' => $readOnly,
                    'guest_access' => $guestAccess,
                    'created' => date('Y-m-d H:i:s'),
                ];
                @file_put_contents($shareSettingsFile, json_encode($shareSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                
                // exec 시도
                $output = [];
                $rc = -1;
                set_error_handler(function() {}, E_ALL);
                @exec('chcp 65001 >nul 2>&1 & ' . $cmd . ' 2>&1', $output, $rc);
                restore_error_handler();
                
                if ($rc === 0) {
                    $result = ['success' => true];
                } else {
                    $result = ['success' => true, 'warning' => true, 'cmd' => $cmd,
                        'message' => '자동 생성 실패. 관리자 CMD에서 실행하세요: ' . $cmd];
                }
                break;
                
            } elseif ($os === 'Linux') {
                $confPath = '/etc/samba/smb.conf';
                if (!file_exists($confPath) || !is_writable($confPath)) {
                    $result = ['success' => false, 'error' => 'Cannot write to /etc/samba/smb.conf. Check permissions or install Samba.'];
                    break;
                }
                
                $conf = file_get_contents($confPath);
                if (preg_match('/\[' . preg_quote($shareName, '/') . '\]/i', $conf)) {
                    $result = ['success' => false, 'error' => "Share [{$shareName}] already exists"];
                    break;
                }
                
                $writable = $readOnly ? 'no' : 'yes';
                $guestOk = $guestAccess ? 'yes' : 'no';
                $browseable = 'yes';
                
                // valid users 설정 (게스트가 아닌 경우 FileStation 사용자만 허용)
                $validUsersLine = '';
                if (!$guestAccess) {
                    // 활성 사용자 목록 (smb.conf 안전 문자만 허용)
                    $users = $db->findAll('users', ['status' => 'active']);
                    $usernames = [];
                    foreach ($users as $u) {
                        if (preg_match('/^[a-zA-Z0-9_\-]+$/', $u['username'])) {
                            $usernames[] = $u['username'];
                        }
                    }
                    if (!empty($usernames)) {
                        $validUsersLine = "\n   valid users = " . implode(' ', $usernames);
                    }
                }
                
                $newSection = "\n\n# FileStation auto-generated share\n[{$shareName}]\n   path = \"" . $storagePath . "\"\n   browseable = {$browseable}\n   read only = " . ($readOnly ? 'yes' : 'no') . "\n   writable = {$writable}\n   guest ok = {$guestOk}{$validUsersLine}\n   create mask = 0664\n   directory mask = 0775\n   force group = www-data\n";
                
                if (!file_put_contents($confPath, $conf . $newSection)) {
                    $result = ['success' => false, 'error' => 'Failed to write smb.conf'];
                    break;
                }
                
                set_error_handler(function() {}, E_ALL);
                @exec('systemctl restart smbd 2>&1 || service smbd restart 2>&1', $output, $rc);
                restore_error_handler();
                $success = true;
            }
            
            if ($success) {
                $shareSettingsFile = DATA_PATH . '/network_share_settings.json';
                $shareSettings = @file_exists($shareSettingsFile) ? (@json_decode(@file_get_contents($shareSettingsFile), true) ?: []) : [];
                $shareSettings[$shareName] = [
                    'storage_id' => $storageId,
                    'share_name' => $shareName,
                    'path' => $storagePath,
                    'read_only' => $readOnly,
                    'guest_access' => $guestAccess,
                    'created' => date('Y-m-d H:i:s'),
                ];
                @file_put_contents($shareSettingsFile, json_encode($shareSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
            
            $result = ['success' => $success, 'output' => $output];
            break;
            
        case 'network_share_remove':
            $auth->requireAdminPerm('storages');
            
            session_write_close(); // 세션 락 해제
            $shareName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['share_name'] ?? '');
            if (!$shareName) {
                $result = ['success' => false, 'error' => 'Share name required'];
                break;
            }
            
            $os = PHP_OS_FAMILY;
            $success = false;
            $output = [];
            
            if ($os === 'Windows') {
                // 설정 제거
                $shareSettingsFile = DATA_PATH . '/network_share_settings.json';
                if (@file_exists($shareSettingsFile)) {
                    $shareSettings = @json_decode(@file_get_contents($shareSettingsFile), true) ?: [];
                    unset($shareSettings[$shareName]);
                    @file_put_contents($shareSettingsFile, json_encode($shareSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                }
                
                // exec 시도
                $cmd = 'net share ' . escapeshellarg($shareName) . ' /DELETE /Y';
                $output = [];
                $rc = -1;
                set_error_handler(function() {}, E_ALL);
                @exec('chcp 65001 >nul 2>&1 & ' . $cmd . ' 2>&1', $output, $rc);
                restore_error_handler();
                
                if ($rc === 0) {
                    $result = ['success' => true];
                } else {
                    $result = ['success' => true, 'warning' => true,
                        'message' => '자동 삭제 실패. 관리자 CMD에서 실행하세요: ' . $cmd];
                }
                break;
                
            } elseif ($os === 'Linux') {
                $confPath = '/etc/samba/smb.conf';
                if (!is_writable($confPath)) {
                    $result = ['success' => false, 'error' => 'Cannot write to /etc/samba/smb.conf'];
                    break;
                }
                
                $conf = file_get_contents($confPath);
                $pattern = '/\n*# FileStation auto-generated share\n\[' . preg_quote($shareName, '/') . '\][^\[]*/i';
                $newConf = preg_replace($pattern, '', $conf);
                if ($newConf === $conf) {
                    $pattern = '/\n*\[' . preg_quote($shareName, '/') . '\][^\[]*/i';
                    $newConf = preg_replace($pattern, '', $conf);
                }
                
                file_put_contents($confPath, $newConf);
                set_error_handler(function() {}, E_ALL);
                @exec('systemctl restart smbd 2>&1 || service smbd restart 2>&1', $output, $rc);
                restore_error_handler();
                $success = true;
            }
            
            if ($success) {
                $shareSettingsFile = DATA_PATH . '/network_share_settings.json';
                if (@file_exists($shareSettingsFile)) {
                    $shareSettings = @json_decode(@file_get_contents($shareSettingsFile), true) ?: [];
                    unset($shareSettings[$shareName]);
                    @file_put_contents($shareSettingsFile, json_encode($shareSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                }
            }
            
            $result = ['success' => $success, 'output' => $output];
            break;
            
        case 'smb_sync_users':
            $auth->requireAdminPerm('storages');
            
            session_write_close(); // 세션 락 해제
            if (PHP_OS_FAMILY !== 'Linux') {
                $result = ['success' => false, 'error' => 'Samba user sync is only supported on Linux'];
                break;
            }
            
            $disabledStr = @ini_get('disable_functions') ?: '';
            $disabledArr = array_map('trim', explode(',', $disabledStr));
            $canExec = function_exists('exec') && !in_array('exec', $disabledArr);
            if (!$canExec) {
                $result = ['success' => false, 'error' => 'exec() is disabled'];
                break;
            }
            
            // smbpasswd 확인
            $smbOut = [];
            set_error_handler(function() {}, E_ALL);
            @exec('which smbpasswd 2>/dev/null', $smbOut, $smbRc);
            restore_error_handler();
            if ($smbRc !== 0) {
                $result = ['success' => false, 'error' => 'smbpasswd not found. Install Samba first.'];
                break;
            }
            
            // 특정 사용자만 동기화 (선택)
            $targetUserId = $input['user_id'] ?? null;
            $targetPassword = $input['password'] ?? null;
            
            $users = $db->load('users');
            $synced = [];
            $errors = [];
            
            foreach ($users as $user) {
                if (($user['status'] ?? '') !== 'active') continue;
                if ($targetUserId && (int)$user['id'] !== (int)$targetUserId) continue;
                
                $username = $user['username'];
                // 안전한 사용자명만 허용
                if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
                    $errors[] = "{$username}: invalid characters in username";
                    continue;
                }
                
                // 리눅스 사용자 존재 확인 → 없으면 생성
                $idOut = [];
                set_error_handler(function() {}, E_ALL);
                @exec('id ' . escapeshellarg($username) . ' 2>/dev/null', $idOut, $idRc);
                restore_error_handler();
                
                if ($idRc !== 0) {
                    // 시스템 사용자 생성 (홈 디렉토리 없이, 로그인 불가)
                    $addOut = [];
                    set_error_handler(function() {}, E_ALL);
                    @exec('useradd -M -s /usr/sbin/nologin -g www-data ' . escapeshellarg($username) . ' 2>&1', $addOut, $addRc);
                    restore_error_handler();
                    if ($addRc !== 0) {
                        $errors[] = "{$username}: failed to create system user";
                        continue;
                    }
                }
                
                // Samba 비밀번호 설정
                $pass = $targetPassword ?: ($user['username'] . '1234'); // 기본 비밀번호
                $smbCmd = 'printf ' . escapeshellarg($pass . "\n" . $pass . "\n") . ' | smbpasswd -a -s ' . escapeshellarg($username) . ' 2>&1';
                $smbSetOut = [];
                set_error_handler(function() {}, E_ALL);
                @exec($smbCmd, $smbSetOut, $smbSetRc);
                restore_error_handler();
                
                if ($smbSetRc === 0) {
                    // Samba 사용자 활성화
                    set_error_handler(function() {}, E_ALL);
                    @exec('smbpasswd -e ' . escapeshellarg($username) . ' 2>&1', $enableOut, $enableRc);
                    restore_error_handler();
                    $synced[] = $username;
                } else {
                    $errors[] = "{$username}: smbpasswd failed";
                }
            }
            
            $result = [
                'success' => true,
                'synced' => $synced,
                'errors' => $errors,
                'message' => count($synced) . ' users synced' . (count($errors) ? ', ' . count($errors) . ' errors' : '')
            ];
            break;
            
        // ===== 작업 큐 시스템 =====
        
        case 'job_create':
            $auth->requireLogin();
            $user = $auth->getUser();
            $jobType = $input['type'] ?? '';
            $jobParams = $input['params'] ?? [];
            
            if (!in_array($jobType, ['copy', 'move', 'delete', 'restore', 'trash_empty', 'trash_delete'])) {
                $result = ['success' => false, 'error' => 'Invalid job type'];
                break;
            }
            
            $jobManager = JobManager::getInstance();
            $jobManager->setAuth($auth);
            $createResult = $jobManager->create($jobType, $jobParams, $user['id']);
            
            if (!$createResult['success']) {
                $result = $createResult;
                break;
            }
            
            $jobId = $createResult['job_id'];
            
            // 작업 등록만 하고 즉시 반환 (실행은 클라이언트가 job_execute 호출)
            session_write_close();
            
            $result = ['success' => true, 'job_id' => $createResult['job_id']];
            break;
        
        case 'job_execute':
            $auth->requireLogin();
            $user = $auth->getUser();
            $jobId = $input['id'] ?? '';
            
            $jobManager = JobManager::getInstance();
            $jobManager->setAuth($auth);
            
            // 본인 작업만 실행 가능
            $jobCheck = $jobManager->getStatus($jobId);
            if (!$jobCheck || ($jobCheck['user_id'] ?? 0) !== $user['id']) {
                $result = ['success' => false, 'error' => 'Job not found'];
                break;
            }
            
            // 동시 실행 중인 작업 수 체크 (running 상태)
            $activeJobs = $jobManager->listJobs($user['id'], true);
            $runningCount = 0;
            foreach ($activeJobs as $aj) {
                if (($aj['status'] ?? '') === 'running' && $aj['id'] !== $jobId) {
                    $runningCount++;
                }
            }
            $appSettings = $db->load('settings');
            $maxRunning = (int)($appSettings['max_running_jobs'] ?? 2);
            if ($runningCount >= $maxRunning) {
                // 대기 상태로 두고 응답 (폴링에서 재시도)
                $result = ['success' => true, 'queued' => true];
                break;
            }
            
            // 응답을 먼저 보내고 작업 실행
            session_write_close();
            ignore_user_abort(true);
            @set_time_limit(0);
            
            $response = json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            if (function_exists('fastcgi_finish_request')) {
                echo $response;
                fastcgi_finish_request();
            } else {
                while (ob_get_level() > 0) ob_end_clean();
                header('Content-Type: application/json; charset=utf-8');
                header('Connection: close');
                header('Content-Length: ' . strlen($response));
                echo $response;
                flush();
            }
            
            $jobManager->execute($jobId);
            exit;
        
        case 'job_status':
            $auth->requireLogin();
            session_write_close();
            $user = $auth->getUser();
            $jobId = $input['id'] ?? ($_GET['id'] ?? '');
            
            $jobManager = JobManager::getInstance();
            $job = $jobManager->getStatus($jobId);
            
            if (!$job || ($job['user_id'] ?? 0) !== $user['id']) {
                $result = ['success' => false, 'error' => 'Job not found'];
            } else {
                $result = ['success' => true, 'job' => $job];
            }
            break;
        
        case 'job_cancel':
            $auth->requireLogin();
            $user = $auth->getUser();
            $jobId = $input['id'] ?? '';
            
            $jobManager = JobManager::getInstance();
            $result = $jobManager->cancel($jobId, $user['id']);
            break;
        
        case 'job_list':
            $auth->requireLogin();
            session_write_close();
            $user = $auth->getUser();
            
            $jobManager = JobManager::getInstance();
            // 오래된 작업 정리도 함께 실행
            $jobManager->cleanup();
            $jobs = $jobManager->listJobs($user['id']);
            
            $result = ['success' => true, 'jobs' => $jobs];
            break;
        
        // ============================
        // E2E 암호화 Vault API
        // ============================
        
        case 'vault_create':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            $folderName = $input['folder_name'] ?? '';
            $salt = $input['salt'] ?? '';
            $passwordValidator = $input['password_validator'] ?? '';
            
            // 스토리지 쓰기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $path, 'can_write')) {
                $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')];
                break;
            }
            
            if (empty($folderName) || empty($salt) || empty($passwordValidator)) {
                $result = ['success' => false, 'error' => __('vault_err_missing_params', '필수 파라미터가 누락되었습니다.')];
                break;
            }
            
            // 폴더명 검증
            if (preg_match('/[\/\\\\:*?"<>|]/', $folderName)) {
                $result = ['success' => false, 'error' => __('vault_err_invalid_name', '사용할 수 없는 폴더 이름입니다.')];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
                break;
            }
            $basePath = $_vp['basePath'];
            $fullPath = $_vp['vaultDir'];
            $vaultDir = $fullPath . DIRECTORY_SEPARATOR . $folderName;
            
            if (is_dir($vaultDir)) {
                $result = ['success' => false, 'error' => __('vault_err_already_exists', '이미 존재하는 폴더입니다.')];
                break;
            }
            
            if (!mkdir($vaultDir, 0755, true)) {
                $result = ['success' => false, 'error' => __('vault_err_create_failed', '폴더 생성에 실패했습니다.')];
                break;
            }
            
            // .vault.json 생성
            $vaultConfig = [
                'version' => 1,
                'salt' => $salt,
                'passwordValidator' => $passwordValidator,
                'created' => date('Y-m-d H:i:s'),
                'createdBy' => $auth->getUser()['id'] ?? 'unknown'
            ];
            
            $vaultJsonPath = $vaultDir . DIRECTORY_SEPARATOR . '.vault.json';
            if (!file_put_contents($vaultJsonPath, json_encode($vaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                // 롤백: 폴더 삭제
                @rmdir($vaultDir);
                $result = ['success' => false, 'error' => __('vault_err_config_failed', '설정 파일 생성에 실패했습니다.')];
                break;
            }
            
            $result = ['success' => true, 'message' => __('vault_created', '암호화 폴더가 생성되었습니다.')];
            break;
        
        case 'vault_info':
            $auth->requireLogin();
            session_write_close();
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            
            // 폴더 읽기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $path, 'can_read')) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $fullPath = $_vp['vaultDir'];
            $vaultJsonPath = $fullPath . DIRECTORY_SEPARATOR . '.vault.json';
            
            if (!file_exists($vaultJsonPath)) {
                $result = ['success' => true, 'is_vault' => false];
                break;
            }
            
            $vaultConfig = @json_decode(@file_get_contents($vaultJsonPath), true);
            if (!is_array($vaultConfig)) {
                $result = ['success' => false, 'error' => __('vault_err_config_corrupted', '설정 파일이 손상되었습니다.')];
                break;
            }
            
            $result = [
                'success' => true,
                'is_vault' => true,
                'salt' => $vaultConfig['salt'] ?? '',
                'passwordValidator' => $vaultConfig['passwordValidator'] ?? ''
            ];
            break;
        
        case 'vault_init':
            // 기존 폴더에 .vault.json 생성 (하위 vault 자동 생성용)
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $storageId = (int)($input['storage_id'] ?? 0);
            
            // 스토리지 쓰기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $input['path'] ?? '', 'can_write')) {
                $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')];
                break;
            }
            $path = $input['path'] ?? '';
            $salt = $input['salt'] ?? '';
            $passwordValidator = $input['passwordValidator'] ?? '';
            
            if (empty($salt) || empty($passwordValidator)) {
                $result = ['success' => false, 'error' => __('api_err_missing_params', '필수 매개변수가 누락되었습니다.')];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $fullPath = $_vp['vaultDir'];
            
            if (!is_dir($fullPath)) {
                $result = ['success' => false, 'error' => 'Directory not found'];
                break;
            }
            
            $vaultJsonPath = $fullPath . DIRECTORY_SEPARATOR . '.vault.json';
            if (file_exists($vaultJsonPath)) {
                $result = ['success' => true, 'message' => 'Already a vault'];
                break;
            }
            
            $vaultConfig = [
                'version' => 1,
                'salt' => $salt,
                'passwordValidator' => $passwordValidator,
                'created' => date('Y-m-d H:i:s'),
                'createdBy' => $auth->getUser()['id'] ?? 'unknown'
            ];
            
            if (file_put_contents($vaultJsonPath, json_encode($vaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                $result = ['success' => true];
            } else {
                $result = ['success' => false, 'error' => 'Failed to create vault config'];
            }
            break;
        
        case 'vault_upload':
            $auth->requireLogin();
            // 스토리지 권한 체크
            { $__sid = (int)($input['storage_id'] ?? $_POST['storage_id'] ?? $_GET['storage_id'] ?? 0);
              $__path = $input['path'] ?? $_POST['path'] ?? $_GET['path'] ?? '';
              if (!$storage->checkFolderPermission($__sid, $__path, 'can_write')) {
                  $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')];
                  break;
              }
            }
            session_write_close();
            $storageId = (int)($_POST['storage_id'] ?? 0);
            $path = $_POST['path'] ?? '';
            $uuid = $_POST['uuid'] ?? '';
            $encMeta = $_POST['enc_meta'] ?? '';       // 첫 요청 또는 단일 모드에서만
            $chunkIndex = $_POST['chunk_index'] ?? null; // null=단일, 숫자=청크
            $totalChunks = (int)($_POST['total_chunks'] ?? 0);
            
            if (empty($uuid)) {
                $result = ['success' => false, 'error' => __('vault_err_missing_params')];
                break;
            }
            
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
                $result = ['success' => false, 'error' => __('vault_err_invalid_uuid', '잘못된 UUID입니다.')];
                break;
            }
            
            if (empty($_FILES['enc_file']) || $_FILES['enc_file']['error'] !== UPLOAD_ERR_OK) {
                $result = ['success' => false, 'error' => __('api_err_no_file', '파일이 없습니다.')];
                break;
            }
            
            // 쿼터 사전 검증
            $fileSize = (int)($_FILES['enc_file']['size'] ?? 0);
            $quotaCheck = $fileManager->checkQuotaPublic($storageId, $fileSize);
            if (!$quotaCheck['allowed']) {
                $result = ['success' => false, 'error' => $quotaCheck['error']];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $vaultDir = $_vp['vaultDir'];
            
            if (!file_exists($vaultDir . DIRECTORY_SEPARATOR . '.vault.json')) {
                $result = ['success' => false, 'error' => __('vault_err_not_vault', '암호화 폴더가 아닙니다.')];
                break;
            }
            
            if ($chunkIndex !== null && $chunkIndex !== '') {
                // === 청크 모드 ===
                $chunkIdx = (int)$chunkIndex;
                $isLast = ($_POST['is_last'] ?? '0') === '1';
                
                // 청크는 uuid 서브폴더에 저장
                $chunkDir = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.chunks';
                if (!is_dir($chunkDir)) {
                    @mkdir($chunkDir, 0755, true);
                }
                $chunkFilePath = $chunkDir . DIRECTORY_SEPARATOR . $chunkIdx;
                
                if (!move_uploaded_file($_FILES['enc_file']['tmp_name'], $chunkFilePath)) {
                    $result = ['success' => false, 'error' => __('vault_err_upload_failed')];
                    break;
                }
                
                $chunkSize = filesize($chunkFilePath);
                $storage->updateUsedSize($storageId, $chunkSize);
                
                // 메타데이터는 첫 청크에서 저장
                if (!empty($encMeta)) {
                    $encMetaPath = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.meta.enc';
                    file_put_contents($encMetaPath, $encMeta, LOCK_EX);
                }
                
                $result = ['success' => true, 'uuid' => $uuid, 'chunk_index' => $chunkIdx, 'chunk_size' => $chunkSize];
                
                if ($isLast) {
                    // 청크 업로드 완료 — .chunks/ 폴더 그대로 유지 (병합하지 않음)
                    // 병합 시 concat 형식이 되어 복호화 호환성 문제 발생
                    $result['total_chunks'] = $chunkIdx + 1;
                    $result['complete'] = true;
                }
            } else {
                // === 단일 모드 (하위 호환) ===
                if (empty($encMeta)) {
                    $result = ['success' => false, 'error' => __('vault_err_missing_params')];
                    break;
                }
                
                $encFilePath = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.enc';
                $encMetaPath = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.meta.enc';
                
                if (file_exists($encFilePath)) {
                    $result = ['success' => false, 'error' => __('vault_err_file_exists', '파일이 이미 존재합니다.')];
                    break;
                }
                
                if (!move_uploaded_file($_FILES['enc_file']['tmp_name'], $encFilePath)) {
                    $result = ['success' => false, 'error' => __('vault_err_upload_failed', '파일 저장에 실패했습니다.')];
                    break;
                }
                
                if (!file_put_contents($encMetaPath, $encMeta, LOCK_EX)) {
                    @unlink($encFilePath);
                    $result = ['success' => false, 'error' => __('vault_err_meta_failed', '메타데이터 저장에 실패했습니다.')];
                    break;
                }
                
                $encSize = filesize($encFilePath) + strlen($encMeta);
                $storage->updateUsedSize($storageId, $encSize);
                
                $result = ['success' => true, 'uuid' => $uuid, 'enc_size' => filesize($encFilePath)];
            }
            break;
        
        case 'vault_list':
            $auth->requireLogin();
            session_write_close();
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            
            // 폴더 읽기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $path, 'can_read')) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $vaultDir = $_vp['vaultDir'];
            $relative = trim($path, '/\\');
            
            if (!file_exists($vaultDir . DIRECTORY_SEPARATOR . '.vault.json')) {
                $result = ['success' => false, 'error' => __('vault_err_not_vault')];
                break;
            }
            
            $files = [];
            $plainFiles = [];
            $chunkedUuids = []; // UUID => { totalSize, modified, chunks }
            $pendingEncFiles = []; // .enc 파일 임시 저장 (chunkedUuids 확인 후 추가)
            $iterator = new DirectoryIterator($vaultDir);
            foreach ($iterator as $file) {
                if ($file->isDot()) continue;
                $name = $file->getFilename();
                if (substr($name, 0, 1) === '.') continue;
                
                // uuid.chunks 서브폴더 (암호화 청크 또는 미리보기 임시)
                if ($file->isDir() && preg_match('/^([0-9a-f\-]{36})\.chunks$/', $name, $m)) {
                    $uuid = $m[1];
                    // .meta.enc가 없으면 미리보기 임시 폴더 → 건너뛰기
                    $metaCheck = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.meta.enc';
                    if (!file_exists($metaCheck)) {
                        continue;
                    }
                    $chunkDir = $file->getPathname();
                    $totalSize = 0;
                    $chunkCount = 0;
                    $latestMtime = 0;
                    $chunkIter = new DirectoryIterator($chunkDir);
                    foreach ($chunkIter as $cf) {
                        if ($cf->isDot() || $cf->isDir()) continue;
                        $totalSize += $cf->getSize();
                        $chunkCount++;
                        if ($cf->getMTime() > $latestMtime) $latestMtime = $cf->getMTime();
                    }
                    if ($chunkCount > 0) {
                        $chunkedUuids[$uuid] = ['totalSize' => $totalSize, 'modified' => $latestMtime, 'chunks' => $chunkCount];
                    }
                    continue;
                }
                
                if ($file->isDir()) {
                    // 하위 vault 폴더 (.vault.json이 있는 폴더)
                    $subVaultJson = $file->getPathname() . DIRECTORY_SEPARATOR . '.vault.json';
                    $isSubVault = file_exists($subVaultJson);
                    $plainFiles[] = [
                        'name' => $name,
                        'size' => 0,
                        'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                        'extension' => '',
                        'path' => $relative ? $relative . '/' . $name : $name,
                        'is_dir' => true,
                        'is_vault' => $isSubVault
                    ];
                    continue;
                }
                
                // 단일 .enc 파일 — 임시 저장 (루프 후 chunkedUuids와 비교)
                if (preg_match('/^([0-9a-f\-]{36})\.enc$/', $name, $m)) {
                    $uuid = $m[1];
                    $pendingEncFiles[$uuid] = [
                        'size' => $file->getSize(),
                        'modified' => date('Y-m-d H:i:s', $file->getMTime())
                    ];
                }
                // 레거시 청크 파일 (.enc.0, .enc.1, ...)
                else if (preg_match('/^([0-9a-f\-]{36})\.enc\.(\d+)$/', $name, $m)) {
                    $uuid = $m[1];
                    if (!isset($chunkedUuids[$uuid])) {
                        $chunkedUuids[$uuid] = ['totalSize' => 0, 'modified' => 0, 'chunks' => 0];
                    }
                    $chunkedUuids[$uuid]['totalSize'] += $file->getSize();
                    $chunkedUuids[$uuid]['chunks']++;
                    $mtime = $file->getMTime();
                    if ($mtime > $chunkedUuids[$uuid]['modified']) {
                        $chunkedUuids[$uuid]['modified'] = $mtime;
                    }
                }
                // .meta.enc는 건너뛰기
                else if (str_ends_with($name, '.meta.enc')) {
                    continue;
                }
                // 일반 파일 (암호화되지 않은 파일)
                else {
                    $plainFiles[] = [
                        'name' => $name,
                        'size' => $file->getSize(),
                        'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                        'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                        'path' => $relative ? $relative . '/' . $name : $name
                    ];
                }
            }
            
            // 단일 .enc 파일 처리 (청크 버전이 있으면 스킵)
            foreach ($pendingEncFiles as $uuid => $encInfo) {
                if (isset($chunkedUuids[$uuid])) continue; // 청크 모드 우선
                $metaPath = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.meta.enc';
                $encMeta = '';
                if (file_exists($metaPath)) {
                    $encMeta = file_get_contents($metaPath);
                }
                $files[] = [
                    'uuid' => $uuid,
                    'enc_meta' => $encMeta,
                    'enc_size' => $encInfo['size'],
                    'modified' => $encInfo['modified'],
                    'chunked' => false,
                    'chunk_count' => 0
                ];
            }
            
            // 청크 파일들을 files에 추가
            foreach ($chunkedUuids as $uuid => $info) {
                $metaPath = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.meta.enc';
                $encMeta = '';
                if (file_exists($metaPath)) {
                    $encMeta = file_get_contents($metaPath);
                }
                $files[] = [
                    'uuid' => $uuid,
                    'enc_meta' => $encMeta,
                    'enc_size' => $info['totalSize'],
                    'modified' => date('Y-m-d H:i:s', $info['modified']),
                    'chunked' => true,
                    'chunk_count' => $info['chunks']
                ];
            }
            
            $result = [
                'success' => true,
                'is_vault' => true,
                'path' => $path,
                'files' => $files,
                'plain_files' => $plainFiles,
                'breadcrumb' => $fileManager->getBreadcrumb($path)
            ];
            break;
        
        case 'vault_download':
            $auth->requireLogin();
            session_write_close();
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            $uuid = $_GET['uuid'] ?? '';
            $chunkIndex = $_GET['chunk_index'] ?? null;
            
            // 폴더 읽기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $path, 'can_read')) {
                http_response_code(403);
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                break;
            }
            
            if (empty($uuid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
                http_response_code(400);
                $result = ['success' => false, 'error' => 'Invalid UUID'];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $vaultDir = $_vp['vaultDir'];
            
            // 청크 모드 또는 단일 모드
            if ($chunkIndex !== null && $chunkIndex !== '') {
                $ci = (int)$chunkIndex;
                // 새 형식: uuid.chunks/N
                $chunkDir = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.chunks';
                $encFilePath = $chunkDir . DIRECTORY_SEPARATOR . $ci;
                // 레거시 폴백: uuid.enc.N
                if (!file_exists($encFilePath)) {
                    $encFilePath = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.enc.' . $ci;
                }
                $dlName = $uuid . '.chunk.' . $ci;
            } else {
                $encFilePath = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.enc';
                $dlName = $uuid . '.enc';
            }
            
            if (!file_exists($encFilePath)) {
                http_response_code(404);
                $result = ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')];
                break;
            }
            
            $size = filesize($encFilePath);
            
            // 출력 버퍼 비우기 (대용량 파일 메모리 초과 방지)
            while (ob_get_level()) ob_end_clean();
            
            // Range 요청 지원 (대용량 파일 분할 다운로드)
            $rangeStart = 0;
            $rangeEnd = $size - 1;
            $isRange = false;
            
            if (isset($_SERVER['HTTP_RANGE'])) {
                if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $rm)) {
                    $rangeStart = (int)$rm[1];
                    $rangeEnd = !empty($rm[2]) ? (int)$rm[2] : $size - 1;
                    if ($rangeStart > $rangeEnd || $rangeStart >= $size) {
                        http_response_code(416);
                        header("Content-Range: bytes */$size");
                        exit;
                    }
                    $rangeEnd = min($rangeEnd, $size - 1);
                    $isRange = true;
                }
            }
            
            $length = $rangeEnd - $rangeStart + 1;
            
            if ($isRange) {
                http_response_code(206);
                header("Content-Range: bytes $rangeStart-$rangeEnd/$size");
            }
            
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . $length);
            header("Content-Disposition: attachment; filename=\"{$dlName}\"; filename*=UTF-8''" . rawurlencode($dlName));
            header('Accept-Ranges: bytes');
            header('Cache-Control: no-cache');
            
            // 스트리밍 (메모리 무관)
            $fp = fopen($encFilePath, 'rb');
            if ($fp) {
                if ($rangeStart > 0) fseek($fp, $rangeStart);
                $remaining = $length;
                while ($remaining > 0 && !feof($fp)) {
                    $readSize = min(8192, $remaining);
                    echo fread($fp, $readSize);
                    flush();
                    $remaining -= $readSize;
                }
                fclose($fp);
            }
            exit;
        
        case 'vault_rename':
            // vault 파일 이름변경 (메타만 재암호화)
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            
            // 스토리지 쓰기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $input['path'] ?? '', 'can_write')) {
                $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')];
                break;
            }
            $path = $input['path'] ?? '';
            $uuid = $input['uuid'] ?? '';
            $encMeta = $input['enc_meta'] ?? '';
            
            if (empty($uuid) || empty($encMeta)) {
                $result = ['success' => false, 'error' => __('api_err_missing_params', '필수 매개변수가 누락되었습니다.')];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $vaultDir = $_vp['vaultDir'];
            $metaPath = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.meta.enc';
            
            if (!file_exists($metaPath)) {
                $result = ['success' => false, 'error' => 'Meta file not found'];
                break;
            }
            
            if (file_put_contents($metaPath, $encMeta, LOCK_EX) !== false) {
                $result = ['success' => true];
            } else {
                $result = ['success' => false, 'error' => 'Failed to write meta'];
            }
            break;
        
        case 'vault_delete':
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            // 스토리지 권한 체크
            { $__sid = (int)($input['storage_id'] ?? $_POST['storage_id'] ?? $_GET['storage_id'] ?? 0);
              $__path = $input['path'] ?? $_POST['path'] ?? $_GET['path'] ?? '';
              if (!$storage->checkFolderPermission($__sid, $__path, 'can_write')) {
                  $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')];
                  break;
              }
            }
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            $uuid = $input['uuid'] ?? '';
            
            if (empty($uuid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
                $result = ['success' => false, 'error' => 'Invalid UUID'];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $vaultDir = $_vp['vaultDir'];
            
            if (!file_exists($vaultDir . DIRECTORY_SEPARATOR . '.vault.json')) {
                $result = ['success' => false, 'error' => __('vault_err_not_vault')];
                break;
            }
            
            $encFilePath = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.enc';
            $encMetaPath = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.meta.enc';
            
            $deletedSize = 0;
            // 단일 .enc 삭제
            if (file_exists($encFilePath)) {
                $deletedSize += filesize($encFilePath);
                @unlink($encFilePath);
            }
            // 새 형식: uuid.chunks 폴더 삭제
            $chunkDir = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.chunks';
            if (is_dir($chunkDir)) {
                $chunkIter = new DirectoryIterator($chunkDir);
                foreach ($chunkIter as $cf) {
                    if ($cf->isDot()) continue;
                    $deletedSize += $cf->getSize();
                    @unlink($cf->getPathname());
                }
                @rmdir($chunkDir);
            }
            // 레거시: .enc.N 삭제
            $chunkIdx = 0;
            while (file_exists($vaultDir . DIRECTORY_SEPARATOR . $uuid . '.enc.' . $chunkIdx)) {
                $chunkPath = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.enc.' . $chunkIdx;
                $deletedSize += filesize($chunkPath);
                @unlink($chunkPath);
                $chunkIdx++;
            }
            // 메타 삭제
            if (file_exists($encMetaPath)) {
                $deletedSize += filesize($encMetaPath);
                @unlink($encMetaPath);
            }
            
            if ($deletedSize > 0) {
                $storage->updateUsedSize($storageId, -$deletedSize);
            }
            
            $result = ['success' => true, 'message' => __('vault_file_deleted', '파일이 삭제되었습니다.')];
            break;
        
        case 'vault_convert_init':
            // 기존 폴더를 vault로 변환 시작: .vault.json 생성 + 파일 목록 반환
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $storageId = (int)($input['storage_id'] ?? 0);
            
            // 스토리지 쓰기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $input['path'] ?? '', 'can_write')) {
                $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')];
                break;
            }
            $path = $input['path'] ?? '';
            $salt = $input['salt'] ?? '';
            $passwordValidator = $input['password_validator'] ?? '';
            
            if (empty($path) || empty($salt) || empty($passwordValidator)) {
                $result = ['success' => false, 'error' => __('vault_err_missing_params', '필수 파라미터가 누락되었습니다.')];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $fullPath = $_vp['vaultDir'];
            
            if (!is_dir($fullPath)) {
                $result = ['success' => false, 'error' => __('api_err_folder_not_found', '폴더를 찾을 수 없습니다.')];
                break;
            }
            
            $vaultJsonPath = $fullPath . DIRECTORY_SEPARATOR . '.vault.json';
            if (file_exists($vaultJsonPath)) {
                $result = ['success' => false, 'error' => __('vault_err_already_vault', '이미 암호화 폴더입니다.')];
                break;
            }
            
            // 폴더 내 파일 목록 수집 (하위 폴더 포함 — 재귀)
            $files = [];
            $totalSize = 0;
            $baseDirLen = strlen($fullPath) + 1; // +1 for separator
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($rii as $file) {
                if ($file->isDir()) continue;
                $fname = $file->getFilename();
                if (substr($fname, 0, 1) === '.') continue;
                // 폴더 기준 상대 경로 계산
                $relPath = str_replace('\\', '/', substr($file->getPathname(), $baseDirLen));
                $files[] = [
                    'name' => $fname,
                    'size' => $file->getSize(),
                    'path' => $path ? $path . '/' . $relPath : $relPath,
                    'relative' => $relPath
                ];
                $totalSize += $file->getSize();
            }
            
            // 파일이 없어도 vault 변환 허용 (빈 폴더 → 암호화 폴더)
            
            // 하위 폴더를 sub-vault로 변환 (.vault.json 생성)
            $subDirs = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($subDirs as $subItem) {
                if (!$subItem->isDir()) continue;
                $subVaultJson = $subItem->getPathname() . DIRECTORY_SEPARATOR . '.vault.json';
                if (!file_exists($subVaultJson)) {
                    $subConfig = [
                        'version' => 1,
                        'salt' => $salt,
                        'passwordValidator' => $passwordValidator,
                        'created' => date('Y-m-d H:i:s'),
                        'createdBy' => $auth->getUser()['id'] ?? 'unknown',
                        'convertedFrom' => str_replace('\\', '/', substr($subItem->getPathname(), strlen($basePath) + 1))
                    ];
                    @file_put_contents($subVaultJson, json_encode($subConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
            
            // 루트 .vault.json 생성
            $vaultConfig = [
                'version' => 1,
                'salt' => $salt,
                'passwordValidator' => $passwordValidator,
                'created' => date('Y-m-d H:i:s'),
                'createdBy' => $auth->getUser()['id'] ?? 'unknown',
                'convertedFrom' => $path
            ];
            
            if (!file_put_contents($vaultJsonPath, json_encode($vaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                $result = ['success' => false, 'error' => __('vault_err_config_failed')];
                break;
            }
            
            $result = [
                'success' => true,
                'files' => $files,
                'total_size' => $totalSize,
                'vault_path' => $path
            ];
            break;
        
        case 'vault_convert_file':
            // 개별 파일 변환: 원본 다운로드용 (클라이언트가 암호화 후 vault_upload으로 재업로드)
            $auth->requireLogin();
            session_write_close();
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? ''; // 파일의 상대 경로
            
            if (empty($path)) {
                http_response_code(400);
                $result = ['success' => false, 'error' => 'Missing path'];
                break;
            }
            
            // 폴더별 읽기 권한 체크
            if (!$storage->checkFolderPermission($storageId, dirname($path), 'can_read')) {
                http_response_code(403);
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, dirname($path));
            if (!$_vp) {
                http_response_code(404);
                $result = ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
                break;
            }
            $basePath = $_vp['basePath'];
            $relative = trim($path, '/\\');
            $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            
            // 경로 안전성 검증 (path traversal 방지)
            $realBase = realpath($basePath);
            $realFull = realpath($fullPath);
            // 보안: isSubPath로 정확한 하위 경로 검증 (prefix 확장 공격 방어)
            if (!$realBase || !$realFull || !\isSubPath($realFull, $realBase)) {
                http_response_code(403);
                $result = ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
                break;
            }
            
            if (!file_exists($fullPath) || is_dir($fullPath)) {
                http_response_code(404);
                $result = ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')];
                break;
            }
            
            // 바이너리 스트리밍
            $size = filesize($fullPath);
            $filename = basename($fullPath);
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . $size);
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
            header('Cache-Control: no-cache');
            readfile($fullPath);
            exit;
        
        case 'vault_convert_delete_original':
            // 변환 완료 후 원본 파일 삭제
            $auth->requireLogin();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? ''; // 원본 파일 상대 경로
            
            // 스토리지 쓰기 권한 체크
            if (!$storage->checkPermission($storageId, 'can_write')) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
            }
            if (!$storage->checkFolderPermission($storageId, dirname($path), 'can_write')) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')]; break;
            }
            
            if (empty($path)) {
                $result = ['success' => false, 'error' => 'Missing path'];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, dirname($path));
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $relative = trim($path, '/\\');
            $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            
            if (!file_exists($fullPath) || is_dir($fullPath)) {
                $result = ['success' => true]; // 이미 없으면 성공 처리
                break;
            }
            
            $fileSize = filesize($fullPath);
            if (@unlink($fullPath)) {
                $result = ['success' => true, 'deleted_size' => $fileSize];
            } else {
                $result = ['success' => false, 'error' => __('err_delete_failed', '삭제에 실패했습니다.')];
            }
            break;
        
        case 'vault_decrypt_save':
            // 클라이언트가 복호화한 파일을 원본 이름으로 저장 + .enc/.meta.enc 삭제
            $auth->requireLogin();
            session_write_close();
            // 스토리지 쓰기 권한 체크
            { $__sid = (int)($input['storage_id'] ?? $_POST['storage_id'] ?? 0);
              $__path = $input['path'] ?? $_POST['path'] ?? '';
              if (!$storage->checkFolderPermission($__sid, $__path, 'can_write')) {
                  $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')];
                  break;
              }
            }
            $storageId = (int)($_POST['storage_id'] ?? 0);
            $path = $_POST['path'] ?? '';  // vault 폴더 경로
            $uuid = $_POST['uuid'] ?? '';
            $originalName = $_POST['original_name'] ?? '';
            
            if (empty($uuid) || empty($originalName)) {
                $result = ['success' => false, 'error' => __('vault_err_missing_params')];
                break;
            }
            
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
                $result = ['success' => false, 'error' => 'Invalid UUID'];
                break;
            }
            
            // 파일명 검증
            if (preg_match('/[\/\\\\:*?"<>|]/', $originalName) || strpos($originalName, '..') !== false) {
                $result = ['success' => false, 'error' => __('vault_err_invalid_name')];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $vaultDir = $_vp['vaultDir'];
            
            if (!file_exists($vaultDir . DIRECTORY_SEPARATOR . '.vault.json')) {
                $result = ['success' => false, 'error' => __('vault_err_not_vault')];
                break;
            }
            
            if (empty($_FILES['decrypted_file']) || $_FILES['decrypted_file']['error'] !== UPLOAD_ERR_OK) {
                $result = ['success' => false, 'error' => __('api_err_no_file')];
                break;
            }
            
            $destPath = $vaultDir . DIRECTORY_SEPARATOR . $originalName;
            // 동일 이름 존재 시 번호 추가
            if (file_exists($destPath)) {
                $info = pathinfo($originalName);
                $base = $info['filename'];
                $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
                $i = 1;
                while (file_exists($vaultDir . DIRECTORY_SEPARATOR . $base . " ($i)" . $ext)) $i++;
                $destPath = $vaultDir . DIRECTORY_SEPARATOR . $base . " ($i)" . $ext;
            }
            
            if (!move_uploaded_file($_FILES['decrypted_file']['tmp_name'], $destPath)) {
                $result = ['success' => false, 'error' => __('vault_err_upload_failed')];
                break;
            }
            
            // .enc 및 .meta.enc 삭제
            $encFile = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.enc';
            $metaFile = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.meta.enc';
            $freedSize = 0;
            if (file_exists($encFile)) { $freedSize += filesize($encFile); @unlink($encFile); }
            if (file_exists($metaFile)) { $freedSize += filesize($metaFile); @unlink($metaFile); }
            
            $result = ['success' => true, 'saved_as' => basename($destPath)];
            break;
        
        case 'vault_decrypt_save_chunk':
            // 청크 방식 복호화 파일 저장 (대용량 파일 413 에러 방지)
            $auth->requireLogin();
            session_write_close();
            $storageId = (int)($_POST['storage_id'] ?? 0);
            $path = $_POST['path'] ?? '';
            $uuid = $_POST['uuid'] ?? '';
            $originalName = $_POST['original_name'] ?? '';
            $chunkIndex = (int)($_POST['chunk_index'] ?? 0);
            $totalChunks = (int)($_POST['total_chunks'] ?? 1);
            
            // 스토리지 쓰기 권한 체크
            if (!$storage->checkPermission($storageId, 'can_write')) {
                $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')];
                break;
            }
            
            if (empty($uuid) || empty($originalName)) {
                $result = ['success' => false, 'error' => __('vault_err_missing_params')];
                break;
            }
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
                $result = ['success' => false, 'error' => 'Invalid UUID'];
                break;
            }
            if (preg_match('/[\\/\\\\:*?"<>|]/', $originalName) || strpos($originalName, '..') !== false) {
                $result = ['success' => false, 'error' => __('vault_err_invalid_name')];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $vaultDir = $_vp['vaultDir'];
            
            if (!file_exists($vaultDir . DIRECTORY_SEPARATOR . '.vault.json')) {
                $result = ['success' => false, 'error' => __('vault_err_not_vault')];
                break;
            }
            
            if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
                $result = ['success' => false, 'error' => __('api_err_no_file')];
                break;
            }
            
            // 임시 파일에 청크 추가
            $tmpFile = $vaultDir . DIRECTORY_SEPARATOR . '.decrypt_tmp_' . $uuid;
            $mode = ($chunkIndex === 0) ? 'wb' : 'ab';
            $fp = fopen($tmpFile, $mode);
            if (!$fp) {
                $result = ['success' => false, 'error' => 'Failed to open temp file'];
                break;
            }
            $chunkData = file_get_contents($_FILES['chunk']['tmp_name']);
            fwrite($fp, $chunkData);
            fclose($fp);
            
            // 마지막 청크: 임시 파일을 최종 파일명으로 이동 + 원본 .enc 삭제
            if ($chunkIndex >= $totalChunks - 1) {
                $destPath = $vaultDir . DIRECTORY_SEPARATOR . $originalName;
                if (file_exists($destPath)) {
                    $info = pathinfo($originalName);
                    $base = $info['filename'];
                    $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
                    $i = 1;
                    while (file_exists($vaultDir . DIRECTORY_SEPARATOR . $base . " ($i)" . $ext)) $i++;
                    $destPath = $vaultDir . DIRECTORY_SEPARATOR . $base . " ($i)" . $ext;
                }
                rename($tmpFile, $destPath);
                
                // .enc 및 .meta.enc + .chunks 삭제
                $encFile = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.enc';
                $metaFile = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.meta.enc';
                $chunksDir = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.chunks';
                if (file_exists($encFile)) @unlink($encFile);
                if (file_exists($metaFile)) @unlink($metaFile);
                if (is_dir($chunksDir)) {
                    $chunkFiles = glob($chunksDir . DIRECTORY_SEPARATOR . '*');
                    foreach ($chunkFiles as $cf) @unlink($cf);
                    @rmdir($chunksDir);
                }
                
                $result = ['success' => true, 'saved_as' => basename($destPath), 'completed' => true];
            } else {
                $result = ['success' => true, 'chunk_index' => $chunkIndex, 'completed' => false];
            }
            break;
        
        case 'vault_remove':
            // vault 폴더의 .vault.json 삭제 → 일반 폴더로 전환
            // (파일 복호화는 클라이언트가 vault_decrypt_save로 개별 처리한 후 호출)
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            // 스토리지 쓰기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $path, 'can_write')) {
                $result = ['success' => false, 'error' => __('no_write_permission', '쓰기 권한이 없습니다.')];
                break;
            }
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $vaultDir = $_vp['vaultDir'];
            $vaultJsonPath = $vaultDir . DIRECTORY_SEPARATOR . '.vault.json';
            
            if (!file_exists($vaultJsonPath)) {
                $result = ['success' => false, 'error' => __('vault_err_not_vault')];
                break;
            }
            
            // 아직 .enc 파일이 남아있는지 확인
            $remainingEnc = 0;
            $iter = new DirectoryIterator($vaultDir);
            foreach ($iter as $f) {
                if (!$f->isDot() && preg_match('/\.enc$/', $f->getFilename())) {
                    $remainingEnc++;
                }
            }
            
            if ($remainingEnc > 0) {
                $result = ['success' => false, 'error' => __('vault_remove_has_enc', '아직 암호화된 파일이 남아있습니다. 모든 파일을 복호화한 후 해제하세요.'), 'remaining' => $remainingEnc];
                break;
            }
            
            if (@unlink($vaultJsonPath)) {
                // recursive 옵션: 하위 sub-vault의 .vault.json도 삭제
                if (!empty($input['recursive'])) {
                    $rii = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($vaultDir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($rii as $subItem) {
                        if ($subItem->isDir()) {
                            $subVaultJson = $subItem->getPathname() . DIRECTORY_SEPARATOR . '.vault.json';
                            if (file_exists($subVaultJson)) {
                                @unlink($subVaultJson);
                            }
                        }
                    }
                }
                $result = ['success' => true, 'message' => __('vault_removed', '암호화가 해제되었습니다.')];
            } else {
                $result = ['success' => false, 'error' => __('vault_err_remove_failed', '암호화 해제에 실패했습니다.')];
            }
            break;
        
        case 'vault_server_copy':
            // 같은 키의 vault 간 .enc 파일을 서버에서 직접 복사/이동 (복호화-재암호화 불필요)
            $auth->requireLogin();
            session_write_close();
            $storageId = (int)($input['storage_id'] ?? 0);
            $srcPath   = $input['src_path'] ?? '';
            $destPath  = $input['dest_path'] ?? '';
            $uuid      = $input['uuid'] ?? '';
            $copyMode  = $input['mode'] ?? 'copy'; // 'copy' or 'cut'
            
            if (empty($srcPath) || empty($destPath) || empty($uuid)) {
                $result = ['success' => false, 'error' => __('vault_err_missing_params')];
                break;
            }
            
            // 같은 경로 복사 차단
            if ($srcPath === $destPath) {
                $result = ['success' => false, 'error' => 'Source and destination are the same'];
                break;
            }
            
            // UUID 형식 검증
            if (!preg_match('/^[0-9a-f\-]{36}$/', $uuid)) {
                $result = ['success' => false, 'error' => 'Invalid UUID'];
                break;
            }
            
            // 권한 체크
            if (!$storage->checkFolderPermission($storageId, $srcPath, 'can_read') ||
                !$storage->checkFolderPermission($storageId, $destPath, 'can_write')) {
                $result = ['success' => false, 'error' => __('no_permission_dot')];
                break;
            }
            
            // 경로 해석
            $_vpSrc  = resolveVaultPath($storage, $storageId, $srcPath);
            $_vpDest = resolveVaultPath($storage, $storageId, $destPath);
            if (!$_vpSrc || !$_vpDest) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $srcDir  = $_vpSrc['vaultDir'];
            $destDir = $_vpDest['vaultDir'];
            
            // vault 폴더인지 확인
            if (!file_exists($srcDir . DIRECTORY_SEPARATOR . '.vault.json') ||
                !file_exists($destDir . DIRECTORY_SEPARATOR . '.vault.json')) {
                $result = ['success' => false, 'error' => __('vault_err_not_vault')];
                break;
            }
            
            $copied = 0;
            $filesToDelete = []; // cut 모드: 복사 성공 후 원본 삭제
            
            // 1) uuid.enc (단일 암호화 파일)
            $encFile = $srcDir . DIRECTORY_SEPARATOR . $uuid . '.enc';
            if (file_exists($encFile)) {
                $destFile = $destDir . DIRECTORY_SEPARATOR . $uuid . '.enc';
                if (@copy($encFile, $destFile)) {
                    $copied++;
                    if ($copyMode === 'cut') $filesToDelete[] = $encFile;
                }
            }
            
            // 2) uuid.meta.enc (메타데이터)
            $metaFile = $srcDir . DIRECTORY_SEPARATOR . $uuid . '.meta.enc';
            if (file_exists($metaFile)) {
                $destMeta = $destDir . DIRECTORY_SEPARATOR . $uuid . '.meta.enc';
                if (@copy($metaFile, $destMeta)) {
                    $copied++;
                    if ($copyMode === 'cut') $filesToDelete[] = $metaFile;
                }
            }
            
            // 3) uuid.chunks/ (청크 폴더)
            $chunksDir = $srcDir . DIRECTORY_SEPARATOR . $uuid . '.chunks';
            if (is_dir($chunksDir)) {
                $destChunks = $destDir . DIRECTORY_SEPARATOR . $uuid . '.chunks';
                if (!is_dir($destChunks)) @mkdir($destChunks, 0755, true);
                $chunkCopied = true;
                $chunkIter = new DirectoryIterator($chunksDir);
                foreach ($chunkIter as $cf) {
                    if ($cf->isDot()) continue;
                    if (!@copy($cf->getPathname(), $destChunks . DIRECTORY_SEPARATOR . $cf->getFilename())) {
                        $chunkCopied = false;
                    }
                }
                if ($chunkCopied) {
                    $copied++;
                    if ($copyMode === 'cut') $filesToDelete[] = $chunksDir;
                }
            }
            
            // cut 모드: 모든 복사 성공 후 원본 삭제
            if ($copied > 0 && $copyMode === 'cut') {
                foreach ($filesToDelete as $delPath) {
                    if (is_dir($delPath)) {
                        // 청크 폴더 삭제
                        $di = new DirectoryIterator($delPath);
                        foreach ($di as $df) { if (!$df->isDot()) @unlink($df->getPathname()); }
                        @rmdir($delPath);
                    } else {
                        @unlink($delPath);
                    }
                }
            }
            
            if ($copied > 0) {
                $result = ['success' => true, 'copied' => $copied];
            } else {
                $result = ['success' => false, 'error' => 'No files found for UUID: ' . $uuid];
            }
            break;
        
        case 'vault_migrate_legacy':
            // 레거시 concat .enc 파일을 새 .chunks/ 형식으로 분할
            $auth->requireLogin();
            session_write_close();
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            $uuid = $input['uuid'] ?? '';
            $chunkSize = (int)($input['chunk_size'] ?? 5242880); // 기본 5MB
            
            if (empty($uuid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
                $result = ['success' => false, 'error' => 'Invalid UUID'];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $vaultDir = $_vp['vaultDir'];
            $encFile = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.enc';
            
            if (!file_exists($encFile)) {
                $result = ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')];
                break;
            }
            
            $fileSize = filesize($encFile);
            // 암호화된 청크 크기 = IV(12) + 원본 청크(chunkSize) + GCM tag(16)
            $encChunkSize = 12 + $chunkSize + 16;
            
            // 단일 모드 파일은 마이그레이션 불필요 (encChunkSize 이하)
            if ($fileSize <= $encChunkSize) {
                $result = ['success' => true, 'message' => 'File is already single-mode, no migration needed', 'migrated' => false];
                break;
            }
            
            // .chunks 디렉토리 생성
            $chunkDir = $vaultDir . DIRECTORY_SEPARATOR . $uuid . '.chunks';
            if (is_dir($chunkDir)) {
                $result = ['success' => false, 'error' => 'Chunks directory already exists'];
                break;
            }
            
            if (!@mkdir($chunkDir, 0755, true)) {
                $result = ['success' => false, 'error' => 'Failed to create chunks directory'];
                break;
            }
            
            // concat 파일을 encChunkSize 단위로 분할
            $fp = fopen($encFile, 'rb');
            if (!$fp) {
                @rmdir($chunkDir);
                $result = ['success' => false, 'error' => 'Failed to open encrypted file'];
                break;
            }
            
            $chunkIdx = 0;
            $totalWritten = 0;
            $migrationOk = true;
            
            while (!feof($fp)) {
                $data = fread($fp, $encChunkSize);
                if ($data === false || strlen($data) === 0) break;
                
                // 최소 크기 검증 (IV + GCM tag + 1byte)
                if (strlen($data) < 29) {
                    // 끝에 남은 잔여 바이트 — 무시
                    break;
                }
                
                $chunkPath = $chunkDir . DIRECTORY_SEPARATOR . $chunkIdx;
                if (file_put_contents($chunkPath, $data) === false) {
                    $migrationOk = false;
                    break;
                }
                
                $totalWritten += strlen($data);
                $chunkIdx++;
            }
            fclose($fp);
            
            if (!$migrationOk || $chunkIdx === 0) {
                // 실패 시 정리
                $iter = new DirectoryIterator($chunkDir);
                foreach ($iter as $f) {
                    if (!$f->isDot()) @unlink($f->getPathname());
                }
                @rmdir($chunkDir);
                $result = ['success' => false, 'error' => 'Migration failed during chunk split'];
                break;
            }
            
            // 원본 concat .enc 삭제
            if (!@unlink($encFile)) {
                // .enc 삭제 실패해도 .chunks는 유지 (수동 정리 필요)
                $result = ['success' => true, 'warning' => 'Chunks created but failed to delete original .enc', 'chunks' => $chunkIdx, 'migrated' => true];
                break;
            }
            
            $result = ['success' => true, 'migrated' => true, 'chunks' => $chunkIdx, 'original_size' => $fileSize];
            break;
        
        case 'vault_preview_temp':
            // 복호화 파일을 vault 폴더 내 uuid.chunks/ 에 저장
            $auth->requireLogin();
            session_write_close();
            ignore_user_abort(false); // 클라이언트 연결 끊기면 PHP도 중단
            $storageId = (int)($_POST['storage_id'] ?? 0);
            $path = $_POST['path'] ?? '';
            
            // 폴더 읽기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $path, 'can_read')) {
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                break;
            }
            
            $originalName = $_POST['original_name'] ?? '';
            $tempId = $_POST['temp_id'] ?? '';  // uuid (미리보기용 임시 ID)
            $chunkMode = $_POST['chunk_mode'] ?? '0';
            $chunkIndex = (int)($_POST['chunk_index'] ?? 0);
            $isLast = ($_POST['is_last'] ?? '0') === '1';
            
            if (empty($originalName) || empty($tempId)) {
                $result = ['success' => false, 'error' => __('api_err_missing_params', '필수 매개변수가 누락되었습니다.')];
                break;
            }
            
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tempId)) {
                $result = ['success' => false, 'error' => 'Invalid temp ID'];
                break;
            }
            
            if (empty($_FILES['decrypted_file']) || $_FILES['decrypted_file']['error'] !== UPLOAD_ERR_OK) {
                $result = ['success' => false, 'error' => __('api_err_no_file') . ' (err=' . ($_FILES['decrypted_file']['error'] ?? 'none') . ')'];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $vaultDir = $_vp['vaultDir'];
            
            // 임시 파일은 data/vault_temp/ 에 저장 (한글 경로 문제 회피)
            $tempBaseDir = DATA_PATH . DIRECTORY_SEPARATOR . 'vault_temp';
            if (!is_dir($tempBaseDir)) {
                $mkResult = mkdir($tempBaseDir, 0755, true);
                if (!$mkResult) {
                    $result = ['success' => false, 'error' => 'Failed to create vault_temp directory'];
                    break;
                }
            }
            // 서브 디렉토리 없이 tempId.ext 파일로 직접 저장
            $tempDir = $tempBaseDir; // serve/cleanup에서도 같은 경로 사용
            
            $safeName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $originalName);
            $safeName = basename($safeName); // 경로 탐색 방지
            if (strpos($safeName, '..') !== false || empty($safeName)) {
                $result = ['success' => false, 'error' => 'Invalid filename'];
                break;
            }
            // Windows 한글 파일명 호환: 실제 저장은 tempId + 확장자로 (serve에서도 동일)
            $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
            $storedName = $tempId . ($ext ? '.' . $ext : '');
            $tempFilePath = $tempDir . DIRECTORY_SEPARATOR . $storedName;
            
            if ($chunkMode === '1') {
                // 청크 모드: 파일에 append
                $tmpFile = $_FILES['decrypted_file']['tmp_name'];
                $fpIn = fopen($tmpFile, 'rb');
                $fpOut = fopen($tempFilePath, $chunkIndex === 0 ? 'wb' : 'ab');
                if ($fpIn && $fpOut) {
                    while (!feof($fpIn)) {
                        fwrite($fpOut, fread($fpIn, 8192));
                    }
                    fclose($fpIn);
                    fclose($fpOut);
                } else {
                    if ($fpIn) fclose($fpIn);
                    if ($fpOut) fclose($fpOut);
                    $result = ['success' => false, 'error' => 'Failed to append chunk'];
                    break;
                }
                
                $result = ['success' => true, 'chunk_index' => $chunkIndex];
                if ($isLast) {
                    $result['complete'] = true;
                    $result['size'] = filesize($tempFilePath);
                }
            } else {
                // 단일 모드
                $moveOk = move_uploaded_file($_FILES['decrypted_file']['tmp_name'], $tempFilePath);
                if (!$moveOk || !file_exists($tempFilePath)) {
                    $result = ['success' => false, 'error' => 'Failed to save temp file'];
                    break;
                }
                $result = ['success' => true, 'temp_id' => $tempId, 'name' => $safeName];
            }
            break;
        
        case 'vault_preview_serve':
            // vault 폴더 내 임시 복호화 파일 스트리밍
            $auth->requireLogin();
            session_write_close();
            $storageId = (int)($_GET['storage_id'] ?? 0);
            $path = $_GET['path'] ?? '';
            
            // 폴더 읽기 권한 체크
            if (!$storage->checkFolderPermission($storageId, $path, 'can_read')) {
                http_response_code(403);
                $result = ['success' => false, 'error' => __('no_permission_dot', '권한이 없습니다.')];
                break;
            }
            
            $tempId = $_GET['temp_id'] ?? '';
            $name = $_GET['name'] ?? '';
            
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tempId) || empty($name)) {
                http_response_code(400);
                $result = ['success' => false, 'error' => 'Invalid parameters'];
                break;
            }
            
            $_vp = resolveVaultPath($storage, $storageId, $path);
            if (!$_vp) {
                $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                break;
            }
            $basePath = $_vp['basePath'];
            $vaultDir = $_vp['vaultDir'];
            // 임시 파일은 data/vault_temp/ 에 직접 저장됨 (서브 디렉토리 없음)
            $tempDir = DATA_PATH . DIRECTORY_SEPARATOR . 'vault_temp';
            $safeName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $name);
            $safeName = basename($safeName); // 경로 탐색 방지
            if (strpos($safeName, '..') !== false || empty($safeName)) {
                http_response_code(400);
                $result = ['success' => false, 'error' => 'Invalid filename'];
                break;
            }
            // Windows 한글 파일명 호환: tempId + 확장자로 저장됨
            $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
            $storedName = $tempId . ($ext ? '.' . $ext : '');
            $filePath = $tempDir . DIRECTORY_SEPARATOR . $storedName;
            
            if (!file_exists($filePath)) {
                http_response_code(404);
                $result = ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')];
                break;
            }
            
            $size = filesize($filePath);
            $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
            $mimeTypes = [
                'mp4'=>'video/mp4','webm'=>'video/webm','ogg'=>'video/ogg','mov'=>'video/quicktime',
                'mkv'=>'video/x-matroska','avi'=>'video/x-msvideo',
                'mp3'=>'audio/mpeg','wav'=>'audio/wav','flac'=>'audio/flac','m4a'=>'audio/mp4','aac'=>'audio/aac',
                'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
                'webp'=>'image/webp','bmp'=>'image/bmp','svg'=>'image/svg+xml',
                'pdf'=>'application/pdf','txt'=>'text/plain','json'=>'application/json',
                // html/css/js는 safeMimeInline에 없으므로 자동으로 octet-stream 처리됨
            ];
            $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
            
            while (ob_get_level()) ob_end_clean();
            
            // Range 요청 지원 (비디오 시크 가능하게)
            $start = 0;
            $end = $size - 1;
            $httpCode = 200;
            
            if (isset($_SERVER['HTTP_RANGE'])) {
                if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
                    $start = (int)$m[1];
                    if (!empty($m[2])) $end = (int)$m[2];
                    $httpCode = 206;
                }
            }
            
            $length = $end - $start + 1;
            
            http_response_code($httpCode);
            // 안전한 MIME만 inline 허용, 나머지는 다운로드 강제 (XSS/렌더링 차단)
            $safeMimeInline = ['image/jpeg','image/png','image/gif','image/webp','image/bmp',
                'video/mp4','video/webm','video/ogg','video/quicktime','video/x-matroska','video/x-msvideo',
                'audio/mpeg','audio/wav','audio/flac','audio/mp4','audio/aac',
                'application/pdf','text/plain'];
            if (in_array($contentType, $safeMimeInline)) {
                header("Content-Disposition: inline; filename=\"{$safeName}\"");
                // MIME sniffing 방지 + text/plain에 sandbox CSP
                header("X-Content-Type-Options: nosniff");
                if ($contentType === 'text/plain') {
                    header("Content-Security-Policy: default-src 'none'; sandbox;");
                }
            } else {
                $contentType = 'application/octet-stream';
                header("Content-Security-Policy: default-src 'none'; sandbox;");
                header("Content-Disposition: attachment; filename=\"{$safeName}\"");
            }
            header("Content-Type: {$contentType}");
            header("Content-Length: {$length}");
            header("Accept-Ranges: bytes");
            header('Cache-Control: no-cache');
            
            if ($httpCode === 206) {
                header("Content-Range: bytes {$start}-{$end}/{$size}");
            }
            
            $fp = fopen($filePath, 'rb');
            if ($fp) {
                if ($start > 0) fseek($fp, $start);
                $remaining = $length;
                while ($remaining > 0 && !feof($fp)) {
                    $readSize = min(8192, $remaining);
                    echo fread($fp, $readSize);
                    $remaining -= $readSize;
                    flush();
                }
                fclose($fp);
            }
            exit;
        
        case 'vault_preview_touch':
            // 뷰어가 열려있는 동안 임시 파일 mtime 갱신 (서버 정리에서 제외)
            $auth->requireLogin();
            $tempId = $input['temp_id'] ?? '';
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tempId)) {
                $result = ['success' => true];
                break;
            }
            $tempBaseDir = DATA_PATH . DIRECTORY_SEPARATOR . 'vault_temp';
            $pattern = $tempBaseDir . DIRECTORY_SEPARATOR . $tempId . '.*';
            $matchedFiles = glob($pattern);
            if ($matchedFiles) {
                foreach ($matchedFiles as $f) {
                    @touch($f); // mtime 갱신
                }
            }
            $result = ['success' => true];
            break;
        
        case 'vault_preview_cleanup':
            // vault 폴더 내 임시 복호화 폴더(uuid.chunks) 삭제
            $auth->requireLogin();
            session_write_close(); // 세션 락 해제
            $storageId = (int)($input['storage_id'] ?? 0);
            $path = $input['path'] ?? '';
            $tempId = $input['temp_id'] ?? '';
            
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tempId)) {
                $result = ['success' => true, 'reason' => 'invalid_temp_id'];
                break;
            }
            
            
                $_vp = resolveVaultPath($storage, $storageId, $path);
                if (!$_vp) {
                    $result = ['success' => false, 'error' => __('api_err_storage_not_found')];
                    break;
                }
                // 임시 파일은 data/vault_temp/ 에 직접 저장됨
                $tempBaseDir = DATA_PATH . DIRECTORY_SEPARATOR . 'vault_temp';
                
                // tempId.* 파일 삭제
                $deletedFiles = 0;
                $pattern = $tempBaseDir . DIRECTORY_SEPARATOR . $tempId . '.*';
                $matchedFiles = glob($pattern);
                if ($matchedFiles) {
                    foreach ($matchedFiles as $f) {
                        if (@unlink($f)) $deletedFiles++;
                    }
                }
                $result = ['success' => true, 'deleted_files' => $deletedFiles, 'dir_removed' => true];
            break;
        
        // ===== 아이콘 관리 (관리자 전용) =====
        case 'icon_manager':
            if (!$auth->isAdmin() && !$auth->isAdminOrSubAdmin()) {
                http_response_code(403);
                $result = ['success' => false, 'error' => '관리자 권한이 필요합니다'];
                break;
            }
            
            require_once __DIR__ . '/api/IconManager.php';
            $im = new IconManager();
            $op = $_GET['op'] ?? $input['op'] ?? '';
            
            switch ($op) {
                case 'list':
                    // 전체 상태 조회
                    $result = ['success' => true, 'data' => $im->getAll()];
                    break;
                
                case 'upload':
                    // SVG 업로드 (라벨형 옵션 + 배경 제거 + 라벨 자동 교체 지원)
                    $name = $_POST['name'] ?? '';
                    $labelMode = !empty($_POST['label_mode']) && $_POST['label_mode'] !== '0' && $_POST['label_mode'] !== 'false';
                    $removeBg = !empty($_POST['remove_bg']) && $_POST['remove_bg'] !== '0' && $_POST['remove_bg'] !== 'false';
                    $labelText = isset($_POST['label_text']) ? (string)$_POST['label_text'] : '';
                    // label_auto: true면 라벨 감지 실패 시 오버레이 라벨 추가하지 않고 원본 그대로 (자동 모드)
                    $labelAuto = !empty($_POST['label_auto']) && $_POST['label_auto'] !== '0' && $_POST['label_auto'] !== 'false';
                    if (!isset($_FILES['svg'])) {
                        $result = ['success' => false, 'error' => 'SVG 파일을 첨부해주세요'];
                        break;
                    }
                    $result = $im->uploadIcon($name, $_FILES['svg'], $labelMode, $removeBg, $labelText, $labelAuto);
                    break;
                
                case 'delete_custom':
                    // 커스텀 아이콘 삭제
                    $name = $input['name'] ?? '';
                    $result = $im->deleteCustomIcon($name);
                    break;
                
                case 'clone_from_gallery':
                    // 갤러리 아이콘 복사 → 옵션(배경제거/라벨교체) 적용 → 새 custom 파일 생성
                    //   용도: 이미 라벨 박힌 갤러리 아이콘을 다른 확장자에 적용할 때 라벨 재교체
                    $sourceName = $input['source'] ?? '';
                    $newName    = $input['name']   ?? '';
                    $labelMode  = !empty($input['label_mode']) && $input['label_mode'] !== '0' && $input['label_mode'] !== 'false';
                    $removeBg   = !empty($input['remove_bg'])  && $input['remove_bg']  !== '0' && $input['remove_bg']  !== 'false';
                    $labelText  = isset($input['label_text']) ? (string)$input['label_text'] : '';
                    $labelAuto  = !empty($input['label_auto']) && $input['label_auto'] !== '0' && $input['label_auto'] !== 'false';
                    $result = $im->cloneFromGallery($sourceName, $newName, $labelMode, $removeBg, $labelText, $labelAuto);
                    break;
                
                case 'set_mapping':
                    // 확장자 → 아이콘 매핑
                    //   label_mode: true면 매핑값에 '|label' 서픽스 부여 → 렌더 시 CSS 오버레이
                    $ext = $input['ext'] ?? '';
                    $iconName = $input['icon'] ?? '';
                    $labelMode = !empty($input['label_mode']) 
                                 && $input['label_mode'] !== '0' 
                                 && $input['label_mode'] !== 'false';
                    $result = $im->setMapping($ext, $iconName, $labelMode);
                    break;
                
                case 'remove_mapping':
                    // 매핑 제거 (기본값으로 복원)
                    $ext = $input['ext'] ?? '';
                    $result = $im->removeMapping($ext);
                    break;
                
                case 'set_label_position':
                    // CSS 오버레이 라벨 위치 저장 (9개 위치 중 하나)
                    //   적용 대상: fs-archive-icon, fs-subtitle-icon
                    //   영향 X: 라벨이 이미지에 영구 박힌 자동 감지 교체 결과
                    $position = $input['position'] ?? '';
                    $result = $im->setLabelPosition((string)$position);
                    break;
                
                case 'set_folder_icon':
                    // 폴더 아이콘 설정 (전체 폴더 동일 적용)
                    //   icon: 아이콘 이름 (custom 또는 builtin 파일명) - 비면 기본값 복원
                    $iconName = isset($input['icon']) ? (string)$input['icon'] : '';
                    $result = $im->setFolderIcon($iconName !== '' ? $iconName : null);
                    break;
                
                default:
                    http_response_code(400);
                    $result = ['success' => false, 'error' => '알 수 없는 op: ' . htmlspecialchars($op)];
            }
            break;
        
        default:
            http_response_code(400);
            $result = ['success' => false, 'error' => __('api_err_unknown_action', '알 수 없는 액션입니다.')];
    }
    
    // vault 임시 파일 자동 정리 (10% 확률 — mtime이 5분 이상 오래된 파일 삭제)
    if (mt_rand(1, 10) === 1) {
        $vtDir = DATA_PATH . '/vault_temp';
        if (is_dir($vtDir)) {
            foreach (new DirectoryIterator($vtDir) as $vf) {
                if ($vf->isDot() || $vf->isDir()) continue;
                if (time() - $vf->getMTime() > 300) @unlink($vf->getPathname());
            }
        }
        // 게시판 업로드 청크 임시 폴더 정리 (30분 이상 오래된 것)
        $bcDir = __DIR__ . '/data/board_chunks';
        if (is_dir($bcDir)) {
            foreach (new DirectoryIterator($bcDir) as $bd) {
                if ($bd->isDot() || !$bd->isDir()) continue;
                if (time() - $bd->getMTime() > 1800) {
                    foreach (new DirectoryIterator($bd->getPathname()) as $cf) {
                        if (!$cf->isDot()) @unlink($cf->getPathname());
                    }
                    @rmdir($bd->getPathname());
                }
            }
        }
    }
    
    $jsonOutput = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonOutput === false) {
        // JSON 인코딩 실패 시 UTF-8 정리 후 재시도
        $jsonOutput = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($jsonOutput === false) {
            $jsonOutput = json_encode(['success' => false, 'error' => 'JSON encoding failed: ' . json_last_error_msg()]);
        }
    }
    echo $jsonOutput;
    _sessionDebugLog('EXIT', 'success=' . (isset($result['success']) ? ($result['success'] ? '1' : '0') : '?'));
    
} catch (\Throwable $e) {
    http_response_code(500);
    _sessionDebugLog('EXCEPTION', 'msg=' . substr($e->getMessage(), 0, 200) . ' file=' . basename($e->getFile()) . ':' . $e->getLine());
    echo json_encode(['success' => false, 'error' => __('server_error_prefix') . $e->getMessage()]);
}