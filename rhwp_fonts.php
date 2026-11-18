<?php
require_once __DIR__ . '/php_version_check.php';
/**
 * rhwp(한글 뷰어·편집기)용 서버 글꼴 폴더
 *
 * ★ (2026-11-18 21:29, 349차 펜닐 요청 "웹 폰트가 아니라 일반 폰트 인식, 특정 경로에 넣어도 인식")
 * ★ (2026-11-18 21:53, 350차 펜닐 요청 "TTF·OTF·WOFF·WOFF2 다 지원, 폰트 위치 설정 변경, 메뉴에서 지정(편집기에서 설정하면 뷰어는 자동)")
 *   - 글꼴 폴더: 관리자가 편집기 [도구 › 글꼴 폴더 설정]에서 여러 개 지정(예 data/fonts, C:/Windows/Fonts) → data/rhwp_font_settings.json.
 *     뷰어·편집기 모두 이 목록을 씀(편집기에서 바꾸면 뷰어도 바로). 설정 파일이 없으면 기본 data/fonts(config.php 에 RHWP_FONT_PATH 가 있으면 그 경로)
 *   - ttf·otf·ttc·otc·woff·woff2 를 글꼴 '안 이름'(한글·영문 패밀리, PostScript 이름)으로 인식 — woff2 는 순수 PHP brotli(api/FsBrotli.php)로 풀어 읽음.
 *     woff2 는 파일 이름도 별칭으로 둠(349차와 같게). ttc 는 안의 글꼴마다 따로 떼어 보냄(브라우저가 ttc 를 못 읽음)
 *   - 글꼴 목록 캐시는 data/rhwp_font_index.json(글꼴 폴더 안에 쓰지 않음 — C:/Windows/Fonts 같은 곳을 더럽히지 않게). 파일 크기·시각이 같으면 다시 읽지 않음
 *   - 받기·목록은 로그인한 사람만, 설정 보기·바꾸기·다시 읽기는 관리자(또는 '시스템 설정' 권한 부관리자)만, 바꾸기는 CSRF 토큰 필요
 *   - 폴더가 없거나 비어 있으면 빈 목록 → 뷰어·편집기는 종전과 똑같이 동작
 *
 *   GET  ?action=list            → {"fonts":[{"id","names":[..],"weight","style","format"}]}
 *   GET  ?action=font&id=…       → 글꼴 파일(목록에 있는 id 만, 경로를 받지 않음)
 *   GET  ?action=settings        → 관리자: 폴더 목록·상태·글꼴 수
 *   POST ?action=settings_save   → 관리자: {"folders":[{"path","enabled"}]} 저장(켠 폴더는 검사 통과해야 — 351차, 350차 {"paths"} 도 받음)
 *   POST ?action=rescan          → 관리자: 캐시를 지우고 다시 읽기
 */

require_once __DIR__ . '/config.php';

$auth = new Auth();
if (!$auth->isLoggedIn() && !$auth->checkRememberToken()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    exit('{"fonts":[]}');
}

const FS_FONT_EXTS = ['ttf', 'otf', 'ttc', 'otc', 'woff', 'woff2'];
const FS_FONT_MAX_BYTES = 80 * 1024 * 1024;   // 파일 하나 최대 80MB
const FS_FONT_MAX_FILES = 3000;               // 폴더 전체 합계
const FS_FONT_MAX_DIRS = 10;
const FS_FONT_INDEX_VER = 2;
const FS_FONT_SCAN_BUDGET = 20.0;             // 한 번 요청에서 새 글꼴 읽기에 쓰는 최대 초(넘으면 나머지는 다음 요청에서)

function fsFontSettingsFile(): string { return DATA_PATH . '/rhwp_font_settings.json'; }
function fsFontIndexFile(): string { return DATA_PATH . '/rhwp_font_index.json'; }

function fsFontDefaultPath(): string {
    return defined('RHWP_FONT_PATH') ? (string)RHWP_FONT_PATH : 'data/fonts';
}

/** ★ (2026-11-18 22:17, 351차 펜닐 지적 "어디어디에서 폰트를 가져다 쓰고 활성화 여부도 안 나옴")
 *  설정의 폴더 목록 [['path'=>적힌 그대로, 'enabled'=>bool]] — 폴더마다 켜기/끄기(끈 폴더는 지우지 않고 남겨 둠).
 *  350차 형식({"paths":[…]})은 모두 켠 것으로 읽음. 설정 파일이 없으면 기본 폴더 하나(켬) */
function fsFontFolders(): array {
    $j = @file_get_contents(fsFontSettingsFile());
    if (is_string($j)) {
        $d = json_decode($j, true);
        if (is_array($d) && isset($d['folders']) && is_array($d['folders'])) {
            $out = [];
            foreach ($d['folders'] as $f) {
                if (!is_array($f) || !is_string($f['path'] ?? null) || trim($f['path']) === '') continue;
                $out[] = ['path' => $f['path'], 'enabled' => !empty($f['enabled'])];
            }
            return $out;
        }
        if (is_array($d) && isset($d['paths']) && is_array($d['paths'])) {
            return array_map(function ($p) { return ['path' => $p, 'enabled' => true]; }, array_values(array_filter(array_map('strval', $d['paths']), 'strlen')));
        }
    }
    return [['path' => fsFontDefaultPath(), 'enabled' => true]];
}

/** 켜 둔 폴더만(글꼴을 실제로 읽는 대상) */
function fsFontConfiguredPaths(): array {
    $out = [];
    foreach (fsFontFolders() as $f) if ($f['enabled']) $out[] = $f['path'];
    return $out;
}

/** 적힌 경로 → 실제 경로(상대 경로는 FileStation 폴더 기준). 못 쓰면 [null, 사유] */
function fsFontResolve(string $p): array {
    $p = trim(str_replace('\\', '/', $p));
    if ($p === '') return [null, 'empty'];
    if (strlen($p) > 400 || preg_match('/[\x00-\x1f\x7f]/', $p)) return [null, 'invalid'];
    $isAbs = ($p[0] === '/') || preg_match('#^[A-Za-z]:/#', $p) || strpos($p, '//') === 0;
    $full = $isAbs ? $p : (rtrim(str_replace('\\', '/', BASE_PATH), '/') . '/' . $p);
    $real = @realpath($full);
    if ($real === false) return [null, 'not_found'];
    if (!is_dir($real)) return [null, 'not_dir'];
    if (!is_readable($real)) return [null, 'not_readable'];
    return [rtrim(str_replace('\\', '/', $real), '/'), null];
}

/** 파일 핸들에서 offset 부터 len 바이트 */
function fsFontRead($fp, int $off, int $len): string {
    if ($len <= 0 || $off < 0) return '';
    if (fseek($fp, $off) !== 0) return '';
    $s = ''; while (strlen($s) < $len && !feof($fp)) { $c = fread($fp, $len - strlen($s)); if ($c === false || $c === '') break; $s .= $c; }
    return $s;
}

/** sfnt 표 목록 [tag => [offset, length, checksum]] (offset = 파일 안 위치) */
function fsFontSfntTables($fp, int $base): array {
    $h = fsFontRead($fp, $base, 12);
    if (strlen($h) < 12) return [];
    $n = unpack('n', substr($h, 4, 2))[1];
    if ($n < 1 || $n > 200) return [];
    $dir = fsFontRead($fp, $base + 12, 16 * $n);
    if (strlen($dir) < 16 * $n) return [];
    $t = [];
    for ($i = 0; $i < $n; $i++) {
        $r = substr($dir, 16 * $i, 16);
        $t[substr($r, 0, 4)] = [unpack('N', substr($r, 8, 4))[1], unpack('N', substr($r, 12, 4))[1], unpack('N', substr($r, 4, 4))[1]];
    }
    return $t;
}

function fsFontCleanName(string $s): string {
    return trim(preg_replace('/[\x00-\x1f\x7f"\'\\\\<>]/u', '', $s));
}

/** name 표에서 패밀리 이름들 + 굵기·기울임 */
function fsFontParseNames(string $name, string $os2): array {
    $names = []; $full = []; $weight = 400; $italic = false;
    if (strlen($os2) >= 64) {
        $w = unpack('n', substr($os2, 4, 2))[1];
        if ($w >= 1 && $w <= 1000) $weight = $w;
        $sel = unpack('n', substr($os2, 62, 2))[1];
        $italic = (bool)($sel & 1);
    }
    if (strlen($name) >= 6) {
        $cnt = unpack('n', substr($name, 2, 2))[1];
        $soff = unpack('n', substr($name, 4, 2))[1];
        for ($i = 0; $i < $cnt && $i < 1000; $i++) {
            $r = substr($name, 6 + 12 * $i, 12);
            if (strlen($r) < 12) break;
            $v = unpack('npid/neid/nlid/nnid/nlen/noff', $r);
            if (!in_array($v['nid'], [1, 4, 6, 16], true)) continue;
            $raw = substr($name, $soff + $v['off'], $v['len']);
            if ($raw === '' || strlen($raw) !== $v['len']) continue;
            if ($v['pid'] === 3 || $v['pid'] === 0) $s = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
            elseif ($v['pid'] === 1 && $v['eid'] === 0 && !preg_match('/[\x80-\xff]/', $raw)) $s = $raw;
            else continue;
            $s = fsFontCleanName((string)$s);
            if ($s === '' || mb_strlen($s) > 100) continue;
            if ($v['nid'] === 4) $full[$s] = true; else $names[$s] = true;
        }
    }
    // 전체 이름(예 'HY견명조', 'Malgun Gothic Bold')은 보통 굵기·기울임이 아닌 글꼴만 패밀리처럼 씀
    if ($weight === 400 && !$italic) foreach ($full as $s => $_) $names[$s] = true;
    return ['names' => array_keys($names), 'weight' => $weight, 'style' => $italic ? 'italic' : 'normal'];
}

/** WOFF2 의 UIntBase128 */
function fsFontB128(string $b, int &$i): int {
    $v = 0;
    for ($k = 0; $k < 5; $k++) {
        if ($i >= strlen($b)) return -1;
        $x = ord($b[$i++]);
        if ($k === 0 && $x === 0x80) return -1;
        if ($v & 0xFE000000) return -1;
        $v = ($v << 7) | ($x & 0x7f);
        if (!($x & 0x80)) return $v;
    }
    return -1;
}

/** WOFF2 → name·OS/2 표(풀어서). 모음(ttcf)·깨진 파일은 null */
function fsFontWoff2Tables(string $path): ?array {
    static $known = ['cmap','head','hhea','hmtx','maxp','name','OS/2','post','cvt ','fpgm','glyf','loca','prep','CFF ','VORG','EBDT','EBLC','gasp','hdmx','kern','LTSH','PCLT','VDMX','vhea','vmtx','BASE','GDEF','GPOS','GSUB','EBSC','JSTF','MATH','CBDT','CBLC','COLR','CPAL','SVG ','sbix','acnt','avar','bdat','bloc','bsln','cvar','fdsc','feat','fmtx','fvar','gvar','hsty','just','lcar','mort','morx','opbd','prop','trak','Zapf','Silf','Glat','Gloc','Feat','Sill'];
    $b = @file_get_contents($path);
    if (!is_string($b) || strlen($b) < 48 || substr($b, 0, 4) !== 'wOF2') return null;
    $flavor = substr($b, 4, 4);
    if ($flavor === 'ttcf') return null;
    $num = unpack('n', substr($b, 12, 2))[1];
    $compLen = unpack('N', substr($b, 20, 4))[1];
    if ($num < 1 || $num > 200) return null;
    $i = 48; $off = 0; $want = [];
    for ($t = 0; $t < $num; $t++) {
        if ($i >= strlen($b)) return null;
        $fl = ord($b[$i++]);
        $ti = $fl & 0x3f; $ver = $fl >> 6;
        if ($ti === 63) { $tag = substr($b, $i, 4); $i += 4; } else { $tag = $known[$ti] ?? null; }
        if ($tag === null) return null;
        $orig = fsFontB128($b, $i);
        if ($orig < 0) return null;
        $len = $orig;
        $transformed = in_array($tag, ['glyf', 'loca'], true) ? ($ver === 0) : ($ver !== 0);
        if ($transformed) { $len = fsFontB128($b, $i); if ($len < 0) return null; }
        if ($tag === 'name' || $tag === 'OS/2') $want[$tag] = [$off, $len];
        $off += $len;
    }
    if (!isset($want['name'])) return null;
    $stop = 0; foreach ($want as [$o, $l]) $stop = max($stop, $o + $l);
    try {
        $raw = FsBrotli::decompress(substr($b, $i, $compLen), $stop, max($stop, 1) + 64 * 1024 * 1024);
    } catch (Throwable $e) { return null; }
    $out = [];
    foreach ($want as $tag => [$o, $l]) $out[$tag] = (string)substr($raw, $o, $l);
    return $out;
}

/** 파일 하나 → 글꼴 목록(ttc 는 여러 개) */
function fsFontScanFile(string $path, string $ext): array {
    $fp = @fopen($path, 'rb');
    if (!$fp) return [];
    $out = [];
    try {
        $sig = fsFontRead($fp, 0, 4);
        if ($sig === 'wOF2') {
            fclose($fp); $fp = null;
            $tb = fsFontWoff2Tables($path);
            $p = $tb ? fsFontParseNames($tb['name'] ?? '', $tb['OS/2'] ?? '') : ['names' => [], 'weight' => 400, 'style' => 'normal'];
            $out[] = ['face' => 0] + $p + ['format' => 'woff2', 'cff' => false];
        } elseif ($sig === 'wOFF') {
            $h = fsFontRead($fp, 0, 44);
            $flavor = substr($h, 4, 4);
            $n = unpack('n', substr($h, 12, 2))[1];
            $dir = fsFontRead($fp, 44, 20 * min($n, 200));
            $get = function ($tag) use ($fp, $dir, $n) {
                for ($i = 0; $i < $n; $i++) {
                    $r = substr($dir, 20 * $i, 20);
                    if (strlen($r) < 20 || substr($r, 0, 4) !== $tag) continue;
                    $o = unpack('N', substr($r, 4, 4))[1]; $cl = unpack('N', substr($r, 8, 4))[1]; $ol = unpack('N', substr($r, 12, 4))[1];
                    if ($cl > 4 * 1024 * 1024) return '';
                    $d = fsFontRead($fp, $o, $cl);
                    if ($cl < $ol) { $d = @gzuncompress($d); if (!is_string($d)) return ''; }
                    return $d;
                }
                return '';
            };
            $p = fsFontParseNames($get('name'), $get('OS/2'));
            $out[] = ['face' => 0] + $p + ['format' => 'woff', 'cff' => $flavor === 'OTTO'];
        } else {
            $bases = [0];
            if ($sig === 'ttcf') {
                $h = fsFontRead($fp, 0, 12);
                $nf = strlen($h) === 12 ? unpack('N', substr($h, 8, 4))[1] : 0;
                $nf = min($nf, 64);
                $offs = fsFontRead($fp, 12, 4 * $nf);
                $bases = [];
                for ($i = 0; $i < $nf; $i++) if (strlen($offs) >= 4 * ($i + 1)) $bases[] = unpack('N', substr($offs, 4 * $i, 4))[1];
            } elseif (!in_array($sig, ["\x00\x01\x00\x00", 'OTTO', 'true'], true)) {
                return [];
            }
            foreach ($bases as $fi => $b) {
                $t = fsFontSfntTables($fp, $b);
                if (!$t) continue;
                $nm = isset($t['name']) && $t['name'][1] < 4 * 1024 * 1024 ? fsFontRead($fp, $t['name'][0], $t['name'][1]) : '';
                $os = isset($t['OS/2']) && $t['OS/2'][1] < 65536 ? fsFontRead($fp, $t['OS/2'][0], $t['OS/2'][1]) : '';
                $p = fsFontParseNames($nm, $os);
                $cff = isset($t['CFF ']) || isset($t['CFF2']);
                $out[] = ['face' => $sig === 'ttcf' ? $fi : 0] + $p + ['format' => $cff ? 'opentype' : 'truetype', 'cff' => $cff, 'ttc' => $sig === 'ttcf'];
            }
        }
    } finally { if ($fp) fclose($fp); }
    return $out;
}

/**
 * 모든 글꼴 폴더의 목록. $status 에 폴더별 상태를 채움.
 * 캐시: data/rhwp_font_index.json — 폴더(실제 경로)·파일(상대 경로)별 크기·시각이 같으면 다시 읽지 않음
 */
function fsFontIndex(?array &$status = null, bool $fresh = false): array {
    $status = [];
    $cache = [];
    if (!$fresh) {
        $cj = @file_get_contents(fsFontIndexFile());
        if (is_string($cj)) { $c = json_decode($cj, true); if (is_array($c) && ($c['v'] ?? 0) === FS_FONT_INDEX_VER && is_array($c['dirs'] ?? null)) $cache = $c['dirs']; }
    }
    $newCache = []; $changed = $fresh; $count = 0; $t0 = microtime(true); $deferred = 0;
    $seenReal = [];
    foreach (array_slice(fsFontConfiguredPaths(), 0, FS_FONT_MAX_DIRS) as $raw) {
        [$real, $err] = fsFontResolve($raw);
        $st = ['path' => $raw, 'ok' => $real !== null, 'error' => $err, 'files' => 0, 'faces' => 0];
        if ($real === null) { $status[] = $st; continue; }
        if (isset($seenReal[$real])) { $st['ok'] = false; $st['error'] = 'duplicate'; $status[] = $st; continue; }
        $seenReal[$real] = true;
        $old = $cache[$real] ?? [];
        $files = [];
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
            $it->setMaxDepth(3);
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $ext = strtolower($f->getExtension());
                if (!in_array($ext, FS_FONT_EXTS, true)) continue;
                $p = str_replace('\\', '/', $f->getPathname());
                $rp = @realpath($p);
                if ($rp === false || strpos(str_replace('\\', '/', $rp) . '/', $real . '/') !== 0) continue;   // 폴더 밖을 가리키는 바로가기는 건너뜀
                $size = (int)$f->getSize();
                if ($size <= 0 || $size > FS_FONT_MAX_BYTES) continue;
                if (++$count > FS_FONT_MAX_FILES) break 2;
                $rel = substr($p, strlen($real) + 1);
                $mt = (int)$f->getMTime();
                if (isset($old[$rel]) && ($old[$rel]['s'] ?? -1) === $size && ($old[$rel]['m'] ?? -1) === $mt && is_array($old[$rel]['faces'] ?? null)) {
                    $files[$rel] = $old[$rel];
                    continue;
                }
                if (microtime(true) - $t0 > FS_FONT_SCAN_BUDGET) { $deferred++; continue; }   // 시간 넘으면 다음 요청에서
                $files[$rel] = ['s' => $size, 'm' => $mt, 'faces' => fsFontScanFile($p, $ext)];
                $changed = true;
            }
        } catch (Throwable $e) { $st['ok'] = false; $st['error'] = 'not_readable'; }
        if (count($files) !== count($old)) $changed = true;
        $newCache[$real] = $files;
        $st['files'] = count($files);
        foreach ($files as $info) $st['faces'] += count($info['faces']);
        $status[] = $st;
    }
    if ($deferred) $status[] = ['path' => '', 'ok' => true, 'error' => 'deferred', 'files' => $deferred, 'faces' => 0];
    if ($changed || count($newCache) !== count($cache)) {
        @file_put_contents(fsFontIndexFile(), json_encode(['v' => FS_FONT_INDEX_VER, 'dirs' => $newCache], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    $list = [];
    foreach ($newCache as $real => $files) {
        foreach ($files as $rel => $info) {
            $stem = fsFontCleanName(pathinfo($rel, PATHINFO_FILENAME));
            foreach ($info['faces'] as $fc) {
                $names = $fc['names'];
                if (!$names || ($fc['format'] ?? '') === 'woff2') $names[] = $stem;   // 이름을 못 읽은 글꼴, woff2(349차와 같게)는 파일 이름도
                $names = array_values(array_unique(array_filter(array_map('fsFontCleanName', $names), 'strlen')));
                if (!$names) continue;
                $list[] = [
                    'id' => substr(sha1($real . '|' . $rel . '#' . (int)$fc['face'] . '#' . $info['s'] . '#' . $info['m']), 0, 20),
                    'path' => $real . '/' . $rel, 'face' => (int)$fc['face'], 'ttc' => !empty($fc['ttc']),
                    'names' => $names, 'weight' => (int)$fc['weight'], 'style' => $fc['style'] === 'italic' ? 'italic' : 'normal',
                    'format' => $fc['format'], 'cff' => !empty($fc['cff']),
                ];
            }
        }
    }
    return $list;
}

/** ttc 안 글꼴 하나를 독립 sfnt 로 */
function fsFontExtractTtcFace(string $path, int $face): ?string {
    $fp = @fopen($path, 'rb');
    if (!$fp) return null;
    try {
        $h = fsFontRead($fp, 0, 12);
        if (substr($h, 0, 4) !== 'ttcf') return null;
        $nf = unpack('N', substr($h, 8, 4))[1];
        if ($face < 0 || $face >= $nf) return null;
        $base = unpack('N', fsFontRead($fp, 12 + 4 * $face, 4))[1];
        $hdr = fsFontRead($fp, $base, 12);
        $t = fsFontSfntTables($fp, $base);
        if (!$t) return null;
        $n = count($t);
        $out = substr($hdr, 0, 4) . pack('n', $n) . substr($hdr, 6, 6);
        $off = 12 + 16 * $n;
        $dirs = ''; $data = '';
        ksort($t, SORT_STRING);
        foreach ($t as $tag => [$o, $len, $sum]) {
            $d = fsFontRead($fp, $o, $len);
            if (strlen($d) !== $len) return null;
            $dirs .= $tag . pack('NNN', $sum, $off + strlen($data), $len);
            $data .= $d . str_repeat("\0", (4 - ($len % 4)) % 4);
        }
        return $out . $dirs . $data;
    } finally { fclose($fp); }
}

function fsFontCanManage(Auth $auth): bool {
    return $auth->isAdmin() || ($auth->isSubAdmin() && $auth->hasAdminPerm('system_settings'));
}

/** 관리자 화면용 상태 — ★ (2026-11-18 22:17, 351차 펜닐 지적 "어디어디에서 폰트를 가져다 쓰고 활성화 여부도 안 나옴")
 *  폴더마다 켜짐/꺼짐·상태·글꼴 수 + 다른 출처(rhwp 기본 글꼴 파일 수, 인터넷 글꼴)도 함께 */
function fsFontSettingsPayload(bool $fresh = false): array {
    $status = [];
    $list = fsFontIndex($status, $fresh);
    $names = [];
    foreach ($list as $f) foreach ($f['names'] as $n) $names[$n] = true;
    $nameList = array_keys($names);
    sort($nameList, SORT_STRING);
    $byPath = [];
    foreach ($status as $st) if ($st['path'] !== '' && !isset($byPath[$st['path']])) $byPath[$st['path']] = $st;
    $folders = [];
    foreach (fsFontFolders() as $f) {
        if (!$f['enabled']) { $folders[] = ['path' => $f['path'], 'enabled' => false, 'ok' => null, 'error' => null, 'files' => 0, 'faces' => 0]; continue; }
        $st = $byPath[$f['path']] ?? ['ok' => false, 'error' => 'not_found', 'files' => 0, 'faces' => 0];
        $folders[] = ['path' => $f['path'], 'enabled' => true, 'ok' => $st['ok'], 'error' => $st['error'], 'files' => $st['files'], 'faces' => $st['faces']];
    }
    $deferred = 0;
    foreach ($status as $st) if (($st['error'] ?? '') === 'deferred') $deferred += (int)$st['files'];
    $builtin = 0;
    foreach (['woff2', 'woff', 'ttf', 'otf'] as $e) $builtin += count(glob(__DIR__ . '/assets/rhwp/studio/fonts/*.' . $e) ?: []);
    return [
        'success' => true,
        'folders' => $folders,
        'paths' => fsFontConfiguredPaths(),
        'default' => fsFontDefaultPath(),
        'status' => $status,
        'deferred' => $deferred,
        'faces' => count($list),
        'names' => array_slice($nameList, 0, 500),
        'names_total' => count($nameList),
        'builtin' => $builtin,
        'cdn' => ['함초롬돋움', '함초롬바탕'],
    ];
}

$action = (string)($_GET['action'] ?? 'list');
$isWrite = in_array($action, ['settings_save', 'rescan'], true);
if (!$isWrite && session_status() === PHP_SESSION_ACTIVE) session_write_close();   // 글꼴 여러 개를 동시에 받을 때 세션 잠금으로 줄 서지 않게

if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-cache');
    $pub = array_map(function ($f) { return ['id' => $f['id'], 'names' => $f['names'], 'weight' => $f['weight'], 'style' => $f['style'], 'format' => $f['format']]; }, fsFontIndex());
    echo json_encode(['fonts' => $pub], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'font') {
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{20}$/', $id)) { http_response_code(400); exit; }
    $hit = null;
    foreach (fsFontIndex() as $f) { if ($f['id'] === $id) { $hit = $f; break; } }
    if (!$hit || !is_file($hit['path'])) { http_response_code(404); exit; }
    $etag = '"' . $id . '"';
    header('Cache-Control: private, max-age=604800');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
    $types = ['truetype' => 'font/ttf', 'opentype' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];
    header('Content-Type: ' . ($types[$hit['format']] ?? 'application/octet-stream'));
    if ($hit['ttc']) {
        $bin = fsFontExtractTtcFace($hit['path'], $hit['face']);
        if ($bin === null) { http_response_code(500); exit; }
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
    header('Content-Length: ' . filesize($hit['path']));
    readfile($hit['path']);
    exit;
}

if ($action === 'settings' || $isWrite) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-cache');
    if (!fsFontCanManage($auth)) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'forbidden']); exit; }
    if ($isWrite) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'method']); exit; }
        if (!validateCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'csrf', 'csrf_error' => true]); exit; }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    }
    if ($action === 'settings_save') {
        // ★ (2026-11-18 22:17, 351차 펜닐 지적 "어디어디에서 폰트를 가져다 쓰고 활성화 여부도 안 나옴")
        //   {"folders":[{"path","enabled"}]} — 켠 폴더는 실제로 있어야 저장, 끈 폴더는 글자만 검사(없는 드라이브 등도 꺼 둔 채 남길 수 있게). 350차 {"paths":[…]} 도 받음
        $in = json_decode((string)file_get_contents('php://input'), true);
        $rows = null;
        if (is_array($in) && is_array($in['folders'] ?? null)) $rows = $in['folders'];
        elseif (is_array($in) && is_array($in['paths'] ?? null)) $rows = array_map(function ($p) { return ['path' => $p, 'enabled' => true]; }, $in['paths']);
        if ($rows === null) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'bad_request']); exit; }
        $clean = []; $errors = []; $seenReal = []; $seenRaw = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !is_string($r['path'] ?? null)) continue;
            $p = trim(str_replace('\\', '/', $r['path']));
            if ($p === '') continue;
            $en = !empty($r['enabled']);
            if (strlen($p) > 400 || preg_match('/[\x00-\x1f\x7f]/', $p)) { $errors[] = ['path' => $p, 'error' => 'invalid']; continue; }
            if (isset($seenRaw[$p])) continue;
            if ($en) {
                [$real, $err] = fsFontResolve($p);
                if ($real === null) { $errors[] = ['path' => $p, 'error' => $err]; continue; }
                if (isset($seenReal[$real])) continue;
                $seenReal[$real] = true;
            }
            $seenRaw[$p] = true;
            $clean[] = ['path' => $p, 'enabled' => $en];
        }
        if (count($clean) > FS_FONT_MAX_DIRS) $errors[] = ['path' => '', 'error' => 'too_many'];
        if ($errors) { echo json_encode(['success' => false, 'error' => 'invalid_paths', 'errors' => $errors], JSON_UNESCAPED_UNICODE); exit; }
        $enabledPaths = []; foreach ($clean as $c) if ($c['enabled']) $enabledPaths[] = $c['path'];
        $data = ['folders' => $clean, 'paths' => $enabledPaths, 'updated_at' => date('c'), 'updated_by' => (string)($auth->getUser()['username'] ?? '')];
        if (@file_put_contents(fsFontSettingsFile(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            echo json_encode(['success' => false, 'error' => 'write_failed']); exit;
        }
        echo json_encode(fsFontSettingsPayload(), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'rescan') { @unlink(fsFontIndexFile()); echo json_encode(fsFontSettingsPayload(true), JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(fsFontSettingsPayload(), JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
