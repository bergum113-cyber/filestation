File Icons - vscode-icons 기반
================================

출처: https://github.com/vscode-icons/vscode-icons
라이선스: MIT License (LICENSE.txt 참조)
Copyright (c) 2016 Roberto Huertas

이 폴더의 SVG 파일들은 vscode-icons 프로젝트에서 가져온 것으로,
파일 확장자별 아이콘을 제공합니다.

포함된 아이콘:
- 문서: pdf, word, excel, powerpoint, markdown, text(미사용)
- 코드: html, css, js, json, xml, php, python, java, c, cpp,
       ruby, go, rust, swift, yaml, toml, ini, sql, shell, powershell
- 미디어: image, video, font (audio는 이모지 🎵 사용)
- 압축: archive (공용, 확장자 라벨 오버레이), zip(미사용)
- 기본: folder(미사용), default(미사용)

미사용 아이콘(text/folder/default/zip)은 FileManager.php의 getFileIcon()에서
기존 인라인 SVG를 반환하므로 로드되지 않습니다.
삭제하지 않는 이유: 나중에 변경 시 재사용 가능성.

특수 처리:
- HWP/HWPX: app.js 및 config.php의 인라인 SVG (vscode-icons에 없음)
- 압축 파일: archive.svg + CSS 오버레이로 확장자 라벨 표시
  지원: zip, 7z, rar, tar, gz, bz2, xz, tgz, tbz2, 001, iso
- 자막 파일: subtitle.svg + CSS 오버레이로 확장자 라벨 표시
  지원: srt, smi, ass, ssa, vtt, sub
- TS 파일: TypeScript가 아닌 MPEG-TS 동영상으로 취급
