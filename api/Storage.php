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
        // ★ (2026-11-02 09:01, 41차 점검) 권한 줄에 can_visible 키가 없는(옛) 줄을 웹 목록은 '보임(1)', 실제 검사(checkPermission)는 '없음(false)'으로 읽어
        //   사이드바엔 보이는데 열면 '권한이 없습니다'였음 → 웹 목록도 실제 검사·관리 화면(빠진 키=체크 해제)과 같게 '없음(0)'. WebDAV 목록은 실제 검사가
        //   can_read 라 종전처럼 1(지금 되는 WebDAV 접근을 막지 않게)
        $visDefault = $context === 'webdav' ? 1 : 0;
        foreach ($permissions as $perm) {
            if ($perm[$visibleKey] ?? ($perm['can_visible'] ?? $visDefault)) {
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
                            if (!($perm[$visibleKey] ?? ($perm['can_visible'] ?? $visDefault))) {   // ★ (2026-11-02 09:01, 41차 점검) 위 $visDefault 와 같은 이유
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
                        if ($perm[$visibleKey] ?? ($perm['can_visible'] ?? $visDefault)) {
                            // ★ (2026-11-02 09:01, 41차 점검) 빠진 키는 checkPermission 과 같이 '없음(0)'으로 표시 — 종전 1 은 화면엔 받기 가능으로 보이는데 실제 받기는 거부됐음
                            $storage['can_read'] = $perm['can_read'] ?? 0;
                            $storage['can_write'] = $perm['can_write'] ?? 0;
                            $storage['can_download'] = $perm['can_download'] ?? 0;
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
                        if (!($perm[$visibleKey] ?? ($perm['can_visible'] ?? $visDefault))) {   // ★ (2026-11-02 09:01, 41차 점검) 위 $visDefault 와 같은 이유
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
                        $storage['can_download'] = $perm['can_download'] ?? 0;   // ★ (2026-11-02 09:01, 41차 점검) checkPermission 과 같이 빠진 키 = 없음(종전 1 은 화면만 받기 가능)
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
        
        // ★ (2026-11-01 09:44, 38차 점검) 관리자·'스토리지' 관리 권한자가 아니면 접속 설정(config — 레거시·암호화 실패 폴백이면 평문 비밀번호)·레거시 smb_password·
        //   default_permissions·created_by 를 빼고 보냄. 화면(app.js)은 이 목록에서 이 값들을 쓰지 않음(쓰는 건 id·name·storage_type·icon·path·can_*)
        //   path 는 사이드바 툴팁·네트워크 아이콘 판정에 쓰여 그대로 둠
        if (!$isAdmin && !$this->auth->hasAdminPerm('storages')) {
            foreach ([&$home, &$public, &$shared] as &$_grp) {
                foreach ($_grp as &$_st) {
                    unset($_st['config'], $_st['smb_password'], $_st['default_permissions'], $_st['created_by']);
                }
                unset($_st);
            }
            unset($_grp);
        } else {
            // ★ (2026-11-02 09:01, 41차 점검) 관리자·스토리지 관리 권한자에게도 config 는 비밀번호를 가려 보냄(storage_get 과 같은 기준) — 옛 base64 형식(키 없이 풀림)이면
            //   비밀번호가 평문으로 나갔음. 화면은 이 목록의 config 를 쓰지 않음(수정 창은 storage_get)
            foreach ([&$home, &$public, &$shared] as &$_grp) {
                foreach ($_grp as &$_st) {
                    if (array_key_exists('config', $_st)) $_st['config'] = $this->maskConfigForList($_st['config']);
                    unset($_st['smb_password']);
                }
                unset($_st);
            }
            unset($_grp);
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
            // ★ (2026-11-02 09:01, 41차 점검) config 비밀번호 가림(storage_get 과 같은 기준) — 종전엔 config 를 그대로 보내 옛 base64 형식이면 원격 스토리지 비밀번호가 평문으로 나갔음.
            //   화면(스토리지 관리 표·동기화 선택 상자)은 이 목록의 config 를 쓰지 않음
            if (array_key_exists('config', $s)) $s['config'] = $this->maskConfigForList($s['config']);

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

    // ★ (2026-11-02 09:01, 41차 점검) 목록 응답용 config — 풀 수 있으면 풀어서 비밀 항목을 비움(getStorage 와 같은 키). 못 풀면 ENC: 값만 그대로(키 없이는 못 푸는 값), 그 밖의 형식은 빈 값
    private function maskConfigForList($config) {
        if (empty($config)) return $config;
        $dec = $this->decodeConfig($config);
        if (!$dec) return (is_string($config) && strpos($config, 'ENC:') === 0) ? $config : '';
        foreach (['password', 'secret_key', 'client_secret', 'app_secret', 'private_key', 'key_passphrase'] as $key) {   // ★ (2026-11-11 21:13, 84차 STG-4) SFTP 키 암호(key_passphrase)도 가림 — 종전엔 storages_all·storage_get 응답에 평문(부관리자도 봄, 재현)
            if (isset($dec[$key]) && !empty($dec[$key])) $dec[$key] = '';
        }
        return $dec;
    }

    // 스토리지 추가
    public function addStorage(array $data): array {
        if (!isset($data['name']) || !is_scalar($data['name']) || (string)$data['name'] === '' || $data['name'] === false) {   // ★ (2026-11-11 21:15, 84차 STG-6) empty() 가 이름 '0' 을 빈 값으로 봐 추가가 거부됐음(수정은 됨, 재현)
            return ['success' => false, 'error' => __('api_err_name_required', '이름은 필수입니다.')];
        }
        
        // ★ (2026-11-02 09:04, 41차 점검) totp_settings.json 이 깨져 암호화 키를 믿을 수 없으면 접속 정보(config) 저장을 처음부터 거부(D8#2) — encryptStorageConfig 가 예외를 던져
        //   500 '서버 오류'로 끝나던 것을 같은 문구의 일반 오류로. 연결 시도·.htaccess 생성 전에 멈춤. config 없는 로컬 스토리지는 암호화하지 않으므로 종전대로
        if (!empty($data['config']) && function_exists('fsTotpKeyUnreliableError') && ($kErr = fsTotpKeyUnreliableError()) !== null) {
            return ['success' => false, 'error' => $kErr];
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
            if ($q === 0 && is_numeric($data['quota']) && (float)$data['quota'] > 0) $q = 1;   // ★ (2026-11-02 10:25, 42차 점검) 1 바이트 미만 소수(0.5 등)가 (int) 로 0(=무제한)이 되던 것 → 제한이 있는 쪽(1 바이트)으로(E8#3 서버 쪽). 화면은 정수 바이트만 보내 영향 없음
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
                    // ★ (2026-11-16 16:04, 254차 점검) 값을 0/1 로(permBit) — 종전엔 그대로 저장해 글자 'false'·[0]·객체가 '허용'으로 읽혔음(setPermission 과 같음)
                    'can_visible' => self::permBit($perm['can_visible'] ?? null, 1),
                    'can_visible_webdav' => self::permBit($perm['can_visible_webdav'] ?? ($perm['can_visible'] ?? null), 1),
                    'can_read' => self::permBit($perm['can_read'] ?? null, 1),
                    'can_download' => self::permBit($perm['can_download'] ?? null, 1),
                    'can_write' => self::permBit($perm['can_write'] ?? null, 0),
                    'can_delete' => self::permBit($perm['can_delete'] ?? null, 0),
                    'can_share' => self::permBit($perm['can_share'] ?? null, 0)
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
            $config = $this->decodeConfig($storage['config']);   // ★ (2026-11-02 09:01, 41차 점검) 배열로 저장된 config 도 받아 줌(어댑터와 같게) — 종전엔 decryptStorageConfig(string) 에 배열이 들어가 TypeError(500)
            if ($config) {
                // 민감한 정보 마스킹
                foreach (['password', 'secret_key', 'client_secret', 'app_secret', 'private_key', 'key_passphrase'] as $key) {   // ★ (2026-11-11 21:13, 84차 STG-4) 위와 같음
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
            if ($q === 0 && is_numeric($data['quota']) && (float)$data['quota'] > 0) $q = 1;   // ★ (2026-11-02 10:25, 42차 점검) addStorage 와 같음(소수가 0=무제한이 되지 않게, E8#3)
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
        // ★ (2026-11-02 09:45, 41차 재검토) 화면은 local·home·shared 에도 늘 config:{} 를 보내 isset 이 참 → 암호화 키 파일이 깨진 상태면 로컬 스토리지 이름·용량·권한 수정까지 거부됐음(검토 발견).
        //   새 config 도 기존 config 도 비었으면 바꿀 것이 없으므로 건너뜀(종전엔 빈 배열을 암호화해 저장했을 뿐 — 풀면 같은 [])
        // ★ (2026-11-02 10:15, 42차 점검) 새 config 가 비었으면 통째로 건너뜀(기존 값 유지) — 41차 전엔 수정할 때마다 빈 config 를 암호화해 'ENC:…' 로 저장해서, 한 번이라도 수정한 로컬 스토리지는 위 조건으로도 여전히 거부됐음(검토 재현)
        // ★ (2026-11-02 10:59, 42차 재검토) 종류를 바꿨는데 새 config 가 비었으면(원격 → local 등) 옛 접속 정보를 지움 — 위 '비면 건너뜀' 때문에 옛 FTP/SFTP 비밀번호가 암호화된 채 남아,
        //   나중에 다시 원격으로 바꾸고 비밀번호 칸을 비우면 옛 값이 되살아났음(검토 발견). 빈 문자열은 암호화하지 않으므로 키가 깨진 상태에서도 됨
        if (isset($data['config']) && empty($data['config']) && isset($data['storage_type']) && $data['storage_type'] !== ($storage['storage_type'] ?? null) && !empty($storage['config'])) {
            $updateData['config'] = '';
        }
        if (isset($data['config']) && !empty($data['config'])) {
            // ★ (2026-11-02 09:04, 41차 점검) addStorage 와 같은 이유로 암호화 키를 믿을 수 없으면 거부 — 이 상태에선 기존 config 도 못 풀어([]) '비밀번호 칸 비우면 기존 값 유지'도 안 됐음(D8#2)
            if (function_exists('fsTotpKeyUnreliableError') && ($kErr = fsTotpKeyUnreliableError()) !== null) {
                return ['success' => false, 'error' => $kErr];
            }
            $newConfig = $data['config'];

            // 기존 config 로드
            $existingConfig = [];
            if (!empty($storage['config'])) {
                $existingConfig = $this->decodeConfig($storage['config']);   // ★ (2026-11-02 09:01, 41차 점검) 배열 config 도(위 getStorage 와 같은 이유)
            }
            
            // 비밀번호 등 빈 값이면 기존 값 유지
            // ★ (2026-11-02 11:36, 43차 점검) 종류가 그대로일 때만 — FTP → SFTP 처럼 종류를 바꾸면 옛 비밀번호가 새 config 로 넘어가 새 호스트 연결 시험에까지 쓰였음(검토 발견)
            $sameType = !isset($data['storage_type']) || $data['storage_type'] === ($storage['storage_type'] ?? null);
            foreach (['password', 'secret_key', 'client_secret', 'app_secret', 'private_key'] as $key) {
                if ($sameType && isset($newConfig[$key]) && empty($newConfig[$key]) && !empty($existingConfig[$key])) {
                    $newConfig[$key] = $existingConfig[$key];
                }
            }
            // ★ (2026-11-11 21:13, 84차 STG-4) 키 암호는 수정 창에 칸이 없어 보내지 않음 → 같은 종류면(키 인증일 때만 쓰임) 저장된 값을 이어받음. 응답에서 가리게 되면서 빈 값으로 올 수도 있어 빈 값도 같게
            if ($sameType && empty($newConfig['key_passphrase']) && !empty($existingConfig['key_passphrase'])) $newConfig['key_passphrase'] = $existingConfig['key_passphrase'];
            
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
                    // ★ (2026-11-16 16:04, 254차 점검) 값을 0/1 로(permBit) — 종전엔 그대로 저장해 글자 'false'·[0]·객체가 '허용'으로 읽혔음(setPermission 과 같음)
                    'can_visible' => self::permBit($perm['can_visible'] ?? null, 1),
                    'can_visible_webdav' => self::permBit($perm['can_visible_webdav'] ?? ($perm['can_visible'] ?? null), 1),
                    'can_read' => self::permBit($perm['can_read'] ?? null, 1),
                    'can_download' => self::permBit($perm['can_download'] ?? null, 1),
                    'can_write' => self::permBit($perm['can_write'] ?? null, 0),
                    'can_delete' => self::permBit($perm['can_delete'] ?? null, 0),
                    'can_share' => self::permBit($perm['can_share'] ?? null, 0)
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
        // ★ (2026-11-02 10:24, 42차 점검) 딸린 데이터 정리를 purgeStorageData() 로 모음 — 사용자 삭제(Auth::deleteUserData)의 홈 스토리지 행 제거도 같은 정리를 부를 수 있게(E1#1)
        $this->purgeStorageData((int)$id);

        // 캐시 무효화
        self::$storageCache = [];

        return ['success' => true];
    }

    /**
     * ★ (2026-11-02 10:24, 42차 점검) 스토리지 번호에 딸린 데이터 정리(행 자체는 부르는 쪽이 지움) — deleteStorage 가 하던 정리 + 파일 버전(meta 의 storage_id).
     *   번호는 '최대+1'로 다시 쓰여, 남겨 두면 같은 번호를 받은 새 스토리지(사용자 삭제 뒤 새 가입자 홈 포함)에 옛 색인·숨김·동기화 작업·버전이 붙었음(E1#1 재현)
     *   휴지통 기록은 종전대로 유지(36차 결정)
     */
    public function purgeStorageData(int $id): void {
        if ($id <= 0) return;
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
        // ★ (2026-11-01 01:56, 36차 전체 점검) 즐겨찾기·최근 파일·이어 보기 위치·숨김 표시도 같은 이유로 지움 — 남아 있으면 같은 번호를 받은 새 스토리지에 옛 숨김이 걸리고 즐겨찾기·최근 파일이 엉뚱한 파일을 가리켰음
        //   (휴지통 기록은 사본이 남아 있어 지우면 복원할 길이 없어지므로 그대로 둠)
        foreach (['favorites', 'recent_files', 'video_progress'] as $sdTbl) {
            try {
                $this->db->atomicUpdate($sdTbl, function ($rows) use ($id) {
                    return array_values(array_filter(is_array($rows) ? $rows : [], fn($r) => !is_array($r) || (int)($r['storage_id'] ?? 0) !== $id));
                });
            } catch (\Throwable $e) {}
        }
        try {
            $this->db->atomicUpdate('hidden_items', function ($all) use ($id) {
                $all = is_array($all) ? $all : [];
                unset($all[(string)$id]);
                return $all;
            });
        } catch (\Throwable $e) {}
        // ★ (2026-11-02 10:24, 42차 점검) 파일 버전(data/file_versions/md5("번호/경로")/meta.json)도 지움 — 종전엔 남아 같은 번호를 받은 새 스토리지에서
        //   같은 경로로 옛 파일의 이전 버전을 내려받거나 복원할 수 있었음(E1#1). FileManager 버전 저장과 같은 잠금 파일 안에서 meta 의 storage_id 로 판정
        try {
            $vBase = DATA_PATH . '/file_versions';
            if (is_dir($vBase)) {
                $vLk = @fopen(DATA_PATH . '/file_versions_meta.lock', 'c');
                if ($vLk) @flock($vLk, LOCK_EX);
                try {
                    foreach ((scandir($vBase) ?: []) as $vHash) {
                        if ($vHash === '.' || $vHash === '..') continue;
                        $vDir = $vBase . '/' . $vHash;
                        $vMeta = $vDir . '/meta.json';
                        if (!is_dir($vDir) || !is_file($vMeta)) continue;
                        $m = json_decode((string)@file_get_contents($vMeta), true);
                        if (!is_array($m) || (int)($m['storage_id'] ?? 0) !== $id) continue;
                        foreach ((scandir($vDir) ?: []) as $vf) {
                            if ($vf === '.' || $vf === '..') continue;
                            @unlink($vDir . '/' . $vf);
                        }
                        @rmdir($vDir);
                    }
                } finally {
                    if ($vLk) { @flock($vLk, LOCK_UN); @fclose($vLk); }
                }
            }
        } catch (\Throwable $e) {}
        self::$storageCache = [];
    }
    
    // ★ (2026-11-02 17:36, 46차 점검) 공유 목록·내부 공유·재구축 반복문이 getStorageById 를 공유마다 불러 storages·users 를 매번 처음부터 훑었음(사용자·공유 5000명이면 화면 하나 13~18초, H1#1).
    //   → 요청 동안 id → 첫 행 표를 재사용. 표 배열이 바뀌면(저장·다시 읽기) 다시 만들고, 정수 모양이 아닌 id 가 섞인 표는 종전 find 그대로(결과 같음)
    private static $idIdx = [];
    private function findByIdFast(string $table, int $id): ?array {
        $rows = $this->db->load($table);
        $c = self::$idIdx[$table] ?? null;
        if ($c !== null && $c[0] === $rows) {
            self::$idIdx[$table][0] = $rows;   // 같은 내용을 다시 읽은 새 배열이면 참조만 바꿔 다음부터 포인터 비교로 끝나게(안 하면 매번 깊은 비교로 오히려 느려짐)
        } else {
            $map = []; $ok = true;
            foreach ($rows as $r) {
                if (!is_array($r) || !isset($r['id'])) continue;   // find 도 id 없는 행은 건너뜀
                $v = $r['id'];
                if (is_int($v) || (is_string($v) && preg_match('/^-?\d{1,18}\z/', $v))) { $k = (int)$v; if (!isset($map[$k])) $map[$k] = $r; }   // matchValue 와 같은 정수 비교, 첫 행 우선
                else { $ok = false; break; }   // 실수·기타 값이 있으면 표를 쓰지 않음
            }
            self::$idIdx[$table] = $c = [$rows, $ok ? $map : null];
        }
        if ($c[1] === null) return $this->db->find($table, ['id' => $id]);
        return $c[1][$id] ?? null;
    }

    // 스토리지 정보 조회
    public function getStorageById(int $id): ?array {
        $storage = $this->findByIdFast('storages', $id);   // ★ (2026-11-02 17:36, 46차 점검) 종전 $this->db->find('storages', ['id' => $id]) — 위 표 참고
        
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
        $user = $this->findByIdFast('users', $ownerId);   // ★ (2026-11-02 17:36, 46차 점검) 종전 $this->db->find('users', ['id' => $ownerId]) — findByIdFast 참고
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
        // ★ (2026-11-01 09:44, 38차 점검) 만든 사람 규칙은 지금 실제 관리자이거나 '스토리지' 관리 권한이 있는 부관리자일 때만 — 종전엔 created_by 만 맞으면 통과해,
        //   사용자 삭제 뒤 번호를 다시 받은 새 가입자(일반 사용자)가 그 스토리지의 모든 권한을 얻었음. 스토리지 관리 권한자는 권한 줄을 스스로 다시 넣을 수 있어 종전 사용은 그대로
        if ((int)($storage['created_by'] ?? 0) === (int)$userId && $this->auth->hasAdminPerm('storages')) {
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
    // ★ (2026-11-02 17:36, 46차 점검) 같은 경로를 규칙마다 다시 정규화했음(H1#6) → 입력 문자열별 결과 메모(순수 함수라 결과 같음, 2만 개 넘으면 비움)
    private static $normMemo = [];
    private function normFolderPath(string $path): string {
        if (isset(self::$normMemo[$path])) return self::$normMemo[$path];
        if (count(self::$normMemo) > 20000) self::$normMemo = [];
        return self::$normMemo[$path] = $this->normFolderPathRaw($path);
    }
    private function normFolderPathRaw(string $path): string {
        $path = str_replace('\\', '/', $path);
        $out = [];
        // ★ (2026-11-04 01:39, 54차 점검) 끝 점·공백 떼기와 공백 섞인 '..' 인정은 Windows 에서만(Windows 는 아래 종전 코드 그대로) — Linux 에서 '.. '·' ..'·'secret.' 은
        //   실제로 다른 폴더인데 '..'(위로)·'secret' 으로 봐서, 읽기 금지 폴더 안의 '.. ' 폴더 파일을 목록·받기로 읽을 수 있었음(재현: files 'r54FIXH/secret/.. ' → 성공, inner.txt 받아짐).
        //   Linux 는 정확히 '..' 만 위로, 빈 조각·'.' 만 버리고 나머지 조각은 글자 그대로 비교
        $isWinFs = DIRECTORY_SEPARATOR === '\\';
        foreach (explode('/', $path) as $seg) {
            if (!$isWinFs) {
                if ($seg === '..') { array_pop($out); continue; }
                if ($seg === '' || $seg === '.') continue;
                $out[] = $seg;
                continue;
            }
            // ★ (2026-11-03 13:46, 51차 점검) '..' 를 끝 점·공백 떼기보다 먼저 봄 — 종전엔 rtrim(" .") 이 '..' 를 빈 값으로 만들어 아래 '..' 처리가 안 돼
            //   'vw/../vw/secret/s.docx' 가 'vw/vw/secret' 로 비교돼 secret 규칙을 비켜 갔음(재현: 일반 사용자가 뷰어로 읽기 금지 폴더 문서 열기)
            $seg = trim($seg);
            if ($seg === '..') { array_pop($out); continue; }
            $seg = rtrim($seg, " .");
            if ($seg === '' || $seg === '.') continue;
            $out[] = $seg;
        }
        $joined = implode('/', $out);
        // ★ (2026-11-02 09:01, 41차 점검) Windows 비교 키를 소문자 → 단순 대문자(1:1 대응)로 — NTFS 는 이름을 대문자 표로 맞춰 비교하는데, 소문자로 맞추면
        //   'ſ'(→S)·'ı'(→I)·'ς'(→Σ)처럼 대문자는 같고 소문자는 다른 글자로 쓴 'ſecret' 이 실제 'secret' 폴더를 열면서 'secret' 규칙엔 안 걸렸음(Windows 실기 미시험).
        //   전체 대응(ß→SS)은 NTFS 와 달라 쓰지 않음. 저장 값은 그대로이고 비교할 때만 양쪽을 같은 식으로 바꿈
        return DIRECTORY_SEPARATOR === '\\' ? mb_convert_case($joined, MB_CASE_UPPER_SIMPLE, 'UTF-8') : $joined;
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
    
    /**
     * ★ (2026-11-17 13:28, 322차 — 펜닐 결정 '공유는 접근 때 만든 사람 권한을 다시 확인') 특정 사용자 기준 스토리지 권한 — 세션·요청 캐시를 쓰지 않음.
     *   규칙은 예약 동기화(sync_cron.php 40차 $syCan)와 같음: 실제 관리자 전부, 홈은 주인만, 공용 폴더는 권한 줄 또는 기본 읽기·내려받기·보기,
     *   그 밖은 권한 줄, 줄이 없으면 그 스토리지를 만든 '스토리지' 관리 부관리자만. checkPermission(세션 기준)은 그대로 둠
     */
    public function userStoragePermAllowed(int $uid, int $storageId, string $perm): bool {
        $u = $this->db->find('users', ['id' => $uid]);
        if (!$u) return false;
        if (($u['role'] ?? '') === 'admin') return true;
        $st = $this->getStorageById($storageId);
        if (!$st) return false;
        $type = $st['storage_type'] ?? 'local';
        if ($type === 'home') return (int)($st['owner_id'] ?? 0) === $uid;
        $p = $this->db->find('permissions', ['storage_id' => $storageId, 'user_id' => $uid]);
        if ($type === 'shared') return $p ? (bool)($p[$perm] ?? false) : in_array($perm, ['can_read', 'can_download', 'can_visible'], true);
        if ($p) return (bool)($p[$perm] ?? false);
        $subStor = ($u['role'] ?? '') === 'sub_admin' && is_array($u['admin_perms'] ?? null) && in_array('storages', $u['admin_perms'], true);
        return (int)($st['created_by'] ?? 0) === $uid && $subStor;
    }

    /**
     * ★ (2026-11-17 13:28, 322차) 특정 사용자 기준 폴더 규칙 — checkFolderPermission($tree=false)·checkFolderPermission+checkFolderTreePermission($tree=true)와 같은 판정
     *   (sync_cron.php 55차 $syFolderOk 와 같은 식). 실제 관리자는 부르는 쪽에서 건너뜀
     */
    public function userFolderPermAllowed(int $uid, int $storageId, string $path, string $perm, bool $tree): bool {
        $rules = $this->getFolderPermissions($storageId);
        if (empty($rules)) return true;
        $base = $this->normFolderPath($path);
        $best = null; $bestLen = -1;
        foreach ($rules as $r) {
            if ((int)($r['user_id'] ?? 0) !== $uid) continue;
            $rp = $this->normFolderPath((string)($r['folder_path'] ?? ''));
            if ($rp === '') continue;
            if ($base !== '' && ($base === $rp || strpos($base . '/', $rp . '/') === 0)) { if (strlen($rp) > $bestLen) { $bestLen = strlen($rp); $best = $r; } }
            elseif ($tree && ($base === '' || strpos($rp, $base . '/') === 0) && !$this->folderRuleAllows($r, $perm)) return false;
        }
        return $best === null || $this->folderRuleAllows($best, $perm);
    }

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
        $folderPath = trim(str_replace('\\', '/', $folderPath), '/');
        // ★ (2026-11-01 09:44, 38차 점검) 잠금(atomicUpdate) 안에서 최신 표를 읽고 고쳐 씀 — 종전엔 잠금 없이 load→save 라, 그 사이 다른 요청의 이름 바꾸기가
        //   규칙을 새 경로로 옮기면(moveFolderPermissions) 옛 표로 덮어써 옮긴 규칙이 옛 경로로 돌아갔음(이름 바꾼 폴더의 보기·읽기 금지가 풀림)
        $ok = $this->db->atomicUpdate('folder_permissions', function ($all) use ($storageId, $folderPath, $userId, $perms) {
        $all = is_array($all) ? $all : [];
        
        // 기존 규칙 찾기
        // ★ (2026-10-31 13:42, 34차 후속 개선) 판정(checkFolderPermission)과 같은 normFolderPath 로 비교 — 종전엔 글자 그대로 비교해 'Secret'·'secret'(Windows) 규칙이 둘 다 저장돼 어느 쪽이 적용될지 정해지지 않았음
        $found = false;
        $normTarget = $this->normFolderPath($folderPath);
        foreach ($all as &$rule) {
            if ((int)($rule['storage_id'] ?? 0) === $storageId 
                && $this->normFolderPath((string)($rule['folder_path'] ?? '')) === $normTarget 
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
        return $all;
        });
        
        self::$folderPermCache = []; // 캐시 초기화
        return $ok;
    }
    
    /**
     * 폴더 권한 삭제
     */
    public function removeFolderPermission(int $storageId, string $folderPath, int $userId): bool {
        $folderPath = trim(str_replace('\\', '/', $folderPath), '/');
        
        // ★ (2026-10-31 13:42, 34차 후속 개선) 삭제도 normFolderPath 로 비교(한쪽 표기로 지우면 다른 표기 규칙이 남던 문제)
        $normTarget = $this->normFolderPath($folderPath);
        // ★ (2026-11-01 09:44, 38차 점검) 저장과 같은 이유로 잠금(atomicUpdate) 안에서 읽고 씀
        $ok = $this->db->atomicUpdate('folder_permissions', function ($all) use ($storageId, $normTarget, $userId) {
            $all = is_array($all) ? $all : [];
            return array_values(array_filter($all, function($rule) use ($storageId, $normTarget, $userId) {
                return !((int)($rule['storage_id'] ?? 0) === $storageId 
                    && $this->normFolderPath((string)($rule['folder_path'] ?? '')) === $normTarget 
                    && (int)($rule['user_id'] ?? 0) === $userId);
            }));
        });
        
        self::$folderPermCache = [];
        return $ok;
    }
    
    /**
     * ★ (2026-10-30 01:52, 26차 점검) 폴더 권한 규칙을 옮긴 자리로 따라가게(이름 바꾸기·이동) — 규칙은 경로 글자로 걸려 있어, 종전엔 상위 폴더 이름만 바꾸거나 옮기면
     *   'Projects/Secret' 의 보기·읽기 금지 규칙이 새 경로('Projects2/Secret')에 적용되지 않아 막아 둔 폴더가 보였음. 숨김 표시(HiddenItems::movePath)와 같은 방식.
     *   옮긴 자리에 같은 사용자 규칙이 이미 있으면 옮겨 온 규칙으로 바꿈. 바뀐 게 있으면 true
     */
    // ★ (2026-11-01 17:53, 40차 재검토) $keepSource: 원본 규칙을 지우지 않고 대상에 복사만 함 — 이동이 '복사는 끝났지만 원본 일부가 남음'으로 끝난 경우 원본(남은 조각)·대상(완전한 사본) 둘 다 보호. 기본 false = 종전 그대로
    /** ★ (2026-11-17 14:58, 336차) 즐겨찾기(favorites)·최근 파일(recent_files)의 경로를 새 자리로 — 판정($match)은 아래 폴더 규칙 옮기기와 같은 기준(Windows 대소문자 무시). 앞 '/' 모양은 원래대로 */
    private function movePathRefs(int $srcStorageId, int $dstStorageId, string $new, callable $match): void {
        // 지금 사용자 기록만 — 개인 폴더(home)는 스토리지 번호가 같아도 사람마다 다른 폴더라 남의 같은 이름 경로를 바꾸면 안 됨(공용 스토리지의 남 기록은 종전처럼 그대로)
        $uid = (int)($this->auth->getUserId() ?? 0);
        if ($uid <= 0) return;
        foreach (['favorites', 'recent_files'] as $tbl) {
            $this->db->atomicUpdate($tbl, function ($rows) use ($srcStorageId, $dstStorageId, $new, $match, $uid) {
                if (!is_array($rows)) return $rows;
                foreach ($rows as &$r) {
                    if (!is_array($r) || (int)($r['user_id'] ?? 0) !== $uid || (int)($r['storage_id'] ?? 0) !== $srcStorageId || !is_string($r['path'] ?? null)) continue;
                    $raw = str_replace('\\', '/', $r['path']);
                    $rest = $match(trim(preg_replace('#/{2,}#', '/', $raw), '/'));
                    if ($rest === null) continue;
                    $r['storage_id'] = $dstStorageId;
                    $r['path'] = (strpos($raw, '/') === 0 ? '/' : '') . $new . $rest;
                    if ($rest === '' && isset($r['name'])) $r['name'] = basename($new);
                }
                unset($r);
                return $rows;
            });
        }
    }

    public function moveFolderPermissions(int $srcStorageId, string $oldPath, int $dstStorageId, string $newPath, bool $keepSource = false): bool {
        $old = trim(preg_replace('#/{2,}#', '/', str_replace('\\', '/', $oldPath)), '/');   // ★ (2026-10-30 02:21, 26차 재검토) 겹친 '/' 정리
        $new = trim(preg_replace('#/{2,}#', '/', str_replace('\\', '/', $newPath)), '/');
        if ($old === '' || $new === '' || ($srcStorageId === $dstStorageId && $old === $new)) return false;
        $ci = DIRECTORY_SEPARATOR === '\\';   // Windows 는 경로 대소문자를 가리지 않음
        $match = function (string $p) use ($old, $ci): ?string {
            // ★ (2026-11-02 09:05, 41차 점검) Windows 비교를 normFolderPath 와 같은 단순 대문자로(종전 소문자) — 판정은 대문자 기준인데 옮기기만 소문자면 'ſ'·'ı' 가 든 규칙이 판정엔 걸리고 이름 바꿀 때는 안 따라왔음
            $a = $ci ? mb_convert_case($p, MB_CASE_UPPER_SIMPLE, 'UTF-8') : $p; $b = $ci ? mb_convert_case($old, MB_CASE_UPPER_SIMPLE, 'UTF-8') : $old;
            if ($a === $b) return '';
            if (strpos($a, $b . '/') === 0) return $ci ? '/' . implode('/', array_slice(explode('/', $p), substr_count($old, '/') + 1)) : substr($p, strlen($old));   // ★ (2026-10-30 03:03, 26차 재검토) 소문자로 바꾸면 길이가 달라지는 글자(İ 등)가 있어 Windows 비교는 '/' 마디 수로 자름
            return null;
        };
        // ★ (2026-11-17 14:58, 336차 — 247차 기록 '결정 필요 E', 펜닐 '전부 알아서') 이름 바꾸기·이동 때 즐겨찾기·최근 파일의 경로도 따라감(종전엔 옛 경로로 남아 눌러도 열리지 않았음).
        //   복사(keepSource)는 원본이 그대로라 바꾸지 않음. 지우기는 휴지통에서 되살리면 같은 자리라 그대로 둠. 실패해도 이동 결과에는 영향 없음
        if (!$keepSource) { try { $this->movePathRefs($srcStorageId, $dstStorageId, $new, $match); } catch (\Throwable $e) {} }
        $changed = false;
        $this->db->atomicUpdate('folder_permissions', function ($all) use ($srcStorageId, $dstStorageId, $new, $match, $keepSource, &$changed) {
            $all = is_array($all) ? $all : [];
            $moved = [];
            foreach ($all as $i => $rule) {
                if ((int)($rule['storage_id'] ?? 0) !== $srcStorageId) continue;
                $rest = $match(trim((string)($rule['folder_path'] ?? ''), '/'));
                if ($rest === null) continue;
                $rule['storage_id'] = $dstStorageId;
                $rule['folder_path'] = $new . $rest;
                $moved[] = $rule;
                if (!$keepSource) unset($all[$i]);
            }
            if (!$moved) return array_values($all);
            $changed = true;
            $ci = DIRECTORY_SEPARATOR === '\\';
            $key = function ($r) use ($ci) { $fp = trim((string)($r['folder_path'] ?? ''), '/'); if ($ci) $fp = mb_convert_case($fp, MB_CASE_UPPER_SIMPLE, 'UTF-8'); return (int)($r['storage_id'] ?? 0) . '|' . $fp . '|' . (int)($r['user_id'] ?? 0); };   // ★ (2026-10-30 03:03, 26차 재검토) Windows 는 대소문자만 다른 같은 폴더 규칙이 둘 남지 않게 / ★ (2026-11-02 09:05, 41차 점검) 위와 같은 단순 대문자로
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
    /** ★ (2026-11-16 16:04, 254차 점검) 권한 값 하나를 0/1 로 — null 은 기본값, bool·숫자·숫자 글자·'true'/'on'/'yes' 는 뜻대로, 그 밖의 글자('false' 등)·배열·객체는 0.
     *  setPermission·addStorage·updateStorage 권한 배열에서 씀(화면은 늘 0/1 을 보내 결과 같음) */
    public static function permBit($v, int $def): int {   // ★ (2026-11-16 16:56, 255차) api.php folder_permission_set 에서도 쓰려고 public
        if ($v === null) return $def;
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_int($v) || is_float($v)) return $v != 0 ? 1 : 0;
        if (is_string($v)) { $t = strtolower(trim($v)); if (is_numeric($t)) return (float)$t != 0 ? 1 : 0; return in_array($t, ['true', 'on', 'yes'], true) ? 1 : 0; }
        return 0;
    }

    public function setPermission(int $storageId, int $userId, array $permissions): array {
        $existing = $this->db->find('permissions', [
            'storage_id' => $storageId,
            'user_id' => $userId
        ]);
        
        // ★ (2026-11-16 16:04, 254차 점검) 값을 0/1 로 정리 — 종전엔 받은 값을 그대로 저장해 checkPermission 의 (bool) 이 글자 'false'·[0]·{"a":1} 을 '허용'으로 읽었음
        //   (재현: can_write 'false' 로 저장 → 올리기 성공). 빠진 값·null 은 종전 기본값, 0·1·'0'·'1'·true·false·'true' 는 종전과 같은 결과. 화면은 이 API 를 부르지 않음
        $data = [
            'can_visible' => self::permBit($permissions['can_visible'] ?? null, 1),
            'can_visible_webdav' => self::permBit($permissions['can_visible_webdav'] ?? ($permissions['can_visible'] ?? null), 1),
            'can_read' => self::permBit($permissions['can_read'] ?? null, 1),
            'can_download' => self::permBit($permissions['can_download'] ?? null, 1),
            'can_write' => self::permBit($permissions['can_write'] ?? null, 0),
            'can_delete' => self::permBit($permissions['can_delete'] ?? null, 0),
            'can_share' => self::permBit($permissions['can_share'] ?? null, 0)
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
            // ★ (2026-10-31 13:43, 34차 후속 개선) Windows 의 escapeshellarg 는 % ! " 를 공백으로 바꿔, 이 글자가 든 사용자명·비밀번호는 net use 인증이 늘 실패했음
            //   → 그런 경우에만 cmd.exe 를 거치지 않는 proc_open 배열 형식으로 실행(인자를 그대로 전달). 그 밖의 자격 증명은 종전 방식 그대로. Windows 실기 시험은 못 함
            if (strpbrk($username . $password, '%!"') !== false && function_exists('proc_open')) {
                $returnCode = 1;
                $proc = @proc_open(['net', 'use', $uncPath, '/user:' . $username, $password], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (is_resource($proc)) {
                    fclose($pipes[0]);   // 확인 질문이 나와도 기다리지 않게 입력은 바로 닫음
                    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
                    fclose($pipes[1]); fclose($pipes[2]);
                    $returnCode = proc_close($proc);
                }
                return $returnCode === 0 || is_dir($uncPath);
            }
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
        
        $credFile = null;
        if (!empty($username)) {
            // ★ (2026-11-01 17:18, 40차 점검) mount.cifs 옵션 문자열은 쉼표로만 나누고 '\,' 이스케이프가 없어, 쉼표가 든 사용자명('bob,uid=0,…')이
            //   root 로 도는 mount 에 다른 옵션을 끼워 넣었음(정상 사용자명도 늘 인증 실패) → 쉼표가 든 사용자명은 마운트하지 않음(Windows·Samba 사용자명엔 쉼표를 못 씀)
            if (strpos($username, ',') !== false) return false;
            $options[] = 'username=' . $username;
            if (!empty($password)) {
                // ★ (2026-11-01 09:44, 38차 점검) 비밀번호는 mount.cifs 자격 증명 파일(credentials=, 0600 임시 파일)로 넘기고 마운트 뒤 지움 —
                //   종전엔 '-o password=...' 명령줄 인자라 같은 서버의 ps 로 보였고, 옵션 문자열은 '\,' 이스케이프를 지원하지 않아 쉼표가 든 비밀번호는 늘 인증 실패였음
                $credFile = @tempnam(sys_get_temp_dir(), 'fscifs_');
                if ($credFile && strpbrk($password, "\r\n") === false && @chmod($credFile, 0600) && @file_put_contents($credFile, 'password=' . $password . "\n") !== false) {
                    $options[] = 'credentials=' . $credFile;
                } else {
                    if ($credFile) @unlink($credFile);
                    $credFile = null;
                    $options[] = 'password=' . str_replace(',', '\\,', $password);   // 임시 파일을 못 만들면 종전 방식
                }
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
        if ($credFile) @unlink($credFile);   // ★ (2026-11-01 09:44, 38차 점검) 자격 증명 임시 파일은 마운트 시도가 끝나면 바로 지움

        return $returnCode === 0 && is_dir($mountPoint);
    }
    
    // 마운트 상태 확인
    private function isMounted(string $path): bool {
        if ($this->isWindows()) return false;
        $path = realpath($path) ?: $path;
        // ★ (2026-11-01 09:44, 38차 점검) 마운트 지점을 정확히 비교 — 종전엔 strpos(부분 문자열)라 '.../nas_a' 가 '.../nas_a2' 마운트 줄에도 걸려
        //   마운트를 건너뛰고 비어 있는 로컬 폴더(data 아래)를 SMB 로 썼음. /proc/mounts 의 두 번째 칸(공백 등은 \040 같은 8진 표기) 우선, 없으면 mount 출력의 ' on <경로> type '
        $pm = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($pm) && $pm) {
            foreach ($pm as $line) {
                $f = explode(' ', $line);
                if (isset($f[1]) && stripcslashes($f[1]) === $path) return true;
            }
            return false;
        }
        exec('mount 2>/dev/null', $mounts);
        foreach ($mounts as $line) {
            if (strpos($line, ' on ' . $path . ' type ') !== false) {
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
    // ★ (2026-11-02 09:01, 41차 점검) 배열로 저장된 config 는 그대로 씀(StorageAdapterFactory 와 같은 허용) — 종전엔 string 형식 선언이라 배열이면 SMB 경로 계산(getRealPath)이 TypeError(500)
    private function decodeConfig($config): array {
        if (is_array($config)) return $config;
        if (!is_string($config)) return [];
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
        // ★ (2026-11-01 09:44, 38차 점검) 예외로 중간에 멈춘 부분 합계는 저장하지 않음(이전 값 유지). 락 파일은 남겨 간격($intervalSeconds) 뒤에 다시 시도(매 요청 전수 스캔 방지)
        if ($this->lastCalcFailed) {
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
                return ['success' => false, 'error' => __('api_index_unavailable_hint', '인덱스 DB를 사용할 수 없습니다. 인덱스를 먼저 구축하세요.')];   // ★ (2026-11-02 10:25, 42차 점검) 같은 키의 lang 값이 '인덱스 사용 불가'(짧은 꼴)라 조치 안내가 빠졌음(E3) → 안내 문장용 새 키
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
                // ★ (2026-11-01 09:44, 38차 점검) 예외로 중간에 멈춘 부분 합계는 저장하지 않고 실패로 알림
                if ($this->lastCalcFailed) {
                    return ['success' => false, 'error' => __('api_err_recalc_failed', '사용량 계산 중 오류가 나 끝까지 세지 못했습니다. 부분 결과는 저장하지 않았습니다.')];
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
    private $lastCalcFailed = false;   // ★ (2026-11-01 09:44, 38차 점검) 예외로 중간에 멈춤(부분 합계) — 호출부가 저장하지 않게
    
    private function calculateDirectorySize(string $path, int $maxSeconds = 20): int {
        $size = 0;
        $this->lastCalcTimedOut = false;
        $this->lastCalcFileCount = 0;
        $this->lastCalcFailed = false;
        
        if (!is_dir($path)) return 0;
        
        $startTime = time();
        $fileCount = 0;
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD   // ★ (2026-11-01 09:44, 38차 점검) 열 수 없는 하위 폴더(System Volume Information 등)만 건너뜀 — 종전엔 예외로 계산 전체가 멈춰 그때까지의 부분 합계가 저장됐음
            );
            
            foreach ($iterator as $file) {
                // 시간 초과 방지 — 타임아웃 시 플래그 세팅
                if ((time() - $startTime) > $maxSeconds) {
                    $this->lastCalcTimedOut = true;
                    break;
                }
                
                // ★ (2026-11-02 09:01, 41차 점검) 점(.) 이름 파일도 사용량에 셈 — 종전엔 숨김이라고 빼서, WebDAV 등으로 올린 뒤 '.x' 로 이름을 바꾸면 다음 재계산 때
                //   그만큼 사용량이 줄어 한도를 계속 넘길 수 있었음(실제로 디스크를 차지함). 앱이 쓰는 동안만 잠깐 생기는 임시 파일(.~fsts-·.~fsvr-·.~fsoo-)만 뺌
                // ★ (2026-11-02 09:45, 41차 재검토) '.~fs' 로 시작하는 이름을 빼던 줄 제거 — WebDAV 로 '.~fs아무거나' 이름을 만들면 같은 우회가 됐음(검토 발견). 앱 임시 파일은 저장하는 순간에만 있음
                // ★ (2026-11-01 09:44, 38차 점검) 훑는 사이 지워진 파일 등 한 파일의 크기 읽기 실패는 그 파일만 건너뜀(아래 catch 가 계산 실패로 처리하므로)
                try {
                    if ($file->isFile()) {
                        $size += $file->getSize();
                    }
                } catch (\RuntimeException $e) {}
                
                // IO 양보: 매 200개 항목마다 5ms 쉼
                // 전체 스캔 시간은 약간 늘지만 다른 요청이 디스크 I/O 획득 가능 → 무한로딩 방지
                $fileCount++;
                if ($fileCount % 200 === 0) {
                    usleep(5000);
                }
            }
        } catch (\Throwable $e) {
            // 접근 불가 디렉토리 등 에러 무시
            // ★ (2026-11-01 09:44, 38차 점검) 중간에 멈춘 부분 합계라 '실패'로 표시(종전엔 정상 값으로 저장돼 용량 한도 판정이 실제보다 작은 값으로 돌았음)
            $this->lastCalcFailed = true;
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