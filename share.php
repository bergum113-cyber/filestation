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

// 공유 MP3 ID3 커버 요청 — 가벼운 응답이라 우선 처리 (HLS stop 패턴)
if (isset($_GET['cover']) && $_GET['cover'] === '1') {
    // ★ 세션 락 즉시 해제 — 병렬 트랙 변경 요청이 직렬로 처리되는 것 방지
    //   메인 audio_cover/audio_lyrics와 동일 패턴
    session_write_close();
    
    $coverPassword = $_POST['password'] ?? $_GET['password'] ?? null;
    
    // 폴더 stream 공유: ?file=basename 으로 트랙별 커버 요청
    $coverSubFile = isset($_GET['file']) ? (string)$_GET['file'] : null;
    
    // PHP 자동 캐시 헤더 제거 (audioCover와 동일 패턴)
    while (ob_get_level()) ob_end_clean();
    header_remove('Cache-Control');
    header_remove('Expires');
    header_remove('Pragma');
    
    $cover = $shareManager->getShareCover($token, $coverPassword, $coverSubFile);
    if (!$cover) {
        // 커버 없음 또는 권한 실패 → 204 (콘솔 빨간 에러 방지)
        http_response_code(204);
        header('Cache-Control: public, max-age=604800');
        exit;
    }
    
    // 캐시 ETag (캐시 키에 mtime 포함이라 immutable)
    $etag = '"' . $cover['cache_key'] . '"';
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=2592000, immutable');
        exit;
    }
    
    header('Content-Type: ' . $cover['mime']);
    header('Content-Length: ' . strlen($cover['data']));
    header('ETag: ' . $etag, true);
    header('Cache-Control: public, max-age=2592000, immutable', true);
    echo $cover['data'];
    exit;
}

/*
// ★ 클라이언트 디버그 로그 수신 (펜닐님 진단용 — 임시) — 주석처리됨 (다음 디버깅 위해 보존)
if (isset($_GET['debug_log']) && $_GET['debug_log'] === '1') {
    while (ob_get_level()) ob_end_clean();
    
    $_dbgDir = __DIR__ . '/data/debug';
    if (!is_dir($_dbgDir)) @mkdir($_dbgDir, 0755, true);
    $_dbgFile = $_dbgDir . '/folder_stream_debug.log';
    
    $_msg = $_POST['msg'] ?? '';
    $_data = $_POST['data'] ?? '';
    @file_put_contents(
        $_dbgFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $_msg . ($_data ? ' | ' . $_data : '') . "\n",
        FILE_APPEND
    );
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}
*/

// 공유 MP3 가사 요청 — 세션 인증 통과 (URL 비번 비포함, getShareCover와 동일 패턴)
if (isset($_GET['lyrics']) && $_GET['lyrics'] === '1') {
    // ★ 세션 락 즉시 해제 — 병렬 트랙 변경 요청이 직렬로 처리되는 것 방지
    //   메인 audio_lyrics와 동일 패턴 (api.php:5806)
    session_write_close();
    
    while (ob_get_level()) ob_end_clean();
    header_remove('Cache-Control');
    header_remove('Expires');
    header_remove('Pragma');
    
    // 폴더 stream 공유: ?file=basename 으로 트랙별 가사 요청
    $lyricsSubFile = isset($_GET['file']) ? (string)$_GET['file'] : null;
    
    $lyrics = $shareManager->getShareLyrics($token, null, $lyricsSubFile);
    
    // ★ v 파라미터 있을 때 (mtime 기반) immutable 적용 — 같은 URL은 영원히 동일 응답
    //   v 파라미터 없는 단일 파일 케이스는 짧은 캐시 유지 (호환성)
    $hasVersion = isset($_GET['v']) && $_GET['v'] !== '';
    
    if (!$lyrics) {
        // 가사 없음 → 204 (조용히 fallback)
        http_response_code(204);
        if ($hasVersion) {
            header('Cache-Control: public, max-age=2592000, immutable', true);
        } else {
            header('Cache-Control: public, max-age=300', true);
        }
        exit;
    }
    
    // JSON 응답 (LRC 텍스트 + 메타)
    header('Content-Type: application/json; charset=utf-8', true);
    if ($hasVersion) {
        header('Cache-Control: public, max-age=2592000, immutable', true);
    } else {
        header('Cache-Control: public, max-age=3600', true);
    }
    echo json_encode([
        'source' => $lyrics['source'],     // 'lrc' | 'uslt' | 'txt'
        'synced' => $lyrics['synced'],     // true = LRC 시간태그 있음, false = 정적
        'text' => $lyrics['text'],
        'language' => $lyrics['language'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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

// ★ 폴더 stream + sub-file: 단일 트랙 페이지로 처리 (트랜스코드/HLS 등 단일 영상 흐름 재사용)
$pageSubFile = null;          // 페이지 렌더링 시 sub-file (검증 통과 시만 설정)
$folderTrackNext = null;      // 자동 다음 영상 URL (페이지 종료 후 이동)
$folderTrackPrev = null;      // 이전 영상 URL (네비게이션용)
$folderTrackIndex = null;     // 현재 트랙 1-based 인덱스
$folderTrackTotal = null;     // 총 트랙 수
$folderAutoFirstTrack = false; // 자동 선택 여부 (URL에 ?file= 없이 첫 영상 자동 선택된 경우)

if ($share && !empty($share['is_dir']) && ($share['share_type'] ?? '') === 'stream') {
    // 영상 폴더의 sub-file 처리 (?file= 명시 또는 자동 첫 트랙)
    $_subFileBasename = null;
    $_validatedPath = null;
    $_videoTracks = null;

    if (isset($_GET['file']) && (string)$_GET['file'] !== '') {
        // 케이스 A: ?file= 명시 — 보안 검증 통과 + 영상 트랙 매칭
        $_subFileGet = (string)$_GET['file'];
        $_validatedPath = $shareManager->validateSharedFolderSubPath($token, null, $_subFileGet);
        if ($_validatedPath !== null) {
            $_subFileBasename = basename($_validatedPath);
            $_allTracks = $shareManager->getSharedFolderTracks($token, null) ?: [];
            $_videoTracks = array_values(array_filter($_allTracks, fn($t) => ($t['type'] ?? '') === 'video'));
            // 영상 트랙 매칭 안 되면 무효화 (음악 sub-file 등은 폴더 stream 흐름 유지)
            $_isVideoSubFile = false;
            foreach ($_videoTracks as $_t) {
                if ($_t['file'] === $_subFileBasename) { $_isVideoSubFile = true; break; }
            }
            if (!$_isVideoSubFile) {
                $_subFileBasename = null;
                $_validatedPath = null;
                $_videoTracks = null;
            }
        }
    } else {
        // 케이스 B: ?file= 없음 — 영상 폴더 stream인 경우 첫 영상 트랙 자동 선택 (URL 변경 없음)
        $_allTracks = $shareManager->getSharedFolderTracks($token, null) ?: [];
        $_videoTracks = array_values(array_filter($_allTracks, fn($t) => ($t['type'] ?? '') === 'video'));
        $_audioTracks = array_values(array_filter($_allTracks, fn($t) => ($t['type'] ?? '') === 'audio'));
        // 영상 다수 또는 동률(영상 우선) — 라인 783 분기 로직과 동일
        if (count($_videoTracks) > 0 && count($_videoTracks) >= count($_audioTracks)) {
            $_first = $_videoTracks[0];
            $_validatedPath = $shareManager->validateSharedFolderSubPath($token, null, $_first['file']);
            if ($_validatedPath !== null) {
                $_subFileBasename = basename($_validatedPath);
                $folderAutoFirstTrack = true;
            } else {
                $_videoTracks = null;
            }
        } else {
            $_videoTracks = null;
        }
    }

    // 공통 처리: 영상 sub-file이 결정된 경우 페이지 메타 + 네비게이션 설정
    if ($_subFileBasename !== null && is_array($_videoTracks)) {
        foreach ($_videoTracks as $_idx => $_t) {
            if ($_t['file'] === $_subFileBasename) {
                $folderTrackIndex = $_idx + 1;
                if ($_idx + 1 < count($_videoTracks)) {
                    $folderTrackNext = "share.php?t=" . urlencode($token) . "&file=" . urlencode($_videoTracks[$_idx + 1]['file']);
                }
                if ($_idx > 0) {
                    $folderTrackPrev = "share.php?t=" . urlencode($token) . "&file=" . urlencode($_videoTracks[$_idx - 1]['file']);
                }
                break;
            }
        }

        $pageSubFile = $_subFileBasename;
        $folderTrackTotal = count($_videoTracks);

        // ★ $share 정보를 sub-file 정보로 교체 (단일 영상처럼 렌더링)
        $_subFileSize = @filesize($_validatedPath) ?: 0;
        $share['filename'] = $pageSubFile;       // 폴더명 → 영상 파일명
        $share['size'] = $_subFileSize;           // 폴더 크기 → 영상 크기
        $share['is_dir'] = 0;                     // 폴더 → 단일 파일로
    }
    // 음악 폴더는 폴더 stream 흐름 그대로 (트랙 목록 또는 음악 플레이어)
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
        h1 { font-size: 24px; color: #333; margin-bottom: 10px; word-break: break-all; overflow-wrap: break-word; }
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
        /* FSAudioPlayer 사용으로 다크 배경 (메인 .preview-audio-wrap 패턴 동일) */
        .player-wrap.audio-wrap {
            background: linear-gradient(135deg, #1e1e2e 0%, #13131a 100%);
            padding: 0;
            border-radius: 12px;
            text-align: center;
            width: 100%;
            /* 고정 높이로 다중 트랙 플레이리스트 스크롤 가능하게 (단일 트랙은 480px 그대로) */
            height: 600px;
            max-height: calc(100vh - 200px);
            min-width: 300px;
            min-height: 480px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: stretch;
            box-sizing: border-box;
            overflow: hidden;
        }
        @media (max-width: 768px) {
            .player-wrap.audio-wrap {
                min-width: 0 !important;
                width: 100% !important;
                padding: 0 !important;
                height: calc(100vh - 180px) !important;
                max-height: calc(100vh - 180px) !important;
                min-height: 420px !important;
                border-radius: 8px;
            }
        }
        .container.stream-mode { max-width: 800px; }
        .container.stream-mode .filename { font-size: 15px; color: #888; margin-bottom: 12px; }
        .container.stream-mode h1 { font-size: 20px; margin-bottom: 6px; }
        .container.stream-mode .icon { font-size: 48px; margin-bottom: 12px; }
        .container.stream-mode .info { font-size: 13px; padding: 12px; margin-bottom: 15px; }

        /* ★ 폴더 stream sub-file: 사이드 플레이리스트 패널 (팟플레이어 스타일)
           container 위치는 절대 고정 (가운데 정렬) + 패널은 container 우측 바로 옆 absolute
           → 패널 토글해도 영상 흔들림 X (펜닐님 피드백 반영) */
        .share-playlist-panel {
            position: absolute;
            left: 100%;
            top: 0;
            bottom: 0;
            margin-left: 8px;
            background: #1e1e2e;
            border-radius: 14px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            width: 340px;
            display: none;
            flex-direction: column;
            overflow: hidden;
            color: #ddd;
            text-align: left;
            z-index: 10;
            opacity: 0;
            transform: translateX(-12px);
            transition: transform 0.2s ease, opacity 0.2s ease;
        }
        .share-playlist-panel.open {
            display: flex;
            transform: translateX(0);
            opacity: 1;
        }
        /* container를 position relative로 — 패널의 absolute 기준 */
        .container.stream-mode { position: relative; }
        .share-playlist-header {
            padding: 16px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        .share-playlist-header .pl-icon { font-size: 22px; }
        .share-playlist-header .pl-title { color: #fff; font-size: 15px; font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .share-playlist-header .pl-count { color: #888; font-size: 12px; flex-shrink: 0; }
        .share-playlist-header .pl-close {
            background: none;
            border: none;
            color: #aaa;
            font-size: 20px;
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
            flex-shrink: 0;
        }
        .share-playlist-header .pl-close:hover { color: #fff; }
        .share-playlist-body {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 8px 0;
        }
        .share-playlist-body::-webkit-scrollbar { width: 8px; }
        .share-playlist-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }
        .share-playlist-body::-webkit-scrollbar-track { background: transparent; }
        .share-pl-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            color: #ccc;
            text-decoration: none;
            font-size: 13px;
            transition: background 0.15s;
            border-left: 3px solid transparent;
        }
        .share-pl-item:hover { background: rgba(255,255,255,0.06); }
        .share-pl-item.current {
            background: rgba(102,126,234,0.15);
            border-left-color: #667eea;
            color: #fff;
        }
        .share-pl-item .pl-num { color: #888; font-size: 11px; font-family: monospace; min-width: 26px; text-align: right; flex-shrink: 0; }
        .share-pl-item.current .pl-num { color: #667eea; font-weight: 600; }
        .share-pl-item .pl-name { flex: 1; min-width: 0; overflow: hidden; white-space: nowrap; position: relative; }
        .share-pl-item .pl-name-inner {
            display: inline-block;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: bottom;
        }
        /* 재생 중 트랙 + 텍스트 overflow 감지 시: 마퀴 (오른쪽 → 왼쪽 회전) */
        .share-pl-item.current .pl-name-inner.is-overflow {
            max-width: none;
            overflow: visible;
            text-overflow: clip;
            padding-left: 100%;
            animation: share-pl-marquee 12s linear infinite;
        }
        /* hover 시 마퀴 일시정지 */
        .share-pl-item.current:hover .pl-name-inner.is-overflow {
            animation-play-state: paused;
        }
        @keyframes share-pl-marquee {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-100%); }
        }
        .share-pl-item .pl-meta { color: #666; font-size: 10px; flex-shrink: 0; }
        .share-pl-item .pl-play-icon { color: #888; font-size: 12px; flex-shrink: 0; }
        .share-pl-item.current .pl-play-icon { color: #667eea; }

        /* 목록 토글 버튼 */
        .share-pl-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(102,126,234,0.15);
            border: 1px solid rgba(102,126,234,0.4);
            color: #667eea;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            font-family: inherit;
        }
        .share-pl-toggle:hover { background: rgba(102,126,234,0.25); }
        .share-pl-toggle.active { background: #667eea; color: #fff; }

        /* 모바일: 1024px 이하 — 영상 아래 세로 표시 (absolute 해제 → 본문 흐름) */
        @media (max-width: 1024px) {
            .share-playlist-panel {
                position: static;
                width: 100%;
                max-width: 800px;
                margin: 12px auto 0;
                transform: none;
                max-height: 50vh;
                left: auto;
                top: auto;
                bottom: auto;
            }
            .share-playlist-panel.open { transform: none; }
        }
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
        
        /* 시킹 오버레이 (메인 .video-seek-overlay 패턴 동일) */
        .video-seek-overlay {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.6);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: bold;
            opacity: 0;
            pointer-events: none;
            z-index: 13;
        }
        .video-seek-overlay.show { animation: seekFlash 0.6s ease-out forwards; }
        .video-seek-left { left: 16px; }
        .video-seek-right { right: 16px; }
        @keyframes seekFlash {
            0% { opacity: 0; transform: translateY(-50%) scale(0.8); }
            20% { opacity: 1; transform: translateY(-50%) scale(1.1); }
            40% { opacity: 1; transform: translateY(-50%) scale(1); }
            100% { opacity: 0; transform: translateY(-50%) scale(1); }
        }
        @media (max-width: 1024px) { .video-seek-overlay { display: none !important; } }
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
        
        /* ===== FSAudioPlayer 컨테이너 ===== */
        /* .audio-wrap 자체 스타일은 라인 213 (.player-wrap.audio-wrap) 참조 */
        #fs-audio-player {
            width: 100%;
            height: 100%;
            max-width: 100%;
            /* min-height는 부모(.player-wrap.audio-wrap)에서 관리 (다중 트랙 플레이리스트 스크롤 위해) */
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
                
                // 폴더 stream 공유: 폴더 안 미디어 트랙 자동 검색 (직속 파일만, audio + video)
                $isFolderStream = !empty($share['is_dir']) && $shareType === 'stream';
                $folderTracks = [];
                $folderAudioTracks = [];
                $folderVideoTracks = [];
                $isFolderAudioPlaylist = false;
                $isFolderVideoPlaylist = false;
                if ($isFolderStream) {
                    $folderTracks = $shareManager->getSharedFolderTracks($token, null) ?: [];
                    
                    // type 필드로 audio/video 분리
                    foreach ($folderTracks as $t) {
                        if (($t['type'] ?? '') === 'audio') $folderAudioTracks[] = $t;
                        elseif (($t['type'] ?? '') === 'video') $folderVideoTracks[] = $t;
                    }
                    
                    $audioCount = count($folderAudioTracks);
                    $videoCount = count($folderVideoTracks);
                    
                    // 다수 우선 + 동률 시 영상 우선 (펜닐님 결정)
                    if ($audioCount > 0 || $videoCount > 0) {
                        if ($videoCount >= $audioCount) {
                            // 영상 우선 (다수 또는 동률)
                            $isFolderVideoPlaylist = true;
                            $folderTracks = $folderVideoTracks;  // 화면에 표시할 트랙은 영상만
                        } else {
                            // 음악 다수
                            $isFolderAudioPlaylist = true;
                            $folderTracks = $folderAudioTracks;  // 화면에 표시할 트랙은 음악만
                        }
                    }
                } elseif ($pageSubFile !== null) {
                    // ★ 영상 sub-file 모드: 사이드 패널용 영상 트랙 목록 보존
                    //   라인 260 $share['is_dir'] = 0 처리로 $isFolderStream = false가 되어 트랙 데이터가
                    //   비워지므로, 사이드 패널 렌더링을 위해 영상 트랙 다시 채움
                    $_subAllTracks = $shareManager->getSharedFolderTracks($token, null) ?: [];
                    $folderTracks = array_values(array_filter($_subAllTracks, fn($t) => ($t['type'] ?? '') === 'video'));
                }
                
                /*
                // ★ 디버그 로그 (펜닐님 진단용 — 임시) — 주석처리됨 (다음 디버깅 위해 보존)
                $_folderDebugDir = __DIR__ . '/data/debug';
                if (!is_dir($_folderDebugDir)) @mkdir($_folderDebugDir, 0755, true);
                $_folderDebugFile = $_folderDebugDir . '/folder_stream_debug.log';
                @file_put_contents(
                    $_folderDebugFile,
                    '[' . date('Y-m-d H:i:s') . '] [SHARE-PHP] 진단 시작' . "\n" .
                    '  - filename: ' . $share['filename'] . "\n" .
                    '  - is_dir: ' . ($share['is_dir'] ?? 'NULL') . "\n" .
                    '  - shareType: ' . $shareType . "\n" .
                    '  - isFolderStream: ' . ($isFolderStream ? 'TRUE' : 'FALSE') . "\n" .
                    '  - folderTracks count: ' . count($folderTracks) . "\n" .
                    '  - isFolderAudioPlaylist: ' . ($isFolderAudioPlaylist ? 'TRUE' : 'FALSE') . "\n" .
                    '  - tracks: ' . json_encode(array_map(fn($t) => $t['file'] ?? '?', array_slice($folderTracks, 0, 5)), JSON_UNESCAPED_UNICODE) . "\n",
                    FILE_APPEND
                );
                */
                
                $isStreamable = (($isVideo || $isAudio) && $shareType === 'stream') || $isFolderAudioPlaylist || $isFolderVideoPlaylist;
                
                // 브라우저 네이티브 지원 포맷 (확장자 기반 1차 판단)
                $nativeVideo = ['mp4','webm','ogg'];
                $nativeAudio = ['mp3','wav','ogg','flac','m4a','aac','opus'];
                $needsTranscode = $isVideo && !in_array($ext, $nativeVideo);
                
                // ★ 디버그 로그 (펜닐님 진단용 — 비활성화: 함수 본체만 주석, 호출은 no-op)
                //   재활성 시 아래 본체 주석 해제하면 즉시 작동
                $_codecLog = function($msg) {
                    /*
                    $_dir = __DIR__ . '/data/debug';
                    if (!is_dir($_dir)) @mkdir($_dir, 0755, true);
                    @file_put_contents(
                        $_dir . '/codec_debug.log',
                        '[' . date('Y-m-d H:i:s') . '] [SHARE-PHP] ' . $msg . "\n",
                        FILE_APPEND
                    );
                    */
                };
                $_codecLog('=== 코덱 진단 시작 ===');
                $_codecLog('share filename: ' . ($share['filename'] ?? 'NULL'));
                $_codecLog('share is_dir: ' . ($share['is_dir'] ?? 'NULL'));
                $_codecLog('pageSubFile: ' . ($pageSubFile ?? 'NULL'));
                $_codecLog('ext: ' . $ext);
                $_codecLog('isVideo: ' . ($isVideo ? 'TRUE' : 'FALSE'));
                $_codecLog('isAudio: ' . ($isAudio ? 'TRUE' : 'FALSE'));
                $_codecLog('needsTranscode (초기): ' . ($needsTranscode ? 'TRUE' : 'FALSE'));
                $_codecLog('isVideo && !needsTranscode: ' . (($isVideo && !$needsTranscode) ? 'TRUE → ffprobe 진입' : 'FALSE → ffprobe 스킵'));
                // ============================================================
                
                // 네이티브 포맷이라도 코덱/크기 체크 (ffprobe)
                $videoCodec = '';
                $videoResolution = '';
                $fileSize = $share['size'] ?? 0;
                if ($isVideo && !$needsTranscode) {
                    // 실제 파일 경로 구하기
                    $_db = JsonDB::getInstance();
                    $_shareRec = $_db->find('shares', ['token' => $token]);
                    $_storageRec = $_shareRec ? $_db->find('storages', ['id' => $_shareRec['storage_id']]) : null;
                    $_codecLog('shareRec found: ' . ($_shareRec ? 'YES' : 'NO'));
                    $_codecLog('storageRec found: ' . ($_storageRec ? 'YES' : 'NO'));
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
                        $_codecLog('basePath: ' . $_bPath);
                        // ★ 폴더 stream + sub-file: 폴더 경로 + sub-file basename = 실제 영상 파일
                        if ($pageSubFile !== null) {
                            $_realFile = $_bPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $_shareRec['file_path']) . DIRECTORY_SEPARATOR . $pageSubFile;
                            $_codecLog('realFile (sub-file 모드): ' . $_realFile);
                        } else {
                            $_realFile = $_bPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $_shareRec['file_path']);
                            $_codecLog('realFile (단일): ' . $_realFile);
                        }
                        $_codecLog('file_exists: ' . (file_exists($_realFile) ? 'YES' : 'NO'));
                        
                        if (file_exists($_realFile)) {
                            $fileSize = filesize($_realFile) ?: $fileSize;
                            // ffprobe/ffmpeg 경로
                            $_settings = $_db->load('settings');
                            $_thumbSettings = $_settings['thumbnails'] ?? [];
                            $_probeBin = '';
                            $_ffprobePath = trim($_thumbSettings['ffprobe_path'] ?? '');
                            $_ffmpegPath = trim($_thumbSettings['ffmpeg_path'] ?? '');
                            $_codecLog('ffprobe_path 설정: ' . ($_ffprobePath ?: '(빈값)'));
                            $_codecLog('ffmpeg_path 설정: ' . ($_ffmpegPath ?: '(빈값)'));
                            if ($_ffprobePath && @is_executable($_ffprobePath)) $_probeBin = $_ffprobePath;
                            elseif ($_ffmpegPath && @is_executable($_ffmpegPath)) $_probeBin = $_ffmpegPath;
                            else { foreach (['ffprobe', 'ffmpeg'] as $_bin) { $out = @shell_exec("$_bin -version 2>&1"); if ($out && strpos($out, 'version') !== false) { $_probeBin = $_bin; break; } } }
                            $_codecLog('probeBin 선택: ' . ($_probeBin ?: '(없음 → ffprobe 스킵!)'));
                            
                            if ($_probeBin) {
                                $_probeOut = @shell_exec(escapeshellarg($_probeBin) . ' -i ' . escapeshellarg($_realFile) . ' 2>&1');
                                $_codecLog('probeOut 길이: ' . strlen((string)$_probeOut));
                                $_codecLog('probeOut 일부: ' . substr((string)$_probeOut, 0, 500));
                                if (preg_match('/Stream\s+#\d+:\d+.*Video:\s*(\w+)/i', $_probeOut, $_vm)) {
                                    $videoCodec = strtolower($_vm[1]);
                                    $_codecLog('videoCodec 추출: ' . $videoCodec);
                                } else {
                                    $_codecLog('videoCodec 추출 실패');
                                }
                                if (preg_match('/(\d{2,5})x(\d{2,5})/', $_probeOut, $_rm)) {
                                    $videoResolution = $_rm[1] . 'x' . $_rm[2];
                                }
                                // 코덱 미지원 → 트랜스코딩
                                $nativeCodecs = ['h264', 'vp8', 'vp9', 'av1'];
                                $_codecLog('videoCodec in nativeCodecs: ' . (in_array($videoCodec, $nativeCodecs) ? 'YES (네이티브 재생)' : 'NO (트랜스코드)'));
                                if ($videoCodec && !in_array($videoCodec, $nativeCodecs)) {
                                    $needsTranscode = true;
                                    $_codecLog('needsTranscode 설정: TRUE');
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
                $_codecLog('=== 최종 needsTranscode: ' . ($needsTranscode ? 'TRUE' : 'FALSE') . ' ===');
                
                // ★ 비번 URL 비포함: 라인 31의 $password = $_POST['password']로 GET의 password는 읽지 않음
                //   (dead code 제거) + 비로그인 사용자 환경에서 프록시/로그 노출 방지
                //   세션 인증($_SESSION['share_authenticated'][$token])으로 audio/video 모두 통과
                $streamUrl = "share.php?t=" . urlencode($token) . "&download=1&stream=1";
                // ★ 폴더 stream + sub-file: 트랙별 streamUrl
                if ($pageSubFile !== null) {
                    $streamUrl .= "&file=" . urlencode($pageSubFile);
                }
                
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
                            // ★ 폴더 stream + sub-file: 폴더 안 sub-file 기준으로 자막 검색
                            if ($pageSubFile !== null) {
                                // 폴더 = $shareRecord['file_path'], sub-file = basename
                                $dir = $filePath;  // 폴더 자체가 검색 디렉토리
                                $videoBase = strtolower(pathinfo($pageSubFile, PATHINFO_FILENAME));
                            } else {
                                $dir = dirname($filePath);
                                $videoBase = strtolower(pathinfo($filePath, PATHINFO_FILENAME));
                            }
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
                <svg id="share-header-icon" width="56" height="56" viewBox="0 0 56 56" fill="none">
                    <rect width="56" height="56" rx="14" fill="linear-gradient(135deg, #667eea 0%, #764ba2 100%)"/>
                    <defs><linearGradient id="g1" x1="0" y1="0" x2="56" y2="56"><stop offset="0%" stop-color="#667eea"/><stop offset="100%" stop-color="#764ba2"/></linearGradient></defs>
                    <rect width="56" height="56" rx="14" fill="url(#g1)"/>
                    <?php if ($isAudio || $isFolderAudioPlaylist): ?>
                    <!-- 음표 ♪ 아이콘 (음악 stream 케이스) -->
                    <path d="M37 14V32.5C37 35 35 37 32.5 37C30 37 28 35 28 32.5C28 30 30 28 32.5 28C33.4 28 34.3 28.3 35 28.7V20L25 22V36.5C25 39 23 41 20.5 41C18 41 16 39 16 36.5C16 34 18 32 20.5 32C21.4 32 22.3 32.3 23 32.7V19L37 16V14Z" fill="white"/>
                    <?php else: ?>
                    <!-- 재생 ▶ 아이콘 (동영상 stream 등 그 외 케이스) -->
                    <path d="M22 17L42 28L22 39V17Z" fill="white"/>
                    <?php endif; ?>
                </svg>
            </div>
            <?php if (!$isFolderAudioPlaylist): ?>
            <h1><?= htmlspecialchars($share['filename']) ?></h1>
            <?php endif; ?>
            
            <?php if ($pageSubFile !== null && $folderTrackTotal !== null): ?>
            <!-- 폴더 stream sub-file: 트랙 네비게이션 -->
            <div style="margin:8px 0 12px;display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;">
                <?php if ($folderTrackPrev): ?>
                    <a href="<?= htmlspecialchars($folderTrackPrev) ?>" style="color:#667eea;text-decoration:none;font-size:13px;padding:4px 10px;border:1px solid rgba(102,126,234,0.4);border-radius:4px;">◀ <?= __('prev_track', '이전') ?></a>
                <?php else: ?>
                    <span style="color:#888;font-size:13px;padding:4px 10px;border:1px solid rgba(255,255,255,0.1);border-radius:4px;">◀ <?= __('prev_track', '이전') ?></span>
                <?php endif; ?>
                <span style="color:#aaa;font-size:13px;padding:4px 10px;">📋 <?= htmlspecialchars((string)$folderTrackIndex) ?> / <?= htmlspecialchars((string)$folderTrackTotal) ?></span>
                <?php if ($folderTrackNext): ?>
                    <a href="<?= htmlspecialchars($folderTrackNext) ?>" style="color:#667eea;text-decoration:none;font-size:13px;padding:4px 10px;border:1px solid rgba(102,126,234,0.4);border-radius:4px;"><?= __('next_track', '다음') ?> ▶</a>
                <?php else: ?>
                    <span style="color:#888;font-size:13px;padding:4px 10px;border:1px solid rgba(255,255,255,0.1);border-radius:4px;"><?= __('next_track', '다음') ?> ▶</span>
                <?php endif; ?>
                <button type="button" id="share-pl-toggle-btn" class="share-pl-toggle" title="<?= __('playlist_toggle', '목록 열기/닫기') ?>">📋 <?= __('playlist', '목록') ?></button>
            </div>
            <?php endif; ?>
            
            <div class="info">
                <div>📦 <?= formatFileSize($share['size'] ?? 0) ?> · 📅 <?= htmlspecialchars(date('Y-m-d H:i', strtotime($share['created_at']))) ?><?php if ($share['expire_at']): ?> · ⏰ ~<?= htmlspecialchars(date('Y-m-d', strtotime($share['expire_at']))) ?><?php endif; ?></div>
            </div>
            
            <?php if ($isFolderAudioPlaylist): ?>
            <!-- 폴더 stream 공유: 폴더 안 음악 다중 트랙 플레이리스트 -->
            <?php
                /* @file_put_contents(__DIR__ . '/data/debug/folder_stream_debug.log',
                    '[' . date('Y-m-d H:i:s') . '] [SHARE-PHP] ★ 폴더 stream 분기 진입 (라인 732)' . "\n",
                    FILE_APPEND); */
                // 폴더 스트림 모드 — streamUrl은 sub-file 추가해서 트랙별 생성
                // (하나의 단일 streamUrl 변수 대신 트랙별로 동적 생성)
                $folderStreamBaseUrl = "share.php?t=" . urlencode($token) . "&download=1&stream=1";
            ?>
            <link rel="stylesheet" href="assets/css/fs-audio-player.css?v=<?= APP_VERSION ?>">
            <div class="player-wrap audio-wrap">
                <div id="fs-audio-player"></div>
                <audio id="stream-player" preload="metadata" style="display:none;"></audio>
            </div>
            
            <?php elseif ($isFolderVideoPlaylist): ?>
            <!-- 펜닐님 결정 (옵션 A): 영상 폴더 트랙 목록 페이지 제거 — 사이드 패널로 대체 -->
            <!-- 이 분기는 첫 영상 트랙 검증 실패 시 fallback (정상 케이스에선 자동 첫 트랙 선택됨) -->
            <div class="folder-video-list" style="background:#1e1e2e;color:#ccc;border-radius:12px;padding:24px;text-align:center;">
                <div style="font-size:48px;margin-bottom:12px;">⚠️</div>
                <div style="color:#fff;font-size:16px;font-weight:600;margin-bottom:8px;"><?= __('share_video_folder', '동영상 폴더') ?></div>
                <div style="color:#888;font-size:13px;"><?= __('share_no_playable_track', '재생 가능한 트랙이 없습니다') ?></div>
            </div>
            
            <?php elseif ($isVideo): ?>
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
                <!-- 시킹 오버레이 (-5/+5초 시각 피드백, 메인 패턴 동일) -->
                <div class="video-seek-overlay video-seek-left">-5</div>
                <div class="video-seek-overlay video-seek-right">+5</div>
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
                <?php /* 공유 자막 파일 업로드 (펜닐님 요청으로 주석처리)
                <label class="ctrl-btn sub-file-label" title="<?= __('sub_load', '자막 파일 불러오기') ?>">
                    📝 <?= __('subtitle', '자막') ?>
                    <input type="file" id="sub-file-input" accept=".srt,.vtt,.smi,.sami,.ass,.ssa">
                </label>
                */ ?>
                <button class="ctrl-btn" id="btn-pip" title="PIP">🖼️ PIP</button>
                <button class="ctrl-btn" id="btn-fullscreen" title="<?= __('fullscreen', '전체화면') ?>">⛶</button>
            </div>
            <?php else: ?>
            <!-- FSAudioPlayer (메인 미리보기와 동일 — 풍부한 UI) -->
            <?php
                // ★ 보안: 비밀번호는 URL에 노출하지 않음 (서버 로그/Referer 누출 방지)
                //   페이지 렌더링 시점엔 이미 $_SESSION['share_authenticated'][$token] = true 상태이므로
                //   cover=1 호출 시 accessShare가 세션 플래그로 인증 통과 (메인의 audio_cover 패턴과 동일)
                $coverUrl = "share.php?t=" . urlencode($token) . "&cover=1";
            ?>
            <link rel="stylesheet" href="assets/css/fs-audio-player.css?v=<?= APP_VERSION ?>">
            <div class="player-wrap audio-wrap">
                <div id="fs-audio-player"></div>
                <!-- 메인과 호환을 위한 player 변수 — JS의 player 참조 안전 -->
                <audio preload="metadata" id="stream-player" style="display:none;">
                    <source src="<?= htmlspecialchars($streamUrl) ?>" type="audio/mpeg">
                </audio>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <!-- 다운로드 모드 -->
            <?php
                /* @file_put_contents(__DIR__ . '/data/debug/folder_stream_debug.log',
                    '[' . date('Y-m-d H:i:s') . '] [SHARE-PHP] ★ 다운로드 모드 진입 (라인 854) - isStreamable=' . ($isStreamable ? 'TRUE' : 'FALSE') . "\n",
                    FILE_APPEND); */
            ?>
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

        <?php if ($pageSubFile !== null && $folderTrackTotal !== null && !empty($folderTracks)): ?>
        <!-- 폴더 stream sub-file: 사이드 플레이리스트 패널 (팟플레이어 스타일)
             container 안 absolute로 배치 → 영상 위치 흔들림 X -->
        <aside class="share-playlist-panel" id="share-playlist-panel" aria-hidden="true">
            <div class="share-playlist-header">
                <span class="pl-icon">🎬</span>
                <span class="pl-title"><?= __('share_video_folder', '동영상 폴더') ?></span>
                <span class="pl-count"><?= count($folderTracks) ?><?= __('tracks_unit', '개 트랙') ?></span>
                <button type="button" class="pl-close" id="share-pl-close" title="<?= __('close', '닫기') ?>">✕</button>
            </div>
            <div class="share-playlist-body" id="share-pl-body">
            <?php foreach ($folderTracks as $i => $t): ?>
                <?php $isCurrent = ($t['file'] === $pageSubFile); ?>
                <a class="share-pl-item<?= $isCurrent ? ' current' : '' ?>"
                   href="share.php?t=<?= urlencode($token) ?>&file=<?= urlencode($t['file']) ?>"
                   data-track-index="<?= $i + 1 ?>"
                   <?= $isCurrent ? 'data-current="1"' : '' ?>>
                    <span class="pl-num"><?= $i + 1 ?>.</span>
                    <span class="pl-name" title="<?= htmlspecialchars($t['name']) ?>"><span class="pl-name-inner"><?= htmlspecialchars($t['name']) ?></span></span>
                    <span class="pl-meta"><?= htmlspecialchars(strtoupper($t['ext'])) ?> · <?= formatFileSize($t['size']) ?></span>
                    <span class="pl-play-icon"><?= $isCurrent ? '▶' : '·' ?></span>
                </a>
            <?php endforeach; ?>
            </div>
        </aside>
        <?php endif; ?>
    </div>
    
    <div class="toast" id="toast"></div>

    <?php /* 사이드 패널은 container 안으로 이동됨 (absolute 기준) */ ?>
    
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
    <?php if (!empty($isAudio) || !empty($isFolderAudioPlaylist)): ?>
    <!-- FSAudioPlayer (메인 미리보기와 동일 클래스, 별도 파일로 분리됨) -->
    <!-- 단일 audio 파일 OR 폴더 stream(다중 트랙) 모두 필요 -->
    <script src="assets/js/fs-audio-player.js?v=<?= APP_VERSION ?>"></script>
    <?php endif; ?>
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
                                // ★ bufferAppendError / manifestLoadError → SW fallback 트리거 (멀티오디오 영상 회귀 대응)
                                //   메인 인덱스의 _hlsSwRetried 메커니즘과 동일 — 분석 기반, 강제 아님
                                //   - HW 인코더가 m3u8/segment 정상 생성했지만 클라이언트 디코더 실패 (EAC3 등)
                                //   - 또는 manifestLoadError로 m3u8 로드 자체 실패
                                //   - 이 세션 stop 후 force_sw=1로 재시작
                                const isManifestErr = (data.details === 'manifestLoadError' || data.details === 'manifestLoadTimeOut' || data.details === 'manifestParsingError');
                                const isBufferAppendErr = (data.details === 'bufferAppendError');
                                const is404 = data.response && (data.response.code === 404 || data.response.code === 410);
                                if ((isManifestErr || isBufferAppendErr || is404) && !hlsStartUrl.includes('force_sw=1') && !window._shareHlsSwRetried) {
                                    window._shareHlsSwRetried = true;
                                    hls.destroy();
                                    _shareHlsInstance = null;
                                    // 기존 HW 세션 stop (서버 ffmpeg 종료 — _shareHlsSession 초기화 전에)
                                    try {
                                        if (_shareHlsSession) {
                                            const stopUrl = hlsStartUrl.replace('hls_action=start', 'hls_action=stop') + '&session=' + encodeURIComponent(_shareHlsSession);
                                            fetch(stopUrl, { keepalive: true }).catch(() => {});
                                        }
                                    } catch(e) {}
                                    _shareHlsSession = null;
                                    const swBadge = document.getElementById('stream-badge');
                                    if (swBadge) swBadge.innerHTML = '⚡ HW <?= __('failed_sw_retry', '실패 — SW 재시도 중...') ?>';
                                    // SW 재시작 (비동기)
                                    (async () => {
                                        try {
                                            const swUrl = hlsStartUrl + '&force_sw=1';
                                            const swRes = await fetch(swUrl);
                                            const swCt = swRes.headers.get('content-type') || '';
                                            if (swCt.includes('json')) {
                                                const swData = await swRes.json();
                                                if (swData.success && swData.playlist) {
                                                    if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                                                        const swHls = new Hls({ maxBufferLength: 30, maxMaxBufferLength: 60 });
                                                        swHls.loadSource(swData.playlist);
                                                        swHls.attachMedia(player);
                                                        swHls.on(Hls.Events.MANIFEST_PARSED, () => {
                                                            if (swBadge) swBadge.innerHTML = '⚡ HLS <?= __('streaming', '스트리밍') ?> <span class="encoder-info">SW : CPU</span>';
                                                            if (_userRequestedPlay) player.play().catch(() => {});
                                                        });
                                                        swHls.on(Hls.Events.ERROR, (e, d) => {
                                                            if (d.fatal) { swHls.destroy(); _shareHlsInstance = null; _shareHlsSession = null; _fallbackToMMS(transcodeUrl); }
                                                        });
                                                        _shareHlsSession = swData.session;
                                                        _shareHlsInstance = swHls;
                                                        return;
                                                    } else if (player.canPlayType('application/vnd.apple.mpegurl')) {
                                                        player.src = swData.playlist;
                                                        player.load();
                                                        if (swBadge) swBadge.innerHTML = '⚡ HLS <?= __('streaming', '스트리밍') ?> <span class="encoder-info">SW : CPU</span>';
                                                        if (_userRequestedPlay) player.play().catch(() => {});
                                                        _shareHlsSession = swData.session;
                                                        return;
                                                    }
                                                }
                                            }
                                            // SW 재시도도 실패 → MMS fallback
                                            _fallbackToMMS(transcodeUrl);
                                        } catch(swErr) {
                                            _fallbackToMMS(transcodeUrl);
                                        }
                                    })();
                                    return;
                                }
                                
                                // 그 외 fatal 에러는 MMS fallback (기존 동작)
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
                        
                        // ★ iOS 네이티브 HLS — video.error → SW fallback (멀티오디오 EAC3 등 회귀 대응)
                        //   PC hls.js의 ERROR 이벤트와 동등 메커니즘
                        player.addEventListener('error', async function _shareIosHlsErrorHandler() {
                            player.removeEventListener('error', _shareIosHlsErrorHandler);
                            if (window._shareHlsSwRetried) return;
                            window._shareHlsSwRetried = true;
                            
                            const eb = document.getElementById('stream-badge');
                            if (eb) eb.textContent = '⚡ HW 실패 — SW 재시도 중...';
                            
                            try { player.pause(); } catch(e) {}
                            player.removeAttribute('src');
                            player.load();
                            
                            try {
                                const swUrl = hlsStartUrl + '&force_sw=1';
                                const swRes = await fetch(swUrl);
                                const swCt = swRes.headers.get('content-type') || '';
                                if (!swCt.includes('json')) {
                                    if (eb) eb.textContent = '❌ SW 재시작 실패';
                                    return;
                                }
                                const swData = await swRes.json();
                                if (!swData.success || !swData.playlist) {
                                    if (eb) eb.textContent = '❌ SW 재시작 실패';
                                    return;
                                }
                                
                                player.src = swData.playlist;
                                player.load();
                                if (eb) eb.innerHTML = '⚡ HLS <?= __('streaming', '스트리밍') ?> <span class="encoder-info">SW : CPU</span>';
                                if (_userRequestedPlay) player.play().catch(() => {});
                                _shareHlsSession = swData.session;
                                
                                // ★ SW도 실패 시 MMS fallback (PC와 일관성)
                                player.addEventListener('error', function _shareIosSwErrorHandler() {
                                    player.removeEventListener('error', _shareIosSwErrorHandler);
                                    _fallbackToMMS(transcodeUrl);
                                }, { once: true });
                            } catch(swErr) {
                                _fallbackToMMS(transcodeUrl);
                            }
                        }, { once: false });
                        
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
            const e = player.error;
            
            // ★ 네이티브 재생 video.error → 트랜스코딩 자동 전환 (펜닐님 결정 — 메인 인덱스와 일관성)
            //   PC 동영상 플레이어 (팟플레이어/곰플레이어) 수준 호환성:
            //   - 사용자가 트랜스코딩 모드로 시작한 경우는 제외 (loaded 후이지만 src에 따라 판단)
            //   - 이미 트랜스코딩 모드면 무시 (_switchingToTranscode)
            //   - ABORTED (e.code === 1) 또는 'Empty src'는 사용자 의도이므로 제외
            if (e && e.code !== 1 && !(e.message && e.message.includes('Empty src')) && !player._switchingToTranscode) {
                // 이미 HLS/MMS 모드면 (data-hls-url 사용 중) 트랜스코딩 자동 전환 무의미
                //   → 이 경우 fallback은 _fallbackToMMS에서 처리됨
                // 네이티브 재생 시도 후 실패한 경우만 startTranscode 재호출
                const isHlsMode = !!_shareHlsSession || !!_shareHlsInstance;
                const isMmsMode = player.srcObject !== null && typeof MediaSource !== 'undefined';
                if (!isHlsMode && !isMmsMode) {
                    // 네이티브 재생 실패 → 트랜스코딩 강제 시작
                    player._switchingToTranscode = true;
                    if (status) {
                        status.innerHTML = '⚡ <?= __("native_failed_transcode", "네이티브 재생 실패 — 트랜스코딩 전환 중...") ?>';
                        status.classList.add('active');
                    }
                    // loaded 플래그 리셋 → startTranscode 재실행 가능
                    loaded = false;
                    try {
                        player.pause();
                        player.removeAttribute('src');
                        player.querySelectorAll('source').forEach(s => s.remove());
                        player.load();
                    } catch(err) {}
                    setTimeout(() => startTranscode(), 100);
                    return;
                }
            }
            
            // 그 외 케이스 → 기존 에러 메시지 표시
            if (status) {
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
                    // ★ 멀티오디오 자동 SW 강제 (메인 페이지와 동일 패턴 — 펜닐님 결정)
                    //   - HW 인코더 + EAC3 5.1 호환 문제 (EAC3 5.1 등 멀티오디오 코덱)
                    //   - 메인 페이지는 info → multiAudioSwForced 결정 후 hlsStartUrl 호출
                    //   - 공유 페이지는 hlsStartUrl 먼저 발송 후 info 호출 → 이미 HW 세션 시작됨
                    //   - 해결: 멀티오디오 검출 + 현재 HW 세션이면 stop 후 force_sw=1로 재시작
                    if (!window._shareMultiAudioSwForced && _shareHlsSession && !window._shareHlsSwRetried) {
                        window._shareMultiAudioSwForced = true;
                        // 현재 HW 세션이면 SW로 재시작 (배지가 SW 아님 = HW 사용 중)
                        const _curBadge = document.getElementById('stream-badge');
                        const _curEnc = _curBadge ? _curBadge.querySelector('.encoder-info') : null;
                        const _isCurrentlySw = _curEnc && _curEnc.textContent.startsWith('SW');
                        if (!_isCurrentlySw) {
                            // 기존 HW 세션 stop
                            const _oldSession = _shareHlsSession;
                            const _token = <?= json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                            const _oldStopUrl = 'share.php?t=' + encodeURIComponent(_token) + '&download=1&stream=1&hls=1&hls_action=stop&session=' + _oldSession;
                            try {
                                if (navigator.sendBeacon) navigator.sendBeacon(_oldStopUrl);
                                fetch(_oldStopUrl, { keepalive: true }).catch(() => {});
                            } catch(e) {}
                            
                            // 기존 HLS 인스턴스 destroy
                            if (_shareHlsInstance) {
                                try { _shareHlsInstance.destroy(); } catch(e) {}
                                _shareHlsInstance = null;
                            }
                            _shareHlsSession = null;
                            
                            // 배지 즉시 SW로 갱신
                            if (_curBadge) {
                                _curBadge.innerHTML = `⏳ <?= __('realtime_converting', '실시간 변환 중...') ?> <span class="encoder-info">SW : CPU</span>`;
                            }
                            
                            // force_sw=1로 재시작
                            const _hlsStartUrl = player.dataset.hlsUrl;
                            const _swStartUrl = _hlsStartUrl + (_hlsStartUrl.includes('force_sw=1') ? '' : '&force_sw=1');
                            
                            (async () => {
                                try {
                                    const _swRes = await fetch(_swStartUrl);
                                    const _swData = await _swRes.json();
                                    if (_swData.success && _swData.playlist) {
                                        _shareHlsSession = _swData.session;
                                        // hls.js 재생성
                                        if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                                            const _newHls = new Hls({
                                                manifestLoadingMaxRetry: 4,
                                                manifestLoadingRetryDelay: 1000,
                                                fragLoadingMaxRetry: 6,
                                                fragLoadingRetryDelay: 500
                                            });
                                            _newHls.loadSource(_swData.playlist);
                                            _newHls.attachMedia(player);
                                            _newHls.on(Hls.Events.MANIFEST_PARSED, () => {
                                                player.play().catch(() => {});
                                                if (_curBadge) {
                                                    _curBadge.innerHTML = `⚡ HLS <?= __('streaming', '스트리밍') ?> <span class="encoder-info">SW : CPU</span>`;
                                                }
                                            });
                                            _shareHlsInstance = _newHls;
                                        } else if (player.canPlayType('application/vnd.apple.mpegurl')) {
                                            player.src = _swData.playlist;
                                            player.play().catch(() => {});
                                        }
                                    }
                                } catch(e) {}
                            })();
                        }
                    }
                    
                    const wrap = document.getElementById('share-audio-track-wrap');
                    if (wrap) {
                        wrap.style.display = '';
                        // ★ 메인 _buildAudioTrackUI와 동일 패턴 (펜닐님 결정 — 메인+공유 일관)
                        //   본질: _fetchShareInfo가 startTranscode 재호출 시 두 번 실행 → appendChild 누적으로 옵션 2배
                        //   해결: select 통째 innerHTML 교체 → 이전 옵션/change 리스너 모두 제거
                        const _curHlsUrl = player.dataset.hlsUrl || '';
                        const _curAudio = (() => {
                            try {
                                const m = _curHlsUrl.match(/[?&]audio=(\d+)/);
                                return m ? m[1] : null;
                            } catch (e) { return null; }
                        })();
                        // label만 그대로 두고 select만 새로 생성 (이전 select와 그 change 리스너 제거됨)
                        const _selOpts = info.audio_tracks.map((t, i) => {
                            const _label = [t.language, t.codec, t.channels, t.title].filter(Boolean).join(' · ') || ('Track ' + (i+1));
                            const _isSelected = _curAudio ? (String(t.index) === String(_curAudio)) : (i === 0);
                            return `<option value="${t.index}"${_isSelected ? ' selected' : ''}>${_label.replace(/[<>"']/g, c => ({'<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}</option>`;
                        }).join('');
                        wrap.innerHTML = `<label style="color:#fff;font-size:12px;text-shadow:0 0 3px #000;">🔊 <?= __('audio_label', '오디오') ?>:
                            <select id="share-audio-select" style="font-size:12px;max-width:220px;">${_selOpts}</select>
                        </label>`;
                        const sel = document.getElementById('share-audio-select');
                        if (sel) {
                            sel.addEventListener('change', () => {
                            sel.blur();
                            
                            // ★ audio 변경 시 메인 인덱스와 동일 패턴 적용 (펜닐님 결정 — 일관성)
                            //   1. 기존 HLS 인스턴스 destroy (두 모드 충돌 → bufferAppendError 방지)
                            //   2. HLS 서버 세션 stop (서버 세션 누적 방지)
                            //   3. _shareHlsSwRetried 플래그 리셋 (새 트랙은 새 세션)
                            //   4. transcodeUrl에서 기존 audio 파라미터 제거 후 새로 추가
                            
                            // 기존 HLS 인스턴스 정리
                            if (_shareHlsInstance) {
                                try { _shareHlsInstance.destroy(); } catch(e) {}
                                _shareHlsInstance = null;
                            }
                            // HLS 서버 세션 stop
                            if (_shareHlsSession) {
                                const token = <?= json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                                const stopUrl = 'share.php?t=' + encodeURIComponent(token) + '&download=1&stream=1&hls=1&hls_action=stop&session=' + _shareHlsSession;
                                try {
                                    if (navigator.sendBeacon) {
                                        navigator.sendBeacon(stopUrl);
                                    } else {
                                        fetch(stopUrl, { keepalive: true }).catch(() => {});
                                    }
                                } catch(e) {}
                                _shareHlsSession = null;
                            }
                            // SW retry 플래그 리셋 — 새 트랙도 SW fallback 가능하게
                            window._shareHlsSwRetried = false;
                            
                            // ★ 멀티오디오 audio 변경: HLS 흐름 유지 (메인 PC 분기와 동일 — 펜닐님 결정)
                            //   - 이전: player.src = newUrl (MMS 모드 변경) → HLS 폴더 안 씀
                            //   - 변경: 새 hlsStartUrl + audio=N + force_sw=1 → 새 HLS 세션
                            //   - 이유: 멀티오디오는 SW 인코딩 필요 (EAC3 5.1 호환 문제)
                            const _origHlsUrl = player.dataset.hlsUrl;
                            const _cleanHlsUrl = _origHlsUrl.replace(/&audio=\d+/g, '').replace(/&force_sw=1/g, '');
                            const _newHlsStartUrl = _cleanHlsUrl + '&audio=' + sel.value + '&force_sw=1';
                            
                            // 비디오 정리
                            try { player.pause(); } catch(e) {}
                            player.removeAttribute('src');
                            player.load();
                            
                            // 배지 즉시 SW로
                            const _changeBadge = document.getElementById('stream-badge');
                            if (_changeBadge) {
                                _changeBadge.innerHTML = `⏳ <?= __('realtime_converting', '실시간 변환 중...') ?> <span class="encoder-info">SW : CPU</span>`;
                            }
                            
                            // 새 HLS 세션 시작 (force_sw=1 + audio=N)
                            (async () => {
                                try {
                                    const _newRes = await fetch(_newHlsStartUrl);
                                    const _newData = await _newRes.json();
                                    if (_newData.success && _newData.playlist) {
                                        _shareHlsSession = _newData.session;
                                        if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                                            const _changeHls = new Hls({
                                                manifestLoadingMaxRetry: 4,
                                                manifestLoadingRetryDelay: 1000,
                                                fragLoadingMaxRetry: 6,
                                                fragLoadingRetryDelay: 500
                                            });
                                            _changeHls.loadSource(_newData.playlist);
                                            _changeHls.attachMedia(player);
                                            _changeHls.on(Hls.Events.MANIFEST_PARSED, () => {
                                                player.play().catch(() => {});
                                                if (_changeBadge) {
                                                    _changeBadge.innerHTML = `⚡ HLS <?= __('streaming', '스트리밍') ?> <span class="encoder-info">SW : CPU</span>`;
                                                }
                                            });
                                            _shareHlsInstance = _changeHls;
                                        } else if (player.canPlayType('application/vnd.apple.mpegurl')) {
                                            player.src = _newData.playlist;
                                            player.play().catch(() => {});
                                        }
                                    } else {
                                        // HLS 실패 시 MMS fallback (기존 동작)
                                        const cleanUrl = transcodeUrl.replace(/&audio=\d+/g, '');
                                        const newUrl = cleanUrl + '&audio=' + sel.value;
                                        player.src = newUrl;
                                        player.load();
                                        player.play().catch(() => {});
                                    }
                                } catch(e) {
                                    // 네트워크 실패 시 MMS fallback
                                    const cleanUrl = transcodeUrl.replace(/&audio=\d+/g, '');
                                    const newUrl = cleanUrl + '&audio=' + sel.value;
                                    player.src = newUrl;
                                    player.load();
                                    player.play().catch(() => {});
                                }
                            })();
                        });
                        }
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
        
        // === 비디오 키보드 단축키 (메인 modal-preview 패턴 동일) ===
        // Space: 재생/일시정지, ←/→: 5초 이동 + 시킹 오버레이 시각 피드백
        // 페이지 로드 시 자동 작동 (포커스 불필요) — 메인은 모달 떠있을 때 자동 작동, 공유는 페이지 자체가 video 환경
        if (player.tagName === 'VIDEO') {
            // ★ 폴더 stream sub-file: 영상 종료 시 자동 다음 영상 페이지 이동
            <?php if ($pageSubFile !== null && $folderTrackNext !== null): ?>
            const _folderNextUrl = <?= json_encode($folderTrackNext) ?>;
            player.addEventListener('ended', () => {
                // 약간 딜레이 후 이동 (마지막 프레임 보고 자연스러운 전환)
                setTimeout(() => { location.href = _folderNextUrl; }, 1000);
            });
            <?php endif; ?>
            
            const shareVideoKeyHandler = (e) => {
                // input/textarea 안에서는 무시 (자막 검색 등)
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                if (e.target.isContentEditable) return;
                
                // ←→: 5초 이동 + 시킹 오버레이 시각 피드백 (메인 패턴 동일)
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    const seekAmount = e.key === 'ArrowLeft' ? -5 : 5;
                    player.currentTime = Math.max(0, Math.min(player.duration || 0, player.currentTime + seekAmount));
                    
                    // 오버레이 피드백 표시
                    const overlay = document.querySelector(e.key === 'ArrowLeft' ? '.video-seek-left' : '.video-seek-right');
                    if (overlay) {
                        overlay.classList.remove('show');
                        void overlay.offsetWidth;  // reflow 트리거 → 애니메이션 재시작
                        overlay.classList.add('show');
                    }
                    e.preventDefault();
                    return;
                }
                
                // Space: 재생/일시정지
                if (e.key === ' ' || e.code === 'Space') {
                    if (player.paused || player.ended) {
                        player.play().catch(() => {});
                    } else {
                        player.pause();
                    }
                    e.preventDefault();
                    return;
                }
            };
            document.addEventListener('keydown', shareVideoKeyHandler);
            
            // 페이지 unload 시 정리
            window.addEventListener('beforeunload', () => {
                document.removeEventListener('keydown', shareVideoKeyHandler);
            }, { once: true });
        }
        
        // === FSAudioPlayer 초기화 (audio일 때만) ===
        // 메인 미리보기와 동일한 풍부한 UI: 커버 아트, 시킹바, 볼륨, 비주얼라이저, 셔플/반복, 키보드 단축키
        
        // ★ 디버그 (펜닐님 진단용 — 임시) — 비활성화됨 (다음 디버깅 시 본체 코드 활성화)
        const _fsDbg = (msg, data) => {
            // 디버그 비활성화: 아래 줄들의 주석 해제하면 활성화됨
            /*
            try { console.log('[FolderStreamDebug]', msg, data || ''); } catch(e) {}
            try {
                const fd = new FormData();
                fd.append('msg', '[CLIENT-JS] ' + msg);
                if (data !== undefined) fd.append('data', typeof data === 'object' ? JSON.stringify(data) : String(data));
                fetch('share.php?t=<?= urlencode($token) ?>&debug_log=1', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(()=>{});
            } catch(e) {}
            */
        };
        _fsDbg('FSAudioPlayer 초기화 진입 점검', {
            playerTagName: player.tagName,
            playerExists: !!player,
            fsAudioPlayerDivExists: !!document.getElementById('fs-audio-player'),
            FSAudioPlayerDefined: typeof FSAudioPlayer !== 'undefined',
            FSAudioPlayerType: typeof FSAudioPlayer
        });
        
        if (player.tagName === 'AUDIO' && document.getElementById('fs-audio-player') && typeof FSAudioPlayer !== 'undefined') {
            try {
                <?php if ($isFolderAudioPlaylist): ?>
                _fsDbg('★ 폴더 stream 모드 진입 (다중 트랙)');
                // === 폴더 stream 모드: 다중 트랙 플레이리스트 ===
                // 서버측 ShareManager::getSharedFolderTracks가 보안 검증 + 음악만 추출
                // 각 트랙별 streamUrl/coverUrl/lyricsApiUrl는 sub-file 기반 (basename 화이트리스트 검증)
                const folderTracks = <?= json_encode($folderTracks, JSON_UNESCAPED_UNICODE) ?>;
                const folderStreamBase = "share.php?t=<?= urlencode($token) ?>&download=1&stream=1";
                const folderCoverBase = "share.php?t=<?= urlencode($token) ?>&cover=1";
                const folderLyricsBase = "share.php?t=<?= urlencode($token) ?>&lyrics=1";
                
                _fsDbg('folderTracks parsed', { count: folderTracks.length, first: folderTracks[0] });
                
                const playlist = folderTracks.map(t => ({
                    name: t.name,
                    fullName: t.file,
                    ext: t.ext,
                    path: t.file,
                    url: folderStreamBase + "&file=" + encodeURIComponent(t.file),
                    // ★ HTTP 캐시 무효화: mtime 추가 — 같은 트랙 두 번째 요청 시 브라우저 캐시 적중
                    coverApiUrl: t.ext === 'mp3' ? (folderCoverBase + "&file=" + encodeURIComponent(t.file) + "&v=" + (t.mtime || 0)) : null,
                    lyricsApiUrl: folderLyricsBase + "&file=" + encodeURIComponent(t.file) + "&v=" + (t.mtime || 0),
                }));
                
                _fsDbg('playlist mapped', { count: playlist.length, firstUrl: playlist[0]?.url });
                
                _fsDbg('FSAudioPlayer 인스턴스화 시작');
                const sharePlayer = new FSAudioPlayer({
                    container: document.getElementById('fs-audio-player'),
                    playlist: playlist,
                    startIndex: 0,
                    volume: 0.8,
                    loop: 'all',  // 다중 트랙은 전체 반복 (메인 패턴 동일)
                    cover: '',
                });
                _fsDbg('FSAudioPlayer 인스턴스화 완료', { instance: !!sharePlayer });
                
                // 공유 폴더 stream에서 fileSize는 합산 (메타 정보용)
                const fileSize = playlist.reduce((sum, t) => sum + (folderTracks.find(ft => ft.file === t.fullName)?.size || 0), 0);
                <?php else: ?>
                // === 단일 파일 stream 모드 ===
                const filename = <?= json_encode($share['filename']) ?>;
                const ext = filename.split('.').pop().toLowerCase();
                const coverApiUrl = <?= json_encode($coverUrl) ?>;
                const streamUrl = <?= json_encode($streamUrl) ?>;
                
                // 공유 가사 URL — 비번 URL 비포함 (세션 인증으로 통과, getShareCover 패턴 동일)
                // 서버측 ShareManager::getShareLyrics가 LRC > USLT > TXT 우선순위로 응답
                // (mp3 외 포맷도 LRC/TXT는 서버측에서 같은 폴더 검색 가능 → ext 제한 없음)
                const lyricsApiUrl = "share.php?t=<?= urlencode($token) ?>&lyrics=1";
                
                // 단일 트랙 플레이리스트 구성 (FSAudioPlayer는 playlist 기반)
                const sharePlayer = new FSAudioPlayer({
                    container: document.getElementById('fs-audio-player'),
                    playlist: [{
                        name: filename.replace(/\.[^.]+$/, ''),
                        fullName: filename,
                        ext: ext,
                        path: filename,
                        url: streamUrl,
                        // ID3 커버 — mp3일 때만 (다른 포맷은 음악 아이콘 fallback)
                        coverApiUrl: ext === 'mp3' ? coverApiUrl : null,
                        // 가사 URL — LRC > USLT > TXT 우선순위 (서버측 ShareManager가 처리)
                        lyricsApiUrl: lyricsApiUrl,
                    }],
                    startIndex: 0,
                    volume: 0.8,
                    loop: 'one',  // 단일 트랙은 반복 재생
                    cover: '',
                });
                
                // === 메타 정보 fallback (서버 audio_durations API가 없으므로 클라이언트 직접 추출) ===
                // 메인은 audio_durations API로 sample_rate/bitrate/channels 받지만,
                // 공유 환경엔 그 API가 없음 → 파일 크기 + duration으로 평균 비트레이트만 표시
                const fileSize = <?= (int)($share['size'] ?? 0) ?>;
                <?php endif; ?>
                <?php if (!$isFolderAudioPlaylist): ?>
                // 단일 트랙: 메타 정보 fallback (폴더 stream은 트랙별로 FSAudioPlayer가 자동 처리)
                const updateShareMeta = () => {
                    if (sharePlayer._destroyed) return;
                    const metaEl = sharePlayer.$ && sharePlayer.$.meta;
                    if (!metaEl) return;
                    const dur = sharePlayer.audio.duration;
                    if (!dur || !isFinite(dur) || dur <= 0) return;
                    const parts = [(ext || 'audio').toUpperCase()];
                    if (fileSize > 0) {
                        const estBitrate = Math.round((fileSize * 8) / dur / 1000);
                        if (estBitrate > 0 && estBitrate < 10000) {
                            parts.push('~' + estBitrate + 'kbps');
                        }
                    }
                    metaEl.textContent = parts.join(' · ');
                };
                // loadedmetadata 이벤트 후 메타 갱신 (duration이 들어옴)
                sharePlayer.audio.addEventListener('loadedmetadata', updateShareMeta);
                // 즉시 한 번 시도 (이미 로드된 경우)
                if (sharePlayer.audio.readyState >= 1) updateShareMeta();
                <?php endif; ?>
                
                // === 키보드 단축키 (메인의 modal-preview 핸들러 패턴과 동일) ===
                // Space: 재생/일시정지, M: 음소거, ←→: 5초 이동, ↑↓: 볼륨 ±5%, S: 셔플, L: 반복
                // 버튼 click() 시뮬레이션으로 FSAudioPlayer 내부 로직 그대로 활용 (안전)
                const shareKeyHandler = (e) => {
                    // input/textarea 안에서는 무시
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                    if (e.target.isContentEditable) return;
                    if (sharePlayer._destroyed) return;
                    
                    const audio = sharePlayer.audio;
                    if (!audio) return;
                    
                    // Space: 재생/일시정지
                    if (e.key === ' ' || e.code === 'Space') {
                        sharePlayer.togglePlay();
                        e.preventDefault();
                        return;
                    }
                    // M: 음소거 (볼륨 버튼 click 시뮬레이션)
                    if (e.key === 'm' || e.key === 'M') {
                        if (sharePlayer.$ && sharePlayer.$.btnVol) {
                            sharePlayer.$.btnVol.click();
                        }
                        e.preventDefault();
                        return;
                    }
                    // ←→: 5초 이동
                    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                        const seekAmount = e.key === 'ArrowLeft' ? -5 : 5;
                        audio.currentTime = Math.max(0, Math.min(audio.duration || 0, audio.currentTime + seekAmount));
                        e.preventDefault();
                        return;
                    }
                    // ↑↓: 볼륨 ±5% (iOS는 시스템 정책상 audio.volume 변경 불가 → skip)
                    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                        if (sharePlayer._isIOS) return;
                        const delta = e.key === 'ArrowUp' ? 0.05 : -0.05;
                        const curVol = (typeof sharePlayer._getVolume === 'function') 
                            ? sharePlayer._getVolume() 
                            : (sharePlayer._volume || 0);
                        const newVol = Math.max(0, Math.min(1, curVol + delta));
                        if (typeof sharePlayer._setVolume === 'function') {
                            sharePlayer._setVolume(newVol);
                            if (typeof sharePlayer._updateVolUI === 'function') sharePlayer._updateVolUI();
                            if (typeof sharePlayer._showVolumeToast === 'function') sharePlayer._showVolumeToast();
                            if (typeof sharePlayer._saveVolumePref === 'function') sharePlayer._saveVolumePref();
                        }
                        e.preventDefault();
                        return;
                    }
                    // S: 셔플 토글 (단일 트랙은 효과 없지만 일관성 위해 유지)
                    if (e.key === 's' || e.key === 'S') {
                        if (sharePlayer.$ && sharePlayer.$.btnShuffle) {
                            sharePlayer.$.btnShuffle.click();
                            e.preventDefault();
                        }
                        return;
                    }
                    // L: 반복 모드 토글
                    if (e.key === 'l' || e.key === 'L') {
                        if (sharePlayer.$ && sharePlayer.$.btnLoop) {
                            sharePlayer.$.btnLoop.click();
                            e.preventDefault();
                        }
                        return;
                    }
                };
                document.addEventListener('keydown', shareKeyHandler);
                
                // 페이지 unload 시 정리 (Wake Lock 해제 등)
                window.addEventListener('beforeunload', () => {
                    try { sharePlayer.destroy(); } catch(e) {}
                    document.removeEventListener('keydown', shareKeyHandler);
                });
            } catch (e) {
                console.error('[Share] FSAudioPlayer init failed:', e);
                _fsDbg('★ FSAudioPlayer 초기화 EXCEPTION', { error: String(e), stack: e?.stack?.substring(0, 500) });
                // 초기화 실패 시 숨겨둔 audio 표시 (브라우저 기본 fallback)
                player.style.display = 'block';
                player.controls = true;
            }
        } else {
            _fsDbg('★ FSAudioPlayer 초기화 if 조건 실패', {
                playerTagName: player?.tagName,
                fsAudioPlayerDivExists: !!document.getElementById('fs-audio-player'),
                FSAudioPlayerDefined: typeof FSAudioPlayer !== 'undefined'
            });
        }
        
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

    <?php if ($pageSubFile !== null && $folderTrackTotal !== null && !empty($folderTracks)): ?>
    <script nonce="<?= $cspNonce ?>">
    /* ★ 폴더 stream sub-file: 사이드 플레이리스트 패널 토글 (팟플레이어 스타일) */
    (function() {
        const panel = document.getElementById('share-playlist-panel');
        const toggleBtn = document.getElementById('share-pl-toggle-btn');
        const closeBtn = document.getElementById('share-pl-close');
        const body = document.getElementById('share-pl-body');
        if (!panel || !toggleBtn) return;

        const STORAGE_KEY = 'share_playlist_panel_open';
        // ★ 사이드 패널 스크롤 위치 임시 저장 (펜닐님 결정 옵션 B)
        //   - 트랙 클릭 직전: 현재 스크롤 위치 sessionStorage 저장
        //   - 새 페이지 로드 시: 저장된 위치 복원 (없으면 가운데 — 첫 진입)
        //   - 사용 후 즉시 삭제 → 다음 첫 진입(브라우저 닫고 다시 열기 등) 시 가운데
        const SCROLL_POS_KEY = 'share_playlist_panel_scroll';

        function openPanel(skipScrollAdjust) {
            panel.classList.add('open');
            panel.setAttribute('aria-hidden', 'false');
            toggleBtn.classList.add('active');
            try { sessionStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
            // ★ 첫 로드 시만 스크롤 위치 적용 (토글로 열 때는 DOM 그대로 → 위치 자동 유지)
            if (!skipScrollAdjust) {
                applyScrollPosition();
            }
            // display:none → flex 직후 layout 적용 대기 (rAF로 다음 프레임 보장)
            requestAnimationFrame(function() {
                checkCurrentTrackOverflow();
            });
        }
        function closePanel() {
            panel.classList.remove('open');
            panel.setAttribute('aria-hidden', 'true');
            toggleBtn.classList.remove('active');
            try { sessionStorage.setItem(STORAGE_KEY, '0'); } catch (e) {}
        }
        function togglePanel() {
            if (panel.classList.contains('open')) closePanel();
            else openPanel(true);  // 토글로 열 때는 스크롤 조정 스킵 (위치 유지)
        }
        // ★ 스크롤 위치 적용 (sessionStorage 우선, 없으면 가운데 — 펜닐님 결정 옵션 B)
        function applyScrollPosition() {
            if (!body) return;
            try {
                const savedScroll = sessionStorage.getItem(SCROLL_POS_KEY);
                if (savedScroll !== null) {
                    // 트랙 전환으로 새 페이지 로드 → 이전 스크롤 위치 복원
                    body.scrollTop = parseInt(savedScroll, 10) || 0;
                    sessionStorage.removeItem(SCROLL_POS_KEY);  // 1회용 — 다음 첫 진입 시 가운데
                } else {
                    // 첫 진입(저장 없음) → 재생 중 트랙 가운데 스크롤
                    scrollToCurrentTrack();
                }
            } catch (e) {}
        }
        function scrollToCurrentTrack() {
            if (!body) return;
            const cur = body.querySelector('.share-pl-item.current');
            if (cur) {
                // body 기준 상대 위치 — 부드럽게 스크롤
                try {
                    const offset = cur.offsetTop - body.offsetTop - (body.clientHeight / 2) + (cur.clientHeight / 2);
                    body.scrollTop = Math.max(0, offset);
                } catch (e) {}
            }
        }
        // ★ 트랙 클릭 시 현재 스크롤 위치 저장 (페이지 이동 직전 — 다음 페이지에서 복원)
        if (body) {
            body.addEventListener('click', function(e) {
                const item = e.target.closest('.share-pl-item');
                if (!item) return;
                try {
                    sessionStorage.setItem(SCROLL_POS_KEY, String(body.scrollTop));
                } catch (e) {}
                // <a> 기본 동작(href 이동) 그대로 진행 → 새 페이지 로드 → openPanel → applyScrollPosition
            });
        }
        // ★ 재생 중 트랙 제목이 컨테이너 폭 초과 시 마퀴 활성화 (is-overflow 클래스)
        //    펜닐님 결정: current 트랙만 마퀴, 다른 트랙은 ellipsis 유지
        function checkCurrentTrackOverflow() {
            if (!body) return;
            try {
                const cur = body.querySelector('.share-pl-item.current');
                if (!cur) return;
                const nameEl = cur.querySelector('.pl-name');
                const innerEl = cur.querySelector('.pl-name-inner');
                if (!nameEl || !innerEl) return;
                // 측정 전: is-overflow 제거하고 자연 폭 측정
                innerEl.classList.remove('is-overflow');
                // ★ 모든 animation 속성 리셋
                innerEl.style.removeProperty('animation');
                innerEl.style.removeProperty('animation-name');
                innerEl.style.removeProperty('animation-duration');
                innerEl.style.removeProperty('animation-timing-function');
                innerEl.style.removeProperty('animation-iteration-count');
                // scrollWidth = 텍스트 자연 폭, clientWidth = 컨테이너 가용 폭
                const textW = innerEl.scrollWidth;
                const boxW = nameEl.clientWidth;
                if (textW > boxW + 1) {
                    // 마퀴 시간: 텍스트 폭 + 컨테이너 폭(=padding-left:100%) 기준 — 픽셀당 ~25ms (대략 40px/sec)
                    const totalPx = textW + boxW;
                    const dur = Math.max(8, Math.min(30, Math.round(totalPx / 40)));
                    // ★ 개별 속성으로 !important 설정 — shorthand는 :hover paused 무시함
                    innerEl.style.setProperty('animation-name', 'share-pl-marquee', 'important');
                    innerEl.style.setProperty('animation-duration', dur + 's', 'important');
                    innerEl.style.setProperty('animation-timing-function', 'linear', 'important');
                    innerEl.style.setProperty('animation-iteration-count', 'infinite', 'important');
                    innerEl.classList.add('is-overflow');
                }
            } catch (e) { /* 무시 */ }
        }

        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            togglePanel();
        });
        if (closeBtn) {
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                closePanel();
            });
        }

        // 페이지 로드 시 sessionStorage 상태 복원
        // ★ 펜닐님 결정 (기본값 = 열림): 첫 진입 시 (saved === null) 자동 열림
        //   - saved === '1': 명시적으로 열린 상태 → 열림
        //   - saved === '0': 사용자가 명시적으로 닫음 → 닫힘
        //   - saved === null: 첫 진입 → 자동 열림 (기본값)
        try {
            const saved = sessionStorage.getItem(STORAGE_KEY);
            if (saved !== '0') {
                // '1' 또는 null (첫 진입) → 열림
                openPanel();
            }
        } catch (e) {}

        // ESC 키로 패널 닫기 (전체화면 우선이라 fullscreen 아닐 때만)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && panel.classList.contains('open') && !document.fullscreenElement) {
                closePanel();
            }
        });

        // ★ 윈도우 리사이즈 시 마퀴 폭 재측정 (패널 폭이 변할 수 있는 모바일 환경 대응)
        let _resizeTimer = null;
        window.addEventListener('resize', function() {
            if (_resizeTimer) clearTimeout(_resizeTimer);
            _resizeTimer = setTimeout(function() {
                if (panel.classList.contains('open')) checkCurrentTrackOverflow();
            }, 200);
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
