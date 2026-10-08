/**
 * FSVideoSkin — FileStation 동영상 플레이어 새 껍데기 (A 겹침형)
 *
 * ★ (2026-09-23) 탐색기·공유 × 일반 재생·스트리밍. 기본 켜짐(끄기: 주소에 ?vskin=0).
 *
 * [원칙]
 *  1) 기능을 새로 구현하지 않는다. 속도·반복·구간 반복·자막·화질·재생방식·PIP·전체화면은
 *     **기존 버튼/셀렉트를 대신 눌러준다**(ctx 로 받은 함수가 기존 요소를 click/change 한다).
 *     그래서 그동안 고친 로직(구간 반복 스트리밍 숨김, 자막 On/Off 의 iOS 게이트 등)이 그대로 산다.
 *  2) 재생·일시정지·탐색·음량은 <video> 표준 API 만 쓴다. 브라우저 기본 컨트롤이 하던 일과 같아서
 *     기존 코드가 듣는 play/pause/seeking/seeked 이벤트가 똑같이 발생한다.
 *  3) 스트리밍(트랜스코딩·HLS)에는 붙지 않는다. 재생 중에 스트리밍으로 바뀌면(ctx.isStreaming)
 *     **스스로 물러나** 기본 컨트롤을 되돌린다 — 스트리밍은 길이·탐색이 특수 처리되기 때문.
 *  4) 되돌리기 쉽게: detach() 하면 흔적 없이 원래 상태로 돌아간다.
 *
 * [사용]
 *  FSVideoSkin.attach({ video, wrap, ctx })   // 같은 wrap 에 다시 붙이면 이전 것을 먼저 뗀다
 *  FSVideoSkin.detach(wrap)
 */
(function () {
    'use strict';
    if (window.FSVideoSkin) return;

    // ★ (2026-09-23) 번역: 탐색기는 window.t, 공유 페이지는 JS 번역 함수가 없어 PHP 가 넣어 준 window.LANG_STRINGS 를 직접 읽는다.
    const T = (k, d) => {
        try {
            if (typeof window.t === 'function') return window.t(k, d);
            if (window.LANG_STRINGS && window.LANG_STRINGS[k] !== undefined) return window.LANG_STRINGS[k];
        } catch (e) {}
        return d;
    };
    const SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 2];

    const ICON = {
        play:  '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>',
        pause: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>',
        vol:   '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 9a4 4 0 010 6M18.5 6.5a7.5 7.5 0 010 11"/></svg>',
        mute:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M16 9.5l5 5M21 9.5l-5 5"/></svg>',
        loop:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3l3 3-3 3"/><path d="M4 11V9.5A3.5 3.5 0 017.5 6H20"/><path d="M7 21l-3-3 3-3"/><path d="M20 13v1.5a3.5 3.5 0 01-3.5 3.5H4"/></svg>',
        gear:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/></svg>',
        pip:   '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><rect x="12" y="11" width="7" height="6" rx="1" fill="currentColor"/></svg>',
        prev:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M6 5h2v14H6zM20 5.5v13L9.5 12z"/></svg>',
        next:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M16 5h2v14h-2zM4 5.5v13L14.5 12z"/></svg>',
        close: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
        info:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5"/><circle cx="12" cy="7.8" r="0.6" fill="currentColor"/></svg>',
        fs:    '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5"/></svg>'
    };

    const fmt = (s) => {
        if (!isFinite(s) || s < 0) s = 0;
        s = Math.floor(s);
        const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
        const mm = (h ? String(m).padStart(2, '0') : String(m));
        return (h ? h + ':' : '') + mm + ':' + String(x).padStart(2, '0');
    };
    const el = (tag, cls, html) => { const e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; };
    const btn = (cls, html, label) => {
        const b = el('button', 'fsvs-btn ' + (cls || ''), html);
        b.type = 'button';
        if (label) { b.setAttribute('aria-label', label); b.title = label; }
        return b;
    };

    function detach(wrap) {
        if (!wrap || !wrap._fsvs) return;
        try { wrap._fsvs.destroy(); } catch (e) {}
        wrap._fsvs = null;
    }

    function attach(opts) {
        const video = opts && opts.video, wrap = opts && opts.wrap, ctx = (opts && opts.ctx) || {};
        if (!video || !wrap) return null;
        detach(wrap);

        const cleanups = [];
        const on = (target, ev, fn, o) => { target.addEventListener(ev, fn, o); cleanups.push(() => target.removeEventListener(ev, fn, o)); };
        const stop = (e) => { e.stopPropagation(); };

        // ── 기본 컨트롤 끄기 (다른 코드가 다시 켜면 되끈다) ────────────────────
        const hadControls = video.hasAttribute('controls');
        video.controls = false;
        const mo = new MutationObserver(() => { if (video.hasAttribute('controls')) video.controls = false; });
        mo.observe(video, { attributes: true, attributeFilter: ['controls'] });
        cleanups.push(() => mo.disconnect());

        // ★ (2026-09-29) 오류 배지는 줄이지 않는다(펜닐 요청) — 배지(탐색기 .video-stream-badge·공유 .stream-badge)는 폭이 모자라면 한 줄 말줄임이지만
        //   오류 문구('❌' 로 시작 — 탐색기 14곳·공유 3곳)는 전부 보이게 fsvs-badge-error 를 붙여 줄바꿈을 허용한다(CSS). 문구를 넣는 곳을 하나하나
        //   고치지 않고 틀 안의 변화를 지켜본다. 탐색기는 배지를 바꿀 때 className 을 통째로 다시 써 표시가 지워질 수 있어 class 변화도 본다 —
        //   이미 맞는 상태면 classList.toggle 이 아무것도 바꾸지 않아 되먹임 반복이 없다. 다음 프레임에 한 번만, 떼어낼 때 해제.
        const markErrorBadges = () => {
            try { wrap.querySelectorAll('.video-stream-badge, .stream-badge').forEach((b) => { b.classList.toggle('fsvs-badge-error', /^\s*❌/.test(b.textContent || '')); }); } catch (e) {}
        };
        let badgeRaf = 0;
        const badgeMo = new MutationObserver(() => {
            if (badgeRaf) return;
            badgeRaf = requestAnimationFrame(() => { badgeRaf = 0; markErrorBadges(); });
        });
        badgeMo.observe(wrap, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class'] });
        markErrorBadges();
        cleanups.push(() => {
            badgeMo.disconnect();
            if (badgeRaf) cancelAnimationFrame(badgeRaf);
            try { wrap.querySelectorAll('.fsvs-badge-error').forEach((b) => b.classList.remove('fsvs-badge-error')); } catch (e) {}
        });

        // ── DOM ─────────────────────────────────────────────────────────────
        const root = el('div', 'fsvs-root');
        // ★ (2026-09-26) iOS(아이폰·아이패드)는 웹에서 볼륨 값을 바꿀 수 없어(읽기 전용) 슬라이더를 늘 숨긴다 — CSS .fsvs-ios.
        //   종전엔 폭 600px 이하에서만 숨겨 아이폰 가로 화면(844px)엔 움직여도 소용없는 슬라이더가 보였다. 볼륨 버튼(음소거)은 그대로.
        if (/iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) root.classList.add('fsvs-ios');
        const menu = el('div', 'fsvs-menu');
        // ★ (2026-09-23) 설정 창 여닫기는 반드시 이 함수로 — hidden 속성과 인라인 display 를 함께 바꾼다.
        //   [결함] CSS 의 두 칸 배치 규칙(display: grid)이 숨김 규칙과 우선순위가 같고 뒤에 있어 이겼다 →
        //   가로모드(폭이 넓어 두 칸)에서 ⚙·✕ 로 닫아도 창이 계속 보였다(펜닐 제보). CSS 에도 !important 를 넣었지만
        //   인라인 스타일은 스타일시트 순서·우선순위와 무관하게 이기므로 이중으로 막는다.
        // ★ (2026-10-08) 열려 있는 동안 wrap 에 fsvs-menu-open — 공유 페이지가 설정 창을 플레이어 위로 넘치게 보이도록(overflow) 쓴다
        const setMenuOpen = (open) => { menu.hidden = !open; menu.style.display = open ? '' : 'none'; wrap.classList.toggle('fsvs-menu-open', !!open); };
        setMenuOpen(false);
        const bar = el('div', 'fsvs-bar');
        const prog = el('div', 'fsvs-prog');
        const track = el('div', 'fsvs-track');
        const buf = el('div', 'fsvs-buf');
        const ab = el('div', 'fsvs-abband');
        const played = el('div', 'fsvs-played');
        const thumb = el('div', 'fsvs-thumb');
        track.append(buf, ab, played, thumb);
        prog.append(track);
        prog.setAttribute('role', 'slider');
        prog.setAttribute('aria-label', T('seek', '재생 위치'));
        prog.tabIndex = 0;

        const row = el('div', 'fsvs-row');
        // ★ (2026-09-23) 이전·다음 영상 — 같은 폴더에 재생할 영상이 여러 개(목록이 있을 때)만 보인다(펜닐 지시).
        const bPrev = btn('fsvs-prev', ICON.prev, T('prev_video', '이전 영상'));
        const bPlay = btn('fsvs-play', ICON.pause, T('pause', '일시정지'));
        const bNext = btn('fsvs-next', ICON.next, T('next_video', '다음 영상'));
        const bVol = btn('fsvs-vol', ICON.vol, T('mute', '음소거'));
        const vol = el('input', 'fsvs-volrange');
        vol.type = 'range'; vol.min = '0'; vol.max = '1'; vol.step = '0.01';
        vol.setAttribute('aria-label', T('volume', '음량'));
        const time = el('div', 'fsvs-time', '0:00 / 0:00');
        const spacer = el('div', 'fsvs-spacer');
        const bSpeed = btn('fsvs-speedchip', '1x', T('playback_speed', '재생 속도'));
        const bLoop = btn('fsvs-loop', ICON.loop, '');
        const bAb = btn('fsvs-ab', 'A-B', '');
        const bCc = btn('fsvs-cc', 'CC', '');
        // ★ (2026-10-07) 동영상 정보(ⓘ) — 페이지가 ctx.showInfo 를 줄 때만(탐색기). 공유 페이지는 숨김.
        const bInfo = btn('fsvs-info', ICON.info, T('vi_dlg_title', '동영상 정보') + ' (I)');
        const hasInfo = typeof ctx.showInfo === 'function';
        if (!hasInfo) bInfo.style.display = 'none';
        const bSet = btn('fsvs-set', ICON.gear, T('settings', '설정'));
        const bPip = btn('fsvs-pip', ICON.pip, T('pip', 'PIP 작은 창'));
        const bFs = btn('fsvs-fs', ICON.fs, T('fullscreen', '전체화면'));
        row.append(bPrev, bPlay, bNext, bVol, vol, time, spacer, bSpeed, bLoop, bAb, bCc, bInfo, bSet, bPip, bFs);
        bar.append(prog, row);
        // ★ (2026-09-23) 가운데 재생 버튼은 만들지 않는다 — 기존 플레이어의 가운데 버튼으로 충분하고
        //   겹침이 생겼다(펜닐 확인). 재생/일시정지는 조작 줄의 버튼과 기존 가운데 버튼으로 한다.
        root.append(menu, bar);
        wrap.appendChild(root);
        // ★ (2026-10-05) 음량 표시(유튜브처럼, 펜닐 요청) — 키보드로 음량을 바꾸면 위쪽 가운데에 아이콘 + 퍼센트를 1초.
        //   음량 막대(입력칸)를 키보드로 움직이면 스킨이 직접, ↑↓ 키 처리(탐색기·공유)는 FSVideoSkin.showVolume(wrap) 으로 부른다.
        //   ★ (2026-10-08) 마우스·터치로 막대를 움직일 때도 띄운다(펜닐 요청 — 종전엔 키보드만).
        const volOsd = el('div', 'fsvs-volosd');
        volOsd.setAttribute('aria-hidden', 'true');
        root.appendChild(volOsd);
        let volOsdTimer = null;
        const showVolOsd = () => {
            const v = video.muted ? 0 : (Number(video.volume) || 0);
            volOsd.innerHTML = v > 0 ? ICON.vol : ICON.mute;
            const pct = document.createElement('span');
            pct.textContent = Math.round(v * 100) + '%';
            volOsd.appendChild(pct);
            volOsd.classList.add('on');
            if (volOsdTimer) clearTimeout(volOsdTimer);
            volOsdTimer = setTimeout(() => { volOsdTimer = null; volOsd.classList.remove('on'); }, 1000);
        };
        cleanups.push(() => { if (volOsdTimer) clearTimeout(volOsdTimer); volOsdTimer = null; });
        // ★ (2026-10-08) 짧은 알림(자막 있음·자막 켜짐/꺼짐 — 펜닐 요청, 팟플레이어·VLC·mpv 의 OSD 처럼) — 음량 표시와 **같은 자리·같은 상자**를 쓴다.
        //   나중에 뜬 것이 앞의 것을 바로 바꾼다(타이머도 같이 씀). 글자는 textContent 로만 넣는다(파일 이름 등). 음량 표시 코드는 그대로.
        //   opt: { icon: 'cc' (CC 표시), off: true (CC 를 꺼짐 모양으로), ms: 표시 시간(0.5~5초, 기본 1.5초) }
        const showMsgOsd = (text, opt) => {
            opt = opt || {};
            volOsd.textContent = '';
            if (opt.icon === 'cc') {
                const cc = document.createElement('b');
                cc.className = 'fsvs-osd-cc' + (opt.off ? ' is-off' : '');
                cc.textContent = 'CC';
                volOsd.appendChild(cc);
            }
            const sp = document.createElement('span');
            sp.textContent = String(text == null ? '' : text);
            volOsd.appendChild(sp);
            volOsd.classList.add('on', 'fsvs-osd-msg');
            const ms = Math.max(500, Math.min(5000, Number(opt.ms) || 1500));
            if (volOsdTimer) clearTimeout(volOsdTimer);
            volOsdTimer = setTimeout(() => { volOsdTimer = null; volOsd.classList.remove('on'); }, ms);
        };
        wrap.classList.add('fsvs-on');
        document.body.classList.add('fsvs-active');

        // 조작부 안의 클릭이 기존 wrap/video 핸들러(탭으로 재생 토글 등)로 새지 않게 한다
        [bar, menu].forEach((n) => { on(n, 'click', stop); on(n, 'touchstart', stop, { passive: true }); });

        // ── 재생·음량 ────────────────────────────────────────────────────────
        const togglePlay = () => { if (video.paused || video.ended) { const p = video.play(); if (p && p.catch) p.catch(() => {}); } else video.pause(); };
        on(bPlay, 'click', togglePlay);
        on(bVol, 'click', () => { video.muted = !video.muted; });
        on(vol, 'input', () => { video.volume = parseFloat(vol.value); if (video.volume > 0 && video.muted) video.muted = false; });
        on(vol, 'input', showVolOsd);   // ★ (2026-10-08) 막대를 키보드·마우스·터치 어느 것으로 움직여도 음량 표시(바로 위에서 음량을 바꾼 뒤라 새 값으로 그림)

        // ── 시간축 ★ (2026-09-23) 스트리밍 지원 ─────────────────────────────────
        //   트랜스코딩·HLS 는 서버가 변환하면서 조각을 붙이므로 브라우저가 아는 길이가 늘어나거나 무한대이고,
        //   아직 변환 안 된 뒷부분으로는 갈 수 없다. 또 화질·음성 트랙을 바꾸면 &seek=실제시각 으로 중간부터
        //   다시 변환하므로 그 세션에선 currentTime 0 = 실제 N초다(기존 코드가 video._qualitySeekOffset 에 기억).
        //   그래서 스트리밍에서는 '실제 시각 = currentTime + 오프셋', '전체 = 서버가 알려준 실제 길이
        //   (video._knownDuration)', '갈 수 있는 끝 = 오프셋 + 변환된 끝(seekable)' 으로 그린다.
        const isStreaming = () => { try { return !!(ctx.isStreaming && ctx.isStreaming()); } catch (e) { return false; } };
        const offset = () => (isStreaming() ? (ctx.timeOffset ? ctx.timeOffset() : (video._qualitySeekOffset || 0)) || 0 : 0);
        const relEnd = () => {   // 이 세션에서 갈 수 있는 끝(상대 시각)
            let m = 0;
            try { const r = video.seekable; for (let i = 0; i < r.length; i++) m = Math.max(m, r.end(i)); } catch (e) {}
            if (!m) { try { const r = video.buffered; for (let i = 0; i < r.length; i++) m = Math.max(m, r.end(i)); } catch (e) {} }
            return m;
        };
        const total = () => {
            const d = video.duration, known = (ctx.knownDuration ? ctx.knownDuration() : video._knownDuration) || 0;
            if (isStreaming()) return known > 0 ? known : offset() + ((isFinite(d) && d > 0) ? d : relEnd());
            return (isFinite(d) && d > 0) ? d : 0;
        };
        const absNow = () => offset() + (video.currentTime || 0);
        // 실제 시각 T 로 이동. 스트리밍이면 이 세션에서 갈 수 있는 범위로 제한한다(변환 안 된 곳은 아직 못 간다).
        const seekAbs = (T) => {
            if (!isStreaming()) { const d = total(); if (d > 0) video.currentTime = Math.min(Math.max(0, T), d); return; }
            const off = offset(), end = relEnd();
            let rel = T - off;
            rel = Math.max(0, Math.min(rel, end > 0.6 ? end - 0.5 : 0));
            video.currentTime = rel;
        };

        // ── 진행바 (끌기·클릭) ────────────────────────────────────────────────
        let dragging = false, dragRatio = 0;
        const ratioAt = (clientX) => { const r = track.getBoundingClientRect(); return Math.min(1, Math.max(0, (clientX - r.left) / (r.width || 1))); };
        const commit = (ratio) => { const d = total(); if (d > 0) seekAbs(ratio * d); };
        on(prog, 'pointerdown', (e) => {
            if (e.button !== undefined && e.button !== 0) return;
            dragging = true; dragRatio = ratioAt(e.clientX);
            try { prog.setPointerCapture(e.pointerId); } catch (x) {}
            render(); e.preventDefault();
        });
        on(prog, 'pointermove', (e) => { if (!dragging) return; dragRatio = ratioAt(e.clientX); render(); });
        const endDrag = (e) => { if (!dragging) return; dragging = false; commit(ratioAt(e.clientX)); render(); };
        on(prog, 'pointerup', endDrag);
        on(prog, 'pointercancel', () => { dragging = false; render(); });
        // ★ (2026-10-02) 재생바 미리보기(펜닐 지시) — PC 는 마우스를 재생바 위에서 움직일 때, 휴대폰은 끄는 동안 그 위치의 작은 장면과 시간을 띄운다.
        //   · 그 위치로 **실제 이동할 수 있을 때만**: 스트리밍(트랜스코딩)이면 seekAbs 와 같은 범위(이 세션에서 변환된 끝까지) — 갈 수 없는 곳의
        //     장면을 보여주면 이동되는 것처럼 보여서. 빠른 시작·일반 재생은 전체.
        //   · 장면은 ctx.frameUrl(초) — 원본에서 뽑은 그림(서버 videoFrameFile, 10초 단위 캐시). 지원 안 하면(보관함 등) null → 아예 띄우지 않음.
        //   · 시간은 바로, 그림은 0.15초 멈췄을 때 10초 단위로 요청(끌기 중 요청이 쏟아지지 않게). 그림을 못 받으면 그림만 숨김.
        //   · 위 진행바 처리는 그대로 두고 별도로 붙인다(먼저 등록된 위 처리가 dragRatio 를 먼저 갱신). 상자는 pointer-events:none 이라 누르기에 영향 없음.
        const sp = el('div', 'fsvs-seekprev');
        const spImg = document.createElement('img');
        spImg.alt = ''; spImg.draggable = false; spImg.decoding = 'async'; spImg.hidden = true;
        const spTime = el('span', 'fsvs-seekprev-t');
        sp.append(spImg, spTime);
        prog.append(sp);
        let spOn = false;
        // ★ (2026-10-02) 미리 받기 + 가까운 장면 먼저(펜닐 제보: 끌고 멈춰야 바뀜 — 장면을 그 자리에서 만들어 0.1~0.5초 늦게 따라와서).
        //   · 받는 일은 보이지 않는 로더 하나만(동시 1개 — img.src 를 바꾸면 브라우저 요청은 취소돼도 서버 ffmpeg 는 끝까지 돌아 쌓인다;
        //     서버도 동시 2개, videoFrameFile). 받은 장면은 spDone(구간 → 주소)에 두고, 보이는 그림은 거기서 꺼내 바꿔 즉시 나온다(브라우저에 이미 있음).
        //   · 우선순위: 사용자가 지금 보는 구간(spWant) → 미리 받기(spQueue). 미리 받기는 처음 보일 때 영상 전체에 고르게 최대 60구간,
        //     몇 장만 받아도 재생바 전체에 가까운 장면이 생기게 처음·가운데·1/4·3/4 … 순서(비트 반전). 트랜스코딩에서 아직 못 가는 구간은 건너뜀.
        //   · 표시: 그 구간 장면이 있으면 그것, 없으면 받은 것 중 가장 가까운 장면을 바로 — 새 장면이 올 때마다 더 가까운 것으로 바꾼다.
        //   · 실패·바쁨(503): 0.3초 쉬고 다음. 미리 받기에서 실패한 구간은 다시 안 함(사용자가 그 자리로 들어오면 한 번 다시 요청).
        const spLoader = new Image();
        spLoader.decoding = 'async';
        const spDone = new Map(), spFailed = new Set();
        // ★ (2026-10-02) 진단 기록(동작 변경 없음) — 앱의 window._diagLog 가 있을 때만(탐색기 ?hlsdiag=1). 안 뜨는 이유는 이유별 1번,
        //   받기 성공은 처음 3장, 실패는 처음 5번 — 첫 실패 땐 같은 주소를 한 번 더 받아 서버 사유(응답 코드·X-Frame-Error)를 남긴다.
        const spDiag = (data) => { try { if (typeof window._diagLog === 'function') window._diagLog('seekprev', data); } catch (e) {} };
        const spDiagOff = new Set(); let spDiagOk = 0, spDiagFail = 0, spDiagShown = false, spReqAt = 0;
        let spLoadingKey = null, spWant = null, spShownKey = null, spCurKey = null, spQueue = null, spQueueDur = 0;
        let spDead = false, spRetryTimer = null;
        // ★ (2026-10-02) 묶음 미리 받기(펜닐 승인 — 서버 PC 에서 장당 약 0.7초, 대부분 장면마다 반복되는 PHP 요청·ffmpeg 실행 비용).
        //   미리 받기 목록에서 20개씩 ctx.framesUrl 로 보내 서버가 ffmpeg 1번으로 캐시에 만든다(FileManager::videoFramesFile). 결과:
        //   ok → spReady(서버 캐시에 있음 — 보이지 않는 로더가 캐시에서 바로) · fail → 다시 안 함 · fallback → spSingle(한 장씩) ·
        //   left → 목록 앞으로(다음 묶음) · busy(503) → 1초 쉬고(10번 연속이면 5초). 묶음을 지원 안 하면(ctx.framesUrl 없음) 종전 한 장씩.
        //   한 사람이 동시에 쓰는 서버 자리는 최대 2(묶음 1 + 사용자 위치 1) — 캐시에서 받는 것은 자리를 안 쓴다.
        const spReady = [], spSingle = [];
        let spBatchBusy = false, spBatchTimer = null, spBusyRun = 0, spDiagBatch = 0;
        const spDisplay = (key) => {
            const u = spDone.get(key);
            if (!u) return;
            if (spShownKey !== key) { spImg.src = u; spShownKey = key; }
            spImg.hidden = false;
        };
        const spNearest = (key) => {
            let best = null, bd = Infinity;
            for (const k of spDone.keys()) { const dd = Math.abs(k - key); if (dd < bd) { bd = dd; best = k; } }
            return best;
        };
        const spRefresh = () => {   // 지금 위치에 맞는(없으면 가장 가까운) 장면으로
            if (!spOn || spCurKey === null) return;
            if (spDone.has(spCurKey)) spDisplay(spCurKey);
            else { const n = spNearest(spCurKey); if (n !== null) spDisplay(n); }
        };
        const spBuildQueue = (d, step) => {
            // ★ (2026-10-02) 최대 60 → 150장(mpv 미리보기 스크립트 기본값 thumbnail_count=150 — 펜닐 지시로 조사)
            const n = Math.max(1, Math.min(150, Math.ceil(d / step)));
            const keys = [];
            for (let i = 0; i < n; i++) { const k = Math.floor(((i + 0.5) * d / n) / step) * step; if (keys[keys.length - 1] !== k) keys.push(k); }
            let bits = 0; while ((1 << bits) < keys.length) bits++;
            const rev = (i) => { let r = 0; for (let b = 0; b < bits; b++) if (i & (1 << b)) r |= 1 << (bits - 1 - b); return r; };
            spQueue = keys.map((k, i) => [rev(i), k]).sort((a, b) => a[0] - b[0]).map((x) => x[1]);
            spQueueDur = Math.round(d);
        };
        const spPump = () => {   // 쉬는 중이면 다음 것을 받는다 — 사용자가 보는 구간 → 묶음으로 준비된 것 → 한 장씩 할 것 → (묶음 미지원이면) 미리 받기
            if (spDead || spLoadingKey !== null) return;
            let key = null;
            if (spWant !== null && !spDone.has(spWant)) { key = spWant; }
            spWant = null;
            while (key === null && spReady.length) { const k = spReady.shift(); if (!spDone.has(k)) key = k; }
            while (key === null && spSingle.length) { const k = spSingle.shift(); if (!spDone.has(k) && !spFailed.has(k) && canSeekTo(k)) key = k; }
            while (key === null && !framesUrl([0]) && spQueue && spQueue.length) {
                const k = spQueue.shift();
                if (!spDone.has(k) && !spFailed.has(k) && canSeekTo(k)) key = k;
            }
            if (key === null) return;
            const u = frameUrl(key);
            if (!u) return;
            spLoadingKey = key; spReqAt = Date.now();
            spLoader.src = u;
        };
        on(spLoader, 'load', () => {
            const key = spLoadingKey; spLoadingKey = null;
            if (key !== null) { spDone.set(key, spLoader.src); spRefresh(); }
            if (spDiagOk < 3) { spDiagOk++; spDiag({ ev: 'load', key, ms: Date.now() - spReqAt, w: spLoader.naturalWidth }); }
            spPump();
        });
        on(spLoader, 'error', () => {
            const key = spLoadingKey; spLoadingKey = null;
            if (key !== null) spFailed.add(key);
            if (spDiagFail < 5) {
                spDiagFail++;
                const u = spLoader.src, ms = Date.now() - spReqAt, first = spDiagFail === 1;
                if (first && typeof fetch === 'function' && typeof window._diagLog === 'function') {   // 첫 실패만 — 서버 사유 읽기(진단 로그가 켜졌을 때만)
                    fetch(u, { credentials: 'same-origin', cache: 'no-store' })
                        .then((r) => spDiag({ ev: 'fail', key, ms, status: r.status, reason: r.headers.get('X-Frame-Error') || '' }))
                        .catch((e) => spDiag({ ev: 'fail', key, ms, status: 'fetch_error', reason: String(e && e.message || e).slice(0, 80) }));
                } else spDiag({ ev: 'fail', key, ms });
            }
            if (spRetryTimer) clearTimeout(spRetryTimer);
            spRetryTimer = setTimeout(() => { spRetryTimer = null; spPump(); }, 300);   // 바쁨(503)일 수 있어 잠깐 쉬고 다음
        });
        const spBatchPump = () => {   // 묶음 미리 받기 — 하나씩(응답이 오면 다음)
            if (spDead || spBatchBusy || !spQueue || !framesUrl([0])) return;
            const keys = [];
            while (keys.length < 20 && spQueue.length) {
                const k = spQueue.shift();
                if (!spDone.has(k) && !spFailed.has(k) && canSeekTo(k) && spReady.indexOf(k) < 0 && spSingle.indexOf(k) < 0) keys.push(k);
            }
            if (!keys.length) return;
            const u = framesUrl(keys);
            if (!u || typeof fetch !== 'function') { spSingle.push(...keys); spPump(); return; }
            spBatchBusy = true;
            const at = Date.now();
            let busy = false;
            fetch(u, { credentials: 'same-origin', cache: 'no-store' })
                .then((r) => { busy = r.status === 503; return r.json(); })
                .then((j) => {
                    if (spDead) return;
                    const arr = (x) => (Array.isArray(x) ? x.map(Number).filter((v) => isFinite(v)) : []);
                    const ok = arr(j.ok), fail = arr(j.fail), fb = arr(j.fallback), left = arr(j.left);
                    ok.forEach((x) => { if (!spDone.has(x)) spReady.push(x); });
                    fail.forEach((x) => spFailed.add(x));
                    spSingle.push(...fb);
                    if (left.length) spQueue.unshift(...left);
                    const seen = new Set([...ok, ...fail, ...fb, ...left]);
                    keys.forEach((x) => { if (!seen.has(x)) spSingle.push(x); });   // 응답에 빠진 것은 한 장씩
                    busy = busy || !!j.busy;
                    if (spDiagBatch < 10) { spDiagBatch++; spDiag({ ev: 'batch', n: keys.length, ok: ok.length, fail: fail.length, fb: fb.length, left: left.length, made: j.made | 0, ms: j.ms | 0, rtt: Date.now() - at, busy }); }
                })
                .catch(() => { if (!spDead) spSingle.push(...keys); })   // 응답이 이상하면 한 장씩
                .then(() => {
                    spBatchBusy = false;
                    if (spDead) return;
                    spBusyRun = busy ? spBusyRun + 1 : 0;
                    spPump();
                    spBatchTimer = setTimeout(() => { spBatchTimer = null; spBatchPump(); }, busy ? (spBusyRun >= 10 ? 5000 : 1000) : 0);
                });
        };
        cleanups.push(() => { spDead = true; if (spRetryTimer) clearTimeout(spRetryTimer); spRetryTimer = null; if (spBatchTimer) clearTimeout(spBatchTimer); spBatchTimer = null; try { spLoader.removeAttribute('src'); } catch (e) {} });
        const spHide = () => {
            if (spOn) { sp.classList.remove('on'); spOn = false; }
        };
        const canSeekTo = (tt) => {
            if (!isStreaming()) return true;
            const off = offset(), end = relEnd();
            return end > 0.6 && tt >= off && tt <= off + end - 0.5;
        };
        // ★ (2026-10-02) 10분 이하 영상은 a=1(서버 정확 탐색 — 짧은 영상은 키프레임이 드물어 키프레임 장면만으론 첫 장면만 나올 수 있음, 펜닐 제보 8초 영상).
        //   spAcc 는 spShow 가 영상 길이로 정한다. 탐색기·공유 주소는 모두 '?…' 형태라 뒤에 붙인다.
        let spAcc = false;
        const frameUrl = (sec) => { try { const u = (typeof ctx.frameUrl === 'function') ? ctx.frameUrl(sec) : null; return u ? (spAcc ? u + '&a=1' : u) : null; } catch (e) { return null; } };
        const framesUrl = (secs) => { try { const u = (typeof ctx.framesUrl === 'function') ? ctx.framesUrl(secs) : null; return u ? (spAcc ? u + '&a=1' : u) : null; } catch (e) { return null; } };
        const spOff = (why, extra) => { if (!spDiagOff.has(why)) { spDiagOff.add(why); spDiag(Object.assign({ ev: 'off', why }, extra || {})); } spHide(); };
        const spShow = (ratio, clientX) => {
            if (!frameUrl(0)) return spOff('no_url');
            const d = total();
            if (!(d > 0)) return spOff('no_dur', { d: d });
            const tt = Math.min(ratio * d, Math.max(0, d - 0.5));
            if (!canSeekTo(tt)) return spOff('cant_seek', { tt: Math.round(tt * 10) / 10, off: Math.round(offset() * 10) / 10, end: Math.round(relEnd() * 10) / 10 });
            spTime.textContent = fmt(tt);
            if (!spOn) { sp.classList.add('on'); spOn = true; }
            const pr = prog.getBoundingClientRect(), w = sp.offsetWidth || 0;
            let x = clientX - pr.left;
            if (w && pr.width > w) x = Math.max(w / 2, Math.min(pr.width - w / 2, x));   // 재생바 밖으로 넘치지 않게
            sp.style.left = x + 'px';
            // ★ (2026-10-02) 구간 간격을 영상 길이에 맞춤 — 5분 이하 2초 · 30분 이하 5초 · 그 이상 10초(펜닐 제보: 장면이 몇 개 안 나옴, 3분 영상이 10초 간격이면 18장).
            //   장면은 그 시각 바로 앞 키프레임이라 키프레임 간격보다 촘촘하면 같은 장면이 이어 나올 수 있다(서버 videoFrameFile).
            // ★ (2026-10-02) 2분 이하 1초 추가(유튜브 방식을 따른 스크립트: 0~2분 1초·2~5분 2초·5~15분 5초·그 이상 10초)
            const step = d <= 120 ? 1 : (d <= 300 ? 2 : (d <= 1800 ? 5 : 10));
            spAcc = d <= 600;
            const key = Math.floor(tt / step) * step;
            if (spQueue === null || spQueueDur !== Math.round(d)) spBuildQueue(d, step);   // 처음 보일 때(길이가 바뀌면 다시) 미리 받기 목록
            if (!spDiagShown) { spDiagShown = true; spDiag({ ev: 'show', d: Math.round(d * 10) / 10, step, queue: spQueue ? spQueue.length : 0, stream: !!isStreaming() }); }
            const entered = key !== spCurKey;
            spCurKey = key;
            spRefresh();   // 있으면 그 장면, 없으면 가장 가까운 장면을 바로
            // 그 구간 장면이 없고 받는 중도 아니면 — 새로 들어왔을 때만 요청(같은 구간 안에서 움직일 때마다 다시 요청하지 않게)
            if (entered && !spDone.has(key) && spLoadingKey !== key) spWant = key;
            spPump();
            spBatchPump();   // 묶음 미리 받기(지원할 때) — 이미 진행 중이면 그대로
        };
        on(spImg, 'error', () => { spImg.hidden = true; spShownKey = null; });
        on(prog, 'pointerdown', (e) => { if (dragging) spShow(dragRatio, e.clientX); });
        on(prog, 'pointermove', (e) => {
            if (dragging) spShow(dragRatio, e.clientX);
            else if (e.pointerType === 'mouse') spShow(ratioAt(e.clientX), e.clientX);
        });
        on(prog, 'pointerup', (e) => { if (e.pointerType !== 'mouse') spHide(); });   // 마우스는 계속 위에 있으면 유지(벗어나면 아래에서 숨김)
        on(prog, 'pointercancel', spHide);
        on(prog, 'pointerleave', () => { if (!dragging) spHide(); });
        cleanups.push(spHide);
        // ★ (2026-09-23) 방향키는 여기서 처리하지 않는다. 탐색기·공유 모두 문서 전체에서 이미 ←/→ ±5초를
        //   처리하고(이동 표시도 보여줌), 여기서도 처리하면 진행바에 초점이 있을 때 ±10초로 두 번 이동했다.

        // ── 기존 기능 대신 눌러주기 ───────────────────────────────────────────
        const call = (fn) => { try { if (typeof fn === 'function') fn(); } catch (e) {} setTimeout(render, 30); };
        on(bPrev, 'click', () => { if (!bPrev.disabled) call(ctx.goPrev); });
        on(bNext, 'click', () => { if (!bNext.disabled) call(ctx.goNext); });
        on(bLoop, 'click', () => call(ctx.toggleLoop));
        on(bAb, 'click', () => call(ctx.clickAb));
        on(bCc, 'click', () => call(ctx.toggleCc));
        on(bPip, 'click', () => call(ctx.togglePip));
        on(bFs, 'click', () => call(ctx.toggleFs));
        on(bSpeed, 'click', () => { setMenuOpen(true); buildMenu(); fitMenu(); render(); });
        on(bInfo, 'click', () => { setMenuOpen(false); render(); try { if (hasInfo) ctx.showInfo(); } catch (e) {} });
        on(bSet, 'click', () => { setMenuOpen(menu.hidden); if (!menu.hidden) { buildMenu(); fitMenu(); } render(); });

        // ★ (2026-09-23) 설정 창을 플레이어 안에 **한 번에 다 보이게** 맞춘다 — 스크롤 없이(펜닐 지시).
        //   ① 그대로 들어가면 끝 ② 폭이 넉넉하면 두 칸 배치(높이 약 절반) ③ 촘촘하게(버튼·글자·간격 축소)
        //   ④ 80% 이상으로 줄여서 들어가면 비율 축소(오른쪽 아래 기준)
        //   ⑤ 그보다 더 줄여야 하면(휴대폰 세로의 작은 영상 등) **화면 아래에서 올라오는 판**으로 띄운다 —
        //      50% 로 줄이면 버튼이 14px 이 되어 손가락으로 누를 수 없기 때문(유튜브 휴대폰과 같은 방식).
        //      미리보기 창은 transform: none 이고 overflow: hidden 은 fixed 요소를 자르지 않아 화면 기준으로 붙는다.
        //   위치(bottom)도 조작 줄 높이 + 8px 로 매번 다시 정한다(고정값이면 조작 줄 높이가 바뀔 때 어긋난다).
        // ★ (2026-09-26) 설정 창 크기를 재는 동안만 전환(transition)을 끈다 — 조작 줄(fitRow)과 같은 이유: style.css 의 모바일 전체 규칙
        //   '* { transition-duration: 0.1s !important }'(원래 코드, 속성 기본값 all)가 설정 창·칩의 크기까지 0.1초 애니메이션으로 만들어,
        //   두 칸·세 칸·촘촘 클래스를 바꾸고 곧바로 재면 예전 크기가 잡힐 수 있다(펜닐 지시로 미리 적용). 원래 본문은 fitMenuInner 로
        //   그대로 옮기고, 8곳의 종료 지점 어디서 끝나도 finally 로 반드시 되돌린다. 설정 창엔 스킨 고유의 전환·애니메이션이 없어 잃는 효과 없음.
        function fitMenu() {
            menu.classList.add('fsvs-fitting');
            try { fitMenuInner(); } finally { menu.classList.remove('fsvs-fitting'); }
        }
        function fitMenuInner() {
            if (menu.hidden) return;
            // ★ (2026-09-25) 크기 맞춤 재설계(펜닐 제보: 플레이어 높이가 낮으면 설정 창이 플레이어를 벗어나 브라우저 창 전체로
            //   펼쳐짐 — ⑤ '화면 아래 판'이 창 기준 고정·전체 폭이었다). **플레이어 안에서** 맞추는 단계를 늘리고,
            //   창 전체 판은 휴대폰(창의 짧은 쪽 600px 미만)에서만 쓴다 — 휴대폰은 플레이어가 곧 화면 폭이고 높이가 너무 낮아 판이 필요.
            //   순서: ①그대로 ②두 칸(최대 720px) ③세 칸(넓을 때, 최대 980px) ④촘촘하게 ⑤70% 이상 축소
            //         ⑥[휴대폰] 창 아래 판 / [그 밖] 플레이어 안 최대 크기 + 창 안 스크롤(최후 수단)
            //   음성 목록은 CSS 에서 여러 칸 격자로 바꿔 높이 자체를 줄였다(11개면 한 줄에 하나씩일 때보다 훨씬 낮다).
            menu.classList.remove('fsvs-2col', 'fsvs-3col', 'fsvs-compact', 'fsvs-sheet', 'fsvs-raised');
            menu.style.transform = ''; menu.style.width = ''; menu.style.maxHeight = ''; menu.style.overflowY = '';
            const barH = bar.offsetHeight || 0;
            menu.style.bottom = (barH + 8) + 'px';
            let availH = (wrap.clientHeight || 0) - barH - 16;   // ★ (2026-10-08) let — 아래 '플레이어 위 빈 곳' 단계에서 늘릴 수 있게
            const availW = (wrap.clientWidth || 0) - 32;
            if (availH <= 0 || availW <= 0) return;   // 배치 정보를 못 얻으면(숨김 등) 그대로 둔다
            const fits = () => menu.scrollHeight <= availH && menu.scrollWidth <= availW + 1;
            if (fits()) return;
            if (availW >= 520) {
                menu.classList.add('fsvs-2col');
                menu.style.width = Math.min(720, availW) + 'px';
                if (fits()) return;
                if (availW >= 760) {
                    menu.classList.remove('fsvs-2col'); menu.classList.add('fsvs-3col');
                    menu.style.width = Math.min(980, availW) + 'px';
                    if (fits()) return;
                }
            }
            menu.classList.add('fsvs-compact');
            if (fits()) return;
            // ★ (2026-10-08) 플레이어 위 빈 곳까지 쓰기(공유 페이지 — ctx.menuRoomAbove, 펜닐 승인) — 공유는 플레이어가 작아(최대 720×405) 음성이 많으면
            //   설정 창이 플레이어 안에서 스크롤됐다. 페이지가 영상 하나라 플레이어 위(제목·정보)에 겹쳐 펼쳐도 되므로, 줄이거나 스크롤하기 전에
            //   그 공간(창 위쪽까지 보이는 만큼)을 더해 본다. 휴대폰은 종전대로 아래 판. 탐색기는 이 값을 주지 않아 종전 그대로.
            const _phoneRoom = Math.min(window.innerWidth || 0, window.innerHeight || 0) < 600;
            const _extra = (!_phoneRoom && typeof ctx.menuRoomAbove === 'function') ? Math.max(0, Math.floor(Number(ctx.menuRoomAbove()) || 0)) : 0;
            if (_extra > 0) {
                const _baseH = availH;
                availH = _baseH + _extra;
                if (fits()) return;
                availH = _baseH;
            }
            const sc = Math.min(availH / (menu.scrollHeight || 1), availW / (menu.scrollWidth || 1));
            // ★ (2026-10-08) 휴대폰(창의 짧은 쪽 600px 미만)·터치 화면은 85% 미만으로 줄이지 않고 아래 판으로 — 긴 목록을 넓게 놓은 뒤(아래)
            //   휴대폰 가로에서 77% 로 줄어 버튼이 약 21px(손가락으로 누르기 어려움)이 되던 것을 막는다. PC·태블릿은 종전 그대로 70%.
            const _minSc = (Math.min(window.innerWidth || 0, window.innerHeight || 0) < 600 && window.matchMedia && window.matchMedia('(pointer: coarse)').matches) ? 0.85 : 0.7;
            if (sc >= _minSc) { menu.style.transform = 'scale(' + sc.toFixed(3) + ')'; return; }
            // 휴대폰 판정은 '창의 짧은 쪽 < 600px' — 가로로 돌린 휴대폰(예 844×390)도 휴대폰으로 본다.
            //   ★ 처음엔 '폭 < 600' 으로 써서 가로 화면 휴대폰을 PC 로 판정했다 → 판이 사라져 낮은 플레이어 안 스크롤이 되는
            //   퇴보를 시험(t13, 09-23 가로 화면 판 수정)이 잡았다.
            const phone = Math.min(window.innerWidth || 0, window.innerHeight || 0) < 600;
            if (!phone) {
                // ⑥ 플레이어 안 최대 크기 + 창 안 스크롤 — 플레이어를 벗어나지 않는다
                menu.style.maxHeight = availH + 'px';
                menu.style.overflowY = 'auto';
                return;
            }
            // ⑥ [휴대폰] 창 아래 판
            menu.style.bottom = ''; menu.style.width = ''; menu.style.transform = '';
            menu.classList.remove('fsvs-2col', 'fsvs-3col', 'fsvs-raised');
            menu.classList.add('fsvs-sheet');
            if ((window.innerWidth || 0) >= 520) menu.classList.add('fsvs-2col');   // 가로 화면 휴대폰은 판도 두 칸(종전 그대로 — 다시 쓰며 빠뜨렸던 줄)
            // ★ (2026-09-23) 판이 조작 줄을 덮지 않게 한다 — 조작 줄이 화면에 보이면 그 **바로 위**에 띄운다.
            //   화면 맨 아래에 붙이면 가로모드처럼 화면이 낮을 때 ⚙ 를 덮어 다시 눌러 닫을 수 없었다(펜닐 제보).
            const vh = window.innerHeight || 0;
            let above = 0;
            try {
                const br = bar.getBoundingClientRect();
                if (br.height > 0 && br.top < vh && br.bottom > 0) above = Math.max(0, Math.round(vh - br.top) + 8);
            } catch (e) {}
            if (above > 0) { menu.style.bottom = above + 'px'; menu.classList.add('fsvs-raised'); }
            const maxH = Math.floor((vh - above) * 0.95);
            if (maxH > 0 && menu.scrollHeight > maxH) {   // 남은 공간으로도 안 되는 극단적인 경우만
                menu.style.maxHeight = maxH + 'px';
                menu.style.overflowY = 'auto';
            }
        }
        // ★ (2026-09-26) 화면 크기·전체화면이 바뀌면 render() 도 부른다 — 자막 올림 값(--fsvs-sub-raise)이 배치에 따라 달라지게 된 뒤,
        //   멈춘 채 휴대폰을 돌리면(timeupdate 없음) 돌리기 전 값이 남아 조작 줄이 자막을 가릴 수 있었다(재검토에서 발견).
        // ★ (2026-10-02) 플레이어 틀 높이가 충분할 때만 터치 화면에서 넓은 재생바·버튼 줄 간격(CSS .fsvs-roomy) — 가로로 긴 영화를 휴대폰
        //   세로 화면으로 보면 틀이 약 162px 라 막대가 높아지면 가운데 재생·±5초 버튼 아래 23px 가 막대에 가렸다(실측). 가운데 버튼 아래끝 ≈
        //   틀 높이/2 + 28px 라 막대 76px 와 안 겹치려면 208px, 미리보기 상자가 위로 안 넘치려면 194px → 여유 두고 210px 이상. 막대는 틀 위에
        //   겹쳐 떠 있어(absolute) 틀 크기를 바꾸지 않으므로 되먹임 없음. 창 크기·전체 화면·틀 크기 변화(refit)마다 다시 정한다.
        const syncRoomy = () => { try { wrap.classList.toggle('fsvs-roomy', (wrap.clientHeight || 0) >= 210); } catch (e) {} };
        cleanups.push(() => { try { wrap.classList.remove('fsvs-roomy'); } catch (e) {} });
        const refit = () => { syncRoomy(); if (!menu.hidden) fitMenu(); render(); };
        syncRoomy();
        on(window, 'resize', refit);
        on(document, 'fullscreenchange', refit);
        on(document, 'webkitfullscreenchange', refit);
        // ★ (2026-09-26) 플레이어 틀 크기 변화를 지켜본다(ResizeObserver) — 창 크기는 그대로인데 틀 폭만 바뀌는 경우(영상 목록 패널 여닫기,
        //   미리보기 창 애니메이션 등)엔 resize 가 없어, 멈춘 영상에선 조작 줄이 예전 폭 기준으로 남아 넘치거나(버튼 잘림) 접힌 채 남았다
        //   (펜닐 제보 사진 2·3). 다음 프레임에 한 번 refit(메뉴 크기·render→fitRow·자막 올림). 조작 줄은 틀 위에 겹쳐 떠 있어(absolute)
        //   틀 크기를 바꾸지 않으므로 되먹임 고리가 생기지 않는다. 떼어낼 때 해제(cleanups).
        if (typeof ResizeObserver === 'function') {
            let roRaf = 0;
            let roLastW = -1;
            const ro = new ResizeObserver((entries) => {
                // ★ (2026-09-26) 진단 기록 — 틀 크기 변화 알림이 실제로 오는지(폭이 바뀐 때만 — 애니메이션 중 과다 기록 방지)
                try {
                    const cr = entries && entries[0] && entries[0].contentRect;
                    const cw = cr ? Math.round(cr.width) : -2;
                    if (cw !== roLastW && window._hlsDiag && typeof window._diagLog === 'function') {
                        roLastW = cw;
                        window._diagLog('row_resize', { wrapW: cw, wrapH: cr ? Math.round(cr.height) : null, rowW: row.clientWidth });
                    }
                } catch (e) {}
                if (roRaf) return;
                roRaf = requestAnimationFrame(() => { roRaf = 0; refit(); });
            });
            ro.observe(wrap);
            cleanups.push(() => { ro.disconnect(); if (roRaf) cancelAnimationFrame(roRaf); });
        }

        // ── ⚙ 메뉴 (열 때마다 기존 셀렉트/상태에서 다시 만든다) ────────────────
        const section = (title) => { const s = el('div', 'fsvs-sec'); s.append(el('div', 'fsvs-sectitle', title)); const body = el('div', 'fsvs-chips'); s.append(body); menu.append(s); return body; };
        // 칩은 메뉴를 다시 만들 때 통째로 버려지므로 정리 목록(on)에 넣지 않는다 — 여닫을수록 목록이 커지는 것 방지
        // ★ (2026-09-23) 누른 칩은 메뉴를 다시 그린 뒤에도 잠깐 강조한다(flashKey) — 누른 반응이 보이게.
        let flashKey = null;
        // ★ (2026-09-25) 보안 — 항목 글자는 HTML 이 아니라 **글자 그대로**(textContent) 넣는다.
        //   [결함] 종전엔 btn() → el() 가 글자를 innerHTML 로 넣었다. 음성 항목 이름은 **동영상 파일 안의 정보**(음성 제목·언어,
        //   트랜스코딩 음성 상자의 option 글자, 사파리 audioTracks.label)라, 제목에 '<img src=x onerror=…>' 를 넣은 파일을 열면
        //   ⚙ 메뉴에서 스크립트가 실행될 수 있었다(공유 페이지면 링크를 받은 사람 브라우저에서). 기존 음성 상자는 escapeHtml 로
        //   안전하게 넣지만 스킨이 그 글자를 다시 HTML 로 넣어 무의미해졌다 — 09-23 음성을 ⚙ 로 옮길 때 생긴 구멍(재검토에서 발견).
        //   chip 에 들어가는 글자(배속·번역 글자·선택 상자 글자·음성 이름)는 모두 일반 글자라 모양은 같다.
        const chip = (label, active, onClick, key) => {
            const b = btn('fsvs-chip' + (active ? ' is-on' : ''), null, label);
            b.textContent = (label == null) ? '' : String(label);
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
            if (key && key === flashKey) { b.classList.add('is-flash'); setTimeout(() => b.classList.remove('is-flash'), 450); }
            b.addEventListener('click', () => { flashKey = key || null; onClick(); buildMenu(); fitMenu(); render(); flashKey = null; });
            return b;
        };
        function buildMenu() {
            menu.textContent = '';
            // ★ (2026-09-23) 머리줄(제목 + ✕ 닫기) — 화면 아래 판(⑤)일 때만 보인다(CSS).
            //   판이 커서 조작 줄의 ⚙ 를 가리는 경우에도 확실히 닫을 수 있게 한다(펜닐 제보: 가로모드에서 ⚙ 로 안 닫힘).
            const head = el('div', 'fsvs-menuhead');
            head.append(el('div', 'fsvs-menutitle', T('settings', '설정')));
            const bClose = btn('fsvs-menuclose', ICON.close, T('close', '닫기'));
            bClose.addEventListener('click', () => { setMenuOpen(false); render(); });
            head.append(bClose);
            menu.append(head);
            // ★ (2026-09-23) 자리가 모자라 조작 줄에서 접힌 버튼은 여기 '더 보기'에 상태와 함께 나온다(fitRow).
            const folded = collapsible.filter((b) => b.classList.contains('fsvs-collapsed'));
            if (folded.length) {
                const more = section(T('fsvs_more', '더 보기'));
                if (folded.includes(bInfo)) {
                    more.append(chip(T('vi_dlg_title', '동영상 정보'), false, () => { setMenuOpen(false); try { ctx.showInfo(); } catch (e) {} }, 'info'));
                }
                if (folded.includes(bVol)) {
                    const m = video.muted || video.volume === 0;
                    more.append(chip(m ? T('fsvs_unmute', '소리 켜기') : T('mute', '음소거'), m, () => { video.muted = !video.muted; }, 'mute'));
                }
                if (folded.includes(bLoop)) {
                    const lo = ctx.isLoop ? ctx.isLoop() : video.loop;
                    more.append(chip(lo ? T('video_loop_one_on_s', '이 영상만 반복: 켬') : T('video_loop_one', '이 영상만 반복'), lo, () => { if (ctx.toggleLoop) ctx.toggleLoop(); }, 'loop'));
                }
                if (folded.includes(bAb)) {
                    const st = (ctx.abState && ctx.abState()) || {};
                    const a = st.a != null, b = st.b != null;
                    const label = !a ? T('fsvs_ab_set_a', '구간 반복: A 지정') : (!b ? T('fsvs_ab_set_b', '구간 반복: B 지정') : T('fsvs_ab_clear', '구간 반복 해제'));
                    more.append(chip(label, a && b, () => { if (ctx.clickAb) ctx.clickAb(); }, 'ab'));
                }
            }
            const sp = section(T('playback_speed', '재생 속도'));
            SPEEDS.forEach((v) => sp.append(chip(v + 'x', Math.abs(video.playbackRate - v) < 0.001,
                () => { if (ctx.setSpeed) ctx.setSpeed(v); else video.playbackRate = v; })));
            const sels = (ctx.selects && ctx.selects()) || [];
            sels.forEach((sel) => {
                if (!sel || !sel.options || sel.options.length < 2) return;
                // 탐색기는 class, 공유 페이지는 id(share-playback-mode)로 재생방식 셀렉트를 구분한다
                const isMode = sel.classList.contains('playback-mode-select') || /playback-mode/.test(sel.id || '');
                // ★ (2026-09-23) 다국어 음성 — 탐색기 audio-track-picker(.audio-track-select), 공유 share-audio-select.
                //   항목 글자가 길어('English · EAC3 · 5.1(side)') 한 줄에 하나씩 보이게 한다(fsvs-list).
                const isAudio = sel.classList.contains('audio-track-select') || sel.id === 'audio-track-picker' || sel.id === 'share-audio-select';
                const body = section(isAudio ? T('audio_track', '오디오') : (isMode ? T('playback_mode', '재생 방식') : T('quality', '화질')));
                if (isAudio) { body.classList.add('fsvs-list'); body.parentNode.classList.add('fsvs-sec-wide'); }   // ★ (2026-10-08) 긴 목록은 한 줄을 통째로(CSS)
                Array.from(sel.options).forEach((o) => body.append(chip(o.textContent, sel.value === o.value, () => {
                    if (sel.value === o.value) return;
                    sel.value = o.value;
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                    setMenuOpen(false);
                })));
            });
            // ★ (2026-09-24) 일반 재생 중 다국어 음성(펜닐 지시 — 4번 '나'). 트랜스코딩 중엔 위의 기존 음성 상자가 맡으므로
            //   그 상자가 없을 때만 페이지(ctx.nativeAudio)에 묻는다. 사파리는 즉시 전환, 그 밖의 브라우저는
            //   그 음성으로 스트리밍 전환(보던 위치부터) — 전환 방법은 페이지 쪽이 기존 흐름대로 처리한다.
            const hasAudioSel = sels.some((s) => s && s.options && s.options.length > 1
                && (s.classList.contains('audio-track-select') || s.id === 'audio-track-picker' || s.id === 'share-audio-select'));
            const na = (!hasAudioSel && ctx.nativeAudio) ? ctx.nativeAudio() : null;
            if (na && Array.isArray(na.tracks) && na.tracks.length >= 2 && typeof na.select === 'function') {
                const ab = section(T('audio_track', '오디오'));
                ab.classList.add('fsvs-list'); ab.parentNode.classList.add('fsvs-sec-wide');   // ★ (2026-10-08) 긴 목록은 한 줄을 통째로(CSS)
                na.tracks.forEach((tk, i) => ab.append(chip(String(tk.label || ('Track ' + (i + 1))), !!tk.on, () => {
                    if (tk.on) return;
                    try { na.select(i); } catch (e) {}
                    setMenuOpen(false);
                })));
            }
            if (ctx.hasCc && ctx.hasCc()) {
                // ★ (2026-10-08) 자막 파일 고르기(펜닐 요청) — 페이지가 찾은 자막이 2개 이상일 때만(ctx.subTracks). 파일 이름은 글자 그대로(chip → textContent).
                const st = ctx.subTracks ? ctx.subTracks() : null;
                if (st && Array.isArray(st.tracks) && st.tracks.length >= 2 && typeof st.select === 'function') {
                    const fb = section(T('fsvs_sub_file', '자막 파일'));
                    fb.classList.add('fsvs-list', 'fsvs-subfiles'); fb.parentNode.classList.add('fsvs-sec-wide');   // ★ (2026-10-08) 한 줄을 통째로(CSS)
                    st.tracks.forEach((tk, i) => fb.append(chip(String(tk.label || ('#' + (i + 1))), !!tk.on, () => {
                        if (tk.on) return;
                        try { st.select(i); } catch (e) {}
                        setMenuOpen(false);
                    })));
                }
                const sb = section(T('subtitle', '자막'));
                sb.classList.add('fsvs-subgrid');   // ★ (2026-10-08) 행 이름 칸을 가장 긴 이름에 맞춤(CSS) — 영어 'Position' 이 34px 칸을 넘어 버튼에 겹쳤다
                // ★ (2026-09-23) 크기·위치에 현재 값을 보여 준다 — 누를 때마다 숫자가 바뀌어 반응이 보인다.
                //   탐색기·공유 모두 자막 크기·위치를 자막 요소의 인라인 스타일(font-size em · bottom %)에 적용하므로
                //   그 값을 그대로 읽는다(누른 즉시 기존 핸들러가 바꾼 뒤 메뉴를 다시 그리므로 새 값이 나온다).
                const ov = wrap.querySelector('.subtitle-overlay');
                const em = ov ? parseFloat(ov.style.fontSize) : NaN;
                const bt = ov ? parseFloat(ov.style.bottom) : NaN;
                const val = (txt) => el('div', 'fsvs-val', txt);
                const row = (label, a, mid, b) => { const g = el('div', 'fsvs-subrow'); g.append(el('div', 'fsvs-rowlabel', label), a, mid, b); sb.append(g); };
                const act = (name, label) => chip(label, false, () => ctx.subAct && ctx.subAct(name), name);
                row(T('fsvs_sub_size', '크기'), act('size-down', T('fsvs_smaller', '작게')),
                    val(isFinite(em) ? Math.round(em * 100) + '%' : T('fsvs_default', '기본')), act('size-up', T('fsvs_bigger', '크게')));
                row(T('fsvs_sub_pos', '위치'), act('pos-down', T('fsvs_lower', '아래로')),
                    val(isFinite(bt) ? Math.round(bt) + '%' : T('fsvs_default', '기본')), act('pos-up', T('fsvs_higher', '위로')));
                const off = (ctx.subSync && ctx.subSync()) || 0;
                const g = el('div', 'fsvs-subrow');
                g.append(el('div', 'fsvs-rowlabel', T('fsvs_sub_sync', '싱크')));
                // ★ (2026-09-23) 가운데는 '현재값 + 되돌리기'임을 ↺ 로 구분한다.
                //   싱크가 +0.5초일 때 가운데와 오른쪽이 똑같이 "+0.5초"로 보여 누르면 다른 동작인데 구분되지 않았다.
                g.append(act('sync-down', '−0.5' + T('sec', '초')),
                         act('sync-reset', '↺ ' + (off > 0 ? '+' : '') + off.toFixed(1) + T('sec', '초')),
                         act('sync-up', '+0.5' + T('sec', '초')));
                sb.append(g);
            }
        }
        on(document, 'pointerdown', (e) => { if (!menu.hidden && !menu.contains(e.target) && e.target !== bSet && e.target !== bSpeed && !bSet.contains(e.target)) { setMenuOpen(false); render(); } });

        // ── 좁은 화면 넘침 처리 ★ (2026-09-23) ──────────────────────────────────
        //   [결함] 조작 줄은 한 줄로 늘어서고 틀(overflow:hidden)이 넘친 부분을 자른다. 이전·다음 버튼을 넣은 뒤
        //   휴대폰 세로(폭 360~430px)에서 목록이 있으면 약 16~86px 넘쳐 **맨 오른쪽 ⚙·⛶ 가 잘려 누를 수 없었다.**
        //   [처리] 넘칠 때만 중요도가 낮은 것부터 접어(음소거 → 구간 반복 → 이 영상만 반복) ⚙ 메뉴의 '더 보기'로
        //   옮긴다. 기능은 사라지지 않는다. 재생·이전·다음·시간·CC·⚙·⛶ 는 항상 남는다.
        //   자리가 충분하면 아무것도 접지 않는다. 폭·보이는 버튼·시간 길이가 바뀔 때만 다시 잰다(매 갱신마다 재지 않음).
        // ★ (2026-09-26) 접는 순서를 A-B → 반복 → 볼륨으로 — 볼륨(음소거)을 마지막까지 남긴다(펜닐: 휴대폰 세로에서 볼륨 아이콘이 사라짐).
        // ★ (2026-10-07) 동영상 정보(ⓘ)를 맨 먼저 접는다(⚙ '더 보기'로) — 자주 쓰지 않는 버튼이라 기존 버튼보다 먼저 자리를 내준다.
        const collapsible = [bInfo, bAb, bLoop, bVol];
        let rowSig = '';
        function fitRow() {
            const W = row.clientWidth || 0;
            const sig = [W, bPrev.style.display, bInfo.style.display, bAb.style.display, bCc.style.display, bPip.style.display, bSpeed.style.display, time.textContent.length].join('|');
            if (sig === rowSig) return;
            rowSig = sig;
            // ★ (2026-09-26) 진단 기록(동작 변경 없음) — 크기별 배치가 맞게 되는지 실제 기기 수치로 확인하기 위해(펜닐 요청).
            //   진단이 켜졌을 때(hlsdiag=1, 탐색기의 _diagLog)만 모은다.
            const _dg = (window._hlsDiag && typeof window._diagLog === 'function') ? { W } : null;
            // ★ (2026-09-26) 계산하는 동안 조작 줄의 전환(transition)을 끈다 — [원인 확정, hlsdiag 기록] style.css 의 모바일 규칙
            //   '* { transition-duration: 0.1s !important }'(10544행, 원래 코드 — 속성을 안 정해 기본값 all)이 폭·간격·글자 크기까지 0.1초
            //   애니메이션으로 만들어, 클래스를 바꾸고 곧바로 재면 예전 크기가 잡혔다 → 같은 폭(359)에서 촘촘 ↔ 보통이 번갈아 나오고
            //   '보통'일 땐 넘쳐 버튼이 잘렸다(사진 2·3). 계산 뒤 떼어도 크기는 이미 바뀐 상태라 새 애니메이션이 생기지 않는다.
            row.classList.add('fsvs-fitting');
            collapsible.forEach((b) => b.classList.remove('fsvs-collapsed'));
            row.classList.remove('fsvs-tight', 'fsvs-timeup');
            if (!W) { row.classList.remove('fsvs-fitting'); if (_dg) logFit(_dg); return; }
            if (_dg) _dg.sw0 = row.scrollWidth;          // 보통일 때 필요한 폭
            // ★ (2026-09-26) 자리가 모자라면 먼저 버튼을 촘촘하게(간격 0·버튼 32px·재생 36px·시간 11px — 약 66px 확보) —
            //   종전엔 곧바로 접어 같은 파일도 화면 폭에 따라 보이는 아이콘이 달랐다(펜닐 제보). 그래도 모자랄 때만 접는다.
            if (row.scrollWidth > W + 1) row.classList.add('fsvs-tight');
            if (_dg) _dg.sw1 = row.scrollWidth;          // 촘촘하게 한 뒤
            // ★ (2026-09-26) 그래도 모자라면 버튼을 접기 전에 시간 표시를 조작 줄 위(진행 막대 위 왼쪽)로 옮긴다(약 70px 확보) —
            //   '좁으면 다른 위치에'(펜닐 요청). 조작 줄이 숨겨지면 함께 숨겨진다(조작 줄 안의 요소).
            if (row.scrollWidth > W + 1) row.classList.add('fsvs-timeup');
            if (_dg) _dg.sw2 = row.scrollWidth;          // 시간 표시를 위로 옮긴 뒤
            for (const b of collapsible) {
                if (row.scrollWidth <= W + 1) break;
                if (b.style.display === 'none') continue;   // 원래 안 보이는 버튼(예: 스트리밍의 A-B)은 건너뜀
                b.classList.add('fsvs-collapsed');
            }
            if (_dg) logFit(_dg);
            row.classList.remove('fsvs-fitting');
        }
        // ★ (2026-09-26) 진단 기록 — 배치 결과와 화면 수치. 조작 줄 오른쪽 끝이 잘라내는 상위 요소(overflow)의 오른쪽 끝보다 크면
        //   '보이는 폭보다 넓게 잡혀 넘침을 감지하지 못함'을 뜻한다. 각 버튼의 실제 폭도 남긴다.
        function logFit(o) {
            try {
                const lr = (el) => { const b = el.getBoundingClientRect(); return [Math.round(b.left), Math.round(b.right)]; };
                // 상태 클래스(fsvs-btn·fsvs-collapsed 등)는 빼고 버튼 이름만 — 접힌 버튼이 'collapsed' 로 찍히지 않게
                const short = (el) => { const m = (String(el.className || '').match(/fsvs-[\w-]+/g) || []).filter((x) => !/^fsvs-(btn|collapsed|on|off|flash|active|chip)$/.test(x)); return m.length ? m[0].replace('fsvs-', '') : el.tagName.toLowerCase(); };
                o.swEnd = row.scrollWidth;
                o.tight = row.classList.contains('fsvs-tight');
                o.timeup = row.classList.contains('fsvs-timeup');
                o.folded = collapsible.filter((b) => b.classList.contains('fsvs-collapsed')).map(short);
                o.items = Array.prototype.filter.call(row.children, (c) => c.offsetWidth > 0).map((c) => short(c) + ':' + c.offsetWidth).join(' ');
                o.rowLR = lr(row); o.wrapLR = lr(wrap); o.barW = bar.clientWidth;
                let p = wrap.parentElement;
                while (p && p !== document.documentElement) {
                    const cs = getComputedStyle(p);
                    if (/hidden|auto|scroll|clip/.test(cs.overflowX || cs.overflow || '')) {   // 일부 환경은 overflowX 를 풀어 주지 않음
                        o.clip = (p.id ? '#' + p.id : '') + '.' + String(p.className || '').trim().split(/\s+/).slice(0, 2).join('.');
                        o.clipLR = lr(p);
                        break;
                    }
                    p = p.parentElement;
                }
                o.vw = window.innerWidth;
                o.vvw = window.visualViewport ? Math.round(window.visualViewport.width) : null;
                o.dpr = window.devicePixelRatio;
                o.land = window.innerWidth > window.innerHeight;
                window._diagLog('row_fit', o);
            } catch (e) {}
        }

        // ── 가운데 재생 버튼 맞추기 ★ (2026-09-23) ───────────────────────────────
        //   [결함] 다국어 음성을 바꾸면(탐색기·공유 공통) 영상 주소를 새로 넣어 영상이 멈추는데, HTML 규칙상 pause 신호가 오지 않는다.
        //   가운데 버튼 아이콘과 영상 틀의 'playing' 표시는 기존 코드가 play·pause·ended 신호로만 바꾸므로 '재생 중'(⏸)으로 남았다
        //   (펜닐 제보 — 새 조작 줄의 재생 버튼은 상태를 직접 읽어 맞게 나옴).
        //   [처리] 다시 그릴 때 **화면 표시만** 실제 상태에 맞춘다 — 가짜 pause 신호는 보내지 않는다
        //   (탐색기의 pause 처리가 '사용자가 멈춤(_userPaused)'으로 기록해 자동 이어 재생을 막으므로 다른 동작이 바뀐다).
        //   아이콘 표시 값('' / 'none')과 'playing' 제거는 기존 showPlayIcon·pause 처리와 같다. 달라졌을 때만 바꾼다.
        function syncCenter() {
            const stopped = video.paused || video.ended;
            wrap.querySelectorAll('.video-play-overlay').forEach((ov) => {
                if (ov.classList.contains('video-seek-btn')) return;
                const ip = ov.querySelector('.icon-play'), ipa = ov.querySelector('.icon-pause');
                if (!ip || !ipa) return;
                const wantPlay = stopped ? '' : 'none', wantPause = stopped ? 'none' : '';
                if (ip.style.display !== wantPlay) ip.style.display = wantPlay;
                if (ipa.style.display !== wantPause) ipa.style.display = wantPause;
            });
            if (stopped && wrap.classList.contains('playing')) wrap.classList.remove('playing');
        }

        // ── 화면 갱신 ────────────────────────────────────────────────────────
        const setActive = (b, on2) => { b.classList.toggle('is-on', !!on2); b.setAttribute('aria-pressed', on2 ? 'true' : 'false'); };
        function render() {
            const d = total(), cur = absNow(), streaming = isStreaming();
            const ok = d > 0;
            const ratio = dragging ? dragRatio : (ok ? Math.min(1, cur / d) : 0);
            played.style.width = (ratio * 100) + '%';
            thumb.style.left = (ratio * 100) + '%';
            if (streaming) {
                // 스트리밍: 이 세션에서 갈 수 있는 구간(오프셋 ~ 오프셋+변환된 끝)을 표시
                const off = offset(), end = relEnd();
                buf.style.left = (ok ? Math.min(1, off / d) * 100 : 0) + '%';
                buf.style.width = (ok ? Math.max(0, Math.min(1, (off + end) / d) - Math.min(1, off / d)) * 100 : 0) + '%';
            } else {
                let bEnd = 0;
                const rc = video.currentTime || 0;
                try { const b = video.buffered; for (let i = 0; i < b.length; i++) { if (rc >= b.start(i) - 0.5 && rc <= b.end(i) + 0.5) { bEnd = b.end(i); break; } } } catch (e) {}
                buf.style.left = '0%';
                buf.style.width = (ok ? Math.min(1, bEnd / d) * 100 : 0) + '%';
            }
            wrap.classList.toggle('fsvs-streaming', streaming);
            time.textContent = fmt(dragging && ok ? dragRatio * d : cur) + ' / ' + (ok ? fmt(d) : '--:--');
            prog.setAttribute('aria-valuenow', String(Math.round(ratio * 100)));

            const paused = video.paused || video.ended;
            // ★ (2026-09-23) 자막이 조작 줄 뒤로 숨지 않도록 조작 줄 높이를 알려 준다(CSS 가 자막을 그만큼 올림).
            //   자막 위치 조절은 bottom(!important) 이라 transform 과 겹치지 않는다.
            const _barH = bar.offsetHeight || 0;
            wrap.style.setProperty('--fsvs-bar-h', _barH + 'px');
            // ★ (2026-09-26) 자막은 조작 줄과 **겹치는 만큼만** 올린다(펜닐 제보: 휴대폰에서 누르면 자막이 가운데로 갔다가 조작 줄이
            //   사라지면 설정 위치로 돌아옴 — PC 는 괜찮음). 종전엔 조작 줄이 보이면 무조건 그 높이만큼 올려, 플레이어가 낮거나
            //   영상 위아래에 여백이 있어 이미 조작 줄 위에 있는 자막까지 크게 움직였다. 실제 화면 좌표로 자막 아래끝과 조작 줄 위끝을 비교해
            //   겹치는 만큼(+여유 4px)만 올리고, 종전 값(조작 줄 높이)을 넘지 않게 한다. 자막이 숨겨져 위치를 못 읽으면 종전 값.
            let _raise = _barH;
            try {
                const ov = wrap.querySelector('.subtitle-overlay');
                const cb = ov && (ov.offsetParent || ov.parentElement);   // 대사가 없어 숨겨져 있으면 offsetParent 가 비므로 부모(자막 기준 상자)
                if (ov && cb && _barH > 0) {
                    const cs = getComputedStyle(ov);
                    let b = parseFloat(cs.bottom);
                    if (/%$/.test(cs.bottom)) b = b / 100 * (cb.clientHeight || 0);   // 일부 환경은 % 로 돌려준다
                    if (isFinite(b)) {
                        const cbR = cb.getBoundingClientRect(), barR = bar.getBoundingClientRect();
                        const subBottom = cbR.bottom - b;                  // 올리기 전 자막 아래끝(화면 좌표 — 자막 자신의 transform 과 무관)
                        _raise = Math.max(0, Math.min(_barH, Math.round(subBottom - barR.top + 4)));
                        if (_raise !== wrap._fsvsSubRaise) {
                            wrap._fsvsSubRaise = _raise;
                            try { window._diagLog && window._diagLog('sub_raise', { raise: _raise, barH: _barH, subBottom: Math.round(subBottom), barTop: Math.round(barR.top), bottomPx: Math.round(b), wrapH: wrap.clientHeight, cbH: cb.clientHeight }); } catch (x) {}
                        }
                    }
                }
            } catch (e) {}
            wrap.style.setProperty('--fsvs-sub-raise', _raise + 'px');
            // ★ (2026-10-02) 아이콘이 바뀔 때만 다시 그린다(펜닐 제보: 버튼을 몇 번 눌러야 동작). 매번 innerHTML 을 바꾸면 누르는 순간과 떼는 순간 사이에
            //   누른 아이콘(SVG)이 문서에서 사라져 브라우저가 click 을 보내지 않았다 — render 는 재생 중 초당 4번 이상(실측: 누르는 동안 갱신되면 5번 중 0번 동작).
            const _playIco = paused ? ICON.play : ICON.pause;
            if (bPlay._fsvsIco !== _playIco) { bPlay._fsvsIco = _playIco; bPlay.innerHTML = _playIco; }
            bPlay.setAttribute('aria-label', paused ? T('play', '재생') : T('pause', '일시정지'));
            const _volIco = (video.muted || video.volume === 0) ? ICON.mute : ICON.vol;
            if (bVol._fsvsIco !== _volIco) { bVol._fsvsIco = _volIco; bVol.innerHTML = _volIco; }   // 위와 같은 이유
            if (document.activeElement !== vol) vol.value = String(video.muted ? 0 : video.volume);

            const rate = video.playbackRate || 1;
            bSpeed.style.display = Math.abs(rate - 1) > 0.001 ? '' : 'none';
            bSpeed.textContent = rate + 'x';

            const loopOn = ctx.isLoop ? ctx.isLoop() : video.loop;
            setActive(bLoop, loopOn);
            bLoop.title = loopOn ? T('video_loop_one_on', '이 영상만 반복: 켬 (끝나면 처음부터 · 다음 영상으로 안 넘어감)') : T('video_loop_one_off', '이 영상만 반복: 끔');
            bLoop.setAttribute('aria-label', bLoop.title);

            const abVis = ctx.abVisible ? ctx.abVisible() : false;
            bAb.style.display = abVis ? '' : 'none';
            const st = (ctx.abState && ctx.abState()) || {};
            const hasA = st.a != null, hasB = st.b != null;
            bAb.textContent = (hasA && !hasB) ? 'A' : 'A-B';
            bAb.classList.toggle('is-half', hasA && !hasB);
            setActive(bAb, hasA && hasB);
            // ★ (2026-09-23) video_ab_set_b·video_ab_clear 는 번역 파일에 '눌러서 B 지정'·'눌러서 해제' 처럼 **문장 일부**로 들어 있어
            //   기존 코드(탐색기 A-B 버튼)는 앞에 '구간 반복'을 붙여 쓴다. 단독으로 쓰면 설명이 반쪽이 되므로 같은 방식으로 조합한다.
            bAb.title = !hasA ? T('video_ab_set_a', '구간 반복: 눌러서 A 지정')
                : (!hasB ? T('video_ab', '구간 반복') + ': ' + T('video_ab_set_b', '눌러서 B 지정')
                         : T('video_ab', '구간 반복') + ' · ' + T('video_ab_clear', '눌러서 해제'));
            bAb.setAttribute('aria-label', bAb.title);
            if (abVis && hasA && hasB && ok) {
                ab.style.display = '';
                ab.style.left = (st.a / d * 100) + '%';
                ab.style.width = (Math.max(0, st.b - st.a) / d * 100) + '%';
            } else ab.style.display = 'none';

            const hasCc = ctx.hasCc ? ctx.hasCc() : false;
            bCc.style.display = hasCc ? '' : 'none';
            const ccOn = ctx.ccOn ? ctx.ccOn() : false;
            setActive(bCc, ccOn);
            bCc.classList.toggle('is-off', !ccOn);
            bCc.title = ccOn ? T('sub_toggle_off', '자막 끄기') : T('sub_toggle_on', '자막 켜기');
            bCc.setAttribute('aria-label', bCc.title);

            bPip.style.display = (ctx.hasPip && ctx.hasPip()) ? '' : 'none';
            // 이전·다음: 목록이 있을 때만 보이고, 처음·끝에서는 해당 버튼을 누를 수 없게(흐리게)
            let hasList = false;
            try { hasList = !!(ctx.hasList && ctx.hasList()); } catch (e) {}
            bPrev.style.display = hasList ? '' : 'none';
            bNext.style.display = hasList ? '' : 'none';
            if (hasList) {
                try { bPrev.disabled = !(ctx.canPrev && ctx.canPrev()); } catch (e) { bPrev.disabled = true; }
                try { bNext.disabled = !(ctx.canNext && ctx.canNext()); } catch (e) { bNext.disabled = true; }
            }
            setActive(bSet, !menu.hidden);
            fitRow();
            syncCenter();
        }

        // ── 자동 숨김 (재생 중 2.5초간 움직임이 없으면) ─────────────────────────
        let idleTimer = null;
        const wake = () => {
            wrap.classList.remove('fsvs-idle');
            clearTimeout(idleTimer);
            idleTimer = setTimeout(() => { if (!video.paused && menu.hidden && !dragging) wrap.classList.add('fsvs-idle'); }, 2500);
        };
        on(wrap, 'pointermove', wake, { passive: true });
        on(wrap, 'touchstart', wake, { passive: true });
        on(wrap, 'keydown', wake);
        cleanups.push(() => { clearTimeout(idleTimer); wrap.classList.remove('fsvs-idle'); });

        // ★ (2026-09-25) 켜진 음성이 여럿이면 첫 번째만 남긴다(사파리 audioTracks — 아이폰·맥).
        //   [원인] 다국어 mkv 를 mp4 로 바꾼 파일 중 여러 음성이 '켜짐·각기 다른 그룹'으로 기록된 것은 아이폰이 모두 동시에 재생하려다
        //   재생되지 않고, 음성을 하나 골라야(=하나만 켬) 재생됐다(펜닐 제보). 이미 변환한 파일을 위해 열 때 같은 정리를 자동으로 한다.
        //   켜진 음성이 하나뿐인 정상 파일·audioTracks 가 없는 브라우저(크롬 등)는 건드리지 않는다. 하나도 안 켜진 경우도 그대로 둔다.
        const oneAudio = () => {
            try {
                const at = video.audioTracks;
                if (!at || at.length < 2) return;
                let first = -1, n = 0;
                for (let i = 0; i < at.length; i++) { if (at[i].enabled) { n++; if (first < 0) first = i; } }
                if (n > 1) { for (let k = 0; k < at.length; k++) at[k].enabled = (k === first); }
            } catch (e) {}
        };
        on(video, 'loadedmetadata', oneAudio);
        oneAudio();
        // ★ (2026-09-23) emptied·loadstart 추가 — 음성·화질을 바꿔 주소를 새로 넣으면 영상이 멈추지만 pause 신호가 오지 않는다(HTML 규칙).
        //   그 순간 곧바로 다시 그려 재생 버튼·가운데 버튼(syncCenter)을 실제 상태에 맞춘다.
        ['play', 'pause', 'ended', 'timeupdate', 'durationchange', 'loadedmetadata', 'progress', 'volumechange', 'ratechange', 'seeked', 'emptied', 'loadstart'].forEach((ev) => on(video, ev, render));
        on(video, 'play', wake);
        on(video, 'pause', () => wrap.classList.remove('fsvs-idle'));

        // ── 감시: 스트리밍 전환·요소 교체·모달 닫힘이면 물러난다 ─────────────────
        const watch = setInterval(() => {
            if (!video.isConnected || !wrap.isConnected) {
                // 이 인스턴스만 정리한다(같은 틀에 이미 새 인스턴스가 붙었으면 그것을 떼지 않는다).
                if (wrap._fsvs === api) detach(wrap); else api.destroy();
                // ★ (2026-09-23) 영상 요소만 바뀐 경우(틀은 그대로) 앱에 알려 **새 영상에 곧바로 다시 붙게** 한다.
                //   [결함] 트랜스코딩 전환 때 탐색기가 영상 요소를 새로 바꿔 끼우는데(replaceChild), 새 영상에는
                //   변환 준비가 끝나야 다시 붙었다 → 그 사이 가려 둔 화질·재생방식 선택이 다시 보이고
                //   목록 버튼도 아래로 내려갔다(펜닐 제보).
                if (wrap.isConnected && typeof ctx.onVideoGone === 'function') { try { ctx.onVideoGone(); } catch (e) {} }
                return;
            }
            render();
        }, 500);
        cleanups.push(() => clearInterval(watch));

        const api = {
            video, wrap,
            showVolume: showVolOsd,   // ★ (2026-10-05) 음량 표시 — FSVideoSkin.showVolume(wrap)
            showMessage: showMsgOsd,  // ★ (2026-10-08) 짧은 알림 — FSVideoSkin.showMessage(wrap, 글자, opt)
            destroy() {
                cleanups.splice(0).reverse().forEach((f) => { try { f(); } catch (e) {} });
                try { root.remove(); } catch (e) {}
                wrap.classList.remove('fsvs-on', 'fsvs-idle', 'fsvs-streaming', 'fsvs-menu-open');
                wrap.style.removeProperty('--fsvs-bar-h');
                wrap.style.removeProperty('--fsvs-sub-raise');
                if (!document.querySelector('.fsvs-on')) document.body.classList.remove('fsvs-active');
                // 기본 컨트롤 복원 — 준비 전(video-not-ready) 상태면 기존 코드가 스스로 켠다
                if (video.isConnected && (hadControls || video._hadControls) && !video.classList.contains('video-not-ready')) video.controls = true;
            },
            render
        };
        wrap._fsvs = api;
        render();
        wake();
        return api;
    }

    // ★ (2026-10-05) 음량 표시 — 탐색기·공유의 ↑↓ 키 처리가 부른다(스킨이 붙어 있을 때만)
    function showVolume(wrap) { try { if (wrap && wrap._fsvs && typeof wrap._fsvs.showVolume === 'function') wrap._fsvs.showVolume(); } catch (e) {} }
    // ★ (2026-10-08) 짧은 알림(자막 있음·자막 켜짐/꺼짐) — 음량 표시와 같은 자리. 스킨이 붙어 있을 때만, 아니면 아무것도 안 함.
    function showMessage(wrap, text, opt) { try { if (wrap && wrap._fsvs && typeof wrap._fsvs.showMessage === 'function') wrap._fsvs.showMessage(text, opt); } catch (e) {} }
    window.FSVideoSkin = { attach, detach, showVolume, showMessage, version: '1' };
})();
