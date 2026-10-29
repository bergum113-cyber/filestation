<?php
/**
 * Storage - 스토리지(네트워크/로컬 드라이브) 관리 (JSON 기반)
 * 
 * 수정 이력:
 * - 2026-01-19: checkPermission 개선 - 스토리지 타입별 권한 처리 추가
 *               외부 공유폴더 업로드 문제 해결
 */
class Storage {
    private $db;
    private $auth;
    
    // 성능 최적화를 위한 캐시 (같은 요청 내에서 재사용)
    private static $storageCache = [];
    private static $permissionCache = [];
    private static $isAdminCache = null;
    private static $userIdCache = null;
    
    public function __construct() {
        $this->db = JsonDB::getInstance();
        $this->auth = new Auth();
    }
    
    /**
     * 캐시 초기화 (필요 시 호출)
     */
    // clearCache() 제거됨 — 외부 호출처 없음
    
    // 스토리지 목록 (사용자 권한 기반)
    // $context: 'web' (기본) 또는 'webdav'
    public function getStorages(string $context = 'web'): array {
        $userId = $this->auth->getUserId();
        $isAdmin = $this->auth->isAdmin();
        
        // 공용 폴더(shared) 자동 생성 확인
        $this->ensureSharedStorage();
        
        $storages = $this->db->findAll('storages', ['is_active' => 1]);
        $permissions = $this->db->findAll('permissions', ['user_id' => $userId]);
        $allowedIds = [];
        
        // 컨텍스트에 따라 can_visible 또는 can_visible_webdav 체크
        $visibleKey = $context === 'webdav' ? 'can_visible_webdav' : 'can_visible';
        foreach ($permissions as $perm) {
            if ($perm[$visibleKey] ?? ($perm['can_visible'] ?? 1)) {
                $allowedIds[] = $perm['storage_id'];
            }
        }
        
        $home = [];
        $public = [];  // 공용 폴더 (shared 타입)
        $shared = [];  // 외부 스토리지 (local, smb, ftp 등)
        
        foreach ($storages as $storage) {
            $type = $storage['storage_type'] ?? 'local';
            
            // 홈 스토리지: 본인 것만 (모든 권한)
            if ($type === 'home') {
                if ((int)($storage['owner_id'] ?? 0) === (int)$userId) {
                    // 홈 스토리지는 소유자이므로 모든 권한
                    $storage['can_read'] = 1;
                    $storage['can_write'] = 1;
                    $storage['can_delete'] = 1;
                    $storage['can_share'] = 1;
                    $storage['can_download'] = 1;
                    // path 동적 계산
                    $storage['path'] = $this->getHomeStoragePath($storage['owner_id']);
                    // 이름/설명을 현재 언어로 번역
                    $storage['name'] = __('api_home_storage_name', '내 파일');
                    $currentUser = $this->auth->getUser();
                    $username = ($currentUser !== null) ? ($currentUser['username'] ?? '') : '';
                    $storage['description'] = $username . __('api_home_storage_desc', '의 개인 폴더');
                    $home[] = $storage;
                }
                continue;
            }
            
            // 공용 폴더(shared 타입): 권한 기반으로 접근 제어
            if ($type === 'shared') {
                $storage['path'] = $this->getSharedStoragePath();
                // 이름/설명을 현재 언어로 번역
                $storage['name'] = __('api_shared_folder_name', '공유 폴더');
                $storage['description'] = __('api_shared_folder_desc', '모든 사용자가 접근 가능한 공용 폴더');
                
                // 관리자도 can_visible 체크
                if ($isAdmin) {
                    // 관리자 권한 테이블 확인
                    $adminVisible = true;
                    foreach ($permissions as $perm) {
                        if ((int)$perm['storage_id'] === (int)$storage['id']) {
                            if (!($perm[$visibleKey] ?? ($perm['can_visible'] ?? 1))) {
                                $adminVisible = false;
                            }
                            break;
                        }
                    }
                    if ($adminVisible) {
                        $storage['can_read'] = 1;
                        $storage['can_write'] = 1;
                        $storage['can_download'] = 1;
                        $storage['can_share'] = 1;
                        $storage['can_delete'] = 1;
                        $public[] = $storage;
                    }
                    continue;
                }
                
                // 일반 사용자: 권한 확인
                $hasPerm = false;
                $isHidden = false;
                foreach ($permissions as $perm) {
                    if ((int)$perm['storage_id'] === (int)$storage['id']) {
                        if ($perm[$visibleKey] ?? ($perm['can_visible'] ?? 1)) {
                            $storage['can_read'] = $perm['can_read'] ?? 1;
                            $storage['can_write'] = $perm['can_write'] ?? 0;
                            $storage['can_download'] = $perm['can_download'] ?? 1;
                            $storage['can_share'] = $perm['can_share'] ?? 0;
                            $storage['can_delete'] = $perm['can_delete'] ?? 0;
                            $public[] = $storage;
                            $hasPerm = true;
                        } else {
                            $isHidden = true;
                        }
                        break;
                    }
                }
                
                // 권한 레코드 자체가 없는 경우만 기본값으로 표시
                // can_visible=0으로 명시적 숨김된 경우는 제외
                if (!$hasPerm && !$isHidden) {
                    $storage['can_read'] = 1;
                    $storage['can_write'] = 0;
                    $storage['can_download'] = 1;
                    $storage['can_share'] = 0;
                    $storage['can_delete'] = 0;
                    $public[] = $storage;
                }
                continue;
            }
            
            // 외부 스토리지 (local, smb, ftp 등): can_visible 체크 후 접근
            // 관리자: 권한 테이블에 can_visible=0이면 숨김
            // 일반 사용자: allowedIds(can_visible=1)에 있는 것만
            $storageVisible = false;
            if ($isAdmin) {
                // 관리자: 기본 표시, 권한에 can_visible=0 있으면 숨김
                $storageVisible = true;
                foreach ($permissions as $perm) {
                    if ((int)$perm['storage_id'] === (int)$storage['id']) {
                        if (!($perm[$visibleKey] ?? ($perm['can_visible'] ?? 1))) {
                            $storageVisible = false;
                        }
                        break;
                    }
                }
            } else {
                $storageVisible = in_array($storage['id'], $allowedIds);
            }
            
            if ($storageVisible) {
                // 기본 권한 설정 (권한이 없을 경우를 대비)
                $storage['can_read'] = 0;
                $storage['can_write'] = 0;
                $storage['can_delete'] = 0;
                $storage['can_share'] = 0;
                $storage['can_download'] = 0;
                
                // 권한 정보 추가
                foreach ($permissions as $perm) {
                    if ((int)$perm['storage_id'] === (int)$storage['id']) {
                        $storage['can_read'] = $perm['can_read'] ?? 0;
                        $storage['can_write'] = $perm['can_write'] ?? 0;
                        $storage['can_delete'] = $perm['can_delete'] ?? 0;
                        $storage['can_share'] = $perm['can_share'] ?? 0;
                        $storage['can_download'] = $perm['can_download'] ?? 1;
                        break;
                    }
                }
                
                // 관리자는 모든 권한
                if ($isAdmin) {
                    $storage['can_read'] = 1;
                    $storage['can_write'] = 1;
                    $storage['can_delete'] = 1;
                    $storage['can_share'] = 1;
                    $storage['can_download'] = 1;
                }
                
                $shared[] = $storage;
            }
        }
        
        return [
            'home' => $home,
            'public' => $public,
            'shared' => $shared
        ];
    }
    
    // 모든 스토리지 조회 (관리자용) - 홈 스토리지만 제외
    public function getAllStorages(): array {
        $storages = $this->db->load('storages');
        $result = [];
        
        foreach ($storages as $s) {
            // 홈 스토리지만 제외 (개인 폴더)
            $type = $s['storage_type'] ?? 'local';
            if ($type === 'home') {
                continue;
            }
            unset($s['smb_password']);
            
            // 기본값 보장
            if (!isset($s['quota'])) $s['quota'] = 0;
            if (!isset($s['used_size'])) $s['used_size'] = 0;
            
            // shared 타입: 이름/설명을 현재 언어로 번역
            if ($type === 'shared') {
                $s['name'] = __('api_shared_folder_name', '공유 폴더');
                $s['description'] = __('api_shared_folder_desc', '모든 사용자가 접근 가능한 공용 폴더');
            }
            
            $result[] = $s;
        }
        
        return $result;
    }
    
    // 스토리지 추가
    public function addStorage(array $data): array {
        if (empty($data['name'])) {
            return ['success' => false, 'error' => __('api_err_name_required', '이름은 필수입니다.')];
        }
        
        $storageType = $data['storage_type'] ?? 'local';
        $path = '';
        
        // local 타입: 경로 검사
        if ($storageType === 'local') {
            if (empty($data['path'])) {
                return ['success' => false, 'error' => __('api_err_path_required', '경로는 필수입니다.')];
            }
            $path = $this->normalizePath($data['path']);
            
            if (!$this->isPathAccessible($path, $data)) {
                return ['success' => false, 'error' => __('path_access_denied') . $path];
            }
            
            // ★ 보안: .htaccess 자동 생성 (URL 직접 접근 차단)
            $this->createProtectionFile($path);
        }
        
        // SMB 타입: 연결 시도 + 마운트 포인트를 path로 설정
        if ($storageType === 'smb') {
            $config = $data['config'] ?? [];
            if (empty($config['host']) || empty($config['share'])) {
                return ['success' => false, 'error' => __('api_err_smb_host_required', 'SMB 호스트와 공유 이름은 필수입니다.')];
            }
            
            $connectResult = $this->connectSmb(['config' => $config]);
            if (!$connectResult) {
                return ['success' => false, 'error' => __('api_err_smb_connect_failed', 'SMB 연결에 실패했습니다. 호스트/인증 정보를 확인하세요.')];
            }
            
            // 경로 설정: Windows는 UNC, Linux는 마운트 포인트
            if ($this->isWindows()) {
                $path = "\\\\{$config['host']}\\{$config['share']}";
            } else {
                $path = $this->getSmbMountPoint($config['host'], $config['share']);
            }
        }
        
        // 원격 스토리지 연결 테스트 (FTP, SFTP, WebDAV, S3)
        $remoteTypes = ['ftp', 'sftp', 'webdav', 's3'];
        if (in_array($storageType, $remoteTypes)) {
            $testResult = $this->testRemoteConnection($storageType, $data['config'] ?? []);
            if (!$testResult['success']) {
                return ['success' => false, 'error' => $testResult['error']];
            }
        }
        
        // config 암호화 저장
        $config = $data['config'] ?? [];
        if (!empty($config)) {
            // 민감한 정보 암호화 (AES-256-GCM)
            $config = encryptStorageConfig($config);
        } else {
            $config = '';
        }
        
        // quota 처리 (바이트 단위)
        $quota = 0;
        if (isset($data['quota'])) {
            $q = (int)$data['quota'];
            $quota = $q === -1 ? -1 : max(0, $q);
        }
        
        $storageData = [
            'name' => $data['name'],
            'path' => $path,
            'storage_type' => $storageType,
            'description' => $data['description'] ?? '',
            'icon' => $this->getStorageIcon($storageType),
            'is_active' => 1,
            'created_by' => $this->auth->getUserId(),
            'created_at' => date('Y-m-d H:i:s'),
            'config' => $config,
            'quota' => $quota,
            'used_size' => 0,  // 초기값, 필요시 recalculate로 계산
            'default_permissions' => $data['default_permissions'] ?? []
        ];
        
        $id = $this->db->insert('storages', $storageData);
        
        // 권한 설정
        if (!empty($data['permissions'])) {
            foreach ($data['permissions'] as $perm) {
                $this->db->insert('permissions', [
                    'storage_id' => $id,
                    'user_id' => $perm['user_id'],
                    'can_visible' => $perm['can_visible'] ?? 1,
                    'can_visible_webdav' => $perm['can_visible_webdav'] ?? ($perm['can_visible'] ?? 1),
                    'can_read' => $perm['can_read'] ?? 1,
                    'can_download' => $perm['can_download'] ?? 1,
                    'can_write' => $perm['can_write'] ?? 0,
                    'can_delete' => $perm['can_delete'] ?? 0,
                    'can_share' => $perm['can_share'] ?? 0
                ]);
            }
        } else {
            // 권한 설정이 없으면 생성자에게만 모든 권한 부여
            $this->db->insert('permissions', [
                'storage_id' => $id,
                'user_id' => $this->auth->getUserId(),
                'can_visible' => 1,
                'can_read' => 1,
                'can_visible_webdav' => 1,
                'can_download' => 1,
                'can_write' => 1,
                'can_delete' => 1,
                'can_share' => 1
            ]);
        }
        
        // 캐시 무효화
        self::$storageCache = [];
        
        // 사용량 계산 요청 시
        $result = ['success' => true, 'id' => $id];
        if (!empty($data['recalculate_usage'])) {
            $recalcResult = $this->recalculateUsedSize($id);
            if ($recalcResult['success']) {
                $result['used_size'] = $recalcResult['used_size'];
                $result['used_size_formatted'] = $recalcResult['used_size_formatted'];
            }
        }
        
        return $result;
    }
    
    // 스토리지 단일 조회 (민감 정보 제외)
    public function getStorage(int $id): array {
        $storage = $this->getStorageById($id);
        if (!$storage) {
            return ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
        }
        
        // config 복호화 (비밀번호 제외)
        if (!empty($storage['config'])) {
            $config = decryptStorageConfig($storage['config']);
            if ($config) {
                // 민감한 정보 마스킹
                foreach (['password', 'secret_key', 'client_secret', 'app_secret', 'private_key'] as $key) {
                    if (isset($config[$key]) && !empty($config[$key])) {
                        $config[$key] = ''; // 빈 값으로 (프론트에서 입력 안 하면 유지)
                    }
                }
                $storage['config'] = $config;
            }
        }
        
        // 레거시 필드 제거
        unset($storage['smb_password']);
        
        // shared 타입: 이름/설명을 현재 언어로 번역
        if (($storage['storage_type'] ?? '') === 'shared') {
            $storage['name'] = __('api_shared_folder_name', '공유 폴더');
            $storage['description'] = __('api_shared_folder_desc', '모든 사용자가 접근 가능한 공용 폴더');
        }
        
        return ['success' => true, 'storage' => $storage];
    }
    
    // 스토리지 수정
    public function updateStorage(int $id, array $data): array {
        $storage = $this->getStorageById($id);
        if (!$storage) {
            return ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
        }
        
        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['description'])) $updateData['description'] = $data['description'];
        if (isset($data['storage_type'])) {
            $updateData['storage_type'] = $data['storage_type'];
            $updateData['icon'] = $this->getStorageIcon($data['storage_type']);
        }
        if (isset($data['is_active'])) $updateData['is_active'] = $data['is_active'];
        
        // quota 업데이트
        if (isset($data['quota'])) {
            $q = (int)$data['quota'];
            $updateData['quota'] = $q === -1 ? -1 : max(0, $q);
        }
        
        // 신규회원 기본 권한 업데이트
        if (isset($data['default_permissions'])) {
            $updateData['default_permissions'] = $data['default_permissions'];
        }
        
        // local 타입 경로 업데이트
        if (isset($data['path']) && ($data['storage_type'] ?? $storage['storage_type']) === 'local') {
            $path = $this->normalizePath($data['path']);
            if (!$this->isPathAccessible($path, $data)) {
                return ['success' => false, 'error' => __('api_err_path_not_accessible', '경로에 접근할 수 없습니다.')];
            }
            $updateData['path'] = $path;
            
            // ★ 보안: .htaccess 자동 생성 (URL 직접 접근 차단)
            $this->createProtectionFile($path);
        }
        
        // config 업데이트
        if (isset($data['config'])) {
            $newConfig = $data['config'];
            
            // 기존 config 로드
            $existingConfig = [];
            if (!empty($storage['config'])) {
                $existingConfig = decryptStorageConfig($storage['config']);
            }
            
            // 비밀번호 등 빈 값이면 기존 값 유지
            foreach (['password', 'secret_key', 'client_secret', 'app_secret', 'private_key'] as $key) {
                if (isset($newConfig[$key]) && empty($newConfig[$key]) && !empty($existingConfig[$key])) {
                    $newConfig[$key] = $existingConfig[$key];
                }
            }
            
            // 원격 스토리지인 경우 연결 테스트
            $currentType = $data['storage_type'] ?? $storage['storage_type'];
            $remoteTypes = ['ftp', 'sftp', 'webdav', 's3'];
            if (in_array($currentType, $remoteTypes)) {
                $testResult = $this->testRemoteConnection($currentType, $newConfig);
                if (!$testResult['success']) {
                    return ['success' => false, 'error' => $testResult['error']];
                }
            }
            
            $updateData['config'] = encryptStorageConfig($newConfig);
        }
        
        if (!empty($updateData)) {
            $this->db->update('storages', ['id' => $id], $updateData);
            // 캐시 무효화
            self::$storageCache = [];
        }
        
        // 권한 업데이트
        if (!empty($data['permissions'])) {
            // 기존 권한 삭제 후 새로 추가
            $this->db->delete('permissions', ['storage_id' => $id]);
            
            foreach ($data['permissions'] as $perm) {
                $this->db->insert('permissions', [
                    'storage_id' => $id,
                    'user_id' => $perm['user_id'],
                    'can_visible' => $perm['can_visible'] ?? 1,
                    'can_visible_webdav' => $perm['can_visible_webdav'] ?? ($perm['can_visible'] ?? 1),
                    'can_read' => $perm['can_read'] ?? 1,
                    'can_download' => $perm['can_download'] ?? 1,
                    'can_write' => $perm['can_write'] ?? 0,
                    'can_delete' => $perm['can_delete'] ?? 0,
                    'can_share' => $perm['can_share'] ?? 0
                ]);
            }
        }
        
        // 사용량 계산 요청 시
        $result = ['success' => true];
        if (!empty($data['recalculate_usage'])) {
            $recalcResult = $this->recalculateUsedSize($id);
            if ($recalcResult['success']) {
                $result['used_size'] = $recalcResult['used_size'];
                $result['used_size_formatted'] = $recalcResult['used_size_formatted'];
            }
        }
        
        return $result;
    }
    
    // 스토리지 삭제
    public function deleteStorage(int $id): array {
        $storage = $this->getStorageById($id);
        
        // shared 타입은 삭제 불가
        if ($storage && ($storage['storage_type'] ?? '') === 'shared') {
            return ['success' => false, 'error' => __('api_err_shared_no_delete', '공용 폴더는 삭제할 수 없습니다.')];
        }
        
        $this->db->delete('storages', ['id' => $id]);
        $this->db->delete('permissions', ['storage_id' => $id]);
        $this->db->delete('shares', ['storage_id' => $id]);
        // ★ (2026-10-29 22:00, 24차 점검) 이 스토리지 번호에 묶인 내부 공유·폴더 규칙·잠금·동기화 작업도 지움 — 번호는 '최대+1'로 다시 쓰여, 가장 큰 번호를 지우고
        //   새로 만들면 옛 규칙·잠금이 새 스토리지에 걸리고 옛 동기화 작업(고아 삭제 포함)이 새 스토리지를 대상으로 돌 수 있었음(사용자 삭제 16차와 같은 이유)
        $this->db->delete('internal_shares', ['storage_id' => $id]);
        $this->db->delete('folder_permissions', ['storage_id' => $id]);
        $this->db->delete('locked_files', ['storage_id' => $id]);
        $syncTasks = $this->db->load('sync_tasks') ?: [];
        $syncKeep = array_values(array_filter($syncTasks, fn($t) => (int)($t['source_storage_id'] ?? 0) !== $id && (int)($t['target_storage_id'] ?? 0) !== $id));
        if (count($syncKeep) !== count($syncTasks)) $this->db->save('sync_tasks', $syncKeep);
        // ★ (2026-10-29 22:39, 24차 점검) 파일 인덱스(검색·원격 목록·사용량 계산에 쓰임)도 같은 이유로 지움(2회차 재검토)
        try { if (class_exists('FileIndex')) \FileIndex::getInstance()->removeStorage($id); } catch (\Throwable $e) {}
        
        // 캐시 무효화
        self::$storageCache = [];
        
        return ['success' => true];
    }
    
    // 스토리지 정보 조회
    public function getStorageById(int $id): ?array {
        $storage = $this->db->find('storages', ['id' => $id]);
        
        if (!$storage) return null;
        
        // 기본값 보장
        if (!isset($storage['quota'])) $storage['quota'] = 0;
        if (!isset($storage['used_size'])) $storage['used_size'] = 0;
        
        // home 타입이면 동적으로 경로 계산
        if (($storage['storage_type'] ?? '') === 'home') {
            $storage['path'] = $this->getHomeStoragePath($storage['owner_id'] ?? 0);
        }
        
        // shared 타입이면 동적으로 경로 계산
        if (($storage['storage_type'] ?? '') === 'shared') {
            $storage['path'] = $this->getSharedStoragePath();
        }
        
        return $storage;
    }
    
    /**
     * home 타입 스토리지의 실제 경로 계산
     * USER_FILES_ROOT + username
     */
    private function getHomeStoragePath(int $ownerId): string {
        $user = $this->db->find('users', ['id' => $ownerId]);
        $username = $user['username'] ?? 'unknown';
        return USER_FILES_ROOT . DIRECTORY_SEPARATOR . $username;
    }
    
    /**
     * shared 타입 스토리지의 실제 경로 계산
     * SHARED_FILES_ROOT
     */
    private function getSharedStoragePath(): string {
        return SHARED_FILES_ROOT;
    }
    
    /**
     * 공용 폴더(shared) 스토리지 자동 생성 및 중복 정리
     */
    private function ensureSharedStorage(): void {
        if (!defined('SHARED_FILES_ROOT')) return;
        
        // 폴더 생성
        $sharedPath = SHARED_FILES_ROOT;
        if (!is_dir($sharedPath)) {
            @mkdir($sharedPath, 0755, true);
        }
        
        // 모든 스토리지 로드
        $allStorages = $this->db->load('storages');
        $sharedStorages = [];
        $duplicates = [];
        $needsUpdate = false;
        
        foreach ($allStorages as $index => &$s) {
            $type = $s['storage_type'] ?? '';
            $name = $s['name'] ?? '';
            
            // 정상 shared 타입
            if ($type === 'shared') {
                $sharedStorages[] = $s;
                
                // 기존 공유 폴더에 누락된 필드 추가
                if (!isset($s['quota'])) {
                    $allStorages[$index]['quota'] = 0;
                    $needsUpdate = true;
                }
                if (!isset($s['used_size'])) {
                    $allStorages[$index]['used_size'] = 0;
                    $needsUpdate = true;
                }
            }
            // 중복/잘못된 공유 폴더 (storage_type이 없거나 다른데 이름이 "공유 폴더" 또는 "Shared Folder")
            // ★ (2026-10-29 10:53, 20차 점검) 이름만 같은 정상 스토리지는 지우지 않음 — 종류가 비었거나 경로가 공용 폴더 경로와 같은 옛 잘못된 기록만.
            //   종전엔 관리자가 로컬 스토리지 이름을 '공유 폴더' 로 지으면 다음 목록 조회 때 그 기록(권한·설정 포함)이 사라졌음(재현)
            elseif (($name === __('shared_folder_name') || $name === 'Shared Folder') && $type !== 'shared') {
                $dupPath = (string)($s['path'] ?? '');
                $isSharedRoot = $dupPath !== '' && ($rp1 = realpath($dupPath)) !== false && ($rp2 = realpath($sharedPath)) !== false && $rp1 === $rp2;
                if ($type === '' || $isSharedRoot) $duplicates[] = $index;
            }
        }
        unset($s);
        
        // 중복 항목 삭제 또는 필드 업데이트
        if (!empty($duplicates) || $needsUpdate) {
            foreach (array_reverse($duplicates) as $index) {
                unset($allStorages[$index]);
            }
            $this->db->save('storages', array_values($allStorages));
        }
        
        // 정상 shared 스토리지가 있으면 종료
        if (!empty($sharedStorages)) return;
        
        // shared 스토리지 생성
        $this->db->insert('storages', [
            'name' => __('api_shared_folder_name', '공유 폴더'),
            'path' => '',  // 동적 계산
            'storage_type' => 'shared',
            'description' => __('api_shared_folder_desc', __('api_shared_folder_desc', '모든 사용자가 접근 가능한 공용 폴더')),
            'icon' => '📂',
            'is_active' => 1,
            'created_by' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'quota' => 0,
            'used_size' => 0
        ]);
    }
    
    /**
     * 권한 확인 (캐싱 적용 버전)
     * 
     * 스토리지 타입별 처리:
     * - 관리자: 항상 모든 권한
     * - home: 소유자면 모든 권한
     * - shared: permissions 테이블 확인
     * - 외부 스토리지 (local, smb 등): permissions 테이블 확인, 생성자도 확인
     * 
     * @param int $storageId 스토리지 ID
     * @param string $permission 확인할 권한 (can_read, can_write, can_delete, can_share, can_download)
     * @return bool
     */
    public function checkPermission(int $storageId, string $permission): bool {
        // 관리자 캐시 확인
        if (self::$isAdminCache === null) {
            self::$isAdminCache = $this->auth->isAdmin();
        }
        
        // 사용자 ID 캐시
        if (self::$userIdCache === null) {
            self::$userIdCache = $this->auth->getUserId();
        }
        $userId = self::$userIdCache;
        
        // 스토리지 정보 캐시
        if (!isset(self::$storageCache[$storageId])) {
            self::$storageCache[$storageId] = $this->getStorageById($storageId);
        }
        $storage = self::$storageCache[$storageId];
        
        if (!$storage) {
            return false;
        }
        
        $storageType = $storage['storage_type'] ?? 'local';
        
        // 1. home 타입: 소유자면 모든 권한
        if ($storageType === 'home') {
            if ((int)($storage['owner_id'] ?? 0) === (int)$userId) {
                return true;
            }
            return false;
        }
        
        // 권한 정보 캐시 키
        $permCacheKey = "{$storageId}_{$userId}";
        
        // 권한 정보 캐시 확인
        if (!isset(self::$permissionCache[$permCacheKey])) {
            self::$permissionCache[$permCacheKey] = $this->db->find('permissions', [
                'storage_id' => $storageId,
                'user_id' => $userId
            ]);
        }
        $perm = self::$permissionCache[$permCacheKey];
        
        // 2. shared 타입
        if ($storageType === 'shared') {
            if ($perm) {
                return (bool)($perm[$permission] ?? false);
            }
            // permissions에 없으면: 관리자는 모든 권한, 일반 사용자는 읽기/다운로드만
            if (self::$isAdminCache) {
                return true;
            }
            // ★ (2026-10-29 21:52, 24차 점검) 보기(can_visible)도 — 스토리지 목록(getStorages)은 기록 없는 공용 폴더를 읽기 가능으로 보여 주는데 목록 열기(files)는
            //   can_visible 로 확인해, 누르면 '권한이 없습니다'만 떴음(재현: 가입 등으로 공용 폴더 기록이 없는 사용자)
            if ($permission === 'can_read' || $permission === 'can_download' || $permission === 'can_visible') {
                return true;
            }
            return false;
        }
        
        // 3. 외부 스토리지 (local, smb, ftp 등)
        if ($perm) {
            return (bool)($perm[$permission] ?? false);
        }
        
        // 스토리지 생성자인 경우 모든 권한
        if ((int)($storage['created_by'] ?? 0) === (int)$userId) {
            return true;
        }
        
        // 관리자이지만 권한 레코드가 없는 경우: 모든 권한 (스토리지 관리 가능)
        if (self::$isAdminCache) {
            return true;
        }
        
        return false;
    }
    
    // ===== 폴더별 권한 =====
    
    /**
     * 폴더별 권한 체크 - 해당 경로에 접근 가능한지 확인
     * 폴더 권한이 설정되지 않으면 스토리지 권한을 따름
     */
    /**
     * ★ (2026-10-28 17:23, 소스 점검 2단계) 폴더 권한 비교용 경로 정규화 — 종전엔 trim 만 해서
     *   './secret'·'x/../secret'·끝에 점·공백 붙인 'secret.'·'secret ' 같은 표기가 'secret' 규칙에 안 걸려 폴더 권한을 우회할 수 있었음.
     *   실제 파일 접근은 realpath(윈도우는 대소문자 무시)를 거치므로, 규칙 비교도 같은 수준으로: 조각 단위로 '.'·빈 조각 제거, '..' 로 위로,
     *   조각 끝의 점·공백 제거(윈도우가 파일명에서 떼어냄). 비교는 윈도우에서 대소문자 무시(소문자로).
     */
    private function normFolderPath(string $path): string {
        $path = str_replace('\\', '/', $path);
        $out = [];
        foreach (explode('/', $path) as $seg) {
            $seg = rtrim(trim($seg), " .");
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($out); continue; }
            $out[] = $seg;
        }
        $joined = implode('/', $out);
        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($joined) : $joined;
    }

    public function checkFolderPermission(int $storageId, string $folderPath, string $permission = 'can_read'): bool {
        // 관리자도 폴더 권한 적용 (스토리지 권한과 달리 폴더 권한은 모든 사용자에게 적용)
        
        $userId = $this->auth->getUserId();
        $rules = $this->getFolderPermissions($storageId);
        
        if (empty($rules)) return true; // 폴더 권한 설정 없으면 스토리지 권한으로
        
        // 경로 정규화 (./ · ../ · 끝 점·공백 · 대소문자(윈도우) 까지 — 우회 방지)
        $folderPath = $this->normFolderPath($folderPath);
        if ($folderPath === '') return true; // 루트는 항상 허용
        
        // 해당 경로에 매칭되는 가장 구체적인 규칙 찾기 (길이순 정렬)
        $matchedRule = null;
        $matchLen = -1;
        
        foreach ($rules as $rule) {
            if ((int)($rule['user_id'] ?? 0) !== (int)$userId) continue;
            
            $rulePath = $this->normFolderPath($rule['folder_path'] ?? '');
            if ($rulePath === '') continue;
            
            // 정확히 일치하거나 상위 경로인지 확인
            if ($folderPath === $rulePath || strpos($folderPath . '/', $rulePath . '/') === 0) {
                if (strlen($rulePath) > $matchLen) {
                    $matchLen = strlen($rulePath);
                    $matchedRule = $rule;
                }
            }
        }
        
        if ($matchedRule === null) return true; // 규칙 없으면 허용
        
        return $this->folderRuleAllows($matchedRule, $permission);
    }

    /**
     * ★ (2026-10-28 22:39, 10차 점검, 펜닐 결정 '쓰기 금지 = 삭제도 금지') 규칙 한 줄이 이 권한을 허용하는지.
     *   폴더 권한 설정(관리 화면·folder_permission_set)은 보기·읽기·쓰기만 저장하고 삭제(can_delete)는 저장하지 않아,
     *   종전엔 삭제 검사가 늘 '없음 = 허용' 으로 통과해 쓰기를 막은 폴더도 지워졌음(관리 API 로 만든 규칙으로 재현).
     *   → can_delete 가 없는 규칙은 can_write 값을 따름(있으면 그 값). 다른 권한은 종전대로(없으면 허용)
     */
    private function folderRuleAllows(array $rule, string $permission): bool {
        if ($permission === 'can_delete' && !array_key_exists('can_delete', $rule)) {
            return (bool)($rule['can_write'] ?? true);
        }
        return (bool)($rule[$permission] ?? true);
    }
    
    /**
     * ★ (2026-10-28 21:53, 9차 점검) 항목 자신 + 그 아래에 걸린 폴더 권한 규칙까지 확인 — 폴더를 지우거나 옮기거나 복사·공유하면
     *   안에 든 하위 폴더도 같이 지워지거나 옮겨지는데, 종전엔 들어 있는 상위 폴더 권한만 봐서 보호 폴더 자체나 그걸 품은 폴더를
     *   지우고 옮기고 복사·공유할 수 있었음(재현). 펜닐 결정 "작업 자체를 거부": 이 사용자에게 걸린 규칙 중 대상 아래에 있는 규칙이
     *   하나라도 이 권한을 막으면 false. 규칙 목록만 보므로(파일을 훑지 않음) 큰 폴더·원격 스토리지에서도 같음
     */
    public function checkFolderTreePermission(int $storageId, string $path, string $permission): bool {
        if (!$this->checkFolderPermission($storageId, $path, $permission)) return false;
        $rules = $this->getFolderPermissions($storageId);
        if (empty($rules)) return true;
        $userId = $this->auth->getUserId();
        $base = $this->normFolderPath($path);
        foreach ($rules as $rule) {
            if ((int)($rule['user_id'] ?? 0) !== (int)$userId) continue;
            $rulePath = $this->normFolderPath($rule['folder_path'] ?? '');
            if ($rulePath === '') continue;
            $under = ($base === '') ? true : (strpos($rulePath, $base . '/') === 0);
            if ($under && !$this->folderRuleAllows($rule, $permission)) return false;
        }
        return true;
    }

    /**
     * 파일 목록에서 폴더 권한에 따라 필터링
     */
    public function filterByFolderPermission(int $storageId, string $currentPath, array $items): array {
        // 관리자도 폴더 권한 적용
        
        $userId = $this->auth->getUserId();
        $rules = $this->getFolderPermissions($storageId);
        
        if (empty($rules)) return $items; // 규칙 없으면 그대로
        
        // 현재 사용자의 규칙만 필터
        $userRules = array_filter($rules, fn($r) => (int)($r['user_id'] ?? 0) === (int)$userId);
        if (empty($userRules)) return $items; // 이 사용자에 대한 규칙 없으면 그대로
        
        $currentPath = $this->normFolderPath($currentPath);
        
        return array_values(array_filter($items, function($item) use ($userRules, $currentPath) {
            $itemName = $item['name'] ?? '';
            $isDir = $item['is_dir'] ?? false;
            
            // 파일은 항상 보임 (폴더 권한은 폴더만 제어)
            if (!$isDir) return true;
            
            // ★ (2026-10-28 17:23) 규칙 비교와 같은 정규화(대소문자·끝 점·공백)
            $itemPath = $this->normFolderPath($currentPath === '' ? $itemName : $currentPath . '/' . $itemName);
            
            // ★ (2026-10-29 11:11, 20차 점검) 자신·상위 규칙 중 가장 구체적인(긴) 규칙을 따름(checkFolderPermission 과 같은 기준) — 종전엔 경로순으로
            //   먼저 만난 상위 규칙('A' 보임)에서 끝나, 하위 규칙('A/B' 숨김)이 있어도 목록에 보였음(재현. 들어가면 권한 없음)
            $best = null; $bestLen = -1; $hasDescendant = false;
            foreach ($userRules as $rule) {
                $rulePath = $this->normFolderPath($rule['folder_path'] ?? '');
                if ($rulePath === '') continue;
                
                // 이 폴더 자신 또는 상위 규칙
                if ($itemPath === $rulePath || strpos($itemPath . '/', $rulePath . '/') === 0) {
                    if (strlen($rulePath) > $bestLen) { $bestLen = strlen($rulePath); $best = $rule; }
                    continue;
                }
                
                // 이 폴더가 규칙 경로의 상위 (하위 탐색 위해 보여야 함)
                if (strpos($rulePath . '/', $itemPath . '/') === 0) {
                    $hasDescendant = true;
                }
            }
            if ($best !== null) return (bool)($best['can_visible'] ?? true);
            if ($hasDescendant) return true;
            
            // 규칙에 매칭되지 않는 폴더: 스토리지 권한 따름 (기본 보임)
            return true;
        }));
    }
    
    /**
     * 스토리지의 폴더 권한 목록 가져오기 (캐싱)
     */
    private static $folderPermCache = [];
    
    public function getFolderPermissions(int $storageId): array {
        if (isset(self::$folderPermCache[$storageId])) {
            return self::$folderPermCache[$storageId];
        }
        
        $all = $this->db->load('folder_permissions');
        $filtered = array_filter($all, fn($r) => (int)($r['storage_id'] ?? 0) === $storageId);
        $filtered = array_values($filtered);
        // 폴더 경로순 정렬
        usort($filtered, function($a, $b) {
            $cmp = strcasecmp($a['folder_path'] ?? '', $b['folder_path'] ?? '');
            if ($cmp !== 0) return $cmp;
            return ($a['user_id'] ?? 0) - ($b['user_id'] ?? 0);
        });
        self::$folderPermCache[$storageId] = $filtered;
        return self::$folderPermCache[$storageId];
    }
    
    /**
     * 폴더 권한 설정 (관리자 전용)
     */
    public function setFolderPermission(int $storageId, string $folderPath, int $userId, array $perms): bool {
        $all = $this->db->load('folder_permissions');
        $folderPath = trim(str_replace('\\', '/', $folderPath), '/');
        
        // 기존 규칙 찾기
        $found = false;
        foreach ($all as &$rule) {
            if ((int)($rule['storage_id'] ?? 0) === $storageId 
                && trim($rule['folder_path'] ?? '', '/') === $folderPath 
                && (int)($rule['user_id'] ?? 0) === $userId) {
                $rule = array_merge($rule, $perms);
                $found = true;
                break;
            }
        }
        unset($rule);
        
        if (!$found) {
            $all[] = array_merge([
                'storage_id' => $storageId,
                'folder_path' => $folderPath,
                'user_id' => $userId,
                'can_visible' => 1,
                'can_read' => 1,
                'can_write' => 0,
            ], $perms);
        }
        
        self::$folderPermCache = []; // 캐시 초기화
        return $this->db->save('folder_permissions', $all);
    }
    
    /**
     * 폴더 권한 삭제
     */
    public function removeFolderPermission(int $storageId, string $folderPath, int $userId): bool {
        $all = $this->db->load('folder_permissions');
        $folderPath = trim(str_replace('\\', '/', $folderPath), '/');
        
        $all = array_values(array_filter($all, function($rule) use ($storageId, $folderPath, $userId) {
            return !((int)($rule['storage_id'] ?? 0) === $storageId 
                && trim($rule['folder_path'] ?? '', '/') === $folderPath 
                && (int)($rule['user_id'] ?? 0) === $userId);
        }));
        
        self::$folderPermCache = [];
        return $this->db->save('folder_permissions', $all);
    }
    
    /**
     * ★ (2026-10-30 01:52, 26차 점검) 폴더 권한 규칙을 옮긴 자리로 따라가게(이름 바꾸기·이동) — 규칙은 경로 글자로 걸려 있어, 종전엔 상위 폴더 이름만 바꾸거나 옮기면
     *   'Projects/Secret' 의 보기·읽기 금지 규칙이 새 경로('Projects2/Secret')에 적용되지 않아 막아 둔 폴더가 보였음. 숨김 표시(HiddenItems::movePath)와 같은 방식.
     *   옮긴 자리에 같은 사용자 규칙이 이미 있으면 옮겨 온 규칙으로 바꿈. 바뀐 게 있으면 true
     */
    public function moveFolderPermissions(int $srcStorageId, string $oldPath, int $dstStorageId, string $newPath): bool {
        $old = trim(preg_replace('#/{2,}#', '/', str_replace('\\', '/', $oldPath)), '/');   // ★ (2026-10-30 02:21, 26차 재검토) 겹친 '/' 정리
        $new = trim(preg_replace('#/{2,}#', '/', str_replace('\\', '/', $newPath)), '/');
        if ($old === '' || $new === '' || ($srcStorageId === $dstStorageId && $old === $new)) return false;
        $ci = DIRECTORY_SEPARATOR === '\\';   // Windows 는 경로 대소문자를 가리지 않음
        $match = function (string $p) use ($old, $ci): ?string {
            $a = $ci ? mb_strtolower($p) : $p; $b = $ci ? mb_strtolower($old) : $old;
            if ($a === $b) return '';
            if (strpos($a, $b . '/') === 0) return $ci ? '/' . implode('/', array_slice(explode('/', $p), substr_count($old, '/') + 1)) : substr($p, strlen($old));   // ★ (2026-10-30 03:03, 26차 재검토) 소문자로 바꾸면 길이가 달라지는 글자(İ 등)가 있어 Windows 비교는 '/' 마디 수로 자름
            return null;
        };
        $changed = false;
        $this->db->atomicUpdate('folder_permissions', function ($all) use ($srcStorageId, $dstStorageId, $new, $match, &$changed) {
            $all = is_array($all) ? $all : [];
            $moved = [];
            foreach ($all as $i => $rule) {
                if ((int)($rule['storage_id'] ?? 0) !== $srcStorageId) continue;
                $rest = $match(trim((string)($rule['folder_path'] ?? ''), '/'));
                if ($rest === null) continue;
                $rule['storage_id'] = $dstStorageId;
                $rule['folder_path'] = $new . $rest;
                $moved[] = $rule;
                unset($all[$i]);
            }
            if (!$moved) return array_values($all);
            $changed = true;
            $ci = DIRECTORY_SEPARATOR === '\\';
            $key = function ($r) use ($ci) { $fp = trim((string)($r['folder_path'] ?? ''), '/'); if ($ci) $fp = mb_strtolower($fp); return (int)($r['storage_id'] ?? 0) . '|' . $fp . '|' . (int)($r['user_id'] ?? 0); };   // ★ (2026-10-30 03:03, 26차 재검토) Windows 는 대소문자만 다른 같은 폴더 규칙이 둘 남지 않게
            $mk = [];
            foreach ($moved as $r) $mk[$key($r)] = true;
            $all = array_filter($all, function ($r) use ($mk, $key) { return !isset($mk[$key($r)]); });
            return array_values(array_merge($all, $moved));
        });
        if ($changed) self::$folderPermCache = [];
        return $changed;
    }

    /**
     * 스토리지의 전체 폴더 권한 목록 (관리자용)
     */
    public function getAllFolderPermissions(int $storageId): array {
        return $this->getFolderPermissions($storageId);
    }

    /**
     * 사용자의 특정 스토리지에 대한 전체 권한 정보 반환 (캐싱 적용)
     * 
     * @param int $storageId 스토리지 ID
     * @return array 권한 배열
     */
    /* 미사용 함수 제거됨 — getEffectivePermissions */
    
    // 권한 설정 (캐시 무효화 포함)
    public function setPermission(int $storageId, int $userId, array $permissions): array {
        $existing = $this->db->find('permissions', [
            'storage_id' => $storageId,
            'user_id' => $userId
        ]);
        
        $data = [
            'can_visible' => $permissions['can_visible'] ?? 1,
            'can_visible_webdav' => $permissions['can_visible_webdav'] ?? ($permissions['can_visible'] ?? 1),
            'can_read' => $permissions['can_read'] ?? 1,
            'can_download' => $permissions['can_download'] ?? 1,
            'can_write' => $permissions['can_write'] ?? 0,
            'can_delete' => $permissions['can_delete'] ?? 0,
            'can_share' => $permissions['can_share'] ?? 0
        ];
        
        if ($existing) {
            $this->db->update('permissions', [
                'storage_id' => $storageId,
                'user_id' => $userId
            ], $data);
        } else {
            $data['storage_id'] = $storageId;
            $data['user_id'] = $userId;
            $this->db->insert('permissions', $data);
        }
        
        // 해당 권한 캐시 무효화
        $permCacheKey = "{$storageId}_{$userId}";
        unset(self::$permissionCache[$permCacheKey]);
        
        return ['success' => true];
    }
    
    // 스토리지별 권한 목록
    public function getPermissions(int $storageId): array {
        $permissions = $this->db->findAll('permissions', ['storage_id' => $storageId]);
        $users = $this->db->load('users');
        
        // 사용자 정보 추가
        foreach ($permissions as &$perm) {
            foreach ($users as $user) {
                if ((int)$user['id'] === (int)$perm['user_id']) {
                    $perm['username'] = $user['username'];
                    $perm['display_name'] = $user['display_name'];
                    break;
                }
            }
        }
        
        return $permissions;
    }
    
    // 권한 삭제
    public function removePermission(int $storageId, int $userId): array {
        $this->db->delete('permissions', [
            'storage_id' => $storageId,
            'user_id' => $userId
        ]);
        return ['success' => true];
    }
    
    // 스토리지 타입별 아이콘
    private function getStorageIcon(string $type): string {
        $icons = [
            'local' => '📁',
            'smb' => '🖥️',
            'ftp' => '📡',
            'sftp' => '🔒',
            'webdav' => '🌐',
            's3' => '☁️',
            'home' => '🏠',
            'shared' => '📂'
        ];
        return $icons[$type] ?? '📁';
    }
    
    // 경로 정규화
    private function normalizePath(string $path): string {
        // Windows UNC 경로 처리
        if (preg_match('/^\\\\\\\\/', $path) || preg_match('/^\/\//', $path)) {
            return str_replace('/', '\\', $path);
        }
        // Windows 드라이브 경로
        if (preg_match('/^[A-Za-z]:/', $path)) {
            return rtrim(str_replace('/', '\\', $path), '\\');
        }
        // Linux 경로
        return rtrim($path, '/');
    }
    
    // 경로 접근 가능 여부 확인
    private function isPathAccessible(string $path, array $data = []): bool {
        // SMB 연결 시도
        if (($data['storage_type'] ?? '') === 'smb') {
            return $this->connectSmb($data);
        }
        
        return is_dir($path) && is_readable($path);
    }
    
    // Windows 환경 확인
    private function isWindows(): bool {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
    
    // SMB 마운트 포인트 경로 (Linux)
    private function getSmbMountPoint(string $host, string $share): string {
        $safeHost = preg_replace('/[^a-zA-Z0-9._-]/', '_', $host);
        $safeShare = preg_replace('/[^a-zA-Z0-9$._-]/', '_', $share);
        $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
        return $dataDir . DIRECTORY_SEPARATOR . 'smb_mounts' . DIRECTORY_SEPARATOR . $safeHost . '_' . $safeShare;
    }
    
    // SMB 연결 (Windows: net use, Linux: mount.cifs)
    private function connectSmb(array $data): bool {
        $host = $data['smb_host'] ?? $data['config']['host'] ?? '';
        $share = $data['smb_share'] ?? $data['config']['share'] ?? '';
        $username = $data['smb_username'] ?? $data['config']['username'] ?? '';
        $password = $data['smb_password'] ?? $data['config']['password'] ?? '';
        
        if (empty($host) || empty($share)) {
            return false;
        }
        
        // 입력값 검증
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $host)) {
            return false;
        }
        if (!preg_match('/^[a-zA-Z0-9$._\/ -]+$/', $share)) {
            return false;
        }
        
        if ($this->isWindows()) {
            return $this->connectSmbWindows($host, $share, $username, $password);
        } else {
            return $this->connectSmbLinux($host, $share, $username, $password);
        }
    }
    
    // Windows SMB 연결 (net use)
    private function connectSmbWindows(string $host, string $share, string $username, string $password): bool {
        $uncPath = "\\\\{$host}\\{$share}";
        
        if (is_dir($uncPath)) {
            return true;
        }
        
        if (!empty($username) && !empty($password)) {
            $cmd = sprintf(
                'net use %s /user:%s %s 2>&1',
                escapeshellarg($uncPath),
                escapeshellarg($username),
                escapeshellarg($password)
            );
            exec($cmd, $output, $returnCode);
            return $returnCode === 0 || is_dir($uncPath);
        }
        
        return is_dir($uncPath);
    }
    
    // Linux SMB 마운트 (mount.cifs)
    private function connectSmbLinux(string $host, string $share, string $username, string $password): bool {
        $mountPoint = $this->getSmbMountPoint($host, $share);
        
        // 이미 마운트되어 있으면 OK
        if ($this->isMounted($mountPoint)) {
            return true;
        }
        
        // 마운트 포인트 디렉토리 생성
        if (!is_dir($mountPoint)) {
            @mkdir($mountPoint, 0777, true);
        }
        
        // mount.cifs 명령어 구성
        $smbPath = "//{$host}/{$share}";
        $options = ['vers=3.0', 'iocharset=utf8', 'noperm', 'noserverino'];
        
        if (!empty($username)) {
            $options[] = 'username=' . str_replace(',', '\\,', $username);
            if (!empty($password)) {
                $options[] = 'password=' . str_replace(',', '\\,', $password);
            }
        } else {
            $options[] = 'guest';
        }
        
        $cmd = sprintf(
            'mount -t cifs %s %s -o %s 2>&1',
            escapeshellarg($smbPath),
            escapeshellarg($mountPoint),
            escapeshellarg(implode(',', $options))
        );
        
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            // vers=3.0 실패 시 vers=2.1로 재시도
            $options = array_map(function($o) {
                return $o === 'vers=3.0' ? 'vers=2.1' : $o;
            }, $options);
            
            $cmd = sprintf(
                'mount -t cifs %s %s -o %s 2>&1',
                escapeshellarg($smbPath),
                escapeshellarg($mountPoint),
                escapeshellarg(implode(',', $options))
            );
            exec($cmd, $output2, $returnCode);
            
            if ($returnCode !== 0) {
                // vers 옵션 없이 최종 재시도
                $options = array_filter($options, function($o) {
                    return strpos($o, 'vers=') !== 0;
                });
                
                $cmd = sprintf(
                    'mount -t cifs %s %s -o %s 2>&1',
                    escapeshellarg($smbPath),
                    escapeshellarg($mountPoint),
                    escapeshellarg(implode(',', $options))
                );
                exec($cmd, $output3, $returnCode);
            }
        }
        
        return $returnCode === 0 && is_dir($mountPoint);
    }
    
    // 마운트 상태 확인
    private function isMounted(string $path): bool {
        if ($this->isWindows()) return false;
        $path = realpath($path) ?: $path;
        exec('mount 2>/dev/null', $mounts);
        foreach ($mounts as $line) {
            if (strpos($line, $path) !== false) {
                return true;
            }
        }
        return false;
    }
    
    // SMB 언마운트 (Linux)
    /* 미사용 함수 제거됨 — disconnectSmb */
    
    // 실제 경로 반환 (SMB 포함)
    public function getRealPath(int $storageId): ?string {
        $storage = $this->getStorageById($storageId);
        if (!$storage) return null;
        
        if ($storage['storage_type'] === 'smb') {
            // config에서 SMB 정보 추출
            $config = $this->decodeConfig($storage['config'] ?? '');
            $host = $config['host'] ?? '';
            $share = $config['share'] ?? '';
            
            if (empty($host) || empty($share)) return null;
            
            if ($this->isWindows()) {
                // Windows: UNC 경로 + 재연결
                $this->connectSmb(['config' => $config]);
                return "\\\\{$host}\\{$share}";
            } else {
                // Linux: 마운트 포인트 + 재마운트
                $this->connectSmb(['config' => $config]);
                return $this->getSmbMountPoint($host, $share);
            }
        }
        
        return $storage['path'];
    }
    
    // config 디코딩 헬퍼
    private function decodeConfig(string $config): array {
        return \decryptStorageConfig($config);
    }
    
    /**
     * 스토리지 사용량 업데이트 (파일 업로드/삭제 시 호출)
     * @param int $storageId 스토리지 ID
     * @param int $sizeDelta 변경량 (양수: 증가, 음수: 감소)
     */
    /**
     * 백그라운드 사용량 재계산 (자동)
     * 마지막 계산으로부터 일정 시간이 지난 스토리지만 재계산
     * 
     * ★ 타임아웃 쿨다운: calculateDirectorySize가 20초 타임아웃되면
     *    해당 스토리지는 기본 24시간 동안 자동 재계산 스킵.
     *    이유: 23TB FTP 마운트 같은 느린 스토리지는 매 15분마다 20초씩
     *    worker를 점유하여 다른 요청(files/thumbnail 등)을 지연시킴 = 무한로딩 원인.
     *    수동 재계산 버튼은 이 쿨다운을 무시하고 실행 (recalcUsedSize 경로).
     */
    public function backgroundRecalcIfNeeded(int $storageId, int $intervalSeconds = 1800): bool {
        $storage = $this->getStorageById($storageId);
        if (!$storage) return false;
        
        // home 타입은 매번 직접 계산하므로 불필요
        if (($storage['storage_type'] ?? '') === 'home') return false;
        
        // ★ 타임아웃 쿨다운 체크 — 이전 호출이 타임아웃됐으면 장기간 스킵
        $timeoutCooldownFile = sys_get_temp_dir() . '/fs_recalc_timeout_' . $storageId . '.cooldown';
        $timeoutCooldownSeconds = 86400; // 24시간
        $hadTimeoutCooldown = false; // [v5.8.2e] 이 스토리지가 과거 전수스캔 타임아웃 이력이 있는지 (인덱스 근사경로 판단용)
        if (file_exists($timeoutCooldownFile)) {
            $age = time() - filemtime($timeoutCooldownFile);
            if ($age < $timeoutCooldownSeconds) {
                // 여전히 쿨다운 중 — 자동 재계산 스킵
                return false;
            } else {
                // 쿨다운 만료 — 마커 제거하고 한 번 더 시도 (단, 타임아웃 이력은 기억)
                $hadTimeoutCooldown = true;
                @unlink($timeoutCooldownFile);
            }
        }
        
        // 마지막 계산 시간 확인
        $lastCalc = (int)($storage['used_size_updated_at'] ?? 0);
        if ($lastCalc > 0 && (time() - $lastCalc) < $intervalSeconds) {
            return false; // 아직 간격 안 됨
        }
        
        // 락 파일로 중복 실행 방지
        $lockFile = sys_get_temp_dir() . '/fs_recalc_' . $storageId . '.lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < $intervalSeconds) {
            return false;
        }
        @touch($lockFile);
        
        // ★ 원격 스토리지(ftp/sftp/webdav/s3/smb): 인덱스 DB에서 합산
        // 기존엔 calculateDirectorySize로 fs 스캔했는데 FTP 같은 네트워크 경로는 매우 느려서
        // 23TB짜리는 20초 타임아웃 발생 → 매번 worker 점유 = 무한로딩 원인이었음
        // 대안: 인덱스 DB 사용. 인덱스 없으면 fs 스캔으로 폴백 (원본 동작 유지)
        // [v5.8.2e] smb 추가: 수동 재계산(recalculateUsedSize)은 이미 smb를 원격으로 취급하는데
        //   자동 재계산만 smb를 로컬 전수스캔으로 보내던 불일치 수정. smb도 네트워크 프로토콜이라
        //   전수스캔은 비용 큼 → 다른 원격타입과 동일하게 인덱스 SUM(또는 미동기화 시 skip) 처리.
        $remoteTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
        $storageType = $storage['storage_type'] ?? 'local';
        if (in_array($storageType, $remoteTypes)) {
            $fileIndex = \FileIndex::getInstance();
            if ($fileIndex->isAvailable()) {
                // 스토리지별 last_sync 확인 + 체크포인트 없음 체크
                $storageSyncStr2 = $fileIndex->getMeta('last_sync_storage_' . $storageId);
                $storageSyncTs2 = $storageSyncStr2 ? strtotime($storageSyncStr2) : 0;
                $_dataDirChk2 = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
                $checkpointFile2 = $_dataDirChk2 . DIRECTORY_SEPARATOR . 'index_sync_checkpoint_' . $storageId . '.json';
                
                if ($storageSyncTs2 > 0 && !is_file($checkpointFile2)) {
                    // 인덱스가 한 번이라도 완전 동기화됐고 진행 중 아님 → 인덱스 사용
                    $usedSize = $fileIndex->getStorageTotalSize($storageId);
                    
                    $this->db->update('storages', ['id' => $storageId], [
                        'used_size' => $usedSize,
                        'used_size_updated_at' => time()
                    ]);
                    self::$storageCache = [];
                    @unlink($lockFile);
                    return true;
                }
            }
            // 인덱스 없거나 동기화 전이면 skip (fs 스캔은 원격에 비용 큼)
            @unlink($lockFile);
            return false;
        }
        
        $path = $this->getRealPath($storageId);
        if (!$path || !is_dir($path)) {
            @unlink($lockFile);
            return false;
        }
        
        // ★ 로컬 스토리지: 인덱스 DB가 최신이면 거기서 합산 (23TB+ 대용량 대응)
        // calculateDirectorySize는 RecursiveIteratorIterator로 전수 스캔하는데
        // 23TB 스토리지는 수천만 파일이라 20초 타임아웃 발생 → 매번 worker 점유 = 무한로딩
        // 인덱스 DB의 SUM 쿼리는 0.1초 이내에 완료되므로 용량과 무관하게 확장 가능.
        // 조건: 해당 스토리지가 24시간 이내에 완전 동기화됨 + 체크포인트 없음(진행 중 아님)
        $fileIndex = \FileIndex::getInstance();
        if ($fileIndex->isAvailable()) {
            // 스토리지별 last_sync 사용 (전역 last_sync은 다른 스토리지 완료에도 갱신돼서 부정확)
            $storageSyncStr = $fileIndex->getMeta('last_sync_storage_' . $storageId);
            $storageSyncTs = $storageSyncStr ? strtotime($storageSyncStr) : 0;
            
            // 체크포인트 파일 존재 여부 = 현재 점진적 인덱싱 진행 중 = DB 불완전
            $_dataDirChk = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
            $checkpointFile = $_dataDirChk . DIRECTORY_SEPARATOR . 'index_sync_checkpoint_' . $storageId . '.json';
            $syncInProgress = is_file($checkpointFile);
            
            // 해당 스토리지 동기화가 24시간 이내에 완전 완료됐고, 진행 중이 아닌 경우만 인덱스 사용
            if ($storageSyncTs > 0 && (time() - $storageSyncTs) < 86400 && !$syncInProgress) {
                $storageStats = $fileIndex->getStorageStats($storageId);
                if (($storageStats['files'] ?? 0) > 0) {
                    // 인덱스에 해당 스토리지 파일이 있음 → SUM 쿼리로 즉시 계산
                    $usedSize = $fileIndex->getStorageTotalSize($storageId);
                    
                    $this->db->update('storages', ['id' => $storageId], [
                        'used_size' => $usedSize,
                        'used_size_updated_at' => time()
                    ]);
                    self::$storageCache = [];
                    @unlink($lockFile);
                    
                    // 성공 시 이전 타임아웃 쿨다운 마커 제거
                    if (file_exists($timeoutCooldownFile)) {
                        @unlink($timeoutCooldownFile);
                    }
                    
                    // 로그 (data/scan_perf.log 있을 때만)
                    $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
                    $perfLog = $dataDir . '/scan_perf.log';
                    if (is_file($perfLog)) {
                        @file_put_contents($perfLog,
                            sprintf("[%s] storage_recalc via_index storage_id=%d name=%s size=%s (instant)\n",
                                date('Y-m-d H:i:s'), $storageId,
                                substr($storage['name'] ?? '?', 0, 30),
                                $this->formatSize($usedSize)),
                            FILE_APPEND | LOCK_EX);
                    }
                    return true;
                }
            }
            // 인덱스가 없거나 stale하거나 진행 중 → fs 스캔 경로로 계속 (아래)
        }
        
        // ★ [v5.8.2e] 전수스캔 폴백 직전 안전장치 (대용량/네트워크 스토리지 무한 타임아웃 방지)
        //   문제: ftphdd 같은 대용량(10만+ 파일)·네트워크 마운트 스토리지는 완전동기화 조건
        //         (24h 이내 완전동기화 + 진행중 아님)을 자주 못 만족해 아래 calculateDirectorySize
        //         전수스캔으로 빠지는데, 전수스캔은 20초 타임아웃 → 결과 폐기(used_size 갱신 안 됨)
        //         → 순수 낭비 + 워커 20초 점유(루트 접근 스파이크의 서버측 원인).
        //   해결: 과거 타임아웃 이력이 있는 스토리지($hadTimeoutCooldown)는 전수스캔이 반복 실패하므로,
        //         인덱스에 파일이 있으면 근사 SUM(0.1초)을 채택하고 전수스캔을 건너뛴다.
        //   안전성 근거:
        //     - 완전동기화되면 위쪽 authoritative fast-path가 먼저 잡아 정확값으로 정정 + 쿨다운 마커 제거.
        //     - 근사값이라도 "폐기되는 타임아웃"보다 항상 낫다(현재는 갱신조차 안 됨 → 회귀 없음).
        //     - 쿨다운 마커를 다시 찍어 24h간 전수스캔 재시도 자체를 억제(무한 20초 낭비 제거).
        //     - 타임아웃 이력이 없는 정상/소용량 스토리지엔 전혀 영향 없음(아래 전수스캔 그대로 수행).
        //   [v5.8.2e 7/9 보강] 트리거에 (B) 대용량(인덱스 파일 수) 추가:
        //     (A) $hadTimeoutCooldown(마커)만으로는 부족 — via_index 성공이 마커를 지워(1308행) 조건이 유지 안 됨.
        //         실제 로그(7/9)에서 via_index_approx 0건, ftphdd 여전히 20초 타임아웃 반복 확인됨.
        //     (B) 대용량은 마커와 무관하게 파일 수로 판단 → 전수스캔 타임아웃 위험 스토리지를 영구 차단.
        $FULLSCAN_RISK_FILES = 30000;  // 인덱스 파일 수 이 이상 = 전수스캔 20초 타임아웃 위험 → 인덱스 SUM 사용
        if (isset($fileIndex) && $fileIndex->isAvailable()) {
            $statsFallback = $fileIndex->getStorageStats($storageId);
            $fileCountFb = (int)($statsFallback['files'] ?? 0);
            if ($fileCountFb > 0 && ($hadTimeoutCooldown || $fileCountFb >= $FULLSCAN_RISK_FILES)) {
                $usedSize = $fileIndex->getStorageTotalSize($storageId);
                $this->db->update('storages', ['id' => $storageId], [
                    'used_size' => $usedSize,
                    'used_size_updated_at' => time()
                ]);
                self::$storageCache = [];
                // 전수스캔 재시도를 24h간 억제 (다음 완전동기화 시 fast-path가 마커 제거하며 정확값으로 정정)
                @touch($timeoutCooldownFile);
                @unlink($lockFile);
                // 로그 (data/scan_perf.log 있을 때만)
                $dataDirFb = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
                $perfLogFb = $dataDirFb . '/scan_perf.log';
                if (is_file($perfLogFb)) {
                    @file_put_contents($perfLogFb,
                        sprintf("[%s] storage_recalc via_index_approx storage_id=%d name=%s size=%s files=%d (fullscan skipped: %s)\n",
                            date('Y-m-d H:i:s'), $storageId,
                            substr($storage['name'] ?? '?', 0, 30),
                            $this->formatSize($usedSize), $fileCountFb,
                            $hadTimeoutCooldown ? 'prior timeout' : 'large storage'),
                        FILE_APPEND | LOCK_EX);
                }
                return true;
            }
        }
        
        // 사용량 계산 (인덱스 DB 없거나 stale한 경우의 폴백)
        // 상세로그(v5.8.2e): 이 전수 fs 스캔은 대용량/네트워크(smb 등)에서 느림·타임아웃의 주원인.
        //   여기 FULLSCAN_START가 찍히면 = 해당 스토리지가 인덱스 즉시경로를 못 타고 전수스캔 중이라는 뜻.
        //   특히 smb 타입이 여기 찍히면 재계산 분기 원격목록 누락(오분류) 신호.
        $_fsScanStart = microtime(true);
        $_fsScanPerfLog = (defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data')) . '/scan_perf.log';
        if (is_file($_fsScanPerfLog)) {
            @file_put_contents($_fsScanPerfLog,
                sprintf("[%s] storage_recalc FULLSCAN_START storage_id=%d type=%s name=%s (index unavailable/stale -> recursive fs scan)\n",
                    date('Y-m-d H:i:s'), $storageId, ($storage['storage_type'] ?? '?'),
                    substr($storage['name'] ?? '?', 0, 30)),
                FILE_APPEND | LOCK_EX);
        }
        $usedSize = $this->calculateDirectorySize($path);
        
        // ★ 타임아웃 감지 — 쿨다운 마커 세팅
        // 타임아웃된 계산 결과는 부분합계라 used_size 업데이트 안 함 (이전 값 유지)
        // used_size_updated_at도 갱신 안 함 — UI에 "방금 재계산됨"으로 잘못 표시되는 것 방지
        // 쿨다운 24h 체크가 먼저 실행되므로 interval 체크와 무한 루프 충돌 없음
        if ($this->lastCalcTimedOut) {
            @touch($timeoutCooldownFile);
            @unlink($lockFile);
            
            // 로그 남기기 (data/scan_perf.log 있을 때만)
            $dataDir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
            $perfLog = $dataDir . '/scan_perf.log';
            if (is_file($perfLog)) {
                @file_put_contents($perfLog,
                    sprintf("[%s] storage_recalc TIMEOUT storage_id=%d name=%s file_count=%d -> cooldown 24h\n",
                        date('Y-m-d H:i:s'), $storageId,
                        substr($storage['name'] ?? '?', 0, 30),
                        $this->lastCalcFileCount),
                    FILE_APPEND | LOCK_EX);
            }
            return false;
        }
        
        // DB 업데이트 (used_size + 타임스탬프) — 정상 완료된 경우만
        $this->db->update('storages', ['id' => $storageId], [
            'used_size' => $usedSize,
            'used_size_updated_at' => time()
        ]);
        
        // 캐시 무효화
        self::$storageCache = [];
        
        // 상세로그(v5.8.2e): 전수스캔이 타임아웃 없이 완료된 경우의 소요시간 기록
        if (isset($_fsScanPerfLog) && is_file($_fsScanPerfLog)) {
            @file_put_contents($_fsScanPerfLog,
                sprintf("[%s] storage_recalc FULLSCAN_DONE storage_id=%d type=%s elapsed=%.1fs size=%s\n",
                    date('Y-m-d H:i:s'), $storageId, ($storage['storage_type'] ?? '?'),
                    microtime(true) - $_fsScanStart, $this->formatSize($usedSize)),
                FILE_APPEND | LOCK_EX);
        }
        
        @unlink($lockFile);
        return true;
    }

    public function updateUsedSize(int $storageId, int $sizeDelta): void {
        $storage = $this->getStorageById($storageId);
        if (!$storage) return;
        
        // home 타입은 사용자별 quota 사용 (used_size 사용 안함)
        if (($storage['storage_type'] ?? '') === 'home') return;
        
        if ($sizeDelta === 0) return;
        // ★ (2026-10-29 04:18, 17차) 잠금 안에서 최신 값을 읽어 더함 — 종전엔 캐시에서 읽은 값에 더한 결과를 덮어써, 동시에 올리기·지우기가 겹치면
        //   한쪽 변화가 사라졌음(예: 두 업로드가 같은 옛 값에 각자 더해 저장)
        $this->db->atomicUpdate('storages', function ($rows) use ($storageId, $sizeDelta) {
            foreach ($rows as &$row) {
                if ((int)($row['id'] ?? 0) === $storageId) {
                    $row['used_size'] = max(0, (int)($row['used_size'] ?? 0) + $sizeDelta);
                    break;
                }
            }
            unset($row);
            return $rows;
        });
        
        // 캐시 무효화
        self::$storageCache = [];
    }
    
    /**
     * 스토리지 사용량 재계산 (관리자용)
     * @param int $storageId 스토리지 ID
     * @return array 결과
     */
    public function recalculateUsedSize(int $storageId): array {
        $storage = $this->getStorageById($storageId);
        if (!$storage) {
            return ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
        }
        
        // home 타입은 제외
        if (($storage['storage_type'] ?? '') === 'home') {
            return ['success' => false, 'error' => __('api_err_home_storage_quota', '개인폴더는 사용자별 용량을 사용합니다.')];
        }
        
        $remoteTypes = ['ftp', 'sftp', 'webdav', 's3', 'smb'];
        $storageType = $storage['storage_type'] ?? 'local';
        
        if (in_array($storageType, $remoteTypes)) {
            // 원격 스토리지: 인덱스 DB에서 합산 (인덱스 재구축 필요)
            $fileIndex = \FileIndex::getInstance();
            if (!$fileIndex->isAvailable()) {
                return ['success' => false, 'error' => __('api_index_unavailable', '인덱스 DB를 사용할 수 없습니다. 인덱스를 먼저 구축하세요.')];
            }
            $usedSize = $fileIndex->getStorageTotalSize($storageId);
        } else {
            // 로컬 스토리지: 파일시스템 직접 계산
            $path = $this->getRealPath($storageId);
            if (!$path || !is_dir($path)) {
                return ['success' => false, 'error' => __('api_err_storage_path_not_accessible', '스토리지 경로에 접근할 수 없습니다.')];
            }
            
            // ★ 수동 재계산도 인덱스 DB가 최신이면 우선 사용 (23TB+ 대용량 대응)
            // 인덱스 DB는 0.1초 안에 완료되므로 즉시 정확한 값 반환 가능
            // 조건: 해당 스토리지 완전 동기화 24h 이내 + 체크포인트 없음
            $fileIndex = \FileIndex::getInstance();
            $useIndex = false;
            if ($fileIndex->isAvailable()) {
                $storageSyncStr = $fileIndex->getMeta('last_sync_storage_' . $storageId);
                $storageSyncTs = $storageSyncStr ? strtotime($storageSyncStr) : 0;
                $_dataDirChk = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data');
                $checkpointFile = $_dataDirChk . DIRECTORY_SEPARATOR . 'index_sync_checkpoint_' . $storageId . '.json';
                
                if ($storageSyncTs > 0 && (time() - $storageSyncTs) < 86400 && !is_file($checkpointFile)) {
                    $storageStats = $fileIndex->getStorageStats($storageId);
                    if (($storageStats['files'] ?? 0) > 0) {
                        $useIndex = true;
                    }
                }
            }
            
            if ($useIndex) {
                // 인덱스 DB 기반 즉시 계산
                $usedSize = $fileIndex->getStorageTotalSize($storageId);
                
                // 성공 시 이전 타임아웃 쿨다운 마커 제거
                $timeoutCooldownFile = sys_get_temp_dir() . '/fs_recalc_timeout_' . $storageId . '.cooldown';
                if (file_exists($timeoutCooldownFile)) {
                    @unlink($timeoutCooldownFile);
                }
            } else {
                // 폴백: fs 스캔 (타임아웃 쿨다운 무시)
                $usedSize = $this->calculateDirectorySize($path);
                
                if ($this->lastCalcTimedOut) {
                    // 타임아웃 — 쿨다운 마커 세팅 (24시간)
                    // used_size / updated_at 모두 갱신 안 함 (부정확한 값 유지 방지)
                    $timeoutCooldownFile = sys_get_temp_dir() . '/fs_recalc_timeout_' . $storageId . '.cooldown';
                    @touch($timeoutCooldownFile);
                    return [
                        'success' => false,
                        'error' => __('recalc_timeout',
                            '사용량 계산이 20초 내에 완료되지 않았습니다 (이 스토리지는 너무 크거나 느린 저장소입니다). 인덱스를 먼저 재구축한 뒤 다시 시도하시면 즉시 계산 가능합니다. 부분 스캔 결과는 저장되지 않았습니다.'),
                        'partial_size' => $usedSize,
                        'file_count_scanned' => $this->lastCalcFileCount,
                        'timeout' => true,
                        'hint_rebuild_index' => true
                    ];
                }
                
                // 정상 완료 — 이전 쿨다운 마커가 있으면 제거
                $timeoutCooldownFile = sys_get_temp_dir() . '/fs_recalc_timeout_' . $storageId . '.cooldown';
                if (file_exists($timeoutCooldownFile)) {
                    @unlink($timeoutCooldownFile);
                }
            }
        }
        
        $this->db->update('storages', ['id' => $storageId], [
            'used_size' => $usedSize,
            'used_size_updated_at' => time()
        ]);
        
        // 캐시 무효화
        self::$storageCache = [];
        
        return [
            'success' => true, 
            'used_size' => $usedSize,
            'used_size_formatted' => $this->formatSize($usedSize)
        ];
    }
    
    /**
     * 디렉토리 크기 계산
     * 타임아웃 여부는 $this->lastCalcTimedOut 플래그에 저장 (호출자가 체크 가능)
     */
    private $lastCalcTimedOut = false;
    private $lastCalcFileCount = 0;
    
    private function calculateDirectorySize(string $path, int $maxSeconds = 20): int {
        $size = 0;
        $this->lastCalcTimedOut = false;
        $this->lastCalcFileCount = 0;
        
        if (!is_dir($path)) return 0;
        
        $startTime = time();
        $fileCount = 0;
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                // 시간 초과 방지 — 타임아웃 시 플래그 세팅
                if ((time() - $startTime) > $maxSeconds) {
                    $this->lastCalcTimedOut = true;
                    break;
                }
                
                // 숨김 파일 제외 (.htaccess, .gitignore 등) - 목록 표시와 일관성
                if (substr($file->getFilename(), 0, 1) === '.') continue;
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
                
                // IO 양보: 매 200개 항목마다 5ms 쉼
                // 전체 스캔 시간은 약간 늘지만 다른 요청이 디스크 I/O 획득 가능 → 무한로딩 방지
                $fileCount++;
                if ($fileCount % 200 === 0) {
                    usleep(5000);
                }
            }
        } catch (\Throwable $e) {
            // 접근 불가 디렉토리 등 에러 무시
        }
        
        $this->lastCalcFileCount = $fileCount;
        return $size;
    }
    
    /**
     * 파일 크기 포맷팅 (공통 함수 사용)
     */
    private function formatSize(int $bytes): string {
        return formatFileSize($bytes);
    }
    
    /**
     * 스토리지 용량 정보 조회
     */
    public function getQuotaInfo(int $storageId): array {
        $storage = $this->getStorageById($storageId);
        if (!$storage) {
            return ['success' => false, 'error' => __('api_err_storage_not_found', '스토리지를 찾을 수 없습니다.')];
        }
        
        $quota = (int)($storage['quota'] ?? 0);
        $usedSize = (int)($storage['used_size'] ?? 0);
        
        return [
            'success' => true,
            'quota' => $quota,
            'used_size' => $usedSize,
            'available' => $quota > 0 ? max(0, $quota - $usedSize) : -1,
            'quota_formatted' => $quota > 0 ? $this->formatSize($quota) : __('unlimited'),
            'used_size_formatted' => $this->formatSize($usedSize)
        ];
    }
    
    /**
     * 스토리지 폴더에 .htaccess 보호 파일 생성
     * URL 직접 접근 차단용
     */
    private function createProtectionFile(string $path): bool {
        if (!is_dir($path)) {
            return false;
        }
        
        $htaccessPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
        
        // 이미 .htaccess가 있으면 건드리지 않음
        if (file_exists($htaccessPath)) {
            return true;
        }
        
        $content = <<<'HTACCESS'
# FileStation 스토리지 보호
# 모든 파일은 api.php를 통해서만 접근 가능

# Apache 2.4+
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>

# Apache 2.2
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>

# Fallback
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule .* - [F,L]
</IfModule>
HTACCESS;
        
        return @file_put_contents($htaccessPath, $content) !== false;
    }
    
    /**
     * 원격 스토리지 연결 테스트
     */
    private function testRemoteConnection(string $type, array $config): array {
        // StorageAdapter가 로드되어 있는지 확인
        if (!class_exists('StorageAdapterFactory')) {
            return ['success' => false, 'error' => __('api_err_adapter_not_loaded', 'StorageAdapter가 로드되지 않았습니다.')];
        }
        
        // 임시 스토리지 정보 생성
        $tempStorage = [
            'storage_type' => $type,
            'config' => encryptStorageConfig($config),
            'path' => ''
        ];
        
        // 어댑터 생성 및 연결 테스트
        $adapter = StorageAdapterFactory::create($tempStorage);
        if (!$adapter) {
            return ['success' => false, 'error' => __('api_err_unsupported_storage_type', '지원하지 않는 스토리지 유형입니다.')];
        }
        
        if (!$adapter->connect()) {
            // 상세 에러 메시지 반환
            if (method_exists($adapter, 'getLastError')) {
                $error = $adapter->getLastError();
                if ($error) {
                    return ['success' => false, 'error' => $error];
                }
            }
            return ['success' => false, 'error' => __('api_err_remote_conn_failed_detail', '원격 스토리지에 연결할 수 없습니다.')];
        }
        
        // 연결 성공 - 연결 해제
        $adapter->disconnect();
        
        return ['success' => true];
    }
}