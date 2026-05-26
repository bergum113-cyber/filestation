# FileStation v5.8.1j

![version](https://img.shields.io/badge/version-v5.8.1j-blue)
![PHP](https://img.shields.io/badge/PHP-8.0~8.4-777BB4?logo=php&logoColor=white)
![license](https://img.shields.io/badge/license-GPL--3.0-green)
![webserver](https://img.shields.io/badge/server-Apache%20%7C%20Nginx%20%7C%20IIS-orange)
![rhwp](https://img.shields.io/badge/rhwp-0.7.13-9cf)
![platform](https://img.shields.io/badge/platform-self--hosted-lightgrey)

> 🇰🇷 **한국 사용자를 위한 자체호스팅 웹 NAS** — HWP/HWPX 뷰어, OnlyOffice 통합, E2E 암호화 Vault, 5종 외부 스토리지, HLS 비디오 스트리밍, MP3 플레이어 일체형

---

## 🌟 핵심 특징

| 기능 | 설명 |
|---|---|
| 📄 **HWP/HWPX 뷰어 + 편집기** | rhwp 0.7.13 통합 — **자체호스팅 NAS 중 글로벌 유일** |
| 📝 **OnlyOffice 통합** | docx/xlsx/pptx/odt 등 Office 문서 직접 편집 |
| 🔐 **E2E 암호화 Vault** | AES-256-GCM, Web Crypto API, 클라이언트 측 복호화 |
| 🌐 **5종 외부 스토리지** | FTP / SFTP / WebDAV / S3 / SMB 통합 인터페이스 |
| 🎵 **MP3 플레이어** | 셔플/반복/볼륨 영구저장, 비주얼라이저, iOS MediaSession |
| 📺 **HLS 비디오 스트리밍** | FFmpeg 기반 실시간 트랜스코딩 |
| 🔒 **2FA TOTP + 백업코드** | Google Authenticator 호환, 분실 시 복구 코드 |
| 🛡 **백신 + 랜섬웨어 방지** | ClamAV / Windows Defender 연동, 엔트로피 검사 |
| 🗂 **WebDAV 서버** | Windows 탐색기/macOS Finder에서 직접 마운트 |
| 📋 **게시판 + 댓글 + 알림** | 내장 게시판 시스템 (첨부파일 지원) |
| 🌍 **다국어** | 한국어/영어 완전 지원 (1,400+ 번역 키) |
| 🎨 **18종 테마** | 라이트/다크/파스텔 18종 (다크모드 포함) |

---

## ⚠️ Disclaimer

이 프로젝트는 Synology Inc. 또는 QNAP Systems, Inc.와 **관계가 없습니다.** "FileStation"이라는 명칭은 일반 영어 단어 조합("File" + "Station")으로 프로젝트의 기능을 설명하기 위해 사용되었습니다. Synology의 File Station®과 QNAP의 File Station®은 별개의 제품입니다.

This project is **not affiliated with Synology Inc. or QNAP Systems, Inc.** "FileStation" is a generic combination of common English words and is used here to describe the project's purpose.

---

## ⚙️ 요구사항

| 구분 | 항목 |
|---|---|
| **PHP** | 8.0 ~ 8.4 (8.2~8.3 권장) |
| **웹서버** | Apache / Nginx / IIS |
| **필수 확장** | json, mbstring, zip, gd |
| **선택 확장** | curl, intl, ssh2 (SFTP용), smbclient (SMB용) |
| **선택 프로그램** | ffmpeg (영상 트랜스코딩), 7-Zip (RAR/7z/ISO/CAB 등 해제) |
| **백신 연동** | ClamAV / Windows Defender |
| **메모리** | 512MB 이상 (2GB 이상 권장) |
| **디스크** | 100MB 이상 (사용자 데이터 별도) |

---

## 🔐 계정 관리

| 기능 | 설명 |
|---|---|
| 회원가입 | 아이디/비밀번호/이메일 입력 |
| 회원가입 승인 | 관리자가 신규 가입자 승인 (status: pending → active) |
| 로그인 | 일반 로그인 |
| 로그인 유지 | 브라우저 닫아도 로그인 유지 (remember_token) |
| **2단계 인증 (2FA TOTP)** | OTP 앱 인증 (Google Authenticator, Authy 등) |
| **백업 코드** | 2FA 분실 시 복구용 코드 |
| **OIDC SSO** | Keycloak/Azure AD 등 OIDC 호환 IdP 통합 |
| 비밀번호 변경 | 현재 비밀번호 확인 후 변경 |
| 비밀번호 찾기 | 이메일로 재설정 링크 발송 (SMTP 필요) |
| 세션 관리 | 접속 중인 기기 확인/로그아웃 |
| 회원탈퇴 | 계정 + 관련 데이터 삭제 (휴지통/즐겨찾기/최근파일/공유링크/세션/2FA 정보 모두 정리) |

---

## 📋 사이드 메뉴 (사용자)

| 메뉴 | 설명 |
|---|---|
| 🏠 내 파일 | 개인 전용 폴더 (자동 생성) |
| 📂 공용 폴더 | 모든 사용자 공유 폴더 |
| 💾 외부 스토리지 | 관리자가 추가한 드라이브 |
| ⭐ 즐겨찾기 | 즐겨찾기 등록한 파일/폴더 |
| 🕐 최근 파일 | 최근 접근한 파일 목록 |
| 🔗 내 공유 링크 | 내가 만든 공유 링크 관리 |
| 🗑️ 내 휴지통 | 삭제한 파일 보관/복원 |

---

## 🛠️ 관리자 메뉴

### 사용자 / 권한

| 메뉴 | 설명 |
|---|---|
| 👥 사용자 관리 | 사용자 추가/수정/삭제, 용량 설정, 일괄 쿼터 |
| 🏷️ 역할 관리 | 역할별 권한 설정 |
| 👻 탈퇴/삭제 기록 | 탈퇴 사용자 조회 / 영구 삭제 |
| ✅ 가입 승인 | pending 상태 사용자 승인 |

### 스토리지 / 파일

| 메뉴 | 설명 |
|---|---|
| 💾 스토리지 관리 | 스토리지 추가/수정/권한 설정 |
| 🔗 공유 관리 | 전체 공유 링크 조회 / 삭제 |
| 🗑️ 전체 휴지통 | 모든 사용자 휴지통 관리 |
| 🧹 조건부 삭제 | 패턴으로 파일 일괄 검색 / 삭제 |
| 🔍 검색 인덱스 | 파일 인덱스 구축 / 재구축 / 초기화 |

### 로그 / 기록

| 메뉴 | 설명 |
|---|---|
| 📋 로그인 기록 | 로그인 성공/실패 기록 |
| 📜 활동 로그 | 파일 작업 기록 (업로드/다운로드/삭제 등) |
| 📊 접속 통계 | 일별/월별/국가별 접속 통계 (GeoIP) |

### 시스템

| 메뉴 | 설명 |
|---|---|
| ⚡ 속도 제한 (QoS) | 역할별/사용자별 다운로드 속도 제한 |
| 🔒 보안 설정 | IP/국가 차단 (GeoIP), 브루트포스 방지 |
| 🛡️ 백신 설정 | ClamAV / Windows Defender 연동 |
| 🔐 랜섬웨어 방지 | 의심 확장자 차단, 엔트로피 검사, 버전 관리 |
| 📢 공지 설정 | 팝업/배너 공지 관리 |
| 📜 이용약관 | 회원가입 시 약관 설정 |
| 📋 게시판 관리 | 게시판 생성/삭제, 게시글/댓글 관리 |
| 🔧 시스템 설정 | 사이트명, 회원가입, SMTP 등 |
| 📊 시스템 정보 | CPU/메모리/디스크 사용량, PHP 정보 |

---

## 📂 스토리지 종류

| 종류 | 설명 |
|---|---|
| **내 파일 (home)** | 개인 전용 폴더 (자동 생성) |
| **공용 폴더 (shared)** | 모든 사용자 공유 폴더 |
| **로컬 드라이브** | 서버 내 다른 경로 (예: D:\Files, /mnt/data) |
| **네트워크 드라이브** | UNC 경로 (예: \\NAS\share) |
| **FTP** | FTP 서버 연결 |
| **SFTP** | SSH 파일 전송 (ssh2 확장 필요) |
| **WebDAV** | WebDAV 서버 연결 (NextCloud, OwnCloud 등) |
| **Amazon S3** | S3 버킷 연결 (S3 호환 객체 스토리지 포함) |
| **SMB / CIFS** | Windows 공유 폴더 (smbclient 필요) |

---

## 📤 업로드 / 📥 다운로드

### 업로드

| 기능 | 설명 |
|---|---|
| 드래그 & 드롭 | 파일/폴더를 화면에 끌어다 놓기 |
| 멀티 업로드 | 여러 파일 동시 업로드 (개별 진행률 표시) |
| 폴더 업로드 | 폴더 구조 그대로 업로드 |
| 대용량 지원 | 파일 크기 제한 없음 (자동 분할 청크 업로드) |
| 중복 처리 | 이름 변경 / 덮어쓰기 / 건너뛰기 선택 |
| 백그라운드 | 업로드 중 다른 작업 가능 |
| **외부 업로드 (filedrop)** | 비밀번호 보호된 링크로 외부 사용자 파일 업로드 받기 |

### 다운로드

| 기능 | 설명 |
|---|---|
| 단일 파일 | 더블클릭 또는 우클릭 → 다운로드 |
| 다중 파일 | 여러 파일 선택 → ZIP 압축 다운로드 |
| 폴더 다운로드 | 폴더 전체 ZIP으로 다운로드 |
| 이어받기 | 중단된 다운로드 재개 (Range 헤더 지원) |
| **속도 제한** | QoS 설정 시 사용자별/역할별 속도 제한 적용 |

---

## 📂 파일 관리

| 기능 | 단축키 | 설명 |
|---|---|---|
| 새 폴더 | - | 폴더 생성 |
| 복사 | Ctrl+C → Ctrl+V | 파일/폴더 복사 |
| 이동 | Ctrl+X → Ctrl+V | 파일/폴더 이동 |
| 드래그 이동 | 마우스 드래그 | 폴더로 끌어서 이동 |
| 삭제 | Delete | 휴지통으로 이동 |
| 이름 변경 | F2 | 파일/폴더 이름 변경 |
| 전체 선택 | Ctrl+A | 모든 항목 선택 |
| 다중 선택 | Ctrl+클릭 | 개별 항목 추가 선택 |
| 범위 선택 | Shift+클릭 | 연속 항목 선택 |
| 열기 | Enter | 파일/폴더 열기 |
| 상위 폴더 | Backspace | 상위 폴더로 이동 |
| **파일 잠금** | 우클릭 메뉴 | 다른 사용자 수정/이동/삭제 방지 |

---

## 👁️ 미리보기 지원 형식

| 종류 | 지원 형식 |
|---|---|
| 이미지 | JPG, PNG, GIF, WebP, SVG, BMP, ICO, TIFF, HEIC |
| 동영상 | MP4, MKV, AVI, MOV, WebM, WMV, FLV, TS, 3GP, M2TS, MPEG, OGV 등 |
| 음악 | MP3, WAV, FLAC, OGG, M4A, AAC, WMA, OPUS |
| 문서 | PDF, TXT, HTML, Markdown |
| 코드 | PHP, JS, TS, Python, Java, C/C++, Go, Rust, Ruby, Swift 등 80+ 언어 |
| **한글** | **HWP, HWPX (rhwp 0.7.13 전용 뷰어 + 편집기)** |
| **오피스** | **DOCX, XLSX, PPTX (OnlyOffice 직접 편집)** |
| 압축 | ZIP, RAR, 7Z, TAR, GZ, BZ2, ISO, CAB, WIM, ARJ, LZH, XZ |

---

## ✏️ 편집

| 기능 | 설명 |
|---|---|
| **OnlyOffice 편집** | Word/Excel/PowerPoint 웹에서 직접 편집 (별도 Docker) |
| **HWP/HWPX 편집** | rhwp 통합 편집기로 한글 문서 편집 |
| 텍스트 편집 | 코드/텍스트 파일 편집 |

---

## 🔗 공유

5가지 공유 타입:

| 타입 | 설명 |
|---|---|
| **download** | 일반 파일 다운로드 공유 |
| **stream** | HLS 비디오 스트리밍 공유 |
| **filedrop** | 외부 사용자가 파일을 업로드받음 |
| **internal share** | 사용자 간 공유 |

공통 옵션:

| 기능 | 설명 |
|---|---|
| 공유 링크 생성 | 파일/폴더 공유 URL 생성 |
| 비밀번호 보호 | 링크 접근 시 비밀번호 필요 (해시 저장) |
| 만료일 설정 | 지정 날짜 후 자동 만료 |
| 다운로드 횟수 제한 | 횟수 초과 시 자동 만료 |
| 공유 목록 | 내가 만든 공유 링크 관리 |
| 공유 삭제 | 공유 링크 즉시 무효화 |

---

## 🔍 검색

| 기능 | 설명 |
|---|---|
| 기본 검색 | 현재 스토리지에서 파일명 검색 |
| 전체 검색 | 모든 스토리지 통합 검색 (FileIndex 무제한 인덱싱) |
| 고급 검색 | 파일 유형 필터 (이미지/동영상/문서 등) |
| | 날짜 범위 필터 |
| | 크기 범위 필터 |

---

## ⭐ 정리

| 기능 | 설명 |
|---|---|
| 즐겨찾기 | 자주 쓰는 파일/폴더 등록 |
| 최근 파일 | 최근 접근한 파일 목록 |
| 정렬 | 이름/날짜/크기/유형 (오름차순/내림차순) |
| 보기 모드 | 그리드 뷰 / 리스트 뷰 |

---

## 🗑️ 휴지통 / 📜 파일 버전

| 기능 | 설명 |
|---|---|
| **휴지통** | 삭제 → 보관 / 복원 / 영구 삭제 / 휴지통 비우기 |
| **파일 버전** | 덮어쓰기 전 자동 백업 → 이전 버전 / 복원 / 다운로드 |

---

## 📦 압축

| 기능 | 설명 |
|---|---|
| ZIP 압축 | 선택한 파일/폴더를 ZIP으로 생성 |
| 7Z 압축 | 7-Zip 포맷 압축 (서버에 7-Zip 필요) |
| 분할 압축 | 대용량 파일 분할 압축 (.001, .002...) |
| 비밀번호 압축 | AES-256 암호화 압축 |
| **압축 해제** | ZIP / RAR / 7Z / ISO / CAB / WIM / ARJ / LZH / TAR / GZ / BZ2 / XZ |
| **분할 아카이브** | .001 시리즈 자동 통합 해제 |

---

## ℹ️ 파일 정보

| 기능 | 설명 |
|---|---|
| 기본 정보 | 파일명, 크기, 수정일, 유형 |
| **EXIF 정보** | 이미지 촬영 정보 (카메라, 날짜 등) |
| **GPS 좌표** | 사진 촬영 위치 정보 |
| **EXIF 회전 자동 처리** | 썸네일 생성 시 자동 회전 |

---

## 🎬 미디어 재생

### 동영상

- HLS (HTTP Live Streaming) 실시간 트랜스코딩
- FFmpeg `-re` 플래그로 실시간 인코딩
- iOS ManagedMediaSource sourceBuffer 쿼터 관리
- 적응형 버퍼 관리
- 자동 세그먼트 정리 (HLS cleanup)
- **폴더 자동 다음 재생** — 영상 2개 이상 폴더에서 재생 종료 시 다음 트랙 자동 재생 (사이드 패널 헤더 토글 스위치로 ON/OFF, 일반=영구/공유=세션 저장). 작은 화면(1024px 이하)에서는 패널 숨김으로 토글 불가능하므로 자동 재생 비활성화

### 오디오 (FSAudioPlayer)

- 순수 JS/CSS 커스텀 플레이어
- 재생목록 / 셔플 / 반복 (localStorage 영구저장)
- 볼륨 영구저장
- 캔버스 비주얼라이저
- **iOS MediaSession API** (잠금화면에서 재생/일시정지/이전/다음)
- 앨범아트 IndexedDB 캐시 (mtime 기반 무효화)

### 공유 스트리밍

- HLS 공유 링크 (`share_type='stream'`)
- 외부에서 비밀번호 보호된 비디오 스트리밍 가능

---

## 📋 게시판

| 기능 | 설명 |
|---|---|
| 게시판 관리 | 다중 게시판 생성/관리 |
| 게시글 작성 | 본문 + 첨부파일 (청크 분할 업로드) |
| 게시글 보기/수정/삭제 | 본인 또는 관리자 |
| **댓글** | 게시글에 댓글 + 첨부파일 |
| **알림** | 새 댓글/게시글 알림 (안 읽은 갯수 표시) |
| 스토리지 첨부 | FileStation 내 파일을 게시글에 첨부 |
| 다운로드 | 첨부 파일 다운로드 |

---

## 🛡️ 보안

### 기본 보안

- 🔒 CSRF 토큰 검증 (모든 변경 작업)
- 🛡 XSS 방지 (sanitizeHtml + DOMDocument 화이트리스트)
- 🚫 Path Traversal 방지 (isPathSafe 검증)
- 🚫 Zip Slip 방지 (압축 해제 시 경로 검증)
- 🔐 SSRF 방어 (외부 URL 호출 시 IP 화이트리스트)
- 🔑 비밀번호 해시 (password_hash + PASSWORD_DEFAULT)
- 📊 Rate Limiting (로그인, 업로드, API)
- 🛡 JsonDB 원자적 쓰기 (data race 방지)
- 🔒 OIDC nonce 검증
- 📝 100+ 보안 패치 (path traversal, Zip Slip, OIDC, AES-GCM 업그레이드, XSS, CSRF)
- 🌍 IP / 국가 차단 (GeoIP)
- 🔨 브루트포스 방지

### 🔐 E2E 암호화 Vault

- AES-256-GCM 알고리즘
- Web Crypto API (브라우저 네이티브)
- 클라이언트 측 키 파생 (PBKDF2)
- 서버는 암호문 + IV + Auth Tag만 보관
- Vault 전용 미리보기 (vault_url 파라미터)

### 🦠 백신 연동

- **ClamAV** (Linux/Windows)
- **Windows Defender** (Windows)
- 업로드 파일 자동 검사
- 감염 의심 시 격리/차단

### 🔒 랜섬웨어 방지

- **의심 확장자 차단** (`.encrypted`, `.locked` 등)
- **엔트로피 검사** (암호화된 파일 패턴 감지)
- **버전 관리** (랜섬웨어 공격 후 이전 버전 복구)
- **대량 변경 감지** (단시간 다수 파일 수정 시 경고)

---

## 🌐 WebDAV

| 기능 | 설명 |
|---|---|
| Windows 연결 | 네트워크 드라이브로 연결 (`net use Z: https://your-domain/mydav.php`) |
| Mac 연결 | Finder → 서버에 연결 → `https://your-domain/mydav.php` |
| Linux 연결 | davfs2 mount |
| 탐색기 사용 | 로컬 폴더처럼 파일 관리 |

---

## ⚙️ 설정

| 기능 | 설명 |
|---|---|
| **테마** | **18종 테마** (default, dark, blue, blue-full, lavender, lavender-full, mint, mint-full, pastel-blue, pastel-blue-full, peach, peach-full, pink, pink-full, rose, rose-full, sky, sky-full) |
| 보기 모드 | 그리드 / 리스트 전환 |
| 비밀번호 변경 | 계정 비밀번호 변경 |
| 2FA 설정 | 2단계 인증 활성화/비활성화 + 백업 코드 |

---

## 🖱️ 우클릭 메뉴

### 파일/폴더 선택 시

| 메뉴 | 설명 |
|---|---|
| 열기 | 파일 열기 / 미리보기 |
| OnlyOffice로 편집 | Office 문서 편집 |
| HWP 뷰어로 열기 | rhwp 통합 뷰어 (HWP/HWPX 전용) |
| 다운로드 | 파일 다운로드 |
| 공유 | 공유 링크 생성 |
| 즐겨찾기 | 즐겨찾기 추가/제거 |
| 파일 잠금 | 잠금/해제 |
| 복사 | 클립보드에 복사 |
| 잘라내기 | 이동 준비 |
| 이름 변경 | 이름 바꾸기 |
| 압축 | ZIP/7Z 압축 |
| 압축 해제 | ZIP/RAR/7Z/... 풀기 |
| 이전 버전 | 버전 목록 보기 |
| 정보 | 상세 정보 보기 (EXIF/GPS 포함) |
| 삭제 | 휴지통으로 이동 |

### 빈 공간 클릭 시

| 메뉴 | 설명 |
|---|---|
| 새 폴더 | 폴더 생성 |
| 새 텍스트 문서 (.txt) | 빈 텍스트 파일 즉시 생성 |
| 새 Word 문서 (.docx) | 빈 Word 문서 즉시 생성 (서버 템플릿 복사) |
| 새 Excel 문서 (.xlsx) | 빈 Excel 문서 즉시 생성 (서버 템플릿 복사) |
| 새 프레젠테이션 (.pptx) | 빈 PowerPoint 문서 즉시 생성 (서버 템플릿 복사) |
| 새 한글 문서 (.hwp) | 빈 한글 문서 즉시 생성 (서버 템플릿 복사) |
| 파일 업로드 | 파일 올리기 |
| 폴더 업로드 | 폴더 올리기 |
| 붙여넣기 | 복사/잘라낸 파일 붙여넣기 |
| 새로고침 | 목록 새로고침 |

> 💡 Windows 탐색기 방식 — prompt 없이 기본 이름으로 즉시 생성, 중복 시 `(2)`, `(3)` 자동 증가

---

## ⌨️ 키보드 단축키

| 단축키 | 기능 |
|---|---|
| Ctrl + C | 복사 |
| Ctrl + X | 잘라내기 |
| Ctrl + V | 붙여넣기 |
| Ctrl + A | 전체 선택 |
| Delete | 삭제 |
| F2 | 이름 변경 |
| Enter | 열기 |
| Space | 선택 토글 |
| Escape | 선택 해제 / 모달 닫기 |
| Backspace | 상위 폴더 |
| Home | 첫 번째 항목 |
| End | 마지막 항목 |
| ↑ ↓ ← → | 항목 이동 |
| Shift + 방향키 | 범위 선택 |
| Ctrl + 클릭 | 다중 선택 |
| Shift + 클릭 | 범위 선택 |

---

## 🛠 기술 스택

### 백엔드

- **언어**: PHP 8.0 ~ 8.4 호환 (PHP 8.2 ~ 8.3 권장)
- **데이터베이스**: JsonDB (file-based, 원자적 쓰기)
- **웹 서버**: Apache (.htaccess) / Nginx / IIS
- **외부 의존성**:
  - FFmpeg (HLS 트랜스코딩)
  - 7-Zip (RAR/7z/ISO/CAB 등 해제, 분할 압축)
  - OnlyOffice Document Server (옵션, Docker)
  - php_smbclient 또는 wsmb (SMB 스토리지, 옵션)
  - ClamAV / Windows Defender (옵션)

### 프론트엔드

- Vanilla JS + jQuery
- Web Crypto API (Vault)
- IndexedDB (앨범아트 캐시)
- HLS.js (iOS 외 브라우저)
- ManagedMediaSource (iOS Safari)

### 통합

- **rhwp 0.7.13** — HWP/HWPX 뷰어 + 편집기 (Rust+WASM)
- **OnlyOffice Document Server** — Office 문서 편집 (JWT 인증)
- **WebDAV 서버** — `mydav.php` (Windows 네트워크 드라이브)

---

## 🚀 설치

### 1. 파일 복사

```bash
# 웹 서버 디렉토리에 파일 복사
unzip FileStation_v5.8.1j.zip -d /var/www/html/filestation
```

### 2. 권한 설정

```bash
# Linux
chown -R www-data:www-data /var/www/html/filestation
chmod -R 755 /var/www/html/filestation
chmod -R 775 /var/www/html/filestation/data

# Windows IIS
# data 폴더에 IIS_IUSRS 또는 IUSR 쓰기 권한 부여

# Windows Apache
# data 폴더에 Apache 사용자 (보통 SYSTEM) 쓰기 권한 부여
```

### 3. 초기 설정

브라우저에서 `https://your-domain/filestation/` 접속:
1. 첫 사용자 회원가입 (자동으로 관리자 권한 부여)
2. 관리자 패널에서 추가 사용자 생성 또는 가입 승인
3. 스토리지 추가 (로컬 디스크 또는 외부)
4. (선택) SMTP 설정 → 비밀번호 찾기 활성화
5. (선택) 백신 연동 → 업로드 파일 자동 검사

---

## 📁 파일 구조

```
filestation/
├── index.php                     # 메인 진입점
├── config.php                    # 환경 설정
├── api.php                       # 메인 API 라우터
├── lang.php                      # 다국어 처리
├── share.php                     # 공유 링크 페이지
├── mydav.php                     # WebDAV 서버 엔드포인트
├── onlyoffice.php                # OnlyOffice 콜백
├── office_viewer.php             # OnlyOffice 뷰어
├── hwp_viewer.php                # HWP 뷰어 (간단)
├── rhwp_viewer.php               # rhwp HWP/HWPX 뷰어
├── rhwp_editor.php               # rhwp HWP/HWPX 편집기
├── cleanup_rhwp_studio.php       # rhwp studio 이전 파일 정리
├── sync_cron.php                 # 동기화 CLI 스크립트
├── api/
│   ├── Auth.php                  # 인증/세션 + 회원/2FA/백업코드/비밀번호찾기
│   ├── TOTP.php                  # 2FA TOTP 알고리즘
│   ├── SSOAuth.php               # OIDC SSO
│   ├── FileManager.php           # 파일 작업 (8000+ 라인) + 백신/랜섬웨어
│   ├── ShareManager.php          # 공유 링크 (5종 타입)
│   ├── StorageAdapter.php        # 스토리지 어댑터 (Local/Ftp/Sftp/WebDav/S3/Smb)
│   ├── JobManager.php            # 백그라운드 작업 큐
│   ├── ActivityLog.php           # 활동 로그
│   ├── DebugLog.php              # 디버그 로그
│   ├── FileIndex.php             # 검색 인덱스 (전체 스토리지)
│   ├── IconManager.php           # 파일 아이콘
│   ├── IconUrl.php               # 아이콘 URL
│   ├── JsonDB.php                # 파일 기반 DB
│   └── MinimalSFTP.php           # SFTP 어댑터 보조
├── assets/
│   ├── js/app.js                 # 메인 프론트엔드
│   ├── css/style.css             # 스타일
│   ├── themes/                   # 18종 테마 폴더
│   ├── rhwp/                     # rhwp WASM (HWP 뷰어)
│   │   ├── rhwp.js
│   │   ├── rhwp_bg.wasm
│   │   └── studio/               # rhwp-studio (편집기)
│   └── file-icons/               # 파일 아이콘
├── lang/
│   ├── ko.json                   # 한국어 번역
│   └── en.json                   # 영어 번역
├── templates/                    # 새 파일 만들기용 빈 문서 템플릿
│   ├── empty.docx
│   ├── empty.xlsx
│   ├── empty.pptx
│   └── empty.hwp                 # 한컴오피스 한글 2010 정품 빈 문서
├── data/                         # 사용자 데이터 (DB, 캐시 등)
└── shared/                       # 공유 자원
```

---

## ⚙ 시스템 설정

### config.php 주요 설정

```php
// 사이트 정보
define('SITE_NAME', 'FileStation');
define('APP_VERSION', '5.8.1j');

// 데이터 경로
define('DATA_PATH', __DIR__ . '/data');

// HLS / FFmpeg
define('FFMPEG_BIN', 'C:/ffmpeg/bin/ffmpeg.exe'); // Windows
// define('FFMPEG_BIN', '/usr/bin/ffmpeg');       // Linux

// 7-Zip
define('SEVENZIP_BIN', 'C:/Program Files/7-Zip/7z.exe');

// Rate Limiting
define('API_RATE_LIMIT', 120);  // 분당 요청 수
define('API_RATE_WINDOW', 60);  // 윈도우 (초)
```

### php.ini 권장 설정

```ini
upload_max_filesize = 10G
post_max_size = 10G
memory_limit = -1
max_execution_time = 0
max_input_time = -1
file_uploads = On
```

### Apache (.htaccess)

```apache
# FileStation — PHP 업로드/실행 제한 해제
php_value upload_max_filesize 10G
php_value post_max_size 10G
php_value memory_limit -1
php_value max_execution_time 0
```

### Nginx

`nginx.conf.example` 참조

### IIS (web.config)

업로드 크기 제한 해제 + URL 리라이트 필요. 펜닐님 환경 설정 참조.

---

## 🌐 다국어

| 언어 | 상태 |
|---|---|
| 🇰🇷 한국어 (ko) | ✅ 완전 지원 (1,400+ 번역 키, 기본 언어) |
| 🇺🇸 English (en) | ✅ 완전 지원 |

추가 언어는 `lang/` 디렉토리에 JSON 파일 추가.

---

## 🔌 외부 통합

### OnlyOffice Document Server

```
Docker: onlyoffice/documentserver:latest
JWT 시크릿: config.php의 ONLYOFFICE_JWT_SECRET
SSRF 방어: hex 키 우회 + IP 화이트리스트
```

### rhwp (HWP/HWPX 뷰어)

```
버전: 0.7.10
파일: assets/rhwp/
업그레이드: rhwp_업그레이드_가이드_v5.md 참조
```

### WebDAV 서버

```
엔드포인트: https://your-domain/mydav.php
Windows: net use Z: https://your-domain/mydav.php
macOS: Finder → 서버에 연결 → https://your-domain/mydav.php
```

---

## ⚠ 알려진 제한사항

- ❌ **TUS 재개 업로드 미지원** — 청크 분할 업로드만 지원
- ❌ **모바일/데스크톱 네이티브 앱 없음** — 웹 UI만 (모바일 반응형은 지원)
- ❌ **태그 자동완성 미지원** — 기본 검색은 지원
- ⚠ **JsonDB는 다중 사용자 환경에서 한계** — 수십 명 미만 환경 권장
- ⚠ **HWPX 직접 저장 미지원** — rhwp 0.7.13의 베타 단계 제한, HWP 형식만 저장 가능

---

## 📝 라이선스

**GNU General Public License v3.0 (GPL-3.0)**

Copyright (C) 2026 Pennil

이 프로그램은 자유 소프트웨어입니다. 자유 소프트웨어 재단(FSF)이 발행한 GNU 일반 공중 사용 허가서(GPL) 버전 3 또는 (선택에 따라) 그 이후 버전의 조건 하에서 재배포 또는 수정할 수 있습니다.

이 프로그램은 유용하게 사용될 것을 기대하며 배포되지만, 어떠한 보증도 제공하지 않습니다. 상품성이나 특정 목적에의 적합성에 대한 묵시적 보증조차 제공하지 않습니다. 자세한 내용은 GNU 일반 공중 사용 허가서를 참조하십시오.

전체 라이선스 본문: [LICENSE](LICENSE) 파일 또는 https://www.gnu.org/licenses/gpl-3.0.html

---

This program is free software: you can redistribute it and/or modify it under the terms of the **GNU General Public License v3.0** as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.

### 외부 의존성 라이선스

| 컴포넌트 | 라이선스 | 호환성 |
|---|---|---|
| **rhwp** (HWP/HWPX 뷰어) | MIT — Copyright (c) 2025-2026 Edward Kim | ✅ MIT는 GPL v3과 호환 |
| **OnlyOffice Document Server** | AGPL v3 (별도 서버, Docker) | ✅ 별도 서버 통합 |
| **FFmpeg** | LGPL/GPL (선택 빌드) | ✅ |
| **7-Zip** | LGPL (별도 실행) | ✅ |

---

## 🔄 버전 정보

**현재 버전**: v5.8.1j (rhwp 0.7.13 기반)

### 주요 변경 이력

#### v5.8.1j (2026-05-18) ⭐ 현재

**추가 — [rhwp 0.7.12 → 0.7.13 업그레이드] (2026-05-26)**

펜닐님 요청. 펜닐 룰 부합 (npm 정식 배포 후 진행, 검증된 절차):
- **npm @rhwp/core 0.7.13 tarball** 다운로드 (shasum 검증: f9fd6a31...) — 진본 확인
- **GitHub v0.7.13 태그** clone — rhwp-studio 빌드 입력으로 사용
- **rhwp-studio 빌드** (npm install + tsc + vite build)
  - `vite.config.ts`에 `base: './'` 안전 추가 (옵션 B) — PWA 플러그인 + 커스텀 `serve-samples-dir` 플러그인 + alias(@/@wasm) 보존
- **파일 교체** (assets/rhwp/):
  - core: `rhwp.js` + `rhwp_bg.wasm` (5.06MB, npm tarball md5 일치 — 진본)
  - studio: `index-EQbwmbnL.js` → `index-B0ptdqhv.js`, `index-C_SbAHsx.css` → `index-Dp_1IBLX.css`, `rhwp_bg-BSNi2Fvg.wasm` → `rhwp_bg-CWgV9Qnr.wasm`
  - 정적 자산: favicon.ico + fonts (37파일) + images (1파일) 0.7.13 빌드로 동기화
  - 이전 해시 파일 자동 정리 (각 1개씩만 잔존)
- **패치** (이전 0.7.10~0.7.12 작업과 동일):
  - J1 (Ctrl+S file:save 매핑 제거): ✅ 1개 매칭, 메뉴 정의 유지
  - J2 (Ctrl+P file:print 매핑 제거): ✅ 1개 매칭
  - P2 (CSS url 경로): ✅ `url(../images/)` → `url(images/)` (P1은 0건)
  - 다른 단축키 매핑 15개(Ctrl+Z/Y/A/E/O/B 등) 보존 — 의도한 2개만 제거 확인
- **rhwp_editor.php / rhwp_viewer.php**:
  - editor: studio 파일명 갱신 (3줄) + `@rhwp_version 0.7.13`
  - viewer: `@rhwp_version 0.7.13` (1줄)
  - 커스텀 로직(save/save-as/Blob 캡처/Ctrl+S 핸들러/MutationObserver/notifyParentFileChanged) 무변경 — 백업 대비 diff로 확인
- **0.7.13 변경 사항** (CHANGELOG 사전 점검 — 0.7.12 후속 patch 사이클):
  - HWPX → HWP 저장 호환성 대폭 보강 (표/셀 axis, gradient BORDER_FILL, 메모 컨트롤, 목차 필드 등)
  - HWPX 렌더링 정합 (바탕쪽/머리말꼬리말/문단번호/글상자, exam_kor/exam_social/hwp3-sample16 한컴 변환본 SVG 시각 정합)
  - 페이지네이션·조판 정정 (treat_as_char LINE_SEG, 중첩 표 분할, 그림 pushdown/vpos)
  - rhwp-studio: TAC 도형 커서 이동 개선, Chrome 확장 file:// 접근 안내 (#1131/#1132)
  - 외부 PR #1077/#1078/#1080/#1117/#1120/#1125 등 cherry-pick
  - 이미지 비율 회귀(0.7.6 PR #335류) 신규 없음 — 정정 위주, 렌더링 회귀 없음
- **ABI 호환성**: viewer.php import 심볼(HwpDocument, version, renderPageSvg) 0.7.13 core에 모두 존재 ✓
- **검증** (16/16 통과):
  - editor.php 참조 파일명 ↔ 실제 studio 파일 일치 ✓
  - file:save 메뉴 정의 유지 (마우스 클릭 동작) ✓
  - J1/J2 패치 매칭 + HWPX canExecute 차단 유지(`sourceFormat!==hwpx`) ✓
  - CSS 참조 이미지(icon_small_ko.svg) 실제 존재 ✓
  - url(images/) 정규형 ✓
  - PHP 커스텀 로직 무변경 + 캐시 버스팅 보존 ✓
  - `@rhwp_version 0.7.13` (editor + viewer) ✓
- ⚠️ FileStation 버전 유지 (v5.8.1j — 펜닐 룰, "버전 올려줘" 명시 없음, 재패키징)

**[2차 세션] 자막 컨트롤 iOS 작동 / 배지 용량 단위 / 재생 안정성 (펜닐님 보고)**

펜닐님 보고 다건을 정밀 진단 후 수정. 모두 데이터/실측으로 원인 확정, 추측 수정 0건.

**🟢 A. 자막 크기/위치 조절 4개 버튼 iOS 작동 안 됨**

- **현상**: 일반 동영상 미리보기에서 iOS만 자막 컨트롤 4개 버튼(A-/A+/▲/▼) 무반응. PC는 정상. 같은 영역 싱크 버튼(-0.5s/+0.5s/↺)은 iOS도 정상.
- **원인** (펜닐님 "싱크는 됨" 정보로 확정): 모바일 `@media (max-width:1024px) .subtitle-overlay { font-size: 0.95em !important; bottom: 15% !important }`가 JS의 inline style을 우선순위로 무력화. JS 핸들러는 정상 작동했으나 `overlay.style.fontSize`/`bottom` 변경이 CSS `!important`에 눌려 화면 미반영. 싱크 버튼은 `video._subSyncOffset`/`textContent`만 건드려 무관 → 정상.
- **해결** (`assets/js/app.js` 자막 초기화/핸들러 5곳): `overlay.style.fontSize = ...` → `overlay.style.setProperty('font-size', ..., 'important')`, `bottom`도 동일. inline `!important`가 CSS `!important`를 이김. 버튼 안 누르면 모바일 기본값 유지 → 회귀 없음.
- **자막 첫 기본 위치 분기**: `subBottom` 저장값 없을 때 모바일(≤1024px) 15% / PC 8% (CSS 모바일 기본 15%와 일치). 저장값은 `localStorage !== null`로 판정해 0% 저장도 정상 복원(기존 `||'8'`의 falsy 버그 해소). 한 번이라도 ▲/▼ 조정 시 저장값 우선(모바일/PC/전체화면 공통).
- **자막 A+ 최대 크기**: `Math.min(2.5, ...)` → `Math.min(4.0, ...)` (기본 1.1em 대비 약 3.6배까지).

**🟢 B. 모바일 세로 전체화면 버튼 위치**

- **현상**: 전체화면 버튼이 200px(이전 세션 값)에서 세로로 긴 동영상의 영상 영역에 걸림.
- **해결** (`assets/js/app.js` 2곳): `fsBtn.style.bottom = isMobile ? '200px' : '75px'` → `'100px' : '75px'`. PC 75px 무변경. 세로 모바일 CSS `bottom:8px`는 `!important` 없어 인라인 100px가 정상 우선.

**🟢 C. 일반 재생 배지 용량 단위 (트랜스코딩 배지 제외)**

- **현상**: 일반 재생 배지 용량이 항상 GB 고정 → 100MB가 0.1GB, 100MB 이하 0.0GB로 표시.
- **메인앱 해결** (`assets/js/app.js`): `(fileSize/1024^3).toFixed(1)+'GB'` → `this.formatSize(fileSize)` (코드베이스 공용 함수, B/KB/MB/GB 자동). 100MB→"100.0 MB".
- **공유 페이지 추가** (`share.php` 일반재생 배지): `(코덱 해상도)` → `(코덱 해상도 용량)`. `config.php`의 전역 `formatFileSize($fileSize)` 재사용(share.php가 config.php require). `$fileSize`는 항상 초기화 + `htmlspecialchars` 이스케이프. PHP 실행테스트 5케이스 warning 0.
- **트랜스코딩 배지는 양쪽 제외** (펜닐님 결정). 메인앱 트랜스코딩 배지 실제 표시는 `⚡ HLS 스트리밍 HW : Intel`(인코더 정보만, 코덱/해상도/용량 없음).

**🟢 D. 재생 버튼 더블클릭 / 마우스 채터링 방어 (일반/공유 공통)**

- **현상**: 일반 재생에서 한 번 클릭인데 재생→즉시정지 또는 무반응 (간헐적). 트랜스코딩은 증상 거의 없음.
- **원인** (펜닐님 "일반재생만, 한번 클릭인데 더블클릭 증상" 정보로 확정): `vid.play()`가 비동기(Promise)라, 채터링/더블클릭 시 1번째 클릭=play 시작 → 2번째 클릭 시점엔 `vid.paused=false`라 pause() 실행 → 즉시 정지. 무반응은 play Promise pending 중 pause 끼어들어 AbortError. (바인딩 자체는 방어됨: 일반재생=1회, 트랜스코딩=cloneNode 교체.)
- **해결** (togglePlay 3곳: app.js 일반재생/트랜스코딩, share.php): 핸들러 맨 앞에 300ms 디바운스 — `if (vid._lastToggleAt && Date.now()-vid._lastToggleAt<300) return; vid._lastToggleAt=Date.now();`. `_lastToggleAt`은 video/player 객체 속성이라 video 교체 시에도 안전(새 객체 첫 클릭 통과). 다른 재생경로(자동재생/미디어세션/키보드)는 togglePlay 안 거쳐 영향 0.
- **검증**: 시뮬레이션 — 단일클릭 통과 / 더블클릭 차단(재생 유지) / 시간차(300ms↑) 정지 정상.

**보류 (펜닐님 "패스" 결정):**
- 재생버튼(▶)과 브라우저 네이티브 로딩 인디케이터(검은 호) 중심 어긋남 — PC/iOS 공통, 잠깐 표시. 우리 코드에 영상 위 회전 스피너 요소 없음 확인 → 브라우저가 video 위에 직접 그리는 네이티브 인디케이터. CSS/JS 위치 제어 불가(표준 방법 없음). 멀쩡한 video-not-ready 로직 안 건드림.

**오진단 정정 (자기 점검):**
1. 자막 4개 버튼 → "iOS click 이벤트 문제" 추정 → 펜닐님 "싱크는 됨"으로 정정, 실제는 CSS `!important`.
2. 트랜스코딩 배지에 "코덱 있다" 단언 → 실제 `⚡ HLS 스트리밍 HW : Intel`(인코더만). 펜닐님 지적 정정.
3. 재생버튼 → "더블 바인딩" 의심 → 코드 추적 결과 바인딩 방어됨, 실제는 play() 비동기+디바운스 부재. 펜닐님 정보로 확정.

**안전성:** 변경 파일 app.js/share.php 2개만(CSS/config/api 무변경). app.js `node --check` OK, share.php/config.php `php -l` OK. 인증/escape/보안함수 무변경. 검증영역(togglePlay 본체/_isReady 가드/cloneNode 바인딩) 무변경 — 가드만 앞에 추가.

---

**[1차 세션] HLS ffmpeg 자동 종료 신뢰성 개선 — 비정상 종료 케이스 + 다른 환경 권한 문제 해결**

펜닐님 질문 "동영상 닫히면 ffmpeg가 자동 종료되어 자원 문제 해결되는가?" 에 대한 정밀 검토 후 발견된 6개 케이스 중 5개 해결.

**검토 배경:**
- 펜닐님 환경 (Windows + Apache + 단일 사용자)에서는 정상 동작 확인됨
- 그러나 다른 환경 (Linux + nginx + PHP-FPM, Synology DSM, Docker 등)에서는 비정상 종료 시 ffmpeg 잔존 가능성 발견
- 6개 케이스 분석: A(모바일 끊김) / B(브라우저 충돌) / C(iOS 백그라운드 종료) / D(마지막 사용자 후 새 요청 없음) / E(권한 실패) / F(taskkill 실패)

**🟢 1. HLS orphan 세션 자동 청소 (옵션 C — 케이스 A/B/C/D 해결)**

- **문제**: 기존 `hlsCleanupStale`은 **새 HLS 요청 시에만 호출됨**
  - → 비정상 종료(브라우저 충돌/iOS 백그라운드/네트워크 끊김) 후 새 HLS 요청 없으면 ffmpeg가 계속 살아 자원 점유
- **해결 (`api.php` storages case)**:
  - 페이지 로드 시 반드시 호출되는 `storages` 액션에서 청소 시도
  - 5분 throttle (`data/hls_last_cleanup.txt`)로 호출 빈도 제한
  - try-catch로 청소 실패 시 응답 무영향
  - 활성 세션은 `last_access.txt` 기반으로 보호됨
- **효과**:
  - A/B/C: ✅ 10분 대기 → **다음 페이지 로드 시 즉시 청소**
  - D: 🟡 영구 → **다음 방문 사용자 있을 때 청소** (실용적 해결)
- **안전성**:
  - 기존 `hlsCleanupStale()` 로직 무변경 (검증된 코드 재사용)
  - 5분 throttle로 성능 영향 0
  - try-catch 격리, LOCK_EX 파일 쓰기로 동시성 안전
  - `data/.htaccess`가 외부 접근 차단, 인증(requireLogin) 후 호출

**🟢 2. 케이스 E 해결 — `last_access.txt` 갱신 권한 실패 대응**

- **환경**: nginx+PHP-FPM 워커가 다른 사용자, Synology DSM 시스템 사용자 분리 등
- **증상**: `@file_put_contents()` 조용히 실패 → hlsCleanupStale 2단계가 30분 maxAge만 사용 → 정상 재생 중인 세션도 30분 후 강제 종료 → **30분 이상 영상 시청 시 끊김** ⚠️
- **해결 (`api/FileManager.php` hlsCleanupStale 2단계)**:
  - **2-A 추가**: ts 세그먼트 파일(`stream*.ts`) mtime을 idle 판정 보조 신호로 사용
  - ffmpeg가 ts 파일 생성/갱신 → ffmpeg 권한으로 생성 → `last_access.txt` 권한과 무관
  - 마지막 ts mtime 10분 이내 = 재생 중 → 보호 / 10분 초과 = 고아 → 정리
  - **2-B**: ts 파일도 없는 손상 케이스는 기존 30분 maxAge 로직 fallback으로 유지

**🟢 3. 케이스 F 해결 — Linux/Unix taskkill 실패 fallback 추가**

- **환경**: Linux+nginx+PHP-FPM 워커가 ffmpeg 실행 사용자와 다른 경우, Synology http 사용자 등
- **기존 코드**: Windows는 `wmic`+`PowerShell` fallback 있음, **Linux는 fallback 없음** ⚠️
- **해결 (`api/FileManager.php` hlsCleanup Linux `else` 경로)**:
  - Windows 경로(`if`): 변경 없음 — 펜닐님 환경 영향 0
  - Linux/Unix `else` 경로에 sessionId 기반 ffmpeg 재검색 추가:
    - 방법 2-1: `pgrep -af ffmpeg` (대부분 Linux/Synology 사용 가능)
    - 방법 2-2: `ps -ef` fallback (BusyBox 등 pgrep 미설치 환경)
    - 발견 후 `posix_kill` → `exec('kill -9')` → `shell_exec('kill -9')` 우선순위
- **보안 — 방어 심층**:
  - sessionId 추가 sanitize (`preg_replace('/[^a-zA-Z0-9_-]/', '', ...)`)
  - 원본 sessionId와 일치 여부 검증 (불일치 시 작업 안 함)
  - basename($dir) 경로로 호출돼도 안전

**환경별 영향 종합:**

| 환경 | A | B | C | D | E | F |
|---|---|---|---|---|---|---|
| Windows (펜닐님) | ✅ | ✅ | ✅ | 🟡 | (해당없음) | (해당없음) |
| Linux 일반 | ✅ | ✅ | ✅ | 🟡 | ✅ 해결 | ✅ 해결 |
| nginx+PHP-FPM | ✅ | ✅ | ✅ | 🟡 | ✅ 해결 | ✅ 해결 |
| Synology DSM | ✅ | ✅ | ✅ | 🟡 | ✅ 해결 | ✅ 해결 |
| Docker | ✅ | ✅ | ✅ | 🟡 | ✅ 해결 | ✅ 해결 |

🟡 D (영구 잔존): 마지막 사용자 후 0명 방문 케이스 — cron만 완전 해결 가능. 다만 누구든 방문 시 해결됨.

**변경 파일:**
- `config.php`: APP_VERSION `5.8.1i` → `5.8.1j`
- `api.php`: storages case에 hlsCleanupStale throttled 호출 추가 (try-catch 격리)
- `api/FileManager.php`:
  - hlsCleanupStale 2단계에 ts mtime 기반 idle 판정 추가 (케이스 E)
  - hlsCleanup Linux else 경로에 sessionId 기반 ffmpeg 재검색 fallback 추가 (케이스 F)
- `assets/js/app.js` (FSAudioPlayer 클래스):
  - 생성자: artwork maintenance 인스턴스 변수 4개 추가
  - `_updateMediaSession`: `_currentMetadata` 저장 + `_startArtworkMaintenance()` 호출 추가
  - 신규 메서드 3개 추가: `_startArtworkMaintenance` / `_checkAndRestoreArtwork` / `_forceRefreshArtwork`
  - `_loadTrack`: 트랙 변경 시 `_lastForceRefreshMin` 리셋
  - `destroy()`: artwork maintenance 타이머 정리 + metadata null
- `assets/css/style.css`:
  - 모바일 비디오 미리보기 시 modal-body 높이 고정 (컨트롤 위치 고정, 방안 C)
  - 모바일 세로 작은 영상에서 wrap이 flex shrink되지 않도록 align-self:stretch (컨트롤 shrink 방지 후속 수정)

**펜닐 룰 준수:**
- ✅ Windows 경로 완전 무변경 (펜닐님 환경 회귀 위험 0)
- ✅ 펜닐님 검증된 영역 (FileManager 코어, 1단계 종료 로직) 무변경
- ✅ 보안 코드 무변경, sanitize는 강화만
- ✅ `function_exists` 체크로 `disable_functions` 환경 안전
- ✅ 추측 수정 0건 — 펜닐님 질문 기반 정밀 진단 후 안전한 fallback만 추가
- ✅ 최소 변경: Windows 경로 0, Linux 경로 fallback만 추가

**🟢 4. iOS 잠금화면 음악 썸네일 사라짐 수정 (z_music 검증 패턴 이식)**

펜닐님 보고: "긴 음악 파일 재생 시 아이폰 잠금화면 썸네일이 나오다가 안 나옴".

- **원인 정밀 진단**:
  - iOS Safari는 긴 음악 재생(1시간+) 시 메모리 관리로 MediaSession metadata 캐시 무효화
  - FileStation의 `_updateMediaSession`은 트랙 변경/visibilitychange 이벤트 기반만 호출 → 시간 경과 시 metadata 잃어버려도 갱신 안 됨
  - 펜닐님 다른 프로젝트(Rhymix CMS의 `z_music`, `simple_mp3_player`)는 이미 동일 문제로 30초 체크 + 2분 강제 갱신 패턴 적용됨 (검증 완료)
  - **FileStation에는 미적용** — 이번 v5.8.1j에서 동일 패턴 이식
- **해결 — z_music 검증 패턴 그대로 이식 (`assets/js/app.js` FSAudioPlayer 클래스)**:
  - 생성자: 인스턴스 변수 `_artworkMaintenanceTimer`, `_lastArtworkCheck`, `_currentMetadata`, `_lastForceRefreshMin` 추가
  - `_updateMediaSession`: metadata 설정 후 `_currentMetadata` 저장 + `_startArtworkMaintenance()` 호출
  - **신규 메서드 3개 추가**:
    - `_startArtworkMaintenance()`: 30초 setInterval 타이머 시작 (재생 중일 때만, 25초 throttle)
    - `_checkAndRestoreArtwork()`: artwork 손실 감지 + 2분 경계마다 강제 갱신 (재생 시간 기준)
    - `_forceRefreshArtwork()`: `_currentMetadata`로 metadata 재설정 (z_music 동일)
  - `_loadTrack`: 트랙 변경 시 `_lastForceRefreshMin = -1` 리셋 (이전 트랙 분 경계 제거)
  - `destroy()`: `clearInterval` + `_currentMetadata = null` cleanup (메모리 누수 방지)
- **효과**:
  - iOS 잠금화면에서 1시간+ 음악 재생 시에도 썸네일 유지
  - 30초마다 손실 감지 → 즉시 복구
  - 2분마다 강제 갱신 → 시스템 레벨 무효화 대응
- **안전성**:
  - z_music에서 검증된 패턴 그대로 이식 (펜닐님 실사용 확인됨)
  - 기존 `_updateMediaSession` 함수 본체 무변경 (metadata 객체 추출 + 2줄만 추가)
  - 재생 중일 때만 동작 (`audio.paused` 체크) → 배터리 영향 최소
  - 25초 throttle로 호출 빈도 제한
  - 인스턴스 변수 (window 전역 안 씀) → 다른 페이지 영향 0
  - iOS 외 환경 (안드/PC)도 안전 (mediaSession 표준 동작)
- **펜닐 룰 준수**:
  - 검증된 영역 (모바일 가로 UI, _vsRender, FSAudioPlayer 코어) 무변경
  - 추측 수정 0건 — z_music 검증 패턴 정확 이식
  - 보안 코드 무변경 (PHP/Windows 무관, JS만)

**🟢 5. 모바일 세로 동영상 미리보기 컨트롤 위치 고정 (방안 C)**

펜닐님 보고: "모바일 세로모드에서 자막/전체화면 등 컨트롤이 동영상 크기에 따라 움직임. PC처럼 화면(모달) 고정 위치 원함".

- **원인 정밀 진단**:
  - 모바일 `.modal-preview .modal-body`가 `height` 없이 `max-height: calc(100vh-60px)`만 있어서 비디오 크기에 따라 modal-body 높이 가변
  - → 그 안의 `.video-player-wrap`(height:100%)도 가변
  - → 모든 컨트롤(자막/전체화면/인코더 뱃지/화질/오디오)이 wrap 기준 absolute라서 비디오 크기 따라 이동
  - 데스크탑은 `.modal-preview { height: 80vh }` 고정이라 컨트롤도 고정 → 환경 차이 발생
- **해결 (`assets/css/style.css` 모바일 영역)**:
  - 비디오일 때만 modal-body를 화면 높이로 고정 → wrap 고정 → 모든 컨트롤 위치 일괄 고정
  - 선택자: `.modal-preview:not(.preview-immersive) .modal-body:has(.video-player-wrap) { height: calc(100vh - 60px) !important; }`
- **효과**:
  - 자막/전체화면/인코더 뱃지/화질/오디오 컨트롤 모두 PC처럼 모달 기준 고정
  - 세로/가로 동영상 비율 무관하게 일관된 위치
- **안전성**:
  - `:has(.video-player-wrap)`로 비디오 미리보기만 영향 → 이미지/PDF/오디오 미리보기 무영향
  - `:not(.preview-immersive)`로 모바일 가로 immersive 모드 제외 (v5.8.1i 작업 보존)
  - 컨트롤 CSS 자체 무변경 (부모 modal-body 높이만 고정)
  - `:has()` iOS 15.4+ 지원 (펜닐님 iOS 18.5 완전 지원, 코드베이스 18곳 사용 중)
- **펜닐 룰 준수**:
  - 컨트롤 위치 CSS 무변경 (근본 원인인 modal-body 높이만 수정)
  - 모바일 가로 immersive (v5.8.1i 검증됨) 제외 처리
  - 추측 수정 0건 — 근본 원인 진단 후 최소 변경

- **후속 수정 (작은 세로 영상 컨트롤 shrink, 펜닐님 2차 보고)**:
  - 증상: modal-body 고정했는데도 H264 1280x720 같은 작은 영상에서 전체화면/배지/화질/오디오 컨트롤 전체가 영상 영역으로 줄어듦
  - 원인: `.video-player-wrap`이 `#preview-content`(display:flex, align-items:center)의 flex item이라, 영상이 작으면(max-height:70vh 미달) wrap이 영상 높이로 shrink → wrap 기준 absolute 컨트롤도 같이 줄어듦. modal-body는 고정됐지만 그 안 wrap이 flex로 줄어드는 건 못 막았음
  - 데스크탑이 정상인 이유: 데스크탑 `.preview-video`는 `height: 100%`라 영상이 wrap을 꽉 채움. 모바일은 `max-height: 70vh`(height 없음)라 작은 영상 시 wrap이 shrink
  - 해결: 모바일 세로에서만 wrap을 `align-self: stretch !important; height: 100% !important`로 부모 꽉 채움 + `#preview-content { align-items: stretch !important }`. 영상 자체는 wrap 안에서 `object-fit:contain`으로 가운데 유지
  - 선택자: 단일 영상(`#preview-content > .video-player-wrap`) + 재생목록(`#preview-content > .fs-vp-flex > .video-player-wrap`) 둘 다 커버
  - 안전: `@media (max-width:1024px)` 안에만 존재 → 데스크탑 무영향. `:not(.preview-immersive)` → 가로 immersive 무영향. 영상 크기 CSS 무변경 (wrap 높이만 stretch)

**캐시 무효화:** `APP_VERSION` `5.8.1i` → `5.8.1j`

---

#### v5.8.1i (2026-05-15 ~ 2026-05-18)

**모바일 가로 모드 음악 플레이어 / 동영상 터치 UX 개선 (펜닐님 보고)**

펜닐님 보고 2건을 정확 진단 후 수정. 추측 수정 0건.

**1. 모바일 가로 일반 음악 플레이어 재생목록 안 보임 문제**

- **현상**: 모바일 가로 모드에서 일반(미공유) 음악 미리보기 모달 열면 재생화면이 화면을 거의 차지해 재생목록이 안 보임. 공유 페이지(share.php)는 무관 (별도 페이지 + 별도 구조).
- **1차 시도** (CSS overflow-y: auto 추가): PC 크롬 모바일 모드(휠)는 작동, 실제 모바일 터치 스크롤 안 됨 (펜닐님 1차 검증 보고)
- **2차 시도** (.modal-body flex-start): 모바일 터치 스크롤은 됐지만 재생목록이 첫 13개만 보임 (펜닐님 2차 검증 보고)
  - 원인: `.fap-playlist-list`는 가상 스크롤(`_vsRender`) 컨테이너인데, `.fap` height auto로 만들면서 자체 스크롤 트리거 못 함 → `list.scrollTop = 0` 고정 → 첫 viewH 만큼만 렌더링
- **3차 해결 (`assets/css/style.css` 라인 11015 부근, `@media (orientation: landscape) and (max-width: 1024px)` 안):
  - 2차 수정 유지: `.modal-body:has(.preview-audio-wrap)` `align-items: flex-start` (모바일 터치 스크롤 정상)
  - 2차 수정 유지: `.fap` / `#fs-audio-player` / `.preview-audio-wrap` `height: auto + min-height: 100%`
  - **추가**: `.fap-playlist-list` `max-height: 60vh !important` + `-webkit-overflow-scrolling: touch`
    → 재생목록이 자체 스크롤 영역(60vh) 확보 → 가상 스크롤 정상 동작 → 200곡 모두 렌더링
- **펜닐님 검증된 영역 무변경**: 가상 스크롤 로직(`_vsRender`, `_VS_ITEM_H`, `_VS_OVERSCAN` 등 라인 3706~) 그대로 유지
- **영향 격리** (`:has()` 선택자 사용, iOS Safari 15.4+ 지원, 펜닐님 환경 iOS 18.5/26.5 모두 지원):
  - 음악 미리보기 시(`:has(.preview-audio-wrap)`)만 매칭 → 이미지/동영상/PDF 미리보기 영향 0
  - 가로 모드(orientation: landscape) + 모바일(max-width: 1024px) 한정 → 세로/PC 영향 0
  - `.modal-preview` 한정 → 공유 페이지/다른 모달 영향 0

**2. 모바일 가로 동영상 화면 터치 시 헤더/푸터 무반응 문제**

- **현상**: 모바일 가로 모드 동영상 미리보기에서 동영상 화면을 터치하면 헤더/푸터가 안 나타남. 동영상 외 영역(검정 띠 등) 터치해야 헤더/푸터 토글됨.
- **원인** (펜닐님 자체 주석으로 확인됨, 라인 21950: "video controls가 click을 가로채기 때문"):
  - `_setupPreviewImmersive`의 `toggleBars`는 `body.addEventListener('click', ...)` 등록
  - video 태그가 click 이벤트를 controls 표시용으로 가로채 → toggleBars까지 이벤트 도달 안 함
- **해결 (`assets/js/app.js` 라인 30378 부근):
  - 기존 `click` 이벤트는 유지 (PC 마우스 클릭 정상 작동)
  - `touchstart` 이벤트 추가 등록 — video 위 터치도 잡힘
  - touch 후 자동 발화되는 click과 중복 발화 방지 — `_fsRecentTouch` 플래그(500ms) 사용
  - cleanup 시 두 이벤트 모두 제거 + 타이머 정리
- **기존 코드와 공존**:
  - 라인 34490의 `wrap.touchstart` 핸들러(영상 컨트롤바 show-controls 토글)는 그대로 유지 — 충돌 없음
  - video 위 터치 시: 라인 34490이 영상 컨트롤바 표시 + 새 핸들러가 헤더/푸터 토글 — 펜닐님 의도와 일치
- **영향 격리** (펜닐 룰 부합):
  - `preview-immersive` 클래스 있을 때만 발화 — 일반 모드 영향 0
  - `toggleBars` 내부 `e.target.closest('button, a, input, select, .modal-close')` 가드 유지 — 컨트롤 버튼 클릭 무관

**추가 — v5.8.1i 자체 부작용 수정: 탭/스크롤 구분 패턴 적용 (펜닐님 보고)**

자기 검토 후 펜닐님 검증으로 부작용 실제 발생 확인 → 수정.

- **부작용 (펜닐님 보고)**:
  - 1번 작업으로 음악 미리보기 모달이 모바일 가로에서 스크롤 가능해짐
  - 2번 작업으로 `body.touchstart`가 등록되어 immersive 모드에서 즉시 `toggleBars` 발화
  - 펜닐님이 재생목록 스크롤 시도 → 터치 발생 → 헤더/푸터 토글
  - **펜닐님 보고**: "스크롤할려고 하니깐 터치가 들어가다 보니 헤더, 푸터가 나왔다 사라졌다가 하는 게 있구나"
- **펜닐님 의도 명확화** (재질문 후 확인):
  - 탭 (가만히 누르기): 헤더/푸터 토글 (음악 포함 모든 미디어)
  - 드래그/스크롤: 토글 안 함
  - 표준 모바일 UX 패턴 (YouTube/Netflix/Apple Music 모두 동일)
- **해결 (`assets/js/app.js` `_setupPreviewImmersive` 함수 안)**:
  - `touchstart`: 시작 위치(`_fsTouchStartX/Y`) 기록만, toggle 호출 안 함
  - `touchmove`: 시작 위치 대비 이동량 측정, 10px 초과 시 `_fsTouchMoved = true`
  - `touchend`: `_fsTouchMoved` false면 탭 판정 → `toggleBars` 호출, true면 드래그 → 무시
  - `_fsRecentTouch` 플래그(500ms)로 touch 후 자동 발화되는 click 차단 (중복 방지)
  - TAP_THRESHOLD_PX = 10px (표준 패턴)
- **영향 검증**:
  - 음악 모달 탭: 토글 ✓ (펜닐님 의도)
  - 음악 모달 재생목록 스크롤: 토글 안 함 ✓ (펜닐님 보고 해결)
  - 동영상 화면 탭: 토글 ✓ (v5.8.1i 2번 의도 유지)
  - 동영상 화면 드래그: 토글 안 함 ✓ (의도된 동작)
  - 이미지/PDF 미리보기 탭: 토글 ✓
  - 이미지 핀치 줌/스와이프: 토글 안 함 ✓
  - PC 마우스 클릭: 토글 ✓ (`onClickToggle` 그대로)
  - X 버튼(`.modal-close`): 기존 가드로 정상

**추가 — [rhwp 0.7.11 → 0.7.12 업그레이드] (2026-05-18)**

펜닐님 요청. 펜닐 룰 부합 (npm 정식 배포 후 진행, 검증된 절차):
- **npm @rhwp/core 0.7.12 tarball** 다운로드 (shasum 검증: 1ca43a89...) — 진본 확인
- **GitHub v0.7.12 태그** clone — Cargo.toml/package.json 버전 0.7.12 확인
- **rhwp-studio 빌드** (npm install + tsc + vite build)
  - `vite.config.ts`에 `base: './'` 추가 (상대경로 ./assets/... 출력, FileStation 경로와 일치)
  - PWA 플러그인 + 기존 alias (@/@wasm) 보존
- **파일 교체** (assets/rhwp/):
  - core: `rhwp.js` (234K → 240K) + `rhwp_bg.wasm` (4.5M → 4.4M, -100KB) — 0.7.12 LTO+strip 최적화
  - studio: `index-1fhO7yjt.js` → `index-EQbwmbnL.js`, `index-DMFL0yRA.css` → `index-C_SbAHsx.css`, `rhwp_bg-B9Ehujv1.wasm` → `rhwp_bg-BSNi2Fvg.wasm`
  - 정적 자산: favicon.ico + fonts (37파일) + images (1파일) 0.7.12 빌드로 동기화
- **패치** (이전 0.7.10/0.7.11 작업과 동일):
  - J1 (Ctrl+S file:save 매핑 제거): ✅ 1개 매칭, 메뉴 정의 유지
  - J2 (Ctrl+P file:print 매핑 제거): ✅ 1개 매칭
  - P2 (CSS url 경로): ✅ `url(../images/)` → `url(images/)` 1건 (P1은 0건)
- **rhwp_editor.php / rhwp_viewer.php**:
  - studio 파일명 갱신 (sed 자동)
  - 주석: `@rhwp_version 0.7.11` → `0.7.12`
  - 커스텀 단축키 핸들러 + 캐시 버스팅 코드 무변경 (펜닐 룰 부합)
- **0.7.12 변경 사항** (CHANGELOG 사전 점검):
  - Issue #952 5개 분리 결함 완결 (Issue #956~#964)
  - WMF SetTextAlign vertical bits 정정 (#966)
  - HWP3 sample18 inflate 정정 (#968)
  - release 빌드 LTO + codegen-units=1 + strip → CLI -28% / WASM -6.5%
  - rhwp-studio 신규: F5 본문 블록 + F3 영역 확장 + 메뉴 hotkey + searchAllText API
  - 외부 PR 19+ cherry-pick (cargo test 1288 통과, 회귀 0)
- **검증**:
  - file:save 메뉴 정의 유지 (마우스 클릭 동작) ✓
  - J1/J2 패치 매칭 ✓
  - url(images/) 정규형 ✓
  - PHP 커스텀 로직 무변경 (캐시 버스팅 13건, 커스텀 단축키 56건) ✓
  - `@rhwp_version 0.7.12` (editor + viewer) ✓

**캐시 무효화:** `APP_VERSION` `5.8.1h` → `5.8.1i`

---

#### v5.8.1h (2026-05-14)

**모바일 백그라운드 복귀 시 "스토리지를 선택하세요" 멈춤 증상 진단 + 수정**

펜닐님 보고: 모바일에서 다른 탭에 30분+ 머물다 FileStation 복귀 시 "스토리지를 선택하세요" 탐색기 화면에서 멈춤, 새로고침해야 정상 (간헐적 증상). 추측 수정 절대 금지 원칙에 따라 디버그 로그 시스템 구축 → 정확 진단 → 정확 수정 순서로 진행.

**[34th rev] PC 미리보기 모달 영상 비율 (팟플레이어 동작) 보완 (v5.8.1g 후속)**

v5.8.1f에서 4:3/1:1 영상 세로 잘림 해결 위해 `.preview-video`의 `width: 100%; height: 100%` 제거. v5.8.1g 검증 중 부작용 발견 (펜닐님 보고): 작은 영상(720x480, 853x480 등)이 자연 크기로만 표시되어 모달 안에 작게 나타남.

- **펜닐님 F12 측정으로 정확 진단** (추측 수정 아님):
  - 853x480 영상: wrap은 1177x666으로 정확히 계산되지만 video는 자연 크기 853x480만 표시 → 좌우 공간 큼
  - 640x480 영상: wrap은 960x720 (4:3 정확), video는 640x480 자연 크기 → 작게 보임
  - 원인: `_fitVideoToModal()`이 wrap을 영상 비율로 정확히 계산하지만, video가 `width:100%; height:100%` 없어서 wrap을 채우지 않음
- **해결**:
  - `.preview-video`에 `width: 100%; height: 100%` 복원
  - video 인라인 style 2곳(초기 생성 라인 33018, 트랜스코딩 fallback 라인 33916)에 `width:100%;height:100%` 복원
  - `object-fit: contain`이 유지되어 비율 잘림 절대 없음 (v5.8.1f 시나리오 재발 방지)
- **검증된 동작 (펜닐님 직접)**:
  - 16:9 영상: 모달 꽉 채움 ✓
  - 4:3 영상: 세로 꽉 + 좌우 검정 띠 ✓
  - 1:1, 21:9 등 모든 비율: 비율 유지하며 최대 확대 ✓
  - "팟플레이어 같다" 확인 ✓

**모바일 백그라운드 복귀 진단 시스템 (임시 진단 도구)**

펜닐님 보고: 모바일에서 다른 탭 30분+ 머물다가 FileStation 복귀 시 "스토리지를 선택하세요" 화면에서 멈춤, 새로고침해야 정상 (간헐적, 모바일에서 F12 불가). 추측 수정 안 함, 정확 진단 위한 서버 로그 시스템 구축.

- **신규 action**: `api.php` `?action=debug_log`
  - `noAuthActions`, `csrfExclude` 모두 포함 → 인증/CSRF 무관 동작 (세션 만료 케이스도 진단 가능)
  - JSON body 수신 → `data/debug_logs/YYYY-MM-DD.log`에 한 줄 JSON으로 append
  - 보안: 페이로드 4KB 제한, 일일 로그 10MB 제한, `data/.htaccess` 외부 접근 차단(기존)
  - 영구 보존 (펜닐님 결정, 수동 제거)
- **ON/OFF 제어 (펜닐님 결정 방식)**:
  - **폴더 존재 여부**로 ON/OFF 결정 (`data/debug_logs/`)
  - **ON**: 폴더 생성 → `mkdir data/debug_logs` → 페이지 새로고침 → 로그 기록 시작
  - **OFF**: 폴더 삭제 → `rmdir data/debug_logs` → 페이지 새로고침 → 로그 기록 안 함
  - OFF 시 클라이언트: 페이지당 fetch 1회 (`disabled: true` 응답 받음) → 그 후 호출 안 함 (네트워크 부하 0)
  - OFF 시 서버: `is_dir()` 체크 1번만 → 즉시 종료 (CPU 부하 0)
  - 코드는 그대로 유지 — 추후 진단 필요 시 폴더만 다시 생성하면 즉시 활성화
- **클라이언트 진단 시점 (`app.js`)**:
  - `init_start` / `init_end`: 페이지 새로 로드 + 성능 정보
  - `pageshow`: BF Cache 복원 (`event.persisted`) + 인증/스토리지 상태
  - `popstate`: 브라우저 뒤로가기 + history state 내용 (storageId 누락 진단)
  - `visibility_visible` / `visibility_hidden`: 탭 보임/숨김
  - `loadStorages_start` / `loadStorages_response` (강화) / `loadStorages_retry_scheduled` / `loadStorages_failed_final`: 스토리지 API 호출 추적 (storagesKeys, firstHomeId, firstHomeIdType, elapsedMs 응답 시간)
  - `loadStorages_hashRestore_try` / `loadStorages_hashRestore_exists`: hash 기반 복원 추적
  - `loadStorages_done`: 최종 currentStorage 값
  - `selectDefaultStorage` / `selectDefaultStorage_all_empty` / `selectStorage_called`: 스토리지 선택 시점
  - `boardHash_no_homeStorageId`: boardHash + homeStorageId 누락 케이스
  - **`show_select_storage_msg`** ⭐ : 펜닐님 증상 발생 시점 (stack trace 포함)
- **403 에러 픽스**: 초기 구현 시 `debug_log`를 `noAuthActions`에만 포함하고 `csrfExclude` 누락 → POST CSRF 검증 단계에서 403. 동일 ZIP 패키지 내 수정 완료

**"스토리지를 선택하세요" 증상 진단 완료 및 수정**

펜닐님 보고 증상 ("스토리지를 선택하세요" 잠깐 보였다가 파일 리스트로 교체)을 디버그 로그 분석으로 정확 진단.

- **진단 (추측 아님)**:
  - 펜닐님 모바일에서 증상 발생 시 `show_select_storage_msg` 디버그 로그가 한 번도 안 찍힘
  - 코드 정밀 검토 결과 `index.php` 라인 702에 **HTML 정적 메시지** 발견
  - JS 실행 전 HTML 자체에 박혀있어 펜닐님 진단 로그 추적 경로 우회
- **원인 확정**:
  - 페이지 로드 시점에 `<div class="empty-msg">스토리지를 선택하세요</div>` HTML로 즉시 표시
  - JS의 `init() → loadStorages() → selectStorage() → loadFiles()` 흐름이 완료되면서 자동으로 파일 리스트로 교체됨
  - 평소: 빠른 교체로 사용자 인지 못함
  - 모바일/네트워크 느림 시: JS 실행 지연으로 수초 동안 메시지 노출 → 펜닐님이 인지
- **수정**: `index.php` 라인 702 HTML 정적 메시지를 "로딩 중..." (영문 "Loading...")으로 변경
  - 자연스러운 로딩 표시 (혼란 없음)
  - 펜닐님 코드의 다른 곳도 같은 "로딩 중..." 패턴 사용 중 → 일관성 유지
  - JS의 `loadFiles()` 안에 있는 "스토리지를 선택하세요" 메시지(라인 9446)는 **그대로 유지** — 진짜로 스토리지 선택이 필요한 케이스(빈 목록 등)에서는 정확한 메시지 필요
- **변경 범위**: `index.php` 단 1줄
- **펜닐 룰 부합**:
  - 추측 수정 0건 — 디버그 로그로 100% 진단 후 수정
  - 최소 변경 — 단 1줄
  - 다른 기능 영향 없음 — JS 메시지 유지

**모바일 백그라운드 복귀 자동 갱신 (옵션 A, 펜닐님 결정)**

펜닐님 보고 "30분+ 후 복귀 시 파일 리스트 안 보임, 새로고침해야 정상" 증상의 진짜 원인(JS 멈춤)은 간헐적이라 시점 확정 어려움. 펜닐님 결정으로 **로그 기반 합리적 보완** 적용.

- **근거 (디버그 로그로 확인된 사실)**:
  - 펜닐님 BF Cache 코드(`pageshow` + `event.persisted`)가 환경에서 **작동 안 함** (모바일/PC 모두 `persisted: false`로 한 번도 안 찍힘)
  - `visibilitychange` 이벤트는 **정상 작동** (탭 보임/숨김 정상 추적됨)
  - API 응답은 **정상** (success, 데이터 있음, elapsedMs 21~67ms)
  - → BF Cache 코드 보완 필요성 명확 (추측 아닌 데이터 기반)
- **동작**:
  - `visibilitychange` 시 `document.visibilityState === 'visible'` 감지
  - 조건: 로그인 상태 + 게시판 모드 아님 + 마지막 갱신 후 **5분+ 경과**
  - 자동으로 `loadStorages()` + `loadFiles(false)` 호출
  - 펜닐님이 수동 새로고침하던 동작을 자동화
- **안전성**:
  - try/catch로 본 기능 영향 격리
  - 게시판 모드는 건드리지 않음
  - 5분 쿨다운으로 과도한 API 호출 방지
  - `loadStorages()` 호출 시 `_lastRefresh` 자동 갱신 (수동 작업 시 쿨다운 정확도 향상)
- **실증 검증 (펜닐님 PC 로그)**:
  - 00:54:09: elapsedMs=448,553ms (7분 28초) → willRefresh: true → 자동 갱신 정상 발동 확인 ✓
  - `visibility_auto_refresh_start` → `loadStorages_start` → `loadStorages_response` (성공, 67ms) → `loadStorages_done` 흐름 정상
- **추적**:
  - `visibility_refresh_check`: 갱신 검토 시점 (elapsed, threshold, willRefresh)
  - `visibility_auto_refresh_start`: 자동 갱신 시작 시점
  - `visibility_auto_refresh_loadStorages_error` / `visibility_auto_refresh_loadFiles_error`: 에러 발생 시

**debug_log API 세션 활동 시간 갱신 부작용 제거 (펜닐님 결정)**

자기 검토 시 발견된 잠재 부작용을 펜닐님 결정으로 수정.

- **부작용 원인**: `debug_log` case 내부에서 `$auth->isLoggedIn()` 호출 시 부작용 발생
  - `$_SESSION['last_activity'] = time()` 갱신 (세션 무한 연장)
  - `recordSession()` 호출로 sessions DB 테이블 update (불필요한 디스크 I/O)
- **영향**: 디버그 모드 ON 상태에서 디버그 로그가 자주 호출되면 세션 자동 연장됨 (의도되지 않은 부작용)
- **수정**: `$auth->isLoggedIn()` / `$auth->getUser()` → `$_SESSION['user_id']`, `$_SESSION['username']` 직접 체크
  - `$_SESSION['username']`이 모든 로그인 경로(일반 로그인, Remember Me, SSO 등)에서 설정됨 확인 후 적용
  - 결과값 (authed Y/N, user 값) 동일 — 부작용만 제거됨
  - 함수 호출 2회 → 0회 (성능 약간 개선)
- **변경 범위**: `api.php` debug_log case 내부 4줄

**캐시 무효화:** `APP_VERSION` `5.8.1g` → `5.8.1h`

---

#### v5.8.1g (2026-05-12)

**[33rd rev] 동영상 자동 다음 재생 ON/OFF 토글 추가**

**배경:**
- v5.8.1e에서 자동 다음 트랙 재생 기능 도입 (31st rev) — 항상 ON 동작
- 사용자가 자동 재생을 끄고 싶을 때 토글 수단 부재

**추가 기능:**
- 사이드 패널 헤더에 자동 다음 재생 ON/OFF 토글 스위치 추가 (유튜브 스타일 가로 막대 + 동그라미)
- ON 상태: 노란색 트랙 + 우측 동그라미 / OFF 상태: 회색 트랙 + 좌측 동그라미
- 기본값: ON (펜닐님 기존 동작 유지)

**저장 정책 (펜닐님 결정):**
- 일반 페이지: `localStorage` `fs_vp_auto_next` (영구 저장)
- 공유 페이지: `sessionStorage` `share_auto_next` (세션 한정 — 공유는 외부 일회성 사용이라 영구 저장 불필요)
- 두 페이지가 다른 키 이름 사용 → 정책 분리 명확

**동작:**
- 동영상 종료 시 (`ended` 이벤트) 키 체크 → OFF면 다음 트랙으로 안 넘어감
- 사용자가 패널에서 트랙 수동 클릭은 토글과 무관 (수동 클릭은 `ended` 이벤트 안 거침)
- 단일 영상 폴더: 패널 자체가 표시 안 되므로 토글도 무관 (기존 동작)
- 마지막 트랙: 자동 재생 ON이라도 다음 트랙 없으므로 정지 (기존 동작)
- **작은 화면(1024px 이하)**: 사이드 패널 자체가 숨김(`display: none !important`)이라 토글 버튼이 보이지 않음 → **자동 재생 자체를 비활성화** (사용자가 끌 방법이 없으므로). CSS 미디어쿼리 `@media (max-width: 1024px)`와 일치하는 `window.innerWidth <= 1024` 기준. UA 무관 화면 크기 기반 (아이폰/안드로이드/iPadOS 동일)
- 1024px 초과 (PC, 아이패드 가로, 큰 태블릿): 토글 설정에 따름
- 공유 페이지는 별도 — 모바일에서도 패널이 정상 노출되므로 토글 그대로 작동

**적용 범위:**
- `assets/css/style.css`: `.fs-vp-header .fs-vp-autonext` 스타일 추가
- `assets/js/app.js`:
  - 패널 HTML에 토글 버튼 추가 (라인 ~32917 인근)
  - 토글 핸들러 등록 (라인 ~36815 인근)
  - `_fsVpBindAutoNext`의 `ended` 핸들러에 키 체크 추가 (라인 ~36683)
- `share.php`:
  - `.share-playlist-header .pl-autonext` 스타일 추가
  - 패널 HTML에 토글 버튼 추가
  - 토글 핸들러 등록
  - `ended` 핸들러에 키 체크 추가 (라인 ~3096)
- `lang/ko.json`, `lang/en.json`: `autonext_toggle` 키 추가

**추가 수정 — preview-immersive 모드 적용 조건 변경:**

iPad Air 가로(1180×820) 등 1024px 초과 큰 터치 디바이스에서 미리보기 모달 헤더/푸터가 사라지는 증상 (`preview-immersive` 모드가 터치 디바이스 + 가로 조건으로 진입하여 헤더 opacity:0, 푸터 display:none 처리).

- **변경**: `_setupPreviewImmersive`의 isMobile 판정을 `'ontouchstart' in window || window.innerWidth <= 1024` → **`window.innerWidth <= 1024`**로 변경
- **효과**:
  - 1024px 초과 (iPad Air 가로 1180, iPad mini 가로 1133, 큰 태블릿 등): immersive 미적용 → **PC처럼 헤더/푸터 정상 표시**
  - 1024px 이하 (iPhone/안드폰 가로 등): 기존대로 immersive 적용
- **정책 일관성**: 자동재생 토글의 화면 크기 기준 + CSS 미디어쿼리 `@media (max-width: 1024px)`와 완전 일치 (UA/터치 기반 아닌 화면 크기 기반으로 통일)

**추가 수정 — preview-immersive 모드 동작 패턴 변경 (유튜브/넷플릭스 스타일):**

작은 화면 가로모드(iPhone 등)에서 동영상/PDF/이미지 미리보기 모달 열림 시 헤더/푸터가 즉시 숨겨져 파일명/X 닫기/재생속도 등 컨트롤 접근이 어려운 증상.

- **변경 전**: 모달 열림 직후 헤더/푸터 즉시 숨김 → 탭하면 잠깐 표시 (3초 후 다시 숨김)
- **변경 후**: 모달 열림 직후 헤더/푸터 **보임** → 영상 재생 시작 시 3초 후 자동 숨김 → 일시정지/종료 시 다시 보임 → 탭으로 토글 가능
- **유튜브/넷플릭스 패턴**: 컨트롤이 처음엔 보이다가 재생 시작하면 자연스럽게 사라지는 일반적인 영상 플레이어 UX
- **추가 진단/수정**: 푸터가 안 보이는 증상의 근본 원인 발견 — CSS `display: none !important`가 인라인 style을 덮어쓰는 문제. JS를 **`show-bar` 클래스 토글** 방식으로 변경하여 CSS 설계와 일치시킴 (헤더/푸터/줌바 모두 동일 패턴)
- **콘텐츠별 동작**:
  - **동영상**: play 이벤트로 3초 자동 숨김 트리거, pause/ended에서 다시 보임
  - **PDF/이미지/문서**: 자동 숨김 없음 (정적 콘텐츠는 사용자가 탭할 때만 토글)
- **모든 미리보기 모달**에 일관 적용 (펜닐님 결정)

**추가 — 음악 기본 artwork SVG → PNG 변경 (iOS Safari 잠금화면 호환):**

iOS Safari MediaSession API는 SVG data URL을 artwork로 지원 안 함 (iOS 16.1.1 이후 알려진 제약). 커버 아트 없는 MP3 재생 시 iOS 잠금화면에 회색 빈 상자 표시되는 증상.

- **변경**: `_updateMediaSession`의 SVG data URL fallback → **PNG 정적 파일**(`assets/images/default-music-artwork.png`, 512x512, 8.9KB)로 교체
- **디자인**: 기존 SVG와 동일 (배경 `#1e1e2e` 둥근 모서리, 보라색 `#7c6ef6` 음표)
- **호환성**:
  - iOS Safari 잠금화면/제어센터: 정상 표시 ✓
  - 안드 Chrome 잠금화면/알림: 정상 표시 ✓
  - 데스크톱 미디어 컨트롤: 정상 표시 ✓
- **MediaMetadata artwork 배열**: 96x96, 128x128, 256x256, 512x512 메타 제공 (iOS가 적절한 사이즈 선택, 실제 파일은 1개)
- **신규 파일**: `assets/images/default-music-artwork.png` (8.9KB)

**캐시 무효화:** `APP_VERSION` `5.8.1f` → `5.8.1g`

---

#### v5.8.1f (2026-05-12)

**[32nd rev] PC 미리보기 모달 동영상 비율 처리 개선**

**증상 진단:**
- 와이드 동영상 (16:9, 21:9 등) 재생 시 modal-body 상단에 영상이 붙고 세로 가운데 정렬 안 됨 (패널 열림/닫힘 모두)
- 4:3, 1:1 동영상 처음 재생 시 모달 폭에 꽉 차서 영상이 세로로 잘림

**원인 분석:**
- video 인라인 스타일 `width:100%; height:100%`이 `.video-player-wrap` 가득 채우는데, `_fitVideoToModal`이 wrap 크기를 영상 비율로 조정하기 전 시점에 영상이 모달 폭 기준으로 강제 확대됨
- `.fs-vp-flex`의 `align-items: stretch`가 wrap을 세로 가득 stretch → 영상 위에 붙음

**해결 (공유 페이지 패턴 적용):**
- `.preview-video`: `width:100%; height:100%` 제거 → 자연 크기 + `max-width/max-height: 100%`로 wrap 안에 한정, `margin: 0 auto → margin: auto`
- `.fs-vp-flex`: `align-items: stretch → center`, `justify-content: center` 추가 → wrap 세로/가로 가운데 정렬
- `.fs-vp-flex .video-player-wrap`: `flex: 1 1 auto → 0 1 auto` → wrap이 영상 비율로 줄어들도록 (4:3, 1:1 정상 표시 위해 필수 — 펜닐님 테스트로 확정)
- video 인라인 스타일에서 `width:100%; height:100%` 제거 (2곳: 초기 생성 + 트랜스코딩 fallback)

**시나리오 매트릭스 (펜닐님 검증 완료):**

| 비율 | 동작 |
|---|---|
| 와이드 (24:10, 21:9) | 세로 가운데 ✓ |
| 16:9 일반 | 거의 가득, 미세 가운데 ✓ |
| 4:3 | 자연 크기 fit, **잘림 없음** ✓ |
| 1:1 | 양축 가운데 ✓ |
| 세로 (9:16) | 기존 vertical-video 처리 유지 |
| 전체화면 (F11) | `:fullscreen` `!important` 보호 → 영향 없음 |
| pseudo-fullscreen (⛶) | `.pseudo-fullscreen` `!important` 보호 → 영향 없음 |
| 모바일 (1024px 이하) | `max-height: 70vh !important` 보호 → 영향 없음 |

**주의 사항 (잠재 동작 차이):**
- `flex: 0 1 auto` 변경으로 인해 재생 중 패널 자동 숨김 시 영상 폭 확장 동작에 미세한 차이가 있을 수 있음. 펜닐님 테스트에서 정상 동작 확인 — 그대로 유지.

**적용 범위:**
- `assets/css/style.css` 3개 규칙 (`.preview-video`, `.fs-vp-flex`, `.fs-vp-flex .video-player-wrap`)
- `assets/js/app.js` 2곳 (video 인라인 스타일 정리)
- `share.php` 무수정 — 이미 공유 페이지는 동일 패턴 사용 중이라 무변경

**캐시 무효화:** `APP_VERSION` `5.8.1e` → `5.8.1f`

---

#### v5.8.1e (2026-05-06 ~ 2026-05-10)

**최종 상태 요약:** 누적 21개 항목 (자동 다음 트랙 31st + SMI 자막 fix #1/#2 + iOS Safari media_info 폴백 + SMB media_info 보완 + 새 파일 만들기 + CSP wasm-unsafe-eval + 빈 파일 업로드 fix + hwp 템플릿 통합 + create_from_template quota + scanWithDefender 사이즈 비교 + validateMimeType php 항목 정리 + 청크 업로드 로컬 MIME 검증 + i18n/주석 정리 + 동영상 플레이리스트 단일 영상 숨김 + 자동 다음 트랙 자동 재생 + 새 파일 메뉴 아이콘 SVG + 동영상 패널 OFF→ON 가운데 스크롤 + rhwp 0.7.10 → 0.7.11 + hwp/rhwp 미리보기 인쇄 버튼 + 시스템 설정 OnlyOffice 안내 보강 + OnlyOffice 디버그 로그 조건부 활성화)


**[rhwp 0.7.10 → 0.7.11 업그레이드] (2026-05-10)**

**진행:** `rhwp_업그레이드_가이드_v5.md` 표준 절차에 따라 수행.

**변경:**
- `assets/rhwp/rhwp.js`, `rhwp_bg.wasm` 0.7.11 교체 (뷰어용)
- studio 빌드 결과 교체:
  - `index-zIVQkhcx.js` → `index-1fhO7yjt.js`
  - `index-ro3nVBB2.css` → `index-DMFL0yRA.css`
  - `rhwp_bg-BbAYsuOY.wasm` → `rhwp_bg-B9Ehujv1.wasm`
  - fonts/images/favicon 신규 빌드 자산 교체
- `rhwp_editor.php` studio 해시 갱신 (라인 397~398)
- `@rhwp_version 0.7.10` → `@rhwp_version 0.7.11` (editor + viewer)

**패치 적용:**
- J1 (file:save Ctrl+S 매핑 제거): 1개 매칭 → 제거 ✓
- J2 (file:print Ctrl+P 매핑 제거, **신규**): 1개 매칭 → 제거 ✓ (Ctrl+P → 브라우저 기본 인쇄 대화상자로 fallback)
- P1 (절대경로 `/images/`): 0개 매칭 (0.7.11은 절대경로 미사용)
- P2 (상위참조 `../images/`): 다수 매칭 → `images/` 로 정규화 ✓
- A/B (HWPX canExecute / 툴팁): 원본 그대로 유지 (펜닐 룰 — 데이터 손상 방지)

**0.7.11 주요 변경 (회귀 점검 결과):**
- CLI 바이너리 릴리즈 (웹 빌드 영향 없음)
- PNG raster backend (`native-skia` feature gate, opt-in — 기본 빌드 영향 0)
- AI 파이프라인 / VLM 연동 (CLI 전용)
- HWP 5.0 스펙 정정, HWP3 처리, TAC 표 정정 등 (정합성 개선)
- 외부 PR 13건 cherry-pick (모두 정합성/회귀 정정 영역)
- → **렌더링 회귀 없음**, 웹 (rhwp_viewer/editor) 영향 없음

**검증 (15/15 통과):**
- 커스텀 기능 보존: save / save-as / Blob 캡처 / save-as 메뉴 / MutationObserver / 캐시 버스팅 / Ctrl+S 핸들러 / syncSuspended / notifyParentFileChanged / app.js postMessage 리스너 ✓
- 엔진 패치 상태: J1 적용 + A/B 원본 + CSS 경로 정규화 + rhwp 버전 0.7.11 ✓

**작업 이력 메모:**
- 2026-05-10 PDF 내보내기 기능 시도 → 인쇄 대화상자 방식이라 "바로 저장" 안 됨 → 펜닐 결정에 따라 제거
- jsPDF (한글 폰트 이슈), Chrome headless (서버 환경 변경 부담) 등 대안 모두 트레이드오프 큼 → 현재 미구현 상태 유지
- 패치 J2는 PDF 내보내기 작업 중 도입했으나 PDF 제거 후에도 **유지 결정** — Ctrl+P 누를 시 disabled 메뉴로 빠지는 불친절 동작 대신 브라우저 기본 인쇄 대화상자가 열리도록 함
- 2026-05-11 OnlyOffice events 핸들러 추가 시도 → `onDownloadAs` 등록만으로 OnlyOffice가 "호스트 처리" 모드 전환 → 인쇄 미리보기 안 뜨고 바로 PDF 다운으로 동작 변경 → 펜닐 보고로 발견 → 모두 제거 (events 12개 + window.open 가로채기 + api.php onlyoffice_clientlog 액션). 펜닐 룰 위반 사례.

---

**[hwp/rhwp 미리보기 인쇄 버튼 추가] (2026-05-11)**

**기능:** `hwp_viewer.php` (legacy ohah/hwpjs)와 `rhwp_viewer.php` (rhwp WASM) 미리보기에 🖨️ 인쇄 버튼 추가. 클릭 시 브라우저 기본 인쇄 대화상자 → 사용자가 "PDF로 저장" 또는 실물 프린터 선택.

**구현:**
- `rhwp_viewer.php` 라인 292: 다운로드 버튼 옆 `<button id="btn-print">🖨️</button>` 추가, title=`__('viewer_print')` i18n
- 클릭 핸들러 (라인 ~563): 단순 `window.print()` 호출
- `@media print` CSS 추가 (라인 ~245~): `.viewer-header` 숨김 + `.page-container` page-break-after + `@page { margin: 10mm }`
- `hwp_viewer.php` 라인 622: 툴바에 `printDocument()` 호출 버튼 추가, i18n 적용
- `printDocument()` 함수 (라인 ~1205): 단순 `window.print()`
- 기존 `@media print` CSS 보강 (라인 590~): `transform: none !important` (줌 transform 리셋) + `.hwp-page, .HWPPage` 페이지 분리 셀렉터 (안전한 좁은 셀렉터)

**i18n:** 기존 `viewer_print` 키 재사용 (en.json: "Print", ko.json: "인쇄") — office_viewer.php와 동일 키

**Ctrl+P:** 브라우저 기본 동작에 맡김 (별도 핸들러 등록 안 함 → OnlyOffice events 사례처럼 동작 변경 위험 회피)

**효과:** 사용자가 미리보기에서 직접 인쇄/PDF 저장 가능 (이전엔 다운로드 후 외부 앱에서만 가능)

**검증 권장 사항:** `.hwp-page` / `.HWPPage` 셀렉터가 실제 ohah/hwpjs 엔진 출력 클래스와 일치하는지는 미확정 — 매칭 안 되어도 부작용 없음 (페이지 분리 안 되어 한 페이지로 길게 인쇄). 펜닐 검증 후 정확한 클래스 확인되면 조정 가능.

---

**[시스템 설정 OnlyOffice 안내 보강] Apache /printfile/ + Nginx/시놀로지 명시 (2026-05-11)**

**문제:** 펜닐 환경에서 OnlyOffice 9.3.1 인쇄 시도 → 404 발생. 진단 결과 Apache 서브패스 방식(`/oo/`)에서 인쇄용 URL `https://도메인/printfile/...` 가 Apache 리버스 프록시 누락으로 FileStation Apache가 받음 → 404.

**원인 (OnlyOffice 측):**
- OnlyOffice 9.3.1+ 인쇄 동작: 편집기 → Document Server에 PDF 생성 요청 → Document Server가 `/printfile/{key}/file.pdf` 절대경로 URL 생성
- Document Server는 자신이 `/oo` 서브패스에 있는 걸 모르므로 `/oo/printfile/` 이 아닌 절대경로 사용
- Apache 측 `/printfile/` 프록시 누락 시 404

**해결 (펜닐 실제 적용 확인):**
```apache
<Location /printfile/>
    ProxyPass http://OnlyOffice내부IP:8080/printfile/
    ProxyPassReverse http://OnlyOffice내부IP:8080/printfile/
</Location>
```

**FileStation 측 변경 (`index.php` OnlyOffice 설정 안내):**
- Apache 안내문에 `/printfile/` 프록시 블록 추가 (`/cache/` 다음 위치)
- Nginx 안내문에 명시 추가: "`location /` 가 `/printfile/`, `/cache/` 포함 모든 경로를 프록시하므로 인쇄 기능에 추가 설정 불필요"
- 시놀로지 방법1 (서브도메인) 안내에 명시 추가: "서브도메인 방식은 `/printfile/`, `/cache/` 포함 모든 경로를 프록시하므로 인쇄 기능에 추가 설정 불필요"
- 시놀로지 방법2 (서브패스 `/oo`) 경고 보강: "리다이렉트 문제 + `/printfile/` `/cache/` 경로 별도 프록시 항목 필요"

**환경별 설정 필요 여부:**
| 환경 | /printfile/ 추가 |
|---|---|
| Apache 서브패스 (/oo) | 필요 (펜닐 사례) |
| Nginx 서브도메인 (oo.도메인.com) | 불필요 (자동 포함) |
| 시놀로지 방법1 (서브도메인) | 불필요 (자동 포함) |
| 시놀로지 방법2 (서브패스 /oo) | 필요 (안 권장) |

---

**[OnlyOffice 디버그 로그 조건부 활성화] (2026-05-11)**

**목적:** OnlyOffice 인쇄 문제 진단 중 추가. 평소 동작 영향 0, 디버그 필요 시 로그 파일 생성으로 자동 활성화.

**구현 패턴:** `if (file_exists($ooDataDir . '/onlyoffice_debug.log'))` 게이트로 모든 디버그 로그 호출 감쌈.

**적용 위치:**
- `onlyoffice.php` 라인 162~165: OPEN 로그 (파일 열림 시 fileUrl/callbackBase/mode 기록)
- `api.php` 라인 1336~1377: JWT_FAIL × 3 + JWT_BYPASS + DOWNLOAD (Document Server 요청 추적)
- `api.php` 라인 1497~1498: CALLBACK (저장 상태 변화 추적, 기존 무조건 기록을 조건부로 변경)
- `api.php` 라인 1555~1595: SSRF BLOCKED + CURL FAIL + DOWNLOAD OK (저장 시 URL 다운로드 추적)

**사용 방법:**
```cmd
# 디버그 활성화
type nul > data\onlyoffice_debug.log

# 디버그 종료
del data\onlyoffice_debug.log
```

**효과:**
- 평소: file_exists() 체크만 → 동작 영향 거의 0, 디스크 쓰기 0
- 디버그 활성: 자동으로 모든 OnlyOffice 동작 추적, 펜닐 환경의 OnlyOffice 9.3.1 인쇄 404 진단에 핵심 단서 제공

**검증된 사용 사례:** 펜닐 환경에서 OnlyOffice 인쇄 404 진단 → DOWNLOAD/CALLBACK 흐름 확인 → 원인이 FileStation 측 아닌 Apache `/printfile/` 프록시 누락임을 확정.

---

**[동영상 패널 OFF→ON 가운데 스크롤] 영상 재생 후 토글 ON 시 현재 트랙 가운데 (2026-05-10)**

**문제:** 동영상 플레이리스트 패널 OFF 상태에서 영상 재생 시작 후 사용자가 패널 토글 ON 클릭하면, 현재 재생 중인 트랙이 가운데가 아닌 맨 위(scrollTop=0)에 위치 → 어느 영상이 재생 중인지 한눈에 안 보임.

**원인:**
- **메인 (`assets/js/app.js`):** 토글 핸들러(라인 36814~)가 `isOpen=true` 분기에서 스크롤 처리 안 함 (펜닐 의도: "사용자가 한번이라도 스크롤한 후엔 위치 보존")
- **공유 (`share.php`):** `togglePanel()`이 `openPanel(true)` 호출 → `skipScrollAdjust=true` → 스크롤 조정 스킵 (동일 패턴)
- 두 케이스 모두 "사용자 스크롤 후 위치 보존" 의도 보호하느라 "OFF 상태로 시작한 첫 토글 시 가운데" 케이스를 함께 차단

**해결 (사용자 스크롤 추적 플래그):**

**메인 (`assets/js/app.js`):**
- 라인 36791: `_setupVideoPlaylistPanel()` 진입 시 `App._fsVpUserScrolled = false` 리셋 (새 영상마다)
- 라인 36835~: body의 scroll 이벤트 리스너 등록 (50ms 지연 — `_fsVpScrollToCurrent()` 가 만든 자동 스크롤 이벤트 무시) → 사용자 스크롤 시 `true` 세팅
- 라인 36819~: 토글 ON 시 `_fsVpUserScrolled === false` 면 `_fsVpScrollToCurrent()` 호출 (가운데), `true` 면 보존
- 라인 36812: 사용자 패널 트랙 클릭 시 명시 리셋 (펜닐 룰 — 수동 클릭은 자동 재생 안 됨과 같은 보호)

**공유 (`share.php` 라인 3856~3879):**
- IIFE 내부 `let _userScrolled = false` 정의
- body의 scroll 이벤트 리스너 등록 (50ms 지연 — `scrollToCurrentTrack()` 자동 스크롤 무시)
- `togglePanel()` ON 분기 변경:
  - `_userScrolled === false` → `openPanel(false)` (skipScrollAdjust=false) → `applyScrollPosition()` 호출 → 가운데
  - `_userScrolled === true` → `openPanel(true)` (기존 동작 — 위치 유지)

**보존된 펜닐 의도:**
- 사용자가 한번이라도 스크롤하면 OFF→ON 시 위치 유지
- 자동 다음 트랙 시 sessionStorage `SCROLL_POS_KEY` 우선 (펜닐 결정 옵션 B)
- 단일 영상 폴더는 자동 재생 무관 (`folderVideos.length < 2` 체크)

**검증 (펜닐님 직접):** 메인에서 OFF 상태로 영상 시작 → 토글 ON → 현재 트랙 가운데 ✓ 공유도 동일 패턴 적용.

---

**[새 파일 메뉴 아이콘 SVG] 컨텍스트 메뉴 5종 아이콘 → 기존 파일 확장자 아이콘 통일 (2026-05-09)**

**문제:** 우클릭 → 새 파일 만들기 메뉴의 아이콘이 이모지(📄/📘/📊/📑/📄)로 되어 있어, 파일 목록의 SVG 아이콘과 시각적 일관성 부족.

**해결 (`index.php` 라인 5575~5579):** 5개 메뉴 항목을 기존 파일 목록과 동일한 SVG 아이콘으로 교체:

| 확장자 | 아이콘 |
|---|---|
| txt | `text.svg` |
| docx | `word.svg` |
| xlsx | `excel.svg` |
| pptx | `powerpoint.svg` |
| hwp | `hwp.svg` |

**스타일 처리 — 한 줄 정렬 fix:**
- `class="fs-file-icon-img"` **사용 안 함** — 이 클래스는 메인 파일 목록(grid/list view)용으로 `display:block` 적용됨 → 메뉴 `<li>` 안에서는 텍스트가 다음 줄로 밀리는 문제 발생
- 대신 inline 스타일로 `display:inline-block; vertical-align:-3px; width:14px; height:14px; margin-right:5px;` 직접 지정 → 이모지처럼 한 줄 정렬
- `user-select:none; -webkit-user-drag:none;` 드래그 방지

**캐시 버스터:** `?v=<?= APP_VERSION ?>` 적용 (다른 SVG 사용처와 동일 패턴).

**영향 범위:**
- 메뉴 5개 항목만 변경 → 메인 파일 목록은 `fs-file-icon-img` 그대로 사용 → 영향 없음
- CSS 파일 무수정 (펜닐 룰)

---

**[동영상 자동 다음 트랙 자동 재생] 메인 + 공유 페이지 자동 재생 트리거 (2026-05-09)**

**문제:** 동영상 ended 후 다음 트랙으로 전환은 되지만 **자동 재생되지 않고 ▶ 버튼이 보이는 상태로 멈춤**. 사용자가 ▶ 클릭해야 재생 시작.

**원인 ① (메인 페이지):** `_fsVpBindAutoNext()` 의 ended 핸들러가 `App.showPreview(nextFile)` 호출하지만, showPreview는 새 영상을 로드만 하고 자동 재생 의도를 받을 수단이 없었음. 게다가 코덱 체크 가드(라인 32985, 35185)가 `_isReady === false` 상태에서 play 이벤트 발생 시 즉시 `pause()` 호출 → 자동 재생 시도가 가드에 막힘.

**원인 ② (공유 페이지):** ended 핸들러가 `location.href = _folderNextUrl` 로 새 페이지 로드 → 새 페이지 video는 `controls`만 있고 `autoplay` 없음 → 사용자 ▶ 필요.

**해결 ① (`assets/js/app.js`):**
- 라인 36670~: `_fsVpBindAutoNext()` 에서 다음 트랙 전환 시 `App._fsVpPendingAutoPlay = true` 플래그 세팅
- 라인 36705~ 신규 함수 `_fsVpTryAutoPlay()`: 코덱 체크 가드 인지 + 준비 완료 대기 + play() 호출
  - 준비 완료 판단 3조건: `wrapper`에 `video-not-ready` 없음 + `video._isReady !== false` + `video.readyState >= 1`
  - **MutationObserver**: wrapper 클래스 변경 감시 (코덱 체크 완료)
  - **canplay + loadedmetadata**: readyState 진행 감시 (네이티브/HLS/트랜스코딩 모두)
  - **10초 안전망**: 그래도 안 되면 사용자 ▶ 폴백
  - **video null 안전 가드**: 플래그 즉시 소비 (stuck 방지 → 다음 사용자 수동 클릭이 자동 재생되는 펜닐 룰 위반 방지)
- 사용자 트랙 클릭 시 `App._fsVpPendingAutoPlay = false` 명시 리셋 (펜닐 룰 보호)
- 트랜스코딩 분기에서도 `_fsVpTryAutoPlay()` 호출 (라인 33891)

**해결 ② (`share.php` 라인 3063~3110):**
- ended 핸들러에서 다음 URL에 `autoplay=1` 파라미터 추가 (사용자 "다음" 버튼은 영향 없음)
- 페이지 로드 시 URL의 `autoplay=1` 처리:
  - **트랜스코딩 모드:** `startTranscode()` 자동 시작 (overlay 클릭 시뮬레이션)
  - **네이티브 모드:** `player.play()` 직접 호출
- **`_autoPlayDone` 가드 (펜닐 v5.8.1e):** 한 번 시도 후 setTimeout 1.5초 재시도가 사용자 일시정지를 무시하고 재생하는 race 방지. 자동 재생 거부 시(catch)에만 재시도 허용

**펜닐 룰 보호:**
- 첫 재생은 수동 ▶ — 그대로 유지
- 사용자 트랙 클릭은 자동 재생 X — 명시 리셋 + 1회용 플래그
- 마지막 트랙 끝나면 그냥 끝 (반복 재생 X)
- 단일 영상은 자동 재생 무관 (`folderVideos.length < 2` 체크)

**검증 (펜닐님 직접):** 메인 네이티브 mp4 자동 다음 재생 ✅, 사용자 트랙 수동 클릭 → 수동 재생 ✅.

---

**[동영상 플레이리스트 단일 영상 숨김] 영상 1개 폴더에서 목록/네비게이션 숨김 (2026-05-09)**

**문제:** 폴더 stream 공유 시 동영상이 1개여도 목록/이전/다음 네비게이션이 표시되어 의미 없는 UI 노출.

**원인 (`share.php`):**
- `$folderTrackTotal` 이 영상 1개여도 1로 채워짐 (라인 306)
- 라인 1249, 1437, 3775의 조건이 `$folderTrackTotal !== null` 만 체크해서 1개일 때도 트랙 네비게이션 + 사이드 패널 + 토글 버튼 표시

**해결:** 3곳 모두 `$folderTrackTotal >= 2` 조건 추가:
- 라인 1249: 트랙 네비게이션 (이전/다음 + 1/N 표시)
- 라인 1437: 사이드 플레이리스트 패널 (팟플레이어 스타일)
- 라인 3775: 패널 JS 토글 핸들러

**메인 페이지 (`assets/js/app.js`):** 이미 라인 32892에서 `hasVideoPlaylist = folderVideos.length >= 2` 조건으로 정상 동작 중 (이번 변경 불필요).

**검증 (펜닐님 직접):** 영상 1개 폴더 stream 공유 → 목록/네비게이션 안 보임 ✅ (메인 + 공유 모두).

---

**[보안/일관성 강화 4종] quota 누락 + Defender 오진 + 화이트리스트 정리 + 청크 MIME 검증 (2026-05-08)**

코드 검토 중 발견된 미시적 이슈 4건을 영향도 0으로 안전하게 수정.

**① create_from_template Quota 검사 추가** (`api.php` 라인 5998~6006)

**문제:** 새 파일 만들기(`create_from_template`) 핸들러가 quota 검사를 호출하지 않아, 한도 도달 사용자가 8.5KB(.hwp) 파일을 무한 생성 가능.

**해결:** 템플릿 파일 존재 확인 후, 실제 복사 전에 `checkQuotaPublic` 호출 추가:
```php
$_templateSize = filesize($templatePath);
if ($_templateSize !== false) {
    $_quotaCheck = $fileManager->checkQuotaPublic($storageId, $_templateSize);
    if (!($_quotaCheck['allowed'] ?? true)) {
        $result = ['success' => false, 'error' => ...]; break;
    }
}
```

**안전 가드:** `filesize()` false 시 스킵, `allowed ?? true` defensive 처리. 일반 사용자(quota 여유) 영향 0%.

**② scanWithDefender 임시파일 검증 정확도 개선** (`api/FileManager.php` 라인 1301~1320)

**문제:** Defender 검사용 임시파일 복사 후 `filesize($tempFile) === 0` 으로 복사 실패 판정 → 정상 0-byte 파일도 오진. 또한 디스크 풀로 인한 부분 복사(예: 100KB 원본 → 50KB 사본)는 검출 못함.

**해결:** 사본만 보던 검증을 원본/사본 사이즈 비교로 변경 (안전 가드 포함):
```php
clearstatcache(true, $tempFile);
clearstatcache(true, $filePath);
if (!file_exists($tempFile)) {
    @unlink($tempFile);
    return ['clean' => true, 'error' => __('temp_file_copy_fail')];
}
$_origSize = @filesize($filePath);
$_copySize = @filesize($tempFile);
// 원본 사이즈를 알 수 있을 때만 비교 (false이면 fallback: 사본 존재 자체가 OK)
if ($_origSize !== false && $_copySize !== $_origSize) {
    @unlink($tempFile);
    return ['clean' => true, 'error' => __('temp_file_copy_fail')];
}
```

**안전 가드:**
- 원본 `filesize()` false 시(파일 사라짐, 권한 문제 등) 사본 존재만 확인 (race condition 방어)
- `clearstatcache()` 양쪽 모두 호출 (PHP stat 캐시 무효화)

**부수 개선:** 검증 실패 시 `@unlink($tempFile)` 추가 (기존엔 누락 → 임시파일 누적 가능성 있었음).

**현재 영향:** scanForVirus의 0-byte 단축 통과로 도달 불가능 코드 경로 → 영향 0%. 미래 디스크 풀 시나리오 정확도 향상.

**③ validateMimeType 화이트리스트의 php 항목 제거** (`api/FileManager.php` 라인 421~422)

**문제:** `'php' => ['text/x-php', 'text/plain', 'application/x-httpd-php']` 화이트리스트 항목이 존재. `checkUploadSettings`의 `serverExecExts`에서 항상 차단되므로 현재는 도달 불가하지만, 미래에 누가 `serverExecExts`에서 php 빼면 이 항목이 PHP 업로드를 허용하는 함정이 됨.

**해결:** php 항목 의도적 제거. py/java/c/cpp/h 항목은 위장 방지 기능 정상 작동하므로 유지 (서버 실행 안 됨).

**현재 영향:** 도달 불가 코드라 0%. 미래 회귀 방지.

**④ 청크 업로드 로컬 경로에 MIME 검증 추가** (`api/FileManager.php` 라인 1784~1793)

**문제:** 일반 업로드(라인 1493)와 청크 업로드 원격(라인 1945)에는 `validateMimeType` 호출이 있는데 청크 업로드 로컬 경로에만 누락. 일관성 부족 + 위장 파일(.jpg에 PHP 코드) 우회 가능성.

**해결:** 일반 업로드와 동일 패턴(옵션 체크 포함)으로 추가:
```php
$_chunkMimeCheck = $this->checkUploadSettings($filename, $finalSize);
$_chunkMimeEnabled = $_chunkMimeCheck['mime_check'] ?? true;
if ($_chunkMimeEnabled && !$this->validateMimeType($targetPath, $filename)) {
    @unlink($targetPath);
    $this->cleanupChunks($tempDir);
    return ['success' => false, 'error' => __('file_type_invalid')];
}
```

**다중 방어선 (이미 존재):**
- 위험 확장자(.php/.jsp/.asp 등): `serverExecExts` 항상 차단
- 이중 확장자(evil.php.jpg): 라인 511~522에서 차단
- 브라우저 MIME 추론: `X-Content-Type-Options: nosniff` (api.php 라인 51)
- SVG inline: 별도 CSP `sandbox` (api/FileManager.php 라인 4612)

**MIME 검증 추가 효과:** 위장 파일 차단 강화 (예: .jpg 확장자에 실제 PHP 코드 → 청크 업로드로 업로드 시도 시 차단).

**사용자 경험:** 정상 사용자 영향 0% (`mime_check` 옵션 켜진 환경에서만 작동, 일반 업로드와 동일 동작).

---

**[i18n/주석 정리] 영어 사용자 UX + 부정확 주석 정리 (2026-05-08)**

**① en.json default_name_* 5개 키 추가** (`lang/en.json` 라인 545~549)

**문제:** "새 파일 만들기" 시 사용되는 `default_name_txt`, `default_name_docx`, `default_name_xlsx`, `default_name_pptx`, `default_name_hwp` 키가 영어 번역 파일에 누락 → 영어 사용자가 새 파일 만들면 한국어 폴백("새 텍스트 문서.txt") 표시. 메뉴 자체는 영어인데 생성되는 파일명만 한국어 → UX 불일치.

**해결:**
```json
"default_name_txt": "New Text Document",
"default_name_docx": "New Word Document",
"default_name_xlsx": "New Excel Document",
"default_name_pptx": "New Presentation",
"default_name_hwp": "New HWP Document",
```

**② 부정확 주석 정리** (`assets/js/app.js` 라인 11419/11737, 16271~16272 + `index.php` 라인 20)

- `app.js`: 주석에 `'md'`, `'text/markdown'` 언급 있는데 라우터/defaultNameMap에 해당 케이스 없음 → 미래 혼란 방지를 위해 제거
- `app.js`: hwp 분기 주석 "rhwp 항상 탑재" → "서버 템플릿" 으로 정확화 (이번 세션 변경 반영)
- `index.php`: CSP 코멘트의 "빈 hwp 문서 생성 등" → "rhwp_viewer.php / rhwp_editor.php 의 HWP/HWPX 뷰어·편집기 WASM 로드" 로 정확화

**코드 동작:** 변화 없음 (주석/번역 데이터만 변경).

---

**[hwp 생성 경로 통합] rhwp.createEmpty() → 한컴 정품 템플릿 복사 (2026-05-08)**

**증상:** "새 한글 문서" 생성 후 한글 에디터(rhwp_editor.php)로 열면 panic:
```
panicked at src/document_core/queries/rendering.rs:300:47:
index out of bounds: the len is 0 but the index is 0
[CanvasView] 페이지 0 정보 조회 실패: RuntimeError: unreachable
```

**원인:** rhwp의 `HwpDocument.createEmpty()` 가 **페이지 0개의 hwp 바이트** 생성. 파일 자체는 유효한 CFB 컨테이너지만 BodyText에 페이지가 없어 `getPageInfo(0)` 호출 시 빈 배열에 인덱스 0 접근으로 패닉. rhwp 라이브러리 측 결함이라 직접 수정 불가.

**해결:**
- `templates/empty.hwp` 추가 — 한컴오피스 한글 2010 정품이 만든 빈 문서 (8704 bytes, CFB v5.0)
- `assets/js/app.js` 라인 11751~11756 라우터: `'rhwp'` → `'template'` 으로 변경
- `assets/js/app.js` 라인 16310~ `_createNewFile`: rhwp 분기(WASM 동적 import + createEmpty + exportHwp + Blob 업로드, 약 55줄) 통째로 제거
- `api.php` 무수정 — `create_from_template` 핸들러는 이미 `['docx', 'xlsx', 'pptx', 'hwp']` 화이트리스트에 hwp 포함

**부수적 이점:**
- 새 파일 생성 시 WASM 컴파일 트리거 안 됨 (rhwp_viewer/editor 사용 시에만 트리거)
- iOS Safari 15 이하에서도 새 한글문서 생성 가능 (편집/뷰어는 여전히 16+ 필요)
- 응답 속도: WASM 로드/초기화 (~1-2초) 없이 즉시 생성
- 외부 한글 프로그램 호환성 100% (한컴이 만든 파일 그대로)

**검증 (펜닐님 직접):** 5종 새 파일(txt/docx/xlsx/pptx/hwp) 생성 + 에디터 열기 모두 정상 + 모바일 + 원격 스토리지 생성 확인.

---

**[빈 파일 업로드 fix] validateMimeType + scanForVirus 0-byte 단축 통과 (2026-05-08)**

**증상 ①:** 빈 텍스트 문서(.txt) 생성 시 토스트 "파일 형식이 올바르지 않습니다. (확장자와 실제 파일 타입 불일치)"

**원인 ①:** `_createNewFile` 이 `new Blob([''], {type: 'text/plain'})` 으로 0-byte 파일 업로드 → 서버 `validateMimeType()` 의 `finfo_file()` 이 빈 파일에 대해 `application/x-empty` (또는 `inode/x-empty`) 반환 → 화이트리스트(`txt => ['text/plain']`)와 불일치 → 거부.

**해결 ①:** `api/FileManager.php` `validateMimeType()` 에 두 단계 안전망 추가:
- 라인 444: `if (@filesize($tmpFile) === 0) return true;` (1차)
- 라인 458: `if ($realMime === 'application/x-empty' || $realMime === 'inode/x-empty') return true;` (2차)

**증상 ②:** ① 수정 후 다시 생성 시도하니 다른 토스트 발생 — "🛡️ 바이러스가 감지되었습니다: 백신 검사 오류로 차단됨: 임시 파일 복사 실패"

**원인 ②:** Defender 검사용 임시파일 복사 후 검증 로직(`scanWithDefender` 라인 1295)이 0-byte를 "복사 실패"로 오판 → `block_on_error` 옵션으로 차단 전환.

**해결 ②:** `api/FileManager.php` `scanForVirus()` 진입 직후 0-byte 단축 통과 추가 (라인 1177~1179):
```php
if ($fileSize === 0) {
    return ['clean' => true, 'skipped' => true];
}
```
- 엔진 분기(ClamAV/Defender) 이전에 처리 → 양쪽 엔진 모두 안전
- 0 bytes는 어떤 바이러스 시그니처도 담을 수 없음 (수학적 자명)

**보안 영향 평가:**
- 위험 확장자 차단(`checkUploadSettings` 라인 505의 .php/.jsp/.asp 등)은 MIME 검사 **이전** 단계 → 우회 불가
- 랜섬웨어 확장자 차단(`checkRansomwareExtension` 라인 1472)도 우회 불가
- 이중 확장자(evil.php.jpg) 차단도 우회 불가

**영향 범위 — 함께 해결:**
- 새 텍스트 문서 (.txt) ⭐
- 외부 도구가 만든 0-byte placeholder (rsync, Git LFS pointer 등)
- WebDAV 클라이언트의 빈 PUT 요청
- 청크 업로드의 0-byte 합본 케이스

---

**[CSP wasm-unsafe-eval] WebAssembly 컴파일 허용 (2026-05-07)**

**증상:** 빈 한글 문서 생성 시 콘솔 에러:
```
Refused to compile or instantiate WebAssembly module because neither
'wasm-unsafe-eval' nor 'unsafe-eval' is an allowed source of script
```

**원인:** `index.php` 라인 22 CSP 헤더의 `script-src` 디렉티브에 `'wasm-unsafe-eval'` 누락. 브라우저가 WebAssembly 모듈 컴파일/인스턴스화 차단.

**해결:** `index.php` 라인 22 CSP 헤더에 `'wasm-unsafe-eval'` 추가:
```
script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' blob: https://cdnjs.cloudflare.com
```

**보안 영향 평가:**
- `'wasm-unsafe-eval'` 는 W3C 표준 키워드, `'unsafe-eval'` (JS eval) 보다 매우 제한적
- WebAssembly 모듈 컴파일/인스턴스화만 허용, JavaScript `eval()` 같은 위험 동작은 **여전히 차단**
- rhwp는 FileStation 자체 탑재 코드 (외부 동적 로드 아님)

**브라우저 호환성:**
| 브라우저 | 지원 |
|---|---|
| Chrome / Edge / Firefox | ✅ 모든 버전 |
| Safari 16+ (2022년 9월 이후) | ✅ |
| Safari 15.x 이하 | ⚠️ |

**적용 페이지:** `index.php` 만 수정 — rhwp_viewer.php, rhwp_editor.php, share.php는 자체 CSP 없거나 별도 처리.

---

**[새 파일 만들기 — 우클릭 메뉴] Windows 탐색기 스타일 즉시 생성 + 자동 번호 (2026-05-07)**

**기능:** 폴더 빈 영역 우클릭 → "새로 만들기" → txt / docx / xlsx / pptx / hwp 즉시 생성. prompt 없이 기본 이름으로 만들고 중복 시 `(2)`, `(3)` 자동 증가 (Windows 탐색기와 동일).

**구현:**
- `assets/js/app.js` 라인 11739~11756: 메뉴 라우터 5개 케이스 추가
- `assets/js/app.js` 라인 16271~ `_showNewFileDialog()`: 기본 이름 매핑 (`'새 텍스트 문서'`, `'새 Word 문서'`, `'새 Excel 문서'`, `'새 프레젠테이션'`, `'새 한글 문서'`)
- `assets/js/app.js` 라인 16288~ `_findUniqueFileName()`: 클라이언트 측 중복 검사 (`(2)`~`(999)` 자동 번호)
- `assets/js/app.js` 라인 16313~ `_createNewFile()`: 두 경로 분기
  - **txt:** 빈 Blob → 기존 `upload` API (보안/권한 자동 적용)
  - **docx/xlsx/pptx/hwp:** `create_from_template` API → 서버 `templates/empty.{ext}` 복사
- `api.php` 라인 5963~6102: `create_from_template` 핸들러 신규 추가
  - 화이트리스트: `['docx', 'xlsx', 'pptx', 'hwp']`
  - 파일명 보안 검사 (path traversal 방어, 특수문자 차단)
  - 로컬: `copy()` 직접 복사 / 원격: `$adapter->write()` 호출
  - 중복 시 서버측에서도 `(n)` 자동 증가 → 응답 `name` 사용
  - 활동 로그 + 파일 인덱스 갱신
- `templates/empty.docx`, `empty.xlsx`, `empty.pptx`, `empty.hwp` 4개 템플릿 추가

**서버 보안:**
- 화이트리스트 외 템플릿 거부
- `realpath()` 기반 path traversal 방어 (라인 6047~6050)
- 폴더별 쓰기 권한 체크 (라인 5971)

---

**[SMB media_info] SMB 타입도 ffprobe 가능하게 보완 (2026-05-08)**

**문제:** `api/FileManager.php`의 `getMediaInfo()`가 SMB도 원격 스토리지로 묶어 early return → ffprobe 실행 안 함, `file_size` 응답 누락. 결과적으로 SMB 타입 등록한 NAS는 모바일 + 500MB 이상 mp4 자동 트랜스코딩 결정 못 함.

**원인:** `isRemoteStorage()` true 분기가 FTP/SFTP/WebDAV/S3/SMB 모두 차단. 다만 SMB는 `transcodeStream()`에서 이미 마운트 포인트 / UNC 경로로 ffmpeg 정상 호출 → **media_info만 분기 일관성 부족**.

**해결:**
- `REMOTE_TYPES`에서 'smb' 제외한 타입만 차단 (`api/FileManager.php` 라인 8032~8043):
  ```php
  $_storageInfoMI = $this->storage->getStorageById($storageId);
  if (!$_storageInfoMI) {
      return ['success' => false, 'error' => 'Storage not found'];
  }
  $_storageTypeMI = $_storageInfoMI['storage_type'] ?? 'local';
  // REMOTE_TYPES에서 'smb' 제외한 타입만 차단 (SMB는 ffprobe 가능)
  if (in_array($_storageTypeMI, self::REMOTE_TYPES) && $_storageTypeMI !== 'smb') {
      return ['success' => true, ..., 'note' => 'remote storage'];
  }
  ```
- SMB는 `getRealPath()`에서 UNC 경로/마운트 포인트 받아 `is_file()`, `filesize()`, `ffprobe` 정상 호출
- FTP/SFTP/WebDAV/S3는 기존대로 차단 (어댑터 외 접근 불가)

**효과 매트릭스:**

| 등록 방식 | media_info | 모바일+대용량 트랜스코딩 |
|---|---|---|
| 로컬 디스크 | ✅ | ✅ |
| UNC + local 등록 (펜닐님 환경) | ✅ | ✅ |
| **SMB 타입 등록** | ⭐ 이번 수정으로 ✅ | ⭐ 이번 수정으로 ✅ |
| FTP/SFTP/WebDAV/S3 | ❌ early return (변경 없음) | ❌ |

**적용 범위:** `api/FileManager.php`만 수정 (1개 함수의 1개 분기 보완 + null 체크 추가)

---

**[media_info fallback v2] iOS Safari abort race condition 보완 (2026-05-07)**

**증상:** iPhone Safari에서 mp4 500MB 이상 파일 미리보기 시 간헐적으로 "▶ 일반 재생" 배지(코덱 정보 없음) + 재생 버튼 비활성. 모달 닫고 다시 열면 정상.

**원인 진단:**
- `media_info` API의 fetch 자체가 reject (iOS Safari fetch race / 네트워크 일시 실패)
- catch 분기로 진입 → 배지만 변경하고 끝, native 활성화 처리 없음
- `_isReady = false`, `controls` 미설정, `video-not-ready` 클래스 잔존 → 재생 버튼 비활성

**해결 — 조건분기 폴백 (펜닐님 옵션 3 결정):**
1. `assets/js/app.js` 라인 33747~ catch 분기:
   - `err.name === 'AbortError'` 무시 (정상 abort)
   - 진짜 에러: 모바일 + 500MB↑ → 트랜스코딩 폴백 / 그 외 → native 폴백
2. 라인 33583~ `info.success === false` 분기에도 동일 패턴 적용
3. **추가 보완:** 폴백 시 video 요소 `<source>` 태그 정리 누락 → 양쪽에 `_oldVidFb.querySelectorAll('source').forEach(s => s.remove())` + `_switchingToTranscode = true` + `video-not-ready` 추가 (정상 트랜스코딩 분기와 동일 패턴)

**검증:** 변수 클로저 (`item`, `storageId` 모두 `_showPreviewImpl(item)` 함수 스코프), `App.` vs `this` 일관성, AbortError 처리, 보안 위험 0개

---

**[SMI fix #2] 멀티라인 자막 빈 공백 줄 정리 (2026-05-07)**

**증상:** Lucky Number Slevin 같은 SMI 자막에서 2~3줄로 표시되어야 할 자막이 1줄만 표시.

**검증 데이터:** `Lucky.Number.Slevin.2006.1080p.BrRip.x264.BOKUTOX.YIFY.smi` (cp949, 2309 sync, 멀티라인 588개)

**원인 (시뮬레이션 추적):**
- SMI 원본: `오케이,<br> \n용건이 뭐요?` (`<br>` + 공백 + `\n`)
- `<br>` → `\n` 변환 후: `오케이,\n \n용건이 뭐요?` (`\n`과 `\n` 사이 공백 한 칸)
- 기존 `\n{2,}` 정규식이 못 잡음 (공백 끼어있어 연속 \n이 아님)
- VTT 출력 후 재파싱 라인 36919: `vttLines[ci].trim() !== ''` 체크에서 공백 줄을 빈 줄로 인식 → cue가 첫 줄에서 끊김
- "용건이 뭐요?" 누락

**해결:**
- `assets/js/app.js` `_smiToVtt()` 라인 37262: `replace(/\n[ \t]+\n/g, '\n\n')` 추가 (공백/탭만 있는 줄 → 빈 줄로 만들어 다음 정규식 `\n{2,}→\n`이 잡을 수 있게)
- `share.php` `parseSmi()` 라인 3693: 동일 정규식 추가
- 검증: 588개 멀티라인 모두 정상 처리

---

**[SMI fix #1] 자막 들러붙음 (Subtitle Sticking) 수정 (2026-05-07)**

**증상:** SMI 자막이 끝난 시점이 지나도 다음 자막 시작까지 화면에 박혀있음. 일반 플레이어(팟플레이어 등)는 정상.

**검증 데이터:** John Wick .smi 자막 (628개 자막 OFF 신호, 5초+ 빈 시간 135개, 가장 긴 빈 시간 163초)

**원인:** SMI 파서가 빈 SYNC 블록(`<SYNC Start=...><P>&nbsp;</P>`, SMI 표준의 자막 OFF 신호)을 무시하여 이전 자막의 endTime이 다음 자막 시작까지 늘어남. 시뮬레이션: 수정 전 "부기맨" 자막 166.9초 표시 → 수정 후 3.1초.

**해결 (양쪽 동일 패턴):**
- `assets/js/app.js` `_smiToVtt()` 라인 37230~: 빈 자막도 `syncs.push({ ms, text: text || '' })`로 추가, cue 생성 시 `if (!syncs[i].text) continue` (cue 안 만들지만 endTime 역할)
- `share.php` `parseSmi()` 라인 3683~: 1단계로 모든 sync 수집 (빈 자막 포함), 2단계로 cue 생성 (정렬 후 빈 자막은 endTime만 활용)
- XSS 방어 동일 유지 (텍스트 있을 때만 이스케이프)
- VTT/SRT/ASS 파서는 endTime 명시 포맷이라 수정 불필요 (변경 없음)

**효과 — 모든 자막 환경 검증 매트릭스 (12/12 통과):**

| 환경 | SMI 들러붙음 (fix #1) | 멀티라인 (fix #2) |
|---|---|---|
| 일반 페이지 + 로컬 | ✅ | ✅ |
| 일반 페이지 + SMB | ✅ | ✅ |
| 일반 페이지 + FTP/SFTP/WebDAV/S3 | ✅ | ✅ |
| 공유 페이지 + 모든 스토리지 | ✅ | ✅ |
| 모달 재생 | ✅ | ✅ |
| 전체화면 (PC/Android/iOS) | ✅ | ✅ |

**검증된 라이브러리 비교:** sami-parser (elegantcoder, 한국 NPM), subsrt (papnkukn), mantas-done/subtitles (PHP) 모두 우리 SMI 파서와 거의 동등한 처리 수준.

---

**[31st rev] 동영상 자동 다음 트랙 재생 — 트랜스코딩 모드 보완 (2026-05-06)**

**증상 진단:** 폴더 안 동영상 2개 이상일 때 끝나면 다음 트랙 자동 재생되어야 하는데, 트랜스코딩 모드(mkv/avi 등 변환 필요한 형식)에서 자동 재생이 안 되는 문제.

**원인:** 트랜스코딩 분기에서 `replaceChild`로 video 요소 새로 생성 → `_setupVideoPlaylistPanel`에서 등록한 ended 리스너 소실 (`_fsVpAutoNextBound` 플래그도 새 요소엔 없음).

**해결:**
1. **헬퍼 함수 `_fsVpBindAutoNext()` 추출** — 기존 `_setupVideoPlaylistPanel` 안 인라인 자동 재생 로직을 별도 함수로 분리
2. **트랜스코딩 분기 `_waitAndBind` 콜백에 재바인딩 추가** — video 요소 교체 후 새 video에 ended 리스너 재등록

```javascript
// 헬퍼 함수 (_fsVpAutoNextBound 플래그로 중복 바인딩 방지)
_fsVpBindAutoNext() {
    const folderVideos = this._fsVpFolderVideos || [];
    if (folderVideos.length < 2) return;
    const video = document.querySelector('#preview-content .preview-video');
    if (!video || video._fsVpAutoNextBound) return;
    video._fsVpAutoNextBound = true;
    const curIdx = folderVideos.findIndex(f => f.path === this._fsVpCurrentPath);
    video.addEventListener('ended', () => {
        if (curIdx >= 0 && curIdx < folderVideos.length - 1) {
            // 다음 트랙으로 전환
            ...
        }
        // 마지막 트랙이면 그냥 끝 (반복 재생 X — 펜닐 룰)
    });
}
```

**시나리오 매트릭스 (모든 모드 + 일반/공유 양쪽):**

| 시나리오 | 동작 |
|---|---|
| 일반 + 네이티브 (mp4) | ✅ |
| 일반 + HLS (스트리밍) | ✅ |
| 일반 + 트랜스코딩 (mkv/avi) | ✅ ⭐ (이번 수정) |
| 공유 폴더 + 모든 모드 | ✅ (PHP measured `location.href` 자동 이동) |

**펜닐 룰:**
- 첫 재생은 수동 (사용자가 ▶ 버튼)
- 마지막 트랙 끝나면 그냥 끝 (반복 X)
- 단일 영상은 자동 재생 무관 (다음 트랙 자체가 없음)

**적용 범위:** `assets/js/app.js`만 수정 (1개 함수 추가 + 2곳 호출 추가/수정)
**share.php는 수정 안 함** — 이미 모든 모드 자동 이동 동작.

**캐시 무효화:** `APP_VERSION` `5.8.1d` → `5.8.1e`

---

#### v5.8.1d (2026-05-04 ~ 2026-05-06)

**최종 상태 요약:** 30 리비전 누적 (VU 미터 28th + BF Cache 핸들러 29b + rhwp 0.7.10 30th)

---

**[30th rev] rhwp 0.7.9 → 0.7.10 업그레이드 (2026-05-06)**

가이드 v5 표준 절차로 진행, 검증 15/15 통과.

- **빌드 결과:**
  - JS: `index-CCef3-Zl.js` → `index-zIVQkhcx.js`
  - CSS: `index-ro3nVBB2.css` (해시 동일 — 0.7.10도 변경 없음)
  - WASM: `rhwp_bg-Bb98LUYj.wasm` → `rhwp_bg-BbAYsuOY.wasm`
  - 신규 폰트: `SourceHanSerifK-OldHangul-subset.woff2` (한글 옛 글자 지원)
- **0.7.10 주요 신규 기능 (rhwp 측):**
  - 외부 PR 13건 cherry-pick (7명 컨트리뷰터)
  - HWP 5.0 스펙 0x18/0x1E swap 정정, HWP3 변환본 식별 휴리스틱
  - 표 셀 레이아웃 7건 정정, HY견명조 폰트 분류 정정, 글상자 화살표 매핑 정정
  - PNG raster backend (Skia 기반, native-skia feature gate)
  - AI 파이프라인/VLM 연동 (export-png CLI, FileStation 미사용)
  - CLI 바이너리 릴리즈 4 플랫폼
- **패치 적용:** J1 1건 매칭(file:save 단축키 매핑 제거), P1 0건/P2 2건(`../images/` → `images/`)
- **PR #335 회귀:** svg.rs preserveAspectRatio="none" 코드 0.7.10도 잔존, 펜닐 환경 실사용에서 재현 안 됨
- **FileStation 버전 유지** (펜닐 룰: "버전 올려줘" 명시 없음 → v5.8.1d 그대로)

---

**[29b rev] 모바일 BF Cache 복원 처리 (2026-05-05)**

**증상:** 모바일에서 음악/동영상/웹하드 사용 후 백그라운드 → 복귀 시 사이드바 스토리지 리스트 비어있고 "스토리지를 선택하세요" 메시지 표시. 수동 새로고침 시 정상.

**원인:** iOS Safari/Android Chrome의 BF Cache(Back-Forward Cache)에서 페이지 복원 시 `init()` 자체가 재실행 안 되어 `loadStorages()` 호출 누락.

**해결:**
1. **App.init()에 pageshow 핸들러 추가** (라인 4514) — 음악 플레이어 인스턴스 유무와 무관하게 항상 등록
   ```javascript
   window.addEventListener('pageshow', (event) => {
       if (!event.persisted) return;  // 일반 새로고침은 init() 재실행되므로 무관
       if (!this.user) return;        // 미로그인 상태는 무관
       this.loadStorages();
       this.updateShareBadges();
       this.updateSharedWithMeBadge();
   });
   ```
2. **FSAudioPlayer의 _pageshowHandler에서 App 데이터 갱신 부분 제거** (중복 방지) — MediaSession 재설정 로직만 유지

**시나리오 매트릭스 (6/6 정상):**
- ✅ 음악 재생 후 백그라운드 → 복귀
- ✅ 동영상 재생 후 백그라운드 → 복귀
- ✅ 웹하드 탐색만 후 백그라운드 → 복귀
- ✅ 미로그인 상태 BF Cache 복원 (this.user 가드)
- ✅ 일반 새로고침 (event.persisted 가드)
- ✅ PC 데스크탑 (BF Cache 거의 발생 안 함, 발생 시 정상)

**fs-audio-player.js (share.php):** 단일 공유 페이지라 스토리지 사이드바 자체가 없어 수정 불필요.

---

**[28th rev] 죽은 코드/CSS 정리 + VU/VU LED 모드 _analyser 호출 스킵 (2026-05-05)**

이전 리비전들에서 누적된 잔재 코드 청소 (동작 변경 0).

1. **죽은 코드 제거** — `_vuBacklight = localStorage 값`을 즉시 `= true`로 덮어쓰는 패턴이 있어 localStorage 읽기 부분 제거 (양쪽 파일)
2. **죽은 CSS 제거** — `.fap-vu-bl-toggle` 셀렉터 (15th rev에서 토글 버튼 제거됨에도 잔존, style.css/fs-audio-player.css)
3. **`_analyser` 불필요 호출 스킵** — VU/VU LED 모드는 `_vuAnalyserL/R` 사용하므로 `_analyser.getByte*` + `_updateBandValues()` 호출 스킵 (단, VU 인프라 실패 시 bars fallback 보장 위해 `vuInfraReady` 체크 추가)

검증: 양쪽 파일 동기화 12/12, 함수 정의/호출 일치 17/17, 보안 회귀 0.

---

**[VU 미터 디자인 진화: 13th ~ 27th rev]**

WAVES VU 미터 비례 + 1번 이미지 디자인 매칭 (펜닐 직접 검증). 22번 리비전 동안 미세조정.

- **박스 비율 변화**: 280×130 → 220×140 → 300×170 → **300×150** (12th의 가로형 → 더 압축된 가로형)
- **0VU 위치**: WAVES 표준 (가로 72%) — 박스 정중앙 우측
- **piecewise 매핑 진화**: 0VU=+5° → +12° (펜닐 "0이 빨강 1~2 사이" 요청)
- **빨강 영역**: +12° ~ +24° (호 24%, 0VU 라벨부터 +3까지 균일 4° 간격)
- **음수 라벨 균일 간격**: -20부터 0까지 약 27px씩 균일 (펜닐 "-20부터 0까지 균일 간격" 요청)
- **−/+ 부호**: 호 양 끝 외측 (-27°/+27°, R-5) — 그래프 끝에 위치
- **VU 라벨**: italic 세리프 폰트 (Georgia, 16pt) — McIntosh 위쪽 (y=120)
- **하단 텍스트**: "McIntosh" → **"METER"** (상표권 회피, 펜닐 결정 — 27th rev)
- **피벗 점(O) 제거** — viewBox 밖 dummy로 호환성 유지 (`_applyVUBacklight` 참조 위해 객체는 남김)
- **VU LED 모드 추가** (14b rev) — 가로형 디지털 LED 미터 33 세그먼트 × 2행, 50ms attack
- **백라이트 토글 제거** (15th rev) — 항상 ON 고정

**WAVES VU 미터 비례 (22nd rev 최종):**
- viewBox: `0 0 300 150`
- 회전축: cx=150, cy=350 (viewBox 외부 200px 아래)
- 호 반지름: 320 (눈금 정점이 박스 안 약간 안쪽)
- 매핑: -20→-25°, -10→-20°, -7→-15°, -5→-10°, -3→-5°, -2→0°, -1→+6°, **0→+12°**, +1→+16°, +2→+20°, +3→+24°
- 빨강 영역: +12° ~ +24°

**디자인 출처 (누적):**
- WAVES VU Meter 비례 (펜닐 1번 참고 이미지)
- piecewise interpolation, ballistics 300ms: [Jun-Murakami/vu-meter-react](https://github.com/Jun-Murakami/vu-meter-react) (MIT)
- SVG line + rotate(deg cx cy) 패턴: [matteovinci/angular-vumeter](https://github.com/matteovinci/angular-vumeter) (MIT)

**유지된 인프라:**
- VU ballistics — 1차 lowpass 필터, 300ms attack/release
- 0 VU = -18dBFS 캘리브레이션
- Peak lamp — 클립 임계값 / 1s 홀드 → 5s 페이드아웃
- ChannelSplitter L/R, 기존 audio 그래프 영향 0%

---

**[VU 미터 12th rev (초기, 2026-05-04)]**

McIntosh 가로 비율 (280×130, viewBox 280×130) 첫 도입. 이후 28번째 리비전까지 디자인 진화.

---

**[캐시 무효화]**
- `APP_VERSION` `5.8.1c` → `5.8.1d` 업데이트 (rhwp 0.7.10 업그레이드 시에도 펜닐 룰 따라 유지)


#### v5.8.1c (2026-05-03)
**1. rhwp 0.7.8 → 0.7.9 업그레이드**
- 가이드 v5 표준 절차 진행 (사전 회귀 점검 → clone → npm @rhwp/core 0.7.9 → studio 빌드 → 패치 J1/P2 자동 적용 → 검증 15/15 통과)
- 빌드 결과:
  - JS: `index-Db8NVuPi.js` → `index-CCef3-Zl.js` (해시 갱신)
  - CSS: `index-ro3nVBB2.css` (해시 동일 — 0.7.9 CSS 변경 없음)
  - WASM: `rhwp_bg-BqZZZ9ls.wasm` → `rhwp_bg-Bb98LUYj.wasm` (해시 갱신)
- 0.7.9 주요 개선 (rhwp 측):
  - **Task #501**: HWP 셀 padding 비정상 케이스 한컴 방어 로직 추가 — `pad_top + pad_bottom > cell.height`인 비정상 셀에서 row 높이 계산 회귀 정정 (mel-001.hwp 등 정상 표시)
  - **PR #428**: 그룹 내 그림(Picture) 직렬화 구현 — 그룹 안에 그림이 포함된 HWP 저장 시 그림 데이터 유실 결함 정정
  - **PR #494**: `Paragraph::utf16_pos_to_char_idx` 외부 노출 (API 추가)
  - **PR #478**: Layout 정합 + 수식 렌더링 정정 (수식 토크나이저 폰트 스타일 prefix 분리, italic honor 등)
- 패치 적용 결과:
  - 패치 J1 (file:save 단축키 매핑 제거): 1건 매칭 → 제거 성공 (커스텀 Ctrl+S 정상 동작)
  - 패치 P1 (절대경로): 0건 (0.7.9도 절대경로 사용 안 함)
  - 패치 P2 (`../images/` → `images/`): 1건 매칭 → 제거 성공
- 빌드 환경 변화: vite 5/6 → vite 8, TypeScript 5 → 6 (PWA 플러그인 추가됐으나 FileStation은 PWA 미사용 — 가이드대로 PWA 없이 빌드)
- 커스텀 보존 검증 15/15 통과 (save/save-as/Ctrl+S/syncSuspended/notifyParent/MutationObserver/HWPX 차단 5중 방어 등 모두 그대로)

**2. MP3 플레이어 가사 모달 (옵션 A — Apple Music 스타일)**
- 본질: 동기화된 가사(LRC, synced=true)는 인라인 3줄 표시 + 모달, 정적 가사(USLT/TXT, synced=false)는 인라인 숨김 + 모달에서만 정적 표시
- 새 기능:
  - 가사 버튼 (`fap-btn-lyrics`) — 가사 있는 트랙만 활성화 (없으면 숨김)
  - 가사 모달 — PC: 흰색 카드 (사이트 일반 모달 스타일), 모바일: 풀스크린 어두운 배경 (Apple Music 스타일)
  - 헤더 노래 제목 표시 (PC/모바일 통일 — "가사" 라벨 없이 트랙 제목만)
  - PC 헤더 드래그로 모달 위치 이동 가능 (모바일은 풀스크린이라 비활성)
  - 정적 가사 모달은 사용자 자유 스크롤 (자동 스크롤 제거 — 시간 동기 불가능해서 혼란 방지)
  - 동기화 가사 모달은 활성라인 자동 스크롤 (LRC만)
- 단축키:
  - `Ctrl+L`: 가사 모달 토글 (MusicBee/Apple Music 비공식 표준)
  - `Esc`: 가사 모달만 닫기 (mp3 모달은 유지)
  - 가사 모달 열려있을 때 다른 키(Space/←/→/M/S/L) 차단 — 의도치 않은 음악 컨트롤 방지
  - 외부 클릭으로 안 닫힘 (X 버튼 또는 Esc로만)
- 적용 범위: app.js (메인 미리보기), fs-audio-player.js + share.php (공유 페이지)
- APlayer Fixed 스킨 grid 조정:
  - 마지막 행 `1fr` 적용 → 모달 크기 변화 시 남는 공간이 마지막 행으로 흡수 (다른 스킨 패턴과 일관)
  - 시킹바/컨트롤/볼륨 패딩 16px로 늘림 + `align-self: start`로 상단 고정
  - 볼륨은 컨트롤 바로 아래 고정 (남는 공간은 모달 바닥으로 몰림)
  - 가사 없을 때 (정적 가사 또는 가사 0개) `fap-no-inline-lyrics` 클래스로 grid row 2 압축

**3. 동영상 화질(Quality) 셀렉터 추가**
- 본질: HLS 트랜스코딩 영상에 7단계 화질 선택 (원본 / 1080p / 720p / 480p / 360p / 240p / 144p)
- 동작 케이스:
  - CASE 1: 네이티브 → 네이티브 (original 유지) — 변화 없음
  - CASE 2: 네이티브 → 트랜스코딩 (비-original 선택) — HLS 세션 시작
  - CASE 3: 트랜스코딩 → 네이티브 (original 선택) — HLS 인스턴스 정리 + native source 복원
  - CASE 4: 트랜스코딩 → 다른 quality — 기존 인스턴스/세션 정리 + 새 HLS 세션 시작
- `_qualitySeekOffset` 누적 보정 (핵심):
  - 트랜스코딩은 `stream0.ts = 새 세션 시작 시점`이라 `currentTime`은 상대시간
  - 절대시점 = `currentTime + offset` 으로 누적 계산 (audio 변경 시도 보정)
  - 메인 app.js + share.php 모두 동일 패턴
- race-safe play: `MANIFEST_PARSED` 후 `canplay` 이벤트에서 play() 호출 (interrupted 경고 회피) + 2초 fallback
- 위치: PC 우상단 (audio 셀렉터와 가로 배치), 모바일 audio 셀렉터 아래
- 적용 범위: app.js, share.php — 트랜스코딩 영상 + 네이티브 재생 영상 모두 활성화

**4. FLAC/Opus 등 모바일 재생 (MIME 타입 보강)**
- 본질: `getMimeType()`/`mimeMap`이 FLAC, M4A, AAC, OPUS 등 누락 → `application/octet-stream` 응답 → 모바일 브라우저가 audio로 인식 못 하고 다운로드 처리
- 수정:
  - FileManager::getMimeType() — FLAC, M4A, AAC, OPUS, WMA, OGA, AIFF 추가
  - ShareManager mimeMap — 동일하게 동기화 + Opus를 `audio/opus` → `audio/ogg`로 변경 (일부 모바일은 audio/opus 미인식)
- 효과: iOS Safari, Chrome Android에서 FLAC 재생 정상

**5. 모바일 자막 컨트롤 정리**
- PC: fullscreen에서만 표시 (`:fullscreen` / `:-webkit-full-screen`)
- 모바일: 항상 숨김 (`!important`) — 화면이 좁아 컨트롤이 거슬림
- 자막 컨트롤 위치: `bottom: 190px` (iOS native fullscreen 컨트롤바 회피)

**6. 모바일 파일 리스트 UX 개선 (CSS 한정)**
- 모바일 list-view에서 grid 레이아웃 적용 (`grid-template-areas: "check icon name name" / "check icon date type"`)
- 이름 1행 전체폭, 그 아래 작은 글씨로 날짜·유형
- size 컬럼 모바일 숨김 + 헤더 숨김 (옵션 B)
- PC는 변경 없음

**7. 메모리 누수 + UX 결함 수정 (4차 검토 누적)**
- 가사 모달 드래그 핸들러 destroy 시 `removeEventListener` 정리 추가
- 드래그 후 모달 재오픈 시 `position/margin/transition` 모두 초기화 (이전엔 `left/top`만 초기화 → 좌상단 표시 버그)
- 가사 모달 열 때 LRC 활성라인 즉시 표시 (`_renderLyricsModalContent` 끝에 처리 — 이전엔 다음 timeupdate 기다림)
- `scrollIntoView` → 수동 `scrollTop` 패턴으로 통일 (페이지 스크롤 영향 회피, 메인-share 일관성)
- `_openLyricsModal` 순서 변경 — `display = ''` 먼저 → 그 후 렌더 (clientHeight 정확한 계산)

**8. 캐시 무효화**
- `APP_VERSION` `5.8.1b` → `5.8.1c` 업데이트
- `?v=APP_VERSION` 자원 URL 모두 새로 다운로드 (iOS Safari 등 공격적 캐시 회피)

#### v5.8.1b (2026-04-30)
**1. 멀티오디오 영상 HW 인코딩 활용 (저부하 개선)**
- 본질: 기존 멀티오디오 검출 시 무조건 SW 강제 → CPU 부하 큼 (특히 N100/저사양 환경)
- 변경: 스트리밍 방식별 분기 — HLS 경로는 HW 시도, MMS 경로 + iOS만 SW 강제
- 근거 (펜닐님 실측):
  - iOS HLS는 멀티오디오 + HW 인코딩 정상 재생 (문제 없음 확인)
  - iOS MMS만 단일 스트림 구조라 HW segment 실패 시 회복 불가
  - PC/Android는 어느 경로든 hls.js ERROR 핸들러로 force_sw=1 자동 fallback
- 안전망: 클라이언트 hls.js 에러 → force_sw=1 fallback (라인 33967), 서버측 HW 실패 감지 → libx264 자동 fallback (FileManager.php 라인 3026~) — 기존 메커니즘 그대로
- 효과: 멀티오디오 영상 재생 시 영상 HW + 오디오 SW 분리 → CPU 부하 80% → 15% 수준 (영상 코덱이 부하 95% 차지)
- 적용 범위:
  - 메인 페이지 — 멀티오디오 시작 시 분기 (app.js 라인 33285~)
  - 공유 페이지 — 멀티오디오 시작 시 분기 (share.php 라인 1929~)
  - 공유 페이지 — 오디오 트랙 변경 시 일관성 (share.php 라인 2066~) ★ 1차 누락 발견 후 추가 수정
  - 공유 페이지 — 오디오 변경 후 _changeHls ERROR 핸들러 (share.php 라인 2118~) ★ 2차 누락 발견 후 추가, 첫 hls 인스턴스(라인 1571)와 일관된 SW fallback 보장
- 회귀 진단 키: `_diagLog('multiaudio_decision', ...)` — willUseHls / swForced 값 확인 가능

**2. 폴더 진입 실패 시 #file-list 모래시계 잔존 해결 (UI 복구)**
- 본질: `loadFiles` 에러 응답 시 토스트만 띄우고 `#file-list`는 ⏳ 모래시계 그대로 → 사용자가 화면 멈춘 것처럼 인식
- 발생 시나리오: Windows 네트워크 드라이브 매핑(SMB) idle stale → `is_dir()` 실패 → "폴더를 찾을 수 없음" 응답
- 해결: 에러 응답 시 `#file-list`에 명확한 에러 표시 (⚠️ + 메시지) — 자동 재시도는 하지 않음 (사용자가 새로고침 또는 다른 폴더 클릭으로 복구)
- 적용 범위: assets/js/app.js 라인 8287~ (loadFiles 에러 처리)
- 부작용 0: 정상 흐름 영향 없음, 에러 분기에만 UI 갱신 추가

**3. 전체화면 자막 표시 본질 해결 (PC/모바일 일반/공유 통합)**
- 본질 분석:
  - PC/Android 표준 fullscreen: 기존 `onFullscreenChange`가 커스텀 오버레이를 강제 종료(`display:none`)하고 native `<track>`로 전환 → blob URL + mode 토글 패턴이 일부 브라우저에서 cue 미렌더링
  - iOS native fullscreen: `<track src="data:...">` 방식이 메뉴엔 표시되지만 cue 미그림 (flowplayer #1151, video.js #7356 — iOS 14.6+ 미해결 알려진 이슈)
  - iOS hidden 모드 트랙은 cue 메모리를 자체 회수 → fullscreen 진입 시점에 `cues.length === 0`이 되어 자막 안 그려짐 (디버그 로그로 확인)
- 해결:
  - PC/Android: `fullscreenchange`에서 커스텀 오버레이 그대로 유지 (`.subtitle-overlay`가 `.video-player-wrap` 자식이라 fullscreen 안에서 보임), native track은 idle 모드 유지
  - iOS: `<track>` 엘리먼트 → `addTextTrack()` API + `VTTCue` 직접 추가 방식으로 전환 (src 파싱/CORS 우회)
  - iOS idle 모드: `'disabled'` → `'hidden'` (user-disabled 트랩 회피)
  - **fullscreen 클릭 시점 cue 재주입**: cue가 비어있으면 `_subInjectCues()`로 즉석 재주입 (핵심 fix)
  - `webkitEnterFullscreen` 직전 mode='showing' 동기 설정 (user gesture 컨텍스트 안)
  - `webkitbeginfullscreen` 이벤트에도 cue 재주입 호출 (비디오 컨트롤바 자체 fullscreen 버튼 경로 대비)
  - VTT 헤더의 `STYLE\n::cue {...}` 블록 제거 (iOS Safari가 STYLE 블록 파싱 시 cue 무효화)
  - VTTCue 위치 고정 (`vc.line=90; vc.lineAlign='end'`) — iOS가 default 위치로 그릴 때 video letterbox 밖 잘림 방어
- 적용 범위: app.js 메인 자막 로더 + 게시판 인라인 자막, share.php `_updateTrackElement` (총 3곳)
- 효과: PC/Android/iOS 일반/공유 fullscreen 모든 컨텍스트에서 자막 안정 표시

**4. 공유 페이지 fullscreen UI 동기화 + 자막 컨트롤 추가**
- `.fs-idle` 셀렉터에 `.video-play-overlay`(일시정지 버튼) 누락 보완 — 배지/시간/멀티오디오와 같이 2.5초 비활동 시 동기화 숨김
- 멀티오디오 셀렉터 fs-idle 시 안 사라지던 문제 해결 — `.fs-idle:hover` 명시로 `.playing:hover` 룰과 특이도 동등화 (cascade 순서로 fs-idle 우선)
- fullscreen용 floating subtitle controls 추가 (`.fs-subtitle-controls`)
  - 메인 사이트 패턴 차용 (A-/A+/▲/▼/-0.5s/0.0s/+0.5s/↺ 8개 버튼)
  - 기존 `.player-controls`와 같은 `subActions` 객체 공유 → 한쪽 조정이 다른 쪽 즉시 반영
  - 자막 cue 유무에 따라 `wrap.has-subs` 클래스 토글 → 자막 없는 영상에선 컨트롤 자체 표시 안 됨
  - hover/show-controls 시 표시, fs-idle 시 동기화 숨김

#### v5.8.1a (2026-04-30)
**텍스트 미리보기 모달 스크롤바 가시성 개선**
- 본질: 글로벌 스크롤바(`rgba(0,0,0,0.15)` 검정 계열)가 다크 배경(`#1e1e1e`) 위에서 사실상 안 보임
- 해결: `.preview-text` / `.preview-code` 전용 스크롤바 오버라이드 추가 (assets/css/style.css 라인 6119~)
  - 평상시 4px (글로벌과 동일 두께 유지) + 흰색 25% 불투명도
  - hover 시 6px + 45% 불투명도 (선명도 강화)
  - `transition: background 0.15s` 부드러운 색 전환
- 적용 범위: 텍스트(.txt/.log/.md 등) + 코드 미리보기(`previewExtensions.code` 항목) 한정, 다른 영역 글로벌 스타일 유지

**세션 관리 유령 세션 잔존 본질 해결**
- 본질: `session_regenerate_id(true)` 호출 시 PHP 세션 ID는 X→Y로 변경되지만, sessions DB의 X는 안 지워짐 → 24시간 동안 유령 세션 잔존
- 영향: 같은 PC에서 재로그인 시 세션 관리 화면에 동일 기기가 2개로 표시
- 해결: 라인 120 (login) + 라인 1918 (2FA verify)에서 `session_regenerate_id` 호출 직전에 `removeSession()` 호출하여 이전 session_id를 DB에서 즉시 제거
- 회귀 위험 없음 (이미 존재하는 `removeSession` 함수 재활용)

#### v5.8.1 (2026-04-30)
**rhwp 0.7.3 → 0.7.8 업그레이드**
- 가이드 v5 표준 절차 진행 (사전 회귀 점검 → clone → npm @rhwp/core 0.7.8 → studio 빌드 → 패치 J1/P1/P2 자동 적용 → 검증 15/15 통과)
- 빌드 결과: `index-Db8NVuPi.js` / `index-ro3nVBB2.css` / `rhwp_bg-BqZZZ9ls.wasm`
- 0.7.7/0.7.8 새 기능 활용: 다단/페이지네이션 정밀화, 수식 렌더링, 표 분할 등
- 패치 J1 (file:save 단축키 매핑 제거) 1개 매칭 → 제거 성공
- 패치 P1 (절대경로) 0개 매칭, 패치 P2 (`../images/`) 1개 매칭 → 제거 성공
- HWPX 차단 5중 방어 / save·save-as·notifyParentFileChanged·syncSuspended 모두 보존
- 가이드 v4 → v5 갱신 (0.7.8 실측 결과 반영, P1/P2 버전별 매칭 패턴 정정, STEP 9 버전 정책 명시)

#### v5.8.0b (2026-04-28 ~ 2026-04-30)
**메인 인덱스 동영상 미리보기 — 사이드 플레이리스트 패널**
- 같은 폴더 동영상 2개 이상일 때 자동 활성화 (flex 방식)
- 트랙 클릭 시 깜빡임 0 (`showPreview()` 직접 호출, 모달 유지)
- 자동 다음 재생 + 마퀴/툴팁/자동 스크롤
- 재생 중 토글 버튼 + 패널 자동 숨김 (배지 패턴, hover 시 표시)
- 재생 중 영상 폭 자동 확장 (transition 0.25s)

**공유 폴더 음악 스트리밍 — 캐싱 강화 (메인과 동등)**
- `share.php` cover/lyrics 분기 `session_write_close()` (병렬 처리)
- 가사 로딩 레이아웃 시프트 방지 (`_loadLyrics` 응답 시점 처리)
- folder_tracks PHP 캐시 (30s TTL + 폴더 mtime 무효화)
- 트랙 데이터 mtime 추가 + URL `&v=mtime` (HTTP 캐시 적중)
- immutable 캐시 헤더 (lyrics, `v` 파라미터 있을 때 30일)
- Cover 네거티브 캐시 (`.nocover` 마커, 커버 없는 mp3)

**공유 캐시 자동 정리 (`_cleanShareCache`)**
- deleteShare (수동 삭제)
- 만료 자동 정리 (생성자/accessShare/cleanupExpiredShares/filedrop)
- 횟수 초과 자동 정리
- 파일/폴더 삭제 시 연관 공유 자동 삭제 (FileManager:cleanupSharesForPath)
- 폴더 cover 정리: folder_tracks 캐시 만료 시 폴더 직접 스캔 fallback

**멀티오디오 메인+공유 일관 (2026-04-29)**
- 메인 audio_cover/audio_lyrics reuse 비교에 `audio` + `force_sw` + `client_session` 추가
- `client_session` = PHP `session_id()` SHA-256 hash 16자 → 같은 사용자 다중 기기 폴더 공유 시 독립 재생
- `force_sw=1` 케이스 reuse 건너뛰기 처리 → 폴더 두 개 동시 재생 가능
- 공유 페이지 (share.php)도 동일 로직 적용 → 메인/공유 일관성

**모바일 UX 개선 (2026-04-29)**
- 메인 페이지 모바일 (≤1024px) 사이드 플레이리스트 토글+패널 숨김
- 공유 페이지는 모바일에서 영상 하단 패널 표시 (요청 사항)
- iOS 전체화면 자막 강제 표시: `trackEl.default = true` (iOS만) + `setTrackMode('showing')` 다중 시점 강제
- 공유 페이지 영상 제목 `word-break: break-all` (긴 파일명 컨테이너 초과 방지)

**설정 모달 개선 (2026-04-29)**
- PC 설정 탭 가로 휠 + 드래그 스크롤 (`_settingsTabsWheelBound` 1번만 등록 가드)
- 5px 임계값으로 클릭 vs 드래그 구분 → 탭 클릭 동작 보존
- 드래그 중 `cursor: grabbing` + `userSelect: none` 시각 피드백

**세션 관리 개선 (2026-04-29)**
- `isLoggedIn`에서 `recordSession` 호출 추가 → Remember Me 자동 로그인 시 sessions DB 기록 보장
- 매 API 요청 시 `last_activity` 갱신 → 24시간 비활성 정리 방어
- `getSessions` raw user_agent 반환 → 클라이언트 `parseUserAgentDetails`로 정확 표시
- 로그인 기록과 동일 패턴: PC/모바일/태블릿 구분 + 아이콘 표시

**iOS 음악 썸네일 누락 본질 해결 (2026-04-30)**
- 본질: iOS Safari/Chrome IndexedDB blob 직렬화/역직렬화 후 손상 (iOS 26 베타 가능성)
- 해결 1: `FSCoverCacheDB._isIOS = true` → `_disabled = true` (iOS는 IDB 영구 비활성, 메모리 캐시만)
- 해결 2: `_updateTrackMeta` duration 갱신 시 `_vsRender` 강제 호출 제거 (메인 app.js 라인 775~778 주석, 공유 fs-audio-player.js 라인 433~436 주석)
  - 본질: VS_RENDER 다중 호출 → 모든 li 재생성 → 비동기 cover fetch 시점에 IMG_DISCONNECTED 폭증 → 일부 트랙 누락
  - 메인+공유 일관 적용 (둘 다 같은 race condition)
- PC는 그대로 30일 IDB 영구 캐시 유지 → 데이터 절감
- 공유 페이지에도 FSCoverCacheDB 추가 (메인과 같은 DB_NAME → IDB 공유, PC만)

**HLS 진단 서버 코드 정리 (2026-04-30)**
- `api/FileManager.php` 라인 2636~2651, 3470~3488 HLS_DIAG 서버 로그 작성 코드 주석 처리 (`/* */`)
- 본질: 가드 없이 매 영상 재생마다 `data/hls_diag.log` 작성 → 무한 증가 위험
- 클라이언트 진단 (`window._hlsDiag = false`)은 비활성 가드로 안전했지만, 서버측은 가드 없음 발견
- 다른 진단 코드 (cover_diag, share.php debug_log)와 동일 패턴: 다음 디버깅 위해 보존

**공유 페이지 멀티오디오 중복 표시 본질 해결 (2026-04-30)**
- 본질: `_fetchShareInfo`가 `startTranscode` 재호출 시 두 번 실행 → `appendChild` 누적으로 옵션 N×2개 표시
- 시나리오: 네이티브 재생 실패 → `loaded = false` → `startTranscode` 재호출 → `_fetchShareInfo` 2차 호출 → 7개 + 7개 = 14개
- 추가 본질: change 이벤트 리스너도 누적 등록 → 트랙 변경 시 HLS destroy/fetch 두 번 발생
- 해결: 메인 `_buildAudioTrackUI` 패턴 적용 — `wrap.innerHTML` 통째 교체로 select 자체 새로 생성
  - 이전 select DOM 제거 → 이전 change 리스너 자동 가비지 콜렉션
  - 옵션 누적 0, change 리스너 누적 0 (메인+공유 일관)
- 보안: `<>"'` HTML 이스케이프 적용 (영상 메타데이터 title XSS 방어)
- 메인 `_buildAudioTrackUI`도 `this.escapeHtml(info)` 적용 (메인+공유 일관 강화)

#### v5.8.0a (2026-04-26)
- rhwp 0.7.6 시도 → PR #335 회귀(이미지 비율 무시) 발견
- rhwp 0.7.3 + v5.8.0a로 롤백 결정
- MP3 IDB 캐시 + mtime 기반 무효화
- rhwp/OnlyOffice 외부 스토리지 정책 통일 (3곳)
- rhwp_viewer/editor를 office_viewer.php 패턴으로 통일 (read/write 메서드)
- SMB 어댑터 추가 (4파일 9곳)
- Vault 컨텍스트 'open' 액션 처리 추가

#### v5.7.9 시리즈 (2026-04~)
- OnlyOffice 통합 본격화 (JWT, SSRF 방어, callback URL 수정)
- HLS/MMS 비디오 스트리밍 안정화 (iOS, FFmpeg `-re`)
- 모바일 UI 반응형 통일 (1024px)
- 100+ 보안 패치 (Path Traversal, Zip Slip, OIDC nonce, AES-GCM, XSS)
- WebDAV 서버 (mydav.php)
- SFTP/FTP/WebDAV 외부 스토리지

#### v5.6 ~ v5.7 초기
- 작업 큐 시스템 (Job Queue)
- E2E 암호화 Vault (AES-256-GCM)
- 다국어 (ko/en, 1,400+ 키)
- Docker 배포 옵션 (시놀로지)
- OnlyOffice 초기 통합

---

*FileStation v5.8.1j — 한국 사용자를 위한 자체호스팅 웹 NAS*
*최종 업데이트: 2026-05-26 (rhwp 0.7.13 기준)*
