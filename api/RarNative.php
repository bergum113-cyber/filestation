<?php
/**
 * RarNative.php - PHP 네이티브 RAR 헤더 파서 (RAR5 + RAR4)
 *
 * 목적: 7-Zip/UnRAR CLI는 Windows 콘솔 코드페이지에 의존하여 한글/유니코드
 *       파일명이 깨질 수 있다. 반디집처럼 RAR 헤더를 직접 읽어 파일명을 추출하면
 *       실행 환경(LANG/chcp)과 무관하게 항상 정확하다.
 *
 * 반환: ['fmt'=>'RAR5'|'RAR4', 'items'=>[ ['name','size','is_dir','encrypted'], ... ]]
 *       또는 RAR이 아니면 null.
 *
 * 주의: 비밀번호로 헤더 자체가 암호화된 RAR(헤더 암호화)은 이름을 읽을 수 없어
 *       items가 비거나 부정확할 수 있다. 그 경우 호출측에서 기존 7z/UnRAR로 폴백한다.
 */

if (!function_exists('fs_rar_native_list')) {

    /** RAR5 가변정수(vint, 7비트 LEB128) */
    function fs_rar5_vint($data, &$pos) {
        $val = 0; $shift = 0; $len = strlen($data);
        while ($pos < $len) {
            $b = ord($data[$pos]); $pos++;
            $val |= ($b & 0x7F) << $shift;
            if (!($b & 0x80)) break;
            $shift += 7;
            if ($shift > 63) break; // 안전장치
        }
        return $val;
    }

    /** RAR5 파싱: 파일명은 UTF-8로 저장됨 */
    function fs_parse_rar5($data) {
        $pos = 8; $items = []; $len = strlen($data);
        $guard = 0;
        while ($pos < $len) {
            if (++$guard > 200000) break; // 무한루프 방지
            if ($pos + 4 > $len) break;
            $pos += 4; // CRC32 (블록 헤더 CRC)
            $hsize = fs_rar5_vint($data, $pos);
            if ($hsize <= 0) break;
            $hbodyStart = $pos;          // hsize는 이 지점부터의 길이
            $htype  = fs_rar5_vint($data, $pos);
            $hflags = fs_rar5_vint($data, $pos);
            $extraSize = 0; $dsize = 0;
            if ($hflags & 0x01) $extraSize = fs_rar5_vint($data, $pos); // extra area size
            if ($hflags & 0x02) $dsize = fs_rar5_vint($data, $pos);     // data area size
            if ($htype === 2) { // File header
                $fflags = fs_rar5_vint($data, $pos);
                $unp    = fs_rar5_vint($data, $pos);
                fs_rar5_vint($data, $pos); // attributes
                $mtime = '';
                if ($fflags & 0x02) { // mtime이 헤더 본문에 있는 경우(unix, 4바이트 LE)
                    if ($pos + 4 <= $len) {
                        $ts = unpack('V', substr($data, $pos, 4))[1];
                        if ($ts > 0) $mtime = date('Y-m-d H:i:s', $ts);
                    }
                    $pos += 4;
                }
                if ($fflags & 0x04) $pos += 4; // data crc32
                fs_rar5_vint($data, $pos); // compression info
                fs_rar5_vint($data, $pos); // host os
                $nlen = fs_rar5_vint($data, $pos);
                if ($nlen < 0 || $pos + $nlen > $len) break;
                $nm = substr($data, $pos, $nlen); // UTF-8
                $isDir = (bool)($fflags & 0x01);
                // extra area에서 mtime(type 3)과 암호화(type 1) 추출
                $isEnc = false;
                if ($extraSize > 0) {
                    $extraStart = $hbodyStart + $hsize - $extraSize;
                    if ($mtime === '') {
                        $mtime = fs_rar5_extra_mtime($data, $extraStart, $extraSize);
                    }
                    $isEnc = fs_rar5_extra_has_crypt($data, $extraStart, $extraSize);
                }
                $items[] = [
                    'name'      => str_replace('\\', '/', $nm),
                    'size'      => $unp,
                    'is_dir'    => $isDir,
                    'encrypted' => $isEnc,
                    'modified'  => $mtime,
                ];
            }
            $next = $hbodyStart + $hsize + $dsize;
            if ($next <= $pos - 4 || $next <= 0) break; // 진행 안 하면 중단
            $pos = $next;
        }
        return $items;
    }

    /** RAR4 유니코드 파일명 디코더 (unrar EncodeFileName::Decode 알고리즘) */
    function fs_rar4_decode_unicode($oem, $enc) {
        $encLen = strlen($enc);
        if ($encLen === 0) return $oem;
        $high = ord($enc[0]); $encPos = 1;
        $flagBits = 0; $flags = 0; $decPos = 0;
        $oemLen = strlen($oem); $cps = [];
        $guard = 0;
        $decMax = max(2048, ($oemLen + $encLen) * 2);   // ★ (2026-10-31 12:03, 34차 전체 점검) 출력 길이 상한 — unrar 원본처럼 버퍼 크기에서 멈춤(조작한 이름 하나가 수 MB 로 부풀었음)
        while ($encPos < $encLen) {
            if (++$guard > 100000 || $decPos > $decMax) break;
            if ($flagBits === 0) {
                if ($encPos >= $encLen) break;
                $flags = ord($enc[$encPos]); $encPos++; $flagBits = 8;
            }
            switch ($flags >> 6) {
                case 0:
                    if ($encPos >= $encLen) break 2;
                    $cps[$decPos++] = ord($enc[$encPos]); $encPos++;
                    break;
                case 1:
                    if ($encPos >= $encLen) break 2;
                    $cps[$decPos++] = ord($enc[$encPos]) + ($high << 8); $encPos++;
                    break;
                case 2:
                    if ($encPos + 1 >= $encLen) break 2;
                    $cps[$decPos++] = ord($enc[$encPos]) + (ord($enc[$encPos+1]) << 8); $encPos += 2;
                    break;
                case 3:
                    if ($encPos >= $encLen) break 2;
                    $length = ord($enc[$encPos]); $encPos++;
                    if ($length & 0x80) {
                        if ($encPos >= $encLen) break 2;
                        $corr = ord($enc[$encPos]); $encPos++;
                        for ($l = ($length & 0x7f) + 2; $l > 0; $l--, $decPos++) {
                            $base = ($decPos < $oemLen) ? ord($oem[$decPos]) : 0;
                            $cps[$decPos] = (($base + $corr) & 0xff) + ($high << 8);
                        }
                    } else {
                        for ($l = $length + 2; $l > 0; $l--, $decPos++) {
                            $cps[$decPos] = ($decPos < $oemLen) ? ord($oem[$decPos]) : 0;
                        }
                    }
                    break;
            }
            $flags = ($flags << 2) & 0xFF; $flagBits -= 2;
        }
        // UTF-16 코드포인트 → UTF-8
        // ★ (2026-11-02 09:03, 41차 점검) 서로게이트 쌍(U+10000 이상: 이모지·확장 한자)을 합쳐 4바이트로 — 종전엔 두 단위를 3바이트씩 따로 써서 잘못된 UTF-8(CESU-8)이 돼
        //   호출부가 CP949/SJIS 변환으로 넘겨 이름이 깨졌음(재현). 짝 없는 서로게이트는 U+FFFD 로
        $out = '';
        $cps = array_values($cps); $cpN = count($cps);
        for ($ci = 0; $ci < $cpN; $ci++) {
            $cp = $cps[$ci];
            if ($cp >= 0xD800 && $cp <= 0xDBFF && $ci + 1 < $cpN && $cps[$ci + 1] >= 0xDC00 && $cps[$ci + 1] <= 0xDFFF) {
                $cp = 0x10000 + (($cp - 0xD800) << 10) + ($cps[$ci + 1] - 0xDC00); $ci++;
                $out .= chr(0xF0|($cp>>18)).chr(0x80|(($cp>>12)&0x3F)).chr(0x80|(($cp>>6)&0x3F)).chr(0x80|($cp&0x3F));
                continue;
            }
            if ($cp >= 0xD800 && $cp <= 0xDFFF) $cp = 0xFFFD;
            if ($cp < 0x80)       $out .= chr($cp);
            elseif ($cp < 0x800)  $out .= chr(0xC0|($cp>>6)).chr(0x80|($cp&0x3F));
            else                  $out .= chr(0xE0|($cp>>12)).chr(0x80|(($cp>>6)&0x3F)).chr(0x80|($cp&0x3F));
        }
        return $out;
    }

    /** RAR4 파싱 */
    function fs_parse_rar4($data) {
        $pos = 7; $items = []; $len = strlen($data);
        $guard = 0;
        while ($pos + 7 <= $len) {
            if (++$guard > 200000) break;
            $head_flags = unpack('v', substr($data, $pos+3, 2))[1];
            $head_size  = unpack('v', substr($data, $pos+5, 2))[1];
            $head_type  = ord($data[$pos+2]);
            if ($head_size < 7) break;
            $add_size = 0;
            if ($head_flags & 0x8000) {
                if ($pos + 11 > $len) break;
                $add_size = unpack('V', substr($data, $pos+7, 4))[1];
            }
            if ($head_type === 0x74) { // File header
                if ($pos + 32 > $len) break;
                $unp_size  = unpack('V', substr($data, $pos+11, 4))[1];
                // ★ (2026-11-01 09:43, 38차 점검) LHD_LARGE(0x100)면 위쪽 32비트 크기(HIGH_UNP_SIZE, +36)를 더함 — 종전엔 4GB 넘는 항목이 2^32 로 나눈 나머지(5GB → 1GB)로 나왔음
                if (($head_flags & 0x100) && $pos + 40 <= $len) $unp_size += unpack('V', substr($data, $pos+36, 4))[1] * 4294967296;
                $name_size = unpack('v', substr($data, $pos+26, 2))[1];
                // FTIME: DOS datetime (pos+20, 4바이트 LE)
                $mtime = '';
                $ft = unpack('V', substr($data, $pos+20, 4))[1];
                if ($ft > 0) {
                    $d = ($ft >> 16) & 0xffff; $t = $ft & 0xffff;
                    $yy = (($d >> 9) & 0x7f) + 1980; $mm = ($d >> 5) & 0x0f; $dd = $d & 0x1f;
                    $hh = ($t >> 11) & 0x1f; $mi = ($t >> 5) & 0x3f; $ss = ($t & 0x1f) * 2;
                    if ($mm >= 1 && $mm <= 12 && $dd >= 1 && $dd <= 31) {
                        $mtime = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $yy, $mm, $dd, $hh, $mi, $ss);
                    }
                }
                $name_off  = $pos + 32;
                if ($head_flags & 0x100) $name_off += 8; // HIGH_PACK_SIZE/HIGH_UNP_SIZE
                if ($name_size < 0 || $name_off + $name_size > $len) { $pos += $head_size + $add_size; continue; }
                $raw = substr($data, $name_off, $name_size);
                if ($head_flags & 0x200) { // 유니코드 확장 이름
                    $zp = strpos($raw, "\x00");
                    if ($zp !== false) {
                        $oem  = substr($raw, 0, $zp);
                        $encU = substr($raw, $zp + 1);
                        $nm = fs_rar4_decode_unicode($oem, $encU);
                    } else {
                        $nm = $raw; // 전부 ASCII
                    }
                } else {
                    $nm = $raw; // OEM(보통 CP437/CP949). 호출측에서 UTF-8 검증 후 CP949 폴백.
                }
                $isDir = (($head_flags & 0xe0) === 0xe0); // LHD_DIRECTORY
                $enc   = (bool)($head_flags & 0x04);       // LHD_PASSWORD
                $items[] = [
                    'name'      => str_replace('\\', '/', $nm),
                    'size'      => $unp_size,
                    'is_dir'    => $isDir,
                    'encrypted' => $enc,
                    'modified'  => $mtime,
                ];
            }
            $pos += $head_size + $add_size;
        }
        return $items;
    }

    /** RAR5 extra area에서 파일시간 레코드(type 3)의 mtime 추출 */
    /**
     * RAR5 File 헤더의 extra area에서 암호화 레코드(type 1) 존재 여부 확인.
     * RAR5 스펙: 파일이 암호화되면 extra area에 File encryption record(type 1)가 포함된다.
     * (헤더 암호화 -hp의 경우 헤더 자체가 안 읽혀 별도 처리. 여기선 데이터 암호화 -p 감지)
     * @return bool 암호화 레코드가 있으면 true
     */
    function fs_rar5_extra_has_crypt($data, $start, $size) {
        $len = strlen($data);
        $end = $start + $size;
        if ($start < 0 || $end > $len) return false;
        $p = $start; $guard = 0;
        while ($p < $end) {
            if (++$guard > 1000) break;
            $recSize = fs_rar5_vint($data, $p);
            if ($recSize <= 0) break;
            $recBodyStart = $p;
            $recType = fs_rar5_vint($data, $p);
            if ($recType === 1) return true; // File encryption record
            $p = $recBodyStart + $recSize;
            if ($p <= $recBodyStart) break;
        }
        return false;
    }

    function fs_rar5_extra_mtime($data, $start, $size) {
        $len = strlen($data);
        $end = $start + $size;
        if ($start < 0 || $end > $len) return '';
        $p = $start; $guard = 0;
        while ($p < $end) {
            if (++$guard > 1000) break;
            $recSize = fs_rar5_vint($data, $p); // 이 레코드 크기(type부터 끝까지)
            if ($recSize <= 0) break;
            $recBodyStart = $p;
            $recType = fs_rar5_vint($data, $p);
            if ($recType === 3) { // File time
                $tflags = fs_rar5_vint($data, $p);
                $isUnix = (bool)($tflags & 0x01);
                $hasM   = (bool)($tflags & 0x02);
                if ($hasM) {
                    if ($isUnix) {
                        if ($p + 4 <= $len) {
                            $ts = unpack('V', substr($data, $p, 4))[1];
                            if ($ts > 0) return date('Y-m-d H:i:s', $ts);
                        }
                    } else {
                        // Windows FILETIME (64bit, 100ns 단위, 1601 기준)
                        if ($p + 8 <= $len) {
                            $lo = unpack('V', substr($data, $p, 4))[1];
                            $hi = unpack('V', substr($data, $p+4, 4))[1];
                            $ft = $hi * 4294967296 + $lo;
                            $unix = (int)(($ft - 116444736000000000) / 10000000);
                            if ($unix > 0) return date('Y-m-d H:i:s', $unix);
                        }
                    }
                }
                return '';
            }
            $p = $recBodyStart + $recSize; // 다음 레코드
            if ($p <= $recBodyStart) break;
        }
        return '';
    }

    /**
     * RAR 파일을 네이티브 파싱.
     * @return array|null ['fmt','items'] 또는 RAR 아니면 null
     */
    function fs_rar_native_list($path) {
        $fh = @fopen($path, 'rb');
        if (!$fh) return null;
        $head = @fread($fh, 8);
        if ($head === false || strlen($head) < 7) { @fclose($fh); return null; }
        $isR5 = (substr($head, 0, 8) === "Rar!\x1a\x07\x01\x00");
        $isR4 = (substr($head, 0, 7) === "Rar!\x1a\x07\x00");
        if (!$isR5 && !$isR4) { @fclose($fh); return null; }
        // ★ (2026-11-01 09:43, 38차 점검) 파일 전체(최대 1GB)를 file_get_contents 로 메모리에 올리던 것을 블록 헤더만 읽고 데이터 영역은 fseek 으로 건너뛰게 바꿈.
        //   종전엔 요청 하나가 RAR 크기만큼 RAM 을 썼고, memory_limit 이 걸린 환경에선 치명 오류(호출부 null 폴백도 못 탐)였음.
        //   각 블록 헤더를 '시그니처 + 그 블록 헤더' 짧은 버퍼로 만들어 기존 파서(fs_parse_rar5/4)에 그대로 넘기므로 해석 규칙은 종전과 같음.
        //   헤더를 읽다 끊기거나 값이 이상하면 거기까지 모은 항목을 돌려줌(종전 파서가 범위를 벗어나면 멈추던 것과 같음).
        $items = [];
        $pos = $isR5 ? 8 : 7;
        $sig = $isR5 ? "Rar!\x1a\x07\x01\x00" : "Rar!\x1a\x07\x00";
        $guard = 0;
        while (true) {
            if (++$guard > 200000) break;              // 무한루프 방지(종전 파서와 같은 상한)
            if (@fseek($fh, $pos) !== 0) break;
            if ($isR5) {
                $pre = @fread($fh, 4 + 10);            // CRC32(4) + hsize vint(최대 10바이트)
                if ($pre === false || strlen($pre) < 5) break;
                $p = 4;
                $hsize = fs_rar5_vint($pre, $p);
                if ($hsize <= 0 || $hsize > 2097152 + 64) break;   // 규격상 헤더 최대 2MB
                $total = $p + $hsize;                  // CRC + vint + 헤더 본문
                if (@fseek($fh, $pos) !== 0) break;
                $blk = @fread($fh, $total);
                if ($blk === false || strlen($blk) < $total) break;
                // 데이터 영역 크기(dsize) — 기존 파서와 같은 순서로 읽음
                $q = $p;
                fs_rar5_vint($blk, $q);                // type
                $hflags = fs_rar5_vint($blk, $q);
                if ($hflags & 0x01) fs_rar5_vint($blk, $q);
                $dsize = ($hflags & 0x02) ? fs_rar5_vint($blk, $q) : 0;
                foreach (fs_parse_rar5($sig . $blk) as $it) $items[] = $it;
                $next = $pos + $total + $dsize;
            } else {
                $pre = @fread($fh, 11);
                if ($pre === false || strlen($pre) < 7) break;
                $head_flags = unpack('v', substr($pre, 3, 2))[1];
                $head_size  = unpack('v', substr($pre, 5, 2))[1];
                if ($head_size < 7) break;
                $add_size = 0;
                if ($head_flags & 0x8000) {
                    if (strlen($pre) < 11) break;
                    $add_size = unpack('V', substr($pre, 7, 4))[1];
                }
                if (@fseek($fh, $pos) !== 0) break;
                $blk = @fread($fh, $head_size);
                if ($blk === false || strlen($blk) < $head_size) break;
                // 파일 헤더에 LHD_LARGE 면 묶음 크기 위쪽 32비트(HIGH_PACK_SIZE, +32)도 데이터 영역에 포함
                if (ord($pre[2]) === 0x74 && ($head_flags & 0x100) && $head_size >= 36) {
                    $add_size += unpack('V', substr($blk, 32, 4))[1] * 4294967296;
                }
                foreach (fs_parse_rar4($sig . $blk) as $it) $items[] = $it;
                $next = $pos + $head_size + $add_size;
            }
            if (!is_int($next) || $next <= $pos) break;   // 진행 안 하거나 크기가 정수 범위를 넘으면(조작·손상) 중단
            $pos = $next;
        }
        @fclose($fh);
        return ['fmt' => $isR5 ? 'RAR5' : 'RAR4', 'items' => $items];
    }
}
