<?php
/**
 * ShareManager - 공유 링크 관리 (JSON 기반)
 */
class ShareManager {
    private $db;
    private $auth;
    private $storage;
    
    public function __construct() {
        $this->db = JsonDB::getInstance();
        $this->auth = new Auth();
        $this->storage = new Storage();
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
                if (!empty($share['max_downloads']) && 
                    ($share['download_count'] ?? 0) >= $share['max_downloads']) {
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
        if (!empty($options['expire_days'])) {
            $days = (float)$options['expire_days'];
            $seconds = max(60, (int)round($days * 86400)); // 최소 1분
            $expireAt = date('Y-m-d H:i:s', time() + $seconds);
        } elseif (!empty($options['expire_at'])) {
            $expireAt = $options['expire_at'];
        }
        
        $password = null;
        if (!empty($options['password'])) {
            $password = password_hash($options['password'], PASSWORD_DEFAULT);
        }
        
        $id = $this->db->insert('shares', [
            'token' => $token,
            'storage_id' => $storageId,
            'file_path' => $filePath,
            'created_by' => $this->auth->getUserId(),
            'password' => $password,
            'expire_at' => $expireAt,
            'max_downloads' => $options['max_downloads'] ?? null,
            'download_count' => 0,
            'share_type' => $options['share_type'] ?? 'download',
            'is_dir' => is_dir($fullPath) ? 1 : 0,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
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
    public function getShares(): array {
        // 만료된 공유 자동 정리
        $this->cleanupExpiredShares();
        
        $userId = $this->auth->getUserId();
        $isAdmin = $this->auth->isAdmin();
        
        $shares = $this->db->load('shares');
        $users = $this->db->load('users');
        $storages = $this->db->load('storages');
        
        $result = [];
        foreach ($shares as $share) {
            if (!$isAdmin && (int)$share['created_by'] !== (int)$userId) {
                continue;
            }
            
            // 생성자 이름 추가
            foreach ($users as $user) {
                if ((int)$user['id'] === (int)$share['created_by']) {
                    $share['creator_name'] = $user['username'];
                    break;
                }
            }
            
            // 스토리지 이름 추가
            foreach ($storages as $storage) {
                if ((int)$storage['id'] === (int)$share['storage_id']) {
                    $share['storage_name'] = $storage['name'];
                    // is_dir이 없는 기존 데이터 대응: 실제 경로로 확인
                    if (!isset($share['is_dir'])) {
                        $basePath = $this->storage->getRealPath((int)$share['storage_id']);
                        if ($basePath) {
                            $fp = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
                            $share['is_dir'] = is_dir($fp) ? 1 : 0;
                        }
                    }
                    break;
                }
            }
            
            // ★ 보안: 비번 해시는 클라이언트에 노출하지 않음 (has_password 플래그만)
            $share['has_password'] = !empty($share['password']);
            unset($share['password']);
            
            $result[] = $share;
        }
        
        // 최신순 정렬
        usort($result, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        return $result;
    }
    
    // 만료된 공유 자동 정리
    /**
     * 경로 안전성 검증 (realpath + fallback)
     * Windows에서 특수문자([], (), 공백, ... 등) 파일명에 realpath가 실패하는 경우 대비
     */
    private function isSharePathSafe(string $basePath, string $fullPath): bool {
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
    
    public function cleanupExpiredShares(): int {
        $shares = $this->db->load('shares');
        $deletedCount = 0;
        $now = time();
        
        
        foreach ($shares as $share) {
            $shouldDelete = false;
            $deleteReason = '';
            
            // 1. 만료일이 지난 경우
            if (!empty($share['expire_at']) && strtotime($share['expire_at']) < $now) {
                $shouldDelete = true;
                $deleteReason = 'expired';
            }
            
            // 2. 다운로드 횟수 초과
            if (!empty($share['max_downloads']) && 
                ($share['download_count'] ?? 0) >= $share['max_downloads']) {
                $shouldDelete = true;
                $deleteReason = 'max_downloads';
            }
            
            // 3. 실제 파일이 존재하지 않는 경우
            if (!$shouldDelete) {
                $basePath = $this->storage->getRealPath($share['storage_id']);
                if ($basePath) {
                    $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
                    
                    
                    if (!file_exists($fullPath)) {
                        $shouldDelete = true;
                        $deleteReason = 'file_not_exists';
                    }
                } else {
                    // 스토리지가 삭제된 경우
                    $shouldDelete = true;
                    $deleteReason = 'storage_deleted';
                }
            }
            
            if ($shouldDelete) {
                $this->_cleanShareCache($share);  // ★ 캐시 정리 (자동 정리 함수 — 일관성)
                $this->db->delete('shares', ['id' => $share['id']]);
                $deletedCount++;
            }
        }
        
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
                        $audioExts = ['mp3', 'wav', 'flac', 'm4a', 'aac', 'ogg', 'opus', 'wma', 'ape', 'alac', 'aiff'];
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
                            $key = md5($token . '/' . $share['file_path'] . '/' . $mtime);
                            @unlink($shareAudioDir . '/' . $key . '.img');
                            @unlink($shareAudioDir . '/' . $key . '.meta');
                            @unlink($shareAudioDir . '/' . $key . '.nocover');
                        }
                    }
                }
            }
        }
        
        // 참고: stale 캐시 파일 (파일 변경/이름변경 등으로 키가 바뀐 옛 캐시)은 남을 수 있음
        // → TTL 기반 정리는 펜닐님 결정 (옵션 C)에서 제외 → 누적은 미미한 수준이라 무시
    }
    
    // 공유 링크로 접근
    public function accessShare(string $token, ?string $password = null): array {
        $share = $this->db->find('shares', ['token' => $token, 'is_active' => 1]);
        
        if (!$share) {
            return ['success' => false, 'error' => __('api_err_share_link_not_found', '공유 링크를 찾을 수 없습니다.')];
        }
        
        $storage = $this->db->find('storages', ['id' => $share['storage_id']]);
        if (!$storage) {
            // 스토리지가 삭제된 경우 공유도 삭제
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
        }
        
        // 만료 확인
        if ($share['expire_at'] && strtotime($share['expire_at']) < time()) {
            // 만료된 공유 삭제
            $this->_cleanShareCache($share);  // ★ 캐시 정리
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => __('api_err_share_expired', '만료된 공유 링크입니다.')];
        }
        
        // 다운로드 횟수 확인
        if ($share['max_downloads'] && $share['download_count'] >= $share['max_downloads']) {
            // 횟수 초과 공유 삭제
            $this->_cleanShareCache($share);  // ★ 캐시 정리
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => __('api_err_share_download_limit', '다운로드 횟수를 초과했습니다.')];
        }
        
        // 비밀번호 확인
        if ($share['password']) {
            // 세션에서 인증 완료 여부 먼저 확인
            $sessionAuthed = !empty($_SESSION['share_authenticated'][$token]);
            if (!$password && !$sessionAuthed) {
                return ['success' => false, 'error' => 'password_required', 'needs_password' => true];
            }
            if (!$sessionAuthed && !password_verify($password, $share['password'])) {
                return ['success' => false, 'error' => __('api_err_share_password_wrong', '비밀번호가 올바르지 않습니다.')];
            }
        }
        
        // 파일 경로 확인 - Storage 클래스 사용
        $basePath = $this->storage->getRealPath($share['storage_id']);
        
        if (!$basePath) {
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => __('api_err_storage_path_not_found', '스토리지 경로를 찾을 수 없습니다.')];
        }
        
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
        
        // 경로 안전성 검증
        if (!$this->isSharePathSafe($basePath, $fullPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!file_exists($fullPath)) {
            // 파일이 삭제된 경우 공유도 삭제
            $this->db->delete('shares', ['id' => $share['id']]);
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
        $access = $this->accessShare($token, $password);
        
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
        $isHlsPlaylistOrSegment = isset($_GET['hls']) && in_array($hlsAction, ['playlist', 'segment'], true);
        
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
            $fileManager->hlsShareStream($fullPath);
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
        
        // 다운로드 횟수 증가 (스트리밍은 제외)
        if (!isset($_GET['stream'])) {
            $this->db->update('shares', ['token' => $token], [
                'download_count' => ($share['download_count'] ?? 0) + 1
            ]);
        }
        
        // 폴더인 경우 ZIP으로 압축
        if (is_dir($fullPath)) {
            $this->downloadAsZip($fullPath);
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
            $fileManager->hlsShareStream($fullPath);
            exit;
        }
        
        // 트랜스코딩 요청 (브라우저 미지원 포맷 — MMS/MSE fallback)
        if ($isStream && isset($_GET['transcode'])) {
            require_once __DIR__ . '/FileManager.php';
            $fileManager = new FileManager();
            $fileManager->transcodeShareStream($fullPath);
            exit;
        }
        
        if ($isStream) {
            // 스트리밍: 올바른 MIME + inline
            $mimeMap = [
                'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'video/ogg',
                'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska',
                'wmv' => 'video/x-ms-wmv', 'flv' => 'video/x-flv', 'ts' => 'video/mp2t',
                'm2ts' => 'video/mp2t', 'mts' => 'video/mp2t', 'mpg' => 'video/mpeg',
                'mpeg' => 'video/mpeg', 'm4v' => 'video/x-m4v', '3gp' => 'video/3gpp',
                'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'flac' => 'audio/flac',
                'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wma' => 'audio/x-ms-wma',
                'opus' => 'audio/opus'
            ];
            $mime = $mimeMap[$ext] ?? (mime_content_type($fullPath) ?: 'application/octet-stream');
            header('Content-Type: ' . $mime);
            $filenameSafe = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename);
            $filenameEncoded = rawurlencode($filename);
            header("Content-Disposition: inline; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
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
        $filenameSafe = preg_replace('/[^\x20-\x7E]|["\\]/', '_', $filename);
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
        
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
                $start = intval($matches[1]);
                if (!empty($matches[2])) {
                    $end = intval($matches[2]);
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
            header('Cache-Control: public, max-age=86400');
        } else {
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        header('X-Accel-Buffering: no');
        
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
            while ($remaining > 0 && !feof($fp)) {
                $read = min($chunkSize, $remaining);
                echo fread($fp, $read);
                $remaining -= $read;
            }
            flush();
            fclose($fp);
        }
        exit;
    }
    
    // ZIP 압축 다운로드
    private function downloadAsZip(string $dir): void {
        $zipName = basename($dir) . '.zip';
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('share_') . '.zip';
        
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
        foreach ($iterator as $file) {
            $relativePath = $baseName . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
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
        
        $zip->close();
        
        // 출력 버퍼링 완전 비활성화
        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', 'Off');
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // RFC 5987 형식으로 파일명 인코딩
        $zipNameSafe = preg_replace('/[^\x20-\x7E]|["\\]/', '_', $zipName);
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
                $days = (float)$options['expire_days'];
                $seconds = max(60, (int)round($days * 86400));
                $updateData['expire_at'] = date('Y-m-d H:i:s', time() + $seconds);
            } else {
                $updateData['expire_at'] = null;
            }
        }
        
        if (isset($options['max_downloads'])) {
            $updateData['max_downloads'] = $options['max_downloads'] ?: null;
        }
        
        if (isset($options['password'])) {
            $updateData['password'] = $options['password'] 
                ? password_hash($options['password'], PASSWORD_DEFAULT)
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
        if (!in_array($permission, ['read', 'write', 'full'])) {
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
            if (!$share['file_exists']) {
                $toDelete[] = $share['id'];
                continue;
            }
            
            $result[] = $share;
        }
        
        // 존재하지 않는 파일의 공유 레코드 자동 삭제
        foreach ($toDelete as $id) {
            $this->db->delete('internal_shares', ['id' => $id]);
        }
        
        // 최신순 정렬
        usort($result, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
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
        
        $basePath = $this->storage->getRealPath($share['storage_id']);
        if (!$basePath) {
            http_response_code(404);
            exit(__('api_err_storage_path_not_found'));
        }
        
        $filePath = $share['file_path'];
        if (!empty($subPath)) {
            $subPath = str_replace(['\\', '/'], '/', trim($subPath, '/'));
            if (preg_match('#(^|[\\/])\\.\\.($|[\\/])#', $subPath)) {
                http_response_code(400);
                exit(__('api_err_invalid_path'));
            }
            $filePath = rtrim($filePath, '/') . '/' . $subPath;
        }
        
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        
        if ($share['is_dir'] && !empty($subPath)) {
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
            $this->downloadAsZip($fullPath);
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
        
        $targetPath = $subPath ? $sharePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subPath) : $sharePath;
        
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
        if ($entries === false) return ['success' => true, 'items' => []];
        
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullPath = $targetPath . DIRECTORY_SEPARATOR . $entry;
            $isDir = is_dir($fullPath);
            $items[] = [
                'name' => $entry,
                'path' => $subPath ? $subPath . '/' . $entry : $entry,
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
        
        // 권한 체크
        $perm = $share['permission'] ?? 'read';
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
        
        $targetPath = $subPath ? $sharePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subPath) : $sharePath;
        
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
        if (!is_dir($targetDir)) {
            $targetDir = dirname($v['targetPath']);
            if (!is_dir($targetDir)) {
                return ['success' => false, 'error' => __('ishare_target_not_folder', '대상 폴더가 존재하지 않습니다.')];
            }
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => __('err_upload_failed', '업로드 실패')];
        }
        
        $filename = basename($file['name']);
        $filename = preg_replace('/[\/\\\\]/', '', $filename);
        if (empty($filename) || $filename === '.' || $filename === '..') {
            return ['success' => false, 'error' => __('api_err_invalid_filename', '잘못된 파일명입니다.')];
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
                    break;
                case 'rename':
                default:
                    $ext = pathinfo($filename, PATHINFO_EXTENSION);
                    $name = pathinfo($filename, PATHINFO_FILENAME);
                    $i = 1;
                    do {
                        $newName = $name . " ({$i})" . ($ext ? ".{$ext}" : '');
                        $targetFile = $targetDir . DIRECTORY_SEPARATOR . $newName;
                        $i++;
                    } while (file_exists($targetFile));
                    $filename = $newName;
                    break;
            }
        }
        
        if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
            return ['success' => false, 'error' => __('err_upload_failed', '업로드 실패')];
        }
        
        return ['success' => true, 'filename' => $filename];
    }
    
    /**
     * 공유받은 폴더 내 파일/폴더 삭제 (전체 권한 필요)
     */
    public function deleteInInternalShare(int $shareId, string $subPath): array {
        if (empty($subPath)) {
            return ['success' => false, 'error' => __('ishare_cannot_delete_root', '공유 루트 폴더는 삭제할 수 없습니다.')];
        }
        
        $v = $this->validateInternalSharePath($shareId, $subPath, 'full');
        if (!$v['success']) return $v;
        
        $target = $v['targetPath'];
        if (!file_exists($target)) {
            return ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')];
        }
        
        if (is_dir($target)) {
            $this->deleteDirectory($target);
        } else {
            unlink($target);
        }
        
        return ['success' => true];
    }
    
    /**
     * 공유받은 폴더 내 파일/폴더 이름변경 (전체 권한 필요)
     */
    public function renameInInternalShare(int $shareId, string $subPath, string $newName): array {
        if (empty($subPath)) {
            return ['success' => false, 'error' => __('ishare_cannot_rename_root', '공유 루트 폴더는 이름을 변경할 수 없습니다.')];
        }
        
        $v = $this->validateInternalSharePath($shareId, $subPath, 'full');
        if (!$v['success']) return $v;
        
        $target = $v['targetPath'];
        if (!file_exists($target)) {
            return ['success' => false, 'error' => __('api_err_file_not_found', '파일을 찾을 수 없습니다.')];
        }
        
        $newName = basename($newName);
        $newName = preg_replace('/[\/\\\\]/', '', $newName);
        if (empty($newName) || $newName === '.' || $newName === '..') {
            return ['success' => false, 'error' => __('api_err_invalid_filename', '잘못된 파일명입니다.')];
        }
        
        $newPath = dirname($target) . DIRECTORY_SEPARATOR . $newName;
        if (file_exists($newPath)) {
            return ['success' => false, 'error' => __('api_err_file_exists', '같은 이름의 파일이 이미 존재합니다.')];
        }
        
        if (!rename($target, $newPath)) {
            return ['success' => false, 'error' => __('api_err_rename_failed', '이름 변경 실패')];
        }
        
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
        $folderName = preg_replace('/[\/\\\\]/', '', $folderName);
        if (empty($folderName) || $folderName === '.' || $folderName === '..') {
            return ['success' => false, 'error' => __('api_err_invalid_filename', '잘못된 폴더명입니다.')];
        }
        
        $newPath = $targetDir . DIRECTORY_SEPARATOR . $folderName;
        if (file_exists($newPath)) {
            return ['success' => false, 'error' => __('api_err_file_exists', '같은 이름이 이미 존재합니다.')];
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
            if (($user['status'] ?? 'active') !== 'active') continue;
            
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
        if (!empty($share['password'])) {
            if (!$password || !password_verify($password, $share['password'])) {
                return ['success' => false, 'error' => __('api_err_share_password_wrong', '비밀번호가 올바르지 않습니다.')];
            }
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
        $filename = preg_replace('/[\/\\\\]/', '', $filename);
        if (empty($filename) || $filename === '.' || $filename === '..') {
            return ['success' => false, 'error' => __('api_err_invalid_filename', '잘못된 파일명입니다.')];
        }
        
        // 위험 확장자 차단 (PHP, JSP, 실행파일 등)
        $dangerousExts = DANGEROUS_EXTS;
        $finalExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($finalExt, $dangerousExts)) {
            return ['success' => false, 'error' => __('api_err_dangerous_ext', '보안상 허용되지 않는 파일 형식입니다.')];
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
                $newName = $name . " ({$i})" . ($ext ? ".{$ext}" : '');
                $targetPath = $targetDir . DIRECTORY_SEPARATOR . $newName;
                $i++;
            } while (file_exists($targetPath));
            $filename = $newName;
        }
        
        // 파일 이동
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['success' => false, 'error' => __('err_upload_failed', '업로드 실패')];
        }
        
        // 업로드 카운트 증가
        $this->db->update('shares', ['token' => $token], [
            'download_count' => ($share['download_count'] ?? 0) + 1
        ]);
        
        return ['success' => true, 'filename' => $filename];
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
            
            // 캐시 hit
            if (file_exists($cacheFile) && file_exists($cacheMeta)) {
                $cacheMtime = @filemtime($cacheFile);
                $fileMtime = @filemtime($fullPath);
                if ($cacheMtime !== false && $fileMtime !== false && $cacheMtime >= $fileMtime) {
                    $mime = @file_get_contents($cacheMeta);
                    $data = @file_get_contents($cacheFile);
                    if ($mime !== false && $data !== false && strlen($data) > 100) {
                        return ['mime' => $mime, 'data' => $data, 'cached' => true, 'cache_key' => $cacheKey];
                    }
                }
            }
            
            // ★ 네거티브 캐시 hit — 커버 없는 mp3로 기록된 경우 ID3 파싱 스킵 (즉시 null)
            //   파일 mtime 비교로 stale 감지 (파일 변경 시 마커 무효화)
            if (file_exists($cacheNoCover)) {
                $markerMtime = @filemtime($cacheNoCover);
                $fileMtime = @filemtime($fullPath);
                if ($markerMtime !== false && $fileMtime !== false && $markerMtime >= $fileMtime) {
                    return null;  // 커버 없음 (캐시된 결과)
                }
            }
            
            // 캐시 miss → ID3 추출
            $cover = $this->extractID3v2Cover($fullPath);
            if (!$cover) {
                // 커버 없음 → 네거티브 캐시 마커 저장 (다음 요청 시 ID3 파싱 스킵)
                @file_put_contents($cacheNoCover, '1');
                return null;
            }
            
            // 캐시 저장
            @file_put_contents($cacheFile, $cover['data']);
            @file_put_contents($cacheMeta, $cover['mime']);
            
            return ['mime' => $cover['mime'], 'data' => $cover['data'], 'cached' => false, 'cache_key' => $cacheKey];
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
        $cacheKey = md5($token . '/' . $share['file_path'] . '/' . $mtime);
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
                if ($mime && preg_match('/^image\/[a-z0-9+-]+$/i', $mime)) {
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
        if (is_file($cacheNoCoverFile)) {
            return null;
        }
        
        // FileManager의 ID3v2 추출 로직과 동일 (프라이빗 메서드 복제 — 결합도 낮춤)
        $cover = $this->extractID3v2Cover($fullPath);
        if (!$cover) {
            @file_put_contents($cacheNoCoverFile, '1');
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
                    if (!preg_match('/^image\/[a-z0-9+-]+$/i', $mime)) {
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
                        // ISO-8859-1
                        $lyricsText = @mb_convert_encoding($lyricsBytes, 'UTF-8', 'ISO-8859-1');
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
                        // UTF-8
                        $lyricsText = $lyricsBytes;
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
                            $lyricText = @mb_convert_encoding($textBytes, 'UTF-8', 'ISO-8859-1');
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
                            $lyricText = $textBytes;
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
            $lrcPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.lrc';
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
            $txtPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.txt';
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
        $lrcPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.lrc';
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
        $txtPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.txt';
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
        $audioExts = ['mp3', 'wav', 'flac', 'm4a', 'aac', 'ogg', 'opus', 'wma', 'ape', 'alac', 'aiff'];
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
        @file_put_contents($_cacheFile, json_encode([
            'folder_mtime' => $_folderMtime,
            'tracks' => $tracks,
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
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