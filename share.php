<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * 공유 링크 접근 페이지
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';

// 세션 시작 (비밀번호 인증 유지용)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSP nonce 생성
$cspNonce = base64_encode(random_bytes(16));

$currentLang = getLang();

// 보안 헤더 (다운로드/스트리밍 요청 시 최소화)
if (isset($_GET['download'])) {
    header('X-Frame-Options: SAMEORIGIN');
} else {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}' blob: https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; frame-ancestors 'self';");
}

$token = $_GET['t'] ?? '';
$password = $_POST['password'] ?? null;
$download = isset($_GET['download']);

if (empty($token)) {
    http_response_code(400);
    exit(__('share_invalid_access'));
}

// 세션에서 이전 인증 여부 확인
if (!$password && !empty($_SESSION['share_authenticated'][$token])) {
    // 세션 인증 완료 — 비밀번호 불필요 (accessShare에서 세션 체크)
}

$shareManager = new ShareManager();

// HLS stop 요청 — 인증 없이 즉시 처리 (탭 닫기 시 sendBeacon)
if ($download && isset($_GET['hls']) && ($_GET['hls_action'] ?? '') === 'stop') {
    $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['session'] ?? $_POST['session'] ?? '');
    if ($sessionId) {
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/data');
        $sessionDir = $dataDir . '/hls_sessions/' . $sessionId;
        
        if (is_dir($sessionDir)) {
            $pidFile = $sessionDir . '/pid.txt';
            $pid = 0;
            if (file_exists($pidFile)) {
                $pid = (int)file_get_contents($pidFile);
            } else {
                if (PHP_OS_FAMILY === 'Windows') {
                    $wmic = @shell_exec('wmic process where "name=\'ffmpeg.exe\'" get processid,commandline /format:csv 2>NUL');
                    if ($wmic) {
                        foreach (explode("\n", $wmic) as $line) {
                            if (strpos($line, $sessionId) !== false && preg_match('/,(\d+)\s*$/', trim($line), $pm)) {
                                $pid = (int)$pm[1]; break;
                            }
                        }
                    }
                } else {
                    $pid = (int)trim(@shell_exec('pgrep -f "' . $sessionId . '" 2>/dev/null') ?? '');
                }
            }
            
            if ($pid > 0) {
                if (PHP_OS_FAMILY === 'Windows') {
                    @exec("taskkill /PID $pid /F /T 2>NUL");
                } else {
                    // posix_kill → exec → shell_exec → popen 순서로 fallback
                    if (function_exists('posix_kill')) {
                        @posix_kill($pid, 9);
                    } elseif (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', @ini_get('disable_functions') ?: '')))) {
                        @exec("kill -9 $pid 2>/dev/null");
                    } elseif (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', @ini_get('disable_functions') ?: '')))) {
                        @shell_exec("kill -9 $pid 2>/dev/null");
                    } else {
                        $ph = @popen("kill -9 $pid 2>/dev/null", 'r');
                        if (is_resource($ph)) @pclose($ph);
                    }
                }
                usleep(500000);
            }
            
            for ($retry = 0; $retry < 3; $retry++) {
                foreach (new DirectoryIterator($sessionDir) as $f) {
                    if (!$f->isDot()) @unlink($f->getPathname());
                }
                if (@rmdir($sessionDir)) break;
                usleep(500000);
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// 다운로드 요청
if ($download) {
    // 세션 락 해제 (트랜스코딩 장시간 실행 시 다른 요청 블로킹 방지)
    session_write_close();
    $shareManager->downloadShare($token, $password);
    exit;
}

// 공유 정보 확인
$result = $shareManager->accessShare($token, $password);
$needsPassword = ($result['error'] ?? '') === 'password_required';
$error = (!$result['success'] && !$needsPassword) ? $result['error'] : null;
$share = $result['share'] ?? null;

// 비밀번호 인증 성공 시 세션에 인증 완료 플래그 저장 (비밀번호 원문 미저장)
if ($result['success'] && $password) {
    $_SESSION['share_authenticated'][$token] = true;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('share_file_sharing') ?> - <?= APP_NAME ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 450px;
            width: 100%;
            text-align: center;
        }
        .icon { font-size: 64px; margin-bottom: 20px; }
        h1 { font-size: 24px; color: #333; margin-bottom: 10px; }
        .filename { font-size: 18px; color: #666; word-break: break-all; margin-bottom: 20px; }
        .info { background: #f5f5f5; border-radius: 8px; padding: 15px; margin-bottom: 20px; font-size: 14px; color: #666; }
        .info div { margin: 5px 0; }
        .btn { display: inline-block; padding: 14px 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 30px; font-size: 16px; font-weight: 600; border: none; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-cancel { background: #dc3545; margin-left: 10px; }
        .btn-cancel:hover { box-shadow: 0 10px 30px rgba(220, 53, 69, 0.4); }
        .error { background: #fee; color: #c00; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        input[type="password"] { width: 100%; padding: 14px; border: 2px solid #ddd; border-radius: 8px; font-size: 16px; margin-bottom: 15px; transition: border-color 0.2s; }
        input[type="password"]:focus { outline: none; border-color: #667eea; }
        .password-form { margin-bottom: 20px; }
        .progress-container { display: none; margin-top: 20px; }
        .progress-container.active { display: block; }
        .progress-bar-wrap { background: #e9ecef; border-radius: 10px; height: 20px; overflow: hidden; margin-bottom: 10px; }
        .progress-bar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 10px; }
        .progress-text { font-size: 14px; color: #666; margin-bottom: 15px; }
        .progress-speed { font-size: 12px; color: #999; margin-bottom: 15px; }
        .toast { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(100px); background: #333; color: white; padding: 14px 28px; border-radius: 30px; font-size: 14px; opacity: 0; transition: all 0.3s ease; z-index: 1000; max-width: 90%; text-align: center; }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: #28a745; }
        .toast.error { background: #dc3545; }
        .toast.info { background: #17a2b8; }
        .player-wrap { margin-bottom: 15px; border-radius: 12px; overflow: hidden; background: #000; position: relative; }
        .player-wrap video { width: 100%; max-height: 70vh; display: block; }
        .player-wrap.audio-wrap { background: #f5f5f5; padding: 20px; border-radius: 12px; }
        .container.stream-mode { max-width: 800px; }
        .container.stream-mode .filename { font-size: 15px; color: #888; margin-bottom: 12px; }
        .container.stream-mode h1 { font-size: 20px; margin-bottom: 6px; }
        .container.stream-mode .icon { font-size: 48px; margin-bottom: 12px; }
        .container.stream-mode .info { font-size: 13px; padding: 12px; margin-bottom: 15px; }
        .transcode-status { text-align: center; padding: 10px; color: #999; font-size: 13px; display: none; }
        .transcode-status.active { display: block; }
        .transcode-status .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #ccc; border-top-color: #667eea; border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 6px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .play-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; align-items: center; justify-content: center; cursor: pointer; background: rgba(0,0,0,0.4); transition: background 0.2s; }
        .play-overlay:hover { background: rgba(0,0,0,0.25); }
        .play-overlay svg { filter: drop-shadow(0 2px 8px rgba(0,0,0,0.5)); transition: transform 0.15s; }
        .play-overlay:hover svg { transform: scale(1.1); }
        .video-play-overlay { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 72px; height: 72px; border-radius: 50%; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 12; transition: opacity 0.25s, transform 0.15s; border: 2px solid rgba(255,255,255,0.6); opacity: 0; pointer-events: none; }
        .video-play-overlay:hover { background: rgba(0,0,0,0.75); transform: translate(-50%, -50%) scale(1.08); }
        .video-play-overlay:active { transform: translate(-50%, -50%) scale(0.95); }
        .player-wrap.playing .video-play-overlay { pointer-events: auto; }
        .player-wrap.playing:hover .video-play-overlay { opacity: 0.6; }
        .player-wrap.playing:hover .video-play-overlay:hover { opacity: 1; }
        .player-wrap:not(.playing) .video-play-overlay { opacity: 1; pointer-events: auto; }
        .player-wrap.has-initial-overlay .video-play-overlay { opacity: 0 !important; pointer-events: none !important; }
        @media (max-width: 1024px) { .video-play-overlay { display: none !important; } }
        .stream-badge { position: absolute; top: 10px; left: 10px; font-size: 11px; font-weight: bold; padding: 3px 8px; border-radius: 3px; z-index: 10; pointer-events: none; color: #fff; opacity: 0.9; transition: opacity 0.3s; }
        .stream-badge .encoder-info { font-weight: bold; font-size: 11px; opacity: 0.85; margin-left: 2px; }
        .stream-badge.transcode { background: rgba(255, 152, 0, 0.85); }
        .stream-badge.native { background: rgba(76, 175, 80, 0.85); }
        .player-wrap.playing .stream-badge { opacity: 0; transition: opacity 0.3s; }
        .player-wrap.playing:hover .stream-badge { opacity: 0.9; }
        .player-wrap.playing.show-controls .stream-badge { opacity: 0.9; }
        .player-wrap.playing #share-audio-track-wrap { opacity: 0 !important; transition: opacity 0.3s; }
        .player-wrap.playing:hover #share-audio-track-wrap { opacity: 1 !important; }
        .player-wrap.playing.show-controls #share-audio-track-wrap { opacity: 1 !important; }
        .player-wrap.fs-idle .stream-badge,
        .player-wrap.fs-idle .transcode-duration,
        .player-wrap.fs-idle #share-audio-track-wrap { opacity: 0 !important; }
        .player-wrap.fs-idle { cursor: none; }
        /* 트랜스코딩 전체 길이 표시 */
        .transcode-duration { position:absolute;bottom:65px;left:8px;background:rgba(0,0,0,0.65);color:#ff9800;padding:3px 10px;border-radius:4px;font-size:13px;font-weight:600;z-index:10;pointer-events:none;font-family:monospace;opacity:0;transition:opacity 0.3s; }
        .player-wrap:not(.playing) .transcode-duration { opacity: 1; }
        .player-wrap.playing .transcode-duration { opacity: 0; }
        .player-wrap.playing:hover .transcode-duration { opacity: 1; }
        .player-wrap.playing.show-controls .transcode-duration { opacity: 1; }
        .player-wrap:fullscreen .transcode-duration,
        .player-wrap:-webkit-full-screen .transcode-duration { bottom: 75px; }
        .player-wrap:fullscreen { background: #000; display: flex; align-items: center; justify-content: center; overflow: visible; }
        .player-wrap:fullscreen video { max-height: 100vh; height: 100%; }
        .player-wrap:-webkit-full-screen { background: #000; display: flex; align-items: center; justify-content: center; overflow: visible; }
        .player-wrap:-webkit-full-screen video { max-height: 100vh; height: 100%; }
        .player-wrap:fullscreen .subtitle-overlay,
        .player-wrap:-webkit-full-screen .subtitle-overlay { font-size: 2.2vw; bottom: 6%; z-index: 20; }
        video::-webkit-media-controls-fullscreen-button { display: none; }
        .player-controls { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 10px; flex-wrap: wrap; }
        .player-controls .ctrl-btn { background: #f0f0f0; border: 1px solid #ddd; border-radius: 6px; padding: 5px 10px; font-size: 12px; cursor: pointer; transition: all 0.15s; color: #555; }
        .player-controls .ctrl-btn:hover { background: #e0e0f0; border-color: #667eea; color: #667eea; }
        .player-controls .ctrl-btn.active { background: #667eea; color: #fff; border-color: #667eea; }
        .ctrl-sep { width: 1px; height: 20px; background: #ddd; margin: 0 2px; }
        .subtitle-overlay { position: absolute; bottom: 8%; left: 5%; right: 5%; text-align: center; color: #fff; font-size: 1.1em; font-weight: 500; text-shadow: -1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000, 1px 1px 0 #000, 0 0 6px rgba(0,0,0,0.9); pointer-events: none; z-index: 10; line-height: 1.5; word-wrap: break-word; overflow-wrap: break-word; }
        .sub-file-label { display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
        .sub-file-label input { display: none; }
        @media (max-width: 480px) {
            body { padding: 15px; }
            .container { padding: 25px 20px; border-radius: 12px; }
            .icon { font-size: 48px; margin-bottom: 15px; }
            h1 { font-size: 20px; }
            .filename { font-size: 15px; }
            .info { font-size: 13px; padding: 12px; }
            .btn { padding: 12px 30px; font-size: 15px; }
            .btn-cancel { margin-left: 0; margin-top: 10px; display: block; width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($error): ?>
            <div class="icon">❌</div>
            <h1><?= __('share_access_denied') ?></h1>
            <div class="error"><?= htmlspecialchars($error) ?></div>
            <a href="/" class="btn"><?= __('share_go_home') ?></a>
            
        <?php elseif ($needsPassword): ?>
            <div class="icon">🔒</div>
            <h1><?= __('share_password_required') ?></h1>
            <p style="color:#666;margin-bottom:20px;"><?= __('share_password_protected') ?></p>
            <form method="post" class="password-form">
                <input type="password" name="password" placeholder="<?= __('share_enter_password') ?>" required autofocus>
                <button type="submit" class="btn"><?= __('share_confirm') ?></button>
            </form>
            
        <?php elseif ($share): ?>
            <?php
                $shareType = $share['share_type'] ?? 'download';
            ?>
            
            <?php if ($shareType === 'filedrop'): ?>
                <!-- 파일 드롭 (업로드 전용) -->
                <div class="icon">📤</div>
                <h1><?= __('filedrop_title', '파일 업로드') ?></h1>
                <p class="filename"><?= htmlspecialchars($share['filename']) ?></p>
                
                <?php
                    $fdInfo = [];
                    if ($share['max_downloads']) {
                        $remaining = $share['max_downloads'] - ($share['download_count'] ?? 0);
                        $fdInfo[] = __('filedrop_remaining', '남은 업로드') . ": {$remaining}" . __('filedrop_count_unit', '회');
                    }
                    if ($share['expire_at']) {
                        $fdInfo[] = __('filedrop_expire', '만료') . ': ' . substr($share['expire_at'], 0, 16);
                    }
                ?>
                <?php if (!empty($fdInfo)): ?>
                <div class="info"><?= htmlspecialchars(implode(' · ', $fdInfo)) ?></div>
                <?php endif; ?>
                
                <div id="filedrop-zone" class="filedrop-zone">
                    <div class="filedrop-icon">📁</div>
                    <p><?= __('filedrop_drag', '파일을 여기에 끌어놓거나 클릭하세요') ?></p>
                    <input type="file" id="filedrop-input" multiple style="display:none;">
                </div>
                
                <div id="filedrop-list" class="filedrop-list" style="display:none;"></div>
                <div id="filedrop-progress" style="display:none;margin-top:15px;">
                    <div class="progress-bar"><div class="progress-fill" id="filedrop-bar"></div></div>
                    <p id="filedrop-status" style="font-size:13px;color:#666;margin-top:5px;"></p>
                </div>
                <button id="filedrop-submit" class="btn" style="display:none;margin-top:15px;">
                    📤 <?= __('filedrop_upload_btn', '업로드') ?>
                </button>
                <div id="filedrop-result" style="display:none;margin-top:15px;"></div>
                
                <style>
                .filedrop-zone {
                    border: 3px dashed #ddd; border-radius: 12px; padding: 40px 20px;
                    cursor: pointer; transition: all 0.3s; margin-top: 15px;
                }
                .filedrop-zone:hover, .filedrop-zone.dragover { border-color: #667eea; background: #f0f2ff; }
                .filedrop-icon { font-size: 48px; margin-bottom: 10px; }
                .filedrop-list { margin-top: 15px; text-align: left; max-height: 200px; overflow-y: auto; }
                .filedrop-item { padding: 8px 12px; background: #f5f5f5; border-radius: 6px; margin-bottom: 5px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; }
                .filedrop-item .remove { cursor: pointer; color: #e74c3c; font-weight: bold; }
                .progress-bar { height: 8px; background: #eee; border-radius: 4px; overflow: hidden; }
                .progress-fill { height: 100%; background: #667eea; border-radius: 4px; transition: width 0.3s; width: 0%; }
                .result-ok { color: #27ae60; } .result-err { color: #e74c3c; }
                </style>
                
                <script nonce="<?= $cspNonce ?>">
                const token = '<?= htmlspecialchars($token, ENT_QUOTES) ?>';
                const password = ''; // 비밀번호는 서버 세션에서 관리됨
                let selectedFiles = [];
                const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                
                const zone = document.getElementById('filedrop-zone');
                const input = document.getElementById('filedrop-input');
                
                zone.addEventListener('click', () => input.click());
                zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('dragover'); });
                zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
                zone.addEventListener('drop', (e) => {
                    e.preventDefault(); zone.classList.remove('dragover');
                    addFiles(e.dataTransfer.files);
                });
                input.addEventListener('change', () => addFiles(input.files));
                
                function addFiles(files) {
                    for (const f of files) selectedFiles.push(f);
                    renderList();
                }
                
                function removeFile(idx) {
                    selectedFiles.splice(idx, 1);
                    renderList();
                }
                
                function formatSize(b) {
                    if (b < 1024) return b + ' B';
                    if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
                    if (b < 1073741824) return (b/1048576).toFixed(1) + ' MB';
                    return (b/1073741824).toFixed(2) + ' GB';
                }
                
                function renderList() {
                    const list = document.getElementById('filedrop-list');
                    const btn = document.getElementById('filedrop-submit');
                    if (selectedFiles.length === 0) {
                        list.style.display = 'none'; btn.style.display = 'none'; return;
                    }
                    list.style.display = 'block'; btn.style.display = 'inline-block';
                    list.innerHTML = selectedFiles.map((f,i) =>
                        `<div class="filedrop-item"><span>📄 ${esc(f.name)} (${formatSize(f.size)})</span><span class="remove" data-remove-idx="${i}">✕</span></div>`
                    ).join('');
                    list.querySelectorAll('.remove[data-remove-idx]').forEach(el => {
                        el.addEventListener('click', function() { removeFile(parseInt(this.dataset.removeIdx)); });
                    });
                }
                
                async function startFileDropUpload() {
                    if (selectedFiles.length === 0) return;
                    const progress = document.getElementById('filedrop-progress');
                    const bar = document.getElementById('filedrop-bar');
                    const status = document.getElementById('filedrop-status');
                    const resultDiv = document.getElementById('filedrop-result');
                    const btn = document.getElementById('filedrop-submit');
                    
                    progress.style.display = 'block';
                    btn.disabled = true;
                    resultDiv.style.display = 'block';
                    resultDiv.innerHTML = '';
                    
                    let success = 0, fail = 0;
                    for (let i = 0; i < selectedFiles.length; i++) {
                        const f = selectedFiles[i];
                        status.textContent = `${i+1}/${selectedFiles.length}: ${f.name}`;
                        bar.style.width = ((i / selectedFiles.length) * 100) + '%';
                        
                        const fd = new FormData();
                        fd.append('action', 'filedrop_upload');
                        fd.append('token', token);
                        fd.append('file', f);
                        if (password) fd.append('password', password);
                        
                        try {
                            const res = await fetch('api.php?action=filedrop_upload', { method: 'POST', body: fd });
                            const data = await res.json();
                            if (data.success) {
                                resultDiv.innerHTML += `<div class="result-ok">✅ ${esc(data.filename)}</div>`;
                                success++;
                            } else {
                                resultDiv.innerHTML += `<div class="result-err">❌ ${esc(f.name)}: ${esc(data.error)}</div>`;
                                fail++;
                            }
                        } catch(e) {
                            resultDiv.innerHTML += `<div class="result-err">❌ ${esc(f.name)}: ${esc(e.message)}</div>`;
                            fail++;
                        }
                    }
                    
                    bar.style.width = '100%';
                    status.textContent = `<?= __('filedrop_complete', '완료') ?>: ${success}<?= __('filedrop_success_unit', '개 성공') ?>${fail ? `, ${fail}<?= __('filedrop_fail_unit', '개 실패') ?>` : ''}`;
                    btn.disabled = false;
                    selectedFiles = [];
                    document.getElementById('filedrop-list').style.display = 'none';
                    btn.style.display = 'none';
                }
                
                // 인라인 onclick 대신 이벤트 바인딩 (CSP 호환)
                var fdBtn = document.getElementById('filedrop-submit');
                if (fdBtn) fdBtn.addEventListener('click', function() { startFileDropUpload(); });
                </script>
            
            <?php else: ?>
            <?php
                $ext = strtolower(pathinfo($share['filename'], PATHINFO_EXTENSION));
                $videoExts = ['mp4','webm','ogg','mov','avi','mkv','wmv','flv','ts','m2ts','mts','mpg','mpeg','m4v','3gp'];
                $audioExts = ['mp3','wav','ogg','flac','m4a','aac','wma','opus'];
                $isVideo = in_array($ext, $videoExts);
                $isAudio = in_array($ext, $audioExts);
                $isStreamable = ($isVideo || $isAudio) && $shareType === 'stream';
                
                // 브라우저 네이티브 지원 포맷 (확장자 기반 1차 판단)
                $nativeVideo = ['mp4','webm','ogg'];
                $nativeAudio = ['mp3','wav','ogg','flac','m4a','aac','opus'];
                $needsTranscode = $isVideo && !in_array($ext, $nativeVideo);
                
                // 네이티브 포맷이라도 코덱/크기 체크 (ffprobe)
                $videoCodec = '';
                $videoResolution = '';
                $fileSize = $share['size'] ?? 0;
                if ($isVideo && !$needsTranscode) {
                    // 실제 파일 경로 구하기
                    $_db = JsonDB::getInstance();
                    $_shareRec = $_db->find('shares', ['token' => $token]);
                    $_storageRec = $_shareRec ? $_db->find('storages', ['id' => $_shareRec['storage_id']]) : null;
                    if ($_storageRec) {
                        $_sType = $_storageRec['storage_type'] ?? 'local';
                        if ($_sType === 'home') {
                            $_owner = $_db->find('users', ['id' => $_storageRec['owner_id'] ?? 0]);
                            $_bPath = (defined('USER_FILES_ROOT') ? USER_FILES_ROOT : '') . DIRECTORY_SEPARATOR . ($_owner['username'] ?? 'unknown');
                        } elseif ($_sType === 'shared') {
                            $_bPath = defined('SHARED_FILES_ROOT') ? SHARED_FILES_ROOT : (DATA_PATH . DIRECTORY_SEPARATOR . 'shared');
                        } else {
                            $_bPath = $_storageRec['path'] ?? '';
                        }
                        $_realFile = $_bPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $_shareRec['file_path']);
                        
                        if (file_exists($_realFile)) {
                            $fileSize = filesize($_realFile) ?: $fileSize;
                            // ffprobe/ffmpeg 경로
                            $_settings = $_db->load('settings');
                            $_thumbSettings = $_settings['thumbnails'] ?? [];
                            $_probeBin = '';
                            $_ffprobePath = trim($_thumbSettings['ffprobe_path'] ?? '');
                            $_ffmpegPath = trim($_thumbSettings['ffmpeg_path'] ?? '');
                            if ($_ffprobePath && @is_executable($_ffprobePath)) $_probeBin = $_ffprobePath;
                            elseif ($_ffmpegPath && @is_executable($_ffmpegPath)) $_probeBin = $_ffmpegPath;
                            else { foreach (['ffprobe', 'ffmpeg'] as $_bin) { $out = @shell_exec("$_bin -version 2>&1"); if ($out && strpos($out, 'version') !== false) { $_probeBin = $_bin; break; } } }
                            
                            if ($_probeBin) {
                                $_probeOut = @shell_exec(escapeshellarg($_probeBin) . ' -i ' . escapeshellarg($_realFile) . ' 2>&1');
                                if (preg_match('/Stream\s+#\d+:\d+.*Video:\s*(\w+)/i', $_probeOut, $_vm)) {
                                    $videoCodec = strtolower($_vm[1]);
                                }
                                if (preg_match('/(\d{2,5})x(\d{2,5})/', $_probeOut, $_rm)) {
                                    $videoResolution = $_rm[1] . 'x' . $_rm[2];
                                }
                                // 코덱 미지원 → 트랜스코딩
                                $nativeCodecs = ['h264', 'vp8', 'vp9', 'av1'];
                                if ($videoCodec && !in_array($videoCodec, $nativeCodecs)) {
                                    $needsTranscode = true;
                                }
                            }
                        }
                    }
                    
                    // 모바일 + 500MB 이상 → 트랜스코딩
                    if (!$needsTranscode && $fileSize > 500 * 1024 * 1024) {
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        if (preg_match('/Android|iPhone|iPad|iPod|Mobile/i', $ua)) {
                            $needsTranscode = true;
                        }
                    }
                }
                
                $streamUrl = "share.php?t=" . urlencode($token) . "&download=1&stream=1";
                if ($password) $streamUrl .= "&password=" . urlencode($password);
                
                // 같은 폴더의 자막 파일 자동 검색
                $autoSubtitles = [];
                try {
                if ($isVideo) {
                    $db = JsonDB::getInstance();
                    $shareRecord = $db->find('shares', ['token' => $token]);
                    if ($shareRecord) {
                        $storageRecord = $db->find('storages', ['id' => $shareRecord['storage_id']]);
                        // 스토리지 타입별 실제 경로 계산
                        $sType = $storageRecord["storage_type"] ?? "local";
                        if ($sType === "home") {
                            $owner = $db->find("users", ["id" => $storageRecord["owner_id"] ?? 0]);
                            $basePath = (defined("USER_FILES_ROOT") ? USER_FILES_ROOT : "") . DIRECTORY_SEPARATOR . ($owner["username"] ?? "unknown");
                        } elseif ($sType === "shared") {
                            $basePath = defined("SHARED_FILES_ROOT") ? SHARED_FILES_ROOT : (DATA_PATH . DIRECTORY_SEPARATOR . "shared");
                        } else {
                            $basePath = $storageRecord["path"] ?? "";
                        }
                        if ($basePath && is_dir($basePath)) {
                            $filePath = $shareRecord['file_path'];
                            $dir = dirname($filePath);
                            $videoBase = strtolower(pathinfo($filePath, PATHINFO_FILENAME));
                            $fullDir = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
                            if (is_dir($fullDir)) {
                                $subExts = ['srt', 'smi', 'sami', 'vtt', 'ass', 'ssa'];
                                foreach (scandir($fullDir) as $f) {
                                    if ($f === '.' || $f === '..') continue;
                                    $fext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                                    $fbase = strtolower(pathinfo($f, PATHINFO_FILENAME));
                                    if (in_array($fext, $subExts) && (
                                        $fbase === $videoBase ||
                                        strpos($fbase, $videoBase) === 0 ||
                                        strpos($videoBase, $fbase) === 0
                                    )) {
                                        $subFullPath = $fullDir . DIRECTORY_SEPARATOR . $f;
                                        $subContent = @file_get_contents($subFullPath);
                                        if ($subContent !== false) {
                                            // 인코딩 자동 감지: UTF-16 LE/BE, UTF-8 BOM, EUC-KR
                                            $b = $subContent;
                                            if (strlen($b) >= 2 && ord($b[0]) === 0xFF && ord($b[1]) === 0xFE) {
                                                $subContent = mb_convert_encoding(substr($b, 2), 'UTF-8', 'UTF-16LE');
                                            } elseif (strlen($b) >= 2 && ord($b[0]) === 0xFE && ord($b[1]) === 0xFF) {
                                                $subContent = mb_convert_encoding(substr($b, 2), 'UTF-8', 'UTF-16BE');
                                            } elseif (strlen($b) >= 3 && ord($b[0]) === 0xEF && ord($b[1]) === 0xBB && ord($b[2]) === 0xBF) {
                                                $subContent = substr($b, 3);
                                            } elseif (!mb_check_encoding($b, 'UTF-8')) {
                                                $subContent = mb_convert_encoding($b, 'UTF-8', 'EUC-KR,CP949,SJIS');
                                            }
                                            $autoSubtitles[] = [
                                                'name' => $f,
                                                'ext' => $fext,
                                                'content' => $subContent
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                } catch (\Throwable $e) { $autoSubtitles = []; }
            ?>
            
            <?php if ($isStreamable): ?>
            <!-- 스트리밍 모드 -->
            <script nonce="<?= $cspNonce ?>">document.querySelector('.container').classList.add('stream-mode');</script>
            <div style="margin-bottom: 16px;">
                <svg width="56" height="56" viewBox="0 0 56 56" fill="none">
                    <rect width="56" height="56" rx="14" fill="linear-gradient(135deg, #667eea 0%, #764ba2 100%)"/>
                    <defs><linearGradient id="g1" x1="0" y1="0" x2="56" y2="56"><stop offset="0%" stop-color="#667eea"/><stop offset="100%" stop-color="#764ba2"/></linearGradient></defs>
                    <rect width="56" height="56" rx="14" fill="url(#g1)"/>
                    <path d="M22 17L42 28L22 39V17Z" fill="white"/>
                </svg>
            </div>
            <h1><?= htmlspecialchars($share['filename']) ?></h1>
            
            <div class="info">
                <div>📦 <?= formatFileSize($share['size'] ?? 0) ?> · 📅 <?= htmlspecialchars(date('Y-m-d H:i', strtotime($share['created_at']))) ?><?php if ($share['expire_at']): ?> · ⏰ ~<?= htmlspecialchars(date('Y-m-d', strtotime($share['expire_at']))) ?><?php endif; ?></div>
            </div>
            
            <?php if ($isVideo): ?>
            <div class="player-wrap<?php if ($needsTranscode): ?> has-initial-overlay<?php endif; ?>" id="player-wrap">
                <?php if ($needsTranscode): ?>
                <?php
                    $codecLabel = strtoupper($videoCodec ?: $ext);
                    $sizeMB = round($fileSize / (1024 * 1024));
                ?>
                <div class="stream-badge transcode" id="stream-badge">⚡ <?= htmlspecialchars($codecLabel) ?><?php if ($videoResolution): ?> <?= htmlspecialchars($videoResolution) ?><?php endif; ?> → <?= __('realtime_converting', '실시간 변환 재생') ?></div>
                <div id="share-audio-track-wrap" style="display:none;position:absolute;top:4px;right:4px;z-index:15;opacity:0;transition:opacity 0.3s;">
                    <label style="color:#fff;font-size:12px;text-shadow:0 0 3px #000;">🔊 <?= __('audio_label', '오디오') ?>:
                        <select id="share-audio-select" style="font-size:12px;max-width:220px;"></select>
                    </label>
                </div>
                <video controls playsinline webkit-playsinline preload="none" id="stream-player" data-transcode-url="<?= htmlspecialchars($streamUrl . '&transcode=1') ?>" data-hls-url="<?= htmlspecialchars($streamUrl . '&hls=1&hls_action=start') ?>" style="min-height:220px;background:#111;">
                </video>
                <div class="play-overlay" id="play-overlay">
                    <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                        <circle cx="36" cy="36" r="35" fill="rgba(0,0,0,0.6)"/>
                        <path d="M28 20L54 36L28 52V20Z" fill="white"/>
                    </svg>
                </div>
                <div class="video-play-overlay" id="share-play-pause">
                    <svg class="icon-play" viewBox="0 0 24 24" width="48" height="48" fill="white"><path d="M8 5v14l11-7z"/></svg>
                    <svg class="icon-pause" viewBox="0 0 24 24" width="48" height="48" fill="white" style="display:none"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                </div>
                <?php else: ?>
                <div class="stream-badge native">▶ <?= __('native_playback', '일반 재생') ?><?php if ($videoCodec): ?> (<?= htmlspecialchars(strtoupper($videoCodec)) ?><?php if ($videoResolution): ?> <?= htmlspecialchars($videoResolution) ?><?php endif; ?>)<?php endif; ?></div>
                <video controls playsinline webkit-playsinline preload="metadata" id="stream-player" data-transcode-url="<?= htmlspecialchars($streamUrl . '&transcode=1') ?>" data-hls-url="<?= htmlspecialchars($streamUrl . '&hls=1&hls_action=start') ?>">
                    <source src="<?= htmlspecialchars($streamUrl) ?>" type="video/mp4">
                </video>
                <div class="video-play-overlay" id="share-play-pause">
                    <svg class="icon-play" viewBox="0 0 24 24" width="48" height="48" fill="white"><path d="M8 5v14l11-7z"/></svg>
                    <svg class="icon-pause" viewBox="0 0 24 24" width="48" height="48" fill="white" style="display:none"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                </div>
                <?php endif; ?>
                <div class="subtitle-overlay" id="subtitle-overlay"></div>
            </div>
            <div class="transcode-status" id="transcode-status">
                <span class="spinner"></span> <?= __('share_transcode_loading', '실시간 변환 중...') ?>
            </div>
            <div class="player-controls" id="player-controls" <?php if ($needsTranscode): ?>style="display:none;"<?php endif; ?>>
                <button class="ctrl-btn" data-speed="0.5">0.5x</button>
                <button class="ctrl-btn" data-speed="0.75">0.75x</button>
                <button class="ctrl-btn active" data-speed="1">1x</button>
                <button class="ctrl-btn" data-speed="1.25">1.25x</button>
                <button class="ctrl-btn" data-speed="1.5">1.5x</button>
                <button class="ctrl-btn" data-speed="2">2x</button>
                <div class="ctrl-sep"></div>
                <button class="ctrl-btn" id="btn-sub-down" title="<?= __('sub_size_down', '자막 축소') ?>">A-</button>
                <button class="ctrl-btn" id="btn-sub-up" title="<?= __('sub_size_up', '자막 확대') ?>">A+</button>
                <button class="ctrl-btn" id="btn-sub-pos-up" title="<?= __('sub_pos_up', '자막 위로') ?>">▲</button>
                <button class="ctrl-btn" id="btn-sub-pos-down" title="<?= __('sub_pos_down', '자막 아래로') ?>">▼</button>
                <button class="ctrl-btn" id="btn-sub-sync-down" title="<?= __('sub_sync_back', '자막 싱크 -0.5초') ?>">-0.5s</button>
                <span class="ctrl-btn" id="sub-sync-display" style="cursor:default;min-width:38px;text-align:center;font-family:monospace;font-size:11px;">0.0s</span>
                <button class="ctrl-btn" id="btn-sub-sync-up" title="<?= __('sub_sync_forward', '자막 싱크 +0.5초') ?>">+0.5s</button>
                <button class="ctrl-btn" id="btn-sub-sync-reset" title="<?= __('sub_sync_reset', '싱크 초기화') ?>">↺</button>
                <div class="ctrl-sep"></div>
                <label class="ctrl-btn sub-file-label" title="<?= __('sub_load', '자막 파일 불러오기') ?>">
                    📝 <?= __('subtitle', '자막') ?>
                    <input type="file" id="sub-file-input" accept=".srt,.vtt,.smi,.sami,.ass,.ssa">
                </label>
                <button class="ctrl-btn" id="btn-pip" title="PIP">🖼️ PIP</button>
                <button class="ctrl-btn" id="btn-fullscreen" title="<?= __('fullscreen', '전체화면') ?>">⛶</button>
            </div>
            <?php else: ?>
            <div class="player-wrap audio-wrap">
                <audio controls preload="metadata" id="stream-player" style="width:100%;">
                    <source src="<?= htmlspecialchars($streamUrl) ?>" type="audio/mpeg">
                </audio>
            </div>
            <div class="player-controls" id="player-controls">
                <button class="ctrl-btn" data-speed="0.5">0.5x</button>
                <button class="ctrl-btn" data-speed="0.75">0.75x</button>
                <button class="ctrl-btn active" data-speed="1">1x</button>
                <button class="ctrl-btn" data-speed="1.25">1.25x</button>
                <button class="ctrl-btn" data-speed="1.5">1.5x</button>
                <button class="ctrl-btn" data-speed="2">2x</button>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <!-- 다운로드 모드 -->
            <div class="icon"><?= $share['is_dir'] ? '📁' : '📄' ?></div>
            <h1><?= __('share_file_sharing') ?></h1>
            <div class="filename" id="filename"><?= htmlspecialchars($share['filename']) ?></div>
            
            <div class="info">
                <?php if (!$share['is_dir']): ?>
                <div>📦 <?= __('share_size') ?>: <?= formatFileSize($share['size'] ?? 0) ?></div>
                <?php endif; ?>
                <div>📅 <?= __('share_date') ?>: <?= htmlspecialchars(date('Y-m-d H:i', strtotime($share['created_at']))) ?></div>
                <?php if ($share['expire_at']): ?>
                <div>⏰ <?= __('share_expire') ?>: <?= htmlspecialchars(date('Y-m-d H:i', strtotime($share['expire_at']))) ?></div>
                <?php endif; ?>
                <?php if ($share['max_downloads']): ?>
                <div>📥 <?= __('share_download_count') ?>: <?= (int)$share['download_count'] ?> / <?= (int)$share['max_downloads'] ?></div>
                <?php endif; ?>
            </div>
            
            <div id="btn-container">
                <button type="button" class="btn" id="download-btn">
                    <?= $share['is_dir'] ? __('share_zip_download') : __('share_download_btn') ?>
                </button>
            </div>
            
            <div class="progress-container" id="progress-container">
                <div class="progress-bar-wrap">
                    <div class="progress-bar" id="progress-bar"></div>
                </div>
                <div class="progress-text" id="progress-text"><?= __('share_preparing') ?></div>
                <div class="progress-speed" id="progress-speed"></div>
                <button type="button" class="btn btn-cancel" id="cancel-btn">✕ <?= __('cancel') ?></button>
            </div>
            <?php endif; /* isStreamable else */ ?>
        <?php endif; ?>
    </div>
    
    <div class="toast" id="toast"></div>
    
    <?php if ($share && empty($isStreamable)): ?>
    <script nonce="<?= $cspNonce ?>">
    (function() {
        const S = {
            preparing: <?= json_encode(__('share_preparing'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            downloading: <?= json_encode(__('share_downloading'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            complete: <?= json_encode(__('share_download_complete'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            cancelled: <?= json_encode(__('share_download_cancelled'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            failed: <?= json_encode(__('share_download_failed'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            remaining: <?= json_encode(__('share_remaining'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            sec: <?= json_encode(__('share_seconds'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            min: <?= json_encode(__('share_minutes'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            hr: <?= json_encode(__('share_hours'), JSON_HEX_TAG | JSON_HEX_AMP) ?>
        };
        const token = <?= json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const filename = <?= json_encode($share['filename'], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const isDir = <?= $share['is_dir'] ? 'true' : 'false' ?>;
        const fileSize = <?= (int)($share['size'] ?? 0) ?>;
        let controller = null, startTime = 0, progressTimer = null;
        
        window.startDownload = async function() {
            const downloadBtn = document.getElementById('download-btn');
            const progressContainer = document.getElementById('progress-container');
            const progressBar = document.getElementById('progress-bar');
            const progressText = document.getElementById('progress-text');
            const progressSpeed = document.getElementById('progress-speed');
            downloadBtn.disabled = true;
            downloadBtn.style.visibility = 'hidden';
            controller = new AbortController();
            startTime = Date.now();
            // 진행바는 300ms 후에 표시 (작은 파일은 그 전에 끝남)
            const cancelBtn = document.getElementById('cancel-btn');
            // 진행바는 500ms 후에 표시 (작은 파일은 그 전에 끝남)
            progressTimer = setTimeout(function() { progressContainer.classList.add('active'); }, 500);
            const downloadUrl = `share.php?t=${encodeURIComponent(token)}&download=1`;
            try {
                const response = await fetch(downloadUrl, { signal: controller.signal });
                if (!response.ok) throw new Error(S.failed);
                const contentLength = response.headers.get('Content-Length');
                const total = parseInt(contentLength, 10) || fileSize || 0;
                let downloadFilename = isDir ? filename + '.zip' : filename;
                const cd = response.headers.get('Content-Disposition');
                if (cd) {
                    const utf8Match = cd.match(/filename\*=UTF-8''([^;]+)/i);
                    if (utf8Match) { try { downloadFilename = decodeURIComponent(utf8Match[1]); } catch(e) {} }
                    else { const m = cd.match(/filename="([^"]+)"/); if (m) downloadFilename = m[1]; }
                }
                if (total > 0) {
                    const reader = response.body.getReader();
                    const chunks = [];
                    let received = 0;
                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        chunks.push(value);
                        received += value.length;
                        const percent = Math.round((received / total) * 100);
                        progressBar.style.width = percent + '%';
                        progressText.textContent = `${fmtSize(received)} / ${fmtSize(total)} (${percent}%)`;
                        const elapsed = (Date.now() - startTime) / 1000;
                        if (elapsed > 0) {
                            const speed = received / elapsed;
                            const remaining = (total - received) / speed;
                            progressSpeed.textContent = `${fmtSize(speed)}/s · ${S.remaining}: ${fmtTime(remaining)}`;
                        }
                    }
                    saveBlob(new Blob(chunks), downloadFilename);
                } else {
                    progressText.textContent = S.downloading;
                    saveBlob(await response.blob(), downloadFilename);
                }
                showToast(`${downloadFilename} ${S.complete}`, 'success');
                resetUI();
            } catch (e) {
                if (e.name === 'AbortError') showToast(S.cancelled, 'info');
                else { console.error('Download error:', e); showToast(e.message || S.failed, 'error'); }
                resetUI();
            }
        };
        window.cancelDownload = function() { if (controller) { controller.abort(); controller = null; } };
        
        // 인라인 onclick 대신 이벤트 바인딩 (CSP 호환)
        var dlBtn = document.getElementById('download-btn');
        if (dlBtn) dlBtn.addEventListener('click', function() { startDownload(); });
        var clBtn = document.getElementById('cancel-btn');
        if (clBtn) clBtn.addEventListener('click', function() { cancelDownload(); });
        function resetUI() {
            clearTimeout(progressTimer);
            const b = document.getElementById('download-btn'), p = document.getElementById('progress-container');
            b.disabled = false; b.style.visibility = 'visible';
            p.classList.remove('active');
            document.getElementById('progress-bar').style.width = '0%';
            document.getElementById('progress-speed').textContent = '';
            controller = null;
        }
        function saveBlob(blob, fn) {
            const u = URL.createObjectURL(blob), a = document.createElement('a');
            a.href = u; a.download = fn; a.style.display = 'none';
            document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(u);
        }
        function fmtSize(b) {
            if (b === 0) return '0 B';
            const u = ['B','KB','MB','GB','TB'], i = Math.floor(Math.log(b)/Math.log(1024));
            return (b/Math.pow(1024,i)).toFixed(i>0?1:0)+' '+u[i];
        }
        function fmtTime(sec) {
            if (!isFinite(sec)||sec<0) return '--:--';
            if (sec<60) return Math.round(sec)+S.sec;
            if (sec<3600) return Math.floor(sec/60)+S.min+Math.round(sec%60)+S.sec;
            return Math.floor(sec/3600)+S.hr+Math.floor((sec%3600)/60)+S.min;
        }
        function showToast(msg, type='info') {
            const t = document.getElementById('toast');
            t.textContent = msg; t.className = 'toast '+type; t.classList.add('show');
            setTimeout(()=>t.classList.remove('show'), 3000);
        }
    })();
    </script>
    <?php endif; ?>
    <?php if (!empty($isStreamable)): ?>
    <script src="assets/vendor/hls-1.5.7.min.js"></script>
    <script nonce="<?= $cspNonce ?>">
    (function() {
        const player = document.getElementById('stream-player');
        if (!player) return;
        
        // 재생/일시정지 오버레이 토글
        const playerWrap = document.getElementById('player-wrap');
        const playPauseOverlay = document.getElementById('share-play-pause');
        if (playPauseOverlay && playerWrap) {
            const iconPlay = playPauseOverlay.querySelector('.icon-play');
            const iconPause = playPauseOverlay.querySelector('.icon-pause');
            
            const showPlayIcon = () => {
                if (iconPlay) iconPlay.style.display = '';
                if (iconPause) iconPause.style.display = 'none';
                playerWrap.classList.remove('playing');
            };
            const showPauseIcon = () => {
                if (iconPlay) iconPlay.style.display = 'none';
                if (iconPause) iconPause.style.display = '';
                playerWrap.classList.add('playing');
            };
            
            const togglePlay = (e) => {
                e.stopPropagation();
                if (e.type === 'touchend') e.preventDefault();
                if (player.paused || player.ended) {
                    player.play().catch(() => {});
                } else {
                    player.pause();
                }
            };
            
            playPauseOverlay.addEventListener('click', togglePlay);
            playPauseOverlay.addEventListener('touchend', togglePlay);
            
            player.addEventListener('play', () => showPauseIcon());
            player.addEventListener('pause', () => { if (!player.ended) showPlayIcon(); });
            player.addEventListener('ended', () => showPlayIcon());
        }
        
        
        <?php if (!empty($needsTranscode)): ?>
        // === 트랜스코딩 (MMS/MSE/직접 src 자동 선택) ===
        const status = document.getElementById('transcode-status');
        const overlay = document.getElementById('play-overlay');
        const controls = document.getElementById('player-controls');
        let loaded = false;
        let _userRequestedPlay = false;
        
        async function startTranscode() {
            if (loaded) return;
            loaded = true;
            _userRequestedPlay = true;
            if (overlay) overlay.style.display = 'none';
            if (playerWrap) playerWrap.classList.remove('has-initial-overlay');
            if (status) status.classList.add('active');
            if (controls) controls.style.display = '';
            
            // info 요청 (HLS/MMS 시작 후 — _shareHlsSession이 설정된 후 실행)
            setTimeout(() => _fetchShareInfo(), 2000);
            
            const hlsStartUrl = player.dataset.hlsUrl;
            const transcodeUrl = player.dataset.transcodeUrl;
            
            // HLS 모드 시도
            try {
                const startRes = await fetch(hlsStartUrl);
                const contentType = startRes.headers.get('content-type') || '';
                if (!contentType.includes('json')) {
                    throw new Error('HLS start returned non-JSON: ' + contentType);
                }
                
                const startData = await startRes.json();
                
                if (startData.success && startData.playlist) {
                    if (status) status.classList.remove('active');
                    
                    // hls.js 우선 사용 (크롬/엣지 등 — 네이티브 HLS보다 안정적)
                    if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                        
                        const hls = new Hls({
                            maxBufferLength: 30,
                            maxMaxBufferLength: 60,
                            startLevel: -1,
                            // ★ playlist 응답 시 X-Current-Encoder 헤더 읽어 배지 실시간 갱신
                            //   서버가 HW 실패 → SW fallback 실행한 경우 반영
                            xhrSetup: function(xhr, url) {
                                xhr.addEventListener('load', function() {
                                    if (url && url.indexOf('hls_action=playlist') !== -1) {
                                        try {
                                            const enc = xhr.getResponseHeader('X-Current-Encoder');
                                            const b = document.getElementById('stream-badge');
                                            if (enc && b) {
                                                const isHw = (enc !== 'libx264');
                                                const names = {'h264_nvenc':'NVIDIA','h264_qsv':'Intel','h264_amf':'AMD','libx264':'CPU'};
                                                const name = names[enc] || enc;
                                                const newText = (isHw ? 'HW' : 'SW') + ' : ' + name;
                                                const curEnc = b.querySelector('.encoder-info');
                                                if (!curEnc || curEnc.textContent !== newText) {
                                                    b.innerHTML = '⚡ HLS <?= __('streaming', '스트리밍') ?> <span class="encoder-info">' + newText + '</span>';
                                                }
                                            }
                                        } catch(e) { /* 헤더 읽기 실패 무시 */ }
                                    }
                                });
                            }
                        });
                        hls.loadSource(startData.playlist);
                        hls.attachMedia(player);
                        hls.on(Hls.Events.MANIFEST_PARSED, () => {
                            if (status) status.classList.remove('active');
                            const b = document.getElementById('stream-badge');
                            if (b) {
                                const enc = b.querySelector('span');
                                b.innerHTML = '⚡ HLS <?= __('streaming', '스트리밍') ?>' + (enc && enc.textContent.includes(':') ? ' ' + enc.outerHTML : '');
                            }
                            if (_userRequestedPlay) player.play().catch(() => {});
                        });
                        hls.on(Hls.Events.ERROR, (event, data) => {
                            console.error('[Share HLS] Error:', data.type, data.details, data.fatal ? 'FATAL' : '');
                            if (data.fatal) {
                                hls.destroy();
                                _shareHlsInstance = null;
                                _shareHlsSession = null; // stop 전송 방지
                                _fallbackToMMS(transcodeUrl);
                            }
                        });
                        _shareHlsSession = startData.session;
                        _shareHlsInstance = hls;
                        return;
                    }
                    
                    // Safari 등 hls.js 미지원 → 네이티브 HLS fallback
                    if (player.canPlayType('application/vnd.apple.mpegurl')) {
                        player.src = startData.playlist;
                        player.load();
                        const b = document.getElementById('stream-badge');
                        if (b) {
                            const enc = b.querySelector('span');
                            b.innerHTML = '⚡ HLS <?= __('streaming', '스트리밍') ?>' + (enc && enc.textContent.includes(':') ? ' ' + enc.outerHTML : '');
                        }
                        player.play().catch(() => {});
                        _shareHlsSession = startData.session;
                        return;
                    }
                    
                    
                } else {
                    console.error('[Share HLS] Start failed:', startData);
                }
            } catch(e) {
                // HLS 실패 — force_sw로 1회 재시도
                if (!window._shareHlsSwRetried) {
                    window._shareHlsSwRetried = true;
                    try {
                        const swUrl = hlsStartUrl + '&force_sw=1';
                        const swRes = await fetch(swUrl);
                        const swCt = swRes.headers.get('content-type') || '';
                        if (swCt.includes('json')) {
                            const swData = await swRes.json();
                            if (swData.success && swData.playlist) {
                                if (status) status.classList.remove('active');
                                const swBadge = document.getElementById('stream-badge');
                                if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                                    const hls = new Hls({ maxBufferLength: 30, maxMaxBufferLength: 60 });
                                    hls.loadSource(swData.playlist);
                                    hls.attachMedia(player);
                                    hls.on(Hls.Events.MANIFEST_PARSED, () => {
                                        if (swBadge) swBadge.innerHTML = '⚡ HLS <?= __('streaming', '스트리밍') ?> <span class="encoder-info">SW : CPU</span>';
                                        if (_userRequestedPlay) player.play().catch(() => {});
                                    });
                                    hls.on(Hls.Events.ERROR, (event, data) => {
                                        if (data.fatal) { hls.destroy(); _shareHlsInstance = null; _shareHlsSession = null; _fallbackToMMS(transcodeUrl); }
                                    });
                                    _shareHlsSession = swData.session;
                                    _shareHlsInstance = hls;
                                    return;
                                } else if (player.canPlayType('application/vnd.apple.mpegurl')) {
                                    player.src = swData.playlist;
                                    player.load();
                                    player.play().catch(() => {});
                                    _shareHlsSession = swData.session;
                                    return;
                                }
                            }
                        }
                    } catch(swErr) {}
                }
            }
            
            // HLS 실패 시 기존 MMS/MSE fallback
            
            _fallbackToMMS(transcodeUrl);
        }
        
        var _shareHlsSession = null;
        var _shareHlsInstance = null;
        
        function _fallbackToMMS(transcodeUrl) {
            const MSApi = window.ManagedMediaSource || window.MediaSource;
            const mimeType = 'video/mp4; codecs="avc1.640029,mp4a.40.2"';
            
            if (MSApi && typeof MSApi.isTypeSupported === 'function' && MSApi.isTypeSupported(mimeType)) {
                try {
                    const mediaSource = new MSApi();
                    if (window.ManagedMediaSource) {
                        player.disableRemotePlayback = true;
                        player.srcObject = mediaSource;
                    } else {
                        player.src = URL.createObjectURL(mediaSource);
                    }
                    
                    mediaSource.addEventListener('sourceopen', async () => {
                        const sourceBuffer = mediaSource.addSourceBuffer(mimeType);
                        const mmsBadge = document.getElementById('stream-badge');
                        if (mmsBadge) mmsBadge.innerHTML = '⚡ MMS <?= __('streaming', '스트리밍') ?>';
                        const queue = [];
                        let appending = false;
                        let userPaused = false;
                        player.addEventListener('pause', () => { userPaused = true; });
                        player.addEventListener('play', () => { userPaused = false; });
                        
                        function appendNext() {
                            if (appending || queue.length === 0 || sourceBuffer.updating) return;
                            appending = true;
                            try { sourceBuffer.appendBuffer(queue.shift()); } catch(e) { appending = false; }
                        }
                        sourceBuffer.addEventListener('updateend', () => {
                            appending = false;
                            appendNext();
                            if (!userPaused && player.paused && sourceBuffer.buffered.length > 0) {
                                player.play().catch(() => {});
                            }
                        });
                        
                        try {
                            const resp = await fetch(transcodeUrl);
                            if (!resp.ok || !resp.body) throw new Error('fetch failed');
                            if (status) status.classList.remove('active');
                            const reader = resp.body.getReader();
                            while (true) {
                                const {done, value} = await reader.read();
                                if (done) break;
                                queue.push(value);
                                appendNext();
                            }
                            const waitUpdate = () => new Promise(r => { if (!sourceBuffer.updating) return r(); sourceBuffer.addEventListener('updateend', r, {once:true}); });
                            await waitUpdate();
                            if (mediaSource.readyState === 'open') mediaSource.endOfStream();
                        } catch(e) {
                            player.srcObject = null;
                            player.src = transcodeUrl;
                            player.load();
                            player.play().catch(() => {});
                            const pb1 = document.getElementById('stream-badge');
                            if (pb1) pb1.innerHTML = '⚡ Pipe <?= __('streaming', '스트리밍') ?>';
                        }
                    }, {once: true});
                } catch(e) {
                    player.src = transcodeUrl;
                    player.load();
                    player.play().catch(() => {});
                    const pb2 = document.getElementById('stream-badge');
                    if (pb2) pb2.innerHTML = '⚡ Pipe <?= __('streaming', '스트리밍') ?>';
                }
            } else {
                player.src = transcodeUrl;
                player.load();
                player.play().catch(() => {});
                const pb3 = document.getElementById('stream-badge');
                if (pb3) pb3.innerHTML = '⚡ Pipe <?= __('streaming', '스트리밍') ?>';
            }
        }
        
        if (overlay) overlay.addEventListener('click', startTranscode);
        player.addEventListener('play', startTranscode);
        player.addEventListener('canplay', () => { if (status) status.classList.remove('active'); });
        player.addEventListener('playing', () => { if (status) status.classList.remove('active'); });
        player.addEventListener('error', () => {
            if (!loaded) return;
            if (status) {
                const e = player.error;
                let detail = '';
                if (e) {
                    const codes = {1:'<?= __("media_err_aborted", "중단됨") ?>',2:'<?= __("media_err_network", "네트워크 오류") ?>',3:'<?= __("media_err_decode", "디코딩 오류") ?>',4:'<?= __("media_err_not_supported", "지원하지 않는 형식") ?>'};
                    detail = ' (' + (codes[e.code] || 'code:' + e.code) + ')';
                }
                status.innerHTML = '❌ <?= __("share_transcode_failed", "동영상 변환에 실패했습니다.") ?>' + detail;
                status.classList.add('active');
            }
        });
        
        // 페이지 떠날 때 HLS 세션 정리
        function _cleanupShareHls() {
            if (_shareHlsInstance) { try { _shareHlsInstance.destroy(); _shareHlsInstance = null; } catch(e) {} }
            if (_shareHlsSession) {
                const token = <?= json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                const stopUrl = 'share.php?t=' + encodeURIComponent(token) + '&download=1&stream=1&hls=1&hls_action=stop&session=' + _shareHlsSession;
                const fd = new FormData();
                fd.append('session', _shareHlsSession);
                navigator.sendBeacon(stopUrl, fd);
                _shareHlsSession = null;
            }
        }
        window.addEventListener('beforeunload', _cleanupShareHls);
        window.addEventListener('pagehide', _cleanupShareHls);
        // 모바일에서만 visibilitychange로 정리 (데스크톱은 beforeunload으로 충분)
        if (/iPhone|iPad|iPod|Android/i.test(navigator.userAgent)) {
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden' && _shareHlsSession) {
                    setTimeout(() => {
                        if (document.visibilityState === 'hidden') _cleanupShareHls();
                    }, 30000); // 30초 — 모바일 탭 전환 시 충분한 대기
                }
            });
        }
        
        // === 트랜스코딩 info 요청 (오디오 트랙 / 인코더 / duration) ===
        async function _fetchShareInfo() {
            try {
                const transcodeUrl = player.dataset.transcodeUrl;
                const infoUrl = transcodeUrl + '&info=1';
                const resp = await fetch(infoUrl);
                const info = await resp.json();
                
                // 인코더 배지
                // ※ xhrSetup이 playlist 응답에서 이미 배지 설정한 경우 덮어쓰지 않음
                //   (HLS playlist가 info API보다 먼저 응답 오면 배지가 이미 정확한 상태)
                //   info API는 detectHwEncoder() 반환값만 알고 실제 HLS에서 SW fallback 됐는지 모름
                const badge = document.getElementById('stream-badge');
                if (badge && info.encoder && !window._shareHlsSwRetried) {
                    const names = {'h264_nvenc':'NVIDIA','h264_qsv':'Intel','h264_amf':'AMD','libx264':'CPU','libx264 (CPU)':'CPU'};
                    const isHw = !info.encoder.includes('libx264');
                    const name = names[info.encoder] || info.encoder;
                    const method = _shareHlsSession ? 'HLS' : 'MMS';
                    // xhrSetup이 먼저 SW로 바꾼 경우, info가 HW라고 해서 되돌리면 안 됨
                    const curEnc = badge.querySelector('.encoder-info');
                    const alreadySw = curEnc && curEnc.textContent.startsWith('SW');
                    if (!(alreadySw && isHw)) {
                        badge.innerHTML = `⚡ ${method} <?= __('streaming', '스트리밍') ?> <span class="encoder-info">${isHw?'HW':'SW'} : ${name}</span>`;
                    }
                }
                
                // 오디오 트랙
                if (info.audio_tracks && info.audio_tracks.length > 1) {
                    const wrap = document.getElementById('share-audio-track-wrap');
                    const sel = document.getElementById('share-audio-select');
                    if (wrap && sel) {
                        wrap.style.display = '';
                        info.audio_tracks.forEach((t, i) => {
                            const label = [t.language, t.codec, t.channels, t.title].filter(Boolean).join(' · ') || 'Track ' + (i+1);
                            const opt = document.createElement('option');
                            opt.value = t.index;
                            opt.textContent = label;
                            sel.appendChild(opt);
                        });
                        sel.addEventListener('change', () => {
                            sel.blur();
                            const newUrl = transcodeUrl + '&audio=' + sel.value;
                            player.src = newUrl;
                            player.load();
                            player.play().catch(() => {});
                        });
                    }
                }
                
                // duration 표시
                if (info.duration && info.duration > 0) {
                    const realDur = info.duration;
                    const fmt = (sec) => {
                        const h = Math.floor(sec/3600), m = Math.floor((sec%3600)/60), s = Math.floor(sec%60);
                        return h > 0 ? `${h}:${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}` : `${m}:${s.toString().padStart(2,'0')}`;
                    };
                    const durText = fmt(realDur);
                    const playerWrap = document.getElementById('player-wrap');
                    const durEl = document.createElement('div');
                    durEl.className = 'transcode-duration';
                    durEl.textContent = `0:00 / ${durText}`;
                    if (playerWrap) {
                        playerWrap.style.position = 'relative';
                        playerWrap.appendChild(durEl);
                    }
                    player.addEventListener('timeupdate', () => {
                        durEl.textContent = `${fmt(player.currentTime)} / ${durText}`;
                    });
                }
            } catch(e) { console.error('share info error:', e); }
        }
        <?php endif; ?>
        <?php if (empty($needsTranscode) && $isVideo): ?>
        // === 네이티브 재생 실패 → 트랜스코딩 자동 전환 ===
        (function() {
            let _nativeFallbackDone = false;
            const _triggerTranscodeFallback = () => {
                if (_nativeFallbackDone) return;
                _nativeFallbackDone = true;
                const hlsUrl = player.dataset.hlsUrl;
                const transcodeUrl = player.dataset.transcodeUrl;
                if (!hlsUrl && !transcodeUrl) return;
                
                // 배지 업데이트
                const badge = document.querySelector('.stream-badge');
                if (badge) { badge.className = 'stream-badge transcode'; badge.textContent = '⚡ <?= __('realtime_streaming', '실시간 스트리밍') ?>'; }
                
                // 상태 표시
                const status = document.getElementById('transcode-status');
                if (status) status.classList.add('active');
                const controls = document.getElementById('player-controls');
                if (controls) controls.style.display = '';
                
                // 비디오 소스 제거
                player.pause();
                player.removeAttribute('src');
                player.querySelectorAll('source').forEach(s => s.remove());
                
                // HLS 시도
                const _startFallbackTranscode = async () => {
                    try {
                        const startRes = await fetch(hlsUrl);
                        const ct = startRes.headers.get('content-type') || '';
                        if (!ct.includes('json')) throw new Error('non-JSON');
                        const startData = await startRes.json();
                        if (startData.success && startData.playlist) {
                            if (status) status.classList.remove('active');
                            if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                                const hls = new Hls({ maxBufferLength: 30, maxMaxBufferLength: 60, startLevel: -1 });
                                hls.loadSource(startData.playlist);
                                hls.attachMedia(player);
                                hls.on(Hls.Events.MANIFEST_PARSED, () => {
                                    if (status) status.classList.remove('active');
                                    player.play().catch(() => {});
                                });
                                hls.on(Hls.Events.ERROR, (ev, data) => {
                                    if (data.fatal) { hls.destroy(); _fallbackMMS(); }
                                });
                                window._shareNativeHls = hls;
                                window._shareNativeHlsSession = startData.session;
                                return;
                            }
                            if (player.canPlayType('application/vnd.apple.mpegurl')) {
                                player.src = startData.playlist;
                                player.load();
                                player.play().catch(() => {});
                                window._shareNativeHlsSession = startData.session;
                                return;
                            }
                        }
                    } catch(e) {}
                    _fallbackMMS();
                };
                
                const _fallbackMMS = async () => {
                    // HLS 세션 정리 (시도했다가 실패한 경우)
                    if (window._shareNativeHlsSession) {
                        const tk = <?= json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                        fetch('share.php?t=' + encodeURIComponent(tk) + '&download=1&stream=1&hls=1&hls_action=stop&session=' + window._shareNativeHlsSession).catch(() => {});
                        window._shareNativeHlsSession = null;
                    }
                    try {
                        if (status) status.classList.add('active');
                        player.src = transcodeUrl;
                        player.load();
                        player.addEventListener('canplay', () => { if (status) status.classList.remove('active'); }, { once: true });
                        player.play().catch(() => {});
                    } catch(e) {
                        if (status) { status.innerHTML = '❌ <?= __('share_transcode_failed', '동영상 변환에 실패했습니다.') ?>'; status.classList.add('active'); }
                    }
                };
                
                _startFallbackTranscode();
                
                // 자막 재적용
                <?php if (!empty($autoSubtitles)): ?>
                setTimeout(() => {
                    const autoSubs = <?= json_encode($autoSubtitles, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                    if (autoSubs.length > 0) {
                        subCues = parseSubtitle(autoSubs[0].content, autoSubs[0].ext);
                        if (subCues.length > 0) _updateTrackElement();
                    }
                }, 1000);
                <?php endif; ?>
            };
            
            // 네이티브 비디오 에러 시 전환
            player.addEventListener('error', (e) => {
                if (_nativeFallbackDone) return;
                const err = player.error;
                // MEDIA_ERR_SRC_NOT_SUPPORTED(4) 또는 MEDIA_ERR_DECODE(3)
                if (err && (err.code === 4 || err.code === 3)) {
                    _triggerTranscodeFallback();
                }
            });
            
            // 10초 내 재생 시작 안 되면 전환 (네트워크 스톨 등)
            let _playStarted = false;
            player.addEventListener('playing', () => { _playStarted = true; });
            player.addEventListener('play', () => {
                if (_nativeFallbackDone) return;
                setTimeout(() => {
                    if (!_playStarted && !_nativeFallbackDone && player.readyState < 3) {
                        _triggerTranscodeFallback();
                    }
                }, 10000);
            });
            
            // 네이티브 전환 HLS 세션 정리
            const _cleanupNativeHls = () => {
                if (window._shareNativeHls) { try { window._shareNativeHls.destroy(); } catch(e) {} window._shareNativeHls = null; }
                if (window._shareNativeHlsSession) {
                    const tk = <?= json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                    const stopUrl = 'share.php?t=' + encodeURIComponent(tk) + '&download=1&stream=1&hls=1&hls_action=stop&session=' + window._shareNativeHlsSession;
                    navigator.sendBeacon(stopUrl, new FormData());
                    window._shareNativeHlsSession = null;
                }
            };
            window.addEventListener('beforeunload', _cleanupNativeHls);
            window.addEventListener('pagehide', _cleanupNativeHls);
        })();
        <?php endif; ?>
        const wrap = document.getElementById('player-wrap');
        if (wrap) {
            player.addEventListener('playing', () => wrap.classList.add('playing'));
            player.addEventListener('pause', () => { wrap.classList.remove('playing'); wrap.classList.remove('show-controls'); });
            player.addEventListener('ended', () => { wrap.classList.remove('playing'); wrap.classList.remove('show-controls'); });
            
            // 모바일: 터치 시 컨트롤 표시/숨김 토글 (3초 후 자동 숨김)
            if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
                let _shareControlsTimer = null;
                wrap.addEventListener('touchstart', (e) => {
                    if (wrap.classList.contains('playing')) {
                        wrap.classList.toggle('show-controls');
                        if (_shareControlsTimer) clearTimeout(_shareControlsTimer);
                        if (wrap.classList.contains('show-controls')) {
                            _shareControlsTimer = setTimeout(() => {
                                wrap.classList.remove('show-controls');
                                _shareControlsTimer = null;
                            }, 3000);
                        }
                    }
                }, { passive: true });
            }
            
            // 더블클릭으로 전체화면 (wrap 우선 - 오버레이 유지, iOS 폴백: 네이티브)
            player.addEventListener('dblclick', (e) => {
                e.preventDefault();
                if (document.fullscreenElement || document.webkitFullscreenElement) {
                    (document.exitFullscreen || document.webkitExitFullscreen).call(document);
                } else if (wrap.requestFullscreen || wrap.webkitRequestFullscreen) {
                    (wrap.requestFullscreen || wrap.webkitRequestFullscreen).call(wrap).catch(() => {});
                } else if (player.webkitEnterFullscreen) {
                    // iOS Safari 폴백: 네이티브 전체화면
                    if (player.paused) player.play().catch(() => {});
                    setTimeout(() => player.webkitEnterFullscreen(), 50);
                }
            });
            // 전체화면 시 마우스/터치 비활동 → 배지/오디오 숨김
            let hideTimer = null;
            const hideUI = () => { wrap.classList.add('fs-idle'); };
            const showUI = () => { wrap.classList.remove('fs-idle'); clearTimeout(hideTimer); hideTimer = setTimeout(hideUI, 2500); };
            wrap.addEventListener('mousemove', () => {
                if (document.fullscreenElement || document.webkitFullscreenElement) showUI();
            });
            // 모바일: 전체화면에서 터치 시 UI 표시
            wrap.addEventListener('touchstart', () => {
                if (document.fullscreenElement || document.webkitFullscreenElement) showUI();
            }, { passive: true });
            document.addEventListener('fullscreenchange', () => {
                const isFull = !!(document.fullscreenElement || document.webkitFullscreenElement);
                if (isFull) {
                    player.style.maxHeight = '100vh';
                    player.style.height = '100vh';
                    hideTimer = setTimeout(hideUI, 2500);
                } else {
                    player.style.maxHeight = '';
                    player.style.height = '';
                    wrap.classList.remove('fs-idle');
                    clearTimeout(hideTimer);
                }
            });
            // iOS Safari 네이티브 전체화면 이벤트
            player.addEventListener('webkitbeginfullscreen', () => {
                if (fsBtn) fsBtn.innerHTML = '⊠';
            });
            player.addEventListener('webkitendfullscreen', () => {
                if (fsBtn) fsBtn.innerHTML = '⛶';
            });
        }
        
        // === 배속 ===
        document.querySelectorAll('.ctrl-btn[data-speed]').forEach(btn => {
            btn.addEventListener('click', () => {
                player.playbackRate = parseFloat(btn.dataset.speed);
                document.querySelectorAll('.ctrl-btn[data-speed]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            });
        });
        
        // === PIP ===
        const pipBtn = document.getElementById('btn-pip');
        if (pipBtn) {
            if (document.pictureInPictureEnabled) {
                pipBtn.addEventListener('click', async () => {
                    try {
                        if (document.pictureInPictureElement) await document.exitPictureInPicture();
                        else await player.requestPictureInPicture();
                    } catch(e) {}
                });
            } else {
                pipBtn.style.display = 'none';
            }
        }
        
        // === 전체화면 (wrap 우선 - 오버레이 유지, iOS 폴백: 네이티브) ===
        const fsBtn = document.getElementById('btn-fullscreen');
        if (fsBtn && wrap) {
            fsBtn.addEventListener('click', () => {
                const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
                if (fsEl) {
                    (document.exitFullscreen || document.webkitExitFullscreen).call(document);
                } else if (wrap.requestFullscreen || wrap.webkitRequestFullscreen) {
                    (wrap.requestFullscreen || wrap.webkitRequestFullscreen).call(wrap).catch(() => {});
                } else if (player.webkitEnterFullscreen) {
                    // iOS Safari 폴백: 네이티브 전체화면
                    if (player.paused) player.play().catch(() => {});
                    setTimeout(() => player.webkitEnterFullscreen(), 50);
                }
            });
        }
        
        // === 자막 ===
        const subOverlay = document.getElementById('subtitle-overlay');
        const subInput = document.getElementById('sub-file-input');
        let subCues = [];
        let subSize = 1.1;
        let subBottom = 8;
        
        if (subOverlay) {
            subOverlay.style.fontSize = subSize + 'em';
            subOverlay.style.bottom = subBottom + '%';
        }
        
        // 자막 크기 조절
        const btnSubDown = document.getElementById('btn-sub-down');
        const btnSubUp = document.getElementById('btn-sub-up');
        const btnSubPosUp = document.getElementById('btn-sub-pos-up');
        const btnSubPosDown = document.getElementById('btn-sub-pos-down');
        
        if (btnSubDown) btnSubDown.addEventListener('click', () => {
            subSize = Math.max(0.6, subSize - 0.1);
            if (subOverlay) subOverlay.style.fontSize = subSize + 'em';
        });
        if (btnSubUp) btnSubUp.addEventListener('click', () => {
            subSize = Math.min(2.5, subSize + 0.1);
            if (subOverlay) subOverlay.style.fontSize = subSize + 'em';
        });
        if (btnSubPosUp) btnSubPosUp.addEventListener('click', () => {
            subBottom = Math.min(40, subBottom + 2);
            if (subOverlay) subOverlay.style.bottom = subBottom + '%';
        });
        if (btnSubPosDown) btnSubPosDown.addEventListener('click', () => {
            subBottom = Math.max(0, subBottom - 2);
            if (subOverlay) subOverlay.style.bottom = subBottom + '%';
        });
        
        // 자막 싱크 조절
        let subSyncOffset = 0;
        const syncDisplay = document.getElementById('sub-sync-display');
        const updateSyncDisplay = () => {
            if (syncDisplay) {
                syncDisplay.textContent = (subSyncOffset >= 0 ? '+' : '') + subSyncOffset.toFixed(1) + 's';
                syncDisplay.style.color = subSyncOffset === 0 ? '' : '#ff9800';
            }
        };
        const btnSyncDown = document.getElementById('btn-sub-sync-down');
        const btnSyncUp = document.getElementById('btn-sub-sync-up');
        const btnSyncReset = document.getElementById('btn-sub-sync-reset');
        if (btnSyncDown) btnSyncDown.addEventListener('click', () => {
            subSyncOffset = Math.max(-30, subSyncOffset - 0.5);
            updateSyncDisplay();
        });
        if (btnSyncUp) btnSyncUp.addEventListener('click', () => {
            subSyncOffset = Math.min(30, subSyncOffset + 0.5);
            updateSyncDisplay();
        });
        if (btnSyncReset) btnSyncReset.addEventListener('click', () => {
            subSyncOffset = 0;
            updateSyncDisplay();
        });
        
        // 자막 파일 로딩
        if (subInput) subInput.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            const text = await file.text();
            const ext = file.name.split('.').pop().toLowerCase();
            subCues = parseSubtitle(text, ext);
            if (subCues.length > 0 && subOverlay) {
                subOverlay.textContent = '✅ ' + file.name + ' (' + subCues.length + ')';
                setTimeout(() => { subOverlay.textContent = ''; }, 2000);
            }
            // iOS 전체화면용 track 엘리먼트 갱신
            _updateTrackElement();
        });
        
        // iOS 전체화면용: subCues → VTT blob → <track> 엘리먼트
        let _iosSubActive = false;
        function _updateTrackElement() {
            // 기존 track 제거
            player.querySelectorAll('track').forEach(t => t.remove());
            if (subCues.length === 0) return;
            // cues → VTT 텍스트
            let vtt = 'WEBVTT\n\n';
            const pad = (n, d) => String(n).padStart(d, '0');
            const fmt = (s) => {
                const h = Math.floor(s / 3600);
                const m = Math.floor((s % 3600) / 60);
                const sec = Math.floor(s % 60);
                const ms = Math.round((s % 1) * 1000);
                return `${pad(h,2)}:${pad(m,2)}:${pad(sec,2)}.${pad(ms,3)}`;
            };
            for (const c of subCues) {
                // cue.text에는 <br>이 들어있으므로 줄바꿈으로 변환
                const txt = c.text.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, '');
                vtt += `${fmt(c.start)} --> ${fmt(c.end)}\n${txt}\n\n`;
            }
            const blob = new Blob([vtt], { type: 'text/vtt' });
            const trackUrl = URL.createObjectURL(blob);
            const trackEl = document.createElement('track');
            trackEl.kind = 'subtitles';
            trackEl.label = '자막';
            trackEl.srclang = 'ko';
            trackEl.src = trackUrl;
            trackEl.default = false;
            player.appendChild(trackEl);
            // 네이티브 트랙 비활성 (커스텀 오버레이 사용)
            try { for (let i = 0; i < player.textTracks.length; i++) player.textTracks[i].mode = 'disabled'; } catch(e) {}
        }
        
        // iOS 전체화면 진입/종료 시 네이티브 ↔ 커스텀 자막 전환
        player.addEventListener('webkitbeginfullscreen', () => {
            _iosSubActive = true;
            try { for (let i = 0; i < player.textTracks.length; i++) player.textTracks[i].mode = 'showing'; } catch(e) {}
            if (subOverlay) subOverlay.style.display = 'none';
        });
        player.addEventListener('webkitendfullscreen', () => {
            _iosSubActive = false;
            try { for (let i = 0; i < player.textTracks.length; i++) player.textTracks[i].mode = 'disabled'; } catch(e) {}
            if (subOverlay) subOverlay.style.display = '';
        });
        
        // 자막 동기화
        player.addEventListener('timeupdate', () => {
            if (!subOverlay || subCues.length === 0) return;
            if (_iosSubActive) return; // iOS 전체화면 중엔 네이티브 track이 처리
            const t = player.currentTime + subSyncOffset;
            let found = '';
            for (const cue of subCues) {
                if (t >= cue.start && t <= cue.end) {
                    found = cue.text;
                    break;
                }
            }
            subOverlay.innerHTML = found;
        });
        
        // === 자막 파서 (SRT, VTT, SMI) ===
        function parseSubtitle(text, ext) {
            if (ext === 'vtt' || ext === 'srt') return parseSrt(text);
            if (ext === 'smi' || ext === 'sami') return parseSmi(text);
            if (ext === 'ass' || ext === 'ssa') return parseAss(text);
            return parseSrt(text);
        }
        
        function parseSrt(text) {
            const cues = [];
            const blocks = text.replace(/\r/g, '').split(/\n\n+/);
            for (const block of blocks) {
                const lines = block.trim().split('\n');
                for (let i = 0; i < lines.length; i++) {
                    const m = lines[i].match(/(\d{1,2}):(\d{2}):(\d{2})[.,](\d{3})\s*-->\s*(\d{1,2}):(\d{2}):(\d{2})[.,](\d{3})/);
                    if (m) {
                        const start = +m[1]*3600 + +m[2]*60 + +m[3] + +m[4]/1000;
                        const end = +m[5]*3600 + +m[6]*60 + +m[7] + +m[8]/1000;
                        // 보안: HTML 태그 제거 후 엔티티 이스케이프 (XSS 방어)
                        let txt = lines.slice(i+1).join('\n').replace(/<[^>]+>/g, '').trim();
                        if (txt) {
                            txt = txt
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/"/g, '&quot;')
                                .replace(/'/g, '&#39;')
                                .replace(/\n/g, '<br>');
                            cues.push({ start, end, text: txt });
                        }
                        break;
                    }
                }
            }
            return cues;
        }
        
        function parseSmi(text) {
            const cues = [];
            // CRLF 정규화 (Windows 자막 파일 대응)
            text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const regex = /<sync\s+start=(\d+)>\s*<p[^>]*>([\s\S]*?)(?=<sync|$)/gi;
            let m;
            while ((m = regex.exec(text)) !== null) {
                const ms = parseInt(m[1]);
                // 보안: HTML 태그/entity 제거 후 이스케이프 (XSS 방어)
                let txt = m[2].replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, '').replace(/&nbsp;/gi, ' ').replace(/\n{2,}/g, '\n').trim();
                if (txt && txt !== '&nbsp;' && !/^\s*$/.test(txt)) {
                    txt = txt
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#39;')
                        .replace(/\n/g, '<br>');
                    cues.push({ start: ms/1000, end: 0, text: txt });
                }
            }
            for (let i = 0; i < cues.length - 1; i++) {
                if (!cues[i].end) cues[i].end = cues[i+1].start;
            }
            if (cues.length > 0 && !cues[cues.length-1].end) {
                cues[cues.length-1].end = cues[cues.length-1].start + 5;
            }
            return cues;
        }
        
        function parseAss(text) {
            const cues = [];
            text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const lines = text.split('\n');
            for (const line of lines) {
                if (!line.startsWith('Dialogue:')) continue;
                const parts = line.substring(9).split(',');
                if (parts.length < 10) continue;
                const start = assTime(parts[1].trim());
                const end = assTime(parts[2].trim());
                // 보안: ASS 스타일 태그 {...} 제거 + HTML 태그 제거 (XSS 방어) + \N → <br>
                let txt = parts.slice(9).join(',')
                    .replace(/\{[^}]*\}/g, '')
                    .replace(/<[^>]+>/g, '')
                    .trim();
                // HTML 특수문자 이스케이프 후 \N → <br>
                txt = txt
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;')
                    .replace(/\\N/gi, '<br>');
                if (txt) cues.push({ start, end, text: txt });
            }
            return cues;
        }
        
        function assTime(s) {
            const m = s.match(/(\d+):(\d{2}):(\d{2})[.](\d{2})/);
            return m ? +m[1]*3600 + +m[2]*60 + +m[3] + +m[4]/100 : 0;
        }
        
        // 서버에서 찾은 자막 자동 로드
        <?php if (!empty($autoSubtitles)): ?>
        (function() {
            const autoSubs = <?= json_encode($autoSubtitles, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            if (autoSubs.length > 0) {
                const sub = autoSubs[0]; // 첫 번째 자막 사용
                subCues = parseSubtitle(sub.content, sub.ext);
                if (subCues.length > 0 && subOverlay) {
                    _updateTrackElement(); // iOS 전체화면용 track 생성
                }
            }
        })();
        <?php endif; ?>
    })();
    </script>
    <?php endif; ?>
    <?php endif; ?>
</body>
</html>
