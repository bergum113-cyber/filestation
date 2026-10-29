<?php
/**
 * ★ (2026-10-28, 펜닐 요청) 숨김 항목 — 윈도우 탐색기처럼 시스템 파일·사용자가 숨긴 항목을 탐색기 목록·검색에서 숨김
 *
 * 숨김으로 보는 것
 *   1) 시스템 파일 이름(대소문자 무시): Thumbs.db · ehthumbs.db · ehthumbs_vista.db · desktop.ini · $RECYCLE.BIN ·
 *      System Volume Information(윈도우) · @eaDir(시놀로지) · #recycle(시놀로지·QNAP)
 *   2) 오피스 임시 파일: 이름이 '~$' 로 시작(문서를 열면 생기는 소유자 파일 — 윈도우에서도 숨김)
 *   3) 사용자가 파일 속성에서 '숨김'으로 표시한 항목 — 웹하드 자체 표시(data/hidden_items.json), 실제 파일 속성은 안 바꿈
 *   ※ 이름이 '.' 로 시작하는 항목은 종전대로 항상 숨김(각 목록 코드에서 처리 — 여기서 바꾸지 않음)
 *
 * 숨기는 곳: 탐색기 목록(files), 검색(search_advanced), 내부 공유 폴더 목록, 공유 폴더 재생 목록
 * 그대로인 곳: 삭제·이동·복사·압축·폴더 크기(숨김 항목까지 함께 처리 — 윈도우 탐색기와 같음), WebDAV
 * '숨김 항목 보기'(사용자 설정)를 켜면 탐색기 목록·검색에 다시 보이고 흐리게 표시(item.hidden = true)
 *
 * 저장 형식(JsonDB 'hidden_items'): { "<storage_id>": { "<상대 경로>": 1, ... }, ... }
 */
class HiddenItems {
    const SYSTEM_NAMES = [
        'thumbs.db', 'ehthumbs.db', 'ehthumbs_vista.db', 'desktop.ini',
        '$recycle.bin', 'system volume information',
        '@eadir', '#recycle',
    ];
    const DB_NAME = 'hidden_items';

    /** 시스템 파일 이름 또는 오피스 임시 파일('~$…')이면 true */
    public static function isSystemName(string $name): bool {
        if ($name === '') return false;
        if (strncmp($name, '~$', 2) === 0) return true;
        $l = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        return in_array($l, self::SYSTEM_NAMES, true);
    }

    /** 경로 정규화 — 역슬래시 → '/', 앞뒤 '/' 제거, 빈 조각·'.' 제거('..' 가 있으면 빈 문자열) */
    public static function norm(string $path): string {
        $path = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $path) as $p) {
            if ($p === '' || $p === '.') continue;
            if ($p === '..') return '';
            $parts[] = $p;
        }
        return implode('/', $parts);
    }

    /** 스토리지별 숨김 경로 표 { 경로 => 1 } */
    public static function mapFor(int $storageId): array {
        if (!class_exists('JsonDB')) return [];
        $all = JsonDB::getInstance()->load(self::DB_NAME);
        $m = $all[(string)$storageId] ?? [];
        return is_array($m) ? $m : [];
    }

    /** 이 경로가 사용자 숨김인지(자기 자신만) */
    public static function isUserHidden(int $storageId, string $path, ?array $map = null): bool {
        $map = $map ?? self::mapFor($storageId);
        $p = self::norm($path);
        return $p !== '' && isset($map[$p]);
    }

    /** 자기 자신 또는 상위 폴더 중 하나라도 숨김(시스템 이름 포함)이면 true — 검색 결과 판정용 */
    public static function isHiddenPath(int $storageId, string $path, ?array $map = null): bool {
        $map = $map ?? self::mapFor($storageId);
        $p = self::norm($path);
        if ($p === '') return false;
        $acc = '';
        foreach (explode('/', $p) as $seg) {
            $acc = $acc === '' ? $seg : $acc . '/' . $seg;
            if (self::isSystemName($seg) || isset($map[$acc])) return true;
        }
        return false;
    }

    /**
     * 목록 걸러내기 — 숨김 항목은 $showHidden 이면 hidden=true 표시를 달아 남기고, 아니면 뺌
     * @param array $items  각 항목에 name, path(없으면 부모 경로 + name 으로 계산)
     */
    public static function filterItems(int $storageId, string $parentPath, array $items, bool $showHidden): array {
        $map = self::mapFor($storageId);
        $parent = self::norm($parentPath);
        $out = [];
        foreach ($items as $it) {
            $name = (string)($it['name'] ?? '');
            $p = isset($it['path']) ? self::norm((string)$it['path']) : ($parent === '' ? $name : $parent . '/' . $name);
            $hidden = self::isSystemName($name) || ($p !== '' && isset($map[$p]));
            if ($hidden) {
                if (!$showHidden) continue;
                $it['hidden'] = true;
                $it['hidden_system'] = self::isSystemName($name);   // 시스템 파일은 속성에서 숨김 해제 불가(윈도우도 이름으로 숨김)
            }
            $out[] = $it;
        }
        return $out;
    }

    /** 숨김 표시 켜기/끄기 — 경로 여러 개 */
    public static function set(int $storageId, array $paths, bool $hidden): bool {
        $paths = array_values(array_filter(array_map([self::class, 'norm'], $paths), function ($p) { return $p !== ''; }));
        if (!$paths) return false;
        return JsonDB::getInstance()->atomicUpdate(self::DB_NAME, function ($all) use ($storageId, $paths, $hidden) {
            if (!is_array($all)) $all = [];
            $k = (string)$storageId;
            $m = (isset($all[$k]) && is_array($all[$k])) ? $all[$k] : [];
            foreach ($paths as $p) {
                if ($hidden) $m[$p] = 1; else unset($m[$p]);
            }
            if ($m) $all[$k] = $m; else unset($all[$k]);
            return $all;
        });
    }

    /**
     * 이름 변경·이동 — 자기 자신과 그 아래 경로의 숨김 표시를 새 경로로 옮김
     * @param bool $replaceDest  true 면 새 경로(와 그 아래)에 남아 있던 표시를 먼저 지움 — 이동이 같은 이름을 덮어썼거나
     *                           밖에서 지워진 항목의 옛 표시가 남아 있을 때, 옮긴 항목이 그 표시를 물려받지 않게(윈도우: 덮어쓴 파일은 원본 속성)
     *   ※ (2026-10-28) 옮길 표시를 먼저 떼어 낸 뒤 대상 쪽을 지우므로, 새 경로가 원래 경로의 상위여도 옮길 표시는 안 사라짐
     */
    public static function movePath(int $srcStorageId, string $oldPath, int $dstStorageId, string $newPath, bool $replaceDest = false): void {
        $old = self::norm($oldPath); $new = self::norm($newPath);
        if ($old === '' || $new === '' || ($old === $new && $srcStorageId === $dstStorageId)) return;
        $under = function (string $p, string $base): bool { return $p === $base || strpos($p, $base . '/') === 0; };
        $hit = false;
        foreach (self::mapFor($srcStorageId) as $p => $_) { if ($under((string)$p, $old)) { $hit = true; break; } }
        if (!$hit && $replaceDest) {
            foreach (self::mapFor($dstStorageId) as $p => $_) { if ($under((string)$p, $new)) { $hit = true; break; } }
        }
        if (!$hit) return;   // 옮기거나 지울 표시가 없으면 파일을 건드리지 않음
        JsonDB::getInstance()->atomicUpdate(self::DB_NAME, function ($all) use ($srcStorageId, $dstStorageId, $old, $new, $replaceDest, $under) {
            if (!is_array($all)) $all = [];
            $sk = (string)$srcStorageId; $dk = (string)$dstStorageId;
            // 1) 옮길 표시를 떼어 냄
            $sm = (isset($all[$sk]) && is_array($all[$sk])) ? $all[$sk] : [];
            $moved = [];
            foreach ($sm as $p => $v) {
                $p = (string)$p;
                if ($under($p, $old)) { $moved[$new . substr($p, strlen($old))] = 1; unset($sm[$p]); }
            }
            if ($sm) $all[$sk] = $sm; else unset($all[$sk]);
            // 2) 대상 경로에 남아 있던 표시 지움(덮어쓰기·옛 표시)
            $dm = (isset($all[$dk]) && is_array($all[$dk])) ? $all[$dk] : [];
            if ($replaceDest) {
                foreach ($dm as $p => $v) { if ($under((string)$p, $new)) unset($dm[$p]); }
            }
            // 3) 옮긴 표시 붙임
            $dm = $moved + $dm;
            if ($dm) $all[$dk] = $dm; else unset($all[$dk]);
            return $all;
        });
    }

    /** 삭제 — 자기 자신과 그 아래 경로의 숨김 표시를 지움(같은 이름으로 새로 만든 파일이 숨겨지지 않게) */
    public static function removePath(int $storageId, string $path): void {
        $old = self::norm($path);
        if ($old === '') return;
        $m = self::mapFor($storageId);
        $hit = false;
        // ★ (2026-10-29 11:13, 20차 점검) 키를 문자열로 비교(movePath 와 같음) — PHP 는 '2024' 같은 숫자 이름 키를 정수로 바꿔 === 가 늘 거짓이라 숫자 이름 폴더의 표시가 안 지워졌음(재현)
        foreach ($m as $p => $_) { $p = (string)$p; if ($p === $old || strpos($p, $old . '/') === 0) { $hit = true; break; } }
        if (!$hit) return;
        JsonDB::getInstance()->atomicUpdate(self::DB_NAME, function ($all) use ($storageId, $old) {
            if (!is_array($all)) $all = [];
            $k = (string)$storageId;
            $m = (isset($all[$k]) && is_array($all[$k])) ? $all[$k] : [];
            foreach ($m as $p => $v) { if ((string)$p === $old || strpos((string)$p, $old . '/') === 0) unset($m[$p]); }
            if ($m) $all[$k] = $m; else unset($all[$k]);
            return $all;
        });
    }
}
