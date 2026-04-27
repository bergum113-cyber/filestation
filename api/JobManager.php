<?php
/**
 * JobManager - 서버 사이드 작업 큐 시스템
 * 
 * 복사/이동/삭제/복구/휴지통 작업을 서버에서 백그라운드로 실행하여
 * 브라우저 새로고침/종료와 무관하게 작업이 계속 진행됩니다.
 */
class JobManager {
    private static ?JobManager $instance = null;
    private string $jobsDir;
    private FileManager $fileManager;
    private ?Auth $auth;
    
    private function __construct() {
        $this->jobsDir = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/jobs';
        if (!is_dir($this->jobsDir)) {
            mkdir($this->jobsDir, 0755, true);
        }
        $this->fileManager = new FileManager();
        $this->auth = null;
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function setAuth(Auth $auth): void {
        $this->auth = $auth;
    }
    
    // ===== 작업 생성 =====
    
    public function create(string $type, array $params, int $userId): array {
        $jobId = 'job_' . uniqid() . '_' . bin2hex(random_bytes(4));
        
        // 사용자당 활성 작업 수 제한
        $db = JsonDB::getInstance();
        $appSettings = $db->load('settings');
        $maxJobs = (int)($appSettings['max_concurrent_jobs'] ?? 5);
        if ($maxJobs > 0) {
            $activeJobs = $this->listJobs($userId, true);
            if (count($activeJobs) >= $maxJobs) {
                return ['success' => false, 'error' => __f('err_too_many_jobs', ['max' => $maxJobs], '동시 작업은 최대 ' . $maxJobs . '개까지 가능합니다.')];
            }
        }
        
        $job = [
            'id' => $jobId,
            'type' => $type,
            'status' => 'pending',
            'user_id' => $userId,
            'session_id' => session_id() ?: '',
            'created_at' => date('Y-m-d H:i:s'),
            'started_at' => null,
            'completed_at' => null,
            'params' => $params,
            'progress' => [
                'total_items' => count($params['items'] ?? []),
                'done_items' => 0,
                'done_success' => 0,
                'total_files' => 0,
                'done_files' => 0,
                'total_size' => 0,
                'done_size' => 0,
                'current_file' => '',
                'speed' => 0,
                'errors' => []
            ]
        ];
        
        // 총 크기/파일 수 계산
        $totalSize = 0;
        foreach (($params['items'] ?? []) as $item) {
            $totalSize += $item['size'] ?? 0;
        }
        $job['progress']['total_size'] = $totalSize;
        
        $this->saveJob($job);
        
        return ['success' => true, 'job_id' => $jobId];
    }
    
    // ===== 작업 실행 (백그라운드에서 호출) =====
    
    public function execute(string $jobId): void {
        $job = $this->loadJob($jobId);
        if (!$job || $job['status'] !== 'pending') return;
        
        $job['status'] = 'running';
        $job['started_at'] = date('Y-m-d H:i:s');
        $this->saveJob($job);
        
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        
        try {
            switch ($job['type']) {
                case 'copy':
                case 'move':
                    $this->executeCopyMove($job);
                    break;
                case 'delete':
                    $this->executeDelete($job);
                    break;
                case 'restore':
                    $this->executeRestore($job);
                    break;
                case 'trash_empty':
                case 'trash_delete':
                    $this->executeTrashDelete($job);
                    break;
                default:
                    $job = $this->loadJob($jobId);
                    $job['status'] = 'failed';
                    $job['progress']['errors'][] = 'Unknown job type: ' . $job['type'];
                    $this->saveJob($job);
                    return;
            }
        } catch (\Throwable $e) {
            $job = $this->loadJob($jobId);
            if ($job) {
                $job['status'] = 'failed';
                $job['progress']['errors'][] = $e->getMessage();
                $job['completed_at'] = date('Y-m-d H:i:s');
                $this->saveJob($job);
            }
        }
    }
    
    // ===== 복사/이동 실행 =====
    
    private function executeCopyMove(array $job): void {
        $jobId = $job['id'];
        $params = $job['params'];
        $items = $params['items'] ?? [];
        $sourceStorageId = (int)($params['source_storage_id'] ?? 0);
        $destStorageId = (int)($params['dest_storage_id'] ?? $sourceStorageId);
        $destPath = $params['dest_path'] ?? '';
        $duplicateAction = $params['duplicate_action'] ?? 'rename';
        $action = $job['type']; // 'copy' or 'move'
        
        // FileManager에 취소 체크 파일 설정 (청크 복사 중 취소 감지용)
        $this->fileManager->setCancelCheckFile($this->getJobFile($jobId));
        
        $storage = new Storage();
        $db = JsonDB::getInstance();
        $appSettings = $db->load('settings');
        $autoIndex = !empty($appSettings['auto_index']);
        
        // 활동 로그
        $activityLog = null;
        if ($this->auth) {
            $activityLog = new ActivityLog($db, $this->auth);
        }
        
        for ($i = 0; $i < count($items); $i++) {
            $job = $this->loadJob($jobId);
            if (!$job || $job['status'] === 'cancelled') return;
            
            $item = $items[$i];
            $job['progress']['done_items'] = $i;
            $job['progress']['current_file'] = $item['name'] ?? '';
            $this->saveJob($job);
            
            try {
                if ($action === 'copy') {
                    $result = $this->fileManager->copy(
                        $sourceStorageId, $item['path'],
                        $destStorageId, $destPath,
                        $duplicateAction
                    );
                } else {
                    $result = $this->fileManager->move(
                        $sourceStorageId, $item['path'],
                        $destStorageId, $destPath,
                        $duplicateAction
                    );
                }
                
                $job = $this->loadJob($jobId);
                if (!$job) return;
                
                if ($result['success'] ?? false) {
                    if (!($result['skipped'] ?? false)) {
                        $job['progress']['done_size'] += $item['size'] ?? 0;
                        $job['progress']['done_success'] = ($job['progress']['done_success'] ?? 0) + 1;
                    }
                    
                    // 활동 로그
                    if ($activityLog) {
                        $storageInfo = $storage->getStorageById($sourceStorageId);
                        $destStorageInfo = $storage->getStorageById($destStorageId);
                        $logType = $action === 'copy' ? ActivityLog::TYPE_COPY : ActivityLog::TYPE_MOVE;
                        $activityLog->log($logType, [
                            'storage_id' => $sourceStorageId,
                            'storage_name' => $storageInfo['name'] ?? '',
                            'path' => $item['path'],
                            'filename' => basename($item['path']),
                            'details' => '→ ' . ($destStorageInfo['name'] ?? '') . ':' . $destPath
                        ]);
                    }
                    
                    // 인덱스 갱신
                    if ($autoIndex) {
                        $fileIndex = FileIndex::getInstance();
                        if ($fileIndex->isAvailable()) {
                            $destBasePath = $storage->getRealPath($destStorageId);
                            $newPath = trim($destPath . '/' . basename($item['path']), '/');
                            $fullPath = $destBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPath);
                            if (is_dir($fullPath)) {
                                $fileIndex->indexFolder($destStorageId, $destBasePath, $newPath);
                            } elseif (is_file($fullPath)) {
                                $fileIndex->addFile($destStorageId, $newPath, [
                                    'name' => basename($item['path']),
                                    'size' => filesize($fullPath) ?: 0,
                                    'modified' => filemtime($fullPath) ?: time(),
                                    'is_dir' => false
                                ]);
                            }
                            
                            // 이동인 경우 원본 인덱스 제거
                            if ($action === 'move') {
                                $srcBasePath = $storage->getRealPath($sourceStorageId);
                                $srcFullPath = $srcBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $item['path']);
                                if ($item['isDir'] ?? false) {
                                    $fileIndex->removeFolder($sourceStorageId, $item['path']);
                                } else {
                                    $fileIndex->removeFile($sourceStorageId, $item['path']);
                                }
                            }
                        }
                    }
                } else {
                    // 취소로 인한 중단은 에러로 카운트하지 않음
                    if (($result['error'] ?? '') !== 'cancelled') {
                        $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . ($result['error'] ?? 'Unknown error');
                    }
                }
            } catch (\Throwable $e) {
                $job = $this->loadJob($jobId);
                if (!$job) return;
                $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . $e->getMessage();
            }
            
            $job['progress']['done_items'] = $i + 1;
            $this->saveJob($job);
        }
        
        // 완료
        $job = $this->loadJob($jobId);
        if ($job && $job['status'] === 'running') {
            $job['status'] = 'completed';
            $job['completed_at'] = date('Y-m-d H:i:s');
            $this->saveJob($job);
        }
    }
    
    // ===== 삭제 실행 =====
    
    private function executeDelete(array $job): void {
        $jobId = $job['id'];
        $params = $job['params'];
        $items = $params['items'] ?? [];
        
        $db = JsonDB::getInstance();
        $activityLog = null;
        if ($this->auth) {
            $activityLog = new ActivityLog($db, $this->auth);
        }
        $storage = new Storage();
        
        for ($i = 0; $i < count($items); $i++) {
            $job = $this->loadJob($jobId);
            if (!$job || $job['status'] === 'cancelled') return;
            
            $item = $items[$i];
            $storageId = (int)($item['storage_id'] ?? $params['storage_id'] ?? 0);
            
            $job['progress']['done_items'] = $i;
            $job['progress']['current_file'] = $item['name'] ?? '';
            $this->saveJob($job);
            
            try {
                // 폴더인 경우 진행률 파일 생성
                $progressFile = '';
                if (!empty($item['isDir'])) {
                    $progressFile = $this->jobsDir . '/delete_progress_' . md5($item['path'] ?? $jobId . '_' . $i) . '.tmp';
                }
                
                $result = $this->fileManager->delete($storageId, $item['path'], $progressFile);
                
                $job = $this->loadJob($jobId);
                if (!$job) return;
                
                if ($result['success'] ?? false) {
                    $job['progress']['done_size'] += $item['size'] ?? 0;
                    $job['progress']['done_success'] = ($job['progress']['done_success'] ?? 0) + 1;
                    
                    if ($activityLog) {
                        $storageInfo = $storage->getStorageById($storageId);
                        $activityLog->log(ActivityLog::TYPE_DELETE, [
                            'storage_id' => $storageId,
                            'storage_name' => $storageInfo['name'] ?? '',
                            'path' => $item['path'],
                            'filename' => basename($item['path'])
                        ]);
                    }
                } else {
                    $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . ($result['error'] ?? 'Unknown error');
                }
            } catch (\Throwable $e) {
                $job = $this->loadJob($jobId);
                if (!$job) return;
                $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . $e->getMessage();
            }
            

            // 진행률 파일 정리
            if ($progressFile && is_file($progressFile)) {
                @unlink($progressFile);
            }
            $job['progress']['done_items'] = $i + 1;
            $this->saveJob($job);
        }
        
        $job = $this->loadJob($jobId);
        if ($job && $job['status'] === 'running') {
            $job['status'] = 'completed';
            $job['completed_at'] = date('Y-m-d H:i:s');
            $this->saveJob($job);
        }
    }
    
    // ===== 복구 실행 =====
    
    private function executeRestore(array $job): void {
        $jobId = $job['id'];
        $params = $job['params'];
        $items = $params['items'] ?? [];
        
        for ($i = 0; $i < count($items); $i++) {
            $job = $this->loadJob($jobId);
            if (!$job || $job['status'] === 'cancelled') return;
            
            $item = $items[$i];
            
            $job['progress']['done_items'] = $i;
            $job['progress']['current_file'] = $item['name'] ?? '';
            $this->saveJob($job);
            
            // 진행률 파일 생성
            $progressFile = $this->jobsDir . '/restore_progress_' . md5($jobId . '_' . $i) . '.tmp';
            
            try {
                $result = $this->fileManager->restoreFromTrash($item['id'], $progressFile);
                
                $job = $this->loadJob($jobId);
                if (!$job) return;
                
                if ($result['success'] ?? false) {
                    $job['progress']['done_size'] += $item['size'] ?? 0;
                    $job['progress']['done_success'] = ($job['progress']['done_success'] ?? 0) + 1;
                } else {
                    $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . ($result['error'] ?? 'Unknown error');
                }
            } catch (\Throwable $e) {
                $job = $this->loadJob($jobId);
                if (!$job) return;
                $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . $e->getMessage();
            }
            
            // 진행률 파일 정리
            if (is_file($progressFile)) {
                @unlink($progressFile);
            }
            
            $job['progress']['done_items'] = $i + 1;
            $this->saveJob($job);
        }
        
        $job = $this->loadJob($jobId);
        if ($job && $job['status'] === 'running') {
            $job['status'] = 'completed';
            $job['completed_at'] = date('Y-m-d H:i:s');
            $this->saveJob($job);
        }
    }
    
    // ===== 휴지통 영구삭제 실행 =====
    
    private function executeTrashDelete(array $job): void {
        $jobId = $job['id'];
        $params = $job['params'];
        $items = $params['items'] ?? [];
        
        for ($i = 0; $i < count($items); $i++) {
            $job = $this->loadJob($jobId);
            if (!$job || $job['status'] === 'cancelled') return;
            
            $item = $items[$i];
            
            $job['progress']['done_items'] = $i;
            $job['progress']['current_file'] = $item['name'] ?? '';
            $this->saveJob($job);
            
            // 진행률 파일 생성 (폴더인 경우)
            $progressFile = '';
            if (!empty($item['is_dir'])) {
                $progressFile = $this->jobsDir . '/delete_progress_' . md5($item['id']) . '.tmp';
            }
            
            try {
                $result = $this->fileManager->deleteFromTrash($item['id'], $progressFile);
                
                $job = $this->loadJob($jobId);
                if (!$job) return;
                
                if ($result['success'] ?? false) {
                    $job['progress']['done_size'] += $item['size'] ?? 0;
                    $job['progress']['done_success'] = ($job['progress']['done_success'] ?? 0) + 1;
                } else {
                    $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . ($result['error'] ?? 'Unknown error');
                }
            } catch (\Throwable $e) {
                $job = $this->loadJob($jobId);
                if (!$job) return;
                $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . $e->getMessage();
            }
            
            // 진행률 파일 정리
            if ($progressFile && is_file($progressFile)) {
                @unlink($progressFile);
            }
            
            $job['progress']['done_items'] = $i + 1;
            $this->saveJob($job);
        }
        
        $job = $this->loadJob($jobId);
        if ($job && $job['status'] === 'running') {
            $job['status'] = 'completed';
            $job['completed_at'] = date('Y-m-d H:i:s');
            $this->saveJob($job);
        }
    }
    
    // ===== 상태 조회 =====
    
    public function getStatus(string $jobId): ?array {
        $job = $this->loadJob($jobId);
        if (!$job) return null;
        
        // copy_progress 파일에서 실시간 파일별 진행률 병합
        if (in_array($job['type'], ['copy', 'move']) && $job['status'] === 'running') {
            $this->mergeCopyProgress($job);
        }
        
        // delete_progress 파일에서 실시간 진행률 병합
        if (in_array($job['type'], ['delete', 'trash_empty', 'trash_delete', 'restore']) && $job['status'] === 'running') {
            $this->mergeDeleteProgress($job);
        }
        
        return $job;
    }
    
    // copy_progress 파일에서 실시간 진행률 읽기
    private function mergeCopyProgress(array &$job): void {
        $items = $job['params']['items'] ?? [];
        $doneItems = $job['progress']['done_items'] ?? 0;
        
        if ($doneItems < count($items)) {
            $currentItem = $items[$doneItems] ?? null;
            if ($currentItem) {
                $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
                $itemPath = $currentItem['path'] ?? '';
                
                // progressFile 경로 후보: job의 session_id, 현재 session_id(), 빈 문자열
                $candidates = [];
                $jobSessionId = $job['session_id'] ?? '';
                if ($jobSessionId !== '') {
                    $candidates[] = $dataDir . '/copy_progress_' . md5($jobSessionId . $itemPath) . '.tmp';
                }
                $currentSessionId = session_id() ?: '';
                if ($currentSessionId !== '' && $currentSessionId !== $jobSessionId) {
                    $candidates[] = $dataDir . '/copy_progress_' . md5($currentSessionId . $itemPath) . '.tmp';
                }
                // 빈 session_id로 생성된 경우의 fallback
                $candidates[] = $dataDir . '/copy_progress_' . md5($itemPath) . '.tmp';
                
                $progressFile = null;
                foreach ($candidates as $pf) {
                    if (is_file($pf)) { $progressFile = $pf; break; }
                }
                
                if ($progressFile) {
                    $data = @json_decode(@file_get_contents($progressFile), true);
                    if ($data) {
                        $job['progress']['current_file'] = ($currentItem['name'] ?? ''); if (!empty($data['file'])) $job['progress']['current_file'] .= ' → ' . $data['file'];
                        if (isset($data['filesDone'])) $job['progress']['done_files'] = $data['filesDone'];
                        if (isset($data['totalFiles'])) $job['progress']['total_files'] = $data['totalFiles'];
                        if (isset($data['copied'])) {
                            // done_size = 이전 항목 합산 + 현재 항목 진행분
                            $prevSize = 0;
                            for ($i = 0; $i < $doneItems; $i++) {
                                $prevSize += $items[$i]['size'] ?? 0;
                            }
                            $job['progress']['done_size'] = $prevSize + $data['copied'];
                        }
                    }
                }
            }
        }
    }
    
    // delete_progress 파일에서 실시간 진행률 읽기
    private function mergeDeleteProgress(array &$job): void {
        $items = $job['params']['items'] ?? [];
        $doneItems = $job['progress']['done_items'] ?? 0;
        
        if ($doneItems < count($items)) {
            $currentItem = $items[$doneItems] ?? null;
            if ($currentItem) {
                // 진행률 파일 찾기: id 기반 (trash_delete/trash_empty) 또는 path 기반 (delete) 또는 jobId 기반 (restore)
                $progressFile = null;
                $jobId = $job['id'] ?? '';
                
                if (!empty($currentItem['id'])) {
                    // trash_delete, trash_empty, restore
                    $pf = $this->jobsDir . '/delete_progress_' . md5($currentItem['id']) . '.tmp';
                    if (is_file($pf)) $progressFile = $pf;
                    
                    // restore용
                    if (!$progressFile) {
                        $pf = $this->jobsDir . '/restore_progress_' . md5($jobId . '_' . $doneItems) . '.tmp';
                        if (is_file($pf)) $progressFile = $pf;
                    }
                }
                
                if (!$progressFile && !empty($currentItem['path'])) {
                    // delete (일반 삭제)
                    $pf = $this->jobsDir . '/delete_progress_' . md5($currentItem['path'] ?? $jobId . '_' . $doneItems) . '.tmp';
                    if (is_file($pf)) $progressFile = $pf;
                }
                
                if ($progressFile && is_file($progressFile)) {
                    $data = @json_decode(@file_get_contents($progressFile), true);
                    if ($data) {
                        $job['progress']['current_file'] = ($currentItem['name'] ?? ''); if (!empty($data['file'])) $job['progress']['current_file'] .= ' → ' . $data['file'];
                        if (isset($data['filesDone'])) $job['progress']['done_files'] = $data['filesDone'];
                        if (isset($data['totalFiles'])) $job['progress']['total_files'] = $data['totalFiles'];
                        if (isset($data['deleted'])) {
                            $prevSize = 0;
                            for ($i = 0; $i < $doneItems; $i++) {
                                $prevSize += $items[$i]['size'] ?? 0;
                            }
                            $job['progress']['done_size'] = $prevSize + $data['deleted'];
                        }
                        if (isset($data['copied'])) {
                            $prevSize = 0;
                            for ($i = 0; $i < $doneItems; $i++) {
                                $prevSize += $items[$i]['size'] ?? 0;
                            }
                            $job['progress']['done_size'] = $prevSize + $data['copied'];
                        }
                    }
                }
            }
        }
    }
    
    // ===== 취소 =====
    
    public function cancel(string $jobId, int $userId): array {
        $job = $this->loadJob($jobId);
        if (!$job) return ['success' => false, 'error' => 'Job not found'];
        if ($job['user_id'] !== $userId) return ['success' => false, 'error' => 'Permission denied'];
        if ($job['status'] === 'completed' || $job['status'] === 'failed') {
            return ['success' => false, 'error' => 'Job already finished'];
        }
        
        $job['status'] = 'cancelled';
        $job['completed_at'] = date('Y-m-d H:i:s');
        $this->saveJob($job);
        
        return ['success' => true];
    }
    
    // ===== 사용자 작업 목록 =====
    
    public function listJobs(int $userId, bool $activeOnly = true): array {
        $jobs = [];
        $files = @glob($this->jobsDir . '/job_*.json');
        if (!$files) return $jobs;
        
        foreach ($files as $file) {
            $job = @json_decode(@file_get_contents($file), true);
            if (!$job || ($job['user_id'] ?? 0) !== $userId) continue;
            
            if ($activeOnly && in_array($job['status'], ['completed', 'failed', 'cancelled'])) {
                continue;
            }
            
            // 실시간 진행률 병합
            if ($job['status'] === 'running') {
                if (in_array($job['type'], ['copy', 'move'])) {
                    $this->mergeCopyProgress($job);
                } elseif (in_array($job['type'], ['trash_empty', 'trash_delete'])) {
                    $this->mergeDeleteProgress($job);
                }
            }
            
            $jobs[] = $job;
        }
        
        // 최신순 정렬
        usort($jobs, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        
        return $jobs;
    }
    
    // ===== 오래된 작업 정리 =====
    
    public function cleanup(int $maxAge = 3600): int {
        $cleaned = 0;
        $files = @glob($this->jobsDir . '/job_*.json');
        if (!$files) return 0;
        
        foreach ($files as $file) {
            $job = @json_decode(@file_get_contents($file), true);
            if (!$job) { @unlink($file); $cleaned++; continue; }
            
            // 완료/실패/취소된 작업이 maxAge 이상 지나면 삭제
            if (in_array($job['status'] ?? '', ['completed', 'failed', 'cancelled'])) {
                $completedAt = strtotime($job['completed_at'] ?? '');
                if ($completedAt && (time() - $completedAt) > $maxAge) {
                    @unlink($file);
                    $cleaned++;
                }
            }
            
            // pending 상태로 5분 이상 방치된 작업 (실행 안 됨) 삭제
            if (($job['status'] ?? '') === 'pending') {
                $createdAt = strtotime($job['created_at'] ?? '');
                if ($createdAt && (time() - $createdAt) > 300) {
                    @unlink($file);
                    $cleaned++;
                }
            }
            
            // running 상태로 12시간 이상 (비정상 종료) → failed로 변경
            if (($job['status'] ?? '') === 'running') {
                $startedAt = strtotime($job['started_at'] ?? '');
                if ($startedAt && (time() - $startedAt) > 43200) {
                    $job['status'] = 'failed';
                    $job['completed_at'] = date('Y-m-d H:i:s');
                    $job['progress']['errors'][] = 'Job timed out (12h)';
                    $this->saveJob($job);
                    $cleaned++;
                }
            }
        }
        
        // delete_progress tmp 파일 정리
        $tmpFiles = @glob($this->jobsDir . '/delete_progress_*.tmp');
        if ($tmpFiles) {
            foreach ($tmpFiles as $f) {
                if (filemtime($f) < time() - 3600) @unlink($f);
            }
        }
        
        return $cleaned;
    }
    
    // ===== 파일 I/O =====
    
    private function getJobFile(string $jobId): string {
        // jobId 검증 (경로 탐색 방지)
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $jobId);
        return $this->jobsDir . '/' . $safe . '.json';
    }
    
    private function loadJob(string $jobId): ?array {
        $file = $this->getJobFile($jobId);
        if (!is_file($file)) return null;
        $data = @file_get_contents($file);
        if (!$data) return null;
        return @json_decode($data, true);
    }
    
    private function saveJob(array $job): void {
        $file = $this->getJobFile($job['id']);
        $tmp = $file . '.tmp';
        @file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @rename($tmp, $file);
    }
}
