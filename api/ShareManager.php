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
                    $this->db->delete('shares', ['id' => $share['id']]);
                    continue;
                }
                
                // 다운로드 횟수 초과 확인 - 초과한 공유는 삭제
                if (!empty($share['max_downloads']) && 
                    ($share['download_count'] ?? 0) >= $share['max_downloads']) {
                    $this->db->delete('shares', ['id' => $share['id']]);
                    continue;
                }
                
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
        
        $this->db->delete('shares', ['id' => $id]);
        return ['success' => true];
    }
    
    // 공유 링크로 접근
    public function accessShare(string $token, string $password = null): array {
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
            $this->db->delete('shares', ['id' => $share['id']]);
            return ['success' => false, 'error' => __('api_err_share_expired', '만료된 공유 링크입니다.')];
        }
        
        // 다운로드 횟수 확인
        if ($share['max_downloads'] && $share['download_count'] >= $share['max_downloads']) {
            // 횟수 초과 공유 삭제
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
    public function downloadShare(string $token, string $password = null): void {
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
        
        $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $share['file_path']);
        
        // 경로 안전성 검증
        if (!$this->isSharePathSafe($basePath, $fullPath)) {
            http_response_code(403);
            exit(__('api_err_invalid_path', '잘못된 경로입니다.'));
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
    public function uploadToFileDrop(string $token, array $file, string $password = null): array {
        $share = $this->db->find('shares', ['token' => $token, 'is_active' => 1]);
        
        if (!$share || ($share['share_type'] ?? '') !== 'filedrop') {
            return ['success' => false, 'error' => __('ishare_invalid_filedrop', '유효하지 않은 파일 드롭 링크입니다.')];
        }
        
        // 만료 확인
        if (!empty($share['expire_at']) && strtotime($share['expire_at']) < time()) {
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
}