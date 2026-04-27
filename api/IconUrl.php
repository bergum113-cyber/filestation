<?php
/**
 * IconUrl — 아이콘 파일 URL에 캐시 버스터를 붙여주는 헬퍼
 *   - 파일 내용 해시(short md5) 기반 버스터 → 내용이 바뀌면 자동 무효화
 *   - 요청 단위 메모리 캐시 (static $hashCache)
 *   - 파일 없으면 버스터 없이 원본 URL 반환 (404는 그대로 표현)
 *
 * 사용 예:
 *   IconUrl::get('subtitle')           → "assets/file-icons/subtitle.svg?v=a1b2c3d4"
 *   IconUrl::get('mycustom', true)     → "assets/file-icons/custom/mycustom.svg?v=..."
 *   IconUrl::map(['pdf','doc','xls'])  → ["pdf" => "...svg?v=...", ...] (JS 주입용)
 */
class IconUrl {
    
    /** @var array<string,string> 경로 → 해시 캐시 */
    private static $hashCache = [];
    
    /** @var string|null 프로젝트 루트 */
    private static $rootDir = null;
    
    private static function root(): string {
        if (self::$rootDir === null) {
            self::$rootDir = dirname(__DIR__);
        }
        return self::$rootDir;
    }
    
    /**
     * 아이콘 URL 생성 (버스터 포함)
     *
     * @param string $name 아이콘 이름 (확장자 제외, 예: "subtitle")
     * @param bool $isCustom true면 custom/ 하위 경로
     * @return string 상대 URL (버스터 포함)
     */
    public static function get(string $name, bool $isCustom = false): string {
        // 이름 정제 (경로 탐색 방어)
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if ($clean === '' || $clean === '.' || $clean === '..') {
            $clean = 'default';
        }
        
        $relPath = $isCustom 
            ? 'assets/file-icons/custom/' . $clean . '.svg'
            : 'assets/file-icons/' . $clean . '.svg';
        
        $buster = self::getBuster($relPath);
        return $buster !== null ? $relPath . '?v=' . $buster : $relPath;
    }
    
    /**
     * custom 우선으로 아이콘 URL 결정 (custom > builtin)
     *   IconManager::resolveIconPath와 동일 로직이지만 URL(버스터 포함) 반환
     */
    public static function resolve(string $name): string {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if ($clean === '') $clean = 'default';
        
        $customPath = 'assets/file-icons/custom/' . $clean . '.svg';
        $builtinPath = 'assets/file-icons/' . $clean . '.svg';
        
        $root = self::root();
        if (file_exists($root . '/' . $customPath)) {
            $buster = self::getBuster($customPath);
            return $buster !== null ? $customPath . '?v=' . $buster : $customPath;
        }
        if (file_exists($root . '/' . $builtinPath)) {
            $buster = self::getBuster($builtinPath);
            return $buster !== null ? $builtinPath . '?v=' . $buster : $builtinPath;
        }
        // 둘 다 없으면 default fallback (버스터 포함)
        $def = 'assets/file-icons/default.svg';
        $buster = self::getBuster($def);
        return $buster !== null ? $def . '?v=' . $buster : $def;
    }
    
    /**
     * 내부: 파일의 버스터 해시 (md5 앞 8자) 반환
     *   파일 없으면 null
     */
    public static function getBuster(string $relPath): ?string {
        if (isset(self::$hashCache[$relPath])) {
            return self::$hashCache[$relPath] ?: null;
        }
        
        $absPath = self::root() . '/' . $relPath;
        if (!file_exists($absPath) || !is_file($absPath)) {
            self::$hashCache[$relPath] = ''; // 미스 캐시 (재탐색 방지)
            return null;
        }
        
        // mtime이 더 빠름 (md5_file보다 100배 이상) - 내용이 바뀌면 mtime도 바뀌니 충분
        //   8자리 hex로 줄여서 URL 깔끔
        $mtime = @filemtime($absPath);
        $hash = $mtime !== false ? substr(md5((string)$mtime), 0, 8) : '';
        
        self::$hashCache[$relPath] = $hash;
        return $hash ?: null;
    }
    
    /**
     * 여러 아이콘의 URL 맵 생성 (JS에 주입용)
     *   window.ICON_BUSTER_MAP = { "pdf": "a1b2c3d4", "doc": "..." }
     *
     * @param array<string> $names 아이콘 이름 목록
     * @return array<string,string> 이름 → 버스터 해시 (파일 없으면 제외)
     */
    public static function busterMap(array $names): array {
        $map = [];
        $root = self::root();
        foreach ($names as $name) {
            $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
            if ($clean === '') continue;
            
            // custom 우선
            $customRel = 'assets/file-icons/custom/' . $clean . '.svg';
            $builtinRel = 'assets/file-icons/' . $clean . '.svg';
            
            if (file_exists($root . '/' . $customRel)) {
                $h = self::getBuster($customRel);
                if ($h !== null) $map[$clean] = $h;
            } elseif (file_exists($root . '/' . $builtinRel)) {
                $h = self::getBuster($builtinRel);
                if ($h !== null) $map[$clean] = $h;
            }
        }
        return $map;
    }
    
    /**
     * file-icons 디렉토리 전체의 버스터 맵 (builtin + custom 모두)
     *   JS에서 아이콘 이름만 알면 버스터를 찾을 수 있도록
     *   {name: "hash"} 구조, name은 확장자 없는 파일명
     */
    public static function allBusterMap(): array {
        $map = [];
        $root = self::root();
        
        // builtin
        $builtinDir = $root . '/assets/file-icons';
        if (is_dir($builtinDir)) {
            foreach (scandir($builtinDir) as $f) {
                if ($f === '.' || $f === '..') continue;
                if (substr($f, -4) !== '.svg') continue;
                $name = substr($f, 0, -4);
                $h = self::getBuster('assets/file-icons/' . $f);
                if ($h !== null) $map[$name] = $h;
            }
        }
        
        // custom (builtin과 이름 충돌 시 custom 우선 - resolve 로직과 일관)
        $customDir = $root . '/assets/file-icons/custom';
        if (is_dir($customDir)) {
            foreach (scandir($customDir) as $f) {
                if ($f === '.' || $f === '..') continue;
                if (substr($f, -4) !== '.svg') continue;
                $name = substr($f, 0, -4);
                $h = self::getBuster('assets/file-icons/custom/' . $f);
                if ($h !== null) $map[$name] = $h;
            }
        }
        
        return $map;
    }
}
