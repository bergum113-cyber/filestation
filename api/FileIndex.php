<?php
/**
 * FileIndex - SQLite 기반 파일 인덱스
 * 빠른 검색을 위한 파일 목록 캐시
 */
class FileIndex {
    private static ?FileIndex $instance = null;
    private $db = null;  // SQLite3 또는 null
    private string $dbPath;
    private bool $available = false;
    
    private function __construct() {
        // SQLite3 확장 사용 가능 여부 확인
        if (!class_exists('SQLite3')) {
            $this->available = false;
            return;
        }
        
        $this->dbPath = DATA_PATH . '/file_index.db';
        
        try {
            $this->initDatabase();
            // DB 무결성 간단 체크
            $test = @$this->db->querySingle("SELECT COUNT(*) FROM files LIMIT 1");
            $this->available = true;
        } catch (Throwable $e) {
            // DB 손상 시 자동 재생성
            error_log("FileIndex DB corrupted, recreating: " . $e->getMessage());
            $this->destroyDb();
            try {
                $this->initDatabase();
                $this->available = true;
            } catch (Throwable $e2) {
                $this->available = false;
                error_log("FileIndex DB recreation failed: " . $e2->getMessage());
            }
        }
    }
    
    /**
     * 손상된 DB 파일 제거
     */
    private function destroyDb(): void {
        if ($this->db) {
            try { $this->db->close(); } catch (Throwable $e) {}
            $this->db = null;
        }
        @unlink($this->dbPath);
        @unlink($this->dbPath . '-wal');
        @unlink($this->dbPath . '-shm');
    }
    
    public static function getInstance(): FileIndex {
        if (self::$instance === null) {
            self::$instance = new FileIndex();
        }
        return self::$instance;
    }
    
    /**
     * SQLite3 사용 가능 여부
     */
    public function isAvailable(): bool {
        return $this->available;
    }
    
    /**
     * DB 인스턴스 반환 (디버깅용)
     */
    public function getDb() {
        return $this->db;
    }
    
    private function initDatabase(): void {
        $isNew = !file_exists($this->dbPath);
        $this->db = new SQLite3($this->dbPath);
        $this->db->busyTimeout(5000);
        
        // WAL 모드 (동시 접근 성능 향상)
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA synchronous=NORMAL');
        $this->db->exec('PRAGMA cache_size=-65536'); // 64MB 캐시
        $this->db->exec('PRAGMA temp_store=MEMORY');
        $this->db->exec('PRAGMA mmap_size=268435456'); // 256MB mmap
        
        if ($isNew) {
            $this->createTables();
        }
    }
    
    private function createTables(): void {
        // 파일 인덱스 테이블
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                storage_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                filepath TEXT NOT NULL,
                is_dir INTEGER NOT NULL DEFAULT 0,
                size INTEGER NOT NULL DEFAULT 0,
                modified TEXT,
                extension TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(storage_id, filepath)
            )
        ');
        
        // 인덱스 생성 (검색 속도 향상)
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_storage ON files(storage_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_filename ON files(filename COLLATE NOCASE)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_filepath ON files(filepath)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_extension ON files(extension)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_storage_filepath ON files(storage_id, filepath)');
        
        // 메타 정보 테이블
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS meta (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ');
    }
    
    /**
     * 파일 추가/업데이트
     */
    public function addFile(int $storageId, string $filepath, array $info = []): bool {
        if (!$this->available) return false;
        
        $stmt = $this->db->prepare('
            INSERT OR REPLACE INTO files (storage_id, filename, filepath, is_dir, size, modified, extension)
            VALUES (:storage_id, :filename, :filepath, :is_dir, :size, :modified, :extension)
        ');
        
        $filename = basename($filepath);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
        $stmt->bindValue(':filepath', $filepath, SQLITE3_TEXT);
        $stmt->bindValue(':is_dir', $info['is_dir'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':size', $info['size'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':modified', $info['modified'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':extension', strtolower($extension), SQLITE3_TEXT);
        
        return $stmt->execute() !== false;
    }
    
    /**
     * 파일 삭제
     */
    public function removeFile(int $storageId, string $filepath): bool {
        if (!$this->available) return false;
        
        $stmt = $this->db->prepare('DELETE FROM files WHERE storage_id = :storage_id AND filepath = :filepath');
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $stmt->bindValue(':filepath', $filepath, SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }
    
    /**
     * 폴더 삭제 (하위 항목 포함)
     */
    public function removeFolder(int $storageId, string $folderPath): bool {
        if (!$this->available) return false;
        
        // 폴더 자체 삭제
        $this->removeFile($storageId, $folderPath);
        
        // 하위 항목 삭제
        $pattern = $folderPath . '/%';
        $stmt = $this->db->prepare('DELETE FROM files WHERE storage_id = :storage_id AND filepath LIKE :pattern');
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $stmt->bindValue(':pattern', $pattern, SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }
    
    /**
     * 파일/폴더 이동 (경로 업데이트)
     */
    public function moveFile(int $storageId, string $oldPath, string $newPath): bool {
        if (!$this->available) return false;
        
        // 파일 자체 이동
        $stmt = $this->db->prepare('
            UPDATE files 
            SET filepath = :new_path, filename = :filename 
            WHERE storage_id = :storage_id AND filepath = :old_path
        ');
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $stmt->bindValue(':old_path', $oldPath, SQLITE3_TEXT);
        $stmt->bindValue(':new_path', $newPath, SQLITE3_TEXT);
        $stmt->bindValue(':filename', basename($newPath), SQLITE3_TEXT);
        $stmt->execute();
        
        // 하위 항목 이동 (폴더인 경우)
        $oldPattern = $oldPath . '/%';
        $oldLen = strlen($oldPath);
        
        $selectStmt = $this->db->prepare('SELECT id, filepath FROM files WHERE storage_id = :storage_id AND filepath LIKE :pattern');
        $selectStmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $selectStmt->bindValue(':pattern', $oldPattern, SQLITE3_TEXT);
        $result = $selectStmt->execute();
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $newFilePath = $newPath . substr($row['filepath'], $oldLen);
            $updateStmt = $this->db->prepare('UPDATE files SET filepath = :new_path WHERE id = :id');
            $updateStmt->bindValue(':new_path', $newFilePath, SQLITE3_TEXT);
            $updateStmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $updateStmt->execute();
        }
        
        return true;
    }
    
    /**
     * 검색
     * @param string $query 검색어
     * @param int|array|null $storageIds 단일 ID, ID 배열, 또는 null(전체)
     * @param int $limit 결과 제한 (0 = 무제한)
     */
    public function search(string $query, $storageIds = null, int $limit = 500): array {
        if (!$this->available) return [];
        
        // 와일드카드 변환: * → %, ? → _
        // 사용자가 이미 *나 ?를 사용했으면 변환
        if (strpos($query, '*') !== false || strpos($query, '?') !== false) {
            // SQL 특수문자 이스케이프 (%, _, \)
            $query = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            // 와일드카드 변환
            $query = str_replace(['*', '?'], ['%', '_'], $query);
            $useEscape = true;
        } else {
            // 일반 검색: 양쪽에 % 추가
            $query = '%' . $query . '%';
            $useEscape = false;
        }
        
        $escapeSql = $useEscape ? " ESCAPE '\\'" : "";
        $limitSql = $limit > 0 ? " LIMIT " . (int)$limit : ""; // 0이면 무제한
        
        if ($storageIds !== null) {
            // 배열이 아니면 배열로 변환
            if (!is_array($storageIds)) {
                $storageIds = [$storageIds];
            }
            
            if (empty($storageIds)) {
                return []; // 허용된 스토리지 없음
            }
            
            // IN 절 생성
            $placeholders = implode(',', array_fill(0, count($storageIds), '?'));
            $stmt = $this->db->prepare("
                SELECT * FROM files 
                WHERE storage_id IN ($placeholders) AND filename LIKE ? COLLATE NOCASE{$escapeSql}
                ORDER BY is_dir DESC, filename ASC
                {$limitSql}
            ");
            
            $paramIndex = 1;
            foreach ($storageIds as $id) {
                $stmt->bindValue($paramIndex++, (int)$id, SQLITE3_INTEGER);
            }
            $stmt->bindValue($paramIndex, $query, SQLITE3_TEXT);
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM files 
                WHERE filename LIKE :query COLLATE NOCASE{$escapeSql}
                ORDER BY is_dir DESC, filename ASC
                {$limitSql}
            ");
            $stmt->bindValue(':query', $query, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        $files = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $files[] = [
                'name' => $row['filename'],
                'path' => $row['filepath'],
                'is_dir' => (bool)$row['is_dir'],
                'size' => (int)$row['size'],
                'modified' => $row['modified'],
                'extension' => $row['extension'],
                'storage_id' => (int)$row['storage_id']
            ];
        }
        
        return $files;
    }
    
    /**
     * 와일드카드 패턴으로 검색 (조건부 일괄삭제용)
     * @param int $storageId 스토리지 ID
     * @param string $basePath 검색 기준 경로 (빈 문자열이면 루트)
     * @param array $patterns 검색 패턴 배열 (*.zip, test*.txt 등)
     * @param string $scope 'recursive' 또는 'current'
     * @param string $type 'all', 'file', 'folder'
     * @param int $limit 최대 결과 수
     */
    public function searchByPatterns(int $storageId, string $basePath, array $patterns, string $scope = 'recursive', string $type = 'all', int $limit = 1000): array {
        if (!$this->available) return [];
        
        // 패턴을 SQL LIKE 패턴으로 변환
        $likePatterns = [];
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if (empty($pattern)) continue;
            
            // 와일드카드를 SQL LIKE로 변환: * → %, ? → _
            $likePattern = str_replace(['*', '?'], ['%', '_'], $pattern);
            // 특수문자 이스케이프 (% _ 제외)
            $likePattern = addcslashes($likePattern, '%_\\');
            $likePattern = str_replace(['\\%', '\\_'], ['%', '_'], $likePattern);
            $likePatterns[] = $likePattern;
        }
        
        if (empty($likePatterns)) return [];
        
        // SQL 쿼리 조건 생성
        $conditions = ['storage_id = :storage_id'];
        $bindings = [];
        
        // 경로 조건
        $normalizedPath = empty($basePath) ? '' : rtrim(str_replace('\\', '/', $basePath), '/');
        
        if ($scope === 'current') {
            // 현재 폴더만 - 직접 자식만 검색
            if (empty($normalizedPath)) {
                // 루트 폴더: filepath에 /가 없는 것 (직접 자식)
                $conditions[] = "filepath NOT LIKE '%/%'";
            } else {
                // 하위 폴더: 정확히 basePath/filename 형태인 것만
                // filepath가 basePath/로 시작하고, 그 이후에 /가 없는 것
                $conditions[] = "(filepath LIKE :path_direct AND filepath NOT LIKE :path_subdir)";
                $bindings[':path_direct'] = $normalizedPath . '/%';
                $bindings[':path_subdir'] = $normalizedPath . '/%/%';
            }
        } else {
            // 재귀 검색
            if (!empty($normalizedPath)) {
                // basePath 하위 전체
                $conditions[] = "(filepath LIKE :path_prefix OR filepath = :path_exact)";
                $bindings[':path_prefix'] = $normalizedPath . '/%';
                $bindings[':path_exact'] = $normalizedPath;
            }
            // 루트에서 재귀는 조건 없음 (전체 검색)
        }
        
        // 타입 조건
        if ($type === 'file') {
            $conditions[] = 'is_dir = 0';
        } elseif ($type === 'folder') {
            $conditions[] = 'is_dir = 1';
        }
        
        // 패턴 조건 (OR로 연결)
        $patternConditions = [];
        foreach ($likePatterns as $i => $lp) {
            $patternConditions[] = "filename LIKE :pattern{$i} ESCAPE '\\'";
            $bindings[":pattern{$i}"] = $lp;
        }
        $conditions[] = '(' . implode(' OR ', $patternConditions) . ')';
        
        $sql = "SELECT * FROM files WHERE " . implode(' AND ', $conditions) . 
               " ORDER BY is_dir DESC, filename ASC LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        
        // 동적 바인딩
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        $files = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $files[] = [
                'name' => $row['filename'],
                'path' => $row['filepath'],
                'is_dir' => (bool)$row['is_dir'],
                'size' => (int)$row['size'],
                'modified' => $row['modified'],
                'storage_id' => (int)$row['storage_id']
            ];
        }
        
        return $files;
    }
    
    /**
     * 스토리지 전체 재인덱싱
     */
    public function rebuildStorage(int $storageId, string $basePath): int {
        if (!$this->available) return 0;
        
        // 트랜잭션 시작 (대량 INSERT 성능 개선)
        $this->db->exec('BEGIN TRANSACTION');
        
        try {
            // 기존 인덱스 삭제
            $stmt = $this->db->prepare('DELETE FROM files WHERE storage_id = :storage_id');
            $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
            $stmt->execute();
            
            // 새로 인덱싱
            $count = 0;
            $this->indexDirectory($storageId, $basePath, $basePath, $count);
            
            // 트랜잭션 커밋
            $this->db->exec('COMMIT');
            
            // 마지막 재구축 시간 업데이트 + 스토리지별 last_sync도 세팅
            // (재구축은 완전 sync와 동일하므로 backgroundRecalc에서 used_size 계산 가능하도록)
            $this->setMeta('last_rebuild', date('Y-m-d H:i:s'));
            $this->setMeta('last_sync', date('Y-m-d H:i:s'));
            $this->setMeta('last_sync_storage_' . $storageId, date('Y-m-d H:i:s'));
            
            // 체크포인트가 있었다면 삭제 (재구축 완료됐으므로)
            $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
            $checkpointFile = $dataDir . DIRECTORY_SEPARATOR . 'index_sync_checkpoint_' . $storageId . '.json';
            if (is_file($checkpointFile)) @unlink($checkpointFile);
            
            return $count;
        } catch (Exception $e) {
            // 오류 시 롤백
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
    
    /**
     * 디렉토리 재귀 인덱싱
     */
    private function indexDirectory(int $storageId, string $dir, string $basePath, int &$count): void {
        if (!is_dir($dir)) return;
        
        try {
            $iterator = new DirectoryIterator($dir);
            foreach ($iterator as $file) {
                if ($file->isDot()) continue;
                
                $filename = $file->getFilename();
                if (substr($filename, 0, 1) === '.') continue;
                
                $fullPath = $file->getPathname();
                $relativePath = substr($fullPath, strlen($basePath) + 1);
                $relativePath = str_replace('\\', '/', $relativePath);
                
                $this->addFile($storageId, $relativePath, [
                    'is_dir' => $file->isDir() ? 1 : 0,
                    'size' => $file->isDir() ? 0 : $file->getSize(),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime())
                ]);
                
                $count++;
                
                // 5000건마다 중간 커밋
                if ($count % 5000 === 0) {
                    $this->db->exec('COMMIT');
                    $this->db->exec('BEGIN TRANSACTION');
                    @set_time_limit(600); // 타이머 리셋 (대용량 인덱싱 보호)
                }
                
                if ($file->isDir()) {
                    $this->indexDirectory($storageId, $fullPath, $basePath, $count);
                }
            }
        } catch (Exception $e) {
            // 접근 불가 폴더 무시
        }
    }
    
    /**
     * 원격 스토리지 재인덱싱 (어댑터 기반)
     */
    public function rebuildStorageRemote(int $storageId, $adapter, ?callable $progressCallback = null): int {
        if (!$this->available) return 0;
        
        $this->db->exec('BEGIN TRANSACTION');
        
        try {
            $stmt = $this->db->prepare('DELETE FROM files WHERE storage_id = :storage_id');
            $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
            $stmt->execute();
            
            $count = 0;
            $this->indexRemoteDirectory($storageId, '', $adapter, $count, $progressCallback);
            
            $this->db->exec('COMMIT');
            $this->setMeta('last_rebuild', date('Y-m-d H:i:s'));
            $this->setMeta('last_sync', date('Y-m-d H:i:s'));
            $this->setMeta('last_sync_storage_' . $storageId, date('Y-m-d H:i:s'));
            
            // 체크포인트가 있었다면 삭제
            $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
            $checkpointFile = $dataDir . DIRECTORY_SEPARATOR . 'index_sync_checkpoint_' . $storageId . '.json';
            if (is_file($checkpointFile)) @unlink($checkpointFile);
            
            return $count;
        } catch (Exception $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
    
    /**
     * 원격 디렉토리 재귀 인덱싱 (어댑터의 list() 사용)
     */
    private function indexRemoteDirectory(int $storageId, string $path, $adapter, int &$count, ?callable $progressCallback = null): void {
        
        try {
            $items = $adapter->list($path);
            foreach ($items as $item) {
                $filename = $item['name'] ?? '';
                if ($filename === '' || substr($filename, 0, 1) === '.') continue;
                
                $relativePath = $item['path'] ?? ltrim($path . '/' . $filename, '/');
                
                $this->addFile($storageId, $relativePath, [
                    'is_dir' => ($item['is_dir'] ?? false) ? 1 : 0,
                    'size' => ($item['is_dir'] ?? false) ? 0 : ($item['size'] ?? 0),
                    'modified' => isset($item['modified']) ? date('Y-m-d H:i:s', $item['modified']) : date('Y-m-d H:i:s')
                ]);
                
                $count++;
                
                // 500건마다 진행률 콜백 호출
                if ($progressCallback && $count % 500 === 0) {
                    $progressCallback($count, $path);
                }
                
                // 5000건마다 중간 커밋 (메모리 부담 감소)
                if ($count % 5000 === 0) {
                    $this->db->exec('COMMIT');
                    $this->db->exec('BEGIN TRANSACTION');
                    @set_time_limit(600); // 타이머 리셋 (대용량 인덱싱 보호)
                }
                
                if ($item['is_dir'] ?? false) {
                    $this->indexRemoteDirectory($storageId, $relativePath, $adapter, $count, $progressCallback);
                }
            }
        } catch (Exception $e) {
            // 접근 불가 폴더 무시
        }
    }
    
    /**
     * 전체 재인덱싱
     */
    public function rebuildAll(array $storages): array {
        if (!$this->available) return [];
        
        $results = [];
        foreach ($storages as $storage) {
            if (empty($storage['path'])) continue;
            $count = $this->rebuildStorage($storage['id'], $storage['path']);
            $results[$storage['id']] = [
                'name' => $storage['name'],
                'count' => $count
            ];
        }
        
        // 메타 정보 업데이트
        $this->setMeta('last_rebuild', date('Y-m-d H:i:s'));
        
        return $results;
    }
    
    /**
     * 인덱스 통계
     */
    public function getStats(): array {
        if (!$this->available) {
            return [
                'total' => 0,
                'folders' => 0,
                'files' => 0,
                'last_rebuild' => null,
                'available' => false
            ];
        }
        
        $total = $this->db->querySingle('SELECT COUNT(*) FROM files');
        $folders = $this->db->querySingle('SELECT COUNT(*) FROM files WHERE is_dir = 1');
        $files = $this->db->querySingle('SELECT COUNT(*) FROM files WHERE is_dir = 0');
        $lastRebuild = $this->getMeta('last_rebuild');
        $lastSync = $this->getMeta('last_sync');
        
        return [
            'total' => (int)$total,
            'folders' => (int)$folders,
            'files' => (int)$files,
            'last_rebuild' => $lastRebuild,
            'last_sync' => $lastSync,
            'available' => true
        ];
    }
    
    /**
     * 스토리지별 통계
     */
    public function getStorageStats(int $storageId): array {
        if (!$this->available) {
            return ['total' => 0, 'files' => 0, 'folders' => 0];
        }
        
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM files WHERE storage_id = :id');
        $stmt->bindValue(':id', $storageId, SQLITE3_INTEGER);
        $total = $stmt->execute()->fetchArray()[0];
        
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM files WHERE storage_id = :id AND is_dir = 0');
        $stmt->bindValue(':id', $storageId, SQLITE3_INTEGER);
        $files = $stmt->execute()->fetchArray()[0];
        
        return [
            'total' => (int)$total,
            'files' => (int)$files,
            'folders' => (int)$total - (int)$files
        ];
    }
    
    /**
     * 스토리지별 총 파일 크기 (인덱스 DB 기준)
     */
    public function getStorageTotalSize(int $storageId): int {
        if (!$this->available) return 0;
        
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(size), 0) FROM files WHERE storage_id = :id AND is_dir = 0');
        $stmt->bindValue(':id', $storageId, SQLITE3_INTEGER);
        return (int)$stmt->execute()->fetchArray()[0];
    }
    
    /**
     * 메타 정보 저장
     */
    public function setMeta(string $key, string $value): void {
        if (!$this->available) return;
        
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (:key, :value)');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $stmt->execute();
    }
    
    /**
     * 메타 정보 조회
     */
    public function getMeta(string $key): ?string {
        if (!$this->available) return null;
        
        $stmt = $this->db->prepare('SELECT value FROM meta WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray();
        return $result ? $result[0] : null;
    }
    
    /**
     * 인덱스 존재 여부 (SQLite3 사용 가능 && 인덱스 있음)
     */
    public function hasIndex(): bool {
        if (!$this->available) return false;
        
        $count = $this->db->querySingle('SELECT COUNT(*) FROM files');
        return $count > 0;
    }
    
    /**
     * 인덱스에 데이터가 있는 스토리지 ID 목록
     */
    public function getIndexedStorageIds(): array {
        if (!$this->available) return [];
        
        $result = $this->db->query('SELECT DISTINCT storage_id FROM files');
        $ids = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $ids[] = (int)$row['storage_id'];
        }
        return $ids;
    }
    
    /**
     * 인덱스 초기화
     */
    public function clearAll(): bool {
        if (!$this->available) return false;
        
        // DB 연결 닫기
        if ($this->db) {
            $this->db->close();
            $this->db = null;
        }
        
        // DB 파일 삭제
        $deleted = false;
        if (file_exists($this->dbPath)) {
            $deleted = @unlink($this->dbPath);
        }
        
        // WAL 관련 파일도 삭제
        $walFile = $this->dbPath . '-wal';
        $shmFile = $this->dbPath . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
        
        // 인스턴스 초기화 (다음 호출 시 새로 생성)
        $this->available = false;
        self::$instance = null;
        
        return $deleted || !file_exists($this->dbPath);
    }
    
    /**
     * 증분 동기화: 파일시스템과 인덱스를 비교하여 변경분만 갱신
     * 네트워크 드라이브 등 외부에서 파일이 추가/삭제된 경우 감지
     */
    public function syncStorage(int $storageId, string $basePath): array {
        if (!$this->available) return ['added' => 0, 'removed' => 0, 'updated' => 0];
        
        $startTime = time();
        $maxTime = 300; // 스토리지당 최대 300초
        $added = 0;
        $removed = 0;
        $updated = 0;
        
        // ★ 체크포인트 파일: 이전 sync가 타임아웃된 경우 남은 디렉토리 큐 복원
        // 이렇게 안 하면 23TB+ 대용량 스토리지는 매번 basePath부터 시작 → 영원히 완료 못 함
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $checkpointFile = $dataDir . DIRECTORY_SEPARATOR . 'index_sync_checkpoint_' . $storageId . '.json';
        $dirQueue = [$basePath];
        $resumed = false;
        
        if (is_file($checkpointFile)) {
            $checkpointData = @json_decode(@file_get_contents($checkpointFile), true);
            if (is_array($checkpointData)
                && isset($checkpointData['base_path']) 
                && $checkpointData['base_path'] === $basePath
                && isset($checkpointData['queue']) 
                && is_array($checkpointData['queue'])
                && !empty($checkpointData['queue'])) {
                $dirQueue = $checkpointData['queue'];
                $resumed = true;
            } else {
                // 체크포인트 손상 또는 base_path 변경 — 삭제하고 처음부터
                @unlink($checkpointFile);
            }
        }
        
        // 스트리밍 방식: 디렉토리 단위로 비교 (메모리 절약)
        $this->db->exec('BEGIN TRANSACTION');
        $this->_scanAborted = false;
        $processedCount = 0;
        // $this->_skipDebugLogCount = 0;  // [주석처리] 디버그 로그 카운터 리셋 (한 sync당 최대 30건)
        
        try {
            while (!empty($dirQueue)) {
                if ((time() - $startTime) > $maxTime) { $this->_scanAborted = true; break; }
                
                $currentDir = array_shift($dirQueue);
                if (!is_dir($currentDir)) continue;
                
                // Windows 시스템 폴더 스킵
                $dirName = basename($currentDir);
                if (in_array($dirName, ['$RECYCLE.BIN', 'System Volume Information', '$Recycle.Bin'])) continue;
                
                // ★ 최적화: 디렉토리 mtime 기반 변경 감지
                // 디렉토리의 mtime은 내부 파일/폴더가 추가·삭제·이름변경될 때만 변경됨
                // (파일 내용 변경만으로는 디렉토리 mtime이 안 변함)
                // → 변경 없는 폴더는 scandir 스킵 + DB에서 하위 디렉토리 큐만 가져옴
                // 결과: 23TB 변경 없을 때 매번 13분 → 수 초로 단축
                $currentRelDir = ($currentDir === $basePath) ? '' : substr($currentDir, strlen($basePath) + 1);
                $currentRelDir = str_replace('\\', '/', $currentRelDir);
                
                $dirMtime = @filemtime($currentDir);
                $dirMtimeStr = $dirMtime ? date('Y-m-d H:i:s', $dirMtime) : null;
                
                $skipScan = false;
                // $_skipDebugReason = null; // [주석처리] 디버그 로그용
                if ($dirMtimeStr !== null && $currentRelDir !== '') {
                    // 디렉토리 자체가 인덱스에 있는지 + mtime 일치하는지 확인
                    // 주의: 네트워크 마운트(일부 NAS)는 mtime 업데이트가 지연될 수 있음
                    // → mtime 신뢰 못 하는 환경이면 사용자가 수동 "인덱스 재구축"으로 복구 가능
                    $stmt = $this->db->prepare('SELECT modified FROM files WHERE storage_id = :sid AND filepath = :fp AND is_dir = 1 LIMIT 1');
                    $stmt->bindValue(':sid', $storageId, SQLITE3_INTEGER);
                    $stmt->bindValue(':fp', $currentRelDir, SQLITE3_TEXT);
                    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                    if ($res && $res['modified'] === $dirMtimeStr) {
                        $skipScan = true;
                    }
                    // else if (!$res) { $_skipDebugReason = 'not_in_db'; }
                    // else { $_skipDebugReason = 'mtime_diff:db=' . $res['modified'] . ',fs=' . $dirMtimeStr; }
                }
                // else if ($dirMtimeStr === null) { $_skipDebugReason = 'no_filemtime'; }
                // else { $_skipDebugReason = 'root'; }
                
                /* [주석처리] 디버그 로그: not_in_db / mtime_diff 발생 시 이유 로그 (한 sync당 최대 30건)
                 * 이 로그로 23TB에 매번 scandir되는 폴더가 뭐고 왜 그런지 확인
                 * 2026-04-23 진단 완료: skip 로직 정상 작동 확인됨 (sync_skip_fail 0건)
                if (!$skipScan && $_skipDebugReason !== null && $_skipDebugReason !== 'root') {
                    if (!isset($this->_skipDebugLogCount)) $this->_skipDebugLogCount = 0;
                    if ($this->_skipDebugLogCount < 30) {
                        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
                        $perfLog = $dataDir . '/scan_perf.log';
                        if (is_file($perfLog)) {
                            @file_put_contents($perfLog,
                                sprintf("[%s] sync_skip_fail storage_id=%d dir=%s reason=%s\n",
                                    date('Y-m-d H:i:s'), $storageId,
                                    substr($currentRelDir, 0, 150),
                                    $_skipDebugReason),
                                FILE_APPEND | LOCK_EX);
                        }
                        $this->_skipDebugLogCount++;
                    }
                }
                */
                
                if ($skipScan) {
                    // 변경 없음 — 하위 디렉토리만 큐에 추가 (인덱스에서 가져옴)
                    $prefix = $currentRelDir . '/';
                    $stmt = $this->db->prepare("SELECT filepath FROM files WHERE storage_id = :sid AND filepath LIKE :prefix AND filepath NOT LIKE :deeper AND is_dir = 1");
                    $stmt->bindValue(':sid', $storageId, SQLITE3_INTEGER);
                    $stmt->bindValue(':prefix', $prefix . '%', SQLITE3_TEXT);
                    $stmt->bindValue(':deeper', $prefix . '%/%', SQLITE3_TEXT);
                    $res = $stmt->execute();
                    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                        $childFullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['filepath']);
                        $dirQueue[] = $childFullPath;
                    }
                    $processedCount++;
                    if ($processedCount % 50 === 0) usleep(10000);
                    continue;
                }
                
                // 현재 디렉토리의 파일시스템 항목
                $fsItems = [];
                try {
                    $items = @scandir($currentDir);
                    if (!$items) continue;
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..' || $item[0] === '.') continue;
                        $fullPath = $currentDir . DIRECTORY_SEPARATOR . $item;
                        $relPath = substr($fullPath, strlen($basePath) + 1);
                        $relPath = str_replace('\\', '/', $relPath);
                        $isDir = is_dir($fullPath);
                        $mtime = @filemtime($fullPath);
                        $size = $isDir ? 0 : (@filesize($fullPath) ?: 0);
                        $fsItems[$relPath] = [
                            'is_dir' => $isDir,
                            'size' => $size,
                            'mtime' => $mtime ?: 0
                        ];
                        if ($isDir) {
                            $dirQueue[] = $fullPath;
                        }
                    }
                } catch (Exception $e) { continue; }
                
                // 현재 디렉토리의 인덱스 항목 (해당 디렉토리 직접 자식만)
                $indexedInDir = [];
                if ($currentRelDir === '') {
                    // 루트: filepath에 /가 없는 항목
                    $stmt = $this->db->prepare("SELECT filepath, modified, size, is_dir FROM files WHERE storage_id = :sid AND filepath NOT LIKE '%/%'");
                } else {
                    // 하위 디렉토리: prefix/ 로 시작하고 그 아래에 추가 /가 없는 항목
                    $prefix = $currentRelDir . '/';
                    $stmt = $this->db->prepare("SELECT filepath, modified, size, is_dir FROM files WHERE storage_id = :sid AND filepath LIKE :prefix AND filepath NOT LIKE :deeper");
                    $stmt->bindValue(':prefix', $prefix . '%', SQLITE3_TEXT);
                    $stmt->bindValue(':deeper', $prefix . '%/%', SQLITE3_TEXT);
                }
                $stmt->bindValue(':sid', $storageId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $indexedInDir[$row['filepath']] = $row;
                }
                
                // 비교: 추가/업데이트
                foreach ($fsItems as $relPath => $info) {
                    if (isset($indexedInDir[$relPath])) {
                        $idxMod = $indexedInDir[$relPath]['modified'];
                        $fsMod = date('Y-m-d H:i:s', $info['mtime']);
                        if ($idxMod !== $fsMod || (int)$indexedInDir[$relPath]['size'] !== $info['size']) {
                            $this->addFile($storageId, $relPath, [
                                'is_dir' => $info['is_dir'] ? 1 : 0,
                                'size' => $info['size'],
                                'modified' => $fsMod
                            ]);
                            $updated++;
                        }
                        unset($indexedInDir[$relPath]);
                    } else {
                        $this->addFile($storageId, $relPath, [
                            'is_dir' => $info['is_dir'] ? 1 : 0,
                            'size' => $info['size'],
                            'modified' => date('Y-m-d H:i:s', $info['mtime'])
                        ]);
                        $added++;
                    }
                }
                
                // 삭제: 인덱스에만 있고 파일시스템에 없는 항목
                // 현재 디렉토리의 직접 자식들만 비교하므로 이 디렉토리 자체는 완전히 스캔됨
                // → 다른 디렉토리가 큐에 남아있어도 이 디렉토리의 삭제 판정은 정확
                foreach ($indexedInDir as $relPath => $row) {
                    $this->removeFile($storageId, $relPath);
                    $removed++;
                }
                
                $processedCount++;
                
                // 1000 디렉토리마다 중간 커밋 (SQLite WAL 부담 감소)
                if ($processedCount % 1000 === 0) {
                    $this->db->exec('COMMIT');
                    $this->db->exec('BEGIN TRANSACTION');
                }
                
                // IO 양보: 매 50 디렉토리마다 10ms 쉼
                // 다른 요청이 SQLite와 디스크 I/O 획득 가능 → 무한로딩 방지
                if ($processedCount % 50 === 0) {
                    usleep(10000);
                }
            }
            
            $this->db->exec('COMMIT');
            
            // ★ 체크포인트 관리
            if (empty($dirQueue)) {
                // 완료 — 체크포인트 삭제
                if (is_file($checkpointFile)) @unlink($checkpointFile);
                // 완전 1회 완료된 경우에만 last_sync 업데이트 (체크포인트 없이 처음부터 끝까지 간 경우만)
                $this->setMeta('last_sync', date('Y-m-d H:i:s'));
                $this->setMeta('last_sync_storage_' . $storageId, date('Y-m-d H:i:s'));
            } elseif ($this->_scanAborted) {
                // 타임아웃으로 중단 — 남은 큐 저장해서 다음번에 이어서 스캔
                // ★ 큐 크기 제한: 너무 크면(500,000 초과) 앞부분만 저장 (메모리/디스크 보호)
                // 나머지는 다음 이어하기 중 재발견됨 (dirQueue에 재삽입됨)
                $queueToSave = array_values($dirQueue);
                $queueCount = count($queueToSave);
                $maxQueueSize = 500000;
                $truncated = false;
                if ($queueCount > $maxQueueSize) {
                    $queueToSave = array_slice($queueToSave, 0, $maxQueueSize);
                    $truncated = true;
                }
                $checkpointPayload = [
                    'base_path' => $basePath,
                    'queue' => $queueToSave,
                    'saved_at' => time(),
                    'processed_this_run' => $processedCount,
                    'queue_original_size' => $queueCount,
                    'queue_truncated' => $truncated,
                ];
                $json = json_encode($checkpointPayload);
                // 100MB 제한 (병적으로 긴 경로 방지)
                if ($json !== false && strlen($json) < 100 * 1024 * 1024) {
                    @file_put_contents($checkpointFile, $json);
                } else {
                    // JSON 인코딩 실패 or 너무 큼 — 체크포인트 포기, 다음에 처음부터
                    if (is_file($checkpointFile)) @unlink($checkpointFile);
                }
            }
        } catch (Exception $e) {
            $this->db->exec('ROLLBACK');
        }
        
        return [
            'added' => $added, 
            'removed' => $removed, 
            'updated' => $updated,
            'resumed' => $resumed,
            'remaining_dirs' => count($dirQueue),
            'processed_dirs' => $processedCount,
        ];
    }
    
    /**
     * 파일시스템 순회 (동기화용)
     */
    private int $_scanStartTime = 0;
    private int $_scanMaxTime = 120; // 최대 120초
    private int $_scanCount = 0;
    private bool $_scanAborted = false;
    
    private function _scanForSync(string $dir, string $basePath, array &$result): void {
        if (!is_dir($dir)) return;
        // 시간 제한 체크
        if ($this->_scanStartTime > 0 && (time() - $this->_scanStartTime) > $this->_scanMaxTime) { $this->_scanAborted = true; return; }
        
        // Windows 시스템 폴더 스킵
        static $skipDirs = ['$RECYCLE.BIN', 'System Volume Information', '$Recycle.Bin'];
        try {
            $items = @scandir($dir);
            if (!$items) return;
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item[0] === '.') continue;
                if (in_array($item, $skipDirs)) continue;
                if ($this->_scanStartTime > 0 && (time() - $this->_scanStartTime) > $this->_scanMaxTime) { $this->_scanAborted = true; return; }
                $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
                $relPath = substr($fullPath, strlen($basePath) + 1);
                $relPath = str_replace('\\', '/', $relPath);
                $isDir = is_dir($fullPath);
                $mtime = @filemtime($fullPath);
                $size = $isDir ? 0 : (@filesize($fullPath) ?: 0);
                $result[$relPath] = [
                    'is_dir' => $isDir,
                    'size' => $size,
                    'mtime' => $mtime ?: 0
                ];
                $this->_scanCount++;
                if ($isDir) {
                    $this->_scanForSync($fullPath, $basePath, $result);
                }
            }
        } catch (Exception $e) {}
    }
    
    /**
     * 원격 스토리지 증분 동기화 (어댑터 기반)
     */
    /* 미사용 함수 제거됨 — syncStorageRemote */
    
    /**
     * 원격 파일시스템 순회 (동기화용)
     */
    private function _scanRemoteForSync(string $path, $adapter, array &$result): void {
        try {
            $items = $adapter->list($path);
            foreach ($items as $item) {
                $name = $item['name'] ?? '';
                if ($name === '' || $name[0] === '.') continue;
                $relPath = $item['path'] ?? ltrim($path . '/' . $name, '/');
                $isDir = $item['is_dir'] ?? false;
                $result[$relPath] = [
                    'is_dir' => $isDir,
                    'size' => $isDir ? 0 : ($item['size'] ?? 0),
                    'mtime' => $item['modified'] ?? 0
                ];
                if ($isDir) {
                    $this->_scanRemoteForSync($relPath, $adapter, $result);
                }
            }
        } catch (Exception $e) {}
    }
    
    /**
     * 폴더를 재귀적으로 인덱싱
     */
    public function indexFolder(int $storageId, string $basePath, string $relativePath): void {
        if (!$this->available) return;
        
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_dir($fullPath)) return;
        
        // 폴더 자체 등록
        $this->addFile($storageId, $relativePath, [
            'name' => basename($relativePath),
            'size' => 0,
            'modified' => filemtime($fullPath) ?: time(),
            'is_dir' => true
        ]);
        
        // 하위 파일/폴더 재귀 순회
        $items = @scandir($fullPath);
        if (!$items) return;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $childRelative = $relativePath . '/' . $item;
            $childFull = $fullPath . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($childFull)) {
                $this->indexFolder($storageId, $basePath, $childRelative);
            } else {
                $this->addFile($storageId, $childRelative, [
                    'name' => $item,
                    'size' => filesize($childFull) ?: 0,
                    'modified' => filemtime($childFull) ?: time(),
                    'is_dir' => false
                ]);
            }
        }
    }
}
