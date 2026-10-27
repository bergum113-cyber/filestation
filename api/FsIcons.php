<?php
/**
 * FileStation 선 그림 아이콘 모음 (2026-10-26)
 *
 * 화면 곳곳의 이모지(기기 글꼴이 그리는 컬러 그림 — 테마색을 못 따르고 기기마다 모양이 다름)를
 * 툴바와 같은 선 그림 아이콘으로 바꾸기 위한 한 곳짜리 원본.
 *  - 모양: Feather / Lucide 계열 (ISC·MIT 라이선스), 24×24, 선 굵기 2, 색은 글자색(currentColor)
 *  - index.php 가 <body> 바로 뒤에 fs_icon_sprite() 로 <symbol> 묶음을 한 번 넣고,
 *    PHP 화면은 fsi('folder'), JS 는 fsIco('folder') 로 <svg><use href="#fsi-folder"></svg> 를 만든다.
 *  - 모양을 바꾸거나 더하려면 이 배열만 고치면 PHP·JS 모두 반영된다.
 */

if (!function_exists('fs_icon_paths')) {
    function fs_icon_paths(): array {
        return [
            'folder'        => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
            'folder-open'   => '<path d="m6 14 1.45-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.55 6a2 2 0 0 1-1.94 1.5H4a2 2 0 0 1-2-2V5c0-1.1.9-2 2-2h3.93a2 2 0 0 1 1.66.9l.82 1.2a2 2 0 0 0 1.66.9H18a2 2 0 0 1 2 2v2"/>',
            'folder-plus'   => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/>',
            'folder-lock'   => '<rect x="14" y="17" width="8" height="5" rx="1"/><path d="M10 20H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H20a2 2 0 0 1 2 2v2.5"/><path d="M20 17v-2a2 2 0 1 0-4 0v2"/>',
            'home'          => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'hard-drive'    => '<line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/>',
            'monitor'       => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
            'radio'         => '<circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49m-8.48-.01a6 6 0 0 1 0-8.49m11.31-2.82a10 10 0 0 1 0 14.14m-14.14 0a10 10 0 0 1 0-14.14"/>',
            'cloud'         => '<path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>',
            'globe'         => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            'map-pin'       => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
            'eye'           => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'edit'          => '<path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>',
            'file'          => '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>',
            'file-text'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
            'download'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'upload'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
            'save'          => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
            'link'          => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'user'          => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'user-plus'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
            'star'          => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'star-off'      => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/><line x1="3" y1="3" x2="21" y2="21"/>',
            'lock'          => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'unlock'        => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
            'copy'          => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'scissors'      => '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/>',
            'clipboard'     => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
            'clipboard-list'=> '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="15" y2="16"/>',
            'pin'           => '<line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24z"/>',
            'package'       => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
            'archive'       => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
            'film'          => '<rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="17" y1="2" x2="17" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="2" y1="7" x2="7" y2="7"/><line x1="2" y1="17" x2="7" y2="17"/><line x1="17" y1="17" x2="22" y2="17"/><line x1="17" y1="7" x2="22" y2="7"/>',
            'eraser'        => '<path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21"/><path d="M22 21H7"/><path d="m5 11 9 9"/>',
            'history'       => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
            'info'          => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
            'trash'         => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
            'refresh'       => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'key'           => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
            'shield-check'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
            'palette'       => '<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.65-.75 1.65-1.69 0-.44-.18-.84-.44-1.13-.29-.29-.44-.65-.44-1.13a1.64 1.64 0 0 1 1.67-1.67h2c3.05 0 5.55-2.5 5.55-5.55C21.97 6.01 17.46 2 12 2z"/><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/>',
            'smartphone'    => '<rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
            'mail'          => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
            'calendar'      => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'settings'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'bell'          => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            'search'        => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'lightbulb'     => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"/>',
            'inbox'         => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'clock'         => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'users'         => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'tag'           => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
            'user-x'        => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="18" y1="8" x2="23" y2="13"/><line x1="23" y1="8" x2="18" y2="13"/>',
            'bar-chart'     => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            'shield'        => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'megaphone'     => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
            'wrench'        => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            'x'             => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'sliders'       => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
            'check-circle'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'x-circle'      => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
            'alert-triangle'=> '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'alert-octagon' => '<polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
            'hourglass'     => '<path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.17a2 2 0 0 0-.59-1.42L12 12l-4.41 4.41A2 2 0 0 0 7 17.83V22"/><path d="M7 2v4.17a2 2 0 0 0 .59 1.42L12 12l4.41-4.41A2 2 0 0 0 17 6.17V2"/>',
            'zap'           => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
            'image'         => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'music'         => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
            'plus'          => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'minus'         => '<line x1="5" y1="12" x2="19" y2="12"/>',
            'undo'          => '<polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>',
            'arrow-down'    => '<line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/>',
            'arrow-up'      => '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>',
            'arrow-left'    => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'maximize'      => '<path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>',
            'ban'           => '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
            'flask'         => '<path d="M10 2v7.53a2 2 0 0 1-.21.9L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.07-10.12a2 2 0 0 1-.21-.9V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/>',
            'repeat'        => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
            'volume'        => '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/>',
            'volume-x'      => '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/>',
            'code'          => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
            'paperclip'     => '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
            'book-open'     => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
            'book'          => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
            'sparkles'      => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/>',
            'rotate-ccw'    => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
            'ruler'         => '<path d="M21.3 15.3a2.4 2.4 0 0 1 0 3.4l-2.6 2.6a2.4 2.4 0 0 1-3.4 0L2.7 8.7a2.41 2.41 0 0 1 0-3.4l2.6-2.6a2.41 2.41 0 0 1 3.4 0Z"/><path d="m14.5 12.5 2-2"/><path d="m11.5 9.5 2-2"/><path d="m8.5 6.5 2-2"/><path d="m17.5 15.5 2-2"/>',
            'square'        => '<rect x="5" y="5" width="14" height="14" rx="1"/>',
            'pause'         => '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>',
            'log-out'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
            'plug'          => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
            'camera'        => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
            'cpu'           => '<rect x="4" y="4" width="16" height="16" rx="2" ry="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>',
            'server'        => '<rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>',
            'target'        => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
            'map'           => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>',
            'moon'          => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
            'snowflake'     => '<line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/><path d="m20 16-4-4 4-4"/><path d="m4 8 4 4-4 4"/><path d="m16 4-4 4-4-4"/><path d="m8 20 4-4 4 4"/>',
            'sunrise'       => '<path d="M17 18a5 5 0 0 0-10 0"/><line x1="12" y1="2" x2="12" y2="9"/><line x1="4.22" y1="10.22" x2="5.64" y2="11.64"/><line x1="1" y1="18" x2="3" y2="18"/><line x1="21" y1="18" x2="23" y2="18"/><line x1="18.36" y1="11.64" x2="19.78" y2="10.22"/><line x1="23" y1="22" x2="1" y2="22"/><polyline points="8 6 12 2 16 6"/>',
            'building'      => '<rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
            'dot'           => '<circle cx="12" cy="12" r="6" fill="currentColor" stroke="none"/>',
            'check'         => '<polyline points="20 6 9 17 4 12"/>',
            'chevron-left'  => '<polyline points="15 18 9 12 15 6"/>',
            'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
            'type'          => '<polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>',
            'printer'       => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
            'network'       => '<rect x="16" y="16" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="9" y="2" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"/><path d="M12 12V8"/>',
            'server-lock'   => '<rect x="2" y="2" width="20" height="8" rx="2"/><path d="M12 22H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h8"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/><rect x="14.5" y="17" width="7.5" height="5" rx="1"/><path d="M16 17v-1.5a2.25 2.25 0 0 1 4.5 0V17"/>',
            'message'       => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        ];
    }
}

if (!function_exists('fs_icon_sprite')) {
    /** <body> 바로 뒤에 한 번 출력하는 숨은 <symbol> 묶음 */
    function fs_icon_sprite(): string {
        $out = '<svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false">';
        foreach (fs_icon_paths() as $name => $body) {
            $out .= '<symbol id="fsi-' . $name . '" viewBox="0 0 24 24">' . $body . '</symbol>';
        }
        return $out . '</svg>';
    }
}

if (!function_exists('fsi')) {
    /** 아이콘 하나 — 글자색을 따르는 선 그림. 모르는 이름이면 빈 문자열 */
    function fsi(string $name, string $extraClass = ''): string {
        static $names = null;
        if ($names === null) $names = fs_icon_paths();
        if (!isset($names[$name])) return '';
        $cls = 'fs-ico' . ($extraClass !== '' ? ' ' . htmlspecialchars($extraClass, ENT_QUOTES) : '');
        return '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#fsi-' . $name . '"></use></svg>';
    }
}

if (!function_exists('fs_icon_emoji_map')) {
    /**
     * 이모지 → [아이콘 이름, 추가 class] (2026-10-26, 2단계)
     *  - 서버 출력(fs_icon_convert_html)과 브라우저(assets/js/fs-icons.js, window.FS_ICO_EMOJI 로 전달)가 같은 표를 씀
     *  - 화살표(→←↑↓)·삼각형(▲▼▶◀▾)·⌘·♪ 처럼 글자 흐름 속 기호는 넣지 않음(그대로 둠). ✕·✓·★·❮❯ 는 같은 모양 선 그림으로
 *  - 색이 뜻인 상태 점(🟢🔴⚪🔷)·필터 색 하트(💜💙🧡⚫)는 같은 색의 점(fs-dot-*)으로
     *  - ✅·❌·⚠️ 등은 뜻이 색이라 fs-ico-ok / fs-ico-err / fs-ico-warn 로 색을 붙임
     */
    function fs_icon_emoji_map(): array {
        return [
            '📁'=>['folder',''], '📂'=>['folder-open',''], '🏠'=>['home',''], '🖥'=>['monitor',''], '💻'=>['monitor',''], '📺'=>['monitor',''],
            '🐧'=>['monitor',''], '🍎'=>['monitor',''], '📡'=>['radio',''], '🔒'=>['lock',''], '🔐'=>['lock',''], '🔓'=>['unlock',''],
            '🌐'=>['globe',''], '🌍'=>['map-pin',''], '☁'=>['cloud',''], '💾'=>['save',''], '👤'=>['user',''], '👥'=>['users',''], '👻'=>['user-x',''],
            '🔔'=>['bell',''], '📥'=>['download',''], '⬇'=>['arrow-down',''], '📤'=>['upload',''], '⬆'=>['arrow-up',''], '⬅'=>['arrow-left',''], '🔙'=>['arrow-left',''],
            '🔍'=>['search',''], '💬'=>['message',''], '👁'=>['eye',''], '✏'=>['edit',''], '📋'=>['clipboard',''], '✂'=>['scissors',''], '📌'=>['pin',''],
            'ℹ'=>['info','fs-ico-info'], '🗑'=>['trash',''], '♻'=>['refresh',''], '⭐'=>['star','fs-ico-star'], '☆'=>['star-off',''], '🔗'=>['link',''], '📄'=>['file',''], '📝'=>['file-text',''],
            '📦'=>['package',''], '🐳'=>['package',''], '🗜'=>['archive',''], '🎬'=>['film',''], '🎞'=>['film',''], '🧹'=>['eraser',''], '📜'=>['history',''],
            '🔄'=>['refresh',''], '🔁'=>['repeat',''], '↺'=>['rotate-ccw',''], '↩'=>['undo',''], '🔑'=>['key',''], '🎨'=>['palette',''], '📱'=>['smartphone',''],
            '📧'=>['mail',''], '📨'=>['inbox',''], '📭'=>['inbox',''], '📅'=>['calendar',''], '📆'=>['calendar',''], '⚙'=>['settings',''], '🔧'=>['wrench',''], '🛠'=>['wrench',''],
            '💡'=>['lightbulb',''], '🕐'=>['clock',''], '⏱'=>['clock',''], '⏰'=>['clock',''], '🏷'=>['tag',''], '📊'=>['bar-chart',''], '📈'=>['bar-chart',''],
            '🛡'=>['shield',''], '📢'=>['megaphone',''], '💽'=>['hard-drive',''], '🗄'=>['server',''], '🔌'=>['plug',''], '📷'=>['camera',''], '🖼'=>['image',''],
            '🎵'=>['music',''], '🔊'=>['volume',''], '🔇'=>['volume-x',''], '⛶'=>['maximize',''], '⏸'=>['pause',''], '⏹'=>['square',''],
            '➕'=>['plus',''], '➖'=>['minus',''], '⚡'=>['zap',''], '⏳'=>['hourglass',''], '🧪'=>['flask',''], '📎'=>['paperclip',''],
            '📖'=>['book-open',''], '📘'=>['book',''], '📗'=>['book',''], '📙'=>['book',''], '📕'=>['book',''], '🎉'=>['sparkles',''], '🆕'=>['sparkles',''],
            '📐'=>['ruler',''], '📏'=>['ruler',''], '📍'=>['map-pin',''], '🗺'=>['map',''], '🚪'=>['log-out',''], '🧠'=>['cpu',''], '🎯'=>['target',''],
            '🐘'=>['code',''], '🔤'=>['type',''], '🖨'=>['printer',''], '🌓'=>['moon',''], '✖'=>['x',''], '🪟'=>['monitor',''], '⬛'=>['square',''], '🔲'=>['square',''], '🌟'=>['sparkles',''], '🌙'=>['moon',''],
            '✕'=>['x',''], '✓'=>['check',''], '✔'=>['check',''], '★'=>['star',''], '❮'=>['chevron-left',''], '❯'=>['chevron-right',''],
            '🌫'=>['cloud',''], '❄'=>['snowflake',''], '🌅'=>['sunrise',''], '🌈'=>['palette',''], '🗨'=>['message',''], '📣'=>['megaphone',''], '🪪'=>['key',''], '🏢'=>['building',''],
            // 색이 뜻인 점·하트(상태 표시·배경 필터 색) → 같은 색의 점
            '🟢'=>['dot','fs-dot-green'], '🔴'=>['dot','fs-dot-red'], '⚪'=>['dot','fs-dot-gray'], '🔷'=>['dot','fs-dot-blue'],
            '💜'=>['dot','fs-dot-purple'], '💙'=>['dot','fs-dot-blue'], '🧡'=>['dot','fs-dot-orange'], '⚫'=>['dot','fs-dot-black'],
            '✅'=>['check-circle','fs-ico-ok'], '❌'=>['x-circle','fs-ico-err'], '⚠'=>['alert-triangle','fs-ico-warn'],
            '🚫'=>['ban','fs-ico-err'], '⛔'=>['ban','fs-ico-err'], '✋'=>['ban','fs-ico-err'], '🚨'=>['alert-octagon','fs-ico-err'],
        ];
    }
}

if (!function_exists('fs_icon_emoji_regex')) {
    /** 표의 이모지(뒤 변형 선택자 U+FE0F 포함)를 찾는 정규식. 국기(🇰🇷 등 두 글자)는 옵션 글자 정리용으로 따로 잡음 */
    function fs_icon_emoji_regex(): string {
        static $re = null;
        if ($re === null) {
            $keys = array_keys(fs_icon_emoji_map());
            usort($keys, function ($a, $b) { return strlen($b) - strlen($a); });
            $re = '/(' . implode('|', array_map(function ($k) { return preg_quote($k, '/'); }, $keys)) . ')\x{FE0F}?/u';
        }
        return $re;
    }
}

if (!function_exists('fs_icon_strip')) {
    /** 글자만 남기기(선택 목록 <option> 등 그림을 못 넣는 곳) — 표의 이모지·국기 제거 후 공백 정리 */
    function fs_icon_strip(string $text): string {
        $t = preg_replace(fs_icon_emoji_regex(), '', $text);
        $t = preg_replace('/[\x{1F1E6}-\x{1F1FF}]{2}\x{FE0F}?/u', '', $t);
        $t = preg_replace('/^[ \t]+/u', '', $t);
        $t = preg_replace('/[ \t]+$/u', '', $t);
        return preg_replace('/(?<=\S)[ \t]{2,}/u', ' ', $t) ?? $text;
    }
}

if (!function_exists('fs_icon_convert_html')) {
    /**
     * 출력 버퍼 필터(ob_start) — 화면 HTML 의 글자 부분에 있는 이모지를 선 그림으로 (2026-10-26, 2단계)
     *  - 태그 속성·<script>·<style>·<textarea>·<title> 안은 건드리지 않음(스크립트 안의 HTML 조각은 브라우저 쪽 fs-icons.js 가 화면에 들어갈 때 바꿈)
     *  - <option> 글자는 그림을 넣을 수 없어 이모지를 빼고 글자만
     *  - HTML 문서가 아니면(JSON 등) 그대로 돌려줌
     */
    function fs_icon_convert_html(string $html): string {
        if (stripos($html, '<html') === false || !preg_match('//u', $html)) return $html;   // HTML 아님 / 잘못된 UTF-8 → 그대로
        $map = fs_icon_emoji_map();
        $re  = fs_icon_emoji_regex();
        $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return $html;
        $skip = null;      // 지금 안에 있는 건너뛸 태그 이름
        $inOption = false;
        foreach ($parts as $i => $p) {
            if ($p === '') continue;
            if ($p[0] === '<') {
                if (preg_match('/^<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9-]*)/', $p, $m)) {
                    $tag = strtolower($m[2]); $close = $m[1] === '/';
                    if ($skip !== null) { if ($close && $tag === $skip) $skip = null; continue; }
                    if (!$close && in_array($tag, ['script', 'style', 'textarea', 'title'], true) && substr(rtrim($p), -2) !== '/>') { $skip = $tag; continue; }
                    if ($tag === 'option') $inOption = !$close;
                    elseif ($tag === 'select' || $tag === 'optgroup' && $close) $inOption = false;
                }
                continue;
            }
            if ($skip !== null) continue;
            if (strpos($p, "\xF0") === false && !preg_match('/[\x{2139}\x{2190}-\x{2BFF}]/u', $p)) continue;   // 이모지 없음 — 빠르게 건너뜀
            if ($inOption) { $parts[$i] = fs_icon_strip($p); continue; }
            $parts[$i] = preg_replace_callback($re, function ($mm) use ($map) {
                $k = $mm[1]; return isset($map[$k]) ? fsi($map[$k][0], $map[$k][1]) : $mm[0];
            }, $p);
        }
        return implode('', $parts);
    }
}

if (!function_exists('fs_icon_map_json')) {
    /** 브라우저 쪽(fs-icons.js)에 같은 이모지 표를 넘기는 한 줄 — { "📁": ["folder",""], ... } */
    function fs_icon_map_json(): string {
        return json_encode(fs_icon_emoji_map(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}

if (!function_exists('fsi_inline')) {
    /**
     * ★ (2026-10-26) 묶음(<symbol>)이 없는 짧은 화면용 — 모양을 직접 담은 아이콘(예: 뷰어의 403 '접근 권한 없음' 화면).
     * 일반 화면은 fsi() 를 씀.
     */
    function fsi_inline(string $name, string $size = '1.2em'): string {
        $p = fs_icon_paths();
        if (!isset($p[$name])) return '';
        $sz = htmlspecialchars($size, ENT_QUOTES);
        return '<svg width="' . $sz . '" height="' . $sz . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.2em">' . $p[$name] . '</svg>';
    }
}
