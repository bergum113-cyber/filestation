<?php
/**
 * FileStation Language System
 * 다국어 지원을 위한 번역 시스템
 */

class Lang {
    private static $instance = null;
    private $lang = 'ko';
    private $strings = [];
    private $loaded = false;
    
    private function __construct() {
        $this->detectLanguage();
        $this->load();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 언어 감지 (쿠키 > 세션 > 브라우저 > 기본값)
     */
    private function detectLanguage() {
        // 1. 쿠키에서 확인
        if (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], ['ko', 'en'])) {
            $this->lang = $_COOKIE['lang'];
            return;
        }
        
        // 2. 세션에서 확인
        if (isset($_SESSION['lang']) && in_array($_SESSION['lang'], ['ko', 'en'])) {
            $this->lang = $_SESSION['lang'];
            return;
        }
        
        // 3. 브라우저 Accept-Language 헤더
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
            if ($browserLang === 'ko') {
                $this->lang = 'ko';
            } elseif (in_array($browserLang, ['en', 'us'])) {
                $this->lang = 'en';
            }
        }
        
        // 4. 기본값: 한국어
        // $this->lang = 'ko';
    }
    
    /**
     * 언어 파일 로드
     */
    private function load() {
        if ($this->loaded) return;
        
        $langFile = __DIR__ . '/lang/' . $this->lang . '.json';
        
        if (file_exists($langFile)) {
            $json = file_get_contents($langFile);
            $this->strings = json_decode($json, true) ?: [];
        }
        
        // 폴백: 한국어 파일 로드 (키가 없을 경우 대비)
        if ($this->lang !== 'ko') {
            $fallbackFile = __DIR__ . '/lang/ko.json';
            if (file_exists($fallbackFile)) {
                $fallback = json_decode(file_get_contents($fallbackFile), true) ?: [];
                $this->strings = array_merge($fallback, $this->strings);
            }
        }
        
        $this->loaded = true;
    }
    
    /**
     * 언어 설정
     */
    public function setLang($lang) {
        if (in_array($lang, ['ko', 'en'])) {
            $this->lang = $lang;
            $this->loaded = false;
            $this->strings = [];
            $this->load();
            
            // 쿠키에 저장 (1년)
            setcookie('lang', $lang, time() + 365 * 24 * 60 * 60, '/');
            
            // 세션에도 저장
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['lang'] = $lang;
            }
        }
    }
    
    /**
     * 현재 언어 반환
     */
    public function getLang() {
        return $this->lang;
    }
    
    /**
     * 번역 문자열 가져오기
     */
    public function get($key, $default = null) {
        // _comment로 시작하는 키는 주석이므로 무시
        if (strpos($key, '_comment') === 0) {
            return $default ?? $key;
        }
        
        return $this->strings[$key] ?? $default ?? $key;
    }
    
    /**
     * 변수 치환이 있는 번역
     * 예: __('welcome_msg', ['name' => '홍길동'])
     * 언어파일: "welcome_msg": "안녕하세요, {name}님"
     */
    public function getFormatted($key, $vars = [], $default = null) {
        $str = $this->get($key, $default);
        
        foreach ($vars as $k => $v) {
            $str = str_replace('{' . $k . '}', $v, $str);
        }
        
        return $str;
    }
    
    /**
     * 모든 번역 문자열 반환 (JS용)
     */
    public function getAll() {
        // _comment 키 제외
        return array_filter($this->strings, function($key) {
            return strpos($key, '_comment') !== 0;
        }, ARRAY_FILTER_USE_KEY);
    }
    
    /**
     * JSON으로 출력 (JS 임베딩용)
     */
    public function toJson() {
        return json_encode($this->getAll(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}

/**
 * 단축 함수: 번역 문자열 가져오기
 */
function __($key, $default = null) {
    return Lang::getInstance()->get($key, $default);
}

/**
 * 단축 함수: 번역 문자열 출력
 */
function _e($key, $default = null) {
    echo __($key, $default);
}

/**
 * 단축 함수: 변수 치환 번역
 */
function __f($key, $vars = [], $default = null) {
    return Lang::getInstance()->getFormatted($key, $vars, $default);
}

/**
 * 단축 함수: 현재 언어 반환
 */
function getLang() {
    return Lang::getInstance()->getLang();
}

/**
 * 단축 함수: 언어 설정
 */
function setLang($lang) {
    Lang::getInstance()->setLang($lang);
}
