<?php
/**
 * Storage Adapter Interface and Implementations
 * 각 스토리지 타입별 파일 작업 처리
 * 
 * 지원 스토리지 타입:
 * - LocalAdapter: 로컬/네트워크 드라이브
 * - FtpAdapter: FTP/FTPS 서버
 * - SftpAdapter: SFTP 서버 (ssh2 확장 필요)
 * - WebDavAdapter: WebDAV 서버
 * - S3Adapter: Amazon S3 / 호환 스토리지
 */

interface StorageAdapterInterface {
    public function connect(): bool;
    public function disconnect(): void;
    public function list(string $path): array;
    public function read(string $path): string;
    public function write(string $path, string $content): bool;
    public function delete(string $path): bool;
    public function mkdir(string $path): bool;
    public function rename(string $from, string $to): bool;
    public function exists(string $path): bool;
    public function isDir(string $path): bool;
    public function getSize(string $path): int;
    public function getMime(string $path): string;
    public function getModified(string $path): int;
}

/**
 * 스토리지 어댑터 팩토리
 */
class StorageAdapterFactory {
    public static function create(array $storage): ?StorageAdapterInterface {
        $type = $storage['storage_type'] ?? 'local';
        $config = [];
        
        if (!empty($storage['config'])) {
            $config = is_array($storage['config']) 
                ? $storage['config'] 
                : \decryptStorageConfig($storage['config']);
        }
        
        switch ($type) {
            case 'local':
                return new LocalAdapter($storage['path']);
            case 'ftp':
                return new FtpAdapter($config);
            case 'sftp':
                return new SftpAdapter($config);
            case 'webdav':
                return new WebDavAdapter($config);
            case 's3':
                return new S3Adapter($config);
            case 'smb':
                return new SmbAdapter($config);
            default:
                return new LocalAdapter($storage['path'] ?? '');
        }
    }
}

/**
 * 로컬 파일시스템 어댑터
 */
class LocalAdapter implements StorageAdapterInterface {
    private string $basePath;
    
    public function __construct(string $basePath) {
        $this->basePath = rtrim($basePath, '/\\');
    }
    
    public function connect(): bool {
        return is_dir($this->basePath);
    }
    
    public function disconnect(): void {}
    
    public function list(string $path): array {
        $fullPath = $this->getFullPath($path);
        if (!is_dir($fullPath)) return [];
        
        static $skipDirs = ['$RECYCLE.BIN', 'System Volume Information', '$Recycle.Bin'];
        $items = [];
        foreach (scandir($fullPath) as $item) {
            if ($item === '.' || $item === '..') continue;
            if (in_array($item, $skipDirs)) continue;
            $itemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
            $isDir = is_dir($itemPath);
            $items[] = [
                'name' => $item,
                'path' => ltrim($path . '/' . $item, '/'),
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : (@filesize($itemPath) ?: 0),
                'modified' => @filemtime($itemPath) ?: 0
            ];
        }
        return $items;
    }
    
    public function read(string $path): string {
        return file_get_contents($this->getFullPath($path)) ?: '';
    }
    
    public function write(string $path, string $content): bool {
        return file_put_contents($this->getFullPath($path), $content) !== false;
    }
    
    public function delete(string $path): bool {
        $fullPath = $this->getFullPath($path);
        if (is_dir($fullPath)) {
            return $this->deleteDir($fullPath);
        }
        return unlink($fullPath);
    }
    
    private function deleteDir(string $dir): bool {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        return rmdir($dir);
    }
    
    public function mkdir(string $path): bool {
        return mkdir($this->getFullPath($path), 0755, true);
    }
    
    public function rename(string $from, string $to): bool {
        return rename($this->getFullPath($from), $this->getFullPath($to));
    }
    
    public function exists(string $path): bool {
        return file_exists($this->getFullPath($path));
    }
    
    public function isDir(string $path): bool {
        return is_dir($this->getFullPath($path));
    }
    
    public function getSize(string $path): int {
        return filesize($this->getFullPath($path)) ?: 0;
    }
    
    public function getMime(string $path): string {
        return mime_content_type($this->getFullPath($path)) ?: 'application/octet-stream';
    }
    
    public function getModified(string $path): int {
        return @filemtime($this->getFullPath($path)) ?: 0;
    }
    
    public function copyFileTo(string $srcPath, StorageAdapterInterface $target, string $tgtPath): bool {
        $fullSrc = $this->getFullPath($srcPath);
        if ($target instanceof LocalAdapter) {
            $fullTgt = $target->getFullPath($tgtPath);
            $tgtDir = dirname($fullTgt);
            if (!is_dir($tgtDir)) @mkdir($tgtDir, 0755, true);
            $ok = @copy($fullSrc, $fullTgt);
            if (!$ok) {
                fsLog("Sync copy FAILED: $fullSrc → $fullTgt");
            }
            return $ok;
        }
        // 원격 대상: 청크 읽기
        $fh = @fopen($fullSrc, 'rb');
        if (!$fh) return false;
        $content = '';
        while (!feof($fh)) {
            $content .= fread($fh, 8 * 1024 * 1024);
        }
        fclose($fh);
        return $target->write($tgtPath, $content);
    }
    
    public function getFullPath(string $path): string {
        return $this->basePath . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

/**
 * FTP 어댑터
 */
class FtpAdapter implements StorageAdapterInterface {
    private $connection = null;
    private bool $_lazyConnect = false;
    private array $config;
    private string $lastError = '';
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    public function getLastError(): string {
        return $this->lastError;
    }
    
    public function connect(): bool {
        $host = $this->config['host'] ?? '';
        $port = $this->config['port'] ?? 21;
        
        if (empty($host)) {
            $this->lastError = __('api_err_ftp_no_host', 'FTP 호스트가 지정되지 않았습니다.');
            return false;
        }
        
        // curl이 있으면 PHP FTP 연결을 지연 (목록 조회는 curl로 처리)
        if (function_exists('curl_init')) {
            $this->_lazyConnect = true;
            return true;
        }
        
        return $this->_doFtpConnect();
    }
    
    /** PHP FTP 확장으로 실제 연결 (필요 시 호출) */
    private function _doFtpConnect(): bool {
        if ($this->connection) return true;
        
        $host = $this->config['host'] ?? '';
        $port = $this->config['port'] ?? 21;
        $ssl = $this->config['ssl'] ?? false;
        $connectTimeout = (int)($this->config['connect_timeout'] ?? 5);
        
        $this->connection = $ssl 
            ? @ftp_ssl_connect($host, $port, $connectTimeout)
            : @ftp_connect($host, $port, $connectTimeout);
            
        if (!$this->connection) {
            $this->lastError = __f('ftp_connect_fail', ['host' => $host, 'port' => $port]);
            return false;
        }
        
        $username = $this->config['username'] ?? 'anonymous';
        $password = $this->config['password'] ?? '';
        
        if (!@ftp_login($this->connection, $username, $password)) {
            $this->lastError = __('api_err_ftp_login_failed', 'FTP 로그인 실패: 사용자명 또는 비밀번호를 확인하세요.');
            $this->disconnect();
            return false;
        }
        
        if ($this->config['passive'] ?? true) {
            ftp_pasv($this->connection, true);
            if (defined('FTP_USEPASVADDRESS')) {
                @ftp_set_option($this->connection, FTP_USEPASVADDRESS, false);
            }
        }
        
        return true;
    }
    
    public function disconnect(): void {
        if ($this->connection) {
            @ftp_close($this->connection);
            $this->connection = null;
        }
    }
    
    public function list(string $path): array {
        if (!$this->connection && !$this->_lazyConnect) return [];
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        if (empty($fullPath) || $fullPath === '') $fullPath = '/';
        
        $items = [];
        
        // 1차: curl 기반 목록 조회 (Passive Mode 안정, 가장 빠름)
        if (function_exists('curl_init')) {
            $curlItems = $this->_curlFtpList($fullPath, $path);
            if ($curlItems !== false) return $curlItems;
        }
        
        // PHP FTP 확장 폴백 (curl 실패 시) - 지연 연결
        if (!$this->connection && !$this->_doFtpConnect()) return $items;
        
        // 2차: ftp_mlsd (MLSD 지원 서버용)
        $origTimeout = @ftp_get_option($this->connection, FTP_TIMEOUT_SEC) ?: 90;
        @ftp_set_option($this->connection, FTP_TIMEOUT_SEC, 3);
        $list = @ftp_mlsd($this->connection, $fullPath);
        @ftp_set_option($this->connection, FTP_TIMEOUT_SEC, $origTimeout);
        
        if ($list !== false) {
            foreach ($list as $item) {
                if ($item['name'] === '.' || $item['name'] === '..') continue;
                $items[] = [
                    'name' => $item['name'],
                    'path' => ltrim($path . '/' . $item['name'], '/'),
                    'is_dir' => $item['type'] === 'dir',
                    'size' => (int)($item['size'] ?? 0),
                    'modified' => isset($item['modify']) ? strtotime($item['modify']) : 0
                ];
            }
            return $items;
        }
        
        // 3차: ftp_rawlist (Unix/Windows 형식 파싱)
        // ipTIME 등에서 Passive rawlist가 10초 타임아웃될 수 있으므로 FTP 타임아웃 단축
        $origTimeout = @ftp_get_option($this->connection, FTP_TIMEOUT_SEC) ?: 90;
        @ftp_set_option($this->connection, FTP_TIMEOUT_SEC, 3);
        $rawlist = @ftp_rawlist($this->connection, $fullPath);
        
        // 패시브 모드에서 실패 시 액티브 모드로 재시도
        if ($rawlist === false) {
            @ftp_pasv($this->connection, false);
            $rawlist = @ftp_rawlist($this->connection, $fullPath);
            // 다시 패시브 모드로 복귀
            if ($this->config['passive'] ?? true) {
                @ftp_pasv($this->connection, true);
            }
        }
        @ftp_set_option($this->connection, FTP_TIMEOUT_SEC, $origTimeout);
        
        if ($rawlist !== false && count($rawlist) > 0) {
            foreach ($rawlist as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                $parsed = $this->parseFtpRawLine($line, $path);
                if ($parsed) $items[] = $parsed;
            }
            return $items;
        }
        
        // 4차: ftp_nlist (이름만)
        @ftp_set_option($this->connection, FTP_TIMEOUT_SEC, 3);
        $files = @ftp_nlist($this->connection, $fullPath);
        
        // 패시브 모드에서 실패 시 액티브 모드로 재시도
        if ($files === false) {
            @ftp_pasv($this->connection, false);
            $files = @ftp_nlist($this->connection, $fullPath);
            if ($this->config['passive'] ?? true) {
                @ftp_pasv($this->connection, true);
            }
        }
        
        if ($files) {
            foreach ($files as $file) {
                $name = basename($file);
                if ($name === '.' || $name === '..') continue;
                $filePath = (strpos($file, '/') === 0) ? $file : $fullPath . '/' . $name;
                $size = @ftp_size($this->connection, $filePath);
                $isDir = ($size === -1);
                $items[] = [
                    'name' => $name,
                    'path' => ltrim($path . '/' . $name, '/'),
                    'is_dir' => $isDir,
                    'size' => $isDir ? 0 : $size,
                    'modified' => @ftp_mdtm($this->connection, $filePath)
                ];
            }
        }
        
        @ftp_set_option($this->connection, FTP_TIMEOUT_SEC, $origTimeout);
        return $items;
    }
    
    /**
     * FTP rawlist 한 줄 파싱 (Unix/Windows 형식)
     */
    private function parseFtpRawLine(string $line, string $basePath): ?array {
        // Unix 형식: drwxr-xr-x  2 user group  4096 Mar 16 12:00 dirname
        if (preg_match('/^([d\-l])([rwxsStT\-]{9})\s+\d+\s+\S+\s+\S+\s+(\d+)\s+(\w+\s+\d+\s+[\d:]+)\s+(.+)$/', $line, $m)) {
            $name = trim($m[5]);
            if ($name === '.' || $name === '..') return null;
            $isDir = ($m[1] === 'd');
            $size = (int)$m[3];
            $modified = strtotime($m[4]);
            return [
                'name' => $name,
                'path' => ltrim($basePath . '/' . $name, '/'),
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : $size,
                'modified' => $modified ?: 0
            ];
        }
        
        // Windows 형식: 03-16-26  12:00PM  <DIR>  dirname
        //               03-16-26  12:00PM  12345  filename.txt
        if (preg_match('/^(\d{2}-\d{2}-\d{2}\s+\d{2}:\d{2}[AP]M)\s+(<DIR>|\d+)\s+(.+)$/', $line, $m)) {
            $name = trim($m[3]);
            if ($name === '.' || $name === '..') return null;
            $isDir = ($m[2] === '<DIR>');
            $size = $isDir ? 0 : (int)$m[2];
            $modified = strtotime($m[1]);
            return [
                'name' => $name,
                'path' => ltrim($basePath . '/' . $name, '/'),
                'is_dir' => $isDir,
                'size' => $size,
                'modified' => $modified ?: 0
            ];
        }
        
        // ipTIME/기타 간단 형식: 이름만 있는 경우
        $name = trim($line);
        if ($name === '.' || $name === '..' || empty($name)) return null;
        
        // 전체 경로가 포함된 경우 basename 추출
        if (strpos($name, '/') !== false) $name = basename($name);
        
        return [
            'name' => $name,
            'path' => ltrim($basePath . '/' . $name, '/'),
            'is_dir' => false,  // rawlist에서 형식 파싱 실패 시 파일로 간주
            'size' => 0,
            'modified' => 0
        ];
    }
    
    public function read(string $path): string {
        if (!$this->connection && !$this->_doFtpConnect()) return '';
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        // curl 우선 사용 (PHP FTP 확장보다 안정적)
        if (function_exists('curl_init')) {
            $content = $this->_curlFtpGet($fullPath);
            if ($content !== false) return $content;
        }
        
        // 폴백: PHP FTP 확장
        $temp = tmpfile();
        $tempPath = stream_get_meta_data($temp)['uri'];
        
        if (@ftp_get($this->connection, $tempPath, $fullPath, FTP_BINARY)) {
            $content = file_get_contents($tempPath);
            fclose($temp);
            return $content;
        }
        
        fclose($temp);
        return '';
    }
    
    /**
     * FTP 부분 읽기 (MP3 헤더 파싱용)
     * curl: CURLOPT_RANGE (FTP REST + 청크 제한)
     * PHP FTP: ftp_nb_fget + ftp_nb_continue (청크 다운로드 후 중단)
     */
    public function readPartial(string $path, int $offset = 0, int $length = 65536): string {
        if (!$this->connection && !$this->_doFtpConnect()) return '';
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        // curl 방식: CURLOPT_WRITEFUNCTION으로 정확한 바이트 제어
        // - CURLOPT_RANGE로 요청하되 서버가 Range 무시할 경우에도
        //   WRITEFUNCTION에서 누적 바이트 체크 → $length 초과 시 즉시 abort
        // - 이렇게 하면 절대 요청 크기 이상 받지 않음 (FTP 데이터 낭비 완전 차단)
        if (function_exists('curl_init')) {
            $url = $this->_buildFtpUrl($fullPath);
            if ($url) {
                $end = $offset + $length - 1;
                $buffer = '';
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_TIMEOUT => 5,  // 빠른 실패 (64KB 헤더는 5초면 충분)
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_FTP_SSL => CURLFTPSSL_TRY,
                    CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
                    CURLOPT_RANGE => "$offset-$end",
                    // WRITEFUNCTION: 받는 즉시 바이트 누적, 목표 도달 시 abort
                    // 이 방식은 progress보다 정확하고 즉각적
                    CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$buffer, $length) {
                        $buffer .= $data;
                        if (strlen($buffer) >= $length) {
                            // 목표 크기 도달 → 정확히 $length만 유지하고 즉시 abort
                            $buffer = substr($buffer, 0, $length);
                            return 0; // curl 중단 (CURLE_WRITE_ERROR 23)
                        }
                        return strlen($data); // 계속 받기
                    },
                ]);
                @curl_exec($ch);
                curl_close($ch);
                // WRITEFUNCTION에서 abort해도 buffer에는 정확한 데이터가 담김
                if ($buffer !== '') {
                    return $buffer;
                }
            }
        }
        
        // 폴백: PHP FTP 확장 - non-blocking으로 청크 다운받고 중단
        // FTP_AUTORESUME + REST 명령으로 offset 지정
        $temp = tmpfile();
        $tempPath = stream_get_meta_data($temp)['uri'];
        
        // ftp_nb_fget을 사용하여 청크별로 받다가 length 도달 시 중단
        $result = @ftp_nb_fget($this->connection, $temp, $fullPath, FTP_BINARY, $offset);
        $downloaded = 0;
        $fallbackStart = microtime(true);
        $fallbackTimeout = 5; // 5초 초과 시 포기 (FTP 서버 응답 느림 방지)
        while ($result === FTP_MOREDATA && $downloaded < $length) {
            // 타임아웃 체크
            if ((microtime(true) - $fallbackStart) > $fallbackTimeout) {
                break;
            }
            $stat = @fstat($temp);
            $downloaded = $stat['size'] ?? 0;
            if ($downloaded >= $length) break;
            $result = @ftp_nb_continue($this->connection);
        }
        
        // 받은 만큼 읽기
        @fseek($temp, 0);
        $content = @fread($temp, $length);
        fclose($temp);
        
        return $content !== false ? $content : '';
    }
    
    /** FTP 파일을 php://output으로 직접 스트리밍 (curl 우선, 대용량 안정) */
    public function streamToOutput(string $path): bool {
        if (!$this->connection && !$this->_doFtpConnect()) return false;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        // curl 우선 사용 (대용량 FTP 다운로드 안정)
        if (function_exists('curl_init')) {
            $result = $this->_curlFtpStream($fullPath);
            if ($result) return true;
        }
        
        // 폴백: PHP FTP 확장 ftp_fget
        $output = fopen('php://output', 'wb');
        if (!$output) return false;
        
        $result = @ftp_fget($this->connection, $output, $fullPath, FTP_BINARY);
        fclose($output);
        
        return $result;
    }
    
    /** curl로 FTP 파일 내용 가져오기 (소용량) */
    /** curl로 FTP 디렉토리 목록 조회 (Passive Mode 안정)
     * @return array|false
     */
    private function _curlFtpList(string $remotePath, string $relativePath) {
        $url = $this->_buildFtpUrl(rtrim($remotePath, '/') . '/');
        if (!$url) return false;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FTPLISTONLY => false, // 상세 목록 (LIST)
            CURLOPT_FTP_SSL => CURLFTPSSL_TRY,
        ]);
        
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        
        if ($errno !== 0 || $result === false || trim($result) === '') return false;
        
        $items = [];
        $lines = explode("\n", trim($result));
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parsed = $this->parseFtpRawLine($line, $relativePath);
            if ($parsed) $items[] = $parsed;
        }
        
        return count($items) > 0 ? $items : false;
    }
    
    /** @return string|false */
    private function _curlFtpGet(string $remotePath) {
        $url = $this->_buildFtpUrl($remotePath);
        if (!$url) return false;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FTP_SSL => CURLFTPSSL_TRY,
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
        ]);
        
        $content = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        
        return ($errno === 0 && $content !== false) ? $content : false;
    }
    
    /** curl로 FTP 파일을 php://output에 직접 스트리밍 (대용량) */
    private function _curlFtpStream(string $remotePath): bool {
        $url = $this->_buildFtpUrl($remotePath);
        if (!$url) return false;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 0, // 무제한 (대용량 파일)
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FTP_SSL => CURLFTPSSL_TRY,
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
            CURLOPT_BUFFERSIZE => 65536, // 64KB 버퍼
            // 청크 단위 출력 + 클라이언트 연결 끊김 감지
            CURLOPT_WRITEFUNCTION => function($ch, $data) {
                if (connection_aborted()) return 0; // 연결 끊김 → curl 중단
                echo $data;
                flush();
                return strlen($data);
            }
        ]);
        
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        
        // CURLE_WRITE_ERROR(23)은 클라이언트 연결 끊김으로 발생 → 정상 처리
        return ($errno === 0 || $errno === 23) && $result !== false;
    }
    
    /** FTP curl URL 생성 */
    private function _buildFtpUrl(string $remotePath): string {
        $host = $this->config['host'] ?? '';
        $port = (int)($this->config['port'] ?? 21);
        $user = rawurlencode($this->config['username'] ?? $this->config['user'] ?? '');
        $pass = rawurlencode($this->config['password'] ?? $this->config['pass'] ?? '');
        $ssl = !empty($this->config['ssl']);
        
        if (empty($host)) return '';
        
        $scheme = $ssl ? 'ftps' : 'ftp';
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $remotePath)));
        
        return "{$scheme}://{$user}:{$pass}@{$host}:{$port}{$encodedPath}";
    }
    
    public function write(string $path, string $content): bool {
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        // curl 우선 (Passive Mode 안정)
        if (function_exists('curl_init')) {
            $url = $this->_buildFtpUrl($fullPath);
            if ($url) {
                $temp = tmpfile();
                fwrite($temp, $content);
                rewind($temp);
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_UPLOAD => true,
                    CURLOPT_INFILE => $temp,
                    CURLOPT_INFILESIZE => strlen($content),
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FTP_SSL => CURLFTPSSL_TRY,
                    CURLOPT_FTP_CREATE_MISSING_DIRS => true,
                ]);
                
                $result = curl_exec($ch);
                $errno = curl_errno($ch);
                curl_close($ch);
                fclose($temp);
                
                if ($errno === 0 && $result !== false) return true;
            }
        }
        
        // 폴백: PHP FTP 확장
        if (!$this->connection && !$this->_doFtpConnect()) return false;
        
        $temp = tmpfile();
        fwrite($temp, $content);
        rewind($temp);
        
        $result = @ftp_fput($this->connection, $fullPath, $temp, FTP_BINARY);
        fclose($temp);
        
        return $result;
    }
    
    public function delete(string $path): bool {
        if (!$this->connection && !$this->_doFtpConnect()) return false;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        if ($this->isDir($path)) {
            return @ftp_rmdir($this->connection, $fullPath);
        }
        return @ftp_delete($this->connection, $fullPath);
    }
    
    public function mkdir(string $path): bool {
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        // curl 우선
        if (function_exists('curl_init')) {
            $url = $this->_buildFtpUrl(rtrim($fullPath, '/') . '/');
            if ($url) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_FTP_CREATE_MISSING_DIRS => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FTP_SSL => CURLFTPSSL_TRY,
                    CURLOPT_QUOTE => ['MKD ' . $fullPath],
                ]);
                curl_exec($ch);
                $errno = curl_errno($ch);
                curl_close($ch);
                if ($errno === 0) return true;
            }
        }
        
        // 폴백: PHP FTP 확장
        if (!$this->connection && !$this->_doFtpConnect()) return false;
        return @ftp_mkdir($this->connection, $fullPath) !== false;
    }
    
    public function rename(string $from, string $to): bool {
        if (!$this->connection && !$this->_doFtpConnect()) return false;
        
        $root = $this->config['root'] ?? '/';
        $fromPath = rtrim($root, '/') . '/' . ltrim($from, '/');
        $toPath = rtrim($root, '/') . '/' . ltrim($to, '/');
        
        return @ftp_rename($this->connection, $fromPath, $toPath);
    }
    
    public function exists(string $path): bool {
        if (!$this->connection && !$this->_doFtpConnect()) return false;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        // ftp_size >= 0 이면 파일로 존재
        if (@ftp_size($this->connection, $fullPath) >= 0) return true;
        
        // 디렉토리 존재 확인: chdir 시도
        $currentDir = @ftp_pwd($this->connection);
        if (@ftp_chdir($this->connection, $fullPath)) {
            @ftp_chdir($this->connection, $currentDir);
            return true;
        }
        return false;
    }
    
    public function isDir(string $path): bool {
        if (!$this->connection && !$this->_doFtpConnect()) return false;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        // ftp_size >= 0 이면 파일
        if (@ftp_size($this->connection, $fullPath) >= 0) return false;
        
        // ftp_chdir로 디렉토리 확인 (빈 디렉토리도 정확히 판별)
        $currentDir = @ftp_pwd($this->connection);
        if (@ftp_chdir($this->connection, $fullPath)) {
            @ftp_chdir($this->connection, $currentDir); // 원래 디렉토리 복원
            return true;
        }
        return false;
    }
    
    public function getSize(string $path): int {
        if (!$this->connection && !$this->_doFtpConnect()) return 0;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        return max(0, @ftp_size($this->connection, $fullPath));
    }
    
    public function getMime(string $path): string {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = [
            'txt' => 'text/plain', 'htm' => 'text/html', 'html' => 'text/html',
            'css' => 'text/css', 'js' => 'application/javascript',
            'json' => 'application/json', 'xml' => 'application/xml',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'mp4' => 'video/mp4',
            'webm' => 'video/webm', 'pdf' => 'application/pdf',
            'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed'
        ];
        return $mimes[$ext] ?? 'application/octet-stream';
    }
    
    public function getModified(string $path): int {
        if (!$this->connection && !$this->_doFtpConnect()) return 0;
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        return @ftp_mdtm($this->connection, $fullPath);
    }
}

/**
 * SFTP 어댑터 (ssh2 확장 필요)
 */
class SftpAdapter implements StorageAdapterInterface {
    private $connection = null;   // ssh2 연결 (fallback용)
    private $sftp = null;         // ssh2_sftp 리소스 (fallback용)
    private $seclib = null;       // phpseclib3 SFTP 인스턴스
    private bool $useSeclib = false;
    private array $config;
    private string $lastError = '';
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    public function getLastError(): string {
        return $this->lastError;
    }
    
    public function connect(): bool {
        $host = $this->config['host'] ?? '';
        $port = $this->config['port'] ?? 22;
        $connectTimeout = (int)($this->config['connect_timeout'] ?? 10);
        
        if (empty($host)) {
            $this->lastError = __('api_err_sftp_no_host', 'SFTP 호스트가 지정되지 않았습니다.');
            return false;
        }
        
        // 소켓 레벨 사전 체크
        $sock = @fsockopen($host, $port, $errno, $errstr, $connectTimeout);
        if (!$sock) {
            $this->lastError = __f('sftp_connect_fail', ['host' => $host, 'port' => $port]) 
                . " (Timeout: {$connectTimeout}s, Error: {$errstr})";
            return false;
        }
        @fclose($sock);
        
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';
        $authType = $this->config['auth_type'] ?? 'password';
        
        // phpseclib3 우선 시도
        if ($this->connectSeclib($host, $port, $username, $password, $authType, $connectTimeout)) {
            $this->useSeclib = true;
            return true;
        }
        
        // fallback: php-ssh2 확장
        return $this->connectSsh2($host, $port, $username, $password, $authType);
    }
    
    private function connectSeclib(string $host, int $port, string $username, string $password, string $authType, int $timeout): bool {
        // autoloader 로드
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload)) return false;
        require_once $autoload;
        
        if (!class_exists('\\phpseclib3\\Net\\SFTP')) return false;
        
        try {
            $sftp = new \phpseclib3\Net\SFTP($host, $port, $timeout);
            
            if ($authType === 'key') {
                $privateKey = $this->config['private_key'] ?? '';
                $passphrase = $this->config['key_passphrase'] ?? false;
                $key = \phpseclib3\Crypt\PublicKeyLoader::load($privateKey, $passphrase);
                if (!$sftp->login($username, $key)) {
                    return false;
                }
            } else {
                if (!$sftp->login($username, $password)) {
                    return false;
                }
            }
            
            $this->seclib = $sftp;
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    private function connectSsh2(string $host, int $port, string $username, string $password, string $authType): bool {
        if (!function_exists('ssh2_connect')) {
            $this->lastError = __('api_err_sftp_no_ssh2', 'SFTP 연결에 필요한 php-ssh2 확장이 설치되지 않았습니다. 서버 관리자에게 문의하세요.');
            return false;
        }
        
        $this->connection = @ssh2_connect($host, $port);
        if (!$this->connection) {
            $this->lastError = __f('sftp_connect_fail', ['host' => $host, 'port' => $port]);
            return false;
        }
        
        if ($authType === 'key') {
            $privateKey = $this->config['private_key'] ?? '';
            $tempKey = tempnam(sys_get_temp_dir(), 'sftp_key');
            file_put_contents($tempKey, $privateKey);
            $result = @ssh2_auth_pubkey_file($this->connection, $username, $tempKey . '.pub', $tempKey);
            @unlink($tempKey);
            if (!$result) {
                $this->lastError = __('sftp_key_auth_fail');
            }
        } else {
            $result = @ssh2_auth_password($this->connection, $username, $password);
            if (!$result) {
                $this->lastError = __('sftp_auth_fail');
            }
        }
        
        if (!$result) {
            $this->disconnect();
            return false;
        }
        
        $this->sftp = @ssh2_sftp($this->connection);
        if ($this->sftp === false) {
            $this->lastError = __('sftp_session_fail');
            return false;
        }
        return true;
    }
    
    public function disconnect(): void {
        if ($this->seclib) {
            try { $this->seclib->disconnect(); } catch (\Throwable $e) {}
            $this->seclib = null;
        }
        $this->sftp = null;
        $this->connection = null;
        $this->useSeclib = false;
    }
    
    // ===== 경로 헬퍼 =====
    
    private function getRawPath(string $path): string {
        $root = $this->config['root'] ?? '/';
        return rtrim($root, '/') . '/' . ltrim($path, '/');
    }
    
    private function getSftpPath(string $path): string {
        $fullPath = $this->getRawPath($path);
        $sftpId = intval($this->sftp);
        $encodedPath = str_replace(['#', '?'], ['%23', '%3F'], $fullPath);
        return "ssh2.sftp://{$sftpId}{$encodedPath}";
    }
    
    // ===== 파일 작업 (phpseclib3 우선) =====
    
    public function list(string $path): array {
        $rawPath = $this->getRawPath($path);
        
        // phpseclib3
        if ($this->useSeclib && $this->seclib) {
            $entries = $this->seclib->rawlist($rawPath);
            if (!is_array($entries)) return [];
            
            $items = [];
            foreach ($entries as $name => $attrs) {
                if ($name === '.' || $name === '..') continue;
                $isDir = ($attrs['type'] ?? 0) === 2;
                $items[] = [
                    'name' => $name,
                    'path' => ltrim($path . '/' . $name, '/'),
                    'is_dir' => $isDir,
                    'size' => $isDir ? 0 : ($attrs['size'] ?? 0),
                    'modified' => $attrs['mtime'] ?? 0
                ];
            }
            return $items;
        }
        
        // ssh2 확장 fallback
        if (!$this->sftp) return [];
        
        if (!preg_match('/[#?]/', $rawPath)) {
            $handle = @opendir($this->getSftpPath($path));
            if ($handle) {
                return $this->readDirItems($handle, $path);
            }
        }
        
        $items = $this->listViaSymlink($path);
        if (!empty($items) || !$this->connection) return $items;
        return $this->listViaSshExec($path);
    }
    
    public function read(string $path): string {
        $rawPath = $this->getRawPath($path);
        
        if ($this->useSeclib && $this->seclib) {
            $result = $this->seclib->get($rawPath);
            return $result !== false ? $result : '';
        }
        
        if (!$this->sftp) return '';
        
        if (!preg_match('/[#?]/', $rawPath)) {
            $result = @file_get_contents($this->getSftpPath($path));
            if ($result !== false) return $result;
        }
        
        return $this->readViaSymlink($path);
    }
    
    /**
     * 파일의 일부만 읽기 (MP3 헤더 파싱용 - 데이터 전송량 최소화)
     * @param string $path 파일 경로
     * @param int $offset 시작 위치 (bytes)
     * @param int $length 읽을 길이 (bytes)
     * @return string 읽은 데이터 (빈 문자열이면 실패)
     */
    public function readPartial(string $path, int $offset = 0, int $length = 65536): string {
        $rawPath = $this->getRawPath($path);
        
        if ($this->useSeclib && $this->seclib) {
            // phpseclib의 get은 offset, length 지원
            $result = $this->seclib->get($rawPath, false, $offset, $length);
            return $result !== false ? $result : '';
        }
        
        if (!$this->sftp) return '';
        
        // ssh2 fallback: fopen + fseek + fread
        if (!preg_match('/[#?]/', $rawPath)) {
            $sftpPath = $this->getSftpPath($path);
            $fp = @fopen($sftpPath, 'r');
            if ($fp) {
                if ($offset > 0) @fseek($fp, $offset);
                $data = @fread($fp, $length);
                @fclose($fp);
                return $data !== false ? $data : '';
            }
        }
        
        return '';
    }
    
    public function write(string $path, string $content): bool {
        $rawPath = $this->getRawPath($path);
        
        if ($this->useSeclib && $this->seclib) {
            return $this->seclib->put($rawPath, $content) !== false;
        }
        
        if (!$this->sftp) return false;
        
        if (!preg_match('/[#?]/', $rawPath)) {
            $result = @file_put_contents($this->getSftpPath($path), $content);
            if ($result !== false) return true;
        }
        
        return $this->writeViaSymlink($path, $content);
    }
    
    public function delete(string $path): bool {
        $rawPath = $this->getRawPath($path);
        
        if ($this->useSeclib && $this->seclib) {
            if ($this->seclib->is_dir($rawPath)) {
                return $this->seclib->rmdir($rawPath);
            }
            return $this->seclib->delete($rawPath);
        }
        
        if (!$this->sftp) return false;
        $stat = @ssh2_sftp_stat($this->sftp, $rawPath);
        $isDir = $stat ? (($stat['mode'] & 0040000) !== 0) : false;
        if ($isDir) {
            return @ssh2_sftp_rmdir($this->sftp, $rawPath);
        }
        return @ssh2_sftp_unlink($this->sftp, $rawPath);
    }
    
    public function mkdir(string $path): bool {
        $rawPath = $this->getRawPath($path);
        
        if ($this->useSeclib && $this->seclib) {
            return $this->seclib->mkdir($rawPath, -1, true);
        }
        
        if (!$this->sftp) return false;
        return @ssh2_sftp_mkdir($this->sftp, $rawPath, 0755, true);
    }
    
    public function rename(string $from, string $to): bool {
        if ($this->useSeclib && $this->seclib) {
            return $this->seclib->rename($this->getRawPath($from), $this->getRawPath($to));
        }
        
        if (!$this->sftp) return false;
        return @ssh2_sftp_rename($this->sftp, $this->getRawPath($from), $this->getRawPath($to));
    }
    
    public function exists(string $path): bool {
        $rawPath = $this->getRawPath($path);
        
        if ($this->useSeclib && $this->seclib) {
            return $this->seclib->stat($rawPath) !== false;
        }
        
        if (!$this->sftp) return false;
        return @ssh2_sftp_stat($this->sftp, $rawPath) !== false;
    }
    
    public function isDir(string $path): bool {
        $rawPath = $this->getRawPath($path);
        
        if ($this->useSeclib && $this->seclib) {
            return $this->seclib->is_dir($rawPath);
        }
        
        if (!$this->sftp) return false;
        $stat = @ssh2_sftp_stat($this->sftp, $rawPath);
        return $stat ? (($stat['mode'] & 0040000) !== 0) : false;
    }
    
    public function streamToOutput(string $path): bool {
        $rawPath = $this->getRawPath($path);
        
        // phpseclib3: 임시 파일 경유 스트리밍 (메모리 절약)
        if ($this->useSeclib && $this->seclib) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'sftp_');
            if (!$tmpFile) {
                // 임시 파일 실패 시 직접 읽기 폴백
                $content = $this->seclib->get($rawPath);
                if ($content === false) return false;
                echo $content;
                return true;
            }
            // phpseclib3 get(remote, localPath): 파일에 직접 저장
            $result = $this->seclib->get($rawPath, $tmpFile);
            if ($result === false) {
                @unlink($tmpFile);
                return false;
            }
            // 임시 파일을 청크 단위로 출력
            $fh = @fopen($tmpFile, 'rb');
            if ($fh) {
                while (!feof($fh)) {
                    echo fread($fh, 1024 * 1024);
                    if (connection_aborted()) break;
                    flush();
                }
                fclose($fh);
            }
            @unlink($tmpFile);
            return true;
        }
        
        // ssh2 확장: stream wrapper로 청크 스트리밍
        if ($this->sftp) {
            $sftpPath = $this->getSftpPath($path);
            $stream = @fopen($sftpPath, 'rb');
            if ($stream) {
                while (!feof($stream)) {
                    echo fread($stream, 1024 * 1024);
                    if (connection_aborted()) break;
                    flush();
                }
                fclose($stream);
                return true;
            }
        }
        
        return false;
    }
    
    public function getSize(string $path): int {
        $rawPath = $this->getRawPath($path);
        
        if ($this->useSeclib && $this->seclib) {
            $stat = $this->seclib->stat($rawPath);
            return $stat['size'] ?? 0;
        }
        
        if (!$this->sftp) return 0;
        $stat = @ssh2_sftp_stat($this->sftp, $rawPath);
        return $stat['size'] ?? 0;
    }
    
    public function getMime(string $path): string {
        return (new FtpAdapter([]))->getMime($path);
    }
    
    public function getModified(string $path): int {
        $rawPath = $this->getRawPath($path);
        
        if ($this->useSeclib && $this->seclib) {
            $stat = $this->seclib->stat($rawPath);
            return $stat['mtime'] ?? 0;
        }
        
        if (!$this->sftp) return 0;
        $stat = @ssh2_sftp_stat($this->sftp, $rawPath);
        return $stat['mtime'] ?? 0;
    }
    
    // ===== ssh2 확장 fallback 헬퍼 =====
    
    private function listViaSymlink(string $path): array {
        if (!$this->sftp) return [];
        $rawPath = $this->getRawPath($path);
        $sftpId = intval($this->sftp);
        $linkName = '/tmp/.fs_symlink_' . md5($rawPath . microtime(true) . mt_rand());
        
        if (!@ssh2_sftp_symlink($this->sftp, $rawPath, $linkName)) {
            return [];
        }
        
        $handle = @opendir("ssh2.sftp://{$sftpId}{$linkName}");
        @ssh2_sftp_unlink($this->sftp, $linkName);
        
        if (!$handle) return [];
        return $this->readDirItems($handle, $path);
    }
    
    private function listViaSshExec(string $path): array {
        if (!$this->connection) return [];
        $rawPath = $this->getRawPath($path);
        $escaped = escapeshellarg($rawPath);
        $stream = @ssh2_exec($this->connection, "ls -1a {$escaped} 2>&1");
        
        if (!$stream) {
            return [];
        }
        
        stream_set_blocking($stream, true);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        if ($output === false || trim($output) === '' || strpos($output, 'No such file') !== false) {
            return [];
        }
        
        $entries = explode("\n", trim($output));
        $items = [];
        $rawBase = $this->getRawPath($path);
        foreach ($entries as $item) {
            $item = trim($item);
            if ($item === '' || $item === '.' || $item === '..') continue;
            $itemRawPath = rtrim($rawBase, '/') . '/' . $item;
            $stat = @ssh2_sftp_stat($this->sftp, $itemRawPath);
            $isDir = $stat ? (($stat['mode'] & 0040000) !== 0) : false;
            $items[] = [
                'name' => $item,
                'path' => ltrim($path . '/' . $item, '/'),
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : ($stat['size'] ?? 0),
                'modified' => $stat['mtime'] ?? 0
            ];
        }
        return $items;
    }
    
    private function readDirItems($handle, string $path): array {
        $rawBase = $this->getRawPath($path);
        $items = [];
        while (($item = readdir($handle)) !== false) {
            if ($item === '.' || $item === '..') continue;
            $itemRawPath = rtrim($rawBase, '/') . '/' . $item;
            $stat = @ssh2_sftp_stat($this->sftp, $itemRawPath);
            $isDir = $stat ? (($stat['mode'] & 0040000) !== 0) : false;
            $items[] = [
                'name' => $item,
                'path' => ltrim($path . '/' . $item, '/'),
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : ($stat['size'] ?? 0),
                'modified' => $stat['mtime'] ?? 0
            ];
        }
        closedir($handle);
        return $items;
    }
    
    private function readViaSymlink(string $path): string {
        if (!$this->sftp) return '';
        $rawPath = $this->getRawPath($path);
        $sftpId = intval($this->sftp);
        $linkName = '/tmp/.fs_read_' . md5($rawPath . microtime(true) . mt_rand());
        
        if (!@ssh2_sftp_symlink($this->sftp, $rawPath, $linkName)) {
            return '';
        }
        
        $result = @file_get_contents("ssh2.sftp://{$sftpId}{$linkName}");
        @ssh2_sftp_unlink($this->sftp, $linkName);
        return $result ?: '';
    }
    
    private function writeViaSymlink(string $path, string $content): bool {
        if (!$this->sftp) return false;
        $rawPath = $this->getRawPath($path);
        $sftpId = intval($this->sftp);
        $dir = dirname($rawPath);
        $filename = basename($rawPath);
        $linkName = '/tmp/.fs_write_' . md5($dir . microtime(true) . mt_rand());
        
        if (!@ssh2_sftp_symlink($this->sftp, $dir, $linkName)) {
            @ssh2_sftp_unlink($this->sftp, $linkName);
            return false;
        }
        
        $result = @file_put_contents("ssh2.sftp://{$sftpId}{$linkName}/{$filename}", $content);
        @ssh2_sftp_unlink($this->sftp, $linkName);
        return $result !== false;
    }
}

/**
 * WebDAV 어댑터
 */
class WebDavAdapter implements StorageAdapterInterface {
    private array $config;
    private string $lastError = '';
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    public function getLastError(): string {
        return $this->lastError;
    }
    
    private function request(string $method, string $path, array $options = []): array {
        // 경로의 각 세그먼트를 URL 인코딩 (특수문자 #, ?, @ 등 안전 처리)
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url = rtrim($this->config['url'] ?? '', '/') . '/' . ltrim($encodedPath, '/');
        
        if (!function_exists('curl_init')) {
            $this->lastError = __('api_err_webdav_no_curl', 'WebDAV 연결에 필요한 cURL 확장이 설치되지 않았습니다.');
            return ['code' => 0, 'body' => ''];
        }
        
        $connectTimeout = (int)($this->config['connect_timeout'] ?? 10);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => ($this->config['username'] ?? '') . ':' . ($this->config['password'] ?? ''),
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => 30
        ]);
        
        if (!empty($options['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
        }
        if (!empty($options['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $this->lastError = __('webdav_connect_error') . $curlError;
        }
        
        return ['code' => $httpCode, 'body' => $response];
    }
    
    public function connect(): bool {
        $url = $this->config['url'] ?? '';
        if (empty($url)) {
            $this->lastError = __('api_err_webdav_no_url', 'WebDAV URL이 지정되지 않았습니다.');
            return false;
        }
        
        $result = $this->request('PROPFIND', '', ['headers' => ['Depth: 0']]);
        
        if ($result['code'] === 0) {
            return false; // lastError는 request에서 설정됨
        }
        if ($result['code'] === 401) {
            $this->lastError = __('webdav_auth_fail');
            return false;
        }
        if ($result['code'] < 200 || $result['code'] >= 400) {
            $this->lastError = __f('webdav_server_error', ['code' => $result['code']]);
            return false;
        }
        
        return true;
    }
    
    public function disconnect(): void {}
    
    public function list(string $path): array {
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 1']]);
        if ($result['code'] !== 207) return [];
        
        $items = [];
        $xml = @simplexml_load_string($result['body']);
        if (!$xml) return [];
        
        $xml->registerXPathNamespace('d', 'DAV:');
        foreach ($xml->xpath('//d:response') as $response) {
            $href = (string)$response->xpath('d:href')[0];
            $props = $response->xpath('d:propstat/d:prop')[0];
            
            $name = basename(urldecode($href));
            if (empty($name) || $name === basename($path)) continue;
            
            $isDir = !empty($props->xpath('d:resourcetype/d:collection'));
            $size = (int)($props->xpath('d:getcontentlength')[0] ?? 0);
            $modified = strtotime((string)($props->xpath('d:getlastmodified')[0] ?? ''));
            
            $items[] = [
                'name' => $name,
                'path' => ltrim($path . '/' . $name, '/'),
                'is_dir' => $isDir,
                'size' => $size,
                'modified' => $modified
            ];
        }
        
        return $items;
    }
    
    public function read(string $path): string {
        $result = $this->request('GET', $path);
        return $result['code'] === 200 ? $result['body'] : '';
    }
    
    /**
     * HTTP Range 요청으로 부분 읽기 (MP3 헤더 파싱용)
     * 주의: range 미지원 서버는 전체 파일을 보내므로 조기 skip
     */
    public function readPartial(string $path, int $offset = 0, int $length = 65536): string {
        // 서버가 range를 무시하는 것으로 알려져 있으면 skip (대역폭 낭비 방지)
        if (!empty($this->_rangeUnsupported)) return '';
        
        $end = $offset + $length - 1;
        $result = $this->request('GET', $path, ['headers' => ["Range: bytes=$offset-$end"]]);
        if ($result['code'] === 206) return $result['body'] ?? '';
        if ($result['code'] === 200) {
            // 서버가 range 무시 → 이후 호출은 skip
            $this->_rangeUnsupported = true;
            return '';
        }
        return '';
    }
    
    public function write(string $path, string $content): bool {
        $result = $this->request('PUT', $path, ['body' => $content]);
        return $result['code'] >= 200 && $result['code'] < 300;
    }
    
    public function delete(string $path): bool {
        $result = $this->request('DELETE', $path);
        return $result['code'] >= 200 && $result['code'] < 300;
    }
    
    public function mkdir(string $path): bool {
        $result = $this->request('MKCOL', $path);
        return $result['code'] === 201;
    }
    
    public function rename(string $from, string $to): bool {
        // 경로의 각 세그먼트를 URL 인코딩 (특수문자 안전 처리)
        $encodedTo = implode('/', array_map('rawurlencode', explode('/', ltrim($to, '/'))));
        $destUrl = rtrim($this->config['url'] ?? '', '/') . '/' . $encodedTo;
        $result = $this->request('MOVE', $from, ['headers' => ["Destination: $destUrl"]]);
        return $result['code'] >= 200 && $result['code'] < 300;
    }
    
    public function exists(string $path): bool {
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 0']]);
        return $result['code'] === 207;
    }
    
    public function isDir(string $path): bool {
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 0']]);
        if ($result['code'] !== 207) return false;
        $xml = @simplexml_load_string($result['body']);
        if (!$xml) return false;
        $xml->registerXPathNamespace('d', 'DAV:');
        return !empty($xml->xpath('//d:resourcetype/d:collection'));
    }
    
    public function getSize(string $path): int {
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 0']]);
        if ($result['code'] !== 207) return 0;
        $xml = @simplexml_load_string($result['body']);
        if (!$xml) return 0;
        $xml->registerXPathNamespace('d', 'DAV:');
        return (int)($xml->xpath('//d:getcontentlength')[0] ?? 0);
    }
    
    public function getMime(string $path): string {
        return (new FtpAdapter([]))->getMime($path);
    }
    
    public function getModified(string $path): int {
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 0']]);
        if ($result['code'] !== 207) return 0;
        $xml = @simplexml_load_string($result['body']);
        if (!$xml) return 0;
        $xml->registerXPathNamespace('d', 'DAV:');
        return strtotime((string)($xml->xpath('//d:getlastmodified')[0] ?? ''));
    }
}

/**
 * S3 어댑터 (S3 호환 스토리지)
 */
class S3Adapter implements StorageAdapterInterface {
    private array $config;
    private string $lastError = '';
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    public function getLastError(): string {
        return $this->lastError;
    }
    
    private function sign(string $method, string $uri, array $headers, string $payload = ''): array {
        $accessKey = $this->config['access_key'] ?? '';
        $secretKey = $this->config['secret_key'] ?? '';
        $region = $this->config['region'] ?? 'us-east-1';
        $service = 's3';
        
        $date = gmdate('Ymd\THis\Z');
        $dateShort = gmdate('Ymd');
        
        $headers['x-amz-date'] = $date;
        $headers['x-amz-content-sha256'] = hash('sha256', $payload);
        
        // URI 인코딩 (AWS Signature V4: canonical URI는 URI-encode 필요)
        $encodedUri = implode('/', array_map('rawurlencode', explode('/', $uri)));
        
        // Canonical request
        ksort($headers);
        $signedHeaders = implode(';', array_map('strtolower', array_keys($headers)));
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
        }
        
        $canonicalRequest = implode("\n", [
            $method,
            $encodedUri,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $headers['x-amz-content-sha256']
        ]);
        
        // String to sign
        $scope = "$dateShort/$region/$service/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $date,
            $scope,
            hash('sha256', $canonicalRequest)
        ]);
        
        // Signing key
        $kDate = hash_hmac('sha256', $dateShort, "AWS4$secretKey", true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        
        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, SignedHeaders=$signedHeaders, Signature=$signature";
        
        return $headers;
    }
    
    private function request(string $method, string $path, string $body = '', array $query = []): array {
        $endpoint = $this->config['endpoint'] ?? 's3.amazonaws.com';
        $bucket = $this->config['bucket'] ?? '';
        $prefix = $this->config['prefix'] ?? '';
        
        $fullPath = '/' . $bucket . '/' . ltrim($prefix . $path, '/');
        // 경로의 각 세그먼트를 URL 인코딩 (특수문자 안전 처리, /는 유지)
        $encodedFullPath = implode('/', array_map('rawurlencode', explode('/', $fullPath)));
        $uri = 'https://' . $endpoint . $encodedFullPath;
        
        if (!empty($query)) {
            $uri .= '?' . http_build_query($query);
        }
        
        $headers = ['Host' => $endpoint];
        $headers = $this->sign($method, $fullPath, $headers, $body);
        
        $ch = curl_init($uri);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_TIMEOUT => 30
        ]);
        
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ['code' => $httpCode, 'body' => $response];
    }
    
    public function connect(): bool {
        $bucket = $this->config['bucket'] ?? '';
        $accessKey = $this->config['access_key'] ?? '';
        
        if (empty($bucket)) {
            $this->lastError = __('api_err_s3_no_bucket', 'S3 버킷 이름이 지정되지 않았습니다.');
            return false;
        }
        if (empty($accessKey)) {
            $this->lastError = __('api_err_s3_no_access_key', 'S3 Access Key가 지정되지 않았습니다.');
            return false;
        }
        
        $result = $this->request('GET', '', '', ['list-type' => '2', 'max-keys' => '1']);
        
        if ($result['code'] === 403) {
            $this->lastError = __('s3_access_denied');
            return false;
        }
        if ($result['code'] === 404) {
            $this->lastError = __('s3_bucket_not_found') . $bucket;
            return false;
        }
        if ($result['code'] !== 200) {
            $this->lastError = __f('s3_connect_error', ['code' => $result['code']]);
            return false;
        }
        
        return true;
    }
    
    public function disconnect(): void {}
    
    public function list(string $path): array {
        $prefix = ltrim($path, '/');
        if (!empty($prefix) && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }
        
        $result = $this->request('GET', '', '', [
            'list-type' => '2',
            'prefix' => $prefix,
            'delimiter' => '/'
        ]);
        
        if ($result['code'] !== 200) return [];
        
        $items = [];
        $xml = @simplexml_load_string($result['body']);
        if (!$xml) return [];
        
        // 폴더
        foreach ($xml->CommonPrefixes ?? [] as $cp) {
            $key = (string)$cp->Prefix;
            $name = basename(rtrim($key, '/'));
            $items[] = [
                'name' => $name,
                'path' => rtrim($key, '/'),
                'is_dir' => true,
                'size' => 0,
                'modified' => 0
            ];
        }
        
        // 파일
        foreach ($xml->Contents ?? [] as $content) {
            $key = (string)$content->Key;
            if ($key === $prefix) continue;
            $name = basename($key);
            $items[] = [
                'name' => $name,
                'path' => $key,
                'is_dir' => false,
                'size' => (int)$content->Size,
                'modified' => strtotime((string)$content->LastModified)
            ];
        }
        
        return $items;
    }
    
    public function read(string $path): string {
        $result = $this->request('GET', $path);
        return $result['code'] === 200 ? $result['body'] : '';
    }
    
    /**
     * S3 Range 요청으로 부분 읽기 (MP3 헤더 파싱용)
     */
    public function readPartial(string $path, int $offset = 0, int $length = 65536): string {
        $end = $offset + $length - 1;
        $endpoint = $this->config['endpoint'] ?? 's3.amazonaws.com';
        $bucket = $this->config['bucket'] ?? '';
        $prefix = $this->config['prefix'] ?? '';
        $fullPath = '/' . $bucket . '/' . ltrim($prefix . $path, '/');
        $encodedFullPath = implode('/', array_map('rawurlencode', explode('/', $fullPath)));
        $uri = 'https://' . $endpoint . $encodedFullPath;
        
        $headers = ['Host' => $endpoint, 'Range' => "bytes=$offset-$end"];
        $headers = $this->sign('GET', $fullPath, $headers, '');
        
        $ch = curl_init($uri);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 206) return $response ?: '';
        if ($httpCode === 200) return substr($response ?: '', $offset, $length);
        return '';
    }
    
    public function write(string $path, string $content): bool {
        $result = $this->request('PUT', $path, $content);
        return $result['code'] === 200;
    }
    
    public function delete(string $path): bool {
        $result = $this->request('DELETE', $path);
        return $result['code'] === 204 || $result['code'] === 200;
    }
    
    public function mkdir(string $path): bool {
        // S3에서는 폴더가 없으므로 빈 객체 생성
        $result = $this->request('PUT', rtrim($path, '/') . '/');
        return $result['code'] === 200;
    }
    
    public function rename(string $from, string $to): bool {
        // S3는 rename이 없으므로 copy + delete
        $content = $this->read($from);
        if ($this->write($to, $content)) {
            return $this->delete($from);
        }
        return false;
    }
    
    public function exists(string $path): bool {
        $result = $this->request('HEAD', $path);
        return $result['code'] === 200;
    }
    
    public function isDir(string $path): bool {
        return str_ends_with($path, '/');
    }
    
    public function getSize(string $path): int {
        // HEAD 요청으로 Content-Length 가져오기
        return 0;
    }
    
    public function getMime(string $path): string {
        return (new FtpAdapter([]))->getMime($path);
    }
    
    public function getModified(string $path): int {
        return 0;
    }
}

/**
 * SMB/CIFS 어댑터 (네트워크 드라이브)
 * UNC 경로 접근 전 소켓 프리체크로 타임아웃 방지
 */
class SmbAdapter implements StorageAdapterInterface {
    private string $basePath;
    private array $config;
    private string $lastError = '';
    private bool $connected = false;
    
    public function __construct(array $config) {
        $this->config = $config;
        $host = $config['host'] ?? '';
        $share = $config['share'] ?? '';
        $subfolder = trim($config['path'] ?? '', '/\\');
        
        // Windows UNC 경로 구성
        $this->basePath = "\\\\{$host}\\{$share}";
        if ($subfolder) {
            $this->basePath .= "\\{$subfolder}";
        }
    }
    
    public function getLastError(): string {
        return $this->lastError;
    }
    
    public function connect(): bool {
        $host = $this->config['host'] ?? '';
        $port = (int)($this->config['port'] ?? 445);
        $connectTimeout = (int)($this->config['connect_timeout'] ?? 5); // SMB는 기본 5초 (보통 로컬 네트워크)
        
        if (empty($host)) {
            $this->lastError = 'SMB 호스트가 지정되지 않았습니다.';
            return false;
        }
        
        // 소켓 레벨 도달 가능성 사전 체크 (빠른 실패)
        $sock = @fsockopen($host, $port, $errno, $errstr, $connectTimeout);
        if (!$sock) {
            $this->lastError = "SMB 서버 연결 실패: {$host}:{$port} (Timeout: {$connectTimeout}s, Error: {$errstr})";
            return false;
        }
        @fclose($sock);
        
        // 실제 UNC 경로 접근 가능 여부 확인
        if (!@is_dir($this->basePath)) {
            $this->lastError = "SMB 공유 폴더 접근 불가: {$this->basePath}";
            return false;
        }
        
        $this->connected = true;
        return true;
    }
    
    public function disconnect(): void {
        $this->connected = false;
    }
    
    private function fullPath(string $path): string {
        // 경로 조작 방어
        $path = str_replace(['../', '..\\', "\0"], '', $path);
        $path = str_replace('/', '\\', ltrim($path, '/\\'));
        return $this->basePath . ($path ? "\\{$path}" : '');
    }
    
    public function list(string $path): array {
        if (!$this->connected) return [];
        
        $dir = $this->fullPath($path);
        $items = [];
        
        $entries = @scandir($dir);
        if ($entries === false) return [];
        
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $dir . '\\' . $entry;
            $isDir = is_dir($full);
            $items[] = [
                'name' => $entry,
                'path' => ltrim($path . '/' . $entry, '/'),
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : (int)@filesize($full),
                'modified' => (int)@filemtime($full)
            ];
        }
        
        return $items;
    }
    
    public function read(string $path): string {
        if (!$this->connected) return '';
        return (string)@file_get_contents($this->fullPath($path));
    }
    
    /**
     * SMB 부분 읽기 (MP3 등 오디오 duration 파싱용)
     * UNC 경로에 대해 fopen+fseek+fread 사용 - Windows 파일시스템 네이티브 지원
     */
    public function readPartial(string $path, int $offset = 0, int $length = 65536): string {
        if (!$this->connected) return '';
        $full = $this->fullPath($path);
        $fp = @fopen($full, 'rb');
        if (!$fp) return '';
        if ($offset > 0) @fseek($fp, $offset);
        $data = @fread($fp, $length);
        @fclose($fp);
        return $data !== false ? $data : '';
    }
    
    public function write(string $path, string $content): bool {
        if (!$this->connected) return false;
        $full = $this->fullPath($path);
        $dir = dirname($full);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return file_put_contents($full, $content) !== false;
    }
    
    public function delete(string $path): bool {
        if (!$this->connected) return false;
        $full = $this->fullPath($path);
        if (is_dir($full)) {
            return @rmdir($full);
        }
        return @unlink($full);
    }
    
    public function mkdir(string $path): bool {
        if (!$this->connected) return false;
        return @mkdir($this->fullPath($path), 0777, true);
    }
    
    public function rename(string $from, string $to): bool {
        if (!$this->connected) return false;
        return @rename($this->fullPath($from), $this->fullPath($to));
    }
    
    public function exists(string $path): bool {
        if (!$this->connected) return false;
        return file_exists($this->fullPath($path));
    }
    
    public function isDir(string $path): bool {
        if (!$this->connected) return false;
        return is_dir($this->fullPath($path));
    }
    
    public function getSize(string $path): int {
        if (!$this->connected) return 0;
        return (int)@filesize($this->fullPath($path));
    }
    
    public function getMime(string $path): string {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo',
            'mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'wav' => 'audio/wav',
            'pdf' => 'application/pdf', 'zip' => 'application/zip',
            'txt' => 'text/plain', 'html' => 'text/html',
        ];
        return $mimes[$ext] ?? 'application/octet-stream';
    }
    
    public function getModified(string $path): int {
        if (!$this->connected) return 0;
        return (int)@filemtime($this->fullPath($path));
    }
}

