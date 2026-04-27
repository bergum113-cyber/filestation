<?php
/**
 * 작업 큐 백그라운드 워커
 * 
 * 사용법: php job_worker.php <job_id>
 * api.php의 job_create에서 별도 프로세스로 실행됩니다.
 */

// CLI에서만 실행 가능
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if ($argc < 2) {
    exit('Usage: php job_worker.php <job_id>');
}

$jobId = $argv[1];

// 프로젝트 루트 경로 설정
$rootDir = dirname(__DIR__);
require_once $rootDir . '/config.php';

// 필요한 클래스 로드 (config.php에서 autoload 설정에 따라)
require_once $rootDir . '/api/JobManager.php';

@set_time_limit(0);
@ini_set('memory_limit', '512M');

// 세션 시작 (FileManager 내부에서 Auth가 필요)
// job 파일에서 session_id를 읽어서 세션 복원
$jobManager = JobManager::getInstance();

// 작업 상태 확인
$job = $jobManager->getStatus($jobId);
if (!$job) {
    exit("Job not found: $jobId");
}

if ($job['status'] !== 'pending') {
    exit("Job already started: {$job['status']}");
}

// session_id가 있으면 세션 복원
$sessionId = $job['session_id'] ?? '';
if ($sessionId) {
    session_id($sessionId);
    session_start();
    session_write_close();
}

// Auth 설정
$auth = new Auth();
$jobManager->setAuth($auth);

// 작업 실행
$jobManager->execute($jobId);
