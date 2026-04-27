<?php
/**
 * WebDAV Server for g5 File Manager
 * Windows 네트워크 드라이브 연결 지원
 */

class WebDAV {
    private $db;
    private $auth;
    private $storage;
    private $baseUri;
    private $currentUser = null;  // 인증된 사용자 정보
    
    public function __construct($db, $auth, $storage) {
        $this->db = $db;
        $this->auth = $auth;
        $this->storage = $storage;
        // 동적으로 현재 스크립트 경로 사용
        $this->baseUri = $_SERVER['SCRIPT_NAME'] ?? '/webdav.php';
    }
    
    /**
     * 요청 처리
     */
    public function handleRequest(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];
        
        // OPTIONS는 인증 없이 응답 (WebDAV 클라이언트가 서버 능력 확인용으로 먼저 보냄)
        if ($method === 'OPTIONS') {
            $this->handleOptions();
            return;
        }
        
        // Basic Auth 인증
        if (!$this->authenticate()) {
            $this->requireAuth();
            return;
        }
        
        // URI에서 경로 추출
        $path = $this->getPathFromUri($uri);
        
        $logFile = defined('DATA_PATH') ? DATA_PATH . '/webdav_debug.log' : __DIR__ . '/../data/webdav_debug.log';
        // @file_put_contents($logFile, date('H:i:s') . " REQUEST: $method path='$path' uri='$uri'\n", FILE_APPEND);
        
        switch ($method) {
            case 'PROPFIND':
                $this->handlePropfind($path);
                break;
            case 'GET':
            case 'HEAD':
                $this->handleGet($path, $method === 'HEAD');
                break;
            case 'PUT':
                $this->handlePut($path);
                break;
            case 'DELETE':
                $this->handleDelete($path);
                break;
            case 'MKCOL':
                $this->handleMkcol($path);
                break;
            case 'MOVE':
                $this->handleMove($path);
                break;
            case 'COPY':
                $this->handleCopy($path);
                break;
            case 'PROPPATCH':
                $this->handleProppatch($path);
                break;
            case 'LOCK':
                $this->handleLock($path);
                break;
            case 'UNLOCK':
                $this->handleUnlock($path);
                break;
            default:
                http_response_code(405);
                header('Allow: OPTIONS, PROPFIND, GET, HEAD, PUT, DELETE, MKCOL, MOVE, COPY');
                break;
        }
    }
    
    /**
     * Basic Auth 인증
     */
    private function authenticate(): bool {
        $logFile = defined('DATA_PATH') ? DATA_PATH . '/webdav_debug.log' : __DIR__ . '/../data/webdav_debug.log';
        
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            // @file_put_contents($logFile, date('H:i:s') . " AUTH: PHP_AUTH_USER not set, method={$_SERVER['REQUEST_METHOD']}\n", FILE_APPEND);
            return false;
        }
        
        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];
        
        // @file_put_contents($logFile, date('H:i:s') . " AUTH: user=$username pw_len=" . strlen($password) . " method={$_SERVER['REQUEST_METHOD']}\n", FILE_APPEND);
        
        // 빈 비밀번호 즉시 거부 (SSO 사용자 보호)
        if (empty($password)) {
            return false;
        }
        
        // 브루트포스 방지: IP 기반 시도 횟수 체크
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rateLimitDir = DATA_PATH . '/webdav_attempts';
        if (!is_dir($rateLimitDir)) @mkdir($rateLimitDir, 0755, true);
        $attemptFile = $rateLimitDir . '/' . md5($ip . '_' . $username) . '.json';
        $maxAttempts = defined('LOGIN_MAX_ATTEMPTS') ? LOGIN_MAX_ATTEMPTS : 5;
        $lockoutMinutes = defined('LOGIN_LOCKOUT_MINUTES') ? LOGIN_LOCKOUT_MINUTES : 15;
        
        if (file_exists($attemptFile)) {
            $attemptData = @json_decode(@file_get_contents($attemptFile), true) ?: [];
            $attempts = (int)($attemptData['attempts'] ?? 0);
            $lastAttempt = $attemptData['last_attempt'] ?? '';
            if ($attempts >= $maxAttempts && !empty($lastAttempt)) {
                $lockoutUntil = strtotime($lastAttempt) + ($lockoutMinutes * 60);
                if (time() < $lockoutUntil) {
                    return false; // 잠금 상태
                }
                // 잠금 시간 경과 → 초기화
                @unlink($attemptFile);
            }
        }
        
        // g5 로그인 확인
        $users = $this->db->load('users');
        foreach ($users as $user) {
            // 빈 해시 비밀번호 차단 (SSO 사용자 등)
            if (empty($user['password'])) continue;
            
            if ($user['username'] === $username && password_verify($password, $user['password'])) {
                if (($user['status'] ?? '') !== 'active') {
                    return false;
                }
                
                // 2FA 활성화된 사용자는 일반 비밀번호로 WebDAV 접근 차단
                // (앱 비밀번호를 사용해야 함)
                if (!empty($user['2fa_enabled'])) {
                    // 앱 비밀번호로 재시도하지 않고 여기서 차단
                    // (아래 앱 비밀번호 체크에서 별도 처리)
                    // @file_put_contents($logFile, date('H:i:s') . " AUTH: 2FA user, normal pw blocked → checking app passwords\n", FILE_APPEND);
                    continue;
                }
                
                // 인증 성공 → 시도 횟수 초기화
                if (file_exists($attemptFile)) @unlink($attemptFile);
                
                // 사용자 정보 저장
                $this->currentUser = $user;
                // Storage/Auth에서 $_SESSION['user_id']를 참조하므로 요청 내에서만 설정
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user'] = $user;
                return true;
            }
            
            // 앱 비밀번호 체크 (2FA 사용자 포함)
            if ($user['username'] === $username && !empty($user['app_passwords'])) {
                $plainPassword = str_replace('-', '', $password); // 하이픈 제거
                // @file_put_contents($logFile, date('H:i:s') . " APP_PW: checking " . count($user['app_passwords']) . " app passwords, input_len=" . strlen($plainPassword) . "\n", FILE_APPEND);
                foreach ($user['app_passwords'] as &$ap) {
                    $verifyResult = password_verify($plainPassword, $ap['hash']);
                    // @file_put_contents($logFile, date('H:i:s') . " APP_PW: verify=" . ($verifyResult ? 'TRUE' : 'FALSE') . " hash_prefix=" . substr($ap['hash'], 0, 20) . " input_prefix=" . substr($plainPassword, 0, 4) . "\n", FILE_APPEND);
                    if ($verifyResult) {
                        if (($user['status'] ?? '') !== 'active') {
                            return false;
                        }
                        
                        // 앱 비밀번호 인증 성공 → 마지막 사용 시간 업데이트 (1분 간격으로만 — 성능)
                        $lastUsed = $ap['last_used'] ?? '';
                        if (empty($lastUsed) || (time() - strtotime($lastUsed)) > 60) {
                            $ap['last_used'] = date('Y-m-d H:i:s');
                            $users2 = $this->db->load('users');
                            foreach ($users2 as &$u2) {
                                if ($u2['id'] === $user['id']) {
                                    foreach ($u2['app_passwords'] as &$ap2) {
                                        if ($ap2['id'] === $ap['id']) {
                                            $ap2['last_used'] = $ap['last_used'];
                                            break;
                                        }
                                    }
                                    unset($ap2);
                                    break;
                                }
                            }
                            unset($u2);
                            $this->db->save('users', $users2);
                        }
                        
                        // 인증 성공 → 시도 횟수 초기화
                        if (file_exists($attemptFile)) @unlink($attemptFile);
                        
                        $this->currentUser = $user;
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user'] = $user;
                        return true;
                    }
                }
                unset($ap);
            }
        }
        
        // 인증 실패 → 시도 횟수 기록
        // @file_put_contents($logFile, date('H:i:s') . " AUTH FAILED: user=$username (normal pw + app pw all failed)\n", FILE_APPEND);
        $attemptData = file_exists($attemptFile) 
            ? (@json_decode(@file_get_contents($attemptFile), true) ?: [])
            : [];
        $attemptData['attempts'] = ((int)($attemptData['attempts'] ?? 0)) + 1;
        $attemptData['last_attempt'] = date('Y-m-d H:i:s');
        $attemptData['ip'] = $ip;
        @file_put_contents($attemptFile, json_encode($attemptData), LOCK_EX);
        
        return false;
    }
    
    /**
     * 인증 요청
     */
    private function requireAuth(): void {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="g5 WebDAV"');
        echo 'Authentication required';
    }
    
    /**
     * WebDAV 전용 권한 체크
     */
    private function checkPermission(int $storageId, string $permission): bool {
        if (!$this->currentUser) {
            return false;
        }
        
        // 관리자는 모든 권한
        if (($this->currentUser['role'] ?? '') === 'admin') {
            return true;
        }
        
        $userId = $this->currentUser['id'] ?? 0;
        
        // 홈 스토리지인지 확인 (소유자면 모든 권한)
        $storages = $this->db->load('storages');
        foreach ($storages as $storage) {
            if (($storage['id'] ?? 0) == $storageId) {
                if (($storage['storage_type'] ?? '') === 'home' && ($storage['owner_id'] ?? 0) == $userId) {
                    return true;
                }
                break;
            }
        }
        
        // 권한 테이블에서 확인
        $permissions = $this->db->load('permissions');
        foreach ($permissions as $perm) {
            if (($perm['storage_id'] ?? 0) == $storageId && ($perm['user_id'] ?? 0) == $userId) {
                return (bool)($perm[$permission] ?? false);
            }
        }
        
        return false;
    }
    
    /**
     * URI에서 경로 추출
     */
    private function getPathFromUri(string $uri): string {
        // 쿼리 스트링 제거
        $path = parse_url($uri, PHP_URL_PATH);
        
        // 현재 스크립트명 (예: /g5/mydav.php → mydav.php)
        $scriptName = $_SERVER['SCRIPT_NAME'];
        
        // 스크립트 경로 제거 (Windows/Linux 모두 호환)
        // /g5/mydav.php/1/folder → 1/folder
        if (strpos($path, $scriptName) === 0) {
            $path = substr($path, strlen($scriptName));
        }
        
        // URL 디코딩
        $path = rawurldecode($path);
        
        // 앞뒤 슬래시 정리
        $path = trim($path, '/');
        
        return $path;
    }
    
    /**
     * 경로를 스토리지와 상대경로로 분리
     * 형식: /스토리지ID/상대경로
     */
    private function parsePath(string $path): ?array {
        if (empty($path)) {
            return ['storage_id' => 0, 'relative_path' => '', 'is_root' => true];
        }
        
        $parts = explode('/', $path, 2);
        $firstSegment = $parts[0];
        $relativePath = $parts[1] ?? '';
        
        // 1) 숫자만이면 기존 ID 방식 호환
        if (ctype_digit($firstSegment)) {
            $storageId = (int)$firstSegment;
        } else {
            // 이름으로 스토리지 검색 (중복 이름 처리 포함)
            $storages = $this->storage->getStorages('webdav');
            $allStorages = array_merge($storages['home'] ?? [], $storages['public'] ?? [], $storages['shared'] ?? []);
            $storageId = 0;
            $usedNames = [];
            foreach ($allStorages as $s) {
                if (!($s['can_read'] ?? false)) continue;
                $sName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $s['name'] ?? '');
                $sName = trim($sName);
                if ($sName === '') $sName = 'storage_' . $s['id'];
                $baseName = $sName;
                $counter = 2;
                while (in_array($sName, $usedNames)) {
                    $sName = $baseName . ' (' . $counter++ . ')';
                }
                $usedNames[] = $sName;
                if ($sName === $firstSegment) {
                    $storageId = (int)$s['id'];
                    break;
                }
            }
            if ($storageId === 0) {
                return null;
            }
        }
        
        // 스토리지 접근 권한 확인
        if ($storageId > 0 && !$this->checkPermission($storageId, 'can_read')) {
            return null;
        }
        
        return [
            'storage_id' => $storageId,
            'relative_path' => $relativePath,
            'is_root' => false
        ];
    }
    
    /**
     * 실제 파일 경로 가져오기
     */
    private function getRealPath(int $storageId, string $relativePath): ?string {
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return null;
        }
        
        if (empty($relativePath)) {
            return $basePath;
        }
        
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        
        // 경로 검증 (상위 디렉토리 접근 방지)
        $realBase = realpath($basePath);
        if (!$realBase) {
            return null;
        }
        
        // 1차: realpath 시도
        $realFull = realpath($fullPath);
        if ($realFull !== false) {
            return \isSubPath($realFull, $realBase) ? $fullPath : null;
        }
        
        // 2차: realpath 실패 (특수문자 파일명) — dirname realpath + 정규화 비교
        $realParent = realpath(dirname($fullPath));
        if ($realParent !== false) {
            $cmp = \isSubPath($realParent, $realBase);
            if ($cmp) {
                return $fullPath;
            }
        }
        
        // 3차: file_exists로 존재 확인 + 정규화 경로 비교
        if (file_exists($fullPath) || is_file($fullPath) || is_dir($fullPath)) {
            $sep = DIRECTORY_SEPARATOR;
            $parts = explode($sep, str_replace(['/', '\\'], $sep, $fullPath));
            $resolved = [];
            foreach ($parts as $part) {
                if ($part === '..') {
                    if (empty($resolved)) return null;
                    array_pop($resolved);
                } elseif ($part !== '' && $part !== '.') {
                    $resolved[] = $part;
                }
            }
            $cleanPath = implode($sep, $resolved);
            $cleanBase = str_replace(['/', '\\'], $sep, $realBase);
            // 보안: isSubPath로 정확한 하위 경로 검증 (prefix 확장 공격 방어)
            if (\isSubPath($cleanPath, $cleanBase)) {
                return $fullPath;
            }
        }
        
        return null;
    }
    
    /**
     * OPTIONS 처리
     */
    private function handleOptions(): void {
        http_response_code(200);
        header('Allow: OPTIONS, PROPFIND, GET, HEAD, PUT, DELETE, MKCOL, MOVE, COPY, PROPPATCH, LOCK, UNLOCK');
        header('DAV: 1, 2');
        header('MS-Author-Via: DAV');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: OPTIONS, PROPFIND, GET, HEAD, PUT, DELETE, MKCOL, MOVE, COPY, PROPPATCH, LOCK, UNLOCK');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Depth, Overwrite, Destination, If, Lock-Token, Timeout');
        header('Access-Control-Max-Age: 86400');
        header('Content-Length: 0');
    }
    
    /**
     * PROPFIND 처리 (파일/폴더 목록)
     */
    private function handlePropfind(string $path): void {
        $depth = $_SERVER['HTTP_DEPTH'] ?? 'infinity';
        if ($depth === 'infinity') {
            $depth = 1;
        }
        $depth = (int)$depth;
        
        $parsed = $this->parsePath($path);
        if ($parsed === null) {
            http_response_code(403);
            return;
        }
        
        $responses = [];
        
        if ($parsed['is_root']) {
            // 루트: 스토리지 목록 표시
            $responses[] = $this->buildResponse('/', true, time(), 0);
            
            if ($depth > 0) {
                $storages = $this->storage->getStorages('webdav');
                $allStorages = array_merge($storages['home'] ?? [], $storages['public'] ?? [], $storages['shared'] ?? []);
                
                $usedNames = [];
                foreach ($allStorages as $storage) {
                    if (!($storage['can_read'] ?? false)) continue;
                    $folderName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $storage['name'] ?? 'storage_' . $storage['id']);
                    $folderName = trim($folderName);
                    if ($folderName === '') $folderName = 'storage_' . $storage['id'];
                    // 이름 중복 시 (2), (3) 추가
                    $baseName = $folderName;
                    $counter = 2;
                    while (in_array($folderName, $usedNames)) {
                        $folderName = $baseName . ' (' . $counter++ . ')';
                    }
                    $usedNames[] = $folderName;
                    $responses[] = $this->buildResponse('/' . rawurlencode($folderName), true, time(), 0, $storage['name']);
                }
            }
        } else {
            $realPath = $this->getRealPath($parsed['storage_id'], $parsed['relative_path']);
            
            if (!$realPath || !file_exists($realPath)) {
                http_response_code(404);
                return;
            }
            
            $isDir = is_dir($realPath);
            $href = '/' . $path;
            $responses[] = $this->buildResponse($href, $isDir, filemtime($realPath), $isDir ? 0 : filesize($realPath));
            
            if ($isDir && $depth > 0) {
                $items = scandir($realPath);
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    
                    $itemPath = $realPath . DIRECTORY_SEPARATOR . $item;
                    $itemIsDir = is_dir($itemPath);
                    $itemHref = '/' . $path . '/' . rawurlencode($item);
                    
                    $responses[] = $this->buildResponse(
                        $itemHref,
                        $itemIsDir,
                        filemtime($itemPath),
                        $itemIsDir ? 0 : filesize($itemPath)
                    );
                }
            }
        }
        
        $this->sendMultiStatus($responses);
    }
    
    /**
     * PROPFIND 응답 빌드
     */
    private function buildResponse(string $href, bool $isDir, int $mtime, int $size, ?string $displayName = null): array {
        $props = [
            'displayname' => $displayName ?? basename($href) ?: '/',
            'getlastmodified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'resourcetype' => $isDir ? '<D:collection/>' : '',
        ];
        
        if (!$isDir) {
            $props['getcontentlength'] = $size;
            $props['getcontenttype'] = $this->getMimeType(basename($href));
        }
        
        // ETag 추가
        $props['getetag'] = '"' . md5($href . $mtime . $size) . '"';
        
        return [
            'href' => $this->baseUri . $href,
            'props' => $props
        ];
    }
    
    /**
     * MultiStatus XML 응답
     */
    private function sendMultiStatus(array $responses): void {
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<D:multistatus xmlns:D="DAV:">' . "\n";
        
        foreach ($responses as $response) {
            $xml .= '<D:response>' . "\n";
            $xml .= '<D:href>' . htmlspecialchars($response['href']) . '</D:href>' . "\n";
            $xml .= '<D:propstat>' . "\n";
            $xml .= '<D:prop>' . "\n";
            
            foreach ($response['props'] as $name => $value) {
                if ($name === 'resourcetype') {
                    $xml .= "<D:resourcetype>{$value}</D:resourcetype>\n";
                } else {
                    $xml .= "<D:{$name}>" . htmlspecialchars($value) . "</D:{$name}>\n";
                }
            }
            
            $xml .= '</D:prop>' . "\n";
            $xml .= '<D:status>HTTP/1.1 200 OK</D:status>' . "\n";
            $xml .= '</D:propstat>' . "\n";
            $xml .= '</D:response>' . "\n";
        }
        
        $xml .= '</D:multistatus>';
        
        echo $xml;
    }
    
    /**
     * GET 처리 (파일 다운로드) - 최적화
     */
    private function handleGet(string $path, bool $headOnly = false): void {
        $parsed = $this->parsePath($path);
        if ($parsed === null || $parsed['is_root']) {
            // 브라우저에서 접근 시 안내 메시지
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><title>WebDAV Server</title></head><body>';
            echo '<h1>WebDAV Server</h1>';
            echo '<p>' . __('webdav_info') . '</p>';
            echo '<p><strong>' . __('webdav_windows_connect') . '</strong></p>';
            echo '<ol>';
            echo '<li>' . __('webdav_step1') . '</li>';
            echo '<li>' . __('webdav_step2') . '</li>';
            echo '<li>' . __('webdav_step3') . '</li>';
            echo '<li>' . __('webdav_step4') . '</li>';
            echo '<li>' . __('webdav_step5') . '</li>';
            echo '</ol>';
            echo '</body></html>';
            return;
        }
        
        $realPath = $this->getRealPath($parsed['storage_id'], $parsed['relative_path']);
        
        if (!$realPath || !file_exists($realPath)) {
            http_response_code(404);
            return;
        }
        
        if (is_dir($realPath)) {
            http_response_code(403);
            return;
        }
        
        $size = filesize($realPath);
        $mtime = filemtime($realPath);
        $mime = $this->getMimeType($realPath);
        $etag = '"' . md5($path . $mtime . $size) . '"';
        
        // If-None-Match 캐시 확인
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
            http_response_code(304);
            return;
        }
        
        // If-Modified-Since 캐시 확인
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $ifModified = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if ($ifModified !== false && $mtime <= $ifModified) {
                http_response_code(304);
                return;
            }
        }
        
        // 공통 헤더
        header('Content-Type: ' . $mime);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('ETag: ' . $etag);
        header('Accept-Ranges: bytes');
        header('Connection: keep-alive');
        header('Keep-Alive: timeout=5, max=100');
        header('Cache-Control: private, max-age=0');
        
        // Range 요청 처리
        $start = 0;
        $end = $size - 1;
        $isPartial = false;
        
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
                $start = $matches[1] === '' ? 0 : (int)$matches[1];
                $end = $matches[2] === '' ? $size - 1 : (int)$matches[2];
                
                if ($start > $end || $start >= $size || $end >= $size) {
                    http_response_code(416); // Range Not Satisfiable
                    header('Content-Range: bytes */' . $size);
                    return;
                }
                
                $isPartial = true;
            }
        }
        
        $length = $end - $start + 1;
        
        if ($isPartial) {
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        
        header('Content-Length: ' . $length);
        
        if ($headOnly) {
            return;
        }
        
        // 출력 버퍼 비우기
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // 파일 스트리밍 전송
        $fp = fopen($realPath, 'rb');
        if ($fp) {
            if ($start > 0) {
                fseek($fp, $start);
            }
            
            $bufferSize = 8192; // 8KB 버퍼
            $remaining = $length;
            
            while (!feof($fp) && $remaining > 0) {
                $readSize = min($bufferSize, $remaining);
                echo fread($fp, $readSize);
                $remaining -= $readSize;
                flush();
            }
            
            fclose($fp);
        }
    }
    
    /**
     * PUT 처리 (파일 업로드) - 최적화
     */
    private function handlePut(string $path): void {
        $parsed = $this->parsePath($path);
        if ($parsed === null || $parsed['is_root']) {
            http_response_code(403);
            return;
        }
        
        // 쓰기 권한 확인
        if (!$this->checkPermission($parsed['storage_id'], 'can_write')) {
            http_response_code(403);
            return;
        }
        
        $realPath = $this->getRealPath($parsed['storage_id'], $parsed['relative_path']);
        if (!$realPath) {
            http_response_code(403);
            return;
        }
        
        // 상위 디렉토리 존재 확인
        $dir = dirname($realPath);
        if (!is_dir($dir)) {
            http_response_code(409); // Conflict - 상위 폴더 없음
            return;
        }
        
        // Content-Length 확인
        $expectedSize = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : -1;
        
        // Expect: 100-continue 처리 (클라이언트 대용량 전송 시)
        if (isset($_SERVER['HTTP_EXPECT']) && stripos($_SERVER['HTTP_EXPECT'], '100-continue') !== false) {
            header('HTTP/1.1 100 Continue', true, 100);
            flush();
        }
        
        // quota 체크 (사용자별/스토리지별)
        if ($expectedSize > 0) {
            $quotaResult = $this->checkQuota($parsed['storage_id'], $expectedSize);
            if (!$quotaResult['allowed']) {
                http_response_code(507); // Insufficient Storage
                return;
            }
        }
        
        $isNew = !file_exists($realPath);
        
        // 세션 잠금 해제 (대용량 전송 중 다른 요청 차단 방지)
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        
        // 임시 파일에 먼저 저장 (업로드 중 끊김 시 원본 보호)
        $tempPath = $realPath . '.webdav_tmp_' . uniqid();
        
        $input = fopen('php://input', 'rb');
        if (!$input) {
            http_response_code(500);
            return;
        }
        
        $output = fopen($tempPath, 'wb');
        if (!$output) {
            fclose($input);
            http_response_code(500);
            return;
        }
        
        // 업로드 진행 (중간 quota 체크 포함)
        $written = 0;
        $bufferSize = 65536; // 64KB
        $lastFlush = time();
        $lastQuotaCheck = 0;
        $quotaCheckInterval = 10 * 1024 * 1024; // 10MB마다 체크
        
        while (!feof($input)) {
            $chunk = fread($input, $bufferSize);
            if ($chunk === false) {
                break;
            }
            
            $bytes = fwrite($output, $chunk);
            if ($bytes === false) {
                fclose($input);
                fclose($output);
                @unlink($tempPath);
                http_response_code(500);
                return;
            }
            
            $written += $bytes;
            
            // Content-Length 없을 때 10MB마다 quota 체크
            if ($expectedSize <= 0 && ($written - $lastQuotaCheck) >= $quotaCheckInterval) {
                $quotaResult = $this->checkQuota($parsed['storage_id'], $written, $written);
                if (!$quotaResult['allowed']) {
                    fclose($input);
                    fclose($output);
                    @unlink($tempPath);
                    http_response_code(507);
                    return;
                }
                $lastQuotaCheck = $written;
            }
            
            // 주기적으로 flush (5초마다)
            if (time() - $lastFlush >= 5) {
                fflush($output);
                $lastFlush = time();
            }
        }
        
        fclose($input);
        fclose($output);
        
        // 크기 검증 (Content-Length가 있을 때)
        if ($expectedSize > 0 && $written !== $expectedSize) {
            @unlink($tempPath);
            http_response_code(400); // Bad Request - 크기 불일치
            return;
        }
        
        // 최종 quota 체크 (Content-Length 없었던 경우 최종 확인)
        if ($expectedSize <= 0 && $written > 0) {
            $quotaResult = $this->checkQuota($parsed['storage_id'], $written, $written);
            if (!$quotaResult['allowed']) {
                @unlink($tempPath);
                http_response_code(507);
                return;
            }
        }
        
        // 임시 파일을 실제 위치로 이동
        if (file_exists($realPath)) {
            @unlink($realPath);
        }
        
        if (!rename($tempPath, $realPath)) {
            // rename 실패 시 copy 시도
            if (copy($tempPath, $realPath)) {
                @unlink($tempPath);
            } else {
                @unlink($tempPath);
                http_response_code(500);
                return;
            }
        }
        
        // PROPFIND 캐시 무효화
        $this->invalidatePropfindCache($parsed['storage_id'], dirname($parsed['relative_path']));
        
        // 스토리지 사용량 업데이트
        $this->storage->updateUsedSize($parsed['storage_id'], $written);
        
        http_response_code($isNew ? 201 : 204);
    }
    
    /**
     * PROPFIND 캐시 무효화 (현재 미사용)
     */
    private function invalidatePropfindCache(int $storageId, string $relativePath): void {
        // 캐싱 비활성화됨 - 빈 함수
    }
    
    /**
     * DELETE 처리 (휴지통으로 이동)
     */
    private function handleDelete(string $path): void {
        $parsed = $this->parsePath($path);
        if ($parsed === null || $parsed['is_root']) {
            http_response_code(403);
            return;
        }
        
        // 삭제 권한 확인
        if (!$this->checkPermission($parsed['storage_id'], 'can_delete')) {
            http_response_code(403);
            return;
        }
        
        $realPath = $this->getRealPath($parsed['storage_id'], $parsed['relative_path']);
        
        if (!$realPath || !file_exists($realPath)) {
            http_response_code(404);
            return;
        }
        
        // 삭제할 파일/폴더 크기 계산
        $deleteSize = is_dir($realPath) ? $this->getDirectorySize($realPath) : (int)@filesize($realPath);
        
        // 휴지통으로 이동
        $trashResult = $this->moveToTrash($parsed['storage_id'], $parsed['relative_path'], $realPath);
        
        if (!$trashResult) {
            // 휴지통 이동 실패 시 직접 삭제 (fallback)
            if (is_dir($realPath)) {
                $this->deleteDirectory($realPath);
            } else {
                unlink($realPath);
            }
        }
        
        // 스토리지 사용량 업데이트 (감소)
        if ($deleteSize > 0) {
            $this->storage->updateUsedSize($parsed['storage_id'], -$deleteSize);
        }
        
        // PROPFIND 캐시 무효화
        $this->invalidatePropfindCache($parsed['storage_id'], dirname($parsed['relative_path']));
        
        http_response_code(204);
    }
    
    /**
     * 파일/폴더를 휴지통으로 이동
     */
    private function moveToTrash(int $storageId, string $relativePath, string $fullPath): bool {
        // TRASH_PATH 상수 확인
        if (!defined('TRASH_PATH')) {
            return false;
        }
        
        $trashDir = TRASH_PATH;
        if (!is_dir($trashDir)) {
            @mkdir($trashDir, 0755, true);
        }
        
        $isDir = is_dir($fullPath);
        $trashId = uniqid('trash_');
        $trashPath = $trashDir . DIRECTORY_SEPARATOR . $trashId;
        
        // 파일/폴더를 휴지통으로 이동
        if (!@rename($fullPath, $trashPath)) {
            // rename 실패 시 복사 후 삭제
            if ($isDir) {
                if (!$this->copyDirectory($fullPath, $trashPath)) {
                    return false;
                }
                $this->deleteDirectory($fullPath);
            } else {
                if (!@copy($fullPath, $trashPath)) {
                    return false;
                }
                @unlink($fullPath);
            }
        }
        
        // 휴지통 DB에 기록
        $trash = $this->db->load('trash');
        $trash[] = [
            'id' => $trashId,
            'name' => basename($fullPath),
            'original_path' => $relativePath,
            'storage_id' => $storageId,
            'deleted_by' => $this->currentUser['id'] ?? 0,
            'deleted_by_name' => $this->currentUser['display_name'] ?? $this->currentUser['username'] ?? '',
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_dir' => $isDir,
            'size' => $isDir ? $this->getDirectorySize($trashPath) : filesize($trashPath),
            'trash_path' => $trashPath
        ];
        $this->db->save('trash', $trash);
        
        return true;
    }
    
    /**
     * 디렉토리 크기 계산
     */
    private function getDirectorySize(string $dir): int {
        $size = 0;
        if (!is_dir($dir)) return 0;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }
    
    /**
     * quota 체크 (FileManager 로직과 동일)
     * $alreadyOnDisk: 이미 디스크에 쓰인 임시파일 크기 (중간 체크 시 disk_free_space 보정용)
     */
    private function checkQuota(int $storageId, int $fileSize, int $alreadyOnDisk = 0): array {
        $storageInfo = $this->storage->getStorageById($storageId);
        if (!$storageInfo) {
            return ['allowed' => false];
        }
        
        $storageType = $storageInfo['storage_type'] ?? 'local';
        $basePath = $this->storage->getRealPath($storageId);
        
        // home 타입: 사용자별 quota 체크
        if ($storageType === 'home') {
            $userId = $this->currentUser['id'] ?? 0;
            $user = $this->db->find('users', ['id' => $userId]);
            $quota = (int)($user['quota'] ?? 0);
            
            if ($quota > 0) {
                $used = $this->getDirectorySize($basePath);
                // 임시파일이 이미 디렉토리에 포함되어 있으므로 빼줌
                $used = max(0, $used - $alreadyOnDisk);
                if ($fileSize > ($quota - $used)) {
                    return ['allowed' => false];
                }
            }
            return ['allowed' => true];
        }
        
        // shared/public 타입: 스토리지별 quota 체크
        $quota = (int)($storageInfo['quota'] ?? 0);
        $usedSize = (int)($storageInfo['used_size'] ?? 0);
        
        if ($quota > 0 && $fileSize > ($quota - $usedSize)) {
            return ['allowed' => false];
        }
        
        // 디스크 여유 공간 체크 (임시파일 크기 보정)
        if ($basePath) {
            $diskFree = @disk_free_space($basePath);
            if ($diskFree !== false && $fileSize > ($diskFree + $alreadyOnDisk)) {
                return ['allowed' => false];
            }
        }
        
        return ['allowed' => true];
    }
    
    /**
     * MKCOL 처리 (폴더 생성)
     */
    private function handleMkcol(string $path): void {
        $parsed = $this->parsePath($path);
        if ($parsed === null || $parsed['is_root']) {
            http_response_code(403);
            return;
        }
        
        // 쓰기 권한 확인
        if (!$this->checkPermission($parsed['storage_id'], 'can_write')) {
            http_response_code(403);
            return;
        }
        
        $realPath = $this->getRealPath($parsed['storage_id'], $parsed['relative_path']);
        if (!$realPath) {
            http_response_code(403);
            return;
        }
        
        if (file_exists($realPath)) {
            http_response_code(405); // 이미 존재
            return;
        }
        
        // 상위 디렉토리 존재 확인
        $parent = dirname($realPath);
        if (!is_dir($parent)) {
            http_response_code(409); // Conflict
            return;
        }
        
        if (mkdir($realPath, 0755)) {
            // PROPFIND 캐시 무효화
            $this->invalidatePropfindCache($parsed['storage_id'], dirname($parsed['relative_path']));
            http_response_code(201);
        } else {
            http_response_code(500);
        }
    }
    
    /**
     * MOVE 처리 (이동/이름변경)
     */
    private function handleMove(string $path): void {
        $this->handleMoveOrCopy($path, true);
    }
    
    /**
     * COPY 처리
     */
    private function handleCopy(string $path): void {
        $this->handleMoveOrCopy($path, false);
    }
    
    /**
     * MOVE/COPY 공통 처리
     */
    private function handleMoveOrCopy(string $srcPath, bool $isMove): void {
        $destination = $_SERVER['HTTP_DESTINATION'] ?? '';
        if (empty($destination)) {
            http_response_code(400);
            return;
        }
        
        // Destination 헤더에서 경로 추출
        $destUri = parse_url($destination, PHP_URL_PATH);
        $destPath = $this->getPathFromUri($destUri);
        
        $srcParsed = $this->parsePath($srcPath);
        $destParsed = $this->parsePath($destPath);
        
        if ($srcParsed === null || $destParsed === null || $srcParsed['is_root'] || $destParsed['is_root']) {
            http_response_code(403);
            return;
        }
        
        // 권한 확인
        if (!$this->checkPermission($destParsed['storage_id'], 'can_write')) {
            http_response_code(403);
            return;
        }
        
        if ($isMove && !$this->checkPermission($srcParsed['storage_id'], 'can_delete')) {
            http_response_code(403);
            return;
        }
        
        $srcRealPath = $this->getRealPath($srcParsed['storage_id'], $srcParsed['relative_path']);
        $destRealPath = $this->getRealPath($destParsed['storage_id'], $destParsed['relative_path']);
        
        if (!$srcRealPath || !file_exists($srcRealPath)) {
            http_response_code(404);
            return;
        }
        
        if (!$destRealPath) {
            http_response_code(403);
            return;
        }
        
        $overwrite = ($_SERVER['HTTP_OVERWRITE'] ?? 'T') === 'T';
        $destExists = file_exists($destRealPath);
        
        if ($destExists && !$overwrite) {
            http_response_code(412); // Precondition Failed
            return;
        }
        
        // 버그 방어: 동일한 원본/대상 경로 (0바이트 덮어쓰기 위험)
        $srcReal = realpath($srcRealPath);
        $destReal = $destExists ? realpath($destRealPath) : null;
        if ($srcReal !== false && $destReal !== false && $srcReal === $destReal) {
            http_response_code(403); // Forbidden - 자기 자신으로 이동/복사 불가
            return;
        }
        
        // 대상 상위 폴더 확인
        $destDir = dirname($destRealPath);
        if (!is_dir($destDir)) {
            http_response_code(409);
            return;
        }
        
        if ($isMove) {
            // 이동
            if ($destExists) {
                if (is_dir($destRealPath)) {
                    $this->deleteDirectory($destRealPath);
                } else {
                    unlink($destRealPath);
                }
            }
            $success = rename($srcRealPath, $destRealPath);
        } else {
            // 복사
            if (is_dir($srcRealPath)) {
                $success = $this->copyDirectory($srcRealPath, $destRealPath);
            } else {
                $success = copy($srcRealPath, $destRealPath);
            }
        }
        
        if ($success) {
            http_response_code($destExists ? 204 : 201);
        } else {
            http_response_code(500);
        }
    }
    
    /**
     * PROPPATCH 처리 (속성 변경 - 최소 지원)
     */
    private function handleProppatch(string $path): void {
        // 대부분의 클라이언트에서 필요하지만 실제로는 무시해도 됨
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<D:multistatus xmlns:D="DAV:">' . "\n";
        $xml .= '<D:response>' . "\n";
        $xml .= '<D:href>' . htmlspecialchars($this->baseUri . '/' . $path) . '</D:href>' . "\n";
        $xml .= '<D:propstat>' . "\n";
        $xml .= '<D:prop/>' . "\n";
        $xml .= '<D:status>HTTP/1.1 200 OK</D:status>' . "\n";
        $xml .= '</D:propstat>' . "\n";
        $xml .= '</D:response>' . "\n";
        $xml .= '</D:multistatus>';
        
        echo $xml;
    }
    
    /**
     * LOCK 처리 (잠금 - 최소 지원)
     */
    private function handleLock(string $path): void {
        // Windows에서 Office 파일 편집 시 필요
        $token = 'opaquelocktoken:' . uniqid();
        
        http_response_code(200);
        header('Content-Type: application/xml; charset=utf-8');
        header('Lock-Token: <' . $token . '>');
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<D:prop xmlns:D="DAV:">' . "\n";
        $xml .= '<D:lockdiscovery>' . "\n";
        $xml .= '<D:activelock>' . "\n";
        $xml .= '<D:locktype><D:write/></D:locktype>' . "\n";
        $xml .= '<D:lockscope><D:exclusive/></D:lockscope>' . "\n";
        $xml .= '<D:depth>infinity</D:depth>' . "\n";
        $xml .= '<D:owner/>' . "\n";
        $xml .= '<D:timeout>Second-3600</D:timeout>' . "\n";
        $xml .= '<D:locktoken><D:href>' . $token . '</D:href></D:locktoken>' . "\n";
        $xml .= '</D:activelock>' . "\n";
        $xml .= '</D:lockdiscovery>' . "\n";
        $xml .= '</D:prop>';
        
        echo $xml;
    }
    
    /**
     * UNLOCK 처리
     */
    private function handleUnlock(string $path): void {
        http_response_code(204);
    }
    
    /**
     * MIME 타입 가져오기
     */
    private function getMimeType(string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'txt' => 'text/plain',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
        ];
        
        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }
    
    /**
     * 디렉토리 삭제 (재귀)
     */
    private function deleteDirectory(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }
        
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
    
    /**
     * 디렉토리 복사 (재귀)
     */
    private function copyDirectory(string $src, string $dst): bool {
        if (!is_dir($src)) {
            return false;
        }
        
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        
        $items = scandir($src);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $srcPath = $src . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        
        return true;
    }
}
