<?php
/**
 * FileStation - PHP 버전 검사
 * 
 * 모든 진입점(index.php, api.php, share.php 등)에서 가장 먼저 require됨.
 * 이 파일은 PHP 5.6 이상에서도 파싱 가능한 구문만 사용함.
 * (Union Type, str_contains 등 PHP 8.0+ 문법 사용 금지)
 * 
 * @author 펜닐 (Pennil)
 */

// 최소 요구 PHP 버전
define('FS_MIN_PHP_VERSION', '8.0.0');

// 현재 PHP 버전이 최소 요구 버전 이상인지 검사
if (version_compare(PHP_VERSION, FS_MIN_PHP_VERSION, '<')) {
    // 응답 형식 자동 감지 (API 요청인지 일반 페이지인지)
    $isApi = (
        isset($_SERVER['REQUEST_URI']) && (
            strpos($_SERVER['REQUEST_URI'], '/api.php') !== false ||
            strpos($_SERVER['REQUEST_URI'], '/mydav.php') !== false
        )
    ) || (
        isset($_SERVER['HTTP_ACCEPT']) && 
        strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
    );
    
    // CLI 모드
    $isCli = (PHP_SAPI === 'cli');
    
    $errorMsg = sprintf(
        'FileStation requires PHP %s or higher. Your PHP version: %s',
        FS_MIN_PHP_VERSION,
        PHP_VERSION
    );
    $errorMsgKo = sprintf(
        'FileStation은 PHP %s 이상이 필요합니다. 현재 PHP 버전: %s',
        FS_MIN_PHP_VERSION,
        PHP_VERSION
    );
    
    if ($isCli) {
        // CLI: 텍스트 출력
        fwrite(STDERR, "ERROR: " . $errorMsg . "\n");
        fwrite(STDERR, "오류: " . $errorMsgKo . "\n");
        exit(1);
    }
    
    if ($isApi) {
        // API: JSON 응답
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'success' => false,
            'error' => $errorMsgKo,
            'error_en' => $errorMsg,
            'required_php' => FS_MIN_PHP_VERSION,
            'current_php' => PHP_VERSION
        ));
        exit;
    }
    
    // 일반 페이지: HTML 응답
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FileStation - PHP 버전 호환성 오류</title>
<style>
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Malgun Gothic', sans-serif;
        background: #f5f5f5;
        margin: 0;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
    }
    .container {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        padding: 40px;
        max-width: 600px;
        width: 90%;
        text-align: center;
    }
    .icon {
        font-size: 64px;
        margin-bottom: 20px;
    }
    h1 {
        color: #d32f2f;
        margin: 0 0 10px;
        font-size: 24px;
    }
    h2 {
        color: #666;
        font-size: 16px;
        font-weight: normal;
        margin: 0 0 30px;
    }
    .info-box {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
        text-align: left;
    }
    .info-box strong {
        color: #856404;
    }
    .version-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #ffe69c;
    }
    .version-row:last-child {
        border-bottom: none;
    }
    .version-row .label {
        color: #666;
    }
    .version-row .value {
        font-family: 'Courier New', monospace;
        font-weight: bold;
    }
    .ok { color: #2e7d32; }
    .fail { color: #d32f2f; }
    .help {
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #eee;
        color: #666;
        font-size: 14px;
        text-align: left;
    }
    .help h3 {
        color: #333;
        font-size: 16px;
        margin: 0 0 10px;
    }
    .help ul {
        margin: 10px 0;
        padding-left: 20px;
    }
    .help li {
        margin: 5px 0;
    }
    code {
        background: #f5f5f5;
        padding: 2px 6px;
        border-radius: 4px;
        font-family: 'Courier New', monospace;
        font-size: 13px;
    }
</style>
</head>
<body>
    <div class="container">
        <div class="icon">⚠️</div>
        <h1>PHP 버전 호환성 오류</h1>
        <h2>PHP version compatibility error</h2>
        
        <div class="info-box">
            <div class="version-row">
                <span class="label">필요한 PHP 버전 / Required:</span>
                <span class="value ok"><?php echo htmlspecialchars(FS_MIN_PHP_VERSION); ?> 이상</span>
            </div>
            <div class="version-row">
                <span class="label">현재 PHP 버전 / Current:</span>
                <span class="value fail"><?php echo htmlspecialchars(PHP_VERSION); ?></span>
            </div>
        </div>
        
        <div class="help">
            <h3>🇰🇷 해결 방법</h3>
            <ul>
                <li>웹 호스팅의 PHP 버전을 <strong>8.0 이상</strong>으로 변경하세요. (권장: PHP 8.3 ~ 8.4)</li>
                <li>시놀로지 NAS의 경우: <strong>패키지 센터</strong>에서 PHP 8.x 패키지 설치 후 Web Station에서 변경</li>
                <li>Apache의 경우: <code>httpd.conf</code> 또는 <code>.htaccess</code>에서 PHP 모듈 변경</li>
                <li>cPanel의 경우: <strong>MultiPHP Manager</strong>에서 도메인별 PHP 버전 설정</li>
            </ul>
            
            <h3>🇺🇸 How to resolve</h3>
            <ul>
                <li>Upgrade your PHP version to <strong>8.0 or higher</strong>. (Recommended: PHP 8.3 ~ 8.4)</li>
                <li>For Synology NAS: Install PHP 8.x package via Package Center, then change in Web Station</li>
                <li>For Apache: Update PHP module in <code>httpd.conf</code> or <code>.htaccess</code></li>
                <li>For cPanel: Use <strong>MultiPHP Manager</strong> to set PHP version per domain</li>
            </ul>
        </div>
        
        <div class="help">
            <h3>📚 PHP 다운로드 / Download PHP</h3>
            <ul>
                <li>공식 사이트: <a href="https://www.php.net/downloads.php" target="_blank">https://www.php.net/downloads.php</a></li>
                <li>Windows: <a href="https://windows.php.net/download/" target="_blank">https://windows.php.net/download/</a></li>
            </ul>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}
