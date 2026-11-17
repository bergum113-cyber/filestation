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
        <div class="icon" style="color:#e65100;"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.15em"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div><?php /* ★ (2026-10-26) 이모지 → 선 그림(이 파일은 옛 PHP 에서도 읽혀야 해서 api/FsIcons.php 를 쓰지 않고 직접 넣음) */ ?>
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
            <h3>해결 방법</h3>
            <ul>
                <li>웹 호스팅의 PHP 버전을 <strong>8.0 이상</strong>으로 변경하세요. (권장: PHP 8.3 ~ 8.4)</li>
                <li>시놀로지 NAS의 경우: <strong>패키지 센터</strong>에서 PHP 8.x 패키지 설치 후 Web Station에서 변경</li>
                <li>Apache의 경우: <code>httpd.conf</code> 또는 <code>.htaccess</code>에서 PHP 모듈 변경</li>
                <li>cPanel의 경우: <strong>MultiPHP Manager</strong>에서 도메인별 PHP 버전 설정</li>
            </ul>
            
            <h3>How to resolve</h3>
            <ul>
                <li>Upgrade your PHP version to <strong>8.0 or higher</strong>. (Recommended: PHP 8.3 ~ 8.4)</li>
                <li>For Synology NAS: Install PHP 8.x package via Package Center, then change in Web Station</li>
                <li>For Apache: Update PHP module in <code>httpd.conf</code> or <code>.htaccess</code></li>
                <li>For cPanel: Use <strong>MultiPHP Manager</strong> to set PHP version per domain</li>
            </ul>
        </div>
        
        <div class="help">
            <h3><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.15em"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg> PHP 다운로드 / Download PHP</h3>
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

// ★ (2026-11-02 10:30, 42차 점검) 처음 설치할 때 data/ 에 쓸 수 없거나 필수 확장 mbstring 이 없으면 원인이 화면에 안 보였음(E5#8 — 가입 화면에서
//   '잠시 후 다시 시도'만, mbstring 이 없으면 로그인부터 '서버 오류') → 위 버전 오류처럼 원인과 해결법을 보여 줌.
//   data/ 검사는 첫 실행(data/users.json 없음)에만 함 — 이미 쓰고 있는 서버의 요청마다 판정이 끼어들지 않게. 웹 요청만(CLI 는 그대로)
if (PHP_SAPI !== 'cli') {
    $fsEnvProblems = array();
    if (!extension_loaded('mbstring')) {
        $fsEnvProblems[] = array(
            'PHP 확장 mbstring 이 꺼져 있습니다. php.ini 에서 extension=mbstring 을 켜고 웹 서버(또는 PHP-FPM)를 다시 시작하세요.',
            'The PHP extension mbstring is not enabled. Enable extension=mbstring in php.ini and restart the web server (or PHP-FPM).'
        );
    }
    $fsDataDir = __DIR__ . '/data';
    if (!file_exists($fsDataDir . '/users.json')) {
        $fsDataOk = is_dir($fsDataDir) ? is_writable($fsDataDir) : is_writable(__DIR__);
        if (!$fsDataOk) {
            $fsEnvProblems[] = array(
                'data 폴더에 쓸 수 없습니다(' . $fsDataDir . '). 웹 서버 사용자에게 이 폴더(없으면 FileStation 폴더)의 쓰기 권한을 주세요. 예: Linux chown -R www-data data / Windows IIS_IUSRS 또는 Apache 실행 계정에 수정 권한. 기본 저장 위치 users·shared 폴더도 같습니다.',
                'Cannot write to the data folder (' . $fsDataDir . '). Give the web server user write permission to this folder (or the FileStation folder if it does not exist yet). E.g. Linux: chown -R www-data data / Windows: Modify permission for IIS_IUSRS or the Apache service account. The default storage folders users and shared need the same.'
            );
        }
    }
    if ($fsEnvProblems) {
        $fsEnvIsApi = (isset($_SERVER['REQUEST_URI']) && (strpos($_SERVER['REQUEST_URI'], '/api.php') !== false || strpos($_SERVER['REQUEST_URI'], '/mydav.php') !== false))
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        http_response_code(500);
        if ($fsEnvIsApi) {
            header('Content-Type: application/json; charset=utf-8');
            $ko = array(); $en = array();
            foreach ($fsEnvProblems as $p) { $ko[] = $p[0]; $en[] = $p[1]; }
            echo json_encode(array('success' => false, 'error' => implode(' ', $ko), 'error_en' => implode(' ', $en)));
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>FileStation - 서버 환경 오류</title></head>'
            . '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',\'Malgun Gothic\',sans-serif;background:#f5f5f5;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;">'
            . '<div style="background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,0.1);padding:32px;max-width:640px;width:90%;">'
            . '<h1 style="color:#d32f2f;font-size:22px;margin:0 0 6px;">서버 환경 오류</h1><h2 style="color:#666;font-size:15px;font-weight:normal;margin:0 0 20px;">Server environment error</h2><ul style="padding-left:20px;color:#333;line-height:1.6;">';
        foreach ($fsEnvProblems as $p) {
            echo '<li style="margin-bottom:12px;">' . htmlspecialchars($p[0]) . '<br><span style="color:#666;">' . htmlspecialchars($p[1]) . '</span></li>';
        }
        echo '</ul></div></body></html>';
        exit;
    }
    unset($fsEnvProblems, $fsDataDir, $fsDataOk);
}
