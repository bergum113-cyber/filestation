<?php
/**
 * Mp4KeyIndex — mp4 목차(moov)에서 영상 키프레임 위치와 코덱을 읽는다 (2026-09-30, 원본 스트리밍용)
 *
 * - 파일은 읽기 전용으로 열고, 최상위 상자 머리글만 따라가 moov 하나만 읽는다(영상 데이터 mdat 은 읽지 않음). 파일을 바꾸지 않는다.
 * - 키프레임 표시 시각(pts)은 ffmpeg(mov 디먹서)와 같게 계산: pts = dts + ctts - elst.media_time (+ 앞쪽 빈 편집 구간).
 *   조각 끝은 다음 키프레임의 디코딩 시각(dts)으로 자른다 — ffmpeg -to 가 dts 기준이라, pts 로 자르면 B프레임 때문에
 *   다음 키프레임과 그 뒤 프레임이 앞 조각에 딸려 들어가 겹친다(시험으로 확인).
 * - 긴 영상도 프레임마다 배열을 만들지 않고 키프레임 번호만 따라가며 계산한다(3시간 60fps ≈ 65만 프레임).
 * - 손상·비정상 파일은 예외 없이 null. 조각 파일(moof)·영상 트랙 없음·moov 가 너무 큼도 null.
 * - 시험: ffprobe 정답과 키프레임 개수·시각 일치(규칙 4초 / 불규칙·B프레임 3개·29.97fps, 목차 앞·뒤 모두).
 */
class Mp4KeyIndex {
    /** @return array|null ['duration'=>초, 'keyPts'=>[초..], 'keyDts'=>[초..], 'vcodec'=>'avc1'.., 'acodec'=>'mp4a'|''|.., 'frames'=>n] */
    public static function read(string $path, int $maxMoov = 96 * 1048576): ?array {
        $fp = @fopen($path, 'rb');
        if (!$fp) return null;
        $size = @filesize($path);
        if (!$size) { fclose($fp); return null; }
        $off = 0; $moov = null; $guard = 0;
        while ($off + 8 <= $size && $guard++ < 100000) {
            if (fseek($fp, $off) !== 0) break;
            $h = fread($fp, 16);
            if ($h === false || strlen($h) < 8) break;
            $bs = self::u32($h, 0); $type = substr($h, 4, 4); $hl = 8;
            if ($bs === 1) { if (strlen($h) < 16) break; $bs = self::u64($h, 8); $hl = 16; }
            elseif ($bs === 0) { $bs = $size - $off; }
            if ($bs < $hl || $off + $bs > $size) break;
            if ($type === 'moof') { fclose($fp); return null; }                 // 조각 mp4 — 목차에 표본이 없음
            if ($type === 'moov') {
                if ($bs - $hl > $maxMoov) { fclose($fp); return null; }
                fseek($fp, $off + $hl);
                $moov = self::readAll($fp, $bs - $hl);
                break;
            }
            $off += $bs;
        }
        fclose($fp);
        if ($moov === null || $moov === '') return null;
        try { return self::parseMoov($moov); } catch (\Throwable $e) { return null; }
    }

    private static function parseMoov(string $moov): ?array {
        $mvTs = 1000;
        $mvhd = self::child($moov, 'mvhd');
        if ($mvhd !== null) { $t = self::mdhdTimescale($mvhd); if ($t > 0) $mvTs = $t; }
        $video = null; $acodec = ''; $acodecs = [];   // ★ (2026-09-30) 음성 트랙 코덱 전부(순서 = ffmpeg 0:a:N)
        foreach (self::boxes($moov) as [$t, $trak]) {
            if ($t !== 'trak') continue;
            $mdia = self::child($trak, 'mdia'); if ($mdia === null) continue;
            $hdlr = self::child($mdia, 'hdlr'); if ($hdlr === null || strlen($hdlr) < 12) continue;
            $kind = substr($hdlr, 8, 4);
            $minf = self::child($mdia, 'minf'); $stbl = $minf !== null ? self::child($minf, 'stbl') : null;
            if ($stbl === null) continue;
            $codec = self::sampleEntry($stbl);
            if ($kind === 'soun') { if ($acodec === '') $acodec = $codec; $acodecs[] = $codec; }
            if ($kind === 'vide' && $video === null) $video = [$trak, $mdia, $stbl, $codec];
        }
        if ($video === null) return null;
        [$trak, $mdia, $stbl, $vcodec] = $video;
        $mdhd = self::child($mdia, 'mdhd'); $ts = $mdhd ? self::mdhdTimescale($mdhd) : 0;
        if ($ts <= 0) return null;
        $stts = self::child($stbl, 'stts'); if ($stts === null || strlen($stts) < 8) return null;
        $stss = self::child($stbl, 'stss'); $ctts = self::child($stbl, 'ctts');

        // 편집 목록: 앞쪽 빈 구간(media_time -1)은 표시 지연, 첫 실제 구간의 media_time 만큼 앞으로 당김
        $shift = 0.0; $mediaTime = 0;
        $edts = self::child($trak, 'edts'); $elst = $edts ? self::child($edts, 'elst') : null;
        if ($elst !== null && strlen($elst) >= 8) {
            $v = ord($elst[0]); $n = self::u32($elst, 4); $p = 8; $es = ($v === 1) ? 20 : 12;
            for ($i = 0; $i < $n && $p + $es <= strlen($elst); $i++, $p += $es) {
                if ($v === 1) { $segDur = self::u64($elst, $p); $mt = self::s64($elst, $p + 8); }
                else { $segDur = self::u32($elst, $p); $mt = self::s32($elst, $p + 4); }
                if ($mt === -1) { $shift += $segDur / $mvTs; continue; }
                $mediaTime = $mt; break;
            }
        }

        // 전체 프레임 수·길이
        $sttsN = self::u32($stts, 4); if (8 + $sttsN * 8 > strlen($stts)) return null;
        $total = 0; $durTicks = 0;
        for ($i = 0, $p = 8; $i < $sttsN; $i++, $p += 8) { $c = self::u32($stts, $p); $total += $c; $durTicks += $c * self::u32($stts, $p + 4); }
        if ($total <= 0) return null;

        // 키프레임 번호(0부터, 오름차순). stss 가 없으면 모든 프레임이 키프레임 — 조각이 너무 잘아지지 않게 계획 쪽에서 묶는다
        $keys = [];
        if ($stss !== null && strlen($stss) >= 8) {
            $n = self::u32($stss, 4); if (8 + $n * 4 > strlen($stss)) return null;
            for ($i = 0, $p = 8; $i < $n; $i++, $p += 4) { $k = self::u32($stss, $p) - 1; if ($k >= 0 && $k < $total) $keys[] = $k; }
            sort($keys);
        } else {
            $step = max(1, (int)round($ts > 0 ? $total / max(1, $durTicks / $ts) : 1));   // 약 1초 간격 표본만(모두 키프레임인 경우)
            for ($k = 0; $k < $total; $k += $step) $keys[] = $k;
        }
        if (!$keys) return null;

        // 키프레임마다 dts: stts 를 따라가며 해당 번호에서 멈춤(프레임별 배열 없음)
        $keyDtsTicks = []; $ei = 0; $p = 8; $cnt = self::u32($stts, 8); $delta = self::u32($stts, 12); $base = 0; $firstIdx = 0;
        foreach ($keys as $k) {
            while ($ei < $sttsN && $k >= $firstIdx + $cnt) { $base += $cnt * $delta; $firstIdx += $cnt; $ei++; $p += 8;
                if ($ei < $sttsN) { $cnt = self::u32($stts, $p); $delta = self::u32($stts, $p + 4); } }
            $keyDtsTicks[] = $base + ($k - $firstIdx) * $delta;
        }
        // 키프레임마다 ctts(표시 순서 보정)
        $keyCtts = array_fill(0, count($keys), 0);
        if ($ctts !== null && strlen($ctts) >= 8) {
            $cv = ord($ctts[0]); $n = self::u32($ctts, 4);
            if (8 + $n * 8 <= strlen($ctts)) {
                $ci = 0; $p = 8; $firstIdx = 0; $c = $n ? self::u32($ctts, 8) : 0;
                foreach ($keys as $j => $k) {
                    while ($ci < $n && $k >= $firstIdx + $c) { $firstIdx += $c; $ci++; $p += 8; if ($ci < $n) $c = self::u32($ctts, $p); }
                    if ($ci < $n) { $raw = self::u32($ctts, $p + 4); $keyCtts[$j] = ($cv === 1 || $raw > 0x7fffffff) ? self::s32($ctts, $p + 4) : $raw; }
                }
            }
        }
        $keyPts = []; $keyDts = [];
        foreach ($keys as $j => $k) {
            $keyPts[] = ($keyDtsTicks[$j] + $keyCtts[$j] - $mediaTime) / $ts + $shift;
            $keyDts[] = ($keyDtsTicks[$j] - $mediaTime) / $ts + $shift;
        }
        array_multisort($keyPts, $keyDts);
        return ['duration' => $durTicks / $ts, 'keyPts' => $keyPts, 'keyDts' => $keyDts,
                'vcodec' => $vcodec, 'acodec' => $acodec, 'acodecs' => $acodecs, 'frames' => $total];
    }

    /** 키프레임 경계로 약 $target 초 이상씩 묶은 조각 계획: [[시작pts, 끝pts(목록 길이용), 끊는 시각(다음 키프레임 dts)], ...] */
    public static function plan(array $idx, float $target = 4.0): array {
        $k = $idx['keyPts']; $kd = $idx['keyDts']; $dur = (float)$idx['duration']; $n = count($k); $segs = []; $i = 0;
        while ($i < $n) {
            $s = $k[$i]; $j = $i + 1;
            while ($j < $n && $k[$j] - $s < $target) $j++;
            $e = ($j < $n) ? $k[$j] : max($dur, $s);
            $cut = ($j < $n) ? $kd[$j] : $dur + 1;
            if ($e - $s > 0.01) $segs[] = [$s, $e, $cut];
            $i = $j;
        }
        return $segs;
    }

    /**
     * ★ (2026-09-30) HEVC 원본 스트리밍용 — ffmpeg 가 조각마다 따로 만든 조각 mp4(frag_discont)를 초기화 부분(ftyp+moov)과
     *   조각 부분(moof+mdat)으로 나누고, 각 트랙 조각의 시작 시각(tfdt)을 바로잡는다.
     *   ffmpeg 는 tfdt 에 첫 표본의 표시 시각(pts)을 적고 표본별 표시 차이(ctts)도 그대로 둬 B프레임만큼 밀리므로(조각마다 다름)
     *   '적힌 값 - 첫 표본 ctts' = 실제 해석 시각(dts). 음성은 ctts 가 없어 적힌 값 그대로(첫 조각은 AAC 여유분으로 음수일 수 있음).
     *   모든 트랙에 $off 초를 더해 음수를 없앤다(TS 쪽 -output_ts_offset 과 같은 뜻 — 재생기는 첫 조각 기준으로 맞춤).
     *   검증: 조각을 따로 만들어 이어 붙인 결과의 모든 영상·음성 표시 시각이 원본과 틱 단위로 같음(HEVC hvc1/hev1, B프레임 H.264).
     *   크기는 바꾸지 않으므로(tfdt 제자리 덮어쓰기) trun 의 자료 위치는 그대로. 구조가 이상하면 null(잘못된 조각을 보내지 않게).
     *   ★ (2026-09-30) 메모리: 조각을 이어 붙이면 복사본으로 조각의 약 3배를 썼다(36MB 조각 → 110MB 실측). 그래서 이어 붙이지 않고
     *   '보낼 조각 목록'을 돌려준다 — 고친 moof 는 새 문자열(작음), mdat 은 원래 버퍼의 [위치, 길이]만. 보내는 쪽이 목록대로 잘라 보낸다.
     *   @return array|null ['init' => ftyp+moov, 'parts' => [['s', 문자열] | ['r', 위치, 길이], ...], 'fragLen' => 보낼 전체 길이]
     */
    public static function fmp4Split(callable $get, int $len, float $off): ?array {
        // ★ (2026-09-30) 입력은 '위치·길이로 읽어 주는 함수'($get) — 받은 자료를 한 문자열로 이어 붙이면 늘릴 때마다 순간 두 벌이 필요해
        //   memory_limit 에 먼저 걸렸다(48M 에서 22MB 조각 치명 오류 실측). 목차·머리처럼 작은 상자만 꺼내 해석하고 mdat 은 [위치, 길이]만.
        try {
            $top = [];
            for ($o = 0; $o + 8 <= $len; ) {                       // 맨 위 상자 목록
                $h = $get($o, min(16, $len - $o));
                $bs = self::u32($h, 0); $t = substr($h, 4, 4); $hl = 8;
                if ($bs === 1) { if (strlen($h) < 16) break; $bs = self::u64($h, 8); $hl = 16; } elseif ($bs === 0) { $bs = $len - $o; }
                if ($bs < $hl || $o + $bs > $len) break;
                $top[] = [$t, $o, $hl, $bs]; $o += $bs;
            }
            $ts = [];
            foreach ($top as [$t, $o, $hl, $bs]) {
                if ($t !== 'moov') continue;
                $d = $get($o, $bs);                                  // 목차는 작다(수 KB)
                foreach (self::walk($d, $hl, $bs) as [$tt, $to, $th, $tsz]) {
                    if ($tt !== 'trak') continue;
                    $tk = self::find($d, $to + $th, $to + $tsz, 'tkhd'); $md = self::find($d, $to + $th, $to + $tsz, 'mdia');
                    $mh = $md ? self::find($d, $md[0] + $md[1], $md[2], 'mdhd') : null;
                    if (!$tk || !$mh) return null;
                    $tid = self::u32($d, $tk[0] + $tk[1] + (ord($d[$tk[0] + $tk[1]]) === 1 ? 20 : 12));
                    $tsc = self::u32($d, $mh[0] + $mh[1] + (ord($d[$mh[0] + $mh[1]]) === 1 ? 20 : 12));
                    if ($tsc <= 0) return null;
                    $ts[$tid] = $tsc;
                }
            }
            if (!$ts) return null;
            $init = ''; $parts = []; $fragLen = 0;
            foreach ($top as [$t, $o, $hl, $bs]) {
                if ($t === 'ftyp' || $t === 'moov') { $init .= $get($o, $bs); continue; }
                if ($t === 'mdat') { $parts[] = ['r', $o, $bs]; $fragLen += $bs; continue; }
                if ($t !== 'moof') continue;
                $b = $get($o, $bs);                                  // 조각 머리도 작다
                foreach (self::walk($b, $hl, $bs) as [$tt, $to, $th, $tsz]) {
                    if ($tt !== 'traf') continue;
                    $hd = self::find($b, $to + $th, $to + $tsz, 'tfhd'); $fd = self::find($b, $to + $th, $to + $tsz, 'tfdt'); $tr = self::find($b, $to + $th, $to + $tsz, 'trun');
                    if (!$hd || !$fd) return null;
                    $tid = self::u32($b, $hd[0] + $hd[1] + 4);
                    if (!isset($ts[$tid])) return null;
                    $p = $fd[0] + $fd[1]; $v = ord($b[$p]);
                    $raw = ($v === 1) ? self::u64($b, $p + 4) : self::u32($b, $p + 4);   // u64 은 음수(0xFFFF...)를 음수로 돌려준다
                    $c0 = 0;
                    if ($tr) {
                        $q = $tr[0] + $tr[1]; $tv = ord($b[$q]); $fl = (ord($b[$q + 1]) << 16) | (ord($b[$q + 2]) << 8) | ord($b[$q + 3]);
                        $n = self::u32($b, $q + 4); $r = $q + 8;
                        if ($fl & 0x1) $r += 4; if ($fl & 0x4) $r += 4;
                        if ($n > 0 && ($fl & 0x800)) {
                            if ($fl & 0x100) $r += 4; if ($fl & 0x200) $r += 4; if ($fl & 0x400) $r += 4;
                            $c0 = ($tv === 1) ? self::s32($b, $r) : self::u32($b, $r);
                        }
                    }
                    $new = $raw - $c0 + (int)round($off * $ts[$tid]);
                    if ($new < 0) return null;
                    if ($v === 1) $b = substr_replace($b, pack('J', $new), $p + 4, 8);
                    else { if ($new > 0xFFFFFFFF) return null; $b = substr_replace($b, pack('N', $new), $p + 4, 4); }
                }
                $parts[] = ['s', $b]; $fragLen += strlen($b);
            }
            if ($init === '' || $fragLen === 0) return null;
            return ['init' => $init, 'parts' => $parts, 'fragLen' => $fragLen];
        } catch (\Throwable $e) { return null; }
    }
    /** 상자 목록(위치 포함): [[종류, 시작, 머리 길이, 전체 길이], ...] — [$s, $e) 범위, 길이 확인 */
    private static function walk(string $d, int $s, int $e): array {
        $r = []; $o = $s;
        while ($o + 8 <= $e) {
            $bs = self::u32($d, $o); $t = substr($d, $o + 4, 4); $hl = 8;
            if ($bs === 1) { if ($o + 16 > $e) break; $bs = self::u64($d, $o + 8); $hl = 16; } elseif ($bs === 0) { $bs = $e - $o; }
            if ($bs < $hl || $o + $bs > $e) break;
            $r[] = [$t, $o, $hl, $bs]; $o += $bs;
        }
        return $r;
    }
    /** 범위 안에서 첫 $type 상자: [시작, 머리 길이, 끝] 또는 null */
    private static function find(string $d, int $s, int $e, string $type): ?array {
        foreach (self::walk($d, $s, $e) as [$t, $o, $hl, $bs]) if ($t === $type) return [$o, $hl, $o + $bs];
        return null;
    }

    // ── 도우미 (모든 읽기에 길이 확인) ─────────────────────────────────
    private static function readAll($fp, int $len): string {
        $buf = '';
        while (strlen($buf) < $len && !feof($fp)) { $c = fread($fp, min(1048576, $len - strlen($buf))); if ($c === false || $c === '') break; $buf .= $c; }
        return $buf;
    }
    private static function boxes(string $d): array {
        $r = []; $o = 0; $L = strlen($d);
        while ($o + 8 <= $L) {
            $bs = self::u32($d, $o); $t = substr($d, $o + 4, 4); $hl = 8;
            if ($bs === 1) { if ($o + 16 > $L) break; $bs = self::u64($d, $o + 8); $hl = 16; } elseif ($bs === 0) { $bs = $L - $o; }
            if ($bs < $hl || $o + $bs > $L) break;
            $r[] = [$t, substr($d, $o + $hl, $bs - $hl)]; $o += $bs;
        }
        return $r;
    }
    private static function child(string $d, string $type): ?string { foreach (self::boxes($d) as [$t, $b]) if ($t === $type) return $b; return null; }
    private static function sampleEntry(string $stbl): string {        // stsd 첫 표본 항목 종류('avc1','hvc1','mp4a','ac-3' ...)
        $stsd = self::child($stbl, 'stsd');
        return ($stsd !== null && strlen($stsd) >= 16) ? substr($stsd, 12, 4) : '';
    }
    private static function mdhdTimescale(string $b): int {
        if (strlen($b) < 24) return 0;
        return ord($b[0]) === 1 ? self::u32($b, 20) : self::u32($b, 12);
    }
    private static function u32(string $s, int $o): int { if ($o + 4 > strlen($s)) throw new \RuntimeException('short'); return unpack('N', substr($s, $o, 4))[1]; }
    private static function s32(string $s, int $o): int { $v = self::u32($s, $o); return $v > 0x7fffffff ? $v - 0x100000000 : $v; }
    private static function u64(string $s, int $o): int { if ($o + 8 > strlen($s)) throw new \RuntimeException('short'); $a = unpack('N2', substr($s, $o, 8)); return ($a[1] << 32) | $a[2]; }
    private static function s64(string $s, int $o): int { return self::u64($s, $o); }   // PHP 64비트 정수 — 0xFFFF... 는 -1 로 떨어진다
}
