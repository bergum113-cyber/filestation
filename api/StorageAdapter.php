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
    private static int $syNameGen = 0;   // ★ (2026-11-11 00:10, 69차 재확인 반영) 이름이 바뀌면 copyFileTo 가 기억한 폴더 이름 목록을 버리게 함
    
    public function __construct(string $basePath) {
        $this->basePath = rtrim($basePath, '/\\');
    }
    
    public function connect(): bool {
        return is_dir($this->basePath);
    }
    
    public function disconnect(): void {}
    
    public bool $listFailed = false;   // ★ (2026-10-29 18:52, 22차 점검) 원격 어댑터와 같은 표시 — 폴더를 읽지 못했으면 true(없는 폴더는 빈 목록, 실패 아님)
    public function list(string $path): array {
        $this->listFailed = false;
        $fullPath = $this->getFullPath($path);
        if (!is_dir($fullPath)) return [];
        
        static $skipDirs = ['$RECYCLE.BIN', 'System Volume Information', '$Recycle.Bin'];
        $items = [];
        $scanned = @scandir($fullPath);
        if ($scanned === false) { $this->listFailed = true; return []; }
        foreach ($scanned as $item) {
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
        self::$syNameGen++;   // ★ (2026-11-11 00:10, 69차 재확인 반영) 이름이 바뀌면 copyFileTo 가 기억한 폴더 이름 목록을 버리게 함
        return file_put_contents($this->getFullPath($path), $content) !== false;
    }
    
    public function delete(string $path): bool {
        self::$syNameGen++;   // ★ (2026-11-11 00:10, 69차 재확인 반영) 이름이 바뀌면 copyFileTo 가 기억한 폴더 이름 목록을 버리게 함
        $fullPath = $this->getFullPath($path);
        if (is_dir($fullPath)) {
            return $this->deleteDir($fullPath);
        }
        return unlink($fullPath);
    }
    
    private function deleteDir(string $dir): bool {
        // ★ (2026-11-04 01:52, 54차 점검) 링크·정션이면 링크만 지우고 안으로 들어가지 않음(Linux 는 unlink, Windows 폴더 링크·정션은 rmdir) — 종전엔 is_dir 가 링크를 따라가
        //   가리키는 곳(스토리지 밖 포함)의 파일을 지웠음(재현: old/lnk → 밖 폴더, 동기화 고아 삭제로 밖 o.txt·deep/d.txt 삭제). 정션은 is_link 가 거짓일 수 있어 실제 경로가 '부모 실제 경로/이름'과 다른지로도 봄
        $isLink = is_link($dir);
        if (!$isLink) {
            $rp = @realpath($dir); $pp = @realpath(dirname($dir));
            if ($rp !== false && $pp !== false) {
                $exp = rtrim($pp, '/\\') . DIRECTORY_SEPARATOR . basename($dir);
                $isLink = (DIRECTORY_SEPARATOR === '\\') ? (strcasecmp(rtrim($rp, '\\'), $exp) !== 0) : (rtrim($rp, '/') !== $exp);
            }
        }
        if ($isLink) return DIRECTORY_SEPARATOR === '\\' ? (@rmdir($dir) || @unlink($dir)) : @unlink($dir);
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
        self::$syNameGen++;   // ★ (2026-11-11 00:10, 69차 재확인 반영) 이름이 바뀌면 copyFileTo 가 기억한 폴더 이름 목록을 버리게 함
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
        // ★ (2026-11-02 10:27, 42차 점검) fileinfo 확장이 없으면 mime_content_type 이 정의되지 않아 치명 오류였음(E5#4) → 그때는 다른 어댑터와 같은 확장자 표로
        if (!function_exists('mime_content_type')) return (new FtpAdapter([]))->getMime($path);
        return @mime_content_type($this->getFullPath($path)) ?: 'application/octet-stream';
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
            // ★ (2026-11-10 16:47, 66차 점검) 대상 자리에 바로 copy 해 디스크가 차는 등 중간 실패 때 잘린 파일이 새 수정 시각으로 남았고,
            //   양방향 다음 실행이 그걸 '대상에서 고침'으로 보고 원본까지 잘린 내용으로 덮었음 → 40차 버전 복원과 같은 방식: 같은 폴더 임시 이름(점으로 시작)에
            //   다 쓴 뒤 rename, 임시 쓰기가 실패하면 대상은 그대로. rename 이 막히면(Windows 열린 파일 등) 종전처럼 바로 copy.
            //   대소문자 무시 파일 시스템에서 대상이 다른 대소문자 이름으로 이미 있으면 그 이름으로 rename(종전 copy 처럼 기존 이름 유지)
            // ★ (2026-11-10 23:50, 69차 재검토) 갱신마다 폴더 전체를 scandir 해 큰 폴더(2만 개)에서 갱신 2천 개가 0.12초 → 24초로 느려졌음
            //   → 마지막으로 본 폴더 하나의 이름 목록만 기억해 다시 씀(정확한 이름·소문자 이름). 기억한 목록과 실제가 맞지 않으면(밖에서 바뀜) 한 번 다시 읽음
            //   (2026-11-11 00:04 재확인 반영) 기억한 목록은 2초만 쓰고, 정확한 이름이 목록에 없으면 다른 대소문자 이름을 고르기 전에 반드시 다시 읽음
            //   — 기억한 옛 이름으로 되돌리는 일(밖에서·앞 작업에서 바뀐 경우) 방지
            static $syCDir = null, $syCAt = 0.0, $syCExact = [], $syCLower = [], $syCGen = -1;
            $syFinal = $fullTgt;
            $syBn = basename($fullTgt);
            if (file_exists($fullTgt)) {
                $syLow = mb_strtolower($syBn, 'UTF-8');
                for ($syTry = 0; $syTry < 2; $syTry++) {
                    if ($syCDir !== $tgtDir || $syTry === 1 || $syCGen !== self::$syNameGen || (microtime(true) - $syCAt) > 2.0) {
                        $syList = @scandir($tgtDir);
                        $syCDir = $tgtDir; $syCAt = microtime(true); $syCGen = self::$syNameGen; $syCExact = []; $syCLower = [];
                        if (is_array($syList)) {
                            foreach ($syList as $syE) { $syCExact[$syE] = true; $syL = mb_strtolower($syE, 'UTF-8'); if (!isset($syCLower[$syL])) $syCLower[$syL] = $syE; }
                        }
                    }
                    if (isset($syCExact[$syBn])) break;
                    if ($syTry === 1 && isset($syCLower[$syLow])) { $syFinal = $tgtDir . DIRECTORY_SEPARATOR . $syCLower[$syLow]; break; }
                }
            }
            $syTmp = $tgtDir . DIRECTORY_SEPARATOR . '.~fssy-' . bin2hex(random_bytes(6));
            $ok = false;
            if (@copy($fullSrc, $syTmp) && @filesize($syTmp) === @filesize($fullSrc)) {
                if (DIRECTORY_SEPARATOR === '/' && file_exists($syFinal)) { $syPerm = @fileperms($syFinal); if ($syPerm !== false) @chmod($syTmp, $syPerm & 0777); }   // ★ (2026-11-10 17:02, 66차 점검) 갱신 뒤 기존 권한(0600 등)이 0644 로 바뀌었음 → 텍스트 저장처럼 기존 권한 유지(Windows 는 읽기 전용만 다뤄 rename·정리가 막힐 수 있어 제외)
                $ok = @rename($syTmp, $syFinal);
                if (!$ok) { @unlink($syTmp); $ok = @copy($fullSrc, $fullTgt); }
                if ($ok && $syCDir === $tgtDir && !isset($syCExact[basename($syFinal)])) { $syCExact[basename($syFinal)] = true; $syL = mb_strtolower(basename($syFinal), 'UTF-8'); if (!isset($syCLower[$syL])) $syCLower[$syL] = basename($syFinal); }
            } elseif (!file_exists($syTmp)) {
                // ★ (2026-11-10 23:50, 69차 재검토) 임시 파일을 아예 못 만들면(폴더는 쓰기 불가·기존 파일만 쓰기 가능 등) 종전처럼 바로 copy — 종전에 되던 갱신이 실패하지 않게
                //   (2026-11-11 00:04 재확인 반영) 단 디스크 여유가 원본 크기보다 클 때만 — 꽉 차서 임시 파일도 못 만든 경우 바로 copy 하면 다시 잘린 파일이 생겼음
                $syFree = @disk_free_space($tgtDir);
                if ($syFree === false || $syFree > (float)@filesize($fullSrc)) $ok = @copy($fullSrc, $fullTgt);
            } else {
                @unlink($syTmp);
            }
            if (!$ok) {
                fsLog("Sync copy FAILED: $fullSrc → $fullTgt");
            }
            return $ok;
        }
        // ★ (2026-11-01 09:45, 38차 점검) 대상이 파일에서 바로 보내기(writeFromFile — FTP·WebDAV)를 지원하면 그걸로 흘려 보냄 — 종전엔 파일 전체를 문자열로 이어 붙여 write() 에 넘겨
        //   큰 파일에서 메모리 초과(Allowed memory size exhausted)로 동기화 전체가 멈췄음. 지원하지 않는 대상은 종전 방식 (64차 15:30: 63차에 SFTP·SMB 도 writeFromFile 이 생겨 흘려 보냄 — 이제 S3 만 종전)
        if (method_exists($target, 'writeFromFile')) {
            // ★ (2026-11-11 08:03, 72차 RMT-1) 원격 대상에 진짜 이름으로 바로 써서, 올리다 끊기면 잘린 파일이 지금 시각으로 남았고 양방향 다음 실행이 그걸 '원격에서 고친 새 파일'로 보고
            //   로컬 원본까지 잘린 내용으로 덮었음(FTP·WebDAV·SFTP 재현) → 이름 바꾸기가 되는 대상(syncRemoteAtomic)은 66차 로컬 대상과 같게 같은 폴더 임시 이름(.~fssy-, 동기화 목록에서 건너뜀)에
            //   다 쓴 뒤 rename. 임시 쓰기가 실패하면 임시만 지우고(끊겼으면 못 지울 수 있음 — 목록에서 건너뛰므로 되쓰이지 않음) 대상은 그대로.
            //   rename 이 기존 파일 위로 안 되는 서버(OpenSSH SFTP 류)는 기존 것을 지우고 다시 rename, 그래도 안 되면 임시를 지우고 종전처럼 바로 씀(정상 동기화가 실패하지 않게).
            //   이름 바꾸기가 안 되는 대상(FTP·SMB — 72차 재검토 반영으로 FTP 는 확장이 있어도 여기)은 종전처럼 바로 쓰고, 양방향 호출부가 실패한 키를 다음 한 번 로컬 우선으로 표시(syncRemoteDirect)
            if (self::syncRemoteAtomic($target)) {
                $syRd = dirname(str_replace('\\', '/', $tgtPath));
                $syRt = (($syRd === '.' || $syRd === '' || $syRd === '/') ? '' : rtrim($syRd, '/') . '/') . '.~fssy-' . bin2hex(random_bytes(6));
                try { $syW = $target->writeFromFile($syRt, $fullSrc); }
                catch (\Throwable $e) { try { $target->delete($syRt); } catch (\Throwable $e2) {} throw $e; }   // SFTP(phpseclib)는 끊기면 예외 — 종전과 같은 오류 문구로 올림
                if (!$syW) { try { $target->delete($syRt); } catch (\Throwable $e) {} return false; }
                try { if ($target->rename($syRt, $tgtPath)) return true; } catch (\Throwable $e) {}
                try { if ($target->exists($tgtPath) && $target->delete($tgtPath) && $target->rename($syRt, $tgtPath)) return true; } catch (\Throwable $e) {}
                try { $target->delete($syRt); } catch (\Throwable $e) {}
            }
            return $target->writeFromFile($tgtPath, $fullSrc);
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
    
    // ★ (2026-11-11 08:03, 72차 RMT-1) 동기화 원격 대상: 임시 이름에 다 쓴 뒤 이름 바꾸기를 쓸 수 있는지(copyFileTo 위 설명). FTP 는 아래 72차 재검토 반영으로 뺌
    // ★ (2026-11-11 10:35, 72차 재검토 반영) FTP 는 ftp 확장이 있어도 뺌(바로 쓰기 + 호출부 표시 갈래) — root 가 기본값('/')이면 임시 파일은 curl 이 로그인 폴더 기준으로 쓰고
    //   rename·delete 는 확장이 절대 경로로 다뤄, 비-chroot 서버에서 둘 다 실패한 채 바로 쓰기로 넘어가 원격에 .~fssy- 가 파일마다 쌓였음(재검토 재현)
    public static function syncRemoteAtomic($target): bool {
        return $target instanceof WebDavAdapter || $target instanceof SftpAdapter;
    }
    // ★ (2026-11-11 08:03, 72차 RMT-1) 진짜 이름에 바로 쓰는 원격 대상(끊기면 잘린 파일이 남을 수 있음) — 양방향 호출부가 실패 때 그 키를 다음 한 번 로컬 우선으로 표시.
    //   S3 는 본문 해시를 서명해 보내(x-amz-content-sha256) 잘린 본문을 서버가 받지 않으므로 빠짐(write() 경로)
    public static function syncRemoteDirect($target): bool {
        return !($target instanceof LocalAdapter) && method_exists($target, 'writeFromFile') && !self::syncRemoteAtomic($target);
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
        // ★ (2026-11-01 09:45, 38차 점검) PHP FTP 확장이 없으면 여기서 실패로 돌려줌 — curl 만 있으면 connect() 는 성공하는데, 삭제·이름 바꾸기·존재 확인 등이 이 함수를 거쳐
        //   ftp_connect 를 부르면 PHP 8 은 '@' 로도 막히지 않는 Error(Call to undefined function)로 요청이 치명 오류로 끝났음
        if (!function_exists('ftp_connect')) {
            $this->lastError = __('api_err_ftp_ext_missing', 'PHP FTP 확장(ftp)이 켜져 있지 않아 이 작업을 할 수 없습니다.');
            return false;
        }
        
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
    
    public bool $listFailed = false;   // ★ (2026-10-29 09:23, 20차) 목록 실패 표시 — list() 는 실패해도 [] 를 돌려줘 '빈 폴더' 와 구분이 안 돼, 원격 폴더 휴지통·이동 때 실패를 빈 폴더로 보고 원본을 지웠음. 호출부가 listFailed 로 확인
    public function list(string $path): array {
        $this->listFailed = false;
        if (!$this->connection && !$this->_lazyConnect) { $this->listFailed = true; return []; }
        
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
        if (!$this->connection && !$this->_doFtpConnect()) { $this->listFailed = true; return $items; }
        
        // 2차: ftp_mlsd (MLSD 지원 서버용)
        $origTimeout = @ftp_get_option($this->connection, FTP_TIMEOUT_SEC) ?: 90;
        @ftp_set_option($this->connection, FTP_TIMEOUT_SEC, 3);
        $list = @ftp_mlsd($this->connection, $fullPath);
        @ftp_set_option($this->connection, FTP_TIMEOUT_SEC, $origTimeout);
        
        if ($list !== false) {
            foreach ($list as $item) {
                if ($item['name'] === '.' || $item['name'] === '..') continue;
                // ★ (2026-11-01 09:45, 38차 점검) 현재·상위 폴더 줄(type=cdir/pdir)은 이름과 상관없이 건너뜀 — RFC 3659 예시처럼 'tmp'·'/tmp' 같은 이름으로 오면 '파일' 항목으로 섞였음
                $mlsdType = strtolower((string)($item['type'] ?? ''));
                if ($mlsdType === 'cdir' || $mlsdType === 'pdir') continue;
                $items[] = [
                    'name' => $item['name'],
                    'path' => ltrim($path . '/' . $item['name'], '/'),
                    'is_dir' => $item['type'] === 'dir',
                    'size' => (int)($item['size'] ?? 0),
                    'modified' => isset($item['modify']) ? self::ftpMlsdTime((string)$item['modify']) : 0   // ★ (2026-11-02 10:26, 42차 점검) MLSD 시각은 UTC(RFC 3659)인데 서버 시간대로 읽어 9시간 어긋났고 소수 초가 붙으면 0 이었음(E8#1)
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
                $line = ltrim(rtrim($line, "\r\n"));   // ★ (2026-11-04 01:35, 54차 점검) 줄 끝은 줄바꿈만 뗌 — 종전 trim() 은 이름 끝 공백('trail.txt ')까지 잘라 그 파일을 받기·지우기 못 했음(재현)
                if ($line === '') continue;   // ★ (2026-11-01 17:18, 40차 점검) empty() 는 '0' 도 빈 값으로 봐 이름만 오는 목록(ipTIME 등)에서 이름이 '0' 인 항목이 빠졌음 → 빈 문자열만 건너뜀

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
        if ($rawlist === false && $files === false) $this->listFailed = true;   // 모든 방법이 실패(빈 폴더는 [] 로 옴)
        return $items;
    }
    
    /** ★ (2026-11-02 10:26, 42차 점검) MLSD modify(YYYYMMDDHHMMSS[.sss], UTC) → 타임스탬프. 형식이 다르면 종전처럼 strtotime */
    private static function ftpMlsdTime(string $v): int {
        if (preg_match('/^(\d{14})(?:\.\d+)?$/', trim($v), $mm)) {
            $d = \DateTime::createFromFormat('!YmdHis', $mm[1], new \DateTimeZone('UTC'));
            if ($d) return $d->getTimestamp();
        }
        return (int)(strtotime($v) ?: 0);
    }

    /**
     * FTP rawlist 한 줄 파싱 (Unix/Windows 형식)
     */
    private function parseFtpRawLine(string $line, string $basePath): ?array {
        // Unix 형식: drwxr-xr-x  2 user group  4096 Mar 16 12:00 dirname
        // ★ (2026-11-02 10:26, 42차 점검) 권한 뒤 ACL 표시(+ . @)가 붙은 줄도 받음 — 종전엔 형식 불일치로 줄 전체가 파일 이름이 됐음(E8#1)
        // ★ (2026-11-04 01:35, 54차 점검) 시각 뒤 '공백 한 칸' 다음부터가 이름(ls 형식) — 종전엔 \s+ 와 trim() 으로 이름 앞뒤 공백(' lead.txt')이 잘려
        //   목록엔 'lead.txt' 로 보이고 받기가 404 였음(재현). 맞는 줄의 범위는 종전과 같음(.+ 가 남은 공백을 받음)
        if (preg_match('/^([d\-l])([rwxsStT\-]{9})[+.@]?\s+\d+\s+\S+\s+\S+\s+(\d+)\s+(\w+\s+\d+\s+[\d:]+)\s(.+)$/', $line, $m)) {
            $name = $m[5];
            // ★ (2026-11-01 09:45, 38차 점검) 심볼릭 링크(l) 줄은 'name -> target' 으로 와서 이름 전체가 그대로 들어가 열기·받기가 실패했음 → ' -> ' 앞까지만 이름으로
            if ($m[1] === 'l' && ($arrow = strpos($name, ' -> ')) !== false) $name = substr($name, 0, $arrow);
            if ($name === '.' || $name === '..') return null;
            $isDir = ($m[1] === 'd');
            $size = (int)$m[3];
            $modified = strtotime($m[4]);
            // ★ (2026-11-02 10:26, 42차 점검) 'Dec 20 12:00' 처럼 연도 없는(6개월 안) 날짜는 strtotime 이 올해를 붙여, 해가 바뀐 뒤엔 작년 파일이 미래 시각이 됐음
            //   → 동기화가 늘 'FTP 쪽이 새것'으로 보고 로컬 수정본을 덮어씀(E8#1 재현). 지금보다 하루 넘게 미래면 1년 뺌(연도 있는 형식은 그대로)
            if ($modified && strpos($m[4], ':') !== false && $modified > time() + 86400) {
                $modified = strtotime('-1 year', $modified) ?: $modified;
            }
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
        // ★ (2026-11-02 10:26, 42차 점검) IIS '4자리 연도 표시'(03-16-2026) 도 받음 — 종전엔 맨 끝 분기로 가 줄 전체가 이름·폴더도 파일이 됐음(E8#1)
        // ★ (2026-11-04 01:35, 54차 점검) 파일 줄은 크기 뒤 공백 한 칸 다음부터가 이름, <DIR> 줄은 칸 맞춤 공백이 여러 칸이라 앞 공백만 뗌 — 종전 trim() 은 이름 앞뒤 공백을 잘랐음(Unix 형식과 같은 문제)
        if (preg_match('/^(\d{2}-\d{2}-(?:\d{4}|\d{2})\s+\d{2}:\d{2}[AP]M)\s+(<DIR>|\d+)\s(.+)$/', $line, $m)) {
            $name = ($m[2] === '<DIR>') ? ltrim($m[3]) : $m[3];
            if ($name === '.' || $name === '..') return null;
            $isDir = ($m[2] === '<DIR>');
            $size = $isDir ? 0 : (int)$m[2];
            // ★ (2026-11-02 10:26, 42차 점검) MM-DD-YY 는 strtotime 이 못 읽거나(→0) 다르게 읽었음(11-01-26 → 2011-01-26) → 형식을 정해 읽음(E8#1)
            $wdt = preg_replace('/\s+/', ' ', $m[1]);
            $wobj = \DateTime::createFromFormat(strlen(explode(' ', $wdt)[0]) === 10 ? '!m-d-Y h:iA' : '!m-d-y h:iA', $wdt);
            $modified = $wobj ? $wobj->getTimestamp() : 0;
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
        if ($name === '.' || $name === '..' || $name === '') return null;   // ★ (2026-11-01 17:18, 40차 점검) empty() → === '' — 이름 '0' 항목이 빠졌음
        if (preg_match('/^total \d+$/', $name)) return null;   // ★ (2026-11-04 01:35, 54차 점검) ls -l 을 그대로 내는 서버의 첫 줄 'total N' 은 항목이 아님 — 종전엔 가짜 파일 'total N' 으로 들어갔음(해석 함수 직접 호출로 재현). 실제 그 이름의 파일은 Unix 형식 줄로 와서 영향 없음

        // 전체 경로가 포함된 경우 basename 추출
        if (strpos($name, '/') !== false) $name = basename($name);
        // ★ (2026-11-01 17:18, 40차 점검) basename 뒤 'x/..' 같은 줄이 '..' 이름이 되어 목록에 섞였음(동기화가 이 이름으로 대상 밖 경로를 만듦) → 다시 걸러냄
        if ($name === '.' || $name === '..' || $name === '') return null;

        return [
            'name' => $name,
            'path' => ltrim($basePath . '/' . $name, '/'),
            'is_dir' => false,  // rawlist에서 형식 파싱 실패 시 파일로 간주
            'size' => 0,
            'modified' => 0
        ];
    }
    
    public function read(string $path): string {
        // ★ (2026-10-31 13:36, 34차 후속 개선) 첫 줄에서 FTP 확장 연결부터 요구해 curl 로 충분한 읽기도 확장 연결이 실패하면(implicit FTPS 등) '' 가 됐음 → 확장 연결은 curl 실패 뒤 폴백 직전에만
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        // curl 우선 사용 (PHP FTP 확장보다 안정적)
        if (function_exists('curl_init')) {
            $content = $this->_curlFtpGet($fullPath);
            if ($content !== false) return $content;
        }
        
        // 폴백: PHP FTP 확장
        if (!$this->connection && !$this->_doFtpConnect()) return '';
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
        // ★ (2026-10-31 13:36, 34차 후속 개선) 첫 줄에서 FTP 확장 연결부터 요구해 curl 로 충분한 읽기도 확장 연결이 실패하면(implicit FTPS 등) '' 가 됐음 → 확장 연결은 curl 실패 뒤 폴백 직전에만
        
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
                    CURLOPT_FTP_SSL => $this->_curlFtpSslMode(),
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
        if (!$this->connection && !$this->_doFtpConnect()) return '';
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
        // ★ (2026-10-31 13:36, 34차 후속 개선) 첫 줄에서 FTP 확장 연결부터 요구해 curl 로 충분한 읽기도 확장 연결이 실패하면(implicit FTPS 등) '' 가 됐음 → 확장 연결은 curl 실패 뒤 폴백 직전에만
        
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        // curl 우선 사용 (대용량 FTP 다운로드 안정)
        if (function_exists('curl_init')) {
            $this->_ftpStreamSent = 0;
            $result = $this->_curlFtpStream($fullPath);
            if ($result) return true;
            // ★ (2026-11-01 01:14, 35차 전체 점검) curl 이 일부라도 내보낸 뒤 실패했으면 처음부터 다시 쓰는 ftp_fget 폴백을 하지 않고 실패로 돌려줌 —
            //   종전엔 '앞부분 일부 + 파일 처음부터 전체'가 이어 붙어, 호출부(다운로드·원격→로컬 복사)가 깨진 파일을 성공으로 받았음
            if ($this->_ftpStreamSent > 0) return false;
        }
        
        // 폴백: PHP FTP 확장 ftp_fget
        if (!$this->connection && !$this->_doFtpConnect()) return false;
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
            CURLOPT_FTP_SSL => $this->_curlFtpSslMode(),
        ]);
        
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        
        if ($errno !== 0 || $result === false) return false;
        // ★ (2026-11-01 09:45, 38차 점검) curl 이 오류 없이(errno 0) 빈 응답을 주면 빈 폴더 — 종전엔 false 로 확장 폴백에 넘겨, 확장이 없거나 990(implicit FTPS)이면 빈 폴더가 '목록 실패'가 되어
        //   빈 폴더 삭제·이동이 거부됐음(없는 폴더는 curl 이 CWD 단계에서 오류 번호를 돌려줌)
        if (trim($result) === '') return [];
        
        $items = [];
        $sawTotal = false;
        $lines = explode("\n", $result);   // ★ (2026-11-04 01:35, 54차 점검) 전체 trim() 을 뺌 — 종전엔 마지막 줄 이름 끝 공백('trail.txt ')이 잘렸음(재현). 빈 줄은 아래에서 건너뜀
        foreach ($lines as $line) {
            $line = ltrim(rtrim($line, "\r\n"));   // ★ (2026-11-04 01:35, 54차 점검) 줄 끝은 줄바꿈만 뗌 — 종전 trim() 은 이름 끝 공백까지 잘랐음(재현)
            if ($line === '') continue;   // ★ (2026-11-01 17:18, 40차 점검) empty() 는 '0' 도 빈 값으로 봐 이름 '0' 항목이 빠졌음 → 빈 문자열만 건너뜀
            if (preg_match('/^total \d+$/', $line)) { $sawTotal = true; continue; }   // ★ (2026-11-04 01:35, 54차 점검) 'total N' 줄은 항목 아님 — 빈 폴더('total 0' 한 줄)는 아래에서 [] (빈 폴더)로
            $parsed = $this->parseFtpRawLine($line, $relativePath);
            if ($parsed) $items[] = $parsed;
        }

        return (count($items) > 0 || $sawTotal) ? $items : false;
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
            CURLOPT_FTP_SSL => $this->_curlFtpSslMode(),
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
        ]);
        
        $content = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        
        return ($errno === 0 && $content !== false) ? $content : false;
    }
    
    private $_ftpStreamSent = 0;   // ★ (2026-11-01 01:14, 35차 전체 점검) _curlFtpStream 이 내보낸 바이트 수
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
            CURLOPT_FTP_SSL => $this->_curlFtpSslMode(),
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
            CURLOPT_BUFFERSIZE => 65536, // 64KB 버퍼
            CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 60,   // ★ (2026-11-01 01:50, 36차 전체 점검) 1분 동안 초당 1KB 미만이면 중단(WebDAV·S3 34차와 같게) — 서버가 데이터 연결을 조용히 버리면 PHP 가 끝없이 기다렸음
            // 청크 단위 출력 + 클라이언트 연결 끊김 감지
            CURLOPT_WRITEFUNCTION => function($ch, $data) {
                if (connection_aborted()) return 0; // 연결 끊김 → curl 중단
                echo $data;
                flush();
                $this->_ftpStreamSent += strlen($data);   // ★ (2026-11-01 01:14, 35차 전체 점검) 내보낸 양(폴백 판단용)
                return strlen($data);
            }
        ]);
        
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        
        // CURLE_WRITE_ERROR(23)은 클라이언트 연결 끊김으로 발생 → 정상 처리
        return ($errno === 0 || $errno === 23) && $result !== false;
    }
    
    // ★ (2026-10-31 13:36, 34차 후속 개선) curl FTP 의 TLS 사용 방식 — ssl 설정이면 AUTH TLS 필수(CURLUSESSL_ALL, explicit), 아니면 종전처럼 '되면 사용'(TRY)
    private function _curlFtpSslMode(): int {
        return !empty($this->config['ssl']) ? CURLUSESSL_ALL : CURLFTPSSL_TRY;
    }

    /** FTP curl URL 생성 */
    private function _buildFtpUrl(string $remotePath): string {
        $host = $this->config['host'] ?? '';
        $port = (int)($this->config['port'] ?? 21);
        $user = rawurlencode($this->config['username'] ?? $this->config['user'] ?? '');
        $pass = rawurlencode($this->config['password'] ?? $this->config['pass'] ?? '');
        $ssl = !empty($this->config['ssl']);
        
        if (empty($host)) return '';
        
        // ★ (2026-10-31 13:36, 34차 후속 개선) ssl 이면 curl 은 ftps://(implicit TLS)를, FTP 확장(ftp_ssl_connect)은 explicit(AUTH TLS)를 써서 방식이 어긋났음
        //   → explicit 으로 통일(ftp:// + _curlFtpSslMode() 의 CURLUSESSL_ALL). 단 990번 포트는 implicit 전용 포트라 종전처럼 ftps:// 유지(ftp_ssl_connect 로는 원래 연결 불가)
        $scheme = ($ssl && $port === 990) ? 'ftps' : 'ftp';
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $remotePath)));
        // ★ (2026-10-31 13:35, 34차 후속 개선) curl 의 FTP URL 에서 '/a/b' 는 '로그인 폴더 기준 상대 경로'라, 절대 경로로 쓰는 FTP 확장(ftp_size·ftp_delete·ftp_rename·MKD 등)과
        //   비-chroot 서버에서 서로 다른 위치를 봤음 → '/' 로 시작하는 경로는 '/%2F/...'(CWD / 부터)로 보내 절대 경로로 통일. chroot 서버는 '/' 가 로그인 폴더라 결과가 같음
        //   ★ (2026-10-31 13:46, 34차 후속 개선) 단 root 를 기본값('/' 또는 빈 값)으로 둔 스토리지는 종전 그대로(로그인 폴더 기준) — 비-chroot 서버에서
        //   목록이 갑자기 서버의 진짜 '/' 로 바뀌어 보이는 일이 없게. 절대 경로 root(예: /volume1/share)를 적은 경우에만 절대 경로로 통일
        $cfgRoot = trim((string)($this->config['root'] ?? '/'));
        if ($cfgRoot !== '' && $cfgRoot !== '/' && $remotePath !== '' && $remotePath[0] === '/') {
            $encodedPath = '/%2F/' . implode('/', array_map('rawurlencode', explode('/', ltrim($remotePath, '/'))));
        }
        
        return "{$scheme}://{$user}:{$pass}@{$host}:{$port}{$encodedPath}";
    }
    
    /**
     * ★ (2026-10-29 01:12, 14차) 로컬 파일을 메모리에 통째로 올리지 않고 그대로 흘려 보냄(큰 파일 업로드용) — write() 와 같은 방식(curl 우선, 없으면 FTP 확장).
     *   시간 제한은 '1분 동안 초당 1KB 미만이면 중단'(큰 파일이 고정 300초에 끊기지 않게). 실패하면 false
     */
    public function writeFromFile(string $path, string $localFile): bool {
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        $size = @filesize($localFile);
        if ($size === false) return false;
        
        if (function_exists('curl_init')) {
            $url = $this->_buildFtpUrl($fullPath);
            if ($url) {
                $fh = @fopen($localFile, 'rb');
                if ($fh) {
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $url,
                        CURLOPT_UPLOAD => true,
                        CURLOPT_INFILE => $fh,
                        CURLOPT_INFILESIZE => $size,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_LOW_SPEED_LIMIT => 1024,
                        CURLOPT_LOW_SPEED_TIME => 60,
                        CURLOPT_FTP_SSL => $this->_curlFtpSslMode(),
                        CURLOPT_FTP_CREATE_MISSING_DIRS => true,
                    ]);
                    $result = curl_exec($ch);
                    $errno = curl_errno($ch);
                    curl_close($ch);
                    fclose($fh);
                    if ($errno === 0 && $result !== false) return true;
                }
            }
        }
        
        // 폴백: PHP FTP 확장
        if (!$this->connection && !$this->_doFtpConnect()) return false;
        $fh = @fopen($localFile, 'rb');
        if (!$fh) return false;
        $result = @ftp_fput($this->connection, $fullPath, $fh, FTP_BINARY);
        fclose($fh);
        return $result;
    }
    
    public function write(string $path, string $content): bool {
        $root = $this->config['root'] ?? '/';
        $fullPath = rtrim($root, '/') . '/' . ltrim($path, '/');
        
        // curl 우선 (Passive Mode 안정)
        if (function_exists('curl_init')) {
            $url = $this->_buildFtpUrl($fullPath);
            // ★ (2026-11-01 17:18, 40차 점검) 임시 파일 생성·쓰기 결과를 안 봐, 시스템 임시 디스크가 차면 잘린 내용을 올리고 성공(로컬→FTP 이동이면 원본 삭제)·tmpfile() 실패면 TypeError 였음
            //   → 다 쓰지 못하면 curl 은 건너뛰고(아래 폴백도 같은 확인으로 false)
            $temp = $url ? @tmpfile() : false;
            if ($temp !== false && @fwrite($temp, $content) !== strlen($content)) { fclose($temp); $temp = false; }
            if ($url && $temp !== false) {
                rewind($temp);

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_UPLOAD => true,
                    CURLOPT_INFILE => $temp,
                    CURLOPT_INFILESIZE => strlen($content),
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FTP_SSL => $this->_curlFtpSslMode(),
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

        $temp = @tmpfile();
        if ($temp === false) return false;   // ★ (2026-11-01 17:18, 40차 점검) 임시 파일을 못 만들거나 다 못 쓰면 잘린 내용을 올리지 않고 실패
        if (@fwrite($temp, $content) !== strlen($content)) { fclose($temp); return false; }
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
            if ($this->isLink($path)) return @ftp_delete($this->connection, $fullPath);   // ★ (2026-11-12 16:39, 100차 RMT100-4) 폴더를 가리키는 링크는 링크만 지움(isLink ★) — 종전엔 rmdir 이 실패했음
            return @ftp_rmdir($this->connection, $fullPath);
        }
        return @ftp_delete($this->connection, $fullPath);
    }
    
    // ★ (2026-11-12 16:39, 100차 RMT100-4) 서버 쪽 링크인지 — 상위 폴더 LIST 줄이 'l' 로 시작하는지(PHP FTP 확장으로 연결돼 있을 때만, 아니면 거짓 = 종전과 같음).
    //   지우기 갈래(delete·FileManager::deleteRemoteDir)에서만 씀 — 종전엔 isDir(chdir)가 링크를 따라가, 링크 폴더를 지우면 가리키는 곳(스토리지 root 밖 포함)의 파일을 지웠음(재현)
    public function isLink(string $path): bool {
        if (!$this->connection) return false;
        $p = trim(str_replace('\\', '/', $path), '/');
        if ($p === '') return false;
        $root = $this->config['root'] ?? '/';
        $parent = rtrim($root, '/') . '/' . ((strpos($p, '/') === false) ? '' : dirname($p));
        $raw = @ftp_rawlist($this->connection, $parent);
        if (!is_array($raw)) return false;
        foreach ($raw as $line) {
            $line = ltrim(rtrim((string)$line, "\r\n"));
            if ($line === '' || $line[0] !== 'l') continue;
            $it = $this->parseFtpRawLine($line, '');
            if ($it && (string)($it['name'] ?? '') === basename($p)) return true;
        }
        return false;
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
                    CURLOPT_FTP_SSL => $this->_curlFtpSslMode(),
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

    // ★ (2026-11-09 15:36, 59차 F1) 양방향 동기화 비교 전용 — MDTM(RFC 3659: UTC·초 단위) 시각을 curl 로(FTP 확장 없이도, 한 연결 재사용). 못 얻으면 0 → 호출부는 종전 목록 시각 그대로
    private $_mdtmCh = null;
    public function syncMdtm(string $path): int {
        if (!function_exists('curl_init')) return 0;
        $url = $this->_buildFtpUrl(rtrim($this->config['root'] ?? '/', '/') . '/' . ltrim($path, '/'));
        if (!$url) return 0;
        if (!$this->_mdtmCh) $this->_mdtmCh = curl_init();
        curl_setopt_array($this->_mdtmCh, [CURLOPT_URL => $url, CURLOPT_NOBODY => true, CURLOPT_FILETIME => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FTP_SSL => $this->_curlFtpSslMode(), CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS]);
        if (curl_exec($this->_mdtmCh) === false || curl_errno($this->_mdtmCh) !== 0) return 0;
        $t = (int)curl_getinfo($this->_mdtmCh, CURLINFO_FILETIME);
        return $t > 0 ? $t : 0;
    }
    /** ★ (2026-11-09 15:36, 59차 F1) 보정값이 같은 FTP 서버의 것인지 구분하는 표시 */
    public function syncMdtmSig(): string {
        return md5(json_encode([(string)($this->config['host'] ?? ''), (int)($this->config['port'] ?? 21), (string)($this->config['username'] ?? ''), (string)($this->config['root'] ?? '/')]));
    }
    /**
     * ★ (2026-11-09 15:36, 59차 F1) 양쪽에 다 있고 크기나 시각이 다른 파일만 MDTM 시각을 모아 돌려줌([키 => 시각]). 하나라도 못 얻거나 지금보다 2분 넘게 미래면
     *   (MDTM 을 현지 시각으로 주는 서버) null → 호출부는 아무것도 바꾸지 않음(종전 그대로)
     */
    public function syncMdtmFor(array $mine, array $other): ?array {
        $out = [];
        foreach ($mine as $k => $f) {
            if (!empty($f['is_dir']) || !isset($other[$k]) || !empty($other[$k]['is_dir'])) continue;
            if ((int)($f['size'] ?? 0) === (int)($other[$k]['size'] ?? 0) && (int)($f['modified'] ?? 0) === (int)($other[$k]['modified'] ?? 0)) continue;
            $t = $this->syncMdtm((string)$f['path']);
            if ($t <= 0 || $t > time() + 120) return null;
            $out[$k] = $t;
        }
        return $out;
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
        // ★ (2026-10-31 13:39, 34차 후속 개선) phpseclib 로그인이 실패해도 사유를 남기지 않고 ssh2 폴백으로 넘어가, ssh2 가 없으면 비밀번호가 틀려도 '확장 미설치'로 안내됐음
        //   → connectSeclib 가 실패 사유를 lastError 에 남기고, ssh2 확장이 없으면 그 사유를 그대로 돌려줌(ssh2 가 있으면 종전처럼 폴백)
        $this->lastError = '';
        if ($this->connectSeclib($host, $port, $username, $password, $authType, $connectTimeout)) {
            $this->useSeclib = true;
            return true;
        }
        
        // fallback: php-ssh2 확장
        if ($this->lastError !== '' && !function_exists('ssh2_connect')) return false;
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
                    $this->lastError = __('sftp_key_auth_fail');   // ★ (2026-10-31 13:39, 34차 후속 개선) 실패 사유 남김
                    return false;
                }
            } else {
                if (!$sftp->login($username, $password)) {
                    $this->lastError = __('sftp_auth_fail');   // ★ (2026-10-31 13:39, 34차 후속 개선) 실패 사유 남김
                    return false;
                }
            }
            
            $this->seclib = $sftp;
            return true;
        } catch (\Throwable $e) {
            // ★ (2026-10-31 13:39, 34차 후속 개선) 연결·키 해석 실패 사유 남김(키 형식 오류는 키 인증 실패로)
            $this->lastError = ($authType === 'key' && $e instanceof \phpseclib3\Exception\NoKeyLoadedException)
                ? __('sftp_key_auth_fail')
                : __f('sftp_connect_fail', ['host' => $host, 'port' => $port]) . ' (' . $e->getMessage() . ')';
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
    
    public bool $listFailed = false;   // ★ (2026-10-29 09:23, 20차) 목록 실패 표시 — list() 는 실패해도 [] 를 돌려줘 '빈 폴더' 와 구분이 안 돼, 원격 폴더 휴지통·이동 때 실패를 빈 폴더로 보고 원본을 지웠음. 호출부가 listFailed 로 확인
    public function list(string $path): array {
        $this->listFailed = false;
        $rawPath = $this->getRawPath($path);
        
        // phpseclib3
        if ($this->useSeclib && $this->seclib) {
            $entries = $this->seclib->rawlist($rawPath);
            if (!is_array($entries)) { $this->listFailed = true; return []; }
            
            $items = [];
            foreach ($entries as $name => $attrs) {
                $name = (string)$name;   // ★ (2026-10-29 21:41, 24차 점검) 배열 키라 숫자 이름('2024')이 정수로 옴 → 문자열로(목록 화면 오류 방지)
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
        if (!$this->sftp) { $this->listFailed = true; return []; }
        
        $sfOpenTried = false;
        if (!preg_match('/[#?]/', $rawPath)) {
            $sfOpenTried = true;
            $handle = @opendir($this->getSftpPath($path));
            if ($handle) {
                return $this->readDirItems($handle, $path);
            }
        }
        
        $items = $this->listViaSymlink($path);
        if (empty($items) && $this->connection) $items = $this->listViaSshExec($path);
        // 폴더 열기 실패 뒤 대체 방법도 비면 빈 폴더인지 알 수 없음 → 실패로
        // ★ (2026-10-29 15:35, 21차 재점검) 이름에 #·? 가 있어 폴더 열기를 아예 건너뛴 경우는 종전처럼 빈 폴더로 봄 — 20차엔 이런 이름의 빈 폴더가 늘 '목록 실패' 가 되어 삭제·이동·복사가 실패했음
        if (empty($items) && $sfOpenTried) $this->listFailed = true;
        return $items;
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
    
    // ★ (2026-11-10 13:18, 63차) 로컬 파일을 메모리에 통째로 올리지 않고 흘려 보냄(FTP·WebDAV writeFromFile 과 같은 이름) — 종전엔 없어 원격→SFTP 폴더 복사·로컬→SFTP 업로드·복사가 파일 전체를 문자열로 읽어 write() 에 넘겨 작업 큐(512M)에서 큰 파일이 치명 오류로 멈췄음(61차 재현). phpseclib3 put() 에 로컬 파일 핸들을 넘김(문자열 모드와 같은 반복, substr 대신 fread). 로컬 파일을 먼저 열어 못 열면 원격에 아무것도 만들지 않고 false. ssh2 확장 폴백은 종전처럼 전체를 읽어 write()
    public function writeFromFile(string $path, string $localFile): bool {
        if ($this->useSeclib && $this->seclib) {
            $fh = @fopen($localFile, 'rb');
            if (!$fh) return false;
            try {
                return $this->seclib->put($this->getRawPath($path), $fh) !== false;
            } finally {
                if (is_resource($fh)) fclose($fh);
            }
        }
        $content = @file_get_contents($localFile);
        if ($content === false) return false;
        return $this->write($path, $content);
    }
    
    public function delete(string $path): bool {
        $rawPath = $this->getRawPath($path);
        
        if ($this->useSeclib && $this->seclib) {
            // ★ (2026-11-12 17:52, 101차 RGR20-2) lstat 한 번으로 링크(3)·폴더(2)·그 밖(파일)을 가름 — 100차엔 항목마다 isLink(lstat)에 is_dir(stat)까지 따로 물어,
            //   폴더마다 서버 왕복이 2번 늘어 폴더 지우기가 느려졌음(재현: 지연 5ms SFTP 240항목 100차 전 27.5s → 100차 후 28.9s, 왕복 계측 is_dir 241·isLink 122).
            //   목록에서 받은 파일 속성은 phpseclib 캐시에 있어 파일은 왕복이 없음. 링크는 100차와 같이 링크만 지우고(되풀이 삭제 끔), 파일도 되풀이 삭제를 끔(파일이면 결과 같음)
            $lst = $this->seclib->lstat($rawPath);
            if (is_array($lst) && isset($lst['type'])) {
                if ((int)$lst['type'] === 3) return $this->seclib->delete($rawPath, false);   // 링크 — ★ (2026-11-12 16:39, 100차 RMT100-4) 링크만 지움
                if ((int)$lst['type'] === 2) return $this->seclib->rmdir($rawPath);
                return $this->seclib->delete($rawPath, false);
            }
            if ($this->seclib->is_dir($rawPath)) {
                if ($this->isLink($path)) return $this->seclib->delete($rawPath, false);   // ★ (2026-11-12 16:39, 100차 RMT100-4) 링크는 링크만 지움(안으로 들어가지 않게 되풀이 삭제 끔) — 종전엔 stat 이 링크를 따라가 폴더로 보고 rmdir 이 실패했음
                return $this->seclib->rmdir($rawPath);
            }
            return $this->seclib->delete($rawPath);
        }
        
        if (!$this->sftp) return false;
        $stat = @ssh2_sftp_stat($this->sftp, $rawPath);
        $isDir = $stat ? (($stat['mode'] & 0040000) !== 0) : false;
        if ($isDir) {
            if ($this->isLink($path)) return @ssh2_sftp_unlink($this->sftp, $rawPath);   // ★ (2026-11-12 16:39, 100차 RMT100-4) 링크만 지움 — ★ (2026-11-12 17:52, 101차 RGR20-2) 확인은 폴더로 보일 때만(파일 링크는 아래 unlink 가 링크만 지움)
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
    
    // ★ (2026-11-12 16:39, 100차 RMT100-4) 서버 쪽 링크인지(lstat — 따라가지 않음). 지우기 갈래(delete·FileManager::deleteRemoteDir)에서만 씀 — isDir(stat)는 목록·받기 등에서 종전대로 링크를 따라감.
    //   종전엔 링크 폴더를 지우면 deleteRemoteDir 가 링크 안(스토리지 root 밖 포함)의 파일을 지웠음(재현: lnk → ../outside_secret, 밖 s.txt·deep/d.txt 삭제)
    public function isLink(string $path): bool {
        $rawPath = $this->getRawPath($path);
        if ($this->useSeclib && $this->seclib) return (bool)$this->seclib->is_link($rawPath);
        if (!$this->sftp) return false;
        $lst = @ssh2_sftp_lstat($this->sftp, $rawPath);
        return $lst ? ((($lst['mode'] ?? 0) & 0170000) === 0120000) : false;
    }
    
    public function streamToOutput(string $path): bool {
        $rawPath = $this->getRawPath($path);
        
        // ★ (2026-11-01 09:45, 38차 점검) phpseclib3 get() 의 받는 곳에 함수를 넘겨 받은 조각을 바로 내보냄 — 종전엔 파일 전체를 서버 임시 파일로 다 받은 뒤에야 첫 바이트를 보내,
        //   큰 파일은 프록시·FastCGI 응답 대기 시간에 끊기고, 사용자가 취소해도 계속 받고, 시간 초과로 끝나면 임시 파일이 남았음(FTP·WebDAV·S3 와 같게)
        //   get() 은 받는 곳이 함수면 성공 때 '' · 실패 때 false 를 돌려줌. 사용자가 끊으면 예외로 받기를 멈추고 true(FTP 의 curl 23 처리와 같음)
        if ($this->useSeclib && $this->seclib) {
            $sent = 0; $aborted = false;
            try {
                $result = $this->seclib->get($rawPath, function ($data) use (&$sent, &$aborted) {
                    if (connection_aborted()) { $aborted = true; throw new \RuntimeException('client aborted'); }
                    echo $data;
                    flush();
                    $sent += strlen($data);
                });
            } catch (\Throwable $e) {
                if ($aborted) return true;
                if ($sent > 0) return false;   // 일부를 내보낸 뒤 실패 — 호출부가 내보낸 양으로 판단(뒤에 붙이지 않음)
                throw $e;
            }
            return $result !== false;
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
        return $result === false ? '' : $result;   // ★ (2026-11-01 17:18, 40차 점검) '?:' 는 내용이 정확히 '0' 인 파일을 '' 로 바꿔 복사가 '읽기 실패'로 끝났음 → 실패(false)만 ''
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
    private bool $_rangeUnsupported = false;   // ★ (2026-10-31 12:03, 34차 전체 점검) 선언 없는 동적 속성이라 PHP 8.2+ 에서 경고가 났음
    
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
        // ★ (2026-10-29 01:43, 15차) empty() 는 문자열 "0" 도 비었다고 봐, 내용이 정확히 '0' 인 파일이 빈 파일로 저장됐음(재현) → 빈 문자열만 뺌
        if (isset($options['body']) && (string)$options['body'] !== '') {
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
    
    public bool $listFailed = false;   // ★ (2026-10-29 09:23, 20차) 목록 실패 표시 — list() 는 실패해도 [] 를 돌려줘 '빈 폴더' 와 구분이 안 돼, 원격 폴더 휴지통·이동 때 실패를 빈 폴더로 보고 원본을 지웠음. 호출부가 listFailed 로 확인
    public function list(string $path): array {
        $this->listFailed = false;
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 1']]);
        if ($result['code'] !== 207) { $this->listFailed = true; return []; }
        
        $items = [];
        $xml = @simplexml_load_string($result['body']);
        if ($xml === false) { $this->listFailed = true; return []; }   // ★ (2026-10-29 18:10, 22차 점검) '=== false' 로 — DAV 응답은 'D:' 같은 접두어 이름공간이라 SimpleXML 뿌리 요소가 거짓으로 평가돼(!$xml 이 늘 참) 원격 WebDAV 의 목록·폴더 판정·크기·수정 시각이 늘 실패했음(재현)
        
        $xml->registerXPathNamespace('d', 'DAV:');
        // ★ (2026-10-29 03:11, 16차) 목록 대상 폴더 자신(self)의 경로 — 이걸로 self 만 건너뛰어, 부모와 이름이 같은 하위 폴더(photos/photos)가 사라지지 않게.
        //   또 href 해독을 rawurldecode 로 바꿔 '+' 가 공백으로 바뀌던 문제(a+b.txt → 'a b.txt')도 바로잡음
        // ★ (2026-10-29 18:10, 22차 점검) 설정 주소의 경로도 해독해 비교(href 는 해독함) — 종전엔 주소에 %인코딩이 있으면(Nextcloud 'user%40mail.com' 등)
        //   폴더 자신이 걸러지지 않아 자기 이름의 가짜 하위 폴더로 보였고, 그 안을 열면 404 → 목록 실패로 폴더 삭제가 늘 실패했음
        $basePathPart = rtrim(rawurldecode((string)parse_url($this->config['url'] ?? '', PHP_URL_PATH)), '/');
        $selfPath = rtrim($basePathPart . '/' . ltrim(str_replace('\\', '/', $path), '/'), '/');
        foreach ($xml->xpath('//d:response') as $response) {
            // ★ (2026-10-29 18:10, 22차 점검) 하위 요소의 xpath 도 이름공간을 따로 등록해야 함 — 종전엔 등록이 뿌리에만 있어 'd:href' 가 늘 실패(false)해
            //   모든 항목이 이름 없음으로 건너뛰어졌음(원격 WebDAV 폴더가 늘 비어 보임, 재현)
            $response->registerXPathNamespace('d', 'DAV:');
            $hrefNode = $response->xpath('d:href');
            $propNode = $response->xpath('d:propstat/d:prop');
            if (empty($hrefNode) || empty($propNode)) continue;
            $href = (string)$hrefNode[0];
            $props = $propNode[0];
            $props->registerXPathNamespace('d', 'DAV:');

            $hrefPath = rtrim(rawurldecode((string)parse_url($href, PHP_URL_PATH)), '/');
            if ($hrefPath === $selfPath) continue;   // 폴더 자신만 건너뜀(이름이 같은 자식은 유지)
            $name = basename(rawurldecode($href));
            // ★ (2026-11-01 17:18, 40차 점검) empty() 는 '0' 도 빈 값으로 봐 이름이 '0' 인 파일·폴더가 목록에서 빠졌고(폴더 이동 때 원본과 함께 지워짐),
            //   서버가 '..'·'.' 로 끝나는 href 를 주면 그 이름이 그대로 목록에 섞였음(동기화가 이 이름으로 대상 밖 경로를 만듦) → FTP·SFTP·Local 처럼 빈 이름·'.'·'..' 만 건너뜀
            if ($name === '' || $name === '.' || $name === '..') continue;
            
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
    
    /**
     * ★ (2026-10-29 01:12, 14차) 로컬 파일을 메모리에 통째로 올리지 않고 PUT 으로 흘려 보냄(큰 파일 업로드용) — 주소·인증은 request() 와 같음.
     *   시간 제한은 '1분 동안 초당 1KB 미만이면 중단'(write() 는 고정 30초라 느린 연결의 큰 파일은 끊길 수 있음 — 코드 확인, 실제 끊김은 미시험). 2xx 면 성공
     */
    public function writeFromFile(string $path, string $localFile): bool {
        if (!function_exists('curl_init')) {
            $this->lastError = __('api_err_webdav_no_curl', 'WebDAV 연결에 필요한 cURL 확장이 설치되지 않았습니다.');
            return false;
        }
        $size = @filesize($localFile);
        $fh = ($size === false) ? false : @fopen($localFile, 'rb');
        if (!$fh) return false;
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url = rtrim($this->config['url'] ?? '', '/') . '/' . ltrim($encodedPath, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => ($this->config['username'] ?? '') . ':' . ($this->config['password'] ?? ''),
            CURLOPT_CONNECTTIMEOUT => (int)($this->config['connect_timeout'] ?? 10),
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 60,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($curlError) {
            $this->lastError = __('webdav_connect_error') . $curlError;
        }
        return $httpCode >= 200 && $httpCode < 300;
    }
    
    /**
     * ★ (2026-10-31 13:38, 34차 후속 개선) 받은 만큼 바로 출력하는 다운로드(FtpAdapter::streamToOutput 과 같은 방식) — 종전엔 이 함수가 없어
     *   downloadRemote 가 Content-Length 를 보낸 뒤 read()(전송 전체 30초 제한, 실패면 '')를 echo 해 30초 넘는 파일은 본문이 비었음.
     *   시간 제한은 '1분 동안 초당 1KB 미만이면 중단'. 200 이 아닌 응답(404·401 등)의 본문은 내보내지 않고 false
     */
    public function streamToOutput(string $path): bool {
        if (!function_exists('curl_init')) return false;
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url = rtrim($this->config['url'] ?? '', '/') . '/' . ltrim($encodedPath, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => ($this->config['username'] ?? '') . ':' . ($this->config['password'] ?? ''),
            CURLOPT_CONNECTTIMEOUT => (int)($this->config['connect_timeout'] ?? 10),
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_WRITEFUNCTION => function($ch, $data) {
                if ((int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200) return 0;   // 오류 응답 본문은 파일 내용으로 내보내지 않음
                if (connection_aborted()) return 0;
                echo $data;
                flush();
                return strlen($data);
            },
        ]);
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($errno !== 0 && $errno !== 23 && $curlError) {
            $this->lastError = __('webdav_connect_error') . $curlError;
        }
        // CURLE_WRITE_ERROR(23)은 받는 쪽 연결 끊김 → FTP 와 같이 정상 처리
        return $code === 200 && $result !== false && ($errno === 0 || $errno === 23);
    }
    
    public function write(string $path, string $content): bool {
        // ★ (2026-11-04 01:35, 54차 점검) 빈 내용 PUT 에 Content-Length: 0 (S3 24차와 같음) — 종전엔 curl 이 길이 머리글 없이 보내(소켓으로 확인) 길이를 요구하는 서버는 411 로 거부할 수 있었음
        $result = $this->request('PUT', $path, $content === '' ? ['body' => $content, 'headers' => ['Content-Length: 0']] : ['body' => $content]);
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
        // ★ (2026-11-09 15:51, 59차 M) 대소문자만 다른 이름 바꾸기는 같은 폴더의 임시 이름을 거쳐 두 번 MOVE(Overwrite: F) — 대소문자를 무시하는 서버는 대상(=자기 자신)을 먼저 지운 뒤 옮기려다 실패해 파일·폴더가 내용째 사라졌음(재현). 두 번째가 실패하면 되돌리고, 그것도 안 되면 임시 이름으로 남김
        $rnF = ltrim($from, '/'); $rnT = ltrim($to, '/');
        if ($rnF !== $rnT && mb_strtolower($rnF, 'UTF-8') === mb_strtolower($rnT, 'UTF-8')) {
            $rnDir = dirname($rnT);
            $rnDir = ($rnDir === '.' || $rnDir === '') ? '' : $rnDir . '/';
            $rnMove = function (string $src, string $dst): bool {
                $dstUrl = rtrim($this->config['url'] ?? '', '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $dst)));
                $r = $this->request('MOVE', $src, ['headers' => ["Destination: $dstUrl", 'Overwrite: F']]);
                return $r['code'] >= 200 && $r['code'] < 300;
            };
            // ★ (2026-11-09 16:56, 59차 M-1 보완) 먼저 바로 한 번(Overwrite: F — 대소문자 구분 서버는 종전처럼 한 번에 성공, 무시 서버는 대상 '있음'으로 거부하고 지우지 않음). 실패할 때만 아래 두 단계
            if ($rnMove($rnF, $rnT)) return true;
            // ★ (2026-11-09 16:56, 59차 M-1 보완) 임시 이름은 짧고 목록에서 숨겨지지 않게('~$'·'.' 로 시작하지 않는 'rn<4자리>_원래이름') — 종전 '~$fsrn-…-' 15바이트가 붙어 긴 이름(241바이트 넘음)은 한도를 넘어 실패했고(재현),
            //   되돌리기까지 실패하면 숨김 이름으로 남아 사라진 것처럼 보였음. 붙인 이름이 240바이트를 넘으면 원래 이름 앞을 줄여 원래 길이보다 길어지지 않게(글자 경계 기준)
            for ($rnTry = 0; $rnTry < 3; $rnTry++) {
                $rnTag = 'rn' . bin2hex(random_bytes(2)) . '_';
                $rnBase = basename($rnF);
                if (strlen($rnTag . $rnBase) > 240) $rnBase = mb_strcut($rnBase, 10, null, 'UTF-8');
                $rnTmp = $rnDir . $rnTag . $rnBase;
                if (!$this->exists($rnTmp)) break;
            }
            if (!$rnMove($rnF, $rnTmp)) return false;
            if ($rnMove($rnTmp, $rnT)) return true;
            if (!$rnMove($rnTmp, $rnF)) error_log('[FileStation] WebDAV case-only rename: left as ' . $rnTmp);
            return false;
        }
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
        if ($xml === false) return false;   // ★ (22차) 위 list 와 같음
        $xml->registerXPathNamespace('d', 'DAV:');
        return !empty($xml->xpath('//d:resourcetype/d:collection'));
    }
    
    public function getSize(string $path): int {
        $result = $this->request('PROPFIND', $path, ['headers' => ['Depth: 0']]);
        if ($result['code'] !== 207) return 0;
        $xml = @simplexml_load_string($result['body']);
        if ($xml === false) return 0;   // ★ (22차) 위 list 와 같음
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
        if ($xml === false) return 0;   // ★ (22차) 위 list 와 같음
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
    
    private function sign(string $method, string $uri, array $headers, string $payload = '', array $query = []): array {
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

        // ★ (2026-10-29 03:11, 16차) canonical query string 을 실제 쿼리로 채움 — 종전엔 '' 로 고정해, 목록 조회(?list-type=2&prefix=…) 등
        //   쿼리가 붙는 모든 요청의 서명이 어긋나 S3·MinIO 가 SignatureDoesNotMatch 로 거부했음(연결·목록 자체 실패). S3 서버가 없어 소스로만 확인
        $canonicalQuery = '';
        if (!empty($query)) {
            $qk = array_keys($query);
            sort($qk);
            $parts = [];
            foreach ($qk as $k) {
                $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$query[$k]);
            }
            $canonicalQuery = implode('&', $parts);
        }

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
            $canonicalQuery,
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
    
    // ★ (2026-10-31 13:37, 34차 후속 개선) 설정 접두어를 'a/b/' 꼴로 정규화(끝 '/' 가 없으면 'backup'+'a.txt' = 'backupa.txt' 가 됐음). 없으면 ''
    private function _pfx(): string {
        $p = trim((string)($this->config['prefix'] ?? ''), '/');
        return $p === '' ? '' : $p . '/';
    }
    // ★ (2026-10-31 13:37, 34차 후속 개선) 객체 요청 경로 '/bucket/<접두어><키>' (접두어가 없으면 종전과 같음)
    private function _keyPath(string $path): string {
        return '/' . ($this->config['bucket'] ?? '') . '/' . ltrim($this->_pfx() . ltrim($path, '/'), '/');
    }
    // ★ (2026-10-31 13:37, 34차 후속 개선) 목록이 돌려준 전체 키에서 설정 접두어를 뗌(떼지 않으면 그 경로로 다시 읽을 때 접두어가 두 번 붙었음)
    private function _relKey(string $key): string {
        $p = $this->_pfx();
        return ($p !== '' && strncmp($key, $p, strlen($p)) === 0) ? substr($key, strlen($p)) : $key;
    }
    
    /**
     * ★ (2026-11-04 01:35, 54차 점검) endpoint 를 [스킴, 호스트[:포트]] 로 — 'http://'·'https://' 로 시작하면 그 스킴을 쓰고 Host·서명에는 호스트만.
     *   종전엔 늘 'https://'.endpoint 라 URL 을 넣으면 'https://https://…' 로 요청이 아예 안 나갔고 http 전용(사내 MinIO 등)은 쓸 수 없었음(소켓으로 재현).
     *   스킴이 없으면 종전과 똑같이 https + 입력값 그대로
     */
    private function _ep(): array {
        $ep = $this->config['endpoint'] ?? 's3.amazonaws.com';
        if (is_string($ep) && preg_match('#^\s*(https?)://([^/]*)#i', $ep, $m)) return [strtolower($m[1]), $m[2]];
        return ['https', $ep];
    }

    private function request(string $method, string $path, string $body = '', array $query = []): array {
        [$scheme, $endpoint] = $this->_ep();   // ★ (2026-11-04 01:35, 54차 점검) 스킴 있는 endpoint 지원(_ep 참고) — 종전 $this->config['endpoint'] ?? 's3.amazonaws.com'
        $bucket = $this->config['bucket'] ?? '';
        
        // ★ (2026-10-31 13:37, 34차 후속 개선) 설정 접두어(prefix)를 버킷 단위 목록 요청(list-type) 경로에도 붙여 '/bucket/<prefix>?list-type=2'(= 그 키의 GetObject)가 되어
        //   연결·목록이 깨졌음 → 목록 요청은 경로를 '/bucket/' 로만 보내고 쿼리 prefix 앞에 설정 접두어를 붙임. 객체 요청은 _keyPath()(끝 '/' 보정)로
        if (isset($query['list-type'])) {
            $fullPath = '/' . $bucket . '/';
            $query['prefix'] = $this->_pfx() . ($query['prefix'] ?? '');
        } else {
            $fullPath = $this->_keyPath($path);
        }
        // 경로의 각 세그먼트를 URL 인코딩 (특수문자 안전 처리, /는 유지)
        $encodedFullPath = implode('/', array_map('rawurlencode', explode('/', $fullPath)));
        $uri = $scheme . '://' . $endpoint . $encodedFullPath;   // ★ (2026-11-04 01:35, 54차 점검) 종전 'https://' 고정

        // ★ (2026-10-29 03:11, 16차) URL 의 쿼리도 서명과 똑같이 rawurlencode(정렬)로 만들어 서명과 어긋나지 않게(http_build_query 는 공백을 + 로 등 RFC3986 과 다름)
        if (!empty($query)) {
            $qk = array_keys($query); sort($qk);
            $qp = [];
            foreach ($qk as $k) $qp[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$query[$k]);
            $uri .= '?' . implode('&', $qp);
        }

        $headers = ['Host' => $endpoint];
        $headers = $this->sign($method, $fullPath, $headers, $body, $query);
        
        $ch = curl_init($uri);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_TIMEOUT => 30
        ]);
        // ★ (2026-10-29 20:29, 23차 점검) HEAD 는 본문 없이 — CUSTOMREQUEST 만 HEAD 로 주면 curl 이 응답의 Content-Length(=파일 크기)만큼 본문을 기다리다
        //   30초 시간 초과 뒤에야 돌아왔음(Content-Length 를 주는 서버로 재현). 22차에 삭제 전 HEAD 확인을 넣어 파일 삭제·이동마다 30초씩 걸릴 수 있었음
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        
        // ★ (2026-10-29 01:43, 15차) WebDAV 와 같은 문제 — empty() 가 "0" 을 빈 값으로 봐 내용 '0' 인 파일의 본문이 빠졌음(서명은 '0' 기준이라 어긋남). 빈 문자열만 뺌 — S3 서버가 없어 소스로만 확인
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method === 'PUT' || $method === 'POST') {
            // ★ (2026-10-29 22:02, 24차 점검) 빈 본문 PUT 에도 Content-Length: 0 — 없으면 curl 이 길이 머리글을 안 보내(시험 서버로 확인) 실제 S3·MinIO 는
            //   411(MissingContentLength)로 거부해 새 폴더 만들기(빈 '이름/' 객체)·0바이트 파일 저장이 실패함(moto 는 받아 줘 시험에서 안 드러났음)
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers), ['Content-Length: 0']));
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
    
    public bool $listFailed = false;   // ★ (2026-10-29 09:23, 20차) 목록 실패 표시 — list() 는 실패해도 [] 를 돌려줘 '빈 폴더' 와 구분이 안 돼, 원격 폴더 휴지통·이동 때 실패를 빈 폴더로 보고 원본을 지웠음. 호출부가 listFailed 로 확인
    public function list(string $path): array {
        $this->listFailed = false;
        $prefix = ltrim($path, '/');
        // ★ (2026-11-01 17:18, 40차 점검) !empty() 는 '0' 을 빈 값으로 봐 폴더 '0' 의 접두어가 '0/' 이 아닌 '0' 이 되어 형제('0'·'00'·'0a.txt')가 보이고 실제 내용은 숨었음 → 빈 문자열만 제외
        if ($prefix !== '' && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        // ★ (2026-10-29 22:02, 24차 점검) 다음 쪽 이어 받기(ListObjectsV2 는 한 번에 최대 1000개) — 종전엔 첫 1000개만 읽어 큰 폴더가 일부만 보였고,
        //   폴더 이동·삭제가 앞 1000개만 처리한 뒤 실패했음
        $items = [];
        $contToken = null;
        $pages = 0;
        do {
        $q = ['list-type' => '2', 'prefix' => $prefix, 'delimiter' => '/'];
        if ($contToken !== null) $q['continuation-token'] = $contToken;
        $result = $this->request('GET', '', '', $q);
        
        if ($result['code'] !== 200) { $this->listFailed = true; return []; }
        
        $xml = @simplexml_load_string($result['body']);
        if (!$xml) { $this->listFailed = true; return []; }
        
        // 폴더
        foreach ($xml->CommonPrefixes ?? [] as $cp) {
            $key = $this->_relKey((string)$cp->Prefix);   // ★ (2026-10-31 13:37, 34차 후속 개선) 접두어 뗌
            $name = basename(rtrim($key, '/'));
            // ★ (2026-11-01 17:18, 40차 점검) 'share/../' 같은 키 접두어가 '..' 폴더로 목록에 섞였음(동기화가 이 이름으로 대상 스토리지 밖에 씀, C2 재현) → FTP·SFTP·Local 처럼 빈 이름·'.'·'..' 건너뜀
            if ($name === '' || $name === '.' || $name === '..') continue;
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
            $key = $this->_relKey((string)$content->Key);   // ★ (2026-10-31 13:37, 34차 후속 개선) 접두어 뗌
            if ($key === $prefix) continue;
            $name = basename($key);
            if ($name === '' || $name === '.' || $name === '..') continue;   // ★ (2026-11-01 17:18, 40차 점검) 'share/..' 같은 키가 '..' 파일로 섞였음 → 건너뜀(위 폴더와 같은 이유)
            $items[] = [
                'name' => $name,
                'path' => $key,
                'is_dir' => false,
                'size' => (int)$content->Size,
                'modified' => strtotime((string)$content->LastModified)
            ];
        }
        $contToken = (strtolower((string)($xml->IsTruncated ?? '')) === 'true' && (string)($xml->NextContinuationToken ?? '') !== '') ? (string)$xml->NextContinuationToken : null;
        } while ($contToken !== null && ++$pages < 1000);
        
        return $items;
    }
    
    /** ★ (2026-10-29 22:02, 24차 점검) 접두어 아래 모든 객체 키(하위 폴더 포함, 이어 받기) — 폴더 이름 바꾸기용. 실패면 null */
    private function listAllKeys(string $prefix): ?array {
        $keys = []; $tok = null; $pages = 0;
        do {
            $q = ['list-type' => '2', 'prefix' => $prefix];
            if ($tok !== null) $q['continuation-token'] = $tok;
            $r = $this->request('GET', '', '', $q);
            if ($r['code'] !== 200) return null;
            $xml = @simplexml_load_string($r['body']);
            if (!$xml) return null;
            foreach ($xml->Contents ?? [] as $c) $keys[] = $this->_relKey((string)$c->Key);   // ★ (2026-10-31 13:37, 34차 후속 개선) 접두어 뗀 키(이름 바꾸기가 이 키로 다시 요청함)
            $tok = (strtolower((string)($xml->IsTruncated ?? '')) === 'true' && (string)($xml->NextContinuationToken ?? '') !== '') ? (string)$xml->NextContinuationToken : null;
        } while ($tok !== null && ++$pages < 1000);
        return $keys;
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
        [$scheme, $endpoint] = $this->_ep();   // ★ (2026-11-04 01:35, 54차 점검) 스킴 있는 endpoint 지원(request() 와 같음) — 종전 'https://' 고정
        $fullPath = $this->_keyPath($path);   // ★ (2026-10-31 13:37, 34차 후속 개선) 접두어 끝 '/' 보정(request() 와 같은 규칙)
        $encodedFullPath = implode('/', array_map('rawurlencode', explode('/', $fullPath)));
        $uri = $scheme . '://' . $endpoint . $encodedFullPath;
        
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
    
    /**
     * ★ (2026-10-31 13:38, 34차 후속 개선) S3 도 받은 만큼 바로 출력하는 다운로드 — 종전엔 없어서 downloadRemote 가 read()(전송 전체 30초 제한)를 echo 해
     *   30초 넘는 파일은 Content-Length 만 크고 본문이 비었음. 서명은 readPartial 과 같은 방식, 시간 제한은 저속 제한(1분간 초당 1KB 미만이면 중단)
     */
    public function streamToOutput(string $path): bool {
        if (!function_exists('curl_init')) return false;
        [$scheme, $endpoint] = $this->_ep();   // ★ (2026-11-04 01:35, 54차 점검) 스킴 있는 endpoint 지원(request() 와 같음) — 종전 'https://' 고정
        $fullPath = $this->_keyPath($path);
        $encodedFullPath = implode('/', array_map('rawurlencode', explode('/', $fullPath)));
        $headers = $this->sign('GET', $fullPath, ['Host' => $endpoint], '');
        $ch = curl_init($scheme . '://' . $endpoint . $encodedFullPath);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_WRITEFUNCTION => function($ch, $data) {
                if ((int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200) return 0;   // 오류 응답(XML) 본문은 내보내지 않음
                if (connection_aborted()) return 0;
                echo $data;
                flush();
                return strlen($data);
            },
        ]);
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $code === 200 && $result !== false && ($errno === 0 || $errno === 23);
    }
    
    public function write(string $path, string $content): bool {
        $result = $this->request('PUT', $path, $content);
        return $result['code'] === 200;
    }
    
    public function delete(string $path): bool {
        // ★ (2026-10-29 18:18, 22차 점검) 그 이름의 객체(파일)가 없으면 폴더 표시 객체('이름/')를 지움 — 폴더 삭제(deleteRemoteDir)는 마지막에 delete('폴더') 를 부르는데
        //   S3 는 'folder' 와 'folder/' 가 다른 키라, 내용을 다 지워도 빈 폴더가 남아 목록에 계속 보였음(moto S3 로 재현).
        //   파일이면 종전대로 그 키만 지움(버전 관리 버킷에 쓸데없는 삭제 표시가 쌓이지 않게 — 변경 검토 반영)
        // ★ (2026-10-29 20:29, 23차 점검) '없음(404)'일 때만 폴더 표시로 봄 — 권한 없음(403) 등에서 파일은 남긴 채 성공으로 돌려주던 것 막음(그때는 종전대로 그 키를 지움)
        if (!str_ends_with($path, '/') && trim($path, '/') !== '' && $this->request('HEAD', $path)['code'] === 404) {
            $result = $this->request('DELETE', rtrim($path, '/') . '/');
            return $result['code'] === 204 || $result['code'] === 200;
        }
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
        // ★ (2026-10-29 09:23, 20차) 읽기 성공(200)을 확인한 뒤에만 쓰고 지움 — 종전엔 read() 가 실패해도 '' 를 돌려줘(시간 초과·403 등) 빈 객체를 만들고 원본을 지웠음
        $r = $this->request('GET', $from);
        if ($r['code'] === 404 && $this->isDir($from)) {
            // ★ (2026-10-29 22:02, 24차 점검) 폴더(키 앞부분)는 아래 객체를 모두 새 이름으로 복사한 뒤에만 원본을 지움(하나라도 실패하면 만든 사본을 지우고 실패) —
            //   종전엔 '그 키 GET' 만 해서 폴더는 늘 404 → S3 폴더 이름 바꾸기·같은 S3 안 폴더 이동이 늘 실패했음
            $src = rtrim(ltrim($from, '/'), '/') . '/';
            $dst = rtrim(ltrim($to, '/'), '/') . '/';
            $keys = $this->listAllKeys($src);
            if ($keys === null) return false;
            $made = [];
            foreach ($keys as $k) {
                $nk = $dst . substr($k, strlen($src));
                if (str_ends_with($k, '/')) { $ok = $this->request('PUT', $nk)['code'] === 200; }
                else { $g = $this->request('GET', $k); $ok = $g['code'] === 200 && is_string($g['body']) && $this->write($nk, $g['body']); }
                if (!$ok) { foreach ($made as $m) $this->request('DELETE', $m); return false; }
                $made[] = $nk;
            }
            if (!in_array($dst, $made, true)) $this->request('PUT', $dst);   // 새 이름의 폴더 표시
            foreach ($keys as $k) $this->request('DELETE', $k);
            if (!in_array($src, $keys, true)) $this->request('DELETE', $src);
            return true;
        }
        if ($r['code'] !== 200 || !is_string($r['body'])) return false;
        if ($this->write($to, $r['body'])) {
            return $this->delete($from);
        }
        return false;
    }
    
    public function exists(string $path): bool {
        $result = $this->request('HEAD', $path);
        // ★ (2026-10-29 18:18, 22차 점검) 폴더(그 이름 아래 객체가 있음)도 있는 것으로 — 종전엔 객체 키만 봐서 S3 폴더는 늘 '없음'(이름 바꾸기·이동이 '파일을 찾을 수 없음')
        return $result['code'] === 200 || (!str_ends_with($path, '/') && $this->isDir($path));
    }
    
    public function isDir(string $path): bool {
        if (str_ends_with($path, '/')) return true;
        // ★ (2026-10-29 18:18, 22차 점검) 끝에 '/' 가 없어도 그 이름 아래에 객체가 있으면 폴더 — list() 는 폴더 경로를 '/' 없이 돌려주는데 종전엔
        //   '/' 로 끝날 때만 폴더로 봐서, 폴더 삭제가 '파일'로 처리돼 0바이트 휴지통 항목만 생기고 폴더와 내용은 그대로 남았음(moto S3 로 재현)
        $key = trim($path, '/');
        if ($key === '') return true;
        $r = $this->request('GET', '', '', ['list-type' => '2', 'prefix' => $key . '/', 'max-keys' => '1']);
        if ($r['code'] !== 200) return false;
        $xml = @simplexml_load_string((string)$r['body']);
        if ($xml === false) return false;
        return (int)($xml->KeyCount ?? 0) > 0 || count($xml->Contents ?? []) > 0 || count($xml->CommonPrefixes ?? []) > 0;
    }
    
    public function getSize(string $path): int {
        // ★ (2026-10-29 09:23, 20차) 실제 크기 — 종전엔 늘 0 을 돌려줘 S3 파일을 받으면 0바이트(Content-Length: 0)·이어받기 416 이 났고,
        //   '내용이 비었는데 크기가 0 보다 크면 읽기 실패' 판정도 S3 에선 늘 거짓이었음. 목록 요청(ListObjectsV2, list() 와 같은 방식)으로 그 키의 Size 를 읽음
        $key = ltrim($path, '/');
        if ($key === '' || str_ends_with($key, '/')) return 0;
        $r = $this->request('GET', '', '', ['list-type' => '2', 'prefix' => $key, 'max-keys' => '10']);
        if ($r['code'] !== 200) return 0;
        $xml = @simplexml_load_string((string)$r['body']);
        if (!$xml) return 0;
        foreach ($xml->Contents ?? [] as $c) {
            if ($this->_relKey((string)$c->Key) === $key) return (int)$c->Size;   // ★ (2026-10-31 13:37, 34차 후속 개선) 돌려받은 키는 접두어 포함
        }
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
            $this->lastError = __('api_err_smb_no_host', 'SMB 호스트가 지정되지 않았습니다.');   // ★ (2026-11-02 10:25, 42차 점검) SMB 연결 오류 3곳이 번역 밖 한국어라 영어 화면에도 한국어로 나왔음(E3) → 키로
            return false;
        }
        
        // 소켓 레벨 도달 가능성 사전 체크 (빠른 실패)
        $sock = @fsockopen($host, $port, $errno, $errstr, $connectTimeout);
        if (!$sock) {
            $this->lastError = __f('smb_connect_fail_detail', ['host' => $host, 'port' => $port, 'timeout' => $connectTimeout, 'error' => $errstr], 'SMB 서버 연결 실패: {host}:{port} (Timeout: {timeout}s, Error: {error})');   // ★ (2026-11-02 10:25, 42차 점검) 위와 같음
            return false;
        }
        @fclose($sock);
        
        // 실제 UNC 경로 접근 가능 여부 확인
        if (!@is_dir($this->basePath)) {
            $this->lastError = __f('smb_share_unreachable', ['path' => $this->basePath], 'SMB 공유 폴더 접근 불가: {path}');   // ★ (2026-11-02 10:25, 42차 점검) 위와 같음
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
        // ★ (2026-10-29 09:14, 20차) 구간 단위로 '.'·'..'·빈 구간을 버림 — 종전엔 '../' 를 한 번만 지워 '....//x' 가 '../x' 가 되어
        //   설정한 하위 폴더 밖(같은 공유의 다른 폴더)을 열 수 있었음
        $segs = [];
        foreach (preg_split('#[\\\\/]+#', str_replace("\0", '', $path)) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || rtrim($seg, ' .') === '') continue;
            $segs[] = $seg;
        }
        $path = implode('\\', $segs);
        return $this->basePath . ($path ? "\\{$path}" : '');
    }
    
    public bool $listFailed = false;   // ★ (2026-10-29 09:23, 20차) 목록 실패 표시 — list() 는 실패해도 [] 를 돌려줘 '빈 폴더' 와 구분이 안 돼, 원격 폴더 휴지통·이동 때 실패를 빈 폴더로 보고 원본을 지웠음. 호출부가 listFailed 로 확인
    public function list(string $path): array {
        $this->listFailed = false;
        if (!$this->connected) { $this->listFailed = true; return []; }
        
        $dir = $this->fullPath($path);
        $items = [];
        
        $entries = @scandir($dir);
        if ($entries === false) { $this->listFailed = true; return []; }
        
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
    
    // ★ (2026-11-09 15:51, 59차 M) 받기·원격→로컬 복사용 흘려 보내기(다른 원격 어댑터와 같은 이름) — 종전엔 없어 read() 로 파일 전체를 메모리에 올렸음(300MB 받기에 PHP 메모리 약 363MB, 재현). 끝까지 읽었을 때만 true
    public function streamToOutput(string $path): bool {
        if (!$this->connected) return false;
        $fp = @fopen($this->fullPath($path), 'rb');
        if (!$fp) return false;
        $ok = true;
        while (!feof($fp)) {
            $buf = @fread($fp, 1048576);
            if ($buf === false) { $ok = false; break; }
            if ($buf === '') { if (!feof($fp)) $ok = false; break; }
            echo $buf;
            flush();
            if (connection_aborted()) { $ok = false; break; }
        }
        @fclose($fp);
        return $ok;
    }

    public function write(string $path, string $content): bool {
        if (!$this->connected) return false;
        $full = $this->fullPath($path);
        $dir = dirname($full);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return file_put_contents($full, $content) !== false;
    }
    
    // ★ (2026-11-10 13:18, 63차) 로컬 파일을 메모리에 통째로 올리지 않고 흘려 복사(FTP·WebDAV writeFromFile 과 같은 이름) — 종전엔 없어 원격→SMB 폴더 복사·로컬→SMB 업로드·복사가 파일 전체를 문자열로 읽어 write() 에 넘겼음. write() 와 같은 방식('wb' 로 새로 씀·상위 폴더 만들기)이고 copy() 는 쓰지 않음(Windows 에선 속성·시각까지 복사될 수 있어 종전 결과와 달라짐). 로컬 파일을 못 열면 아무것도 만들지 않고 false, 다 쓰지 못하면 false
    public function writeFromFile(string $path, string $localFile): bool {
        if (!$this->connected) return false;
        $src = @fopen($localFile, 'rb');
        if (!$src) return false;
        $st = @fstat($src);
        $size = is_array($st) ? (int)$st['size'] : -1;
        $full = $this->fullPath($path);
        $dir = dirname($full);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $dst = fopen($full, 'wb');
        if (!$dst) { fclose($src); return false; }
        $n = stream_copy_to_stream($src, $dst);
        fclose($dst);
        fclose($src);
        return $n !== false && $n === $size;
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

