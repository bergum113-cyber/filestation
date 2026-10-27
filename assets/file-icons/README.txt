File Icons - FileStation 파일 아이콘 (최종 정리 2026-10-27)
==============================================================

이 폴더의 기본 SVG(사용자 업로드 custom/ 폴더 제외)는 두 가지 모양이 섞여 있습니다.
(2026-10-27 14시 정리 — 앞 단계 설명이 남아 서로 안 맞던 부분을 지금 상태로 고침)

1) 파일 목록에 쓰는 그림 — 'ext-확장자.svg' 164개 (2026-10-27 14:39, 펜닐 선택 ③ — 종류별 색 하나 + 꽉 찬 그림 + 아래 색 띠)
 - 흰 종이(회색 테두리) + 접힌 귀퉁이 연한 색 + 종류 색으로 꽉 찬 그림 + 종이 아래쪽 종류 색 띠 안에 흰 확장자 글자(도형). 48×48. 노란 라벨 없음
 - 종전(윈도우 11 느낌 — 확장자마다 다른 색 + 가는 선 그림)은 모바일 목록에서 동영상·자막이 구분되지 않아 바꿈(펜닐 14:28 제보)
 - 그림 크기·위치는 종전 아이콘과 같음(48 칸 기준 14.5,11 에서 0.8배, 오피스 로고는 14,9.5 에 20×20) — 띠(y 31~44)만 더함(14:48 펜닐)
 - 종류별 색(흰 띠 글자 대비 4.5:1 이상, 오피스 상표색과 겹치지 않게)
   동영상 #6741d9(보라) · 자막 #0b7285(청록) · 음악 #c2255c(자홍) · 사진 #237d36(초록) · 압축 #8a5a00(황갈) · 디스크 #5c6b7a
   실행·설치·안드로이드·라이브러리 #37474f · 텍스트·로그·마크다운·설정 #5a6772 · 코드·스크립트·JSON·DB #3d4f8f(언어는 확장자 글자로)
   글꼴 #7a4a2a · 링크 #1c5fb8 · 토렌트 #4b7a12 · 디자인(psd #1971c2 · ai #c2410c · eps #a61e4d · svg #a35f00)
 - 그림: 동영상 꽉 찬 화면 + 흰 ▶ · 자막 꽉 찬 말풍선 + 흰 글줄 · 음악 굵은 음표 · 사진 · 압축 지퍼 상자 · 디스크 원판 · 실행 창 · 설치 상자
   · 라이브러리 퍼즐 · 스크립트 터미널(>_) · 설정 톱니 · 글꼴 'A' · DB 원통 · 코드 </> · JSON { } · 링크 · 토렌트 내려받기 화살표
 - 상표가 있는 형식 38개(2026-10-27 15:07 — 15:23 에 .db 는 일반 DB 그림으로 되돌림(Thumbs.db 등 SQLite 아닌 파일이 많음), 펜닐 '상표색·상표 그림 기준') — 로고 모양·공식 색은 Simple Icons 13.21(https://github.com/simple-icons/simple-icons, CC0-1.0),
   띠 색은 상표색(로고가 검정이면 GitHub Linguist languages.yml 언어색), 띠 글자는 흰색/검정 중 대비가 큰 쪽
   js·mjs·cjs · jsx · tsx · py·pyw · php·phtml · java·class·jar · c·h · cpp·cc·cxx·hpp·hxx · rb · go · rs · swift · html·htm · css · scss·sass · less · xml·xsd·xsl
   · yaml·yml · toml · json · json5 · md·markdown · sh·bash · zsh · fish · sqlite·sqlite3 · env · apk · psd · ai · svg · deb · rpm · dmg·app · torrent(qBittorrent)
   exe·msi·dll·so·dylib 는 Microsoft 로고가 Simple Icons 에 없어 창·상자·퍼즐 그림 + 윈도우 파랑 #0078d4
   ※ 로고 그림 데이터는 CC0 이지만 상표권은 각 회사 것(파일 종류 표시용)
 - 오피스·PDF·한글 14개: 확정 로고(word·excel·powerpoint·pdf·hwp.svg 그림) + 상표색 띠
   - ext-doc · ext-docx · ext-odt · ext-rtf : 워드(#2b579a)   - ext-xls · ext-xlsx · ext-ods · ext-csv : 엑셀(#217346)
   - ext-ppt · ext-pptx · ext-odp : 파워포인트(#c43e1c)        - ext-pdf : PDF(#d32f2f)   - ext-hwp · ext-hwpx : 한글(#1f6fbf)
 - 파일 목록(서버 FileManager::getFileIcon · 브라우저 App.getFileIcon)은 관리자 지정 아이콘 다음으로 ext-확장자.svg 를 가장 먼저 씀
   (한글 분기·압축·자막보다 앞). 아이콘 관리 미리보기(_iconMgrGetEffectiveIcon)도 같은 순서

2) 로고·선 그림 — 갤러리·미리보기용, ext 그림이 없는 확장자에 쓰임
 - word.svg · excel.svg · powerpoint.svg · pdf.svg : 예전(v5.8.6 원본) 그림. vscode-icons(MIT, LICENSE.txt) 그림
   (W·X·P 로고 모양, PDF 는 빨간 'PDF' 띠). 위 ext 그림 14개의 로고가 이것
 - hwp.svg : 새로 그린 한글 그림(펜닐이 보낸 한컴 아이콘 참고, 시안 C) — 줄 그은 문서 + 오른쪽 아래 파란 접힘 판·흰 '한'
 - 크기(2026-10-27): 다섯 그림(word·excel·powerpoint·pdf·hwp)은 그림 내용은 그대로 두고 viewBox 만 넓혀, 칸 안에서
   가로 72%·세로 83% 안에 들어오게 함
 - 아래 '선 그림' 그림들(video·audio·archive …)은 관리자가 고르는 갤러리용, ext 그림이 없는 확장자의 대체 그림으로 남아 있음

(참고) 선 그림 + 종류별 색 (2026-10-26) — 갤러리·대체용 그림(위 ext 그림과는 별개)
 - 48×48, 선 굵기 2.4, 둥근 끝. 종류별 색은 흰 바탕·어두운 바탕(#1b1d22) 모두 대비 3:1 이상이 되게 밝기를 맞춤
 - 코드·설정은 파일 모양 + 짧은 글자(JS·PHP·PY·HTML·CSS·SQL·INI …). 같은 계열 다른 확장자는
   '이름-확장자.svg'(예: js-jsx.svg 'JSX', cpp-hpp.svg 'HPP', ini-conf.svg 'CONF', php-phtml.svg 'PHTML')
   tsx.svg 는 TypeScript(파란 'TSX')
 - 미디어·기타는 그림: image(사진) · video(재생) · audio(음표) · subtitle(자막) · archive(지퍼) · disc(디스크 — iso·dmg·img)
   · app(실행·설치 파일) · text(txt·log) · font('Aa') · json('{ }') · database(원통 — db·sqlite·sqlite3)
   · shell · powershell · bat · link(url·lnk) · torrent · markdown('MD')
 - 그림 속 글자는 글꼴 글자(<text>)가 아니라 도형(<path>) — 글꼴 글자는 작은 크기에서 PC(윈도우) 글자 맞춤 때문에
   'XL SX' 처럼 글자 사이가 벌어져 보였음. 모양은 Liberation Sans Bold(Arial 과 같은 폭) 글자 그대로
 - folder.svg / folder_default.svg 와 config.php SVG_ICONS['folder']·app.js 의 기본 폴더 그림이 같은 모양
   (테두리 + 노란 칠 — 윈도우 탐색기처럼)

크기 값(2026-10-27 16시): 기본 SVG 240개 모두 맨 앞 <svg> 에 width="48" height="48" — 화면 크기는 CSS 가 정하고(목록 40·그리드 60·사이드바 16 등),
 이 값은 크기 규칙이 없는 곳에서 사파리(WebKit)가 기본 150×150 으로 그리는 것을 막는 안전장치. 새 그림을 넣을 때도 같은 값을 넣을 것

확장자 라벨(노란 글자 표시, 라벨 위치·'라벨 표시' 켜기/끄기 설정을 따름)
 - 지금 기본 확장자는 모두 ext 그림(그림 안에 확장자)이 있어 라벨이 붙는 기본 아이콘은 없음(2026-10-27 기준)
 - 라벨이 붙는 경우: ① 관리자가 '확장자 아이콘 관리'에서 '|라벨'을 붙여 고른 아이콘
   ② ext 그림이 없는 확장자가 아래 종류 그림으로 그려질 때 — 압축(archive) · 자막(subtitle) · 동영상(video) · 음악(audio)
      · 실행 파일(app) · 텍스트(text) · 디스크 이미지(disc) · 오피스(word·excel·powerpoint)
 - 규칙 위치: 서버 api/FileManager.php FileManager::iconNeedsExtLabel · 브라우저 assets/js/app.js App._fsIconNeedsLabel (같은 표)

특수 처리(ext 그림이 없을 때의 대체 — 지금은 아래 확장자 모두 ext 그림이 있어 ext 그림이 먼저 쓰임)
- 압축 파일: archive.svg + 확장자 라벨 (zip, 7z, rar, tar, gz, bz2, xz, tgz, tbz2, 001, lz, cab, lzma, z)
- 디스크 이미지: disc.svg + 확장자 라벨 (iso, dmg, img — iso 는 종전 압축 그림이었음)
- 자막 파일: subtitle.svg + 확장자 라벨 (srt, smi, ass, ssa, vtt, sub)
- TS 파일: TypeScript가 아닌 MPEG-TS 동영상으로 취급
- 관리자가 '확장자 아이콘 관리'에서 고른 아이콘·업로드한 아이콘(custom/)은 그대로 우선 적용

지금 화면에서 쓰지 않는 그림(2026-10-27 기준)
 - word-docx · word-odt · word-rtf · excel-xlsx · excel-ods · excel-csv · powerpoint-pptx · powerpoint-odp
   (오피스를 예전 그림 + 라벨로 되돌리면서 안 씀), hwp-hwpx(한글 새 그림으로 안 씀), sql-db(database.svg 로 안 씀)
 - 관리자가 '확장자 아이콘 관리'에서 골라 둔 경우를 위해 지우지 않음(갤러리에 보임)
