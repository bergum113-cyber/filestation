<?php
/**
 * MinimalSFTP - 최소 SSH2/SFTP 클라이언트 (순수 PHP)
 * 
 * phpseclib3 대체용. PHP openssl 확장 필요.
 * FileStation SFTP 어댑터에서 특수문자(#, ? 등) 경로 지원을 위해 사용.
 * URL 스트림 래퍼를 사용하지 않으므로 모든 특수문자 경로 안전.
 * 
 * 지원: password 인증, 디렉토리 목록, 파일 읽기/쓰기/삭제/이름변경
 * 미지원: 공개키 인증 (ssh2 확장 fallback 사용), 압축
 * 
 * @license MIT
 */

class MinimalSFTP {
    private $socket = null;
    private string $sessionId = '';
    private int $sequenceSend = 0;
    private int $sequenceRecv = 0;
    
    // 암호화 관련
    private $encryptCipher = null;
    private $decryptCipher = null;
    private string $encryptIV = '';
    private string $decryptIV = '';
    private string $encryptKey = '';
    private string $decryptKey = '';
    private string $macKeySend = '';
    private string $macKeyRecv = '';
    private int $blockSize = 16;
    private int $macLength = 32;
    private bool $encrypted = false;
    
    // SFTP 관련
    private $sftpChannel = null;
    private int $channelId = 0;
    private int $sftpRequestId = 0;
    private string $sftpBuffer = '';
    private int $remoteWindowSize = 0;
    private int $remoteMaxPacket = 32768;
    
    private string $lastError = '';
    private bool $connected = false;
    
    // SSH 메시지 타입
    private const SSH_MSG_DISCONNECT = 1;
    private const SSH_MSG_IGNORE = 2;
    private const SSH_MSG_UNIMPLEMENTED = 3;
    private const SSH_MSG_DEBUG = 4;
    private const SSH_MSG_SERVICE_REQUEST = 5;
    private const SSH_MSG_SERVICE_ACCEPT = 6;
    private const SSH_MSG_KEXINIT = 20;
    private const SSH_MSG_NEWKEYS = 21;
    private const SSH_MSG_KEXDH_INIT = 30;
    private const SSH_MSG_KEXDH_REPLY = 31;
    private const SSH_MSG_USERAUTH_REQUEST = 50;
    private const SSH_MSG_USERAUTH_FAILURE = 51;
    private const SSH_MSG_USERAUTH_SUCCESS = 52;
    private const SSH_MSG_GLOBAL_REQUEST = 80;
    private const SSH_MSG_REQUEST_SUCCESS = 81;
    private const SSH_MSG_REQUEST_FAILURE = 82;
    private const SSH_MSG_CHANNEL_OPEN = 90;
    private const SSH_MSG_CHANNEL_OPEN_CONFIRMATION = 91;
    private const SSH_MSG_CHANNEL_OPEN_FAILURE = 92;
    private const SSH_MSG_CHANNEL_WINDOW_ADJUST = 93;
    private const SSH_MSG_CHANNEL_DATA = 94;
    private const SSH_MSG_CHANNEL_EOF = 96;
    private const SSH_MSG_CHANNEL_CLOSE = 97;
    private const SSH_MSG_CHANNEL_REQUEST = 98;
    private const SSH_MSG_CHANNEL_SUCCESS = 99;
    private const SSH_MSG_CHANNEL_FAILURE = 100;
    
    // SFTP 패킷 타입
    private const SSH_FXP_INIT = 1;
    private const SSH_FXP_VERSION = 2;
    private const SSH_FXP_OPEN = 3;
    private const SSH_FXP_CLOSE = 4;
    private const SSH_FXP_READ = 5;
    private const SSH_FXP_WRITE = 6;
    private const SSH_FXP_LSTAT = 7;
    private const SSH_FXP_FSTAT = 8;
    private const SSH_FXP_SETSTAT = 9;
    private const SSH_FXP_OPENDIR = 11;
    private const SSH_FXP_READDIR = 12;
    private const SSH_FXP_REMOVE = 13;
    private const SSH_FXP_MKDIR = 14;
    private const SSH_FXP_RMDIR = 15;
    private const SSH_FXP_REALPATH = 16;
    private const SSH_FXP_STAT = 17;
    private const SSH_FXP_RENAME = 18;
    private const SSH_FXP_READLINK = 19;
    private const SSH_FXP_SYMLINK = 20;
    private const SSH_FXP_STATUS = 101;
    private const SSH_FXP_HANDLE = 102;
    private const SSH_FXP_DATA = 103;
    private const SSH_FXP_NAME = 104;
    private const SSH_FXP_ATTRS = 105;
    
    // SFTP 상태 코드
    private const SSH_FX_OK = 0;
    private const SSH_FX_EOF = 1;
    
    // SFTP 파일 플래그
    private const SSH_FXF_READ = 0x00000001;
    private const SSH_FXF_WRITE = 0x00000002;
    private const SSH_FXF_CREAT = 0x00000008;
    private const SSH_FXF_TRUNC = 0x00000010;
    
    // SFTP 속성 플래그
    private const SSH_FILEXFER_ATTR_SIZE = 0x00000001;
    private const SSH_FILEXFER_ATTR_UIDGID = 0x00000002;
    private const SSH_FILEXFER_ATTR_PERMISSIONS = 0x00000004;
    private const SSH_FILEXFER_ATTR_ACMODTIME = 0x00000008;
    
    public function __construct(private string $host, private int $port = 22, private int $timeout = 10) {}
    
    public function getLastError(): string {
        return $this->lastError;
    }
    
    /**
     * SFTP 접속 (SSH 연결 + 인증 + SFTP 서브시스템)
     */
    public function login(string $username, string $password): bool {
        try {
            if (!$this->connectSocket()) return false;
            if (!$this->exchangeVersions()) return false;
            if (!$this->keyExchange()) return false;
            if (!$this->authenticate($username, $password)) return false;
            if (!$this->openSftpChannel()) return false;
            $this->connected = true;
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }
    
    public function disconnect(): void {
        if ($this->socket) {
            try {
                $this->sendPacket(pack('CN', self::SSH_MSG_DISCONNECT, 11) . $this->packString('bye') . $this->packString(''));
            } catch (\Throwable $e) {}
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }
    
    // ===== SFTP 공개 API =====
    
    /**
     * 디렉토리 목록 (rawlist)
     * @return array|false [name => [size, mtime, type, permissions], ...]
     */
    public function rawlist(string $path): array|false {
        if (!$this->connected) return false;
        
        $handle = $this->sftpRequest(self::SSH_FXP_OPENDIR, $this->packString($path));
        if ($handle === false) return false;
        
        $entries = [];
        while (true) {
            $response = $this->sftpRequest(self::SSH_FXP_READDIR, $handle);
            if ($response === false) break;
            
            $data = $response;
            $count = $this->unpackInt($data);
            
            for ($i = 0; $i < $count; $i++) {
                $name = $this->unpackString($data);
                $longname = $this->unpackString($data);
                $attrs = $this->parseAttrs($data);
                $entries[$name] = $attrs;
            }
        }
        
        // 핸들 닫기
        $this->sftpRequest(self::SSH_FXP_CLOSE, $handle);
        
        return $entries;
    }
    
    /**
     * 파일 읽기
     */
    public function get(string $remotePath): string|false {
        if (!$this->connected) return false;
        
        $flags = pack('N', self::SSH_FXF_READ);
        $handle = $this->sftpRequest(self::SSH_FXP_OPEN, $this->packString($remotePath) . $flags . pack('N', 0));
        if ($handle === false) return false;
        
        $content = '';
        $offset = 0;
        $chunkSize = 32768;
        
        while (true) {
            $reqData = $handle . pack('N2', ($offset >> 32) & 0xFFFFFFFF, $offset & 0xFFFFFFFF) . pack('N', $chunkSize);
            $response = $this->sftpRequest(self::SSH_FXP_READ, $reqData);
            if ($response === false) break;
            
            $chunk = $this->unpackString($response);
            if ($chunk === '') break;
            $content .= $chunk;
            $offset += strlen($chunk);
        }
        
        $this->sftpRequest(self::SSH_FXP_CLOSE, $handle);
        return $content;
    }
    
    /**
     * 파일 쓰기
     */
    public function put(string $remotePath, string $content): bool {
        if (!$this->connected) return false;
        
        $flags = pack('N', self::SSH_FXF_WRITE | self::SSH_FXF_CREAT | self::SSH_FXF_TRUNC);
        $handle = $this->sftpRequest(self::SSH_FXP_OPEN, $this->packString($remotePath) . $flags . pack('N', 0));
        if ($handle === false) return false;
        
        $offset = 0;
        $chunkSize = 32768;
        $success = true;
        
        while ($offset < strlen($content)) {
            $chunk = substr($content, $offset, $chunkSize);
            $reqData = $handle . pack('N2', ($offset >> 32) & 0xFFFFFFFF, $offset & 0xFFFFFFFF) . $this->packString($chunk);
            $result = $this->sftpRequest(self::SSH_FXP_WRITE, $reqData);
            if ($result === false) {
                $success = false;
                break;
            }
            $offset += strlen($chunk);
        }
        
        $this->sftpRequest(self::SSH_FXP_CLOSE, $handle);
        return $success;
    }
    
    /**
     * 파일/디렉토리 stat
     */
    public function stat(string $path): array|false {
        if (!$this->connected) return false;
        
        $response = $this->sftpRequest(self::SSH_FXP_STAT, $this->packString($path));
        if ($response === false) return false;
        
        return $this->parseAttrs($response);
    }
    
    /**
     * 디렉토리 여부
     */
    public function is_dir(string $path): bool {
        $stat = $this->stat($path);
        if (!$stat) return false;
        return (($stat['permissions'] ?? 0) & 0040000) !== 0;
    }
    
    /**
     * 파일 삭제
     */
    public function delete(string $path): bool {
        if (!$this->connected) return false;
        $result = $this->sftpRequest(self::SSH_FXP_REMOVE, $this->packString($path));
        return $result !== false;
    }
    
    /**
     * 디렉토리 삭제
     */
    public function rmdir(string $path): bool {
        if (!$this->connected) return false;
        $result = $this->sftpRequest(self::SSH_FXP_RMDIR, $this->packString($path));
        return $result !== false;
    }
    
    /**
     * 디렉토리 생성
     */
    public function mkdir(string $path, int $mode = -1, bool $recursive = false): bool {
        if (!$this->connected) return false;
        
        if ($recursive) {
            $parts = explode('/', trim($path, '/'));
            $current = '';
            foreach ($parts as $part) {
                $current .= '/' . $part;
                $stat = $this->stat($current);
                if ($stat === false) {
                    $attrs = ($mode > 0) ? (pack('N', self::SSH_FILEXFER_ATTR_PERMISSIONS) . pack('N', $mode)) : pack('N', 0);
                    $this->sftpRequest(self::SSH_FXP_MKDIR, $this->packString($current) . $attrs);
                }
            }
            return $this->is_dir($path);
        }
        
        $attrs = ($mode > 0) ? (pack('N', self::SSH_FILEXFER_ATTR_PERMISSIONS) . pack('N', $mode)) : pack('N', 0);
        $result = $this->sftpRequest(self::SSH_FXP_MKDIR, $this->packString($path) . $attrs);
        return $result !== false;
    }
    
    /**
     * 이름 변경
     */
    public function rename(string $from, string $to): bool {
        if (!$this->connected) return false;
        $result = $this->sftpRequest(self::SSH_FXP_RENAME, $this->packString($from) . $this->packString($to));
        return $result !== false;
    }
    
    // ===== SSH 프로토콜 구현 =====
    
    private function connectSocket(): bool {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            $this->lastError = "연결 실패: {$this->host}:{$this->port} - {$errstr}";
            return false;
        }
        stream_set_timeout($this->socket, $this->timeout);
        return true;
    }
    
    private function exchangeVersions(): bool {
        // 서버 버전 읽기
        $serverVersion = '';
        while (true) {
            $line = fgets($this->socket, 256);
            if ($line === false) {
                $this->lastError = 'SSH 버전 교환 실패';
                return false;
            }
            $line = rtrim($line, "\r\n");
            if (str_starts_with($line, 'SSH-')) {
                $serverVersion = $line;
                break;
            }
        }
        
        // 클라이언트 버전 전송
        $clientVersion = 'SSH-2.0-FileStation_MinimalSFTP';
        fwrite($this->socket, $clientVersion . "\r\n");
        
        $this->sessionId = $clientVersion . '|' . $serverVersion;
        return true;
    }
    
    private function keyExchange(): bool {
        // 서버 KEXINIT 수신
        $serverKexInit = $this->receivePacket();
        if ($serverKexInit === false || ord($serverKexInit[0]) !== self::SSH_MSG_KEXINIT) {
            $this->lastError = 'KEX INIT 수신 실패';
            return false;
        }
        $serverKexPayload = $serverKexInit;
        
        // 서버가 지원하는 알고리즘 파싱
        $pos = 17; // 1(type) + 16(cookie)
        $serverAlgos = [];
        for ($i = 0; $i < 10; $i++) {
            $len = unpack('N', substr($serverKexInit, $pos, 4))[1];
            $pos += 4;
            $serverAlgos[] = explode(',', substr($serverKexInit, $pos, $len));
            $pos += $len;
        }
        
        // 알고리즘 선택
        $kexAlgo = $this->negotiateAlgo($serverAlgos[0], [
            'curve25519-sha256', 'curve25519-sha256@libssh.org',
            'diffie-hellman-group14-sha256', 'diffie-hellman-group14-sha1',
            'diffie-hellman-group-exchange-sha256', 'diffie-hellman-group-exchange-sha1',
        ]);
        $hostKeyAlgo = $this->negotiateAlgo($serverAlgos[1], [
            'ssh-ed25519', 'rsa-sha2-256', 'rsa-sha2-512', 'ssh-rsa',
        ]);
        $encAlgoCS = $this->negotiateAlgo($serverAlgos[2], [
            'aes128-ctr', 'aes192-ctr', 'aes256-ctr', 'aes128-cbc', 'aes256-cbc',
        ]);
        $encAlgoSC = $this->negotiateAlgo($serverAlgos[3], [
            'aes128-ctr', 'aes192-ctr', 'aes256-ctr', 'aes128-cbc', 'aes256-cbc',
        ]);
        $macAlgoCS = $this->negotiateAlgo($serverAlgos[4], [
            'hmac-sha2-256', 'hmac-sha1', 'hmac-sha2-512',
        ]);
        $macAlgoSC = $this->negotiateAlgo($serverAlgos[5], [
            'hmac-sha2-256', 'hmac-sha1', 'hmac-sha2-512',
        ]);
        
        if (!$kexAlgo || !$hostKeyAlgo || !$encAlgoCS || !$encAlgoSC || !$macAlgoCS || !$macAlgoSC) {
            $this->lastError = '알고리즘 협상 실패';
            return false;
        }
        
        // 클라이언트 KEXINIT 전송
        $clientKexPayload = $this->buildKexInit($kexAlgo, $hostKeyAlgo, $encAlgoCS, $encAlgoSC, $macAlgoCS, $macAlgoSC);
        $this->sendPacket($clientKexPayload);
        
        // DH 키 교환
        if (str_contains($kexAlgo, 'curve25519')) {
            $result = $this->kexCurve25519($clientKexPayload, $serverKexPayload, $kexAlgo);
        } elseif (str_contains($kexAlgo, 'group-exchange')) {
            $result = $this->kexDHGroupExchange($clientKexPayload, $serverKexPayload, $kexAlgo);
        } else {
            $result = $this->kexDHGroup14($clientKexPayload, $serverKexPayload, $kexAlgo);
        }
        
        if (!$result) return false;
        
        // NEWKEYS 교환
        $this->sendPacket(pack('C', self::SSH_MSG_NEWKEYS));
        $newkeys = $this->receivePacket();
        if ($newkeys === false || ord($newkeys[0]) !== self::SSH_MSG_NEWKEYS) {
            $this->lastError = 'NEWKEYS 수신 실패';
            return false;
        }
        
        // 암호화 키 생성 및 활성화
        $this->setupEncryption($encAlgoCS, $encAlgoSC, $macAlgoCS, $macAlgoSC);
        
        return true;
    }
    
    private function negotiateAlgo(array $serverList, array $clientPrefs): ?string {
        foreach ($clientPrefs as $algo) {
            if (in_array($algo, $serverList)) return $algo;
        }
        return null;
    }
    
    private function buildKexInit(string ...$algos): string {
        $cookie = random_bytes(16);
        $payload = pack('C', self::SSH_MSG_KEXINIT) . $cookie;
        
        // kex, hostkey, enc_cs, enc_sc, mac_cs, mac_sc
        foreach ($algos as $algo) {
            $payload .= $this->packString($algo);
        }
        // compression_cs, compression_sc
        $payload .= $this->packString('none') . $this->packString('none');
        // languages_cs, languages_sc
        $payload .= $this->packString('') . $this->packString('');
        // first_kex_packet_follows, reserved
        $payload .= pack('CN', 0, 0);
        
        return $payload;
    }
    
    private function kexDHGroup14(string $clientKex, string $serverKex, string $algo): bool {
        // RFC 3526 Group 14 (2048-bit MODP)
        $pHex = 'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD1' .
                '29024E088A67CC74020BBEA63B139B22514A08798E3404DD' .
                'EF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245' .
                'E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED' .
                'EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3D' .
                'C2007CB8A163BF0598DA48361C55D39A69163FA8FD24CF5F' .
                '83655D23DCA3AD961C62F356208552BB9ED529077096966D' .
                '670C354E4ABC9804F1746C08CA18217C32905E462E36CE3B' .
                'E39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9' .
                'DE2BCBF6955817183995497CEA956AE515D2261898FA0510' .
                '15728E5A8AACAA68FFFFFFFFFFFFFFFF';
        
        $p = gmp_init($pHex, 16);
        $g = gmp_init(2);
        
        // 클라이언트 비밀키 생성
        $x = gmp_init(bin2hex(random_bytes(32)), 16);
        $e = gmp_powm($g, $x, $p);
        
        // DH INIT 전송
        $eBytes = $this->gmpToBytes($e);
        $this->sendPacket(pack('C', self::SSH_MSG_KEXDH_INIT) . $this->packString($eBytes));
        
        // DH REPLY 수신
        $reply = $this->receivePacket();
        if ($reply === false || ord($reply[0]) !== self::SSH_MSG_KEXDH_REPLY) {
            $this->lastError = 'DH REPLY 수신 실패';
            return false;
        }
        
        $pos = 1;
        $hostKey = $this->extractString($reply, $pos);
        $fBytes = $this->extractString($reply, $pos);
        $signature = $this->extractString($reply, $pos);
        
        $f = gmp_import($fBytes);
        $K = gmp_powm($f, $x, $p);
        $KBytes = $this->gmpToBytes($K);
        
        // 해시 계산
        $hashAlgo = str_contains($algo, 'sha256') ? 'sha256' : 'sha1';
        $hashData = $this->packString('SSH-2.0-FileStation_MinimalSFTP');
        
        // 서버 버전 추출
        $parts = explode('|', $this->sessionId);
        $hashData .= $this->packString($parts[1] ?? '');
        $hashData = $this->packString($parts[0]) . $this->packString($parts[1] ?? '');
        $hashData .= $this->packString($clientKex);
        $hashData .= $this->packString($serverKex);
        $hashData .= $this->packString($hostKey);
        $hashData .= $this->packString($eBytes);
        $hashData .= $this->packString($fBytes);
        $hashData .= $this->packString($KBytes);
        
        $H = hash($hashAlgo, $hashData, true);
        
        if ($this->sessionId === $parts[0] . '|' . ($parts[1] ?? '')) {
            $this->sessionId = $H;
        }
        
        $this->deriveKeys($KBytes, $H, $hashAlgo);
        
        return true;
    }
    
    private function kexCurve25519(string $clientKex, string $serverKex, string $algo): bool {
        // curve25519 지원 여부 확인
        if (!function_exists('sodium_crypto_box_keypair')) {
            $this->lastError = 'sodium 확장 필요 (curve25519)';
            return false;
        }
        
        // 키 쌍 생성
        $keypair = sodium_crypto_box_keypair();
        $clientPublic = sodium_crypto_box_publickey($keypair);
        $clientSecret = sodium_crypto_box_secretkey($keypair);
        
        // 전송
        $this->sendPacket(pack('C', self::SSH_MSG_KEXDH_INIT) . $this->packString($clientPublic));
        
        // 수신
        $reply = $this->receivePacket();
        if ($reply === false || ord($reply[0]) !== self::SSH_MSG_KEXDH_REPLY) {
            $this->lastError = 'ECDH REPLY 수신 실패';
            return false;
        }
        
        $pos = 1;
        $hostKey = $this->extractString($reply, $pos);
        $serverPublic = $this->extractString($reply, $pos);
        $signature = $this->extractString($reply, $pos);
        
        // 공유 비밀 계산 (X25519)
        $sharedSecret = sodium_crypto_scalarmult($clientSecret, $serverPublic);
        
        // 앞의 0 바이트 제거 후 mpint로
        $K = ltrim($sharedSecret, "\x00");
        if (ord($K[0]) & 0x80) $K = "\x00" . $K;
        $KBytes = $K;
        
        // 해시
        $parts = explode('|', $this->sessionId);
        $hashData = $this->packString($parts[0]);
        $hashData .= $this->packString($parts[1] ?? '');
        $hashData .= $this->packString($clientKex);
        $hashData .= $this->packString($serverKex);
        $hashData .= $this->packString($hostKey);
        $hashData .= $this->packString($clientPublic);
        $hashData .= $this->packString($serverPublic);
        $hashData .= $this->packString($KBytes);
        
        $H = hash('sha256', $hashData, true);
        
        if (!str_contains($this->sessionId, "\x00")) {
            $this->sessionId = $H;
        }
        
        $this->deriveKeys($KBytes, $H, 'sha256');
        
        return true;
    }
    
    private function kexDHGroupExchange(string $clientKex, string $serverKex, string $algo): bool {
        // GEX 요청: min=2048, preferred=2048, max=4096
        $this->sendPacket(pack('CNNN', 34, 2048, 2048, 4096)); // SSH_MSG_KEX_DH_GEX_REQUEST
        
        // GEX 그룹 수신
        $group = $this->receivePacket();
        if ($group === false || ord($group[0]) !== 31) { // SSH_MSG_KEX_DH_GEX_GROUP
            $this->lastError = 'DH GEX GROUP 수신 실패';
            return false;
        }
        
        $pos = 1;
        $pBytes = $this->extractString($group, $pos);
        $gBytes = $this->extractString($group, $pos);
        
        $p = gmp_import($pBytes);
        $g = gmp_import($gBytes);
        
        // 클라이언트 비밀키 생성
        $x = gmp_init(bin2hex(random_bytes(32)), 16);
        $e = gmp_powm($g, $x, $p);
        $eBytes = $this->gmpToBytes($e);
        
        // GEX INIT 전송
        $this->sendPacket(pack('C', 32) . $this->packString($eBytes)); // SSH_MSG_KEX_DH_GEX_INIT
        
        // GEX REPLY 수신
        $reply = $this->receivePacket();
        if ($reply === false || ord($reply[0]) !== 33) { // SSH_MSG_KEX_DH_GEX_REPLY
            $this->lastError = 'DH GEX REPLY 수신 실패';
            return false;
        }
        
        $pos = 1;
        $hostKey = $this->extractString($reply, $pos);
        $fBytes = $this->extractString($reply, $pos);
        $signature = $this->extractString($reply, $pos);
        
        $f = gmp_import($fBytes);
        $K = gmp_powm($f, $x, $p);
        $KBytes = $this->gmpToBytes($K);
        
        // 해시
        $hashAlgo = str_contains($algo, 'sha256') ? 'sha256' : 'sha1';
        $parts = explode('|', $this->sessionId);
        $hashData = $this->packString($parts[0]);
        $hashData .= $this->packString($parts[1] ?? '');
        $hashData .= $this->packString($clientKex);
        $hashData .= $this->packString($serverKex);
        $hashData .= $this->packString($hostKey);
        $hashData .= pack('NNN', 2048, 2048, 4096); // min, n, max
        $hashData .= $this->packString($pBytes);
        $hashData .= $this->packString($gBytes);
        $hashData .= $this->packString($eBytes);
        $hashData .= $this->packString($fBytes);
        $hashData .= $this->packString($KBytes);
        
        $H = hash($hashAlgo, $hashData, true);
        
        if (!str_contains($this->sessionId, "\x00")) {
            $this->sessionId = $H;
        }
        
        $this->deriveKeys($KBytes, $H, $hashAlgo);
        
        return true;
    }
    
    private function gmpToBytes(\GMP $n): string {
        $bytes = gmp_export($n);
        // mpint: 양수인데 최상위 비트가 1이면 0x00 패딩
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return $bytes;
    }
    
    private function deriveKeys(string $K, string $H, string $hashAlgo): void {
        $this->encryptKey = $this->deriveKey($K, $H, 'C', 32, $hashAlgo);
        $this->decryptKey = $this->deriveKey($K, $H, 'D', 32, $hashAlgo);
        $this->encryptIV = $this->deriveKey($K, $H, 'A', 16, $hashAlgo);
        $this->decryptIV = $this->deriveKey($K, $H, 'B', 16, $hashAlgo);
        $this->macKeySend = $this->deriveKey($K, $H, 'E', 32, $hashAlgo);
        $this->macKeyRecv = $this->deriveKey($K, $H, 'F', 32, $hashAlgo);
    }
    
    private function deriveKey(string $K, string $H, string $letter, int $needed, string $hashAlgo): string {
        $key = hash($hashAlgo, $this->packString($K) . $H . $letter . $this->sessionId, true);
        while (strlen($key) < $needed) {
            $key .= hash($hashAlgo, $this->packString($K) . $H . $key, true);
        }
        return substr($key, 0, $needed);
    }
    
    private function setupEncryption(string $encCS, string $encSC, string $macCS, string $macSC): void {
        // AES-CTR 모드 사용 (가장 일반적)
        $cipherMethod = match(true) {
            str_contains($encCS, '256') => 'aes-256-ctr',
            str_contains($encCS, '192') => 'aes-192-ctr',
            default => 'aes-128-ctr',
        };
        
        if (str_contains($encCS, 'cbc')) {
            $cipherMethod = str_replace('ctr', 'cbc', $cipherMethod);
        }
        
        $keyLen = match(true) {
            str_contains($encCS, '256') => 32,
            str_contains($encCS, '192') => 24,
            default => 16,
        };
        
        $this->encryptKey = substr($this->encryptKey, 0, $keyLen);
        $this->decryptKey = substr($this->decryptKey, 0, $keyLen);
        
        $this->encryptCipher = $cipherMethod;
        $this->decryptCipher = $cipherMethod;
        $this->blockSize = 16;
        
        $this->macLength = str_contains($macCS, 'sha2-256') ? 32 : (str_contains($macCS, 'sha2-512') ? 64 : 20);
        
        $this->encrypted = true;
    }
    
    private function authenticate(string $username, string $password): bool {
        // 서비스 요청: ssh-userauth
        $this->sendPacket(pack('C', self::SSH_MSG_SERVICE_REQUEST) . $this->packString('ssh-userauth'));
        
        $response = $this->receivePacket();
        if ($response === false || ord($response[0]) !== self::SSH_MSG_SERVICE_ACCEPT) {
            $this->lastError = '서비스 요청 실패';
            return false;
        }
        
        // password 인증
        $packet = pack('C', self::SSH_MSG_USERAUTH_REQUEST);
        $packet .= $this->packString($username);
        $packet .= $this->packString('ssh-connection');
        $packet .= $this->packString('password');
        $packet .= pack('C', 0); // FALSE
        $packet .= $this->packString($password);
        
        $this->sendPacket($packet);
        
        $response = $this->receivePacket();
        if ($response === false) {
            $this->lastError = '인증 응답 수신 실패';
            return false;
        }
        
        $type = ord($response[0]);
        if ($type === self::SSH_MSG_USERAUTH_SUCCESS) {
            return true;
        }
        
        $this->lastError = '인증 실패 (잘못된 사용자명 또는 비밀번호)';
        return false;
    }
    
    private function openSftpChannel(): bool {
        $this->channelId = 0;
        
        // 채널 열기
        $packet = pack('C', self::SSH_MSG_CHANNEL_OPEN);
        $packet .= $this->packString('session');
        $packet .= pack('N', $this->channelId); // sender channel
        $packet .= pack('N', 2097152); // initial window size (2MB)
        $packet .= pack('N', 32768); // max packet size
        
        $this->sendPacket($packet);
        
        $response = $this->receivePacket();
        if ($response === false || ord($response[0]) !== self::SSH_MSG_CHANNEL_OPEN_CONFIRMATION) {
            $dbg = '채널 열기 실패: ';
            if ($response === false) {
                $dbg .= 'response=false';
            } else {
                $dbg .= 'type=' . ord($response[0]) . ',len=' . strlen($response);
            }
            $this->lastError = $dbg;
            return false;
        }
        
        $remoteChannel = unpack('N', substr($response, 1, 4))[1];
        // skip sender channel (4 bytes)
        $this->remoteWindowSize = unpack('N', substr($response, 9, 4))[1];
        $this->remoteMaxPacket = unpack('N', substr($response, 13, 4))[1];
        $this->sftpChannel = $remoteChannel;
        
        // SFTP 서브시스템 요청
        $packet = pack('C', self::SSH_MSG_CHANNEL_REQUEST);
        $packet .= pack('N', $this->sftpChannel);
        $packet .= $this->packString('subsystem');
        $packet .= pack('C', 1); // want reply
        $packet .= $this->packString('sftp');
        
        $this->sendPacket($packet);
        
        // 응답 대기 (CHANNEL_SUCCESS 또는 WINDOW_ADJUST 등)
        while (true) {
            $response = $this->receivePacket();
            if ($response === false) {
                $this->lastError = 'SFTP 서브시스템 응답 실패';
                return false;
            }
            $type = ord($response[0]);
            if ($type === self::SSH_MSG_CHANNEL_SUCCESS) break;
            if ($type === self::SSH_MSG_CHANNEL_FAILURE) {
                $this->lastError = 'SFTP 서브시스템 지원 안 됨';
                return false;
            }
            if ($type === self::SSH_MSG_CHANNEL_WINDOW_ADJUST) {
                $this->remoteWindowSize += unpack('N', substr($response, 5, 4))[1];
                continue;
            }
        }
        
        // SFTP INIT
        $sftpInit = pack('NC', 5, self::SSH_FXP_INIT) . pack('N', 3); // version 3
        $this->sendChannelData($sftpInit);
        
        // SFTP VERSION 응답
        $sftpData = $this->receiveChannelData();
        if ($sftpData === false || strlen($sftpData) < 5 || ord($sftpData[0]) !== self::SSH_FXP_VERSION) {
            $dbg = 'SFTP 초기화 실패: ';
            if ($sftpData === false) {
                $dbg .= 'receiveChannelData=false';
            } else {
                $dbg .= 'len=' . strlen($sftpData) . ',type=' . ord($sftpData[0]) . ',hex=' . bin2hex(substr($sftpData, 0, 20));
            }
            $this->lastError = $dbg;
            return false;
        }
        
        return true;
    }
    
    // ===== SFTP 프로토콜 =====
    
    private function sftpRequest(int $type, string $data): string|false {
        $this->sftpRequestId++;
        $id = $this->sftpRequestId;
        
        $payload = pack('C', $type) . pack('N', $id) . $data;
        $packet = pack('N', strlen($payload)) . $payload;
        $this->sendChannelData($packet);
        
        // 응답 수신
        $response = $this->receiveSftpResponse($id);
        if ($response === false) return false;
        
        $respType = ord($response[0]);
        $respData = substr($response, 5); // type(1) + id(4) 건너뜀
        
        switch ($respType) {
            case self::SSH_FXP_HANDLE:
                return $this->extractStringRaw($respData);
                
            case self::SSH_FXP_NAME:
                return $respData;
                
            case self::SSH_FXP_DATA:
                return $respData;
                
            case self::SSH_FXP_ATTRS:
                return $respData;
                
            case self::SSH_FXP_STATUS:
                $code = unpack('N', substr($respData, 0, 4))[1];
                if ($code === self::SSH_FX_OK) return '';
                if ($code === self::SSH_FX_EOF) return false;
                return false;
                
            default:
                return false;
        }
    }
    
    private function receiveSftpResponse(int $expectedId): string|false {
        while (true) {
            $data = $this->receiveChannelData();
            if ($data === false) return false;
            
            // 버퍼에 추가
            $this->sftpBuffer .= $data;
            
            // 완전한 SFTP 패킷 확인
            while (strlen($this->sftpBuffer) >= 4) {
                $length = unpack('N', substr($this->sftpBuffer, 0, 4))[1];
                if (strlen($this->sftpBuffer) < 4 + $length) break;
                
                $sftpPacket = substr($this->sftpBuffer, 4, $length);
                $this->sftpBuffer = substr($this->sftpBuffer, 4 + $length);
                
                return $sftpPacket;
            }
        }
    }
    
    // ===== SSH 패킷 송수신 =====
    
    private function sendPacket(string $payload): void {
        if ($this->encrypted) {
            $this->sendEncryptedPacket($payload);
        } else {
            $this->sendPlainPacket($payload);
        }
        $this->sequenceSend++;
    }
    
    private function sendPlainPacket(string $payload): void {
        $paddingMin = 4;
        $packetLen = 1 + strlen($payload); // padding_length(1) + payload
        $padding = $this->blockSize - (($packetLen + 4) % $this->blockSize);
        if ($padding < $paddingMin) $padding += $this->blockSize;
        
        $packet = pack('NC', strlen($payload) + $padding + 1, $padding);
        $packet .= $payload;
        $packet .= random_bytes($padding);
        
        fwrite($this->socket, $packet);
    }
    
    private function sendEncryptedPacket(string $payload): void {
        $paddingMin = 4;
        $packetLen = 1 + strlen($payload);
        $padding = $this->blockSize - (($packetLen + 4) % $this->blockSize);
        if ($padding < $paddingMin) $padding += $this->blockSize;
        
        $packet = pack('NC', strlen($payload) + $padding + 1, $padding);
        $packet .= $payload;
        $packet .= random_bytes($padding);
        
        // MAC 계산
        $mac = hash_hmac($this->getMacAlgo(), pack('N', $this->sequenceSend) . $packet, $this->macKeySend, true);
        
        // 암호화
        $encrypted = openssl_encrypt($packet, $this->encryptCipher, $this->encryptKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->encryptIV);
        
        // CTR 모드: IV 업데이트
        $this->encryptIV = $this->incrementIV($this->encryptIV, strlen($packet) / $this->blockSize);
        
        fwrite($this->socket, $encrypted . $mac);
    }
    
    private function receivePacket(): string|false {
        if ($this->encrypted) {
            return $this->receiveEncryptedPacket();
        }
        return $this->receivePlainPacket();
    }
    
    private function receivePlainPacket(): string|false {
        $header = $this->readExact(4);
        if ($header === false) return false;
        
        $packetLength = unpack('N', $header)[1];
        if ($packetLength > 35000) {
            $this->lastError = '패킷 크기 초과';
            return false;
        }
        
        $data = $this->readExact($packetLength);
        if ($data === false) return false;
        
        $paddingLength = ord($data[0]);
        $payload = substr($data, 1, $packetLength - $paddingLength - 1);
        
        $this->sequenceRecv++;
        return $payload;
    }
    
    private function receiveEncryptedPacket(): string|false {
        // 첫 블록 읽기 (패킷 길이 포함)
        $firstBlock = $this->readExact($this->blockSize);
        if ($firstBlock === false) return false;
        
        $decrypted = openssl_decrypt($firstBlock, $this->decryptCipher, $this->decryptKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->decryptIV);
        $this->decryptIV = $this->incrementIV($this->decryptIV, 1);
        
        $packetLength = unpack('N', substr($decrypted, 0, 4))[1];
        
        // 나머지 읽기
        $remaining = $packetLength + 4 - $this->blockSize;
        $restDecrypted = '';
        if ($remaining > 0) {
            $restEncrypted = $this->readExact($remaining);
            if ($restEncrypted === false) return false;
            
            $restDecrypted = openssl_decrypt($restEncrypted, $this->decryptCipher, $this->decryptKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->decryptIV);
            $this->decryptIV = $this->incrementIV($this->decryptIV, $remaining / $this->blockSize);
        }
        
        $fullPacket = $decrypted . $restDecrypted;
        
        // MAC 읽기 및 검증
        $mac = $this->readExact($this->macLength);
        $expectedMac = hash_hmac($this->getMacAlgo(), pack('N', $this->sequenceRecv) . $fullPacket, $this->macKeyRecv, true);
        
        if ($mac !== $expectedMac) {
            $this->lastError = 'MAC 검증 실패';
            return false;
        }
        
        $paddingLength = ord($fullPacket[4]);
        $payload = substr($fullPacket, 5, $packetLength - $paddingLength - 1);
        
        $this->sequenceRecv++;
        return $payload;
    }
    
    private function getMacAlgo(): string {
        return match($this->macLength) {
            64 => 'sha512',
            32 => 'sha256',
            default => 'sha1',
        };
    }
    
    private function incrementIV(string $iv, int $blocks): string {
        $blocks = (int)$blocks;
        $len = strlen($iv);
        for ($i = 0; $i < $blocks; $i++) {
            // Big-endian increment
            for ($j = $len - 1; $j >= 0; $j--) {
                $val = ord($iv[$j]) + 1;
                $iv[$j] = chr($val & 0xFF);
                if ($val < 256) break;
            }
        }
        return $iv;
    }
    
    // ===== 채널 데이터 =====
    
    private function sendChannelData(string $data): void {
        $offset = 0;
        while ($offset < strlen($data)) {
            $chunkSize = min(strlen($data) - $offset, $this->remoteMaxPacket - 100, $this->remoteWindowSize);
            if ($chunkSize <= 0) {
                // 윈도우 대기
                $this->receiveAndProcessPacket();
                continue;
            }
            
            $chunk = substr($data, $offset, $chunkSize);
            $packet = pack('C', self::SSH_MSG_CHANNEL_DATA);
            $packet .= pack('N', $this->sftpChannel);
            $packet .= $this->packString($chunk);
            $this->sendPacket($packet);
            
            $this->remoteWindowSize -= $chunkSize;
            $offset += $chunkSize;
        }
    }
    
    private function receiveChannelData(): string|false {
        while (true) {
            $packet = $this->receivePacket();
            if ($packet === false) return false;
            
            $type = ord($packet[0]);
            
            switch ($type) {
                case self::SSH_MSG_CHANNEL_DATA:
                    $pos = 5; // type(1) + channel(4)
                    return $this->extractString($packet, $pos);
                    
                case self::SSH_MSG_CHANNEL_WINDOW_ADJUST:
                    $this->remoteWindowSize += unpack('N', substr($packet, 5, 4))[1];
                    continue 2;
                    
                case self::SSH_MSG_CHANNEL_EOF:
                case self::SSH_MSG_CHANNEL_CLOSE:
                    return false;
                    
                case self::SSH_MSG_GLOBAL_REQUEST:
                    // 무시
                    continue 2;
                    
                case self::SSH_MSG_IGNORE:
                case self::SSH_MSG_DEBUG:
                    continue 2;
                    
                default:
                    continue 2;
            }
        }
    }
    
    private function receiveAndProcessPacket(): void {
        $packet = $this->receivePacket();
        if ($packet === false) return;
        
        $type = ord($packet[0]);
        if ($type === self::SSH_MSG_CHANNEL_WINDOW_ADJUST) {
            $this->remoteWindowSize += unpack('N', substr($packet, 5, 4))[1];
        }
    }
    
    // ===== 유틸리티 =====
    
    private function readExact(int $length): string|false {
        $data = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = @fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if ($meta['timed_out']) {
                    $this->lastError = '읽기 타임아웃';
                    return false;
                }
                return false;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $data;
    }
    
    private function packString(string $s): string {
        return pack('N', strlen($s)) . $s;
    }
    
    private function extractString(string $data, int &$pos): string {
        $len = unpack('N', substr($data, $pos, 4))[1];
        $pos += 4;
        $str = substr($data, $pos, $len);
        $pos += $len;
        return $str;
    }
    
    private function extractStringRaw(string $data): string {
        $len = unpack('N', substr($data, 0, 4))[1];
        return substr($data, 4, $len);
    }
    
    private function unpackInt(string &$data): int {
        $val = unpack('N', substr($data, 0, 4))[1];
        $data = substr($data, 4);
        return $val;
    }
    
    private function unpackString(string &$data): string {
        $len = unpack('N', substr($data, 0, 4))[1];
        $str = substr($data, 4, $len);
        $data = substr($data, 4 + $len);
        return $str;
    }
    
    private function parseAttrs(string &$data): array {
        $flags = $this->unpackInt($data);
        $attrs = ['type' => 0, 'size' => 0, 'uid' => 0, 'gid' => 0, 'permissions' => 0, 'atime' => 0, 'mtime' => 0];
        
        if ($flags & self::SSH_FILEXFER_ATTR_SIZE) {
            $hi = $this->unpackInt($data);
            $lo = $this->unpackInt($data);
            $attrs['size'] = ($hi << 32) | $lo;
        }
        
        if ($flags & self::SSH_FILEXFER_ATTR_UIDGID) {
            $attrs['uid'] = $this->unpackInt($data);
            $attrs['gid'] = $this->unpackInt($data);
        }
        
        if ($flags & self::SSH_FILEXFER_ATTR_PERMISSIONS) {
            $attrs['permissions'] = $this->unpackInt($data);
            // type 추론
            if (($attrs['permissions'] & 0040000) !== 0) {
                $attrs['type'] = 2; // directory
            } else {
                $attrs['type'] = 1; // regular file
            }
        }
        
        if ($flags & self::SSH_FILEXFER_ATTR_ACMODTIME) {
            $attrs['atime'] = $this->unpackInt($data);
            $attrs['mtime'] = $this->unpackInt($data);
        }
        
        return $attrs;
    }
}
