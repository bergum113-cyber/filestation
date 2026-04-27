<?php
/**
 * TOTP - Time-based One-Time Password 구현
 * RFC 6238 호환
 */
class TOTP {
    private const PERIOD = 30;      // 30초 간격
    private const DIGITS = 6;       // 6자리 코드
    private const ALGORITHM = 'sha1';
    
    // Base32 문자셋
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    
    /**
     * 랜덤 시크릿 키 생성 (160비트 = 32자 Base32)
     */
    public static function generateSecret(int $length = 32): string {
        $secret = '';
        $chars = self::BASE32_CHARS;
        
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        
        return $secret;
    }
    
    /**
     * TOTP 코드 생성
     */
    public static function getCode(string $secret, ?int $timestamp = null): string {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        // 시간 카운터 (30초 단위)
        $counter = floor($timestamp / self::PERIOD);
        
        // 카운터를 8바이트 빅엔디안으로 변환
        $counterBytes = pack('J', $counter);
        
        // Base32 디코딩
        $secretBytes = self::base32Decode($secret);
        
        // HMAC-SHA1
        $hash = hash_hmac(self::ALGORITHM, $counterBytes, $secretBytes, true);
        
        // Dynamic truncation
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        );
        
        // 6자리 코드
        $otp = $binary % pow(10, self::DIGITS);
        
        return str_pad((string)$otp, self::DIGITS, '0', STR_PAD_LEFT);
    }
    
    /**
     * TOTP 코드 검증 (전후 1개 윈도우 허용)
     */
    public static function verify(string $secret, string $code, int $window = 1): bool {
        $timestamp = time();
        
        // 현재 시간 및 전후 윈도우 체크
        for ($i = -$window; $i <= $window; $i++) {
            $checkTime = $timestamp + ($i * self::PERIOD);
            $expectedCode = self::getCode($secret, $checkTime);
            
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * QR 코드용 otpauth URI 생성
     */
    public static function getUri(string $secret, string $username, string $issuer = 'WebHard'): string {
        $label = rawurlencode($issuer) . ':' . rawurlencode($username);
        
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits' => self::DIGITS,
            'period' => self::PERIOD
        ]);
        
        return "otpauth://totp/{$label}?{$params}";
    }
    
    /**
     * QR 코드 이미지 URL (QR Server API)
     */
    public static function getQRCodeUrl(string $uri, int $size = 200): string {
        return 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
            'size' => "{$size}x{$size}",
            'data' => $uri,
            'format' => 'png',
            'margin' => 10
        ]);
    }
    
    /**
     * 백업 코드 생성 (8자리 x 10개)
     */
    public static function generateBackupCodes(int $count = 10): array {
        $codes = [];
        
        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= random_int(0, 9);
            }
            // 4자리-4자리 형식
            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4, 4);
        }
        
        return $codes;
    }
    
    /**
     * Base32 디코딩
     */
    private static function base32Decode(string $data): string {
        $data = strtoupper($data);
        $data = str_replace('=', '', $data);
        
        $chars = self::BASE32_CHARS;
        $buffer = 0;
        $bufferSize = 0;
        $result = '';
        
        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            $value = strpos($chars, $char);
            
            if ($value === false) {
                continue; // 잘못된 문자 무시
            }
            
            $buffer = ($buffer << 5) | $value;
            $bufferSize += 5;
            
            if ($bufferSize >= 8) {
                $bufferSize -= 8;
                $result .= chr(($buffer >> $bufferSize) & 0xff);
            }
        }
        
        return $result;
    }
    
    /**
     * Base32 인코딩
     */
    public static function base32Encode(string $data): string {
        $chars = self::BASE32_CHARS;
        $buffer = 0;
        $bufferSize = 0;
        $result = '';
        
        for ($i = 0; $i < strlen($data); $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bufferSize += 8;
            
            while ($bufferSize >= 5) {
                $bufferSize -= 5;
                $result .= $chars[($buffer >> $bufferSize) & 0x1f];
            }
        }
        
        if ($bufferSize > 0) {
            $result .= $chars[($buffer << (5 - $bufferSize)) & 0x1f];
        }
        
        return $result;
    }
}
