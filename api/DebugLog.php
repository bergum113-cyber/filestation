<?php
/**
 * Debug Logger - 업로드 성능 분석용
 * 
 * 사용법:
 * 1. config.php에서 DEBUG_UPLOAD를 true로 설정 (또는 아래 기본값 사용)
 * 2. 업로드 테스트 후 data/debug_upload.log 확인
 * 3. 분석 완료 후 DEBUG_UPLOAD를 false로 변경
 */

// 디버그 모드 (config.php에서 정의되지 않은 경우 기본값)
if (!defined('DEBUG_UPLOAD')) {
    define('DEBUG_UPLOAD', false);
}

// 디버그 로그 파일 경로 (config.php에서 정의 가능)
if (!defined('DEBUG_LOG_FILE')) {
    define('DEBUG_LOG_FILE', DATA_PATH . '/debug_upload.log');
}

// 디버그 로그 최대 크기 (5MB)
if (!defined('DEBUG_LOG_MAX_SIZE')) {
    define('DEBUG_LOG_MAX_SIZE', 5 * 1024 * 1024);
}

class DebugLog {
    private static $startTime = null;
    private static $lastTime = null;
    private static $logFile = null;
    private static $requestId = null;
    
    /**
     * 로그 초기화 (요청 시작 시 호출)
     */
    public static function init(): void {
        if (!DEBUG_UPLOAD) return;
        
        self::$startTime = microtime(true);
        self::$lastTime = self::$startTime;
        self::$requestId = substr(uniqid(), -6);
        self::$logFile = DEBUG_LOG_FILE;
        
        // 로그 파일 크기 제한 (초과 시 초기화)
        if (file_exists(self::$logFile) && filesize(self::$logFile) > DEBUG_LOG_MAX_SIZE) {
            file_put_contents(self::$logFile, '');
        }
    }
    
    /**
     * 타이밍 로그 기록
     * 
     * @param string $label 로그 레이블
     * @param array $data 추가 데이터 (선택)
     */
    public static function log(string $label, array $data = []): void {
        if (!DEBUG_UPLOAD || !self::$startTime) return;
        
        $now = microtime(true);
        $totalMs = round(($now - self::$startTime) * 1000, 2);
        $deltaMs = round(($now - self::$lastTime) * 1000, 2);
        self::$lastTime = $now;
        
        $entry = sprintf(
            "[%s] [%s] +%sms (total: %sms) %s",
            date('H:i:s'),
            self::$requestId,
            str_pad($deltaMs, 8, ' ', STR_PAD_LEFT),
            str_pad($totalMs, 8, ' ', STR_PAD_LEFT),
            $label
        );
        
        if (!empty($data)) {
            $entry .= ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        
        $entry .= "\n";
        
        @file_put_contents(self::$logFile, $entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * 요청 시작 로그
     */
    public static function start(string $action, array $params = []): void {
        self::init();
        self::log("=== START: {$action} ===", $params);
    }
    
    /**
     * 요청 종료 로그
     */
    public static function end(string $action, array $result = []): void {
        if (!DEBUG_UPLOAD) return;
        
        $totalMs = round((microtime(true) - self::$startTime) * 1000, 2);
        self::log("=== END: {$action} === (Total: {$totalMs}ms)", [
            'success' => $result['success'] ?? null,
            'complete' => $result['complete'] ?? null
        ]);
        self::log(""); // 빈 줄
    }
    
    /**
     * 메모리 사용량 로그
     */
    public static function memory(string $label = ''): void {
        if (!DEBUG_UPLOAD) return;
        
        $mem = round(memory_get_usage(true) / 1024 / 1024, 2);
        $peak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        self::log("MEMORY {$label}", ['current' => "{$mem}MB", 'peak' => "{$peak}MB"]);
    }
}
