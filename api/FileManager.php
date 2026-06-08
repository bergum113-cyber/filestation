<?php
/**
 * FileManager - 파일 작업 관리
 * 로컬 및 원격 스토리지(FTP, SFTP, WebDAV, S3) 지원
 */
require_once __DIR__ . '/IconUrl.php';

class FileManager {
    private $db;
    private $auth;
    private $storage;
    private $fileIndex;
    private $adapters = [];  // 어댑터 캐시
    private ?string $cancelCheckFile = null;  // 취소 체크용 job 파일 경로
    
    /** 위험 확장자 목록 (업로드/청크 공통) */
    // DANGEROUS_EXTS는 config.php의 전역 상수 사용
    
    // 원격 스토리지 타입 목록
    private const REMOTE_TYPES = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
    
    public function __construct() {
        $this->db = JsonDB::getInstance();
        $this->auth = new Auth();
        $this->storage = new Storage();
        $this->fileIndex = FileIndex::getInstance();
    }
    
    /**
     * 취소 체크용 job 파일 경로 설정
     * JobManager에서 설정하면, 청크 복사/이동 루프에서 취소를 감지할 수 있음
     */
    public function setCancelCheckFile(?string $path): void {
        $this->cancelCheckFile = $path;
    }
    
    /**
     * 취소 여부 확인 (cancelCheckFile이 설정된 경우)
     */
    private function isCancelled(): bool {
        if (!$this->cancelCheckFile || !is_file($this->cancelCheckFile)) return false;
        $data = @json_decode(@file_get_contents($this->cancelCheckFile), true);
        return ($data['status'] ?? '') === 'cancelled';
    }
    
    /**
     * 원격 스토리지인지 확인
     */
    public function isRemoteStorage(int $storageId): bool {
        $info = $this->storage->getStorageById($storageId);
        if (!$info) return false;
        $type = $info['storage_type'] ?? 'local';
        return in_array($type, self::REMOTE_TYPES);
    }
    
    /**
     * 스토리지 타입 반환
     */
    private function getStorageType(int $storageId): string {
        $info = $this->storage->getStorageById($storageId);
        return $info['storage_type'] ?? 'local';
    }
    
    private string $lastAdapterError = '';
    
    /**
     * StorageAdapter 인스턴스 반환 (캐시됨)
     */
    public function getAdapter(int $storageId): ?StorageAdapterInterface {
        // 캐시 확인
        if (isset($this->adapters[$storageId])) {
            return $this->adapters[$storageId];
        }
        
        $info = $this->storage->getStorageById($storageId);
        if (!$info) {
            $this->lastAdapterError = __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.');
            return null;
        }
        
        // 어댑터 생성
        $adapter = StorageAdapterFactory::create($info);
        if ($adapter && $adapter->connect()) {
            $this->adapters[$storageId] = $adapter;
            $this->lastAdapterError = '';
            return $adapter;
        }
        
        // 연결 실패 시 상세 에러 저장
        if ($adapter && method_exists($adapter, 'getLastError')) {
            $this->lastAdapterError = $adapter->getLastError() ?: __('api_err_remote_conn_failed', '원격 스토리지 연결 실패');
        } else {
            $this->lastAdapterError = __('api_err_remote_conn_failed', '원격 스토리지 연결 실패');
        }
        
        return null;
    }
    
    /**
     * 마지막 어댑터 에러 반환
     */
    private function getLastAdapterError(): string {
        return $this->lastAdapterError ?: __('api_err_remote_conn_failed', '원격 스토리지 연결 실패');
    }
    
    /**
     * 어댑터 연결 해제
     */
    public function __destruct() {
        foreach ($this->adapters as $adapter) {
            $adapter->disconnect();
        }
    }
    
    // 파일 목록 조회
    public function listFiles(int $storageId, string $relativePath = ''): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => __('api_err_no_read_perm', '읽기 권한이 없습니다.')];
        }
        
        // 원격 스토리지인 경우 어댑터 사용
        if ($this->isRemoteStorage($storageId)) {
            return $this->listFilesRemote($storageId, $relativePath);
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
        }
        
        // 베이스 폴더가 없으면 생성
        if (!is_dir($basePath)) {
            @mkdir($basePath, 0755, true);
        }
        
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        // 경로 탐색 공격 방지
        if (!$this->isPathSafe($basePath, $fullPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!is_dir($fullPath)) {
            // 루트 경로면 생성 시도
            if (empty($relativePath)) {
                @mkdir($fullPath, 0755, true);
            }
            if (!is_dir($fullPath)) {
                return ['success' => false, 'error' => __('api_err_folder_not_found', '폴더를 찾을 수 없습니다.')];
            }
        }
        
        $items = [];
        $iterator = new DirectoryIterator($fullPath);
        
        foreach ($iterator as $file) {
            if ($file->isDot()) continue;
            
            $filename = $file->getFilename();
            // 숨김 파일 제외 (.htaccess, .gitignore 등)
            if (substr($filename, 0, 1) === '.') continue;
            
            $isDir = $file->isDir();
            $item = [
                'name' => $filename,
                'path' => $relativePath ? $relativePath . '/' . $filename : $filename,
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : $file->getSize(),
                'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                'extension' => $isDir ? '' : strtolower($file->getExtension()),
            ];
            
            $item['type'] = $this->getFileType($item['extension'], $isDir);
            $item['icon'] = $this->getFileIcon($item['type'], $item['extension']);
            
            $items[] = $item;
        }
        
        // E2E 암호화 폴더 감지 (glob으로 일괄 탐지, 특수문자 폴더는 fallback)
        $vaultDirNames = [];
        $vaultGlob = @glob($fullPath . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '.vault.json', GLOB_NOSORT);
        if ($vaultGlob) {
            foreach ($vaultGlob as $vf) {
                $vaultDirNames[basename(dirname($vf))] = true;
            }
        }
        // fallback: glob으로 탐지 못한 특수문자 폴더 (*, ?, [, ], { }) 처리
        foreach ($items as $_vi) {
            if ($_vi['is_dir'] && !isset($vaultDirNames[$_vi['name']])) {
                $_hasSpecial = preg_match('/[\*\?\[\]\{\}]/', $_vi['name']);
                if ($_hasSpecial && file_exists($fullPath . DIRECTORY_SEPARATOR . $_vi['name'] . DIRECTORY_SEPARATOR . '.vault.json')) {
                    $vaultDirNames[$_vi['name']] = true;
                }
            }
        }
        // is_vault 플래그 적용
        foreach ($items as &$_vi) {
            if ($_vi['is_dir'] && isset($vaultDirNames[$_vi['name']])) {
                $_vi['is_vault'] = true;
            }
        }
        unset($_vi);
        
        // 정렬은 api.php에서 sortFiles()로 처리 (중복 정렬 제거)
        
        return [
            'success' => true,
            'path' => $relativePath,
            'items' => $items,
            'breadcrumb' => $this->getBreadcrumb($relativePath)
        ];
    }
    
    /**
     * 원격 스토리지 파일 목록 조회
     */
    private function listFilesRemote(int $storageId, string $relativePath = ''): array {
        $adapter = $this->getAdapter($storageId);
        if (!$adapter) {
            $type = $this->getStorageType($storageId);
            return ['success' => false, 'error' => __f('storage_connect_fail', ['type' => $type])];
        }
        
        try {
            $rawItems = $adapter->list($relativePath);
            $items = [];
            
            foreach ($rawItems as $raw) {
                $filename = $raw['name'];
                // 숨김 파일 제외
                if (substr($filename, 0, 1) === '.') continue;
                
                $extension = '';
                if (!$raw['is_dir']) {
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                }
                
                $item = [
                    'name' => $filename,
                    'path' => $raw['path'],
                    'is_dir' => $raw['is_dir'],
                    'size' => $raw['size'] ?? 0,
                    // mtime 없으면 빈 문자열 (date(time()) 사용 시 매번 달라져서 캐시 키 불일치 발생)
                    'modified' => !empty($raw['modified']) ? date('Y-m-d H:i:s', $raw['modified']) : '',
                    'extension' => $extension,
                ];
                
                $item['type'] = $this->getFileType($item['extension'], $item['is_dir']);
                $item['icon'] = $this->getFileIcon($item['type'], $item['extension']);
                
                $items[] = $item;
            }
            
            // 정렬은 api.php의 sortFiles()에서 처리
            
            $response = [
                'success' => true,
                'path' => $relativePath,
                'items' => $items,
                'breadcrumb' => $this->getBreadcrumb($relativePath),
                'remote' => true
            ];
            return $response;
        } catch (Exception $e) {
            return ['success' => false, 'error' => __('remote_list_fail') . $e->getMessage()];
        }
    }
    
    // 용량 체크 (업로드 전) - 내부용 (최적화 버전)
    private function checkQuota(int $storageId, int $fileSize): array {
        $storageInfo = $this->storage->getStorageById($storageId);
        if (!$storageInfo) {
            return ['allowed' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
        }
        
        $storageType = $storageInfo['storage_type'] ?? 'local';
        $basePath = $this->storage->getRealPath($storageId);
        
        // home 타입: 사용자별 quota 체크
        if ($storageType === 'home') {
            $userId = $this->auth->getUserId();
            $user = $this->db->find('users', ['id' => $userId]);
            $quota = (int)($user['quota'] ?? 0);
            
            // quota가 0이면 무제한
            if ($quota > 0) {
                $usedSize = $this->getDirectorySize($basePath, true);
                $available = $quota - $usedSize;
                if ($fileSize > $available) {
                    return [
                        'allowed' => false, 
                        'error' => __f('quota_insufficient', ['free' => $this->formatSize(max(0, $available)), 'size' => $this->formatSize($fileSize)])
                    ];
                }
            }
            
            // 디스크 여유 공간 체크
            $diskFree = @disk_free_space($basePath);
            if ($diskFree !== false && $fileSize > $diskFree) {
                return [
                    'allowed' => false, 
                    'error' => __f('disk_space_insufficient', ['free' => $this->formatSize($diskFree), 'size' => $this->formatSize($fileSize)])
                ];
            }
            return ['allowed' => true];
        }
        
        // shared/local 타입: DB 캐싱된 used_size 사용 (빠름!)
        $quota = (int)($storageInfo['quota'] ?? 0);
        $usedSize = (int)($storageInfo['used_size'] ?? 0);
        
        if ($quota > 0) {
            // quota 설정된 경우: 캐싱된 used_size로 빠르게 체크
            $available = $quota - $usedSize;
            if ($fileSize > $available) {
                return [
                    'allowed' => false, 
                    'error' => __f('storage_quota_insufficient', ['free' => $this->formatSize(max(0, $available)), 'size' => $this->formatSize($fileSize)])
                ];
            }
            
            // 추가로 디스크 여유 공간도 체크
            $diskFree = @disk_free_space($basePath);
            if ($diskFree !== false && $fileSize > $diskFree) {
                return [
                    'allowed' => false, 
                    'error' => __f('disk_space_insufficient', ['free' => $this->formatSize($diskFree), 'size' => $this->formatSize($fileSize)])
                ];
            }
        } else {
            // quota 미설정 (무제한): 디스크 여유 공간만 체크
            $diskFree = @disk_free_space($basePath);
            if ($diskFree !== false && $fileSize > $diskFree) {
                return [
                    'allowed' => false, 
                    'error' => __f('disk_space_insufficient', ['free' => $this->formatSize($diskFree), 'size' => $this->formatSize($fileSize)])
                ];
            }
        }
        
        return ['allowed' => true];
    }
    
    // 용량 체크 - API용 public 메서드
    public function checkQuotaPublic(int $storageId, int $fileSize): array {
        $result = $this->checkQuota($storageId, $fileSize);
        $result['success'] = true;  // API 응답 형식
        return $result;
    }
    
    // 용량 포맷 (공통 함수 사용)
    public function formatSize(int $bytes): string {
        return formatFileSize($bytes);
    }
    
    // MIME 타입 검증 (확장자와 실제 타입 비교)
    private function validateMimeType(string $tmpFile, string $filename): bool {
        // 허용할 MIME 타입 매핑
        $allowedMimes = [
            // 이미지
            'jpg' => ['image/jpeg', 'image/pjpeg'],
            'jpeg' => ['image/jpeg', 'image/pjpeg'],
            'png' => ['image/png', 'image/x-png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'bmp' => ['image/bmp', 'image/x-ms-bmp', 'image/x-bmp'],
            'svg' => ['image/svg+xml'],
            'ico' => ['image/x-icon', 'image/vnd.microsoft.icon'],
            'heic' => ['image/heic', 'image/heif'],
            'heif' => ['image/heic', 'image/heif'],
            // 문서
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'html' => ['text/html'],
            'htm' => ['text/html'],
            'css' => ['text/css', 'text/plain'],
            'js' => ['application/javascript', 'text/javascript', 'text/plain'],
            'json' => ['application/json', 'text/json', 'text/plain'],
            'xml' => ['application/xml', 'text/xml'],
            'md' => ['text/markdown', 'text/plain'],
            // 오피스
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'odt' => ['application/vnd.oasis.opendocument.text'],
            'ods' => ['application/vnd.oasis.opendocument.spreadsheet'],
            // 압축
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'rar' => ['application/x-rar-compressed', 'application/vnd.rar', 'application/x-rar'],
            '7z' => ['application/x-7z-compressed'],
            'tar' => ['application/x-tar'],
            'gz' => ['application/gzip', 'application/x-gzip'],
            // 미디어
            'mp3' => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg'],
            'mp4' => ['video/mp4', 'video/3gpp', 'video/3gpp2'],
            'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave'],
            'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
            'webm' => ['video/webm', 'audio/webm'],
            'mkv' => ['video/x-matroska'],
            'avi' => ['video/x-msvideo', 'video/avi'],
            'mov' => ['video/quicktime'],
            'flac' => ['audio/flac', 'audio/x-flac'],
            'm4a' => ['audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac'],
            'm4v' => ['video/mp4', 'video/x-m4v'],
            'aac' => ['audio/aac', 'audio/x-aac', 'audio/mp4'],
            '3gp' => ['video/3gpp', 'audio/3gpp'],
            'mpg' => ['video/mpeg', 'video/mp2t'],
            'mpeg' => ['video/mpeg', 'video/mp2t'],
            'ts' => ['video/mp2t', 'video/mpeg'],
            'm2ts' => ['video/mp2t', 'video/mpeg'],
            'mts' => ['video/mp2t', 'video/mpeg'],
            'wmv' => ['video/x-ms-wmv'],
            'flv' => ['video/x-flv'],
            'wma' => ['audio/x-ms-wma'],
            // 기타
            'csv' => ['text/csv', 'text/plain'],
            'sql' => ['application/sql', 'text/plain'],
            // 'php' 항목 의도적 제거 (펜닐 v5.8.1e) — checkUploadSettings의 serverExecExts에서 항상 차단
            //   여기에 두면 미래에 serverExecExts 변경 시 PHP 업로드 허용하는 함정이 됨
            'py' => ['text/x-python', 'text/plain'],
            'java' => ['text/x-java-source', 'text/plain'],
            'c' => ['text/x-c', 'text/plain'],
            'cpp' => ['text/x-c++', 'text/plain'],
            'h' => ['text/x-c', 'text/plain'],
        ];
        
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // 확장자가 허용 목록에 없으면 기본 허용 (알 수 없는 파일 타입)
        if (!isset($allowedMimes[$ext])) {
            return true;
        }
        
        // fileinfo 확장이 없으면 검증 스킵
        if (!function_exists('finfo_open')) {
            return true;
        }
        
        // ★ 빈 파일(0 bytes)은 MIME 검증 스킵 — "새 텍스트 문서" 등 즉시 생성 시나리오 (펜닐 v5.8.1e)
        //   finfo_file이 application/x-empty / inode/x-empty 를 반환하면 화이트리스트와 불일치하여 거부됨
        //   확장자가 화이트리스트에 있으면 빈 파일은 무해하므로 허용
        if (@filesize($tmpFile) === 0) {
            return true;
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $tmpFile);
        finfo_close($finfo);
        
        // application/octet-stream은 iOS 등에서 정확한 MIME을 못 잡을 때 발생 → 허용
        if ($realMime === 'application/octet-stream') {
            return true;
        }
        
        // ★ 빈 파일 매직 — finfo가 이걸 반환하는 환경 추가 안전망 (펜닐 v5.8.1e)
        if ($realMime === 'application/x-empty' || $realMime === 'inode/x-empty') {
            return true;
        }
        
        // ★ MIME 검증 정책 (v5.8.2 — 펜닐 결정): "서버 실행 파일만 빼고 다 허용"에 맞춤
        //   기존: 화이트리스트 MIME과 정확히 일치해야 통과 → finfo 변형 MIME(rar의 application/x-rar 등)
        //         이 누락되면 정상 파일도 거부되는 문제 (확장자별 MIME을 무한정 등록해야 하는 땜질).
        //   변경: ① 화이트리스트 일치 → 통과 (기존 동작 100% 보존, 정상 파일 영향 0)
        //         ② 불일치해도 — 위장된 실행 파일(PHP/스크립트, 또는 바이너리 확장자에 숨긴 HTML)만 차단,
        //            그 외(정상 파일의 MIME 변형)는 통과.
        //   ※ 서버 실행 확장자(php/jsp/asp 등)는 checkUploadSettings의 serverExecExts에서 이미 항상 차단됨.
        //      이 함수는 "안전한 확장자로 위장한 위험 내용"을 보조 차단하는 역할.

        // ① 화이트리스트 정확 일치 → 통과 (기존과 동일)
        if (in_array($realMime, $allowedMimes[$ext], true)) {
            return true;
        }

        // ② 불일치 — 위장 실행 파일만 차단
        // 서버/브라우저에서 코드로 실행될 수 있는 위험 MIME
        $dangerousMimes = [
            'application/x-httpd-php', 'application/x-php', 'text/x-php',
            'application/x-httpd-php-source', 'application/x-httpd-php5',
            'application/x-php-source',
        ];
        // 내용이 텍스트/마크업이어도 정상인 확장자 (HTML 내용 허용)
        $textExts = ['txt', 'html', 'htm', 'css', 'js', 'json', 'xml', 'md', 'csv', 'sql',
            'py', 'java', 'c', 'cpp', 'h', 'svg'];

        if (in_array($realMime, $dangerousMimes, true)) {
            return false;   // PHP 등 실행 스크립트가 다른 확장자로 위장 → 차단
        }
        if ($realMime === 'text/html' && !in_array($ext, $textExts, true)) {
            return false;   // 바이너리 확장자(이미지/미디어/압축 등)에 HTML 내용 → 위장 차단
        }

        // 그 외 — 정상 파일의 MIME 변형(rar=application/x-rar 등)으로 간주, 통과
        return true;
    }
    
    /**
     * 랜섬웨어 방지 설정 로드
     */
    private function getRansomwareConfig(): array {
        static $config = null;
        if ($config === null) {
            $configFile = DATA_PATH . '/ransomware_config.json';
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?: [];
            } else {
                $config = [];
            }
        }
        return $config;
    }
    
    /**
     * 랜섬웨어 의심 확장자 체크
     */
    /**
     * 업로드 설정 기반 파일 검증
     */
    private function checkUploadSettings(string $filename, int $fileSize = 0): array {
        static $settings = null;
        if ($settings === null) {
            $configFile = DATA_PATH . '/upload_settings.json';
            if (!file_exists($configFile)) {
                $settings = ['mode' => 'all', 'mime_check' => true];
            } else {
                $settings = json_decode(file_get_contents($configFile), true) ?: ['mode' => 'all', 'mime_check' => true];
            }
        }
        
        $mode = $settings['mode'] ?? 'all';
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // 서버 실행 확장자는 모든 모드에서 항상 차단
        $serverExecExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps',
            'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'htaccess', 'user.ini'];
        if (in_array($ext, $serverExecExts)) {
            return [
                'allowed' => false,
                'reason' => __('api_err_dangerous_ext', '보안상 허용되지 않는 파일 형식입니다') . ": .$ext"
            ];
        }
        // 이중 확장자 차단 (evil.php.jpg)
        $nameParts = explode('.', strtolower(basename($filename)));
        if (count($nameParts) > 2) {
            for ($i = 1; $i < count($nameParts) - 1; $i++) {
                if (in_array($nameParts[$i], $serverExecExts)) {
                    return [
                        'allowed' => false,
                        'reason' => __('api_err_dangerous_filename', '보안상 허용되지 않는 파일명입니다')
                    ];
                }
            }
        }
        
        if ($mode === 'all') {
            return ['allowed' => true, 'mime_check' => $settings['mime_check'] ?? true];
        }
        
        // 허용 모드
        if ($mode === 'allow' && !empty($settings['allowed_extensions'])) {
            $allowed = array_map('trim', array_map('strtolower', explode(',', $settings['allowed_extensions'])));
            $allowed = array_filter($allowed);
            if (!empty($allowed) && !in_array($ext, $allowed)) {
                return [
                    'allowed' => false,
                    'reason' => __('upload_ext_not_allowed', '허용되지 않는 파일 형식입니다') . ": .$ext"
                ];
            }
        }
        
        // 차단 모드
        if ($mode === 'block' && !empty($settings['blocked_extensions'])) {
            $blocked = array_map('trim', array_map('strtolower', explode(',', $settings['blocked_extensions'])));
            $blocked = array_filter($blocked);
            if (in_array($ext, $blocked)) {
                return [
                    'allowed' => false,
                    'reason' => __('upload_ext_blocked', '차단된 파일 형식입니다') . ": .$ext"
                ];
            }
        }
        
        // 최대 파일 크기 검증
        $maxSize = (int)($settings['max_filesize'] ?? 0);
        if ($maxSize > 0 && $fileSize > 0 && $fileSize > $maxSize * 1024 * 1024) {
            return [
                'allowed' => false,
                'reason' => __('upload_file_too_large', '파일 크기가 제한을 초과합니다') . ": " . round($fileSize / 1024 / 1024, 1) . "MB / {$maxSize}MB"
            ];
        }
        
        return ['allowed' => true, 'mime_check' => $settings['mime_check'] ?? true];
    }
    
    private function checkRansomwareExtension(string $filename): array {
        $config = $this->getRansomwareConfig();
        
        if (empty($config['block_extensions'])) {
            return ['allowed' => true];
        }
        
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (empty($ext)) {
            return ['allowed' => true];
        }
        
        $ext = '.' . $ext;
        
        // 차단 확장자 목록 체크
        $blockedList = $config['blocked_extensions'] ?? '';
        if (!empty($blockedList)) {
            $blocked = array_map('trim', explode(',', strtolower($blockedList)));
            if (in_array($ext, $blocked)) {
                $this->logRansomwareEvent('blocked', __('ransomware_suspicious_ext'), __f('ransomware_file_info', ['filename' => $filename, 'ext' => $ext]));
                return ['allowed' => false, 'reason' => __f('ransomware_ext_blocked', ['ext' => $ext])];
            }
        }
        
        // 랜덤 확장자 체크 (숫자+문자 조합 6자 이상)
        if (!empty($config['block_random_ext'])) {
            $extWithoutDot = substr($ext, 1);
            if (strlen($extWithoutDot) >= 6 && preg_match('/^[a-z0-9]+$/i', $extWithoutDot)) {
                // 알려진 일반 확장자는 제외
                $knownExtensions = ['backup', 'config', 'sqlite', 'download', 'partial', 'crdownload', 'torrent'];
                if (!in_array($extWithoutDot, $knownExtensions)) {
                    $this->logRansomwareEvent('blocked', __('ransomware_random_ext'), __f('ransomware_file_info', ['filename' => $filename, 'ext' => $ext]));
                    return ['allowed' => false, 'reason' => __f('ransomware_random_ext_blocked', ['ext' => $ext])];
                }
            }
        }
        
        return ['allowed' => true];
    }
    
    /**
     * 파일 내용 검사 (엔트로피 + 시그니처)
     */
    private function checkRansomwareContent(string $filePath, string $filename): array {
        $config = $this->getRansomwareConfig();
        
        // 내용 검사 비활성화 시
        if (empty($config['content_check'])) {
            return ['allowed' => true];
        }
        
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $fileSize = filesize($filePath);
        
        // 너무 작은 파일은 검사 제외 (1KB 미만)
        if ($fileSize < 1024) {
            return ['allowed' => true];
        }
        
        // 1. 파일 시그니처 검사
        if (!empty($config['signature_check'])) {
            $sigCheck = $this->checkFileSignature($filePath, $ext);
            if (!$sigCheck['valid']) {
                $this->logRansomwareEvent('warning', __('file_sig_mismatch'), 
                    __f('file_sig_mismatch_detail', ['filename' => $filename, 'ext' => $ext, 'expected' => $sigCheck['expected'], 'actual' => $sigCheck['actual']]));
                
                // 경고만 하고 차단은 선택적
                if (!empty($config['block_signature_mismatch'])) {
                    return ['allowed' => false, 'reason' => __f('file_type_mismatch', ['ext' => $ext])];
                }
            }
        }
        
        return ['allowed' => true];
    }
    
    /**
    /**
     * 파일 시그니처(매직 바이트) 검사
     */
    private function checkFileSignature(string $filePath, string $ext): array {
        // 알려진 파일 시그니처 (매직 바이트)
        $signatures = [
            // 문서
            'pdf'  => ['25504446'],  // %PDF
            'doc'  => ['D0CF11E0A1B11AE1'],  // OLE
            'docx' => ['504B0304', '504B0506', '504B0708'],  // ZIP (Office Open XML)
            'xls'  => ['D0CF11E0A1B11AE1'],
            'xlsx' => ['504B0304', '504B0506', '504B0708'],
            'ppt'  => ['D0CF11E0A1B11AE1'],
            'pptx' => ['504B0304', '504B0506', '504B0708'],
            'odt'  => ['504B0304'],
            'ods'  => ['504B0304'],
            'odp'  => ['504B0304'],
            
            // 이미지
            'jpg'  => ['FFD8FF'],
            'jpeg' => ['FFD8FF'],
            'png'  => ['89504E47'],
            'gif'  => ['47494638'],
            'bmp'  => ['424D'],
            'webp' => ['52494646'],  // RIFF
            'ico'  => ['00000100'],
            'tiff' => ['49492A00', '4D4D002A'],
            'tif'  => ['49492A00', '4D4D002A'],
            
            // 압축
            'zip'  => ['504B0304', '504B0506', '504B0708'],
            'rar'  => ['526172211A0700', '526172211A070100'],
            '7z'   => ['377ABCAF271C'],
            'gz'   => ['1F8B'],
            'bz2'  => ['425A68'],
            'xz'   => ['FD377A585A00'],
            'tar'  => ['7573746172'],  // "ustar" at offset 257
            
            // 실행파일
            'exe'  => ['4D5A'],  // MZ
            'dll'  => ['4D5A'],
            'msi'  => ['D0CF11E0A1B11AE1'],
            
            // 오디오/비디오
            'mp3'  => ['494433', 'FFFB', 'FFF3', 'FFF2'],  // ID3, MP3 frames
            'mp4'  => ['__ftyp_offset4__'],  // ftyp at offset 4 (special handling)
            'mkv'  => ['1A45DFA3'],
            'avi'  => ['52494646'],  // RIFF
            'wav'  => ['52494646'],  // RIFF
            'flac' => ['664C6143'],  // fLaC
            'ogg'  => ['4F676753'],  // OggS
            'webm' => ['1A45DFA3'],
            
            // 기타
            'sqlite' => ['53514C697465'],  // SQLite
            'xml'  => ['3C3F786D6C'],  // <?xml
            'html' => ['3C21444F43', '3C68746D6C', '3C48544D4C'],  // <!DOC, <html, <HTML
            'rtf'  => ['7B5C727466'],  // {\rtf
        ];
        
        // 확장자에 해당하는 시그니처가 없으면 검사 스킵
        if (!isset($signatures[$ext])) {
            return ['valid' => true, 'expected' => 'N/A', 'actual' => 'N/A'];
        }
        
        // 파일 헤더 읽기 (최대 16바이트)
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return ['valid' => true, 'expected' => 'N/A', 'actual' => 'error'];
        }
        
        $header = fread($handle, 16);
        fclose($handle);
        
        if (empty($header)) {
            return ['valid' => true, 'expected' => 'N/A', 'actual' => 'empty'];
        }
        
        $headerHex = strtoupper(bin2hex($header));
        $expectedSigs = $signatures[$ext];
        
        // MP4/MOV/3GP: ftyp가 offset 4에 위치 (처음 4바이트는 박스 크기)
        if (in_array($ext, ['mp4', 'm4v', 'm4a', 'mov', '3gp'])) {
            if (strlen($headerHex) >= 16 && substr($headerHex, 8, 8) === '66747970') {
                return ['valid' => true, 'expected' => 'ftyp@offset4', 'actual' => substr($headerHex, 8, 8)];
            }
            // ftyp가 offset 0에 있는 경우도 허용
            if (strpos($headerHex, '66747970') === 0) {
                return ['valid' => true, 'expected' => 'ftyp@offset0', 'actual' => substr($headerHex, 0, 8)];
            }
            return [
                'valid' => false,
                'expected' => 'ftyp at offset 0 or 4',
                'actual' => substr($headerHex, 0, 16)
            ];
        }
        
        foreach ($expectedSigs as $sig) {
            if (strpos($headerHex, strtoupper($sig)) === 0) {
                return ['valid' => true, 'expected' => $sig, 'actual' => substr($headerHex, 0, strlen($sig))];
            }
        }
        
        return [
            'valid' => false, 
            'expected' => implode(' or ', $expectedSigs), 
            'actual' => substr($headerHex, 0, 16)
        ];
    }
    
    /**
     * 대량 작업 감지 (삭제/덮어쓰기)
     */
    private function checkBulkOperation(string $operationType): array {
        $config = $this->getRansomwareConfig();
        
        if (empty($config['bulk_protection'])) {
            return ['allowed' => true];
        }
        
        $user = $this->auth->getUser();
        $userId = $user['id'] ?? 0;
        
        if ($userId === 0) {
            return ['allowed' => true];
        }
        
        // 관리자는 대량 작업 감지에서 제외
        if (($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'sub_admin') {
            return ['allowed' => true];
        }
        
        $trackFile = DATA_PATH . '/ransomware_bulk_track.json';
        $track = [];
        if (file_exists($trackFile)) {
            $track = json_decode(file_get_contents($trackFile), true) ?: [];
        }
        
        $now = time();
        $bulkTime = (int)($config['bulk_time'] ?? 60);
        $blockDuration = (int)($config['block_duration'] ?? 30) * 60; // 분 -> 초
        
        // 차단 상태 확인
        if (isset($track[$userId]['blocked_until']) && $track[$userId]['blocked_until'] > $now) {
            $remaining = ceil(($track[$userId]['blocked_until'] - $now) / 60);
            return ['allowed' => false, 'reason' => __f('bulk_op_restricted', ['remaining' => $remaining])];
        }
        
        // 작업 기록 초기화
        if (!isset($track[$userId])) {
            $track[$userId] = ['delete' => [], 'overwrite' => []];
        }
        
        // 오래된 기록 제거
        $cutoff = $now - $bulkTime;
        foreach (['delete', 'overwrite'] as $type) {
            if (isset($track[$userId][$type])) {
                $track[$userId][$type] = array_filter($track[$userId][$type], fn($t) => $t > $cutoff);
            }
        }
        
        // 현재 작업 기록
        $track[$userId][$operationType][] = $now;
        
        // 임계값 확인
        $deleteLimit = (int)($config['bulk_delete_limit'] ?? 50);
        $overwriteLimit = (int)($config['bulk_overwrite_limit'] ?? 50);
        
        $deleteCount = count($track[$userId]['delete'] ?? []);
        $overwriteCount = count($track[$userId]['overwrite'] ?? []);
        
        $blocked = false;
        $reason = '';
        
        if ($operationType === 'delete' && $deleteCount >= $deleteLimit) {
            $blocked = true;
            $reason = __f('bulk_delete_blocked', ['count' => $deleteCount]);
            $this->logRansomwareEvent('blocked', __('bulk_delete_event'), __f('bulk_delete_detail', ['userId' => $userId, 'count' => $deleteCount]));
        } elseif ($operationType === 'overwrite' && $overwriteCount >= $overwriteLimit) {
            $blocked = true;
            $reason = __f('bulk_overwrite_blocked', ['count' => $overwriteCount]);
            $this->logRansomwareEvent('blocked', __('bulk_overwrite_event'), __f('bulk_overwrite_detail', ['userId' => $userId, 'count' => $overwriteCount]));
        }
        
        if ($blocked) {
            $track[$userId]['blocked_until'] = $now + $blockDuration;
        }
        
        // 저장
        file_put_contents($trackFile, json_encode($track, JSON_PRETTY_PRINT), LOCK_EX);
        
        if ($blocked) {
            return ['allowed' => false, 'reason' => $reason];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * 파일 버전 저장 (덮어쓰기 전)
     */
    private function saveFileVersion(string $filePath, string $relativePath, int $storageId): void {
        $config = $this->getRansomwareConfig();
        
        if (empty($config['versioning'])) {
            return;
        }
        
        if (!file_exists($filePath)) {
            return;
        }
        
        // 제외 확장자 체크
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $excludeList = $config['version_exclude'] ?? '';
        if (!empty($excludeList)) {
            $excluded = array_map(function($e) {
                return ltrim(trim($e), '.');
            }, explode(',', strtolower($excludeList)));
            if (in_array($ext, $excluded)) {
                return;
            }
        }
        
        // 버전 저장 디렉토리
        $versionDir = DATA_PATH . '/file_versions';
        if (!is_dir($versionDir)) {
            mkdir($versionDir, 0755, true);
        }
        
        // 파일 해시로 고유 식별
        $fileHash = md5($storageId . '/' . $relativePath);
        $fileVersionDir = $versionDir . '/' . $fileHash;
        if (!is_dir($fileVersionDir)) {
            mkdir($fileVersionDir, 0755, true);
        }
        
        // 버전 메타데이터
        $metaFile = $fileVersionDir . '/meta.json';
        $meta = [];
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true) ?: [];
        }
        
        if (!isset($meta['versions'])) {
            $meta['versions'] = [];
        }
        
        // 새 버전 저장
        $versionId = time() . '_' . uniqid();
        $versionFile = $fileVersionDir . '/' . $versionId;
        
        if (@copy($filePath, $versionFile)) {
            $meta['file_path'] = $relativePath;
            $meta['storage_id'] = $storageId;
            $meta['versions'][] = [
                'id' => $versionId,
                'time' => date('Y-m-d H:i:s'),
                'size' => filesize($filePath),
                'timestamp' => time()
            ];
            
            // 최대 버전 수 제한
            $maxVersions = (int)($config['max_versions'] ?? 10);
            while (count($meta['versions']) > $maxVersions) {
                $oldVersion = array_shift($meta['versions']);
                @unlink($fileVersionDir . '/' . $oldVersion['id']);
            }
            
            file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
    
    /**
     * 오래된 파일 버전 정리 (cron 또는 수동 호출)
     */
    public function cleanupOldVersions(): array {
        $config = $this->getRansomwareConfig();
        $versionDays = (int)($config['version_days'] ?? 7);
        $cutoff = time() - ($versionDays * 86400);
        
        $versionDir = DATA_PATH . '/file_versions';
        if (!is_dir($versionDir)) {
            return ['success' => true, 'cleaned' => 0, 'orphans' => 0];
        }
        
        // 휴지통의 원본 경로 목록 (복원 가능한 파일)
        $trashPaths = [];
        $trash = $this->db->load('trash') ?: [];
        foreach ($trash as $item) {
            $sid = $item['storage_id'] ?? 0;
            $op = $item['original_path'] ?? '';
            if ($sid && $op) {
                $trashPaths[md5($sid . '/' . $op)] = true;
            }
        }
        
        $cleaned = 0;
        $orphans = 0;
        
        foreach (scandir($versionDir) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            
            $fileVersionDir = $versionDir . '/' . $dir;
            $metaFile = $fileVersionDir . '/meta.json';
            
            if (!file_exists($metaFile)) continue;
            
            $meta = json_decode(file_get_contents($metaFile), true) ?: [];
            
            // 고아 스냅샷 확인: 원본 파일도 없고 휴지통에도 없는 경우
            $sid = $meta['storage_id'] ?? 0;
            $path = $meta['path'] ?? '';
            $fileHash = md5($sid . '/' . $path);
            $isOrphan = false;
            
            if ($sid && $path && !isset($trashPaths[$fileHash])) {
                // 원본 파일 존재 확인
                $realPath = $this->storage->getRealPath($sid);
                if ($realPath) {
                    $fullPath = $realPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
                    if (!file_exists($fullPath)) {
                        $isOrphan = true;
                    }
                } else {
                    // 스토리지 자체가 없으면 고아
                    $isOrphan = true;
                }
            }
            
            if ($isOrphan) {
                // 고아 스냅샷: 전체 삭제
                $versions = $meta['versions'] ?? [];
                foreach ($versions as $version) {
                    @unlink($fileVersionDir . '/' . ($version['id'] ?? ''));
                }
                @unlink($metaFile);
                @rmdir($fileVersionDir);
                $orphans += count($versions);
                continue;
            }
            
            // 기간 초과 버전 정리
            $versions = $meta['versions'] ?? [];
            $remaining = [];
            
            foreach ($versions as $version) {
                if (($version['timestamp'] ?? 0) < $cutoff) {
                    @unlink($fileVersionDir . '/' . $version['id']);
                    $cleaned++;
                } else {
                    $remaining[] = $version;
                }
            }
            
            if (empty($remaining)) {
                // 모든 버전이 삭제되면 디렉토리도 삭제
                @unlink($metaFile);
                @rmdir($fileVersionDir);
            } else {
                $meta['versions'] = $remaining;
                file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }
        
        return ['success' => true, 'cleaned' => $cleaned, 'orphans' => $orphans];
    }
    
    /**
     * 특정 파일의 모든 버전 삭제
     */
    public function deleteFileVersions(int $storageId, string $relativePath): void {
        $versionDir = DATA_PATH . '/file_versions';
        $fileHash = md5($storageId . '/' . $relativePath);
        $fileVersionDir = $versionDir . '/' . $fileHash;
        
        if (is_dir($fileVersionDir)) {
            // 디렉토리 내 모든 파일 삭제
            foreach (scandir($fileVersionDir) as $file) {
                if ($file === '.' || $file === '..') continue;
                @unlink($fileVersionDir . '/' . $file);
            }
            @rmdir($fileVersionDir);
        }
    }
    
    /**
     * 폴더 삭제 시 하위 모든 파일의 버전 스냅샷 정리
     */
    private function deleteDirectoryVersions(int $storageId, string $relativePath, string $fullPath): void {
        $versionBaseDir = DATA_PATH . '/file_versions';
        if (!is_dir($versionBaseDir)) return;
        
        // file_versions 디렉토리의 모든 해시 폴더 순회
        $prefix = $storageId . '/' . $relativePath . '/';
        foreach (scandir($versionBaseDir) as $hashDir) {
            if ($hashDir === '.' || $hashDir === '..') continue;
            $hashPath = $versionBaseDir . '/' . $hashDir;
            if (!is_dir($hashPath)) continue;
            
            $metaFile = $hashPath . '/meta.json';
            if (!file_exists($metaFile)) continue;
            
            $meta = @json_decode(file_get_contents($metaFile), true);
            if (!$meta) continue;
            
            // 이 버전이 삭제 대상 폴더 하위인지 확인
            $storedPath = ($meta['storage_id'] ?? '') . '/' . ($meta['path'] ?? '');
            if (str_starts_with($storedPath, $prefix) || $storedPath === $storageId . '/' . $relativePath) {
                foreach (scandir($hashPath) as $file) {
                    if ($file === '.' || $file === '..') continue;
                    @unlink($hashPath . '/' . $file);
                }
                @rmdir($hashPath);
            }
        }
    }
    
    /**
     * 파일 버전 목록 조회
     */
    public function getFileVersions(int $storageId, string $relativePath): array {
        $versionDir = DATA_PATH . '/file_versions';
        $fileHash = md5($storageId . '/' . $relativePath);
        $metaFile = $versionDir . '/' . $fileHash . '/meta.json';
        
        if (!file_exists($metaFile)) {
            return ['success' => true, 'versions' => []];
        }
        
        $meta = json_decode(file_get_contents($metaFile), true) ?: [];
        
        return [
            'success' => true,
            'versions' => array_reverse($meta['versions'] ?? [])
        ];
    }
    
    /**
     * 파일 버전 복원
     */
    public function restoreFileVersion(int $storageId, string $relativePath, string $versionId): array {
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => __('api_err_no_write_perm', '쓰기 권한이 없습니다.')];
        }
        
        $versionDir = DATA_PATH . '/file_versions';
        $fileHash = md5($storageId . '/' . $relativePath);
        $versionFile = $versionDir . '/' . $fileHash . '/' . $versionId;
        
        if (!file_exists($versionFile)) {
            return ['success' => false, 'error' => __('api_err_version_file_not_found', '버전 파일을 찾을 수 없습니다.')];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $targetPath = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $targetPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        // Windows 표준: 복원 시에는 현재 파일 백업하지 않음
        // 복원은 되돌릴 수 없음 (덮어쓰기)
        
        // 버전 복원
        if (@copy($versionFile, $targetPath)) {
            return ['success' => true, 'message' => __('api_file_restored', '파일이 복원되었습니다.')];
        }
        
        return ['success' => false, 'error' => __('api_err_restore_failed', '파일 복원에 실패했습니다.')];
    }
    
    /**
     * 랜섬웨어 방지 로그 기록
     */
    private function logRansomwareEvent(string $type, string $action, string $detail): void {
        try {
            $logFile = DATA_PATH . '/ransomware_logs.json';
            $logs = [];
            if (file_exists($logFile)) {
                $logs = json_decode(file_get_contents($logFile), true) ?: [];
            }
            
            $user = $this->auth->getUser();
            
            $logs[] = [
                'time' => date('Y-m-d H:i:s'),
                'type' => $type,
                'action' => $action,
                'detail' => $detail,
                'user' => $user['display_name'] ?? $user['username'] ?? 'Unknown',
                'user_id' => $user['id'] ?? 0,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ];
            
            // 최대 10000개 유지
            if (count($logs) > 10000) {
                $logs = array_slice($logs, -10000);
            }
            
            file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        } catch (Exception $e) {
            // 로그 기록 실패는 무시
        }
    }
    
    /**
     * 바이러스 검사 설정 로드
     */
    private function getAntivirusConfig(): array {
        static $config = null;
        if ($config === null) {
            $configFile = DATA_PATH . '/antivirus_config.json';
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?: [];
            } else {
                $config = ['engine' => 'disabled'];
            }
        }
        return $config;
    }
    
    /**
     * 바이러스 검사
     */
    private function scanForVirus(string $tmpFile, string $filename, int $fileSize): array {
        $config = $this->getAntivirusConfig();
        
        $engine = $config['engine'] ?? 'disabled';
        if ($engine === 'disabled') {
            return ['clean' => true];
        }
        
        // ★ 빈 파일(0 bytes)은 바이러스 검사 스킵 (펜닐 v5.8.1e)
        //   "새 텍스트 문서" 등 즉시 생성 시나리오 — 0 바이트는 어떤 시그니처도 담을 수 없으므로 검사 자체가 무의미
        //   Defender/ClamAV의 임시파일 복사 검증(filesize===0) 로직과의 충돌도 회피
        if ($fileSize === 0) {
            return ['clean' => true, 'skipped' => true];
        }
        
        // 최대 크기 체크 (MB -> bytes)
        $maxSize = ($config['max_size'] ?? 100) * 1024 * 1024;
        if ($maxSize > 0 && $fileSize > $maxSize) {
            return ['clean' => true, 'skipped' => true];
        }
        
        $result = ['clean' => true];
        
        switch ($engine) {
            case 'clamav':
                $result = $this->scanWithClamAV($tmpFile, $config);
                break;
            case 'defender':
                $result = $this->scanWithDefender($tmpFile, $config);
                break;
        }
        
        // 스캔 로그 기록
        $this->logAntivirusScan($filename, $result);
        
        // 오류 발생 시 block_on_error 옵션 적용
        if (isset($result['error']) && !empty($config['block_on_error'])) {
            // 오류 시 차단 옵션이 활성화되면 업로드 차단
            $result['clean'] = false;
            $result['threat'] = __('av_scan_error_blocked') . $result['error'];
        }
        
        return $result;
    }
    
    /**
     * ClamAV로 바이러스 검사
     */
    private function scanWithClamAV(string $filePath, array $config): array {
        $clamPath = $config['clamav_path'] ?? '';
        if (empty($clamPath)) {
            $clamPath = 'clamscan';
        }
        
        // escapeShellPath로 통일 (Windows 공백/특수문자 대응)
        $clamPathQuoted = $this->escapeShellPath($clamPath);
        
        // 명령어 존재 여부 확인
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: where 명령어로 확인
            $checkCmd = 'where ' . $clamPathQuoted . ' 2>nul';
            exec($checkCmd, $checkOutput, $checkCode);
            if ($checkCode !== 0 && !file_exists($clamPath)) {
                return ['clean' => true, 'error' => __('clamav_not_found') . $clamPath];
            }
        } else {
            // Linux/Mac: which 명령어로 확인
            $checkCmd = 'which ' . escapeshellarg($clamPath) . ' 2>/dev/null';
            exec($checkCmd, $checkOutput, $checkCode);
            if ($checkCode !== 0 && !file_exists($clamPath)) {
                return ['clean' => true, 'error' => __('clamav_not_installed') . $clamPath];
            }
        }
        
        $command = $clamPathQuoted . ' --no-summary ' . escapeshellarg($filePath) . ' 2>&1';
        exec($command, $output, $returnCode);
        
        // ClamAV 리턴 코드: 0=정상, 1=바이러스 발견, 2+=에러
        if ($returnCode === 0) {
            return ['clean' => true];
        } elseif ($returnCode === 1) {
            // 바이러스 발견 - output에 FOUND가 있는지 확인
            $outputStr = implode(' ', $output);
            if (strpos($outputStr, 'FOUND') !== false) {
                $threat = 'Unknown threat';
                foreach ($output as $line) {
                    if (strpos($line, 'FOUND') !== false) {
                        $threat = trim(str_replace('FOUND', '', $line));
                        $threat = trim(substr($threat, strpos($threat, ':') + 1));
                        break;
                    }
                }
                return ['clean' => false, 'threat' => $threat];
            }
            // FOUND가 없으면 실행 에러
            return ['clean' => true, 'error' => __f('clamav_exec_error', ['code' => 1]) . $outputStr];
        } else {
            return ['clean' => true, 'error' => __f('clamav_exec_error', ['code' => $returnCode]) . implode(' ', $output)];
        }
    }
    
    /**
     * Windows Defender로 바이러스 검사
     */
    private function scanWithDefender(string $filePath, array $config): array {
        if (PHP_OS_FAMILY !== 'Windows') {
            return ['clean' => true, 'error' => __('api_err_defender_windows_only', 'Windows Defender는 Windows에서만 사용 가능합니다.')];
        }
        
        $defenderPath = $config['defender_path'] ?? '';
        if (empty($defenderPath)) {
            $defenderPath = 'C:\\Program Files\\Windows Defender\\MpCmdRun.exe';
        }
        
        if (!file_exists($defenderPath)) {
            return ['clean' => true, 'error' => __('defender_not_found') . $defenderPath];
        }
        
        // 항상 임시 파일로 복사해서 검사 (특수문자 문제 방지)
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'defender_scan_' . uniqid() . ($ext ? '.' . $ext : '');
        
        // stream_copy로 복사 (더 안정적)
        $src = @fopen($filePath, 'rb');
        $dst = @fopen($tempFile, 'wb');
        if (!$src || !$dst) {
            if ($src) fclose($src);
            if ($dst) fclose($dst);
            return ['clean' => true, 'error' => __('temp_file_create_fail')];
        }
        stream_copy_to_stream($src, $dst);
        fclose($src);
        fclose($dst);
        
        // 파일이 완전히 쓰여졌는지 확인
        // ★ 원본/사본 사이즈 비교로 변경 (펜닐 v5.8.1e — 정상 0-byte 파일 오진 방지)
        //   기존: filesize($tempFile) === 0 → 정상 빈 파일도 "복사 실패"로 오판
        //   현재: 원본 사이즈와 비교 → 디스크 풀로 인한 부분 복사도 정확히 검출
        //   안전 가드: 원본 사이즈 false 시(파일 사라짐 등) 사본 존재만 확인 (기존 동작 유지)
        clearstatcache(true, $tempFile);
        clearstatcache(true, $filePath);
        if (!file_exists($tempFile)) {
            @unlink($tempFile);
            return ['clean' => true, 'error' => __('temp_file_copy_fail')];
        }
        $_origSize = @filesize($filePath);
        $_copySize = @filesize($tempFile);
        // 원본 사이즈를 알 수 있을 때만 비교 (false이면 fallback: 사본 존재 자체가 OK)
        if ($_origSize !== false && $_copySize !== $_origSize) {
            @unlink($tempFile);
            return ['clean' => true, 'error' => __('temp_file_copy_fail')];
        }
        
        // Defender 실행 (shell_exec로 동기 실행)
        $command = $this->escapeShellPath($defenderPath) . ' -Scan -ScanType 3 -File ' . $this->escapeShellPath($tempFile) . ' -DisableRemediation 2>&1';
        $outputStr = shell_exec($command);
        $output = $outputStr ? explode("\n", $outputStr) : [];
        
        // 출력에서 결과 확인
        $hasFound = false;
        $hasThreat = false;
        foreach ($output as $line) {
            if (stripos($line, 'found') !== false || stripos($line, 'threat') !== false || stripos($line, 'detected') !== false) {
                $hasThreat = true;
            }
            if (stripos($line, 'no threats') !== false || stripos($line, 'did not find') !== false) {
                $hasFound = false;
                $hasThreat = false;
                break;
            }
        }
        
        // 임시 파일 삭제
        @unlink($tempFile);
        
        // 결과 판단: "Scan finished"만 있고 threat 언급 없으면 정상
        $scanFinished = stripos($outputStr ?? '', 'Scan finished') !== false;
        $noThreatMention = stripos($outputStr ?? '', 'threat') === false && stripos($outputStr ?? '', 'found') === false;
        
        if ($scanFinished && $noThreatMention) {
            return ['clean' => true];
        } elseif ($hasThreat) {
            $threat = 'Threat detected';
            foreach ($output as $line) {
                if (stripos($line, 'threat') !== false || stripos($line, 'found') !== false) {
                    $threat = trim($line);
                    break;
                }
            }
            return ['clean' => false, 'threat' => $threat];
        } else {
            // 판단 불가 - 정상으로 처리
            return ['clean' => true];
        }
    }
    
    /**
     * 바이러스 검사 로그 기록
     */
    private function logAntivirusScan(string $filename, array $result): void {
        try {
            $logFile = DATA_PATH . '/antivirus_scan_logs.json';
            $logs = [];
            if (file_exists($logFile)) {
                $logs = json_decode(file_get_contents($logFile), true) ?: [];
            }
            
            // 결과 문자열 생성
            $resultStr = __('scan_clean');
            if (!$result['clean']) {
                $resultStr = $result['threat'] ?? __('scan_threat');
            } elseif (!empty($result['skipped'])) {
                $resultStr = __('scan_skip_size');
            } elseif (!empty($result['error'])) {
                $resultStr = __('scan_error_prefix') . $result['error'];
            }
            
            $logs[] = [
                'time' => date('Y-m-d H:i:s'),
                'filename' => $filename,
                'clean' => $result['clean'],
                'result' => $resultStr
            ];
            
            // 무제한 기록 (제한 없음)
            
            file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        } catch (Exception $e) {
            // 로그 기록 실패는 무시
        }
    }
    
    /**
     * 바이러스 검사 엔진 테스트
     */
    public function testAntivirus(): array {
        $config = $this->getAntivirusConfig();
        
        $engine = $config['engine'] ?? 'disabled';
        if ($engine === 'disabled') {
            return ['success' => false, 'error' => __('api_av_disabled', '백신 검사가 비활성화되어 있습니다.')];
        }
        
        // 지원하지 않는 엔진
        if (!in_array($engine, ['clamav', 'defender'])) {
            return ['success' => false, 'error' => __('unsupported_scan_engine') . $engine];
        }
        
        // 테스트용 임시 파일 생성
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webhard_av_test_' . uniqid() . '.txt';
        if (!@file_put_contents($tmpFile, 'This is a test file for antivirus scanning.')) {
            return ['success' => false, 'error' => __('temp_file_fail_prefix') . $tmpFile];
        }
        
        try {
            $result = null;
            switch ($engine) {
                case 'clamav':
                    $result = $this->scanWithClamAV($tmpFile, $config);
                    break;
                case 'defender':
                    $result = $this->scanWithDefender($tmpFile, $config);
                    break;
            }
            
            @unlink($tmpFile);
            
            // $result가 null이면 에러
            if ($result === null) {
                return ['success' => false, 'error' => __('api_av_scan_result_error', '검사 결과를 받지 못했습니다.')];
            }
            
            // 에러가 있으면 실패
            if (isset($result['error'])) {
                return ['success' => false, 'error' => $result['error']];
            }
            
            // 테스트 파일이 바이러스로 인식되면 (오탐지지만 엔진은 작동 중)
            if (!$result['clean']) {
                $engineName = $engine === 'clamav' ? 'ClamAV' : 'Windows Defender';
                return [
                    'success' => true,
                    'engine' => $engine,
                    'message' => $engineName . ' ' . __('api_av_engine_working_false_positive', '작동 중 (테스트 파일 오탐지: ') . ($result['threat'] ?? 'unknown') . ')'
                ];
            }
            
            $engineName = $engine === 'clamav' ? 'ClamAV' : 'Windows Defender';
            return [
                'success' => true,
                'engine' => $engine,
                'message' => $engineName . ' ' . __('api_av_engine_working', '정상 작동 중')
            ];
        } catch (Exception $e) {
            @unlink($tmpFile);
            return ['success' => false, 'error' => __('api_av_exception', '예외 발생: ') . $e->getMessage()];
        }
    }
    
    // 파일 업로드
    public function upload(int $storageId, string $relativePath, array $file): array {
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => __('api_err_no_write_perm', '쓰기 권한이 없습니다.')];
        }
        
        // 용량 체크
        $fileSize = (int)($file['size'] ?? 0);
        $quotaCheck = $this->checkQuota($storageId, $fileSize);
        if (!$quotaCheck['allowed']) {
            return ['success' => false, 'error' => $quotaCheck['error']];
        }
        
        // 업로드 설정 검증 (확장자/크기 제한)
        $uploadCheck = $this->checkUploadSettings($file['name'], $fileSize);
        if (!$uploadCheck['allowed']) {
            return ['success' => false, 'error' => '📤 ' . $uploadCheck['reason']];
        }
        
        // 랜섬웨어 의심 확장자 체크
        $ransomCheck = $this->checkRansomwareExtension($file['name']);
        if (!$ransomCheck['allowed']) {
            return ['success' => false, 'error' => '🔐 ' . $ransomCheck['reason']];
        }
        
        // MIME 타입 검증 (확장자 위장 방지) — 업로드 설정에서 끌 수 있음
        $mimeCheckEnabled = $uploadCheck['mime_check'] ?? true;
        if ($mimeCheckEnabled && !$this->validateMimeType($file['tmp_name'], $file['name'])) {
            return ['success' => false, 'error' => __('file_type_invalid')];
        }
        
        // 바이러스 검사
        $virusScan = $this->scanForVirus($file['tmp_name'], $file['name'], $fileSize);
        if (!$virusScan['clean']) {
            $threat = $virusScan['threat'] ?? __('unknown_threat');
            return ['success' => false, 'error' => __('virus_detected') . $threat];
        }
        
        // 원격 스토리지인 경우
        if ($this->isRemoteStorage($storageId)) {
            return $this->uploadRemote($storageId, $relativePath, $file);
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $targetDir = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $targetDir)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!is_dir($targetDir)) {
            return ['success' => false, 'error' => __('api_err_target_folder_missing', '대상 폴더가 없습니다.')];
        }
        
        $filename = $this->sanitizeFilename($file['name']);
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        
        // 파일명 중복 처리 (덮어쓰기 안 함, 새 파일명 생성)
        $targetPath = $this->getUniqueFilename($targetPath);
        
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['success' => false, 'error' => __('api_err_file_save_failed', '파일 저장에 실패했습니다.')];
        }
        
        // 사용량 업데이트
        $uploadedSize = filesize($targetPath);
        $this->storage->updateUsedSize($storageId, $uploadedSize);
        
        return [
            'success' => true,
            'filename' => basename($targetPath),
            'size' => $uploadedSize
        ];
    }
    
    /**
     * 원격 스토리지 파일 업로드
     */
    private function uploadRemote(int $storageId, string $relativePath, array $file): array {
        $adapter = $this->getAdapter($storageId);
        if (!$adapter) {
            return ['success' => false, 'error' => $this->getLastAdapterError()];
        }
        
        try {
            $filename = $this->sanitizeFilename($file['name']);
            $remotePath = empty($relativePath) ? $filename : $relativePath . '/' . $filename;
            
            // 중복 파일명 처리
            $counter = 1;
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            while ($adapter->exists($remotePath)) {
                $newName = $ext ? "{$baseName}_{$counter}.{$ext}" : "{$baseName}_{$counter}";
                $remotePath = empty($relativePath) ? $newName : $relativePath . '/' . $newName;
                $counter++;
            }
            
            // 파일 내용 읽어서 업로드
            $content = file_get_contents($file['tmp_name']);
            if ($adapter->write($remotePath, $content)) {
                return [
                    'success' => true,
                    'filename' => basename($remotePath),
                    'size' => strlen($content),
                    'remote' => true
                ];
            }
            
            return ['success' => false, 'error' => __('api_err_remote_upload_failed', '원격 스토리지 업로드 실패')];
        } catch (Exception $e) {
            return ['success' => false, 'error' => __('upload_error_prefix') . $e->getMessage()];
        }
    }
    
    // 청크 업로드 (대용량)
    public function uploadChunk(int $storageId, string $relativePath, array $data, bool $skipPermCheck = false): array {
        // $skipPermCheck=true: 호출측이 이미 권한을 검증한 경우(내부 공유/외부 filedrop).
        //   스토리지 can_write 체크만 건너뜀. 확장자/랜섬웨어/quota/경로 검증은 그대로 수행(보안 유지).
        if (!$skipPermCheck && !$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => __('api_err_no_write_perm', '쓰기 권한이 없습니다.')];
        }
        
        // 첫 청크일 때만 랜섬웨어 확장자 체크
        $chunkIndex = (int)$data['chunkIndex'];
        if ($chunkIndex === 0) {
            // 업로드 설정 검증 (확장자/크기 제한)
            $totalSize = (int)($data['totalSize'] ?? 0);
            $uploadCheck = $this->checkUploadSettings($data['filename'], $totalSize);
            if (!$uploadCheck['allowed']) {
                return ['success' => false, 'error' => '📤 ' . $uploadCheck['reason']];
            }
            
            $ransomCheck = $this->checkRansomwareExtension($data['filename']);
            if (!$ransomCheck['allowed']) {
                return ['success' => false, 'error' => '🔐 ' . $ransomCheck['reason']];
            }
        }
        
        // 원격 스토리지인 경우 별도 처리
        if ($this->isRemoteStorage($storageId)) {
            return $this->uploadChunkRemote($storageId, $relativePath, $data);
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        
        $targetDir = $this->buildPath($basePath, $relativePath);
        
        // 경로 탐색 공격 방지
        if (!$this->isPathSafe($basePath, $targetDir)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!is_dir($targetDir)) {
            return ['success' => false, 'error' => __('api_err_target_folder_missing', '대상 폴더가 없습니다.')];
        }
        
        $filename = $this->sanitizeFilename($data['filename']);
        $chunkIndex = (int)$data['chunkIndex'];
        $totalChunks = (int)$data['totalChunks'];
        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['uploadId']); // 보안
        $totalSize = (int)($data['totalSize'] ?? 0);
        $lastModified = (int)($data['lastModified'] ?? 0);
        
        // 폴더 업로드인 경우 상대 경로 처리
        $fileRelativePath = $data['relativePath'] ?? null;
        if ($fileRelativePath) {
            // 상대 경로에서 폴더 부분 추출 (예: "MyFolder/SubFolder/file.txt" -> "MyFolder/SubFolder")
            $pathParts = explode('/', str_replace('\\', '/', $fileRelativePath));
            array_pop($pathParts); // 파일명 제거
            
            if (!empty($pathParts)) {
                // 안전한 경로로 변환
                $subPath = implode(DIRECTORY_SEPARATOR, array_map([$this, 'sanitizeFilename'], $pathParts));
                $targetDir = $targetDir . DIRECTORY_SEPARATOR . $subPath;
                
                // 필요한 폴더 생성
                if (!is_dir($targetDir)) {
                    if (!mkdir($targetDir, 0755, true)) {
                        return ['success' => false, 'error' => __('folder_create_fail') . $subPath];
                    }
                }
            }
        }
        
        // 첫 번째 청크일 때 용량 체크
        if ($chunkIndex === 0 && $totalSize > 0) {
            $quotaCheck = $this->checkQuota($storageId, $totalSize);
            if (!$quotaCheck['allowed']) {
                return ['success' => false, 'error' => $quotaCheck['error']];
            }
        }
        
        // 임시 청크 저장 디렉토리
        $tempDir = DATA_PATH . '/chunks/' . $uploadId;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        // duplicateAction 처리
        $duplicateAction = $data['duplicateAction'] ?? 'rename';
        
        // 메타 정보 저장 (첫 청크일 때)
        $metaFile = $tempDir . '/meta.json';
        if ($chunkIndex === 0) {
            file_put_contents($metaFile, json_encode([
                'filename' => $filename,
                'totalChunks' => $totalChunks,
                'totalSize' => $totalSize,
                'targetDir' => $targetDir,
                'lastModified' => $lastModified,
                'duplicateAction' => $duplicateAction,
                'startTime' => time()
            ]));
        }
        
        // 청크 파일 저장
        $chunkPath = $tempDir . '/chunk_' . str_pad($chunkIndex, 8, '0', STR_PAD_LEFT);
        
        if (isset($data['file']['tmp_name'])) {
            move_uploaded_file($data['file']['tmp_name'], $chunkPath);
        } else {
            return ['success' => false, 'error' => __('chunk_file_missing')];
        }
        
        // 업로드된 청크 수 확인
        $uploadedChunks = count(glob($tempDir . '/chunk_*'));
        
        // 모든 청크가 업로드되었는지 확인
        if ($uploadedChunks >= $totalChunks) {
            // ★ 조립 경쟁 조건 방지 (race condition):
            //   병렬 청크 업로드 시 마지막 청크들이 거의 동시 도착하면, 여러 요청이 모두
            //   "완성"으로 판정해 조립을 중복 실행 → 같은 파일이 2개 생기는 버그.
            //   fopen 'x' 모드(파일 없을 때만 생성, 원자적)로 락을 잡아 단 하나만 조립.
            //   파일 락이라 cleanupChunks(is_file→unlink)에서 정상 정리됨. glob('chunk_*')엔 안 걸림.
            $assembleLock = $tempDir . '/.assembling.lock';
            $lockFp = @fopen($assembleLock, 'x');
            if ($lockFp === false) {
                // 다른 요청이 이미 조립 중/완료 → 이 요청은 조립하지 않고 완료로 응답
                return [
                    'success' => true,
                    'complete' => true,
                    'filename' => $filename,
                    'deduped' => true
                ];
            }
            @fclose($lockFp);
            // 메타 정보 읽기
            $meta = json_decode(file_get_contents($metaFile), true);
            $dupAction = $meta['duplicateAction'] ?? 'rename';
            
            // 대상 파일 경로
            $originalPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
            
            // duplicateAction에 따른 처리
            if (file_exists($originalPath)) {
                switch ($dupAction) {
                    case 'skip':
                        // 건너뛰기: 청크 정리 후 종료
                        $this->cleanupChunks($tempDir);
                        return [
                            'success' => true,
                            'complete' => true,
                            'skipped' => true,
                            'filename' => $filename
                        ];
                    
                    case 'overwrite':
                        // 대량 덮어쓰기 감지
                        $bulkCheck = $this->checkBulkOperation('overwrite');
                        if (!$bulkCheck['allowed']) {
                            $this->cleanupChunks($tempDir);
                            return ['success' => false, 'error' => '🔐 ' . $bulkCheck['reason']];
                        }
                        
                        // 덮어쓰기 전 기존 파일 크기 차감
                        if (file_exists($originalPath)) {
                            $existingSize = is_dir($originalPath) ? $this->getDirectorySize($originalPath) : filesize($originalPath);
                            if ($existingSize > 0) {
                                $this->storage->updateUsedSize($storageId, -$existingSize);
                            }
                        }
                        
                        // 덮어쓰기 전 파일 버전 저장
                        $fileRelPath = ltrim($relativePath . '/' . $filename, '/');
                        $this->saveFileVersion($originalPath, $fileRelPath, $storageId);
                        
                        // 덮어쓰기: 기존 파일 삭제
                        @unlink($originalPath);
                        $targetPath = $originalPath;
                        break;
                    
                    case 'rename':
                    default:
                        // 이름 변경
                        $targetPath = $this->getUniqueFilename($originalPath);
                        break;
                }
            } else {
                $targetPath = $originalPath;
            }
            
            $outFile = fopen($targetPath, 'wb');
            if (!$outFile) {
                return ['success' => false, 'error' => __('file_create_fail')];
            }
            
            // 순서대로 병합
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkFile = $tempDir . '/chunk_' . str_pad($i, 8, '0', STR_PAD_LEFT);
                
                if (!file_exists($chunkFile)) {
                    fclose($outFile);
                    unlink($targetPath);
                    return ['success' => false, 'error' => __f('chunk_missing', ['i' => $i])];
                }
                
                $inFile = fopen($chunkFile, 'rb');
                while (!feof($inFile)) {
                    fwrite($outFile, fread($inFile, 1048576)); // 1MB 버퍼
                }
                fclose($inFile);
            }
            
            fclose($outFile);
            
            // 바이러스 검사 (병합된 파일에 대해)
            $finalSize = filesize($targetPath);
            $virusScan = $this->scanForVirus($targetPath, $filename, $finalSize);
            if (!$virusScan['clean']) {
                // 바이러스 발견 시 파일 삭제
                @unlink($targetPath);
                $this->cleanupChunks($tempDir);
                $threat = $virusScan['threat'] ?? __('unknown_threat');
                return ['success' => false, 'error' => __('virus_detected') . $threat];
            }
            
            // ★ MIME 타입 검증 (펜닐 v5.8.1e — 일반 업로드와 일관성)
            //   기존: 청크 업로드 로컬 경로에만 MIME 검증 누락 (원격 청크 업로드는 라인 1945에서 호출됨)
            //   업로드 설정의 mime_check 옵션 존중 (일반 업로드 라인 1493과 동일 패턴)
            $_chunkMimeCheck = $this->checkUploadSettings($filename, $finalSize);
            $_chunkMimeEnabled = $_chunkMimeCheck['mime_check'] ?? true;
            if ($_chunkMimeEnabled && !$this->validateMimeType($targetPath, $filename)) {
                @unlink($targetPath);
                $this->cleanupChunks($tempDir);
                return ['success' => false, 'error' => __('file_type_invalid')];
            }
            
            // 랜섬웨어 내용 검사 (엔트로피 + 시그니처)
            $contentCheck = $this->checkRansomwareContent($targetPath, $filename);
            if (!$contentCheck['allowed']) {
                @unlink($targetPath);
                $this->cleanupChunks($tempDir);
                return ['success' => false, 'error' => '🔐 ' . $contentCheck['reason']];
            }
            
            // 원본 파일의 수정 날짜 복원 (meta는 이미 위에서 읽음)
            $actualMtime = time(); // 기본값
            if (!empty($meta['lastModified']) && $meta['lastModified'] > 0) {
                $mtime = (int)$meta['lastModified'];
                
                // touch로 수정 시간 설정
                $result = @touch($targetPath, $mtime, $mtime);
                
                // 실패 시 재시도 (한 번만)
                if (!$result) {
                    usleep(50000); // 0.05초 대기 (기존 0.1초에서 단축)
                    @touch($targetPath, $mtime, $mtime);
                }
                $actualMtime = $mtime; // touch에서 설정한 값 사용
            }
            
            // 임시 파일 정리
            $this->cleanupChunks($tempDir);
            
            // 사용량 업데이트 (finalSize는 바이러스 검사 시 이미 계산됨)
            $this->storage->updateUsedSize($storageId, $finalSize);
            
            // 인덱스 업데이트 (clearstatcache 제거 - 불필요)
            $indexPath = empty($relativePath) ? basename($targetPath) : $relativePath . '/' . basename($targetPath);
            if ($fileRelativePath) {
                // 폴더 업로드인 경우 전체 상대 경로 사용
                $pathParts = explode('/', str_replace('\\', '/', $fileRelativePath));
                array_pop($pathParts);
                if (!empty($pathParts)) {
                    $indexPath = empty($relativePath) ? $fileRelativePath : $relativePath . '/' . implode('/', $pathParts) . '/' . basename($targetPath);
                }
            }
            $this->fileIndex->addFile($storageId, $indexPath, [
                'is_dir' => 0,
                'size' => filesize($targetPath),
                'modified' => date('Y-m-d H:i:s', $actualMtime)
            ]);
            
            return [
                'success' => true,
                'complete' => true,
                'filename' => basename($targetPath),
                'size' => filesize($targetPath),
                'mtime_set' => $meta['lastModified'] ?? 0,
                'mtime_actual' => $actualMtime
            ];
        }
        
        return [
            'success' => true,
            'complete' => false,
            'uploaded' => $uploadedChunks,
            'total' => $totalChunks,
            'percent' => round(($uploadedChunks / $totalChunks) * 100)
        ];
    }
    
    /**
     * 원격 스토리지용 청크 업로드
     * 청크를 로컬 임시 폴더에 저장 후 완료 시 원격 스토리지에 업로드
     */
    private function uploadChunkRemote(int $storageId, string $relativePath, array $data): array {
        $filename = $this->sanitizeFilename($data['filename']);
        $chunkIndex = (int)$data['chunkIndex'];
        $totalChunks = (int)$data['totalChunks'];
        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['uploadId']);
        $totalSize = (int)($data['totalSize'] ?? 0);
        $lastModified = (int)($data['lastModified'] ?? 0);
        $duplicateAction = $data['duplicateAction'] ?? 'rename';
        
        // 시스템 임시 디렉토리에 청크 저장
        $tempBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webhard_chunks_remote';
        if (!is_dir($tempBase)) {
            @mkdir($tempBase, 0755, true);
        }
        
        $tempDir = $tempBase . DIRECTORY_SEPARATOR . $uploadId;
        if (!is_dir($tempDir)) {
            if (!mkdir($tempDir, 0755, true)) {
                return ['success' => false, 'error' => __('temp_folder_create_fail')];
            }
        }
        
        // 청크 저장
        $chunkPath = $tempDir . '/chunk_' . str_pad($chunkIndex, 8, '0', STR_PAD_LEFT);
        if (!move_uploaded_file($data['file']['tmp_name'], $chunkPath)) {
            return ['success' => false, 'error' => __('chunk_save_fail')];
        }
        
        // 메타 정보 저장 (첫 청크)
        $metaFile = $tempDir . '/meta.json';
        if ($chunkIndex === 0) {
            file_put_contents($metaFile, json_encode([
                'filename' => $filename,
                'totalChunks' => $totalChunks,
                'totalSize' => $totalSize,
                'lastModified' => $lastModified,
                'relativePath' => $relativePath,
                'duplicateAction' => $duplicateAction
            ]));
        }
        
        // 업로드된 청크 수 확인
        $uploadedChunks = count(glob($tempDir . '/chunk_*'));
        
        // 모든 청크 완료
        if ($uploadedChunks >= $totalChunks) {
            // ★ 조립 경쟁 조건 방지 (로컬 uploadChunk와 동일):
            //   병렬 청크 동시 도착 시 중복 조립 → 파일 2개 생성 버그. 원자적 파일 락으로 단 하나만 조립.
            $assembleLock = $tempDir . '/.assembling.lock';
            $lockFp = @fopen($assembleLock, 'x');
            if ($lockFp === false) {
                return [
                    'success' => true,
                    'complete' => true,
                    'filename' => $filename,
                    'deduped' => true
                ];
            }
            @fclose($lockFp);
            // 메타 정보 읽기
            $meta = json_decode(file_get_contents($metaFile), true);
            
            // 임시 파일로 병합
            $tempFile = $tempDir . '/merged_' . $filename;
            $outFile = fopen($tempFile, 'wb');
            if (!$outFile) {
                return ['success' => false, 'error' => __('temp_file_create_fail')];
            }
            
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkFile = $tempDir . '/chunk_' . str_pad($i, 8, '0', STR_PAD_LEFT);
                if (!file_exists($chunkFile)) {
                    fclose($outFile);
                    return ['success' => false, 'error' => __f('chunk_missing', ['i' => $i])];
                }
                $inFile = fopen($chunkFile, 'rb');
                while (!feof($inFile)) {
                    fwrite($outFile, fread($inFile, 1048576));
                }
                fclose($inFile);
            }
            fclose($outFile);
            
            // 바이러스 검사 (병합된 파일에 대해)
            $mergedSize = filesize($tempFile);
            $virusScan = $this->scanForVirus($tempFile, $filename, $mergedSize);
            if (!$virusScan['clean']) {
                // 바이러스 발견 시 정리
                $this->cleanupChunks($tempDir);
                $threat = $virusScan['threat'] ?? __('unknown_threat');
                return ['success' => false, 'error' => __('virus_detected') . $threat];
            }
            
            // MIME 타입 검증 (병합된 최종 파일)
            if (!$this->validateMimeType($tempFile, $filename)) {
                $this->cleanupChunks($tempDir);
                return ['success' => false, 'error' => __('api_err_dangerous_file', '보안상 허용되지 않는 파일입니다.')];
            }
            
            // 랜섬웨어 내용 검사 (엔트로피 + 시그니처)
            $contentCheck = $this->checkRansomwareContent($tempFile, $filename);
            if (!$contentCheck['allowed']) {
                $this->cleanupChunks($tempDir);
                return ['success' => false, 'error' => '🔐 ' . $contentCheck['reason']];
            }
            
            // 원격 스토리지에 업로드
            $adapter = $this->getAdapter($storageId);
            if (!$adapter) {
                $this->cleanupChunks($tempDir);
                return ['success' => false, 'error' => $this->getLastAdapterError()];
            }
            
            // 원격 대상 경로 계산
            $remotePath = empty($relativePath) ? $filename : rtrim($relativePath, '/') . '/' . $filename;
            
            // 중복 처리
            if ($adapter->exists($remotePath)) {
                switch ($duplicateAction) {
                    case 'skip':
                        $this->cleanupChunks($tempDir);
                        return ['success' => true, 'complete' => true, 'skipped' => true, 'filename' => $filename];
                    case 'overwrite':
                        // 대량 덮어쓰기 감지
                        $bulkCheck = $this->checkBulkOperation('overwrite');
                        if (!$bulkCheck['allowed']) {
                            $this->cleanupChunks($tempDir);
                            return ['success' => false, 'error' => '🔐 ' . $bulkCheck['reason']];
                        }
                        // 덮어쓰기 전 기존 파일 크기 차감 (adapter->getSize 사용)
                        $existingSize = method_exists($adapter, 'getSize') ? (int)$adapter->getSize($remotePath) : 0;
                        if ($existingSize > 0) {
                            $this->storage->updateUsedSize($storageId, -$existingSize);
                        }
                        $adapter->delete($remotePath);
                        break;
                    case 'rename':
                    default:
                        $baseName = pathinfo($filename, PATHINFO_FILENAME);
                        $ext = pathinfo($filename, PATHINFO_EXTENSION);
                        $counter = 1;
                        do {
                            $newName = $ext ? "{$baseName} ({$counter}).{$ext}" : "{$baseName} ({$counter})";
                            $remotePath = empty($relativePath) ? $newName : rtrim($relativePath, '/') . '/' . $newName;
                            $counter++;
                        } while ($adapter->exists($remotePath));
                        $filename = $newName;
                        break;
                }
            }
            
            // 파일 읽고 원격에 쓰기
            $content = file_get_contents($tempFile);
            $uploadResult = $adapter->write($remotePath, $content);
            
            // 임시 파일 정리
            $this->cleanupChunks($tempDir);
            
            if ($uploadResult) {
                // 원격 스토리지 사용량 증가
                $this->storage->updateUsedSize($storageId, $totalSize);
                return [
                    'success' => true,
                    'complete' => true,
                    'filename' => $filename,
                    'size' => $totalSize,
                    'remote' => true
                ];
            }
            
            return ['success' => false, 'error' => __('api_err_remote_upload_failed', '원격 스토리지 업로드 실패')];
        }
        
        // 진행 중
        return [
            'success' => true,
            'complete' => false,
            'uploaded' => $uploadedChunks,
            'total' => $totalChunks,
            'percent' => round(($uploadedChunks / $totalChunks) * 100)
        ];
    }
    
    // 청크 임시 파일 정리
    private function cleanupChunks(string $tempDir): void {
        if (!is_dir($tempDir)) return;
        
        $files = glob($tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($tempDir);
    }
    
    // 오래된 청크 정리 (1일 이상)
    public function cleanupOldChunks(): int {
        $chunksDir = DATA_PATH . '/chunks';
        if (!is_dir($chunksDir)) return 0;
        
        $cleaned = 0;
        $dirs = glob($chunksDir . '/*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $metaFile = $dir . '/meta.json';
            $shouldClean = false;
            
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                if (time() - ($meta['startTime'] ?? 0) > 86400) {
                    $shouldClean = true;
                }
            } else {
                // 메타 파일 없으면 디렉토리 수정 시간 기준
                if (time() - filemtime($dir) > 86400) {
                    $shouldClean = true;
                }
            }
            
            if ($shouldClean) {
                $this->cleanupChunks($dir);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
    
    // 썸네일 생성/반환
    public function thumbnail(int $storageId, string $relativePath, int $size = 200): void {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            http_response_code(403);
            exit;
        }
        
        // 사이즈 제한
        $size = max(50, min(400, $size));
        
        // 원격 스토리지는 미지원
        if ($this->isRemoteStorage($storageId)) {
            http_response_code(404);
            exit;
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $fullPath) || !is_file($fullPath)) {
            http_response_code(404);
            exit;
        }
        
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        
        // 썸네일 캐시 디렉토리
        $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }
        $cacheDir = $dataDir . DIRECTORY_SEPARATOR . 'thumbcache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
            // 캐시 디렉토리 생성/쓰기 불가 시 직접 출력 모드로 전환
            // (캐시 없이 매번 생성)
        }
        
        // 캐시 키 생성 (파일경로 + 수정시간 + 사이즈)
        $mtime = filemtime($fullPath);
        $cacheKey = md5($fullPath . '|' . $mtime . '|' . $size);
        $cachePath = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.jpg';
        
        // 캐시된 썸네일이 있으면 바로 반환
        if (is_file($cachePath)) {
            $this->sendThumbnail($cachePath);
        }
        
        // 이미지 파일 썸네일
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        if (in_array($ext, $imageExts)) {
            $this->generateImageThumbnail($fullPath, $cachePath, $size);
            if (is_file($cachePath)) {
                $this->sendThumbnail($cachePath);
            }
        }
        
        // 동영상 썸네일 (ffmpeg 필요)
        $videoExts = ['mp4', 'webm', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'ts', 'm2ts', 'mts', 'mpg', 'mpeg', 'm4v', '3gp'];
        if (in_array($ext, $videoExts)) {
            try {
                $this->generateVideoThumbnail($fullPath, $cachePath, $size);
            } catch (\Throwable $e) {
                // 에러 로그 (500 디버깅용)
                @file_put_contents($cachePath . '.fail', date('Y-m-d H:i:s') . ' Exception in generateVideoThumbnail: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
            }
            if (is_file($cachePath)) {
                $this->sendThumbnail($cachePath);
            }
        }
        
        // PDF 썸네일 (Imagick 필요)
        if ($ext === 'pdf') {
            $this->generatePdfThumbnail($fullPath, $cachePath, $size);
            if (is_file($cachePath)) {
                $this->sendThumbnail($cachePath);
            }
        }
        
        // MP3 썸네일 (1단계: ID3v2 APIC, 2단계: 폴더 커버 이미지, 3단계: 204)
        // 204 사용: 'MP3 커버 없음'은 에러가 아닌 정상 상황 (유튜브 MP3 등 매우 흔함)
        // 사용자 요청: 그리드뷰 탐색기 콘솔에 빨간 404 에러 안 뜨게 해달라
        if ($ext === 'mp3') {
            // 네거티브 캐시 마커 먼저 체크 (이미 커버 없음으로 판명된 파일)
            $mp3NoCoverMarker = $cachePath . '.nocover';
            if (is_file($mp3NoCoverMarker)) {
                // "커버 없음" 알려진 파일 — 파싱 건너뛰고 204 즉시 반환
                http_response_code(204);
                header('Cache-Control: public, max-age=86400');
                exit;
            }
            
            // 1단계: MP3 내장 ID3 커버 시도
            $this->generateMp3Thumbnail($fullPath, $cachePath, $size);
            if (is_file($cachePath)) {
                $this->sendThumbnail($cachePath);
            }
            
            // 2단계: ID3 없으면 같은 폴더의 커버 이미지 찾기 (cover.jpg, folder.jpg 등)
            // 플레이어의 JS 로직과 동일한 이름 우선순위 사용
            $folderCoverPath = $this->findFolderCoverImage($fullPath);
            if ($folderCoverPath !== null) {
                // 폴더 커버 이미지를 썸네일로 리사이즈 (기존 이미지 썸네일 로직 재사용)
                $this->generateImageThumbnail($folderCoverPath, $cachePath, $size);
                if (is_file($cachePath)) {
                    $this->sendThumbnail($cachePath);
                }
            }
            
            // 3단계: ID3도 없고 폴더 커버도 없음 → 네거티브 캐시 마커 저장 + 204
            // 204 No Content: 브라우저 콘솔에 빨간 에러 표시 안 됨
            @file_put_contents($mp3NoCoverMarker, '1');
            http_response_code(204);
            header('Cache-Control: public, max-age=86400');
            exit;
        }
        
        // 썸네일 생성 실패
        http_response_code(404);
        exit;
    }
    
    // MP3 파일이 있는 폴더에서 커버 이미지 파일 찾기
    // 플레이어 JS 로직과 동일한 이름 우선순위:
    //   cover, folder, album, front, thumb, thumbnail, artwork, albumart, albumartsmall
    // 지원 확장자: jpg, jpeg, png, webp, gif, bmp
    // 우선순위 이름이 없으면 폴더의 첫 번째 이미지 파일 반환 (자연 정렬)
    // 대소문자 무관
    //
    // @return string|null 커버 이미지 파일의 전체 경로
    private function findFolderCoverImage(string $audioFilePath): ?string {
        $dir = dirname($audioFilePath);
        if (!is_dir($dir)) return null;
        
        $coverNames = ['cover','folder','album','front','thumb','thumbnail','artwork','albumart','albumartsmall'];
        $imgExts = ['jpg','jpeg','png','webp','gif','bmp'];
        
        // 폴더 내 파일 목록 (에러 억제 — 권한 문제 등)
        $files = @scandir($dir);
        if ($files === false) return null;
        
        // 우선순위 1: 지정 이름 매칭 (대소문자 무관)
        foreach ($coverNames as $coverName) {
            foreach ($imgExts as $imgExt) {
                foreach ($files as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $fLower = strtolower($f);
                    $targetLower = $coverName . '.' . $imgExt;
                    if ($fLower === $targetLower) {
                        $fullPath = $dir . DIRECTORY_SEPARATOR . $f;
                        if (is_file($fullPath)) return $fullPath;
                    }
                }
            }
        }
        
        // 우선순위 2: 폴더 내 첫 번째 이미지 파일 (자연 정렬)
        $imageFiles = [];
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, $imgExts, true)) {
                $fullPath = $dir . DIRECTORY_SEPARATOR . $f;
                if (is_file($fullPath)) {
                    $imageFiles[] = $f;
                }
            }
        }
        
        if (!empty($imageFiles)) {
            natcasesort($imageFiles);
            $first = reset($imageFiles);
            return $dir . DIRECTORY_SEPARATOR . $first;
        }
        
        return null;
    }
    
    // MP3 파일의 ID3v2 APIC 프레임을 썸네일로 변환
    // 1. extractID3v2Cover()로 커버 이미지 바이트 획득
    // 2. GD/Imagick으로 리사이즈해서 $size에 맞춤
    // 3. JPEG로 $cachePath에 저장
    //
    // 디버그: 실패 시 data/thumbcache/mp3_debug.log에 기록 (진단용)
    private function generateMp3Thumbnail(string $fullPath, string $cachePath, int $size): void {
        // 디버그 로그 비활성화 (배포): 필요 시 아래 원본 로직 복원
        // $debugLog = dirname($cachePath) . DIRECTORY_SEPARATOR . 'mp3_debug.log';
        // $logFail = function($reason) use ($debugLog, $fullPath) {
        //     if (is_file($debugLog) && @filesize($debugLog) > 500 * 1024) {
        //         @unlink($debugLog);
        //     }
        //     @file_put_contents(
        //         $debugLog,
        //         date('Y-m-d H:i:s') . ' [' . basename($fullPath) . '] ' . $reason . "\n",
        //         FILE_APPEND
        //     );
        // };
        $logFail = function($reason) { /* no-op: mp3_debug.log 비활성화됨 */ };
        
        if (!is_file($fullPath)) {
            $logFail('file not found: ' . $fullPath);
            return;
        }
        if (!is_readable($fullPath)) {
            $logFail('file not readable: ' . $fullPath);
            return;
        }
        
        $cover = $this->extractID3v2Cover($fullPath);
        if (!$cover || empty($cover['data'])) {
            $logFail('no ID3v2 APIC frame (filesize=' . @filesize($fullPath) . ')');
            return;  // ID3 커버 없음 → 404로 이어짐
        }
        
        $coverSize = strlen($cover['data']);
        $coverMime = $cover['mime'] ?? 'unknown';
        
        // 이미지 바이너리를 GD로 로드 → 리사이즈 → JPEG로 저장
        // GD가 없으면 썸네일 생성 포기 → 호출부의 폴더 이미지 폴백으로 자연스럽게 넘어감
        // (원본 이미지를 .jpg 확장자 + Content-Type: image/jpeg로 반환하면 nosniff 헤더 때문에 
        //  PNG/WebP 커버가 브라우저에서 거부됨 — 안전하게 스킵)
        if (!extension_loaded('gd')) {
            $logFail('GD extension not loaded — skipping to folder image fallback');
            return;
        }
        
        // ID3 커버 이미지 바이너리를 GD로 로드
        // imagecreatefromstring은 JPEG/PNG/GIF/WebP 자동 감지 (GD 지원 형식)
        $srcImg = @imagecreatefromstring($cover['data']);
        if (!$srcImg) {
            $logFail('imagecreatefromstring failed (mime=' . $coverMime . ', size=' . $coverSize . ') — 폴더 이미지 폴백으로');
            return;
        }
        
        $srcW = imagesx($srcImg);
        $srcH = imagesy($srcImg);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($srcImg);
            $logFail('invalid image dimensions: ' . $srcW . 'x' . $srcH);
            return;
        }
        
        // 정사각형 크롭 + 리사이즈 (중앙 크롭)
        $cropSize = min($srcW, $srcH);
        $cropX = intdiv($srcW - $cropSize, 2);
        $cropY = intdiv($srcH - $cropSize, 2);
        
        $dstImg = imagecreatetruecolor($size, $size);
        // 투명 배경 안전 처리 (PNG 등)
        imagefilledrectangle($dstImg, 0, 0, $size, $size, imagecolorallocate($dstImg, 0, 0, 0));
        
        imagecopyresampled(
            $dstImg, $srcImg,
            0, 0,
            $cropX, $cropY,
            $size, $size,
            $cropSize, $cropSize
        );
        
        // JPEG로 저장 (품질 85)
        $saved = @imagejpeg($dstImg, $cachePath, 85);
        if (!$saved) {
            $logFail('imagejpeg save failed: ' . $cachePath);
        }
        
        imagedestroy($srcImg);
        imagedestroy($dstImg);
    }
    
    // 썸네일 이미지 전송 (출력 버퍼 정리 + 헤더 재설정)
    private function sendThumbnail(string $cachePath): void {
        // 0바이트 파일 방어 (디스크 가득 참 등으로 쓰기 실패한 경우)
        $fsize = @filesize($cachePath);
        if ($fsize === false || $fsize === 0) {
            @unlink($cachePath);  // 손상된 캐시 삭제
            http_response_code(404);
            exit;
        }
        // 출력 버퍼링 완전 비활성화
        while (ob_get_level()) {
            ob_end_clean();
        }
        // 기존 헤더 제거 후 이미지 헤더 설정
        header_remove('Content-Security-Policy');
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . $fsize);
        readfile($cachePath);
        exit;
    }
    
    // 이미지 썸네일 생성
    private function generateImageThumbnail(string $srcPath, string $cachePath, int $size): void {
        try {
            $imageInfo = @getimagesize($srcPath);
            if (!$imageInfo) return;
            
            $mime = $imageInfo['mime'];
            $srcW = $imageInfo[0];
            $srcH = $imageInfo[1];
            
            if ($srcW <= 0 || $srcH <= 0) return;
            
            // 메모리 예상치 체크 (픽셀당 ~4바이트 * 안전계수 2)
            $estimatedMem = $srcW * $srcH * 4 * 2;
            $memLimit = $this->getMemoryLimitBytes();
            $memUsed = memory_get_usage(true);
            if ($memLimit > 0 && ($memUsed + $estimatedMem) > $memLimit * 0.8) {
                return; // 메모리 부족 예상 시 스킵
            }
            
            // 원본 로드
            switch ($mime) {
                case 'image/jpeg': $src = @imagecreatefromjpeg($srcPath); break;
                case 'image/png': $src = @imagecreatefrompng($srcPath); break;
                case 'image/gif': $src = @imagecreatefromgif($srcPath); break;
                case 'image/webp': $src = @imagecreatefromwebp($srcPath); break;
                case 'image/bmp': $src = @imagecreatefrombmp($srcPath); break;
                default: return;
            }
            
            if (!$src) return;
            
            // EXIF 회전 처리 (JPEG)
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($srcPath);
                if ($exif && isset($exif['Orientation'])) {
                    switch ($exif['Orientation']) {
                        case 3: $src = imagerotate($src, 180, 0); break;
                        case 6: $src = imagerotate($src, -90, 0); 
                                list($srcW, $srcH) = [$srcH, $srcW]; break;
                        case 8: $src = imagerotate($src, 90, 0);
                                list($srcW, $srcH) = [$srcH, $srcW]; break;
                    }
                }
            }
            
            // 원본이 썸네일보다 작으면 그대로 저장
            if ($srcW <= $size && $srcH <= $size) {
                imagejpeg($src, $cachePath, 85);
                imagedestroy($src);
                return;
            }
            
            // 비율 계산 (cover 방식 - 정사각형 크롭)
            $ratio = max($size / $srcW, $size / $srcH);
            $newW = (int)round($srcW * $ratio);
            $newH = (int)round($srcH * $ratio);
            $offsetX = (int)round(($newW - $size) / 2);
            $offsetY = (int)round(($newH - $size) / 2);
            
            $thumb = imagecreatetruecolor($size, $size);
            
            // 빠른 리샘플링 (원본이 매우 크면 2단계로)
            if ($srcW > $size * 4 || $srcH > $size * 4) {
                // 1단계: imagecopyresized로 중간 사이즈까지 빠르게 축소
                $midW = $newW * 2;
                $midH = $newH * 2;
                $mid = imagecreatetruecolor($midW, $midH);
                imagecopyresized($mid, $src, 0, 0, 0, 0, $midW, $midH, $srcW, $srcH);
                imagedestroy($src);
                // 2단계: imagecopyresampled로 고품질 최종 리사이즈
                imagecopyresampled($thumb, $mid, -$offsetX, -$offsetY, 0, 0, $newW, $newH, $midW, $midH);
                imagedestroy($mid);
            } else {
                imagecopyresampled($thumb, $src, -$offsetX, -$offsetY, 0, 0, $newW, $newH, $srcW, $srcH);
                imagedestroy($src);
            }
            
            imagejpeg($thumb, $cachePath, 75);
            imagedestroy($thumb);
        } catch (\Throwable $e) {
            // 실패 시 무시
        }
    }
    
    private function getMemoryLimitBytes(): int {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') return 0; // 무제한
        $unit = strtolower(substr($limit, -1));
        $val = (int)$limit;
        switch ($unit) {
            case 'g': $val *= 1024 * 1024 * 1024; break;
            case 'm': $val *= 1024 * 1024; break;
            case 'k': $val *= 1024; break;
        }
        return $val;
    }
    
    // Windows에서 escapeshellarg가 !를 제거하는 문제 우회
    private function escapeShellPath(string $path): string {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: 큰따옴표로 감싸고, 내부의 큰따옴표만 이스케이프
            $path = str_replace('"', '""', $path);
            // 환경 변수 확장 방지: % → %% (큰따옴표 안에서 %VAR% 확장 차단)
            $path = str_replace('%', '%%', $path);
            return '"' . $path . '"';
        }
        return escapeshellarg($path);
    }
    
    /**
     * 압축 비밀번호를 안전하게 명령행 -p 인자로 변환.
     *
     * ⚠️ 중요: 비밀번호 문자를 함부로 "제거"하면 안 된다. 과거에 위험문자라며
     *   $ ` " \ 등을 지워버려서, 사용자가 "12#$"를 입력해도 "12#"만 7z/UnRAR에
     *   전달되어 "비밀번호 틀림"이 나는 버그가 있었다(로그로 확정).
     *   → 제어문자(\x00-\x1F, \x7F)만 제거하고, 셸 인용은 플랫폼 규칙대로 처리한다.
     *
     * Windows: bat 파일 안에서 -p"..." 형태로 실행된다.
     *   - cmd가 환경변수 확장하는 % 는 %% 로 이스케이프
     *   - 큰따옴표(")는 bat의 -p"..." 인용을 깨므로 불가피하게 제거(7-Zip도 CLI로 " 포함 비번 입력 불가 — 알려진 한계)
     *   - 나머지($ # & | < > ^ 등)는 큰따옴표 안에서 안전하므로 그대로 둔다.
     * Linux: escapeshellarg가 모든 문자를 안전 처리하므로 원본 그대로 넘긴다.
     *
     * @param string $password 원본 비밀번호
     * @return string " -p\"...\"" 또는 " -p'...'" 형태의 명령행 조각(앞 공백 포함). 빈 비번이면 ' -p""'.
     */
    private function buildPasswordArg(string $password): string {
        // 제어문자만 제거 (명령행 자체를 깨뜨리는 문자) — 일반 특수문자는 보존
        $pw = preg_replace('/[\x00-\x1F\x7F]/', '', $password);
        if ($pw === '') {
            // 빈 비번: 헤더 암호화 아카이브가 프롬프트로 멈추지 않도록 -p"" 지정
            return (DIRECTORY_SEPARATOR === '\\') ? ' -p""' : " -p''";
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows(bat): % → %%, " → 제거(인용 불가), 나머지 보존
            $pw = str_replace('%', '%%', $pw);
            $pw = str_replace('"', '', $pw);
            return ' -p"' . $pw . '"';
        }
        // Linux: escapeshellarg가 $ ` " \ 등 전부 안전 처리
        return ' -p' . escapeshellarg($pw);
    }
    
    /**
     * 7-Zip/UnRAR의 출력·추출 파일명을 환경(로케일)과 무관하게 UTF-8로 처리하기 위한 명령행 prefix.
     * Linux/시놀로지 도커 등에서 LANG/LC_CTYPE이 UTF-8이 아니면(C, POSIX 등) 비ASCII 파일명이 깨지므로
     * UTF-8 로케일을 강제한다. Windows는 chcp 65001로 처리하므로 빈 문자열.
     * (api.php의 $this->utf8EnvPrefix()와 동일 규칙. FileManager가 단독 require될 때를 대비해 자체 메서드로 보유.)
     */
    private function utf8EnvPrefix(): string {
        if (DIRECTORY_SEPARATOR === '\\') return '';
        return 'LANG=C.UTF-8 LC_ALL=C.UTF-8 ';
    }
    
    /**
     * 추출된 파일명이 환경(로케일) 문제로 '?'(0x3F) 등으로 깨진 경우, 이미 정상 추출된 파일의
     * 내용은 그대로 두고 파일명만 압축 목록(UTF-8 정확) 기준으로 rename하여 복원한다.
     *
     * 배경: 시놀로지 등 비UTF-8 로케일 p7zip은 추출 시 디스크 파일명을 '?'로 치환하지만 내용은 정상.
     *   (`7z x -so <한글경로>` 재추출은 해당 빌드에서 한글 경로 매칭이 안 돼 실패하므로 사용하지 않는다.)
     *
     * 동작:
     *   1) 목록(-slt -sccUTF-8)으로 정확한 UTF-8 파일 경로+크기를 얻는다.
     *   2) 그 경로가 디스크에 없으면(=깨짐) 보정 대상. 모두 존재하면 즉시 종료(정상 환경, 회귀 없음).
     *   3) 디스크의 '깨진 이름' 파일을 수집하고, (경로 깊이 + 파일 크기)로 목록 항목과 유일 매칭되는 것만 rename.
     *      매칭이 모호하면(같은 깊이·크기 다수) 건드리지 않는다(데이터 안전 우선).
     *   4) preExisting(추출 이전부터 있던 사용자 파일)은 어떤 이름이든 절대 건드리지 않는다.
     *
     * rename은 PHP가 바이트 경로로 수행하므로 로케일과 무관하다(시놀로지에서도 동작).
     */
    private function fixExtractedFilenames(string $sevenZipBin, string $archivePath, string $extractDir, string $password, callable $dbg, bool $dbgOn, bool $isHereMode = false, array $preExisting = []): void {
        if (!is_dir($extractDir)) return;
        
        // 2) 먼저 목록으로 정확한 UTF-8 경로를 얻는다 (감지·복원 모두에 사용)
        $listCmd = $this->escapeShellPath($sevenZipBin) . ' l -slt -sccUTF-8' . $this->buildPasswordArg($password) . ' ' . $this->escapeShellPath($archivePath);
        if (DIRECTORY_SEPARATOR === '\\') {
            $listOut = @shell_exec('chcp 65001 >nul && ' . $listCmd . ' 2>&1 < nul');
        } else {
            $listOut = @shell_exec($this->utf8EnvPrefix() . $listCmd . ' 2>&1 < /dev/null');
        }
        if (!$listOut) { $dbg("[파일명보정] 목록 획득 실패 → 중단(원본 보존)"); return; }
        
        // -slt 파싱: Path / Folder / Size / Attributes
        $entries = [];
        $cur = [];
        foreach (preg_split('/\r?\n/', $listOut) as $line) {
            if ($line === '') {
                if (!empty($cur['Path'])) $entries[] = $cur;
                $cur = [];
                continue;
            }
            if (preg_match('/^(Path|Folder|Size|Attributes) = (.*)$/', $line, $m)) {
                $cur[$m[1]] = $m[2];
            }
        }
        if (!empty($cur['Path'])) $entries[] = $cur;
        if (empty($entries)) { $dbg("[파일명보정] 목록 파싱 결과 없음 → 중단(원본 보존)"); return; }
        
        // 1) 깨짐 감지: 목록의 UTF-8 상대경로가 디스크에 그대로 존재하는지 확인.
        //    존재하지 않으면(=치환/깨짐) 보정 대상. '?'치환(0x3F)도 잡고, 합법적 '?'파일명은 오판 안 함.
        $sep = DIRECTORY_SEPARATOR;
        $expectedFiles = []; // rel(UTF-8) => size
        foreach ($entries as $e) {
            $p = str_replace('\\', '/', $e['Path']);
            if ($p === '' || $p === $archivePath) continue;
            $isDir = (isset($e['Folder']) && $e['Folder'] === '+')
                  || (isset($e['Attributes']) && strpos($e['Attributes'], 'D') === 0);
            if ($isDir) continue;
            // 경로 안전성: 절대경로/.. 차단
            if (strpos($p, '..') !== false || strpos($p, ':') !== false || $p[0] === '/' || $p[0] === '\\') continue;
            $expectedFiles[$p] = (int)($e['Size'] ?? -1);
        }
        if (empty($expectedFiles)) { $dbg("[파일명보정] 목록에 파일 항목 없음 → 스킵"); return; }
        
        $missingRels = [];
        foreach ($expectedFiles as $rel => $sz) {
            $disk = $extractDir . $sep . str_replace('/', $sep, $rel);
            if (!is_file($disk)) $missingRels[$rel] = $sz;
        }
        if (empty($missingRels)) {
            $dbg("[파일명보정] 목록 경로가 디스크에 모두 정상 존재 → 보정 불필요");
            return;
        }
        $dbg("[파일명보정] 목록 대비 디스크에 없는 파일 " . count($missingRels) . "/" . count($expectedFiles) . "개(파일명 깨짐 추정) → 이름 매칭 복원 시작");
        
        // 2) 디스크에서 '깨진(비UTF-8/?) 이름'이고 '추출 이전부터 있던 게 아닌(preExisting 제외)' 파일을 수집.
        //    내용은 7z이 정상 추출했으므로(이름만 깨짐), 재추출하지 않고 목록 기준으로 rename만 한다.
        //    매칭 키: 같은 부모 디렉토리(깨진 경로는 디렉토리명도 깨졌을 수 있으므로 '깊이' 기준) + 파일 크기.
        //    안전: 매칭이 모호하면(같은 크기 다수 등) 그 항목은 건드리지 않는다.
        $brokenFiles = []; // [ ['path'=>절대경로, 'size'=>크기, 'depth'=>깊이, 'ext'=>확장자] ]
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $full = $f->getPathname();
            $rel = substr($full, strlen($extractDir) + 1);
            $relU = str_replace('\\', '/', $rel);
            // 목록에 정상 존재하는 파일(정상 추출본)·기존 파일은 제외
            if (isset($expectedFiles[$relU]) && !isset($missingRels[$relU])) continue;
            // preExisting(추출 이전부터 있던 사용자 파일) 최상위는 보호
            $topName = explode($sep, $rel)[0];
            if (isset($preExisting[$topName])) continue;
            // 깨진 이름(비UTF-8 또는 ?)만 대상
            $base = $f->getFilename();
            $pathHasBroken = (!mb_check_encoding($rel, 'UTF-8')) || strpos($rel, '?') !== false;
            if (!$pathHasBroken) continue;
            $brokenFiles[] = [
                'path' => $full,
                'size' => $f->getSize(),
                'depth' => substr_count($relU, '/'),
            ];
        }
        
        if (empty($brokenFiles)) { $dbg("[파일명보정] 깨진 디스크 파일 없음 → 스킵(원본 보존)"); return; }
        
        // 3) missingRels(목록의 UTF-8 경로) ↔ brokenFiles(디스크 깨진 파일) 매칭.
        //    (깊이 + 크기)가 유일하게 일치하는 것만 확정 매칭. 모호하거나 크기 정보가 없으면 건드리지 않음(안전).
        //    단, 깨진 파일이 1개뿐이고 목록의 깨진 항목도 1개뿐이면(단일 파일 압축) 크기 없이도 깊이로 매칭 허용.
        $renamed = 0; $ambiguous = 0;
        $usedBroken = [];
        $singleCase = (count($brokenFiles) === 1 && count($missingRels) === 1);
        foreach ($missingRels as $rel => $sz) {
            $depth = substr_count($rel, '/');
            // 후보: 아직 안 쓰였고, 깊이 같고, 크기도 같은(또는 단일 파일 케이스) 깨진 파일
            $cands = [];
            foreach ($brokenFiles as $i => $bf) {
                if (isset($usedBroken[$i])) continue;
                if ($bf['depth'] !== $depth) continue;
                // 크기 검증: 크기 정보가 있으면 반드시 일치해야 함. 없으면(파싱실패) 단일 케이스만 허용.
                if ($sz >= 0) {
                    if ($bf['size'] === $sz) $cands[] = $i;
                } elseif ($singleCase) {
                    $cands[] = $i;
                }
            }
            if (count($cands) === 1) {
                $bi = $cands[0];
                $destAbs = $extractDir . $sep . str_replace('/', $sep, $rel);
                $dp = dirname($destAbs);
                if (!is_dir($dp)) @mkdir($dp, 0755, true);
                // 충돌 시 기존 파일을 덮어쓰지 않고 이름 변경으로 보존(여기에풀기/폴더 모드 공통).
                if (file_exists($destAbs)) $destAbs = $this->getUniqueFilename($destAbs);
                if (@rename($brokenFiles[$bi]['path'], $destAbs)) {
                    $usedBroken[$bi] = true;
                    $renamed++;
                }
            } else {
                $ambiguous++;
            }
        }
        $dbg("[파일명보정] 이름 매칭 복원: 성공 $renamed / 모호(미처리) $ambiguous / 깨진파일 " . count($brokenFiles) . "개");
        
        // 4) 빈 깨진 디렉토리 정리 (rename 후 남은 깨진 폴더 껍데기). preExisting·정상 폴더는 보호.
        $dit = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($dit as $f) {
            if (!$f->isDir()) continue;
            $rel = substr($f->getPathname(), strlen($extractDir) + 1);
            $topName = explode($sep, $rel)[0];
            if (isset($preExisting[$topName])) continue;
            $name = $f->getFilename();
            $broken = (!mb_check_encoding($rel, 'UTF-8')) || strpos($rel, '?') !== false;
            if (!$broken) continue;
            // 비어있을 때만 제거 (안에 뭔가 남아있으면 안전하게 보존)
            @rmdir($f->getPathname()); // 비어있지 않으면 rmdir 실패 → 보존
        }
        $dbg("[파일명보정] 완료: $renamed 개 파일 UTF-8 이름으로 복원" . ($isHereMode ? ' (여기에풀기, 기존파일 보존)' : ''));
    }
    
    // Windows 8.3 짧은 경로명 가져오기 (비ASCII 파일명 문제 해결)
    private function getWindowsShortPath(string $path): ?string {
        if (PHP_OS_FAMILY !== 'Windows') return null;
        if (!file_exists($path)) return null;
        
        try {
            // COM 객체를 사용한 짧은 경로 가져오기
            if (class_exists('COM')) {
                $fso = new \COM('Scripting.FileSystemObject');
                if (is_dir($path)) {
                    $obj = $fso->GetFolder($path);
                } else {
                    $obj = $fso->GetFile($path);
                }
                $shortPath = $obj->ShortPath;
                if ($shortPath && $shortPath !== $path) {
                    return $shortPath;
                }
            }
        } catch (\Exception $e) {}
        
        // fallback: dir /X
        $parent = dirname($path);
        $basename = basename($path);
        $output = shell_exec('dir /X ' . $this->escapeShellPath($parent) . ' 2>NUL');
        if ($output && preg_match('/\s([A-Z0-9~]+\.\S+)\s+' . preg_quote($basename, '/') . '/i', $output, $m)) {
            return $parent . DIRECTORY_SEPARATOR . $m[1];
        }
        
        return null;
    }
    
    // 동영상 실시간 트랜스코딩 스트리밍 (ts, mkv, avi, wmv 등 → mp4)
    // 동영상 실시간 트랜스코딩 스트리밍 (fragmented MP4 pipe)
    public function transcodeStream(int $storageId, string $path): void {

        // Pipe kill 요청 (GET 또는 sendBeacon POST)
        $pipeKillSid = $_GET['pipe_kill'] ?? $_POST['pipe_kill'] ?? null;
        if ($pipeKillSid) {
            $sid = $pipeKillSid;
            if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[a-f0-9]+$/', $sid)) {
                $output = shell_exec('wmic process where "name=\'ffmpeg.exe\'" get processid,commandline /format:csv 2>NUL');
                if ($output) {
                    foreach (explode("\n", $output) as $line) {
                        if (strpos($line, 'pipesid_' . $sid) !== false && preg_match('/,(\d+)\s*$/', $line, $m)) {
                            exec('taskkill /F /PID ' . (int)$m[1] . ' 2>NUL');
                        }
                    }
                }
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        
        // 보안: 읽기 권한 체크
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            http_response_code(403);
            echo json_encode(['error' => 'No permission']);
            exit;
        }
        
        $realPath = $this->storage->getRealPath($storageId);
        if (!$realPath) {
            http_response_code(404);
            echo json_encode(['error' => 'Storage not found']);
            exit;
        }
        
        $fullPath = $realPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        // 보안: path traversal 방어
        if (!$this->isPathSafe($realPath, $fullPath)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid path']);
            exit;
        }
        if (!is_file($fullPath)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            exit;
        }
        
        $ffmpeg = $this->findFfmpeg();
        if (!$ffmpeg) {
            http_response_code(500);
            $disArr = array_map('trim', explode(',', @ini_get('disable_functions') ?: ''));
            $execBlocked = !function_exists('exec') || in_array('exec', $disArr);
            $hint = $execBlocked ? ' (exec function is disabled in PHP)' : ' (check ffmpeg path in admin settings)';
            header('X-Transcode-Error: ffmpeg not available' . $hint);
            echo json_encode(['error' => 'ffmpeg not available' . $hint]);
            exit;
        }
        
        // proc_open 사용 가능 여부 체크 (트랜스코딩 필수)
        $disArr2 = array_map('trim', explode(',', @ini_get('disable_functions') ?: ''));
        if (!function_exists('proc_open') || in_array('proc_open', $disArr2)) {
            http_response_code(500);
            header('X-Transcode-Error: proc_open disabled');
            echo json_encode(['error' => 'proc_open function is disabled in PHP. Required for video streaming.']);
            exit;
        }
        
        // 브라우저 네이티브 지원 포맷은 트랜스코딩 불필요
        // 단, info=1 요청은 먼저 처리 (코덱/인코더 정보 필요)
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $nativeFormats = ['mp4', 'webm', 'ogg'];
        if (in_array($ext, $nativeFormats) && !isset($_GET['info'])) {
            header('Location: api.php?action=download&storage_id=' . $storageId . '&path=' . urlencode($path) . '&inline=1');
            exit;
        }
        
        set_time_limit(0);
        // 모든 PHP 출력 버퍼 제거
        while (ob_get_level()) ob_end_clean();
        
        // Apache mod_deflate 비활성화
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
        
        // Windows 한글 경로 → 8.3 짧은 경로 (ffmpeg 비ASCII 경로 문제 해결)
        $ffmpegInput = $fullPath;
        if (PHP_OS_FAMILY === 'Windows') {
            $shortPath = $this->getWindowsShortPath($fullPath);
            if ($shortPath) $ffmpegInput = $shortPath;
        }
        $inputPath = $this->escapeShellPath($ffmpegInput);
        
        // 오디오/비디오 트랙 정보 조회
        if (isset($_GET['info'])) {
            $probeCmd = escapeshellarg($ffmpeg) . ' -i ' . $inputPath . ' 2>&1';
            $output = shell_exec($probeCmd);
            
            $audioTracks = [];
            if (preg_match_all('/Stream\s+#(\d+):(\d+)(?:\(([a-z]+)\))?:\s*Audio:\s*(\w+)[^,]*,\s*(\d+)\s*Hz[^,]*,\s*([^,\r\n]+)/i', $output, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $streamIdx = $m[2];
                    $lang = $m[3] ?? '';
                    $codec = strtoupper($m[4]);
                    $channels = trim($m[6]);
                    
                    $title = '';
                    if (preg_match('/Stream\s+#' . preg_quote($m[1] . ':' . $m[2]) . '.*\n\s*Metadata:\s*\n\s*title\s*:\s*(.+)/i', $output, $tm)) {
                        $title = trim($tm[1]);
                    }
                    
                    $audioTracks[] = [
                        'index' => (int)$streamIdx,
                        'language' => $lang,
                        'codec' => $codec,
                        'channels' => $channels,
                        'title' => $title,
                    ];
                }
            }
            
            // duration 추출
            $duration = 0;
            if (preg_match('/Duration:\s*(\d+):(\d+):(\d+)\.(\d+)/', $output, $dm)) {
                $duration = (int)$dm[1] * 3600 + (int)$dm[2] * 60 + (int)$dm[3] + (int)$dm[4] / 100;
            }
            
            // 비디오 코덱 정보 추출
            $videoCodec = '';
            $videoResolution = '';
            if (preg_match('/Stream\s+#\d+:\d+.*Video:\s*(\w+)/i', $output, $vm)) {
                $videoCodec = strtolower($vm[1]);
            }
            if (preg_match('/(\d{2,5})x(\d{2,5})/', $output, $rm)) {
                $videoResolution = $rm[1] . 'x' . $rm[2];
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'audio_tracks' => $audioTracks,
                'encoder' => $this->getHwEncoderInfo(),
                'duration' => $duration,
                'video_codec' => $videoCodec,
                'video_resolution' => $videoResolution,
            ]);
            exit;
        }
        
        // seek 요청 처리
        $seekSec = isset($_GET['seek']) ? max(0, (float)$_GET['seek']) : 0;
        $seekArg = $seekSec > 0 ? ' -ss ' . $seekSec : '';
        
        // 오디오 트랙 선택 (항상 비디오 1개 + 오디오 1개만 매핑)
        if (isset($_GET['audio'])) {
            $audioIdx = (int)$_GET['audio'];
            $audioMap = ' -map 0:v:0 -map 0:' . $audioIdx;
        } else {
            // 기본: 첫 번째 비디오 + 첫 번째 오디오만
            $audioMap = ' -map 0:v:0 -map 0:a:0';
        }
        
        // ★ [HLS_DIAG] transcode 액션 audio 매핑 결과 (펜닐님 진단용 — 임시) — 주석처리됨 (다음 디버깅 위해 보존)
        //   다시 활성 필요 시 아래 블록 주석 제거
        /*
        $_diagFile = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/hls_diag.log';
        $_diagDir = dirname($_diagFile);
        if (is_dir($_diagDir) || @mkdir($_diagDir, 0755, true)) {
            $_audioParam = $_GET['audio'] ?? '(none)';
            $_diagLine = '[' . date('Y-m-d H:i:s') . '] [SERVER] transcode_audio_map | '
                . json_encode([
                    'audio_param' => $_audioParam,
                    'audio_map' => trim($audioMap),
                    'path' => basename($inputPath),
                    'session' => $_GET['session'] ?? null
                ]);
            @file_put_contents($_diagFile, $_diagLine . "\n", FILE_APPEND | LOCK_EX);
        }
        */
        
        // 하드웨어 인코더 자동 감지
        // iOS는 소프트웨어 인코더 강제
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isIOS = (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iPod') !== false);
        
        // 원본 코덱 확인 (H.264면 비디오 복사 가능)
        $probeCmd2 = escapeshellarg($ffmpeg) . ' -i ' . $inputPath . ' 2>&1';
        $probeOut2 = shell_exec($probeCmd2) ?? '';
        $isH264Input = (bool)preg_match('/Video:\s*h264/i', $probeOut2);
        
        if (isset($_GET['sw'])) {
            // 명시적 SW 요청
            $videoCodecArgs = '-c:v libx264 -preset veryfast -crf 23 -profile:v high -level 4.1 -pix_fmt yuv420p';
        } elseif ($isH264Input && !$isIOS) {
            // PC + H.264 입력 → 비디오 복사
            $videoCodecArgs = '-c:v copy';
        } else {
            // HW 인코더 사용 (iOS 포함)
            $videoCodecArgs = $this->detectHwEncoder($ffmpeg);
        }
        
        // ★ Quality 옵션 처리 (v5.8.1c — MMS 모드)
        //   quality 요청이 있으면 -c:v copy 분기를 풀어서 SW 인코딩으로 전환
        //   (copy 모드는 비트레이트/scale 적용 불가)
        $mmsQuality = isset($_GET['quality']) ? trim($_GET['quality']) : 'original';
        if ($mmsQuality !== 'original' && $mmsQuality !== '') {
            // copy 모드면 SW로 전환 (비트레이트 적용 위해)
            if (strpos($videoCodecArgs, '-c:v copy') !== false) {
                $videoCodecArgs = '-c:v libx264 -preset veryfast -crf 23 -profile:v high -level 4.1 -pix_fmt yuv420p';
            }
        }
        
        // 실시간 트랜스코딩
        $devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        
        // HW 인코더가 아닌 경우에만 -g 옵션 추가
        $gopArgs = '';
        if (strpos($videoCodecArgs, 'libx264') !== false) {
            $gopArgs = ' -g 24 -keyint_min 24';
        }
        

        
        // iOS MMS: ffmpeg는 최대 속도로 실행, PHP에서 출력 속도 조절
        // PC/Android: 최대 속도 (직접 src이므로 브라우저가 알아서 버퍼링)
        $reFlag = '';
        // 클라이언트 제공 sid 또는 서버 생성 sid (pipe_kill에 사용)
        if (isset($_GET['client_sid']) && preg_match('/^[a-z0-9]+$/', $_GET['client_sid'])) {
            $pipeSid = $_GET['client_sid'];
        } else {
            $pipeSid = md5($inputPath . $audioMap . microtime(true));
        }
        
        // iOS: HW 인코더 시도 (CPU 점유율 절감)
        // ?ios_sw=1 파라미터로 강제 SW 모드 가능 (클라이언트 fallback용)
        // iOS: 1080p 다운스케일 + 비트레이트 제한 (인코딩 속도 확보)
        $scaleFilter = '';
        if ($isIOS) {
            // 원본 해상도 확인 — 1080p 이상이면 다운스케일
            $probeRes = shell_exec(escapeshellarg($ffmpeg) . ' -i ' . $inputPath . ' 2>&1');
            if (preg_match('/(\d{3,5})x(\d{3,5})/', $probeRes ?? '', $resMatch)) {
                $srcW = (int)$resMatch[1];
                $srcH = (int)$resMatch[2];
                if ($srcH > 1080 || $srcW > 1920) {
                    $scaleFilter = ' -vf "scale=1920:-2"';
                }
            }
        }
        
        if ($isIOS && !isset($_GET['ios_sw'])) {
            $hwArgs = $this->detectHwEncoder($ffmpeg);
            if (strpos($hwArgs, 'libx264') === false) {
                // HW 인코더 + iOS 호환 + 비트레이트 제한
                $videoCodecArgs = $hwArgs . ' -b:v 6M -maxrate 8M -bufsize 12M';
                $gopArgs = ' -g 48 -keyint_min 48';
            } else {
                // SW 인코더 + 비트레이트 제한
                $videoCodecArgs = '-c:v libx264 -preset veryfast -crf 23 -profile:v high -level 4.1 -pix_fmt yuv420p -b:v 6M -maxrate 8M -bufsize 12M';
                $gopArgs = ' -g 48 -keyint_min 48';
            }
        } elseif ($isIOS) {
            // ios_sw=1: 강제 SW 모드 + 비트레이트 제한
            $videoCodecArgs = '-c:v libx264 -preset veryfast -crf 23 -profile:v high -level 4.1 -pix_fmt yuv420p -b:v 6M -maxrate 8M -bufsize 12M';
            $gopArgs = ' -g 48 -keyint_min 48';
        }
        
        // ★ Quality 옵션 적용 (v5.8.1c — MMS 모드, iOS 분기 후)
        //   사용자 quality 선택이 있으면 iOS의 6M 비트레이트보다 우선
        //   원본/미지정이면 원본 동작 유지
        $mmsQualityVf = '';
        if ($mmsQuality !== 'original' && $mmsQuality !== '') {
            $mmsQualityResult = $this->applyQualityToArgs($videoCodecArgs, $mmsQuality);
            $videoCodecArgs = $mmsQualityResult['codecArgs'];
            $mmsQualityVf = $mmsQualityResult['vfPrepend'];
            // scaleFilter (iOS 1080p 다운스케일)와 quality scale 충돌 시 quality 우선
            if ($mmsQualityVf !== '') {
                $scaleFilter = ''; // 기존 iOS 다운스케일 무시 (quality scale로 통합)
            }
        }
        
        // 응답 헤더로 세션 ID 전달 (클라이언트가 kill 시 사용)
        header('X-Pipe-Sid: ' . $pipeSid);
        
        // CSP 제거 & 스트리밍 헤더
        header_remove('Content-Security-Policy');
        header_remove('X-Content-Type-Options');
        header('Content-Type: video/mp4');
        header('Cache-Control: no-cache, no-store');
        header('Accept-Ranges: none');
        
        // ffmpeg 프로세스 실행 (HW 인코더 실패 시 SW fallback)
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $stderrLog = $dataDir . '/ffmpeg_pipe_stderr.log';
        
        // HW 인코더 사용 시 비정상 프레임레이트 대응: -r 30 추가
        $fpsArg = '';
        if (strpos($videoCodecArgs, 'libx264') === false && strpos($videoCodecArgs, 'copy') === false) {
            $fpsArg = ' -r 30';
        }
        
        $buildCmd = function($vCodec, $gop, $fps) use ($ffmpeg, $seekArg, $reFlag, $inputPath, $audioMap, $pipeSid, $scaleFilter, $mmsQualityVf) {
            // ★ scaleFilter (iOS 1080p) + mmsQualityVf (사용자 quality scale) 합치기
            //   둘 다 -vf로 시작하지 않고 필터 표현식만 들어있음
            //   scaleFilter 형식: ' -vf "scale=1920:-2"' (이미 -vf 포함)
            //   mmsQualityVf 형식: 'scale=\'-2:min(720\\,ih)\'' (필터만)
            $vfArg = '';
            if ($mmsQualityVf !== '') {
                // quality vf 단독 사용 (iOS scaleFilter는 위에서 ''로 클리어됨)
                $vfArg = ' -vf "' . $mmsQualityVf . '"';
            } else {
                // 기존 iOS scaleFilter 사용
                $vfArg = $scaleFilter;
            }
            return escapeshellarg($ffmpeg)
                . $seekArg
                . ' -analyzeduration 2000000 -probesize 2000000'
                . ' -fflags +genpts+igndts+fastseek'
                . $reFlag
                . ' -i ' . $inputPath
                . $audioMap
                . ' -sn'
                . $vfArg
                . ' -metadata comment=pipesid_' . $pipeSid
                . ' ' . $vCodec
                . $gop
                . $fps
                . ' -c:a aac -b:a 128k -ac 2'
                . ' -output_ts_offset 0'
                . ' -movflags frag_keyframe+empty_moov+default_base_moof'
                . ' -frag_duration 500000'
                . ' -f mp4 pipe:1';
        };
        
        $cmd = $buildCmd($videoCodecArgs, $gopArgs, $fpsArg);
        
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $stderrLog, 'w'],
        ];
        
        $process = proc_open($cmd, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            http_response_code(500);
            echo 'Failed to start ffmpeg';
            exit;
        }
        
        fclose($pipes[0]);
        
        // 첫 데이터 읽기 시도 (HW 인코더 실패 감지)
        // 타임아웃 적용: HW ffmpeg가 초기화에서 hang(데이터 안 줌+안 죽음)하면 무한 대기 방지.
        //   6초 내 데이터 오면 읽고, 안 오면 '' 반환(=기존 폴백 로직 트리거).
        //   정상 재생(데이터 즉시 옴)은 기존과 동일 — hang일 때만 새로 구제됨.
        $firstChunk = $this->_readFirstChunkWithTimeout($pipes[1], 6);
        
        // HW 인코더 실패: 데이터 없음 → SW fallback
        if (($firstChunk === false || $firstChunk === '') && strpos($videoCodecArgs, 'libx264') === false && strpos($videoCodecArgs, 'copy') === false) {
            fclose($pipes[1]);
            proc_terminate($process, 9);
            proc_close($process);
            
            // libx264로 재시도
            $videoCodecArgs = '-c:v libx264 -preset veryfast -crf 23 -profile:v high -level 4.1 -pix_fmt yuv420p';
            $gopArgs = ' -g 24 -keyint_min 24';
            $cmd = $buildCmd($videoCodecArgs, $gopArgs, '');
            
            // stderr 로그에 fallback 기록
            file_put_contents($stderrLog, "\n--- SW FALLBACK ---\n", FILE_APPEND);
            $stderrLog2 = $dataDir . '/ffmpeg_pipe_stderr2.log';
            $descriptors[2] = ['file', $stderrLog2, 'w'];
            
            $process = proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($process)) {
                http_response_code(500);
                echo 'Failed to start ffmpeg (SW fallback)';
                exit;
            }
            fclose($pipes[0]);
            $firstChunk = $this->_readFirstChunkWithTimeout($pipes[1], 6);
        }
        
        // MMS endstreaming 시 클라이언트가 일시적으로 읽기를 멈추므로
        // ignore_user_abort(true)로 설정하여 PHP가 조기 종료되지 않게 함
        // 단, connection_aborted() 체크를 강화하여 실제 끊김 시 빠르게 종료
        ignore_user_abort(true);
        register_shutdown_function(function() use ($process, &$pipes) {
            if (is_resource($pipes[1] ?? null)) @fclose($pipes[1]);
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        });
        
        // stdout → 클라이언트 스트리밍 + 캐시 파일 동시 저장
        $bytesSent = 0;
        $startTime = microtime(true);
        
        // 첫 chunk 전송
        if ($firstChunk !== false && $firstChunk !== '') {
            echo $firstChunk;
            @ob_flush();
            @flush();
            $bytesSent += strlen($firstChunk);
        }
        
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === false || $chunk === '') break;
            
            echo $chunk;
            @ob_flush();
            @flush();
            $bytesSent += strlen($chunk);
            
            // 클라이언트 끊김 감지 (flush 후 확인)
            if (connection_aborted()) {
                break;
            }
        }
        
        fclose($pipes[1]);
        proc_terminate($process, 9);
        proc_close($process);
        exit;
    }

    /**
     * HLS 스트리밍 (Jellyfin 방식)
     * start: ffmpeg foreground 실행 → HLS 세그먼트를 디스크에 생성 (long-running 요청)
     * playlist: m3u8 서빙 (세그먼트 URL 치환)
     * segment: .ts 세그먼트 서빙
     * stop: ffmpeg 종료 + 파일 정리
     */
    public function hlsStream(int $storageId, string $path): void {
        $action = $_GET['hls_action'] ?? 'start';
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $hlsDir = $dataDir . '/hls_sessions';
        
        // === playlist/segment 서빙 ===
        if ($action === 'playlist' || $action === 'segment') {
            $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['session'] ?? '');
            if (!$sessionId) { http_response_code(400); exit; }
            
            $sessionDir = $hlsDir . '/' . $sessionId;
            if (!is_dir($sessionDir)) { http_response_code(404); exit; }
            
            // HLS 재생 로그
            $playLog = $dataDir . '/hls_playback.log';
            $plog = function($msg) use ($playLog, $sessionId) {
                file_put_contents($playLog, date('H:i:s') . " [$sessionId] $msg\n", FILE_APPEND);
            };
            
            if ($action === 'playlist') {
                $m3u8 = $sessionDir . '/stream.m3u8';
                
                // [HLSDebug] data/hls_timing.log 파일이 있을 때만 기록
                $dataDir3 = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
                $timingLog = $dataDir3 . '/hls_timing.log';
                $hlsTimingEnabled = is_file($timingLog);
                if ($hlsTimingEnabled) {
                    @file_put_contents($timingLog, date('H:i:s.') . sprintf('%03d', (int)((microtime(true) - floor(microtime(true))) * 1000)) . " [$sessionId] PLAYLIST request arrived\n", FILE_APPEND);
                }
                
                // m3u8 파일 상태 로그
                $m3u8Exists = file_exists($m3u8);
                $m3u8Size = $m3u8Exists ? filesize($m3u8) : 0;
                $segCount = count(glob($sessionDir . '/stream*.ts'));
                $plog("playlist request: m3u8=" . ($m3u8Exists ? $m3u8Size . 'b' : 'NOT_FOUND') . " segs=$segCount");
                
                // ★ 빠른 실패 감지 + m3u8 생성 대기 (최대 5초)
                //   기존 15초 고정 대기 → 3가지 조건 중 먼저 발생하는 것에 반응
                //   A) m3u8 생성됨 → 성공
                //   B) ffmpeg 프로세스 죽음 (pid.txt 기반) → 실패 확정
                //   C) ffmpeg.log에 치명적 실패 키워드 → 실패 확정
                //   → HW 실패 시 평균 1~2초 내 감지 (기존 15초 → 3~5배 빨라짐)
                $pidFile = $sessionDir . '/pid.txt';
                $ffLog = $sessionDir . '/ffmpeg.log';
                $fatalLogPatterns = [
                    '/Conversion failed!/i',                     // ffmpeg 최종 실패
                    '/Could not open encoder before EOF/i',      // HW 인코더 초기화 실패
                    '/Nothing was written into output file/i',   // 출력 파일 미작성
                    '/Invalid argument\b.*Terminating thread/i', // 프로세스 비정상 종료
                ];
                $waitStart = microtime(true);
                $maxWait = 5.0;
                $ffmpegDied = false;
                $logFatalDetected = false;
                $waited = 0;
                $pollIter = 0;  // 폴링 횟수 카운터 (로그 체크를 300ms 주기로만)
                while (!file_exists($m3u8) && $waited < $maxWait) {
                    usleep(100000); // 100ms (기존 300ms → 3배 빠른 폴링)
                    $waited = microtime(true) - $waitStart;
                    $pollIter++;
                    
                    // A) 프로세스 생존 체크 (300ms 주기로만 - tasklist 호출 비용 고려)
                    if ($waited > 0.3 && file_exists($pidFile) && ($pollIter % 3 === 0)) {
                        $pid = (int)@file_get_contents($pidFile);
                        if ($pid > 0) {
                            if (PHP_OS_FAMILY === 'Windows') {
                                $check = @shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
                                if (!$check || strpos($check, (string)$pid) === false) {
                                    $ffmpegDied = true;
                                    break;
                                }
                            } else {
                                if (!file_exists('/proc/' . $pid)) {
                                    $ffmpegDied = true;
                                    break;
                                }
                            }
                        }
                    }
                    
                    // B) 치명적 로그 키워드 감지 (300ms 주기로만)
                    if ($waited > 0.3 && file_exists($ffLog) && ($pollIter % 3 === 0)) {
                        $logTail = '';
                        // 끝부분 2KB만 효율적으로 읽기 (파일 작으면 전체 읽음)
                        $logSize = @filesize($ffLog);
                        if ($logSize > 0) {
                            $fh = @fopen($ffLog, 'r');
                            if ($fh) {
                                $readFrom = max(0, $logSize - 2048);
                                @fseek($fh, $readFrom, SEEK_SET);
                                $logTail = @fread($fh, 2048) ?: '';
                                @fclose($fh);
                            }
                        }
                        if ($logTail !== '') {
                            foreach ($fatalLogPatterns as $pat) {
                                if (preg_match($pat, $logTail)) {
                                    $logFatalDetected = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
                $waitedMs = (int)((microtime(true) - $waitStart) * 1000);
                if ($hlsTimingEnabled) {
                    $reason = file_exists($m3u8) ? 'm3u8_ok' : ($ffmpegDied ? 'process_died' : ($logFatalDetected ? 'log_fatal' : 'timeout'));
                    @file_put_contents($timingLog, date('H:i:s.') . sprintf('%03d', (int)((microtime(true) - floor(microtime(true))) * 1000)) . " [$sessionId] PLAYLIST m3u8_wait={$waitedMs}ms, found=" . (file_exists($m3u8) ? 'YES' : 'NO') . ", segs=" . count(glob($sessionDir . '/stream*.ts')) . ", reason=$reason\n", FILE_APPEND);
                }
                
                if (!file_exists($m3u8)) {
                    // ffmpeg.log 에러 내용 분석
                    $ffLogDump = '(no log)';
                    $hwErrorDetected = false;
                    
                    // 프로세스 죽음 또는 로그 키워드 감지되면 HW 실패로 간주
                    // (HW 실패 외 다른 이유일 수도 있지만, libx264 재시도 시 어차피 원본 에러도 확인됨)
                    if ($ffmpegDied || $logFatalDetected) {
                        $hwErrorDetected = true;
                    }
                    
                    if (file_exists($ffLog)) {
                        $logText = file_get_contents($ffLog);
                        // 끝부분 800자 - 에러는 보통 끝부분에 나옴
                        $ffLogDump = substr(trim($logText), -800);
                        $ffLogDump = str_replace(["\r", "\n"], ' | ', $ffLogDump);
                        
                        // HW 인코더 관련 에러 키워드 (QSV/NVENC/AMF 고유 문자열만 사용)
                        // 정상 ffmpeg 로그에 흔히 등장하는 "Cannot open", "Error initializing" 같은
                        // 일반 문자열은 제외 — 오탐으로 HW→SW 영구 전환되는 것 방지.
                        // 여기서는 HW 초기화 실패 고유 키워드만 좁혀서 매칭.
                        $hwErrorPatterns = [
                            '/MFX session:\s+fail/i',                   // QSV: MFX 세션 초기화 실패
                            '/No capable devices found/i',              // QSV/NVENC: HW 장치 없음
                            '/not supported by the QSV runtime/i',       // QSV: 옵션 거부
                            '/\[h264_qsv[^\]]*\].*Error while opening/i', // QSV 인코더 열기 실패
                            '/\[hevc_qsv[^\]]*\].*Error while opening/i',
                            '/\[h264_nvenc[^\]]*\].*failed/i',           // NVENC 실패
                            '/\[hevc_nvenc[^\]]*\].*failed/i',
                            '/\[h264_amf[^\]]*\].*failed/i',             // AMF 실패
                        ];
                        foreach ($hwErrorPatterns as $pattern) {
                            if (preg_match($pattern, $logText)) {
                                $hwErrorDetected = true;
                                break;
                            }
                        }
                        if ($hlsTimingEnabled) {
                            @file_put_contents($timingLog, date('H:i:s.') . sprintf('%03d', (int)((microtime(true) - floor(microtime(true))) * 1000)) . " [$sessionId] PLAYLIST m3u8 NOT FOUND after wait. hw_err=" . ($hwErrorDetected ? 'YES' : 'NO') . " | FF_LOG: $ffLogDump\n", FILE_APPEND);
                        }
                        // ★ 전역 hw_encoder_cache.json 은 절대 건드리지 않음
                        // 특정 파일이 HW로 처리 못 된다고 해서 모든 파일을 CPU로 바꾸면 안 됨.
                        // 이 세션만 libx264로 폴백하고 다른 파일은 계속 HW 사용.
                    }
                    
                    // ★ HW 에러 감지 시 이 요청 내에서 libx264로 복구 시도
                    // meta.json에 저장해둔 sw_cmd로 ffmpeg 재실행하고 m3u8 생성 대기
                    if ($hwErrorDetected) {
                        $metaFile = $sessionDir . '/meta.json';
                        $meta = file_exists($metaFile) ? @json_decode(@file_get_contents($metaFile), true) : null;
                        $swCmd = is_array($meta) ? ($meta['sw_cmd'] ?? null) : null;
                        $swStderrLog = is_array($meta) ? ($meta['stderr_log'] ?? ($sessionDir . '/ffmpeg.log')) : ($sessionDir . '/ffmpeg.log');
                        
                        if ($swCmd) {
                            // ★ 동시 요청 방지: 락 파일로 한 번만 재실행
                            //   여러 playlist 요청이 동시에 실패 감지하면 libx264가 N번 실행될 수 있음
                            //   → 같은 hls_segment_filename에 여러 프로세스가 쓰면 파일 꼬임
                            $swLockFile = $sessionDir . '/sw_fallback.lock';
                            $lockFp = @fopen($swLockFile, 'c');
                            if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
                                // 락 획득 → 이 요청이 재실행 주도
                                // 락 해제는 m3u8 생성 완료 후
                            } else {
                                // 다른 요청이 이미 재실행 중 → m3u8 생성 대기만 수행
                                $plog('SW fallback already in progress — waiting for m3u8');
                                if ($lockFp) @fclose($lockFp);
                                $waitOtherStart = microtime(true);
                                while (!file_exists($m3u8) && (microtime(true) - $waitOtherStart) < 8) {
                                    usleep(100000);
                                }
                                if (file_exists($m3u8)) {
                                    goto sw_fallback_done;
                                } else {
                                    http_response_code(404); exit;
                                }
                            }
                            
                            $plog('HW encoder failed — retrying with libx264');
                            // 이전 실패 흔적 정리 (세그먼트 잔재, m3u8은 애초에 없음)
                            foreach (glob($sessionDir . '/stream*.ts') as $oldSeg) { @unlink($oldSeg); }
                            
                            // ffmpeg.log에 구분선 추가 (디버그용)
                            @file_put_contents($swStderrLog, "\n=== HW encoder failed, retrying with libx264 ===\n", FILE_APPEND);
                            
                            // ★ 기존 pid.txt 삭제 (죽은 HW ffmpeg PID가 남아있음)
                            //   새 libx264 프로세스 PID로 갱신해야 playlist 핸들러의
                            //   "ffmpegRunning" 체크가 올바르게 동작함 (안 하면 EXT-X-ENDLIST 오주입)
                            $pidFileForSw = $sessionDir . '/pid.txt';
                            @unlink($pidFileForSw);
                            
                            // libx264로 백그라운드 재실행
                            if (PHP_OS_FAMILY === 'Windows') {
                                $bgCmd = 'start "" /B ' . $swCmd . ' 2>>' . escapeshellarg($swStderrLog);
                                @pclose(@popen($bgCmd, 'r'));
                            } else {
                                $bgCmd = $swCmd . ' >> ' . escapeshellarg($swStderrLog) . ' 2>&1 & echo $! > ' . escapeshellarg($pidFileForSw);
                                $wrapper = 'nohup sh -c ' . escapeshellarg($bgCmd) . ' > /dev/null 2>&1 &';
                                if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', @ini_get('disable_functions') ?: '')))) {
                                    @exec($wrapper);
                                } else {
                                    $ph = @popen($wrapper, 'r');
                                    if (is_resource($ph)) @pclose($ph);
                                }
                            }
                            
                            // m3u8 생성 다시 대기 (최대 15초)
                            // libx264 m3u8 생성 대기 (최대 8초, 100ms 폴링)
                            // libx264는 일반적으로 2~3초 내 첫 세그먼트 생성 — 여유 있게 8초
                            $retryWaitStart = microtime(true);
                            $retryWaited = 0;
                            while (!file_exists($m3u8) && $retryWaited < 8) {
                                usleep(100000); // 100ms
                                $retryWaited = microtime(true) - $retryWaitStart;
                            }
                            
                            if (file_exists($m3u8)) {
                                $retrySec = number_format($retryWaited, 2);
                                $plog("libx264 retry succeeded after {$retrySec}s");
                                
                                // ★ Windows: 새 libx264 프로세스 PID 찾아서 pid.txt 갱신
                                //   (Linux는 쉘 래퍼에서 $! > pid.txt 로 이미 기록됨)
                                //   wmic으로 세션ID가 들어간 ffmpeg 프로세스 검색
                                //   ※ unlink 실패 케이스 대비: 기존 파일 있어도 wmic으로 재확인 후 덮어쓰기
                                if (PHP_OS_FAMILY === 'Windows') {
                                    $wmic = @shell_exec('wmic process where "name=\'ffmpeg.exe\'" get processid,commandline /format:csv 2>NUL');
                                    if ($wmic) {
                                        foreach (explode("\n", $wmic) as $line) {
                                            if (strpos($line, $sessionId) !== false && preg_match('/,(\d+)\s*$/', trim($line), $pm)) {
                                                @file_put_contents($pidFileForSw, $pm[1]);
                                                break;
                                            }
                                        }
                                    }
                                }
                                
                                // ★ meta.json의 current_encoder를 libx264로 갱신 (배지 업데이트용)
                                $metaData = @json_decode(@file_get_contents($metaFile), true) ?: [];
                                $metaData['current_encoder'] = 'libx264';
                                $metaData['sw_fallback_applied'] = true;
                                @file_put_contents($metaFile, json_encode($metaData));
                                
                                if ($hlsTimingEnabled) {
                                    @file_put_contents($timingLog, date('H:i:s.') . sprintf('%03d', (int)((microtime(true) - floor(microtime(true))) * 1000)) . " [$sessionId] SW_FALLBACK_SUCCESS after {$retrySec}s\n", FILE_APPEND);
                                }
                                // 락 해제 (다른 요청들도 진행 가능)
                                if (isset($lockFp) && $lockFp) {
                                    @flock($lockFp, LOCK_UN);
                                    @fclose($lockFp);
                                }
                                // m3u8 생성 성공 — 아래 정상 흐름으로 계속 진행
                            } else {
                                $plog('libx264 retry also failed, giving up');
                                if ($hlsTimingEnabled) {
                                    @file_put_contents($timingLog, date('H:i:s.') . sprintf('%03d', (int)((microtime(true) - floor(microtime(true))) * 1000)) . " [$sessionId] SW_FALLBACK_FAILED\n", FILE_APPEND);
                                }
                                // 락 해제
                                if (isset($lockFp) && $lockFp) {
                                    @flock($lockFp, LOCK_UN);
                                    @fclose($lockFp);
                                }
                                http_response_code(404); exit;
                            }
                        } else {
                            // sw_cmd 없음 (force_sw=1로 시작했거나 libx264가 이미 기본이었음)
                            http_response_code(404); exit;
                        }
                    } else {
                        // HW 에러 아닌 다른 이유로 m3u8 생성 실패 (소스 파일 손상 등)
                        http_response_code(404); exit;
                    }
                }
                sw_fallback_done:
                
                // 세션 디렉토리 touch — cleanup에서 재생 중 세션 보호
                @touch($sessionDir);
                // ★ 클라이언트 마지막 접근 시간 기록 (고아 ffmpeg 판정용)
                @file_put_contents($sessionDir . '/last_access.txt', (string)time());
                
                // 디버그 로그 (재생 상태 추적) - 배포 시 비활성화 (필요 시 주석 복원)
                // $hlsLog = $dataDir . '/hls_debug.log';
                $tsFiles = glob($sessionDir . '/stream*.ts');
                $tsCount = $tsFiles ? count($tsFiles) : 0;
                $m3u8Size = filesize($m3u8);
                $hasEndList = (strpos(file_get_contents($m3u8), '#EXT-X-ENDLIST') !== false);
                $ffmpegRunning = false;
                $pidFile = $sessionDir . '/pid.txt';
                if (file_exists($pidFile)) {
                    $pid = (int)file_get_contents($pidFile);
                    if (PHP_OS_FAMILY === 'Windows') {
                        $check = shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
                        $ffmpegRunning = ($check && strpos($check, (string)$pid) !== false);
                    } else {
                        $ffmpegRunning = file_exists('/proc/' . $pid);
                    }
                }
                // file_put_contents($hlsLog, date('H:i:s') . " playlist session=$sessionId ts=$tsCount m3u8=" . $m3u8Size . "B endlist=" . ($hasEndList ? 'Y' : 'N') . " ffmpeg=" . ($ffmpegRunning ? 'running' : 'stopped') . "\n", FILE_APPEND);
                // // 로그 크기 제한 (1MB 초과 시 뒤쪽 50%만 유지)
                // if (file_exists($hlsLog) && filesize($hlsLog) > 1048576) {
                //     $logContent = file_get_contents($hlsLog);
                //     file_put_contents($hlsLog, substr($logContent, strlen($logContent) / 2));
                // }
                
                // m3u8 내 세그먼트 경로를 API URL로 치환
                $content = file_get_contents($m3u8);
                // Windows \r\n 및 \r 정규화
                $content = str_replace("\r\n", "\n", $content);
                $content = str_replace("\r", "\n", $content);
                
                // ffmpeg 종료 + ENDLIST 없음 → 강제 ENDLIST 삽입 (비정상 종료 대응)
                if (!$ffmpegRunning && !$hasEndList) {
                    $plog("ffmpeg dead without ENDLIST — injecting #EXT-X-ENDLIST");
                    $content = rtrim($content) . "\n#EXT-X-ENDLIST\n";
                    // playlist type도 VOD로 변환 (event → vod)
                    $content = str_replace('#EXT-X-PLAYLIST-TYPE:EVENT', '#EXT-X-PLAYLIST-TYPE:VOD', $content);
                }
                
                $segBaseUrl = 'api.php?action=hls_stream&storage_id=' . $storageId . '&path=' . urlencode($path) . '&hls_action=segment&session=' . $sessionId . '&seg=';
                // 줄 단위로 치환 (정규식 대신 확실한 방식)
                $lines = explode("\n", $content);
                foreach ($lines as &$line) {
                    $trimmed = trim($line);
                    if (preg_match('/^stream\d+\.ts$/', $trimmed)) {
                        $line = $segBaseUrl . $trimmed;
                    }
                }
                unset($line);
                $content = implode("\n", $lines);
                
                header('Content-Type: application/vnd.apple.mpegurl');
                header('Cache-Control: no-cache');
                header('Access-Control-Allow-Origin: *');
                header('Access-Control-Expose-Headers: X-Current-Encoder, X-Sw-Fallback-Applied');
                
                // ★ 현재 사용 중인 인코더를 헤더로 전달 — 클라이언트 배지 실시간 업데이트용
                //   SW fallback 발생했으면 'libx264', 아니면 원래 HW 인코더 (h264_qsv 등)
                $currentMeta = @json_decode(@file_get_contents($sessionDir . '/meta.json'), true);
                if (is_array($currentMeta) && !empty($currentMeta['current_encoder'])) {
                    // HTTP 헤더 인젝션 방어: 영숫자/언더스코어만 허용
                    $encSafe = preg_replace('/[^A-Za-z0-9_]/', '', (string)$currentMeta['current_encoder']);
                    if ($encSafe !== '') {
                        header('X-Current-Encoder: ' . $encSafe);
                    }
                    if (!empty($currentMeta['sw_fallback_applied'])) {
                        header('X-Sw-Fallback-Applied: 1');
                    }
                }
                
                echo $content;
                exit;
            }
            
            if ($action === 'segment') {
                $seg = basename($_GET['seg'] ?? '');
                if (!preg_match('/^stream\d+\.ts$/', $seg)) { http_response_code(400); exit; }
                $segPath = $sessionDir . '/' . $seg;
                
                // 세션 디렉토리 touch — cleanup에서 재생 중 세션 보호
                @touch($sessionDir);
                // ★ 클라이언트 마지막 접근 시간 기록 (고아 ffmpeg 판정용)
                @file_put_contents($sessionDir . '/last_access.txt', (string)time());
                
                // ffmpeg 생존 여부 확인 헬퍼
                $isFfmpegRunning = function() use ($sessionDir) {
                    $pidFile = $sessionDir . '/pid.txt';
                    if (!file_exists($pidFile)) return false;
                    $pid = (int)file_get_contents($pidFile);
                    if ($pid <= 0) return false;
                    if (PHP_OS_FAMILY === 'Windows') {
                        $check = shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
                        return ($check && strpos($check, (string)$pid) !== false);
                    } else {
                        return file_exists('/proc/' . $pid);
                    }
                };
                
                // 세그먼트가 아직 생성 중이면 대기 (최대 10초, 단 ffmpeg 죽으면 즉시 중단)
                $waited = 0;
                while (!file_exists($segPath) && $waited < 10) {
                    // 2초마다 ffmpeg 생존 체크
                    if ($waited > 0 && fmod($waited, 2.0) < 0.25 && !$isFfmpegRunning()) {
                        $plog("segment 410: $seg — ffmpeg dead (waited {$waited}s)");
                        http_response_code(410); // Gone — ffmpeg 종료됨
                        header('Content-Type: application/json');
                        echo json_encode(['error' => 'ffmpeg_stopped', 'segment' => $seg]);
                        exit;
                    }
                    usleep(200000);
                    $waited += 0.2;
                }
                if (!file_exists($segPath)) {
                    // 최종 ffmpeg 체크
                    if (!$isFfmpegRunning()) {
                        $plog("segment 410: $seg — ffmpeg dead (waited {$waited}s)");
                        http_response_code(410);
                        header('Content-Type: application/json');
                        echo json_encode(['error' => 'ffmpeg_stopped', 'segment' => $seg]);
                        exit;
                    }
                    $plog("segment 404: $seg (waited {$waited}s)");
                    http_response_code(404); exit;
                }
                
                // 세그먼트가 아직 쓰이고 있을 수 있으므로 크기가 안정될 때까지 대기
                $prevSize = 0;
                for ($i = 0; $i < 10; $i++) {
                    clearstatcache(true, $segPath);
                    $curSize = filesize($segPath);
                    if ($curSize > 0 && $curSize === $prevSize) break;
                    $prevSize = $curSize;
                    usleep(100000);
                }
                
                // 매 50번째 세그먼트만 로그 (과도한 로그 방지)
                $segNum = (int)filter_var($seg, FILTER_SANITIZE_NUMBER_INT);
                if ($segNum % 50 === 0) {
                    $plog("segment served: $seg size=" . filesize($segPath));
                }
                
                header('Content-Type: video/mp2t');
                header('Content-Length: ' . filesize($segPath));
                header('Cache-Control: public, max-age=86400');
                header('Access-Control-Allow-Origin: *');
                readfile($segPath);
                exit;
            }
        }
        
        // === stop: 세션 정리 ===
        if ($action === 'stop') {
            $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['session'] ?? '');
            
            // [HLSDebug] stop 요청 기록 (파일 있을 때만)
            $_timingLogStop = $dataDir . '/hls_timing.log';
            if (is_file($_timingLogStop)) {
                @file_put_contents($_timingLogStop,
                    date('H:i:s.') . sprintf('%03d', (int)((microtime(true) - floor(microtime(true))) * 1000)) .
                    " [$sessionId] STOP request received\n",
                    FILE_APPEND);
            }
            
            if ($sessionId) {
                $this->hlsCleanup($sessionId, $hlsDir);
            }
            
            // 전체 고아 세션 청소 (다른 사용자의 활성 세션은 last_access.txt 기준으로 보호됨)
            $this->hlsCleanupStale();
            
            if (is_file($_timingLogStop)) {
                @file_put_contents($_timingLogStop,
                    date('H:i:s.') . sprintf('%03d', (int)((microtime(true) - floor(microtime(true))) * 1000)) .
                    " [$sessionId] STOP cleanup done\n",
                    FILE_APPEND);
            }
            
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        
        // === start: ffmpeg HLS 프로세스 시작 (foreground — 이 요청이 ffmpeg 완료까지 유지됨) ===
        // 디버그: start 요청 기록
        
        $realPath = $this->storage->getRealPath($storageId);
        if (!$realPath) { http_response_code(404); echo json_encode(['error' => 'Storage not found']); exit; }
        
        $fullPath = $realPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (!is_file($fullPath)) { http_response_code(404); echo json_encode(['error' => 'File not found']); exit; }
        
        // 경로 안전성 검증 (경로 탈출 방지)
        if (!$this->isPathSafe($realPath, $fullPath)) {
            http_response_code(403); echo json_encode(['error' => 'Invalid path']); exit;
        }
        
        $ffmpeg = $this->findFfmpeg();
        if (!$ffmpeg) { http_response_code(500); echo json_encode(['error' => 'ffmpeg not available']); exit; }
        
        // 오래된 세션 정리
        $this->hlsCleanupStale();
        
        // 세션 디렉토리 생성
        if (!is_dir($hlsDir)) @mkdir($hlsDir, 0755, true);
        
        // 같은 파일에 대한 기존 활성 세션 검색 (중복 방지)
        // ★ force_sw=1 요청 시 기존 HW 세션 (force_sw 없음) 은 reuse 안 함 (HW→SW fallback 의도)
        //   하지만 같은 force_sw=1 세션은 reuse — 펜닐님 진단: 서버 이중 실행 시 두 폴더 생성 방어
        //   현재 코드는 force_sw=1이면 무조건 skipReuse → 두 번 호출 시 두 폴더
        //   수정: force_sw 일치 + path + audio + client_session 같으면 reuse 가능
        $reqForceSw = isset($_GET['force_sw']) ? '1' : '0';
        $reqAudioIdx = isset($_GET['audio']) ? (int)$_GET['audio'] : null;
        // ★ client_session — PHP 세션 ID hash로 기기별 분리 (같은 사용자 PC/모바일 동시 시청 시 폴더 공유 방지)
        $reqClientSession = session_id() ? substr(hash('sha256', session_id()), 0, 16) : '';
        // ★ quality 비교 — 다른 quality 요청은 별도 세션 (v5.8.1c)
        //   1080p로 재생 중 → 720p 변경 시: reqQuality='720p' vs existQuality='1080p' → 새 세션
        //   같은 quality + 같은 audio + 같은 client_session: reuse 가능 (이중 호출 방어)
        $reqQuality = isset($_GET['quality']) ? trim($_GET['quality']) : 'original';
        // ★ seek 비교 — quality 변경 시 seek=150 같이 들어오면 별도 세션 (다른 시점부터 인코딩)
        //   같은 시점 + 같은 quality 재요청은 reuse 가능
        $reqSeek = isset($_GET['seek']) ? (float)$_GET['seek'] : 0;
        $existingDirs = glob($hlsDir . '/*', GLOB_ONLYDIR);
        if ($existingDirs) {
            foreach ($existingDirs as $existDir) {
                $existMeta = $existDir . '/meta.json';
                if (!file_exists($existMeta)) continue;
                $existMetaData = @json_decode(file_get_contents($existMeta), true);
                if (!$existMetaData) continue;
                // 같은 storage_id + path + audio + force_sw + client_session + quality + seek 인지 확인
                //   audio 비교: 둘 다 null/없으면 같은 것으로 처리 (단일 오디오 영상)
                //   force_sw 일치 — HW에서 SW fallback 시도 시 새 세션 강제
                //   client_session 일치 — 기기별 분리 (같은 영상이라도 다른 기기는 다른 폴더)
                //   quality 일치 — 다른 화질은 새 세션 (v5.8.1c)
                //   seek 일치 — 다른 시점은 새 세션 (v5.8.1c, quality 변경 시 사용자 위치부터 시작)
                $existAudioIdx = isset($existMetaData['audio']) ? (int)$existMetaData['audio'] : null;
                $existIsSw = !empty($existMetaData['current_encoder']) && strpos($existMetaData['current_encoder'], 'libx264') !== false ? '1' : '0';
                $existClientSession = $existMetaData['client_session'] ?? '';
                $existQuality = $existMetaData['quality'] ?? 'original';
                $existSeek = (float)($existMetaData['seek'] ?? 0);
                if (($existMetaData['storage_id'] ?? -1) == $storageId 
                    && ($existMetaData['path'] ?? '') === $path
                    && $existAudioIdx === $reqAudioIdx
                    && $existIsSw === $reqForceSw
                    && $existClientSession === $reqClientSession
                    && $existQuality === $reqQuality
                    && abs($existSeek - $reqSeek) < 0.5) {
                    $existSessionId = basename($existDir);
                    $isActive = false;
                    $existCreated = $existMetaData['created'] ?? 0;
                    
                    // 1. 생성된 지 60초 이내면 활성 (ffmpeg 시작 중일 수 있음)
                    if (time() - $existCreated < 60) {
                        $isActive = true;
                    }
                    // 2. m3u8가 최근 30초 이내 갱신됐으면 활성
                    if (!$isActive) {
                        $existM3u8 = $existDir . '/stream.m3u8';
                        if (file_exists($existM3u8)) {
                            clearstatcache(true, $existM3u8);
                            $isActive = (time() - filemtime($existM3u8)) < 30;
                        }
                    }
                    // 3. PID로 프로세스 생존 확인
                    if (!$isActive) {
                        $existPid = $existDir . '/pid.txt';
                        if (file_exists($existPid)) {
                            $ep = (int)file_get_contents($existPid);
                            if ($ep > 0 && PHP_OS_FAMILY === 'Windows') {
                                $ec = shell_exec('tasklist /FI "PID eq ' . $ep . '" /NH 2>NUL');
                                $isActive = ($ec && strpos($ec, (string)$ep) !== false);
                            }
                        }
                    }
                    if ($isActive) {
                        // reuse 시 meta.json 갱신 + touch (cleanup 보호)
                        // ★ sw_cmd, stderr_log, audio, current_encoder, client_session, quality, seek 기존 값 보존
                        $existMeta = $existDir . '/meta.json';
                        @file_put_contents($existMeta, json_encode([
                            'created' => time(),
                            'storage_id' => $storageId,
                            'path' => $path,
                            'audio' => $existAudioIdx,
                            'client_session' => $existClientSession,
                            'sw_cmd' => $existMetaData['sw_cmd'] ?? null,
                            'stderr_log' => $existMetaData['stderr_log'] ?? null,
                            'current_encoder' => $existMetaData['current_encoder'] ?? null,
                            'quality' => $existQuality,
                            'seek' => $existSeek,
                        ]));
                        @touch($existDir);
                        
                        // 기존 세션 재사용
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'session' => $existSessionId,
                            'playlist' => 'api.php?action=hls_stream&storage_id=' . $storageId . '&path=' . urlencode($path) . '&hls_action=playlist&session=' . $existSessionId,
                            'reused' => true
                        ]);
                        exit;
                    }
                }
            }
        }
        
        $sessionId = bin2hex(random_bytes(8));
        $sessionDir = $hlsDir . '/' . $sessionId;
        @mkdir($sessionDir, 0755, true);
        
        // Windows 한글 경로 → 8.3 짧은 경로 (ffmpeg 비ASCII 경로 문제 해결)
        $ffmpegInput = $fullPath;
        if (PHP_OS_FAMILY === 'Windows') {
            $shortPath = $this->getWindowsShortPath($fullPath);
            if ($shortPath) $ffmpegInput = $shortPath;
        }
        $inputPath = $this->escapeShellPath($ffmpegInput);
        
        // seek
        $seekSec = isset($_GET['seek']) ? max(0, (float)$_GET['seek']) : 0;
        $seekArg = $seekSec > 0 ? ' -ss ' . $seekSec : '';
        
        // 오디오 트랙
        $audioMap = ' -map 0:v:0 -map 0:a:0';
        if (isset($_GET['audio'])) {
            $audioIdx = (int)$_GET['audio'];
            $audioMap = ' -map 0:v:0 -map 0:' . $audioIdx;
        }
        
        // ★ [HLS_DIAG] HLS 액션 audio 매핑 결과 (펜닐님 진단용 — 임시) — 주석처리됨 (다음 디버깅 위해 보존)
        //   다시 활성 필요 시 아래 블록 주석 제거
        /*
        $_diagFile = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/hls_diag.log';
        $_diagDir = dirname($_diagFile);
        if (is_dir($_diagDir) || @mkdir($_diagDir, 0755, true)) {
            $_audioParam = $_GET['audio'] ?? '(none)';
            $_forceSw = isset($_GET['force_sw']) ? '1' : '0';
            $_clientSession = session_id() ? substr(hash('sha256', session_id()), 0, 8) : '(none)';
            $_diagLine = '[' . date('Y-m-d H:i:s') . '] [SERVER] hls_audio_map | '
                . json_encode([
                    'audio_param' => $_audioParam,
                    'audio_map' => trim($audioMap),
                    'force_sw' => $_forceSw,
                    'path' => basename($inputPath),
                    'session' => $_GET['session'] ?? null,
                    'hls_action' => $_GET['hls_action'] ?? null,
                    'client_session' => $_clientSession
                ]);
            @file_put_contents($_diagFile, $_diagLine . "\n", FILE_APPEND | LOCK_EX);
        }
        */
        
        // 인코더 (QSV 등 HW 우선, force_sw=1이면 CPU 강제)
        // 소프트웨어(CPU) 인코더 인자 — HW 실패 시 playlist 요청 경로에서 폴백용
        $swCodecArgs = '-c:v libx264 -preset ultrafast -crf 23 -tune zerolatency -profile:v high -level 4.1 -pix_fmt yuv420p';
        $usingHwEncoder = false;
        
        if (isset($_GET['force_sw'])) {
            $videoCodecArgs = $swCodecArgs;
        } else {
            $videoCodecArgs = $this->detectHwEncoder($ffmpeg);
            $usingHwEncoder = (strpos($videoCodecArgs, 'libx264') === false);
        }
        $gopArgs = ' -g 48 -keyint_min 48';
        
        // ★ Quality 옵션 적용 (v5.8.1c)
        //   - $_GET['quality'] = '1080p' | '720p' | '480p' | '360p' | '240p' | '144p' | 'original' | 미지정(=original)
        //   - 'original' / 미지정: 옵션 변경 없음 (기존 CRF/QP 그대로)
        //   - 그 외: 비트레이트 옵션 + scale 필터 적용
        //   - HW/SW 양쪽 모두 동일 quality 적용 (SW backup도 같은 옵션)
        $hlsQuality = isset($_GET['quality']) ? trim($_GET['quality']) : 'original';
        $hlsQualityResult = $this->applyQualityToArgs($videoCodecArgs, $hlsQuality);
        $videoCodecArgs = $hlsQualityResult['codecArgs'];
        $hlsQualityVf = $hlsQualityResult['vfPrepend'];
        // SW backup도 동일 quality 적용
        $swQualityResult = $this->applyQualityToArgs($swCodecArgs, $hlsQuality);
        $swCodecArgs = $swQualityResult['codecArgs'];
        
        // ffmpeg HLS 출력 명령
        $outputM3u8 = $this->escapeShellPath($sessionDir . '/stream.m3u8');
        $segPattern = '"' . str_replace('/', DIRECTORY_SEPARATOR, $sessionDir . '/stream%d.ts') . '"';
        $stderrLog = $sessionDir . '/ffmpeg.log';
        
        // 공통 ffmpeg 명령 빌더 (인코더 부분만 교체 가능)
        // -af aresample=async=1000:first_pts=0 :
        //   오디오 타임스탬프 보정. mkv의 오디오가 start 1.0초처럼 지연 시작일 때
        //   비디오보다 짧아져서 브라우저가 duration에 맞추려 오디오를 늘려
        //   피치가 낮아지는(두꺼비 목소리) 문제 방지.
        //   - async=1000: 최대 1초까지 샘플 추가/드롭으로 맞춤
        //   - first_pts=0: 첫 샘플을 0초부터 시작 (지연 무시)
        // ★ v5.8.1c: $hlsQualityVf 가 있으면 -vf 로 scale 필터 prepend
        $buildFfmpegCmd = function($codecArgs) use ($ffmpeg, $seekArg, $inputPath, $audioMap, $gopArgs, $segPattern, $outputM3u8, $hlsQualityVf) {
            $vfArg = $hlsQualityVf !== '' ? ' -vf "' . $hlsQualityVf . '"' : '';
            return escapeshellarg($ffmpeg)
                . $seekArg
                . ' -readrate 3'
                . ' -analyzeduration 2000000 -probesize 2000000'
                . ' -fflags +genpts+igndts+fastseek'
                . ' -i ' . $inputPath
                . $audioMap
                . ' -sn'
                . ' ' . $codecArgs
                . $gopArgs
                . ' -c:a aac -b:a 128k -ac 2'
                . $vfArg
                . ' -af aresample=async=1000:first_pts=0'
                . ' -f hls'
                . ' -hls_time 4'
                . ' -hls_list_size 0'
                . ' -hls_playlist_type event'
                . ' -hls_flags independent_segments'
                . ' -hls_segment_type mpegts'
                . ' -hls_segment_filename ' . $segPattern
                . ' ' . $outputM3u8;
        };
        
        $cmd = $buildFfmpegCmd($videoCodecArgs);
        // HW 인코더 사용 중이면 playlist 요청 경로에서 폴백 가능하도록 SW 명령도 준비
        $cmdSwBackup = $usingHwEncoder ? $buildFfmpegCmd($swCodecArgs) : null;
        
        // [HLSDebug] data/hls_timing.log 파일이 있을 때만 기록
        $dataDir2 = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $hlsDebugLog = $dataDir2 . '/hls_timing.log';
        if (is_file($hlsDebugLog)) {
            @file_put_contents($hlsDebugLog,
                date('H:i:s.') . sprintf('%03d', (int)((microtime(true) - floor(microtime(true))) * 1000)) .
                " [$sessionId] START file=" . basename($path) . " encoder=" . ($usingHwEncoder ? 'HW' : 'SW') .
                " codec_args=" . $videoCodecArgs . " has_sw_backup=" . ($cmdSwBackup ? 'YES' : 'NO') . "\n",
                FILE_APPEND);
        }
        
        // 메타데이터 먼저 저장
        // sw_cmd: HW 인코더 실패 시 playlist 요청 경로가 이 명령으로 재시도
        // current_encoder: 현재 실제 사용 중인 인코더 (SW fallback 시 'libx264'로 갱신됨)
        //   → 클라이언트 배지가 "HW : Intel" → "SW : CPU"로 실시간 변경되는 근거
        $currentEncoder = $this->extractEncoderFromArgs($videoCodecArgs);
        // ★ meta.json에 audio 인덱스 저장 — reuse 비교 시 다른 audio면 새 세션 생성용
        //   (없으면 storage_id+path만 비교 → audio 변경해도 기존 세션 재사용 → 영원히 같은 트랙)
        $metaAudioIdx = isset($_GET['audio']) ? (int)$_GET['audio'] : null;
        // ★ client_session: PHP 세션 ID hash — 기기별 분리 (같은 사용자 PC+모바일 동시 시청 시 폴더 공유 방지)
        //   브라우저/기기마다 PHP session_id 다름 → 다른 client_session → 다른 폴더 사용
        //   같은 브라우저 같은 영상 재생은 같은 session → 같은 폴더 reuse (효율적)
        //   짧은 hash로 변환 (전체 session_id 노출 방지 + 키 단순화)
        $metaClientSession = session_id() ? substr(hash('sha256', session_id()), 0, 16) : '';
        file_put_contents($sessionDir . '/meta.json', json_encode([
            'created' => time(),
            'storage_id' => $storageId,
            'path' => $path,
            'audio' => $metaAudioIdx,
            'client_session' => $metaClientSession,
            'sw_cmd' => $cmdSwBackup,
            'stderr_log' => $stderrLog,
            'current_encoder' => $currentEncoder,
            'quality' => $hlsQuality,
            'seek' => $seekSec,
        ]));
        
        // ★ ffmpeg 백그라운드 실행을 먼저 시작 (응답 전)
        // 이래야 트랜스코딩이 확실히 시작됨 (PHP가 응답 후 죽어도 이미 실행 중)
        $pidFile = $sessionDir . '/pid.txt';
        if (PHP_OS_FAMILY === 'Windows') {
            $bgCmd = 'start "" /B ' . $cmd . ' 2>' . escapeshellarg($stderrLog);
            pclose(popen($bgCmd, 'r'));
        } else {
            $bgCmd = $cmd . ' > ' . escapeshellarg($stderrLog) . ' 2>&1 & echo $! > ' . escapeshellarg($pidFile);
            $wrapper = 'nohup sh -c ' . escapeshellarg($bgCmd) . ' > /dev/null 2>&1 &';
            if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', @ini_get('disable_functions') ?: '')))) {
                exec($wrapper);
            } else {
                $ph = @popen($wrapper, 'r');
                if (is_resource($ph)) @pclose($ph);
            }
        }
        
        // 세션 ID 응답
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'session' => $sessionId,
            'playlist' => 'api.php?action=hls_stream&storage_id=' . $storageId . '&path=' . urlencode($path) . '&hls_action=playlist&session=' . $sessionId,
        ]);
        
        // ★ 응답 즉시 플러시 - 클라이언트가 바로 다음 단계 진행 가능
        // fastcgi_finish_request가 있으면 사용 (가장 빠름)
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            // Apache/mod_php: 출력 버퍼 + 세션 닫기
            @ob_end_flush();
            @flush();
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
            }
        }
        
        // PID 저장 (약간 대기 후)
        usleep(500000); // 0.5초 대기 — ffmpeg 시작 보장 (응답은 이미 전송됨)
        if (PHP_OS_FAMILY === 'Windows') {
            $foundPid = null;
            
            // 방법 1: wmic 시도 (Windows 10/Server 2019 이하)
            //   ⚠️ wmic은 Windows 11 23H2+ / Server 2025부터 deprecated/disabled 가능
            $wmic = @shell_exec('wmic process where "name=\'ffmpeg.exe\'" get processid,commandline /format:csv 2>NUL');
            if ($wmic) {
                foreach (explode("\n", $wmic) as $line) {
                    if (strpos($line, $sessionId) !== false && preg_match('/,(\d+)\s*$/', trim($line), $pm)) {
                        $foundPid = $pm[1];
                        break;
                    }
                }
            }
            
            // 방법 2: PowerShell fallback (wmic 없거나 실패 시 — Windows 11 24H2+ / Server 2022/2025 대응)
            //   Get-CimInstance가 wmic 대체 표준 (Microsoft 공식 권장)
            if (!$foundPid) {
                // sessionId로 commandline 매칭 — escape 처리 (PowerShell single-quote)
                $psSessionId = str_replace("'", "''", $sessionId);
                $psCmd = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \\"Name = \'ffmpeg.exe\'\\" | Where-Object { $_.CommandLine -like \'*' . $psSessionId . '*\' } | Select-Object -First 1 -ExpandProperty ProcessId"';
                $psOut = @shell_exec($psCmd . ' 2>NUL');
                if ($psOut) {
                    $psPid = trim($psOut);
                    if (preg_match('/^\d+$/', $psPid)) {
                        $foundPid = $psPid;
                    }
                }
            }
            
            if ($foundPid) {
                file_put_contents($pidFile, $foundPid);
            }
        } else if (!file_exists($pidFile)) {
            // 쉘 래퍼에서 PID 저장 실패 시 pgrep fallback
            $pid = trim(@shell_exec('pgrep -f "' . $sessionId . '" 2>/dev/null') ?? '');
            if (!$pid) $pid = trim(@shell_exec('ps aux 2>/dev/null | grep ' . escapeshellarg($sessionId) . ' | grep -v grep | awk \'{print $2}\' 2>/dev/null') ?? '');
            if ($pid) file_put_contents($pidFile, $pid);
        }
        
        exit;
    }
    
    /**
     * HLS 세션 정리 (ffmpeg 프로세스 종료 + 파일 삭제)
     */
    private function hlsCleanup(string $sessionId, string $hlsDir): void {
        $sessionDir = $hlsDir . '/' . $sessionId;
        if (!is_dir($sessionDir)) return;
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        
        // [HLSDebug] cleanup 과정 기록 (파일 있을 때만)
        $_timingLogCleanup = $dataDir . '/hls_timing.log';
        $_debugEnabled = is_file($_timingLogCleanup);
        $_debugLog = function($msg) use ($_debugEnabled, $_timingLogCleanup, $sessionId) {
            if (!$_debugEnabled) return;
            @file_put_contents($_timingLogCleanup,
                date('H:i:s.') . sprintf('%03d', (int)((microtime(true) - floor(microtime(true))) * 1000)) .
                " [$sessionId] CLEANUP $msg\n",
                FILE_APPEND);
        };
        
        // 방법 1: PID 파일
        $pidFile = $sessionDir . '/pid.txt';
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            $_debugLog("pid_file_found pid=$pid");
            if ($pid > 0) {
                if (PHP_OS_FAMILY === 'Windows') {
                    $taskkillOut = shell_exec('taskkill /F /PID ' . $pid . ' /T 2>&1');
                    $_debugLog("taskkill_pid pid=$pid result=" . trim(str_replace(["\r","\n"], ' | ', (string)$taskkillOut)));
                } else {
                    if (function_exists('posix_kill')) {
                        $r = posix_kill($pid, 9);
                        $_debugLog("posix_kill pid=$pid result=" . ($r ? 'OK' : 'FAIL'));
                    } else {
                        exec('kill -9 ' . $pid . ' 2>/dev/null');
                        $_debugLog("kill_-9 pid=$pid");
                    }
                }
            }
        } else {
            $_debugLog("pid_file_missing");
        }
        
        // 방법 2: sessionId가 커맨드라인에 포함된 ffmpeg 프로세스 검색 후 kill
        if (PHP_OS_FAMILY === 'Windows') {
            $killCount = 0;
            $foundPids = [];
            
            // 방법 2-1: wmic 시도 (Windows 10/Server 2019 이하)
            $wmic = @shell_exec('wmic process where "name=\'ffmpeg.exe\'" get processid,commandline /format:csv 2>NUL');
            if ($wmic) {
                foreach (explode("\n", $wmic) as $line) {
                    if (strpos($line, $sessionId) !== false && preg_match('/,(\d+)\s*$/', trim($line), $m)) {
                        $foundPids[] = (int)$m[1];
                    }
                }
            }
            
            // 방법 2-2: PowerShell fallback (wmic deprecated 환경 — Windows 11 24H2+ / Server 2022/2025)
            if (empty($foundPids)) {
                $psSessionId = str_replace("'", "''", $sessionId);
                $psCmd = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \\"Name = \'ffmpeg.exe\'\\" | Where-Object { $_.CommandLine -like \'*' . $psSessionId . '*\' } | Select-Object -ExpandProperty ProcessId"';
                $psOut = @shell_exec($psCmd . ' 2>NUL');
                if ($psOut) {
                    foreach (explode("\n", $psOut) as $line) {
                        $line = trim($line);
                        if (preg_match('/^\d+$/', $line)) {
                            $foundPids[] = (int)$line;
                        }
                    }
                }
                if (!empty($foundPids)) {
                    $_debugLog("ps_fallback_found pids=" . implode(',', $foundPids));
                }
            }
            
            foreach ($foundPids as $foundPid) {
                $out = @shell_exec('taskkill /F /PID ' . $foundPid . ' /T 2>&1');
                $killCount++;
                $_debugLog("kill pid=$foundPid result=" . trim(str_replace(["\r","\n"], ' | ', (string)$out)));
            }
            $_debugLog("total_killed=$killCount");
        } else {
            // ★ v5.8.1j 케이스 F 수정 (Linux/Unix 환경 권한 fallback):
            //   pid.txt 기반 posix_kill/kill 이 권한 부족 등으로 실패 시
            //   (예: nginx+PHP-FPM 워커가 다른 사용자, Synology DSM 시스템 사용자 분리)
            //   sessionId로 ffmpeg 프로세스 재검색 후 추가 kill 시도
            //   pgrep -af → grep으로 sessionId 매칭 (ffmpeg 명령행에 sessionId 포함)
            //   pgrep 없는 환경은 ps -ef로 fallback
            //   ★ 보안: sessionId 추가 sanitize (방어 심층 — basename($dir) 경로 진입 시 대응)
            $safeSid = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
            if ($safeSid !== '' && $safeSid === $sessionId) {
                $killCount2 = 0;
                $foundPids2 = [];
                
                // 방법 2-1: pgrep -af (대부분 Linux/Synology에서 사용 가능)
                if (function_exists('shell_exec')) {
                    $pgrepOut = @shell_exec('pgrep -af ffmpeg 2>/dev/null');
                    if ($pgrepOut) {
                        foreach (explode("\n", $pgrepOut) as $line) {
                            if (strpos($line, $safeSid) !== false && preg_match('/^(\d+)\s/', trim($line), $m)) {
                                $foundPids2[] = (int)$m[1];
                            }
                        }
                    }
                    
                    // 방법 2-2: pgrep 결과 없으면 ps -ef fallback (BusyBox 등 pgrep 미설치 환경)
                    if (empty($foundPids2)) {
                        $psOut2 = @shell_exec('ps -ef 2>/dev/null');
                        if ($psOut2) {
                            foreach (explode("\n", $psOut2) as $line) {
                                if (strpos($line, 'ffmpeg') !== false && strpos($line, $safeSid) !== false) {
                                    // ps -ef 출력: UID PID PPID C STIME TTY TIME CMD
                                    if (preg_match('/^\S+\s+(\d+)\s/', trim($line), $m)) {
                                        $foundPids2[] = (int)$m[1];
                                    }
                                }
                            }
                        }
                    }
                }
                
                // 발견된 PID 종료 (posix_kill → kill -9 fallback)
                foreach ($foundPids2 as $foundPid) {
                    if (function_exists('posix_kill')) {
                        $r = @posix_kill($foundPid, 9);
                        $_debugLog("linux_fallback_posix_kill pid=$foundPid result=" . ($r ? 'OK' : 'FAIL'));
                    } elseif (function_exists('exec')) {
                        @exec('kill -9 ' . $foundPid . ' 2>/dev/null');
                        $_debugLog("linux_fallback_kill_-9 pid=$foundPid");
                    } elseif (function_exists('shell_exec')) {
                        @shell_exec('kill -9 ' . $foundPid . ' 2>/dev/null');
                        $_debugLog("linux_fallback_shell_kill pid=$foundPid");
                    }
                    $killCount2++;
                }
                if ($killCount2 > 0) {
                    $_debugLog("linux_total_killed=$killCount2");
                }
            }
        }
        
        // ffmpeg 종료 대기
        usleep(500000);
        
        // 파일 삭제 (재시도) - hidden 파일 포함
        $_debugLog("file_delete_start");
        for ($retry = 0; $retry < 3; $retry++) {
            $entries = @scandir($sessionDir);
            if ($entries) {
                foreach ($entries as $e) {
                    if ($e === '.' || $e === '..') continue;
                    @unlink($sessionDir . DIRECTORY_SEPARATOR . $e);
                }
            }
            if (@rmdir($sessionDir)) {
                $_debugLog("file_delete_done retry=$retry");
                return;
            }
            usleep(500000);
        }
        $_debugLog("file_delete_failed (session dir still exists)");
    }
    
    /**
     * 트랜스코드 캐시 정리 — 7일 이상 미접근 파일 삭제, 총 용량 10GB 초과 시 오래된 것부터 삭제
     */
    public function cleanupTranscodeCache(): void {
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $cacheDir = $dataDir . '/transcode_cache';
        if (!is_dir($cacheDir)) return;
        
        $files = glob($cacheDir . '/*.mp4');
        if (!$files) return;
        
        $maxAge = 7 * 86400; // 7일
        $maxTotalSize = 10 * 1024 * 1024 * 1024; // 10GB
        $now = time();
        
        // 1단계: 7일 이상 미접근 파일 삭제
        $remaining = [];
        foreach ($files as $f) {
            $atime = fileatime($f) ?: filemtime($f);
            if ($now - $atime > $maxAge) {
                @unlink($f);
            } else {
                $remaining[] = ['path' => $f, 'time' => $atime, 'size' => filesize($f)];
            }
        }
        
        // .tmp 파일 정리 (1시간 이상)
        $tmpFiles = glob($cacheDir . '/*.tmp');
        if ($tmpFiles) {
            foreach ($tmpFiles as $t) {
                if ($now - filemtime($t) > 3600) @unlink($t);
            }
        }
        
        // 2단계: 총 용량 초과 시 오래된 것부터 삭제
        usort($remaining, function($a, $b) { return $a['time'] - $b['time']; });
        $totalSize = array_sum(array_column($remaining, 'size'));
        
        while ($totalSize > $maxTotalSize && !empty($remaining)) {
            $oldest = array_shift($remaining);
            @unlink($oldest['path']);
            $totalSize -= $oldest['size'];
        }
    }
    
    public function hlsCleanupStale(): void {
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $hlsDir = $dataDir . '/hls_sessions';
        if (!is_dir($hlsDir)) return;
        
        $dirs = glob($hlsDir . '/*', GLOB_ONLYDIR);
        if (!$dirs) return;
        
        $now = time();
        $orphanTimeout = 600; // 10분 — 클라이언트 미접근 시 고아로 판정
                              // HLS 버퍼 + 긴 일시정지 대응 (5분도 부족할 수 있음)
        $maxAge = 1800; // 30분 — 절대 최대 세션 수명
        
        foreach ($dirs as $dir) {
            $metaFile = $dir . '/meta.json';
            $sessionId = basename($dir);
            $lastAccessFile = $dir . '/last_access.txt';
            
            // ★ 1단계: 클라이언트 마지막 접근 시간 기준 고아 판정
            // playlist/segment 요청 시 last_access.txt가 갱신됨
            // 10분 이상 클라이언트가 요청 안 보내면 = 모달 닫힘/브라우저 종료 = 고아
            if (file_exists($lastAccessFile)) {
                $lastAccess = (int)@file_get_contents($lastAccessFile);
                $idleAge = $now - $lastAccess;
                
                if ($idleAge > $orphanTimeout) {
                    // 고아 세션 — ffmpeg 살아있어도 강제 종료 및 정리
                    $this->hlsCleanup($sessionId, $hlsDir);
                    continue;
                }
                
                // 최근 접근 (10분 이내) — 정상 재생 중
                continue;
            }
            
            // ★ 2단계: last_access.txt 없는 구버전 세션 처리
            // 30분 이상 된 세션만 대상 (안전 여유)
            // ★ v5.8.1j 케이스 E 수정 (다른 환경 권한 문제 대응):
            //   nginx+PHP-FPM/Synology DSM 등에서 last_access.txt 쓰기 권한 실패 시
            //   2단계가 30분 maxAge만 사용 → 정상 재생 중인 세션도 30분 후 끊김 발생
            //   해결: ts 세그먼트 파일 mtime을 idle 판정 보조 신호로 사용
            //   ffmpeg가 세그먼트 만들면 mtime 갱신 — 살아있으면 idle 짧음 / 죽었으면 idle 김
            //   ts 파일은 ffmpeg 권한으로 생성/갱신 → last_access.txt 권한 문제와 무관
            $isOld = false;
            
            // ★ 2-A: ts 세그먼트 mtime 기반 idle 판정 (last_access.txt 권한 fallback)
            $tsFiles = glob($dir . '/stream*.ts');
            if ($tsFiles) {
                // 가장 최근 ts 세그먼트의 mtime 찾기 (max mtime)
                $latestTsMtime = 0;
                foreach ($tsFiles as $ts) {
                    $m = @filemtime($ts);
                    if ($m && $m > $latestTsMtime) $latestTsMtime = $m;
                }
                if ($latestTsMtime > 0) {
                    $tsIdleAge = $now - $latestTsMtime;
                    if ($tsIdleAge > $orphanTimeout) {
                        // ts 파일이 10분 이상 갱신 안 됨 = ffmpeg 정지/죽음 = 고아
                        $this->hlsCleanup($sessionId, $hlsDir);
                        continue;
                    }
                    // ts 파일이 최근 갱신됨 = 재생 중 = 보호
                    continue;
                }
            }
            
            // ★ 2-B: ts 파일도 없는 경우 (초기화 실패 또는 오래된 손상) — 30분 절대 만료
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                $age = $now - ($meta['created'] ?? 0);
                $isOld = ($age > $maxAge);
            } else {
                $age = $now - filemtime($dir);
                $isOld = ($age > $maxAge);
            }
            
            if (!$isOld) continue;
            
            // 30분 넘은 세션은 무조건 정리 (last_access.txt 없으면 구버전/손상)
            $this->hlsCleanup($sessionId, $hlsDir);
        }
    }
    
    /**
     * ffmpeg -c:v 인자 문자열에서 인코더 이름만 추출
     * 예: '-c:v h264_qsv -preset veryfast ...' → 'h264_qsv'
     *     '-c:v libx264 -preset ultrafast ...' → 'libx264'
     * 배지 표시 및 meta.json 기록용
     */
    
    /**
     * Quality 프리셋 (v5.8.1c — 펜닐님 요청 7단계, PC/모바일 공통)
     *   - 키: 'original' / '1080p' / '720p' / '480p' / '360p' / '240p' / '144p'
     *   - height: 다운스케일 목표 높이 (원본이 더 작으면 그대로 유지)
     *   - vbr/maxrate/bufsize: ffmpeg 비트레이트 제한 옵션
     *   - 'original': 옵션 없음 (재인코딩 없이 -c:v copy 가능 — caller가 결정)
     *   - HW/SW 인코더 모두 -b:v -maxrate -bufsize 옵션을 받음
     */
    private function getQualityPreset(string $quality): ?array {
        $presets = [
            '1080p' => ['height' => 1080, 'vbr' => '5M',   'maxrate' => '6M',   'bufsize' => '10M'],
            '720p'  => ['height' => 720,  'vbr' => '2500k','maxrate' => '3M',   'bufsize' => '5M'],
            '480p'  => ['height' => 480,  'vbr' => '1200k','maxrate' => '1500k','bufsize' => '2500k'],
            '360p'  => ['height' => 360,  'vbr' => '700k', 'maxrate' => '900k', 'bufsize' => '1500k'],
            '240p'  => ['height' => 240,  'vbr' => '400k', 'maxrate' => '500k', 'bufsize' => '800k'],
            '144p'  => ['height' => 144,  'vbr' => '200k', 'maxrate' => '250k', 'bufsize' => '400k'],
        ];
        return $presets[$quality] ?? null;
    }
    
    /**
     * Quality 옵션을 ffmpeg 인자로 변환 (v5.8.1c)
     *   - $codecArgs: 기존 비디오 코덱 인자 (e.g. '-c:v libx264 ...')
     *   - $quality: 'original' | '1080p' | '720p' | '480p' | '360p' | '240p' | '144p'
     *   - 반환: ['codecArgs' => 변환된 인자 문자열, 'vfPrepend' => scale 필터 (없으면 '')]
     *   - 'original' 또는 미지원 값이면 원본 그대로 반환
     *   - -c:v copy 인 경우엔 quality 적용 불가 → caller가 copy를 풀어서 SW로 변경 후 호출해야 함
     */
    private function applyQualityToArgs(string $codecArgs, string $quality): array {
        if ($quality === '' || $quality === 'original') {
            return ['codecArgs' => $codecArgs, 'vfPrepend' => ''];
        }
        $preset = $this->getQualityPreset($quality);
        if (!$preset) {
            return ['codecArgs' => $codecArgs, 'vfPrepend' => ''];
        }
        // -c:v copy인 경우는 호출자가 미리 풀어서 와야 함 (방어적: copy면 quality 무시)
        if (preg_match('/-c:v\s+copy/', $codecArgs)) {
            return ['codecArgs' => $codecArgs, 'vfPrepend' => ''];
        }
        // 기존 -b:v / -maxrate / -bufsize 옵션 제거 후 새로 추가 (중복 방지)
        $cleanArgs = preg_replace('/-b:v\s+\S+/', '', $codecArgs);
        $cleanArgs = preg_replace('/-maxrate\s+\S+/', '', $cleanArgs);
        $cleanArgs = preg_replace('/-bufsize\s+\S+/', '', $cleanArgs);
        // h264_nvenc는 -qp 옵션 제거 (vbr 모드와 충돌)
        $cleanArgs = preg_replace('/-qp\s+\d+/', '', $cleanArgs);
        // h264_qsv는 -global_quality 제거 (비트레이트 모드)
        $cleanArgs = preg_replace('/-global_quality\s+\d+/', '', $cleanArgs);
        // h264_amf는 -qp_i / -qp_p 제거
        $cleanArgs = preg_replace('/-qp_[ip]\s+\d+/', '', $cleanArgs);
        // libx264의 crf 제거 (비트레이트 모드)
        $cleanArgs = preg_replace('/-crf\s+\d+/', '', $cleanArgs);
        $cleanArgs = preg_replace('/\s+/', ' ', trim($cleanArgs));
        
        $brArgs = ' -b:v ' . $preset['vbr'] . ' -maxrate ' . $preset['maxrate'] . ' -bufsize ' . $preset['bufsize'];
        // scale 필터: 원본이 작으면 유지, 크면 다운스케일 (-2: 짝수 폭 자동 계산)
        // 'min(H\,ih)' — ffmpeg 표현식에서 콤마 이스케이프 필요
        $vfPrepend = "scale='-2:min(" . (int)$preset['height'] . "\\,ih)'";
        return ['codecArgs' => $cleanArgs . $brArgs, 'vfPrepend' => $vfPrepend];
    }
    
    /**
     * vf 필터를 기존 ffmpeg 명령에 합치기 (v5.8.1c)
     *   - 기존에 -af만 있고 -vf 없으면 -vf 추가
     *   - 기존 -vf "..." 가 있으면 새 필터를 콤마로 합침
     *   - $vfPrepend가 빈 문자열이면 변경 없음
     */
    private function injectVfFilter(string $cmd, string $vfPrepend): string {
        if ($vfPrepend === '') return $cmd;
        // 기존 -vf "..." 가 있는지 확인
        if (preg_match('/-vf\s+"([^"]*)"/', $cmd, $m)) {
            $newVf = $vfPrepend . ',' . $m[1];
            return preg_replace('/-vf\s+"[^"]*"/', '-vf "' . $newVf . '"', $cmd, 1);
        }
        // -vf 없으면 -af 앞에 삽입 (없으면 -c:a 앞에)
        if (strpos($cmd, ' -af ') !== false) {
            return preg_replace('/(\s)-af\s/', '$1-vf "' . $vfPrepend . '" -af ', $cmd, 1);
        }
        if (strpos($cmd, ' -c:a ') !== false) {
            return preg_replace('/(\s)-c:a\s/', '$1-vf "' . $vfPrepend . '" -c:a ', $cmd, 1);
        }
        // 마지막 수단: -f hls 앞에 (HLS만)
        if (strpos($cmd, ' -f hls') !== false) {
            return preg_replace('/(\s)-f hls/', '$1-vf "' . $vfPrepend . '" -f hls', $cmd, 1);
        }
        return $cmd;
    }
    
    private function extractEncoderFromArgs(string $args): string {
        if (preg_match('/-c:v\s+(\S+)/', $args, $m)) {
            return $m[1];
        }
        return 'unknown';
    }
    
    /**
     * ffmpeg stdout 파이프에서 첫 청크를 타임아웃과 함께 읽음.
     * HW 인코더가 초기화에서 hang(데이터도 안 주고 죽지도 않음)할 때 무한 대기를 방지.
     * - 데이터가 오면 즉시 반환 (정상 — 기존 blocking fread와 동일 결과)
     * - ffmpeg가 종료(EOF)되면 '' 반환 (HW 실패 → 호출측 폴백 트리거)
     * - $timeout초 내 아무 데이터도 없으면 '' 반환 (hang → 폴백 트리거, 무한 대기 차단)
     * 반환 후 파이프는 다시 blocking 모드로 복구 (이후 스트리밍 루프는 기존대로 동작).
     */
    private function _readFirstChunkWithTimeout($pipe, int $timeout = 6): string {
        if (!is_resource($pipe)) return '';
        stream_set_blocking($pipe, false);
        $waited = 0.0;
        while ($waited < $timeout) {
            $r = [$pipe]; $w = null; $e = null;
            $sel = @stream_select($r, $w, $e, 1, 0); // 1초 단위
            if ($sel === false) break; // select 오류 → 폴백
            if ($sel > 0) {
                $chunk = fread($pipe, 65536);
                if ($chunk !== false && $chunk !== '') {
                    stream_set_blocking($pipe, true);
                    return $chunk; // 정상: 데이터 반환
                }
                if (feof($pipe)) { // ffmpeg 종료(실패)
                    stream_set_blocking($pipe, true);
                    return '';
                }
            }
            $waited += 1.0;
        }
        stream_set_blocking($pipe, true);
        return ''; // 타임아웃(hang) → 폴백 트리거
    }

    private function detectHwEncoder(string $ffmpeg): string {
        // 캐시 파일로 매번 감지하지 않음
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $cacheFile = $dataDir . DIRECTORY_SEPARATOR . 'hw_encoder_cache.json';
        
        // 캐시 확인 (24시간 유효)
        if (is_file($cacheFile)) {
            $cache = @json_decode(file_get_contents($cacheFile), true);
            if ($cache && isset($cache['args']) && (time() - ($cache['time'] ?? 0)) < 86400) {
                return $cache['args'];
            }
        }
        
        $devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        
        // 사용 가능한 인코더 목록 조회
        $encoderList = shell_exec(escapeshellarg($ffmpeg) . ' -encoders 2>' . $devNull) ?? '';
        
        // 우선순위: NVENC > QSV > AMF > Software
        // -pix_fmt yuv420p: 10bit 입력을 8bit로 변환 (iOS 호환 필수)
        // -profile:v high -level 4.1: iOS/모바일 호환성 보장
        $hwEncoders = [
            'h264_nvenc'  => '-c:v h264_nvenc -preset p1 -tune ll -qp 23 -profile:v high -level 4.1 -pix_fmt yuv420p',
            'h264_qsv'    => '-c:v h264_qsv -preset veryfast -global_quality 23 -profile:v high -pix_fmt yuv420p',
            'h264_amf'    => '-c:v h264_amf -quality speed -qp_i 23 -qp_p 23 -profile:v high -pix_fmt yuv420p',
        ];
        
        $result = '-c:v libx264 -preset ultrafast -crf 23 -tune zerolatency -profile:v high -level 4.1 -pix_fmt yuv420p';
        $detectedName = 'libx264 (CPU)';
        
        foreach ($hwEncoders as $encoder => $args) {
            if (strpos($encoderList, $encoder) === false) continue;
            
            // 실제 인코딩 테스트 (짧은 테스트로 동작 여부 확인)
            $testCmd = escapeshellarg($ffmpeg)
                . ' -f lavfi -i nullsrc=s=64x64:d=0.1 ' . $args
                . ' -f mp4 ' . $devNull . ' 2>&1';
            $testOutput = shell_exec($testCmd);
            
            // 에러가 없으면 사용 가능
            $errorPatterns = ['Error', 'error', 'unsupported', 'failed', 'Cannot', 'No such', 'not found'];
            $hasError = false;
            if ($testOutput === null) { $hasError = true; }
            else {
                foreach ($errorPatterns as $pat) {
                    if (strpos($testOutput, $pat) !== false) { $hasError = true; break; }
                }
            }
            if (!$hasError) {
                $result = $args;
                $detectedName = $encoder;
                break;
            }
        }
        
        // 캐시 저장
        @file_put_contents($cacheFile, json_encode([
            'args' => $result,
            'encoder' => $detectedName,
            'time' => time(),
        ]));
        
        return $result;
    }
    
    // 현재 사용 중인 인코더 정보 반환
    private function getHwEncoderInfo(): string {
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $cacheFile = $dataDir . DIRECTORY_SEPARATOR . 'hw_encoder_cache.json';
        if (is_file($cacheFile)) {
            $cache = @json_decode(file_get_contents($cacheFile), true);
            if ($cache && isset($cache['encoder']) && (time() - ($cache['time'] ?? 0)) < 86400) {
                return $cache['encoder'];
            }
        }
        // 캐시 없으면 감지 실행
        $ffmpeg = $this->findFfmpeg();
        if ($ffmpeg) {
            $this->detectHwEncoder($ffmpeg);
            if (is_file($cacheFile)) {
                $cache = @json_decode(file_get_contents($cacheFile), true);
                return $cache['encoder'] ?? 'unknown';
            }
        }
        return 'unknown';
    }
    
    // ffmpeg 경로 확인 (설정 우선, 자동 감지 폴백)
    private function findFfmpeg(): ?string {
        // exec 함수 사용 가능 여부 체크
        $disabledArr = array_map('trim', explode(',', @ini_get('disable_functions') ?: ''));
        if (!function_exists('exec') || in_array('exec', $disabledArr)) {
            return null;
        }
        
        $settings = $this->loadSiteSettingsOnce();
        $configured = trim($settings['ffmpeg_path'] ?? '');
        if ($configured) {
            $out = [];
            @exec(escapeshellarg($configured) . ' -version 2>&1', $out, $ret);
            if ($ret === 0) return $configured;
        }
        
        if (PHP_OS_FAMILY === 'Windows') {
            $paths = ['ffmpeg', 'C:\\ffmpeg\\bin\\ffmpeg.exe', 'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe'];
        } else {
            $paths = [
                'ffmpeg',
                '/usr/bin/ffmpeg',
                '/usr/local/bin/ffmpeg',
                // Synology DSM
                '/usr/local/ffmpeg/bin/ffmpeg',
                '/volume1/@appstore/ffmpeg/bin/ffmpeg',
                '/volume1/@appstore/MediaServer/bin/ffmpeg',
                '/var/packages/ffmpeg/target/bin/ffmpeg',
                '/var/packages/MediaServer/target/bin/ffmpeg',
            ];
        }
        foreach ($paths as $path) {
            $out = [];
            @exec(escapeshellarg($path) . ' -version 2>&1', $out, $ret);
            if ($ret === 0) return $path;
        }
        return null;
    }
    
    // 동영상 썸네일 생성 (ffmpeg)
    private function generateVideoThumbnail(string $srcPath, string $cachePath, int $size): void {
        // 이전 실패 마커 확인 (반복 시도 방지)
        $failMarker = $cachePath . '.fail';
        if (is_file($failMarker) && (time() - filemtime($failMarker)) < 86400) return;
        
        $ffmpeg = $this->findFfmpeg();
        if (!$ffmpeg) {
            @file_put_contents($failMarker, date('Y-m-d H:i:s') . ' ffmpeg not found');
            return;
        }
        
        $escaped_ffmpeg = escapeshellarg($ffmpeg);
        // Windows 한글 경로 → 8.3 짧은 경로 (ffmpeg 비ASCII 경로 문제 해결)
        $ffmpegSrc = $srcPath;
        if (PHP_OS_FAMILY === 'Windows') {
            $shortPath = $this->getWindowsShortPath($srcPath);
            if ($shortPath) $ffmpegSrc = $shortPath;
        }
        $escaped_src = $this->escapeShellPath($ffmpegSrc);
        $fastOpts = '-analyzeduration 1000000 -probesize 1000000 -an -threads 1';
        $crop = "crop='min(iw,ih)':'min(iw,ih)'";
        $scale = "scale={$size}:{$size}:flags=fast_bilinear";
        $vf = "{$crop},{$scale}";
        
        // 여러 시점에서 프레임 추출 후 검은 화면이 아닌 첫 번째를 선택
        $seekPoints = [3, 7, 15, 30, 60, 120];
        $tempDir = dirname($cachePath);
        $bestFile = null;
        $bestBrightness = -1;
        $tempFiles = [];
        
        foreach ($seekPoints as $sec) {
            $seekTime = gmdate('H:i:s', $sec);
            $tempFile = $tempDir . DIRECTORY_SEPARATOR . basename($cachePath, '.jpg') . "_t{$sec}.jpg";
            $tempFiles[] = $tempFile;
            $escaped_temp = $this->escapeShellPath($tempFile);
            
            $cmd = "{$escaped_ffmpeg} -ss {$seekTime} {$fastOpts} -i {$escaped_src} -vframes 1 -vf \"{$vf}\" -q:v 4 {$escaped_temp} -y 2>&1";
            $output = [];
            exec($cmd, $output, $ret);
            
            if ($ret !== 0 || !is_file($tempFile)) continue;
            
            // 파일 크기로 1차 필터 (매우 작으면 검은/빈 화면)
            if (filesize($tempFile) < 300) {
                @unlink($tempFile);
                continue;
            }
            
            // GD로 평균 밝기 체크 (빠른 샘플링)
            $brightness = $this->getImageBrightness($tempFile);
            
            // 밝기 15 이상이면 검은 화면이 아님 → 바로 사용
            if ($brightness > 15) {
                $bestFile = $tempFile;
                break;
            }
            
            // 가장 밝은 프레임 기록 (모두 어두울 경우 대비)
            if ($brightness > $bestBrightness) {
                $bestBrightness = $brightness;
                $bestFile = $tempFile;
            }
        }
        
        // 최적 프레임을 캐시 경로로 이동
        if ($bestFile && is_file($bestFile)) {
            @rename($bestFile, $cachePath);
            @unlink($failMarker);
        } elseif (!is_file($cachePath)) {
            // 모든 시점 실패 → 0초 최후 시도
            $cmd = "{$escaped_ffmpeg} -ss 00:00:00 {$fastOpts} -i {$escaped_src} -vframes 1 -vf \"{$vf}\" -q:v 4 " . $this->escapeShellPath($cachePath) . " -y 2>&1";
            $output = [];
            exec($cmd, $output, $ret);
            
            if (is_file($cachePath)) {
                @unlink($failMarker);
            } else {
                @file_put_contents($failMarker, date('Y-m-d H:i:s') . ' ffmpeg failed: ' . implode("\n", array_slice($output, -3)));
            }
        }
        
        // 남은 임시 파일 정리
        foreach ($tempFiles as $tf) {
            if (is_file($tf) && $tf !== $cachePath) {
                @unlink($tf);
            }
        }
    }
    
    // 이미지의 평균 밝기 계산 (0~255, 0=완전 검정)
    // 빠른 샘플링: 이미지의 일부 픽셀만 체크
    private function getImageBrightness(string $path): float {
        $img = @imagecreatefromjpeg($path);
        if (!$img) return 0;
        
        $w = imagesx($img);
        $h = imagesy($img);
        $totalBrightness = 0;
        $sampleCount = 0;
        
        // 5x5 그리드 샘플링 (25개 픽셀만 체크 → 매우 빠름)
        for ($i = 1; $i <= 5; $i++) {
            for ($j = 1; $j <= 5; $j++) {
                $x = (int)($w * $i / 6);
                $y = (int)($h * $j / 6);
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                // ITU-R BT.601 가중 평균
                $totalBrightness += ($r * 0.299 + $g * 0.587 + $b * 0.114);
                $sampleCount++;
            }
        }
        
        imagedestroy($img);
        return $sampleCount > 0 ? $totalBrightness / $sampleCount : 0;
    }
    
    // PDF 썸네일 생성 (Imagick)
    private function generatePdfThumbnail(string $srcPath, string $cachePath, int $size): void {
        // 이전 실패 마커 확인 (반복 시도 방지)
        $failMarker = $cachePath . '.fail';
        if (is_file($failMarker) && (time() - filemtime($failMarker)) < 86400) return;
        
        $tempPath = $cachePath . '.tmp.png';
        $success = false;
        
        // 사이트 설정에서 PDF 도구 확인
        $settings = $this->loadSiteSettingsOnce();
        $configuredTool = $settings['pdf_tool'] ?? '';
        $configuredPath = $settings['pdf_tool_path'] ?? '';
        
        // 설정된 도구가 있으면 그것만 먼저 시도
        if ($configuredTool || $configuredPath) {
            $success = $this->tryPdfTool($configuredTool, $configuredPath, $srcPath, $tempPath, $cachePath, $size);
        }
        
        // 설정 도구 실패 시 자동 감지 폴백
        if (!$success) {
            // 1순위: Imagick + Ghostscript
            if (extension_loaded('imagick') && class_exists('Imagick')) {
                try {
                    $im = new \Imagick();
                    $im->setResolution(150, 150);
                    $im->readImage($srcPath . '[0]');
                    $im->setImageFormat('jpeg');
                    $im->thumbnailImage($size, $size, true);
                    $im->setImageCompressionQuality(80);
                    $im->writeImage($cachePath);
                    $im->clear();
                    $im->destroy();
                    $success = true;
                } catch (\Throwable $e) {
                    // Imagick 실패 — 다음 방법 시도
                }
            }
        }
        
        // 2순위: pdftoppm (Poppler)
        if (!$success) {
            $pdftoppm = $this->findExecutable('pdftoppm', [
                '/usr/bin/pdftoppm', '/usr/local/bin/pdftoppm'
            ]);
            if ($pdftoppm) {
                $success = $this->runPdftoppm($pdftoppm, $srcPath, $tempPath, $cachePath, $size);
            }
        }
        
        // 3순위: mutool (MuPDF)
        if (!$success) {
            $mutool = $this->findExecutable('mutool', [
                '/usr/bin/mutool', '/usr/local/bin/mutool'
            ]);
            if ($mutool) {
                $success = $this->runMutool($mutool, $srcPath, $tempPath, $cachePath, $size);
            }
        }
        
        // 4순위: Ghostscript 직접 호출
        if (!$success) {
            $gs = $this->findExecutable('gs', PHP_OS_FAMILY === 'Windows'
                ? ['gswin64c', 'gswin32c', 'C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe']
                : ['/usr/bin/gs', '/usr/local/bin/gs']
            );
            if ($gs) {
                $success = $this->runGhostscript($gs, $srcPath, $tempPath, $cachePath, $size);
            }
        }
        
        // 정리
        @unlink($tempPath);
        if ($success) {
            @unlink($failMarker);
        } else {
            @file_put_contents($failMarker, date('Y-m-d H:i:s') . ' No PDF renderer available');
        }
    }
    
    // 설정된 PDF 도구로 시도
    private function tryPdfTool(string $tool, string $path, string $srcPath, string $tempPath, string $cachePath, int $size): bool {
        $execPath = $path ?: $tool;
        if (!$execPath) return false;
        
        $toolName = $path ? basename($path) : $tool;
        
        if ($tool === 'imagick' || strpos($toolName, 'imagick') !== false) {
            if (!extension_loaded('imagick') || !class_exists('Imagick')) return false;
            try {
                $im = new \Imagick();
                $im->setResolution(150, 150);
                $im->readImage($srcPath . '[0]');
                $im->setImageFormat('jpeg');
                $im->thumbnailImage($size, $size, true);
                $im->setImageCompressionQuality(80);
                $im->writeImage($cachePath);
                $im->clear();
                $im->destroy();
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        }
        
        if ($tool === 'pdftoppm' || strpos($toolName, 'pdftoppm') !== false) {
            return $this->runPdftoppm($execPath, $srcPath, $tempPath, $cachePath, $size);
        }
        
        if ($tool === 'mutool' || strpos($toolName, 'mutool') !== false) {
            return $this->runMutool($execPath, $srcPath, $tempPath, $cachePath, $size);
        }
        
        if ($tool === 'gs' || strpos($toolName, 'gs') !== false) {
            return $this->runGhostscript($execPath, $srcPath, $tempPath, $cachePath, $size);
        }
        
        return false;
    }
    
    private function runPdftoppm(string $execPath, string $srcPath, string $tempPath, string $cachePath, int $size): bool {
        $escaped_pdf = $this->escapeShellPath($srcPath);
        $escaped_out = $this->escapeShellPath(substr($tempPath, 0, -4));
        $cmd = escapeshellarg($execPath) . " -png -f 1 -l 1 -r 150 -singlefile {$escaped_pdf} {$escaped_out} 2>&1";
        $out = [];
        @exec($cmd, $out, $ret);
        if ($ret === 0 && is_file($tempPath)) {
            $this->generateImageThumbnail($tempPath, $cachePath, $size);
            @unlink($tempPath);
            return is_file($cachePath);
        }
        return false;
    }
    
    private function runMutool(string $execPath, string $srcPath, string $tempPath, string $cachePath, int $size): bool {
        $escaped_pdf = $this->escapeShellPath($srcPath);
        $escaped_out = $this->escapeShellPath($tempPath);
        $cmd = escapeshellarg($execPath) . " convert -o {$escaped_out} -O resolution=150 {$escaped_pdf} 1 2>&1";
        $out = [];
        @exec($cmd, $out, $ret);
        if ($ret === 0 && is_file($tempPath)) {
            $this->generateImageThumbnail($tempPath, $cachePath, $size);
            @unlink($tempPath);
            return is_file($cachePath);
        }
        return false;
    }
    
    private function runGhostscript(string $execPath, string $srcPath, string $tempPath, string $cachePath, int $size): bool {
        $escaped_pdf = $this->escapeShellPath($srcPath);
        $escaped_out = $this->escapeShellPath($tempPath);
        $cmd = escapeshellarg($execPath) . " -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r150 -dFirstPage=1 -dLastPage=1 -sOutputFile={$escaped_out} {$escaped_pdf} 2>&1";
        $out = [];
        @exec($cmd, $out, $ret);
        if ($ret === 0 && is_file($tempPath)) {
            $this->generateImageThumbnail($tempPath, $cachePath, $size);
            @unlink($tempPath);
            return is_file($cachePath);
        }
        return false;
    }
    
    // 사이트 설정 로드 (캐시)
    private ?array $_siteSettingsCache = null;
    private function loadSiteSettingsOnce(): array {
        if ($this->_siteSettingsCache !== null) return $this->_siteSettingsCache;
        $settingsFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'site_settings.json';
        if (is_file($settingsFile)) {
            $this->_siteSettingsCache = json_decode(file_get_contents($settingsFile), true) ?: [];
        } else {
            $this->_siteSettingsCache = [];
        }
        return $this->_siteSettingsCache;
    }
    
    // 실행 파일 찾기 (설정 경로 → 지정 경로 → PATH)
    private function findExecutable(string $name, array $paths = []): ?string {
        foreach ($paths as $p) {
            // 와일드카드 지원 (gs* 등)
            if (strpos($p, '*') !== false) {
                $matches = glob($p);
                foreach ($matches as $m) {
                    $out = [];
                    @exec(escapeshellarg($m) . ' --version 2>&1', $out, $ret);
                    if ($ret === 0) return $m;
                }
                continue;
            }
            $out = [];
            @exec(escapeshellarg($p) . ' --version 2>&1', $out, $ret);
            if ($ret === 0) return $p;
        }
        // PATH에서 찾기
        $out = [];
        @exec(escapeshellarg($name) . ' --version 2>&1', $out, $ret);
        if ($ret === 0) return $name;
        return null;
    }
    
    // 다운로드
    public function download(int $storageId, string $relativePath, bool $inline = false, int $speedLimit = 0): void {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            http_response_code(403);
            exit(__('no_permission_dot'));
        }
        
        // 다운로드(첨부) 시 can_download 권한 추가 체크 (inline 미리보기는 can_read만으로 허용)
        if (!$inline && !$this->storage->checkPermission($storageId, 'can_download')) {
            http_response_code(403);
            exit(__('no_permission_dot'));
        }
        
        // 원격 스토리지인 경우
        if ($this->isRemoteStorage($storageId)) {
            $this->downloadRemote($storageId, $relativePath, $inline);
            return;
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $fullPath)) {
            http_response_code(400);
            exit(__('api_err_invalid_path', '잘못된 경로입니다.'));
        }
        
        // 폴더인 경우 ZIP으로 압축해서 다운로드
        if (is_dir($fullPath)) {
            $this->downloadFolderAsZip($fullPath);
            return;
        }
        
        if (!is_file($fullPath)) {
            http_response_code(404);
            exit(__('file_not_found'));
        }
        
        $filename = basename($fullPath);
        $filesize = filesize($fullPath);
        $mimeType = $this->getMimeType($fullPath);
        
        // php.ini 설정에 의존하지 않도록 런타임 설정
        @set_time_limit(0);                    // 실행 시간 제한 해제
        @ini_set('max_execution_time', '0');   // 실행 시간 제한 해제 (대체)
        ignore_user_abort(false);              // 연결 끊기면 중단
        
        // 출력 버퍼링 완전 비활성화
        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', 'Off');
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // 0바이트 파일 처리
        if ($filesize === 0) {
            header('Content-Type: ' . $mimeType);
            $filenameSafe = preg_replace('/[^\x20-\x7E]|["\\]/', '_', $filename);
            $filenameEncoded = rawurlencode($filename);
            if ($inline) {
                header("Content-Disposition: inline; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
            } else {
                header("Content-Disposition: attachment; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
            }
            header('Content-Length: 0');
            exit;
        }
        
        // 범위 요청 처리 (이어받기)
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
                    http_response_code(416); // Range Not Satisfiable
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
        
        header('Content-Type: ' . $mimeType);
        
        // RFC 5987 형식으로 파일명 인코딩 (모든 브라우저 지원)
        $filenameSafe = preg_replace('/[^\x20-\x7E]|["\\]/', '_', $filename);
        $filenameEncoded = rawurlencode($filename);
        
        if ($inline) {
            header("Content-Disposition: inline; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
            // 비디오/오디오 inline 재생 시 CSP 제거
            if (strpos($mimeType, 'video/') === 0 || strpos($mimeType, 'audio/') === 0) {
                header_remove('Content-Security-Policy');
            }
            // SVG inline 서빙 시 스크립트 실행 차단
            if ($mimeType === 'image/svg+xml') {
                header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'none'; sandbox;");
            }
        } else {
            header("Content-Disposition: attachment; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
        }
        
        header('Content-Length: ' . ($end - $start + 1));
        header('Accept-Ranges: bytes');
        header('X-Accel-Buffering: no'); // nginx 버퍼링 비활성화
        
        // 비디오/오디오 inline 재생 시 브라우저 캐시 허용 (Range 요청 성능)
        if ($inline && (strpos($mimeType, 'video/') === 0 || strpos($mimeType, 'audio/') === 0)) {
            header('Cache-Control: private, max-age=3600');
        } else {
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        
        // 무제한 속도 + 전체 파일 요청 = readfile() 사용 (가장 빠름)
        if ($speedLimit <= 0 && !$isPartial) {
            readfile($fullPath);
            exit;
        }
        
        $fp = fopen($fullPath, 'rb');
        if ($fp === false) {
            http_response_code(500);
            exit(__('api_err_file_open_failed', '파일을 열 수 없습니다.'));
        }
        fseek($fp, $start);
        
        $remaining = $end - $start + 1;
        
        // 속도 제한 설정 (MB/s 단위, 0 = 무제한)
        if ($speedLimit > 0) {
            $bytesPerSecond = $speedLimit * 1024 * 1024;
            $chunkSize = 262144; // 256KB 청크
            $startTime = microtime(true);
            $totalSent = 0;
            
            while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
                $chunk = min($chunkSize, $remaining);
                echo fread($fp, $chunk);
                $remaining -= $chunk;
                $totalSent += $chunk;
                flush();
                
                if ($remaining > 0) {
                    $elapsed = microtime(true) - $startTime;
                    $expectedTime = $totalSent / $bytesPerSecond;
                    $sleepTime = $expectedTime - $elapsed;
                    
                    if ($sleepTime > 0) {
                        usleep((int)($sleepTime * 1000000));
                    }
                }
            }
        } else {
            // 무제한 속도 (부분 요청)
            $chunkSize = 1048576; // 1MB 청크
            while ($remaining > 0 && !feof($fp)) {
                $chunk = min($chunkSize, $remaining);
                echo fread($fp, $chunk);
                $remaining -= $chunk;
            }
            flush();
        }
        
        fclose($fp);
        exit;
    }
    
    /**
     * 원격 스토리지 파일 다운로드 (FTP, SFTP, WebDAV, S3)
     */
    private function downloadRemote(int $storageId, string $relativePath, bool $inline = false): void {
        $adapter = $this->getAdapter($storageId);
        if (!$adapter) {
            http_response_code(500);
            exit($this->getLastAdapterError());
        }
        
        // 폴더인 경우 다운로드 불가
        if ($adapter->isDir($relativePath)) {
            http_response_code(400);
            exit(__('remote_folder_no_direct_download'));
        }
        
        if (!$adapter->exists($relativePath)) {
            http_response_code(404);
            exit(__('file_not_found'));
        }
        
        $filename = basename($relativePath);
        $filesize = $adapter->getSize($relativePath);
        $mimeType = $adapter->getMime($relativePath);
        
        // 출력 버퍼링 비활성화
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // 공통 헤더
        header('Content-Type: ' . $mimeType);
        header('Accept-Ranges: bytes');
        
        $filenameSafe = preg_replace('/[^\x20-\x7E]|["\\]/', '_', $filename);
        $filenameEncoded = rawurlencode($filename);
        if ($inline) {
            header("Content-Disposition: inline; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
        } else {
            header("Content-Disposition: attachment; filename=\"{$filenameSafe}\"; filename*=UTF-8''{$filenameEncoded}");
        }
        
        // ★ Range 헤더 처리 (부분 다운로드) - MP3 metadata 프리로드, 영상 seek 등 지원
        // readPartial을 지원하는 adapter만 Range 처리 (SFTP, WebDAV, S3, SMB)
        // ★ FTP 제외 (배포 사용자 피드백): FTP readPartial은 Range 지원 불안정 →
        //   Content-Length 불일치로 HTTP/2 프로토콜 에러 (ERR_HTTP2_PROTOCOL_ERROR) 발생
        $_adapterClass = $adapter ? strtolower(get_class($adapter)) : '';
        $_isFtpAdapter = (strpos($_adapterClass, 'ftp') !== false && strpos($_adapterClass, 'sftp') === false);
        $rangeRequested = isset($_SERVER['HTTP_RANGE']) && method_exists($adapter, 'readPartial') && !$_isFtpAdapter;
        if ($rangeRequested && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = (int)$matches[1];
            $end = (!empty($matches[2])) ? (int)$matches[2] : ($filesize - 1);
            
            // 범위 검증
            if ($start > $end || $start >= $filesize) {
                http_response_code(416); // Range Not Satisfiable
                header("Content-Range: bytes */{$filesize}");
                exit;
            }
            if ($end >= $filesize) {
                $end = $filesize - 1;
            }
            
            $length = $end - $start + 1;
            
            @set_time_limit(0);
            ignore_user_abort(false);
            
            // ★ 먼저 첫 청크 시도: 실패하면 헤더 보내기 전이라 깔끔하게 에러 응답 가능
            //   헤더 보낸 후 실패하면 Content-Length 불일치로 HTTP/2 프로토콜 에러 발생
            $chunkSize = 1024 * 1024;
            $firstReadLen = min($chunkSize, $length);
            $firstBuffer = '';
            try {
                $firstBuffer = $adapter->readPartial($relativePath, $start, $firstReadLen);
            } catch (\Throwable $e) {
                $firstBuffer = '';
            }
            if ($firstBuffer === '' || $firstBuffer === false) {
                // readPartial 실패 → 전체 다운로드 폴백 (Range 처리 포기)
                // 이 경우 클라이언트가 200 OK + 전체 크기 받음 (HTTP/2 안전)
                http_response_code(200);
                header('Content-Length: ' . $filesize);
                if (method_exists($adapter, 'streamToOutput')) {
                    $adapter->streamToOutput($relativePath);
                } else {
                    echo $adapter->read($relativePath);
                }
                exit;
            }
            
            // 첫 청크 성공 → 206 + 청크 단위 전송
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$filesize}");
            header('Content-Length: ' . $length);
            
            // 첫 청크 flush
            echo $firstBuffer;
            @ob_flush();
            @flush();
            $actualFirst = strlen($firstBuffer);
            $remaining = $length - $actualFirst;
            $offset = $start + $actualFirst;
            // 첫 청크가 요청보다 적게 반환 → 일관성 문제. Content-Length 불일치 방지 위해
            //   나머지를 0으로 채우기보다는 그냥 끊고 클라이언트 재시도에 맡김
            //   (실제로는 FTP 제외로 이 경로 타는 일은 드물어야 함)
            if ($actualFirst < $firstReadLen) {
                exit; // 일부만 전송되었지만 TCP 레벨 close로 마무리
            }
            
            // 나머지 청크 전송
            while ($remaining > 0) {
                if (connection_aborted()) break;
                $readLen = min($chunkSize, $remaining);
                try {
                    $buffer = $adapter->readPartial($relativePath, $offset, $readLen);
                } catch (\Throwable $e) {
                    $buffer = '';
                }
                if ($buffer === '' || $buffer === false) break;
                echo $buffer;
                @ob_flush();
                @flush();
                $actualLen = strlen($buffer);
                $offset += $actualLen;
                $remaining -= $actualLen;
                if ($actualLen < $readLen) break;
            }
            exit;
        }
        
        // Range 요청이 아니거나 readPartial 미지원 → 전체 전송 (기존 동작)
        header('Content-Length: ' . $filesize);
        
        // 파일 내용 출력 (스트리밍 우선, 폴백으로 read)
        if (method_exists($adapter, 'streamToOutput')) {
            // FTP 등 원격 스토리지: 직접 스트리밍 (메모리 절약)
            @set_time_limit(0); // 대용량 파일 타임아웃 방지
            if (!$adapter->streamToOutput($relativePath)) {
                // 스트리밍 실패 시 read() 폴백
                $content = $adapter->read($relativePath);
                if (!empty($content)) {
                    echo $content;
                } else {
                    http_response_code(500);
                    exit(__('remote_download_fail', '원격 파일 다운로드 실패'));
                }
            }
        } else {
            $content = $adapter->read($relativePath);
            echo $content;
        }
        exit;
    }
    
    // 폴더를 ZIP으로 압축해서 다운로드
    private function downloadFolderAsZip(string $folderPath): void {
        // ★ 잔류 정리 안전망 (v5.8.1c — 다운로드 중단 시 unlink 못 한 zip 자동 정리)
        //   readfile() 도중 사용자 abort/네트워크 끊김 시 @unlink 미실행 → 임시폴더 누수.
        //   1시간 이상 된 것만 자동 삭제 (현재 진행 중인 다운로드 영향 없음).
        foreach (@glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'folder_*.zip') ?: [] as $_old) {
            if (is_file($_old) && filemtime($_old) < time() - 3600) @unlink($_old);
        }
        
        $folderName = basename($folderPath);
        $zipName = $folderName . '.zip';
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('folder_') . '.zip';
        
        // php.ini 설정에 의존하지 않도록 런타임 설정
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        ignore_user_abort(false);
        
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            exit(__('zip_create_fail'));
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folderPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        $fileCount = 0;
        foreach ($iterator as $file) {
            $relativePath = $folderName . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($file->getPathname(), $relativePath);
                $fileCount++;
            }
        }
        
        // 빈 폴더인 경우 루트 폴더만 추가
        if ($fileCount === 0) {
            $zip->addEmptyDir($folderName);
        }
        
        $zip->close();
        
        // 출력 버퍼링 완전 비활성화
        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', 'Off');
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $zipSize = filesize($zipPath);
        
        // RFC 5987 형식으로 파일명 인코딩
        $zipNameSafe = preg_replace('/[^\x20-\x7E]|["\\]/', '_', $zipName);
        $zipNameEncoded = rawurlencode($zipName);
        
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename=\"{$zipNameSafe}\"; filename*=UTF-8''{$zipNameEncoded}");
        header('Content-Length: ' . $zipSize);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Accel-Buffering: no');
        
        readfile($zipPath);
        
        // 임시 파일 삭제
        @unlink($zipPath);
        exit;
    }
    
    // 폴더 생성
    public function createFolder(int $storageId, string $relativePath, string $folderName): array {
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => __('api_err_no_write_perm', '쓰기 권한이 없습니다.')];
        }
        
        $safeFolderName = $this->sanitizeFilename($folderName);
        
        // 원격 스토리지인 경우
        if ($this->isRemoteStorage($storageId)) {
            $adapter = $this->getAdapter($storageId);
            if (!$adapter) {
                return ['success' => false, 'error' => $this->getLastAdapterError()];
            }
            
            $remotePath = empty($relativePath) ? $safeFolderName : $relativePath . '/' . $safeFolderName;
            
            if ($adapter->exists($remotePath)) {
                return ['success' => false, 'error' => __('api_err_same_folder_exists', '같은 이름의 폴더가 이미 있습니다.')];
            }
            
            if ($adapter->mkdir($remotePath)) {
                return ['success' => true, 'name' => $safeFolderName, 'remote' => true];
            }
            return ['success' => false, 'error' => __('api_err_folder_create_failed', '폴더 생성에 실패했습니다.')];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $targetDir = $this->buildPath($basePath, $relativePath);
        $newFolder = $targetDir . DIRECTORY_SEPARATOR . $safeFolderName;
        
        if (!$this->isPathSafe($basePath, $newFolder)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (is_dir($newFolder)) {
            return ['success' => false, 'error' => __('api_err_same_folder_exists', '같은 이름의 폴더가 이미 있습니다.')];
        }
        
        if (!mkdir($newFolder, 0755, true)) {
            return ['success' => false, 'error' => __('api_err_folder_create_failed', '폴더 생성에 실패했습니다.')];
        }
        
        // 인덱스 추가
        $indexPath = empty($relativePath) ? basename($newFolder) : $relativePath . '/' . basename($newFolder);
        $this->fileIndex->addFile($storageId, $indexPath, [
            'is_dir' => 1,
            'size' => 0,
            'modified' => date('Y-m-d H:i:s')
        ]);
        
        return ['success' => true, 'name' => basename($newFolder)];
    }
    
    // 파일/폴더 삭제
    public function delete(int $storageId, string $relativePath, string $progressFile = '', bool $permanent = false): array {
        @set_time_limit(0);
        
        // 빈 경로 삭제 차단 (스토리지 루트 삭제 방지)
        if (empty(trim($relativePath, '/\\ '))) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!$this->storage->checkPermission($storageId, 'can_delete')) {
            return ['success' => false, 'error' => __('api_err_no_delete_perm', '삭제 권한이 없습니다.')];
        }
        
        // 잠긴 파일 체크
        if ($this->isFileLocked($storageId, $relativePath)) {
            return ['success' => false, 'error' => __('api_err_file_locked', '잠긴 파일은 삭제할 수 없습니다.')];
        }
        
        // 대량 삭제 감지
        $bulkCheck = $this->checkBulkOperation('delete');
        if (!$bulkCheck['allowed']) {
            return ['success' => false, 'error' => '🔐 ' . $bulkCheck['reason']];
        }
        
        // 원격 스토리지인 경우 (휴지통으로 다운로드 후 원격에서 삭제)
        if ($this->isRemoteStorage($storageId)) {
            return $this->deleteRemoteToTrash($storageId, $relativePath);
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $fullPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => __('file_not_found')];
        }
        
        $isDir = is_dir($fullPath);
        
        // 삭제 전 파일/폴더 크기 계산
        $deleteSize = $isDir ? $this->getDirectorySize($fullPath) : filesize($fullPath);
        
        // vault 관련 항목은 자동으로 영구 삭제 (휴지통 복원 시 암호화 구조 깨짐 방지)
        if (!$permanent) {
            // 1. vault 폴더 자체 (.vault.json 포함)
            if ($isDir && file_exists($fullPath . DIRECTORY_SEPARATOR . '.vault.json')) {
                $permanent = true;
            }
            // 2. vault 폴더 안의 파일/폴더 (부모에 .vault.json이 있으면)
            if (!$permanent) {
                $parentDir = dirname($fullPath);
                // 부모 또는 상위 경로에서 .vault.json 검색 (최대 5단계)
                $checkDir = $parentDir;
                for ($i = 0; $i < 5; $i++) {
                    if (file_exists($checkDir . DIRECTORY_SEPARATOR . '.vault.json')) {
                        $permanent = true;
                        break;
                    }
                    $upper = dirname($checkDir);
                    if ($upper === $checkDir || strlen($upper) < strlen($basePath)) break;
                    $checkDir = $upper;
                }
            }
        }
        
        // 휴지통으로 이동 (permanent=true이면 즉시 영구 삭제)
        if ($permanent) {
            if ($isDir) {
                $this->deleteDirectory($fullPath);
                $delOk = !is_dir($fullPath); // 삭제 확인
            } else {
                $delOk = @unlink($fullPath);
            }
            if (!$delOk) {
                return ['success' => false, 'error' => __('err_delete_failed', '삭제에 실패했습니다.')];
            }
            $trashResult = ['success' => true];
        } else {
            $trashResult = $this->moveToTrash($storageId, $relativePath, $fullPath, $progressFile);
        }
        
        if (!$trashResult['success']) {
            return $trashResult;
        }
        
        // 사용량 감소
        $this->storage->updateUsedSize($storageId, -$deleteSize);
        
        // 인덱스에서 삭제
        if ($isDir) {
            $this->fileIndex->removeFolder($storageId, $relativePath);
        } else {
            $this->fileIndex->removeFile($storageId, $relativePath);
        }
        
        // 관련 공유 링크 정리
        $this->cleanupSharesForPath($storageId, $relativePath, $isDir);
        
        return ['success' => true];
    }
    
    /**
     * 원격 스토리지 파일을 휴지통으로 이동 (다운로드 후 삭제)
     */
    private function deleteRemoteToTrash(int $storageId, string $relativePath): array {
        $adapter = $this->getAdapter($storageId);
        if (!$adapter) {
            return ['success' => false, 'error' => $this->getLastAdapterError()];
        }
        
        if (!$adapter->exists($relativePath)) {
            return ['success' => false, 'error' => __('file_not_found')];
        }
        
        // 휴지통 폴더 준비
        $trashDir = TRASH_PATH;
        if (!is_dir($trashDir)) {
            mkdir($trashDir, 0755, true);
        }
        
        $user = $this->auth->getUser();
        $trashId = uniqid('trash_');
        $filename = basename($relativePath);
        $isDir = $adapter->isDir($relativePath);
        
        // 휴지통 경로
        $trashPath = $trashDir . DIRECTORY_SEPARATOR . $trashId;
        
        // 원격 파일/폴더를 로컬 휴지통으로 다운로드
        if ($isDir) {
            // 폴더인 경우 재귀적으로 다운로드
            if (!$this->downloadRemoteFolder($adapter, $relativePath, $trashPath)) {
                return ['success' => false, 'error' => __('trash_move_fail_folder_error')];
            }
        } else {
            // 파일인 경우 직접 다운로드
            $content = $adapter->read($relativePath);
            if ($content === false) {
                return ['success' => false, 'error' => __('trash_move_fail_read_error')];
            }
            if (file_put_contents($trashPath, $content) === false) {
                return ['success' => false, 'error' => __('trash_move_fail_save_error')];
            }
        }
        
        // 원격에서 삭제
        if (!$adapter->delete($relativePath)) {
            // 다운로드 성공했지만 원격 삭제 실패 시 휴지통 파일 삭제
            if ($isDir) {
                $this->deleteDirectory($trashPath);
            } else {
                @unlink($trashPath);
            }
            return ['success' => false, 'error' => __('remote_file_delete_fail')];
        }
        
        // 휴지통 DB에 기록 (원격 스토리지 표시)
        $fileSize = $isDir ? $this->getDirectorySize($trashPath) : filesize($trashPath);
        $trash = $this->db->load('trash');
        $trash[] = [
            'id' => $trashId,
            'name' => $filename,
            'original_path' => $relativePath,
            'storage_id' => $storageId,
            'deleted_by' => $user['id'] ?? 0,
            'deleted_by_name' => $user['display_name'] ?? $user['username'] ?? '',
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_dir' => $isDir,
            'size' => $fileSize,
            'trash_path' => $trashPath,
            'is_remote' => true  // 원격 스토리지 표시
        ];
        $this->db->save('trash', $trash);
        
        return ['success' => true, 'remote' => true];
    }
    
    /**
     * 원격 폴더를 로컬로 재귀 다운로드
     */
    private function downloadRemoteFolder($adapter, string $remotePath, string $localPath): bool {
        if (!mkdir($localPath, 0755, true)) {
            return false;
        }
        
        $items = $adapter->list($remotePath);
        if ($items === false || !is_array($items)) {
            return false;
        }
        
        foreach ($items as $item) {
            $itemRemotePath = rtrim($remotePath, '/') . '/' . $item['name'];
            $itemLocalPath = $localPath . DIRECTORY_SEPARATOR . $item['name'];
            
            if ($item['is_dir']) {
                if (!$this->downloadRemoteFolder($adapter, $itemRemotePath, $itemLocalPath)) {
                    return false;
                }
            } else {
                $content = $adapter->read($itemRemotePath);
                if ($content === false || file_put_contents($itemLocalPath, $content) === false) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    // 휴지통으로 이동
    private function moveToTrash(int $storageId, string $relativePath, string $fullPath, string $progressFile = ''): array {
        @set_time_limit(0);
        // 휴지통 폴더 경로
        $trashDir = TRASH_PATH;
        if (!is_dir($trashDir)) {
            mkdir($trashDir, 0755, true);
        }
        
        $user = $this->auth->getUser();
        $isDir = is_dir($fullPath);
        $trashId = uniqid('trash_');
        
        // 휴지통 내 고유 경로 (ID 기반)
        $trashPath = $trashDir . DIRECTORY_SEPARATOR . $trashId;
        
        // 파일/폴더를 휴지통으로 이동
        // 폴더이고 진행률 추적이 필요하면 copyDirectory 사용 (파일 단위 진행률 표시)
        if ($isDir && $progressFile) {
            if (!$this->copyDirectory($fullPath, $trashPath, $progressFile)) {
                return ['success' => false, 'error' => __('trash_move_fail')];
            }
            $this->deleteDirectory($fullPath);
        } else if (!@rename($fullPath, $trashPath)) {
            // rename 실패 시 복사 후 삭제
            if ($isDir) {
                if (!$this->copyDirectory($fullPath, $trashPath, $progressFile)) {
                    return ['success' => false, 'error' => __('trash_move_fail')];
                }
                $this->deleteDirectory($fullPath);
            } else {
                if (!@copy($fullPath, $trashPath)) {
                    return ['success' => false, 'error' => __('trash_move_fail')];
                }
                if (!@unlink($fullPath)) {
                    @unlink($trashPath);
                    return ['success' => false, 'error' => __('file_in_use', '파일이 사용 중이어서 삭제할 수 없습니다.')];
                }
            }
        }
        
        // 휴지통 DB에 기록
        $trash = $this->db->load('trash');
        $fileCount = $isDir ? $this->countAllFiles($trashPath) : 1;
        $trash[] = [
            'id' => $trashId,
            'name' => basename($fullPath),
            'original_path' => $relativePath,
            'storage_id' => $storageId,
            'deleted_by' => $user['id'] ?? 0,
            'deleted_by_name' => $user['display_name'] ?? $user['username'] ?? '',
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_dir' => $isDir,
            'size' => $isDir ? $this->getDirectorySize($trashPath) : filesize($trashPath),
            'file_count' => $fileCount,
            'trash_path' => $trashPath
        ];
        $this->db->save('trash', $trash);
        
        return ['success' => true];
    }
    
    // 휴지통에서 복원
    public function restoreFromTrash(string $trashId, string $progressFile = ''): array {
        @set_time_limit(0);
        $trash = $this->db->load('trash');
        $item = null;
        $itemIndex = -1;
        
        foreach ($trash as $index => $t) {
            if ($t['id'] === $trashId) {
                $item = $t;
                $itemIndex = $index;
                break;
            }
        }
        
        if (!$item) {
            return ['success' => false, 'error' => __('api_err_trash_not_found', '휴지통에서 찾을 수 없습니다.')];
        }
        
        // 이전 방식으로 저장된 항목 (trash_path 없음) - DB에서만 제거
        if (empty($item['trash_path'])) {
            unset($trash[$itemIndex]);
            $this->db->save('trash', array_values($trash));
            return ['success' => false, 'error' => __('old_delete_no_restore')];
        }
        
        $storageId = $item['storage_id'];
        
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => __('api_err_no_write_perm', '쓰기 권한이 없습니다.')];
        }
        
        $trashPath = $item['trash_path'];
        
        if (!file_exists($trashPath)) {
            // 휴지통 파일이 없으면 DB에서만 제거
            unset($trash[$itemIndex]);
            $this->db->save('trash', array_values($trash));
            return ['success' => false, 'error' => __('trash_file_missing')];
        }
        
        // 원격 스토리지인 경우
        if (!empty($item['is_remote']) && $this->isRemoteStorage($storageId)) {
            return $this->restoreToRemote($item, $itemIndex, $trash);
        }
        
        // 로컬 스토리지 복원
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
        }
        
        $originalPath = $this->buildPath($basePath, $item['original_path']);
        
        // 원래 경로의 상위 폴더가 없으면 생성
        $parentDir = dirname($originalPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }
        
        // 원래 경로에 같은 이름이 있으면 새 이름 생성
        $restorePath = $this->getUniqueFilename($originalPath);
        
        // 복원
        // 폴더이고 진행률 추적이 필요하면 copyDirectory 사용 (파일 단위 진행률 표시)
        if ($item['is_dir'] && $progressFile) {
            if (!$this->copyDirectory($trashPath, $restorePath, $progressFile)) {
                return ['success' => false, 'error' => __('restore_fail')];
            }
            $this->deleteDirectory($trashPath);
        } else if (!@rename($trashPath, $restorePath)) {
            // rename 실패 시 복사 후 삭제
            if ($item['is_dir']) {
                if (!$this->copyDirectory($trashPath, $restorePath, $progressFile)) {
                    return ['success' => false, 'error' => __('restore_fail')];
                }
                $this->deleteDirectory($trashPath);
            } else {
                if (!@copy($trashPath, $restorePath)) {
                    return ['success' => false, 'error' => __('restore_fail')];
                }
                @unlink($trashPath);
            }
        }
        
        // 휴지통 DB에서 제거
        unset($trash[$itemIndex]);
        $this->db->save('trash', array_values($trash));
        
        // 사용량 증가 (복원된 파일/폴더 크기)
        $restoredSize = $item['is_dir'] ? $this->getDirectorySize($restorePath) : filesize($restorePath);
        $this->storage->updateUsedSize($storageId, $restoredSize);
        
        // 인덱스에 다시 추가
        $restoredRelPath = substr($restorePath, strlen($basePath) + 1);
        $restoredRelPath = str_replace('\\', '/', $restoredRelPath);
        $this->reindexPath($storageId, $basePath, $restorePath);
        
        return ['success' => true, 'restored_path' => basename($restorePath)];
    }
    
    /**
     * 원격 스토리지로 복원 (로컬 휴지통에서 업로드)
     */
    private function restoreToRemote(array $item, int $itemIndex, array $trash): array {
        $storageId = $item['storage_id'];
        $adapter = $this->getAdapter($storageId);
        
        if (!$adapter) {
            return ['success' => false, 'error' => $this->getLastAdapterError()];
        }
        
        $trashPath = $item['trash_path'];
        $originalPath = $item['original_path'];
        
        // 원래 경로에 같은 이름이 있으면 새 이름 생성
        $restorePath = $originalPath;
        if ($adapter->exists($restorePath)) {
            $pathInfo = pathinfo($restorePath);
            $baseName = $pathInfo['filename'];
            $ext = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
            $dir = $pathInfo['dirname'] === '.' ? '' : $pathInfo['dirname'] . '/';
            $counter = 1;
            do {
                $restorePath = $dir . $baseName . ' (' . $counter . ')' . $ext;
                $counter++;
            } while ($adapter->exists($restorePath));
        }
        
        // 원격으로 업로드
        if ($item['is_dir']) {
            if (!$this->uploadFolderToRemote($adapter, $trashPath, $restorePath)) {
                return ['success' => false, 'error' => __('remote_restore_fail')];
            }
            $this->deleteDirectory($trashPath);
        } else {
            $content = file_get_contents($trashPath);
            if ($content === false || !$adapter->write($restorePath, $content)) {
                return ['success' => false, 'error' => __('remote_restore_fail')];
            }
            @unlink($trashPath);
        }
        
        // 휴지통 DB에서 제거
        unset($trash[$itemIndex]);
        $this->db->save('trash', array_values($trash));
        
        return ['success' => true, 'restored_path' => basename($restorePath), 'remote' => true];
    }
    
    /**
     * 로컬 폴더를 원격 스토리지로 재귀 업로드
     */
    private function uploadFolderToRemote($adapter, string $localPath, string $remotePath): bool {
        // 원격 폴더 생성
        if (!$adapter->mkdir($remotePath)) {
            return false;
        }
        
        $items = scandir($localPath);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $itemLocalPath = $localPath . DIRECTORY_SEPARATOR . $item;
            $itemRemotePath = $remotePath . '/' . $item;
            
            if (is_dir($itemLocalPath)) {
                if (!$this->uploadFolderToRemote($adapter, $itemLocalPath, $itemRemotePath)) {
                    return false;
                }
            } else {
                $content = file_get_contents($itemLocalPath);
                if ($content === false || !$adapter->write($itemRemotePath, $content)) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    // 휴지통에서 영구 삭제
    public function deleteFromTrash(string $trashId, string $progressFile = ''): array {
        $trash = $this->db->load('trash');
        $item = null;
        $itemIndex = -1;
        
        foreach ($trash as $index => $t) {
            if ($t['id'] === $trashId) {
                $item = $t;
                $itemIndex = $index;
                break;
            }
        }
        
        if (!$item) {
            return ['success' => false, 'error' => __('api_err_trash_not_found', '휴지통에서 찾을 수 없습니다.')];
        }
        
        $trashPath = $item['trash_path'] ?? '';
        
        // 파일/폴더 삭제 (trash_path가 있는 경우만)
        if (!empty($trashPath) && file_exists($trashPath)) {
            if (is_dir($trashPath)) {
                $this->deleteDirectory($trashPath, $progressFile);
            } else {
                @unlink($trashPath);
            }
        }
        
        // 파일 버전도 삭제
        $storageId = $item['storage_id'] ?? 0;
        $originalPath = $item['original_path'] ?? '';
        if ($storageId && $originalPath) {
            $this->deleteFileVersions($storageId, $originalPath);
        }
        
        // 휴지통 DB에서 제거
        unset($trash[$itemIndex]);
        $this->db->save('trash', array_values($trash));
        
        return ['success' => true];
    }
    
    // 휴지통 비우기
    public function emptyTrash(?int $userId = null): array {
        $trash = $this->db->load('trash');
        $newTrash = [];
        $deletedCount = 0;
        
        foreach ($trash as $item) {
            // 사용자 ID가 지정되면 해당 사용자 것만 삭제
            if ($userId !== null && ($item['deleted_by'] ?? 0) != $userId) {
                $newTrash[] = $item;
                continue;
            }
            
            $trashPath = $item['trash_path'] ?? '';
            if (file_exists($trashPath)) {
                if (is_dir($trashPath)) {
                    $this->deleteDirectory($trashPath);
                } else {
                    @unlink($trashPath);
                }
            }
            
            // 파일 버전도 삭제
            $storageId = $item['storage_id'] ?? 0;
            $originalPath = $item['original_path'] ?? '';
            $wasDirectory = $item['is_directory'] ?? false;
            if ($storageId && $originalPath) {
                if ($wasDirectory) {
                    $this->deleteDirectoryVersions($storageId, $originalPath, '');
                }
                $this->deleteFileVersions($storageId, $originalPath);
            }
            
            $deletedCount++;
        }
        
        $this->db->save('trash', $newTrash);
        
        return ['success' => true, 'deleted_count' => $deletedCount];
    }
    
    // 휴지통 목록 조회
    public function getTrashList(?int $userId = null): array {
        $trash = $this->db->load('trash');
        
        if ($userId !== null) {
            $trash = array_filter($trash, function($item) use ($userId) {
                return ($item['deleted_by'] ?? 0) == $userId;
            });
        }
        
        // 최근 삭제순 정렬
        usort($trash, function($a, $b) {
            return strtotime($b['deleted_at']) - strtotime($a['deleted_at']);
        });
        
        // 스토리지 이름 + 폴더 내부 파일 수 추가
        foreach ($trash as &$item) {
            $storage = $this->storage->getStorageById($item['storage_id']);
            $item['storage_name'] = $storage['name'] ?? __('unknown');
            // file_count: DB에 저장된 값 우선, 없으면 계산
            if (!isset($item['file_count'])) {
                if (!empty($item['is_dir']) && !empty($item['trash_path']) && is_dir($item['trash_path'])) {
                    $item['file_count'] = $this->countAllFiles($item['trash_path']);
                } else {
                    $item['file_count'] = $item['is_dir'] ? 0 : 1;
                }
            }
        }
        
        return ['success' => true, 'items' => array_values($trash)];
    }
    
    // 폴더 복사 (휴지통 이동용)
    private function copyDirectory(string $src, string $dst, ?string $progressFile = null, ?array &$progressState = null): bool {
        $dir = opendir($src);
        if (!$dir) return false;
        
        @set_time_limit(0);
        @mkdir($dst, 0755, true);
        
        // progressFile이 있는데 progressState가 없으면 자동 초기화
        if ($progressFile && $progressState === null) {
            $totalSize = $this->getDirectorySize($src);
            $totalFiles = $this->countAllFiles($src);
            $progressState = [
                'copied' => 0,
                'total' => $totalSize,
                'filesDone' => 0,
                'totalFiles' => $totalFiles,
                'lastUpdate' => microtime(true)
            ];
        }
        
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            
            // 취소 체크 (파일 단위)
            if ($this->isCancelled()) {
                closedir($dir);
                return false;
            }
            
            $srcPath = $src . DIRECTORY_SEPARATOR . $file;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($srcPath)) {
                if (!$this->copyDirectory($srcPath, $dstPath, $progressFile, $progressState)) {
                    closedir($dir);
                    return false;
                }
            } else {
                $fileSize = filesize($srcPath) ?: 0;
                
                // 대용량 파일(50MB+)은 청크 복사
                if ($fileSize > 50 * 1024 * 1024) {
                    $sfp = @fopen($srcPath, 'rb');
                    $dfp = @fopen($dstPath, 'wb');
                    if (!$sfp || !$dfp) {
                        if ($sfp) fclose($sfp);
                        if ($dfp) fclose($dfp);
                        closedir($dir);
                        return false;
                    }
                    $chunkSize = 8 * 1024 * 1024;
                    $fileCopied = 0;
                    $lastChunkUpdate = 0;
                    $chunkCancelled = false;
                    while (!feof($sfp)) {
                        // 8MB 청크 단위로 취소 체크
                        if ($this->isCancelled()) {
                            $chunkCancelled = true;
                            break;
                        }
                        $data = fread($sfp, $chunkSize);
                        if ($data === false) break;
                        $written = fwrite($dfp, $data);
                        if ($written === false) break;
                        $fileCopied += $written;
                        
                        // 진행률 파일 갱신 (0.5초마다)
                        if ($progressFile && $progressState !== null) {
                            $now = microtime(true);
                            if ($now - $lastChunkUpdate > 0.5) {
                                @file_put_contents($progressFile, json_encode([
                                    'copied' => $progressState['copied'] + $fileCopied,
                                    'total' => $progressState['total'],
                                    'percent' => round((($progressState['copied'] + $fileCopied) / max(1, $progressState['total'])) * 100),
                                    'file' => $file,
                                    'filesDone' => $progressState['filesDone'],
                                    'totalFiles' => $progressState['totalFiles'] ?? 0
                                ]));
                                $lastChunkUpdate = $now;
                            }
                        }
                    }
                    fclose($sfp);
                    fclose($dfp);
                    // 취소 시 부분 복사된 파일 삭제
                    if ($chunkCancelled) {
                        @unlink($dstPath);
                        closedir($dir);
                        return false;
                    }
                    if ($fileCopied < $fileSize) {
                        closedir($dir);
                        return false;
                    }
                } else {
                    if (!@copy($srcPath, $dstPath)) {
                        closedir($dir);
                        return false;
                    }
                }
                
                // 진행률 업데이트
                if ($progressFile && $progressState !== null) {
                    $progressState['copied'] += $fileSize;
                    $progressState['filesDone']++;
                    $now = microtime(true);
                    if ($now - $progressState['lastUpdate'] > 0.5) {
                        @file_put_contents($progressFile, json_encode([
                            'copied' => $progressState['copied'],
                            'total' => $progressState['total'],
                            'percent' => round(($progressState['copied'] / max(1, $progressState['total'])) * 100),
                            'file' => $file,
                            'filesDone' => $progressState['filesDone'],
                            'totalFiles' => $progressState['totalFiles'] ?? 0
                        ]));
                        $progressState['lastUpdate'] = $now;
                    }
                }
            }
        }
        
        closedir($dir);
        return true;
    }
    
    // 이름 변경
    public function rename(int $storageId, string $relativePath, string $newName): array {
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => __('api_err_no_write_perm', '쓰기 권한이 없습니다.')];
        }
        
        // 잠긴 파일 체크
        if ($this->isFileLocked($storageId, $relativePath)) {
            return ['success' => false, 'error' => __('api_err_file_locked_rename', '잠긴 파일은 이름을 변경할 수 없습니다.')];
        }
        
        $safeNewName = $this->sanitizeFilename($newName);
        
        
        // 원격 스토리지인 경우
        if ($this->isRemoteStorage($storageId)) {
            $adapter = $this->getAdapter($storageId);
            if (!$adapter) {
                return ['success' => false, 'error' => $this->getLastAdapterError()];
            }
            
            if (!$adapter->exists($relativePath)) {
                return ['success' => false, 'error' => __('file_not_found')];
            }
            
            $parentDir = dirname($relativePath);
            $newRelPath = ($parentDir === '.' || $parentDir === '') 
                ? $safeNewName 
                : $parentDir . '/' . $safeNewName;
            
            if ($adapter->exists($newRelPath)) {
                return ['success' => false, 'error' => __('api_err_same_name_exists', '같은 이름이 이미 있습니다.')];
            }
            
            if ($adapter->rename($relativePath, $newRelPath)) {
                return ['success' => true, 'name' => $safeNewName, 'remote' => true];
            }
            return ['success' => false, 'error' => __('api_err_rename_failed', '이름 변경에 실패했습니다.')];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        $parentDir = dirname($fullPath);
        $newPath = $parentDir . DIRECTORY_SEPARATOR . $safeNewName;
        
        if (!$this->isPathSafe($basePath, $fullPath) || !$this->isPathSafe($basePath, $newPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!file_exists($fullPath)) {
            error_log('[FileStation] rename file_not_found: storage=' . $storageId . ', path=' . $relativePath . ', fullPath=' . $fullPath);
            return ['success' => false, 'error' => __('file_not_found'), 'debug' => ['storage_id' => $storageId, 'path' => $relativePath, 'fullPath' => $fullPath, 'locale' => setlocale(LC_ALL, 0)]];
        }
        
        if (file_exists($newPath)) {
            return ['success' => false, 'error' => __('api_err_same_name_exists', '같은 이름이 이미 있습니다.')];
        }
        
        if (!rename($fullPath, $newPath)) {
            return ['success' => false, 'error' => __('api_err_rename_failed', '이름 변경에 실패했습니다.')];
        }
        
        // 인덱스 업데이트
        $parentRelPath = dirname($relativePath);
        $newRelPath = ($parentRelPath === '.' || $parentRelPath === '') 
            ? basename($newPath) 
            : $parentRelPath . '/' . basename($newPath);
        $this->fileIndex->moveFile($storageId, $relativePath, $newRelPath);
        
        return ['success' => true, 'name' => basename($newPath)];
    }
    
    // 이동
    public function move(int $sourceStorageId, string $sourcePath, int $destStorageId, string $destPath, string $duplicateAction = 'overwrite'): array {
        try {
            @set_time_limit(0);
            
            // 잠긴 파일 체크
            if ($this->isFileLocked($sourceStorageId, $sourcePath)) {
                return ['success' => false, 'error' => __('api_err_file_locked_move', '잠긴 파일은 이동할 수 없습니다.')];
            }
            
            // 소스 쓰기/삭제 권한 확인
            if (!$this->storage->checkPermission($sourceStorageId, 'can_write')) {
                return ['success' => false, 'error' => __('src_storage_no_write')];
            }
            
            // 대상 쓰기 권한 확인
            if (!$this->storage->checkPermission($destStorageId, 'can_write')) {
                return ['success' => false, 'error' => __('dst_storage_no_write')];
            }
            
            // 원격 스토리지 처리
            $sourceIsRemote = $this->isRemoteStorage($sourceStorageId);
            $destIsRemote = $this->isRemoteStorage($destStorageId);
            
            // 원격 ↔ 로컬 간 이동 (복사 후 원본 삭제)
            if ($sourceIsRemote !== $destIsRemote) {
                $copyResult = $this->copy($sourceStorageId, $sourcePath, $destStorageId, $destPath, $duplicateAction);
                if ($copyResult['success'] ?? false) {
                    // 복사 성공 시 원본 삭제
                    if ($sourceIsRemote) {
                        $srcAdapter = $this->getAdapter($sourceStorageId);
                        if ($srcAdapter) {
                            if ($srcAdapter->isDir($sourcePath)) {
                                $this->deleteRemoteDir($srcAdapter, $sourcePath);
                            } else {
                                $srcAdapter->delete($sourcePath);
                            }
                        }
                    } else {
                        $srcBase = $this->storage->getRealPath($sourceStorageId);
                        $srcFull = $this->buildPath($srcBase, $sourcePath);
                        if (is_dir($srcFull)) {
                            $this->deleteDirectory($srcFull);
                        } else {
                            @unlink($srcFull);
                        }
                    }
                }
                return $copyResult;
            }
            
            // 동일 원격 스토리지 내 이동
            if ($sourceIsRemote && $sourceStorageId === $destStorageId) {
                return $this->moveRemote($sourceStorageId, $sourcePath, $destPath, $duplicateAction);
            }
            
            // 서로 다른 원격 스토리지 간 이동 (복사 후 원본 삭제)
            if ($sourceIsRemote && $destIsRemote && $sourceStorageId !== $destStorageId) {
                $copyResult = $this->copyRemoteToRemote($sourceStorageId, $sourcePath, $destStorageId, $destPath, $duplicateAction);
                if ($copyResult['success'] ?? false) {
                    $srcAdapter = $this->getAdapter($sourceStorageId);
                    if ($srcAdapter) {
                        if ($srcAdapter->isDir($sourcePath)) {
                            $this->deleteRemoteDir($srcAdapter, $sourcePath);
                        } else {
                            $srcAdapter->delete($sourcePath);
                        }
                    }
                }
                return $copyResult;
            }
            
            $sourceBasePath = $this->storage->getRealPath($sourceStorageId);
            $destBasePath = $this->storage->getRealPath($destStorageId);
            
            if (!$sourceBasePath) {
                return ['success' => false, 'error' => __('src_storage_not_found')];
            }
            if (!$destBasePath) {
                return ['success' => false, 'error' => __('dst_storage_not_found')];
            }
            
            $sourceFullPath = $this->buildPath($sourceBasePath, $sourcePath);
            $destFullPath = $this->buildPath($destBasePath, $destPath);
            
            if (!$this->isPathSafe($sourceBasePath, $sourceFullPath)) {
                return ['success' => false, 'error' => __('invalid_src_path')];
            }
            if (!$this->isPathSafe($destBasePath, $destFullPath)) {
                return ['success' => false, 'error' => __('invalid_dst_path')];
            }
            
            // 원본 존재 확인
            if (!file_exists($sourceFullPath)) {
                return ['success' => false, 'error' => __('src_file_not_exist') . $sourcePath];
            }
            
            // 대상 폴더 존재 확인 및 생성
            if (!is_dir($destFullPath)) {
                if (!@mkdir($destFullPath, 0755, true)) {
                    return ['success' => false, 'error' => __('dst_folder_create_fail')];
                }
            }
            
            $filename = basename($sourceFullPath);
            $newPath = $destFullPath . DIRECTORY_SEPARATOR . $filename;
            
            // 자기 자신으로 이동 방지
            $realSrc = realpath($sourceFullPath);
            $realDst = realpath($newPath);
            if ($realSrc !== false && $realDst !== false && $realSrc === $realDst) {
                return ['success' => true, 'skipped' => true];
            }
            
            // 중복 처리
            if (file_exists($newPath)) {
                switch ($duplicateAction) {
                    case 'skip':
                        return ['success' => true, 'skipped' => true];
                    case 'rename':
                        $newPath = $this->getUniqueFilename($newPath);
                        break;
                    case 'overwrite':
                    default:
                        // 덮어쓰기 전 기존 파일 크기 차감
                        $existingSize = is_dir($newPath) ? $this->getDirectorySize($newPath) : (filesize($newPath) ?: 0);
                        if ($existingSize > 0) {
                            $this->storage->updateUsedSize($destStorageId, -$existingSize);
                        }
                        
                        // 대량 덮어쓰기 감지
                        $bulkCheck = $this->checkBulkOperation('overwrite');
                        if (!$bulkCheck['allowed']) {
                            return ['success' => false, 'error' => '🔐 ' . $bulkCheck['reason']];
                        }
                        
                        // 덮어쓰기 전 파일 버전 저장 (파일인 경우만)
                        if (!is_dir($newPath)) {
                            $fileRelPath = ltrim($destPath . '/' . basename($newPath), '/');
                            $this->saveFileVersion($newPath, $fileRelPath, $destStorageId);
                        }
                        
                        // 기존 파일/폴더 삭제
                        if (is_dir($newPath)) {
                            $this->deleteDirectory($newPath);
                        } else {
                            @unlink($newPath);
                        }
                        break;
                }
            }
            
            // 같은 스토리지면 rename, 다른 스토리지면 copy + delete
            $isSameStorage = ($sourceStorageId === $destStorageId);
            $sourceSize = is_dir($sourceFullPath) ? $this->getDirectorySize($sourceFullPath) : (filesize($sourceFullPath) ?: 0);
            
            if ($isSameStorage) {
                // 같은 스토리지: rename 사용
                if (!@rename($sourceFullPath, $newPath)) {
                    return ['success' => false, 'error' => __('api_err_move_failed', '이동에 실패했습니다.')];
                }
            } else {
                // 다른 스토리지: 복사 후 삭제
                if (is_dir($sourceFullPath)) {
                    $dirSize = $this->getDirectorySize($sourceFullPath);
                    $totalFileCount = $this->countAllFiles($sourceFullPath);
                    $progressId = session_id() ?: uniqid('mv_');
                    $progressFile = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/copy_progress_' . md5($progressId . $sourcePath) . '.tmp';
                    $progressState = [
                        'copied' => 0,
                        'total' => $dirSize,
                        'filesDone' => 0,
                        'totalFiles' => $totalFileCount,
                        'lastUpdate' => 0
                    ];
                    $this->copyDirectory($sourceFullPath, $newPath, $progressFile, $progressState);
                    @unlink($progressFile);
                    
                    // 취소 시 부분 복사된 폴더 삭제, 원본은 유지
                    if ($this->isCancelled()) {
                        if (is_dir($newPath)) $this->deleteDirectory($newPath);
                        return ['success' => false, 'error' => 'cancelled'];
                    }
                    
                    $this->deleteDirectory($sourceFullPath);
                } else {
                    $fileSize = filesize($sourceFullPath) ?: 0;
                    $progressThreshold = 50 * 1024 * 1024; // 50MB
                    
                    if ($fileSize > $progressThreshold) {
                        // 대용량: 청크 복사 + 진행률
                        $progressId = session_id() ?: uniqid('mv_');
                        $progressFile = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/copy_progress_' . md5($progressId . $sourcePath) . '.tmp';
                        
                        $src = @fopen($sourceFullPath, 'rb');
                        $dst = @fopen($newPath, 'wb');
                        if (!$src || !$dst) {
                            if ($src) fclose($src);
                            if ($dst) fclose($dst);
                            @unlink($progressFile);
                            return ['success' => false, 'error' => __('api_err_move_failed', '이동에 실패했습니다.')];
                        }
                        
                        $chunkSize = 8 * 1024 * 1024;
                        $copied = 0;
                        $lastUpdate = 0;
                        $cancelled = false;
                        
                        while (!feof($src)) {
                            // 8MB 청크 단위로 취소 체크
                            if ($this->isCancelled()) {
                                $cancelled = true;
                                break;
                            }
                            $data = fread($src, $chunkSize);
                            if ($data === false) break;
                            $written = fwrite($dst, $data);
                            if ($written === false) break;
                            $copied += $written;
                            
                            $now = microtime(true);
                            if ($now - $lastUpdate > 0.5) {
                                @file_put_contents($progressFile, json_encode([
                                    'copied' => $copied,
                                    'total' => $fileSize,
                                    'percent' => round(($copied / $fileSize) * 100)
                                ]));
                                $lastUpdate = $now;
                            }
                        }
                        
                        fclose($src);
                        fclose($dst);
                        @unlink($progressFile);
                        
                        // 취소 시 부분 복사된 파일 삭제 (원본은 유지)
                        if ($cancelled) {
                            @unlink($newPath);
                            return ['success' => false, 'error' => 'cancelled'];
                        }
                        
                        if ($copied < $fileSize) {
                            @unlink($newPath);
                            return ['success' => false, 'error' => __('api_err_move_failed', '이동에 실패했습니다.')];
                        }
                    } else {
                        if (!@copy($sourceFullPath, $newPath)) {
                            return ['success' => false, 'error' => __('api_err_copy_failed', '파일 복사에 실패했습니다.')];
                        }
                    }
                    @unlink($sourceFullPath);
                }
                
                // 스토리지 사용량 업데이트
                $this->storage->updateUsedSize($sourceStorageId, -$sourceSize);
                $this->storage->updateUsedSize($destStorageId, $sourceSize);
            }
            
            // 인덱스 업데이트
            $newRelPath = empty($destPath) ? basename($newPath) : $destPath . '/' . basename($newPath);
            if ($isSameStorage) {
                $this->fileIndex->moveFile($sourceStorageId, $sourcePath, $newRelPath);
            }
            // 다른 스토리지로 이동 시 인덱스 처리는 api.php에서 수행
            
            // 원본 경로의 공유 정리
            $isDir = is_dir($newPath) || (isset($sourceFullPath) && !file_exists($sourceFullPath));
            $this->cleanupSharesForPath($sourceStorageId, $sourcePath, $isDir);
            
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => __('move_error') . $e->getMessage()];
        }
    }
    
    // 압축 해제
    public function extractZip(int $storageId, string $zipPath, string $destPath = '', ?callable $progressCallback = null, string $password = '', string $mode = 'folder'): array {
        if (!$this->storage->checkPermission($storageId, 'can_write')) {
            return ['success' => false, 'error' => __('api_err_no_write_perm', '쓰기 권한이 없습니다.')];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullZipPath = $this->buildPath($basePath, $zipPath);
        
        if (!$this->isPathSafe($basePath, $fullZipPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!file_exists($fullZipPath)) {
            return ['success' => false, 'error' => __('file_not_found')];
        }
        
        $ext = strtolower(pathinfo($fullZipPath, PATHINFO_EXTENSION));
        
        // 분할 압축 파일 (.001) — 7-Zip으로 해제
        if ($ext === '001') {
            return $this->extractSplitZip($basePath, $fullZipPath, $zipPath, $destPath, $progressCallback, $password, $mode);
        }
        
        // ZIP 이외 형식 (rar, 7z, iso, cab 등) — 7-Zip으로 해제
        // 프론트(app.js archiveExts)와 일치시킴 + 7-Zip이 추출 지원하는 주요 형식 포함.
        $sevenZipFormats = ['rar', '7z', 'iso', 'cab', 'wim', 'arj', 'lzh', 'tar', 'gz', 'tgz',
                            'bz2', 'tbz2', 'xz', 'txz', 'rpm', 'deb', 'dmg', 'msi', 'nsis',
                            'cpio', 'xar', 'lzma', 'z', 'taz', 'tlz', 'zst', 'tzst'];
        if (in_array($ext, $sevenZipFormats)) {
            return $this->extract7zip($basePath, $fullZipPath, $zipPath, $destPath, $progressCallback, $password, $mode);
        }
        
        if ($ext !== 'zip') {
            return ['success' => false, 'error' => __('api_err_unsupported_format', '지원하지 않는 형식입니다.')];
        }
        
        // ZIP: 7-Zip 바이너리가 있으면 7-Zip으로 해제 (PHP ZipArchive는 Windows에서 CP949/EUC-KR 등
        //   레거시 인코딩 파일명을 제대로 처리 못 해 폴더 구조가 깨지는 문제가 있음. 7-Zip은 정확히 처리).
        //   7-Zip이 없는 환경에서는 아래 PHP ZipArchive 경로로 폴백.
        $hasSevenZip = false;
        foreach (['C:\\Program Files\\7-Zip\\7z.exe', 'C:\\Program Files (x86)\\7-Zip\\7z.exe', '/usr/bin/7z', '/usr/local/bin/7z', '/usr/bin/7za', '/usr/bin/7zz', '/usr/local/bin/7zz', '/usr/bin/7zzs', '/usr/local/bin/7zzs', '/usr/bin/7zip', '/bin/7zz', '/bin/7z'] as $szp) {
            if (file_exists($szp)) { $hasSevenZip = true; break; }
        }
        if ($hasSevenZip) {
            return $this->extract7zip($basePath, $fullZipPath, $zipPath, $destPath, $progressCallback, $password, $mode);
        }
        
        // 압축 해제 경로 결정
        // mode='here': 압축파일이 있는 폴더에 직접 풀기 (내부 폴더 구조는 그대로 보존)
        // mode='folder'(기본): 압축파일명 폴더를 만들고 그 안에 풀기
        if ($mode === 'here' && empty($destPath)) {
            $extractDir = dirname($fullZipPath);
        } elseif (empty($destPath)) {
            $basename = basename($fullZipPath);
            if (strtolower(substr($basename, -4)) === '.zip') {
                $zipName = substr($basename, 0, -4);
            } else {
                $zipName = $basename;
            }
            $extractDir = dirname($fullZipPath) . DIRECTORY_SEPARATOR . $zipName;
        } else {
            $extractDir = $this->buildPath($basePath, $destPath);
        }
        
        // 중복 폴더명 처리 — "여기에 풀기"는 기존 폴더에 직접 쓰므로 고유화하지 않음
        $isHereMode = ($mode === 'here' && empty($destPath));
        if (!$isHereMode) {
            $extractDir = $this->getUniqueFilename($extractDir);
        }
        
        if (!$this->isPathSafe($basePath, $extractDir)) {
            return ['success' => false, 'error' => __('invalid_dst_path')];
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($fullZipPath);
        
        if ($result !== true) {
            return ['success' => false, 'error' => __('zip_open_fail') . $result . ')'];
        }
        
        // 암호 걸린 ZIP 감지 및 처리
        $isEncrypted = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat && ($stat['encryption_method'] ?? 0) != 0) {
                $isEncrypted = true;
                break;
            }
        }
        
        if ($isEncrypted) {
            if (empty($password)) {
                $zip->close();
                return ['success' => false, 'error' => 'password_required', 'need_password' => true];
            }
            $zip->setPassword($password);
        }
        
        // 폴더 생성
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }
        
        $totalFiles = $zip->numFiles;
        
        // 암호가 걸린 경우 첫 파일로 암호 검증
        if ($isEncrypted && $totalFiles > 0) {
            // 디렉토리가 아닌 첫 번째 파일 찾기
            $testFile = null;
            for ($i = 0; $i < $totalFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (substr($name, -1) !== '/') {
                    $testFile = $name;
                    break;
                }
            }
            if ($testFile !== null) {
                $testContent = $zip->getFromName($testFile);
                if ($testContent === false) {
                    $zip->close();
                    // 생성된 빈 폴더 삭제 (여기에 풀기면 기존 폴더 보호)
                    if (!$isHereMode) @rmdir($extractDir);
                    return ['success' => false, 'error' => __('zip_wrong_password', '압축 파일 암호가 틀립니다.'), 'need_password' => true];
                }
            }
        }
        
        // '여기에 풀기'(extractDir=사용자 폴더)에서 ZipArchive::extractTo는 같은 이름의 기존 파일을
        //   무조건 덮어써 데이터 손실 위험이 있다. → 임시 stage 폴더에 추출한 뒤 extractDir로 이동하되,
        //   기존 파일과 충돌하면 getUniqueFilename(name (2))으로 보존한다. (괄호 (2) 방식으로 통일)
        //   폴더 생성 모드는 extractDir이 빈 새 폴더라 충돌이 없으므로 기존 방식 유지.
        if ($isHereMode) {
            // stage는 사용자 폴더(extractDir)와 같은 파일시스템에 생성 (cross-device rename 방지).
            //   sys_get_temp_dir(/tmp)는 시놀로지 등에서 /volume1과 다른 fs라 최종 rename이 실패할 수 있음.
            $zipStage = rtrim($extractDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.fs_extract_tmp_' . md5($fullZipPath . microtime(true) . mt_rand());
            @mkdir($zipStage, 0700, true);
            $realStage = realpath($zipStage);
            // stage에 전체 추출 (Zip Slip 방어 포함)
            for ($i = 0; $i < $totalFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if ($filename === false || strpos($filename, '..') !== false) continue;
                $zip->extractTo($zipStage, $filename);
                $ex = realpath($zipStage . DIRECTORY_SEPARATOR . $filename);
                if ($ex && $realStage && !isSubPath($ex, $realStage)) { @unlink($ex); continue; }
                if ($progressCallback && ($i % 20 === 0 || $i === $totalFiles - 1)) {
                    $progressCallback($i + 1, $totalFiles, $filename);
                }
            }
            // stage → extractDir 으로 '최상위 항목 단위' 이동 (폴더/파일 충돌 시 '(2)'로 분리, 기존 데이터 보존)
            if (is_dir($zipStage)) {
                $zdh = @opendir($zipStage);
                if ($zdh) {
                    while (($zname = readdir($zdh)) !== false) {
                        if ($zname === '.' || $zname === '..') continue;
                        $zSrc = $zipStage . DIRECTORY_SEPARATOR . $zname;
                        $zDest = $extractDir . DIRECTORY_SEPARATOR . $zname;
                        if (file_exists($zDest)) $zDest = $this->getUniqueFilename($zDest); // 폴더/파일 통째로 (2)
                        @rename($zSrc, $zDest);
                    }
                    closedir($zdh);
                }
                $this->deleteDirectory($zipStage);
            }
        } elseif ($progressCallback && $totalFiles > 0) {
            $updateInterval = max(1, min(50, (int)($totalFiles * 0.05)));
            $lastUpdate = 0;
            $realExtractDir = realpath($extractDir);
            
            for ($i = 0; $i < $totalFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                // Zip Slip 방어: 경로 탐색 문자 차단
                if (strpos($filename, '..') !== false) continue;
                $zip->extractTo($extractDir, $filename);
                // 추출 후 경로 이탈 검증
                $extracted = realpath($extractDir . DIRECTORY_SEPARATOR . $filename);
                if ($extracted && $realExtractDir && !isSubPath($extracted, $realExtractDir)) {
                    @unlink($extracted);
                    continue;
                }
                
                if ($i - $lastUpdate >= $updateInterval || $i === $totalFiles - 1) {
                    $progressCallback($i + 1, $totalFiles, $filename);
                    $lastUpdate = $i;
                }
            }
        } else {
            // 전체 추출 후 경로 이탈 파일 정리
            $realExtractDir = realpath($extractDir);
            $zip->extractTo($extractDir);
            // 전체 추출 시에도 .. 포함 파일 검증
            for ($i = 0; $i < $totalFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strpos($filename, '..') !== false) {
                    $badFile = $extractDir . DIRECTORY_SEPARATOR . $filename;
                    if (file_exists($badFile)) @unlink($badFile);
                }
            }
        }
        
        $zip->close();
        
        // extracted_to: '여기에 풀기'는 extractDir이 현재 폴더 자체라 폴더명이 무의미하므로 압축파일명 표시.
        return [
            'success' => true, 
            'extracted_to' => $isHereMode ? basename($fullZipPath) : basename($extractDir),
            'file_count' => $totalFiles
        ];
    }
    
    // 압축 생성
    public function createZip(int $storageId, array $paths, string $zipName = '', ?callable $progressCallback = null, string $password = ''): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => __('api_err_no_read_perm', '읽기 권한이 없습니다.')];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        
        if (empty($paths)) {
            return ['success' => false, 'error' => __('api_err_select_files', '압축할 파일을 선택하세요.')];
        }
        
        // ZIP 파일명 결정
        if (empty($zipName)) {
            if (count($paths) === 1) {
                $firstPath = $this->buildPath($basePath, $paths[0]);
                $basename = basename($paths[0]);
                
                // 폴더는 이름 그대로 + .zip
                if (is_dir($firstPath)) {
                    $zipName = $basename . '.zip';
                } else {
                    // 파일: 알려진 확장자만 제거 (버전 번호 등은 유지)
                    $knownExtensions = ['txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 
                        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico',
                        'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a',
                        'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm',
                        'zip', 'rar', '7z', 'tar', 'gz', 'bz2',
                        'exe', 'msi', 'dmg', 'deb', 'rpm',
                        'html', 'htm', 'css', 'js', 'json', 'xml', 'php', 'py', 'java', 'c', 'cpp', 'h'];
                    
                    $lastDot = strrpos($basename, '.');
                    if ($lastDot !== false && $lastDot > 0) {
                        $ext = strtolower(substr($basename, $lastDot + 1));
                        if (in_array($ext, $knownExtensions)) {
                            $zipName = substr($basename, 0, $lastDot) . '.zip';
                        } else {
                            // 알려진 확장자가 아니면 이름 그대로 + .zip
                            $zipName = $basename . '.zip';
                        }
                    } else {
                        $zipName = $basename . '.zip';
                    }
                }
            } else {
                // 타임존 안전 파일명 생성 (kstDate는 config.php에서 정의)
                $zipName = 'archive_' . kstDate('Ymd_His') . '.zip';
            }
        }
        
        // 첫 번째 파일의 디렉토리에 ZIP 생성
        $firstPath = $this->buildPath($basePath, $paths[0]);
        $zipDir = is_dir($firstPath) ? dirname($firstPath) : dirname($firstPath);
        $zipFullPath = $zipDir . DIRECTORY_SEPARATOR . $zipName;
        
        // 중복 파일명 처리
        $zipFullPath = $this->getUniqueFilename($zipFullPath);
        
        if (!$this->isPathSafe($basePath, $zipFullPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== true) {
            return ['success' => false, 'error' => __('zip_create_fail_be')];
        }
        
        // 먼저 총 파일 수 계산
        $fileList = [];
        foreach ($paths as $relativePath) {
            $fullPath = $this->buildPath($basePath, $relativePath);
            
            if (!$this->isPathSafe($basePath, $fullPath) || !file_exists($fullPath)) {
                continue;
            }
            
            if (is_dir($fullPath)) {
                // 폴더 내 파일 목록
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $file) {
                    $fileList[] = [
                        'full' => $file->getPathname(),
                        'relative' => basename($fullPath) . DIRECTORY_SEPARATOR . $iterator->getSubPathname(),
                        'isDir' => $file->isDir()
                    ];
                }
            } else {
                $fileList[] = [
                    'full' => $fullPath,
                    'relative' => basename($fullPath),
                    'isDir' => false
                ];
            }
        }
        
        $totalFiles = count($fileList);
        $addedCount = 0;
        
        // 진행률 업데이트 간격 결정 (최소 5%, 최대 50개 파일마다)
        $updateInterval = $progressCallback ? max(1, min(50, (int)($totalFiles * 0.05))) : 0;
        $lastUpdate = 0;
        
        foreach ($fileList as $idx => $item) {
            // 클라이언트 취소 감지
            if (connection_aborted()) {
                $zip->close();
                @unlink($zipFullPath);
                return ['success' => false, 'error' => 'cancelled'];
            }
            
            if ($item['isDir']) {
                $zip->addEmptyDir($item['relative']);
            } else {
                $fileSize = @filesize($item['full']) ?: 0;
                if ($fileSize < 50 * 1024 * 1024) {
                    $content = @file_get_contents($item['full']);
                    if ($content !== false) {
                        $zip->addFromString($item['relative'], $content);
                        unset($content);
                    }
                } else {
                    $zip->addFile($item['full'], $item['relative']);
                    $zip->setCompressionName($item['relative'], ZipArchive::CM_STORE);
                }
            }
            $addedCount++;
            
            // 일정 간격마다만 진행률 업데이트
            if ($progressCallback && ($idx - $lastUpdate >= $updateInterval || $idx === $totalFiles - 1)) {
                $progressCallback($idx + 1, $totalFiles, $item['relative']);
                $lastUpdate = $idx;
            }
        }
        
        // 암호 설정 (PHP 7.2+ ZipArchive::EM_AES_256)
        if (!empty($password) && $addedCount > 0 && method_exists($zip, 'setEncryptionIndex')) {
            $zip->setPassword($password);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $zip->setEncryptionIndex($i, ZipArchive::EM_AES_256);
            }
        }
        
        $zip->close();
        
        if ($addedCount === 0) {
            @unlink($zipFullPath);
            return ['success' => false, 'error' => __('no_files_to_compress')];
        }
        
        return [
            'success' => true,
            'zip_name' => basename($zipFullPath),
            'file_count' => $addedCount
        ];
    }
    
    /**
     * 분할 ZIP 압축 (7-Zip 필요)
     * @param int $storageId 스토리지 ID
     * @param array $paths 압축할 파일 경로 목록
     * @param string $zipName ZIP 파일명
     * @param int $splitSizeMB 분할 크기 (MB)
     * @param callable|null $progressCallback 진행률 콜백
     * @param string $password 암호
     * @return array 결과
     */
    public function createSplitZip(int $storageId, array $paths, string $zipName = '', int $splitSizeMB = 100, ?callable $progressCallback = null, string $password = ''): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => __('api_err_no_read_perm', '읽기 권한이 없습니다.')];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        if (empty($paths)) {
            return ['success' => false, 'error' => __('api_err_select_files', '압축할 파일을 선택하세요.')];
        }
        
        // 7-Zip 바이너리 탐색
        $sevenZipBin = null;
        $szPaths = ['C:\\Program Files\\7-Zip\\7z.exe', 'C:\\Program Files (x86)\\7-Zip\\7z.exe', '/usr/bin/7z', '/usr/local/bin/7z', '/usr/bin/7za', '/usr/bin/7zz', '/usr/local/bin/7zz', '/usr/bin/7zzs', '/usr/local/bin/7zzs', '/usr/bin/7zip', '/bin/7zz', '/bin/7z'];
        foreach ($szPaths as $p) { if (file_exists($p)) { $sevenZipBin = $p; break; } }
        
        if (!$sevenZipBin) {
            return ['success' => false, 'error' => __('7zip_not_installed', '분할 압축은 서버에 7-Zip이 설치되어야 합니다.')];
        }
        
        // ZIP 파일명 결정
        if (empty($zipName)) {
            if (count($paths) === 1) {
                $zipName = pathinfo(basename($paths[0]), PATHINFO_FILENAME) . '.zip';
            } else {
                // 타임존 안전 파일명 생성 (kstDate는 config.php에서 정의)
                $zipName = 'archive_' . kstDate('Ymd_His') . '.zip';
            }
        }
        // .zip 확장자 보장
        if (strtolower(pathinfo($zipName, PATHINFO_EXTENSION)) !== 'zip') {
            $zipName .= '.zip';
        }
        
        // 첫 번째 파일의 디렉토리에 ZIP 생성
        $firstPath = $this->buildPath($basePath, $paths[0]);
        $zipDir = dirname($firstPath);
        $zipFullPath = $zipDir . DIRECTORY_SEPARATOR . $zipName;
        
        // 중복 파일명 처리
        $zipFullPath = $this->getUniqueFilename($zipFullPath);
        $zipName = basename($zipFullPath);
        
        if (!$this->isPathSafe($basePath, $zipFullPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        // 파일 수 계산
        $fileCount = 0;
        foreach ($paths as $relativePath) {
            $fullPath = $this->buildPath($basePath, $relativePath);
            if (!$this->isPathSafe($basePath, $fullPath) || !file_exists($fullPath)) continue;
            
            if (is_dir($fullPath)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iterator as $f) { $fileCount++; }
            } else {
                $fileCount++;
            }
        }
        
        if ($progressCallback) {
            $progressCallback(0, $fileCount, $zipName);
        }
        
        // 7-Zip 명령어 구성 — @listfile 방식 (유니코드 경로 지원)
        $volumeSize = $splitSizeMB . 'm';
        
        // 파일 목록을 임시 UTF-8 BOM 파일로 저장
        $listFile2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_split_' . md5(uniqid()) . '.txt';
        $listContent = '';
        foreach ($paths as $relativePath) {
            $fullPath = $this->buildPath($basePath, $relativePath);
            if (!file_exists($fullPath)) continue;
            $relToZipDir = basename($relativePath);
            $listContent .= $relToZipDir . "\r\n";
        }
        // UTF-8 BOM 추가 (7-Zip이 UTF-8로 인식하도록)
        file_put_contents($listFile2, "\xEF\xBB\xBF" . $listContent);
        
        // 보안: escapeShellPath로 경로 이스케이프 (command injection 방지)
        $escapedZip = $this->escapeShellPath($zipName);
        $escapedListFile = $this->escapeShellPath($listFile2);
        
        $cmd = $this->escapeShellPath($sevenZipBin) . ' a -tzip -v' . $volumeSize . ' -scsUTF-8';
        
        // 암호 설정
        if (!empty($password)) {
            // 비밀번호를 안전하게 -p 인자로 변환 (제어문자만 제거, $ # ` 등 보존)
            $cmd .= $this->buildPasswordArg($password);
            $cmd .= ' -mem=AES256';
        }
        
        $cmd .= ' ' . $escapedZip . ' @' . $escapedListFile;
        
        @set_time_limit(0);
        
        // 디버그 로그
        //         $debugLog = defined('DATA_PATH') ? DATA_PATH . '/split_debug.log' : '';
        
        // 총 원본 크기 계산 (진행률용)
        $totalSourceSize = 0;
        foreach ($paths as $relativePath) {
            $fullPath = $this->buildPath($basePath, $relativePath);
            if (!file_exists($fullPath)) continue;
            if (is_dir($fullPath)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($it as $f) { $totalSourceSize += $f->getSize(); }
            } else {
                $totalSourceSize += @filesize($fullPath) ?: 0;
            }
        }
        if ($totalSourceSize <= 0) $totalSourceSize = 1;
        
        // 보안: escapeShellPath로 경로 이스케이프 (" 제거 대신 정식 이스케이프)
        $escapedZipDir = $this->escapeShellPath($zipDir);
        
        if ($progressCallback) {
            $progressCallback(0, 100, $zipName . ' (7-Zip)');
        }
        
        // 백그라운드로 7-Zip 실행
        //         if ($debugLog) @file_put_contents($debugLog, date('H:i:s') . " CMD: " . ($bgCmd ?? $cmd) . "\n", FILE_APPEND);
        //         if ($debugLog) @file_put_contents($debugLog, date('H:i:s') . " listFile2: $listFile2\n", FILE_APPEND);
        //         if ($debugLog) @file_put_contents($debugLog, date('H:i:s') . " listContent:\n" . @file_get_contents($listFile2) . "\n", FILE_APPEND);
        //         if ($debugLog) @file_put_contents($debugLog, date('H:i:s') . " zipDir: $zipDir\n", FILE_APPEND);
        //         if ($debugLog) @file_put_contents($debugLog, date('H:i:s') . " zipFullPath: $zipFullPath\n", FILE_APPEND);
        //         if ($debugLog) @file_put_contents($debugLog, date('H:i:s') . " totalSourceSize: $totalSourceSize\n", FILE_APPEND);
        
        if (DIRECTORY_SEPARATOR === '\\') {
            $bgCmd = 'cd /d ' . $escapedZipDir . ' && ' . $cmd;
            // Windows: bat 파일에 chcp 65001로 UTF-8 코드페이지 설정
            $batFile = sys_get_temp_dir() . '\\fs_7z_' . md5(uniqid()) . '.bat';
            $resultFile = sys_get_temp_dir() . '\\fs_7z_cresult_' . md5($zipFullPath . time()) . '.txt';
            @unlink($resultFile);
            // resultFile과 batFile은 서버 생성 경로 (md5) - 특수문자 없어서 안전
            $batContent = "@echo off\r\nsetlocal disabledelayedexpansion\r\nchcp 65001 >nul\r\n" . $bgCmd . "\r\necho %ERRORLEVEL% > \"" . $resultFile . "\"\r\ndel \"%~f0\"\r\n";
            file_put_contents($batFile, $batContent);
            pclose(popen('start /b cmd /c "' . $batFile . '"', 'r'));
        } else {
            $bgCmd = 'cd ' . $escapedZipDir . ' && ' . $cmd;
            $resultFile = sys_get_temp_dir() . '/fs_7z_cresult_' . md5($zipFullPath . time()) . '.txt';
            @unlink($resultFile);
            $escapedResult = escapeshellarg($resultFile);
            exec('(' . $bgCmd . '; echo $? > ' . $escapedResult . ') > /dev/null 2>&1 &');
        }
        
        
        // 생성되는 분할 파일 크기를 모니터링하여 진행률 계산
        $zipBaseName = basename($zipFullPath);
        $lastPercent = 0;
        $stableCount = 0; // 크기 변화 없는 횟수
        $maxWait = 7200;  // 최대 2시간
        $waited = 0;
        
        usleep(2000000); // 2초 대기 (프로세스 시작 시간)
        
        while ($waited < $maxWait) {
            // SSE keepalive + 클라이언트 연결 감지
            echo ": keepalive\n\n";
            @flush();
            
            // 클라이언트 연결 끊김 감지 (취소)
            if (connection_aborted()) {
                // 7-zip 프로세스 강제 종료
                if (DIRECTORY_SEPARATOR === '\\') {
                    exec('taskkill /F /IM 7z.exe 2>NUL');
                } else {
                    exec('pkill -f "7z.*' . $escapedZip . '" 2>/dev/null');
                }
                // 생성된 분할 파일 삭제
                $dirFiles = @scandir($zipDir);
                if ($dirFiles) {
                    foreach ($dirFiles as $df) {
                        if (strpos($df, $zipBaseName) === 0) {
                            @unlink($zipDir . DIRECTORY_SEPARATOR . $df);
                        }
                    }
                }
                @unlink($listFile2);
                @unlink($resultFile);
                
                return ['success' => false, 'error' => 'cancelled'];
            }
            
            // 현재까지 생성된 분할 파일 크기 합산
            $currentSize = 0;
            $currentSplitCount = 0;
            $dirFiles = @scandir($zipDir);
            if ($dirFiles) {
                foreach ($dirFiles as $df) {
                    if (strpos($df, $zipBaseName . '.') === 0) {
                        $ext = substr($df, strlen($zipBaseName) + 1);
                        if (preg_match('/^\d{3}$/', $ext)) {
                            $currentSize += @filesize($zipDir . DIRECTORY_SEPARATOR . $df) ?: 0;
                            $currentSplitCount++;
                        }
                    }
                }
            }
            // 원본 zip 파일도 체크 (분할 불필요한 경우)
            if (file_exists($zipFullPath)) {
                $currentSize += @filesize($zipFullPath) ?: 0;
            }
            
            $percent = min(99, (int)($currentSize / $totalSourceSize * 100));
            
            
            if ($percent !== $lastPercent && $progressCallback) {
                $progressCallback($percent, 100, $zipName . ' (' . $percent . '%)');
                $lastPercent = $percent;
            }
            
            // 7-zip 완료 감지: resultFile 존재 여부로 확인
            if ($waited >= 5) {
                if (file_exists($resultFile)) {
                    usleep(300000);
                    break;
                }
                
                // fallback: tasklist
                $isRunning = false;
                if (DIRECTORY_SEPARATOR === '\\') {
                    $check = @shell_exec('tasklist /FI "IMAGENAME eq 7z.exe" /NH 2>NUL');
                    $isRunning = ($check && stripos($check, '7z.exe') !== false);
                } else {
                    $check = @shell_exec('pgrep -f "7z.*' . $escapedZip . '" 2>/dev/null');
                    $isRunning = !empty(trim($check ?? ''));
                }
                
                if (!$isRunning) {
                    usleep(300000);
                    break;
                }
            }
            
            sleep(1);
            $waited++;
        }
        
        // 임시 파일 삭제
        @unlink($listFile2);
        @unlink($resultFile);
        
        
        if ($progressCallback) {
            $progressCallback(100, 100, $zipName . ' (100%)');
        }
        
        // 생성된 분할 파일 수 확인
        // 7-Zip 분할: 파일명.zip.001, .002 ...
        // glob()은 경로에 [,]가 있으면 와일드카드로 해석하므로 scandir 사용
        $splitFiles = [];
        $zipBaseName = basename($zipFullPath);
        $dirFiles = @scandir($zipDir);
        if ($dirFiles) {
            foreach ($dirFiles as $df) {
                // 파일명.zip.001, .002 ... 패턴 매칭
                if (strpos($df, $zipBaseName . '.') === 0) {
                    $ext = substr($df, strlen($zipBaseName) + 1);
                    if (preg_match('/^\d{3}$/', $ext)) {
                        $splitFiles[] = $zipDir . DIRECTORY_SEPARATOR . $df;
                    }
                }
            }
        }
        $splitCount = count($splitFiles);
        
        //         if ($debugLog) @file_put_contents($debugLog, date('H:i:s') . " waited: $waited sec, splitCount: $splitCount\n", FILE_APPEND);
        //         if ($debugLog) @file_put_contents($debugLog, date('H:i:s') . " splitFiles: " . json_encode(array_map('basename', $splitFiles)) . "\n", FILE_APPEND);
        //         if ($debugLog) @file_put_contents($debugLog, date('H:i:s') . " file_exists(zipFullPath): " . (file_exists($zipFullPath) ? 'YES' : 'NO') . "\n", FILE_APPEND);
        
        if ($splitCount === 0) {
            // 분할 안 됨 (파일이 분할 크기보다 작음) → 원본 zip이 생성됨
            if (file_exists($zipFullPath)) {
                return ['success' => true, 'zip_name' => $zipName, 'file_count' => $fileCount, 'split_count' => 1];
            }
            return ['success' => false, 'error' => __('compress_failed', '압축 실패')];
        }
        
        return [
            'success' => true,
            'zip_name' => $zipName,
            'file_count' => $fileCount,
            'split_count' => $splitCount
        ];
    }
    
    /**
     * 분할 ZIP 해제 (7-Zip 필요)
     */
    private function extractSplitZip(string $basePath, string $fullZipPath, string $zipPath, string $destPath, ?callable $progressCallback, string $password, string $mode = 'folder'): array {
        // 7-Zip 바이너리 탐색
        $sevenZipBin = null;
        $szPaths = ['C:\\Program Files\\7-Zip\\7z.exe', 'C:\\Program Files (x86)\\7-Zip\\7z.exe', '/usr/bin/7z', '/usr/local/bin/7z', '/usr/bin/7za', '/usr/bin/7zz', '/usr/local/bin/7zz', '/usr/bin/7zzs', '/usr/local/bin/7zzs', '/usr/bin/7zip', '/bin/7zz', '/bin/7z'];
        foreach ($szPaths as $p) { if (file_exists($p)) { $sevenZipBin = $p; break; } }
        
        if (!$sevenZipBin) {
            return ['success' => false, 'error' => __('7zip_not_installed', '분할 압축 해제는 서버에 7-Zip이 설치되어야 합니다.')];
        }
        
        // 해제 경로 결정 (.zip.001 → .zip 제거 후 폴더명)
        $basename = basename($fullZipPath);
        // archive.zip.001 → archive
        $folderName = preg_replace('/\.zip\.\d{3}$/i', '', $basename);
        if ($folderName === $basename) {
            // .001만 있는 경우
            $folderName = preg_replace('/\.\d{3}$/i', '', $basename);
        }
        
        // mode='here': 압축파일이 있는 폴더에 직접 풀기 / 'folder'(기본): 압축파일명 폴더 생성
        if ($mode === 'here' && empty($destPath)) {
            $extractDir = dirname($fullZipPath);
        } elseif (empty($destPath)) {
            $extractDir = dirname($fullZipPath) . DIRECTORY_SEPARATOR . $folderName;
        } else {
            $extractDir = $this->buildPath($basePath, $destPath);
        }
        
        // "여기에 풀기" 모드 여부 — extractDir이 기존 폴더이므로 실패/취소 시 삭제 금지
        $isHereMode = ($mode === 'here' && empty($destPath));
        
        // [여기에 풀기 폴더충돌 (2) 분리] extract7zip과 동일: 실제 추출은 임시 stage에서, 최종에 최상위 단위 이동.
        $hereFinalDest = null;
        if ($isHereMode) {
            $hereFinalDest = $extractDir;
            // stage는 사용자 폴더와 같은 파일시스템에 생성 (cross-device rename 방지 — extract7zip과 동일)
            $extractDir = rtrim($hereFinalDest, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.fs_extract_tmp_' . md5($fullZipPath . microtime(true) . mt_rand());
        }
        if (!$isHereMode) {
            $extractDir = $this->getUniqueFilename($extractDir);
        }
        $safeCleanup = function() use (&$extractDir, $isHereMode) {
            if ($isHereMode) return; // 기존 폴더 보호
            if (is_dir($extractDir)) $this->deleteDirectory($extractDir);
        };
        
        if (!$this->isPathSafe($basePath, $extractDir)) {
            return ['success' => false, 'error' => __('invalid_dst_path')];
        }
        
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }
        
        @set_time_limit(0);
        
        // 7z l -slt 로 암호화 여부 사전 체크 (보안: escapeShellPath 사용)
        // 틀린 더미 비번(-p)으로 시도하여 헤더 암호화(목록까지 암호화)도 감지. stdin 차단으로 비번 프롬프트 멈춤 방지.
        $encProbePw = '__fs_enc_probe__';
        $checkCmd = $this->escapeShellPath($sevenZipBin) . ' l -slt -sccUTF-8 -p' . $this->escapeShellPath($encProbePw) . ' ' . $this->escapeShellPath($fullZipPath);
        if (DIRECTORY_SEPARATOR === '\\') {
            $checkOutput = @shell_exec('chcp 65001 >nul && ' . $checkCmd . ' 2>&1 < nul');
        } else {
            $checkOutput = @shell_exec($this->utf8EnvPrefix() . $checkCmd . ' 2>&1 < /dev/null');
        }
        
        $isEncrypted = false;
        if ($checkOutput) {
            // "Encrypted = +"/"Method = AES" (일반 암호화·항목 정보) 또는
            // "Cannot open encrypted"/"Wrong password" (헤더 암호화·목록까지 암호) 패턴 확인
            if (preg_match('/Encrypted\s*=\s*\+/i', $checkOutput) || 
                preg_match('/Method\s*=.*AES/i', $checkOutput) ||
                stripos($checkOutput, 'Cannot open encrypted') !== false ||
                preg_match('/Wrong password/i', $checkOutput)) {
                $isEncrypted = true;
            }
        }
        
        if ($isEncrypted && empty($password)) {
            // 암호화된 파일인데 패스워드 없음 → 패스워드 요청
            if (!$isHereMode) @rmdir($extractDir); // 여기에 풀기면 기존 폴더 보호
            return ['success' => false, 'error' => 'password_required', 'need_password' => true];
        }
        
        // 분할 파일 총 크기 (진행률용)
        $totalSplitSize = 0;
        $dirFiles = @scandir(dirname($fullZipPath));
        $baseZipName = basename($fullZipPath);
        // .001 → 원본 이름 추출 (예: archive.zip)
        $basePattern = preg_replace('/\.\d{3}$/', '', $baseZipName);
        if ($dirFiles) {
            foreach ($dirFiles as $df) {
                if (strpos($df, $basePattern . '.') === 0 && preg_match('/\.\d{3}$/', $df)) {
                    $totalSplitSize += @filesize(dirname($fullZipPath) . DIRECTORY_SEPARATOR . $df) ?: 0;
                }
            }
        }
        if ($totalSplitSize <= 0) $totalSplitSize = @filesize($fullZipPath) ?: 1;
        
        // 7z x "archive.zip.001" -o"출력경로"
        // 보안: escapeShellPath로 경로 이스케이프
        $escapedZip = $this->escapeShellPath($fullZipPath);
        $escapedDest = $this->escapeShellPath($extractDir);
        // '여기에 풀기'는 빈 stage에 추출하므로 -aoa로 무방. (2)분리는 최종 이동의 getUniqueFilename이 담당.
        $cmd = $this->escapeShellPath($sevenZipBin) . ' x -sccUTF-8 ' . $escapedZip . ' -o' . $escapedDest . ' -aoa';
        
        if (!empty($password)) {
            // 비밀번호를 안전하게 -p 인자로 변환 (제어문자만 제거, $ # ` 등 보존)
            $cmd .= $this->buildPasswordArg($password);
        }
        
        // 백그라운드 실행 — 결과 코드를 파일에 기록
        $resultFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_7z_result_' . md5($fullZipPath . time()) . '.txt';
        @unlink($resultFile); // 이전 결과 삭제
        
        if (DIRECTORY_SEPARATOR === '\\') {
            $batFile = sys_get_temp_dir() . '\\fs_7z_ex_' . md5(uniqid()) . '.bat';
            // 7z 실행 후 exitcode를 result 파일에 기록
            // resultFile/batFile은 서버 생성 경로(md5) - 안전
            $batContent = "@echo off\r\nsetlocal disabledelayedexpansion\r\nchcp 65001 >nul\r\n" . $cmd . "\r\necho %ERRORLEVEL% > \"" . $resultFile . "\"\r\ndel \"%~f0\"\r\n";
            file_put_contents($batFile, $batContent);
            pclose(popen('start /b cmd /c "' . $batFile . '"', 'r'));
        } else {
            $escapedResult = escapeshellarg($resultFile);
            exec('(' . $this->utf8EnvPrefix() . $cmd . '; echo $? > ' . $escapedResult . ') > /dev/null 2>&1 &');
        }
        
        // 암호화 파일: 결과 파일이 생길 때까지 대기 (7z가 끝날 때까지)
        // 패스워드 틀리면 빨리 끝남, 맞으면 오래 걸림
        // → 비암호화: 바로 progress 시작
        // → 암호화: 짧은 폴링으로 빠른 실패 감지, 일정 시간 지나면 맞는 것으로 간주하고 progress 시작
        $progressStarted = false;
        
        if (!$isEncrypted) {
            if ($progressCallback) {
                $progressCallback(0, 100, basename($fullZipPath) . ' (7-Zip)');
                $progressStarted = true;
            }
            usleep(500000);
        } else {
            // 암호화 파일: 최대 5초 동안 빠른 폴링 — 7z가 일찍 끝나면 패스워드 틀림
            for ($pwdWait = 0; $pwdWait < 50; $pwdWait++) { // 50 * 100ms = 5초
                // SSE keepalive (연결 유지)
                echo ": keepalive\n\n";
                @flush();
                
                if (file_exists($resultFile)) {
                    // 7z 완료됨 — exitcode 확인
                    $exitCode = (int)trim(@file_get_contents($resultFile));
                    @unlink($resultFile);
                    
                    if ($exitCode !== 0) {
                        // 실패 (패스워드 틀림 = exitcode 2) — 여기에 풀기면 기존 폴더 보호
                        $safeCleanup();
                        return ['success' => false, 'error' => 'wrong_password', 'need_password' => true];
                    }
                    
                    // exitcode 0 = 성공 — 이미 해제 완료됨 (작은 파일)
                    break;
                }
                usleep(100000); // 100ms
            }
            
            // 5초 안에 result 파일 안 생김 = 아직 해제 중 = 패스워드 맞음
            if ($progressCallback) {
                $progressCallback(0, 100, basename($fullZipPath) . ' (7-Zip)');
                $progressStarted = true;
            }
        }
        
        // 해제 폴더 크기 모니터링
        $lastPercent = 0;
        $maxWait = 7200;
        $waited = 0;
        
        while ($waited < $maxWait) {
            // SSE keepalive
            echo ": keepalive\n\n";
            @flush();
            
            if (connection_aborted()) {
                // 7-zip 프로세스 종료
                if (DIRECTORY_SEPARATOR === '\\') {
                    exec('taskkill /F /IM 7z.exe 2>NUL');
                } else {
                    exec('pkill -f ' . escapeshellarg('7z.*' . basename($fullZipPath)) . ' 2>/dev/null');
                }
                // 해제 폴더 삭제 (여기에 풀기면 기존 폴더 보호)
                $safeCleanup();
                @unlink($resultFile);
                return ['success' => false, 'error' => 'cancelled'];
            }
            
            // 현재 해제된 크기
            $currentSize = 0;
            if (is_dir($extractDir)) {
                try {
                    $it = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    foreach ($it as $f) { $currentSize += $f->getSize(); }
                } catch (\Exception $e) {}
            }
            
            $percent = min(99, (int)($currentSize / $totalSplitSize * 100));
            if ($percent !== $lastPercent && $progressCallback) {
                $progressCallback($percent, 100, basename($fullZipPath) . ' (' . $percent . '%)');
                $lastPercent = $percent;
            }
            
            // 7-zip 완료 감지 — resultFile 존재 시 즉시 종료
            if (file_exists($resultFile)) {
                usleep(300000);
                break;
            }
            
            // fallback: tasklist로도 체크
            $isRunning = false;
            if (DIRECTORY_SEPARATOR === '\\') {
                $check = @shell_exec('tasklist /FI "IMAGENAME eq 7z.exe" /NH 2>NUL');
                $isRunning = ($check && stripos($check, '7z.exe') !== false);
            } else {
                $check = @shell_exec('pgrep -f ' . escapeshellarg('7z.*' . basename($fullZipPath)) . ' 2>/dev/null');
                $isRunning = !empty(trim($check ?? ''));
            }
            
            if (!$isRunning) {
                usleep(300000);
                break;
            }
            
            sleep(1);
            $waited++;
        }
        
        // resultFile 정리
        @unlink($resultFile);
        
        // 결과 확인 — 해제된 파일 수 + 총 크기
        $fileCount = 0;
        $totalExtractedSize = 0;
        if (is_dir($extractDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($it as $f) { 
                $fileCount++; 
                $totalExtractedSize += $f->getSize();
            }
        }
        
        if ($fileCount === 0 || ($isEncrypted && $totalExtractedSize === 0)) {
            // 해제 실패 또는 패스워드 틀림 (0바이트 파일만 생성됨)
            // 빈 폴더/파일 삭제 (여기에 풀기면 기존 폴더 보호)
            $safeCleanup();
            
            if ($isEncrypted) {
                return ['success' => false, 'error' => 'wrong_password', 'need_password' => true];
            }
            return ['success' => false, 'error' => __('extract_failed', '압축 해제 실패')];
        }
        
        // [여기에 풀기 최종 이동] stage → 사용자 폴더, 최상위 항목 단위(충돌 시 (2) 분리)
        if ($hereFinalDest !== null) {
            if (!is_dir($hereFinalDest)) @mkdir($hereFinalDest, 0755, true);
            $sdh = @opendir($extractDir);
            if ($sdh) {
                while (($sname = readdir($sdh)) !== false) {
                    if ($sname === '.' || $sname === '..') continue;
                    $sSrc = $extractDir . DIRECTORY_SEPARATOR . $sname;
                    $sDest = $hereFinalDest . DIRECTORY_SEPARATOR . $sname;
                    if (file_exists($sDest)) $sDest = $this->getUniqueFilename($sDest);
                    @rename($sSrc, $sDest);
                }
                closedir($sdh);
            }
            if (is_dir($extractDir) && strpos(basename($extractDir), ".fs_extract_tmp_") === 0) {
                $this->deleteDirectory($extractDir);
            }
            $extractDir = $hereFinalDest;
        }
        
        // 상대 경로 계산
        $relExtractDir = str_replace($basePath . DIRECTORY_SEPARATOR, '', $extractDir);
        $relExtractDir = str_replace('\\', '/', $relExtractDir);
        
        return [
            'success' => true,
            'extracted_to' => $hereFinalDest !== null ? $basename : basename($extractDir),
            'file_count' => $fileCount,
            'path' => $relExtractDir
        ];
    }
    
    /**
     * 7-Zip으로 아카이브 해제 (rar, 7z, iso, cab 등)
     */
    private function extract7zip(string $basePath, string $fullPath, string $zipPath, string $destPath, ?callable $progressCallback, string $password, string $mode = 'folder'): array {
        // ===== 디버그 로그 (압축 해제 문제 진단용) =====
        // config.php의 EXTRACT_DEBUG 를 true 로 바꾸면 <DATA_PATH>/extract_debug.log 에 기록됨.
        $dbgOn = (defined('EXTRACT_DEBUG') && EXTRACT_DEBUG);
        $dbgLog = (defined('DATA_PATH') ? DATA_PATH : sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'extract_debug.log';
        $dbg = function($msg) use ($dbgOn, $dbgLog) {
            if ($dbgOn) @file_put_contents($dbgLog, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
        };
        $dbg("");
        $dbg("======== extract7zip 시작 ========");
        $dbg("fullPath=$fullPath");
        $dbg("mode=$mode / password=" . ($password !== '' ? '[있음 len=' . strlen($password) . ' hex=' . bin2hex(substr($password,0,20)) . ']' : '[없음]'));

        $sevenZipBin = null;
        $szPaths = ['C:\\Program Files\\7-Zip\\7z.exe', 'C:\\Program Files (x86)\\7-Zip\\7z.exe', '/usr/bin/7z', '/usr/local/bin/7z', '/usr/bin/7za', '/usr/bin/7zz', '/usr/local/bin/7zz', '/usr/bin/7zzs', '/usr/local/bin/7zzs', '/usr/bin/7zip', '/bin/7zz', '/bin/7z'];
        foreach ($szPaths as $p) { if (file_exists($p)) { $sevenZipBin = $p; break; } }
        
        if (!$sevenZipBin) {
            return ['success' => false, 'error' => __('7zip_not_installed', '이 형식의 압축 해제는 서버에 7-Zip이 설치되어야 합니다.')];
        }
        $dbg("sevenZipBin=$sevenZipBin");
        
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $basename = basename($fullPath);
        $folderName = pathinfo($basename, PATHINFO_FILENAME);
        
        // mode='here': 압축파일이 있는 폴더에 직접 풀기 (내부 폴더 구조 보존)
        // mode='folder'(기본): 압축파일명 폴더 생성 후 그 안에 풀기
        if ($mode === 'here' && empty($destPath)) {
            $extractDir = dirname($fullPath);
        } elseif (empty($destPath)) {
            $extractDir = dirname($fullPath) . DIRECTORY_SEPARATOR . $folderName;
        } else {
            $extractDir = $this->buildPath($basePath, $destPath);
        }
        
        // "여기에 풀기" 모드 여부 — 이 경우 extractDir이 기존 폴더(개인폴더 등)이므로
        // 실패/취소 시에도 절대 폴더를 통째로 삭제하면 안 됨 (데이터 손실 방지).
        $isHereMode = ($mode === 'here' && empty($destPath));
        
        // [여기에 풀기 폴더충돌 (2) 분리]
        //   '여기에 풀기'는 사용자 폴더에 직접 풀면, 압축 최상위 폴더가 기존 폴더와 같을 때 7z이 병합(merge)해버린다.
        //   이를 막기 위해 실제 추출은 임시 stage 폴더에서 수행하고(아래 모든 후처리도 stage 기준으로 동작),
        //   함수 마지막에 stage의 '최상위 항목(폴더/파일) 단위'로 사용자 폴더에 이동하면서 충돌 시 '(2)'로 분리한다.
        //   $hereFinalDest = 사용자가 실제로 풀고 싶은 폴더(원래 extractDir). 추출/후처리는 $extractDir(=stage)에서.
        $hereFinalDest = null;
        if ($isHereMode) {
            $hereFinalDest = $extractDir; // 사용자 폴더 (최종 이동 목적지)
            // ⚠️ stage는 반드시 '사용자 폴더와 같은 파일시스템'에 만들어야 한다. sys_get_temp_dir()(/tmp 등)는
            //   시놀로지처럼 사용자 볼륨(/volume1)과 다른 파일시스템일 수 있어, 최종 rename이 cross-device로 실패한다.
            //   → 사용자 폴더 안에 숨김 임시 디렉토리를 만들어 같은 fs 내 이동(rename)이 되도록 한다.
            $extractDir = rtrim($hereFinalDest, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.fs_extract_tmp_' . md5($fullPath . microtime(true) . mt_rand());
            // stage는 새 빈 폴더이므로, 이하 로직에서 preExisting/덮어쓰기 충돌이 자연히 없어 추출/집계/보정이 정확해진다.
        }
        
        // "여기에 풀기"는 기존 폴더에 직접 쓰므로 고유화하지 않음
        if (!$isHereMode) {
            $extractDir = $this->getUniqueFilename($extractDir);
        }
        $dbg("isHereMode=" . ($isHereMode ? 'YES(폴더삭제 금지)' : 'NO') . ($hereFinalDest ? " / stage=$extractDir / finalDest=$hereFinalDest" : ''));
        
        // 안전 삭제: "여기에 풀기"면 extractDir(기존 폴더)을 삭제하지 않음.
        // 폴더 생성 모드일 때만 추출 폴더 정리.
        $safeCleanup = function() use (&$extractDir, $isHereMode) {
            if ($isHereMode) return; // 기존 폴더 보호 — 절대 삭제 안 함
            if (is_dir($extractDir)) $this->deleteDirectory($extractDir);
        };
        
        if (!$this->isPathSafe($basePath, $extractDir)) {
            return ['success' => false, 'error' => __('invalid_dst_path')];
        }
        
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }
        
        // [파일명보정 대비] 추출 직전 extractDir의 기존 항목 스냅샷.
        //   보정 단계에서 '깨진 이름' 제거 시, 이 스냅샷에 있던(=추출 이전부터 존재한) 항목은
        //   사용자의 기존 파일이므로 절대 건드리지 않는다. 비UTF-8 이름의 기존 파일도 보호된다.
        $preExisting = [];
        if (is_dir($extractDir)) {
            $pdh = @opendir($extractDir);
            if ($pdh) {
                while (($pn = readdir($pdh)) !== false) {
                    if ($pn === '.' || $pn === '..') continue;
                    $preExisting[$pn] = true;
                }
                closedir($pdh);
            }
        }
        
        @set_time_limit(0);
        
        // 7z l -slt 로 암호화 여부 사전 체크 (보안: escapeShellPath)
        // 헤더 암호화 아카이브가 비밀번호 프롬프트로 멈추지 않도록 빈 비번(-p"") + stdin 차단.
        $checkCmd = $this->escapeShellPath($sevenZipBin) . ' l -slt -sccUTF-8 -p"" ' . $this->escapeShellPath($fullPath);
        if (DIRECTORY_SEPARATOR === '\\') {
            $checkOutput = @shell_exec('chcp 65001 >nul && ' . $checkCmd . ' 2>&1 < nul');
        } else {
            $checkOutput = @shell_exec($this->utf8EnvPrefix() . $checkCmd . ' 2>&1 < /dev/null');
        }
        // 헤더 암호화 감지: 목록을 못 뽑고 암호 프롬프트/오류가 난 경우도 암호화로 간주
        if ($checkOutput !== null && (
                stripos($checkOutput, 'Enter password') !== false ||
                stripos($checkOutput, 'Wrong password') !== false ||
                stripos($checkOutput, 'Cannot open encrypted archive') !== false
            )) {
            // 헤더 암호화 — 아래 $isEncrypted 판정에서 처리되도록 표시
            $checkOutput = ($checkOutput ?? '') . "\nEncrypted = +\n";
        }
        
        $isEncrypted = false;
        if ($checkOutput) {
            if (preg_match('/Encrypted\s*=\s*\+/i', $checkOutput) || 
                preg_match('/Method\s*=.*AES/i', $checkOutput)) {
                $isEncrypted = true;
            }
        }
        $dbg("extractDir=$extractDir / ext=$ext / isEncrypted=" . ($isEncrypted ? 'YES' : 'NO'));
        $dbg("checkOutput(앞 300자)=" . substr((string)$checkOutput, 0, 300));
        
        if ($isEncrypted && empty($password)) {
            if (!$isHereMode) @rmdir($extractDir); // 여기에 풀기면 기존 폴더 보호
            $dbg("→ password_required 반환 (암호화인데 비번 없음)");
            return ['success' => false, 'error' => 'password_required', 'need_password' => true];
        }
        
        $totalSize = @filesize($fullPath) ?: 1;
        
        // 보안: escapeShellPath로 경로 이스케이프
        $escapedPath = $this->escapeShellPath($fullPath);
        $escapedDest = $this->escapeShellPath($extractDir);
        // 덮어쓰기 정책: '여기에 풀기'는 빈 임시 stage에 추출하므로(15차) 충돌이 없어 -aoa로 무방하고,
        //   7z의 -aou가 만드는 'name_1' 형식 대신, 최종 이동 단계에서 getUniqueFilename이 'name (2)' 형식으로
        //   충돌을 처리한다(폴더/파일 일관). '폴더 생성' 모드도 빈 새 폴더라 -aoa.
        $overwriteOpt = '-aoa';
        $cmd = $this->escapeShellPath($sevenZipBin) . ' x -sccUTF-8 ' . $escapedPath . ' -o' . $escapedDest . ' ' . $overwriteOpt;
        
        if (!empty($password)) {
            // 비밀번호를 안전하게 -p 인자로 변환 (제어문자만 제거, $ # ` 등 보존)
            $cmd .= $this->buildPasswordArg($password);
        }
        $dbg("7z 추출 cmd=$cmd");
        
        // [진단] 디버그 켜졌을 때: 추출 명령을 별도 임시 폴더에 동기 실행해 실제 stdout/stderr 캡처.
        //   (왜 실패하는지 = 비번 오류인지 Unsupported인지 등 결정적 정보)
        //   ★ 실제 추출(extractDir)과 분리된 임시 폴더에 돌리므로, 아래 본 추출이 중복 실행되지 않음.
        //   진단 폴더는 사용 후 즉시 정리. 디버그 OFF면 이 블록 전체가 no-op.
        if ($dbgOn) {
            $diagDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_7z_diag_' . md5($fullPath . microtime(true) . mt_rand());
            @mkdir($diagDir, 0700, true);
            $diagCmd = $this->escapeShellPath($sevenZipBin) . ' x -sccUTF-8 ' . $escapedPath . ' -o' . $this->escapeShellPath($diagDir) . ' -aoa';
            if (!empty($password)) $diagCmd .= $this->buildPasswordArg($password);
            if (DIRECTORY_SEPARATOR === '\\') {
                $diagOut = @shell_exec('chcp 65001 >nul && ' . $diagCmd . ' 2>&1 < nul');
            } else {
                $diagOut = @shell_exec($this->utf8EnvPrefix() . $diagCmd . ' 2>&1 < /dev/null');
            }
            $dbg("[진단] 7z x 실제 출력:\n" . substr((string)$diagOut, 0, 1500));
            // 진단 추출 결과 폴더 구조 기록
            if (is_dir($diagDir)) {
                $diagCnt = 0;
                $diagIt = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($diagDir, \RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($diagIt as $df) {
                    if ($diagCnt++ >= 40) { $dbg("  ...(이하 생략)"); break; }
                    $drel = str_replace($diagDir . DIRECTORY_SEPARATOR, '', $df->getPathname());
                    $dbg("  [진단추출] " . ($df->isDir() ? '[D] ' : '[F] ') . $drel . ' (' . $df->getSize() . 'B)');
                }
                $this->deleteDirectory($diagDir); // 진단 임시폴더 정리
            }
        }
        
        // 백그라운드 실행 — 결과 코드를 파일에 기록
        $resultFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_7z_result_' . md5($fullPath . time()) . '.txt';
        @unlink($resultFile);
        
        if (DIRECTORY_SEPARATOR === '\\') {
            $batFile = sys_get_temp_dir() . '\\fs_7z_e7_' . md5(uniqid()) . '.bat';
            // resultFile/batFile은 서버 생성 md5 경로 - 안전
            $batContent = "@echo off\r\nsetlocal disabledelayedexpansion\r\nchcp 65001 >nul\r\n" . $cmd . "\r\necho %ERRORLEVEL% > \"" . $resultFile . "\"\r\ndel \"%~f0\"\r\n";
            file_put_contents($batFile, $batContent);
            pclose(popen('start /b cmd /c "' . $batFile . '"', 'r'));
        } else {
            $escapedResult = escapeshellarg($resultFile);
            exec('(' . $this->utf8EnvPrefix() . $cmd . '; echo $? > ' . $escapedResult . ') > /dev/null 2>&1 &');
        }
        
        $progressStarted = false;
        
        if (!$isEncrypted) {
            if ($progressCallback) {
                $progressCallback(0, 100, $basename . ' (7-Zip)');
                $progressStarted = true;
            }
            usleep(500000);
        } else {
            // 암호화 파일: 최대 5초 동안 빠른 폴링
            for ($pwdWait = 0; $pwdWait < 50; $pwdWait++) {
                echo ": keepalive\n\n";
                @flush();
                
                if (file_exists($resultFile)) {
                    $exitCode = (int)trim(@file_get_contents($resultFile));
                    @unlink($resultFile);
                    $dbg("암호화 추출 exitCode=$exitCode");
                    
                    if ($exitCode !== 0) {
                        // rar은 7-Zip이 RAR5 암호화를 "Unsupported Method"로 실패할 수 있음.
                        // 이 경우 비번 오류로 단정하지 말고 아래 UnRAR 폴백에서 재시도(rar 공식 도구가 정확).
                        if ($ext === 'rar') {
                            $dbg("→ rar exit≠0, UnRAR 폴백으로 넘김");
                            // 여기에 풀기 모드면 기존 폴더를 삭제하면 안 됨(개인폴더 보호). 폴더 생성 모드만 정리.
                            if (!$isHereMode && is_dir($extractDir)) {
                                $this->deleteDirectory($extractDir);
                                @mkdir($extractDir, 0755, true);
                            }
                            break; // 폴링 종료 → 아래 fileCount=0 → UnRAR 폴백 진입
                        }
                        $dbg("→ wrong_password 반환 (7z/zip exit≠0)");
                        $safeCleanup(); // 여기에 풀기면 기존 폴더 보호
                        return ['success' => false, 'error' => 'wrong_password', 'need_password' => true];
                    }
                    break;
                }
                usleep(100000);
            }
            
            if ($progressCallback) {
                $progressCallback(0, 100, $basename . ' (7-Zip)');
                $progressStarted = true;
            }
        }
        
        $lastPercent = 0;
        $maxWait = 7200;
        $waited = 0;
        
        while ($waited < $maxWait) {
            echo ": keepalive\n\n";
            @flush();
            
            if (connection_aborted()) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    exec('taskkill /F /IM 7z.exe 2>NUL');
                } else {
                    exec('pkill -f ' . escapeshellarg('7z.*' . $basename) . ' 2>/dev/null');
                }
                $safeCleanup(); // 여기에 풀기면 기존 폴더(개인폴더 등) 보호 — 삭제 안 함
                @unlink($resultFile);
                return ['success' => false, 'error' => 'cancelled'];
            }
            
            $currentSize = 0;
            if (is_dir($extractDir)) {
                try {
                    $it = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    foreach ($it as $f) { $currentSize += $f->getSize(); }
                } catch (\Exception $e) {}
            }
            
            $percent = min(99, (int)($currentSize / $totalSize * 100));
            if ($percent !== $lastPercent && $progressCallback) {
                $progressCallback($percent, 100, $basename . ' (' . $percent . '%)');
                $lastPercent = $percent;
            }
            
            // 완료 감지
            if (file_exists($resultFile)) {
                usleep(300000);
                break;
            }
            
            $isRunning = false;
            if (DIRECTORY_SEPARATOR === '\\') {
                $check = @shell_exec('tasklist /FI "IMAGENAME eq 7z.exe" /NH 2>NUL');
                $isRunning = ($check && stripos($check, '7z.exe') !== false);
            } else {
                $check = @shell_exec('pgrep -f ' . escapeshellarg('7z.*' . $basename) . ' 2>/dev/null');
                $isRunning = !empty(trim($check ?? ''));
            }
            
            if (!$isRunning) {
                usleep(300000);
                break;
            }
            
            sleep(1);
            $waited++;
        }
        
        // resultFile 정리
        @unlink($resultFile);
        
        $fileCount = 0;
        $totalExtractedSize = 0;
        if (is_dir($extractDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            $dbgNameCnt = 0;
            foreach ($it as $f) { 
                // '여기에 풀기'는 extractDir이 사용자 폴더 자체다. 추출 이전부터 있던 파일(preExisting)을
                //   세면, 추출이 실패(0개)해도 기존 파일 때문에 '성공'으로 오판한다. → 새로 생긴 것만 카운트.
                if ($isHereMode) {
                    $topName = explode(DIRECTORY_SEPARATOR, substr($f->getPathname(), strlen($extractDir) + 1))[0];
                    if (isset($preExisting[$topName])) continue;
                }
                $fileCount++; 
                $totalExtractedSize += $f->getSize();
                if ($dbgOn && $dbgNameCnt++ < 20) {
                    $rn = $f->getFilename();
                    $dbg("  [추출파일명] " . $rn . " / hex=" . bin2hex(substr($rn, 0, 40)) . " / UTF-8유효=" . (mb_check_encoding($rn, 'UTF-8') ? 'YES' : 'NO') . " / ?포함=" . (strpos($rn, '?') !== false ? 'YES' : 'NO'));
                }
            }
        }
        // '여기에 풀기'는 extractDir이 사용자 폴더 자체라, 위 집계엔 기존 파일까지 포함된다.
        //   토스트에 표시할 '압축 해제 파일 개수'는 추출 성공 판정(rar 폴백 등)과 별개로 압축 목록 기준으로 따로 센다.
        //   (성공 판정용 $fileCount는 그대로 두고, 표시용 개수만 $archiveFileCount로 분리.)
        $archiveFileCount = $fileCount;
        if ($isHereMode) {
            $listCmd2 = $this->escapeShellPath($sevenZipBin) . ' l -slt -sccUTF-8' . $this->buildPasswordArg($password) . ' ' . $this->escapeShellPath($fullPath);
            if (DIRECTORY_SEPARATOR === '\\') {
                $listOut2 = @shell_exec('chcp 65001 >nul && ' . $listCmd2 . ' 2>&1 < nul');
            } else {
                $listOut2 = @shell_exec($this->utf8EnvPrefix() . $listCmd2 . ' 2>&1 < /dev/null');
            }
            if ($listOut2) {
                // -slt 출력을 Path 단위 엔트리로 파싱. 아카이브 자체(첫 Path=아카이브경로)와 폴더(Attributes D 시작)는 제외.
                $cntFiles = 0;
                $cur = [];
                $flush = function() use (&$cur, &$cntFiles, $fullPath) {
                    if (empty($cur['Path'])) return;
                    if ($cur['Path'] === $fullPath) return; // 아카이브 자체
                    $isDir = isset($cur['Attributes']) && strpos(ltrim($cur['Attributes']), 'D') === 0;
                    if (!$isDir) $cntFiles++;
                };
                foreach (preg_split('/\r?\n/', $listOut2) as $ln) {
                    if (preg_match('/^(Path|Attributes) = (.*)$/', $ln, $mm)) {
                        if ($mm[1] === 'Path') { $flush(); $cur = ['Path' => $mm[2]]; }
                        else { $cur['Attributes'] = $mm[2]; }
                    }
                }
                $flush();
                if ($cntFiles > 0) $archiveFileCount = $cntFiles;
            }
        }
        //   → 목록 기준으로 깨진 파일명을 rename 복원(fixExtractedFilenames).
        // [파일명 인코딩 보정] 일부 환경(시놀로지 DSM 등 비UTF-8 로케일 리눅스)에서는 7-Zip이 추출 시
        //   파일을 디스크에 쓸 때 OS 파일 API가 LC_CTYPE 로케일을 따라, 비ASCII 파일명을 '?'로 치환한다.
        //   → 목록 기준 -so 재추출로 보정.
        // ⚠️ Windows는 7-Zip이 유니코드 파일명을 Win32 API(UTF-16)로 정확히 기록하므로 깨지지 않는다.
        //   오히려 Windows에서 PHP의 is_file()이 한글 경로를 못 읽어 '깨짐'으로 오판하거나, -so 출력을
        //   chcp 콘솔 파이프가 변조해 바이너리(PDF/이미지)를 손상시킬 위험이 있으므로 보정을 돌리지 않는다.
        //   (보정은 비UTF-8 로케일 리눅스 환경 전용.)
        if ($fileCount > 0 && $ext !== 'rar' && DIRECTORY_SEPARATOR !== '\\') {
            $this->fixExtractedFilenames($sevenZipBin, $fullPath, $extractDir, $password, $dbg, $dbgOn, $isHereMode, $preExisting);
            // 보정 후 재집계 (이름만 바뀌므로 개수/크기는 동일하지만 안전하게)
            $fileCount = 0; $totalExtractedSize = 0;
            if (is_dir($extractDir)) {
                $it2 = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($it2 as $f) { $fileCount++; $totalExtractedSize += $f->getSize(); }
            }
        }
        
        if ($progressCallback) {
            $progressCallback(100, 100, $basename);
        }
        
        // UnRAR 폴백: rar인데 7-Zip이 추출하지 못한 경우 (7-Zip의 rar 지원 한계 보완 — 목록/미리보기 폴백과 동일 취지)
        if ($fileCount === 0 && $ext === 'rar') {
            $unrarBin = null;
            $unrarPaths = [
                'C:\\Program Files\\WinRAR\\UnRAR.exe',
                'C:\\Program Files (x86)\\WinRAR\\UnRAR.exe',
                'C:\\Program Files\\WinRAR\\Rar.exe',
                'C:\\Program Files (x86)\\WinRAR\\Rar.exe',
                'C:\\Program Files\\WinRAR\\UnRAR.exe',
                '/usr/bin/unrar', '/usr/local/bin/unrar', '/usr/bin/unrar-nonfree'
            ];
            foreach ($unrarPaths as $up) { if (file_exists($up)) { $unrarBin = $up; break; } }
            $dbg("UnRAR 폴백 진입 (7z 추출 실패). unrarBin=" . ($unrarBin ?: '[없음! UnRAR.exe를 못 찾음]'));
            if ($unrarBin) {
                // unrar x: 전체 경로 유지 추출, -y: 자동 yes, -o+: 덮어쓰기, -inul: 메시지 억제. stdin 차단으로 비밀번호 프롬프트 멈춤 방지.
                // 보안: escapeShellPath로 경로 이스케이프 (command injection 방지)
                // ⚠️ 시놀로지 등 locale=C 환경에서는 UnRAR이 '한글 절대경로'를 '?'로 변환해 파일을 못 열어
                //    'Program aborted'로 실패한다. → 압축파일이 있는 디렉토리로 cd 후 파일명만 상대경로로 넘기고,
                //    추출 대상도 상대경로로 지정해 셸이 경로를 바이트 그대로 전달하게 한다(로케일 무관).
                $archiveDir = dirname($fullPath);
                $archiveBase = basename($fullPath);
                $urDest = rtrim($extractDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                // 추출 대상이 압축파일 디렉토리 하위면 상대경로로, 아니면 절대경로 유지
                $urDestArg = $urDest;
                if (strpos($urDest, $archiveDir . DIRECTORY_SEPARATOR) === 0) {
                    $urDestArg = '.' . DIRECTORY_SEPARATOR . substr($urDest, strlen($archiveDir) + 1);
                }
                $cdPrefix = 'cd ' . $this->escapeShellPath($archiveDir) . ' && ';
                $urCmd = $cdPrefix . $this->escapeShellPath($unrarBin) . ' x -y -o+ -inul ' . $this->escapeShellPath($archiveBase) . ' ' . $this->escapeShellPath($urDestArg);
                // 암호화 rar 대응: 비밀번호가 있으면 -p<password> 추가 (7z x와 동일한 비번 처리 패턴).
                // 흐름상 여기 도달 = 무암호 또는 (암호화 + 비번 있음). 비번 없는 암호화는 사전 체크에서 need_password로 이미 반환됨.
                if (!empty($password)) {
                    $urCmd .= $this->buildPasswordArg($password);
                }
                $dbg("UnRAR 폴백 cmd=$urCmd");
                // 디버그 시 stderr까지 캡처해 실패 원인 기록 (-inul 대신 메시지 보이게)
                if ($dbgOn) {
                    $urDiagCmd = $cdPrefix . $this->escapeShellPath($unrarBin) . ' x -y -o+ ' . $this->escapeShellPath($archiveBase) . ' ' . $this->escapeShellPath($urDestArg);
                    if (!empty($password)) {
                        $urDiagCmd .= $this->buildPasswordArg($password);
                    }
                    if (DIRECTORY_SEPARATOR === '\\') {
                        $urDiagOut = @shell_exec('chcp 65001 >nul && ' . $urDiagCmd . ' 2>&1 < nul');
                    } else {
                        $urDiagOut = @shell_exec($this->utf8EnvPrefix() . $urDiagCmd . ' 2>&1 < /dev/null');
                    }
                    $dbg("[진단] UnRAR 실제 출력:\n" . substr((string)$urDiagOut, 0, 1000));
                } else {
                    if (DIRECTORY_SEPARATOR === '\\') {
                        @shell_exec('chcp 65001 >nul && ' . $urCmd . ' 2>nul < nul');
                    } else {
                        @shell_exec($this->utf8EnvPrefix() . $urCmd . ' 2>/dev/null < /dev/null');
                    }
                }
                // 추출 결과 다시 집계 ('여기에 풀기'면 추출 이전부터 있던 파일은 제외하고 새로 생긴 것만 카운트)
                $fileCount = 0; $totalExtractedSize = 0;
                if (is_dir($extractDir)) {
                    $it = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    foreach ($it as $f) {
                        if ($isHereMode) {
                            $topName = explode(DIRECTORY_SEPARATOR, substr($f->getPathname(), strlen($extractDir) + 1))[0];
                            if (isset($preExisting[$topName])) continue; // 기존 파일 제외
                        }
                        $fileCount++; $totalExtractedSize += $f->getSize();
                    }
                }
                $dbg("UnRAR 폴백 후 fileCount=$fileCount, totalSize=$totalExtractedSize");
            }
        }

        if ($fileCount === 0 || ($isEncrypted && $totalExtractedSize === 0)) {
            $dbg("→ 추출 실패 판정 (fileCount=$fileCount, isEncrypted=" . ($isEncrypted?'Y':'N') . ", totalSize=$totalExtractedSize)");
            $safeCleanup(); // 여기에 풀기면 기존 폴더(개인폴더 등) 보호 — 삭제 안 함
            // 여기에 풀기 stage는 사용자 폴더가 아닌 임시 폴더이므로 실패 시 정리 (사용자 데이터와 무관).
            if ($hereFinalDest !== null && is_dir($extractDir) && strpos(basename($extractDir), ".fs_extract_tmp_") === 0) {
                $this->deleteDirectory($extractDir);
            }
            if ($isEncrypted) {
                return ['success' => false, 'error' => 'wrong_password', 'need_password' => true];
            }
            return ['success' => false, 'error' => __('extract_failed', '압축 해제 실패')];
        }
        
        $relExtractDir = str_replace($basePath . DIRECTORY_SEPARATOR, '', $extractDir);
        $relExtractDir = str_replace('\\', '/', $relExtractDir);
        
        if ($dbgOn) {
            $dbg("→ 추출 성공: extracted_to=" . basename($extractDir) . ", fileCount=$fileCount, path=$relExtractDir");
            if (is_dir($extractDir)) {
                $structIt = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
                $cnt = 0;
                foreach ($structIt as $f) {
                    if ($cnt++ >= 30) { $dbg("  ...(이하 생략)"); break; }
                    $rel = str_replace($extractDir . DIRECTORY_SEPARATOR, '', $f->getPathname());
                    $dbg("  추출됨: " . ($f->isDir() ? '[D] ' : '[F] ') . $rel);
                }
            }
        }
        
        // [여기에 풀기 최종 이동] stage(임시)에서 추출/보정/집계가 끝났으니, 사용자 폴더로 최상위 항목 단위 이동.
        //   최상위 폴더/파일이 기존과 충돌하면 '(2)'로 분리(병합 방지). 기존 데이터는 절대 덮어쓰지 않음.
        if ($hereFinalDest !== null) {
            if (!is_dir($hereFinalDest)) @mkdir($hereFinalDest, 0755, true);
            $movedTop = 0;
            $sdh = @opendir($extractDir);
            if ($sdh) {
                while (($sname = readdir($sdh)) !== false) {
                    if ($sname === '.' || $sname === '..') continue;
                    $sSrc = $extractDir . DIRECTORY_SEPARATOR . $sname;
                    $sDest = $hereFinalDest . DIRECTORY_SEPARATOR . $sname;
                    if (file_exists($sDest)) {
                        $sDest = $this->getUniqueFilename($sDest); // 폴더/파일 통째로 '(2)' 분리
                    }
                    if (@rename($sSrc, $sDest)) $movedTop++;
                }
                closedir($sdh);
            }
            $dbg("[여기에풀기 이동] stage→사용자폴더 최상위 $movedTop개 이동 (충돌 시 (2) 분리)");
            // stage 잔재 정리 (임시 폴더)
            if (is_dir($extractDir) && strpos(basename($extractDir), ".fs_extract_tmp_") === 0) {
                $this->deleteDirectory($extractDir);
            }
            // 사용자에게 보여줄 경로/이름은 실제 목적지 기준으로
            $relExtractDir = str_replace($basePath . DIRECTORY_SEPARATOR, '', $hereFinalDest);
            $relExtractDir = str_replace('\\', '/', $relExtractDir);
        }
        
        // extracted_to: 폴더 생성 모드는 만들어진 폴더명, '여기에 풀기'는 압축파일명을 보여준다.
        // file_count: '여기에 풀기'는 압축 목록 기준 개수($archiveFileCount)로 표시.
        //   단 압축 목록 계산이 실패(rar 폴백 등으로 7z 목록 못 읽음)해 0이면 fileCount로 폴백.
        $displayCount = ($isHereMode && $archiveFileCount > 0) ? $archiveFileCount : $fileCount;
        return [
            'success' => true,
            'extracted_to' => $isHereMode ? $basename : basename($extractDir),
            'file_count' => $displayCount,
            'path' => $relExtractDir
        ];
    }
    
    // ZIP에 폴더 추가 (재귀)
    private function addFolderToZip(ZipArchive $zip, string $folderPath, string $zipPath): void {
        $zip->addEmptyDir($zipPath);
        
        $files = scandir($folderPath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $fullPath = $folderPath . DIRECTORY_SEPARATOR . $file;
            $localPath = $zipPath . '/' . $file;
            
            if (is_dir($fullPath)) {
                $this->addFolderToZip($zip, $fullPath, $localPath);
            } else {
                $zip->addFile($fullPath, $localPath);
            }
        }
    }
    
    // 파일 개수 세기
    /**
     * 원격 스토리지 내 파일/폴더 이동
     */
    private function moveRemote(int $storageId, string $sourcePath, string $destPath, string $duplicateAction = 'overwrite'): array {
        $adapter = $this->getAdapter($storageId);
        if (!$adapter) {
            return ['success' => false, 'error' => $this->getLastAdapterError()];
        }
        
        if (!$adapter->exists($sourcePath)) {
            return ['success' => false, 'error' => __('src_not_exist')];
        }
        
        $filename = basename($sourcePath);
        $newPath = rtrim($destPath, '/') . '/' . $filename;
        
        // 자기 자신으로 이동 방지
        if ($sourcePath === $newPath) {
            return ['success' => true, 'skipped' => true];
        }
        
        // 중복 처리
        if ($adapter->exists($newPath)) {
            switch ($duplicateAction) {
                case 'skip':
                    return ['success' => true, 'skipped' => true];
                case 'rename':
                    $baseName = pathinfo($filename, PATHINFO_FILENAME);
                    $ext = pathinfo($filename, PATHINFO_EXTENSION);
                    $counter = 1;
                    do {
                        $newName = $ext ? "{$baseName} ({$counter}).{$ext}" : "{$baseName} ({$counter})";
                        $newPath = rtrim($destPath, '/') . '/' . $newName;
                        $counter++;
                    } while ($adapter->exists($newPath));
                    break;
                case 'overwrite':
                default:
                        // 덮어쓰기 전 기존 파일 크기 차감
                        $existingSize = is_dir($newPath) ? $this->getDirectorySize($newPath) : (filesize($newPath) ?: 0);
                        if ($existingSize > 0) {
                            $this->storage->updateUsedSize($storageId, -$existingSize);
                        }
                        
                    // 대량 덮어쓰기 감지
                    $bulkCheck = $this->checkBulkOperation('overwrite');
                    if (!$bulkCheck['allowed']) {
                        return ['success' => false, 'error' => '🔐 ' . $bulkCheck['reason']];
                    }
                    $adapter->delete($newPath);
                    break;
            }
        }
        
        // 대상 폴더 존재 확인 및 생성
        if (!empty($destPath) && !$adapter->exists($destPath)) {
            $adapter->mkdir($destPath);
        }
        
        if ($adapter->rename($sourcePath, $newPath)) {
            return ['success' => true, 'name' => basename($newPath), 'remote' => true];
        }
        
        return ['success' => false, 'error' => __('api_err_move_failed', '이동에 실패했습니다.')];
    }
    
    // 복사
    public function copy(int $sourceStorageId, string $sourcePath, int $destStorageId, string $destPath, string $duplicateAction = 'overwrite'): array {
        try {
            @set_time_limit(0);
            
            // 소스 읽기 권한 확인
            if (!$this->storage->checkPermission($sourceStorageId, 'can_read')) {
                return ['success' => false, 'error' => __('src_storage_no_read')];
            }
            
            // 대상 쓰기 권한 확인
            if (!$this->storage->checkPermission($destStorageId, 'can_write')) {
                return ['success' => false, 'error' => __('dst_storage_no_write')];
            }
            
            // 원격 스토리지 처리
            $sourceIsRemote = $this->isRemoteStorage($sourceStorageId);
            $destIsRemote = $this->isRemoteStorage($destStorageId);
            
            // 원격 ↔ 로컬 간 복사
            if ($sourceIsRemote !== $destIsRemote) {
                if ($sourceIsRemote && !$destIsRemote) {
                    // 원격 → 로컬: 원격에서 다운로드하여 로컬에 저장
                    return $this->copyRemoteToLocal($sourceStorageId, $sourcePath, $destStorageId, $destPath, $duplicateAction);
                } else {
                    // 로컬 → 원격: 로컬에서 읽어서 원격에 업로드
                    return $this->copyLocalToRemote($sourceStorageId, $sourcePath, $destStorageId, $destPath, $duplicateAction);
                }
            }
            
            // 동일 원격 스토리지 내 복사
            if ($sourceIsRemote && $sourceStorageId === $destStorageId) {
                return $this->copyRemote($sourceStorageId, $sourcePath, $destPath, $duplicateAction);
            }
            
            // 서로 다른 원격 스토리지 간 복사 (임시 파일 경유)
            if ($sourceIsRemote && $destIsRemote && $sourceStorageId !== $destStorageId) {
                return $this->copyRemoteToRemote($sourceStorageId, $sourcePath, $destStorageId, $destPath, $duplicateAction);
            }
            
            $sourceBasePath = $this->storage->getRealPath($sourceStorageId);
            $destBasePath = $this->storage->getRealPath($destStorageId);
            
            if (!$sourceBasePath) {
                return ['success' => false, 'error' => __('src_storage_not_found')];
            }
            if (!$destBasePath) {
                return ['success' => false, 'error' => __('dst_storage_not_found')];
            }
            
            $sourceFullPath = $this->buildPath($sourceBasePath, $sourcePath);
            $destFullPath = $this->buildPath($destBasePath, $destPath);
            
            if (!$this->isPathSafe($sourceBasePath, $sourceFullPath)) {
                return ['success' => false, 'error' => __('invalid_src_path')];
            }
            if (!$this->isPathSafe($destBasePath, $destFullPath)) {
                return ['success' => false, 'error' => __('invalid_dst_path')];
            }
            
            // 원본 존재 확인
            if (!file_exists($sourceFullPath)) {
                return ['success' => false, 'error' => __('src_file_not_exist') . $sourcePath];
            }
            
            // 대상 폴더 존재 확인 및 생성
            if (!is_dir($destFullPath)) {
                if (!@mkdir($destFullPath, 0755, true)) {
                    return ['success' => false, 'error' => __('dst_folder_create_fail')];
                }
            }
            
            $filename = basename($sourceFullPath);
            $newPath = $destFullPath . DIRECTORY_SEPARATOR . $filename;
            
            // 자기 자신으로 복사 방지
            $realSrc = realpath($sourceFullPath);
            $realDst = realpath($newPath);
            if ($realSrc !== false && $realDst !== false && $realSrc === $realDst) {
                $newPath = $this->getUniqueFilename($newPath);
            }
            
            // 중복 처리
            if (file_exists($newPath)) {
                switch ($duplicateAction) {
                    case 'skip':
                        return ['success' => true, 'skipped' => true];
                    case 'rename':
                        $newPath = $this->getUniqueFilename($newPath);
                        break;
                    case 'overwrite':
                    default:
                        // 덮어쓰기 전 기존 파일 크기 차감
                        $existingSize = is_dir($newPath) ? $this->getDirectorySize($newPath) : (filesize($newPath) ?: 0);
                        if ($existingSize > 0) {
                            $this->storage->updateUsedSize($destStorageId, -$existingSize);
                        }
                        
                        // 대량 덮어쓰기 감지
                        $bulkCheck = $this->checkBulkOperation('overwrite');
                        if (!$bulkCheck['allowed']) {
                            return ['success' => false, 'error' => '🔐 ' . $bulkCheck['reason']];
                        }
                        
                        // 덮어쓰기 전 파일 버전 저장 (파일인 경우만)
                        if (!is_dir($newPath)) {
                            $fileRelPath = ltrim($destPath . '/' . basename($newPath), '/');
                            $this->saveFileVersion($newPath, $fileRelPath, $destStorageId);
                        }
                        
                        // 기존 파일/폴더 삭제
                        if (is_dir($newPath)) {
                            $this->deleteDirectory($newPath);
                        } else {
                            @unlink($newPath);
                        }
                        break;
                }
            }
            
            if (is_dir($sourceFullPath)) {
                // 폴더 복사: 전체 크기/파일 수 계산 후 진행률 추적
                $dirSize = $this->getDirectorySize($sourceFullPath);
                $totalFileCount = $this->countAllFiles($sourceFullPath);
                $progressId = session_id() ?: uniqid('cp_');
                $progressFile = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/copy_progress_' . md5($progressId . $sourcePath) . '.tmp';
                $progressState = [
                    'copied' => 0,
                    'total' => $dirSize,
                    'filesDone' => 0,
                    'totalFiles' => $totalFileCount,
                    'lastUpdate' => 0
                ];
                $this->copyDirectory($sourceFullPath, $newPath, $progressFile, $progressState);
                @unlink($progressFile);
                
                // 취소 시 부분 복사된 폴더 삭제
                if ($this->isCancelled()) {
                    if (is_dir($newPath)) $this->deleteDirectory($newPath);
                    return ['success' => false, 'error' => 'cancelled'];
                }
            } else {
                $fileSize = filesize($sourceFullPath);
                $progressThreshold = 50 * 1024 * 1024; // 50MB 이상이면 진행률 추적
                
                if ($fileSize > $progressThreshold) {
                    // 대용량 파일: 청크 복사 + 진행률 기록
                    $progressId = session_id() ?: uniqid('cp_');
                    $progressFile = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/copy_progress_' . md5($progressId . $sourcePath) . '.tmp';
                    
                    $src = @fopen($sourceFullPath, 'rb');
                    $dst = @fopen($newPath, 'wb');
                    if (!$src || !$dst) {
                        if ($src) fclose($src);
                        if ($dst) fclose($dst);
                        @unlink($progressFile);
                        return ['success' => false, 'error' => __('api_err_copy_failed', '파일 복사에 실패했습니다.')];
                    }
                    
                    $chunkSize = 8 * 1024 * 1024; // 8MB 청크
                    $copied = 0;
                    $lastUpdate = 0;
                    $cancelled = false;
                    
                    while (!feof($src)) {
                        // 8MB 청크 단위로 취소 체크
                        if ($this->isCancelled()) {
                            $cancelled = true;
                            break;
                        }
                        $data = fread($src, $chunkSize);
                        if ($data === false) break;
                        $written = fwrite($dst, $data);
                        if ($written === false) break;
                        $copied += $written;
                        
                        // 0.5초마다 진행률 파일 갱신
                        $now = microtime(true);
                        if ($now - $lastUpdate > 0.5) {
                            @file_put_contents($progressFile, json_encode([
                                'copied' => $copied,
                                'total' => $fileSize,
                                'percent' => round(($copied / $fileSize) * 100)
                            ]));
                            $lastUpdate = $now;
                        }
                    }
                    
                    fclose($src);
                    fclose($dst);
                    @unlink($progressFile);
                    
                    // 취소 시 부분 복사된 파일 삭제
                    if ($cancelled) {
                        @unlink($newPath);
                        return ['success' => false, 'error' => 'cancelled'];
                    }
                    
                    if ($copied < $fileSize) {
                        @unlink($newPath);
                        return ['success' => false, 'error' => __('api_err_copy_failed', '파일 복사에 실패했습니다.')];
                    }
                } else {
                    // 소용량 파일: 기존 방식
                    if (!@copy($sourceFullPath, $newPath)) {
                        return ['success' => false, 'error' => __('api_err_copy_failed', '파일 복사에 실패했습니다.')];
                    }
                }
            }
            
            // 대상 스토리지 사용량 증가
            $copiedSize = is_dir($newPath) ? $this->getDirectorySize($newPath) : (filesize($newPath) ?: 0);
            $this->storage->updateUsedSize($destStorageId, $copiedSize);
            
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => __('copy_error') . $e->getMessage()];
        }
    }
    
    /**
     * 원격 스토리지 내 파일 복사
     * 주의: 대부분의 원격 프로토콜(FTP, SFTP)은 서버측 복사를 지원하지 않음
     * 따라서 read → write 방식으로 복사
     */
    /**
     * 원격 → 로컬 복사
     */
    private function copyRemoteToLocal(int $srcStorageId, string $srcPath, int $dstStorageId, string $dstPath, string $duplicateAction = 'overwrite'): array {
        $adapter = $this->getAdapter($srcStorageId);
        if (!$adapter) {
            return ['success' => false, 'error' => $this->getLastAdapterError()];
        }
        
        $dstBasePath = $this->storage->getRealPath($dstStorageId);
        if (!$dstBasePath) {
            return ['success' => false, 'error' => __('dst_storage_not_found')];
        }
        
        $dstFullPath = $this->buildPath($dstBasePath, $dstPath);
        if (!$this->isPathSafe($dstBasePath, $dstFullPath)) {
            return ['success' => false, 'error' => __('invalid_dst_path')];
        }
        
        if (!is_dir($dstFullPath)) {
            if (!@mkdir($dstFullPath, 0755, true)) {
                return ['success' => false, 'error' => __('dst_folder_create_fail')];
            }
        }
        
        $filename = basename($srcPath);
        $newPath = $dstFullPath . DIRECTORY_SEPARATOR . $filename;
        
        // 중복 처리
        if (file_exists($newPath)) {
            if ($duplicateAction === 'skip') {
                return ['success' => true, 'skipped' => true];
            } elseif ($duplicateAction === 'rename') {
                $newPath = $this->getUniqueFilename($newPath);
            }
        }
        
        // 원격 파일/폴더 판별
        if ($adapter->isDir($srcPath)) {
            // 폴더: 재귀 복사
            return $this->copyRemoteDirToLocal($adapter, $srcPath, $newPath);
        }
        
        // 파일: 원격에서 읽어서 로컬에 저장 (스트리밍 우선)
        if (method_exists($adapter, 'streamToOutput')) {
            // 스트리밍: 메모리 절약
            $outFile = @fopen($newPath, 'wb');
            if ($outFile) {
                ob_start(function($buffer) use ($outFile) {
                    fwrite($outFile, $buffer);
                    return '';
                }, 1024 * 1024);
                $streamOk = $adapter->streamToOutput($srcPath);
                ob_end_clean();
                fclose($outFile);
                if ($streamOk && filesize($newPath) > 0) {
                    return ['success' => true, 'filename' => basename($newPath)];
                }
                // 스트리밍 실패 시 파일 삭제 후 read() 폴백
                @unlink($newPath);
            }
        }
        
        // 폴백: read()로 전체 로드
        $content = $adapter->read($srcPath);
        if ($content === '' && $adapter->getSize($srcPath) > 0) {
            return ['success' => false, 'error' => __('remote_read_failed', '원격 파일 읽기 실패')];
        }
        
        if (@file_put_contents($newPath, $content) === false) {
            return ['success' => false, 'error' => __('local_write_failed', '로컬 파일 저장 실패')];
        }
        
        return ['success' => true, 'filename' => basename($newPath)];
    }
    
    private function copyRemoteDirToLocal($adapter, string $remotePath, string $localPath): array {
        if (!@mkdir($localPath, 0755, true) && !is_dir($localPath)) {
            return ['success' => false, 'error' => __('dst_folder_create_fail')];
        }
        
        $items = $adapter->list($remotePath);
        $copied = 0;
        $failed = 0;
        
        foreach ($items as $item) {
            $name = $item['name'] ?? '';
            if ($name === '' || $name === '.' || $name === '..') continue;
            
            $remoteItemPath = rtrim($remotePath, '/') . '/' . $name;
            $localItemPath = $localPath . DIRECTORY_SEPARATOR . $name;
            
            if ($item['is_dir'] ?? false) {
                $sub = $this->copyRemoteDirToLocal($adapter, $remoteItemPath, $localItemPath);
                if ($sub['success'] ?? false) $copied += ($sub['copied'] ?? 1);
                else $failed++;
            } else {
                $content = $adapter->read($remoteItemPath);
                if (@file_put_contents($localItemPath, $content) !== false) {
                    $copied++;
                } else {
                    $failed++;
                }
            }
        }
        
        return ['success' => $failed === 0, 'copied' => $copied, 'failed' => $failed];
    }
    
    /**
     * 로컬 → 원격 복사
     */
    private function copyLocalToRemote(int $srcStorageId, string $srcPath, int $dstStorageId, string $dstPath, string $duplicateAction = 'overwrite'): array {
        $srcBasePath = $this->storage->getRealPath($srcStorageId);
        if (!$srcBasePath) {
            return ['success' => false, 'error' => __('src_storage_not_found')];
        }
        
        $srcFullPath = $this->buildPath($srcBasePath, $srcPath);
        if (!$this->isPathSafe($srcBasePath, $srcFullPath)) {
            return ['success' => false, 'error' => __('invalid_src_path')];
        }
        
        if (!file_exists($srcFullPath)) {
            return ['success' => false, 'error' => __('src_file_not_exist')];
        }
        
        $adapter = $this->getAdapter($dstStorageId);
        if (!$adapter) {
            return ['success' => false, 'error' => $this->getLastAdapterError()];
        }
        
        $filename = basename($srcPath);
        $remotePath = empty($dstPath) ? $filename : rtrim($dstPath, '/') . '/' . $filename;
        
        // 중복 처리
        if ($adapter->exists($remotePath)) {
            if ($duplicateAction === 'skip') {
                return ['success' => true, 'skipped' => true];
            } elseif ($duplicateAction === 'rename') {
                $base = pathinfo($filename, PATHINFO_FILENAME);
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                $counter = 1;
                do {
                    $newName = $ext ? "{$base}_{$counter}.{$ext}" : "{$base}_{$counter}";
                    $remotePath = empty($dstPath) ? $newName : rtrim($dstPath, '/') . '/' . $newName;
                    $counter++;
                } while ($adapter->exists($remotePath));
            }
        }
        
        if (is_dir($srcFullPath)) {
            return $this->copyLocalDirToRemote($srcFullPath, $adapter, $remotePath);
        }
        
        $content = @file_get_contents($srcFullPath);
        if ($content === false) {
            return ['success' => false, 'error' => __('local_read_failed', '로컬 파일 읽기 실패')];
        }
        
        if (!$adapter->write($remotePath, $content)) {
            return ['success' => false, 'error' => __('remote_write_failed', '원격 파일 저장 실패')];
        }
        
        return ['success' => true, 'filename' => basename($remotePath)];
    }
    
    private function copyLocalDirToRemote(string $localPath, $adapter, string $remotePath): array {
        $adapter->mkdir($remotePath);
        
        $copied = 0;
        $failed = 0;
        $items = @scandir($localPath);
        if (!$items) return ['success' => false, 'error' => __('local_read_failed')];
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $localItemPath = $localPath . DIRECTORY_SEPARATOR . $item;
            $remoteItemPath = rtrim($remotePath, '/') . '/' . $item;
            
            if (is_dir($localItemPath)) {
                $sub = $this->copyLocalDirToRemote($localItemPath, $adapter, $remoteItemPath);
                if ($sub['success'] ?? false) $copied += ($sub['copied'] ?? 1);
                else $failed++;
            } else {
                $content = @file_get_contents($localItemPath);
                if ($content !== false && $adapter->write($remoteItemPath, $content)) {
                    $copied++;
                } else {
                    $failed++;
                }
            }
        }
        
        return ['success' => $failed === 0, 'copied' => $copied, 'failed' => $failed];
    }
    
    /**
     * 서로 다른 원격 스토리지 간 복사 (소스 읽기 → 대상 쓰기)
     */
    private function copyRemoteToRemote(int $srcStorageId, string $srcPath, int $dstStorageId, string $dstPath, string $duplicateAction = 'overwrite'): array {
        $srcAdapter = $this->getAdapter($srcStorageId);
        $dstAdapter = $this->getAdapter($dstStorageId);
        if (!$srcAdapter || !$dstAdapter) {
            return ['success' => false, 'error' => $this->getLastAdapterError()];
        }
        
        $filename = basename($srcPath);
        $remoteDstPath = empty($dstPath) ? $filename : rtrim($dstPath, '/') . '/' . $filename;
        
        // 중복 처리
        if ($dstAdapter->exists($remoteDstPath)) {
            if ($duplicateAction === 'skip') {
                return ['success' => true, 'skipped' => true];
            } elseif ($duplicateAction === 'rename') {
                $base = pathinfo($filename, PATHINFO_FILENAME);
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                $counter = 1;
                do {
                    $newName = $ext ? "{$base}_{$counter}.{$ext}" : "{$base}_{$counter}";
                    $remoteDstPath = empty($dstPath) ? $newName : rtrim($dstPath, '/') . '/' . $newName;
                    $counter++;
                } while ($dstAdapter->exists($remoteDstPath));
            }
        }
        
        if ($srcAdapter->isDir($srcPath)) {
            return $this->copyRemoteDirToRemote($srcAdapter, $srcPath, $dstAdapter, $remoteDstPath);
        }
        
        $content = $srcAdapter->read($srcPath);
        if ($content === '' && $srcAdapter->getSize($srcPath) > 0) {
            return ['success' => false, 'error' => __('remote_read_failed', '원격 파일 읽기 실패')];
        }
        
        if (!$dstAdapter->write($remoteDstPath, $content)) {
            return ['success' => false, 'error' => __('remote_write_failed', '원격 파일 저장 실패')];
        }
        
        return ['success' => true, 'filename' => basename($remoteDstPath)];
    }
    
    /**
     * 원격 디렉토리 재귀 삭제
     */
    private function deleteRemoteDir($adapter, string $path): bool {
        $items = $adapter->list($path);
        foreach ($items as $item) {
            $name = $item['name'] ?? '';
            if ($name === '' || $name === '.' || $name === '..') continue;
            $itemPath = rtrim($path, '/') . '/' . $name;
            if ($item['is_dir'] ?? false) {
                $this->deleteRemoteDir($adapter, $itemPath);
            } else {
                $adapter->delete($itemPath);
            }
        }
        $adapter->delete($path); // rmdir
        return true;
    }
    
    private function copyRemoteDirToRemote($srcAdapter, string $srcPath, $dstAdapter, string $dstPath): array {
        $dstAdapter->mkdir($dstPath);
        
        $items = $srcAdapter->list($srcPath);
        $copied = 0;
        $failed = 0;
        
        foreach ($items as $item) {
            $name = $item['name'] ?? '';
            if ($name === '' || $name === '.' || $name === '..') continue;
            
            $srcItemPath = rtrim($srcPath, '/') . '/' . $name;
            $dstItemPath = rtrim($dstPath, '/') . '/' . $name;
            
            if ($item['is_dir'] ?? false) {
                $sub = $this->copyRemoteDirToRemote($srcAdapter, $srcItemPath, $dstAdapter, $dstItemPath);
                if ($sub['success'] ?? false) $copied += ($sub['copied'] ?? 1);
                else $failed++;
            } else {
                $content = $srcAdapter->read($srcItemPath);
                if ($dstAdapter->write($dstItemPath, $content)) {
                    $copied++;
                } else {
                    $failed++;
                }
            }
        }
        
        return ['success' => $failed === 0, 'copied' => $copied, 'failed' => $failed];
    }
    
    private function copyRemote(int $storageId, string $sourcePath, string $destPath, string $duplicateAction = 'overwrite'): array {
        $adapter = $this->getAdapter($storageId);
        if (!$adapter) {
            return ['success' => false, 'error' => $this->getLastAdapterError()];
        }
        
        if (!$adapter->exists($sourcePath)) {
            return ['success' => false, 'error' => __('src_file_not_exist2')];
        }
        
        // 폴더 복사는 미지원 (재귀 복사 필요)
        if ($adapter->isDir($sourcePath)) {
            return ['success' => false, 'error' => __('remote_folder_copy_unsupported')];
        }
        
        $filename = basename($sourcePath);
        $newPath = rtrim($destPath, '/') . '/' . $filename;
        
        // 자기 자신으로 복사 시 이름 변경
        if ($sourcePath === $newPath) {
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $newPath = rtrim($destPath, '/') . '/' . ($ext ? "{$baseName} - " . __('copy_suffix') . ".{$ext}" : "{$baseName} - " . __('copy_suffix'));
        }
        
        // 중복 처리
        if ($adapter->exists($newPath)) {
            switch ($duplicateAction) {
                case 'skip':
                    return ['success' => true, 'skipped' => true];
                case 'rename':
                    $baseName = pathinfo($filename, PATHINFO_FILENAME);
                    $ext = pathinfo($filename, PATHINFO_EXTENSION);
                    $counter = 1;
                    do {
                        $newName = $ext ? "{$baseName} ({$counter}).{$ext}" : "{$baseName} ({$counter})";
                        $newPath = rtrim($destPath, '/') . '/' . $newName;
                        $counter++;
                    } while ($adapter->exists($newPath));
                    break;
                case 'overwrite':
                default:
                        // 덮어쓰기 전 기존 파일 크기 차감
                        $existingSize = is_dir($newPath) ? $this->getDirectorySize($newPath) : (filesize($newPath) ?: 0);
                        if ($existingSize > 0) {
                            $this->storage->updateUsedSize($storageId, -$existingSize);
                        }
                        
                    // 대량 덮어쓰기 감지
                    $bulkCheck = $this->checkBulkOperation('overwrite');
                    if (!$bulkCheck['allowed']) {
                        return ['success' => false, 'error' => '🔐 ' . $bulkCheck['reason']];
                    }
                    $adapter->delete($newPath);
                    break;
            }
        }
        
        // 대상 폴더 존재 확인 및 생성
        if (!empty($destPath) && !$adapter->exists($destPath)) {
            $adapter->mkdir($destPath);
        }
        
        // read → write 방식으로 복사
        $content = $adapter->read($sourcePath);
        if ($content === '' && $adapter->getSize($sourcePath) > 0) {
            return ['success' => false, 'error' => __('src_file_read_fail')];
        }
        
        if ($adapter->write($newPath, $content)) {
            // 복사된 파일 크기만큼 사용량 증가
            $copiedSize = strlen($content);
            if ($copiedSize > 0) {
                $this->storage->updateUsedSize($storageId, $copiedSize);
            }
            return ['success' => true, 'name' => basename($newPath), 'remote' => true];
        }
        
        return ['success' => false, 'error' => __('api_err_copy_failed', '파일 복사에 실패했습니다.')];
    }
    
    // 검색 (인덱스 기반)
    public function search(int $storageId, string $query, string $basePath = ''): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => __('api_err_no_read_perm', '읽기 권한이 없습니다.')];
        }
        
        // 인덱스가 있으면 인덱스 검색
        if ($this->fileIndex->hasIndex()) {
            $results = $this->fileIndex->search($query, $storageId, 100);
            return ['success' => true, 'results' => $results, 'indexed' => true];
        }
        
        // 인덱스가 없으면 폴더 순회 (fallback)
        $storagePath = $this->storage->getRealPath($storageId);
        $searchPath = $this->buildPath($storagePath, $basePath);
        
        $results = [];
        $this->searchRecursive($searchPath, $query, $storagePath, $results, 100);
        
        return ['success' => true, 'results' => $results, 'indexed' => false];
    }
    
    // 통합 검색 (모든 접근 가능한 스토리지) - 인덱스 기반
    public function searchAll(string $query): array {
        if (empty(trim($query))) {
            return ['success' => false, 'error' => __('api_err_enter_search', '검색어를 입력하세요.')];
        }
        
        // 현재 사용자가 접근 가능한 스토리지 목록 가져오기
        $storageData = $this->storage->getStorages();
        $allowedStorages = array_merge(
            $storageData['home'] ?? [],
            $storageData['shared'] ?? [],
            $storageData['public'] ?? []
        );
        
        // 읽기 권한 있는 스토리지 ID만 추출
        $allowedIds = [];
        $storageMap = [];
        foreach ($allowedStorages as $s) {
            if ($s['can_read'] ?? false) {
                $allowedIds[] = $s['id'];
                $storageMap[$s['id']] = $s;
            }
        }
        
        if (empty($allowedIds)) {
            return ['success' => true, 'results' => [], 'indexed' => true];
        }
        
        // 인덱스가 있으면 인덱스 검색 (허용된 스토리지만)
        if ($this->fileIndex->hasIndex()) {
            $results = $this->fileIndex->search($query, $allowedIds, 200);
            
            // 스토리지 정보 추가
            foreach ($results as &$item) {
                $storage = $storageMap[$item['storage_id']] ?? null;
                if ($storage) {
                    $item['storage_name'] = $storage['name'];
                    $item['storage_type'] = $storage['storage_type'] ?? 'unknown';
                }
            }
            
            return ['success' => true, 'results' => $results, 'indexed' => true];
        }
        
        // 인덱스가 없으면 폴더 순회 (fallback)
        $results = [];
        $remoteAdapterTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
        
        foreach ($allowedStorages as $storage) {
            $storageId = $storage['id'];
            $storageName = $storage['name'];
            $storageType = $storage['storage_type'] ?? 'unknown';
            
            if (!($storage['can_read'] ?? false)) {
                continue;
            }
            
            $storageResults = [];
            
            if (in_array($storageType, $remoteAdapterTypes)) {
                // 원격 스토리지: 어댑터 기반 검색
                try {
                    require_once __DIR__ . '/../api/StorageAdapter.php';
                    $storageInfo = $this->storage->getStorageById($storageId);
                    $adapter = \StorageAdapterFactory::create($storageInfo);
                    if ($adapter->connect()) {
                        $this->_searchStartTime = time();
                        $this->searchRemoteRecursive($adapter, '', $query, $storageResults, 50);
                        $adapter->disconnect();
                    }
                } catch (\Throwable $e) {
                    // 연결 실패 시 건너뜀
                }
            } else {
                // 로컬 스토리지: 폴더 순회
                $storagePath = $this->storage->getRealPath($storageId);
                if (!$storagePath || !is_dir($storagePath)) {
                    continue;
                }
                $this->_searchStartTime = time();
                $this->searchRecursive($storagePath, $query, $storagePath, $storageResults, 50);
            }
            
            // 각 결과에 스토리지 정보 추가
            foreach ($storageResults as &$item) {
                $item['storage_id'] = $storageId;
                $item['storage_name'] = $storageName;
                $item['storage_type'] = $storageType;
            }
            
            $results = array_merge($results, $storageResults);
            
            // 전체 결과 제한
            if (count($results) >= 200) {
                break;
            }
        }
        
        // 이름순 정렬
        usort($results, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
        return ['success' => true, 'results' => array_slice($results, 0, 200), 'indexed' => false];
    }
    
    // 파일 정보
    public function getInfo(int $storageId, string $relativePath): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => __('api_err_no_read_perm', '읽기 권한이 없습니다.')];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        // 보안: path traversal 방어
        if (!$this->isPathSafe($basePath, $fullPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => __('file_not_found')];
        }
        
        $info = [
            'name' => basename($fullPath),
            'path' => $relativePath,
            'is_dir' => is_dir($fullPath),
            'size' => is_dir($fullPath) ? $this->getDirectorySize($fullPath) : filesize($fullPath),
            'modified' => date('Y-m-d H:i:s', filemtime($fullPath)),
            'created' => date('Y-m-d H:i:s', filectime($fullPath)),
            'permissions' => substr(sprintf('%o', fileperms($fullPath)), -4)
        ];
        
        if (!$info['is_dir']) {
            $info['extension'] = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $info['mime_type'] = $this->getMimeType($fullPath);
        }
        
        return ['success' => true, 'info' => $info];
    }
    
    // === 헬퍼 메서드 ===
    
    /**
     * 파일/폴더 삭제 시 관련 공유 링크 및 내부 공유 정리
     */
    private function cleanupSharesForPath(int $storageId, string $relativePath, bool $isDir): void {
        try {
            // 1) 외부 공유 링크 정리
            $shares = $this->db->load('shares');
            $toDelete = [];
            $toDeleteShares = [];  // ★ 캐시 정리용 — share 객체 보관 (이슈 #19)
            foreach ($shares as $share) {
                if ((int)($share['storage_id'] ?? 0) !== $storageId) continue;
                $sharePath = $share['file_path'] ?? '';
                if ($isDir) {
                    // 폴더 삭제: 해당 폴더 및 하위 경로 공유 모두 삭제
                    if ($sharePath === $relativePath || strpos($sharePath, $relativePath . '/') === 0) {
                        $toDelete[] = $share['id'];
                        $toDeleteShares[] = $share;
                    }
                } else {
                    if ($sharePath === $relativePath) {
                        $toDelete[] = $share['id'];
                        $toDeleteShares[] = $share;
                    }
                }
            }
            // ★ 캐시 정리 (DB 삭제 전 — share 정보 필요, 펜닐님 결정 이슈 #19)
            //   ShareManager는 autoload 됨 (config.php의 spl_autoload_register)
            if (!empty($toDeleteShares)) {
                try {
                    $shareManager = new ShareManager();
                    foreach ($toDeleteShares as $shareToClean) {
                        $shareManager->cleanShareCacheByShare($shareToClean);
                    }
                } catch (\Exception $e) {
                    // 캐시 정리 실패해도 공유 삭제는 계속 (안전 우선)
                    error_log('[FileStation] cleanShareCacheByShare error: ' . $e->getMessage());
                }
            }
            foreach ($toDelete as $id) {
                $this->db->delete('shares', ['id' => $id]);
            }
            
            // 2) 내부 사용자 공유 정리
            $ishares = $this->db->load('internal_shares');
            $toDelete2 = [];
            foreach ($ishares as $ishare) {
                if ((int)($ishare['storage_id'] ?? 0) !== $storageId) continue;
                $isharePath = $ishare['file_path'] ?? '';
                if ($isDir) {
                    if ($isharePath === $relativePath || strpos($isharePath, $relativePath . '/') === 0) {
                        $toDelete2[] = $ishare['id'];
                    }
                } else {
                    if ($isharePath === $relativePath) {
                        $toDelete2[] = $ishare['id'];
                    }
                }
            }
            foreach ($toDelete2 as $id) {
                $this->db->delete('internal_shares', ['id' => $id]);
            }
        } catch (\Exception $e) {
            // 공유 정리 실패해도 삭제 자체는 성공
            error_log('[FileStation] cleanupSharesForPath error: ' . $e->getMessage());
        }
    }
    
    /**
     * 동영상 코덱/해상도 정보 조회 (네이티브 재생 가능 여부 판단)
     */
    public function getMediaInfo(int $storageId, string $relativePath): array {
        if (empty($relativePath)) {
            return ['success' => false, 'error' => 'Path required'];
        }
        
        // 스토리지 읽기 권한 체크
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => 'Permission denied'];
        }
        
        // 진짜 원격 스토리지(FTP/SFTP/WebDAV/S3)는 어댑터 통한 데이터 접근만 가능 → ffprobe 불가
        // SMB는 OS에 마운트된 UNC/마운트 포인트로 접근 가능 → ffprobe 진행
        // (펜닐 v5.8.1e — 펜닐님 지적: SMB도 본질적으로 IP 접근이고 transcodeStream도 SMB 동작하니 일관성 위해 보완)
        $_storageInfoMI = $this->storage->getStorageById($storageId);
        if (!$_storageInfoMI) {
            return ['success' => false, 'error' => 'Storage not found'];
        }
        $_storageTypeMI = $_storageInfoMI['storage_type'] ?? 'local';
        // REMOTE_TYPES에서 'smb' 제외한 타입만 차단 (SMB는 ffprobe 가능)
        if (in_array($_storageTypeMI, self::REMOTE_TYPES) && $_storageTypeMI !== 'smb') {
            return ['success' => true, 'video_codec' => '', 'audio_codec' => '', 'can_play_native' => true, 'note' => 'remote storage'];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return ['success' => false, 'error' => 'Storage not found'];
        }
        
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $fullPath) || !is_file($fullPath)) {
            return ['success' => false, 'error' => 'File not found'];
        }
        
        $fileSize = filesize($fullPath) ?: 0;
        
        // ffprobe/ffmpeg 경로
        $probeBin = $this->findProbeBin();
        if (!$probeBin) {
            return ['success' => true, 'video_codec' => '', 'audio_codec' => '', 'can_play_native' => true, 'file_size' => $fileSize, 'note' => 'ffprobe not available'];
        }
        
        // ★ 디버그: ffprobe 실행 시간 기록
        $_dataDirDbg = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $_timingLog = $_dataDirDbg . '/hls_timing.log';
        $_probeStart = microtime(true);
        /* [PreplayDebug] media_info probe START - 필요 시 주석 해제
        @file_put_contents($_timingLog, date('H:i:s.') . sprintf('%03d', (int)(($_probeStart - floor($_probeStart)) * 1000)) . " [media_info] probe START size=" . round($fileSize/1024/1024) . "MB file=" . basename($fullPath) . "\n", FILE_APPEND);
        */
        
        // 원본 명령어 (probe 안정성 우선)
        $cmd = escapeshellarg($probeBin) . ' -i ' . escapeshellarg($fullPath) . ' 2>&1';
        $output = @shell_exec($cmd);
        
        $_probeDur = (int)((microtime(true) - $_probeStart) * 1000);
        /* [PreplayDebug] media_info probe END - 필요 시 주석 해제
        @file_put_contents($_timingLog, date('H:i:s.') . sprintf('%03d', (int)((microtime(true) - floor(microtime(true))) * 1000)) . " [media_info] probe END dur={$_probeDur}ms\n", FILE_APPEND);
        */
        
        $videoCodec = '';
        $audioCodec = '';
        $resolution = '';
        if (preg_match('/Stream\s+#\d+:\d+.*Video:\s*(\w+)/i', $output, $vm)) {
            $videoCodec = strtolower($vm[1]);
        }
        if (preg_match('/Stream\s+#\d+:\d+.*Audio:\s*(\w+)/i', $output, $am)) {
            $audioCodec = strtolower($am[1]);
        }
        if (preg_match('/(\d{2,5})x(\d{2,5})/', $output, $rm)) {
            $resolution = $rm[1] . 'x' . $rm[2];
        }
        
        $nativeVideoCodecs = ['h264', 'vp8', 'vp9', 'av1'];
        $canPlayNative = empty($videoCodec) || in_array($videoCodec, $nativeVideoCodecs);
        
        return [
            'success' => true,
            'video_codec' => $videoCodec,
            'audio_codec' => $audioCodec,
            'resolution' => $resolution,
            'can_play_native' => $canPlayNative,
            'file_size' => $fileSize,
        ];
    }
    
    /**
     * 동영상을 H264/MP4로 영구 변환 (SSE 진행률 스트리밍)
     * - 원본이 H264 → -c:v copy (재인코딩 없이 MP4 재포장, 화질/용량 원본 그대로)
     * - 그 외(HEVC 등) → libx264 -crf 18 (원본 화질 최대 보존)
     * - 오디오: AAC 아니면 aac 변환, 이미 aac면 copy
     * - 출력: 같은 폴더 원본명.mp4 (충돌 시 _h264 suffix)
     * - 이미 h264 + .mp4면 변환 불필요 알림
     * - ★ 1단계: 변환만. 원본 휴지통 이동은 별도 단계에서 활성화(deleteOriginal)
     * - 백그라운드 실행: 기존 7-Zip 패턴(bat + resultFile + SSE) 재사용
     */
    public function convertToH264Mp4(int $storageId, string $relativePath, bool $deleteOriginal = false): void {
        @set_time_limit(0);
        @ignore_user_abort(true);

        // SSE 헤더
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) { @ob_end_flush(); }

        $sse = function($event, $data) {
            echo "event: {$event}\n";
            echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            @flush();
        };

        // ★ 변환 진단 로그 (data/convert_debug.log) — 실패 시에만 기록(가볍게)
        $_cvLog = function($msg) use ($relativePath) {
            $line = '[' . date('Y-m-d H:i:s') . '] [' . $relativePath . '] ' . $msg . "\n";
            @file_put_contents(DATA_PATH . '/convert_debug.log', $line, FILE_APPEND | LOCK_EX);
        };

        // 변환 ffmpeg 식별용 고유 sid (취소/새로고침 시 wmic로 찾아 taskkill — 트랜스코딩 pipesid와 동일 방식)
        $convSid = bin2hex(random_bytes(8));
        // 클라이언트에 sid 전달 (취소 버튼/새로고침 시 kill 요청에 사용)
        $sse('convsid', ['sid' => $convSid]);

        // 권한
        if (!$this->storage->checkPermission($storageId, 'can_read') ||
            !$this->storage->checkPermission($storageId, 'can_write')) {
            $_cvLog('FAIL: 권한 없음');
            $sse('error', ['error' => __('no_permission', '권한이 없습니다')]);
            return;
        }

        // 원격 스토리지는 미지원 (로컬 ffmpeg 필요)
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            $_cvLog('FAIL: 원격 스토리지 미지원');
            $sse('error', ['error' => __('convert_remote_unsupported', '원격 스토리지는 변환을 지원하지 않습니다')]);
            return;
        }

        $fullPath = $this->buildPath($basePath, $relativePath);
        if (!$this->isPathSafe($basePath, $fullPath) || !is_file($fullPath)) {
            $_cvLog('FAIL: 파일 없음 fullPath=' . ($fullPath ?? '?'));
            $sse('error', ['error' => __('file_not_found', '파일을 찾을 수 없습니다')]);
            return;
        }

        $ffmpeg = $this->findFfmpeg();
        if (!$ffmpeg) {
            $_cvLog('FAIL: ffmpeg 못 찾음');
            $sse('error', ['error' => __('ffmpeg_not_found', 'ffmpeg를 찾을 수 없습니다')]);
            return;
        }

        $sse('progress', ['percent' => 0, 'stage' => __('convert_probing', '코덱 분석 중...')]);

        // Windows escapeshellarg()는 보안상 '!'와 '%'를 공백으로 치환하는 버그가 있어
        //   (느낌표 포함 파일명이 깨져 ffmpeg가 "Illegal byte sequence"로 실패).
        //   → Windows에서는 큰따옴표로 직접 감싼다. .bat의 setlocal disabledelayedexpansion이
        //     '!'를 리터럴로 보존하고, 경로 내 '"'는 Windows 파일명에 못 쓰이므로 안전.
        $cvArg = function($s) {
            if (PHP_OS_FAMILY === 'Windows') {
                return '"' . str_replace('"', '', (string)$s) . '"';
            }
            return escapeshellarg($s);
        };

        // 1) 코덱/길이 프로브
        $probeOut = @shell_exec($cvArg($ffmpeg) . ' -i ' . $cvArg($fullPath) . ' 2>&1') ?? '';
        $videoCodec = '';
        $audioCodec = '';
        if (preg_match('/Stream\s+#\d+:\d+.*Video:\s*(\w+)/i', $probeOut, $vm)) {
            $videoCodec = strtolower($vm[1]);
        }
        if (preg_match('/Stream\s+#\d+:\d+.*Audio:\s*(\w+)/i', $probeOut, $am)) {
            $audioCodec = strtolower($am[1]);
        }
        // 총 길이(초) — 진행률 계산용
        $durationSec = 0.0;
        if (preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $probeOut, $dm)) {
            $durationSec = (int)$dm[1] * 3600 + (int)$dm[2] * 60 + (float)$dm[3];
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        // 2) 이미 h264 + mp4 컨테이너면 변환 불필요
        if ($videoCodec === 'h264' && $ext === 'mp4') {
            $sse('skip', [
                'message' => __('convert_already_h264_mp4', '이미 H264/MP4 형식입니다. 변환이 필요 없습니다.'),
                'video_codec' => $videoCodec,
            ]);
            return;
        }

        // 3) 출력 경로 결정 (충돌 회피)
        $dir = dirname($fullPath);
        $baseName = pathinfo($fullPath, PATHINFO_FILENAME);
        $outName = $baseName . '.mp4';
        $outPath = $dir . DIRECTORY_SEPARATOR . $outName;
        if (file_exists($outPath) && realpath($outPath) !== realpath($fullPath)) {
            // 동일 폴더에 같은 이름 mp4가 이미 있으면 _h264 suffix
            $outName = $baseName . '_h264.mp4';
            $outPath = $dir . DIRECTORY_SEPARATOR . $outName;
            $i = 2;
            while (file_exists($outPath)) {
                $outName = $baseName . '_h264_' . $i . '.mp4';
                $outPath = $dir . DIRECTORY_SEPARATOR . $outName;
                $i++;
            }
        }
        // 출력 경로도 안전성 검증
        if (!$this->isPathSafe($basePath, $outPath)) {
            $_cvLog('FAIL: 출력경로 unsafe outPath=' . ($outPath ?? '?'));
            $sse('error', ['error' => __('convert_output_unsafe', '출력 경로가 올바르지 않습니다')]);
            return;
        }

        // ffmpeg는 dotfile 임시 출력(.{이름}.converting)에 쓰고, 완료+검증 후 최종 outPath로 rename.
        //   목록 조회가 dotfile('.'시작)을 자동 제외하므로, 변환 중/중단된 미완성 파일은 목록에 안 보임.
        //   (취소/새로고침/브라우저 닫힘으로 중단돼도 임시파일만 남고, 그것도 정리됨 — 목록엔 영향 없음)
        $tmpOut = $dir . DIRECTORY_SEPARATOR . '.' . $outName . '.' . $convSid . '.converting';
        if (!$this->isPathSafe($basePath, $tmpOut)) {
            $sse('error', ['error' => __('convert_output_unsafe', '출력 경로가 올바르지 않습니다')]);
            return;
        }
        // 비정상 종료(PHP 강제 kill 등)로 남은 오래된 .converting 잔재 정리 — 1시간+ 된 것만.
        //   (진행 중인 변환은 ffmpeg가 출력에 계속 써서 mtime이 최근이라 보존됨. dotfile이라 목록엔 안 보이지만 디스크 누적 방지.)
        foreach (@scandir($dir) ?: [] as $_sf) {
            if ($_sf[0] === '.' && substr($_sf, -11) === '.converting') {
                $_sp = $dir . DIRECTORY_SEPARATOR . $_sf;
                if (is_file($_sp) && (time() - @filemtime($_sp)) > 3600) @unlink($_sp);
            }
        }

        // 4) 코덱 인자 결정
        //    오디오: aac면 copy, 아니면 aac 192k
        $aArgs = ($audioCodec === 'aac')
            ? '-c:a copy'
            : '-c:a aac -b:a 192k';

        // 비디오 인코딩 방식 결정
        //  - h264 입력 → copy (재인코딩 없음, 화질/용량 그대로)
        //  - 그 외(HEVC/WMV/VC-1 등) → HW(Intel QSV 등) 우선 시도, 실패 시 SW(libx264) 폴백
        $isCopy = ($videoCodec === 'h264');
        // SW 명령(폴백/최종): libx264 화질 우선
        $swVArgs = '-c:v libx264 -preset medium -crf 18 -pix_fmt yuv420p';
        // HW 명령: detectHwEncoder가 동작 확인된 인코더 반환 (libx264면 HW 없음 → SW만)
        $hwArgsRaw = $isCopy ? '' : $this->detectHwEncoder($ffmpeg);
        $hwAvailable = (!$isCopy && $hwArgsRaw !== '' && strpos($hwArgsRaw, 'libx264') === false);
        // HW 화질 우선 조정: 실시간용 global_quality 23 → 변환용 20(화질↑). qp 23 → 20.
        $hwVArgs = $hwArgsRaw;
        if ($hwAvailable) {
            $hwVArgs = preg_replace('/-global_quality\s+\d+/', '-global_quality 20', $hwVArgs);
            $hwVArgs = preg_replace('/-qp\s+\d+/', '-qp 20', $hwVArgs);
            $hwVArgs = preg_replace('/-qp_i\s+\d+/', '-qp_i 20', $hwVArgs);
            $hwVArgs = preg_replace('/-qp_p\s+\d+/', '-qp_p 20', $hwVArgs);
            // 변환은 실시간이 아니므로 속도 preset을 화질 쪽으로 (qsv veryfast→medium)
            $hwVArgs = preg_replace('/-preset\s+veryfast/', '-preset medium', $hwVArgs);
        }

        $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_conv_prog_' . md5($outPath . microtime(true)) . '.txt';

        // 변환 명령 빌더 (vArgs만 다르게)
        $buildConvCmd = function($vArgs, $aArgsOverride = null) use ($ffmpeg, $fullPath, $aArgs, $progressFile, $tmpOut, $cvArg, $convSid) {
            $aUse = ($aArgsOverride !== null) ? $aArgsOverride : $aArgs; // copy 폴백 시 오디오도 재인코딩(-c:a aac)
            return $cvArg($ffmpeg)
                . ' -y'
                . ' -analyzeduration 2000000 -probesize 2000000'  // 트랜스코딩과 동일 — 복잡/손상 .ts 스트림 분석 견딤
                . ' -fflags +genpts+igndts+fastseek+discardcorrupt'  // discardcorrupt: 손상 패킷 버림(copy 모드에서 MP4 muxer Invalid data 회피)
                . ' -i ' . $cvArg($fullPath)
                . ' -map 0:v:0 -map 0:a? -sn '                     // -sn: 자막/데이터 스트림 무시(손상 stream 회피) — 트랜스코딩 일관
                . $vArgs . ' ' . $aUse
                . ' -metadata comment=convsid_' . $convSid        // 취소/새로고침 시 wmic로 이 ffmpeg 찾아 kill
                . ' -movflags +faststart'
                . ' -f mp4'                                        // 출력 포맷 명시 — 임시 확장자(.converting)라 ffmpeg가 확장자로 포맷 추론 못 하므로 필수
                . ' -progress ' . $cvArg($progressFile)
                . ' ' . $cvArg($tmpOut);                          // dotfile 임시 출력 (완료 시 outPath로 rename)
        };

        // 첫 시도: copy면 copy, HW 가능하면 HW, 아니면 SW
        $firstVArgs = $isCopy ? '-c:v copy' : ($hwAvailable ? $hwVArgs : $swVArgs);
        $firstMode = $isCopy ? 'copy' : ($hwAvailable ? 'hw' : 'libx264');

        $sse('progress', ['percent' => 1, 'stage' => __('convert_encoding', '변환 중...'),
            'mode' => $firstMode, 'src_codec' => $videoCodec]);

        // 5) 백그라운드 실행 클로저 (vArgs 받아 실행, resultFile/stderrLog 반환)
        $runConv = function($vArgs, $aArgsOverride = null) use ($buildConvCmd, $tmpOut, $progressFile, $outPath) {
            @unlink($progressFile);
            @unlink($tmpOut);
            $cmd = $buildConvCmd($vArgs, $aArgsOverride);
            $resultFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_conv_result_' . md5($outPath . microtime(true)) . '.txt';
            $stderrLog  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_conv_err_' . md5($outPath . microtime(true)) . '.txt';
            @unlink($resultFile);
            @unlink($stderrLog);
            if (PHP_OS_FAMILY === 'Windows') {
                $batFile = sys_get_temp_dir() . '\\fs_conv_' . md5(uniqid()) . '.bat';
                $batContent = "@echo off\r\nsetlocal disabledelayedexpansion\r\nchcp 65001 >nul\r\n" . $cmd . ' 2>"' . $stderrLog . '"' . "\r\necho %ERRORLEVEL% > \"" . $resultFile . "\"\r\ndel \"%~f0\"\r\n";
                @file_put_contents($batFile, $batContent);
                pclose(popen('start /b cmd /c "' . $batFile . '"', 'r'));
            } else {
                $bgCmd = '(' . $cmd . ' 2>' . escapeshellarg($stderrLog) . '; echo $? > ' . escapeshellarg($resultFile) . ') > /dev/null 2>&1 &';
                @exec($bgCmd);
            }
            return [$resultFile, $stderrLog];
        };

        // HW 에러 패턴 (실시간 트랜스코딩과 동일 — HW 실패 시 SW 폴백 트리거)
        $hwErrorPatterns = [
            '/No capable devices found/i',
            '/not supported by the QSV runtime/i',
            '/\[h264_qsv[^\]]*\].*Error while opening/i',
            '/\[h264_nvenc[^\]]*\].*failed/i',
            '/\[h264_amf[^\]]*\].*failed/i',
            '/Error initializing output stream/i',
            '/Device creation failed/i',
        ];

        // 첫 실행 (copy/HW/SW). HW로 시작했으면 실패 시 SW 폴백 가능.
        $curVArgs = $firstVArgs;
        $triedSw = $isCopy || !$hwAvailable; // copy거나 처음부터 SW면 (HW→SW) 폴백 불필요
        $triedReencode = false;  // copy 실패 → 재인코딩 폴백 1회 플래그 (손상 .ts 구제)
        $reencFallbackAArgs = '-c:a aac -b:a 192k';  // copy 폴백 시 오디오 재인코딩 인자 (폴백 블록 + 진단 로그 공유)
        list($resultFile, $stderrLog) = $runConv($curVArgs);

        // 6) 진행률 폴링 (ffmpeg -progress 파일의 out_time_ms 사용)
        $startTime = time();
        $maxWait = 3600; // 최대 1시간
        $lastPercent = 1;
        $lastSpeed = ''; // 마지막 유효 배속 캐시 — percent 갱신 순간 speed가 N/A면 직전값 사용(타이밍 누락 보완)
        // ffmpeg kill 헬퍼 (convsid 마커로 wmic 검색 후 taskkill — 트랜스코딩 pipe_kill과 동일 방식)
        $killConvFfmpeg = function() use ($convSid) {
            if (PHP_OS_FAMILY === 'Windows') {
                $output = @shell_exec('wmic process where "name=\'ffmpeg.exe\'" get processid,commandline /format:csv 2>NUL');
                if ($output) {
                    foreach (explode("\n", $output) as $line) {
                        if (strpos($line, 'convsid_' . $convSid) !== false && preg_match('/,(\d+)\s*$/', trim($line), $m)) {
                            @exec('taskkill /F /PID ' . (int)$m[1] . ' 2>NUL');
                        }
                    }
                }
            } else {
                @exec('pkill -f ' . escapeshellarg('convsid_' . $convSid) . ' 2>/dev/null');
            }
        };

        $clientGone = false;
        while (true) {
            if (!$clientGone && connection_aborted()) {
                // 클라이언트 끊김(새로고침/탭 종료/취소) — 진행 중인 ffmpeg를 kill해 CPU/GPU 자원 낭비 방지.
                //   (이전엔 ffmpeg가 끝까지 돌아 자원 점유. 이제 끊기면 즉시 중단.)
                $clientGone = true;
                $killConvFfmpeg();
                // taskkill 후 ffmpeg가 출력 파일 핸들을 놓을 때까지 잠시 대기 (Windows 파일 잠금 → 즉시 unlink 실패 방지)
                usleep(800000); // 0.8s
                // 임시파일 정리
                @unlink($progressFile);
                @unlink($resultFile);
                @unlink($stderrLog);
                // 미완성 출력(.converting dotfile) 삭제 — ffmpeg 종료 지연으로 잠겨 있을 수 있어 최대 5회 재시도
                for ($i = 0; $i < 5; $i++) {
                    clearstatcache();
                    if (!is_file($tmpOut)) break;
                    if (@unlink($tmpOut)) break;
                    usleep(400000); // 0.4s 후 재시도 (핸들 해제 대기)
                }
                break;
            }
            // 완료 감지
            if (file_exists($resultFile)) {
                clearstatcache();
                $code = trim(@file_get_contents($resultFile) ?: '');
                @unlink($resultFile);
                $okOutput = ($code === '0' && is_file($tmpOut) && filesize($tmpOut) > 0);

                // ★ 실행 결과 진단 로그 — 실패 시에만 기록(성공은 로그 안 남김)
                if (!$okOutput) {
                    // mode 판정: curVArgs가 swVArgs면 재인코딩(libx264) — copy 폴백 후에도 정확히 표시
                    $_cvModeStr = ($curVArgs === $swVArgs) ? ($triedReencode ? 'libx264(reencode-fallback)' : 'libx264') : ($isCopy ? 'copy' : 'hw');
                    $_cvLog('FAIL 실행결과 mode=' . $_cvModeStr . ' code=[' . $code . '] tmpOut_exists=' . (is_file($tmpOut) ? '1' : '0')
                        . ' size=' . (is_file($tmpOut) ? filesize($tmpOut) : 'N/A'));
                    // cmd 로그: 재인코딩 폴백 시 오디오 오버라이드(aac) 반영 — 실제 실행 명령과 일치
                    $_cvLog('cmd=' . $buildConvCmd($curVArgs, $triedReencode ? $reencFallbackAArgs : null));
                    $_seDbg = @file_get_contents($stderrLog) ?: '';
                    $_cvLog('stderr_tail=' . mb_substr(trim($_seDbg), -1500));
                }

                // HW 실패 → SW(libx264) 1회 폴백 (아직 SW 안 써봤을 때만)
                if (!$okOutput && !$triedSw) {
                    $errLog = @file_get_contents($stderrLog) ?: '';
                    $hwFailed = true; // 출력 실패면 폴백 시도 (패턴 매칭은 로그 확인용 보조)
                    foreach ($hwErrorPatterns as $pat) {
                        if (preg_match($pat, $errLog)) { $hwFailed = true; break; }
                    }
                    @unlink($stderrLog);
                    if ($hwFailed) {
                        $triedSw = true;
                        $curVArgs = $swVArgs;
                        $lastPercent = 1;
                        if (!$clientGone) {
                            $sse('progress', ['percent' => 1, 'stage' => __('convert_sw_fallback', '하드웨어 변환 실패 — CPU로 재시도...'), 'mode' => 'libx264']);
                        }
                        list($resultFile, $stderrLog) = $runConv($curVArgs);
                        $startTime = time(); // 타임아웃 리셋
                        usleep(500000);
                        continue;
                    }
                }
                // copy 실패 → 재인코딩(libx264 + aac) 1회 폴백
                //   원인: 손상 .ts의 깨진 패킷이 copy 모드로 그대로 MP4 muxer에 전달 → Invalid data 거부.
                //   트랜스코딩(재생)은 항상 재인코딩이라 디코드→재인코드로 손상 흡수 → 됨.
                //   따라서 copy 실패 시 비디오+오디오 모두 재인코딩하면 트랜스코딩과 동일하게 성공.
                if (!$okOutput && $isCopy && !$triedReencode) {
                    $triedReencode = true;
                    $curVArgs = $swVArgs;                 // 비디오 재인코딩(libx264)
                    $reencAArgs = $reencFallbackAArgs;    // 오디오도 재인코딩(copy 오디오 손상 회피)
                    $lastPercent = 1;
                    @unlink($stderrLog);
                    if (!$clientGone) {
                        $sse('progress', ['percent' => 1, 'stage' => __('convert_reencode_fallback', '손상 감지 — 재인코딩으로 재시도...'), 'mode' => 'libx264']);
                    }
                    list($resultFile, $stderrLog) = $runConv($curVArgs, $reencAArgs);
                    $startTime = time(); // 타임아웃 리셋
                    usleep(500000);
                    continue;
                }
                // ★ 실패 진단용: stderr 마지막 부분 저장 (삭제 전에 읽어둠)
                $convStderrTail = '';
                $_se = @file_get_contents($stderrLog) ?: '';
                if ($_se !== '') {
                    $_se = trim($_se);
                    $convStderrTail = mb_substr($_se, -800); // 마지막 800자
                }
                @unlink($stderrLog);
                @unlink($progressFile);
                if ($okOutput) {
                    // 검증: 출력(임시 dotfile)이 실제로 h264인지 확인
                    $verifyOut = @shell_exec($cvArg($ffmpeg) . ' -i ' . $cvArg($tmpOut) . ' 2>&1') ?? '';
                    $okH264 = (bool)preg_match('/Video:\s*h264/i', $verifyOut);
                    if (!$okH264) {
                        @unlink($tmpOut);
                        $sse('error', ['error' => __('convert_verify_fail', '변환 결과 검증 실패 (H264 아님)')]);
                        return;
                    }
                    // 검증 통과 → 임시 dotfile을 최종 outPath로 rename (이 순간 목록에 정상 mp4로 나타남)
                    if (!@rename($tmpOut, $outPath)) {
                        @unlink($tmpOut);
                        $sse('error', ['error' => __('convert_failed', '변환 실패') . ' (rename)']);
                        return;
                    }
                    $outRel = ltrim(str_replace($basePath, '', $outPath), '/\\');
                    $outRel = str_replace(DIRECTORY_SEPARATOR, '/', $outRel);

                    // ★ 원본 휴지통 이동 (deleteOriginal=true일 때만 — 3단계에서 활성화)
                    $trashed = false;
                    $trashSkippedNoPerm = false;
                    if ($deleteOriginal) {
                        // 삭제(휴지통 이동)는 can_delete 권한 필요 — write만으로 원본 제거 우회 차단.
                        // 공유 스토리지에서 쓰기는 되나 삭제 권한 없는 사용자가 변환으로 원본을 치우는 것 방지.
                        $cvParentDir = dirname($relativePath);
                        if ($cvParentDir === '.' || $cvParentDir === DIRECTORY_SEPARATOR) $cvParentDir = '';
                        $canDelete = $this->storage->checkPermission($storageId, 'can_delete')
                            && $this->storage->checkFolderPermission($storageId, $cvParentDir ?: $relativePath, 'can_delete');
                        if (!$canDelete) {
                            $trashSkippedNoPerm = true; // 권한 없어 원본 보존됨 (UI 알림용)
                        } elseif (realpath($outPath) !== realpath($fullPath)) {
                            // 삭제 권한 있고 출력≠원본일 때만 휴지통 이동
                            $tr = $this->moveToTrash($storageId, $relativePath, $fullPath);
                            $trashed = !empty($tr['success']);
                        }
                    }
                    $sse('done', [
                        'percent' => 100,
                        'output' => $outRel,
                        'output_name' => $outName,
                        'size' => filesize($outPath),
                        'original_trashed' => $trashed,
                        'trash_skipped_no_perm' => $trashSkippedNoPerm,
                        'mode' => ($isCopy ? 'copy' : ($curVArgs === $swVArgs ? 'libx264' : 'hw')),
                    ]);
                    @unlink($progressFile); @unlink($resultFile); @unlink($stderrLog); // 완료 후 임시파일 정리
                    return;
                } else {
                    @unlink($tmpOut); // 실패 시 불완전 임시 출력 제거 (rename 전이라 tmpOut)
                    @unlink($progressFile); @unlink($resultFile); // 실패 후 임시파일 정리(stderrLog는 아래 진단 후 정리)
                    // ★ 진단: ffmpeg stderr 마지막 부분을 함께 전달 (실패 원인 파악용)
                    $sse('error', [
                        'error' => __('convert_failed', '변환 실패') . ' (code=' . $code . ')',
                        'detail' => $convStderrTail,
                    ]);
                    @unlink($stderrLog); // 진단 detail 추출 후 임시파일 정리
                    return;
                }
            }
            // 진행률 갱신
            if ($durationSec > 0 && file_exists($progressFile)) {
                $pc = @file_get_contents($progressFile) ?: '';
                if (preg_match_all('/out_time_ms=(\d+)/', $pc, $om) && !empty($om[1])) {
                    $outMs = (int)end($om[1]);
                    $cur = $outMs / 1000000.0; // us → s
                    $percent = (int)min(99, max($lastPercent, ($cur / $durationSec) * 100));
                    // ffmpeg -progress 파일의 speed= 값(배속) 파싱 (예: speed=2.5x, 빠른 변환은 지수표기 speed=2.9e+03x)
                    $speed = '';
                    if (preg_match_all('/speed=\s*([\d.]+(?:e[+-]?\d+)?)x/i', $pc, $sm) && !empty($sm[1])) {
                        $raw = (string)end($sm[1]); // 가장 최근 값
                        // 지수 표기(2.9e+03)면 일반 숫자로 변환(2900). 일반 표기는 그대로.
                        if (stripos($raw, 'e') !== false) {
                            $raw = (string)round((float)$raw); // 지수 → 정수 (빠른 변환은 수백~수천 배)
                        }
                        $speed = $raw;
                        if ($speed !== '') $lastSpeed = $speed; // 유효 배속 캐시 갱신
                    }
                    if ($percent > $lastPercent) {
                        $lastPercent = $percent;
                        $prog = ['percent' => $percent, 'stage' => __('convert_encoding', '변환 중...')];
                        // 현재 speed가 비었으면(N/A 등) 직전 유효 배속 사용 — percent 갱신과 speed 출현 타이밍 어긋남 보완
                        $useSpeed = ($speed !== '') ? $speed : $lastSpeed;
                        if ($useSpeed !== '') $prog['speed'] = $useSpeed; // 배속 (예: "2.5")
                        $sse('progress', $prog);
                    }
                }
            }
            if (time() - $startTime > $maxWait) {
                $killConvFfmpeg(); // 시간 초과 — 매달린 ffmpeg 정리
                @unlink($progressFile); @unlink($tmpOut);
                $sse('error', ['error' => __('convert_timeout', '변환 시간 초과')]);
                return;
            }
            // keepalive(SSE 주석) — percent 정체 구간에도 출력하여 connection_aborted를 매 폴링 감지
            //   (클라이언트 끊김 시 ffmpeg kill이 빠르게 발동되도록). 주석(:)이라 클라이언트 무영향.
            if (!$clientGone) { echo ": ka\n\n"; @flush(); }
            usleep(500000); // 0.5s
        }
    }

    /**
     * 폴더 내 오디오 파일 duration 일괄 조회 (캐시 + 하이브리드 고속 추출)
     * - MP3: PHP로 프레임 헤더 읽어서 계산 (수 KB만 읽음, 매우 빠름)
     * - 기타 (FLAC/OGG/M4A 등): ffprobe 병렬 실행 (4개씩 동시)
     * - 캐시 키: storage_id:path:size:mtime (파일 변경 시 자동 무효화)
     * - $clientFiles: 원격 스토리지 최적화용 (클라이언트가 이미 가진 파일 목록 재사용)
     */
    public function getAudioDurations(int $storageId, string $folderPath, array $clientFiles = []): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => 'Permission denied'];
        }
        
        // 대용량 폴더 파싱 시 PHP 타임아웃 방지
        @set_time_limit(60);
        
        $audioExts = ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'wma', 'opus'];
        $isRemote = $this->isRemoteStorage($storageId);
        
        // 원격 스토리지: 파일 목록을 어댑터로부터 받아옴 (stat 호출 불가능, 캐시 조회만)
        if ($isRemote) {
            $audioFiles = [];
            
            // 클라이언트가 파일 목록을 제공했으면 재사용 (remote list 호출 중복 방지)
            if (!empty($clientFiles)) {
                foreach ($clientFiles as $cf) {
                    if (!is_array($cf)) continue;
                    $fname = $cf['name'] ?? '';
                    if (!$fname) continue;
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    if (!in_array($ext, $audioExts)) continue;
                    // 보안: path 검증
                    $relpath = $folderPath ? $folderPath . '/' . $fname : $fname;
                    if (strpos($fname, '..') !== false || strpos($fname, '/') !== false || strpos($fname, '\\') !== false) continue;
                    $audioFiles[] = [
                        'name' => $fname,
                        'ext' => $ext,
                        'full' => '',
                        'relpath' => $relpath,
                        'size' => (int)($cf['size'] ?? 0),
                        'mtime' => (int)($cf['mtime'] ?? 0),
                    ];
                }
            } else {
                // 클라이언트 정보 없으면 서버에서 조회 (기존 방식)
                $listResult = $this->listFilesRemote($storageId, $folderPath);
                if (!($listResult['success'] ?? false)) {
                    return ['success' => true, 'durations' => (object)[], 'details' => (object)[], 'cached' => 0, 'computed' => 0, 'is_remote' => true];
                }
                foreach (($listResult['items'] ?? []) as $item) {
                    if (!empty($item['is_dir'])) continue;
                    $fname = $item['name'] ?? '';
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    if (!in_array($ext, $audioExts)) continue;
                    $audioFiles[] = [
                        'name' => $fname,
                        'ext' => $ext,
                        'full' => '',
                        'relpath' => $folderPath ? $folderPath . '/' . $fname : $fname,
                        'size' => (int)($item['size'] ?? 0),
                        'mtime' => @strtotime($item['modified'] ?? '') ?: 0,
                    ];
                }
            }
            
            if (empty($audioFiles)) {
                return ['success' => true, 'durations' => (object)[], 'details' => (object)[], 'cached' => 0, 'computed' => 0, 'is_remote' => true];
            }
            
            // 캐시 조회만 (원격은 ffprobe 불가)
            $dataDir = defined('DATA_PATH') ? DATA_PATH : (dirname(__DIR__) . '/data');
            $cacheFile = $dataDir . '/audio_durations.json';
            $cache = [];
            if (is_file($cacheFile)) {
                $content = @file_get_contents($cacheFile);
                if ($content) {
                    $decoded = @json_decode($content, true);
                    if (is_array($decoded)) $cache = $decoded;
                }
            }
            
            $durations = [];
            $details = [];
            $cacheHits = 0;
            $computed = 0;
            $cacheUpdated = false;
            $fileMeta = []; // 클라이언트가 save 시 다시 보낼 메타데이터
            
            // 어댑터 및 Range read 지원 여부 (MP3 헤더 파싱용)
            $adapter = null;
            $supportsPartialRead = false;
            try {
                $adapter = $this->getAdapter($storageId);
                $supportsPartialRead = $adapter && method_exists($adapter, 'readPartial');
            } catch (\Throwable $e) {
                $adapter = null;
            }
            
            // 처리 시간 제한 (PHP 타임아웃 방지)
            $maxTime = 50;
            $startTime = microtime(true);
            $pendingCount = 0; // 타임아웃으로 처리 못한 파일 수
            $timedOut = false;
            $consecutiveFails = 0; // readPartial 연속 실패 카운터 (FTP range 미지원 감지용)
            
            foreach ($audioFiles as $af) {
                // 이미 타임아웃 발생한 경우: 캐시 hit만 처리하고 miss는 pending으로 카운트
                if ($timedOut) {
                    $cacheKey = $storageId . ':' . $af['relpath'] . ':' . $af['size'] . ':' . $af['mtime'];
                    $fileMeta[$af['name']] = [
                        'path' => $af['relpath'],
                        'size' => $af['size'],
                        'mtime' => $af['mtime'],
                    ];
                    if (isset($cache[$cacheKey])) {
                        $cached = $cache[$cacheKey];
                        if (is_numeric($cached)) {
                            $durations[$af['name']] = (float)$cached;
                        } else if (is_array($cached) && isset($cached['dur'])) {
                            $durations[$af['name']] = $cached['dur'];
                            if (isset($cached['sr'])) {
                                $details[$af['name']] = [
                                    'sample_rate' => $cached['sr'] ?? 0,
                                    'bitrate' => $cached['br'] ?? 0,
                                    'channels' => $cached['ch'] ?? 0,
                                ];
                            }
                        }
                        $cacheHits++;
                    } else {
                        $pendingCount++;
                    }
                    continue;
                }
                
                $cacheKey = $storageId . ':' . $af['relpath'] . ':' . $af['size'] . ':' . $af['mtime'];
                // 캐시 키 매칭용 메타데이터를 클라이언트에 제공
                $fileMeta[$af['name']] = [
                    'path' => $af['relpath'],
                    'size' => $af['size'],
                    'mtime' => $af['mtime'],
                ];
                if (isset($cache[$cacheKey])) {
                    $cached = $cache[$cacheKey];
                    if (is_numeric($cached)) {
                        $durations[$af['name']] = (float)$cached;
                    } else if (is_array($cached) && isset($cached['dur'])) {
                        $durations[$af['name']] = $cached['dur'];
                        if (isset($cached['sr'])) {
                            $details[$af['name']] = [
                                'sample_rate' => $cached['sr'] ?? 0,
                                'bitrate' => $cached['br'] ?? 0,
                                'channels' => $cached['ch'] ?? 0,
                            ];
                        }
                    }
                    $cacheHits++;
                    continue;
                }
                
                // 캐시 miss + 파싱 가능한 포맷 + Range read 가능한 어댑터 → 서버에서 직접 파싱
                $parseableFormats = ['mp3', 'flac', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'mp4'];
                if (in_array($af['ext'], $parseableFormats) && $supportsPartialRead && $af['size'] > 0) {
                    if ((microtime(true) - $startTime) > $maxTime) {
                        $timedOut = true;
                        $pendingCount++;
                        continue;
                    }
                    
                    // ★ 연속 실패 감지: FTP 서버가 range read 미지원이면 조기 포기
                    // 처음 3개 파일 시도 후 모두 빈 buffer면 이 어댑터는 작동 안 함
                    if ($consecutiveFails >= 3) {
                        $timedOut = true; // 이후 파일은 모두 pending 처리
                        $pendingCount++;
                        continue;
                    }
                    
                    // 파일 앞 64KB 읽기
                    $readLen = min(65536, $af['size']);
                    $buffer = '';
                    try {
                        $buffer = $adapter->readPartial($af['relpath'], 0, $readLen);
                    } catch (\Throwable $e) {
                        $buffer = '';
                    }
                    
                    // 실패 카운터
                    if ($buffer === '' || strlen($buffer) < 10) {
                        $consecutiveFails++;
                    } else {
                        $consecutiveFails = 0;
                    }
                    
                    $info = ['dur' => 0];
                    if ($buffer !== '' && strlen($buffer) >= 10) {
                        // 포맷별 파서 호출
                        if ($af['ext'] === 'mp3') {
                            $info = $this->_parseMp3Duration('', $af['size'], $buffer);
                            
                            // ID3v2 태그가 64KB보다 큰 경우 (커버 이미지 등) → 오디오 시작 위치부터 재시도
                            if ($info['dur'] === 0 && substr($buffer, 0, 3) === 'ID3') {
                                $b = unpack('C4', substr($buffer, 6, 4));
                                $tagSize = (($b[1] & 0x7F) << 21) | (($b[2] & 0x7F) << 14) | (($b[3] & 0x7F) << 7) | ($b[4] & 0x7F);
                                $audioStart = 10 + $tagSize;
                                if ($audioStart < $af['size'] && $audioStart > $readLen - 100) {
                                    try {
                                        $buffer2 = $adapter->readPartial($af['relpath'], $audioStart, 8192);
                                        if ($buffer2 !== '' && strlen($buffer2) >= 10) {
                                            $info = $this->_parseMp3Duration('', $af['size'], $buffer2);
                                        }
                                    } catch (\Throwable $e) {}
                                }
                            }
                        } else if ($af['ext'] === 'flac') {
                            $info = $this->_parseFlacDuration('', $af['size'], $buffer);
                        } else if ($af['ext'] === 'ogg' || $af['ext'] === 'oga' || $af['ext'] === 'opus') {
                            $info = $this->_parseOggDuration('', $af['size'], $buffer);
                        } else if ($af['ext'] === 'm4a' || $af['ext'] === 'aac' || $af['ext'] === 'mp4') {
                            $info = $this->_parseM4aDuration('', $af['size'], $buffer);
                        }
                    }
                    
                    if ($info['dur'] > 0) {
                        $durations[$af['name']] = $info['dur'];
                        $details[$af['name']] = [
                            'sample_rate' => $info['sr'],
                            'bitrate' => $info['br'],
                            'channels' => $info['ch'],
                        ];
                        $cache[$cacheKey] = [
                            'dur' => $info['dur'],
                            'sr' => $info['sr'],
                            'br' => $info['br'],
                            'ch' => $info['ch'],
                        ];
                        $cacheUpdated = true;
                        $computed++;
                    }
                }
            }
            
            // 캐시 저장
            if ($cacheUpdated) {
                if (count($cache) > 10000) {
                    $cache = array_slice($cache, -5000, null, true);                }
                @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
            
            return [
                'success' => true,
                'durations' => empty($durations) ? (object)[] : $durations,
                'details' => empty($details) ? (object)[] : $details,
                'cached' => $cacheHits,
                'computed' => $computed,
                'pending' => $pendingCount, // 타임아웃으로 미처리된 파일 수 (재요청 시 처리됨)
                'is_remote' => true,  // 클라이언트에게 "캐시 없는 것은 직접 측정 후 저장" 힌트
                'file_meta' => empty($fileMeta) ? (object)[] : $fileMeta, // save 시 사용할 size/mtime
                'debug' => [
                    'adapter_class' => $adapter ? get_class($adapter) : 'none',
                    'supports_partial_read' => $supportsPartialRead,
                    'total_files' => count($audioFiles),
                    'elapsed_ms' => (int)((microtime(true) - $startTime) * 1000),
                ],
            ];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) return ['success' => false, 'error' => 'Storage not found'];
        
        $fullPath = $this->buildPath($basePath, $folderPath);
        if (!$this->isPathSafe($basePath, $fullPath) || !is_dir($fullPath)) {
            return ['success' => false, 'error' => 'Folder not found'];
        }
        
        // 폴더 내 오디오 파일 수집
        $audioFiles = [];
        $dh = @opendir($fullPath);
        if ($dh) {
            while (($filename = readdir($dh)) !== false) {
                if ($filename === '.' || $filename === '..' || $filename[0] === '.') continue;
                $filePath = $fullPath . DIRECTORY_SEPARATOR . $filename;
                if (!is_file($filePath)) continue;
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($ext, $audioExts)) continue;
                $audioFiles[] = [
                    'name' => $filename,
                    'ext' => $ext,
                    'full' => $filePath,
                    'relpath' => $folderPath ? $folderPath . '/' . $filename : $filename,
                    'size' => @filesize($filePath) ?: 0,
                    'mtime' => @filemtime($filePath) ?: 0,
                ];
            }
            closedir($dh);
        }
        
        if (empty($audioFiles)) {
            return ['success' => true, 'durations' => (object)[], 'details' => (object)[], 'cached' => 0, 'computed' => 0];
        }
        
        // 캐시 파일 경로 (data/audio_durations.json)
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (dirname(__DIR__) . '/data');
        $cacheFile = $dataDir . '/audio_durations.json';
        $cache = [];
        if (is_file($cacheFile)) {
            $content = @file_get_contents($cacheFile);
            if ($content) {
                $decoded = @json_decode($content, true);
                if (is_array($decoded)) $cache = $decoded;
            }
        }
        
        $durations = [];
        $details = []; // sample_rate, bitrate, channels
        $cacheHits = 0;
        $computed = 0;
        $cacheUpdated = false;
        
        // 캐시 hit / miss 분리
        $needCompute = []; // ffprobe나 MP3 파서가 필요한 파일들
        $fileMeta = []; // 클라이언트에서 fallback bitrate 계산용
        foreach ($audioFiles as $af) {
            $cacheKey = $storageId . ':' . $af['relpath'] . ':' . $af['size'] . ':' . $af['mtime'];
            // file_meta는 클라이언트 fallback용 (bitrate 추정)
            $fileMeta[$af['name']] = [
                'path' => $af['relpath'],
                'size' => $af['size'],
                'mtime' => $af['mtime'],
            ];
            if (isset($cache[$cacheKey])) {
                $cached = $cache[$cacheKey];
                // 네이티브 파서 지원 포맷 (ffprobe 없이도 트랙 정보 추출 가능)
                $parseableExts = ['mp3', 'flac', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'mp4'];
                $isParseable = in_array($af['ext'], $parseableExts);
                
                // 구 형식 (숫자만) - duration 있음, details 없음 → 재파싱 필요
                if (is_numeric($cached)) {
                    $durations[$af['name']] = (float)$cached;
                    // 지원 포맷은 재파싱해서 details 채우기
                    if ($isParseable) {
                        $af['_cacheKey'] = $cacheKey;
                        $af['_hasDuration'] = true; // duration은 이미 있음 (재저장용)
                        $needCompute[] = $af;
                        $cacheHits++;
                        continue;
                    }
                    $cacheHits++;
                } else if (is_array($cached) && isset($cached['dur'])) {
                    $durations[$af['name']] = $cached['dur'];
                    if (isset($cached['sr'])) {
                        $details[$af['name']] = [
                            'sample_rate' => $cached['sr'] ?? 0,
                            'bitrate' => $cached['br'] ?? 0,
                            'channels' => $cached['ch'] ?? 0,
                        ];
                        $cacheHits++;
                    } else {
                        // details 없는 array 형식 → 파싱 가능한 포맷이면 재파싱
                        if ($isParseable) {
                            $af['_cacheKey'] = $cacheKey;
                            $af['_hasDuration'] = true;
                            $needCompute[] = $af;
                        }
                        $cacheHits++;
                    }
                }
            } else {
                $af['_cacheKey'] = $cacheKey;
                $needCompute[] = $af;
            }
        }
        
        // 최대 처리 시간 (PHP 타임아웃 방지)
        $maxTime = 50; // 초
        $startTime = microtime(true);
        $pendingCount = 0; // 타임아웃으로 미처리된 파일 수
        
        // === 1단계: MP3 고속 파싱 (PHP 네이티브) ===
        $mp3Files = array_filter($needCompute, fn($f) => $f['ext'] === 'mp3');
        $mp3TimedOut = false;
        foreach ($mp3Files as $af) {
            if ($mp3TimedOut || (microtime(true) - $startTime) > $maxTime) {
                $mp3TimedOut = true;
                $pendingCount++;
                continue;
            }
            $info = $this->_parseMp3Duration($af['full'], $af['size']);
            if ($info['dur'] > 0) {
                $durations[$af['name']] = $info['dur'];
                $details[$af['name']] = [
                    'sample_rate' => $info['sr'],
                    'bitrate' => $info['br'],
                    'channels' => $info['ch'],
                ];
                // 캐시에는 상세 정보도 저장
                $cache[$af['_cacheKey']] = [
                    'dur' => $info['dur'],
                    'sr' => $info['sr'],
                    'br' => $info['br'],
                    'ch' => $info['ch'],
                ];
                $cacheUpdated = true;
                $computed++;
            }
        }
        
        // === 2단계: 나머지 포맷 - 네이티브 파서 우선 시도 후 ffprobe fallback ===
        $otherFiles = array_values(array_filter($needCompute, fn($f) => $f['ext'] !== 'mp3'));
        
        // 네이티브 파서 지원 포맷 (ffprobe 없이도 트랙 정보 추출 가능)
        $nativeFormats = ['flac', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'mp4'];
        $stillNeedFfprobe = [];
        foreach ($otherFiles as $af) {
            if ((microtime(true) - $startTime) > $maxTime) {
                $stillNeedFfprobe[] = $af;
                continue;
            }
            $info = ['dur' => 0];
            if ($af['ext'] === 'flac') {
                $info = $this->_parseFlacDuration($af['full'], $af['size']);
            } else if ($af['ext'] === 'ogg' || $af['ext'] === 'oga' || $af['ext'] === 'opus') {
                $info = $this->_parseOggDuration($af['full'], $af['size']);
            } else if ($af['ext'] === 'm4a' || $af['ext'] === 'aac' || $af['ext'] === 'mp4') {
                $info = $this->_parseM4aDuration($af['full'], $af['size']);
            }
            
            if ($info['dur'] > 0) {
                $durations[$af['name']] = $info['dur'];
                $details[$af['name']] = [
                    'sample_rate' => $info['sr'],
                    'bitrate' => $info['br'],
                    'channels' => $info['ch'],
                ];
                $cache[$af['_cacheKey']] = [
                    'dur' => $info['dur'],
                    'sr' => $info['sr'],
                    'br' => $info['br'],
                    'ch' => $info['ch'],
                ];
                $cacheUpdated = true;
                $computed++;
            } else {
                // 네이티브 파서 실패 → ffprobe 폴백
                $stillNeedFfprobe[] = $af;
            }
        }
        $otherFiles = $stillNeedFfprobe;
        
        if (!empty($otherFiles) && (microtime(true) - $startTime) < $maxTime) {
            $probeBin = $this->findProbeBin();
            // proc_open 사용 가능 여부 체크
            $disArr = array_map('trim', explode(',', @ini_get('disable_functions') ?: ''));
            $hasProcOpen = function_exists('proc_open') && !in_array('proc_open', $disArr);
            
            if ($probeBin && $hasProcOpen) {
                $CONCURRENT = 4; // 동시 실행 수
                $i = 0;
                while ($i < count($otherFiles)) {
                    if ((microtime(true) - $startTime) > $maxTime) break;
                    
                    // 병렬 배치 준비
                    $batch = [];
                    $procs = [];
                    $pipes = [];
                    for ($j = 0; $j < $CONCURRENT && $i < count($otherFiles); $j++, $i++) {
                        $af = $otherFiles[$i];
                        $cmd = escapeshellarg($probeBin) . ' -i ' . escapeshellarg($af['full']) . ' 2>&1';
                        $descriptors = [
                            0 => ['pipe', 'r'],
                            1 => ['pipe', 'w'],
                            2 => ['pipe', 'w'],
                        ];
                        $proc = @proc_open($cmd, $descriptors, $pipe);
                        if (is_resource($proc)) {
                            if (isset($pipe[0])) fclose($pipe[0]);
                            $batch[] = ['af' => $af, 'proc' => $proc, 'pipes' => $pipe];
                        }
                    }
                    
                    // 결과 수집
                    foreach ($batch as $item) {
                        $output = '';
                        if (isset($item['pipes'][1])) {
                            $output .= @stream_get_contents($item['pipes'][1]);
                            fclose($item['pipes'][1]);
                        }
                        if (isset($item['pipes'][2])) {
                            $output .= @stream_get_contents($item['pipes'][2]);
                            fclose($item['pipes'][2]);
                        }
                        @proc_close($item['proc']);
                        
                        $this->_parseFfprobeOutput($output, $item['af'], $durations, $details, $cache);
                        if (isset($durations[$item['af']['name']])) {
                            $cacheUpdated = true;
                            $computed++;
                        }
                    }
                }
            } else if ($probeBin) {
                // proc_open 비활성화 환경: shell_exec fallback (순차 실행)
                foreach ($otherFiles as $af) {
                    if ((microtime(true) - $startTime) > $maxTime) break;
                    $cmd = escapeshellarg($probeBin) . ' -i ' . escapeshellarg($af['full']) . ' 2>&1';
                    $output = @shell_exec($cmd);
                    if ($output) {
                        $this->_parseFfprobeOutput($output, $af, $durations, $details, $cache);
                        if (isset($durations[$af['name']])) {
                            $cacheUpdated = true;
                            $computed++;
                        }
                    }
                }
            }
        }
        
        // 캐시 크기 제한 (10000개까지, 오래된 항목 제거)
        if ($cacheUpdated) {
            if (count($cache) > 10000) {
                $cache = array_slice($cache, -5000, null, true);
            }
            @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        
        return [
            'success' => true,
            'durations' => empty($durations) ? (object)[] : $durations,
            'details' => empty($details) ? (object)[] : $details,
            'cached' => $cacheHits,
            'computed' => $computed,
            'pending' => $pendingCount, // 타임아웃으로 미처리된 파일 수
            'file_meta' => empty($fileMeta) ? (object)[] : $fileMeta,
        ];
    }
    
    /**
     * 클라이언트가 측정한 duration을 캐시에 저장 (원격 스토리지용)
     * 클라이언트가 tmpAudio로 측정한 결과를 서버 캐시에 반영
     * @param array $items [['path' => 'folder/song.mp3', 'duration' => 184.5, 'sample_rate' => 44100, ...], ...]
     */
    public function saveAudioDurations(int $storageId, array $items): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => 'Permission denied'];
        }
        
        if (empty($items)) {
            return ['success' => true, 'saved' => 0];
        }
        
        // 요청당 최대 1000개로 제한 (공격 방지)
        if (count($items) > 1000) {
            $items = array_slice($items, 0, 1000);
        }
        
        $isRemote = $this->isRemoteStorage($storageId);
        
        // 원격이 아니면 서버에서 직접 계산하므로 저장 불필요
        if (!$isRemote) {
            return ['success' => true, 'saved' => 0, 'skipped' => 'local_storage'];
        }
        
        // 원격 스토리지: 파일 정보 확인 (size/mtime)
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (dirname(__DIR__) . '/data');
        $cacheFile = $dataDir . '/audio_durations.json';
        $cache = [];
        if (is_file($cacheFile)) {
            $content = @file_get_contents($cacheFile);
            if ($content) {
                $decoded = @json_decode($content, true);
                if (is_array($decoded)) $cache = $decoded;
            }
        }
        
        $saved = 0;
        foreach ($items as $item) {
            $path = $item['path'] ?? '';
            $dur = (float)($item['duration'] ?? 0);
            if (!$path || $dur <= 0) continue;
            
            // 보안: path 검증 (절대경로/traversal 차단)
            if ($path[0] === '/' || $path[0] === '\\' || strpos($path, '..') !== false) continue;
            // 보안: duration 범위 검증 (0 < dur < 24시간)
            if ($dur > 86400) continue;
            // 보안: path 길이 제한
            if (strlen($path) > 1024) continue;
            
            // 파일 정보 (클라이언트가 전달한 size/mtime 신뢰)
            $size = (int)($item['size'] ?? 0);
            $mtime = (int)($item['mtime'] ?? 0);
            if ($size <= 0) continue;
            
            $cacheKey = $storageId . ':' . $path . ':' . $size . ':' . $mtime;
            
            $sr = (int)($item['sample_rate'] ?? 0);
            $br = (int)($item['bitrate'] ?? 0);
            $ch = (int)($item['channels'] ?? 0);
            
            // 보안: 수치 범위 검증
            if ($sr > 384000 || $br > 10000000 || $ch > 16) continue;
            
            if ($sr || $br || $ch) {
                $cache[$cacheKey] = [
                    'dur' => $dur,
                    'sr' => $sr,
                    'br' => $br,
                    'ch' => $ch,
                ];
            } else {
                $cache[$cacheKey] = $dur;
            }
            $saved++;
        }
        
        if ($saved > 0) {
            if (count($cache) > 10000) {
                $cache = array_slice($cache, -5000, null, true);
            }
            // 캐시 파일 저장 (실패 진단을 위해 결과 확인)
            $writeResult = @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
            if ($writeResult === false) {
                // 저장 실패: 데이터 디렉토리 문제 진단 정보 반환
                return [
                    'success' => false, 
                    'saved' => 0,
                    'error' => 'Cache write failed',
                    'debug' => [
                        'cache_file' => $cacheFile,
                        'dir_exists' => is_dir($dataDir),
                        'dir_writable' => is_dir($dataDir) ? is_writable($dataDir) : false,
                        'file_exists' => is_file($cacheFile),
                        'file_writable' => is_file($cacheFile) ? is_writable($cacheFile) : false,
                    ],
                ];
            }
        }
        
        return [
            'success' => true, 
            'saved' => $saved,
            'items_received' => count($items),
            'cache_file' => basename($cacheFile),
        ];
    }
    
    /**
     * ffprobe 출력 파싱 - duration + audio details 추출 + 결과를 배열에 채움
     */
    private function _parseFfprobeOutput(string $output, array $af, array &$durations, array &$details, array &$cache): void {
        if (!preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $output, $m)) return;
        $dur = (int)$m[1] * 3600 + (int)$m[2] * 60 + (float)$m[3];
        if ($dur <= 0) return;
        
        // 오디오 스트림 상세 정보 추출
        $sr = 0; $ch = 0; $br = 0;
        if (preg_match('/Audio:.*?(\d+)\s*Hz/', $output, $sm)) $sr = (int)$sm[1];
        if (preg_match('/Audio:.*?Hz,\s*(stereo|mono|(\d+)\s*channels)/', $output, $cm)) {
            if (stripos($cm[1], 'stereo') !== false) $ch = 2;
            else if (stripos($cm[1], 'mono') !== false) $ch = 1;
            else $ch = (int)($cm[2] ?? 0);
        }
        if (preg_match('/Audio:.*?(\d+)\s*kb\/s/', $output, $bm)) $br = (int)$bm[1] * 1000;
        else if (preg_match('/bitrate:\s*(\d+)\s*kb\/s/', $output, $bm)) $br = (int)$bm[1] * 1000;
        
        $durations[$af['name']] = $dur;
        if ($sr || $ch || $br) {
            $details[$af['name']] = [
                'sample_rate' => $sr,
                'bitrate' => $br,
                'channels' => $ch,
            ];
            $cache[$af['_cacheKey']] = [
                'dur' => $dur,
                'sr' => $sr,
                'br' => $br,
                'ch' => $ch,
            ];
        } else {
            $cache[$af['_cacheKey']] = $dur;
        }
    }
    
    /**
     * MP3 파일 duration 계산 (ffprobe 없이 순수 PHP)
     * Xing/VBRI 헤더 우선, 없으면 CBR로 추정
     * 파일 앞부분 ~64KB만 읽으므로 매우 빠름
     * @param string $file 파일 경로 (또는 빈 문자열 + $buffer 전달)
     * @param int $fileSize 파일 전체 크기 (VBR 외 파일의 duration 계산용)
     * @param string $buffer 미리 읽은 데이터 (제공 시 $file 무시) — 원격 스토리지용
     * @return array {dur, sr (sample_rate), br (bitrate bps), ch (channels)}
     */
    private function _parseMp3Duration(string $file, int $fileSize, string $buffer = ''): array {
        $result = ['dur' => 0, 'sr' => 0, 'br' => 0, 'ch' => 0];
        
        // 데이터 소스 준비: buffer가 있으면 사용, 없으면 파일에서 읽기
        $data = '';
        $isLocalFile = ($buffer === '');
        if (!$isLocalFile) {
            // 원격 스토리지: 이미 읽은 버퍼 사용
            $data = $buffer;
        } else {
            // 로컬 스토리지: 파일 시작부터 64KB 읽기
            $fp = @fopen($file, 'rb');
            if (!$fp) return $result;
            $data = fread($fp, 65536);
            fclose($fp);
        }
        
        if (strlen($data) < 10) return $result;
        
        // ID3v2 태그 스킵
        $audioStart = 0;
        if (substr($data, 0, 3) === 'ID3') {
            $b = unpack('C4', substr($data, 6, 4));
            $tagSize = (($b[1] & 0x7F) << 21) | (($b[2] & 0x7F) << 14) | (($b[3] & 0x7F) << 7) | ($b[4] & 0x7F);
            $audioStart = 10 + $tagSize;
        }
        
        // ID3v2 태그가 버퍼보다 크면 (앨범 커버 이미지 등) → 오디오 시작 위치부터 다시 읽기
        // 로컬 파일만 가능 (원격은 이미 전달된 버퍼만 사용)
        if ($audioStart >= strlen($data) - 4) {
            if (!$isLocalFile) return $result; // 원격: 더 못 읽음
            // 로컬: fseek + fread로 오디오 데이터만 새로 읽기 (8KB면 프레임 헤더 + Xing 충분)
            $fp = @fopen($file, 'rb');
            if (!$fp) return $result;
            if (@fseek($fp, $audioStart) !== 0) {
                fclose($fp);
                return $result;
            }
            $data = fread($fp, 8192);
            fclose($fp);
            if (strlen($data) < 10) return $result;
            $audioStart = 0; // 새 버퍼는 오디오 데이터 시작부터
        }
        
        // 버퍼에서 첫 프레임 찾기 (audioStart 기준)
        $searchStart = $audioStart;
        if ($searchStart >= strlen($data) - 4) return $result;
        
        $frameOffset = -1;
        $bufLen = strlen($data);
        for ($i = $searchStart; $i < $bufLen - 4; $i++) {
            if (ord($data[$i]) === 0xFF && (ord($data[$i+1]) & 0xE0) === 0xE0) {
                $frameOffset = $i;
                break;
            }
        }
        if ($frameOffset === -1) return $result;
        
        $b1 = ord($data[$frameOffset + 1]);
        $b2 = ord($data[$frameOffset + 2]);
        $b3 = ord($data[$frameOffset + 3]);
        
        $versionBits = ($b1 >> 3) & 0x03;
        $layerBits = ($b1 >> 1) & 0x03;
        $bitrateIdx = ($b2 >> 4) & 0x0F;
        $sampleRateIdx = ($b2 >> 2) & 0x03;
        $channelMode = ($b3 >> 6) & 0x03;
        
        $bitrateTable = [
            1 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0],
            2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0],
        ];
        $sampleRateTable = [
            3 => [44100, 48000, 32000, 0],
            2 => [22050, 24000, 16000, 0],
            0 => [11025, 12000, 8000, 0],
        ];
        
        $mpegV = ($versionBits === 3) ? 1 : 2;
        $bitrate = $bitrateTable[$mpegV][$bitrateIdx] ?? 0;
        $sampleRate = $sampleRateTable[$versionBits][$sampleRateIdx] ?? 0;
        $channels = ($channelMode === 3) ? 1 : 2;
        
        if ($bitrate === 0 || $sampleRate === 0) return $result;
        
        $result['sr'] = $sampleRate;
        $result['br'] = $bitrate * 1000;
        $result['ch'] = $channels;
        
        // Xing/Info 헤더 체크 (VBR)
        $xingOffset = $frameOffset + (($mpegV === 1) ? 36 : 21);
        $duration = 0;
        
        if ($xingOffset + 24 <= $bufLen) {
            $tag = substr($data, $xingOffset, 4);
            if ($tag === 'Xing' || $tag === 'Info' || $tag === 'VBRI') {
                $frames = 0;
                if ($tag === 'VBRI') {
                    $fc = unpack('N', substr($data, $xingOffset + 14, 4));
                    $frames = $fc[1] ?? 0;
                } else {
                    $flags = unpack('N', substr($data, $xingOffset + 4, 4))[1];
                    if ($flags & 0x01) {
                        $fc = unpack('N', substr($data, $xingOffset + 8, 4));
                        $frames = $fc[1] ?? 0;
                    }
                }
                if ($frames > 0) {
                    $samplesPerFrame = ($mpegV === 1) ? 1152 : 576;
                    $duration = ($frames * $samplesPerFrame) / $sampleRate;
                    // VBR 파일: 평균 bitrate 추정
                    $audioBytes = max(0, $fileSize - $audioStart);
                    if ($duration > 0) {
                        $result['br'] = (int)(($audioBytes * 8) / $duration);
                    }
                }
            }
        }
        
        // Xing 없으면 CBR로 추정
        if ($duration <= 0) {
            $audioBytes = max(0, $fileSize - $audioStart);
            $duration = ($audioBytes * 8) / ($bitrate * 1000);
        }
        
        $result['dur'] = $duration > 0 ? round($duration, 2) : 0;
        return $result;
    }
    
    /**
     * FLAC 파일 파싱 (STREAMINFO metadata block)
     * FLAC 시그니처 "fLaC" 다음에 metadata blocks
     * STREAMINFO (type 0): 34 bytes
     *   - 16비트 min block size
     *   - 16비트 max block size
     *   - 24비트 min frame size
     *   - 24비트 max frame size
     *   - 20비트 sample rate
     *   - 3비트 channels - 1
     *   - 5비트 bits/sample - 1
     *   - 36비트 total samples
     * @return array {dur, sr, br, ch}
     */
    private function _parseFlacDuration(string $file, int $fileSize, string $buffer = ''): array {
        $result = ['dur' => 0, 'sr' => 0, 'br' => 0, 'ch' => 0];
        
        $data = '';
        if ($buffer !== '') {
            $data = $buffer;
        } else {
            $fp = @fopen($file, 'rb');
            if (!$fp) return $result;
            $data = fread($fp, 8192);
            fclose($fp);
        }
        
        if (strlen($data) < 42) return $result;
        
        // FLAC 시그니처 확인 ("fLaC" = 0x664C6143)
        if (substr($data, 0, 4) !== 'fLaC') return $result;
        
        // METADATA_BLOCK_HEADER: 1 byte (type) + 3 bytes (length)
        // STREAMINFO type = 0
        $blockType = ord($data[4]) & 0x7F;
        if ($blockType !== 0) return $result; // 첫 블록은 STREAMINFO여야 함
        
        // STREAMINFO 데이터: offset 8부터 34바이트
        // offset 8+10 = 18부터 sample_rate / channels / bits
        // bytes[18..22]: 
        //   sample_rate: 20 bits
        //   channels: 3 bits (실제 채널 수 = 값 + 1)
        //   bits/sample: 5 bits (실제 비트 = 값 + 1)
        //   total_samples: 36 bits
        if (strlen($data) < 42) return $result;
        
        $b18 = ord($data[18]);
        $b19 = ord($data[19]);
        $b20 = ord($data[20]);
        $b21 = ord($data[21]);
        
        // sample_rate: 20 비트 (b18 << 12 | b19 << 4 | b20 >> 4)
        $sampleRate = ($b18 << 12) | ($b19 << 4) | ($b20 >> 4);
        // channels: 3 비트 ((b20 >> 1) & 0x07) + 1
        $channels = (($b20 >> 1) & 0x07) + 1;
        // bits/sample: 5 비트 (((b20 & 0x01) << 4) | (b21 >> 4)) + 1
        $bitsPerSample = ((($b20 & 0x01) << 4) | ($b21 >> 4)) + 1;
        
        // total_samples: 36비트 (b21 & 0x0F) << 32 | b22 << 24 | b23 << 16 | b24 << 8 | b25
        $totalSamplesHi = $b21 & 0x0F;
        $b22 = ord($data[22]);
        $b23 = ord($data[23]);
        $b24 = ord($data[24]);
        $b25 = ord($data[25]);
        $totalSamples = ($totalSamplesHi * 4294967296) + ($b22 * 16777216) + ($b23 * 65536) + ($b24 * 256) + $b25;
        
        if ($sampleRate === 0 || $totalSamples === 0) return $result;
        
        $duration = $totalSamples / $sampleRate;
        // bitrate = (fileSize * 8) / duration (평균)
        $bitrate = ($duration > 0) ? (int)(($fileSize * 8) / $duration) : 0;
        
        $result['dur'] = round($duration, 2);
        $result['sr'] = $sampleRate;
        $result['br'] = $bitrate;
        $result['ch'] = $channels;
        
        return $result;
    }
    
    /**
     * OGG Vorbis 파일 파싱
     * OGG 컨테이너 페이지에서 Vorbis identification header 추출
     * OGG page: "OggS" + version + type + position + serial + page + crc + segments
     * 첫 Vorbis 패킷: 0x01 "vorbis" + version(4) + channels(1) + sample_rate(4) + ...
     * Duration: OGG 마지막 페이지의 granule position 필요 (긴 파일은 읽기 어려움 → 생략)
     * @return array {dur, sr, br, ch}
     */
    private function _parseOggDuration(string $file, int $fileSize, string $buffer = ''): array {
        $result = ['dur' => 0, 'sr' => 0, 'br' => 0, 'ch' => 0];
        
        $data = '';
        if ($buffer !== '') {
            $data = $buffer;
        } else {
            $fp = @fopen($file, 'rb');
            if (!$fp) return $result;
            $data = fread($fp, 8192);
            fclose($fp);
        }
        
        if (strlen($data) < 58) return $result;
        
        // OggS 시그니처 확인
        if (substr($data, 0, 4) !== 'OggS') return $result;
        
        // Vorbis identification 헤더 찾기: "\x01vorbis" 패턴
        $vorbisPos = strpos($data, "\x01vorbis");
        if ($vorbisPos === false) return $result;
        
        // \x01vorbis (7) + version (4) = 11 오프셋
        $headerStart = $vorbisPos + 7 + 4;
        if ($headerStart + 16 > strlen($data)) return $result;
        
        // channels: 1 byte
        $channels = ord($data[$headerStart]);
        // sample_rate: 4 bytes (little-endian)
        $srBytes = unpack('V', substr($data, $headerStart + 1, 4));
        $sampleRate = $srBytes[1] ?? 0;
        // bitrate_maximum: 4 bytes (signed LE)
        // bitrate_nominal: 4 bytes (signed LE)
        $bnBytes = unpack('V', substr($data, $headerStart + 9, 4));
        $bitrateNominal = $bnBytes[1] ?? 0;
        // sign conversion for 32-bit
        if ($bitrateNominal > 0x7FFFFFFF) $bitrateNominal -= 0x100000000;
        
        if ($sampleRate === 0) return $result;
        
        // Duration 계산: 평균 비트레이트 + 파일 크기로 추정
        // 정확한 duration은 마지막 페이지의 granule position이 필요
        $duration = 0;
        if ($bitrateNominal > 0) {
            $duration = ($fileSize * 8) / $bitrateNominal;
        }
        
        $result['sr'] = $sampleRate;
        $result['ch'] = $channels;
        $result['br'] = max(0, $bitrateNominal);
        $result['dur'] = $duration > 0 ? round($duration, 2) : 0;
        
        return $result;
    }
    
    /**
     * M4A/MP4 파일 파싱 (AAC 등)
     * MP4 container atom 구조:
     *   - ftyp (file type)
     *   - moov
     *     - mvhd (movie header - timescale, duration)
     *     - trak
     *       - mdia
     *         - mdhd (media header - timescale, duration)
     *         - minf
     *           - stbl
     *             - stsd (sample description - sample_rate, channels)
     * @return array {dur, sr, br, ch}
     */
    private function _parseM4aDuration(string $file, int $fileSize, string $buffer = ''): array {
        $result = ['dur' => 0, 'sr' => 0, 'br' => 0, 'ch' => 0];
        
        $data = '';
        if ($buffer !== '') {
            $data = $buffer;
        } else {
            $fp = @fopen($file, 'rb');
            if (!$fp) return $result;
            // M4A는 moov atom이 파일 끝에 있을 수도 있어 더 큰 버퍼 필요
            $data = fread($fp, 65536);
            fclose($fp);
        }
        
        if (strlen($data) < 16) return $result;
        
        // mvhd atom 찾기 (movie header)
        $mvhdPos = strpos($data, 'mvhd');
        if ($mvhdPos !== false && $mvhdPos + 24 <= strlen($data)) {
            // mvhd 구조: atom header (8) + version(1) + flags(3) + creation(4) + modification(4) + timescale(4) + duration(4)
            // mvhd 문자 이후: version(1) + flags(3) + creation(4) + modification(4) + timescale(4) + duration(4)
            $verPos = $mvhdPos + 4;
            $version = ord($data[$verPos]);
            
            if ($version === 0) {
                // v0: 32-bit timescale, 32-bit duration
                if ($verPos + 20 <= strlen($data)) {
                    $tsBytes = unpack('N', substr($data, $verPos + 12, 4));
                    $durBytes = unpack('N', substr($data, $verPos + 16, 4));
                    $timescale = $tsBytes[1] ?? 0;
                    $duration = $durBytes[1] ?? 0;
                    if ($timescale > 0) {
                        $result['dur'] = round($duration / $timescale, 2);
                    }
                }
            } else if ($version === 1) {
                // v1: 32-bit timescale, 64-bit duration
                if ($verPos + 28 <= strlen($data)) {
                    $tsBytes = unpack('N', substr($data, $verPos + 20, 4));
                    $durHiBytes = unpack('N', substr($data, $verPos + 24, 4));
                    $durLoBytes = unpack('N', substr($data, $verPos + 28, 4));
                    $timescale = $tsBytes[1] ?? 0;
                    $duration = (($durHiBytes[1] ?? 0) * 4294967296) + ($durLoBytes[1] ?? 0);
                    if ($timescale > 0) {
                        $result['dur'] = round($duration / $timescale, 2);
                    }
                }
            }
        }
        
        // stsd atom 찾기 (sample description)
        // stsd 안에 mp4a/alac/... 엔트리가 있고, 그 안에 sample_rate와 channels
        $stsdPos = strpos($data, 'stsd');
        if ($stsdPos !== false) {
            // stsd header 이후 mp4a 또는 alac atom 찾기
            $searchFrom = $stsdPos + 4;
            $audioAtomTypes = ['mp4a', 'alac', 'samr', 'ac-3', 'ec-3'];
            foreach ($audioAtomTypes as $atomType) {
                $atomPos = strpos($data, $atomType, $searchFrom);
                if ($atomPos !== false && $atomPos - $searchFrom < 200) {
                    // Audio Sample Entry: 
                    //   - reserved (6)
                    //   - data_reference_index (2)
                    //   - version (2)
                    //   - revision (2)
                    //   - vendor (4)
                    //   - channels (2)
                    //   - sample_size (2)
                    //   - ... 
                    //   - sample_rate (4 - 16.16 fixed point)
                    // Total offset from atom name: 16 bytes to channels, 24 bytes to sample_rate
                    $channelsOff = $atomPos + 4 + 16;
                    $srOff = $atomPos + 4 + 24;
                    if ($srOff + 4 <= strlen($data)) {
                        $chBytes = unpack('n', substr($data, $channelsOff, 2));
                        $srBytes = unpack('N', substr($data, $srOff, 4));
                        $channels = $chBytes[1] ?? 0;
                        $sampleRateFixed = $srBytes[1] ?? 0;
                        // 16.16 fixed point: upper 16 bits is integer sample rate
                        $sampleRate = ($sampleRateFixed >> 16) & 0xFFFF;
                        
                        if ($sampleRate > 0) $result['sr'] = $sampleRate;
                        if ($channels > 0) $result['ch'] = $channels;
                    }
                    break;
                }
            }
        }
        
        // bitrate 계산 (평균)
        if ($result['dur'] > 0) {
            $result['br'] = (int)(($fileSize * 8) / $result['dur']);
        }
        
        return $result;
    }
    
    /**
     * ffprobe/ffmpeg 바이너리 경로 찾기
     */
    private function findProbeBin(): string {
        $settings = $this->db->load('settings');
        $thumbSettings = $settings['thumbnails'] ?? [];
        $ffprobePath = trim($thumbSettings['ffprobe_path'] ?? '');
        $ffmpegPath = trim($thumbSettings['ffmpeg_path'] ?? '');
        
        if ($ffprobePath && @is_executable($ffprobePath)) return $ffprobePath;
        if ($ffmpegPath && @is_executable($ffmpegPath)) return $ffmpegPath;
        
        foreach (['ffprobe', 'ffmpeg'] as $bin) {
            $out = @shell_exec("$bin -version 2>&1");
            if ($out && strpos($out, 'version') !== false) return $bin;
        }
        return '';
    }
    
    private function buildPath(string $base, string $relative): string {
        // 슬래시 통일 후 DIRECTORY_SEPARATOR로 변환
        $base = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base);
        $relative = trim($relative, '/\\');
        if (empty($relative)) {
            return $base;
        }
        return $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }
    
    /**
     * 파일이 잠겨있는지 확인
     */
    private function isFileLocked(int $storageId, string $relativePath): bool {
        $locked = $this->db->find('locked_files', [
            'storage_id' => $storageId,
            'path' => $relativePath
        ]);
        return !empty($locked);
    }
    
    private function isPathSafe(string $basePath, string $targetPath): bool {
        // 경로 정규화 (Windows/Linux 호환)
        $basePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $basePath);
        $targetPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetPath);
        
        $realBase = realpath($basePath);
        $realTarget = realpath($targetPath);
        
        // basePath가 존재하지 않으면 생성 시도
        if ($realBase === false) {
            if (!is_dir($basePath)) {
                @mkdir($basePath, 0755, true);
            }
            $realBase = realpath($basePath);
            if ($realBase === false) {
                return false;
            }
        }
        
        // realpath 성공 시 직접 비교
        if ($realTarget !== false) {
            if (DIRECTORY_SEPARATOR === '\\') {
                return stripos($realTarget . DIRECTORY_SEPARATOR, $realBase . DIRECTORY_SEPARATOR) === 0
                       || strcasecmp($realTarget, $realBase) === 0;
            }
            return strpos($realTarget . DIRECTORY_SEPARATOR, $realBase . DIRECTORY_SEPARATOR) === 0
                   || $realTarget === $realBase;
        }
        
        // realpath 실패 — 파일이 존재하면 특수문자 파일명 (Windows)
        if (file_exists($targetPath) || is_file($targetPath) || is_dir($targetPath)) {
            // .. 경로 탐색이 없는지 정규화하여 확인
            $parts = explode(DIRECTORY_SEPARATOR, $targetPath);
            $resolved = [];
            foreach ($parts as $part) {
                if ($part === '..') {
                    if (empty($resolved)) return false;
                    array_pop($resolved);
                } elseif ($part !== '' && $part !== '.') {
                    $resolved[] = $part;
                }
            }
            $cleanTarget = implode(DIRECTORY_SEPARATOR, $resolved);
            $cleanBase = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realBase);
            if (DIRECTORY_SEPARATOR === '\\') {
                return stripos($cleanTarget, $cleanBase) === 0;
            }
            return strpos($cleanTarget, $cleanBase) === 0;
        }
        
        // 아직 존재하지 않는 경로 (새 파일/폴더) — 부모 디렉토리 확인
        $parent = dirname($targetPath);
        while (!is_dir($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }
        $realParent = realpath($parent);
        if ($realParent === false) {
            // 부모도 realpath 실패 시 file_exists로 fallback
            if (file_exists($parent)) {
                $cleanParent = implode(DIRECTORY_SEPARATOR, array_filter(explode(DIRECTORY_SEPARATOR, 
                    str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $parent)), function($p) { return $p !== '' && $p !== '.'; }));
                $cleanBase = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realBase);
                if (DIRECTORY_SEPARATOR === '\\') {
                    return stripos($cleanParent, $cleanBase) === 0;
                }
                return strpos($cleanParent, $cleanBase) === 0;
            }
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return stripos($realParent . DIRECTORY_SEPARATOR, $realBase . DIRECTORY_SEPARATOR) === 0
                   || strcasecmp($realParent, $realBase) === 0;
        }
        return strpos($realParent . DIRECTORY_SEPARATOR, $realBase . DIRECTORY_SEPARATOR) === 0
               || $realParent === $realBase;
    }
    
    private function sanitizeFilename(string $filename): string {
        // 위험한 문자 제거 (Windows/Linux 공통)
        $filename = preg_replace('/[<>:"\/\\|?*\x00-\x1f]/', '', $filename);
        
        $filename = trim($filename, '. ');
        return $filename ?: 'unnamed';
    }
    
    private function getUniqueFilename(string $path): string {
        if (!file_exists($path)) {
            return $path;
        }
        
        $dir = dirname($path);
        $isDir = is_dir($path);
        
        // 폴더는 확장자 분리 안함, 파일만 확장자 분리
        if ($isDir) {
            $basename = basename($path);
            $filename = $basename;
            $ext = '';
        } else {
            $basename = basename($path);
            $lastDot = strrpos($basename, '.');
            if ($lastDot !== false && $lastDot > 0) {
                $filename = substr($basename, 0, $lastDot);
                $ext = substr($basename, $lastDot + 1);
            } else {
                $filename = $basename;
                $ext = '';
            }
        }
        
        // Windows 스타일: (2)부터 시작
        $counter = 2;
        do {
            if ($ext) {
                $newName = $filename . ' (' . $counter . ').' . $ext;
            } else {
                $newName = $filename . ' (' . $counter . ')';
            }
            $newPath = $dir . DIRECTORY_SEPARATOR . $newName;
            $counter++;
        } while (file_exists($newPath));
        
        return $newPath;
    }
    
    private function getFileType(string $extension, bool $isDir): string {
        if ($isDir) return 'folder';
        if ($extension === 'pdf') return 'pdf';
        if (in_array($extension, PREVIEW_EXTENSIONS['image'])) return 'image';
        if (in_array($extension, PREVIEW_EXTENSIONS['video'])) return 'video';
        if (in_array($extension, PREVIEW_EXTENSIONS['audio'])) return 'audio';
        if (in_array($extension, PREVIEW_EXTENSIONS['document'])) return 'document';
        if (in_array($extension, PREVIEW_EXTENSIONS['code'])) return 'code';
        if (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz', 'xz', 'bz2', 'cab', 'iso', 'tgz'])) return 'archive';
        return 'default';
    }
    
    private function getFileIcon(string $type, string $extension = ''): string {
        // 폴더 / 기본 / TEXT(txt,log) / HWP는 기존 인라인 SVG 유지 (펜닐 선호)
        if ($type === 'folder') {
            // 사용자 정의 폴더 아이콘 확인 (user_icon_map.json의 __folder 키)
            //   있으면 해당 svg 파일 사용, 없으면 기본 노란 SVG
            static $_folderIconCache = null;
            if ($_folderIconCache === null) {
                $_userMapFile = dirname(__DIR__) . '/data/user_icon_map.json';
                if (file_exists($_userMapFile)) {
                    $_map = @json_decode(@file_get_contents($_userMapFile), true);
                    if (is_array($_map) && isset($_map['__folder'])) {
                        $_raw = (string)$_map['__folder'];
                        if (substr($_raw, -6) === '|label') $_raw = substr($_raw, 0, -6);
                        $_clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $_raw);
                        if ($_clean !== '') {
                            $_folderIconCache = $_clean;
                        }
                    }
                }
                if ($_folderIconCache === null) $_folderIconCache = '';  // 미스 캐싱
            }
            
            if ($_folderIconCache !== '') {
                $_customPath = dirname(__DIR__) . '/assets/file-icons/custom/' . $_folderIconCache . '.svg';
                $_builtinPath = dirname(__DIR__) . '/assets/file-icons/' . $_folderIconCache . '.svg';
                if (file_exists($_customPath)) {
                    return '<img src="' . htmlspecialchars(IconUrl::get($_folderIconCache, true)) . '" alt="folder" class="fs-file-icon-img" draggable="false" loading="lazy">';
                }
                if (file_exists($_builtinPath)) {
                    return '<img src="' . htmlspecialchars(IconUrl::get($_folderIconCache, false)) . '" alt="folder" class="fs-file-icon-img" draggable="false" loading="lazy">';
                }
                // 파일 없으면 기본 SVG로 fallback
            }
            
            return SVG_ICONS['folder'] ?? '';
        }
        
        $ext = strtolower($extension);
        
        // ★ 사용자 정의 아이콘 매핑 우선 (관리자가 설정한 경우)
        //   IconManager가 관리자 메뉴에서 변경한 매핑을 data/user_icon_map.json에 저장
        //   특수 아이콘(HWP, 자막, 압축 등)은 이 체크 이후에도 적용되므로 펜닐 커스텀이 오버라이드 가능
        static $_userIconCache = null;
        if ($_userIconCache === null) {
            $_userMapFile = dirname(__DIR__) . '/data/user_icon_map.json';
            $_userIconCache = file_exists($_userMapFile)
                ? (@json_decode(@file_get_contents($_userMapFile), true) ?: [])
                : [];
        }
        if (isset($_userIconCache[$ext])) {
            // 매핑값 파싱: 'name' 또는 'name|label' 형태
            //   '|label' 서픽스가 있으면 라벨 CSS 오버레이 강제 활성화
            $rawValue = (string)$_userIconCache[$ext];
            $hasLabelSuffix = substr($rawValue, -6) === '|label';
            $rawName = $hasLabelSuffix ? substr($rawValue, 0, -6) : $rawValue;
            
            $iconName = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawName);
            if ($iconName !== '') {
                $customPath = dirname(__DIR__) . '/assets/file-icons/custom/' . $iconName . '.svg';
                $builtinPath = dirname(__DIR__) . '/assets/file-icons/' . $iconName . '.svg';
                
                // ★ 라벨형 아이콘: 
                //   - 이름이 'archive_'로 시작하거나 (기존 업로드 라벨형 규칙)
                //   - 매핑값에 '|label' 서픽스가 있으면 (갤러리 아이콘 + 라벨 체크 조합)
                //   → CSS 오버레이 표시
                $isLabelMode = $hasLabelSuffix || strpos($iconName, 'archive_') === 0;
                
                if (file_exists($customPath)) {
                    if ($isLabelMode) {
                        return '<span class="fs-archive-icon">'
                             . '<img src="' . htmlspecialchars(IconUrl::get($iconName, true)) . '" alt="' . htmlspecialchars($ext) . '" class="fs-file-icon-img" draggable="false" loading="lazy">'
                             . '<span class="archive-ext">' . strtoupper(htmlspecialchars($ext)) . '</span>'
                             . '</span>';
                    }
                    return '<img src="' . htmlspecialchars(IconUrl::get($iconName, true)) . '" alt="' . htmlspecialchars($ext) . '" class="fs-file-icon-img" draggable="false" loading="lazy">';
                } elseif (file_exists($builtinPath)) {
                    if ($isLabelMode) {
                        return '<span class="fs-archive-icon">'
                             . '<img src="' . htmlspecialchars(IconUrl::get($iconName, false)) . '" alt="' . htmlspecialchars($ext) . '" class="fs-file-icon-img" draggable="false" loading="lazy">'
                             . '<span class="archive-ext">' . strtoupper(htmlspecialchars($ext)) . '</span>'
                             . '</span>';
                    }
                    return '<img src="' . htmlspecialchars(IconUrl::get($iconName, false)) . '" alt="' . htmlspecialchars($ext) . '" class="fs-file-icon-img" draggable="false" loading="lazy">';
                }
            }
        }
        
        // HWP/HWPX
        if ($ext === 'hwp' || $ext === 'hwpx') {
            return SVG_ICONS['hwp'] ?? '';
        }
        
        // TEXT (파란 카드 + 가로 라인 3개) - 파일 기반 (text.svg)
        if ($ext === 'txt' || $ext === 'log') {
            return '<img src="' . htmlspecialchars(IconUrl::get('text', false)) . '" alt="text" class="fs-file-icon-img" draggable="false" loading="lazy">';
        }
        
        // 압축 파일: 공용 archive.svg + 확장자 라벨 오버레이 (11개)
        // 진짜 압축: zip, 7z, rar, tar, gz, bz2, xz, tgz, tbz2, 001
        // 디스크 이미지: iso
        $archiveMap = [
            'zip' => 'ZIP', '7z' => '7Z', 'rar' => 'RAR',
            'tar' => 'TAR', 'gz' => 'GZ', 'bz2' => 'BZ2', 'xz' => 'XZ',
            'tgz' => 'TGZ', 'tbz2' => 'TBZ', '001' => '001',
            'iso' => 'ISO'
        ];
        if (isset($archiveMap[$ext])) {
            return '<span class="fs-archive-icon">'
                 . '<img src="' . htmlspecialchars(IconUrl::get('archive', false)) . '" alt="archive" class="fs-file-icon-img" draggable="false" loading="lazy">'
                 . '<span class="archive-ext">' . $archiveMap[$ext] . '</span>'
                 . '</span>';
        }
        
        // 자막 파일: 공용 subtitle.svg + 확장자 라벨 오버레이 (6개)
        // 남색 문서 + 노란 "자막" 글자 + 노란 확장자 라벨
        $subtitleMap = [
            'srt' => 'SRT', 'smi' => 'SMI',
            'ass' => 'ASS', 'ssa' => 'SSA',
            'vtt' => 'VTT', 'sub' => 'SUB'
        ];
        if (isset($subtitleMap[$ext])) {
            return '<span class="fs-subtitle-icon">'
                 . '<img src="' . htmlspecialchars(IconUrl::get('subtitle', false)) . '" alt="subtitle" class="fs-file-icon-img" draggable="false" loading="lazy">'
                 . '<span class="subtitle-ext">' . $subtitleMap[$ext] . '</span>'
                 . '</span>';
        }
        
        // 확장자 → 아이콘 파일명 매핑 (vscode-icons)
        $iconMap = [
            // 문서
            'pdf' => 'pdf',
            'doc' => 'word', 'docx' => 'word', 'odt' => 'word', 'rtf' => 'word',
            'xls' => 'excel', 'xlsx' => 'excel', 'ods' => 'excel', 'csv' => 'excel',
            'ppt' => 'powerpoint', 'pptx' => 'powerpoint', 'odp' => 'powerpoint',
            'md' => 'markdown', 'markdown' => 'markdown',
            // 코드
            'html' => 'html', 'htm' => 'html',
            'css' => 'css', 'scss' => 'css', 'sass' => 'css', 'less' => 'css',
            'js' => 'js', 'jsx' => 'js', 'mjs' => 'js', 'cjs' => 'js',
            // 주의: ts는 video (TypeScript 아닌 MPEG-TS/동영상)
            'tsx' => 'js',
            'json' => 'json', 'json5' => 'json',
            'xml' => 'xml', 'xsd' => 'xml', 'xsl' => 'xml',
            'php' => 'php', 'phtml' => 'php',
            'py' => 'python', 'pyw' => 'python',
            'java' => 'java', 'class' => 'java', 'jar' => 'java',
            'c' => 'c', 'h' => 'c',
            'cpp' => 'cpp', 'cc' => 'cpp', 'cxx' => 'cpp', 'hpp' => 'cpp', 'hxx' => 'cpp',
            'rb' => 'ruby',
            'go' => 'go',
            'rs' => 'rust',
            'swift' => 'swift',
            'yaml' => 'yaml', 'yml' => 'yaml',
            'toml' => 'toml',
            'ini' => 'ini', 'conf' => 'ini', 'cfg' => 'ini', 'env' => 'ini',
            'sql' => 'sql', 'db' => 'sql', 'sqlite' => 'sql', 'sqlite3' => 'sql',
            'sh' => 'shell', 'bash' => 'shell', 'zsh' => 'shell', 'fish' => 'shell',
            'ps1' => 'powershell', 'psm1' => 'powershell', 'psd1' => 'powershell',
            // Windows 배치 스크립트
            'bat' => 'bat', 'cmd' => 'bat',
            // 토렌트
            'torrent' => 'torrent',
            // 이미지
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
            'bmp' => 'image', 'svg' => 'image', 'ico' => 'image', 'tiff' => 'image', 'tif' => 'image',
            'raw' => 'image', 'psd' => 'image', 'ai' => 'image', 'eps' => 'image', 'heic' => 'image', 'heif' => 'image',
            // 동영상 (TS는 MPEG-TS 동영상)
            'mp4' => 'video', 'mkv' => 'video', 'avi' => 'video', 'mov' => 'video', 'wmv' => 'video',
            'flv' => 'video', 'webm' => 'video', 'ts' => 'video', 'm2ts' => 'video', 'mts' => 'video',
            'vob' => 'video', 'rmvb' => 'video', 'rm' => 'video', '3gp' => 'video', '3g2' => 'video',
            'mpg' => 'video', 'mpeg' => 'video', 'm4v' => 'video', 'f4v' => 'video', 'asf' => 'video',
            // 음악: iconMap에서 제외 — EMOJI_ICONS 폴백으로 🎵 (펜닐 선호)
            // 폰트
            'ttf' => 'font', 'otf' => 'font', 'woff' => 'font', 'woff2' => 'font', 'eot' => 'font'
        ];
        
        if (isset($iconMap[$ext])) {
            return '<img src="' . htmlspecialchars(IconUrl::get($iconMap[$ext], false)) . '" alt="' . htmlspecialchars($ext) . '" class="fs-file-icon-img" draggable="false" loading="lazy">';
        }
        
        // 실행/설치 파일 등은 기존 이모지 유지 (msi, exe, deb, rpm, apk, dmg 등)
        if (defined('EMOJI_ICONS') && isset(EMOJI_ICONS[$ext])) {
            return EMOJI_ICONS[$ext];
        }
        
        // 기본: assets/file-icons/default.svg 파일 사용 (파일 기반으로 통일 - 갤러리와 동일)
        //   예전엔 SVG_ICONS['default'] 인라인 SVG였으나 갤러리/관리UI와 일관성 위해 파일로 통일
        return '<img src="' . htmlspecialchars(IconUrl::get('default', false)) . '" alt="file" class="fs-file-icon-img" draggable="false" loading="lazy">';
    }
    
    /**
     * IconManager 전용 — FileStation이 기본으로 인지하는 모든 확장자 및 매핑 반환
     * 관리자 UI에서 "윈도우 탐색기처럼 전체 확장자 리스트" 표시용
     * 
     * @return array [
     *   'code_map' => ['pdf'=>'pdf', 'docx'=>'word', ...],  // iconMap 하드코딩분
     *   'archive_exts' => ['zip', '7z', ...],  // 압축 (특수 렌더링)
     *   'subtitle_exts' => ['srt', 'smi', ...],  // 자막 (특수 렌더링)
     *   'special' => ['hwp'=>'hwp', 'hwpx'=>'hwp', 'txt'=>'text', 'log'=>'text'],
     *   'emoji_exts' => ['mp3', 'wav', ...],  // 이모지만 있는 확장자
     * ]
     */
    public function getBuiltinIconMap(): array {
        // 위에 정의된 $iconMap과 동일하게 유지 (하드코딩)
        // 주의: 새 확장자 추가 시 getFileIcon()과 이 메서드 둘 다 업데이트 필요
        $codeMap = [
            'pdf' => 'pdf',
            'doc' => 'word', 'docx' => 'word', 'odt' => 'word', 'rtf' => 'word',
            'xls' => 'excel', 'xlsx' => 'excel', 'ods' => 'excel', 'csv' => 'excel',
            'ppt' => 'powerpoint', 'pptx' => 'powerpoint', 'odp' => 'powerpoint',
            'md' => 'markdown', 'markdown' => 'markdown',
            'html' => 'html', 'htm' => 'html',
            'css' => 'css', 'scss' => 'css', 'sass' => 'css', 'less' => 'css',
            'js' => 'js', 'jsx' => 'js', 'mjs' => 'js', 'cjs' => 'js',
            'tsx' => 'js',
            'json' => 'json', 'json5' => 'json',
            'xml' => 'xml', 'xsd' => 'xml', 'xsl' => 'xml',
            'php' => 'php', 'phtml' => 'php',
            'py' => 'python', 'pyw' => 'python',
            'java' => 'java', 'class' => 'java', 'jar' => 'java',
            'c' => 'c', 'h' => 'c',
            'cpp' => 'cpp', 'cc' => 'cpp', 'cxx' => 'cpp', 'hpp' => 'cpp', 'hxx' => 'cpp',
            'rb' => 'ruby', 'go' => 'go', 'rs' => 'rust', 'swift' => 'swift',
            'yaml' => 'yaml', 'yml' => 'yaml',
            'toml' => 'toml',
            'ini' => 'ini', 'conf' => 'ini', 'cfg' => 'ini', 'env' => 'ini',
            'sql' => 'sql', 'db' => 'sql', 'sqlite' => 'sql', 'sqlite3' => 'sql',
            'sh' => 'shell', 'bash' => 'shell', 'zsh' => 'shell', 'fish' => 'shell',
            'ps1' => 'powershell', 'psm1' => 'powershell', 'psd1' => 'powershell',
            'bat' => 'bat', 'cmd' => 'bat',
            'torrent' => 'torrent',
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
            'bmp' => 'image', 'svg' => 'image', 'ico' => 'image', 'tiff' => 'image', 'tif' => 'image',
            'raw' => 'image', 'psd' => 'image', 'ai' => 'image', 'eps' => 'image', 'heic' => 'image', 'heif' => 'image',
            'mp4' => 'video', 'mkv' => 'video', 'avi' => 'video', 'mov' => 'video', 'wmv' => 'video',
            'flv' => 'video', 'webm' => 'video', 'ts' => 'video', 'm2ts' => 'video', 'mts' => 'video',
            'vob' => 'video', 'rmvb' => 'video', 'rm' => 'video', '3gp' => 'video', '3g2' => 'video',
            'mpg' => 'video', 'mpeg' => 'video', 'm4v' => 'video', 'f4v' => 'video', 'asf' => 'video',
            'ttf' => 'font', 'otf' => 'font', 'woff' => 'font', 'woff2' => 'font', 'eot' => 'font'
        ];
        
        return [
            'code_map' => $codeMap,
            'archive_exts' => ['zip', '7z', 'rar', 'tar', 'gz', 'bz2', 'xz', 'tgz', 'tbz2', '001', 'iso'],
            'subtitle_exts' => ['srt', 'smi', 'ass', 'ssa', 'vtt', 'sub'],
            // text.svg, hwp.svg 파일 모두 존재 → special로 통합
            'special' => ['txt' => 'text', 'log' => 'text', 'hwp' => 'hwp', 'hwpx' => 'hwp'],
            // inline_exts는 이제 비어있음 (HWP/HWPX도 파일로 존재)
            'inline_exts' => [],
            // EMOJI_ICONS는 config.php에 있음 — 주요 확장자 표시용
            'emoji_exts' => defined('EMOJI_ICONS') ? array_keys(EMOJI_ICONS) : [],
            // 실제 이모지 문자 맵 (확장자 → 이모지) - 아이콘 관리 UI 미리보기에 사용
            'emoji_map' => defined('EMOJI_ICONS') ? EMOJI_ICONS : [],
        ];
    }
    
    private function getMimeType(string $path): string {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = [
            // 이미지
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp', 'tiff' => 'image/tiff', 'tif' => 'image/tiff',
            // 비디오
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
            'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
            'm4v' => 'video/mp4', 'wmv' => 'video/x-ms-wmv', 'flv' => 'video/x-flv',
            '3gp' => 'video/3gpp', 'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg',
            'ts' => 'video/mp2t', 'm2ts' => 'video/mp2t', 'mts' => 'video/mp2t',
            // 오디오 (★ v5.8.1c 펜닐님 모바일 FLAC 재생 이슈 — 누락 포맷 추가)
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',          // ★ 추가 — 이전엔 octet-stream으로 응답되어 모바일 재생 안 됨
            'm4a' => 'audio/mp4',            // ★ 추가
            'aac' => 'audio/aac',            // ★ 추가
            'opus' => 'audio/ogg',           // ★ 추가 (Opus는 OGG 컨테이너)
            'wma' => 'audio/x-ms-wma',       // ★ 추가
            'oga' => 'audio/ogg',            // ★ 추가
            'aiff' => 'audio/aiff', 'aif' => 'audio/aiff',  // ★ 추가
            // 문서
            'pdf' => 'application/pdf', 'txt' => 'text/plain', 'html' => 'text/html',
            'json' => 'application/json', 'xml' => 'application/xml',
            'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed'
        ];
        return $mimes[$ext] ?? 'application/octet-stream';
    }
    
    public function getBreadcrumb(string $path): array {
        if (empty($path)) return [];
        
        $parts = explode('/', str_replace('\\', '/', $path));
        $breadcrumb = [];
        $current = '';
        
        foreach ($parts as $part) {
            $current .= ($current ? '/' : '') . $part;
            $breadcrumb[] = ['name' => $part, 'path' => $current];
        }
        
        return $breadcrumb;
    }
    
    private function deleteDirectory(string $dir, string $progressFile = ''): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        // 진행률 추적
        $totalFiles = 0;
        $deletedFiles = 0;
        $deletedSize = 0;
        if ($progressFile) {
            // 전체 파일 수 계산 (디렉토리 제외)
            foreach ($iterator as $file) {
                if (!$file->isDir()) $totalFiles++;
            }
            $iterator->rewind();
        }
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                $fsize = $file->getSize();
                @unlink($file->getPathname());
                $deletedFiles++;
                $deletedSize += $fsize;
                
                // 100파일마다 또는 100MB마다 진행률 기록
                if ($progressFile && ($deletedFiles % 100 === 0 || $deletedSize % (100 * 1024 * 1024) < $fsize)) {
                    @file_put_contents($progressFile, json_encode([
                        'filesDone' => $deletedFiles,
                        'totalFiles' => $totalFiles,
                        'deleted' => $deletedSize,
                        'file' => basename($file->getPathname()),
                        'percent' => $totalFiles > 0 ? round($deletedFiles / $totalFiles * 100) : 0
                    ]));
                }
            }
        }
        @rmdir($dir);
        
        if ($progressFile) {
            @unlink($progressFile);
        }
    }
    
    public $_searchStartTime = 0;
    public $_searchTimeLimit = 60; // 초
    
    private function searchRecursive(string $dir, string $query, string $basePath, array &$results, int $limit): void {
        if (count($results) >= $limit) return;
        if ($this->_searchStartTime && (time() - $this->_searchStartTime) > $this->_searchTimeLimit) return;
        
        try {
            $iterator = new DirectoryIterator($dir);
            foreach ($iterator as $file) {
                if ($file->isDot()) continue;
                if (count($results) >= $limit) return;
                if ($this->_searchStartTime && (time() - $this->_searchStartTime) > $this->_searchTimeLimit) return;
                
                $filename = $file->getFilename();
                if (stripos($filename, $query) !== false) {
                    $fullPath = $file->getPathname();
                    $relativePath = substr($fullPath, strlen($basePath) + 1);
                    $relativePath = str_replace('\\', '/', $relativePath);
                    
                    $results[] = [
                        'name' => $filename,
                        'filepath' => $relativePath,
                        'path' => $relativePath,
                        'is_dir' => $file->isDir(),
                        'size' => $file->isDir() ? 0 : $file->getSize(),
                        'modified' => date('Y-m-d H:i:s', $file->getMTime())
                    ];
                }
                
                if ($file->isDir()) {
                    $this->searchRecursive($file->getPathname(), $query, $basePath, $results, $limit);
                }
            }
        } catch (Exception $e) {
            // 접근 불가 폴더 무시
        }
    }
    
    // 실시간 검색용 public 래퍼
    public function searchRecursivePublic(string $dir, string $query, string $basePath, array &$results, int $limit): void {
        $this->_searchStartTime = time();
        $this->searchRecursive($dir, $query, $basePath, $results, $limit);
    }
    
    // FTP/SFTP 어댑터 기반 원격 검색
    public function searchRemoteRecursive($adapter, string $path, string $query, array &$results, int $limit): void {
        if (count($results) >= $limit) return;
        if ($this->_searchStartTime && (time() - $this->_searchStartTime) > $this->_searchTimeLimit) return;
        
        try {
            $items = $adapter->list($path);
            foreach ($items as $item) {
                if (count($results) >= $limit) return;
                if ($this->_searchStartTime && (time() - $this->_searchStartTime) > $this->_searchTimeLimit) return;
                $name = $item['name'] ?? '';
                if ($name === '' || $name[0] === '.') continue;
                
                $relPath = $item['path'] ?? ltrim($path . '/' . $name, '/');
                $isDir = $item['is_dir'] ?? false;
                
                if (stripos($name, $query) !== false) {
                    $results[] = [
                        'name' => $name,
                        'filepath' => $relPath,
                        'path' => $relPath,
                        'is_dir' => $isDir,
                        'size' => $isDir ? 0 : ($item['size'] ?? 0),
                        'modified' => isset($item['modified']) ? date('Y-m-d H:i:s', $item['modified']) : ''
                    ];
                }
                
                if ($isDir) {
                    $this->searchRemoteRecursive($adapter, $relPath, $query, $results, $limit);
                }
            }
        } catch (Exception $e) {}
    }
    
    public function getDirectorySize(string $dir, bool $excludeHidden = false): int {
        $size = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($excludeHidden && substr($file->getFilename(), 0, 1) === '.') continue;
                $size += $file->getSize();
            }
        } catch (Exception $e) {}
        return $size;
    }
    
    // ===== 파일 상세 정보 (EXIF 포함) =====
    public function getDetailedInfo(int $storageId, string $relativePath): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => __('api_err_no_read_perm', '읽기 권한이 없습니다.')];
        }
        
        // 원격 스토리지인 경우
        if ($this->isRemoteStorage($storageId)) {
            return $this->getDetailedInfoRemote($storageId, $relativePath);
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $fullPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => __('file_not_found')];
        }
        
        $isDir = is_dir($fullPath);
        
        // 경로: 스토리지명 + 상대경로 (시놀로지 방식)
        $storageInfo = $this->storage->getStorageById($storageId);
        $storageName = $storageInfo['name'] ?? __('storage_label');
        
        // 파일이면 상위 폴더 경로, 폴더면 해당 경로
        $folderPath = $isDir ? $relativePath : dirname($relativePath);
        if ($folderPath === '.' || $folderPath === '') {
            $displayPath = '/' . $storageName;
        } else {
            $displayPath = '/' . $storageName . '/' . $folderPath;
        }
        
        $info = [
            'name' => basename($fullPath),
            'path' => $displayPath,
            'is_dir' => $isDir,
            'created' => date('Y-m-d H:i:s', filectime($fullPath)),
            'modified' => date('Y-m-d H:i:s', filemtime($fullPath)),
            'accessed' => date('Y-m-d H:i:s', fileatime($fullPath)),
        ];
        
        if ($isDir) {
            $info['size'] = $this->getDirectorySize($fullPath);
            $info['size_formatted'] = $this->formatSize($info['size']);
            $info['item_count'] = $this->countDirectoryItems($fullPath);
        } else {
            $info['size'] = filesize($fullPath);
            $info['size_formatted'] = $this->formatSize($info['size']);
            $info['extension'] = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $info['mime'] = $this->getMimeType($fullPath);
            
            // 이미지 EXIF 정보
            $imageExts = ['jpg', 'jpeg', 'tiff', 'tif'];
            if (in_array($info['extension'], $imageExts) && function_exists('exif_read_data')) {
                $exif = @exif_read_data($fullPath, 'ANY_TAG', true);
                if ($exif) {
                    $info['exif'] = [];
                    
                    if (isset($exif['COMPUTED']['Width'])) {
                        $info['dimensions'] = $exif['COMPUTED']['Width'] . ' x ' . $exif['COMPUTED']['Height'];
                    }
                    if (isset($exif['IFD0']['Make'])) {
                        $info['exif']['make'] = $exif['IFD0']['Make'];
                    }
                    if (isset($exif['IFD0']['Model'])) {
                        $info['exif']['model'] = $exif['IFD0']['Model'];
                    }
                    if (isset($exif['EXIF']['DateTimeOriginal'])) {
                        $info['exif']['taken'] = $exif['EXIF']['DateTimeOriginal'];
                    }
                    if (isset($exif['EXIF']['ExposureTime'])) {
                        $info['exif']['exposure'] = $exif['EXIF']['ExposureTime'];
                    }
                    if (isset($exif['EXIF']['FNumber'])) {
                        $f = $exif['EXIF']['FNumber'];
                        if (is_string($f) && strpos($f, '/') !== false) {
                            list($n, $d) = explode('/', $f);
                            $info['exif']['aperture'] = 'f/' . round($n / $d, 1);
                        }
                    }
                    if (isset($exif['EXIF']['ISOSpeedRatings'])) {
                        $info['exif']['iso'] = $exif['EXIF']['ISOSpeedRatings'];
                    }
                    if (isset($exif['EXIF']['FocalLength'])) {
                        $fl = $exif['EXIF']['FocalLength'];
                        if (is_string($fl) && strpos($fl, '/') !== false) {
                            list($n, $d) = explode('/', $fl);
                            $info['exif']['focal_length'] = round($n / $d) . 'mm';
                        }
                    }
                    
                    // GPS 정보
                    if (isset($exif['GPS'])) {
                        $gps = $this->parseGPS($exif['GPS']);
                        if ($gps) {
                            $info['exif']['gps'] = $gps;
                        }
                    }
                }
            }
            
            // 이미지 크기 (EXIF 없는 경우)
            if (!isset($info['dimensions'])) {
                $imageExtsAll = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
                if (in_array($info['extension'], $imageExtsAll)) {
                    $size = @getimagesize($fullPath);
                    if ($size) {
                        $info['dimensions'] = $size[0] . ' x ' . $size[1];
                    }
                }
            }
        }
        
        // 공유 정보 조회
        $shares = $this->db->load('shares');
        $relPath = ltrim(str_replace('\\', '/', $relativePath), '/');
        foreach ($shares as $share) {
            $sharePath = ltrim(str_replace('\\', '/', $share['file_path'] ?? ''), '/');
            if ((int)($share['storage_id'] ?? 0) === (int)$storageId && $sharePath === $relPath && !empty($share['is_active'])) {
                $info['shared'] = true;
                $info['share_id'] = $share['id'];
                $info['share_token'] = $share['token'];
                $info['share_type'] = $share['share_type'] ?? 'download';
                $info['share_expire'] = $share['expire_at'] ?? null;
                $info['share_created'] = $share['created_at'] ?? null;
                $info['share_downloads'] = $share['download_count'] ?? 0;
                $info['share_max_downloads'] = $share['max_downloads'] ?? null;
                $info['share_password'] = !empty($share['password']);
                break;
            }
        }
        
        return ['success' => true, 'info' => $info];
    }
    
    // GPS 좌표 파싱
    private function parseGPS(array $gps): ?array {
        if (!isset($gps['GPSLatitude']) || !isset($gps['GPSLongitude'])) {
            return null;
        }
        
        $lat = $this->gpsToDecimal($gps['GPSLatitude'], $gps['GPSLatitudeRef'] ?? 'N');
        $lon = $this->gpsToDecimal($gps['GPSLongitude'], $gps['GPSLongitudeRef'] ?? 'E');
        
        if ($lat === null || $lon === null) return null;
        
        return [
            'latitude' => $lat,
            'longitude' => $lon,
            'formatted' => sprintf('%.6f, %.6f', $lat, $lon)
        ];
    }
    
    private function gpsToDecimal(array $coord, string $ref): ?float {
        if (count($coord) < 3) return null;
        
        $degrees = $this->fractionToFloat($coord[0]);
        $minutes = $this->fractionToFloat($coord[1]);
        $seconds = $this->fractionToFloat($coord[2]);
        
        if ($degrees === null) return null;
        
        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
        
        if ($ref === 'S' || $ref === 'W') {
            $decimal = -$decimal;
        }
        
        return $decimal;
    }
    
    private function fractionToFloat($value): ?float {
        if (is_numeric($value)) return (float)$value;
        if (!is_string($value)) return null;
        
        $parts = explode('/', $value);
        if (count($parts) !== 2) return null;
        
        $num = (float)$parts[0];
        $den = (float)$parts[1];
        
        return $den != 0 ? $num / $den : null;
    }
    
    /**
     * 원격 스토리지 파일/폴더 상세 정보
     */
    private function getDetailedInfoRemote(int $storageId, string $relativePath): array {
        $adapter = $this->getAdapter($storageId);
        if (!$adapter) {
            return ['success' => false, 'error' => $this->getLastAdapterError()];
        }
        
        if (!$adapter->exists($relativePath)) {
            return ['success' => false, 'error' => __('file_not_found')];
        }
        
        $storageInfo = $this->storage->getStorageById($storageId);
        $storageName = $storageInfo['name'] ?? __('storage_label');
        $isDir = $adapter->isDir($relativePath);
        
        $folderPath = $isDir ? $relativePath : dirname($relativePath);
        if ($folderPath === '.' || $folderPath === '') {
            $displayPath = '/' . $storageName;
        } else {
            $displayPath = '/' . $storageName . '/' . $folderPath;
        }
        
        $info = [
            'name' => basename($relativePath) ?: $storageName,
            'path' => $displayPath,
            'is_dir' => $isDir,
            'modified' => date('Y-m-d H:i:s', $adapter->getModified($relativePath)),
            'created' => '',
            'accessed' => '',
        ];
        
        if ($isDir) {
            // 원격 폴더 크기 계산은 비용이 크므로 생략
            $info['size'] = 0;
            $info['size_formatted'] = '-';
            // 항목 수는 list로 간단 조회
            try {
                $items = $adapter->list($relativePath);
                $fc = 0; $dc = 0;
                foreach ($items as $i) {
                    if ($i['is_dir']) $dc++; else $fc++;
                }
                $info['item_count'] = ['folders' => $dc, 'files' => $fc];
            } catch (\Throwable $e) {
                $info['item_count'] = ['folders' => 0, 'files' => 0];
            }
        } else {
            $info['size'] = $adapter->getSize($relativePath);
            $info['size_formatted'] = $this->formatSize($info['size']);
            $info['extension'] = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            $info['mime'] = $adapter->getMime($relativePath);
        }
        
        return ['success' => true, 'info' => $info];
    }
    
    // 폴더 내 항목 수
    private function countDirectoryItems(string $dir): array {
        $files = 0;
        $folders = 0;
        
        try {
            $iterator = new DirectoryIterator($dir);
            foreach ($iterator as $item) {
                if ($item->isDot()) continue;
                if ($item->isDir()) $folders++;
                else $files++;
            }
        } catch (Exception $e) {}
        
        return ['files' => $files, 'folders' => $folders];
    }
    
    // 재귀적 전체 파일 수 카운트
    public function countAllFiles(string $dir): int {
        $count = 0;
        try {
            $ri = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($ri as $file) {
                if ($file->isFile()) $count++;
            }
        } catch (\Exception $e) {}
        return $count;
    }
    
    // ===== 드래그앤드롭 이동/복사 =====
    public function dragDrop(int $storageId, array $sources, string $destPath, string $action = 'move'): array {
        $permission = $action === 'copy' ? 'can_write' : 'can_delete';
        
        if (!$this->storage->checkPermission($storageId, $permission)) {
            return ['success' => false, 'error' => $action === 'copy' ? __('api_err_no_write_perm', '쓰기 권한이 없습니다.') : __('api_err_no_delete_perm', '삭제 권한이 없습니다.')];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $destFullPath = $this->buildPath($basePath, $destPath);
        
        if (!$this->isPathSafe($basePath, $destFullPath)) {
            return ['success' => false, 'error' => __('invalid_dst_path')];
        }
        
        if (!is_dir($destFullPath)) {
            return ['success' => false, 'error' => __('api_err_target_folder_missing', '대상 폴더가 없습니다.')];
        }
        
        $results = [];
        $errors = [];
        
        foreach ($sources as $source) {
            $sourceFullPath = $this->buildPath($basePath, $source);
            
            if (!$this->isPathSafe($basePath, $sourceFullPath)) {
                $errors[] = __f('invalid_path_for', ['source' => $source]);
                continue;
            }
            
            if (!file_exists($sourceFullPath)) {
                $errors[] = __f('file_missing_for', ['source' => $source]);
                continue;
            }
            
            $filename = basename($source);
            $targetPath = $destFullPath . DIRECTORY_SEPARATOR . $filename;
            
            // 자기 자신으로 이동 방지
            $realSrc = realpath($sourceFullPath);
            $realDst = realpath($targetPath);
            if ($realSrc !== false && $realDst !== false && $realSrc === $realDst) {
                $errors[] = __f('same_location_for', ['source' => $source]);
                continue;
            }
            
            // 하위 폴더로 이동 방지
            if (is_dir($sourceFullPath)) {
                $realDest = realpath($destFullPath);
                $realSrc = realpath($sourceFullPath);
                if ($realDest && $realSrc && \isSubPath($realDest, $realSrc)) {
                    $errors[] = __f('subfolder_move_for', ['source' => $source]);
                    continue;
                }
            }
            
            // 중복 처리
            $targetPath = $this->getUniqueFilename($targetPath);
            
            try {
                if ($action === 'copy') {
                    if (is_dir($sourceFullPath)) {
                        $this->copyDirectory($sourceFullPath, $targetPath);
                    } else {
                        copy($sourceFullPath, $targetPath);
                    }
                } else {
                    rename($sourceFullPath, $targetPath);
                }
                
                $results[] = [
                    'source' => $source,
                    'dest' => $destPath . '/' . basename($targetPath)
                ];
            } catch (Exception $e) {
                $errors[] = "{$source}: " . $e->getMessage();
            }
        }
        
        return [
            'success' => count($errors) === 0,
            'results' => $results,
            'errors' => $errors
        ];
    }
    
    // ===== 파일 정렬 =====
    public function sortFiles(array $items, string $sortBy = 'name', string $order = 'asc'): array {
        usort($items, function($a, $b) use ($sortBy, $order) {
            // Windows 탐색기 방식: 오름차순→폴더 위, 내림차순→폴더 아래
            if ($a['is_dir'] !== $b['is_dir']) {
                if ($order === 'asc') {
                    return $b['is_dir'] - $a['is_dir']; // 폴더 위
                } else {
                    return $a['is_dir'] - $b['is_dir']; // 폴더 아래
                }
            }
            
            $result = 0;
            switch ($sortBy) {
                case 'name':
                    $result = strcasecmp($a['name'], $b['name']);
                    break;
                case 'size':
                    $result = ($a['size'] ?? 0) - ($b['size'] ?? 0);
                    break;
                case 'date':
                    $result = strtotime($a['modified'] ?? '0') - strtotime($b['modified'] ?? '0');
                    break;
                case 'type':
                    $result = strcasecmp($a['extension'] ?? '', $b['extension'] ?? '');
                    if ($result === 0) {
                        $result = strcasecmp($a['name'], $b['name']);
                    }
                    break;
            }
            
            return $order === 'desc' ? -$result : $result;
        });
        
        return $items;
    }
    
    // 조건부 파일 검색 (패턴 매칭)
    public function bulkSearch(int $storageId, string $basePath, array $patterns, string $scope = 'recursive', string $type = 'all'): array {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            return ['success' => false, 'error' => __('api_err_no_read_perm', '읽기 권한이 없습니다.')];
        }
        
        // 검색 인덱스 우선 사용 (원격 스토리지도 인덱스 있으면 검색 가능)
        $fileIndex = FileIndex::getInstance();
        if ($fileIndex->isAvailable()) {
            $results = $fileIndex->searchByPatterns($storageId, $basePath, $patterns, $scope, $type, 1000);
            
            return [
                'success' => true,
                'items' => $results,
                'count' => count($results),
                'method' => 'index'
            ];
        }
        
        $storagePath = $this->storage->getRealPath($storageId);
        if (!$storagePath) {
            return ['success' => false, 'error' => __('remote_no_index', '원격 스토리지는 인덱스가 필요합니다. 인덱스를 먼저 구축하세요.')];
        }
        
        // 인덱스 없으면 파일 시스템 직접 검색 (fallback)
        $searchPath = empty($basePath) ? $storagePath : $this->buildPath($storagePath, $basePath);
        
        if (!$this->isPathSafe($storagePath, $searchPath)) {
            return ['success' => false, 'error' => __('api_err_invalid_path', '잘못된 경로입니다.')];
        }
        
        $results = [];
        $this->searchByPatterns($searchPath, $storagePath, $patterns, $scope === 'recursive', $type, $results, 1000);
        
        return [
            'success' => true,
            'items' => $results,
            'count' => count($results),
            'method' => 'filesystem'
        ];
    }
    
    // 패턴 매칭 검색 (재귀)
    private function searchByPatterns(string $dir, string $basePath, array $patterns, bool $recursive, string $type, array &$results, int $limit): void {
        if (count($results) >= $limit) return;
        if (!is_dir($dir)) return;
        
        $items = @scandir($dir);
        if ($items === false) return;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (count($results) >= $limit) return;
            
            $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
            $isDir = is_dir($fullPath);
            
            // 타입 필터
            if ($type === 'file' && $isDir) {
                // 폴더인데 파일만 검색 - 하위는 계속 탐색
                if ($recursive) {
                    $this->searchByPatterns($fullPath, $basePath, $patterns, $recursive, $type, $results, $limit);
                }
                continue;
            }
            if ($type === 'folder' && !$isDir) {
                continue;
            }
            
            // 패턴 매칭
            $matched = false;
            foreach ($patterns as $pattern) {
                $pattern = trim($pattern);
                if (empty($pattern)) continue;
                
                if ($this->matchPattern($item, $pattern)) {
                    $matched = true;
                    break;
                }
            }
            
            if ($matched) {
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $fullPath);
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
                
                $results[] = [
                    'name' => $item,
                    'path' => $relativePath,
                    'is_dir' => $isDir,
                    'size' => $isDir ? 0 : @filesize($fullPath),
                    'modified' => date('Y-m-d H:i:s', @filemtime($fullPath))
                ];
            }
            
            // 재귀 탐색 (폴더가 패턴에 매칭되어도 내부 탐색)
            if ($recursive && $isDir) {
                $this->searchByPatterns($fullPath, $basePath, $patterns, $recursive, $type, $results, $limit);
            }
        }
    }
    
    // 와일드카드 패턴 매칭
    private function matchPattern(string $name, string $pattern): bool {
        // 정확히 일치
        if (strcasecmp($name, $pattern) === 0) return true;
        
        // 와일드카드 패턴 (*, ?)
        if (strpos($pattern, '*') !== false || strpos($pattern, '?') !== false) {
            // 순서 중요: 먼저 .을 이스케이프, 그 다음 *와 ? 변환
            $regex = preg_quote($pattern, '/');
            $regex = str_replace(['\*', '\?'], ['.*', '.'], $regex);
            return preg_match('/^' . $regex . '$/i', $name) === 1;
        }
        
        // 부분 일치 (와일드카드 없을 때)
        return stripos($name, $pattern) !== false;
    }
    
    // 조건부 일괄 삭제
    public function bulkDelete(int $storageId, array $paths): array {
        if (!$this->storage->checkPermission($storageId, 'can_delete')) {
            return ['success' => false, 'error' => __('api_err_no_delete_perm', '삭제 권한이 없습니다.')];
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) {
            return ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
        }
        
        $deleted = 0;
        $failed = 0;
        
        foreach ($paths as $relativePath) {
            $fullPath = $this->buildPath($basePath, $relativePath);
            
            if (!$this->isPathSafe($basePath, $fullPath)) {
                $failed++;
                continue;
            }
            
            if (!file_exists($fullPath)) {
                $failed++;
                continue;
            }
            
            // 휴지통으로 이동
            $result = $this->moveToTrash($storageId, $relativePath, $fullPath);
            
            if ($result['success']) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        
        return [
            'success' => true,
            'deleted' => $deleted,
            'failed' => $failed
        ];
    }
    
    /**
     * 특정 경로를 인덱스에 추가 (복원 시 사용)
     */
    private function reindexPath(int $storageId, string $basePath, string $fullPath): void {
        $relativePath = substr($fullPath, strlen($basePath) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);
        
        if (is_dir($fullPath)) {
            // 폴더인 경우 재귀적으로 인덱싱
            $this->fileIndex->addFile($storageId, $relativePath, [
                'is_dir' => 1,
                'size' => 0,
                'modified' => date('Y-m-d H:i:s', filemtime($fullPath))
            ]);
            
            $iterator = new DirectoryIterator($fullPath);
            foreach ($iterator as $file) {
                if ($file->isDot()) continue;
                $this->reindexPath($storageId, $basePath, $file->getPathname());
            }
        } else {
            // 파일인 경우
            $this->fileIndex->addFile($storageId, $relativePath, [
                'is_dir' => 0,
                'size' => filesize($fullPath),
                'modified' => date('Y-m-d H:i:s', filemtime($fullPath))
            ]);
        }
    }
    
    /**
     * 공유 링크용 트랜스코딩 스트리밍 (ffmpeg → fragmented MP4)
     */
    public function transcodeShareStream(string $fullPath): void {
        $ffmpeg = $this->findFfmpeg();
        if (!$ffmpeg) {
            http_response_code(500);
            header('Content-Type: application/json');
            $disArr = array_map('trim', explode(',', @ini_get('disable_functions') ?: ''));
            $execBlocked = !function_exists('exec') || in_array('exec', $disArr);
            $hint = $execBlocked ? ' (exec function is disabled in PHP)' : ' (check ffmpeg path in admin settings)';
            header('X-Transcode-Error: ffmpeg not available' . $hint);
            echo json_encode(['error' => 'ffmpeg not available' . $hint]);
            exit;
        }
        
        // proc_open 사용 가능 여부 체크 (트랜스코딩 필수)
        $disArr2 = array_map('trim', explode(',', @ini_get('disable_functions') ?: ''));
        if (!function_exists('proc_open') || in_array('proc_open', $disArr2)) {
            http_response_code(500);
            header('Content-Type: application/json');
            header('X-Transcode-Error: proc_open disabled');
            echo json_encode(['error' => 'proc_open function is disabled in PHP. Required for video streaming.']);
            exit;
        }
        
        if (!is_file($fullPath)) {
            http_response_code(404);
            header('Content-Type: application/json');
            header('X-Transcode-Error: file-not-found');
            echo json_encode(['error' => 'File not found', 'path' => basename($fullPath)]);
            exit;
        }
        
        set_time_limit(0);
        while (ob_get_level()) ob_end_clean();
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
        
        $inputPath = $this->escapeShellPath($fullPath);
        
        // Windows 한국어 파일명 → 짧은 경로로 변환
        if (PHP_OS_FAMILY === 'Windows') {
            $shortPath = $this->getWindowsShortPath($fullPath);
            if ($shortPath) $inputPath = $this->escapeShellPath($shortPath);
        }
        
        // 오디오/비디오 트랙 정보 조회
        if (isset($_GET['info'])) {
            $probeCmd = escapeshellarg($ffmpeg) . ' -i ' . $inputPath . ' 2>&1';
            $output = shell_exec($probeCmd);
            
            $audioTracks = [];
            if (preg_match_all('/Stream\s+#(\d+):(\d+)(?:\(([a-z]+)\))?\:\s*Audio:\s*(\w+)[^,]*,\s*(\d+)\s*Hz[^,]*,\s*([^,\r\n]+)/i', $output, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $title = '';
                    if (preg_match('/Stream\s+#' . preg_quote($m[1] . ':' . $m[2]) . '.*\n\s*Metadata:\s*\n\s*title\s*:\s*(.+)/i', $output, $tm)) {
                        $title = trim($tm[1]);
                    }
                    $audioTracks[] = [
                        'index' => (int)$m[2],
                        'language' => $m[3] ?? '',
                        'codec' => strtoupper($m[4]),
                        'channels' => trim($m[6]),
                        'title' => $title,
                    ];
                }
            }
            
            $duration = 0;
            if (preg_match('/Duration:\s*(\d+):(\d+):(\d+)\.(\d+)/', $output, $dm)) {
                $duration = (int)$dm[1] * 3600 + (int)$dm[2] * 60 + (int)$dm[3] + (int)$dm[4] / 100;
            }
            
            // 비디오 코덱 정보 추출
            $videoCodec = '';
            $videoResolution = '';
            if (preg_match('/Stream\s+#\d+:\d+.*Video:\s*(\w+)/i', $output, $vm)) {
                $videoCodec = strtolower($vm[1]);
            }
            if (preg_match('/(\d{2,5})x(\d{2,5})/', $output, $rm)) {
                $videoResolution = $rm[1] . 'x' . $rm[2];
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'audio_tracks' => $audioTracks,
                'encoder' => $this->getHwEncoderInfo(),
                'duration' => $duration,
                'video_codec' => $videoCodec,
                'video_resolution' => $videoResolution,
            ]);
            exit;
        }
        
        // 오디오 트랙 선택
        if (isset($_GET['audio'])) {
            $audioIdx = (int)$_GET['audio'];
            $audioMap = ' -map 0:v:0 -map 0:' . $audioIdx;
        } else {
            $audioMap = ' -map 0:v:0 -map 0:a:0';
        }
        
        // iOS 감지
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isIOS = (bool)preg_match('/iPhone|iPad|iPod/i', $ua);
        
        // HW 인코더 감지 + iOS 호환 프로필
        $videoCodecArgs = $this->detectHwEncoder($ffmpeg);
        $gopArgs = ' -g 48 -keyint_min 48';
        $fpsArg = '';
        
        if ($isIOS) {
            // iOS: 반드시 H.264 High Profile 4.1
            if (strpos($videoCodecArgs, 'libx264') === false) {
                // HW 인코더 사용
                $gopArgs = ' -g 24 -keyint_min 24';
            } else {
                $videoCodecArgs = '-c:v libx264 -preset veryfast -crf 23 -profile:v high -level 4.1 -pix_fmt yuv420p';
                $gopArgs = ' -g 24 -keyint_min 24';
            }
        }
        
        // HW 인코더 시 fps 제한
        if (strpos($videoCodecArgs, 'libx264') === false && strpos($videoCodecArgs, 'copy') === false) {
            $fpsArg = ' -r 30';
        }
        
        // stderr 로그
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $stderrLog = $dataDir . '/ffmpeg_share_stderr.log';
        
        $buildCmd = function($vCodec, $gop, $fps) use ($ffmpeg, $inputPath, $audioMap) {
            return escapeshellarg($ffmpeg)
                . ' -analyzeduration 2000000 -probesize 2000000'
                . ' -fflags +genpts+igndts+fastseek'
                . ' -i ' . $inputPath
                . $audioMap
                . ' -sn'
                . ' ' . $vCodec
                . $gop
                . $fps
                . ' -c:a aac -b:a 128k -ac 2'
                . ' -output_ts_offset 0'
                . ' -movflags frag_keyframe+empty_moov+default_base_moof'
                . ' -frag_duration 500000'
                . ' -f mp4 pipe:1';
        };
        
        $cmd = $buildCmd($videoCodecArgs, $gopArgs, $fpsArg);
        
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $stderrLog, 'w'],
        ];
        
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            http_response_code(500);
            echo 'Failed to start ffmpeg';
            exit;
        }
        
        fclose($pipes[0]);
        
        // 보안 헤더 제거 (스트리밍 응답에 불필요)
        header_remove('Content-Security-Policy');
        header_remove('X-Content-Type-Options');
        header('Content-Type: video/mp4');
        header('Cache-Control: no-cache, no-store');
        header('Accept-Ranges: none');
        header('X-Accel-Buffering: no');
        
        // 첫 데이터 읽기 (HW 인코더 실패 감지)
        $firstChunk = $this->_readFirstChunkWithTimeout($pipes[1], 6);
        
        if ($firstChunk === false || $firstChunk === '') {
            // HW 실패 → SW fallback
            fclose($pipes[1]);
            proc_close($process);
            
            $swCodec = '-c:v libx264 -preset veryfast -crf 23 -profile:v high -level 4.1 -pix_fmt yuv420p';
            $cmd = $buildCmd($swCodec, ' -g 48 -keyint_min 48', '');
            $process = proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($process)) {
                http_response_code(500);
                echo 'Failed to start ffmpeg (SW fallback)';
                exit;
            }
            fclose($pipes[0]);
            $firstChunk = $this->_readFirstChunkWithTimeout($pipes[1], 6);
        }
        
        if ($firstChunk !== false && $firstChunk !== '') {
            echo $firstChunk;
            if (ob_get_level()) ob_flush();
            flush();
        }
        
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') {
                echo $chunk;
                if (ob_get_level()) ob_flush();
                flush();
            }
        }
        
        fclose($pipes[1]);
        proc_close($process);
        exit;
    }

    /**
     * 공유 스트리밍 HLS 모드
     * share.php에서 토큰 인증 후 호출됨 (로그인 불필요)
     * hls_action: start(세션생성), playlist(m3u8서빙), segment(ts서빙), stop(정리)
     */
    public function hlsShareStream(string $fullPath): void {
        $action = $_GET['hls_action'] ?? 'start';
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        $hlsDir = $dataDir . '/hls_sessions';
        
        // === playlist/segment 서빙 ===
        if ($action === 'playlist' || $action === 'segment') {
            $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['session'] ?? '');
            if (!$sessionId) { http_response_code(400); exit; }
            
            $sessionDir = $hlsDir . '/' . $sessionId;
            if (!is_dir($sessionDir)) { http_response_code(404); exit; }
            
            if ($action === 'playlist') {
                $m3u8 = $sessionDir . '/stream.m3u8';
                
                // ★ 빠른 실패 감지 + m3u8 생성 대기 (최대 5초, api.php 경로와 동일)
                //   A) m3u8 생성됨 → 성공
                //   B) ffmpeg 프로세스 죽음 (pid.txt 기반) → 실패 확정
                //   C) ffmpeg.log에 치명적 실패 키워드 → 실패 확정
                $pidFile = $sessionDir . '/pid.txt';
                $ffLog = $sessionDir . '/ffmpeg.log';
                $fatalLogPatterns = [
                    '/Conversion failed!/i',
                    '/Could not open encoder before EOF/i',
                    '/Nothing was written into output file/i',
                    '/Invalid argument\b.*Terminating thread/i',
                ];
                $waitStart = microtime(true);
                $maxWait = 5.0;
                $ffmpegDied = false;
                $logFatalDetected = false;
                $waited = 0;
                $pollIter = 0;
                while (!file_exists($m3u8) && $waited < $maxWait) {
                    usleep(100000);
                    $waited = microtime(true) - $waitStart;
                    $pollIter++;
                    
                    // A) 프로세스 생존 체크 (300ms 주기)
                    if ($waited > 0.3 && file_exists($pidFile) && ($pollIter % 3 === 0)) {
                        $pid = (int)@file_get_contents($pidFile);
                        if ($pid > 0) {
                            if (PHP_OS_FAMILY === 'Windows') {
                                $check = @shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
                                if (!$check || strpos($check, (string)$pid) === false) {
                                    $ffmpegDied = true;
                                    break;
                                }
                            } else {
                                if (!file_exists('/proc/' . $pid)) {
                                    $ffmpegDied = true;
                                    break;
                                }
                            }
                        }
                    }
                    
                    // B) 치명적 로그 키워드 감지 (300ms 주기)
                    if ($waited > 0.3 && file_exists($ffLog) && ($pollIter % 3 === 0)) {
                        $logTail = '';
                        $logSize = @filesize($ffLog);
                        if ($logSize > 0) {
                            $fh = @fopen($ffLog, 'r');
                            if ($fh) {
                                $readFrom = max(0, $logSize - 2048);
                                @fseek($fh, $readFrom, SEEK_SET);
                                $logTail = @fread($fh, 2048) ?: '';
                                @fclose($fh);
                            }
                        }
                        if ($logTail !== '') {
                            foreach ($fatalLogPatterns as $pat) {
                                if (preg_match($pat, $logTail)) {
                                    $logFatalDetected = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
                
                if (!file_exists($m3u8)) {
                    // HW 에러 감지 시 libx264로 복구 시도
                    $hwErrorDetected = ($ffmpegDied || $logFatalDetected);
                    if (file_exists($ffLog)) {
                        $logText = file_get_contents($ffLog);
                        // HW 인코더 관련 에러 키워드 추가 체크
                        // ※ 전역 hw_encoder_cache.json은 절대 건드리지 않음
                        //   (공유 세션에서 특정 파일이 실패했다고 서버 전체를 SW로 바꾸면 안 됨)
                        $hwErrorPatterns = [
                            '/MFX session:\s+fail/i',
                            '/No capable devices found/i',
                            '/not supported by the QSV runtime/i',
                            '/\[h264_qsv[^\]]*\].*Error while opening/i',
                            '/\[hevc_qsv[^\]]*\].*Error while opening/i',
                            '/\[h264_nvenc[^\]]*\].*failed/i',
                            '/\[hevc_nvenc[^\]]*\].*failed/i',
                            '/\[h264_amf[^\]]*\].*failed/i',
                        ];
                        foreach ($hwErrorPatterns as $pattern) {
                            if (preg_match($pattern, $logText)) {
                                $hwErrorDetected = true;
                                break;
                            }
                        }
                    }
                    
                    // libx264로 자동 재시도
                    if ($hwErrorDetected) {
                        $metaFile = $sessionDir . '/meta.json';
                        $metaForSw = file_exists($metaFile) ? @json_decode(@file_get_contents($metaFile), true) : null;
                        $swCmd = is_array($metaForSw) ? ($metaForSw['sw_cmd'] ?? null) : null;
                        $swStderrLog = is_array($metaForSw) ? ($metaForSw['stderr_log'] ?? ($sessionDir . '/ffmpeg.log')) : ($sessionDir . '/ffmpeg.log');
                        
                        if ($swCmd) {
                            // 동시 요청 방지 락
                            $swLockFile = $sessionDir . '/sw_fallback.lock';
                            $lockFp = @fopen($swLockFile, 'c');
                            if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
                                // 락 획득 → 재실행 주도
                            } else {
                                // 다른 요청이 이미 재실행 중 → m3u8 대기만
                                if ($lockFp) @fclose($lockFp);
                                $waitOtherStart = microtime(true);
                                while (!file_exists($m3u8) && (microtime(true) - $waitOtherStart) < 8) {
                                    usleep(100000);
                                }
                                if (!file_exists($m3u8)) {
                                    http_response_code(404); exit;
                                }
                                // 이어서 m3u8 서빙하도록 아래로 진행
                                goto share_sw_done;
                            }
                            
                            // 이전 실패 흔적 정리
                            foreach (glob($sessionDir . '/stream*.ts') as $oldSeg) { @unlink($oldSeg); }
                            @file_put_contents($swStderrLog, "\n=== HW encoder failed, retrying with libx264 ===\n", FILE_APPEND);
                            
                            // 기존 pid.txt 삭제 (죽은 HW PID 제거)
                            $pidFileForSwShare = $sessionDir . '/pid.txt';
                            @unlink($pidFileForSwShare);
                            
                            // libx264 백그라운드 재실행
                            if (PHP_OS_FAMILY === 'Windows') {
                                $bgCmd = 'start "" /B ' . $swCmd . ' 2>>' . escapeshellarg($swStderrLog);
                                @pclose(@popen($bgCmd, 'r'));
                            } else {
                                $bgCmd = $swCmd . ' >> ' . escapeshellarg($swStderrLog) . ' 2>&1 & echo $! > ' . escapeshellarg($pidFileForSwShare);
                                $wrapper = 'nohup sh -c ' . escapeshellarg($bgCmd) . ' > /dev/null 2>&1 &';
                                if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', @ini_get('disable_functions') ?: '')))) {
                                    @exec($wrapper);
                                } else {
                                    $ph = @popen($wrapper, 'r');
                                    if (is_resource($ph)) @pclose($ph);
                                }
                            }
                            
                            // m3u8 생성 대기 (최대 8초)
                            $retryWaitStart = microtime(true);
                            $retryWaited = 0;
                            while (!file_exists($m3u8) && $retryWaited < 8) {
                                usleep(100000);
                                $retryWaited = microtime(true) - $retryWaitStart;
                            }
                            
                            if (file_exists($m3u8)) {
                                // Windows: 새 libx264 PID 찾아서 pid.txt 갱신
                                if (PHP_OS_FAMILY === 'Windows') {
                                    $wmic = @shell_exec('wmic process where "name=\'ffmpeg.exe\'" get processid,commandline /format:csv 2>NUL');
                                    if ($wmic) {
                                        foreach (explode("\n", $wmic) as $line) {
                                            if (strpos($line, $sessionId) !== false && preg_match('/,(\d+)\s*$/', trim($line), $pm)) {
                                                @file_put_contents($pidFileForSwShare, $pm[1]);
                                                break;
                                            }
                                        }
                                    }
                                }
                                
                                // meta.json의 current_encoder 갱신
                                $metaData = @json_decode(@file_get_contents($metaFile), true) ?: [];
                                $metaData['current_encoder'] = 'libx264';
                                $metaData['sw_fallback_applied'] = true;
                                @file_put_contents($metaFile, json_encode($metaData));
                                
                                // 락 해제
                                if (isset($lockFp) && $lockFp) {
                                    @flock($lockFp, LOCK_UN);
                                    @fclose($lockFp);
                                }
                                // 성공 — 아래 정상 흐름으로 진행
                            } else {
                                if (isset($lockFp) && $lockFp) {
                                    @flock($lockFp, LOCK_UN);
                                    @fclose($lockFp);
                                }
                                http_response_code(404); exit;
                            }
                        } else {
                            http_response_code(404); exit;
                        }
                    } else {
                        http_response_code(404); exit;
                    }
                }
                share_sw_done:
                
                @touch($sessionDir);
                
                $content = file_get_contents($m3u8);
                // 세그먼트 URL 치환 — share.php 경로로
                $token = $_GET['t'] ?? '';
                $password = $_GET['password'] ?? '';
                $baseUrl = 'share.php?t=' . urlencode($token) . '&download=1&stream=1&hls=1&hls_action=segment&session=' . $sessionId;
                if ($password) $baseUrl .= '&password=' . urlencode($password);
                $content = preg_replace('/^(stream\d+\.ts)$/m', $baseUrl . '&file=$1', $content);
                
                header('Content-Type: application/vnd.apple.mpegurl');
                header('Cache-Control: no-cache');
                
                // 현재 인코더 헤더 (배지 업데이트용, 인젝션 방어)
                $currentMetaShare = @json_decode(@file_get_contents($sessionDir . '/meta.json'), true);
                if (is_array($currentMetaShare) && !empty($currentMetaShare['current_encoder'])) {
                    $encSafe = preg_replace('/[^A-Za-z0-9_]/', '', (string)$currentMetaShare['current_encoder']);
                    if ($encSafe !== '') {
                        header('Access-Control-Expose-Headers: X-Current-Encoder, X-Sw-Fallback-Applied');
                        header('X-Current-Encoder: ' . $encSafe);
                    }
                    if (!empty($currentMetaShare['sw_fallback_applied'])) {
                        header('X-Sw-Fallback-Applied: 1');
                    }
                }
                
                echo $content;
                exit;
            }
            
            if ($action === 'segment') {
                $file = basename($_GET['file'] ?? '');
                if (!$file || !preg_match('/^stream\d+\.ts$/', $file)) { http_response_code(400); exit; }
                $segPath = $sessionDir . '/' . $file;
                
                $waited = 0;
                while (!file_exists($segPath) && $waited < 10) {
                    usleep(300000);
                    $waited += 0.3;
                }
                if (!file_exists($segPath)) { http_response_code(404); exit; }
                
                @touch($sessionDir);
                
                header('Content-Type: video/mp2t');
                header('Content-Length: ' . filesize($segPath));
                header('Cache-Control: public, max-age=3600');
                readfile($segPath);
                exit;
            }
        }
        
        // === stop ===
        if ($action === 'stop') {
            $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['session'] ?? $_POST['session'] ?? '');
            $dataDir2 = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
            if ($sessionId) {
                $sessionDir = $hlsDir . '/' . $sessionId;
                if (is_dir($sessionDir)) {
                    $pidFile = $sessionDir . '/pid.txt';
                    if (file_exists($pidFile)) {
                        $pid = (int)file_get_contents($pidFile);
                        if ($pid > 0) {
                            if (PHP_OS_FAMILY === 'Windows') {
                                @exec("taskkill /PID $pid /F /T 2>NUL");
                            } else {
                                @posix_kill($pid, 15);
                            }
                        }
                    }
                }
                // 세션 디렉토리 즉시 삭제
                if (is_dir($sessionDir)) {
                    foreach (new \DirectoryIterator($sessionDir) as $f) {
                        if (!$f->isDot()) @unlink($f->getPathname());
                    }
                    @rmdir($sessionDir);
                }
            }
            $this->hlsCleanupStale();
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        
        // === start: 새 세션 생성 ===
        $ffmpeg = $this->findFfmpeg();
        if (!$ffmpeg) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => 'ffmpeg not available']);
            exit;
        }
        
        if (!is_file($fullPath)) {
            header('Content-Type: application/json');
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            exit;
        }
        
        if (!is_dir($hlsDir)) @mkdir($hlsDir, 0755, true);
        
        // 기존 세션 재사용 (같은 파일)
        // ★ force_sw=1 요청 시 기존 HW 세션 (force_sw 없음) 은 reuse 안 함 (HW→SW fallback 의도)
        //   하지만 같은 force_sw=1 세션은 reuse — 서버 이중 실행 시 두 폴더 생성 방어 (메인과 동일)
        //   force_sw + audio + path + client_session 모두 일치 시 reuse
        $reqForceSwShare = isset($_GET['force_sw']) ? '1' : '0';
        // ★ 요청된 audio 인덱스 (reuse 비교용)
        $reqAudioIdxShare = isset($_GET['audio']) ? (int)$_GET['audio'] : null;
        // ★ client_session — PHP 세션 ID hash로 기기별 분리 (메인과 동일 패턴)
        //   같은 사용자 PC/모바일 동시 시청 시 폴더 공유 방지
        $reqClientSessionShare = session_id() ? substr(hash('sha256', session_id()), 0, 16) : '';
        // ★ quality 비교 — 다른 quality 요청은 별도 세션 (v5.8.1c)
        $reqQualityShare = isset($_GET['quality']) ? trim($_GET['quality']) : 'original';
        if (is_dir($hlsDir)) {
            foreach (new \DirectoryIterator($hlsDir) as $d) {
                if ($d->isDot() || !$d->isDir()) continue;
                $metaFile = $d->getPathname() . '/meta.json';
                if (!file_exists($metaFile)) continue;
                $meta = @json_decode(file_get_contents($metaFile), true);
                $existAudioIdxShare = isset($meta['audio']) ? (int)$meta['audio'] : null;
                $existIsSwShare = !empty($meta['current_encoder']) && strpos($meta['current_encoder'], 'libx264') !== false ? '1' : '0';
                $existClientSessionShare = $meta['client_session'] ?? '';
                $existQualityShare = $meta['quality'] ?? 'original';
                if (($meta['share_path'] ?? '') === $fullPath
                    && $existAudioIdxShare === $reqAudioIdxShare
                    && $existIsSwShare === $reqForceSwShare
                    && $existClientSessionShare === $reqClientSessionShare
                    && $existQualityShare === $reqQualityShare) {
                    $existDir = $d->getPathname();
                    $existSessionId = $d->getFilename();
                    $existCreated = $meta['created'] ?? 0;
                    $isActive = false;
                    if (time() - $existCreated < 60) $isActive = true;
                    if (!$isActive) {
                        $existM3u8 = $existDir . '/stream.m3u8';
                        if (file_exists($existM3u8)) {
                            clearstatcache(true, $existM3u8);
                            $isActive = (time() - filemtime($existM3u8)) < 30;
                        }
                    }
                    if ($isActive) {
                        // reuse 시 meta.json 갱신 + touch (cleanup 보호)
                        // ★ sw_cmd, stderr_log, current_encoder, audio, client_session, quality, seek 기존 값 보존
                        @file_put_contents($metaFile, json_encode([
                            'created' => time(),
                            'share_path' => $fullPath,
                            'audio' => $existAudioIdxShare,
                            'client_session' => $existClientSessionShare,
                            'sw_cmd' => $meta['sw_cmd'] ?? null,
                            'stderr_log' => $meta['stderr_log'] ?? null,
                            'current_encoder' => $meta['current_encoder'] ?? null,
                            'sw_fallback_applied' => $meta['sw_fallback_applied'] ?? false,
                            'quality' => $existQualityShare,
                            'seek' => (float)($meta['seek'] ?? 0),
                        ]));
                        @touch($existDir);
                        
                        $token = $_GET['t'] ?? '';
                        $password = $_GET['password'] ?? '';
                        $playlistUrl = 'share.php?t=' . urlencode($token) . '&download=1&stream=1&hls=1&hls_action=playlist&session=' . $existSessionId;
                        if ($password) $playlistUrl .= '&password=' . urlencode($password);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'session' => $existSessionId, 'playlist' => $playlistUrl, 'reused' => true]);
                        exit;
                    }
                }
            }
        }
        
        $sessionId = bin2hex(random_bytes(8));
        $sessionDir = $hlsDir . '/' . $sessionId;
        @mkdir($sessionDir, 0755, true);
        
        // Windows 한글 경로 → 8.3 짧은 경로
        $ffmpegInput = $fullPath;
        if (PHP_OS_FAMILY === 'Windows') {
            $shortPath = $this->getWindowsShortPath($fullPath);
            if ($shortPath) $ffmpegInput = $shortPath;
        }
        $inputPath = $this->escapeShellPath($ffmpegInput);
        
        $audioMap = ' -map 0:v:0 -map 0:a:0';
        if (isset($_GET['audio'])) {
            $audioIdx = (int)$_GET['audio'];
            $audioMap = ' -map 0:v:0 -map 0:' . $audioIdx;
        }
        
        // ★ seek 파라미터 처리 (v5.8.1c — share에서도 quality 변경 시 사용자 시점부터 트랜스코딩)
        $seekSecShare = isset($_GET['seek']) ? max(0, (float)$_GET['seek']) : 0;
        $seekArgShare = $seekSecShare > 0 ? ' -ss ' . $seekSecShare : '';
        
        // SW 코덱 인자는 고정 (HW 실패 시 재시도용)
        $swCodecArgs = '-c:v libx264 -preset ultrafast -crf 23 -tune zerolatency -profile:v high -level 4.1 -pix_fmt yuv420p';
        $usingHwEncoder = false;
        if (isset($_GET['force_sw'])) {
            $videoCodecArgs = $swCodecArgs;
        } else {
            $videoCodecArgs = $this->detectHwEncoder($ffmpeg);
            $usingHwEncoder = (strpos($videoCodecArgs, 'libx264') === false);
        }
        $gopArgs = ' -g 48 -keyint_min 48';
        
        // ★ Quality 옵션 적용 (v5.8.1c)
        //   메인 HLS와 동일 패턴 — 양쪽 codec args 모두 적용
        $shareQuality = isset($_GET['quality']) ? trim($_GET['quality']) : 'original';
        $shareQualityResult = $this->applyQualityToArgs($videoCodecArgs, $shareQuality);
        $videoCodecArgs = $shareQualityResult['codecArgs'];
        $shareQualityVf = $shareQualityResult['vfPrepend'];
        $swQualityResultShare = $this->applyQualityToArgs($swCodecArgs, $shareQuality);
        $swCodecArgs = $swQualityResultShare['codecArgs'];
        
        $outputM3u8 = $this->escapeShellPath($sessionDir . '/stream.m3u8');
        $segPattern = '"' . str_replace('/', DIRECTORY_SEPARATOR, $sessionDir . '/stream%d.ts') . '"';
        
        // ffmpeg 명령 빌더 (같은 옵션으로 HW/SW 둘 다 만들 수 있도록 함수화)
        $buildShareFfmpegCmd = function($codecArgs) use ($ffmpeg, $inputPath, $audioMap, $gopArgs, $segPattern, $outputM3u8, $shareQualityVf, $seekArgShare) {
            $vfArg = $shareQualityVf !== '' ? ' -vf "' . $shareQualityVf . '"' : '';
            return escapeshellarg($ffmpeg)
                . $seekArgShare
                . ' -readrate 3'
                . ' -analyzeduration 2000000 -probesize 2000000'
                . ' -fflags +genpts+igndts+fastseek'
                . ' -i ' . $inputPath
                . $audioMap
                . ' -sn'
                . ' ' . $codecArgs
                . $gopArgs
                . ' -c:a aac -b:a 128k -ac 2'
                . $vfArg
                . ' -af aresample=async=1000:first_pts=0'
                . ' -f hls'
                . ' -hls_time 4'
                . ' -hls_list_size 0'
                . ' -hls_playlist_type event'
                . ' -hls_flags independent_segments'
                . ' -hls_segment_type mpegts'
                . ' -hls_segment_filename ' . $segPattern
                . ' ' . $outputM3u8;
        };
        $cmd = $buildShareFfmpegCmd($videoCodecArgs);
        // HW 인코더 사용 중이면 playlist 요청 경로에서 폴백 가능하도록 SW 명령도 준비
        $cmdSwBackup = $usingHwEncoder ? $buildShareFfmpegCmd($swCodecArgs) : null;
        
        $token = $_GET['t'] ?? '';
        $password = $_GET['password'] ?? '';
        $playlistUrl = 'share.php?t=' . urlencode($token) . '&download=1&stream=1&hls=1&hls_action=playlist&session=' . $sessionId;
        if ($password) $playlistUrl .= '&password=' . urlencode($password);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'session' => $sessionId,
            'playlist' => $playlistUrl,
        ]);
        
        // 메타데이터 저장 — sw_cmd, stderr_log, current_encoder, audio, client_session 포함 (api.php 경로와 동일)
        $stderrLog = $sessionDir . '/ffmpeg.log';
        $metaAudioIdxShare = isset($_GET['audio']) ? (int)$_GET['audio'] : null;
        // ★ client_session: 기기별 분리 (메인과 동일 패턴)
        $metaClientSessionShare = session_id() ? substr(hash('sha256', session_id()), 0, 16) : '';
        file_put_contents($sessionDir . '/meta.json', json_encode([
            'created' => time(),
            'share_path' => $fullPath,
            'audio' => $metaAudioIdxShare,
            'client_session' => $metaClientSessionShare,
            'sw_cmd' => $cmdSwBackup,
            'stderr_log' => $stderrLog,
            'current_encoder' => $this->extractEncoderFromArgs($videoCodecArgs),
            'quality' => $shareQuality,
            'seek' => $seekSecShare,
        ]));
        
        $pidFile = $sessionDir . '/pid.txt';
        if (PHP_OS_FAMILY === 'Windows') {
            $bgCmd = 'start /B "" ' . $cmd . ' > NUL 2> "' . $sessionDir . '\\ffmpeg.log"';
            pclose(popen($bgCmd, 'r'));
        } else {
            $bgCmd = $cmd . ' > ' . escapeshellarg($stderrLog) . ' 2>&1 & echo $! > ' . escapeshellarg($pidFile);
            $wrapper = 'nohup sh -c ' . escapeshellarg($bgCmd) . ' > /dev/null 2>&1 &';
            if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', @ini_get('disable_functions') ?: '')))) {
                exec($wrapper);
            } else {
                $ph = @popen($wrapper, 'r');
                if (is_resource($ph)) @pclose($ph);
            }
        }
        
        // PID 저장 (약간 대기 후)
        usleep(1000000);
        if (PHP_OS_FAMILY === 'Windows') {
            $wmic = shell_exec('wmic process where "name=\'ffmpeg.exe\'" get processid,commandline /format:csv 2>NUL');
            if ($wmic) {
                foreach (explode("\n", $wmic) as $line) {
                    if (strpos($line, $sessionId) !== false && preg_match('/,(\d+)\s*$/', trim($line), $pm)) {
                        file_put_contents($pidFile, $pm[1]);
                        break;
                    }
                }
            }
        } else if (!file_exists($pidFile)) {
            $pid = trim(@shell_exec('pgrep -f "' . $sessionId . '" 2>/dev/null') ?? '');
            if (!$pid) $pid = trim(@shell_exec('ps aux 2>/dev/null | grep ' . escapeshellarg($sessionId) . ' | grep -v grep | awk \'{print $2}\' 2>/dev/null') ?? '');
            if ($pid) file_put_contents($pidFile, $pid);
        }
        
        exit;
    }
    
    /**
     * 오디오 파일의 ID3v2 APIC 프레임에서 커버 아트 추출
     * 
     * 동작 방식:
     * 1. ID3v2 태그 헤더 읽기 (처음 10바이트)
     * 2. 태그 크기만큼 버퍼 읽기 (보통 수백KB 이하)
     * 3. APIC 프레임 찾아서 이미지 바이너리 추출
     * 4. 썸네일 캐시 디렉토리에 저장
     * 5. HTTP 응답으로 이미지 전송
     * 
     * 캐시: data/thumbcache/audio/{hash}.{ext}
     * - 캐시 키: md5(storage_id + path + filemtime) - 파일 변경 시 자동 갱신
     * 
     * 지원 포맷: MP3 (ID3v2.3, ID3v2.4), FLAC는 확장 가능
     * 
     * 원격 스토리지는 ID3 추출 안 함 (성능 문제) - 404 반환하여 클라이언트가 폴더 이미지 fallback
     */
    public function audioCover(int $storageId, string $relativePath): void {
        if (!$this->storage->checkPermission($storageId, 'can_read')) {
            http_response_code(403);
            exit;
        }
        
        // ★ PHP session_start()가 자동으로 추가한 캐시 비활성 헤더 제거
        //    PHP는 세션 사용 시 'Cache-Control: max-age=0, private, must-revalidate' 강제 추가
        //    이 헤더가 우리 캐시 헤더를 덮어써서 브라우저 캐시 안 됨
        //    HAR 검증 결과 발견 (펜닐님 환경에서 캐시 안 되는 원인)
        header_remove('Cache-Control');
        header_remove('Expires');
        header_remove('Pragma');
        
        // 원격 스토리지는 미지원 (FTP/SFTP/SMB에서 ID3 추출은 느려서 UX 저하)
        // → 클라이언트에서 404 받으면 기존 폴더 이미지 fallback으로 자연스럽게 처리
        if ($this->isRemoteStorage($storageId)) {
            http_response_code(404);
            exit;
        }
        
        $basePath = $this->storage->getRealPath($storageId);
        $fullPath = $this->buildPath($basePath, $relativePath);
        
        if (!$this->isPathSafe($basePath, $fullPath) || !is_file($fullPath)) {
            http_response_code(404);
            exit;
        }
        
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        // 지원 확장자 체크 (MP3만, 확장 가능)
        if (!in_array($ext, ['mp3'])) {
            http_response_code(404);
            exit;
        }
        
        // 파일 크기 제한: 0 바이트 또는 500MB 이상은 스킵 (0은 읽을 것 없고, 500MB는 과도한 ID3)
        $fileSize = @filesize($fullPath);
        if ($fileSize === false || $fileSize <= 0 || $fileSize > 500 * 1024 * 1024) {
            http_response_code(404);
            exit;
        }
        
        // 캐시 경로: data/thumbcache/audio/{hash}
        // 캐시 키에 mtime 포함 → 파일 변경 시 자동으로 새 캐시 사용
        $mtime = @filemtime($fullPath) ?: 0;
        $cacheKey = md5($storageId . '/' . $relativePath . '/' . $mtime);
        $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
        $cacheDir = $dataDir . DIRECTORY_SEPARATOR . 'thumbcache' . DIRECTORY_SEPARATOR . 'audio';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        // 캐시 조회 (확장자 모르므로 glob 대신 메타 파일 사용)
        $cacheMetaFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.meta';
        $cacheImgFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.img';
        // 네거티브 캐시 마커 (커버 없는 파일임을 기억 → 반복 파싱 방지)
        $cacheNoCoverFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.nocover';
        
        if (is_file($cacheMetaFile) && is_file($cacheImgFile)) {
            // 캐시 히트: 즉시 반환 (단, 0바이트 파일은 무시 — 디스크 가득 참 등으로 쓰기 실패한 경우)
            $imgFileSize = @filesize($cacheImgFile);
            if ($imgFileSize !== false && $imgFileSize > 0) {
                $mime = trim(@file_get_contents($cacheMetaFile));
                if ($mime && preg_match('/^image\/[a-z0-9+-]+$/i', $mime)) {
                    // ★ ETag: 캐시 키 자체가 mtime 포함 → immutable 보장
                    //   브라우저가 If-None-Match 보내면 304 응답으로 트래픽 절약
                    $etag = '"' . $cacheKey . '"';
                    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                        http_response_code(304);
                        header('ETag: ' . $etag);
                        header('Cache-Control: public, max-age=2592000, immutable');
                        exit;
                    }
                    header('Content-Type: ' . $mime);
                    header('Content-Length: ' . $imgFileSize);
                    header('ETag: ' . $etag, true);
                    // ★ 30일 캐시 + immutable: 캐시 키에 mtime 포함이라 파일 변경 시 새 URL이 됨
                    //    immutable 힌트 → 브라우저 재검증 안 함 (Firefox/Chrome 지원)
                    //    replace=true 명시 (PHP 자동 헤더 강제 덮어쓰기)
                    header('Cache-Control: public, max-age=2592000, immutable', true);
                    readfile($cacheImgFile);
                    exit;
                }
            }
            // 캐시 파일 문제 있으면 삭제 후 재파싱으로 폴백
            @unlink($cacheMetaFile);
            @unlink($cacheImgFile);
        }
        
        // 네거티브 캐시 히트: "커버 없는 파일"로 기록된 경우 ID3 파싱 건너뛰고 즉시 204
        // 204 사용: 'ID3 커버 없음'은 정상 상황 (유튜브 MP3 등 매우 흔함)
        // 사용자 요청: 콘솔에 빨간 에러 안 뜨게
        if (is_file($cacheNoCoverFile)) {
            http_response_code(204);
            // ★ 네거티브 캐시도 mtime 포함 키이므로 7일로 늘림 (immutable은 빼서 안전)
            header('Cache-Control: public, max-age=604800');
            exit;
        }
        
        // 캐시 없음 → ID3v2에서 APIC 추출
        $cover = $this->extractID3v2Cover($fullPath);
        if (!$cover) {
            // 커버 없음 → 네거티브 캐시 마커 저장 (다음 요청 시 파싱 건너뛰기)
            @file_put_contents($cacheNoCoverFile, '1');
            // 204 No Content: 브라우저 콘솔에 빨간 에러 표시 안 됨
            http_response_code(204);
            header('Cache-Control: public, max-age=604800');
            exit;
        }
        
        // 캐시 저장 (실패해도 응답은 계속)
        @file_put_contents($cacheMetaFile, $cover['mime']);
        @file_put_contents($cacheImgFile, $cover['data']);
        
        // HTTP 응답
        // ★ ETag + 30일 캐시 + immutable (캐시 히트 분기와 동일 정책)
        $etag = '"' . $cacheKey . '"';
        header('Content-Type: ' . $cover['mime']);
        header('Content-Length: ' . strlen($cover['data']));
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=2592000, immutable');
        echo $cover['data'];
        exit;
    }
    
    /**
     * MP3 파일의 ID3v2 태그에서 APIC 프레임(커버 이미지) 추출
     * 
     * ID3v2 구조:
     * - 헤더 10바이트: "ID3" + version(2) + flags(1) + size(4, syncsafe integer)
     * - 태그 본문: 연속된 프레임들
     * 
     * APIC 프레임 구조 (ID3v2.3/2.4):
     * - 프레임 헤더 10바이트: "APIC" + size(4) + flags(2)
     * - 프레임 본문:
     *   [text_encoding(1)] + [mime_type(null-terminated)] + 
     *   [picture_type(1)] + [description(null-terminated)] + [image_data]
     * 
     * @return array|null ['mime' => 'image/jpeg', 'data' => binary]
     */
    private function extractID3v2Cover(string $file): ?array {
        $fp = @fopen($file, 'rb');
        if (!$fp) return null;
        
        try {
            // ID3v2 헤더 10바이트 읽기
            $header = @fread($fp, 10);
            if (strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') {
                return null;
            }
            
            // 버전 체크 (v2.3 또는 v2.4만 지원 — v2.2는 프레임 ID가 3바이트라 별도 처리 필요)
            $majorVersion = ord($header[3]);
            if ($majorVersion !== 3 && $majorVersion !== 4) {
                return null;
            }
            
            // Flags 바이트 체크 (header[5])
            // bit 7: Unsynchronization (설정되면 태그 전체가 unsync되어 복잡) - 드물어서 거부
            // bit 6: Extended Header 존재 여부 (우리가 스킵해야 함)
            // bit 5: Experimental (무시 OK)
            $flags = ord($header[5]);
            $hasExtendedHeader = ($flags & 0x40) !== 0;
            $hasUnsynchronization = ($flags & 0x80) !== 0;
            if ($hasUnsynchronization) {
                return null;  // 복잡한 unsync 처리 불가 → 폴더 이미지 fallback으로
            }
            
            // 태그 크기 (syncsafe integer: 각 바이트의 최상위 비트 무시)
            $b = unpack('C4', substr($header, 6, 4));
            $tagSize = (($b[1] & 0x7F) << 21) | (($b[2] & 0x7F) << 14) | 
                       (($b[3] & 0x7F) << 7)  | ($b[4] & 0x7F);
            
            // 비정상적으로 큰 태그 거부 (10MB 초과 시)
            if ($tagSize > 10 * 1024 * 1024 || $tagSize < 10) {
                return null;
            }
            
            // 태그 본문 전체 읽기
            $tagBody = @fread($fp, $tagSize);
            if (strlen($tagBody) < $tagSize - 10) {
                // 일부 실패는 허용, 너무 적으면 포기
                if (strlen($tagBody) < 100) return null;
            }
            
            // 프레임 순회하며 APIC 찾기
            $pos = 0;
            $bodyLen = strlen($tagBody);
            
            // Extended Header 스킵 (v2.3/v2.4 구조 다름)
            if ($hasExtendedHeader && $bodyLen >= 4) {
                if ($majorVersion === 4) {
                    // v2.4: Extended Header Size는 syncsafe integer
                    $eb = unpack('C4', substr($tagBody, 0, 4));
                    $extHeaderSize = (($eb[1] & 0x7F) << 21) | (($eb[2] & 0x7F) << 14) |
                                     (($eb[3] & 0x7F) << 7)  | ($eb[4] & 0x7F);
                } else {
                    // v2.3: Extended Header Size는 일반 32bit
                    $eb = unpack('C4', substr($tagBody, 0, 4));
                    $extHeaderSize = ($eb[1] << 24) | ($eb[2] << 16) | ($eb[3] << 8) | $eb[4];
                    // v2.3은 size에 자신(4바이트) 포함 안함 → 추가 스킵
                    $extHeaderSize += 4;
                }
                // 유효 범위 체크 (극단적 값 거부)
                if ($extHeaderSize > 0 && $extHeaderSize < $bodyLen) {
                    $pos = $extHeaderSize;
                }
            }
            
            while ($pos + 10 <= $bodyLen) {
                $frameId = substr($tagBody, $pos, 4);
                
                // 패딩 영역 도달 (null 바이트) → 종료
                if ($frameId === "\x00\x00\x00\x00") break;
                
                // 프레임 크기 (v2.3: 일반 32bit big-endian, v2.4: syncsafe)
                $sb = unpack('C4', substr($tagBody, $pos + 4, 4));
                if ($majorVersion === 4) {
                    $frameSize = (($sb[1] & 0x7F) << 21) | (($sb[2] & 0x7F) << 14) |
                                 (($sb[3] & 0x7F) << 7)  | ($sb[4] & 0x7F);
                } else {
                    $frameSize = ($sb[1] << 24) | ($sb[2] << 16) | ($sb[3] << 8) | $sb[4];
                }
                
                // 유효성 체크
                if ($frameSize <= 0 || $frameSize > $bodyLen - $pos - 10) break;
                
                if ($frameId === 'APIC') {
                    // APIC 프레임 본문 추출
                    $framePos = $pos + 10;  // 프레임 헤더 건너뛰기
                    $frameData = substr($tagBody, $framePos, $frameSize);
                    
                    // [text_encoding(1)] + [mime(null-terminated)] + 
                    // [picture_type(1)] + [description(null-terminated)] + [image_data]
                    if (strlen($frameData) < 4) return null;
                    
                    $encoding = ord($frameData[0]);
                    $p = 1;
                    
                    // MIME 타입 (null-terminated ASCII)
                    $mimeEnd = strpos($frameData, "\x00", $p);
                    if ($mimeEnd === false) return null;
                    $mime = substr($frameData, $p, $mimeEnd - $p);
                    $p = $mimeEnd + 1;
                    
                    // Picture type (1 byte) — 0x03 = Cover (front) 이 이상적이지만 다 허용
                    if ($p >= strlen($frameData)) return null;
                    $p += 1;
                    
                    // Description (null-terminated, encoding별 구분자 다름)
                    if ($encoding === 1 || $encoding === 2) {
                        // UTF-16: null byte 2개
                        while ($p + 1 < strlen($frameData)) {
                            if ($frameData[$p] === "\x00" && $frameData[$p+1] === "\x00") {
                                $p += 2;
                                break;
                            }
                            $p += 2;
                        }
                    } else {
                        // ISO-8859-1 / UTF-8: null byte 1개
                        $descEnd = strpos($frameData, "\x00", $p);
                        if ($descEnd === false) return null;
                        $p = $descEnd + 1;
                    }
                    
                    if ($p >= strlen($frameData)) return null;
                    $imageData = substr($frameData, $p);
                    
                    // 이미지 데이터 크기 체크
                    if (strlen($imageData) < 100) return null;  // 너무 작으면 유효한 이미지 아님
                    if (strlen($imageData) > 20 * 1024 * 1024) return null;  // 20MB 이상 거부 (비정상)
                    
                    // MIME 타입 정규화
                    $mime = strtolower(trim($mime));
                    if (empty($mime) || $mime === '-->') {
                        // URL 링크 타입이거나 빈 MIME → 매직 바이트로 판별
                        $mime = $this->detectImageMimeByMagic($imageData);
                        if (!$mime) return null;
                    } else if (!preg_match('/^image\//', $mime)) {
                        // 일부 파일이 "jpeg" 같이 축약형 MIME 사용
                        if (preg_match('/^(jpg|jpeg|png|gif|webp|bmp)$/i', $mime)) {
                            $mime = 'image/' . strtolower($mime);
                            if ($mime === 'image/jpg') $mime = 'image/jpeg';
                        } else {
                            // 매직 바이트로 재판별
                            $detected = $this->detectImageMimeByMagic($imageData);
                            if ($detected) $mime = $detected;
                            else return null;
                        }
                    }
                    
                    return ['mime' => $mime, 'data' => $imageData];
                }
                
                $pos += 10 + $frameSize;
            }
            
            return null;
        } finally {
            @fclose($fp);
        }
    }
    
    /**
     * MP3 파일의 ID3v2 USLT 프레임에서 정적 가사 추출
     * (ShareManager::extractID3v2Lyrics 동일 로직 — 결합도 낮춤)
     * 
     * 반환: ['language' => 'kor'/..., 'text' => '가사 본문'] 또는 null
     */
    private function extractID3v2Lyrics(string $file): ?array {
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
                
                if ($frameId === 'USLT') {
                    $frameBody = substr($tagData, $offset + 10, $frameSize);
                    if (strlen($frameBody) < 5) { $offset += 10 + $frameSize; continue; }
                    
                    $textEncoding = ord($frameBody[0]);
                    $language = substr($frameBody, 1, 3);
                    
                    $pos = 4;
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
                    
                    $lyricsBytes = substr($frameBody, $pos);
                    if (strlen($lyricsBytes) < 1) { $offset += 10 + $frameSize; continue; }
                    
                    $lyricsText = '';
                    if ($textEncoding === 0) {
                        $lyricsText = @mb_convert_encoding($lyricsBytes, 'UTF-8', 'ISO-8859-1');
                    } elseif ($textEncoding === 1) {
                        if (strlen($lyricsBytes) >= 2) {
                            $bom = substr($lyricsBytes, 0, 2);
                            if ($bom === "\xFF\xFE") {
                                $lyricsText = @mb_convert_encoding(substr($lyricsBytes, 2), 'UTF-8', 'UTF-16LE');
                            } elseif ($bom === "\xFE\xFF") {
                                $lyricsText = @mb_convert_encoding(substr($lyricsBytes, 2), 'UTF-8', 'UTF-16BE');
                            } else {
                                $lyricsText = @mb_convert_encoding($lyricsBytes, 'UTF-8', 'UTF-16LE');
                            }
                        }
                    } elseif ($textEncoding === 2) {
                        $lyricsText = @mb_convert_encoding($lyricsBytes, 'UTF-8', 'UTF-16BE');
                    } elseif ($textEncoding === 3) {
                        $lyricsText = $lyricsBytes;
                    }
                    
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
     * MP3 파일의 ID3v2 SYLT 프레임에서 시간 동기화 가사 추출
     * 
     * SYLT 프레임 구조:
     *   [Encoding 1byte][Language 3byte][Format 1byte][Type 1byte][Description...]\x00
     *   [Text 1]\x00\x00 (UTF-16) or \x00 (단일 바이트)[Time stamp 1 — 4 bytes BE]
     *   [Text 2]\x00...[Time stamp 2]
     *   ...
     * 
     * Format: 0x01 = MPEG frames, 0x02 = milliseconds
     * Type: 0x01 = lyrics (다른 값은 코드/이벤트 등 — 무시)
     * 
     * 반환: ['text' => 'LRC 형식 변환된 가사', 'language' => 'kor'] 또는 null
     *       (텍스트는 [mm:ss.xx]가사 형식으로 변환되어 반환됨)
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
                    
                    // Type 0x01 (lyrics)만 처리, 다른 값은 무시
                    if ($contentType !== 0x01) { $offset += 10 + $frameSize; continue; }
                    
                    // Description 건너뛰기 (null terminator)
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
                    
                    // 가사 + 시간스탬프 쌍을 LRC 형식으로 변환
                    $lrcLines = [];
                    while ($pos + 4 < strlen($frameBody)) {
                        // 텍스트 끝(null) 찾기
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
                        
                        // 시간스탬프 (4 bytes BE)
                        if ($pos + 4 > strlen($frameBody)) break;
                        $timestamp = (ord($frameBody[$pos]) << 24)
                                   | (ord($frameBody[$pos + 1]) << 16)
                                   | (ord($frameBody[$pos + 2]) << 8)
                                   |  ord($frameBody[$pos + 3]);
                        $pos += 4;
                        
                        // 텍스트 인코딩 변환
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
                        
                        // 시간스탬프를 [mm:ss.xx] 형식으로 변환
                        if ($timeFormat === 0x02) {
                            // 밀리초 단위
                            $totalSec = $timestamp / 1000.0;
                        } else {
                            // MPEG frames — 정확한 변환 어렵지만 추정 (44100Hz 가정)
                            // 일반적으로 timestamp 자체가 ms로 들어오는 경우가 많음
                            $totalSec = $timestamp / 1000.0;
                        }
                        
                        if ($totalSec < 0 || $totalSec > 36000) continue;  // 10시간 미만
                        
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
     * 🏠 [메인 페이지] 오디오 파일의 가사 가져오기 (LRC > SYLT > USLT > TXT 우선순위)
     * ============================================================================
     * 
     * ⚠️ 주의: 이 메서드는 *메인 페이지(index.php) 전용*입니다.
     * 
     * - API 엔드포인트: api.php?action=audio_lyrics&storage_id=&path=
     * - 호출 흐름: 메인 미리보기 → FSAudioPlayer._loadLyrics → audio_lyrics → 이 메서드
     * - 짝 메서드: ShareManager::getShareLyrics() (공유 스트리밍 전용)
     *   공유 페이지 가사는 share.php?t=토큰&lyrics=1 → ShareManager::getShareLyrics
     * 
     * 반환: ['source', 'text', 'synced', 'language'] 또는 null
     */
    public function getAudioLyrics(int $storageId, string $path): ?array {
        // 권한 체크 (audioCover 패턴 동일)
        if (!$this->storage->checkPermission($storageId, 'can_read')) return null;
        
        // 원격 스토리지는 미지원 (LRC 검색 시 매번 디렉토리 listing 필요해서 비효율)
        if ($this->isRemoteStorage($storageId)) return null;
        
        $basePath = $this->storage->getRealPath($storageId);
        if (!$basePath) return null;
        
        $fullPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
        if (!file_exists($fullPath) || !is_readable($fullPath)) return null;
        
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $audioExts = ['mp3', 'm4a', 'flac', 'ogg', 'wav', 'aac', 'opus', 'ape', 'alac', 'aiff'];
        if (!in_array($ext, $audioExts)) return null;
        
        $dir = dirname($fullPath);
        $baseName = pathinfo($fullPath, PATHINFO_FILENAME);
        
        // 1. LRC 파일 (정확 매칭)
        $lrcPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.lrc';
        if (file_exists($lrcPath) && is_readable($lrcPath)) {
            $lrcSize = @filesize($lrcPath);
            if ($lrcSize > 0 && $lrcSize < 1 * 1024 * 1024) {
                $lrcText = @file_get_contents($lrcPath);
                if ($lrcText !== false) {
                    if (substr($lrcText, 0, 3) === "\xEF\xBB\xBF") {
                        $lrcText = substr($lrcText, 3);
                    }
                    if (!mb_check_encoding($lrcText, 'UTF-8')) {
                        $lrcText = @mb_convert_encoding($lrcText, 'UTF-8', 'CP949,EUC-KR,UTF-16LE,UTF-16BE,ISO-8859-1');
                    }
                    if ($lrcText !== false && strlen(trim($lrcText)) > 0) {
                        return ['source' => 'lrc', 'text' => $lrcText, 'synced' => true];
                    }
                }
            }
        }
        
        // 2. ID3v2 SYLT (mp3만, 시간 동기화 가사)
        if ($ext === 'mp3') {
            $sylt = $this->extractID3v2SyncedLyrics($fullPath);
            if ($sylt && strlen(trim($sylt['text'])) > 0) {
                return ['source' => 'sylt', 'text' => $sylt['text'], 'synced' => true, 'language' => $sylt['language']];
            }
        }
        
        // 3. ID3v2 USLT (mp3만, 정적 가사)
        if ($ext === 'mp3') {
            $uslt = $this->extractID3v2Lyrics($fullPath);
            if ($uslt && strlen(trim($uslt['text'])) > 0) {
                return ['source' => 'uslt', 'text' => $uslt['text'], 'synced' => false, 'language' => $uslt['language']];
            }
        }
        
        // 4. TXT 파일 (정확 매칭)
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
                        $synced = preg_match('/^\s*\[\d{1,2}:\d{2}(?:[.:]\d{1,3})?\]/m', $txtText) === 1;
                        return ['source' => 'txt', 'text' => $txtText, 'synced' => $synced];
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * 이미지 바이너리 시작 바이트로 MIME 타입 추측
     */
    private function detectImageMimeByMagic(string $data): ?string {
        if (strlen($data) < 8) return null;
        $header = substr($data, 0, 8);
        // JPEG: FF D8 FF
        if (substr($header, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (substr($header, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") return 'image/png';
        // GIF: GIF87a / GIF89a
        if (substr($header, 0, 6) === 'GIF87a' || substr($header, 0, 6) === 'GIF89a') return 'image/gif';
        // WebP: RIFF....WEBP
        if (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') return 'image/webp';
        // BMP: BM
        if (substr($header, 0, 2) === 'BM') return 'image/bmp';
        return null;
    }
}