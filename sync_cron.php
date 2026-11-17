<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * FileStation 동기화 Cron 스크립트
 * 
 * 사용법:
 *   php sync_cron.php              — 예약 시간이 된 모든 작업 실행
 *   php sync_cron.php --task=1     — 특정 작업만 실행
 *   php sync_cron.php --list       — 작업 목록 표시
 * 
 * Cron 예시:
 *   * * * * * php /path/to/sync_cron.php >> /path/to/sync_cron.log 2>&1
 */

// 웹 접속 차단
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

set_time_limit(0);
error_reporting(E_ALL);   // ★ (2026-11-01 09:44, 38차 점검) 참고 — 아래 config.php 가 error_reporting(0) 으로 다시 정해 실제로는 효과 없음(cron 로그에 PHP 경고가 남지 않음). 동작은 그대로 둠
date_default_timezone_set('Asia/Seoul');

$baseDir = __DIR__;

// config.php 로드 (상수 정의 + 오토로더 + StorageAdapter)
require_once $baseDir . '/config.php';

// DB 초기화 (싱글턴)
$db = JsonDB::getInstance();
$storage = new Storage();

// 인자 파싱
$taskId = null;
$listMode = false;
foreach ($argv as $arg) {
    if (preg_match('/--task=(\d+)/', $arg, $m)) $taskId = (int)$m[1];
    if ($arg === '--list') $listMode = true;
}

$tasks = $db->load('sync_tasks') ?: [];

// 작업 목록 표시
if ($listMode) {
    echo "=== Sync Tasks ===\n";
    foreach ($tasks as $t) {
        $sched = $t['schedule'] ?? null;
        $schedStr = $sched && !empty($sched['enabled']) ? ($sched['type'] ?? 'daily') . ' ' . ($sched['time'] ?? '') : 'disabled';
        echo sprintf("  #%d  %-20s  mode:%-12s  schedule:%s  last:%s\n",
            $t['id'], $t['name'], $t['mode'], $schedStr, $t['last_run'] ?? 'never');
    }
    exit(0);
}

// 실행할 작업 필터링
$now = time();
$currentHour = (int)date('G');
$currentMinute = (int)date('i');
$currentDow = (int)date('w');
$currentDom = (int)date('j');

$toExecute = [];

foreach ($tasks as &$task) {
    if ($taskId !== null) {
        if (($task['id'] ?? 0) == $taskId) {
            $toExecute[] = &$task;
        }
        continue;
    }
    
    $sched = $task['schedule'] ?? null;
    if (!$sched || empty($sched['enabled'])) continue;
    
    $type = $sched['type'] ?? 'daily';
    $time = $sched['time'] ?? '03:00';
    if (!is_string($time)) $time = '03:00';   // ★ (2026-11-17 00:12, 282차 점검) 시각이 글자가 아니면(API 로 배열 저장) 없을 때와 같은 기본값 — 종전엔 explode TypeError 로 예약 실행 전체가 멈춰(종료 코드 255) 다른 멀쩡한 작업도 안 돌았음(재현)
    list($schedHour, $schedMin) = array_map('intval', explode(':', $time));
    
    $shouldRun = false;
    // ★ (2026-11-02 09:09, 41차 점검) daily·weekly·monthly 는 '정확히 그 분'에만 돌아, 앞 회차가 긴 동기화로 그 분을 넘기면(Windows 작업 스케줄러 기본값은 앞 인스턴스가 돌면 새로 안 띄움)
    //   그날 예약이 통째로 빠졌음(D6#3) → 그날(예약 요일·날짜인 그날) 예정 시각이 지났고 마지막 실행(last_run, 끝날 때 기록)이 그 예정 시각보다 앞이면 다음 실행에서 돌림.
    //   한 번도 안 돈 작업은 만든 시각(created_at)을 기준으로(그날 예정 시각 뒤에 만든 작업은 다음 예정 시각까지 기다림 — 종전과 같음), 둘 다 없으면 종전처럼 그 분에만
    $syDueToday = mktime($schedHour, $schedMin, 0);
    $syRef = !empty($task['last_run']) ? strtotime((string)$task['last_run']) : (!empty($task['created_at']) ? strtotime((string)$task['created_at']) : false);
    // ★ (2026-11-02 09:47, 41차 재검토) 실행을 시작한 시각(last_attempt)도 기준에 넣음 — 실행 중 치명 오류·강제 종료로 last_run 이 안 남으면 그날 남은 시간 동안 매분 처음부터 다시 돌았음(검토 발견)
    if (!empty($task['last_attempt']) && ($syAt = strtotime((string)$task['last_attempt'])) !== false && ($syRef === false || $syAt > $syRef)) $syRef = $syAt;
    $syCatchUp = ($syRef !== false && $syRef !== null) ? ($now >= $syDueToday && $syRef < $syDueToday)
                                                       : ($currentHour == $schedHour && $currentMinute == $schedMin);
    
    switch ($type) {
        case 'hourly':
            $interval = (int)($sched['hours'] ?? 1);
            $lastRun = $task['last_run'] ? strtotime($task['last_run']) : 0;
            if (!empty($task['last_attempt'])) $lastRun = max((int)$lastRun, (int)strtotime((string)$task['last_attempt']));   // ★ (2026-11-02 09:47, 41차 재검토) 시작 시각도 봄 — 도중에 죽으면 last_run 이 안 남아 매분 다시 돌았음(종전부터)
            // ★ (2026-11-02 10:32, 42차 점검) 끝난 시각(last_run) 기준 + 분 단위 cron 이라 회차마다 '걸린 시간 + 1분 미만'씩 밀려 하루 24회가 23회 남짓이었음(E8#8)
            //   → 시작 시각(last_attempt — cron·화면 실행 모두 시작할 때 기록)이 있으면 그것을 기준으로, cron 이 몇 초 늦게 뜨는 것은 59초까지 봐줌(예정 시각에 맞춰 돎).
            //   시작 시각이 없는 옛 작업은 종전처럼 last_run. 도중에 죽은 경우(last_attempt > last_run)도 시작 시각 기준이라 종전과 같음
            if ($interval < 1) $interval = 1;   // 0 이하면 매분 돌았음(저장 쪽도 1 이상으로 자름)
            if (!empty($task['last_attempt']) && ($syHa = strtotime((string)$task['last_attempt'])) !== false) $lastRun = $syHa;
            // ★ (2026-11-02 10:58, 42차 재검토) 분 단위로 비교 — '초 차이 ≥ N시간-59초' 는 cron 이 뜨는 지연이 기록한 회차보다 늦으면 59분째에 돌아 회차가 1분씩 앞당겨졌음(검토 발견)
            if (intdiv($now, 60) - intdiv((int)$lastRun, 60) >= $interval * 60) {
                $shouldRun = true;
            }
            break;
        case 'daily':
            if ($syCatchUp) {
                $shouldRun = true;
            }
            break;
        case 'weekly':
            $dow = (int)($sched['dow'] ?? 0);
            if ($currentDow == $dow && $syCatchUp) {
                $shouldRun = true;
            }
            break;
        case 'monthly':
            $dom = (int)($sched['dom'] ?? 1);
            // ★ (2026-11-02 10:32, 42차 점검) 29~31 은 그날이 없는 달(2·4·6·9·11월)에 통째로 빠지고 32 이상(저장 쪽 범위 확인 전 값)은 영영 안 돌았음(E8#7)
            //   → 정한 날짜가 그 달 마지막 날보다 크면 그 달 마지막 날에 돎(31일 예약 = '매달 말일'). 1 미만은 1일
            $dom = min(max(1, $dom), (int)date('t'));
            if ($currentDom == $dom && $syCatchUp) {
                $shouldRun = true;
            }
            break;
    }
    
    // 중복 실행 방지 (60초 이내)
    if ($shouldRun && $task['last_run']) {
        if ($now - strtotime($task['last_run']) < 60) {
            $shouldRun = false;
        }
    }
    
    if ($shouldRun) {
        $toExecute[] = &$task;
    }
}
unset($task);

if (empty($toExecute)) {
    exit(0);
}

// ★ (2026-10-29 04:18, 17차) 작업별 잠금 — 앞 회차가 아직 돌고 있거나(긴 작업 + 매시 예약) 화면에서 같은 작업을 실행 중이면 이번엔 건너뜀.
//   종전엔 매분 cron 이 같은 작업을 겹쳐 돌려 같은 대상에 동시에 쓰고 지울 수 있었음(last_run 은 끝날 때만 기록되므로 막히지 않았음)
$syncLockDir = defined('DATA_PATH') ? DATA_PATH : ($baseDir . '/data');
// ★ (2026-11-02 10:15, 42차 점검) 실행 중 치명 오류·강제 종료로 끝나도 모은 사용량(아래 $syDelta)을 반영(api.php sync_execute 와 같음 — 끝 반영까지 못 가 버려졌음, 검토 발견)
$syDelta = [];
register_shutdown_function(function () use (&$syDelta, $storage) {
    if (!is_array($syDelta)) return;
    foreach ($syDelta as $sdSid => $sdD) { if ((int)$sdD !== 0) { try { $storage->updateUsedSize((int)$sdSid, (int)$sdD); } catch (\Throwable $sdE) {} } }
    $syDelta = [];
});
foreach ($toExecute as &$task) {
    $taskName = $task['name'] ?? 'Unnamed';
    $syncLock = @fopen($syncLockDir . '/sync_task_' . (int)($task['id'] ?? 0) . '.lock', 'c');
    // ★ (2026-11-02 09:09, 41차 점검) cron 과 웹이 다른 계정이면(Linux crontab -e = 로그인 계정) 다른 쪽이 만든 잠금 파일(0644)을 쓰기로 못 열어 늘 '이미 실행 중'이었음(D6#8)
    //   → 쓰기로 못 열면 읽기로 다시 엶(flock 은 읽기 핸들로도 됨 — api.php sync_execute 도 같게)
    if (!$syncLock) $syncLock = @fopen($syncLockDir . '/sync_task_' . (int)($task['id'] ?? 0) . '.lock', 'r');
    if (!$syncLock || !flock($syncLock, LOCK_EX | LOCK_NB)) {
        echo date('[Y-m-d H:i:s]') . " Skip sync task #{$task['id']}: {$taskName} (already running)\n";
        if ($syncLock) fclose($syncLock);
        continue;
    }
    // ★ (2026-11-01 07:23, 37차 기능 점검) 잠금을 얻은 뒤 최신 설정을 다시 읽음 — 한 번에 여러 작업을 돌릴 때 앞 작업이 오래 걸리는 사이 고치거나 지운 작업이 옛 설정(대상 경로·고아 삭제)으로 실행됐음
    $syFresh = null;
    foreach (($db->load('sync_tasks') ?: []) as $syT) { if ((int)($syT['id'] ?? 0) === (int)($task['id'] ?? 0)) { $syFresh = $syT; break; } }
    if ($syFresh === null) { echo date('[Y-m-d H:i:s]') . " Skip sync task #{$task['id']}: deleted\n"; flock($syncLock, LOCK_UN); fclose($syncLock); continue; }
    // ★ (2026-11-01 09:45, 38차 점검) 다시 읽은 설정에서 예약이 꺼졌으면 이번 예약 실행은 건너뜀 — 종전엔 앞 작업이 도는 사이 관리자가 예약을 끈 작업(고아 삭제 포함)도 돌았음.
    //   --task=번호 로 직접 실행한 경우는 종전처럼 예약과 상관없이 실행
    if ($taskId === null && empty($syFresh['schedule']['enabled'])) { echo date('[Y-m-d H:i:s]') . " Skip sync task #{$task['id']}: schedule disabled\n"; flock($syncLock, LOCK_UN); fclose($syncLock); continue; }
    // ★ (2026-11-02 09:47, 41차 재검토) 판정 뒤 다른 실행이 이 작업을 이미 돌렸으면 건너뜀 — Linux 매분 cron 에서 앞 작업이 도는 동안 뜬 다른 회차도 같은 따라잡기 후보를 잡아,
    //   먼저 돈 회차가 잠금을 푼 뒤 같은 날 또 돌았음(검토 발견). 판정 때 읽은 last_run·last_attempt 와 잠금 뒤 다시 읽은 값이 다르면 그 사이 누군가 실행한 것
    if ($taskId === null && ((string)($syFresh['last_run'] ?? '') !== (string)($task['last_run'] ?? '') || (string)($syFresh['last_attempt'] ?? '') !== (string)($task['last_attempt'] ?? ''))) {
        echo date('[Y-m-d H:i:s]') . " Skip sync task #{$task['id']}: {$taskName} (already run by another instance)\n"; flock($syncLock, LOCK_UN); fclose($syncLock); continue;
    }
    $task = array_merge($task, $syFresh);
    try { $db->update('sync_tasks', ['id' => $task['id']], ['last_attempt' => date('Y-m-d H:i:s')]); } catch (\Throwable $e) {}   // ★ (2026-11-02 09:47, 41차 재검토) 시작 시각 기록(위 따라잡기 기준)
    echo date('[Y-m-d H:i:s]') . " Starting sync task #{$task['id']}: {$taskName}\n";
    $syDelta = [];   // ★ (2026-11-02 09:48, 41차 재검토) 모은 사용량(아래 끝에서 반영)
    
    try {
        $srcStorageId = $task['source_storage_id'];
        $tgtStorageId = $task['target_storage_id'];
        $srcPath = trim($task['source_path'] ?? '', '/');
        $tgtPath = trim($task['target_path'] ?? '', '/');
        // ★ (2026-11-01 09:45, 38차 점검) 경로를 조각 단위로 정리하고 '..' 가 있으면 실행하지 않음 — 종전엔 trim 만 해서 source_path·target_path 에 '../../(앱 폴더)/data' 를 넣으면
        //   LocalAdapter 가 그대로 이어 붙여 스토리지 밖(users.json 등)을 읽고 덮어쓸 수 있었음(api.php sync_task_save·sync_execute 와 같은 규칙).
        //   역슬래시도 구분자로 보고, '' 와 '.' 조각은 버림('./docs'·'a//b' 를 정리해 아래 겹침 판정도 정리된 값으로). Windows 는 점·공백만인 조각(' ..', '...')도 '..' 로 봄
        $syNorm = function ($p) {
            $out = [];
            foreach (explode('/', str_replace('\\', '/', (string)$p)) as $seg) {
                if ($seg === '' || $seg === '.') continue;
                if ($seg === '..' || (PHP_OS_FAMILY === 'Windows' && trim($seg, '. ') === '')) return null;
                $out[] = $seg;
            }
            return implode('/', $out);
        };
        $srcPathN = $syNorm($srcPath);
        $tgtPathN = $syNorm($tgtPath);
        if ($srcPathN === null || $tgtPathN === null) throw new Exception("Invalid sync path ('..' not allowed): src='$srcPath', tgt='$tgtPath'");
        $srcPath = $srcPathN;
        $tgtPath = $tgtPathN;
        // ★ (2026-11-01 01:56, 36차 전체 점검) 아래 경로 판정은 참/거짓 대신 !== '' — 폴더 이름이 '0' 이면 빈 경로로 취급돼 엉뚱한 곳에 복사하고 대상의 기존 파일을 고아로 지웠음(26차 '0' 폴더 수정과 같은 방식)
        $mode = $task['mode'] ?? 'one-way';
        $deleteOrphan = !empty($task['delete_orphan']);
        $includeSubdir = $task['include_subdir'] ?? true;
        
        // getStorageById로 shared/home 경로 정상 해석
        $srcStorage = $storage->getStorageById($srcStorageId);
        $tgtStorage = $storage->getStorageById($tgtStorageId);
        if (!$srcStorage || !$tgtStorage) throw new Exception("Storage not found (src:$srcStorageId, tgt:$tgtStorageId)");
        // ★ (2026-11-01 17:19, 40차 점검) 예약 실행도 작업을 저장한 사람(created_by) 기준으로 스토리지 권한 확인 — 종전엔 확인이 없어 '스토리지' 권한 부관리자가 만든 작업이
        //   남의 홈을 읽고 덮어쓰고 지울 수 있었음. 규칙은 Storage::checkPermission 과 같고(api.php fsSyncPermError 와 같은 필요 권한), 실제 관리자가 저장한 작업은 종전 그대로.
        //   created_by 가 없는 옛 작업은 종전 그대로 실행(누가 만들었는지 알 수 없음 — 결정 필요 항목). 화면에서 한 번 저장하면 기록됨
        $syFolderUid = null;   // ★ (2026-11-04, 55차 점검) 폴더 규칙을 볼 작업 소유자(실제 관리자가 아닐 때만 — 아래 X-5 확인)
        if (isset($task['created_by'])) {
            $syUid = (int)$task['created_by'];
            $syUser = $syUid > 0 ? $db->find('users', ['id' => $syUid]) : null;
            if (!$syUser) throw new Exception("Task owner not found (created_by:$syUid)");
            if (($syUser['role'] ?? '') !== 'admin') {
                $syFolderUid = $syUid;
                $syCan = function (array $st, int $sid, string $perm) use ($db, $syUid, $syUser) {
                    $type = $st['storage_type'] ?? 'local';
                    if ($type === 'home') return (int)($st['owner_id'] ?? 0) === $syUid;
                    $p = $db->find('permissions', ['storage_id' => $sid, 'user_id' => $syUid]);
                    if ($type === 'shared') return $p ? (bool)($p[$perm] ?? false) : in_array($perm, ['can_read', 'can_download', 'can_visible'], true);
                    if ($p) return (bool)($p[$perm] ?? false);
                    $subStor = ($syUser['role'] ?? '') === 'sub_admin' && is_array($syUser['admin_perms'] ?? null) && in_array('storages', $syUser['admin_perms'], true);
                    return (int)($st['created_by'] ?? 0) === $syUid && $subStor;
                };
                $syNeed = [[$srcStorage, (int)$srcStorageId, 'can_read'], [$tgtStorage, (int)$tgtStorageId, 'can_write']];
                if (!empty($task['delete_orphan']) && ($task['mode'] ?? 'one-way') !== 'two-way') $syNeed[] = [$tgtStorage, (int)$tgtStorageId, 'can_delete'];
                if (($task['mode'] ?? 'one-way') === 'two-way') $syNeed[] = [$srcStorage, (int)$srcStorageId, 'can_write'];
                foreach ($syNeed as $sn) { if (!$syCan($sn[0], $sn[1], $sn[2])) throw new Exception("Task owner (user #$syUid) lacks {$sn[2]} on storage #{$sn[1]}"); }
            }
        }

        // 스토리지명 중복 경로 자동 제거
        $srcBaseName = basename($srcStorage['path'] ?? '');
        if ($srcBaseName && $srcPath) {
            if (strpos($srcPath, $srcBaseName . '/') === 0) $srcPath = substr($srcPath, strlen($srcBaseName) + 1);
            elseif ($srcPath === $srcBaseName) $srcPath = '';
        }
        $tgtBaseName = basename($tgtStorage['path'] ?? '');
        if ($tgtBaseName && $tgtPath) {
            if (strpos($tgtPath, $tgtBaseName . '/') === 0) $tgtPath = substr($tgtPath, strlen($tgtBaseName) + 1);
            elseif ($tgtPath === $tgtBaseName) $tgtPath = '';
        }
        // ★ (2026-11-01 07:23, 37차 기능 점검) 같은 스토리지에서 원본·대상 경로가 겹치면(같거나 한쪽이 다른 쪽 안) 실행하지 않음 — 원본이 대상 안이면 원본 파일이 대상 목록에 잡혀
        //   '고아 삭제' 로 원본이 지워졌고, 대상이 원본 안이면 실행할 때마다 대상이 한 겹씩 더 복사돼 디스크가 찼음
        // ★ (2026-11-01 07:57, 37차 기능 점검 재검토) 대소문자 무시는 Windows 서버에서만(Linux 의 Docs·docs 는 다른 폴더), 하위 폴더 미포함이면 같은 폴더만 막음(한 겹만 보므로 안팎 관계는 해가 없음 — 종전 정상 설정을 막았음)
        $syOv = function ($a, $b) use ($includeSubdir) { $ci = (PHP_OS_FAMILY === 'Windows'); $a = trim(str_replace('\\', '/', (string)$a), '/'); $b = trim(str_replace('\\', '/', (string)$b), '/'); if ($ci) { $a = implode('/', array_map(function ($s) { return rtrim($s, '. '); }, explode('/', $a))); $b = implode('/', array_map(function ($s) { return rtrim($s, '. '); }, explode('/', $b))); $a = mb_strtolower($a, 'UTF-8'); $b = mb_strtolower($b, 'UTF-8'); } if ($a === $b) return true; if (!$includeSubdir) return false; return $a === '' || $b === '' || strpos($a . '/', $b . '/') === 0 || strpos($b . '/', $a . '/') === 0; };   // ★ (2026-11-01 10:14, 38차 점검 재검토) api.php sync_execute 와 같게 — Windows 에서 조각 끝 점·공백도 떼고 비교('docs.' = 'docs')
        if ((int)$srcStorageId === (int)$tgtStorageId && $syOv($srcPath, $tgtPath)) throw new Exception('Source and target folders overlap');
        // ★ (2026-11-04, 55차 점검) 폴더 규칙(folder_permissions)도 작업 소유자 기준으로 확인 — api.php fsSyncFolderPermError 와 같은 기준(원본 아래까지 can_read, 대상 can_write,
        //   고아 삭제면 대상 아래까지 can_delete, 양방향이면 원본 아래까지 can_write). 종전엔 부관리자가 읽기 금지 폴더를 동기화로 가져갈 수 있었음(X-5). 판정은 Storage 의 규칙 비교
        //   (normFolderPath·folderRuleAllows)를 그대로 쓰되 사용자만 소유자로 — checkFolderPermission/checkFolderTreePermission 과 같은 결과. 실제 관리자·created_by 없는 옛 작업은 종전 그대로
        if ($syFolderUid !== null) {
            $syFolderOk = function (int $sid, string $path, string $perm, bool $tree) use ($storage, $syFolderUid) {
                return (function () use ($sid, $path, $perm, $tree, $syFolderUid) {
                    $rules = $this->getFolderPermissions($sid);
                    if (empty($rules)) return true;
                    $base = $this->normFolderPath($path);
                    $best = null; $bestLen = -1;
                    foreach ($rules as $r) {
                        if ((int)($r['user_id'] ?? 0) !== (int)$syFolderUid) continue;
                        $rp = $this->normFolderPath((string)($r['folder_path'] ?? ''));
                        if ($rp === '') continue;
                        if ($base !== '' && ($base === $rp || strpos($base . '/', $rp . '/') === 0)) { if (strlen($rp) > $bestLen) { $bestLen = strlen($rp); $best = $r; } }
                        elseif ($tree && ($base === '' || strpos($rp, $base . '/') === 0) && !$this->folderRuleAllows($r, $perm)) return false;
                    }
                    return $best === null || $this->folderRuleAllows($best, $perm);
                })->call($storage);
            };
            $syMode = (string)($task['mode'] ?? 'one-way');
            $syFOk = $syFolderOk((int)$srcStorageId, $srcPath, 'can_read', true) && $syFolderOk((int)$tgtStorageId, $tgtPath, 'can_write', false);
            if ($syFOk && $deleteOrphan && $syMode !== 'two-way') $syFOk = $syFolderOk((int)$tgtStorageId, $tgtPath, 'can_delete', true);
            if ($syFOk && $syMode === 'two-way') $syFOk = $syFolderOk((int)$srcStorageId, $srcPath, 'can_write', true);
            if (!$syFOk) throw new Exception("Task owner (user #$syFolderUid) is denied by a folder permission rule: src='$srcPath', tgt='$tgtPath'");
        }

        $srcAdapter = StorageAdapterFactory::create($srcStorage);
        $tgtAdapter = StorageAdapterFactory::create($tgtStorage);
        // ★ (2026-10-29 02:36, 16차) 연결부터(화면 실행 sync_execute 와 같음) — 종전엔 connect() 를 안 불러 FTP·SMB 원본은 목록이 비어 '고아 삭제' 가 대상 전체를 지울 수 있었음
        if (!$srcAdapter || !$srcAdapter->connect()) throw new Exception("Source storage connect failed (src:$srcStorageId)");
        if (!$tgtAdapter || !$tgtAdapter->connect()) throw new Exception("Target storage connect failed (tgt:$tgtStorageId)");
        // ★ (2026-11-04) 56차 C7-B — 원격(FTP 등) 대상은 목록 시각이 분 단위·서버 시간대(pyftpdlib 은 GMT)라 실제보다 몇 시간 앞으로 읽혀, 단방향·증분이 실행마다 모든 파일을 'Updated' 로 다시 올렸음(재현).
        //   → 로컬 원본 → 원격 대상일 때만, 직전에 깨끗이 끝난 실행(오류·잠금 건너뜀·목록 실패·중지 없음)의 시작 시각을 기억해 대상 시각을 그 시각(5분 여유)보다 앞으로 보지 않음.
        //   그 뒤 바뀐 원본·크기가 다른 파일·새 파일은 종전대로 복사. 경로가 바뀌면(작업 수정) 기억값을 쓰지 않음. 로컬 대상·양방향은 종전 그대로
        $syBaseOn = ($mode === 'one-way' || $mode === 'incremental') && $srcAdapter instanceof LocalAdapter && !($tgtAdapter instanceof LocalAdapter);
        $syBaseSig = md5(json_encode([(int)$srcStorageId, (string)$srcPath, (int)$tgtStorageId, (string)$tgtPath, (bool)$includeSubdir]));
        $syBaseStart = time();
        $syBase = ($syBaseOn && is_array($task['sync_base'] ?? null) && (string)($task['sync_base']['sig'] ?? '') === $syBaseSig) ? max(0, (int)($task['sync_base']['start'] ?? 0) - 300) : 0;
        // ★ (2026-11-01 09:45, 38차 점검) 로컬 스토리지는 실제 경로(링크·정션을 따라간 realpath)가 스토리지 폴더 안인지도 확인 — '..' 없이도 스토리지 안의 링크로 밖을 가리키면
        //   스토리지 밖을 읽고 쓸 수 있었음(FileManager::isPathSafe 와 같은 기준). 아직 없는 폴더(첫 동기화의 대상)는 가장 가까운 있는 부모로 판정, realpath 를 못 얻으면 '..' 검사만으로 둠
        $syInRoot = function ($adapter, $rel) {
            if (!($adapter instanceof LocalAdapter)) return true;
            $root = realpath($adapter->getFullPath(''));
            if ($root === false) return true;
            $p = $adapter->getFullPath($rel);
            while (!file_exists($p) && dirname($p) !== $p) $p = dirname($p);
            $real = realpath($p);
            if ($real === false) return false;   // ★ (2026-11-01 10:14, 38차 점검 재검토) 실제 경로를 못 얻으면 거부(api.php fsSyncLocalInside 와 같게 — 종전 허용)
            $rootSep = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
            if (DIRECTORY_SEPARATOR === '\\') return strcasecmp(rtrim($real, '\\'), rtrim($root, '\\')) === 0 || stripos($real . '\\', $rootSep) === 0;
            return $real === $root || strpos($real . '/', $rootSep) === 0;
        };
        if (!$syInRoot($srcAdapter, $srcPath) || !$syInRoot($tgtAdapter, $tgtPath)) throw new Exception("Sync path is outside the storage folder: src='$srcPath', tgt='$tgtPath'");
        // ★ (2026-11-04, 55차 점검) 서로 다른 로컬 스토리지라도 실제 폴더(realpath — 없는 경로는 가장 가까운 있는 부모 + 남은 조각)가 겹치면 실행하지 않음(api.php fsSyncRealOverlap 과 같음, X-3).
        //   실제 경로를 못 얻으면 종전대로 진행, 원격은 그대로
        if ((int)$srcStorageId !== (int)$tgtStorageId && $srcAdapter instanceof LocalAdapter && $tgtAdapter instanceof LocalAdapter) {
            $syReal = function (LocalAdapter $ad, string $rel) {
                $p = $ad->getFullPath($rel);
                $rest = [];
                while (!file_exists($p) && dirname($p) !== $p) { array_unshift($rest, basename($p)); $p = dirname($p); }
                $rp = @realpath($p);
                if ($rp === false) return null;
                $full = rtrim(str_replace('\\', '/', $rp), '/') . ($rest ? '/' . implode('/', $rest) : '');
                if (PHP_OS_FAMILY === 'Windows') $full = mb_strtolower(implode('/', array_map(function ($s) { return rtrim($s, '. '); }, explode('/', $full))), 'UTF-8');
                return $full;
            };
            $syRa = $syReal($srcAdapter, $srcPath); $syRb = $syReal($tgtAdapter, $tgtPath);
            if ($syRa !== null && $syRb !== null && ($syRa === $syRb || ($includeSubdir && ($syRa === '' || $syRb === '' || strpos($syRa . '/', $syRb . '/') === 0 || strpos($syRb . '/', $syRa . '/') === 0)))) {
                throw new Exception('Source and target folders overlap');
            }
        }
        // ★ (2026-11-04 01:43, 54차 점검) 하위 항목도 같은 확인 — 로컬 스토리지 안의 링크·정션이 실제로 스토리지 밖을 가리키면(또는 끊긴 링크면) true. 위 확인은 작업 경로 맨 위만 봐서,
        //   하위의 링크를 따라 밖의 파일을 대상에 복사하고(읽기), 대상 쪽 링크를 따라 밖에 썼음(재현). 실제 경로를 못 얻는 보통 항목은 종전처럼 진행(false) — 링크가 없는 스토리지는 결과가 같음
        $syOutside = function ($adapter, $rel) use ($syInRoot) {
            if (!($adapter instanceof LocalAdapter)) return false;
            $p = $adapter->getFullPath($rel);
            if (is_link($p) && @realpath($p) === false) return true;
            while (!file_exists($p) && dirname($p) !== $p) $p = dirname($p);
            return @realpath($p) !== false && !$syInRoot($adapter, $rel);
        };
        $syLinkKeys = [];   // ★ (2026-11-04 01:43, 54차 점검) 위 이유로 목록에서 뺀 항목(작업 경로 기준 키) — 고아 삭제에서 그 항목·그 항목을 품은 폴더를 지우지 않음
        
        echo "  Adapters: src=" . get_class($srcAdapter) . ", tgt=" . get_class($tgtAdapter) . "\n";
        
        $stats = ['copied' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0];
        // ★ (2026-11-02 09:09, 41차 점검) 동기화가 쓰고 지운 것을 사용량(used_size)·검색 색인에 전혀 반영하지 않아, 원격 대상은 사용량(=색인 합계)과 색인 즉답 목록이
        //   수동 재구축 때까지 틀렸음(D5#4·D6#10) → 파일마다 크기 차이를 updateUsedSize(개인 폴더는 그 함수가 무시)로, 색인은 addFile·removeFile·removeFolder 로 고침.
        //   한도 확인은 하지 않음(관리 기능 — 결정 필요로 보고). 실패해도 동기화는 계속
        // ★ (2026-11-02 09:48, 41차 재검토) 사용량은 모았다가 끝에 스토리지별로 한 번만 반영(api.php sync_execute 와 같음 — 파일마다 storages.json 을 다시 쓰던 것)
        $syReflect = function (int $sid, string $op, string $rel, int $delta, int $newSize = 0, $modified = null, bool $isDir = false) use ($storage, &$syDelta) {
            try {
                if ($delta !== 0) $syDelta[$sid] = ($syDelta[$sid] ?? 0) + $delta;
                if (!class_exists('FileIndex')) return;
                $fi = FileIndex::getInstance();
                if (!$fi->isAvailable()) return;
                $rel = trim(str_replace('\\', '/', $rel), '/');
                if ($rel === '') return;
                if ($op === 'put') $fi->addFile($sid, $rel, ['size' => $newSize, 'modified' => $modified, 'is_dir' => $isDir ? 1 : 0]);
                elseif ($op === 'del') { if ($isDir) $fi->removeFolder($sid, $rel); else $fi->removeFile($sid, $rel); }
            } catch (\Throwable $e) {}
        };
        // ★ (2026-11-02 10:32, 42차 점검) 잠긴 파일(locked_files)을 덮어쓰고 지웠고, 고아 삭제한 경로의 공유 링크·내부 공유·숨김 표시를 남겼음(E1#5, api.php sync_execute 와 같은 처리)
        //   → 덮어쓸 대상(양방향 되쓰기는 원본)이 잠겼으면 건너뛰고 기록, 잠긴 파일은 고아 삭제도 안 함(그 파일을 품은 폴더도), 지운 경로는 삭제와 같은 정리.
        //   잠금이 없는 스토리지는 확인을 건너뜀. FileManager 는 필요할 때만 만들고, 못 만들면 종전처럼 진행
        $syLockSkip = [];   // ★ (2026-11-02 11:35, 43차 점검) 정방향에서 잠금으로 건너뛴 키(되쓰기에서도 건너뜀)
        $syLockSids = [];
        foreach (($db->load('locked_files') ?: []) as $syL) { $syLockSids[(int)($syL['storage_id'] ?? 0)] = true; }
        $syFm = null;
        $syGetFm = function () use (&$syFm) { if ($syFm === null) { try { $syFm = new FileManager(); } catch (\Throwable $e) { $syFm = false; } } return $syFm ?: null; };
        $syIsLocked = function (int $sid, string $rel) use ($syLockSids, $syGetFm) {
            if (empty($syLockSids[$sid])) return false;
            $fm = $syGetFm();
            if (!$fm) return false;
            try { return $fm->isFileLockedPublic($sid, $rel); } catch (\Throwable $e) { return false; }
        };
        $syCleanup = function (int $sid, string $rel, bool $isDir) use ($syGetFm) {
            $fm = $syGetFm();
            if ($fm) { try { $fm->cleanupSharesForPathPublic($sid, $rel, $isDir); } catch (\Throwable $e) {} }
            try { if (class_exists('HiddenItems')) HiddenItems::removePath($sid, $rel); } catch (\Throwable $e) {}
        };
        $syLockedKeys = [];
        // ★ (2026-11-11 08:05, 72차 RMT-1) 양방향 + 진짜 이름에 바로 쓰는 원격(FTP·SMB — 72차 재검토 반영으로 FTP 는 확장이 있어도 여기)에서 로컬 → 원격 쓰기가 실패한 키를 바로 저장하고, 다음 실행은 그 키만 로컬 쪽을 다시 보내며
        //   잘린 원격 → 로컬 방향은 하지 않음(api.php sync_execute 와 같음 — 그쪽 설명)
        $syRm = ($mode === 'two-way' && is_array($task['rmt_retry'] ?? null) && (string)($task['rmt_retry']['sig'] ?? '') === $syBaseSig && is_array($task['rmt_retry']['keys'] ?? null)) ? $task['rmt_retry']['keys'] : [];
        $syRmDone = []; $syRmDirty = false;
        $syRmNew = []; $syRmHad = !empty($task['rmt_retry']);   // ★ (2026-11-11 10:35, 72차 재검토 반영) 표시는 불러온 이번 실행 한 번만 씀 — 끝나면 모드·경로가 다른 실행은 이번에 새로 실패한 키만, 같은 양방향 실행은 못 본 키까지 남김(아래 끝 저장, 11:24 보완). 종전엔 경로·모드를 바꾼 동안 남았다가 되돌리면 다시 적용돼 원격의 진짜 수정을 덮었음(재검토 재현)
        foreach (array_merge(@glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . '~$fsdl-*-fs_sydl_*.tmp') ?: [], @glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_sydl_*.tmp') ?: []) as $syDlOld) { if (is_file($syDlOld) && @filemtime($syDlOld) < time() - 3600) @unlink($syDlOld); }   // ★ (2026-11-11 10:35, 72차 재검토 반영) RMT-4 흘려 받기 도중 PHP 가 죽으면 서버 임시 폴더에 받다 만 파일이 남았음 → share_*.zip 정리처럼 이 이름 형식만 1시간 지난 것을 지움
        $syRmSave = function () use (&$syRm, &$syRmDirty, $db, $task, $syBaseSig) { try { $db->update('sync_tasks', ['id' => $task['id']], ['rmt_retry' => $syRm ? ['sig' => $syBaseSig, 'keys' => $syRm] : null]); $syRmDirty = false; } catch (\Throwable $e) {} };
        
        // 재귀 파일 목록
        $syncFailed = [];   // ★ (2026-10-29 19:04, 22차 점검) 목록을 못 읽은 하위 폴더(양쪽) — 그 아래는 이번 동기화에서 비교·복사·삭제하지 않음
        $collectFiles = function($adapter, $basePath, $includeSubdir, $rootPath = null) use (&$collectFiles, &$syncFailed, $syOutside, &$syLinkKeys) {   // ★ (2026-11-04 01:43, 54차 점검) 링크 건너뛰기용 두 값 추가
            if ($rootPath === null) $rootPath = $basePath;
            $files = [];
            $items = $adapter->list($basePath);
            // ★ (2026-10-29 18:52, 22차 점검) 목록을 못 읽은 폴더를 빈 폴더로 보지 않음 — 종전엔 빈 폴더로 보고 계속해, '고아 삭제'가 켜져 있으면 대상의 그 폴더 아래를 지우고
            //   양방향이면 대상 쪽으로 덮어쓸 수 있었음(16차의 '원본이 통째로 비면 삭제 건너뜀'은 맨 위만 막았음)
            //   (없는 폴더 — 첫 동기화의 대상 등 — 는 원격 어댑터가 실패로 표시하므로 exists 로 다시 보고 빈 목록으로)
            if (is_object($adapter) && property_exists($adapter, 'listFailed') && $adapter->listFailed) {
                // ★ (2026-10-29 19:04, 22차 점검) 맨 위(작업 경로)를 못 읽으면 전체 중단, 하위 폴더 하나면 그 폴더만 이번에 건너뜀(변경 검토 반영 — 'System Volume Information' 같은 읽기 금지 폴더 때문에 전체가 멈추지 않게)
                $sfExists = true;
                try { $sfExists = ($basePath === '') || $adapter->exists($basePath); } catch (\Throwable $e) { $sfExists = true; }
                if ($sfExists) {
                    if ($basePath === $rootPath) throw new Exception(__('remote_read_failed', '원격 파일 읽기 실패') . ': ' . ($basePath === '' ? '/' : $basePath));
                    $syncFailed[] = $rootPath === '' ? $basePath : substr($basePath, strlen($rootPath) + 1);
                }
                return [];
            }
            foreach ($items as $item) {
                // ★ (2026-11-01 17:18, 40차 점검) 목록 이름을 검사 없이 이어 붙여 원격이 '..'·'/' 든 이름을 주면 대상 스토리지 밖에 썼음 → api.php sync_execute·copyRemoteDirToLocal 과 같은 규칙으로 건너뜀
                $syName = (string)($item['name'] ?? '');
                if ($syName === '' || $syName === '.' || $syName === '..' || strpbrk($syName, DIRECTORY_SEPARATOR === '\\' ? "/\\\0" : "/\0") !== false
                    || (DIRECTORY_SEPARATOR === '\\' && trim($syName, '. ') === '')) continue;
                if (strncmp($syName, '.~fssy-', 7) === 0) continue;   // ★ (2026-11-10 17:02, 66차 점검) 복사 중 강제 종료로 남은 동기화 임시 파일(.~fssy-, LocalAdapter::copyFileTo)을 양방향이 반대쪽으로 되썼음 → 건너뜀
                $relPath = ($basePath !== '') ? $basePath . '/' . $item['name'] : $item['name'];
                // ★ (2026-11-04 01:43, 54차 점검) 스토리지 밖을 가리키는 링크·정션(끊긴 링크 포함)은 목록에서 뺌(위 $syOutside) — 종전엔 따라가 밖의 파일을 대상에 진짜 파일로 복사했음(재현: src/lnk → 밖 secret.txt)
                if ($syOutside($adapter, $relPath)) {
                    echo "  [SKIP-LINK] $relPath\n";
                    $syLinkKeys[] = ($rootPath === '') ? $relPath : substr($relPath, strlen($rootPath) + 1);
                    continue;
                }
                if ($item['is_dir']) {
                    if ($includeSubdir) {
                        $files = array_merge($files, $collectFiles($adapter, $relPath, true, $rootPath));
                        $files[] = ['path' => $relPath, 'is_dir' => true, 'modified' => $item['modified'] ?? 0, 'size' => 0];
                    }
                } else {
                    $files[] = ['path' => $relPath, 'is_dir' => false, 'modified' => $item['modified'] ?? 0, 'size' => $item['size'] ?? 0];
                }
            }
            return $files;
        };
        
        echo "  Collecting source files...\n";
        $srcFiles = $collectFiles($srcAdapter, $srcPath, $includeSubdir);
        echo "  Source: " . count($srcFiles) . " files\n";
        echo "  Collecting target files...\n";
        $tgtFiles = $collectFiles($tgtAdapter, $tgtPath, $includeSubdir);
        echo "  Target: " . count($tgtFiles) . " files\n";
        
        // 인덱스
        $srcIndex = [];
        foreach ($srcFiles as $f) {
            $key = ($srcPath !== '') ? substr($f['path'], strlen($srcPath) + 1) : $f['path'];
            $srcIndex[$key] = $f;
        }
        $tgtIndex = [];
        foreach ($tgtFiles as $f) {
            $key = ($tgtPath !== '') ? substr($f['path'], strlen($tgtPath) + 1) : $f['path'];
            $tgtIndex[$key] = $f;
        }
        // ★ (2026-11-17 11:18, 317차 — 296차 펜닐 결정 "보호 파일 지우기 막기"를 동기화에도) 어느 쪽이든 스토리지 맨 위 .htaccess 에 해당하는 항목은 양쪽 목록에서 뺌 — 종전엔 다른 스토리지 맨 위로
        //   '남는 파일 지우기' 동기화를 하면 대상의 보호 파일이 '[DEL] .htaccess' 로 지워졌고, 원본 폴더에 .htaccess 가 있으면 대상 보호 파일을 덮어썼음(재현: 'Options +Indexes' 로 바뀜). 판정은 웹·내부 공유와 같은 FileManager 함수(로컬 맨 위 .htaccess 만)
        try {
            $syPfm = new FileManager();
            foreach (array_unique(array_merge(array_keys($srcIndex), array_keys($tgtIndex))) as $syPk) {
                $syPk = (string)$syPk;
                if ($syPfm->isStorageProtectFilePublic((int)$srcStorageId, ($srcPath !== '' ? $srcPath . '/' : '') . $syPk) || $syPfm->isStorageProtectFilePublic((int)$tgtStorageId, ($tgtPath !== '' ? $tgtPath . '/' : '') . $syPk)) { unset($srcIndex[$syPk], $tgtIndex[$syPk]); }
            }
        } catch (\Throwable $e) {}
        // ★ (2026-10-29 19:04, 22차 점검) 목록을 못 읽은 하위 폴더 아래는 양쪽 모두 이번에 빼고 진행(그 폴더를 빈 것으로 보고 지우거나 덮어쓰지 않게)
        foreach (array_unique($syncFailed) as $sfKey) {
            foreach ([&$srcIndex, &$tgtIndex] as &$sfIdx) {
                foreach (array_keys($sfIdx) as $k) { if ($k === $sfKey || strpos($k, $sfKey . '/') === 0) unset($sfIdx[$k]); }
            }
            unset($sfIdx);
        }
        // ★ (2026-11-09 15:36, 59차 F1) 양방향 + FTP: 확인된 FTP 서버만 양쪽에 다 있고 크기·시각이 다른 파일의 FTP 시각을 MDTM(UTC·초)으로(api.php sync_execute 와 같음 — 그쪽 설명)
        $syMdSkew = [];
        if ($mode === 'two-way') {
            $syMdCal = is_array($task['ftp_mdtm'] ?? null) ? $task['ftp_mdtm'] : [];
            $syMdPlan = []; $syMdOk = true;
            foreach ([['s', $srcAdapter, (int)$srcStorageId], ['t', $tgtAdapter, (int)$tgtStorageId]] as [$syMdSide, $syMdAd, $syMdSid]) {
                if (!($syMdAd instanceof FtpAdapter)) continue;
                $syMdC = $syMdCal[(string)$syMdSid] ?? null;
                if (!is_array($syMdC) || (string)($syMdC['sig'] ?? '') !== $syMdAd->syncMdtmSig() || abs((int)($syMdC['skew'] ?? 99999)) > 120) { $syMdOk = false; break; }
            }
            if ($syMdOk) { try { $syMdPlan = FileManager::syncTwoWayFtpPlan($srcAdapter, $tgtAdapter, $srcIndex, $tgtIndex); } catch (\Throwable $e) { $syMdPlan = null; } if (!is_array($syMdPlan)) $syMdOk = false; }   // ★ (2026-11-09 16:58, 59차 F1 보완) 꼭 필요한 파일만 MDTM(api.php 와 같음)
            if ($syMdOk) { foreach ($syMdPlan as $syMdSide => $syMdR) { foreach ($syMdR as $k => $syMdT) { if ($syMdSide === 's') { $srcIndex[$k]['touch'] = $srcIndex[$k]['modified']; $srcIndex[$k]['modified'] = $syMdT; } else { $tgtIndex[$k]['touch'] = $tgtIndex[$k]['modified']; $tgtIndex[$k]['modified'] = $syMdT; } } } }   // ★ (2026-11-09 18:04, 59차 F1 재보완) 원래 목록 시각을 'touch' 로 남김(받은 로컬 시각 맞춤용)
        }
        // ★ (2026-11-10 07:22, 61차) 대소문자만 다른 이름(소문자로 같은 키 묶음) — api.php sync_execute 와 같음(그쪽 설명). ① 양방향: 한쪽 둘 이상·다른 쪽 하나뿐이고 그쪽이 다른 이름도 찾으면 그 묶음은 이번에 건너뜀
        //   ② 단방향·증분 + 고아 삭제: 대상의 대소문자만 다른 하나뿐인 파일을 대상이 새 이름으로도 찾으면 같은 파일로 비교하고 옛 이름은 고아 삭제에서 남김, 못 찾거나 새 이름이 이미 따로 있으면(구분) 종전 그대로. ★ (2026-11-10 07:42, 61차) 확인이 안 되는 경우만 이번에 복사한 키와 소문자로 같은 옛 이름을 남김
        $syLow = function ($k) { return mb_strtolower((string)$k, 'UTF-8'); };
        $syCaseSkip = []; $syCaseEq = []; $syCaseKeep = []; $syCaseKeepKeys = []; $syCaseCs = [];
        $syCaseEq2 = [];   // ★ (2026-11-10 14:33, 64차) 양방향 1:1 — 원본 키 → 대상 실제 이름(api.php 와 같음)
        if ($mode === 'two-way' || $mode === 'one-way' || $mode === 'incremental') {   // ★ (2026-11-10 14:33, 64차) ② '같은 파일로 비교'는 고아 삭제와 상관없이(api.php 와 같음 — 종전엔 고아 삭제가 꺼지면 매 실행 다시 복사·사용량 증가, 재현). 옛 이름 지우기는 고아 삭제 조건 그대로
            $syCsS = []; $syCsT = [];
            foreach ($srcIndex as $k => $f) { if (!$f['is_dir']) $syCsS[$syLow($k)][] = (string)$k; }
            foreach ($tgtIndex as $k => $f) { if (!$f['is_dir']) $syCsT[$syLow($k)][] = (string)$k; }
            foreach ($syCsS as $syCsL => $syCsSk) {
                $syCsTk = $syCsT[$syCsL] ?? [];
                if ($mode === 'two-way') {
                    if (count($syCsSk) === 1 && count($syCsTk) === 1 && $syCsSk[0] !== $syCsTk[0]) { try { if (!($srcAdapter instanceof FtpAdapter) && !($tgtAdapter instanceof FtpAdapter) && $tgtAdapter->exists(($tgtPath !== '') ? $tgtPath . '/' . $syCsSk[0] : $syCsSk[0]) && $srcAdapter->exists(($srcPath !== '') ? $srcPath . '/' . $syCsTk[0] : $syCsTk[0])) $syCaseEq2[$syCsSk[0]] = $syCsTk[0]; } catch (\Throwable $e) {} continue; }   // ★ (2026-11-10 14:33, 64차) 양방향 1:1·이름 대소문자만 다름: 두 쪽이 서로의 이름을 다 찾으면(양쪽 다 무시 = 같은 파일) 같은 키로 봄(api.php 와 같음 — 종전엔 대상 쪽 수정이 양쪽에서 사라지고 매 실행 왕복, 재현). 한쪽이라도 못 찾으면 종전 그대로  ★ (2026-11-10 15:24, 64차 보완) 한쪽이라도 FTP 면 같은 키로 보지 않음(종전 그대로) — FTP 시각은 59차 계획(MDTM 보정)이 이 재키보다 앞에서 같은 키 파일만 고쳐, 이 쌍은 보정 안 된 LIST 시각(UTC 서버면 9시간 옛것)으로 비교돼 FTP 쪽의 더 새 수정이 옛 로컬 내용으로 덮였음(재검토 재현)
                    if (!((count($syCsSk) >= 2 && count($syCsTk) === 1) || (count($syCsTk) >= 2 && count($syCsSk) === 1))) continue;
                    [$syCsOne, $syCsMany, $syCsAd, $syCsBase] = (count($syCsSk) === 1) ? [$syCsSk[0], $syCsTk, $srcAdapter, $srcPath] : [$syCsTk[0], $syCsSk, $tgtAdapter, $tgtPath];
                    foreach ($syCsMany as $syCsK) {
                        if ($syCsK === $syCsOne) continue;
                        try { $syCsHit = $syCsAd->exists(($syCsBase !== '') ? $syCsBase . '/' . $syCsK : $syCsK); } catch (\Throwable $e) { $syCsHit = true; }
                        if ($syCsHit) { $syCaseSkip[$syCsL] = true; break; }
                    }
                } elseif (count($syCsSk) === 1 && count($syCsTk) === 1 && $syCsSk[0] !== $syCsTk[0]) {
                    try { if ($tgtAdapter->exists(($tgtPath !== '') ? $tgtPath . '/' . $syCsSk[0] : $syCsSk[0])) $syCaseEq[$syCsSk[0]] = $syCsTk[0]; else $syCaseCs[$syCsSk[0]] = true; } catch (\Throwable $e) {}
                }
            }
        }
        foreach ($syCaseEq2 as $syEqS => $syEqT) { $tgtIndex[$syEqS] = $tgtIndex[$syEqT]; unset($tgtIndex[$syEqT]); }   // ★ (2026-11-10 14:33, 64차) 위 양방향 1:1 — 대상 항목을 원본 키로 옮겨 종전 양방향 판정·되쓰기가 같은 키로 돎(api.php 와 같음)
        $syDirLow = []; foreach ($tgtIndex as $k => $f) { if ($f['is_dir']) $syDirLow[$syLow($k)] = (string)$k; }   // ★ (2026-11-10 14:33, 64차) 대상 폴더의 소문자 키 → 실제 이름(아래 폴더 만들기의 유령 색인 행 방지용)
        
        // 디렉토리 생성
        // ★ (2026-11-04, 55차 점검) 대상 작업 폴더가 아직 없으면 조각마다 차례로 먼저 만들고 색인(api.php sync_execute 와 같음, X-2 — WebDAV 대상 전부 실패·FTP 대상 폴더 행 누락). 대상 목록이 비었을 때만 확인
        if ($tgtPath !== '' && empty($tgtIndex) && !$syOutside($tgtAdapter, $tgtPath)) {
            $syAcc = '';
            foreach (explode('/', $tgtPath) as $sySeg) {
                $syAcc = ($syAcc === '') ? $sySeg : $syAcc . '/' . $sySeg;
                try {
                    if (!$tgtAdapter->exists($syAcc) && ($tgtAdapter->mkdir($syAcc) !== false || $tgtAdapter->exists($syAcc))) {
                        $syReflect((int)$tgtStorageId, 'put', $syAcc, 0, 0, null, true);
                        echo "  [DIR+] /$syAcc\n";
                    }
                } catch (\Throwable $e) {}
            }
        }
        // ★ (2026-11-04, 55차 점검) 부모 폴더부터 만들게 깊이('/' 개수) 오름차순(api.php sync_execute 와 같음, X-2)
        $syMkOrder = $srcIndex;
        uksort($syMkOrder, function ($a, $b) { $a = (string)$a; $b = (string)$b; return (substr_count($a, '/') <=> substr_count($b, '/')) ?: strcmp($a, $b); });
        foreach ($syMkOrder as $key => $sf) {
            if ($sf['is_dir'] && !isset($tgtIndex[$key])) {
                try {
                    $syMk = ($tgtPath !== '') ? $tgtPath . '/' . $key : $key;
                    if ($syOutside($tgtAdapter, $syMk)) { echo "  [SKIP-LINK] $key\n"; continue; }   // ★ (2026-11-04 01:43, 54차 점검) 대상 쪽 링크를 따라 밖에 폴더를 만들지 않음
                    if (isset($syDirLow[$syLow($key)])) { if ($tgtAdapter->mkdir($syMk) !== false) $syReflect((int)$tgtStorageId, 'put', $syMk, 0, 0, $sf['modified'] ?? null, true); echo "  [DIR+] $key\n"; continue; }   // ★ (2026-11-10 14:33, 64차) 대상에 대소문자만 다른 폴더가 있으면 만들기가 실패했을 때(무시 대상 — 같은 폴더) 색인 행을 넣지 않음(api.php 와 같음 — 종전엔 유령 행이 영영 남았음, 재현). 만들어지면 종전처럼
                    if ($tgtAdapter->mkdir($syMk) !== false || $tgtAdapter->exists($syMk)) $syReflect((int)$tgtStorageId, 'put', $syMk, 0, 0, $sf['modified'] ?? null, true);   // ★ (2026-11-02 09:09, 41차 점검) 색인에 새 폴더
                    echo "  [DIR+] $key\n";
                } catch (Exception $e) {}
            }
        }
        
        // 파일 복사/업데이트
        foreach ($srcIndex as $key => $sf) {
            if ($sf['is_dir']) continue;
            $needCopy = false;
            $action = 'copy';
            
            if (!isset($tgtIndex[$key])) {
                $needCopy = true;
            } elseif ($mode === 'incremental' || $mode === 'one-way') {
                $tf = $tgtIndex[$key];
                if ($sf['size'] != $tf['size'] || $sf['modified'] > ($syBase > 0 ? max($tf['modified'], $syBase) : $tf['modified'])) {   // ★ (2026-11-04) 56차 C7-B 위 $syBase 주석
                    $needCopy = true;
                    $action = 'update';
                }
            } elseif ($mode === 'two-way') {
                $tf = $tgtIndex[$key];
                if ($sf['modified'] > $tf['modified']) {
                    $needCopy = true;
                    $action = 'update';
                }
            }
            if (isset($syCaseEq[$key])) { $tf = $tgtIndex[$syCaseEq[$key]]; $needCopy = ($sf['size'] != $tf['size'] || $sf['modified'] > ($syBase > 0 ? max($tf['modified'], $syBase) : $tf['modified'])); $action = 'update'; $syCaseKeep[$syLow($key)] = true; }   // ★ (2026-11-10 07:22, 61차) 위 ② — 대소문자만 다른 대상 파일과 같은 기준으로 비교
            if ($syRm && isset($syRm[$key])) { if ($syRm[$key] === 't') { $needCopy = true; $action = isset($tgtIndex[$key]) ? 'update' : 'copy'; } else { $needCopy = false; } }   // ★ (2026-11-11 08:05, 72차 RMT-1) 위 표시(api.php 와 같음)
            if (isset($syCaseSkip[$syLow($key)])) { echo "  [SKIP-CASE] $key\n"; $needCopy = false; }   // ★ (2026-11-10 07:22, 61차) 위 ① — 이 묶음은 이번에 복사하지 않음
            if ($needCopy /* ★ (2026-11-02 10:58, 42차 재검토) 대상에 같은 키가 없어도 확인 — Windows 는 'A.txt'·잠긴 'a.txt' 가 같은 파일인데 isset 이 거짓이라 덮어썼음(검토 발견) */ && $syIsLocked((int)$tgtStorageId, ($tgtPath !== '') ? $tgtPath . '/' . $key : $key)) {   // ★ (2026-11-02 10:32, 42차 점검) 잠긴 대상은 덮어쓰지 않음(위 설명)
                echo "  [LOCKED] $key\n";
                $needCopy = false;
                $syLockSkip[(DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($key) : $key)] = true;   // ★ (2026-11-02 11:35, 43차 점검) 아래 되쓰기에서 이 키는 건너뜀
            }
            if ($needCopy && $syOutside($tgtAdapter, ($tgtPath !== '') ? $tgtPath . '/' . $key : $key)) {   // ★ (2026-11-04 01:43, 54차 점검) 대상 쪽 링크를 따라 스토리지 밖에 쓰지 않음 — 종전엔 dst/lnk2 → 밖 이면 밖에 w.txt 를 만들었음(재현)
                echo "  [SKIP-LINK] $key\n";
                $needCopy = false;
            }
            if ($needCopy && !isset($tgtIndex[$key]) && !isset($syCaseCs[$key])) $syCaseKeep[$syLow($key)] = true;   // ★ (2026-11-10 07:22, 61차) 위 ② — 이번에 복사하는 키와 소문자로 같은 대상 옛 이름은 이번 고아 삭제에서 남김. ★ (2026-11-10 07:42, 61차) 대상이 새 이름을 이미 따로 갖고 있거나 못 찾은(구분) 경우는 빼고 — 매 실행 다시 올리는 구분 대상(FTP 등)에서 옛 이름이 영영 안 지워졌음(재현)

            if ($needCopy) {
                try {
                    $srcFullPath = $sf['path'];
                    $tgtFullPath = ($tgtPath !== '') ? $tgtPath . '/' . $key : $key;
                    $fileSize = $sf['size'] ?? 0;
                    $sizeMB = round($fileSize / 1024 / 1024, 1);
                    
                    $copyOk = false;
                    // ★ (2026-11-14 12:29, 127차 펜닐 결정 '동기화도 버전 남기기') 대상이 로컬이고 이미 있는 파일을 덮을 때 덮기 전 판을 버전으로 — 종전엔 동기화만 버전을 남기지 않았음(업로드·복사·편집 등은 남김). 버전 관리 설정·제외 확장자는 다른 경로와 같음 — ★ (2026-11-14 16:42, 128차) 가장 최근 버전과 같은 내용이면 남기지 않음(saveFileVersionIfChanged — 복사 실패가 되풀이될 때 옛 버전이 밀려나던 것)
                    if ($tgtAdapter instanceof LocalAdapter && ($syVerP = isset($tgtIndex[$key]) ? (string)$tgtIndex[$key]['path'] : (isset($syCaseEq[$key]) ? (string)$tgtIndex[$syCaseEq[$key]]['path'] : '')) !== '') { try { if ($syVerFm = $syGetFm()) $syVerFm->saveFileVersionIfChanged($tgtAdapter->getFullPath($syVerP), trim(str_replace('\\', '/', $syVerP), '/'), (int)$tgtStorageId); } catch (\Throwable $e) {} }
                    if ($srcAdapter instanceof LocalAdapter && method_exists($srcAdapter, 'copyFileTo')) {
                        $syWrTo = $tgtFullPath;   // ★ (2026-11-11 08:05, 72차 RMT-1) 원격 대상이 대소문자만 다른 같은 파일이면 실제 이름에 씀(api.php 와 같음 — 임시 이름 + rename 이 기존 이름을 바꾸지 않게)
                        if (!($tgtAdapter instanceof LocalAdapter)) { if (isset($syCaseEq[$key])) $syWrTo = (string)$tgtIndex[$syCaseEq[$key]]['path']; elseif (isset($syCaseEq2[$key])) $syWrTo = (string)$tgtIndex[$key]['path']; }
                        $copyOk = $srcAdapter->copyFileTo($srcFullPath, $tgtAdapter, $syWrTo);
                    } elseif ($tgtAdapter instanceof LocalAdapter && method_exists($srcAdapter, 'streamToOutput') && ($syDlFm = $syGetFm()) && ($syDlGot = $syDlFm->rcFetchToFilePublic($srcAdapter, $srcFullPath, ($syDlTmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_sydl_' . bin2hex(random_bytes(8)) . '.tmp'), (int)($sf['size'] ?? 0))) !== null) {
                        // ★ (2026-11-11 08:39, 72차 RMT-4) 원격 원본 → 로컬 대상은 서버 임시 파일에 흘려 받은 뒤 copyFileTo 로(api.php sync_execute 와 같음 — 종전 read() 는 파일 전체를 메모리에, WebDAV·S3 는 30초 넘으면 실패).
                        //   임시 파일은 성공·실패 모두 지움. FileManager 를 못 만들거나 흘려 받기가 없거나 실패하면 아래 종전 read()
                        try { $copyOk = (new LocalAdapter(dirname($syDlTmp)))->copyFileTo(basename($syDlTmp), $tgtAdapter, $tgtFullPath); } finally { @unlink($syDlTmp); }
                    } else {
                        $content = $srcAdapter->read($srcFullPath);
                        if ($content === false || strlen((string)$content) < (int)($sf['size'] ?? 0)) throw new Exception('Read failed');   // ★ (2026-11-01 17:30, 40차 점검) 연결이 중간에 끊겨 앞부분만 온 내용(SMB·SFTP ssh2 는 false 대신 받은 만큼 돌려줌)도 '성공'으로 대상을 잘린 파일로 덮어썼음 → 목록 크기보다 짧으면 실패(39차 copyRemoteToLocal 과 같은 기준)   // ★ (2026-10-29 09:23, 20차) 원격 read() 는 실패해도 '' 를 돌려줌 — 내용이 비었는데 목록상 크기가 0 보다 크면 읽기 실패로(종전엔 대상 파일을 빈 파일로 덮어썼음)
                        $copyOk = $tgtAdapter->write($tgtFullPath, $content);
                        unset($content);
                    }
                    
                    if (!$copyOk) throw new Exception('Copy/write failed');
                    if (isset($syRm[$key])) $syRmDone[$key] = true;   // ★ (2026-11-11 08:05, 72차 RMT-1) 다시 보냈음 — 표시는 되쓰기를 지난 뒤 지움(api.php 와 같음)
                    if ($mode === 'two-way' && $tgtAdapter instanceof FtpAdapter && !isset($syMdSkew[(int)$tgtStorageId])) { try { $syMdT = $tgtAdapter->syncMdtm($tgtFullPath); if ($syMdT > 0) $syMdSkew[(int)$tgtStorageId] = $syMdT - time(); } catch (\Throwable $e) {} }   // ★ (2026-11-09 15:36, 59차 F1) 방금 쓴 파일의 MDTM 과 지금의 차이(위 확인값)
                    // ★ (2026-11-04 01:43, 54차 점검) 양방향만: 로컬 대상은 받은 파일의 수정 시각을 원본 시각으로 맞춤 — 종전엔 복사 시각이 돼 다음 실행에서 거꾸로 '더 새것'으로 보여 바뀌지 않은 파일을 실행마다 왕복 복사했음(재현). 단방향·증분은 종전 그대로
                    if ($mode === 'two-way' && $tgtAdapter instanceof LocalAdapter && is_numeric($sf['modified'] ?? null) && (int)$sf['modified'] > 0) @touch($tgtAdapter->getFullPath($tgtFullPath), (int)($sf['touch'] ?? $sf['modified']));   // ★ (2026-11-09 18:04, 59차 F1 재보완) 계획으로 비교 시각을 바꿨으면 원래 목록 시각('touch')으로 맞춤(원본과 같은 기준)
                    $syNew = (int)($sf['size'] ?? 0);   // ★ (2026-11-02 09:09, 41차 점검) 대상 사용량·색인 반영(덮어쓰면 옛 크기를 뺌)
                    $syReflect((int)$tgtStorageId, 'put', $tgtFullPath, $syNew - (isset($tgtIndex[$key]) ? (int)($tgtIndex[$key]['size'] ?? 0) : 0), $syNew, ($tgtAdapter instanceof LocalAdapter) ? ($sf['touch'] ?? $sf['modified'] ?? null) : ($sf['modified'] ?? null));   // ★ (2026-11-09 19:41, 60차 Z15) 받는 쪽이 로컬이면 색인 시각도 위 touch 와 같은 값(디스크와 같게 — 종전엔 계획의 MDTM 이 들어가 색인 즉답 목록 시각이 디스크와 달랐음, 재현). 판정은 그대로
                    if (isset($syCaseEq[$key])) { $syReflect((int)$tgtStorageId, 'del', $tgtFullPath, -(int)($tgtIndex[$syCaseEq[$key]]['size'] ?? 0)); $syReflect((int)$tgtStorageId, 'put', (string)$tgtIndex[$syCaseEq[$key]]['path'], 0, $syNew, ($tgtAdapter instanceof LocalAdapter) ? ($sf['touch'] ?? $sf['modified'] ?? null) : ($sf['modified'] ?? null)); }   // ★ (2026-11-10 07:22, 61차) 위 ② — 같은 파일을 덮었으니 옛 크기를 사용량에서 뺌. ★ (2026-11-10 08:29, 61차 C-1) 색인도 대상의 실제 이름 행(옛 대소문자)에 새 크기·시각을 넣고 새 이름 행은 지움(api.php 와 같음 — 종전엔 두 행이 영영 남았음, 재현)
                    if (isset($syCaseEq2[$key])) { $syReflect((int)$tgtStorageId, 'del', $tgtFullPath, 0); $syReflect((int)$tgtStorageId, 'put', (string)$tgtIndex[$key]['path'], 0, $syNew, ($tgtAdapter instanceof LocalAdapter) ? ($sf['touch'] ?? $sf['modified'] ?? null) : ($sf['modified'] ?? null)); }   // ★ (2026-11-10 14:33, 64차) 양방향 1:1(위 재키) — 색인은 대상 실제 이름 행에(api.php 와 같음)
                    if ($mode !== 'two-way' && $tgtAdapter instanceof LocalAdapter) { $syIxP = isset($syCaseEq[$key]) ? (string)$tgtIndex[$syCaseEq[$key]]['path'] : $tgtFullPath; $syIxF = $tgtAdapter->getFullPath($syIxP); clearstatcache(true, $syIxF); $syIxM = @filemtime($syIxF); if ($syIxM) $syReflect((int)$tgtStorageId, 'put', $syIxP, 0, $syNew, $syIxM); }   // ★ (2026-11-10 14:33, 64차) 단방향·증분으로 받은 로컬 대상은 색인 시각을 실제 디스크 시각으로(api.php 와 같음 — 종전엔 원본 시각, 재현). 판정·디스크 시각은 그대로

                    echo "  [" . strtoupper($action) . "] $key ({$sizeMB}MB)\n";
                    $stats[$action === 'copy' ? 'copied' : 'updated']++;
                } catch (Exception $e) {
                    echo "  [ERR] $key: " . $e->getMessage() . "\n";
                    $stats['errors']++;
                    if ($mode === 'two-way' && $srcAdapter instanceof LocalAdapter && LocalAdapter::syncRemoteDirect($tgtAdapter)) { $syRm[$key] = 't'; $syRmNew[$key] = 't'; unset($syRmDone[$key]); $syRmSave(); }   // ★ (2026-11-11 08:05, 72차 RMT-1) 위 표시 — 바로 저장
                }
            } else {
                $stats['skipped']++;
            }
        }
        
        // 양방향: 대상 → 원본
        if ($mode === 'two-way') {
            foreach ($tgtIndex as $key => $tf) {
                if ($tf['is_dir']) continue;
                $needReverse = false;
                $reverseAction = 'copy';
                
                if (!isset($srcIndex[$key])) {
                    $needReverse = true;
                } elseif ($tf['modified'] > $srcIndex[$key]['modified']) {
                    $needReverse = true;
                    $reverseAction = 'update';
                }
                if ($syRm && isset($syRm[$key])) { if ($syRm[$key] === 's') { $needReverse = true; $reverseAction = isset($srcIndex[$key]) ? 'update' : 'copy'; } else { $needReverse = false; } }   // ★ (2026-11-11 08:05, 72차 RMT-1) 위 표시(api.php 와 같음)
                // ★ (2026-11-02 11:35, 43차 점검) 정방향에서 잠긴 대상 때문에 건너뛴 키는 되쓰기도 건너뜀(api.php sync_execute 와 같음 — Windows 대소문자만 다른 경우 원본 새 내용이 옛 내용으로 덮였음)
                if ($needReverse && !empty($syLockSkip[(DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($key) : $key)])) { $needReverse = false; }
                if ($needReverse && isset($syCaseSkip[$syLow($key)])) { echo "  [SKIP-CASE←] $key\n"; $stats['skipped']++; $needReverse = false; }   // ★ (2026-11-10 07:22, 61차) 위 ① — 이 묶음은 되쓰기도 건너뜀
                if ($needReverse /* ★ (2026-11-02 10:58, 42차 재검토) 대상에 같은 키가 없어도 확인 — Windows 는 'A.txt'·잠긴 'a.txt' 가 같은 파일인데 isset 이 거짓이라 덮어썼음(검토 발견) */ && $syIsLocked((int)$srcStorageId, ($srcPath !== '') ? $srcPath . '/' . $key : $key)) {   // ★ (2026-11-02 10:32, 42차 점검) 양방향 되쓰기도 잠긴 원본은 덮어쓰지 않음
                    echo "  [LOCKED←] $key\n";
                    $stats['skipped']++;
                    $needReverse = false;
                }
                if ($needReverse && $syOutside($srcAdapter, ($srcPath !== '') ? $srcPath . '/' . $key : $key)) {   // ★ (2026-11-04 01:43, 54차 점검) 되쓰기도 원본 쪽 링크를 따라 스토리지 밖에 쓰지 않음(위 정방향과 같음)
                    echo "  [SKIP-LINK←] $key\n";
                    $stats['skipped']++;
                    $needReverse = false;
                }
                
                if ($needReverse) {
                    try {
                        $tgtFullPath = $tf['path'];
                        $srcFullPath = ($srcPath !== '') ? $srcPath . '/' . $key : $key;
                        
                        // ★ (2026-11-09 14:24, 58차 R) 원본 쪽에 없는 상위 폴더를 먼저 만듦(api.php sync_execute 와 같음 — 종전엔 SFTP·WebDAV·로컬 원본에서 새 폴더 안 파일 되쓰기가 매번 실패, 재현)
                        $syRvDir = dirname(str_replace('\\', '/', (string)$key));
                        if ($syRvDir !== '.' && $syRvDir !== '' && !isset($srcIndex[$syRvDir])) {
                            $syRvAcc = '';
                            foreach (explode('/', $syRvDir) as $syRvSeg) {
                                $syRvAcc = ($syRvAcc === '') ? $syRvSeg : $syRvAcc . '/' . $syRvSeg;
                                if (isset($srcIndex[$syRvAcc])) continue;
                                $syRvFull = ($srcPath !== '') ? $srcPath . '/' . $syRvAcc : $syRvAcc;
                                try { if (!$srcAdapter->exists($syRvFull)) $srcAdapter->mkdir($syRvFull); } catch (\Throwable $e) {}
                            }
                        }
                        $copyOk = false;
                        // ★ (2026-11-14 12:29, 127차 펜닐 결정 '동기화도 버전 남기기') 되쓰기(원본 쪽 덮기)도 원본이 로컬이고 이미 있는 파일이면 덮기 전 판을 버전으로(위 정방향과 같음)
                        if ($srcAdapter instanceof LocalAdapter && isset($srcIndex[$key])) { try { if ($syVerFm = $syGetFm()) $syVerFm->saveFileVersionIfChanged($srcAdapter->getFullPath($srcFullPath), trim(str_replace('\\', '/', $srcFullPath), '/'), (int)$srcStorageId); } catch (\Throwable $e) {} }
                        if ($tgtAdapter instanceof LocalAdapter && method_exists($tgtAdapter, 'copyFileTo')) {
                            $copyOk = $tgtAdapter->copyFileTo($tgtFullPath, $srcAdapter, $srcFullPath);
                        } elseif ($srcAdapter instanceof LocalAdapter && method_exists($tgtAdapter, 'streamToOutput') && ($syDlFm = $syGetFm()) && ($syDlGot = $syDlFm->rcFetchToFilePublic($tgtAdapter, $tgtFullPath, ($syDlTmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_sydl_' . bin2hex(random_bytes(8)) . '.tmp'), (int)($tf['size'] ?? 0))) !== null) {
                            // ★ (2026-11-11 08:39, 72차 RMT-4) 되쓰기도 원격 대상 → 로컬 원본이면 흘려 받은 임시 파일을 copyFileTo 로(위 정방향과 같음). 임시 파일은 성공·실패 모두 지움
                            try { $copyOk = (new LocalAdapter(dirname($syDlTmp)))->copyFileTo(basename($syDlTmp), $srcAdapter, $srcFullPath); } finally { @unlink($syDlTmp); }
                        } else {
                            $content = $tgtAdapter->read($tgtFullPath);
                            if ($content === false || strlen((string)$content) < (int)($tf['size'] ?? 0)) throw new Exception('Read failed');   // ★ (2026-11-01 17:30, 40차 점검) 연결이 중간에 끊겨 앞부분만 온 내용(SMB·SFTP ssh2 는 false 대신 받은 만큼 돌려줌)도 '성공'으로 대상을 잘린 파일로 덮어썼음 → 목록 크기보다 짧으면 실패(39차 copyRemoteToLocal 과 같은 기준)   // ★ (2026-10-29 09:23, 20차) 원격 read() 는 실패해도 '' 를 돌려줌 — 내용이 비었는데 목록상 크기가 0 보다 크면 읽기 실패로(종전엔 대상 파일을 빈 파일로 덮어썼음)
                            $copyOk = $srcAdapter->write($srcFullPath, $content);
                            unset($content);
                        }
                        
                        if (!$copyOk) throw new Exception('Copy/write failed');
                        if (isset($syRm[$key])) { unset($syRm[$key]); $syRmDirty = true; }   // ★ (2026-11-11 08:05, 72차 RMT-1) 다시 보냈음 — 표시 지움
                        if ($srcAdapter instanceof FtpAdapter && !isset($syMdSkew[(int)$srcStorageId])) { try { $syMdT = $srcAdapter->syncMdtm($srcFullPath); if ($syMdT > 0) $syMdSkew[(int)$srcStorageId] = $syMdT - time(); } catch (\Throwable $e) {} }   // ★ (2026-11-09 15:36, 59차 F1) 되쓰기도 같은 확인값 측정
                        if ($srcAdapter instanceof LocalAdapter && is_numeric($tf['modified'] ?? null) && (int)$tf['modified'] > 0) @touch($srcAdapter->getFullPath($srcFullPath), (int)($tf['touch'] ?? $tf['modified']));   // ★ (2026-11-09 18:04, 59차 F1 재보완) 계획으로 비교 시각을 바꿨으면 원래 목록 시각('touch')으로 맞춤   // ★ (2026-11-04 01:43, 54차 점검) 되쓰기도 받은 쪽(원본) 시각을 대상 시각으로(위 정방향과 같은 이유 — 종전엔 다음 실행에서 다시 정방향 복사, 재현)
                        $syNew = (int)($tf['size'] ?? 0);   // ★ (2026-11-02 09:09, 41차 점검) 양방향 되쓰기는 원본 쪽 사용량·색인 반영
                        $syReflect((int)$srcStorageId, 'put', $srcFullPath, $syNew - (isset($srcIndex[$key]) ? (int)($srcIndex[$key]['size'] ?? 0) : 0), $syNew, ($srcAdapter instanceof LocalAdapter) ? ($tf['touch'] ?? $tf['modified'] ?? null) : ($tf['modified'] ?? null));   // ★ (2026-11-09 19:41, 60차 Z15) 받는 쪽(원본)이 로컬이면 색인 시각도 위 touch 와 같은 값(정방향과 같은 이유). 판정은 그대로
                        if (!isset($srcIndex[$key]) && class_exists('JobManager')) { try { JobManager::indexRemoteAncestors((int)$srcStorageId, $srcFullPath); } catch (\Throwable $e) {} }   // ★ (2026-11-09 11:57, 57차 Z15-1) 되쓰기로 새로 생긴 원본 쪽 상위 폴더 중 색인에 없는 것만 폴더 행으로(종전엔 파일 행만 넣어 색인 즉답 목록에서 그 폴더가 안 보였음, 재현)
                        
                        echo "  [" . strtoupper($reverseAction) . "←] $key\n";
                        $stats[$reverseAction === 'copy' ? 'copied' : 'updated']++;
                    } catch (Exception $e) {
                        echo "  [ERR←] $key: " . $e->getMessage() . "\n";
                        $stats['errors']++;
                        if ($tgtAdapter instanceof LocalAdapter && LocalAdapter::syncRemoteDirect($srcAdapter)) { $syRm[$key] = 's'; $syRmNew[$key] = 's'; $syRmSave(); }   // ★ (2026-11-11 08:05, 72차 RMT-1) 위 표시 — 되쓰기 실패, 바로 저장
                    }
                }
            }
            foreach ($syRmDone as $syRmK => $syRmV) { if (($syRm[$syRmK] ?? '') === 't') { unset($syRm[$syRmK]); $syRmDirty = true; } }   // ★ (2026-11-11 08:05, 72차 RMT-1) 정방향으로 다시 보낸 키의 표시 지움
        }
        
        // 고아 삭제
        // ★ (2026-10-29 02:36, 16차) 원본 목록이 비었는데 대상엔 파일이 있으면 삭제하지 않음(원본 폴더 없음·읽기 실패 때 대상 전체 삭제 방지)
        if ($deleteOrphan && empty($srcIndex) && !empty($tgtIndex)) {
            echo "  [SKIP] orphan delete skipped: source list is empty (target protected)\n";
            $deleteOrphan = false;
        }
        if ($deleteOrphan && ($mode === 'one-way' || $mode === 'incremental')) {
            foreach ($tgtIndex as $key => $tf) {
                if (!$tf['is_dir'] && !isset($srcIndex[$key])) {
                    $syUnderLink = false;   // ★ (2026-11-04 01:43, 54차 점검) 원본에서 링크라 뺀 항목(아래 파일)은 '원본에 없음'이 아니므로 대상에서 지우지 않음(종전엔 따라가 복사해 두었던 것)
                    foreach ($syLinkKeys as $syLk) { if ((string)$key === $syLk || strpos((string)$key, $syLk . '/') === 0) { $syUnderLink = true; break; } }
                    if ($syUnderLink) { $stats['skipped']++; continue; }
                    if (isset($syCaseKeep[$syLow($key)])) { echo "  [SKIP-CASE] del $key\n"; $stats['skipped']++; $syCaseKeepKeys[] = (string)$key; continue; }   // ★ (2026-11-10 07:22, 61차) 위 ② — 대소문자만 다른 원본 키를 이번에 복사했거나 같은 파일로 봤으면 지우지 않음
                    if ($syIsLocked((int)$tgtStorageId, $tf['path'])) {   // ★ (2026-11-02 10:32, 42차 점검) 잠긴 파일은 고아 삭제하지 않음
                        echo "  [LOCKED] del $key\n";
                        $stats['skipped']++;
                        $syLockedKeys[] = (string)$key;
                        continue;
                    }
                    try {
                        if ($tgtAdapter->delete($tf['path']) !== false) { $syReflect((int)$tgtStorageId, 'del', $tf['path'], -(int)($tf['size'] ?? 0)); $syCleanup((int)$tgtStorageId, $tf['path'], false); }   // ★ (2026-11-02 09:09, 41차 점검) 지운 것만 사용량·색인에서 뺌 — ★ (2026-11-02 10:32, 42차 점검) 공유·숨김 정리
                        echo "  [DEL] $key\n";
                        $stats['deleted']++;
                    } catch (Exception $e) {
                        echo "  [ERR] del $key: " . $e->getMessage() . "\n";
                        $stats['errors']++;
                    }
                }
            }
            // 빈 디렉토리 삭제
            $dirs = [];
            foreach ($tgtIndex as $key => $tf) {
                if ($tf['is_dir'] && !isset($srcIndex[$key])) {
                    // ★ (2026-11-01 01:56, 36차 전체 점검) 목록을 못 읽은 하위 폴더를 품은 폴더는 지우지 않음(폴더 삭제는 재귀 — api.php sync_execute 와 같게)
                    $sfUnder = false;
                    foreach (array_unique($syncFailed) as $sfKey) { if (strpos((string)$sfKey, $key . '/') === 0) { $sfUnder = true; break; } }
                    foreach ($syLockedKeys as $syLk) { if (strpos($syLk, $key . '/') === 0) { $sfUnder = true; break; } }   // ★ (2026-11-02 10:32, 42차 점검) 남긴 잠긴 파일을 품은 폴더도 지우지 않음(폴더 삭제는 재귀)
                    foreach ($syLinkKeys as $syLk) { if ((string)$key === $syLk || strpos($syLk, $key . '/') === 0 || strpos((string)$key, $syLk . '/') === 0) { $sfUnder = true; break; } }   // ★ (2026-11-04 01:43, 54차 점검) 링크라 뺀 항목을 품은 폴더는 지우지 않음 — 폴더 삭제(LocalAdapter::deleteDir)가 링크를 따라 들어가, 대상 old/lnk3 → 밖 이면 스토리지 밖 파일을 지웠음(재현)
                    foreach ($syCaseKeepKeys as $syLk) { if (strpos($syLk, $key . '/') === 0) { $sfUnder = true; break; } }   // ★ (2026-11-10 07:22, 61차) 위에서 남긴 대소문자 옛 이름 파일을 품은 폴더도 지우지 않음(폴더 삭제는 재귀)
                    if (!$sfUnder) $dirs[] = $tf;
                }
            }
            usort($dirs, function($a, $b) { return strlen($b['path']) - strlen($a['path']); });
            foreach ($dirs as $d) {
                try { if ($tgtAdapter->delete($d['path']) !== false) { $syReflect((int)$tgtStorageId, 'del', $d['path'], 0, 0, null, true); $syCleanup((int)$tgtStorageId, $d['path'], true); } $stats['deleted']++; } catch (Exception $e) {}   // ★ (2026-11-02 10:32, 42차 점검) 지운 폴더 경로의 공유·숨김 정리   // ★ (2026-11-02 09:09, 41차 점검) 색인에서 폴더(하위 포함) 제거 — 안의 파일은 위에서 이미 뺐음
            }
        }
        
        $task['last_run'] = date('Y-m-d H:i:s');
        $task['last_status'] = $stats['errors'] > 0 ? 'partial' : 'success';
        if ($syBaseOn && $stats['errors'] === 0 && !$syLockSkip && !$syncFailed) { try { $db->update('sync_tasks', ['id' => $task['id']], ['sync_base' => ['sig' => $syBaseSig, 'start' => $syBaseStart]]); } catch (\Throwable $e) {} }   // ★ (2026-11-04) 56차 C7-B 깨끗이 끝난 실행의 시작 시각
        if ($syMdSkew) { try { $syMdCal = is_array($task['ftp_mdtm'] ?? null) ? $task['ftp_mdtm'] : []; foreach ($syMdSkew as $syMdSid => $syMdK) { $syMdAd = ((int)$syMdSid === (int)$srcStorageId) ? $srcAdapter : $tgtAdapter; $syMdCal[(string)$syMdSid] = ['sig' => $syMdAd->syncMdtmSig(), 'skew' => (int)$syMdK, 'at' => time()]; } $db->update('sync_tasks', ['id' => $task['id']], ['ftp_mdtm' => $syMdCal]); } catch (\Throwable $e) {} }   // ★ (2026-11-09 15:36, 59차 F1) 이번에 잰 FTP MDTM 확인값 저장(api.php 와 같음)
        if ($syRmHad || $syRmNew) { $syRmSave(); }   // ★ (2026-11-11 11:24, 72차 재검토 반영) 이번에 새로 실패한 키만이 아니라 '불러온 표시 중 이번에 다시 보내지도 지우지도 못한 키'도 남김 — 표시는 양방향·같은 경로(sig)일 때만 불러오므로 모드·경로가 다른 실행은 지금처럼 새 실패만 남음. 종전엔 표시 키가 잠금·대소문자 묶음 건너뜀·로컬 파일 잠시 없음으로 처리되지 않아도 지워져, 그다음 실행이 잘린 원격으로 로컬 원본을 덮었음(재검토 재현)

        echo sprintf("  ✅ Done — Copied:%d Updated:%d Deleted:%d Skipped:%d Errors:%d\n",
            $stats['copied'], $stats['updated'], $stats['deleted'], $stats['skipped'], $stats['errors']);
        
    } catch (Exception $e) {
        $task['last_run'] = date('Y-m-d H:i:s');
        $task['last_status'] = 'error';
        echo "  ❌ Error: " . $e->getMessage() . "\n";
    }
    // ★ (2026-10-29 04:18, 17차) 이 작업의 결과 칸만 최신 목록에 저장 — 종전엔 시작 때 읽은 목록 전체를 끝에 다시 저장해, 그사이 화면에서 바꾼 설정·
    //   새로 만든 작업·지운 작업이 되돌아갔음
    foreach ($syDelta as $sdSid => $sdD) { if ((int)$sdD !== 0) { try { $storage->updateUsedSize((int)$sdSid, (int)$sdD); } catch (\Throwable $e) {} } } $syDelta = [];   // ★ (2026-11-02 09:48, 41차 재검토) 모은 사용량 반영(성공·실패 모두 — 그때까지 쓴 만큼)
    $db->update('sync_tasks', ['id' => $task['id']], ['last_run' => $task['last_run'] ?? date('Y-m-d H:i:s'), 'last_status' => $task['last_status'] ?? 'error']);
    flock($syncLock, LOCK_UN);
    fclose($syncLock);
}
unset($task);

echo date('[Y-m-d H:i:s]') . " Cron finished.\n";
