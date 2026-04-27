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
error_reporting(E_ALL);
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
    list($schedHour, $schedMin) = array_map('intval', explode(':', $time));
    
    $shouldRun = false;
    
    switch ($type) {
        case 'hourly':
            $interval = (int)($sched['hours'] ?? 1);
            $lastRun = $task['last_run'] ? strtotime($task['last_run']) : 0;
            if ($now - $lastRun >= $interval * 3600) {
                $shouldRun = true;
            }
            break;
        case 'daily':
            if ($currentHour == $schedHour && $currentMinute == $schedMin) {
                $shouldRun = true;
            }
            break;
        case 'weekly':
            $dow = (int)($sched['dow'] ?? 0);
            if ($currentDow == $dow && $currentHour == $schedHour && $currentMinute == $schedMin) {
                $shouldRun = true;
            }
            break;
        case 'monthly':
            $dom = (int)($sched['dom'] ?? 1);
            if ($currentDom == $dom && $currentHour == $schedHour && $currentMinute == $schedMin) {
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

foreach ($toExecute as &$task) {
    $taskName = $task['name'] ?? 'Unnamed';
    echo date('[Y-m-d H:i:s]') . " Starting sync task #{$task['id']}: {$taskName}\n";
    
    try {
        $srcStorageId = $task['source_storage_id'];
        $tgtStorageId = $task['target_storage_id'];
        $srcPath = trim($task['source_path'] ?? '', '/');
        $tgtPath = trim($task['target_path'] ?? '', '/');
        $mode = $task['mode'] ?? 'one-way';
        $deleteOrphan = !empty($task['delete_orphan']);
        $includeSubdir = $task['include_subdir'] ?? true;
        
        // getStorageById로 shared/home 경로 정상 해석
        $srcStorage = $storage->getStorageById($srcStorageId);
        $tgtStorage = $storage->getStorageById($tgtStorageId);
        if (!$srcStorage || !$tgtStorage) throw new Exception("Storage not found (src:$srcStorageId, tgt:$tgtStorageId)");
        
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
        
        $srcAdapter = StorageAdapterFactory::create($srcStorage);
        $tgtAdapter = StorageAdapterFactory::create($tgtStorage);
        
        echo "  Adapters: src=" . get_class($srcAdapter) . ", tgt=" . get_class($tgtAdapter) . "\n";
        
        $stats = ['copied' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0];
        
        // 재귀 파일 목록
        $collectFiles = function($adapter, $basePath, $includeSubdir) use (&$collectFiles) {
            $files = [];
            $items = $adapter->list($basePath);
            foreach ($items as $item) {
                $relPath = $basePath ? $basePath . '/' . $item['name'] : $item['name'];
                if ($item['is_dir']) {
                    if ($includeSubdir) {
                        $files = array_merge($files, $collectFiles($adapter, $relPath, true));
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
            $key = $srcPath ? substr($f['path'], strlen($srcPath) + 1) : $f['path'];
            $srcIndex[$key] = $f;
        }
        $tgtIndex = [];
        foreach ($tgtFiles as $f) {
            $key = $tgtPath ? substr($f['path'], strlen($tgtPath) + 1) : $f['path'];
            $tgtIndex[$key] = $f;
        }
        
        // 디렉토리 생성
        foreach ($srcIndex as $key => $sf) {
            if ($sf['is_dir'] && !isset($tgtIndex[$key])) {
                try {
                    $tgtAdapter->mkdir($tgtPath ? $tgtPath . '/' . $key : $key);
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
                if ($sf['size'] != $tf['size'] || $sf['modified'] > $tf['modified']) {
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
            
            if ($needCopy) {
                try {
                    $srcFullPath = $sf['path'];
                    $tgtFullPath = $tgtPath ? $tgtPath . '/' . $key : $key;
                    $fileSize = $sf['size'] ?? 0;
                    $sizeMB = round($fileSize / 1024 / 1024, 1);
                    
                    $copyOk = false;
                    if ($srcAdapter instanceof LocalAdapter && method_exists($srcAdapter, 'copyFileTo')) {
                        $copyOk = $srcAdapter->copyFileTo($srcFullPath, $tgtAdapter, $tgtFullPath);
                    } else {
                        $content = $srcAdapter->read($srcFullPath);
                        if ($content === false) throw new Exception('Read failed');
                        $copyOk = $tgtAdapter->write($tgtFullPath, $content);
                        unset($content);
                    }
                    
                    if (!$copyOk) throw new Exception('Copy/write failed');
                    
                    echo "  [" . strtoupper($action) . "] $key ({$sizeMB}MB)\n";
                    $stats[$action === 'copy' ? 'copied' : 'updated']++;
                } catch (Exception $e) {
                    echo "  [ERR] $key: " . $e->getMessage() . "\n";
                    $stats['errors']++;
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
                
                if ($needReverse) {
                    try {
                        $tgtFullPath = $tf['path'];
                        $srcFullPath = $srcPath ? $srcPath . '/' . $key : $key;
                        
                        $copyOk = false;
                        if ($tgtAdapter instanceof LocalAdapter && method_exists($tgtAdapter, 'copyFileTo')) {
                            $copyOk = $tgtAdapter->copyFileTo($tgtFullPath, $srcAdapter, $srcFullPath);
                        } else {
                            $content = $tgtAdapter->read($tgtFullPath);
                            if ($content === false) throw new Exception('Read failed');
                            $copyOk = $srcAdapter->write($srcFullPath, $content);
                            unset($content);
                        }
                        
                        if (!$copyOk) throw new Exception('Copy/write failed');
                        
                        echo "  [" . strtoupper($reverseAction) . "←] $key\n";
                        $stats[$reverseAction === 'copy' ? 'copied' : 'updated']++;
                    } catch (Exception $e) {
                        echo "  [ERR←] $key: " . $e->getMessage() . "\n";
                        $stats['errors']++;
                    }
                }
            }
        }
        
        // 고아 삭제
        if ($deleteOrphan && ($mode === 'one-way' || $mode === 'incremental')) {
            foreach ($tgtIndex as $key => $tf) {
                if (!$tf['is_dir'] && !isset($srcIndex[$key])) {
                    try {
                        $tgtAdapter->delete($tf['path']);
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
                if ($tf['is_dir'] && !isset($srcIndex[$key])) $dirs[] = $tf;
            }
            usort($dirs, function($a, $b) { return strlen($b['path']) - strlen($a['path']); });
            foreach ($dirs as $d) {
                try { $tgtAdapter->delete($d['path']); $stats['deleted']++; } catch (Exception $e) {}
            }
        }
        
        $task['last_run'] = date('Y-m-d H:i:s');
        $task['last_status'] = $stats['errors'] > 0 ? 'partial' : 'success';
        
        echo sprintf("  ✅ Done — Copied:%d Updated:%d Deleted:%d Skipped:%d Errors:%d\n",
            $stats['copied'], $stats['updated'], $stats['deleted'], $stats['skipped'], $stats['errors']);
        
    } catch (Exception $e) {
        $task['last_run'] = date('Y-m-d H:i:s');
        $task['last_status'] = 'error';
        echo "  ❌ Error: " . $e->getMessage() . "\n";
    }
}
unset($task);

$db->save('sync_tasks', $tasks);
echo date('[Y-m-d H:i:s]') . " Cron finished.\n";
