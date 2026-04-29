# FileStation v5.8.1

> 🇰🇷 **한국 사용자를 위한 자체호스팅 웹 NAS** — HWP/HWPX 뷰어, OnlyOffice 통합, E2E 암호화 Vault, 5종 외부 스토리지, HLS 비디오 스트리밍, MP3 플레이어 일체형

---

## 🌟 핵심 특징

| 기능 | 설명 |
|---|---|
| 📄 **HWP/HWPX 뷰어 + 편집기** | rhwp 0.7.8 통합 — **자체호스팅 NAS 중 글로벌 유일** |
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
| **한글** | **HWP, HWPX (rhwp 0.7.8 전용 뷰어 + 편집기)** |
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
| 파일 업로드 | 파일 올리기 |
| 폴더 업로드 | 폴더 올리기 |
| 붙여넣기 | 복사/잘라낸 파일 붙여넣기 |
| 새로고침 | 목록 새로고침 |

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

- **rhwp 0.7.8** — HWP/HWPX 뷰어 + 편집기 (Rust+WASM)
- **OnlyOffice Document Server** — Office 문서 편집 (JWT 인증)
- **WebDAV 서버** — `mydav.php` (Windows 네트워크 드라이브)

---

## 🚀 설치

### 1. 파일 복사

```bash
# 웹 서버 디렉토리에 파일 복사
unzip FileStation_v5.8.1.zip -d /var/www/html/filestation
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
├── data/                         # 사용자 데이터 (DB, 캐시 등)
└── shared/                       # 공유 자원
```

---

## ⚙ 시스템 설정

### config.php 주요 설정

```php
// 사이트 정보
define('SITE_NAME', 'FileStation');
define('APP_VERSION', '5.8.1');

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
버전: 0.7.8
파일: assets/rhwp/
업그레이드: rhwp_업그레이드_가이드_v4.md 참조
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
- ⚠ **rhwp 0.7.8 PR #335 회귀** — 가로로 긴 이미지가 비율 무시하고 늘어나는 증상 (0.7.6부터 잔존)
- ⚠ **JsonDB는 다중 사용자 환경에서 한계** — 수십 명 미만 환경 권장
- ⚠ **HWPX 직접 저장 미지원** — rhwp 0.7.8의 베타 단계 제한, HWP 형식만 저장 가능

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

**현재 버전**: v5.8.1 (rhwp 0.7.8 기반)

### 주요 변경 이력

#### v5.8.1 (2026-04-30) ⭐ 현재
**rhwp 0.7.3 → 0.7.8 업그레이드**
- 가이드 v5 표준 절차 진행 (사전 회귀 점검 → clone → npm @rhwp/core 0.7.8 → studio 빌드 → 패치 J1/P1/P2 자동 적용 → 검증 15/15 통과)
- 빌드 결과: `index-Db8NVuPi.js` / `index-ro3nVBB2.css` / `rhwp_bg-BqZZZ9ls.wasm`
- 0.7.7/0.7.8 새 기능 활용: 다단/페이지네이션 정밀화, 수식 렌더링, 표 분할 등
- 패치 J1 (file:save 단축키 매핑 제거) 1개 매칭 → 제거 성공
- 패치 P1 (절대경로) 0개 매칭, 패치 P2 (`../images/`) 1개 매칭 → 제거 성공
- HWPX 차단 5중 방어 / save·save-as·notifyParentFileChanged·syncSuspended 모두 보존
- ⚠ **PR #335 회귀 0.7.8에서도 미패치** — 회귀 인지하고 진행 결정 (새 기능 우선)
  - svg.rs 9곳 모두 `preserveAspectRatio="none"` 그대로
  - viewer/editor 모두 회귀 회피 후처리 없이 rhwp 원본에 맡김
  - 0.7.9+ 출시 시 svg.rs 패치 재점검 권장
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

*FileStation v5.8.1 — 한국 사용자를 위한 자체호스팅 웹 NAS*
*최종 업데이트: 2026-04-30*
