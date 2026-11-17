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
        // ★ (2026-10-31 13:40, 34차 후속 개선) 'pending 확인 → running 저장' 사이에 잠금이 없어, job_execute 가 겹치면(폴링 재요청·탭 두 개) 같은 작업이 두 번 돌 수 있었음
        //   → 작업별 잠금 파일을 LOCK_NB 로 잡고(못 잡으면 다른 요청이 실행 중이라 그냥 돌아감) 잠금 안에서 상태 확인·실행. 끝나면 풀고 지움(남은 것은 cleanup 이 정리)
        $lockFile = $this->getJobFile($jobId) . '.lock';
        $lockFp = @fopen($lockFile, 'c');
        if (!$lockFp) return;
        if (!@flock($lockFp, LOCK_EX | LOCK_NB)) { fclose($lockFp); return; }
        try {
            $this->executeLocked($jobId);
        } finally {
            @flock($lockFp, LOCK_UN);
            fclose($lockFp);
            @unlink($lockFile);
        }
    }
    
    private function executeLocked(string $jobId): void {
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
        $this->fileManager->setCancelCheckFile($this->cancelFlagFile($jobId));   // ★ (2026-11-02 14:55, 44차 점검) 작은 취소 표시 파일(청크마다 큰 작업 파일을 읽지 않게, 위 saveProgress 설명)
        
        $storage = new Storage();
        $db = JsonDB::getInstance();
        $appSettings = $db->load('settings');
        $autoIndex = !empty($appSettings['auto_index']);
        
        // 활동 로그
        $activityLog = null;
        $logBuf = null;
        if ($this->auth) {
            $logBuf = new JobActivityBuffer();   // ★ (2026-11-02 14:55, 44차 점검) 기록은 모아 한 번에(flushActivity)
            register_shutdown_function(function () use ($logBuf) { $this->flushActivity($logBuf, true); });   // ★ (2026-11-02 15:20, 44차 재검토) 치명 오류·실행 시간 초과로 끝나도 모아 둔 기록을 씀(finally 는 이 경우 안 돎)
            $activityLog = new ActivityLog($logBuf, $this->auth);
        }
        $storageInfoMemo = [];   // ★ (2026-11-02 14:55, 44차 점검) 스토리지 이름 조회를 항목마다 하지 않게(id→행, 이 작업 안에서만)
        $lastMainCheck = 0.0;
        // ★ (2026-11-13 09:18, 110차 BULK-2) 이 작업이 대상에 놓은 이름(Windows 는 대소문자 무시) — '덮어쓰기'로 고른 항목끼리 이름이 같으면(검색 결과 sx/qq.txt·sy/qq.txt) 둘째부터는 '이름 변경'(qq (2).txt)으로.
        //   종전엔 둘째가 방금 옮긴 첫째를 덮어 첫 파일이 휴지통·버전 없이 사라졌음(재현). 대상에 원래 있던 항목 덮어쓰기·건너뛰기·이름 변경은 그대로
        $placedNames = [];
        $placedKey = function ($n) { $n = basename(str_replace('\\', '/', (string)$n)); return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($n) : $n; };
        // ★ (2026-11-13 16:57, 113차 DEF-2) 이번 작업에서 옮긴 경로(executeDelete 110차 BULK-3 $delDone 과 같은 방식) — 폴더와 그 안 항목을 함께 골라 '이동'하면 폴더와 함께 이미 옮겨진 하위가
        //   '원본 파일/폴더가 존재하지 않습니다'로 실패로 세어졌음(재현) → 같은 로컬 스토리지 안 이동에서, 옮긴 폴더의 하위이고 원본 자리에 실제로 없을 때만 성공으로 셈. 복사·다른 스토리지 이동·원격·잠금 등 실제 실패는 그대로
        $mvDone = [];
        $mvDest = [];   // ★ (2026-11-13 21:10, 116차 개선 RGR29-4) $mvDone 과 같은 순서로, 옮긴 폴더가 놓인 자리(스토리지 안 상대 경로) — 아래 '하위가 대상 자리에 실제로 있는지' 확인에 씀
        $mvKey = function ($p) { $p = trim(str_replace('\\', '/', (string)$p), '/'); return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($p) : $p; };
        $mvBase = ($action === 'move' && $sourceStorageId === $destStorageId && !$this->fileManager->isRemoteStorage($sourceStorageId)) ? $storage->getRealPath($sourceStorageId) : null;   // ★ (2026-11-13 16:57, 113차 DEF-2)
        
        try {
        for ($i = 0; $i < count($items); $i++) {
            // ★ (2026-11-02 14:55, 44차 점검) 작업 파일을 다시 읽고 쓰지 않음 — 진행은 메모리의 $job 에 두고 작은 진행 파일에 씀(종전 loadJob/saveJob 4회 → 확인 1회 + 진행 쓰기 2회)
            // ★ (2026-11-02 15:20, 44차 재검토) 폴더·큰 파일(20MB 이상) 항목 앞에서는 모아 둔 기록을 바로 씀 — 오래 걸리는 항목 동안 앞 항목 기록이 메모리에만 있어, 그사이 프로세스가 죽으면 사라졌음(R44_job#2 재현)
            $this->flushActivity($logBuf, !empty($items[$i]['is_dir']) || !empty($items[$i]['isDir']) || (int)($items[$i]['size'] ?? 0) >= 20 * 1024 * 1024);   // ★ (2026-11-04 01:30, 54차 점검) 화면은 isDir 로 보냄 — is_dir 만 봐서 폴더 앞에서 기록을 바로 쓰지 않았음(재현)
            if ($this->jobShouldStop($job, $lastMainCheck)) return;
            
            $item = $items[$i];
            $job['progress']['done_items'] = $i;
            $job['progress']['current_file'] = $item['name'] ?? '';
            $this->saveProgress($job);
            $itemDupAction = ($duplicateAction === 'overwrite' && isset($placedNames[$placedKey($item['path'] ?? '')])) ? 'rename' : $duplicateAction;   // ★ (2026-11-13 09:18, 110차 BULK-2) 위 $placedNames 설명
            
            try {
                if ($action === 'copy') {
                    $result = $this->fileManager->copy(
                        $sourceStorageId, $item['path'],
                        $destStorageId, $destPath,
                        $itemDupAction
                    );
                } else {
                    $result = $this->fileManager->move(
                        $sourceStorageId, $item['path'],
                        $destStorageId, $destPath,
                        $itemDupAction
                    );
                }
                
                if (!$this->jobFileExists($jobId)) return;
                
                if ($result['success'] ?? false) {
                    if (!($result['skipped'] ?? false)) {
                        $job['progress']['done_size'] += $item['size'] ?? 0;
                        $job['progress']['done_success'] = ($job['progress']['done_success'] ?? 0) + 1;
                        $placedNames[$placedKey($result['name'] ?? ($result['filename'] ?? ($item['path'] ?? '')))] = true;   // ★ (2026-11-13 09:18, 110차 BULK-2) 놓인 이름 기억(위 설명)
                        if ($mvBase) $mvDone[] = $mvKey($item['path'] ?? '');   // ★ (2026-11-13 16:57, 113차 DEF-2) 위 $mvDone
                        if ($mvBase) $mvDest[] = trim(trim(str_replace('\\', '/', (string)$destPath), '/') . '/' . basename(str_replace('\\', '/', (string)($result['name'] ?? ($item['path'] ?? '')))), '/');   // ★ (2026-11-13 21:10, 116차 개선 RGR29-4) 위 $mvDest(로컬 이동은 놓인 이름을 'name' 으로 돌려줌 — 이름 변경 포함)
                    }
                    
                    // 활동 로그 — ★ (2026-10-28) 건너뜀(같은 이름이 있어 아무것도 안 함)이면 기록·인덱스 갱신 안 함
                    if ($activityLog && empty($result['skipped'])) {
                        if (!array_key_exists($sourceStorageId, $storageInfoMemo)) $storageInfoMemo[$sourceStorageId] = $storage->getStorageById($sourceStorageId);
                        if (!array_key_exists($destStorageId, $storageInfoMemo)) $storageInfoMemo[$destStorageId] = $storage->getStorageById($destStorageId);
                        $storageInfo = $storageInfoMemo[$sourceStorageId];
                        $destStorageInfo = $storageInfoMemo[$destStorageId];
                        $logType = $action === 'copy' ? ActivityLog::TYPE_COPY : ActivityLog::TYPE_MOVE;
                        $activityLog->log($logType, [
                            'storage_id' => $sourceStorageId,
                            'storage_name' => $storageInfo['name'] ?? '',
                            'path' => $item['path'],
                            'filename' => basename($item['path']),
                            'details' => '→ ' . ($destStorageInfo['name'] ?? '') . ':' . $destPath
                        ]);
                    }
                    
                    // 인덱스 갱신(건너뜀이면 안 함 — 종전엔 이동을 건너뛰어도 원본이 인덱스에서 빠졌음)
                    if ($autoIndex && empty($result['skipped'])) {
                        $fileIndex = FileIndex::getInstance();
                        if ($fileIndex->isAvailable()) {
                            $destBasePath = $storage->getRealPath($destStorageId);
                            // ★ (2026-10-28) '이름 변경'으로 놓였으면 실제 이름 — 이동·복사 모두 'name'(로컬), 원격이 끼면 'filename', 없으면 원래 이름
                            $_placedName = (string)($result['name'] ?? ($result['filename'] ?? basename($item['path'])));
                            $newPath = trim($destPath . '/' . $_placedName, '/');
                            $fullPath = $destBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPath);
                            // ★ (2026-10-28) 원격 스토리지는 로컬 경로가 빈 문자열이라 '/이름' 같은 서버 루트 경로를 보게 됨
                            //   (예: 원격으로 'mnt' 폴더를 붙여넣으면 서버의 /mnt 내용이 그 원격 스토리지 인덱스에 들어가 검색에 보였음) → 로컬 경로가 있을 때만
                            if ($destBasePath && is_dir($fullPath)) {
                                // ★ (2026-11-02 14:39, 44차 점검) 그 자리의 옛 행(하위까지)을 먼저 지우고 디스크 그대로 다시 색인(api.php copy·move, WebDAV 와 같게) — 폴더 위 덮어쓰기에서
                                //   옛 폴더에만 있던 파일 행이 유령으로 남고 사용량 합계가 부풀었음(G1#3)
                                $fileIndex->removeFolder($destStorageId, $newPath);
                                $fileIndex->indexFolder($destStorageId, $destBasePath, $newPath);
                            } elseif ($destBasePath && is_file($fullPath)) {
                                $fileIndex->addFile($destStorageId, $newPath, [
                                    'name' => $_placedName,
                                    'size' => filesize($fullPath) ?: 0,
                                    'modified' => filemtime($fullPath) ?: time(),
                                    'is_dir' => false
                                ]);
                            } elseif (!$destBasePath) {
                                // ★ (2026-10-29 23:57, 24차 점검) 원격 대상(FTP 등, 로컬 경로 없음)도 놓인 항목 한 행은 넣음 — 종전엔 아무것도 안 넣어(이동이면 원본 행만 지움)
                                //   화면 붙여넣기로 원격에 옮긴·복사한 항목이 다음 동기화 전까지 검색·인덱스 목록에서 빠졌음(5회차 재검토). 폴더 하위는 다음 동기화
                                $fileIndex->addFile($destStorageId, $newPath, [
                                    'name' => $_placedName,
                                    'size' => !empty($item['isDir']) ? 0 : max(0, (int)($item['size'] ?? 0)),
                                    'modified' => time(),
                                    'is_dir' => !empty($item['isDir']) ? 1 : 0
                                ]);
                                if (!empty($item['isDir'])) self::indexRemoteTree($this->fileManager, $destStorageId, $newPath);   // ★ (2026-11-04, 55차 점검) 놓인 폴더의 하위도 원격 목록으로 색인(위 indexRemoteTree 설명, X-1). 목록 실패면 종전처럼 폴더 행만
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
                    // ★ (2026-11-13 16:57, 113차 DEF-2) 위 $mvDone — 이번에 옮긴 폴더의 하위이고 원본 자리에 실제로 없으면 성공으로 셈(오류·failed_paths 에 안 넣음, 기록은 상위 폴더 한 줄)
                    $mvUnder = false;
                    if ($mvBase && $mvDone && ($result['error'] ?? '') !== 'cancelled') {
                        $mvK = $mvKey($item['path'] ?? '');
                        foreach ($mvDone as $mvI => $mvD) { if ($mvD !== '' && strpos($mvK, $mvD . '/') === 0) { $mvUnder = true; $mvHit = $mvI; break; } }   // ★ (2026-11-13 21:10, 116차 개선 RGR29-4) 어느 폴더 아래인지($mvHit)도 기억
                        if ($mvUnder) $mvUnder = !file_exists(rtrim($mvBase, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim(str_replace('\\', '/', (string)$item['path']), '/')));
                        if ($mvUnder) { $mvRest = mb_substr(trim(str_replace('\\', '/', (string)$item['path']), '/'), mb_strlen($mvDone[$mvHit]) + 1); $mvUnder = file_exists(rtrim($mvBase, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($mvDest[$mvHit] . '/' . $mvRest, '/'))); }   // ★ (2026-11-13 21:10, 116차 개선 RGR29-4) 113차 DEF-2 를 좁힘 —
                        //   그 하위가 옮긴 폴더의 새 자리(같은 상대 경로)에 실제로 있을 때만 성공으로 셈. 종전엔 처음부터 없던 항목(다른 기기에서 지운 뒤 오래된 목록으로 고름)도 성공으로 세어 완료 개수가 하나 많았음(재현) → 없으면 113차 전처럼 오류
                    }
                    // 취소로 인한 중단은 에러로 카운트하지 않음
                    if ($mvUnder) {
                        $job['progress']['done_success'] = ($job['progress']['done_success'] ?? 0) + 1;
                    } elseif (($result['error'] ?? '') !== 'cancelled') {
                        $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . ($result['error'] ?? 'Unknown error');
                        $job['progress']['failed_paths'][] = (string)($item['path'] ?? '');   // ★ (2026-11-13 11:37, 111차 RGR27-3) 실패한 항목의 원본 경로(화면이 보낸 값)를 새 필드로 더함 — 화면이 클립보드에 남길 항목을 경로로 짝지음. errors 등 기존 필드는 그대로. 종전엔 이름만 있어, 같은 이름의 다른 경로가 섞이면 이미 옮겨진 항목도 클립보드에 남았음(재현)
                    }
                }
            } catch (\Throwable $e) {
                if (!$this->jobFileExists($jobId)) return;
                $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . $e->getMessage();
                $job['progress']['failed_paths'][] = (string)($item['path'] ?? '');   // ★ (2026-11-13 11:37, 111차 RGR27-3) 위와 같음
            }
            
            $job['progress']['done_items'] = $i + 1;
            $this->saveProgress($job);
        }
        
        // 완료
        $this->finishJob($job, $lastMainCheck);
        } finally {
            $this->flushActivity($logBuf, true);
        }
    }
    
    /**
     * ★ (2026-11-04, 55차 점검) 원격 스토리지에 놓인 폴더 하나만 원격 목록으로 색인(하위 전부) — 원격으로 폴더를 복사·이동·복원하면 폴더 행 하나만 넣고 '하위는 다음 동기화'로 미뤘는데,
     *   원격은 자동 색인 동기화(index_sync)에서 빠져 수동 재구축 전까지 색인 즉답 목록에서 폴더가 비어 보이고 검색에도 안 나왔음(X-1). FileIndex::indexRemoteDirectory 와 같은 규칙
     *   (점 이름 건너뜀·폴더마다 짧은 트랜잭션). 행은 덮어쓰기만(지우지 않음 — 원격 폴더 덮어쓰기는 합치기). 맨 위 목록이 실패하면 false(부른 쪽은 종전처럼 폴더 행만)
     */
    public static function indexRemoteTree(FileManager $fm, int $storageId, string $relPath): bool {
        $relPath = trim(str_replace('\\', '/', $relPath), '/');
        if ($relPath === '' || !class_exists('FileIndex')) return false;
        $ix = FileIndex::getInstance();
        if (!$ix->isAvailable()) return false;
        $adapter = $fm->getAdapter($storageId);
        if (!$adapter) return false;
        $db = $ix->getDb();
        $queue = [$relPath];
        $first = true;
        while ($queue) {
            $dir = array_shift($queue);
            try { $items = $adapter->list($dir); } catch (\Throwable $e) { $items = null; }
            $failed = !is_array($items) || (property_exists($adapter, 'listFailed') && $adapter->listFailed);
            if ($failed) { if ($first) return false; continue; }
            $first = false;
            $inTx = false;
            try {
                $db->exec('BEGIN TRANSACTION'); $inTx = true;
                foreach ($items as $it) {
                    $nm = (string)($it['name'] ?? '');
                    if ($nm === '' || $nm[0] === '.' || strpbrk($nm, "/\\\0") !== false) continue;
                    $rp = $dir . '/' . $nm;
                    $isDir = !empty($it['is_dir']);
                    $ix->addFile($storageId, $rp, [
                        'is_dir' => $isDir ? 1 : 0,
                        'size' => $isDir ? 0 : max(0, (int)($it['size'] ?? 0)),
                        'modified' => isset($it['modified']) && is_numeric($it['modified']) ? (int)$it['modified'] : time(),
                    ]);
                    if ($isDir) $queue[] = $rp;
                }
                $db->exec('COMMIT'); $inTx = false;
            } catch (\Throwable $e) {
                if ($inTx) { @$db->exec('COMMIT'); }
            }
        }
        return true;
    }

    // ===== 삭제 실행 =====

    private function executeDelete(array $job): void {
        $jobId = $job['id'];
        $params = $job['params'];
        $items = $params['items'] ?? [];
        
        $db = JsonDB::getInstance();
        $activityLog = null;
        $logBuf = null;
        if ($this->auth) {
            $logBuf = new JobActivityBuffer();   // ★ (2026-11-02 14:55, 44차 점검) 기록은 모아 한 번에(flushActivity)
            register_shutdown_function(function () use ($logBuf) { $this->flushActivity($logBuf, true); });   // ★ (2026-11-02 15:20, 44차 재검토) 치명 오류·실행 시간 초과로 끝나도 모아 둔 기록을 씀(finally 는 이 경우 안 돎)
            $activityLog = new ActivityLog($logBuf, $this->auth);
        }
        $storage = new Storage();
        $storageInfoMemo = [];   // ★ (2026-11-02 14:55, 44차 점검) 스토리지 이름 조회를 항목마다 하지 않게
        $lastMainCheck = 0.0;
        // ★ (2026-11-13 09:47, 110차 BULK-3) 이번 작업에서 지운 경로(스토리지별) — 상위 폴더와 함께 이미 휴지통에 간 하위 항목(검색 결과에서 폴더와 그 안 항목을 같이 고름)이 '파일을 찾을 수 없습니다'로
        //   실패로 세어졌음(재현) → 로컬 스토리지에서 실제로 없을 때만 성공으로 셈(기록은 상위 폴더 한 줄). 잠금 등 실제 실패·원격 스토리지는 그대로
        $delDone = [];
        $delKey = function ($p) { $p = trim(str_replace('\\', '/', (string)$p), '/'); return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($p) : $p; };
        // ★ (2026-11-14 12:31, 127차 펜닐 결정 '이동처럼 오류로 세기') 위 $delDone 은 '지금 없으면' 성공이라 처음부터 없던 하위 항목도 성공으로 셌음(재현) →
        //   작업을 시작할 때 '함께 고른 상위 폴더가 있는 항목'(로컬 스토리지)만 실제로 있었는지 미리 적어 두고, 있던 것만 성공으로 셈(없던 것은 종전 오류 그대로 — 이동 116차 RGR29-4 와 같은 기준)
        $delPreHad = [];
        try {
            $dpSel = [];
            foreach ($items as $dpIt) { $dpSel[(int)($dpIt['storage_id'] ?? $params['storage_id'] ?? 0)][$delKey($dpIt['path'] ?? '')] = true; }
            foreach ($items as $dpI => $dpIt) {
                $dpSid = (int)($dpIt['storage_id'] ?? $params['storage_id'] ?? 0);
                $dpK = $delKey($dpIt['path'] ?? ''); $dpUp = $dpK; $dpHasParent = false;
                while (($dpPos = strrpos($dpUp, '/')) !== false) { $dpUp = substr($dpUp, 0, $dpPos); if (isset($dpSel[$dpSid][$dpUp])) { $dpHasParent = true; break; } }
                if (!$dpHasParent || $this->fileManager->isRemoteStorage($dpSid)) continue;
                $dpBase = $storage->getRealPath($dpSid);
                if ($dpBase) $delPreHad[$dpI] = file_exists(rtrim($dpBase, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim(str_replace('\\', '/', (string)($dpIt['path'] ?? '')), '/')));
            }
        } catch (\Throwable $e) { $delPreHad = []; }
        
        try {
        for ($i = 0; $i < count($items); $i++) {
            // ★ (2026-11-02 14:55, 44차 점검) 작업 파일 대신 메모리 $job + 작은 진행 파일(executeCopyMove 와 같음)
            // ★ (2026-11-02 15:20, 44차 재검토) 폴더·큰 파일(20MB 이상) 항목 앞에서는 모아 둔 기록을 바로 씀 — 오래 걸리는 항목 동안 앞 항목 기록이 메모리에만 있어, 그사이 프로세스가 죽으면 사라졌음(R44_job#2 재현)
            $this->flushActivity($logBuf, !empty($items[$i]['is_dir']) || !empty($items[$i]['isDir']) || (int)($items[$i]['size'] ?? 0) >= 20 * 1024 * 1024);   // ★ (2026-11-04 01:30, 54차 점검) 화면은 isDir 로 보냄 — is_dir 만 봐서 폴더 앞에서 기록을 바로 쓰지 않았음(재현)
            if ($this->jobShouldStop($job, $lastMainCheck)) return;
            
            $item = $items[$i];
            $storageId = (int)($item['storage_id'] ?? $params['storage_id'] ?? 0);
            
            $job['progress']['done_items'] = $i;
            $job['progress']['current_file'] = $item['name'] ?? '';
            $this->saveProgress($job);
            
            try {
                // 폴더인 경우 진행률 파일 생성
                $progressFile = '';
                if (!empty($item['isDir'])) {
                    // ★ (2026-11-01 01:13, 35차 전체 점검) 이름에 작업 ID 를 넣음(아래 mergeDeleteProgress 와 같은 규칙) — 종전엔 상대경로만이라 두 스토리지에서 같은 경로를 동시에 지우면 진행률 파일을 함께 썼음
                    $progressFile = $this->jobsDir . '/delete_progress_' . md5($jobId . '|' . ($item['path'] ?? ('#' . $i))) . '.tmp';
                }
                
                $result = $this->fileManager->delete($storageId, $item['path'], $progressFile);
                
                if (!$this->jobFileExists($jobId)) return;
                
                // ★ (2026-11-13 09:47, 110차 BULK-3) 위 $delDone — 이번에 지운 폴더의 하위이고 로컬에서 실제로 없으면 성공으로 셈
                $delUnder = false;
                if (!($result['success'] ?? false) && !empty($delDone[$storageId])) {
                    $delK = $delKey($item['path'] ?? '');
                    foreach ($delDone[$storageId] as $delD) { if ($delD !== '' && strpos($delK, $delD . '/') === 0) { $delUnder = true; break; } }
                    if ($delUnder) {
                        $delBase = $this->fileManager->isRemoteStorage($storageId) ? null : $storage->getRealPath($storageId);
                        $delUnder = $delBase && !file_exists(rtrim($delBase, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim(str_replace('\\', '/', (string)$item['path']), '/')));
                        if ($delUnder && array_key_exists($i, $delPreHad) && !$delPreHad[$i]) $delUnder = false;   // ★ (2026-11-14 12:31, 127차 펜닐 결정 '이동처럼 오류로 세기') 처음부터 없던 항목은 성공으로 세지 않음(위 $delPreHad)
                    }
                }
                if ($delUnder) {
                    $job['progress']['done_success'] = ($job['progress']['done_success'] ?? 0) + 1;
                } elseif ($result['success'] ?? false) {
                    $delDone[$storageId][] = $delKey($item['path'] ?? '');   // ★ (2026-11-13 09:47, 110차 BULK-3)
                    $job['progress']['done_size'] += $item['size'] ?? 0;
                    $job['progress']['done_success'] = ($job['progress']['done_success'] ?? 0) + 1;
                    
                    if ($activityLog) {
                        if (!array_key_exists($storageId, $storageInfoMemo)) $storageInfoMemo[$storageId] = $storage->getStorageById($storageId);
                        $storageInfo = $storageInfoMemo[$storageId];
                        $activityLog->log(ActivityLog::TYPE_DELETE, [
                            'storage_id' => $storageId,
                            'storage_name' => $storageInfo['name'] ?? '',
                            'path' => $item['path'],
                            'filename' => basename($item['path'])
                        ]);
                    }
                    // ★ (2026-11-04, 55차 점검) 보관함 폴더처럼 서버가 스스로 영구 삭제로 바꾼 경우 그 경로·하위의 폴더 규칙 정리(api.php 'delete' 의 26차 재검토 수정과 같은 방식) —
                    //   화면 삭제는 이 작업 큐로 와서 규칙이 남았고, 같은 이름으로 새로 만든 폴더가 옛 규칙을 물려받아 일부 사용자에게 안 보였음(D3). 휴지통으로 간 것은 종전대로 규칙 유지(복원용)
                    if (!empty($result['permanent'])) {
                        try {
                            $dpClean = trim(str_replace('\\', '/', (string)$item['path']), '/');
                            if ($dpClean !== '') {
                                foreach ($storage->getAllFolderPermissions($storageId) as $fp) {
                                    $fpPath = trim($fp['folder_path'] ?? '', '/');
                                    if ($fpPath === $dpClean || strpos($fpPath . '/', $dpClean . '/') === 0 || strpos($fpPath, $dpClean . '/') === 0) {
                                        $storage->removeFolderPermission($storageId, $fp['folder_path'], (int)($fp['user_id'] ?? 0));
                                    }
                                }
                            }
                        } catch (\Throwable $e) {}
                    }
                } else {
                    $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . ($result['error'] ?? 'Unknown error');
                }
            } catch (\Throwable $e) {
                if (!$this->jobFileExists($jobId)) return;
                $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . $e->getMessage();
            }
            

            // 진행률 파일 정리
            if ($progressFile && is_file($progressFile)) {
                @unlink($progressFile);
            }
            $job['progress']['done_items'] = $i + 1;
            $this->saveProgress($job);
        }
        
        $this->finishJob($job, $lastMainCheck);
        } finally {
            $this->flushActivity($logBuf, true);
        }
    }
    
    // ===== 복구 실행 =====
    
    private function executeRestore(array $job): void {
        $jobId = $job['id'];
        $params = $job['params'];
        $items = $params['items'] ?? [];

        // ★ (2026-11-04, 55차 점검) 복원 활동 기록 — 화면의 복원(한 개·선택·전체)은 모두 이 작업 큐라 '복원' 기록이 한 건도 안 남았음(직접 trash_restore 만 남김, D4). 그 기록과 같은 칸으로 한 줄(성공 항목만)
        $activityLog = null;
        $logBuf = null;
        if ($this->auth) {
            $logBuf = new JobActivityBuffer();
            register_shutdown_function(function () use ($logBuf) { $this->flushActivity($logBuf, true); });
            $activityLog = new ActivityLog($logBuf, $this->auth);
        }
        $rsStorage = new Storage();
        $rsStorageMemo = [];

        $lastMainCheck = 0.0;
        try {
        for ($i = 0; $i < count($items); $i++) {
            // ★ (2026-11-02 14:55, 44차 점검) 작업 파일 대신 메모리 $job + 작은 진행 파일(executeCopyMove 와 같음)
            $this->flushActivity($logBuf, !empty($items[$i]['is_dir']) || !empty($items[$i]['isDir']));   // ★ (2026-11-04, 55차 점검) 폴더 앞에서는 모아 둔 기록을 바로 씀(executeDelete 와 같음)
            if ($this->jobShouldStop($job, $lastMainCheck)) return;
            
            $item = $items[$i];
            
            $job['progress']['done_items'] = $i;
            $job['progress']['current_file'] = $item['name'] ?? '';
            $this->saveProgress($job);
            
            // 진행률 파일 생성
            $progressFile = $this->jobsDir . '/restore_progress_' . md5($jobId . '_' . $i) . '.tmp';
            
            try {
                // ★ (2026-10-29 23:57, 24차 점검) 원격 복원 인덱스용으로 휴지통 기록을 먼저 읽어 둠(복원하면 기록이 지워짐)
                $_rsRec = null;
                try { foreach ((JsonDB::getInstance()->load('trash') ?: []) as $_t) { if (($_t['id'] ?? null) === ($item['id'] ?? '')) { $_rsRec = $_t; break; } } } catch (\Throwable $e) {}
                $result = $this->fileManager->restoreFromTrash($item['id'], $progressFile);
                // ★ (2026-10-29 23:57, 24차 점검) 원격으로 복원하면 실제 놓인 이름(같은 이름 있으면 'X (1)')으로 한 행 넣음 — 로컬은 restoreFromTrash 안의 reindexPath 가 처리, 원격은 종전엔 아무것도 안 넣었음(5회차 재검토)
                if (($result['success'] ?? false) && !empty($result['remote']) && $_rsRec && !empty($result['restored_path'])) {
                    try {
                        $_rsDir = dirname(str_replace('\\', '/', (string)($_rsRec['original_path'] ?? '')));
                        $_rsPath = (($_rsDir === '.' || $_rsDir === '/' || $_rsDir === '') ? '' : $_rsDir . '/') . $result['restored_path'];
                        $_rsIx = FileIndex::getInstance();
                        if ($_rsIx->isAvailable()) $_rsIx->addFile((int)($_rsRec['storage_id'] ?? 0), $_rsPath, ['name' => basename($_rsPath), 'size' => !empty($_rsRec['is_dir']) ? 0 : max(0, (int)($_rsRec['size'] ?? 0)), 'modified' => time(), 'is_dir' => !empty($_rsRec['is_dir']) ? 1 : 0]);
                        if ($_rsIx->isAvailable()) {
                            // ★ (2026-11-04, 55차 점검) 상위 폴더가 없어져 원격 쓰기가 다시 만든 조상 폴더도 색인 행이 없으면 넣음(있는 행은 그대로) — 색인 즉답 목록에서 복원한 항목의 폴더가 안 보였음(D5)
                            self::indexRemoteAncestors((int)($_rsRec['storage_id'] ?? 0), $_rsPath);
                            // ★ (2026-11-04, 55차 점검) 폴더 복원은 하위도 원격 목록으로 색인(X-1 — 종전엔 폴더 행만이라 빈 폴더로 보였음). 목록 실패면 종전처럼 폴더 행만
                            if (!empty($_rsRec['is_dir'])) self::indexRemoteTree($this->fileManager, (int)($_rsRec['storage_id'] ?? 0), $_rsPath);
                        }
                    } catch (\Throwable $e) {}
                }

                if (!$this->jobFileExists($jobId)) return;

                if ($result['success'] ?? false) {
                    $job['progress']['done_size'] += $item['size'] ?? 0;
                    $job['progress']['done_success'] = ($job['progress']['done_success'] ?? 0) + 1;
                    if ($activityLog && $_rsRec) {   // ★ (2026-11-04, 55차 점검) 복원 기록(위 설명 — trash_restore 와 같은 칸, 스토리지 이름은 executeDelete 처럼 조회)
                        $rsSid = (int)($_rsRec['storage_id'] ?? 0);
                        if (!array_key_exists($rsSid, $rsStorageMemo)) $rsStorageMemo[$rsSid] = $rsStorage->getStorageById($rsSid);
                        $activityLog->log(ActivityLog::TYPE_RESTORE, [
                            'storage_id' => $rsSid,
                            'storage_name' => $rsStorageMemo[$rsSid]['name'] ?? '',
                            'path' => $_rsRec['original_path'] ?? '',
                            'filename' => $_rsRec['name'] ?? ''
                        ]);
                    }
                } else {
                    $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . ($result['error'] ?? 'Unknown error');
                }
            } catch (\Throwable $e) {
                if (!$this->jobFileExists($jobId)) return;
                $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . $e->getMessage();
            }
            
            // 진행률 파일 정리
            if (is_file($progressFile)) {
                @unlink($progressFile);
            }
            
            $job['progress']['done_items'] = $i + 1;
            $this->saveProgress($job);
        }

        $this->finishJob($job, $lastMainCheck);
        } finally {
            $this->flushActivity($logBuf, true);   // ★ (2026-11-04, 55차 점검) 모아 둔 복원 기록 쓰기(executeDelete 와 같음)
        }
    }

    /**
     * ★ (2026-11-04, 55차 점검) 원격에 놓인 항목의 조상 폴더 중 색인 행이 없는 것만 폴더 행으로 넣음(D5 — 원격 쓰기가 상위 폴더를 다시 만들어도 색인엔 안 들어갔음). 있는 행은 건드리지 않음
     */
    public static function indexRemoteAncestors(int $storageId, string $relPath): void {
        if (!class_exists('FileIndex')) return;
        $ix = FileIndex::getInstance();
        if (!$ix->isAvailable()) return;
        $segs = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $relPath), '/')), 'strlen'));
        array_pop($segs);
        $cur = '';
        foreach ($segs as $sg) {
            $cur = $cur === '' ? $sg : $cur . '/' . $sg;
            try {
                $st = $ix->getDb()->prepare('SELECT 1 FROM files WHERE storage_id = :s AND filepath = :p LIMIT 1');
                $st->bindValue(':s', $storageId, SQLITE3_INTEGER);
                $st->bindValue(':p', $cur, SQLITE3_TEXT);
                $rw = $st->execute();
                if ($rw && $rw->fetchArray()) continue;
                $ix->addFile($storageId, $cur, ['is_dir' => 1, 'size' => 0, 'modified' => time()]);
            } catch (\Throwable $e) {}
        }
    }

    // ===== 휴지통 영구삭제 실행 =====
    
    private function executeTrashDelete(array $job): void {
        $jobId = $job['id'];
        $params = $job['params'];
        $items = $params['items'] ?? [];
        
        $lastMainCheck = 0.0;
        for ($i = 0; $i < count($items); $i++) {
            // ★ (2026-11-02 14:55, 44차 점검) 작업 파일 대신 메모리 $job + 작은 진행 파일(executeCopyMove 와 같음)
            if ($this->jobShouldStop($job, $lastMainCheck)) return;
            
            $item = $items[$i];
            
            $job['progress']['done_items'] = $i;
            $job['progress']['current_file'] = $item['name'] ?? '';
            $this->saveProgress($job);
            
            // 진행률 파일 생성 (폴더인 경우)
            $progressFile = '';
            if (!empty($item['is_dir'])) {
                $progressFile = $this->jobsDir . '/delete_progress_' . md5($item['id']) . '.tmp';
            }
            
            try {
                $result = $this->fileManager->deleteFromTrash($item['id'], $progressFile);
                
                if (!$this->jobFileExists($jobId)) return;
                
                if ($result['success'] ?? false) {
                    $job['progress']['done_size'] += $item['size'] ?? 0;
                    $job['progress']['done_success'] = ($job['progress']['done_success'] ?? 0) + 1;
                } else {
                    $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . ($result['error'] ?? 'Unknown error');
                }
            } catch (\Throwable $e) {
                if (!$this->jobFileExists($jobId)) return;
                $job['progress']['errors'][] = ($item['name'] ?? '') . ': ' . $e->getMessage();
            }
            
            // 진행률 파일 정리
            if ($progressFile && is_file($progressFile)) {
                @unlink($progressFile);
            }
            
            $job['progress']['done_items'] = $i + 1;
            $this->saveProgress($job);
        }
        
        $this->finishJob($job, $lastMainCheck);
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
        
        // ★ (2026-11-01 09:46, 38차 점검) 세션 ID 는 응답에서 뺌 — job_status 가 그대로 화면에 보내 HttpOnly 세션 쿠키 값을 JS 가 읽을 수 있었음(화면은 이 값을 안 씀).
        //   작업 파일에는 그대로 저장(진행률 파일 이름 찾기 mergeCopyProgress 는 위에서 원본 값으로 이미 씀)
        unset($job['session_id']);
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
                    $pf = $this->jobsDir . '/delete_progress_' . md5($jobId . '|' . ($currentItem['path'] ?? ('#' . $doneItems))) . '.tmp';   // ★ (2026-11-01 01:13, 35차 전체 점검) 위 execute 쪽과 같은 이름 규칙
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
        // ★ (2026-11-02 10:29, 42차 점검) 영어 고정 문구였음 → 번역(이 함수는 api.php job_cancel 에서만 부름 — lang.php 로드됨)
        if (!$job) return ['success' => false, 'error' => __('job_not_found', '작업을 찾을 수 없습니다.')];
        if ($job['user_id'] !== $userId) return ['success' => false, 'error' => __('no_permission', '권한이 없습니다.')];
        if ($job['status'] === 'completed' || $job['status'] === 'failed') {
            return ['success' => false, 'error' => __('job_already_finished', '이미 끝난 작업입니다.')];
        }
        
        $job['status'] = 'cancelled';
        $job['completed_at'] = date('Y-m-d H:i:s');
        $this->saveJob($job);
        // ★ (2026-11-02 14:55, 44차 점검) 실행 쪽·FileManager 청크 확인이 보는 작은 취소 표시(작업 파일 저장 뒤에 — 표시가 보이면 작업 파일은 이미 cancelled). FileManager::isCancelled 가 읽는 형식
        @file_put_contents($this->cancelFlagFile($jobId), '{"status":"cancelled"}');
        
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
            if (is_array($job)) $job = $this->overlayProgress($job);   // ★ (2026-11-02 14:55, 44차 점검) 실행 중이면 진행 파일의 progress(loadJob 과 같음)
            
            if ($activeOnly && in_array($job['status'], ['completed', 'failed', 'cancelled'])) {
                continue;
            }
            
            // 실시간 진행률 병합
            if ($job['status'] === 'running') {
                if (in_array($job['type'], ['copy', 'move'])) {
                    $this->mergeCopyProgress($job);
                } elseif (in_array($job['type'], ['delete', 'trash_empty', 'trash_delete', 'restore'])) {   // ★ (2026-11-01 01:13, 35차 전체 점검) getStatus 와 같게(삭제·복구도 실시간 진행률)
                    $this->mergeDeleteProgress($job);
                }
            }
            
            unset($job['session_id']);   // ★ (2026-11-01 09:46, 38차 점검) getStatus 와 같이 응답에서 세션 ID 를 뺌(job_list 로 화면에 나갔음)
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
        // ★ (2026-10-29 15:35, 21차 재점검) 실행 중(running) 작업이 있는 사용자 — 그 사용자의 대기 작업은 차례를 기다리는 중이라 오래 둠(아래)
        $runningUsers = [];
        foreach ($files as $rf) {
            $rj = @json_decode(@file_get_contents($rf), true);
            if (is_array($rj) && ($rj['status'] ?? '') === 'running') $runningUsers[(int)($rj['user_id'] ?? 0)] = true;
        }
        
        foreach ($files as $file) {
            $job = @json_decode(@file_get_contents($file), true);
            // ★ (2026-11-02 09:02, 41차 점검) 못 읽은(빈·깨진) 작업 파일은 1시간 넘게 안 바뀐 것만 지움 — 종전엔 바로 지워, Windows 에서 saveJob 의 직접 쓰기 폴백(자르고 쓰는 순간)과
            //   겹치면 실행 중 작업 파일이 사라졌음(실행 쪽은 다음 loadJob 에서 조용히 멈춤). 실행 중이면 진행 저장으로 수정 시각이 계속 바뀜
            //   ★ (2026-11-02 15:20, 44차 재검토) 44차부터 실행 중 진행은 작은 진행 파일(<작업>.json.progress)에만 써서 작업 파일 수정 시각이 시작 시각에 머묾 → 진행 파일 수정 시각이 더 최근이면 그걸로 봄(1시간 넘게 도는 작업을 순간 못 읽었다고 지우지 않게)
            //   ★ (2026-11-02 16:42, 45차 재확인) 한 항목이 1시간 넘게 걸리면 진행 파일 시각도 오래될 수 있어 — 실행 중이면 잡고 있는 작업 잠금(.lock, execute)을 LOCK_NB 로 확인해 못 잡으면 건너뜀
            if (!$job) {
                $jfM = max((int)@filemtime($file), (int)@filemtime($file . '.progress'));
                if ($jfM < time() - 3600) {
                    $jfBusy = false;
                    if (is_file($file . '.lock') && ($jfLk = @fopen($file . '.lock', 'r'))) { if (@flock($jfLk, LOCK_EX | LOCK_NB)) @flock($jfLk, LOCK_UN); else $jfBusy = true; fclose($jfLk); }
                    if (!$jfBusy) { @unlink($file); $cleaned++; }
                }
                continue;
            }
            
            // 완료/실패/취소된 작업이 maxAge 이상 지나면 삭제
            if (in_array($job['status'] ?? '', ['completed', 'failed', 'cancelled'])) {
                $completedAt = strtotime($job['completed_at'] ?? '');
                if ($completedAt && (time() - $completedAt) > $maxAge) {
                    @unlink($file);
                    $cleaned++;
                }
            }
            
            // pending 상태로 오래 방치된 작업 (실행 안 됨)
            // ★ (2026-10-29 10:10, 20차 점검) 5분 → 12시간, 지우지 않고 '실패' 로 — pending 은 동시 실행 한도(max_running_jobs) 때문에 차례를 기다리는
            //   정상 상태이기도 해서, 종전엔 앞 작업이 5분 넘게 걸리면 기다리던 복사·이동이 지워지고 화면엔 '완료' 로 보였음(재현).
            //   창을 닫았다 열면 pending 작업은 다시 실행되므로(_restoreActiveJobs) 오래 둬도 됨. 실패로 바꾼 것은 위의 완료 작업 정리 규칙으로 지워짐
            // ★ (2026-10-29 15:35, 21차 재점검) 같은 사용자의 실행 중 작업이 없는데 5분 넘게 대기면 버려진 작업(만든 뒤 실행 요청이 안 옴)으로 보고 종전처럼 정리 —
            //   20차엔 모두 12시간 남겨, 창을 다시 열 때(_restoreActiveJobs 가 대기 작업을 실행) 몇 시간 전 삭제·이동이 저절로 실행될 수 있었음(재현)
            if (($job['status'] ?? '') === 'pending') {
                $createdAt = strtotime($job['created_at'] ?? '');
                $pendQueued = !empty($runningUsers[(int)($job['user_id'] ?? 0)]);
                $pendPolled = (int)@filemtime($file) > time() - 60;   // ★ (2026-11-12 17:34, 101차 TRA-2) 1분 안에 화면이 실행을 다시 물었으면(touchQueued) 버려진 작업이 아님 —
                //   종전엔 앞 작업이 막 끝나 10초 재시도를 기다리는 사이 누구든 job_list 를 부르면 지워지고 화면엔 '완료'로 보였음(재현). 실행 요청이 없던 작업은 파일 시각 = 만든 시각이라 종전과 같이 5분에 지움
                if (!$pendQueued && !$pendPolled && $createdAt && (time() - $createdAt) > 300) {
                    @unlink($file);   // 버려진 대기 작업 — 종전(20차 전)과 같이 지움
                    $cleaned++;
                    continue;
                }
                if ($createdAt && (time() - $createdAt) > 43200) {
                    $job['status'] = 'failed';
                    $job['completed_at'] = date('Y-m-d H:i:s');
                    $job['progress']['errors'][] = 'Job not started (12h)';
                    $this->saveJob($job);
                    $cleaned++;
                }
            }
            
            // running 상태로 12시간 이상 (비정상 종료) → failed로 변경
            // ★ (2026-11-02 09:02, 41차 점검) 시간 대신 execute() 의 작업 잠금으로 판정 — 종전엔 ①12시간 넘게 정상으로 도는 작업(수 TB 복사)도 'failed' 로 바꿔
            //   작업은 계속 도는데 화면은 멈추고 취소는 'Job already finished' 로 거부됐고(재현) ②job_execute 를 처리하던 PHP 가 죽으면(Apache 재시작·FastCGI 시간 초과)
            //   12시간 동안 'running' 으로 남아 진행 막대가 멈춘 채였음(재현). → 잠금이 잡혀 있으면(실행 중) 얼마가 지났든 둠(멈췄으면 사용자가 취소 가능),
            //   잠금을 잡을 수 있으면(실행하는 프로세스 없음) 잠금을 쥔 채 다시 읽어 아직 running 일 때만 '중단됨(failed)'. 잠금 파일을 못 열면 종전 12시간 규칙
            if (($job['status'] ?? '') === 'running') {
                $jlFile = $file . '.lock';
                $jlFp = @fopen($jlFile, 'c');
                if ($jlFp) {
                    if (@flock($jlFp, LOCK_EX | LOCK_NB)) {
                        $cur = @json_decode((string)@file_get_contents($file), true);
                        if (is_array($cur)) $cur = $this->overlayProgress($cur);   // ★ (2026-11-02 14:55, 44차 점검) 중단 표시에 마지막 진행(종전엔 작업 파일에 늘 있었음)
                        if (is_array($cur) && ($cur['status'] ?? '') === 'running') {
                            $cur['status'] = 'failed';
                            $cur['completed_at'] = date('Y-m-d H:i:s');
                            $cur['progress']['errors'][] = 'Job interrupted (process ended)';
                            $this->saveJob($cur);
                            $cleaned++;
                        }
                        @flock($jlFp, LOCK_UN);
                        fclose($jlFp);
                        @unlink($jlFile);
                    } else {
                        fclose($jlFp);   // 실행 중 — 그대로 둠
                    }
                    continue;
                }
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
        // ★ (2026-10-31 13:40, 34차 후속 개선) restore_progress_*.tmp 는 정리 대상이 아니어서 복구 도중 프로세스가 끝나면 영구히 남았음 → 같은 1시간 기준으로 함께 정리
        $tmpFiles = array_merge(@glob($this->jobsDir . '/delete_progress_*.tmp') ?: [], @glob($this->jobsDir . '/restore_progress_*.tmp') ?: []);
        if ($tmpFiles) {
            foreach ($tmpFiles as $f) {
                if (@filemtime($f) < time() - 3600) @unlink($f);
            }
        }
        // ★ (2026-10-31 13:40, 34차 후속 개선) execute() 잠금 파일 중 작업 파일이 이미 없는 것(프로세스가 죽어 못 지운 것) 정리
        foreach ((@glob($this->jobsDir . '/job_*.json.lock') ?: []) as $lf) {
            if (!is_file(substr($lf, 0, -5))) @unlink($lf);
        }
        // ★ (2026-11-02 14:55, 44차 점검) 작업 파일이 지워진 진행·취소 표시 파일 정리(위 saveProgress 설명). 남은 진행 임시 파일은 1시간 지난 것만
        foreach (array_merge(@glob($this->jobsDir . '/job_*.json.progress') ?: [], @glob($this->jobsDir . '/job_*.json.cancel') ?: []) as $sf) {
            if (!is_file(substr($sf, 0, strrpos($sf, '.')))) @unlink($sf);
        }
        foreach ((@glob($this->jobsDir . '/job_*.json.progress.tmp.*') ?: []) as $sf) {
            if (@filemtime($sf) < time() - 3600) @unlink($sf);
        }
        
        return $cleaned;
    }
    
    // ===== 파일 I/O =====
    
    // ★ (2026-11-12 17:34, 101차 TRA-2) 대기 응답(job_execute queued) 때 작업 파일 수정 시각만 지금으로 — cleanup 이 '화면이 아직 실행을 묻는 중'으로 봄(내용은 그대로)
    public function touchQueued(string $jobId): void {
        $f = $this->getJobFile($jobId);
        if (is_file($f)) @touch($f);
    }
    
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
        $job = @json_decode($data, true);
        return is_array($job) ? $this->overlayProgress($job) : $job;   // ★ (2026-11-02 14:55, 44차 점검) 실행 중이면 진행 파일의 progress 를 얹음(아래 saveProgress 설명)
    }

    // ★ (2026-11-02 14:55, 44차 점검) 작업 큐가 항목마다 작업 파일(params.items 전체)을 4번 읽고 2번 써서 항목 수의 제곱으로 느려졌음(G3#1, 1만 항목 ≈ 16분) →
    //   실행 중 진행 상태는 작은 진행 파일(<작업>.json.progress, progress 만)에 쓰고 작업 파일은 시작·끝·취소 때만 씀. 읽는 쪽(getStatus·listJobs·cancel·cleanup)은
    //   상태가 running 일 때 진행 파일의 progress 를 얹어 종전과 같은 값을 봄. 취소는 cancel() 이 작업 파일 저장 뒤 작은 취소 표시 파일(<작업>.json.cancel)도 만들어
    //   실행 쪽·FileManager 청크 확인이 그 파일만 봄(작업 파일은 1초마다 한 번 더 확인). 두 파일은 작업 파일이 지워질 때 cleanup 이 함께 지움
    private function progressFile(string $jobId): string {
        return $this->getJobFile($jobId) . '.progress';
    }
    private function cancelFlagFile(string $jobId): string {
        return $this->getJobFile($jobId) . '.cancel';
    }
    private function overlayProgress(array $job): array {
        if (($job['status'] ?? '') !== 'running' || !isset($job['id'])) return $job;
        $pf = $this->progressFile((string)$job['id']);
        if (!is_file($pf)) return $job;
        $p = @json_decode((string)@file_get_contents($pf), true);
        if (is_array($p) && isset($p['progress']) && is_array($p['progress'])) $job['progress'] = $p['progress'];
        return $job;
    }
    /** 진행 파일 쓰기 — 최종 상태는 늘 saveJob 이 작업 파일에 쓰므로 여기선 직접 쓰기 폴백 없이(읽는 쪽이 반쯤 쓴 파일을 보지 않게) rename 이 끝내 안 되면 이번 것은 건너뜀 */
    private function saveProgress(array $job): void {
        $json = json_encode(['progress' => $job['progress'] ?? []], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) return;
        $file = $this->progressFile((string)$job['id']);
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json) === false) { @unlink($tmp); return; }
        for ($i = 0; $i < 5; $i++) {
            if (@rename($tmp, $file)) return;
            usleep(40000);
        }
        @unlink($tmp);
    }
    /**
     * 항목 시작 전 확인 — 종전 'loadJob → 없거나 cancelled 면 return' 과 같은 판정. true 면 실행을 멈춤.
     *   취소면 지금까지의 진행을 작업 파일에 저장(saveJob 이 cancelled·completed_at 을 지킴 — 종전 마지막 진행 저장과 같은 결과)
     */
    private function jobShouldStop(array $job, float &$lastMainCheck): bool {
        $file = $this->getJobFile((string)$job['id']);
        $flag = $this->cancelFlagFile((string)$job['id']);
        clearstatcache(true, $file);
        clearstatcache(true, $flag);
        if (!is_file($file)) return true;
        $cancelled = is_file($flag);
        if (!$cancelled && microtime(true) - $lastMainCheck >= 1.0) {
            $lastMainCheck = microtime(true);
            $data = @file_get_contents($file);
            $cur = $data ? @json_decode($data, true) : null;
            if (!is_array($cur)) return true;
            $cancelled = ($cur['status'] ?? '') === 'cancelled';
        }
        if ($cancelled) {
            $this->saveJob($job);
            return true;
        }
        return false;
    }
    /**
     * 완료 처리 — 종전 '다시 읽어 running 이면 completed 저장' 과 같음. 그사이 취소됐으면 메모리의 마지막 진행을 저장(cancelled·completed_at 은 saveJob 이 지킴 —
     *   취소 뒤엔 읽는 쪽이 진행 파일을 얹지 않으므로 작업 파일에 마지막 진행이 있어야 함. 종전엔 항목 끝 진행 저장이 이 역할을 했음)
     */
    private function finishJob(array $job, float &$lastMainCheck): void {
        $file = $this->getJobFile((string)$job['id']);
        clearstatcache(true, $file);
        $data = is_file($file) ? @file_get_contents($file) : false;
        $cur = $data ? @json_decode($data, true) : null;
        if (!is_array($cur)) return;
        if (($cur['status'] ?? '') === 'running') {
            $job['status'] = 'completed';
            $job['completed_at'] = date('Y-m-d H:i:s');
            $this->saveJob($job);
        } elseif (($cur['status'] ?? '') === 'cancelled') {
            $this->saveJob($job);
        }
    }
    /** 실행 중 '작업 파일이 아직 있나' — 종전 항목 끝의 'loadJob → 없으면 return' 자리 */
    private function jobFileExists(string $jobId): bool {
        $file = $this->getJobFile($jobId);
        clearstatcache(true, $file);
        return is_file($file);
    }
    /** 활동 기록 모아 쓰기 — 1초 또는 100건마다, 그리고 작업 끝(finally)에 한 번에 추가(JsonDB::insertMany — 행 내용·created_at·번호 규칙은 한 건씩 넣을 때와 같음) */
    private function flushActivity(?JobActivityBuffer $buf, bool $force): void {
        if (!$buf || !$buf->rows) return;
        if (!$force && count($buf->rows) < 100 && microtime(true) - $buf->since < 1.0) return;
        $rows = $buf->rows;
        $buf->rows = [];
        // 기록 실패가 작업을 멈추지 않게(종전에도 기록 실패는 그 항목 안에서 끝났음) — 서버 로그에만 남김
        try {
            $group = []; $gKey = null;
            foreach ($rows as $r) {
                $k = $r[0] . '|' . $r[2];
                if ($gKey !== null && $k !== $gKey) { JsonDB::getInstance()->insertMany($group[0][0], array_column($group, 1), $group[0][2]); $group = []; }
                $gKey = $k;
                $group[] = $r;
            }
            if ($group) JsonDB::getInstance()->insertMany($group[0][0], array_column($group, 1), $group[0][2]);
        } catch (\Throwable $e) {
            error_log('[JobManager] activity log flush failed: ' . $e->getMessage());
        }
    }
    
    private function saveJob(array $job): void {
        $file = $this->getJobFile($job['id']);
        // ★ (2026-10-29 11:14, 20차 점검) 실행 중 진행 저장이 그사이 들어온 '취소' 를 덮지 않게 — 종전엔 다시 읽고 저장하는 사이에 취소가 오면 사라져 작업이 계속됐음
        if (in_array($job['status'] ?? '', ['running', 'pending'], true) && is_file($file)) {
            $cur = @json_decode((string)@file_get_contents($file), true);
            if (is_array($cur) && ($cur['status'] ?? '') === 'cancelled') {
                $job['status'] = 'cancelled';
                $job['completed_at'] = $cur['completed_at'] ?? date('Y-m-d H:i:s');
            }
        }
        // ★ (2026-11-01 09:46, 38차 점검) JsonDB 저장 방식과 맞춤 — ①임시 파일 이름이 늘 '<작업>.json.tmp' 하나라 작업 진행 저장과 취소 요청 저장이 겹치면 같은 tmp 에 섞여
        //   깨진 JSON 이 올라갔음(→ loadJob null, 'Job not found') → 요청마다 다른 tmp 이름 ②rename 실패(윈도우에서 job_status 가 파일을 잠깐 열고 있을 때)를 무시해
        //   마지막 'completed' 저장이 빠지면 'running' 으로 남았음 → 몇 번 다시 시도, 그래도 안 되면 직접 쓰기 ③깨진 UTF-8 이 섞이면 json_encode 가 false 라 0바이트 파일이 됐음 → 대체 문자로 인코딩, 실패하면 쓰지 않음
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            error_log('[JobManager] json_encode failed for job ' . ($job['id'] ?? '?') . ' — not written');
            return;
        }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json) === false) { @unlink($tmp); return; }
        for ($i = 0; $i < 5; $i++) {
            if (@rename($tmp, $file)) return;
            usleep(40000);
        }
        @file_put_contents($file, $json);   // rename 이 끝내 안 되면 직접 쓰기(JsonDB 와 같은 폴백)
        @unlink($tmp);
    }
}

/**
 * ★ (2026-11-02 14:55, 44차 점검) 작업 큐 활동 기록을 모으는 자리 — ActivityLog 는 $db->insert() 만 부르므로 이 객체를 db 로 넘겨 행을 받아 두고
 *   JobManager::flushActivity 가 insertMany 로 한 번에 넣음. 종전엔 항목마다 activity_logs(최대 1만 행) 전체를 다시 써 1건 ≈ 90ms 였음(G3#4)
 */
class JobActivityBuffer {
    public array $rows = [];
    public float $since = 0.0;
    public function insert(string $name, array $record, int $maxSize = 0): int {
        if (!$this->rows) $this->since = microtime(true);
        $this->rows[] = [$name, $record, $maxSize];
        return 0;
    }
}
