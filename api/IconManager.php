<?php
/**
 * IconManager — 사용자 정의 아이콘 관리
 * 관리자가 SVG 파일을 업로드하고 확장자를 매핑할 수 있게 함
 * 
 * 저장:
 *   - SVG 파일: assets/file-icons/custom/{name}.svg
 *   - 매핑: data/user_icon_map.json
 * 
 * 보안:
 *   - 관리자 권한 필수
 *   - SVG 안의 <script>, on* 이벤트, javascript: URL 제거
 *   - 파일 크기 제한 (100KB)
 *   - 이름/확장자 정제 (영숫자만)
 */
class IconManager {
    private $customDir;
    private $mapFile;
    private $builtinDir;
    
    const MAX_SVG_SIZE = 102400; // 100KB (SVG용)
    const MAX_IMAGE_SIZE = 524288; // 512KB (일반 이미지용)
    const NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,32}$/';
    const EXT_PATTERN  = '/^[a-zA-Z0-9]{1,16}$/';
    const ALLOWED_UPLOAD_EXTS = ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp'];
    
    public function __construct() {
        $baseDir = dirname(__DIR__);
        $this->customDir = $baseDir . '/assets/file-icons/custom';
        $this->builtinDir = $baseDir . '/assets/file-icons';
        $this->mapFile = $baseDir . '/data/user_icon_map.json';
        
        if (!is_dir($this->customDir)) {
            @mkdir($this->customDir, 0755, true);
        }
    }
    
    /**
     * 현재 사용자 정의 매핑 반환
     * @return array ['ext' => 'icon_name', ...]
     */
    public function getUserMap(): array {
        if (!file_exists($this->mapFile)) return [];
        $data = @json_decode(@file_get_contents($this->mapFile), true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * 사용자 정의 매핑 저장
     */
    private function saveUserMap(array $map): bool {
        $dir = dirname($this->mapFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return @file_put_contents($this->mapFile, $json, LOCK_EX) !== false;
    }
    
    /**
     * 내장 아이콘 목록 (builtin - 펜닐이 기본으로 선택할 수 있는 것들)
     * @return array ['pdf', 'word', ...]
     */
    public function getBuiltinIcons(): array {
        // 갤러리에서 숨길 내부 전용 아이콘:
        //   - folder: 폴더 아이콘 (파일 아이콘 선택에 부적합)
        //   - default: 기본/대체 아이콘 (내부 사용)
        //   - zip: 독립 파일 존재하나 실사용은 archive.svg + 라벨 방식 → 혼란 방지
        //   (archive.svg, subtitle.svg는 라벨 붙는 특수 아이콘 - 별도 섹션으로 처리됨)
        $hiddenIcons = ['folder', 'default', 'zip'];
        
        $icons = [];
        if (!is_dir($this->builtinDir)) return $icons;
        foreach (new \DirectoryIterator($this->builtinDir) as $file) {
            if ($file->isDot() || !$file->isFile()) continue;
            $name = $file->getFilename();
            if (substr($name, -4) !== '.svg') continue;
            $iconName = substr($name, 0, -4);
            if (in_array($iconName, $hiddenIcons, true)) continue;
            $icons[] = $iconName;
        }
        sort($icons);
        return $icons;
    }
    
    /**
     * 사용자 정의(커스텀) 아이콘 목록
     */
    public function getCustomIcons(): array {
        $icons = [];
        if (!is_dir($this->customDir)) return $icons;
        foreach (new \DirectoryIterator($this->customDir) as $file) {
            if ($file->isDot() || !$file->isFile()) continue;
            $name = $file->getFilename();
            if (substr($name, -4) !== '.svg') continue;
            $icons[] = substr($name, 0, -4);
        }
        sort($icons);
        return $icons;
    }
    
    /**
     * 확장자 정제
     */
    private function sanitizeExt(string $ext): string {
        $ext = strtolower(trim($ext));
        // 앞에 점 떼기
        if (strlen($ext) > 0 && $ext[0] === '.') {
            $ext = substr($ext, 1);
        }
        return preg_match(self::EXT_PATTERN, $ext) ? $ext : '';
    }
    
    /**
     * 아이콘 이름 정제
     */
    private function sanitizeName(string $name): string {
        $name = trim($name);
        // 확장자 제거
        if (substr($name, -4) === '.svg') {
            $name = substr($name, 0, -4);
        }
        return preg_match(self::NAME_PATTERN, $name) ? $name : '';
    }
    
    /**
     * SVG 내용 보안 검증 및 정제 + 크기 정규화
     * 위험 요소: <script>, on* 이벤트, javascript: URL, <foreignObject>, <iframe>, external href
     * 크기 정규화: 원본 크기와 상관없이 48x48 viewBox로 래핑 (탐색기 크기 일관성)
     */
    private function sanitizeSvg(string $content): string {
        // BOM 제거
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        
        // SVG 태그로 시작하는지 확인
        if (!preg_match('/^\s*(<\?xml[^?]*\?>\s*)?(<!DOCTYPE[^>]*>\s*)?<svg\b/i', $content)) {
            return '';
        }
        
        // <script> 태그 제거 (내용 포함)
        $content = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $content);
        $content = preg_replace('#<script\b[^>]*/>#i', '', $content);
        
        // <foreignObject>, <iframe>, <object>, <embed> 제거
        $content = preg_replace('#<(foreignObject|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $content);
        $content = preg_replace('#<(foreignObject|iframe|object|embed)\b[^>]*/>#i', '', $content);
        
        // on* 이벤트 속성 제거 (onclick, onload, onerror 등)
        $content = preg_replace('/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', $content);
        $content = preg_replace("/\s+on[a-z]+\s*=\s*'[^']*'/i", '', $content);
        $content = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/i', '', $content);
        
        // javascript: URL 제거
        $content = preg_replace('/(["\'=\s])\s*javascript\s*:/i', '$1about:blank', $content);
        
        // <use xlink:href="http..."> 외부 참조 제거
        $content = preg_replace('/xlink:href\s*=\s*["\'](https?|file|ftp):[^"\']*["\']/i', 'xlink:href="#"', $content);
        $content = preg_replace('/href\s*=\s*["\'](https?|file|ftp):[^"\']*["\']/i', 'href="#"', $content);
        
        // <!ENTITY> 선언 제거 (XXE 방지)
        $content = preg_replace('/<!ENTITY[^>]*>/i', '', $content);
        $content = preg_replace('/<!DOCTYPE[^>]*>/i', '', $content);
        
        // 크기 정규화 - 원본 viewBox/width/height 추출
        $origViewBox = null;
        $origWidth = null;
        $origHeight = null;
        
        if (preg_match('/<svg\b[^>]*\bviewBox\s*=\s*["\']([^"\']+)["\']/i', $content, $m)) {
            $origViewBox = trim($m[1]);
        }
        if (preg_match('/<svg\b[^>]*\bwidth\s*=\s*["\']?(\d+(?:\.\d+)?)/i', $content, $m)) {
            $origWidth = (float)$m[1];
        }
        if (preg_match('/<svg\b[^>]*\bheight\s*=\s*["\']?(\d+(?:\.\d+)?)/i', $content, $m)) {
            $origHeight = (float)$m[1];
        }
        
        // viewBox가 없으면 width/height에서 계산
        if (!$origViewBox && $origWidth && $origHeight) {
            $origViewBox = "0 0 {$origWidth} {$origHeight}";
        }
        
        // 여전히 없으면 기본값
        if (!$origViewBox) {
            $origViewBox = "0 0 48 48";
        }
        
        // 내부 콘텐츠 추출 (<svg...>와 </svg> 사이)
        if (preg_match('/<svg\b[^>]*>(.*)<\/svg\s*>/is', $content, $m)) {
            $innerContent = $m[1];
            
            // 48x48 표준 viewBox로 래핑
            // preserveAspectRatio="xMidYMid meet" → 원본 비율 유지하며 48x48에 맞게 자동 조정
            $content = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                     . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
                     . 'viewBox="' . $origViewBox . '" width="48" height="48" '
                     . 'preserveAspectRatio="xMidYMid meet">' . "\n"
                     . $innerContent
                     . "\n</svg>";
        }
        
        return $content;
    }
    
    /**
     * 이미지 업로드 처리 (SVG, PNG, JPG, GIF, WEBP, ICO, BMP 지원)
     * 비-SVG 포맷은 자동으로 SVG 래퍼에 base64 임베드됨
     * 
     * @param string $iconName 저장할 아이콘 이름 (확장자 없음)
     * @param array $file $_FILES의 개별 파일 항목
     * @param bool $labelMode true이면 이름 앞에 'archive_' 접두어를 추가 → 렌더 시 확장자 라벨이 우상단에 자동 표시
     * @param bool $removeBg true이면 이미지 업로드 시 흰색 배경 자동 제거 (GD 필요)
     * @param string $labelText 비어있지 않으면 라벨 감지/교체 시도 또는 SVG 오버레이 라벨 주입
     * @param bool $labelAuto true이면 라벨 감지 실패 시 오버레이 라벨 추가하지 않고 원본 유지 (자동 모드)
     *                       false (명시적 모드)이면 감지 실패 시 기존 방식대로 SVG 오버레이 노란 라벨 추가
     * @return array ['success' => bool, 'error' => string, 'name' => string]
     */
    public function uploadIcon(string $iconName, array $file, bool $labelMode = false, bool $removeBg = false, string $labelText = '', bool $labelAuto = false): array {
        $name = $this->sanitizeName($iconName);
        if ($name === '') {
            return ['success' => false, 'error' => '아이콘 이름은 영문/숫자/하이픈/언더스코어만 허용합니다 (1~32자)'];
        }
        
        // 라벨형 아이콘은 이름 앞에 'archive_' 접두어 부여 (getFileIcon에서 이를 보고 라벨 스타일 렌더)
        //   이미 접두어가 붙어있으면 중복 방지
        if ($labelMode && strpos($name, 'archive_') !== 0) {
            // 총 32자 제한 내로 맞추기
            $name = substr('archive_' . $name, 0, 32);
        }
        
        // 업로드 에러 체크
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => '유효한 업로드 파일이 아닙니다'];
        }
        
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => '업로드 실패 (error code: ' . $file['error'] . ')'];
        }
        
        // 확장자 체크
        $origName = $file['name'] ?? '';
        $origExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($origExt, self::ALLOWED_UPLOAD_EXTS)) {
            return ['success' => false, 'error' => '지원하지 않는 이미지 형식입니다 (svg/png/jpg/gif/webp/ico/bmp만 가능)'];
        }
        
        // 파일 크기 체크 (SVG vs 이미지 분리)
        $maxSize = ($origExt === 'svg') ? self::MAX_SVG_SIZE : self::MAX_IMAGE_SIZE;
        if ($file['size'] > $maxSize) {
            $maxKB = $maxSize / 1024;
            return ['success' => false, 'error' => '파일이 너무 큽니다 (최대 ' . $maxKB . 'KB)'];
        }
        
        // 내용 읽기
        $content = @file_get_contents($file['tmp_name']);
        if ($content === false || $content === '') {
            return ['success' => false, 'error' => '파일을 읽을 수 없습니다'];
        }
        
        // 포맷별 처리 → SVG 생성
        if ($origExt === 'svg') {
            // SVG: 기존 정제 로직
            $svgContent = $this->sanitizeSvg($content);
            if ($svgContent === '') {
                return ['success' => false, 'error' => '유효한 SVG 파일이 아닙니다'];
            }
            // SVG는 라스터 기반 라벨 감지 불가 (벡터)
            //   자동 모드에서는 SVG에 오버레이 라벨 추가 안 함 (원본 그대로)
            //   명시적 모드에서만 오버레이 주입
            if ($labelText !== '' && !$labelAuto) {
                $svgContent = $this->injectLabelIntoSvg($svgContent, $labelText);
            }
        } else {
            // 비-SVG: 이미지 처리 순서
            //   1. 라벨 감지/교체 시도 (labelText 있을 때) — 성공 시 배경제거는 이미 포함됨
            //   2. 실패 or labelText 없음 → 기존 방식 (convertImageToSvg + injectLabelIntoSvg)
            
            $processedData = $content;
            $labelReplaced = false;
            
            // ICO는 PNG로 먼저 변환
            $workExt = $origExt;
            if ($workExt === 'ico') {
                $extracted = $this->extractIcoImage($content);
                if ($extracted !== null) {
                    $processedData = $extracted['data'];
                    $workExt = $extracted['format'];
                }
            }
            
            // 라벨 텍스트가 있으면 먼저 감지/교체 시도
            if ($labelText !== '') {
                // 라벨 텍스트 정제 (영숫자만, 최대 8자)
                $cleanLabel = preg_replace('/[^A-Za-z0-9]/', '', $labelText);
                if ($cleanLabel !== '') {
                    $cleanLabel = strtoupper(substr($cleanLabel, 0, 8));
                    $replaced = $this->detectAndReplaceLabel($processedData, $workExt, $cleanLabel);
                    if ($replaced !== null) {
                        $processedData = $replaced;
                        $workExt = 'png'; // 출력은 PNG
                        $labelReplaced = true;
                    }
                }
            }
            
            // SVG 래핑 (+ 요청 시 흰색 배경 제거)
            //   라벨 교체 성공/실패와 무관하게 배경 제거는 독립적으로 적용
            //   (라벨 교체는 이미지 일부분만 수정하므로, 나머지 배경은 제거 대상)
            $svgContent = $this->convertImageToSvg($processedData, $workExt, $removeBg);
            if ($svgContent === '') {
                return ['success' => false, 'error' => '이미지 변환 실패 (손상되었거나 지원하지 않는 포맷)'];
            }
            
            // 라벨 교체 실패 시 fallback (이미지가 너무 작거나 손상 등의 이유):
            //   - 자동 모드($labelAuto=true): 오버레이 추가하지 않고 원본 유지
            //   - 명시적 모드($labelAuto=false): SVG 오버레이 노란 라벨 추가
            if ($labelText !== '' && !$labelReplaced && !$labelAuto) {
                $svgContent = $this->injectLabelIntoSvg($svgContent, $labelText);
            }
        }
        
        // 저장
        $targetPath = $this->customDir . '/' . $name . '.svg';
        if (@file_put_contents($targetPath, $svgContent, LOCK_EX) === false) {
            return ['success' => false, 'error' => '파일 저장 실패'];
        }
        
        return ['success' => true, 'name' => $name];
    }
    
    /**
     * 갤러리 아이콘을 복사해서 옵션(배경제거/라벨교체) 적용 후 새 custom 파일로 저장
     *   ※ uploadIcon의 갤러리 버전 - $file 대신 기존 갤러리 아이콘 이름을 받음
     *
     * @param string $sourceName 원본 갤러리 아이콘 이름 (builtin 또는 custom)
     * @param string $newName    저장할 새 이름 (user_ext_ts 형식)
     * @param bool   $labelMode  true면 archive_ 접두어 부여 (CSS 오버레이 모드)
     * @param bool   $removeBg   true면 흰색 배경 제거
     * @param string $labelText  라벨 텍스트 (빈값이면 라벨 처리 없음)
     * @param bool   $labelAuto  true면 라벨 감지/교체 실패 시 원본 유지 (오버레이 강제 삽입 X)
     */
    public function cloneFromGallery(string $sourceName, string $newName, bool $labelMode = false, bool $removeBg = false, string $labelText = '', bool $labelAuto = false): array {
        // 원본 이름 정제 (서픽스 '|label' 제거)
        $sourceName = $this->stripLabelSuffix($sourceName);
        $sourceClean = $this->sanitizeName($sourceName);
        if ($sourceClean === '') {
            return ['success' => false, 'error' => '잘못된 원본 아이콘 이름'];
        }
        
        // 원본 파일 경로 찾기 (custom 우선, builtin fallback)
        $customPath = $this->customDir . '/' . $sourceClean . '.svg';
        $builtinPath = $this->builtinDir . '/' . $sourceClean . '.svg';
        $sourcePath = null;
        if (file_exists($customPath)) {
            $sourcePath = $customPath;
        } elseif (file_exists($builtinPath)) {
            $sourcePath = $builtinPath;
        } else {
            return ['success' => false, 'error' => '원본 아이콘이 존재하지 않습니다'];
        }
        
        // 새 이름 정제
        $name = $this->sanitizeName($newName);
        if ($name === '') {
            return ['success' => false, 'error' => '잘못된 새 아이콘 이름'];
        }
        
        // 라벨형이면 archive_ 접두어 부여
        if ($labelMode && strpos($name, 'archive_') !== 0) {
            $name = substr('archive_' . $name, 0, 32);
        }
        
        // 원본 내용 읽기
        $content = @file_get_contents($sourcePath);
        if ($content === false || $content === '') {
            return ['success' => false, 'error' => '원본 아이콘을 읽을 수 없습니다'];
        }
        
        // 갤러리 아이콘은 전부 SVG (.svg) - 내부 래핑 구조에 따라 분기
        //   Case A: 기존 custom이 PNG/JPG를 base64로 embed한 SVG → base64 추출해서 라스터 처리 가능
        //   Case B: 순수 벡터 SVG → 라스터 라벨 감지 불가, 배경 제거도 의미 없음 (벡터엔 배경 개념 애매)
        
        $rasterData = $this->extractBase64ImageFromSvg($content);
        
        if ($rasterData !== null) {
            // Case A: base64 임베드 → 기존 uploadIcon의 비-SVG 경로와 동일 처리
            $processedData = $rasterData['data'];
            $workExt = $rasterData['ext'];
            $labelReplaced = false;
            
            // 라벨 텍스트 있으면 감지/교체 시도
            if ($labelText !== '') {
                $cleanLabel = preg_replace('/[^A-Za-z0-9]/', '', $labelText);
                if ($cleanLabel !== '') {
                    $cleanLabel = strtoupper(substr($cleanLabel, 0, 8));
                    $replaced = $this->detectAndReplaceLabel($processedData, $workExt, $cleanLabel);
                    if ($replaced !== null) {
                        $processedData = $replaced;
                        $workExt = 'png';
                        $labelReplaced = true;
                    }
                }
            }
            
            // SVG 래핑 + 배경 제거 (요청 시)
            $svgContent = $this->convertImageToSvg($processedData, $workExt, $removeBg);
            if ($svgContent === '') {
                return ['success' => false, 'error' => '이미지 변환 실패'];
            }
            
            // 라벨 교체 실패 시 명시적 모드만 SVG 오버레이 삽입
            if ($labelText !== '' && !$labelReplaced && !$labelAuto) {
                $svgContent = $this->injectLabelIntoSvg($svgContent, $labelText);
            }
        } else {
            // Case B: 순수 벡터 SVG → sanitize 후 필요 시 오버레이만 삽입
            //   배경 제거는 벡터에 적용 불가 → 무시
            //   라벨 감지/교체도 불가 → 명시적 모드만 오버레이 삽입
            $svgContent = $this->sanitizeSvg($content);
            if ($svgContent === '') {
                return ['success' => false, 'error' => '유효한 SVG가 아닙니다'];
            }
            if ($labelText !== '' && !$labelAuto) {
                $svgContent = $this->injectLabelIntoSvg($svgContent, $labelText);
            }
        }
        
        // 저장 (이름 충돌 방지: 이미 존재하면 타임스탬프 추가)
        $targetPath = $this->customDir . '/' . $name . '.svg';
        if (file_exists($targetPath)) {
            $name = substr($name . '_' . substr((string)time(), -4), 0, 32);
            $targetPath = $this->customDir . '/' . $name . '.svg';
        }
        
        if (@file_put_contents($targetPath, $svgContent, LOCK_EX) === false) {
            return ['success' => false, 'error' => '파일 저장 실패'];
        }
        
        return ['success' => true, 'name' => $name];
    }
    
    /**
     * SVG 내부에 base64로 임베드된 raster image 추출
     *   → <image href="data:image/png;base64,..."> 형태에서 바이너리 디코딩
     *   
     * @return array|null ['data' => binary, 'ext' => 'png'|'jpg'|'gif'|'webp'] 또는 null
     */
    private function extractBase64ImageFromSvg(string $svg): ?array {
        // href 또는 xlink:href 속성에서 data URI 찾기
        if (preg_match('#(?:xlink:)?href\s*=\s*["\']data:image/(png|jpe?g|gif|webp|bmp);base64,([A-Za-z0-9+/=]+)["\']#i', $svg, $m)) {
            $ext = strtolower($m[1]);
            if ($ext === 'jpeg') $ext = 'jpg';
            $binary = @base64_decode($m[2], true);
            if ($binary !== false && strlen($binary) > 100) {
                return ['data' => $binary, 'ext' => $ext];
            }
        }
        return null;
    }
    
    /**
     * 일반 이미지 파일(PNG/JPG/GIF/WEBP/ICO/BMP)을 SVG로 변환
     * base64로 임베드하여 48x48 SVG 캔버스에 배치
     * 
     * @param string $data 원본 이미지 바이너리
     * @param string $ext 원본 확장자 (소문자)
     * @return string SVG 문자열 또는 빈 문자열 (실패 시)
     */
    private function convertImageToSvg(string $data, string $ext, bool $removeBg = false): string {
        // ICO: 첫 번째 PNG/BMP 이미지 추출
        if ($ext === 'ico') {
            $extracted = $this->extractIcoImage($data);
            if ($extracted === null) return '';
            $data = $extracted['data'];
            $ext = $extracted['format']; // 'png' 또는 'bmp'
        }
        
        // MIME 타입 매핑
        $mimeMap = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
        ];
        
        $mime = $mimeMap[$ext] ?? null;
        if ($mime === null) return '';
        
        // 이미지 실제 유효성 검증 (매직 넘버 체크)
        if (!$this->isValidImage($data, $ext)) {
            return '';
        }
        
        // 흰색 배경 제거 (GD 필요) — 옵션
        //   요청이 있고 GD가 있을 때만 처리. 실패 시 원본 그대로
        if ($removeBg && function_exists('imagecreatefromstring')) {
            $processed = $this->removeWhiteBackground($data, $ext);
            if ($processed !== null) {
                $data = $processed;
                $mime = 'image/png'; // 투명 처리는 PNG로 출력
            }
        }
        
        $b64 = base64_encode($data);
        
        // SVG 래퍼 (48x48 viewBox, 이미지를 가득 채움)
        // preserveAspectRatio="xMidYMid meet"으로 비율 유지하며 중앙 배치
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
             . 'viewBox="0 0 48 48" width="48" height="48">' . "\n"
             . '  <image x="0" y="0" width="48" height="48" '
             . 'preserveAspectRatio="xMidYMid meet" '
             . 'xlink:href="data:' . $mime . ';base64,' . $b64 . '"/>' . "\n"
             . '</svg>';
    }
    
    /**
     * 흰색(또는 유사색) 배경 제거 → 투명 PNG로 재인코딩
     * 
     * 전략:
     *   1. 모서리 4개 픽셀을 샘플링
     *   2. 이들의 평균 색이 밝으면(임계값 이상) 배경으로 판정
     *   3. 해당 배경색과 유사한 픽셀 전부를 투명 처리 (톨러런스 허용)
     * 
     * @return string|null PNG 바이너리 (실패 시 null)
     */
    private function removeWhiteBackground(string $data, string $ext): ?string {
        try {
            $img = @imagecreatefromstring($data);
            if (!$img) return null;
            
            $w = imagesx($img);
            $h = imagesy($img);
            if ($w < 2 || $h < 2) { imagedestroy($img); return null; }
            
            // 새 투명 캔버스 생성 (truecolor + alpha)
            $out = imagecreatetruecolor($w, $h);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
            imagefilledrectangle($out, 0, 0, $w - 1, $h - 1, $transparent);
            
            // 모서리 4개 샘플링
            $corners = [
                imagecolorat($img, 0, 0),
                imagecolorat($img, $w - 1, 0),
                imagecolorat($img, 0, $h - 1),
                imagecolorat($img, $w - 1, $h - 1),
            ];
            $rSum = 0; $gSum = 0; $bSum = 0;
            foreach ($corners as $c) {
                $rSum += ($c >> 16) & 0xFF;
                $gSum += ($c >> 8) & 0xFF;
                $bSum += $c & 0xFF;
            }
            $bgR = (int)($rSum / 4);
            $bgG = (int)($gSum / 4);
            $bgB = (int)($bSum / 4);
            
            // 배경 판정 조건 (둘 다 충족해야 함):
            //   1) 밝기 >= 220 → 흰색/아주 밝은 배경만 허용 (회색/컬러는 제외)
            //   2) 무채색 (R/G/B 편차 <= 20) → 컬러 배경(파랑/빨강 등)은 배경으로 오인하지 않음
            //   → "흰색" 배경만 제거. 회색 및 유색 배경은 그대로 유지
            $brightness = ($bgR + $bgG + $bgB) / 3;
            $maxCh = max($bgR, $bgG, $bgB);
            $minCh = min($bgR, $bgG, $bgB);
            $chromaDiff = $maxCh - $minCh;
            
            if ($brightness < 220 || $chromaDiff > 20) {
                imagedestroy($img); imagedestroy($out);
                return null; // 배경 제거 불가 - 원본 유지 (흰색이 아님)
            }
            
            // 픽셀 순회 → 배경색과 유사하면 투명, 아니면 그대로 복사
            //   톨러런스: 유클리드 거리 25 이하면 배경 간주 (흰색만 타겟팅하므로 좁게)
            $tolerance = 25;
            $tolSq = $tolerance * $tolerance * 3;
            
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $rgba = imagecolorat($img, $x, $y);
                    $r = ($rgba >> 16) & 0xFF;
                    $g = ($rgba >> 8) & 0xFF;
                    $b = $rgba & 0xFF;
                    $a = ($rgba >> 24) & 0x7F;
                    
                    // 이미 투명한 픽셀은 그대로
                    if ($a >= 127) continue;
                    
                    $dr = $r - $bgR;
                    $dg = $g - $bgG;
                    $db = $b - $bgB;
                    $distSq = $dr * $dr + $dg * $dg + $db * $db;
                    
                    if ($distSq > $tolSq) {
                        // 배경 아님 → 원본 픽셀 복사
                        $srcColor = imagecolorallocatealpha($out, $r, $g, $b, $a);
                        if ($srcColor === false) continue;
                        imagesetpixel($out, $x, $y, $srcColor);
                    }
                    // 배경이면 이미 투명이므로 스킵
                }
            }
            
            // 2차 처리: 비투명 영역을 찾아서 크롭 + 중앙 정렬
            //   배경 제거 후 아이콘이 캔버스 한쪽에 치우쳐 있거나 작은 경우 개선
            //   → 비투명 픽셀의 bounding box를 찾아 새 캔버스에 중앙 배치 + 확대
            $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $rgba = imagecolorat($out, $x, $y);
                    $alpha = ($rgba >> 24) & 0x7F;
                    if ($alpha < 127) {
                        // 비투명 픽셀
                        if ($x < $minX) $minX = $x;
                        if ($y < $minY) $minY = $y;
                        if ($x > $maxX) $maxX = $x;
                        if ($y > $maxY) $maxY = $y;
                    }
                }
            }
            
            // 비투명 영역이 있고, 유의미하게 여백이 있을 때만 리사이즈
            //   (이미 꽉 찬 경우 건드리지 않음)
            if ($maxX >= 0 && $maxY >= 0) {
                $contentW = $maxX - $minX + 1;
                $contentH = $maxY - $minY + 1;
                
                // 원본 대비 콘텐츠 영역이 90% 미만일 때만 재배치
                if ($contentW < $w * 0.9 || $contentH < $h * 0.9) {
                    // 정사각형 캔버스로 통일 (48x48 SVG viewBox에 맞춤)
                    $canvasSize = max($w, $h);
                    $final = imagecreatetruecolor($canvasSize, $canvasSize);
                    imagealphablending($final, false);
                    imagesavealpha($final, true);
                    $transBg = imagecolorallocatealpha($final, 0, 0, 0, 127);
                    imagefilledrectangle($final, 0, 0, $canvasSize - 1, $canvasSize - 1, $transBg);
                    
                    // 콘텐츠가 캔버스의 90%를 차지하도록 스케일 계산
                    $targetSize = (int)($canvasSize * 0.90);
                    $scale = min($targetSize / $contentW, $targetSize / $contentH);
                    $scaledW = (int)($contentW * $scale);
                    $scaledH = (int)($contentH * $scale);
                    
                    // 중앙 정렬
                    $dstX = (int)(($canvasSize - $scaledW) / 2);
                    $dstY = (int)(($canvasSize - $scaledH) / 2);
                    
                    imagealphablending($final, false);
                    imagecopyresampled(
                        $final, $out,
                        $dstX, $dstY, $minX, $minY,
                        $scaledW, $scaledH, $contentW, $contentH
                    );
                    
                    imagedestroy($out);
                    $out = $final;
                }
            }
            
            // PNG로 출력
            ob_start();
            imagepng($out, null, 9);
            $pngData = ob_get_clean();
            
            imagedestroy($img);
            imagedestroy($out);
            
            return $pngData ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 이미지에서 기존 라벨을 감지하고 새 텍스트로 교체
     * 
     * 개선된 전략 (v2):
     *   1. 배경색 파악 (모서리 4픽셀)
     *   2. 각 행별 "비-배경 픽셀 연속 블록" 감지 (가장 긴 연속 구간)
     *   3. 연속된 여러 행이 비슷한 폭의 블록을 가지면 "라벨 후보"로 판정
     *   4. 후보 중 두께가 이미지 높이의 10% 이상 + 중심부 색이 유채색인 것을 라벨로 확정
     *   5. 해당 영역을 감지된 라벨 색으로 덮고 → 흰색 텍스트로 새 라벨 그림
     * 
     * @param string $data 이미지 바이너리
     * @param string $ext 확장자 (png/jpg 등)
     * @param string $labelText 새 라벨 텍스트
     * @return string|null PNG 바이너리 (성공), null (감지 실패)
     */
    private function detectAndReplaceLabel(string $data, string $ext, string $labelText): ?string {
        // 디버그 로그 비활성화 (배포 시): 필요 시 아래 3줄 복원
        // $debugLog = __DIR__ . '/../data/icon_debug.log';
        // if (@file_exists($debugLog) && @filesize($debugLog) > 100 * 1024) {
        //     @file_put_contents($debugLog, '');
        // }
        // $log = function($msg) use ($debugLog) {
        //     @file_put_contents($debugLog, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
        // };
        $log = function($msg) { /* no-op: icon_debug.log 비활성화됨 */ };
        
        // 빈 라벨 텍스트는 처리하지 않음 (방어적 체크)
        if ($labelText === '') return null;
        
        try {
            if (!function_exists('imagecreatefromstring')) {
                $log("FAIL: GD imagecreatefromstring 없음");
                return null;
            }
            
            $img = @imagecreatefromstring($data);
            if (!$img) {
                $log("FAIL: imagecreatefromstring 반환값 false (ext=$ext, size=" . strlen($data) . "B)");
                return null;
            }
            
            $w = imagesx($img);
            $h = imagesy($img);
            $log("START: {$w}x{$h} ext=$ext label=$labelText");
            if ($w < 20 || $h < 20) { 
                imagedestroy($img); 
                $log("FAIL: 이미지가 너무 작음 ({$w}x{$h})");
                return null; 
            }
            
            // 배경색 파악 (모서리 4개 평균)
            //   투명 픽셀 제외: 알파 >= 64이면 그 픽셀은 투명으로 간주하고 배경 판정에서 제외
            $rSum = 0; $gSum = 0; $bSum = 0; $validCnt = 0;
            $transparentCnt = 0;
            $cornerCoords = [
                [0, 0],
                [$w - 1, 0],
                [0, $h - 1],
                [$w - 1, $h - 1],
            ];
            foreach ($cornerCoords as [$cx, $cy]) {
                $c = imagecolorat($img, $cx, $cy);
                $alpha = ($c >> 24) & 0x7F;
                if ($alpha >= 64) {
                    // 투명 또는 반투명 (배경으로 간주하되 색은 흰색으로)
                    $transparentCnt++;
                    continue;
                }
                $rSum += ($c >> 16) & 0xFF;
                $gSum += ($c >> 8) & 0xFF;
                $bSum += $c & 0xFF;
                $validCnt++;
            }
            if ($transparentCnt >= 3) {
                // 모서리 대부분 투명 → 배경은 흰색으로 간주 (투명 영역은 라벨 덮을 때 흰색 대신 유지됨)
                $bgR = 255; $bgG = 255; $bgB = 255;
                $log("BG: 투명 모서리 ({$transparentCnt}개) → 흰색 처리");
            } elseif ($validCnt > 0) {
                $bgR = (int)($rSum / $validCnt);
                $bgG = (int)($gSum / $validCnt);
                $bgB = (int)($bSum / $validCnt);
            } else {
                // 모두 유효하지 않음 (이론상 도달 불가)
                $bgR = 255; $bgG = 255; $bgB = 255;
            }
            
            // 각 행에서 "비-배경 픽셀의 좌우 범위(span)" 추출
            //   ★ 중간에 공백이 있어도 (예: 라벨 안의 텍스트 AI) 첫 비배경~마지막 비배경까지를 하나의 span으로 인식
            //   → 라벨 색상은 비-배경 픽셀 평균으로 계산
            $rowData = []; // y => ['left', 'right', 'width', 'r', 'g', 'b']
            
            for ($y = 0; $y < $h; $y++) {
                $firstX = -1;
                $lastX = -1;
                $rSum = 0; $gSum = 0; $bSum = 0; $cnt = 0;
                
                for ($x = 0; $x < $w; $x++) {
                    $rgba = imagecolorat($img, $x, $y);
                    $alpha = ($rgba >> 24) & 0x7F;
                    // 투명 또는 반투명 픽셀 (알파 >= 64)은 배경으로 간주하여 제외
                    if ($alpha >= 64) continue;
                    
                    $r = ($rgba >> 16) & 0xFF;
                    $g = ($rgba >> 8) & 0xFF;
                    $b = $rgba & 0xFF;
                    
                    $dr = $r - $bgR; $dg = $g - $bgG; $db = $b - $bgB;
                    $distSq = $dr * $dr + $dg * $dg + $db * $db;
                    
                    if ($distSq > 50 * 50) {
                        if ($firstX === -1) $firstX = $x;
                        $lastX = $x;
                        $rSum += $r; $gSum += $g; $bSum += $b; $cnt++;
                    }
                }
                
                if ($firstX === -1) {
                    $rowData[$y] = null;
                    continue;
                }
                
                $spanWidth = $lastX - $firstX + 1;
                // span이 이미지 너비의 30% 이상 + 최소 1픽셀 있으면 라벨 후보 행
                if ($spanWidth > $w * 0.30 && $cnt > 0) {
                    $rowData[$y] = [
                        'left' => $firstX,
                        'right' => $lastX,
                        'width' => $spanWidth,
                        'r' => (int)($rSum / $cnt),
                        'g' => (int)($gSum / $cnt),
                        'b' => (int)($bSum / $cnt),
                    ];
                } else {
                    $rowData[$y] = null;
                }
            }
            
            // 연속된 행들을 블록으로 그룹화 (행 몇 개 누락 허용 - 라벨 내 텍스트 공백 대응)
            //   → 가장 두꺼운 블록을 라벨 후보로 선정
            $blocks = []; // [{top, bottom, leftSum, rightSum, rSum, gSum, bSum, count}]
            $curBlock = null;
            $gapCount = 0;           // 연속 null 행 개수 (일정 개수까지 허용)
            $maxGap = max(2, (int)($h * 0.03));  // 행 높이 3%까지 공백 허용
            
            for ($y = 0; $y < $h; $y++) {
                $row = $rowData[$y] ?? null;
                
                if ($row === null) {
                    $gapCount++;
                    if ($curBlock !== null && $gapCount > $maxGap) {
                        // 블록 종료
                        $blocks[] = $curBlock;
                        $curBlock = null;
                        $gapCount = 0;
                    }
                    continue;
                }
                
                $gapCount = 0;
                
                if ($curBlock === null) {
                    $curBlock = [
                        'top' => $y,
                        'bottom' => $y,
                        'leftSum' => $row['left'],
                        'rightSum' => $row['right'],
                        'rSum' => $row['r'],
                        'gSum' => $row['g'],
                        'bSum' => $row['b'],
                        'count' => 1,
                    ];
                } else {
                    // 기존 블록과 비슷한 색이면 이어가기
                    $avgR = $curBlock['rSum'] / $curBlock['count'];
                    $avgG = $curBlock['gSum'] / $curBlock['count'];
                    $avgB = $curBlock['bSum'] / $curBlock['count'];
                    
                    $dr = $row['r'] - $avgR; $dg = $row['g'] - $avgG; $db = $row['b'] - $avgB;
                    $colorDist = sqrt($dr * $dr + $dg * $dg + $db * $db);
                    
                    if ($colorDist < 60) {
                        $curBlock['bottom'] = $y;
                        $curBlock['leftSum'] += $row['left'];
                        $curBlock['rightSum'] += $row['right'];
                        $curBlock['rSum'] += $row['r'];
                        $curBlock['gSum'] += $row['g'];
                        $curBlock['bSum'] += $row['b'];
                        $curBlock['count']++;
                    } else {
                        // 색 달라짐 → 블록 종료 + 새 블록 시작
                        $blocks[] = $curBlock;
                        $curBlock = [
                            'top' => $y,
                            'bottom' => $y,
                            'leftSum' => $row['left'],
                            'rightSum' => $row['right'],
                            'rSum' => $row['r'],
                            'gSum' => $row['g'],
                            'bSum' => $row['b'],
                            'count' => 1,
                        ];
                    }
                }
            }
            if ($curBlock !== null) $blocks[] = $curBlock;
            
            // 블록 필터링:
            //   1. 두께가 이미지 높이의 8% 이상 ~ 40% 이하 (파일 전체 본체 배제)
            //   2. 평균 색이 유채색 (R/G/B 편차 >= 20) — 회색 테두리 배제
            //      OR 어두운 회색 블록도 허용 (밝기 100 이하)
            //   3. 가로/세로 비율 2:1 이상 (납작한 가로 박스여야 라벨임)
            //   4. 중심 확인: 블록 내부 중앙 픽셀의 색이 블록 평균색과 유사해야 함
            //      (파일 본체처럼 안쪽이 흰색인 경우 배제)
            $minThickness = max(3, (int)($h * 0.08));
            $maxThickness = (int)($h * 0.40);
            $candidates = [];
            
            foreach ($blocks as $b) {
                $thickness = $b['bottom'] - $b['top'] + 1;
                if ($thickness < $minThickness) continue;
                if ($thickness > $maxThickness) continue; // 파일 본체 배제
                
                // 상단/하단 극단 배제 (파일 접힌 모서리, 상하 그림자 등)
                //   라벨은 이미지 수직 10%~90% 사이에 중심이 있어야 함
                //   크롭된 이미지는 라벨이 상하단에 위치할 수 있으므로 관대하게
                $centerY = ($b['top'] + $b['bottom']) / 2;
                if ($centerY < $h * 0.10 || $centerY > $h * 0.90) continue;
                
                $avgR = $b['rSum'] / $b['count'];
                $avgG = $b['gSum'] / $b['count'];
                $avgB = $b['bSum'] / $b['count'];
                $maxCh = max($avgR, $avgG, $avgB);
                $minCh = min($avgR, $avgG, $avgB);
                $chromaDiff = $maxCh - $minCh;
                $brightness = ($avgR + $avgG + $avgB) / 3;
                
                // 라벨 후보: 유채색이거나(chromaDiff>=20), 매우 어두운 색(검정계열)
                if ($chromaDiff < 20 && $brightness > 100) {
                    continue; // 회색 테두리 등 제외
                }
                
                $avgLeft = (int)($b['leftSum'] / $b['count']);
                $avgRight = (int)($b['rightSum'] / $b['count']);
                $widthPx = $avgRight - $avgLeft + 1;
                
                // 가로/세로 비율 체크: 납작한 가로 박스여야 라벨 (파일 세로 본체 배제)
                //   라벨은 가로가 세로보다 최소 1.5배 이상 길어야 함
                if ($widthPx < $thickness * 1.5) continue;
                
                // 중심 확인: 블록 세로 중앙의 가운데 픽셀이 블록 평균색과 유사한지
                //   파일 본체(내부 흰색 + 테두리 색)가 평균색으로 집계되는 경우 배제
                $checkY = (int)(($b['top'] + $b['bottom']) / 2);
                $checkX = (int)(($avgLeft + $avgRight) / 2);
                $checkRGBA = imagecolorat($img, $checkX, $checkY);
                $checkR = ($checkRGBA >> 16) & 0xFF;
                $checkG = ($checkRGBA >> 8) & 0xFF;
                $checkB = $checkRGBA & 0xFF;
                $dCR = $checkR - $avgR;
                $dCG = $checkG - $avgG;
                $dCB = $checkB - $avgB;
                $centerDist = sqrt($dCR * $dCR + $dCG * $dCG + $dCB * $dCB);
                if ($centerDist > 70) continue; // 중앙 픽셀이 평균색과 다르면 라벨 아님
                
                $candidates[] = [
                    'top' => $b['top'],
                    'bottom' => $b['bottom'],
                    'left' => $avgLeft,
                    'right' => $avgRight,
                    'r' => (int)$avgR,
                    'g' => (int)$avgG,
                    'b' => (int)$avgB,
                    'thickness' => $thickness,
                    'chromaDiff' => $chromaDiff,
                ];
            }
            
            // 후보 정렬: "라벨다움 점수" 기반
            //   점수 항목:
            //     1. chromaDiff (유채색): 높을수록 +
            //     2. 세로 위치 (이미지 중앙에 가까울수록 +)
            //     3. 가로/세로 비율 (납작할수록 +)
            //     4. 상단 1/10 / 하단 1/10 영역 패널티 (필터 20%/80% 이후 미세 조정용)
            //     5. 두께 점수 (이미지 높이의 12~30%가 라벨다운 범위)
            $scoreBlock = function($c) use ($h) {
                $centerY = ($c['top'] + $c['bottom']) / 2;
                $imgCenter = $h / 2;
                // 세로 위치 점수: 중앙에서 멀수록 감점 (0~100)
                $yDist = abs($centerY - $imgCenter) / $imgCenter; // 0: 중앙, 1: 끝
                $positionScore = 100 * (1 - $yDist);
                
                // 상단 10% 영역(y < h*0.1) 패널티 (필터 20% 배제 외에 남은 극단 보정)
                if ($centerY < $h * 0.1) $positionScore -= 30;
                // 하단 10% 영역도 동일 패널티
                if ($centerY > $h * 0.9) $positionScore -= 30;
                
                // 가로/세로 비율 점수: 2:1~5:1이 라벨답, 그 외 감점
                $widthPx = $c['right'] - $c['left'] + 1;
                $ratio = $widthPx / $c['thickness'];
                $ratioScore = 0;
                if ($ratio >= 2.0 && $ratio <= 5.0) $ratioScore = 50;
                elseif ($ratio >= 1.5 && $ratio < 2.0) $ratioScore = 30;
                elseif ($ratio > 5.0) $ratioScore = 20;
                else $ratioScore = 10;
                
                // chroma 점수: 유채색일수록 +, 무채색이면 0
                $chromaScore = min(100, $c['chromaDiff']);
                
                // 두께 점수: 이미지 높이의 12~30%가 라벨다운 범위
                //   너무 얇으면(10% 이하) 선/텍스트일 가능성 → 감점
                //   너무 두꺼우면(30% 이상) 파일 본체 일부일 가능성 → 감점
                $thicknessRatio = $c['thickness'] / $h;
                $thicknessScore = 0;
                if ($thicknessRatio >= 0.12 && $thicknessRatio <= 0.30) $thicknessScore = 50;
                elseif ($thicknessRatio >= 0.10 && $thicknessRatio < 0.12) $thicknessScore = 20;
                elseif ($thicknessRatio >= 0.08 && $thicknessRatio < 0.10) $thicknessScore = 0;
                elseif ($thicknessRatio > 0.30 && $thicknessRatio <= 0.35) $thicknessScore = 20;
                else $thicknessScore = -20; // 너무 얇거나 너무 두꺼움
                
                return $positionScore + $ratioScore + $chromaScore + $thicknessScore;
            };
            
            if (!empty($candidates)) {
                usort($candidates, function($a, $b) use ($scoreBlock) {
                    return $scoreBlock($b) - $scoreBlock($a); // 높은 점수 먼저
                });
            }
            $log("strict candidates: " . count($candidates));
            
            // 엄격한 필터 통과 없으면 → 완화된 조건으로 재시도 (가로/세로 비만 느슨하게)
            //   라벨이 있긴 한데 엄격한 조건에 안 맞는 경우 (작은 라벨, 정사각에 가까운 라벨 등)
            if (empty($candidates)) {
                foreach ($blocks as $b) {
                    $thickness = $b['bottom'] - $b['top'] + 1;
                    if ($thickness < $minThickness) continue;
                    if ($thickness > $maxThickness) continue;
                    
                    $avgR = $b['rSum'] / $b['count'];
                    $avgG = $b['gSum'] / $b['count'];
                    $avgB = $b['bSum'] / $b['count'];
                    $chromaDiff = max($avgR, $avgG, $avgB) - min($avgR, $avgG, $avgB);
                    $brightness = ($avgR + $avgG + $avgB) / 3;
                    
                    // 유채색 필요 (relaxed)
                    if ($chromaDiff < 20 && $brightness > 100) continue;
                    
                    $avgLeft = (int)($b['leftSum'] / $b['count']);
                    $avgRight = (int)($b['rightSum'] / $b['count']);
                    $widthPx = $avgRight - $avgLeft + 1;
                    
                    // 완화: 가로/세로 비율 1:1 이상 (정사각 허용)
                    if ($widthPx < $thickness * 1.0) continue;
                    
                    // 상단/하단 극단 배제 (파일 접힌 모서리, 상하 그림자 등)
                    //   라벨은 이미지 수직 10%~90% 사이에 중심이 있어야 함
                    //   크롭된 이미지는 라벨이 상하단에 위치할 수 있으므로 관대하게
                    $centerY = ($b['top'] + $b['bottom']) / 2;
                    if ($centerY < $h * 0.10 || $centerY > $h * 0.90) continue;
                    
                    $candidates[] = [
                        'top' => $b['top'],
                        'bottom' => $b['bottom'],
                        'left' => $avgLeft,
                        'right' => $avgRight,
                        'r' => (int)$avgR,
                        'g' => (int)$avgG,
                        'b' => (int)$avgB,
                        'thickness' => $thickness,
                        'chromaDiff' => $chromaDiff,
                    ];
                }
                if (!empty($candidates)) {
                    usort($candidates, function($a, $b) use ($scoreBlock) {
                        return $scoreBlock($b) - $scoreBlock($a);
                    });
                }
                $log("relaxed candidates: " . count($candidates));
            }
            
            // 라벨 감지 실패 → null 반환 (원본 이미지 유지)
            //   라벨 없는 이미지에는 라벨을 억지로 만들지 않음 (펜닐 방침)
            if (empty($candidates)) {
                $log("NO LABEL DETECTED - 원본 유지");
                imagedestroy($img);
                return null;
            }
            
            // 최고 후보의 점수가 임계값 미만이면 라벨 아닌 것으로 판단
            //   이상적인 라벨: ~300점, 경계 케이스: ~135점
            //   임계값 180: 확실한 라벨만 교체 대상으로 선정 (펜닐의 얇은 하단 블록 같은 노이즈 배제)
            $topScore = $scoreBlock($candidates[0]);
            $log("top score: $topScore");
            if ($topScore < 180) {
                $log("SCORE TOO LOW ($topScore < 180) - 라벨 아님, 원본 유지");
                imagedestroy($img);
                return null;
            }
            
            // 라벨 박스 결정: 감지된 박스 + 여유 크게 (원본 라벨 완전 삭제 목적)
            //   세로로 +3픽셀씩, 가로로 +4픽셀씩 확장
            //   원본 라벨 색이 그림자/그라데이션으로 살짝 삐져나올 수 있으니 충분히 크게
            $out = imagecreatetruecolor($w, $h);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
            imagealphablending($out, true);
            
            // 위에서 chromaDiff 기준으로 이미 정렬됨 - 가장 유채색 블록 사용
            $label = $candidates[0];
            $log("label pick: y={$label['top']}-{$label['bottom']} x={$label['left']}-{$label['right']} chroma={$label['chromaDiff']}");
            
            $padX = 4; $padY = 3;
            $fillLeft   = max(0,       $label['left']   - $padX);
            $fillTop    = max(0,       $label['top']    - $padY);
            $fillRight  = min($w - 1,  $label['right']  + $padX);
            $fillBottom = min($h - 1,  $label['bottom'] + $padY);
            
            // 원본 라벨을 배경색으로 먼저 지우기 (잔흔 제거)
            //   배경색으로 덮으면 파일 모양 내부가 흰색이었다면 흰색으로 보임
            $bgColor = imagecolorallocate($out, $bgR, $bgG, $bgB);
            imagefilledrectangle($out, $fillLeft, $fillTop, $fillRight, $fillBottom, $bgColor);
            
            // 노란색 라벨 박스 그리기 (펜닐 요청: 일관된 노란색)
            //   색상: #ffd54f (amber 300, 기존 archive 라벨과 동일)
            //   테두리: #f9a825 (amber 800)
            $yellowBg = imagecolorallocate($out, 0xff, 0xd5, 0x4f);
            $yellowBorder = imagecolorallocate($out, 0xf9, 0xa8, 0x25);
            imagefilledrectangle($out, $fillLeft, $fillTop, $fillRight, $fillBottom, $yellowBg);
            // 테두리 (얇은 선)
            imagerectangle($out, $fillLeft, $fillTop, $fillRight, $fillBottom, $yellowBorder);
            
            // 새 텍스트 그리기 (진한 색, 중앙 정렬)
            //   노란 배경에 잘 보이도록 진회색 텍스트 (#333333)
            $textColor = imagecolorallocate($out, 0x33, 0x33, 0x33);
            $labelW = $fillRight - $fillLeft;
            $labelH = $fillBottom - $fillTop;
            $textLen = strlen($labelText);
            
            $ttfRendered = false;
            if (function_exists('imagettftext') && function_exists('imagettfbbox')) {
                // 시스템 TTF 폰트 찾기 (여러 후보 경로 - OS별)
                $ttfCandidates = [
                    // Linux
                    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                    '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
                    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                    // Windows (Apache/PHP가 Windows에서 접근 가능한 경로)
                    'C:/Windows/Fonts/arialbd.ttf',
                    'C:/Windows/Fonts/ARIALBD.TTF',
                    'C:\\Windows\\Fonts\\arialbd.ttf',
                    'C:/Windows/Fonts/arial.ttf',
                    'C:\\Windows\\Fonts\\arial.ttf',
                    'C:/Windows/Fonts/malgun.ttf',       // 맑은 고딕
                    'C:\\Windows\\Fonts\\malgun.ttf',
                    'C:/Windows/Fonts/gulim.ttc',        // 굴림
                    // macOS
                    '/System/Library/Fonts/Helvetica.ttc',
                    '/Library/Fonts/Arial Bold.ttf',
                ];
                $ttfPath = null;
                foreach ($ttfCandidates as $p) {
                    if (is_file($p) && is_readable($p)) { $ttfPath = $p; break; }
                }
                
                if ($ttfPath !== null) {
                    // 라벨 박스 크기에 맞게 폰트 크기 자동 조정 (높이 기준 60%, 최소 8pt)
                    //   bbox로 실제 텍스트 폭 측정 → 박스에 들어가는지 확인 후 조정
                    $fontSize = max(8, (int)($labelH * 0.55));
                    // 폰트 크기를 박스 폭에 맞게 줄이기 (반복 fitting)
                    for ($try = 0; $try < 5; $try++) {
                        $bbox = @imagettfbbox($fontSize, 0, $ttfPath, $labelText);
                        if (!$bbox) break;
                        $textW = abs($bbox[2] - $bbox[0]);
                        $textH = abs($bbox[5] - $bbox[1]);
                        if ($textW <= $labelW - 6 && $textH <= $labelH - 4) break;
                        $fontSize = (int)($fontSize * 0.85);
                        if ($fontSize < 6) break;
                    }
                    
                    $bbox = @imagettfbbox($fontSize, 0, $ttfPath, $labelText);
                    if ($bbox) {
                        $textW = abs($bbox[2] - $bbox[0]);
                        $textH = abs($bbox[5] - $bbox[1]);
                        // 중앙 정렬 (imagettftext는 baseline 기준)
                        $textX = $fillLeft + (int)(($labelW - $textW) / 2);
                        $textY = $fillTop + (int)(($labelH + $textH) / 2);
                        @imagettftext($out, $fontSize, 0, $textX, $textY, $textColor, $ttfPath, $labelText);
                        $ttfRendered = true;
                    }
                }
            }
            
            if (!$ttfRendered) {
                // GD 내장 폰트 fallback
                //   박스 크기에 맞춰 폰트 번호 선택 (1~5)
                $fontSizes = [
                    5 => [9, 15],   // 가장 큼
                    4 => [8, 14],
                    3 => [7, 13],
                    2 => [6, 11],
                    1 => [5, 8],
                ];
                $fontNum = 5; $fontW = 9; $fontH = 15;
                foreach ($fontSizes as $n => [$fw, $fh]) {
                    if ($textLen * $fw <= $labelW - 4 && $fh <= $labelH - 2) {
                        $fontNum = $n; $fontW = $fw; $fontH = $fh;
                        break;
                    }
                }
                $textW = $textLen * $fontW;
                $textX = $fillLeft + (int)(($labelW - $textW) / 2);
                $textY = $fillTop + (int)(($labelH - $fontH) / 2);
                if ($textX < $fillLeft) $textX = $fillLeft + 2;
                if ($textY < $fillTop) $textY = $fillTop + 2;
                imagestring($out, $fontNum, $textX, $textY, $labelText, $textColor);
            }
            
            // PNG 출력
            ob_start();
            imagepng($out, null, 9);
            $pngData = ob_get_clean();
            
            imagedestroy($img);
            imagedestroy($out);
            
            $log("SUCCESS: " . strlen($pngData) . "B 출력, fill=({$fillLeft},{$fillTop})-({$fillRight},{$fillBottom}), candidates=" . count($candidates));
            return $pngData ?: null;
        } catch (\Throwable $e) {
            $log("EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return null;
        }
    }
    
    /**
     * SVG에 라벨 텍스트 주입
     * 
     * 기존 SVG의 </svg> 태그 직전에 라벨 박스(노란 배경 + 대문자 텍스트)를 삽입.
     * viewBox 또는 width/height 속성을 파싱해서 우상단에 적절한 크기로 배치.
     * 
     * @param string $svg 원본 SVG 문자열
     * @param string $labelText 라벨 텍스트 (예: 'ZIP', 'RAR')
     * @return string 라벨이 주입된 SVG
     */
    private function injectLabelIntoSvg(string $svg, string $labelText): string {
        // 안전: 라벨 텍스트 정제 (영숫자 + 한글만, 최대 8자)
        $clean = preg_replace('/[^A-Za-z0-9가-힣]/u', '', $labelText);
        if ($clean === '' || $clean === null) return $svg;
        // mbstring 없을 때 대비한 방어적 substr
        $clean = function_exists('mb_substr') ? mb_substr($clean, 0, 8, 'UTF-8') : substr($clean, 0, 24);
        $clean = strtoupper($clean);
        
        // viewBox 파싱 시도 → 라벨 크기/위치 계산
        //   viewBox="0 0 48 48" 형태
        $vbWidth = 48.0; // 기본값
        $vbHeight = 48.0;
        if (preg_match('/viewBox\s*=\s*["\']\s*[\d.\-]+\s+[\d.\-]+\s+([\d.]+)\s+([\d.]+)\s*["\']/', $svg, $m)) {
            $vbWidth = (float)$m[1];
            $vbHeight = (float)$m[2];
        }
        
        // 라벨 박스 크기/위치 (아이콘 우상단, 아이콘 크기의 일정 비율)
        //   높이: viewBox 높이의 30%
        //   너비: 글자 수에 비례 (글자당 viewBox 너비의 12% + 패딩)
        $charCount = function_exists('mb_strlen') ? mb_strlen($clean, 'UTF-8') : strlen($clean);
        $labelH = $vbHeight * 0.30;
        $charW = $vbWidth * 0.12;
        $padding = $vbWidth * 0.06;
        $labelW = $charCount * $charW + $padding * 2;
        // 최대 너비 제한 (아이콘 전체 너비의 80%)
        if ($labelW > $vbWidth * 0.80) $labelW = $vbWidth * 0.80;
        
        $x = $vbWidth - $labelW - 1;      // 우측에서 1만큼 여백
        $y = 1;                            // 상단에서 1만큼 여백
        $textX = $x + $labelW / 2;         // 텍스트 중앙
        $textY = $y + $labelH * 0.72;      // 수직 중앙 정렬 (베이스라인 보정)
        $fontSize = $labelH * 0.60;
        $radius = $labelH * 0.15;
        
        // 라벨 그룹 빌드 - <g>로 감싸 레이어 식별 가능
        //   XSS 방지: 라벨 텍스트는 이미 정제됨
        $labelGroup = sprintf(
            '<g class="fs-injected-label">' .
            '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="%.2f" ry="%.2f" fill="#ffd54f" stroke="#f9a825" stroke-width="0.5"/>' .
            '<text x="%.2f" y="%.2f" text-anchor="middle" fill="#333" font-size="%.2f" font-weight="bold" font-family="Arial, sans-serif">%s</text>' .
            '</g>',
            $x, $y, $labelW, $labelH, $radius, $radius,
            $textX, $textY, $fontSize, htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8')
        );
        
        // </svg> 태그 직전에 삽입 (마지막 발생 위치)
        $pos = strrpos($svg, '</svg>');
        if ($pos === false) return $svg;
        
        return substr($svg, 0, $pos) . $labelGroup . substr($svg, $pos);
    }
    
    /**
     * 이미지 매직 넘버 검증 — 확장자 위조 방지
     */
    private function isValidImage(string $data, string $ext): bool {
        if (strlen($data) < 8) return false;
        
        $sig = substr($data, 0, 8);
        
        switch ($ext) {
            case 'png':
                return substr($sig, 0, 8) === "\x89PNG\r\n\x1a\n";
            case 'jpg':
            case 'jpeg':
                return substr($sig, 0, 3) === "\xFF\xD8\xFF";
            case 'gif':
                return substr($sig, 0, 6) === 'GIF87a' || substr($sig, 0, 6) === 'GIF89a';
            case 'webp':
                return substr($sig, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP';
            case 'bmp':
                return substr($sig, 0, 2) === 'BM';
            default:
                return false;
        }
    }
    
    /**
     * ICO 파일에서 첫 번째 이미지 (가장 큰 것 선호) 추출
     * @return array|null ['data' => 바이너리, 'format' => 'png'|'bmp']
     */
    private function extractIcoImage(string $data): ?array {
        if (strlen($data) < 22) return null;
        
        // ICO 헤더 파싱
        $header = unpack('vreserved/vtype/vcount', substr($data, 0, 6));
        if ($header === false) return null;
        
        // type 1=ICO, 2=CUR
        if ($header['type'] !== 1 && $header['type'] !== 2) return null;
        if ($header['count'] < 1 || $header['count'] > 32) return null;
        
        // 엔트리 목록 읽기 — 가장 큰 이미지 선택
        $bestEntry = null;
        $bestSize = 0;
        
        for ($i = 0; $i < $header['count']; $i++) {
            $entryOffset = 6 + $i * 16;
            if (strlen($data) < $entryOffset + 16) break;
            
            $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vsize/Voffset', substr($data, $entryOffset, 16));
            if ($entry === false) continue;
            
            // 폭/높이 0은 256 의미
            $width = $entry['width'] === 0 ? 256 : $entry['width'];
            $pixelCount = $width * $width;
            
            if ($entry['size'] > 0 && $entry['offset'] > 0 && $entry['offset'] + $entry['size'] <= strlen($data)) {
                if ($pixelCount > $bestSize) {
                    $bestSize = $pixelCount;
                    $bestEntry = $entry;
                }
            }
        }
        
        if ($bestEntry === null) return null;
        
        $imgData = substr($data, $bestEntry['offset'], $bestEntry['size']);
        if (strlen($imgData) < 8) return null;
        
        // PNG 형태인지 BMP 형태인지 판별
        if (substr($imgData, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return ['data' => $imgData, 'format' => 'png'];
        }
        
        // BMP의 경우 DIB 헤더만 있고 BMP 파일 헤더(14바이트)가 없음 → 추가 필요
        // DIB 헤더 첫 4바이트 = biSize (40=BITMAPINFOHEADER)
        $dibSize = unpack('V', substr($imgData, 0, 4))[1] ?? 0;
        if ($dibSize >= 40 && $dibSize <= 256) {
            // BMP 파일 헤더 생성 (14바이트): "BM" + 파일크기 + 예약(4) + 픽셀데이터 오프셋
            // ICO 내부 BMP는 높이가 2배로 저장됨 (AND 마스크 포함) — 복잡하므로
            // 브라우저가 이 변환된 BMP를 제대로 렌더하지 않을 수 있음
            // → 해결책: ICO의 BMP는 지원 포기하고 오류 반환. PNG 형태 ICO만 지원.
            return null;
        }
        
        return null;
    }
    
    /**
     * 커스텀 아이콘 삭제
     */
    public function deleteCustomIcon(string $iconName): array {
        $name = $this->sanitizeName($iconName);
        if ($name === '') {
            return ['success' => false, 'error' => '잘못된 아이콘 이름'];
        }
        
        $path = $this->customDir . '/' . $name . '.svg';
        if (!file_exists($path)) {
            return ['success' => false, 'error' => '아이콘이 존재하지 않습니다'];
        }
        
        // 이 아이콘을 참조하는 매핑은 'default'로 전환 (매핑 자체는 유지)
        //   → 갤러리에서 아이콘 삭제해도 확장자는 리스트에 남아있음 (기본 아이콘으로 표시)
        //   → 확장자 자체를 리스트에서 제거하려면 확장자 관리의 [🗑️ 삭제] 버튼 사용
        //   매핑값은 'name' 또는 'name|label' 형태 → parseMapValue로 실제 이름 추출 후 비교
        //   __folder 키는 예외: 삭제하면 기본 노란 폴더로 돌아감
        $map = $this->getUserMap();
        $changed = false;
        foreach ($map as $ext => $iconRef) {
            $parsed = $this->parseMapValue($iconRef);
            if ($parsed['name'] === $name) {
                if ($ext === '__folder') {
                    // 폴더 아이콘 → 기본값으로 되돌림 (키 제거)
                    unset($map['__folder']);
                } else {
                    $map[$ext] = 'default';
                }
                $changed = true;
            }
        }
        if ($changed) {
            $this->saveUserMap($map);
        }
        
        @unlink($path);
        return ['success' => true];
    }
    
    /**
     * 확장자 → 아이콘 매핑 설정
     * 
     * @param string $ext 확장자 (영숫자 1~16자)
     * @param string $iconName 아이콘 이름 (svg 파일명, 영숫자 + _ - 허용)
     * @param bool $labelMode true면 매핑값에 '|label' 서픽스 추가 → 렌더 시 CSS 오버레이 표시
     */
    public function setMapping(string $ext, string $iconName, bool $labelMode = false): array {
        $cleanExt = $this->sanitizeExt($ext);
        if ($cleanExt === '') {
            return ['success' => false, 'error' => '잘못된 확장자 (영숫자 1~16자만 허용)'];
        }
        
        // 들어온 iconName에 이미 '|label' 서픽스가 있으면 제거 후 재판정
        $iconName = $this->stripLabelSuffix($iconName);
        
        $cleanName = $this->sanitizeName($iconName);
        if ($cleanName === '') {
            return ['success' => false, 'error' => '잘못된 아이콘 이름'];
        }
        
        // 해당 아이콘이 실제로 존재하는지 확인 (builtin 또는 custom)
        $builtinPath = $this->builtinDir . '/' . $cleanName . '.svg';
        $customPath = $this->customDir . '/' . $cleanName . '.svg';
        if (!file_exists($builtinPath) && !file_exists($customPath)) {
            return ['success' => false, 'error' => '존재하지 않는 아이콘입니다'];
        }
        
        // labelMode면 '|label' 서픽스 부여 - 렌더 단에서 이 서픽스를 파싱해 CSS 오버레이 표시
        //   단, 이름이 이미 'archive_'로 시작하면 접두어 자체가 라벨 표시자이므로 서픽스 중복 금지
        $alreadyLabeled = strpos($cleanName, 'archive_') === 0;
        $storedValue = ($labelMode && !$alreadyLabeled) ? ($cleanName . '|label') : $cleanName;
        
        $map = $this->getUserMap();
        $map[$cleanExt] = $storedValue;
        
        if (!$this->saveUserMap($map)) {
            return ['success' => false, 'error' => '매핑 저장 실패'];
        }
        
        return ['success' => true, 'ext' => $cleanExt, 'icon' => $cleanName, 'label_mode' => $labelMode];
    }
    
    /**
     * 매핑값에서 '|label' 서픽스 분리
     *   반환: ['name' => 'pdf', 'labelMode' => true] 
     *   입력 'pdf|label' → name='pdf', labelMode=true
     *   입력 'pdf'       → name='pdf', labelMode=false
     */
    public function parseMapValue(string $value): array {
        if (substr($value, -6) === '|label') {
            return ['name' => substr($value, 0, -6), 'labelMode' => true];
        }
        return ['name' => $value, 'labelMode' => false];
    }
    
    /**
     * 매핑값에서 '|label' 서픽스만 제거
     */
    private function stripLabelSuffix(string $value): string {
        if (substr($value, -6) === '|label') {
            return substr($value, 0, -6);
        }
        return $value;
    }
    
    /**
     * 확장자 매핑 제거
     */
    public function removeMapping(string $ext): array {
        $cleanExt = $this->sanitizeExt($ext);
        if ($cleanExt === '') {
            return ['success' => false, 'error' => '잘못된 확장자'];
        }
        
        $map = $this->getUserMap();
        if (!isset($map[$cleanExt])) {
            return ['success' => true]; // 이미 없음
        }
        
        unset($map[$cleanExt]);
        if (!$this->saveUserMap($map)) {
            return ['success' => false, 'error' => '매핑 저장 실패'];
        }
        
        return ['success' => true];
    }
    
    /**
     * 특정 확장자에 해당하는 사용자 정의 아이콘 경로 반환
     * FileManager::getFileIcon()에서 호출
     * @return string|null 상대 경로 ("assets/file-icons/custom/xxx.svg") 또는 null
     */
    public function getIconPathForExt(string $ext): ?string {
        $cleanExt = $this->sanitizeExt($ext);
        if ($cleanExt === '') return null;
        
        $map = $this->getUserMap();
        if (!isset($map[$cleanExt])) return null;
        
        // 매핑값에서 '|label' 서픽스 제거 후 파일명으로 사용
        $iconName = $this->stripLabelSuffix($map[$cleanExt]);
        
        // custom 폴더 먼저 확인
        $customPath = $this->customDir . '/' . $iconName . '.svg';
        if (file_exists($customPath)) {
            return 'assets/file-icons/custom/' . $iconName . '.svg';
        }
        
        // builtin 폴더 확인
        $builtinPath = $this->builtinDir . '/' . $iconName . '.svg';
        if (file_exists($builtinPath)) {
            return 'assets/file-icons/' . $iconName . '.svg';
        }
        
        return null;
    }
    
    /**
     * 전체 설정 가져오기 (관리자 UI용)
     * 내장 확장자 매핑도 포함 — 윈도우 탐색기처럼 전체 리스트 표시용
     */
    public function getAll(): array {
        // FileManager에서 내장 매핑 가져오기
        require_once __DIR__ . '/FileManager.php';
        require_once __DIR__ . '/IconUrl.php';
        $fm = new FileManager();
        $builtinMap = $fm->getBuiltinIconMap();
        
        return [
            'builtin_icons' => $this->getBuiltinIcons(),
            'custom_icons' => $this->getCustomIcons(),
            'user_map' => $this->getUserMap(),
            'builtin_map' => $builtinMap,
            'buster_map' => IconUrl::allBusterMap(),  // 캐시 무효화용 해시 맵
            'label_position' => $this->getLabelPosition(),  // CSS 오버레이 라벨 위치
            'folder_icon' => $this->getFolderIcon(),  // 폴더 아이콘 커스텀 설정 (null=기본)
        ];
    }
    
    /**
     * 라벨 오버레이 위치 조회 (CSS 오버레이 모드 전용)
     *   적용 대상: fs-archive-icon .archive-ext, fs-subtitle-icon .subtitle-ext
     *   미적용: 자동 감지 교체로 이미지에 영구 박힌 라벨은 영향 없음
     *   
     *   9개 위치 값: top-left, top-center, top-right,
     *                center-left, center, center-right,
     *                bottom-left, bottom-center, bottom-right
     *   기본값: top-right (현재 동작 유지)
     */
    public function getLabelPosition(): string {
        $file = dirname(__DIR__) . '/data/icon_label_position.json';
        if (!file_exists($file)) return 'top-right';
        
        $data = @json_decode(@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['position'])) return 'top-right';
        
        $pos = (string)$data['position'];
        return in_array($pos, self::VALID_LABEL_POSITIONS, true) ? $pos : 'top-right';
    }
    
    /**
     * 라벨 오버레이 위치 저장
     */
    public function setLabelPosition(string $position): array {
        if (!in_array($position, self::VALID_LABEL_POSITIONS, true)) {
            return ['success' => false, 'error' => '유효하지 않은 위치 값'];
        }
        
        $dir = dirname(__DIR__) . '/data';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return ['success' => false, 'error' => 'data 디렉토리 생성 실패'];
            }
        }
        
        $file = $dir . '/icon_label_position.json';
        $ok = @file_put_contents($file, json_encode(['position' => $position], JSON_UNESCAPED_UNICODE), LOCK_EX);
        if ($ok === false) {
            return ['success' => false, 'error' => '위치 저장 실패'];
        }
        
        return ['success' => true, 'position' => $position];
    }
    
    /** 유효한 라벨 위치 값 (9개) */
    private const VALID_LABEL_POSITIONS = [
        'top-left', 'top-center', 'top-right',
        'center-left', 'center', 'center-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];
    
    /**
     * 폴더 아이콘 설정 조회
     *   user_icon_map.json의 특수 키 "__folder" 활용
     *   값 형식:
     *     - null/미존재 → 기본 노란 폴더 (인라인 SVG)
     *     - 'foldericon' → 해당 이름 아이콘 사용 (custom 우선, builtin fallback)
     *   라벨 서픽스(|label)는 폴더엔 의미 없음 → 무시
     */
    public function getFolderIcon(): ?string {
        $map = $this->getUserMap();
        if (!isset($map['__folder'])) return null;
        
        $raw = (string)$map['__folder'];
        // 폴더엔 라벨 의미 없음 → |label 서픽스 제거
        if (substr($raw, -6) === '|label') {
            $raw = substr($raw, 0, -6);
        }
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $raw);
        return $clean !== '' ? $clean : null;
    }
    
    /**
     * 폴더 아이콘 설정 저장
     *   $iconName === '' 또는 null → 기본값으로 복원 (__folder 키 제거)
     */
    public function setFolderIcon(?string $iconName): array {
        $clean = $iconName !== null ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$iconName) : '';
        
        $map = $this->getUserMap();
        if ($clean === '') {
            // 기본값으로 복원
            unset($map['__folder']);
        } else {
            // 존재하는 아이콘인지 검증
            $customPath = $this->customDir . '/' . $clean . '.svg';
            $builtinPath = $this->builtinDir . '/' . $clean . '.svg';
            if (!file_exists($customPath) && !file_exists($builtinPath)) {
                return ['success' => false, 'error' => '존재하지 않는 아이콘: ' . $clean];
            }
            $map['__folder'] = $clean;
        }
        
        // 저장
        $file = dirname(__DIR__) . '/data/user_icon_map.json';
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['success' => false, 'error' => 'data 디렉토리 생성 실패'];
        }
        $ok = @file_put_contents($file, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        if ($ok === false) {
            return ['success' => false, 'error' => '저장 실패'];
        }
        
        return ['success' => true, 'icon' => $clean ?: null];
    }
}
