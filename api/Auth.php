<?php
/**
 * Auth - 사용자 인증 관리 (JSON 기반) + 보안 기능
 */
class Auth {
    private $db;
    private static $user = null;
    // ★ (2026-11-02 09:01, 41차 점검) 없는 아이디의 응답 시간을 맞추는 더미 해시(아무 비밀번호와도 안 맞음). PHP 8.3 기본(bcrypt cost 10)과 같은 비용
    const DUMMY_PW_HASH = '$2y$10$2jndCaAJfaAMc56s4JyAwe/ib69dxq4X6Q6nj2iMYiL6ZuJgAHC8y';
    const PW_RESET_PENDING_SEC = 86400;   // ★ (2026-11-15 01:31, 153차) 비밀번호 찾기 임시 비밀번호가 '대기'로 유효한 시간(24시간) — requestPasswordReset·login

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
            // ★ (2026-11-12 19:40, 103차 개선 B3) 입력 그대로 없는 아이디면 앞뒤 공백을 뺀 값으로 한 번 더 찾고, 찾으면 아래 잠금·조회·기록을 그 계정 아이디로 — 휴대폰 자동 완성·붙여 넣기의 ' admin' 이 로그인 안 됐음(재현).
            //   입력 그대로가 먼저라 22차 전에 공백째 저장된 'viewer ' 계정은 종전처럼 'viewer ' 로 들어가고, 둘 다 없으면 종전처럼 입력값 그대로(잠금 키·응답 같음)
            if (!$lockUser && ($trimName = trim($username)) !== $username && $trimName !== '' && ($trimUser = $this->db->find('users', ['username' => $trimName]))) { $lockUser = $trimUser; $username = (string)$trimUser['username']; }
            $lockName = $lockUser ? (string)$lockUser['username'] : $username;
            // 브루트포스 체크
            // ★ (2026-11-01 07:21, 37차 기능 점검) 잠금 확인과 시도 기록을 한 잠금 안에서(시도를 먼저 1회 기록, 비밀번호가 맞으면 되돌림 — 2FA 와 같은 예약 방식).
            //   종전엔 잠금 확인(잠금 없이 읽기)과 실패 기록이 따로라, 동시에 보낸 묶음은 모두 '아직 5회 미만'으로 보고 비밀번호 확인까지 갔음(재현: 동시 40회 → 40회 모두 확인)
            $lockUndo = null;
            if (!$this->reserveLoginAttempt($lockName, $ip, $lockUndo)) {
                $this->logLogin($username, false, $ip, __('api_log_account_locked', '계정 잠금'));
                return ['success' => false, 'error' => __('api_err_login_locked', '로그인 시도 횟수 초과. 잠시 후 다시 시도하세요.')];
            }

            // 먼저 사용자 조회 (is_active 체크 없이)
            $user = $this->db->find('users', ['username' => $username]);

            // ★ (2026-11-02 09:01, 41차 점검) 없는 아이디(또는 비밀번호 해시가 없는 SSO 전용 계정)도 더미 해시로 password_verify 를 한 번 돌림 — 종전엔 앞에서 끝나
            //   bcrypt 를 건너뛰어 응답이 약 60ms 빨라, 문구가 같아도 시간으로 아이디가 있는지 알 수 있었음(D3#5). 결과는 늘 실패(종전과 같음)
            $pwHash = $user ? (string)($user['password'] ?? '') : '';
            if ($user && $pwHash !== '' && $pwHash[0] !== '!') {   // ★ (2026-11-02 09:45, 41차 재검토) '비밀번호 없음' = 빈 값 또는 '!' 로 시작(SSO 등). password_get_info 는 $2a$·$2b$·$6$ 등 옮겨 온 해시를 algo NULL 로 봐 그런 계정의 로그인이 늘 실패했음(검토 재현)
                $pwOk = password_verify($password, $pwHash);
            } else {
                password_verify($password, self::DUMMY_PW_HASH);
                $pwOk = false;
            }
            // ★ (2026-11-15 01:31, 153차) 비밀번호 찾기로 받은 '대기' 임시 비밀번호(24시간)로 들어오면 그때 진짜 비밀번호로 바꾸고 다른 세션·로그인 유지를 끊음
            //   (종전엔 요청 순간 바꿨음 — requestPasswordReset 주석). 로컬 비밀번호가 있는 계정만(위 '비밀번호 없음' 판정과 같음). 기존 비밀번호로 들어오면 대기 값은 지움
            if ($user && $pwHash !== '' && $pwHash[0] !== '!') {
                if (!$pwOk) {
                    $prRow = $this->pendingResetGet((int)$user['id']);
                    if ($prRow && password_verify($password, (string)$prRow['hash'])) {
                        $this->promotePendingReset((int)$user['id'], (string)$prRow['hash']);
                        $user = $this->db->find('users', ['id' => $user['id']]) ?: $user;
                        $pwHash = (string)$prRow['hash'];   // 아래 2FA 지문(pw_fp)·64차 '그사이 비밀번호 바뀜' 확인이 지금 비밀번호(=임시)를 기준으로 보게
                        $pwOk = true;
                    }
                } else {
                    $this->clearPendingReset((int)$user['id']);
                }
            }
            // ★ (2026-11-01 07:21, 37차 기능 점검) 비밀번호가 맞으면 미리 기록한 1회를 되돌림(정지·승인 대기·2FA 대기 등에서 종전처럼 실패 횟수가 늘지 않게). 틀리면 그 1회가 실패 기록
            if ($pwOk) $this->releaseLoginAttempt($lockName, $ip, $lockUndo);
            if (!$pwOk) {
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
                    } elseif (!fsUserSuspendedNow($user)) {
                        // ★ (2026-11-02 11:50, 43차 점검) 시작일 전(예약된 정지)이면 아직 활성으로 진행(상태는 그대로 — 시작일부터 막힘). 종전엔 종료일만 봐 저장 즉시 막혔음(F5#3)
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
                    'started_at' => time(),   // ★ (2026-10-28 18:22) 대기 만료(10분) 기준
                    'pw_fp' => self::pwFingerprint($pwHash)   // ★ (2026-11-10 14:36, 64차) 1단계에 쓴 비밀번호 해시의 짧은 지문 — verify2FA 가 그사이 비밀번호가 바뀌었는지 봄(해시 원문은 넣지 않음)
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
            $this->markSessionInstance();   // ★ (2026-11-02 10:29, 42차 점검) 이 설치의 로그인 표시(E5#3, isLoggedIn 이 확인)
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
            
            // ★ (2026-11-10 14:36, 64차) 토큰·세션을 기록한 뒤 비밀번호 해시를 다시 읽어, 확인에 쓴 해시와 다르면(그사이 비밀번호 변경·관리자 변경·비밀번호 찾기) 방금 만든 세션 행·토큰·로그인을 되돌리고 '틀린 비밀번호'와 같게 끝냄 — 종전엔 옛 비밀번호로 진행 중이던 로그인이 변경 쪽의 세션 끊기·토큰 삭제 뒤에 기록돼 그대로 남았음(재현). 다시 읽기 뒤에 바뀌면 변경 쪽이 이미 기록된 이 세션·토큰을 끊음
            $this->db->clearCache('users');
            $reUser = $this->db->find('users', ['id' => $user['id']]);
            if (!$reUser || !hash_equals($pwHash, (string)($reUser['password'] ?? ''))) {
                $reSid = (string)session_id();
                $this->removeSession((int)$user['id'], $reSid);
                if ($reSid !== '') $this->deleteRememberTokensForSessions([$reSid]);
                if ($remember && defined('REMEMBER_ME_ENABLED') && REMEMBER_ME_ENABLED) setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
                unset($_SESSION['user_id'], $_SESSION['fs_instance'], $_SESSION['username'], $_SESSION['role'], $_SESSION['last_activity'], $_SESSION['remember_me'], $_SESSION['_fs_sess_rec']);
                $this->recordFailedAttempt($lockName, $ip);
                $this->logLogin($user['username'], false, $ip, __('api_log_invalid_creds', '잘못된 인증정보'));
                return ['success' => false, 'error' => __('api_err_invalid_credentials', '아이디 또는 비밀번호가 올바르지 않습니다.')];
            }

            // 로그인 로그 (DB에 저장된 정확한 username 사용)
            $this->logLogin($user['username'], true, $ip, __('login_success'));
            
            // 사용자 폴더 자동 생성
            if (defined('AUTO_CREATE_USER_FOLDER') && AUTO_CREATE_USER_FOLDER) {
                $this->ensureUserFolder($user);
            }
            
            // ★ (2026-11-01 07:21, 37차 기능 점검) 응답에서 2FA 비밀키·백업 코드·앱 비밀번호 해시도 뺌(sanitizeUser) — 종전엔 password 만 빼고 내려보냈음. 화면은 이 칸들을 쓰지 않음(app_password_list 가 따로 있음)
            return ['success' => true, 'user' => $this->sanitizeUser($user)];
            
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
            // ★ (2026-11-02 10:32, 42차 점검) 만들기 실패(users/ 쓰기 권한 없음)를 기록 없이 넘겨 '내 파일'이 '잘못된 경로'로만 보였음(E5#7) → 서버 오류 로그에 원인을 남김(동작은 그대로, 화면 문구는 FileManager 몫)
            if (!@mkdir($realPath, 0755, true) && !is_dir($realPath)) {
                error_log('[FileStation] cannot create user folder: ' . $realPath . ' — check write permission for the web server user');
            }
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
        
        // ★ (2026-11-01 17:18, 40차 점검) 쿠키가 배열(remember_token[]=1)이면 string 매개변수에서 TypeError → 로그아웃이 세션 정리 전에 500 — 문자열일 때만 사용
        if ($userId && is_string($_COOKIE['remember_token'] ?? null)) {
            $this->deleteRememberToken($_COOKIE['remember_token']);
            setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        
        if ($userId) {
            $this->removeSession($userId, session_id());
            $this->revokeSessionIds([(string)session_id()]);   // ★ (2026-11-12 17:35, 101차 SES-1) 로그아웃한 세션 ID 도 끊긴 목록에 넣음(세션 종료·다른 세션 모두 종료와 같은 장치) — 종전엔 세션만 지워, Redis 잠금 꺼짐에서 같은 순간 처리 중이던 같은 브라우저 요청이 세션을 다시 써 로그아웃 뒤에도 로그인된 채였음(재현 9/15). 새 로그인은 새 세션 ID 라 겹치지 않음
        }
        
        session_destroy();
        self::$user = null;
    }
    
    private static $sessionTimeoutChecked = false;
    
    /**
     * ★ (2026-11-02 10:29, 42차 점검) 세션이 이 설치에서 연 로그인인지(E5#3) — 세션 쿠키(PHPSESSID, path=/)와 저장소를 같은 호스트의 다른 FileStation·PHP 앱과 함께 써서,
     *   그쪽이 user_id 를 넣은 세션이 여기서 그 번호 계정으로 통했음(재현). 로그인·2FA 통과·SSO 관문·로그인 유지 복구에서 FS_INSTANCE_ID 를 넣고(markSessionInstance) 여기서 비교.
     *   다르면 '로그인 안 됨'으로만 봄(다른 앱의 세션 데이터는 지우지 않음). 42차 전에 열린 세션은 표시가 없어 한 번 다시 로그인(로그인 유지 쿠키가 있으면 자동 복구)
     */
    private function sessionInstanceOk(): bool {
        if (!defined('FS_INSTANCE_ID')) return true;
        // ★ (2026-11-02 11:00, 42차 재검토) 표시를 사용자 번호와 묶음('설치값:번호') — 설치값만 보면 FileStation 에 한 번 로그인해 표시가 남은 세션에 다른 앱이 user_id 만 덮어써도
        //   그 번호로 통했음(검토 발견). 표시를 넣는 곳은 모두 user_id 를 넣은 직후(ssoGate 는 부르는 쪽이 직후 다시 넣음)
        return ($_SESSION['fs_instance'] ?? null) === FS_INSTANCE_ID . ':' . (int)($_SESSION['user_id'] ?? 0);
    }
    private function markSessionInstance(): void {
        if (defined('FS_INSTANCE_ID')) $_SESSION['fs_instance'] = FS_INSTANCE_ID . ':' . (int)($_SESSION['user_id'] ?? 0);
    }

    public function isLoggedIn(): bool {
        if (!isset($_SESSION['user_id'])) return false;
        if (!$this->sessionInstanceOk()) return false;   // ★ (2026-11-02 10:29, 42차 점검) 다른 설치·앱이 넣은 user_id 는 로그인으로 보지 않음(위 설명)

        // 세션 타임아웃 체크 (요청당 1회만)
        if (!self::$sessionTimeoutChecked) {
            self::$sessionTimeoutChecked = true;

            // ★ (2026-10-29 03:11, 16차) 세션 강제 종료·계정 정지/비활성을 즉시 반영 — 종전엔 로그인 뒤 $_SESSION['user_id'] 만 봐서,
            //   '세션 종료' 를 눌러도(기록만 지워져 recordSession 이 다시 넣음) 끊기지 않고, 정지·비활성 처리한 계정의 기존 세션도 계속 살아 있었음(재현)
            // ★ (2026-11-07 10:40, 로그인 유지 풀림 수정) 끊긴 이유를 나눔 — 'revoked'(세션 종료·동시 세션 초과·비밀번호 변경 등 일부러 끊음)와
            //   'superseded'(같은 브라우저가 로그인 유지로 새 세션을 열어 대체된 옛 세션). 대체된 세션은 세션만 닫고 토큰·쿠키는 남김(토큰은 이미 새 세션 것) — 아래 checkRememberToken 참고
            $_revKind = $this->sessionRevokeKind(session_id());
            $_usable = $this->isAccountUsable((int)$_SESSION['user_id']);
            if ($_revKind !== null || !$_usable) {
                $uid0 = $_SESSION['user_id'] ?? null;
                if ($uid0) $this->removeSession((int)$uid0, session_id());
                // ★ (2026-10-29 09:57, 20차 점검) 끊긴 기기의 '로그인 유지' 토큰도 폐기 — 종전엔 세션만 끊겨 같은 요청에서 토큰으로 바로 다시 로그인됐음(재현).
                //   토큰은 세션과 연결돼 있지 않아, 이 요청이 들고 온 쿠키(=끊긴 그 기기의 것)만 지움. 다른 기기의 로그인 유지는 그대로
                // ★ (2026-11-07 10:40) 대체된(superseded) 세션이고 계정이 쓸 수 있는 상태면 지우지 않음 — 이 요청 뒤 checkRememberToken 이 같은 토큰으로 새 세션을 엶
                if ($_usable && $_revKind === 'superseded') {
                    // 토큰 유지
                } elseif (is_string($_COOKIE['remember_token'] ?? null)) {   // ★ (2026-11-01 17:18, 40차 점검) 배열 쿠키면 (string) 변환 경고가 api.php 에서 예외(500)가 됐음 — 문자열일 때만
                    $this->deleteRememberToken((string)$_COOKIE['remember_token']);
                    setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
                    unset($_COOKIE['remember_token']);
                }
                session_unset();
                session_destroy();
                if (session_status() === PHP_SESSION_NONE) session_start();
                self::$user = null;
                // ★ (2026-11-07 11:05, 재검토) 대체된 세션이면 같은 요청에서 바로 토큰으로 다시 엶 — 뷰어(office·hwp·rhwp·onlyoffice)처럼 토큰 복구를 부르지 않는
                //   진입점이 첫 요청이면 한 번 401 이 나던 것(같은 브라우저 동시 복구 경합 뒤, 시험 재현). 토큰 쿠키가 없거나(옛 세션 ID 만 가진 쪽) 무효·IP 제한이면 종전처럼 false
                if ($_usable && $_revKind === 'superseded' && defined('REMEMBER_ME_ENABLED') && REMEMBER_ME_ENABLED) {
                    $_reOk = $this->checkRememberToken();
                    // ★ (2026-11-09 10:23, 재검토 A-2) 새로 연 세션을 바로 세션 목록에 기록 — 종전엔 이 요청에서 기록을 건너뛰어(위 확인 표시가 이미 켜짐) 다음 요청 전까지 목록에 없어 '다른 세션 모두 종료'·비밀번호 변경으로 끊기지 않았음(재현)
                    if ($_reOk && isset($_SESSION['user_id']) && session_id() !== '') {
                        $_rsIp2 = $this->getClientIP();
                        if ($this->recordSession((int)$_SESSION['user_id'], $_rsIp2)) $_SESSION['_fs_sess_rec'] = ['sid' => session_id(), 'uid' => (int)$_SESSION['user_id'], 'ip' => $_rsIp2, 't' => time()];
                    }
                    return $_reOk;
                }
                self::$sessionTimeoutChecked = false;   // ★ (2026-11-09 14:01, 58차 G1-2) 확인 표시를 되돌림 — 이 뒤 api.php·requireLogin·index.php 가 토큰으로 연 새 세션이 같은 요청의 다음 isLoggedIn 에서 세션 목록에 기록되게(종전엔 건너뛰어 '다른 세션 모두 종료'·비밀번호 변경 뒤에도 남았음, 재현). 세션은 이미 비워져 다음 isLoggedIn 은 user_id 가 다시 생겼을 때만 검사함
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
                        self::$sessionTimeoutChecked = false;   // ★ (2026-11-09 14:01, 58차 G1-2) 위와 같음 — 유휴 만료 뒤 같은 요청에서 토큰으로 연 세션도 기록되게(재현)
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
            // ★ (2026-11-02 15:02, 44차 점검) 같은 세션·같은 사용자·같은 IP 면 sessions 기록 갱신은 60초에 한 번 — 종전엔 로그인한 모든 요청(썸네일 수백 장 포함)이 sessions.json 전체를
            //   배타 잠금 안에서 다시 써 서버 전체가 한 줄로 섰음(G3#2, 5000행 1건 33ms·동시 약 28요청/초가 상한). 강제 종료·정지 확인은 위 isSessionRevoked·isAccountUsable 이
            //   매 요청 그대로 하고(sessions 표와 무관), 유휴 만료는 $_SESSION['last_activity'](바로 위, 매 요청 갱신)로 판정. 세션 ID 가 바뀌거나(재발급) IP 가 바뀌면 바로 기록.
            //   차이: 세션 목록·시스템 정보의 '마지막 활동'(DB 값)이 최대 59초 늦게 보일 수 있음. 세션을 닫은 요청(session_write_close 뒤)이면 표시가 남지 않아 종전처럼 매번 기록
            $_rsIp = $this->getClientIP();
            $_rsNow = time();
            $_rsMark = $_SESSION['_fs_sess_rec'] ?? null;
            $_rsSkip = is_array($_rsMark) && ($_rsMark['sid'] ?? null) === session_id() && (int)($_rsMark['uid'] ?? 0) === (int)$_SESSION['user_id']
                && ($_rsMark['ip'] ?? null) === $_rsIp && is_int($_rsMark['t'] ?? null) && $_rsNow >= $_rsMark['t'] && $_rsNow - $_rsMark['t'] < 60;
            if (!$_rsSkip) {
                if ($this->recordSession($_SESSION['user_id'], $_rsIp)) {   // ★ (2026-10-29 18:45, 22차 점검) 신뢰 프록시 규칙(getClientIP)으로 — 종전엔 요청마다 프록시 주소로 덮어썼음(로그인 때와 다름)
                    $_SESSION['_fs_sess_rec'] = ['sid' => session_id(), 'uid' => (int)$_SESSION['user_id'], 'ip' => $_rsIp, 't' => $_rsNow];
                }
            }
        }
        
        return isset($_SESSION['user_id']);
    }
    
    public function getUser(): ?array {
        if (!$this->isLoggedIn()) return null;
        
        if (self::$user === null) {
            $user = $this->db->find('users', ['id' => $_SESSION['user_id']]);
            if ($user) {
                // ★ (2026-11-01 07:21, 37차 기능 점검) 앱 비밀번호 해시 목록도 뺌(me 응답으로 내려갔음, 관리자용 getUsers 와 같은 기준). 이 값을 읽는 곳은 users 를 직접 읽음
                unset($user['password'], $user['2fa_secret'], $user['2fa_backup_codes'], $user['app_passwords']);
                self::$user = $user;
            }
        }
        return self::$user;
    }
    
    public function getUserId(): ?int {
        if (isset($_SESSION['user_id']) && !$this->sessionInstanceOk()) return null;   // ★ (2026-11-02 10:29, 42차 점검) isLoggedIn 과 같은 기준(다른 설치·앱의 user_id 는 이 설치의 사용자 번호로 쓰지 않음)
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

    /** ★ (2026-11-02 16:17, 가입 금지 아이디 — 펜닐 요청 "특정일부 아이디는 가입 예외 처리") 관리자가 정한 '가입 금지 아이디' 목록에 걸리는지 — 대소문자 무시, '*' 는 아무 글자(0개 이상).
     *  회원가입(스스로 가입)에만 씀 — 관리자가 직접 만드는 계정·SSO 자동 생성은 막지 않음(그 아이디를 따로 남겨 두는 용도) */
    /** ★ (2026-11-03 10:22, 금지 아이디·닉네임 관리 — 펜닐 요청 "회원관리쪽에 특정 아이디 생성제한 관리 … 이미지1-2 같은게 필요") 금지 닉네임(표시 이름) — 회원가입·본인 표시 이름 변경에만 씀(관리자는 막지 않음). 대소문자 무시, 앞뒤 공백 무시, '*' 는 아무 글자 */
    public static function nicknameBlocked(JsonDB $db, string $name): bool {
        // ★ (2026-11-03 12:16, 50차 점검) 보이지 않는 글자(폭 없는 공백 U+200B 등)는 어디에 있든 빼고, 앞뒤 공백은 전각 공백(U+3000)까지 지운 뒤 비교 —
        //   종전 trim() 은 ASCII 공백만 지워 '운영자'+전각 공백·U+200B+'운영자' 로 금지 닉네임을 비켜 갈 수 있었음(재현)
        //   ★ (2026-11-03 12:33, 50차 재검토) 서식 문자 전체(\p{Cf} — 방향 제어 U+202A~202E·U+2066~2069 포함)와 한글 채움 문자(U+115F·U+1160·U+3164·U+FFA0)도 뺌,
        //   깨진 UTF-8 이라 정규식이 실패하면 종전처럼 trim() 값으로 비교(종전엔 빈 값이 돼 와일드카드 금지를 통과했음 — 검토 재현)
        $norm = function ($v) {
            $v = (string)$v;
            $r = preg_replace('/[\p{Cf}\x{115F}\x{1160}\x{3164}\x{FFA0}]/u', '', $v);
            if ($r === null) return trim($v);
            $r = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $r);
            return $r === null ? trim($v) : $r;
        };
        // ★ (2026-11-04, 55차 G1) 전각 영문·숫자(ｅ５５ｂｏｓｓ)로 금지 닉네임을 비켜 가던 것 → intl(Normalizer) 있을 때만 NFKC 로 한 번 더 비교(없거나 실패하면 종전 값 그대로), 저장 이름은 안 바꿈
        $kc = function ($v) {
            if (!class_exists('Normalizer')) return $v;
            $r = \Normalizer::normalize($v, \Normalizer::FORM_KC);
            return ($r === false || $r === null) ? $v : mb_strtolower($r, 'UTF-8');
        };
        $n = mb_strtolower($norm($name), 'UTF-8');
        if ($n === '') return false;
        $nk = $kc($n);
        $list = ($db->load('settings') ?: [])['signup_blocked_nicknames'] ?? [];
        if (!is_array($list)) return false;
        foreach ($list as $p) {
            if (!is_scalar($p)) continue;
            $p = mb_strtolower($norm($p), 'UTF-8');
            if ($p === '') continue;
            if (strpos($p, '*') === false) { if ($p === $n || $kc($p) === $nk) return true; continue; }
            if (preg_match('/^' . str_replace('\\*', '.*', preg_quote($p, '/')) . '$/u', $n)) return true;
            if (preg_match('/^' . implode('.*', array_map(function ($s) use ($kc) { return preg_quote($kc($s), '/'); }, explode('*', $p))) . '$/u', $nk)) return true;
        }
        return false;
    }

    public static function signupBlocked(JsonDB $db, string $username): bool {
        $u = strtolower(trim($username));
        if ($u === '') return false;
        $list = ($db->load('settings') ?: [])['signup_blocked_usernames'] ?? [];
        if (!is_array($list)) return false;
        foreach ($list as $p) {
            if (!is_scalar($p)) continue;   // ★ (2026-11-02 16:42, 45차 재확인) 손으로 고친 설정 백업을 복원해 목록에 배열·객체가 들어가면 (string) 경고가 예외(api.php 오류 처리기)가 돼 회원가입 전체가 오류였음
            $p = strtolower(trim((string)$p));
            if ($p === '') continue;
            if (strpos($p, '*') === false) { if ($p === $u) return true; continue; }
            $re = '/^' . str_replace('\*', '.*', preg_quote($p, '/')) . '$/';
            if (preg_match($re, $u)) return true;
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
            // ★ (2026-11-01 09:43, 38차 점검) Windows 는 경로 끝의 점·공백을 지워 'alice.' 홈 폴더가 'alice' 와 같은 폴더가 됨 — 끝 점·공백을 뺀 값이 같아도 이미 있음으로
            if (rtrim($xu, '. ') === rtrim($u, '. ')) return 'exists';
            // ★ (2026-10-29 21:59, 24차 점검) 숫자 아이디는 DB 찾기(JsonDB::matchValue)가 숫자로 비교해 '020231234'·'20231234' 를 같은 계정으로 봄 — 같은 기준으로 중복 처리
            //   (종전엔 따로 만들어져, 나중 계정은 로그인하면 앞 계정을 찾아 비밀번호가 늘 틀렸다고 나왔음)
            if (is_numeric($xu) && is_numeric($u) && (int)$xu === (int)$u) return 'exists';
        }
        // ★ (2026-11-01 09:43, 38차 점검) 끝에 점·공백이 붙은 새 아이디는 거부(위 Windows 경로 규칙, SSO 아이디는 '.' 를 남김) — 이 검사는 새로 만들 때만 쓰여 기존 'alice.' 계정의 로그인은 그대로
        if (rtrim($u, '. ') !== $u) return 'reserved';
        // ★ (2026-11-01 17:21, 40차 점검) Windows 장치 이름(con·prn·aux·nul·com1-9·lpt1-9, 뒤에 '.확장자' 포함)은 홈 폴더(USER_FILES_ROOT\con)를 만들 수 없어 새 아이디로 거부 —
        //   39차 식(FileManager copyRemoteDirToLocal)과 같음. Windows 에서만, 새로 만들 때만 쓰여 기존 계정·Linux 는 그대로
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $u)) return 'reserved';
        return null;
    }

    /** ★ (2026-11-01 17:18, 40차 점검) 저장해도 되는 역할 값인지 — 기본 3개(api.php 'roles' 의 default_roles)와 roles 표의 value(엄격 비교, 문자열만) */
    private function isValidRole($role): bool {
        if (!is_string($role) || $role === '') return false;
        if (in_array($role, ['admin', 'sub_admin', 'user'], true)) return true;
        foreach (($this->db->load('roles') ?: []) as $r) {
            if (is_array($r) && (string)($r['value'] ?? '') === $role) return true;
        }
        return false;
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
            return ['success' => false, 'error' => __('api_err_password_length', '비밀번호는 8자 이상, 72바이트 이하여야 합니다(한글은 1자가 3바이트).')];   // ★ (2026-11-12 10:56, 97차 개선 B2) 문구만 실제 기준(바이트, bcrypt 72바이트)으로 — 종전 '8~72자' 는 한글 30자(90바이트) 거부 때 안내와 맞지 않았음(재현). 받는 기준은 그대로
        }
        if (self::emailInUse($this->db, (string)($data['email'] ?? ''))) {   // ★ (2026-10-30 01:42, 26차 — 24차 결정 필요 '메일 중복' 자동 선택: 거부)
            return ['success' => false, 'error' => __('err_email_in_use', '다른 계정이 이미 쓰는 메일입니다.')];
        }
        
        $role = $data['role'] ?? 'user';
        // ★ (2026-11-01 17:18, 40차 점검) 역할은 정해진 값(admin·sub_admin·user + 역할 관리에서 만든 custom 역할)만 — 종전엔 배열·true·아무 글자나 저장돼,
        //   느슨한 비교(in_array(role, ['admin','sub_admin']))를 쓰는 곳에서 role=true 가 관리자급으로 통했음
        if (!$this->isValidRole($role)) {
            return ['success' => false, 'error' => __('api_err_role_not_found', '역할을 찾을 수 없습니다.')];
        }
        // 관리자는 무조건 활성 상태
        $status = ($role === 'admin') ? 'active' : ($data['status'] ?? 'active');
        
        $userData = [
            'username' => $data['username'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            // ★ (2026-11-17 08:54, 303차 — 296·297차 펜닐 결정 "방향 뒤집기 문자 빼기"를 표시 이름에도) 가입·관리자 만들기/수정에서 글자 방향 조작 문자 지움
            'display_name' => (trim(fsStripBidi((string)($data['display_name'] ?? ''))) !== '') ? fsStripBidi((string)$data['display_name']) : $data['username'],   // ★ (2026-11-12 01:27, 90차 USR-3) 비우면 아이디로(회원가입과 같은 규칙) — 종전엔 '' 로 저장돼 게시글 작성자·활동 기록 이름이 빈칸(재현)
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
        
        // ★ (2026-11-04, 56차 C1-1) 추가를 잠금(atomicUpdate) 안에서 하며 같은 아이디를 다시 확인(위 usernameProblem 의 'exists' 규칙 그대로, 40차 회원가입과 같은 방식) — 종전엔 확인→insert 사이가 비어 두 번 클릭(운영 Redis 세션은 잠금 없음)으로 같은 아이디 계정이 여러 개 생겼음(재현). 번호는 insert 와 같은 reserveId
        $id = 0; $cuExists = false;
        $this->db->atomicUpdate('users', function ($rows) use ($userData, &$id, &$cuExists) {
            $rows = is_array($rows) ? $rows : [];
            $u = strtolower(trim((string)$userData['username']));
            foreach ($rows as $x) {
                $xu = strtolower(trim((string)($x['username'] ?? '')));
                if ($xu === $u || rtrim($xu, '. ') === rtrim($u, '. ') || (is_numeric($xu) && is_numeric($u) && (int)$xu === (int)$u)) { $cuExists = true; return $rows; }
            }
            $id = $this->db->reserveId('users', empty($rows) ? 0 : (int)max(array_column($rows, 'id')));
            $userData['id'] = $id;
            $rows[] = $userData;
            return $rows;
        });
        if ($cuExists) {
            return ['success' => false, 'error' => __('username_already_exists')];
        }

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
        // ★ (2026-11-14 18:36, 128차 펜닐 결정 '서버에서도 막기') 마지막 활성 관리자를 비활성(is_active 0)으로 바꾸는 요청은 거부 — 화면은 보내지 않지만
        //   API 로 직접 보내면 하나뿐인 관리자도 비활성이 되어 아무도 관리자로 로그인할 수 없었음(재현). 다른 활성 관리자가 있으면 종전과 같음
        if (($targetUser['role'] ?? '') === 'admin' && array_key_exists('is_active', $data) && !$data['is_active']) {
            $laOther = 0;
            foreach (($this->db->load('users') ?: []) as $laU) {
                if ((int)($laU['id'] ?? 0) === (int)$id || ($laU['role'] ?? '') !== 'admin') continue;
                if (!($laU['is_active'] ?? 1) || (($laU['status'] ?? 'active') !== 'active')) continue;
                $laOther++;
            }
            if ($laOther === 0) return ['success' => false, 'error' => __('api_err_last_admin_inactive', '마지막 관리자 계정은 비활성으로 바꿀 수 없습니다.')];
        }
        
        $updateData = [];
        if (isset($data['display_name'])) {
            if (mb_strlen($data['display_name']) > 30) {
                return ['success' => false, 'error' => __('err_display_name_too_long', '표시 이름은 30자 이내로 입력하세요.')];
            }
            // ★ (2026-11-17 08:54, 303차 — 296·297차 펜닐 결정 "방향 뒤집기 문자 빼기"를 표시 이름에도) 가입·관리자 만들기/수정에서 글자 방향 조작 문자 지움
            $updateData['display_name'] = (trim(fsStripBidi((string)$data['display_name'])) !== '') ? fsStripBidi((string)$data['display_name']) : (string)($targetUser['username'] ?? '');   // ★ (2026-11-12 01:27, 90차 USR-3) 관리 화면에서 비워 저장하면 아이디로(위 만들기와 같음)
        }
        if (isset($data['email'])) {
            // 메일을 바꿀 때만(이미 겹쳐 있던 옛 자료여도 다른 항목 수정은 막지 않게)
            if (strcasecmp(trim((string)($targetUser['email'] ?? '')), trim((string)$data['email'])) !== 0 && self::emailInUse($this->db, (string)$data['email'], $id)) {   // ★ (2026-10-30 01:42, 26차 — 24차 결정 필요 '메일 중복' 자동 선택: 거부)
                return ['success' => false, 'error' => __('err_email_in_use', '다른 계정이 이미 쓰는 메일입니다.')];
            }
            $updateData['email'] = $data['email'];
        }
        if (isset($data['role'])) {
            // ★ (2026-11-01 17:18, 40차 점검) 위 createUser 와 같은 역할 값 검사 — 지금 저장된 역할과 같은 값(옛 자료·SSO 기본 역할 등)은 그대로 통과시켜 다른 항목 수정이 막히지 않게
            if ($data['role'] !== ($targetUser['role'] ?? null) && !$this->isValidRole($data['role'])) {
                return ['success' => false, 'error' => __('api_err_role_not_found', '역할을 찾을 수 없습니다.')];
            }
            $updateData['role'] = $data['role'];
        }
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
                return ['success' => false, 'error' => __('api_err_password_length', '비밀번호는 8자 이상, 72바이트 이하여야 합니다(한글은 1자가 3바이트).')];   // ★ (2026-11-12 10:56, 97차 개선 B2) 문구만 실제 기준(바이트, bcrypt 72바이트)으로 — 종전 '8~72자' 는 한글 30자(90바이트) 거부 때 안내와 맞지 않았음(재현). 받는 기준은 그대로
            }
            $updateData['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            $this->clearPendingReset((int)$id);   // ★ (2026-11-15 01:31, 153차) 관리자가 비밀번호를 정하면 비밀번호 찾기 대기 임시 비밀번호는 지움
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
            || (isset($updateData['status']) && $updateData['status'] !== 'active'
                // ★ (2026-11-02 11:50, 43차 점검) 시작일이 아직 안 된 정지(예약)는 토큰을 남김 — 시작일부터는 checkRememberToken·isAccountUsable 이 막음(F5#3)
                && !($updateData['status'] === 'suspended' && !fsUserSuspendedNow(array_merge($targetUser, $updateData))))) {
            // ★ (2026-11-09 14:01, 58차 G1-1) 관리자가 자기 비밀번호만 바꾸면(비활성·정지 변경 없음) 이 기기(쿠키) 토큰은 남김 — 16차 '모두 폐기'를 '이 기기만 남기고 폐기'로 바꿈('다른 세션 모두 종료' 37차와 같은 방식).
            //   종전엔 이 기기 토큰까지 지워, 세션 쿠키를 잃으면(iPhone 탭 정리·세션 만료) 다시 로그인해야 했음(재현). 다른 기기·훔친 쿠키 토큰과 다른 사용자의 비밀번호 변경은 종전대로 모두 폐기
            if ($id === (int)$this->getUserId() && !(isset($updateData['is_active']) && !$updateData['is_active']) && !(isset($updateData['status']) && $updateData['status'] !== 'active')) {
                $this->deleteRememberTokensForUserExcept($id, is_string($_COOKIE['remember_token'] ?? null) ? hash('sha256', $_COOKIE['remember_token']) : null, true);   // ★ (2026-11-10 07:34, 61차 B) 지운 토큰의 세션도 끊음(changePassword 와 같게 — 같은 순간 토큰 복구한 다른 기기 세션이 남던 것 재현). 이 기기 토큰·지금 세션은 남음
            } elseif (isset($updateData['password'])) {
                $this->deleteRememberTokensForUserExcept($id, null, true);   // ★ (2026-11-10 07:21, 61차 B) 다른 사용자 비밀번호 변경은 토큰 모두 지우고 그 토큰의 세션도 끊음(60차 Z14 ② — 같은 순간 토큰 복구한 세션이 남던 것 재현). 비활성·정지만 바꿀 때는 아래 종전 그대로
            } else {
                $this->deleteRememberTokensForUser($id);
            }
        }
        // ★ (2026-10-31 13:34, 34차 후속 개선) 관리자가 비밀번호를 바꾸면 그 사용자의 로그인 세션도 끊음(자기 계정이면 이 기기는 남김)
        if (isset($updateData['password'])) {
            $this->revokeUserSessions($id, $id === (int)$this->getUserId() ? session_id() : null);
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
        // ★ (2026-10-31 13:19, 34차 후속 개선) 잠금 안에서 고침(종전 load→save 는 그사이 다른 사용자 변경을 되돌릴 수 있었음).
        //   부관리자가 '전체' 로 바꿀 때 관리자 계정은 건드리지 않음(개별 수정 user_update 와 같은 경계)
        $updated = 0;
        $callerIsAdmin = $this->isAdmin();
        $this->db->atomicUpdate('users', function ($users) use ($target, $quota, $callerIsAdmin, &$updated) {
        foreach ($users as &$user) {
            $shouldUpdate = false;
            // ★ (2026-10-31 14:09, 34차 후속 개선) 부관리자는 어떤 대상('전체'·'무제한' 등)이든 관리자 계정을 건드리지 않음 — 'unlimited' 는 관리자(보통 무제한)도 바꿨음
            if (!$callerIsAdmin && ($user['role'] ?? 'user') === 'admin') continue;
            
            switch ($target) {
                case 'all':
                    $shouldUpdate = true;
                    break;
                case 'user':
                    // ★ (2026-11-04, 55차 G3) '일반 사용자만' 인데 부관리자까지 바꿨음 → 관리자·부관리자 제외(사용자 정의 역할은 종전처럼 일반 쪽)
                    $shouldUpdate = !in_array(($user['role'] ?? 'user'), ['admin', 'sub_admin'], true);
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
        return $users;
        });
        
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

        // ★ (2026-11-02 09:01, 41차 점검) 사용자별 시도 제한(checkCurrentPassword, D3#7)
        $pwc = $this->checkCurrentPassword((int)$this->getUserId(), $currentPassword, (string)($user['password'] ?? ''));
        if ($pwc === null) return ['success' => false, 'error' => __('api_err_too_many_attempts', '시도가 너무 많습니다. 잠시 후 다시 시도하세요.')];
        if (!$pwc) {
            return ['success' => false, 'error' => __('api_err_current_password', '현재 비밀번호가 올바르지 않습니다.')];
        }
        
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            return ['success' => false, 'error' => __('api_err_password_length', '비밀번호는 8자 이상, 72바이트 이하여야 합니다(한글은 1자가 3바이트).')];   // ★ (2026-11-12 10:56, 97차 개선 B2) 문구만 실제 기준(바이트, bcrypt 72바이트)으로 — 종전 '8~72자' 는 한글 30자(90바이트) 거부 때 안내와 맞지 않았음(재현). 받는 기준은 그대로
        }
        
        $this->clearPendingReset((int)$this->getUserId());   // ★ (2026-11-15 01:31, 153차) 직접 바꾸면 비밀번호 찾기 대기 임시 비밀번호도 지움
        $this->db->update('users', ['id' => $this->getUserId()], [
            'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            'password_reset_required' => false   // ★ (2026-11-12 00:02, 88차 개선 G3-5) 직접 바꾸면 임시 비밀번호 표시를 풂 — 종전엔 바꾼 뒤에도 true 로 남았음(재현)
        ]);
        // ★ (2026-10-31 13:34, 34차 후속 개선) 이 기기를 뺀 그 계정의 다른 로그인 세션도 끊음 — 종전엔 로그인 유지 토큰만 지워, 털린 계정의 비밀번호를 바꿔도 공격자의 기존 세션이 살아 있었음
        $this->revokeUserSessions((int)$this->getUserId(), session_id());
        // ★ (2026-10-29 03:11, 16차) 비밀번호 바꾸면 기존 로그인 유지 토큰 폐기
        // ★ (2026-11-09 14:01, 58차 G1-1) 16차 '모두 폐기'를 '이 기기(쿠키) 토큰만 남기고 폐기'로 바꿈 — 세션은 이 기기를 남기는데 토큰은 이 기기 것까지 지워, 세션 쿠키를 잃으면 다시 로그인해야 했음(재현). '다른 세션 모두 종료'(37차)와 같은 방식, 다른 기기·훔친 쿠키 토큰은 종전대로 폐기
        $this->deleteRememberTokensForUserExcept((int)$this->getUserId(), is_string($_COOKIE['remember_token'] ?? null) ? hash('sha256', $_COOKIE['remember_token']) : null, true);   // ★ (2026-11-09 19:42, 60차 Z14) 지운 토큰의 세션도 끊음(같은 순간 토큰 복구한 다른 기기 세션이 남던 것 — 함수 설명)

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
        if (!$fullUser) {
            return ['success' => false, 'error' => __('api_err_password_incorrect', '비밀번호가 올바르지 않습니다.')];
        }
        // ★ (2026-11-02 11:52, 43차 점검) 로컬 비밀번호가 없는 계정(SSO 등 — login·enable2FA 의 41차 '비밀번호 없음' 판정과 같은 기준)은 비밀번호 칸에 자기 아이디를 넣어 확인 —
        //   종전엔 password_verify('!…') 가 늘 거짓이라 '비밀번호가 올바르지 않습니다'로 탈퇴가 불가능했고 시도마다 pwchk 제한만 소모했음(F5#4). 화면(비밀번호 입력 창)은 그대로 씀
        $wdHash = (string)($fullUser['password'] ?? '');
        if ($wdHash === '' || $wdHash[0] === '!') {
            if (strtolower(trim($password)) !== strtolower((string)($fullUser['username'] ?? ''))) {
                return ['success' => false, 'error' => __('api_err_withdraw_nopw_confirm', '로그인 비밀번호가 없는 계정(SSO)입니다. 확인을 위해 비밀번호 칸에 아이디를 입력하세요.')];
            }
            return $this->deleteUserData($user['id'], __('delete_type_withdraw'));
        }
        // ★ (2026-11-02 09:01, 41차 점검) 사용자별 시도 제한(checkCurrentPassword, D3#7)
        $pwc = $this->checkCurrentPassword((int)$user['id'], $password, $wdHash);
        if ($pwc === null) return ['success' => false, 'error' => __('api_err_too_many_attempts', '시도가 너무 많습니다. 잠시 후 다시 시도하세요.')];
        if (!$pwc) {
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

        // ★ (2026-11-01 17:17, 40차 점검) 사용자 폴더 이동(옛 16단계)을 맨 앞으로 — 종전엔 3~15단계(삭제 기록·로그·세션·공유·권한 삭제)를 먼저 저장한 뒤 rename 해서,
        //   Windows 에서 파일이 열려 있어 rename 이 실패하면 api.php 오류 처리기가 예외로 바꿔 17단계(users 에서 빼기)를 건너뛰었음(사용자는 남고 공유·권한만 사라짐, 삭제 기록만 또 쌓임).
        //   이제 폴더를 못 옮기면 아무것도 지우지 않고 실패로 답함(폴더가 없으면 종전처럼 그냥 진행)
        $delId = 'del_' . bin2hex(random_bytes(7));   // ★ (2026-11-02 09:01, 41차 점검) uniqid 는 다른 PHP 프로세스가 같은 마이크로초에 부르면 같은 값 — 삭제 기록 ID 가 겹치면 purgeDeletedUser 가 둘을 함께 지움(D3#8). 14자리 임의 hex

        // 타임존 안전 타임스탬프 생성 (kstDate는 config.php에서 정의)
        $folderName = $username . '_' . kstDate('Ymd_His') . '_' . $delId;
        $userFolder = USER_FILES_ROOT . '/' . $username;
        $deletedFolder = USER_FILES_ROOT . '/_deleted';
        $targetFolder = $deletedFolder . '/' . $folderName;
        $folderMoved = false;
        if (is_dir($userFolder)) {
            // _deleted 폴더 생성
            if (!is_dir($deletedFolder)) {
                mkdir($deletedFolder, 0755, true);
                // .htaccess로 직접 접근 차단
                file_put_contents($deletedFolder . '/.htaccess', "Deny from all\n");
            }
            if (!@rename($userFolder, $targetFolder)) {
                return ['success' => false, 'error' => __('err_user_folder_move_failed', '사용자 폴더를 옮기지 못했습니다. 그 사용자의 파일이 사용 중일 수 있으니 잠시 뒤 다시 시도하세요.')];
            }
            $folderMoved = true;
        }

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
        // ★ (2026-11-01 17:17, 40차 점검) 4~15단계도 표마다 잠금 안에서(atomicUpdate) 최신 표에 같은 거르기를 적용 — 종전엔 load→filter→save 라
        //   그사이 다른 요청의 권한 회수·공유 삭제·새 세션 등이 낡은 사본으로 덮여 되살아났고(재현), 옛 16단계가 3단계의 낡은 deleted_users 를 다시 저장했음
        $delRecord = [
            'id' => $delId,
            'original_id' => $userId,
            'username' => $username,
            'email' => $user['email'] ?? '',
            'display_name' => $user['display_name'] ?? '',
            'role' => $user['role'] ?? 'user',
            'admin_perms' => (($user['role'] ?? '') === 'sub_admin' && is_array($user['admin_perms'] ?? null)) ? array_values($user['admin_perms']) : null,   // ★ (2026-11-04, 55차 G4) 부관리자 관리 권한도 남김(복원 때 비어 돌아오던 것 — 복원 쪽 반영은 api.php)
            'quota' => (int)($user['quota'] ?? 0),   // ★ (2026-11-02 11:52, 43차 점검) 삭제 전 용량 한도도 남김(복원 때 쓸 수 있게 — 종전엔 기록이 없어 복원 계정이 무제한이 됨, F5#6. 복원 쪽 반영은 api.php)
            'created_at' => $user['created_at'] ?? '',
            'delete_type' => $deleteType,
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => $deleteType === __('delete_type_withdraw') ? $username : ($_SESSION['username'] ?? 'admin'),
            // 로그 전체 보관
            'login_logs' => $userLoginLogs,
            'activity_logs' => $userActivityLogs
        ];
        if ($folderMoved) {
            // 삭제된 사용자 정보에 폴더 경로 추가
            $delRecord['folder_path'] = '_deleted/' . $folderName;
            $delRecord['folder_name'] = $folderName;
        }
        $this->db->atomicUpdate('deleted_users', function ($rows) use ($delRecord) {
            $rows = is_array($rows) ? $rows : [];
            $rows[] = $delRecord;
            return $rows;
        });
        // 표 하나를 잠금 안에서 거르는 도우미(거르기 조건은 종전 그대로)
        $filterTable = function (string $table, callable $keep) {
            $this->db->atomicUpdate($table, function ($rows) use ($keep) {
                return array_values(array_filter(is_array($rows) ? $rows : [], $keep));
            });
        };

        // 4. 로그인 기록 삭제
        $filterTable('login_logs', function($log) use ($username) {
            return strtolower($log['username'] ?? '') !== strtolower($username);
        });

        // 5. 활동 로그 삭제
        $filterTable('activity_logs', function($log) use ($userId) {
            return ($log['user_id'] ?? 0) !== $userId;
        });

        // 6. 세션 삭제
        $filterTable('sessions', function($s) use ($userId) {
            return ($s['user_id'] ?? 0) !== $userId;
        });

        // 7. Remember 토큰 삭제
        $filterTable('remember_tokens', function($t) use ($userId) {
            return ($t['user_id'] ?? 0) !== $userId;
        });

        // 8. 즐겨찾기 삭제
        $filterTable('favorites', function($f) use ($userId) {
            return ($f['user_id'] ?? 0) !== $userId;
        });

        // 9. 최근 파일 삭제
        $filterTable('recent_files', function($r) use ($userId) {
            return ($r['user_id'] ?? 0) !== $userId;
        });

        // 9-1. ★ (2026-10-04) 동영상 이어 보기 기록 삭제
        $filterTable('video_progress', function($r) use ($userId) {
            return ($r['user_id'] ?? 0) !== $userId;
        });

        // 10. 공유 링크 삭제 (해당 사용자가 만든 공유)
        // ★ (2026-10-29 03:11, 16차) 공유는 created_by 로 저장되는데 종전엔 user_id 로만 걸러 한 건도 안 지워졌음. created_by·user_id 둘 다 봄.
        //   또 사용자가 만들었거나 받은 내부 공유(internal_shares)·폴더 규칙(folder_permissions)도 정리 — 안 지우면 ID 가 재사용될 때(다음 가입자=max+1)
        //   그 공유·규칙을 물려받을 수 있었음
        $filterTable('shares', function($s) use ($userId) {
            return ($s['user_id'] ?? 0) !== $userId && (int)($s['created_by'] ?? 0) !== $userId;
        });

        $filterTable('internal_shares', function($s) use ($userId) {
            return (int)($s['shared_by'] ?? 0) !== $userId && (int)($s['shared_with'] ?? 0) !== $userId;
        });

        $filterTable('folder_permissions', function($p) use ($userId) {
            return (int)($p['user_id'] ?? 0) !== $userId;
        });
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
        $filterTable('twofa', function($t) use ($userId) {
            return ($t['user_id'] ?? 0) !== $userId;
        });

        // 12. 스토리지 권한 삭제
        $filterTable('permissions', function($p) use ($userId) {
            return ($p['user_id'] ?? 0) !== $userId;
        });
        
        // 12-1. home 타입 스토리지 항목 삭제 (소유자가 삭제됐으므로)
        //   → 인덱스 재구축, 스토리지 목록 등에서 'unknown' 사용자로 표시되는 문제 방지
        //   → 실제 폴더는 위에서 _deleted/로 이동되므로 DB의 home 항목만 제거하면 됨
        // ★ (2026-11-02 10:29, 42차 점검) 지운 홈 스토리지 번호에 딸린 데이터(색인·숨김·동기화 작업·폴더 규칙·공유·잠금·파일 버전 등)도 deleteStorage 와 같은 정리로 지움(E1#1) —
        //   종전엔 행만 지워, 번호가 다시 나가면(최대+1) 새 가입자 홈에 옛 사용자의 색인·이전 버전·동기화 작업이 붙었음(재현). 번호 재사용 자체는 JsonDB::reserveId 가 막음
        $homeIds = [];
        $filterTable('storages', function($s) use ($userId, &$homeIds) {
            $isHome = (($s['storage_type'] ?? '') === 'home' && ($s['owner_id'] ?? 0) === $userId);
            if ($isHome && (int)($s['id'] ?? 0) > 0) $homeIds[] = (int)$s['id'];
            return !$isHome;
        });
        if ($homeIds && class_exists('Storage') && method_exists('Storage', 'purgeStorageData')) {
            try {
                $purger = new Storage();
                foreach (array_unique($homeIds) as $hid) $purger->purgeStorageData($hid);
            } catch (\Throwable $e) {
                error_log('[Auth] purgeStorageData failed for deleted user ' . $userId . ': ' . $e->getMessage());
            }
        }

        // 13. 로그인 시도 기록 삭제
        $filterTable('login_attempts', function($a) use ($username) {
            return strtolower($a['username'] ?? '') !== strtolower($username);
        });
        
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
        
        // 14-2. ★ (2026-11-01 01:52, 36차 전체 점검) 게시판 — 글·댓글의 작성자 번호는 0 으로(이름 author_name 은 남김, 위 휴지통과 같은 방식), 그 사용자가 받은 게시판 알림은 지움.
        //   종전엔 그대로 남아, 지운 사용자의 번호를 다음 가입자가 받으면(가장 큰 번호 + 1) 그 사람이 이전 사용자의 글·댓글을 자기 이름으로 보고 고치거나 지울 수 있었고 알림도 물려받았음
        $boardAuthorF = function ($rows) use ($userId) {
            $rows = is_array($rows) ? array_values($rows) : [];
            foreach ($rows as &$r) {
                if (is_array($r) && (int)($r['author_id'] ?? 0) === $userId) $r['author_id'] = 0;
            }
            unset($r);
            return $rows;
        };
        $this->db->atomicUpdate('board_posts', $boardAuthorF);
        $this->db->atomicUpdate('board_comments', $boardAuthorF);
        $this->db->atomicUpdate('board_notifications', function ($rows) use ($userId) {
            $rows = array_values(array_filter(is_array($rows) ? $rows : [], function ($n) use ($userId) {
                return !is_array($n) || ((int)($n['user_id'] ?? 0) !== $userId);
            }));
            // ★ (2026-11-01 02:14, 36차 전체 점검) 그 사용자가 보낸 알림의 보낸 사람 번호도 0 으로(이름 from_user_name 은 남김) — 목록이 번호로 지금 이름을 덮어써 새 가입자가 보낸 사람으로 보였음(독립 검토 지적)
            foreach ($rows as &$n) { if (is_array($n) && (int)($n['from_user_id'] ?? 0) === $userId) $n['from_user_id'] = 0; }
            unset($n);
            return $rows;
        });
        
        // 15. 파일 잠금 삭제
        $filterTable('locked_files', function($l) use ($userId) {
            return ($l['user_id'] ?? 0) !== $userId;
        });

        // 16. 사용자 폴더 이동 (users/{username}/ → users/_deleted/{username}_{날짜}_{del_id}/)
        //   ★ (2026-11-01 17:17, 40차 점검) 이 함수 맨 앞으로 옮김(이동 실패 시 아무것도 지우지 않게) — 위 설명 참고

        // 17. 사용자 삭제
        // ★ (2026-10-31 13:19, 34차 후속 개선) 잠금 안에서 그 사용자만 뺌
        $this->db->atomicUpdate('users', function ($users) use ($userId) {
            return array_values(array_filter($users, fn($u) => (int)($u['id'] ?? 0) !== (int)$userId));
        });
        
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
        
        // ★ (2026-11-04, 56차 C1-3) 기록 지우기는 잠금(atomicUpdate) 안에서 그 id 만 거름 — 종전엔 처음 읽은 사본을 (폴더 삭제가 오래 걸린 뒤) 통째로 저장해, 그사이 추가된 다른 사용자의 삭제 기록(보관 위치)이 사라졌음(재현). 폴더 삭제는 지금처럼 잠금 밖에서 먼저
        $this->db->atomicUpdate('deleted_users', function ($rows) use ($id) {
            return array_values(array_filter(is_array($rows) ? $rows : [], fn($u) => ($u['id'] ?? '') !== $id));
        });
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
            if (is_link($path)) { if (!@unlink($path)) @rmdir($path); continue; }   // ★ (2026-11-04 01:27, 54차 점검) 링크는 링크만 지움 — 종전엔 폴더 링크를 따라 들어가 스토리지 밖 파일까지 지웠음(재현)
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
        // ★ (2026-11-01 07:24, 37차 기능 점검) api.php getClientIP 와 같은 새 규칙 — X-Forwarded-For 를 오른쪽부터 보며 유효한 IP 중 신뢰 프록시가 아닌 첫 주소
        //   (유효하지 않은 값을 만나면 멈추고 접속 주소). CF-Connecting-IP 는 그보다 먼저(07:57 재검토), XFF 가 없으면 X-Real-IP(IP 형식 검증).
        //   종전엔 CF-Connecting-IP·XFF 첫 값(클라이언트가 넣을 수 있음)을 그대로 믿어 WebDAV·index.php 의 IP 제한을 헤더로 피할 수 있었음
        $ipIsTrusted = function ($ip) use ($trusted) {
            foreach ($trusted as $proxy) {
                $proxy = (string)$proxy;
                if (strpos($proxy, '/') !== false ? $this->ipInRange($ip, $proxy) : $ip === $proxy) return true;
            }
            return false;
        };
        // ★ (2026-11-01 09:43, 38차 점검) CF-Connecting-IP 를 무조건 먼저 믿어서, 신뢰 프록시(로컬 nginx 등)에 직접 붙은 공격자가 그 헤더만 넣으면
        //   IP 제한·잠금을 아무 주소로 피할 수 있었음 → api.php getClientIP 와 같은 38차 규칙: XFF 맨 오른쪽 값(프록시가 덧붙인 바로 앞 주소)이
        //   유효한 IP 이고 신뢰 프록시일 때만 CF 헤더 사용, XFF 가 없으면 CF 헤더 무시(Cloudflare 는 늘 XFF 를 보냄).
        //   ※ TRUSTED_PROXIES 에 Cloudflare 대역 전체를 넣어야 CF 헤더를 믿음(안 넣으면 XFF 오른쪽부터 걷기 결과 = CF 엣지 주소)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = array_values(array_filter(array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])), 'strlen'));
            $last = $ips ? $ips[count($ips) - 1] : '';
            if ($last !== '' && filter_var($last, FILTER_VALIDATE_IP) && $ipIsTrusted($last)
                && !empty($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
                return (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
            }
            for ($i = count($ips) - 1; $i >= 0; $i--) {
                if (!filter_var($ips[$i], FILTER_VALIDATE_IP)) break;
                if (!$ipIsTrusted($ips[$i])) return $ips[$i];
            }
            return $remoteAddr;
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP']) && filter_var($_SERVER['HTTP_X_REAL_IP'], FILTER_VALIDATE_IP)) return (string)$_SERVER['HTTP_X_REAL_IP'];
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
    
    /** ★ (2026-11-03 09:19, 49차 점검) 저장하려는 보안 설정이 이 IP 를 막는지(기록은 남기지 않음) — security_settings_save 의 '자기 IP 차단' 방지용 */
    public function ipAllowedBySettings(array $settings, string $ip): bool {
        // ★ (2026-11-03 09:42, 49차 재검토) IP 규칙(차단·허용만·관리자 IP)만 봄 — 국가 규칙까지 보면 GeoIP 를 저장돼 있는 옛 소스로 조회해(외부 ip-api 호출·국가 캐시 쓰기) 같은 저장에서 소스를 바꿀 때 잘못 거부했음(검토 재현)
        $s2 = $settings; $s2['block_country'] = false; $s2['allow_country_only'] = false; $s2['block_unknown'] = false;
        return !empty($this->checkIPRestriction($ip, $s2, false)['allowed']);
    }
    
    private function checkIPRestriction(string $ip, ?array $settingsOverride = null, bool $log = true): array {   // ★ (2026-11-03 09:19, 49차 점검) 시험할 설정·기록 여부(기본은 종전과 같음)
        $settings = $settingsOverride ?? $this->getSecuritySettings();
        
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
                    if ($log) $this->logBlockedAccess($ip, __('ip_blocked'));
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
                if ($log) $this->logBlockedAccess($ip, __('ip_not_in_whitelist'));
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
                if ($log) $this->logBlockedAccess($ip, __('country_unknown'));
                return ['allowed' => false, 'reason' => $blockMessage];
            }
            
            // 특정 국가 차단
            if ($blockCountry && !empty($blockedCountries) && in_array($country, $blockedCountries)) {
                if ($log) $this->logBlockedAccess($ip, __f('country_blocked', ['country' => $country]));
                return ['allowed' => false, 'reason' => $blockMessage];
            }
            
            // 특정 국가만 허용
            if ($allowCountryOnly && !empty($allowedCountries)) {
                if (!in_array($country, $allowedCountries)) {
                    if ($log) $this->logBlockedAccess($ip, __f('country_not_allowed', ['country' => $country]));
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
            'user_agent' => mb_strcut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512, 'UTF-8'),   // ★ (2026-11-04 01:38, 54차 점검) 512바이트에서 자름(활동 기록과 같은 상한, 화면은 표시만) — 종전엔 상한이 없어 차단된 요청이 8KB UA 를 그대로 남겼음(활동·로그인 기록 재현과 같은 식, 이 표는 정적 확인)
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
        if (strpos($range, '/') === false) { $a1 = @inet_pton($ip); $b1 = @inet_pton(trim($range)); return ($a1 !== false && $b1 !== false) ? $a1 === $b1 : $ip === $range; }   // ★ (2026-11-03 09:20, 49차 점검) 한 IP 는 이진 형태로 비교(IPv6 대소문자·0 생략 여부·표기가 달라도 같은 주소면 일치) — 종전 글자 비교라 '2001:DB8::1' 을 넣으면 저장은 되는데 '2001:db8::1' 접속과 안 맞았음. 해석 못 하면 종전처럼 글자 비교
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
        // ★ (2026-11-04 07:47, 54차 결정 — 펜닐 '알아서') 인터넷에서 올 수 없는 주소도 내부망으로: 사설 IPv6(fc00::/7)·링크 로컬(fe80::/10·169.254/16)·127/8 전체·IPv4 매핑(::ffff:사설)·CGNAT(100.64/10 — Tailscale 등)
        //   종전엔 '국가만 허용'·'알 수 없음 차단'을 켜면 내부망 IPv6·VPN 접속이 막혔음. 국가 판정만 건너뛰는 용도라(IP 차단·관리자 IP 규칙은 그대로) 막던 것을 푸는 쪽만
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $lcIp = strtolower($ip);
            if (strpos($lcIp, '::ffff:') === 0 && filter_var(substr($lcIp, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) $lcIp = substr($lcIp, 7);
            if (filter_var($lcIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return 'LOCAL';
            if (preg_match('/^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\./', $lcIp)) return 'LOCAL';
            if (preg_match('/^fe[89ab][0-9a-f]:/', $lcIp)) return 'LOCAL';
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
        // ★ (2026-11-01 17:18, 40차 점검) 잠금 안에서 최신 캐시에 붙임 — 종전엔 조회(수 초) 전에 읽은 사본을 통째로 저장해 그사이 다른 요청이 넣은 캐시가 사라졌음
        $this->db->atomicUpdate('ip_country_cache', function ($cache) use ($ip, $country, $cacheHours) {
            $cache = is_array($cache) ? $cache : [];
            $cache[] = ['ip' => $ip, 'country' => $country, 'cached_at' => date('Y-m-d H:i:s')];
            $cache = array_filter($cache, fn($e) => strtotime($e['cached_at'] ?? '0') > strtotime("-{$cacheHours} hours"));
            if (count($cache) > 1000) $cache = array_slice($cache, -500);
            return array_values($cache);
        });
        
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
    // ★ (2026-11-14 12:27, 127차 펜닐 결정 '관리자 IP는 잠금에서 빼기')
    //   보안 설정의 '무제한 잠금'(또는 잠금 시간 0)이면 관리자 본인도 틀린 횟수를 넘기면 영구히 잠겨, 관리자 IP 에서도·설정을 되돌려도 풀리지 않았음(재현).
    //   관리자 IP 목록(admin_ips)에 있는 IP 는 무제한 대신 보통 잠금(설정한 잠금 시간, 0 이면 15분)으로 — 대입 막기는 그대로 두고 시간이 지나면 풀림.
    //   관리자 IP 가 아니거나 무제한이 아니면 아무것도 바꾸지 않음
    private function adminIpLockRelief(array $settings, string $ip, &$lockoutPermanent, &$lockoutMinutes): void {
        if (!($lockoutPermanent || (int)$lockoutMinutes === 0)) return;
        $adminIps = $settings['admin_ips'] ?? [];
        if (!is_array($adminIps) || !$adminIps || $ip === '') return;
        foreach ($adminIps as $adminIp) {
            $adminIp = trim((string)$adminIp);
            if ($adminIp !== '' && $this->ipInRange($ip, $adminIp)) {
                $lockoutPermanent = false;
                $lockoutMinutes = (int)$lockoutMinutes > 0 ? (int)$lockoutMinutes : 15;
                return;
            }
        }
    }

    private function isLockedOut(string $username, string $ip): bool {
        $settings = $this->getSecuritySettings();
        $maxAttempts = $settings['max_attempts'] ?? 5;
        $lockoutMinutes = $settings['lockout_minutes'] ?? 15;
        $lockoutPermanent = $settings['lockout_permanent'] ?? false;
        $attemptResetMinutes = $settings['attempt_reset_minutes'] ?? 30;
        $this->adminIpLockRelief($settings, $ip, $lockoutPermanent, $lockoutMinutes);   // ★ (2026-11-14 12:27, 127차 펜닐 결정 '관리자 IP는 잠금에서 빼기') 설명은 함수 위
        
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
                        // ★ (2026-10-31 12:15, 34차 전체 점검) 잠금 안에서 그 행만 0 으로(다시 확인) — 종전엔 통째 load→save 라 동시에 들어온 다른 실패 기록을 덮어썼음
                        $this->db->atomicUpdate('login_attempts', function ($all) use ($key, $attemptResetMinutes) {
                            foreach ($all as &$a) {
                                if (($a['key'] ?? '') === $key && time() - strtotime($a['last_attempt'] ?? '0') >= $attemptResetMinutes * 60) $a['count'] = 0;
                            }
                            unset($a);
                            return $all;
                        });
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
        
        // ★ (2026-10-31 12:15, 34차 전체 점검) 잠금 안에서 읽고 +1 해 저장 — 종전엔 load→+1→save 라 같은 아이디로 동시에 여러 번 틀리면 횟수가 빠져
        //   '15분에 5회' 잠금이 사실상 걸리지 않았음(재현: 동시 30회 → 횟수 1~몇)
        $key = md5($username . $ip);
        $this->db->atomicUpdate('login_attempts', function ($attempts) use ($key, $username, $ip, $attemptResetMinutes) {
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
                'username' => self::attemptName($username),
                'ip' => $ip,
                'count' => 1,
                'attempts' => 1,
                'last_attempt' => date('Y-m-d H:i:s')
            ];
            $s = $this->getSecuritySettings();
            $attempts = $this->pruneLoginAttempts($attempts, (int)($s['max_attempts'] ?? 5), (int)($s['lockout_minutes'] ?? 15), (bool)($s['lockout_permanent'] ?? false), (int)$attemptResetMinutes);
        }

        return $attempts;
        });
    }

    /** ★ (2026-11-02 09:01, 41차 점검) login_attempts 에 적는 아이디는 255바이트까지(UTF-8 글자 단위로 자름) — 종전엔 길이 제한이 없어 1MB 아이디면 행 하나가 1MB(D2#3). 잠금 key(md5)는 종전대로 전체 입력으로 */
    private static function attemptName(string $username): string {
        return strlen($username) > 255 ? mb_strcut($username, 0, 255, 'UTF-8') : $username;
    }

    /** ★ (2026-11-10 14:36, 64차) 비밀번호 해시의 짧은 지문(sha256 앞 16자) — 2FA 대기 값에 해시 원문 대신 넣어 비밀번호가 바뀌었는지만 비교 */
    private static function pwFingerprint(string $hash): string {
        return substr(hash('sha256', $hash), 0, 16);
    }

    /**
     * ★ (2026-11-02 09:01, 41차 점검) login_attempts 정리(D2#3) — 종전엔 자동 정리가 없어 아이디를 바꿔 가며 틀리면 행이 끝없이 늘고, 로그인마다 표 전체를 읽고 썼음.
     *   ① 마지막 시도가 30일(잠금·리셋 시간이 더 길면 그만큼)보다 오래된 행을 지움 — 단 '무제한 잠금'으로 잠긴 행은 관리자가 풀 때까지 둠
     *   ② 그래도 1만 행(로그인 기록 상한과 같음)을 넘으면 지금 잠겨 있는 행을 먼저 남기고 최근 시도 순으로 1만 행만 남김
     *   새 행을 추가할 때만 부름(기존 행 갱신은 표 크기가 그대로)
     */
    private function pruneLoginAttempts(array $attempts, int $maxAttempts, int $lockoutMinutes, bool $lockoutPermanent, int $attemptResetMinutes): array {
        $now = time();
        $keepSec = max(30 * 86400, $lockoutMinutes * 60, $attemptResetMinutes * 60);
        $isLocked = function ($a) use ($now, $maxAttempts, $lockoutMinutes, $lockoutPermanent) {
            if ($maxAttempts <= 0 || (int)($a['count'] ?? 0) < $maxAttempts) return false;
            return $lockoutPermanent || $lockoutMinutes === 0 || $now - strtotime($a['last_attempt'] ?? '0') < $lockoutMinutes * 60;
        };
        $permLock = $lockoutPermanent || $lockoutMinutes === 0;
        $attempts = array_values(array_filter($attempts, function ($a) use ($now, $keepSec, $permLock, $maxAttempts) {
            if (!is_array($a)) return false;
            if ($permLock && $maxAttempts > 0 && (int)($a['count'] ?? 0) >= $maxAttempts) return true;
            return $now - (int)strtotime($a['last_attempt'] ?? '0') < $keepSec;
        }));
        if (count($attempts) > 10000) {
            usort($attempts, function ($a, $b) use ($isLocked) {
                $la = $isLocked($a) ? 1 : 0; $lb = $isLocked($b) ? 1 : 0;
                if ($la !== $lb) return $lb - $la;
                return strcmp((string)($b['last_attempt'] ?? ''), (string)($a['last_attempt'] ?? ''));
            });
            $attempts = array_slice($attempts, 0, 10000);
        }
        return $attempts;
    }
    
    private function clearFailedAttempts(string $username, string $ip): void {
        $key = md5($username . $ip);
        // ★ (2026-10-31 12:15, 34차 전체 점검) 잠금 안에서 그 행만 지움(다른 사람의 실패 기록을 덮어쓰지 않게)
        $this->db->atomicUpdate('login_attempts', function ($attempts) use ($key) {
            return array_values(array_filter($attempts, fn($a) => ($a['key'] ?? '') !== $key));
        });
    }

    /**
     * ★ (2026-11-01 07:21, 37차 기능 점검) 로그인 시도 예약 — 한 잠금 안에서 isLockedOut 과 같은 기준으로 잠겼는지 보고, 잠겼으면 false(기록 안 함),
     *   아니면 recordFailedAttempt 와 같은 방식으로 이번 시도를 1회 기록하고 true. 비밀번호가 맞으면 releaseLoginAttempt 로 되돌림.
     *   종전엔 확인과 기록이 따로라 동시에 보낸 요청들이 잠금 기준을 모두 통과했음. 기록 파일을 못 잠그면 막지 않음(종전과 같게)
     */
    private function reserveLoginAttempt(string $username, string $ip, ?array &$undo): bool {
        $undo = null;
        $settings = $this->getSecuritySettings();
        $maxAttempts = $settings['max_attempts'] ?? 5;
        $lockoutMinutes = $settings['lockout_minutes'] ?? 15;
        $lockoutPermanent = $settings['lockout_permanent'] ?? false;
        $attemptResetMinutes = $settings['attempt_reset_minutes'] ?? 30;
        $this->adminIpLockRelief($settings, $ip, $lockoutPermanent, $lockoutMinutes);   // ★ (2026-11-14 12:27, 127차 펜닐 결정 '관리자 IP는 잠금에서 빼기') isLockedOut 과 같은 기준
        if ($maxAttempts <= 0) return true;

        $key = md5($username . $ip);
        $locked = false; $u = null;
        $this->db->atomicUpdate('login_attempts', function ($attempts) use ($key, $username, $ip, $maxAttempts, $lockoutMinutes, $lockoutPermanent, $attemptResetMinutes, &$locked, &$u) {
            $attempts = is_array($attempts) ? $attempts : [];
            $now = date('Y-m-d H:i:s');
            foreach ($attempts as &$attempt) {
                if (($attempt['key'] ?? '') !== $key) continue;
                $lastAttempt = strtotime($attempt['last_attempt'] ?? '0');
                $count = $attempt['count'] ?? 0;
                $resetDue = $attemptResetMinutes > 0 && time() - $lastAttempt >= $attemptResetMinutes * 60;
                // 잠금 확인(isLockedOut 과 같은 기준)
                if ($count >= $maxAttempts) {
                    if ($lockoutPermanent || $lockoutMinutes === 0 || time() - $lastAttempt < $lockoutMinutes * 60) { $locked = true; break; }
                }
                // 이번 시도 기록(recordFailedAttempt 와 같은 방식)
                $u = ['created' => false, 'prev_count' => $attempt['count'] ?? 0, 'prev_attempts' => $attempt['attempts'] ?? null, 'prev_last' => $attempt['last_attempt'] ?? null];
                $attempt['count'] = $resetDue ? 1 : $count + 1;
                $attempt['last_attempt'] = $now;
                $attempt['attempts'] = $attempt['count'];
                $u['count'] = $attempt['count']; $u['last'] = $now;
                break;
            }
            unset($attempt);
            if (!$locked && $u === null) {
                $attempts[] = ['key' => $key, 'username' => self::attemptName($username), 'ip' => $ip, 'count' => 1, 'attempts' => 1, 'last_attempt' => $now];
                $u = ['created' => true, 'count' => 1, 'last' => $now];
                $attempts = $this->pruneLoginAttempts($attempts, (int)$maxAttempts, (int)$lockoutMinutes, (bool)$lockoutPermanent, (int)$attemptResetMinutes);
            }
            return $attempts;
        });
        if ($locked) return false;
        $undo = $u;
        return true;
    }

    /** ★ (2026-11-01 07:21, 37차 기능 점검) reserveLoginAttempt 로 미리 기록한 1회를 되돌림 — 그사이 다른 시도가 없으면 원래 값으로, 있으면 1회만 뺌 */
    private function releaseLoginAttempt(string $username, string $ip, ?array $undo): void {
        if (!$undo) return;
        $key = md5($username . $ip);
        $this->db->atomicUpdate('login_attempts', function ($attempts) use ($key, $undo) {
            $attempts = is_array($attempts) ? $attempts : [];
            foreach ($attempts as $i => &$a) {
                if (($a['key'] ?? '') !== $key) continue;
                $untouched = ($a['last_attempt'] ?? null) === $undo['last'] && (int)($a['count'] ?? 0) === (int)$undo['count'];
                if ($untouched && !empty($undo['created'])) { unset($attempts[$i]); break; }
                if ($untouched) {
                    $a['count'] = $undo['prev_count'];
                    $a['last_attempt'] = $undo['prev_last'];
                    if ($undo['prev_attempts'] === null) unset($a['attempts']); else $a['attempts'] = $undo['prev_attempts'];
                } else {
                    $a['count'] = max(0, (int)($a['count'] ?? 0) - 1);
                    $a['attempts'] = $a['count'];
                }
                break;
            }
            unset($a);
            return array_values($attempts);
        });
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
        
        // ★ (2026-11-01 01:08, 35차 전체 점검) 잠금 안에서 읽고 고침 — 종전 load→save 는 그사이 비밀번호 변경으로 지운 토큰들을 되살릴 수 있었음
        // ★ (2026-11-01 07:23, 37차 기능 점검) 토큰을 만든 세션 ID(sid)를 함께 적음 — 그 세션을 끊을 때(세션 종료·동시 세션 초과) 이 토큰도 지우려고(F01-1)
        $newTok = ['user_id' => $userId, 'token' => hash('sha256', $token), 'expires' => $expires, 'created_at' => date('Y-m-d H:i:s'), 'sid' => (string)session_id()];
        // ★ (2026-11-07 10:40, 로그인 유지 풀림 수정) 이 브라우저가 들고 온 옛 토큰은 아래 setcookie 로 덮여 다시 쓰이지 않으므로 함께 지움 —
        //   종전엔 다시 로그인할 때마다 옛 토큰(최대 10년)이 남아 쌓였고, 그 토큰의 옛 세션 행도 남아 동시 세션 수에 들어갔음
        $oldHash = is_string($_COOKIE['remember_token'] ?? null) && $_COOKIE['remember_token'] !== '' ? hash('sha256', $_COOKIE['remember_token']) : null;
        $oldTok = null;
        $this->db->atomicUpdate('remember_tokens', function ($tokens) use ($newTok, $oldHash, &$oldTok) {
            $tokens = is_array($tokens) ? $tokens : [];
            if ($oldHash !== null && !hash_equals($oldHash, $newTok['token'])) {
                $tokens = array_values(array_filter($tokens, function ($t) use ($oldHash, &$oldTok) {
                    if (is_array($t) && hash_equals((string)($t['token'] ?? ''), $oldHash)) { $oldTok = $t; return false; }
                    return true;
                }));
            }
            $tokens[] = $newTok;
            // 만료된 토큰 정리
            return array_values(array_filter($tokens, fn($t) => strtotime($t['expires'] ?? '0') > time()));
        });
        
        setcookie('remember_token', $token, ['expires' => time() + ($days * 86400), 'path' => '/', 'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax']);

        // ★ (2026-11-07 10:40) 옛 토큰이 쓰던 세션 행 정리(같은 브라우저의 지난 세션). 지금 세션이면 건너뜀
        if (is_array($oldTok)) {
            $this->retireSupersededSession((int)($oldTok['user_id'] ?? 0), (string)($oldTok['sid'] ?? ''));
        }
    }
    
    public function checkRememberToken(): bool {
        // ★ (2026-11-01 17:18, 40차 점검) 배열 쿠키(remember_token[]=1)면 hash() 에서 TypeError → 세션 없는 요청(requireLogin·me)이 500 — 문자열 아니면 토큰 없음으로
        if (!is_string($_COOKIE['remember_token'] ?? null)) return false;
        
        // 이미 로그인 상태면 스킵 (동시 요청에서 중복 체크 방지)
        // ★ (2026-11-02 10:29, 42차 점검) 이 설치의 로그인 표시가 있을 때만 — 종전 기준(user_id 만)이면 다른 앱 세션·42차 전 세션에서 '복구 성공'으로 답하고 실제로는 로그인 안 된 채였음
        if (isset($_SESSION['user_id']) && $this->sessionInstanceOk()) return true;
        
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
                    
                    // ★ (2026-11-02 11:50, 43차 점검) 정지라도 지금 기간 밖(시작일 전·종료일 지남)이면 복구 허용 — 로그인·세션 유지와 같은 기준(F5#3). 종전엔 예약한 정지도 바로 로그인 유지가 끊겼음
                    // ★ (2026-11-02 12:19, 43차 재검토) 지금 정지 기간이면 이 토큰을 지움 — 예약 정지는 저장 때 토큰을 남기므로(시작 전엔 써야 함), 정지 기간에 막히기만 하고 남아 종료 뒤 옛 쿠키로 다시 자동 로그인됐음(검토 발견)
                    if ($status === 'suspended' && fsUserSuspendedNow($user)) { $this->deleteRememberToken($token); }
                    if (($status === 'active' || ($status === 'suspended' && !fsUserSuspendedNow($user))) && $isActive) {
                        // ★ (2026-11-02 09:01, 41차 점검) 토큰으로 세션을 열 때도 세션 ID·CSRF 토큰을 새로(일반 로그인·2FA·SSO 와 같게, D3#3) — 종전엔 지금 세션 ID 그대로
                        //   로그인시켜, 공격자가 심어 둔 로그인 전 세션 ID 가 피해자 계정 세션이 됐음(세션 고정). 세션이 닫혔거나 헤더를 이미 보냈으면 바꿀 수 없어 종전대로.
                        //   화면: index.php 는 이 뒤에 getCsrfToken() 으로 새 토큰을 넣고, app.js checkAuth 는 me 뒤에 csrf_token 을 다시 받음. API 중간 복구는 다음 요청의 csrf_error 재시도로 받음
                        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                            if (@session_regenerate_id(true)) regenerateCsrfToken();
                        }
                        $_SESSION['user_id'] = $user['id'];
                        $this->markSessionInstance();   // ★ (2026-11-02 10:29, 42차 점검) 로그인 유지 복구도 이 설치의 로그인 표시(E5#3)
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['last_activity'] = time();
                        $_SESSION['remember_me'] = true;
                        // ★ (2026-11-01 07:23, 37차 기능 점검) 토큰으로 새 세션을 열면 토큰의 sid 를 이 세션 ID 로 바꿈 — 종전엔 토큰이 세션과 묶여 있지 않아, 세션을 끊어도
                        //   (세션 쿠키를 지우거나 세션이 만료된 뒤) 이 토큰으로 바로 다시 로그인됐음(재현). 이제 이 세션을 끊으면 토큰도 지워짐
                        $curSid = (string)session_id();
                        // ★ (2026-11-07 10:40, 로그인 유지 풀림 수정) 옛 세션 ID 는 잠금 안에서 읽음 — 동시에 복구한 요청이 먼저 바꿔 둔 값을 보기 위해(위 $t 는 잠금 밖에서 읽은 것)
                        $prevSid = (string)($t['sid'] ?? '');
                        if ($curSid !== '' && $prevSid !== $curSid) {
                            $tokAlive = false;
                            $sidUpOk = $this->db->atomicUpdate('remember_tokens', function ($all) use ($hashedToken, $curSid, &$prevSid, &$tokAlive) {
                                $all = is_array($all) ? $all : [];
                                foreach ($all as &$x) { if (hash_equals((string)($x['token'] ?? ''), $hashedToken)) { $prevSid = (string)($x['sid'] ?? ''); $x['sid'] = $curSid; $tokAlive = true; } }
                                unset($x);
                                return $all;
                            });
                            // ★ (2026-11-09 19:42, 60차 Z14) 잠금 안에서 보니 토큰이 이미 지워졌으면(위 확인 뒤 비밀번호 변경·'다른 세션 모두 종료' 등) 이번 복구를 취소 — 종전엔 그대로 로그인돼 그 세션이 끊김 목록에도 없이 남았음(재현).
                            //   토큰이 있으면(정상·동시 복구) 종전과 같음, 잠금을 못 잡았으면($sidUpOk false) 종전대로 진행
                            if ($sidUpOk && !$tokAlive) {
                                unset($_SESSION['user_id'], $_SESSION['fs_instance'], $_SESSION['username'], $_SESSION['role'], $_SESSION['last_activity'], $_SESSION['remember_me']);
                                return false;
                            }
                        }
                        // ★ (2026-11-07 10:40) 이 토큰이 쓰던 옛 세션(같은 브라우저)을 정리 — 세션 행이 쌓여 동시 세션 한도로 다른 기기가 밀려나던 것(retireSupersededSession 설명)
                        if ($curSid !== '' && $prevSid !== '' && $prevSid !== $curSid) {
                            $this->retireSupersededSession((int)$user['id'], $prevSid);
                        }
                        
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
        // ★ (2026-11-01 01:08, 35차 전체 점검) 잠금 안에서 지움(동시 요청이 넣은 토큰을 덮어쓰지 않게)
        $this->db->atomicUpdate('remember_tokens', function ($tokens) use ($hashedToken) {
            return array_values(array_filter(is_array($tokens) ? $tokens : [], fn($t) => ($t['token'] ?? '') !== $hashedToken));
        });
    }

    /**
     * ★ (2026-10-29 03:11, 16차) 한 사용자의 로그인 유지 토큰을 모두 폐기 — 비밀번호 변경·초기화·2FA 켜기 때 호출.
     *   종전엔 탈퇴/삭제(deleteUserData)에서만 지워, 훔친 remember 쿠키가 비밀번호를 바꾸거나 2FA 를 켜도 최대 10년간 로그인됐음
     */
    public function deleteRememberTokensForUser(int $userId): void {
        // ★ (2026-11-01 01:08, 35차 전체 점검) 잠금 안에서 지움 — 종전엔 같은 순간 새로 만든 토큰 저장이 이 삭제를 되돌릴 수 있었음
        $this->db->atomicUpdate('remember_tokens', function ($tokens) use ($userId) {
            return array_values(array_filter(is_array($tokens) ? $tokens : [], fn($t) => (int)($t['user_id'] ?? 0) !== $userId));
        });
    }

    /** ★ (2026-11-01 07:23, 37차 기능 점검) 끊은 세션들(sid)의 로그인 유지 토큰을 지움 — 세션 종료·동시 세션 초과 정리에서 호출 */
    private function deleteRememberTokensForSessions(array $sessionIds): void {
        $sessionIds = array_values(array_filter(array_map('strval', $sessionIds), fn($x) => $x !== ''));
        if (!$sessionIds) return;
        $this->db->atomicUpdate('remember_tokens', function ($tokens) use ($sessionIds) {
            return array_values(array_filter(is_array($tokens) ? $tokens : [], fn($t) => !in_array((string)($t['sid'] ?? ''), $sessionIds, true)));
        });
    }

    /** ★ (2026-11-01 07:23, 37차 기능 점검) 한 사용자의 로그인 유지 토큰을 지금 기기(쿠키) 것만 남기고 모두 지움 — '다른 기기 모두 로그아웃'. sid 없는 옛 토큰도 지워짐 */
    // ★ (2026-11-09 19:42, 60차 Z14) $revokeSids — true 면 지운 토큰이 가리키던 세션(sid)도 끊음(지금 세션 제외). 비밀번호 변경·'다른 세션 모두 종료'만 true:
    //   같은 순간 토큰만으로 복구하던 다른 기기 요청이 세션 끊기 뒤에 새 세션을 기록하면 끊을 목록에 없어 살아남았음(재현 — 그 요청은 잠금 안에서 토큰 sid 를 새 세션으로 바꿔 두므로 여기서 끊김). 기본 false = 종전(2FA 켜기 등)
    private function deleteRememberTokensForUserExcept(int $userId, ?string $keepHash, bool $revokeSids = false): void {
        $delSids = [];
        $this->db->atomicUpdate('remember_tokens', function ($tokens) use ($userId, $keepHash, $revokeSids, &$delSids) {
            $kept = array_values(array_filter(is_array($tokens) ? $tokens : [], fn($t) => (int)($t['user_id'] ?? 0) !== $userId
                || ($keepHash !== null && hash_equals((string)($t['token'] ?? ''), $keepHash))));
            if ($revokeSids) {
                foreach ((is_array($tokens) ? $tokens : []) as $t) {
                    if (is_array($t) && !in_array($t, $kept, true) && (string)($t['sid'] ?? '') !== '') $delSids[] = (string)$t['sid'];
                }
            }
            return $kept;
        });
        $curSid = (string)session_id();
        $delSids = array_values(array_filter(array_unique($delSids), fn($x) => $x !== $curSid));
        if ($delSids) $this->revokeSessionIds($delSids);
    }
    
    // ===== 세션 관리 =====
    private function recordSession(int $userId, string $ip): bool {   // ★ (2026-11-02 15:02, 44차 점검) 기록했는지 돌려줌(isLoggedIn 의 60초 건너뛰기는 기록에 성공했을 때만 시작). 기록 안 하는 설정이면 false(건너뛰기 없음 — 종전과 같이 매번 여기서 바로 끝남)
        if (!defined('SESSION_TRACKING_ENABLED') || !SESSION_TRACKING_ENABLED) return false;
        if (defined('FS_WEBDAV_REQUEST')) return false;   // ★ (2026-10-29 09:14, 20차) WebDAV(쿠키 없는 1회성 세션)는 기록하지 않음 — mydav.php 참고
        
        $sessionId = session_id();
        // ★ (2026-11-01 07:23, 37차 기능 점검) 잠금 안에서 읽고 고침(atomicUpdate) — 종전엔 매 요청 통째 load→save 라 동시에 끊은 세션 행이 되살아나거나 새 로그인 행이 빠졌음.
        //   밀려난 세션 끊기(revoked_sessions)와 그 세션의 로그인 유지 토큰 삭제는 잠금을 푼 뒤에
        $pushedOut = [];
        $_rsOk = $this->db->atomicUpdate('sessions', function ($sessions) use ($userId, $ip, $sessionId, &$pushedOut) {
        $sessions = is_array($sessions) ? $sessions : [];
        // ★ (2026-11-09 10:23, 재검토 A-1) 끊김·대체됨 표시가 붙은 행은 수에 넣지 않고 지움(이미 못 쓰는 세션 — 토큰은 지우지 않음). 같은 브라우저 요청 여러 개가 동시에 토큰으로 복구하면 대체된 세션 행이 다시 들어가 남아, 동시 세션 수를 채워 다른 기기를 밀어내고 그 토큰까지 지웠음(재현). 표시는 이 잠금 안에서 읽음(retireSupersededSession 은 표시 먼저 하고 행을 지움)
        $this->db->clearCache('revoked_sessions');   // ★ (2026-11-09 10:40, 재검토) 캐시를 거치지 않고 파일에서 읽음 — 캐시는 수정 시각(초)·크기·inode 로 비교하는데 Windows 는 inode 가 0 이라 같은 초·같은 크기 변경을 놓칠 수 있음
        $_revMarks = $this->db->load('revoked_sessions');
        if (is_array($_revMarks) && $_revMarks) {
            $sessions = array_values(array_filter($sessions, function ($s) use ($_revMarks) { $x = (string)($s['session_id'] ?? ''); return $x === '' || (!isset($_revMarks[$x]) && !isset($_revMarks['~' . $x])); }));
            if (isset($_revMarks[$sessionId]) || isset($_revMarks['~' . $sessionId])) return $sessions;   // 지금 세션이 이미 끊김·대체됨이면 기록하지 않음(다음 요청에서 isLoggedIn 이 닫음)
        }
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
                'user_agent' => mb_strcut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512, 'UTF-8'),   // ★ (2026-11-04 01:38, 54차 점검) 512바이트에서 자름(세션 목록 화면은 표시만, 비교에 안 씀) — 종전엔 상한이 없었음(재현: 8020바이트 그대로 저장)
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
                $pushedOut = array_map(fn($r) => $r['session_id'] ?? '', $toRemove);   // ★ (2026-10-29 03:11, 16차) 밀려난 세션은 실제로 끊음(아래 잠금 밖에서)
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
        
        return array_values($sessions);
        });
        if ($pushedOut) {
            $this->revokeSessionIds($pushedOut);
            $this->deleteRememberTokensForSessions($pushedOut);   // ★ (2026-11-01 07:23, 37차 기능 점검) 밀려난 세션의 로그인 유지 토큰도 지움(종전엔 토큰으로 바로 다시 들어왔음)
        }
        return (bool)$_rsOk;
    }
    
    private function removeSession(int $userId, string $sessionId): void {
        // ★ (2026-11-01 07:23, 37차 기능 점검) 잠금 안에서 그 행만 지움(종전 load→save 는 동시에 들어온 다른 세션 기록을 덮어썼음)
        $this->db->atomicUpdate('sessions', function ($sessions) use ($userId, $sessionId) {
            return array_values(array_filter(is_array($sessions) ? $sessions : [], fn($s) => !(($s['user_id'] ?? 0) === $userId && ($s['session_id'] ?? '') === $sessionId)));
        });
    }

    /**
     * ★ (2026-10-29 03:11, 16차) 강제 종료된 세션 ID 목록(revoked_sessions.json: session_id→끊은 시각).
     *   '세션 종료'·'다른 세션 모두 종료'·동시 세션 초과 정리에서 지운 세션 ID 를 여기에 적어, 그 세션의 다음 요청을 isLoggedIn 이 끊음.
     *   세션은 어차피 24시간이면 만료되므로 그보다 오래된 항목은 지움(파일이 계속 커지지 않게)
     */
    /** ★ (2026-10-31 13:34, 34차 후속 개선) 한 사용자의 로그인 세션을 모두(또는 지정한 세션 하나 빼고) 끊음 — 세션 기록은 잠금 안에서 지우고 끊은 목록에 넣음 */
    private function revokeUserSessions(int $userId, ?string $exceptSid): void {
        if ($userId <= 0) return;
        $ids = [];
        $this->db->atomicUpdate('sessions', function ($sessions) use ($userId, $exceptSid, &$ids) {
            if (!is_array($sessions)) return [];
            $keep = [];
            foreach ($sessions as $s) {
                $sid = (string)($s['session_id'] ?? '');
                if ((int)($s['user_id'] ?? 0) === $userId && $sid !== '' && $sid !== (string)$exceptSid) { $ids[] = $sid; continue; }
                $keep[] = $s;
            }
            return $keep;
        });
        $this->revokeSessionIds($ids);
    }
    
    private function revokeSessionIds(array $sessionIds): void {
        $sessionIds = array_values(array_filter(array_map('strval', $sessionIds), fn($x) => $x !== ''));
        if (!$sessionIds) return;
        $now = time();
        $_sessLife = max(86400, (int)ini_get('session.gc_maxlifetime'));   // ★ (2026-10-30 01:10, 25차 재검토) 끊은 세션 기록도 PHP 세션 수명만큼 보관(종전 하루 — 수명이 30일이면 하루 뒤 같은 세션으로 다시 들어올 수 있었음)
        // ★ (2026-11-01 01:08, 35차 전체 점검) 잠금 안에서 더함 — 종전 load→save 는 동시에 끊은 다른 세션 ID 를 덮어써 그 세션이 살아남을 수 있었음
        $this->db->atomicUpdate('revoked_sessions', function ($revoked) use ($sessionIds, $now, $_sessLife) {
            $revoked = is_array($revoked) ? $revoked : [];
            foreach ($sessionIds as $sid) $revoked[$sid] = $now;
            foreach ($revoked as $sid => $ts) { if (!is_int($ts) || $now - (int)$ts > $_sessLife) unset($revoked[$sid]); }
            return $revoked;
        });
    }

    private function isSessionRevoked(string $sessionId): bool {
        return $this->sessionRevokeKind($sessionId) === 'revoked';
    }

    /**
     * ★ (2026-11-07 10:40, 로그인 유지 풀림 수정) 세션이 끊긴 종류 — 'revoked'(일부러 끊음, 종전과 같음) / 'superseded'(로그인 유지 복구로 대체됨) / null(끊기지 않음).
     *   대체 표시는 같은 revoked_sessions.json 에 '~세션ID' 키로 둠(세션 ID 에는 '~' 가 들어가지 않아 겹치지 않음). 보관 기간·정리는 revokeSessionIds 와 같음.
     *   일부러 끊은 기록이 있으면 그쪽이 우선
     */
    private function sessionRevokeKind(string $sessionId): ?string {
        if ($sessionId === '') return null;
        $revoked = $this->db->load('revoked_sessions');
        if (!is_array($revoked)) return null;
        if (isset($revoked[$sessionId])) return 'revoked';
        if (isset($revoked['~' . $sessionId])) return 'superseded';
        return null;
    }

    /**
     * ★ (2026-11-07 10:40, 로그인 유지 풀림 수정) 같은 브라우저의 옛 세션을 정리 — 로그인 유지 토큰으로 새 세션을 열었거나(checkRememberToken),
     *   로그인 유지를 켜고 다시 로그인해 옛 토큰이 바뀔 때(createRememberToken) 부름.
     *   원인: 종전엔 복구할 때마다 새 세션 행만 늘고 옛 행은 남아, 기기 2~3대여도 하루 안 행이 동시 세션 한도(SESSION_MAX_CONCURRENT)를 넘음 →
     *   살아 있는 다른 기기 세션이 밀려나며 그 기기의 로그인 유지 토큰까지 지워져(37차 F01-1) 다음날 로그아웃돼 있었음(실데이터: 하루 11건 끊김 기록, 대조 가능한 5건은 새 세션 생성과 같은 초).
     *   - 옛 세션 행을 sessions 목록에서 지움(동시 세션 수·세션 목록에서 빠짐)
     *   - 옛 세션을 '대체됨'으로 표시 — 그 세션 ID 로 다시 들어오면 세션만 닫힘(남은 PHP 세션 파일을 훔친 쪽도 못 씀). 토큰은 지우지 않음
     *   토큰 삭제(deleteRememberTokensForSessions)는 하지 않음 — 이 옛 세션에 묶였던 토큰은 방금 새 세션으로 옮겨졌거나 바뀌는 중임
     */
    private function retireSupersededSession(int $userId, string $oldSid): void {
        $oldSid = (string)$oldSid;
        if ($userId <= 0 || $oldSid === '' || $oldSid === (string)session_id()) return;
        $now = time();
        $_sessLife = max(86400, (int)ini_get('session.gc_maxlifetime'));
        $this->db->atomicUpdate('revoked_sessions', function ($revoked) use ($oldSid, $now, $_sessLife) {
            $revoked = is_array($revoked) ? $revoked : [];
            if (!isset($revoked[$oldSid])) $revoked['~' . $oldSid] = $now;   // 이미 일부러 끊긴 세션이면 그 기록을 그대로 둠
            foreach ($revoked as $sid => $ts) { if (!is_int($ts) || $now - (int)$ts > $_sessLife) unset($revoked[$sid]); }
            return $revoked;
        });
        // ★ (2026-11-09 10:23, 재검토 A-1) 표시를 먼저 하고 행을 지움 — 동시에 같은 세션을 기록하던 요청(recordSession)이 표시를 보고 행을 다시 넣지 않게
        $this->removeSession($userId, $oldSid);
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
            // 종료일이 지났으면 아직 로그인 가능(다음 로그인 때 자동 활성화됨). 그 외 정지는 끊음
            // ★ (2026-11-02 11:50, 43차 점검) 공통 판정(fsUserSuspendedNow)으로 — 시작일 전(예약된 정지)도 유지(F5#3)
            if (fsUserSuspendedNow($u)) return false;
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
        
        $found = false;
        $prefix = rtrim($sessionIdPrefix, '.');
        // ★ (2026-11-01 07:46, 37차 기능 점검) 빈 값('', '...')이면 strpos(x,'')===0 이라 내 세션 중 첫 번째(현재 세션일 수도)를 끊었음 — 찾을 수 없음으로 처리
        if ($prefix === '') {
            return ['success' => false, 'error' => __('api_err_session_not_found', '세션을 찾을 수 없습니다.')];
        }

        $revokedId = '';
        // ★ (2026-11-01 07:23, 37차 기능 점검) 잠금 안에서 찾아 지움(종전 load→save 는 동시 요청의 recordSession 저장에 덮여 끊은 행이 목록에 되살아났음)
        $this->db->atomicUpdate('sessions', function ($sessions) use ($userId, $prefix, &$revokedId, &$found) {
        $sessions = is_array($sessions) ? $sessions : [];
        foreach ($sessions as $key => $s) {
            if (($s['user_id'] ?? 0) === $userId && strpos($s['session_id'] ?? '', $prefix) === 0) {
                $revokedId = $s['session_id'] ?? '';
                unset($sessions[$key]);
                $found = true;
                break;
            }
        }
        return array_values($sessions);
        });

        if (!$found) {
            return ['success' => false, 'error' => __('api_err_session_not_found', '세션을 찾을 수 없습니다.')];
        }

        if ($revokedId !== '') {
            $this->revokeSessionIds([$revokedId]);   // ★ (2026-10-29 03:11, 16차) 기록만 지우지 않고 실제로 끊음
            $this->deleteRememberTokensForSessions([$revokedId]);   // ★ (2026-11-01 07:23, 37차 기능 점검) 그 세션의 로그인 유지 토큰도 지움(종전엔 세션 쿠키 없이 토큰만으로 바로 다시 들어왔음)
        }
        return ['success' => true];
    }
    
    public function terminateAllOtherSessions(): array {
        $userId = $this->getUserId();
        if (!$userId) return ['success' => false, 'error' => __('api_login_required')];
        
        $currentSessionId = session_id();
        // ★ (2026-10-29 03:11, 16차) 끊는 세션 ID 를 모아 실제로 끊음(종전엔 기록만 지워 계속 로그인됐음)
        $revokeIds = [];
        // ★ (2026-11-01 07:23, 37차 기능 점검) 잠금 안에서 읽고 지움(종전 load→save 는 동시 요청 저장에 덮여 끊은 행이 되살아났음)
        $this->db->atomicUpdate('sessions', function ($sessions) use ($userId, $currentSessionId, &$revokeIds) {
        $sessions = is_array($sessions) ? $sessions : [];
        foreach ($sessions as $s) {
            if (($s['user_id'] ?? 0) === $userId && ($s['session_id'] ?? '') !== $currentSessionId) $revokeIds[] = $s['session_id'] ?? '';
        }
        $sessions = array_filter($sessions, fn($s) => !(($s['user_id'] ?? 0) === $userId && ($s['session_id'] ?? '') !== $currentSessionId));
        return array_values($sessions);
        });
        $this->revokeSessionIds($revokeIds);
        // ★ (2026-11-01 07:23, 37차 기능 점검) 지금 기기(쿠키) 토큰만 남기고 이 사용자의 로그인 유지 토큰을 모두 지움 — 종전엔 세션 ID 만 끊어, 세션 쿠키를 지우거나 하루 넘게 쉬어
        //   세션 목록에서 빠진 기기가 토큰으로 바로 다시 들어왔음(재현). sid 없는 옛 토큰도 여기서 지워짐
        $curTok = is_string($_COOKIE['remember_token'] ?? null) ? hash('sha256', $_COOKIE['remember_token']) : null;   // ★ (2026-11-01 17:18, 40차 점검) 배열 쿠키면 (string) 변환 경고가 예외(500)가 됐음
        $this->deleteRememberTokensForUserExcept((int)$userId, $curTok, true);   // ★ (2026-11-09 19:42, 60차 Z14) 지운 토큰의 세션도 끊음(위 비밀번호 변경과 같음)

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
            // 국가 코드 가져오기
            $country = '';
            try {
                $country = $this->getCountryFromIP($ip);
            } catch (Exception $e) {
                $country = '';
            }
            
            // ★ (2026-11-01 17:18, 40차 점검) 국가 조회(최대 수 초)를 먼저 끝내고 잠금 안에서 최신 표에 한 줄만 붙임 — 종전엔 조회 전에 읽은 표에 붙여 통째로 저장해
            //   그 몇 초 사이의 다른 로그인·실패 기록이 사라지고 관리자가 지운 기록이 되살아났음(재현: 동시 20건 → 1건). id 형식(log_…)은 그대로
            $row = [
                'id' => uniqid('log_'),
                'username' => $username,
                'success' => $success,
                'ip' => $ip,
                'country' => $country,
                'reason' => $reason,
                'user_agent' => mb_strcut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512, 'UTF-8'),   // ★ (2026-11-04 01:38, 54차 점검) 512바이트에서 자름(활동 기록과 같은 상한, 화면은 표시만) — 종전엔 상한이 없어 로그인 실패마다 8KB UA 행이 쌓여 login_logs 가 80MB 까지 커질 수 있었음(재현: 8020바이트 그대로 저장)
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $this->db->atomicUpdate('login_logs', function ($logs) use ($row) {
                $logs = is_array($logs) ? $logs : [];
                $logs[] = $row;
                // 최대 10,000건 제한 (로그 파일 무한 성장 방지)
                if (count($logs) > 10000) {
                    $logs = array_slice($logs, -10000);
                }
                return array_values($logs);
            });
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
        
        // ★ (2026-11-01 17:18, 40차 점검) 느슨한 in_array 라 ids:[true] 하나가 모든 기록과 같다고 판정돼 전체가 지워졌음 → 문자열·정수 id 만 글자로 엄격 비교.
        //   또 잠금 안에서 최신 표를 거름(종전 load→save 는 그사이 들어온 로그인 기록을 덮었음)
        $idSet = array_map('strval', array_filter($ids, fn($x) => is_string($x) || is_int($x)));
        $this->db->atomicUpdate('login_logs', function ($logs) use ($idSet) {
            return array_values(array_filter(is_array($logs) ? $logs : [], fn($l) => !in_array((string)($l['id'] ?? ''), $idSet, true)));
        });
        
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
        
        // ★ (2026-11-01 17:18, 40차 점검) 잠금 안에서 최신 표를 거름 — 종전 load→save 는 그사이 들어온 로그인 기록을 덮었음
        $cutoff = strtotime("-{$days} days");
        $deleted = 0;
        $this->db->atomicUpdate('login_logs', function ($logs) use ($cutoff, &$deleted) {
            $logs = is_array($logs) ? $logs : [];
            $before = count($logs);
            $logs = array_values(array_filter($logs, fn($l) => strtotime($l['created_at'] ?? '0') > $cutoff));
            $deleted = $before - count($logs);
            return $logs;
        });
        
        return ['success' => true, 'deleted' => $deleted];
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
        // ★ (2026-11-15 10:10, 174차) 같은 초면 나중에 기록된 것(저장 순서가 뒤)이 위로 — 종전엔 같은 초 안에서 오래된 것이 위였음(재현: 실패→성공 순으로 로그인하면 실패가 위). 시각이 다르면 종전과 같은 순서
        $logs = array_values($logs);
        $__llTs = array_map(fn($r) => strtotime($r['created_at'] ?? '0'), $logs);
        $__llIx = array_keys($logs);
        usort($__llIx, fn($x, $y) => ($__llTs[$y] - $__llTs[$x]) ?: ($y <=> $x));
        $logs = array_map(fn($i) => $logs[$i], $__llIx);
        
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
        // ★ (2026-11-02 09:01, 41차 점검) totp_settings.json 이 깨져 이 요청의 암호화 키가 임시/기본 키면 시작부터 거부 — 켜기(enable2FA)에서 막히기 전에 OTP 앱에 등록하지 않게(D3#6·D8#2)
        if (($kErr = fsTotpKeyUnreliableError()) !== null) return ['success' => false, 'error' => $kErr];

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
    public function enable2FA(string $code, string $password = ''): array {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'error' => __('api_login_required')];
        }
        // ★ (2026-11-02 09:01, 41차 점검) 깨진 totp_settings.json(임시/기본 키) 상태면 2FA 비밀키를 암호화해 저장하지 않음 — 그 키로 저장하면 파일을 고친 뒤 풀 수 없어 로그인 불가(D3#6·D8#2)
        if (($kErr = fsTotpKeyUnreliableError()) !== null) return ['success' => false, 'error' => $kErr];

        $secret = $_SESSION['2fa_setup_secret'] ?? '';
        if (empty($secret)) {
            return ['success' => false, 'error' => __('api_2fa_setup_first', '2FA 설정을 먼저 시작하세요.')];
        }

        // ★ (2026-11-02 09:01, 41차 점검) 현재 비밀번호 확인(D3#4) — 종전엔 세션만 있으면 공격자가 자기 OTP 앱으로 2FA 를 켜 주인을 잠글 수 있었음(백업 코드도 공격자가 받음).
        //   로컬 비밀번호 해시가 없는 계정(SSO 로 만든 계정 '!SSO_NO_PASSWORD' 등 — password_get_info 로 판정)은 입력할 비밀번호가 없어 종전대로 확인 없이 진행
        $fullUser = $this->db->find('users', ['id' => $user['id']]);
        $pwHash = (string)($fullUser['password'] ?? '');
        if ($pwHash !== '' && $pwHash[0] !== '!') {   // ★ (2026-11-02 09:45, 41차 재검토) 위 login 과 같은 판정(옮겨 온 해시 계정이 확인을 건너뛰지 않게)
            $pwc = $this->checkCurrentPassword((int)$user['id'], $password, $pwHash);
            if ($pwc === null) return ['success' => false, 'error' => __('api_err_too_many_attempts', '시도가 너무 많습니다. 잠시 후 다시 시도하세요.')];
            if (!$pwc) return ['success' => false, 'error' => __('api_err_password_incorrect', '비밀번호가 올바르지 않습니다.')];
        }

        require_once __DIR__ . '/TOTP.php';
        
        // 코드 검증
        // ★ (2026-11-02 10:07, 42차 점검) 켜기에 쓴 코드도 '사용함'(totp_used)으로 기록 — 종전엔 기록하지 않아 같은 30초 안에 같은 코드로 백업 코드 재생성·로그인 2단계를
        //   다시 통과했음(41차 재사용 거부의 빈틈, 시나리오 시험 실측). 시간 칸 번호를 받아 아래 저장 뒤 기록
        $enCounter = TOTP::verifyCounter($secret, $code);
        if ($enCounter === null) {
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
        
        // ★ (2026-11-02 10:07, 42차 점검) 켜기에 쓴 시간 칸 기록(위 참고 — verifyTotpFresh 와 같은 방식, 더 큰 값만 남김)
        try {
            $enUid = (string)(int)$user['id'];
            $this->db->atomicUpdate('totp_used', function ($all) use ($enUid, $enCounter) {
                if (!is_array($all)) $all = [];
                if ((int)($all[$enUid] ?? -1) < $enCounter) $all[$enUid] = $enCounter;
                return $all;
            });
        } catch (\Throwable $e) {}
        // 세션 정리
        unset($_SESSION['2fa_setup_secret']);
        // ★ (2026-10-29 03:11, 16차) 2FA 를 켜면 기존 로그인 유지 토큰 폐기(그 토큰은 2FA 를 안 거치므로)
        // ★ (2026-11-09 14:01, 58차 G1-1) 16차 '모두 폐기'를 '이 기기(쿠키) 토큰만 남기고 폐기'로 바꿈 — 방금 코드·비밀번호로 2FA 를 켠 이 기기까지 세션 쿠키를 잃으면 다시 로그인해야 했음(재현). 다른 기기·훔친 쿠키 토큰은 종전대로 폐기('다른 세션 모두 종료' 37차와 같은 방식)
        $this->deleteRememberTokensForUserExcept((int)$user['id'], is_string($_COOKIE['remember_token'] ?? null) ? hash('sha256', $_COOKIE['remember_token']) : null);

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
        // ★ (2026-11-02 09:01, 41차 점검) 사용자별 시도 제한(checkCurrentPassword, D3#7)
        // ★ (2026-11-04 01:38, 54차 점검) 로컬 비밀번호가 없는 계정(SSO '!SSO_NO_PASSWORD' 등 — enable2FA·탈퇴의 41·43차 판정과 같은 기준)은 비밀번호 확인을 건너뛰고 아래 OTP(또는 백업 코드)만으로 —
        //   종전엔 password_verify('!…') 가 늘 거짓이라 켤 수는 있어도 끌 수 없었음(재현: 빈 비밀번호+맞는 OTP → '비밀번호가 올바르지 않습니다'). 비밀번호 있는 계정은 종전 그대로
        $dfHash = (string)($fullUser['password'] ?? '');
        if ($dfHash !== '' && $dfHash[0] !== '!') {
        $pwc = $this->checkCurrentPassword((int)$user['id'], $password, $dfHash);
        if ($pwc === null) return ['success' => false, 'error' => __('api_err_too_many_attempts', '시도가 너무 많습니다. 잠시 후 다시 시도하세요.')];
        if (!$pwc) {
            return ['success' => false, 'error' => __('api_err_password_incorrect', '비밀번호가 올바르지 않습니다.')];
        }
        }

        // 2FA 활성화 상태면 OTP 검증
        // ★ (2026-10-29 03:11, 16차) 코드를 반드시 검증 — 종전엔 '&& !empty($code)' 라 code 를 빈 값으로 보내면 OTP 검사를 건너뛰어
        //   비밀번호만으로 2FA 를 끌 수 있었음(재현). 백업 코드도 인정
        // ★ (2026-11-02 09:01, 41차 점검) verify2FA 와 같은 시도 제한(사용자별 15분 10회, 로그인 2단계와 같은 기록)과 같은 OTP 재사용 거부(totp_used)를 적용(D3#2) —
        //   종전엔 TOTP::verify 만 불러 IP당 분당 120회로 하루 약 40% 확률로 맞힐 수 있었음. 백업 코드는 종전대로 인정(쓰면 지워지는 1회용)
        if (!empty($user['2fa_enabled'])) {
            require_once __DIR__ . '/TOTP.php';
            $secret = $this->decrypt2FASecret($fullUser['2fa_secret'] ?? '');
            if ($secret === '' || $code === '') {
                return ['success' => false, 'error' => __('api_err_invalid_otp', '인증 코드가 올바르지 않습니다.')];
            }
            if (!$this->twoFaAttemptReserve((int)$user['id'])) {
                return ['success' => false, 'error' => __('api_err_too_many_attempts', '시도가 너무 많습니다. 잠시 후 다시 시도하세요.')];
            }
            if (!$this->verifyTotpFresh((int)$user['id'], $secret, $code) && !$this->verifyBackupCode($user['id'], $code)) {
                return ['success' => false, 'error' => __('api_err_invalid_otp', '인증 코드가 올바르지 않습니다.')];
            }
            $this->twoFaFailClear((int)$user['id']);
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
    // ★ (2026-11-02 09:01, 41차 점검) $kind 추가 — '2fa'(종전 파일 이름 그대로) 말고 'pwchk'(로그인한 세션의 현재 비밀번호 확인) 기록에도 같은 잠금 방식을 씀
    private function twoFaFailFile(int $uid, string $kind = '2fa'): string {
        $dir = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/rate_limits';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . '/' . $kind . '_' . $uid . '.json';
    }
    /** ★ (2026-10-28 20:42, 7차 점검) 잠금 안에서 15분 창의 시도 수를 세어 10회 미만이면 이번 시도를 기록하고 true, 아니면 false */
    private function twoFaAttemptReserve(int $uid, int $max = 10, int $window = 900, string $kind = '2fa'): bool {
        $fp = @fopen($this->twoFaFailFile($uid, $kind), 'c+');
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
    private function twoFaFailClear(int $uid, string $kind = '2fa'): void {
        $f = $this->twoFaFailFile($uid, $kind);
        if (!is_file($f)) return;
        $fp = @fopen($f, 'c+'); if (!$fp) return;
        if (flock($fp, LOCK_EX)) { ftruncate($fp, 0); fflush($fp); flock($fp, LOCK_UN); }
        fclose($fp);
    }

    /**
     * ★ (2026-11-02 09:01, 41차 점검) 로그인한 세션에서 현재 비밀번호 확인(비밀번호 변경·탈퇴·2FA 켜기/끄기·백업 코드 재생성) — 사용자별 시도 제한(D3#7).
     *   종전엔 IP당 분당 120회 공용 제한뿐이라, 탈취한 세션으로 비밀번호를 계속 대입해 알아낼 수 있었음.
     *   15분에 30회(네 곳 합산) — 사람이 잘못 치는 횟수로는 닿지 않게 넉넉히. 넘으면 null(호출부가 '시도가 너무 많습니다'), 맞으면 기록을 비움
     */
    private function checkCurrentPassword(int $uid, string $password, string $hash): ?bool {
        if (!$this->twoFaAttemptReserve($uid, 30, 900, 'pwchk')) return null;
        $ok = $hash !== '' && password_verify($password, $hash);
        if ($ok) $this->twoFaFailClear($uid, 'pwchk');
        return $ok;
    }

    /**
     * ★ (2026-11-02 09:01, 41차 점검) OTP 앱 코드 1회 확인 — verify2FA 와 같은 기준(이미 쓴 시간 칸 다시 쓰기 거부, totp_used 를 잠금 안에서 기록).
     *   2FA 끄기·백업 코드 재생성에서 씀(D3#1·#2). 시도 제한(twoFaAttemptReserve)은 호출부에서 먼저
     */
    private function verifyTotpFresh(int $uid, string $secret, string $code): bool {
        if ($secret === '' || $code === '') return false;
        require_once __DIR__ . '/TOTP.php';
        $counter = TOTP::verifyCounter($secret, $code);
        if ($counter === null) return false;
        $fresh = false; $tuid = (string)$uid;
        $this->db->atomicUpdate('totp_used', function ($all) use ($tuid, $counter, &$fresh) {
            if (!is_array($all)) $all = [];
            if ((int)($all[$tuid] ?? -1) < $counter) { $all[$tuid] = $counter; $fresh = true; }
            return $all;
        });
        return $fresh;
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
        // ★ (2026-11-02 10:29, 42차 점검) 바로 로그인해도 되는 SSO(LDAP·OIDC·SAML·Kerberos)는 부르는 쪽(api.php·index.php)이 이 뒤에 session_regenerate_id(데이터 유지) →
        //   $_SESSION['user_id'] 를 넣으므로, 이 설치의 로그인 표시(E5#3)를 여기서 넣어 둠(부르는 쪽 5곳을 고치지 않아도 isLoggedIn 이 통과). user_id 없이 표시만 있으면 로그인 아님
        $this->markSessionInstance();
        return ['success' => true];
    }

    /** ★ (2026-10-29 03:51, 17차) LDAP 로그인 시도 제한 — 일반 로그인과 같은 잠금(isLockedOut·recordFailedAttempt) 사용. 종전엔 횟수 제한이 없어 비밀번호 대입·AD 계정 잠금 유발이 가능했음 */
    //   ★ (2026-10-29 07:45, 18차) 잠금 기준 아이디는 앞뒤 공백 없앤 소문자 — AD 는 대소문자를 안 가려 'Admin'·'ADMIN' 으로 바꿔 가며 시도 횟수를 늘릴 수 있었음
    private function ssoLockKey(string $username): string {
        return 'ldap:' . mb_strtolower(trim($username), 'UTF-8');
    }
    // ★ (2026-11-01 07:26, 37차 기능 점검) LDAP 도 일반 로그인(37차)처럼 확인과 시도 기록을 한 잠금 안에서(예약) — 종전엔 동시에 보낸 시도가 잠금 확인을 함께 통과했음.
    //   실패면 예약한 1회가 그대로 남고(추가 기록 없음), 성공하면 아래 Succeeded 가 기록을 지움
    private $ssoReservedUndo = null;
    public function ssoAttemptAllowed(string $username): bool {
        $undo = null;
        $ok = $this->reserveLoginAttempt($this->ssoLockKey($username), $this->getClientIP(), $undo);
        $this->ssoReservedUndo = $ok ? ($undo ?? false) : null;
        return $ok;
    }
    public function ssoAttemptFailed(string $username): void {
        $ip = $this->getClientIP();
        if ($this->ssoReservedUndo === null) $this->recordFailedAttempt($this->ssoLockKey($username), $ip);   // 예약 없이 불린 경우만 종전처럼 기록
        $this->ssoReservedUndo = null;
        $this->logLogin($username, false, $ip, 'LDAP 인증 실패');
    }
    public function ssoAttemptSucceeded(string $username): void {
        $this->ssoReservedUndo = null;
        $this->clearFailedAttempts($this->ssoLockKey($username), $this->getClientIP());
    }
    /** ★ (2026-11-01 07:57, 37차 기능 점검 재검토) LDAP 서버 쪽 오류(연결·STARTTLS·서비스 계정·검색 실패 등 예외)로 판정을 못 한 시도는 예약을 되돌림 —
     *   예약 방식으로 바꾼 뒤엔 그 1회가 실패로 남아, LDAP 장애 중 5번 누르면 사용자가 잠겼음(종전엔 예외면 기록 없음). 이미 성공·실패로 끝났으면 아무것도 안 함 */
    public function ssoAttemptAborted(string $username): void {
        $undo = $this->ssoReservedUndo;
        $this->ssoReservedUndo = null;
        if (is_array($undo)) $this->releaseLoginAttempt($this->ssoLockKey($username), $this->getClientIP(), $undo);
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
        // ★ (2026-11-10 14:36, 64차) 1단계 뒤 비밀번호가 바뀌었으면(대기 값의 지문과 지금 해시 지문이 다름) 대기를 지우고 다시 로그인하게 — 종전엔 옛 비밀번호로 만든 대기가 변경 뒤에도 10분간 통해 OTP 만으로 로그인·토큰이 생겼음(재현). 지문이 없는 대기(SSO·업데이트 전 대기)는 종전대로
        if (isset($pendingUser['pw_fp'])) {
            $this->db->clearCache('users');
            $fpUser = $this->db->find('users', ['id' => $pendingUser['id'] ?? 0]);
            if ($fpUser && !hash_equals((string)$pendingUser['pw_fp'], self::pwFingerprint((string)($fpUser['password'] ?? '')))) {
                unset($_SESSION['2fa_pending_user']);
                return ['success' => false, 'error' => __('api_2fa_expired', '인증 시간이 지났습니다. 다시 로그인하세요.')];
            }
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
        // ★ (2026-10-31 13:19, 34차 후속 개선) 이미 쓴 시간 칸(같은 코드) 다시 쓰기 거부 — 종전엔 앞뒤 1칸(약 90초) 안에서 같은 코드가 여러 번 통과해,
        //   비밀번호를 아는 사람이 방금 입력한 코드를 엿보면 다른 기기에서 그대로 로그인할 수 있었음. 사용자별 마지막 칸은 잠금 안에서 기록
        $totpCounter = TOTP::verifyCounter($secret, $code);
        if ($totpCounter !== null) {
            $fresh = false; $tuid = (string)(int)$user['id'];
            $this->db->atomicUpdate('totp_used', function ($all) use ($tuid, $totpCounter, &$fresh) {
                if (!is_array($all)) $all = [];
                if ((int)($all[$tuid] ?? -1) < $totpCounter) { $all[$tuid] = $totpCounter; $fresh = true; }
                return $all;
            });
            if ($fresh) $isValid = true;
        }
        // 백업 코드 검증
        elseif ($this->verifyBackupCode($user['id'], $code)) {
            $isValid = true;
            $usedBackup = true;
        }
        
        if (!$isValid) {
            // (시도는 위 twoFaAttemptReserve 에서 이미 1회로 기록됨 — 사용자 단위)
            $this->logLogin((string)($user['username'] ?? ''), false, $this->getClientIP(), '2FA: ' . (($secret === '' && !empty($user['2fa_secret'])) ? __('api_err_2fa_key_mismatch', '2단계 인증 정보를 확인할 수 없습니다(서버 암호화 키 문제). 백업 코드로 로그인하거나 관리자에게 알리세요.') : __('api_err_invalid_otp', '인증 코드가 올바르지 않습니다.')));   // ★ (2026-11-11 23:35, 88차 개선 G1-4) 2FA 코드 실패도 비밀번호 실패처럼 로그인 기록에 한 줄 — 종전엔 어디에도 남지 않았음(재현: 틀린 코드 2번 → login_logs 0건). 기록만 함(시도 횟수·잠금 판정은 그대로, login_logs 는 화면·통계에만 쓰임) / ★ (2026-11-12 01:09, 89차 RGR8-1) 키 불일치면 그 이유로 기록(활동 기록과 같게)
            // ★ (2026-11-03 14:36, 펜닐 제보 — totp_settings.json 이 다른 파일로 바뀌어 OTP 로그인 불가) 저장된 비밀키를 지금 암호화 키로 풀지 못하면(키가 바뀜) 어떤 코드를 넣어도
            //   '코드가 올바르지 않습니다' 만 떠 원인을 알 수 없었음 → 서버 로그에 원인을 남기고 화면엔 백업 코드 안내(파일 경로는 화면에 안 보임). 시도 횟수 처리는 종전 그대로
            if ($secret === '' && !empty($user['2fa_secret'])) {
                error_log('[FileStation] 2FA secret of user #' . (int)$user['id'] . ' cannot be decrypted — data/totp_settings.json encryption_key differs from the one used at enrollment (restore the original file, or log in with a backup code)');
                return ['success' => false, 'error' => __('api_err_2fa_key_mismatch', '2단계 인증 정보를 확인할 수 없습니다(서버 암호화 키 문제). 백업 코드로 로그인하거나 관리자에게 알리세요.')];
            }
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
        $this->markSessionInstance();   // ★ (2026-11-02 10:29, 42차 점검) 2FA 통과(일반·SSO 모두)도 이 설치의 로그인 표시(E5#3)
        // ★ (2026-10-29 10:44, 20차 점검) 일반 로그인처럼 이름·역할도 — 종전엔 2FA 로 들어오면 비어 있어 사용자 삭제 기록의 '삭제한 사람' 이 'admin' 으로 남는 등 어긋났음
        $_SESSION['username'] = $user['username'] ?? '';
        $_SESSION['role'] = $user['role'] ?? 'user';
        $_SESSION['last_activity'] = time();
        self::$user = $user;
        // ★ (2026-11-14 22:09, 139차) 일반 로그인(login → recordSession)처럼 2FA 를 통과한 이 순간 세션 목록에 기록 — 종전엔 다음 요청의 isLoggedIn 에서야 기록돼,
        //   2FA 로그인 직후 다음 요청 전에 '다른 세션 모두 종료'·비밀번호 변경을 하면 그 세션이 목록에 없어 끊기지 않고 살아남았음(재현: 백업 코드·OTP 로그인 둘 다)
        $this->recordSession((int)$user['id'], $this->getClientIP());
        
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
        // ★ (2026-10-31 13:34, 34차 후속 개선) 2FA 로 로그인을 끝내도 일반 로그인처럼 ① 비밀번호 실패 기록을 지우고 ② '내 파일' 폴더를 보장
        //   — 종전엔 3번 틀린 뒤 맞혀 2FA 까지 통과해도 30분 안에 2번 더 틀리면 잠겼고, 2FA 사용자는 폴더 자동 생성을 건너뛰었음
        if (empty($pendingUser['sso_provider'])) $this->clearFailedAttempts((string)$user['username'], $this->getClientIP());
        if (defined('AUTO_CREATE_USER_FOLDER') && AUTO_CREATE_USER_FOLDER) $this->ensureUserFolder($user);
        
        return [
            'success' => true,
            'user' => $this->sanitizeUser($user),
            'used_backup' => $usedBackup,
            // ★ (2026-11-02 09:01, 41차 점검) 2FA 로그인을 마치면 CSRF 토큰도 새로(일반 로그인과 같게) — 종전엔 로그인 전 세션의 토큰이 그대로 남았음(D3#3).
            //   화면(app.js verify2FA)은 응답의 csrf_token 을 받아 바꿔 씀
            'csrf_token' => regenerateCsrfToken()
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
        
        $cleanCode = str_replace('-', '', $code);
        // ★ (2026-10-31 13:34, 34차 후속 개선) 확인과 '사용한 코드 지우기' 를 잠금 안에서 한 번에 — 종전엔 사이에 잠금이 없어 같은 백업 코드를 동시에 두 번 쓸 수 있었음
        // ★ (2026-10-31 14:09, 34차 후속 개선) 맞는 해시는 잠금 밖에서 먼저 찾고(틀린 코드마다 users 잠금 안에서 bcrypt 를 돌려 다른 요청이 기다리던 것), 맞을 때만 잠금 안에서 그 해시가 아직 있는지 확인 후 지움
        $matched = null;
        foreach ((array)$user['2fa_backup_codes'] as $hc) { if (is_string($hc) && password_verify($cleanCode, $hc)) { $matched = $hc; break; } }
        if ($matched === null) return false;
        $ok = false;
        $this->db->atomicUpdate('users', function ($users) use ($userId, $matched, &$ok) {
            foreach ($users as &$u) {
                if ((int)($u['id'] ?? 0) !== $userId) continue;
                $codes = is_array($u['2fa_backup_codes'] ?? null) ? $u['2fa_backup_codes'] : [];
                foreach ($codes as $index => $hashedCode) {
                    if ($hashedCode === $matched) {
                        unset($codes[$index]);   // 사용한 코드 제거
                        $u['2fa_backup_codes'] = array_values($codes);
                        $ok = true;
                        break;
                    }
                }
                break;
            }
            unset($u);
            return $users;
        });
        return $ok;
    }
    
    /**
     * 백업 코드 재생성
     */
    public function regenerateBackupCodes(string $password, string $code = ''): array {
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
        // ★ (2026-11-02 09:01, 41차 점검) 사용자별 시도 제한(checkCurrentPassword, D3#7)
        // ★ (2026-11-04 01:38, 54차 점검) 로컬 비밀번호가 없는 계정(SSO 등, enable2FA 와 같은 판정)은 비밀번호 확인을 건너뛰고 아래 OTP 앱 코드(필수)만으로 —
        //   종전엔 늘 '비밀번호가 올바르지 않습니다'라 백업 코드를 새로 받을 수 없었음(재현). 비밀번호 있는 계정은 종전 그대로
        $rgHash = (string)($fullUser['password'] ?? '');
        if ($rgHash !== '' && $rgHash[0] !== '!') {
        $pwc = $this->checkCurrentPassword((int)$user['id'], $password, $rgHash);
        if ($pwc === null) return ['success' => false, 'error' => __('api_err_too_many_attempts', '시도가 너무 많습니다. 잠시 후 다시 시도하세요.')];
        if (!$pwc) {
            return ['success' => false, 'error' => __('api_err_password_incorrect', '비밀번호가 올바르지 않습니다.')];
        }
        }

        if (empty($user['2fa_enabled'])) {
            return ['success' => false, 'error' => __('api_2fa_not_enabled', '2FA가 활성화되어 있지 않습니다.')];
        }

        require_once __DIR__ . '/TOTP.php';

        // ★ (2026-11-02 09:01, 41차 점검) 지금 OTP 앱 코드(6자리, 백업 코드 아님)도 확인(D3#1) — 종전엔 비밀번호만으로 새 백업 코드 10개를 받아
        //   그 코드로 2FA 를 끌 수 있어(disable2FA 가 백업 코드를 받음) 16차의 'OTP 필수'가 무력화됐음. verify2FA 와 같은 시도 제한·재사용 거부
        $secret = $this->decrypt2FASecret($fullUser['2fa_secret'] ?? '');
        if ($secret === '' || trim($code) === '') {
            return ['success' => false, 'error' => __('api_err_invalid_otp', '인증 코드가 올바르지 않습니다.')];
        }
        if (!$this->twoFaAttemptReserve((int)$user['id'])) {
            return ['success' => false, 'error' => __('api_err_too_many_attempts', '시도가 너무 많습니다. 잠시 후 다시 시도하세요.')];
        }
        if (!$this->verifyTotpFresh((int)$user['id'], $secret, trim($code))) {
            return ['success' => false, 'error' => __('api_err_invalid_otp', '인증 코드가 올바르지 않습니다.')];
        }
        $this->twoFaFailClear((int)$user['id']);
        
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
        // ★ (2026-11-02 10:28, 42차 점검) openssl 확장이 없으면 Error 로 2FA 로그인·끄기가 '서버 오류'였음(E5#10) → 못 푼 것과 같게 빈 값(TOTP::verifyCounter 가 빈 비밀키는 늘 거부, 백업 코드는 그대로 씀) + 로그
        if (!function_exists('openssl_decrypt')) { error_log('[FileStation] openssl extension missing — cannot decrypt 2FA secret'); return ''; }

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
        unset($user['app_passwords']);   // ★ (2026-11-01 07:21, 37차 기능 점검) 앱 비밀번호 해시도 응답에서 뺌(login·2fa_verify)
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
        // ★ (2026-11-02 11:52, 43차 점검) 같은 메일은 10분에 한 번만 발송(비밀번호 찾기의 password_reset_rl 과 같은 방식, F5#8) — 종전엔 요청마다 보내
        //   비로그인 요청으로 남의 메일함에 계속 보내고 서버 SMTP 발송 한도를 소모할 수 있었음. 응답은 종전처럼 늘 같음
        $fuOk = false; $fuKey = hash('sha256', strtolower(trim($email)));
        $this->db->atomicUpdate('find_username_rl', function ($all) use ($fuKey, &$fuOk) {
            if (!is_array($all)) $all = [];
            $now = time();
            foreach ($all as $k => $ts) { if ($now - (int)$ts > 3600) unset($all[$k]); }
            if ($now - (int)($all[$fuKey] ?? 0) >= 600) { $all[$fuKey] = $now; $fuOk = true; }
            return $all;
        });
        if (!$fuOk) return ['success' => true, 'message' => __('email_id_sent_if_exists')];

        // 이메일 발송
        $siteName = function_exists('fsSiteName') ? fsSiteName() : (defined('SITE_NAME') ? SITE_NAME : __('site_name_default'));   // ★ (2026-11-02 11:51, 43차 점검) 화면의 '사이트 이름' 설정 사용, 없으면 종전 값(F2#7)
        $subject = __f('email_find_id_subject', ['siteName' => $siteName]);
        $message = __('email_find_id_greeting');
        $message .= __('email_find_id_body');
        
        foreach ($foundUsers as $user) {
            $username = $user['username'];
            $displayName = $user['display_name'] ?? '';
            // 아이디 일부 마스킹 (앞 2자 + ***) 
            $maskedId = mb_substr($username, 0, 2) . str_repeat('*', max(1, mb_strlen($username) - 2));
            $message .= __f('email_find_id_item', ['maskedId' => $maskedId]);
            if ($displayName !== '') {   // ★ (2026-11-12 16:24, 100차 NTF-3) 빈 문자열 판정으로 — 종전엔 표시 이름 '0' 이 거짓으로 취급돼 메일에서 빠졌음(재현)
                $message .= __f('email_find_id_displayname', ['displayName' => $displayName]);
            }
            $message .= "\n";
        }
        
        $message .= __('email_find_id_contact_admin');
        $message .= __('email_ignore_if_not_you');
        
        $sent = $this->sendEmail($email, $subject, $message);
        // ★ (2026-11-02 11:52, 43차 점검) 메일이 안 나갔으면 간격 기록을 지움(SMTP 설정을 고친 뒤 바로 다시 받을 수 있게 — 비밀번호 찾기와 같음)
        if (!$sent) $this->db->atomicUpdate('find_username_rl', function ($all) use ($fuKey) { if (is_array($all)) unset($all[$fuKey]); return is_array($all) ? $all : []; });

        // 보안: 성공/실패 무관하게 동일 메시지
        return ['success' => true, 'message' => __('email_id_sent_if_exists')];
    }
    
    public function requestPasswordReset(string $username, string $email): array {
        // ★ (2026-10-31 13:19, 34차 후속 개선) 아이디가 없거나·메일이 없거나·안 맞아도 같은 응답 — 종전엔 응답이 셋으로 갈려 아이디 존재·아이디+메일 조합을
        //   추측해 확인할 수 있었음(아이디 찾기는 이미 늘 같은 응답). 같은 계정은 10분에 한 번만 — 반복 요청으로 남의 비밀번호를
        //   계속 바꿔 본인이 로그인 못 하게 만들던 것을 막음
        $generic = ['success' => true, 'message' => __('api_pw_reset_generic', '입력한 아이디와 이메일이 맞으면 임시 비밀번호를 이메일로 보냈습니다.')];
        // 사용자 찾기
        $user = $this->db->find('users', ['username' => $username]);
        if (!$user) return $generic;
        
        // 이메일 확인
        $userEmail = $user['email'] ?? '';
        if (empty($userEmail) || strtolower($userEmail) !== strtolower($email)) return $generic;
        // ★ (2026-11-02 11:52, 43차 점검) 로컬 비밀번호가 없는 계정(SSO 로 만든 '!SSO_NO_PASSWORD' 등 — login 의 41차 '비밀번호 없음' 판정과 같은 기준)은 임시 비밀번호를 만들지 않음(같은 응답) —
        //   종전엔 여기서 로컬 비밀번호가 생겨, IdP 에서 막힌(퇴사·MFA) 사람도 메일만 받으면 로컬 로그인으로 들어올 수 있었음(F5#4)
        $curPw = (string)($user['password'] ?? '');
        if ($curPw === '' || $curPw[0] === '!') return $generic;
        // ★ (2026-11-14 12:31, 127차 펜닐 결정 '정지·대기 계정은 보내지 않기') 로그인할 수 없는 계정(정지 기간 안·승인 대기 등 active 가 아닌 상태·비활성)은
        //   임시 비밀번호 메일을 보내지 않고 비밀번호도 바꾸지 않음 — 종전엔 메일이 가고 비밀번호가 바뀌었음(로그인은 여전히 막힘, 재현).
        //   응답은 다른 경우와 같은 $generic(계정이 있는지·상태를 드러내지 않음). 기준은 WebDAV davAccountUsable 과 같음 — 시작일 전(예약된 정지)은 보냄
        $rsStatus = (string)($user['status'] ?? 'active');
        if ($rsStatus === 'suspended') { if (function_exists('fsUserSuspendedNow') && fsUserSuspendedNow($user)) return $generic; }
        elseif ($rsStatus !== 'active') return $generic;
        if (!($user['is_active'] ?? 1)) return $generic;

        // 같은 계정 10분 간격(잠금 안에서 확인·기록)
        $rlOk = false; $rlUid = (string)(int)$user['id'];
        $this->db->atomicUpdate('password_reset_rl', function ($all) use ($rlUid, &$rlOk) {
            if (!is_array($all)) $all = [];
            $now = time();
            foreach ($all as $k => $ts) { if ($now - (int)$ts > 3600) unset($all[$k]); }
            if ($now - (int)($all[$rlUid] ?? 0) >= 600) { $all[$rlUid] = $now; $rlOk = true; }
            return $all;
        });
        if (!$rlOk) return $generic;
        
        // 임시 비밀번호 생성 (8자리)
        $tempPassword = $this->generateTempPassword();

        // ★ (2026-10-29 03:11, 16차) 메일을 먼저 보내고, 성공했을 때만 비밀번호를 바꿈 — 종전엔 비밀번호부터 바꾸고 메일을 보내,
        //   SMTP 미설정 등으로 메일이 실패하면 임시 비밀번호를 아무도 모르는 채 계정이 잠겼음(재현). 메일 실패 시 기존 비밀번호 유지
        $siteName = function_exists('fsSiteName') ? fsSiteName() : (defined('SITE_NAME') ? SITE_NAME : __('site_name_default'));   // ★ (2026-11-02 11:51, 43차 점검) 화면의 '사이트 이름' 설정 사용, 없으면 종전 값(F2#7)
        $subject = __f('email_temp_pw_subject', ['siteName' => $siteName]);
        $message = __f('email_temp_pw_greeting', ['displayName' => $user['display_name']]);
        $message .= __('email_temp_pw_body');
        $message .= __f('email_temp_pw_id', ['username' => $username]);
        $message .= __f('email_temp_pw_value', ['password' => $tempPassword]);
        $message .= __('email_temp_pw_change');
        $message .= __('email_temp_pw_pending', "임시 비밀번호는 24시간 동안 쓸 수 있습니다. 임시 비밀번호로 로그인하기 전까지는 기존 비밀번호도 그대로 쓸 수 있습니다.\n\n");   // ★ (2026-11-15 01:31, 153차) 대기 방식 안내
        $message .= __('email_ignore_if_not_you');

        $sent = $this->sendEmail($email, $subject, $message);

        if (!$sent) {
            // 메일이 안 나가면 다음 시도를 막지 않도록 간격 기록을 지움(관리자 SMTP 설정 문제)
            $this->db->atomicUpdate('password_reset_rl', function ($all) use ($rlUid) { if (is_array($all)) unset($all[$rlUid]); return is_array($all) ? $all : []; });
            // ★ (2026-10-31 14:09, 34차 후속 개선) 실패도 같은 응답(따로 오류를 돌려주면 SMTP 장애 때 아이디+메일 조합이 맞는지 드러났음). 관리자는 서버 오류 로그로 확인
            error_log('[FileStation] password reset mail failed for user id ' . (int)$user['id']);
            return $generic;
        }

        // ★ (2026-11-15 01:31, 153차 펜닐 요청 '안 고친 것 안전하게 개선') 메일이 나가도 비밀번호를 바로 바꾸지 않고 '대기'로만 둠(24시간) —
        //   종전엔 아이디+메일을 아는 남이 요청만 해도 본인 비밀번호가 바로 바뀌고 로그인 세션이 끊겼음(재현: 요청 직후 기존 비밀번호·로그인 세션 모두 실패).
        //   임시 비밀번호로 웹 로그인에 성공하는 순간 진짜 비밀번호가 되고(login → promotePendingReset — 종전 바로 바꾸던 코드를 그대로 옮김) 그때 다른 세션·로그인 유지를 끊음.
        //   그 전에는 기존 비밀번호도 그대로 됨. 다시 요청하면 새 임시 비밀번호로 바뀜(같은 계정 10분 간격은 위 그대로)
        $tmpHash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $prUid = (string)(int)$user['id'];
        $this->db->atomicUpdate('password_reset_pending', function ($all) use ($prUid, $tmpHash) {
            if (!is_array($all)) $all = [];
            $now = time();
            foreach ($all as $k => $r) { if (!is_array($r) || (int)($r['exp'] ?? 0) < $now) unset($all[$k]); }
            $all[$prUid] = ['hash' => $tmpHash, 'exp' => $now + self::PW_RESET_PENDING_SEC];
            return $all;
        });

        return $generic;
    }

    /** ★ (2026-11-15 01:31, 153차) 비밀번호 찾기 대기 임시 비밀번호 — 대기 표(password_reset_pending: { "<사용자 번호>": {hash, exp} })에서 아직 유효한 행 */
    private function pendingResetGet(int $uid): ?array {
        $all = $this->db->load('password_reset_pending');
        $r = (is_array($all) && isset($all[(string)$uid]) && is_array($all[(string)$uid])) ? $all[(string)$uid] : null;
        if (!$r || (int)($r['exp'] ?? 0) < time() || !is_string($r['hash'] ?? null) || $r['hash'] === '') return null;
        return $r;
    }

    /** ★ (2026-11-15 01:31, 153차) 대기 임시 비밀번호 지우기 — 임시 비밀번호를 쓴 뒤·비밀번호를 직접 바꾼 뒤·관리자가 바꾼 뒤·기존 비밀번호로 로그인한 뒤 */
    public function clearPendingReset(int $uid): void {
        $all = $this->db->load('password_reset_pending');
        if (!is_array($all) || !array_key_exists((string)$uid, $all)) return;   // 행이 없으면 쓰지 않음(로그인마다 파일을 다시 쓰지 않게)
        $this->db->atomicUpdate('password_reset_pending', function ($all) use ($uid) { if (is_array($all)) unset($all[(string)$uid]); return is_array($all) ? $all : []; });
    }

    /** ★ (2026-11-15 01:31, 153차) 대기 임시 비밀번호로 로그인했을 때 — 종전 requestPasswordReset 이 메일 직후 하던 일을 그대로(비밀번호 교체·변경 안내 표시·로그인 유지 토큰과 세션 끊기) */
    private function promotePendingReset(int $uid, string $tmpHash): void {
        $this->db->atomicUpdate('users', function ($users) use ($uid, $tmpHash) {
            foreach ($users as &$u) {
                if ((int)($u['id'] ?? 0) === $uid) {
                    $u['password'] = $tmpHash;
                    $u['password_reset_required'] = true; // 로그인 후 변경 필요 표시
                    $u['updated_at'] = date('Y-m-d H:i:s');
                    break;
                }
            }
            unset($u);
            return $users;
        });
        $this->deleteRememberTokensForUserExcept($uid, null, true);   // ★ (2026-11-10 07:21, 61차 B) 토큰 모두 지우고 그 토큰의 세션도 끊음(60차 Z14 ② — 같은 순간 토큰 복구한 세션이 남던 것 재현)
        // ★ (2026-11-01 01:08, 35차 전체 점검) 기존 로그인 세션도 모두 끊음 — 비밀번호 변경·관리자 변경(34차 후속)과 같게. 비로그인 요청이라 남길 세션 없음
        $this->revokeUserSessions($uid, null);
        $this->clearPendingReset($uid);
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
        // ★ (2026-11-02 11:53, 43차 점검) 발신 도메인은 설정의 외부 접속 주소(external_url) 호스트를 먼저 씀 — 종전엔 늘 요청 Host 머리글이라 비로그인 비밀번호 찾기 요청이
        //   Host 를 바꾸면 임시 비밀번호 메일의 발신 주소 도메인이 그 값이 됐음(F5#9). external_url 이 없으면 종전대로 Host
        $extHost = is_string($settings['external_url'] ?? null) ? (string)parse_url(trim($settings['external_url']), PHP_URL_HOST) : '';
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $extHost !== '' ? $extHost : ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $from = !empty($smtp['from']) ? $smtp['from'] : 'noreply@' . $host;
        $headers = "From: {$from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";   // ★ (2026-11-02 11:53, 43차 점검) MIME 머리글 추가(F5#9)
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        // ★ (2026-11-02 11:53, 43차 점검) 한국어 제목을 RFC 2047 로 인코딩(SMTP 경로와 같게) — 종전엔 8비트 그대로 나가 받는 쪽에서 깨질 수 있었음(F5#9)
        if (preg_match('/[^\x20-\x7E]/', $subject)) $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

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
        // ★ (2026-11-02 11:51, 43차 점검) 보내는 주소가 빈 문자열이어도 SMTP 사용자명 사용(화면 안내대로) — 저장값이 '' 라 ?? 대체가 안 돼 MAIL FROM:<>·빈 From 으로 나갔음(F2#2·F5#7)
        $from = trim((string)($smtp['from'] ?? '')) !== '' ? trim((string)$smtp['from']) : $user;
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
            // ★ (2026-11-01 01:08, 35차 전체 점검) 주소의 CR/LF 제거(api.php sendSmtpEmail 과 같게) — SMTP 명령·머리글이 끼어들지 않게
            $to = str_replace(["\r", "\n"], '', $to);
            $from = str_replace(["\r", "\n"], '', $from);
            $fromHeader = $fromName ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>" : $from;
            
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
            // ★ (2026-11-11 23:32, 88차 개선 G2-4) Date·Message-ID 머리글(RFC 5322, api.php sendSmtpEmail 과 같게) — 종전엔 둘 다 없었음(가짜 SMTP 로 받은 원문 재현). 도메인은 보내는 주소의 것(없으면 localhost)
            $data .= "Date: " . date('r') . "\r\n";
            $data .= "Message-ID: <" . bin2hex(random_bytes(16)) . "@" . ((string)preg_replace('/[^a-zA-Z0-9.\-]/', '', (string)substr((string)strrchr($from, '@'), 1)) ?: 'localhost') . ">\r\n";
            $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $data .= "\r\n";
            // ★ (2026-11-01 01:08, 35차 전체 점검) 줄바꿈을 CRLF 로 맞추고 점으로 시작하는 줄은 점을 하나 더(dot-stuffing, api.php 와 같게) — 종전엔 본문(표시 이름 등)에 '\r\n.\r\n' 이 있으면 거기서 메일이 끝나고 나머지가 SMTP 명령으로 들어갔음
            $body = preg_replace("/\r\n|\r|\n/", "\r\n", (string)$message);
            if (strpos($body, '.') === 0) $body = '.' . $body;
            $body = str_replace("\r\n.", "\r\n..", $body);
            $data .= $body . "\r\n";
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
        // ★ (2026-11-03 13:58, 51차 점검) 오늘 포함 7일·30일 — 종전 -7·-30 일에 >= 비교라 8일·31일치를 셌음(아래 일별 30칸은 -29일부터라 이것과 맞춤)
        $sevenDaysAgo = (clone $now)->modify('-6 days')->format('Y-m-d');
        $thirtyDaysAgo = (clone $now)->modify('-29 days')->format('Y-m-d');
        
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
            $month = (clone $now)->modify('first day of this month')->modify("-$i months")->format('Y-m');   // ★ (2026-11-09 19:42, 60차 Z14) 그달 1일 기준으로 개월을 뺌 — 종전엔 29~31일에 '-N months' 가 다음 달로 넘어가 같은 달이 겹치고 몇 달이 표에서 빠졌음(재현: 12-31 → 서로 다른 달 7개)
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
            // ★ (2026-11-03 13:58, 51차 점검) 로그인 실패는 '접속' 집계에서 뺌 — 종전엔 틀린 비밀번호·없는 아이디(someone 등)까지 접속 수·사용자별 목록·'N명' 에 들어갔음(재현).
            //   아래 '최근 로그인' 표(recent_logins)는 성공·실패를 함께 보여 주는 목록이라 종전 그대로
            if (empty($log['success'])) continue;
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
        // ★ (2026-11-15 10:10, 174차) 같은 초면 나중에 기록된 것(저장 순서가 뒤)이 위로 — 종전엔 같은 초 안에서 오래된 것이 위였음(재현: 실패→성공 순으로 로그인하면 실패가 위). 시각이 다르면 종전과 같은 순서
        $logs = array_values($logs);
        $__rlTs = array_map(fn($r) => strtotime($r['created_at'] ?? '0'), $logs);
        $__rlIx = array_keys($logs);
        usort($__rlIx, fn($x, $y) => ($__rlTs[$y] - $__rlTs[$x]) ?: ($y <=> $x));
        $logs = array_map(fn($i) => $logs[$i], $__rlIx);
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
