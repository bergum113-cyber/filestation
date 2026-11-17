<?php
/**
 * ShareManager - 공유 링크 관리 (JSON 기반)
 */
class ShareManager {
    // ★ (2026-11-12 16:10, 100차 CVR-1) 공유 표지 추출(extractID3v2Cover) 판 — '표지 없음' 표시(.nocover)에 ';cv:판' 을 적고 같은 판 표시만 믿음(옛 표시는 만나면 지우고 다시 추출).
    //   share.php 표지 주소의 &cv= 에도 쓰여 브라우저의 옛 204 캐시를 피함. 추출 코드를 고치면 이 숫자만 올리면 됨(성공한 표지 캐시는 그대로 씀) — 종전엔 99차 AUD-1 뒤에도 옛 판 표시가 남아 204(재현)
    public const COVER_VER = 2;
    private $db;
    private $auth;
    private $storage;
    
    public function __construct() {
        $this->db = JsonDB::getInstance();
        $this->auth = new Auth();
        $this->storage = new Storage();
    }

    /**
     * ★ (2026-11-11 12:24, 73차) 스토리지 표를 믿을 수 없는 상태인지 — 손상·읽기 실패·빈 표이거나, 손상 보관본(storages.json.corrupt-*)·없어짐 표시(storages.json.missing-*, JsonDB::init)가 있음.
     *   이때 '스토리지 없음 → 공유 삭제'를 건너뜀(MIG_73 #1: 깨진 storages.json 을 빈 표로 읽어 모든 공유·내부 공유가 백업 없이 지워졌음, 비로그인 방문 한 번으로도).
     *   손상된 표는 첫 쓰기 때 정상 표로 바뀌므로 보관본·표시 파일이 남아 있는 동안(관리자가 복구·정리할 때까지) 계속 건너뜀. 정상 설치(보관본 없음)는 종전 그대로
     */
    private ?bool $stTableUnreliable = null;
    private function storagesTableUnreliable(): bool {
        if ($this->stTableUnreliable !== null) return $this->stTableUnreliable;
        $base = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/storages.json';
        $this->stTableUnreliable = $this->db->readFailed('storages') || $this->db->isCorrupt('storages') || empty($this->db->load('storages'))
            || (bool)glob($base . '.corrupt-*') || (bool)glob($base . '.missing-*');
        return $this->stTableUnreliable;
    }

    /**
     * ★ (2026-11-11 12:24, 73차) 공유를 '스토리지/경로 없음'·'파일 없음'으로 지워도 되는지 — 지우면 안 되는 경우 false.
     *   $kind: 'storage'(스토리지 행 없음) / 'path'(행은 있는데 기준 경로 null) / 'file'(기준 폴더는 열리는데 파일 없음)
     *   ① 'storage': 스토리지 표를 믿을 수 없으면(위 함수) 지우지 않음. 표가 정상인데 그 스토리지만 없으면 종전처럼 지움
     *   ② 'path': SMB 는 접속 정보를 못 풀면(totp_settings.json 손상·키 변경) 경로가 null 이라 지우지 않음(MIG_73 #3). 그 밖의 종류는 종전처럼 지움
     *   ③ 'file': storage_paths.json 이 깨져 기본 폴더로 대체된 요청(config.php FS_STORAGE_PATHS_BROKEN)이면 그 경로를 쓰는 홈·공용 폴더 스토리지의 공유는 지우지 않음(MIG_73 #2)
     */
    // ★ (2026-11-11 13:14, 74차) private → public: api.php 'folder_permissions' 의 폴더 규칙 자동 정리도 같은 판정('file')을 씀. 판정 내용은 그대로
    public function shareCleanupSafe(int $storageId, string $kind, ?array $stRow = null): bool {
        if ($kind === 'storage') return !$this->storagesTableUnreliable();
        if ($stRow === null) $stRow = $this->storage->getStorageById($storageId);
        $type = (string)($stRow['storage_type'] ?? '');
        if ($kind === 'path') return $type !== 'smb';
        if ($kind === 'file') return !(defined('FS_STORAGE_PATHS_BROKEN') && FS_STORAGE_PATHS_BROKEN && ($type === 'home' || $type === 'shared'));
        return true;
    }

    // 파일에 대한 기존 공유 확인
    public function checkShare(int $storageId, string $filePath): array {
        $userId = $this->auth->getUserId();
        
        $shares = $this->db->load('shares');
        
        // 경로 정규화
        $normalizedPath = str_replace(['\\', '\/'], '/', $filePath);
        
        foreach ($shares as $share) {
            $sharePath = str_replace(['\\', '\/'], '/', $share['file_path']);
            
            if ($share['storage_id'] == $storageId && 
                $sharePath == $normalizedPath && 
                $share['created_by'] == $userId) {
                
                // 만료 확인 - 만료된 공유는 삭제
                if (!empty($share['expire_at']) && strtotime($share['expire_at']) < time()) {
                    $this->_cleanShareCache($share);  // ★ 캐시 정리
                    $this->db->delete('shares', ['id' => $share['id']]);
                    continue;
                }
                
                // 다운로드 횟수 초과 확인 - 초과한 공유는 삭제
                // ★ (2026-10-29 21:54, 24차 점검) 파일받기 링크는 주인이 아직 못 본 업로드 알림(pending_uploads)이 남아 있으면 지우지 않음(알림이 사라지지 않게)
                if (!empty($share['max_downloads']) && 
                    ($share['download_count'] ?? 0) >= $share['max_downloads'] &&
                    !(($share['share_type'] ?? '') === 'filedrop' && (int)($share['pending_uploads'] ?? 0) > 0)) {
                    $this->_cleanShareCache($share);  // ★ 캐시 정리
                    $this->db->delete('shares', ['id' => $share['id']]);
                    continue;
                }
                
                // ★ 보안: 비번 해시는 클라이언트에 노출하지 않음 (has_password 플래그만)
                $share['has_password'] = !empty($share['password']);
                unset($share['password']);
                
                return ['success' => true, 'share' => $share];
            }
        }
        
        return ['success' => true, 'share' => null];
    }
    
    // 공유 링크 생성
    public function createShare(int $storageId, string $filePath, array $options = []): array {
        // 개인 폴더 공유 허용 여부 체크
        $storageInfo = $this->storage->getStorageById($storageId);
        if ($storageInfo && ($storageInfo['storage_type'] ?? '') === 'home') {
            $settings = $this->db->load('settings');
            $homeShareEnabled = $settings['home_share_enabled'] ?? true;
            if (!$homeShareEnabled) {
                return ['success' => false, 'error' => __('api_err_personal_share_disabled', '개인 폴더 외부 공유가 비활성화되어 있습니다.')];
            }
        }
        
        if (!$this->storage->checkPermission($storageId, 'can_share')) {
            return ['success' => false, 'error' => __('api_err_no_share_perm', '공유 권한이 없습니다.')];
        }
        
        // 경로 정규화
        $filePath = str_replace(['\\', '\/'], '/', $filePath);

        // 경로 탐색 공격 방지
        if (preg_match('#(^|[\\/])\\.\\.($|[\\/])#', $filePath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        // ★ (2026-11-01 07:19, 37차 기능 점검) 저장 경로를 Storage::normFolderPath 처럼 '.'·빈 조각 제거('./a'·'/a'·'a//b'·끝 '/' → 'a/b') — 종전엔 그대로 저장해,
        //   글자 그대로 비교하는 삭제·이름 바꾸기 뒤 공유 정리(cleanupSharesForPath)·목록 배지에서 빠져 옛 링크가 남았음(대소문자·끝 점은 실제 이름이라 그대로 둠)
        $filePath = implode('/', array_filter(explode('/', $filePath), static fn($s) => $s !== '' && $s !== '.'));

        // 파일 존재 확인
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        
        // 경로 안전성 검증
        if (!$this->isSharePathSafe($basePath, $fullPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')];
        }
        
        $token = $this->generateToken();
        
        $expireAt = null;
        // ★ (2026-11-02 10:26, 42차 점검) 만든 시각을 만료 계산과 같은 time() 값으로 — 종전엔 password_hash(수십 ms) 뒤 date() 라 초가 바뀌면
        //   1일 공유가 86399초가 돼 공유 관리 '1일' 필터에서 빠지고 '1시간 이하'로 분류됐음(비밀번호 공유의 약 6%)
        $_createdTs = time();
        if (!empty($options['expire_days'])) {
            $days = min(36500.0, (float)$options['expire_days']);   // ★ (2026-11-16 12:31, 233차) 위 한도 100년 — 아주 큰 값(예: 1e9일)은 만료일이 수백만 년 뒤가 되어 목록·정리(strtotime)가 못 읽고 만료로 봐 만들자마자 지워졌음(재현: '성공'인데 공유가 없음). 화면 선택지(30분~30일)는 그대로
            $seconds = max(60, (int)round($days * 86400)); // 최소 1분
            $expireAt = date('Y-m-d H:i:s', $_createdTs + $seconds);
        } elseif (!empty($options['expire_at'])) {
            // ★ (2026-10-31 12:03, 34차 전체 점검) 문자열·날짜로 읽히는 값만 — 종전엔 배열 등을 그대로 저장해, 공유 목록·정리(strtotime)가 TypeError 로
            //   모든 사용자에게 500 이 났음
            $ts = is_string($options['expire_at']) ? strtotime($options['expire_at']) : false;
            $expireAt = $ts ? date('Y-m-d H:i:s', $ts) : null;
        }
        
        $password = null;
        // ★ (2026-11-01 17:16, 40차 점검) 비밀번호 '0' 이 empty('0') 로 '없음'이 돼 비밀번호 없는 공개 링크가 만들어졌음 → 빈 문자열일 때만 '없음'
        if (isset($options['password']) && !is_scalar($options['password'])) {   // 배열 등은 비밀번호 없는 링크로 만들지 않고 거부(종전엔 password_hash TypeError 로 실패)
            return ['success' => false, 'error' => __('api_err_invalid_request', '잘못된 요청입니다.')];
        }
        $_pwIn = is_scalar($options['password'] ?? null) ? (string)$options['password'] : '';
        if ($_pwIn !== '') {
            $password = password_hash($_pwIn, PASSWORD_DEFAULT);
        }
        
        $id = $this->db->insert('shares', [
            'token' => $token,
            'storage_id' => $storageId,
            'file_path' => $filePath,
            'created_by' => $this->auth->getUserId(),
            'password' => $password,
            'expire_at' => $expireAt,
            'max_downloads' => (is_numeric($options['max_downloads'] ?? null) && (int)$options['max_downloads'] > 0) ? (int)$options['max_downloads'] : null,   // ★ (2026-10-31 12:03, 34차 전체 점검) 정수로
            'download_count' => 0,
            'share_type' => $options['share_type'] ?? 'download',
            'is_dir' => is_dir($fullPath) ? 1 : 0,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s', $_createdTs)   // ★ (2026-11-02 10:26, 42차 점검) 위 만료 계산과 같은 시각
        ]);
        
        // ★ (2026-11-01 17:21, 40차 점검) 저장 실패(insert 0)를 안 보고 성공·토큰을 응답해 동작하지 않는 링크를 줬음 → 실패로 응답
        if (!$id) {
            return ['success' => false, 'error' => __('err_save_failed', '저장 실패')];
        }
        // 디버깅: 저장 후 다시 로드해서 확인
        $savedShares = $this->db->load('shares');
        
        $shareUrl = $this->getShareUrl($token);
        
        return [
            'success' => true,
            'id' => $id,
            'token' => $token,
            'url' => $shareUrl
        ];
    }
    
    // 공유 링크 목록
    // ★ (2026-10-29 14:22, 21차 점검) $mineOnly — '내 공유 링크' 는 관리자도 본인이 만든 것만(README '내가 만든 공유 링크'·메뉴 옆 개수와 같게).
    //   종전엔 관리자의 '내 공유 링크' 에 모든 사용자의 공유가 나왔음(재현). '공유 관리'(전체)는 그대로
    public function getShares(bool $mineOnly = false): array {
        // 만료된 공유 자동 정리
        $this->cleanupExpiredShares();
        
        $userId = $this->auth->getUserId();
        $isAdmin = $this->auth->isAdmin();
        
        $shares = $this->db->load('shares');
        $users = $this->db->load('users');
        $storages = $this->db->load('storages');
        
        // ★ (2026-11-02 17:37, 46차 점검) 공유마다 users·storages 를 처음부터 훑었음(공유 수 × 사용자 수, H1#2) → (int)id → 첫 행 표를 한 번 만들어 씀(종전 '첫 일치에서 break' 와 같은 행)
        $uMap = []; foreach ($users as $user) { $k = (int)$user['id']; if (!array_key_exists($k, $uMap)) $uMap[$k] = $user; }
        $sMap = []; foreach ($storages as $storage) { $k = (int)$storage['id']; if (!array_key_exists($k, $sMap)) $sMap[$k] = $storage; }
        
        $result = [];
        foreach ($shares as $share) {
            if ((!$isAdmin || $mineOnly) && (int)$share['created_by'] !== (int)$userId) {
                continue;
            }
            
            // 생성자 이름 추가
            if (array_key_exists((int)$share['created_by'], $uMap)) {   // ★ (2026-11-02 17:37, 46차 점검) 종전 foreach ($users …) 첫 일치
                $user = $uMap[(int)$share['created_by']];
                $share['creator_name'] = $user['username'];
            }
            
            // 스토리지 이름 추가
            if (array_key_exists((int)$share['storage_id'], $sMap)) {   // ★ (2026-11-02 17:37, 46차 점검) 종전 foreach ($storages …) 첫 일치
                $storage = $sMap[(int)$share['storage_id']];
                $share['storage_name'] = $storage['name'];
                // is_dir이 없는 기존 데이터 대응: 실제 경로로 확인
                if (!isset($share['is_dir'])) {
                    $basePath = $this->storage->getRealPath((int)$share['storage_id']);
                    if ($basePath) {
                        $fp = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
                        $share['is_dir'] = is_dir($fp) ? 1 : 0;
                    }
                }
            }
            
            // ★ 보안: 비번 해시는 클라이언트에 노출하지 않음 (has_password 플래그만)
            $share['has_password'] = !empty($share['password']);
            unset($share['password']);
            
            $result[] = $share;
        }
        
        // 최신순 정렬
        // ★ (2026-11-02 17:37, 46차 점검) 비교마다 strtotime 을 두 번 불렀음(H1#2) → 항목마다 한 번 계산해 번호 배열을 같은 비교식으로 정렬(안정 정렬이라 동률 순서도 같음)
        $ts = array_map(fn($r) => strtotime($r['created_at']), $result);
        $ix = array_keys($result);
        usort($ix, function($a, $b) use ($ts) { return $ts[$b] - $ts[$a]; });
        $result = array_map(fn($i) => $result[$i], $ix);
        
        return $result;
    }
    
    // 만료된 공유 자동 정리
    /**
     * 경로 안전성 검증 (realpath + fallback)
     * Windows에서 특수문자([], (), 공백, ... 등) 파일명에 realpath가 실패하는 경우 대비
     */
    private function isSharePathSafe(string $basePath, string $fullPath): bool {
        // ★ (2026-10-29 22:39, 24차 점검) 기준 경로가 비면(원격 스토리지) 거부 — realpath('') 가 앱 폴더라, 원격 스토리지 번호로 앱 폴더 아래 파일의 공유·내려받기가 통과할 수 있었음(onlyoffice 경로와 같은 원인, FileManager::isPathSafe 24차와 같은 기준)
        if (trim($basePath) === '') return false;
        $realBase = realpath($basePath);
        if ($realBase === false) {
            return false;
        }
        
        // 1차: realpath 시도
        $realFull = realpath($fullPath);
        if ($realFull !== false) {
            return \isSubPath($realFull, $realBase);
        }
        
        // 2차: realpath 실패 — 정규화 경로 비교 + file_exists 확인
        
        // .. 제거한 정규화
        $sep = DIRECTORY_SEPARATOR;
        $normalized = str_replace(['/', '\\'], $sep, $fullPath);
        $parts = explode($sep, $normalized);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                if (empty($resolved)) return false;
                array_pop($resolved);
            } elseif ($part !== '' && $part !== '.') {
                $resolved[] = $part;
            }
        }
        $cleanPath = implode($sep, $resolved);
        $cleanBase = str_replace(['/', '\\'], $sep, $realBase);
        
        // 정규화된 경로가 base 내에 있는지 확인 (대소문자 무시 - Windows)
        if (!\isSubPath($cleanPath, $cleanBase)) {
            return false;
        }
        
        // file_exists 체크
        if (file_exists($fullPath)) {
            return true;
        }
        
        // file_exists도 실패 — Windows 긴 경로(\\?\) 시도
        if (PHP_OS_FAMILY === 'Windows' && strlen($fullPath) > 2) {
            $winPath = $fullPath;
            // UNC 경로가 아닌 경우에만 \\?\ 접두사 추가
            if (substr($winPath, 0, 2) !== '\\\\') {
                $winPath = '\\\\?\\' . $winPath;
            }
            if (file_exists($winPath)) {
                return true;
            }
        }
        
        // 최종 시도: is_file / is_dir
        if (is_file($fullPath) || is_dir($fullPath)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * realpath 대체 — Windows 특수문자 파일명에서 realpath 실패 시 fallback
     */
    private function safeRealpath(string $path): string|false {
        $real = realpath($path);
        if ($real !== false) return $real;
        
        // realpath 실패 시: 파일이 존재하면 정규화된 경로 반환
        if (!file_exists($path)) return false;
        
        // .. 해소한 정규화 경로
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = explode(DIRECTORY_SEPARATOR, $normalized);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                if (empty($resolved)) return false;
                array_pop($resolved);
            } elseif ($part !== '' && $part !== '.') {
                $resolved[] = $part;
            }
        }
        return implode(DIRECTORY_SEPARATOR, $resolved);
    }
    
    // ★ (2026-11-02 17:37, 46차 점검) 여러 id 를 표 잠금·쓰기 한 번으로 지움 — delete($table, ['id' => $id]) 를 id 마다 차례로 부른 것과 같은 결과(같은 matchValue 비교, 남는 행 순서 유지).
    //   api.php fsDbDeleteIds 와 같은 방식(share.php 등 api.php 없이도 불리므로 여기 따로 둠)
    private function deleteIdsOnce(string $table, array $ids): void {
        $ids = array_values($ids);
        if (!$ids) return;
        $db = $this->db;
        $db->atomicUpdate($table, function ($data) use ($db, $ids) {
            $intLike = function ($v) { return is_int($v) || (is_string($v) && preg_match('/^-?\d{1,18}\z/', $v)); };
            $allInt = true; $intSet = [];
            foreach ($ids as $id) { if ($intLike($id)) $intSet[(int)$id] = true; else { $allInt = false; break; } }
            $keep = [];
            foreach ((is_array($data) ? $data : []) as $row) {
                $hit = false;
                if (isset($row['id'])) {   // delete 도 id 없는 행은 남김
                    if ($allInt && $intLike($row['id'])) $hit = isset($intSet[(int)$row['id']]);   // 둘 다 정수 모양이면 matchValue 도 (int) 비교
                    else { foreach ($ids as $id) { if ($db->matchValue($row['id'], $id)) { $hit = true; break; } } }
                }
                if (!$hit) $keep[] = $row;
            }
            return $keep;
        });
    }
    
    public function cleanupExpiredShares(): int {
        $shares = $this->db->load('shares');
        $deletedCount = 0;
        $delIds = [];   // ★ (2026-11-02 17:37, 46차 점검) 아래 한 번에 지우기용
        $now = time();
        
        
        foreach ($shares as $share) {
            $shouldDelete = false;
            $deleteReason = '';
            
            // 1. 만료일이 지난 경우
            if (!empty($share['expire_at']) && strtotime($share['expire_at']) < $now) {
                $shouldDelete = true;
                $deleteReason = 'expired';
            }
            
            // 2. 다운로드 횟수 초과 (★ 24차 — 파일받기 링크에 못 본 업로드 알림이 있으면 남김, 위와 같음)
            if (!empty($share['max_downloads']) && 
                ($share['download_count'] ?? 0) >= $share['max_downloads'] &&
                !(($share['share_type'] ?? '') === 'filedrop' && (int)($share['pending_uploads'] ?? 0) > 0)) {
                $shouldDelete = true;
                $deleteReason = 'max_downloads';
            }
            
            // 3. 실제 파일이 존재하지 않는 경우
            if (!$shouldDelete) {
                $basePath = $this->storage->getRealPath($share['storage_id']);
                if ($basePath) {
                    $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
                    
                    
                    // ★ (2026-10-29 18:42, 22차 점검) 스토리지 자체에 닿을 때만 '파일 없음'으로 지움 — 종전엔 SMB·외장 디스크가 잠깐 끊겨도 그 스토리지의 공유가 모두 영구 삭제됐음
                    if (!file_exists($fullPath) && is_dir($basePath) && $this->shareCleanupSafe((int)$share['storage_id'], 'file')) {   // ★ (2026-11-11 12:24, 73차) 경로 설정이 깨진 요청이면 홈·공용 폴더 공유는 남김(shareCleanupSafe)
                        $shouldDelete = true;
                        $deleteReason = 'file_not_exists';
                    }
                } else {
                    // 스토리지가 삭제된 경우
                    // ★ (2026-11-11 12:24, 73차) 스토리지 표를 믿을 수 없거나(손상·없어짐) SMB 접속 정보를 못 푼 경우는 지우지 않음(shareCleanupSafe) — 그 밖은 종전 그대로
                    $stRowC = $this->storage->getStorageById((int)$share['storage_id']);
                    if ($stRowC ? $this->shareCleanupSafe((int)$share['storage_id'], 'path', $stRowC) : $this->shareCleanupSafe((int)$share['storage_id'], 'storage')) {
                        $shouldDelete = true;
                        $deleteReason = 'storage_deleted';
                    }
                }
            }
            
            if ($shouldDelete) {
                $this->_cleanShareCache($share);  // ★ 캐시 정리 (자동 정리 함수 — 일관성)
                $delIds[] = $share['id'] ?? null;   // ★ (2026-11-02 17:37, 46차 점검) 종전 여기서 한 건씩 $this->db->delete('shares', …) — 아래에서 한 번에
                $deletedCount++;
            }
        }
        // ★ (2026-11-02 17:37, 46차 점검) 지울 공유마다 shares 표 전체를 다시 썼음(500건 만료 시 18초, H1#4) → 모아서 한 번에(종전 delete 를 차례로 부른 것과 남는 행·순서 같음)
        $this->deleteIdsOnce('shares', $delIds);
        
        return $deletedCount;
    }
    
    // 공유 링크 삭제
    public function deleteShare(int $id): array {
        $share = $this->db->find('shares', ['id' => $id]);
        
        if (!$share) {
            return ['success' => false, 'error' => __('api_err_share_not_found', '공유를 찾을 수 없습니다.')];
        }
        
        if (!$this->auth->isAdmin() && $share['created_by'] != $this->auth->getUserId()) {
            return ['success' => false, 'error' => __('api_err_no_permission', '권한이 없습니다.')];
        }
        
        // ★ 캐시 정리 (DB 삭제 전에 — share 정보 필요)
        $this->_cleanShareCache($share);
        
        $this->db->delete('shares', ['id' => $id]);
        return ['success' => true];
    }
    
    /**
     * ★ 공유 캐시 정리 (외부 호출용 public 래퍼 — 펜닐님 결정 이슈 #19)
     * 
     * 사용처: FileManager::cleanupSharesForPath (파일/폴더 삭제 시 자동 공유 삭제)
     * 캡슐화 유지를 위해 _cleanShareCache는 private으로 두고 이 래퍼로 외부 노출
     * 
     * @param array $share shares 테이블 row (token, is_dir, file_path, storage_id 등)
     */
    public function cleanShareCacheByShare(array $share): void {
        $this->_cleanShareCache($share);
    }
    
    /**
     * ★ 공유 캐시 정리 (펜닐님 결정 옵션 C: deleteShare + 만료 자동 정리)
     * 
     * 정리 대상:
     *  1. data/cache/folder_tracks/{md5(token)}.json (폴더 트랙 캐시)
     *  2. data/thumbcache/share_audio/{sha1(token+'|'+subFile)}.bin/.meta/.nocover (폴더 cover)
     *  3. data/thumbcache/share_audio/{md5(token+'/'+filePath+'/'+mtime)}.img/.meta/.nocover (단일 파일 cover)
     * 
     * 폴더 cover의 subFile 목록 추출 우선순위:
     *  1. folder_tracks 캐시에서 추출 (캐시 살아있으면 가장 정확)
     *  2. 캐시 만료/없음 → 실제 폴더 직접 스캔 (음악 확장자 파일 목록)
     *  3. 폴더 자체가 삭제됐으면 정리 불가 (stale 캐시 누적 — 미미한 수준)
     */
    private function _cleanShareCache(array $share): void {
        $token = $share['token'] ?? '';
        if ($token === '') return;
        
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        
        // 1. folder_tracks 캐시 정리 + subFile 목록 추출 (cover 정리에 활용)
        $folderTracksFile = $dataDir . '/cache/folder_tracks/' . md5($token) . '.json';
        $subFiles = [];
        if (is_file($folderTracksFile)) {
            $cacheData = @file_get_contents($folderTracksFile);
            if ($cacheData !== false) {
                $cached = @json_decode($cacheData, true);
                if (is_array($cached) && isset($cached['tracks']) && is_array($cached['tracks'])) {
                    foreach ($cached['tracks'] as $t) {
                        if (isset($t['file'])) $subFiles[] = $t['file'];
                    }
                }
            }
            @unlink($folderTracksFile);
        }
        
        // 2. share_audio 캐시 디렉토리
        $shareAudioDir = $dataDir . '/thumbcache/share_audio';
        if (!is_dir($shareAudioDir)) return;
        
        // 3-A. 폴더 공유 cover (subFile별 sha1 키)
        if (!empty($share['is_dir'])) {
            // ★ subFiles가 비어있으면 (folder_tracks 캐시 만료/없음) — 실제 폴더 직접 스캔
            //   getSharedFolderTracks와 동일한 음악 확장자 화이트리스트 적용
            if (empty($subFiles) && !empty($share['file_path']) && !empty($share['storage_id'])) {
                $basePath = $this->storage->getRealPath($share['storage_id']);
                if ($basePath) {
                    $folderPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
                    if (is_dir($folderPath)) {
                        $audioExts = ['mp3', 'm4a', 'm4b', 'aac', 'ogg', 'oga', 'opus', 'flac', 'wav', 'weba'];   // ★ (2026-10-08) 음악은 웹 플레이어가 여는 형식만(펜닐 결정 — wma·ape·alac·aiff 는 브라우저가 재생 못 함)
                        $items = @scandir($folderPath);
                        if ($items !== false) {
                            foreach ($items as $item) {
                                if ($item === '.' || $item === '..') continue;
                                if (substr($item, 0, 1) === '.') continue;  // 숨김 파일 skip
                                $itemPath = $folderPath . DIRECTORY_SEPARATOR . $item;
                                if (!is_file($itemPath)) continue;
                                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                                // mp3만 cover 캐시 대상 (getShareCover에서 mp3만 처리)
                                if ($ext === 'mp3') {
                                    $subFiles[] = $item;
                                }
                            }
                        }
                    }
                }
            }
            
            // 트랙별 cover 캐시 삭제
            foreach ($subFiles as $subFile) {
                $key = sha1($token . '|' . $subFile);
                @unlink($shareAudioDir . '/' . $key . '.bin');
                @unlink($shareAudioDir . '/' . $key . '.meta');
                @unlink($shareAudioDir . '/' . $key . '.nocover');
                @unlink($shareAudioDir . '/' . $key . '.w512.jpg');   // ★ (2026-09-26) 줄인 커버(09-23 share.php 커버 512px) — 빠져 있어 공유를 지워도 남았다(재검토)
            }
        }
        
        // 3-B. 단일 파일 공유 cover (token + file_path + mtime)
        //   mtime을 모를 수 있으므로 best-effort로 현재 mtime 시도
        if (empty($share['is_dir']) && !empty($share['file_path'])) {
            // 실제 파일 mtime 시도 (best-effort)
            if (!empty($share['storage_id'])) {
                $basePath = $this->storage->getRealPath($share['storage_id']);
                if ($basePath) {
                    $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
                    if (is_file($fullPath)) {
                        $mtime = @filemtime($fullPath);
                        if ($mtime !== false) {
                            // ★ (2026-11-02 10:27, 42차 점검) getShareCover 의 새 키(시각-크기)와 예전 키(시각만) 둘 다 지움
                            foreach ([md5($token . '/' . $share['file_path'] . '/' . $mtime . '-' . (int)@filesize($fullPath)), md5($token . '/' . $share['file_path'] . '/' . $mtime)] as $key) {
                            @unlink($shareAudioDir . '/' . $key . '.img');
                            @unlink($shareAudioDir . '/' . $key . '.meta');
                            @unlink($shareAudioDir . '/' . $key . '.nocover');
                            @unlink($shareAudioDir . '/' . $key . '.w512.jpg');   // ★ (2026-09-26) 줄인 커버(09-23 share.php 커버 512px) — 빠져 있어 공유를 지워도 남았다(재검토)
                            }
                        }
                    }
                }
            }
        }
        
        // 참고: stale 캐시 파일 (파일 변경/이름변경 등으로 키가 바뀐 옛 캐시)은 남을 수 있음
        // → TTL 기반 정리는 펜닐님 결정 (옵션 C)에서 제외 → 누적은 미미한 수준이라 무시
    }
    
    // 공유 링크로 접근
    /**
     * ★ (2026-10-28 18:22, 소스 점검 5단계) 공유 비밀번호 무차별 대입 제한 — 틀린 비밀번호를 IP+토큰 단위로 세어
     *   10분 안에 10회를 넘으면 잠시 막음(맞으면 기록 삭제). 종전엔 횟수·지연 제한이 전혀 없었음.
     */
    private function sharePwRlFile(string $token): string {
        $dir = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/rate_limits';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        // ★ (2026-10-28 19:55, 6차 점검) 종전엔 X-Forwarded-For 를 무조건 믿어 요청마다 값을 바꾸면 횟수가 매번 새로 시작됐음 →
        //   api.php getClientIP()(TRUSTED_PROXIES 에서 온 연결만 헤더 인정)와 같은 기준. 그 함수가 없는 진입점(share.php)은 REMOTE_ADDR
        // ★ (2026-10-31 12:03, 34차 전체 점검) 전역 getClientIP 는 api.php 에만 있어 share.php 에서는 늘 REMOTE_ADDR(프록시 뒤면 모두 한 주소)였음 → Auth 와 같은 기준
        $ip = $this->auth ? (string)$this->auth->getCurrentIP() : (function_exists('getClientIP') ? (string)getClientIP() : (string)($_SERVER['REMOTE_ADDR'] ?? '0'));
        return $dir . '/sharepw_' . md5($ip . '|' . $token) . '.json';
    }
    private function sharePwBlocked(string $token, int $max = 10, int $window = 600): bool {
        $f = $this->sharePwRlFile($token);
        $now = time();
        $data = @json_decode((string)@file_get_contents($f), true) ?: [];
        $data = array_filter($data, fn($t) => ($now - $t) < $window);
        return count($data) >= $max;
    }
    private function sharePwFail(string $token, int $window = 600): void {
        $f = $this->sharePwRlFile($token);
        $fp = @fopen($f, 'c+'); if (!$fp) return;
        if (flock($fp, LOCK_EX)) {
            $now = time();
            $data = @json_decode(stream_get_contents($fp), true) ?: [];
            $data = array_values(array_filter($data, fn($t) => ($now - $t) < $window));
            $data[] = $now;
            ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($data)); fflush($fp); flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    // ★ (2026-10-31 13:44, 34차 후속 개선) 확인과 기록을 한 잠금 안에서(Auth::twoFaAttemptReserve 와 같은 방식) — 종전엔 sharePwBlocked(잠금 없이 읽기)와 sharePwFail(실패 뒤 기록)이 따로라
    //   틀린 비밀번호 요청을 동시에 많이 보내면 모두 password_verify 까지 진행해 10회를 넘게 시도할 수 있었음. 시도를 먼저 1회로 기록하고, 맞으면 sharePwClear 로 비움.
    //   반환 false = 이미 한도(막음)
    private function sharePwReserve(string $token, int $max = 10, int $window = 600): bool {
        $fp = @fopen($this->sharePwRlFile($token), 'c+');
        if (!$fp) return true;   // 기록 파일을 못 열면 막지 않음(sharePwFail 이 조용히 넘어가던 종전 동작과 같게)
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
    // ★ (2026-10-31 13:44, 34차 후속 개선) 지우지 않고 잠금 안에서 비움(twoFaFailClear 와 같음) — 이제 맞은 시도도 먼저 기록되므로, Windows 에서 다른 요청이 연 파일 삭제가 조용히 실패하면 기록이 쌓일 수 있었음
    private function sharePwClear(string $token): void {
        $f = $this->sharePwRlFile($token);
        if (!is_file($f)) return;
        $fp = @fopen($f, 'c+'); if (!$fp) return;
        if (flock($fp, LOCK_EX)) { ftruncate($fp, 0); fflush($fp); flock($fp, LOCK_UN); }
        fclose($fp);
    }

    /** ★ (2026-10-29 18:46, 22차 점검) 세션 인증 표시 값 — 공유 비밀번호(해시)가 바뀌면 달라짐 */
    public static function shareAuthMark(array $share): string {
        return substr(hash('sha256', 'fs-share-auth|' . (string)($share['password'] ?? '')), 0, 24);
    }
    public static function isShareSessionAuthed(string $token, array $share): bool {
        $v = $_SESSION['share_authenticated'][$token] ?? null;
        return is_string($v) && hash_equals(self::shareAuthMark($share), $v);
    }
    
    // ★ (2026-11-01 01:52, 36차 전체 점검) $resumeRange — downloadShare 만 넘김: 이 세션에서 이미 받기 시작했고 Range 시작>0 인 이어받기(단일 파일)는 횟수 초과로 지우지 않음
    /**
     * ★ (2026-11-17 13:28, 322차 — 펜닐 결정 "둘 다 접근 때 다시 확인", README 결정 필요 2204·2398줄 정리) 공유를 연 지금도 만든 사람이 쓸 수 있는 계정이고
     *   만들 때 요구한 권한을 아직 가졌는지 — 종전엔 만든 사람의 권한을 빼거나 계정을 정지해도 공개 공유·파일 받기·내부 공유가 계속 동작했음(재현).
     *   기준은 만들 때와 같음(api.php share_create·internal_share_create): 공유 경로 읽기(부모 + 하위 폴더 규칙), 받기·스트리밍은 내려받기,
     *   파일 받기는 쓰기(경로 규칙 포함), 내부 공유는 내려받기 + 요청한 작업이 쓰기면 쓰기·전체면 지우기까지(하위 규칙 포함). 권한을 돌려주면 다시 동작.
     *   실제 관리자가 만든 공유와 만든 사람이 기록되지 않은 옛 공유는 종전 그대로
     */
    private function _creatorStillAllowed(array $share, bool $internal, string $need = 'read'): bool {
        $uid = (int)($internal ? ($share['shared_by'] ?? 0) : ($share['created_by'] ?? 0));
        if ($uid <= 0) return true;
        $u = $this->db->find('users', ['id' => $uid]);
        if (!$u) return false;
        $status = $u['status'] ?? 'active';
        if ($status === 'suspended') { if (function_exists('fsUserSuspendedNow') && fsUserSuspendedNow($u)) return false; }
        elseif ($status !== 'active') return false;
        if (!($u['is_active'] ?? 1)) return false;
        if (($u['role'] ?? '') === 'admin') return true;
        $sid = (int)($share['storage_id'] ?? 0);
        $path = (string)($share['file_path'] ?? '');
        $par = dirname(str_replace('\\', '/', $path));
        if ($par === '.' || $par === '/' || $par === '\\') $par = '';
        $st = $this->storage;
        if (!$st->userFolderPermAllowed($uid, $sid, $par !== '' ? $par : $path, 'can_read', false) || !$st->userFolderPermAllowed($uid, $sid, $path, 'can_read', true)) return false;
        if ($internal) {
            if (!$st->userStoragePermAllowed($uid, $sid, 'can_download')) return false;
            if ($need === 'write' || $need === 'full') {
                if (!$st->userStoragePermAllowed($uid, $sid, 'can_write') || !$st->userFolderPermAllowed($uid, $sid, $path, 'can_write', true)) return false;
            }
            if ($need === 'full') {
                if (!$st->userStoragePermAllowed($uid, $sid, 'can_delete') || !$st->userFolderPermAllowed($uid, $sid, $path, 'can_delete', true)) return false;
            }
        } elseif (($share['share_type'] ?? 'download') === 'filedrop') {
            if (!$st->userStoragePermAllowed($uid, $sid, 'can_write') || !$st->userFolderPermAllowed($uid, $sid, $path, 'can_write', false)) return false;
        } else {
            if (!$st->userStoragePermAllowed($uid, $sid, 'can_download')) return false;
        }
        return true;
    }

    public function accessShare(string $token, ?string $password = null, bool $resumeRange = false): array {
        $share = $this->db->find('shares', ['token' => $token, 'is_active' => 1]);
        
        if (!$share) {
            return ['success' => false, 'error' => __('api_err_share_link_not_found', '공유 링크를 찾을 수 없습니다.')];
        }
        
        $storage = $this->db->find('storages', ['id' => $share['storage_id']]);
        if (!$storage) {
            // 스토리지가 삭제된 경우 공유도 삭제
            if ($this->shareCleanupSafe((int)$share['storage_id'], 'storage')) $this->db->delete('shares', ['id' => $share['id']]);   // ★ (2026-11-11 12:24, 73차) 스토리지 표를 믿을 수 없으면 지우지 않고 같은 응답만(MIG_73 #1)
            return ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
        }
        
        if (!$this->_creatorStillAllowed($share, false)) return ['success' => false, 'error' => __('share_creator_no_access', '공유한 사람에게 이 항목에 대한 권한이 없어 열 수 없습니다.')];   // ★ (2026-11-17 13:28, 322차) 위 _creatorStillAllowed
        
        // 만료 확인
        if ($share['expire_at'] && strtotime($share['expire_at']) < time()) {
            // 만료된 공유 삭제
            $this->_cleanShareCache($share);  // ★ 캐시 정리
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => __('api_err_share_expired', '만료된 공유 링크입니다.')];
        }
        
        // 다운로드 횟수 확인
        // ★ (2026-10-29 21:54, 24차 점검) 파일받기(filedrop) 링크는 이 칸이 '업로드 수'라 지우지 않고 업로드 횟수 초과로만 알림 — 종전엔 한도까지 올라온 뒤 누가 페이지를 열기만 해도
        //   공유가 지워져 주인의 '새 업로드' 알림까지 사라졌고, 방문자에겐 '다운로드 횟수 초과'가 떴음(업로드 함수는 거부만 하고 지우지 않음)
        if ($share['max_downloads'] && $share['download_count'] >= $share['max_downloads'] && ($share['share_type'] ?? '') === 'filedrop') {
            return ['success' => false, 'error' => __('ishare_upload_limit', '업로드 횟수를 초과했습니다.')];
        }
        // ★ (2026-11-01 01:52, 36차 전체 점검) 마지막 허용 다운로드의 이어받기·분할 받기가 '횟수 초과'로 막히고 공유까지 지워지던 것 —
        //   16·20차의 '이어받기는 세지 않음'(share_dl_started)이 이 검사 뒤에 있어서였음. 그 표시가 있는 Range 시작>0 단일 파일 요청만 통과(폴더 ZIP 은 Range 를 안 써 그대로 막음)
        $resumeOk = false;
        if ($resumeRange && $share['max_downloads'] && $share['download_count'] >= $share['max_downloads']) {
            $_rbp = $this->storage->getRealPath($share['storage_id']);
            $resumeOk = $_rbp && is_file($_rbp . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']));
        }
        if ($share['max_downloads'] && $share['download_count'] >= $share['max_downloads'] && !$resumeOk) {
            // 횟수 초과 공유 삭제
            $this->_cleanShareCache($share);  // ★ 캐시 정리
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => __('api_err_share_download_limit', '다운로드 횟수를 초과했습니다.')];
        }
        
        // 비밀번호 확인
        if ($share['password']) {
            // 세션에서 인증 완료 여부 먼저 확인
            // ★ (2026-10-29 18:46, 22차 점검) 세션 인증 표시는 '그때의 비밀번호'에 묶음(shareAuthMark) — 종전엔 true 하나라, 공유자가 비밀번호를 바꿔도 이미 들어온 방문자는 계속 보고·받고·올릴 수 있었음
            $sessionAuthed = self::isShareSessionAuthed($token, $share);
            if (($password === null || $password === '') && !$sessionAuthed) {   // ★ (2026-11-01 17:16, 40차 점검) '0' 을 '안 보냄'으로 봐 비밀번호 '0' 공유를 열 수 없었음
                return ['success' => false, 'error' => 'password_required', 'needs_password' => true];
            }
            // ★ (2026-10-28 18:22, 소스 점검 5단계) 무차별 대입 제한 — 틀린 시도가 많으면 잠시 막음
            if (!$sessionAuthed && !$this->sharePwReserve($token)) {   // ★ (2026-10-31 13:44, 34차 후속 개선) 확인+기록을 한 잠금 안에서
                return ['success' => false, 'error' => __('api_err_too_many_attempts', '시도가 너무 많습니다. 잠시 후 다시 시도하세요.')];
            }
            if (!$sessionAuthed && !password_verify($password, $share['password'])) {
                // (실패 기록은 위 sharePwReserve 에서 이미 함)
                return ['success' => false, 'error' => __('api_err_share_password_wrong', '비밀번호가 올바르지 않습니다.')];
            }
            if (!$sessionAuthed) {
                $this->sharePwClear($token);   // 맞았으면 기록 삭제
                if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['share_authenticated'][$token] = self::shareAuthMark($share);   // ★ (2026-10-29 18:46, 22차 점검)
            }
        }
        
        // 파일 경로 확인 - Storage 클래스 사용
        $basePath = $this->storage->getRealPath($share['storage_id']);
        
        if (!$basePath) {
            if ($this->shareCleanupSafe((int)$share['storage_id'], 'path', $storage)) $this->db->delete('shares', ['id' => $share['id']]);   // ★ (2026-11-11 12:24, 73차) SMB 접속 정보를 못 푼 경우는 지우지 않음(MIG_73 #3)
            return ['success' => false, 'error' => __('api_err_storage_path_not_found', '스토리지 경로를 찾을 수 없습니다.')];
        }
        
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
        
        // ★ (2026-11-03 11:48, 50차 점검) 앱 밖에서 지운 파일 — 아래 경로 검사(realpath)가 없는 파일을 '잘못된 경로' 로 돌려보내 22차의 '공유 삭제 + 파일이 존재하지 않습니다'
        //   처리에 닿지 못했음(재현: 파일을 지운 뒤 방문해도 공유가 남음). 저장된 경로에 '..' 가 없을 때만 먼저 존재 여부를 봄('..' 는 종전대로 아래 검사가 거부)
        $shFileMissing = !file_exists($fullPath) && !in_array('..', explode('/', str_replace('\\', '/', (string)$share['file_path'])), true);
        // 경로 안전성 검증
        if (!$shFileMissing && !$this->isSharePathSafe($basePath, $fullPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if ($shFileMissing || !file_exists($fullPath)) {
            // ★ (2026-10-29 18:42, 22차 점검) 스토리지 자체에 닿지 않으면(일시 끊김) 공유는 남기고 실패만 — 종전엔 방문 한 번에 영구 삭제됐음
            if (!is_dir($basePath)) {
                return ['success' => false, 'error' => __('api_err_storage_path_not_found', '스토리지 경로를 찾을 수 없습니다.')];
            }
            // 파일이 삭제된 경우 공유도 삭제
            if ($this->shareCleanupSafe((int)$share['storage_id'], 'file')) $this->db->delete('shares', ['id' => $share['id']]);   // ★ (2026-11-11 12:24, 73차) 경로 설정이 깨진 요청이면 홈·공용 폴더 공유는 남김(MIG_73 #2)
            return ['success' => false, 'error' => __('api_err_shared_file_not_exists', '파일이 존재하지 않습니다.')];
        }
        
        $isDir = is_dir($fullPath);
        $filename = basename($share['file_path']);
        
        return [
            'success' => true,
            'share' => [
                'token' => $share['token'],
                'filename' => $filename,
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : filesize($fullPath),
                'created_at' => $share['created_at'],
                'expire_at' => $share['expire_at'],
                'download_count' => $share['download_count'],
                'max_downloads' => $share['max_downloads'],
                'share_type' => $share['share_type'] ?? 'download'
            ]
        ];
    }
    
    // 공유 파일 다운로드
    public function downloadShare(string $token, ?string $password = null): void {
        // ★ (2026-11-01 01:52, 36차 전체 점검) 아래 $isRangePart 와 같은 조건(이 세션에서 받기 시작 + Range 시작>0)이면 accessShare 의 횟수 초과 삭제를 건너뜀
        $resumeRange = !empty($_SESSION['share_dl_started'][$token]) && isset($_SERVER['HTTP_RANGE'])
            && preg_match('/^\s*bytes=(\d+)-/i', $_SERVER['HTTP_RANGE'], $_rrM) && (int)$_rrM[1] > 0;
        $access = $this->accessShare($token, $password, $resumeRange);
        
        if (!$access['success']) {
            if (($access['error'] ?? '') === 'password_required') {
                http_response_code(401);
                echo json_encode(['error' => 'password_required']);
            } else {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo $access['error'] ?? __('api_err_access_blocked', '접근이 차단되었습니다.');
            }
            exit;
        }
        
        $share = $this->db->find('shares', ['token' => $token]);
        
        // ★ (2026-10-28 18:22, 소스 점검 5단계) 업로드 전용(filedrop) 링크로는 내려받기 금지 — 종전엔 share_type 을 안 봐서
        //   남들이 올린 파일·폴더의 기존 내용을 token 하나로 전부 ZIP 다운로드할 수 있었음(업로드만 받으려고 뿌린 링크)
        if (($share['share_type'] ?? 'download') === 'filedrop') {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit(__('api_err_filedrop_no_download', '업로드 전용 링크는 내려받을 수 없습니다.'));
        }
        
        // Storage 클래스 사용하여 실제 경로 가져오기
        $basePath = $this->storage->getRealPath($share['storage_id']);
        
        if (!$basePath) {
            http_response_code(500);
            exit(__('api_err_storage_path_not_found', '스토리지 경로를 찾을 수 없습니다.'));
        }
        
        // 폴더 stream 공유 + sub-file: 트랙별 라우팅 (보안 검증 후 파일 경로 교체)
        $shareType = $share['share_type'] ?? 'download';
        $isFolderStream = ($access['share']['is_dir'] ?? false) && $shareType === 'stream';
        $subFile = isset($_GET['file']) ? (string)$_GET['file'] : null;
        
        // ★ HLS playlist/segment 액션은 세션 ID로 처리 (sub-file 무관, segment 파일명이 file에 들어옴)
        $hlsAction = $_GET['hls_action'] ?? '';
        // ★ (2026-11-01 11:41, 39차 점검) stop 도 세션 ID 만 쓰는 요청인데, share.php 의 stop 주소 5곳은 file= 없이 만들어져 폴더 스트리밍 공유에선
        //   아래 'file 없는 stream 호출 400' 에 막혀 ffmpeg 가 안 꺼졌음(페이지 떠나기·화질 변경·음성 SW 재시작·MMS 전환). 400 예외만 두면 폴더라 ZIP 으로
        //   빠지므로 playlist/segment 처럼 여기서 바로 hlsShareStream 으로 넘김(세션이 이 공유의 것인지는 hlsShareStream 이 meta.json 으로 확인)
        $isHlsPlaylistOrSegment = isset($_GET['hls']) && in_array($hlsAction, ['playlist', 'segment', 'stop'], true);
        
        if ($isHlsPlaylistOrSegment) {
            // HLS playlist/segment 요청: file 파라미터는 segment 파일명이거나 무시 — sub-file 검증 스킵
            // (실제 fullPath는 hlsShareStream가 세션 디렉토리에서 처리하므로 폴더 경로 그대로 ok)
            $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
            // ★ 보안: 단일 파일 공유와 동일한 경로 안전성 검증 (방어선 유지)
            if (!$this->isSharePathSafe($basePath, $fullPath)) {
                http_response_code(403);
                exit(__('api_err_invalid_path', '잘못된 경로입니다.'));
            }
            // ★ HLS 액션은 즉시 처리 (is_dir/ZIP 분기 우회 — 폴더 경로라도 hlsShareStream가 세션으로 처리)
            require_once __DIR__ . '/FileManager.php';
            $fileManager = new FileManager();
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // ★ (2026-10-02) FileManager 생성자의 new Auth() 가 세션을 다시 열어 요청 내내 잠갔다(transcode=1 대체 재생은 영상 끝까지) — 같은 시청자의 다른 요청이 기다림. 세션 내용은 안 쓰고 session_id() 는 닫아도 같음(확인)
            $fileManager->hlsShareStream($fullPath, !empty($share['password']));   // ★ (2026-11-04) 56차 비밀번호 공유면 조각을 private 캐시로(아래 일반 스트림 54차와 같은 기준)
            exit;
        } elseif ($isFolderStream && $subFile !== null && $subFile !== '') {
            // 보안 검증 (basename + 화이트리스트 + realpath)
            $validatedPath = $this->validateSharedFolderSubPath($token, $password, $subFile);
            if ($validatedPath === null) {
                http_response_code(403);
                exit(__('api_err_invalid_path', '잘못된 경로입니다.'));
            }
            $fullPath = $validatedPath;
        } else {
            // 단일 파일 공유 (기존 로직)
            $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
            
            // 경로 안전성 검증
            if (!$this->isSharePathSafe($basePath, $fullPath)) {
                http_response_code(403);
                exit(__('api_err_invalid_path', '잘못된 경로입니다.'));
            }
        }
        
        // 다운로드 횟수 증가 — ★ (2026-10-28 18:22, 소스 점검 5단계) 실제 스트리밍(공유 유형이 stream)일 때만 제외.
        //   종전엔 '&stream=1' 이 붙기만 하면 (download 타입이라 실제론 전체 전송인데도) 횟수를 안 올려, 횟수 제한을 무한 우회할 수 있었음.
        //   또 Range(이어받기) 요청마다 1씩 오르던 것도 바로잡아, 첫 조각(미지정/0부터)일 때만 1 올림.
        $isStreamCount = (($share['share_type'] ?? 'download') === 'stream') && isset($_GET['stream']);
        // ★ (2026-10-29 02:36, 16차) 이어받기 판정을 실제 시작 위치로 — 종전엔 헤더 글자만 봐서 'bytes=00-'·'x' 처럼 이상한 Range 를 붙이면
        //   (서버는 파일 전체를 보내면서) 횟수를 안 올려 제한을 무한히 피할 수 있었음(재현). 폴더(ZIP)는 Range 를 안 쓰므로 늘 셈.
        //   시작이 0 보다 큰 이어받기라도 이 방문(세션)에서 처음 받는 것이면 셈 — 앞부분 없이 'bytes=1-' 로만 반복 받는 것도 막음
        $isRangePart = false;
        if (isset($_SERVER['HTTP_RANGE']) && !is_dir($fullPath) && preg_match('/^\s*bytes=(\d+)-/i', $_SERVER['HTTP_RANGE'], $_rgM) && (int)$_rgM[1] > 0) {
            $isRangePart = !empty($_SESSION['share_dl_started'][$token]);
        }
        // ★ (2026-11-01 07:19, 37차 기능 점검) 횟수 +1 을 잠금 안에서 '한도 미만일 때만' 하고 실패하면 거부 — 종전엔 accessShare 검사와 +1 이 따로라 max_downloads=1 링크도 동시 요청 10개가 모두 받았음.
        //   아래 '받기 시작' 세션 표시보다 먼저 해, 거부된 요청이 표시를 남겨 36차 이어받기 예외(resumeRange)로 새는 일이 없게 함. 이어받기($isRangePart)·스트리밍은 종전처럼 세지 않음
        // ★ (2026-11-02 10:29, 42차 점검) HEAD 요청(링크 검사기·받기 도구의 사전 확인)은 횟수를 세지 않고 '받기 시작' 표시도 남기지 않음 — 종전엔 GET 과 같은 길이라
        //   max_downloads=1 링크가 HEAD 한 번으로 소진되고 이어진 실제 받기는 '횟수 초과' + 공유 삭제였음. HEAD 는 아래에서 헤더만 보내고 끝냄(본문·ZIP 만들기 없음)
        $isHeadReq = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD';
        $dlCounted = false;
        if (!$isStreamCount && !$isRangePart && !$isHeadReq) {
            if (!$this->shareIncrementIfBelow($token)) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                exit(__('api_err_share_download_limit', '다운로드 횟수를 초과했습니다.'));
            }
            $dlCounted = $this->lastIncApplied;   // ★ (2026-11-02 11:01, 42차 재검토) 실제로 올렸을 때만 되돌림 대상
        }
        if (!$isStreamCount && !$isRangePart && !$isHeadReq && empty($_SESSION['share_dl_started'][$token])) {
            // ★ (2026-10-29 09:23, 20차) share.php 는 내려받기 전에 세션을 닫아(session_write_close) 16차의 이 표시가 저장되지 않았음 →
            //   이어받기(시작>0)마다 횟수가 올라 한도에 닿으면 받던 도중 공유가 지워졌음. 닫혀 있으면 잠깐 다시 열어 표시만 저장
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['share_dl_started'][$token] = true;
            } elseif (session_status() === PHP_SESSION_NONE && !headers_sent() && @session_start()) {
                $_SESSION['share_dl_started'][$token] = true;
                session_write_close();
            }
        }
        // ★ stream 공유 폴더의 잘못된 stream 호출 차단 (v5.8.1c — 펜닐님 환경 share_*.zip 잔류 원인)
        //   isFolderStream + stream=1 파라미터 있는데 file 파라미터 없는 호출은
        //   "트랙 라우팅 실패 또는 잘못된 stream URL" — 폴더 통째 zip 생성 의도와 다름.
        //   ※ stream=1 없이 download=1만 있는 호출은 다운로드 버튼 클릭 → 폴더 zip 다운로드 (정상 허용).
        //     예: 빈 폴더를 stream 타입으로 공유한 사용자가 다운로드 버튼 누르는 경우.
        if ($isFolderStream && isset($_GET['stream']) && ($subFile === null || $subFile === '') && !$isHlsPlaylistOrSegment) {
            http_response_code(400);
            exit(__('api_err_invalid_request', '잘못된 요청입니다.'));
        }
        
        // 폴더인 경우 ZIP으로 압축
        if (is_dir($fullPath)) {
            if ($isHeadReq) {   // ★ (2026-11-02 10:29, 42차 점검) HEAD 는 ZIP 을 만들지 않고 형식·이름만(크기는 만들어 봐야 알 수 있어 보내지 않음)
                $zhName = basename($fullPath) . '.zip';
                header('Content-Type: application/zip');
                header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $zhName) . "\"; filename*=UTF-8''" . rawurlencode($zhName));
                header('Cache-Control: no-cache, no-store, must-revalidate');
                exit;
            }
            $zRb = $this->safeRealpath($basePath); $zRf = $this->safeRealpath($fullPath);   // ★ (2026-11-17 11:28, 319차) ZIP 숨김 거르기용 스토리지 기준 경로(못 구하면 공유 경로)
            $zRel = ($zRb !== false && $zRf !== false && strpos($zRf, $zRb) === 0) ? trim(str_replace('\\', '/', substr($zRf, strlen($zRb))), '/') : (string)($share['file_path'] ?? '');
            $this->downloadAsZip($fullPath, $dlCounted ? $token : '', (int)$share['storage_id'], $zRel);   // ★ (2026-11-11 07:27, 72차) ZIP 만들기 실패 때 위에서 센 1회를 되돌릴 수 있게(실제로 올렸을 때만 토큰을 넘김)
            return;
        }
        
        // 파일 다운로드
        $filename = basename($fullPath);
        $filesize = filesize($fullPath);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // 공유 유형에 따른 Content-Type/Disposition 결정
        $shareType = $share['share_type'] ?? 'download';
        $isStream = ($shareType === 'stream') && isset($_GET['stream']);
        
        // HLS 스트리밍 요청
        if ($isStream && isset($_GET['hls'])) {
            require_once __DIR__ . '/FileManager.php';
            $fileManager = new FileManager();
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // ★ (2026-10-02) FileManager 생성자의 new Auth() 가 세션을 다시 열어 요청 내내 잠갔다(transcode=1 대체 재생은 영상 끝까지) — 같은 시청자의 다른 요청이 기다림. 세션 내용은 안 쓰고 session_id() 는 닫아도 같음(확인)
            $fileManager->hlsShareStream($fullPath, !empty($share['password']));   // ★ (2026-11-04) 56차 비밀번호 공유면 조각을 private 캐시로(아래 일반 스트림 54차와 같은 기준)
            exit;
        }
        
        // 트랜스코딩 요청 (브라우저 미지원 포맷 — MMS/MSE fallback)
        if ($isStream && isset($_GET['transcode'])) {
            require_once __DIR__ . '/FileManager.php';
            $fileManager = new FileManager();
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // ★ (2026-10-02) FileManager 생성자의 new Auth() 가 세션을 다시 열어 요청 내내 잠갔다(transcode=1 대체 재생은 영상 끝까지) — 같은 시청자의 다른 요청이 기다림. 세션 내용은 안 쓰고 session_id() 는 닫아도 같음(확인)
            $fileManager->transcodeShareStream($fullPath);
            exit;
        }

        // ★ (2026-09-30) 일반재생 빠른 시작(원본 스트리밍) — mp4 를 다시 인코딩하지 않고 키프레임 조각으로(FileManager::directStreamFile).
        //   위의 검증(accessShare: 토큰·만료·비밀번호 / isSharePathSafe 또는 폴더 하위 파일 validateSharedFolderSubPath)을 모두 통과한 뒤,
        //   스트리밍 공유 + stream=1 일 때만(HLS·트랜스코딩과 같은 조건). 조각 주소에도 같은 file= 를 붙여 조각마다 같은 검증을 다시 거친다.
        //   스트리밍이라 다운로드 횟수는 늘지 않음(위 !stream 조건).
        if ($isStream && isset($_GET['ds'])) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            require_once __DIR__ . '/FileManager.php';
            $fileManager = new FileManager();
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // ★ (2026-10-02) FileManager 생성자의 new Auth() 가 세션을 다시 열어 ffmpeg 작업 내내 잠갔다 — 같은 시청자의 다른 요청(장면·조각)이 기다렸음
            $segBase = 'share.php?t=' . rawurlencode($token) . '&download=1&stream=1'
                     . (($subFile !== null && $subFile !== '') ? '&file=' . rawurlencode($subFile) : '') . '&ds=segment';
            $fileManager->directStreamFile($fullPath, $segBase);
            exit;
        }

        // ★ (2026-10-02) 재생바 미리보기 장면 — 빠른 시작과 같은 조건(위 공유 검증 모두 통과 + 스트리밍 공유 + stream=1). 다운로드 횟수 안 늘림.
        if ($isStream && isset($_GET['vf'])) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            require_once __DIR__ . '/FileManager.php';
            $fileManager = new FileManager();
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // ★ (2026-10-02) FileManager 생성자의 new Auth() 가 세션을 다시 열어 ffmpeg 작업 내내 잠갔다 — 같은 시청자의 다른 요청(장면·조각)이 기다렸음
            $fileManager->videoFrameFile($fullPath, (float)$_GET['vf'], !empty($_GET['a']));   // a=1: 짧은 영상 정확 탐색(화면이 정함)
            exit;
        }

        // ★ (2026-10-02) 재생바 미리보기 장면 묶음 — 한 장(vf)과 같은 조건(공유 검증 모두 통과 + 스트리밍 공유 + stream=1). 다운로드 횟수 안 늘림.
        if ($isStream && isset($_GET['vfs'])) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            require_once __DIR__ . '/FileManager.php';
            $fileManager = new FileManager();
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // ★ (2026-10-02) FileManager 생성자의 new Auth() 가 세션을 다시 열어 ffmpeg 작업 내내 잠갔다 — 같은 시청자의 다른 요청(장면·조각)이 기다렸음
            $fileManager->videoFramesFile($fullPath, (string)$_GET['vfs'], !empty($_GET['a']));
            exit;
        }

        // ★ (2026-10-09) 재생바 미리보기용 키프레임 시각 목록 — 장면(vf·vfs)과 같은 조건(공유 검증 모두 통과 + 스트리밍 공유 + stream=1). 다운로드 횟수 안 늘림.
        if ($isStream && isset($_GET['vk'])) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            require_once __DIR__ . '/FileManager.php';
            $fileManager = new FileManager();
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // FileManager 생성자의 new Auth() 가 세션을 다시 열 수 있어(vf 와 같음)
            $fileManager->videoKeyTimesFile($fullPath);
            exit;
        }
        
        // ★ (2026-11-02 10:29, 42차 점검) 헤더(206·Content-Length·첨부 이름)를 정하기 전에 열어 봄 — 종전엔 다 정한 뒤 fopen 이 실패하면(Windows 에서 다른 프로그램이 잡은 파일,
        //   목록과 받기 사이에 지워진 파일) '500 + Content-Length: <파일 크기> + 첨부 이름'이 남아 브라우저가 깨진 파일을 남겼음. 못 열면 위에서 센 1회도 되돌림
        $_fpProbe = @fopen($fullPath, 'rb');
        if ($_fpProbe === false) {
            if ($dlCounted) $this->shareIncrement($token, ['download_count' => -1]);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit(__('file_open_fail'));
        }
        fclose($_fpProbe);
        
        if ($isStream) {
            // 스트리밍: 올바른 MIME + inline
            // ★ v5.8.1c: 메인 FileManager::getMimeType()와 동기화 (펜닐님 모바일 FLAC 재생 이슈 해결)
            //   - opus는 'audio/ogg'로 통일 (Opus는 OGG 컨테이너 사용, 일부 모바일은 audio/opus 미인식)
            //   - oga/aiff/aif 추가 (모바일 호환성 향상)
            $mimeMap = [
                // 비디오
                'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'video/ogg',
                'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska',
                'wmv' => 'video/x-ms-wmv', 'flv' => 'video/x-flv', 'ts' => 'video/mp2t',
                'm2ts' => 'video/mp2t', 'mts' => 'video/mp2t', 'mpg' => 'video/mpeg',
                'mpeg' => 'video/mpeg', 'm4v' => 'video/x-m4v', '3gp' => 'video/3gpp',
                // 오디오
                'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
                'flac' => 'audio/flac',          // 모바일에서 인식되려면 application/octet-stream 대신 이게 필요
                'm4a' => 'audio/mp4',
                'aac' => 'audio/aac',
                'opus' => 'audio/ogg',           // ★ Opus는 OGG 컨테이너 — 일부 모바일은 audio/opus 인식 못함
                'wma' => 'audio/x-ms-wma',
                'oga' => 'audio/ogg',            // ★ 추가 (Vorbis/FLAC in OGG)
                'm4b' => 'audio/mp4', 'weba' => 'audio/webm',   // ★ (2026-10-08) 웹 플레이어가 여는 형식 추가
                'aiff' => 'audio/aiff', 'aif' => 'audio/aiff',  // ★ 추가
            ];
            // ★ (2026-11-02 10:29, 42차 점검) fileinfo 확장이 없으면 mime_content_type 이 정의되지 않아 치명 오류(500) — 있을 때만 부르고 없으면 octet-stream(내려받기 + sandbox 로 감)
            $mime = $mimeMap[$ext] ?? ((function_exists('mime_content_type') ? @mime_content_type($fullPath) : false) ?: 'application/octet-stream');
            $filenameSafe = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename);
            $filenameEncoded = rawurlencode($filename);
            // ★ (2026-10-29 18:00, 22차 점검) 표에 없는 형식은 안전한 것(그림·PDF·텍스트)만 화면에 띄우고 나머지는 내려받기 + sandbox — 탐색기 inline(api.php)과 같은 기준.
            //   종전엔 서버가 추측한 형식(text/html·image/svg+xml)을 그대로 inline 으로 보내, 'x.html' 스트리밍 공유 링크가 앱과 같은 주소에서 스크립트를 실행했음
            header('X-Content-Type-Options: nosniff');
            $shSafeInline = ['image/jpeg','image/png','image/gif','image/webp','image/bmp','application/pdf','text/plain'];
            if (isset($mimeMap[$ext]) || in_array($mime, $shSafeInline, true)) {
                header('Content-Type: ' . $mime);
                if ($mime === 'text/plain') header("Content-Security-Policy: default-src 'none'; sandbox;");
                header("Content-Disposition: inline; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
            } else {
                header('Content-Type: application/octet-stream');
                header("Content-Security-Policy: default-src 'none'; sandbox;");
                header("Content-Disposition: attachment; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
            }
        } else {
            header('Content-Type: application/octet-stream');
        }
        
        // php.ini 설정에 의존하지 않도록 런타임 설정
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        ignore_user_abort(false);
        
        // 출력 버퍼링 완전 비활성화
        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', 'Off');
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // RFC 5987 형식으로 파일명 인코딩
        $filenameSafe = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename);
        $filenameEncoded = rawurlencode($filename);
        
        // 0바이트 파일 처리
        if ($filesize === 0) {
            if (!$isStream) {
                header('Content-Type: application/octet-stream');
                header("Content-Disposition: attachment; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
            }
            header('Content-Length: 0');
            exit;
        }
        
        // 범위 요청 처리 (이어받기 지원)
        $start = 0;
        $end = $filesize - 1;
        $isPartial = false;
        
        // ★ (2026-11-02 10:29, 42차 점검) 해석할 수 있는 Range 만 206 — 종전엔 정규식이 안 맞아도('bytes=-500' 접미 범위, 'items=0-5' 다른 단위) 블록 밖의 206 + 'bytes 0-끝' 으로
        //   파일 전체를 보냈음. 접미 범위는 마지막 N바이트(WebDAV 22차와 같은 해석), 그 밖에 해석 못 한 Range 는 무시하고 200 전체
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^\s*bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $matches) && ($matches[1] !== '' || $matches[2] !== '')) {
            if ($matches[1] === '') {   // 접미 범위 'bytes=-N' (N=0 이면 아래 검증에서 416)
                $start = max(0, $filesize - intval($matches[2]));
            } else {
                $start = intval($matches[1]);
                if ($matches[2] !== '') {   // ★ (2026-11-01 17:16, 40차 점검) empty('0') 라 'bytes=0-0'(1바이트)에 파일 전체를 보냈음 — 39차 api.php·FileManager 와 같게 빈 값만 '끝 없음'
                    $end = intval($matches[2]);
                }
            }
            // 범위 검증
            if ($start > $end || $start >= $filesize) {
                http_response_code(416);
                header("Content-Range: bytes */{$filesize}");
                exit;
            }
            if ($end >= $filesize) {
                $end = $filesize - 1;
            }
            // ★ (2026-09-23) 음악 재생(미리보기·공유 스트리밍)은 한 번에 최대 1MB 만 보낸다 — 나머지는 재생기가 이어서 요청한다.
            //   [원인] 곡 파일을 '처음부터 끝까지'로 요청하면 5~11MB 전체를 12~23Mbps 로 한꺼번에 밀어냈는데, 휴대폰 회선이
            //   5Mbps 면 수 MB 가 망 중간에 쌓인다. 그 상태에서 곡을 넘기면 새 곡의 요청·응답이 쌓인 데이터 뒤에서 기다려
            //   곡 시작이 3~4초 늦었다(09-23 Apache 로그: 서버 처리는 0.01~0.1초인데 휴대폰의 '맛보기 2바이트 → 본 요청'
            //   간격이 3초 — 이전 곡 전송 직후 넘긴 경우에만, 오전의 빠른 회선에선 0초).
            //   [처리] 요청 범위가 1MB 보다 크면 끝을 '시작+1MB' 로 줄여 206 으로 보낸다(RFC 9110 §15.3.7 — 서버는 요청 범위의
            //   일부만 보낼 수 있고, Content-Range 로 스스로 설명되므로 재생기가 나머지를 이어서 요청한다).
            //   1MB 는 320kbps 기준 약 26초 분량이라 재생이 끊기지 않고, 넘길 때 쌓여 있는 양이 최대 1MB(5Mbps 에서 약 1.6초)로 준다.
            //   음악 재생에만 적용 — 일반 다운로드·동영상·이미지·범위 요청이 아닌 경우는 종전과 같다.
            if ($isStream && isset($mime) && strpos($mime, 'audio/') === 0) {
                $capBytes = 1048576;
                if ($end - $start + 1 > $capBytes) {
                    $end = $start + $capBytes - 1;
                }
            }
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$filesize}");
            $isPartial = true;
        }
        
        if (!$isStream) {
            header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
        }
        header('Content-Length: ' . ($end - $start + 1));
        header('Accept-Ranges: bytes');
        if ($isStream) {
            // ★ (2026-11-04 01:47, 54차 점검) 비밀번호 공유는 private(커버·가사 34차와 같음) — public 이라 중간 공유 캐시가 비밀번호 확인된 본문을 저장해 다른 사람에게 줄 수 있었음(재현). 비밀번호 없는 공유는 종전 그대로
            header('Cache-Control: ' . (!empty($share['password']) ? 'private' : 'public') . ', max-age=86400');
        } else {
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        header('X-Accel-Buffering: no');
        if ($isHeadReq) exit;   // ★ (2026-11-02 10:29, 42차 점검) HEAD 는 헤더만(위에서 횟수도 안 셈)
        
        // 전체 파일 요청이면 readfile 사용
        if (!$isPartial) {
            readfile($fullPath);
        } else {
            // 부분 요청
            $fp = fopen($fullPath, 'rb');
            if ($fp === false) {
                http_response_code(500);
                exit(__('file_open_fail'));
            }
            fseek($fp, $start);
            $remaining = $end - $start + 1;
            $chunkSize = 1048576; // 1MB
            // ★ (2026-09-30) 브라우저가 끊으면 바로 멈춘다 — 종전엔 이 갈래(속도 제한 없음 + 구간 요청)만 끊김 확인 없이 반복 뒤에 한 번 flush 해,
            //   아이폰 동영상의 구간 요청(Range: bytes=0- — 파일 전체)을 트랜스코딩 전환 등으로 끊어도 서버가 1.5GB 를 계속 읽고 보내려 할 수 있었다
            //   (펜닐 로그: 원본 다운로드가 열려 있는 동안만 다른 작은 요청의 서버 처리가 3~7초). 속도 제한 갈래와 같게 connection_aborted() 확인 +
            //   조각마다 flush(PHP 는 내보내다 실패할 때 끊김을 안다). 보내는 데이터는 같다.
            while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
                $read = min($chunkSize, $remaining);
                echo fread($fp, $read);
                $remaining -= $read;
                flush();
            }
            flush();
            fclose($fp);
        }
        exit;
    }
    
    // ZIP 압축 다운로드
    private function downloadAsZip(string $dir, string $revertToken = '', ?int $hidSid = null, ?string $hidBase = null): void {
        // ★ (2026-11-17 11:28, 319차 — 2026-10-28 펜닐 결정 "숨김 항목은 공유받은 사람에게도 안 보이게"를 폴더 ZIP 에도) $hidSid·$hidBase(스토리지 기준 폴더 경로)를 주면
        //   목록(공개 공유 페이지·내부 공유 listInternalShareFolder)과 같은 기준으로 숨김 항목을 ZIP 에 넣지 않음 — 종전엔 목록엔 안 보이는 .git/config·.secret·Thumbs.db·사용자 숨김 파일이
        //   공개·내부 공유 ZIP 에 모두 들어갔음(재현). 기준: 경로 조각이 '.' 로 시작·시스템 이름(HiddenItems::isSystemName)·사용자 숨김 경로(자기 또는 상위 폴더). 안 주면 종전 그대로
        $zHidMap = ($hidSid !== null && class_exists('HiddenItems')) ? HiddenItems::mapFor($hidSid) : null;
        $zHidBase = ($zHidMap !== null) ? HiddenItems::norm((string)$hidBase) : '';
        // ★ 잔류 정리 안전망 (v5.8.1c — 다운로드 중단 시 unlink 못 한 zip 자동 정리)
        //   readfile() 도중 사용자 abort/네트워크 끊김 시 PHP가 즉시 종료되어 @unlink 못 실행 →
        //   sys_temp_dir에 share_*.zip 잔류 (큰 폴더면 GB급). 1시간 이상 된 것만 자동 삭제.
        //   hwp_viewer.php의 fs_hwp_* 패턴과 동일.
        foreach (@glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'share_*.zip') ?: [] as $_old) {
            if (is_file($_old) && filemtime($_old) < time() - 3600) @unlink($_old);
        }
        
        $zipName = basename($dir) . '.zip';
        // ★ (2026-11-02 09:01, 41차 점검) uniqid() 는 다른 PHP 프로세스가 같은 마이크로초에 부르면 같은 값 → 두 방문자의 ZIP 이 같은 임시 경로에 써져 섞이거나 깨질 수 있었음.
        //   34차(휴지통·사본 이름)와 같게 임의 hex 로. 'share_' 접두어·'.zip' 은 그대로(위 1시간 잔류 정리 glob 과 맞춤)
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'share_' . bin2hex(random_bytes(8)) . '.zip';
        
        // php.ini 설정에 의존하지 않도록 런타임 설정
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        ignore_user_abort(false);
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            exit(__('zip_create_fail'));
        }
        
        $baseName = basename($dir);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        $fileCount = 0;
        $zTop = realpath($dir);
        foreach ($iterator as $file) {
            if ($file->isLink() && !((($zLr = realpath($file->getPathname())) !== false) && $zTop !== false && ($zLr === $zTop || isSubPath($zLr, $zTop)))) continue;   // ★ (2026-11-03 23:38, 53차 점검) 받는 폴더 밖을 가리키는 심볼릭 링크는 뺌 — 종전엔 링크된 바깥 파일 내용(예: data/users.json)이 ZIP 에 들어갔음(재현, 공유 링크 비로그인 받기 포함). 기준은 받는 폴더(같은 스토리지의 다른 폴더를 가리키는 링크도 뺌 — 공유 범위·폴더 권한을 넘지 않게)
            if ($zHidMap !== null) {   // ★ (2026-11-17 11:28, 319차) 위 숨김 기준 — 조각마다 확인(숨긴 폴더 아래도 뺌)
                $zAcc = $zHidBase; $zSkip = false;
                foreach (explode('/', str_replace('\\', '/', $iterator->getSubPathname())) as $zSeg) {
                    if ($zSeg === '') continue;
                    $zAcc = ($zAcc === '') ? $zSeg : $zAcc . '/' . $zSeg;
                    if ($zSeg[0] === '.' || HiddenItems::isSystemName($zSeg) || isset($zHidMap[$zAcc])) { $zSkip = true; break; }
                }
                if ($zSkip) continue;
            }
            $relativePath = str_replace('\\', '/', $baseName . '/' . $iterator->getSubPathname());   // ★ (2026-10-30 02:05, 26차 점검) ZIP 항목 이름은 '/' 로(규격) — Windows 에선 '\\' 가 들어가 macOS·리눅스·아이폰에서 '폴더\\파일' 이름의 파일 하나로 풀렸음
            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($file->getPathname(), $relativePath);
                $fileCount++;
            }
        }
        
        // 빈 폴더인 경우 루트 폴더만 추가
        if ($fileCount === 0) {
            $zip->addEmptyDir($baseName);
        }
        
        // ★ (2026-11-11 07:27, 72차) close() 결과·결과 파일 확인(탐색기 폴더 ZIP 55차 Z2 와 같게) — ZipArchive 는 close 때 파일을 읽어, 그 사이 넣은 파일이 지워지거나 잠기면
        //   ZIP 이 안 생기는데도 200 + 빈 본문(0바이트 '다운로드 완료')을 보내고 받기 횟수만 깎였음(재현). 실패면 임시 파일을 지우고 500, 센 1회는 42차 방식으로 되돌림
        $zClosed = $zip->close();
        clearstatcache(true, $zipPath);
        if (!$zClosed || !is_file($zipPath)) {
            @unlink($zipPath);
            if ($revertToken !== '') $this->shareIncrement($revertToken, ['download_count' => -1]);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit(__('zip_create_fail'));
        }

        // 출력 버퍼링 완전 비활성화
        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', 'Off');
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // RFC 5987 형식으로 파일명 인코딩
        $zipNameSafe = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $zipName);
        $zipNameEncoded = rawurlencode($zipName);
        
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename=\"{$zipNameSafe}\"; filename*=UTF-8''{$zipNameEncoded}");
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Accel-Buffering: no');
        
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }
    
    // 토큰 생성
    private function generateToken(): string {
        return bin2hex(random_bytes(SHARE_LINK_LENGTH / 2));
    }
    
    // 공유 URL 생성
    private function getShareUrl(string $token): string {
        // 외부 URL 설정 확인
        $settings = $this->db->load('settings');
        $externalUrl = $settings['external_url'] ?? '';
        
        if (!empty($externalUrl)) {
            // 외부 URL 설정이 있으면 사용
            $externalUrl = rtrim($externalUrl, '/');
            return "{$externalUrl}/share.php?t={$token}";
        }
        
        // 기본: 현재 접속 URL 사용
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['SCRIPT_NAME']);
        $path = str_replace('\\', '/', $path); // Windows 역슬래시 제거
        $path = rtrim($path, '/');
        return "{$protocol}://{$host}{$path}/share.php?t={$token}";
    }
    
    // 공유 링크 수정
    public function updateShare(int $id, array $options): array {
        $share = $this->db->find('shares', ['id' => $id]);
        
        if (!$share) {
            return ['success' => false, 'error' => __('api_err_share_not_found', '공유를 찾을 수 없습니다.')];
        }
        
        if (!$this->auth->isAdmin() && $share['created_by'] != $this->auth->getUserId()) {
            return ['success' => false, 'error' => __('api_err_no_permission', '권한이 없습니다.')];
        }
        
        $updateData = [];
        
        if (isset($options['expire_days'])) {
            if ($options['expire_days']) {
                $days = min(36500.0, (float)$options['expire_days']);   // ★ (2026-11-16 12:31, 233차) 만들 때(createShare)와 같은 위 한도 100년
                $seconds = max(60, (int)round($days * 86400));
                $updateData['expire_at'] = date('Y-m-d H:i:s', time() + $seconds);
            } else {
                $updateData['expire_at'] = null;
            }
        }
        
        if (isset($options['max_downloads'])) {
            $updateData['max_downloads'] = (is_numeric($options['max_downloads']) && (int)$options['max_downloads'] > 0) ? (int)$options['max_downloads'] : null;   // ★ (2026-10-31 12:03, 34차 전체 점검) 정수로
        }
        
        if (isset($options['password'])) {
            // ★ (2026-11-01 17:16, 40차 점검) 수정에서 '0' 을 넣으면 거짓으로 봐 기존 비밀번호를 지웠음 → 빈 문자열일 때만 지움
            if (!is_scalar($options['password'])) {   // 배열 등으로 기존 비밀번호를 지우지 않게 거부(종전엔 password_hash TypeError 로 실패)
                return ['success' => false, 'error' => __('api_err_invalid_request', '잘못된 요청입니다.')];
            }
            $_pwIn = (string)$options['password'];
            $updateData['password'] = $_pwIn !== ''
                ? password_hash($_pwIn, PASSWORD_DEFAULT)
                : null;
        }
        
        if (isset($options['is_active'])) {
            $updateData['is_active'] = $options['is_active'];
        }
        
        if (empty($updateData)) {
            return ['success' => false, 'error' => __('api_err_no_changes', '변경할 내용이 없습니다.')];
        }
        
        $this->db->update('shares', ['id' => $id], $updateData);
        return ['success' => true];
    }
    
    // ===== 내부 사용자 간 파일 공유 =====
    
    /**
     * 내부 공유 생성 - 개인 파일을 특정 사용자에게 공유
     */
    public function createInternalShare(int $storageId, string $filePath, int $targetUserId, array $options = []): array {
        $currentUserId = $this->auth->getUserId();
        
        // 자기 자신에게 공유 불가
        if ($currentUserId === $targetUserId) {
            return ['success' => false, 'error' => __('ishare_cannot_share_self', '자기 자신에게는 공유할 수 없습니다.')];
        }
        
        // 공유 권한 체크
        if (!$this->storage->checkPermission($storageId, 'can_share')) {
            return ['success' => false, 'error' => __('api_err_no_share_perm', '공유 권한이 없습니다.')];
        }
        
        // 경로 정규화 및 보안 검증
        $filePath = str_replace(['\\', '\/'], '/', $filePath);
        if (preg_match('#(^|[\\/])\\.\\.($|[\\/])#', $filePath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        $filePath = implode('/', array_filter(explode('/', $filePath), static fn($s) => $s !== '' && $s !== '.'));   // ★ (2026-11-01 07:19, 37차 기능 점검) 위 createShare 와 같은 경로 정리

        // 파일 존재 확인
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        
        if (!$this->isSharePathSafe($basePath, $fullPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')];
        }
        
        // 대상 사용자 존재 확인
        $targetUser = $this->db->find('users', ['id' => $targetUserId]);
        if (!$targetUser) {
            return ['success' => false, 'error' => __('ishare_user_not_found', '대상 사용자를 찾을 수 없습니다.')];
        }
        
        // 중복 공유 확인
        $existing = $this->db->findAll('internal_shares', [
            'shared_by' => $currentUserId,
            'shared_with' => $targetUserId,
            'storage_id' => $storageId,
            'file_path' => $filePath
        ]);
        if (!empty($existing)) {
            return ['success' => false, 'error' => __('ishare_already_shared', '이미 해당 사용자에게 공유된 항목입니다.')];
        }
        
        $permission = $options['permission'] ?? 'read'; // read, write, full
        if (!in_array($permission, ['read', 'write', 'full'], true)) {   // ★ (2026-10-29 22:39, 24차 점검) 엄격 비교 — 종전엔 JSON true 가 'read' 와 같다고 통과·저장돼, api.php 의 '공유자 권한까지만'(write/full) 검사를 건너뛰고 쓰기 공유가 됐음(2회차 재검토)
            $permission = 'read';
        }
        
        $id = $this->db->insert('internal_shares', [
            'shared_by' => $currentUserId,
            'shared_with' => $targetUserId,
            'storage_id' => $storageId,
            'file_path' => $filePath,
            'is_dir' => is_dir($fullPath) ? 1 : 0,
            'permission' => $permission,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        if (!$id) {   // ★ (2026-11-01 17:21, 40차 점검) 저장 실패(insert 0)도 성공으로 응답했음(외부 공유 createShare 와 같음)
            return ['success' => false, 'error' => __('err_save_failed', '저장 실패')];
        }
        
        return ['success' => true, 'id' => $id];
    }
    
    /**
     * 내 파일에 대한 내부 공유 목록 (내가 공유한 것)
     */
    public function getMyInternalShares(): array {
        $userId = $this->auth->getUserId();
        $shares = $this->db->findAll('internal_shares', ['shared_by' => $userId]);
        
        return $this->enrichInternalShares($shares);
    }
    
    /**
     * 나에게 공유된 항목 목록
     */
    public function getSharedWithMe(): array {
        $userId = $this->auth->getUserId();
        $shares = $this->db->findAll('internal_shares', ['shared_with' => $userId]);
        
        return $this->enrichInternalShares($shares);
    }
    
    /**
     * 관리자: 전체 내부 공유 목록
     */
    public function getAllInternalShares(): array {
        if (!$this->auth->isAdmin()) {
            return [];
        }
        $shares = $this->db->load('internal_shares');
        return $this->enrichInternalShares(is_array($shares) ? $shares : []);
    }
    
    /**
     * 내부 공유 정보 보강 (사용자명, 스토리지명, 파일 존재 확인)
     */
    private function enrichInternalShares(array $shares): array {
        $users = $this->db->load('users');
        $storages = $this->db->load('storages');
        $userMap = [];
        foreach ($users as $u) { $userMap[(int)$u['id']] = $u; }
        $storageMap = [];
        foreach ($storages as $s) { $storageMap[(int)$s['id']] = $s; }
        
        $result = [];
        $toDelete = [];
        foreach ($shares as $share) {
            $share['shared_by_name'] = $userMap[(int)$share['shared_by']]['username'] ?? '?';
            $share['shared_by_display'] = $userMap[(int)$share['shared_by']]['display_name'] ?? $share['shared_by_name'];
            $share['shared_with_name'] = $userMap[(int)$share['shared_with']]['username'] ?? '?';
            $share['shared_with_display'] = $userMap[(int)$share['shared_with']]['display_name'] ?? $share['shared_with_name'];
            $share['storage_name'] = $storageMap[(int)$share['storage_id']]['name'] ?? '?';
            $share['filename'] = basename($share['file_path']);
            
            // 파일 존재 확인
            $basePath = $this->storage->getRealPath($share['storage_id']);
            if ($basePath) {
                $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
                $share['file_exists'] = file_exists($fullPath);
                if ($share['file_exists'] && !$share['is_dir']) {
                    $share['file_size'] = filesize($fullPath);
                }
            } else {
                $share['file_exists'] = false;
            }
            
            // 파일이 존재하지 않으면 자동 삭제 대상
            // ★ (2026-10-29 18:42, 22차 점검) 스토리지 자체에 닿을 때만(일시 끊김이면 표시만 '없음', 기록은 남김)
            if (!$share['file_exists'] && (!$basePath || is_dir($basePath))) {
                // ★ (2026-11-11 12:24, 73차) 스토리지 표를 믿을 수 없음·SMB 접속 정보 못 풂·경로 설정 깨짐이면 지우지 않고 목록에 '없음'으로 남김(shareCleanupSafe, MIG_73 #1~#3)
                $stRowE = $basePath ? null : $this->storage->getStorageById((int)$share['storage_id']);
                $ieSafe = $basePath ? $this->shareCleanupSafe((int)$share['storage_id'], 'file')
                    : ($stRowE ? $this->shareCleanupSafe((int)$share['storage_id'], 'path', $stRowE) : $this->shareCleanupSafe((int)$share['storage_id'], 'storage'));
                if ($ieSafe) {
                    $toDelete[] = $share['id'];
                    continue;
                }
            }
            
            $result[] = $share;
        }
        
        // 존재하지 않는 파일의 공유 레코드 자동 삭제
        $this->deleteIdsOnce('internal_shares', $toDelete);   // ★ (2026-11-02 17:37, 46차 점검) 종전 id 마다 delete(표 전체 다시 쓰기) — 한 번에(결과 같음)
        
        // 최신순 정렬
        // ★ (2026-11-02 17:37, 46차 점검) 비교마다 strtotime 을 두 번 불렀음(H1#2) → 항목마다 한 번 계산해 번호 배열을 같은 비교식으로 정렬(안정 정렬이라 동률 순서도 같음)
        $ts = array_map(fn($r) => strtotime($r['created_at']), $result);
        $ix = array_keys($result);
        usort($ix, function($a, $b) use ($ts) { return $ts[$b] - $ts[$a]; });
        $result = array_map(fn($i) => $result[$i], $ix);
        
        return $result;
    }

    /**
     * 내부 공유 삭제
     */
    public function deleteInternalShare(int $id): array {
        $share = $this->db->find('internal_shares', ['id' => $id]);
        if (!$share) {
            return ['success' => false, 'error' => __('ishare_not_found', '공유를 찾을 수 없습니다.')];
        }
        
        $userId = $this->auth->getUserId();
        // 공유한 사람 또는 공유받은 사람 또는 관리자만 삭제 가능
        if ((int)$share['shared_by'] !== $userId && (int)$share['shared_with'] !== $userId && !$this->auth->isAdmin()) {
            return ['success' => false, 'error' => __('api_err_no_permission', '권한이 없습니다.')];
        }
        
        $this->db->delete('internal_shares', ['id' => $id]);
        return ['success' => true];
    }
    
    /**
     * 내부 공유 권한 변경
     */
    public function updateInternalShare(int $id, string $permission): array {
        $share = $this->db->find('internal_shares', ['id' => $id]);
        if (!$share) {
            return ['success' => false, 'error' => __('ishare_not_found', '공유를 찾을 수 없습니다.')];
        }
        
        $userId = $this->auth->getUserId();
        if ((int)$share['shared_by'] !== $userId && !$this->auth->isAdmin()) {
            return ['success' => false, 'error' => __('api_err_no_permission', '권한이 없습니다.')];
        }
        
        if (!in_array($permission, ['read', 'write', 'full'])) {
            return ['success' => false, 'error' => __('ishare_invalid_permission', '잘못된 권한입니다.')];
        }
        
        $this->db->update('internal_shares', ['id' => $id], ['permission' => $permission]);
        return ['success' => true];
    }
    
    /**
     * 공유받은 파일 다운로드 (내부 공유)
     */
    public function downloadInternalShare(int $shareId, string $subPath = ''): void {
        $userId = $this->auth->getUserId();
        $share = $this->db->find('internal_shares', ['id' => $shareId]);
        
        if (!$share || (int)$share['shared_with'] !== $userId) {
            http_response_code(403);
            exit(__('api_err_no_permission'));
        }
        if (!$this->_creatorStillAllowed($share, true, 'read')) { http_response_code(403); exit(__('share_creator_no_access', '공유한 사람에게 이 항목에 대한 권한이 없어 열 수 없습니다.')); }   // ★ (2026-11-17 13:28, 322차) 받기도 만든 사람 권한 다시 확인
        
        $basePath = $this->storage->getRealPath($share['storage_id']);
        if (!$basePath) {
            http_response_code(404);
            exit(__('api_err_storage_path_not_found'));
        }
        
        $filePath = $share['file_path'];
        if (trim((string)$subPath, '/\\') !== '') {   // ★ (2026-10-30 01:53, 26차 점검) 이름이 '0' 인 항목도(종전 !empty 는 '0' 을 빈 값으로 봐 공유 루트로 처리)
            $subPath = str_replace(['\\', '/'], '/', trim($subPath, '/'));
            if (preg_match('#(^|[\\/])\\.\\.($|[\\/])#', $subPath)) {
                http_response_code(400);
                exit(__('api_err_invalid_path'));
            }
            $filePath = rtrim($filePath, '/') . '/' . $subPath;
        }
        
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        
        if ($share['is_dir'] && (string)$subPath !== '') {
            $shareRoot = $this->safeRealpath($basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']));
            $targetReal = $this->safeRealpath($fullPath);
            // 보안: isSubPath로 정확한 하위 경로 검증 (prefix 확장 공격 방어)
            if ($shareRoot === false || $targetReal === false || !\isSubPath($targetReal, $shareRoot)) {
                http_response_code(403);
                exit(__('api_err_invalid_path'));
            }
        }
        
        if (!file_exists($fullPath)) {
            http_response_code(404);
            exit(__('api_err_file_not_found'));
        }
        
        // 폴더인 경우 ZIP
        if (is_dir($fullPath)) {
            $this->downloadAsZip($fullPath, '', (int)$share['storage_id'], (string)$filePath);   // ★ (2026-11-17 11:28, 319차) 숨김 항목 빼기(위 downloadAsZip 설명 — 내부 공유 목록과 같은 기준)
            return;
        }
        
        $filename = basename($fullPath);
        $filesize = filesize($fullPath);
        
        @set_time_limit(0);
        while (ob_get_level()) { ob_end_clean(); }
        
        $filenameSafe = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename);
        $filenameEncoded = rawurlencode($filename);
        
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
        header('Content-Length: ' . $filesize);
        header('Cache-Control: no-cache');
        
        readfile($fullPath);
        exit;
    }
    
    /**
     * 공유받은 폴더의 파일 목록 조회
     */
    public function listInternalShareFolder(int $shareId, string $subPath = ''): array {
        $userId = $this->auth->getUserId();
        $share = $this->db->find('internal_shares', ['id' => $shareId]);
        
        if (!$share || (int)$share['shared_with'] !== $userId) {
            return ['success' => false, 'error' => __('api_err_no_permission', '권한이 없습니다.')];
        }
        if (!$this->_creatorStillAllowed($share, true, 'read')) return ['success' => false, 'error' => __('share_creator_no_access', '공유한 사람에게 이 항목에 대한 권한이 없어 열 수 없습니다.')];   // ★ (2026-11-17 13:28, 322차) 목록도 만든 사람 권한 다시 확인
        
        if (!$share['is_dir']) {
            return ['success' => false, 'error' => __('ishare_not_folder', '폴더가 아닙니다.')];
        }
        
        $basePath = $this->storage->getRealPath($share['storage_id']);
        if (!$basePath) {
            return ['success' => false, 'error' => __('api_err_storage_path_not_found')];
        }
        
        $sharePath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
        
        // subPath 보안 검증
        $subPath = str_replace(['\\', '\/'], '/', trim($subPath, '/'));
        if (preg_match('#(^|[\\/])\\.\\.($|[\\/])#', $subPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path')];
        }
        
        $targetPath = ($subPath !== '') ? $sharePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subPath) : $sharePath;
        
        $realShare = $this->safeRealpath($sharePath);
        $realTarget = $this->safeRealpath($targetPath);
        // 보안: isSubPath로 정확한 하위 경로 검증 (prefix 확장 공격 방어)
        if ($realShare === false || $realTarget === false || !\isSubPath($realTarget, $realShare)) {
            return ['success' => false, 'error' => __('api_err_invalid_path')];
        }
        
        if (!is_dir($targetPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path')];
        }
        
        $items = [];
        $entries = @scandir($targetPath);
        // ★ (2026-10-29 14:20, 21차 점검) 읽기 실패는 실패로 — 종전엔 share 없이 성공으로 답해 화면이 share.permission 에서 오류로 멈췄음(폴더를 읽을 수 없다는 안내도 없었음)
        if ($entries === false) return ['success' => false, 'error' => __('err_folder_read', '폴더를 읽을 수 없습니다')];
        
        // ★ (2026-10-28, 펜닐) 숨김 항목은 공유받은 사람에게도 안 보이게 — 이름이 '.' 로 시작(탐색기 목록과 같음)·시스템 파일·~$ 임시·속성에서 숨긴 항목
        $_hidMap = class_exists('HiddenItems') ? HiddenItems::mapFor((int)$share['storage_id']) : [];
        $_hidBase = class_exists('HiddenItems') ? HiddenItems::norm((string)$share['file_path']) : '';
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (class_exists('HiddenItems')) {
                if ($entry[0] === '.' || HiddenItems::isSystemName($entry)) continue;
                $_hidRel = trim($_hidBase . '/' . ($subPath !== '' ? $subPath . '/' : '') . $entry, '/');
                if (isset($_hidMap[$_hidRel])) continue;
            }
            $fullPath = $targetPath . DIRECTORY_SEPARATOR . $entry;
            $isDir = is_dir($fullPath);
            $items[] = [
                'name' => $entry,
                'path' => $subPath !== '' ? $subPath . '/' . $entry : $entry,
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : filesize($fullPath),
                'modified' => date('Y-m-d H:i:s', filemtime($fullPath)),
                'ext' => $isDir ? '' : strtolower(pathinfo($entry, PATHINFO_EXTENSION))
            ];
        }
        
        // 폴더 우선, 이름순
        usort($items, function($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] - $a['is_dir'];
            return strnatcasecmp($a['name'], $b['name']);
        });
        
        // ★ (2026-10-29 14:20, 21차 점검) 화면 제목에 쓰는 filename 을 채움(목록 화면과 같게 basename) — 종전엔 DB 기록 그대로라 제목이 '📁 ' 만 보였음(재현)
        $share['filename'] = basename(str_replace('\\', '/', (string)($share['file_path'] ?? '')));
        // ★ (2026-11-13 00:13, 107차 개선 W2) 지울 수 있는 사람(전체 권한)에게만, 지우면 휴지통 없이 영구 삭제되는 항목에 in_vault 표시 — deleteInInternalShare 와 같은 판정
        //   (보관함 폴더 자신, 또는 이 폴더~위로 5단계 안에 .vault.json). 화면 확인 창이 '영구 삭제' 경고를 띄우는 데만 씀 — 종전엔 목록에 표시가 없어 일반 '삭제' 확인만 나왔음(재현). 삭제 동작은 그대로
        if (($share['permission'] ?? '') === 'full') {
            $ivIn = false; $ivBase = rtrim((string)$basePath, '/\\'); $ivChk = $targetPath;
            for ($i = 0; $i < 5 && $ivBase !== ''; $i++) {
                if (file_exists($ivChk . DIRECTORY_SEPARATOR . '.vault.json')) { $ivIn = true; break; }
                $ivUp = dirname($ivChk);
                if ($ivUp === $ivChk || strlen($ivUp) < strlen($ivBase)) break;
                $ivChk = $ivUp;
            }
            foreach ($items as $ivK => $ivIt) {
                if ($ivIn || ($ivIt['is_dir'] && file_exists($targetPath . DIRECTORY_SEPARATOR . $ivIt['name'] . DIRECTORY_SEPARATOR . '.vault.json'))) $items[$ivK]['in_vault'] = true;
            }
        }
        return ['success' => true, 'items' => $items, 'share' => $share];
    }
    
    /**
     * 공유 폴더 경로 검증 헬퍼
     * @return array ['success' => bool, 'share' => array, 'sharePath' => string, 'targetPath' => string, 'error' => string]
     */
    private function validateInternalSharePath(int $shareId, string $subPath, string $requiredPerm = 'read'): array {
        $userId = $this->auth->getUserId();
        $share = $this->db->find('internal_shares', ['id' => $shareId]);
        
        if (!$share || (int)$share['shared_with'] !== $userId) {
            return ['success' => false, 'error' => __('api_err_no_permission', '권한이 없습니다.')];
        }
        
        if (!$share['is_dir']) {
            return ['success' => false, 'error' => __('ishare_not_folder', '폴더가 아닙니다.')];
        }
        
        if (!$this->_creatorStillAllowed($share, true, $requiredPerm)) return ['success' => false, 'error' => __('share_creator_no_access', '공유한 사람에게 이 항목에 대한 권한이 없어 열 수 없습니다.')];   // ★ (2026-11-17 13:28, 322차) 요청한 작업(read·write·full) 기준으로 만든 사람 권한 다시 확인
        
        // 권한 체크
        $perm = $share['permission'] ?? 'read';
        // ★ (2026-11-02 09:00, 41차 점검) 24차 전에 저장될 수 있던 permission=true(문자열 아님)가 'read' 가 아니라서 쓰기(올리기·이름 바꾸기·폴더 만들기)를 통과했음.
        //   저장값은 그대로 두고 읽을 때만 — 'read'·'write'·'full' 이 아니면 'read' 로 봄(createInternalShare·updateInternalShare 검사와 같은 값 집합)
        if (!in_array($perm, ['read', 'write', 'full'], true)) $perm = 'read';
        if ($requiredPerm === 'write' && $perm === 'read') {
            return ['success' => false, 'error' => __('ishare_no_write', '쓰기 권한이 없습니다.')];
        }
        if ($requiredPerm === 'full' && $perm !== 'full') {
            return ['success' => false, 'error' => __('ishare_no_full', '전체 권한이 필요합니다.')];
        }
        
        $basePath = $this->storage->getRealPath($share['storage_id']);
        if (!$basePath) {
            return ['success' => false, 'error' => __('api_err_storage_path_not_found')];
        }
        
        $sharePath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
        
        $subPath = str_replace(['\\', '/'], '/', trim($subPath, '/'));
        if (preg_match('#(^|[\\/])\\.\\.($|[\\/])#', $subPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path')];
        }
        
        $targetPath = ($subPath !== '') ? $sharePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subPath) : $sharePath;
        
        $realShare = $this->safeRealpath($sharePath);
        if ($realShare === false) {
            return ['success' => false, 'error' => __('api_err_invalid_path')];
        }
        
        // targetPath가 존재하면 직접 확인, 없으면 부모 디렉토리 확인
        if (file_exists($targetPath)) {
            $realTarget = $this->safeRealpath($targetPath);
        } else {
            // 아직 존재하지 않는 경로 (새 폴더 생성 등) — 부모가 공유 범위 내인지 확인
            $parentDir = dirname($targetPath);
            $realTarget = $this->safeRealpath($parentDir);
        }
        
        if ($realTarget === false || !\isSubPath($realTarget, $realShare)) {
            return ['success' => false, 'error' => __('api_err_invalid_path')];
        }
        
        return ['success' => true, 'share' => $share, 'sharePath' => $sharePath, 'targetPath' => $targetPath];
    }
    
    /**
     * 공유받은 폴더에 파일 업로드 (쓰기 권한 필요)
     */
    public function uploadToInternalShare(int $shareId, string $subPath, array $file, string $duplicateAction = 'rename'): array {
        $v = $this->validateInternalSharePath($shareId, $subPath, 'write');
        if (!$v['success']) return $v;
        
        $targetDir = $v['targetPath'];
        $lockSub = trim(str_replace('\\', '/', $subPath), '/');   // ★ (2026-11-04 01:29, 54차 점검) 잠금 검사에 쓸 '실제 저장 폴더' 경로
        if (!is_dir($targetDir)) {
            $targetDir = dirname($v['targetPath']);
            if (!is_dir($targetDir)) {
                return ['success' => false, 'error' => __('ishare_target_not_folder', '대상 폴더가 존재하지 않습니다.')];
            }
            // sub_path 가 파일이면 그 파일이 든 폴더에 저장되므로 잠금도 그 폴더 기준 — 종전엔 '파일/새이름' 으로 검사해 잠긴 파일을 덮어쓸 수 있었음(재현)
            $lockSub = (strpos($lockSub, '/') !== false) ? substr($lockSub, 0, strrpos($lockSub, '/')) : '';
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => __('err_upload_failed', '업로드 실패')];
        }
        
        $filename = basename($file['name']);
        // ★ (2026-11-01 07:19, 37차 기능 점검) 저장 이름을 조각 경로·FileManager::sanitizeFilename 과 같은 정리로(위험 문자 제거 + 앞뒤 점·공백 제거) 만든 뒤 그 이름으로 검사·저장 —
        //   종전엔 슬래시만 지워 '.vault.json' 을 심어(이후 그 폴더 삭제가 영구 삭제) 보관함 메타를 덮어쓰거나 'x.docx::$DATA'·끝 점/공백 이름으로 검사와 실제 저장 이름이 달라졌음
        $filename = trim(preg_replace('/[<>:"\/\\\\|?*\x00-\x1f]/', '', $filename), '. ');
        $filename = trim(fsStripBidi($filename), '. ');   // ★ (2026-11-17 07:56, 297차 — 296차 펜닐 결정 "이름의 방향 뒤집기 문자 빼기"를 다른 올리기 길에도)
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $filename)) $filename = '_' . $filename;   // ★ (2026-11-04, 56차 C2-2) Windows 장치 이름은 '_' 를 붙여 보통 파일로(FileManager::sanitizeFilename 40차와 같은 식·조건)
        if ($filename === '' || $filename === '.' || $filename === '..') {   // ★ (2026-11-01 17:20, 40차 점검) empty('0') 라 이름이 정확히 '0' 인 항목을 '잘못된 이름'으로 거부했음 → 빈 문자열만
            return ['success' => false, 'error' => __('api_err_invalid_filename', '잘못된 파일명입니다.')];
        }
        // ★ (2026-10-28 17:42, 소스 점검 3단계) 서버 실행 확장자 차단(저장될 이름 기준 — config.php fsServerExecReason) — 종전엔 내부 공유 일반 업로드에 확장자 검사가 아예 없었음
        if (($execReason = \fsServerExecReason($filename)) !== null) {
            return ['success' => false, 'error' => $execReason];
        }
        
        $targetFile = $targetDir . DIRECTORY_SEPARATOR . $filename;
        
        // 경로가 공유 폴더 범위 내인지 최종 확인
        $realShare = $this->safeRealpath($v['sharePath']);
        $realTargetDir = $this->safeRealpath($targetDir);
        // 보안: isSubPath로 정확한 하위 경로 검증 (prefix 확장 공격 방어)
        if ($realShare === false || $realTargetDir === false || !\isSubPath($realTargetDir, $realShare)) {
            return ['success' => false, 'error' => __('api_err_invalid_path')];
        }
        
        // 중복 파일 처리
        if (file_exists($targetFile)) {
            switch ($duplicateAction) {
                case 'skip':
                    return ['success' => true, 'filename' => $filename, 'skipped' => true];
                case 'overwrite':
                    // 기존 파일 덮어쓰기
                    // ★ (2026-10-29 18:45, 22차 점검) 잠긴 파일은 덮어쓰지 않음
                    if ($this->_ishareLocked((int)$v['share']['storage_id'], $this->_ishareRelPath($v, ($lockSub !== '' ? $lockSub . '/' : '') . $filename), false)
                        || $this->_ishareLocked((int)$v['share']['storage_id'], $this->_ishareRelPath($v, $lockSub), false)) {
                        return ['success' => false, 'error' => __('exif_strip_locked', '잠긴 파일입니다.')];
                    }
                    // ★ (2026-11-04) 55차: 조각 경로(FileManager::uploadChunk 덮어쓰기)와 같은 순서로 대량 덮어쓰기 감지 → 이전 버전 저장 — 종전엔 둘 다 없어 버전이 안 남고 차단 중에도 덮어써졌음(재현)
                    if (!is_dir($targetFile)) {
                        require_once __DIR__ . '/FileManager.php';
                        $isOwFm = new FileManager();
                        $isOwBulk = $isOwFm->checkBulkOperationPublic('overwrite');
                        if (empty($isOwBulk['allowed'])) return ['success' => false, 'error' => '🔐 ' . ($isOwBulk['reason'] ?? '')];
                        $isOwFm->saveFileVersionPublic($targetFile, $this->_ishareRelPath($v, ($lockSub !== '' ? $lockSub . '/' : '') . $filename), (int)$v['share']['storage_id']);
                    }
                    break;
                case 'rename':
                default:
                    $ext = pathinfo($filename, PATHINFO_EXTENSION);
                    $name = pathinfo($filename, PATHINFO_FILENAME);
                    $i = 1;
                    do {
                        $newName = $name . " ({$i})" . ($ext !== '' ? ".{$ext}" : '');   // ★ (2026-11-01 17:20, 40차 점검) 확장자 '0'(log.0)이 거짓이라 'log (1)' 로 확장자가 떨어졌음
                        $targetFile = $targetDir . DIRECTORY_SEPARATOR . $newName;
                        $i++;
                    } while (file_exists($targetFile));
                    $filename = $newName;
                    break;
            }
        }
        
        // ★ (2026-10-29 18:45, 22차 점검) 랜섬웨어 의심 확장자·용량 한도 확인 + 사용량 반영(위 파일받기와 같음)
        $isOld = is_file($targetFile) ? (int)@filesize($targetFile) : 0;
        if (($lgErr = $this->_legacyUploadGuard((int)$v['share']['storage_id'], $filename, max(0, (int)($file['size'] ?? 0) - $isOld), (string)($file['tmp_name'] ?? ''))) !== null) {
            return ['success' => false, 'error' => $lgErr];
        }
        
        if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
            return ['success' => false, 'error' => __('err_upload_failed', '업로드 실패')];
        }
        $isDelta = (int)@filesize($targetFile) - $isOld;
        if ($isDelta !== 0) $this->storage->updateUsedSize((int)$v['share']['storage_id'], $isDelta);
        // ★ (2026-11-01 01:10, 35차 전체 점검) 공유 주인 알림·검색 인덱스를 조각 업로드(uploadToInternalShareChunk·uploadChunk)와 같게 — 종전엔 이 옛 경로로 올리면 둘 다 빠졌음
        // ★ (2026-11-01 01:53, 36차 전체 점검) Windows 는 이름 끝 점·공백을 떼고 저장하므로 알림·인덱스 이름도 실제와 같게(폴더 만들기 24차 방식) — 종전엔 'a.txt.' 로 유령 인덱스 행이 생겼음
        $isUpName = (DIRECTORY_SEPARATOR === '\\' && rtrim($filename, '. ') !== '') ? rtrim($filename, '. ') : $filename;
        $this->db->atomicUpdate('internal_shares', function ($rows) use ($shareId, $isUpName) {
            $rows = is_array($rows) ? $rows : [];
            foreach ($rows as &$row) {
                if ((int)($row['id'] ?? 0) !== (int)$shareId) continue;
                $row['pending_uploads'] = (int)($row['pending_uploads'] ?? 0) + 1;
                $row['last_upload_at'] = date('Y-m-d H:i:s');
                $row['last_upload_name'] = $isUpName;
            }
            unset($row);
            return $rows;
        });
        try {
            require_once __DIR__ . '/FileIndex.php';
            // ★ (2026-11-01 01:30, 35차 전체 점검) 실제 저장 폴더 기준(공유 대상이 폴더가 아니면 위에서 그 상위 폴더에 저장함)
            $isIxDir = $this->_ishareRelPath($v, $subPath);
            if (!is_dir($v['targetPath'])) { $isIxDir = dirname($isIxDir); if ($isIxDir === '.' || $isIxDir === '\\' || $isIxDir === '/') $isIxDir = ''; }
            \FileIndex::getInstance()->addFile((int)$v['share']['storage_id'], trim($isIxDir . '/' . $isUpName, '/'), ['is_dir' => 0, 'size' => (int)@filesize($targetFile), 'modified' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {}
        // ★ (2026-11-04) 56차 활동 기록(위 색인과 같은 실제 저장 경로)
        $isLogDir = $this->_ishareRelPath($v, $subPath);
        if (!is_dir($v['targetPath'])) { $isLogDir = dirname(str_replace('\\', '/', $isLogDir)); if ($isLogDir === '.' || $isLogDir === '/') $isLogDir = ''; }
        $this->_logShareUpload((int)$v['share']['storage_id'], $isLogDir . '/' . $isUpName, (int)@filesize($targetFile), 'internal share #' . (int)$shareId);

        return ['success' => true, 'filename' => $filename];
    }
    
    /** ★ (2026-11-04) 56차 내부 공유·파일받기 업로드도 '업로드' 활동 기록(실제 저장 경로·이름) — 종전엔 기록이 없었음. 기록 실패는 업로드 결과에 영향 없음 */
    private function _logShareUpload(int $storageId, string $relPath, int $size, string $details, array $who = []): void {
        try {
            if (!class_exists('ActivityLog')) return;
            $relPath = trim(str_replace('\\', '/', $relPath), '/');
            $st = $this->storage->getStorageById($storageId) ?: [];
            (new ActivityLog($this->db, $this->auth))->log(ActivityLog::TYPE_UPLOAD, array_merge([
                'storage_id' => $storageId,
                'storage_name' => $st['name'] ?? '',
                'path' => $relPath,
                'filename' => basename($relPath),
                'size' => $size,
                'details' => $details
            ], $who));
        } catch (\Throwable $e) {}
    }
    /** ★ (2026-11-04) 56차 파일받기 기록의 사용자 — 세션 사용자(있으면) 아니면 guest. getUser() 는 부르지 않음(ActivityLog 24차 주석: 공유 비밀번호 인증 세션을 지울 수 있음) */
    private function _filedropLogWho(): array {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $u = $uid > 0 ? ($this->db->find('users', ['id' => $uid]) ?: null) : null;
        return [
            'user_id' => $u ? $uid : 0,
            'username' => $u ? (string)($u['username'] ?? 'guest') : 'guest',
            'display_name' => $u ? (string)($u['display_name'] ?? ($u['username'] ?? 'Guest')) : 'Guest',
        ];
    }
    private function _filedropLogDetails(array $share): string {
        return 'filedrop share #' . (int)($share['id'] ?? 0) . ' (owner user #' . (int)($share['created_by'] ?? 0) . ')';
    }

    /** ★ (2026-10-29 18:45, 22차 점검) 옛(비조각) 업로드 공통 검사 — 문제 있으면 사유, 없으면 null */
    private function _legacyUploadGuard(int $storageId, string $filename, int $size, string $tmpPath = ''): ?string {
        try {
            require_once __DIR__ . '/FileManager.php';
            $fm = new FileManager();
            // ★ (2026-10-30 01:55, 26차 점검) 업로드 설정(허용·차단 확장자, 최대 크기)·랜섬웨어 확장자, 받은 파일의 MIME·내용 검사까지 — 조각 업로드·WebDAV 와 같은 함수.
            //   종전엔 랜섬웨어 확장자·용량만 봐서 filedrop_upload(비로그인)·internal_share_upload 로 직접 보내면 관리자가 막은 형식·크기도 저장됐음
            $rc = $fm->checkRansomwarePublic($filename);   // 랜섬웨어 확장자를 먼저(종전과 같은 '🔐' 안내)
            if (empty($rc['allowed'])) return '🔐 ' . ($rc['reason'] ?? '');
            if (($pre = $fm->davUploadPrecheck($filename, $size)) !== null) return '📤 ' . $pre;
            if ($tmpPath !== '' && is_file($tmpPath) && ($post = $fm->davUploadPostcheck($tmpPath, $filename)) !== null) return '🔐 ' . $post;
            // ★ (2026-11-01 07:29, 37차 기능 점검) 백신 검사도(조각 경로·일반 업로드와 같게) — 비로그인 filedrop_upload 를 직접 부르면 백신을 건너뛰었음
            if ($tmpPath !== '' && is_file($tmpPath) && method_exists($fm, 'scanForVirusPublic')) {
                $vs = $fm->scanForVirusPublic($tmpPath, $filename, (int)@filesize($tmpPath));
                if (empty($vs['clean'])) return !empty($vs['scan_error_blocked']) ? \FileManager::avBlockMessage($vs) : __('api_av_virus_detected', '🛡️ 바이러스 감지: ') . ($vs['threat'] ?? __('api_av_unknown_threat', '알 수 없는 위협'));   // ★ (2026-11-04) 56차 검사 오류 차단은 감염과 다른 문구(판정 그대로)
            }
            $q = $fm->checkQuotaPublic($storageId, $size);
            if (empty($q['allowed'])) return (string)($q['error'] ?? __('quota_exceeded', '용량 한도를 초과했습니다.'));
        } catch (\Throwable $e) {
            return __('err_upload_failed', '업로드 실패');
        }
        return null;
    }
    
    /**
     * 공유받은 폴더에 청크 업로드 (대용량 파일 지원)
     * - validateInternalSharePath로 공유 권한 검증(write) 후,
     *   검증된 FileManager::uploadChunk 코어(청크 조립 + 경쟁조건 락) 재사용.
     * - 권한은 공유 체계로 이미 검증했으므로 uploadChunk의 스토리지 can_write 체크는 skip
     *   (확장자/랜섬웨어/quota/경로 검증은 uploadChunk 내부에서 그대로 수행).
     */
    public function uploadToInternalShareChunk(int $shareId, string $subPath, array $data): array {
        $v = $this->validateInternalSharePath($shareId, $subPath, 'write');
        if (!$v['success']) return $v;
        
        $share = $v['share'];
        $storageId = (int)$share['storage_id'];
        
        // 스토리지 루트 기준 상대경로 계산 (file_path + subPath)
        // uploadChunk는 (storageId, relativePath)로 대상 폴더를 정함.
        $filePath = str_replace(['\\', '/'], '/', trim($share['file_path'] ?? '', '/'));
        $sub = str_replace(['\\', '/'], '/', trim($subPath, '/'));
        $relativePath = $filePath;
        if ($sub !== '') {
            $relativePath = ($filePath !== '') ? ($filePath . '/' . $sub) : $sub;
        }
        
        require_once __DIR__ . '/FileManager.php';
        $fileManager = new FileManager();
        // skipPermCheck=true: 공유 권한을 이미 검증했으므로 스토리지 권한 체크만 건너뜀
        $result = $fileManager->uploadChunk($storageId, $relativePath, $data, true);
        
        // 조립 완료 시 공유 소유자 알림 정보 기록 (중복 조립 deduped 제외)
        if (!empty($result['success']) && !empty($result['complete']) && empty($result['deduped']) && empty($result['skipped'])) {   // ★ (2026-11-04) 55차: '건너뛰기'(저장 안 함)는 알림 안 올림(옛 경로와 같게)
            // ★ (2026-10-29 22:08, 24차 점검) 잠금 안에서 최신 값에 +1(파일받기 20차 shareIncrement 와 같게) — 종전엔 처음 읽은 값에 +1 해 동시에 올리면 알림 수가 빠졌음
            $isUpName = $result['filename'] ?? ($data['filename'] ?? '');
            $this->db->atomicUpdate('internal_shares', function ($rows) use ($shareId, $isUpName) {
                $rows = is_array($rows) ? $rows : [];
                foreach ($rows as &$row) {
                    if ((int)($row['id'] ?? 0) !== (int)$shareId) continue;
                    $row['pending_uploads'] = (int)($row['pending_uploads'] ?? 0) + 1;
                    $row['last_upload_at'] = date('Y-m-d H:i:s');
                    $row['last_upload_name'] = $isUpName;
                }
                unset($row);
                return $rows;
            });
            // ★ (2026-11-04) 56차 활동 기록(uploadChunk 가 알려 준 실제 저장 경로)
            $this->_logShareUpload($storageId, (string)($result['saved_path'] ?? trim($relativePath . '/' . $isUpName, '/')), (int)($result['size'] ?? 0), 'internal share #' . (int)$shareId);
        }
        return $result;
    }

    /**
     * 공유 업로드 알림 목록: 내 공유(shares=filedrop/download + internal_shares) 중
     * pending_uploads > 0 인 것만 반환. 클라이언트 폴링용(가벼움).
     */
    public function getShareUploadNotifications(int $userId): array {
        $list = [];
        // 스토리지 id→name 맵 (알림에 스토리지명 표시용)
        $storageNames = [];
        foreach (($this->db->load('storages') ?: []) as $st) {
            $storageNames[(int)$st['id']] = $st['name'] ?? '';
        }
        // 일반 공유(filedrop 등)
        foreach (($this->db->load('shares') ?: []) as $s) {
            if ((int)($s['created_by'] ?? 0) !== (int)$userId) continue;
            if (($s['pending_uploads'] ?? 0) <= 0) continue;
            $list[] = [
                'id' => $s['id'],
                'is_internal' => false,
                'share_type' => $s['share_type'] ?? 'download',
                'storage_id' => $s['storage_id'] ?? null,
                'storage_name' => $storageNames[(int)($s['storage_id'] ?? 0)] ?? '',
                'file_path' => $s['file_path'] ?? '',
                'pending_uploads' => (int)$s['pending_uploads'],
                'last_upload_name' => $s['last_upload_name'] ?? '',
                'last_upload_at' => $s['last_upload_at'] ?? ''
            ];
        }
        // 내부 공유 (소유자 필드 = shared_by)
        foreach (($this->db->load('internal_shares') ?: []) as $s) {
            if ((int)($s['shared_by'] ?? 0) !== (int)$userId) continue;
            if (($s['pending_uploads'] ?? 0) <= 0) continue;
            $list[] = [
                'id' => $s['id'],
                'is_internal' => true,
                'share_type' => 'internal',
                'storage_id' => $s['storage_id'] ?? null,
                'storage_name' => $storageNames[(int)($s['storage_id'] ?? 0)] ?? '',
                'file_path' => $s['file_path'] ?? '',
                'pending_uploads' => (int)$s['pending_uploads'],
                'last_upload_name' => $s['last_upload_name'] ?? '',
                'last_upload_at' => $s['last_upload_at'] ?? ''
            ];
        }
        return ['success' => true, 'notifications' => $list];
    }
    
    /**
     * 공유 업로드 알림 확인 처리 (소유자가 해당 폴더를 열면 미확인 업로드 수 초기화)
     * - shareId 직접 지정 OR storageId+folderPath 매칭(일반 탐색기로 공유 폴더를 연 경우).
     * - 소유자(created_by) 본인만 초기화 가능.
     */
    public function clearShareUploadNotify(int $userId, ?int $shareId = null, ?int $storageId = null, ?string $folderPath = null, bool $isInternal = false, bool $clearAll = false): array {
        $cleared = 0;
        if ($clearAll) {
            // X(닫기) = 확인 처리: 내 모든 공유의 미확인 업로드 초기화
            $tables = ['shares' => 'created_by', 'internal_shares' => 'shared_by'];
            foreach ($tables as $table => $ownerField) {
                foreach (($this->db->load($table) ?: []) as $s) {
                    if ((int)($s[$ownerField] ?? 0) !== (int)$userId) continue;
                    if (($s['pending_uploads'] ?? 0) <= 0) continue;
                    $this->db->update($table, ['id' => $s['id']], ['pending_uploads' => 0]);
                    $cleared++;
                }
            }
        } elseif ($shareId) {
            // 내부 공유는 internal_shares(소유자=shared_by), 그 외(filedrop)는 shares(소유자=created_by)
            $table = $isInternal ? 'internal_shares' : 'shares';
            $ownerField = $isInternal ? 'shared_by' : 'created_by';
            $share = $this->db->find($table, ['id' => $shareId]);
            if ($share && (int)($share[$ownerField] ?? 0) === (int)$userId && ($share['pending_uploads'] ?? 0) > 0) {
                $this->db->update($table, ['id' => $shareId], ['pending_uploads' => 0]);
                $cleared++;
            }
        } elseif ($storageId !== null && $folderPath !== null) {
            // 일반 탐색기로 공유 대상 폴더를 연 경우: 양쪽 테이블에서 storage_id + file_path 일치하는 내 공유 초기화
            $fp = str_replace(['\\', '/'], '/', trim($folderPath, '/'));
            $tables = ['shares' => 'created_by', 'internal_shares' => 'shared_by'];
            foreach ($tables as $table => $ownerField) {
                $allShares = $this->db->load($table) ?: [];
                foreach ($allShares as $s) {
                    if ((int)($s[$ownerField] ?? 0) !== (int)$userId) continue;
                    if ((int)($s['storage_id'] ?? -1) !== (int)$storageId) continue;
                    if (($s['pending_uploads'] ?? 0) <= 0) continue;
                    $sfp = str_replace(['\\', '/'], '/', trim($s['file_path'] ?? '', '/'));
                    if ($sfp === $fp) {
                        $this->db->update($table, ['id' => $s['id']], ['pending_uploads' => 0]);
                        $cleared++;
                    }
                }
            }
        }
        return ['success' => true, 'cleared' => $cleared];
    }
    
    /**
     * 공유받은 폴더 내 파일/폴더 삭제 (전체 권한 필요)
     */
    /**
     * ★ (2026-10-29 03:03, 16차) 공유 루트 자체를 가리키는지 — '/'·'./'·'.' 처럼 empty() 를 통과하지만 정리하면 비는 경로, 또는 실제 경로가 공유 루트와 같으면 true.
     *   종전엔 empty($subPath) 만 봐서 sub_path='/' 로 공유한 폴더 자체를 휴지통 없이 지우거나 이름을 바꿀 수 있었음
     */
    private function isInternalShareRoot(array $v, string $subPath): bool {
        $norm = trim(str_replace('\\', '/', $subPath), '/ ');
        $norm = preg_replace('#(^|/)\.(?=/|$)#', '', $norm);
        if (trim((string)$norm, '/ ') === '') return true;
        $rt = $this->safeRealpath($v['targetPath']);
        $rs = $this->safeRealpath($v['sharePath']);
        if ($rt === false || $rs === false) return false;
        $a = rtrim(str_replace('\\', '/', $rt), '/'); $b = rtrim(str_replace('\\', '/', $rs), '/');
        return (PHP_OS_FAMILY === 'Windows') ? (strcasecmp($a, $b) === 0) : ($a === $b);
    }
    
    public function deleteInInternalShare(int $shareId, string $subPath): array {
        if (trim((string)$subPath, '/\\') === '') {   // ★ (2026-10-30 01:53, 26차 점검) '0' 은 루트가 아님
            return ['success' => false, 'error' => __('ishare_cannot_delete_root', '공유 루트 폴더는 삭제할 수 없습니다.')];
        }
        
        $v = $this->validateInternalSharePath($shareId, $subPath, 'full');
        if (!$v['success']) return $v;
        if ($this->isInternalShareRoot($v, $subPath)) {   // ★ (2026-10-29 03:03, 16차) 공유 루트 자체는 안 됨(sub_path='/' 등)
            return ['success' => false, 'error' => __('ishare_cannot_delete_root', '공유 루트 폴더는 삭제할 수 없습니다.')];
        }
        
        $target = $v['targetPath'];
        if (!file_exists($target)) {
            return ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')];
        }
        // ★ (2026-10-29 18:42, 22차 점검) 잠긴 파일(폴더면 그 안의 잠긴 파일)은 지우지 않고, 지운 만큼 사용량에서 뺌 — 종전엔 주인이 잠근 파일도 받은 사람이 바로 지울 수 있었고 사용량은 그대로 남았음
        $isRel = $this->_ishareRelPath($v, $subPath);
        if ((new FileManager())->isStorageProtectFilePublic((int)$v['share']['storage_id'], $isRel)) return ['success' => false, 'error' => __('storage_protect_file_locked', '스토리지 보호 파일(.htaccess)은 지우거나 바꿀 수 없습니다.')];   // ★ (2026-11-17 10:49, 313차 — 296차 펜닐 결정 "보호 파일 지우기 막기"를 내부 공유에도) 맨 위를 '전체'로 공유받은 사람이 스토리지 맨 위 .htaccess 를 지우거나 이름을 바꿔 보호를 풀 수 있었음(재현: 이름 바꾸기 성공)
        if ($this->_ishareLocked((int)$v['share']['storage_id'], $isRel, is_dir($target))) {
            return ['success' => false, 'error' => __('exif_strip_locked', '잠긴 파일입니다.')];
        }
        // ★ (2026-10-30 01:55, 26차 점검) 대량 삭제 감지(랜섬웨어 방지 bulk_protection) — 일반 삭제·WebDAV 와 같은 기록·한도. 종전엔 받은 사람의 삭제만 빠져 한도 없이 연달아 지울 수 있었음
        try { $isBulk = (new FileManager())->checkBulkOperationPublic('delete'); if (empty($isBulk['allowed'])) return ['success' => false, 'error' => '🔐 ' . ($isBulk['reason'] ?? '')]; } catch (\Throwable $e) {}
        $isWasDir = is_dir($target);
        $isSize = $isWasDir ? $this->_ishareDirSize($target) : (int)@filesize($target);
        
        // ★ (2026-10-29 21:23, 24차 점검) 휴지통으로(일반 삭제와 같게) — 종전엔 받은 사람이 지우면 휴지통 없이 바로 영구 삭제돼 주인·관리자도 복원할 수 없었음.
        //   휴지통 기록의 '지운 사람'은 공유한 사람(주인)으로 → 주인이 자기 휴지통에서 복원. 휴지통으로 못 옮기면 지우지 않고 실패(WebDAV 22차와 같은 기준)
        // ★ (2026-10-29 22:39, 24차 점검) 보관함(.vault.json) 폴더 또는 그 안 항목은 종전처럼 바로 영구 삭제 — 일반 delete() 와 같은 기준(휴지통 복원 시 암호화 구조 깨짐 방지, 2회차 재검토)
        $isVault = is_dir($target) && file_exists($target . DIRECTORY_SEPARATOR . '.vault.json');
        if (!$isVault) {
            $isBase = rtrim((string)$this->storage->getRealPath((int)$v['share']['storage_id']), '/\\');
            $chk = dirname($target);
            for ($i = 0; $i < 5 && $isBase !== ''; $i++) {
                if (file_exists($chk . DIRECTORY_SEPARATOR . '.vault.json')) { $isVault = true; break; }
                $up = dirname($chk);
                if ($up === $chk || strlen($up) < strlen($isBase)) break;
                $chk = $up;
            }
        }
        if ($isVault) {
            if (is_dir($target)) $this->deleteDirectory($target); else @unlink($target);
            $isTrash = ['success' => !file_exists($target)];
        } else {
            $isOwner = $this->db->find('users', ['id' => (int)($v['share']['shared_by'] ?? 0)]) ?: ['id' => (int)($v['share']['shared_by'] ?? 0)];
            $isTrash = (new FileManager())->moveToTrashAs((int)$v['share']['storage_id'], $isRel, $target, $isOwner);
        }
        clearstatcache();
        if (empty($isTrash['success']) && empty($isTrash['partial']) && file_exists($target)) {
            return ['success' => false, 'error' => $isTrash['error'] ?? __('err_delete_failed', '삭제에 실패했습니다.')];
        }
        if (file_exists($target)) {
            // 일부만 지워진 경우 실제로 지운 만큼만 반영
            $isLeft = is_dir($target) ? $this->_ishareDirSize($target) : (int)@filesize($target);
            if ($isSize - $isLeft > 0) $this->storage->updateUsedSize((int)$v['share']['storage_id'], -($isSize - $isLeft));
            return ['success' => false, 'error' => __('err_delete_failed', '삭제에 실패했습니다.')];
        }
        if ($isSize > 0) $this->storage->updateUsedSize((int)$v['share']['storage_id'], -$isSize);
        // ★ (2026-10-29 22:39, 24차 점검) 일반 삭제(FileManager::delete)와 같은 뒷정리 — 인덱스·그 경로의 공유 링크·숨김 표시(2회차 재검토: 지운 항목이 검색에 남았음)
        try {
            $isSid = (int)$v['share']['storage_id'];
            $isFi = \FileIndex::getInstance();
            if ($isWasDir) $isFi->removeFolder($isSid, $isRel); else $isFi->removeFile($isSid, $isRel);
            $isFm = new FileManager();
            $isFm->cleanupSharesForPathPublic($isSid, $isRel, $isWasDir);
            if (class_exists('HiddenItems')) \HiddenItems::removePath($isSid, $isRel);
        } catch (\Throwable $e) {}
        // ★ (2026-11-04) 55차: 보관함 폴더는 휴지통 없이 영구 삭제되므로 그 경로·하위 폴더 규칙도 정리(api.php 'delete' 26차와 같은 비교) — 종전엔 남아 같은 이름 새 폴더에 걸렸음(재현)
        if ($isVault && $isWasDir) {
            try {
                $isSid = (int)$v['share']['storage_id'];
                $isClean = trim(str_replace('\\', '/', $isRel), '/');
                foreach ($this->storage->getAllFolderPermissions($isSid) as $fp) {
                    $fpPath = trim($fp['folder_path'] ?? '', '/');
                    if ($fpPath === $isClean || strpos($fpPath . '/', $isClean . '/') === 0 || strpos($fpPath, $isClean . '/') === 0) {
                        $this->storage->removeFolderPermission($isSid, $fp['folder_path'], (int)($fp['user_id'] ?? 0));
                    }
                }
            } catch (\Throwable $e) {}
        }
        
        return ['success' => true];
    }
    
    /** ★ (2026-10-29 18:42, 22차 점검) 내부 공유 안 경로 → 스토리지 기준 상대 경로 */
    private function _ishareRelPath(array $v, string $subPath): string {
        $base = trim(str_replace('\\', '/', (string)($v['share']['file_path'] ?? '')), '/');
        $sub = trim(str_replace('\\', '/', $subPath), '/');
        return trim($base . '/' . $sub, '/');
    }
    
    /** ★ (2026-10-29 18:42, 22차 점검) 그 경로(폴더면 그 아래 포함)에 잠긴 파일이 있는지 */
    private function _ishareLocked(int $storageId, string $rel, bool $isDir): bool {
        // ★ (2026-10-30 02:21, 26차 재검토) 경로 정리 후 비교(WebDAV·FileManager 잠금 검사와 같은 기준)
        $_lkN = function ($p) { $p = str_replace('\\', '/', (string)$p); $p = preg_replace('#(^|/)\.(?=/|$)#', '$1', $p); $p = trim(preg_replace('#/{2,}#', '/', $p), '/'); return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($p) : $p; };
        // ★ (2026-11-01 17:17, 40차 점검) Windows 는 조각 끝 점·공백을 떼고 같은 파일로 열어 'docs./a.txt'·'docs/a.txt..' 가 잠금 비교만 지나갔음 →
        //   Windows 에서만 조각마다 끝 ' .' 를 떼고 비교(폴더 권한 Storage::normFolderPath 와 같은 기준). Linux 는 종전 그대로
        if (DIRECTORY_SEPARATOR === '\\') {
            $_lkN0 = $_lkN;
            $_lkN = function ($p) use ($_lkN0) { return implode('/', array_filter(array_map(static fn($s) => rtrim($s, ' .'), explode('/', $_lkN0($p))), static fn($s) => $s !== '')); };
        }
        $rel = $_lkN($rel);
        foreach (($this->db->load('locked_files') ?: []) as $lk) {
            if ((int)($lk['storage_id'] ?? 0) !== $storageId) continue;
            $lp = $_lkN($lk['path'] ?? '');
            if ($lp === $rel || ($isDir && strpos($lp, $rel . '/') === 0)) return true;
        }
        return false;
    }
    
    private function _ishareDirSize(string $dir): int {
        $size = 0;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) { if ($f->isFile()) $size += $f->getSize(); }
        } catch (\Throwable $e) {}
        return $size;
    }
    
    /**
     * 공유받은 폴더 내 파일/폴더 이름변경 (전체 권한 필요)
     */
    public function renameInInternalShare(int $shareId, string $subPath, string $newName): array {
        if (trim((string)$subPath, '/\\') === '') {   // ★ (2026-10-30 01:53, 26차 점검) '0' 은 루트가 아님
            return ['success' => false, 'error' => __('ishare_cannot_rename_root', '공유 루트 폴더는 이름을 변경할 수 없습니다.')];
        }
        
        $v = $this->validateInternalSharePath($shareId, $subPath, 'full');
        if (!$v['success']) return $v;
        if ($this->isInternalShareRoot($v, $subPath)) {   // ★ (2026-10-29 03:03, 16차) 공유 루트 자체는 안 됨(sub_path='/' 등)
            return ['success' => false, 'error' => __('ishare_cannot_rename_root', '공유 루트 폴더는 이름을 변경할 수 없습니다.')];
        }
        
        $target = $v['targetPath'];
        if (!file_exists($target)) {
            return ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')];
        }
        if ((new FileManager())->isStorageProtectFilePublic((int)$v['share']['storage_id'], $this->_ishareRelPath($v, $subPath))) return ['success' => false, 'error' => __('storage_protect_file_locked', '스토리지 보호 파일(.htaccess)은 지우거나 바꿀 수 없습니다.')];   // ★ (2026-11-17 10:49, 313차) 위 지우기와 같음
        
        $newName = basename($newName);
        // ★ (2026-11-03 08:04, 48차 점검) 업로드(37차)와 같은 정리(위험 문자 + 앞뒤 점·공백) — 종전엔 슬래시만 지워, 쓰기 공유만 받은 사람이 이름을 '.vault.json' 으로 바꿔 주인 폴더를 보관함처럼 보이게(목록 멈춤·삭제가 휴지통 없이 영구) 할 수 있었음(재현)
        $newName = trim(preg_replace('/[<>:"\/\\\\|?*\x00-\x1f]/', '', $newName), '. ');
        $newName = trim(fsStripBidi($newName), '. ');   // ★ (2026-11-17 07:56, 297차 — 296차 펜닐 결정 "이름의 방향 뒤집기 문자 빼기"를 다른 올리기 길에도)
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $newName)) $newName = '_' . $newName;   // ★ (2026-11-04, 56차 C2-2) Windows 장치 이름은 '_' 접두(FileManager::sanitizeFilename 40차와 같은 식·조건)
        if ($newName === '' || $newName === '.' || $newName === '..') {   // ★ (2026-11-01 17:20, 40차 점검) empty('0') 라 이름이 정확히 '0' 인 항목을 '잘못된 이름'으로 거부했음 → 빈 문자열만
            return ['success' => false, 'error' => __('api_err_invalid_filename', '잘못된 파일명입니다.')];
        }
        // ★ (2026-10-28 17:42, 소스 점검 3단계) 서버 실행 확장자 차단(저장될 이름 기준 — config.php fsServerExecReason) — 파일만(폴더 이름은 실행과 무관)
        if (!is_dir($target) && ($execReason = \fsServerExecReason($newName)) !== null) {
            return ['success' => false, 'error' => $execReason];
        }
        
        // ★ (2026-11-04 01:29, 54차 점검) 랜섬웨어 의심·무작위 확장자로 바꾸는 것 막기(53차 웹 이름 바꾸기와 같은 기준 — 파일이고 확장자가 바뀔 때만). 종전엔 내부 공유로는 a.txt → a.locked 가 됐음(재현)
        if (!is_dir($target) && strtolower(pathinfo($newName, PATHINFO_EXTENSION)) !== strtolower(pathinfo($target, PATHINFO_EXTENSION))) {
            $rnRw = (new FileManager())->checkRansomwarePublic($newName);
            if (empty($rnRw['allowed'])) return ['success' => false, 'error' => (string)($rnRw['reason'] ?? '')];
        }
        $newPath = dirname($target) . DIRECTORY_SEPARATOR . $newName;
        // ★ (2026-11-04 01:29, 54차 점검) 대소문자만 바꾸는 이름 바꾸기는 '같은 이름 있음'으로 막지 않음(일반 이름 바꾸기 isCaseOnlyRename 과 같은 기준 — Windows 는 같은 파일로 보임)
        $rnOldBase = basename($target);
        $rnCaseOnly = false;
        if ($rnOldBase !== $newName && mb_strtolower($rnOldBase, 'UTF-8') === mb_strtolower($newName, 'UTF-8')) {
            $rnEnt = @scandir(dirname($target));
            $rnCaseOnly = is_array($rnEnt) && in_array($rnOldBase, $rnEnt, true) && !in_array($newName, $rnEnt, true);
        }
        if (file_exists($newPath) && !$rnCaseOnly) {
            return ['success' => false, 'error' => __('api_err_file_exists', '같은 이름의 파일이 이미 존재합니다.')];
        }
        // ★ (2026-10-29 18:42, 22차 점검) 잠긴 파일은 이름을 바꾸지 않음(일반 이름 바꾸기와 같은 기준 — 그 경로 자체)
        // ★ (2026-11-02 10:24, 42차 점검) 폴더 이름을 바꾸면 그 안의 잠긴 파일 잠금이 옛 경로에 남아 풀렸음(그 경로 자체만 검사) →
        //   폴더면 하위 잠금까지 봄(받은 쪽 삭제·일반 이름 바꾸기 isTreeLocked 와 같은 기준)
        if ($this->_ishareLocked((int)$v['share']['storage_id'], $this->_ishareRelPath($v, $subPath), is_dir($target))) {
            return ['success' => false, 'error' => __('exif_strip_locked', '잠긴 파일입니다.')];
        }

        $rnWasDir = is_dir($target);
        if (!rename($target, $newPath)) {
            return ['success' => false, 'error' => __('api_err_rename_failed', '이름 변경 실패')];
        }
        // ★ (2026-11-01 01:53, 36차 전체 점검) Windows 는 끝 점·공백을 떼고 바꾸므로('b.' → 'b') 인덱스·숨김·권한·응답 이름도 실제 이름으로(폴더 만들기 24차 방식) — 종전엔 유령 인덱스 행·틀린 new_name
        if (DIRECTORY_SEPARATOR === '\\' && rtrim($newName, '. ') !== '') $newName = rtrim($newName, '. ');
        // ★ (2026-10-29 23:24, 24차 점검) 일반 이름 바꾸기와 같은 뒷정리 — 인덱스(옛→새)·옛 경로의 공유 링크·숨김 표시(3회차 재검토: 이 경로만 빠져 옛 링크가 남았음)
        try {
            $rnSid = (int)$v['share']['storage_id'];
            $rnOld = $this->_ishareRelPath($v, $subPath);
            $rnPar = dirname($rnOld); $rnNew = (($rnPar === '.' || $rnPar === '') ? '' : $rnPar . '/') . $newName;
            \FileIndex::getInstance()->moveFile($rnSid, $rnOld, $rnNew);
            (new FileManager())->cleanupSharesForPathPublic($rnSid, $rnOld, $rnWasDir);
            if (class_exists('HiddenItems')) \HiddenItems::movePath($rnSid, $rnOld, $rnSid, $rnNew, true);
            (new \Storage())->moveFolderPermissions($rnSid, $rnOld, $rnSid, $rnNew);   // ★ (2026-10-30 01:52, 26차 점검) 폴더 권한 규칙도 새 이름으로
        } catch (\Throwable $e) {}
        
        return ['success' => true, 'new_name' => $newName];
    }
    
    /**
     * 공유받은 폴더에 새 폴더 생성 (쓰기 권한 필요)
     */
    public function createFolderInInternalShare(int $shareId, string $subPath, string $folderName): array {
        $v = $this->validateInternalSharePath($shareId, $subPath, 'write');
        if (!$v['success']) return $v;
        
        $targetDir = $v['targetPath'];
        if (!is_dir($targetDir)) {
            return ['success' => false, 'error' => __('ishare_target_not_folder', '대상 폴더가 존재하지 않습니다.')];
        }
        
        $folderName = basename($folderName);
        // ★ (2026-11-03 08:04, 48차 점검) 새 폴더 이름도 같은 정리 — 종전엔 '.vault.json' 이라는 폴더를 만들 수 있었음(재현, 위 이름 바꾸기와 같음)
        $folderName = trim(preg_replace('/[<>:"\/\\\\|?*\x00-\x1f]/', '', $folderName), '. ');
        $folderName = trim(fsStripBidi($folderName), '. ');   // ★ (2026-11-17 07:56, 297차 — 296차 펜닐 결정 "이름의 방향 뒤집기 문자 빼기"를 다른 올리기 길에도)
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $folderName)) $folderName = '_' . $folderName;   // ★ (2026-11-04, 56차 C2-2) Windows 장치 이름은 '_' 접두(FileManager::sanitizeFilename 40차와 같은 식·조건)
        if ($folderName === '' || $folderName === '.' || $folderName === '..') {   // ★ (2026-11-01 17:20, 40차 점검) empty('0') 라 이름이 정확히 '0' 인 항목을 '잘못된 이름'으로 거부했음 → 빈 문자열만
            // ★ (2026-11-02 10:24, 42차 점검) 파일용 키(lang 값 '잘못된 파일명입니다.')를 써서 기본 문구 '폴더명'이 안 나왔음 → 폴더용 새 키
            return ['success' => false, 'error' => __('api_err_invalid_foldername', '잘못된 폴더명입니다.')];
        }

        $newPath = $targetDir . DIRECTORY_SEPARATOR . $folderName;
        if (file_exists($newPath)) {
            // ★ (2026-11-02 10:24, 42차 점검) 파일용 키(lang 값 '같은 이름의 파일이…')라 폴더 만들기에 '파일'로 나왔음 → 기존 키 name_exists(같은 이름이 이미 존재합니다.)
            return ['success' => false, 'error' => __('name_exists', '같은 이름이 이미 존재합니다.')];
        }
        
        // 경로 범위 확인
        $realShare = $this->safeRealpath($v['sharePath']);
        $realTargetDir = $this->safeRealpath($targetDir);
        // 보안: isSubPath로 정확한 하위 경로 검증 (prefix 확장 공격 방어)
        if ($realShare === false || $realTargetDir === false || !\isSubPath($realTargetDir, $realShare)) {
            return ['success' => false, 'error' => __('api_err_invalid_path')];
        }
        
        if (!mkdir($newPath, 0755)) {
            return ['success' => false, 'error' => __('api_err_mkdir_failed', '폴더 생성 실패')];
        }
        // ★ (2026-10-29 23:35, 24차 점검) 인덱스에도(일반 폴더 만들기와 같게, 4회차 재검토)
        // ★ (2026-10-29 23:42, 24차 점검) Windows 는 이름 끝의 점·공백을 떼고 만들므로 인덱스도 실제 이름으로(유령 행 방지, 5회차 재검토)
        $_mkIxName = (DIRECTORY_SEPARATOR === '\\') ? rtrim($folderName, '. ') : $folderName;
        if ($_mkIxName !== '') try { $mkRel = trim($this->_ishareRelPath($v, $subPath) . '/' . $_mkIxName, '/'); \FileIndex::getInstance()->addFile((int)$v['share']['storage_id'], $mkRel, ['is_dir' => 1, 'size' => 0, 'modified' => date('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
        
        return ['success' => true, 'name' => $folderName];
    }
    
    /**
     * 디렉토리 재귀 삭제 헬퍼
     */
    private function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) { if (!@unlink($path)) @rmdir($path); continue; }   // ★ (2026-11-04 01:27, 54차 점검) 링크는 링크만 지움 — 종전엔 폴더 링크를 따라 들어가 스토리지 밖 파일까지 지웠음(재현)
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
    
    /**
     * 사용자 검색 (내부 공유용 - 일반 사용자도 사용 가능)
     */
    public function searchUsers(string $query): array {
        $currentUserId = $this->auth->getUserId();
        $users = $this->db->load('users');
        $query = mb_strtolower(trim($query));
        
        if (mb_strlen($query) < 1) {
            return [];
        }
        
        $result = [];
        foreach ($users as $user) {
            if ((int)$user['id'] === $currentUserId) continue;
            // ★ (2026-11-02 11:55, 43차 점검) 정지를 미래 날짜로 예약했거나 종료일이 지난 계정은 아직(이미) 쓸 수 있으므로 공유 대상에서 빼지 않음(로그인 판정 fsUserSuspendedNow 와 같은 기준)
            $usSt = $user['status'] ?? 'active';
            if ($usSt !== 'active' && !($usSt === 'suspended' && function_exists('fsUserSuspendedNow') && !fsUserSuspendedNow($user))) continue;
            
            $username = mb_strtolower($user['username'] ?? '');
            $displayName = mb_strtolower($user['display_name'] ?? '');
            
            if (strpos($username, $query) !== false || strpos($displayName, $query) !== false) {
                $result[] = [
                    'id' => (int)$user['id'],
                    'username' => $user['username'],
                    'display_name' => $user['display_name'] ?? $user['username']
                ];
            }
            
            if (count($result) >= 10) break;
        }
        
        return $result;
    }
    
    // ===== 파일 드롭 (업로드 전용 공유) =====
    
    /**
     * 파일 드롭 공유 링크로 파일 업로드
     */
    public function uploadToFileDrop(string $token, array $file, ?string $password = null): array {
        $share = $this->db->find('shares', ['token' => $token, 'is_active' => 1]);
        if ($share && !$this->_creatorStillAllowed($share, false)) return ['success' => false, 'error' => __('share_creator_no_access', '공유한 사람에게 이 항목에 대한 권한이 없어 열 수 없습니다.')];   // ★ (2026-11-17 13:28, 322차) 파일 받기도 만든 사람 권한 다시 확인
        
        if (!$share || ($share['share_type'] ?? '') !== 'filedrop') {
            return ['success' => false, 'error' => __('ishare_invalid_filedrop', '유효하지 않은 파일 드롭 링크입니다.')];
        }
        
        // 만료 확인
        if (!empty($share['expire_at']) && strtotime($share['expire_at']) < time()) {
            $this->_cleanShareCache($share);  // ★ 캐시 정리
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => __('api_err_share_expired', '만료된 공유 링크입니다.')];
        }
        
        // 비밀번호 확인
        // ★ (2026-10-28 19:55, 6차 점검) 파일 드롭 비밀번호도 무차별 대입 제한(공유 열기와 같은 IP+토큰 10회/10분) — 종전엔 제한이 없었음
        // ★ (2026-10-29 09:39, 20차 점검) share.php 에서 비밀번호로 들어온 세션이면 통과 — 화면은 비밀번호를 다시 보내지 않아(password='') 종전엔 업로드가 항상 '비밀번호 틀림'
        if (!empty($share['password']) && !self::isShareSessionAuthed($token, $share)) {   // ★ (22차) 비밀번호에 묶인 세션 인증
            // ★ (2026-10-31 13:44, 34차 후속 개선) 비밀번호를 보낸 시도는 확인+기록을 한 잠금 안에서(sharePwReserve), 빈 비밀번호는 종전처럼 차단 여부만 봄. 맞으면 기록을 비움(시도를 먼저 기록하므로)
            // ★ (2026-11-01 17:16, 40차 점검) 비밀번호 '0' 을 '안 보냄'으로 보던 판정을 빈 값/null 기준으로
            $_pwGiven = ($password !== null && $password !== '');
            if ($_pwGiven ? !$this->sharePwReserve($token) : $this->sharePwBlocked($token)) {
                return ['success' => false, 'error' => __('api_err_too_many_attempts', '시도가 너무 많습니다. 잠시 후 다시 시도하세요.')];
            }
            if (!$_pwGiven || !password_verify($password, $share['password'])) {
                return ['success' => false, 'error' => __('api_err_share_password_wrong', '비밀번호가 올바르지 않습니다.')];
            }
            $this->sharePwClear($token);
        }
        
        // 업로드 횟수 제한 확인 (max_downloads를 max_uploads로도 활용)
        if (!empty($share['max_downloads']) && ($share['download_count'] ?? 0) >= $share['max_downloads']) {
            return ['success' => false, 'error' => __('ishare_upload_limit', '업로드 횟수를 초과했습니다.')];
        }
        
        // 대상 경로 확인
        $basePath = $this->storage->getRealPath($share['storage_id']);
        if (!$basePath) {
            return ['success' => false, 'error' => __('api_err_storage_path_not_found')];
        }
        
        $targetDir = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
        
        // 경로 안전성 검증
        if (!$this->isSharePathSafe($basePath, $targetDir)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!is_dir($targetDir)) {
            return ['success' => false, 'error' => __('ishare_target_not_folder', '대상 폴더가 존재하지 않습니다.')];
        }
        
        // 파일 검증
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => __('err_upload_failed', '업로드 실패')];
        }
        
        // 파일명 안전성 검증
        $filename = basename($file['name']);
        // ★ (2026-11-01 07:19, 37차 기능 점검) 저장 이름을 조각 경로(uploadToFileDropChunk)·sanitizeFilename 과 같은 정리로 만든 뒤 그 이름으로 검사·저장 —
        //   종전엔 슬래시만 지워 'virus.exe '·'run.bat::$DATA'(Windows 저장 땐 virus.exe·run.bat)가 위험 확장자 검사를 지났고 '.vault.json' 도 심을 수 있었음
        $filename = trim(preg_replace('/[<>:"\/\\\\|?*\x00-\x1f]/', '', $filename), '. ');
        $filename = trim(fsStripBidi($filename), '. ');   // ★ (2026-11-17 07:56, 297차 — 296차 펜닐 결정 "이름의 방향 뒤집기 문자 빼기"를 다른 올리기 길에도)
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $filename)) $filename = '_' . $filename;   // ★ (2026-11-04, 56차 C2-2) Windows 장치 이름은 '_' 접두(FileManager::sanitizeFilename 40차와 같은 식·조건)
        if ($filename === '' || $filename === '.' || $filename === '..') {   // ★ (2026-11-01 17:20, 40차 점검) empty('0') 라 이름이 정확히 '0' 인 항목을 '잘못된 이름'으로 거부했음 → 빈 문자열만
            return ['success' => false, 'error' => __('api_err_invalid_filename', '잘못된 파일명입니다.')];
        }

        // 위험 확장자 차단 (PHP, JSP, 실행파일 등)
        $dangerousExts = DANGEROUS_EXTS;
        $finalExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($finalExt, $dangerousExts)) {
            return ['success' => false, 'error' => __('api_err_dangerous_ext', '보안상 허용되지 않는 파일 형식입니다.')];
        }
        // ★ (2026-10-28 17:42, 소스 점검 3단계) 서버 실행 확장자 차단(저장될 이름 기준 — config.php fsServerExecReason) — 위 검사는 정리 전 이름이라 'x.p:hp'(저장 땐 'x.php') 가 통과했음
        if (($execReason = \fsServerExecReason($filename)) !== null) {
            return ['success' => false, 'error' => $execReason];
        }
        // 이중 확장자 차단 (예: evil.php.jpg)
        $nameParts = explode('.', strtolower($filename));
        if (count($nameParts) > 2) {
            for ($i = 1; $i < count($nameParts) - 1; $i++) {
                if (in_array($nameParts[$i], $dangerousExts)) {
                    return ['success' => false, 'error' => __('api_err_dangerous_filename', '보안상 허용되지 않는 파일명입니다.')];
                }
            }
        }
        
        // MIME 타입 검증 (실행 가능 파일 차단)
        if (function_exists('finfo_open') && !empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $blockedMimes = [
                'application/x-httpd-php', 'application/x-php', 'text/x-php',
                'application/x-executable', 'application/x-sharedlib',
                'application/x-shellscript', 'text/x-shellscript',
                'application/x-msdos-program',
            ];
            if (in_array($realMime, $blockedMimes)) {
                return ['success' => false, 'error' => __('api_err_dangerous_mime', '보안상 허용되지 않는 파일입니다.')];
            }
        }
        
        // 중복 파일명 처리 (rename)
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($targetPath)) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $i = 1;
            do {
                $newName = $name . " ({$i})" . ($ext !== '' ? ".{$ext}" : '');   // ★ (2026-11-01 17:20, 40차 점검) 확장자 '0'(log.0)이 거짓이라 'log (1)' 로 확장자가 떨어졌음
                $targetPath = $targetDir . DIRECTORY_SEPARATOR . $newName;
                $i++;
            } while (file_exists($targetPath));
            $filename = $newName;
        }
        
        // ★ (2026-10-29 18:45, 22차 점검) 랜섬웨어 의심 확장자·용량 한도 확인 + 사용량 반영(조각 업로드 uploadChunk 와 같은 기준) — 종전엔 이 옛(비조각) 경로에 모두 없어,
        //   링크를 가진 외부인이 filedrop_upload 로 직접 올리면 용량 한도를 무시하고 채울 수 있었고 사용량에도 잡히지 않았음
        if (($lgErr = $this->_legacyUploadGuard((int)$share['storage_id'], $filename, (int)($file['size'] ?? 0), (string)($file['tmp_name'] ?? ''))) !== null) {
            return ['success' => false, 'error' => $lgErr];
        }

        // ★ (2026-11-01 07:19, 37차 기능 점검) 업로드 수 한도를 잠금 안에서 '한도 미만일 때만 +1'로 먼저 잡고(실패하면 거부), 저장에 실패하면 되돌림 —
        //   종전엔 위 검사와 저장 뒤 +1 이 따로라 동시에 올린 여러 파일이 한도를 넘겨 저장됐음
        if (!$this->shareIncrementIfBelow($token)) {
            return ['success' => false, 'error' => __('ishare_upload_limit', '업로드 횟수를 초과했습니다.')];
        }
        $fdApplied = $this->lastIncApplied;   // ★ (2026-11-02 11:36, 43차 점검) 실제로 올렸는지(잠금 실패면 못 올림 — 42차 재검토의 다른 두 호출부와 같게)
        // 파일 이동
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            if ($fdApplied) $this->shareIncrement($token, ['download_count' => -1]);   // ★ (2026-11-01 07:19, 37차 기능 점검) 위에서 잡은 1회 되돌림 — ★ (2026-11-02 11:36, 43차 점검) 실제로 올렸을 때만
            return ['success' => false, 'error' => __('err_upload_failed', '업로드 실패')];
        }
        $this->storage->updateUsedSize((int)$share['storage_id'], (int)@filesize($targetPath));

        // 업로드 카운트 증가
        // ★ (2026-11-01 01:10, 35차 전체 점검) 주인 알림(미확인 업로드 수·마지막 업로드)도 조각 업로드(uploadToFileDropChunk)와 같게 올림 — 종전엔 이 옛 경로로 올리면 알림이 없었음
        // ★ (2026-11-01 01:53, 36차 전체 점검) Windows 는 이름 끝 점·공백을 떼고 저장하므로 알림·인덱스 이름도 실제와 같게(폴더 만들기 24차 방식)
        $fdIxName = (DIRECTORY_SEPARATOR === '\\' && rtrim($filename, '. ') !== '') ? rtrim($filename, '. ') : $filename;
        // ★ (2026-11-01 07:19, 37차 기능 점검) download_count 는 위 shareIncrementIfBelow 에서 이미 +1 — 여기선 알림 수만
        $this->shareIncrement($token, $fdApplied ? ['pending_uploads' => 1] : ['download_count' => 1, 'pending_uploads' => 1], [   // ★ (2026-11-02 11:36, 43차 점검) 못 올렸으면 여기서 셈
            'last_upload_at' => date('Y-m-d H:i:s'),
            'last_upload_name' => $fdIxName
        ]);
        // ★ (2026-11-01 01:10, 35차 전체 점검) 검색 인덱스에도 넣음(조각 업로드는 uploadChunk 가 넣음) — 실패해도 업로드는 성공
        try {
            require_once __DIR__ . '/FileIndex.php';
            $fdRel = trim(trim(str_replace('\\', '/', (string)($share['file_path'] ?? '')), '/') . '/' . $fdIxName, '/');
            \FileIndex::getInstance()->addFile((int)$share['storage_id'], $fdRel, ['is_dir' => 0, 'size' => (int)@filesize($targetPath), 'modified' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {}
        // ★ (2026-11-04) 56차 활동 기록 — 올린 사람은 링크 방문자라 공유 주인이 아니라 guest(로그인한 방문자면 그 사람 — share.php 공유 접근 기록과 같은 기준), details 에 파일받기·공유 번호·주인 번호
        $this->_logShareUpload((int)$share['storage_id'], trim(trim(str_replace('\\', '/', (string)($share['file_path'] ?? '')), '/') . '/' . $fdIxName, '/'), (int)@filesize($targetPath),
            $this->_filedropLogDetails($share), $this->_filedropLogWho());

        return ['success' => true, 'filename' => $filename];
    }
    
    /**
     * ★ (2026-10-29 11:09, 20차 점검) 공유 횟수는 잠금 안에서 최신 값에 더함 — 종전엔 처음 읽은 값 + 1 을 저장해, 동시에 받거나 올리면 횟수가 빠졌음
     *   (재현: 파일받기 링크로 18개 동시 업로드 → 9~11 로 기록) — 받기 횟수 제한도 그만큼 넘길 수 있었음
     */
    private function shareIncrement(string $token, array $inc, array $set = []): void {
        $this->db->atomicUpdate('shares', function ($data) use ($token, $inc, $set) {
            $data = is_array($data) ? $data : [];
            foreach ($data as &$row) {
                if ((string)($row['token'] ?? '') !== (string)$token) continue;
                foreach ($inc as $k => $n) $row[$k] = (int)($row[$k] ?? 0) + (int)$n;
                foreach ($set as $k => $v) $row[$k] = $v;
            }
            unset($row);
            return $data;
        });
    }

    /**
     * ★ (2026-11-01 07:19, 37차 기능 점검) 잠금 안에서 '한도(max_downloads) 미만일 때만' download_count +1 — 성공하면 true.
     *   종전엔 횟수 검사(accessShare·업로드 앞)와 +1 이 따로라, 동시에 보내면 모두 검사를 지나 한도를 넘겨 받거나 올릴 수 있었음
     */
    /** ★ (2026-11-02 11:01, 42차 재검토) 바로 앞 shareIncrementIfBelow 가 숫자를 실제로 올렸는지 — 잠금을 못 얻으면 '허용(true)했지만 못 올림'이라, 이걸 '1회 잡아 둠'으로 보고 되돌리면
     *   올린 적 없는 숫자를 깎아 한도가 1회 더 열렸고, 파일받기는 완료 때 +1 을 건너뛰어 그 업로드를 아예 안 셌음(검토 발견) */
    private bool $lastIncApplied = false;
    private function shareIncrementIfBelow(string $token): bool {
        $this->lastIncApplied = false;
        $ok = false;
        $saved = $this->db->atomicUpdate('shares', function ($data) use ($token, &$ok) {
            $data = is_array($data) ? $data : [];
            foreach ($data as &$row) {
                if ((string)($row['token'] ?? '') !== (string)$token) continue;
                $max = (int)($row['max_downloads'] ?? 0);
                $cnt = (int)($row['download_count'] ?? 0);
                if ($max > 0 && $cnt >= $max) break;
                $row['download_count'] = $cnt + 1;
                $ok = true;
                break;
            }
            unset($row);
            return $data;
        });
        if ($saved) { $this->lastIncApplied = $ok; return $ok; }
        // ★ (2026-11-01 07:57, 37차 기능 점검 재검토) 잠금을 못 얻어 저장이 안 됐으면(부하) 종전처럼 읽은 값으로만 판정 — 처음 고친 코드는 이때 늘 false 라
        //   한도 없는 공유까지 정상 받기·올리기가 '횟수 초과' 로 막혔음. 숫자는 이번엔 못 올림(종전 잠금 없는 방식과 같은 정도)
        $row = $this->db->find('shares', ['token' => $token]);
        if (!$row) return false;
        $max = (int)($row['max_downloads'] ?? 0);
        return !($max > 0 && (int)($row['download_count'] ?? 0) >= $max);
    }

    /**
     * 파일 드롭 공유 링크로 청크 업로드 (대용량 파일 지원)
     * - 외부 공개(비로그인)이므로 매 청크마다 토큰/만료/비밀번호/횟수 재검증.
     * - 첫 청크에서 위험 확장자/파일명 차단(filedrop 고유 보안).
     * - 검증 후 FileManager::uploadChunk 코어(청크 조립 + 경쟁조건 락) 재사용(skipPermCheck).
     * - 조립 완료(complete) 시 업로드 횟수 1 증가.
     */
    public function uploadToFileDropChunk(string $token, array $data, ?string $password = null): array {
        $share = $this->db->find('shares', ['token' => $token, 'is_active' => 1]);
        if ($share && !$this->_creatorStillAllowed($share, false)) return ['success' => false, 'error' => __('share_creator_no_access', '공유한 사람에게 이 항목에 대한 권한이 없어 열 수 없습니다.')];   // ★ (2026-11-17 13:28, 322차) 파일 받기도 만든 사람 권한 다시 확인
        if (!$share || ($share['share_type'] ?? '') !== 'filedrop') {
            return ['success' => false, 'error' => __('ishare_invalid_filedrop', '유효하지 않은 파일 드롭 링크입니다.')];
        }
        // 만료 확인
        if (!empty($share['expire_at']) && strtotime($share['expire_at']) < time()) {
            $this->_cleanShareCache($share);
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => __('api_err_share_expired', '만료된 공유 링크입니다.')];
        }
        // 비밀번호 확인
        // ★ (2026-10-28 19:55, 6차 점검) 파일 드롭 비밀번호도 무차별 대입 제한(공유 열기와 같은 IP+토큰 10회/10분) — 종전엔 제한이 없었음
        // ★ (2026-10-29 09:39, 20차 점검) share.php 에서 비밀번호로 들어온 세션이면 통과 — 화면은 비밀번호를 다시 보내지 않아(password='') 종전엔 업로드가 항상 '비밀번호 틀림'
        if (!empty($share['password']) && !self::isShareSessionAuthed($token, $share)) {   // ★ (22차) 비밀번호에 묶인 세션 인증
            // ★ (2026-10-31 13:44, 34차 후속 개선) 비밀번호를 보낸 시도는 확인+기록을 한 잠금 안에서(sharePwReserve), 빈 비밀번호는 종전처럼 차단 여부만 봄. 맞으면 기록을 비움(시도를 먼저 기록하므로)
            // ★ (2026-11-01 17:16, 40차 점검) 비밀번호 '0' 을 '안 보냄'으로 보던 판정을 빈 값/null 기준으로
            $_pwGiven = ($password !== null && $password !== '');
            if ($_pwGiven ? !$this->sharePwReserve($token) : $this->sharePwBlocked($token)) {
                return ['success' => false, 'error' => __('api_err_too_many_attempts', '시도가 너무 많습니다. 잠시 후 다시 시도하세요.')];
            }
            if (!$_pwGiven || !password_verify($password, $share['password'])) {
                return ['success' => false, 'error' => __('api_err_share_password_wrong', '비밀번호가 올바르지 않습니다.')];
            }
            $this->sharePwClear($token);
        }
        // 업로드 횟수 제한 확인 (max_downloads를 max_uploads로 활용)
        if (!empty($share['max_downloads']) && ($share['download_count'] ?? 0) >= $share['max_downloads']) {
            return ['success' => false, 'error' => __('ishare_upload_limit', '업로드 횟수를 초과했습니다.')];
        }
        
        // 파일명 안전성 + 위험 확장자 차단 (파일명 기반이라 데이터 불필요, 모든 청크 검증)
        // ★ (2026-11-01 01:53, 36차 전체 점검) 검사 이름을 실제 저장 이름과 같은 정리로(FileManager::sanitizeFilename 과 같은 식) — 종전엔 basename(마지막 '/' 뒤만)으로 검사해
        //   'virus.ex/e'·'virus.ex:e' 가 확장자 없음으로 통과한 뒤 'virus.exe' 로 저장됐음(DANGEROUS_EXTS 우회)
        $filename = trim(preg_replace('/[<>:"\/\\\\|?*\x00-\x1f]/', '', (string)($data['filename'] ?? '')), '. ');
        $filename = trim(fsStripBidi($filename), '. ');   // ★ (2026-11-17 07:56, 297차 — 296차 펜닐 결정 "이름의 방향 뒤집기 문자 빼기"를 다른 올리기 길에도)
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $filename)) $filename = '_' . $filename;   // ★ (2026-11-04, 56차 C2-2) 검사·기록 이름을 실제 저장 이름(FileManager::uploadChunk→sanitizeFilename 의 Windows '_' 접두)과 같게
        if ($filename === '' || $filename === '.' || $filename === '..') {   // ★ (2026-11-01 17:20, 40차 점검) empty('0') 라 이름이 정확히 '0' 인 항목을 '잘못된 이름'으로 거부했음 → 빈 문자열만
            return ['success' => false, 'error' => __('api_err_invalid_filename', '잘못된 파일명입니다.')];
        }
        $dangerousExts = DANGEROUS_EXTS;
        $finalExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($finalExt, $dangerousExts)) {
            return ['success' => false, 'error' => __('api_err_dangerous_ext', '보안상 허용되지 않는 파일 형식입니다.')];
        }
        // ★ (2026-10-28 17:42, 소스 점검 3단계) 서버 실행 확장자 차단(저장될 이름 기준 — config.php fsServerExecReason) — 위 검사는 정리 전 이름이라 'x.p:hp'(저장 땐 'x.php') 가 통과했음
        if (($execReason = \fsServerExecReason($filename)) !== null) {
            return ['success' => false, 'error' => $execReason];
        }
        $nameParts = explode('.', strtolower($filename));
        if (count($nameParts) > 2) {
            for ($i = 1; $i < count($nameParts) - 1; $i++) {
                if (in_array($nameParts[$i], $dangerousExts)) {
                    return ['success' => false, 'error' => __('api_err_dangerous_filename', '보안상 허용되지 않는 파일명입니다.')];
                }
            }
        }
        
        // 대상: 공유의 storage_id + file_path (스토리지 루트 기준 상대경로)
        $storageId = (int)$share['storage_id'];
        $relativePath = str_replace(['\\', '/'], '/', trim($share['file_path'] ?? '', '/'));
        
        require_once __DIR__ . '/FileManager.php';
        $fileManager = new FileManager();
        // skipPermCheck=true: filedrop은 토큰으로 검증(스토리지 로그인 권한 체계 아님)
        // ★ (2026-11-02 10:29, 42차 점검) 마지막 조각(조립을 끝내는 조각 — share.php 는 마지막 조각을 다른 조각이 다 끝난 뒤 따로 보냄)은 업로드 수를 잠금 안에서 먼저 1회 잡고
        //   (한도면 거부), 조립이 안 끝났거나 실패·중복(deduped)이면 되돌림 — 37차 옛 경로(uploadToFileDrop)와 같은 방식. 종전엔 위 한도 확인은 조립 전, +1 은 조립 뒤라
        //   그 사이(큰 파일은 수 초) 들어온 다른 방문자의 마지막 조각도 통과해 한도를 넘겨 저장됐음
        $fdTotal = (int)($data['totalChunks'] ?? 0);
        $fdReserved = false;
        if ($fdTotal > 0 && (int)($data['chunkIndex'] ?? -1) === $fdTotal - 1) {
            if (!$this->shareIncrementIfBelow($token)) {
                return ['success' => false, 'error' => __('ishare_upload_limit', '업로드 횟수를 초과했습니다.')];
            }
            $fdReserved = $this->lastIncApplied;   // ★ (2026-11-02 11:01, 42차 재검토) 실제로 올렸을 때만 '잡아 둠'(못 올렸으면 완료 때 종전처럼 +1)
        }
        try {
            $result = $fileManager->uploadChunk($storageId, $relativePath, $data, true);
        } catch (\Throwable $e) {
            if ($fdReserved) $this->shareIncrement($token, ['download_count' => -1]);
            throw $e;
        }
        $fdDone = !empty($result['success']) && !empty($result['complete']) && empty($result['deduped']);
        if ($fdReserved && !$fdDone) $this->shareIncrement($token, ['download_count' => -1]);   // 잡아 둔 1회 되돌림
        
        // 조립 완료 시에만 업로드 횟수 증가 (중복 조립 deduped 제외)
        if ($fdDone) {
            // 공유 소유자 알림용: 미확인 업로드 수 + 마지막 업로드 정보
            // ★ (2026-11-02 10:29, 42차 점검) 마지막 조각에서 이미 잡았으면 download_count 는 더하지 않음(다른 조각이 조립을 끝낸 경우 — 옛 화면 등 — 만 종전처럼 +1)
            $this->shareIncrement($token, $fdReserved ? ['pending_uploads' => 1] : ['download_count' => 1, 'pending_uploads' => 1], [
                'last_upload_at' => date('Y-m-d H:i:s'),
                'last_upload_name' => $result['filename'] ?? $filename
            ]);
            // ★ (2026-11-04) 56차 활동 기록(옛 경로 uploadToFileDrop 과 같은 사용자 기준, uploadChunk 가 알려 준 실제 저장 경로)
            if (empty($result['skipped'])) $this->_logShareUpload($storageId, (string)($result['saved_path'] ?? trim($relativePath . '/' . ($result['filename'] ?? $filename), '/')), (int)($result['size'] ?? 0),
                $this->_filedropLogDetails($share), $this->_filedropLogWho());
        }
        return $result;
    }
    
    /**
     * 공유 MP3 파일의 ID3v2 APIC 커버 이미지 추출
     * 
     * 동작:
     * 1. 토큰/비밀번호 검증 (accessShare 동일 패턴)
     * 2. 파일이 mp3인지 확인
     * 3. ID3v2 APIC 프레임에서 커버 추출
     * 4. data/thumbcache/share_audio/ 캐시 (토큰별 분리)
     * 5. 이미지 바이트 배열 반환 (HTTP 응답은 호출자 책임)
     * 
     * 보안:
     * - 토큰 + 비밀번호 동일 검증 (accessShare 패턴)
     * - 캐시 키에 토큰 포함 (다른 공유와 격리)
     * - 캐시 파일명은 hash라 외부에서 추측 불가
     * 
     * @return array|null ['mime' => 'image/jpeg', 'data' => binary, 'cached' => bool]
     *                    실패 시 null
     */
    public function getShareCover(string $token, ?string $password = null, ?string $subFile = null): ?array {
        // 폴더 공유 + sub-path 모드
        if ($subFile !== null && $subFile !== '') {
            // sub-path 보안 검증 (basename + 화이트리스트 + realpath)
            $fullPath = $this->validateSharedFolderSubPath($token, $password, $subFile);
            if ($fullPath === null) return null;
            
            // mp3만 ID3 커버 추출 가능
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            if ($ext !== 'mp3') return null;
            
            // 캐시 (sub-file 포함된 토큰별 격리 키)
            $cacheKey = sha1($token . '|' . $subFile);
            $cacheDir = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/thumbcache/share_audio';
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
            $cacheFile = $cacheDir . '/' . $cacheKey . '.bin';
            $cacheMeta = $cacheDir . '/' . $cacheKey . '.meta';
            // ★ 네거티브 캐시 마커 (커버 없는 mp3 — 메인 audioCover 패턴 동일)
            //   첫 ID3 파싱에서 커버 없음 확인 시 마커 저장 → 재요청 시 즉시 null 반환 (파싱 스킵)
            $cacheNoCover = $cacheDir . '/' . $cacheKey . '.nocover';
            // ★ (2026-10-29 11:31, 20차 점검) 같은 이름으로 곡 파일을 바꿔도 줄인 커버(.w512.jpg)가 그대로라 예전 커버가 계속 나왔음
            // ★ (2026-10-29 15:35, 21차 재점검) 20차엔 캐시 키에 파일 시각을 섞었는데, 그러면 공유 삭제 정리(_cleanShareCache, 옛 키 이름)가 줄인 커버를
            //   못 지워 파일이 쌓였음(재현) → 키는 원래대로 두고, 아래에서 원본 커버를 새로 만들 때(곡 파일이 바뀜) 옛 줄인 커버를 지움.
            //   브라우저 쪽은 커버 주소에 &v=파일시각 이 붙어(share.php) 따로 갱신됨
            $retKey = $cacheKey;
            
            // 캐시 hit
            if (file_exists($cacheFile) && file_exists($cacheMeta)) {
                $cacheMtime = @filemtime($cacheFile);
                $fileMtime = @filemtime($fullPath);
                if ($cacheMtime !== false && $fileMtime !== false && $cacheMtime >= $fileMtime) {
                    $mime = @file_get_contents($cacheMeta);
                    $data = @file_get_contents($cacheFile);
                    // ★ (2026-10-31 13:44, 34차 후속 개선) 단일 파일 커버(26차)와 같이 그림(래스터) 형식만 — 종전엔 .meta 의 형식을 그대로 돌려줘 수정 전에 만든 image/svg+xml 캐시가 계속 쓰였음.
                    //   맞지 않으면 아래에서 캐시를 지우고 다시 추출(추출 함수는 이미 래스터만 돌려줌)
                    $mime = ($mime !== false) ? trim($mime) : false;
                    // ★ (2026-11-02 10:27, 42차 점검) .meta 둘째 줄에 곡 파일 크기를 적어 두고 다르면 다시 추출 — 종전엔 시각만 비교해 mp3tag 처럼 시각을 유지한 채 표지를 바꾸면
                    //   옛 표지가 계속 나왔음(탐색기 39차 '크기 포함' 키와 같은 기준). 크기 줄이 없는 옛 캐시는 종전처럼 시각만 봄
                    $_cvSzOk = true;
                    if ($mime !== false && strpos($mime, "\n") !== false) {
                        [$mime, $_cvSz] = explode("\n", $mime, 2);
                        $mime = trim($mime); $_cvSz = trim($_cvSz);
                        if ($_cvSz !== '' && (int)$_cvSz !== (int)@filesize($fullPath)) $_cvSzOk = false;
                    }
                    if ($_cvSzOk && $mime !== false && $data !== false && strlen($data) > 100 && preg_match('/^image\/(jpe?g|pjpeg|png|x-png|gif|webp|bmp|x-ms-bmp|avif|heic|heif)$/i', $mime)) {
                        return ['mime' => $mime, 'data' => $data, 'cached' => true, 'cache_key' => $retKey];
                    }
                    if ($mime !== false && !preg_match('/^image\/(jpe?g|pjpeg|png|x-png|gif|webp|bmp|x-ms-bmp|avif|heic|heif)$/i', $mime)) { @unlink($cacheFile); @unlink($cacheMeta); }
                }
            }
            
            // ★ 네거티브 캐시 hit — 커버 없는 mp3로 기록된 경우 ID3 파싱 스킵 (즉시 null)
            //   파일 mtime 비교로 stale 감지 (파일 변경 시 마커 무효화)
            if (file_exists($cacheNoCover) && substr((string)@file_get_contents($cacheNoCover), -strlen(';cv:' . self::COVER_VER)) !== ';cv:' . self::COVER_VER) @unlink($cacheNoCover);   // ★ (2026-11-12 16:10, 100차 CVR-1) 다른 판 표시는 지우고 다시 추출(COVER_VER 주석)
            if (file_exists($cacheNoCover)) {
                $markerMtime = @filemtime($cacheNoCover);
                $fileMtime = @filemtime($fullPath);
                // ★ (2026-11-02 10:27, 42차 점검) 표시에 적어 둔 곡 파일 크기가 다르면 다시 확인(위 커버 캐시와 같음, 옛 '1' 표시는 종전처럼 시각만)
                $_ncRaw = (string)@file_get_contents($cacheNoCover);
                $_ncSzOk = !(strncmp($_ncRaw, 'sz:', 3) === 0 && (int)substr($_ncRaw, 3) !== (int)@filesize($fullPath));
                if ($_ncSzOk && $markerMtime !== false && $fileMtime !== false && $markerMtime >= $fileMtime) {
                    return null;  // 커버 없음 (캐시된 결과)
                }
            }
            
            // 캐시 miss → ID3 추출 (★ 21차 재점검: 곡 파일이 바뀐 경우이므로 옛 줄인 커버 삭제 — share.php 가 새 커버로 다시 만듦)
            @unlink($cacheDir . '/' . $cacheKey . '.w512.jpg');
            $cover = $this->extractID3v2Cover($fullPath);
            if (!$cover) {
                // 커버 없음 → 네거티브 캐시 마커 저장 (다음 요청 시 ID3 파싱 스킵)
                @file_put_contents($cacheNoCover, 'sz:' . (int)@filesize($fullPath) . ';cv:' . self::COVER_VER);   // ★ (2026-11-02 10:27, 42차 점검) 곡 파일 크기도 적음(위 비교용)   // ★ (2026-11-12 16:10, 100차 CVR-1) 끝에 추출 판(COVER_VER 주석)
                return null;
            }
            
            // 캐시 저장
            @file_put_contents($cacheFile, $cover['data']);
            @file_put_contents($cacheMeta, $cover['mime'] . "\n" . (int)@filesize($fullPath));   // ★ (2026-11-02 10:27, 42차 점검) 둘째 줄 = 곡 파일 크기(위 비교용)
            
            return ['mime' => $cover['mime'], 'data' => $cover['data'], 'cached' => false, 'cache_key' => $retKey];
        }
        
        // 단일 파일 공유 (기존 로직)
        // accessShare 검증 (만료/비밀번호/파일 존재 등 일관)
        $access = $this->accessShare($token, $password);
        if (!$access['success']) {
            return null;
        }
        
        // 폴더 공유는 미지원
        if ($access['share']['is_dir']) {
            return null;
        }
        
        // mp3 확장자 체크
        $filename = $access['share']['filename'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'mp3') {
            return null;
        }
        
        // 실제 파일 경로 계산 (accessShare 내부와 동일 로직)
        $share = $this->db->find('shares', ['token' => $token, 'is_active' => 1]);
        if (!$share) return null;
        
        $basePath = $this->storage->getRealPath($share['storage_id']);
        if (!$basePath) return null;
        
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
        if (!$this->isSharePathSafe($basePath, $fullPath) || !is_file($fullPath)) {
            return null;
        }
        
        // 파일 크기 제한 (audioCover와 동일: 0 또는 500MB 초과는 스킵)
        $fileSize = @filesize($fullPath);
        if ($fileSize === false || $fileSize <= 0 || $fileSize > 500 * 1024 * 1024) {
            return null;
        }
        
        // 캐시 경로 (토큰별 분리)
        $mtime = @filemtime($fullPath) ?: 0;
        // ★ (2026-11-02 10:27, 42차 점검) 키에 파일 크기도 넣음 — 종전엔 시각만이라 mp3tag 처럼 시각을 유지한 채 표지를 바꾸면 옛 표지(·커버 없음 표시)가 계속 쓰였음.
        //   이 키는 ETag 로도 쓰여 브라우저 쪽도 함께 갱신됨. 공유 삭제 정리(_cleanShareCache 3-B)도 같은 키로
        $cacheKey = md5($token . '/' . $share['file_path'] . '/' . $mtime . '-' . (int)$fileSize);
        $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
        $cacheDir = $dataDir . DIRECTORY_SEPARATOR . 'thumbcache' . DIRECTORY_SEPARATOR . 'share_audio';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        $cacheMetaFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.meta';
        $cacheImgFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.img';
        $cacheNoCoverFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.nocover';
        
        // 캐시 히트
        if (is_file($cacheMetaFile) && is_file($cacheImgFile)) {
            $imgFileSize = @filesize($cacheImgFile);
            if ($imgFileSize !== false && $imgFileSize > 0) {
                $mime = trim(@file_get_contents($cacheMetaFile));
                if ($mime && preg_match('/^image\/(jpe?g|pjpeg|png|x-png|gif|webp|bmp|x-ms-bmp|avif|heic|heif)$/i', $mime)) {   // ★ (2026-10-30 01:54, 26차 점검) 그림(래스터)만 — SVG 커버는 같은 주소에서 문서로 열릴 수 있어 제외
                    return [
                        'mime' => $mime,
                        'data' => @file_get_contents($cacheImgFile),
                        'cached' => true,
                        'cache_key' => $cacheKey,
                    ];
                }
            }
            @unlink($cacheMetaFile);
            @unlink($cacheImgFile);
        }
        
        // 네거티브 캐시 (커버 없음 기록)
        if (is_file($cacheNoCoverFile) && substr((string)@file_get_contents($cacheNoCoverFile), -strlen(';cv:' . self::COVER_VER)) !== ';cv:' . self::COVER_VER) @unlink($cacheNoCoverFile);   // ★ (2026-11-12 16:10, 100차 CVR-1) 다른 판 표시('1' 등)는 지우고 다시 추출(COVER_VER 주석)
        if (is_file($cacheNoCoverFile)) {
            return null;
        }
        
        // FileManager의 ID3v2 추출 로직과 동일 (프라이빗 메서드 복제 — 결합도 낮춤)
        $cover = $this->extractID3v2Cover($fullPath);
        if (!$cover) {
            @file_put_contents($cacheNoCoverFile, '1;cv:' . self::COVER_VER);   // ★ (2026-11-12 16:10, 100차 CVR-1) 추출 판도 적음(COVER_VER 주석) — 종전 '1'
            return null;
        }
        
        // 캐시 저장
        @file_put_contents($cacheMetaFile, $cover['mime']);
        @file_put_contents($cacheImgFile, $cover['data']);
        
        return [
            'mime' => $cover['mime'],
            'data' => $cover['data'],
            'cached' => false,
            'cache_key' => $cacheKey,
        ];
    }
    
    /**
     * MP3 파일의 ID3v2 태그에서 APIC 프레임(커버 이미지) 추출
     * 
     * FileManager::extractID3v2Cover의 로직을 복제 (결합도 낮춤).
     * 외부 라이브러리 의존 없이 ID3v2.3/2.4 표준만 파싱.
     * 
     * @return array|null ['mime' => 'image/jpeg', 'data' => binary]
     */
    private function extractID3v2Cover(string $file): ?array {
        $fp = @fopen($file, 'rb');
        if (!$fp) return null;
        
        try {
            // ID3v2 헤더 (10바이트): "ID3" + version(2) + flags(1) + size(4, syncsafe)
            $header = fread($fp, 10);
            if (strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') {
                return null;
            }
            
            // syncsafe integer (각 바이트 7비트만 사용)
            $sizeBytes = substr($header, 6, 4);
            $tagSize = 0;
            for ($i = 0; $i < 4; $i++) {
                $tagSize = ($tagSize << 7) | (ord($sizeBytes[$i]) & 0x7F);
            }
            
            // 태그 크기 제한 (최대 10MB — 이상치 방지)
            if ($tagSize <= 0 || $tagSize > 10 * 1024 * 1024) {
                return null;
            }
            
            $tagData = fread($fp, $tagSize);
            if (strlen($tagData) < $tagSize) {
                // 파일이 짧아도 가능한 만큼 시도
            }
            
            // APIC 프레임 검색
            $offset = 0;
            $tagDataLen = strlen($tagData);
            
            while ($offset + 10 <= $tagDataLen) {
                $frameId = substr($tagData, $offset, 4);
                
                // 프레임 끝 (null 패딩) 또는 ID 형식 깨짐
                if ($frameId === "\x00\x00\x00\x00" || !preg_match('/^[A-Z0-9]{4}$/', $frameId)) {
                    break;
                }
                
                // 프레임 크기 (4바이트, ID3v2.3는 일반 정수, 2.4는 syncsafe)
                $frameSize = unpack('Nsize', substr($tagData, $offset + 4, 4))['size'];
                
                // ★ (2026-11-12 15:30, 99차 AUD-1) v2.4 는 syncsafe 로 읽음(가사 추출·탐색기 표지와 같은 기준) — 종전엔 일반 정수로 먼저 읽어 앞 프레임이 128바이트 이상(한글 제목 약 43자)이면
                //   그 값이 태그 안이라 엉뚱한 곳으로 건너뛰어 표지 없음(204)이었음(재현: 탐색기 200·공유 204). 다만 v2.4 머리에 일반 정수 크기를 쓴 비표준 파일은 종전에 공유에서 표지가 나왔으므로,
                //   syncsafe 로 건너뛴 자리가 프레임 시작·패딩·태그 끝이 아니고 일반 정수 자리는 맞을 때만 종전 값(일반 정수)을 그대로 씀
                if (ord($header[3]) >= 4) {
                    $sb = substr($tagData, $offset + 4, 4);
                    $ssSize = ((ord($sb[0]) & 0x7F) << 21) | ((ord($sb[1]) & 0x7F) << 14) | ((ord($sb[2]) & 0x7F) << 7) | (ord($sb[3]) & 0x7F);
                    $okNext = static function (string $d, int $p): bool {   // 그 자리가 태그 끝·패딩(0)·'프레임 ID + 태그 안에 드는 크기'인지
                        $n = strlen($d);
                        if ($p === $n) return true;
                        if ($p > $n) return false;
                        if (strspn($d, "\x00", $p, 4) === min(4, $n - $p)) return true;
                        if ($p + 10 > $n || preg_match('/^[A-Z0-9]{4}$/', substr($d, $p, 4)) !== 1) return false;
                        $q = substr($d, $p + 4, 4);
                        return (((ord($q[0]) & 0x7F) << 21) | ((ord($q[1]) & 0x7F) << 14) | ((ord($q[2]) & 0x7F) << 7) | (ord($q[3]) & 0x7F)) <= $n - $p - 10;
                    };
                    // ★ (2026-11-12 16:39, 100차 RGR19-1) 비표준 v2.4(일반 정수 크기) + 0 바이트가 이어지는 표지(BMP·무압축 PNG)는 syncsafe 로 건너뛴 자리가 그림 속 0 바이트라 패딩으로 보여 표지가 잘렸음(재현: 270054 → 69478바이트, 99차 AUD-1 뒤).
                    //   일반 정수 자리가 맞고 ① 크기 바이트에 0x80 이상이 있거나(syncsafe 로는 나올 수 없는 값) ② syncsafe 자리는 0 바이트인데 거기서 일반 정수 자리(+4)까지 0 아닌 바이트가 있으면(진짜 패딩이면 끝까지 0) 일반 정수를 씀 — 표준 v2.4 는 두 경우가 생기지 않음
                    $ssAt = $offset + 10 + $ssSize; $plEnd = min($offset + 14 + $frameSize, $tagDataLen);
                    $plKeep = $ssSize !== $frameSize && $okNext($tagData, $offset + 10 + $frameSize) && ((((ord($sb[0]) | ord($sb[1]) | ord($sb[2]) | ord($sb[3])) & 0x80) !== 0) || ($ssAt < $tagDataLen && strspn($tagData, "\x00", $ssAt, 4) === min(4, $tagDataLen - $ssAt) && $plEnd > $ssAt && strspn($tagData, "\x00", $ssAt, $plEnd - $ssAt) !== $plEnd - $ssAt));
                    if ($ssSize !== $frameSize && !$plKeep && ($okNext($tagData, $offset + 10 + $ssSize) || !$okNext($tagData, $offset + 10 + $frameSize))) $frameSize = $ssSize;   // ★ (2026-11-12 16:39, 100차 RGR19-1) !$plKeep 추가(위 주석)
                }
                // ID3v2.4의 syncsafe 크기 처리 (대부분 2.3이라 일반 정수가 맞음, 폴백)
                if ($frameSize > $tagDataLen - $offset || $frameSize < 0) {
                    // syncsafe로 재시도
                    $sb = substr($tagData, $offset + 4, 4);
                    $frameSize = ((ord($sb[0]) & 0x7F) << 21) | ((ord($sb[1]) & 0x7F) << 14)
                               | ((ord($sb[2]) & 0x7F) << 7)  | (ord($sb[3]) & 0x7F);
                    if ($frameSize > $tagDataLen - $offset || $frameSize <= 0) {
                        break;
                    }
                }
                
                if ($frameId === 'APIC') {
                    // APIC 본문: encoding(1) + mime(null term) + picture_type(1) + description(null term) + image
                    $frameBody = substr($tagData, $offset + 10, $frameSize);
                    if (strlen($frameBody) < 4) { $offset += 10 + $frameSize; continue; }
                    
                    $textEncoding = ord($frameBody[0]);
                    
                    // MIME (null-terminated, ASCII)
                    $mimeEnd = strpos($frameBody, "\x00", 1);
                    if ($mimeEnd === false || $mimeEnd === 1) {
                        $offset += 10 + $frameSize; continue;
                    }
                    $mime = substr($frameBody, 1, $mimeEnd - 1);
                    
                    // MIME 정규화 (image/ 접두어 보장)
                    if (strpos($mime, '/') === false) {
                        $mime = 'image/' . strtolower($mime);
                    }
                    if (!preg_match('/^image\/(jpe?g|pjpeg|png|x-png|gif|webp|bmp|x-ms-bmp|avif|heic|heif)$/i', $mime)) {   // ★ (2026-10-30 01:54, 26차 점검) 위와 같음(SVG 등 제외)
                        $offset += 10 + $frameSize; continue;
                    }
                    
                    // picture_type 1바이트
                    $pos = $mimeEnd + 1 + 1;
                    
                    // description (null-terminated, encoding에 따라 1 또는 2바이트 단위)
                    if ($textEncoding === 1 || $textEncoding === 2) {
                        // UTF-16 BOM 또는 UTF-16BE: 2바이트 단위 null
                        $descEnd = $pos;
                        while ($descEnd + 1 < strlen($frameBody)) {
                            if ($frameBody[$descEnd] === "\x00" && $frameBody[$descEnd + 1] === "\x00") {
                                $pos = $descEnd + 2;
                                break;
                            }
                            $descEnd += 2;
                        }
                        if ($descEnd + 1 >= strlen($frameBody)) {
                            $offset += 10 + $frameSize; continue;
                        }
                    } else {
                        // ISO-8859-1 또는 UTF-8: 1바이트 단위 null
                        $descEnd = strpos($frameBody, "\x00", $pos);
                        if ($descEnd === false) { $offset += 10 + $frameSize; continue; }
                        $pos = $descEnd + 1;
                    }
                    
                    $imageData = substr($frameBody, $pos);
                    if (strlen($imageData) < 100) { // 너무 작으면 무효
                        $offset += 10 + $frameSize; continue;
                    }
                    
                    return ['mime' => $mime, 'data' => $imageData];
                }
                
                $offset += 10 + $frameSize;
            }
            
            return null;
        } finally {
            @fclose($fp);
        }
    }
    
    /**
     * ★ (2026-11-02 08:58, 41차 점검) ID3 가사(USLT/SYLT) 인코딩 0·3 바이트를 UTF-8 로.
     *   종전: 인코딩 3(UTF-8)은 검사 없이 그대로 → 옛 태거의 CP949·잘린 바이트면 json_encode 가 false 라 본문 빈 200(30일 immutable 캐시)이었고,
     *   인코딩 0 은 무조건 ISO-8859-1 → 한국 옛 MP3 의 CP949 가사가 '»ç¶ûÇØ' 로 깨졌음.
     *   지금: 0 은 0x80 이상 바이트가 있고 CP949 로 유효하며 결과에 한글 음절이 있을 때만 CP949(라틴-1 가사는 종전대로),
     *   3 은 UTF-8 이 아니면 같은 조건으로 CP949, 아니면 깨진 바이트만 대체 문자로.
     */
    private static function id3LegacyText(string $bytes, int $enc): string {
        $cp = static function (string $b): ?string {
            // ★ (2026-11-02 10:15, 42차 점검) 정규식 반복을 '*+'(소유)로 — '*' 는 PCRE JIT 스택 한계로 약 8~16KB 넘는 가사에서 false 가 나 CP949 가사가 라틴-1 로 깨졌음(검토 재현)
            // ★ (2026-11-02 09:44, 41차 재검토) KS X 1001 완성형 바이트 쌍으로만 된 글일 때만 CP949 — 확장 영역까지 받아 'Ärger' 가 '훣ger'(한글 음절이라 아래 검사도 통과)로 바뀌었음(검토 재현)
            if (!preg_match('/[\x80-\xFF]/', $b) || !preg_match('/^(?:[\x00-\x7F]|[\xA1-\xFE][\xA1-\xFE])*+$/', $b) || !@mb_check_encoding($b, 'CP949')) return null;
            $cv = @mb_convert_encoding($b, 'UTF-8', 'CP949');
            return (is_string($cv) && preg_match('/[\x{AC00}-\x{D7A3}]/u', $cv)) ? $cv : null;
        };
        if ($enc === 3) {
            if (mb_check_encoding($bytes, 'UTF-8')) return $bytes;
            return $cp($bytes) ?? (string)mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
        }
        return $cp($bytes) ?? (string)@mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
    }
    
    /**
     * MP3 파일의 ID3v2 USLT 프레임에서 정적 가사 추출
     * 
     * 1. ID3v2 헤더 검사
     * 2. USLT (Unsynchronized Lyrics) 프레임 검색
     * 3. 인코딩에 맞춰 가사 본문 디코드 (UTF-16 BOM, UTF-16BE, UTF-8, ISO-8859-1)
     * 
     * 반환: ['language' => 'kor'/'eng'/..., 'text' => '가사 본문'] 또는 null
     */
    private function extractID3v2Lyrics(string $file): ?array {
        $fp = @fopen($file, 'rb');
        if (!$fp) return null;
        
        try {
            // ID3v2 헤더 (10바이트)
            $header = fread($fp, 10);
            if (strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') {
                return null;
            }
            
            // syncsafe integer
            $sizeBytes = substr($header, 6, 4);
            $tagSize = 0;
            for ($i = 0; $i < 4; $i++) {
                $tagSize = ($tagSize << 7) | (ord($sizeBytes[$i]) & 0x7F);
            }
            
            if ($tagSize <= 0 || $tagSize > 10 * 1024 * 1024) {
                return null;
            }
            
            $tagData = fread($fp, $tagSize);
            
            // USLT 프레임 검색
            $offset = 0;
            $tagDataLen = strlen($tagData);
            
            while ($offset + 10 <= $tagDataLen) {
                $frameId = substr($tagData, $offset, 4);
                
                // 프레임 ID는 [A-Z0-9]{4} 패턴 — 패딩이면 종료
                if (!preg_match('/^[A-Z0-9]{4}$/', $frameId)) {
                    break;
                }
                
                // 프레임 크기 (4바이트, 빅엔디안 — ID3v2.3는 정수, v2.4는 syncsafe)
                $frameSizeBytes = substr($tagData, $offset + 4, 4);
                $frameSize = 0;
                $majorVersion = ord($header[3]);
                if ($majorVersion >= 4) {
                    // ID3v2.4: syncsafe
                    for ($i = 0; $i < 4; $i++) {
                        $frameSize = ($frameSize << 7) | (ord($frameSizeBytes[$i]) & 0x7F);
                    }
                } else {
                    // ID3v2.3: 일반 정수
                    $frameSize = (ord($frameSizeBytes[0]) << 24)
                               | (ord($frameSizeBytes[1]) << 16)
                               | (ord($frameSizeBytes[2]) << 8)
                               |  ord($frameSizeBytes[3]);
                }
                
                if ($frameSize <= 0 || $offset + 10 + $frameSize > $tagDataLen) {
                    break;
                }
                
                if ($frameId === 'USLT') {
                    // USLT 프레임 본문
                    $frameBody = substr($tagData, $offset + 10, $frameSize);
                    if (strlen($frameBody) < 5) {
                        $offset += 10 + $frameSize; continue;
                    }
                    
                    $textEncoding = ord($frameBody[0]);
                    $language = substr($frameBody, 1, 3);
                    
                    // description (null-terminated, encoding에 따라 1 또는 2바이트 단위)
                    $pos = 4;
                    $descEnd = -1;
                    if ($textEncoding === 1 || $textEncoding === 2) {
                        // UTF-16 BOM 또는 UTF-16BE: 2바이트 단위 null
                        $descEnd = $pos;
                        while ($descEnd + 1 < strlen($frameBody)) {
                            if ($frameBody[$descEnd] === "\x00" && $frameBody[$descEnd + 1] === "\x00") {
                                break;
                            }
                            $descEnd += 2;
                        }
                        if ($descEnd + 1 >= strlen($frameBody)) {
                            $offset += 10 + $frameSize; continue;
                        }
                        $pos = $descEnd + 2;
                    } else {
                        // ISO-8859-1 또는 UTF-8: 1바이트 단위 null
                        $descEnd = strpos($frameBody, "\x00", $pos);
                        if ($descEnd === false) { $offset += 10 + $frameSize; continue; }
                        $pos = $descEnd + 1;
                    }
                    
                    // 가사 본문 추출
                    $lyricsBytes = substr($frameBody, $pos);
                    if (strlen($lyricsBytes) < 1) {
                        $offset += 10 + $frameSize; continue;
                    }
                    
                    // 인코딩별 디코드
                    $lyricsText = '';
                    if ($textEncoding === 0) {
                        // ISO-8859-1 — ★ (2026-11-02 08:58, 41차 점검) 옛 MP3 의 CP949 는 살림(id3LegacyText)
                        $lyricsText = self::id3LegacyText($lyricsBytes, 0);
                    } elseif ($textEncoding === 1) {
                        // UTF-16 with BOM
                        if (strlen($lyricsBytes) >= 2) {
                            $bom = substr($lyricsBytes, 0, 2);
                            if ($bom === "\xFF\xFE") {
                                $lyricsText = @mb_convert_encoding(substr($lyricsBytes, 2), 'UTF-8', 'UTF-16LE');
                            } elseif ($bom === "\xFE\xFF") {
                                $lyricsText = @mb_convert_encoding(substr($lyricsBytes, 2), 'UTF-8', 'UTF-16BE');
                            } else {
                                // BOM 없으면 UTF-16LE 가정
                                $lyricsText = @mb_convert_encoding($lyricsBytes, 'UTF-8', 'UTF-16LE');
                            }
                        }
                    } elseif ($textEncoding === 2) {
                        // UTF-16BE
                        $lyricsText = @mb_convert_encoding($lyricsBytes, 'UTF-8', 'UTF-16BE');
                    } elseif ($textEncoding === 3) {
                        // UTF-8 — ★ (2026-11-02 08:58, 41차 점검) 검사 없이 쓰면 CP949·잘린 바이트에서 JSON 응답이 빈 본문이 됐음(id3LegacyText)
                        $lyricsText = self::id3LegacyText($lyricsBytes, 3);
                    }
                    
                    // null 바이트 제거 + trim
                    $lyricsText = rtrim(str_replace("\x00", '', $lyricsText));
                    
                    if (strlen($lyricsText) > 0) {
                        return ['language' => trim($language), 'text' => $lyricsText];
                    }
                }
                
                $offset += 10 + $frameSize;
            }
            
            return null;
        } finally {
            @fclose($fp);
        }
    }
    
    /**
     * 🌐 [공유 스트리밍] MP3 파일의 ID3v2 SYLT 프레임 추출 (시간 동기화 가사)
     * FileManager::extractID3v2SyncedLyrics 동일 로직 — 결합도 낮춤
     * 
     * 반환: ['text' => 'LRC 형식 변환된 가사', 'language' => 'kor'] 또는 null
     */
    private function extractID3v2SyncedLyrics(string $file): ?array {
        $fp = @fopen($file, 'rb');
        if (!$fp) return null;
        
        try {
            $header = fread($fp, 10);
            if (strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') return null;
            
            $sizeBytes = substr($header, 6, 4);
            $tagSize = 0;
            for ($i = 0; $i < 4; $i++) {
                $tagSize = ($tagSize << 7) | (ord($sizeBytes[$i]) & 0x7F);
            }
            if ($tagSize <= 0 || $tagSize > 10 * 1024 * 1024) return null;
            
            $tagData = fread($fp, $tagSize);
            $offset = 0;
            $tagDataLen = strlen($tagData);
            $majorVersion = ord($header[3]);
            
            while ($offset + 10 <= $tagDataLen) {
                $frameId = substr($tagData, $offset, 4);
                if (!preg_match('/^[A-Z0-9]{4}$/', $frameId)) break;
                
                $frameSizeBytes = substr($tagData, $offset + 4, 4);
                $frameSize = 0;
                if ($majorVersion >= 4) {
                    for ($i = 0; $i < 4; $i++) {
                        $frameSize = ($frameSize << 7) | (ord($frameSizeBytes[$i]) & 0x7F);
                    }
                } else {
                    $frameSize = (ord($frameSizeBytes[0]) << 24)
                               | (ord($frameSizeBytes[1]) << 16)
                               | (ord($frameSizeBytes[2]) << 8)
                               |  ord($frameSizeBytes[3]);
                }
                if ($frameSize <= 0 || $offset + 10 + $frameSize > $tagDataLen) break;
                
                if ($frameId === 'SYLT') {
                    $frameBody = substr($tagData, $offset + 10, $frameSize);
                    if (strlen($frameBody) < 6) { $offset += 10 + $frameSize; continue; }
                    
                    $textEncoding = ord($frameBody[0]);
                    $language = substr($frameBody, 1, 3);
                    $timeFormat = ord($frameBody[4]);
                    $contentType = ord($frameBody[5]);
                    
                    if ($contentType !== 0x01) { $offset += 10 + $frameSize; continue; }
                    
                    $pos = 6;
                    if ($textEncoding === 1 || $textEncoding === 2) {
                        $descEnd = $pos;
                        while ($descEnd + 1 < strlen($frameBody)) {
                            if ($frameBody[$descEnd] === "\x00" && $frameBody[$descEnd + 1] === "\x00") break;
                            $descEnd += 2;
                        }
                        if ($descEnd + 1 >= strlen($frameBody)) { $offset += 10 + $frameSize; continue; }
                        $pos = $descEnd + 2;
                    } else {
                        $descEnd = strpos($frameBody, "\x00", $pos);
                        if ($descEnd === false) { $offset += 10 + $frameSize; continue; }
                        $pos = $descEnd + 1;
                    }
                    
                    $lrcLines = [];
                    while ($pos + 4 < strlen($frameBody)) {
                        $textEnd = $pos;
                        if ($textEncoding === 1 || $textEncoding === 2) {
                            while ($textEnd + 1 < strlen($frameBody)) {
                                if ($frameBody[$textEnd] === "\x00" && $frameBody[$textEnd + 1] === "\x00") break;
                                $textEnd += 2;
                            }
                            if ($textEnd + 1 >= strlen($frameBody)) break;
                            $textBytes = substr($frameBody, $pos, $textEnd - $pos);
                            $pos = $textEnd + 2;
                        } else {
                            $textEnd = strpos($frameBody, "\x00", $pos);
                            if ($textEnd === false) break;
                            $textBytes = substr($frameBody, $pos, $textEnd - $pos);
                            $pos = $textEnd + 1;
                        }
                        
                        if ($pos + 4 > strlen($frameBody)) break;
                        $timestamp = (ord($frameBody[$pos]) << 24)
                                   | (ord($frameBody[$pos + 1]) << 16)
                                   | (ord($frameBody[$pos + 2]) << 8)
                                   |  ord($frameBody[$pos + 3]);
                        $pos += 4;
                        
                        $lyricText = '';
                        if ($textEncoding === 0) {
                            $lyricText = self::id3LegacyText($textBytes, 0);   // ★ (2026-11-02 08:58, 41차 점검) USLT 와 같은 처리(CP949 살림)
                        } elseif ($textEncoding === 1) {
                            if (strlen($textBytes) >= 2) {
                                $bom = substr($textBytes, 0, 2);
                                if ($bom === "\xFF\xFE") {
                                    $lyricText = @mb_convert_encoding(substr($textBytes, 2), 'UTF-8', 'UTF-16LE');
                                } elseif ($bom === "\xFE\xFF") {
                                    $lyricText = @mb_convert_encoding(substr($textBytes, 2), 'UTF-8', 'UTF-16BE');
                                } else {
                                    $lyricText = @mb_convert_encoding($textBytes, 'UTF-8', 'UTF-16');
                                }
                            }
                        } elseif ($textEncoding === 2) {
                            $lyricText = @mb_convert_encoding($textBytes, 'UTF-8', 'UTF-16BE');
                        } elseif ($textEncoding === 3) {
                            $lyricText = self::id3LegacyText($textBytes, 3);   // ★ (2026-11-02 08:58, 41차 점검) UTF-8 검사(USLT 와 같은 처리)
                        }
                        
                        if ($lyricText === false || $lyricText === null) continue;
                        $lyricText = trim($lyricText);
                        if ($lyricText === '') continue;
                        
                        $totalSec = ($timeFormat === 0x02) ? $timestamp / 1000.0 : $timestamp / 1000.0;
                        if ($totalSec < 0 || $totalSec > 36000) continue;
                        
                        $min = floor($totalSec / 60);
                        $sec = floor($totalSec - $min * 60);
                        $cs = floor(($totalSec - floor($totalSec)) * 100);
                        $timeTag = sprintf('[%02d:%02d.%02d]', $min, $sec, $cs);
                        
                        $lrcLines[] = $timeTag . $lyricText;
                    }
                    
                    if (count($lrcLines) === 0) { $offset += 10 + $frameSize; continue; }
                    
                    return [
                        'text' => implode("\n", $lrcLines),
                        'language' => trim($language) ?: null,
                    ];
                }
                
                $offset += 10 + $frameSize;
            }
            
            return null;
        } finally {
            @fclose($fp);
        }
    }
    
    /**
     * ★ (2026-11-02 09:00, 41차 점검) 가사 짝 파일(같은이름.lrc/.txt) 경로.
     *   종전엔 '$dir/$baseName.lrc' 의 file_exists 만 봐서, 맥에서 온 NFD 이름 음악 옆의 NFC 이름 가사(또는 반대)를 못 찾았음.
     *   그대로 있으면 종전 경로를 그대로 돌려주고, 없을 때만(이름에 비ASCII 가 있고 intl Normalizer 가 있을 때) 폴더에서 NFC 로 같은 이름을 찾음 — 비교만, 파일 이름은 그대로.
     */
    private static function lyricsPairPath(string $dir, string $baseName, string $ext): string {
        $exact = $dir . DIRECTORY_SEPARATOR . $baseName . '.' . $ext;
        if (file_exists($exact) || !class_exists('Normalizer') || !preg_match('/[\x80-\xFF]/', $baseName)) return $exact;
        $want = \Normalizer::normalize($baseName . '.' . $ext, \Normalizer::FORM_C);
        if (!is_string($want)) return $exact;
        foreach ((@scandir($dir) ?: []) as $f) {
            if ($f === '.' || $f === '..' || !preg_match('/[\x80-\xFF]/', $f)) continue;
            $n = \Normalizer::normalize($f, \Normalizer::FORM_C);
            if (is_string($n) && $n === $want) return $dir . DIRECTORY_SEPARATOR . $f;
        }
        return $exact;
    }
    
    /**
     * ============================================================================
     * 🌐 [공유 스트리밍] MP3 파일의 가사 추출 (LRC 파일 → SYLT → USLT → TXT 우선순위)
     * ============================================================================
     * 
     * ⚠️ 주의: 이 메서드는 *공유 페이지(share.php) 전용*입니다.
     * 
     * - 호출 위치: share.php?t=토큰&lyrics=1 (공유 스트리밍 모드)
     * - 짝 메서드: FileManager::getAudioLyrics() (메인 페이지 전용)
     *   메인 페이지 가사는 api.php?action=audio_lyrics → FileManager::getAudioLyrics
     * 
     * 반환: ['source' => 'lrc'|'uslt'|'txt', 'text' => '가사', 'synced' => bool] 또는 null
     */
    public function getShareLyrics(string $token, ?string $password = null, ?string $subFile = null): ?array {
        // 폴더 공유 + sub-path 모드
        if ($subFile !== null && $subFile !== '') {
            // sub-path 보안 검증
            $fullPath = $this->validateSharedFolderSubPath($token, $password, $subFile);
            if ($fullPath === null) return null;
            
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $audioExts = ['mp3', 'm4a', 'flac', 'ogg', 'wav', 'aac', 'opus', 'ape', 'alac', 'aiff'];
            if (!in_array($ext, $audioExts)) return null;
            
            // 폴더 안 가사 검색 (LRC > USLT > TXT)
            $dir = dirname($fullPath);
            $baseName = pathinfo($fullPath, PATHINFO_FILENAME);
            
            // 1. LRC
            $lrcPath = self::lyricsPairPath($dir, $baseName, 'lrc');   // ★ (2026-11-02 09:00, 41차 점검) NFC/NFD 이름 차이도 짝으로(lyricsPairPath)
            if (file_exists($lrcPath) && is_readable($lrcPath)) {
                $lrcSize = @filesize($lrcPath);
                if ($lrcSize > 0 && $lrcSize < 1 * 1024 * 1024) {
                    $lrcText = @file_get_contents($lrcPath);
                    if ($lrcText !== false) {
                        if (substr($lrcText, 0, 3) === "\xEF\xBB\xBF") $lrcText = substr($lrcText, 3);
                        if (!mb_check_encoding($lrcText, 'UTF-8')) {
                            $lrcText = @mb_convert_encoding($lrcText, 'UTF-8', 'CP949,EUC-KR,UTF-16LE,UTF-16BE,ISO-8859-1');
                        }
                        if ($lrcText !== false && strlen(trim($lrcText)) > 0) {
                            return ['source' => 'lrc', 'text' => $lrcText, 'synced' => true];
                        }
                    }
                }
            }
            
            // 2. SYLT (mp3만, 시간 동기화 가사)
            if ($ext === 'mp3') {
                $sylt = $this->extractID3v2SyncedLyrics($fullPath);
                if ($sylt && strlen(trim($sylt['text'])) > 0) {
                    return ['source' => 'sylt', 'text' => $sylt['text'], 'synced' => true, 'language' => $sylt['language']];
                }
            }
            
            // 3. USLT (mp3만, 정적 가사)
            if ($ext === 'mp3') {
                $uslt = $this->extractID3v2Lyrics($fullPath);
                if ($uslt && strlen(trim($uslt['text'])) > 0) {
                    return ['source' => 'uslt', 'text' => $uslt['text'], 'synced' => false, 'language' => $uslt['language']];
                }
            }
            
            // 4. TXT
            $txtPath = self::lyricsPairPath($dir, $baseName, 'txt');   // ★ (2026-11-02 09:00, 41차 점검) NFC/NFD 이름 차이도 짝으로(lyricsPairPath)
            if (file_exists($txtPath) && is_readable($txtPath)) {
                $txtSize = @filesize($txtPath);
                if ($txtSize > 0 && $txtSize < 1 * 1024 * 1024) {
                    $txtText = @file_get_contents($txtPath);
                    if ($txtText !== false) {
                        if (substr($txtText, 0, 3) === "\xEF\xBB\xBF") $txtText = substr($txtText, 3);
                        if (!mb_check_encoding($txtText, 'UTF-8')) {
                            $txtText = @mb_convert_encoding($txtText, 'UTF-8', 'CP949,EUC-KR,UTF-16LE,UTF-16BE,ISO-8859-1');
                        }
                        if ($txtText !== false && strlen(trim($txtText)) > 0) {
                            $synced = preg_match('/^\s*\[\d{1,2}:\d{2}(?:[.:]\d{1,3})?\]/m', $txtText) === 1;
                            return ['source' => 'txt', 'text' => $txtText, 'synced' => $synced];
                        }
                    }
                }
            }
            
            return null;
        }
        
        // 단일 파일 공유 (기존 로직)
        $access = $this->accessShare($token, $password);
        if (!$access['success']) return null;
        if ($access['share']['is_dir']) return null;
        // ★ (2026-11-01 01:09, 35차 전체 점검) 재생(stream) 공유만 — 화면도 stream 공유에서만 플레이어·가사를 씀(share.php $isStreamable).
        //   종전엔 download 공유에서도 같은 폴더의 '같은이름.lrc/.txt'(공유 안 한 파일) 내용을 돌려줬음. 폴더 경로(validateSharedFolderSubPath)와 같은 기준
        if (($access['share']['share_type'] ?? '') !== 'stream') return null;
        
        $filename = $access['share']['filename'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // 오디오만 (mp3/m4a/flac 등 ID3 가능)
        $audioExts = ['mp3', 'm4a', 'flac', 'ogg', 'wav', 'aac', 'opus', 'ape', 'alac', 'aiff'];
        if (!in_array($ext, $audioExts)) return null;
        
        // 실제 파일 경로 계산 (accessShare 내부와 동일 로직)
        $share = $this->db->find('shares', ['token' => $token, 'is_active' => 1]);
        if (!$share) return null;
        
        $basePath = $this->storage->getRealPath($share['storage_id']);
        if (!$basePath) return null;
        
        $fullPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim($share['file_path'], '/\\');
        if (!file_exists($fullPath) || !is_readable($fullPath)) return null;
        
        // 1. 같은 폴더의 LRC 파일 (정확 매칭)
        $dir = dirname($fullPath);
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $lrcPath = self::lyricsPairPath($dir, $baseName, 'lrc');   // ★ (2026-11-02 09:00, 41차 점검) NFC/NFD 이름 차이도 짝으로(lyricsPairPath)
        if (file_exists($lrcPath) && is_readable($lrcPath)) {
            $lrcSize = @filesize($lrcPath);
            if ($lrcSize > 0 && $lrcSize < 1 * 1024 * 1024) { // 최대 1MB
                $lrcText = @file_get_contents($lrcPath);
                if ($lrcText !== false) {
                    // BOM 제거
                    if (substr($lrcText, 0, 3) === "\xEF\xBB\xBF") {
                        $lrcText = substr($lrcText, 3);
                    }
                    // 인코딩 변환 (UTF-8이 아니면 시도)
                    if (!mb_check_encoding($lrcText, 'UTF-8')) {
                        $lrcText = @mb_convert_encoding($lrcText, 'UTF-8', 'CP949,EUC-KR,UTF-16LE,UTF-16BE,ISO-8859-1');
                    }
                    if ($lrcText !== false && strlen(trim($lrcText)) > 0) {
                        return ['source' => 'lrc', 'text' => $lrcText, 'synced' => true];
                    }
                }
            }
        }
        
        // 2. ID3v2 SYLT 임베드 가사 (mp3만, 시간 동기화)
        if ($ext === 'mp3') {
            $sylt = $this->extractID3v2SyncedLyrics($fullPath);
            if ($sylt && strlen(trim($sylt['text'])) > 0) {
                return ['source' => 'sylt', 'text' => $sylt['text'], 'synced' => true, 'language' => $sylt['language']];
            }
        }
        
        // 3. ID3v2 USLT 임베드 가사 (mp3만, 정적)
        if ($ext === 'mp3') {
            $uslt = $this->extractID3v2Lyrics($fullPath);
            if ($uslt && strlen(trim($uslt['text'])) > 0) {
                return ['source' => 'uslt', 'text' => $uslt['text'], 'synced' => false, 'language' => $uslt['language']];
            }
        }
        
        // 4. 같은 폴더의 TXT 파일 (정확 매칭)
        $txtPath = self::lyricsPairPath($dir, $baseName, 'txt');   // ★ (2026-11-02 09:00, 41차 점검) NFC/NFD 이름 차이도 짝으로(lyricsPairPath)
        if (file_exists($txtPath) && is_readable($txtPath)) {
            $txtSize = @filesize($txtPath);
            if ($txtSize > 0 && $txtSize < 1 * 1024 * 1024) {
                $txtText = @file_get_contents($txtPath);
                if ($txtText !== false) {
                    if (substr($txtText, 0, 3) === "\xEF\xBB\xBF") {
                        $txtText = substr($txtText, 3);
                    }
                    if (!mb_check_encoding($txtText, 'UTF-8')) {
                        $txtText = @mb_convert_encoding($txtText, 'UTF-8', 'CP949,EUC-KR,UTF-16LE,UTF-16BE,ISO-8859-1');
                    }
                    if ($txtText !== false && strlen(trim($txtText)) > 0) {
                        // TXT 안에 LRC 시간 태그가 있으면 LRC로 처리
                        $synced = preg_match('/^\s*\[\d{1,2}:\d{2}(?:[.:]\d{1,3})?\]/m', $txtText) === 1;
                        return ['source' => 'txt', 'text' => $txtText, 'synced' => $synced];
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * 공유 폴더 안 음악 트랙 목록 가져오기 (직속 파일만)
     * 
     * 보안:
     *  - 폴더 공유 + stream 타입만 처리
     *  - 직속 파일만 (재귀 X) → path traversal 위험 최소화
     *  - 음악 확장자 화이트리스트 (mp3/wav/flac/m4a/aac/ogg/opus/wma/ape/alac/aiff)
     *  - basename + realpath 이중 검증 (sub-path 공격 방어)
     *  - 숨김 파일 제외 (.htaccess 등)
     * 
     * 반환: ['file' => 'song.mp3', 'name' => 'song', 'ext' => 'mp3', 'size' => 123456], ...
     *       또는 빈 배열 (음악 파일 없음)
     */
    public function getSharedFolderTracks(string $token, ?string $password = null): ?array {
        // ★ 디버그 로그 (펜닐님 동영상 폴더 stream 진단용 — 비활성화: 함수 본체만 주석, 호출은 no-op)
        //   재활성 시 아래 본체 주석 해제하면 즉시 작동
        $_gsftLog = function($msg) {
            /*
            $_logDir = __DIR__ . '/../data/debug';
            if (!is_dir($_logDir)) @mkdir($_logDir, 0755, true);
            @file_put_contents(
                $_logDir . '/folder_stream_debug.log',
                '[' . date('Y-m-d H:i:s') . '] [GSF-TRACKS] ' . $msg . "\n",
                FILE_APPEND
            );
            */
        };
        $_gsftLog('=== getSharedFolderTracks 시작 ===');
        $_gsftLog('token: ' . substr($token, 0, 16) . '...');
        
        // accessShare 검증 (만료/비밀번호/세션 인증 일관)
        $access = $this->accessShare($token, $password);
        $_gsftLog('accessShare result: ' . ($access['success'] ? 'success' : 'FAIL - ' . ($access['error'] ?? '')));
        if (!$access['success']) return null;
        
        // 폴더 공유만
        $_gsftLog('share is_dir: ' . ($access['share']['is_dir'] ? 'TRUE' : 'FALSE'));
        if (!$access['share']['is_dir']) {
            $_gsftLog('FAIL: not a directory share');
            return null;
        }
        
        // stream 타입만 (download 폴더 공유는 ZIP 다운로드 그대로)
        $_gsftLog('share_type: ' . ($access['share']['share_type'] ?? 'NULL'));
        if (($access['share']['share_type'] ?? '') !== 'stream') {
            $_gsftLog('FAIL: share_type is not stream');
            return null;
        }
        
        // 실제 폴더 경로 계산
        $share = $this->db->find('shares', ['token' => $token, 'is_active' => 1]);
        if (!$share) {
            $_gsftLog('FAIL: share record not in DB');
            return null;
        }
        $_gsftLog('share record found - storage_id: ' . $share['storage_id'] . ', file_path: ' . $share['file_path']);
        
        $basePath = $this->storage->getRealPath($share['storage_id']);
        $_gsftLog('basePath: ' . ($basePath ?: 'NULL'));
        if (!$basePath) {
            $_gsftLog('FAIL: basePath empty');
            return null;
        }
        
        $folderPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
        $_gsftLog('folderPath: ' . $folderPath);
        $_gsftLog('is_dir($folderPath): ' . (is_dir($folderPath) ? 'TRUE' : 'FALSE'));
        if (!is_dir($folderPath)) {
            $_gsftLog('FAIL: folder does not exist');
            return null;
        }
        
        // 보안: realpath 검증 (스토리지 외부 차단)
        $realFolder = realpath($folderPath);
        $realBase = realpath($basePath);
        $_gsftLog('realFolder: ' . ($realFolder ?: 'FALSE'));
        $_gsftLog('realBase: ' . ($realBase ?: 'FALSE'));
        if ($realFolder === false || $realBase === false) {
            $_gsftLog('FAIL: realpath returned false');
            return null;
        }
        if (strpos($realFolder, $realBase) !== 0) {
            $_gsftLog('FAIL: realFolder NOT inside realBase (escape attempt?)');
            return null;
        }
        
        // ★ 펜닐님 결정 (옵션 D): PHP 파일 캐시 — 폴더 mtime + TTL 30s 기반
        //   같은 token에 대한 cover/lyrics 등 다중 요청에서 디렉토리 스캔 1회로 줄임
        //   - 캐시 키: token MD5 (보안: 토큰 자체 노출 X)
        //   - 무효화: 폴더 mtime 변경 시 (파일 추가/삭제) + TTL 30s (내용 수정 커버)
        $_cacheDir = __DIR__ . '/../data/cache/folder_tracks';
        $_cacheKey = md5($token);
        $_cacheFile = $_cacheDir . '/' . $_cacheKey . '.json';
        $_folderMtime = @filemtime($realFolder) ?: 0;
        $_now = time();
        if (is_file($_cacheFile)) {
            $_cacheAge = $_now - (@filemtime($_cacheFile) ?: 0);
            if ($_cacheAge < 30) {
                $_cacheData = @file_get_contents($_cacheFile);
                if ($_cacheData !== false) {
                    $_cached = @json_decode($_cacheData, true);
                    if (is_array($_cached) && isset($_cached['folder_mtime'], $_cached['tracks'])) {
                        // 폴더 mtime 일치 시 캐시 적중 (파일 추가/삭제 안 됨)
                        if ((int)$_cached['folder_mtime'] === $_folderMtime) {
                            $_gsftLog('CACHE HIT - returning cached tracks (count=' . count($_cached['tracks']) . ', age=' . $_cacheAge . 's)');
                            return $_cached['tracks'];
                        }
                    }
                }
            }
        }
        
        // 미디어 확장자 화이트리스트 (audio + video 통합)
        $audioExts = ['mp3', 'm4a', 'm4b', 'aac', 'ogg', 'oga', 'opus', 'flac', 'wav', 'weba'];   // ★ (2026-10-08) 음악은 웹 플레이어가 여는 형식만(펜닐 결정 — wma·ape·alac·aiff 는 브라우저가 재생 못 함)
        $videoExts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'wmv', 'flv', 'ts', 'm2ts', 'mts', 'mpg', 'mpeg', 'm4v', '3gp'];
        // 주의: 'ogg'는 video share.php에도 있으나 audio로 우선 처리 (대부분 ogg vorbis 음악)
        
        $tracks = [];
        $items = @scandir($realFolder);
        $_gsftLog('scandir count: ' . ($items === false ? 'FALSE' : count($items)));
        if ($items === false) return [];
        
        $skippedReasons = ['hidden' => 0, 'not_file' => 0, 'is_link' => 0, 'wrong_ext' => 0];
        $allExts = [];
        
        foreach ($items as $item) {
            // 숨김 파일/시스템 파일 제외
            if ($item === '.' || $item === '..') { $skippedReasons['hidden']++; continue; }
            if (substr($item, 0, 1) === '.') { $skippedReasons['hidden']++; continue; }
            // ★ (2026-10-28) 시스템 파일·~$ 임시·속성에서 숨긴 항목도 공유 재생 목록에서 제외
            if (class_exists('HiddenItems') && (HiddenItems::isSystemName($item)
                || HiddenItems::isUserHidden((int)$share['storage_id'], trim(HiddenItems::norm((string)$share['file_path']) . '/' . $item, '/')))) { $skippedReasons['hidden']++; continue; }
            
            $itemPath = $realFolder . DIRECTORY_SEPARATOR . $item;
            
            // 직속 파일만 (디렉토리 X, 심볼릭 링크 X)
            if (!is_file($itemPath)) { $skippedReasons['not_file']++; continue; }
            if (is_link($itemPath)) { $skippedReasons['is_link']++; continue; }
            
            // 확장자 화이트리스트 (audio 또는 video)
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            $allExts[$ext] = ($allExts[$ext] ?? 0) + 1;
            
            // type 결정: audio 우선 검사 (ogg는 audio)
            $type = null;
            if (in_array($ext, $audioExts, true)) {
                $type = 'audio';
            } elseif (in_array($ext, $videoExts, true)) {
                $type = 'video';
            }
            if ($type === null) { $skippedReasons['wrong_ext']++; continue; }
            
            $tracks[] = [
                'file' => $item,
                'name' => pathinfo($item, PATHINFO_FILENAME),
                'ext' => $ext,
                'size' => @filesize($itemPath) ?: 0,
                'mtime' => @filemtime($itemPath) ?: 0,  // ★ HTTP 캐시 무효화용 (mtime 기반 URL)
                'type' => $type,  // ★ 신규: 'audio' 또는 'video'
            ];
        }
        
        $_gsftLog('found tracks: ' . count($tracks));
        $_gsftLog('skipped: ' . json_encode($skippedReasons));
        $_gsftLog('all extensions found: ' . json_encode($allExts, JSON_UNESCAPED_UNICODE));
        if (count($tracks) > 0) {
            $_gsftLog('first 5 tracks: ' . json_encode(array_slice(array_column($tracks, 'file'), 0, 5), JSON_UNESCAPED_UNICODE));
            $audioCount = count(array_filter($tracks, fn($t) => $t['type'] === 'audio'));
            $videoCount = count(array_filter($tracks, fn($t) => $t['type'] === 'video'));
            $_gsftLog("track types: audio={$audioCount}, video={$videoCount}");
        }
        
        // 파일명 자연정렬 (트랙 1, 2, 3, ... 11, 12 순서)
        usort($tracks, function($a, $b) {
            return strnatcasecmp($a['file'], $b['file']);
        });
        
        // ★ 캐시 저장 (다음 요청 시 재사용)
        if (!is_dir($_cacheDir)) @mkdir($_cacheDir, 0755, true);
        // ★ (2026-11-02 08:57, 41차 점검) UTF-8 이 아닌 이름이 하나라도 있으면 json_encode 가 false → 빈 캐시 파일이 남아 30초 캐시가 늘 빗나갔음.
        //   INVALID_UTF8_SUBSTITUTE 추가(깨진 이름은 '�' 로 저장 — 화면도 같은 값을 받아 어차피 재생 안 되고, validateSharedFolderSubPath 는 realpath 실패로 거부)
        // ★ (2026-11-02 09:45, 41차 재검토) 위 SUBSTITUTE 는 되돌림 — 캐시가 맞으면 '�' 로 바뀐 이름이 돌아와 목록 확인(validateSharedFolderSubPath)·재생 링크가 원래 바이트 이름과 안 맞아
        //   UTF-8 이 아닌 이름의 재생·이동이 막혔음(검토 발견). 인코딩이 실패하면 캐시를 쓰지 않음(종전처럼 매번 새로 읽음 — 빈 캐시 파일도 남기지 않음). 화면 출력 쪽 SUBSTITUTE(share.php)는 그대로
        $_cacheJson = json_encode([
            'folder_mtime' => $_folderMtime,
            'tracks' => $tracks,
        ], JSON_UNESCAPED_UNICODE);
        if ($_cacheJson !== false) @file_put_contents($_cacheFile, $_cacheJson, LOCK_EX);
        $_gsftLog('CACHE WRITE - tracks count=' . count($tracks));
        
        return $tracks;
    }
    
    /**
     * 공유 폴더의 sub-path 보안 검증
     * 
     * 외부에서 file= 파라미터로 받은 sub-path가:
     *  1. basename만 사용 (디렉토리 분리자 차단)
     *  2. 화이트리스트(getSharedFolderTracks)에 있는 파일인지 검증
     *  3. realpath가 폴더 안에 있는지 재확인
     * 
     * 반환: 검증 통과 시 fullPath, 실패 시 null
     */
    public function validateSharedFolderSubPath(string $token, ?string $password, string $subFile): ?string {
        // accessShare 통한 폴더 공유 검증
        $access = $this->accessShare($token, $password);
        if (!$access['success']) return null;
        if (!$access['share']['is_dir']) return null;
        if (($access['share']['share_type'] ?? '') !== 'stream') return null;
        
        // basename만 추출 (path traversal 차단: ../, /, \ 모두 제거됨)
        $cleanFile = basename($subFile);
        if ($cleanFile === '' || $cleanFile === '.' || $cleanFile === '..') return null;
        if ($cleanFile !== $subFile) return null;  // 입력에 디렉토리 분리자 있었으면 거부
        if (substr($cleanFile, 0, 1) === '.') return null;  // 숨김 파일 거부
        
        // 화이트리스트 검증 (트랙 목록에 있는 파일인지)
        $tracks = $this->getSharedFolderTracks($token, $password);
        if (!$tracks) return null;
        
        $found = false;
        foreach ($tracks as $t) {
            if ($t['file'] === $cleanFile) {
                $found = true;
                break;
            }
        }
        if (!$found) return null;
        
        // 실제 경로 + realpath 재검증
        $share = $this->db->find('shares', ['token' => $token, 'is_active' => 1]);
        if (!$share) return null;
        
        $basePath = $this->storage->getRealPath($share['storage_id']);
        if (!$basePath) return null;
        
        $folderPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
        $fullPath = $folderPath . DIRECTORY_SEPARATOR . $cleanFile;
        
        $realFull = realpath($fullPath);
        $realFolder = realpath($folderPath);
        if ($realFull === false || $realFolder === false) return null;
        if (strpos($realFull, $realFolder) !== 0) return null;  // 폴더 외부 접근 차단
        
        return $realFull;
    }
}