<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * rhwp studio 이전 버전 파일 자동 정리 스크립트
 * 
 * 사용법:
 *   CLI: php cleanup_rhwp_studio.php
 *   웹: https://your-site/cleanup_rhwp_studio.php?confirm=1  (관리자 인증 필요)
 * 
 * 동작:
 *   1. rhwp_editor.php가 현재 참조하는 index-*.js, *.css 파일명 추출
 *   2. studio/ 폴더의 해시 파일들 중 현재 사용 중이 아닌 것 탐색
 *   3. 드라이런(dry-run)으로 먼저 나열, --execute 옵션으로 실제 삭제
 *
 * 이 파일은 FileStation 최상위에 위치. rhwp 업그레이드와 무관하게 유지됨.
 */

// ───────────────────────────────────────────────────────────
// CLI / 웹 실행 모드 감지
// ───────────────────────────────────────────────────────────
$isCLI = (php_sapi_name() === 'cli');
$dryRun = true;

if ($isCLI) {
    // CLI: --execute 플래그로 실제 삭제
    $dryRun = !in_array('--execute', $argv ?? []);
    echo "=== rhwp studio 이전 파일 정리 ===\n";
    echo "모드: " . ($dryRun ? "드라이런 (삭제 안 함)" : "실행 (실제 삭제)") . "\n\n";
} else {
    // 웹: 관리자 인증 필수
    require_once __DIR__ . '/config.php';
    session_start();
    
    // Auth 체크 (관리자만)
    if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>403 접근 권한 없음</h1><p>관리자로 로그인해주세요.</p>';
        exit;
    }
    
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>rhwp studio 정리</title>';
    echo '<style>body{font-family:sans-serif;margin:20px;}code{background:#eee;padding:2px 4px;}';
    echo '.btn{padding:8px 16px;background:#0066cc;color:#fff;border:none;border-radius:4px;cursor:pointer;text-decoration:none;display:inline-block;}';
    echo '.btn.danger{background:#cc3333;}.ok{color:#080;}.warn{color:#c60;}.info{color:#06c;}</style></head><body>';
    echo '<h1>🧹 rhwp studio 이전 파일 정리</h1>';
    
    $dryRun = !isset($_GET['confirm']) || $_GET['confirm'] !== '1';
    echo '<p>모드: <strong>' . ($dryRun ? '<span class="info">미리보기 (실제 삭제 없음)</span>' : '<span class="warn">실제 삭제 실행</span>') . '</strong></p>';
}

// ───────────────────────────────────────────────────────────
// 1. 현재 사용 중인 파일명 추출 (rhwp_editor.php에서)
// ───────────────────────────────────────────────────────────
$editorPath = __DIR__ . '/rhwp_editor.php';
if (!file_exists($editorPath)) {
    $msg = "❌ rhwp_editor.php를 찾을 수 없습니다.";
    if ($isCLI) echo $msg . "\n"; else echo "<p class='warn'>$msg</p></body></html>";
    exit(1);
}

$editorContent = file_get_contents($editorPath);
preg_match_all('/index-[A-Za-z0-9_-]+\.(js|css)/', $editorContent, $matches);
$activeFiles = array_unique($matches[0] ?? []);

if (empty($activeFiles)) {
    $msg = "⚠️ rhwp_editor.php에서 참조 중인 index-*.js/css 파일을 찾을 수 없습니다.";
    if ($isCLI) echo $msg . "\n"; else echo "<p class='warn'>$msg</p></body></html>";
    exit(1);
}

if ($isCLI) {
    echo "[현재 사용 중인 파일]\n";
    foreach ($activeFiles as $f) echo "  - $f\n";
    echo "\n";
} else {
    echo '<h2>현재 사용 중인 파일</h2><ul>';
    foreach ($activeFiles as $f) echo "<li><code>$f</code></li>";
    echo '</ul>';
}

// ───────────────────────────────────────────────────────────
// 2. studio/ 폴더에서 해시 파일 목록 수집
// ───────────────────────────────────────────────────────────
$studioDir = __DIR__ . '/assets/rhwp/studio';
if (!is_dir($studioDir)) {
    $msg = "❌ studio 폴더 없음: $studioDir";
    if ($isCLI) echo $msg . "\n"; else echo "<p class='warn'>$msg</p></body></html>";
    exit(1);
}

// 해시 파일 패턴: index-XXX.js, index-XXX.css, rhwp_bg-XXX.wasm
$patterns = [
    'index-*.js',
    'index-*.css',
    'rhwp_bg-*.wasm',
];

// 현재 사용 중인 wasm 파일명도 추출 (studio JS 안에 있음)
// wasm은 rhwp_editor.php가 아니라 studio JS가 참조하므로 별도로 찾음
$activeWasm = [];
foreach ($activeFiles as $f) {
    if (substr($f, -3) === '.js') {
        $jsPath = $studioDir . '/' . $f;
        if (file_exists($jsPath)) {
            $jsContent = file_get_contents($jsPath);
            preg_match_all('/rhwp_bg-[A-Za-z0-9_-]+\.wasm/', $jsContent, $m);
            $activeWasm = array_merge($activeWasm, $m[0] ?? []);
        }
    }
}
$activeWasm = array_unique($activeWasm);
$activeAll = array_merge($activeFiles, $activeWasm);

if ($isCLI) {
    echo "[현재 사용 중인 WASM]\n";
    foreach ($activeWasm as $f) echo "  - $f\n";
    echo "\n";
} else {
    echo '<h2>현재 사용 중인 WASM</h2><ul>';
    foreach ($activeWasm as $f) echo "<li><code>$f</code></li>";
    echo '</ul>';
}

// ───────────────────────────────────────────────────────────
// 3. 삭제 대상 탐색
// ───────────────────────────────────────────────────────────
$toDelete = [];
$totalSize = 0;

foreach ($patterns as $pattern) {
    $files = glob($studioDir . '/' . $pattern);
    foreach ($files as $filepath) {
        $filename = basename($filepath);
        // 현재 사용 중이면 스킵
        if (in_array($filename, $activeAll, true)) continue;
        
        $size = filesize($filepath);
        $toDelete[] = [
            'path' => $filepath,
            'name' => $filename,
            'size' => $size,
            'mtime' => filemtime($filepath),
        ];
        $totalSize += $size;
    }
}

if ($isCLI) {
    echo "[삭제 대상: " . count($toDelete) . "개, " . formatBytes($totalSize) . "]\n";
    if (empty($toDelete)) {
        echo "✅ 이미 정리된 상태입니다.\n";
        exit(0);
    }
    foreach ($toDelete as $item) {
        $age = floor((time() - $item['mtime']) / 86400);
        echo "  - {$item['name']}  (" . formatBytes($item['size']) . ", {$age}일 전)\n";
    }
} else {
    echo '<h2>🗑️ 삭제 대상 (' . count($toDelete) . '개, ' . formatBytes($totalSize) . ')</h2>';
    if (empty($toDelete)) {
        echo '<p class="ok">✅ 이미 정리된 상태입니다.</p></body></html>';
        exit(0);
    }
    echo '<table border="1" cellpadding="6" style="border-collapse:collapse;"><tr><th>파일명</th><th>크기</th><th>수정일</th></tr>';
    foreach ($toDelete as $item) {
        echo '<tr><td><code>' . htmlspecialchars($item['name']) . '</code></td>';
        echo '<td>' . formatBytes($item['size']) . '</td>';
        echo '<td>' . date('Y-m-d H:i', $item['mtime']) . '</td></tr>';
    }
    echo '</table>';
}

// ───────────────────────────────────────────────────────────
// 4. 실제 삭제 (또는 미리보기)
// ───────────────────────────────────────────────────────────
if ($dryRun) {
    if ($isCLI) {
        echo "\n[드라이런 종료 — 실제 삭제하려면 --execute 옵션 추가]\n";
        echo "  php " . basename(__FILE__) . " --execute\n";
    } else {
        $url = $_SERVER['PHP_SELF'] . '?confirm=1';
        echo '<p><a href="' . htmlspecialchars($url) . '" class="btn danger" onclick="return confirm(\'정말 삭제하시겠습니까?\')">🗑️ 실제 삭제하기</a></p>';
        echo '</body></html>';
    }
    exit(0);
}

// 실제 삭제
$deleted = 0;
$failed = 0;
foreach ($toDelete as $item) {
    if (@unlink($item['path'])) {
        $deleted++;
    } else {
        $failed++;
    }
}

$result = "✅ 삭제 완료: {$deleted}개, 실패: {$failed}개, 정리된 용량: " . formatBytes($totalSize);
if ($isCLI) {
    echo "\n$result\n";
} else {
    echo '<p class="ok"><strong>' . $result . '</strong></p>';
    echo '<p><a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="btn">다시 확인</a></p>';
    echo '</body></html>';
}

// ───────────────────────────────────────────────────────────
function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . 'B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . 'KB';
    return round($bytes / 1024 / 1024, 1) . 'MB';
}
