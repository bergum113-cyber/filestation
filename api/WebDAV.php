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
        // ★ (2026-10-29 10:27, 20차 점검) IP·국가 제한(웹 로그인과 같은 규칙, 비밀번호 확인 전에) — 종전엔 WebDAV 는 이 제한을 보지 않아 막힌 IP 에서도 파일에 접근됐음(재현)
        if ($this->auth && method_exists($this->auth, 'webdavIpDenied') && $this->auth->webdavIpDenied((string)$username)) {
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
    /**
     * ★ (2026-10-29 03:11, 16차) 이 스토리지가 현재 사용자에게 WebDAV 로 노출되는지 — 이름 조회가 쓰는 getStorages('webdav') 와 같은 목록으로 판단.
     *   본인 홈은 항상 노출(getStorages 가 home 을 포함). 관리자도 WebDAV 목록 기준(숫자 ID 로 남의 홈을 여는 건 별도 항목)
     */
    private function isWebDavVisible(int $storageId): bool {
        if (!$this->currentUser) return false;
        if ($this->isOthersHome($storageId)) return false;   // ★ (2026-10-29 04:25, 17차) 남의 홈은 관리자도 안 보임(웹과 같음, 펜닐 결정)
        if (($this->currentUser['role'] ?? '') === 'admin') return true;
        $list = $this->storage->getStorages('webdav');
        foreach (array_merge($list['home'] ?? [], $list['public'] ?? [], $list['shared'] ?? []) as $s) {
            if ((int)($s['id'] ?? 0) === $storageId) return true;
        }
        return false;
    }

    /**
     * ★ (2026-10-29 04:25, 17차) 남의 홈 스토리지인지 — 홈은 소유자 전용(관리자 포함). 웹(Storage::checkPermission)은 종전부터 소유자만 허용했는데
     *   WebDAV 는 관리자에게 모든 권한을 줘 숫자 ID(/mydav.php/<id>/)로 다른 사용자의 개인 폴더를 읽고 쓰고 지울 수 있었음
     */
    private function isOthersHome(int $storageId): bool {
        foreach ($this->db->load('storages') ?: [] as $st) {
            if ((int)($st['id'] ?? 0) === $storageId) {
                return ($st['storage_type'] ?? '') === 'home' && (int)($st['owner_id'] ?? 0) !== (int)($this->currentUser['id'] ?? 0);
            }
        }
        return false;
    }

    private function checkPermission(int $storageId, string $permission): bool {
        if (!$this->currentUser) {
            return false;
        }
        if ($this->isOthersHome($storageId)) return false;   // ★ (2026-10-29 04:25, 17차) 남의 홈은 관리자도 거부
        
        // 관리자는 모든 권한
        if (($this->currentUser['role'] ?? '') === 'admin') {
            return true;
        }
        
        $userId = $this->currentUser['id'] ?? 0;
        
        // 홈 스토리지인지 확인 (소유자면 모든 권한)
        $storages = $this->db->load('storages');
        $stType = '';
        foreach ($storages as $storage) {
            if (($storage['id'] ?? 0) == $storageId) {
                $stType = (string)($storage['storage_type'] ?? '');
                if ($stType === 'home' && ($storage['owner_id'] ?? 0) == $userId) {
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
        
        // ★ (2026-10-29 18:10, 22차 점검) 공용 폴더(shared)는 권한 기록이 없으면 읽기·내려받기만(웹 Storage::checkPermission 과 같은 기준) — 종전엔 WebDAV 만 거부해,
        //   WebDAV 루트 목록에는 보이는데 열면 403 이었음
        if ($stType === 'shared' && ($permission === 'can_read' || $permission === 'can_download')) {
            return true;
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
    /**
     * ★ (2026-10-29 22:05, 24차 점검) WebDAV 최상위에 보이는 스토리지 이름 => 번호(이름 정리·중복 (2) 붙이기 규칙 하나로 — 목록과 경로 해석이 같은 이름을 쓰게).
     *   로컬 경로가 없는 원격 스토리지(FTP·SFTP·WebDAV·S3)는 WebDAV 로 열 수 없어(늘 404) 목록에서 뺌
     */
    private function davStorageNames(): array {
        $storages = $this->storage->getStorages('webdav');
        $all = array_merge($storages['home'] ?? [], $storages['public'] ?? [], $storages['shared'] ?? []);
        $map = [];
        foreach ($all as $s) {
            if (!($s['can_read'] ?? false)) continue;
            if (in_array(($s['storage_type'] ?? ''), ['ftp', 'sftp', 'webdav', 's3'], true)) continue;
            $n = trim(preg_replace('/[\/\\\\:*?"<>|]/', '_', $s['name'] ?? ''));
            if ($n === '') $n = 'storage_' . $s['id'];
            $base = $n; $c = 2;
            while (isset($map[$n])) $n = $base . ' (' . $c++ . ')';
            $map[$n] = (int)$s['id'];
        }
        return $map;
    }
    
    private function parsePath(string $path): ?array {
        if (empty($path)) {
            return ['storage_id' => 0, 'relative_path' => '', 'is_root' => true];
        }
        
        $parts = explode('/', $path, 2);
        $firstSegment = $parts[0];
        $relativePath = $parts[1] ?? '';
        
        // ★ (2026-10-29 22:05, 24차 점검) 이름 목록을 먼저 보고(최상위 목록과 같은 이름 규칙), 숫자만인 첫 구간은 그 이름이 없을 때만 ID 로 —
        //   종전엔 숫자만이면 늘 ID 로 봐서 이름이 '2025' 같은 스토리지는 403 이 나거나 번호가 2025 인 다른 스토리지가 열렸음
        $davNames = $this->davStorageNames();
        if (isset($davNames[$firstSegment])) {
            $storageId = $davNames[$firstSegment];
        } elseif (ctype_digit($firstSegment)) {
            // 1) 숫자만이면 기존 ID 방식 호환
            $storageId = (int)$firstSegment;
            // ★ (2026-10-29 03:11, 16차) 숫자 ID 로 들어와도 'WebDAV 노출(can_visible_webdav)' 을 확인 — 종전엔 이름으로 들어올 때만
            //   getStorages('webdav') 로 걸러, 숫자 ID 로는 WebDAV 를 꺼 둔 스토리지도 열 수 있었음(ID 는 순번이라 추측 쉬움)
            if (!$this->isWebDavVisible($storageId)) {
                return null;
            }
        } else {
            return null;
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
     * ★ (2026-10-28 21:19, 8차 점검) 폴더별 권한(Storage::checkFolderPermission — 요청 사용자는 인증 때 $_SESSION['user_id'] 로 넣어 둠).
     *   들어 있는 폴더 기준, $self 면 대상 폴더 자신도 확인
     *   ★ (2026-10-28 21:53, 9차 점검) $tree 면 대상 자신 + 그 아래 보호 폴더까지(Storage::checkFolderTreePermission — 펜닐 결정 '작업 자체를 거부')
     */
    private function folderAllowed(int $storageId, string $relativePath, string $perm, bool $self = false, bool $tree = false): bool {
        $rel = trim(str_replace('\\', '/', $relativePath), '/');
        $dir = dirname($rel); if ($dir === '.') $dir = '';
        if (!$this->storage->checkFolderPermission($storageId, $dir ?: $rel, $perm)) return false;
        if ($tree && $rel !== '' && !$this->storage->checkFolderTreePermission($storageId, $rel, $perm)) return false;
        if ($self && $rel !== '' && !$this->storage->checkFolderPermission($storageId, $rel, $perm)) return false;
        return true;
    }

    /**
     * ★ (2026-10-28 20:42, 7차 점검) 이 경로가 스토리지 최상위 자체인지 — 빈 경로, 점·공백만인 구간('.', '..', ' ')이 있는 경로,
     *   실제 경로(realpath)가 스토리지 기준 폴더와 같은 경로는 모두 최상위로 봄(삭제·이동·덮어쓰기 금지용). 윈도우는 대소문자 무시
     */
    private function isStorageRootTarget(int $storageId, string $relativePath): bool {
        $rel = str_replace('\\', '/', $relativePath);
        if (trim($rel, '/') === '') return true;
        foreach (explode('/', $rel) as $seg) {
            if ($seg !== '' && rtrim($seg, ' .') === '') return true;
        }
        $base = $this->storage->getRealPath($storageId);
        $rb = $base ? realpath($base) : false;
        $full = $this->getRealPath($storageId, $relativePath);
        $rf = $full ? realpath($full) : false;
        if ($rb === false || $rf === false) return false;
        $rb = rtrim($rb, '\\/'); $rf = rtrim($rf, '\\/');
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($rf, $rb) === 0 : $rf === $rb;
    }

    /**
     * 실제 파일 경로 가져오기
     */
    private function getRealPath(int $storageId, string $relativePath): ?string {
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return null;
        }
        
        if ((string)$relativePath === '') {   // ★ (2026-10-30 01:50, 26차 점검) 이름이 '0' 인 최상위 항목도 그 항목으로(종전 empty() 는 '0' 을 빈 값으로 봐 스토리지 최상위로 해석)
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
                // ★ (2026-10-29 22:05, 24차 점검) 이름 규칙은 davStorageNames() 하나로(경로 해석과 같게)
                $davSt = $this->storage->getStorages('webdav');
                $davById = [];
                foreach (array_merge($davSt['home'] ?? [], $davSt['public'] ?? [], $davSt['shared'] ?? []) as $st) $davById[(int)$st['id']] = $st;
                foreach ($this->davStorageNames() as $folderName => $sid) {
                    $responses[] = $this->buildResponse('/' . rawurlencode($folderName), true, time(), 0, $davById[$sid]['name'] ?? $folderName);
                }
            }
        } else {
            $realPath = $this->getRealPath($parsed['storage_id'], $parsed['relative_path']);
            
            if (!$realPath || !file_exists($realPath)) {
                http_response_code(404);
                return;
            }
            
            $isDir = is_dir($realPath);
            // ★ (2026-10-28 21:19, 8차 점검, 펜닐 결정) 폴더별 권한 적용(웹 화면과 같은 기준) — 종전엔 WebDAV 는 스토리지 권한만 봐서 웹에서 막힌 폴더도 읽고·쓰고·지울 수 있었음
            if (!$this->folderAllowed((int)$parsed['storage_id'], (string)$parsed['relative_path'], 'can_read', $isDir)) {
                http_response_code(403);
                return;
            }
            $pfHasRules = !empty($this->storage->getFolderPermissions((int)$parsed['storage_id']));
            $pfRel = trim(str_replace('\\', '/', (string)$parsed['relative_path']), '/');
            // ★ (2026-10-29 18:18, 22차 점검) 해독된 현재 경로를 href 에 넣을 때 '%'·'#'·'?' 는 다시 인코딩(그 밖의 글자는 종전 그대로 — 한글 등 기존 클라이언트 동작 유지) —
            //   종전엔 이름에 이런 글자가 든 폴더 안에서 클라이언트가 href 를 잘못 해석했음
            $hrefPath = strtr((string)$path, ['%' => '%25', '#' => '%23', '?' => '%3F']);
            $href = '/' . $hrefPath;
            $responses[] = $this->buildResponse($href, $isDir, filemtime($realPath), $isDir ? 0 : filesize($realPath), $path === '' ? null : basename(str_replace('\\', '/', (string)$path)));
            
            if ($isDir && $depth > 0) {
                $items = scandir($realPath);
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    
                    $itemPath = $realPath . DIRECTORY_SEPARATOR . $item;
                    $itemIsDir = is_dir($itemPath);
                    // 읽기가 막힌 하위 폴더는 목록에서 숨김(웹 목록과 같음)
                    if ($pfHasRules && $itemIsDir && !$this->storage->checkFolderPermission((int)$parsed['storage_id'], ($pfRel !== '' ? $pfRel . '/' : '') . $item, 'can_read')) continue;
                    $itemHref = '/' . $hrefPath . '/' . rawurlencode($item);
                    
                    $responses[] = $this->buildResponse(
                        $itemHref,
                        $itemIsDir,
                        filemtime($itemPath),
                        $itemIsDir ? 0 : filesize($itemPath),
                        $item   // ★ (22차) 표시 이름은 원래 이름 — 종전엔 인코딩된 href 의 basename('%ED%95%9C.txt')이 표시 이름이 됐음
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
        // ★ (2026-10-28 21:19, 8차 점검, 펜닐 결정) 폴더별 권한 적용(웹 화면과 같은 기준) — 종전엔 WebDAV 는 스토리지 권한만 봐서 웹에서 막힌 폴더도 읽고·쓰고·지울 수 있었음
        if (!$this->folderAllowed((int)$parsed['storage_id'], (string)$parsed['relative_path'], 'can_read')) {
            http_response_code(403);
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
        // ★ (2026-10-29 18:10, 22차 점검) 브라우저로 WebDAV 주소를 열 때 html·svg 등의 스크립트가 앱과 같은 주소에서 돌지 않게 — 형식 추측 금지 + 그 밖의 형식은 sandbox
        //   (WebDAV 클라이언트는 이 헤더를 쓰지 않아 동작 변화 없음. 웹 미리보기·공유와 같은 기준)
        header('X-Content-Type-Options: nosniff');
        if (!preg_match('#^(image/(jpeg|png|gif|webp|bmp)|video/|audio/|application/pdf$|application/octet-stream$)#', (string)$mime)) {
            header("Content-Security-Policy: default-src 'none'; sandbox;");
        }
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
                // ★ (2026-10-29 18:18, 22차 점검) 'bytes=-N'(끝에서 N바이트)은 마지막 N바이트, 끝 값이 크기를 넘으면 마지막 바이트로 줄임(RFC 9110) —
                //   종전엔 'bytes=-500' 을 0~500 으로 보내 앞부분을 줬고, 끝이 크기 이상이면 416 이었음
                if ($matches[1] === '' && $matches[2] !== '') {
                    $start = max(0, $size - (int)$matches[2]);
                    $end = $size - 1;
                } else {
                    $start = $matches[1] === '' ? 0 : (int)$matches[1];
                    $end = $matches[2] === '' ? $size - 1 : min((int)$matches[2], $size - 1);
                }
                
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
    /**
     * ★ (2026-10-28 17:42, 소스 점검 3단계) WebDAV 로 만들 수 없는 이름 — 웹 화면 업로드와 같은 기준(서버 실행 확장자·이중 확장자, config.php fsServerExecReason)
     *   + WebDAV 는 이름을 정리하지 않고 그대로 저장하므로 아파치·PHP 설정 파일('.htaccess'·'.htpasswd'·'.user.ini')도 막음.
     *   종전엔 PUT/MOVE/COPY 에 확장자 검사가 아예 없었음
     */
    private function isBlockedDavName(string $relativePath): bool {
        $base = strtolower(basename(str_replace('\\', '/', $relativePath)));
        $base = rtrim($base, ' .');   // 윈도우는 이름 끝 점·공백을 떼어 저장
        if (in_array($base, ['.htaccess', '.htpasswd', '.user.ini'], true)) return true;
        return function_exists('fsServerExecReason') && fsServerExecReason($base) !== null;
    }

    /** ★ (2026-10-30 00:48, 25차 — 펜닐 선택 'WebDAV 도 웹 업로드와 같게') 업로드 설정·랜섬웨어·대량 감지 검사를 웹과 같은 FileManager 함수로(요청 사용자는 인증 때 세션에 넣어 둠) */
    private $davFmInst = null;
    private function davFm(): ?FileManager {
        if ($this->davFmInst === null) { try { $this->davFmInst = new FileManager(); } catch (\Throwable $e) { $this->davFmInst = false; } }
        return $this->davFmInst ?: null;
    }

    private function handlePut(string $path): void {
        $parsed = $this->parsePath($path);
        if ($parsed === null || $parsed['is_root']) {
            http_response_code(403);
            return;
        }
        if ($this->isBlockedDavName($parsed['relative_path'])) {
            http_response_code(403);
            return;
        }
        
        // 쓰기 권한 확인
        if (!$this->checkPermission($parsed['storage_id'], 'can_write')) {
            http_response_code(403);
            return;
        }
        // ★ (2026-10-28 21:19, 8차 점검, 펜닐 결정) 폴더별 권한 적용(웹 화면과 같은 기준) — 종전엔 WebDAV 는 스토리지 권한만 봐서 웹에서 막힌 폴더도 읽고·쓰고·지울 수 있었음
        if (!$this->folderAllowed((int)$parsed['storage_id'], (string)$parsed['relative_path'], 'can_write')) {
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
        if (is_file($realPath) && $this->davLocked((int)$parsed['storage_id'], (string)$parsed['relative_path'], false)) {   // ★ (2026-10-29 22:01, 24차 점검) 잠긴 파일 덮어쓰기 거부
            http_response_code(423);
            return;
        }
        
        // Content-Length 확인
        $expectedSize = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : -1;
        // ★ (2026-10-30 00:48, 25차 — 펜닐 선택 'WebDAV 도 웹 업로드와 같게')
        //   받기 전에: 업로드 설정(허용·차단 확장자, 최대 크기)·랜섬웨어 의심 확장자 — 종전엔 WebDAV 로는 웹에서 막힌 형식·크기도 올릴 수 있었음
        $davFm = $this->davFm();
        $davName = basename(str_replace('\\', '/', (string)$parsed['relative_path']));
        if ($davFm && ($davWhy = $davFm->davUploadPrecheck($davName, $expectedSize > 0 ? $expectedSize : 0)) !== null) {
            http_response_code(403);
            return;
        }
        //   내용이 있는 기존 파일을 덮어쓰면 대량 덮어쓰기 감지에 셈(웹 덮어쓰기와 같은 기록) — 탐색기가 복사할 때 먼저 만드는 0바이트 파일을 채우는 것은 셈하지 않음
        if ($davFm && is_file($realPath) && (int)(@filesize($realPath) ?: 0) > 0) {
            $davBulk = $davFm->checkBulkOperationPublic('overwrite');
            if (empty($davBulk['allowed'])) { http_response_code(403); return; }
        }
        
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
        // ★ (2026-10-30 00:48, 25차 — 펜닐 선택 'WebDAV 도 웹 업로드와 같게') 다 받은 뒤: 최대 크기(Content-Length 없던 경우)·내용 형식(MIME)·랜섬웨어 내용 검사 — 웹 업로드 완료 때와 같음. 걸리면 기존 파일은 그대로
        if ($davFm && ($davWhy = $davFm->davUploadPostcheck($tempPath, $davName)) !== null) {
            @unlink($tempPath);
            http_response_code(403);
            return;
        }
        
        // 임시 파일을 실제 위치로 이동
        // ★ (2026-10-29 10:52, 20차 점검) 덮어쓸 기존 파일은 지우지 않고 비켜 뒀다가 성공하면 지움(실패하면 되돌림) + 사용량은 옛 크기를 빼고 더함 —
        //   종전엔 먼저 지워 옮기기·복사가 둘 다 실패하면 원본이 없어졌고, 덮어쓸 때도 새 크기만 더해 사용량이 늘기만 했음(재현: 4000B→1000B 덮어쓰기 +1000)
        $oldSize = is_file($realPath) ? (int)(@filesize($realPath) ?: 0) : 0;
        $putBak = null;
        if (is_file($realPath)) {
            $putBak = $realPath . '.davput_' . getmypid() . '_' . mt_rand(1000, 9999);
            if (!@rename($realPath, $putBak)) { $putBak = null; @unlink($realPath); }
        } elseif (file_exists($realPath)) {
            // ★ (2026-10-29 15:35, 21차 재점검) 같은 이름의 폴더 자리에는 파일을 쓰지 않음(20차 전처럼 실패) — 20차엔 폴더도 비켜 두기가 돼서
            //   폴더가 '이름.davput_…' 으로 바뀐 채 남고 그 자리에 파일이 생겼음(재현). 409 = 대상이 폴더라 충돌
            @unlink($tempPath);
            http_response_code(409);
            return;
        }
        
        if (!rename($tempPath, $realPath)) {
            // rename 실패 시 copy 시도
            if (copy($tempPath, $realPath)) {
                @unlink($tempPath);
            } else {
                @unlink($tempPath);
                if ($putBak !== null && !file_exists($realPath)) @rename($putBak, $realPath);   // 원본 되돌림
                http_response_code(500);
                return;
            }
        }
        if ($putBak !== null) @unlink($putBak);
        
        // PROPFIND 캐시 무효화
        $this->invalidatePropfindCache($parsed['storage_id'], dirname($parsed['relative_path']));
        
        // 스토리지 사용량 업데이트
        $this->storage->updateUsedSize($parsed['storage_id'], $written - $oldSize);
        // ★ (2026-10-29 22:39, 24차 점검) 파일 인덱스에도 넣음(웹 업로드와 같게) — 종전엔 드라이브로 올린 파일이 동기화 전까지 검색·인덱스 목록에 안 나왔음(2회차 재검토)
        try { $fiPut = FileIndex::getInstance(); if ($fiPut->isAvailable()) { clearstatcache(true, $realPath); $fiPut->addFile($parsed['storage_id'], trim(str_replace('\\', '/', $parsed['relative_path']), '/'), ['is_dir' => 0, 'size' => (int)@filesize($realPath), 'modified' => date('Y-m-d H:i:s', (int)@filemtime($realPath))]); } } catch (\Throwable $e) {}
        
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
    /**
     * ★ (2026-10-29 22:01, 24차 점검) 웹에서 잠근 파일(locked_files)인지 — 폴더면 그 안의 잠긴 파일까지(내부 공유 22차와 같은 기준).
     *   종전엔 WebDAV 가 잠금을 전혀 보지 않아 잠긴 파일을 드라이브에서 지우고·옮기고·덮어쓸 수 있었음
     */
    private function davLocked(int $storageId, string $rel, bool $subtree): bool {
        // ★ (2026-10-30 02:21, 26차 재검토) 겹친 '/'·'.' 마디·(Windows) 대소문자를 정리해 비교 — 종전엔 '/스토리지/lk//a.txt' 로 잠긴 파일 검사를 지났음(FileManager::isFileLocked 와 같은 기준)
        $_lkN = function ($p) { $p = str_replace('\\', '/', (string)$p); $p = preg_replace('#(^|/)\.(?=/|$)#', '$1', $p); $p = trim(preg_replace('#/{2,}#', '/', $p), '/'); return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($p) : $p; };
        $rel = $_lkN($rel);
        if ($rel === '') return false;
        foreach (($this->db->load('locked_files') ?: []) as $l) {
            if ((int)($l['storage_id'] ?? 0) !== $storageId) continue;
            $lp = $_lkN($l['path'] ?? '');
            if ($lp === $rel || ($subtree && strpos($lp, $rel . '/') === 0)) return true;
        }
        return false;
    }
    
    private function handleDelete(string $path): void {
        $parsed = $this->parsePath($path);
        // ★ (2026-10-28 19:55, 6차 점검) '/스토리지' 자체(상대 경로가 빈 것)도 최상위로 보고 거부 — 종전엔 is_root 가 false 라
        //   스토리지 폴더 전체가 휴지통으로 옮겨지거나 지워질 수 있었음(웹 화면 삭제의 최상위 차단과 같은 기준)
        // ★ (2026-10-28 20:42, 7차 점검) 문자열 비교만으로는 '/1/.'·'/1/%2e'·'/1/sub/..' 가 통과해 스토리지 전체가 지워졌음(재현) → isStorageRootTarget(실제 경로 비교)
        if ($parsed === null || $parsed['is_root'] || $this->isStorageRootTarget((int)$parsed['storage_id'], (string)$parsed['relative_path'])) {
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
        // ★ (2026-10-28 21:19, 8차 점검, 펜닐 결정) 폴더별 권한 적용(웹 화면과 같은 기준) — 종전엔 WebDAV 는 스토리지 권한만 봐서 웹에서 막힌 폴더도 읽고·쓰고·지울 수 있었음
        // ★ (2026-10-28 21:53, 9차 점검) 지우는 폴더 안의 보호 폴더까지 — 종전엔 보호 폴더를 품은 폴더를 지우면 같이 지워졌음(재현)
        if (!$this->folderAllowed((int)$parsed['storage_id'], (string)$parsed['relative_path'], 'can_delete', false, true)) {
            http_response_code(403);
            return;
        }
        
        if ($this->davLocked((int)$parsed['storage_id'], (string)$parsed['relative_path'], is_dir($realPath))) {   // ★ (2026-10-29 22:01, 24차 점검) 잠긴 파일
            http_response_code(423);
            return;
        }
        // ★ (2026-10-30 00:48, 25차 — 펜닐 선택 'WebDAV 도 웹 업로드와 같게') 대량 삭제 감지(웹 삭제와 같은 기록·한도) — 한 번 지울 때마다 하나로 셈
        if (($davFmD = $this->davFm()) !== null) {
            $davBulkD = $davFmD->checkBulkOperationPublic('delete');
            if (empty($davBulkD['allowed'])) { http_response_code(403); return; }
        }
        // 삭제할 파일/폴더 크기 계산
        $delWasDir = is_dir($realPath);
        $deleteSize = $delWasDir ? $this->getDirectorySize($realPath) : (int)@filesize($realPath);
        
        // 휴지통으로 이동
        $trashResult = $this->moveToTrash($parsed['storage_id'], $parsed['relative_path'], $realPath);
        
        if (!$trashResult) {
            // ★ (2026-10-29 18:09, 22차 점검) 휴지통으로 못 옮기면 지우지 않고 실패(웹 삭제와 같음) — 종전엔 휴지통 없이 바로 영구 삭제했고(복원 불가), 그마저 실패해도 204·사용량 차감이었음
            http_response_code(500);
            return;
        }
        
        // 스토리지 사용량 업데이트 (감소)
        if ($deleteSize > 0) {
            $this->storage->updateUsedSize($parsed['storage_id'], -$deleteSize);
        }
        $this->cleanupSharesForPath((int)$parsed['storage_id'], (string)$parsed['relative_path'], $delWasDir);   // ★ (2026-10-29 18:09, 22차 점검)
        // ★ (2026-10-29 22:06, 24차 점검) 파일 인덱스에서도 지움(웹 삭제와 같게) — 종전엔 WebDAV 로 지운 항목이 검색·인덱스 목록에 남았음
        try { $fiDel = FileIndex::getInstance(); if ($fiDel->isAvailable()) $fiDel->removeFolder((int)$parsed['storage_id'], trim(str_replace('\\', '/', (string)$parsed['relative_path']), '/')); } catch (\Throwable $e) {}
        
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
            // ★ (2026-10-29 18:09, 22차 점검) 복사가 하나라도 실패하면 만들다 만 휴지통 사본을 지우고 실패(원본 그대로), 파일 원본을 못 지우면(사용 중) 사본을 지우고 실패 —
            //   종전엔 copyDirectory 가 늘 성공이라 일부만 복사된 채 원본을 지웠고, 파일은 원본이 남아도 성공(휴지통 기록·사용량 차감)이었음(20차 FileManager 와 같은 기준)
            if ($isDir) {
                if (!$this->copyDirectory($fullPath, $trashPath)) {
                    if (is_dir($trashPath)) $this->deleteDirectory($trashPath);
                    return false;
                }
                $this->deleteDirectory($fullPath);
            } else {
                if (!@copy($fullPath, $trashPath)) {
                    @unlink($trashPath);
                    return false;
                }
                if (!@unlink($fullPath)) {
                    @unlink($trashPath);
                    return false;
                }
            }
        }
        
        // 휴지통 DB에 기록
        // ★ (2026-10-29 10:15, 20차 점검) 잠금 안에서 최신 목록에 추가 — 종전엔 읽고-더하고-저장이라 동시 삭제 때 기록이 덮여 사라졌음(FileManager 와 같음)
        $trashRec = [
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
        if (!$this->db->atomicUpdate('trash', function ($t) use ($trashRec) { $t = is_array($t) ? array_values($t) : []; $t[] = $trashRec; return $t; })) {
            $trash = $this->db->load('trash') ?: []; $trash[] = $trashRec; $this->db->save('trash', array_values($trash));
        }
        
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
        // ★ (2026-10-28 21:19, 8차 점검, 펜닐 결정) 폴더별 권한 적용(웹 화면과 같은 기준) — 종전엔 WebDAV 는 스토리지 권한만 봐서 웹에서 막힌 폴더도 읽고·쓰고·지울 수 있었음
        if (!$this->folderAllowed((int)$parsed['storage_id'], (string)$parsed['relative_path'], 'can_write')) {
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
            // ★ (2026-10-29 22:39, 24차 점검) 새 폴더도 인덱스에(웹 폴더 만들기와 같게)
            try { $fiMk = FileIndex::getInstance(); if ($fiMk->isAvailable()) $fiMk->addFile($parsed['storage_id'], trim(str_replace('\\', '/', $parsed['relative_path']), '/'), ['is_dir' => 1, 'size' => 0, 'modified' => date('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
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
        // ★ (2026-10-28 19:55, 6차 점검) 스토리지 최상위('/1')를 옮기거나, 최상위를 대상으로 덮어쓰지 못하게(삭제와 같은 기준)
        //   ★ (2026-10-28 20:42, 7차 점검) '.'·'..' 표기로 우회되던 것 → 실제 경로 비교(isStorageRootTarget)
        if ($this->isStorageRootTarget((int)$srcParsed['storage_id'], (string)$srcParsed['relative_path']) || $this->isStorageRootTarget((int)$destParsed['storage_id'], (string)$destParsed['relative_path'])) {
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
        // ★ (2026-10-28 21:19, 8차 점검, 펜닐 결정) 폴더별 권한 적용(웹 화면과 같은 기준) — 종전엔 WebDAV 는 스토리지 권한만 봐서 웹에서 막힌 폴더도 읽고·쓰고·지울 수 있었음
        //   원본: 읽기(이동이면 쓰기·삭제도 — 웹 이동과 같은 기준), 대상: 쓰기
        // ★ (2026-10-28 21:53, 9차 점검) 원본은 자신 + 안의 보호 폴더까지(복사로 보호 폴더 내용이 빠져나가던 것, 재현).
        //   같은 폴더 안 이름 바꾸기(탐색기 이름 바꾸기 = MOVE)는 웹 이름 바꾸기와 같이 쓰기만(펜닐 결정), 다른 폴더로 옮기기는 쓰기+삭제
        $mcSid = (int)$srcParsed['storage_id']; $mcRel = (string)$srcParsed['relative_path'];
        $mdSid = (int)$destParsed['storage_id']; $mdRel = (string)$destParsed['relative_path'];
        // ★ (2026-10-28 22:39, 10차 점검) 윈도우는 대소문자 무시로 비교(같은 폴더인데 표기만 달라 '다른 폴더 이동' 으로 잘못 보지 않게) — 삭제 권한이 실제로 걸리게 되면서 필요
        $mcDirOf = function ($r) { $d = dirname(trim(str_replace('\\', '/', $r), '/')); $d = ($d === '.' ? '' : $d); return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($d) : $d; };
        $mcSameDir = $isMove && $mcSid === $mdSid && $mcDirOf($mcRel) === $mcDirOf($mdRel);
        if (!$this->folderAllowed($mcSid, $mcRel, 'can_read', false, true)
            || ($isMove && !$this->folderAllowed($mcSid, $mcRel, 'can_write', false, true))
            || ($isMove && !$mcSameDir && !$this->folderAllowed($mcSid, $mcRel, 'can_delete', false, true))
            || !$this->folderAllowed($mdSid, $mdRel, 'can_write')) {
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
        // ★ (2026-10-28 17:42, 소스 점검 3단계) 파일을 실행 확장자·설정 파일 이름으로 옮기거나 복사하지 못하게(PUT 과 같은 기준)
        if (!is_dir($srcRealPath) && $this->isBlockedDavName($destParsed['relative_path'])) {
            http_response_code(403);
            return;
        }
        
        $overwrite = ($_SERVER['HTTP_OVERWRITE'] ?? 'T') === 'T';
        $destExists = file_exists($destRealPath);
        // ★ (2026-10-29 22:01, 24차 점검) 잠긴 파일 — 옮기는 원본(폴더면 안의 것까지)과 덮어써질 대상
        if (($isMove && $this->davLocked((int)$srcParsed['storage_id'], (string)$srcParsed['relative_path'], is_dir($srcRealPath)))
            || ($destExists && $this->davLocked((int)$destParsed['storage_id'], (string)$destParsed['relative_path'], is_dir($destRealPath)))) {
            http_response_code(423);
            return;
        }
        
        if ($destExists && !$overwrite) {
            http_response_code(412); // Precondition Failed
            return;
        }
        // ★ (2026-10-28 21:53, 9차 점검) 덮어쓰면 기존 대상(폴더면 안의 내용까지)이 지워지므로 대상 자신 + 그 아래 보호 폴더의 삭제·쓰기 권한 —
        //   종전(8차)엔 대상이 든 폴더의 쓰기만 봐서 보호 폴더 자리로 덮어쓰기 이동하면 보호 폴더가 지워졌음(재현, 8차가 만든 빈틈)
        if ($destExists && (!$this->folderAllowed($mdSid, $mdRel, 'can_delete', true, true) || !$this->folderAllowed($mdSid, $mdRel, 'can_write', true, true))) {
            http_response_code(403);
            return;
        }
        
        // ★ (2026-10-30 00:48, 25차 — 펜닐 선택 'WebDAV 도 웹 업로드와 같게') 파일을 옮기거나 복사해 생기는 이름도 랜섬웨어 의심 확장자 검사(웹 업로드와 같은 목록) — 랜섬웨어가 드라이브에서 이름을 '.locked' 등으로 바꾸는 경로.
        //   내용이 있는 대상을 덮어쓰면 대량 덮어쓰기 감지에 셈(웹 이동·복사 덮어쓰기와 같음)
        if (($davFmM = $this->davFm()) !== null) {
            if (!is_dir($srcRealPath)) {
                $davRc = $davFmM->checkRansomwarePublic(basename(str_replace('\\', '/', (string)$destParsed['relative_path'])));
                if (empty($davRc['allowed'])) { http_response_code(403); return; }
            }
            if ($destExists && (is_dir($destRealPath) || (int)(@filesize($destRealPath) ?: 0) > 0)) {
                $davBulkM = $davFmM->checkBulkOperationPublic('overwrite');
                if (empty($davBulkM['allowed'])) { http_response_code(403); return; }
            }
        }
        
        // 버그 방어: 동일한 원본/대상 경로 (0바이트 덮어쓰기 위험)
        $srcReal = realpath($srcRealPath);
        $destReal = $destExists ? realpath($destRealPath) : null;
        if ($srcReal !== false && $destReal !== false && $srcReal === $destReal) {
            http_response_code(403); // Forbidden - 자기 자신으로 이동/복사 불가
            return;
        }
        
        // ★ (2026-10-28 19:55, 6차 점검) 폴더를 자기 안으로 복사·이동(끝없이 커지거나 원본 손상)하거나, 원본을 품은 폴더로 덮어쓰기(덮어쓰면서
        //   원본까지 지워짐)는 거부 — 웹 화면 복사·이동의 같은 차단과 같은 기준
        if ($srcReal !== false && function_exists('isSubPath')) {
            $destCmp = $destReal ?: (realpath(dirname($destRealPath)) ? realpath(dirname($destRealPath)) . DIRECTORY_SEPARATOR . basename($destRealPath) : null);
            if ($destCmp && is_dir($srcReal) && isSubPath($destCmp, $srcReal)) { http_response_code(403); return; }
            if ($destExists && $destReal && isSubPath($srcReal, $destReal)) { http_response_code(403); return; }
        }
        
        // 대상 상위 폴더 확인
        $destDir = dirname($destRealPath);
        if (!is_dir($destDir)) {
            http_response_code(409);
            return;
        }
        
        // ★ (2026-10-29 04:14, 17차) 사용량(used_size) 반영 — 종전엔 WebDAV 복사·이동이 사용량에 안 잡혔음(복사로 늘어난 용량, 다른 스토리지로 옮긴 용량, 덮어써 지운 용량).
        //   전후 크기 차이로 계산. 같은 스토리지 안 이동은 덮어쓰기가 없으면 변화가 없어 크기를 재지 않음
        $mcSizeOf = function ($p) { clearstatcache(true, $p); return is_dir($p) ? $this->getDirectorySize($p) : (is_file($p) ? (int)(@filesize($p) ?: 0) : 0); };
        $mcMeasure = !($isMove && $mcSid === $mdSid && !$destExists);
        // ★ (2026-10-29 07:45, 18차) 양쪽 다 홈이면(홈은 used_size 를 안 씀) 크기를 재지 않음 — 큰 폴더 복사 때 폴더 전체를 두 번 훑던 낭비
        $mcHome = function ($sid) { $st = $this->storage->getStorageById((int)$sid); return $st && ($st['storage_type'] ?? '') === 'home'; };
        if ($mcMeasure && $mcHome($mcSid) && $mcHome($mdSid)) $mcMeasure = false;
        $mcDestBefore = ($mcMeasure && $destExists) ? $mcSizeOf($destRealPath) : 0;
        $mcSrcBefore = ($mcMeasure && $isMove && $mcSid !== $mdSid) ? $mcSizeOf($srcRealPath) : 0;
        
        // ★ (2026-10-29 10:09, 20차 점검) 대상 스토리지 용량 확인(PUT 과 같은 기준) — 종전엔 COPY·다른 스토리지로 MOVE 가 한도를 넘겨도 됐음(재현).
        //   같은 스토리지 안 이동은 늘지 않아 건너뜀. 필요량 = 원본 크기 − 덮어써 지워질 대상 크기
        // ★ (2026-10-29 15:35, 21차 재점검) 대상에 용량 한도가 있을 때만 크기를 잼 — 20차엔 한도가 없어도 원본 폴더 크기를 재서, 18차에 없앤
        //   '홈→홈 큰 폴더 복사 때 폴더를 한 번 더 훑기' 가 다시 생겼음(결과는 같고 느려짐)
        $mdInfo = $this->storage->getStorageById((int)$mdSid);
        $mdHasQuota = $mdInfo && ((($mdInfo['storage_type'] ?? '') === 'home')
            ? ((int)(($this->db->find('users', ['id' => (int)($this->currentUser['id'] ?? 0)]) ?: [])['quota'] ?? 0) > 0)
            : ((int)($mdInfo['quota'] ?? 0) > 0));
        if ($mdHasQuota && !($isMove && $mcSid === $mdSid)) {
            $qSrc = ($isMove && $mcMeasure) ? $mcSrcBefore : $mcSizeOf($srcRealPath);
            $qDst = $destExists ? (($mcMeasure) ? $mcDestBefore : $mcSizeOf($destRealPath)) : 0;
            $qNeed = $qSrc - $qDst;
            if ($qNeed > 0 && empty($this->checkQuota($mdSid, $qNeed)['allowed'])) {
                http_response_code(507); // Insufficient Storage
                return;
            }
        }
        
        // ★ (2026-10-29 18:09, 22차 점검) 덮어쓸 기존 대상은 먼저 지우지 않고 비켜 뒀다가(같은 폴더의 숨김 이름) 성공하면 지우고 실패하면 되돌림(PUT 20차·웹 복사/이동과 같은 방식) —
        //   종전엔 대상을 먼저 영구 삭제해 이동이 실패하면 대상만 사라졌고, 폴더 위 COPY 는 지우지 않고 합쳐 옛 파일이 남았음
        $owAside = null;
        if ($destExists) {
            $owAside = dirname($destRealPath) . DIRECTORY_SEPARATOR . '.davow_' . getmypid() . '_' . mt_rand(1000, 9999) . '_' . basename($destRealPath);
            if (!@rename($destRealPath, $owAside)) {
                http_response_code(423);   // 비켜 둘 수 없음(사용 중 등) — 아무것도 바꾸지 않음
                return;
            }
        }
        if ($isMove) {
            // 이동
            $success = @rename($srcRealPath, $destRealPath);
            // ★ (2026-10-29 18:09, 22차 점검) 다른 드라이브 사이 폴더 이동은 rename 이 늘 실패 → 복사 후 원본 삭제(복사가 다 되면만 원본을 지움)
            if (!$success && is_dir($srcRealPath) && !file_exists($destRealPath)) {
                if ($this->copyDirectory($srcRealPath, $destRealPath)) {
                    $this->deleteDirectory($srcRealPath);
                    $success = true;
                } elseif (is_dir($destRealPath)) {
                    $this->deleteDirectory($destRealPath);
                }
            }
        } else {
            // 복사
            if (is_dir($srcRealPath)) {
                $success = $this->copyDirectory($srcRealPath, $destRealPath);
                if (!$success && is_dir($destRealPath)) $this->deleteDirectory($destRealPath);   // ★ (2026-10-29 18:09, 22차 점검) 만들다 만 사본 지움
            } else {
                $success = @copy($srcRealPath, $destRealPath);
            }
        }
        if ($owAside !== null) {
            if ($success) {
                if (is_dir($owAside)) $this->deleteDirectory($owAside); else @unlink($owAside);
            } else {
                if (file_exists($destRealPath)) { if (is_dir($destRealPath)) $this->deleteDirectory($destRealPath); else @unlink($destRealPath); }
                @rename($owAside, $destRealPath);   // 원래 대상 되돌림
            }
        }
        
        if ($mcMeasure) {
            if ($isMove && $mcSid === $mdSid) {
                // 같은 스토리지 안 이동: 옮긴 것은 그대로, 덮어써 지운 대상만큼 줄어듦
                if ($mcDestBefore > 0 && ($success || !file_exists($destRealPath))) {   // 실패했어도 대상이 이미 지워졌으면 반영
                    $this->storage->updateUsedSize($mdSid, -$mcDestBefore);
                }
            } else {
                if ($isMove) $this->storage->updateUsedSize($mcSid, $mcSizeOf($srcRealPath) - $mcSrcBefore);
                $mcDestDelta = $mcSizeOf($destRealPath) - $mcDestBefore;
                if ($mcDestDelta !== 0) $this->storage->updateUsedSize($mdSid, $mcDestDelta);
            }
        }
        
        if ($success) {
            if ($isMove) $this->cleanupSharesForPath($mcSid, $mcRel, is_dir($destRealPath));   // ★ (2026-10-29 18:09, 22차 점검) 옮겨져 없어진 원본 경로의 공유 정리
            if ($isMove && is_dir($destRealPath)) { try { $this->storage->moveFolderPermissions($mcSid, $mcRel, $mdSid, $mdRel); } catch (\Throwable $e) {} }   // ★ (2026-10-30 01:52, 26차 점검) 폴더 권한 규칙도 옮긴 자리로(탐색기 이름 바꾸기 = MOVE)
            // ★ (2026-10-29 22:07, 24차 점검) 파일 인덱스도 갱신(웹 이동·복사와 같게) — 종전엔 옮긴 원본이 인덱스에 남고 새 위치는 동기화 전까지 안 잡혔음
            try {
                $fiMv = FileIndex::getInstance();
                if ($fiMv->isAvailable()) {
                    $fiSrc = trim(str_replace('\\', '/', $mcRel), '/'); $fiDst = trim(str_replace('\\', '/', $mdRel), '/');
                    if ($isMove && $mcSid === $mdSid) $fiMv->moveFile($mcSid, $fiSrc, $fiDst);
                    else {
                        if ($isMove) $fiMv->removeFolder($mcSid, $fiSrc);
                        $fiDstBase = (string)$this->storage->getRealPath($mdSid);
                        if (is_dir($destRealPath)) { $fiMv->removeFolder($mdSid, $fiDst); $fiMv->indexFolder($mdSid, $fiDstBase, $fiDst); }
                        else $fiMv->addFile($mdSid, $fiDst, ['is_dir' => 0, 'size' => (int)@filesize($destRealPath), 'modified' => date('Y-m-d H:i:s', (int)@filemtime($destRealPath))]);
                    }
                }
            } catch (\Throwable $e) {}
            http_response_code($destExists ? 204 : 201);
        } else {
            http_response_code(500);
        }
    }
    
    /**
     * ★ (2026-10-29 18:09, 22차 점검) 지우거나 옮겨 없어진 경로의 외부 공유 링크·내부 공유 정리(웹 FileManager::cleanupSharesForPath 와 같은 기준) —
     *   종전엔 WebDAV 로 지우거나 옮겨도 공유가 남아, 같은 경로에 새 파일이 생기면 옛 링크로 그 파일이 공개됐음
     */
    private function cleanupSharesForPath(int $storageId, string $relativePath, bool $isDir): void {
        try {
            $rel = trim(str_replace('\\', '/', $relativePath), '/');
            if ($rel === '') return;
            $hit = function ($fp) use ($rel, $isDir) {
                $fp = trim(str_replace('\\', '/', (string)$fp), '/');
                return $fp === $rel || ($isDir && strpos($fp, $rel . '/') === 0);
            };
            $gone = [];
            foreach (($this->db->load('shares') ?: []) as $sh) {
                if ((int)($sh['storage_id'] ?? 0) === $storageId && $hit($sh['file_path'] ?? '')) $gone[] = $sh;
            }
            if ($gone) {
                try { if (class_exists('ShareManager')) { $sm = new ShareManager(); foreach ($gone as $sh) $sm->cleanShareCacheByShare($sh); } } catch (\Throwable $e) {}
                foreach ($gone as $sh) $this->db->delete('shares', ['id' => $sh['id']]);
            }
            foreach (($this->db->load('internal_shares') ?: []) as $ish) {
                if ((int)($ish['storage_id'] ?? 0) === $storageId && $hit($ish['file_path'] ?? '')) $this->db->delete('internal_shares', ['id' => $ish['id']]);
            }
        } catch (\Throwable $e) {
            error_log('[WebDAV] cleanupSharesForPath: ' . $e->getMessage());
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
        
        // ★ (2026-10-29 18:09, 22차 점검) 만들기·복사 실패를 결과에 반영(하나라도 실패하면 false, 나머지는 계속 복사) — 종전엔 늘 true 라 휴지통 이동·COPY 가 일부 실패를 성공으로 처리했음
        if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
            return false;
        }
        
        $items = @scandir($src);
        if ($items === false) return false;
        $ok = true;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $srcPath = $src . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($srcPath)) {
                if (!$this->copyDirectory($srcPath, $dstPath)) $ok = false;
            } else {
                if (!@copy($srcPath, $dstPath)) $ok = false;
            }
        }
        
        return $ok;
    }
}
