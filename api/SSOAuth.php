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
        
        // Apache가 설정한 REMOTE_USER (Kerberos 인증 성공 시)
        $remoteUser = $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? $_SERVER['HTTP_X_REMOTE_USER'] ?? null;
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
                    $ssoUser = array_merge($ssoUser, $ldapInfo);
                }
            } catch (Exception $e) {
                // LDAP 조회 실패해도 Kerberos 인증은 유효
                fsLog("SSO Kerberos LDAP lookup failed: " . $e->getMessage());
            }
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
        
        if ($useStartTls) @ldap_start_tls($conn);
        
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
        
        return [
            'username' => $entries[0][$usernameAttr][0] ?? $username,
            'email' => $entries[0][$emailAttr][0] ?? '',
            'display_name' => $entries[0][$displayNameAttr][0] ?? $username,
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
        
        $userDn = $entries[0]['dn'];
        $userData = [
            'username' => $entries[0][$usernameAttr][0] ?? $username,
            'email' => $entries[0][$emailAttr][0] ?? '',
            'display_name' => $entries[0][$displayNameAttr][0] ?? $username,
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
            
            // 인증서 공개키로 SignatureValue 검증
            $sigValueNode = $xpath->query('//ds:SignatureValue')->item(0);
            $signedInfoNode = $xpath->query('//ds:SignedInfo')->item(0);
            if ($sigValueNode && $signedInfoNode) {
                $signatureValue = base64_decode(preg_replace('/\s+/', '', $sigValueNode->textContent));
                // SignedInfo를 정규화(C14N)하여 검증
                $signedInfoC14N = $signedInfoNode->C14N(true, false);
                
                // PEM 형식으로 변환
                $certPem = $idpCert;
                if (strpos($certPem, '-----BEGIN') === false) {
                    $certPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', $certPem), 64, "\n") . "-----END CERTIFICATE-----\n";
                }
                $pubKey = openssl_pkey_get_public($certPem);
                if ($pubKey) {
                    $verified = openssl_verify($signedInfoC14N, $signatureValue, $pubKey, OPENSSL_ALGO_SHA256);
                    if ($verified === 0) {
                        // SHA256 실패 시 SHA1로 재시도
                        $verified = openssl_verify($signedInfoC14N, $signatureValue, $pubKey, OPENSSL_ALGO_SHA1);
                    }
                    if ($verified !== 1) {
                        throw new Exception('SAML Response 서명 검증 실패: 서명이 IdP 인증서와 일치하지 않습니다.');
                    }
                } else {
                    throw new Exception('SAML IdP 인증서를 파싱할 수 없습니다.');
                }
            } else {
                throw new Exception('SAML Response에서 SignatureValue 또는 SignedInfo를 찾을 수 없습니다.');
            }
        }
        // 주의: idp_cert 미설정 시 서명 검증을 건너뜁니다.
        // 운영 환경에서는 반드시 IdP 인증서를 설정하세요.
        
        // 속성 추출
        $usernameAttr = $config['username_attr'] ?? 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name';
        $emailAttr = $config['email_attr'] ?? 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress';
        $displayNameAttr = $config['display_name_attr'] ?? 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname';
        
        $attributes = [];
        $attrNodes = $xpath->query('//saml:Attribute');
        foreach ($attrNodes as $attrNode) {
            $name = $attrNode->getAttribute('Name');
            $valueNode = $xpath->query('saml:AttributeValue', $attrNode)->item(0);
            $attributes[$name] = $valueNode ? $valueNode->textContent : '';
        }
        
        // NameID 가져오기
        $nameIdNode = $xpath->query('//saml:NameID')->item(0);
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
        $email = $ssoUser['email'] ?? '';
        $displayName = $ssoUser['display_name'] ?? $username;
        
        if (empty($username)) {
            throw new Exception('SSO에서 유효한 사용자 이름을 가져올 수 없습니다.');
        }
        
        $users = $this->db->load('users') ?: [];
        
        // 기존 사용자 찾기 (username 또는 email 매칭)
        $existingUser = null;
        foreach ($users as &$u) {
            if (($u['username'] ?? '') === $username || 
                ($email && ($u['email'] ?? '') === $email)) {
                $existingUser = &$u;
                break;
            }
        }
        unset($u);
        
        if ($existingUser) {
            // SSO 제공자 정보 업데이트
            $existingUser['sso_provider'] = $provider;
            $existingUser['sso_last_login'] = date('Y-m-d H:i:s');
            if ($displayName && empty($existingUser['display_name'])) {
                $existingUser['display_name'] = $displayName;
            }
            $this->db->save('users', $users);
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
        
        // 새 사용자 생성
        $maxId = 0;
        foreach ($users as $u) { if (($u['id'] ?? 0) > $maxId) $maxId = $u['id']; }
        
        $defaultRole = $providerConfig['default_role'] ?? 'user';
        $defaultQuota = (int)($providerConfig['default_quota'] ?? 0);
        
        $newUser = [
            'id' => $maxId + 1,
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
        
        $users[] = $newUser;
        $this->db->save('users', $users);
        
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
