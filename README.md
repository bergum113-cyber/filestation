# FileStation v5.8.3c

![version](https://img.shields.io/badge/version-v5.8.3c-blue)
![PHP](https://img.shields.io/badge/PHP-8.0~8.4-777BB4?logo=php&logoColor=white)
![license](https://img.shields.io/badge/license-GPL--3.0-green)
![webserver](https://img.shields.io/badge/server-Apache%20%7C%20Nginx%20%7C%20IIS-orange)
![rhwp](https://img.shields.io/badge/rhwp-0.8.2-9cf)
![platform](https://img.shields.io/badge/platform-self--hosted-lightgrey)

> 🇰🇷 **한국 사용자를 위한 자체호스팅 웹 NAS** — HWP/HWPX 뷰어, OnlyOffice 통합, E2E 암호화 Vault, 5종 외부 스토리지, HLS 비디오 스트리밍, MP3 플레이어 일체형

---

## 🌟 핵심 특징

| 기능 | 설명 |
|---|---|
| 📄 **HWP/HWPX 뷰어 + 편집기** | rhwp 0.8.0 통합 — **자체호스팅 NAS 중 글로벌 유일** |
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
| **한글** | **HWP, HWPX (rhwp 0.8.0 전용 뷰어 + 편집기)** |
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

- **rhwp 0.8.0** — HWP/HWPX 뷰어 + 편집기 (Rust+WASM)
- **OnlyOffice Document Server** — Office 문서 편집 (JWT 인증)
- **WebDAV 서버** — `mydav.php` (Windows 네트워크 드라이브)

---

## 🚀 설치

### 1. 파일 복사

```bash
# 웹 서버 디렉토리에 파일 복사
unzip FileStation_v5.8.2d.zip -d /var/www/html/filestation
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
버전: 0.8.2
파일: assets/rhwp/
업그레이드: rhwp_업그레이드_가이드_v6.6.md 참조
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
- **rhwp 0.7.19 업그레이드 (2026-07-19)** — 코어(npm shasum 검증 진본) + studio 소스 빌드 후 패치(J1 file:save, J2 file:print, P2 CSS경로) 재적용. 저장 지오메트리 신호 존중 계보의 렌더·편집 정합, 표 페이지네이션 정밀도, BinData 지연 로딩(RSS 244MB→49MB), HML 열기·저장 신규(공개 API 하위호환 PATCH). HWPX 저장 차단은 `rhwp_editor.php` 서버측 확장자 검증(.hwp만 허용)으로 건재 — 단, 가이드 검증 #11의 `sourceFormat` 가드 패턴은 0.7.18에서 이미 업스트림에서 사라진 스테일 패턴으로 확인됨(회귀 아님, 가이드 개정 권장). studio JS 구문 검증 통과. ⚠️ 렌더/편집 실동작은 실기기 확인 필요.
- ✅ **HWPX 직접 저장 지원 (2026-07-26 개방)** — HWPX 문서를 HWP로 변환하지 않고 **HWPX 그대로** 저장/다른 이름으로 저장 가능. 저장 형식은 원본 형식을 따름(HWP→HWP, HWPX→HWPX)

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

**현재 버전**: v5.8.3c (rhwp 0.8.2 기반)

### 주요 변경 이력

#### v5.8.3c (2026-07-27) ⭐ 현재

- **[업그레이드] rhwp 0.8.0 → 0.8.2** — 아래 "rhwp 업그레이드 이력" 참조. 커스텀 로직 변경 없음(`rhwp_editor.php` diff 2줄: studio JS 파일명 + `@rhwp_version`).
- 버전 번호 상향(5.8.3b → 5.8.3c)에 따라 `?v=APP_VERSION` 캐시 버스팅이 자동 무효화되어, 브라우저가 갱신된 studio 자산을 새로 받는다.

#### v5.8.3b (2026-07-22~26)

**[버그 수정] H264/MP4 변환 후 파일 인덱스 미갱신 — 대용량 스토리지 목록에 변환 결과 미반영 (2026-07-22, 펜닐님 제보)**

- **증상**: 대용량 스토리지에서 동영상을 H264/MP4로 변환하면, **서버 실제 파일은 정상적으로 `.mp4`로 생성**되고 원본(`.ts` 등)은 휴지통으로 이동되는데, **목록에는 변환 전 원본(`.ts`)이 유령처럼 그대로 표시**되고 그 항목의 정보를 조회하면 "파일을 찾을 수 없습니다"가 뜸. 목록에 mp4로 보이는 파일은 정상.
- **원인**: FileStation은 네트워크 마운트/대용량 스토리지의 콜드 접근 지연을 피하려 목록을 **파일 인덱스(SQLite)로 즉답**함(`tryListFromIndex` — 원격 스토리지 또는 파일 3만 개 이상 로컬 + 완전동기화 이력이 있을 때만 발동). 폴더생성·삭제·이름변경·이동·복사·압축·압축해제·업로드·복원 등 **다른 모든 쓰기 작업은 인덱스를 갱신**하는데, **H264 변환 경로만 인덱스 갱신이 누락**돼 있었음. 그 결과 인덱스에는 새 `.mp4`가 추가되지 않고 원본 `.ts`도 제거되지 않아, 인덱스 즉답 목록에 옛 항목이 유령으로 남음. (소용량 로컬 스토리지는 실시간 stat로 목록을 만들어 애초에 이 증상이 없음 — 대용량 인덱스 스토리지에서만 발현.)
- **왜 변환만 누락됐나**: 다른 작업은 `api.php` 액션 핸들러 레벨에서 인덱스를 후처리하는데, `convert_h264` 액션은 **SSE 스트리밍**이라 함수가 스트림을 직접 끝내고 바로 `break`하므로 액션 레벨 후처리가 불가능. 즉 **변환 함수 내부에서 갱신하는 것이 유일하게 가능한 위치**.
- **수정** (`api/FileManager.php` `convertToH264Mp4`, 성공 경로에 추가):
  - 변환 산출물 rename 성공 직후 → `reindexPath()`로 새 `.mp4`를 인덱스에 추가(다른 작업의 `addFile`/`indexFolder`와 동일 계열, 경로는 슬래시 정규화로 `getFolderListing` 조회 형식과 일치).
  - 원본을 휴지통으로 이동한 경우(`$trashed`)에 한해 → `fileIndex->removeFile()`로 원본을 인덱스에서 제거(일반 `delete()`가 `moveToTrash` 후 `removeFile`을 부르는 것과 동일 패턴). 권한 없어 보존됐거나 출력=원본이면 원본이 디스크에 남으므로 인덱스도 유지.
  - **두 갱신 모두 `try/catch(\Throwable)`로 방어**: 이 함수는 SSE라 인덱스 갱신이 예외를 던지면 `done` 이벤트를 못 보내 "변환은 성공했는데 실패로 보이는" 문제가 생길 수 있음. 인덱스 갱신은 부가 후처리이므로 실패해도 변환 성공 자체는 그대로 완료(실패 시 `convert_debug.log`에만 기록).
  - 인덱스 미사용(available=false) 스토리지에선 `addFile`/`removeFile`이 무해한 no-op — 소용량 로컬 등 기존 정상 동작에 영향 없음.
- **범위 점검**: 나열된 전체 쓰기 작업(업로드·폴더생성·새파일·삭제·이름변경·이동/잘라내기·복사/붙여넣기·압축 생성·압축 해제·복원·파일버전 복원·휴지통 비우기·검색 인덱스 재구축/동기화)의 인덱스 갱신 여부를 소스로 전수 확인 → **변환만 누락**이었고 나머지는 정상(함수 또는 액션 레벨에서 처리)임을 확인. 휴지통 비우기는 휴지통이 인덱스 대상 밖이라 갱신 불필요(정상).
- **진단 로그 (v5.8.3b 초기 진단판에서 도입, 유지)**: 변환 성공 경로에도 `data/convert_debug.log` 기록 추가(`PROBE`/`OK_OUTPUT`/`VERIFY`/`RENAMED`/`TRASH`/`INDEX`) — 기존엔 실패 시에만 로그가 남아 "성공했는데 로그가 없어 실패처럼 보이는" 착시가 있었음. 이제 성공/실패 모두 기록.
- **로그 게이트 방식 통일**: `convert_debug.log`가 다른 진단 로그와 달리 **파일을 무조건 자동 생성**하던 것을, `files_perf.log`·`scan_perf.log`와 동일한 **`is_file()` 게이트 방식**으로 변경 — **`data/convert_debug.log` 빈 파일이 미리 있을 때만 기록**하고, 없으면 아무것도 하지 않음(자동 생성 안 함). 변환 진단이 필요할 때만 빈 파일을 만들어 두면 되고, 평상시엔 불필요한 로그가 안 쌓임. (전체 로그 지점을 전수 점검한 결과 나머지 로그 — `files_perf`/`scan_perf`/`extract_debug`(EXTRACT_DEBUG 스위치)/`hls_diag`·`webdav_debug`·`folder_stream_debug`(주석)/`onlyoffice_debug`(file_exists 게이트)/`debug_upload`(DEBUG_UPLOAD 스위치) — 는 이미 게이트/스위치/주석으로 자동 생성되지 않음을 확인. `filestation.log`는 정식 정보 로거라 대상 아님.)
- **[UI] 인덱스 동기화 버튼 완료 후 목록 자동 새로고침**: 폴더의 인덱스 동기화 버튼(`#btn-index-sync`)이 서버 인덱스는 정상 재구축(`index_rebuild_stream` → `rebuildStorage`, 파일시스템 재스캔)하지만, 완료 후 **클라이언트 목록을 다시 불러오지 않아** 수동 새로고침을 눌러야 변경(예: 변환된 확장자)이 화면에 반영되던 문제를 개선. 동기화 성공 시 `loadFiles(false)`(캐시 무시, 서버에서 재로드)를 호출하도록 추가 — 다른 작업(변환/삭제/업로드 등)이 완료 후 목록을 갱신하는 것과 동일한 방식. 이제 동기화 버튼 한 번으로 화면까지 갱신됨. (`assets/js/app.js`, 서버측 인덱스 로직·동기화 동작 자체는 미변경 — 순수 클라이언트 UI 갱신 보강.)
- **[버그 수정] 지연 발생 시 좌측 사이드바 스토리지 목록이 안 뜨는 문제 (2026-07-23, 펜닐님 제보)**
  - **증상**: 지연이 걸리면 좌측 사이드바에 스토리지(내 파일 / 공용 폴더 / 외부 스토리지)가 **아예 표시되지 않고**, 아무 오류 메시지도 없음. 새로고침을 몇 번 하면 정상 로딩됨.
  - **원인 1 — 재시도가 1회뿐이고 실패 시 영구 포기**: `loadStorages()`는 실패 시 1초 뒤 딱 1회만 재시도하는데, 재시도 플래그(`_storageRetried`)가 **성공했을 때만** 리셋되는 구조여서 **연속 2회 실패하면 그 뒤로는 재시도가 아예 없었음** → 사이드바가 빈 채로 굳어 수동 새로고침이 필요했음. 지연 상황에서는 1초 뒤 재시도도 함께 실패하기 쉬워 딱 이 경로를 탐.
  - **원인 2 — 요청 취소(abort) 시 널 가드 누락**: `api()`는 `AbortError`일 때 `null`을 반환하는데(`loadFiles`에는 `if (!res) return` 가드가 있으나 `loadStorages`에는 없었음), 가드 없이 `res.success`를 참조해 **TypeError**가 발생. 이 예외는 async 내부에서 나므로 호출부의 `try/catch`(동기 호출만 감쌈)에도 잡히지 않고 전역 `unhandledrejection` 핸들러도 없어 **완전히 무음으로 죽으면서 재시도 로직조차 타지 못했음**.
  - **수정** (`assets/js/app.js` `loadStorages`):
    - 재시도 스케줄러 `_retryLoad()` 도입 — **최대 3회(1초→2초→3초 백오프)** 재시도하고, 소진되면 카운터(`_storageRetryCount`)를 **리셋**해 다음 트리거(페이지 새로고침 / 탭 복귀 자동갱신)에서 다시 재시도할 수 있게 함.
    - `api()` 응답에 **`if (!res)` 널 가드** 추가(`loadFiles`와 동일 계열) — abort 시 TypeError 대신 재시도 경로로 진입.
    - 세션 만료 시에는 단축평가로 재시도하지 않는 **기존 동작 유지**. 성공 시 카운터 0 리셋.
    - 진단 로그 보강: `loadStorages_start`에 `retryAttempt`, `loadStorages_retry_scheduled`에 `attempt`/`reason`, `loadStorages_failed_final`에 `reason` 추가.
  - **무한 루프 없음**: 재시도는 3회에서 종료되며 소진 시 새 예약을 걸지 않음(요청 총 4회 / 약 6초). 동시 호출 시에도 카운터를 공유해 재시도 총량이 오히려 줄어드는 방향(fail-closed).
  - **원인이 아니었던 것(오진 방지 확인)**: 무거운 액션(`files`/`index_rebuild_stream`/`index_sync`/`convert_h264`/압축·해제 스트림)은 모두 `session_write_close()`로 세션 락을 조기 해제하므로 **세션 락 대기 아님**. 서버 `storages` 액션도 `getStorages()`가 DB 기반이라 빠름(실측 17~91ms, 최대 485ms).
  - ⚠️ **실기기 확인 필요**: 지연 상황에서 사이드바가 **자동으로** 복구되는지(수동 새로고침 없이). 제보 로그(2026-07-22)에는 `loadStorages` 26회가 전부 성공해 실패 사례가 담기지 않았으므로, 위 두 원인은 **소스상 확인된 결함**이며 실제 발생 사례로 확증된 것은 아님. 재발 시 진단 지문: `loadStorages_start`만 있고 뒤따르는 `loadStorages_response`가 없으면 원인 2, `loadStorages_failed_final`이 있으면 원인 1.
- **[UI] 게시판 검색창 — 모바일에서 포커스 시 가로 확장이 동작하지 않던 문제 (2026-07-24, 펜닐님 제보)**
  - **증상**: PC에서는 게시판 목록 툴바의 검색창을 클릭하면 가로로 살짝 넓어지는데(180px → 220px), 모바일에서는 기본 폭이 작은데도 클릭해도 전혀 넓어지지 않음.
  - **원인**: PC의 확장은 CSS `:focus`가 아니라 엘리먼트의 **인라인 `onfocus`/`onblur`가 `this.style.width`를 직접 바꾸는 방식**(`index.php`의 `#board-inline-search`). 그런데 모바일용 규칙 `@media (max-width:1024px) { #board-inline-toolbar #board-inline-search { width: 80px !important; } }`가 **`!important`**여서, 스타일시트의 `!important`가 일반 인라인 스타일을 이겨 `onfocus`가 넣은 폭이 무시됐음. 모바일에는 `:focus` 규칙도 없었음.
  - **수정** (`assets/css/style.css`, 위 미디어쿼리 블록 내부에 추가):
    ```css
    #board-inline-toolbar #board-inline-search { width: 100px !important; }   /* 기본 폭 */
    #board-inline-toolbar #board-inline-search:focus { width: 140px !important; }  /* 포커스 확장 */
    ```
    모바일 기본 폭을 **80px → 100px**로 키우고, 포커스 시 **140px**로 확장(펜닐님 지정값). 같은 `!important`끼리는 특이도가 높은 `:focus`(ID 2개+의사클래스)가 이기므로 적용되고, 포커스 해제 시 `:focus`가 풀리며 기본 규칙(100px)으로 자연히 복귀함. 전환 애니메이션은 엘리먼트 인라인 `transition: width 0.2s`가 그대로 적용됨.
  - **영향 범위 한정**: 폭 1024px 초과인 모바일 UA 기기는 이 미디어쿼리가 적용되지 않아 기존처럼 인라인 방식(180→220px)으로 동작하며 **변경 없음**. PC 동작도 미변경.
  - **값 일치 정리**: 같은 요소의 모바일 기본 폭을 선언하는 `body.is-mobile #board-inline-toolbar #board-inline-search`도 80px → 100px로 함께 맞춤. 이 규칙은 `!important`가 없어 엘리먼트 인라인 `style="width:180px"`에 항상 밀리는(=실제 렌더링에 관여하지 않는) 선언이라 **동작 변화는 없으며**, 두 곳의 기본 폭 값이 어긋나 생길 혼선을 막기 위한 정리임.
  - **미적용(별건)**: 상세화면 검색창 `#board-detail-search`는 인라인 스타일에 `onfocus`/`onblur`와 `transition`이 아예 없어 **PC에서도 확장 동작이 없음**. 이번 수정 범위에 포함하지 않음.
  - ⚠️ **실기기 확인 필요**: 모바일에서 검색창이 기본 100px로 보이고 클릭 시 140px로 부드럽게 넓어지는지, 넓어진 상태에서 글쓰기 버튼이 밀리거나 툴바가 넘치지 않는지(breadcrumb은 `min-width:0` + 말줄임이라 줄어들도록 되어 있음).
- **[기능] 게시판관리 — 게시글 일괄(선택) 삭제 (2026-07-24, 펜닐님 요청)**
  - **배경**: 글을 하나씩 열어 들어가 삭제해야 해서 정리가 번거로웠음. 목록에서 여러 글을 골라 한 번에 지울 수 있게 함.
  - **동작**:
    - 관리자(`admin`/`sub_admin`)에게만 목록 맨 앞에 **체크박스 열**이 상시 표시됨. 헤더의 전체선택 체크박스는 **현재 페이지에 보이는 글**을 토글(일부만 선택 시 indeterminate 표시).
    - **공지글은 체크박스를 아예 만들지 않아 선택 자체가 불가** — 공지는 저장 시 `is_notice`/`is_pinned`가 함께 설정되므로 둘 중 하나라도 켜져 있으면 제외.
    - 목록 **오른쪽 하단**에 `🗑️ 게시판관리` 버튼(관리자 전용). 누르면 `N개의 게시글을 삭제하시겠습니까?` 확인 팝업(기존 `confirmDelete` 빨간 모달 재사용) → 확인 시 일괄 삭제 후 목록 자동 갱신.
    - 체크박스/셀 클릭은 `event.stopPropagation()`으로 행 클릭(글 열기)과 분리.
  - **서버**(`api.php`, 신규 액션 `board_posts_delete` 추가 — 기존 `board_post_delete`는 미변경):
    - 이 DB는 JSON 파일 방식이라 단건 액션을 N번 부르면 `board_posts` 전체를 **N번 다시 쓰게** 됨. 그래서 일괄 액션을 따로 두어 **한 번의 load/save**로 처리.
    - 권한은 단건 삭제와 동일(작성자 본인 또는 관리자), 글·댓글·첨부 폴더까지 동일하게 정리.
    - 안전장치: 입력 ID 정수화·중복 제거, 최대 500건 제한, **공지글 서버측 재차 제외**, `board_id` 범위 가드(오래된 화면에서 다른 게시판 ID가 섞여 와도 삭제되지 않음), 첨부 정리 실패는 `try/catch`로 삼켜 글 삭제 자체를 되돌리지 않음. 응답에 `deleted`/`skipped` 수 반환.
  - **검증**: `php -l`·`node --check` 통과, 4개 파일 모두 **삭제된 줄 0(순수 추가)**. 서버 판정 로직을 추출해 시뮬레이션 — 관리자 전체선택 시 공지·타 게시판·고정글은 보존하고 일반글만 삭제, 일반 사용자는 본인 글만 삭제, 중복/음수/문자 혼입 입력도 정상 정규화됨을 확인.
  - **변경 파일**: `api.php`(신규 액션), `assets/js/app.js`(체크박스 열·액션 바·함수 4개), `index.php`(하단 액션 영역 div), `assets/css/style.css`(체크박스 열 폭 PC/모바일).
  - ⚠️ **실기기 확인 필요**: 관리자 계정에서 체크박스 열과 하단 버튼이 보이는지, 체크박스를 눌러도 글이 열리지 않는지, 공지글에는 체크박스가 없는지, 전체선택이 현재 페이지 글만 잡는지, 삭제 후 목록·페이지네이션이 정상 갱신되는지. 일반 사용자 화면에는 체크박스/버튼이 보이지 않아야 함.- **무손상 확인**: 변경은 `api/FileManager.php` 단일 파일, **순수 추가만(기존 줄 삭제·변경 0)**. `php -l` 통과. 버전 규칙에 따라 버그 수정이므로 버전 번호는 유지(5.8.3b 재패키징).
- **[UI] 게시글 작성 버튼 라벨 '저장' → '등록' (2026-07-24, 펜닐님 요청)**
  - 게시글 작성/수정 폼의 확정 버튼 라벨을 **등록**(영문 Submit)으로 변경.
  - **주의점**: 이 버튼은 `index.php`의 라벨 외에, 저장 진행 중 `saving`으로 바꿨다가 완료 시 `save`로 **되돌리는 JS가 두 곳**(`saveBoardPost` 진입부·finally) 있어, HTML만 고치면 첫 등록 직후 다시 '저장'으로 돌아감. 세 곳을 함께 변경.
  - **공용 키 오염 방지**: `save`/`saving`은 앱 전역에서 쓰는 공용 번역 키(텍스트 편집기 저장 버튼 등)라 값을 바꾸면 모든 저장 버튼이 함께 바뀜. 그래서 값을 건드리지 않고 **전용 키 `board_post_submit`/`board_post_submitting`을 신규 추가**(`lang/ko.json`, `lang/en.json`)해 게시글 버튼에서만 사용.
  - 텍스트 편집기(`saveTextEditor`)의 동일한 형태의 코드는 **미변경**(같은 문자열이라 문맥으로 구분해 교체).
  - **검증**: `node --check`·`php -l` 통과, 두 언어파일 JSON 파싱 정상, 기존 키 값 변경 0건(추가 2개만).- ⚠️ **실기기 확인 필요**: ① 대용량(인덱스) 스토리지에서 `.ts`→mp4 변환 후 **목록에 즉시 mp4로 반영**되는지 ② 원본 휴지통 이동 시 옛 `.ts` 유령 항목이 사라지는지 ③ 소용량 로컬 등 기존 정상 스토리지의 변환 동작에 변화 없는지. (기존에 이미 어긋난 유령 항목은 해당 폴더 검색 인덱스 재동기화로 정리됨.)
- **[UI] 게시판관리 버튼을 페이지네이션과 같은 줄로 이동 (2026-07-24, 펜닐님 요청)**
  - 기존에는 페이지네이션 **아래 줄** 오른쪽에 있던 `🗑️ 게시판관리` 버튼을, 페이지네이션과 **같은 줄 오른쪽**에 배치.
  - **구현**: 페이지네이션(`#board-inline-pagination`)과 버튼(`#board-admin-actions`)을 `#board-bottom-row`(`position:relative`)로 감싸고, 버튼만 `position:absolute; right:14px; top:50%`로 오른쪽에 겹쳐 올림. 페이지네이션의 **가운데 정렬과 전체 폭 상단 구분선(`.board-paginate`의 `border-top`)을 그대로 보존**하기 위해 flex 재배치 대신 오버레이 방식을 택함(기존 `.board-paginate` 규칙 미변경).
  - **좁은 화면(≤1024px)도 PC와 동일한 배치로 통일 (2026-07-24, 화면 비교 후 재수정)**: 처음에는 겹침을 우려해 모바일에서 버튼을 아래 줄로 내렸고, 이후 flex로 자리를 나눠 같은 줄에 뒀으나 **페이지네이션이 왼쪽으로 밀려 PC와 모양이 달라지는** 문제가 있었음(모바일/PC 화면 비교로 확인). 최종적으로 **모바일도 PC와 동일한 absolute 오버레이**로 통일 — 페이지네이션은 전체 폭 기준 가운데 정렬을 유지하고 버튼만 오른쪽에 겹쳐 올림.
    - 겹침 방지: 모바일 `.board-paginate`에 **좌우 대칭 여백(90px)** + `flex-wrap: wrap` 적용. 대칭이라 가운데 정렬은 유지되고, 페이지 번호가 많아져도 버튼 영역을 침범하지 않고 아래 줄로 줄바꿈됨. 상하 패딩(20/24)과 전체 폭 상단 구분선은 원본 그대로.
  - **버튼 크기 조정**: 기본 `.btn`(padding 10/20, 14px)이 페이지네이션 줄에 비해 커 보이는 문제를 개선 — 앱의 게시판 버튼 관례(글쓰기 버튼과 동일 규격)에 맞춰 **PC 32px/12px, 모바일 28px/11px**로 축소(`#btn-board-manage`).
  - **검색 조건 select 가운데 정렬**: 닫힌 상태의 선택값이 왼쪽으로 붙어 보이던 것을 `text-align: center` + `text-align-last: center`로 가운데 정렬(PC·모바일 공통).
  - **구조 변경 영향 점검**: 기존 `#board-inline-pagination { flex-shrink: 0; }`은 부모 `#board-list-scroll-wrap`이 flex 컨테이너가 아니라 실제로는 무효였으나, 의도 보존을 위해 감싼 행에도 `flex-shrink:0`을 부여. 두 요소의 **id는 유지**되어 JS(`paginEl`, `_boardRenderAdminBar`)는 변경 없음.
  - **검증**: `php -l`·`node --check` 통과, CSS 삭제 줄 0(순수 추가), 중괄호 균형 유지, id 중복 없음(각 1개).
- **[기능/버그] 게시판 검색 조건 선택 + 삭제 규칙 정리 (2026-07-24, 펜닐님 요청)**
  - **검색 조건 선택 추가**: 검색창 왼쪽에 `#board-search-type` 셀렉트 추가 — **제목+내용(기본) / 제목 / 내용 / 댓글내용 / 작성자이름**. 조건을 바꾸면 입력된 검색어가 있을 때 즉시 재검색. 서버는 `search_type` 파라미터로 분기(`board_posts`).
    - `댓글내용`은 같은 게시판의 댓글에서 일치하는 `post_id`를 모아 해당 글만 남기며, **삭제된 댓글(`is_deleted`)과 다른 게시판 댓글은 제외**.
    - `작성자이름`은 글의 `author_name`으로 검색. 모두 `stripos` 부분일치(대소문자 무시).
  - **[버그 수정] 게시글 삭제 시 댓글 첨부파일이 남던 문제**: 첨부 경로가 게시글 `data/board_files/{board}/{post}/`, 댓글 `data/board_files/{board}/comments/{comment}/`로 **형제 관계**인데 게시글 폴더만 지우고 있었음(소스 주석엔 "댓글 첨부 포함"이라 적혀 있었으나 실제로는 미포함). 삭제될 댓글 id를 먼저 수집해 **댓글 첨부 폴더까지 정리**하도록 수정 — 단건 삭제(`board_post_delete`)와 일괄 삭제(`board_posts_delete`) **양쪽 모두**.
  - **[정책] 답글 달린 댓글 삭제 규칙**: 답글이 있는 댓글은 **작성자 본인은 삭제 불가**(“답글이 달린 댓글은 삭제할 수 없습니다.” 안내), **관리자만 소프트 삭제**(내용·첨부 제거, 항목은 남겨 답글 트리 유지). 답글이 없으면 기존대로 완전 삭제하며, 소프트 삭제된 부모의 마지막 답글이 지워지면 부모까지 정리하는 기존 로직 유지(정리 시 부모 첨부도 함께 제거).
    - 참고: XE/라이믹스 계열도 코어는 부모 댓글 삭제 시 하위 답글이 함께 사라지는 문제가 알려져 있고, 커뮤니티 권장 방식이 "하위 댓글이 있으면 삭제 대신 '삭제되었습니다'로 바꾸고 그 상태에서는 수정·삭제를 막는" 것 — 이번 정책이 그와 같은 방향.
  - **[개선] 권한 없을 때 조용한 성공 응답 제거**: 게시글·댓글 삭제가 권한이 없어도 `success: true`를 반환해 삭제된 것처럼 보이던 문제를 고쳐 **"권한이 없습니다."** 에러를 반환. 대상이 없으면 "게시글/댓글을 찾을 수 없습니다." 반환. (권한 규칙 자체는 기존과 동일 — 작성자 본인 또는 관리자)
  - **[중요] 클라이언트 실패 처리 누락 보완**: `deleteBoardPost`·`deleteBoardComment`는 `if (res.success)`만 있고 **else가 없어서**, 서버가 위와 같이 에러를 돌려주도록 바꾸자 **아무 메시지 없이 조용히 아무 일도 일어나지 않는** 상태가 됐음(특히 "답글이 달린 댓글은 삭제할 수 없습니다." 안내가 화면에 전혀 뜨지 않음). 같은 파일의 `deleteCommentAttachment`가 이미 쓰던 패턴(`else { toast(res.error) }`)을 그대로 적용하고, 요청 취소(`res === null`) 가드도 함께 추가.
  - **검증**: `php -l`·`node --check` 통과, 언어파일 JSON 정상, CSS 중괄호 균형 유지. 검색 5개 조건과 댓글 삭제 6개 시나리오(작성자/관리자/타인 × 답글 유무)를 로직 시뮬레이션으로 확인.
  - ⚠️ **실기기 확인 필요**: 조건 선택 후 검색 결과, 게시글 삭제 후 `data/board_files/{board}/comments/` 잔여 폴더 유무, 답글 달린 댓글 삭제 시 작성자에게 안내 문구 노출 여부.#### v5.8.3a (2026-07-19)

**[기능] HWPX 직접 저장 개방 (2026-07-26, 펜닐님 요청)**

- **배경**: HWPX 문서를 열면 "저장"·"다른 이름으로 저장" 메뉴는 활성으로 보이는데 실제로는 저장되지 않았음. rhwp 엔진 제약이 아니라 **FileStation이 5중으로 막아둔 것**이 원인(0.7.3 시절 `exportHwpx()`가 문서를 손상시켜 넣은 차단). rhwp studio는 0.7.18 무렵부터 HWPX 저장을 정식 지원하고, 0.8.0에서 HWPX 왕복 속성 보존이 대폭 보강되어 차단을 해제함.
  - 메뉴가 활성으로 보였던 이유: HWPX일 때는 MutationObserver를 돌리지 않고 0/2/5초 타이머로만 비활성화했는데, 메뉴를 여는 시점에 studio가 다시 그리면서 활성 상태로 되돌아갔음. 클릭하면 `notifyHwpxNotSupported()`가 하단 상태바에 4초간 안내만 띄우고 종료 → 사용자에겐 "아무 반응 없음"으로 보임.
- **변경** (`rhwp_editor.php` 단일 파일, 9개 지점):
  - **PHP `action=save`**: `.hwpx` 거부 → **`.hwp` / `.hwpx` 화이트리스트**로 변경.
  - **PHP `action=save-as`**(덮어쓰기 재시도 포함): `.hwp` 강제 → **원본과 같은 확장자만 허용**(`pathinfo` 비교). 내용은 원본 형식으로 저장되므로 확장자만 바꾸면 깨진 파일이 되기 때문 — HWP 문서를 `.hwpx`로 저장하는 사고를 서버에서 차단.
  - **JS Blob 훅**: `application/x-hwp`만 캡처하던 것을 **`application/hwp+zip`(HWPX)도 캡처**. studio 정의(`hwpx: { mimeType: 'application/hwp+zip' }`)와 실제 Blob 생성부를 직접 확인해 MIME 일치 검증. 이 훅이 없으면 앞단만 열어도 "Blob 미생성"으로 저장 실패함.
  - **JS Ctrl+S 핸들러 / 메뉴 클릭 핸들러 2곳**: `IS_HWPX_DOC` 차단 분기 제거.
  - **JS 파일명 검증**: `.hwp` 강제 → 원본 형식에 맞춰 `.hwp` 또는 `.hwpx` 요구(클라이언트 1차, 서버 2차 이중 검증).
  - **JS 메뉴 상태 동기화**: HWPX 전용 비활성화 분기 제거 → HWP와 동일 처리. HWPX에서 observer를 건너뛰던 예외도 제거해 실시간 동기화 통일.
  - 이제 호출부가 없어진 `notifyHwpxNotSupported()`는 **함수 정의만 남겨둠**(재차단이 필요할 때를 위한 안전망, 동작 영향 없음). 사실과 어긋나게 된 주석 5곳 현행화.
- **저장 형식 결정은 studio가 담당**: `getSourceFormat()` 결과에 따라 HWP 원본은 `exportHwp()`, HWPX 원본은 `exportHwpx()`가 호출되고 각각 `application/x-hwp` / `application/hwp+zip` Blob으로 나옴. FileStation은 그 바이트를 그대로 서버에 올릴 뿐이라 **형식 변환은 일어나지 않음**.
- **검증**: `php -l` 통과, 내장 JS를 추출해 PHP 태그를 리터럴로 치환 후 `node --check` 통과(스크립트 블록 4개), 변경 파일은 `rhwp_editor.php` 하나뿐(백업 대비 diff 확인).
- ⚠️ **실기기 확인 필수**: ① HWPX 문서에서 Ctrl+S 저장 후 **한글에서 정상적으로 열리는지**(가장 중요 — 저장 품질은 코드로 검증 불가), ② Ctrl+Shift+S 다른 이름으로 저장(`.hwpx` 이름 유지되는지), ③ HWP 문서 저장이 기존과 동일한지(회귀 없는지), ④ 저장 후 웹하드 목록 자동 갱신. **중요 문서로 먼저 시험하지 마시고 사본으로 확인 권장.**
- **[후속] 저장 후 창을 닫을 때 뜨던 "변경사항이 저장되지 않을 수 있습니다" 경고 제거 (2026-07-26, 펜닐님 제보)**
  - **증상**: 서버 저장이 정상 완료되고 문서를 다시 열면 내용도 제대로 저장돼 있는데, 편집기 창을 닫으면 브라우저의 이탈 경고가 계속 떴음.
  - **원인**: studio는 문서가 dirty일 때 `beforeunload`로 경고를 띄운다(`beforeUnloadHandler = e => { if (this.dirty) ... }`). 그런데 FileStation의 서버 저장은 로컬 저장을 막으려 `showSaveFilePicker`를 `AbortError`로 던지기 때문에 **studio 입장에서는 "사용자가 저장을 취소함"으로 보여 내부 `markClean()`에 도달하지 못한다.** 실제로는 서버에 저장됐는데 dirty 플래그만 남아 경고가 뜬 것.
  - **수정**: rhwp **0.8.0에서 새로 추가된 호스트용 API** `window.rhwpStudio.notifySaved()`를 서버 저장 성공 직후 호출하도록 헬퍼 `markStudioSaved()` 추가(`rhwp_editor.php`). 이 API는 `markClean('host-save')`와 자동 임시저장 초안 폐기를 함께 수행한다. 0.7.19에는 없던 API이므로(`window.rhwpStudio` 0건 → 1건) **존재 확인 후 호출**하고, 실패해도 저장 자체에는 영향이 없도록 `try/catch`로 감쌌다.
  - **적용 범위**: 저장(Ctrl+S), 다른 이름으로 저장, 다른 이름으로 저장-덮어쓰기 **3곳 모두**. 저장은 원본 파일명을 인자로 넘기고(동일 이름이라 무변화), save-as 계열은 **인자를 넘기지 않는다** — 인자를 주면 studio의 표시 파일명이 사본 이름으로 바뀌는데 FileStation의 저장 대상은 여전히 원본이라 혼동을 부르기 때문.
  - ⚠️ **참고**: save-as 후에도 dirty가 해제되므로, 사본에만 저장하고 원본에는 반영하지 않은 채 창을 닫아도 경고가 뜨지 않는다(studio 자체 save-as 동작과 동일). 원본 보호를 위해 save-as에서는 경고를 유지하고 싶다면 해당 호출 2곳만 제거하면 됨.
  - **검증**: `php -l` 통과, 내장 JS 추출 후 `node --check` 통과, 헬퍼 정의와 호출 3곳이 동일 스크립트 블록임을 확인, 백업 대비 **삭제된 줄 0(순수 추가)**.

**[기능] 동영상 플레이어 좌우 탐색 버튼 추가 (2026-07-26, 펜닐님 요청)**

- **배경**: 키보드 좌우 방향키로는 ±5초 탐색이 되지만, 마우스·터치로는 화면 가운데의 재생/일시정지 버튼밖에 없었음. 재생 버튼 좌우에 탐색 버튼을 추가.
- **적용 범위**: 일반(미리보기 모달, `assets/js/app.js`)과 공유 페이지(`share.php`) **양쪽**.
- **동작**: 버튼 클릭 시 ±5초 이동 — 키보드 방향키와 **동일한 동작·동일한 ±5 피드백 오버레이**(`.video-seek-left/right`) 재사용.
  - ⚠️ **`duration` 처리 (2026-07-26 재점검에서 수정)**: 처음에는 `if (!isFinite(dur) || dur <= 0) return;`으로 막았으나, 트랜스코딩 재생은 MSE 기반이라 `video.duration`이 **Infinity/NaN**이 될 수 있어(그래서 재생시간을 `.transcode-duration`으로 따로 표시함) **키보드 좌우는 되는데 버튼만 아무 반응 없는** 상태가 됨. 키보드와 같이 **길이가 유한할 때만 상한을 적용**하도록 완화. 두 로직을 같은 입력으로 시뮬레이션해 일반 영상·끝부근·시작·트랜스코딩(Infinity) 전 구간에서 결과가 일치함을 확인.
  - 남는 차이는 메타데이터 미로드(`NaN`)·`duration 0` 뿐 — 키보드는 0으로 점프하고 버튼은 목표 지점을 그대로 설정한다(브라우저가 재생 시작 위치로 보관). 실사용 영향은 없으며 버튼 쪽이 의도에 더 가까움.
- **인식 영역 한정**: 탐색은 **버튼 위에서만** 인식. 핸들러에서 `stopPropagation()`으로 기존 재생/일시정지 경로에 전달되지 않게 했고, **버튼 밖을 클릭하면 기존대로 재생/일시정지가 동작**함(해당 로직은 손대지 않음).
- **표시 규칙 상속**: 버튼에 `video-play-overlay` 클래스를 함께 부여해 재생중·호버·전체화면 idle·모바일 숨김·버퍼 미준비 등 **재생 버튼의 표시 규칙을 그대로 따르게** 함. 위치·크기만 별도 CSS로 덮어씀(52px, 중심에서 좌우 **104px**).
- **아이콘·간격 (2026-07-26 화면 확인 후 조정)**: 처음에는 이중 삼각형(빨리감기 계열) 아이콘에 좌우 84px였으나, 재생 버튼에 너무 붙어 보이고 이동 초 수가 드러나지 않아 조정.
  - 아이콘을 **원형 화살표 + 숫자(5)** 형태(Material `replay_5`/`forward_5` 계열)로 교체. 숫자는 경로가 아닌 SVG `<text>`로 그려 렌더 실패 위험을 없앰. 새 SVG 4개 모두 XML 파싱으로 구조 검증.
  - 좌우 간격: 고정 px이 아니라 **영상 폭에 비례(20%)하되 96~150px로 제한**(`left: calc(50% ± clamp(96px, 20%, 150px))`). 실제 플레이어들이 쓰는 비율 배치 방식으로, 영상 크기가 바뀌어도 자동으로 맞춰진다 — 폭 750px 이상이면 최대 150px(버튼 사이 여백 88px)까지 시원하게 벌어지고, 좁아지면 96px까지 좁혀져 재생 버튼과 겹치거나 영상 밖으로 밀리지 않는다. (고정 150px이면 폭 320px 영상에서 3버튼 352px로 넘침 — 폭별 계산으로 확인)
  - 마크업 순서상 재생 버튼이 항상 먼저라, 기존 `wrap.querySelector('.video-play-overlay')`(에러 오버레이·재바인딩용)는 계속 **재생 버튼**을 가리킴 — 순서 의존이므로 이후 마크업 수정 시 주의.
- ⚠️ **클릭 가로채기 방지 (중요)**: 재생 중에는 재생 버튼이 `opacity:0`이면서도 `pointer-events:auto`(가운데를 눌러 일시정지하는 기존 동작)라, 탐색 버튼이 그 규칙을 그대로 따르면 **보이지 않는 버튼이 클릭을 가로채** 원래 그 자리를 눌렀을 때 되던 재생/일시정지가 막힌다. 그래서 탐색 버튼만 `.playing` 상태에서 `pointer-events:none`, `.playing:hover`에서 `auto`로 두어 **실제로 보일 때만** 클릭을 받도록 함(일반·공유 동일).
- **모바일**: 실기기 모바일은 재생 오버레이를 숨기고 브라우저 기본 컨트롤을 쓰므로(`isRealMobileDevice` 분기 + `@media (max-width:1024px)` 숨김) **탐색 버튼도 함께 숨김** — 재생 버튼과 노출 조건을 일치시킴.
- **중복 바인딩 방지**: `_bindVideoSeekButtons()`는 clone 교체 후 리스너를 다시 거는 방식이라, 최초 렌더와 화질/트랙 변경 후 재바인딩에서 여러 번 호출돼도 중복 등록되지 않음.
- **언어 키 신규**: `seek_back_5`, `seek_fwd_5` (ko/en). 기존 키 변경 0건.
- **검증**: `php -l`·`node --check`·JSON 파싱 통과, CSS 중괄호 균형, 변경 4파일 모두 **삭제된 줄 0(순수 추가)**.
- ⚠️ **실기기 확인 필요**: 버튼이 재생 버튼 좌우에 자연스럽게 보이는지, 클릭 시 ±5초 이동과 피드백 표시, **버튼 밖 클릭의 재생/일시정지가 그대로인지(회귀 확인)**, 재생 중 버튼이 안 보일 때 그 자리를 클릭하면 기존대로 재생/일시정지되는지, 전체화면·좁은 화면에서 겹치지 않는지.

**[업그레이드] rhwp 0.8.0 → 0.8.2 (2026-07-27, 가이드 v6.5 준수 → 이후 v6.6으로 개정)**

- **rhwp 0.8.2** (npm latest, shasum `af134d04e67e8822923435d31a285991bbce23ab` 진본 확인) 적용. 0.8.1·0.8.2를 한 번에 건너뛰어 올림.
- **업스트림 성격 — 공개 API 변경 없음**
  - **0.8.1 (PATCH)**: 렌더 정정(바탕쪽 머리말 개체가 용지 가장자리로 튀어나가던 문제, HWP3 글맵시 내장 OLE 추출, HWP3 문단 테두리 '선 없음' 오렌더), CLI 계약 정합·신규 기능(`edit fill-fields`/`replace-text`/`set-cell` 등 — 전부 CLI 계층), studio는 스타일 생성·수정·삭제를 편집 히스토리에 기록(`Ctrl+Z` 가능)·외부 그림 dev fetch 가드. CHANGELOG 명시: **라이브러리 공개 API 변경 없음**.
  - **0.8.2 (핫픽스)**: 브라우저 확장의 인쇄가 "파일을 찾을 수 없음"으로 실패하던 문제 복구(`print.html`이 확장 빌드 산출물에서 빠져 있었음 — v0.8.0부터 영향). 빌드에 **필수 산출물 게이트** 추가(자산 복사 실패 시 경고만 남기고 성공하던 동작 제거). 렌더 정정 1건(TAC 인라인 표 x-원점 바깥여백).
  - → 두 릴리즈 모두 **FileStation 사용 경로(뷰어 SVG·에디터 저장)와 무관한 영역**이 중심. 확장 인쇄는 FileStation이 쓰지 않음.
- **ABI 호환성**: `exportHwp`/`exportHwpx`/`exportHml`/`renderPageSvg`/`renderPageToCanvas`/`renderPageHtml`/`version` — 0.8.0과 **심볼 집합 완전 동일**(사라진 심볼 0). 커스텀 저장·뷰어 렌더 무영향.
- **호스트 API 존속 확인**: 저장 후 이탈 경고 제거에 쓰는 `window.rhwpStudio.notifySaved()`가 0.8.2 studio 번들에도 존재(1건) → 해당 기능 유지.
- **코어 파일 대조로 API 무변경 교차 확인**: `rhwp.js`(wasm-bindgen 글루)가 0.8.0과 **바이트 단위 동일**(md5 `a771a8b7047c`, 347,437B) — 내보내는 API 표면이 바뀌지 않았다는 뜻으로 CHANGELOG의 "공개 API 변경 없음"과 일치. 엔진 본체 `rhwp_bg.wasm`만 갱신(7,175,465B → 7,189,247B). 배포본 두 파일 모두 npm 0.8.2 원본과 md5 일치 확인.
- **빌드 산출물**: `index-1A4EvFd5.js`(신규), **`index-CX93BaKm.css`는 0.8.0과 동일 해시**(CSS 무변경), `rhwp_bg-DPb8Hj0A.wasm`, `canvaskit-renderer-BRkR7HEv.js`. **`canvaskit-DB1zH3nD.wasm`은 0.7.16 이후 계속 동일 해시**(md5 대조 확인).
- **패치 재적용**: J1(`file:save` Ctrl+S 매핑) 1건 제거, J2(`file:print` Ctrl+P 매핑) 1건 제거 — `file:save` 메뉴 정의 유지. P1 0건 / P2(`../images/`) 1건 정규화. 패치 후 studio JS `node --check` 통과.
- **`vite.config.ts`**: PWA·커스텀 플러그인이 있어 **옵션 B**(`base: './'`만 안전 추가)로 처리 — PWA 생성 정상(`sw.js`, precache 57 entries).
- **변경 범위**: `rhwp_editor.php` **2줄**(studio JS 파일명, `@rhwp_version`), `rhwp_viewer.php` 1줄(`@rhwp_version`), `config.php` 1줄(APP_VERSION), `README.md`, `assets/rhwp/` 자산. **커스텀 로직·게시판·동영상 플레이어 코드 일절 미변경**(백업 대비 diff 확인).
- **STEP 10 검증 (v6.5)**: 1~10 커스텀 보존 전부 기준 충족, 개정 항목 11/11b/12(서버측 확장자 검증·save-as 원본확장자 비교·HWPX Blob 캡처)와 신설 18/18b(`markStudioSaved` 4건·studio API 노출 1건) 충족, 13~17(J1·J2 제거, CSS 정규화, `@rhwp_version 0.8.2`, CanvasKit 배치) ✅. PHP 6파일 `php -l` 통과.
- **검증 #5 관련 기록 정정**: 작업 중 `MutationObserver` 카운트가 0.8.0 때 관측한 3에서 2로 줄어 회귀를 의심했으나, **가이드의 기대값은 원래 `1 이상`**이라 현재 2는 정상 통과다(관측값을 기준값으로 착각한 오진). 감소 원인은 HWPX 저장 개방 작업에서 observer 관련 주석 한 줄이 정리된 것이며, 기능 코드(`new MutationObserver` 1 + `observer.observe` 2건)는 0.8.0 백업과 **완전히 동일**함을 diff로 확인했다. 가이드 수정 불필요.
- ⚠️ **실기기 확인 필요**: HWP 뷰어 렌더, 에디터 Ctrl+S 서버 저장, Ctrl+Shift+S 다른 이름으로 저장, HWPX 직접 저장(0.8.0에서 개방한 기능 유지 확인), 저장 후 이탈 경고 미표시, CanvasKit 렌더, 표/다단 문서 페이지 정합(0.8.1 렌더 정정 영향 확인).

**[업그레이드] rhwp 0.7.19 → 0.8.0 (2026-07-26, 가이드 v6.4 준수)**

- **rhwp 0.8.0** (npm latest, shasum `95d5be3a…` 진본 확인) 적용. 업스트림 성격: **MINOR 릴리즈 — v0.7.19 이후 265개 PR 통합**. 버전이 0.8.0으로 올라간 주된 이유는 브라우저 확장 버전을 라이브러리와 통일(0.2.8 → 0.8.0)한 것이며, **공개 API breaking change 없음**.
- **주요 변경**: 저장 왕복 보존 대공사(무효화 계약 확립, HWPX/HWP5 속성 왕복 수십 건), 에이전트용 CLI 조회·검증 도구군 신설, cargo-fuzz 파서 퍼징 인프라 + 악성/손상 입력 방어(WMF/EMF/CFB/DIB 패닉·과대할당), 편집 undo 충실도 연작, 렌더·폰트 정합(10k 오라클 서베이 r23, 쪽수 회귀 0), studio 반응성·한글 입력 지연 개선.
- **ABI 호환성 확인**: `export_hwp` / `renderPageSvg` / `version` + `exportHwp` / `renderPageToCanvas` 모두 0.8.0 `rhwp.d.ts`에 보존 → `rhwp_viewer.php`(SVG)·`rhwp_editor.php`(Blob 훅 + 무인자 `exportHwp`) 커스텀 저장 **무영향**.
- **패치 재적용**: J1(`file:save` Ctrl+S 매핑) 1건 제거, J2(`file:print` Ctrl+P 매핑) 1건 제거 — `file:save` 메뉴 정의는 유지(마우스 클릭 정상). P2(`url(../images/`) 1건 → `url(images/)` 정규화, P1은 0건(0.7.8 이후 동일 패턴). 패치 후 studio JS `node --check` 통과.
- **CanvasKit**: `canvaskit-DB1zH3nD.wasm`은 0.7.19와 **동일 해시**(skia 정적 자산 무변경 지속), renderer JS만 `Cn_7bIBe`로 갱신. studio 폴더에 함께 배치.
- 🔵 **HWPX 저장 관련 사실 확인 (0.8.0에서 바뀐 것 없음 — 작업 중 서술 정정)** — studio의 `file:save-as-hwpx`("HWPX 형식으로 저장", `canExecute: e=>e.hasDocument`)와 "HWPX 원본은 HWPX로 저장"하는 포맷 결정 로직은 **이미 0.7.19 빌드에 동일하게 존재**했다. 0.7.19 studio JS와 0.8.0 studio JS를 직접 대조한 결과 해당 항목 전부 동일(메뉴 1건/1건, 포맷 결정 로직 2건/2건, 구 가드 `sourceFormat!==` + backtick hwpx 0건/0건). 즉 **0.8.0에서 새로 열린 것이 아니라**, 업스트림이 0.7.15부터 HWPX 저장 계약(serializer fidelity)을 다듬어 오다 0.7.18 무렵 studio 차단을 해제한 것의 연장선이다(README 0.7.19 항목의 "스테일 패턴" 기록과 일치).
  - 잔존 문구 `"HWPX 문서는 현재 직접 저장할 수 없습니다."`는 저장 확인 대화상자의 `canSave`가 false일 때만 노출되는 툴팁인데, 호출부가 `canSave: !0`(항상 true)로 고정되어 있어 **실제로는 표시되지 않는 죽은 문구**(0.7.19·0.8.0 동일).
  - **FileStation 영향 없음**: HWPX 차단은 studio canExecute에 의존하지 않는다. ⑤ `rhwp_editor.php` 서버측 확장자 검증(`action=save`/`save-as`에서 `.hwp`만 허용), ②③④ `IS_HWPX_DOC` + `notifyHwpxNotSupported`(11건), 그리고 커스텀 저장이 **무인자 `exportHwp()`**라 HWPX와 무관하게 항상 HWP로 저장 — 모두 건재. FileStation을 통한 저장 경로는 여전히 HWP 전용.
  - ✅ **가이드 개정 완료 (v6.4 → v6.5, 2026-07-26)**: STEP 10 검증 #11/#12를 폐지하고 **서버측 확장자 검증 + HWPX Blob 캡처 확인**으로 대체, `notifySaved` 검증 #18 신설, HWPX 직접 저장 지원으로 커스텀 기능 표·트러블슈팅·체크리스트 갱신, 버전 이력 0.7.19/0.8.0 추가, 환경 주의(php 미설치·`/home/claude/work` 휘발) 명시.
- **FileStation 버전 유지** (v5.8.3b — 펜닐 룰, "버전 올려줘" 명시 없음 → rhwp 버전만 갱신 후 재패키징).
- **변경 범위 검증**: 백업 대비 `rhwp_editor.php` 3줄(studio JS/CSS 파일명 2 + `@rhwp_version` 1), `rhwp_viewer.php` 1줄(`@rhwp_version`), `README.md`, `assets/rhwp/` 자산만 변경. **게시판 작업이 든 `api.php`·`assets/js/app.js`·`assets/css/style.css`·`lang/*.json`은 무변경 확인.** PHP 문법(`php -l` 5개 파일)·JS(`node --check`) 전부 통과.
- ⚠️ **실기기 확인 필요**: HWP 뷰어 렌더, 에디터 Ctrl+S 서버 저장, Ctrl+Shift+S 다른 이름으로 저장, HWPX 파일이 FileStation에서 여전히 저장 차단되는지, CanvasKit 렌더, 다크테마와 커스텀 메뉴 공존.

**[업그레이드] rhwp 0.7.18 → 0.7.19 (2026-07-19, 가이드 v6.4 준수)**

- **rhwp 0.7.19** (npm 배포 2026-07-17) 적용. 업스트림 성격: v0.7.18 후속 **PATCH — 공개 API 하위 호환 유지**, breaking change 없음.
- **주요 업스트림 변경**: 저장 지오메트리 신호(intra-para vpos 리셋) 존중 계보의 렌더·편집 경로 정합, 표 페이지네이션 정밀도(rowspan 선언-잔여, 서식 문서 과소분할), 편집 vpos 재계산이 저장 리셋 보존(편집 스윕 574건 중 가짜 페이지 변동 60건 해소), BinData 지연 로딩(RSS 244MB→49MB), **HML(HWPML) 문서 열기·의미 보존 저장 신규**, CanvasKit readiness gate 강화, legacy `/web` 개발 앱 제거(스튜디오 무영향).
- **작업 절차** (가이드 STEP 0~12):
  - core: npm `@rhwp/core@0.7.19` tarball **shasum 진본 검증 통과**(`df3d303e965bb207a6ef66ce9f6beb13d94ccc60` 일치) → `assets/rhwp/rhwp.js`·`rhwp_bg.wasm` 교체.
  - studio: GitHub `v0.7.19` 태그 clone → `vite.config.ts`에 **옵션 B(base만 안전 추가)** 적용해 **VitePWA·serve-samples-dir 플러그인 보존** → `npm install`+`tsc`(에러 0)+`vite build` → 산출물 교체(`index-C2tVKY8p.js`, `index-DXdWbUsL.css`, `rhwp_bg-PSxFoLe6.wasm`, `canvaskit-DB1zH3nD.wasm`, `canvaskit-renderer-Dh3baHL6.js`, fonts/images/icons/favicon).
  - 패치 재적용: **J1**(file:save Ctrl+S 매핑 제거) 1건 ✅, **J2**(file:print Ctrl+P 매핑 제거) 1건 ✅, **P2**(CSS `../images/` → `images/`) ✅ — P1은 해당 없음(0.7.19 빌드는 상위참조 패턴).
  - `rhwp_editor.php`: studio 파일명 갱신(`index-BbUFqbC-.js`→`index-C2tVKY8p.js`, `index-BKc-ZB2H.css`→`index-DXdWbUsL.css`) + `@rhwp_version 0.7.19`. `rhwp_viewer.php`: `@rhwp_version 0.7.19`. **그 외 커스텀 로직 일절 미변경**(diff 3줄만).
- **검증 (STEP 10, 17항목)**: 1~10 커스텀 보존 전부 기준 충족(save/save-as 엔드포인트, Blob 캡처, MutationObserver, 캐시버스팅 2, `e.code==='KeyS'`, syncSuspended 6, notifyParentFileChanged 4, app.js `rhwp-file-changed`). 13/14/15/16/17 ✅(J1·J2 제거 확인, CSS 정규화, `Version 0.7.19`, CanvasKit 배치). studio JS `node --check` 통과, PHP 6파일 `php -l` 통과.
- ⚠️ **검증 #11 항목은 가이드 패턴 스테일 — 회귀 아님(조사 완료)**: `sourceFormat!==`hwpx`` 패턴이 0.7.19에서 매칭 0건이나, **0.7.18(직전 운영 버전)에서도 동일하게 0건**임을 백업본 대조로 확인. 양 버전 모두 `file:save`의 `canExecute:e=>e.hasDocument`로 동일하여 **업스트림 동작 차이 없음**. HWPX 저장 차단의 실질 방어선은 `rhwp_editor.php`의 **서버측 확장자 검증(.hwp만 허용)** 이며 건재. → 가이드 #11 패턴은 0.7.18 이전에 업스트림에서 사라진 낡은 패턴이므로 **다음 가이드 개정 시 갱신 권장**.
- **v5.8.3의 OnlyOffice PDF 연동 작업분 무손상**(배선 12건 유지, app.js `node --check` 통과).
- ⚠️ **실기기 확인 필요**: ① HWP 문서 뷰어 렌더링 ② 에디터 열기 → 편집 → **Ctrl+S 서버 저장** ③ 다른 이름으로 저장 ④ Ctrl+P 브라우저 인쇄 대화상자 ⑤ HWPX 열람(저장 차단 유지 확인) ⑥ 표/다단 문서 페이지 정합(0.7.19 렌더 변경 영향 확인).

#### v5.8.3 (2026-07-11)

**[기능] PDF를 OnlyOffice로 편집 — 서버 버전 게이트 + pdf.js 미리보기 유지 (2026-07-11, 펜닐님 요청)**

- **핵심**: PDF를 OnlyOffice PDF 에디터로 열어 **편집·저장·수정** 가능하게 연결. 단, OnlyOffice Document Server 버전이 낮으면(8.1 미만) 연결을 차단해 **PDF 편집이 안 되게** 하고, 기존처럼 내장 **pdf.js 미리보기(보기 전용)** 로만 열리게 함. (근거: OnlyOffice는 8.0에서 `documentType: "pdf"` 추가, **8.1+에서 PDF 편집** 지원.)
- **동작**:
  - 버전 **8.1 이상** 확인 시 → PDF **더블클릭 = OnlyOffice 편집**(편집/저장/수정), 컨텍스트 메뉴엔 다른 오피스 파일처럼 **"미리보기"(pdf.js)** + **"OnlyOffice" 편집** 항목 모두 노출.
  - 버전 **미확인/8.1 미만** → PDF 더블클릭·미리보기 모두 **pdf.js 뷰어**(현행 유지). OnlyOffice 라우팅 안 함.
- **시스템 설정 — 버전 확인 추가**: OnlyOffice 설정에 **"🔍 버전 확인"** 버튼 신설. Document Server **Command Service**(`/coauthoring/CommandService.ashx`, `{"c":"version"}`, JWT 시크릿 있으면 서명)로 실제 설치 버전을 조회해 표시(`v9.3.1 — PDF 편집 지원` / `v8.0 — PDF 편집 불가` 형태). 조회한 버전·PDF가능여부를 settings에 저장하고, **재로그인 없이 현재 세션에 즉시 반영**.
  - 설정 저장 시 **서버 URL이 동일하면 버전 정보 보존**, URL이 바뀌면 초기화(재확인 유도) → 잘못된 버전으로 PDF 편집이 열리는 사고 방지(안전 기본값: 미확인이면 PDF 편집 OFF).
- **시스템 설정 — 업그레이드 방법 추가**: 8.1 미만일 때 문서/설정 유실 없이 올리는 **Docker 업그레이드 절차**(기존 `-v` 마운트·`JWT_SECRET`·포트 그대로 재사용, 롤백용 old 컨테이너 보존, 버전 확인 후 정리) 안내 블록을 설정 화면에 추가. 특정 버전 태그 고정(예: `onlyoffice/documentserver:9.3.1`) 안내 포함.
- **변경 파일/지점**:
  - `onlyoffice.php`: `documentTypes`에 `'pdf' => ['pdf','djvu','oxps','xps']` 추가 → PDF가 `documentType: pdf`로 열림(편집기). 기존 저장 콜백(`callbackUrl`+`forcesave`) 그대로 사용.
  - `api.php`: 신규 액션 **`onlyoffice_version`**(관리자 전용, Command Service 조회·저장). `onlyoffice_config`(공개)엔 `pdf_enabled`(bool)만 반환하고 조건부로 `pdf` 확장자 노출 — 버전 문자열은 정보노출 축소를 위해 공개 엔드포인트에 넣지 않고 로그인 필요한 `settings`/`onlyoffice_version`으로만 제공. 설정 저장 시 version/pdf_editable 보존 로직.
  - `assets/js/app.js`: `onlyofficePdfEnabled` 플래그 + `ooEditExts()` 헬퍼(확장자 목록 단일화, PDF는 게이트 통과 시에만 포함) → 라우팅 6곳(PC/모바일 더블클릭·엔터·컨텍스트 메뉴 2곳·모바일 열기)을 헬퍼로 통일. `checkOnlyOfficeVersion()`/`_renderOnlyOfficeVersion()` + 버튼 바인딩. 설정/설정로드 2곳에 플래그 반영.
  - `index.php`: 설정 UI에 "버전 확인" 버튼·결과 표시 + 업그레이드 방법 안내.
- **미리보기 무손상**: pdf.js 미리보기 경로는 **일절 미변경**. 컨텍스트 메뉴 "미리보기"는 버전과 무관하게 항상 pdf.js.
- **검증**: PHP 4파일 `php -l` 통과(config/onlyoffice/api/index). `assets/js/app.js` `node --check` 통과 + 원본과 괄호 균형 동일(신규 불균형 0). OnlyOffice API 근거는 공식 문서 확인(`documentType` pdf, Command Service version).
- ⚠️ **실기기 확인 필요**: ① "버전 확인" 클릭 시 실제 버전 표시(펜닐님 서버 9.3.1 → "PDF 편집 지원") ② PDF 더블클릭 시 OnlyOffice 편집기 로드·저장 반영 ③ 8.1 미만 가정 시 pdf.js로만 열리는지 ④ 컨텍스트 "미리보기"=pdf.js / "OnlyOffice"=편집 분리 동작.

#### v5.8.2e (2026-06-23 ~ 07-11)

**[성능 개선] 스토리지 용량 재계산 병목 제거 + 진단 로그 강화 (2026-07-08, 펜닐님 요청)**

- **용량 재계산 무한 타임아웃 낭비 제거** (`Storage.php::backgroundRecalcIfNeeded`): 대용량/네트워크 스토리지(예: ftphdd, 10만+ 파일)가 완전동기화 조건(24h 이내 완전동기화 + 진행중 아님)을 못 만족해 매번 20초 전수스캔 → 타임아웃 → 결과 폐기(used_size 갱신조차 안 됨)하던 **순수 낭비 + 워커 20초 점유** 확인(scan_perf.log 6~7월 16회+ TIMEOUT, 파일 10만+). 전수스캔 직전 **인덱스 근사 SUM(0.1초)**을 채택하고 전수스캔을 건너뛰도록 안전장치 추가. **자기치유**: 인덱스 완전동기화되면 authoritative fast-path가 먼저 정확값으로 정정. 근사값이라도 "폐기되는 타임아웃"보다 항상 나음 → 회귀 없음.
  - **⚠️ 2026-07-08 1차 시도는 실패 → 07-09 정정**: 처음엔 트리거를 `$hadTimeoutCooldown`(타임아웃 쿨다운 마커)으로 했으나, `via_index` 빠른 경로 성공 시 그 마커를 지워버려(1308행) 조건이 유지되지 않음. 실서버 로그(7/9)에서 **`via_index_approx` 0건 + ftphdd 여전히 20초 타임아웃 반복** 확인 → 1차 수정이 실제로는 미작동이었음. **정정**: 트리거를 마커에 더해 **인덱스 파일 수 기준**(`$fileCountFb >= 30000`)으로 보강 → 대용량 스토리지는 마커 소실과 무관하게 항상 근사 SUM 사용. 소용량/정상 스토리지는 기존대로 전수스캔(무영향). 로그에 `files=N (fullscan skipped: large storage|prior timeout)` 기록해 실서버 검증 가능.
- **자동 재계산 smb 일관성 수정**: `backgroundRecalcIfNeeded`의 원격타입 목록에 smb 누락 → smb를 로컬 전수스캔으로 보내던 불일치를, 수동 재계산(`recalculateUsedSize`, 이미 smb 포함)과 동일하게 **인덱스 SUM 처리**로 정정. smb는 index_sync에서 어댑터(`rebuildStorageRemote`)로 인덱싱되므로 SUM 소스 존재 확인. 연결테스트용 remoteTypes 2곳(addStorage/updateStorage)은 smb 별도 처리 경로라 **의도적 제외 유지**(기계적 일괄수정 안 함).
- **진단 로그 상세화** (게이트형 — `data/`에 해당 파일이 있을 때만 기록, 없으면 오버헤드 0):
  - `files_perf.log`: 기존 "시간+경로" → **storage_id / type / remote여부 / 항목수** 추가. 어느 스토리지가 느린지 식별 가능.
  - `files_perf.log` (07-09 추가): **listFiles 세부 타이밍** — `listFiles sid=N iter=Xms vault=Yms count=Z path=...`. 폴더 목록 지연이 메인 목록(iter, 파일 stat)인지 vault(암호화 폴더 glob 탐지)인지 분리 측정. `FileManager::listFiles`에 게이트형 추가(목록 로직 무손상, 순수 추가).
    - **진단 결과 (07-10 최종 확정)**: 3단 계측으로 끝까지 특정됨.
      1. **클라이언트(client_perf)**: files 1~1.9초 지연 순간의 조건이 `conn=4g, downlink=10, ip=192.168.0.1(집WiFi), online=true` → **네트워크/IP변경/신호 문제 아님, 서버측 확정**. (모바일 IP는 실제로 5분내 14회 등 자주 바뀌나, 세션은 안 끊기고 이 지연과 무관.)
      2. **서버 전 단계(files_perf)**: 지연이 전부 `lfcall`(listFiles), 나머지 hls/perm/vchk/pfilt/shr = 0~1ms.
      3. **listFiles 내부(iter/vault)**: 지연이 `iter`(DirectoryIterator 항목별 stat). **count=3인데 iter=1886ms**(항목당 600ms) → 항목 수 무관, **sid=7(ftphdd) 네트워크 마운트의 콜드 stat I/O**. 콜드→웜 명확: 같은 루트 첫 접근 1018ms → 재접근 19~21ms.
    - **결론**: 서버가 네트워크 마운트(NAS)에서 파일 stat하는 I/O 지연. 서버 CPU·코드·클라 네트워크·IP 전부 무관. **코드 최적화 여지 없음**(stat 항목당 1회로 이미 최소). **단기 캐시도 무효**(느린 건 "첫 콜드 접근"이라 캐시할 게 없고, 재방문은 이미 19ms) — 07-09에 캐시를 레버로 봤으나 07-10 콜드/웜 데이터로 반증됨. 현실 레버: ① 마운트 keep-warm(유휴 방지 핑) ② 인덱스 기반 목록(신선도 트레이드오프+큰 변경) ③ 수용.
  - **[하이브리드 인덱스 목록] 콜드 접근 지연 해소 (07-10, 게이트형·기본 OFF)** — 위 레버 ②를 안전하게 단계 구현.
    - **1단계 (완료)**: `FileIndex::getFolderListing($storageId, $relativePath)` — 인덱스 DB에서 폴더 직속 자식 즉답 조회. SQL 로직 SQLite 실측 검증(직속만·손자 제외·스토리지 격리·LIKE 특수문자 이스케이프).
    - **2단계 (완료)**: `FileManager::tryListFromIndex()` — listFiles 권한체크 직후, 원격/로컬 분기 前 분기(모든 연결방식 커버). **타깃 자동 판정**: conf 없으면 자동 모드 — **원격타입(ftp/sftp/webdav/s3/smb, 어댑터 목록=느림) 또는 대용량 로컬(인덱스 파일 30000+, 네트워크 마운트 콜드 지연 대상)**에 자동 적용, **소용량 로컬 디스크는 실시간 유지**(빠르고 외부변경 즉시반영). `data/index_listing.conf` 있으면 수동 모드(적힌 ID만, 빈 파일=전부 off 탈출구). **완전동기화 1회 이상(last_sync 존재)이면** 인덱스 즉답(재동기화 중에도 사용 — 증분 방식이라 인덱스는 이전 완전 스냅샷 유지, 07-10 로그로 checkpoint 조건이 과함을 확인해 제거)(`from_index:true`), 아니면 실시간. **전체 try/catch로 어떤 오류든 실시간 폴백**(목록 절대 안 깨짐). 항목 형식은 실시간과 완전 일치 검증. **배포판에서 conf 없이도 콜드 지연 스토리지에 자동 적용됨**(수동 설정 불필요).
    - **3단계 (보류 — 실기기 검증 필요)**: 클라 `from_index` 응답 시 백그라운드 실시간 재검증→외부변경 자동 반영. 브라우저 렌더 경로라 컨테이너 테스트 불가 → 미구현.
    - **효과**: 콜드 지연 스토리지(원격타입·대용량 네트워크 마운트) 폴더 **첫 접근 즉답**, 배포판에서 **conf 없이 자동**. 웹UI 작업은 인덱스 동기 갱신이라 **즉시 반영**. **한계**: 외부(NAS/원격서버 직접) 변경은 다음 인덱스 동기화 때 반영(3단계 미구현이라 자동갱신 없음). vault 폴더 표시는 생략. 소용량 로컬 디스크는 실시간 유지(영향 없음).
    - **제어**: 자동 판정이 기본. 특정 스토리지 강제 지정/제외는 `data/index_listing.conf`에 스토리지 ID 기입(수동 모드). ⚠️ 실서버 검증 후 사용 권장(php -l 미실행, SQL·구조·로직 검증만).
    - **실측 검증 (07-10)**: 배포 후 sid=7(ftphdd) 로그 확인 — **인덱스 목록 작동 확정**(실시간 `listFiles iter=` 경로 안 탐), **콜드 스파이크(1000~2000ms) 소멸 → 일정한 ~130ms**(항목 수 무관), 첫 접근 폴더도 안 느림, 재동기화 중에도 작동. 콜드 지연 문제 해결 확인.
    - **추가 최적화 검토·미채택**: ~130ms는 getFolderListing의 직속자식 LIKE 쿼리가 서브트리를 훑기 때문(루트는 `NOT LIKE '%/%'`로 전체 스캔). `parent_path` 컬럼+인덱스로 ~10ms까지 가능하나 **스키마 변경+기존 인덱스 마이그레이션(backfill)이 배포 리스크**라 미채택. 130ms로 체감 충분하고 "멀쩡한 것 안 건드림" 원칙 유지.
  - `files_perf.log` (07-09 전 단계 계측): 간헐 딜레이가 **어느 구간이든 한 로그 줄에 특정**되도록 files 액션 전 단계 시간 기록 — `[hls=N perm=N lfcall=N vchk=N pfilt=N shr=N]`. hls(HLS세션정리 1%확률), perm(권한체크), lfcall(listFiles 전체=iterator前 is_dir/realpath 포함), vchk(현재폴더 vault 확인), pfilt(폴더권한필터), shr(공유목록 로드·매칭). 예: `music/AI_ERRDAY WAVE ()` 폴더가 총 5071ms인데 listFiles iter=37ms였던 케이스 → 5초가 listFiles 밖 어느 단계인지 이 계측으로 확정 가능. (간헐 증상이라 "로그 넣고 재현 대기" 반복을 없애려 전 단계를 한 번에 계측.)
  - **클라이언트 계측** (07-09, `assets/js/app.js` `api()` 래퍼): 느린 요청(1초+)의 **왕복시간(rtt) + 네트워크 상태**를 서버 `debug_log`로 전송(`event=client_perf`, `detail`={action, rtt, conn=4g/3g, downlink, connRtt, online}). **서버 무변경** — 기존 debug_log가 **IP·시각 자동 기록**(연속 로그 IP 다르면 = 모바일 IP변경 추적). 목적: 모바일 간헐 딜레이가 **서버측(files_perf와 대조)인지 네트워크측인지, 네트워크면 어떤 통신상태였는지** 확정. fire-and-forget(sendBeacon, this.api 미경유=재귀없음), 느린 요청만 전송. `data/debug_logs/` 폴더 있을 때만 기록(없으면 부담 0).
  - `scan_perf.log`: 전수스캔 시작/완료(**FULLSCAN_START / FULLSCAN_DONE**, storage_type·경과시간) + 근사경로 채택(**via_index_approx**) 이벤트 추가.
- **진단 결론**: 서버측 목록 처리는 대부분 1~40ms로 빠름. 유휴 후 스토리지 루트 5~7초 스파이크는 콜드 네트워크 마운트 재연결 + 백그라운드 재계산/인덱싱 워커 점유가 겹친 것. 일반 폴더의 모바일 지연은 네트워크 전송(서버 무관).
- ⚠️ **실서버 확인 필요**: 컨테이너 PHP 부재로 `php -l` 정식 린트 미실행(구조 검증만 — 괄호 균형·스코프·메서드 존재 확인). 반영 후 `scan_perf.log`에 `TIMEOUT` 대신 `via_index_approx`가 찍히면 정상 동작.

**[버그 수정] 음악 플레이어 셔플 — 반복/누락 개선 (2026-07-08, 펜닐님 요청)**

- **증상**: 셔플 상태에서 들을 때마다 거의 같은 곡만 나오고 일부 곡은 한 번도 안 나옴.
- **원인**(코드 추적으로 확정): 플레이어가 곡을 열 때마다 파괴/재생성되는데(app.js `destroy()`→`new FSAudioPlayer`), 생성자에서 셔플 ON이면 **매번 새 랜덤 순열을 만들고 저장하지 않음** + on/off 플래그만 저장. → 짧은 재생을 반복(곡 클릭·이동)하면 순서가 계속 리셋되어 앞쪽/특정 곡만 반복되고 순열 뒤쪽 곡은 누락. (셔플 **알고리즘**(Fisher-Yates)은 정상 — 순서를 **안 지키는** 상태관리가 문제.)
- **수정 (멜론/벅스식 표준 셔플)**: ① 한 바퀴 중복 없이 전곡 재생 → ② 전곡 소진 시 자동 재셔플 → ③ 플레이어 닫았다 열면 새 순서. 단 **재생 중 재생성**(곡 클릭·화면 이동으로 destroy→new)은 순서·진행 유지. 구분 방법: 곡 열 때 이전 인스턴스가 살아있으면(`reuseShuffleOrder=true`) 순서 유지, `null`이면(닫힘/첫 열기) 새 순서. 셔플 순서+진행(들은 곡)은 `fap-shuffle-order` localStorage에 재생목록 식별자(개수+첫/끝 트랙 경로)와 함께 저장. **손상 데이터 방어**: 저장값이 완전순열(0..n-1 정확히 한 번씩)이 아니면 폐기하고 재생성.
- **적용 범위**: app.js(본체) + fs-audio-player.js(공유) **양쪽 동일**(룰4). 헬퍼 5개(`_playlistSignature`/`_isValidShuffleOrder`/`_saveShuffleOrder`/`_ensureShuffleOrder`/`_markShufflePlayed`). 재셔플은 `_loadTrack`(모든 곡 로드 중앙 지점)에서 "들은 곡 수 = 전곡"일 때 트리거. **재생 순회(next/prev/onEnded)는 무손상** — 걷기 로직 안 건드리고 `_loadTrack`에 추적 1줄만 추가(최소·저위험). 비셔플 재생은 가드로 무영향. share.php는 페이지당 1회 생성이라 기본값(새 순서)으로 자동 정상 → 미수정.
- **검증**: 양쪽 `node --check` 통과. 시뮬레이션 4종 — ①한 바퀴 전곡 ✅ ②닫았다열기 새순서 ✅ ③2바퀴 각각 전곡+순서 다름 ✅ ④재생 중 재생성 순서·진행 유지 ✅.
- **한계**(버그 아님): 저장 슬롯 1개라 다른 폴더 전환 시 각 폴더용 새 순서 생성(한 폴더 내에선 정상 동작). `_loadTrack`마다 localStorage 저장(사용자 속도라 부담 미미).

**[rhwp 0.7.17 → 0.7.18 업그레이드] (2026-07-11, 펜닐님 요청)** ⚠️ 실기기 확인 대기
- npm tarball shasum: `290da8dbdf552a3fa705165cb44d254b8acacc41` (진본 확인). npm latest = 0.7.18.
- 새 studio 자산: **index-BbUFqbC-.js**, **index-BKc-ZB2H.css**, **rhwp_bg-pm0fNsz7.wasm**, **canvaskit-renderer-Bks2wD1j.js** (canvaskit-DB1zH3nD.wasm은 0.7.17과 동일 해시 — skia 정적 자산 무변경). 코어 rhwp_bg.wasm = 6,640,844 bytes.
- 패치 재적용: J1(file:save 매핑 제거), J2(file:print 매핑 제거), P1/P2(CSS 경로). J1/J2 각 1건 제거·메뉴 정의 유지·studio JS 구문 검증 통과(node --check). P1은 0.7.18 CSS가 `../images/`만 써서 미매칭(정상), P2 1건 처리.
- 0.7.18 변경(CHANGELOG): 렌더링 정합 대규모 보정(부동/전면 개체 페이지네이션, RowBreak 표, 미주 흐름), 초대형 표 성능(52,694셀 타임아웃 해소), 편집기 캐럿/undo/OLE 정합(#2021/#2164 등), 관용 파싱·HWPX 보존 확대, WMF 재작성, 내부 리팩토링 21라운드(행동 회귀 0). 공개 API 하위호환 PATCH.
- FileStation 무영향 확인: 커스텀 저장(Blob 훅 + 무인자 `exportHwp`) ABI 보존, HWPX 저장 차단(#11 `sourceFormat!==\`hwpx\``) 원본 유지. config APP_VERSION은 5.8.2e 유지("버전 올려줘" 없음).
- ⚠️ studio는 소스 빌드·JS 구문 검증했으나 렌더/편집 실동작은 컨테이너 테스트 불가 → 실기기 확인 필요. 문제 시 0.7.17 studio로 롤백.

**[rhwp 0.7.16 → 0.7.17 업그레이드] (2026-06-23, 펜닐님 요청)** ⚠️ 실기기 확인 대기

- npm tarball shasum 검증: `ddb292e5cec94f9d84999eec02af5930ab10c695` (진본 확인). npm latest = 0.7.17.
- 빌드 결과: **index-_kYZKzIp.js**, **index-sQvB-4Hv.css**, **rhwp_bg-Bry_KM2v.wasm**, **canvaskit-renderer-BS-907Do.js** (canvaskit-DB1zH3nD.wasm은 0.7.16과 동일 해시 — skia 정적 자산 무변경).
- 패치 J1(file:save Ctrl+S 매핑 제거): 1개 매칭 → 제거 성공.
- 패치 J2(file:print Ctrl+P 매핑 제거 → 브라우저 인쇄 fallback): 1개 매칭 → 제거 성공.
- 패치 P1(절대경로): 0개 매칭(정상, 0.7.6+ 절대경로 미사용). 패치 P2(상위참조): 2개 매칭 → 제거 성공 (`../images/` → `images/`, light + dark 아이콘).
- 검증 17/17 통과 (커스텀 10개 보존 + 엔진 패치 J1/J2 + CSS P1/P2 + CanvasKit 자산 배치).
- 🆕 **새 발견: rhwp가 0.7.17에서 HWPX 안내 툴팁 문구 자체 변경.** 패치 A(HWPX 저장 차단 `sourceFormat!==\`hwpx\``)는 정상 유지되나, 안내 문구가 구"HWPX 직접 저장은 현재 베타…" → 신"HWPX 형식은 현재 베타 단계라 직접 저장이 비활성화되어 있습니다." + "HWPX 문서는 현재 직접 저장할 수 없습니다."로 바뀜. 또한 studio에 "HWPX 변환 저장 모드(HWP로 내보냄)" 토스트 + "HWPX 비표준 감지" 경고 다이얼로그가 존재하나, **rhwp 공식 0.7.17 CHANGELOG엔 신규 항목으로 없음** — 0.7.17 이전부터 있던 studio 기능으로 추정(0.7.17에선 안내 문구만 변경). 단, FileStation 에디터가 HWPX 저장을 5중 방어로 일괄 차단하므로 이 변환 모드 경로엔 실제로 도달하지 않음. HWPX 차단·안내 기능 자체는 유지(오히려 강화). → 가이드 검증 #12의 매칭 문자열은 0.7.17 기준 갱신 필요(rhwp 업스트림 문구 변경, FileStation 패치 문제 아님).
- vite.config.ts: PWA + serve-samples-dir 커스텀 플러그인 보존 위해 옵션 B(`base: './'`만 Python 안전 추가) 적용.
- CanvasKit 자산: renderer JS 해시 변경(0.7.16 CLU8e50R → 0.7.17 BS-907Do) — replay 계약 가드 확장(#1447/#1469) 반영. wasm은 동일. `import.meta.url` 자동 경로라 base href·PHP 참조 불필요. zip 약 36MB 유지.
- **0.7.17 변경 사항** (CHANGELOG 사전 점검 — 0.7.16 후속 patch 사이클):
  - **API**: WASM 고인자(7+) 함수 26개에 options object 변형 `*Ex(options_json[, image_data])` 추가 (#1413). **기존 positional API 유지(하위 호환)** — FileStation 커스텀 저장 로직은 Blob 훅 + exportHwp(무인자)라 영향 없음. ABI 보존 확인(`export_hwp(&mut self)`, `render_page_svg_*`, `version()`).
  - **렌더링(차트)**: OOXML 차트 7종(3D막대4·3D원형1·ofPie2)을 2D 근사 렌더로 전환, "차트(미지원)" placeholder 제거 (#1453). 막대 차트 누적/백분율(c:grouping) 정합. → 기능 추가, 회귀 아님.
  - **HWPX 저장 계약**: legacy 도형 shapeComment 직렬화 누락 정정 (#1451), ir-diff tab_extended 예약필드 거짓차이 제외 (#1473). → 우리가 차단하는 영역이라 무영향.
  - **렌더링**: Text IR v2 폰트 fallback 권위 gap 유지 + CanvasKit replay 가드 확장 (#1429/#1447/#1469). 표 셀 TAC 그림·텍스트 세로정렬 보정 (#1352), 글자처럼 해제 그림 재흐름 (#1459).
  - **rhwp-studio**: 미저장 문서 자동 백업 + 복구 UI (#1448), 로컬 글꼴 감지 opt-in (#1328), 쪽 테두리 미리보기 토글 복구 (#1426), 그림 삽입·인라인 커서 정합 (#1452), **표 줄/칸 입력·지우기 회귀 보정**(#1481), 표 셀 드래그 선택·셀 보호 (#1443/#493), 크기 고정 개체 조작 차단 (#1436), 플랫폼별 메뉴 단축키 표시 보정 (#1476).
  - **브라우저 확장(0.2.6)**: CSP·다크 아이콘·Chrome 다운로드 interceptor 부작용 정정 — 우리 웹뷰어와 무관.
  - **인프라**: Cargo.lock git 추적, 의존성 일괄 업데이트(skia-safe 0.99.0 등).
- → 웹 빌드 영향: 정정·기능추가 위주, **렌더링 회귀 없음**. studio는 오히려 표 줄/칸 회귀(#1481) **수정** 포함.
- → ABI 호환성: HwpDocument, renderPageSvg, version 모두 보존 (rhwp_viewer.php 호환).
- → 펜닐님 커스텀 저장 로직: `application/x-hwp` MIME — Blob 패치 정상 작동.
- **사전 회귀 점검**: SVG 렌더러의 `preserveAspectRatio="none"`(과거 PR #335)은 0.7.8~0.7.17 동일 잔존이나 펜닐님 환경 실사용 미재현 확정 항목 → 조치 불필요.
- ⚠️ **실기기 확인 필요**: ① HWP 뷰어 렌더, ② 에디터 Ctrl+S 서버 저장, ③ 다크테마 + 커스텀 메뉴 공존, ④ CanvasKit 렌더, ⑤ studio 신규 자동 백업/복구 UI(#1448)가 커스텀 저장과 충돌 없는지, ⑥ 플랫폼별 단축키 표시 변경(#1476)이 J1/J2 동작에 영향 없는지.

**[버전 정정] config.php @version 주석 동기화 (2026-06-23)**

- `@version` 주석이 5.8.2a로 정체되어 있고 `APP_VERSION`은 5.8.2d였던 불일치 발견. 이번 버전 올림(5.8.2e) 시 둘 다 5.8.2e로 맞춤. (config.php 주석에 "수정 시 상단 @version 주석도 함께 업데이트할 것" 명시되어 있던 룰 준수.)

#### v5.8.2d (2026-06-09 ~ 2026-06-10)

- **[버그수정] H264/MP4 변환 시 원본 휴지통 이동 → 휴지통 카운트 미갱신 (2026-06-19, 펜닐님 보고)**: 일반 삭제/복원/비우기/일괄삭제는 동작 후 updateTrashIcon()으로 카운트 즉시 갱신되나, convertToH264 변환 완료 후처리(finally)에만 호출이 빠져 있어 원본이 휴지통으로 가도 30초 폴링 전까지 카운트가 안 맞았음. 변환 finally에 deleteOriginal 시 updateTrashIcon() 추가. 단일/다중 변환 모두 동일 경로라 일괄 커버. 이로써 휴지통 변경 동작(삭제/복원/비우기/일괄삭제/변환) 전부 카운트 갱신 일관.

**[검색 인덱스 재구축 알림: X 닫기/메뉴 방문 후에도 새로고침마다 다시 뜨던 버그 수정] (2026-06-10, 펜닐님 보고)**

- **증상**: 인덱스 재구축 알림을 X로 닫아도 새로고침/폴더이동 시 다시 뜸. 일반 알림처럼 X 닫기나 해당 메뉴 방문 시 꺼지고 재구축 전까지 안 떠야 함.
- **원인 3가지** (assets/js/app.js _checkIndexRebuildNotify):
  1. **읽기/쓰기 키 불일치**: dismiss를 읽을 땐 settings.index_rebuild_notify_dismissed(서버 설정), X 닫기는 user_preferences.dismissed_notifications(범용)에 저장 → 서로 다른 키라 dismiss가 반영 안 됨.
  2. **TDZ 버그**: dismiss 비교에서 lastRebuild를 선언(const) 전에 사용 → ReferenceError가 try-catch에 묻혀 로직 일부 무력화.
  3. **메뉴 방문 시 dismiss 없음**: showSearchIndexModal이 알림을 끄지 않음.
- **해결**: dismiss를 범용 _dismissedNotifications['index-rebuild']로 통일(X 닫기가 이미 저장하는 곳). lastRebuild 선언을 비교 앞으로 옮겨 TDZ 제거. showSearchIndexModal 진입 시 dismiss 저장 + 알림 제거 추가. dismiss 시각 > 마지막 재구축 시각이면 숨김 → 재구축하면 자동으로 다시 알림. _dismissedNotifications 미로드 시 레이스 방지 await 추가, 없으면 빈 객체로 초기화.
- **회귀 경위 (중요)**: 이 알림은 2026-04월에 _dismissedNotifications['index-rebuild'] 범용 키 방식으로 정상 동작했음(펜닐님 "옛날엔 됐다" 기억이 정확). 이후 어느 시점에 settings.index_rebuild_notify_dismissed(서버 설정) 방식으로 재작성되면서 X 닫기 쪽(_dismissedNotifications)과 키가 어긋나 깨짐. 이번 수정은 4월의 원래 방식으로 복원한 것. 이 로직은 반드시 _dismissedNotifications 범용 키를 사용할 것 — settings 키로 바꾸면 X 닫기와 불일치하여 재발함.
- **동작 확정** (펜닐님 결정): X 닫기 또는 검색 인덱스 메뉴 방문 시 알림 꺼짐 → 새로고침/폴더이동/작업해도 안 뜸. 재구축하면 다시 뜸. (외부/내부 업로드 알림은 share_clear_upload_notify로 서버 pending 초기화, 게시판 댓글 알림은 board_notification_read로 서버 읽음 처리 — 둘 다 같은 '확인하면 안 뜸' 패턴으로 이미 정상 동작 확인됨.)


**[음악 플레이어 APlayer Fixed 스킨 개선: 비주얼라이저 추가 + ON/OFF 토글 + 재생목록 짤림 해결] (2026-06-10, 펜닐님 요청)** ✅ PC 실기기 확인 완료

PC 음악 플레이어(일반 #modal-preview + 공유 share)의 ap-fixed 스킨 개선. 본체(app.js/style.css) + 공유(fs-audio-player.js/fs-audio-player.css) 양쪽 일치 수정.

- **① ap-fixed 비주얼라이저 추가 (커버 밑 독립 행)**: 기존 ap-fixed는 비주얼라이저/VU를 `display:none`으로 전부 숨겼음(10th rev). → 커버(row1) **아래 독립 grid 행(row2)**으로 배치해 시킹바/컨트롤/볼륨이 그만큼 아래로 밀리게 함. (처음 오버레이(`align-self:end`로 커버에 겹침) 방식 시도 → 펜닐님 "재생바가 안 내려온다" 보고 → 실제 행으로 재수정.) grid를 7행으로 구성: `340px(커버) auto(비주얼row2) auto(가사) auto(시킹) auto(컨트롤) auto(볼륨) 1fr(맨아래 spacer)`. vuled 숨김 해제.
- **🔴 핵심 버그(컨트롤 고정) 해결**: 처음엔 마지막 행을 볼륨=1fr로 뒀는데, 이러면 비주얼라이저 ON/OFF 시 **남는 공간(볼륨 1fr)만 늘었다 줄고 컨트롤 위치는 고정**됐음(펜닐님 보고: "컨트롤 고정, 볼륨바만 내려감"). → 볼륨도 auto로 바꾸고 **맨 아래 빈 spacer 행(1fr)을 추가**. 이제 비주얼라이저 ON 시 가사~컨트롤~볼륨이 통째로 아래로 밀리고 OFF 시 위로 올라옴. grid-row는 `!important`로 강제(다른 규칙에 안 밀리게). iOS/모바일(768px)/no-inline-lyrics 변형 grid 전부 7행 구조로 일치(비주얼 행은 OFF·iOS 시 0).
- **② 비주얼라이저 ON/OFF 토글**: 스킨 메뉴에 '비주얼라이저 > 표시 ON/OFF' 항목 추가(`.fap-vis-toggle-item`, data-vis-toggle). `_setVisEnabled()`가 `.fap-vis-off` 클래스를 root에 토글 → canvas/VU 숨김 + grid row2를 0으로 접음 + draw 루프(`_visEnabled===false`) 스킵(CPU 절약). localStorage `fap_vis_enabled`. OFF 시작 후 ON 시 `_initVisualizer()` 지연 초기화. **토글 글자색·폰트 스킨별 일치**(soundcloud=#f50, terminal=#00ff41+모노, pixel=#9bbc0f+대문자, cassette=#e8b923), **폰트 10px**(skin-item 12px와 구분).
- **③ ap-fixed 재생목록 짤림**: `_applySkin`에서 ap-fixed 전환 시 `#modal-preview` 높이가 작으면 자동 확대(최대 85vh/760px, 폭 860px) + 화면 밖 위치 보정. (본체 모달만 — 공유는 모달 아님.)
- **④ 여백 미세 조정 (펜닐님 요청)**: VU 미터 `align-self:stretch`→`center`, ap-fixed VU 높이 160→**80px**, VU 아래여백 제거(`margin:2px 0 0`). 볼륨 위 padding 12→4px. `.fap-skin-ap-fixed .fap-controls`의 `padding:16px 16px !important` 주석처리.
- **모바일 복원 (펜닐님 요청)**: 모바일(@media 768px)은 ap-fixed 비주얼라이저 작업 전 구조로 되돌림 — PC만 비주얼라이저 행(7트랙), 모바일은 작업 전 6트랙(비주얼 숨김). 분기점은 1023px — iPad 세로(768)·폰은 모바일(비주얼 숨김), iPad 가로(1024)·PC는 비주얼라이저 표시(펜닐님 요청, iPad 가로 1024가 PC로 가도록 1024→1023 조정). 모바일 ap-fixed가 영향받던 문제 해결.
- **✅ 실기기 확인(펜닐님 PC)**: 비주얼라이저 커버 밑 표시, ON/OFF 시 컨트롤 같이 이동, VU 컴팩트, 재생목록 정상 — 전부 확인 완료.
- **모바일 비주얼라이저 메뉴 숨김 (펜닐님 결정 경우B)**: 모바일(≤1023px)은 비주얼라이저가 표시 안 되므로(iOS는 OS 차단, ap-fixed는 숨김), 스킨 메뉴의 비주얼라이저 표시 ON/OFF + 색상 항목을 .fap-vis-section wrapper로 묶어 모바일에서 display:none. 전 스킨 공통(iOS/안드로이드 무관, 화면 크기 기준). 스킨 선택 항목은 wrapper 밖이라 모바일서도 유지.
- **[패스]** 재생목록 검색창과 스킨 버튼 위치 겹침 → 위치 이동/검색창 축소 등 검토했으나 다른 스킨과 일관성 문제로 현 상태 유지(펜닐님 결정).
- **참고**: 공유 플레이어(fs-audio-player.js)는 artwork maintenance(iOS 썸네일 갱신)는 없지만 스킨/비주얼라이저 구조는 동일 — ap-fixed 개선 양쪽 적용됨.

**[음악 플레이어 iOS 썸네일: 강제 갱신을 재생시간 2분 경계 → 1초 주기로 개선] (2026-06-09~10)**

- **증상** (펜닐님 보고): iOS 제어센터/잠금화면 썸네일이 4분 이내 곡에서도 간헐적으로 사라진 채 복구 안 됨 (곡 넘기면 나옴). 노래 듣다가 다음 트랙 넘기려 잠금화면/제어센터를 볼 때 빈 썸네일을 인지. 제어센터·잠금화면은 같은 MediaSession metadata라 증상 동일.
- **원인**: v5.8.1j의 강제 갱신이 '재생 시간 기준 2분 경계(%2)' 방식이라 곡의 2분/4분 '지점'에서만 발동. 2분 직후 iOS가 시스템 레벨로 유실하면(JS에선 metadata가 멀쩡해 보여 손실 감지 불가) 다음 갱신은 4분 지점 → 4분 이내 곡은 끝까지 복구 기회 없음. 시뮬 재현: 3분30초 곡+2분10초 유실 → 구방식 복구 0회.
- **해결** (`assets/js/app.js`): 벽시계 기준 주기 갱신으로 변경(변수 `_lastForceRefreshMin`→`_lastForceRefreshAt`, 트랙 변경 시 Date.now() 리셋). 30초로 1차 개선 후, **1초 주기로 최종 결정**(펜닐님 2026-06-10) — 복구 메커니즘은 z_music에서 검증됐으므로 1초 갱신이면 빈 썸네일 노출이 최대 1초라 사용자가 사실상 인지 불가. 타이머 setInterval 1초(throttle 0.8초)+무조건 갱신 1초.
- **부하**: 갱신은 메모리 `_currentMetadata` 깊은복사 재설정뿐(네트워크 fetch 없음, artwork URL 동일해 iOS 캐시) → 1초 주기여도 CPU/배터리 영향 미미.
- **✅ 실기기 확인 완료 (2026-06-10, 펜닐님 iOS)**: 1초 재설정 시 제어센터/잠금화면 깜빡임·진행바 흔들림 **없음** 확인 — 같은 artwork URL이라 iOS가 다시 그리지 않는 것이 실기기로 검증됨. 1초 갱신 방식 확정.
- **참고**: 공유 페이지 플레이어(fs-audio-player.js)는 artwork maintenance 자체가 없음(이벤트 기반만) — 공유에서도 같은 증상 시 동일 패턴 이식 필요(미적용).

#### v5.8.2c (2026-06-08 ~ 2026-06-09)

**[v5.8.2c] 압축파일 다국어(한/일/중/특수문자) 파일명 인코딩 + 추출 안전성 전면 개선 (2026-06-08)**

다양한 서버 환경(Windows+Apache, 시놀로지 도커, 일반 리눅스)에서 7z/zip/rar 압축파일의 다국어 파일명이 목록·미리보기·추출 시 깨지던 문제를 환경 독립적으로 해결하고, "여기에 풀기" 시 기존 데이터 보존 등 추출 안전성을 강화. ("버전 올려줘" 지시로 5.8.2b→5.8.2c)

- **🔴 [18차 보강] 폴더모드 RAR 영문 작업폴더에서 src.rar이 삭제돼 UnRAR 실패하던 버그 수정 (FileManager.php):** 18차 영문 작업폴더 우회를 적용했는데도 시놀로지 로그에서 폴더풀기 RAR이 'Cannot open src.rar / No such file'로 실패. 원인 — 7z 추출 실패 후 UnRAR 폴백 전, 폴더모드는 추출 폴더를 deleteDirectory로 통째 비우고 재생성하는데(7z이 남긴 깨진 추출물 정리 목적), 18차에서 extractDir이 영문 작업폴더로 바뀌면서 그 안의 영문 임시본(src.rar)까지 같이 삭제 → UnRAR이 입력 파일을 못 찾아 실패. (여기풀기는 이 정리 로직을 안 타서 .fs_rar_src_가 살아남아 성공 → 같은 RAR인데 여기풀기만 되던 모순의 원인.) → 폴더 정리 시 영문 임시본(rarTempCopy)은 보존하고 7z이 남긴 깨진 추출물만 삭제하도록 수정. 검증: 폴더모드 한글경로 암호 RAR이 src.rar 보존 → UnRAR 추출 성공 → 결과 이동, src.rar 오염 없음, 일반 폴더모드 회귀 없음 확인.
- **🔴 [18차 수정] 폴더에 풀기 시 한글경로 RAR '암호 틀림' 오류 해결 (FileManager.php):** 배포 사용자(시놀로지) 확인 — '여기에 풀기'는 되는데 '폴더에 풀기'만 암호가 틀리다고 나오던 문제. 로그 분석 결과 실제로는 암호 오류가 아니라 **UnRAR 5.70이 cwd(현재 디렉토리) 경로에 한글이 있으면 그 안의 파일조차 'No such file or directory'로 못 여는** 것이 원인. '여기에 풀기'는 추출 stage가 영문(.fs_extract_tmp_)이라 UnRAR cwd가 영문이 되어 성공했고, '폴더에 풀기'는 추출 폴더명이 한글이라 UnRAR cwd가 한글 → 실패 → 추출물 0 → '암호 틀림'으로 표시됨. → 폴더 모드에서 RAR이고 추출 경로에 비ASCII가 있으면, basePath(보통 영문 사용자ID 경로) 바로 아래에 영문 작업폴더(.fs_rar_work_<hash>)를 만들어 거기서 추출(7z·UnRAR이 다루는 압축파일·출력·cwd 모두 ASCII)한 뒤, 결과를 실제 목적지로 이동(충돌 시 (2) 분리). 여기에 풀기는 기존 stage 방식이 이미 영문이라 우회 미적용(회귀 없음). 실패/성공 모두 작업폴더 정리. 검증: 폴더 모드 한글경로 암호 RAR을 영문 작업폴더 cwd로 추출 성공, 여기풀기 회귀 없음 확인.
- **[17차 보강] RAR 영문 임시본 오염 방지 이중 방어 (FileManager.php):** 검토 중 — 여기에 풀기 stage에서 RAR 영문 임시본(.fs_rar_src_) 삭제가 만약 실패하면 최종 이동 시 사용자 폴더로 옮겨질 수 있어, 최종 이동 루프에서 .fs_rar_src_로 시작하는 항목은 사용자 폴더로 옮기지 않고 제거하는 이중 방어 추가. 검증: 여기에 풀기 + 한글경로 암호 RAR에서 사용자 폴더에 임시파일 잔재 0 확인. (멀쩡한 로직 미수정.)
- **🔴 [17차 수정] 암호 RAR 비번창 미표시 + 한글경로 RAR 추출 실패 해결 (FileManager.php):** 시놀로지 로그 분석 — ① **암호 RAR인데 비번 입력창 안 뜸**: 추출(extract7zip)의 암호 감지가 7z `checkOutput`에만 의존하는데, 시놀로지 7z이 한글경로 RAR을 아예 못 열어 암호 감지도 실패 → `isEncrypted=NO` → 비번 요구를 안 함(미리보기는 RarNative로 암호 감지하지만 추출 경로는 안 씀). → RAR이면 추출 경로에서도 RarNative(`fs_rar_native_list`)로 헤더를 직접 파싱해 암호화 여부를 확정, 암호인데 비번 없으면 password_required 반환. ② **한글경로 RAR 추출 실패**: 시놀로지 p7zip 16.02·UnRAR 5.70이 비ASCII 경로 RAR을 'Can not open the file as archive'/'Program aborted'로 못 열던 문제. → RAR이고 경로에 비ASCII가 있으면 압축파일을 추출 폴더 안에 영문 임시명(.fs_rar_src_<hash>.rar)으로 복사한 뒤 그 경로로 7z/UnRAR 추출(경로 문제 회피), 내부 파일명 깨짐은 기존 rename 보정이 처리, 추출 후 임시본 삭제·집계 제외. 검증: RarNative 암호 RAR 감지, 영문 임시본으로 한글경로 RAR 열림 확인.
- **🔴 [16차 보강] 여기에 풀기 stage를 사용자 폴더와 같은 파일시스템에 생성 (cross-device rename 방지) (FileManager.php):** 15·16차에서 도입한 stage 방식이 `sys_get_temp_dir()`(/tmp 등)에 임시 추출 후 사용자 폴더로 rename하는 구조였는데, 시놀로지 등에서 `/tmp`(RAM tmpfs)와 사용자 볼륨(`/volume1`)이 다른 파일시스템이면 PHP rename이 'Invalid cross-device link'로 실패해 추출물이 사용자 폴더로 이동되지 않는(사라진 것처럼 보이는) 치명적 문제 + 대용량 시 RAM 부족 위험. → stage를 사용자 폴더 안의 숨김 임시 디렉토리(`.fs_extract_tmp_<hash>`)에 생성해 항상 같은 파일시스템 내 이동이 되도록 수정(extract7zip/PHP ZipArchive/분할압축 3경로 모두). stage 정리 조건도 디렉토리명(.fs_extract_tmp_) 기반으로 변경. 검증: stage가 사용자 폴더 하위에 생성→같은 fs rename 성공, 이동 후 임시폴더 정리, isPathSafe 통과 확인.
- **[16차 수정] 압축 해제 시 파일명 충돌 표기를 _1 → (2)(3) 괄호 방식으로 통일 (FileManager.php):** '여기에 풀기'에서 동일 이름 파일이 있을 때 7z `-aou` 옵션이 'name_1' 형식으로 만들던 것을, 폴더 충돌과 동일하게 'name (2)', 'name (3)' 괄호 방식으로 통일. 추출은 빈 임시 stage에서 -aoa로 수행하고(충돌 없음), 최종 이동 단계에서 getUniqueFilename이 파일·폴더 모두 ' (2)' 형식으로 충돌 처리하도록 일원화. extract7zip(7z/rar/zip) + 분할압축(.001) 모두 stage 방식으로 통일. 검증: 파일 연속 충돌 시 (2)(3) 증가, 폴더 충돌 (2), 기존 파일 보존 확인.
- **🔴 [15차 수정] '여기에 풀기' 폴더명 충돌 시 (2) 분리 (FileManager.php):** '여기에 풀기'에서 압축 최상위 폴더명이 기존 폴더와 같으면 7z `-aou`가 기존 폴더 안에 병합(merge)해버리던 문제(파일 충돌만 name_1 처리되고 폴더는 합쳐짐). → '여기에 풀기'의 실제 추출을 임시 stage 폴더에서 수행하고(추출/파일명보정/개수집계/UnRAR폴백 등 모든 후처리는 stage 기준으로 그대로 동작), 완료 후 stage의 '최상위 항목(폴더/파일) 단위'로 사용자 폴더에 이동하면서 충돌 시 '폴더 (2)'/'파일 (2).ext'처럼 분리(폴더 생성 모드와 일관). 기존 사용자 데이터는 절대 덮어쓰지 않음. extract7zip(7z/rar/zip)와 PHP ZipArchive 경로 모두 최상위 단위 이동으로 통일. 실패 시 stage(임시폴더) 정리, 사용자 폴더는 무손상. 검증: 압축 '한글폴더/'가 기존 '한글폴더'와 충돌 시 '한글폴더 (2)/'로 분리 + 기존 폴더 안 파일·무관 파일 모두 보존, 충돌 없으면 그대로, 최상위 파일 충돌도 '(2)' 분리 확인. (분할압축 .001은 드물어 현행 -aou 유지.)
- **🔴 [14차 수정] RAR 추출 실패 + '여기에 풀기' 성공 오판 해결 (FileManager.php):** 시놀로지 로그 분석 — ① **RAR 추출 실패**: 시놀로지(locale=C)에서 7z은 한글경로 RAR을 `Can not open the file as archive`로 못 열고, UnRAR 폴백도 한글 절대경로를 `?`로 변환해 `Program aborted`로 실패. → UnRAR을 압축파일 디렉토리로 `cd` 후 파일명만 상대경로로 호출하도록 변경(셸이 경로를 바이트로 전달해 로케일 무관). ② **'여기에 풀기' 성공 오판**: 추출 파일 개수를 extractDir(='여기에 풀기'면 사용자 폴더) 전체로 세어, RAR 추출이 0개 성공했는데도 폴더에 원래 있던 파일들(7z/zip/rar 등)을 세어 "성공 N개"로 잘못 판정하고 완료 토스트를 띄우던 문제. → '여기에 풀기'면 추출 직전 스냅샷(preExisting)에 없던 '새로 생긴 파일'만 카운트하도록 메인 집계·UnRAR 폴백 집계 모두 수정. 이제 실제 추출물이 없으면 정확히 실패로 판정. 검증: 한글경로 RAR을 cwd 방식으로 추출 성공, 추출 실패 시 fileCount=0으로 실패 판정 확인.
- **[13차 보강] rename 매칭 안전성 강화 (FileManager.php):** 검토 중 발견 — ① 매칭 시 크기 정보가 있으면 반드시 크기까지 일치해야 rename(크기 정보 없으면 단일 파일 압축일 때만 깊이로 매칭) → 서로 다른 파일의 오매칭 방지. ② rename 대상이 이미 존재하면 여기에풀기/폴더 모드 공통으로 getUniqueFilename으로 보존(덮어쓰기 방지). (멀쩡한 로직은 미수정, 안전 우선 보강만.)
- **🔴 [13차 수정] 추출 파일명 보정을 -so 재추출 → rename 매칭 방식으로 전환 (FileManager.php):** 시놀로지 실기기 로그 분석 결과, 10차의 `-so` 재추출 보정이 시놀로지 p7zip 16.02(locale=C)에서 "기대 1/성공 0"으로 실패(해당 빌드는 `7z x -so <한글경로>` 매칭 불가) → 보정 취소 → 파일명이 `?`로 깨진 채 남던 문제. 원인: 추출 자체는 내용이 정상이고 파일명만 `?`로 치환되는데(크기 정상 확인), `-so`로 한글 경로를 다시 지정하면 그 빌드가 경로를 못 찾음. 해결: 재추출하지 않고, 이미 정상 추출된 '깨진 이름' 파일을 압축 목록(UTF-8) 기준으로 **rename**만 한다. 매칭은 (경로 깊이 + 파일 크기)로 유일하게 일치하는 것만 확정 rename하고, 모호하면 건드리지 않음(데이터 안전 우선). preExisting 사용자 파일 보호, 여기에 풀기 충돌 시 이름변경 유지. rename은 바이트 경로로 수행돼 로케일 무관(시놀로지 동작). 검증: 시놀로지 상황(파일명만 ? 깨짐, 내용 정상) 재현 → UTF-8 이름 복원(크기 보존), 여기에 풀기 기존 파일 보존, 모호 케이스 미처리 확인.
- **🔴 [12차 보강2] 7z 미설치 환경(PHP ZipArchive)의 여기에 풀기 덮어쓰기 방지 (FileManager.php):** 7-Zip 바이너리가 없는 환경에서 ZIP을 PHP ZipArchive로 푸는 경로는 `extractTo`가 같은 이름의 기존 파일을 무조건 덮어써, '여기에 풀기' 시 데이터 손실 위험이 있었음. 수정: '여기에 풀기' 모드면 임시 stage 폴더에 추출한 뒤 extractDir로 이동하되 충돌 시 getUniqueFilename(name_1)으로 기존 파일 보존(7z 경로의 -aou와 동일 취지). 폴더 생성 모드는 빈 새 폴더라 기존 방식 유지. 이로써 모든 추출 경로(7z/zip+7z바이너리, 분할압축, PHP ZipArchive, 보정 -so)가 여기에 풀기 시 기존 데이터를 보존. 검증: 7z 없는 환경에서 기존 사용자 파일 보존 + zip 새 파일은 name_1로 확인.
- **[12차 보강] 검토 중 발견된 누락 보완 (FileManager.php):** ① 분할압축(.001) 추출도 '여기에 풀기' 시 `-aou`로 기존 파일 보존(12차에서 7z/zip만 적용됐던 것을 분할압축까지 확대). ② '여기에 풀기' 파일 개수 계산이 rar 폴백 등으로 압축 목록을 못 읽어 0이 되면 실제 추출 개수(fileCount)로 폴백. (검토 중 발견, 멀쩡한 코드는 미수정.)
- **🔴 [12차 수정] 여기에 풀기 3대 문제 해결 (FileManager.php):** ① **기존 파일 덮어쓰기**: '여기에 풀기'가 `-aoa`(무조건 덮어쓰기)라 같은 이름의 사용자 기존 파일을 덮어쓰던 문제 → '여기에 풀기'는 `-aou`(충돌 시 자동 이름변경 name_1)로 변경해 기존 파일 보존(폴더 생성 모드의 (1)(2) 동작과 일관). 보정의 stage→extractDir 이동도 충돌 시 getUniqueFilename으로 보존. ② **파일 개수 오표시**: '여기에 풀기'에서 완료 토스트의 파일 개수가 extractDir(사용자 폴더) 전체 개수로 표시되던 것 → 압축 목록 기준 실제 압축 해제 파일 개수로 표시. ③ **PDF 등 바이너리 손상**: Windows에서 파일명 보정이 불필요하게 발동해 `-so` 출력이 chcp 콘솔 파이프로 변조되어 파일이 손상되던 문제 → 보정을 비UTF-8 로케일 리눅스 전용으로 제한(Windows는 7z이 Win32 API로 유니코드 파일명을 정확히 기록하므로 보정 불필요).
- **[11차 수정] 압축 해제 완료 토스트의 표시 이름 수정 (FileManager.php):** '여기에 풀기' 모드에서 완료 토스트가 `압축 해제 완료: seo (462개 파일)`처럼 **사용자 폴더명(seo)**을 표시하던 문제. 원인: extracted_to가 항상 `basename($extractDir)`인데, '여기에 풀기'는 extractDir이 현재 폴더 자체(예: users/seo)라 그 폴더명이 표시됨. 수정: '여기에 풀기' 모드면 압축파일명을, 폴더 생성 모드면 만들어진 폴더명을 표시하도록 변경(extract7zip + PHP ZipArchive 경로 2곳). 표시 전용 변경이라 추출 동작·데이터에는 영향 없음.
- **🔴 [10차 긴급수정2] 파일명 보정이 사용자의 기존 비UTF-8 파일을 삭제하던 버그 (FileManager.php):** 10차 긴급수정 후에도, '여기에 풀기' 폴더에 사용자가 예전부터 가지고 있던 비UTF-8 이름 파일(EUC-KR 등 레거시 인코딩)이 있으면, 보정의 '깨진 이름 제거'가 이를 추출물로 오인해 삭제할 수 있던 문제. 수정: 추출 직전 extractDir의 기존 항목을 스냅샷(`$preExisting`)으로 기록하고, 보정의 깨진 항목 제거 시 스냅샷에 있던(=추출 이전부터 존재한) 항목은 어떤 이름이든 절대 건드리지 않도록 함. 검증: 사용자의 비UTF-8 기존 파일 + 정상 파일 + 깨진 추출물이 섞인 '여기에 풀기'에서, 기존 파일(비UTF-8 포함) 전부 보존 + 깨진 추출물만 정상 복원 확인.
- **🔴 [10차 긴급수정] 파일명 보정이 '여기에 풀기' 시 기존 파일을 삭제하던 치명 버그 (FileManager.php):** 10차 보정(`fixExtractedFilenames`)의 교체 단계가 `extractDir` 전체를 비운 뒤 정상본으로 교체하도록 돼 있었는데, '여기에 풀기' 모드에선 `extractDir`이 사용자의 현재 폴더 자체라 **기존 파일이 전부 삭제되는 데이터 손실 사고**가 발생. 수정: extractDir 전체 삭제를 제거하고, 압축에서 나온 '깨진 이름(비UTF-8/?) 최상위 항목'만 정밀 제거 후 정상본으로 교체. 사용자의 기존 파일은 일절 건드리지 않음. `isHereMode` 전달. 검증: '여기에 풀기'로 기존 파일 3개 + 깨진 추출물 섞인 폴더에서 기존 파일 전부 보존 + 깨진 추출물만 정상 복원 확인.
- **[10차 수정] 비UTF-8 로케일 환경에서 7z/zip 추출 시 파일명 깨짐 해결 — -so 재추출 보정 (FileManager.php):** 9차(-sccUTF-8)로 목록·미리보기 파일명은 해결됐으나, 추출(압축 해제) 시 파일명이 `?`로 깨지는 문제가 시놀로지 등에서 남아있던 것. 원인(검색 확인): 7-Zip 추출은 파일을 디스크에 쓸 때 OS 파일 API가 `LC_CTYPE` 로케일을 따르는데, 시놀로지 DSM은 C/POSIX 로케일이라 비ASCII 파일명을 글자당 `?`(0x3F)로 치환해 저장(콘솔용 -sccUTF-8로는 디스크 기록을 못 고침). `?`로 치환되면 추출된 이름만으론 복원 불가. 해결: `fixExtractedFilenames()` 보정 단계를 추출 직후에 추가 — ① 목록(`l -slt -sccUTF-8`, UTF-8 정확)의 파일 경로가 디스크에 실제로 존재하는지 대조해 깨짐 감지(목록·디스크 대조 방식이라 `?`치환도 잡고 합법적 `?`파일명은 오판 안 함), ② 깨졌으면 각 파일을 별도 임시폴더에 `7z x -so`(stdout)로 받아 PHP가 올바른 UTF-8 경로로 기록, ③ **전체 파일이 모두 성공했을 때만** 기존 추출물과 교체(하나라도 실패하면 원본 보존 — 데이터 손실 방지), ④ 경로 안전성(`..`/절대경로 차단) 포함. 7z이 파일명을 디스크에 쓰지 않고 PHP가 직접 쓰므로 로케일 무관. 정상 환경(Windows/UTF-8 리눅스)은 목록 경로가 디스크에 모두 존재해 보정을 건너뜀(회귀 없음). 검증: `?`치환·비UTF-8 손실 재현 → 정상 UTF-8 이름+내용 복원, 정상 환경 스킵, 재추출 실패 시 원본 보존 모두 확인. RAR은 RarNative라 무관(제외).
- **[9차 수정] 시놀로지/로케일 없는 환경에서 7z 파일명 깨짐 해결 — -sccUTF-8 (api.php + FileManager.php):** 7차 수정(LANG 로케일 강제)으로도 시놀로지 DSM/Nginx 등에서 7z 미리보기·추출 시 한글 등 파일명이 `?`로 깨지던 문제. 원인: 시놀로지 DSM은 기본 로케일이 C/POSIX뿐이고 UTF-8 로케일(C.UTF-8 등)이 설치돼 있지 않아(검색 확인), 명령행에서 `LANG=C.UTF-8`을 강제해도 해당 로케일이 없으면 7z이 C/POSIX로 폴백해 비ASCII 파일명을 `?`로 출력. 해결: 7z의 `-sccUTF-8` 스위치(콘솔 입출력 문자셋을 UTF-8로 강제)를 목록·추출·미리보기·암호체크 모든 7z 실행에 추가 — 시스템 로케일과 무관하게 7z이 UTF-8로 입출력. 검증: 로케일 완전 제거 환경(env -i)에서 영/한/일/중/특수문자 목록·추출 전부 보존, 로케일 있는 기존 환경·구버전 p7zip 16.02에서도 회귀 없음(옵션 정상 인식). RAR은 RarNative라 무관.
- **[8차 수정] 최신 리눅스/도커 환경의 7-Zip 바이너리 탐색 보강 (api.php + FileManager.php):** 기존 7z 바이너리 탐색 경로가 `7z`/`7za`(구 p7zip)만 포함해, 최신 리눅스/도커(공식 7-Zip 리눅스판 `7zz`, 또는 `7zip` 패키지)에서 7z을 못 찾아 ZIP/7z 처리가 안 될 수 있던 문제. 검색 확인: p7zip(2016년 16.02 마지막)은 deprecated되고 공식 네이티브 `7zz`로 이행 중(Ubuntu 24.04+, Debian 12+ 등). 탐색 목록에 `/usr/bin/7zz`, `/usr/local/bin/7zz`, `7zzs`(정적 빌드), `/usr/bin/7zip`, `/bin/7zz` 등 추가(9곳 전부). 기존 경로(`7z.exe`, `/usr/bin/7z`)는 그대로 두고 후보만 추가해 회귀 없음. RAR은 RarNative.php(PHP 직접 파싱)라 바이너리 무관.
- **[7차 수정] 다양한 서버 환경에서 압축 파일명(한글 등) 깨짐 방지 (api.php + FileManager.php):** 7-Zip/UnRAR CLI의 출력·추출 파일명 인코딩이 실행 환경(콘솔 코드페이지/로케일)에 의존해, 일부 환경(특정 Windows 콘솔, 시놀로지 도커, 비UTF-8 로케일 리눅스)에서 한글 등 비ASCII 파일명이 물음표(`?`)나 깨진 문자로 표시·추출되던 문제. 원인: ① Windows는 stdout을 파이프로 받으면 콘솔이 표현 못 하는 문자를 글자당 `?`로 치환(스크린샷에서 한글 글자수와 `?` 개수 일치로 확정) ② 리눅스 p7zip은 LANG/LC_CTYPE 로케일이 UTF-8이 아니면 비ASCII 출력이 깨짐(7z 네이티브는 UTF-16 저장이라 데이터 자체는 정상). 해결: Windows는 7z 목록 출력을 콘솔 파이프 대신 임시 파일로 직접 리다이렉트(콘솔 표시 단계 우회), 리눅스는 7z/UnRAR 실행 시 `LANG=C.UTF-8 LC_ALL=C.UTF-8` 환경변수를 강제(`fs_utf8_env_prefix()` / `utf8EnvPrefix()` 헬퍼)해 항상 UTF-8 출력·추출. 목록·추출·미리보기·암호체크 모든 7z/UnRAR 실행 지점에 일관 적용. 검증: 영문/한글 파일명, zip/7z, 비UTF-8 로케일(C)에서 파일명 보존 확인. C.UTF-8 미존재 환경에서도 7z이 무시하고 정상 동작(안전한 폴백).

#### v5.8.2b (2026-06-07)

**[v5.8.2b] 암호 압축파일 처리 개선 + 압축 해제 방식 선택 (2026-06-07)**

암호(비밀번호) 걸린 7z/rar 처리와 압축 해제 폴더 방식을 개선. ("버전 올려줘" 지시로 5.8.2a→5.8.2b)

- **🔴 [근본 원인 수정] 비밀번호 `$`·`` ` ``·`"` 문자가 제거되어 "비번 틀림"이 나던 버그 (api.php + FileManager.php):** 디버그 로그로 원인 확정 — 비번 정제 정규식 `[\x00-\x1F\x7F"`$\\]`가 **`$`를 정상 비번 문자인데도 제거**해서, 사용자가 `12#$`를 입력해도 7z/UnRAR에는 `12#`만 전달됨 → 7z·UnRAR 모두 "Wrong password/Incorrect password". 즉 비번이 틀린 게 아니라 코드가 비번을 잘랐던 것. **`fs_build_password_arg()`/`buildPasswordArg()` 공통 헬퍼**를 도입해 제어문자(`\x00-\x1F`,`\x7F`)만 제거하고 `$ # \` ``` 등 정상 문자는 보존. 셸 인용은 플랫폼 규칙대로(Windows bat: `%`→`%%`, `"`만 제거(7-Zip CLI 한계)·나머지 보존 / Linux: escapeshellarg). 비번 정제 8곳(목록·추출·미리보기·분할·UnRAR 폴백) 전부 헬퍼로 교체. 검증: `12#$` 비번으로 7z 생성→추출 성공, 폴더 구조·내용 정상.
- 위 수정으로 **"폴더 따로 파일 따로" 문제도 함께 해결**: 비번이 잘려 7z가 부분 실패하며 0바이트 깨진 파일만 추출되던 것이, 비번이 온전히 전달되면서 정상 폴더 구조로 추출됨.
- **[5차 수정] ZIP 폴더 구조 깨짐 — 7-Zip으로 추출 (FileManager.php):** PHP ZipArchive가 Windows에서 만든 ZIP의 CP949/EUC-KR 레거시 인코딩 파일명을 제대로 처리 못 해 폴더 구조가 깨지는 문제(검색 확인: ZipArchive는 비ASCII 파일명 미지원). ZIP도 7-Zip 바이너리가 있으면 7-Zip(`7z x`)으로 해제하도록 변경 — 7-Zip은 인코딩을 정확히 처리. 7-Zip이 없는 환경은 기존 PHP ZipArchive로 폴백(회귀 없음). 검증: 한글 폴더구조 ZIP·암호 ZIP 모두 폴더 구조 정상 보존.
- **[6차 수정] 헤더 암호화 7z 무한 비번창 버그 (api.php archive_list):** 헤더 암호화(`-mhe=on`) 7z에서 비밀번호를 올바로 입력해 목록을 읽었는데도(`total=2`) `need_password` 플래그가 해제되지 않아, 프론트가 비번 입력창을 계속 다시 띄우던 문제(로그로 확정: 비번 `-p"12#$"` 정상 전달·목록 성공인데 need_password=YES 유지). 목록 파싱 성공(items가 채워짐) 시 `needPassword`를 false로 리셋하도록 수정. 검증: 헤더암호 7z이 비번 입력 후 목록 정상 표시, 비번창 재출현 없음.
- **[5차 수정] 7z 미리보기 비밀번호 인식 (app.js):** 데이터 암호화 7z(목록은 보이나 파일만 암호화)에서 이미지 미리보기 시 비밀번호가 전달되지 않던 문제. ① 암호화 항목 미리보기 클릭 시 비번이 없으면 비번 입력창을 띄우고 받은 뒤 재시도, ② 갤러리 네비게이션 URL에도 비밀번호 파라미터 추가(누락분 보완), ③ 암호화 이미지도 미리보기 클릭은 허용(클릭 시 비번 요청). 검증: 데이터암호 7z 이미지가 비번 입력 후 미리보기됨.

- **🔴 [긴급 수정] "여기에 풀기" 모드의 폴더 삭제 사고 방지 (FileManager.php):** "여기에 풀기"는 `extractDir`이 압축파일이 있는 폴더(개인폴더 등 기존 폴더)인데, 추출 실패·취소·비번 오류 시 `deleteDirectory($extractDir)`가 호출되어 **그 폴더가 통째로 삭제**되는 치명적 버그가 있었음. `$isHereMode` 플래그와 `$safeCleanup()` 헬퍼를 도입해, 여기에 풀기 모드에서는 어떤 실패 경로에서도 기존 폴더를 절대 삭제하지 않도록 가드. extractZip(ZIP)·extract7zip(7z/rar)·extractSplitZip(.001)의 모든 삭제 지점(실패/취소/비번오류, 총 10여 곳)에 적용. 폴더에 풀기 모드는 기존대로 새 추출 폴더만 정리(회귀 없음). 검증: 여기에 풀기 추출 실패 시 개인폴더 내 파일 전부 보존, 폴더에 풀기는 압축명 폴더만 정리 확인.

- **RAR 암호 표시 (RarNative.php):** RAR5 네이티브 파서가 파일 암호화를 감지하도록 `fs_rar5_extra_has_crypt()` 추가. RAR5 스펙상 암호화 파일은 File 헤더 extra area에 암호화 레코드(type 1)를 포함하는데, 기존엔 `encrypted=false`로 하드코딩되어 암호 자물쇠(🔒) 표시가 안 됐음. extra area를 순회해 type 1 레코드가 있으면 `encrypted=true`. (mtime 추출 함수는 무수정) 검증: 암호 rar는 감지, 비암호 rar은 오탐 없음.
- **7z 헤더 암호화 (api.php archive_list):** 헤더 암호화(`-mhe=on`) 7z은 `7z l`이 파일 목록 자체를 못 읽어 "빈 폴더"로 보이던 문제. 7z 목록 호출에 빈 비번(`-p""`)+stdin 차단(`< nul`/`< /dev/null`)을 넣어 비번 프롬프트로 멈추지 않게 하고, 암호 프롬프트/오류 감지 시 `need_password`를 반환. 프론트(`_loadZipList`)는 비번 입력창을 띄우고 비번으로 목록을 재요청 → 반디집처럼 동작. 비번 맞으면 목록+암호 배지 정상 표시.
- **미리보기 비번 전달 (api.php archive_preview):** 암호화 아카이브 내부 이미지 미리보기에 비번(`password`)을 받아 7z `e`/unrar `e` 추출에 `-p` 전달(없으면 `-p""`+stdin 차단). 프론트는 목록 단계에서 받은 비번을 미리보기 URL에 전달, 비번 보유 시 암호화 항목도 미리보기 허용.
- **압축 해제 방식 2가지 (FileManager.php + api.php + app.js):** 표준 압축 프로그램(WinRAR/7-Zip/반디집)처럼 "📁 폴더에 풀기"(압축파일명 폴더 생성 후 그 안에 풀기, 기본) / "📂 여기에 풀기"(현재 위치에 직접 풀기) 선택 모달 추가. `extractZip/extract7zip/extractSplitZip`에 `$mode='folder'|'here'` 파라미터 추가(기본 folder=기존 동작 100% 보존). 두 방식 모두 압축 내부 폴더 구조를 그대로 보존(`7z x`/`unrar x`/ZipArchive 경로 유지). 검증: 폴더/하위폴더/파일 구조 보존 확인.
- **압축 해제 사전 암호체크 멈춤 방지 (FileManager.php + api.php archive_check_password):** `7z l` 사전 체크에도 `-p""`+stdin 차단 추가 → 헤더 암호화 아카이브에서 비번 프롬프트로 무한 대기하던 잠재 멈춤 해소.
- **[3차 수정] 엔터키 확실히 동작 (app.js showZipPasswordModal):** 기존엔 엔터 핸들러가 input 요소에만 붙어 포커스가 빗나가면 동작 안 할 수 있었음. document 캡처 단계(`addEventListener(..., true)`)로 옮겨 포커스 위치·다른 핸들러와 무관하게 엔터=확인/ESC=취소 보장. close 시 핸들러 확실히 제거.
- **[3차 수정] RAR 암호 추출 "비번 틀림" 오판 수정 (FileManager.php extract7zip):** 암호화 rar을 7-Zip으로 풀면 RAR5를 "Unsupported Method"(exit≠0)로 실패하는데, 기존엔 이를 무조건 `wrong_password`로 반환해 **맞는 비번도 틀렸다고 표시**되고 UnRAR 폴백에 도달 못 했음. rar이면 exit≠0 시 비번 오류로 단정하지 않고 UnRAR 폴백으로 넘겨 재시도(rar 공식 도구가 RAR5 암호 추출 정확). 비ASCII 비번은 bat+chcp 65001로 UTF-8 전달.
- **[4차 진단용] 압축 디버그 로그/콘솔 (기본 OFF):** 압축 해제·목록 문제(폴더 구조/비번 인식) 실기기 진단용. ① **서버 로그**: `config.php`의 `EXTRACT_DEBUG`를 `true`로 바꾸면 `<DATA_PATH>/extract_debug.log`에 archive_list(목록·암호감지·비번 hex·7z 명령)와 extract7zip(추출 명령·실제 7z 출력·exit코드·추출된 파일 구조)·archive_preview(미리보기 추출)가 전부 기록됨. ② **브라우저 콘솔(F12)**: `[FS압축]` 콘솔 로그. 진단 완료 후 정리 — `EXTRACT_DEBUG`는 기본 `false`(no-op), 콘솔 로그는 주석 처리(호출부 보존)해 평상시 출력 없음. 재진단 시 주석 해제 또는 `EXTRACT_DEBUG=true`로 즉시 활성화 가능. ③ **[개선] 디버그 중복 추출 제거**: 디버그 ON일 때 extract7zip의 진단용 동기 실행이 실제 추출 폴더(extractDir)를 써서 7z이 두 번 실행되던 것을, 별도 임시 폴더(`fs_7z_diag_*`)에서 진단하고 즉시 정리하도록 변경. 본 추출은 항상 1회만 실행. 디버그 OFF면 전체 no-op(영향 없음).
- **엔터키:** 압축 비번 입력(showZipPasswordModal)·범용 비번(promptPassword)은 기존에 이미 엔터/ESC 처리 구현됨. 신규 방식선택 모달에도 ESC 닫기 적용.
- 안전성: 모든 셸 호출 escapeshellarg, 비번 위험문자 제거(`[\x00-\x1F\x7F"`$\\]`), 임시 추출 격리·정리 유지. 기존 호출부는 mode 생략 시 동작 불변(회귀 없음). 전 파일 `php -l`/`node --check` 통과.
- ⚠️ 알려진 환경 의존: 컨테이너 p7zip 16.02는 RAR5 **암호 추출**을 "Unsupported Method"로 미지원(목록·감지는 정상). Windows 정품 7-Zip/WinRAR은 지원하므로 실기기 확인 권장. 모든 검증은 Linux 컨테이너 기준.

**[rhwp 0.7.15 → 0.7.16 업그레이드] (2026-06-19)**

HWP/HWPX 뷰어·편집기 엔진(rhwp)을 0.7.16으로 업그레이드. 0.7.15 후속 patch — HWPX 저장 계약(serializer fidelity) 정밀화, 누름틀 안내문 한컴 호환, rhwp-studio 드래그&드롭 보안 게이트(opt-in 모달), 다크테마 추가, 렌더·표·그림 정합 + 외부 기여자 PR 다수. 공개 API 하위 호환 유지(PATCH) — `HwpDocument`/`renderPageSvg`/`version` ABI 보존으로 뷰어 호환.

- npm `@rhwp/core@0.7.16` tarball shasum 진본 검증(`9450eabd6c7327eddebc0b3048eb54f9f7aa24bd`) 후 빌드. 빌드 결과: `index-DbPVRDJn.js`, `index-BCYJGuoE.css`, `rhwp_bg-BRJ7oLBV.wasm`.
- **🆕 CanvasKit 신규 자산**: 0.7.16부터 `canvaskit-DB1zH3nD.wasm`(7MB) + `canvaskit-renderer-CLU8e50R.js`가 studio 빌드 산출물에 추가됨(렌더링 백엔드). studio 폴더에 함께 배치. JS가 `import.meta.url` 기반 상대경로로 동적 로드하므로 base href와 무관하게 자기 위치(studio/)에서 해결 — PHP 참조 불필요. (가이드 v6는 0.7.12 기준이라 미기재 → STEP 4에 canvaskit 복사 추가)
- studio 패치 매 업그레이드 재적용: J1(file:save Ctrl+S 매핑 제거) 1건, J2(file:print Ctrl+P 매핑 제거) 1건, P2(CSS `../images/` → `images/`) 2건 적용. P1(절대경로) 0건은 정상. 다크테마용 `icon_small_ko_dark.svg`도 정상 경로 처리(충돌 없음).
- vite 빌드 시 PWA·커스텀 플러그인 보존 위해 `base: './'`만 안전 추가(옵션 B).
- 사전 회귀 점검: 0.7.16은 정정·기능추가 위주 PATCH, 렌더링 새 회귀 없음. 드래그&드롭 보안 게이트는 우리가 안 쓰는 경로(`action=stream`으로 주입). HWPX 저장 계약 정밀화는 우리가 차단(5중 방어)하는 영역이라 영향 없음. 개체 비율 유지(#1430)는 편집 기능 개선(회귀 아님).
- HWPX 직접 저장: 0.7.16도 여전히 베타 비활성 추정 — HWP 형식 저장만 동작. FileStation HWPX 차단(5중 방어) 그대로 유지.
- 커스텀 기능 보존 검증 통과(서버 저장/다른 이름으로 저장/Blob 캡처/MutationObserver/Ctrl+S/저장 중 동기화 일시중지/웹하드 자동 갱신 등). PHP 커스텀 로직은 파일명·버전 주석 외 무변경.
- 알려진 한계: SVG/Canvas `preserveAspectRatio="none"`(과거 #335)은 코드상 잔존하나 실사용 재현 없음(0.7.8~0.7.16 동일).
- ⚠️ FileStation 버전 유지(v5.8.2d — 펜닐 룰, "버전 올려줘" 명시 없음).
- ⚠️ **빌드/실기기 확인 필요**: 컨테이너에서 문법·패치·경로까지 검증. 실제 브라우저에서 ① HWP 뷰어 렌더링 ② 에디터 열기/편집/서버 저장(Ctrl+S) ③ 다크테마 추가에 따른 커스텀 메뉴 표시 ④ CanvasKit 렌더 정상 동작은 펜닐님 환경 확인 권장.

#### v5.8.2a (2026-06-06)

**[v5.8.2a] rhwp 0.7.14 → 0.7.15 업그레이드 (2026-06-07)**

HWP/HWPX 뷰어·편집기 엔진(rhwp)을 0.7.15로 업그레이드. 0.7.14 후속 security patch 사이클 — 브라우저 확장 service worker fetch 경로 보안 강화(SSRF 차단), 수식 TAC 흐름·미주 커서 이동 정정, HWPX 저장 계약 후속 보강(그림 flip/rotation·isEmbeded·대각선 셀 테두리). 공개 API 하위 호환 유지(PATCH) — `HwpDocument`/`renderPageSvg`/`version` ABI 보존으로 뷰어 호환.

- npm `@rhwp/core@0.7.15` tarball shasum 진본 검증(`dfe74e615dd8cddb2a3920317be798cf2791d31e`) 후 빌드. 빌드 결과: `index-BuFkkamh.js`, `index-C9eG_4qi.css`(0.7.14와 동일 해시 — CSS 무변경), `rhwp_bg-DsnvX-Xj.wasm`.
- studio 패치 매 업그레이드 재적용: J1(file:save Ctrl+S 매핑 제거) 1건, J2(file:print Ctrl+P 매핑 제거) 1건, P2(CSS `../images/` → `images/`) 2건 적용. P1(절대경로) 0건은 정상.
- vite 빌드 시 PWA·커스텀 플러그인 보존 위해 `base: './'`만 안전 추가(통째 덮어쓰기 회피).
- 사전 회귀 점검: 0.7.15는 보안·정정 위주 PATCH, 렌더링 새 회귀 없음. 보안 변경(확장 service worker)은 웹뷰어 경로와 무관. HWPX 저장은 우리가 차단(5중 방어)하는 영역이라 영향 없음.
- HWPX 직접 저장: rhwp 0.7.15도 여전히 베타 비활성(studio 코드 `#196` 차단, `#197 완전 변환기` 완료 시까지). HWP 형식 저장만 동작. FileStation HWPX 차단(5중 방어) 그대로 유지.
- 커스텀 기능 보존 검증 통과(서버 저장/다른 이름으로 저장/Blob 캡처/MutationObserver/Ctrl+S/저장 중 동기화 일시중지/웹하드 자동 갱신 등). PHP 커스텀 로직은 파일명·버전 주석 외 무변경 확인.
- 알려진 한계: SVG/Canvas `preserveAspectRatio="none"`(과거 #335)은 코드상 잔존하나 실사용 재현 없음(0.7.8~0.7.14 동일).
- ⚠️ FileStation 버전 유지(v5.8.2a — 펜닐 룰, "버전 올려줘" 명시 없음).

**[v5.8.2a 정정/롤백] RAR5 파일명 임의 변환(fs_rar5_decode/normalize) 제거 — 원본 코드포인트 보존 (2026-06-06)**

직전에 추가했던 RAR5 파일명 "escape 디코더"가 **오진이었음을 확인하고 전량 롤백**. 원본(코드포인트 그대로 보존) 동작으로 복구.

- 오진 경위: 일부 RAR5의 파일명이 `U+FFFE` 다음에 `U+E000~U+E0FF`(PUA, 사용자정의영역) 코드포인트로 저장된 것을 보고, 이를 "한글이 escape된 것"으로 오판해 `cp - 0xE000` 바이트로 변환하는 디코더를 넣었다. 변환 결과가 우연히 유효한 한글 UTF-8(`만화 [완결]` 등)이 나오자 맞다고 단정한 것이 오류였다.
- 사실 확인: **정품 WinRAR로 동일 RAR을 열면 그 PUA 코드포인트(특수 그림문자)가 그대로 표시된다.** 즉 RAR에 저장된 진짜 파일명이 PUA 문자이며(이 테스트 파일을 만든 압축기가 그렇게 저장함), WinRAR(표준 참조 구현)과 일치시키려면 **어떤 변환도 하지 않고 원본 코드포인트를 그대로 보존**해야 한다. 디코더는 원본을 변조하는 버그였다.
- 조치: `api/RarNative.php`를 디코더 추가 이전 원본으로 복원(fs_rar5_decode_name·fs_rar5_normalize_name 제거, RAR5 파일명은 헤더의 UTF-8 바이트 그대로 사용). `api.php`도 원본으로 복원(archive_preview에 추가했던 "전체추출+정규화 매칭" 폴백 제거). 두 파일 모두 직전 배포 이전 원본과 diff 동일 확인, `php -l` 통과.
- 결과: RAR 목록·미리보기가 WinRAR과 동일하게 원본 코드포인트를 보존. PUA 글리프는 뷰어/폰트에 따라 그림문자 또는 대체문자(￾)로 보이지만, 바이트는 원본과 100% 일치(변조 없음). 7z 경로는 이번 변경과 무관하게 기존대로 정상.
- 최종 결론(PUA 표시 = 폰트 문제, 데이터 무수정 유지): 브라우저 콘솔에서 `App._zipItems`의 코드포인트를 직접 확인한 결과 `U+E0EB U+E0A7 …`(PUA) + `U+FFFE`로, **FileStation이 들고 있는 데이터는 WinRAR과 100% 동일**함(U+FFFD 같은 손상 없음). 즉 목록이 두부문자(□/￾)로 보이는 것은 데이터 손상이 아니라, 웹 폰트에 PUA(U+E000~U+F8FF) 영역 글리프가 없어서 생기는 **순수 렌더링/폰트 문제**다. WinRAR·반디집은 해당 PUA 글리프를 가진 폰트가 있어 그림문자로 보여주는 것일 뿐, PUA 파일명은 본래 이식성이 없다(폰트/환경이 다르면 동일하게 깨짐).
- 공식 스펙 근거(RAR 5.0 archive format, rarlab/unrar): "Unix 파일명에 Unicode/UTF-8로 변환 불가한 high ASCII 문자가 있으면, 해당 바이트를 **0xE080–0xE0FF PUA 영역에 매핑**하고 문자열에 **0xFFFE(non-character) 마커를 삽입**한다. 이렇게 매핑된 이름은 **이식성이 없으며 생성한 바로 그 시스템에서만 정확히 복원**할 수 있다(Such mapped names are not portable and can be correctly unpacked only on the same system where they were created)." → 즉 U+FFFE+U+E0xx는 RAR이 정의한 정식 동작이고, 원래 시스템 인코딩을 모르는 다른 환경(WinRAR 포함)에서는 PUA 그대로 두는 것이 스펙에 부합한다. (참고: 이번 테스트 RAR의 PUA 바이트를 꺼내 UTF-8로 풀면 `만화 [완결]`이 나오긴 하나, 인코딩이 항상 UTF-8이라는 보장이 없어 임의 복원은 위험 — WinRAR과 동일하게 보존하는 방침을 채택함.)
- 결정 근거 보강: 위 스펙에 따라 PUA 보존이 표준에 부합하는 안전한 선택. 임의 디코딩(특정 인코딩 가정)은 다른 시스템 인코딩으로 만든 파일에서 오역 위험이 있어 채택하지 않음.
- 결정: 데이터가 이미 정확하고(미리보기·추출은 PUA 코드포인트로 매칭되어 정상 동작), 실사용 파일에는 이런 PUA 그림문자가 없으므로(일반 한글·일본어·중국어·특수문자는 정상 표시됨), **코드를 추가 수정하지 않고 원본 보존 상태를 유지**한다. PUA를 WinRAR처럼 그림으로 보여주려면 해당 PUA 글리프 폰트를 `@font-face`로 웹에 탑재해야 하나, 환경 종속·라이선스·유지보수 부담 대비 실익이 낮아 보류. (정상 동작하는 표시 로직을 건드려 회귀를 만들지 않기 위함)

**[v5.8.2a 버그 수정] rar 내부 한글/유니코드 파일명 깨짐 — PHP 네이티브 RAR 헤더 직접 파싱 (2026-06-06)**

UnRAR 목록 우선화(아래 항목)를 적용해도 Windows에서 한글 파일명이 여전히 깨지는 경우가 있어, 7-Zip/UnRAR CLI에 의존하지 않고 RAR 헤더를 직접 읽어 파일명을 추출하는 방식으로 근본 해결. (반디집 같은 전용 압축 프로그램이 쓰는 방식)

- 근본 원인: 7-Zip·UnRAR CLI 모두 출력이 Windows 콘솔 코드페이지(chcp)에 의존. `chcp 65001`을 붙여도 shell_exec의 파이프/리다이렉션 환경에서 일관되게 적용되지 않아 한글이 깨질 수 있음. CLI를 거치는 한 환경 의존을 완전히 제거할 수 없음.
- 해결: `api/RarNative.php` 신규 — PHP만으로 RAR 헤더를 직접 파싱.
  - RAR5(`Rar!\x1A\x07\x01\x00`): 가변정수(vint) 파싱 → File 헤더(type 2)에서 파일명을 UTF-8 그대로 추출(원본 코드포인트 보존 — WinRAR과 동일). 크기·디렉토리 플래그·extra area의 파일시간 레코드(type 3, Unix/Windows FILETIME)에서 수정시각 추출.
  - RAR4(`Rar!\x1A\x07\x00`): File 헤더(0x74) 고정 오프셋 파싱(NAME_SIZE=블록+26, 이름=블록+32, HIGH 필드 시 +8). 유니코드 확장 이름(플래그 0x200)은 UnRAR의 EncodeFileName 알고리즘으로 디코드. DOS datetime에서 수정시각, head_flags 0xe0=디렉토리, 0x04=암호화 추출.
- 통합: archive_list에서 rar이면 **네이티브 파싱을 최우선** 시도. 성공 시 7-Zip 목록을 교체하고 method='rar'(화면 '· RAR'), UnRAR 셸 폴백은 건너뜀. 네이티브 실패(헤더 암호화 등) 시에만 기존 UnRAR/7-Zip 폴백 동작. zip/7z/iso 등 다른 형식과 7-Zip 블록은 무변경.
- RAR4 비유니코드 OEM 이름은 호출측에서 UTF-8 검증 후 CP949 폴백. 1GB 초과 파일은 메모리 보호를 위해 네이티브 생략(기존 폴백 사용).
- 검증: PHP `php -l` OK, JS `node --check` OK. 실제 RAR5/RAR4 파일 및 한글 RAR5로 파일명·크기·날짜·디렉토리 개수 일치 확인(파일/디렉토리 카운트가 7-Zip·UnRAR 결과와 정확히 일치). ※ Windows 실기기에서 한글 목록 표시('· RAR' 라벨)와 내부 이미지 미리보기 검증 권장. 미리보기/추출은 여전히 7z/UnRAR로 해당 이름을 추출하므로(별도 경로), 한글 미리보기가 안 되면 추출 경로가 다음 과제.

**[v5.8.2a 버그 수정] rar 내부 한글/유니코드 파일명 깨짐 — UnRAR 목록 우선화 (2026-06-06)**

rar 압축 내부의 한글/유니코드 파일명이 `___?羅??萃??溜?`처럼 깨져 표시되던 문제 수정. (7-Zip은 rar의 유니코드 파일명 처리가 약함 — RAR은 파일명을 UTF-16으로 저장하는데 7-Zip이 콘솔 코드페이지로 변환하며 손실)

- 원인: 7-Zip이 rar를 읽되 한글 파일명을 깨뜨려도 목록 항목은 채워지므로(`empty($items)` 아님), 기존 UnRAR 폴백(0개일 때만 동작)이 작동하지 않아 깨진 이름이 그대로 표시됨.
- 수정: archive_list에서 rar이고 UnRAR이 있으면 UnRAR 목록을 우선 사용(RAR 공식 도구가 유니코드 정확). UnRAR 결과를 임시 배열에 모아 성공 시 7-Zip 목록을 교체, UnRAR 실패 시 7-Zip 목록 유지(자동 폴백). 7-Zip 목록 블록·zip/7z/iso 등 다른 형식은 무변경.
- 미리보기: 목록이 정상 이름을 주면 미리보기 시 7-Zip이 해당 이름을 못 찾아(0개) `fileData=null` → 기존 archive_preview UnRAR 폴백(`unrar e`)이 정상 이름으로 추출. (7z의 "No files to process" 동작 실측 확인)
- 검증: PHP `php -l` OK, 7z e 미존재 entry 0개 추출 확인. ※ 실제 rar 실기기 검증 권장. extract(전체 압축해제)는 별도 — 아래 한계 참고.

**[v5.8.2a 버그 수정] 압축 목록 유니코드 파일명 처리 — 7z 목록 호출 chcp 누락 (2026-06-06)**

압축(7z/rar/iso 등) 내부 파일명에 일본어·중국어 등 비한글 유니코드가 있을 때 Windows에서 목록이 깨질 수 있던 문제 수정. (한글은 기존 CP949→UTF-8 변환으로 보완돼 있었음)

- 원인: archive_list의 7z `l -slt` 목록 호출에만 `chcp 65001`(UTF-8 콘솔)이 빠져 있었음. UnRAR 목록·미리보기 추출·암호 체크에는 이미 적용돼 있어 7z 목록만 불일치.
- 수정: 7z 목록 호출에 Windows 분기로 `chcp 65001` 추가. 이제 인코딩 처리 4곳(7z 목록/UnRAR 목록/미리보기 추출/암호 체크) 모두 일관. saveEntry의 CP949→UTF-8 폴백은 UTF-8 출력 시 자동 스킵되어 충돌 없음.
- 클라이언트는 기존에도 `encodeURIComponent`로 entry 전달, 추출은 escapeshellarg+chcp라 특수문자([], &, 공백 등)·한글은 정상 처리됐음. 이번 수정으로 비한글 유니코드까지 정확.
- 검증: PHP `php -l` OK. 한글/일본어/특수문자 이미지명 목록 파싱 확인. ※ Windows 실기기에서 일본어 등 파일명 검증 권장.

**[v5.8.2a 기능] 헤더 암호화 7z/rar 압축해제 — 비밀번호 입력창 지원 (2026-06-06)**

암호 걸린 7z/rar 압축해제가 ZIP처럼 동작하도록 개선. 일반 암호화(내용만)는 기존에도 비번 입력창이 떴으나, 헤더 암호화(파일 목록까지 암호화)는 압축해제 시 감지가 안 돼 "압축 해제 실패"로 끝나던 것을 보완.

- 원인: extract7zip 사전 암호화 체크가 `Encrypted = +`(항목 정보)에만 의존 → 헤더 암호화는 목록 자체가 안 읽혀 해당 신호가 없어 미감지 + `7z l`이 비번 프롬프트로 멈출 위험.
- 수정: 사전 체크를 틀린 더미 비번(`-p`)으로 시도 + stdin 차단. 헤더 암호화면 7-Zip이 `Cannot open encrypted archive` / `Wrong password`를 반환 → 이를 추가 감지. 무암호=목록정상(미암호화), 일반암호=`Encrypted=+`, 헤더암호=`Cannot open encrypted` 3케이스 정확 구분(실측).
- 클라이언트 무변경: 기존 extract `need_password` 흐름(입력창 → 비번 재요청 → `7z x -p` 해제) 재사용. ZIP·일반 암호화 동작 100% 보존.
- 검증: PHP `php -l` OK, 7z로 무암호/일반암호/헤더암호 3케이스 감지 실측. ※ 실제 헤더 암호화 rar 실기기 검증 권장.

**[v5.8.2a 기능] rar 처리에 UnRAR 폴백 추가 — 7-Zip이 못 읽는 rar 대응 (2026-06-06)**

7-Zip의 rar 지원 한계로 일부 rar(특정 RAR5 방식 등)에서 목록이 0개로 나오던 것을, RAR 공식 도구 UnRAR 폴백으로 보완. 7-Zip으로 먼저 시도하고, rar인데 목록/추출이 안 되면 UnRAR로 재시도.

- 서버(archive_list): UnRAR 바이너리 경로 탐색(WinRAR\UnRAR.exe·Rar.exe / unrar) 추가. 7-Zip이 0개 반환 + rar 확장자면 `unrar lt`(technical list)로 목록 재파싱(Name/Type/Size, 빈 줄 구분, CP949→UTF-8 변환). 기존 7-Zip 동작은 100% 보존 — 7-Zip이 읽으면 그대로, 못 읽을 때만 UnRAR.
- 서버(archive_preview): 7-Zip 이미지 추출 실패 + rar면 `unrar e`로 임시 디렉토리 추출 후 읽기(바이너리 안전 방식).
- 서버(extract7zip, 압축해제): 7-Zip 추출 결과가 0개(rar)면 `unrar x`로 재추출 후 파일 수 재집계. 암호화 rar도 커버 — 비밀번호가 있으면 `-p<password>` 추가(7z x와 동일한 비번 처리 패턴). 무암호는 비번 없이 추출(회귀 없음). 추출 폴백은 출력 파싱이 없어(파일 생성 여부만 확인) 안정적. extractSplitZip(분할 압축)은 영향 없음.
- 보안: escapeshellarg로 command injection 방지, stdin 차단으로 비밀번호 프롬프트 멈춤 방지, 임시 디렉토리 추출 후 정리.
- 검증: PHP `php -l` OK. UnRAR lt 표준 출력 파싱 시뮬레이션 확인(파일/디렉토리/한글 구분). ※ 서버에 WinRAR/UnRAR 설치 필요, 실제 rar로 실기기 검증 필요.

**[v5.8.2a 기능] 시스템 설정에 UnRAR 설치 상태 표시 (2026-06-06)**

관리탭 > 시스템 설정 > "압축파일 미리보기 설정"에 7-Zip 상태만 있던 것에 UnRAR(rar 전용) 설치 상태 표시를 추가. rar UnRAR 폴백 기능과 일관성 확보.

- 서버: system_info 응답에 `unrar` 정보(설치 여부/경로/버전) 추가. UnRAR 경로 탐색은 archive_list 폴백과 동일(WinRAR\UnRAR.exe, Rar.exe, /usr/bin/unrar 등).
- 클라이언트: 7-Zip 상태 패널 아래 UnRAR 상태 패널 추가(설치됨/미설치). 안내 테이블에 "UnRAR(선택) — 7-Zip이 못 읽는 rar 보완" 행 추가.
- 설치 안내: 7-Zip 설치 안내 박스 아래 UnRAR 설치 안내 박스 추가(Windows win-rar.com, Linux apt/yum install unrar). 선택 사항임을 명시.
- UnRAR은 선택 사항(7-Zip이 대부분의 rar 처리, UnRAR은 그 외 rar 보완)이므로 미설치 시 경고가 아닌 회색 안내로 표시.
- 검증: PHP `php -l`(api.php·index.php) OK, app.js node --check OK. UnRAR 감지 실측(경로·버전 인식) 확인.

**[v5.8.2a 버그 수정] 압축/ISO 내부 .ico 이미지 미리보기 제외 (2026-06-06)**

압축(zip/7z/rar)·ISO 내부 목록에서 `.ico`(아이콘) 파일이 이미지로 인식되어 미리보기 대상이 되던 것을 제외. Portable 앱 등 아이콘이 많은 압축에서 불필요한 이미지 미리보기 링크가 다수 생기는 문제 해소.

- 수정: 압축 내부 이미지 판정 확장자에서 `ico` 제거 — 클라이언트 3곳(목록 미리보기 링크 생성, 갤러리 네비게이션, 이미지 찾기) + 서버(archive_preview 이미지 화이트리스트). jpg/jpeg/png/gif/webp/bmp/svg만 미리보기 대상.
- 범위: 압축/ISO 내부 미리보기 흐름만. 일반 파일 탐색기의 `.ico` 파일 타입 분류·아이콘 표시는 그대로 유지.
- 검증: PHP `php -l` OK, app.js node --check OK. 서버 로직 실측 — .ico는 400 거부, png/jpg는 정상 미리보기.

#### v5.8.2 (2026-05-29 ~ 2026-06-05)

**[v5.8.2 버그 수정] 압축 내부 이미지 미리보기 — 7z/rar 등에서 안 뜨던 문제 (2026-06-05)**

zip 압축은 내부 이미지 미리보기가 되는데 7z/rar 등은 목록만 보이고 이미지가 안 뜨던 문제. 정밀 진단 → Windows 바이너리 stdout 파이프 문제 확정·수정.

- 원인: `archive_preview`가 7z/rar 내부 이미지를 `7z e -so`(stdout 파이프)로 추출하는데, Windows에서 `shell_exec`로 **바이너리(이미지) stdout**을 받으면 cmd 파이프가 데이터를 깨뜨려 추출 실패. zip은 `ZipArchive`(PHP 내장)이라 shell을 안 거쳐 정상, 7z/rar 목록은 **텍스트** 출력이라 정상 → "zip만 됨 + 7z/rar는 목록만 보이고 이미지만 실패" 증상과 일치. 파일명(숫자/영문/한글) 무관하게 모든 이미지 실패.
- 수정: stdout 파이프(`-so`) → **임시 디렉토리로 추출 후 `file_get_contents`로 읽기**로 변경. 바이너리 안전 + 크로스플랫폼. 동시 요청 충돌 방지용 고유 임시 디렉토리(md5+microtime+rand), 읽은 뒤 즉시 정리. Windows는 한글/유니코드 경로 대응 위해 `chcp 65001`(UTF-8 콘솔) 후 실행(`archive_check_password`와 동일 검증된 패턴).
- 안전성: zip 경로(PHP 내장)는 그대로라 영향 0. 7z 바이너리 탐색 로직·크기 제한(10MB)·경로 트래버설 방어 모두 유지. command injection은 `escapeshellarg`로 방어.
- 검증: PHP `php -l` OK. 새 추출 로직 실측 — 루트/하위폴더/숫자/한글 파일명 전부 정상 추출 확인(Linux). Windows 실기기 확인 필요.

**[v5.8.2 버그 수정] 업로드 MIME 검증 — 정상 파일 거부 문제 근본 개선 (2026-06-05)**

"모든 파일 허용" 모드인데도 rar 등 일부 파일이 업로드 거부되던 문제. 정밀 진단 → MIME 검증 로직의 구조적 결함 확정·수정.

- 원인: `validateMimeType`이 확장자별 MIME 화이트리스트와 **정확히 일치**해야 통과시키는 구조. PHP `finfo`가 반환하는 실제 MIME이 목록에 없으면(예: rar의 finfo 값은 `application/x-rar`인데 목록엔 `application/x-rar-compressed`·`application/vnd.rar`만 있음) 정상 파일도 거부됨. "모든 파일 허용"은 확장자 제한만 푸는 것이고 MIME 검증은 별개로 동작 → 화이트리스트에 등록된 확장자가 오히려 더 엄격히 검사받는 모순. finfo 변형 MIME을 확장자마다 무한정 등록해야 하는 땜질 구조였음.
- 수정: "정확 일치 안 하면 거부" → "위장된 실행 파일만 거부"로 전환. ① 화이트리스트 일치 → 통과(기존 동작 100% 보존) ② 불일치해도 위험 MIME(`application/x-httpd-php`·`text/x-php` 등 PHP/스크립트, 또는 바이너리 확장자에 숨긴 `text/html`)만 차단, 그 외 정상 파일의 MIME 변형은 통과. 텍스트/마크업 확장자(txt/html/svg 등)는 HTML 내용 허용.
- 보안: 서버 실행 확장자(php/jsp/asp 등)는 `serverExecExts`에서 MIME과 무관하게 항상 차단(불변). 이 함수는 "안전한 확장자로 위장한 위험 내용" 보조 차단 역할 유지 — jpg로 위장한 php/html은 차단됨.
- 안전성: 화이트리스트 일치 경로는 그대로라 기존 정상 파일 영향 0. 일반 업로드(1493)·청크 업로드(1808) 양쪽 동일 함수라 함께 적용.
- 검증: PHP `php -l` OK. 리플렉션 실측 — 정상 rar/zip 통과, php·html 위장 차단, txt 내 html 통과 전부 확인.

**[v5.8.2] rhwp 0.7.13 → 0.7.14 업그레이드 (2026-06-05)**

HWP/HWPX 뷰어·편집기 엔진(rhwp)을 0.7.14로 업그레이드. 0.7.13 후속 patch 사이클 — 미주(해설) 흐름·간격 정합, 수식 렌더링 정밀화, 표 셀 안 그림 편집(삽입·복사·hit-test) 한컴 정합, HWPX 저장 계약 확장, 외부 기여자 PR 다수 흡수. 공개 API 하위 호환 유지(PATCH) — `HwpDocument`/`renderPageSvg`/`version` ABI 보존으로 뷰어 호환.

- npm `@rhwp/core@0.7.14` tarball shasum 진본 검증 후 빌드. 빌드 결과: `index-DEFSFLZA.js`, `index-C9eG_4qi.css`, `rhwp_bg-DaWO6n11.wasm`.
- studio 패치 매 업그레이드 재적용: J1(file:save Ctrl+S 매핑 제거 — 커스텀 서버저장 우선) 1건, J2(file:print Ctrl+P 매핑 제거 — 브라우저 인쇄 fallback) 1건, P2(CSS `../images/` → `images/`) 1건 적용. P1(절대경로) 0건은 정상.
- vite 빌드 시 PWA·커스텀 플러그인 보존 위해 `base: './'`만 안전 추가(통째 덮어쓰기 회피).
- 신규 폰트 `NotoSansKR-ExtraLight.woff2` 추가(한컴 돋움 폴백 정합, rhwp #1234).
- 커스텀 기능 16항목 보존 검증 통과(서버 저장/다른 이름으로 저장/Blob 캡처/MutationObserver/Ctrl+S/저장 중 동기화 일시중지/웹하드 자동 갱신 등). PHP 커스텀 로직은 파일명·버전 주석 외 무변경 확인.
- canvaskit 동적 렌더러 파일은 0.7.13과 동일하게 제외(렌더 실패 시 기본 렌더러 폴백 존재, 실사용 검증된 상태 유지).
- 알려진 한계: SVG/Canvas `preserveAspectRatio="none"`(과거 #335)은 코드상 잔존하나 실사용 재현 없음(0.7.8~0.7.13 동일).

**🟢 B-2. 전체화면 버튼/시간표시 위치 — 영상 표시 영역 기준 + 기준 요소 수정 (2026-05-29)**

- **전체화면 버튼(fsBtn) 위치 재설계** (`_positionFsBtn`, app.js): 고정 px 대신 동영상 표시 영역(object-fit:contain) 우하단 안쪽 기준으로 계산. 분기 3가지 — ①전체화면(네이티브/유사)=CSS `:fullscreen` 규칙 사용(inline 비움) ②PC(>1024px)=고정 `right:5px/bottom:70px`(펜닐님 지시, 영상영역 계산 안 함) ③모바일 세로=`marginX/Y + padRight:5 + padBottom:64`. 재계산 트리거: loadedmetadata/resize/orientationchange/fullscreenchange + wrap class MutationObserver(유사 전체화면) + setTimeout(300/1000). 모달 닫힐 때 리스너+observer 정리(add=remove).
- **transcode-duration(노란 HLS 시간표시) 위치 — 장기 디버깅 끝 근본 원인 확정**:
  - **증상**: 동영상마다, 재생 전/후/일시정지로 노란 시간 위치가 왔다갔다. `bottom` 계산값(예: 317px)이 영상 위 검은 공간에 뜸.
  - **여러 헛수정 후 콘솔 로그(`[TD]`/`[FSB]`)로 확정한 진짜 원인**: 자막 있는 영상은 `<video>`가 `.video-sub-wrapper`로 한 번 더 감싸짐 → transcode-duration이 `video.parentElement`(=sub-wrapper)에 append되어 **position:absolute 기준 요소가 전체화면 버튼(.video-player-wrap 기준)과 달랐음**. 같은 `bottom:317px`라도 기준이 달라 전체화면 버튼은 영상 하단(렌더 bottom 553), 노란 시간은 위쪽(렌더 bottom 299)으로 그려짐. (계산식 자체는 처음부터 옳았고, 붙는 위치가 문제.)
  - **해결**: ①노란 시간을 `video.closest('.video-player-wrap')`(=host)에 직접 append — 전체화면 버튼과 동일 기준 ②위치는 `_positionTranscodeDuration`이 전체화면 버튼의 실제 렌더 위치(getBoundingClientRect)를 읽어 `bottom = wrap.bottom - fsBtn.bottom`으로 맞춤(계산이 아닌 렌더 결과 복사 → CSS/inline 무엇이 적용되든 항상 같은 줄). left:5px(좌측), 전체화면 버튼은 right(우측) → 같은 높이 양끝. ③전체화면/PC는 inline 비워 CSS 사용. ④`_positionFsBtn` 끝에서 `_positionTranscodeDuration` 단방향 호출(무한루프 없음). ⑤재계산은 video 자체 이벤트(pause/play/resize)+생성 시(ensureEl)로 연결 — window 리스너 없어 video 제거 시 자동 정리(누수 없음).
- **CSS**: `.transcode-duration { background: rgba(0,0,0,0.65) }` 주석 처리(펜닐님 요청). `.video-fullscreen-btn`/`.video-play-overlay`의 `rgba(0,0,0,0.55)`는 무관해 보존.

**🟢 B-3. PiP(Picture-in-Picture) 모달 연동 개선 — B-1 방식 (2026-05-31)**

- **배경**: 메인 미리보기는 영상이 모달(`modal-preview`) 안에 있어, PiP 켜도 모달이 화면을 가려 파일 작업 불가. 또 모달을 닫으면 영상 정리(ffmpeg/HLS)가 실행돼 PiP도 멈춤. (공유 페이지 `share.php`는 영상이 본문에 직접 있어 가릴 모달이 없으므로 수정 불필요 — 그대로 보존.)
- **표준 확인**(W3C/MDN): `leavepictureinpicture`는 "X 종료"와 "탭으로 돌아가기"를 구분하지 못함(동일 이벤트). 일반 video PiP에선 자동 구분 불가 → **B-1 방식**(둘 다 모달 복원) 채택.
- **B-1 동작** (`assets/js/app.js`): ①PiP 버튼 → `enterpictureinpicture` → `_hidePreviewForPip()`로 모달을 화면에서 **숨김만**(`display:none`, video는 DOM 유지 — W3C상 DOM 제거 시 PiP 끊김) ②PiP 종료(X/탭복귀) → `leavepictureinpicture` → `_restorePreviewFromPip()`로 모달 복원(`display:flex`, 영상 계속) ③모달 진짜 닫기 → 기존 `hideModal` 정리. 중복 바인딩 방지 `video._pipEvtBound` 가드(PiP 버튼은 매번 새로 생성돼 click은 중복 없음).
- **버그1 수정 (PiP 중 다른 파일 열기 → 검은 화면)**: 새 파일 열 때 `_showPreviewImpl`의 `_preClean`은 `hideModal`을 안 거쳐 `_pipModalHidden` 플래그가 안 풀려, 나중에 PiP 종료 시 엉뚱한(이미 닫힌) 모달을 복원 → 검은 화면. → `_preClean` 시작에서 `_pipModalHidden=false` + 이전 PiP `exitPictureInPicture()` 정리.
- **버그2 수정 (모바일: 다른 탭 갔다 PiP 복귀 시 재생 오류)**: `pagehide`/`beforeunload`의 `killActivePipe`가 무조건 ffmpeg/HLS를 죽이는데, 모바일은 탭 전환 시 `pagehide` 발생 → PiP 영상 소스 끊김. → `killActivePipe` 맨 앞에 `if (document.pictureInPictureElement) return;` 추가(PiP 중이면 정리 건너뜀). 일반(비PiP) 백그라운드 정리는 그대로 보존(orphan 세션 누수 방지 유지).
- **트레이드오프**: PiP 중 페이지를 완전히 닫으면 ffmpeg가 잠깐 orphan으로 남을 수 있으나, 서버측 orphan 자동 청소 로직이 백업으로 정리(드문 케이스).
- **안전성**: PiP 이벤트는 video 이벤트(미리보기마다 video 재생성 시 자동 정리, window 리스너 추가 없음). `killActivePipe` 본체(HLS/Pipe/MMS 정리)·기존 모달 정리 로직 전부 보존. 무한루프 없음. 일반/공유 양쪽 실기기 동작 확인됨(메인 PiP/버그1/버그2, 공유 PiP).

**🟢 B-4. 모바일 대용량 mp4 재생방식 선택 셀렉트 (일반재생/트랜스코딩) (2026-06-01)**

- **배경**: 모바일은 대용량 mp4(500MB 초과, H.264 등 네이티브 가능 코덱)를 자동 트랜스코딩(HLS)함 — 과거 결정(3GB+ H.264 네이티브 재생 시 모바일 메모리 부족·버벅임). 사용자가 원하면 원본 그대로(일반재생) 볼 수 있게 선택권 제공.
- **UI**: 화질 셀렉트(`#video-quality-select`) 안에 `[재생방식 ▼: 일반재생/트랜스코딩]`을 화질 셀렉트 앞에 배치 → `[재생방식][화질]`. flex 컨테이너(gap:6px, right:10px)라 화질 셀렉트와 함께 이동.
- **표시 조건** (`_showNativeBtn`, app.js): `needsTranscodeBySize && !needsTranscodeByCodec && _navExt === 'mp4'` — 모바일+500MB초과+코덱 네이티브 가능+**mp4 한정**. 코덱 미지원(HEVC 등)은 네이티브 불가라 미표시, webm/ogg 등 다른 포맷도 미표시(펜닐님 요청). media_info 실패 폴백(F2/Fb)은 코덱 불명이라 `_showNativeBtn=false`.
- **동작**: 셀렉트 change → `_forceNativePlayback`(일반재생=true/트랜스코딩=false) 설정 후 `showPreview(현재 item)` 재시작. 메인 분기에서 `_forceNative`면 `needsTranscodeBySize`를 무시(코덱 미지원은 유지)하여 네이티브 재생. `showPreview` 재시작이 기존 트랜스코딩 정리(ffmpeg/HLS)를 수행하므로 화질 change 로직(CASE 1~4)은 건드리지 않음. `_forceNativePlayback`은 1회성(메인+F2+Fb 3곳에서 사용 후 리셋).
- **셀렉트 초기값**: `_buildQualitySelectUI`의 `nativeMode` 파라미터로 결정(트랜스코딩 경로=`transcode`, 네이티브 경로=`native`). 초기값이 실제 상태와 같아야 같은 값 재선택 시 change 미발생 문제가 없음(초기값이 어긋나면 일반재생을 못 고르던 버그를 이 방식으로 해결).
- **버튼 누락 버그 수정**: 화질 UI가 2경로(네이티브 선시도→트랜스코딩 전환)로 빌드되어, 첫 호출 때 `_showNativeBtn=false`면 셀렉트를 못 만들고 재호출은 이중 빌드 방어(`select#quality-picker` 존재 시 return)에 막혀 영영 누락됨. → `_ensureQualityNativeBtn(qDiv, nativeMode)` 헬퍼로 분리, 이중 방어 return 전과 정상 경로 양쪽에서 호출하여 어느 경로든 보장.
- **디자인**: 모바일에서 화질 셀렉트와 동일 스타일(11px, `border-radius:14px` 알약형, 동일 배경/테두리). 모바일 화질 셀렉트 앞 `🎬` `::before` 아이콘 제거(셀렉트 2개라 거슬림). **PC는 무변경**(PC는 `🎬 화질:` label 유지).
- **비-mp4 잔재 제거** (`_showPreviewImpl` 시작): 매 영상마다 `_navExtInit !== 'mp4'`일 때만 `_showNativeBtn=false` 리셋. 무조건 false로 하면 화질 변경 재시작 시 media_info 미재호출로 메인 분기를 안 타 셀렉트가 사라지는 버그가 생기므로, mp4가 아닐 때만 정리(mp4는 메인 분기/이전값 유지).
- **재생방식 vs 화질 독립 동작 (nativeMode 재정의)**: mp4 대용량은 네이티브 선시도(`nativeMode=true`)가 먼저 화질 change 핸들러를 만들어 `nativeMode`가 true로 고정됨 → 트랜스코딩 중 화질 '원본' 클릭이 CASE 3(네이티브 전환)으로 잘못 빠짐. → change 핸들러에서 `nativeMode`를 **재생방식 셀렉트의 현재 값**으로 재정의(`_pbModeSel.value === 'native'`, 셀렉트 없으면 `_origNativeMode`). 결과: 트랜스코딩 중 원본=트랜스코딩 원본화질(CASE 4 유지), 일반재생=네이티브(CASE 3). 파라미터명 `nativeMode`→`_origNativeMode`로 변경.
- **CASE 3(원본→네이티브) 정리 누락 4종 추가** (ffmpeg 누수·배지·상태 불일치 수정): ①`_pipeSid` ffmpeg kill — `_startTranscode`/`_preClean`엔 있으나 CASE 3에 누락되어 원본 클릭마다 ffmpeg orphan 누적(누수 주범). ②`window._transcodeAbort.abort()` — 직전 화질 트랜스코딩의 비동기 콜백이 늦게 도착해 배지를 '⚡ HLS 스트리밍'으로 덮어쓰는 경쟁 조건 차단(배지 쓰는 콜백 6+곳이 `aborted()` 미체크가 근본). ③배지 `video-stream-badge native` + `▶ 일반 재생` 명시 갱신(abort는 미래 콜백만 막으므로). ④재생방식 셀렉트 `value='native'` 동기화.
- **셀렉트 위치(top) 수정**: 일반재생→트랜스코딩 동적 전환 시 오디오 셀렉트 요소가 없어(`audioSelectHtml=''`) 기존 `:has(.video-audio-select:empty)`/`.native` 조건이 다 깨져 기본 `top:44px`로 내려감 → `.video-player-wrap:not(:has(.video-audio-select)) .video-quality-select { top:6px }` 추가(오디오 셀렉트 요소 자체가 없으면 top:6px). 멀티오디오(요소 채워짐)만 top:44px 유지.
- **알려진 한계/회귀 주의**: 검토 중 "일반재생 중 720p 선택 시 셀렉트 표시 어긋남"을 의심해 `_ensure` 셀렉트값을 실제 상태(`data-transcode-base`) 기반으로 바꿨으나 동작이 틀어져 **원복**함(CASE 2가 setAttribute 후 _startTranscode라 기존 `nativeMode` 기반으로도 정상). 셀렉트 표시값은 `nativeMode`(원래 방식) 유지.
- **500MB 이하 mp4로 확장 (2026-06-02)**: 작은 mp4가 일부 기기에서 버벅일 때 트랜스코딩으로 볼 수 있도록 크기 제한 제거. ①`_showNativeBtn` 조건에서 `needsTranscodeBySize` 제거 → `isMobileDevice && !needsTranscodeByCodec && _navExt === 'mp4'`(크기 무관). ②네이티브 재생 블록은 early-return 경로라 `_showNativeBtn`이 설정 안 되던 문제 → 네이티브 블록 return 직전에도 `_showNativeBtn` 설정 + `_ensureQualityNativeBtn(qDiv, true)` 호출 추가(화질 셀렉트 빌드 setTimeout과 media_info 응답 순서 무관하게 셀렉트 보장). ③500MB 이하는 `shouldTranscode=false`라 '트랜스코딩' 선택해도 트랜스코딩 안 되던 문제 → **`_forceTranscode` 플래그 신설**: 재생방식 '트랜스코딩' 선택 시 `_forceTranscode=true` → `shouldTranscode = needsTranscodeByCodec || needsTranscodeBySize || _forceTranscode`(1회성, 메인분기+폴백 2곳 리셋). 결과: 모든 모바일 mp4에서 일반재생↔트랜스코딩 자유 전환(크기 무관). 버벅임은 iOS(특정 mp4+모바일 디코딩 성능)에서 주로 발생, 트랜스코딩 전환으로 해소 확인(PC는 미발생).
- **신형 iPad 판정 보강 (2026-06-02)**: `isMobileDevice`가 UA만 검사해 신형 iPad(iPadOS 13+, UA가 'Macintosh'로 위장)를 PC로 오판 → 재생방식 셀렉트/트랜스코딩이 iPad에서 안 떴음. `|| (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)` 추가(코드 내 기존 iPadOS 감지 패턴 356/397/635행과 동일). 신형 iPad도 실제 iOS라 폰과 동일 동작.
- **PC 확장 (2026-06-02) — 일반(app.js)**: PC도 동영상 버퍼링/렉이 발생해 PC에도 재생방식 셀렉트 추가. `_showNativeBtn` 표시 조건 2곳에서 `isMobileDevice` 제거 → PC도 mp4면 셀렉트 표시(34864 `_navExtN==='mp4'`, 34936 `!needsTranscodeByCodec && _navExt==='mp4'`). `isMobileDevice`는 다른 5곳(`needsTranscodeBySize` 등) 유지 → PC 자동 트랜스코딩은 안 함(셀렉트로 선택 시에만 `_forceTranscode`로 동작). 모바일 무영향.
- **PC 확장 (2026-06-02) — 공유(share.php)**: PC는 공유에서 네이티브 분기(UA 비모바일)로 가서 재생방식 셀렉트가 없었음. ①네이티브 분기 화질 wrap에 재생방식 셀렉트 UI 추가(기본 'native'). ②PHP에 `force_transcode=1` 파라미터 처리(트랜스코딩 강제 렌더). ③네이티브 분기 재생방식 핸들러(`_pbModeNat`): '트랜스코딩' 선택 시 `force_transcode=1`로 새로고침 → 트랜스코딩 분기 재사용(기존 CASE 2 안 건드림, reload 방식, 5MB 세그먼트라 처음부터가 구조에 맞음). PC+모바일 500MB 이하 공통.
- **공유 일반재생→화질변경 배지/멀티오디오 누락 수정 (2026-06-02, 콘솔로 확정)**: force_transcode로 트랜스코딩 분기 렌더 후 '일반재생' 선택(`_shareNativeMode=true`) → 화질 변경 시 **트랜스코딩 분기 2667 핸들러의 `_shareNativeMode` 경로**를 타는데, 트랜스코딩 시작(MANIFEST_PARSED) 후 인코더 정보 배지 업데이트 + 멀티오디오 처리가 **누락**되어 배지가 "⚡ 실시간 변환 재생"(임시)에서 안 바뀌고 오디오 셀렉트도 안 떴음. → 2667 핸들러 MANIFEST_PARSED에 `_fetchShareInfo()` 호출 추가(같은 트랜스코딩 분기라 호출 가능, 배지+멀티오디오 둘 다 처리). 임시 배지 `_badge2`는 네이티브 분기 배지에 `id` 없어 `getElementById`→`querySelector('.stream-badge')` 수정. 진단 경위: 처음 네이티브 분기(2902/CASE2)를 의심해 거기 인코더 배지 추가했으나, 콘솔 로그(`force_transcode=1`+`[QCHG]`)로 실제는 트랜스코딩 분기(2667)임을 확정해 정정. (네이티브 분기 2902의 인코더 배지 코드도 PC mp4 네이티브 경로 대비 유지.)

**🟢 B-5. iOS 음악 비주얼라이저 차단 재확인 (iOS 26.5 실기기) (2026-06-02)**

- **현상**: 음악 플레이어 비주얼라이저는 iOS에서 비활성화되어 있음(`_initVisualizer`에서 `if (this._isIOS)` 시 `_visFailed=true` + `display:none` + return). 안드로이드/PC는 정상 작동.
- **이유**: 비주얼라이저는 `AudioContext` + `createMediaElementSource()`로 오디오 신호를 분석해야 하는데, iOS(WebKit)에서 이 연결 시 백그라운드/잠금화면 재생이 끊기는 제약이 있음. 백그라운드 재생 + 잠금화면 MediaSession이 비주얼보다 중요하여 iOS만 차단.
- **iOS 26.5 재확인 결과**: 차단을 임시 해제한 테스트 빌드로 직접 검증 → 잠금화면 시 음악 끊김 + 스크롤 무반응 재현 확인. iOS 26.5에서도 WebKit 제약 미해결 → **차단 유지가 정답**. 코드 주석에도 26.5 재확인 메모 추가.
- **안드로이드**: 엔진(Blink)이 달라 iOS WebKit 제약과 무관, 동일 문제 접수 이력 없음 → 현 상태(비주얼라이저 작동) 유지. (안드로이드 실기기 부재로 직접 테스트는 못 했으나 문제 보고 없음.)

**🟢 B-6. 동영상 변환 — 느낌표(!) 포함 파일명 실패 수정 (2026-06-02)**

- **현상**: H264/MP4 변환 시 파일명에 `!`(느낌표) 포함된 파일만 실패. 원본 재생·웹하드는 정상. ffmpeg stderr에 `Error opening input: Illegal byte sequence`.
- **진짜 원인 (디버그 로그로 확정)**: PHP `escapeshellarg()`가 **Windows에서 보안상 `!`와 `%`를 공백으로 치환**하는 동작 때문. ffmpeg에 전달된 경로의 `!`가 공백으로 바뀌어(예: "발산합니다! "→"발산합니다  ") 실제 파일과 다른 경로가 되어 입력 파일을 못 엶. (처음 `.bat` 지연확장 `!` 문제로 오진단해 `setlocal`을 넣었으나 무관 — 진짜는 escapeshellarg 단계. `data/convert_debug.log` 추가로 cmd 실제 내용 확인해 확정.)
- **수정**: `convertToH264Mp4`의 인자 이스케이프를 OS 분기 헬퍼 `$cvArg`로 교체. Windows는 escapeshellarg 대신 큰따옴표로 직접 감쌈 → `!`/`%` 보존. Linux는 기존 escapeshellarg 유지. 적용: probe(코덱분석)/buildConvCmd(변환)/verify(H264검증) 3곳.
- **진단 로그**: `data/convert_debug.log` — **실패 시에만 기록(가볍게)**. 조기실패(권한/원격/파일없음/ffmpeg/출력경로) + 실행실패 시 `FAIL 실행결과 code·outPath·size` + 실제 `cmd` + `stderr_tail`. 성공 시엔 로그 안 남김(파일 안 커짐). SSE error에 detail(stderr) 포함→클라 콘솔 `[변환 실패 상세]`.
- **결과**: `!` 포함 파일명 변환 **성공 확인**(실기기, code=0/okOutput=1, `Illegal byte sequence` 0건). 과거 myComix `archive_handler.php`의 Windows 큰따옴표 `escapeArg`와 동일 검증된 접근.
- **남은 가능성**: `%` 파일명도 큰따옴표로 해결되나 `.bat` 내 `%`는 별도 이스케이프 필요할 수 있어 추후 확인(보안 아닌 기능 한계). 분할압축/7zip(`createSplitZip`/`extractSplitZip`/`extract7zip`)도 동일 escapeshellarg 패턴이라 `!`/`%` 파일에서 같은 문제 잠재 — 변환만 수정함(보고 시 동일 `$cvArg` 적용 예정).

**🟢 B-6.5. 동영상 변환 개선 — 배속 표기/변환창/손상 .ts/스크롤 보존 (2026-06-03)**

- **배속 누락 (진단 로그로 확정)**: h264/mp4 변환 진행률의 ffmpeg `speed=` 배속이 어떤 영상은 나오고 어떤 건 안 나옴(같은 확장자도 들쭉날쭉). 처음 "타이밍 레이스"로 추정해 `$lastSpeed` 캐싱 추가했으나 여전히 누락 → `data/convert_debug.log`에 raw speed 1회 기록하는 진단으로 실측한 결과 **빠른 변환은 ffmpeg가 지수 표기 출력**(`speed=2.9e+03x` = 2900배)이고, 기존 패턴 `/speed=\s*([\d.]+)x/`가 `e`/`+`를 못 잡아 빈 값이었음(느린 재인코딩은 `2.5x` 일반표기라 정상이었던 것). → 패턴을 `/speed=\s*([\d.]+(?:e[+-]?\d+)?)x/i`로 확장 + 지수 표기면 `round((float))`로 일반 숫자 변환(`2.9e+03`→`2900`, 그대로 표시하면 "2.9e+03x"로 보임). `$lastSpeed` 캐싱은 N/A 순간 누락 보완용으로 유지. 적용: api/FileManager.php convertToH264Mp4 진행률 루프(speed 파싱 1곳만, 다른 진행률 루프 무영향). 클라이언트(app.js `d.speed + 'x'`) 무변경.
- **변환창 너비 고정**: 변환 진행률 모달이 `min-width:400px; max-width:90%`인데 파일명 span에 줄바꿈이 없어 긴 파일명이 한 줄로 늘어나 박스가 90%까지 커졌음. → 박스 `width:550px` 고정(+`box-sizing:border-box`), 파일명 div `word-break:break-all; overflow-wrap:anywhere`로 줄바꿈. 긴 파일명이어도 박스 너비 일정. (압축/해제 모달은 별개 요소라 미변경.)
- **손상 .ts 변환 실패 해결 (트랜스코딩은 되는데 변환만 실패 — 콘솔/로그로 단계 확정)**: 일부 .ts 변환 시 `code=-1094995529 Invalid data / Error submitting a packet to the muxer / corrupt input packet in stream 1` 실패. **재생(트랜스코딩)은 되는데 변환(저장)만 실패**가 단서. 단계적 진단: ①"소스 손상이라 어쩔 수 없다"(부족) → ②트랜스코딩과 입력 옵션 맞춤(`-analyzeduration 2000000 -probesize 2000000` + `-fflags +genpts+igndts+fastseek` + `-sn` 자막 무시) 했으나 여전히 실패(로그 `subtitle:0KiB`로 -sn 효과 확인됐으나 muxer 에러 지속) → ③`-fflags +discardcorrupt`(손상 패킷 버림) 추가했으나 여전히 실패 → ④**진짜 원인 확정**: 이 .ts는 H264+AAC라 변환이 **비디오·오디오 모두 copy**(`$isCopy = videoCodec==='h264'`, `$aArgs`가 aac면 `-c:a copy`)인데, 손상 패킷이 copy로 그대로 MP4 muxer에 전달되어 거부됨. **트랜스코딩은 항상 재인코딩**(libx264/HW + `-c:a aac`)이라 디코드→재인코드로 손상 흡수 → 됐던 것. → **해결: copy 실패 시 재인코딩(libx264+aac) 1회 자동 폴백 추가**(`$triedReencode` 플래그). buildConvCmd/runConv에 오디오 오버라이드 파라미터 추가(폴백 시 비디오+오디오 모두 재인코딩). 흐름: copy 시도(빠름, discardcorrupt 포함) → 실패 시 "손상 감지 — 재인코딩으로 재시도..." → 재인코딩(트랜스코딩과 동일 방식이라 성공). 멀쩡한 파일은 copy로 끝나 폴백 미발생(무영향). 기존 HW→SW 폴백 로직과 별개 조건. 재인코딩 출력은 libx264=h264라 기존 출력 검증(okH264) 통과. *진단 경위: 소스손상→옵션맞춤→discardcorrupt→copy vs 재인코딩까지 단계적으로 좁혔고, "트랜스코딩은 되는데"/"여전히 실패" 피드백이 매 단계 방향을 잡아줌.*
- **변환 완료 후 스크롤 위치 보존**: 변환 완료 시 `loadFiles()`가 폴더를 재로드하는데, 이 함수는 (폴더 이동 대비) 항상 스크롤을 최상위로 리셋 → 변환은 같은 폴더 재로드라 맨 위로 튀었음. → 변환 완료 부분(convertToH264 finally)에서만 재로드 전 `#file-list` scrollTop 저장 → `await loadFiles()` 후 `requestAnimationFrame`으로 복원. loadFiles 함수 자체는 범용이라 미변경, 폴더 이동 등 다른 경로 무영향. (원본 삭제 옵션 시 목록 항목 1개 감소로 px 위치 미세 차이 가능하나 같은 폴더라 거의 동일 위치 유지.)
- **변환 진행 중 탭 전환 시 스크롤 튐 — 자동 새로고침 스킵 (원인 정정)**: 여러 개 변환(5분+ 소요) 중 브라우저 다른 탭 갔다 오면 목록이 맨 위로 튐. 처음 `_lastRefresh=0` 초기값 탓으로 추정했으나 **오진단**(4762에서 이미 `Date.now()`로 초기화됨). 진짜 원인: visibility 자동 새로고침(5분 경과 시 `loadFiles(false)`)이 변환 중 발동 → 스크롤 보존(저장→복원) 방식이 loadFiles 렌더 타이밍을 못 맞춰 가끔 맨 위. → 근본 해결: **변환 진행 중(`_convBatchRunning`)에는 visibility 자동 새로고침 자체를 스킵**(`if (elapsed > threshold && !this._convBatchRunning)`). 목록을 안 그리니 스크롤이 움직일 일 없음(타이밍 비의존). 변환 완료 후 finally에서 어차피 갱신. (visibility 자동 새로고침 시 스크롤 보존 코드는 변환 외 상황 위해 유지.)
- **변환 진단 로그 정확화 (기능 무관)**: copy→재인코딩 폴백 시 실패 진단 로그(`data/convert_debug.log`)가 부정확했음 — mode가 `$isCopy` 우선 판정이라 재인코딩 폴백인데 'copy'로 표시, cmd 로그도 오디오 오버라이드 미반영(copy로 표시). → mode 판정을 `$curVArgs === $swVArgs` 우선으로 바꿔 `libx264(reencode-fallback)` 정확 표시, cmd 로그에 `$triedReencode`면 재인코딩 오디오 인자 반영. 오디오 문자열은 `$reencFallbackAArgs` 변수로 빼서 폴백 블록 + 진단 로그 공유(중복 방지). 실제 ffmpeg 실행은 원래 정확했고 로그 표시만 정확화(기능 영향 없음).
- **변환 ffmpeg 자원 정리 + 취소 버튼 + 미완성 파일 처리 (2026-06-03)**:
  - **문제**: 변환 ffmpeg는 `start /b` 백그라운드 독립 프로세스라, 변환 중 새로고침/탭종료/브라우저 닫기로 중단해도 ffmpeg가 안 죽고 CPU/GPU 자원을 끝까지 점유(트랜스코딩은 proc_terminate 있었으나 변환은 kill 메커니즘 없었음). 또 미완성 출력(.mp4)이 목록에 남음.
  - **ffmpeg kill**: ffmpeg 커맨드에 `-metadata comment=convsid_{고유sid}` 마커 심고(트랜스코딩 pipesid와 동일 방식), 클라이언트 끊김(`connection_aborted`) 감지 시 `wmic`로 그 마커의 ffmpeg.exe 찾아 `taskkill`(Linux는 pkill). SSE 루프에 keepalive(`: ka` 주석) 매 0.5초 출력 → connection_aborted 빠른 감지. clientGone/timeout 시 kill + 미완성 정리 + 중단.
  - **취소 버튼**: 변환 모달에 취소 버튼 추가(안내문도 "취소 불가" → "시간 걸릴 수 있음"). 취소 시 `es.close()` → 서버 connection_aborted 감지 → ffmpeg kill. 여러 개 변환 중 취소 시 나머지도 중단(break).
  - **dotfile 임시 출력 (미완성 파일 목록 노출 방지)**: ffmpeg를 최종 `.mp4`가 아닌 **`.{이름}.mp4.converting` dotfile**에 쓰고, 완료+H264검증 후 `rename`으로 최종 `.mp4`로 전환. 목록 조회(listFiles 161행)가 dotfile('.'시작)을 자동 제외하므로 변환 중/중단된 미완성 파일은 목록에 안 보임(새로고침/취소/브라우저닫힘 모두). rename은 같은 폴더라 빠름. **주의: `.converting` 확장자라 ffmpeg가 출력 포맷 추론 못 함 → `-f mp4` 명시 필수**(이거 빠뜨려서 변환 깨졌다가 수정함). 중단/실패/timeout 시 dotfile 정리(잠금 대비 최대 5회 재시도).
  - **임시파일 정리**: 완료(done)/실패(error)/중단(clientGone)/timeout 모든 경로에서 progressFile/resultFile/stderrLog 정리.
  - **HLS는 무관**: 트랜스코딩(HLS)은 기존에 sendBeacon stop + 서버 taskkill(pid.txt)로 브라우저 닫을 때 이미 정리됨("30분 lazy"는 stop 못 받은 예외의 백업일 뿐 — 초기 오진단 정정).
  - **동시 변환 충돌 방지 + 잔재 정리 (엣지 보강)**: tmpOut 이름에 convSid 포함(`.{이름}.mp4.{convSid}.converting`) — 다른 탭/사용자가 동시에 같은 파일을 변환해도 임시파일이 안 겹침. convSid 고유화로 이전의 고정-이름 덮어쓰기 정리가 무효가 되므로, 변환 시작 시 같은 폴더의 **1시간+ 오래된 .converting 잔재**를 scandir로 정리(비정상 PHP 종료 잔재 누적 방지 — 진행 중 변환은 ffmpeg가 계속 써서 mtime 최근이라 보존).

**🟢 B-7. 동영상 변환/압축 .bat 지연확장 비활성화 (2026-06-02)**

- `convertToH264Mp4`/`createSplitZip`/`extractSplitZip`/`extract7zip`의 Windows `.bat`에 `setlocal disabledelayedexpansion` 추가(무해한 방어적 추가). 단 B-6 실제 원인은 escapeshellarg였음.

**🟢 B-8. 공유 페이지 재생방식 셀렉트 적용 (2026-06-02)**

- **배경**: 본체(app.js)에 추가한 재생방식 셀렉트(일반재생/트랜스코딩)를 공유 페이지(share.php)에도 적용. 공유는 본체와 완전히 독립 구조(PHP가 `$needsTranscode`로 HTML을 미리 분기 렌더링 + 자체 인라인 JS)라 단순 복사 불가, 공유 구조에 맞춰 단계적 구현.
- **1단계 (트랜스코딩 → 일반재생)**: 트랜스코딩 영상(모바일 500MB+)의 화질 셀렉트 wrap에 재생방식 셀렉트 추가. '일반재생' 선택 시 HLS 정리(기존 quality change 패턴 재사용) + 네이티브 `src` 설정(`data-transcode-url`에서 `&transcode=1` 제거) + 재생 + 배지를 네이티브로(코덱/해상도/용량 포함, 원본 배지와 동일 형식). `loaded`가 이미 true라 네이티브 play 시 `startTranscode` 재호출 안 됨(기존 메커니즘 활용). '트랜스코딩' 재선택은 `location.reload()`로 원복.
- **2단계 (일반재생 중 화질 변경 → 트랜스코딩)**: 본체 CASE 2/3 로직 적용. 1단계 네이티브 전환 시 `player._shareNativeMode=true` 설정. 화질 change 핸들러 맨 앞에서 네이티브 모드 분기 — '원본'이면 네이티브 유지(return), 비-original이면 `_shareNativeMode=false` + 재생방식 셀렉트 'transcode' 동기화 + 배지 '⚡ 실시간 변환 재생' + dataset.hlsUrl의 quality 리셋 후 아래 기존 HLS 시작 로직으로 진행. 기존 '트랜스코딩 중 화질 변경'은 미수정(네이티브 분기만 앞에 추가).
- **셀렉트 디자인**: 모바일 셀렉트(재생방식/화질/오디오)를 본체와 동일하게 PC처럼 각진 디자인(`border-radius:4px`)으로 통일(이전 알약형 14px에서 변경). 본체 style.css 3곳(`audio-track-select`/`quality-select`/`playback-mode-select`) + 공유 share.php 모두. 공유 화질 라벨 '🎬 화질:'는 모바일에서 `.share-q-label span` `display:none`으로 숨김(본체 모바일과 통일), PC는 라벨 유지.
- **실기기 확인**: 모바일 공유에서 트랜스코딩↔일반재생 전환, 일반재생 중 화질 변경→트랜스코딩 전환, 배지 표시 모두 정상 확인. 본체와 동등 동작.
- **검토 완료 — 아래 3건은 의도적으로 현 상태 유지가 정답 (2026-06-02 펜닐님 결정)**:
  1. **음악 비주얼라이저 `FSAudioPlayer` 코드가 app.js(본체)와 fs-audio-player.js(공유) 2개로 분리** — '중복 실수'가 아니라 일반/공유가 각각 쓰는 의도된 구조(공유는 본체 통째로 안 불러오고 가벼운 별도 파일 사용). 둘 다 동일한 iOS 비주얼라이저 차단 로직 보유 확인. → 유지. (단, 음악 플레이어 비주얼라이저 수정 시 두 파일 모두 손봐야 일반/공유 동작 일치함을 기억할 것.)
  2. **처음부터 네이티브로 시작하는 영상(500MB 이하 등)의 공유 재생방식 셀렉트 미적용** — 공유는 트랜스코딩 영상(모바일 500MB+) 위주로 적용. 네이티브 시작 영상은 기존 `_shareNativeQualitySetup`(화질→트랜스코딩)이 담당. 필요 보고 없어 현 상태 유지.
  3. **재생방식 '트랜스코딩' 직접 재선택 = `location.reload()`(처음부터 재생)** — 이어서 재생(seek 유지)으로 개선 안 함. 트랜스코딩이 5MB HLS 세그먼트 방식이라 처음부터 시작이 구조에 맞고, seek 지점부터 변환 세션 맞추는 건 복잡·불안정. reload가 오히려 깔끔하고 안정적 → **개선 안 하는 게 정답**(펜닐님 결정).

**🟢 B-9. 음악 플레이어 재생목록 검색 (일반+공유) (2026-06-02)**

- **방식**: 검색어와 일치하는 곡으로 **스크롤 이동 + 하이라이트**(필터링 아님 — 목록/재생 인덱스 그대로 유지). 가상 스크롤 핵심(`_vsRender`) 안 건드리고 `scrollTop`만 변경하는 안전한 방식(펜닐님 선택).
- **대상**: 파일명(`track.name`)만. 일치 시 해당 곡 위치로 스크롤(가운데 정렬) + 노란 하이라이트(`fap-pl-search-hit`).
- **순회**: Enter로 다음 일치 곡 순환(마지막→처음). `input` 이벤트(첫 일치) / Enter(다음 순회) 분리, 캐시된 `_plSearchMatches`로 pos 유지.
- **한글 IME 버그 수정 (로그로 확정)**: `keydown`에선 한글 조합 중 Enter가 `key='Process'`/`keyCode=229`로 와서 감지 불가 → **`keyup`으로 변경**(조합 확정 후 keyup에서 정상 Enter 감지). `isComposing`/229 가드 추가.
- **적용 범위**: 일반(app.js + style.css) + 공유(fs-audio-player.js + fs-audio-player.css) 양쪽 동일. FSAudioPlayer가 2개 파일로 분리돼 있어 양쪽 다 적용(B-8 메모 참조). 헤더 검색창 UI(`fap-playlist-search`) + `_plSearchHighlight` 헬퍼 + `data-index`/`fap-pl-item` 기존 요소 재사용.
- **실기기 확인**: 일반 검색/스크롤/하이라이트/한글·영문 Enter 순회 정상. 기존 재생목록 스크롤/클릭 재생 회귀 없음 확인.
- **디자인 보강 (2026-06-02)**: 검색창에 돋보기 SVG 아이콘(왼쪽, `background-image data:svg`) 추가 + 높이 28px 고정(`box-sizing:border-box`, 위아래 커지는 문제 해결). **흰 배경 스킨에서 검색창 안 보임 수정** — 검색창 기본이 흰 글자+투명 흰 배경이라 밝은 스킨에서 묻힘 → 흰 배경 스킨 2개(`ap-fixed`, `soundcloud`)에 어두운 글자(#333)/테두리(#ddd)/회색 아이콘 오버라이드. (pixel 스킨도 밝은 연두 배경이나 펜닐님 결정으로 미적용 — 2개만.) 양쪽(style.css + fs-audio-player.css) 일치. 참고: 개발 중 CSS 변경은 APP_VERSION 미변경 시 브라우저 캐시로 즉시 반영 안 됨 → 강력 새로고침(Ctrl+Shift+R) 필요.
- **모바일 일반 검색창 아이콘+글자 겹침 수정 (2026-06-02)**: 메인 페이지의 전역 스타일이 검색창 `padding-left`(26→12px)·`font-size`(11→13px)를 덮어 아이콘 위에 글자가 겹침(콘솔 computed로 확정, 공유는 그 전역 스타일 없어 정상이었음). → `.fap-playlist-search`의 `padding`/`font-size`에 `!important`로 의도값 보호(검색창에만 적용, 안전).
- **ap-fixed 재생목록 썸네일 정렬 수정 (2026-06-02)**: ap-fixed만 `.fap-pl-item` `height:60px` + 썸네일 `40px`로 달라, 가상 스크롤(`_VS_ITEM_H=48px` 간격 배치)과 어긋나 썸네일이 줄 안에서 세로 정렬이 틀어지고 아이템이 겹쳤음(다른 스킨은 48px라 정상). → ap-fixed도 `height:48px`/썸네일 `36px`로 다른 스킨과 통일(방법 A, 펜닐님 결정). `* {box-sizing:border-box}` 전역이라 48px 안에 padding 흡수됨. 가상 스크롤 핵심 미수정. 일반/공유·PC/모바일 공통 문제였고 양쪽 적용. (ap-fixed 검색창 우측 여백 margin-right은 PC 스킨버튼 겹침 대비 140→110px 시도했으나 최종 불필요로 제거 — 펜닐님 실기기 확인.)

**🟢 B-10. PC 동영상 목록 패널 검색 (2026-06-03)**

- **배경**: PC 동영상 폴더 재생 시 사이드 패널(`fs-vp-panel`, 영상 2개 이상일 때)에 음악 재생목록 검색(B-9)과 같은 검색 기능 추가 요청.
- **방식**: B-9와 동일 — **스크롤 이동 + 하이라이트**(필터링 아님, 목록 유지), **파일명만**(`.fs-vp-name-inner` 텍스트) 검색. Enter로 다음 일치 순환, 한글 IME는 `keyup`+`isComposing`/229 가드(B-9와 동일 이유).
- **음악과 구조 차이**: 음악 재생목록은 가상 스크롤(`_vsRender`, scrollTop 계산)이지만, 동영상 패널은 **일반 DOM 목록(전부 렌더)**이라 `scrollIntoView({block:'center', behavior:'smooth'})` 사용(더 간단·부드러움). 동작/UX는 동일.
- **UI**: 헤더(`fs-vp-header`)가 아이콘+제목+카운트+버튼들로 꽉 차서, 검색창은 **헤더 아래 별도 줄**(`fs-vp-search`). 어두운 패널 테마에 맞춰 흰 글자+돋보기 아이콘+높이 30px. 일치 항목 노란 하이라이트(`fs-vp-search-hit`).
- **변수 독립**: 음악(`_plSearchMatches`/`_plSearchPos`)과 별개로 `_vpSearchMatches`/`_vpSearchPos` 사용 — 충돌 없음. 기존 패널 기능(클릭 재생, 자동 다음, 토글, 스크롤 위치 저장) 미변경, 검색 핸들러만 `_setupVideoPlaylistPanel`에 추가. PC 한정(패널 자체가 PC 폴더 재생용).

**🟢 B-11. 그리드 모드 폴더 떨림(부들부들) — 스크롤바 토글 진동 (2026-06-03)**

- **증상**: 그리드 모드에서 가만히 있어도 폴더가 가끔 부들부들 떨림(아무 폴더나, 항목 적어도 — 폴더 4개 폴더에서도). 펜닐님이 "탐색기 스크롤바가 진동" 관찰.
- **원인**: `.file-list { overflow-y: auto }` — 콘텐츠 높이가 뷰포트 경계에 걸치면 세로 스크롤바가 생김↔사라짐 반복 → 가로 폭 변동 → 그리드 `grid-template-columns: repeat(auto-fill, minmax(140px, 1fr))`의 `1fr` 재계산 → 아이템 높이 미세 변동 → 콘텐츠 높이 변동 → 스크롤바 토글 반복(무한 진동). 항목이 적어도 1행↔2행 재배치로 경계 걸치면 발생.
- **해결**: `.file-list`에 `scrollbar-gutter: stable` 추가 — 스크롤바 공간을 항상 확보해 스크롤바 유무와 무관하게 가로 폭 고정 → 진동 차단. `overflow-y: auto`(스크롤 동작)는 유지. 모바일은 `body.is-mobile .file-list { overflow-y: visible }`(스크롤 컨테이너 아님)이라 scrollbar-gutter 무효 → PC만 적용, 모바일 무영향.
- **트레이드오프**: PC에서 스크롤 불필요한(항목 적은) 폴더도 우측에 스크롤바 너비(약 8px) 여백 상시 확보. 미미함. (대안: `overflow-y: scroll`은 스크롤바 상시 표시 — scrollbar-gutter가 더 깔끔.)
- **검증 한계**: 증상 기반 추정(어쩌다 떨려 재현 어려움). scrollbar-gutter:stable은 스크롤바 토글이 원인이면 무조건 차단하는 방식이라 적용해두고 일상 사용하며 관찰 권장. 여전히 떨면 F12로 진동 요소 실측 예정.

**🟢 B-12. PC 동영상 재생목록 전체/연관 모드 + 헤더 재구성 (2026-06-03)**

- **배경**: PC 동영상 폴더 사이드 패널(`fs-vp-panel`)에 PotPlayer의 "비슷한 파일 / 모든 파일" 모드처럼, 폴더 전체 동영상(`전체`)과 재생 중 파일의 시리즈만(`연관`) 전환하는 토글 요청.
- **모드 토글 UI**: 헤더에 토글 2개를 세로 스택(`fs-vp-toggles`). 자동재생(`fs-vp-autonext`, 수동↔자동)과 모드(`fs-vp-mode`, 전체↔연관, 초록 #4caf50) 동일 스타일(폭 52px, 슬라이더). 각 토글 좌측에 라벨(`fs-vp-toggle-label`: "다음재생"/"범위", min-width 44px 우측정렬).
- **모드 영구 저장**: 마지막 사용 모드(전체/연관)를 `localStorage 'fs_vp_mode'`에 저장(자동재생 `fs_vp_auto_next`와 동일 패턴, 기본 'all'). 패널 생성 시 복원 → 버튼 상태 반영 → related면 즉시 필터 적용. 새로고침·다른 영상 진입·재방문 후에도 마지막 모드 유지(매번 다시 켤 필요 없음). 토글 클릭 시 `localStorage.setItem`으로 즉시 저장.
- **연관 판정 로직** (`_vpSeriesPattern` + `_vpIsRelated`, 2단계 — PotPlayer 동작에 맞춤):
  - (1) **숫자→# 패턴 일치**: 파일명의 연속 숫자묶음을 `#`로 치환해 패턴이 같으면 시리즈 (한자리/두자리 에피소드 번호 섞여도 OK. 예: `...-1화` vs `...-10화` 둘 다 `...-#화`).
  - (2) **공통 접두사 분석**: 공통 시작부분 다음이 ① 양쪽 다 숫자(번호 차이) 또는 ② 공통이 4자 이상이고 양쪽이 구분자/끝으로 이어지면 시리즈. **단, 공통접두사가 닫는괄호 `]` `)`로 끝나면**(릴그룹/태그만 공통, 예 `[XXX] A편` vs `[XXX] B편`) **다른 작품으로 간주해 제외** — 같은 릴그룹의 다른 시리즈가 잘못 묶이는 것 방지.
  - **진화 경위(오진단 정정 반복)**: 처음 "숫자+구분자 모두 제거한 뼈대"→릴그룹/화질 메타가 섞여 시리즈가 쪼개짐 / "첫 숫자 앞까지"→`M`+숫자로 시작하는 파일에서 키 1글자로 깨짐 / "마지막 숫자 앞까지"→뒤에 날짜·화질 메타 많으면 깨짐 / "숫자→# 패턴"→한자리/두자리 섞이면 일부 빠짐 해결했으나 부제가 다른 긴 파일명은 패턴 달라 안 묶임 / "공통접두사 비율 50%"→97자 파일에서 공통 26자(27%)라 걸러짐 / "공통 단어로 묶기"→릴그룹만 같은 다른 작품까지 과도하게 묶임. **최종: 숫자패턴 + 공통접두사 다음글자 판정(릴그룹 태그 배제)**. 각 단계마다 콘솔 진단로그로 실제 파일명/패턴 확정 후 수정(추측 금지).
  - **정규화 추가 (`_vpNorm`)**: 비교 전에 파일명을 확장자 제거 + 모든 구분자/특수문자(공백·`-`·`_`·`.`·`+`·괄호 등) 제거해 순수 문자+숫자만 남김. 같은 시리즈인데 한 파일만 띄어쓰기가 다른 경우(제목 붙임 vs 띄움)에 글자단위 공통접두사가 깨져 대부분 누락되던 회귀를 해결 — PotPlayer가 띄어쓰기/구분자를 무시하고 묶는 동작과 일치. 패턴·접두사 비교 모두 정규화 문자열 기준이고, 릴그룹 태그 배제만 원본 기준으로 판정(괄호 정보 필요).
  - **대량 검증 방법(회귀 방지)**: 실제 시리즈를 PotPlayer 재생목록(`.dpl`)으로 내보내 "팟플이 실제로 묶은 목록"을 기준 정답으로 삼고, app.js의 `_vpIsRelated`를 그 파일명들에 돌려 일치율을 자동 대조 + 기존 시리즈 케이스 회귀까지 한 번에 확인하는 방식 확립(눈으로 일일이 비교 불필요). 한 시리즈 12개 중 1개만 매칭되던 회귀를 이 방법으로 즉시 발견·수정함. (`.dpl`/파일목록의 실제 파일명은 검증용으로만 사용, 코드·문서엔 미기록.)
  - **(3) 핵심 단어 교집합 보조 (`_vpKeyTokens`)**: 번호가 **맨 앞에 붙은** 형식(`01 제목 - 부제 …`, `02 제목 - 다른부제 …`)은 공통 접두사가 앞 번호에서 끊기고(`0`만 공통) 중간 부제도 달라 (1)패턴·(2)접두사로 못 잡음. → (1)(2)로 비연관일 때만 작동하는 보조 조건으로, 메타 토큰(eng/subs/1080p/연도/릴그룹명 등 STOP 목록) 제외한 핵심 단어가 **2개 이상 AND 짧은쪽의 50% 이상 공통**이면 시리즈로 판정. 부작용(단어 하나 겹쳐 과하게 묶임) 방지 위해 "2개+ & 50%+"로 엄격. 다른 작품(공통 핵심단어 부족)은 배제 확인.
  - **연관 판정 최종 구조 (3단계)**: 모든 비교는 `_vpNorm`(구분자·특수문자 제거) 후 → (1) 숫자→# 패턴 일치 / (2) 정규화 공통접두사 다음글자 분석(양쪽 숫자·한쪽 끝·한쪽 숫자) + 릴그룹 태그(`]``)`) 배제 / (3) 핵심단어 교집합. 검증 표본 7종(실제 .dpl 2종 + 테스트 5종) 전부 PotPlayer와 일치, 회귀 0.
  - **알려진 한계**: "비슷함" 판정은 정답이 없어 100% 자동 불가(PotPlayer도 동일, 그래서 전체/연관 토글 제공). 새 파일명 형식이 나오면 `.dpl` 대량검증으로 빠르게 발견·보강하는 운영 방식. 시즌 구분(시즌1/시즌2)·앞자리 연도 시작 등은 케이스 나올 때 대응.
- **`_vpApplyMode`**: `related` 모드 시 `all.filter(f => _vpIsRelated(curFile.name, f.name))`, 매칭 0이면 자기 자신만. **연관 목록은 파일명 자연정렬**(`localeCompare numeric:true` → 2화가 10화 앞). `filter` 결과는 `slice()` 복사라 전체 목록(`_fsVpFolderVideos`)은 원래 순서(수정날짜순) 유지. body innerHTML 재렌더 + 카운트(`fs-vp-count`) 갱신 + 검색 초기화.
- **연동 처리**: 클릭 핸들러·자동 다음 재생(`_fsVpBindAutoNext`)이 `_fsVpViewVideos`(현재 보이는 목록, 전체/연관) 기준 인덱스 참조 — ended 핸들러는 실행 시점에 목록 재계산(모드 전환 후에도 정확). 검색(B-10)은 DOM 기준이라 재렌더 자동 반영 + 모드 전환 시 초기화. 트랙 전환 시 패널 재생성되며 모드 유지 + 새 파일 기준 재필터.
- **헤더 재구성**: 아이콘(`fs-vp-icon`)을 헤더 직속으로 빼고, 제목+트랙수를 세로 스택(`fs-vp-header-main`, max-width 45%, margin-right 15px)으로 묶어 트랙수를 제목 아래로 이동. 닫기(`fs-vp-close`)는 `margin-left:auto`로 우측 고정. 토글 묶음은 제목과 X 사이.
- **검증**: 여러 파일명 형식(릴그룹 태그 유무, 부제 차이, 한자리/두자리 번호, 한글/영문 시리즈, 단품 영화) 조합으로 그룹이 끼리끼리 정확히 분리되고 다른 작품은 안 섞이는 것 확인. 동일 릴그룹의 다른 작품 배제 확인. 문법/CSS 균형/진단 잔재 0.
- **정직한 한계**: "비슷함" 판정은 정답이 없어 100% 자동 불가(PotPlayer도 동일, 그래서 전체/연관 토글 제공). 추가 번호(`_2` 등)나 화질 태그가 파일마다 다르면 분리될 수 있음. 안 맞는 케이스는 실제 사례 확인 후 보강 방침.



- **현상**: 일반 재생 배지 용량이 항상 GB 고정 → 100MB가 0.1GB, 100MB 이하 0.0GB로 표시.
- **메인앱 해결** (`assets/js/app.js`): `(fileSize/1024^3).toFixed(1)+'GB'` → `this.formatSize(fileSize)` (코드베이스 공용 함수, B/KB/MB/GB 자동). 100MB→"100.0 MB".
- **공유 페이지 추가** (`share.php` 일반재생 배지): `(코덱 해상도)` → `(코덱 해상도 용량)`. `config.php`의 전역 `formatFileSize($fileSize)` 재사용(share.php가 config.php require). `$fileSize`는 항상 초기화 + `htmlspecialchars` 이스케이프. PHP 실행테스트 5케이스 warning 0.
- **트랜스코딩 배지는 양쪽 제외** (펜닐님 결정). 메인앱 트랜스코딩 배지 실제 표시는 `⚡ HLS 스트리밍 HW : Intel`(인코더 정보만, 코덱/해상도/용량 없음).

#### v5.8.1l (2026-05-29)

**[v5.8.1l 버그 수정] 청크 업로드 조립 경쟁 조건 — 큰 파일 1개가 2개로 중복 생성 (2026-05-29)**

펜닐님 보고: 60MB 파일 1개를 드래그 업로드했는데 결과가 2개(`파일.zip` + `파일 (2).zip`) 생김. 무조건이 아니라 간헐적. 정밀 진단 → 소스 경쟁 조건(race condition) 확정·수정.

- **원인**: 큰 파일은 청크(PC 10MB 단위)로 분할되어 PC 기준 3개씩 병렬 전송(`PARALLEL=3`). 서버 `uploadChunk`(api/FileManager.php)는 청크 도착마다 `count(glob('chunk_*'))`로 개수를 세서 "다 왔으면 조립"하는데, **이 완성 판정에 락이 없음**. 마지막 청크들이 거의 동시 도착하면 여러 요청이 모두 "완성" 판정 → **조립 중복 실행** → 같은 파일 2개(2번째에 `(2)` 자동 부여).
  - 60MB = 6청크 → 마지막 배치(3개) 동시 전송 → 동시 도착 시 발생. 타이밍 의존이라 **간헐적**(펜닐님 "무조건 아님" 일치). 작은 파일(10MB 미만, 1청크)은 경쟁 없어 항상 정상.
- **수정**: 완성 판정 직후 `fopen($tempDir.'/.assembling.lock', 'x')` **원자적 락**(파일 없을 때만 생성). 동시 도착해도 단 하나의 요청만 락 획득 → 그 요청만 조립. 락 못 잡은 요청은 조립 없이 완료 응답(`complete:true`). 파일 락이라 `cleanupChunks`(is_file→unlink)에서 정상 정리되고, `glob('chunk_*')` 청크 수 계산에도 안 걸림.
  - **로컬 업로드(`uploadChunk`) + 원격 스토리지 업로드(`uploadChunkRemote`, FTP/SFTP/WebDAV 등) 양쪽 모두** 동일 경쟁 조건이 있어 둘 다 락 적용(전수 점검: `count(glob chunk_*) >= totalChunks` 판정 2곳 모두 보호).
- **적용 범위 (전수 점검)**:
  - 업로드 버튼 / 폴더 업로드 / 드래그 파일·폴더 업로드 → 모두 클라이언트 `uploadChunkedMulti`·`uploadChunked` → 서버 단일 `uploadChunk` 함수로 수렴 → **락 하나로 전부 보호**.
  - 복사→붙여넣기 / 잘라내기→붙여넣기 → 서버 `$fileManager->copy()`/`move()`(PHP copy/rename) → **청크 조립 안 함, 경쟁 조건 무관**.
  - 게시판 첨부 업로드 → 클라이언트 순차 전송(`await` 루프) → 동시 도착 없어 경쟁 조건 없음.
- **안전성**: 기존 조립 로직 삭제 없이 락만 추가. 청크 저장(move_uploaded_file)은 락 체크 전 실행 → 데이터 손실 없음(락 잡은 요청이 모든 청크 조립). 클라이언트는 `complete:true`로 정상 처리(추가한 `deduped` 필드 몰라도 됨). 락은 uploadId별 고유 tempDir 안 → 다른 업로드와 무간섭, 죽은 요청의 락은 `cleanupOldChunks`(1일)가 정리 → deadlock 없음.
- **검증**: PHP `php -l` OK. 멀쩡한 코드 보존(완성 판정·조립·cleanup 로직).
- **테스트(실기기 필요)**: 10MB 이상 파일을 여러 번(10회+) 반복 드래그 업로드 → 항상 1개만 생성되는지. 경쟁 조건은 타이밍 의존이라 1~2회로는 확신 어려움(수정 전에도 운 좋으면 1개였음). 락은 동시 도착해도 원천 차단하므로 반복해도 1개여야 함.

**[v5.8.1l 기능] 공유 업로드 청크화 — 내부 공유 + 외부 filedrop 대용량 지원 (2026-05-29)**

기존 공유 업로드 2종(내부 공유 업로드, 외부 filedrop 업로드)은 청크 분할 없이 파일을 통째로 전송 → PHP `upload_max_filesize`/`post_max_size` 제한에 걸려 큰 파일 업로드 불가(`ini_set`은 이 값 변경 불가). 일반 업로드처럼 청크화하여 사실상 무제한(디스크/quota까지) 지원.

- **재사용 설계 (안전)**: 검증된 `FileManager::uploadChunk`(청크 조립 + 경쟁조건 락) 코어를 그대로 재사용. uploadChunk에 `$skipPermCheck` 파라미터(기본 false) 추가 — **기존 일반 업로드는 인자 안 넘겨 동작 100% 불변**. 공유 경로만 자체 권한 검증 후 true로 호출(스토리지 can_write 체크만 skip, 확장자/랜섬웨어/quota/경로(isPathSafe) 검증은 그대로 수행).
- **내부 공유** (`uploadToInternalShareChunk`): `validateInternalSharePath`로 공유 write 권한 검증 후, 공유의 storage_id + 상대경로(file_path+subPath)로 uploadChunk 호출. 클라이언트 `_executeIShareUpload`를 청크 분할(`_uploadIShareChunked`, 10MB·병렬3)로 교체.
- **외부 filedrop** (`uploadToFileDropChunk`): 외부 공개(비로그인)라 **매 청크 토큰/만료/비밀번호/횟수 재검증** + 위험확장자/파일명 차단(DANGEROUS_EXTS) 후 uploadChunk 호출. 조립 완료(complete, deduped 제외) 시에만 업로드 횟수 +1. 클라이언트 share.php 업로드를 청크 분할로 교체.
- **라우팅**: `internal_share_chunk_upload`(로그인 필요, CSRF 검증) + `filedrop_chunk_upload`(noAuth + CSRF 면제 — 외부인은 토큰 기반 인증) 추가. rate limit 면제 + 업로드 전용 rate limit 등록. (filedrop은 외부 공개라 csrfExclude 등록 필수 — 누락 시 외부인 업로드 차단됨.)
- **경로 안전성**: filedrop은 외부 공개지만 uploadChunk 내부 `isPathSafe`로 경로 탐색(../) 차단. file_path는 관리자 생성 공유라 신뢰 + 검증 이중.
- **안전성**: 기존 단일 업로드 함수/라우팅(uploadToInternalShare, uploadToFileDrop)은 **삭제 없이 보존**(ShareManager 삭제줄 0). 새 lang 키 불필요(기존 키 재사용). 청크 조립 락이 공유에도 자동 적용(중복 생성 방지).
- **검증**: api.php/ShareManager/FileManager/share.php `php -l` OK, app.js `node --check` OK. 새 청크 함수가 기존 검증된 코어로 수렴.
- **테스트(실기기 필요)**: ① 내부 공유 폴더에 10MB+ 파일 업로드 ② 외부 filedrop 링크로 10MB+ 파일 업로드(비밀번호/횟수 제한 포함) ③ 위험 확장자(.php 등) 차단 ④ 대용량 파일 중복 생성 없는지.

**[v5.8.1l 기능] 공유 폴더 업로드 알림 — 소유자에게 헤더 알림 (2026-05-29)**

공유 폴더(내부 공유 + 외부 filedrop)에 타인/외부인이 업로드해도, 공유 소유자가 그 폴더를 열어둔 상태에선 자동 갱신이 안 됨(실시간 폴링/푸시 없음 — 설계상 정상). 새로고침해야 보임. 기존 알림 폴링 인프라를 활용해 "내 공유에 업로드됨" 알림을 추가.

- **방식**: 클라이언트 폴링(기존 30초 알림 폴링과 동일 인프라) + 헤더 알림(인덱스 재구축 알림과 같은 위치). SSE 실시간 푸시는 PHP(Windows+Apache) 환경에서 워커 점유 위험이 커 폴링 채택.
- **서버**: 청크 업로드 완료(complete, deduped 제외) 시 공유 레코드에 `pending_uploads`(미확인 수) + `last_upload_at/name` 기록. 일반 공유=`shares`, 내부 공유=`internal_shares` 각각 기록. 통합 조회 `getShareUploadNotifications`(pending>0만) + 초기화 `clearShareUploadNotify`. 라우팅 `share_upload_notify_list`(GET) + `share_clear_upload_notify`(POST).
- **클라이언트**: `_startShareUploadNotifyPolling`(30초) → pending 있으면 `showHeaderNotification('share-upload', …)`. 알림 클릭 시 해당 공유 폴더로 이동.
- **알림 해제(소유자가 폴더 열면 꺼짐 — 게시판 댓글/쪽지와 동일 UX)**:
  - 내부 공유: `browseInternalShare` 시 `share_clear_upload_notify(share_id, is_internal=1)`.
  - 외부 filedrop: 소유자가 일반 탐색기로 그 폴더(storage_id+file_path 매칭)를 열면 초기화. 부담 최소화 위해 헤더 알림이 떠있을 때만 `loadFiles` 완료 후 호출.
- **대상**: 공유 소유자 본인만(서버에서 소유자 검증). 내부 공유 소유자 필드는 `shared_by`, 일반 공유는 `created_by`로 정확히 구분.
- **안전성**: 기존 알림/폴링 로직 삭제 없이 추가. 로그아웃 시 폴링 타이머 정리. db 메서드는 internal_shares에 기존 사용된 패턴과 동일. lang 키 4종(ko/en) 추가.
- **검증**: 전체 `php -l`/`node --check`/JSON 파싱 OK. 멀쩡한 코드 보존(renderFiles 등).
- **테스트(실기기 필요)**: ① 외부인이 내 filedrop 폴더에 업로드 → 30초 내 헤더 알림 ② 알림 클릭 → 폴더 이동 ③ 그 폴더 열면 알림 사라짐 ④ 내부 공유도 동일 ⑤ 여러 공유 동시 업로드 시 합산 표시.

**[v5.8.1l 기능] 내부 공유 업로드 — 드래그 드롭 + 진행률바 추가 (2026-05-29)**

내부 공유 폴더 업로드가 파일 선택 버튼만 있고 드래그 업로드/진행 표시가 없던 것을 보완(외부 filedrop과 동일 수준).

- **드래그 드롭존**: 공유 폴더 뷰(쓰기 권한 시)에 점선 드롭존 추가. 파일 끌어놓기 + 클릭 업로드 모두 지원. dragover 시 강조 스타일. 드롭 시 기존 중복 체크 흐름(`_ishHandleDroppedFiles` → 중복 모달 or 업로드) 재사용.
- **진행률바 + 정보**: 외부 filedrop과 동일하게 청크 단위 진행률바 + "N/M: 파일명 / 용량(%) · 속도" 표시. 여러 파일 시 "전체 %" 추가. `_executeIShareUpload`/`_uploadIShareChunked`에 onProgress 콜백 추가(청크 배치마다 갱신). 완료 후 browseInternalShare 재렌더 시 자동 정리.
- **안전성**: 기존 업로드 버튼/중복 모달/청크 전송 로직 보존, onProgress는 선택 인자(미전달 시 기존 동작). lang 키 `ishare_drop_hint`/`filedrop_total`(ko/en) 추가. `node --check` OK.

**캐시 무효화:** `APP_VERSION` `5.8.1k` → `5.8.1l`

---

#### v5.8.1k (2026-05-26 ~ 2026-05-28)

**추가 — [음악 플레이어 iOS 썸네일: 재생 재개 시 복구] (2026-05-26)**

펜닐님 보고. 추측 수정 금지 — 코드 흐름으로 원인 확정 후 수정.
- **증상**: 재생 중 탭 닫음/다른 앱(유튜브 등) 갔다가 FileStation 탭 복귀 → 플레이어는 살아있어 이어듣기 가능하나, 재생 누를 시 iOS 잠금화면/제어센터 썸네일이 안 나오거나 갱신 안 됨. (잠금/백그라운드가 아닌, 정지 상태 후 재생 재개 케이스)
- **원인** (확정): 정지 상태 동안 iOS가 MediaSession metadata/artwork를 메모리 정리로 유실. artwork maintenance 타이머는 `audio.paused`면 동작 안 함(`_startArtworkMaintenance`/`_checkAndRestoreArtwork` 둘 다 paused return). 게다가 `togglePlay()`가 재생 재개 시 `audio.play()`만 호출하고 artwork 복구 트리거가 없었음 → 재생해도 썸네일 미복구.
- **해결** (`assets/js/app.js` togglePlay, +11/-1): 재생 재개(paused→play) 시 `play().then(() => this._forceRefreshArtwork())`로 artwork 복구 추가. 기존 복구 함수 재사용. play 비동기 resolve 후 복구.
- **안전**: 일시정지 분기(`audio.pause()`)는 완전 무변경. 재생 시작에만 복구 추가. `_currentMetadata`는 플레이어 생존 시 메모리 유지되어 복구 가능. togglePlay 호출처 4곳(재생버튼/MediaSession play/외부) 모두 정상 — 잠금화면 재생 시에도 복구 이득.
- ⚠️ FileStation 버전 유지 (v5.8.1j — 버그 수정, 같은 버전 재패키징)

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

**추가 — [동영상 H264/MP4 영구 변환 기능 신규] (2026-05-26)**

펜닐님 요청. 실시간 트랜스코딩은 매 재생마다 변환이라 탐색(스크롤) 불가 → 미리 H264/MP4로 영구 변환해두면 일반 재생(copy)으로 자유 탐색 가능. 단계적 구현 + 매 단계 종합 재검토.
- **서버** (`api/FileManager.php` +284, `api.php` +19): `convertToH264Mp4()` + `convert_h264` SSE 액션
  - 코덱 판별: H264 입력 → `-c:v copy`(화질/용량 원본 그대로), HEVC/WMV 등 → 재인코딩
  - **HW(Intel QSV 등) 우선 → 실패 시 SW(libx264) 자동 폴백** (detectHwEncoder 동작확인 + stderr 에러패턴 감지, "CPU로 재시도" 안내). HW 화질조정 global_quality 20, SW libx264 -crf 18(화질 최대 보존)
  - 이미 h264+mp4면 skip(변환 불필요 알림). 출력 충돌 시 `_h264` suffix. faststart(웹 탐색 최적화)
  - 보안: requireLogin + checkFolderPermission + checkPermission(read/write) + isPathSafe(입출력) + escapeshellarg 전부. 7-Zip 백그라운드 패턴(bat+resultFile+SSE) 재사용. 임시파일 누수 방지(clientGone 폴링 계속), maxWait 1시간
- **UI** (`assets/js/app.js` +220, `index.php` +2): 컨텍스트 메뉴(우클릭/롱프레스) + 작업 버튼 메뉴 "🎬 H264/MP4로 변환"
  - **다중 순차 변환**: 선택 항목 중 변환 필요한 동영상만 필터(mp4 등 네이티브 제외), 하나씩 await 순차 처리, 진행률 "(N/M)" 표시, 완료 요약 토스트
  - 변환 다이얼로그에 **"변환 후 원본 휴지통 이동" 체크박스** (체크 시 변환 성공+H264 검증 후에만 moveToTrash, 실패 파일 원본 보존)
  - 진행률 모달(전용 conv-progress-* ID, 중첩 방지), `_convBatchRunning` 가드(finally 리셋), `_convertOneFile`로 단일 변환 분리
- **i18n**: lang/ko·en.json convert_* 키 22개씩
- **실측 검증** (SW 경로 — 컨테이너 GPU 없어 HW는 펜닐님 환경 테스트 필요): HEVC→H264 SSIM 0.999/PSNR 56.7dB(거의무손실), WMV→H264 SSIM 0.998(용량 1044KB→292KB 감소), H264→copy SSIM 1.000000(완전무손실), faststart 적용 + 5초 seek 성공(탐색 가능 = 핵심목적 달성)
- **검토 중 발견·수정** (자체 결함): 임시파일 누수(clientGone), 변환가드 catch 미리셋, mp4 변환메뉴 노출(_needsTranscode), 작업버튼 메뉴 항목 누락, 다중선택 우클릭 메뉴 미표시(selectedItems.some), 진행률 모달 중첩, 서버 i18n 키 4개 누락
- ⚠️ FileStation 버전 유지 (v5.8.1j — 신규 기능이나 "버전 올려줘" 명시 없음, 재패키징)

**[메뉴 ▲▼ 스크롤 인디케이터] PC + 모바일 컨텍스트 메뉴 / 작업 버튼 메뉴 (펜닐님 요청)**

펜닐님 분석: 모니터가 커도 메뉴가 화면 가득 차면 불편, 작아도 잘림. 스크롤바는 모양이 메뉴와 안 어울려 어색. 항목은 다 필요해서 못 줄이고, 서브메뉴화는 Windows 11처럼 숨김 불편 발생. 결론 — **고정 높이 + 스크롤바 숨김 + ▲▼로 "더 있음" 인지**. macOS Finder 검증된 패턴.

- **CSS** (`assets/css/style.css` +96/-4):
  - PC `.context-menu` / `.toolbar-action-menu`: max-height **500px** 고정 (모니터 크기 무관 일관)
  - 스크롤바 완전 숨김: `::-webkit-scrollbar { display:none }` + `scrollbar-width: none` (Chrome/Edge/Firefox/Safari 모두)
  - 컨텍스트 메뉴: 스크롤을 `<ul>`로 이동(메뉴 자체 overflow visible — ▲▼이 잘리지 않게), ▲▼은 메뉴 박스에 `position: absolute`로 부착
  - 작업 버튼 메뉴: 메뉴 자체가 스크롤 영역, ▲▼은 `position: sticky`로 첫/마지막에 부착
  - `.scroll-indicator`: 높이 22px, 흰 배경 95% 불투명, 회색 화살표(▲▼), `pointer-events: none`(항목 클릭 방해 X)
  - `.has-scroll-up` / `.has-scroll-down` 클래스로 표시 토글
  - 모바일 시트(`@media`): 기존 하단시트/그립바/slideUp 유지하되, 메뉴 자체 `overflow-y:auto` → `<ul>`로 이동 (PC와 통일된 ▲▼ 로직). ▲ 위치는 그립바 아래(top:14px)로 조정해 충돌 방지
  - 작업 버튼 메뉴 max-height `calc(100vh - 120px)` → 500px (큰 모니터 가득참 해소)
- **JS** (`assets/js/app.js` +82/-1): `_setupScrollArrows(menuEl, scrollEl)` 신규 헬퍼
  - ▲▼ 인디케이터 요소 동적 삽입 (HTML 무변경)
  - `scroll` 이벤트 + `ResizeObserver`(콘텐츠 크기 변화) + `MutationObserver`(메뉴 display/class 변화) 3중 감지로 ▲▼ 자동 토글
  - `scrollTop > 1` → ▲ / `scrollTop + clientHeight < scrollHeight - 1` → ▼
  - 페이지 로드 시 `App.init()` 직후 한 번만 부착 — 메뉴 표시/숨김 토글마다 호출 불필요
  - `_scrollArrowsSetup` 플래그로 중복 부착 방지
  - sticky(toolbar)/absolute(context) 두 가지 구조 자동 분기
- **삭제된 4줄 (의도된 이동/대체)**:
  - toolbar `max-height: calc(100vh - 120px)` → 500px
  - 모바일 context-menu `-webkit-overflow-scrolling`/`overflow-y:auto`/`overscroll-behavior` 3줄 → `<ul>`로 이동 (스크롤 영역 분리)
- **안전성**:
  - 기존 메뉴 토글 로직 무변경 (showContextMenu 본체 등 일절 안 건드림)
  - ▲▼ 동적 삽입 → HTML 파일 무변경
  - `pointer-events: none` → 항목 클릭 절대 방해 안 함
  - 메뉴 짧을 때(빈화면 메뉴 등) 자동으로 ▲▼ 안 뜸 (scrollHeight ≤ clientHeight 조건)
- **회귀 검증**: app.js `node --check` OK, PHP 문법 OK. 메뉴 토글 함수 일절 미변경

**[v5.8.1k 후속 수정] 펜닐님 테스트 보고 3건 (2026-05-27)**

▲▼ 인디케이터 1차 적용 후 펜닐님 테스트로 발견된 문제 3건 정밀 수정. 데이터/코드 흐름으로 원인 확정 후 처리.

- **1. 모바일 ▲▼이 첫/마지막 메뉴 항목 가림** (`style.css`)
  - 원인: 모바일 시트에서 ▲(top:14px)/▼(bottom)이 22px 높이로 ul 영역과 겹침. ul은 ▲▼ 표시 시 회피 padding 없었음.
  - 수정: `.context-menu.has-scroll-up > ul { padding-top }` / `.has-scroll-down > ul { padding-bottom }` 동적 padding. ▲▼ 안 떴을 때는 padding 0 (회귀 없음). PC 22px, 모바일 24/28px(env safe-area 고려).
- **3. PC 메뉴 최대 높이 500 → 400px** (`style.css`)
  - 펜닐님 판단: 500px 다소 김. 400px(약 11~12개 항목)로 축소.
  - 적용: `.context-menu`(2670), `.context-menu > ul`(2677), `.toolbar-action-menu`(7640) 3곳. `.trash-list`(휴지통)은 메뉴 아님 → 500px 보존(건드리면 안 되는 멀쩡한 코드).
- **4. 모바일 컨텍스트 메뉴 스크롤 중 메뉴 항목 잘못 눌림** (`assets/js/app.js`)
  - 원인 (코드 흐름 확정): touch 임계값 10px이 작아 손가락 떨림 허용폭 부족 + **`click` 핸들러가 `ctxTouchMoved` 플래그 무시**(touchend는 막아도 iOS가 합성 발사하는 click이 그대로 통과해 액션 실행).
  - 수정 ①: 임계값 `dx>10||dy>10` → `dx>20||dy>15` (수직이 메뉴 주 스크롤이라 dy 더 민감). 손가락 떨림 허용폭↑.
  - 수정 ②: `click` 핸들러에 `if (ctxTouchMoved && Date.now()-ctxTouchEndTime < 500) return` 추가. iOS가 touchend 후 ~300ms 안에 합성하는 click을 차단(500ms 여유). touchend 직후의 합성 click만 정확히 막고, 일반 마우스 click(데스크탑)에는 영향 없음(`ctxTouchMoved`가 항상 false).
  - `ctxTouchEndTime` 변수 추가로 click과 touchend 시간 상관관계 추적.
- **검증**: app.js `node --check` OK / config.php `php -l` OK / `.trash-list`(500px) 보존 확인 / 멀쩡한 코드 무변경(이번 새 삭제 1줄 = 임계값 10px의 의도된 교체)

**[v5.8.1k 후속 수정 2차] 메뉴 길이 통일 + ▼ 버그 + 컨텍스트 메뉴 구조 통일 (2026-05-28)**

펜닐님 테스트 보고로 발견. 처음 ▲▼ 구현에서 컨텍스트 메뉴(absolute, `<ul>` 스크롤)와 작업버튼 메뉴(sticky, 메뉴 자체 스크롤)를 **다른 구조로 만든 것이 근본 원인**이었음. 여러 증상(항목 가림, ▼ 안 사라짐, 길이 차이)이 모두 여기서 파생. 최종적으로 두 메뉴를 100% 동일 구조로 통일하여 해결.

- **▼ 버그 (다 봤는데 ▼ 안 사라짐)** (`assets/js/app.js`):
  - 원인 (확정): ▲▼이 스크롤 영역(scrollEl) 안에 있어 그 높이(각 22px)가 `scrollHeight`에 포함됨 → 끝까지 스크롤해도 `scrollHeight > clientHeight`라 ▼ 유지.
  - 수정: `update()`에서 표시 중인 ▲▼ 높이를 뺀 `effectiveScrollHeight`로 끝 도달 판정. (▲▼이 scrollEl 안이고 has-scroll 클래스일 때만 보정 → 컨텍스트/작업버튼 양쪽 동일 적용)
- **컨텍스트 메뉴를 작업버튼 메뉴와 100% 동일 구조로 통일** (`style.css` + `app.js`):
  - 기존: 컨텍스트만 `<ul>` 스크롤 + ▲▼ absolute → 기존 PC 위치조정 JS(menuEl 스크롤 전제)와 충돌해 max-height/▲▼ 위치 어긋남.
  - 변경: 컨텍스트도 **메뉴 자체 스크롤**(overflow-y auto, `<ul>`은 흐름) + **▲▼ sticky**(scrollEl 안 첫/마지막) + 스크롤바 숨김. `_setupScrollArrows(ctxMenu, ctxMenu)`로 작업버튼과 동일 호출.
  - 모바일 시트도 같은 구조로 정리(메뉴 자체 스크롤), 하단 시트/그립바/slideUp 디자인은 유지.
- **메뉴 길이 (펜닐님 개발자도구 실측값)**:
  - 컨텍스트 메뉴 max-height **420px** (CSS + JS `Math.min(420, viewHeight - 20)`)
  - 작업버튼 메뉴 max-height **415.2px** (JS `Math.min(415.2, vh - btnRect.bottom - 14, vh*0.6)`)
  - 둘 다 `Math.min`이라 작은 화면에선 화면에 맞게 자동 축소(메뉴 화면 밖 방지)
- **안전성**:
  - ▲▼ `pointer-events: none` + 클릭 핸들러 `closest('li')`/`data-action` 미존재로 무시 → 항목 클릭 이중 안전
  - `_scrollArrowsSetup` 중복 부착 방지
  - absolute 시절 잔재 CSS(padding-top/max-height 보정 등) 완전 제거 — 시행착오 흔적 정리됨
- **검증**: app.js `node --check` OK / CSS 중괄호 균형 OK / `.trash-list`(500px) + 다른 400px 8곳 보존 / 두 메뉴 sticky·overflow-y 동일 확인 / 펜닐님 PC·모바일 테스트 "잘 된다" 확인
- ⚠️ 솔직한 기록: 이번 메뉴 작업은 시행착오가 많았음(처음 두 메뉴를 다른 구조로 만든 것이 원인). 펜닐님 반복 테스트로 올바른 해법(동일 구조 통일)에 도달. 처음부터 동일 구조로 했어야 함.

**[v5.8.1k 보안 수정] 동영상 변환 권한 체크 강화 (2026-05-28)**

펜닐님 권한 검토로 발견. 변환 기능에 권한 허점 2건 — 공유 스토리지 환경에서 문제될 수 있어 수정.

- **허점 1: 폴더별 쓰기 권한 우회** (`api.php`):
  - 변환은 파일 생성(쓰기 작업)인데 라우팅이 `checkFolderPermission`을 `can_read`로만 체크(transcode 패턴 복사 실수). 스토리지 write는 있으나 특정 폴더만 read-only인 사용자가 그 폴더에서 변환(파일 생성) 가능했음.
  - 수정: `can_read` → `can_write` (compress/rename 등 다른 쓰기 작업과 동일).
- **허점 2: 삭제 권한 우회 (원본 휴지통 이동)** (`api/FileManager.php`):
  - "변환 후 원본 휴지통 이동"이 `can_delete` 체크 없이 write 권한만으로 동작. 공유 스토리지에서 **쓰기는 되나 삭제 권한 없는 사용자가 변환을 통해 원본을 치우는** 우회 가능했음.
  - 수정: deleteOriginal 시 `checkPermission(can_delete)` + `checkFolderPermission(can_delete)` 체크. 권한 없으면 변환은 정상 진행하되 **원본 보존**(moveToTrash 안 함).
  - UI: 권한 없어 원본 보존된 경우 "삭제 권한이 없어 원본은 보존됨" 토스트 알림(`trash_skipped_no_perm` + i18n convert_no_delete_perm). 체크박스는 그대로 두되 서버가 권한으로 최종 판단(서버 차단이 핵심).
- **변환 권한 체계 (최종, 다른 기능과 일관)**:
  - 변환 실행: read + write (스토리지 + 폴더 레벨)
  - 원본 휴지통 이동: can_delete (스토리지 + 폴더 레벨)
  - UI 메뉴 표시: can_write
- **검증**: PHP `php -l` OK / app.js `node --check` OK / JSON OK. 변경 = api.php 1곳(can_write) + FileManager.php deleteOriginal 권한 블록 + app.js done 토스트 + i18n 2키.

**[v5.8.1k 보안 수정 2] 키보드 Del 키 삭제 권한 체크 (2026-05-28)**

펜닐님 권한 검토 연장. 변환 권한을 점검하다 발견 — 컨텍스트 메뉴/작업버튼은 `can_delete` 없으면 삭제 항목을 숨기는데, **키보드 Del 키는 권한 체크 없이 `deleteSelected()` 직접 호출**하던 불일치.

- **원인**: Del 키 핸들러가 선택 항목 있으면 바로 삭제 진행. 서버(api.php delete 라우팅)가 `can_delete`로 최종 차단하므로 **보안은 안전했으나**, 클라이언트가 미리 안 막아 헛된 요청 + 어색한 실패(확인 다이얼로그 후 서버 거부).
- **수정** (`assets/js/app.js` Del 핸들러): `this.currentPermissions.can_delete` 체크 추가. 권한 없으면 "삭제 권한이 없습니다"(i18n no_delete_permission) 알림 후 동작 안 함. 컨텍스트 메뉴와 완전 일관.
- **삭제 권한 3중 체계 (최종)**: ① UI 메뉴 표시(can_delete) ② 키보드 Del(can_delete, 이번 추가) ③ 서버 라우팅(checkFolderPermission can_delete)
- **안전성**: Del 키 1곳만 변경. F2(이름변경) 등 다른 키 미변경. 서버 차단은 기존 유지(보안 변화 없음, UX 일관성 개선).
- **검증**: app.js `node --check` OK / JSON OK / i18n no_delete_permission 2키 추가

**[v5.8.1k 개선] 동영상 변환 진행률에 배속(speed) 표시 (2026-05-28)**

펜닐님 제안. 변환 중 진행률(%)만 있고 속도 정보가 없어, ffmpeg의 실제 변환 배속을 표시. 저사양 환경에서 "얼마나 빠른지/오래 걸릴지" 가늠 가능.

- **서버** (`api/FileManager.php`): ffmpeg `-progress` 파일에서 `speed=2.5x` 값 파싱(out_time_ms 파싱과 같은 자리) → `progress` 이벤트에 `speed` 추가. speed 있을 때만 전송(copy 모드는 인코딩 안 해 거의 없음).
- **UI** (`assets/js/app.js`): progress 핸들러에서 `d.speed` 있으면 진행 문구에 "(2.5x)" 표시. 단일·다중 변환 공통(같은 `_convertOneFile` 핸들러).
- **의미**: 2.5x=실시간의 2.5배 빠름, 0.6x=실시간보다 느림(시간 오래 걸림 신호). HW/SW 폴백 시 각 모드 실제 속도 표시.
- **안전성**: H264 변환 경로만 변경. vault 등 무관한 `_updateConvertProgress`(언더스코어) 함수 미변경. speed 없으면 기존과 동일(하위 호환).
- **검증**: PHP `php -l` OK / app.js `node --check` OK. (컨테이너 GPU 없어 SW libx264 speed만 실측 — HW QSV speed는 실기기 확인 필요)

**[v5.8.1k 안정화] 동영상 실시간 트랜스코딩 — hang 타임아웃 + ffmpeg 종료 경로 keepalive (2026-05-28)**

펜닐님 보고(개발 중 ffmpeg 2개 누적/종료 안 됨, 직접 죽여야 했음)를 정밀 코드 점검으로 진단 → 소스 문제 2건 확정·수정.

- **문제 1: HW hang 시 첫 청크 blocking read 무한 대기** (`api/FileManager.php`):
  - `transcodeStream`(미리보기) + `transcodeShareStream`(공유)의 첫 청크 읽기가 `stream_set_blocking(true)+fread`로 타임아웃 없음. HW ffmpeg가 초기화에서 hang(데이터도 안 주고 죽지도 않음)하면 PHP가 무한 대기 → `register_shutdown_function` 안 불림 → ffmpeg 잔존(직접 죽여야 함).
  - 수정: `_readFirstChunkWithTimeout($pipe, 6)` 클래스 메서드 신설. non-blocking + `stream_select`로 6초 대기 → 데이터 오면 반환(정상, 기존과 동일), EOF/타임아웃이면 `''` 반환(=기존 폴백 로직 트리거). 모든 종료 경로에서 blocking 모드 복구 보장.
  - 양쪽 함수 HW/SW 첫 청크 4곳 적용. 메인 스트리밍 루프(while feof)·폴백 판정 로직은 보존.
  - 타임아웃 6초: HW 초기화(보통 1~3초)보다 충분, hang 시 체감 지연 최소화. 빠른 전환(끄고 바로 재생) 시 pipe_kill로 ffmpeg 죽으면 select가 EOF 감지해 즉시 탈출(6초 안 기다림).
- **문제 2: ffmpeg 종료 요청(pipe_kill/stop)이 일반 fetch라 취소됨** (`assets/js/app.js`, `share.php`):
  - 모달 닫기/새 재생 직전 이전 ffmpeg를 죽이는 호출이 일반 `fetch`로 된 곳이 미리보기 3곳(hideModal·preview 정리·새 재생 직전) + 공유 1곳(HLS→MMS 폴백 stop). "어 이상하네 하고 바로 끄고 다시 재생" 시 후속 정리/네비게이션 중 fetch가 취소 → 이전 ffmpeg 안 죽고 새 것과 공존(누적).
  - 수정: 해당 경로 모두 `sendBeacon` 우선 + `fetch(keepalive)` 폴백으로 통일(페이지 정리 중에도 도달 보장). 기존 sendBeacon 경로(pagehide 등)의 폴백도 keepalive로 일관화.
  - 최종: 미리보기 순수 일반 fetch pipe_kill 0개, 공유 순수 일반 fetch stop 0개.
- **미수정(투명)**: ② proc_terminate 후 proc_get_status 미확인 — SIGKILL이 대부분 죽여 위험 낮아 안 건드림. 배지(HW/SW/fail) 로직 — 정교한 폴백 상태머신이고 명백한 버그 없어 보존(펜닐님 "거의 고쳐서 문제없다" 확인).
- **검증**: app.js `node --check` OK / share.php·FileManager.php `php -l` OK. share.php 정확히 1줄 변경. 멀쩡한 코드 보존(스트리밍 루프·폴백 판정·배지 로직).
- **테스트(실기기 필요, 컨테이너 GPU 없어 미실측)**: 미리보기/공유 각각 재생→바로 끄고 재생 반복 시 ffmpeg 누적 없는지, HW hang 시 6초 후 SW 폴백.

**캐시 무효화:** `APP_VERSION` `5.8.1j` → `5.8.1k`

---

#### v5.8.1j (2026-05-18)

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
- 일반 페이지: `localStorage` `fs_vp_auto_next` (자동재생, 영구 저장) + `localStorage` `fs_vp_mode` (전체/연관 모드, 영구 저장 — 동일 패턴)
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

*FileStation v5.8.2d — 한국 사용자를 위한 자체호스팅 웹 NAS*
*최종 업데이트: 2026-07-27 (v5.8.3c, rhwp 0.8.2 기준)*
