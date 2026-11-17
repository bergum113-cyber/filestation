<?php
/**
 * JsonDB - JSON 파일 기반 데이터 저장 (파일 락킹 지원)
 */
class JsonDB {
    private static $instance = null;
    private $dataPath;
    private $cache = [];
    private $cacheTime = []; // 캐시된 파일의 mtime
    private $lockHandles = []; // 락 파일 핸들
    private $readFailed = []; // ★ (2026-11-11 12:24, 73차) 이 요청에서 파일이 있는데 읽지 못한 표(load 참고)

    private function __construct() {
        $this->dataPath = DATA_PATH;
        if (!is_dir($this->dataPath)) {
            mkdir($this->dataPath, 0755, true);
        }
        $this->init();
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function init(): void {
        // 빈 사용자 목록 (첫 가입자가 관리자가 됨)
        if (!$this->exists('users')) {
            $this->save('users', []);
        }
        
        // 빈 스토리지 목록
        if (!$this->exists('storages')) {
            // ★ (2026-11-11 12:24, 73차) 사용자가 있는(또는 번호를 준 적 있는) 설치에서 storages.json 만 없어진 것이면 표시 파일 'storages.json.missing-날짜' 를 남김 —
            //   새로 만든 빈 표를 '정상'으로 보고 공유 정리가 모든 공유를 '스토리지 삭제'로 지웠음(MIG_73 #1 재현). 표시는 ShareManager::storagesTableUnreliable 가 봄. 새 설치(사용자 없음)는 종전 그대로
            $this->markStoragesLost('missing');
            $this->save('storages', []);
        }
        
        // 빈 권한 목록
        if (!$this->exists('permissions')) {
            $this->save('permissions', []);
        }
        
        // 폴더별 권한 목록
        if (!$this->exists('folder_permissions')) {
            $this->save('folder_permissions', []);
        }
        
        // 빈 공유 목록
        if (!$this->exists('shares')) {
            $this->save('shares', []);
        }
        // ★ (2026-11-02 10:28, 42차 점검) 첫 실행 때 data/ 에 쓰지 못하면 아무 기록 없이 넘어가 원인을 찾기 어려웠음(E5#8) → 서버 오류 로그에 한 줄 남김(동작은 그대로)
        if (!$this->exists('users')) {
            error_log('[JsonDB] cannot create data files in ' . $this->dataPath . ' — check write permission for the web server user');
        }
    }
    
    /**
     * ★ (2026-11-11 12:27, 73차) 이미 쓰던 설치(사용자가 있거나 스토리지 번호를 준 적 있음)에서 storages.json 이 없어졌거나 0바이트로 읽히면 표시 파일
     *   'storages.json.missing-날짜' 를 한 번 남김(이미 있으면 그대로). 빈 표를 정상으로 보고 공유 정리가 모든 공유를 '스토리지 삭제'로 지웠음(MIG_73 #1 재현).
     *   표시는 ShareManager::storagesTableUnreliable 가 봄. 새 설치(사용자 없음)는 아무것도 안 함
     */
    private function markStoragesLost(string $why): void {
        $sp = $this->getPath('storages');
        if (glob($sp . '.missing-*')) return;
        $seq = $this->load('_seq');
        if (empty($this->load('users')) && (int)($seq['storages'] ?? 0) <= 0) return;
        @touch($sp . '.missing-' . date('Ymd-His'));
        error_log("[JsonDB] storages.json {$why} in an existing install — marker left, share cleanup by storage is paused");
    }

    // 파일 경로
    private function getPath(string $name): string {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid DB name: ' . $name);
        }
        return $this->dataPath . '/' . $name . '.json';
    }
    
    // 락 파일 경로
    private function getLockPath(string $name): string {
        return $this->dataPath . '/' . $name . '.lock';
    }
    
    // 존재 여부
    public function exists(string $name): bool {
        return file_exists($this->getPath($name));
    }
    
    // 공유 락 획득 (읽기용)
    private function acquireSharedLock(string $name): bool {
        $lockPath = $this->getLockPath($name);
        $handle = fopen($lockPath, 'c+');
        if (!$handle) {
            return false;
        }
        
        // flock 대기 시간 측정 (디버그 로그 활성화 시만)
        $lockWaitStart = microtime(true);
        $locked = flock($handle, LOCK_SH);
        $lockWaitMs = (microtime(true) - $lockWaitStart) * 1000;
        
        // 대기 시간이 10ms 이상이면 로그에 기록 (LOCK_EX 경쟁으로 읽기가 대기 중임을 뜻함)
        if ($lockWaitMs > 10 && function_exists('_sessionDebugLog')) {
            _sessionDebugLog('JSONDB:flock_wait_SH', sprintf('name=%s wait=%.0fms', $name, $lockWaitMs));
        }
        
        if ($locked) {
            $this->lockHandles[$name] = $handle;
            return true;
        }
        
        fclose($handle);
        return false;
    }
    
    // 배타적 락 획득 (쓰기용)
    private function acquireExclusiveLock(string $name): bool {
        $lockPath = $this->getLockPath($name);
        $handle = fopen($lockPath, 'c+');
        if (!$handle) {
            return false;
        }
        
        // flock 대기 시간 측정 (디버그 로그 활성화 시만)
        $lockWaitStart = microtime(true);
        $locked = flock($handle, LOCK_EX);
        $lockWaitMs = (microtime(true) - $lockWaitStart) * 1000;
        
        // 대기 시간이 10ms 이상이면 로그에 기록 (flock 경쟁 감지)
        if ($lockWaitMs > 10 && function_exists('_sessionDebugLog')) {
            _sessionDebugLog('JSONDB:flock_wait_EX', sprintf('name=%s wait=%.0fms', $name, $lockWaitMs));
        }
        
        if ($locked) {
            $this->lockHandles[$name] = $handle;
            return true;
        }
        
        fclose($handle);
        return false;
    }
    
    // 락 해제
    private function releaseLock(string $name): void {
        if (isset($this->lockHandles[$name])) {
            flock($this->lockHandles[$name], LOCK_UN);
            fclose($this->lockHandles[$name]);
            unset($this->lockHandles[$name]);
        }
    }
    
    /**
     * ★ (2026-11-04, 56차 C1-4) 캐시 비교 값 — filemtime 은 정수 초라 같은 초 안에 다른 프로세스가 고친 표를 오래 도는 프로세스(sync_cron·작업 큐·SSE)가 끝까지 못 봤음(CLI 재현).
     *   크기와 inode(쓰기는 임시 파일+rename 이라 쓸 때마다 바뀜)를 함께 봄. stat 실패면 null(캐시 안 씀). 캐시 동작은 그대로
     */
    private function fileStamp(string $path): ?string {
        clearstatcache(true, $path);
        $st = @stat($path);
        return $st ? $st['mtime'] . ':' . $st['size'] . ':' . $st['ino'] : null;
    }

    // 데이터 로드 (공유 락 사용)
    public function load(string $name): array {
        $path = $this->getPath($name);
        
        if (!file_exists($path)) {
            return [];
        }
        
        // 파일 수정 시간 체크 (외부에서 수정되었는지 확인)
        $mtime = $this->fileStamp($path);   // ★ (2026-11-04, 56차 C1-4) 수정 시각(초)만 보던 것을 수정 시각+크기+inode 로(fileStamp 설명)
        if (isset($this->cache[$name]) && isset($this->cacheTime[$name]) && $this->cacheTime[$name] === $mtime) {
            return $this->cache[$name];
        }
        
        // 공유 락 획득
        $this->acquireSharedLock($name);
        
        $content = file_get_contents($path);
        // ★ (2026-11-11 12:24, 73차) 파일이 있는데 읽기 자체가 실패(false)하면 빈 표를 캐시하지 않고 '읽기 실패' 표시만 — 종전엔 [] 를 캐시해 같은 요청의 load→save 가
        //   빈 표 기준으로 덮어썼음(MIG_73 #5). 표시가 있는 동안 save() 는 쓰지 않음. api.php 는 오류 처리기가 이 경고를 예외로 바꿔 종전처럼 여기 닿지 않음
        if ($content === false) {
            $this->releaseLock($name);
            $this->readFailed[$name] = true;
            unset($this->cache[$name], $this->cacheTime[$name]);
            error_log("[JsonDB] read failed for existing file: {$name} — treated as empty, not cached, writes blocked");
            return [];
        }
        unset($this->readFailed[$name]);
        $data = $this->decodeOrKeep($name, $path, $content);

        // 락 해제
        $this->releaseLock($name);

        $this->cache[$name] = $data;
        $this->cacheTime[$name] = $mtime;
        
        return $data;
    }
    
    /**
     * ★ (2026-10-31 13:19, 34차 후속 개선) JSON 해석 — 내용이 있는데 해석에 실패하면(손상) 원본을 '.corrupt-날짜' 로 한 번 보관하고 [] 를 돌려줌.
     *   종전엔 손상을 빈 표로만 봐서, 다음 저장이 [] 기준으로 덮어써 복구할 수 있던 원본이 사라졌음.
     *   (users 가 손상되면 가입 화면이 '첫 사용자 = 관리자' 로 열리던 것은 isCorrupt() 로 api.php 에서 막음)
     */
    private function decodeOrKeep(string $name, string $path, $content): array {
        // ★ (2026-11-02 11:49, 43차 점검) 맨 앞 UTF-8 BOM 을 떼고 해석 — 메모장 'UTF-8(BOM)' 으로 손댄 파일이 손상으로 [] 가 되어, 다음 저장이 화면에 없는 키(SMTP 비밀번호·SSO 등)를 지웠음(F2#11)
        if (is_string($content) && strncmp($content, "\xEF\xBB\xBF", 3) === 0) $content = substr($content, 3);
        // ★ (2026-11-11 12:24, 73차) 0바이트(BOM 만 포함)는 종전처럼 빈 표, 길이가 있는데 공백·NUL 뿐이면 손상(아래 보관) — 종전엔 trim 이 NUL 까지 지워 정전 뒤 0 으로 채워진
        //   users.json 을 '빈 표'로 보고 '첫 사용자 = 관리자' 가입이 열렸음(MIG_73 #4 재현). JsonDB 는 공백만 있는 파일을 쓴 적이 없음
        if (!is_string($content) || $content === '') {
            if ($name === 'storages' && $content === '') $this->markStoragesLost('was empty (0 bytes)');   // ★ (2026-11-11 12:27, 73차) 위 markStoragesLost 설명
            return [];
        }
        $data = trim($content) === '' ? null : json_decode($content, true);
        if (is_array($data)) return $data;
        $bak = $path . '.corrupt-' . date('Ymd-His', (int)@filemtime($path));
        if (!file_exists($bak)) { @copy($path, $bak); error_log("[JsonDB] corrupt JSON kept as: {$bak}"); }
        return [];
    }
    /** ★ (2026-10-31 13:19, 34차 후속 개선) 파일이 있는데 해석할 수 없는지(손상) — 첫 사용자 판정 등에서 '비어 있음' 과 구분 */
    public function isCorrupt(string $name): bool {
        $path = $this->getPath($name);
        if (!is_file($path)) return false;
        $c = @file_get_contents($path);
        if ($c === false) return true;
        if (strncmp($c, "\xEF\xBB\xBF", 3) === 0) $c = substr($c, 3);   // ★ (2026-11-02 11:49, 43차 점검) 위 decodeOrKeep 과 같게 BOM 은 손상으로 보지 않음
        if ($c === '') return false;
        if (trim($c) === '') return true;   // ★ (2026-11-11 12:24, 73차) 공백·NUL 뿐 = 손상(위 decodeOrKeep 과 같은 기준)
        return !is_array(json_decode($c, true));
    }

    /**
     * ★ (2026-11-11 12:24, 73차) 이미 쓰던 설치인지(설치 흔적) — '첫 사용자 = 관리자' 판정용. 사용자 번호를 준 적 있음(_seq.json users),
     *   홈 스토리지가 있음, users.json 손상 보관본이 있음 중 하나면 참. users.json 이 0바이트·없음·[] 이 되어도 처음 설치로 보지 않게(MIG_73 #4).
     *   완전히 빈 data(처음 설치)에서는 거짓이라 첫 가입은 종전처럼 관리자
     */
    public function hasInstallTraces(): bool {
        $seq = $this->load('_seq');
        if ((int)($seq['users'] ?? 0) > 0) return true;
        foreach ($this->load('storages') as $s) {
            if (is_array($s) && ($s['storage_type'] ?? '') === 'home') return true;
        }
        return (bool)glob($this->getPath('users') . '.corrupt-*');
    }

    /** ★ (2026-11-11 12:24, 73차) 이 요청에서 그 표를 읽지 못했는지(load 의 읽기 실패 표시) */
    public function readFailed(string $name): bool {
        return !empty($this->readFailed[$name]);
    }
    /** ★ (2026-10-31 13:19, 34차 후속 개선) 윈도우에서 다른 요청이 파일을 잠깐 열고 있으면 rename 이 실패해 원자적이지 않은 직접 쓰기로 넘어갔음 → 몇 번 다시 시도 */
    private function renameRetry(string $from, string $to): bool {
        for ($i = 0; $i < 5; $i++) {
            if (@rename($from, $to)) return true;
            usleep(40000);
        }
        return false;
    }
    
    // 데이터 저장 (배타적 락 사용)
    public function save(string $name, array $data): bool {
        $path = $this->getPath($name);
        
        // 배타적 락 획득 (실패 시 쓰기 중단)
        if (!$this->acquireExclusiveLock($name)) {
            error_log("[JsonDB] Failed to acquire lock for: {$name}");
            return false;
        }
        // ★ (2026-11-11 12:24, 73차) 이 요청에서 이 표를 읽지 못했으면(load 의 읽기 실패) 쓰지 않음 — 넘어온 값이 빈 표 기준일 수 있음(MIG_73 #5). 정상 읽기 뒤에는 종전 그대로
        if (!empty($this->readFailed[$name]) && file_exists($path)) {
            error_log("[JsonDB] save blocked for {$name}: the file could not be read in this request");
            $this->releaseLock($name);
            return false;
        }

        // ★ (2026-11-01 07:18, 37차 기능 점검) 잘못된 UTF-8 한 글자(UA·파일 이름 등)로 json_encode 가 false 가 되면 0바이트를 써 표 전체가 비었음(비로그인으로도 로그 표를 지울 수 있었고,
        //   sessions 가 비면 세션 끊기도 빠졌음) → 깨진 글자는 대체 문자로 바꿔 인코딩하고, 그래도 실패하면 쓰지 않고 false
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            error_log("[JsonDB] json_encode failed for: {$name} — not written");
            $this->releaseLock($name);
            return false;
        }
        // ★ (2026-11-01 18:00, 40차 재검토) 캐시는 쓰기에 성공한 뒤에 넣음 — api.php 오류 처리기가 쓰기 경고를 예외로 바꿔 아래 '실패면 캐시 버림'에 닿기 전에 빠져나가,
        //   저장 안 된 값이 캐시에 남았음(검토 발견, 실패 시 예외로 끝나는 종전 흐름은 그대로)
        
        // 원자적 쓰기: 임시 파일에 쓴 후 rename (비정상 종료 시 파일 손상 방지)
        $tmpPath = $path . '.tmp';
        $result = file_put_contents($tmpPath, $json) !== false;
        if ($result) {
            $result = $this->renameRetry($tmpPath, $path);
            if (!$result) {
                // rename 실패 시 직접 쓰기 폴백
                $result = file_put_contents($path, $json) !== false;
                @unlink($tmpPath);
            }
        }
        
        // 캐시 시간 업데이트
        if ($result) {
            $this->cache[$name] = $data;
            $this->cacheTime[$name] = $this->fileStamp($path);   // ★ (2026-11-04, 56차 C1-4) load 와 같은 비교 값(종전 clearstatcache + filemtime)
        } else {
            // ★ (2026-11-01 17:19, 40차 점검) 쓰기 실패 때 메모리 캐시에 저장 안 된 새 값이 남아(파일 mtime 은 그대로라) 같은 요청의 다음 load() 가 그 값을 돌려줬음 → 캐시를 버려 파일에서 다시 읽게
            unset($this->cache[$name], $this->cacheTime[$name]);
        }
        
        // 락 해제
        $this->releaseLock($name);
        
        return $result;
    }
    
    // 원자적 업데이트 (읽기-수정-쓰기를 하나의 락으로 처리)
    public function atomicUpdate(string $name, callable $callback): bool {
        $path = $this->getPath($name);
        
        // 배타적 락 획득
        if (!$this->acquireExclusiveLock($name)) {
            return false;
        }
        
        // 데이터 읽기 (캐시 무시)
        $data = [];
        if (file_exists($path)) {
            $content = file_get_contents($path);
            // ★ (2026-11-11 12:24, 73차) 파일이 있는데 읽기 자체가 실패하면 빈 표로 고쳐 쓰지 않고 저장 실패(false) — 종전엔 [] 기준 결과로 덮어써 표 전체가 백업 없이 사라졌음
            //   (share.php·mydav.php·sync_cron.php·onlyoffice.php 처럼 오류 처리기가 없는 진입점, MIG_73 #5 재현). api.php 는 오류 처리기가 먼저 예외로 바꿈(종전과 같음)
            if ($content === false) {
                $this->releaseLock($name);
                $this->readFailed[$name] = true;
                unset($this->cache[$name], $this->cacheTime[$name]);
                error_log("[JsonDB] read failed in atomicUpdate for: {$name} — not written");
                return false;
            }
            $data = $this->decodeOrKeep($name, $path, $content);
        }
        
        // 콜백으로 데이터 수정
        // ★ (2026-11-01 07:18, 37차 기능 점검) 콜백이 예외를 던져도 잠금은 풀고 예외를 그대로 넘김(종전엔 잠금이 남아 같은 요청에서 그 표를 다시 쓰면 멈출 수 있었음)
        try {
            $newData = $callback($data);
        } catch (\Throwable $e) {
            $this->releaseLock($name);
            throw $e;
        }
        
        // 데이터 저장 (원자적 쓰기)
        // ★ (2026-11-01 07:18, 37차 기능 점검) 위 save 와 같은 인코딩 실패 처리(0바이트로 표가 비지 않게)
        $json = json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            error_log("[JsonDB] json_encode failed in atomicUpdate for: {$name} — not written");
            $this->releaseLock($name);
            return false;
        }
        $tmpPath = $path . '.tmp';   // ★ (2026-11-01 18:00, 40차 재검토) 캐시는 쓰기 성공 뒤에(위 save 와 같음)
        $result = file_put_contents($tmpPath, $json) !== false;
        if ($result) {
            $result = $this->renameRetry($tmpPath, $path);
            if (!$result) {
                $result = file_put_contents($path, $json) !== false;
                @unlink($tmpPath);
            }
        }
        // ★ (2026-11-01 17:19, 40차 점검) 위 save 와 같음 — 쓰기 실패면 저장 안 된 값을 캐시에 남기지 않음
        if ($result) $this->cache[$name] = $newData; else unset($this->cache[$name], $this->cacheTime[$name]);
        
        // 락 해제
        $this->releaseLock($name);
        
        return $result;
    }
    
    // 다음 ID
    // ★ (2026-11-02 10:28, 42차 점검) 지금까지 준 가장 큰 번호(data/_seq.json)도 봄 — 종전엔 '현재 최대+1'이라 가장 최근 행을 지우면 같은 번호가 다시 나갔음(E1#1). 미리 보기용(번호를 잡지 않음)
    public function nextId(string $name): int {
        $data = $this->load($name);
        $ids = empty($data) ? [] : array_column($data, 'id');
        $maxId = $ids ? (int)max($ids) : 0;
        $seq = $this->load('_seq');
        return max((int)($seq[$name] ?? 0), $maxId) + 1;
    }

    /**
     * ★ (2026-11-02 10:28, 42차 점검) 새 번호 잡기 — 표마다 지금까지 준 가장 큰 번호를 data/_seq.json 에 기억해 max(기억값, 현재 최대)+1 을 줌.
     *   종전 insert 는 '현재 최대+1'이라 가장 최근 사용자·스토리지를 지우고 새로 만들면 같은 번호가 다시 나가, 옛 번호에 딸린 색인·파일 버전·숨김·동기화 작업 등이
     *   새 행에 붙었음(E1#1 재현). 표 잠금을 잡은 채(insert 안) 부르므로 같은 표에서 번호가 겹치지 않음. 기억 파일을 못 쓰면 종전처럼 현재 최대+1.
     *   잠금 순서는 늘 '표 → _seq'(이 함수만 _seq 를 잠그고, 그 안에서 다른 표를 잠그지 않음). 업그레이드 전에 이미 지워진 번호는 기억에 없어 한 번은 다시 나갈 수 있음
     */
    // ★ (2026-11-02 15:04, 44차 점검) public — api.php 최근 파일 배치가 표 잠금(atomicUpdate 콜백) 안에서 insert 와 같은 번호를 잡으려고. 반드시 그 표의 atomicUpdate 콜백 안에서만 부를 것
    public function reserveId(string $name, int $maxId, int $count = 1): int {   // ★ (2026-11-02 14:55, 44차 점검) $count — insertMany 가 번호 여러 개를 한 번에 잡음(첫 번호를 돌려주고 기억값은 마지막 번호). 1 이면 종전과 같음
        $newId = $maxId + 1;
        if ($name === '_seq') return $newId;
        $count = max(1, $count);
        try {
            $this->atomicUpdate('_seq', function ($seq) use ($name, $maxId, $count, &$newId) {
                $seq = is_array($seq) ? $seq : [];
                $newId = max((int)($seq[$name] ?? 0), $maxId) + 1;
                $seq[$name] = $newId + $count - 1;
                return $seq;
            });
        } catch (\Throwable $e) {
            $this->releaseLock('_seq');   // ★ (2026-11-02 11:00, 42차 재검토) 예외로 빠져나오면 _seq 잠금이 풀리지 않아 같은 요청의 다음 insert(와 다른 요청의 insert)가 자기 잠금을 기다리며 멈췄음(검토 재현)
            error_log("[JsonDB] _seq update failed for {$name}: " . $e->getMessage());
        }
        return $newId;
    }
    
    // 값 비교 (숫자는 숫자끼리, 문자열은 문자열끼리 비교)
    public function matchValue($a, $b): bool {   // ★ (2026-11-02 15:04, 44차 점검) public — api.php 가 atomicUpdate 콜백 안에서 find/delete 와 같은 비교를 쓰려고(동작 그대로)
        // 양쪽 다 정수(정수형 또는 숫자만 있는 문자열)면 숫자 비교 — 아이디 1 과 '1' 을 같게 봄
        // ★ (2026-10-31 12:01, 34차 전체 점검) 종전엔 is_numeric 이면 (int) 로 바꿔 비교해, 공유 토큰처럼 무작위 16진 문자열이
        //   '123e4567…'·'0e1234…' 같은 지수 표기로 읽히면 (int) 가 0 이 돼 't=0' 하나로 그 공유가 열렸음(재현).
        //   → 소수점·지수 없는 정수 표기일 때만 숫자 비교, 나머지는 글자 그대로 비교
        $intLike = function ($v) { return is_int($v) || (is_string($v) && preg_match('/^-?\d{1,18}\z/', $v)); };
        if ($intLike($a) && $intLike($b)) {
            return (int)$a === (int)$b;
        }
        if (is_float($a) || is_float($b)) {
            return is_numeric($a) && is_numeric($b) && (float)$a === (float)$b;
        }
        return (string)$a === (string)$b;
    }
    
    // 레코드 찾기 (단일)
    public function find(string $name, array $where): ?array {
        $data = $this->load($name);
        
        foreach ($data as $row) {
            $match = true;
            foreach ($where as $key => $value) {
                if (!isset($row[$key]) || !$this->matchValue($row[$key], $value)) {
                    $match = false;
                    break;
                }
            }
            if ($match) return $row;
        }
        
        return null;
    }
    
    // 레코드 찾기 (복수)
    public function findAll(string $name, array $where = []): array {
        $data = $this->load($name);
        
        if (empty($where)) return $data;
        
        $results = [];
        foreach ($data as $row) {
            $match = true;
            foreach ($where as $key => $value) {
                if (!isset($row[$key]) || !$this->matchValue($row[$key], $value)) {
                    $match = false;
                    break;
                }
            }
            if ($match) $results[] = $row;
        }
        
        return $results;
    }
    
    // 레코드 추가 (원자적)
    public function insert(string $name, array $record, int $maxSize = 0): int {
        $newId = 0;
        
        // ★ (2026-11-01 17:19, 40차 점검) 저장에 실패하면 0 — 종전엔 실패해도 새 id 를 돌려줘 부르는 쪽이 성공으로 응답했음
        $ok = $this->atomicUpdate($name, function($data) use ($name, $record, &$newId, $maxSize) {   // ★ (2026-11-02 10:30, 42차 점검) reserveId 에 표 이름을 넘기려고 $name 추가
            // 최대 ID 계산
            $maxId = empty($data) ? 0 : max(array_column($data, 'id'));
            $newId = $this->reserveId($name, (int)$maxId);   // ★ (2026-11-02 10:28, 42차 점검) 지운 번호를 다시 주지 않게(reserveId 설명) — 종전 $maxId + 1
            $record['id'] = $newId;
            $data[] = $record;
            // 최대 건수 초과 시 오래된 항목 삭제
            if ($maxSize > 0 && count($data) > $maxSize) {
                $data = array_slice($data, -$maxSize);
            }
            return $data;
        });
        
        return $ok ? $newId : 0;
    }
    
    /**
     * // ★ (2026-11-02 14:55, 44차 점검) 여러 행을 표 잠금·쓰기 한 번으로 추가 — 같은 행을 insert() 로 하나씩 연달아 넣은 것과 같은 결과(번호 차례·'id' 키 위치·최대 건수 자르기).
     *   작업 큐 활동 기록처럼 반복문에서 한 건씩 넣으면 표 전체를 건수만큼 다시 써서 느렸음(G3#4). 첫 번호를 돌려줌(실패 0)
     */
    public function insertMany(string $name, array $records, int $maxSize = 0): int {
        $records = array_values($records);
        if (!$records) return 0;
        $firstId = 0;
        $ok = $this->atomicUpdate($name, function($data) use ($name, $records, &$firstId, $maxSize) {
            $maxId = empty($data) ? 0 : max(array_column($data, 'id'));
            $firstId = $this->reserveId($name, (int)$maxId, count($records));
            foreach ($records as $k => $record) {
                $record['id'] = $firstId + $k;
                $data[] = $record;
            }
            if ($maxSize > 0 && count($data) > $maxSize) {
                $data = array_slice($data, -$maxSize);
            }
            return $data;
        });
        return $ok ? $firstId : 0;
    }
    
    // 레코드 수정 (원자적)
    public function update(string $name, array $where, array $update): int {
        $count = 0;
        
        $this->atomicUpdate($name, function($data) use ($where, $update, &$count) {
            foreach ($data as &$row) {
                $match = true;
                foreach ($where as $key => $value) {
                    if (!isset($row[$key]) || !$this->matchValue($row[$key], $value)) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    foreach ($update as $key => $value) {
                        $row[$key] = $value;
                    }
                    $count++;
                }
            }
            return $data;
        });
        
        return $count;
    }
    
    // 레코드 삭제 (원자적)
    public function delete(string $name, array $where): int {
        $count = 0;
        
        $this->atomicUpdate($name, function($data) use ($where, &$count) {
            $data = array_filter($data, function($row) use ($where, &$count) {
                foreach ($where as $key => $value) {
                    if (!isset($row[$key]) || !$this->matchValue($row[$key], $value)) {
                        return true; // 유지
                    }
                }
                $count++;
                return false; // 삭제
            });
            return array_values($data);
        });
        
        return $count;
    }
    
    // 캐시 클리어
    public function clearCache(?string $name = null): void {
        if ($name) {
            unset($this->cache[$name]);
        } else {
            $this->cache = [];
        }
    }
    
    // 소멸자 - 남아있는 락 해제
    public function __destruct() {
        foreach ($this->lockHandles as $name => $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->lockHandles = [];
    }
}