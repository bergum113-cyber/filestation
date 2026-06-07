<?php
require_once __DIR__ . '/php_version_check.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';

// 언어 변경 요청 처리
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ko', 'en'])) {
    setLang($_GET['lang']);
}
$currentLang = getLang();

// 보안 헤더
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// CSP (Content Security Policy) 헤더
// 'unsafe-inline'은 현재 인라인 스크립트/스타일 사용으로 필요, 추후 제거 권장
// 'wasm-unsafe-eval': rhwp_viewer.php / rhwp_editor.php 의 HWP/HWPX 뷰어·편집기 WASM 로드 위해 필요 (펜닐 v5.8.1e)
//   ※ 'unsafe-eval'(JS eval) 보다 훨씬 제한적 — WebAssembly 모듈 컴파일/인스턴스화만 허용
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' blob: https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self' blob: https://cdnjs.cloudflare.com; media-src 'self' blob: data:; frame-src 'self' blob:; frame-ancestors 'self'; worker-src 'self' blob:;");

$auth = new Auth();

// 로그인 상태 체크
$isLoggedIn = $auth->isLoggedIn();
// 세션 만료됐지만 remember_token이 있으면 자동 로그인 시도
if (!$isLoggedIn && method_exists($auth, 'checkRememberToken')) {
    if ($auth->checkRememberToken()) {
        $isLoggedIn = true;
    }
}
// Kerberos/SPNEGO 자동 로그인 (AD 조인 환경)
if (!$isLoggedIn) {
    try {
        $ssoAuth = new SSOAuth();
        $kerberosUser = $ssoAuth->kerberosAutoLogin();
        if ($kerberosUser) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $kerberosUser['id'];
            $_SESSION['username'] = $kerberosUser['username'];
            $_SESSION['role'] = $kerberosUser['role'] ?? 'user';
            $_SESSION['last_activity'] = time();
            $_SESSION['sso_provider'] = 'kerberos';
            $isLoggedIn = true;
        }
    } catch (Exception $e) {}
}

// CSRF 토큰 생성
$csrfToken = getCsrfToken();

// 시스템 설정 로드
$siteSettings = [];
$settingsFile = __DIR__ . '/data/site_settings.json';
if (file_exists($settingsFile)) {
    $siteSettings = json_decode(file_get_contents($settingsFile), true) ?: [];
}
$siteName = !empty($siteSettings['site_name']) ? $siteSettings['site_name'] : 'FileStation';
$logoImage = $siteSettings['logo_image'] ?? '';
$bgImage = $siteSettings['bg_image'] ?? '';
$bgFilterPreset = $siteSettings['bg_filter_preset'] ?? 'none';
$bgFit = $siteSettings['bg_fit'] ?? 'cover';

// bg_fit → CSS background-size/position 매핑
$bgFitStyles = [
    'cover'  => 'center/cover no-repeat',
    'contain'=> 'center/contain no-repeat',
    'fill'   => 'center/100% 100% no-repeat',
    'tile'   => 'top left/auto repeat',
    'center' => 'center/auto no-repeat',
    'span'   => 'center/100% auto no-repeat',
];
$bgFitStyle = $bgFitStyles[$bgFit] ?? $bgFitStyles['cover'];

// 프리셋별 필터 값 정의
$filterPresets = [
    'none' => ['brightness' => 100, 'blur' => 0, 'saturate' => 100, 'overlay' => '', 'opacity' => 0],
    'dark' => ['brightness' => 60, 'blur' => 0, 'saturate' => 100, 'overlay' => '#000000', 'opacity' => 30],
    'blur' => ['brightness' => 100, 'blur' => 5, 'saturate' => 100, 'overlay' => '', 'opacity' => 0],
    'cinema' => ['brightness' => 80, 'blur' => 0, 'saturate' => 80, 'overlay' => '#1a1a2e', 'opacity' => 40],
    'purple' => ['brightness' => 90, 'blur' => 0, 'saturate' => 100, 'overlay' => '#6b21a8', 'opacity' => 40],
    'blue' => ['brightness' => 90, 'blur' => 0, 'saturate' => 100, 'overlay' => '#1e40af', 'opacity' => 40],
    'warm' => ['brightness' => 100, 'blur' => 0, 'saturate' => 120, 'overlay' => '#f97316', 'opacity' => 20],
    'bw' => ['brightness' => 100, 'blur' => 0, 'saturate' => 0, 'overlay' => '', 'opacity' => 0],
    'vintage' => ['brightness' => 90, 'blur' => 0, 'saturate' => 70, 'overlay' => '#a16207', 'opacity' => 20],
    'cool' => ['brightness' => 100, 'blur' => 0, 'saturate' => 90, 'overlay' => '#0ea5e9', 'opacity' => 25],
    'sunset' => ['brightness' => 95, 'blur' => 0, 'saturate' => 120, 'overlay' => '#ea580c', 'opacity' => 30],
    'neon' => ['brightness' => 110, 'blur' => 0, 'saturate' => 150, 'overlay' => '#d946ef', 'opacity' => 25],
];
$bgFilter = $filterPresets[$bgFilterPreset] ?? $filterPresets['none'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($siteName) ?></title>
    <?php
    $faviconPath = null;
    foreach (['favicon.ico', 'favicon.png', 'favicon.svg'] as $f) {
        if (file_exists(__DIR__ . '/' . $f)) {
            $faviconPath = $f;
            break;
        }
    }
    ?>
    <?php if ($faviconPath): ?>
    <link rel="icon" href="<?= htmlspecialchars($faviconPath) ?>">
    <?php else: ?>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E📁%3C/text%3E%3C/svg%3E">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo substr(md5_file(__DIR__ . '/assets/css/style.css'), 0, 10); ?>">
    <link rel="stylesheet" href="assets/lib/viewerjs/viewer.min.css">
    <!-- 테마 깜빡임 방지: localStorage에서 테마 즉시 로드 -->
    <script>
    (function() {
        var theme = localStorage.getItem('theme') || 'default';
        if (/^[a-zA-Z0-9_-]+$/.test(theme)) {
            document.write('<link id="theme-css" rel="stylesheet" href="assets/themes/' + theme + '/theme.css">');
        }
    })();
    </script>
    <?php if ($bgImage): 
        // 오버레이 색상 RGB 변환
        $overlayColor = $bgFilter['overlay'];
        $overlayOpacity = $bgFilter['opacity'];
        if ($overlayColor) {
            $overlayRgb = sscanf($overlayColor, "#%02x%02x%02x");
            $overlayR = $overlayRgb[0] ?? 0;
            $overlayG = $overlayRgb[1] ?? 0;
            $overlayB = $overlayRgb[2] ?? 0;
        }
        $overlayAlpha = $overlayOpacity / 100;
    ?>
    <style>
        #login-screen {
            position: relative;
        }
        #login-screen::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('<?= htmlspecialchars($bgImage) ?>') <?= htmlspecialchars($bgFitStyle) ?>;
            filter: brightness(<?= (int)$bgFilter['brightness'] ?>%) blur(<?= (int)$bgFilter['blur'] ?>px) saturate(<?= (int)$bgFilter['saturate'] ?>%);
            z-index: -2;
        }
        <?php if ($overlayOpacity > 0 && $overlayColor): ?>
        #login-screen::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(<?= (int)$overlayR ?>, <?= (int)$overlayG ?>, <?= (int)$overlayB ?>, <?= (float)$overlayAlpha ?>);
            z-index: -1;
        }
        <?php endif; ?>
    </style>
    <?php endif; ?>
    <script src="assets/vendor/qrcode.min.js"></script>
    <script src="assets/vendor/ckeditor/ckeditor.js"></script>
    <style>
        /* 테마 로딩 전 깜빡임 방지 - opacity 사용 */
        body { opacity: 0; transition: opacity 0.1s; }
        body.loaded { opacity: 1; }
    </style>
    <!-- date input: OS 로케일 기본 표시 사용 -->
    <style>
        /* CKEditor 4 컨테이너 스타일 */
        .cke { border-radius: 6px !important; }
        .cke_inner { border-radius: 6px !important; }
        .cke_top { border-radius: 6px 6px 0 0 !important; }
        .cke_bottom { border-radius: 0 0 6px 6px !important; }
        .img-resize-handle { display: inline-block; position: relative; }
    </style>
</head>
<body>
    <script>
        // 테마 로드 완료 후 body 표시
        (function() {
            var link = document.getElementById('theme-css');
            function showBody() {
                // requestAnimationFrame 두 번으로 스타일 적용 보장
                requestAnimationFrame(function() {
                    requestAnimationFrame(function() {
                        document.body.classList.add('loaded');
                    });
                });
            }
            if (link) {
                if (link.sheet) {
                    showBody();
                } else {
                    link.onload = showBody;
                    link.onerror = showBody;
                    setTimeout(showBody, 300);
                }
            } else {
                showBody();
            }
        })();
    </script>
    <div id="app">
        <!-- 로그인 화면 -->
        <div id="login-screen" class="screen <?php echo $isLoggedIn ? 'hidden' : 'active'; ?>">
            <!-- 시작 화면 - 로고 클릭 유도 -->
            <div class="login-start" id="login-start">
                <div class="login-start-header" id="login-start-logo">
                    <div class="login-start-logo">
                        <?php if ($logoImage): ?>
                        <img src="<?= htmlspecialchars($logoImage) ?>" alt="Logo">
                        <?php else: ?>
                        <svg viewBox="0 0 48 48" class="default-logo-svg">
                            <path fill="#3b82f6" d="M40 12H22l-4-4H8c-2.2 0-4 1.8-4 4v8h40v-4c0-2.2-1.8-4-4-4z"/>
                            <path fill="#1d4ed8" d="M40 12H8c-2.2 0-4 1.8-4 4v20c0 2.2 1.8 4 4 4h32c2.2 0 4-1.8 4-4V16c0-2.2-1.8-4-4-4z"/>
                        </svg>
                        <?php endif; ?>
                    </div>
                    <h1 class="login-start-title"><?php
                        $name = htmlspecialchars($siteName);
                        // "Station" 부분을 파란색으로
                        echo preg_replace('/(Station)/i', '<span>$1</span>', $name);
                    ?></h1>
                </div>
                <div class="login-start-hint">
                    <span><?php echo $currentLang === 'en' ? 'Click to login' : '클릭하여 로그인'; ?></span>
                </div>
            </div>
            
            <!-- 로그인 박스 -->
            <div class="login-box" id="login-box" style="display:none;">
                <button class="login-box-close" id="login-box-close">×</button>
                <?php if ($logoImage): ?>
                <div class="login-logo"><img src="<?= htmlspecialchars($logoImage) ?>" alt="Logo"></div>
                <?php else: ?>
                <div class="login-logo">
                    <svg viewBox="0 0 48 48" width="64" height="64">
                        <path fill="#3b82f6" d="M40 12H22l-4-4H8c-2.2 0-4 1.8-4 4v8h40v-4c0-2.2-1.8-4-4-4z"/>
                        <path fill="#1d4ed8" d="M40 12H8c-2.2 0-4 1.8-4 4v20c0 2.2 1.8 4 4 4h32c2.2 0 4-1.8 4-4V16c0-2.2-1.8-4-4-4z"/>
                    </svg>
                </div>
                <?php endif; ?>
                <h1><?= htmlspecialchars($siteName) ?></h1>
                
                <!-- 로그인 폼 -->
                <form id="login-form">
                    <input type="text" id="login-username" placeholder="<?php _e('username'); ?>" required>
                    <input type="password" id="login-password" placeholder="<?php _e('password'); ?>" required>
                    <label class="remember-me">
                        <input type="checkbox" id="login-remember">
                        <span><?php _e('remember_me'); ?></span>
                    </label>
                    <button type="submit" class="btn btn-primary btn-block"><?php _e('login'); ?></button>
                </form>
                
                <!-- SSO 로그인 버튼 -->
                <div id="sso-buttons" style="display:none;margin-top:12px;">
                    <div style="text-align:center;color:#999;font-size:12px;margin:8px 0;">
                        <span style="background:#fff;padding:0 10px;">OR</span>
                    </div>
                    <button id="sso-ldap-btn" class="btn btn-block" style="display:none;background:#2c3e50;color:#fff;margin-bottom:6px;" onclick="App.showLdapLoginForm()">
                        🔐 LDAP / AD <?php echo $currentLang === 'en' ? 'Login' : '로그인'; ?>
                    </button>
                    <button id="sso-oidc-btn" class="btn btn-block" style="display:none;background:#4285f4;color:#fff;margin-bottom:6px;" onclick="App.ssoOidcLogin()">
                        🌐 <span id="sso-oidc-label">SSO <?php echo $currentLang === 'en' ? 'Login' : '로그인'; ?></span>
                    </button>
                    <button id="sso-saml-btn" class="btn btn-block" style="display:none;background:#e67e22;color:#fff;margin-bottom:6px;" onclick="App.ssoSamlLogin()">
                        🔑 <span id="sso-saml-label">SAML <?php echo $currentLang === 'en' ? 'Login' : '로그인'; ?></span>
                    </button>
                </div>
                
                <!-- LDAP 로그인 폼 -->
                <form id="ldap-login-form" style="display:none;">
                    <p style="text-align:center;font-size:13px;color:#666;margin-bottom:10px;">🔐 LDAP / Active Directory</p>
                    <input type="text" id="ldap-username" placeholder="<?php echo $currentLang === 'en' ? 'Domain Username' : '도메인 계정'; ?>" required>
                    <input type="password" id="ldap-password" placeholder="<?php echo $currentLang === 'en' ? 'Password' : '비밀번호'; ?>" required>
                    <label class="remember-me">
                        <input type="checkbox" id="ldap-remember">
                        <span><?php _e('remember_me'); ?></span>
                    </label>
                    <button type="submit" class="btn btn-primary btn-block">LDAP <?php _e('login'); ?></button>
                    <button type="button" class="btn btn-block" style="margin-top:6px;" onclick="App.showNormalLoginForm()">← <?php echo $currentLang === 'en' ? 'Back' : '돌아가기'; ?></button>
                </form>
                
                <!-- 2FA 입력 폼 -->
                <form id="twofa-form" style="display:none;">
                    <!-- OTP 입력 -->
                    <div id="twofa-otp-section">
                        <p style="text-align:center; margin-bottom:15px; color:#666;">
                            🔐 <?php echo $currentLang === 'en' ? 'Enter the 6-digit code from your authenticator app' : '인증 앱의 6자리 코드를 입력하세요'; ?>
                        </p>
                        <input type="text" id="twofa-code" placeholder="000000" maxlength="6" 
                               inputmode="numeric" pattern="[0-9]*"
                               style="text-align:center; font-size:24px; letter-spacing:5px;" required
                               oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <p style="text-align:center; margin:15px 0 10px 0;">
                            <a href="#" id="show-backup-code" style="font-size:13px; color:#666;"><?php echo $currentLang === 'en' ? 'Login with backup code' : '백업 코드로 로그인'; ?></a>
                        </p>
                    </div>
                    
                    <!-- 백업 코드 입력 -->
                    <div id="twofa-backup-section" style="display:none;">
                        <p style="text-align:center; margin-bottom:15px; color:#666;">
                            🔑 <?php echo $currentLang === 'en' ? 'Enter your backup code' : '백업 코드를 입력하세요'; ?>
                        </p>
                        <input type="text" id="twofa-backup-code" placeholder="1234-5678" maxlength="9" 
                               inputmode="numeric" pattern="[0-9\-]*"
                               style="text-align:center; font-size:24px; letter-spacing:3px;"
                               oninput="this.value = this.value.replace(/[^0-9\-]/g, '')">
                        <p style="text-align:center; margin:15px 0 10px 0;">
                            <a href="#" id="show-otp-code" style="font-size:13px; color:#666;"><?php echo $currentLang === 'en' ? 'Login with OTP code' : 'OTP 코드로 로그인'; ?></a>
                        </p>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block"><?php _e('confirm'); ?></button>
                    <button type="button" class="btn btn-block" id="btn-twofa-back" style="margin-top:10px;"><?php _e('relogin'); ?></button>
                </form>
                
                <div id="login-error" class="error-msg"></div>
                <div id="first-user-notice" class="first-user-notice" style="display:none;">
                    🎉 <strong><?php echo $currentLang === 'en' ? 'Welcome!' : '처음 오셨군요!'; ?></strong><br>
                    <?php echo $currentLang === 'en' ? 'Sign up to become an administrator.' : '회원가입하시면 관리자 계정이 됩니다.'; ?>
                </div>
                <div class="login-links" id="forgot-password-wrap" style="display:none;">
                    <a href="#" id="show-forgot-username" style="font-size:13px; color:#888;"><?php echo $currentLang === 'en' ? 'Forgot username?' : '아이디를 잊으셨나요?'; ?></a>
                    <span style="font-size:13px; color:#ccc;"> | </span>
                    <a href="#" id="show-forgot-password" style="font-size:13px; color:#888;"><?php _e('forgot_password'); ?></a>
                </div>
                <div class="login-links" id="signup-link-wrap" style="display:none;">
                    <span><?php _e('no_account'); ?></span> <a href="#" id="show-signup"><?php _e('register'); ?></a>
                </div>
                <div id="login-copyright" class="login-copyright" style="display:none;"></div>
            </div>
            
            <!-- 아이디 찾기 폼 -->
            <div class="login-box" id="find-username-box" style="display:none;">
                <?php if ($logoImage): ?>
                <div class="login-logo"><img src="<?= htmlspecialchars($logoImage) ?>" alt="Logo"></div>
                <?php else: ?>
                <div class="login-logo">🔍</div>
                <?php endif; ?>
                <h1><?php echo $currentLang === 'en' ? 'Find Username' : '아이디 찾기'; ?></h1>
                <form id="find-username-form">
                    <input type="email" id="find-username-email" placeholder="<?php _e('email_registered'); ?>" required>
                    <button type="submit" class="btn btn-primary btn-block"><?php echo $currentLang === 'en' ? 'Find Username' : '아이디 찾기'; ?></button>
                </form>
                <div id="find-username-error" class="error-msg"></div>
                <div id="find-username-success" class="success-msg" style="display:none;"></div>
                <div class="login-links">
                    <a href="#" id="back-to-login-from-find"><?php _e('back_to_login'); ?></a>
                </div>
            </div>
            
            <!-- 비밀번호 찾기 폼 -->
            <div class="login-box" id="forgot-box" style="display:none;">
                <?php if ($logoImage): ?>
                <div class="login-logo"><img src="<?= htmlspecialchars($logoImage) ?>" alt="Logo"></div>
                <?php else: ?>
                <div class="login-logo">🔑</div>
                <?php endif; ?>
                <h1><?php echo $currentLang === 'en' ? 'Forgot Password' : '비밀번호 찾기'; ?></h1>
                <form id="forgot-form">
                    <input type="text" id="forgot-username" placeholder="<?php echo $currentLang === 'en' ? 'Username' : '아이디'; ?>" required>
                    <input type="email" id="forgot-email" placeholder="<?php echo $currentLang === 'en' ? 'Email registered at signup' : '가입 시 등록한 이메일'; ?>" required>
                    <button type="submit" class="btn btn-primary btn-block"><?php echo $currentLang === 'en' ? 'Send Temporary Password' : '임시 비밀번호 발송'; ?></button>
                </form>
                <div id="forgot-error" class="error-msg"></div>
                <div id="forgot-success" class="success-msg" style="display:none;"></div>
                <div class="login-links">
                    <a href="#" id="back-to-login"><?php echo $currentLang === 'en' ? '← Back to Login' : '← 로그인으로 돌아가기'; ?></a>
                </div>
            </div>
            
            <!-- 회원가입 폼 -->
            <div class="login-box" id="signup-box" style="display:none;">
                <?php if ($logoImage): ?>
                <div class="login-logo"><img src="<?= htmlspecialchars($logoImage) ?>" alt="Logo"></div>
                <?php else: ?>
                <div class="login-logo">📝</div>
                <?php endif; ?>
                <h1><?php _e('register'); ?></h1>
                <form id="signup-form">
                    <input type="text" id="signup-username" placeholder="<?php echo $currentLang === 'en' ? 'Username' : '아이디'; ?>" required pattern="[a-zA-Z0-9_]{3,20}" maxlength="20" title="<?php echo $currentLang === 'en' ? 'Letters, numbers, underscore only (3-20 chars)' : '영문, 숫자, 밑줄만 사용 가능 (3~20자)'; ?>">
                    <div class="input-hint"><?php echo $currentLang === 'en' ? '3-20 characters, letters/numbers/underscore only' : '3~20자, 영문/숫자/밑줄만 가능'; ?></div>
                    <input type="password" id="signup-password" placeholder="<?php _e('password'); ?>" required maxlength="72">
                    <div class="password-strength" id="signup-password-strength"></div>
                    <input type="password" id="signup-password2" placeholder="<?php _e('password_confirm'); ?>" required maxlength="72">
                    <input type="text" id="signup-displayname" placeholder="<?php echo $currentLang === 'en' ? 'Display Name' : '표시 이름'; ?>" required maxlength="30">
                    <div class="input-hint"><?php echo $currentLang === 'en' ? 'Max 30 characters' : '최대 30자'; ?></div>
                    <input type="email" id="signup-email" placeholder="<?php echo $currentLang === 'en' ? 'Email (optional)' : '이메일 (선택)'; ?>">
                    
                    <!-- 약관 동의 (약관이 있을 때만 표시) -->
                    <div id="signup-terms-wrap" style="display:none; margin: 15px 0;">
                        <div class="terms-container" id="signup-terms-content"></div>
                        <div class="terms-agree-wrap">
                            <input type="checkbox" id="signup-terms-agree">
                            <label for="signup-terms-agree"><?php echo $currentLang === 'en' ? 'I agree to the Terms of Service (required)' : '이용약관에 동의합니다 (필수)'; ?></label>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block"><?php _e('signup'); ?></button>
                </form>
                <div id="signup-error" class="error-msg"></div>
                <div class="login-links">
                    <span><?php _e('has_account'); ?></span> <a href="#" id="show-login"><?php _e('login'); ?></a>
                </div>
            </div>
            
            <!-- 로그인 화면 언어 선택 -->
            <div class="login-language-selector">
                <select id="login-language-select" onchange="window.location.href='?lang='+this.value">
                    <option value="ko" <?php echo $currentLang === 'ko' ? 'selected' : ''; ?>>🇰🇷 한국어</option>
                    <option value="en" <?php echo $currentLang === 'en' ? 'selected' : ''; ?>>🇺🇸 English</option>
                </select>
            </div>
        </div>
        <div id="main-screen" class="screen <?php echo $isLoggedIn ? 'active' : 'hidden'; ?>">
            <!-- 헤더 -->
            <header class="header">
                <div class="header-left">
                    <button id="mobile-menu-btn" class="mobile-menu-btn">☰</button>
                    <?php if ($logoImage): ?>
                    <span class="logo" style="cursor:pointer;"><img src="<?= htmlspecialchars($logoImage) ?>" alt="Logo" class="header-logo"> <span class="logo-text"><?= htmlspecialchars($siteName) ?></span></span>
                    <span class="header-version" style="font-size:10px;color:#999;margin-left:4px;vertical-align:middle;">v<?php echo APP_VERSION; ?></span>
                    <?php else: ?>
                    <span class="logo" style="cursor:pointer;">📁 <span class="logo-text"><?= htmlspecialchars($siteName) ?></span></span>
                    <span class="header-version" style="font-size:10px;color:#999;margin-left:4px;vertical-align:middle;">v<?php echo APP_VERSION; ?></span>
                    <?php endif; ?>
                </div>
                <div class="header-center">
                    <div class="search-box">
                        <!-- 브라우저 자동완성 방지용 더미 필드 -->
                        <input type="text" style="display:none" aria-hidden="true">
                        <input type="password" style="display:none" aria-hidden="true">
                        <input type="search" id="search-input" placeholder="<?php echo $currentLang === 'en' ? 'Search... (e.g. *.mp4, doc*)' : '전체 검색... (예: *.mp4, 문서*)'; ?>" autocomplete="off" name="q_search_<?= time() ?>" readonly onfocus="this.removeAttribute('readonly')">
                        <button id="search-btn" title="<?php echo $currentLang === 'en' ? 'Search' : '검색'; ?>">🔍</button>
                        <button id="search-filter-toggle" title="<?php echo $currentLang === 'en' ? 'Toggle Filter' : '필터 표시/숨김'; ?>">⚙️</button>
                    </div>
                    <div class="search-hint"><?php echo $currentLang === 'en' ? '🔍 Integrated: fast (indexed) | ⚙️ Filter: real-time (no index needed)' : '🔍 통합검색: 빠름 (인덱스) | ⚙️ 필터 검색: 실시간 (인덱스 불필요)'; ?></div>
                </div>
                <div class="header-right">
                    <button id="mobile-search-btn" class="mobile-search-btn">🔍</button>
                    <div class="digital-clock" id="digital-clock"></div>
                    <span id="user-name" class="user-welcome"></span>
                    <div class="header-notify-wrap" id="header-notify-wrap" style="display:none;">
                        <button id="btn-notify" class="btn-icon" title="<?php echo $currentLang === 'en' ? 'Notifications' : '알림'; ?>">🔔<span class="notify-badge" id="notify-badge">0</span></button>
                    </div>
                    <button id="btn-settings" class="btn-icon" title="<?php _e('settings'); ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></button>
                    <button id="btn-logout" class="btn-icon" title="<?php _e('logout'); ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></button>
                </div>
            </header>
            
            <!-- 모바일 검색 바 -->
            <div id="mobile-search-bar" class="mobile-search-bar">
                <!-- 브라우저 자동완성 방지용 더미 필드 -->
                <input type="text" style="display:none" aria-hidden="true">
                <input type="password" style="display:none" aria-hidden="true">
                <div class="mobile-search-row">
                    <input type="search" id="mobile-search-input" placeholder="<?php echo $currentLang === 'en' ? 'Search...' : '검색...'; ?>" autocomplete="off" name="q_mobile_<?= time() ?>" readonly onfocus="this.removeAttribute('readonly')">
                    <button id="mobile-search-submit">🔍</button>
                    <button id="mobile-filter-toggle" title="<?php echo $currentLang === 'en' ? 'Filter' : '필터'; ?>">⚙️</button>
                    <button id="mobile-search-close">✕</button>
                </div>
                <div class="search-hint mobile-search-hint"><?php echo $currentLang === 'en' ? '🔍 Integrated: fast (indexed) | ⚙️ Filter: real-time (no index needed)' : '🔍 통합검색: 빠름 (인덱스) | ⚙️ 필터 검색: 실시간 (인덱스 불필요)'; ?></div>
            </div>
            
            <!-- 본문 -->
            <div class="main-content">
                <!-- 사이드바 -->
                <aside class="sidebar">
                    <div class="sidebar-close-wrap">
                        <button class="sidebar-close-btn" onclick="$('.sidebar').removeClass('open'); document.body.classList.remove('sidebar-open'); App.toggleSidebarOverlay();">✕</button>
                    </div>
                    <div class="sidebar-section sidebar-main">
                        <h3><?php _e('storage'); ?></h3>
                        <ul id="storage-list" class="storage-list"></ul>
                        
                        <!-- 게시판 섹션 -->
                        <div id="board-sidebar-section" style="display:none;">
                        <div class="sidebar-divider"></div>
                        <h3 class="section-toggle" data-target="board-list-sidebar">
                            <span class="toggle-icon">−</span> 📋 <?php echo $currentLang === 'en' ? 'Boards' : '게시판'; ?>
                        </h3>
                        <ul class="menu-list collapsible" id="board-list-sidebar">
                        </ul>
                        </div>

                        <div class="sidebar-divider"></div>
                        <ul class="menu-list">
                            <li><a href="#" id="menu-my-shares">🔗 <?php echo $currentLang === 'en' ? 'My Shares' : '내 공유 링크'; ?><span id="my-shares-badge" class="menu-badge" style="display:none;">0</span></a></li>
                            <li><a href="#" id="menu-my-ishares">👤 <?php echo $currentLang === 'en' ? 'My User Shares' : '내 사용자 공유'; ?><span id="my-ishares-badge" class="menu-badge" style="display:none;">0</span></a></li>
                            <li><a href="#" id="menu-shared-with-me">📨 <?php echo $currentLang === 'en' ? 'Shared with Me' : '나에게 공유됨'; ?><span id="shared-with-me-badge" class="menu-badge" style="display:none;">0</span></a></li>
                            <li><a href="#" id="menu-my-trash">🗑️ <?php echo $currentLang === 'en' ? 'My Trash' : '내 휴지통'; ?></a></li>
                            <li id="menu-webdav-item" style="display:none;"><a href="#" id="menu-webdav">🌐 <?php echo $currentLang === 'en' ? 'WebDAV Guide' : 'WebDAV 접속 안내'; ?></a></li>
                        </ul>
                        
                        <!-- 즐겨찾기 섹션 -->
                        <div class="sidebar-divider"></div>
                        <h3 class="section-toggle" data-target="favorites-list">
                            <span class="toggle-icon">+</span> ⭐ <?php _e('favorites'); ?>
                        </h3>
                        <ul class="menu-list collapsible" id="favorites-list" style="display:none;">
                            <li class="empty-message" style="color:#999;font-size:12px;padding:5px 10px;"><?php _e('no_favorites'); ?></li>
                        </ul>
                        
                        <!-- 최근 파일 섹션 -->
                        <div class="sidebar-divider"></div>
                        <h3 class="section-toggle" data-target="recent-files-list">
                            <span class="toggle-icon">+</span> 🕐 <?php _e('recent_files'); ?>
                        </h3>
                        <ul class="menu-list collapsible" id="recent-files-list" style="display:none;">
                            <li class="empty-message" style="color:#999;font-size:12px;padding:5px 10px;"><?php _e('no_recent_files'); ?></li>
                        </ul>
                        
                        <div id="admin-section" style="display:none;">
                            <div class="sidebar-divider"></div>
                            <h3 class="section-toggle" data-target="admin-menu-list">
                                <span class="toggle-icon">+</span> <?php _e('administration'); ?>
                            </h3>
                            <ul class="menu-list collapsible" id="admin-menu-list" style="display:none;">
                                <li class="menu-group-label"><?php echo $currentLang === 'en' ? 'Users/Permissions' : '사용자/권한'; ?></li>
                                <li><a href="#" id="menu-users">👥 <?php _e('user_management'); ?></a></li>
                                <li><a href="#" id="menu-roles">🏷️ <?php echo $currentLang === 'en' ? 'Role Management' : '역할 관리'; ?></a></li>
                                <li><a href="#" id="menu-deleted-users">👻 <?php echo $currentLang === 'en' ? 'Deleted Users' : '탈퇴/삭제 기록'; ?></a></li>
                                <li class="menu-group-label"><?php echo $currentLang === 'en' ? 'Storage/Files' : '스토리지/파일'; ?></li>
                                <li><a href="#" id="menu-storages">💾 <?php _e('storage_management'); ?></a></li>
                                <li><a href="#" id="menu-shares">🔗 <?php echo $currentLang === 'en' ? 'Share Management' : '공유 관리'; ?><span id="all-shares-badge" class="menu-badge" style="display:none;">0</span></a></li>
                                <li><a href="#" id="menu-all-ishares">👤 <?php echo $currentLang === 'en' ? 'User Share Management' : '사용자 공유 관리'; ?><span id="all-ishares-badge" class="menu-badge" style="display:none;">0</span></a></li>
                                <li><a href="#" id="menu-trash">🗑️ <?php echo $currentLang === 'en' ? 'All Trash' : '전체 휴지통'; ?></a></li>
                                <li><a href="#" id="menu-bulk-delete">🧹 <?php echo $currentLang === 'en' ? 'Bulk Delete' : '조건부 삭제'; ?></a></li>
                                <li class="menu-group-label"><?php echo $currentLang === 'en' ? 'Logs/History' : '로그/기록'; ?></li>
                                <li><a href="#" id="menu-all-logins">📋 <?php echo $currentLang === 'en' ? 'Login History' : '로그인 기록'; ?></a></li>
                                <li><a href="#" id="menu-activity-logs">📜 <?php echo $currentLang === 'en' ? 'Activity Logs' : '활동 로그'; ?></a></li>
                                <li><a href="#" id="menu-access-stats">📊 <?php echo $currentLang === 'en' ? 'Access Stats' : '접속 통계'; ?></a></li>
                                <li><a href="#" id="menu-search-index">🔍 <?php _e('search_index_settings'); ?></a></li>
                                <li class="menu-group-label"><?php echo $currentLang === 'en' ? 'System' : '시스템'; ?></li>
                                <li><a href="#" id="menu-qos"><?php _e('speed_limit'); ?></a></li>
                                <li><a href="#" id="menu-security">🔒 <?php echo $currentLang === 'en' ? 'IP/Country Blocking' : 'IP/국가 차단 설정'; ?></a></li>
                                <li><a href="#" id="menu-sso">🔐 <?php echo $currentLang === 'en' ? 'SSO Settings' : 'SSO 인증 설정'; ?></a></li>
                                <li><a href="#" id="menu-antivirus">🛡️ <?php echo $currentLang === 'en' ? 'Antivirus Settings' : '백신 설정'; ?></a></li>
                                <li><a href="#" id="menu-ransomware">🔐 <?php echo $currentLang === 'en' ? 'Ransomware Protection' : '랜섬웨어 방지'; ?></a></li>
                                <li><a href="#" id="menu-upload-settings">📤 <?php echo $currentLang === 'en' ? 'Upload Settings' : '업로드 설정'; ?></a></li>
                                <li><a href="#" id="menu-notice">📢 <?php _e('notice_settings'); ?></a></li>
                                <li><a href="#" id="menu-boards">📋 <?php echo $currentLang === 'en' ? 'Board Management' : '게시판 관리'; ?></a></li>
                                <li><a href="#" id="menu-terms">📜 <?php echo $currentLang === 'en' ? 'Terms of Service' : '가입시 이용약관'; ?></a></li>
                                <li><a href="#" id="menu-system-settings">🔧 <?php _e('system_settings'); ?></a></li>
                                <li><a href="#" id="menu-icon-manager">🎨 <?php echo $currentLang === 'en' ? 'Extension Icon Management' : '확장자 아이콘 관리'; ?></a></li>
                                <li><a href="#" id="menu-system-info">📊 <?php echo $currentLang === 'en' ? 'System Info' : '시스템 정보'; ?></a></li>
                            </ul>
                        </div>
                    </div>
                </aside>
                
                <!-- 파일 영역 -->
                <main class="file-area">
                    <!-- 검색 필터 영역 -->
                    <div id="search-filters" class="search-filters" style="display:none;">
                        <div class="filter-row">
                            <div class="filter-item">
                                <label><?php echo $currentLang === 'en' ? 'Storage' : '스토리지'; ?></label>
                                <select id="filter-storage">
                                    <option value="0"><?php echo $currentLang === 'en' ? 'All Storages' : '전체 스토리지'; ?></option>
                                </select>
                            </div>
                            <div class="filter-item">
                                <label><?php echo $currentLang === 'en' ? 'File Type' : '파일 유형'; ?></label>
                                <select id="filter-type">
                                    <option value="all"><?php echo $currentLang === 'en' ? 'All' : '전체'; ?></option>
                                    <option value="image"><?php echo $currentLang === 'en' ? 'Images' : '이미지'; ?></option>
                                    <option value="video"><?php echo $currentLang === 'en' ? 'Videos' : '동영상'; ?></option>
                                    <option value="audio"><?php echo $currentLang === 'en' ? 'Audio' : '음악'; ?></option>
                                    <option value="document"><?php echo $currentLang === 'en' ? 'Documents' : '문서'; ?></option>
                                    <option value="archive"><?php echo $currentLang === 'en' ? 'Archives' : '압축파일'; ?></option>
                                </select>
                            </div>
                            <div class="filter-item">
                                <label><?php echo $currentLang === 'en' ? 'Date' : '날짜'; ?></label>
                                <input type="date" id="filter-date-from" lang="<?php echo $currentLang; ?>" placeholder="<?php echo $currentLang === 'en' ? 'Start' : '시작'; ?>">
                                <span>~</span>
                                <input type="date" id="filter-date-to" lang="<?php echo $currentLang; ?>" placeholder="<?php echo $currentLang === 'en' ? 'End' : '끝'; ?>">
                            </div>
                            <div class="filter-item">
                                <label><?php echo $currentLang === 'en' ? 'Size (MB)' : '크기 (MB)'; ?></label>
                                <input type="number" id="filter-size-min" placeholder="<?php echo $currentLang === 'en' ? 'Min' : '최소'; ?>" min="0" style="width:70px;">
                                <span>~</span>
                                <input type="number" id="filter-size-max" placeholder="<?php echo $currentLang === 'en' ? 'Max' : '최대'; ?>" min="0" style="width:70px;">
                            </div>
                            <div class="filter-actions">
                                <button id="btn-apply-filter" class="btn btn-sm btn-primary"><?php echo $currentLang === 'en' ? 'Apply' : '적용'; ?></button>
                                <button id="btn-reset-filter" class="btn btn-sm"><?php echo $currentLang === 'en' ? 'Reset' : '초기화'; ?></button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 검색 결과 헤더 (검색 모드일 때만 표시) -->
                    <div id="search-result-header" class="search-result-header" style="display:none;">
                        <div class="search-info">
                            <span class="search-query"></span>
                            <span class="search-count"></span>
                        </div>
                        <button id="btn-exit-search" class="btn btn-sm"><?php echo $currentLang === 'en' ? '✕ Exit Search' : '✕ 검색 종료'; ?></button>
                    </div>
                    
                    <!-- 모바일 현재 위치 -->
                    <div id="mobile-location-bar" class="mobile-location-bar"></div>
                    
                    <!-- 툴바 -->
                    <div class="toolbar">
                        <div class="toolbar-left">
                            <div class="nav-buttons" style="display:inline-flex;gap:1px;align-items:center;margin-right:4px;">
                                <button id="btn-nav-back" class="btn-icon nav-btn" title="<?php echo $currentLang === 'en' ? 'Back' : '뒤로'; ?>" disabled><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg></button>
                                <button id="btn-nav-forward" class="btn-icon nav-btn" title="<?php echo $currentLang === 'en' ? 'Forward' : '앞으로'; ?>" disabled><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><polyline points="12 5 19 12 12 19"/></svg></button>
                                <button id="btn-nav-up" class="btn-icon nav-btn" title="<?php echo $currentLang === 'en' ? 'Up' : '상위 폴더'; ?>" disabled><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><polyline points="5 12 12 5 19 12"/></svg></button>
                            </div>
                            <label class="select-all-wrap" title="<?php echo $currentLang === 'en' ? 'Select All' : '전체 선택'; ?>">
                                <input type="checkbox" id="select-all">
                                <span><?php echo $currentLang === 'en' ? 'All' : '전체'; ?></span>
                            </label>
                            <span id="selection-info" class="selection-info" style="display:none;"></span>
                            <div id="breadcrumb" class="breadcrumb"></div>
                        </div>
                        <div class="toolbar-center">
                            <button id="btn-paste" class="btn btn-primary" style="display:none;">📋 <?php _e('paste'); ?></button>
                            <button id="btn-download-selected" class="btn btn-primary" style="display:none;">📥 <?php echo $currentLang === 'en' ? 'Download' : '다운로드'; ?></button>
                            <button id="btn-delete-selected" class="btn btn-danger" style="display:none;">🗑️ <?php echo $currentLang === 'en' ? 'Delete Selected' : '선택 삭제'; ?></button>
                        </div>
                        <div class="toolbar-right">
                            <div class="action-dropdown" id="toolbar-action-dropdown">
                                <button id="btn-toolbar-action" class="btn">⚡ <?php echo $currentLang === 'en' ? 'Actions' : '작업'; ?> ▾</button>
                                <div id="toolbar-action-menu" class="toolbar-action-menu">
                                    <div class="action-option" data-action="open">📂 <?php echo $currentLang === 'en' ? 'Open' : '열기'; ?></div>
                                    <div class="action-option" data-action="preview">👁️ <?php echo $currentLang === 'en' ? 'Preview' : '미리보기'; ?></div>
                                    <div class="action-option" data-action="onlyoffice">📝 <?php echo $currentLang === 'en' ? 'Edit with OnlyOffice' : 'OnlyOffice로 편집'; ?></div>
                                    <div class="action-option" data-action="rhwp-edit">📝 <?php echo $currentLang === 'en' ? 'Edit with rHWP' : 'rHWP로 편집'; ?></div>
                                    <div class="action-option" data-action="download">⬇️ <?php _e('download'); ?></div>
                                    <div class="action-option" data-action="save-as">💾 <?php echo $currentLang === 'en' ? 'Save As' : '다른 이름으로 저장'; ?></div>
                                    <div class="action-option" data-action="share">🔗 <?php _e('share'); ?></div>
                                    <div class="action-option" data-action="internal_share">👤 <?php echo $currentLang === 'en' ? 'Share with User' : '사용자에게 공유'; ?></div>
                                    <div class="action-divider"></div>
                                    <div class="action-option" data-action="favorite-add">⭐ <?php echo $currentLang === 'en' ? 'Add to Favorites' : '즐겨찾기 추가'; ?></div>
                                    <div class="action-option" data-action="favorite-remove">☆ <?php echo $currentLang === 'en' ? 'Remove from Favorites' : '즐겨찾기 제거'; ?></div>
                                    <div class="action-option" data-action="file-lock">🔒 <?php echo $currentLang === 'en' ? 'Lock' : '잠금'; ?></div>
                                    <div class="action-option" data-action="file-unlock">🔓 <?php echo $currentLang === 'en' ? 'Unlock' : '잠금 해제'; ?></div>
                                    <div class="action-divider"></div>
                                    <div class="action-option" data-action="copy">📋 <?php _e('copy'); ?></div>
                                    <div class="action-option" data-action="move">✂️ <?php _e('cut'); ?></div>
                                    <div class="action-option" data-action="paste">📥 <?php echo $currentLang === 'en' ? 'Paste Here' : '여기에 붙여넣기'; ?></div>
                                    <div class="action-divider"></div>
                                    <div class="action-option" data-action="extract">📦 <?php echo $currentLang === 'en' ? 'Extract' : '압축 해제'; ?></div>
                                    <div class="action-option" data-action="compress">🗜️ <?php echo $currentLang === 'en' ? 'Compress (ZIP)' : '압축 (ZIP)'; ?></div>
                                    <div class="action-option" data-action="convert-h264">🎬 <?php echo $currentLang === 'en' ? 'Convert to H264/MP4' : 'H264/MP4로 변환'; ?></div>
                                    <div class="action-option" data-action="convert-to-vault">🔒 <?php echo $currentLang === 'en' ? 'Encrypt This Folder' : '이 폴더 암호화'; ?></div>
                                    <div class="action-divider"></div>
                                    <div class="action-option" data-action="rename">✏️ <?php _e('rename'); ?></div>
                                    <div class="action-option" data-action="versions">📜 <?php echo $currentLang === 'en' ? 'Previous Versions' : '이전 버전'; ?></div>
                                    <div class="action-option" data-action="info">ℹ️ <?php _e('info'); ?></div>
                                    <div class="action-divider"></div>
                                    <div class="action-option danger" data-action="delete">🗑️ <?php _e('delete'); ?></div>
                                </div>
                            </div>
                            <div class="upload-dropdown">
                                <div class="upload-drag-hint"><?php echo $currentLang === 'en' ? '💡 Drag & Drop supported (files/folders)' : '💡 드래그 앤 드롭 (파일/폴더) 지원'; ?></div>
                                <button id="btn-upload" class="btn btn-primary">📤 <?php _e('upload'); ?> ▾</button>
                                <div id="upload-menu" class="upload-menu">
                                    <div class="upload-option" data-type="file">📄 <?php echo $currentLang === 'en' ? 'Upload Files' : '파일 업로드'; ?></div>
                                    <div class="upload-option" data-type="folder">📁 <?php echo $currentLang === 'en' ? 'Upload Folder' : '폴더 업로드'; ?></div>
                                </div>
                            </div>
                            <button id="btn-new-folder" class="btn">📁 <?php _e('new_folder'); ?></button>
                            <button id="btn-index-sync" class="btn" style="display:none;" title="<?php echo $currentLang === 'en' ? 'Sync Index' : '인덱스 동기화'; ?>">🔄 <?php echo $currentLang === 'en' ? 'Sync' : '동기화'; ?></button>
                            <div class="sort-dropdown">
                                <button id="btn-sort" class="btn-icon" title="<?php _e('sort_by'); ?>">🔀</button>
                                <div id="sort-menu" class="sort-menu">
                                    <div class="sort-option active" data-sort="name">📝 <?php echo $currentLang === 'en' ? 'Name' : '이름'; ?> <span class="sort-arrow">▲</span></div>
                                    <div class="sort-option" data-sort="date">📅 <?php echo $currentLang === 'en' ? 'Date' : '날짜'; ?> <span class="sort-arrow"></span></div>
                                    <div class="sort-option" data-sort="size">📊 <?php echo $currentLang === 'en' ? 'Size' : '크기'; ?> <span class="sort-arrow"></span></div>
                                    <div class="sort-option" data-sort="type">📂 <?php echo $currentLang === 'en' ? 'Type' : '유형'; ?> <span class="sort-arrow"></span></div>
                                </div>
                            </div>
                            <button id="btn-view-grid" class="btn-icon active" title="<?php _e('grid_view'); ?>">▦</button>
                            <button id="btn-view-list" class="btn-icon" title="<?php _e('list_view'); ?>">☰</button>
                        </div>
                    </div>
                    
                    <!-- 리스트 뷰 컬럼 헤더 -->
                    <div id="file-list-header" class="file-list-header" style="display:none;">
                        <div class="fh-check" style="display:none;"></div>
                        <div class="fh-icon"></div>
                        <div class="col-header file-name-col" data-sort="name" data-col="name"><?php echo $currentLang === 'en' ? 'Name' : '이름'; ?><span class="sort-indicator"></span></div>
                        <div class="col-header file-date-col" data-sort="date" data-col="date"><?php echo $currentLang === 'en' ? 'Date modified' : '수정한 날짜'; ?><span class="sort-indicator"></span><div class="col-resize" data-col="date"></div></div>
                        <div class="col-header file-type-col" data-sort="type" data-col="type"><?php echo $currentLang === 'en' ? 'Type' : '유형'; ?><span class="sort-indicator"></span><div class="col-resize" data-col="type"></div></div>
                        <div class="col-header file-size-col" data-sort="size" data-col="size"><?php echo $currentLang === 'en' ? 'Size' : '크기'; ?><span class="sort-indicator"></span></div>
                    </div>
                    
                    <!-- 파일 목록 -->
                    <div id="file-list" class="file-list grid-view">
                        <div class="empty-msg"><?php echo $currentLang === 'en' ? 'Loading...' : '로딩 중...'; ?></div>
                    </div>
                    
                    <!-- 검색 페이지네이션 -->
                    <div id="search-pagination" class="search-pagination" style="display:none;"></div>
                    
                    <!-- 게시판 인라인 뷰 (파일 영역 전환) -->
                    <div id="board-inline-view" style="display:none;">
                        <!-- 게시판 툴바 -->
                        <div class="toolbar" id="board-inline-toolbar">
                            <div class="toolbar-left">
                                <button class="btn-icon" onclick="App.boardGoBack()" title="<?php echo $currentLang === 'en' ? 'Back' : '뒤로'; ?>">⬅️</button>
                                <div id="board-inline-breadcrumb" class="breadcrumb"></div>
                            </div>
                            <div class="toolbar-center">
                                <span id="board-inline-count" style="color:#999;font-size:13px;"></span>
                            </div>
                            <div class="toolbar-right">
                                <div style="position:relative;display:inline-flex;align-items:center;">
                                    <span style="position:absolute;left:8px;color:#aaa;font-size:13px;pointer-events:none;">🔍</span>
                                    <input type="text" id="board-inline-search" placeholder="<?php echo $currentLang === 'en' ? 'Search...' : '검색...'; ?>" style="width:180px;height:32px;font-size:12px;padding:6px 10px 6px 28px;border:1px solid var(--theme-border,var(--border,#ddd));border-radius:16px;outline:none;background:var(--theme-hover,var(--bg-secondary,#f8f9fa));color:var(--theme-text,#333);transition:border-color 0.2s,width 0.2s;" onfocus="this.style.borderColor='var(--theme-primary,var(--primary,#667eea))';this.style.width='220px'" onblur="this.style.borderColor='';this.style.width='180px'">
                                </div>
                                <button id="btn-board-inline-write" class="btn btn-primary btn-board-write" onclick="App.showBoardWriteForm()" style="display:none;">✏️ <?php echo $currentLang === 'en' ? 'Write' : '글쓰기'; ?></button>
                            </div>
                        </div>
                        <!-- 게시글 목록 (스크롤 영역) -->
                        <div id="board-list-scroll-wrap" style="flex:1;overflow-y:auto;min-height:0;">
                        <div id="board-inline-list" class="file-list" style="display:block;"></div>
                        <div id="board-inline-empty" style="display:none;text-align:center;padding:60px 20px;color:var(--theme-text-muted,#999);">
                            <div style="font-size:48px;margin-bottom:10px;">📝</div>
                            <p><?php echo $currentLang === 'en' ? 'No posts yet' : '게시글이 없습니다'; ?></p>
                        </div>
                        <!-- 게시글 읽기 -->
                        <div id="board-inline-detail" style="display:none;"></div>
                        <!-- 게시글 작성/수정 -->
                        <div id="board-inline-form" style="display:none;padding:16px;">
                            <input type="hidden" id="board-post-edit-id">
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Title' : '제목'; ?></label>
                                <input type="text" id="board-post-title" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Enter title' : '제목을 입력하세요'; ?>">
                            </div>
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Content' : '내용'; ?></label>
                                <textarea id="board-post-editor" name="content" rows="10"></textarea>
                            </div>
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Attachments' : '첨부파일'; ?></label>
                                <div id="board-post-attachments" style="margin-bottom:8px;"></div>
                                <div style="display:flex;gap:8px;align-items:stretch;">
                                    <div id="board-file-drop" style="flex:1;padding:16px;border:2px dashed var(--border-color,#ddd);border-radius:8px;text-align:center;color:#999;font-size:13px;cursor:pointer;" onclick="document.getElementById('board-file-input').click();">
                                        📎 <?php echo $currentLang === 'en' ? 'Click or drag files' : '클릭 또는 파일 끌어놓기'; ?>
                                    </div>
                                    <button type="button" class="btn" onclick="App.openStorageFilePicker()" style="padding:8px 14px;font-size:12px;white-space:nowrap;border-radius:8px;border:2px dashed var(--border-color,#ddd);background:none;color:#999;cursor:pointer;">
                                        📂 <?php echo $currentLang === 'en' ? 'From Storage' : '스토리지에서'; ?>
                                    </button>
                                </div>
                                <input type="file" id="board-file-input" multiple style="display:none;" onchange="App.uploadBoardFiles(this.files)">
                                <div id="board-upload-progress" style="display:none;margin-top:8px;">
                                    <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:#666;">
                                        <div class="spinner-small"></div>
                                        <span id="board-upload-progress-text"><?php echo $currentLang === 'en' ? 'Uploading...' : '업로드 중...'; ?></span>
                                    </div>
                                    <div style="margin-top:4px;height:4px;background:#e0e0e0;border-radius:2px;overflow:hidden;">
                                        <div id="board-upload-progress-bar" style="width:0%;height:100%;background:var(--primary,#667eea);border-radius:2px;transition:width 0.3s;"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group" style="display:flex;align-items:center;gap:16px;">
                                <input type="hidden" id="board-post-pinned">
                                <label style="display:inline-flex !important;align-items:center;gap:6px;cursor:pointer;font-size:14px;"><input type="checkbox" id="board-post-notice" style="margin:0;width:auto !important;" onchange="document.getElementById('board-notice-color-wrap').style.display=this.checked?'inline-flex':'none'"> <?php echo $currentLang === 'en' ? '🔔 Notice' : '🔔 공지'; ?></label>
                                <span id="board-notice-color-wrap" style="display:none;align-items:center;gap:6px;margin-left:12px;">
                                    <label style="font-size:13px;color:var(--theme-text-muted,#888);"><?php echo $currentLang === 'en' ? 'Color' : '색상'; ?></label>
                                    <input type="color" id="board-notice-color" value="#e74c3c" style="width:32px;height:26px;padding:0;border:1px solid var(--border-color,#ddd);border-radius:4px;cursor:pointer;">
                                </span>
                            </div>
                            <div style="text-align:right;margin-top:12px;">
                                <button class="btn btn-secondary" onclick="App.cancelBoardForm()"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                                <button id="btn-board-save" class="btn btn-primary" onclick="App.saveBoardPost()"><?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                            </div>
                        </div>
                        <!-- 페이지네이션 -->
                        <div id="board-inline-pagination" style="text-align:center;"></div>
                        </div><!-- /board-list-scroll-wrap -->
                    </div>
                    
                    <!-- 전송 진행 (멀티 업로드 지원) -->
                    <div id="transfer-progress" class="transfer-progress" style="display:none;">
                        <div class="progress-header">
                            <span id="transfer-title">📤 <?php _e('uploading'); ?>...</span>
                            <button id="transfer-cancel" class="btn-icon" title="<?php _e('cancel'); ?>">✕</button>
                        </div>
                        <div class="vpn-warning" id="vpn-warning" style="background:#fff3cd;color:#856404;padding:6px 10px;border-radius:4px;font-size:12px;margin-bottom:8px;">
                            ⚠️ <?php echo $currentLang === 'en' ? 'Upload speed may be significantly slower when using VPN. If speed is slow, please disconnect VPN.' : 'VPN 사용 시 업로드 속도가 현저히 느려질 수 있습니다. 속도가 느리다면 VPN을 종료해주세요.'; ?>
                        </div>
                        
                        <!-- 멀티 업로드 리스트 -->
                        <div id="upload-sessions-list" class="upload-sessions-list" style="display:none;"></div>
                        
                        <!-- 단일 전송용 (다운로드/복사/이동/삭제) -->
                        <div id="single-transfer-area">
                            <div id="transfer-path" style="display:none;font-size:12px;color:#555;margin-bottom:4px;line-height:1.5;">
                                <div id="transfer-path-from" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                                <div id="transfer-path-to" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                            </div>
                            <div class="progress-count" id="transfer-count-wrap" style="display:none;">
                                <span id="transfer-count"></span>
                            </div>
                            <div class="progress-info">
                                <span id="transfer-filename"></span>
                            </div>
                            <div class="progress-bar">
                                <div id="progress-fill" class="progress-fill"></div>
                            </div>
                            <div class="progress-detail">
                                <span id="transfer-percent">0%</span>
                                <span id="transfer-speed"></span>
                            </div>
                            <div class="progress-stats">
                                <span id="transfer-size"></span>
                                <span id="transfer-eta"></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 이전 버전 호환 (숨김) -->
                    <div id="upload-progress" style="display:none;"></div>
                </main>
            </div>
            
            <!-- 하단 상태바 -->
            <footer id="status-bar" class="status-bar">
                <div class="status-left">
                    <span id="status-selection"></span>
                </div>
                <div id="status-copyright" class="status-copyright" style="display:none;"></div>
                <div class="status-right">
                </div>
            </footer>
        </div>
        
        <!-- 모달들 -->
        <div id="modal-overlay" class="modal-overlay" style="display:none;">
            <!-- 새 폴더 모달 -->
            <div id="modal-new-folder" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? 'New Folder' : '새 폴더'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="text" id="new-folder-name" placeholder="<?php echo $currentLang === 'en' ? 'Folder name' : '폴더 이름'; ?>">
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-create-folder"><?php echo $currentLang === 'en' ? 'Create' : '생성'; ?></button>
                </div>
            </div>
            
            <!-- E2E 암호화 폴더 생성 모달 -->
            <div id="modal-vault-create" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>🔒 <?php echo $currentLang === 'en' ? 'Create Encrypted Folder' : '암호화 폴더 생성'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Folder Name' : '폴더 이름'; ?></label>
                        <input type="text" id="vault-create-name" placeholder="<?php echo $currentLang === 'en' ? 'Enter folder name' : '폴더 이름 입력'; ?>">
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Password' : '비밀번호'; ?></label>
                        <input type="password" id="vault-create-password" placeholder="<?php echo $currentLang === 'en' ? 'Set encryption password' : '암호화 비밀번호 설정'; ?>">
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Confirm Password' : '비밀번호 확인'; ?></label>
                        <input type="password" id="vault-create-password2" placeholder="<?php echo $currentLang === 'en' ? 'Re-enter password' : '비밀번호 재입력'; ?>">
                    </div>
                    <div style="padding:8px 12px;background:var(--bg-warning,#fff3cd);border-radius:6px;font-size:0.85em;color:var(--text-warning,#856404);margin-top:8px;">
                        ⚠️ <?php echo $currentLang === 'en' ? 'WARNING: If you forget your password, files cannot be recovered. The server does not store your password.' : '경고: 비밀번호를 잊으면 파일을 복구할 수 없습니다. 서버는 비밀번호를 저장하지 않습니다.'; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-vault-create">🔒 <?php echo $currentLang === 'en' ? 'Create' : '생성'; ?></button>
                </div>
            </div>
            
            <!-- E2E Vault 비밀번호 입력 모달 -->
            <div id="modal-vault-unlock" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>🔐 <?php echo $currentLang === 'en' ? 'Unlock Encrypted Folder' : '암호화 폴더 잠금 해제'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <p id="vault-unlock-folder-name" style="font-weight:600;margin-bottom:12px;"></p>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Password' : '비밀번호'; ?></label>
                        <input type="password" id="vault-unlock-password" placeholder="<?php echo $currentLang === 'en' ? 'Enter password' : '비밀번호 입력'; ?>">
                    </div>
                    <div id="vault-unlock-error" style="display:none;padding:8px 12px;background:var(--bg-danger,#f8d7da);border-radius:6px;font-size:0.85em;color:var(--text-danger,#721c24);margin-top:8px;"></div>
                    <div id="vault-unlock-progress" style="display:none;margin-top:12px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="spinner-small"></div>
                            <span><?php echo $currentLang === 'en' ? 'Deriving key...' : '키 파생 중...'; ?></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-vault-unlock">🔓 <?php echo $currentLang === 'en' ? 'Unlock' : '잠금 해제'; ?></button>
                </div>
            </div>
            
            <!-- 스토리지 추가 모달 -->
            <div id="modal-add-storage" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2 id="storage-modal-title"><?php echo $currentLang === 'en' ? 'Add Storage' : '스토리지 추가'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="storage-id">
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Name' : '이름'; ?></label>
                        <input type="text" id="storage-name" placeholder="<?php echo $currentLang === 'en' ? 'My Drive' : '내 드라이브'; ?>">
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Type' : '유형'; ?></label>
                        <select id="storage-type">
                            <option value="local"><?php echo $currentLang === 'en' ? '📁 Local/Network Path' : '📁 로컬/네트워크 경로'; ?></option>
                            <option value="smb"><?php echo $currentLang === 'en' ? '🖥️ CIFS/SMB Share' : '🖥️ CIFS/SMB 공유'; ?></option>
                            <option value="ftp">📡 FTP</option>
                            <option value="sftp">🔒 SFTP</option>
                            <option value="webdav">🌐 WebDAV</option>
                            <option value="s3">☁️ Amazon S3 / <?php echo $currentLang === 'en' ? 'Compatible' : '호환'; ?></option>
                            <option value="shared" style="display:none;"><?php echo $currentLang === 'en' ? '📂 Shared Folder' : '📂 공유 폴더'; ?></option>
                        </select>
                    </div>
                    
                    <!-- 로컬 옵션 -->
                    <div id="storage-local-options" class="storage-options">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Path' : '경로'; ?></label>
                            <input type="text" id="storage-path" placeholder="<?php echo $currentLang === 'en' ? 'D:\\Files or /data/nas' : 'D:\\Files 또는 /data/nas'; ?>">
                        </div>
                        <details style="margin-top:4px;margin-bottom:12px;font-size:12px;color:#555;">
                            <summary style="cursor:pointer;color:#2980b9;font-weight:500;">📦 Docker <?php echo $currentLang === 'en' ? 'volume mount guide' : '볼륨 마운트 가이드'; ?></summary>
                            <div style="background:#f5f5f5;padding:10px 12px;border-radius:6px;margin-top:6px;font-size:12px;line-height:1.7;color:#333;">
                                
                                <?php if ($currentLang === 'en'): ?>
                                
                                <div style="margin-bottom:10px;">
                                    <b>📌 How it works</b><br>
                                    Docker containers can't directly access host files. You need to <b>mount</b> host folders into the container via <code>docker-compose.yml</code>, then enter the <b>container path</b> here.
                                </div>
                                
                                <div style="margin-bottom:8px;"><b>Example 1: Host folder</b></div>
                                <div style="background:#e8f4fd;padding:8px;border-radius:4px;margin-bottom:4px;font-size:11px;">
                                    If you add <code>volumes: - E:/movies:/mnt/storage/movies</code> in docker-compose.yml,<br>
                                    enter <b>/mnt/storage/movies</b> here → <code>E:\movies</code> becomes an external storage.
                                </div>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:8px;border-radius:4px;overflow-x:auto;margin:4px 0 12px;font-family:monospace;font-size:11px;"># docker-compose.yml
services:
  filestation:
    volumes:
      - E:/movies:/mnt/storage/movies      # Windows host folder
      - /home/user/docs:/mnt/storage/docs  # Linux host folder
      - D:/photos:/mnt/storage/photos      # Another drive</pre>
                                
                                <div style="margin-bottom:8px;"><b>Example 2: NAS (Synology, etc.) via SMB</b></div>
                                <div style="background:#e8f4fd;padding:8px;border-radius:4px;margin-bottom:4px;font-size:11px;">
                                    ① Mount the NAS share on host first<br>
                                    ② Add the mount path to docker-compose.yml<br>
                                    ③ Enter the container path here
                                </div>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:8px;border-radius:4px;overflow-x:auto;margin:4px 0 6px;font-family:monospace;font-size:11px;"># Step 1: Mount NAS on host
sudo mount -t cifs //192.168.1.50/video /mnt/nas_video \
  -o username=admin,password=mypass,vers=3.0

# Step 2: docker-compose.yml
services:
  filestation:
    volumes:
      - /mnt/nas_video:/mnt/storage/nas_video

# Step 3: Enter /mnt/storage/nas_video here</pre>
                                
                                <div style="margin-top:6px;margin-bottom:8px;"><b>Auto mount at boot (/etc/fstab)</b></div>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:8px;border-radius:4px;overflow-x:auto;margin:4px 0;font-family:monospace;font-size:11px;"># /etc/fstab — auto mount on boot
//192.168.1.50/video /mnt/nas_video cifs \
  credentials=/etc/smbcredentials,vers=3.0,_netdev 0 0

# /etc/smbcredentials (chmod 600)
username=admin
password=mypass</pre>

                                <div style="margin-top:10px;background:#fff3cd;padding:8px;border-radius:4px;font-size:11px;">
                                    ⚠️ After changing docker-compose.yml, run <code>docker compose down && docker compose up -d</code> to apply.
                                </div>
                                
                                <?php else: ?>
                                
                                <div style="margin-bottom:10px;">
                                    <b>📌 작동 원리</b><br>
                                    Docker 컨테이너는 호스트 파일에 직접 접근할 수 없습니다. <code>docker-compose.yml</code>에서 호스트 폴더를 컨테이너에 <b>마운트</b>한 후, 위 경로란에 <b>컨테이너 경로</b>를 입력하세요.
                                </div>
                                
                                <div style="margin-bottom:8px;"><b>예시 1: 호스트 폴더 연결</b></div>
                                <div style="background:#e8f4fd;padding:8px;border-radius:4px;margin-bottom:4px;font-size:11px;">
                                    docker-compose.yml에 <code>volumes: - E:/movies:/mnt/storage/movies</code> 추가 후,<br>
                                    위 경로란에 <b>/mnt/storage/movies</b> 입력 → <code>E:\movies</code> 폴더가 외부 스토리지로 추가됩니다.
                                </div>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:8px;border-radius:4px;overflow-x:auto;margin:4px 0 12px;font-family:monospace;font-size:11px;"># docker-compose.yml
services:
  filestation:
    volumes:
      - E:/movies:/mnt/storage/movies      # 윈도우 호스트 폴더
      - /home/user/docs:/mnt/storage/docs  # 리눅스 호스트 폴더
      - D:/photos:/mnt/storage/photos      # 다른 드라이브</pre>
                                
                                <div style="margin-bottom:8px;"><b>예시 2: NAS (시놀로지 등) SMB 연결</b></div>
                                <div style="background:#e8f4fd;padding:8px;border-radius:4px;margin-bottom:4px;font-size:11px;">
                                    ① 호스트에서 NAS 공유 폴더를 먼저 마운트<br>
                                    ② docker-compose.yml에 마운트 경로 추가<br>
                                    ③ 위 경로란에 컨테이너 경로 입력
                                </div>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:8px;border-radius:4px;overflow-x:auto;margin:4px 0 6px;font-family:monospace;font-size:11px;"># 1단계: 호스트에서 NAS 마운트
sudo mount -t cifs //192.168.1.50/video /mnt/nas_video \
  -o username=admin,password=mypass,vers=3.0

# 2단계: docker-compose.yml
services:
  filestation:
    volumes:
      - /mnt/nas_video:/mnt/storage/nas_video

# 3단계: 위 경로란에 /mnt/storage/nas_video 입력</pre>
                                
                                <div style="margin-top:6px;margin-bottom:8px;"><b>부팅 시 자동 마운트 (/etc/fstab)</b></div>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:8px;border-radius:4px;overflow-x:auto;margin:4px 0;font-family:monospace;font-size:11px;"># /etc/fstab — 부팅 시 자동 마운트
//192.168.1.50/video /mnt/nas_video cifs \
  credentials=/etc/smbcredentials,vers=3.0,_netdev 0 0

# /etc/smbcredentials 파일 (chmod 600)
username=admin
password=mypass</pre>

                                <div style="margin-top:10px;background:#fff3cd;padding:8px;border-radius:4px;font-size:11px;">
                                    ⚠️ docker-compose.yml 수정 후 <code>docker compose down && docker compose up -d</code> 실행해야 적용됩니다.
                                </div>
                                
                                <?php endif; ?>
                            </div>
                        </details>
                    </div>
                    
                    <!-- SMB 옵션 -->
                    <div id="storage-smb-options" class="storage-options" style="display:none;">
                        <div style="background:#e8f4fd;padding:8px 12px;border-radius:6px;margin-bottom:12px;font-size:12px;color:#1a5276;line-height:1.6;">
                            <?php if ($currentLang === 'en'): ?>
                                💡 <b>Windows:</b> Connects via <code>net use</code> automatically.<br>
                                🐧 <b>Linux/Docker:</b> Uses <code>mount.cifs</code>. Requires <code>cap_add: [SYS_ADMIN, DAC_READ_SEARCH]</code> in docker-compose.yml and <code>cifs-utils</code> installed.<br>
                                📦 <b>Recommended:</b> Mount SMB on host, then use <b>Local/Network Path</b> type instead. (See guide above)
                            <?php else: ?>
                                💡 <b>Windows:</b> <code>net use</code>로 자동 연결<br>
                                🐧 <b>Linux/Docker:</b> <code>mount.cifs</code> 사용. docker-compose.yml에 <code>cap_add: [SYS_ADMIN, DAC_READ_SEARCH]</code> 필요, 컨테이너에 <code>cifs-utils</code> 설치 필요.<br>
                                📦 <b>권장:</b> 호스트에서 SMB 마운트 후 <b>로컬/네트워크 경로</b> 유형 사용 (위 가이드 참고)
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Host' : '호스트'; ?></label>
                            <input type="text" id="smb-host" placeholder="192.168.1.100">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Share Name' : '공유 이름'; ?></label>
                            <input type="text" id="smb-share" placeholder="share">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Username (optional)' : '사용자명 (선택)'; ?></label>
                            <input type="text" id="smb-username">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Password (optional)' : '비밀번호 (선택)'; ?></label>
                            <input type="password" id="smb-password">
                        </div>
                    </div>
                    
                    <!-- FTP 옵션 -->
                    <div id="storage-ftp-options" class="storage-options" style="display:none;">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Host' : '호스트'; ?></label>
                            <input type="text" id="ftp-host" placeholder="ftp.example.com">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Port' : '포트'; ?></label>
                            <input type="number" id="ftp-port" value="21" placeholder="21">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Username' : '사용자명'; ?></label>
                            <input type="text" id="ftp-username">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Password' : '비밀번호'; ?></label>
                            <input type="password" id="ftp-password">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Root Path (optional)' : '루트 경로 (선택)'; ?></label>
                            <input type="text" id="ftp-root" placeholder="<?php echo $currentLang === 'en' ? 'e.g. /HDD1' : '예: /HDD1'; ?>">
                        </div>
                        <div class="form-group" style="display:flex;gap:16px;flex-wrap:wrap;">
                            <label style="display:inline-flex;align-items:center;gap:4px;margin:0;cursor:pointer;"><input type="checkbox" id="ftp-passive" style="width:auto !important;margin:0;" checked> <?php echo $currentLang === 'en' ? 'Passive Mode' : '패시브 모드'; ?></label>
                            <label style="display:inline-flex;align-items:center;gap:4px;margin:0;cursor:pointer;"><input type="checkbox" id="ftp-ssl" style="width:auto !important;margin:0;"> <?php echo $currentLang === 'en' ? 'Use SSL/TLS' : 'SSL/TLS 사용'; ?></label>
                        </div>
                    </div>
                    
                    <!-- SFTP 옵션 -->
                    <div id="storage-sftp-options" class="storage-options" style="display:none;">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Host' : '호스트'; ?></label>
                            <input type="text" id="sftp-host" placeholder="sftp.example.com">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Port' : '포트'; ?></label>
                            <input type="number" id="sftp-port" value="22" placeholder="22">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Username' : '사용자명'; ?></label>
                            <input type="text" id="sftp-username">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Auth Method' : '인증 방식'; ?></label>
                            <select id="sftp-auth-type">
                                <option value="password"><?php echo $currentLang === 'en' ? 'Password' : '비밀번호'; ?></option>
                                <option value="key"><?php echo $currentLang === 'en' ? 'SSH Key' : 'SSH 키'; ?></option>
                            </select>
                        </div>
                        <div class="form-group" id="sftp-password-group">
                            <label><?php echo $currentLang === 'en' ? 'Password' : '비밀번호'; ?></label>
                            <input type="password" id="sftp-password">
                        </div>
                        <div class="form-group" id="sftp-key-group" style="display:none;">
                            <label><?php echo $currentLang === 'en' ? 'SSH Private Key' : 'SSH 개인키'; ?></label>
                            <textarea id="sftp-private-key" rows="4" placeholder="-----BEGIN RSA PRIVATE KEY-----"></textarea>
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Root Path (optional)' : '루트 경로 (선택)'; ?></label>
                            <input type="text" id="sftp-root" placeholder="<?php echo $currentLang === 'en' ? 'e.g. /home/user' : '예: /home/user'; ?>">
                        </div>
                    </div>
                    
                    <!-- WebDAV 옵션 -->
                    <div id="storage-webdav-options" class="storage-options" style="display:none;">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Server URL' : '서버 URL'; ?></label>
                            <input type="text" id="webdav-url" placeholder="https://cloud.example.com/remote.php/dav/files/user/">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Username' : '사용자명'; ?></label>
                            <input type="text" id="webdav-username">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Password' : '비밀번호'; ?></label>
                            <input type="password" id="webdav-password">
                        </div>
                    </div>
                    
                    <!-- S3 옵션 -->
                    <div id="storage-s3-options" class="storage-options" style="display:none;">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Endpoint (S3 compatible)' : '엔드포인트 (S3 호환)'; ?></label>
                            <input type="text" id="s3-endpoint" placeholder="<?php echo $currentLang === 'en' ? 's3.amazonaws.com or custom URL' : 's3.amazonaws.com 또는 커스텀 URL'; ?>">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Region' : '리전'; ?></label>
                            <input type="text" id="s3-region" placeholder="ap-northeast-2">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Bucket' : '버킷'; ?></label>
                            <input type="text" id="s3-bucket" placeholder="my-bucket">
                        </div>
                        <div class="form-group">
                            <label>Access Key ID</label>
                            <input type="text" id="s3-access-key">
                        </div>
                        <div class="form-group">
                            <label>Secret Access Key</label>
                            <input type="password" id="s3-secret-key">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Prefix (optional)' : '프리픽스 (선택)'; ?></label>
                            <input type="text" id="s3-prefix" placeholder="folder/">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Description' : '설명'; ?></label>
                        <input type="text" id="storage-desc" placeholder="<?php echo $currentLang === 'en' ? 'Optional' : '선택사항'; ?>">
                    </div>
                    
                    <div class="form-group" id="storage-quota-group">
                        <label><?php echo $currentLang === 'en' ? 'Quota Limit' : '용량 제한'; ?></label>
                        <div style="display:flex;flex-direction:column;gap:10px;margin-top:5px;">
                            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
                                <input type="radio" name="quota-mode" value="unlimited" checked style="width:16px;height:16px;">
                                <span><?php echo $currentLang === 'en' ? 'Unlimited' : '무제한'; ?></span>
                            </label>
                            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
                                <input type="radio" name="quota-mode" value="disk" style="width:16px;height:16px;">
                                <span><?php echo $currentLang === 'en' ? 'Show disk usage (progress bar based on actual disk capacity)' : '디스크 용량 표시 (실제 디스크 용량 기준 진행바)'; ?></span>
                            </label>
                            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
                                <input type="radio" name="quota-mode" value="custom" style="width:16px;height:16px;">
                                <span><?php echo $currentLang === 'en' ? 'Set quota limit' : '용량 직접 설정'; ?></span>
                            </label>
                            <div id="quota-custom-wrap" style="display:none;margin-left:28px;">
                                <div style="display:flex;gap:10px;align-items:center;">
                                    <input type="number" id="storage-quota-value" min="1" value="100" style="width:100px;">
                                    <select id="storage-quota-unit" style="width:80px;">
                                        <option value="1073741824">GB</option>
                                        <option value="1099511627776">TB</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top:5px;">
                            <span id="storage-used-size" style="color:#666;font-size:0.9em;"></span>
                        </div>
                        <div style="margin-top:12px;padding:10px;background:var(--bg-light, #f8f9fa);border-radius:6px;">
                            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
                                <input type="checkbox" id="storage-calc-usage" style="width:16px;height:16px;">
                                <span><?php echo $currentLang === 'en' ? 'Calculate current usage on save' : '저장 시 현재 사용량 계산'; ?></span>
                            </label>
                            <div style="color:#e67e22;font-size:0.85em;margin-top:6px;display:none;" id="calc-usage-warning">
                                <?php echo $currentLang === 'en' ? '⚠️ Large storage may take a long time' : '⚠️ 대용량 스토리지는 시간이 오래 걸릴 수 있습니다'; ?>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm" id="btn-recalculate" style="margin-top:10px;display:none;"><?php echo $currentLang === 'en' ? '📊 Recalculate Usage' : '📊 사용량 재계산'; ?></button>
                    </div>
                    
                    <!-- 권한 설정 섹션 -->
                    <div class="permission-section">
                        <h4><?php echo $currentLang === 'en' ? '👥 User Permissions' : '👥 사용자 권한'; ?></h4>
                        <div class="permission-bulk">
                            <span><?php echo $currentLang === 'en' ? 'Bulk Apply:' : '일괄 적용:'; ?></span>
                            <label title="<?php echo $currentLang === 'en' ? 'Show on website' : '웹사이트에 표시'; ?>"><input type="checkbox" id="bulk-visible"> <?php echo $currentLang === 'en' ? 'Web' : '웹'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Show in WebDAV' : 'WebDAV에 표시'; ?>"><input type="checkbox" id="bulk-visible-webdav"> <?php echo $currentLang === 'en' ? 'DAV' : 'DAV'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Open, preview, info' : '파일 열기, 미리보기, 정보'; ?>"><input type="checkbox" id="bulk-read"> <?php echo $currentLang === 'en' ? 'Read' : '읽기'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Download files' : '파일 다운로드'; ?>"><input type="checkbox" id="bulk-download"> <?php echo $currentLang === 'en' ? 'Download' : '다운로드'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Upload, create, rename, move, copy' : '업로드, 새 폴더, 이름변경, 이동, 복사'; ?>"><input type="checkbox" id="bulk-write"> <?php echo $currentLang === 'en' ? 'Write' : '쓰기'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Delete files/folders' : '파일/폴더 삭제'; ?>"><input type="checkbox" id="bulk-delete"> <?php echo $currentLang === 'en' ? 'Delete' : '삭제'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Create external share links' : '외부 공유 링크 생성'; ?>"><input type="checkbox" id="bulk-share"> <?php echo $currentLang === 'en' ? 'Share' : '공유'; ?></label>
                            <button class="btn btn-sm" id="btn-apply-bulk-perm"><?php echo $currentLang === 'en' ? 'Apply' : '적용'; ?></button>
                        </div>
                        <div class="permission-bulk" style="margin-top:4px;background:#f0f7ff;border:1px dashed #90caf9;padding:6px 10px;border-radius:4px;">
                            <span style="font-weight:600;font-size:12px;"><?php echo $currentLang === 'en' ? '🆕 New User Default:' : '🆕 신규회원 기본:'; ?></span>
                            <label title="<?php echo $currentLang === 'en' ? 'Show on website' : '웹사이트에 표시'; ?>"><input type="checkbox" id="def-perm-visible"> <?php echo $currentLang === 'en' ? 'Web' : '웹'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Show in WebDAV' : 'WebDAV에 표시'; ?>"><input type="checkbox" id="def-perm-webdav"> <?php echo $currentLang === 'en' ? 'DAV' : 'DAV'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Open, preview, info' : '파일 열기, 미리보기, 정보'; ?>"><input type="checkbox" id="def-perm-read"> <?php echo $currentLang === 'en' ? 'Read' : '읽기'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Download files' : '파일 다운로드'; ?>"><input type="checkbox" id="def-perm-download"> <?php echo $currentLang === 'en' ? 'Download' : '다운로드'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Upload, create, rename, move, copy' : '업로드, 새 폴더, 이름변경, 이동, 복사'; ?>"><input type="checkbox" id="def-perm-write"> <?php echo $currentLang === 'en' ? 'Write' : '쓰기'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Delete files/folders' : '파일/폴더 삭제'; ?>"><input type="checkbox" id="def-perm-delete"> <?php echo $currentLang === 'en' ? 'Delete' : '삭제'; ?></label>
                            <label title="<?php echo $currentLang === 'en' ? 'Create external share links' : '외부 공유 링크 생성'; ?>"><input type="checkbox" id="def-perm-share"> <?php echo $currentLang === 'en' ? 'Share' : '공유'; ?></label>
                        </div>
                        <p style="margin:6px 0 0;font-size:11px;color:#666;">💡 <?php echo $currentLang === 'en' ? '<b>DAV</b>: When enabled, the WebDAV guide menu appears in the user\'s sidebar.' : '<b>DAV</b>: 활성화하면 해당 사용자의 사이드바에 WebDAV 안내 메뉴가 표시됩니다.'; ?></p>
                        <div class="permission-list" id="permission-list">
                            <input type="text" id="perm-user-search" placeholder="<?php echo $currentLang === 'en' ? '🔍 Search users...' : '🔍 사용자 검색...'; ?>" style="width:100%; padding:6px 10px; margin-bottom:8px; border:1px solid var(--border,#ddd); border-radius:4px; font-size:13px; box-sizing:border-box;">
                            <div id="perm-user-list">
                            <!-- 유저별 권한 목록 -->
                            </div>
                        </div>
                    </div>
                    
                    <!-- 폴더별 권한 섹션 -->
                    <div class="permission-section" style="margin-top:8px;">
                        <h4 style="margin-bottom:4px;">📁 <?php echo $currentLang === 'en' ? 'Folder Permissions' : '폴더별 권한'; ?></h4>
                        <div style="font-size:11px;color:#888;margin:0 0 6px;line-height:1.5;">
                            <p style="margin:0 0 4px;"><?php echo $currentLang === 'en' 
                                ? 'Control folder access per user. Configured folders override storage permissions.' 
                                : '사용자별 특정 폴더의 접근을 제어합니다. 설정된 폴더는 스토리지 권한보다 우선 적용됩니다.'; ?></p>
                            <details style="cursor:pointer;">
                                <summary style="font-weight:bold;color:#555;"><?php echo $currentLang === 'en' ? '📖 Usage Guide' : '📖 사용방법'; ?></summary>
                                <div style="margin-top:4px;padding:6px 8px;background:#f0f4ff;border-radius:4px;color:#555;">
<?php if ($currentLang === 'en') { ?>
                                    <b>• Permission Types</b><br>
                                    &nbsp;&nbsp;✅ Visible — Show/hide folder in list<br>
                                    &nbsp;&nbsp;✅ Read — Allow download, preview, search<br>
                                    &nbsp;&nbsp;✅ Write — Allow upload, rename, delete, move<br><br>
                                    <b>• How it Works</b><br>
                                    &nbsp;&nbsp;- Unconfigured folders follow storage permissions (default visible)<br>
                                    &nbsp;&nbsp;- Configured folders follow the rules below<br>
                                    &nbsp;&nbsp;- Applies to admins as well<br>
                                    &nbsp;&nbsp;- Only folders are controlled; files are always visible<br><br>
                                    <b>• Adding Folders</b><br>
                                    &nbsp;&nbsp;1. Select a user above → Click 📂 Add Folder<br>
                                    &nbsp;&nbsp;2. Check folders in the browse modal → Apply<br><br>
                                    <b>• Adding Users to Existing Folders</b><br>
                                    &nbsp;&nbsp;1. Expand a folder (▶) → Click ➕ Add User<br>
                                    &nbsp;&nbsp;2. Select a user in the modal → Add<br><br>
                                    <b>• Editing Permissions</b><br>
                                    &nbsp;&nbsp;- Click ✅/❌ to toggle individual permissions instantly<br>
                                    &nbsp;&nbsp;- Check folders → "Bulk Edit" to change multiple at once<br><br>
                                    <b>• Example</b><br>
                                    &nbsp;&nbsp;Set "Movies/Adult" → Visible ❌ for user "guest"<br>
                                    &nbsp;&nbsp;→ guest cannot see the folder. Other folders remain visible.
<?php } else { ?>
                                    <b>• 권한 종류</b><br>
                                    &nbsp;&nbsp;✅ 표시 — 폴더 목록에서 보이기/숨기기<br>
                                    &nbsp;&nbsp;✅ 읽기 — 다운로드, 미리보기, 검색 허용<br>
                                    &nbsp;&nbsp;✅ 쓰기 — 업로드, 이름변경, 삭제, 이동 허용<br><br>
                                    <b>• 동작 방식</b><br>
                                    &nbsp;&nbsp;- 미설정 폴더는 스토리지 권한을 따름 (기본 보임)<br>
                                    &nbsp;&nbsp;- 설정된 폴더만 아래 규칙대로 적용<br>
                                    &nbsp;&nbsp;- 관리자에게도 동일하게 적용<br>
                                    &nbsp;&nbsp;- 폴더만 제어하며, 파일은 항상 보임<br><br>
                                    <b>• 폴더 추가</b><br>
                                    &nbsp;&nbsp;1. 위에서 사용자 선택 → 📂 폴더 추가 클릭<br>
                                    &nbsp;&nbsp;2. 찾아보기 모달에서 폴더 체크 → 선택 적용<br><br>
                                    <b>• 기존 폴더에 사용자 추가</b><br>
                                    &nbsp;&nbsp;1. 폴더 펼치기 (▶) → ➕ 사용자 추가 클릭<br>
                                    &nbsp;&nbsp;2. 모달에서 사용자 선택 → 추가<br><br>
                                    <b>• 권한 수정</b><br>
                                    &nbsp;&nbsp;- ✅/❌ 클릭하면 즉시 개별 권한 변경<br>
                                    &nbsp;&nbsp;- 폴더 체크 → "선택 수정"으로 일괄 변경 가능<br><br>
                                    <b>• 사용 예시</b><br>
                                    &nbsp;&nbsp;"영화/성인" 폴더 → 사용자 "guest"에게 표시 ❌ 설정<br>
                                    &nbsp;&nbsp;→ guest는 해당 폴더를 볼 수 없음. 다른 폴더는 정상 표시.
<?php } ?>
                                </div>
                            </details>
                        </div>
                        <div id="folder-perm-list" style="margin-bottom:6px;"></div>
                        <div style="background:#f8f9fa;padding:6px 8px;border-radius:6px;font-size:12px;">
                            <div class="form-row" style="gap:6px;flex-wrap:wrap;align-items:center;">
                                <select id="fperm-user-select" style="flex:1;min-width:100px;font-size:12px;padding:3px 4px;"></select>
                                <button class="btn btn-sm" id="btn-fperm-browse" title="<?php echo $currentLang === 'en' ? 'Browse & Add' : '폴더 찾아보기 & 추가'; ?>">📂 <?php echo $currentLang === 'en' ? 'Add Folder' : '폴더 추가'; ?></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-save-storage"><?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                </div>
            </div>
            
            <!-- 공유 모달 -->
            <div id="modal-share" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? 'Create Share Link' : '공유 링크 생성'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'File' : '파일'; ?></label>
                        <div id="share-filename" class="share-filename"></div>
                    </div>
                    <div class="form-group" id="share-type-group" style="display:none;">
                        <label><?php echo $currentLang === 'en' ? 'Share Type' : '공유 유형'; ?></label>
                        <div style="display: flex; gap: 12px; margin-top: 4px;">
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="radio" name="share-type" value="download" checked style="width: 16px; height: 16px;">
                                <span>📥 <?php echo $currentLang === 'en' ? 'Download' : '다운로드'; ?></span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="radio" name="share-type" value="stream" style="width: 16px; height: 16px;">
                                <span>▶️ <?php echo $currentLang === 'en' ? 'Streaming' : '스트리밍'; ?></span>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Expires' : '만료'; ?></label>
                        <select id="share-expire">
                            <option value=""><?php echo $currentLang === 'en' ? 'Unlimited' : '무제한'; ?></option>
                            <option value="0.020833"><?php echo $currentLang === 'en' ? '30 min' : '30분'; ?></option>
                            <option value="0.041667"><?php echo $currentLang === 'en' ? '1 hour' : '1시간'; ?></option>
                            <option value="0.125"><?php echo $currentLang === 'en' ? '3 hours' : '3시간'; ?></option>
                            <option value="0.25"><?php echo $currentLang === 'en' ? '6 hours' : '6시간'; ?></option>
                            <option value="0.5"><?php echo $currentLang === 'en' ? '12 hours' : '12시간'; ?></option>
                            <option value="1"><?php echo $currentLang === 'en' ? '1 day' : '1일'; ?></option>
                            <option value="7" selected><?php echo $currentLang === 'en' ? '7 days' : '7일'; ?></option>
                            <option value="30"><?php echo $currentLang === 'en' ? '30 days' : '30일'; ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Password (optional)' : '비밀번호 (선택)'; ?></label>
                        <input type="text" id="share-password" placeholder="<?php echo $currentLang === 'en' ? 'Not set' : '설정 안함'; ?>">
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Max Downloads (optional)' : '최대 다운로드 (선택)'; ?></label>
                        <input type="number" id="share-max-downloads" placeholder="<?php echo $currentLang === 'en' ? 'Unlimited' : '무제한'; ?>">
                    </div>
                    <div id="share-result" class="share-result" style="display:none;">
                        <label><?php echo $currentLang === 'en' ? 'Share Link' : '공유 링크'; ?></label>
                        <div class="share-url-box">
                            <input type="text" id="share-url" readonly>
                            <button id="btn-copy-url" class="btn">📋 <?php echo $currentLang === 'en' ? 'Copy' : '복사'; ?></button>
                        </div>
                    </div>
                    <div id="share-existing-container"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Close' : '닫기'; ?></button>
                    <button class="btn btn-primary" id="btn-create-share"><?php echo $currentLang === 'en' ? 'Create' : '생성'; ?></button>
                </div>
            </div>
            
            <!-- 이름 변경 모달 -->
            
            <!-- 내부 사용자 공유 모달 -->
            <div id="modal-internal-share" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>👤 <?php echo $currentLang === 'en' ? 'Share with User' : '사용자에게 공유'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'File/Folder' : '파일/폴더'; ?></label>
                        <div id="ishare-filename" class="share-filename"></div>
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Share with' : '공유 대상'; ?></label>
                        <input type="text" id="ishare-user-search" placeholder="<?php echo $currentLang === 'en' ? 'Search user by name...' : '사용자 이름 검색...'; ?>" autocomplete="off">
                        <div id="ishare-user-results" style="max-height:150px;overflow-y:auto;border:1px solid #ddd;border-radius:6px;display:none;"></div>
                        <div id="ishare-selected-user" style="display:none;margin-top:6px;padding:8px 12px;background:#e8f4ff;border-radius:6px;"></div>
                    </div>
                    <div class="form-group" id="ishare-permission-group">
                        <label><?php echo $currentLang === 'en' ? 'Permission' : '권한'; ?></label>
                        <select id="ishare-permission">
                            <option value="read"><?php echo $currentLang === 'en' ? '👁️ Read only' : '👁️ 읽기 전용'; ?></option>
                            <option value="write"><?php echo $currentLang === 'en' ? '✏️ Read & Write (Upload)' : '✏️ 읽기 + 쓰기 (업로드)'; ?></option>
                            <option value="full"><?php echo $currentLang === 'en' ? '🔓 Full (Upload, Delete, Rename)' : '🔓 전체 (업로드, 삭제, 이름변경)'; ?></option>
                        </select>
                        <div id="ishare-perm-hint" style="font-size:12px;color:#888;margin-top:4px;"></div>
                    </div>
                    <div id="ishare-existing" style="display:none;margin-top:10px;">
                        <label style="font-weight:600;"><?php echo $currentLang === 'en' ? 'Currently shared with' : '현재 공유 중'; ?></label>
                        <div id="ishare-existing-list" style="margin-top:5px;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Close' : '닫기'; ?></button>
                    <button class="btn btn-primary" id="btn-create-ishare"><?php echo $currentLang === 'en' ? 'Share' : '공유'; ?></button>
                </div>
            </div>
            
            <!-- 나에게 공유됨 모달 -->
            <!-- 내 사용자 공유 모달 -->
            <div id="modal-my-ishares" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>👤 <?php echo $currentLang === 'en' ? 'My User Shares' : '내 사용자 공유'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="my-ishares-list"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Close' : '닫기'; ?></button>
                </div>
            </div>
            
            <!-- 관리자: 전체 사용자 공유 관리 모달 -->
            <div id="modal-all-ishares" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>👤 <?php echo $currentLang === 'en' ? 'User Share Management' : '사용자 공유 관리'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="all-ishares-list"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Close' : '닫기'; ?></button>
                </div>
            </div>
            
            <div id="modal-shared-with-me" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>📨 <?php echo $currentLang === 'en' ? 'Shared with Me' : '나에게 공유됨'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="shared-with-me-list"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Close' : '닫기'; ?></button>
                </div>
            </div>
            
            <div id="modal-rename" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? 'Rename' : '이름 변경'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="text" id="rename-input" placeholder="<?php echo $currentLang === 'en' ? 'New name' : '새 이름'; ?>">
                    <div id="rename-ext-row" style="margin-top:10px; display:none;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px; color:#666;">
                            <input type="checkbox" id="rename-show-ext" style="width:auto; margin:0;">
                            <?php echo $currentLang === 'en' ? 'Edit with extension' : '확장자 포함 편집'; ?> <span id="rename-ext-preview" style="color:#999;"></span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-rename-confirm"><?php echo $currentLang === 'en' ? 'Change' : '변경'; ?></button>
                </div>
            </div>
            
            <!-- 스토리지 관리 모달 -->
            <div id="modal-storages" class="modal modal-xl" style="display:none;">
                <div class="modal-header">
                    <h2>💾 <?php echo $currentLang === 'en' ? 'Storage Management' : '스토리지 관리'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <button class="btn btn-primary" id="btn-add-storage-new" style="margin-bottom:15px;"><?php echo $currentLang === 'en' ? '➕ Add Storage' : '➕ 스토리지 추가'; ?></button>
                    <div style="overflow-x:auto;">
                        <table class="data-table" id="storages-table" style="table-layout:fixed;width:100%;min-width:700px;">
                            <thead>
                                <tr>
                                    <th style="width:36px;">ID</th>
                                    <th style="width:130px;"><?php echo $currentLang === 'en' ? 'Name' : '이름'; ?></th>
                                    <th style="width:150px;"><?php echo $currentLang === 'en' ? 'Path' : '경로'; ?></th>
                                    <th style="width:60px;text-align:center;"><?php echo $currentLang === 'en' ? 'Type' : '유형'; ?></th>
                                    <th style="width:140px;"><?php echo $currentLang === 'en' ? 'Quota' : '용량'; ?></th>
                                    <th><?php echo $currentLang === 'en' ? 'Description' : '설명'; ?></th>
                                    <th style="width:120px;"><?php echo $currentLang === 'en' ? 'Actions' : '관리'; ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- 사용자 관리 모달 -->
            <div id="modal-users" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? 'User Management' : '사용자 관리'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- 현재 시스템 설정 상태 -->
                    <div id="user-settings-status" class="settings-status-bar">
                        <span id="status-signup" class="status-item">
                            <span class="status-off">🚫 <?php echo $currentLang === 'en' ? 'Registration Disabled' : '회원가입 비허용'; ?></span>
                        </span>
                        <span id="status-approve" class="status-item" style="display:none;">
                            <span class="status-auto"><?php echo $currentLang === 'en' ? '🔄 Auto Approve' : '🔄 자동 승인'; ?></span>
                        </span>
                        <span id="status-home-share" class="status-item">
                            <span class="status-on">🔗 <?php echo $currentLang === 'en' ? 'Personal folder external sharing allowed' : '개인폴더 외부 공유 허용'; ?></span>
                        </span>
                        <a href="#" id="link-change-settings" class="settings-link"><?php echo $currentLang === 'en' ? '⚙️ Change Settings' : '⚙️ 설정 변경'; ?></a>
                    </div>
                    
                    <div style="margin-bottom:15px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button class="btn btn-primary" id="btn-add-user"><?php echo $currentLang === 'en' ? '➕ Add User' : '➕ 사용자 추가'; ?></button>
                        <button class="btn" id="btn-bulk-quota">💾 <?php echo $currentLang === 'en' ? 'Bulk Quota Settings' : '일괄 용량 설정'; ?></button>
                        <input type="text" id="users-search" placeholder="<?php echo $currentLang === 'en' ? '🔍 Search users...' : '🔍 사용자 검색...'; ?>" style="margin-left:auto; width:200px; padding:6px 10px; border:1px solid var(--border,#ddd); border-radius:4px; font-size:13px;">
                    </div>
                    <div style="margin-bottom:10px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <span style="font-size:13px; color:#666;"><?php echo $currentLang === 'en' ? '🆕 New User Default Quota:' : '🆕 신규회원 기본 용량:'; ?></span>
                        <input type="number" id="default-quota-value" min="0" value="0" style="width:70px; padding:4px 6px; border:1px solid var(--border,#ddd); border-radius:4px; font-size:13px;">
                        <select id="default-quota-unit" style="padding:4px 6px; border:1px solid var(--border,#ddd); border-radius:4px; font-size:13px;">
                            <option value="MB">MB</option>
                            <option value="GB" selected>GB</option>
                        </select>
                        <button class="btn btn-sm" id="btn-save-default-quota" style="font-size:11px;padding:3px 8px;"><?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                    </div>
                    <table class="data-table" id="users-table" style="table-layout:fixed;width:100%;">
                        <thead>
                            <tr>
                                <th style="width:70px;"><?php echo $currentLang === 'en' ? 'Username' : '아이디'; ?></th>
                                <th style="width:70px;"><?php echo $currentLang === 'en' ? 'Name' : '이름'; ?></th>
                                <th style="width:55px;"><?php echo $currentLang === 'en' ? 'Role' : '역할'; ?></th>
                                <th style="width:70px;"><?php echo $currentLang === 'en' ? 'Quota' : '용량'; ?></th>
                                <th style="width:55px;text-align:center;"><?php echo $currentLang === 'en' ? 'Status' : '상태'; ?></th>
                                <th style="width:82px;"><?php echo $currentLang === 'en' ? 'Joined' : '가입일'; ?></th>
                                <th style="width:145px;"><?php echo $currentLang === 'en' ? 'Last Login' : '마지막 로그인'; ?></th>
                                <th style="width:90px;"><?php echo $currentLang === 'en' ? 'Actions' : '관리'; ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            
            <!-- 일괄 용량 설정 모달 -->
            <div id="modal-bulk-quota" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>💾 <?php echo $currentLang === 'en' ? 'Bulk Quota Settings' : '일괄 용량 설정'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Apply To' : '적용 대상'; ?></label>
                        <select id="bulk-quota-target">
                            <option value="all"><?php echo $currentLang === 'en' ? 'All Users' : '모든 사용자'; ?></option>
                            <option value="user"><?php echo $currentLang === 'en' ? 'Normal Users Only' : '일반 사용자만'; ?></option>
                            <option value="unlimited"><?php echo $currentLang === 'en' ? 'Users with Unlimited Quota Only' : '현재 무제한인 사용자만'; ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Quota Settings' : '용량 설정'; ?></label>
                        <div class="quota-input">
                            <input type="number" id="bulk-quota-value" min="0" value="10">
                            <select id="bulk-quota-unit">
                                <option value="0"><?php echo $currentLang === 'en' ? 'Unlimited' : '무제한'; ?></option>
                                <option value="1073741824" selected>GB</option>
                                <option value="1048576">MB</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-muted" style="font-size: 12px; color: #666;">
                        <?php echo $currentLang === 'en' ? '⚠️ The same quota will be applied to all users in the selected target.' : '⚠️ 선택한 대상의 모든 사용자에게 동일한 용량이 적용됩니다.'; ?>
                    </p>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-apply-bulk-quota"><?php echo $currentLang === 'en' ? 'Apply' : '적용'; ?></button>
                </div>
            </div>
            
            <!-- 사용자 추가/수정 모달 -->
            <div id="modal-user-form" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2 id="user-form-title"><?php echo $currentLang === 'en' ? 'Add User' : '사용자 추가'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="user-id">
                    <div class="form-row-2col">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Username' : '아이디'; ?> <span class="input-hint" style="font-weight:normal;"><?php echo $currentLang === 'en' ? '(3-20, letters/numbers/_)' : '(3~20자, 영문/숫자/_)'; ?></span></label>
                            <input type="text" id="user-username" maxlength="20" pattern="[a-zA-Z0-9_]{3,20}">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Password' : '비밀번호'; ?></label>
                            <input type="password" id="user-password" placeholder="<?php echo $currentLang === 'en' ? 'Enter only when changing' : '변경 시에만 입력'; ?>" maxlength="72">
                            <div class="password-strength" id="user-password-strength"></div>
                        </div>
                    </div>
                    <div class="form-row-2col">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Display Name' : '표시 이름'; ?> <span class="input-hint" style="font-weight:normal;"><?php echo $currentLang === 'en' ? '(max 30)' : '(최대 30자)'; ?></span></label>
                            <input type="text" id="user-display-name" maxlength="30">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Email' : '이메일'; ?></label>
                            <input type="email" id="user-email" placeholder="<?php echo $currentLang === 'en' ? 'Used for password recovery' : '비밀번호 찾기에 사용'; ?>">
                        </div>
                    </div>
                    <div class="form-row-2col">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Role' : '역할'; ?></label>
                            <select id="user-role">
                                <option value="user"><?php echo $currentLang === 'en' ? 'Normal User' : '일반 사용자'; ?></option>
                                <option value="sub_admin"><?php echo $currentLang === 'en' ? 'Sub Admin' : '부 관리자'; ?></option>
                                <option value="admin"><?php echo $currentLang === 'en' ? 'Admin' : '관리자'; ?></option>
                            </select>
                        </div>
                        <div class="form-group" id="user-status-group">
                            <label><?php echo $currentLang === 'en' ? 'Status' : '상태'; ?></label>
                            <select id="user-status">
                                <option value="active"><?php echo $currentLang === 'en' ? 'Active' : '활성'; ?></option>
                                <option value="suspended"><?php echo $currentLang === 'en' ? 'Suspended' : '정지'; ?></option>
                                <option value="pending"><?php echo $currentLang === 'en' ? 'Pending' : '승인 대기'; ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row-2col">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Storage Quota Limit' : '저장 용량 제한'; ?></label>
                            <div class="quota-input">
                                <input type="number" id="user-quota" min="0" value="0" style="width: 80px;">
                                <select id="user-quota-unit">
                                    <option value="0"><?php echo $currentLang === 'en' ? 'Unlimited' : '무제한'; ?></option>
                                    <option value="1073741824">GB</option>
                                    <option value="1048576">MB</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group"></div>
                    </div>
                    
                    <!-- 정지 기간 설정 -->
                    <div id="suspend-period" class="suspend-section" style="display:none;">
                        <h4>🚫 <?php echo $currentLang === 'en' ? 'Suspension Period' : '정지 기간 설정'; ?></h4>
                        <div class="form-row-2col">
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Start Date' : '시작일'; ?></label>
                                <input type="date" id="suspend-from" lang="<?php echo $currentLang; ?>">
                            </div>
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'End Date' : '종료일'; ?></label>
                                <input type="date" id="suspend-until" lang="<?php echo $currentLang; ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Suspension Reason' : '정지 사유'; ?></label>
                            <input type="text" id="suspend-reason" placeholder="<?php echo $currentLang === 'en' ? 'Enter suspension reason' : '정지 사유를 입력하세요'; ?>">
                        </div>
                    </div>
                    
                    <!-- 부 관리자 권한 설정 -->
                    <div id="sub-admin-perms" class="sub-admin-section" style="display:none;">
                        <h4>🔧 <?php echo $currentLang === 'en' ? 'Sub Admin Permissions' : '부 관리자 접근 권한'; ?></h4>
                        <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Select admin menus accessible to sub-admins.' : '부 관리자가 접근할 수 있는 관리 메뉴를 선택하세요.'; ?></p>
                        <div class="admin-menu-checks">
                            <label><input type="checkbox" name="admin_perm" value="storages"> <?php echo $currentLang === 'en' ? '💾 Storage Management' : '💾 스토리지 관리'; ?></label>
                            <label><input type="checkbox" name="admin_perm" value="users"> <?php echo $currentLang === 'en' ? '👥 User Management' : '👥 사용자 관리'; ?></label>
                            <label><input type="checkbox" name="admin_perm" value="shares"> <?php echo $currentLang === 'en' ? '🔗 Share Management' : '🔗 공유 관리'; ?></label>
                            <label><input type="checkbox" name="admin_perm" value="logins"> <?php echo $currentLang === 'en' ? '📋 Login History' : '📋 로그인 기록'; ?></label>
                            <label><input type="checkbox" name="admin_perm" value="trash"> <?php echo $currentLang === 'en' ? '🗑️ All Trash' : '🗑️ 전체 휴지통'; ?></label>
                            <label><input type="checkbox" name="admin_perm" value="security"> <?php echo $currentLang === 'en' ? '🔒 IP/Country Blocking' : '🔒 IP/국가 차단 설정'; ?></label>
                            <label><input type="checkbox" name="admin_perm" value="antivirus"> <?php echo $currentLang === 'en' ? '🛡️ Antivirus Settings' : '🛡️ 백신 설정'; ?></label>
                            <label><input type="checkbox" name="admin_perm" value="ransomware"> <?php echo $currentLang === 'en' ? '🔐 Ransomware Protection' : '🔐 랜섬웨어 방지'; ?></label>
                            <label><input type="checkbox" name="admin_perm" value="upload_settings"> <?php echo $currentLang === 'en' ? '📤 Upload Settings' : '📤 업로드 설정'; ?></label>
                            <label><input type="checkbox" name="admin_perm" value="system_settings"> <?php echo $currentLang === 'en' ? '🔧 System Settings' : '🔧 시스템 설정'; ?></label>
                            <label><input type="checkbox" name="admin_perm" value="system_info"> <?php echo $currentLang === 'en' ? '📊 System Info' : '📊 시스템 정보'; ?></label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-save-user"><?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                </div>
            </div>
            
            <!-- 역할 관리 모달 -->
            <div id="modal-roles" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>🏷️ <?php echo $currentLang === 'en' ? 'Role Management' : '역할 관리'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-row" style="margin-bottom: 15px; gap: 10px;">
                        <input type="text" id="new-role-name" placeholder="<?php echo $currentLang === 'en' ? 'New role name' : '새 역할 이름'; ?>" style="flex:1; padding: 10px 12px;">
                        <button class="btn btn-primary" id="btn-add-role" style="padding: 10px 20px;"><?php echo $currentLang === 'en' ? 'Add' : '추가'; ?></button>
                    </div>
                    <div id="roles-list" class="roles-list"></div>
                    <p class="setting-desc" style="margin-top:15px;"><?php echo $currentLang === 'en' ? '※ Default roles (Admin, Sub Admin, User) cannot be deleted.' : '※ 기본 역할(관리자, 부관리자, 사용자)은 삭제할 수 없습니다.'; ?></p>
                </div>
            </div>
            
            <!-- QoS 속도 제한 모달 -->
            <div id="modal-qos" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>⚡ <?php echo $currentLang === 'en' ? 'Speed Limit Settings' : '속도 제한 설정'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="qos-tabs">
                        <button class="qos-tab-btn active" data-tab="qos-roles"><?php echo $currentLang === 'en' ? 'By Role' : '역할별 설정'; ?></button>
                        <button class="qos-tab-btn" data-tab="qos-users"><?php echo $currentLang === 'en' ? 'By User' : '사용자별 설정'; ?></button>
                    </div>
                    
                    <!-- 역할별 설정 탭 -->
                    <div id="qos-roles" class="qos-tab-content active">
                        <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Set default speed limits by role. (0 = Unlimited)' : '역할별 기본 속도 제한을 설정합니다. (0 = 무제한)'; ?></p>
                        <div id="qos-roles-list" class="qos-list"></div>
                    </div>
                    
                    <!-- 사용자별 설정 탭 -->
                    <div id="qos-users" class="qos-tab-content" style="display:none;">
                        <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Set individual user speed limits. Overrides role settings.' : '개별 사용자의 속도 제한을 설정합니다. 역할 설정보다 우선 적용됩니다.'; ?></p>
                        <div class="qos-user-search">
                            <input type="text" id="qos-user-search" placeholder="<?php echo $currentLang === 'en' ? 'Search users...' : '사용자 검색...'; ?>">
                        </div>
                        <div id="qos-users-list" class="qos-list"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Close' : '닫기'; ?></button>
                    <button class="btn btn-primary" id="btn-save-qos"><?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                </div>
            </div>
            
            <!-- 공유 목록 모달 -->
            <div id="modal-shares-list" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>🔗 <?php echo $currentLang === 'en' ? 'Share Management' : '공유 관리'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="shares-toolbar" style="display:none;margin-bottom:12px;gap:8px;align-items:center;flex-wrap:wrap;">
                        <select id="shares-filter" style="padding:6px 10px;border:1px solid var(--border-color,#ddd);border-radius:6px;font-size:13px;">
                            <option value="all"><?php echo $currentLang === 'en' ? 'All periods' : '전체 기간'; ?></option>
                            <option value="hour"><?php echo $currentLang === 'en' ? 'Hours (30m~12h)' : '시간 단위 (30분~12시간)'; ?></option>
                            <option value="1"><?php echo $currentLang === 'en' ? '1 day' : '1일'; ?></option>
                            <option value="7"><?php echo $currentLang === 'en' ? '2~7 days' : '2~7일'; ?></option>
                            <option value="30"><?php echo $currentLang === 'en' ? '8~30 days' : '8~30일'; ?></option>
                            <option value="unlimited"><?php echo $currentLang === 'en' ? 'Unlimited' : '무제한'; ?></option>
                            <option value="expiring"><?php echo $currentLang === 'en' ? 'Expiring soon' : '만료 임박'; ?></option>
                        </select>
                        <select id="shares-type-filter" style="padding:6px 10px;border:1px solid var(--border-color,#ddd);border-radius:6px;font-size:13px;">
                            <option value="all"><?php echo $currentLang === 'en' ? 'All types' : '전체 타입'; ?></option>
                            <option value="download"><?php echo $currentLang === 'en' ? 'Download' : '다운로드'; ?></option>
                            <option value="stream"><?php echo $currentLang === 'en' ? 'Streaming' : '스트리밍'; ?></option>
                            <option value="filedrop"><?php echo $currentLang === 'en' ? 'File Drop' : '파일 드롭'; ?></option>
                        </select>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" id="shares-select-all"> <?php echo $currentLang === 'en' ? 'Select All' : '전체 선택'; ?>
                        </label>
                        <button id="btn-shares-delete-selected" class="btn btn-sm btn-danger" style="display:none;font-size:12px;">🗑️ <?php echo $currentLang === 'en' ? 'Delete Selected' : '선택 삭제'; ?></button>
                        <span id="shares-count" style="margin-left:auto;font-size:12px;color:var(--text-secondary,#888);"></span>
                    </div>
                    <div id="shares-empty" class="empty-msg" style="display:none;"><?php echo $currentLang === 'en' ? 'No shared files' : '공유된 파일이 없습니다'; ?></div>
                    <div id="shares-list-container"></div>
                </div>
            </div>
            
            <!-- 설정 모달 -->
            <div id="modal-settings" class="modal modal-large" style="display:none;">
                <div class="modal-header">
                    <h2>⚙️ <?php _e('settings'); ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- 탭 -->
                    <div class="settings-tabs">
                        <button class="tab-btn active" data-tab="tab-profile">👤 <?php echo $currentLang === 'en' ? 'My Info' : '내 정보'; ?></button>
                        <button class="tab-btn" data-tab="tab-twofa">🔐 <?php echo $currentLang === 'en' ? '2FA' : '2단계 인증'; ?></button>
                        <button class="tab-btn" data-tab="tab-app-passwords">🔑 <?php echo $currentLang === 'en' ? 'App Passwords' : '앱 비밀번호'; ?></button>
                        <button class="tab-btn" data-tab="tab-theme">🎨 <?php _e('theme'); ?></button>
                        <button class="tab-btn" data-tab="tab-sessions"><?php echo $currentLang === 'en' ? '📱 Sessions' : '📱 세션 관리'; ?></button>
                        <button class="tab-btn" data-tab="tab-login-logs"><?php echo $currentLang === 'en' ? '📋 Login History' : '📋 로그인 기록'; ?></button>
                    </div>
                    
                    <!-- 내 정보 탭 -->
                    <div id="tab-profile" class="tab-content active">
                        <!-- 계정 정보 표시 -->
                        <div class="my-account-info">
                            <h4>👤 <?php echo $currentLang === 'en' ? 'My Info' : '내 정보'; ?></h4>
                            <div class="account-info-card">
                                <div class="info-row">
                                    <span class="info-label">🔑 <?php _e('username'); ?></span>
                                    <span class="info-value" id="my-info-username">-</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">👤 <?php echo $currentLang === 'en' ? 'Display Name' : '표시 이름'; ?></span>
                                    <span class="info-value" id="my-info-displayname">-</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">📧 <?php _e('email'); ?></span>
                                    <span class="info-value" id="my-info-email">-</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">📅 <?php _e('join_date'); ?></span>
                                    <span class="info-value" id="my-info-created">-</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">🌐 <?php echo $currentLang === 'en' ? 'IP Address' : '접속 IP'; ?></span>
                                    <span class="info-value" id="my-info-ip">-</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">🌍 <?php echo $currentLang === 'en' ? 'Country' : '접속 국가'; ?></span>
                                    <span class="info-value" id="my-info-country">-</span>
                                </div>
                            </div>
                        </div>
                        
                        <hr style="margin: 20px 0;">
                        
                        <h4>🌐 <?php echo $currentLang === 'en' ? 'Language' : '언어'; ?></h4>
                        <div class="form-group">
                            <select id="language-select" class="form-control" style="max-width:200px;">
                                <option value="ko" <?php echo $currentLang === 'ko' ? 'selected' : ''; ?>>🇰🇷 한국어</option>
                                <option value="en" <?php echo $currentLang === 'en' ? 'selected' : ''; ?>>🇺🇸 English</option>
                            </select>
                        </div>
                        
                        <hr style="margin: 20px 0;">
                        
                        <h4><?php echo $currentLang === 'en' ? 'Edit Profile' : '정보 수정'; ?></h4>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Display Name' : '표시 이름'; ?></label>
                            <input type="text" id="settings-display-name" placeholder="<?php echo $currentLang === 'en' ? 'Display Name' : '표시 이름'; ?>">
                        </div>
                        <div class="form-group">
                            <label><?php _e('email'); ?></label>
                            <input type="email" id="settings-email" placeholder="<?php _e('email'); ?>">
                        </div>
                        <button class="btn btn-primary" id="btn-save-settings"><?php echo $currentLang === 'en' ? 'Save Profile' : '정보 저장'; ?></button>
                        
                        <hr style="margin: 20px 0;">
                        
                        <h4><?php _e('change_password'); ?></h4>
                        <div class="form-group">
                            <label><?php _e('current_password'); ?></label>
                            <input type="password" id="current-password">
                        </div>
                        <div class="form-group">
                            <label><?php _e('new_password'); ?></label>
                            <input type="password" id="new-password">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Confirm New Password' : '새 비밀번호 확인'; ?></label>
                            <input type="password" id="confirm-password">
                        </div>
                        <button class="btn btn-primary" id="btn-change-password"><?php _e('change_password'); ?></button>
                        
                        <hr style="margin: 20px 0;">
                        
                        <!-- 회원 탈퇴 -->
                        <div class="account-danger-zone" id="withdraw-section" style="display:none;">
                            <h4>⚠️ <?php _e('withdraw'); ?></h4>
                            <p class="text-muted"><?php echo $currentLang === 'en' ? 'Account info, login history, and activity logs will be deleted.' : '탈퇴 시 계정 정보, 로그인 기록, 활동 로그가 삭제됩니다.'; ?></p>
                            <button class="btn btn-danger" id="btn-withdraw-account"><?php _e('withdraw'); ?></button>
                        </div>
                        <div class="account-danger-zone" id="withdraw-admin-notice" style="display:none; background: #f0f0f0; border-color: #ddd;">
                            <h4 style="color: #666;">ℹ️ <?php _e('withdraw'); ?></h4>
                            <p class="text-muted"><?php _e('admin_no_withdraw'); ?></p>
                        </div>
                    </div>
                    
                    <!-- 2FA 탭 -->
                    <div id="tab-twofa" class="tab-content" style="display:none;">
                        <h4><?php echo $currentLang === 'en' ? '🔐 Two-Factor Auth (TOTP)' : '🔐 2단계 인증 (TOTP)'; ?></h4>
                        <p class="text-muted" style="margin-bottom:20px;"><?php echo $currentLang === 'en' ? 'Protect your account with Google Authenticator, Authy, or similar apps.' : 'Google Authenticator, Authy 등의 앱을 사용하여 계정을 보호하세요.'; ?></p>
                        
                        <!-- 관리자 TOTP 설정 (JavaScript로 동적 표시) -->
                        <div id="twofa-admin-settings" class="totp-admin-section" style="display:none; margin-bottom:25px; padding:15px; background:#f8f9fa; border-radius:8px; border:1px solid #e9ecef;">
                            <h5 style="margin:0 0 15px 0; color:#495057;"><?php echo $currentLang === 'en' ? '⚙️ Admin TOTP Settings' : '⚙️ 관리자 TOTP 설정'; ?></h5>
                            <p class="text-muted" style="font-size:13px; margin-bottom:15px;"><?php echo $currentLang === 'en' ? 'Settings used when users set up 2FA.' : '사용자들이 2단계 인증을 설정할 때 사용되는 설정입니다.'; ?></p>
                            
                            <div style="display:flex; gap:15px; flex-wrap:wrap;">
                                <div style="flex:2; min-width:200px;">
                                    <label style="display:block; margin-bottom:5px; font-weight:500;"><?php echo $currentLang === 'en' ? 'Issuer Name (shown in QR code)' : '발급자명 (QR 코드에 표시)'; ?></label>
                                    <input type="text" id="totp-issuer" placeholder="WebHard" style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                                    <div style="font-size:12px; color:#6c757d; margin-top:3px;"><?php echo $currentLang === 'en' ? 'Name used to identify accounts in authenticator apps.' : '인증 앱에서 계정을 식별하는 이름입니다.'; ?></div>
                                </div>
                            </div>
                            
                            <div style="display:flex; gap:15px; flex-wrap:wrap; margin-top:15px; align-items:flex-start;">
                                <div style="flex:2; min-width:200px;">
                                    <label style="display:block; margin-bottom:5px; font-weight:500;"><?php echo $currentLang === 'en' ? 'Encryption Key Status' : '암호화 키 상태'; ?></label>
                                    <div style="background:#fff; padding:8px 12px; border:1px solid #ced4da; border-radius:4px;">
                                        <code id="totp-key-masked" style="font-size:13px;">-</code>
                                        <span id="totp-key-generated" style="margin-left:10px; color:#666; font-size:12px;"></span>
                                    </div>
                                    <div style="font-size:12px; color:#6c757d; margin-top:3px;"><?php echo $currentLang === 'en' ? 'Used to encrypt 2FA secret keys. Auto-generated on first install.' : '2FA 비밀키를 암호화하는 데 사용됩니다. 처음 설치 시 자동 생성됩니다.'; ?></div>
                                </div>
                                <div style="flex:1; min-width:150px;">
                                    <label style="display:block; margin-bottom:5px;">&nbsp;</label>
                                    <button class="btn btn-warning btn-sm" id="btn-regenerate-totp-key"><?php echo $currentLang === 'en' ? '🔄 Regenerate Key' : '🔄 키 재생성'; ?></button>
                                    <div style="font-size:11px; color:#d9534f; margin-top:3px;"><?php echo $currentLang === 'en' ? '⚠️ Cannot regenerate if 2FA users exist' : '⚠️ 기존 2FA 사용자가 있으면 재생성 불가'; ?></div>
                                </div>
                            </div>
                            
                            <div style="margin-top:15px; text-align:right;">
                                <button class="btn btn-primary btn-sm" id="btn-save-totp-settings"><?php echo $currentLang === 'en' ? '💾 Save TOTP Settings' : '💾 TOTP 설정 저장'; ?></button>
                            </div>
                        </div>
                        
                        <div id="twofa-status-area">
                            <div id="twofa-disabled-section" style="display:none;">
                                <div class="alert alert-warning">
                                    <strong><?php echo $currentLang === 'en' ? '⚠️ 2FA is disabled.' : '⚠️ 2단계 인증이 비활성화되어 있습니다.'; ?></strong>
                                    <p><?php echo $currentLang === 'en' ? 'Enabling 2FA requires additional verification at login.' : '2단계 인증을 활성화하면 로그인 시 추가 인증이 필요합니다.'; ?></p>
                                </div>
                                <button class="btn btn-primary" id="btn-twofa-setup"><?php echo $currentLang === 'en' ? 'Setup 2FA' : '2단계 인증 설정'; ?></button>
                            </div>
                            
                            <div id="twofa-enabled-section" style="display:none;">
                                <div class="alert alert-success">
                                    <strong><?php echo $currentLang === 'en' ? '✅ 2FA is enabled.' : '✅ 2단계 인증이 활성화되어 있습니다.'; ?></strong>
                                    <p id="twofa-enabled-info"></p>
                                </div>
                                <button class="btn btn-warning" id="btn-twofa-regenerate-backup"><?php echo $currentLang === 'en' ? 'Regenerate Backup Codes' : '백업 코드 재생성'; ?></button>
                                <button class="btn btn-danger" id="btn-twofa-disable"><?php echo $currentLang === 'en' ? 'Disable 2FA' : '2단계 인증 해제'; ?></button>
                            </div>
                            
                            <div id="twofa-setup-section" style="display:none;">
                                <h5><?php echo $currentLang === 'en' ? '1. Scan the QR code with your authenticator app' : '1. 인증 앱에서 QR 코드를 스캔하세요'; ?></h5>
                                <div class="qr-code-area" style="text-align:center; margin:20px 0;">
                                    <div id="twofa-qr-code" style="display:inline-block; border:1px solid #ddd; padding:10px; background:#fff; border-radius:8px;"></div>
                                </div>
                                <p style="text-align:center; color:#666; font-size:12px;"><?php echo $currentLang === 'en' ? 'If you cannot scan the QR code, enter the key below manually:' : 'QR 코드를 스캔할 수 없는 경우 아래 키를 수동으로 입력하세요:'; ?></p>
                                <div class="secret-key-area" style="text-align:center; margin:10px 0;">
                                    <code id="twofa-secret-key" style="font-size:16px; letter-spacing:2px; padding:10px; background:#f5f5f5; display:inline-block;"></code>
                                </div>
                                
                                <h5 style="margin-top:20px;"><?php echo $currentLang === 'en' ? '2. Enter the 6-digit code from your authenticator app' : '2. 인증 앱에 표시된 6자리 코드를 입력하세요'; ?></h5>
                                <div class="form-group" style="max-width:200px; margin:10px auto;">
                                    <input type="text" id="twofa-verify-code" placeholder="000000" maxlength="6" 
                                           inputmode="numeric" pattern="[0-9]*"
                                           style="text-align:center; font-size:24px; letter-spacing:5px;"
                                           oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                </div>
                                <div style="text-align:center;">
                                    <button class="btn btn-primary" id="btn-twofa-verify"><?php echo $currentLang === 'en' ? 'Verify & Enable' : '확인 및 활성화'; ?></button>
                                    <button class="btn" id="btn-twofa-cancel"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                                </div>
                            </div>
                            
                            <div id="twofa-backup-codes-section" style="display:none; margin-top:15px;">
                                <div class="alert alert-info">
                                    <strong><?php echo $currentLang === 'en' ? '📋 Backup Codes' : '📋 백업 코드'; ?></strong>
                                    <p><?php echo $currentLang === 'en' ? 'Keep these codes safe. Use them to login when authenticator app is unavailable.' : '이 코드들을 안전한 곳에 보관하세요. 인증 앱을 사용할 수 없을 때 로그인에 사용할 수 있습니다.'; ?></p>
                                    <p style="color:#c00;"><strong><?php echo $currentLang === 'en' ? '⚠️ Each code can only be used once.' : '⚠️ 각 코드는 한 번만 사용할 수 있습니다.'; ?></strong></p>
                                </div>
                                <div id="twofa-backup-codes-list" class="backup-codes-list" style="display:grid; grid-template-columns:repeat(2, 1fr); gap:10px; max-width:300px; margin:20px auto;"></div>
                                <div style="text-align:center; margin-top:20px;">
                                    <button class="btn btn-primary" id="btn-twofa-backup-done"><?php echo $currentLang === 'en' ? 'Confirm' : '확인'; ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 앱 비밀번호 탭 -->
                    <div id="tab-app-passwords" class="tab-content" style="display:none;">
                        <h4>🔑 <?php echo $currentLang === 'en' ? 'App Passwords' : '앱 비밀번호'; ?></h4>
                        <p class="text-muted" style="margin-bottom:10px;"><?php echo $currentLang === 'en' ? 'App passwords are used for WebDAV, network drives, and other clients that cannot use 2FA.' : '앱 비밀번호는 WebDAV, 네트워크 드라이브, TV 등 2단계 인증을 사용할 수 없는 클라이언트에서 사용합니다.'; ?></p>
                        <div style="padding:10px 12px;background:rgba(255,152,0,0.08);border:1px solid rgba(255,152,0,0.2);border-radius:6px;margin-bottom:15px;font-size:12px;color:#666;">
                            <p style="margin:0 0 8px;font-weight:600;color:#e65100;">⚠️ <?php echo $currentLang === 'en' ? 'Please keep your app password safe' : '앱 비밀번호를 안전하게 보관하세요'; ?></p>
                            <ul style="margin:0;padding-left:18px;line-height:2.0;">
                            <?php if ($currentLang === 'en'): ?>
                                <li>App passwords are shown <b>only once</b> when created and <b>cannot be retrieved</b> later.</li>
                                <li>Windows Explorer (WebDAV) has a known issue of <b>repeatedly asking for passwords</b> due to Basic Auth limitations. You may need to re-enter the password after reboot or timeout.</li>
                                <li><b>Copy and store</b> the password in a safe place (e.g. password manager) when you create it.</li>
                            <?php else: ?>
                                <li>앱 비밀번호는 생성 시 <b>한 번만 표시</b>되며 이후에는 <b>다시 확인할 수 없습니다</b>.</li>
                                <li>Windows 탐색기(WebDAV)는 Basic Auth 제한으로 <b>비밀번호를 반복 요청</b>하는 알려진 문제가 있습니다.<br>재부팅이나 시간 경과 후 다시 입력해야 할 수 있습니다.</li>
                                <li>생성 시 반드시 비밀번호를 <b>복사하여 안전한 곳에 보관</b>하세요 (메모장, 비밀번호 관리자 등).</li>
                            <?php endif; ?>
                            </ul>
                        </div>
                        
                        <div style="display:flex;gap:8px;margin-bottom:15px;flex-wrap:wrap;">
                            <input type="text" id="app-password-label" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Label (e.g. Windows PC, TV)' : '라벨 (예: 윈도우 PC, TV)'; ?>" style="flex:1;min-width:150px;">
                            <button class="btn btn-primary" id="btn-create-app-password"><?php echo $currentLang === 'en' ? '➕ Generate' : '➕ 생성'; ?></button>
                        </div>
                        
                        <!-- 생성된 비밀번호 표시 (한 번만) -->
                        <div id="app-password-created" style="display:none;margin-bottom:15px;padding:15px;background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;">
                            <div style="font-weight:600;margin-bottom:8px;"><?php echo $currentLang === 'en' ? '✅ App password created! Copy it now — it won\'t be shown again.' : '✅ 앱 비밀번호가 생성되었습니다! 지금 복사하세요 — 다시 볼 수 없습니다.'; ?></div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <code id="app-password-value" style="font-size:18px;letter-spacing:2px;padding:8px 12px;background:#fff;border-radius:4px;border:1px solid #ccc;user-select:all;"></code>
                                <button class="btn btn-sm" id="btn-copy-app-password" style="white-space:nowrap;">📋 <?php echo $currentLang === 'en' ? 'Copy' : '복사'; ?></button>
                            </div>
                        </div>
                        
                        <!-- 목록 -->
                        <div id="app-passwords-list" style="margin-top:10px;">
                            <div class="text-muted" style="text-align:center;padding:20px;"><?php echo $currentLang === 'en' ? 'Loading...' : '로딩 중...'; ?></div>
                        </div>
                    </div>
                    
                    <!-- 테마 탭 -->
                    <div id="tab-theme" class="tab-content" style="display:none;">
                        <h4>🎨 <?php _e('theme'); ?></h4>
                        <div class="theme-grid">
                            <div class="theme-item" data-theme="default">
                                <div class="theme-preview theme-preview-default"></div>
                                <span><?php echo $currentLang === 'en' ? 'Default' : '기본'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="dark">
                                <div class="theme-preview theme-preview-dark"></div>
                                <span><?php echo $currentLang === 'en' ? 'Dark' : '다크'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="blue">
                                <div class="theme-preview theme-preview-blue"></div>
                                <span><?php echo $currentLang === 'en' ? 'Blue' : '블루'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="mint">
                                <div class="theme-preview theme-preview-mint"></div>
                                <span><?php echo $currentLang === 'en' ? 'Mint' : '민트'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="rose">
                                <div class="theme-preview theme-preview-rose"></div>
                                <span><?php echo $currentLang === 'en' ? 'Rose' : '로즈'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="blue-full">
                                <div class="theme-preview theme-preview-blue-full"></div>
                                <span><?php echo $currentLang === 'en' ? 'Blue Full' : '블루 전체'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="mint-full">
                                <div class="theme-preview theme-preview-mint-full"></div>
                                <span><?php echo $currentLang === 'en' ? 'Mint Full' : '민트 전체'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="rose-full">
                                <div class="theme-preview theme-preview-rose-full"></div>
                                <span><?php echo $currentLang === 'en' ? 'Rose Full' : '로즈 전체'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="lavender">
                                <div class="theme-preview theme-preview-lavender"></div>
                                <span><?php echo $currentLang === 'en' ? 'Lavender' : '라벤더'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="peach">
                                <div class="theme-preview theme-preview-peach"></div>
                                <span><?php echo $currentLang === 'en' ? 'Peach' : '피치'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="sky">
                                <div class="theme-preview theme-preview-sky"></div>
                                <span><?php echo $currentLang === 'en' ? 'Sky' : '스카이'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="lavender-full">
                                <div class="theme-preview theme-preview-lavender-full"></div>
                                <span><?php echo $currentLang === 'en' ? 'Lavender Full' : '라벤더 전체'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="peach-full">
                                <div class="theme-preview theme-preview-peach-full"></div>
                                <span><?php echo $currentLang === 'en' ? 'Peach Full' : '피치 전체'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="sky-full">
                                <div class="theme-preview theme-preview-sky-full"></div>
                                <span><?php echo $currentLang === 'en' ? 'Sky Full' : '스카이 전체'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="pink">
                                <div class="theme-preview theme-preview-pink"></div>
                                <span><?php echo $currentLang === 'en' ? 'Pink' : '핑크'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="pink-full">
                                <div class="theme-preview theme-preview-pink-full"></div>
                                <span><?php echo $currentLang === 'en' ? 'Pink Full' : '핑크 전체'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="pastel-blue">
                                <div class="theme-preview theme-preview-pastel-blue"></div>
                                <span><?php echo $currentLang === 'en' ? 'Pastel Blue' : '파스텔 블루'; ?></span>
                            </div>
                            <div class="theme-item" data-theme="pastel-blue-full">
                                <div class="theme-preview theme-preview-pastel-blue-full"></div>
                                <span><?php echo $currentLang === 'en' ? 'Pastel Blue Full' : '파스텔 블루 전체'; ?></span>
                            </div>
                        </div>
                        
                        <h4 style="margin-top:25px;"><?php echo $currentLang === 'en' ? '🕐 Clock Style' : '🕐 시계 스타일'; ?></h4>
                        <div class="clock-style-grid">
                            <div class="clock-style-item" data-clock="1">
                                <div class="clock-style-preview style-1">
                                    <span class="preview-time">16:36</span>
                                    <span class="preview-date">02.04</span>
                                </div>
                                <span><?php echo $currentLang === 'en' ? 'Minimal' : '미니멀'; ?></span>
                            </div>
                            <div class="clock-style-item" data-clock="2">
                                <div class="clock-style-preview style-2">
                                    <span class="preview-time">16:36</span>
                                    <span class="preview-sep">|</span>
                                    <span class="preview-date">02.04</span>
                                </div>
                                <span><?php echo $currentLang === 'en' ? 'Card' : '카드'; ?></span>
                            </div>
                            <div class="clock-style-item" data-clock="3">
                                <div class="clock-style-preview style-3">
                                    <span class="preview-digit-box">1</span>
                                    <span class="preview-digit-box">6</span>
                                    <span class="preview-colon">:</span>
                                    <span class="preview-digit-box">3</span>
                                    <span class="preview-digit-box">6</span>
                                </div>
                                <span><?php echo $currentLang === 'en' ? 'Segment' : '세그먼트'; ?></span>
                            </div>
                            <div class="clock-style-item" data-clock="4">
                                <div class="clock-style-preview style-4">
                                    <span class="preview-led">16:36:41</span>
                                </div>
                                <span>LED</span>
                            </div>
                            <div class="clock-style-item" data-clock="5">
                                <div class="clock-style-preview style-5">
                                    <span class="pv-flip">1</span>
                                    <span class="pv-flip">6</span>
                                    <span class="pv-fc">:</span>
                                    <span class="pv-flip">3</span>
                                    <span class="pv-flip">6</span>
                                </div>
                                <span><?php echo $currentLang === 'en' ? 'Flip A' : '플립 A'; ?></span>
                            </div>
                            <div class="clock-style-item" data-clock="6">
                                <div class="clock-style-preview style-6">
                                    <span class="pv-flip">1</span>
                                    <span class="pv-flip">6</span>
                                    <span class="pv-fc">:</span>
                                    <span class="pv-flip">3</span>
                                    <span class="pv-flip">6</span>
                                </div>
                                <span><?php echo $currentLang === 'en' ? 'Flip B' : '플립 B'; ?></span>
                            </div>
                            <div class="clock-style-item" data-clock="7">
                                <div class="clock-style-preview style-7">
                                    <span class="pv-flip">1</span>
                                    <span class="pv-flip">6</span>
                                    <span class="pv-fc">:</span>
                                    <span class="pv-flip">3</span>
                                    <span class="pv-flip">6</span>
                                </div>
                                <span><?php echo $currentLang === 'en' ? 'Flip C' : '플립 C'; ?></span>
                            </div>
                            <div class="clock-style-item" data-clock="8">
                                <div class="clock-style-preview style-8">
                                    <span class="pv-flip">1</span>
                                    <span class="pv-flip">6</span>
                                    <span class="pv-fc">:</span>
                                    <span class="pv-flip">3</span>
                                    <span class="pv-flip">6</span>
                                </div>
                                <span><?php echo $currentLang === 'en' ? 'Flip D' : '플립 D'; ?></span>
                            </div>
                            <div class="clock-style-item" data-clock="9">
                                <div class="clock-style-preview style-9">
                                    <span class="preview-glass">16:36</span>
                                </div>
                                <span><?php echo $currentLang === 'en' ? 'Glass' : '글래스'; ?></span>
                            </div>
                            <div class="clock-style-item" data-clock="10">
                                <div class="clock-style-preview style-10">
                                    <span class="preview-nixie">16:36</span>
                                </div>
                                <span><?php echo $currentLang === 'en' ? 'Nixie' : '닉시관'; ?></span>
                            </div>
                            <div class="clock-style-item" data-clock="0">
                                <div class="clock-style-preview style-off">
                                    <span class="preview-off">OFF</span>
                                </div>
                                <span><?php echo $currentLang === 'en' ? 'Off' : '끄기'; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 세션 관리 탭 -->
                    <div id="tab-sessions" class="tab-content" style="display:none;">
                        <div class="session-header">
                            <h4><?php echo $currentLang === 'en' ? '📱 Active Sessions' : '📱 활성 세션'; ?></h4>
                            <button class="btn btn-danger btn-sm" id="btn-terminate-all"><?php echo $currentLang === 'en' ? 'Logout All Devices' : '모든 기기 로그아웃'; ?></button>
                        </div>
                        <div id="sessions-list" class="sessions-list">
                            <div class="loading"><?php echo $currentLang === 'en' ? 'Loading...' : '로딩 중...'; ?></div>
                        </div>
                    </div>
                    
                    <!-- 로그인 로그 탭 -->
                    <div id="tab-login-logs" class="tab-content" style="display:none;">
                        <h4><?php echo $currentLang === 'en' ? '📋 Recent Login History' : '📋 최근 로그인 기록'; ?></h4>
                        <div id="login-logs-list" class="login-logs-list">
                            <div class="loading"><?php echo $currentLang === 'en' ? 'Loading...' : '로딩 중...'; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 시스템 설정 모달 (관리자) -->
            <div id="modal-system-settings" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>🔧 <?php echo $currentLang === 'en' ? 'System Settings' : '시스템 설정'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="system-settings">
                        <h3><?php echo $currentLang === 'en' ? 'General Settings' : '일반 설정'; ?></h3>
                        <div class="setting-item">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-signup-enabled">
                                <span><?php echo $currentLang === 'en' ? 'Allow Registration' : '회원가입 허용'; ?></span>
                            </label>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'When enabled, registration is available on the login screen.' : '활성화하면 로그인 화면에서 회원가입이 가능합니다.'; ?></p>
                        </div>
                        <div class="setting-item" id="auto-approve-wrap" style="display:none; margin-left: 20px;">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-auto-approve">
                                <span><?php echo $currentLang === 'en' ? 'Auto Approve' : '자동 승인'; ?></span>
                            </label>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'When enabled, users can login immediately. When disabled, admin approval is required.' : '활성화하면 가입 즉시 로그인할 수 있습니다. 비활성화하면 관리자 승인이 필요합니다.'; ?></p>
                        </div>
                        <div class="setting-item">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-home-share">
                                <span><?php echo $currentLang === 'en' ? 'Allow External Sharing' : '개인 폴더 외부 공유 허용'; ?></span>
                            </label>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'When disabled, users cannot share personal folder files via external links.' : '비활성화하면 사용자가 개인 폴더의 파일을 외부 링크로 공유할 수 없습니다.'; ?></p>
                        </div>
                        <div class="setting-item">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-password-reset">
                                <span><?php echo $currentLang === 'en' ? 'Allow Password Recovery' : '아이디/비밀번호 찾기 허용'; ?></span>
                            </label>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'When disabled, password recovery is hidden on login screen. (Requires SMTP)' : '비활성화하면 로그인 화면에서 아이디/비밀번호 찾기 기능이 숨겨집니다. (SMTP 설정 필요)'; ?></p>
                        </div>
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Session Timeout (minutes)' : '세션 유지 시간 (분)'; ?></label>
                            <select id="setting-session-timeout" class="form-control" style="width:auto;min-width:150px;">
                                <option value="30">30<?php echo $currentLang === 'en' ? ' min' : '분'; ?></option>
                                <option value="60">1<?php echo $currentLang === 'en' ? ' hour' : '시간'; ?></option>
                                <option value="120">2<?php echo $currentLang === 'en' ? ' hours' : '시간'; ?></option>
                                <option value="240">4<?php echo $currentLang === 'en' ? ' hours' : '시간'; ?></option>
                                <option value="480">8<?php echo $currentLang === 'en' ? ' hours' : '시간'; ?></option>
                                <option value="1440">24<?php echo $currentLang === 'en' ? ' hours' : '시간'; ?></option>
                                <option value="0"><?php echo $currentLang === 'en' ? 'No limit' : '제한 없음'; ?></option>
                            </select>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Auto logout after inactivity for the specified time. Default: 1 hour.' : '지정된 시간 동안 활동이 없으면 자동 로그아웃됩니다. 기본값: 1시간'; ?></p>
                        </div>
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Max Concurrent Jobs per User' : '사용자당 최대 동시 작업 수'; ?></label>
                            <select id="setting-max-concurrent-jobs" class="form-control" style="width:auto;min-width:150px;">
                                <option value="3">3</option>
                                <option value="5">5</option>
                                <option value="10">10</option>
                                <option value="20">20</option>
                                <option value="0"><?php echo $currentLang === 'en' ? 'No limit' : '제한 없음'; ?></option>
                            </select>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Limits simultaneous copy/move/delete jobs per user. Default: 5' : '사용자별 동시 복사/이동/삭제 작업 수를 제한합니다. 기본값: 5'; ?></p>
                        </div>
                        
                        <h3><?php echo $currentLang === 'en' ? 'Storage Path Settings' : '스토리지 경로 설정'; ?></h3>
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Personal Folder Root Path' : '개인폴더 루트 경로'; ?></label>
                            <input type="text" id="setting-user-files-root" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Leave empty for default' : '비워두면 기본값 사용'; ?>">
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Location where user personal folders are stored. (e.g.: E:\\WebHard\\users or /mnt/data/users)' : '사용자별 개인폴더가 저장되는 위치입니다. (예: E:\\WebHard\\users 또는 /mnt/data/users)'; ?></p>
                            <p class="setting-desc current-path" id="current-user-path"></p>
                        </div>
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Shared Folder Root Path' : '공유폴더 루트 경로'; ?></label>
                            <input type="text" id="setting-shared-files-root" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Leave empty for default' : '비워두면 기본값 사용'; ?>">
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Location where shared folders are stored. (e.g.: E:\\WebHard\\shared or /mnt/data/shared)' : '공유폴더가 저장되는 위치입니다. (예: E:\\WebHard\\shared 또는 /mnt/data/shared)'; ?></p>
                            <p class="setting-desc current-path" id="current-shared-path"></p>
                        </div>
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Trash Path' : '휴지통 경로'; ?></label>
                            <input type="text" id="setting-trash-path" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Leave empty for default' : '비워두면 기본값 사용'; ?>">
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Location where deleted files are stored. (e.g.: E:\\WebHard\\trash or /mnt/data/trash)' : '삭제된 파일이 저장되는 위치입니다. (예: E:\\WebHard\\trash 또는 /mnt/data/trash)'; ?></p>
                            <p class="setting-desc current-path" id="current-trash-path"></p>
                        </div>
                        <div class="setting-notice">
                            <p>⚠️ <strong><?php echo $currentLang === 'en' ? 'Notice' : '주의사항'; ?></strong></p>
                            <ul>
                                <li><?php echo $currentLang === 'en' ? 'Existing files are not automatically moved when changing paths.' : '경로 변경 시 기존 파일은 자동으로 이동되지 않습니다.'; ?></li>
                                <li><?php echo $currentLang === 'en' ? 'Manually move existing files to new path before changing.' : '변경 전 기존 파일을 새 경로로 직접 이동해주세요.'; ?></li>
                                <li><?php echo $currentLang === 'en' ? 'Refresh page after saving to apply changes.' : '저장 후 페이지를 새로고침해야 적용됩니다.'; ?></li>
                            </ul>
                        </div>
                        
                        <h3><?php echo $currentLang === 'en' ? 'External Access Settings' : '외부 접속 설정'; ?></h3>
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'External URL (for sharing)' : '외부 접속 URL (공유 링크용)'; ?></label>
                            <input type="text" id="setting-external-url" class="form-control" placeholder="https://mynas.example.com">
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'External URL for share links. Leave empty to use current URL.' : '공유 링크 생성 시 사용할 외부 URL입니다. 내부망(192.168.x.x)에서 접속해도 이 주소로 공유 링크가 생성됩니다. 비워두면 현재 접속 주소를 사용합니다.'; ?></p>
                        </div>
                        
                        <h3><?php echo $currentLang === 'en' ? '🔗 WebDAV Server Settings' : '🔗 WebDAV 서버 설정'; ?></h3>
                        <p class="setting-desc" style="margin-bottom:10px;"><?php echo $currentLang === 'en' ? 'Server-side settings for WebDAV. Users can access WebDAV from the sidebar menu.' : 'WebDAV 서버 측 설정입니다. 사용자는 사이드바 WebDAV 메뉴에서 접속 안내를 확인할 수 있습니다.'; ?></p>
                        <p class="setting-desc" style="margin-bottom:10px;color:#e65100;font-size:12px;">💡 <?php echo $currentLang === 'en' ? 'The sidebar WebDAV menu is only visible to users who have the <b>WebDAV Visible</b> permission enabled in Storage Permissions.' : '사이드바 WebDAV 메뉴는 <b>스토리지 권한 설정</b>에서 해당 사용자에게 <b>WebDAV 표시</b> 권한을 활성화해야 보입니다.'; ?></p>
                        
                        <div class="setting-notice" style="margin-top:10px;">
                            <p><strong><?php echo $currentLang === 'en' ? '⚙️ Apache Settings (Required for large files)' : '⚙️ Apache 설정 (대용량 파일 전송 시 필수)'; ?></strong></p>
                            <ul style="margin:4px 0 8px 20px; font-size:12px;">
                                <li><?php echo $currentLang === 'en' ? 'Add to site VirtualHost (modify path to match installation):' : '해당 사이트 VirtualHost에 추가 (경로는 설치 위치에 맞게 수정):'; ?>
                                    <div style="background:rgba(128,128,128,0.15); border:1px solid rgba(128,128,128,0.3); border-radius:4px; padding:6px 10px; margin:4px 0; font-family:monospace; font-size:11px;">
                                        &lt;LocationMatch "^/mydav\.php"&gt;<br>
                                        &nbsp;&nbsp;&nbsp;&nbsp;LimitRequestBody 0<br>
                                        &lt;/LocationMatch&gt;
                                    </div>
                                </li>
                                <li><?php echo $currentLang === 'en' ? 'If using ModSecurity, add inside the LocationMatch above:' : 'ModSecurity 사용 시 위 LocationMatch 안에 추가:'; ?>
                                    <div style="background:rgba(128,128,128,0.15); border:1px solid rgba(128,128,128,0.3); border-radius:4px; padding:6px 10px; margin:4px 0; font-family:monospace; font-size:11px;">
                                        &nbsp;&nbsp;&nbsp;&nbsp;SecRuleEngine Off
                                    </div>
                                </li>
                            </ul>
                            
                            <hr style="margin:12px 0;border:none;border-top:1px solid #ddd;">
                            <p><strong><?php echo $currentLang === 'en' ? '⚙️ Nginx Settings (Synology, etc.)' : '⚙️ Nginx 설정 (Synology 등)'; ?></strong></p>
                            <ul style="margin:4px 0 8px 20px; font-size:12px;">
                                <li><?php echo $currentLang === 'en' 
                                    ? 'Nginx must pass the Authorization header to PHP-FPM for WebDAV authentication. Add to your server block:' 
                                    : 'Nginx는 WebDAV 인증을 위해 Authorization 헤더를 PHP-FPM에 전달해야 합니다. server 블록에 추가하세요:'; ?>
                                    <div style="background:rgba(128,128,128,0.15); border:1px solid rgba(128,128,128,0.3); border-radius:4px; padding:6px 10px; margin:4px 0; font-family:monospace; font-size:11px;">
                                        location = /mydav.php {<br>
                                        &nbsp;&nbsp;&nbsp;&nbsp;include fastcgi_params;<br>
                                        &nbsp;&nbsp;&nbsp;&nbsp;fastcgi_param SCRIPT_FILENAME $document_root/mydav.php;<br>
                                        &nbsp;&nbsp;&nbsp;&nbsp;fastcgi_param HTTP_AUTHORIZATION $http_authorization;<br>
                                        &nbsp;&nbsp;&nbsp;&nbsp;fastcgi_pass unix:/run/php/php-fpm.sock;<br>
                                        &nbsp;&nbsp;&nbsp;&nbsp;fastcgi_read_timeout 3600s;<br>
                                        &nbsp;&nbsp;&nbsp;&nbsp;client_max_body_size 0;<br>
                                        }
                                    </div>
                                </li>
                                <li><?php echo $currentLang === 'en' 
                                    ? 'Synology: <b>Control Panel → Web Station → Web Service Portal → Edit → Custom Nginx Config</b>' 
                                    : 'Synology: <b>제어판 → Web Station → 웹 서비스 포털 → 편집 → 사용자 정의 Nginx 설정</b>'; ?></li>
                                <li><?php echo $currentLang === 'en'
                                    ? 'If folder listing is empty, check that <code>fastcgi_param HTTP_AUTHORIZATION</code> is included in your config.'
                                    : '폴더 내용이 표시되지 않으면 <code>fastcgi_param HTTP_AUTHORIZATION</code> 설정이 포함되어 있는지 확인하세요.'; ?></li>
                            </ul>
                            
                            <hr style="margin:12px 0;border:none;border-top:1px solid #ddd;">
                            <p><strong><?php echo $currentLang === 'en' ? '🔒 Brute Force Protection' : '🔒 브루트포스 방지'; ?></strong></p>
                            <p style="font-size:13px;"><?php echo $currentLang === 'en' 
                                ? 'WebDAV login attempts are tracked in <code>data/webdav_attempts/</code>. After 5 failures, the IP+username is locked for 15 minutes. Delete JSON files in this folder to unlock immediately.' 
                                : 'WebDAV 로그인 시도는 <code>data/webdav_attempts/</code>에 기록됩니다. 5회 실패 시 해당 IP+계정이 15분간 잠금됩니다. 즉시 해제하려면 이 폴더의 JSON 파일을 삭제하세요.'; ?></p>
                        </div>
                        
                        <h3><?php echo $currentLang === 'en' ? 'Login Screen Settings' : '로그인 화면 설정'; ?></h3>
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Site Name' : '사이트 이름'; ?></label>
                            <input type="text" id="setting-site-name" class="form-control" placeholder="Cloud Storage">
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Site name displayed on login screen and header.' : '로그인 화면과 상단에 표시되는 사이트 이름입니다.'; ?></p>
                        </div>
                        
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Copyright' : '카피라이트'; ?></label>
                            <input type="text" id="setting-copyright" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'e.g. © 2026 Company Name. All rights reserved.' : '예: © 2026 회사명. All rights reserved.'; ?>">
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Copyright text displayed at the bottom of the login screen.' : '로그인 화면 하단에 표시되는 카피라이트 문구입니다.'; ?></p>
                        </div>
                        
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Logo Image' : '로고 이미지'; ?></label>
                            <div class="image-upload-wrap">
                                <div id="logo-preview" class="image-preview">
                                    <span class="no-image">📁</span>
                                </div>
                                <div class="image-upload-actions">
                                    <input type="file" id="logo-upload" accept="image/*" style="display:none;">
                                    <button type="button" class="btn btn-sm" onclick="document.getElementById('logo-upload').click()"><?php echo $currentLang === 'en' ? 'Select Image' : '이미지 선택'; ?></button>
                                    <button type="button" class="btn btn-sm btn-danger" id="btn-logo-delete" style="display:none;"><?php echo $currentLang === 'en' ? 'Delete' : '삭제'; ?></button>
                                </div>
                            </div>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Logo image displayed on login screen. (Recommended: 128x128px)' : '로그인 화면에 표시되는 로고 이미지입니다. (권장: 128x128px)'; ?></p>
                        </div>
                        
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Login Background Image' : '로그인 배경 이미지'; ?></label>
                            <div class="image-upload-wrap">
                                <div id="bg-preview" class="image-preview bg-preview">
                                    <span class="no-image">🖼️</span>
                                </div>
                                <div class="image-upload-actions">
                                    <input type="file" id="bg-upload" accept="image/*" style="display:none;">
                                    <button type="button" class="btn btn-sm" onclick="document.getElementById('bg-upload').click()"><?php echo $currentLang === 'en' ? 'Select Image' : '이미지 선택'; ?></button>
                                    <button type="button" class="btn btn-sm btn-danger" id="btn-bg-delete" style="display:none;"><?php echo $currentLang === 'en' ? 'Delete' : '삭제'; ?></button>
                                </div>
                            </div>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Background image for login screen. (Recommended: 1920x1080px)' : '로그인 화면의 배경 이미지입니다. (권장: 1920x1080px)'; ?></p>
                        </div>

                        <div class="setting-item" id="bg-fit-settings" style="display:none;">
                            <label><?php echo $currentLang === 'en' ? 'Background Fit' : '배경 이미지 배치'; ?></label>
                            <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:8px;">
                                <?php
                                $fitOptions = [
                                    'cover'   => ['ko'=>'채우기',   'en'=>'Fill',    'icon'=>'⬛'],
                                    'contain' => ['ko'=>'맞춤',     'en'=>'Fit',     'icon'=>'🔲'],
                                    'fill'    => ['ko'=>'확대',     'en'=>'Stretch', 'icon'=>'↔️'],
                                    'tile'    => ['ko'=>'바둑판식', 'en'=>'Tile',    'icon'=>'▦'],
                                    'center'  => ['ko'=>'가운데',   'en'=>'Center',  'icon'=>'🎯'],
                                    'span'    => ['ko'=>'스팬',     'en'=>'Span',    'icon'=>'↕️'],
                                ];
                                foreach ($fitOptions as $val => $opt):
                                    $label = $currentLang === 'en' ? $opt['en'] : $opt['ko'];
                                ?>
                                <button type="button" class="bg-fit-btn" data-fit="<?= $val ?>"
                                    style="padding:6px 14px;border:2px solid var(--border-color,#ddd);border-radius:6px;background:var(--bg-secondary,#f8f9fa);cursor:pointer;font-size:13px;transition:all .15s;">
                                    <?= $opt['icon'] ?> <?= $label ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" id="bg-fit" value="cover">
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'How the background image is displayed.' : '배경 이미지를 화면에 표시하는 방식입니다.'; ?></p>
                        </div>
                        
                        <div class="setting-item" id="bg-filter-settings">
                            <label><?php echo $currentLang === 'en' ? 'Background Image Filter' : '배경 이미지 필터'; ?></label>
                            <div class="bg-filter-presets" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-top:10px;">
                                <button type="button" class="filter-preset" data-filter="none">
                                    <span class="filter-icon">🌟</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Original' : '원본'; ?></span>
                                </button>
                                <button type="button" class="filter-preset" data-filter="dark">
                                    <span class="filter-icon">🌙</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Dark' : '어둡게'; ?></span>
                                </button>
                                <button type="button" class="filter-preset" data-filter="blur">
                                    <span class="filter-icon">🌫️</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Blur' : '흐림'; ?></span>
                                </button>
                                <button type="button" class="filter-preset" data-filter="cinema">
                                    <span class="filter-icon">🎬</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Cinema' : '시네마'; ?></span>
                                </button>
                                <button type="button" class="filter-preset" data-filter="purple">
                                    <span class="filter-icon">💜</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Purple' : '퍼플'; ?></span>
                                </button>
                                <button type="button" class="filter-preset" data-filter="blue">
                                    <span class="filter-icon">💙</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Blue' : '블루'; ?></span>
                                </button>
                                <button type="button" class="filter-preset" data-filter="warm">
                                    <span class="filter-icon">🧡</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Warm' : '따뜻하게'; ?></span>
                                </button>
                                <button type="button" class="filter-preset" data-filter="bw">
                                    <span class="filter-icon">⚫</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Grayscale' : '흑백'; ?></span>
                                </button>
                                <button type="button" class="filter-preset" data-filter="vintage">
                                    <span class="filter-icon">📷</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Vintage' : '빈티지'; ?></span>
                                </button>
                                <button type="button" class="filter-preset" data-filter="cool">
                                    <span class="filter-icon">❄️</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Cool' : '쿨톤'; ?></span>
                                </button>
                                <button type="button" class="filter-preset" data-filter="sunset">
                                    <span class="filter-icon">🌅</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Sunset' : '석양'; ?></span>
                                </button>
                                <button type="button" class="filter-preset" data-filter="neon">
                                    <span class="filter-icon">🌈</span>
                                    <span class="filter-name"><?php echo $currentLang === 'en' ? 'Neon' : '네온'; ?></span>
                                </button>
                            </div>
                            <input type="hidden" id="bg-filter-preset" value="none">
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Select filter to apply to background image.' : '배경 이미지에 적용할 필터를 선택하세요.'; ?></p>
                        </div>
                        
                        <h3><?php echo $currentLang === 'en' ? '🖼️ Thumbnail Settings' : '🖼️ 썸네일 설정'; ?></h3>
                        <div class="setting-item">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-thumbnail-enabled" checked>
                                <span><?php echo $currentLang === 'en' ? 'Enable Thumbnails' : '썸네일 사용'; ?></span>
                            </label>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Show thumbnails in grid view instead of generic file icons. Supported: images (JPG/PNG/GIF/WebP/BMP), videos (MP4/MKV/WMV/AVI/MOV/WebM/FLV/TS/MPG etc.), PDF, and audio cover art (MP3 ID3v2 embedded artwork).' : '그리드 뷰에서 파일 아이콘 대신 썸네일을 표시합니다. 지원 형식: 이미지(JPG/PNG/GIF/WebP/BMP), 동영상(MP4/MKV/WMV/AVI/MOV/WebM/FLV/TS/MPG 등), PDF, 오디오 앨범 커버(MP3 ID3v2 내장 이미지).'; ?></p>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? '⚠️ Disabling this saves CPU/disk when browsing folders with many video files (thumbnail generation requires ffmpeg).' : '⚠️ 동영상이 많은 폴더를 탐색할 때 썸네일 생성 부하를 줄이려면 끄는 것도 방법입니다(동영상 썸네일 생성에 ffmpeg 사용).'; ?></p>
                        </div>
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Thumbnail Size' : '썸네일 크기'; ?></label>
                            <select id="setting-thumbnail-size" class="form-control" style="width:auto;">
                                <option value="100">100px</option>
                                <option value="150">150px</option>
                                <option value="200" selected>200px (<?php echo $currentLang === 'en' ? 'Default' : '기본값'; ?>)</option>
                                <option value="300">300px</option>
                                <option value="400">400px</option>
                            </select>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Thumbnail resolution. Larger sizes give better quality but use more bandwidth.' : '썸네일 해상도입니다. 클수록 선명하지만 트래픽이 증가합니다.'; ?></p>
                        </div>
                                                <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'ffmpeg / ffprobe Path' : 'ffmpeg / ffprobe 경로'; ?></label>
                            <div style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
                                <span style="font-size:12px; color:#888; min-width:60px;">ffmpeg</span>
                                <input type="text" id="setting-ffmpeg-path" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'e.g. C:\\ffmpeg\\bin\\ffmpeg.exe' : '예: C:\\ffmpeg\\bin\\ffmpeg.exe'; ?>" style="flex:1;">
                                <button type="button" class="btn btn-sm" onclick="App.testFfmpeg()"><?php echo $currentLang === 'en' ? 'Test' : '테스트'; ?></button>
                            </div>
                            <span id="ffmpeg-test-result" style="font-size:12px; margin-bottom:6px; display:block;"></span>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <span style="font-size:12px; color:#888; min-width:60px;">ffprobe</span>
                                <input type="text" id="setting-ffprobe-path" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'e.g. C:\\ffmpeg\\bin\\ffprobe.exe' : '예: C:\\ffmpeg\\bin\\ffprobe.exe'; ?>" style="flex:1;">
                                <button type="button" class="btn btn-sm" onclick="App.testFfprobe()"><?php echo $currentLang === 'en' ? 'Test' : '테스트'; ?></button>
                            </div>
                            <span id="ffprobe-test-result" style="font-size:12px; margin-top:4px; display:block;"></span>
                            <p class="setting-desc"><?php echo $currentLang === 'en' 
                                ? 'ffmpeg: Extracts video frame for thumbnail. ffprobe: Reads video duration/metadata. Both are included when you download ffmpeg.' 
                                : 'ffmpeg: 동영상에서 프레임을 추출하여 썸네일을 생성합니다. ffprobe: 동영상 길이/메타데이터를 읽습니다. ffmpeg 다운로드 시 함께 포함되어 있습니다.'; ?> <a href="https://www.gyan.dev/ffmpeg/builds/" target="_blank" style="font-size:12px;"><?php echo $currentLang === 'en' ? 'Download ffmpeg' : 'ffmpeg 다운로드'; ?> ↗</a></p>
                        </div>
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'PDF Tool Path' : 'PDF 도구 경로'; ?></label>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <select id="setting-pdf-tool" class="form-control" style="width:auto; min-width:120px;">
                                    <option value=""><?php echo $currentLang === 'en' ? 'Auto detect' : '자동 감지'; ?></option>
                                    <option value="pdftoppm">pdftoppm (Poppler)</option>
                                    <option value="mutool">mutool (MuPDF)</option>
                                    <option value="gs">Ghostscript (gs)</option>
                                    <option value="imagick">Imagick</option>
                                </select>
                                <input type="text" id="setting-pdf-tool-path" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Path (optional, e.g. /usr/bin/pdftoppm)' : '경로 (선택, 예: /usr/bin/pdftoppm)'; ?>" style="flex:1;">
                                <button type="button" class="btn btn-sm" onclick="App.testPdfTool()"><?php echo $currentLang === 'en' ? 'Test' : '테스트'; ?></button>
                            </div>
                            <span id="pdftool-test-result" style="font-size:12px; margin-top:4px; display:block;"></span>
                            <p class="setting-desc"><?php echo $currentLang === 'en' 
                                ? 'Required for PDF thumbnails. Priority: Imagick+Ghostscript → pdftoppm → mutool → gs. Leave empty to auto-detect.' 
                                : 'PDF 썸네일에 필요합니다. 우선순위: Imagick+Ghostscript → pdftoppm → mutool → gs. 비워두면 자동 감지합니다.'; ?></p>
                        </div>
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Server Capabilities' : '서버 환경'; ?></label>
                            <div id="thumb-capabilities" style="margin-top:5px;">
                                <span style="color:#888; font-size:13px;"><?php echo $currentLang === 'en' ? 'Loading...' : '확인 중...'; ?></span>
                            </div>
                        </div>
                        <div class="setting-item">
                            <button type="button" class="btn btn-sm btn-danger" id="btn-clear-thumbcache" onclick="App.clearThumbCache()">
                                <?php echo $currentLang === 'en' ? '🗑️ Clear Thumbnail Cache' : '🗑️ 썸네일 캐시 삭제'; ?>
                            </button>
                            <span id="thumbcache-status" style="margin-left:10px; font-size:13px; color:#888;"></span>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Delete all cached thumbnails. They will be regenerated on next view.' : '캐시된 썸네일을 모두 삭제합니다. 다음 조회 시 자동으로 다시 생성됩니다.'; ?></p>
                        </div>
                        
                        <h3>📦 <?php echo $currentLang === 'en' ? 'Archive Preview Settings' : '압축파일 미리보기 설정'; ?></h3>
                        <div class="setting-item">
                            <div id="archive-7zip-status" style="padding:10px 12px;border-radius:6px;margin-bottom:10px;font-size:13px;"></div>
                            <div id="archive-unrar-status" style="padding:10px 12px;border-radius:6px;margin-bottom:10px;font-size:13px;"></div>
                            <p class="setting-desc"><?php echo $currentLang === 'en' 
                                ? 'Archive files (zip, rar, 7z, iso, etc.) can be previewed to view internal file lists. ZIP and TAR formats work with PHP built-in. For other formats (RAR, 7Z, ISO, CAB, etc.), <b>7-Zip</b> must be installed on the server.'
                                : '압축 파일(zip, rar, 7z, iso 등)을 미리보기하여 내부 파일 목록을 확인할 수 있습니다. ZIP과 TAR 형식은 PHP 내장으로 동작합니다. 그 외 형식(RAR, 7Z, ISO, CAB 등)은 서버에 <b>7-Zip</b>이 설치되어야 합니다.'; ?></p>
                            <table style="font-size:12px;margin-top:8px;border-collapse:collapse;color:#666;">
                                <tr><td style="padding:3px 15px 3px 0;"><b>PHP <?php echo $currentLang === 'en' ? 'Built-in' : '내장'; ?></b></td><td>zip, tar, gz, tgz, bz2</td></tr>
                                <tr><td style="padding:3px 15px 3px 0;"><b>7-Zip <?php echo $currentLang === 'en' ? 'Required' : '필요'; ?></b></td><td>rar, 7z, iso, cab, wim, arj, lzh, dmg, msi <?php echo $currentLang === 'en' ? 'and more' : '외 다수'; ?></td></tr>
                                <tr><td style="padding:3px 15px 3px 0;"><b>UnRAR <?php echo $currentLang === 'en' ? 'Optional' : '선택'; ?></b></td><td><?php echo $currentLang === 'en' ? 'rar (handles rar files that 7-Zip cannot open)' : 'rar (7-Zip이 못 읽는 rar 파일 보완)'; ?></td></tr>
                            </table>
                            <div style="margin-top:10px;padding:8px 12px;background:rgba(33,150,243,0.06);border:1px solid rgba(33,150,243,0.15);border-radius:6px;font-size:12px;color:#666;">
                                <p style="margin:0 0 4px;font-weight:600;">💡 <?php echo $currentLang === 'en' ? '7-Zip Installation' : '7-Zip 설치 방법'; ?></p>
                                <p style="margin:0;">
                                    Windows: <a href="https://www.7-zip.org/download.html" target="_blank" style="color:#1976d2;">7-zip.org</a> <?php echo $currentLang === 'en' ? 'Download and install to default path' : '에서 다운로드 후 기본 경로에 설치'; ?><br>
                                    Linux: <code style="background:rgba(128,128,128,0.12);padding:1px 5px;border-radius:3px;">apt install 7zip</code> <?php echo $currentLang === 'en' ? 'or' : '또는'; ?> <code style="background:rgba(128,128,128,0.12);padding:1px 5px;border-radius:3px;">yum install p7zip-full</code>
                                </p>
                            </div>
                            <div style="margin-top:10px;padding:8px 12px;background:rgba(158,158,158,0.06);border:1px solid rgba(158,158,158,0.18);border-radius:6px;font-size:12px;color:#666;">
                                <p style="margin:0 0 4px;font-weight:600;">💡 <?php echo $currentLang === 'en' ? 'UnRAR Installation (optional)' : 'UnRAR 설치 방법 (선택)'; ?></p>
                                <p style="margin:0 0 4px;"><?php echo $currentLang === 'en' ? 'Only needed for rar files that 7-Zip cannot open.' : '7-Zip으로 목록이 안 보이는 일부 rar 파일에만 필요합니다.'; ?></p>
                                <p style="margin:0;">
                                    Windows: <a href="https://www.win-rar.com/download.html" target="_blank" style="color:#1976d2;">win-rar.com</a> <?php echo $currentLang === 'en' ? 'Install WinRAR (includes UnRAR.exe)' : '에서 WinRAR 설치 (UnRAR.exe 포함)'; ?><br>
                                    Linux: <code style="background:rgba(128,128,128,0.12);padding:1px 5px;border-radius:3px;">apt install unrar</code> <?php echo $currentLang === 'en' ? 'or' : '또는'; ?> <code style="background:rgba(128,128,128,0.12);padding:1px 5px;border-radius:3px;">yum install unrar</code>
                                </p>
                            </div>
                        </div>
                        
                        <h3><?php echo $currentLang === 'en' ? 'Search Index Settings' : '검색 인덱스 설정'; ?></h3>
                        <div class="setting-item">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-auto-index">
                                <span><?php echo $currentLang === 'en' ? 'Auto Update Index' : '인덱스 자동 갱신'; ?></span>
                            </label>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Periodically scans the file system to detect files added/changed/deleted <strong>outside the web UI</strong> (direct server access, FTP, network drive, external sync tools, etc.) and reflects them in the search index and file list.' : '<strong>웹 UI가 아닌 외부</strong>(서버에서 직접 접근, FTP, 네트워크 드라이브, 외부 동기화 도구 등)에서 파일이 추가/변경/삭제된 것을 주기적으로 감지하여 검색 인덱스와 파일 목록에 반영합니다.'; ?></p>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? '<strong>Not related to web UI file operations</strong> — uploads, deletes, renames, and moves done through the web UI are always reflected instantly regardless of this setting.' : '<strong>웹 UI에서의 파일 작업과는 무관</strong>합니다. 웹 UI로 업로드/삭제/이름변경/이동한 파일은 이 설정과 상관없이 항상 즉시 반영됩니다.'; ?></p>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? '⚠️ For environments with many files, disable this and use the manual "Rebuild Index" button instead.' : '⚠️ 파일이 매우 많은 환경에서는 비활성화하고 수동 "인덱스 재구축" 버튼을 사용하는 편이 서버 부담이 적습니다.'; ?></p>
                        </div>
                        
                        <h3><?php echo $currentLang === 'en' ? 'Background Scan Intervals' : '백그라운드 스캔 주기'; ?></h3>
                        <p class="setting-desc" style="margin-bottom:15px;"><?php echo $currentLang === 'en' ? 'These settings control periodic full-disk scans that run in the background. If the web UI occasionally freezes when opening folders, switching storages, or clicking a video/file to load it, increase these values to reduce scan frequency.' : '백그라운드에서 주기적으로 도는 디스크 전체 스캔의 주기입니다. 폴더 이동, 스토리지 전환, 동영상이나 파일 클릭해서 열 때 가끔 웹 UI가 멈추는 현상이 있으면 이 값을 늘려서 스캔 빈도를 낮춰 주세요.'; ?></p>
                        
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Storage Usage Recalculation Interval (minutes)' : '스토리지 사용량 재계산 주기 (분)'; ?></label>
                            <input type="number" id="setting-storage-recalc-interval" class="form-control" min="1" max="1440" placeholder="60" style="max-width:200px;">
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'How often to recalculate the "used size" of each storage by scanning all files. File uploads/deletes via the web UI are reflected instantly regardless of this setting — this interval only affects detection of changes made outside the web UI (direct file system changes, FTP, etc.).' : '각 스토리지의 "사용 용량"을 파일 전체 스캔으로 재계산하는 주기입니다. 웹 UI로 업로드/삭제하는 파일은 이 설정과 무관하게 즉시 반영되며, 이 주기는 웹 UI 외부에서의 변경(직접 파일 추가, FTP 등) 반영에만 영향을 줍니다.'; ?></p>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? '<strong>Recommended: 60 minutes</strong> (range: 1–1440). Smaller values give faster updates but increase server load. For large storages (10TB+) or slow disks, use 360 minutes (6 hours) or more.' : '<strong>권장: 60분</strong> (범위: 1–1440). 값을 줄이면 반영이 빠르지만 서버 부하가 늘어납니다. 용량이 크거나(10TB 이상) 느린 디스크라면 360분(6시간) 이상을 권장합니다.'; ?></p>
                        </div>
                        
                        <div class="setting-item">
                            <label><?php echo $currentLang === 'en' ? 'Search Index Sync Interval (minutes)' : '검색 인덱스 동기화 주기 (분)'; ?></label>
                            <input type="number" id="setting-index-sync-interval" class="form-control" min="1" max="1440" placeholder="1440" style="max-width:200px;">
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'How often to sync the search index with the file system. Files added/removed via the web UI are indexed instantly — this interval only affects detection of external changes.' : '검색 인덱스를 파일시스템과 동기화하는 주기입니다. 웹 UI로 추가/삭제되는 파일은 이 설정과 무관하게 즉시 인덱싱되며, 이 주기는 외부 변경 감지에만 영향을 줍니다.'; ?></p>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? '<strong>Recommended: 1440 minutes (24 hours)</strong> (range: 1–1440). For immediate indexing after external changes, use the manual "Rebuild Index" button instead of shortening this interval.' : '<strong>권장: 1440분(24시간)</strong> (범위: 1–1440). 외부 변경을 즉시 인덱스에 반영하려면 이 주기를 짧게 하기보다 수동 "인덱스 재구축" 버튼을 사용하는 편이 서버 부담이 적습니다.'; ?></p>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? '⚠️ Only applies when "Auto Update Index" is enabled above.' : '⚠️ 위의 "인덱스 자동 갱신"이 켜져 있을 때만 적용됩니다.'; ?></p>
                        </div>
                        
                        <h3><?php echo $currentLang === 'en' ? '📧 Email (SMTP) Settings' : '📧 이메일(SMTP) 설정'; ?></h3>
                        <p class="setting-desc" style="margin-bottom:15px;"><?php echo $currentLang === 'en' ? 'Used for password recovery. If not set, PHP mail() function is used.' : '아이디/비밀번호 찾기 기능에 사용됩니다. 설정하지 않으면 PHP mail() 함수를 사용합니다.'; ?></p>
                        <div class="setting-item">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-smtp-enabled">
                                <span><?php echo $currentLang === 'en' ? 'Use SMTP' : 'SMTP 사용'; ?></span>
                            </label>
                        </div>
                        <div id="smtp-settings-wrap" style="display:none;">
                            <!-- SMTP 호스트 / 포트 -->
                            <div class="smtp-row">
                                <div class="smtp-col">
                                    <label><?php echo $currentLang === 'en' ? 'SMTP Host' : 'SMTP 호스트'; ?></label>
                                    <input type="text" id="setting-smtp-host" class="form-control" placeholder="smtp.gmail.com">
                                    <small class="smtp-hint">Gmail: smtp.gmail.com | Naver: smtp.naver.com | Daum: smtp.daum.net</small>
                                </div>
                                <div class="smtp-col smtp-col-small">
                                    <label><?php echo $currentLang === 'en' ? 'Port' : '포트'; ?></label>
                                    <input type="number" id="setting-smtp-port" class="form-control" placeholder="587">
                                    <small class="smtp-hint">TLS: 587 | SSL: 465</small>
                                </div>
                            </div>
                            
                            <!-- 암호화 방식 -->
                            <div class="setting-item">
                                <label><?php echo $currentLang === 'en' ? 'Encryption' : '암호화 방식'; ?></label>
                                <div class="smtp-secure-btns">
                                    <button type="button" class="smtp-secure-btn" data-value="tls"><?php echo $currentLang === 'en' ? 'TLS (Recommended)' : 'TLS (권장)'; ?></button>
                                    <button type="button" class="smtp-secure-btn" data-value="ssl">SSL</button>
                                    <button type="button" class="smtp-secure-btn" data-value="none"><?php echo $currentLang === 'en' ? 'None' : '없음'; ?></button>
                                </div>
                                <input type="hidden" id="setting-smtp-secure" value="tls">
                            </div>
                            
                            <!-- 사용자명 / 비밀번호 -->
                            <div class="smtp-row">
                                <div class="smtp-col">
                                    <label><?php echo $currentLang === 'en' ? 'SMTP Username' : 'SMTP 사용자명'; ?></label>
                                    <input type="text" id="setting-smtp-user" class="form-control" placeholder="your-email@gmail.com">
                                    <small class="smtp-hint"><?php echo $currentLang === 'en' ? 'Usually full email address' : '보통 이메일 주소 전체'; ?></small>
                                </div>
                                <div class="smtp-col">
                                    <label><?php echo $currentLang === 'en' ? 'SMTP Password' : 'SMTP 비밀번호'; ?> <span id="smtp-pass-status"></span></label>
                                    <input type="password" id="setting-smtp-pass" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Enter new password to change' : '변경하려면 새 비밀번호 입력'; ?>">
                                    <small class="smtp-hint"><?php echo $currentLang === 'en' ? 'Gmail: Use <a href="https://myaccount.google.com/apppasswords" target="_blank">App Password</a> recommended (Leave empty to keep current)' : 'Gmail: <a href="https://myaccount.google.com/apppasswords" target="_blank">앱 비밀번호</a> 사용 권장 (비워두면 기존 비밀번호 유지)'; ?></small>
                                </div>
                            </div>
                            
                            <!-- 발신자 이메일 / 이름 -->
                            <div class="smtp-row">
                                <div class="smtp-col">
                                    <label><?php echo $currentLang === 'en' ? 'From Email' : '발신자 이메일'; ?></label>
                                    <input type="email" id="setting-smtp-from" class="form-control" placeholder="noreply@example.com">
                                    <small class="smtp-hint"><?php echo $currentLang === 'en' ? 'Uses SMTP username if empty' : '비워두면 SMTP 사용자명 사용'; ?></small>
                                </div>
                                <div class="smtp-col">
                                    <label><?php echo $currentLang === 'en' ? 'From Name' : '발신자 이름'; ?></label>
                                    <input type="text" id="setting-smtp-from-name" class="form-control" placeholder="WebHard">
                                    <small class="smtp-hint"><?php echo $currentLang === 'en' ? 'Sender name shown in email' : '이메일에 표시될 발신자 이름'; ?></small>
                                </div>
                            </div>
                            
                            <!-- 테스트 발송 -->
                            <div class="smtp-test-section">
                                <h4><?php echo $currentLang === 'en' ? '📤 Send Test Email' : '📤 테스트 이메일 발송'; ?></h4>
                                <div class="smtp-test-row">
                                    <input type="email" id="smtp-test-email" class="form-control" placeholder="test@example.com">
                                    <button type="button" class="btn btn-primary" id="btn-smtp-test"><?php echo $currentLang === 'en' ? '📧 Send Test' : '📧 테스트 발송'; ?></button>
                                </div>
                                <small class="smtp-hint"><?php echo $currentLang === 'en' ? 'Save SMTP settings before testing.' : 'SMTP 설정을 저장한 후 테스트하세요.'; ?></small>
                                <div id="smtp-test-result"></div>
                            </div>
                            
                            <!-- 주요 이메일 서비스 안내 -->
                            <div class="smtp-info-section">
                                <h4><?php echo $currentLang === 'en' ? '📋 Major Email Service SMTP Settings' : '📋 주요 이메일 서비스 SMTP 설정'; ?></h4>
                                <table class="smtp-info-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo $currentLang === 'en' ? 'Service' : '서비스'; ?></th>
                                            <th><?php echo $currentLang === 'en' ? 'Host' : '호스트'; ?></th>
                                            <th><?php echo $currentLang === 'en' ? 'Port' : '포트'; ?></th>
                                            <th><?php echo $currentLang === 'en' ? 'Encryption' : '암호화'; ?></th>
                                            <th><?php echo $currentLang === 'en' ? 'Note' : '비고'; ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong>Gmail</strong></td>
                                            <td>smtp.gmail.com</td>
                                            <td>587</td>
                                            <td>TLS</td>
                                            <td><?php echo $currentLang === 'en' ? 'Requires App Password (2FA)' : '앱 비밀번호 필요 (2단계 인증)'; ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Naver</strong></td>
                                            <td>smtp.naver.com</td>
                                            <td>587</td>
                                            <td>TLS</td>
                                            <td><?php echo $currentLang === 'en' ? 'POP3/SMTP must be enabled' : 'POP3/SMTP 사용 설정 필요'; ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Daum/Kakao</strong></td>
                                            <td>smtp.daum.net</td>
                                            <td>465</td>
                                            <td>SSL</td>
                                            <td><?php echo $currentLang === 'en' ? 'External mail setup required' : '외부메일 사용 설정 필요'; ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Outlook</strong></td>
                                            <td>smtp.office365.com</td>
                                            <td>587</td>
                                            <td>TLS</td>
                                            <td><?php echo $currentLang === 'en' ? 'App Password recommended' : '앱 비밀번호 권장'; ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="smtp-warning">
                                    <strong><?php echo $currentLang === 'en' ? '⚠️ Gmail Notice' : '⚠️ Gmail 사용 시 주의'; ?></strong>
                                    <ol>
                                        <li><?php echo $currentLang === 'en' ? 'Enable <strong>2-Step Verification</strong> in Google Account.' : 'Google 계정에서 <strong>2단계 인증</strong>을 활성화하세요.'; ?></li>
                                        <li><?php echo $currentLang === 'en' ? 'Generate <a href="https://myaccount.google.com/apppasswords" target="_blank">App Password</a> for SMTP password.' : '<a href="https://myaccount.google.com/apppasswords" target="_blank">앱 비밀번호</a>를 생성하여 SMTP 비밀번호로 사용하세요.'; ?></li>
                                        <li><?php echo $currentLang === 'en' ? 'Regular passwords are blocked by security policy.' : '일반 비밀번호는 보안 정책으로 차단됩니다.'; ?></li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        
                        <h3><?php echo $currentLang === 'en' ? '📝 OnlyOffice Settings' : '📝 OnlyOffice 설정'; ?></h3>
                        <div class="setting-item">
                            <label class="setting-label">
                                <input type="checkbox" id="setting-onlyoffice-enabled">
                                <span><?php echo $currentLang === 'en' ? 'Enable OnlyOffice Integration' : 'OnlyOffice 연동 활성화'; ?></span>
                            </label>
                            <p class="setting-desc"><?php echo $currentLang === 'en' ? 'When enabled, you can edit documents with OnlyOffice.' : '활성화하면 문서 파일을 OnlyOffice로 편집할 수 있습니다.'; ?></p>
                        </div>
                        <div id="onlyoffice-config" style="display:none;">
                            <div class="setting-item">
                                <label>Document Server URL</label>
                                <div class="input-with-btn">
                                    <input type="text" id="setting-onlyoffice-server" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'https://domain/oo' : 'https://도메인/oo'; ?>">
                                    <button type="button" class="btn btn-sm" id="btn-onlyoffice-test"><?php echo $currentLang === 'en' ? '🔗 Connection Test' : '🔗 연결 테스트'; ?></button>
                                </div>
                                <p class="setting-desc"><?php echo $currentLang === 'en' ? 'OnlyOffice Document Server URL. For HTTPS reverse proxy: https://domain/oo' : 'OnlyOffice Document Server의 URL입니다. HTTPS 리버스 프록시 사용 시: https://도메인/oo'; ?></p>
                                <div id="onlyoffice-test-result" class="test-result" style="display:none;"></div>
                            </div>
                            <div class="setting-item">
                                <label><?php echo $currentLang === 'en' ? 'JWT Secret Key (optional)' : 'JWT 시크릿 키 (선택사항)'; ?> <span id="onlyoffice-secret-status"></span></label>
                                <input type="password" id="setting-onlyoffice-secret" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Leave empty to disable JWT' : '비워두면 JWT 비활성화'; ?>">
                                <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Enter the same value if JWT_SECRET was set in Docker. Leave empty if JWT_ENABLED=false.' : 'Docker 실행 시 JWT_SECRET을 설정했다면 동일한 값을 입력하세요. JWT_ENABLED=false로 설치했다면 비워두세요.'; ?></p>
                                <details style="margin-top:6px;">
                                    <summary style="font-size:12px; color:#4a90e2; cursor:pointer; font-weight:600;"><?php echo $currentLang === 'en' ? '🔑 How to Find JWT Secret Key' : '🔑 JWT 시크릿 키 확인 방법'; ?></summary>
                                    <div style="margin-top:6px; font-size:12px; color:#555;">
                                        <p style="margin-bottom:4px;"><strong><?php echo $currentLang === 'en' ? '1. Check Docker Environment Variables' : '1. Docker 환경변수에서 확인'; ?></strong></p>
                                        <pre style="background:#f5f5f5; padding:6px 10px; border-radius:4px; font-size:11px; margin-bottom:8px;"><?php echo $currentLang === 'en' ? 'docker inspect [container_name] | grep JWT_SECRET' : 'docker inspect [컨테이너명] | grep JWT_SECRET'; ?></pre>
                                        <p style="margin-bottom:4px;"><strong><?php echo $currentLang === 'en' ? '2. Check Container Config File' : '2. 컨테이너 내부 설정 파일에서 확인'; ?></strong></p>
                                        <pre style="background:#f5f5f5; padding:6px 10px; border-radius:4px; font-size:11px; margin-bottom:8px;"><?php echo $currentLang === 'en' ? 'docker exec [container_name] cat /etc/onlyoffice/documentserver/local.json | grep secret' : 'docker exec [컨테이너명] cat /etc/onlyoffice/documentserver/local.json | grep secret'; ?></pre>
                                        <p style="margin-bottom:4px;"><strong><?php echo $currentLang === 'en' ? '3. Check All Container Environment Variables' : '3. 실행 중인 컨테이너의 환경변수 전체 확인'; ?></strong></p>
                                        <pre style="background:#f5f5f5; padding:6px 10px; border-radius:4px; font-size:11px;"><?php echo $currentLang === 'en' ? 'docker exec [container_name] env | grep JWT' : 'docker exec [컨테이너명] env | grep JWT'; ?></pre>
                                    </div>
                                </details>
                            </div>
                            <div class="setting-item">
                                <label><?php echo $currentLang === 'en' ? 'Callback URL (Optional)' : '콜백 URL (선택사항)'; ?></label>
                                <input type="text" id="setting-onlyoffice-callback" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'https://domain/' : 'https://도메인/'; ?>">
                                <p class="setting-desc"><?php echo $currentLang === 'en' 
                                    ? 'URL that OnlyOffice server uses to download files from this web server. Leave empty to auto-use current URL.<br>※ <b>Docker users:</b> If documents fail to open with "Download failed", OnlyOffice Docker cannot reach the external domain. Enter the IP that OnlyOffice Docker can reach:<br>&nbsp;&nbsp;• Same server: <code>http://172.17.0.1</code> (Docker gateway to host)<br>&nbsp;&nbsp;• Different server: <code>http://server-internal-IP</code> (e.g. http://192.168.0.10)<br>&nbsp;&nbsp;• Check gateway: <code>docker network inspect bridge | grep Gateway</code>'
                                    : 'OnlyOffice 서버가 이 웹하드에서 파일을 가져올 때 사용하는 URL입니다. 비워두면 현재 접속 주소를 자동 사용합니다.<br>※ <b>Docker 사용자:</b> 문서 열기 시 "다운로드 실패"가 발생하면, OnlyOffice Docker가 외부 도메인에 접근하지 못하는 것입니다. Docker가 접근 가능한 IP를 입력하세요:<br>&nbsp;&nbsp;• 같은 서버: <code>http://172.17.0.1</code> (Docker → 호스트 접근용)<br>&nbsp;&nbsp;• 다른 서버: <code>http://서버내부IP</code> (예: http://192.168.0.10)<br>&nbsp;&nbsp;• 게이트웨이 확인: <code>docker network inspect bridge | grep Gateway</code>'; ?></p>
                            </div>
                            <div class="setting-notice">
                                <p>📋 <strong><?php echo $currentLang === 'en' ? 'OnlyOffice Installation (Docker)' : 'OnlyOffice 설치 방법 (Docker)'; ?></strong></p>
                                <pre style="background:#f5f5f5; padding:10px; border-radius:4px; overflow-x:auto; font-size:12px;"><?php echo $currentLang === 'en' ? '# With JWT Security (Recommended)' : '# JWT 보안 사용 (권장)'; ?>

docker run -d -p 8080:80 --restart=always \
  --shm-size=256m \
  -e JWT_SECRET=your-secret-key \
  onlyoffice/documentserver

<?php echo $currentLang === 'en' ? '# Without JWT (Simple Install)' : '# JWT 비활성화 (간단 설치)'; ?>

docker run -d -p 8080:80 --restart=always \
  --shm-size=256m \
  -e JWT_ENABLED=false \
  onlyoffice/documentserver</pre>
                                
                                <p style="margin-top:15px;">🔒 <strong><?php echo $currentLang === 'en' ? 'Apache Reverse Proxy Settings' : 'Apache 리버스 프록시 설정'; ?></strong></p>
                                <p style="font-size:12px; color:#666; margin-bottom:5px;"><?php echo $currentLang === 'en' ? 'Add before <code>&lt;/VirtualHost&gt;</code> in your domain\'s SSL(443) config file.<br>(e.g.: httpd-vhosts.conf or httpd-ssl.conf)' : '웹하드 도메인의 SSL(443) 설정 파일에서 <code>&lt;/VirtualHost&gt;</code> 바로 위에 추가하세요.<br>(예: httpd-vhosts.conf 또는 httpd-ssl.conf)'; ?></p>
                                <pre style="background:#f5f5f5; padding:10px; border-radius:4px; overflow-x:auto; font-size:11px;"><?php echo $currentLang === 'en' ? '# WebSocket Proxy' : '# WebSocket 프록시'; ?>
RewriteEngine On
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteRule ^/oo/(.*) ws://<?php echo $currentLang === 'en' ? 'OnlyOffice_Internal_IP' : 'OnlyOffice내부IP'; ?>:8080/$1 [P,L]

<?php echo $currentLang === 'en' ? '# OnlyOffice Reverse Proxy' : '# OnlyOffice 리버스 프록시'; ?>
&lt;Location /oo/&gt;
    ProxyPass http://<?php echo $currentLang === 'en' ? 'OnlyOffice_Internal_IP' : 'OnlyOffice내부IP'; ?>:8080/
    ProxyPassReverse http://<?php echo $currentLang === 'en' ? 'OnlyOffice_Internal_IP' : 'OnlyOffice내부IP'; ?>:8080/
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "https"
    RequestHeader set X-Forwarded-Host "<?php echo $currentLang === 'en' ? 'domain' : '도메인'; ?>"
&lt;/Location&gt;

<?php echo $currentLang === 'en' ? '# Cache Proxy' : '# 캐시 프록시'; ?>
&lt;Location /cache/&gt;
    ProxyPass http://<?php echo $currentLang === 'en' ? 'OnlyOffice_Internal_IP' : 'OnlyOffice내부IP'; ?>:8080/cache/
    ProxyPassReverse http://<?php echo $currentLang === 'en' ? 'OnlyOffice_Internal_IP' : 'OnlyOffice내부IP'; ?>:8080/cache/
&lt;/Location&gt;

<?php echo $currentLang === 'en' ? '# Print PDF Proxy (Required for OnlyOffice 9.3.1+ print feature)' : '# 인쇄 PDF 프록시 (OnlyOffice 9.3.1+ 인쇄 기능 필수)'; ?>
&lt;Location /printfile/&gt;
    ProxyPass http://<?php echo $currentLang === 'en' ? 'OnlyOffice_Internal_IP' : 'OnlyOffice내부IP'; ?>:8080/printfile/
    ProxyPassReverse http://<?php echo $currentLang === 'en' ? 'OnlyOffice_Internal_IP' : 'OnlyOffice내부IP'; ?>:8080/printfile/
&lt;/Location&gt;</pre>
                                <p style="margin-top:10px; font-size:12px; color:#666;"><?php echo $currentLang === 'en' ? '※ Replace OnlyOffice internal IP → Server IP or Docker IP, domain → actual domain. Restart Apache after configuration.<br>※ The <code>/printfile/</code> block is required for OnlyOffice editor\'s "File → Print" feature (calls <code>/printfile/...</code> path on the same domain).' : '※ OnlyOffice내부IP → 서버IP 또는 Docker IP로, 도메인 → 실제 도메인으로 변경하세요. 설정 후 Apache 재시작 필요.<br>※ <code>/printfile/</code> 블록은 OnlyOffice 편집기의 "파일 → 인쇄" 기능에 필수입니다 (인쇄 시 같은 도메인의 <code>/printfile/...</code> 경로 호출).'; ?></p>
                                
                                <p style="margin-top:15px;">🖥️ <strong><?php echo $currentLang === 'en' ? 'Synology DSM Reverse Proxy Settings' : '시놀로지 DSM 리버스 프록시 설정'; ?></strong></p>
                                <p style="font-size:12px; color:#666; margin-bottom:5px;"><?php echo $currentLang === 'en' 
                                    ? 'DSM → Control Panel → Login Portal → Advanced → Reverse Proxy (or Application Portal → Reverse Proxy)'
                                    : 'DSM → 제어판 → 로그인 포탈 → 고급 → 역방향 프록시 (또는 응용 프로그램 포탈 → 역방향 프록시)'; ?></p>
                                <p style="font-size:12px; color:#e67e22; margin-bottom:5px; font-weight:bold;"><?php echo $currentLang === 'en' ? '★ Method 1 (Recommended) - Subdomain' : '★ 방법1 (권장) - 서브도메인 방식'; ?></p>
                                <pre style="background:#f5f5f5; padding:10px; border-radius:4px; overflow-x:auto; font-size:11px;"><?php echo $currentLang === 'en' ? '# Source' : '# 소스'; ?>

<?php echo $currentLang === 'en' ? 'Protocol' : '프로토콜'; ?>: HTTPS
<?php echo $currentLang === 'en' ? 'Hostname' : '호스트명'; ?>: <?php echo $currentLang === 'en' ? 'oo.your-domain.com' : 'oo.도메인.com'; ?>

<?php echo $currentLang === 'en' ? 'Port' : '포트'; ?>: 443

<?php echo $currentLang === 'en' ? '# Destination' : '# 대상'; ?>

<?php echo $currentLang === 'en' ? 'Protocol' : '프로토콜'; ?>: HTTP
<?php echo $currentLang === 'en' ? 'Hostname' : '호스트명'; ?>: localhost
<?php echo $currentLang === 'en' ? 'Port' : '포트'; ?>: 8080</pre>
                                <p style="font-size:12px; color:#666; margin-top:5px;"><?php echo $currentLang === 'en' 
                                    ? '※ Document Server URL → <code>https://oo.your-domain.com</code><br>※ Add DNS A record for <code>oo.your-domain.com</code> pointing to the same IP<br>※ Add <code>oo.your-domain.com</code> to SSL certificate (DSM → Security → Certificate)<br>※ In Custom Header tab, add: <code>Upgrade</code> → <code>$http_upgrade</code>, <code>Connection</code> → <code>Upgrade</code> (for WebSocket)<br>※ Subdomain proxies all paths including <code>/printfile/</code>, <code>/cache/</code> → no separate config needed for print feature.'
                                    : '※ Document Server URL → <code>https://oo.도메인.com</code><br>※ DNS에 <code>oo.도메인.com</code> A레코드 추가 (같은 IP)<br>※ SSL 인증서에 <code>oo.도메인.com</code> 추가 (DSM → 보안 → 인증서)<br>※ 사용자 지정 헤더 탭에서 추가: <code>Upgrade</code> → <code>$http_upgrade</code>, <code>Connection</code> → <code>Upgrade</code> (WebSocket 지원)<br>※ 서브도메인 방식은 <code>/printfile/</code>, <code>/cache/</code> 포함 모든 경로를 프록시하므로 인쇄 기능에 추가 설정 불필요.'; ?></p>
                                <p style="font-size:12px; color:#999; margin-top:10px; margin-bottom:3px;"><?php echo $currentLang === 'en' ? '▸ Method 2 - Subpath (/oo) — Apache only recommended' : '▸ 방법2 - 서브패스 (/oo) — Apache에서만 권장'; ?></p>
                                <pre style="background:#f9f9f9; padding:8px; border-radius:4px; overflow-x:auto; font-size:10px; color:#888;"><?php echo $currentLang === 'en' ? '# Source' : '# 소스'; ?>: HTTPS / <?php echo $currentLang === 'en' ? 'your-domain.com' : '도메인.com'; ?> / 443 / <?php echo $currentLang === 'en' ? 'Path' : '경로'; ?>: /oo
<?php echo $currentLang === 'en' ? '# Destination' : '# 대상'; ?>: HTTP / localhost / 8080
→ Document Server URL: https://<?php echo $currentLang === 'en' ? 'your-domain.com' : '도메인.com'; ?>/oo
<?php echo $currentLang === 'en' ? '⚠️ May cause redirect issues (404) on Synology DSM Nginx, and /printfile/ /cache/ paths need separate proxy entries' : '⚠️ 시놀로지 DSM Nginx에서 리다이렉트 문제(404) 발생 가능, /printfile/ /cache/ 경로 별도 프록시 항목 필요'; ?></pre>
                                
                                <p style="margin-top:15px;">🟢 <strong><?php echo $currentLang === 'en' ? 'Nginx Reverse Proxy Settings' : 'Nginx 리버스 프록시 설정'; ?></strong></p>
                                <p style="font-size:12px; color:#666; margin-bottom:5px;"><?php echo $currentLang === 'en' ? 'Add a new server block for the OnlyOffice subdomain.' : 'OnlyOffice 서브도메인용 서버 블록을 추가하세요.'; ?></p>
                                <pre style="background:#f5f5f5; padding:10px; border-radius:4px; overflow-x:auto; font-size:11px;">server {
    listen 443 ssl;
    server_name <?php echo $currentLang === 'en' ? 'oo.your-domain.com' : 'oo.도메인.com'; ?>;

    ssl_certificate <?php echo $currentLang === 'en' ? '/path/to/cert.pem' : '/인증서/경로/cert.pem'; ?>;
    ssl_certificate_key <?php echo $currentLang === 'en' ? '/path/to/key.pem' : '/인증서/경로/key.pem'; ?>;

    location / {
        proxy_pass http://<?php echo $currentLang === 'en' ? 'OnlyOffice_Internal_IP' : 'OnlyOffice내부IP'; ?>:8080/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_connect_timeout 300;
        proxy_send_timeout 300;
        proxy_read_timeout 300;
        proxy_buffering off;
        client_max_body_size 100m;
    }
}</pre>
                                <p style="font-size:12px; color:#666; margin-top:5px;"><?php echo $currentLang === 'en' 
                                    ? '※ Document Server URL → <code>https://oo.your-domain.com</code><br>※ Add DNS A record for <code>oo.your-domain.com</code><br>※ Same server: use <code>127.0.0.1</code> instead of OnlyOffice internal IP<br>※ <code>location /</code> proxies all paths including <code>/printfile/</code>, <code>/cache/</code> → no separate config needed for print feature.'
                                    : '※ Document Server URL → <code>https://oo.도메인.com</code><br>※ DNS에 <code>oo.도메인.com</code> A레코드 추가<br>※ 같은 서버면 OnlyOffice내부IP 대신 <code>127.0.0.1</code> 사용<br>※ <code>location /</code> 가 <code>/printfile/</code>, <code>/cache/</code> 포함 모든 경로를 프록시하므로 인쇄 기능에 추가 설정 불필요.'; ?></p>
                                <p style="margin-top:5px;"><?php echo $currentLang === 'en' ? 'Supported files: docx, xlsx, pptx, doc, xls, ppt, odt, ods, odp, txt, csv, html, etc.' : '지원 파일: docx, xlsx, pptx, doc, xls, ppt, odt, ods, odp, txt, csv, html 등'; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 동기화/백업 -->
                    <div style="margin-top:20px;">
                        <h3><?php echo $currentLang === 'en' ? '🔄 Sync / Backup' : '🔄 동기화 / 백업'; ?></h3>
                        <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Synchronize or backup files between storages.' : '스토리지 간 파일을 동기화하거나 백업합니다.'; ?></p>
                        <button class="btn btn-primary" id="btn-open-sync" style="margin-top:6px;">🔄 <?php echo $currentLang === 'en' ? 'Manage Sync Tasks' : '동기화 작업 관리'; ?></button>
                        
                        <h3 style="margin-top:24px;"><?php echo $currentLang === 'en' ? '💾 Configuration Backup / Restore' : '💾 설정 백업 / 복원'; ?></h3>
                        <p class="setting-desc"><?php echo $currentLang === 'en' ? 'Backup or restore users, storages, permissions, and all system settings. (Actual files are not included)' : '사용자, 스토리지, 권한, 시스템 설정을 백업하거나 복원합니다. (실제 파일은 포함되지 않습니다)'; ?></p>
                        <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap;align-items:center;">
                            <button class="btn btn-primary" id="btn-config-backup">💾 <?php echo $currentLang === 'en' ? 'Backup Settings' : '설정 백업'; ?></button>
                            <button class="btn btn-warning" id="btn-config-restore" onclick="document.getElementById('config-restore-input').click();">📂 <?php echo $currentLang === 'en' ? 'Restore Settings' : '설정 복원'; ?></button>
                            <input type="file" id="config-restore-input" accept=".json" style="display:none;">
                        </div>
                        <div id="config-backup-status" style="margin-top:6px;font-size:12px;color:#666;"></div>
                        <div style="margin-top:16px;padding:12px;background:#f8f9fa;border-radius:6px;border:1px solid #e9ecef;font-size:12px;color:#666;">
                            <strong style="color:#555;">📋 <?php echo $currentLang === 'en' ? 'Manual Backup' : '수동 백업'; ?></strong>
                            <p style="margin:6px 0 0;line-height:1.6;">
                                <?php echo $currentLang === 'en' 
                                    ? 'You can also manually backup by copying the <code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">data/</code> folder from the FileStation installation directory.<br>To backup actual files, also copy the personal folder (<code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">users/</code>) and shared folder (<code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">shared/</code>).<br>Excluded from backup: <code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">*.lock</code>, <code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">file_index.db</code>, <code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">trash_files/</code>, <code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">*.log</code>'
                                    : 'FileStation 설치 경로의 <code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">data/</code> 폴더를 통째로 복사해도 백업됩니다.<br>실제 파일을 백업하려면 개인폴더(<code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">users/</code>)와 공유폴더(<code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">shared/</code>)도 함께 복사하세요.<br>백업 제외 항목: <code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">*.lock</code>, <code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">file_index.db</code>, <code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">trash_files/</code>, <code style="background:#e9ecef;padding:1px 4px;border-radius:3px;">*.log</code>'; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-save-system-settings"><?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                </div>
            </div>
            
            <!-- 아이콘 관리 모달 (관리자) -->
            <div id="modal-icon-manager" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>🎨 <?php echo $currentLang === 'en' ? 'Extension Icon Management' : '확장자 아이콘 관리'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="icon-manager-wrap">
                        <!-- 라벨 위치 선택 (CSS 오버레이 모드 전용) -->
                        <!--   적용 대상: 압축(zip/7z/...) + 자막(srt/smi/...) + 갤러리 라벨 오버레이 매핑 -->
                        <!--   영향 X: 자동 감지 교체로 이미지에 영구 박힌 라벨 -->
                        <details style="margin-bottom:15px; background:#fff8e1; border:1px solid #ffe082; border-radius:6px; padding:8px 12px;">
                            <summary style="cursor:pointer; font-size:13px; color:#795548; font-weight:bold; user-select:none;">
                                🏷️ <?php echo $currentLang === 'en' ? 'Label Overlay Position' : '라벨 오버레이 위치'; ?>
                                <span style="font-weight:normal; color:#999; font-size:11px; margin-left:6px;"><?php echo $currentLang === 'en' ? '(applies to CSS overlay mode only)' : '(CSS 오버레이 모드 아이콘에만 적용)'; ?></span>
                            </summary>
                            <div style="margin-top:10px; display:grid; grid-template-columns:repeat(3, 1fr); gap:4px; max-width:180px;">
                                <?php
                                $_labelPositions = [
                                    'top-left'      => '↖', 'top-center'    => '↑', 'top-right'     => '↗',
                                    'center-left'   => '←', 'center'        => '●', 'center-right'  => '→',
                                    'bottom-left'   => '↙', 'bottom-center' => '↓', 'bottom-right'  => '↘',
                                ];
                                foreach ($_labelPositions as $_pVal => $_pIcon):
                                ?>
                                <button type="button" class="label-pos-btn" data-position="<?= $_pVal ?>" 
                                        style="padding:8px 0; border:2px solid #ddd; background:#fff; border-radius:4px; cursor:pointer; font-size:16px; transition:all .15s;"
                                        title="<?= $_pVal ?>">
                                    <?= $_pIcon ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top:8px; font-size:11px; color:#777;">
                                <?php echo $currentLang === 'en' ? 'Click to change. Takes effect immediately.' : '클릭하면 즉시 적용됩니다.'; ?>
                            </div>
                        </details>
                        
                        <!-- 상단 안내 + 검색 + 필터 -->
                        <div class="icon-mgr-toolbar" style="display:flex; gap:10px; align-items:center; margin-bottom:15px; flex-wrap:wrap;">
                            <input type="text" id="icon-mgr-search" class="form-control" placeholder="🔍 확장자 검색 (예: jpg, pdf)" style="flex:1; min-width:200px; padding:5px 12px;">
                            <select id="icon-mgr-filter" class="form-control" style="width:auto; min-width:150px; padding:5px 12px;">
                                <option value="all"><?php echo $currentLang === 'en' ? 'All' : '전체'; ?></option>
                                <option value="customized"><?php echo $currentLang === 'en' ? 'User Customized' : '사용자 변경'; ?></option>
                                <option value="builtin"><?php echo $currentLang === 'en' ? 'Built-in' : '기본'; ?></option>
                                <option value="image">🖼️ 이미지</option>
                                <option value="video">🎬 동영상</option>
                                <option value="audio">🎵 음악</option>
                                <option value="document">📄 문서</option>
                                <option value="code">💻 코드</option>
                                <option value="archive">📦 압축</option>
                                <option value="subtitle">💬 자막</option>
                                <option value="other"><?php echo $currentLang === 'en' ? 'Other' : '기타'; ?></option>
                            </select>
                            <button type="button" id="btn-icon-mgr-add-ext" class="btn btn-sm btn-primary icon-mgr-add-btn"><?php echo $currentLang === 'en' ? '+ Add Extension' : '+ 확장자 추가'; ?></button>
                        </div>
                        
                        <!-- 확장자 리스트 (메인) -->
                        <div id="icon-ext-list" style="max-height:500px; overflow-y:auto; border:1px solid #e9ecef; border-radius:6px; background:#fff;">
                            <!-- JS로 채움 -->
                            <div style="padding:40px; text-align:center; color:#aaa;">
                                <?php echo $currentLang === 'en' ? 'Loading...' : '로딩 중...'; ?>
                            </div>
                        </div>
                        
                        <div style="margin-top:10px; font-size:12px; color:#666;">
                            <span id="icon-ext-count">-</span>개 확장자 | 🎨 = 사용자 변경됨 | 하단 버튼으로 저장 필요
                        </div>
                    </div>
                </div>
            </div>
            
            
            <!-- 동기화/백업 모달 -->
            <div id="modal-sync" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>🔄 <?php echo $currentLang === 'en' ? 'Sync / Backup' : '동기화 / 백업'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <button class="btn btn-primary btn-sm" id="btn-add-sync-task">➕ <?php echo $currentLang === 'en' ? 'New Task' : '새 작업'; ?></button>
                    </div>
                    
                    <!-- 작업 추가/편집 폼 -->
                    <div id="sync-task-form" style="display:none;background:#f8f9fa;padding:12px;border-radius:8px;margin-bottom:12px;border:1px solid #e0e0e0;">
                        <input type="hidden" id="sync-task-id">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                            <div>
                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><?php echo $currentLang === 'en' ? 'Task Name' : '작업 이름'; ?></label>
                                <input type="text" id="sync-task-name" class="form-control" style="font-size:13px;" placeholder="<?php echo $currentLang === 'en' ? 'e.g. Daily Backup' : '예: 매일 백업'; ?>">
                            </div>
                            <div>
                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><?php echo $currentLang === 'en' ? 'Mode' : '모드'; ?></label>
                                <select id="sync-task-mode" class="form-control" style="font-size:13px;">
                                    <option value="one-way"><?php echo $currentLang === 'en' ? '→ One-way Backup' : '→ 단방향 백업'; ?></option>
                                    <option value="two-way"><?php echo $currentLang === 'en' ? '⇄ Two-way Sync' : '⇄ 양방향 동기화'; ?></option>
                                    <option value="incremental"><?php echo $currentLang === 'en' ? '📈 Incremental Backup' : '📈 증분 백업'; ?></option>
                                </select>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                            <div>
                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><?php echo $currentLang === 'en' ? 'Source Storage' : '원본 스토리지'; ?></label>
                                <select id="sync-source-storage" class="form-control" style="font-size:13px;"></select>
                                <input type="text" id="sync-source-path" class="form-control" style="font-size:12px;margin-top:4px;" placeholder="<?php echo $currentLang === 'en' ? '/subfolder' : '/하위폴더'; ?>">
                                <small style="color:#888;font-size:11px;line-height:1.4;display:block;margin-top:3px;"><?php echo $currentLang === 'en' ? '▸ Empty = sync entire storage<br>▸ To sync specific folder, enter: /folder name' : '▸ 비우면 = 스토리지 전체 동기화<br>▸ 특정 폴더만 하려면: /폴더명 입력'; ?></small>
                            </div>
                            <div>
                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><?php echo $currentLang === 'en' ? 'Target Storage' : '대상 스토리지'; ?></label>
                                <select id="sync-target-storage" class="form-control" style="font-size:13px;"></select>
                                <input type="text" id="sync-target-path" class="form-control" style="font-size:12px;margin-top:4px;" placeholder="<?php echo $currentLang === 'en' ? '/subfolder' : '/하위폴더'; ?>">
                                <small style="color:#888;font-size:11px;line-height:1.4;display:block;margin-top:3px;"><?php echo $currentLang === 'en' ? '▸ Empty = save to storage root<br>▸ To save in specific folder: /folder name' : '▸ 비우면 = 스토리지 최상위에 저장<br>▸ 특정 폴더에 저장하려면: /폴더명 입력'; ?></small>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <label style="font-size:12px;"><input type="checkbox" id="sync-delete-orphan"> <?php echo $currentLang === 'en' ? 'Delete files not in source' : '원본에 없는 파일 삭제'; ?></label>
                            <label style="font-size:12px;"><input type="checkbox" id="sync-include-subdir" checked> <?php echo $currentLang === 'en' ? 'Include subfolders' : '하위 폴더 포함'; ?></label>
                        </div>
                        <div style="margin-bottom:10px;padding:8px;background:#e8f4fd;border-radius:6px;">
                            <label style="font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                                <input type="checkbox" id="sync-schedule-enabled">
                                <span>🕐 <?php echo $currentLang === 'en' ? 'Auto Schedule (cron)' : '자동 예약 (cron)'; ?></span>
                            </label>
                            <small style="color:#666;font-size:11px;display:block;margin-bottom:6px;line-height:1.4;"><?php echo $currentLang === 'en' ? '※ This only saves the schedule setting. To actually run automatically, you must register sync_cron.php in the server\'s Task Scheduler (Windows) or crontab (Linux). See the guide below.' : '※ 예약 설정만 저장됩니다. 실제 자동 실행은 서버의 작업 스케줄러(Windows) 또는 crontab(Linux)에 sync_cron.php를 등록해야 합니다. 아래 가이드를 참고하세요.'; ?></small>
                            <div id="sync-schedule-options" style="display:none;">
                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <select id="sync-schedule-type" class="form-control" style="font-size:12px;width:auto;">
                                        <option value="daily"><?php echo $currentLang === 'en' ? 'Daily' : '매일'; ?></option>
                                        <option value="weekly"><?php echo $currentLang === 'en' ? 'Weekly' : '매주'; ?></option>
                                        <option value="monthly"><?php echo $currentLang === 'en' ? 'Monthly' : '매월'; ?></option>
                                        <option value="hourly"><?php echo $currentLang === 'en' ? 'Every N hours' : 'N시간마다'; ?></option>
                                    </select>
                                    <div id="sync-schedule-weekly" style="display:none;">
                                        <select id="sync-schedule-dow" class="form-control" style="font-size:12px;width:auto;">
                                            <option value="0"><?php echo $currentLang === 'en' ? 'Sunday' : '일요일'; ?></option>
                                            <option value="1"><?php echo $currentLang === 'en' ? 'Monday' : '월요일'; ?></option>
                                            <option value="2"><?php echo $currentLang === 'en' ? 'Tuesday' : '화요일'; ?></option>
                                            <option value="3"><?php echo $currentLang === 'en' ? 'Wednesday' : '수요일'; ?></option>
                                            <option value="4"><?php echo $currentLang === 'en' ? 'Thursday' : '목요일'; ?></option>
                                            <option value="5"><?php echo $currentLang === 'en' ? 'Friday' : '금요일'; ?></option>
                                            <option value="6"><?php echo $currentLang === 'en' ? 'Saturday' : '토요일'; ?></option>
                                        </select>
                                    </div>
                                    <div id="sync-schedule-monthly" style="display:none;">
                                        <input type="number" id="sync-schedule-dom" class="form-control" style="font-size:12px;width:60px;" min="1" max="28" value="1" placeholder="<?php echo $currentLang === 'en' ? 'Day' : '일'; ?>">
                                    </div>
                                    <div id="sync-schedule-hourly" style="display:none;">
                                        <select id="sync-schedule-hours" class="form-control" style="font-size:12px;width:auto;">
                                            <option value="1">1<?php echo $currentLang === 'en' ? 'h' : '시간'; ?></option>
                                            <option value="2">2<?php echo $currentLang === 'en' ? 'h' : '시간'; ?></option>
                                            <option value="3">3<?php echo $currentLang === 'en' ? 'h' : '시간'; ?></option>
                                            <option value="6">6<?php echo $currentLang === 'en' ? 'h' : '시간'; ?></option>
                                            <option value="12">12<?php echo $currentLang === 'en' ? 'h' : '시간'; ?></option>
                                        </select>
                                    </div>
                                    <div id="sync-schedule-time-wrap">
                                        <input type="time" id="sync-schedule-time" class="form-control" style="font-size:12px;width:auto;" value="03:00">
                                    </div>
                                </div>
                                <details style="margin-top:6px;">
                                    <summary style="font-size:11px;color:#1a5276;cursor:pointer;font-weight:600;">💡 <?php echo $currentLang === 'en' ? 'Server Setup Guide (click to expand)' : '서버 설정 가이드 (클릭하여 펼치기)'; ?></summary>
                                    <div style="margin-top:6px;font-size:11px;line-height:1.7;">
                                        
                                        <?php if ($currentLang === 'en'): ?>
                                        
                                        <div style="margin-bottom:10px;">
                                            <b>📌 How it works:</b> The cron job runs <code>sync_cron.php</code> every minute. It checks scheduled tasks and only executes when the time matches. No load when idle.
                                        </div>
                                        
                                        <div style="margin-bottom:10px;padding:8px;background:#fff;border-radius:4px;border-left:3px solid #3498db;">
                                            <b>🐧 Linux (Ubuntu/Debian/CentOS)</b><br>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:6px;border-radius:4px;margin:4px 0;font-size:10px;overflow-x:auto;"># Open crontab editor
crontab -e

# Add this line (change path to your FileStation location):
* * * * * php /var/www/html/filestation/sync_cron.php >> /var/log/filestation_sync.log 2>&1

# Verify
crontab -l</pre>
                                        </div>
                                        
                                        <div style="margin-bottom:10px;padding:8px;background:#fff;border-radius:4px;border-left:3px solid #e67e22;">
                                            <b>🪟 Windows (Apache/Nginx/IIS)</b><br>
                                            Use Windows Task Scheduler:
<pre style="background:#2d2d2d;color:#f8f8f2;padding:6px;border-radius:4px;margin:4px 0;font-size:10px;overflow-x:auto;"># Method 1: Task Scheduler (GUI)
# 1. Open "Task Scheduler" (taskschd.msc)
# 2. Create Basic Task → Name: "FileStation Sync"
# 3. Trigger: Daily, repeat every 1 minute
# 4. Action: Start a program
#    Program: C:\php\php.exe
#    Arguments: C:\Apache24\htdocs\filestation\sync_cron.php
#    Start in: C:\Apache24\htdocs\filestation

# Method 2: Command line (run as Administrator)
schtasks /create /tn "FileStation Sync" /tr "C:\php\php.exe C:\Apache24\htdocs\filestation\sync_cron.php" /sc minute /mo 1

# Method 3: Using PowerShell (run as Administrator) 
$action = New-ScheduledTaskAction -Execute "C:\php\php.exe" -Argument "C:\Apache24\htdocs\filestation\sync_cron.php" -WorkingDirectory "C:\Apache24\htdocs\filestation"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName "FileStation Sync" -Action $action -Trigger $trigger -RunLevel Highest

# To delete:
schtasks /delete /tn "FileStation Sync" /f</pre>
                                            ⚠️ Make sure <code>php.exe</code> path is correct. Check with: <code>where php</code>
                                        </div>
                                        
                                        <div style="margin-bottom:10px;padding:8px;background:#fff;border-radius:4px;border-left:3px solid #2ecc71;">
                                            <b>🐳 Docker (Linux)</b>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:6px;border-radius:4px;margin:4px 0;font-size:10px;overflow-x:auto;"># Method 1: Host crontab (recommended)
crontab -e
* * * * * docker exec filestation php /var/www/html/sync_cron.php >> /var/log/filestation_sync.log 2>&1

# Method 2: Inside container
docker exec -it filestation bash
apt-get update && apt-get install -y cron
echo "* * * * * php /var/www/html/sync_cron.php >> /var/log/sync.log 2>&1" | crontab -
service cron start

# Method 3: docker-compose with cron (add to docker-compose.yml)
# services:
#   filestation-cron:
#     image: php:8.3-cli
#     volumes:
#       - ./filestation:/var/www/html
#     entrypoint: /bin/sh -c "while true; do php /var/www/html/sync_cron.php; sleep 60; done"</pre>
                                        </div>
                                        
                                        <div style="margin-bottom:10px;padding:8px;background:#fff;border-radius:4px;border-left:3px solid #9b59b6;">
                                            <b>🐳 Docker (Windows / Docker Desktop)</b>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:6px;border-radius:4px;margin:4px 0;font-size:10px;overflow-x:auto;"># Method 1: Windows Task Scheduler + docker exec
schtasks /create /tn "FileStation Sync" /tr "docker exec filestation php /var/www/html/sync_cron.php" /sc minute /mo 1

# Method 2: PowerShell script (save as sync_scheduler.ps1)
while ($true) {
    docker exec filestation php /var/www/html/sync_cron.php
    Start-Sleep -Seconds 60
}
# Run: Start-Process powershell -ArgumentList "-File sync_scheduler.ps1" -WindowStyle Hidden

# Method 3: docker-compose sidecar (same as Linux Docker Method 3)</pre>
                                        </div>
                                        
                                        <div style="padding:8px;background:#fff3cd;border-radius:4px;margin-top:6px;">
                                            <b>🔍 Verify it works:</b><br>
                                            <code>php sync_cron.php --list</code> — Show all tasks<br>
                                            <code>php sync_cron.php --task=1</code> — Test run task #1<br>
                                            Check log file for output.
                                        </div>
                                        
                                        <?php else: ?>
                                        
                                        <div style="margin-bottom:10px;">
                                            <b>📌 동작 원리:</b> cron이 매분 <code>sync_cron.php</code>를 실행하고, 예약 시간이 맞는 작업만 실행합니다. 예약 시간이 아니면 즉시 종료되어 부하가 없습니다.
                                        </div>
                                        
                                        <div style="margin-bottom:10px;padding:8px;background:#fff;border-radius:4px;border-left:3px solid #3498db;">
                                            <b>🐧 리눅스 (Ubuntu/Debian/CentOS)</b><br>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:6px;border-radius:4px;margin:4px 0;font-size:10px;overflow-x:auto;"># crontab 편집기 열기
crontab -e

# 아래 줄 추가 (경로를 FileStation 위치로 변경):
* * * * * php /var/www/html/filestation/sync_cron.php >> /var/log/filestation_sync.log 2>&1

# 확인
crontab -l</pre>
                                        </div>
                                        
                                        <div style="margin-bottom:10px;padding:8px;background:#fff;border-radius:4px;border-left:3px solid #e67e22;">
                                            <b>🪟 윈도우 (Apache/Nginx/IIS)</b><br>
                                            Windows 작업 스케줄러를 사용합니다:
<pre style="background:#2d2d2d;color:#f8f8f2;padding:6px;border-radius:4px;margin:4px 0;font-size:10px;overflow-x:auto;"># 방법 1: 작업 스케줄러 (GUI)
# 1. "작업 스케줄러" 열기 (taskschd.msc)
# 2. 기본 작업 만들기 → 이름: "FileStation Sync"
# 3. 트리거: 매일, 1분마다 반복
# 4. 동작: 프로그램 시작
#    프로그램: C:\php\php.exe
#    인수: C:\Apache24\htdocs\filestation\sync_cron.php
#    시작 위치: C:\Apache24\htdocs\filestation

# 방법 2: 명령줄 (관리자 권한으로 실행)
schtasks /create /tn "FileStation Sync" /tr "C:\php\php.exe C:\Apache24\htdocs\filestation\sync_cron.php" /sc minute /mo 1

# 방법 3: PowerShell (관리자 권한으로 실행)
$action = New-ScheduledTaskAction -Execute "C:\php\php.exe" -Argument "C:\Apache24\htdocs\filestation\sync_cron.php" -WorkingDirectory "C:\Apache24\htdocs\filestation"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName "FileStation Sync" -Action $action -Trigger $trigger -RunLevel Highest

# 삭제:
schtasks /delete /tn "FileStation Sync" /f</pre>
                                            ⚠️ <code>php.exe</code> 경로를 확인하세요. <code>where php</code>로 확인 가능.
                                        </div>
                                        
                                        <div style="margin-bottom:10px;padding:8px;background:#fff;border-radius:4px;border-left:3px solid #2ecc71;">
                                            <b>🐳 리눅스 Docker</b>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:6px;border-radius:4px;margin:4px 0;font-size:10px;overflow-x:auto;"># 방법 1: 호스트 crontab (권장)
crontab -e
* * * * * docker exec filestation php /var/www/html/sync_cron.php >> /var/log/filestation_sync.log 2>&1

# 방법 2: 컨테이너 내부에서
docker exec -it filestation bash
apt-get update && apt-get install -y cron
echo "* * * * * php /var/www/html/sync_cron.php >> /var/log/sync.log 2>&1" | crontab -
service cron start

# 방법 3: docker-compose에 cron 사이드카 추가
# services:
#   filestation-cron:
#     image: php:8.3-cli
#     volumes:
#       - ./filestation:/var/www/html
#     entrypoint: /bin/sh -c "while true; do php /var/www/html/sync_cron.php; sleep 60; done"</pre>
                                        </div>
                                        
                                        <div style="margin-bottom:10px;padding:8px;background:#fff;border-radius:4px;border-left:3px solid #9b59b6;">
                                            <b>🐳 윈도우 Docker (Docker Desktop)</b>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:6px;border-radius:4px;margin:4px 0;font-size:10px;overflow-x:auto;"># 방법 1: 윈도우 작업 스케줄러 + docker exec
schtasks /create /tn "FileStation Sync" /tr "docker exec filestation php /var/www/html/sync_cron.php" /sc minute /mo 1

# 방법 2: PowerShell 스크립트 (sync_scheduler.ps1로 저장)
while ($true) {
    docker exec filestation php /var/www/html/sync_cron.php
    Start-Sleep -Seconds 60
}
# 실행: Start-Process powershell -ArgumentList "-File sync_scheduler.ps1" -WindowStyle Hidden

# 방법 3: docker-compose 사이드카 (리눅스 Docker 방법 3과 동일)</pre>
                                        </div>
                                        
                                        <div style="padding:8px;background:#fff3cd;border-radius:4px;margin-top:6px;">
                                            <b>🔍 동작 확인:</b><br>
                                            <code>php sync_cron.php --list</code> — 전체 작업 목록<br>
                                            <code>php sync_cron.php --task=1</code> — 1번 작업 테스트 실행<br>
                                            로그 파일에서 출력을 확인하세요.
                                        </div>
                                        
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;">
                            <button class="btn btn-primary btn-sm" id="btn-save-sync-task"><?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                            <button class="btn btn-sm" id="btn-cancel-sync-task"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                        </div>
                    </div>
                    
                    <!-- 작업 목록 -->
                    <div id="sync-task-list"></div>
                    
                    <!-- 실행 로그 -->
                    <div id="sync-log" style="display:none;margin-top:12px;">
                        <h4 style="font-size:13px;">📋 <?php echo $currentLang === 'en' ? 'Sync Log' : '동기화 로그'; ?></h4>
                        <div id="sync-log-content" style="max-height:200px;overflow-y:auto;background:#1e1e1e;color:#d4d4d4;padding:10px;border-radius:6px;font-family:monospace;font-size:11px;white-space:pre-wrap;"></div>
                    </div>
                    
                    <!-- 진행 상태 -->
                    <div id="sync-progress" style="display:none;margin-top:12px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                            <span id="sync-progress-text" style="font-size:12px;white-space:pre-wrap;">0%</span>
                            <button class="btn btn-sm btn-danger" id="btn-cancel-sync"><?php echo $currentLang === 'en' ? 'Cancel' : '중지'; ?></button>
                        </div>
                        <div style="background:#e0e0e0;border-radius:4px;height:6px;overflow:hidden;">
                            <div id="sync-progress-bar" style="background:var(--primary,#667eea);height:100%;width:0%;transition:width 0.3s;border-radius:4px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 검색 인덱스 모달 -->
            <div id="modal-search-index" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2>🔍 <?php echo $currentLang === 'en' ? 'Search Index' : '검색 인덱스'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="margin-bottom:8px; padding:8px; background:#fff8e1; border:1px solid #ffe082; border-radius:6px; font-size:12px; line-height:1.5;">
                        <strong><?php echo $currentLang === 'en' ? '🔔 Rebuild Reminder' : '🔔 재구축 알림'; ?></strong><br>
                        <label style="display:inline-flex; align-items:center; gap:4px; margin-top:4px; flex-wrap:wrap;">
                            <select id="index-rebuild-notify-days" class="form-control" style="width:auto; padding:2px 6px; font-size:12px;">
                                <option value="0"><?php echo $currentLang === 'en' ? 'Off' : '사용안함'; ?></option>
                                <option value="7">7<?php echo $currentLang === 'en' ? ' days' : '일'; ?></option>
                                <option value="14">14<?php echo $currentLang === 'en' ? ' days' : '일'; ?></option>
                                <option value="30">30<?php echo $currentLang === 'en' ? ' days' : '일'; ?></option>
                                <option value="60">60<?php echo $currentLang === 'en' ? ' days' : '일'; ?></option>
                                <option value="90">90<?php echo $currentLang === 'en' ? ' days' : '일'; ?></option>
                            </select>
                            <?php echo $currentLang === 'en' ? 'Notify admin when rebuild is overdue' : '경과 시 관리자에게 알림 표시'; ?>
                        </label>
                        <button id="btn-save-notify-days" class="btn btn-sm" style="padding:2px 10px; font-size:11px; margin-left:4px;"><?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                    </div>
                    <div class="index-info" style="padding:8px 12px; font-size:12px; line-height:1.5;">
                        <p style="margin:0;"><?php echo $currentLang === 'en' ? 'Search index improves file search speed. Rebuild may take time for many files.' : '검색 인덱스를 사용하면 파일 검색 속도가 대폭 향상됩니다. 파일이 많은 경우 재구축에 시간이 걸릴 수 있습니다.'; ?></p>
                        <p style="margin:4px 0 0;font-size:11px;color:#666;"><?php echo $currentLang === 'en' ? '⚙️ <strong>Req:</strong> PHP sqlite3 extension (<code>extension=sqlite3</code> in php.ini)' : '⚙️ <strong>요구사항:</strong> PHP sqlite3 확장 (<code>extension=sqlite3</code>)'; ?></p>
                    </div>
                    
                    <!-- 자동 갱신 상태 표시 -->
                    <div id="index-auto-status" class="index-auto-status">
                        <span id="index-auto-on" style="display:none;"><?php echo $currentLang === 'en' ? '✅ <strong>Auto Update Enabled</strong> — Index updates automatically on file changes.' : '✅ <strong>자동 갱신 활성화</strong> — 파일 변경 시 인덱스가 자동 업데이트됩니다.'; ?></span>
                        <span id="index-auto-off" style="display:none;"><?php echo $currentLang === 'en' ? '⚠️ <strong>Auto Update Disabled</strong> — Manual rebuild required. <a href="#" id="link-enable-auto-index">[Enable]</a>' : '⚠️ <strong>자동 갱신 비활성화</strong> — 수동 재구축이 필요합니다. <a href="#" id="link-enable-auto-index">[활성화]</a>'; ?></span>
                    </div>
                    
                    <div id="sqlite-warning" class="sqlite-warning" style="display:none;">
                        <p><strong><?php echo $currentLang === 'en' ? '⚠️ SQLite3 extension is disabled.' : '⚠️ SQLite3 확장이 비활성화되어 있습니다.'; ?></strong></p>
                        <p><?php echo $currentLang === 'en' ? 'Enable <code>extension=sqlite3</code> in php.ini and restart web server.' : 'php.ini에서 <code>extension=sqlite3</code>를 활성화한 후 웹서버를 재시작하세요.'; ?></p>
                    </div>
                    
                    <div class="index-stats">
                        <h3 style="font-size:13px; margin:8px 0 4px;"><?php echo $currentLang === 'en' ? 'Index Status' : '인덱스 현황'; ?></h3>
                        <table class="stats-table" style="font-size:12px;">
                            <tr><th><?php echo $currentLang === 'en' ? 'Total' : '총 항목'; ?></th><td id="index-total">-</td></tr>
                            <tr><th><?php echo $currentLang === 'en' ? 'Files' : '파일'; ?></th><td id="index-files">-</td></tr>
                            <tr><th><?php echo $currentLang === 'en' ? 'Folders' : '폴더'; ?></th><td id="index-folders">-</td></tr>
                            <tr><th><?php echo $currentLang === 'en' ? 'Last Rebuild' : '마지막 재구축'; ?></th><td id="index-last-rebuild">-</td></tr>
                            <tr><th><?php echo $currentLang === 'en' ? 'Last Auto Sync' : '마지막 자동 동기화'; ?></th><td id="index-last-sync">-</td></tr>
                        </table>
                    </div>
                    
                    <div id="index-scope-info" style="margin-bottom:10px; padding:8px 10px; background:#f0f7ff; border:1px solid #cce5ff; border-radius:6px; font-size:11px; color:#555; line-height:1.6;">
                            <strong><?php echo $currentLang === 'en' ? '📋 Index Scope' : '📋 인덱스 대상'; ?></strong><br>
                            <?php echo $currentLang === 'en' 
                                ? '• <strong>Rebuild</strong>: All storages (incl. FTP/SFTP/SMB)<br>• <strong>Auto Sync</strong>: Local only<br><small style="color:#888;">※ FTP/SFTP/SMB excluded from auto sync. Use manual rebuild.</small>'
                                : '• <strong>수동 재구축</strong>: 개인폴더, 공유폴더, 로컬, FTP/SFTP, SMB/CIFS<br>• <strong>자동 갱신</strong>: 개인폴더, 공유폴더, 로컬만 해당<br><small style="color:#888;">※ FTP/SFTP/SMB는 네트워크 부하로 자동 갱신에서 제외됩니다.</small>'; ?>
                            <br>
                            <strong><?php echo $currentLang === 'en' ? '🔍 Search' : '🔍 검색'; ?></strong><br>
                            <?php echo $currentLang === 'en'
                                ? '• <strong>Integrated</strong>: Fast (index) — indexed storages only<br>• <strong>Storage filter</strong>: Real-time (no index) — slower for large storages<br><small style="color:#888;">※ Rebuild indexes for fast integrated search across all storages.</small>'
                                : '• <strong>통합 검색</strong>: 빠름 (인덱스) — 인덱스 구축된 스토리지만<br>• <strong>필터 검색</strong>: 실시간 (인덱스 불필요) — 파일 많으면 느림<br><small style="color:#888;">※ 인덱스 재구축 시 모든 스토리지가 통합 검색에 포함됩니다.</small>'; ?>
                    </div>
                    
                    <div class="index-actions">
                        <button class="btn btn-primary" id="btn-rebuild-index">
                            <?php echo $currentLang === 'en' ? '🔄 Rebuild All Indexes' : '🔄 전체 인덱스 재구축'; ?>
                        </button>
                        <button class="btn btn-danger" id="btn-clear-index">
                            <?php echo $currentLang === 'en' ? '🗑️ Reset Index' : '🗑️ 인덱스 초기화'; ?>
                        </button>
                    </div>
                    
                    <div id="index-progress" style="display:none;">
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <p class="progress-text"><?php echo $currentLang === 'en' ? 'Rebuilding index...' : '인덱스 재구축 중...'; ?></p>
                    </div>
                    
                    <div id="index-status" style="display:none;"></div>
                </div>
            </div>
            
            <!-- 활동 로그 모달 -->
            <div id="modal-activity-logs" class="modal modal-xl" style="display:none;">
                <div class="modal-header">
                    <h2>📜 <?php echo $currentLang === 'en' ? 'Activity Logs' : '활동 로그'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="activity-filters">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label><?php echo $currentLang === 'en' ? 'User' : '사용자'; ?></label>
                                <select id="activity-filter-user" class="form-control">
                                    <option value=""><?php echo $currentLang === 'en' ? 'All' : '전체'; ?></option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><?php echo $currentLang === 'en' ? 'Type' : '유형'; ?></label>
                                <select id="activity-filter-type" class="form-control">
                                    <option value=""><?php echo $currentLang === 'en' ? 'All' : '전체'; ?></option>
                                    <option value="upload"><?php echo $currentLang === 'en' ? '📤 Upload' : '📤 업로드'; ?></option>
                                    <option value="download"><?php echo $currentLang === 'en' ? '📥 Download' : '📥 다운로드'; ?></option>
                                    <option value="delete"><?php echo $currentLang === 'en' ? '🗑️ Delete' : '🗑️ 삭제'; ?></option>
                                    <option value="create_folder"><?php echo $currentLang === 'en' ? '📁 Create Folder' : '📁 폴더 생성'; ?></option>
                                    <option value="rename"><?php echo $currentLang === 'en' ? '✏️ Rename' : '✏️ 이름 변경'; ?></option>
                                    <option value="move"><?php echo $currentLang === 'en' ? '📦 Move' : '📦 이동'; ?></option>
                                    <option value="copy"><?php echo $currentLang === 'en' ? '📋 Copy' : '📋 복사'; ?></option>
                                    <option value="share_create"><?php echo $currentLang === 'en' ? '🔗 Share Created' : '🔗 공유 생성'; ?></option>
                                    <option value="share_access"><?php echo $currentLang === 'en' ? '👁️ Share Access' : '👁️ 공유 접근'; ?></option>
                                    <option value="extract"><?php echo $currentLang === 'en' ? '📦 Extract (ZIP)' : '📦 압축 해제 (ZIP)'; ?></option>
                                    <option value="compress"><?php echo $currentLang === 'en' ? '🗜️ Compress (ZIP)' : '🗜️ 압축 (ZIP)'; ?></option>
                                    <option value="restore"><?php echo $currentLang === 'en' ? '↩️ Restore' : '↩️ 복원'; ?></option>
                                    <option value="login"><?php echo $currentLang === 'en' ? '🔐 Login' : '🔐 로그인'; ?></option>
                                    <option value="logout"><?php echo $currentLang === 'en' ? '🔓 Logout' : '🔓 로그아웃'; ?></option>
                                    <option value="login_fail"><?php echo $currentLang === 'en' ? '⚠️ Login Failed' : '⚠️ 로그인 실패'; ?></option>
                                    <option value="hack_attempt"><?php echo $currentLang === 'en' ? '🚨 Hack Attempt' : '🚨 해킹시도'; ?></option>
                                </select>
                            </div>
                            <div class="filter-group filter-date">
                                <label><?php echo $currentLang === 'en' ? 'Period' : '기간'; ?></label>
                                <div class="date-range">
                                    <input type="date" id="activity-filter-from" class="form-control" lang="<?php echo $currentLang; ?>">
                                    <span class="date-separator">~</span>
                                    <input type="date" id="activity-filter-to" class="form-control" lang="<?php echo $currentLang; ?>">
                                </div>
                            </div>
                            <div class="filter-group filter-search">
                                <label><?php echo $currentLang === 'en' ? 'Search' : '검색'; ?></label>
                                <input type="text" id="activity-filter-search" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Filename, path, user...' : '파일명, 경로, 사용자...'; ?>">
                            </div>
                            <div class="filter-group filter-buttons">
                                <label>&nbsp;</label>
                                <div class="btn-group">
                                    <button class="btn btn-primary" id="btn-activity-search"><?php echo $currentLang === 'en' ? '🔍 Search' : '🔍 검색'; ?></button>
                                    <button class="btn" id="btn-activity-reset"><?php echo $currentLang === 'en' ? 'Reset' : '초기화'; ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="activity-stats" id="activity-stats"></div>
                    <div class="activity-table-wrap">
                        <table class="data-table" id="activity-table" style="table-layout:fixed;width:100%;">
                            <thead>
                                <tr>
                                    <th style="width:125px;"><?php echo $currentLang === 'en' ? 'Time' : '시간'; ?></th>
                                    <th style="width:95px;"><?php echo $currentLang === 'en' ? 'Type' : '유형'; ?></th>
                                    <th style="width:95px;"><?php echo $currentLang === 'en' ? 'User' : '사용자'; ?></th>
                                    <th><?php echo $currentLang === 'en' ? 'File/Path' : '파일/경로'; ?></th>
                                    <th style="width:75px;text-align:right;"><?php echo $currentLang === 'en' ? 'Size' : '크기'; ?></th>
                                    <th style="width:105px;">IP</th>
                                </tr>
                            </thead>
                            <tbody id="activity-table-body"></tbody>
                        </table>
                    </div>
                    <div class="activity-pagination" id="activity-pagination"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-danger" id="btn-activity-clear"><?php echo $currentLang === 'en' ? '🗑️ Delete Old Logs' : '🗑️ 기간 삭제'; ?></button>
                    <button class="btn btn-danger" id="btn-activity-clear-all"><?php echo $currentLang === 'en' ? '🗑️ Delete All' : '🗑️ 전체 삭제'; ?></button>
                    <button class="btn" onclick="closeModal()"><?php echo $currentLang === 'en' ? 'Close' : '닫기'; ?></button>
                </div>
            </div>
            
            <!-- 조건부 일괄 삭제 모달 -->
            <div id="modal-bulk-delete" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>🧹 <?php echo $currentLang === 'en' ? 'Conditional Bulk Delete' : '조건부 일괄 삭제'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="bulk-delete-info">
                        <p class="info-notice"><?php echo $currentLang === 'en' ? '📍 Searches and deletes files/folders matching conditions based on <strong>current folder</strong>.' : '📍 <strong>현재 폴더</strong>를 기준으로 조건에 맞는 파일/폴더를 검색하여 삭제합니다.'; ?></p>
                    </div>
                    <div class="bulk-delete-settings">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Delete Pattern (one per line)' : '삭제 대상 패턴 (한 줄에 하나씩)'; ?></label>
                            <textarea id="bulk-delete-patterns" rows="5" class="form-control" placeholder="@eaDir
*.tmp
Thumbs.db
.DS_Store
desktop.ini"></textarea>
                            <p class="setting-desc">
                                <strong><?php echo $currentLang === 'en' ? 'Wildcards available:' : '와일드카드 사용 가능:'; ?></strong> 
                                <code>*</code> = <?php echo $currentLang === 'en' ? 'any characters' : '모든 문자'; ?>, <code>?</code> = <?php echo $currentLang === 'en' ? 'one character' : '한 문자'; ?><br>
                                <?php echo $currentLang === 'en' ? 'e.g.' : '예'; ?>: <code>*.zip</code> (<?php echo $currentLang === 'en' ? 'all ZIP files' : '모든 ZIP 파일'; ?>), <code>test?.txt</code> (test1.txt, testA.txt <?php echo $currentLang === 'en' ? 'etc.' : '등'; ?>)
                            </p>
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Search Scope' : '검색 범위'; ?></label>
                            <select id="bulk-delete-scope" class="form-control">
                                <option value="current"><?php echo $currentLang === 'en' ? 'Current folder only' : '현재 폴더만'; ?></option>
                                <option value="recursive" selected><?php echo $currentLang === 'en' ? 'Current and subfolders' : '현재 폴더 및 하위 폴더'; ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Target Type' : '대상 유형'; ?></label>
                            <select id="bulk-delete-type" class="form-control">
                                <option value="all"><?php echo $currentLang === 'en' ? 'Files and folders' : '파일 및 폴더'; ?></option>
                                <option value="file"><?php echo $currentLang === 'en' ? 'Files only' : '파일만'; ?></option>
                                <option value="folder"><?php echo $currentLang === 'en' ? 'Folders only' : '폴더만'; ?></option>
                            </select>
                        </div>
                        <div class="bulk-delete-actions">
                            <button class="btn btn-primary" id="btn-bulk-delete-search"><?php echo $currentLang === 'en' ? '🔍 Search' : '🔍 검색'; ?></button>
                        </div>
                        <div id="bulk-delete-results" class="bulk-delete-results" style="display:none;">
                            <h4><?php echo $currentLang === 'en' ? 'Search Results' : '검색 결과'; ?> <span id="bulk-delete-count"></span></h4>
                            <div id="bulk-delete-list" class="bulk-delete-list"></div>
                            <div class="bulk-delete-actions">
                                <button class="btn btn-danger" id="btn-bulk-delete-execute"><?php echo $currentLang === 'en' ? '🗑️ Delete Selected' : '🗑️ 선택 항목 삭제'; ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 권한 설정 모달 -->
            <div id="modal-permissions" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? 'Permission Settings' : '권한 설정'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="perm-storage-id">
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Storage' : '스토리지'; ?></label>
                        <div id="perm-storage-name" class="perm-storage-name"></div>
                    </div>
                    <div id="perm-list" class="perm-list"></div>
                    <hr>
                    <h4><?php echo $currentLang === 'en' ? 'Add Permission' : '권한 추가'; ?></h4>
                    <div class="form-row">
                        <select id="perm-user-select"></select>
                        <label><input type="checkbox" id="perm-read" checked> <?php echo $currentLang === 'en' ? 'Read' : '읽기'; ?></label>
                        <label><input type="checkbox" id="perm-write"> <?php echo $currentLang === 'en' ? 'Write' : '쓰기'; ?></label>
                        <label><input type="checkbox" id="perm-delete"> <?php echo $currentLang === 'en' ? 'Delete' : '삭제'; ?></label>
                        <label><input type="checkbox" id="perm-share"> <?php echo $currentLang === 'en' ? 'Share' : '공유'; ?></label>
                        <button class="btn btn-sm btn-primary" id="btn-add-perm"><?php echo $currentLang === 'en' ? 'Add' : '추가'; ?></button>
                    </div>
                </div>
            </div>
            
            <!-- 상세 정보 모달 -->
            <div id="modal-detailed-info" class="modal modal-md" style="display:none;">
                <div class="modal-header">
                    <h2>📄 <?php echo $currentLang === 'en' ? 'Details' : '상세 정보'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="detailed-info-content"></div>
                </div>
            </div>
            
            <!-- 파일 정보 모달 -->
            <div id="modal-info" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? 'File Info' : '파일 정보'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <table class="info-table" id="file-info-table"></table>
                </div>
            </div>
            
            <!-- 전체 로그인 기록 모달 (관리자) -->
            <div id="modal-all-logins" class="modal modal-xl" style="display:none;">
                <div class="modal-header">
                    <h2>📋 <?php echo $currentLang === 'en' ? 'All Login History' : '전체 로그인 기록'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="log-actions" style="margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <button id="btn-log-delete-selected" class="btn btn-danger btn-sm"><?php echo $currentLang === 'en' ? '🗑️ Delete Selected' : '🗑️ 선택 삭제'; ?></button>
                        <button id="btn-log-delete-all" class="btn btn-danger btn-sm"><?php echo $currentLang === 'en' ? '🗑️ Delete All' : '🗑️ 전체 삭제'; ?></button>
                        <div style="display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                            <input type="number" id="log-delete-days" value="30" min="1" style="width: 50px; padding: 5px;">
                            <span><?php echo $currentLang === 'en' ? 'days ago' : '일 이전'; ?></span>
                            <button id="btn-log-delete-old" class="btn btn-sm"><?php echo $currentLang === 'en' ? '🗑️ Delete' : '🗑️ 삭제'; ?></button>
                        </div>
                    </div>
                    <div class="login-logs-wrapper">
                    <table class="data-table login-logs-table" style="table-layout:auto;width:100%;">
                        <thead>
                            <tr>
                                <th style="width:28px;padding:6px 4px;"><input type="checkbox" id="log-select-all"></th>
                                <th style="white-space:nowrap;"><?php echo $currentLang === 'en' ? 'User' : '사용자'; ?></th>
                                <th style="white-space:nowrap;"><?php echo $currentLang === 'en' ? 'Time' : '시간'; ?></th>
                                <th style="white-space:nowrap;">IP</th>
                                <th style="white-space:nowrap;"><?php echo $currentLang === 'en' ? 'Country' : '국가'; ?></th>
                                <th><?php echo $currentLang === 'en' ? 'Device' : '디바이스'; ?></th>
                                <th style="white-space:nowrap;text-align:center;"><?php echo $currentLang === 'en' ? 'Result' : '결과'; ?></th>
                            </tr>
                        </thead>
                        <tbody id="all-logins-tbody"></tbody>
                    </table>
                    </div>
                    <div id="all-logins-pagination"></div>
                </div>
            </div>
            
            <!-- 게시판 관리 모달 (관리자) -->
            <div id="modal-board-manage" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>📋 <?php echo $currentLang === 'en' ? 'Board Management' : '게시판 관리'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="trash-toolbar">
                        <div class="trash-info">
                            <span id="board-manage-count">0<?php echo $currentLang === 'en' ? ' boards' : '개 게시판'; ?></span>
                        </div>
                        <div class="trash-actions">
                            <button class="btn btn-primary btn-sm" onclick="App.showBoardEditForm()"><?php echo $currentLang === 'en' ? '+ Add Board' : '+ 게시판 추가'; ?></button>
                        </div>
                    </div>
                    <div id="board-manage-list"></div>
                    <div id="board-manage-empty" style="display:none;text-align:center;padding:40px;color:#999;">
                        <div style="font-size:48px;margin-bottom:10px;">📋</div>
                        <p><?php echo $currentLang === 'en' ? 'No boards created yet' : '생성된 게시판이 없습니다'; ?></p>
                    </div>
                </div>
            </div>

            <!-- 게시판 추가/수정 모달 -->
            <div id="modal-board-edit" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2 id="board-edit-title">📋 <?php echo $currentLang === 'en' ? 'Add Board' : '게시판 추가'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="board-edit-id">
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Board Name' : '게시판 이름'; ?></label>
                        <input type="text" id="board-edit-name" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'e.g. Announcements' : '예: 공지사항'; ?>">
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Icon (emoji)' : '아이콘 (이모지)'; ?></label>
                        <input type="text" id="board-edit-icon" class="form-control" value="📋" style="width:60px;">
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Description' : '설명'; ?></label>
                        <input type="text" id="board-edit-desc" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Optional description' : '선택 사항'; ?>">
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'List Permission' : '목록 보기 권한'; ?></label>
                        <select id="board-edit-perm-list" class="form-control">
                            <option value="all"><?php echo $currentLang === 'en' ? 'All Users' : '모든 사용자'; ?></option>
                            <option value="admin"><?php echo $currentLang === 'en' ? 'Admin Only' : '관리자만'; ?></option>
                            <option value="none"><?php echo $currentLang === 'en' ? 'Hidden (not shown)' : '숨김 (목록에 안 보임)'; ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Read Permission' : '읽기 권한'; ?></label>
                        <select id="board-edit-perm-read" class="form-control">
                            <option value="all"><?php echo $currentLang === 'en' ? 'All Users' : '모든 사용자'; ?></option>
                            <option value="admin"><?php echo $currentLang === 'en' ? 'Admin Only' : '관리자만'; ?></option>
                            <option value="none"><?php echo $currentLang === 'en' ? 'No one (read disabled)' : '읽기 금지'; ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Write Permission' : '글쓰기 권한'; ?></label>
                        <select id="board-edit-perm-write" class="form-control">
                            <option value="all"><?php echo $currentLang === 'en' ? 'All Users' : '모든 사용자'; ?></option>
                            <option value="admin"><?php echo $currentLang === 'en' ? 'Admin Only' : '관리자만'; ?></option>
                            <option value="none"><?php echo $currentLang === 'en' ? 'No one (write disabled)' : '쓰기 금지'; ?></option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:8px;">
                        <label style="display:inline-flex !important;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" id="board-edit-comment" checked style="width:auto !important;margin:0;">
                            <?php echo $currentLang === 'en' ? 'Allow Comments' : '댓글 허용'; ?>
                        </label>
                    </div>
                    <hr style="border:none;border-top:1px solid #eee;margin:12px 0;">
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Allowed File Extensions' : '첨부파일 허용 확장자'; ?></label>
                        <input type="text" id="board-edit-allowed-ext" class="form-control" placeholder="">
                        <small style="color:#999;font-size:11px;"><?php echo $currentLang === 'en' ? 'Comma separated. Empty = all allowed' : '쉼표로 구분. 비어있으면 모두 허용'; ?></small>
                        <small style="display:block;color:#888;font-size:11px;margin-top:2px;"><?php echo $currentLang === 'en' ? 'e.g.) jpg,png,gif,pdf,zip,hwp,doc,docx,xls,xlsx' : '예) jpg,png,gif,pdf,zip,hwp,doc,docx,xls,xlsx'; ?></small>
                        <small style="display:block;color:#c62828;font-size:11px;margin-top:4px;">🔒 <?php echo $currentLang === 'en' 
                            ? 'Always blocked: php, phtml, php3~7, phar, jsp, asp, aspx, cgi, htaccess, user.ini' 
                            : '항상 차단: php, phtml, php3~7, phar, phps, jsp, jspx, asp, aspx, cgi, htaccess, user.ini'; ?></small>
                    </div>
                    <div style="display:flex;gap:12px;">
                        <div class="form-group" style="flex:1;">
                            <label><?php echo $currentLang === 'en' ? 'Posts per page' : '게시글 목록 수'; ?></label>
                            <input type="number" id="board-edit-posts-per-page" class="form-control" value="20" min="1" max="100">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label><?php echo $currentLang === 'en' ? 'Page nav count' : '페이지 네비 수'; ?></label>
                            <input type="number" id="board-edit-page-nav" class="form-control" value="10" min="1" max="20">
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;">
                        <div class="form-group" style="flex:1;">
                            <label><?php echo $currentLang === 'en' ? 'Comments per page' : '댓글 목록 수'; ?></label>
                            <input type="number" id="board-edit-comments-per-page" class="form-control" value="20" min="1" max="100">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label><?php echo $currentLang === 'en' ? 'Comment nav count' : '댓글 네비 수'; ?></label>
                            <input type="number" id="board-edit-comment-nav" class="form-control" value="10" min="1" max="20">
                        </div>
                    </div>
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'New badge duration (hours, 0=off)' : 'N 배지 표시 시간 (시간, 0=꺼짐)'; ?></label>
                        <input type="number" id="board-edit-new-badge-hours" class="form-control" value="24" min="0" max="720" style="width:120px;">
                    </div>
                    <div class="modal-footer" style="margin-top:15px;text-align:right;">
                        <button class="btn btn-secondary" onclick="App.hideModal('modal-board-edit')"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                        <button class="btn btn-primary" onclick="App.saveBoardSettings()"><?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                    </div>
                </div>
            </div>


            <!-- 스토리지 파일 선택 모달 -->
            <div class="modal" id="modal-storage-picker" style="display:none;">
                <div class="modal-content" style="max-width:600px;max-height:80vh;display:flex;flex-direction:column;">
                    <div class="modal-header">
                        <h3>📂 <?php echo $currentLang === 'en' ? 'Select from Storage' : '스토리지에서 선택'; ?></h3>
                        <span class="close" onclick="App.hideModal('modal-storage-picker')">&times;</span>
                    </div>
                    <div style="padding:10px 16px;border-bottom:1px solid var(--border-color,#eee);display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <select id="storage-picker-storage" class="form-control" style="width:auto;min-width:120px;height:32px;font-size:12px;" onchange="App.loadStoragePickerFiles()">
                        </select>
                        <div id="storage-picker-breadcrumb" style="font-size:12px;color:#666;display:flex;align-items:center;gap:4px;flex:1;overflow-x:auto;white-space:nowrap;"></div>
                    </div>
                    <div id="storage-picker-list" style="flex:1;overflow-y:auto;padding:8px 16px;min-height:200px;">
                    </div>
                    <div class="modal-footer" style="padding:10px 16px;display:flex;justify-content:space-between;align-items:center;">
                        <span id="storage-picker-count" style="font-size:12px;color:#999;"></span>
                        <div style="display:flex;gap:8px;">
                            <button class="btn btn-secondary" onclick="App.hideModal('modal-storage-picker')"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                            <button class="btn btn-primary" onclick="App.attachStoragePickerFiles()"><?php echo $currentLang === 'en' ? 'Attach Selected' : '선택 첨부'; ?></button>
                        </div>
                    </div>
                </div>
            </div>


            <!-- 휴지통 관리 모달 (관리자) -->
            <!-- 전체 휴지통 모달 (관리자) -->
            <div id="modal-trash" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>🗑️ <?php echo $currentLang === 'en' ? 'All Trash' : '전체 휴지통'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="trash-toolbar">
                        <div class="trash-info">
                            <label class="trash-select-all-wrap" title="<?php echo $currentLang === 'en' ? 'Select All' : '전체 선택'; ?>">
                                <input type="checkbox" id="trash-select-all" onchange="App.toggleTrashSelectAll(this, true)">
                            </label>
                            <span id="trash-count">0<?php echo $currentLang === 'en' ? ' items' : '개 항목'; ?></span>
                            <span id="trash-size">0 B</span>
                            <span id="trash-selected-count" class="trash-selected-info" style="display:none;"></span>
                        </div>
                        <div class="trash-actions">
                            <button id="btn-trash-restore-selected" class="btn btn-primary btn-sm" style="display:none;" onclick="App.restoreSelectedTrash(true)"><?php echo $currentLang === 'en' ? '↩️ Restore Selected' : '↩️ 선택 복구'; ?></button>
                            <button id="btn-trash-delete-selected" class="btn btn-danger btn-sm" style="display:none;" onclick="App.deleteSelectedTrash(true)"><?php echo $currentLang === 'en' ? '🗑️ Delete Selected' : '🗑️ 선택 삭제'; ?></button>
                            <button id="btn-trash-restore-all" class="btn btn-primary btn-sm"><?php echo $currentLang === 'en' ? '↩️ Restore All' : '↩️ 전체 복구'; ?></button>
                            <button id="btn-trash-empty" class="btn btn-danger btn-sm"><?php echo $currentLang === 'en' ? '🗑️ Empty All' : '🗑️ 전체 비우기'; ?></button>
                        </div>
                    </div>
                    <div class="trash-list" id="trash-list"></div>
                    <div class="trash-empty-msg" id="trash-empty-msg" style="display:none;">
                        <div class="empty-icon">🗑️</div>
                        <p><?php echo $currentLang === 'en' ? 'Trash is empty' : '휴지통이 비어있습니다'; ?></p>
                    </div>
                </div>
            </div>
            
            <!-- 내 휴지통 모달 (개인) -->
            <div id="modal-my-trash" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? '🗑️ My Trash' : '🗑️ 내 휴지통'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="trash-toolbar">
                        <div class="trash-info">
                            <label class="trash-select-all-wrap" title="<?php echo $currentLang === 'en' ? 'Select All' : '전체 선택'; ?>">
                                <input type="checkbox" id="my-trash-select-all" onchange="App.toggleTrashSelectAll(this, false)">
                            </label>
                            <span id="my-trash-count">0<?php echo $currentLang === 'en' ? ' items' : '개 항목'; ?></span>
                            <span id="my-trash-size">0 B</span>
                            <span id="my-trash-selected-count" class="trash-selected-info" style="display:none;"></span>
                        </div>
                        <div class="trash-actions">
                            <button id="btn-my-trash-restore-selected" class="btn btn-primary btn-sm" style="display:none;" onclick="App.restoreSelectedTrash(false)"><?php echo $currentLang === 'en' ? '↩️ Restore Selected' : '↩️ 선택 복구'; ?></button>
                            <button id="btn-my-trash-delete-selected" class="btn btn-danger btn-sm" style="display:none;" onclick="App.deleteSelectedTrash(false)"><?php echo $currentLang === 'en' ? '🗑️ Delete Selected' : '🗑️ 선택 삭제'; ?></button>
                            <button id="btn-my-trash-restore-all" class="btn btn-primary btn-sm"><?php echo $currentLang === 'en' ? '↩️ Restore All' : '↩️ 전체 복구'; ?></button>
                            <button id="btn-my-trash-empty" class="btn btn-danger btn-sm"><?php echo $currentLang === 'en' ? '🗑️ Empty' : '🗑️ 비우기'; ?></button>
                        </div>
                    </div>
                    <div class="trash-list" id="my-trash-list"></div>
                    <div class="trash-empty-msg" id="my-trash-empty-msg" style="display:none;">
                        <div class="empty-icon">🗑️</div>
                        <p><?php echo $currentLang === 'en' ? 'Trash is empty' : '휴지통이 비어있습니다'; ?></p>
                    </div>
                </div>
            </div>
            
            <!-- 보안 설정 모달 (관리자) -->
            <div id="modal-security" class="modal modal-xl" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? '🛡️ IP/Country Blocking Settings' : '🛡️ IP/국가 차단 설정'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- 현재 접속 정보 -->
                    <div class="security-info-bar">
                        <?php echo $currentLang === 'en' ? '📍 Current Access Info: IP:' : '📍 현재 접속 정보: IP:'; ?> <code id="current-ip">-</code> | <?php echo $currentLang === 'en' ? 'Country:' : '국가:'; ?> <code id="current-country">-</code>
                    </div>
                    
                    <!-- 차단 기능 활성화 -->
                    <div class="security-toggle-section">
                        <label class="toggle-switch">
                            <input type="checkbox" id="security-enabled">
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-label"><?php echo $currentLang === 'en' ? 'Enable Blocking' : '차단 기능 활성화'; ?></span>
                        <div class="security-warning"><?php echo $currentLang === 'en' ? '⚠️ Enabling alone does not block! Select at least 1 blocking mode below.' : '⚠️ 활성화만으로는 차단되지 않습니다! 아래 차단 모드를 반드시 1개 이상 선택하세요.'; ?></div>
                    </div>
                    
                    <!-- 차단 모드 선택 -->
                    <div class="security-mode-section">
                        <h4><?php echo $currentLang === 'en' ? 'Blocking Mode' : '차단 모드'; ?> <span class="required"><?php echo $currentLang === 'en' ? '※ Required' : '※ 필수 선택'; ?></span></h4>
                        <div class="security-mode-grid">
                            <div class="mode-column">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="security-block-country">
                                    <span class="checkbox-icon">🚫</span> <?php echo $currentLang === 'en' ? 'Block Specific Countries' : '특정 국가 차단'; ?>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" id="security-allow-country-only">
                                    <span class="checkbox-icon">✅</span> <?php echo $currentLang === 'en' ? 'Allow specific countries only' : '특정 국가만 허용'; ?>
                                </label>
                            </div>
                            <div class="mode-column">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="security-block-ip">
                                    <span class="checkbox-icon">🚫</span> <?php echo $currentLang === 'en' ? 'Block Specific IPs' : '특정 IP 차단'; ?>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" id="security-allow-ip-only">
                                    <span class="checkbox-icon">✅</span> <?php echo $currentLang === 'en' ? 'Allow specific IPs only' : '특정 IP만 허용'; ?>
                                </label>
                            </div>
                        </div>
                        <div class="mode-hint"><?php echo $currentLang === 'en' ? '💡 Both country and IP blocking conditions apply when used together.' : '💡 국가 차단 + IP 차단을 함께 사용하면 두 조건 모두 적용됩니다.'; ?></div>
                    </div>
                    
                    <!-- GeoIP 데이터 소스 -->
                    <div class="security-geoip-section">
                        <h4><?php echo $currentLang === 'en' ? '🌐 GeoIP Data Source' : '🌐 GeoIP 데이터 소스'; ?></h4>
                        <div class="geoip-source-options">
                            <label class="geoip-option">
                                <input type="radio" name="geoip-source" value="api" checked>
                                <span class="geoip-icon">🌐</span> <?php echo $currentLang === 'en' ? 'External API' : '외부 API'; ?>
                            </label>
                            <label class="geoip-option">
                                <input type="radio" name="geoip-source" value="mmdb">
                                <span class="geoip-icon">📦</span> <?php echo $currentLang === 'en' ? 'MMDB (Recommended)' : 'MMDB (권장)'; ?>
                            </label>
                            <label class="geoip-option">
                                <input type="radio" name="geoip-source" value="dat">
                                <span class="geoip-icon">📂</span> <?php echo $currentLang === 'en' ? 'DAT (Legacy)' : 'DAT (레거시)'; ?>
                            </label>
                            <label class="geoip-option">
                                <input type="radio" name="geoip-source" value="csv">
                                <span class="geoip-icon">📄</span> <?php echo $currentLang === 'en' ? 'CSV File' : 'CSV 파일'; ?>
                            </label>
                        </div>
                        <div class="geoip-block-unknown">
                            <label>
                                <input type="checkbox" id="geoip-block-unknown">
                                <span class="geoip-icon">🚫</span> <?php echo $currentLang === 'en' ? 'Block Unknown Country IPs' : '국가 확인 불가(UNKNOWN) IP 차단'; ?>
                            </label>
                        </div>
                        <div class="geoip-hint">
                            <?php echo $currentLang === 'en' ? '⚠️ IPs without GeoIP DB or unresolvable are shown as UNKNOWN.' : '⚠️ GeoIP DB가 없거나 찾을 수 없는 IP는 UNKNOWN으로 표시됩니다.'; ?>
                        </div>
                        <div id="geoip-file-section" style="display:none;">
                            <label><?php echo $currentLang === 'en' ? 'GeoIP File Path' : 'GeoIP 파일 경로'; ?></label>
                            <input type="text" id="geoip-file-path" placeholder="data/GeoLite2-Country.mmdb">
                            <div class="input-example">
                                MMDB: <a href="https://dev.maxmind.com/geoip/geolite2-free-geolocation-data" target="_blank">MaxMind GeoLite2</a> <?php echo $currentLang === 'en' ? 'Download' : '다운로드'; ?><br>
                                DAT: GeoIP.dat (<?php echo $currentLang === 'en' ? 'Legacy format' : '레거시 포맷'; ?>)<br>
                                CSV: IP,CountryCode <?php echo $currentLang === 'en' ? 'format (e.g., 1.0.0.0/24,AU)' : '형식 (예: 1.0.0.0/24,AU)'; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 국가/IP 입력 -->
                    <div class="security-input-section">
                        <div class="input-row">
                            <div class="input-column">
                                <label><span class="label-icon">🚫</span> <?php echo $currentLang === 'en' ? 'Blocked Countries <small>(country codes, comma separated)</small>' : '차단할 국가 <small>(국가 코드, 쉼표 구분)</small>'; ?></label>
                                <input type="text" id="security-blocked-countries" placeholder="CN,RU,KP" disabled>
                                <div class="input-example"><?php echo $currentLang === 'en' ? 'e.g.: CN(China), RU(Russia), KP(N.Korea), VN(Vietnam)' : '예: CN(중국), RU(러시아), KP(북한), VN(베트남)'; ?></div>
                            </div>
                            <div class="input-column">
                                <label><span class="label-icon">✅</span> <?php echo $currentLang === 'en' ? 'Allowed Countries' : '허용할 국가'; ?> <small><?php echo $currentLang === 'en' ? '(country codes, comma separated)' : '(국가 코드, 쉼표 구분)'; ?></small></label>
                                <input type="text" id="security-allowed-countries" placeholder="KR,US" disabled>
                                <div class="input-example"><?php echo $currentLang === 'en' ? 'e.g.: KR(Korea), US(USA), JP(Japan)' : '예: KR(한국), US(미국), JP(일본)'; ?></div>
                            </div>
                        </div>
                        <div class="input-row">
                            <div class="input-column">
                                <label><span class="label-icon">🚫</span> <?php echo $currentLang === 'en' ? 'Blocked IPs <small>(line or comma separated, CIDR supported)</small>' : '차단할 IP <small>(줄바꿈 또는 쉼마 구분, CIDR 지원)</small>'; ?></label>
                                <textarea id="security-blocked-ips" rows="4" placeholder="1.2.3.4&#10;5.6.7.0/24" disabled></textarea>
                            </div>
                            <div class="input-column">
                                <label><span class="label-icon">✅</span> <?php echo $currentLang === 'en' ? 'Allowed IPs' : '허용할 IP'; ?> <small><?php echo $currentLang === 'en' ? '(newline or comma, CIDR supported)' : '(줄바꿈 또는 쉼마 구분, CIDR 지원)'; ?></small></label>
                                <textarea id="security-allowed-ips" rows="4" placeholder="192.168.1.0/24&#10;10.0.0.0/8" disabled></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 관리자 IP (화이트리스트) -->
                    <div class="security-admin-section">
                        <h4><?php echo $currentLang === 'en' ? '⭐ Admin IP (Whitelist)' : '⭐ 관리자 IP (화이트리스트)'; ?></h4>
                        <div class="admin-ip-warning">
                            <strong><?php echo $currentLang === 'en' ? '⚠️ Enter admin IP before enabling blocking!' : '⚠️ 차단 설정 전 반드시 관리자 IP를 입력하세요!'; ?></strong><br>
                            <?php echo $currentLang === 'en' ? 'These IPs bypass all blocking rules and are always allowed.' : '이 IP는 모든 차단 규칙을 무시하고 항상 접근이 허용됩니다.'; ?><br>
                            <span class="current-ip-hint"><?php echo $currentLang === 'en' ? 'Current IP:' : '현재 접속 IP:'; ?> <code id="current-ip-hint">-</code> <?php echo $currentLang === 'en' ? '← Add this IP below!' : '← 이 IP를 아래에 추가하세요!'; ?></span>
                        </div>
                        <textarea id="security-admin-ips" rows="3" placeholder="127.0.0.1&#10;192.168.1.100"></textarea>
                        <div class="input-example"><?php echo $currentLang === 'en' ? 'Newline or comma separated, CIDR supported (e.g.: 192.168.1.0/24)' : '줄바꿈 또는 쉼마로 구분, CIDR 지원 (예: 192.168.1.0/24)'; ?></div>
                    </div>
                    
                    <!-- 추가 설정 -->
                    <div class="security-extra-section">
                        <div class="extra-row">
                            <div class="extra-item">
                                <label><?php echo $currentLang === 'en' ? 'Block Message' : '차단 메시지'; ?></label>
                                <input type="text" id="security-block-message" placeholder="<?php echo $currentLang === 'en' ? 'Access has been blocked.' : '접근이 차단되었습니다.'; ?>">
                            </div>
                            <div class="extra-item">
                                <label><?php echo $currentLang === 'en' ? 'IP→Country Cache Duration' : 'IP→국가 캐시 시간'; ?></label>
                                <div class="input-with-unit">
                                    <input type="number" id="security-cache-hours" value="24" min="1" max="168">
                                    <span class="unit"><?php echo $currentLang === 'en' ? 'hours' : '시간'; ?></span>
                                </div>
                            </div>
                            <div class="extra-item">
                                <label><?php echo $currentLang === 'en' ? 'Block Logs' : '차단 로그'; ?></label>
                                <label class="toggle-switch small">
                                    <input type="checkbox" id="security-log-enabled">
                                    <span class="toggle-slider"></span>
                                </label>
                                <span class="toggle-text"><?php echo $currentLang === 'en' ? 'Log Recording' : '로그 기록'; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 국가 코드 참조 (클릭 선택 가능) -->
                    <div class="security-section">
                        <div class="country-codes-header">
                            <h4>🌍 <?php echo $currentLang === 'en' ? 'Country Codes Reference' : '국가 코드 참조'; ?> <a href="#" id="toggle-country-codes" class="toggle-link"><?php echo $currentLang === 'en' ? 'Show/Hide' : '펼치기/접기'; ?></a></h4>
                            <div class="country-select-mode">
                                <label><input type="radio" name="country-select-mode" value="allowed" checked> <?php echo $currentLang === 'en' ? 'Select Allowed Countries' : '허용 국가 선택'; ?></label>
                                <label><input type="radio" name="country-select-mode" value="blocked"> <?php echo $currentLang === 'en' ? 'Select Blocked Countries' : '차단 국가 선택'; ?></label>
                            </div>
                        </div>
                        <div id="country-codes-list" style="display:none;">
                            <div class="country-codes-container">
                                <!-- 아시아 (27개) -->
                                <div class="country-region">
                                    <div class="region-title"><?php echo $currentLang === 'en' ? 'Asia (27)' : '아시아 (27)'; ?></div>
                                    <div class="country-buttons">
                                        <button type="button" class="country-btn" data-code="AF">AF <span class="country-name" data-code="AF"></span></button>
                                        <button type="button" class="country-btn" data-code="BD">BD <span class="country-name" data-code="BD"></span></button>
                                        <button type="button" class="country-btn" data-code="BN">BN <span class="country-name" data-code="BN"></span></button>
                                        <button type="button" class="country-btn" data-code="BT">BT <span class="country-name" data-code="BT"></span></button>
                                        <button type="button" class="country-btn" data-code="CN">CN <span class="country-name" data-code="CN"></span></button>
                                        <button type="button" class="country-btn" data-code="HK">HK <span class="country-name" data-code="HK"></span></button>
                                        <button type="button" class="country-btn" data-code="ID">ID <span class="country-name" data-code="ID"></span></button>
                                        <button type="button" class="country-btn" data-code="IN">IN <span class="country-name" data-code="IN"></span></button>
                                        <button type="button" class="country-btn" data-code="JP">JP <span class="country-name" data-code="JP"></span></button>
                                        <button type="button" class="country-btn" data-code="KH">KH <span class="country-name" data-code="KH"></span></button>
                                        <button type="button" class="country-btn" data-code="KP">KP <span class="country-name" data-code="KP"></span></button>
                                        <button type="button" class="country-btn" data-code="KR">KR <span class="country-name" data-code="KR"></span></button>
                                        <button type="button" class="country-btn" data-code="LA">LA <span class="country-name" data-code="LA"></span></button>
                                        <button type="button" class="country-btn" data-code="LK">LK <span class="country-name" data-code="LK"></span></button>
                                        <button type="button" class="country-btn" data-code="MM">MM <span class="country-name" data-code="MM"></span></button>
                                        <button type="button" class="country-btn" data-code="MN">MN <span class="country-name" data-code="MN"></span></button>
                                        <button type="button" class="country-btn" data-code="MO">MO <span class="country-name" data-code="MO"></span></button>
                                        <button type="button" class="country-btn" data-code="MV">MV <span class="country-name" data-code="MV"></span></button>
                                        <button type="button" class="country-btn" data-code="MY">MY <span class="country-name" data-code="MY"></span></button>
                                        <button type="button" class="country-btn" data-code="NP">NP <span class="country-name" data-code="NP"></span></button>
                                        <button type="button" class="country-btn" data-code="PH">PH <span class="country-name" data-code="PH"></span></button>
                                        <button type="button" class="country-btn" data-code="PK">PK <span class="country-name" data-code="PK"></span></button>
                                        <button type="button" class="country-btn" data-code="SG">SG <span class="country-name" data-code="SG"></span></button>
                                        <button type="button" class="country-btn" data-code="TH">TH <span class="country-name" data-code="TH"></span></button>
                                        <button type="button" class="country-btn" data-code="TL">TL <span class="country-name" data-code="TL"></span></button>
                                        <button type="button" class="country-btn" data-code="TW">TW <span class="country-name" data-code="TW"></span></button>
                                        <button type="button" class="country-btn" data-code="VN">VN <span class="country-name" data-code="VN"></span></button>
                                    </div>
                                </div>
                                <!-- 중앙아시아 (8개) -->
                                <div class="country-region">
                                    <div class="region-title"><?php echo $currentLang === 'en' ? 'Central Asia (8)' : '중앙아시아 (8)'; ?></div>
                                    <div class="country-buttons">
                                        <button type="button" class="country-btn" data-code="AM">AM <span class="country-name" data-code="AM"></span></button>
                                        <button type="button" class="country-btn" data-code="AZ">AZ <span class="country-name" data-code="AZ"></span></button>
                                        <button type="button" class="country-btn" data-code="GE">GE <span class="country-name" data-code="GE"></span></button>
                                        <button type="button" class="country-btn" data-code="KG">KG <span class="country-name" data-code="KG"></span></button>
                                        <button type="button" class="country-btn" data-code="KZ">KZ <span class="country-name" data-code="KZ"></span></button>
                                        <button type="button" class="country-btn" data-code="TJ">TJ <span class="country-name" data-code="TJ"></span></button>
                                        <button type="button" class="country-btn" data-code="TM">TM <span class="country-name" data-code="TM"></span></button>
                                        <button type="button" class="country-btn" data-code="UZ">UZ <span class="country-name" data-code="UZ"></span></button>
                                    </div>
                                </div>
                                <!-- 중동 (16개) -->
                                <div class="country-region">
                                    <div class="region-title"><?php echo $currentLang === 'en' ? 'Middle East (16)' : '중동 (16)'; ?></div>
                                    <div class="country-buttons">
                                        <button type="button" class="country-btn" data-code="AE">AE <span class="country-name" data-code="AE"></span></button>
                                        <button type="button" class="country-btn" data-code="BH">BH <span class="country-name" data-code="BH"></span></button>
                                        <button type="button" class="country-btn" data-code="CY">CY <span class="country-name" data-code="CY"></span></button>
                                        <button type="button" class="country-btn" data-code="IL">IL <span class="country-name" data-code="IL"></span></button>
                                        <button type="button" class="country-btn" data-code="IQ">IQ <span class="country-name" data-code="IQ"></span></button>
                                        <button type="button" class="country-btn" data-code="IR">IR <span class="country-name" data-code="IR"></span></button>
                                        <button type="button" class="country-btn" data-code="JO">JO <span class="country-name" data-code="JO"></span></button>
                                        <button type="button" class="country-btn" data-code="KW">KW <span class="country-name" data-code="KW"></span></button>
                                        <button type="button" class="country-btn" data-code="LB">LB <span class="country-name" data-code="LB"></span></button>
                                        <button type="button" class="country-btn" data-code="OM">OM <span class="country-name" data-code="OM"></span></button>
                                        <button type="button" class="country-btn" data-code="PS">PS <span class="country-name" data-code="PS"></span></button>
                                        <button type="button" class="country-btn" data-code="QA">QA <span class="country-name" data-code="QA"></span></button>
                                        <button type="button" class="country-btn" data-code="SA">SA <span class="country-name" data-code="SA"></span></button>
                                        <button type="button" class="country-btn" data-code="SY">SY <span class="country-name" data-code="SY"></span></button>
                                        <button type="button" class="country-btn" data-code="TR">TR <span class="country-name" data-code="TR"></span></button>
                                        <button type="button" class="country-btn" data-code="YE">YE <span class="country-name" data-code="YE"></span></button>
                                    </div>
                                </div>
                                <!-- 유럽 (50개) -->
                                <div class="country-region">
                                    <div class="region-title"><?php echo $currentLang === 'en' ? 'Europe (50)' : '유럽 (50)'; ?></div>
                                    <div class="country-buttons">
                                        <button type="button" class="country-btn" data-code="AD">AD <span class="country-name" data-code="AD"></span></button>
                                        <button type="button" class="country-btn" data-code="AL">AL <span class="country-name" data-code="AL"></span></button>
                                        <button type="button" class="country-btn" data-code="AT">AT <span class="country-name" data-code="AT"></span></button>
                                        <button type="button" class="country-btn" data-code="AX">AX <span class="country-name" data-code="AX"></span></button>
                                        <button type="button" class="country-btn" data-code="BA">BA <span class="country-name" data-code="BA"></span></button>
                                        <button type="button" class="country-btn" data-code="BE">BE <span class="country-name" data-code="BE"></span></button>
                                        <button type="button" class="country-btn" data-code="BG">BG <span class="country-name" data-code="BG"></span></button>
                                        <button type="button" class="country-btn" data-code="BY">BY <span class="country-name" data-code="BY"></span></button>
                                        <button type="button" class="country-btn" data-code="CH">CH <span class="country-name" data-code="CH"></span></button>
                                        <button type="button" class="country-btn" data-code="CZ">CZ <span class="country-name" data-code="CZ"></span></button>
                                        <button type="button" class="country-btn" data-code="DE">DE <span class="country-name" data-code="DE"></span></button>
                                        <button type="button" class="country-btn" data-code="DK">DK <span class="country-name" data-code="DK"></span></button>
                                        <button type="button" class="country-btn" data-code="EE">EE <span class="country-name" data-code="EE"></span></button>
                                        <button type="button" class="country-btn" data-code="ES">ES <span class="country-name" data-code="ES"></span></button>
                                        <button type="button" class="country-btn" data-code="FI">FI <span class="country-name" data-code="FI"></span></button>
                                        <button type="button" class="country-btn" data-code="FO">FO <span class="country-name" data-code="FO"></span></button>
                                        <button type="button" class="country-btn" data-code="FR">FR <span class="country-name" data-code="FR"></span></button>
                                        <button type="button" class="country-btn" data-code="GB">GB <span class="country-name" data-code="GB"></span></button>
                                        <button type="button" class="country-btn" data-code="GG">GG <span class="country-name" data-code="GG"></span></button>
                                        <button type="button" class="country-btn" data-code="GI">GI <span class="country-name" data-code="GI"></span></button>
                                        <button type="button" class="country-btn" data-code="GL">GL <span class="country-name" data-code="GL"></span></button>
                                        <button type="button" class="country-btn" data-code="GR">GR <span class="country-name" data-code="GR"></span></button>
                                        <button type="button" class="country-btn" data-code="HR">HR <span class="country-name" data-code="HR"></span></button>
                                        <button type="button" class="country-btn" data-code="HU">HU <span class="country-name" data-code="HU"></span></button>
                                        <button type="button" class="country-btn" data-code="IE">IE <span class="country-name" data-code="IE"></span></button>
                                        <button type="button" class="country-btn" data-code="IM">IM <span class="country-name" data-code="IM"></span></button>
                                        <button type="button" class="country-btn" data-code="IS">IS <span class="country-name" data-code="IS"></span></button>
                                        <button type="button" class="country-btn" data-code="IT">IT <span class="country-name" data-code="IT"></span></button>
                                        <button type="button" class="country-btn" data-code="JE">JE <span class="country-name" data-code="JE"></span></button>
                                        <button type="button" class="country-btn" data-code="LI">LI <span class="country-name" data-code="LI"></span></button>
                                        <button type="button" class="country-btn" data-code="LT">LT <span class="country-name" data-code="LT"></span></button>
                                        <button type="button" class="country-btn" data-code="LU">LU <span class="country-name" data-code="LU"></span></button>
                                        <button type="button" class="country-btn" data-code="LV">LV <span class="country-name" data-code="LV"></span></button>
                                        <button type="button" class="country-btn" data-code="MC">MC <span class="country-name" data-code="MC"></span></button>
                                        <button type="button" class="country-btn" data-code="MD">MD <span class="country-name" data-code="MD"></span></button>
                                        <button type="button" class="country-btn" data-code="ME">ME <span class="country-name" data-code="ME"></span></button>
                                        <button type="button" class="country-btn" data-code="MK">MK <span class="country-name" data-code="MK"></span></button>
                                        <button type="button" class="country-btn" data-code="MT">MT <span class="country-name" data-code="MT"></span></button>
                                        <button type="button" class="country-btn" data-code="NL">NL <span class="country-name" data-code="NL"></span></button>
                                        <button type="button" class="country-btn" data-code="NO">NO <span class="country-name" data-code="NO"></span></button>
                                        <button type="button" class="country-btn" data-code="PL">PL <span class="country-name" data-code="PL"></span></button>
                                        <button type="button" class="country-btn" data-code="PT">PT <span class="country-name" data-code="PT"></span></button>
                                        <button type="button" class="country-btn" data-code="RO">RO <span class="country-name" data-code="RO"></span></button>
                                        <button type="button" class="country-btn" data-code="RS">RS <span class="country-name" data-code="RS"></span></button>
                                        <button type="button" class="country-btn" data-code="RU">RU <span class="country-name" data-code="RU"></span></button>
                                        <button type="button" class="country-btn" data-code="SE">SE <span class="country-name" data-code="SE"></span></button>
                                        <button type="button" class="country-btn" data-code="SI">SI <span class="country-name" data-code="SI"></span></button>
                                        <button type="button" class="country-btn" data-code="SJ">SJ <span class="country-name" data-code="SJ"></span></button>
                                        <button type="button" class="country-btn" data-code="SK">SK <span class="country-name" data-code="SK"></span></button>
                                        <button type="button" class="country-btn" data-code="SM">SM <span class="country-name" data-code="SM"></span></button>
                                        <button type="button" class="country-btn" data-code="UA">UA <span class="country-name" data-code="UA"></span></button>
                                        <button type="button" class="country-btn" data-code="VA">VA <span class="country-name" data-code="VA"></span></button>
                                        <button type="button" class="country-btn" data-code="XK">XK <span class="country-name" data-code="XK"></span></button>
                                    </div>
                                </div>
                                <!-- 북미 (38개) -->
                                <div class="country-region">
                                    <div class="region-title"><?php echo $currentLang === 'en' ? 'North America (38)' : '북미 (38)'; ?></div>
                                    <div class="country-buttons">
                                        <button type="button" class="country-btn" data-code="AG">AG <span class="country-name" data-code="AG"></span></button>
                                        <button type="button" class="country-btn" data-code="AI">AI <span class="country-name" data-code="AI"></span></button>
                                        <button type="button" class="country-btn" data-code="AN">AN <span class="country-name" data-code="AN"></span></button>
                                        <button type="button" class="country-btn" data-code="AW">AW <span class="country-name" data-code="AW"></span></button>
                                        <button type="button" class="country-btn" data-code="BB">BB <span class="country-name" data-code="BB"></span></button>
                                        <button type="button" class="country-btn" data-code="BL">BL <span class="country-name" data-code="BL"></span></button>
                                        <button type="button" class="country-btn" data-code="BM">BM <span class="country-name" data-code="BM"></span></button>
                                        <button type="button" class="country-btn" data-code="BQ">BQ <span class="country-name" data-code="BQ"></span></button>
                                        <button type="button" class="country-btn" data-code="BS">BS <span class="country-name" data-code="BS"></span></button>
                                        <button type="button" class="country-btn" data-code="BZ">BZ <span class="country-name" data-code="BZ"></span></button>
                                        <button type="button" class="country-btn" data-code="CA">CA <span class="country-name" data-code="CA"></span></button>
                                        <button type="button" class="country-btn" data-code="CR">CR <span class="country-name" data-code="CR"></span></button>
                                        <button type="button" class="country-btn" data-code="CU">CU <span class="country-name" data-code="CU"></span></button>
                                        <button type="button" class="country-btn" data-code="CW">CW <span class="country-name" data-code="CW"></span></button>
                                        <button type="button" class="country-btn" data-code="DM">DM <span class="country-name" data-code="DM"></span></button>
                                        <button type="button" class="country-btn" data-code="DO">DO <span class="country-name" data-code="DO"></span></button>
                                        <button type="button" class="country-btn" data-code="GD">GD <span class="country-name" data-code="GD"></span></button>
                                        <button type="button" class="country-btn" data-code="GP">GP <span class="country-name" data-code="GP"></span></button>
                                        <button type="button" class="country-btn" data-code="GT">GT <span class="country-name" data-code="GT"></span></button>
                                        <button type="button" class="country-btn" data-code="HN">HN <span class="country-name" data-code="HN"></span></button>
                                        <button type="button" class="country-btn" data-code="HT">HT <span class="country-name" data-code="HT"></span></button>
                                        <button type="button" class="country-btn" data-code="JM">JM <span class="country-name" data-code="JM"></span></button>
                                        <button type="button" class="country-btn" data-code="KN">KN <span class="country-name" data-code="KN"></span></button>
                                        <button type="button" class="country-btn" data-code="KY">KY <span class="country-name" data-code="KY"></span></button>
                                        <button type="button" class="country-btn" data-code="LC">LC <span class="country-name" data-code="LC"></span></button>
                                        <button type="button" class="country-btn" data-code="MF">MF <span class="country-name" data-code="MF"></span></button>
                                        <button type="button" class="country-btn" data-code="MQ">MQ <span class="country-name" data-code="MQ"></span></button>
                                        <button type="button" class="country-btn" data-code="MS">MS <span class="country-name" data-code="MS"></span></button>
                                        <button type="button" class="country-btn" data-code="MX">MX <span class="country-name" data-code="MX"></span></button>
                                        <button type="button" class="country-btn" data-code="NI">NI <span class="country-name" data-code="NI"></span></button>
                                        <button type="button" class="country-btn" data-code="PA">PA <span class="country-name" data-code="PA"></span></button>
                                        <button type="button" class="country-btn" data-code="PR">PR <span class="country-name" data-code="PR"></span></button>
                                        <button type="button" class="country-btn" data-code="SV">SV <span class="country-name" data-code="SV"></span></button>
                                        <button type="button" class="country-btn" data-code="SX">SX <span class="country-name" data-code="SX"></span></button>
                                        <button type="button" class="country-btn" data-code="TC">TC <span class="country-name" data-code="TC"></span></button>
                                        <button type="button" class="country-btn" data-code="TT">TT <span class="country-name" data-code="TT"></span></button>
                                        <button type="button" class="country-btn" data-code="US">US <span class="country-name" data-code="US"></span></button>
                                        <button type="button" class="country-btn" data-code="VC">VC <span class="country-name" data-code="VC"></span></button>
                                        <button type="button" class="country-btn" data-code="VG">VG <span class="country-name" data-code="VG"></span></button>
                                        <button type="button" class="country-btn" data-code="VI">VI <span class="country-name" data-code="VI"></span></button>
                                    </div>
                                </div>
                                <!-- 남미 (14개) -->
                                <div class="country-region">
                                    <div class="region-title"><?php echo $currentLang === 'en' ? 'South America (14)' : '남미 (14)'; ?></div>
                                    <div class="country-buttons">
                                        <button type="button" class="country-btn" data-code="AR">AR <span class="country-name" data-code="AR"></span></button>
                                        <button type="button" class="country-btn" data-code="BO">BO <span class="country-name" data-code="BO"></span></button>
                                        <button type="button" class="country-btn" data-code="BR">BR <span class="country-name" data-code="BR"></span></button>
                                        <button type="button" class="country-btn" data-code="CL">CL <span class="country-name" data-code="CL"></span></button>
                                        <button type="button" class="country-btn" data-code="CO">CO <span class="country-name" data-code="CO"></span></button>
                                        <button type="button" class="country-btn" data-code="EC">EC <span class="country-name" data-code="EC"></span></button>
                                        <button type="button" class="country-btn" data-code="FK">FK <span class="country-name" data-code="FK"></span></button>
                                        <button type="button" class="country-btn" data-code="GF">GF <span class="country-name" data-code="GF"></span></button>
                                        <button type="button" class="country-btn" data-code="GY">GY <span class="country-name" data-code="GY"></span></button>
                                        <button type="button" class="country-btn" data-code="PE">PE <span class="country-name" data-code="PE"></span></button>
                                        <button type="button" class="country-btn" data-code="PY">PY <span class="country-name" data-code="PY"></span></button>
                                        <button type="button" class="country-btn" data-code="SR">SR <span class="country-name" data-code="SR"></span></button>
                                        <button type="button" class="country-btn" data-code="UY">UY <span class="country-name" data-code="UY"></span></button>
                                        <button type="button" class="country-btn" data-code="VE">VE <span class="country-name" data-code="VE"></span></button>
                                    </div>
                                </div>
                                <!-- 오세아니아 (25개) -->
                                <div class="country-region">
                                    <div class="region-title"><?php echo $currentLang === 'en' ? 'Oceania (25)' : '오세아니아 (25)'; ?></div>
                                    <div class="country-buttons">
                                        <button type="button" class="country-btn" data-code="AS">AS <span class="country-name" data-code="AS"></span></button>
                                        <button type="button" class="country-btn" data-code="AU">AU <span class="country-name" data-code="AU"></span></button>
                                        <button type="button" class="country-btn" data-code="CK">CK <span class="country-name" data-code="CK"></span></button>
                                        <button type="button" class="country-btn" data-code="FJ">FJ <span class="country-name" data-code="FJ"></span></button>
                                        <button type="button" class="country-btn" data-code="FM">FM <span class="country-name" data-code="FM"></span></button>
                                        <button type="button" class="country-btn" data-code="GU">GU <span class="country-name" data-code="GU"></span></button>
                                        <button type="button" class="country-btn" data-code="KI">KI <span class="country-name" data-code="KI"></span></button>
                                        <button type="button" class="country-btn" data-code="MH">MH <span class="country-name" data-code="MH"></span></button>
                                        <button type="button" class="country-btn" data-code="MP">MP <span class="country-name" data-code="MP"></span></button>
                                        <button type="button" class="country-btn" data-code="NC">NC <span class="country-name" data-code="NC"></span></button>
                                        <button type="button" class="country-btn" data-code="NF">NF <span class="country-name" data-code="NF"></span></button>
                                        <button type="button" class="country-btn" data-code="NR">NR <span class="country-name" data-code="NR"></span></button>
                                        <button type="button" class="country-btn" data-code="NU">NU <span class="country-name" data-code="NU"></span></button>
                                        <button type="button" class="country-btn" data-code="NZ">NZ <span class="country-name" data-code="NZ"></span></button>
                                        <button type="button" class="country-btn" data-code="PF">PF <span class="country-name" data-code="PF"></span></button>
                                        <button type="button" class="country-btn" data-code="PG">PG <span class="country-name" data-code="PG"></span></button>
                                        <button type="button" class="country-btn" data-code="PN">PN <span class="country-name" data-code="PN"></span></button>
                                        <button type="button" class="country-btn" data-code="PW">PW <span class="country-name" data-code="PW"></span></button>
                                        <button type="button" class="country-btn" data-code="SB">SB <span class="country-name" data-code="SB"></span></button>
                                        <button type="button" class="country-btn" data-code="TK">TK <span class="country-name" data-code="TK"></span></button>
                                        <button type="button" class="country-btn" data-code="TO">TO <span class="country-name" data-code="TO"></span></button>
                                        <button type="button" class="country-btn" data-code="TV">TV <span class="country-name" data-code="TV"></span></button>
                                        <button type="button" class="country-btn" data-code="VU">VU <span class="country-name" data-code="VU"></span></button>
                                        <button type="button" class="country-btn" data-code="WF">WF <span class="country-name" data-code="WF"></span></button>
                                        <button type="button" class="country-btn" data-code="WS">WS <span class="country-name" data-code="WS"></span></button>
                                    </div>
                                </div>
                                <!-- 아프리카 (58개) -->
                                <div class="country-region">
                                    <div class="region-title"><?php echo $currentLang === 'en' ? 'Africa (58)' : '아프리카 (58)'; ?></div>
                                    <div class="country-buttons">
                                        <button type="button" class="country-btn" data-code="AO">AO <span class="country-name" data-code="AO"></span></button>
                                        <button type="button" class="country-btn" data-code="BF">BF <span class="country-name" data-code="BF"></span></button>
                                        <button type="button" class="country-btn" data-code="BI">BI <span class="country-name" data-code="BI"></span></button>
                                        <button type="button" class="country-btn" data-code="BJ">BJ <span class="country-name" data-code="BJ"></span></button>
                                        <button type="button" class="country-btn" data-code="BW">BW <span class="country-name" data-code="BW"></span></button>
                                        <button type="button" class="country-btn" data-code="CD">CD <span class="country-name" data-code="CD"></span></button>
                                        <button type="button" class="country-btn" data-code="CF">CF <span class="country-name" data-code="CF"></span></button>
                                        <button type="button" class="country-btn" data-code="CG">CG <span class="country-name" data-code="CG"></span></button>
                                        <button type="button" class="country-btn" data-code="CI">CI <span class="country-name" data-code="CI"></span></button>
                                        <button type="button" class="country-btn" data-code="CM">CM <span class="country-name" data-code="CM"></span></button>
                                        <button type="button" class="country-btn" data-code="CV">CV <span class="country-name" data-code="CV"></span></button>
                                        <button type="button" class="country-btn" data-code="DJ">DJ <span class="country-name" data-code="DJ"></span></button>
                                        <button type="button" class="country-btn" data-code="DZ">DZ <span class="country-name" data-code="DZ"></span></button>
                                        <button type="button" class="country-btn" data-code="EG">EG <span class="country-name" data-code="EG"></span></button>
                                        <button type="button" class="country-btn" data-code="EH">EH <span class="country-name" data-code="EH"></span></button>
                                        <button type="button" class="country-btn" data-code="ER">ER <span class="country-name" data-code="ER"></span></button>
                                        <button type="button" class="country-btn" data-code="ET">ET <span class="country-name" data-code="ET"></span></button>
                                        <button type="button" class="country-btn" data-code="GA">GA <span class="country-name" data-code="GA"></span></button>
                                        <button type="button" class="country-btn" data-code="GH">GH <span class="country-name" data-code="GH"></span></button>
                                        <button type="button" class="country-btn" data-code="GM">GM <span class="country-name" data-code="GM"></span></button>
                                        <button type="button" class="country-btn" data-code="GN">GN <span class="country-name" data-code="GN"></span></button>
                                        <button type="button" class="country-btn" data-code="GQ">GQ <span class="country-name" data-code="GQ"></span></button>
                                        <button type="button" class="country-btn" data-code="GW">GW <span class="country-name" data-code="GW"></span></button>
                                        <button type="button" class="country-btn" data-code="KE">KE <span class="country-name" data-code="KE"></span></button>
                                        <button type="button" class="country-btn" data-code="KM">KM <span class="country-name" data-code="KM"></span></button>
                                        <button type="button" class="country-btn" data-code="LR">LR <span class="country-name" data-code="LR"></span></button>
                                        <button type="button" class="country-btn" data-code="LS">LS <span class="country-name" data-code="LS"></span></button>
                                        <button type="button" class="country-btn" data-code="LY">LY <span class="country-name" data-code="LY"></span></button>
                                        <button type="button" class="country-btn" data-code="MA">MA <span class="country-name" data-code="MA"></span></button>
                                        <button type="button" class="country-btn" data-code="MG">MG <span class="country-name" data-code="MG"></span></button>
                                        <button type="button" class="country-btn" data-code="ML">ML <span class="country-name" data-code="ML"></span></button>
                                        <button type="button" class="country-btn" data-code="MR">MR <span class="country-name" data-code="MR"></span></button>
                                        <button type="button" class="country-btn" data-code="MU">MU <span class="country-name" data-code="MU"></span></button>
                                        <button type="button" class="country-btn" data-code="MW">MW <span class="country-name" data-code="MW"></span></button>
                                        <button type="button" class="country-btn" data-code="MZ">MZ <span class="country-name" data-code="MZ"></span></button>
                                        <button type="button" class="country-btn" data-code="NA">NA <span class="country-name" data-code="NA"></span></button>
                                        <button type="button" class="country-btn" data-code="NE">NE <span class="country-name" data-code="NE"></span></button>
                                        <button type="button" class="country-btn" data-code="NG">NG <span class="country-name" data-code="NG"></span></button>
                                        <button type="button" class="country-btn" data-code="RE">RE <span class="country-name" data-code="RE"></span></button>
                                        <button type="button" class="country-btn" data-code="RW">RW <span class="country-name" data-code="RW"></span></button>
                                        <button type="button" class="country-btn" data-code="SC">SC <span class="country-name" data-code="SC"></span></button>
                                        <button type="button" class="country-btn" data-code="SD">SD <span class="country-name" data-code="SD"></span></button>
                                        <button type="button" class="country-btn" data-code="SH">SH <span class="country-name" data-code="SH"></span></button>
                                        <button type="button" class="country-btn" data-code="SL">SL <span class="country-name" data-code="SL"></span></button>
                                        <button type="button" class="country-btn" data-code="SN">SN <span class="country-name" data-code="SN"></span></button>
                                        <button type="button" class="country-btn" data-code="SO">SO <span class="country-name" data-code="SO"></span></button>
                                        <button type="button" class="country-btn" data-code="SS">SS <span class="country-name" data-code="SS"></span></button>
                                        <button type="button" class="country-btn" data-code="ST">ST <span class="country-name" data-code="ST"></span></button>
                                        <button type="button" class="country-btn" data-code="SZ">SZ <span class="country-name" data-code="SZ"></span></button>
                                        <button type="button" class="country-btn" data-code="TD">TD <span class="country-name" data-code="TD"></span></button>
                                        <button type="button" class="country-btn" data-code="TG">TG <span class="country-name" data-code="TG"></span></button>
                                        <button type="button" class="country-btn" data-code="TN">TN <span class="country-name" data-code="TN"></span></button>
                                        <button type="button" class="country-btn" data-code="TZ">TZ <span class="country-name" data-code="TZ"></span></button>
                                        <button type="button" class="country-btn" data-code="UG">UG <span class="country-name" data-code="UG"></span></button>
                                        <button type="button" class="country-btn" data-code="YT">YT <span class="country-name" data-code="YT"></span></button>
                                        <button type="button" class="country-btn" data-code="ZA">ZA <span class="country-name" data-code="ZA"></span></button>
                                        <button type="button" class="country-btn" data-code="ZM">ZM <span class="country-name" data-code="ZM"></span></button>
                                        <button type="button" class="country-btn" data-code="ZW">ZW <span class="country-name" data-code="ZW"></span></button>
                                    </div>
                                </div>
                                <!-- 기타 (14개) -->
                                <div class="country-region">
                                    <div class="region-title"><?php echo $currentLang === 'en' ? 'Others (14)' : '기타 (14)'; ?></div>
                                    <div class="country-buttons">
                                        <button type="button" class="country-btn" data-code="AQ">AQ <span class="country-name" data-code="AQ"></span></button>
                                        <button type="button" class="country-btn" data-code="BV">BV <span class="country-name" data-code="BV"></span></button>
                                        <button type="button" class="country-btn" data-code="CC">CC <span class="country-name" data-code="CC"></span></button>
                                        <button type="button" class="country-btn" data-code="CX">CX <span class="country-name" data-code="CX"></span></button>
                                        <button type="button" class="country-btn" data-code="GS">GS <span class="country-name" data-code="GS"></span></button>
                                        <button type="button" class="country-btn" data-code="HM">HM <span class="country-name" data-code="HM"></span></button>
                                        <button type="button" class="country-btn" data-code="IO">IO <span class="country-name" data-code="IO"></span></button>
                                        <button type="button" class="country-btn" data-code="PM">PM <span class="country-name" data-code="PM"></span></button>
                                        <button type="button" class="country-btn" data-code="TF">TF <span class="country-name" data-code="TF"></span></button>
                                        <button type="button" class="country-btn" data-code="UM">UM <span class="country-name" data-code="UM"></span></button>
                                        <button type="button" class="country-btn" data-code="AP">AP <span class="country-name" data-code="AP"></span></button>
                                        <button type="button" class="country-btn" data-code="EU">EU <span class="country-name" data-code="EU"></span></button>
                                        <button type="button" class="country-btn" data-code="YU">YU <span class="country-name" data-code="YU"></span></button>
                                        <button type="button" class="country-btn" data-code="ZZ">ZZ <span class="country-name" data-code="ZZ"></span></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 브루트포스 방지 -->
                    <div class="security-section">
                        <h4><?php echo $currentLang === 'en' ? '🔐 Brute Force Prevention' : '🔐 브루트포스 방지'; ?></h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Max Login Attempts' : '최대 로그인 시도'; ?></label>
                                <input type="number" id="security-max-attempts" min="0" value="5">
                                <small class="form-hint"><?php echo $currentLang === 'en' ? '0 = Disabled' : '0 = 비활성화'; ?></small>
                            </div>
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Attempt Reset (min)' : '시도 횟수 리셋 (분)'; ?></label>
                                <input type="number" id="security-attempt-reset-minutes" min="1" value="30">
                                <small class="form-hint"><?php echo $currentLang === 'en' ? 'Attempts reset after this time' : '이 시간 후 시도 횟수 초기화'; ?></small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Lock Time (min)' : '잠금 시간 (분)'; ?></label>
                                <input type="number" id="security-lockout-minutes" min="0" value="15">
                                <small class="form-hint"><?php echo $currentLang === 'en' ? '0 = Unlimited (manual unlock required)' : '0 = 무제한 (수동 해제 필요)'; ?></small>
                            </div>
                            <div class="form-group">
                                <label class="checkbox-inline" style="margin-top: 25px;">
                                    <input type="checkbox" id="security-lockout-permanent">
                                    <span><?php echo $currentLang === 'en' ? 'Unlimited lock (manual admin unlock required)' : '무제한 잠금 (관리자 수동 해제 필요)'; ?></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 최근 차단 로그 -->
                    <div class="security-section">
                        <h4><?php echo $currentLang === 'en' ? '📋 Recent Block Logs' : '📋 최근 차단 로그'; ?></h4>
                        <div class="log-filter-bar">
                            <div class="log-date-filter">
                                <label><?php echo $currentLang === 'en' ? 'Period:' : '기간:'; ?></label>
                                <input type="date" id="block-log-date-from" lang="<?php echo $currentLang; ?>">
                                <span>~</span>
                                <input type="date" id="block-log-date-to" lang="<?php echo $currentLang; ?>">
                                <button class="btn btn-sm btn-outline" id="btn-filter-block-logs"><?php echo $currentLang === 'en' ? 'Search' : '조회'; ?></button>
                            </div>
                            <div class="log-actions">
                                <button class="btn btn-sm btn-outline" id="btn-load-block-logs"><?php echo $currentLang === 'en' ? '🔄 Refresh' : '🔄 새로고침'; ?></button>
                            </div>
                        </div>
                        <div id="security-block-logs" class="log-table-container">
                            <p class="text-muted"><?php echo $currentLang === 'en' ? 'Loading...' : '로딩 중...'; ?></p>
                        </div>
                        <div class="log-pagination" id="block-log-pagination"></div>
                        <div class="log-footer">
                            <label class="select-all-label">
                                <input type="checkbox" id="block-log-select-all"> <?php echo $currentLang === 'en' ? 'Select All' : '전체 선택'; ?>
                            </label>
                            <button class="btn btn-sm btn-danger" id="btn-delete-selected-block-logs"><?php echo $currentLang === 'en' ? '🗑️ Delete Selected' : '🗑️ 선택 삭제'; ?></button>
                            <button class="btn btn-sm btn-danger" id="btn-clear-block-logs"><?php echo $currentLang === 'en' ? '🗑️ Delete All' : '🗑️ 전체 삭제'; ?></button>
                        </div>
                    </div>
                    
                    <!-- 브루트포스 로그 -->
                    <div class="security-section">
                        <h4><?php echo $currentLang === 'en' ? '🔐 Brute Force Logs' : '🔐 브루트포스 로그'; ?></h4>
                        <div class="log-filter-bar">
                            <div class="log-date-filter">
                                <label><?php echo $currentLang === 'en' ? 'Period:' : '기간:'; ?></label>
                                <input type="date" id="bruteforce-log-date-from" lang="<?php echo $currentLang; ?>">
                                <span>~</span>
                                <input type="date" id="bruteforce-log-date-to" lang="<?php echo $currentLang; ?>">
                                <button class="btn btn-sm btn-outline" id="btn-filter-bruteforce-logs"><?php echo $currentLang === 'en' ? 'Search' : '조회'; ?></button>
                            </div>
                            <div class="log-actions">
                                <button class="btn btn-sm btn-outline" id="btn-load-bruteforce-logs"><?php echo $currentLang === 'en' ? '🔄 Refresh' : '🔄 새로고침'; ?></button>
                            </div>
                        </div>
                        <div id="security-bruteforce-logs" class="log-table-container">
                            <p class="text-muted"><?php echo $currentLang === 'en' ? 'Loading...' : '로딩 중...'; ?></p>
                        </div>
                        <div class="log-pagination" id="bruteforce-log-pagination"></div>
                        <div class="log-footer">
                            <label class="select-all-label">
                                <input type="checkbox" id="bruteforce-log-select-all"> <?php echo $currentLang === 'en' ? 'Select All' : '전체 선택'; ?>
                            </label>
                            <button class="btn btn-sm btn-danger" id="btn-delete-selected-bruteforce-logs"><?php echo $currentLang === 'en' ? '🗑️ Delete Selected' : '🗑️ 선택 삭제'; ?></button>
                            <button class="btn btn-sm btn-danger" id="btn-clear-bruteforce-logs"><?php echo $currentLang === 'en' ? '🗑️ Delete All' : '🗑️ 전체 삭제'; ?></button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" id="btn-test-security"><?php echo $currentLang === 'en' ? '🧪 Test Current IP' : '🧪 현재 IP 테스트'; ?></button>
                    <button class="btn btn-primary" id="btn-save-security"><?php echo $currentLang === 'en' ? '💾 Save' : '💾 저장'; ?></button>
                </div>
            </div>
            
            <!-- 시스템 정보 모달 (관리자) -->
            <div id="modal-system-info" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? '📊 System Info' : '📊 시스템 정보'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="system-info-content"></div>
                </div>
            </div>
            
            <!-- 백신 설정 모달 (관리자) -->
            <div id="modal-antivirus" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? '🛡️ Antivirus Settings' : '🛡️ 백신 설정'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="antivirus-info-box" style="border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <p style="margin: 0; color: #2e7d32;">
                            <strong><?php echo $currentLang === 'en' ? '⚠️ Server Antivirus Scan' : '⚠️ 서버 백신 검사'; ?></strong><br>
                            <br><?php echo $currentLang === 'en' ? 'Scans uploaded files with <strong>server-installed antivirus</strong>.' : '업로드되는 파일을 <strong>서버에 설치된 백신</strong>으로 검사합니다.'; ?><br>
                            <?php echo $currentLang === 'en' ? 'Operates independently from client PC antivirus.' : '클라이언트 PC의 백신과는 별개로 동작합니다.'; ?><br>
                            <span style="color: #f57c00;"><?php echo $currentLang === 'en' ? '⏱️ Upload completion may be delayed due to antivirus scanning.' : '⏱️ 백신 검사로 인해 업로드 완료가 지연될 수 있습니다.'; ?></span>
                        </p>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Scan Engine' : '검사 엔진'; ?></label>
                        <select id="antivirus-engine" class="form-control">
                            <option value="disabled"><?php echo $currentLang === 'en' ? 'Disabled' : '비활성화'; ?></option>
                            <option value="clamav"><?php echo $currentLang === 'en' ? 'ClamAV (Linux/Windows Server)' : 'ClamAV (Linux/Windows 서버)'; ?></option>
                            <option value="defender"><?php echo $currentLang === 'en' ? 'Windows Defender (Windows Server Only)' : 'Windows Defender (Windows 서버 전용)'; ?></option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? 'Max Scan File Size (MB)' : '최대 검사 파일 크기 (MB)'; ?></label>
                        <input type="number" id="antivirus-max-size" class="form-control" min="0" value="100">
                        <small class="form-hint"><?php echo $currentLang === 'en' ? '0 = Unlimited. Files exceeding this size will be skipped.' : '0 = 무제한. 이 크기를 초과하는 파일은 검사를 건너뜁니다.'; ?></small>
                    </div>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" id="antivirus-block-on-error" style="width: 18px; height: 18px;">
                            <span><?php echo $currentLang === 'en' ? 'Block upload on antivirus error' : '백신 오류 시 업로드 차단'; ?></span>
                        </label>
                        <small class="form-hint"><?php echo $currentLang === 'en' ? 'When enabled, uploads are blocked on scan errors. When disabled, uploads are allowed on errors.' : '활성화하면 백신 검사 오류 시 업로드가 차단됩니다. 비활성화하면 오류 시 업로드를 허용합니다.'; ?></small>
                    </div>
                    
                    <div id="antivirus-clamav-section" style="display:none;">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'ClamAV Executable Path (Server)' : 'ClamAV 실행 경로 (서버)'; ?></label>
                            <input type="text" id="antivirus-clamav-path" class="form-control" placeholder="clamscan">
                            <small class="form-hint">
                                Linux: <code>clamscan</code> <?php echo $currentLang === 'en' ? 'or' : '또는'; ?> <code>/usr/bin/clamscan</code><br>
                                Windows: <code>C:\Program Files\ClamAV\clamscan.exe</code>
                            </small>
                        </div>
                    </div>
                    
                    <div id="antivirus-defender-section" style="display:none;">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Windows Defender Path (Server)' : 'Windows Defender 경로 (서버)'; ?></label>
                            <input type="text" id="antivirus-defender-path" class="form-control" placeholder="">
                            <small class="form-hint">
                                <?php echo $currentLang === 'en' ? 'Default:' : '기본값:'; ?> <code>C:\Program Files\Windows Defender\MpCmdRun.exe</code><br>
                                <?php echo $currentLang === 'en' ? 'Leave empty to use default path.' : '비워두면 기본 경로를 사용합니다.'; ?>
                            </small>
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-top: 20px;">
                        <button type="button" class="btn btn-outline" id="btn-test-antivirus"><?php echo $currentLang === 'en' ? '🧪 Test Engine' : '🧪 엔진 테스트'; ?></button>
                        <span id="antivirus-test-result" style="margin-left: 10px;"></span>
                    </div>
                    
                    <!-- 검사 로그 섹션 -->
                    <div id="antivirus-scan-log" style="margin-top: 25px; border-top: 1px solid #ddd; padding-top: 20px;">
                        <h4 style="margin: 0 0 15px 0;"><?php echo $currentLang === 'en' ? '📋 Scan Logs' : '📋 검사 로그'; ?></h4>
                        
                        <!-- 날짜 필터 -->
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 15px;">
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <label style="font-size: 13px; margin: 0;"><?php echo $currentLang === 'en' ? 'Start:' : '시작:'; ?></label>
                                <input type="date" id="antivirus-log-date-from" lang="<?php echo $currentLang; ?>" class="form-control" style="width: 150px; padding: 5px 8px; font-size: 13px;">
                            </div>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <label style="font-size: 13px; margin: 0;"><?php echo $currentLang === 'en' ? 'End:' : '종료:'; ?></label>
                                <input type="date" id="antivirus-log-date-to" lang="<?php echo $currentLang; ?>" class="form-control" style="width: 150px; padding: 5px 8px; font-size: 13px;">
                            </div>
                            <button type="button" class="btn btn-sm btn-outline" id="btn-filter-antivirus-logs"><?php echo $currentLang === 'en' ? '🔍 Query' : '🔍 조회'; ?></button>
                            <button type="button" class="btn btn-sm btn-outline" id="btn-delete-filtered-antivirus-logs" style="color: #f44336; border-color: #f44336;"><?php echo $currentLang === 'en' ? '🗑️ Delete Query Results' : '🗑️ 조회 결과 삭제'; ?></button>
                            <button type="button" class="btn btn-sm btn-danger" id="btn-clear-antivirus-logs"><?php echo $currentLang === 'en' ? 'Delete All' : '전체 삭제'; ?></button>
                        </div>
                        
                        <!-- 로그 통계 -->
                        <div id="antivirus-log-stats" style="font-size: 12px; color: #666; margin-bottom: 10px;"></div>
                        
                        <!-- 로그 목록 -->
                        <div id="antivirus-log-content" style="max-height: 300px; overflow-y: auto; background: #f5f5f5; padding: 10px; border-radius: 4px; font-size: 12px;"></div>
                        
                        <!-- 페이지네이션 -->
                        <div id="antivirus-log-pagination" style="display: flex; justify-content: center; gap: 5px; margin-top: 10px;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary modal-close" style="font-size: 14px;"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-save-antivirus" style="font-size: 14px;"><?php echo $currentLang === 'en' ? '💾 Save' : '💾 저장'; ?></button>
                </div>
            </div>
            
            <!-- 랜섬웨어 방지 모달 (관리자) -->
            <div id="modal-ransomware" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? '🔐 Ransomware Protection' : '🔐 랜섬웨어 방지'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="ransomware-warning-box" style="border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <p style="margin: 0; color: #c62828;">
                            <strong><?php echo $currentLang === 'en' ? '🔐 Ransomware Protection Features' : '🔐 랜섬웨어 방지 기능'; ?></strong><br>
                            <?php echo $currentLang === 'en' ? 'Protects data from ransomware by blocking suspicious uploads,<br>detecting bulk operations, and managing file versions.' : '의심스러운 파일 업로드 차단, 대량 작업 감지, 파일 버전 관리로<br>랜섬웨어로부터 데이터를 보호합니다.'; ?>
                        </p>
                    </div>
                    
                    <!-- 1. 의심 확장자 차단 -->
                    <div style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 15px 0; color: #c62828;"><?php echo $currentLang === 'en' ? '🚫 Suspicious Extension Blocking' : '🚫 의심 확장자 차단'; ?></h4>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="ransomware-block-extensions" style="width: 18px; height: 18px;">
                                <span><?php echo $currentLang === 'en' ? 'Block suspicious extensions' : '의심 확장자 업로드 차단'; ?></span>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Blocked Extensions List' : '차단할 확장자 목록'; ?></label>
                            <textarea id="ransomware-blocked-extensions" class="form-control" rows="3" placeholder=".encrypted, .locked, .crypto, .crypt, .locky, .cerber, .zepto, .thor, .aaa, .zzz"></textarea>
                            <small class="form-hint"><?php echo $currentLang === 'en' ? 'Comma separated. Enter extensions created by ransomware.' : '쉼표(,)로 구분. 랜섬웨어가 생성하는 확장자를 입력하세요.'; ?></small>
                        </div>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="ransomware-block-random-ext" style="width: 18px; height: 18px;">
                                <span><?php echo $currentLang === 'en' ? 'Block suspicious random extensions (6+ alphanumeric chars)' : '의심스러운 랜덤 확장자 차단 (숫자+문자 조합 6자 이상)'; ?></span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- 2. 대량 작업 감지 -->
                    <div style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 15px 0; color: #c62828;"><?php echo $currentLang === 'en' ? '⚠️ Mass Operation Detection & Blocking' : '⚠️ 대량 작업 감지 및 차단'; ?></h4>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="ransomware-bulk-protection" style="width: 18px; height: 18px;">
                                <span><?php echo $currentLang === 'en' ? 'Enable mass operation protection' : '대량 작업 보호 활성화'; ?></span>
                            </label>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Detection Time (sec)' : '감지 시간 (초)'; ?></label>
                                <input type="number" id="ransomware-bulk-time" class="form-control" min="10" max="3600" value="60">
                                <small class="form-hint"><?php echo $currentLang === 'en' ? 'Detect mass operations within this time' : '이 시간 내에 대량 작업 감지'; ?></small>
                            </div>
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Delete Threshold' : '삭제 임계값 (개)'; ?></label>
                                <input type="number" id="ransomware-bulk-delete-limit" class="form-control" min="5" max="1000" value="50">
                                <small class="form-hint"><?php echo $currentLang === 'en' ? 'Block when deleting this many or more' : '이 수 이상 삭제 시 차단'; ?></small>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Overwrite Threshold' : '덮어쓰기 임계값 (개)'; ?></label>
                                <input type="number" id="ransomware-bulk-overwrite-limit" class="form-control" min="5" max="1000" value="50">
                                <small class="form-hint"><?php echo $currentLang === 'en' ? 'Block when overwriting this many or more' : '이 수 이상 덮어쓰기 시 차단'; ?></small>
                            </div>
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Block Time (min)' : '차단 시간 (분)'; ?></label>
                                <input type="number" id="ransomware-block-duration" class="form-control" min="1" max="1440" value="30">
                                <small class="form-hint"><?php echo $currentLang === 'en' ? 'Operations blocked for this duration' : '차단 후 이 시간 동안 작업 금지'; ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 3. 파일 버전 관리 -->
                    <div style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 15px 0; color: #c62828;"><?php echo $currentLang === 'en' ? '📦 File Version Management' : '📦 파일 버전 관리'; ?></h4>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="ransomware-versioning" style="width: 18px; height: 18px;">
                                <span><?php echo $currentLang === 'en' ? 'Auto backup previous version on overwrite' : '덮어쓰기 시 이전 버전 자동 백업'; ?></span>
                            </label>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Version Retention (days)' : '버전 보관 기간 (일)'; ?></label>
                                <input type="number" id="ransomware-version-days" class="form-control" min="1" max="365" value="7">
                                <small class="form-hint"><?php echo $currentLang === 'en' ? 'Previous versions auto-deleted after this period' : '이 기간 후 이전 버전 자동 삭제'; ?></small>
                            </div>
                            <div class="form-group">
                                <label><?php echo $currentLang === 'en' ? 'Max Versions (per file)' : '최대 버전 수 (파일당)'; ?></label>
                                <input type="number" id="ransomware-max-versions" class="form-control" min="1" max="100" value="10">
                                <small class="form-hint"><?php echo $currentLang === 'en' ? 'Maximum versions to keep per file' : '파일당 보관할 최대 버전 수'; ?></small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? 'Excluded Extensions' : '버전 관리 제외 확장자'; ?></label>
                            <input type="text" id="ransomware-version-exclude" class="form-control" placeholder=".tmp, .log, .bak">
                            <small class="form-hint"><?php echo $currentLang === 'en' ? 'Comma separated. Extensions to exclude from versioning' : '쉼표(,)로 구분. 버전 관리하지 않을 확장자'; ?></small>
                        </div>
                    </div>
                    
                    <!-- 4. 파일 내용 검사 -->
                    <div style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 15px 0; color: #c62828;"><?php echo $currentLang === 'en' ? '🔍 File Content Scanning' : '🔍 파일 내용 검사'; ?></h4>
                        <p style="font-size: 12px; color: #666; margin-bottom: 15px;">
                            <?php echo $currentLang === 'en' ? 'Detects encrypted content even if the filename appears normal.' : '파일명이 정상이어도 내용이 암호화된 파일을 감지합니다.'; ?>
                        </p>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="ransomware-content-check" style="width: 18px; height: 18px;">
                                <span><?php echo $currentLang === 'en' ? 'Enable file content inspection' : '파일 내용 검사 활성화'; ?></span>
                            </label>
                        </div>
                        
                        <div style="border-left: 3px solid #e0e0e0; padding-left: 15px; margin-top: 15px;">
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" id="ransomware-signature-check" style="width: 18px; height: 18px;">
                                    <span><?php echo $currentLang === 'en' ? 'File signature check' : '파일 시그니처 검사'; ?></span>
                                </label>
                                <small class="form-hint"><?php echo $currentLang === 'en' ? 'Verify file header (magic bytes) matches extension.' : '파일 헤더(매직 바이트)가 확장자와 일치하는지 확인합니다.'; ?></small>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" id="ransomware-block-signature-mismatch" style="width: 18px; height: 18px;">
                                    <span><?php echo $currentLang === 'en' ? 'Block on signature mismatch' : '시그니처 불일치 시 차단'; ?></span>
                                </label>
                                <small class="form-hint"><?php echo $currentLang === 'en' ? 'When unchecked, only logs a warning.' : '체크 해제 시 경고 로그만 기록합니다.'; ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 로그 섹션 -->
                    <div style="border-top: 1px solid #ddd; padding-top: 20px;">
                        <h4 style="margin: 0 0 15px 0;"><?php echo $currentLang === 'en' ? '📋 Ransomware Protection Logs' : '📋 랜섬웨어 방지 로그'; ?></h4>
                        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                            <button type="button" class="btn btn-sm btn-outline" id="btn-load-ransomware-logs"><?php echo $currentLang === 'en' ? '🔄 Refresh Logs' : '🔄 로그 새로고침'; ?></button>
                            <button type="button" class="btn btn-sm btn-danger" id="btn-clear-ransomware-logs"><?php echo $currentLang === 'en' ? '🗑️ Delete Logs' : '🗑️ 로그 삭제'; ?></button>
                        </div>
                        <div id="ransomware-log-stats" style="font-size: 12px; color: #666; margin-bottom: 10px;"></div>
                        <div id="ransomware-log-content" style="max-height: 200px; overflow-y: auto; background: #f5f5f5; padding: 10px; border-radius: 4px; font-size: 12px;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary modal-close" style="font-size: 14px;"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-save-ransomware" style="font-size: 14px;"><?php echo $currentLang === 'en' ? '💾 Save' : '💾 저장'; ?></button>
                </div>
            </div>
            
            <!-- 접속 통계 모달 (관리자) -->
            <!-- 업로드 설정 모달 (관리자) -->
            <div id="modal-upload-settings" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2>📤 <?php echo $currentLang === 'en' ? 'Upload Settings' : '업로드 설정'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="border-radius: 8px; padding: 15px; margin-bottom: 20px; background: #e3f2fd;">
                        <p style="margin: 0; color: #1565c0;">
                            <strong>📤 <?php echo $currentLang === 'en' ? 'Upload File Type Restrictions' : '업로드 파일 유형 제한'; ?></strong><br>
                            <?php echo $currentLang === 'en' ? 'Control which file types users can upload. Choose between allow-list or block-list mode.' : '사용자가 업로드할 수 있는 파일 유형을 제어합니다. 허용 목록 또는 차단 목록 방식을 선택하세요.'; ?>
                        </p>
                        <p style="margin: 10px 0 0; color: #c62828; font-size: 12px;">
                            🔒 <?php echo $currentLang === 'en' 
                                ? 'Always blocked (server-executable files): php, phtml, php3~7, phar, phps, jsp, jspx, asp, aspx, cgi, htaccess, user.ini — These cannot be uploaded regardless of settings.' 
                                : '항상 차단 (서버 실행 파일): php, phtml, php3~7, phar, phps, jsp, jspx, asp, aspx, cgi, htaccess, user.ini — 설정과 관계없이 업로드할 수 없습니다.'; ?>
                        </p>
                    </div>
                    
                    <!-- 업로드 제한 모드 -->
                    <div style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 15px 0; color: #1565c0;">📋 <?php echo $currentLang === 'en' ? 'Upload Restriction Mode' : '업로드 제한 방식'; ?></h4>
                        
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 12px;">
                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                <input type="radio" name="upload-mode" value="all" id="upload-mode-all" style="width: 18px; height: 18px; margin-top: 1px; flex-shrink: 0;" checked>
                                <span><strong>*.*</strong> — <?php echo $currentLang === 'en' ? 'Allow all files (no restriction)' : '모든 파일 허용 (제한 없음)'; ?></span>
                            </label>
                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                <input type="radio" name="upload-mode" value="allow" id="upload-mode-allow" style="width: 18px; height: 18px; margin-top: 1px; flex-shrink: 0;">
                                <span><?php echo $currentLang === 'en' ? 'Allow only specified extensions (whitelist)' : '지정한 확장자만 허용 (화이트리스트)'; ?></span>
                            </label>
                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                <input type="radio" name="upload-mode" value="block" id="upload-mode-block" style="width: 18px; height: 18px; margin-top: 1px; flex-shrink: 0;">
                                <span><?php echo $currentLang === 'en' ? 'Block specified extensions (blacklist)' : '지정한 확장자 차단 (블랙리스트)'; ?></span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- 허용 확장자 (화이트리스트) -->
                    <div id="upload-allow-section" style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 20px; display: none;">
                        <h4 style="margin: 0 0 15px 0; color: #2e7d32;">✅ <?php echo $currentLang === 'en' ? 'Allowed Extensions' : '허용 확장자'; ?></h4>
                        
                        <div class="form-group">
                            <textarea id="upload-allowed-extensions" class="form-control" rows="3" placeholder="jpg, jpeg, png, gif, pdf, doc, docx, xls, xlsx, ppt, pptx, zip, mp4, mp3"></textarea>
                            <small class="form-hint"><?php echo $currentLang === 'en' ? 'Comma separated, without dots. Only these extensions can be uploaded.' : '쉼표(,)로 구분, 점(.) 없이 입력. 여기에 입력한 확장자만 업로드 가능합니다.'; ?></small>
                        </div>
                        
                        <div style="display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px;">
                            <button type="button" class="btn btn-sm btn-outline upload-preset-btn" data-preset="image"><?php echo $currentLang === 'en' ? '🖼️ Images' : '🖼️ 이미지'; ?></button>
                            <button type="button" class="btn btn-sm btn-outline upload-preset-btn" data-preset="document"><?php echo $currentLang === 'en' ? '📄 Documents' : '📄 문서'; ?></button>
                            <button type="button" class="btn btn-sm btn-outline upload-preset-btn" data-preset="media"><?php echo $currentLang === 'en' ? '🎬 Media' : '🎬 미디어'; ?></button>
                            <button type="button" class="btn btn-sm btn-outline upload-preset-btn" data-preset="archive"><?php echo $currentLang === 'en' ? '📦 Archives' : '📦 압축'; ?></button>
                            <button type="button" class="btn btn-sm btn-outline upload-preset-btn" data-preset="all-common"><?php echo $currentLang === 'en' ? '📎 All Common Types' : '📎 일반 파일 전체'; ?></button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('upload-allowed-extensions').value=''" style="color:#c62828;border-color:#c62828;">🗑️ <?php echo $currentLang === 'en' ? 'Clear' : '전체 삭제'; ?></button>
                        </div>
                    </div>
                    
                    <!-- 차단 확장자 (블랙리스트) -->
                    <div id="upload-block-section" style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 20px; display: none;">
                        <h4 style="margin: 0 0 15px 0; color: #c62828;">🚫 <?php echo $currentLang === 'en' ? 'Blocked Extensions' : '차단 확장자'; ?></h4>
                        
                        <div class="form-group">
                            <textarea id="upload-blocked-extensions" class="form-control" rows="3" placeholder="exe, bat, cmd, com, scr, pif, vbs, js, wsf, msi"></textarea>
                            <small class="form-hint"><?php echo $currentLang === 'en' ? 'Comma separated, without dots. These extensions will be blocked.' : '쉼표(,)로 구분, 점(.) 없이 입력. 여기에 입력한 확장자는 업로드가 차단됩니다.'; ?></small>
                        </div>
                        
                        <div style="display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px;">
                            <button type="button" class="btn btn-sm btn-outline upload-block-preset-btn" data-preset="executable"><?php echo $currentLang === 'en' ? '⚠️ Executables' : '⚠️ 실행파일'; ?></button>
                            <button type="button" class="btn btn-sm btn-outline upload-block-preset-btn" data-preset="script"><?php echo $currentLang === 'en' ? '📜 Scripts' : '📜 스크립트'; ?></button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('upload-blocked-extensions').value=''" style="color:#c62828;border-color:#c62828;">🗑️ <?php echo $currentLang === 'en' ? 'Clear' : '전체 삭제'; ?></button>
                        </div>
                    </div>
                    
                    <!-- MIME 검증 -->
                    <div style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 15px 0; color: #6a1b9a;">🔍 <?php echo $currentLang === 'en' ? 'MIME Type Verification' : 'MIME 타입 검증'; ?></h4>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="upload-mime-check" style="width: 18px; height: 18px; margin-top: 1px; flex-shrink: 0;" checked>
                                <div>
                                    <span><?php echo $currentLang === 'en' ? 'Verify file content matches extension (prevents disguised files)' : '파일 내용과 확장자 일치 여부 검증 (위장 파일 방지)'; ?></span>
                                    <small class="form-hint" style="margin-top: 4px; display: block;"><?php echo $currentLang === 'en' ? 'When enabled, blocks files with mismatched extension and content (e.g. exe renamed to jpg)' : '활성화하면 확장자와 실제 내용이 다른 파일을 차단합니다 (예: exe를 jpg로 변경한 파일)'; ?></small>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <!-- 최대 파일 크기 -->
                    <div style="border: 1px solid #ddd; border-radius: 8px; padding: 15px;">
                        <h4 style="margin: 0 0 15px 0; color: #e65100;">📏 <?php echo $currentLang === 'en' ? 'Max File Size' : '최대 파일 크기'; ?></h4>
                        
                        <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" id="upload-max-filesize" class="form-control" style="width: 120px;" min="0" value="0">
                            <span>MB</span>
                            <small class="form-hint"><?php echo $currentLang === 'en' ? '(0 = unlimited. Per-file size limit regardless of chunked upload)' : '(0 = 무제한. 청크 업로드와 무관하게 개별 파일 크기를 제한합니다)'; ?></small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary modal-close" style="font-size: 14px;"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-save-upload-settings" style="font-size: 14px;">💾 <?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                </div>
            </div>

            <div id="modal-access-stats" class="modal modal-xl" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? '📊 Access Statistics' : '📊 접속 통계'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- 1. 요약 카드 -->
                    <div class="stats-summary-box">
                        <div class="stats-summary-item">
                            <div class="stats-summary-title"><?php echo $currentLang === 'en' ? 'Today\'s Access' : '오늘 접속'; ?></div>
                            <div class="stats-summary-value" id="stats-today-count">0</div>
                            <div class="stats-summary-sub">(<span id="stats-today-users">0</span><?php echo $currentLang === 'en' ? ' users' : '명'; ?>)</div>
                        </div>
                        <div class="stats-summary-divider"></div>
                        <div class="stats-summary-item">
                            <div class="stats-summary-title"><?php echo $currentLang === 'en' ? 'Yesterday' : '어제 접속'; ?></div>
                            <div class="stats-summary-value" id="stats-yesterday-count">0</div>
                            <div class="stats-summary-sub">(<span id="stats-yesterday-users">0</span><?php echo $currentLang === 'en' ? ' users' : '명'; ?>)</div>
                        </div>
                        <div class="stats-summary-divider"></div>
                        <div class="stats-summary-item">
                            <div class="stats-summary-title"><?php echo $currentLang === 'en' ? 'Last 7 Days' : '최근 7일'; ?></div>
                            <div class="stats-summary-value" id="stats-week-count">0</div>
                            <div class="stats-summary-sub">(<span id="stats-week-users">0</span><?php echo $currentLang === 'en' ? ' users' : '명'; ?>)</div>
                        </div>
                        <div class="stats-summary-divider"></div>
                        <div class="stats-summary-item">
                            <div class="stats-summary-title"><?php echo $currentLang === 'en' ? 'Last 30 Days' : '최근 30일'; ?></div>
                            <div class="stats-summary-value" id="stats-month-count">0</div>
                            <div class="stats-summary-sub">(<span id="stats-month-users">0</span><?php echo $currentLang === 'en' ? ' users' : '명'; ?>)</div>
                        </div>
                    </div>
                    
                    <!-- 2. 접속 통계 탭 -->
                    <div class="stats-tabs">
                        <button class="stats-tab active" data-tab="hourly"><?php echo $currentLang === 'en' ? '🕐 Hourly' : '🕐 시간대별'; ?></button>
                        <button class="stats-tab" data-tab="daily"><?php echo $currentLang === 'en' ? '📅 Daily' : '📅 일별'; ?></button>
                        <button class="stats-tab" data-tab="monthly"><?php echo $currentLang === 'en' ? '📆 Monthly' : '📆 월별'; ?></button>
                        <button class="stats-tab" data-tab="yearly"><?php echo $currentLang === 'en' ? '📊 Yearly' : '📊 년도별'; ?></button>
                    </div>
                    
                    <!-- 시간대별 통계 -->
                    <div class="stats-content active" id="stats-hourly">
                        <div class="hourly-table-wrapper">
                            <table class="hourly-table">
                                <thead>
                                    <tr id="hourly-header-1"></tr>
                                    <tr id="hourly-data-1"></tr>
                                </thead>
                            </table>
                            <table class="hourly-table" style="margin-top:10px;">
                                <thead>
                                    <tr id="hourly-header-2"></tr>
                                    <tr id="hourly-data-2"></tr>
                                </thead>
                            </table>
                        </div>
                        <p class="stats-note"><?php echo $currentLang === 'en' ? '* Today\'s hourly access status' : '* 오늘 시간대별 접속 현황'; ?></p>
                    </div>
                    
                    <!-- 일별 통계 -->
                    <div class="stats-content" id="stats-daily">
                        <div class="stats-table-wrapper">
                            <table class="stats-table stats-table-full">
                                <thead>
                                    <tr>
                                        <th><?php echo $currentLang === 'en' ? 'Date' : '날짜'; ?></th>
                                        <th><?php echo $currentLang === 'en' ? 'Logins' : '로그인 수'; ?></th>
                                        <th><?php echo $currentLang === 'en' ? 'Users' : '사용자 수'; ?></th>
                                    </tr>
                                </thead>
                                <tbody id="stats-daily-tbody"></tbody>
                            </table>
                        </div>
                        <p class="stats-note"><?php echo $currentLang === 'en' ? '* Last 30 days' : '* 최근 30일간 기록'; ?></p>
                    </div>
                    
                    <!-- 월별 통계 -->
                    <div class="stats-content" id="stats-monthly">
                        <div class="stats-table-wrapper">
                            <table class="stats-table stats-table-full">
                                <thead>
                                    <tr>
                                        <th><?php echo $currentLang === 'en' ? 'Month' : '월'; ?></th>
                                        <th><?php echo $currentLang === 'en' ? 'Logins' : '로그인 수'; ?></th>
                                        <th><?php echo $currentLang === 'en' ? 'Users' : '사용자 수'; ?></th>
                                    </tr>
                                </thead>
                                <tbody id="stats-monthly-tbody"></tbody>
                            </table>
                        </div>
                        <p class="stats-note"><?php echo $currentLang === 'en' ? '* Last 12 months' : '* 최근 12개월 기록'; ?></p>
                    </div>
                    
                    <!-- 년도별 통계 -->
                    <div class="stats-content" id="stats-yearly">
                        <div class="stats-table-wrapper">
                            <table class="stats-table stats-table-full">
                                <thead>
                                    <tr>
                                        <th><?php echo $currentLang === 'en' ? 'Year' : '년도'; ?></th>
                                        <th><?php echo $currentLang === 'en' ? 'Logins' : '로그인 수'; ?></th>
                                        <th><?php echo $currentLang === 'en' ? 'Users' : '사용자 수'; ?></th>
                                    </tr>
                                </thead>
                                <tbody id="stats-yearly-tbody"></tbody>
                            </table>
                        </div>
                        <p class="stats-note"><?php echo $currentLang === 'en' ? '* Logged-in users only, bots excluded / Unique visitors in parentheses' : '* 로그인 사용자 기준, 봇 제외 / 괄호 안은 순 방문자 수'; ?></p>
                    </div>
                </div>
            </div>
            
            <!-- 범용 확인 모달 -->
            <div id="modal-confirm" class="modal" style="display:none; max-width: 480px;">
                <div class="modal-header">
                    <h2 id="confirm-modal-title"><?php echo $currentLang === 'en' ? 'Confirm' : '확인'; ?></h2>
                    <button class="modal-close" onclick="App.confirmModalCancel()">&times;</button>
                </div>
                <div class="modal-body" style="padding: 15px 20px;">
                    <div id="confirm-modal-content" style="line-height: 1.5; word-break: keep-all;"></div>
                </div>
                <div class="modal-footer" id="confirm-modal-footer" style="padding: 12px 20px;">
                    <!-- 버튼은 동적으로 생성됨 -->
                </div>
            </div>
            
            <!-- 삭제된 사용자 모달 (관리자) -->
            <div id="modal-deleted-users" class="modal modal-xl" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? '👻 Withdrawn/Deleted Users' : '👻 탈퇴/삭제된 사용자'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="text-muted" style="margin-bottom: 10px; font-size:13px;"><?php echo $currentLang === 'en' ? 'Records of users who withdrew or were deleted by admin.' : '회원 탈퇴 또는 관리자가 삭제한 사용자 기록입니다.'; ?></p>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;padding:8px 12px;background:#f8f9fa;border-radius:6px;border:1px solid #e9ecef;flex-wrap:wrap;">
                        <label style="font-size:12px;display:flex;align-items:center;gap:4px;">
                            <input type="checkbox" id="setting-auto-purge-deleted-modal">
                            <span><?php echo $currentLang === 'en' ? 'Auto-delete after' : '자동 삭제'; ?></span>
                        </label>
                        <input type="number" id="setting-auto-purge-days-modal" min="1" max="9999" value="90" style="width:60px;padding:3px 6px;border:1px solid #ccc;border-radius:4px;font-size:12px;">
                        <span style="font-size:12px;"><?php echo $currentLang === 'en' ? 'days' : '일 경과 시 자동 삭제'; ?></span>
                        <button class="btn btn-sm" id="btn-save-auto-purge" style="font-size:11px;padding:3px 8px;"><?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                    </div>
                    <div class="deleted-users-wrapper">
                        <table class="deleted-users-table" style="width:100%;table-layout:fixed;">
                            <thead>
                                <tr>
                                    <th style="width:15%;"><?php echo $currentLang === 'en' ? 'Username' : '아이디'; ?></th>
                                    <th style="width:8%;"><?php echo $currentLang === 'en' ? 'Role' : '역할'; ?></th>
                                    <th style="width:14%;"><?php echo $currentLang === 'en' ? 'Joined' : '가입일'; ?></th>
                                    <th style="width:9%;"><?php echo $currentLang === 'en' ? 'Type' : '유형'; ?></th>
                                    <th style="width:14%;"><?php echo $currentLang === 'en' ? 'Deleted' : '삭제일'; ?></th>
                                    <th style="width:10%;"><?php echo $currentLang === 'en' ? 'By' : '처리자'; ?></th>
                                    <th style="width:10%;"><?php echo $currentLang === 'en' ? 'Logs' : '로그'; ?></th>
                                    <th style="width:7%;"><?php echo $currentLang === 'en' ? 'Files' : '파일'; ?></th>
                                    <th style="width:13%;"><?php echo $currentLang === 'en' ? 'Actions' : '관리'; ?></th>
                                </tr>
                            </thead>
                            <tbody id="deleted-users-tbody"></tbody>
                        </table>
                    </div>
                    <div id="deleted-users-empty" style="display:none; text-align:center; padding:40px; color:#888;">
                        <div style="font-size:48px; margin-bottom:10px;">📭</div>
                        <p><?php echo $currentLang === 'en' ? 'No deleted user records.' : '탈퇴/삭제된 사용자 기록이 없습니다.'; ?></p>
                    </div>
                </div>
            </div>
            
            <!-- 공지 설정 모달 (관리자) -->
            <div id="modal-notice" class="modal modal-xl" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? '📢 Notice Settings (Popups + Banner)' : '📢 공지 설정 (다중 팝업 + 상단 배너)'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- 팝업 관리 섹션 -->
                    <div class="notice-section">
                        <div class="notice-section-header">
                            <h3>📋 <?php echo $currentLang === 'en' ? 'Popup Management' : '팝업 관리'; ?> <small style="font-weight:normal; color:#888;"><?php echo $currentLang === 'en' ? 'Shown top-left after login (max 10)' : '로그인 후 왼쪽 상단에 표시 (최대 10개)'; ?></small></h3>
                            <button class="btn btn-primary btn-sm" id="btn-add-popup"><?php echo $currentLang === 'en' ? '+ Add Popup' : '+ 팝업 추가'; ?></button>
                        </div>
                        
                        <!-- 팝업 배치 설정 -->
                        <div class="popup-layout-settings" style="background:#f8f9fa; border:1px solid #e0e0e0; border-radius:8px; padding:15px; margin-bottom:15px;">
                            <div class="popup-layout-row" style="display:flex; flex-wrap:wrap; gap:20px; align-items:center;">
                                <div>
                                    <label style="font-weight:500; margin-right:10px;"><?php echo $currentLang === 'en' ? '📐 Layout Method:' : '📐 배치 방식:'; ?></label>
                                    <select id="popup-layout" class="form-control" style="width:auto; display:inline-block;">
                                        <option value="horizontal"><?php echo $currentLang === 'en' ? 'Horizontal (1 2 3)' : '가로 배치 (1 2 3)'; ?></option>
                                        <option value="vertical"><?php echo $currentLang === 'en' ? 'Vertical (1 column)' : '세로 배치 (1열)'; ?></option>
                                        <option value="grid-2xN"><?php echo $currentLang === 'en' ? 'Grid 2 columns' : '격자 2열'; ?></option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-weight:500; margin-right:10px;"><?php echo $currentLang === 'en' ? '📏 Default Size:' : '📏 기본 크기:'; ?></label>
                                    <input type="number" id="popup-default-width" value="350" min="200" max="800" style="width:70px;"> x 
                                    <input type="number" id="popup-default-height" value="250" min="150" max="800" style="width:70px;"> px
                                </div>
                                <div>
                                    <label style="font-weight:500; margin-right:10px;"><?php echo $currentLang === 'en' ? '↔️ Spacing:' : '↔️ 간격:'; ?></label>
                                    <input type="number" id="popup-gap" value="20" min="0" max="50" style="width:60px;"> px
                                </div>
                                <div>
                                    <label style="font-weight:500; margin-right:10px;"><?php echo $currentLang === 'en' ? '📍 Start Position:' : '📍 시작 좌표:'; ?></label>
                                    X <input type="number" id="popup-start-x" value="20" min="0" max="2000" style="width:70px;">
                                    Y <input type="number" id="popup-start-y" value="80" min="0" max="2000" style="width:70px;"> px
                                </div>
                            </div>
                            <div style="margin-top:6px; font-size:11px; color:#888;">
                                <?php echo $currentLang === 'en' 
                                    ? '💡 Start Position: Default coordinates for auto-layout. Each popup can override with its own X/Y position.' 
                                    : '💡 시작 좌표: 자동 배치 시 기본 좌표. 개별 팝업에 X/Y 좌표를 설정하면 해당 좌표가 우선 적용됩니다.'; ?>
                            </div>
                        </div>
                        
                        <!-- 팝업 테이블 리스트 -->
                        <div id="popup-list-wrapper" style="display:none;">
                            <table class="popup-list-table" id="popup-list-table">
                                <thead>
                                    <tr>
                                        <th style="width:35px;"><?php echo $currentLang === 'en' ? 'Order' : '순서'; ?></th>
                                        <th style="width:45px;"><?php echo $currentLang === 'en' ? 'Status' : '상태'; ?></th>
                                        <th style="width:15%;"><?php echo $currentLang === 'en' ? 'Title' : '제목'; ?></th>
                                        <th style="width:20%;"><?php echo $currentLang === 'en' ? 'Content' : '내용'; ?></th>
                                        <th style="width:85px;"><?php echo $currentLang === 'en' ? 'Display' : '표시방식'; ?></th>
                                        <th style="width:70px;"><?php echo $currentLang === 'en' ? 'Size' : '크기'; ?></th>
                                        <th style="width:55px;"><?php echo $currentLang === 'en' ? 'Period' : '기간'; ?></th>
                                        <th style="width:40px;"><?php echo $currentLang === 'en' ? 'Image' : '이미지'; ?></th>
                                        <th style="width:45px;"><?php echo $currentLang === 'en' ? 'Header' : '헤더색'; ?></th>
                                        <th style="width:65px;"><?php echo $currentLang === 'en' ? 'Actions' : '작업'; ?></th>
                                    </tr>
                                </thead>
                                <tbody id="popup-list"></tbody>
                            </table>
                        </div>
                        <div id="popup-empty" style="text-align:center; padding:40px; color:#888; display:none;">
                            <div style="font-size:32px; margin-bottom:10px;">🗨️</div>
                            <p><?php echo $currentLang === 'en' ? 'No popups registered.' : '등록된 팝업이 없습니다.'; ?></p>
                            <button class="btn btn-primary" onclick="App.addPopup()"><?php echo $currentLang === 'en' ? '+ Add First Popup' : '+ 첫 팝업 추가하기'; ?></button>
                        </div>
                    </div>
                    
                    <hr style="margin: 30px 0; border: none; border-top: 2px dashed #ddd;">
                    
                    <!-- 상단 배너 섹션 -->
                    <div class="notice-section">
                        <h3>📣 <?php echo $currentLang === 'en' ? 'Top Banner' : '상단 배너'; ?> <small style="font-weight:normal; color:#888;"><?php echo $currentLang === 'en' ? 'One-line notice shown at top of file list' : '파일 목록 상단에 표시되는 한 줄 공지'; ?></small></h3>
                        
                        <table class="notice-form-table">
                            <tr>
                                <th><?php echo $currentLang === 'en' ? '✅ Enable' : '✅ 활성화'; ?></th>
                                <td>
                                    <label class="toggle-label" style="display:inline-flex; align-items:center; gap:10px; cursor:pointer;">
                                        <input type="checkbox" id="banner-enabled" style="width:18px; height:18px;">
                                        <span style="font-weight:500;"><?php echo $currentLang === 'en' ? 'Enable Banner' : '배너 활성화'; ?></span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo $currentLang === 'en' ? '📝 Banner Content' : '📝 배너 내용'; ?></th>
                                <td><input type="text" id="banner-content" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Enter banner content' : '배너에 표시할 내용을 입력하세요'; ?>"></td>
                            </tr>
                            <tr>
                                <th><?php echo $currentLang === 'en' ? '🔗 Link (optional)' : '🔗 링크 (선택)'; ?></th>
                                <td>
                                    <input type="text" id="banner-link" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'e.g., https://example.com/notice' : '예: https://example.com/notice'; ?>">
                                    <small class="text-muted"><?php echo $currentLang === 'en' ? 'URL to navigate on click (leave empty to disable click)' : '클릭 시 이동할 URL (비워두면 클릭 불가)'; ?></small>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo $currentLang === 'en' ? '📅 Start Date' : '📅 시작일'; ?></th>
                                <td>
                                    <input type="date" id="banner-start-date" lang="<?php echo $currentLang; ?>" class="form-control" style="width:auto;">
                                    <small class="text-muted"><?php echo $currentLang === 'en' ? 'Leave empty to start immediately' : '비워두면 즉시 시작'; ?></small>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo $currentLang === 'en' ? '📅 End Date' : '📅 종료일'; ?></th>
                                <td>
                                    <input type="date" id="banner-end-date" lang="<?php echo $currentLang; ?>" class="form-control" style="width:auto;">
                                    <small class="text-muted"><?php echo $currentLang === 'en' ? 'Leave empty for indefinite' : '비워두면 무기한'; ?></small>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo $currentLang === 'en' ? '🎨 Background Color' : '🎨 배경색'; ?></th>
                                <td><input type="color" id="banner-bg-color" value="#fff3cd" style="width:60px; height:35px; cursor:pointer;"></td>
                            </tr>
                            <tr>
                                <th><?php echo $currentLang === 'en' ? '🎨 Text Color' : '🎨 글자색'; ?></th>
                                <td><input type="color" id="banner-text-color" value="#856404" style="width:60px; height:35px; cursor:pointer;"></td>
                            </tr>
                            <tr>
                                <th><?php echo $currentLang === 'en' ? '👁️ Preview' : '👁️ 미리보기'; ?></th>
                                <td>
                                    <div id="banner-preview" style="padding:12px 20px; border-radius:4px; text-align:left;">
                                        <?php echo $currentLang === 'en' ? 'Banner content will appear here' : '배너 내용이 여기에 표시됩니다'; ?>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div style="text-align:center; margin-top:20px; padding-top:20px; border-top:1px solid #eee;">
                        <button class="btn btn-primary btn-lg" id="btn-save-notice"><?php echo $currentLang === 'en' ? '💾 Save Notice Settings' : '💾 공지 설정 저장'; ?></button>
                    </div>
                </div>
            </div>
            
            <!-- 팝업 편집 모달 -->
            <div id="modal-popup-edit" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2 id="popup-edit-title"><?php echo $currentLang === 'en' ? '📝 Edit Popup' : '📝 팝업 편집'; ?></h2>
                    <button class="modal-close" onclick="App.closePopupEditModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="popup-edit-index" value="-1">
                    
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? '📝 Title' : '📝 제목'; ?></label>
                        <input type="text" id="popup-edit-title-input" class="form-control" placeholder="<?php echo $currentLang === 'en' ? 'Popup Title' : '팝업 제목'; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? '📄 Content (HTML supported)' : '📄 내용 (HTML 지원)'; ?></label>
                        <textarea id="popup-edit-content" class="form-control" rows="5" placeholder="<?php echo $currentLang === 'en' ? 'Enter popup content' : '팝업 내용을 입력하세요'; ?>"></textarea>
                    </div>
                    
                    <hr style="margin: 20px 0;">
                    
                    <div class="form-row" style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? '🖼️ Image URL' : '🖼️ 이미지 URL'; ?></label>
                            <input type="text" id="popup-edit-image-url" class="form-control" placeholder="https://example.com/image.jpg">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? '📤 Upload Image' : '📤 이미지 업로드'; ?></label>
                            <input type="file" id="popup-edit-image-file" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control">
                            <small class="text-muted"><?php echo $currentLang === 'en' ? 'jpg, png, gif, webp (max 2MB)' : 'jpg, png, gif, webp (최대 2MB)'; ?></small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? '🖼️ Current Image' : '🖼️ 현재 이미지'; ?></label>
                        <div id="popup-edit-image-preview" class="popup-image-preview empty"><?php echo $currentLang === 'en' ? 'No Image' : '이미지 없음'; ?></div>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? '👁️ Display Method' : '👁️ 표시 방식'; ?></label>
                        <select id="popup-edit-display-mode" class="form-control">
                            <option value="both"><?php echo $currentLang === 'en' ? '🖼️+📝 Image + Text (Default)' : '🖼️+📝 이미지 + 텍스트 (기본)'; ?></option>
                            <option value="image"><?php echo $currentLang === 'en' ? '🖼️ Image Only' : '🖼️ 이미지만'; ?></option>
                            <option value="text"><?php echo $currentLang === 'en' ? '📝 Text Only' : '📝 텍스트만'; ?></option>
                        </select>
                    </div>
                    
                    <hr style="margin: 20px 0;">
                    
                    <div class="form-row" style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? '📅 Start Date' : '📅 시작일'; ?></label>
                            <input type="date" id="popup-edit-start-date" lang="<?php echo $currentLang; ?>" class="form-control">
                            <small class="text-muted"><?php echo $currentLang === 'en' ? 'Leave empty to start immediately' : '비워두면 즉시 시작'; ?></small>
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? '📅 End Date' : '📅 종료일'; ?></label>
                            <input type="date" id="popup-edit-end-date" lang="<?php echo $currentLang; ?>" class="form-control">
                            <small class="text-muted"><?php echo $currentLang === 'en' ? 'Leave empty for indefinite' : '비워두면 무기한'; ?></small>
                        </div>
                    </div>
                    
                    <div class="form-row" style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? '↔️ Width (px)' : '↔️ 가로 크기 (px)'; ?></label>
                            <input type="number" id="popup-edit-width" class="form-control" value="350" min="200" max="800">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? '↕️ Height (px)' : '↕️ 세로 크기 (px)'; ?></label>
                            <input type="number" id="popup-edit-height" class="form-control" value="250" min="150" max="800">
                        </div>
                    </div>
                    
                    <div style="display:flex; gap:15px; flex-wrap:wrap;">
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? '📍 Position X (px)' : '📍 위치 X (px)'; ?></label>
                            <input type="number" id="popup-edit-pos-x" class="form-control" value="" min="0" max="3000" placeholder="<?php echo $currentLang === 'en' ? 'Auto' : '자동'; ?>">
                        </div>
                        <div class="form-group">
                            <label><?php echo $currentLang === 'en' ? '📍 Position Y (px)' : '📍 위치 Y (px)'; ?></label>
                            <input type="number" id="popup-edit-pos-y" class="form-control" value="" min="0" max="3000" placeholder="<?php echo $currentLang === 'en' ? 'Auto' : '자동'; ?>">
                        </div>
                    </div>
                    <div style="font-size:11px; color:#888; margin-bottom:10px;">
                        <?php echo $currentLang === 'en' 
                            ? '💡 Leave empty to use auto-layout position. Set X/Y to place this popup at a specific location.' 
                            : '💡 비워두면 자동 배치. X/Y를 입력하면 해당 위치에 고정 표시됩니다.'; ?>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo $currentLang === 'en' ? '🎨 Header Background Color' : '🎨 헤더 배경색'; ?></label>
                        <input type="color" id="popup-edit-bg-color" class="form-control" value="#667eea" style="width:80px; height:36px; padding:2px;">
                    </div>
                    
                    <div class="form-group">
                        <label class="toggle-label" style="display:inline-flex; align-items:center; gap:10px; cursor:pointer;">
                            <input type="checkbox" id="popup-edit-enabled" style="width:18px; height:18px;" checked>
                            <span style="font-weight:500;"><?php echo $currentLang === 'en' ? '✅ Enable Popup' : '✅ 팝업 활성화'; ?></span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="App.closePopupEditModal()"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
                    <button class="btn btn-primary" id="btn-save-popup-edit"><?php echo $currentLang === 'en' ? '💾 Save' : '💾 저장'; ?></button>
                </div>
            </div>
            
            <!-- 가입시 이용약관 모달 (관리자) -->
            <div id="modal-terms" class="modal modal-lg" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? '📜 Registration Terms of Service' : '📜 가입시 이용약관'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="text-muted" style="margin-bottom: 15px;"><?php echo $currentLang === 'en' ? 'Terms of Service shown during registration.' : '회원가입 시 표시되는 이용약관입니다.'; ?></p>
                    
                    <!-- 활성화 체크박스 -->
                    <div class="form-group" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <label class="toggle-label" style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="terms-enabled" style="width: 18px; height: 18px;">
                            <span style="font-weight: 500;"><?php echo $currentLang === 'en' ? 'Enable Terms' : '이용약관 사용'; ?></span>
                        </label>
                        <p class="text-muted" style="margin: 10px 0 0 28px; font-size: 12px;">
                            <?php echo $currentLang === 'en' ? 'When checked, a terms agreement step will be shown during registration.' : '체크하면 회원가입 시 이용약관 동의 단계가 표시됩니다.'; ?>
                        </p>
                    </div>
                    
                    <div class="form-group terms-editor-group">
                        <label><?php echo $currentLang === 'en' ? 'Terms Content (HTML supported)' : '이용약관 내용 (HTML 지원)'; ?></label>
                        <textarea id="terms-content" class="terms-editor" placeholder="<?php echo $currentLang === 'en' ? 'Enter terms of service content...' : '이용약관 내용을 입력하세요...'; ?>"></textarea>
                    </div>
                    <div id="terms-updated" class="text-muted" style="font-size: 12px; margin-bottom: 15px;"></div>
                    <button class="btn btn-primary" id="btn-save-terms"><?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
                </div>
            </div>
            
            <!-- 파일 미리보기 모달 -->
            <div id="modal-preview" class="modal modal-preview resizable" style="display:none;">
                <div class="modal-header">
                    <h2 id="preview-title"><?php echo $currentLang === 'en' ? 'Preview' : '미리보기'; ?></h2>
                    <span id="preview-counter" style="color:#888;font-size:14px;margin-left:10px;margin-right:12px;white-space:nowrap;flex-shrink:0;"></span>
                    <button id="btn-preview-download-header" class="preview-header-dl" onclick="document.getElementById('btn-preview-download').click();" title="<?php echo $currentLang === 'en' ? 'Download' : '다운로드'; ?>">⬇️</button>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body" style="position:relative;">
                    <div id="preview-content"></div>
                </div>
                <!-- 이전/다음 버튼 (modal-body 밖 → 스크롤 영향 안 받음) -->
                <button id="preview-prev" class="preview-nav-btn preview-prev" style="display:none;" title="<?php echo $currentLang === 'en' ? 'Previous (←)' : '이전 (←)'; ?>"><span>❮</span></button>
                <button id="preview-next" class="preview-nav-btn preview-next" style="display:none;" title="<?php echo $currentLang === 'en' ? 'Next (→)' : '다음 (→)'; ?>"><span>❯</span></button>
                <!-- 이미지 뷰어 컨트롤바 (modal-body 밖에 배치 → Viewer.js 이벤트 차단 우회) -->
                <div id="preview-image-zoom-bar" class="preview-image-zoom-bar" style="display:none;position:absolute;bottom:40px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.72);border-radius:24px;padding:5px 14px;align-items:center;gap:2px;z-index:9999;white-space:nowrap;pointer-events:all;">
                    <button data-action="zoom-out" title="축소" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer;padding:2px 6px;line-height:1;">－</button>
                    <span id="img-zoom-level" style="color:#fff;font-size:12px;min-width:38px;text-align:center;font-weight:bold;">맞춤</span>
                    <button data-action="zoom-in" title="확대" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer;padding:2px 6px;line-height:1;">＋</button>
                    <span style="color:rgba(255,255,255,0.25);padding:0 3px;">│</span>
                    <button data-action="fit-width"  title="너비 맞춤" style="background:none;border:none;color:#fff;cursor:pointer;padding:2px 6px;line-height:0;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12H3"/><polyline points="7 8 3 12 7 16"/><polyline points="17 8 21 12 17 16"/></svg></button>
                    <button data-action="fit-height" title="높이 맞춤" style="background:none;border:none;color:#fff;cursor:pointer;padding:2px 6px;line-height:0;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18"/><polyline points="8 7 12 3 16 7"/><polyline points="8 17 12 21 16 17"/></svg></button>
                    <button data-action="fit-screen" title="화면 맞춤" style="background:none;border:none;color:#fff;cursor:pointer;padding:2px 6px;line-height:0;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/><path d="M15 3v18"/><path d="M3 9h18"/><path d="M3 15h18"/></svg></button>
                    <button data-action="zoom-1to1"  title="원본 크기 (1:1)" style="background:none;border:none;color:#fff;font-size:10px;cursor:pointer;padding:2px 6px;line-height:1;">1:1</button>
                    <span style="color:rgba(255,255,255,0.25);padding:0 3px;">│</span>
                    <button data-action="rotate"     title="회전" style="background:none;border:none;color:#fff;cursor:pointer;padding:2px 6px;line-height:0;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6"/><path d="M21.34 15.57a10 10 0 1 1-.57-8.38L21.5 8"/></svg></button>
                    <button data-action="fullscreen" title="전체화면" style="background:none;border:none;color:#fff;cursor:pointer;padding:2px 6px;line-height:0;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg></button>
                </div>
                <div class="modal-footer preview-footer">
                    <button class="btn" id="btn-preview-edit" style="display:none;"><?php echo $currentLang === 'en' ? '✏️ Edit' : '✏️ 편집'; ?></button>
                    <button class="btn" id="btn-preview-download"><?php echo $currentLang === 'en' ? '⬇️ Download' : '⬇️ 다운로드'; ?></button>
                </div>
                <div class="resize-handle resize-handle-se"></div>
                <div class="resize-handle resize-handle-e"></div>
                <div class="resize-handle resize-handle-s"></div>
            </div>
            
            <!-- 중복 파일 처리 모달 -->
            <div id="modal-duplicate" class="modal" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? '⚠️ Duplicate Files Found' : '⚠️ 중복 파일 발견'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <p id="duplicate-message"><?php echo $currentLang === 'en' ? 'The following files already exist:' : '다음 파일이 이미 존재합니다:'; ?></p>
                    <div id="duplicate-list" class="duplicate-list"></div>
                    <p class="duplicate-hint"><?php echo $currentLang === 'en' ? 'How would you like to proceed?' : '어떻게 처리하시겠습니까?'; ?></p>
                    <div class="vpn-warning" style="background:#fff3cd;color:#856404;padding:8px 12px;border-radius:4px;font-size:12px;margin-top:12px;">
                        <?php echo $currentLang === 'en' ? '⚠️ Upload speed may be much slower when using VPN. If slow, please disconnect VPN.' : '⚠️ VPN 사용 시 업로드 속도가 현저히 느려질 수 있습니다. 속도가 느리다면 VPN을 종료해주세요.'; ?>
                    </div>
                </div>
                <div class="modal-footer duplicate-footer">
                    <button class="btn" id="btn-dup-skip-all"><?php echo $currentLang === 'en' ? 'Skip' : '건너뛰기'; ?></button>
                    <button class="btn btn-warning" id="btn-dup-overwrite-all"><?php echo $currentLang === 'en' ? 'Overwrite' : '덮어쓰기'; ?></button>
                    <button class="btn btn-primary" id="btn-dup-rename-all"><?php echo $currentLang === 'en' ? 'Rename and Copy' : '이름 변경 후 복사'; ?></button>
                </div>
            </div>
            
            <!-- 파일 버전 모달 -->
            <div id="modal-versions" class="modal modal-md" style="display:none;">
                <div class="modal-header">
                    <h2><?php echo $currentLang === 'en' ? '📜 Previous Versions' : '📜 이전 버전'; ?></h2>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="version-file-info">
                        <strong><?php echo $currentLang === 'en' ? '📄 File:' : '📄 파일:'; ?></strong> <span id="version-filename">-</span>
                    </div>
                    <div id="version-list-container">
                        <div id="version-loading" style="text-align:center; padding:30px; color:#888;">
                            <div>⏳</div>
                            <p><?php echo $currentLang === 'en' ? 'Loading version list...' : '버전 목록을 불러오는 중...'; ?></p>
                        </div>
                        <div id="version-empty" style="display:none; text-align:center; padding:30px; color:#888;">
                            <div style="font-size:32px; margin-bottom:10px;">📭</div>
                            <p><?php echo $currentLang === 'en' ? 'No previous versions saved.' : '저장된 이전 버전이 없습니다.'; ?></p>
                            <small><?php echo $currentLang === 'en' ? 'File versioning can be enabled in ransomware protection settings.' : '파일 버전 관리는 랜섬웨어 방지 설정에서 활성화할 수 있습니다.'; ?></small>
                        </div>
                        <table id="version-list-table" class="data-table" style="display:none;">
                            <thead>
                                <tr>
                                    <th style="width:50%;"><?php echo $currentLang === 'en' ? 'Save Time' : '저장 시간'; ?></th>
                                    <th style="width:25%;"><?php echo $currentLang === 'en' ? 'Size' : '크기'; ?></th>
                                    <th style="width:25%;"><?php echo $currentLang === 'en' ? 'Actions' : '작업'; ?></th>
                                </tr>
                            </thead>
                            <tbody id="version-list"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer" style="text-align: right; padding: 10px 15px; border-top: 1px solid #eee;">
                    <button class="btn btn-secondary modal-close" style="padding: 6px 16px; font-size: 13px;"><?php echo $currentLang === 'en' ? 'Close' : '닫기'; ?></button>
                </div>
            </div>

<!-- SSO 설정 모달 -->
<div id="modal-sso" class="modal modal-xl" style="display:none;">
        <div class="modal-header">
            <h3>🔐 <?php echo $currentLang === 'en' ? 'SSO Authentication Settings' : 'SSO 인증 설정'; ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body" style="padding:12px 15px;">
            <!-- 탭 -->
            <div style="display:flex;gap:4px;margin-bottom:15px;border-bottom:2px solid #eee;padding-bottom:8px;">
                <button class="btn btn-sm sso-tab active" data-tab="kerberos" onclick="App.switchSSOTab('kerberos')">🪪 Kerberos/SPNEGO</button>
                <button class="btn btn-sm sso-tab" data-tab="ldap" onclick="App.switchSSOTab('ldap')">🏢 LDAP/AD</button>
                <button class="btn btn-sm sso-tab" data-tab="oidc" onclick="App.switchSSOTab('oidc')">🌐 OIDC</button>
                <button class="btn btn-sm sso-tab" data-tab="saml" onclick="App.switchSSOTab('saml')">🔑 SAML 2.0</button>
            </div>
            
            <!-- 서버 환경 감지 정보 -->
            <div id="sso-env-info"></div>
            
            <!-- Kerberos/SPNEGO -->
            <div id="sso-tab-kerberos" class="sso-tab-content">
                <div style="margin-bottom:10px;">
                    <label style="font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;">
                        <input type="checkbox" id="sso-kerberos-enabled">
                        <?php echo $currentLang === 'en' ? 'Enable Kerberos/SPNEGO Auto-Login' : 'Kerberos/SPNEGO 자동 로그인 활성화'; ?>
                    </label>
                    <small style="color:#888;display:block;margin-top:4px;"><?php echo $currentLang === 'en' ? 'AD-joined Windows PCs will automatically log in without entering ID/PW. Requires Apache mod_auth_kerb (Linux) or IIS Windows Authentication.' : 'AD 조인된 Windows PC에서 ID/PW 입력 없이 자동 로그인됩니다. Apache mod_auth_kerb(Linux) 또는 IIS Windows 인증이 필요합니다.'; ?></small>
                </div>
                <div style="margin-bottom:10px;">
                    <label style="font-size:12px;font-weight:600;"><?php echo $currentLang === 'en' ? 'Auto-create accounts' : '계정 자동 생성'; ?></label>
                    <label style="font-size:12px;display:flex;align-items:center;gap:6px;margin-top:4px;">
                        <input type="checkbox" id="sso-kerberos-auto-create" checked>
                        <?php echo $currentLang === 'en' ? 'Automatically create FileStation account on first login' : '첫 로그인 시 FileStation 계정 자동 생성'; ?>
                    </label>
                </div>
                <div style="margin-bottom:10px;">
                    <label style="font-size:12px;font-weight:600;"><?php echo $currentLang === 'en' ? 'Default Role' : '기본 역할'; ?></label>
                    <select id="sso-kerberos-default-role" class="form-control" style="font-size:12px;margin-top:4px;">
                        <option value="user"><?php echo $currentLang === 'en' ? 'User' : '일반 사용자'; ?></option>
                        <option value="sub_admin"><?php echo $currentLang === 'en' ? 'Sub Admin' : '부 관리자'; ?></option>
                    </select>
                </div>
                
                <details style="margin-top:15px;background:#f0f4f8;padding:10px;border-radius:6px;">
                    <summary style="font-size:12px;font-weight:600;cursor:pointer;">💡 <?php echo $currentLang === 'en' ? 'Apache Server Setup Guide' : 'Apache 서버 설정 가이드'; ?></summary>
                    <div style="font-size:11px;margin-top:6px;line-height:1.6;">
                        <p><b><?php echo $currentLang === 'en' ? '1. Install mod_auth_kerb (Apache 2.4 on Windows)' : '1. mod_auth_kerb 설치 (Apache 2.4 Windows)'; ?></b></p>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:8px;border-radius:4px;font-size:10px;overflow-x:auto;"># httpd.conf
LoadModule auth_kerb_module modules/mod_auth_kerb.so</pre>
                        <p style="margin-top:6px;"><b><?php echo $currentLang === 'en' ? '2. Apache VirtualHost or Directory configuration' : '2. Apache VirtualHost 또는 Directory 설정'; ?></b></p>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:8px;border-radius:4px;font-size:10px;overflow-x:auto;">&lt;Location /api.php&gt;
    AuthType Kerberos
    AuthName "FileStation SSO"
    KrbAuthRealms YOUR.DOMAIN.COM
    KrbServiceName HTTP/filestation.your.domain.com
    Krb5Keytab C:/Apache24/conf/http.keytab
    KrbMethodNegotiate On
    KrbMethodK5Passwd Off
    # Require valid-user  ← 주석 처리: PHP에서 선택적 처리
&lt;/Location&gt;</pre>
                        <p style="margin-top:6px;"><b><?php echo $currentLang === 'en' ? '3. Create SPN and keytab on AD' : '3. AD에서 SPN 및 keytab 생성'; ?></b></p>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:8px;border-radius:4px;font-size:10px;overflow-x:auto;"># AD 서버에서 실행 (PowerShell)
setspn -S HTTP/filestation.your.domain.com DOMAIN\ServiceAccount
ktpass -princ HTTP/filestation.your.domain.com@YOUR.DOMAIN.COM ^
  -mapuser DOMAIN\ServiceAccount -pass * ^
  -out C:\http.keytab -crypto AES256-SHA1 -ptype KRB5_NT_PRINCIPAL</pre>
                        <p style="margin-top:6px;"><b><?php echo $currentLang === 'en' ? '4. Browser Settings (IE/Edge/Chrome)' : '4. 브라우저 설정 (IE/Edge/Chrome)'; ?></b></p>
                        <p><?php echo $currentLang === 'en' ? 'Add site to Local Intranet zone or configure GPO for automatic Kerberos authentication.' : '사이트를 로컬 인트라넷 영역에 추가하거나 GPO로 자동 Kerberos 인증을 설정하세요.'; ?></p>
<pre style="background:#2d2d2d;color:#f8f8f2;padding:8px;border-radius:4px;font-size:10px;overflow-x:auto;"># Chrome/Edge GPO 정책
AuthServerAllowlist: *.your.domain.com
AuthNegotiateDelegateAllowlist: *.your.domain.com</pre>
                    </div>
                </details>
            </div>
            
            <!-- LDAP/AD -->
            <div id="sso-tab-ldap" class="sso-tab-content" style="display:none;">
                <label style="font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                    <input type="checkbox" id="sso-ldap-enabled"> <?php echo $currentLang === 'en' ? 'Enable LDAP/AD Authentication' : 'LDAP/AD 인증 활성화'; ?>
                </label>
                <p class="sso-desc"><?php echo $currentLang === 'en' 
                    ? 'Users can log in with their Active Directory or LDAP account. Enter the AD/LDAP server information below.' 
                    : '사용자가 Active Directory 또는 LDAP 계정으로 로그인할 수 있습니다. 아래에 AD/LDAP 서버 정보를 입력하세요.'; ?></p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                    <div>
                        <label class="sso-label"><?php echo $currentLang === 'en' ? 'Server Host' : '서버 주소'; ?></label>
                        <input type="text" id="sso-ldap-host" class="form-control" placeholder="ldap.example.com">
                    </div>
                    <div>
                        <label class="sso-label"><?php echo $currentLang === 'en' ? 'Port' : '포트'; ?></label>
                        <input type="number" id="sso-ldap-port" class="form-control" value="389">
                    </div>
                    <div style="grid-column:1/-1;">
                        <label class="sso-label">Base DN</label>
                        <input type="text" id="sso-ldap-base-dn" class="form-control" placeholder="DC=example,DC=com">
                    </div>
                    <div>
                        <label class="sso-label">Bind DN</label>
                        <input type="text" id="sso-ldap-bind-dn" class="form-control" placeholder="CN=admin,DC=example,DC=com">
                    </div>
                    <div>
                        <label class="sso-label">Bind Password</label>
                        <input type="password" id="sso-ldap-bind-pw" class="form-control">
                    </div>
                    <div style="grid-column:1/-1;">
                        <label class="sso-label"><?php echo $currentLang === 'en' ? 'User Filter' : '사용자 필터'; ?></label>
                        <input type="text" id="sso-ldap-user-filter" class="form-control" placeholder="(sAMAccountName={username})" value="(sAMAccountName={username})">
                    </div>
                    <div>
                        <label class="sso-label"><?php echo $currentLang === 'en' ? 'Username Attr' : '사용자명 속성'; ?></label>
                        <input type="text" id="sso-ldap-username-attr" class="form-control" value="sAMAccountName">
                    </div>
                    <div>
                        <label class="sso-label"><?php echo $currentLang === 'en' ? 'Email Attr' : '이메일 속성'; ?></label>
                        <input type="text" id="sso-ldap-email-attr" class="form-control" value="mail">
                    </div>
                    <div>
                        <label class="sso-label"><?php echo $currentLang === 'en' ? 'Display Name Attr' : '표시이름 속성'; ?></label>
                        <input type="text" id="sso-ldap-displayname-attr" class="form-control" value="displayName">
                    </div>
                    <div>
                        <label class="sso-label"><?php echo $currentLang === 'en' ? 'Button Label' : '버튼 텍스트'; ?></label>
                        <input type="text" id="sso-ldap-label" class="form-control" value="LDAP/AD 로그인">
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;margin-top:6px;">
                    <label style="font-size:12px;"><input type="checkbox" id="sso-ldap-tls"> LDAPS (TLS)</label>
                    <label style="font-size:12px;"><input type="checkbox" id="sso-ldap-starttls"> STARTTLS</label>
                    <label style="font-size:12px;"><input type="checkbox" id="sso-ldap-auto-create" checked> <?php echo $currentLang === 'en' ? 'Auto create user' : '자동 계정 생성'; ?></label>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn btn-sm btn-secondary" onclick="App.testLDAPConnection()">🔍 <?php echo $currentLang === 'en' ? 'Test Connection' : '연결 테스트'; ?></button>
                    <span id="sso-ldap-test-result" style="font-size:12px;margin-left:8px;"></span>
                </div>
            </div>
            
            <!-- OIDC 설정 -->
            <div id="sso-tab-oidc" class="sso-tab-content" style="display:none;">
                <label style="font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                    <input type="checkbox" id="sso-oidc-enabled"> <?php echo $currentLang === 'en' ? 'Enable OpenID Connect' : 'OpenID Connect 활성화'; ?>
                </label>
                <p class="sso-desc"><?php echo $currentLang === 'en' 
                    ? 'Connect with Azure AD, Google, Keycloak, etc. Enter the Well-Known URL to auto-fill endpoints, or enter them manually.' 
                    : 'Azure AD, Google, Keycloak 등과 연동합니다. Well-Known URL을 입력하면 엔드포인트가 자동 입력되며, 수동 입력도 가능합니다.'; ?></p>
                <div style="margin-bottom:8px;padding:8px;background:#e8f4fd;border-radius:6px;">
                    <label class="sso-label">Well-Known URL (<?php echo $currentLang === 'en' ? 'Auto Discover' : '자동 설정'; ?>)</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="sso-oidc-wellknown" class="form-control" placeholder="https://login.microsoftonline.com/{tenant}/.well-known/openid-configuration" style="flex:1;">
                        <button class="btn btn-sm btn-primary" onclick="App.oidcDiscover()">🔍 <?php echo $currentLang === 'en' ? 'Discover' : '가져오기'; ?></button>
                    </div>
                    <small style="color:#888;font-size:11px;"><?php echo $currentLang === 'en' ? 'Enter Well-Known URL to auto-fill endpoints below' : 'Well-Known URL을 입력하면 아래 엔드포인트가 자동 입력됩니다'; ?></small>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                    <div>
                        <label class="sso-label"><?php echo $currentLang === 'en' ? 'Provider Name' : '제공자 이름'; ?></label>
                        <input type="text" id="sso-oidc-provider" class="form-control" placeholder="Azure AD / Google / Keycloak">
                    </div>
                    <div>
                        <label class="sso-label">Client ID</label>
                        <input type="text" id="sso-oidc-client-id" class="form-control">
                    </div>
                    <div>
                        <label class="sso-label">Client Secret</label>
                        <input type="password" id="sso-oidc-client-secret" class="form-control">
                    </div>
                    <div>
                        <label class="sso-label">Redirect URI</label>
                        <input type="text" id="sso-oidc-redirect-uri" class="form-control" placeholder="https://yoursite.com/api.php?action=sso_oidc_callback">
                    </div>
                    <div style="grid-column:1/-1;">
                        <label class="sso-label">Authorization Endpoint</label>
                        <input type="text" id="sso-oidc-auth-endpoint" class="form-control">
                    </div>
                    <div>
                        <label class="sso-label">Token Endpoint</label>
                        <input type="text" id="sso-oidc-token-endpoint" class="form-control">
                    </div>
                    <div>
                        <label class="sso-label">UserInfo Endpoint</label>
                        <input type="text" id="sso-oidc-userinfo-endpoint" class="form-control">
                    </div>
                    <div>
                        <label class="sso-label">Scope</label>
                        <input type="text" id="sso-oidc-scope" class="form-control" value="openid profile email">
                    </div>
                    <div>
                        <label class="sso-label"><?php echo $currentLang === 'en' ? 'Button Label' : '버튼 텍스트'; ?></label>
                        <input type="text" id="sso-oidc-label" class="form-control" value="SSO 로그인">
                    </div>
                </div>
                <div style="margin-top:6px;">
                    <label style="font-size:12px;"><input type="checkbox" id="sso-oidc-auto-create" checked> <?php echo $currentLang === 'en' ? 'Auto create user' : '자동 계정 생성'; ?></label>
                    <label style="font-size:12px;margin-left:12px;"><input type="checkbox" id="sso-oidc-auto-login"> <?php echo $currentLang === 'en' ? 'Auto-login (Seamless SSO: skip login screen if already authenticated at IdP)' : '자동 로그인 (IdP에 이미 인증된 경우 로그인 화면 없이 자동 진입)'; ?></label>
                </div>
            </div>
            
            <!-- SAML 2.0 설정 -->
            <div id="sso-tab-saml" class="sso-tab-content" style="display:none;">
                <label style="font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                    <input type="checkbox" id="sso-saml-enabled"> <?php echo $currentLang === 'en' ? 'Enable SAML 2.0' : 'SAML 2.0 활성화'; ?>
                </label>
                <p class="sso-desc"><?php echo $currentLang === 'en' 
                    ? 'Connect with SAML 2.0 Identity Providers (Azure AD, Okta, ADFS, etc). Enter the IdP information and register the SP Metadata URL below at the IdP.' 
                    : 'SAML 2.0 IdP(Azure AD, Okta, ADFS 등)와 연동합니다. IdP 정보를 입력하고, 하단의 SP Metadata URL을 IdP에 등록하세요.'; ?></p>
                <h4>IdP (Identity Provider) <?php echo $currentLang === 'en' ? 'Settings' : '설정'; ?></h4>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                    <div style="grid-column:1/-1;">
                        <label class="sso-label">IdP SSO URL</label>
                        <input type="text" id="sso-saml-idp-sso-url" class="form-control" placeholder="https://idp.example.com/saml/sso">
                    </div>
                    <div style="grid-column:1/-1;">
                        <label class="sso-label">IdP <?php echo $currentLang === 'en' ? 'Certificate' : '인증서'; ?> (X.509 PEM)</label>
                        <textarea id="sso-saml-idp-cert" class="form-control" rows="3" placeholder="-----BEGIN CERTIFICATE-----
...
-----END CERTIFICATE-----" style="font-family:monospace;font-size:11px;"></textarea>
                    </div>
                </div>
                <h4>SP (Service Provider) <?php echo $currentLang === 'en' ? 'Settings' : '설정'; ?></h4>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                    <div>
                        <label class="sso-label">SP Entity ID</label>
                        <input type="text" id="sso-saml-sp-entity-id" class="form-control" placeholder="https://yoursite.com/filestation">
                    </div>
                    <div>
                        <label class="sso-label">SP ACS URL</label>
                        <input type="text" id="sso-saml-sp-acs-url" class="form-control" placeholder="https://yoursite.com/api.php?action=sso_saml_callback">
                    </div>
                    <div>
                        <label class="sso-label"><?php echo $currentLang === 'en' ? 'Button Label' : '버튼 텍스트'; ?></label>
                        <input type="text" id="sso-saml-label" class="form-control" value="SAML 로그인">
                    </div>
                </div>
                <div style="margin-top:6px;">
                    <label style="font-size:12px;"><input type="checkbox" id="sso-saml-auto-create" checked> <?php echo $currentLang === 'en' ? 'Auto create user' : '자동 계정 생성'; ?></label>
                </div>
                <div style="margin-top:10px;padding:8px;background:#f5f5f5;border-radius:6px;">
                    <small style="font-size:11px;color:#666;">
                        📄 SP Metadata URL: <code id="sso-saml-metadata-url">api.php?action=sso_saml_metadata</code>
                        <button class="btn btn-xs" onclick="navigator.clipboard.writeText(location.origin+'/api.php?action=sso_saml_metadata');App.toast('복사됨','success');" style="margin-left:4px;">📋 <?php echo $currentLang === 'en' ? 'Copy' : '복사'; ?></button>
                    </small>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="App.saveSSOSettings()">💾 <?php echo $currentLang === 'en' ? 'Save' : '저장'; ?></button>
            <button class="btn btn-secondary" onclick="App.hideModal('modal-sso')"><?php echo $currentLang === 'en' ? 'Close' : '닫기'; ?></button>
        </div>
    </div>
    </div><!-- /modal-overlay -->
        
        <!-- 컨텍스트 메뉴 -->
        <div id="context-menu" class="context-menu" style="display:none;">
            <ul>
                <!-- 파일/폴더 선택 시 -->
                <li data-action="open">📂 <?php echo $currentLang === 'en' ? 'Open' : '열기'; ?></li>
                <li data-action="preview">👁️ <?php echo $currentLang === 'en' ? 'Preview' : '미리보기'; ?></li>
                <li data-action="onlyoffice">📝 <?php echo $currentLang === 'en' ? 'Edit with OnlyOffice' : 'OnlyOffice로 편집'; ?></li>
                <li data-action="rhwp-edit">📝 <?php echo $currentLang === 'en' ? 'Edit with rHWP' : 'rHWP로 편집'; ?></li>
                <li data-action="download">⬇️ <?php _e('download'); ?></li>
                <li data-action="save-as">💾 <?php echo $currentLang === 'en' ? 'Save As' : '다른 이름으로 저장'; ?></li>
                <li data-action="share">🔗 <?php _e('share'); ?></li>
                <li data-action="internal_share">👤 <?php echo $currentLang === 'en' ? 'Share with User' : '사용자에게 공유'; ?></li>
                <li class="divider"></li>
                <li data-action="favorite-add">⭐ <?php echo $currentLang === 'en' ? 'Add to Favorites' : '즐겨찾기 추가'; ?></li>
                <li data-action="favorite-remove">☆ <?php echo $currentLang === 'en' ? 'Remove from Favorites' : '즐겨찾기 제거'; ?></li>
                <li data-action="file-lock">🔒 <?php echo $currentLang === 'en' ? 'Lock' : '잠금'; ?></li>
                <li data-action="file-unlock">🔓 <?php echo $currentLang === 'en' ? 'Unlock' : '잠금 해제'; ?></li>
                <li class="divider"></li>
                <li data-action="copy">📋 <?php _e('copy'); ?></li>
                <li data-action="move">✂️ <?php _e('cut'); ?></li>
                <li data-action="paste">📥 <?php echo $currentLang === 'en' ? 'Paste Here' : '여기에 붙여넣기'; ?></li>
                <li class="divider"></li>
                <li data-action="extract">📦 <?php echo $currentLang === 'en' ? 'Extract (ZIP)' : '압축 해제 (ZIP)'; ?></li>
                <li data-action="compress">🗜️ <?php echo $currentLang === 'en' ? 'Compress (ZIP)' : '압축 (ZIP)'; ?></li>
                <li data-action="convert-h264">🎬 <?php echo $currentLang === 'en' ? 'Convert to H264/MP4' : 'H264/MP4로 변환'; ?></li>
                <li data-action="convert-to-vault">🔒 <?php echo $currentLang === 'en' ? 'Encrypt This Folder' : '이 폴더 암호화'; ?></li>
                <li class="divider"></li>
                <li data-action="rename">✏️ <?php _e('rename'); ?></li>
                <li data-action="versions">📜 <?php echo $currentLang === 'en' ? 'Previous Versions' : '이전 버전'; ?></li>
                <li data-action="info">ℹ️ <?php _e('info'); ?></li>
                <li class="divider"></li>
                <li data-action="delete" class="danger">🗑️ <?php _e('delete'); ?></li>
                <!-- 빈 공간 우클릭 시 -->
                <li data-action="new-folder">📁 <?php _e('new_folder'); ?></li>
                <li data-action="new-vault-folder">🔒 <?php echo $currentLang === 'en' ? 'New Encrypted Folder' : '새 암호화 폴더'; ?></li>
                <li class="divider new-file-divider"></li>
                <li data-action="new-file-txt"><img src="assets/file-icons/text.svg?v=<?= APP_VERSION ?>" alt="txt" style="width:16px;height:16px;display:inline-block;vertical-align:-4px;margin-right:5px;user-select:none;-webkit-user-drag:none;"><?php echo $currentLang === 'en' ? 'New Text File' : '새 텍스트 파일'; ?></li>
                <li data-action="new-file-docx"><img src="assets/file-icons/word.svg?v=<?= APP_VERSION ?>" alt="docx" style="width:16px;height:16px;display:inline-block;vertical-align:-4px;margin-right:5px;user-select:none;-webkit-user-drag:none;"><?php echo $currentLang === 'en' ? 'New Word Document' : '새 Word 문서'; ?></li>
                <li data-action="new-file-xlsx"><img src="assets/file-icons/excel.svg?v=<?= APP_VERSION ?>" alt="xlsx" style="width:16px;height:16px;display:inline-block;vertical-align:-4px;margin-right:5px;user-select:none;-webkit-user-drag:none;"><?php echo $currentLang === 'en' ? 'New Excel Spreadsheet' : '새 Excel 문서'; ?></li>
                <li data-action="new-file-pptx"><img src="assets/file-icons/powerpoint.svg?v=<?= APP_VERSION ?>" alt="pptx" style="width:16px;height:16px;display:inline-block;vertical-align:-4px;margin-right:5px;user-select:none;-webkit-user-drag:none;"><?php echo $currentLang === 'en' ? 'New PowerPoint' : '새 PowerPoint'; ?></li>
                <li data-action="new-file-hwp"><img src="assets/file-icons/hwp.svg?v=<?= APP_VERSION ?>" alt="hwp" style="width:16px;height:16px;display:inline-block;vertical-align:-4px;margin-right:5px;user-select:none;-webkit-user-drag:none;"><?php echo $currentLang === 'en' ? 'New HWP Document' : '새 한글 문서'; ?></li>
                <li class="divider"></li>
                <li data-action="upload-file">📄 <?php echo $currentLang === 'en' ? 'Upload Files' : '파일 업로드'; ?></li>
                <li data-action="upload-folder">📂 <?php echo $currentLang === 'en' ? 'Upload Folder' : '폴더 업로드'; ?></li>
                <li data-action="refresh">🔄 <?php _e('refresh'); ?></li>
            </ul>
        </div>
        
        <!-- 알림 토스트 -->
        <div id="toast" class="toast"></div>
        
        <!-- 숨김 파일 업로드 -->
        <input type="file" id="file-input" multiple style="display:none;">
        <input type="file" id="folder-input" webkitdirectory directory multiple style="display:none;">
    </div>
    
    <!-- CSRF 토큰 -->
    <script>
        window.CSRF_TOKEN = '<?= htmlspecialchars($csrfToken) ?>';
        window.LANG = '<?= htmlspecialchars($currentLang, ENT_QUOTES) ?>';
        window.LANG_STRINGS = <?= Lang::getInstance()->toJson() ?>;
        // 사용자 정의 아이콘 매핑 (관리자가 설정) — 클라이언트 getFileIcon()이 참조
        <?php
            $_userIconMapFile = __DIR__ . '/data/user_icon_map.json';
            $_userIconMap = file_exists($_userIconMapFile)
                ? (@json_decode(@file_get_contents($_userIconMapFile), true) ?: [])
                : [];
            // 커스텀 폴더 아이콘 목록
            $_customIconDir = __DIR__ . '/assets/file-icons/custom';
            $_customIcons = [];
            if (is_dir($_customIconDir)) {
                foreach (scandir($_customIconDir) as $_f) {
                    if (substr($_f, -4) === '.svg') $_customIcons[] = substr($_f, 0, -4);
                }
            }
            // 아이콘 파일 버스터 맵 (캐시 무효화용)
            //   JS가 window.ICON_BUSTER_MAP[name]으로 해시 조회 → ?v=해시 붙임
            //   파일 내용(mtime) 바뀌면 해시도 바뀜 → 브라우저 자동 갱신
            require_once __DIR__ . '/api/IconUrl.php';
            $_iconBusterMap = IconUrl::allBusterMap();
            // 라벨 위치 (CSS 오버레이 전용)
            require_once __DIR__ . '/api/IconManager.php';
            $_labelPosition = (new IconManager())->getLabelPosition();
            // 이모지 맵 (JS의 getFileIcon 폴백에서 사용 - 음악 🎵, 실행 ⚙️ 등)
            //   주의: 이모지가 매핑되는 것보다 iconMap/code_map이 우선
            $_emojiIcons = defined('EMOJI_ICONS') ? EMOJI_ICONS : [];
        ?>
        window.USER_ICON_MAP = <?= json_encode($_userIconMap, JSON_UNESCAPED_UNICODE) ?>;
        window.CUSTOM_ICONS = <?= json_encode($_customIcons, JSON_UNESCAPED_UNICODE) ?>;
        window.ICON_BUSTER_MAP = <?= json_encode($_iconBusterMap, JSON_UNESCAPED_UNICODE) ?>;
        window.LABEL_POSITION = <?= json_encode($_labelPosition) ?>;
        window.EMOJI_ICONS = <?= json_encode($_emojiIcons, JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="assets/lib/viewerjs/viewer.min.js"></script>
    <script src="assets/vendor/hls.min.js"></script>
    <script src="assets/js/e2e-crypto.js?v=<?php echo substr(md5_file(__DIR__ . '/assets/js/e2e-crypto.js'), 0, 10); ?>"></script>
    <script src="assets/js/app.js?v=<?php echo substr(md5_file(__DIR__ . '/assets/js/app.js'), 0, 10); ?>"></script>
<!-- 알림 드롭다운 (body 직접 자식 - overflow hidden 회피) -->
<div class="notify-dropdown" id="notify-dropdown">
    <div class="notify-dropdown-header"><?php _e('notifications'); ?></div>
    <div class="notify-dropdown-body" id="notify-dropdown-body"></div>
</div>

<style>
.sso-label { display:block; font-size:11px; font-weight:600; color:#555; margin:0 0 2px; }
.sso-tab { background:#f0f0f0; border:none; cursor:pointer; padding:5px 12px; font-size:12px; border-radius:4px; }
.sso-tab.active { background:#667eea; color:#fff; }
.sso-tab-content { padding:4px 0; }
.sso-tab-content .form-control { font-size:12px; padding:5px 8px; height:auto; width:100% !important; box-sizing:border-box; border:1px solid #ccc; border-radius:4px; }
.sso-tab-content input[type="text"],
.sso-tab-content input[type="number"],
.sso-tab-content input[type="password"],
.sso-tab-content select,
.sso-tab-content textarea { width:100% !important; box-sizing:border-box; }
.sso-tab-content textarea.form-control { font-size:11px; padding:5px 8px; width:100% !important; box-sizing:border-box; }
.sso-tab-content h4 { font-size:12px; font-weight:700; color:#333; margin:14px 0 6px; padding-bottom:3px; border-bottom:1px solid #eee; }
.sso-tab-content small { font-size:11px; color:#888; }
.sso-desc { font-size:11px; color:#888; margin:2px 0 8px; line-height:1.4; }
.sso-section { margin-bottom:10px; padding:10px; background:#f8f9fa; border-radius:6px; border:1px solid #eee; }
.sso-tab-content div[style*="grid"] { gap:10px 8px; }
.sso-tab-content div[style*="grid"] > div { margin-bottom:2px; }
</style>
<!-- 맨 위로 버튼 -->
<button id="btn-scroll-top" title="맨 위로" aria-label="맨 위로">▲</button>

    <!-- 폴더 권한 모달 (modal-overlay 외부 - position:fixed 정상 작동) -->
    <div id="fperm-modal-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:100002;"></div>
    <div id="modal-fperm-browse" class="modal" style="display:none;">
        <div class="modal-header">
            <h2 id="fperm-browse-title">📂 /</h2>
            <button class="fperm-close-btn" style="background:none;border:none;font-size:20px;cursor:pointer;padding:4px 8px;color:#666;" onclick="document.getElementById('modal-fperm-browse').style.display='none';document.getElementById('fperm-modal-overlay').style.display='none'">&times;</button>
        </div>
        <div class="modal-body" style="padding:8px 12px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <label style="font-size:12px;cursor:pointer;"><input type="checkbox" id="fperm-check-all"> <?php echo $currentLang === 'en' ? 'Select All' : '전체선택'; ?></label>
            </div>
            <div id="fperm-browse-content" style="overflow-y:auto;flex:1;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="document.getElementById('modal-fperm-browse').style.display='none';document.getElementById('fperm-modal-overlay').style.display='none'"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
            <button class="btn btn-primary" id="fperm-browse-apply"><?php echo $currentLang === 'en' ? 'Apply Selected' : '선택 적용'; ?></button>
        </div>
    </div>
    <div id="modal-fperm-bulk-edit" class="modal modal-sm" style="display:none;">
        <div class="modal-header">
            <h2><?php echo $currentLang === 'en' ? '📁 Edit Folder Permissions' : '📁 폴더 권한 수정'; ?></h2>
            <button class="fperm-close-btn" style="background:none;border:none;font-size:20px;cursor:pointer;padding:4px 8px;color:#666;" onclick="document.getElementById('modal-fperm-bulk-edit').style.display='none';document.getElementById('fperm-modal-overlay').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <p id="fperm-bulk-edit-info" style="font-size:12px;color:#666;margin:0 0 12px;"></p>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <label style="font-size:13px;"><input type="checkbox" id="fperm-bulk-visible" checked> <?php echo $currentLang === 'en' ? 'Visible' : '표시'; ?></label>
                <label style="font-size:13px;"><input type="checkbox" id="fperm-bulk-read" checked> <?php echo $currentLang === 'en' ? 'Read' : '읽기'; ?></label>
                <label style="font-size:13px;"><input type="checkbox" id="fperm-bulk-write"> <?php echo $currentLang === 'en' ? 'Write' : '쓰기'; ?></label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="document.getElementById('modal-fperm-bulk-edit').style.display='none';document.getElementById('fperm-modal-overlay').style.display='none'"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
            <button class="btn btn-primary" id="fperm-bulk-edit-apply"><?php echo $currentLang === 'en' ? 'Apply' : '적용'; ?></button>
        </div>
    </div>
    <div id="modal-fperm-add-user" class="modal modal-sm" style="display:none;">
        <div class="modal-header">
            <h2><?php echo $currentLang === 'en' ? '➕ Add User' : '➕ 사용자 추가'; ?></h2>
            <button class="fperm-close-btn" style="background:none;border:none;font-size:20px;cursor:pointer;padding:4px 8px;color:#666;" onclick="document.getElementById('modal-fperm-add-user').style.display='none';document.getElementById('fperm-modal-overlay').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <p id="fperm-add-user-folder" style="font-size:12px;color:#666;margin:0 0 10px;"></p>
            <select id="fperm-add-user-select" style="width:100%;font-size:13px;padding:6px;"></select>
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="document.getElementById('modal-fperm-add-user').style.display='none';document.getElementById('fperm-modal-overlay').style.display='none'"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
            <button class="btn btn-primary" id="fperm-add-user-apply"><?php echo $currentLang === 'en' ? 'Add' : '추가'; ?></button>
        </div>
    </div>
    
    <!-- 아이콘 관리 서브모달 (modal-overlay 외부 - 부모 모달 위에 겹쳐 표시) -->
    <div id="icon-mgr-modal-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:100002;"></div>
    
    <div id="modal-icon-change" class="modal" style="display:none;">
        <div class="modal-header">
            <h3>🎨 <?php echo $currentLang === 'en' ? 'Change Icon' : '아이콘 변경'; ?>: <span id="icon-change-ext" style="color:#4fc3f7; font-family:monospace;"></span></h3>
            <button class="icon-mgr-close-btn" style="background:none;border:none;font-size:20px;cursor:pointer;padding:4px 8px;color:#666;" onclick="App._iconMgrCloseSubModal('modal-icon-change')">&times;</button>
        </div>
        <div class="modal-body">
            <!-- 현재 아이콘 미리보기 -->
            <div style="display:flex; align-items:center; gap:20px; margin-bottom:20px; padding:15px; background:#f8f9fa; border-radius:8px;">
                <div>
                    <div style="font-size:11px; color:#666; margin-bottom:5px;">현재 아이콘</div>
                    <div id="icon-change-current" style="width:64px; height:64px; background:#fff; border:1px solid #ddd; border-radius:6px; display:flex; align-items:center; justify-content:center;">
                        <span style="color:#aaa; font-size:24px;">?</span>
                    </div>
                </div>
                <div style="flex:1;">
                    <div style="font-size:11px; color:#666; margin-bottom:5px;">확장자 정보</div>
                    <div id="icon-change-info" style="font-size:13px; color:#333;"></div>
                </div>
            </div>
            
            <!-- 탭 -->
            <div style="display:flex; gap:6px; border-bottom:1px solid #ddd; margin-bottom:15px;">
                <button type="button" class="icon-change-tab active" data-ctab="upload" style="padding:8px 16px; border:none; background:none; cursor:pointer; border-bottom:3px solid #4fc3f7; color:#1976d2; font-weight:bold;">
                    📤 <?php echo $currentLang === 'en' ? 'Upload New' : '새로 업로드'; ?>
                </button>
                <button type="button" class="icon-change-tab" data-ctab="existing" style="padding:8px 16px; border:none; background:none; cursor:pointer; border-bottom:3px solid transparent;">
                    🖼️ <?php echo $currentLang === 'en' ? 'From Gallery' : '갤러리에서'; ?>
                </button>
            </div>
            
            <!-- Tab: 업로드 -->
            <div id="icon-change-panel-upload" class="icon-change-panel">
                <div style="padding:15px; background:#fff; border:1px dashed #ccc; border-radius:8px; text-align:center;">
                    <input type="file" id="icon-change-file" accept=".svg,.png,.jpg,.jpeg,.gif,.webp,.ico,.bmp,image/*" style="display:none;">
                    <label for="icon-change-file" style="cursor:pointer; display:block;">
                        <div id="icon-change-preview" style="width:96px; height:96px; background:#fafafa; border:1px solid #e0e0e0; border-radius:8px; margin:0 auto 12px; display:flex; align-items:center; justify-content:center; color:#aaa; font-size:13px;">
                            클릭하여 선택
                        </div>
                        <div style="color:#1976d2; font-weight:bold;">📁 파일 선택</div>
                        <div style="color:#888; font-size:11px; margin-top:6px;">
                            SVG / PNG / JPG / GIF / WEBP / ICO / BMP (비-SVG는 자동 변환)
                        </div>
                    </label>
                </div>
                
                <!-- 안내: 자동 라벨 교체 설명 (업로드 탭 전용 - 라벨 오버레이 OFF일 때 업로드 이미지에 라벨 있으면 자동 교체) -->
                <div style="margin-top:10px; padding:10px; background:#e3f2fd; border:1px solid #bbdefb; border-radius:6px; font-size:12px; color:#1565c0;">
                    💡 <strong><?php echo $currentLang === 'en' ? 'Auto Label Replacement (when Overlay is OFF)' : '자동 라벨 교체 (오버레이 체크 안 했을 때)'; ?></strong>
                    <div style="color:#777; margin-top:3px;"><?php echo $currentLang === 'en' ? 'If the uploaded image has a label (colored bar + text), it is replaced with a yellow label showing the extension. No label detected = original kept.' : '업로드한 이미지에 라벨(색 띠 + 글자)이 있으면 자동으로 노란 라벨 + 확장자명으로 교체. 라벨이 없으면 원본 그대로 유지됩니다.'; ?></div>
                </div>
            </div>
            
            <!-- Tab: 갤러리 -->
            <div id="icon-change-panel-existing" class="icon-change-panel" style="display:none;">
                <div id="icon-change-gallery" style="max-height:300px; overflow-y:auto; display:grid; grid-template-columns:repeat(auto-fill,minmax(80px,1fr)); gap:8px; padding:10px; background:#f8f9fa; border-radius:6px;">
                    <!-- JS로 채움 -->
                </div>
                
                <!-- 갤러리 전용 안내: 옵션 조합별 동작 -->
                <div style="margin-top:10px; padding:10px; background:#e3f2fd; border:1px solid #bbdefb; border-radius:6px; font-size:12px; color:#1565c0;">
                    💡 <strong><?php echo $currentLang === 'en' ? 'Gallery Icon + Options' : '갤러리 아이콘 + 옵션'; ?></strong>
                    <div style="color:#777; margin-top:3px;"><?php echo $currentLang === 'en' ? 'Options OFF: Just map the icon (no copy). Background Remove ON or raster icon: Create new custom icon by reprocessing (re-labels if needed).' : '옵션 모두 OFF: 원본 아이콘 그대로 매핑 (파일 생성 X). 배경 제거 ON 또는 라스터 아이콘: 라벨 재교체해서 새 custom 아이콘 생성.'; ?></div>
                </div>
            </div>
            
            <!-- 공통 옵션 (업로드/갤러리 탭 모두에서 적용) -->
            <!-- 배경 제거 + 크기 맞춤 -->
            <div style="margin-top:15px; padding:10px; background:#e8f5e9; border:1px solid #c8e6c9; border-radius:6px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; color:#2e7d32;">
                    <input type="checkbox" id="icon-change-remove-bg" checked style="margin:0;">
                    <span>
                        🎨 <strong><?php echo $currentLang === 'en' ? 'Auto-remove White Background + Fit' : '흰색 배경 제거 + 아이콘 크기 맞춤'; ?></strong>
                        <span style="color:#999; font-size:11px; margin-left:6px;"><?php echo $currentLang === 'en' ? '(PNG/JPG - transparent + auto-scale to canvas, default ON)' : '(PNG/JPG 등 업로드 시, 흰색 배경 투명 처리 + 아이콘 영역에 맞게 자동 확대/중앙 정렬. 기본 켜짐)'; ?></span>
                    </span>
                </label>
            </div>
            
            <!-- 라벨 처리 (3택 라디오) -->
            <div id="icon-change-label-mode-box" style="margin-top:10px; padding:10px; background:#fff8e1; border:1px solid #ffe082; border-radius:6px;">
                <div style="font-size:13px; color:#795548; font-weight:bold; margin-bottom:8px;">
                    🏷️ <?php echo $currentLang === 'en' ? 'Label Handling' : '라벨 처리'; ?>
                </div>
                
                <!-- ○ 원본 그대로 (신규) - 직접 만들어 놓은 아이콘 이미지를 그대로 쓸 때 -->
                <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; font-size:13px; color:#555; margin-bottom:6px;">
                    <input type="radio" name="icon-change-label-mode-radio" value="keep" style="margin:3px 0 0 0;">
                    <span>
                        <strong><?php echo $currentLang === 'en' ? 'Keep Original (don\'t touch label)' : '원본 그대로 (라벨 건드리지 않음)'; ?></strong>
                        <span style="display:block; color:#999; font-size:11px; margin-top:2px;"><?php echo $currentLang === 'en' ? 'Use when you already prepared the icon image as-is' : '직접 만들어 놓은 아이콘 이미지를 그대로 쓸 때'; ?></span>
                    </span>
                </label>
                
                <!-- ● 자동 감지 교체 (기본값) -->
                <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; font-size:13px; color:#555; margin-bottom:6px;">
                    <input type="radio" name="icon-change-label-mode-radio" value="replace" checked style="margin:3px 0 0 0;">
                    <span>
                        <strong><?php echo $currentLang === 'en' ? 'Auto Detect & Replace with Extension' : '자동 감지해서 확장자로 교체'; ?></strong>
                        <span style="display:block; color:#999; font-size:11px; margin-top:2px;"><?php echo $currentLang === 'en' ? 'Detect existing label (colored bar + text) and replace with yellow label showing the extension' : '기존 라벨(색 띠 + 글자)을 감지해서 노란 라벨 + 확장자명으로 교체. 라벨이 없으면 원본 유지'; ?></span>
                    </span>
                </label>
                
                <!-- ○ CSS 오버레이 (동적) -->
                <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; font-size:13px; color:#555;">
                    <input type="radio" name="icon-change-label-mode-radio" value="overlay" style="margin:3px 0 0 0;">
                    <span>
                        <strong><?php echo $currentLang === 'en' ? 'CSS Overlay (dynamic)' : 'CSS 오버레이 (동적)'; ?></strong>
                        <span style="display:block; color:#999; font-size:11px; margin-top:2px;"><?php echo $currentLang === 'en' ? 'Show ext label on top of icon at render time. File stays clean. Use for unlabeled icons like PDF/DOC' : '파일에는 라벨을 박지 않고 렌더 시점에 확장자명 라벨을 덧씌움. 라벨 없는 빈 아이콘(PDF/DOC 등)에 사용'; ?></span>
                    </span>
                </label>
            </div>
            
            <!-- 호환성 유지용 숨겨진 체크박스 (기존 JS가 icon-change-label-mode를 참조함) -->
            <input type="checkbox" id="icon-change-label-mode" style="display:none;">
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="App._iconMgrCloseSubModal('modal-icon-change')"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
            <button type="button" class="btn btn-primary" id="btn-icon-change-apply" disabled>✅ <?php echo $currentLang === 'en' ? 'Apply' : '적용'; ?></button>
        </div>
    </div>
    
    <div id="modal-icon-add-ext" class="modal" style="display:none;">
        <div class="modal-header">
            <h3>➕ <?php echo $currentLang === 'en' ? 'Add Extension' : '확장자 추가'; ?></h3>
            <button class="icon-mgr-close-btn" style="background:none;border:none;font-size:20px;cursor:pointer;padding:4px 8px;color:#666;" onclick="App._iconMgrCloseSubModal('modal-icon-add-ext')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="setting-item">
                <label><?php echo $currentLang === 'en' ? 'Extension (without dot)' : '확장자 (점 제외)'; ?></label>
                <input type="text" id="icon-add-ext-input" class="form-control" placeholder="예: epub, dwg, mobi" maxlength="16" pattern="[a-zA-Z0-9]+" style="font-family:monospace;">
                <p class="setting-desc">영숫자 1~16자만 허용</p>
            </div>
            <div style="margin-top:12px; padding:10px; background:#e3f2fd; border-radius:6px; font-size:12px; color:#1976d2;">
                💡 <?php echo $currentLang === 'en' 
                    ? 'Register only: adds extension with default icon. Select icon: opens icon picker.' 
                    : '등록만 = 기본 아이콘으로 추가 / 아이콘 선택 = 원하는 아이콘 지정'; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="App._iconMgrCloseSubModal('modal-icon-add-ext')"><?php echo $currentLang === 'en' ? 'Cancel' : '취소'; ?></button>
            <button type="button" class="btn" id="btn-icon-add-ext-register">✓ <?php echo $currentLang === 'en' ? 'Register Only' : '등록만'; ?></button>
            <button type="button" class="btn btn-primary" id="btn-icon-add-ext-ok">🎨 <?php echo $currentLang === 'en' ? 'Select Icon' : '아이콘 선택'; ?></button>
        </div>
    </div>
</body>
</html>