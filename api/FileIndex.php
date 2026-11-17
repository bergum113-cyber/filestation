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
    private bool $remoteListIncomplete = false;   // ★ (2026-10-29 18:06, 22차 점검) 원격 재구축 중 하위 목록 실패 여부
    
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
            // ★ (2026-11-01 09:45, 38차 점검) SQLite3 은 기본 설정에서 예외 대신 false 를 돌려줘 손상 감지가 안 됐음 — 첫 생성이 테이블 만들기 전에 끊겨
            //   빈 파일·테이블 없는 DB 가 남으면 available=true 인 채 모든 prepare() 가 false → bindValue() 오류. false 면 테이블을 (IF NOT EXISTS 로) 만들어 보고, 그래도 안 되면 재생성으로 넘김
            if ($test === false) {
                // ★ (2026-11-01 10:20, 38차 점검 재검토) 실패 이유를 보고 나눔 — 잠김(5 BUSY·6 LOCKED)·입출력(10) 같은 일시 오류까지 '손상'으로 보고 아래에서
                //   DB 를 지워 다시 만들 수 있었음(재검토 실측: WAL 이 아닌 상태에서 다른 연결이 쓰는 중이면 code=5). 일시 오류면 이번 요청만 색인을 쓰지 않음
                $code = (int)$this->db->lastErrorCode();
                if (!in_array($code, [1, 11, 26], true)) {   // 1 = 테이블 없음 등, 11 = 손상, 26 = DB 아님 — 이때만 만들기·재생성
                    error_log("FileIndex DB temporarily unavailable (code {$code}): " . $this->db->lastErrorMsg());
                    $this->available = false;
                    return;
                }
                $this->createTables();
                if (@$this->db->querySingle("SELECT COUNT(*) FROM files LIMIT 1") === false) {
                    $code2 = (int)$this->db->lastErrorCode();
                    if (!in_array($code2, [1, 11, 26], true)) {
                        error_log("FileIndex DB temporarily unavailable (code {$code2}): " . $this->db->lastErrorMsg());
                        $this->available = false;
                        return;
                    }
                    throw new RuntimeException('files table unavailable: ' . $this->db->lastErrorMsg());
                }
            }
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
        // ★ (2026-11-02 14:38, 44차 점검) 경로 조각 중 점(.)으로 시작하는 것이 있으면 넣지 않음 — 재구축·동기화·원격 색인·indexFolder(38차)는 점 이름을 빼는데
        //   WebDAV PUT·MKCOL(._a.jpg·.DS_Store)·휴지통 복원(reindexPath)은 여기로 바로 넣어 검색·색인 즉답 목록에 나왔음(G1#9 재현). 한 곳에서 같은 정책으로(넣은 척 true)
        foreach (explode('/', str_replace('\\', '/', $filepath)) as $ixSeg) {
            if ($ixSeg !== '' && $ixSeg[0] === '.') return true;
        }

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
        // ★ (2026-10-29 11:13, 20차 점검) 수정 시각은 늘 'Y-m-d H:i:s' 글자로 — 종전엔 폴더 색인(indexFolder)·압축 등이 초(정수)로 넣어 형식이 섞였고,
        //   동기화(글자끼리 비교)가 그 항목들을 매번 바뀐 것으로 보고 다시 썼음
        $ixMod = $info['modified'] ?? null;
        if (is_int($ixMod) || is_float($ixMod) || (is_string($ixMod) && ctype_digit($ixMod))) $ixMod = date('Y-m-d H:i:s', (int)$ixMod);
        elseif (!is_string($ixMod) || $ixMod === '') $ixMod = date('Y-m-d H:i:s');
        $stmt->bindValue(':modified', $ixMod, SQLITE3_TEXT);
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
     * ★ (2026-10-29 22:39, 24차 점검) 스토리지 하나의 인덱스 전부(행·스토리지별 동기화 시각·체크포인트) 삭제 — 스토리지 삭제 때 사용.
     *   번호는 '최대+1' 로 다시 쓰여, 지운 스토리지의 인덱스를 같은 번호의 새 스토리지가 물려받았음(사용량 덮어쓰기·검색·원격 목록)
     */
    public function removeStorage(int $storageId): bool {
        if (!$this->available) return false;
        $stmt = $this->db->prepare('DELETE FROM files WHERE storage_id = :storage_id');
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $ok = $stmt->execute() !== false;
        $dm = $this->db->prepare('DELETE FROM meta WHERE key = :key');
        $dm->bindValue(':key', 'last_sync_storage_' . $storageId, SQLITE3_TEXT);
        $dm->execute();
        $dm->bindValue(':key', 'index_built_storage_' . $storageId, SQLITE3_TEXT);   // ★ (2026-11-02 15:24, 44차 재검토) 검색용 '재구축 끝남' 표시도(아래 getIndexedStorageIds)
        $dm->execute();
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $cp = $dataDir . DIRECTORY_SEPARATOR . 'index_sync_checkpoint_' . $storageId . '.json';
        if (is_file($cp)) @unlink($cp);
        return $ok;
    }
    
    /**
     * 폴더 삭제 (하위 항목 포함)
     */
    public function removeFolder(int $storageId, string $folderPath): bool {
        if (!$this->available) return false;
        
        // 폴더 자체 삭제
        $this->removeFile($storageId, $folderPath);
        
        // 하위 항목 삭제
        // ★ (2026-09-03) LIKE → 범위 조건으로 교체.
        //   [문제] `LIKE '경로/%'` 는 ESCAPE 절이 없어 폴더 이름의 % · _ 가 와일드카드로 동작했다.
        //   실측: `50%_off` 삭제 시 **디스크에 멀쩡히 존재하는 `50Xyoff` 의 하위 3행이 함께 삭제**됐다
        //   (파일은 무사하고 검색에서만 사라지며 전체 재구축으로 복구되지만, 명백한 오작동이다).
        //   또 SQLite 는 LIKE 가 기본적으로 대소문자를 무시해, 리눅스에서 `Folder` 와 `folder` 가
        //   공존할 때 서로의 하위를 지웠다.
        //   [수정] `filepath >= '경로/' AND filepath < '경로0'` — 와일드카드가 없어 이스케이프 문제가
        //   원천적으로 없고, 대소문자도 정확히 구분한다. 상한은 '/'(0x2F) 를 '0'(0x30) 으로 바꾼 값이며
        //   그 접두사로 시작하는 모든 바이트열이 반드시 이보다 작아 빠지는 항목이 없다.
        //   덤으로 idx_storage_filepath 를 타서 대용량 인덱스에서 훨씬 빠르다.
        $delPrefix = $folderPath . '/';
        $stmt = $this->db->prepare('DELETE FROM files WHERE storage_id = :storage_id AND filepath >= :rlo AND filepath < :rhi');
        $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $stmt->bindValue(':rlo', $delPrefix, SQLITE3_TEXT);
        $stmt->bindValue(':rhi', substr($delPrefix, 0, -1) . '0', SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }
    
    /**
     * 파일/폴더 이동 (경로 업데이트)
     */
    public function moveFile(int $storageId, string $oldPath, string $newPath): bool {
        if (!$this->available) return false;
        // ★ (2026-10-29 22:06, 24차 점검) 덮어쓰기 이동이면 대상 자리의 옛 행(폴더면 그 아래까지)을 먼저 지움 — 종전엔 UNIQUE(storage_id, filepath) 충돌로 UPDATE 가 실패해
        //   원본 경로의 유령 항목이 남고 대상은 옛 크기·시각 그대로였음(직접 이동·끌어 놓기)
        //   원본 행이 있을 때만(같은 이동을 두 번 부르는 경로가 있어 — FileManager 와 api.php — 두 번째에 옮겨 놓은 행을 지우지 않게)
        // ★ (2026-11-02 14:38, 44차 점검) 대상 자리 지우기·폴더 행·하위 행 갱신을 한 SAVEPOINT(바깥 트랜잭션 안에서도 됨) 안에서 — 종전엔 하위 행마다 따로 커밋(10만 행 9초)이라
        //   시간 제한 등으로 끊기면 반은 옛 경로로 남았고 index_sync 로도 안 고쳐졌음(G1#6 재현). 실패하면 전부 되돌리고 false
        $this->db->exec('SAVEPOINT fi_move');
        // ★ (2026-11-11 18:30, 79차 IDX-1) 읽기보다 먼저 쓰기 잠금을 잡음(행을 바꾸지 않는 쓰기 문장) — 종전엔 SAVEPOINT 안 첫 문장이 SELECT 라 읽기 잠금을 쥔 채 UPDATE 로 올리다
        //   다른 요청이 쓰는 중이면(재구축·index_sync·업로드) SQLite 가 busyTimeout 을 기다리지 않고 바로 실패해, 화면은 '성공'인데 검색에 옛 이름이 남았음(재현). 잠금을 끝내 못 잡으면 종전처럼 false
        if ($this->db->exec('DELETE FROM files WHERE 0') === false) {
            $this->db->exec('ROLLBACK TO fi_move');
            $this->db->exec('RELEASE fi_move');
            return false;
        }
        if ($oldPath !== $newPath) {
            $chk = $this->db->prepare('SELECT 1 FROM files WHERE storage_id = :sid AND filepath = :p LIMIT 1');
            $chk->bindValue(':sid', $storageId, SQLITE3_INTEGER);
            $chk->bindValue(':p', $oldPath, SQLITE3_TEXT);
            $chkRes = $chk->execute();
            if ($chkRes && $chkRes->fetchArray()) $this->removeFolder($storageId, $newPath);
        }
        
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
        // ★ (2026-10-28 18:22, 소스 점검 5단계) LIKE → 범위 조건(removeFolder 와 같은 방식). 종전엔 `LIKE '경로/%'` 라 폴더 이름의
        //   '_'·'%' 가 와일드카드로 동작해 형제 폴더(예: a_b 이동 시 a-b/…)의 행까지 새 경로로 바꿨고, 대소문자도 무시됐음.
        //   범위 조건은 와일드카드·대소문자 문제가 없고 인덱스를 탐.
        $oldPrefix = $oldPath . '/';
        $oldLen = strlen($oldPath);

        // ★ (2026-11-02 14:38, 44차 점검) 행마다 SELECT→UPDATE 하던 것을 한 문장으로(같은 범위 조건). 접두사 자르기는 바이트 단위(BLOB substr — PHP substr($fp, $oldLen) 과 같음,
        //   TEXT substr 는 글자 단위라 한글 경로에서 어긋남). OR REPLACE: 대상 자리에 남은 옛 행(원본 폴더 행이 없어 위 지우기를 안 탄 경우)과 부딪혀도 문장 전체가 실패하지 않게
        $updateStmt = $this->db->prepare('UPDATE OR REPLACE files SET filepath = :new_path || CAST(substr(CAST(filepath AS BLOB), :cut) AS TEXT) WHERE storage_id = :storage_id AND filepath >= :rlo AND filepath < :rhi');
        $updateStmt->bindValue(':new_path', $newPath, SQLITE3_TEXT);
        $updateStmt->bindValue(':cut', $oldLen + 1, SQLITE3_INTEGER);
        $updateStmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
        $updateStmt->bindValue(':rlo', $oldPrefix, SQLITE3_TEXT);
        $updateStmt->bindValue(':rhi', substr($oldPrefix, 0, -1) . '0', SQLITE3_TEXT);
        if ($updateStmt->execute() === false) {
            $this->db->exec('ROLLBACK TO fi_move');
            $this->db->exec('RELEASE fi_move');
            return false;
        }
        $this->db->exec('RELEASE fi_move');

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
        
        // ★ (2026-11-02 09:01, 41차 점검) 검색어의 NFC·NFD 두 형태로도 찾음 — 맥에서 올린 이름은 자모가 풀린 NFD 로 색인되는데 키보드 검색어는 NFC 라
        //   종전엔 '보고서' 로 맥에서 올린 '보고서.hwp' 가 안 찾아졌음(재현). 저장된 이름·색인은 그대로 두고 검색어만 늘림. intl(Normalizer) 이 없으면 종전대로 한 형태만
        $qForms = [$query];
        if (class_exists('Normalizer')) {
            foreach ([\Normalizer::FORM_C, \Normalizer::FORM_D] as $nf) {
                $nq = \Normalizer::normalize($query, $nf);
                if (is_string($nq) && $nq !== '' && !in_array($nq, $qForms, true)) $qForms[] = $nq;
            }
        }
        $qLikes = [];
        foreach ($qForms as $query) {
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
            // ★ (2026-10-29 22:08, 24차 점검) 검색어 속 '_'·'%' 는 글자 그대로(와일드카드 검색과 같은 이스케이프) — 종전엔 'a_b' 가 'axb' 도, '%' 하나가 전부를 찾았음
            $query = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
            $useEscape = true;
        }
        $qLikes[] = $query;
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
                WHERE storage_id IN ($placeholders) AND (" . implode(' OR ', array_fill(0, count($qLikes), "filename LIKE ? COLLATE NOCASE{$escapeSql}")) . ")
                ORDER BY is_dir DESC, filename ASC
                {$limitSql}
            ");
            
            $paramIndex = 1;
            foreach ($storageIds as $id) {
                $stmt->bindValue($paramIndex++, (int)$id, SQLITE3_INTEGER);
            }
            foreach ($qLikes as $ql) $stmt->bindValue($paramIndex++, $ql, SQLITE3_TEXT);   // ★ (41차) 검색어 형태마다
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM files 
                WHERE (" . implode(' OR ', array_map(function ($i) use ($escapeSql) { return "filename LIKE :query{$i} COLLATE NOCASE{$escapeSql}"; }, array_keys($qLikes))) . ")
                ORDER BY is_dir DESC, filename ASC
                {$limitSql}
            ");
            foreach ($qLikes as $i => $ql) $stmt->bindValue(':query' . $i, $ql, SQLITE3_TEXT);   // ★ (41차) 검색어 형태마다
        }
        
        $result = $stmt->execute();
        $files = [];
        if ($result === false) return $files;   // ★ (2026-11-02 14:38, 44차 점검) LIKE 패턴 한도(5만 바이트) 초과 등으로 execute()=false 면 빈 결과 — 종전엔 false->fetchArray 로 500(G1#13 재현)
        
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
            if ($pattern === '') continue;   // ★ (2026-11-01 17:18, 40차 점검) empty 가 패턴 '0' 을 빈 값으로 봐 버렸음(조건부 일괄 삭제 패턴 '0' 이 인덱스 경로에서 사라짐)
            
            // ★ (2026-10-29 11:12, 20차 점검) 이름에 든 % _ \ 는 먼저 글자로 이스케이프한 뒤 * ? 만 와일드카드로 — 종전엔 변환 뒤 이스케이프를 다시 풀어
            //   이름의 '_'·'%' 도 와일드카드가 돼, 'pat_20*' 에 'patx20a.txt' 까지 잡혔음(재현. 인덱스 없을 때의 matchPattern 은 글자 그대로)
            $likePattern = addcslashes($pattern, '%_\\');
            $likePattern = str_replace(['*', '?'], ['%', '_'], $likePattern);
            $likePatterns[] = $likePattern;
        }
        
        if (empty($likePatterns)) return [];
        
        // SQL 쿼리 조건 생성
        $conditions = ['storage_id = :storage_id'];
        $bindings = [];
        
        // 경로 조건
        // ★ (2026-11-01 17:18, 40차 점검) 폴더 '0' 을 empty 가 루트로 봐 조건부 일괄 삭제가 스토리지 전체(재귀)·루트 바로 아래(현재 폴더)를 찾았음 → '' 일 때만 루트
        $normalizedPath = $basePath === '' ? '' : rtrim(str_replace('\\', '/', $basePath), '/');
        $likePath = addcslashes($normalizedPath, '%_\\');   // ★ 경로 조건도 폴더 이름의 % _ 를 글자로(아래 ESCAPE '\')
        
        if ($scope === 'current') {
            // 현재 폴더만 - 직접 자식만 검색
            if ($normalizedPath === '') {   // ★ (2026-11-01 17:18, 40차 점검) '0' 폴더를 루트로 보지 않게
                // 루트 폴더: filepath에 /가 없는 것 (직접 자식)
                $conditions[] = "filepath NOT LIKE '%/%'";
            } else {
                // 하위 폴더: 정확히 basePath/filename 형태인 것만
                // filepath가 basePath/로 시작하고, 그 이후에 /가 없는 것
                // ★ (2026-10-31 12:03, 34차 전체 점검) LIKE 는 대소문자를 무시해 'Docs' 로 찾으면 'docs/…' 도 섞였음 → 다른 곳(removeFolder 등)처럼 범위 조건을 함께
                //   (윈도우는 파일 시스템이 대소문자를 구분하지 않아 'docs' 로 친 경로도 'Docs' 를 찾아야 하므로 범위 조건은 윈도우 밖에서만)
                $conditions[] = "(filepath LIKE :path_direct ESCAPE '\\' AND filepath NOT LIKE :path_subdir ESCAPE '\\'" . (DIRECTORY_SEPARATOR === '\\' ? '' : " AND filepath >= :path_rlo AND filepath < :path_rhi") . ")";
                $bindings[':path_direct'] = $likePath . '/%';
                $bindings[':path_subdir'] = $likePath . '/%/%';
                if (DIRECTORY_SEPARATOR !== '\\') { $bindings[':path_rlo'] = $normalizedPath . '/'; $bindings[':path_rhi'] = $normalizedPath . '0'; }
            }
        } else {
            // 재귀 검색
            if ($normalizedPath !== '') {   // ★ (2026-11-01 17:18, 40차 점검) '0' 폴더를 루트로 보지 않게
                // basePath 하위 전체
                // ★ (2026-11-01 01:12, 35차 전체 점검) 기준 폴더 자신은 빼고 하위만(파일 스캔 대체 경로와 같게) — 종전 'OR filepath = 기준' 때문에 조건부 일괄 삭제 결과에
                //   기준 폴더가 체크된 채 들어가, 그대로 삭제하면 패턴에 안 맞는 파일까지 폴더째 휴지통으로 갔음
                $conditions[] = "(filepath LIKE :path_prefix ESCAPE '\\'" . (DIRECTORY_SEPARATOR === '\\' ? '' : " AND filepath >= :path_rlo AND filepath < :path_rhi") . ")";   // ★ (2026-10-31 12:03, 34차 전체 점검) 범위 조건(대소문자, 윈도우 밖)
                $bindings[':path_prefix'] = $likePath . '/%';
                if (DIRECTORY_SEPARATOR !== '\\') { $bindings[':path_rlo'] = $normalizedPath . '/'; $bindings[':path_rhi'] = $normalizedPath . '0'; }
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
        if ($result === false) return $files;   // ★ (2026-11-02 14:38, 44차 점검) LIKE 패턴 한도(5만 바이트) 초과 등으로 execute()=false 면 빈 결과 — 종전엔 false->fetchArray 로 500(G1#13 재현)
        
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
        // ★ (2026-10-28) 안전장치 — 로컬 경로가 비어 있으면(원격 스토리지 등) 서버 최상위 경로('/이름')를 보게 되므로 아무것도 안 함
        if (trim($basePath) === '') return 0;   // (기존 인덱스를 지우기 전에 멈춤)
        
        // 트랜잭션 시작 (대량 INSERT 성능 개선)
        $this->db->exec('BEGIN TRANSACTION');
        
        try {
            // 기존 인덱스 삭제
            $stmt = $this->db->prepare('DELETE FROM files WHERE storage_id = :storage_id');
            $stmt->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
            $stmt->execute();
            // ★ (2026-11-02 08:59, 41차 점검) '완전 동기화' 시각도 같은 트랜잭션에서 지움(끝까지 가면 아래에서 다시 씀) — 종전엔 5000건 중간 커밋부터
            //   반쯤 빈 색인이 보이는데 옛 시각이 남아, 도는 동안·중단 뒤에도 목록 즉답·사용량 합계가 빠진 색인을 믿었음(재현). 실패로 롤백되면 시각도 되돌아감
            $dm = $this->db->prepare('DELETE FROM meta WHERE key = :key');
            $dm->bindValue(':key', 'last_sync_storage_' . $storageId, SQLITE3_TEXT);
            $dm->execute();
            $dm->bindValue(':key', 'index_built_storage_' . $storageId, SQLITE3_TEXT); $dm->execute();   // ★ (2026-11-02 15:24, 44차 재검토) 검색용 '재구축 끝남' 표시도 시작 때 지움(끝까지 가야 다시 씀)
            
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
                
                // ★ (2026-11-13 15:02, 112차 SRCH-1) 크기·시각을 항목마다 보호 — 종전엔 stat 이 실패하는 항목 하나(끊긴 링크, 목록을 읽은 뒤 이름이 바뀌거나 지워진 항목)의
                //   RuntimeException 이 아래 catch 로 빠져 그 폴더의 뒤쪽 형제·하위가 통째로 색인에서 빠지고 재구축은 '완료'로 끝났음(재현: 링크 뒤 44개 누락).
                //   끊긴 링크는 폴더 목록(FileManager 361–362, 크기 0)·증분 동기화(@filemtime→0)처럼 크기 0·시각 0 으로 넣고, 사라진 항목만 건너뜀
                try {
                    $ixSize = $file->isDir() ? 0 : $file->getSize();
                    $ixMtime = $file->getMTime();
                } catch (\Throwable $e) {
                    if (!$file->isLink()) continue;
                    $ixSize = 0; $ixMtime = 0;
                }
                $this->addFile($storageId, $relativePath, [
                    'is_dir' => $file->isDir() ? 1 : 0,
                    'size' => $ixSize,
                    'modified' => date('Y-m-d H:i:s', $ixMtime)
                ]);
                
                $count++;
                
                // 5000건마다 중간 커밋
                if ($count % 5000 === 0) {
                    $this->db->exec('COMMIT');
                    $this->db->exec('BEGIN TRANSACTION');
                    @set_time_limit(600); // 타이머 리셋 (대용량 인덱싱 보호)
                }
                
                if ($file->isDir()) {
                    if ($this->isLinkLoop($fullPath, $basePath)) continue;   // ★ (2026-11-01 09:45, 38차 점검) 상위를 가리키는 링크 순환은 따라 들어가지 않음(isLinkLoop 참고)
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
        
        // ★ (2026-10-29 18:06, 22차 점검) 루트 목록이 실패하면(어댑터 listFailed — 20차) 기존 인덱스를 지우지 않고 실패로 —
        //   종전엔 실패를 빈 폴더로 보고 인덱스를 0건으로 만든 뒤 '완전 동기화' 시각까지 찍어, 다음 사용량 재계산 때 used_size 가 0 이 됐음(용량 한도 풀림)
        $adapter->list('');
        if (property_exists($adapter, 'listFailed') && $adapter->listFailed) {
            throw new Exception('remote list failed');
        }
        $this->remoteListIncomplete = false;
        
        // ★ (2026-11-02 08:59, 41차 점검) 원격 재구축을 '쓰기 트랜잭션을 연 채 원격 목록 기다리기'에서 '폴더마다 목록을 먼저 받고(트랜잭션 밖) 그 폴더 행만 짧게 쓰기'로.
        //   종전엔 시작 때 DELETE 한 트랜잭션을 연 채 원격 list() 를 재귀로 기다려, 목록이 5초(busyTimeout)를 넘기면 그 사이 업로드·삭제·작업 큐의
        //   addFile/removeFile 이 'database is locked' 로 조용히 빠졌고(재현), 5000건 중간 커밋부터는 반쯤 빈 색인이 옛 '완전 동기화' 시각과 함께 보였음.
        //   → 시작 때 '완전 동기화' 시각을 먼저 지우고(끝까지 가야 다시 씀), 기존 행은 미리 지우지 않고 INSERT OR REPLACE 로 새로 쓴 뒤(created_at 이 새 시각이 됨)
        //     끝까지 돌았을 때 이번에 다시 안 쓰인 옛 행(created_at < 시작 시각)만 지움. 도는 동안·중단 뒤에도 색인이 비지 않고, 재구축 중 다른 요청이 넣은 행은 새 시각이라 남음
        $dm0 = $this->db->prepare('DELETE FROM meta WHERE key = :key');
        $dm0->bindValue(':key', 'last_sync_storage_' . $storageId, SQLITE3_TEXT);
        $dm0->execute();
        $dm0->bindValue(':key', 'index_built_storage_' . $storageId, SQLITE3_TEXT); $dm0->execute();   // ★ (2026-11-02 15:24, 44차 재검토) 검색용 '재구축 끝남' 표시도 시작 때 지움(끝까지 가야 다시 씀)
        $rebuildStart = (string)$this->db->querySingle('SELECT CURRENT_TIMESTAMP');   // created_at 기본값과 같은 시계(SQLite UTC 'Y-m-d H:i:s')
        
        try {
            $count = 0;
            $this->indexRemoteDirectory($storageId, '', $adapter, $count, $progressCallback);
            
            // 끝까지 돈 경우에만: 이번 재구축에서 다시 쓰이지 않은 옛 행 정리(종전 '시작 때 전부 DELETE' 와 같은 결과)
            if ($rebuildStart !== '') {
                $this->db->exec('BEGIN TRANSACTION');
                $sw = $this->db->prepare('DELETE FROM files WHERE storage_id = :storage_id AND (created_at IS NULL OR created_at < :start)');
                $sw->bindValue(':storage_id', $storageId, SQLITE3_INTEGER);
                $sw->bindValue(':start', $rebuildStart, SQLITE3_TEXT);
                $sw->execute();
                $this->db->exec('COMMIT');
            }
            $this->setMeta('last_rebuild', date('Y-m-d H:i:s'));
            $this->setMeta('last_sync', date('Y-m-d H:i:s'));
            // ★ (2026-10-29 18:06, 22차 점검) 하위 폴더 목록이 하나라도 실패했으면 '완전 동기화' 시각을 남기지 않음(사용량 재계산이 빠진 인덱스를 믿지 않게)
            //   예전 시각도 지움 — 남겨 두면 사용량 재계산·인덱스 목록이 그 시각을 보고 빠진 인덱스를 그대로 믿었음(변경 검토에서 발견)
            // ★ (2026-11-02 15:24, 44차 재검토) 일부 하위 폴더 목록이 실패해도 끝까지 돈 재구축이면 '검색용 색인 있음' 표시를 남김 — 44차에 고급 검색·조건부 일괄 삭제의 판정을 last_sync_storage_ 로 바꾼 뒤,
            //   폴더 하나가 늘 실패하는 원격 스토리지는 재구축해도 영영 '색인 없음'(고급 검색은 실시간 200건, 일괄 삭제는 '인덱스를 먼저 구축' 거부)이 됐음(R44_fm#1). 목록 즉답·사용량은 종전대로 last_sync_storage_ 만 믿음
            $this->setMeta('index_built_storage_' . $storageId, date('Y-m-d H:i:s'));
            if (empty($this->remoteListIncomplete)) {
                $this->setMeta('last_sync_storage_' . $storageId, date('Y-m-d H:i:s'));
            } else {
                $dm = $this->db->prepare('DELETE FROM meta WHERE key = :key');
                $dm->bindValue(':key', 'last_sync_storage_' . $storageId, SQLITE3_TEXT);
                $dm->execute();
            }
            
            // 체크포인트가 있었다면 삭제
            $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
            $checkpointFile = $dataDir . DIRECTORY_SEPARATOR . 'index_sync_checkpoint_' . $storageId . '.json';
            if (is_file($checkpointFile)) @unlink($checkpointFile);
            
            return $count;
        } catch (Exception $e) {
            @$this->db->exec('ROLLBACK');   // ★ (41차) 폴더 단위로 커밋하므로 열린 트랜잭션이 없을 수 있음(그땐 아무 일 없음 — 경고만 숨김)
            throw $e;
        }
    }
    
    /**
     * 원격 디렉토리 재귀 인덱싱 (어댑터의 list() 사용)
     */
    private function indexRemoteDirectory(int $storageId, string $path, $adapter, int &$count, ?callable $progressCallback = null): void {
        
        $inTx = false;
        $subDirs = [];
        try {
            $items = $adapter->list($path);   // ★ (2026-11-02 08:59, 41차 점검) 네트워크 대기는 트랜잭션 밖에서(위 rebuildStorageRemote 주석)
            if (property_exists($adapter, 'listFailed') && $adapter->listFailed) $this->remoteListIncomplete = true;   // ★ (22차) 목록 실패 기록
            $this->db->exec('BEGIN TRANSACTION');
            $inTx = true;
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
                    $subDirs[] = $relativePath;   // ★ (41차) 하위 폴더는 이 폴더 행을 커밋한 뒤 들어감(하위 목록을 기다리는 동안 잠금을 쥐지 않게)
                }
            }
            $this->db->exec('COMMIT');
            $inTx = false;
        } catch (Exception $e) {
            // 접근 불가 폴더 무시
            if ($inTx) { $this->db->exec('COMMIT'); $inTx = false; }   // ★ (41차) 쓴 데까지 남기고 트랜잭션을 닫음(열어 둔 채 다음 폴더 목록을 기다리지 않게)
        }
        foreach ($subDirs as $sub) {
            $this->indexRemoteDirectory($storageId, $sub, $adapter, $count, $progressCallback);
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
     * 폴더 직속 자식 목록 (인덱스 DB 기반, 실시간 stat 없이 즉답)
     *   하이브리드 목록의 1단계: 네트워크 마운트 콜드 접근 지연 회피용.
     *   반환: 각 항목 [filename, filepath, is_dir, size, modified, extension] 배열, 또는 인덱스 불가 시 null.
     *   주의: 빈 배열([])은 "직속 자식 없음"이며, "이 폴더가 인덱스에 있는지"는 별도 판단 필요
     *         (호출측이 '스토리지 완전 동기화' 여부로 신뢰성 결정 — 미동기화면 실시간 목록 사용).
     *   SQL 로직은 별도 SQLite 테스트로 검증됨(직속만·손자 제외·스토리지 격리·LIKE 특수문자 이스케이프).
     */
    public function getFolderListing(int $storageId, string $relativePath = ''): ?array {
        if (!$this->available) return null;
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        // LIKE 특수문자 이스케이프 (%, _, \)
        $escLike = function (string $s): string {
            return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
        };
        if ($relativePath === '') {
            // 루트: filepath에 '/' 없는 것 = 직속
            $stmt = $this->db->prepare("
                SELECT filename, filepath, is_dir, size, modified, extension
                FROM files
                WHERE storage_id = :sid AND filepath NOT LIKE '%/%' ESCAPE '\\'
                ORDER BY is_dir DESC, filename COLLATE NOCASE ASC
            ");
            $stmt->bindValue(':sid', $storageId, SQLITE3_INTEGER);
        } else {
            $stmt = $this->db->prepare("
                SELECT filename, filepath, is_dir, size, modified, extension
                FROM files
                WHERE storage_id = :sid
                  AND filepath LIKE :pre ESCAPE '\\'
                  AND filepath NOT LIKE :deep ESCAPE '\\'
                  " . (DIRECTORY_SEPARATOR === '\\' ? '' : "AND filepath >= :rlo AND filepath < :rhi") . "
                ORDER BY is_dir DESC, filename COLLATE NOCASE ASC
            ");
            // ★ (2026-10-31 12:03, 34차 전체 점검) LIKE 는 대소문자를 무시해 'Docs' 목록에 'docs/…' 항목이 섞였음 → 범위 조건을 함께(removeFolder 등과 같게).
            //   윈도우는 파일 시스템이 대소문자를 구분하지 않아(같은 폴더) 종전대로 둠
            if (DIRECTORY_SEPARATOR !== '\\') {
                $stmt->bindValue(':rlo', $relativePath . '/', SQLITE3_TEXT);
                $stmt->bindValue(':rhi', $relativePath . '0', SQLITE3_TEXT);
            }
            $stmt->bindValue(':sid', $storageId, SQLITE3_INTEGER);
            $stmt->bindValue(':pre', $escLike($relativePath) . '/%', SQLITE3_TEXT);
            $stmt->bindValue(':deep', $escLike($relativePath) . '/%/%', SQLITE3_TEXT);
        }
        $res = $stmt->execute();
        if ($res === false) return null;
        $items = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $items[] = $row;
        }
        return $items;
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

        // ★ (2026-11-02 14:38, 44차 점검) '색인 있음' = 완전 색인(재구축·완전 동기화가 끝까지 돈) 기록 last_sync_storage_<id> 가 있는 스토리지(tryListFromIndex 와 같은 기준) —
        //   종전엔 행이 하나라도 있으면 색인 있음이라, 재구축을 한 번도 안 한 스토리지도 업로드 1건(addFile 은 auto_index 와 무관)이면 고급 검색·조건부 일괄 삭제(37차 '실제로 색인돼 있을 때만'의 의도)가
        //   그 1건만 봤고, 원격 재구축이 중간에 실패한 반쪽 색인도 같았음(G1#1 재현). 쓰는 곳: search_advanced·그 unindexed 안내, FileManager::bulkSearch
        //   ★ (2026-11-02 15:24, 44차 재검토) 일부 폴더 목록이 실패했어도 끝까지 돈 원격 재구축(index_built_storage_)도 인정
        $result = $this->db->query("SELECT key FROM meta WHERE (key LIKE 'last\\_sync\\_storage\\_%' ESCAPE '\\' OR key LIKE 'index\\_built\\_storage\\_%' ESCAPE '\\') AND value IS NOT NULL AND value <> ''");
        $ids = [];
        while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $ixKey = (string)$row['key'];
            $ixSid = substr($ixKey, strpos($ixKey, '_storage_') + 9);
            if ($ixSid !== '' && ctype_digit($ixSid)) $ids[(int)$ixSid] = (int)$ixSid;
        }
        return array_values($ids);
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
        // ★ (2026-10-28) 안전장치 — 로컬 경로가 비어 있으면(원격 스토리지 등) 서버 최상위 경로('/이름')를 보게 되므로 아무것도 안 함
        if (trim($basePath) === '') return ['added' => 0, 'removed' => 0, 'updated' => 0];
        
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
        // ★ (2026-09-03) 이번 회차에 우리가 직접 추가/갱신한 디렉터리 목록.
        //   부모 스캔이 자식 디렉터리의 modified 를 새 값으로 써 버리면, 정작 그 자식을 방문할 때
        //   DB 와 mtime 이 일치해 스킵된다 → 그 안의 변경이 **영구 유실**된다(실측 확인).
        //   그래서 "우리가 방금 쓴 값 때문에 일치한 것"은 스킵 근거로 삼지 않는다.
        $forceScanDirs = [];
        $forceScanMax  = 200000;   // 메모리 보호. 넘치면 초과분만 종전 동작으로 떨어진다.
        
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
                // ★ (2026-09-03) 강제 스캔 표시 복원 — 중단 후 이어서 돌 때도 유지되어야
                //   "부모가 방금 갱신해서 일치하는" 디렉터리가 다시 스킵되지 않는다.
                if (isset($checkpointData['force']) && is_array($checkpointData['force'])) {
                    foreach ($checkpointData['force'] as $fp) {
                        if (is_string($fp) && $fp !== '') $forceScanDirs[$fp] = true;
                    }
                }
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
                
                // ★ (2026-09-03) 이번 회차에 우리가 갱신한 디렉터리는 스킵하지 않는다.
                //   (DB 가 일치하는 이유가 "실제로 안 바뀜"이 아니라 "방금 우리가 씀"이기 때문)
                if ($skipScan && isset($forceScanDirs[$currentDir])) {
                    $skipScan = false;
                }

                if ($skipScan) {
                    // 변경 없음 — 하위 디렉토리만 큐에 추가 (인덱스에서 가져옴)
                    $prefix = $currentRelDir . '/';
                    // ★ (2026-09-03) 범위 조건(:rlo/:rhi) 추가 — idx_storage_filepath 를 타게 하려는 것.
                    //   LIKE 만 쓰면 SQLite 가 BINARY 인덱스를 못 써서(case_sensitive_like=OFF 기본값)
                    //   디렉터리 하나당 스토리지 전체 행을 훑는다. 실측 23.64ms → 0.08ms (311배).
                    //   [상한값] prefix 는 항상 '/'(0x2F) 로 끝나므로, 마지막 바이트를 '0'(0x30) 으로
                    //   바꾼 값이 상한이다. prefix 로 시작하는 모든 바이트열은 이 값보다 반드시 작아
                    //   빠지는 항목이 없다. (상한을 prefix 뒤에 0xFF 를 붙이는 방식으로 잡으면
                    //   0xFF 로 시작하는 깨진 파일명이 누락된다 — 리눅스 파일명은 임의 바이트가
                    //   가능하므로 실제 위험이다. 실측으로 확인해 이 방식으로 바꿨다.)
                    //   기존 LIKE 조건은 그대로 두므로 결과가 넓어질 수도 없다.
                    $stmt = $this->db->prepare("SELECT filepath FROM files WHERE storage_id = :sid AND filepath >= :rlo AND filepath < :rhi AND filepath LIKE :prefix AND filepath NOT LIKE :deeper AND is_dir = 1");
                    $stmt->bindValue(':sid', $storageId, SQLITE3_INTEGER);
                    $stmt->bindValue(':rlo', $prefix, SQLITE3_TEXT);
                    $stmt->bindValue(':rhi', substr($prefix, 0, -1) . '0', SQLITE3_TEXT);
                    $stmt->bindValue(':prefix', $prefix . '%', SQLITE3_TEXT);
                    $stmt->bindValue(':deeper', $prefix . '%/%', SQLITE3_TEXT);
                    $res = $stmt->execute();
                    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                        $childFullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['filepath']);
                        if ($this->isLinkLoop($childFullPath, $basePath)) continue;   // ★ (2026-11-01 10:21, 38차 점검 재검토) 변경 없는 폴더의 하위를 DB 에서 꺼내 넣는 빠른 경로에도 순환 링크 검사(링크일 때만 비용)
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
                        if ($isDir && !$this->isLinkLoop($fullPath, $basePath)) {   // ★ (2026-11-01 09:45, 38차 점검) 상위를 가리키는 링크 순환은 큐에 넣지 않음(isLinkLoop 참고)
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
                    // ★ (2026-09-03) 위와 같은 이유로 범위 조건 추가 (인덱스 사용 유도).
                    $stmt = $this->db->prepare("SELECT filepath, modified, size, is_dir FROM files WHERE storage_id = :sid AND filepath >= :rlo AND filepath < :rhi AND filepath LIKE :prefix AND filepath NOT LIKE :deeper");
                    $stmt->bindValue(':rlo', $prefix, SQLITE3_TEXT);
                    $stmt->bindValue(':rhi', substr($prefix, 0, -1) . '0', SQLITE3_TEXT);
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
                    $touchedDir = false;
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
                            $touchedDir = true;
                        }
                        unset($indexedInDir[$relPath]);
                    } else {
                        $this->addFile($storageId, $relPath, [
                            'is_dir' => $info['is_dir'] ? 1 : 0,
                            'size' => $info['size'],
                            'modified' => date('Y-m-d H:i:s', $info['mtime'])
                        ]);
                        $added++;
                        $touchedDir = true;
                    }
                    // ★ (2026-09-03) 우리가 방금 쓴 디렉터리는 이번 회차에 반드시 스캔한다.
                    //   이 표시가 없으면 그 안의 새 폴더·파일이 영구히 인덱싱되지 않는다(실측 확인).
                    if ($touchedDir && $info['is_dir'] && count($forceScanDirs) < $forceScanMax) {
                        $forceScanDirs[$basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath)] = true;
                    }
                }
                
                // 삭제: 인덱스에만 있고 파일시스템에 없는 항목
                // 현재 디렉토리의 직접 자식들만 비교하므로 이 디렉토리 자체는 완전히 스캔됨
                // → 다른 디렉토리가 큐에 남아있어도 이 디렉토리의 삭제 판정은 정확
                foreach ($indexedInDir as $relPath => $row) {
                    // ★ (2026-09-03) 디렉터리가 사라졌으면 하위 항목까지 함께 지운다.
                    //   기존에는 removeFile() 로 그 행 하나만 지워, 삭제된 폴더의 하위 파일들이
                    //   **인덱스에 고아로 남아 검색에 계속 나왔다**(실측 확인).
                    //   [왜 removeFolder() 를 쓰지 않는가] 그 메서드는 `filepath LIKE '경로/%'` 를
                    //   ESCAPE 없이 쓴다. 폴더 이름에 % 나 _ 가 있으면 **다른 폴더의 행까지 지운다** —
                    //   실측: `50%_off` 삭제 시 실제로 존재하는 `50Xyoff` 의 하위 3행이 함께 사라졌다.
                    //   그래서 여기서는 범위 조건으로 정확히 그 하위만 잘라낸다(와일드카드 없음 +
                    //   idx_storage_filepath 사용). 상한은 위와 같은 '마지막 바이트 증가' 방식이다.
                    //   (removeFolder() 자체는 다른 호출부 3곳이 쓰고 있어 손대지 않았다.)
                    if (!empty($row['is_dir'])) {
                        $delPrefix = $relPath . '/';
                        $delStmt = $this->db->prepare('DELETE FROM files WHERE storage_id = :sid AND filepath >= :rlo AND filepath < :rhi');
                        $delStmt->bindValue(':sid', $storageId, SQLITE3_INTEGER);
                        $delStmt->bindValue(':rlo', $delPrefix, SQLITE3_TEXT);
                        $delStmt->bindValue(':rhi', substr($delPrefix, 0, -1) . '0', SQLITE3_TEXT);
                        $delStmt->execute();
                    }
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
                // ★ (2026-09-03) 아직 방문 못 한 강제 스캔 대상만 저장한다(남은 큐와 교집합).
                $queueSet = array_flip($queueToSave);
                $forceToSave = [];
                foreach ($forceScanDirs as $fp => $_) {
                    if (isset($queueSet[$fp])) $forceToSave[] = $fp;
                }
                $checkpointPayload = [
                    'base_path' => $basePath,
                    'queue' => $queueToSave,
                    'force' => $forceToSave,
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
     * ★ (2026-11-01 09:45, 38차 점검) 심볼릭 링크·정션이 자기 상위 폴더(또는 스토리지 위쪽)를 가리켜 순환이 생기는지 확인.
     *   종전엔 is_dir() 이 링크를 따라가 OS 한계(리눅스 약 40단계)까지 되풀이 색인 → 같은 파일이 수십 번, 인덱스 합계로 내는 사용량도 부풀었음.
     *   링크가 아닌 폴더와, 바깥 폴더를 가리키는 정상 링크는 종전대로 따라감(링크일 때만 realpath 를 비교하므로 일반 폴더 순회 비용은 그대로).
     */
    private function isLinkLoop(string $dirFull, string $basePath): bool {
        if (!@is_link($dirFull)) return false;
        $target = @realpath($dirFull);
        if ($target === false) return false;
        $t = rtrim($target, '/\\') . DIRECTORY_SEPARATOR;
        $base = rtrim($basePath, '/\\');
        $p = dirname($dirFull);
        for ($i = 0; $i < 4096; $i++) {
            $rp = @realpath($p);
            if ($rp !== false) {
                $a = rtrim($rp, '/\\') . DIRECTORY_SEPARATOR;
                // 링크 대상이 조상 폴더 자신이거나 그 위쪽이면 순환
                if (strncasecmp($a, $t, strlen($t)) === 0 && (DIRECTORY_SEPARATOR === '\\' || strncmp($a, $t, strlen($t)) === 0)) return true;
            }
            if (strlen(rtrim($p, '/\\')) <= strlen($base)) break;   // 스토리지 맨 위까지 확인
            $up = dirname($p);
            if ($up === $p) break;
            $p = $up;
        }
        return false;
    }
    
    /**
     * 폴더를 재귀적으로 인덱싱
     */
    public function indexFolder(int $storageId, string $basePath, string $relativePath): void {
        if (!$this->available) return;
        // ★ (2026-10-28) 안전장치 — 로컬 경로가 비어 있으면(원격 스토리지 등) 서버 최상위 경로('/이름')를 보게 되므로 아무것도 안 함
        if (trim($basePath) === '') return;
        
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
            // ★ (2026-11-01 09:45, 38차 점검) 점(.)으로 시작하는 이름도 건너뜀 — 재구축·동기화·원격 색인·폴더 목록은 모두 빼는데 여기만 넣어 숨은 .vault.json 등이 검색에 나왔음
            if ($item === '.' || $item === '..' || $item[0] === '.') continue;
            $childRelative = $relativePath . '/' . $item;
            $childFull = $fullPath . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($childFull)) {
                if ($this->isLinkLoop($childFull, $basePath)) {   // ★ (2026-11-01 09:45, 38차 점검) 상위를 가리키는 링크 순환은 따라 들어가지 않음(isLinkLoop 참고) — 재구축처럼 링크 자체만 폴더로 등록
                    $this->addFile($storageId, $childRelative, ['name' => $item, 'size' => 0, 'modified' => @filemtime($childFull) ?: time(), 'is_dir' => true]);
                    continue;
                }
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
