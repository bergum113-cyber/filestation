<?php
/**
 * SSOAuth - SSO 인증 모듈 (LDAP/AD, OIDC, SAML 2.0, Kerberos/SPNEGO)
 */
class SSOAuth {
    private $db;
    private $settings;
    
    public function __construct() {
        $this->db = JsonDB::getInstance();
        $this->settings = $this->db->load('settings') ?: [];
    }
    
    // ===== Kerberos/SPNEGO 자동 로그인 =====
    
    /**
     * Kerberos 자동 로그인 시도
     * Apache mod_auth_kerb가 설정되면 $_SERVER['REMOTE_USER']에 AD 사용자명이 들어옴
     * @return array|null 로그인된 사용자 정보 또는 null
     */
    public function kerberosAutoLogin(): ?array {
        $config = $this->getConfig()['kerberos'] ?? [];
        if (empty($config['enabled'])) return null;
        
        // Apache(mod_auth_kerb/SPNEGO)가 설정한 REMOTE_USER — 웹서버가 실제 인증에 성공했을 때만 채워짐.
        // ★ (2026-10-28 18:22, 소스 점검 5단계) HTTP_X_REMOTE_USER(클라이언트가 보낸 X-Remote-User 헤더)는 신뢰하지 않음 —
        //   종전엔 폴백으로 읽어서, 헤더 하나만 붙이면(curl -H "X-Remote-User: admin") 비밀번호·2FA 없이 그 사용자로 로그인됐음.
        //   (서버가 X-Remote-User 로 넘겨주는 특수 구성은 아래 sso.kerberos.trust_header 를 켠 때만 허용)
        $remoteUser = $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? null;
        if (empty($remoteUser) && !empty($config['trust_header'])) {
            $remoteUser = $_SERVER['HTTP_X_REMOTE_USER'] ?? null;
        }
        if (empty($remoteUser)) return null;
        
        // DOMAIN\username 또는 username@DOMAIN 형태에서 username 추출
        $username = $remoteUser;
        if (strpos($username, '\\') !== false) {
            $username = substr($username, strpos($username, '\\') + 1);
        }
        if (strpos($username, '@') !== false) {
            $username = substr($username, 0, strpos($username, '@'));
        }
        $username = strtolower(trim($username));
        
        if (empty($username)) return null;
        
        // fsLog("SSO Kerberos auto-login: REMOTE_USER={$remoteUser} → username={$username}");
        
        // FileStation 계정 매핑/생성
        $ssoUser = [
            'username' => $username,
            'email' => '',
            'display_name' => $username,
        ];
        
        // LDAP에서 추가 정보 가져오기 (설정되어 있으면)
        $ldapConfig = $this->getConfig()['ldap'] ?? [];
        if (!empty($ldapConfig['enabled']) && !empty($ldapConfig['host'])) {
            try {
                $ldapInfo = $this->ldapLookup($username);
                if ($ldapInfo) {
                    // ★ (2026-10-29 07:45, 18차) 아이디는 Kerberos 가 확인한 값 그대로 — LDAP 에서는 메일·표시 이름만 받음
                    //   (ldapLookup 이 이제 속성을 제대로 읽어, 종전처럼 덮어쓰면 아이디가 LDAP 값으로 바뀌어 다른 계정에 연결될 수 있음)
                    $ssoUser = array_merge($ssoUser, $ldapInfo, ['username' => $ssoUser['username']]);
                }
            } catch (Exception $e) {
                // LDAP 조회 실패해도 Kerberos 인증은 유효
                fsLog("SSO Kerberos LDAP lookup failed: " . $e->getMessage());
            }
        }
        
        // ★ (2026-10-29 17:46, 22차 점검) IP·국가 제한을 계정 연결·자동 생성 '전'에 — 종전엔 막힌 IP 에서도 mapOrCreateUser 가 먼저 돌아
        //   계정을 만들거나(sso_provider·마지막 로그인 갱신 포함) 한 뒤에야 ssoGate 에서 거부했음(LDAP·OIDC·SAML 은 api.php 에서 연결 전에 검사)
        if (class_exists('Auth') && (new Auth())->ssoIpDenied($username, 'kerberos') !== null) {
            return null;
        }
        try {
            return $this->mapOrCreateUser($ssoUser, 'kerberos');
        } catch (Exception $e) {
            fsLog("SSO Kerberos mapOrCreateUser failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * LDAP에서 사용자 정보 조회 (바인드 계정으로 검색만, 인증 없이)
     */
    public function ldapLookup(string $username): ?array {
        $config = $this->getConfig()['ldap'];
        if (empty($config['host']) || empty($config['base_dn'])) return null;
        
        if (!function_exists('ldap_connect')) return null;
        
        $host = $config['host'];
        $port = (int)($config['port'] ?? 389);
        $baseDn = $config['base_dn'];
        $bindDn = $config['bind_dn'] ?? '';
        $bindPw = $config['bind_password'] ?? '';
        $userFilter = $config['user_filter'] ?? '(sAMAccountName={username})';
        $usernameAttr = $config['username_attr'] ?? 'sAMAccountName';
        $emailAttr = $config['email_attr'] ?? 'mail';
        $displayNameAttr = $config['display_name_attr'] ?? 'displayName';
        $useTls = !empty($config['use_tls']);
        $useStartTls = !empty($config['use_starttls']);
        
        $uri = ($useTls ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($uri);
        if (!$conn) return null;
        
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
        
        // ★ (2026-10-29 18:02, 22차 점검) STARTTLS 실패면 중단(ldapAuth 와 같음) — 종전엔 실패해도 서비스 계정 비밀번호를 평문으로 바인드했음
        if ($useStartTls && !@ldap_start_tls($conn)) { @ldap_close($conn); return null; }
        
        if ($bindDn) {
            if (!@ldap_bind($conn, $bindDn, $bindPw)) {
                @ldap_close($conn);
                return null;
            }
        }
        
        $filter = str_replace('{username}', ldap_escape($username, '', LDAP_ESCAPE_FILTER), $userFilter);
        $search = @ldap_search($conn, $baseDn, $filter, [$usernameAttr, $emailAttr, $displayNameAttr]);
        if (!$search || ldap_count_entries($conn, $search) === 0) {
            @ldap_close($conn);
            return null;
        }
        
        $entries = ldap_get_entries($conn, $search);
        @ldap_close($conn);
        // ★ (2026-10-29 07:45, 18차) ldapAuth(17차)와 같게 — 여러 명이면 누구 정보인지 모호하므로 안 씀, 속성은 소문자 키로 읽음
        //   (ldap_get_entries 는 속성 이름을 소문자로 돌려줘 종전엔 메일·표시 이름이 늘 비었음)
        if (!is_array($entries) || (int)($entries['count'] ?? 0) !== 1) return null;
        $e0 = $entries[0];
        $attr = function (string $name) use ($e0) {
            $k = strtolower($name);
            return (isset($e0[$k][0]) && $e0[$k][0] !== '') ? (string)$e0[$k][0] : null;
        };
        
        return [
            'username' => $attr($usernameAttr) ?? $username,
            'email' => $attr($emailAttr) ?? '',
            'display_name' => $attr($displayNameAttr) ?? ($attr($usernameAttr) ?? $username),
        ];
    }
    
    /**
     * OIDC Seamless 로그인 (자동 리다이렉트, prompt=none)
     * 이미 IdP에 로그인되어 있으면 ID/PW 없이 자동 인증
     */
    public function oidcGetSilentAuthUrl(): ?string {
        $config = $this->getConfig()['oidc'];
        if (empty($config['enabled']) || empty($config['auto_login'])) return null;
        
        $authEndpoint = $config['authorization_endpoint'] ?? '';
        $clientId = $config['client_id'] ?? '';
        $redirectUri = $config['redirect_uri'] ?? '';
        $scope = $config['scope'] ?? 'openid profile email';
        
        if (empty($authEndpoint) || empty($clientId) || empty($redirectUri)) return null;
        
        $state = bin2hex(random_bytes(16));
        $_SESSION['oidc_state'] = $state;
        $_SESSION['oidc_silent'] = true; // 자동 로그인 시도 표시
        
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['oidc_nonce'] = $nonce;
        
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'none', // 핵심: 이미 로그인된 경우만 자동 인증
        ];
        
        return $authEndpoint . '?' . http_build_query($params);
    }
    
    /**
     * SSO 설정 가져오기
     */
    public function getConfig(): array {
        $sso = $this->settings['sso'] ?? [];
        return [
            'kerberos' => $sso['kerberos'] ?? ['enabled' => false],
            'ldap' => $sso['ldap'] ?? ['enabled' => false],
            'oidc' => $sso['oidc'] ?? ['enabled' => false],
            'saml' => $sso['saml'] ?? ['enabled' => false],
        ];
    }
    
    /**
     * 클라이언트에 보낼 SSO 설정 (시크릿 제외)
     */
    public function getPublicConfig(): array {
        $config = $this->getConfig();
        $public = [];
        
        if (!empty($config['kerberos']['enabled'])) {
            $public['kerberos'] = [
                'enabled' => true,
                'auto_login' => true
            ];
        }
        if (!empty($config['ldap']['enabled'])) {
            $public['ldap'] = [
                'enabled' => true,
                'label' => $config['ldap']['label'] ?? 'LDAP/AD 로그인'
            ];
        }
        if (!empty($config['oidc']['enabled'])) {
            $public['oidc'] = [
                'enabled' => true,
                'label' => $config['oidc']['label'] ?? 'SSO 로그인',
                'provider_name' => $config['oidc']['provider_name'] ?? 'OIDC',
                'auto_login' => !empty($config['oidc']['auto_login'])
            ];
        }
        if (!empty($config['saml']['enabled'])) {
            $public['saml'] = [
                'enabled' => true,
                'label' => $config['saml']['label'] ?? 'SAML 로그인'
            ];
        }
        
        return $public;
    }
    
    // ===== LDAP/AD =====
    
    /**
     * LDAP 인증
     */
    public function ldapAuth(string $username, string $password): ?array {
        $config = $this->getConfig()['ldap'];
        if (empty($config['enabled'])) return null;
        
        if (!function_exists('ldap_connect')) {
            throw new Exception('PHP LDAP 확장이 설치되지 않았습니다.');
        }
        
        $host = $config['host'] ?? '';
        $port = (int)($config['port'] ?? 389);
        $baseDn = $config['base_dn'] ?? '';
        $bindDn = $config['bind_dn'] ?? '';
        $bindPw = $config['bind_password'] ?? '';
        $userFilter = $config['user_filter'] ?? '(sAMAccountName={username})';
        $usernameAttr = $config['username_attr'] ?? 'sAMAccountName';
        $emailAttr = $config['email_attr'] ?? 'mail';
        $displayNameAttr = $config['display_name_attr'] ?? 'displayName';
        $useTls = !empty($config['use_tls']);
        $useStartTls = !empty($config['use_starttls']);
        
        if (empty($host) || empty($baseDn)) {
            throw new Exception('LDAP 서버 설정이 불완전합니다.');
        }
        
        // 연결
        $uri = ($useTls ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($uri);
        if (!$conn) throw new Exception('LDAP 연결 실패');
        
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 10);
        
        if ($useStartTls) {
            if (!@ldap_start_tls($conn)) {
                @ldap_close($conn);
                throw new Exception('LDAP STARTTLS 실패');
            }
        }
        
        // 서비스 계정으로 바인드 (검색용)
        if ($bindDn) {
            if (!@ldap_bind($conn, $bindDn, $bindPw)) {
                @ldap_close($conn);
                throw new Exception('LDAP 바인드 실패: ' . ldap_error($conn));
            }
        }
        
        // 사용자 검색
        $filter = str_replace('{username}', ldap_escape($username, '', LDAP_ESCAPE_FILTER), $userFilter);
        $search = @ldap_search($conn, $baseDn, $filter, [$usernameAttr, $emailAttr, $displayNameAttr]);
        if (!$search) {
            @ldap_close($conn);
            throw new Exception('LDAP 검색 실패: ' . ldap_error($conn));
        }
        
        $entries = ldap_get_entries($conn, $search);
        if ($entries['count'] === 0) {
            @ldap_close($conn);
            return null; // 사용자 없음
        }
        // ★ (2026-10-29 03:51, 17차) 검색 결과가 여럿이면 누구로 로그인할지 모호하므로 거부(종전엔 첫 번째로 진행)
        if ($entries['count'] > 1) {
            @ldap_close($conn);
            throw new Exception('LDAP 검색 결과가 여러 명입니다. 사용자 필터를 확인하세요.');
        }

        $userDn = $entries[0]['dn'];
        // ★ (2026-10-29 03:51, 17차) ldap_get_entries 는 속성 이름을 소문자로 돌려주는데 설정값(sAMAccountName·displayName)을 그대로 키로 써
        //   늘 비어 있었음 → 사용자가 입력한 아이디가 그대로 사용자 이름이 됐음(메일·UPN 필터면 다른 계정에 연결될 수 있음). 소문자 키로 읽음
        $e0 = $entries[0];
        $attr = function (string $name) use ($e0) {
            $k = strtolower($name);
            return (isset($e0[$k][0]) && $e0[$k][0] !== '') ? (string)$e0[$k][0] : null;
        };
        $userData = [
            'username' => $attr($usernameAttr) ?? $username,
            'email' => $attr($emailAttr) ?? '',
            'display_name' => $attr($displayNameAttr) ?? ($attr($usernameAttr) ?? $username),
        ];
        
        // 사용자 비밀번호로 바인드 (인증)
        if (!@ldap_bind($conn, $userDn, $password)) {
            @ldap_close($conn);
            return null; // 비밀번호 불일치
        }
        
        @ldap_close($conn);
        return $userData;
    }
    
    /**
     * LDAP 연결 테스트
     */
    public function ldapTest(array $config): array {
        if (!function_exists('ldap_connect')) {
            return ['success' => false, 'error' => 'PHP LDAP 확장이 설치되지 않았습니다.'];
        }
        
        $host = $config['host'] ?? '';
        $port = (int)($config['port'] ?? 389);
        $baseDn = $config['base_dn'] ?? '';
        $bindDn = $config['bind_dn'] ?? '';
        $bindPw = $config['bind_password'] ?? '';
        $useTls = !empty($config['use_tls']);
        $useStartTls = !empty($config['use_starttls']);
        
        $uri = ($useTls ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($uri);
        if (!$conn) return ['success' => false, 'error' => 'LDAP 연결 실패'];
        
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 10);
        
        if ($useStartTls) {
            if (!@ldap_start_tls($conn)) {
                @ldap_close($conn);
                return ['success' => false, 'error' => 'STARTTLS 실패: ' . ldap_error($conn)];
            }
        }
        
        if ($bindDn) {
            if (!@ldap_bind($conn, $bindDn, $bindPw)) {
                $err = ldap_error($conn);
                @ldap_close($conn);
                return ['success' => false, 'error' => "바인드 실패: $err"];
            }
        }
        
        // 사용자 수 조회
        $search = @ldap_search($conn, $baseDn, '(objectClass=person)', ['sAMAccountName'], 0, 100);
        $count = $search ? ldap_count_entries($conn, $search) : 0;
        
        @ldap_close($conn);
        return ['success' => true, 'message' => "연결 성공! 사용자 약 {$count}명 검색됨"];
    }
    
    // ===== OIDC (OpenID Connect) =====
    
    /**
     * OIDC 인증 URL 생성
     */
    public function oidcGetAuthUrl(): string {
        $config = $this->getConfig()['oidc'];
        if (empty($config['enabled'])) throw new Exception('OIDC가 비활성화되어 있습니다.');
        
        $authEndpoint = $config['authorization_endpoint'] ?? '';
        $clientId = $config['client_id'] ?? '';
        $redirectUri = $config['redirect_uri'] ?? '';
        $scope = $config['scope'] ?? 'openid profile email';
        
        if (empty($authEndpoint) || empty($clientId) || empty($redirectUri)) {
            throw new Exception('OIDC 설정이 불완전합니다.');
        }
        
        // state 생성 (CSRF 방지)
        $state = bin2hex(random_bytes(16));
        $_SESSION['oidc_state'] = $state;
        
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
        ];
        
        // nonce 추가
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['oidc_nonce'] = $nonce;
        $params['nonce'] = $nonce;
        
        return $authEndpoint . '?' . http_build_query($params);
    }
    
    /**
     * OIDC 콜백 처리 (authorization code → token → userinfo)
     */
    public function oidcCallback(string $code, string $state): ?array {
        $config = $this->getConfig()['oidc'];
        if (empty($config['enabled'])) throw new Exception('OIDC가 비활성화되어 있습니다.');
        
        // state 검증 (timing-safe 비교)
        if (empty($_SESSION['oidc_state']) || !hash_equals($_SESSION['oidc_state'], $state)) {
            throw new Exception('잘못된 state 값 (CSRF 의심)');
        }
        unset($_SESSION['oidc_state']);
        
        $tokenEndpoint = $config['token_endpoint'] ?? '';
        $userinfoEndpoint = $config['userinfo_endpoint'] ?? '';
        $clientId = $config['client_id'] ?? '';
        $clientSecret = $config['client_secret'] ?? '';
        $redirectUri = $config['redirect_uri'] ?? '';
        
        // Authorization Code → Access Token
        $tokenData = $this->httpPost($tokenEndpoint, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
        
        if (empty($tokenData['access_token'])) {
            throw new Exception('토큰 획득 실패: ' . json_encode($tokenData));
        }
        
        // ID Token nonce 검증 (replay attack 방지)
        if (!empty($tokenData['id_token']) && !empty($_SESSION['oidc_nonce'])) {
            $idParts = explode('.', $tokenData['id_token']);
            if (count($idParts) === 3) {
                $idPayload = @json_decode(base64_decode(strtr($idParts[1], '-_', '+/')), true);
                if ($idPayload && isset($idPayload['nonce'])) {
                    if (!hash_equals($_SESSION['oidc_nonce'], $idPayload['nonce'])) {
                        unset($_SESSION['oidc_nonce']);
                        throw new Exception('nonce 검증 실패');
                    }
                }
            }
        }
        unset($_SESSION['oidc_nonce']);
        
        // UserInfo 가져오기
        $userInfo = $this->httpGet($userinfoEndpoint, $tokenData['access_token']);
        
        if (empty($userInfo)) {
            throw new Exception('사용자 정보 획득 실패');
        }
        
        // 사용자 정보 매핑
        $usernameAttr = $config['username_attr'] ?? 'preferred_username';
        $emailAttr = $config['email_attr'] ?? 'email';
        $displayNameAttr = $config['display_name_attr'] ?? 'name';
        
        return [
            'username' => $userInfo[$usernameAttr] ?? $userInfo['sub'] ?? '',
            'email' => $userInfo[$emailAttr] ?? '',
            'display_name' => $userInfo[$displayNameAttr] ?? $userInfo[$usernameAttr] ?? '',
            'sub' => $userInfo['sub'] ?? '',
        ];
    }
    
    /**
     * OIDC Discovery (.well-known) 자동 설정
     */
    public function oidcDiscover(string $wellKnownUrl): array {
        $data = $this->httpGet($wellKnownUrl);
        if (empty($data['authorization_endpoint'])) {
            throw new Exception('Well-known URL에서 설정을 가져올 수 없습니다.');
        }
        return [
            'authorization_endpoint' => $data['authorization_endpoint'] ?? '',
            'token_endpoint' => $data['token_endpoint'] ?? '',
            'userinfo_endpoint' => $data['userinfo_endpoint'] ?? '',
            'issuer' => $data['issuer'] ?? '',
        ];
    }
    
    // ===== SAML 2.0 =====
    
    /**
     * SAML AuthnRequest 생성
     */
    public function samlGetAuthUrl(): string {
        $config = $this->getConfig()['saml'];
        if (empty($config['enabled'])) throw new Exception('SAML이 비활성화되어 있습니다.');
        
        $ssoUrl = $config['idp_sso_url'] ?? '';
        $entityId = $config['sp_entity_id'] ?? '';
        $acsUrl = $config['sp_acs_url'] ?? '';
        
        if (empty($ssoUrl) || empty($entityId) || empty($acsUrl)) {
            throw new Exception('SAML 설정이 불완전합니다.');
        }
        
        $id = '_' . bin2hex(random_bytes(16));
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $_SESSION['saml_request_id'] = $id;
        // ★ (2026-10-29 03:51, 17차, 펜닐 결정 '요청한 응답만') 요청 ID 를 서버에도 10분 저장 — IdP 가 보내는 콜백은 다른 사이트에서 오는 POST 라
        //   세션 쿠키(SameSite=Lax)가 안 따라와 세션 값으로는 확인할 수 없음. 콜백에서 InResponseTo 와 맞춰 보고 한 번 쓰면 지움
        $this->samlStorePut('saml_requests', $id, time() + 600);
        
        $authnRequest = <<<XML
<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="{$id}"
    Version="2.0"
    IssueInstant="{$issueInstant}"
    AssertionConsumerServiceURL="{$acsUrl}"
    ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">
    <saml:Issuer>{$entityId}</saml:Issuer>
    <samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress" AllowCreate="true"/>
</samlp:AuthnRequest>
XML;
        
        $encoded = base64_encode(gzdeflate($authnRequest));
        $params = [
            'SAMLRequest' => $encoded,
            'RelayState' => $acsUrl,
        ];
        
        return $ssoUrl . '?' . http_build_query($params);
    }
    
    /**
     * SAML Response 처리
     */
    /**
     * ★ (2026-10-28 18:22, 소스 점검 5단계) SAML 서명 검증 — ① cert 로 SignedInfo 서명(SignatureValue) 확인
     *   ② Reference 가 가리키는 요소를 찾아 enveloped-signature 변환(그 요소 안 Signature 제거) 후 C14N → digest 재계산 → DigestValue 와 대조
     *   ③ 서명된 요소(값을 읽어도 되는 범위)를 돌려줌. 하나라도 어긋나면 예외.
     * ★ (2026-10-28 19:55, 6차 점검) 종전엔 digest 계산 때 복제본 안의 ds:Signature 를 '전부' 지워서, 서명 블록 모양으로 감싼 가짜 NameID/Attribute 를
     *   끼워 넣어도 digest 가 맞아 admin 으로 로그인됐음(재현). 또 Response·Assertion 둘 다 서명한 IdP 와 PrefixList(xs 등)를 쓰는 IdP 는 정상 응답도 거부됐음.
     *   → Response/Assertion 의 '바로 아래' Signature 만 서명으로 인정하고 모두 검증, 각 서명은 자기 부모만 가리켜야 하며(ID 중복이면 거부),
     *     digest 는 원본 문서에서 '그 서명 하나만' 잠시 빼고 계산(조상 namespace 문맥 유지), C14N 방식·InclusiveNamespaces PrefixList 를 따름.
     *   서명된 요소는 Response 서명이 있으면 Response, 없으면 Assertion.
     */
    private function samlVerifySignature(DOMDocument $doc, DOMXPath $xpath, string $idpCert): DOMElement {
        $nsOk = ['urn:oasis:names:tc:SAML:2.0:protocol', 'urn:oasis:names:tc:SAML:2.0:assertion'];
        $cands = [];
        foreach ($xpath->query('//ds:Signature') as $s) {
            $p = $s->parentNode;
            if ($p instanceof DOMElement && in_array($p->localName, ['Response', 'Assertion'], true) && in_array($p->namespaceURI, $nsOk, true)) $cands[] = $s;
        }
        if (!$cands) throw new Exception('SAML Response에 서명이 없습니다.');

        // cert → PEM → 공개키
        $certPem = $idpCert;
        if (strpos($certPem, '-----BEGIN') === false) {
            $certPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', $certPem), 64, "\n") . "-----END CERTIFICATE-----\n";
        }
        $pubKey = openssl_pkey_get_public($certPem);
        if (!$pubKey) throw new Exception('SAML IdP 인증서를 파싱할 수 없습니다.');

        $signedEl = null;
        foreach ($cands as $sig) {
            $t = $this->samlVerifyOne($doc, $xpath, $sig, $pubKey);
            if ($signedEl === null || $t->localName === 'Response') $signedEl = $t;
        }
        return $signedEl;
    }

    /** ★ (2026-10-28 19:55, 6차 점검) InclusiveNamespaces PrefixList 읽기 (없으면 null) */
    private function samlPrefixList(?DOMNode $node): ?array {
        if (!$node instanceof DOMElement) return null;
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === 'InclusiveNamespaces' && $c->namespaceURI === 'http://www.w3.org/2001/10/xml-exc-c14n#') {
                $pl = preg_split('/\s+/', trim($c->getAttribute('PrefixList')), -1, PREG_SPLIT_NO_EMPTY);
                return $pl ?: null;
            }
        }
        return null;
    }

    /** ★ (2026-10-28 19:55, 6차 점검) 서명 하나 검증 → 서명된 요소(서명의 부모) 반환 */
    private function samlVerifyOne(DOMDocument $doc, DOMXPath $xpath, DOMElement $sig, $pubKey): DOMElement {
        $c14nAlgos = [
            'http://www.w3.org/2001/10/xml-exc-c14n#' => [true, false],
            'http://www.w3.org/2001/10/xml-exc-c14n#WithComments' => [true, true],
            'http://www.w3.org/TR/2001/REC-xml-c14n-20010315' => [false, false],
            'http://www.w3.org/TR/2001/REC-xml-c14n-20010315#WithComments' => [false, true],
        ];
        $signedInfo = $xpath->query('./ds:SignedInfo', $sig)->item(0);
        $sigValueNode = $xpath->query('./ds:SignatureValue', $sig)->item(0);
        $refs = $signedInfo ? $xpath->query('./ds:Reference', $signedInfo) : null;
        if (!$signedInfo || !$sigValueNode || !$refs || $refs->length !== 1) {
            throw new Exception('SAML Response 서명 구조가 올바르지 않습니다.');
        }
        $ref = $refs->item(0);
        $digestValueNode = $xpath->query('./ds:DigestValue', $ref)->item(0);
        if (!$digestValueNode) throw new Exception('SAML Response 서명에 DigestValue가 없습니다.');

        // ① SignatureValue 검증 (SignedInfo 를 CanonicalizationMethod 대로 정규화)
        $cmNode = $xpath->query('./ds:CanonicalizationMethod', $signedInfo)->item(0);
        $cmAlgo = $cmNode ? $cmNode->getAttribute('Algorithm') : 'http://www.w3.org/2001/10/xml-exc-c14n#';
        if (!isset($c14nAlgos[$cmAlgo])) throw new Exception('SAML: 지원하지 않는 정규화 방식입니다.');
        [$siExcl, $siCmt] = $c14nAlgos[$cmAlgo];
        $siAlgoNode = $xpath->query('./ds:SignatureMethod', $signedInfo)->item(0);
        $siAlgo = $siAlgoNode ? $siAlgoNode->getAttribute('Algorithm') : '';
        $openAlgo = (strpos($siAlgo, 'sha1') !== false) ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
        $signatureValue = base64_decode(preg_replace('/\s+/', '', $sigValueNode->textContent));
        $signedInfoC14N = $signedInfo->C14N($siExcl, $siCmt, null, $siExcl ? $this->samlPrefixList($cmNode) : null);
        if (openssl_verify($signedInfoC14N, $signatureValue, $pubKey, $openAlgo) !== 1) {
            throw new Exception('SAML Response 서명 검증 실패: 서명이 IdP 인증서와 일치하지 않습니다.');
        }

        // Reference 는 이 서명의 부모(Response/Assertion)를 가리켜야 하고, 그 ID 를 가진 요소는 문서에 하나뿐이어야 함
        $target = $sig->parentNode;
        $uri = $ref->getAttribute('URI');
        if ($uri === '' || $uri[0] !== '#') throw new Exception('SAML: 지원하지 않는 서명 참조입니다.');
        $refId = substr($uri, 1);
        $tid = $target->getAttribute('ID') ?: ($target->getAttribute('AssertionID') ?: $target->getAttribute('Id'));
        if ($tid === '' || $tid !== $refId) throw new Exception('SAML: 서명 위치가 올바르지 않습니다(enveloped 아님).');
        $cnt = 0;
        foreach ($doc->getElementsByTagName('*') as $el) {
            foreach (['ID', 'Id', 'AssertionID'] as $idAttr) {
                if ($el->hasAttribute($idAttr) && $el->getAttribute($idAttr) === $refId) { $cnt++; break; }
            }
        }
        if ($cnt !== 1) throw new Exception('SAML: 같은 ID 를 가진 요소가 여러 개입니다(서명 래핑 의심).');

        // 변환: enveloped-signature + C14N (그 외 변환은 거부)
        $excl = true; $cmt = false; $prefixes = null; $hasEnv = false;
        foreach ($xpath->query('./ds:Transforms/ds:Transform', $ref) as $tr) {
            $al = $tr->getAttribute('Algorithm');
            if ($al === 'http://www.w3.org/2000/09/xmldsig#enveloped-signature') { $hasEnv = true; continue; }
            if (!isset($c14nAlgos[$al])) throw new Exception('SAML: 지원하지 않는 서명 변환입니다.');
            [$excl, $cmt] = $c14nAlgos[$al];
            $prefixes = $excl ? $this->samlPrefixList($tr) : null;
        }
        if (!$hasEnv) throw new Exception('SAML: 서명 변환(enveloped-signature)이 없습니다.');

        // ② 이 서명 하나만 잠시 빼고 정규화 → digest 대조 → 제자리에 되돌림
        $next = $sig->nextSibling;
        $target->removeChild($sig);
        try {
            $c14n = $target->C14N($excl, $cmt, null, $prefixes);
        } finally {
            if ($next) $target->insertBefore($sig, $next); else $target->appendChild($sig);
        }
        if ($c14n === false) throw new Exception('SAML: 서명 대상 정규화 실패');
        $dmNode = $xpath->query('./ds:DigestMethod', $ref)->item(0);
        $dmAlgo = $dmNode ? $dmNode->getAttribute('Algorithm') : '';
        $hashAlgo = (strpos($dmAlgo, 'sha512') !== false) ? 'sha512' : ((strpos($dmAlgo, 'sha384') !== false) ? 'sha384' : ((strpos($dmAlgo, 'sha1') !== false) ? 'sha1' : 'sha256'));
        $calc = base64_encode(hash($hashAlgo, $c14n, true));
        $expected = preg_replace('/\s+/', '', $digestValueNode->textContent);
        if (!hash_equals($expected, $calc)) {
            throw new Exception('SAML: 서명된 내용이 변조되었습니다(digest 불일치).');
        }
        return $target;
    }

    public function samlCallback(string $samlResponse): ?array {
        $config = $this->getConfig()['saml'];
        if (empty($config['enabled'])) throw new Exception('SAML이 비활성화되어 있습니다.');
        
        $xml = base64_decode($samlResponse);
        if (!$xml) throw new Exception('SAML Response 디코딩 실패');
        
        $doc = new DOMDocument();
        // 보안: XXE 공격 방어 - 외부 네트워크 접근 차단
        @$doc->loadXML($xml, LIBXML_NONET);
        // 명시적으로 DOCTYPE 차단 (SAML Response에는 DOCTYPE 없어야 함)
        if ($doc->doctype) {
            throw new Exception('SAML Response에 DOCTYPE 선언이 있습니다 (XXE 공격 의심)');
        }
        
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        
        // Status 확인
        $statusNode = $xpath->query('//samlp:StatusCode')->item(0);
        if ($statusNode) {
            $statusValue = $statusNode->getAttribute('Value');
            if (strpos($statusValue, 'Success') === false) {
                throw new Exception('SAML 인증 실패: ' . $statusValue);
            }
        }
        
        // IdP 인증서로 서명 검증
        $idpCert = $config['idp_cert'] ?? '';
        if ($idpCert) {
            // 서명 존재 여부 확인 (인증서 설정 시 서명 필수)
            $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
            $sigNodes = $xpath->query('//ds:Signature');
            if ($sigNodes->length === 0) {
                throw new Exception('SAML Response에 서명이 없습니다. IdP 인증서가 설정되어 있으므로 서명이 필수입니다.');
            }
            
            // 기본 서명 검증: SignedInfo의 DigestValue 존재 확인
            $digestNodes = $xpath->query('//ds:DigestValue');
            if ($digestNodes->length === 0) {
                throw new Exception('SAML Response 서명에 DigestValue가 없습니다.');
            }
            
            // ★ (2026-10-28 18:22, 소스 점검 5단계) 종전엔 SignedInfo 의 서명만 보고, Reference 가 가리키는 요소의 실제 digest 재계산도,
            //   서명된 요소와 값을 읽는 요소(NameID·Attribute)의 연결도 없었음 → 서명은 그대로 두고 NameID 만 바꾸거나(서명 래핑) 다른 요소를
            //   끼워 admin 으로 로그인할 수 있었음. 아래에서 ① SignatureValue(SignedInfo 서명) ② Reference digest 재계산 ③ 서명된 요소 안의
            //   NameID/Attribute 만 사용 — 셋을 모두 검증하고, 서명된 요소를 $signedEl 로 넘긴다.
            $signedEl = $this->samlVerifySignature($doc, $xpath, $idpCert);
        } else {
            // ★ (2026-10-28 19:55, 6차 점검) 종전엔 idp_cert 미설정이면 서명 검증 없이 받아들여, 누구나 만든 응답으로 아무 계정(admin 포함)에
            //   로그인할 수 있었음(재현). 서명을 확인할 방법이 없으면 거부(설정 화면에서 IdP 인증서를 넣어야 SAML 로그인 가능)
            throw new Exception('SAML IdP 인증서가 설정되지 않아 응답의 서명을 확인할 수 없습니다. 관리자 SSO 설정에서 IdP 인증서를 입력하세요.');
        }
        
        // 속성 추출
        $usernameAttr = $config['username_attr'] ?? 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name';
        $emailAttr = $config['email_attr'] ?? 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress';
        $displayNameAttr = $config['display_name_attr'] ?? 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname';
        
        // ★ (2026-10-28 18:22, 소스 점검 5단계) 값은 '서명된 요소 안'에서만 읽음(서명 밖에 끼워 넣은 NameID/Attribute 무시).
        //   서명된 요소가 Response 면 그 안에 Assertion 이 정확히 1개여야 함(여러 개면 어느 것이 서명됐는지 모호 → 거부). idp_cert 미설정이면 $signedEl 은 null.
        $scope = $signedEl;
        if ($signedEl !== null) {
            if ($signedEl->localName === 'Response') {
                $asserts = $xpath->query('.//saml:Assertion[not(ancestor::ds:Signature)]', $signedEl);
                if ($asserts->length !== 1) throw new Exception('SAML Response: 서명된 응답 안의 Assertion 개수가 올바르지 않습니다.');
                $scope = $asserts->item(0);
            } elseif ($signedEl->localName !== 'Assertion') {
                throw new Exception('SAML Response: 서명된 요소가 Response/Assertion 이 아닙니다.');
            }
            // Conditions NotBefore/NotOnOrAfter(재사용 방지) + Audience(설정 시) 확인
            $cond = $xpath->query('./saml:Conditions', $scope)->item(0);
            if ($cond) {
                $nb = $cond->getAttribute('NotBefore'); $noa = $cond->getAttribute('NotOnOrAfter');
                $now = time();
                if ($nb && $now < strtotime($nb) - 120) throw new Exception('SAML: 아직 유효하지 않은 응답입니다.');
                if ($noa && $now >= strtotime($noa) + 120) throw new Exception('SAML: 만료된 응답입니다.');
                $aud = $config['sp_entity_id'] ?? '';
                if ($aud) {
                    $audNodes = $xpath->query('.//saml:Audience[not(ancestor::ds:Signature)]', $cond);
                    if ($audNodes->length > 0) {
                        $ok = false;
                        foreach ($audNodes as $an) { if (trim($an->textContent) === $aud) { $ok = true; break; } }
                        if (!$ok) throw new Exception('SAML: Audience 가 이 서비스와 일치하지 않습니다.');
                    }
                }
            }
        }
        // ★ (2026-10-29 03:51, 17차, 펜닐 결정 '요청한 응답만') 응답이 우리가 보낸 요청에 대한 것인지·재사용·수신자·만료 확인 —
        //   종전엔 InResponseTo 를 안 봐(요청 없이 보낸 응답·남의 응답도 받음), 같은 응답을 만료 전까지 다시 써도 됐고, Conditions 가 없으면 만료 검사도 없었음
        if ($scope !== null) {
            $this->samlCheckBinding($doc, $xpath, $scope, $signedEl, $config);
        }
        $q = function($expr) use ($xpath, $scope) { return $scope !== null ? $xpath->query($expr, $scope) : $xpath->query($expr); };
        
        $attributes = [];
        $attrNodes = $q('.//saml:Attribute[not(ancestor::ds:Signature)]');   // ★ (2026-10-28 19:55, 6차 점검) 서명 블록 안 값은 무시
        foreach ($attrNodes as $attrNode) {
            $name = $attrNode->getAttribute('Name');
            $valueNode = $xpath->query('saml:AttributeValue', $attrNode)->item(0);
            $attributes[$name] = $valueNode ? $valueNode->textContent : '';
        }
        
        // NameID 가져오기 (서명된 요소 안에서만)
        $nameIdNode = $q('.//saml:NameID[not(ancestor::ds:Signature)]')->item(0);
        $nameId = $nameIdNode ? $nameIdNode->textContent : '';
        
        $username = $attributes[$usernameAttr] ?? $nameId;
        $email = $attributes[$emailAttr] ?? $nameId;
        $displayName = $attributes[$displayNameAttr] ?? $username;
        
        if (empty($username)) {
            throw new Exception('SAML Response에서 사용자 정보를 찾을 수 없습니다.');
        }
        
        return [
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'name_id' => $nameId,
        ];
    }
    
    /**
     * ★ (2026-10-29 03:51, 17차, 펜닐 결정 '요청한 응답만') SAML 응답 연결 검사 — (1) InResponseTo 가 우리가 보낸 요청 ID 이고 아직 안 쓴 것(한 번 쓰면 지움 → 같은 응답 재사용 불가)
     *   (2) Assertion ID 재사용 기록 (3) Recipient·Destination 이 있으면 우리 ACS 주소와 같아야 (4) 만료 시각(Conditions 또는 SubjectConfirmationData)이 반드시 있어야
     */
    private function samlCheckBinding(DOMDocument $doc, DOMXPath $xpath, DOMElement $assertion, ?DOMElement $signedEl, array $config): void {
        $acs = (string)($config['sp_acs_url'] ?? '');
        $root = $doc->documentElement;
        $now = time();
        
        // 서명된 Assertion 안의 SubjectConfirmationData 우선, 없으면(응답 전체가 서명된 경우) Response 의 값
        $scd = $xpath->query('./saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData', $assertion)->item(0);
        $inResp = $scd ? $scd->getAttribute('InResponseTo') : '';
        if ($inResp === '' && $signedEl !== null && $signedEl->localName === 'Response') $inResp = $signedEl->getAttribute('InResponseTo');
        if ($inResp === '') throw new Exception('SAML: 요청하지 않은 응답입니다(InResponseTo 없음). 로그인 버튼으로 다시 시도하세요.');
        if (!$this->samlStoreTake('saml_requests', $inResp)) throw new Exception('SAML: 보낸 요청과 맞지 않거나 이미 사용된 응답입니다.');
        
        if ($acs !== '') {
            $recipient = $scd ? $scd->getAttribute('Recipient') : '';
            if ($recipient !== '' && rtrim($recipient, '/') !== rtrim($acs, '/')) throw new Exception('SAML: 응답 수신자(Recipient)가 이 서비스가 아닙니다.');
            $dest = ($root && $root->localName === 'Response') ? $root->getAttribute('Destination') : '';
            if ($dest !== '' && rtrim($dest, '/') !== rtrim($acs, '/')) throw new Exception('SAML: 응답 목적지(Destination)가 이 서비스가 아닙니다.');
        }
        
        $exp = 0;
        $cond = $xpath->query('./saml:Conditions', $assertion)->item(0);
        if ($cond && $cond->getAttribute('NotOnOrAfter')) $exp = (int)strtotime($cond->getAttribute('NotOnOrAfter'));
        if ($scd && $scd->getAttribute('NotOnOrAfter')) {
            $e2 = (int)strtotime($scd->getAttribute('NotOnOrAfter'));
            if ($e2 > 0 && $now >= $e2 + 120) throw new Exception('SAML: 만료된 응답입니다.');
            if ($exp === 0) $exp = $e2;
        }
        if ($exp <= 0) throw new Exception('SAML: 응답에 만료 시각이 없습니다.');
        
        $aid = $assertion->getAttribute('ID');
        if ($aid !== '') {
            // ★ (2026-10-29 14:34, 21차 점검) 확인과 기록을 한 번의 잠금 안에서 — 종전엔 확인 뒤 기록 사이에 같은 응답이 동시에 들어오면 둘 다 통과(17차 '시험 안 된 것')
            if (!$this->samlStoreAdd('saml_seen', $aid, max($exp + 120, $now + 300))) throw new Exception('SAML: 이미 사용된 응답입니다.');
        }
    }
    
    /** ★ 작은 키-만료 저장소(data/<name>.json) — 만료 지난 항목은 쓸 때 정리 */
    // ★ (2026-10-29 14:34, 21차 점검) 쓰기·꺼내기는 잠금 안에서(atomicUpdate) — 종전엔 읽고-저장 사이에 다른 사람의 SAML 로그인 요청 ID 가 덮여
    //   사라져 '보낸 요청과 맞지 않음' 으로 로그인이 실패할 수 있었고(재현: 동시 30건 중 6~29건만 남음), 같은 응답이 동시에 두 번 통과할 여지도 있었음
    private function samlStorePut(string $name, string $key, int $expires): void {
        $fn = function ($d) use ($key, $expires) {
            $d = is_array($d) ? $d : [];
            $now = time();
            foreach ($d as $k => $v) { if (!is_int($v) || $v < $now) unset($d[$k]); }
            $d[$key] = $expires;
            return $d;
        };
        if (!$this->db->atomicUpdate($name, $fn)) $this->db->save($name, $fn($this->db->load($name) ?: []));
    }
    /** 없을 때만 넣음 — 넣었으면 true, 이미 있으면(유효) false */
    private function samlStoreAdd(string $name, string $key, int $expires): bool {
        $added = false;
        $fn = function ($d) use ($key, $expires, &$added) {
            $d = is_array($d) ? $d : [];
            $now = time();
            foreach ($d as $k => $v) { if (!is_int($v) || $v < $now) unset($d[$k]); }
            if (isset($d[$key])) { $added = false; return $d; }
            $d[$key] = $expires; $added = true;
            return $d;
        };
        if (!$this->db->atomicUpdate($name, $fn)) $this->db->save($name, $fn($this->db->load($name) ?: []));
        return $added;
    }
    private function samlStoreHas(string $name, string $key): bool {
        $d = $this->db->load($name) ?: [];
        return isset($d[$key]) && (int)$d[$key] >= time();
    }
    private function samlStoreTake(string $name, string $key): bool {
        $ok = false;
        $fn = function ($d) use ($key, &$ok) {
            $d = is_array($d) ? $d : [];
            $ok = isset($d[$key]) && (int)$d[$key] >= time();
            unset($d[$key]);
            return $d;
        };
        if (!$this->db->atomicUpdate($name, $fn)) $this->db->save($name, $fn($this->db->load($name) ?: []));
        return $ok;
    }
    
    /**
     * SAML SP 메타데이터 생성
     */
    public function samlGetMetadata(): string {
        $config = $this->getConfig()['saml'];
        $entityId = $config['sp_entity_id'] ?? '';
        $acsUrl = $config['sp_acs_url'] ?? '';
        
        return <<<XML
<?xml version="1.0"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entityId}">
    <md:SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
        <md:AssertionConsumerService 
            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" 
            Location="{$acsUrl}" 
            index="0" isDefault="true"/>
        <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>
    </md:SPSSODescriptor>
</md:EntityDescriptor>
XML;
    }
    
    // ===== 공통: SSO 사용자 → FileStation 계정 매핑 =====
    
    /**
     * SSO 인증된 사용자를 FileStation 계정에 매핑 (없으면 자동 생성)
     */
    public function mapOrCreateUser(array $ssoUser, string $provider): array {
        $username = $this->sanitizeUsername($ssoUser['username'] ?? '');
        $email = is_string($ssoUser['email'] ?? null) ? trim($ssoUser['email']) : '';   // ★ (2026-10-29 08:26, 19차) 문자열만(아래 '' 비교용)
        $displayName = $ssoUser['display_name'] ?? $username;
        
        if (empty($username)) {
            throw new Exception('SSO에서 유효한 사용자 이름을 가져올 수 없습니다.');
        }
        
        $users = $this->db->load('users') ?: [];
        
        // 기존 사용자 찾기 (username 또는 email 매칭)
        // ★ (2026-10-29 07:45, 18차) 아이디가 같은 계정을 먼저 찾고, 없을 때만 메일로 — 종전엔 목록 순서대로 둘 중 먼저 맞는 것을 써서,
        //   아이디가 같은 계정이 있어도 앞쪽의 메일만 같은 다른 계정에 연결될 수 있었음(Kerberos 도 이제 LDAP 메일을 받으므로 같이 정리)
        // ★ (2026-10-29 08:26, 19차, 펜닐 결정 '제공자별 선택') 연결 기준을 관리자 SSO 설정(제공자별 'match_by')에서 고름
        //   username = 아이디만 / email = 메일만 / username_email = 아이디 먼저, 없으면 메일(처음 값 — 종전 동작 그대로)
        $matchBy = $this->matchMode($provider);
        $existingUser = null;
        if ($matchBy !== 'email') {
            foreach ($users as &$u) {
                if (($u['username'] ?? '') === $username) { $existingUser = &$u; break; }
            }
            unset($u);
        }
        if ($existingUser === null && $matchBy !== 'username') {
            if ($matchBy === 'email' && $email === '') {
                throw new Exception(__('sso_match_no_email', 'SSO 에서 메일 주소를 받지 못해 계정을 찾을 수 없습니다(계정 연결 기준: 메일). 관리자에게 문의하세요.'));
            }
            if ($email !== '') {
                foreach ($users as &$u) {
                    if (($u['email'] ?? '') === $email) { $existingUser = &$u; break; }
                }
                unset($u);
            }
            // 메일만 기준인데 메일이 맞는 계정은 없고 같은 아이디 계정이 이미 있으면 — 새로 만들면 아이디가 겹치고, 그 계정에 붙이면 기준을 어기므로 거부
            if ($existingUser === null && $matchBy === 'email') {
                foreach ($users as $u2) {
                    if (($u2['username'] ?? '') === $username) {
                        throw new Exception(__('sso_match_email_conflict', '같은 아이디의 계정이 있지만 메일이 달라 연결하지 않았습니다(계정 연결 기준: 메일). 관리자에게 문의하세요.'));
                    }
                }
            }
        }
        
        if ($existingUser) {
            // ★ (2026-10-29 03:11, 16차) 로컬 로그인과 같은 상태 검사 — 종전엔 SSO 로 들어오면 정지·비활성 계정도 그냥 로그인됐음.
            //   정지(기간 지난 건 제외)·비활성이면 거부. 호출부(try/catch)가 오류로 돌려줌
            $st = $existingUser['status'] ?? 'active';
            if ($st === 'suspended') {
                $until = $existingUser['suspend_until'] ?? null;
                if (!($until && strtotime($until) < strtotime('today'))) {
                    throw new Exception(__('account_suspended', '정지된 계정입니다.'));
                }
            } elseif ($st !== 'active') {
                throw new Exception(__('api_err_account_cannot_login', '로그인할 수 없는 계정입니다.'));
            }
            if (!($existingUser['is_active'] ?? 1)) {
                throw new Exception(__('api_err_account_inactive', '비활성화된 계정입니다.'));
            }
            // ★ (2026-10-29 03:51, 17차, 펜닐 결정 '자동 연결 거부') 관리자·부관리자 계정은 SSO 자동 연결 안 함 — 이름 정리(@ 뒤·특수문자 제거·소문자)나
            //   이메일이 같다는 이유만으로 'admin@아무곳' 같은 SSO 계정이 로컬 관리자로 로그인됐음. 이미 이 제공자로 연결된 계정(sso_provider 같음)은 그대로 허용
            if (in_array($existingUser['role'] ?? 'user', ['admin', 'sub_admin'], true) && ($existingUser['sso_provider'] ?? '') !== $provider) {
                throw new Exception(__('sso_admin_link_denied', '관리자 계정은 SSO 자동 연결이 막혀 있습니다. 관리자가 사용자 관리에서 SSO 연결을 허용해야 합니다.'));
            }
            // SSO 제공자 정보 업데이트
            $existingUser['sso_provider'] = $provider;
            $existingUser['sso_last_login'] = date('Y-m-d H:i:s');
            $ssoUpd = [];
            if ($displayName && empty($existingUser['display_name'])) {
                $existingUser['display_name'] = $displayName;
                $ssoUpd['display_name'] = $displayName;
            }
            // ★ (2026-10-29 17:46, 22차 점검) 바뀐 칸만 잠금 안에서 저장(update) — 종전엔 미리 읽어 둔 전체 목록을 save 해서, 그 사이 관리자가 바꾼 비밀번호·권한·정지를 되돌릴 수 있었음
            $this->db->update('users', ['id' => $existingUser['id']], $ssoUpd + [
                'sso_provider' => $existingUser['sso_provider'],
                'sso_last_login' => $existingUser['sso_last_login'],
            ]);
            return $existingUser;
        }
        
        // 자동 생성 설정 확인
        $ssoConfig = $this->getConfig();
        $providerConfig = $ssoConfig[$provider] ?? [];
        // Kerberos는 LDAP 설정을 fallback으로 사용
        if ($provider === 'kerberos' && empty($providerConfig['auto_create'])) {
            $providerConfig = $ssoConfig['ldap'] ?? $ssoConfig['kerberos'] ?? [];
        }
        if (empty($providerConfig['auto_create'])) {
            throw new Exception('SSO 계정 자동 생성이 비활성화되어 있습니다. 관리자에게 문의하세요.');
        }
        
        // ★ (2026-10-29 17:46, 22차 점검) 새로 만들 아이디가 예약어('_deleted', '.', '..')이거나 대소문자만 다른 기존 계정이 있으면 거부 —
        //   홈 폴더가 USER_FILES_ROOT/아이디 라서 종전엔 '_deleted' 는 삭제된 사용자 보관 폴더, '..' 는 그 상위 폴더가 홈이 됐고,
        //   로컬 'Alice' 가 있으면 SSO 'alice' 가 새 계정으로 만들어져 Windows 에서 같은 홈 폴더를 함께 썼음
        $unProblem = Auth::usernameProblem($this->db, $username);
        if ($unProblem !== null) {
            throw new Exception($unProblem === 'reserved'
                ? __('api_err_username_chars', '아이디는 영문, 숫자, 밑줄만 사용 가능합니다.')
                : __('sso_username_case_conflict', '대소문자만 다른 같은 아이디의 계정이 이미 있어 새 계정을 만들지 않았습니다. 관리자에게 문의하세요.'));
        }
        
        // 새 사용자 생성
        
        $defaultRole = $providerConfig['default_role'] ?? 'user';
        $defaultQuota = (int)($providerConfig['default_quota'] ?? 0);
        
        $newUser = [
            'username' => $username,
            'password' => '!SSO_NO_PASSWORD', // SSO 사용자는 비밀번호 로그인 불가 (password_verify 항상 false)
            'email' => $email,
            'display_name' => $displayName,
            'role' => $defaultRole,
            'quota' => $defaultQuota,
            'status' => 'active',
            'is_active' => 1,
            'sso_provider' => $provider,
            'sso_last_login' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        
        // ★ (2026-10-29 17:46, 22차 점검) 번호 발급·저장을 잠금 안에서(insert) — 종전엔 잠금 없이 load→maxId+1→save 라, 처음 로그인하는 두 사람이 동시에 들어오면
        //   같은 번호를 받고 뒤에 저장한 쪽이 앞 사람 계정을 지웠음(그 사이 관리자의 사용자 수정도 덮어씀)
        $newUser['id'] = $this->db->insert('users', $newUser);
        
        // 홈 폴더 생성
        if (defined('USER_FILES_ROOT')) {
            $homeDir = USER_FILES_ROOT . '/' . $username;
            if (!is_dir($homeDir)) {
                @mkdir($homeDir, 0755, true);
            }
        }
        
        return $newUser;
    }
    
    /**
     * ★ (2026-10-29 08:26, 19차, 펜닐 결정 '제공자별 선택') 제공자별 계정 연결 기준 — 정해진 값이 아니면(설정 전 포함) 'username_email'(종전 동작)
     */
    private function matchMode(string $provider): string {
        $cfg = $this->getConfig()[$provider] ?? [];
        $m = (string)($cfg['match_by'] ?? '');
        return in_array($m, ['username', 'email', 'username_email'], true) ? $m : 'username_email';
    }
    
    /**
     * 사용자명 정리
     */
    private function sanitizeUsername(string $name): string {
        // 이메일에서 @ 앞 부분만
        if (strpos($name, '@') !== false) {
            $name = substr($name, 0, strpos($name, '@'));
        }
        // 특수문자 제거
        $name = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $name);
        return strtolower($name);
    }
    
    // ===== HTTP 헬퍼 =====
    
    private function httpPost(string $url, array $data): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) throw new Exception('HTTP 요청 실패: ' . curl_error($ch));
        curl_close($ch);
        return json_decode($response, true) ?: [];
    }
    
    private function httpGet(string $url, string $token = ''): array {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) throw new Exception('HTTP 요청 실패: ' . curl_error($ch));
        curl_close($ch);
        return json_decode($response, true) ?: [];
    }
}
