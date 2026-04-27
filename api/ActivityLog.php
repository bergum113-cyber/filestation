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
    
    public function __construct($db, $auth) {
        $this->db = $db;
        $this->auth = $auth;
    }
    
    /**
     * 로그 기록
     * $data에 user_id, username, display_name이 있으면 우선 사용 (로그인 시점 등)
     */
    public function log(string $type, array $data = []): int {
        $user = $this->auth->getUser();
        
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
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
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
        usort($logs, function($a, $b) {
            return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
        });
        
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
        
        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            $logs = array_filter($logs, function($log) use ($search) {
                return strpos(strtolower($log['filename'] ?? ''), $search) !== false ||
                       strpos(strtolower($log['path'] ?? ''), $search) !== false ||
                       strpos(strtolower($log['username'] ?? ''), $search) !== false ||
                       strpos(strtolower($log['display_name'] ?? ''), $search) !== false;
            });
        }
        
        $logs = array_values($logs);
        $total = count($logs);
        
        // 페이지네이션
        $offset = ($page - 1) * $limit;
        $logs = array_slice($logs, $offset, $limit);
        
        // 최신 display_name으로 갱신
        $users = $this->db->load('users') ?: [];
        $userMap = [];
        foreach ($users as $u) {
            if (!empty($u['id'])) $userMap[(int)$u['id']] = $u['display_name'] ?? $u['username'] ?? '';
            $uname = strtolower(trim($u['username'] ?? ''));
            if ($uname) $userMap['u:' . $uname] = $u['display_name'] ?? $u['username'] ?? '';
        }
        foreach ($logs as &$log) {
            $uid = (int)($log['user_id'] ?? 0);
            $uname = strtolower(trim($log['username'] ?? ''));
            if ($uid && isset($userMap[$uid])) {
                $log['display_name'] = $userMap[$uid];
            } elseif ($uname && isset($userMap['u:' . $uname])) {
                $log['display_name'] = $userMap['u:' . $uname];
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
        $logs = $this->db->load('activity_logs');
        $originalCount = count($logs);
        
        if ($beforeDate) {
            $cutoff = strtotime($beforeDate);
            if ($cutoff === false) {
                return ['success' => false, 'error' => 'Invalid date format'];
            }
            
            $logs = array_filter($logs, function($log) use ($cutoff) {
                return strtotime($log['created_at'] ?? 0) >= $cutoff;
            });
            $logs = array_values($logs);
        } else {
            $logs = [];
        }
        
        $deleted = $originalCount - count($logs);
        $saved = $this->db->save('activity_logs', $logs);
        
        return ['success' => $saved, 'deleted' => $deleted];
    }
    
    /**
     * 로그 타입 다국어 변환
     */
    /* 미사용 함수 제거됨 — getTypeLabel */
}
