<?php
/**
 * 활동 로그 관리
 * 업로드, 다운로드, 삭제, 공유 등 모든 활동 기록
 */

class ActivityLog {
    private $db;
    private $auth;
    
    // 로그 타입 상수
    const TYPE_UPLOAD = 'upload';
    const TYPE_DOWNLOAD = 'download';
    const TYPE_DELETE = 'delete';
    const TYPE_CREATE_FOLDER = 'create_folder';
    const TYPE_RENAME = 'rename';
    const TYPE_MOVE = 'move';
    const TYPE_COPY = 'copy';
    const TYPE_SHARE_CREATE = 'share_create';
    const TYPE_SHARE_DELETE = 'share_delete';
    const TYPE_SHARE_ACCESS = 'share_access';
    const TYPE_EXTRACT = 'extract';
    const TYPE_COMPRESS = 'compress';
    const TYPE_RESTORE = 'restore';
    const TYPE_LOGIN = 'login';
    const TYPE_LOGOUT = 'logout';
    const TYPE_LOGIN_FAIL = 'login_fail';
    const TYPE_HACK_ATTEMPT = 'hack_attempt';
    const TYPE_EXIF_STRIP = 'exif_strip';   // ★ (2026-10-07) 사진 EXIF 개인정보 삭제
    
    public function __construct($db, $auth) {
        $this->db = $db;
        $this->auth = $auth;
    }
    
    /**
     * 로그 기록
     * $data에 user_id, username, display_name이 있으면 우선 사용 (로그인 시점 등)
     */
    public function log(string $type, array $data = []): int {
        // ★ (2026-10-29 22:39, 24차 점검) 사용자 정보를 다 넘겨받으면 getUser() 를 부르지 않음 — getUser→isLoggedIn 이 만료 세션을 지우며(session_destroy)
        //   share.php 에서 방금 저장한 공유 비밀번호 인증까지 지웠음(2회차 재검토)
        $user = (isset($data['user_id'], $data['username'], $data['display_name'])) ? null : $this->auth->getUser();
        
        $logEntry = [
            'type' => $type,
            'user_id' => $data['user_id'] ?? $user['id'] ?? 0,
            'username' => $data['username'] ?? $user['username'] ?? 'guest',
            'display_name' => $data['display_name'] ?? $user['display_name'] ?? 'Guest',
            'storage_id' => $data['storage_id'] ?? null,
            'storage_name' => $data['storage_name'] ?? null,
            'path' => $data['path'] ?? null,
            'filename' => $data['filename'] ?? null,
            'size' => $data['size'] ?? null,
            'details' => $data['details'] ?? null,
            'ip' => (empty($_SERVER['REMOTE_ADDR']) || !is_object($this->auth) || !method_exists($this->auth, 'getCurrentIP')) ? ($_SERVER['REMOTE_ADDR'] ?? '') : $this->auth->getCurrentIP(),   // ★ (2026-10-29 22:09, 24차 점검) 로그인 기록과 같은 신뢰 프록시 규칙(역방향 프록시 뒤에서 모두 프록시 주소로 남던 것). 22:30 — 백그라운드 작업(CLI, REMOTE_ADDR 없음)은 종전처럼 빈 값(127.0.0.1 로 바뀌지 않게)
            'user_agent' => mb_strcut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512, 'UTF-8'),   // ★ (2026-11-04 01:38, 54차 점검) 512바이트에서 자름(화면은 보여 주기만 함, 보통 UA 는 그대로) — 종전엔 상한이 없어 비로그인 로그인 실패만으로 8KB UA 행이 쌓여 1만 행이 80MB 가 되고 모든 기록 추가가 건당 수 초 걸렸음(재현: 8020바이트 그대로 저장)
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return $this->db->insert('activity_logs', $logEntry, 10000);
    }
    
    /**
     * 로그 목록 조회
     */
    public function getLogs(array $filters = [], int $page = 1, int $limit = 50): array {
        $logs = $this->db->load('activity_logs');
        
        // 최신순 정렬
        // ★ (2026-11-02 17:37, 46차 점검) 비교마다 strtotime 을 두 번 불러 1만 행 정렬이 창 열 때마다 0.3~0.5초(H1#5) → 항목마다 한 번 계산해 번호 배열을 같은 비교식으로 정렬(안정 정렬, 결과 같음)
        $logs = array_values($logs);
        $ts = array_map(fn($r) => strtotime($r['created_at'] ?? 0), $logs);
        $ix = array_keys($logs);
        usort($ix, function($a, $b) use ($ts) { return ($ts[$b] - $ts[$a]) ?: ($b <=> $a); });   // ★ (2026-11-15 10:10, 174차) 같은 초면 나중에 기록된 것(저장 순서가 뒤)이 위로 — 종전엔 같은 초 안에서 오래된 것이 위였음(재현: 같은 초에 0·1·2 를 올리면 0 이 맨 위). 시각이 다르면 종전과 같은 순서
        $logs = array_map(fn($i) => $logs[$i], $ix);
        
        // 필터 적용
        if (!empty($filters['user_id'])) {
            $logs = array_filter($logs, function($log) use ($filters) {
                return ($log['user_id'] ?? 0) == $filters['user_id'];
            });
        }
        
        if (!empty($filters['type'])) {
            $logs = array_filter($logs, function($log) use ($filters) {
                return ($log['type'] ?? '') === $filters['type'];
            });
        }
        
        if (!empty($filters['storage_id'])) {
            $logs = array_filter($logs, function($log) use ($filters) {
                return ($log['storage_id'] ?? 0) == $filters['storage_id'];
            });
        }
        
        if (!empty($filters['date_from'])) {
            $from = strtotime($filters['date_from']);
            $logs = array_filter($logs, function($log) use ($from) {
                return strtotime($log['created_at'] ?? 0) >= $from;
            });
        }
        
        if (!empty($filters['date_to'])) {
            $to = strtotime($filters['date_to'] . ' 23:59:59');
            $logs = array_filter($logs, function($log) use ($to) {
                return strtotime($log['created_at'] ?? 0) <= $to;
            });
        }
        
        // 최신 display_name 표 — ★ (2026-11-04, 55차 G6) 검색에도 쓰려고 거르기 전으로 옮김(아래 응답 갱신은 그대로 이 표를 씀)
        $users = $this->db->load('users') ?: [];
        $userMap = [];
        foreach ($users as $u) {
            if (!empty($u['id'])) $userMap[(int)$u['id']] = $u['display_name'] ?? $u['username'] ?? '';
            $uname = strtolower(trim($u['username'] ?? ''));
            if ($uname) $userMap['u:' . $uname] = $u['display_name'] ?? $u['username'] ?? '';
        }

        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            // ★ (2026-11-04, 55차 G6) 표에 보이는 지금 표시 이름으로도 찾게 함(종전엔 기록 때 옛 이름만 비교 → 이름 바꾼 사용자는 0건). 옛 이름 비교도 그대로
            $logs = array_filter($logs, function($log) use ($search, $userMap) {
                $uid = (int)($log['user_id'] ?? 0);
                $un = strtolower(trim($log['username'] ?? ''));
                if (!$uid && $un === 'guest' && ($log['type'] ?? '') !== self::TYPE_LOGIN_FAIL) $un = '';   // ★ (2026-11-11 23:27, 88차 개선 G1-1) 비로그인 기록(user_id 0·'guest')은 아이디로 사용자를 찾지 않음 — 종전엔 'guest' 계정이 있으면 해킹 시도·공유 접근이 그 사람 표시 이름으로 검색됐음(재현: search=손님계정 → hack_attempt). 로그인 실패(입력 아이디)는 종전 그대로
                $cur = ($uid && isset($userMap[$uid])) ? $userMap[$uid] : (($un !== '' && isset($userMap['u:' . $un])) ? $userMap['u:' . $un] : '');
                return strpos(strtolower($log['filename'] ?? ''), $search) !== false ||
                       strpos(strtolower($log['path'] ?? ''), $search) !== false ||
                       strpos(strtolower($log['username'] ?? ''), $search) !== false ||
                       strpos(strtolower($log['display_name'] ?? ''), $search) !== false ||
                       ($cur !== '' && strpos(strtolower((string)$cur), $search) !== false);
            });
        }
        
        $logs = array_values($logs);
        $total = count($logs);
        
        // 페이지네이션
        // ★ (2026-11-02 10:29, 42차 점검) 아주 큰 page 값이면 곱셈이 float 로 넘쳐 array_slice TypeError(500)였음(재현) → 끝을 넘는 쪽은 곱하지 않고 끝으로(빈 목록 — 종전과 같은 결과)
        $offset = ($page - 1 >= intdiv($total, max(1, $limit)) + 1) ? $total : ($page - 1) * $limit;
        $logs = array_slice($logs, $offset, $limit);
        
        // 최신 display_name으로 갱신($userMap 은 위에서 만듦)
        // ★ (2026-11-02 09:05, 41차 점검) 41차 전에 남은 공유 기록의 토큰 원문(공유 생성 = 토큰 그대로, 공유 접근 = 'Token: 원문')을 실제 관리자가 아니면 앞 6자 + '…' 로 가려 보냄 —
        //   'logins' 권한 부관리자가 남의 공유 링크(실제 관리자 홈 공유 포함)를 그대로 열 수 있었음. 저장된 기록은 그대로 두고 응답만 가림(새 기록은 api.php·share.php 가 처음부터 앞 6자만 남김)
        $alMask = !(is_object($this->auth) && method_exists($this->auth, 'isAdmin') && $this->auth->isAdmin());
        foreach ($logs as &$log) {
            $uid = (int)($log['user_id'] ?? 0);
            $uname = strtolower(trim($log['username'] ?? ''));
            if (!$uid && $uname === 'guest' && ($log['type'] ?? '') !== self::TYPE_LOGIN_FAIL) $uname = '';   // ★ (2026-11-11 23:27, 88차 개선 G1-1) 비로그인 기록은 저장된 'Guest' 그대로 보임 — 종전엔 'guest' 계정의 표시 이름(재현: hack_attempt 가 '손님계정')으로 덮었음. 로그인 실패는 종전 그대로
            if ($uid && isset($userMap[$uid])) {
                $log['display_name'] = $userMap[$uid];
            } elseif ($uname && isset($userMap['u:' . $uname])) {
                $log['display_name'] = $userMap['u:' . $uname];
            }
            if ($alMask && in_array($log['type'] ?? '', [self::TYPE_SHARE_CREATE, self::TYPE_SHARE_DELETE, self::TYPE_SHARE_ACCESS], true)
                && is_string($log['details'] ?? null) && preg_match('/^(?:Token: )?([0-9a-fA-F]{7,})(?![0-9a-zA-Z…])(.*)$/su', $log['details'], $alM)) {
                $log['details'] = 'Token: ' . substr($alM[1], 0, 6) . '…' . $alM[2];
            }
        }
        unset($log);
        
        return [
            'success' => true,
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ];
    }
    
    /**
     * 사용자별 통계
     */
    public function getUserStats(int $userId): array {
        $logs = $this->db->load('activity_logs');
        
        $userLogs = array_filter($logs, function($log) use ($userId) {
            return ($log['user_id'] ?? 0) == $userId;
        });
        
        $stats = [
            'total' => count($userLogs),
            'uploads' => 0,
            'downloads' => 0,
            'deletes' => 0,
            'shares' => 0,
            'total_upload_size' => 0,
            'total_download_size' => 0
        ];
        
        foreach ($userLogs as $log) {
            switch ($log['type'] ?? '') {
                case self::TYPE_UPLOAD:
                    $stats['uploads']++;
                    $stats['total_upload_size'] += $log['size'] ?? 0;
                    break;
                case self::TYPE_DOWNLOAD:
                    $stats['downloads']++;
                    $stats['total_download_size'] += $log['size'] ?? 0;
                    break;
                case self::TYPE_DELETE:
                    $stats['deletes']++;
                    break;
                case self::TYPE_SHARE_CREATE:
                    $stats['shares']++;
                    break;
            }
        }
        
        return $stats;
    }
    
    /**
     * 로그 삭제 (관리자)
     */
    public function clearLogs(?string $beforeDate = null): array {
        // ★ (2026-11-01 09:47, 38차 점검) 읽기→걸러내기→저장을 잠금 하나(atomicUpdate) 안에서 — 종전엔 load 와 save 사이에 다른 요청이 insert(잠금 안에서 추가)한
        //   기록이 덮여 사라졌음(날짜 기준 정리에서 남겨야 할 최신 기록이 빠짐). 지운 개수는 콜백 안에서 셈
        $cutoff = null;
        if ($beforeDate) {
            $cutoff = strtotime($beforeDate);
            if ($cutoff === false) {
                return ['success' => false, 'error' => __('invalid_date_format', '날짜 형식이 잘못되었습니다.')];   // ★ (2026-11-02 10:29, 42차 점검) 영어 고정 문구였음 → 번역
            }
        }
        $deleted = 0;
        $saved = $this->db->atomicUpdate('activity_logs', function($logs) use ($cutoff, &$deleted) {
            $originalCount = count($logs);
            if ($cutoff !== null) {
                $logs = array_values(array_filter($logs, function($log) use ($cutoff) {
                    return strtotime($log['created_at'] ?? 0) >= $cutoff;
                }));
            } else {
                $logs = [];
            }
            $deleted = $originalCount - count($logs);
            return $logs;
        });
        
        return ['success' => $saved, 'deleted' => $deleted];
    }
    
    /**
     * 로그 타입 다국어 변환
     */
    /* 미사용 함수 제거됨 — getTypeLabel */
}
