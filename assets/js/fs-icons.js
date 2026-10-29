/**
 * FileStation 선 그림 아이콘 — 브라우저 쪽 (2026-10-26)
 *
 * 모양 원본: api/FsIcons.php (index.php·share.php 가 <body> 바로 뒤에 <symbol> 묶음을 넣고,
 *            이모지 표를 window.FS_ICO_EMOJI 로 넘긴 뒤 이 파일을 읽음)
 *
 *  - fsIco('folder')            → 글자색을 따르는 <svg class="fs-ico"><use href="#fsi-folder"></svg> 문자열
 *  - fsIcoFrom('📁', 'folder')   → 데이터에 이모지로 저장된 아이콘(스토리지 종류·게시판 등)을 같은 아이콘으로. 아이콘 이름을 넘겨도 됨. 모르는 값은 글자 그대로(이스케이프)
 *  - fsIcoStrip('✅ 저장됨')     → 이모지를 빼고 글자만 (알림(토스트) 문구 — 펜닐: 알림 안 기호는 빼고 글자만)
 *  - 화면 자동 변환: 스크립트가 화면에 넣는 HTML·글자 속 이모지를 넣는 즉시(그려지기 전) 선 그림으로 바꿈.
 *      건너뜀: 입력칸·선택 목록·코드·스크립트·편집 영역, 파일·폴더 이름, 게시글 제목·본문·댓글, 사용자 이름 같은 사용자가 쓴 글자,
 *      알림(토스트), .fs-noico 를 붙인 곳. 선택 목록(<option>)은 그림을 못 넣어 이모지를 빼고 글자만.
 */
(function () {
    'use strict';
    var MAP = window.FS_ICO_EMOJI || {};
    var SVGNS = 'http://www.w3.org/2000/svg';

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function cleanCls(c) { return c ? String(c).replace(/[^\w -]/g, '') : ''; }

    window.fsIco = function (name, extraClass) {
        if (!name || !/^[a-z0-9-]+$/.test(name)) return '';
        var c = cleanCls(extraClass);
        return '<svg class="fs-ico' + (c ? ' ' + c : '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#fsi-' + name + '"></use></svg>';
    };

    window.fsIcoFrom = function (emoji, fallbackName) {
        var raw = (emoji == null ? '' : String(emoji)).replace(/️/g, '').trim();
        if (!raw) return window.fsIco(fallbackName || 'folder');
        var hit = MAP[raw];
        if (hit) return window.fsIco(hit[0], hit[1]);
        if (/^[a-z][a-z0-9-]*$/.test(raw) && document.getElementById('fsi-' + raw)) return window.fsIco(raw);   // 아이콘 이름 직접
        return esc(emoji);
    };

    // 표의 이모지(뒤 U+FE0F 포함)를 찾는 정규식 — 긴 것부터
    var keys = Object.keys(MAP).sort(function (a, b) { return b.length - a.length; });
    var reSrc = keys.length ? '(' + keys.map(function (k) { return k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|') + ')\\uFE0F?' : null;
    var RE_G = reSrc ? new RegExp(reSrc, 'g') : null;
    var RE_T = reSrc ? new RegExp(reSrc) : null;
    var FLAG_G = /(?:\uD83C[\uDDE6-\uDDFF]){2}️?/g;

    window.fsIcoStrip = function (text) {
        if (typeof text !== 'string' || !RE_G) return text;
        var t = text.replace(RE_G, '').replace(FLAG_G, '');
        if (t === text) return text;
        return t.replace(/^[ \t]+|[ \t]+$/g, '').replace(/([^\s])[ \t]{2,}/g, '$1 ');
    };

    if (!RE_G || typeof MutationObserver === 'undefined') return;

    // 바꾸지 않을 곳 — 사용자가 쓴 글자(파일 이름·게시글·댓글·사용자 이름 등)·입력·코드·알림
    //   ★ (2026-10-26 오후) 이름과 앱 아이콘이 한 칸에 같이 들어가는 칸(.file-name-wrap 의 즐겨찾기·잠금 배지, 게시판 제목 칸의 📌🔔📎,
    //     속도 제한의 👤🏷️, 게시판 머리줄 .breadcrumb 의 📋)은 칸 전체를 건너뛰면 아이콘이 이모지로 남았음(펜닐 제보) → 칸 대신
    //     안의 사용자 글자만 .fs-noico 로 감싸 건너뛰게 바꿈
    var SKIP = [
        'script', 'style', 'noscript', 'textarea', 'select', 'option', 'code', 'pre', 'svg', 'title',
        '[contenteditable=""]', '[contenteditable="true"]', '.fs-noico', '#toast', '.toast', '.cke',
        '.file-name', '.trash-item-name', '.share-filename', '.ishare-filename',
        '.upload-session-filename', '.bulk-item-name', '.board-detail-title', '.board-detail-body', '.cmt-body',
        '#breadcrumb', '.storage-name', '.logo-text', '.user-welcome', '.info-value', '.notify-item-text',
        // ★ (2026-10-28 18:04, 소스 점검 4단계) 오늘 새로 생긴 사용자 글자 칸 — 삭제 확인 창 이름, 검색 결과 경로·스토리지 이름, 모바일 검색어 칸
        //   (이름 속 이모지 '🎵 노래.mp3' 가 선 그림으로 바뀌어 사용자가 쓴 이름과 다르게 보였음)
        '.fs-del-name', '.search-path', '.search-storage', '.sr-qfield-text',
        // ★ (2026-10-29 11:19, 20차 점검) 음악 플레이어의 사용자 글자(곡 제목·목록 곡 이름·가사) — 종전엔 이름·가사 속 이모지가 선 그림으로 바뀌었음(재현)
        '.fap-now-title', '.fap-pl-name', '.fap-lyrics-content', '.fap-lyrics-scroll', '.fap-lyrics-modal-text', '.fap-lyrics-modal-track',
        // ★ (2026-10-29 21:57, 24차 점검) 동영상 스킨의 ⚙ 메뉴 항목 이름·값(자막·음성 트랙 이름)과 짧은 알림('자막: 파일 이름') — 이름 속 이모지가 선 그림으로 바뀌었음(20차 음악 플레이어와 같은 누락)
        //   22:39 — '.fsvs-ml'(항목 이름 칸) 통째 제외는 앱 기호('↺ 싱크 0초로')까지 글자로 남겨 빼고, 사용자 이름이 들어가는 선택지 줄에만 fs-noico(fs-video-skin.js optRow)
        '.fsvs-mv', '.fsvs-volosd'
    ].join(',');

    function makeSvg(name, cls) {
        var svg = document.createElementNS(SVGNS, 'svg');
        svg.setAttribute('class', 'fs-ico' + (cls ? ' ' + cls : ''));
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        var use = document.createElementNS(SVGNS, 'use');
        use.setAttribute('href', '#fsi-' + name);
        svg.appendChild(use);
        return svg;
    }

    function convertText(tn) {
        var txt = tn.nodeValue, parent = tn.parentNode;
        if (!txt || !parent || !RE_T.test(txt)) return;
        var frag = document.createDocumentFragment(), last = 0, m;
        RE_G.lastIndex = 0;
        while ((m = RE_G.exec(txt)) !== null) {
            var hit = MAP[m[1]];
            if (!hit) continue;
            if (m.index > last) frag.appendChild(document.createTextNode(txt.slice(last, m.index)));
            frag.appendChild(makeSvg(hit[0], hit[1]));
            last = m.index + m[0].length;
        }
        if (last === 0) return;
        if (last < txt.length) frag.appendChild(document.createTextNode(txt.slice(last)));
        parent.replaceChild(frag, tn);
    }

    function stripOption(opt) {
        var t = opt.text;
        if (t && (RE_T.test(t) || /\uD83C[\uDDE6-\uDDFF]/.test(t))) {
            var n = window.fsIcoStrip(t);
            if (n !== t) opt.text = n;
        }
    }

    function scan(node) {
        if (!node) return;
        if (node.nodeType === 3) {
            var p = node.parentElement;
            if (!p) return;
            if (p.tagName === 'OPTION') { stripOption(p); return; }
            if (RE_T.test(node.nodeValue) && !p.closest(SKIP)) convertText(node);
            return;
        }
        if (node.nodeType !== 1 && node.nodeType !== 11) return;
        if (node.nodeType === 1) {
            if (node.tagName === 'OPTION') { stripOption(node); return; }
            if (node.tagName === 'SELECT' || node.tagName === 'OPTGROUP') { var os = node.querySelectorAll('option'); for (var i = 0; i < os.length; i++) stripOption(os[i]); return; }
            if (node.closest(SKIP)) return;
            if (node.querySelector) { var sel = node.querySelectorAll('select option'); for (var j = 0; j < sel.length; j++) stripOption(sel[j]); }
        }
        var walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT, {
            acceptNode: function (n) { return RE_T.test(n.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT; }
        });
        var list = [], t;
        while ((t = walker.nextNode())) list.push(t);
        for (var k = 0; k < list.length; k++) {
            var pe = list[k].parentElement;
            if (!pe) continue;
            if (pe.tagName === 'OPTION') { stripOption(pe); continue; }
            if (pe.closest(SKIP)) continue;
            convertText(list[k]);
        }
    }
    window.fsIcoScan = scan;

    var mo = new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
            var m = muts[i];
            if (m.type === 'characterData') { scan(m.target); continue; }
            var a = m.addedNodes;
            for (var j = 0; j < a.length; j++) { if (a[j].isConnected) scan(a[j]); }
        }
    });
    function start() {
        mo.observe(document.documentElement, { childList: true, subtree: true, characterData: true });
        if (document.body) scan(document.body);
    }
    start();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { if (document.body) scan(document.body); });
})();
