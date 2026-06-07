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
        while ($encPos < $encLen) {
            if (++$guard > 100000) break;
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
        $out = '';
        foreach ($cps as $cp) {
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
        // 헤더 파싱에는 전체 데이터가 필요(파일명이 본문 곳곳에 위치). 큰 파일도 헤더만 보지만
        // 분산되어 있어 전체 로드가 가장 단순/안전. 단, 과도하게 큰 파일은 상한을 둔다.
        @fclose($fh);
        $size = @filesize($path);
        if ($size === false) return null;
        // 2GB 초과 등 비정상은 호출측 폴백에 맡김(메모리 보호)
        if ($size > 1073741824) return null; // 1GB 초과면 네이티브 생략
        $data = @file_get_contents($path);
        if ($data === false) return null;
        if ($isR5) return ['fmt' => 'RAR5', 'items' => fs_parse_rar5($data)];
        return ['fmt' => 'RAR4', 'items' => fs_parse_rar4($data)];
    }
}
