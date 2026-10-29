<?php
/**
 * Auth - 사용자 인증 관리 (JSON 기반) + 보안 기능
 */
class Auth {
    private $db;
    private static $user = null;
    
    public function __construct() {
        $this->db = JsonDB::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * HTTPS 연결 여부 확인 (리버스 프록시 지원)
     */
    private function isHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }
    
    public function login(string $username, string $password, bool $remember = false): array {
        try {
            $ip = $this->getClientIP();
            
            // IP/국가 제한 체크
            $ipCheck = $this->checkIPRestriction($ip);
            if (!$ipCheck['allowed']) {
                $this->logLogin($username, false, $ip, $ipCheck['reason']);
                return ['success' => false, 'error' => $ipCheck['reason']];
            }
            
            // ★ (2026-10-29 18:45, 22차 점검) 잠금 횟수는 실제 계정 아이디로 셈 — 숫자 아이디는 DB 비교가 숫자로 돼('020231234'·'20231234.0' 도 같은 계정)
            //   종전엔 입력한 글자 그대로 세어, 표기만 바꾸면 잠금 없이 계속 시도할 수 있었음. 없는 아이디는 종전처럼 입력값
            $lockUser = $this->db->find('users', ['username' => $username]);
            $lockName = $lockUser ? (string)$lockUser['username'] : $username;
            // 브루트포스 체크
            if ($this->isLockedOut($lockName, $ip)) {
                $this->logLogin($username, false, $ip, __('api_log_account_locked', '계정 잠금'));
                return ['success' => false, 'error' => __('api_err_login_locked', '로그인 시도 횟수 초과. 잠시 후 다시 시도하세요.')];
            }
            
            // 먼저 사용자 조회 (is_active 체크 없이)
            $user = $this->db->find('users', ['username' => $username]);
            
            if (!$user || !password_verify($password, $user['password'])) {
                $this->recordFailedAttempt($lockName, $ip);
                // 사용자가 존재하면 DB의 username 사용, 아니면 입력값 사용
                $logUsername = $user ? $user['username'] : $username;
                $this->logLogin($logUsername, false, $ip, __('api_log_invalid_creds', '잘못된 인증정보'));
                return ['success' => false, 'error' => __('api_err_invalid_credentials', '아이디 또는 비밀번호가 올바르지 않습니다.')];
            }
            
            // 계정 상태 체크
            $status = $user['status'] ?? 'active';
            if ($status !== 'active') {
                // 정지 상태인 경우 기간 체크
                if ($status === 'suspended') {
                    $suspendUntil = $user['suspend_until'] ?? null;
                    $suspendFrom = $user['suspend_from'] ?? null;
                    $suspendReason = $user['suspend_reason'] ?? '';
                    
                    // 종료일이 지났으면 자동 활성화
                    if ($suspendUntil && strtotime($suspendUntil) < strtotime('today')) {
                        $this->db->update('users', ['id' => $user['id']], [
                            'status' => 'active',
                            'suspend_from' => null,
                            'suspend_until' => null,
                            'suspend_reason' => null
                        ]);
                        // 활성화되었으니 계속 진행
                    } else {
                        // 아직 정지 기간
                        $periodMsg = '';
                        if ($suspendFrom && $suspendUntil) {
                            $periodMsg = "\n" . __f('suspend_period', ['from' => $suspendFrom, 'until' => $suspendUntil]);
                        } elseif ($suspendUntil) {
                            $periodMsg = "\n" . __f('suspend_until', ['until' => $suspendUntil]);
                        }
                        $reasonMsg = $suspendReason ? "\n" . __f('suspend_reason', ['reason' => $suspendReason]) : '';
                        
                        $this->logLogin($user['username'], false, $ip, __('api_log_account_suspended', '계정 정지'));
                        return ['success' => false, 'error' => __('account_suspended') . $periodMsg . $reasonMsg];
                    }
                } elseif ($status === 'pending') {
                    $this->logLogin($user['username'], false, $ip, __('api_log_pending_approval', '승인 대기'));
                    return ['success' => false, 'error' => __('account_pending')];
                } else {
                    $this->logLogin($user['username'], false, $ip, __('account_status_prefix') . $status);
                    return ['success' => false, 'error' => __('api_err_account_cannot_login', '로그인할 수 없는 계정입니다.')];
                }
            }
            
            // is_active 체크 (하위 호환)
            if (!($user['is_active'] ?? 1)) {
                $this->logLogin($user['username'], false, $ip, __('api_log_inactive', '비활성 계정'));
                return ['success' => false, 'error' => __('api_err_account_inactive', '비활성화된 계정입니다.')];
            }
            
            // 2FA 활성화 체크
            if (!empty($user['2fa_enabled'])) {
                // 2FA 인증 대기 상태로 설정
                $_SESSION['2fa_pending_user'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'remember' => $remember,
                    'started_at' => time()   // ★ (2026-10-28 18:22) 대기 만료(10분) 기준
                ];
                
                return [
                    'success' => true,
                    '2fa_required' => true,
                    'message' => __('api_2fa_required', '2단계 인증이 필요합니다.')
                ];
            }
            
            // 성공 - 실패 기록 초기화
            $this->clearFailedAttempts($lockName, $ip);
            
            // Session Fixation 방지: 세션 ID 재생성
            // ★ session_regenerate_id 전에 sessions DB에서 이전 session_id 제거
            //   (PHP 세션 ID는 X→Y로 바뀌지만 sessions DB에는 X 잔존 → 유령 세션 방지)
            if (isset($_SESSION['user_id'])) {
                $this->removeSession((int)$_SESSION['user_id'], session_id());
            }
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['last_activity'] = time();
            
            $this->db->update('users', ['id' => $user['id']], ['last_login' => date('Y-m-d H:i:s')]);
            
            // Remember Me 처리
            if ($remember && defined('REMEMBER_ME_ENABLED') && REMEMBER_ME_ENABLED) {
                $this->createRememberToken($user['id']);
                $_SESSION['remember_me'] = true;
            }
            
            // 세션 기록
            $this->recordSession($user['id'], $ip);
            
            // 로그인 로그 (DB에 저장된 정확한 username 사용)
            $this->logLogin($user['username'], true, $ip, __('login_success'));
            
            // 사용자 폴더 자동 생성
            if (defined('AUTO_CREATE_USER_FOLDER') && AUTO_CREATE_USER_FOLDER) {
                $this->ensureUserFolder($user);
            }
            
            unset($user['password']);
            return ['success' => true, 'user' => $user];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => __('api_err_login_failed_server', '로그인 처리 중 오류가 발생했습니다.')];
        }
    }
    
    // 사용자 개인 폴더 생성 및 스토리지 등록
    private function ensureUserFolder(array $user): void {
        if (!defined('USER_FILES_ROOT')) return;
        
        $baseRoot = str_replace('\\', '/', rtrim(USER_FILES_ROOT, '/\\'));
        $userPath = $baseRoot . '/' . $user['username'];
        $realPath = str_replace('/', DIRECTORY_SEPARATOR, $userPath);
        
        if (!is_dir($realPath)) {
            @mkdir($realPath, 0755, true);
        }
        
        $storages = $this->db->load('storages');
        $homeStorage = null;
        
        foreach ($storages as $s) {
            if (($s['storage_type'] ?? '') === 'home' && ($s['owner_id'] ?? 0) == $user['id']) {
                $homeStorage = $s;
                break;
            }
        }
        
        if (!$homeStorage) {
            // home 타입은 path를 저장하지 않음 (동적 계산)
            $storageId = $this->db->insert('storages', [
                'name' => __('api_home_storage_name', '내 파일'),
                'path' => '',  // Storage::getHomeStoragePath()에서 동적 계산
                'storage_type' => 'home',
                'owner_id' => $user['id'],
                'description' => $user['username'] . __('api_home_storage_desc', '의 개인 폴더'),
                'icon' => '🏠',
                'is_active' => 1,
                'created_by' => $user['id'],
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            $this->db->insert('permissions', [
                'storage_id' => $storageId,
                'user_id' => $user['id'],
                'can_read' => 1,
                'can_write' => 1,
                'can_delete' => 1,
                'can_share' => 1
            ]);
        }
    }
    
    public function logout(): void {
        $userId = $this->getUserId();
        
        if ($userId && isset($_COOKIE['remember_token'])) {
            $this->deleteRememberToken($_COOKIE['remember_token']);
            setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        
        if ($userId) {
            $this->removeSession($userId, session_id());
        }
        
        session_destroy();
        self::$user = null;
    }
    
    private static $sessionTimeoutChecked = false;
    
    public function isLoggedIn(): bool {
        if (!isset($_SESSION['user_id'])) return false;
        
        // 세션 타임아웃 체크 (요청당 1회만)
        if (!self::$sessionTimeoutChecked) {
            self::$sessionTimeoutChecked = true;

            // ★ (2026-10-29 03:11, 16차) 세션 강제 종료·계정 정지/비활성을 즉시 반영 — 종전엔 로그인 뒤 $_SESSION['user_id'] 만 봐서,
            //   '세션 종료' 를 눌러도(기록만 지워져 recordSession 이 다시 넣음) 끊기지 않고, 정지·비활성 처리한 계정의 기존 세션도 계속 살아 있었음(재현)
            if ($this->isSessionRevoked(session_id()) || !$this->isAccountUsable((int)$_SESSION['user_id'])) {
                $uid0 = $_SESSION['user_id'] ?? null;
                if ($uid0) $this->removeSession((int)$uid0, session_id());
                // ★ (2026-10-29 09:57, 20차 점검) 끊긴 기기의 '로그인 유지' 토큰도 폐기 — 종전엔 세션만 끊겨 같은 요청에서 토큰으로 바로 다시 로그인됐음(재현).
                //   토큰은 세션과 연결돼 있지 않아, 이 요청이 들고 온 쿠키(=끊긴 그 기기의 것)만 지움. 다른 기기의 로그인 유지는 그대로
                if (isset($_COOKIE['remember_token'])) {
                    $this->deleteRememberToken((string)$_COOKIE['remember_token']);
                    setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
                    unset($_COOKIE['remember_token']);
                }
                session_unset();
                session_destroy();
                if (session_status() === PHP_SESSION_NONE) session_start();
                self::$user = null;
                return false;
            }

            // 로그인 유지 사용자는 타임아웃 무시
            if (empty($_SESSION['remember_me'])) {
                $settings = $this->db->load('settings');
                $timeoutMin = $settings['session_timeout'] ?? 60; // 기본 60분
                
                if ($timeoutMin > 0 && isset($_SESSION['last_activity'])) {
                    if (time() - $_SESSION['last_activity'] > $timeoutMin * 60) {
                        // 세션 타임아웃: remember_token은 유지하고 세션만 정리
                        // (logout()을 호출하면 remember_token까지 삭제되어 로그인 유지 불가)
                        $userId = $_SESSION['user_id'] ?? null;
                        if ($userId) {
                            $this->removeSession($userId, session_id());
                        }
                        session_unset();
                        session_destroy();
                        if (session_status() === PHP_SESSION_NONE) {
                            session_start();
                        }
                        self::$user = null;
                        return false;
                    }
                }
            }
            
            // 활동 시간 갱신
            $_SESSION['last_activity'] = time();
            
            // ★ sessions DB의 last_activity도 갱신 (펜닐님 진단: 세션 관리 화면에서 안 보이는 문제)
            //   recordSession은 로그인 시점만 호출 → Remember Me 자동 로그인 시 DB 미기록
            //   isLoggedIn에서 한 번 더 호출 → DB 기록 보장 + last_activity 갱신
            //   이미 있으면 last_activity만 갱신, 없으면 추가
            $this->recordSession($_SESSION['user_id'], $this->getClientIP());   // ★ (2026-10-29 18:45, 22차 점검) 신뢰 프록시 규칙(getClientIP)으로 — 종전엔 요청마다 프록시 주소로 덮어썼음(로그인 때와 다름)
        }
        
        return isset($_SESSION['user_id']);
    }
    
    public function getUser(): ?array {
        if (!$this->isLoggedIn()) return null;
        
        if (self::$user === null) {
            $user = $this->db->find('users', ['id' => $_SESSION['user_id']]);
            if ($user) {
                unset($user['password'], $user['2fa_secret'], $user['2fa_backup_codes']);
                self::$user = $user;
            }
        }
        return self::$user;
    }
    
    public function getUserId(): ?int {
        return $_SESSION['user_id'] ?? null;
    }
    
    public function isAdmin(): bool {
        return ($this->getUser()['role'] ?? '') === 'admin';
    }
    
    public function isSubAdmin(): bool {
        return ($this->getUser()['role'] ?? '') === 'sub_admin';
    }
    
    public function isAdminOrSubAdmin(): bool {
        $role = $this->getUser()['role'] ?? '';
        return $role === 'admin' || $role === 'sub_admin';
    }
    
    // 부관리자가 특정 메뉴 권한을 가지고 있는지 확인
    public function hasAdminPerm(string $perm): bool {
        if ($this->isAdmin()) return true;
        if (!$this->isSubAdmin()) return false;
        
        $user = $this->getUser();
        $perms = $user['admin_perms'] ?? [];
        return is_array($perms) && in_array($perm, $perms);
    }
    
    public function requireLogin(): void {
        if (!$this->isLoggedIn()) {
            // 세션 만료됐지만 remember_token이 있으면 복구 시도
            if (defined('REMEMBER_ME_ENABLED') && REMEMBER_ME_ENABLED && isset($_COOKIE['remember_token'])) {
                if ($this->checkRememberToken()) {
                    return; // 복구 성공
                }
            }
            http_response_code(401);
            echo json_encode(['error' => __('api_login_required')]);
            exit;
        }
    }
    
    public function requireAdmin(): void {
        $this->requireLogin();
        if (!$this->isAdminOrSubAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => __('api_admin_required')]);
            exit;
        }
    }
    
    /**
     * 특정 관리 권한이 필요한 경우
     * admin은 모든 권한 보유, sub_admin은 admin_perms에 해당 권한이 있어야 함
     * @param string $perm 필요한 권한 키 (storages, users, logins, shares 등)
     */
    public function requireAdminPerm(string $perm): void {
        $this->requireLogin();
        if (!$this->isAdminOrSubAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => __('api_admin_required')]);
            exit;
        }
        // admin은 모든 권한 보유
        if ($this->isAdmin()) return;
        // sub_admin은 해당 권한이 있는지 확인
        if (!$this->hasAdminPerm($perm)) {
            http_response_code(403);
            echo json_encode(['error' => __('api_err_no_sub_admin_perm', '해당 기능에 대한 권한이 없습니다.')]);
            exit;
        }
    }
    
    // 실제 관리자만 필요한 경우
    public function requireRealAdmin(): void {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => __('api_admin_required')]);
            exit;
        }
    }
    
    // 사용자 관리
    /**
     * ★ (2026-10-30 01:42, 26차 — 24차 결정 필요 '메일 중복' 자동 선택: 거부) 다른 계정이 이미 쓰는 메일인지(대소문자·앞뒤 공백 무시) — 본인 메일 변경(24차)과 같은 기준을
     *   관리자 사용자 추가·수정, 가입, 삭제 사용자 복원에도. 메일로 계정을 찾는 아이디 찾기·비밀번호 찾기·SSO 계정 연결이 엉뚱한 계정을 고르지 않게
     */
    public static function emailInUse(JsonDB $db, string $email, int $exceptId = 0): bool {
        $email = trim($email);
        if ($email === '') return false;
        foreach (($db->load('users') ?: []) as $ou) {
            if ((int)($ou['id'] ?? 0) !== $exceptId && strcasecmp(trim((string)($ou['email'] ?? '')), $email) === 0) return true;
        }
        return false;
    }

    /**
     * ★ (2026-10-29 17:46, 22차 점검) 아이디 중복·예약어 공통 검사 — 홈 폴더는 USER_FILES_ROOT/아이디 이고 Windows 는 대소문자를 구분하지 않아,
     *   'Viewer'·'VIEWER' 가 'viewer' 와 같은 폴더를 쓰게 됐고('viewer ' 도 앞뒤 공백 그대로 저장됐음 — 재현), '_deleted' 는 삭제된 사용자 보관 폴더가 홈이 됐음.
     *   반환: null = 사용 가능 / 'exists' = 이미 있음(대소문자 무시) / 'reserved' = 쓸 수 없는 이름
     */
    public static function usernameProblem(JsonDB $db, string $username): ?string {
        $u = strtolower(trim($username));
        if ($u === '' || trim($u, '.') === '' || $u === '_deleted') return 'reserved';
        foreach (($db->load('users') ?: []) as $x) {
            $xu = strtolower(trim((string)($x['username'] ?? '')));
            if ($xu === $u) return 'exists';
            // ★ (2026-10-29 21:59, 24차 점검) 숫자 아이디는 DB 찾기(JsonDB::matchValue)가 숫자로 비교해 '020231234'·'20231234' 를 같은 계정으로 봄 — 같은 기준으로 중복 처리
            //   (종전엔 따로 만들어져, 나중 계정은 로그인하면 앞 계정을 찾아 비밀번호가 늘 틀렸다고 나왔음)
            if (is_numeric($xu) && is_numeric($u) && (int)$xu === (int)$u) return 'exists';
        }
        return null;
    }

    public function createUser(array $data): array {
        if (empty($data['username']) || empty($data['password'])) {
            return ['success' => false, 'error' => __('id_pw_required')];
        }
        
        $username = trim($data['username']);
        if (strlen($username) < 3 || strlen($username) > 20) {
            return ['success' => false, 'error' => __('api_err_username_length', '아이디는 3~20자여야 합니다.')];
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return ['success' => false, 'error' => __('api_err_username_chars', '아이디는 영문, 숫자, 밑줄만 사용 가능합니다.')];
        }
        
        if (!empty($data['display_name']) && mb_strlen($data['display_name']) > 30) {
            return ['success' => false, 'error' => __('err_display_name_too_long', '표시 이름은 30자 이내로 입력하세요.')];
        }
        
        // ★ (2026-10-29 17:46, 22차 점검) 대소문자 무시 + 예약어(usernameProblem) — 종전엔 정확히 같은 글자만 막았음
        $unProblem = self::usernameProblem($this->db, $username);
        if ($unProblem === 'exists') {
            return ['success' => false, 'error' => __('username_already_exists')];
        }
        if ($unProblem === 'reserved') {
            return ['success' => false, 'error' => __('api_err_username_chars', '아이디는 영문, 숫자, 밑줄만 사용 가능합니다.')];
        }
        $data['username'] = $username;   // ★ (22차) 앞뒤 공백 뺀 값으로 저장 — 종전엔 검사만 trim 하고 원래 값('viewer ')을 저장했음
        
        // 탈퇴/삭제된 사용자 중복 체크
        $deletedUsers = $this->db->load('deleted_users') ?: [];
        foreach ($deletedUsers as $du) {
            if (strtolower(trim($du['username'] ?? '')) === strtolower(trim($data['username']))) {
                return ['success' => false, 'error' => __('username_was_withdrawn', '탈퇴 또는 삭제된 아이디입니다. 다른 아이디를 사용해주세요.')];
            }
        }
        
        if (strlen($data['password']) < 8 || strlen($data['password']) > 72) {
            return ['success' => false, 'error' => __('api_err_password_length', '비밀번호는 8~72자여야 합니다.')];
        }
        if (self::emailInUse($this->db, (string)($data['email'] ?? ''))) {   // ★ (2026-10-30 01:42, 26차 — 24차 결정 필요 '메일 중복' 자동 선택: 거부)
            return ['success' => false, 'error' => __('err_email_in_use', '다른 계정이 이미 쓰는 메일입니다.')];
        }
        
        $role = $data['role'] ?? 'user';
        // 관리자는 무조건 활성 상태
        $status = ($role === 'admin') ? 'active' : ($data['status'] ?? 'active');
        
        $userData = [
            'username' => $data['username'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'display_name' => $data['display_name'] ?? $data['username'],
            'email' => $data['email'] ?? '',
            'role' => $role,
            'status' => $status,
            'admin_perms' => ($role === 'sub_admin' && !empty($data['admin_perms'])) ? $data['admin_perms'] : null,
            'quota' => (int)($data['quota'] ?? 0),
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'last_login' => null
        ];
        
        // 정지 상태인 경우 기간 정보 추가
        if ($status === 'suspended') {
            $userData['suspend_from'] = !empty($data['suspend_from']) ? $data['suspend_from'] : null;
            $userData['suspend_until'] = !empty($data['suspend_until']) ? $data['suspend_until'] : null;
            $userData['suspend_reason'] = !empty($data['suspend_reason']) ? $data['suspend_reason'] : null;
        }
        
        $id = $this->db->insert('users', $userData);
        
        return ['success' => true, 'id' => $id];
    }
    
    public function updateUser(int $id, array $data): array {
        // 대상 사용자 정보 조회
        $targetUser = $this->db->find('users', ['id' => $id]);
        if (!$targetUser) {
            return ['success' => false, 'error' => __('api_err_user_not_found', '사용자를 찾을 수 없습니다.')];
        }
        
        // 관리자 역할 변경 불가
        if (($targetUser['role'] ?? '') === 'admin' && isset($data['role']) && $data['role'] !== 'admin') {
            return ['success' => false, 'error' => __('api_err_admin_role_change', '관리자의 역할은 변경할 수 없습니다.')];
        }
        
        $updateData = [];
        if (isset($data['display_name'])) {
            if (mb_strlen($data['display_name']) > 30) {
                return ['success' => false, 'error' => __('err_display_name_too_long', '표시 이름은 30자 이내로 입력하세요.')];
            }
            $updateData['display_name'] = $data['display_name'];
        }
        if (isset($data['email'])) {
            // 메일을 바꿀 때만(이미 겹쳐 있던 옛 자료여도 다른 항목 수정은 막지 않게)
            if (strcasecmp(trim((string)($targetUser['email'] ?? '')), trim((string)$data['email'])) !== 0 && self::emailInUse($this->db, (string)$data['email'], $id)) {   // ★ (2026-10-30 01:42, 26차 — 24차 결정 필요 '메일 중복' 자동 선택: 거부)
                return ['success' => false, 'error' => __('err_email_in_use', '다른 계정이 이미 쓰는 메일입니다.')];
            }
            $updateData['email'] = $data['email'];
        }
        if (isset($data['role'])) $updateData['role'] = $data['role'];
        // ★ (2026-10-29 09:40, 20차 점검) 화면은 권한을 전부 끄면 null 을 보냄 — 종전엔 isset 이 false 라 옛 권한이 그대로 남아 회수가 안 됐음
        if (array_key_exists('admin_perms', $data)) $updateData['admin_perms'] = is_array($data['admin_perms']) ? array_values($data['admin_perms']) : [];
        if (isset($data['is_active'])) $updateData['is_active'] = $data['is_active'];
        if (isset($data['quota'])) $updateData['quota'] = (int)$data['quota'];
        // ★ (2026-10-29 03:51, 17차) SSO 연결(관리자가 직접) — 값은 정해진 제공자만, '' 는 연결 해제. 누가 보낼 수 있는지는 api.php(관리자만)에서 거름
        if (array_key_exists('sso_provider', $data)) {
            $sp = (string)($data['sso_provider'] ?? '');
            if ($sp === '' || in_array($sp, ['ldap', 'oidc', 'saml', 'kerberos'], true)) $updateData['sso_provider'] = ($sp === '' ? null : $sp);
        }
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8 || strlen($data['password']) > 72) {
                return ['success' => false, 'error' => __('api_err_password_length', '비밀번호는 8~72자여야 합니다.')];
            }
            $updateData['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        // 역할에 따른 상태 처리
        $newRole = $data['role'] ?? $targetUser['role'];
        if ($newRole === 'admin') {
            // 관리자는 무조건 활성 상태
            $updateData['status'] = 'active';
            // 정지 정보 초기화
            $updateData['suspend_from'] = null;
            $updateData['suspend_until'] = null;
            $updateData['suspend_reason'] = null;
        } elseif (isset($data['status'])) {
            $updateData['status'] = $data['status'];
            
            // 정지 상태인 경우 기간 정보 설정
            if ($data['status'] === 'suspended') {
                $updateData['suspend_from'] = !empty($data['suspend_from']) ? $data['suspend_from'] : null;
                $updateData['suspend_until'] = !empty($data['suspend_until']) ? $data['suspend_until'] : null;
                $updateData['suspend_reason'] = !empty($data['suspend_reason']) ? $data['suspend_reason'] : null;
            } else {
                // 정지 아닌 상태면 정지 정보 초기화
                $updateData['suspend_from'] = null;
                $updateData['suspend_until'] = null;
                $updateData['suspend_reason'] = null;
            }
        }
        
        // 부관리자가 아니면 admin_perms 제거
        if ($newRole !== 'sub_admin') {
            $updateData['admin_perms'] = null;
        }
        
        if (empty($updateData)) {
            return ['success' => false, 'error' => __('api_err_no_changes', '변경할 내용이 없습니다.')];
        }
        
        $this->db->update('users', ['id' => $id], $updateData);
        // ★ (2026-10-29 03:11, 16차) 관리자가 비밀번호를 바꾸거나 계정을 비활성/정지하면 그 사용자의 로그인 유지 토큰도 폐기(훔친 쿠키 차단)
        if (isset($updateData['password'])
            || (isset($updateData['is_active']) && !$updateData['is_active'])
            || (isset($updateData['status']) && $updateData['status'] !== 'active')) {
            $this->deleteRememberTokensForUser($id);
        }
        return ['success' => true];
    }
    
    public function deleteUser(int $id): array {
        if ($id === $this->getUserId()) {
            return ['success' => false, 'error' => __('api_err_cannot_delete_self', '자신의 계정은 삭제할 수 없습니다.')];
        }
        
        // 삭제 대상 사용자 조회
        $targetUser = $this->db->find('users', ['id' => $id]);
        if (!$targetUser) {
            return ['success' => false, 'error' => __('api_err_user_not_found', '사용자를 찾을 수 없습니다.')];
        }
        
        // 관리자 계정은 삭제 불가
        if (($targetUser['role'] ?? '') === 'admin') {
            return ['success' => false, 'error' => __('api_err_cannot_delete_admin', '관리자 계정은 삭제할 수 없습니다.')];
        }
        
        // 공통 삭제 처리 (보관 + 로그 삭제)
        return $this->deleteUserData($id, __('delete_type_delete'));
    }
    
    public function bulkUpdateQuota(string $target, int $quota): array {
        $users = $this->db->load('users');
        $updated = 0;
        
        foreach ($users as &$user) {
            $shouldUpdate = false;
            
            switch ($target) {
                case 'all':
                    $shouldUpdate = true;
                    break;
                case 'user':
                    $shouldUpdate = ($user['role'] ?? 'user') !== 'admin';
                    break;
                case 'unlimited':
                    $shouldUpdate = empty($user['quota']) || $user['quota'] == 0;
                    break;
            }
            
            if ($shouldUpdate) {
                $user['quota'] = $quota;
                $updated++;
            }
        }
        unset($user);
        
        $this->db->save('users', $users);
        
        return ['success' => true, 'updated' => $updated];
    }
    
    public function getUsers(): array {
        $users = $this->db->load('users');
        return array_map(function($u) {
            // ★ (2026-10-29 09:53, 20차 점검) 2FA 비밀키(암호화본)·백업 코드·앱 비밀번호 해시도 빼고 보냄 — 종전엔 사용자 관리 권한 부관리자 화면까지 그대로 내려갔음(화면은 2fa_enabled 만 씀)
            unset($u['password'], $u['2fa_secret'], $u['2fa_backup_codes'], $u['app_passwords']);
            return $u;
        }, $users);
    }
    
    public function changePassword(string $currentPassword, string $newPassword): array {
        $user = $this->db->find('users', ['id' => $this->getUserId()]);
        
        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'error' => __('api_err_current_password', '현재 비밀번호가 올바르지 않습니다.')];
        }
        
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            return ['success' => false, 'error' => __('api_err_password_length', '비밀번호는 8~72자여야 합니다.')];
        }
        
        $this->db->update('users', ['id' => $this->getUserId()], [
            'password' => password_hash($newPassword, PASSWORD_DEFAULT)
        ]);
        $this->deleteRememberTokensForUser((int)$this->getUserId());   // ★ (2026-10-29 03:11, 16차) 비밀번호 바꾸면 기존 로그인 유지 토큰 폐기

        return ['success' => true];
    }

    /**
     * 회원 탈퇴 (사용자 직접)
     */
    public function withdrawAccount(string $password): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => __('api_login_required')];
        }
        
        // 관리자는 탈퇴 불가
        if (($user['role'] ?? '') === 'admin') {
            return ['success' => false, 'error' => __('api_err_admin_no_withdraw', '관리자 계정은 탈퇴할 수 없습니다.')];
        }
        
        // 비밀번호 확인
        $fullUser = $this->db->find('users', ['id' => $user['id']]);
        if (!$fullUser || !password_verify($password, $fullUser['password'])) {
            return ['success' => false, 'error' => __('api_err_password_incorrect', '비밀번호가 올바르지 않습니다.')];
        }
        
        // 탈퇴 처리
        return $this->deleteUserData($user['id'], __('delete_type_withdraw'));
    }
    
    /**
     * 사용자 삭제 (관리자 또는 탈퇴 공통)
     */
    public function deleteUserData(int $userId, string $deleteType = ''): array {
        if ($deleteType === '') $deleteType = __('delete_type_delete');
        $user = $this->db->find('users', ['id' => $userId]);
        if (!$user) {
            return ['success' => false, 'error' => __('api_err_user_not_found', '사용자를 찾을 수 없습니다.')];
        }
        
        $username = $user['username'];
        
        // 1. 로그인 기록 추출 (전체 보관)
        $loginLogs = $this->db->load('login_logs');
        $userLoginLogs = array_values(array_filter($loginLogs, function($log) use ($username) {
            return strtolower($log['username'] ?? '') === strtolower($username);
        }));
        
        // 2. 활동 로그 추출 (전체 보관)
        $activityLogs = $this->db->load('activity_logs');
        $userActivityLogs = array_values(array_filter($activityLogs, function($log) use ($userId) {
            return ($log['user_id'] ?? 0) === $userId;
        }));
        
        // 3. 삭제된 사용자 정보 보관 (로그 전체 포함)
        $deletedUsers = $this->db->load('deleted_users');
        $deletedUsers[] = [
            'id' => uniqid('del_'),
            'original_id' => $userId,
            'username' => $username,
            'email' => $user['email'] ?? '',
            'display_name' => $user['display_name'] ?? '',
            'role' => $user['role'] ?? 'user',
            'created_at' => $user['created_at'] ?? '',
            'delete_type' => $deleteType,
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => $deleteType === __('delete_type_withdraw') ? $username : ($_SESSION['username'] ?? 'admin'),
            // 로그 전체 보관
            'login_logs' => $userLoginLogs,
            'activity_logs' => $userActivityLogs
        ];
        $this->db->save('deleted_users', $deletedUsers);
        
        // 4. 로그인 기록 삭제
        $loginLogs = array_filter($loginLogs, function($log) use ($username) {
            return strtolower($log['username'] ?? '') !== strtolower($username);
        });
        $this->db->save('login_logs', array_values($loginLogs));
        
        // 5. 활동 로그 삭제
        $activityLogs = array_filter($activityLogs, function($log) use ($userId) {
            return ($log['user_id'] ?? 0) !== $userId;
        });
        $this->db->save('activity_logs', array_values($activityLogs));
        
        // 6. 세션 삭제
        $sessions = $this->db->load('sessions');
        $sessions = array_filter($sessions, function($s) use ($userId) {
            return ($s['user_id'] ?? 0) !== $userId;
        });
        $this->db->save('sessions', array_values($sessions));
        
        // 7. Remember 토큰 삭제
        $tokens = $this->db->load('remember_tokens');
        $tokens = array_filter($tokens, function($t) use ($userId) {
            return ($t['user_id'] ?? 0) !== $userId;
        });
        $this->db->save('remember_tokens', array_values($tokens));
        
        // 8. 즐겨찾기 삭제
        $favorites = $this->db->load('favorites');
        $favorites = array_filter($favorites, function($f) use ($userId) {
            return ($f['user_id'] ?? 0) !== $userId;
        });
        $this->db->save('favorites', array_values($favorites));
        
        // 9. 최근 파일 삭제
        $recentFiles = $this->db->load('recent_files');
        $recentFiles = array_filter($recentFiles, function($r) use ($userId) {
            return ($r['user_id'] ?? 0) !== $userId;
        });
        $this->db->save('recent_files', array_values($recentFiles));

        // 9-1. ★ (2026-10-04) 동영상 이어 보기 기록 삭제
        $vpRows = $this->db->load('video_progress');
        $vpRows = array_filter($vpRows, function($r) use ($userId) {
            return ($r['user_id'] ?? 0) !== $userId;
        });
        $this->db->save('video_progress', array_values($vpRows));
        
        // 10. 공유 링크 삭제 (해당 사용자가 만든 공유)
        // ★ (2026-10-29 03:11, 16차) 공유는 created_by 로 저장되는데 종전엔 user_id 로만 걸러 한 건도 안 지워졌음. created_by·user_id 둘 다 봄.
        //   또 사용자가 만들었거나 받은 내부 공유(internal_shares)·폴더 규칙(folder_permissions)도 정리 — 안 지우면 ID 가 재사용될 때(다음 가입자=max+1)
        //   그 공유·규칙을 물려받을 수 있었음
        $shares = $this->db->load('shares');
        $shares = array_filter($shares, function($s) use ($userId) {
            return ($s['user_id'] ?? 0) !== $userId && (int)($s['created_by'] ?? 0) !== $userId;
        });
        $this->db->save('shares', array_values($shares));

        $ishares = $this->db->load('internal_shares');
        $ishares = array_filter($ishares, function($s) use ($userId) {
            return (int)($s['shared_by'] ?? 0) !== $userId && (int)($s['shared_with'] ?? 0) !== $userId;
        });
        $this->db->save('internal_shares', array_values($ishares));

        $fperms = $this->db->load('folder_permissions');
        $fperms = array_filter($fperms, function($p) use ($userId) {
            return (int)($p['user_id'] ?? 0) !== $userId;
        });
        $this->db->save('folder_permissions', array_values($fperms));
        // ★ (2026-10-29 21:17, 24차 점검) 사용자별 속도 제한(QoS)도 지움 — 번호가 재사용되면 새 사용자가 지운 사람의 개별 제한(또는 해제)을 물려받았음(위 16차와 같은 이유)
        $qosFile = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/qos_settings.json';
        if (is_file($qosFile)) {
            $qos = json_decode((string)@file_get_contents($qosFile), true);
            if (is_array($qos) && isset($qos['users'][(string)$userId])) {
                unset($qos['users'][(string)$userId]);
                @file_put_contents($qosFile, json_encode($qos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }
        
        // 11. 2FA 설정 삭제
        $twofa = $this->db->load('twofa');
        $twofa = array_filter($twofa, function($t) use ($userId) {
            return ($t['user_id'] ?? 0) !== $userId;
        });
        $this->db->save('twofa', array_values($twofa));
        
        // 12. 스토리지 권한 삭제
        $permissions = $this->db->load('permissions');
        $permissions = array_filter($permissions, function($p) use ($userId) {
            return ($p['user_id'] ?? 0) !== $userId;
        });
        $this->db->save('permissions', array_values($permissions));
        
        // 12-1. home 타입 스토리지 항목 삭제 (소유자가 삭제됐으므로)
        //   → 인덱스 재구축, 스토리지 목록 등에서 'unknown' 사용자로 표시되는 문제 방지
        //   → 실제 폴더는 위에서 _deleted/로 이동되므로 DB의 home 항목만 제거하면 됨
        $storagesAll = $this->db->load('storages');
        $storagesAll = array_filter($storagesAll, function($s) use ($userId) {
            return !(($s['storage_type'] ?? '') === 'home' && ($s['owner_id'] ?? 0) === $userId);
        });
        $this->db->save('storages', array_values($storagesAll));
        
        // 13. 로그인 시도 기록 삭제
        $attempts = $this->db->load('login_attempts');
        $attempts = array_filter($attempts, function($a) use ($username) {
            return strtolower($a['username'] ?? '') !== strtolower($username);
        });
        $this->db->save('login_attempts', array_values($attempts));
        
        // 14. 휴지통 기록 주인 정리
        // ★ (2026-10-29 10:15, 20차 점검) 잠금 안에서 최신 목록에 적용 — 종전엔 읽고-저장 사이에 다른 요청이 더한 휴지통 기록이 덮여 사라질 수 있었음
        // ★ (2026-10-29 11:58, 20차 점검) 휴지통 기록의 사용자 키는 'deleted_by'(FileManager·WebDAV 가 그렇게 씀) — 종전엔 없는 키 'user_id' 로 걸러
        //   아무것도 안 바뀌었고, 지운 사용자의 번호가 새 사용자에게 다시 붙으면(번호 = 가장 큰 번호 + 1) 새 사용자 휴지통에 이전 사용자 파일이 보였음(재현)
        // ★ (2026-10-29 15:35, 21차 재점검) 기록·사본은 지우지 않고 주인 번호만 0 으로(이름 deleted_by_name 은 남김) — 20차엔 사본까지 영구 삭제해
        //   그 사용자가 공용 스토리지에서 지운 파일을 관리자가 더는 복원할 수 없었음(재현). 관리자 '전체 휴지통' 에서 그대로 보이고 복원·비우기 가능
        //   (사용자 폴더를 지우지 않고 _deleted 로 옮겨 두는 아래 16단계와 같은 취지)
        $trashF = function ($trash) use ($userId) {
            $trash = is_array($trash) ? array_values($trash) : [];
            foreach ($trash as &$t) {
                if ((int)($t['deleted_by'] ?? 0) === $userId) $t['deleted_by'] = 0;
            }
            unset($t);
            return $trash;
        };
        if (!$this->db->atomicUpdate('trash', $trashF)) $this->db->save('trash', $trashF($this->db->load('trash')));
        
        // 15. 파일 잠금 삭제
        $lockedFiles = $this->db->load('locked_files');
        $lockedFiles = array_filter($lockedFiles, function($l) use ($userId) {
            return ($l['user_id'] ?? 0) !== $userId;
        });
        $this->db->save('locked_files', array_values($lockedFiles));
        
        // 16. 사용자 폴더 이동 (users/{username}/ → users/_deleted/{username}_{날짜}_{del_id}/)
        $delId = $deletedUsers[count($deletedUsers) - 1]['id'] ?? uniqid('del_');
        // 타임존 안전 타임스탬프 생성 (kstDate는 config.php에서 정의)
        $folderName = $username . '_' . kstDate('Ymd_His') . '_' . $delId;
        
        $userFolder = USER_FILES_ROOT . '/' . $username;
        $deletedFolder = USER_FILES_ROOT . '/_deleted';
        $targetFolder = $deletedFolder . '/' . $folderName;
        
        if (is_dir($userFolder)) {
            // _deleted 폴더 생성
            if (!is_dir($deletedFolder)) {
                mkdir($deletedFolder, 0755, true);
                // .htaccess로 직접 접근 차단
                file_put_contents($deletedFolder . '/.htaccess', "Deny from all\n");
            }
            
            // 폴더 이동
            if (rename($userFolder, $targetFolder)) {
                // 삭제된 사용자 정보에 폴더 경로 추가
                $deletedUsers[count($deletedUsers) - 1]['folder_path'] = '_deleted/' . $folderName;
                $deletedUsers[count($deletedUsers) - 1]['folder_name'] = $folderName;
                $this->db->save('deleted_users', $deletedUsers);
            }
        }
        
        // 17. 사용자 삭제
        $users = $this->db->load('users');
        $users = array_filter($users, fn($u) => ($u['id'] ?? 0) !== $userId);
        $this->db->save('users', array_values($users));
        
        // 탈퇴인 경우 로그아웃
        if ($deleteType === __('delete_type_withdraw') && isset($_SESSION['user_id']) && $_SESSION['user_id'] === $userId) {
            session_destroy();
        }
        
        return ['success' => true, 'message' => __f('delete_complete', ['type' => $deleteType])];
    }
    
    /**
     * 삭제된 사용자 목록 조회 (관리자)
     */
    public function getDeletedUsers(): array {
        $deletedUsers = $this->db->load('deleted_users');
        $users = $this->db->load('users') ?: [];
        
        // username → display_name 맵
        $displayNameMap = [];
        foreach ($users as $u) {
            $displayNameMap[strtolower($u['username'] ?? '')] = $u['display_name'] ?? $u['username'] ?? '';
        }
        
        // 각 사용자의 폴더 존재 여부 + 처리자 표시이름
        foreach ($deletedUsers as &$user) {
            if (!empty($user['folder_path'])) {
                $folderPath = USER_FILES_ROOT . '/' . $user['folder_path'];
                $user['folder_exists'] = is_dir($folderPath);
            } else {
                $user['folder_exists'] = false;
            }
            
            // 처리자 표시이름
            $deletedBy = strtolower($user['deleted_by'] ?? '');
            $user['deleted_by_display'] = $displayNameMap[$deletedBy] ?? $user['deleted_by'] ?? '';
        }
        unset($user);
        
        usort($deletedUsers, fn($a, $b) => strtotime($b['deleted_at'] ?? '0') - strtotime($a['deleted_at'] ?? '0'));
        return $deletedUsers;
    }
    
    /**
     * 삭제된 사용자 기록 영구 삭제 (관리자)
     */
    public function purgeDeletedUser(string $id, bool $deleteFiles = false): array {
        $deletedUsers = $this->db->load('deleted_users');
        
        // 해당 사용자 찾기
        $targetUser = null;
        foreach ($deletedUsers as $u) {
            if (($u['id'] ?? '') === $id) {
                $targetUser = $u;
                break;
            }
        }
        
        // 파일도 삭제하는 경우
        if ($deleteFiles && $targetUser && !empty($targetUser['folder_path'])) {
            $folderPath = USER_FILES_ROOT . '/' . $targetUser['folder_path'];
            if (is_dir($folderPath)) {
                $this->deleteDirectory($folderPath);
            }
        }
        
        $deletedUsers = array_filter($deletedUsers, fn($u) => ($u['id'] ?? '') !== $id);
        $this->db->save('deleted_users', array_values($deletedUsers));
        return ['success' => true];
    }
    
    /**
     * 디렉토리 재귀 삭제
     */
    private function deleteDirectory(string $dir): bool {
        if (!is_dir($dir)) return false;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        return rmdir($dir);
    }
    
    // ===== IP/국가 제한 =====
    private function getClientIP(): string {
        // 전역 getClientIP() 사용 (TRUSTED_PROXIES 검증 포함)
        // ★ (2026-10-29 10:27, 20차 점검) 전역 함수는 api.php 에만 있어 WebDAV(mydav.php)·index.php 에선 없음
        if (function_exists('getClientIP')) return \getClientIP();
        // ★ (2026-10-29 15:35, 21차 재점검) 그때도 api.php getClientIP 와 같은 규칙(신뢰 프록시면 프록시 헤더의 원래 주소) — 20차엔 접속 주소만 써서,
        //   신뢰 프록시를 설정한 서버에선 WebDAV·index.php 의 IP 제한이 프록시 주소로 판단됐음
        $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $trusted = defined('TRUSTED_PROXIES') ? (array)TRUSTED_PROXIES : [];
        if (empty($trusted)) return $remoteAddr;
        $isTrusted = false;
        foreach ($trusted as $proxy) {
            $proxy = (string)$proxy;
            if (strpos($proxy, '/') !== false ? $this->ipInRange($remoteAddr, $proxy) : $remoteAddr === $proxy) { $isTrusted = true; break; }
        }
        if (!$isTrusted) return $remoteAddr;
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) { $ips = array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])); return $ips[0]; }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) return (string)$_SERVER['HTTP_X_REAL_IP'];
        return $remoteAddr;
    }
    
    private function getSecuritySettings(): array {
        $settings = $this->db->load('security_settings');
        if (empty($settings)) {
            return [
                'enabled' => false,
                'block_country' => false,
                'allow_country_only' => false,
                'block_ip' => false,
                'allow_ip_only' => false,
                'allowed_ips' => defined('ALLOWED_IPS') ? ALLOWED_IPS : [],
                'blocked_ips' => defined('BLOCKED_IPS') ? BLOCKED_IPS : [],
                'allowed_countries' => defined('ALLOWED_COUNTRIES') ? ALLOWED_COUNTRIES : [],
                'blocked_countries' => defined('BLOCKED_COUNTRIES') ? BLOCKED_COUNTRIES : [],
                'admin_ips' => [],
                'block_message' => __('access_blocked'),
                'cache_hours' => 24,
                'log_enabled' => false,
                'max_attempts' => defined('LOGIN_MAX_ATTEMPTS') ? LOGIN_MAX_ATTEMPTS : 5,
                'lockout_minutes' => defined('LOGIN_LOCKOUT_MINUTES') ? LOGIN_LOCKOUT_MINUTES : 15,
                // GeoIP 설정
                'geoip_source' => 'api',
                'geoip_file_path' => '',
                'block_unknown' => false
            ];
        }
        return $settings;
    }
    
    public function getCurrentIP(): string {
        return $this->getClientIP();
    }
    
    public function getCurrentCountry(): string {
        return $this->getCountryFromIP($this->getClientIP());
    }
    
    private function checkIPRestriction(string $ip): array {
        $settings = $this->getSecuritySettings();
        
        // 차단 기능이 비활성화되어 있으면 허용
        if (empty($settings['enabled'])) {
            return ['allowed' => true, 'reason' => ''];
        }
        
        // 관리자 IP 화이트리스트 체크 (최우선)
        $adminIps = $settings['admin_ips'] ?? [];
        if (!empty($adminIps)) {
            foreach ($adminIps as $adminIp) {
                if ($this->ipInRange($ip, trim($adminIp))) {
                    return ['allowed' => true, 'reason' => __('api_admin_ip', '관리자 IP')];
                }
            }
        }
        
        $blockMessage = $settings['block_message'] ?? __('access_blocked');
        
        // IP 차단 모드
        $blockIp = $settings['block_ip'] ?? false;
        $allowIpOnly = $settings['allow_ip_only'] ?? false;
        $blockedIps = $settings['blocked_ips'] ?? [];
        $allowedIps = $settings['allowed_ips'] ?? [];
        
        // 특정 IP 차단
        if ($blockIp && !empty($blockedIps)) {
            foreach ($blockedIps as $blocked) {
                if ($this->ipInRange($ip, trim($blocked))) {
                    $this->logBlockedAccess($ip, __('ip_blocked'));
                    return ['allowed' => false, 'reason' => $blockMessage];
                }
            }
        }
        
        // 특정 IP만 허용
        if ($allowIpOnly && !empty($allowedIps)) {
            $allowed = false;
            foreach ($allowedIps as $allowedIp) {
                if ($this->ipInRange($ip, trim($allowedIp))) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $this->logBlockedAccess($ip, __('ip_not_in_whitelist'));
                return ['allowed' => false, 'reason' => $blockMessage];
            }
        }
        
        // 국가 차단 모드
        $blockCountry = $settings['block_country'] ?? false;
        $allowCountryOnly = $settings['allow_country_only'] ?? false;
        $blockedCountries = $settings['blocked_countries'] ?? [];
        $allowedCountries = $settings['allowed_countries'] ?? [];
        $blockUnknown = $settings['block_unknown'] ?? false;
        
        $checkCountry = ($blockCountry && !empty($blockedCountries)) || ($allowCountryOnly && !empty($allowedCountries)) || $blockUnknown;
        
        if ($checkCountry) {
            $country = $this->getCountryFromIP($ip);
            
            // 로컬 IP는 국가 제한 건너뛰기
            if ($country === 'LOCAL') {
                return ['allowed' => true, 'reason' => ''];
            }
            
            // UNKNOWN IP 차단
            if ($blockUnknown && ($country === 'XX' || $country === 'UNKNOWN')) {
                $this->logBlockedAccess($ip, __('country_unknown'));
                return ['allowed' => false, 'reason' => $blockMessage];
            }
            
            // 특정 국가 차단
            if ($blockCountry && !empty($blockedCountries) && in_array($country, $blockedCountries)) {
                $this->logBlockedAccess($ip, __f('country_blocked', ['country' => $country]));
                return ['allowed' => false, 'reason' => $blockMessage];
            }
            
            // 특정 국가만 허용
            if ($allowCountryOnly && !empty($allowedCountries)) {
                if (!in_array($country, $allowedCountries)) {
                    $this->logBlockedAccess($ip, __f('country_not_allowed', ['country' => $country]));
                    return ['allowed' => false, 'reason' => $blockMessage];
                }
            }
        }
        
        return ['allowed' => true, 'reason' => ''];
    }
    
    private function logBlockedAccess(string $ip, string $reason): void {
        $settings = $this->getSecuritySettings();
        if (empty($settings['log_enabled'])) {
            return;
        }
        
        // ★ (2026-10-29 21:53, 24차 점검) 잠금 안에서 읽고 씀(선택 삭제 23차와 같은 잠금) — 종전엔 따로 읽고 써서, 차단이 이어지는 중에 지운 기록이 되살아났음
        $entry = [
            // ★ (2026-10-29 20:14, 23차 점검) 기록마다 고유 번호 — 선택 삭제가 위치 대신 이 번호로 지움(1000건 넘어 앞부분이 잘리면 위치가 밀려 엉뚱한 기록이 지워졌음)
            'lid' => bin2hex(random_bytes(6)),
            'ip' => $ip,
            'reason' => $reason,
            'country' => $this->getCountryFromIP($ip),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ];
        $this->db->atomicUpdate('security_block_logs', function ($logs) use ($entry) {
            $logs = is_array($logs) ? array_values($logs) : [];
            $logs[] = $entry;
            // 최대 1000개 로그 유지
            if (count($logs) > 1000) {
                $logs = array_slice($logs, -500);
            }
            return array_values($logs);
        });
    }
    
    public function testIPRestriction(): array {
        $ip = $this->getClientIP();
        $country = $this->getCountryFromIP($ip);
        $check = $this->checkIPRestriction($ip);
        
        return [
            'ip' => $ip,
            'country' => $country,
            'blocked' => !$check['allowed'],
            'reason' => $check['reason']
        ];
    }
    
    private function ipInRange(string $ip, string $range): bool {
        // 전역 ipInCidr() 사용 (IPv4+IPv6 지원)
        if (function_exists('ipInCidr')) return \ipInCidr($ip, $range);
        // ★ (2026-10-29 10:28, 20차 점검) 전역 함수는 api.php 에만 있어 WebDAV(mydav.php)에선 같은 계산을 여기서(api.php ipInCidr 와 동일)
        if (strpos($range, '/') === false) return $ip === $range;
        list($subnet, $bits) = explode('/', $range);
        $bits = (int)$bits;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) return false;
        if (strlen($ipBin) !== strlen($subnetBin)) return false;
        $byteLen = strlen($ipBin);
        $fullBytes = intdiv($bits, 8);
        $remainBits = $bits % 8;
        for ($i = 0; $i < $fullBytes && $i < $byteLen; $i++) {
            if ($ipBin[$i] !== $subnetBin[$i]) return false;
        }
        if ($remainBits > 0 && $fullBytes < $byteLen) {
            $mask = 0xFF << (8 - $remainBits) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) return false;
        }
        return true;
    }
    
    private function getCountryFromIP(string $ip): string {
        // 로컬/사설 IP는 건너뛰기
        if (in_array($ip, ['127.0.0.1', '::1']) || 
            strpos($ip, '192.168.') === 0 || 
            strpos($ip, '10.') === 0 || 
            (strpos($ip, '172.') === 0 && preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip))) {
            return 'LOCAL';
        }
        
        $settings = $this->getSecuritySettings();
        $cacheHours = $settings['cache_hours'] ?? 24;
        $geoipSource = $settings['geoip_source'] ?? 'api';
        $geoipFilePath = $settings['geoip_file_path'] ?? '';
        
        // 캐시 확인
        $cache = $this->db->load('ip_country_cache');
        foreach ($cache as $entry) {
            if (($entry['ip'] ?? '') === $ip && strtotime($entry['cached_at'] ?? '0') > strtotime("-{$cacheHours} hours")) {
                return $entry['country'] ?? 'XX';
            }
        }
        
        $country = 'XX';
        
        // 소스별 국가 조회
        switch ($geoipSource) {
            case 'mmdb':
                $country = $this->getCountryFromMMDB($ip, $geoipFilePath);
                break;
            case 'dat':
                $country = $this->getCountryFromDAT($ip, $geoipFilePath);
                break;
            case 'csv':
                $country = $this->getCountryFromCSV($ip, $geoipFilePath);
                break;
            case 'api':
            default:
                $country = $this->getCountryFromAPI($ip);
                break;
        }
        
        // 캐시 저장
        $cache[] = ['ip' => $ip, 'country' => $country, 'cached_at' => date('Y-m-d H:i:s')];
        $cache = array_filter($cache, fn($e) => strtotime($e['cached_at'] ?? '0') > strtotime("-{$cacheHours} hours"));
        if (count($cache) > 1000) $cache = array_slice($cache, -500);
        $this->db->save('ip_country_cache', array_values($cache));
        
        return $country;
    }
    
    /**
     * 외부 API로 국가 조회 (ip-api.com)
     */
    private function getCountryFromAPI(string $ip): string {
        $url = "http://ip-api.com/json/{$ip}?fields=status,countryCode";
        
        // cURL 방식 (allow_url_fopen과 무관)
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'WebHard/1.0'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($response && $httpCode === 200) {
                $data = json_decode($response, true);
                if (($data['status'] ?? '') === 'success' && !empty($data['countryCode'])) {
                    return $data['countryCode'];
                }
            }
        } 
        // fallback: file_get_contents
        elseif (ini_get('allow_url_fopen')) {
            try {
                $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
                $response = @file_get_contents($url, false, $context);
                
                if ($response) {
                    $data = json_decode($response, true);
                    if (($data['status'] ?? '') === 'success' && !empty($data['countryCode'])) {
                        return $data['countryCode'];
                    }
                }
            } catch (Exception $e) {
                // 무시
            }
        }
        
        return 'XX';
    }
    
    /**
     * MaxMind MMDB 파일로 국가 조회
     */
    private function getCountryFromMMDB(string $ip, string $filePath): string {
        $fullPath = $this->resolveGeoIPPath($filePath);
        if (!$fullPath || !file_exists($fullPath)) {
            return 'XX';
        }
        
        try {
            // MaxMind Reader 직접 구현 (간소화)
            $handle = fopen($fullPath, 'rb');
            if (!$handle) return 'XX';
            
            // MMDB 메타데이터 읽기 (파일 끝에서 검색)
            fseek($handle, -16384, SEEK_END);
            $data = fread($handle, 16384);
            $markerPos = strrpos($data, "\xab\xcd\xefMaxMind.com");
            
            if ($markerPos === false) {
                fclose($handle);
                return 'XX';
            }
            
            // 간단한 MMDB 검색 (geoip2/geoip2 패키지가 있으면 사용)
            fclose($handle);
            
            // Composer autoload가 있으면 MaxMind Reader 사용
            if (class_exists('\GeoIp2\Database\Reader')) {
                $reader = new \GeoIp2\Database\Reader($fullPath);
                $record = $reader->country($ip);
                return $record->country->isoCode ?? 'XX';
            }
            
            // 없으면 직접 파싱 (기본 구현)
            return $this->parseMMDBDirect($fullPath, $ip);
            
        } catch (Exception $e) {
            return 'XX';
        }
    }
    
    /**
     * MMDB 직접 파싱 (간소화 버전)
     */
    private function parseMMDBDirect(string $filePath, string $ip): string {
        // 간단한 구현 - 바이너리 검색
        // 실제 운영에서는 geoip2/geoip2 Composer 패키지 권장
        
        $handle = fopen($filePath, 'rb');
        if (!$handle) return 'XX';
        
        // MMDB 포맷 파싱은 복잡하므로 기본 반환
        // 실제로는 MaxMind의 PHP Reader 사용 권장
        fclose($handle);
        
        return 'XX';
    }
    
    /**
     * 레거시 DAT 파일로 국가 조회
     */
    private function getCountryFromDAT(string $ip, string $filePath): string {
        $fullPath = $this->resolveGeoIPPath($filePath);
        if (!$fullPath || !file_exists($fullPath)) {
            return 'XX';
        }
        
        // geoip_country_code_by_name 함수 사용 (GeoIP extension)
        if (function_exists('geoip_country_code_by_name')) {
            $code = @geoip_country_code_by_name($ip);
            return $code ?: 'XX';
        }
        
        // PHP Extension 없이 직접 파싱
        try {
            $handle = fopen($fullPath, 'rb');
            if (!$handle) return 'XX';
            
            // GeoIP.dat 포맷
            $countryCodes = [
                '', 'AP', 'EU', 'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'CW',
                'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AZ', 'BA', 'BB',
                'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BM', 'BN', 'BO',
                'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD',
                'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR',
                'CU', 'CV', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO',
                'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ',
                'FK', 'FM', 'FO', 'FR', 'SX', 'GA', 'GB', 'GD', 'GE', 'GF',
                'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT',
                'GU', 'GW', 'GY', 'HK', 'HM', 'HN', 'HR', 'HT', 'HU', 'ID',
                'IE', 'IL', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JM', 'JO',
                'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW',
                'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT',
                'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'MG', 'MH', 'MK', 'ML',
                'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV',
                'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI',
                'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF',
                'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW',
                'PY', 'QA', 'RE', 'RO', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD',
                'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO',
                'SR', 'ST', 'SV', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH',
                'TJ', 'TK', 'TM', 'TN', 'TO', 'TL', 'TR', 'TT', 'TV', 'TW',
                'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE',
                'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'RS', 'ZA',
                'ZM', 'ME', 'ZW', 'A1', 'A2', 'O1', 'AX', 'GG', 'IM', 'JE',
                'BL', 'MF', 'BQ', 'SS'
            ];
            
            $ipNum = ip2long($ip);
            if ($ipNum === false) {
                fclose($handle);
                return 'XX';
            }
            
            // DAT 파일 구조 검색
            $offset = 0;
            for ($depth = 31; $depth >= 0; $depth--) {
                fseek($handle, $offset * 6);
                $buf = fread($handle, 6);
                if (strlen($buf) != 6) {
                    fclose($handle);
                    return 'XX';
                }
                
                $x = [0, 0];
                for ($i = 0; $i < 2; $i++) {
                    for ($j = 0; $j < 3; $j++) {
                        $x[$i] += ord($buf[$i * 3 + $j]) << ($j * 8);
                    }
                }
                
                $bit = ($ipNum >> $depth) & 1;
                if ($x[$bit] >= 16776960) {
                    $idx = $x[$bit] - 16776960;
                    fclose($handle);
                    return $countryCodes[$idx] ?? 'XX';
                }
                $offset = $x[$bit];
            }
            
            fclose($handle);
            return 'XX';
            
        } catch (Exception $e) {
            return 'XX';
        }
    }
    
    /**
     * CSV 파일로 국가 조회
     */
    private function getCountryFromCSV(string $ip, string $filePath): string {
        $fullPath = $this->resolveGeoIPPath($filePath);
        if (!$fullPath || !file_exists($fullPath)) {
            return 'XX';
        }
        
        // CSV 캐시 (메모리에 로드)
        static $csvCache = null;
        static $csvCachePath = null;
        
        if ($csvCache === null || $csvCachePath !== $fullPath) {
            $csvCache = [];
            $csvCachePath = $fullPath;
            
            $handle = fopen($fullPath, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (empty($line) || $line[0] === '#') continue;
                    
                    $parts = str_getcsv($line);
                    if (count($parts) >= 2) {
                        $csvCache[] = [
                            'cidr' => trim($parts[0]),
                            'country' => strtoupper(trim($parts[1]))
                        ];
                    }
                }
                fclose($handle);
            }
        }
        
        // IP가 CIDR 범위에 포함되는지 확인
        $ipLong = ip2long($ip);
        if ($ipLong === false) return 'XX';
        
        foreach ($csvCache as $entry) {
            if ($this->ipInRange($ip, $entry['cidr'])) {
                return $entry['country'];
            }
        }
        
        return 'XX';
    }
    
    /**
     * GeoIP 파일 경로 해석
     */
    private function resolveGeoIPPath(string $filePath): ?string {
        if (empty($filePath)) return null;
        
        // 절대 경로
        if (preg_match('/^([a-zA-Z]:|\/|\\\\)/', $filePath)) {
            return $filePath;
        }
        
        // 상대 경로 (BASE_PATH 기준)
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        return rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim($filePath, '/\\');
    }
    
    // ===== 브루트포스 방지 =====
    private function isLockedOut(string $username, string $ip): bool {
        $settings = $this->getSecuritySettings();
        $maxAttempts = $settings['max_attempts'] ?? 5;
        $lockoutMinutes = $settings['lockout_minutes'] ?? 15;
        $lockoutPermanent = $settings['lockout_permanent'] ?? false;
        $attemptResetMinutes = $settings['attempt_reset_minutes'] ?? 30;
        
        if ($maxAttempts <= 0) return false;
        
        $attempts = $this->db->load('login_attempts');
        $key = md5($username . $ip);
        
        foreach ($attempts as &$attempt) {
            if (($attempt['key'] ?? '') === $key) {
                $lastAttempt = strtotime($attempt['last_attempt'] ?? '0');
                $count = $attempt['count'] ?? 0;
                
                // 시도 횟수 리셋 시간이 지났으면 카운트 초기화
                if ($attemptResetMinutes > 0 && $count < $maxAttempts) {
                    if (time() - $lastAttempt >= $attemptResetMinutes * 60) {
                        $attempt['count'] = 0;
                        $this->db->save('login_attempts', $attempts);
                        return false;
                    }
                }
                
                // 최대 시도 횟수 도달 시 잠금 확인
                if ($count >= $maxAttempts) {
                    // 무제한 잠금이면 항상 잠금 상태
                    if ($lockoutPermanent || $lockoutMinutes === 0) {
                        return true;
                    }
                    // 잠금 시간이 지나지 않았으면 잠금 상태
                    if (time() - $lastAttempt < $lockoutMinutes * 60) {
                        return true;
                    }
                }
            }
        }
        unset($attempt);
        
        return false;
    }
    
    private function recordFailedAttempt(string $username, string $ip): void {
        $settings = $this->getSecuritySettings();
        $maxAttempts = $settings['max_attempts'] ?? 5;
        $attemptResetMinutes = $settings['attempt_reset_minutes'] ?? 30;
        
        if ($maxAttempts <= 0) return;
        
        $attempts = $this->db->load('login_attempts');
        $key = md5($username . $ip);
        $found = false;
        
        foreach ($attempts as &$attempt) {
            if (($attempt['key'] ?? '') === $key) {
                $lastAttempt = strtotime($attempt['last_attempt'] ?? '0');
                
                // 시도 횟수 리셋 시간이 지났으면 카운트 초기화 후 1로 설정
                if ($attemptResetMinutes > 0 && time() - $lastAttempt >= $attemptResetMinutes * 60) {
                    $attempt['count'] = 1;
                } else {
                    $attempt['count'] = ($attempt['count'] ?? 0) + 1;
                }
                
                $attempt['last_attempt'] = date('Y-m-d H:i:s');
                $attempt['attempts'] = $attempt['count']; // 호환성을 위해 추가
                $found = true;
                break;
            }
        }
        unset($attempt);
        
        if (!$found) {
            $attempts[] = [
                'key' => $key,
                'username' => $username,
                'ip' => $ip,
                'count' => 1,
                'attempts' => 1,
                'last_attempt' => date('Y-m-d H:i:s')
            ];
        }
        
        $this->db->save('login_attempts', $attempts);
    }
    
    private function clearFailedAttempts(string $username, string $ip): void {
        $attempts = $this->db->load('login_attempts');
        $key = md5($username . $ip);
        $attempts = array_filter($attempts, fn($a) => ($a['key'] ?? '') !== $key);
        $this->db->save('login_attempts', array_values($attempts));
    }
    
    // ===== Remember Me =====
    public function setRememberToken(int $userId): void {
        $this->createRememberToken($userId);
    }
    
    private function createRememberToken(int $userId): void {
        $tokenLength = defined('REMEMBER_ME_TOKEN_LENGTH') ? REMEMBER_ME_TOKEN_LENGTH : 64;
        $days = defined('REMEMBER_ME_DAYS') ? REMEMBER_ME_DAYS : 30;
        
        $token = bin2hex(random_bytes($tokenLength / 2));
        $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        
        $tokens = $this->db->load('remember_tokens');
        $tokens[] = [
            'user_id' => $userId,
            'token' => hash('sha256', $token),
            'expires' => $expires,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // 만료된 토큰 정리
        $tokens = array_filter($tokens, fn($t) => strtotime($t['expires'] ?? '0') > time());
        $this->db->save('remember_tokens', array_values($tokens));
        
        setcookie('remember_token', $token, ['expires' => time() + ($days * 86400), 'path' => '/', 'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    }
    
    public function checkRememberToken(): bool {
        if (!isset($_COOKIE['remember_token'])) return false;
        
        // 이미 로그인 상태면 스킵 (동시 요청에서 중복 체크 방지)
        if (isset($_SESSION['user_id'])) return true;
        
        $token = $_COOKIE['remember_token'];
        $hashedToken = hash('sha256', $token);
        
        // ★ (2026-10-29 18:30, 22차 점검) IP·국가 제한 확인(로그인과 같은 규칙) — 종전엔 '로그인 유지' 토큰이 있으면 막힌 IP·국가에서도 그대로 자동 로그인됐음
        if (!$this->checkIPRestriction($this->getClientIP())['allowed']) return false;
        
        $tokens = $this->db->load('remember_tokens');
        foreach ($tokens as $t) {
            if (hash_equals($t['token'] ?? '', $hashedToken) && strtotime($t['expires'] ?? '0') > time()) {
                $user = $this->db->find('users', ['id' => $t['user_id']]);
                // 활성 상태 체크 (status 또는 is_active)
                if ($user) {
                    $status = $user['status'] ?? 'active';
                    $isActive = $user['is_active'] ?? 1;
                    
                    if ($status === 'active' && $isActive) {
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['last_activity'] = time();
                        $_SESSION['remember_me'] = true;
                        
                        // 토큰 로테이션 비활성화 — race condition 방지
                        // 30일 토큰 만료로 충분한 보안 확보
                        
                        return true;
                    }
                }
            }
        }
        
        // 토큰 매칭 실패 — 로테이션 직후 race condition일 수 있으므로 쿠키를 바로 삭제하지 않음
        // 쿠키는 유지하되 세션 복구 실패로 처리 (다음 요청에서 재시도)
        return false;
    }
    
    private function deleteRememberToken(string $token): void {
        $hashedToken = hash('sha256', $token);
        $tokens = $this->db->load('remember_tokens');
        $tokens = array_filter($tokens, fn($t) => ($t['token'] ?? '') !== $hashedToken);
        $this->db->save('remember_tokens', array_values($tokens));
    }

    /**
     * ★ (2026-10-29 03:11, 16차) 한 사용자의 로그인 유지 토큰을 모두 폐기 — 비밀번호 변경·초기화·2FA 켜기 때 호출.
     *   종전엔 탈퇴/삭제(deleteUserData)에서만 지워, 훔친 remember 쿠키가 비밀번호를 바꾸거나 2FA 를 켜도 최대 10년간 로그인됐음
     */
    public function deleteRememberTokensForUser(int $userId): void {
        $tokens = $this->db->load('remember_tokens');
        $tokens = array_filter($tokens, fn($t) => (int)($t['user_id'] ?? 0) !== $userId);
        $this->db->save('remember_tokens', array_values($tokens));
    }
    
    // ===== 세션 관리 =====
    private function recordSession(int $userId, string $ip): void {
        if (!defined('SESSION_TRACKING_ENABLED') || !SESSION_TRACKING_ENABLED) return;
        if (defined('FS_WEBDAV_REQUEST')) return;   // ★ (2026-10-29 09:14, 20차) WebDAV(쿠키 없는 1회성 세션)는 기록하지 않음 — mydav.php 참고
        
        $sessions = $this->db->load('sessions');
        $sessionId = session_id();
        
        $found = false;
        foreach ($sessions as &$s) {
            if (($s['session_id'] ?? '') === $sessionId) {
                $s['last_activity'] = date('Y-m-d H:i:s');
                $s['ip'] = $ip;
                $found = true;
                break;
            }
        }
        unset($s);
        
        if (!$found) {
            $sessions[] = [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'ip' => $ip,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at' => date('Y-m-d H:i:s'),
                'last_activity' => date('Y-m-d H:i:s')
            ];
        }
        
        // 동시 세션 제한
        if (defined('SESSION_MAX_CONCURRENT') && SESSION_MAX_CONCURRENT > 0) {
            // ★ (2026-10-30 01:50, 26차 점검) 동시 세션 수는 하루 안에 쓴 세션만 셈 — 25차에 목록 보관을 세션 수명(제한 없음이면 30일)으로 늘리면서, 쿠키를 잃고 '로그인 유지'로
            //   다시 들어온 기기의 죽은 기록까지 수에 들어가 며칠 쉰 살아 있는 세션이 밀려 끊겼음(로그인 유지 토큰까지 지워짐). 하루 넘은 기록은 목록·끊기 대상으로만 남음
            $userSessions = array_filter($sessions, fn($s) => ($s['user_id'] ?? 0) === $userId && (($s['session_id'] ?? '') === $sessionId || strtotime($s['last_activity'] ?? '0') > time() - 86400));
            if (count($userSessions) > SESSION_MAX_CONCURRENT) {
                usort($userSessions, fn($a, $b) => strtotime($a['last_activity'] ?? '0') - strtotime($b['last_activity'] ?? '0'));
                $toRemove = array_slice($userSessions, 0, count($userSessions) - SESSION_MAX_CONCURRENT);
                $this->revokeSessionIds(array_map(fn($r) => $r['session_id'] ?? '', $toRemove));   // ★ (2026-10-29 03:11, 16차) 밀려난 세션은 실제로 끊음
                foreach ($toRemove as $r) {
                    $sessions = array_filter($sessions, fn($s) => ($s['session_id'] ?? '') !== ($r['session_id'] ?? ''));
                }
            }
        }
        
        // 24시간 이상 비활성 세션 정리
        // ★ (2026-10-30 01:10, 25차 재검토) PHP 세션 수명(세션 유지 '제한 없음'이면 30일, config.php)만큼 — 24시간으로 두면 하루 넘게 쉰 기기 세션이 목록에서 빠져
        //   '세션 종료'·'다른 세션 모두 종료'로 끊을 수 없고 동시 세션 수에도 안 잡힌 채 돌아와 로그인됐음(25차 수명 변경과 맞춤)
        $_sessLife = max(86400, (int)ini_get('session.gc_maxlifetime'));
        $sessions = array_filter($sessions, fn($s) => strtotime($s['last_activity'] ?? '0') > time() - $_sessLife);
        
        $this->db->save('sessions', array_values($sessions));
    }
    
    private function removeSession(int $userId, string $sessionId): void {
        $sessions = $this->db->load('sessions');
        $sessions = array_filter($sessions, fn($s) => !(($s['user_id'] ?? 0) === $userId && ($s['session_id'] ?? '') === $sessionId));
        $this->db->save('sessions', array_values($sessions));
    }

    /**
     * ★ (2026-10-29 03:11, 16차) 강제 종료된 세션 ID 목록(revoked_sessions.json: session_id→끊은 시각).
     *   '세션 종료'·'다른 세션 모두 종료'·동시 세션 초과 정리에서 지운 세션 ID 를 여기에 적어, 그 세션의 다음 요청을 isLoggedIn 이 끊음.
     *   세션은 어차피 24시간이면 만료되므로 그보다 오래된 항목은 지움(파일이 계속 커지지 않게)
     */
    private function revokeSessionIds(array $sessionIds): void {
        $sessionIds = array_values(array_filter(array_map('strval', $sessionIds), fn($x) => $x !== ''));
        if (!$sessionIds) return;
        $revoked = $this->db->load('revoked_sessions');
        $now = time();
        foreach ($sessionIds as $sid) $revoked[$sid] = $now;
        $_sessLife = max(86400, (int)ini_get('session.gc_maxlifetime'));   // ★ (2026-10-30 01:10, 25차 재검토) 끊은 세션 기록도 PHP 세션 수명만큼 보관(종전 하루 — 수명이 30일이면 하루 뒤 같은 세션으로 다시 들어올 수 있었음)
        foreach ($revoked as $sid => $ts) { if (!is_int($ts) || $now - (int)$ts > $_sessLife) unset($revoked[$sid]); }
        $this->db->save('revoked_sessions', $revoked);
    }

    private function isSessionRevoked(string $sessionId): bool {
        if ($sessionId === '') return false;
        $revoked = $this->db->load('revoked_sessions');
        return isset($revoked[$sessionId]);
    }

    /**
     * ★ (2026-10-29 03:11, 16차) 그 계정이 지금도 로그인 상태를 유지해도 되는지 — 정지(기간 지난 건 제외)·비활성·삭제 대기면 false.
     *   로그인(login)의 상태 검사와 같은 기준. 계정을 못 찾으면(삭제됨) 로그인 유지 불가
     */
    private function isAccountUsable(int $userId): bool {
        $u = $this->db->find('users', ['id' => $userId]);
        if (!$u) return false;
        // ★ (2026-10-29 18:30, 22차 점검) 세션에 적힌 아이디와 계정 아이디가 다르면(삭제된 사용자의 번호를 새 사용자가 받은 경우) 끊음 —
        //   종전엔 번호만 봐서, 사용자를 지운 뒤 새로 만든 사용자가 같은 번호를 받으면 지운 사람의 남은 세션이 새 사용자로 로그인된 채가 됐음
        if (isset($_SESSION['user_id'], $_SESSION['username']) && (int)$_SESSION['user_id'] === $userId && $_SESSION['username'] !== ''
            && strtolower((string)$_SESSION['username']) !== strtolower((string)($u['username'] ?? ''))) return false;
        $status = $u['status'] ?? 'active';
        if ($status === 'suspended') {
            $until = $u['suspend_until'] ?? null;
            // 종료일이 지났으면 아직 로그인 가능(다음 로그인 때 자동 활성화됨). 그 외 정지는 끊음
            if (!($until && strtotime($until) < strtotime('today'))) return false;
        } elseif ($status !== 'active') {
            return false; // pending·deleted 등
        }
        if (!($u['is_active'] ?? 1)) return false;
        return true;
    }
    
    public function getSessions(): array {
        $userId = $this->getUserId();
        if (!$userId) return [];
        
        $sessions = $this->db->load('sessions');
        $userSessions = array_filter($sessions, fn($s) => ($s['user_id'] ?? 0) === $userId);
        
        $currentSessionId = session_id();
        return array_map(function($s) use ($currentSessionId) {
            return [
                'session_id' => substr($s['session_id'] ?? '', 0, 8) . '...',
                'ip' => $s['ip'] ?? '',
                // ★ raw user_agent 반환 (클라이언트가 parseUserAgentDetails로 가공 — 로그인 기록과 동일 패턴)
                //   이전: parseUserAgent로 "Chrome / Mac" 형식 가공 → iPhone이 Mac으로 잘못 표시되는 버그
                'user_agent' => $s['user_agent'] ?? '',
                'created_at' => $s['created_at'] ?? '',
                'last_activity' => $s['last_activity'] ?? '',
                'is_current' => ($s['session_id'] ?? '') === $currentSessionId
            ];
        }, array_values($userSessions));
    }
    
    public function terminateSession(string $sessionIdPrefix): array {
        $userId = $this->getUserId();
        if (!$userId) return ['success' => false, 'error' => __('api_login_required')];
        
        $sessions = $this->db->load('sessions');
        $found = false;
        $prefix = rtrim($sessionIdPrefix, '.');
        
        $revokedId = '';
        foreach ($sessions as $key => $s) {
            if (($s['user_id'] ?? 0) === $userId && strpos($s['session_id'] ?? '', $prefix) === 0) {
                $revokedId = $s['session_id'] ?? '';
                unset($sessions[$key]);
                $found = true;
                break;
            }
        }

        if (!$found) {
            return ['success' => false, 'error' => __('api_err_session_not_found', '세션을 찾을 수 없습니다.')];
        }

        $this->db->save('sessions', array_values($sessions));
        if ($revokedId !== '') $this->revokeSessionIds([$revokedId]);   // ★ (2026-10-29 03:11, 16차) 기록만 지우지 않고 실제로 끊음
        return ['success' => true];
    }
    
    public function terminateAllOtherSessions(): array {
        $userId = $this->getUserId();
        if (!$userId) return ['success' => false, 'error' => __('api_login_required')];
        
        $currentSessionId = session_id();
        $sessions = $this->db->load('sessions');
        // ★ (2026-10-29 03:11, 16차) 끊는 세션 ID 를 모아 실제로 끊음(종전엔 기록만 지워 계속 로그인됐음)
        $revokeIds = [];
        foreach ($sessions as $s) {
            if (($s['user_id'] ?? 0) === $userId && ($s['session_id'] ?? '') !== $currentSessionId) $revokeIds[] = $s['session_id'] ?? '';
        }
        $sessions = array_filter($sessions, fn($s) => !(($s['user_id'] ?? 0) === $userId && ($s['session_id'] ?? '') !== $currentSessionId));
        $this->db->save('sessions', array_values($sessions));
        $this->revokeSessionIds($revokeIds);

        return ['success' => true];
    }
    
    private function parseUserAgent(string $ua): string {
        if (empty($ua)) return __('unknown');
        
        $browser = __('unknown');
        $os = __('unknown');
        
        if (preg_match('/Edg/i', $ua)) $browser = 'Edge';
        elseif (preg_match('/Chrome/i', $ua)) $browser = 'Chrome';
        elseif (preg_match('/Firefox/i', $ua)) $browser = 'Firefox';
        elseif (preg_match('/Safari/i', $ua)) $browser = 'Safari';
        elseif (preg_match('/MSIE|Trident/i', $ua)) $browser = 'IE';
        
        if (preg_match('/Windows/i', $ua)) $os = 'Windows';
        elseif (preg_match('/Mac/i', $ua)) $os = 'Mac';
        elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
        elseif (preg_match('/Android/i', $ua)) $os = 'Android';
        elseif (preg_match('/iPhone|iPad/i', $ua)) $os = 'iOS';
        
        return "{$browser} / {$os}";
    }
    
    // ===== 로그인 로그 =====
    private function logLogin(string $username, bool $success, string $ip, string $reason): void {
        if (!defined('LOGIN_LOG_ENABLED') || !LOGIN_LOG_ENABLED) return;
        
        try {
            $logs = $this->db->load('login_logs');
            
            // 국가 코드 가져오기
            $country = '';
            try {
                $country = $this->getCountryFromIP($ip);
            } catch (Exception $e) {
                $country = '';
            }
            
            $logs[] = [
                'id' => uniqid('log_'),
                'username' => $username,
                'success' => $success,
                'ip' => $ip,
                'country' => $country,
                'reason' => $reason,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // 최대 10,000건 제한 (로그 파일 무한 성장 방지)
            if (count($logs) > 10000) {
                $logs = array_slice($logs, -10000);
            }
            
            $this->db->save('login_logs', array_values($logs));
        } catch (Exception $e) {
            // 로그 실패는 무시
        }
    }
    
    // 로그인 로그 삭제 (관리자)
    public function deleteLoginLogs(array $ids): array {
        $user = $this->getUser();
        // ★ (2026-10-29 21:15, 24차 점검) '로그인 기록' 권한을 받은 부관리자도(api.php 의 requireAdminPerm('logins') 와 같은 기준) — 종전엔 관리자만이라 권한을 줘도 실패했음
        if (!$user || !$this->hasAdminPerm('logins')) {
            return ['success' => false, 'error' => __('no_permission')];
        }
        
        $logs = $this->db->load('login_logs');
        $logs = array_filter($logs, fn($l) => !in_array($l['id'] ?? '', $ids));
        $this->db->save('login_logs', array_values($logs));
        
        return ['success' => true, 'deleted' => count($ids)];
    }
    
    // 전체 로그인 로그 삭제 (관리자)
    public function deleteAllLoginLogs(): array {
        $user = $this->getUser();
        if (!$user || !$this->hasAdminPerm('logins')) {   // ★ (2026-10-29 21:19, 24차 점검) 위와 같음
            return ['success' => false, 'error' => __('no_permission')];
        }
        
        $this->db->save('login_logs', []);
        return ['success' => true];
    }
    
    // 오래된 로그인 로그 삭제 (관리자)
    public function deleteOldLoginLogs(int $days): array {
        $user = $this->getUser();
        if (!$user || !$this->hasAdminPerm('logins')) {   // ★ (2026-10-29 21:19, 24차 점검) 위와 같음
            return ['success' => false, 'error' => __('no_permission')];
        }
        
        $logs = $this->db->load('login_logs');
        $cutoff = strtotime("-{$days} days");
        $before = count($logs);
        $logs = array_filter($logs, fn($l) => strtotime($l['created_at'] ?? '0') > $cutoff);
        $this->db->save('login_logs', array_values($logs));
        
        return ['success' => true, 'deleted' => $before - count($logs)];
    }
    
    public function getLoginLogs(int $page = 1, int $perPage = 20, bool $all = false): array {
        if ($perPage < 1) $perPage = 20;   // ★ (2026-10-29 03:19, 16차) per_page=0 이면 ceil(0 나눗셈) 으로 죽었음 — 1 미만은 기본값
        if ($page < 1) $page = 1;
        $user = $this->getUser();
        if (!$user) return ['logs' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
        
        $logs = $this->db->load('login_logs');
        
        // 관리자가 아니거나, all=false면 자신의 로그만
        // ★ (2026-10-29 21:15, 24차 점검) 전체 보기도 '로그인 기록' 권한 부관리자 허용(위와 같음)
        if (!$this->hasAdminPerm('logins') || !$all) {
            $currentUsername = strtolower(trim($user['username'] ?? ''));
            $logs = array_filter($logs, function($l) use ($currentUsername) {
                $logUsername = strtolower(trim($l['username'] ?? ''));
                return $logUsername === $currentUsername;
            });
            $logs = array_values($logs);
        }
        
        // 최신순 정렬
        usort($logs, fn($a, $b) => strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0'));
        
        $total = count($logs);
        $totalPages = ceil($total / $perPage);
        $page = max(1, min($page, $totalPages ?: 1));
        $offset = ($page - 1) * $perPage;
        
        $pagedLogs = array_slice($logs, $offset, $perPage);
        
        // display_name 추가
        $users = $this->db->load('users') ?: [];
        $userMap = [];
        foreach ($users as $u) {
            $userMap[strtolower(trim($u['username'] ?? ''))] = $u['display_name'] ?? $u['username'] ?? '';
        }
        foreach ($pagedLogs as &$log) {
            $uname = strtolower(trim($log['username'] ?? ''));
            $log['display_name'] = $userMap[$uname] ?? $log['username'] ?? '';
        }
        unset($log);
        
        return [
            'logs' => $pagedLogs,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages
        ];
    }
    
    // ===== 2FA (TOTP) =====
    
    /**
     * 2FA 설정 시작 - 시크릿 키 생성
     */
    public function setup2FA(): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => __('api_login_required')];
        }
        
        // 이미 활성화되어 있으면 거부
        if (!empty($user['2fa_enabled'])) {
            return ['success' => false, 'error' => __('api_2fa_already_enabled', '2FA가 이미 활성화되어 있습니다.')];
        }
        
        require_once __DIR__ . '/TOTP.php';
        
        // 새 시크릿 생성
        $secret = TOTP::generateSecret();
        
        // 임시로 세션에 저장 (활성화 전까지)
        $_SESSION['2fa_setup_secret'] = $secret;
        
        // QR 코드 URI 생성
        $issuer = defined('TOTP_ISSUER') ? TOTP_ISSUER : (defined('SITE_NAME') ? SITE_NAME : 'WebHard');
        $uri = TOTP::getUri($secret, $user['username'], $issuer);
        $qrUrl = TOTP::getQRCodeUrl($uri, 200);
        
        return [
            'success' => true,
            'secret' => $secret,
            'qr_url' => $qrUrl,
            'uri' => $uri
        ];
    }
    
    /**
     * 2FA 활성화 확인 - OTP 코드로 검증 후 활성화
     */
    public function enable2FA(string $code): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => __('api_login_required')];
        }
        
        $secret = $_SESSION['2fa_setup_secret'] ?? '';
        if (empty($secret)) {
            return ['success' => false, 'error' => __('api_2fa_setup_first', '2FA 설정을 먼저 시작하세요.')];
        }
        
        require_once __DIR__ . '/TOTP.php';
        
        // 코드 검증
        if (!TOTP::verify($secret, $code)) {
            return ['success' => false, 'error' => __('api_err_invalid_otp', '인증 코드가 올바르지 않습니다.')];
        }
        
        // 백업 코드 생성
        $backupCodes = TOTP::generateBackupCodes(10);
        $hashedCodes = array_map(fn($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $backupCodes);
        
        // 사용자 정보 업데이트
        $this->db->update('users', ['id' => $user['id']], [
            '2fa_enabled' => true,
            '2fa_secret' => $this->encrypt2FASecret($secret),
            '2fa_backup_codes' => $hashedCodes,
            '2fa_enabled_at' => date('Y-m-d H:i:s')
        ]);
        
        // 세션 정리
        unset($_SESSION['2fa_setup_secret']);
        $this->deleteRememberTokensForUser((int)$user['id']);   // ★ (2026-10-29 03:11, 16차) 2FA 를 켜면 기존 로그인 유지 토큰 폐기(그 토큰은 2FA 를 안 거치므로)

        // 캐시 갱신
        self::$user = null;

        return [
            'success' => true,
            'message' => __('api_2fa_enabled', '2FA가 활성화되었습니다.'),
            'backup_codes' => $backupCodes
        ];
    }
    
    /**
     * 2FA 비활성화
     */
    public function disable2FA(string $password, string $code = ''): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => __('api_login_required')];
        }
        
        // DB에서 비밀번호 포함해서 다시 조회
        $fullUser = $this->db->find('users', ['id' => $user['id']]);
        if (!$fullUser) {
            return ['success' => false, 'error' => __('api_err_user_not_found', '사용자를 찾을 수 없습니다.')];
        }
        
        // 비밀번호 확인
        if (!password_verify($password, $fullUser['password'])) {
            return ['success' => false, 'error' => __('api_err_password_incorrect', '비밀번호가 올바르지 않습니다.')];
        }
        
        // 2FA 활성화 상태면 OTP 검증
        // ★ (2026-10-29 03:11, 16차) 코드를 반드시 검증 — 종전엔 '&& !empty($code)' 라 code 를 빈 값으로 보내면 OTP 검사를 건너뛰어
        //   비밀번호만으로 2FA 를 끌 수 있었음(재현). 백업 코드도 인정
        if (!empty($user['2fa_enabled'])) {
            require_once __DIR__ . '/TOTP.php';
            $secret = $this->decrypt2FASecret($fullUser['2fa_secret'] ?? '');
            if ($secret === '' || $code === '' || (!TOTP::verify($secret, $code) && !$this->verifyBackupCode($user['id'], $code))) {
                return ['success' => false, 'error' => __('api_err_invalid_otp', '인증 코드가 올바르지 않습니다.')];
            }
        }

        // 2FA 정보 제거
        $this->db->update('users', ['id' => $user['id']], [
            '2fa_enabled' => false,
            '2fa_secret' => null,
            '2fa_backup_codes' => null,
            '2fa_enabled_at' => null
        ]);
        
        // 캐시 갱신
        self::$user = null;
        
        return ['success' => true, 'message' => __('api_2fa_disabled', '2FA가 비활성화되었습니다.')];
    }
    
    /**
     * 2FA 검증 (로그인 2단계)
     */
    /** ★ (2026-10-28 19:55, 6차 점검) 2FA 틀린 횟수(사용자 단위, 15분 창) */
    private function twoFaFailFile(int $uid): string {
        $dir = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/rate_limits';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . '/2fa_' . $uid . '.json';
    }
    /** ★ (2026-10-28 20:42, 7차 점검) 잠금 안에서 15분 창의 시도 수를 세어 10회 미만이면 이번 시도를 기록하고 true, 아니면 false */
    private function twoFaAttemptReserve(int $uid, int $max = 10, int $window = 900): bool {
        $fp = @fopen($this->twoFaFailFile($uid), 'c+');
        if (!$fp) return true;   // 기록 파일을 못 열면 막지 않음(종전 동작과 같게 — 로그인 자체를 막지 않도록)
        $ok = true;
        if (flock($fp, LOCK_EX)) {
            $now = time();
            $data = @json_decode((string)stream_get_contents($fp), true) ?: [];
            $data = array_values(array_filter($data, fn($t) => ($now - (int)$t) < $window));
            if (count($data) >= $max) {
                $ok = false;
            } else {
                $data[] = $now;
                ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($data)); fflush($fp);
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $ok;
    }
    // ★ (2026-10-28 21:19, 8차 점검) 지우지 않고 잠금 안에서 비움 — 윈도우에선 다른 요청이 열어 둔 파일 삭제가 조용히 실패해 기록이 남을 수 있었음
    private function twoFaFailClear(int $uid): void {
        $f = $this->twoFaFailFile($uid);
        if (!is_file($f)) return;
        $fp = @fopen($f, 'c+'); if (!$fp) return;
        if (flock($fp, LOCK_EX)) { ftruncate($fp, 0); fflush($fp); flock($fp, LOCK_UN); }
        fclose($fp);
    }

    /**
     * ★ (2026-10-29 03:51, 17차) SSO(LDAP·OIDC·SAML·Kerberos) 로그인 공통 관문 — 일반 로그인(login)과 같은 기준을 SSO 에도 적용.
     *   종전엔 SSO 로 들어오면 IP/국가 제한·2FA 를 모두 건너뛰고 바로 로그인됐음.
     *   반환: ['success'=>false,'error'=>…] 거부 / ['success'=>true,'2fa_required'=>true] 2FA 대기(세션에 pending 저장) / ['success'=>true] 바로 로그인해도 됨
     */
    public function ssoGate(array $fsUser, bool $remember, string $provider): array {
        $ip = $this->getClientIP();
        $uname = (string)($fsUser['username'] ?? '');
        $ipCheck = $this->checkIPRestriction($ip);
        if (!$ipCheck['allowed']) {
            $this->logLogin($uname, false, $ip, $ipCheck['reason'] . ' (SSO ' . $provider . ')');
            return ['success' => false, 'error' => $ipCheck['reason']];
        }
        $full = $this->db->find('users', ['id' => (int)($fsUser['id'] ?? 0)]);
        if ($full && !empty($full['2fa_enabled'])) {
            $_SESSION['2fa_pending_user'] = [
                'id' => $full['id'],
                'username' => $full['username'],
                'remember' => $remember,
                'started_at' => time(),
                'sso_provider' => $provider
            ];
            return ['success' => true, '2fa_required' => true, 'message' => __('api_2fa_required', '2단계 인증이 필요합니다.')];
        }
        $this->logLogin($uname, true, $ip, 'SSO ' . $provider);
        // ★ (2026-10-29 09:55, 20차 점검) 로컬 로그인처럼 개인 폴더('내 파일') 스토리지 보장 — 종전엔 로컬 로그인에서만 만들어, SSO 로 생긴 계정은 '내 파일' 이 없었음(재현)
        if ($full && defined('AUTO_CREATE_USER_FOLDER') && AUTO_CREATE_USER_FOLDER) {
            $this->ensureUserFolder($full);
        }
        return ['success' => true];
    }

    /** ★ (2026-10-29 03:51, 17차) LDAP 로그인 시도 제한 — 일반 로그인과 같은 잠금(isLockedOut·recordFailedAttempt) 사용. 종전엔 횟수 제한이 없어 비밀번호 대입·AD 계정 잠금 유발이 가능했음 */
    //   ★ (2026-10-29 07:45, 18차) 잠금 기준 아이디는 앞뒤 공백 없앤 소문자 — AD 는 대소문자를 안 가려 'Admin'·'ADMIN' 으로 바꿔 가며 시도 횟수를 늘릴 수 있었음
    private function ssoLockKey(string $username): string {
        return 'ldap:' . mb_strtolower(trim($username), 'UTF-8');
    }
    public function ssoAttemptAllowed(string $username): bool {
        return !$this->isLockedOut($this->ssoLockKey($username), $this->getClientIP());
    }
    public function ssoAttemptFailed(string $username): void {
        $ip = $this->getClientIP();
        $this->recordFailedAttempt($this->ssoLockKey($username), $ip);
        $this->logLogin($username, false, $ip, 'LDAP 인증 실패');
    }
    public function ssoAttemptSucceeded(string $username): void {
        $this->clearFailedAttempts($this->ssoLockKey($username), $this->getClientIP());
    }
    /**
     * ★ (2026-10-29 07:45, 18차) LDAP 로그인 전 IP·국가 제한 — 일반 로그인처럼 인증 '전' 에 확인. 종전(17차)엔 LDAP 인증 뒤 ssoGate 에서만 봐서,
     *   막힌 IP 에서도 응답 문구 차이로 비밀번호가 맞는지 알 수 있었고 계정 자동 생성도 먼저 됐음. 거부면 사유, 허용이면 null
     */
    public function ssoIpDenied(string $username, string $provider): ?string {
        $ip = $this->getClientIP();
        $ipCheck = $this->checkIPRestriction($ip);
        if ($ipCheck['allowed']) return null;
        $this->logLogin($username, false, $ip, $ipCheck['reason'] . ' (SSO ' . $provider . ')');
        return (string)$ipCheck['reason'];
    }

    /** ★ (2026-10-29 10:27, 20차 점검) WebDAV 인증 전 IP·국가 제한 — 웹 로그인과 같은 규칙. 막히면 로그인 기록에 남기고 true */
    public function webdavIpDenied(string $username): bool {
        $ip = $this->getClientIP();
        $ipCheck = $this->checkIPRestriction($ip);
        if ($ipCheck['allowed']) return false;
        $this->logLogin($username, false, $ip, $ipCheck['reason'] . ' (WebDAV)');
        return true;
    }

    public function verify2FA(string $code): array {
        $pendingUser = $_SESSION['2fa_pending_user'] ?? null;
        if (!$pendingUser) {
            return ['success' => false, 'error' => __('api_2fa_not_pending', '2FA 인증 대기 상태가 아닙니다.')];
        }
        // ★ (2026-10-28 18:22, 소스 점검 5단계) 2FA 대기 상태 만료 — 종전엔 만료가 없어 pending 을 무한정 두고 코드를 계속 시도할 수 있었음(10분)
        if (isset($pendingUser['started_at']) && (time() - (int)$pendingUser['started_at']) > 600) {
            unset($_SESSION['2fa_pending_user']);
            return ['success' => false, 'error' => __('api_2fa_expired', '인증 시간이 지났습니다. 다시 로그인하세요.')];
        }
        // ★ (2026-10-28 18:22, 소스 점검 5단계) 코드 무차별 대입 제한 — 틀린 횟수를 세어 너무 많으면 막음.
        //   종전엔 횟수 제한이 없어 시간창 ±1(약 3/10^6/회)을 여러 요청으로 나눠 맞출 수 있었고, 매 시도마다 백업코드 10개를 password_verify 해 부하도 컸음
        // ★ (2026-10-28 19:55, 6차 점검) 세션 단위로만 세어 다시 로그인하면(새 세션) 횟수가 0 으로 돌아갔음(주석의 '사용자 단위'와 달랐음) →
        //   사용자별 파일(data/rate_limits/2fa_<id>.json)에 15분 동안 틀린 시각을 쌓아 10회면 막음. 성공하면 지움
        // ★ (2026-10-28 20:42, 7차 점검) 세기와 더하기를 한 잠금 안에서(시도를 먼저 1회로 기록, 맞으면 지움) — 종전엔 센 뒤 따로 더해
        //   여러 세션에서 동시에 보내면 모두 10회 미만으로 보고 통과할 수 있었음
        $tfUid = (int)($pendingUser['id'] ?? 0);
        if (!$this->twoFaAttemptReserve($tfUid)) {
            unset($_SESSION['2fa_pending_user']);
            return ['success' => false, 'error' => __('api_err_too_many_attempts', '시도가 너무 많습니다. 잠시 후 다시 시도하세요.')];
        }
        
        $user = $this->db->find('users', ['id' => $pendingUser['id']]);
        if (!$user) {
            unset($_SESSION['2fa_pending_user']);
            return ['success' => false, 'error' => __('api_err_user_not_found', '사용자를 찾을 수 없습니다.')];
        }
        
        require_once __DIR__ . '/TOTP.php';
        
        $secret = $this->decrypt2FASecret($user['2fa_secret'] ?? '');
        $isValid = false;
        $usedBackup = false;
        
        // TOTP 코드 검증
        if (TOTP::verify($secret, $code)) {
            $isValid = true;
        }
        // 백업 코드 검증
        elseif ($this->verifyBackupCode($user['id'], $code)) {
            $isValid = true;
            $usedBackup = true;
        }
        
        if (!$isValid) {
            // (시도는 위 twoFaAttemptReserve 에서 이미 1회로 기록됨 — 사용자 단위)
            return ['success' => false, 'error' => __('api_err_invalid_otp', '인증 코드가 올바르지 않습니다.')];
        }
        $this->twoFaFailClear($tfUid);   // ★ 성공 시 초기화
        
        // 로그인 완료 — Session Fixation 방지
        // ★ session_regenerate_id 전에 sessions DB에서 이전 session_id 제거 (유령 세션 방지)
        if (isset($_SESSION['user_id'])) {
            $this->removeSession((int)$_SESSION['user_id'], session_id());
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        // ★ (2026-10-29 10:44, 20차 점검) 일반 로그인처럼 이름·역할도 — 종전엔 2FA 로 들어오면 비어 있어 사용자 삭제 기록의 '삭제한 사람' 이 'admin' 으로 남는 등 어긋났음
        $_SESSION['username'] = $user['username'] ?? '';
        $_SESSION['role'] = $user['role'] ?? 'user';
        $_SESSION['last_activity'] = time();
        self::$user = $user;
        
        // Remember Me 처리
        if ($pendingUser['remember'] ?? false) {
            $this->createRememberToken($user['id']);
            $_SESSION['remember_me'] = true;
        }
        
        // 마지막 로그인 시간 업데이트
        $this->db->update('users', ['id' => $user['id']], [
            'last_login' => date('Y-m-d H:i:s')
        ]);
        
        // 세션 정리
        unset($_SESSION['2fa_pending_user']);
        
        // 로그인 로그
        $this->logLogin($user['username'], true, $this->getClientIP(), __('twofa_complete') . ($usedBackup ? __('twofa_backup_used') : ''));
        
        return [
            'success' => true,
            'user' => $this->sanitizeUser($user),
            'used_backup' => $usedBackup
        ];
    }
    
    /**
     * 백업 코드 검증 및 사용 처리
     */
    private function verifyBackupCode(int $userId, string $code): bool {
        $user = $this->db->find('users', ['id' => $userId]);
        if (!$user || empty($user['2fa_backup_codes'])) {
            return false;
        }
        
        $codes = $user['2fa_backup_codes'];
        $cleanCode = str_replace('-', '', $code);
        
        foreach ($codes as $index => $hashedCode) {
            if (password_verify($cleanCode, $hashedCode)) {
                // 사용한 코드 제거
                unset($codes[$index]);
                $this->db->update('users', ['id' => $userId], [
                    '2fa_backup_codes' => array_values($codes)
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 백업 코드 재생성
     */
    public function regenerateBackupCodes(string $password): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => __('api_login_required')];
        }
        
        // DB에서 비밀번호 포함해서 다시 조회
        $fullUser = $this->db->find('users', ['id' => $user['id']]);
        if (!$fullUser) {
            return ['success' => false, 'error' => __('api_err_user_not_found', '사용자를 찾을 수 없습니다.')];
        }
        
        // 비밀번호 확인
        if (!password_verify($password, $fullUser['password'])) {
            return ['success' => false, 'error' => __('api_err_password_incorrect', '비밀번호가 올바르지 않습니다.')];
        }
        
        if (empty($user['2fa_enabled'])) {
            return ['success' => false, 'error' => __('api_2fa_not_enabled', '2FA가 활성화되어 있지 않습니다.')];
        }
        
        require_once __DIR__ . '/TOTP.php';
        
        // 새 백업 코드 생성
        $backupCodes = TOTP::generateBackupCodes(10);
        $hashedCodes = array_map(fn($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $backupCodes);
        
        $this->db->update('users', ['id' => $user['id']], [
            '2fa_backup_codes' => $hashedCodes
        ]);
        
        return [
            'success' => true,
            'backup_codes' => $backupCodes
        ];
    }
    
    /**
     * 2FA 상태 확인
     */
    public function get2FAStatus(): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => __('api_login_required')];
        }
        // ★ (2026-10-29 21:15, 24차 점검) 남은 백업 코드 수는 DB 의 전체 기록으로 셈 — getUser() 는 백업 코드를 지운 정보라 늘 0개로 보였음(재현)
        $_fu = $this->db->find('users', ['id' => $user['id']]);
        if (is_array($_fu)) $user['2fa_backup_codes'] = $_fu['2fa_backup_codes'] ?? [];
        
        $backupCodesCount = 0;
        if (!empty($user['2fa_backup_codes']) && is_array($user['2fa_backup_codes'])) {
            $backupCodesCount = count($user['2fa_backup_codes']);
        }
        
        return [
            'success' => true,
            'enabled' => !empty($user['2fa_enabled']),
            'enabled_at' => $user['2fa_enabled_at'] ?? null,
            'backup_codes_remaining' => $backupCodesCount,
            'role' => $user['role'] ?? 'user'
        ];
    }
    
    /**
     * 2FA 시크릿 암호화 (AES-256-GCM)
     */
    private function encrypt2FASecret(string $secret): string {
        $key = $this->get2FAEncryptionKey();
        $iv = random_bytes(12); // GCM은 12바이트 IV 권장
        $tag = '';
        $encrypted = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        // 형식: 'gcm:' + base64(iv + tag + ciphertext) — 레거시 CBC와 구분
        return 'gcm:' . base64_encode($iv . $tag . $encrypted);
    }
    
    /**
     * 2FA 시크릿 복호화 (GCM + 레거시 CBC 폴백)
     */
    private function decrypt2FASecret(string $encrypted): string {
        if (empty($encrypted)) return '';
        
        $key = $this->get2FAEncryptionKey();
        
        // GCM 형식 (gcm: 접두사)
        if (str_starts_with($encrypted, 'gcm:')) {
            $data = base64_decode(substr($encrypted, 4));
            $iv = substr($data, 0, 12);
            $tag = substr($data, 12, 16);
            $ciphertext = substr($data, 28);
            $result = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $result !== false ? $result : '';
        }
        
        // 레거시 CBC 폴백 (기존 데이터 호환)
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv) ?: '';
    }
    
    /**
     * 2FA 암호화 키 가져오기
     */
    private function get2FAEncryptionKey(): string {
        // 설정에서 키를 가져오거나 기본값 사용
        $key = defined('TOTP_ENCRYPTION_KEY') ? TOTP_ENCRYPTION_KEY : 'webhard-2fa-default-key-change-me';
        return hash('sha256', $key, true);
    }
    
    /**
     * 사용자 정보 정제 (민감 정보 제거)
     */
    private function sanitizeUser(array $user): array {
        unset($user['password']);
        unset($user['2fa_secret']);
        unset($user['2fa_backup_codes']);
        return $user;
    }
    
    // ===== 비밀번호 찾기 (이메일) =====
    
    /**
     * 임시 비밀번호 발송
     */
    /**
     * 아이디 찾기 (이메일로)
     */
    public function findUsername(string $email): array {
        $users = $this->db->load('users') ?: [];
        
        // 해당 이메일로 등록된 사용자 찾기
        $foundUsers = [];
        foreach ($users as $user) {
            if (!empty($user['email']) && strtolower($user['email']) === strtolower($email)) {
                $foundUsers[] = $user;
            }
        }
        
        if (empty($foundUsers)) {
            // 보안: 이메일 존재 여부를 노출하지 않음
            return ['success' => true, 'message' => __('email_id_sent_if_exists')];
        }
        
        // 이메일 발송
        $siteName = defined('SITE_NAME') ? SITE_NAME : __('site_name_default');
        $subject = __f('email_find_id_subject', ['siteName' => $siteName]);
        $message = __('email_find_id_greeting');
        $message .= __('email_find_id_body');
        
        foreach ($foundUsers as $user) {
            $username = $user['username'];
            $displayName = $user['display_name'] ?? '';
            // 아이디 일부 마스킹 (앞 2자 + ***) 
            $maskedId = mb_substr($username, 0, 2) . str_repeat('*', max(1, mb_strlen($username) - 2));
            $message .= __f('email_find_id_item', ['maskedId' => $maskedId]);
            if ($displayName) {
                $message .= __f('email_find_id_displayname', ['displayName' => $displayName]);
            }
            $message .= "\n";
        }
        
        $message .= __('email_find_id_contact_admin');
        $message .= __('email_ignore_if_not_you');
        
        $sent = $this->sendEmail($email, $subject, $message);
        
        // 보안: 성공/실패 무관하게 동일 메시지
        return ['success' => true, 'message' => __('email_id_sent_if_exists')];
    }
    
    public function requestPasswordReset(string $username, string $email): array {
        // 사용자 찾기
        $user = $this->db->find('users', ['username' => $username]);
        
        if (!$user) {
            return ['success' => false, 'error' => __('username_not_found')];
        }
        
        // 이메일 확인
        $userEmail = $user['email'] ?? '';
        if (empty($userEmail)) {
            return ['success' => false, 'error' => __('no_email_registered')];
        }
        
        if (strtolower($userEmail) !== strtolower($email)) {
            return ['success' => false, 'error' => __('email_id_mismatch')];
        }
        
        // 임시 비밀번호 생성 (8자리)
        $tempPassword = $this->generateTempPassword();

        // ★ (2026-10-29 03:11, 16차) 메일을 먼저 보내고, 성공했을 때만 비밀번호를 바꿈 — 종전엔 비밀번호부터 바꾸고 메일을 보내,
        //   SMTP 미설정 등으로 메일이 실패하면 임시 비밀번호를 아무도 모르는 채 계정이 잠겼음(재현). 메일 실패 시 기존 비밀번호 유지
        $siteName = defined('SITE_NAME') ? SITE_NAME : __('site_name_default');
        $subject = __f('email_temp_pw_subject', ['siteName' => $siteName]);
        $message = __f('email_temp_pw_greeting', ['displayName' => $user['display_name']]);
        $message .= __('email_temp_pw_body');
        $message .= __f('email_temp_pw_id', ['username' => $username]);
        $message .= __f('email_temp_pw_value', ['password' => $tempPassword]);
        $message .= __('email_temp_pw_change');
        $message .= __('email_ignore_if_not_you');

        $sent = $this->sendEmail($email, $subject, $message);

        if (!$sent) {
            return ['success' => false, 'error' => __('api_err_email_send_failed', '이메일 발송에 실패했습니다. 관리자에게 문의하세요.')];
        }

        // 메일 발송 성공 → 비밀번호 업데이트 + 기존 로그인 유지 토큰 폐기
        $users = $this->db->load('users');
        foreach ($users as &$u) {
            if ($u['id'] === $user['id']) {
                $u['password'] = password_hash($tempPassword, PASSWORD_DEFAULT);
                $u['password_reset_required'] = true; // 로그인 후 변경 필요 표시
                $u['updated_at'] = date('Y-m-d H:i:s');
                break;
            }
        }
        unset($u);
        $this->db->save('users', $users);
        $this->deleteRememberTokensForUser((int)$user['id']);

        return ['success' => true, 'message' => __('api_temp_password_sent', '임시 비밀번호가 이메일로 발송되었습니다.')];
    }
    
    /**
     * 임시 비밀번호 생성
     */
    private function generateTempPassword(int $length = 8): string {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
    
    /**
     * 이메일 발송
     */
    private function sendEmail(string $to, string $subject, string $message): bool {
        // settings.json에서 SMTP 설정 로드
        $settings = $this->db->load('settings');
        $smtp = $settings['smtp'] ?? [];
        
        // SMTP 설정이 있으면 사용
        if (!empty($smtp['enabled']) && !empty($smtp['host'])) {
            return $this->sendEmailSMTP($to, $subject, $message, $smtp);
        }
        
        // config.php의 SMTP 설정이 있으면 사용 (하위 호환)
        if (defined('SMTP_HOST') && SMTP_HOST) {
            $smtp = [
                'host' => SMTP_HOST,
                'port' => defined('SMTP_PORT') ? SMTP_PORT : 587,
                'user' => defined('SMTP_USER') ? SMTP_USER : '',
                'pass' => defined('SMTP_PASS') ? SMTP_PASS : '',
                'from' => defined('SMTP_FROM') ? SMTP_FROM : '',
                'secure' => defined('SMTP_SECURE') ? SMTP_SECURE : 'tls'
            ];
            return $this->sendEmailSMTP($to, $subject, $message, $smtp);
        }
        
        // 기본 mail() 함수 사용
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $from = !empty($smtp['from']) ? $smtp['from'] : 'noreply@' . $host;
        $headers = "From: {$from}\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        return @mail($to, $subject, $message, $headers);
    }
    
    /**
     * SMTP로 이메일 발송
     */
    private function sendEmailSMTP(string $to, string $subject, string $message, array $smtp): bool {
        $host = $smtp['host'] ?? '';
        $port = (int)($smtp['port'] ?? 587);
        $user = $smtp['user'] ?? '';
        $pass = $smtp['pass'] ?? '';
        $from = $smtp['from'] ?? $user;
        $fromName = $smtp['from_name'] ?? '';
        $secure = $smtp['secure'] ?? 'tls';
        
        // 발신자 이름이 있으면 "이름 <이메일>" 형식
        $fromHeader = $fromName 
            ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>"
            : $from;
        
        try {
            $socket = @fsockopen(
                ($secure === 'ssl' ? 'ssl://' : '') . $host,
                $port,
                $errno,
                $errstr,
                10
            );
            
            if (!$socket) {
                return false;
            }
            // ★ (2026-10-29 09:58, 20차 점검) 단계마다 서버 응답 코드 확인 — 종전엔 응답을 안 봐서 인증 실패·수신 거부여도 늘 성공(true)으로 답해,
            //   16차의 '메일이 실제로 나갔을 때만 임시 비밀번호로 바꿈' 이 무력했음(재현: 가짜 SMTP 550·535 에도 성공). api.php sendSmtpEmail 과 같은 기준
            $ok = function (string $resp, array $codes) use ($socket): bool {
                foreach ($codes as $c) { if (strpos($resp, (string)$c) === 0) return true; }
                @fwrite($socket, "QUIT\r\n"); @fclose($socket);
                return false;
            };
            $ehloHost = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
            
            if (!$ok($this->smtpRead($socket), [220])) return false;
            
            // EHLO
            if (!$ok($this->smtpSend($socket, "EHLO " . $ehloHost), [250])) return false;
            
            // STARTTLS (if tls)
            if ($secure === 'tls') {
                if (!$ok($this->smtpSend($socket, "STARTTLS"), [220])) return false;
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { @fclose($socket); return false; }
                if (!$ok($this->smtpSend($socket, "EHLO " . $ehloHost), [250])) return false;
            }
            
            // AUTH LOGIN
            if ($user && $pass) {
                if (!$ok($this->smtpSend($socket, "AUTH LOGIN"), [334])) return false;
                if (!$ok($this->smtpSend($socket, base64_encode($user)), [334])) return false;
                if (!$ok($this->smtpSend($socket, base64_encode($pass)), [235])) return false;
            }
            
            // MAIL FROM
            if (!$ok($this->smtpSend($socket, "MAIL FROM:<{$from}>"), [250])) return false;
            
            // RCPT TO
            if (!$ok($this->smtpSend($socket, "RCPT TO:<{$to}>"), [250, 251])) return false;
            
            // DATA
            if (!$ok($this->smtpSend($socket, "DATA"), [354])) return false;
            
            // Message
            $data = "From: {$fromHeader}\r\n";
            $data .= "To: {$to}\r\n";
            $data .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $data .= "\r\n";
            $data .= $message . "\r\n";
            $data .= ".";
            
            if (!$ok($this->smtpSend($socket, $data), [250])) return false;
            
            // QUIT
            $this->smtpSend($socket, "QUIT");
            
            fclose($socket);
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function smtpSend($socket, string $data): string {
        fwrite($socket, $data . "\r\n");
        return $this->smtpRead($socket);
    }
    
    private function smtpRead($socket): string {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $response;
    }
    
    /**
     * 접속 통계 조회 (관리자 전용)
     */
    public function getAccessStats(): array {
        $user = $this->getUser();
        if (!$user || !$this->hasAdminPerm('logins')) {   // ★ (2026-10-29 22:39, 24차 점검) 로그인 기록 함수들(24차)·api.php requireAdminPerm('logins') 와 같은 기준
            return ['success' => false, 'error' => __('api_err_no_permission', '권한이 없습니다.')];
        }
        
        $logs = $this->db->load('login_logs');
        $users = $this->db->load('users');
        
        $now = new \DateTime();
        $today = $now->format('Y-m-d');
        $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
        $sevenDaysAgo = (clone $now)->modify('-7 days')->format('Y-m-d');
        $thirtyDaysAgo = (clone $now)->modify('-30 days')->format('Y-m-d');
        
        // 초기화
        $stats = [
            'total_users' => count($users),
            'summary' => [
                'today' => ['count' => 0, 'users' => []],
                'yesterday' => ['count' => 0, 'users' => []],
                'week' => ['count' => 0, 'users' => []],
                'month' => ['count' => 0, 'users' => []]
            ],
            'hourly' => [],
            'daily' => [],
            'monthly' => [],
            'yearly' => [],
            'by_user' => [],
            'by_country' => [],
            'by_browser' => [],
            'by_device' => [],
            'recent_logins' => []
        ];
        
        // 시간대별 초기화 (0~23시)
        for ($h = 0; $h < 24; $h++) {
            $stats['hourly'][$h] = 0;
        }
        
        // 일별 초기화 (최근 30일) - count와 users 분리
        $dailyUsers = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = (clone $now)->modify("-$i days")->format('Y-m-d');
            $stats['daily'][$date] = 0;
            $dailyUsers[$date] = [];
        }
        
        // 월별 초기화 (최근 12개월)
        $monthlyUsers = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = (clone $now)->modify("-$i months")->format('Y-m');
            $stats['monthly'][$month] = 0;
            $monthlyUsers[$month] = [];
        }
        
        // 년도별 초기화 (최근 5년)
        $yearlyUsers = [];
        $currentYear = (int)$now->format('Y');
        for ($y = $currentYear - 4; $y <= $currentYear; $y++) {
            $stats['yearly'][(string)$y] = 0;
            $yearlyUsers[(string)$y] = [];
        }
        
        foreach ($logs as $log) {
            $logDate = substr($log['created_at'] ?? '', 0, 10);
            $logMonth = substr($log['created_at'] ?? '', 0, 7);
            $logYear = substr($log['created_at'] ?? '', 0, 4);
            $logHour = (int)substr($log['created_at'] ?? '', 11, 2);
            $username = $log['username'] ?? 'unknown';
            
            // 요약 통계
            if ($logDate === $today) {
                $stats['summary']['today']['count']++;
                $stats['summary']['today']['users'][$username] = true;
                // 오늘 시간대별
                if (isset($stats['hourly'][$logHour])) {
                    $stats['hourly'][$logHour]++;
                }
            }
            if ($logDate === $yesterday) {
                $stats['summary']['yesterday']['count']++;
                $stats['summary']['yesterday']['users'][$username] = true;
            }
            if ($logDate >= $sevenDaysAgo) {
                $stats['summary']['week']['count']++;
                $stats['summary']['week']['users'][$username] = true;
            }
            if ($logDate >= $thirtyDaysAgo) {
                $stats['summary']['month']['count']++;
                $stats['summary']['month']['users'][$username] = true;
            }
            
            // 일별 통계
            if (isset($stats['daily'][$logDate])) {
                $stats['daily'][$logDate]++;
                $dailyUsers[$logDate][$username] = true;
            }
            
            // 월별 통계
            if (isset($stats['monthly'][$logMonth])) {
                $stats['monthly'][$logMonth]++;
                $monthlyUsers[$logMonth][$username] = true;
            }
            
            // 년도별 통계
            if (isset($stats['yearly'][$logYear])) {
                $stats['yearly'][$logYear]++;
                $yearlyUsers[$logYear][$username] = true;
            }
            
            // 사용자별 통계
            if (!isset($stats['by_user'][$username])) {
                $stats['by_user'][$username] = 0;
            }
            $stats['by_user'][$username]++;
            
            // 국가별 통계
            $country = $log['country'] ?? 'Unknown';
            if (!isset($stats['by_country'][$country])) {
                $stats['by_country'][$country] = 0;
            }
            $stats['by_country'][$country]++;
            
            // 브라우저별 통계
            $browser = $log['browser'] ?? 'Unknown';
            if (!isset($stats['by_browser'][$browser])) {
                $stats['by_browser'][$browser] = 0;
            }
            $stats['by_browser'][$browser]++;
            
            // 디바이스별 통계
            $device = $log['device_type'] ?? 'Unknown';
            if (!isset($stats['by_device'][$device])) {
                $stats['by_device'][$device] = 0;
            }
            $stats['by_device'][$device]++;
        }
        
        // 요약 유저 수 계산
        $stats['summary']['today']['user_count'] = count($stats['summary']['today']['users']);
        $stats['summary']['yesterday']['user_count'] = count($stats['summary']['yesterday']['users']);
        $stats['summary']['week']['user_count'] = count($stats['summary']['week']['users']);
        $stats['summary']['month']['user_count'] = count($stats['summary']['month']['users']);
        unset($stats['summary']['today']['users'], $stats['summary']['yesterday']['users'], 
              $stats['summary']['week']['users'], $stats['summary']['month']['users']);
        
        // 사용자별 정렬 (접속 많은 순)
        arsort($stats['by_user']);
        $stats['by_user'] = array_slice($stats['by_user'], 0, 20, true);
        
        // 국가별 정렬
        arsort($stats['by_country']);
        $stats['by_country'] = array_slice($stats['by_country'], 0, 10, true);
        
        // 브라우저별 정렬
        arsort($stats['by_browser']);
        
        // 디바이스별 정렬
        arsort($stats['by_device']);
        
        // 최근 로그인 10개
        usort($logs, fn($a, $b) => strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0'));
        $stats['recent_logins'] = array_slice($logs, 0, 10);
        
        // 배열을 테이블용으로 변환 (사용자 수 포함)
        $stats['hourly'] = array_map(fn($hour, $count) => ['hour' => $hour, 'count' => $count], 
            array_keys($stats['hourly']), array_values($stats['hourly']));
        
        // 일별 (최신순 정렬)
        $dailyArr = [];
        foreach (array_reverse(array_keys($stats['daily'])) as $date) {
            $dailyArr[] = [
                'date' => $date,
                'count' => $stats['daily'][$date],
                'users' => count($dailyUsers[$date] ?? [])
            ];
        }
        $stats['daily'] = $dailyArr;
        
        // 월별 (최신순 정렬)
        $monthlyArr = [];
        foreach (array_reverse(array_keys($stats['monthly'])) as $month) {
            $monthlyArr[] = [
                'month' => $month,
                'count' => $stats['monthly'][$month],
                'users' => count($monthlyUsers[$month] ?? [])
            ];
        }
        $stats['monthly'] = $monthlyArr;
        
        // 년도별 (최신순 정렬)
        $yearlyArr = [];
        foreach (array_reverse(array_keys($stats['yearly'])) as $year) {
            $yearlyArr[] = [
                'year' => $year,
                'count' => $stats['yearly'][$year],
                'users' => count($yearlyUsers[$year] ?? [])
            ];
        }
        $stats['yearly'] = $yearlyArr;
        
        $stats['by_user'] = array_map(fn($user, $count) => ['user' => $user, 'count' => $count], 
            array_keys($stats['by_user']), array_values($stats['by_user']));
        $stats['by_country'] = array_map(fn($country, $count) => ['country' => $country, 'count' => $count], 
            array_keys($stats['by_country']), array_values($stats['by_country']));
        $stats['by_browser'] = array_map(fn($browser, $count) => ['browser' => $browser, 'count' => $count], 
            array_keys($stats['by_browser']), array_values($stats['by_browser']));
        $stats['by_device'] = array_map(fn($device, $count) => ['device' => $device, 'count' => $count], 
            array_keys($stats['by_device']), array_values($stats['by_device']));
        
        // 오늘/어제/이번달 날짜 정보도 전달
        $stats['today'] = $today;
        $stats['yesterday'] = $yesterday;
        $stats['this_month'] = $now->format('Y-m');
        
        return ['success' => true, 'stats' => $stats];
    }
    
}
