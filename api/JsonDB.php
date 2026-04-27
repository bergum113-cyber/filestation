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
    
    // 데이터 로드 (공유 락 사용)
    public function load(string $name): array {
        $path = $this->getPath($name);
        
        if (!file_exists($path)) {
            return [];
        }
        
        // 파일 수정 시간 체크 (외부에서 수정되었는지 확인)
        $mtime = filemtime($path);
        if (isset($this->cache[$name]) && isset($this->cacheTime[$name]) && $this->cacheTime[$name] === $mtime) {
            return $this->cache[$name];
        }
        
        // 공유 락 획득
        $this->acquireSharedLock($name);
        
        $content = file_get_contents($path);
        $data = json_decode($content, true) ?? [];
        
        // 락 해제
        $this->releaseLock($name);
        
        $this->cache[$name] = $data;
        $this->cacheTime[$name] = $mtime;
        
        return $data;
    }
    
    // 데이터 저장 (배타적 락 사용)
    public function save(string $name, array $data): bool {
        $path = $this->getPath($name);
        
        // 배타적 락 획득 (실패 시 쓰기 중단)
        if (!$this->acquireExclusiveLock($name)) {
            error_log("[JsonDB] Failed to acquire lock for: {$name}");
            return false;
        }
        
        $this->cache[$name] = $data;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // 원자적 쓰기: 임시 파일에 쓴 후 rename (비정상 종료 시 파일 손상 방지)
        $tmpPath = $path . '.tmp';
        $result = file_put_contents($tmpPath, $json) !== false;
        if ($result) {
            $result = rename($tmpPath, $path);
            if (!$result) {
                // rename 실패 시 직접 쓰기 폴백
                $result = file_put_contents($path, $json) !== false;
                @unlink($tmpPath);
            }
        }
        
        // 캐시 시간 업데이트
        if ($result) {
            clearstatcache(true, $path);
            $this->cacheTime[$name] = filemtime($path);
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
            $data = json_decode($content, true) ?? [];
        }
        
        // 콜백으로 데이터 수정
        $newData = $callback($data);
        
        // 데이터 저장 (원자적 쓰기)
        $this->cache[$name] = $newData;
        $json = json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tmpPath = $path . '.tmp';
        $result = file_put_contents($tmpPath, $json) !== false;
        if ($result) {
            $result = rename($tmpPath, $path);
            if (!$result) {
                $result = file_put_contents($path, $json) !== false;
                @unlink($tmpPath);
            }
        }
        
        // 락 해제
        $this->releaseLock($name);
        
        return $result;
    }
    
    // 다음 ID
    public function nextId(string $name): int {
        $data = $this->load($name);
        if (empty($data)) return 1;
        
        $maxId = max(array_column($data, 'id'));
        return $maxId + 1;
    }
    
    // 값 비교 (숫자는 숫자끼리, 문자열은 문자열끼리 비교)
    private function matchValue($a, $b): bool {
        // 양쪽 다 숫자로 변환 가능하면 숫자 비교
        if (is_numeric($a) && is_numeric($b)) {
            return (int)$a === (int)$b;
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
        
        $this->atomicUpdate($name, function($data) use ($record, &$newId, $maxSize) {
            // 최대 ID 계산
            $maxId = empty($data) ? 0 : max(array_column($data, 'id'));
            $newId = $maxId + 1;
            $record['id'] = $newId;
            $data[] = $record;
            // 최대 건수 초과 시 오래된 항목 삭제
            if ($maxSize > 0 && count($data) > $maxSize) {
                $data = array_slice($data, -$maxSize);
            }
            return $data;
        });
        
        return $newId;
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
    public function clearCache(string $name = null): void {
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