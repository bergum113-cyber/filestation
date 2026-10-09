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
        // ★ (2026-10-09) 재생·일시정지·이전·다음 아이콘을 둥글게(펜닐 요청 — Video.js 처럼 모서리가 둥근 삼각형·막대). 크기·자리는 그대로.
        play:  '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M8 6.82v10.36a1.5 1.5 0 0 0 2.3 1.27l8.14-5.18a1.5 1.5 0 0 0 0-2.54L10.3 5.55A1.5 1.5 0 0 0 8 6.82z"/></svg>',
        pause: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4.5" height="14" rx="1.6"/><rect x="13.5" y="5" width="4.5" height="14" rx="1.6"/></svg>',
        vol:   '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 9a4 4 0 010 6M18.5 6.5a7.5 7.5 0 010 11"/></svg>',
        mute:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M16 9.5l5 5M21 9.5l-5 5"/></svg>',
        loop:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3l3 3-3 3"/><path d="M4 11V9.5A3.5 3.5 0 017.5 6H20"/><path d="M7 21l-3-3 3-3"/><path d="M20 13v1.5a3.5 3.5 0 01-3.5 3.5H4"/></svg>',
        gear:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/></svg>',
        pip:   '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><rect x="12" y="11" width="7" height="6" rx="1" fill="currentColor"/></svg>',
        prev:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><rect x="5.5" y="5" width="2.6" height="14" rx="1.3"/><path d="M19 7.1v9.8a1.3 1.3 0 0 1-2 1.1l-7.4-4.9a1.3 1.3 0 0 1 0-2.2L17 6a1.3 1.3 0 0 1 2 1.1z"/></svg>',
        next:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><rect x="15.9" y="5" width="2.6" height="14" rx="1.3"/><path d="M5 7.1v9.8a1.3 1.3 0 0 0 2 1.1l7.4-4.9a1.3 1.3 0 0 0 0-2.2L7 6a1.3 1.3 0 0 0-2 1.1z"/></svg>',
        close: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
        info:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5"/><circle cx="12" cy="7.8" r="0.6" fill="currentColor"/></svg>',
        fs:    '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5"/></svg>',
        // ★ (2026-10-09) ⚙ 설정 목록(유튜브처럼 — 펜닐 요청)용 아이콘
        speed: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 18a8.5 8.5 0 1 1 15 0"/><path d="M12 13.5l4-4"/></svg>',
        mode:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M10 8.7l5 3.3-5 3.3z"/></svg>',
        subf:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 11h10M7 15h4M13 15h4"/></svg>',
        subs:  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8 12h8M12 9v6"/></svg>',
        chevR: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>',
        chevL: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>',
        check: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>'
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
        // ★ (2026-10-09) 설정 창은 목록형(유튜브처럼) — menuPage: 'main'(목록) 또는 하위 목록 이름. 닫으면 처음(목록)으로.
        let menuPage = 'main';
        const setMenuOpen = (open) => { menu.hidden = !open; menu.style.display = open ? '' : 'none'; wrap.classList.toggle('fsvs-menu-open', !!open); if (!open) menuPage = 'main'; };
        menu.classList.add('fsvs-lst');
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
        // ★ (2026-10-09) 반복·구간 반복·재생 속도를 바꾸면 음량 표시와 같은 자리에 잠깐 알림(펜닐 요청). 바꾼 '뒤'의 실제 상태를 읽어 보여준다
        //   (조작 줄 버튼·⚙ '더 보기' 칩·속도 칩 — 이 셋 말고는 바꾸는 길이 없음: 반복·구간 반복·속도에는 단축키가 없다). 상태를 바꾸지는 않는다.
        const announce = (kind, val) => {
            try {
                if (kind === 'loop') {
                    const lo = ctx.isLoop ? ctx.isLoop() : video.loop;
                    showMsgOsd(lo ? T('video_loop_one_on_s', '이 영상만 반복: 켬') : T('video_loop_one_off', '이 영상만 반복: 끔'));
                } else if (kind === 'ab') {
                    const st = (ctx.abState && ctx.abState()) || {};
                    const a = st.a != null, b = st.b != null;
                    showMsgOsd(!a ? T('fsvs_osd_ab_off', '구간 반복: 끔')
                        : (!b ? T('fsvs_osd_ab_a', '구간 반복: A 지점') + ' ' + fmt(st.a)
                              : T('video_ab', '구간 반복') + ' ' + fmt(st.a) + ' ~ ' + fmt(st.b)));
                } else if (kind === 'speed') {
                    showMsgOsd(T('playback_speed', '재생 속도') + ' ' + val + 'x');
                }
            } catch (e) {}
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
            spQueueMode = 's';
        };
        // ★ (2026-10-09) 긴 mp4(10분 넘게 — 서버가 키프레임 장면을 쓰는 영상)는 미리보기 구간을 '키프레임마다'(펜닐 승인).
        //   종전 5·10초 간격은 키프레임 간격과 어긋나 같은 그림이 이어지거나(키프레임 간격 > 간격) 사이 장면을 건너뛰었다(키프레임 간격 < 간격).
        //   서버가 목차(moov)만 읽어 준 키프레임 시각(ctx.keyTimesUrl → FileManager::videoKeyTimesFile)으로, 키프레임 k 마다 요청 초 s = ceil(k) 를
        //   쓴다 — 서버는 s 이하의 마지막 키프레임(= k)으로 맞추므로(frameTarget) 같은 장면이 겹치지 않는다. 다음 키프레임이 s 이내면(1초보다 촘촘) 건너뜀.
        //   목록을 못 받으면(다른 형식·실패·짧은 영상) 종전 간격 방식 그대로. 영상 하나에 한 번만 받는다(주소가 같으면 다시 안 받음).
        let spK = null, spKLoading = null, spKLoadAt = 0, spQueueMode = 's', spLastShow = null;
        const spKeysEnsure = () => {
            let u = null;
            try { u = (typeof ctx.keyTimesUrl === 'function') ? ctx.keyTimesUrl() : null; } catch (e) { u = null; }
            if (!u || typeof fetch !== 'function') return null;
            if (spK && spK.url === u) return spK.ok ? spK : null;
            if (spKLoading === u) return null;
            spKLoading = u; spKLoadAt = Date.now();
            fetch(u, { credentials: 'same-origin', cache: 'no-store' })
                .then((r) => (r.ok ? r.json() : null))
                .then((j) => {
                    if (spDead || spKLoading !== u) return;
                    spKLoading = null;
                    spK = { url: u, ok: false };
                    if (!j || !j.ok || !Array.isArray(j.keys)) return;
                    const ks = j.keys.map(Number).filter((v) => isFinite(v) && v >= 0).sort((a, b) => a - b);
                    const t = [], r = [];
                    for (let i = 0; i < ks.length; i++) {
                        const s = Math.ceil(ks[i] - 0.001);
                        const next = (i + 1 < ks.length) ? ks[i + 1] : Infinity;
                        if (s + 0.01 >= next) continue;                 // 이 요청 초가 다음 키프레임을 가리키게 되는 촘촘한 구간은 건너뜀
                        if (r.length && s <= r[r.length - 1]) continue;
                        t.push(ks[i]); r.push(s);
                    }
                    if (t.length < 2) return;
                    spK = { url: u, ok: true, d: Number(j.d) || 0, t, r };
                    spCurKey = null;
                    // 미리 받기 목록을 바로 키프레임 기준으로 바꾼다(길이가 맞을 때 — 아니면 다음 표시 때 판단). 받는 중인 묶음 응답은 새 목록에 이어 붙어도 무해.
                    try { const d0 = total(); if (d0 > 600 && (!spK.d || Math.abs(spK.d - d0) < 3)) spBuildQueueKeys(spK, d0); else spQueueMode = ''; } catch (e) { spQueueMode = ''; }
                    // ★ (2026-10-09) 재검토 — 재생바에 마우스를 댄 채로 목록이 오면 그 자리를 키프레임 기준으로 바로 다시 보여준다(종전엔 마우스를 움직여야 바뀜)
                    try { if (spOn && spLastShow) spShow(spLastShow[0], spLastShow[1]); } catch (e) {}
                    if (spDiagOk < 3) spDiag({ ev: 'keys', n: t.length, raw: ks.length });
                })
                .catch(() => { if (spKLoading === u) { spKLoading = null; spK = { url: u, ok: false }; } });
            return null;
        };
        const spBuildQueueKeys = (kk, d) => {   // 키프레임 요청 초 중 최대 150개를 고르게 — 순서는 종전과 같은 비트 반전(몇 장만 받아도 전체에 고르게)
            const all = kk.r, n = Math.min(150, all.length), keys = [];
            for (let i = 0; i < n; i++) { const k = all[Math.min(all.length - 1, Math.floor((i + 0.5) * all.length / n))]; if (keys[keys.length - 1] !== k) keys.push(k); }
            let bits = 0; while ((1 << bits) < keys.length) bits++;
            const rev = (i) => { let r = 0; for (let b = 0; b < bits; b++) if (i & (1 << b)) r |= 1 << (bits - 1 - b); return r; };
            spQueue = keys.map((k, i) => [rev(i), k]).sort((a, b) => a[0] - b[0]).map((x) => x[1]);
            spQueueDur = Math.round(d);
            spQueueMode = 'k';
        };
        // ★ (2026-10-09) 영상이 이동 중(seeking)이거나 재생 중인데 다음 장면을 못 받아 기다리는 중(readyState < 3)이면 true — 이때는 재생바 미리보기
        //   '미리 받기'(서버 ffmpeg 묶음·한 장씩)를 0.7초 뒤로 미룬다(펜닐 승인: 탐색이 빨랐다 느렸다 함 — 미리 받기가 같은 파일을 읽으며 서버 자원을 나눠 썼다).
        //   사용자가 재생바에 올린 자리의 장면 요청은 미루지 않는다. 판단에 실패하면 false(종전처럼 받음).
        const spMediaBusy = () => { try { return !!(video.seeking || (!video.paused && !video.ended && video.readyState < 3)); } catch (e) { return false; } };
        const spPump = () => {   // 쉬는 중이면 다음 것을 받는다 — 사용자가 보는 구간 → 묶음으로 준비된 것 → 한 장씩 할 것 → (묶음 미지원이면) 미리 받기
            if (spDead || spLoadingKey !== null) return;
            let key = null;
            if (spWant !== null && !spDone.has(spWant)) { key = spWant; }
            spWant = null;
            while (key === null && spReady.length) { const k = spReady.shift(); if (!spDone.has(k)) key = k; }
            while (key === null && spSingle.length) { const k = spSingle.shift(); if (!spDone.has(k) && !spFailed.has(k) && canSeekTo(k)) key = k; }
            // ★ (2026-10-09) 영상이 이동 중·받는 중이면 미리 받기는 잠깐 쉰다(spMediaBusy) — 사용자가 올린 자리(spWant)·묶음으로 이미 만든 것은 그대로
            const _mBusy = spMediaBusy();
            while (key === null && !_mBusy && !framesUrl([0]) && spQueue && spQueue.length) {
                const k = spQueue.shift();
                if (!spDone.has(k) && !spFailed.has(k) && canSeekTo(k)) key = k;
            }
            if (key === null && _mBusy && !framesUrl([0]) && spQueue && spQueue.length && !spRetryTimer) {
                spRetryTimer = setTimeout(() => { spRetryTimer = null; spPump(); }, 700);
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
            if (spMediaBusy()) {
                if (!spBatchTimer) spBatchTimer = setTimeout(() => { spBatchTimer = null; spBatchPump(); }, 700);
                return;
            }
            // ★ (2026-10-09) 재검토 — 키프레임 목록을 받는 중이면(긴 mp4 첫 표시) 첫 묶음을 잠깐(최대 2초) 기다린다 — 종전엔 목록이 오기 직전
            //   종전 간격(5·10초)으로 20장을 먼저 만들어 영상마다 한 묶음이 낭비됐다. 2초가 지나도 안 오면 종전 목록 그대로 진행.
            if (spKLoading && Date.now() - spKLoadAt < 2000) {
                if (!spBatchTimer) spBatchTimer = setTimeout(() => { spBatchTimer = null; spBatchPump(); }, 200);
                return;
            }
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
            spLastShow = [ratio, clientX];
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
            let key = Math.floor(tt / step) * step;
            // ★ (2026-10-09) 긴 mp4 는 키프레임마다(spKeysEnsure) — 목록이 있고 길이가 맞으면(3초 이내) tt 이하의 마지막 키프레임의 요청 초
            const kk = spAcc ? null : spKeysEnsure();
            const useK = !!(kk && (!kk.d || Math.abs(kk.d - d) < 3));
            if (useK) {
                let lo = 0, hi = kk.t.length - 1, bi = 0;
                while (lo <= hi) { const mid = (lo + hi) >> 1; if (kk.t[mid] <= tt + 0.001) { bi = mid; lo = mid + 1; } else hi = mid - 1; }
                key = kk.r[bi];
            }
            if (spQueue === null || spQueueDur !== Math.round(d) || spQueueMode !== (useK ? 'k' : 's')) {   // 처음 보일 때(길이·방식이 바뀌면 다시) 미리 받기 목록
                if (useK) spBuildQueueKeys(kk, d); else spBuildQueue(d, step);
            }
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
        on(bLoop, 'click', () => { call(ctx.toggleLoop); announce('loop'); });
        on(bAb, 'click', () => { call(ctx.clickAb); announce('ab'); });
        on(bCc, 'click', () => call(ctx.toggleCc));
        on(bPip, 'click', () => call(ctx.togglePip));
        on(bFs, 'click', () => call(ctx.toggleFs));
        on(bSpeed, 'click', () => { setMenuOpen(true); menuPage = 'speed'; buildMenu(); fitMenu(); render(); });   // ★ (2026-10-09) 속도 칩은 바로 '재생 속도' 목록으로
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
        // ★ (2026-10-09) 목록형으로 바꾸며 단순화(펜닐 요청 — 한 화면에 다 펼치던 칩 배치를 유튜브처럼 목록 → 하위 목록으로).
        //   [종전] 칩을 한 번에 다 보이게 하려고 두 칸 → 세 칸 → 촘촘하게 → 비율 축소 → 휴대폰 판 순서로 맞췄다(2026-09-23~10-08).
        //   [지금] 한 칸 목록(폭 최대 300px). 플레이어 안 높이(공유는 플레이어 위 빈 곳 ctx.menuRoomAbove 까지)를 넘으면 창 안에서 스크롤
        //   (긴 하위 목록 — 음성 11개 등, 유튜브와 같음 — 펜닐 승인). 휴대폰(창의 짧은 쪽 600px 미만)에서 플레이어가 너무 낮으면(목록이
        //   들어갈 높이 220px 미만) 종전처럼 화면 아래에서 올라오는 판 — 조작 줄이 보이면 그 바로 위에(⚙ 를 덮지 않게).
        function fitMenuInner() {
            if (menu.hidden) return;
            menu.classList.remove('fsvs-2col', 'fsvs-3col', 'fsvs-compact', 'fsvs-sheet', 'fsvs-raised');
            menu.style.transform = ''; menu.style.width = ''; menu.style.maxHeight = ''; menu.style.overflowY = ''; menu.style.right = '';
            const barH = bar.offsetHeight || 0;
            menu.style.bottom = (barH + 8) + 'px';
            let availH = (wrap.clientHeight || 0) - barH - 16;
            const availW = (wrap.clientWidth || 0) - 24;
            if (availH <= 0 || availW <= 0) return;   // 배치 정보를 못 얻으면(숨김 등) 그대로 둔다
            const phone = Math.min(window.innerWidth || 0, window.innerHeight || 0) < 600;
            if (phone && (availH < 220 || availW < 240)) {
                menu.style.bottom = '';
                menu.classList.add('fsvs-sheet');
                const vh = window.innerHeight || 0;
                let above = 0;
                try {
                    const br = bar.getBoundingClientRect();
                    if (br.height > 0 && br.top < vh && br.bottom > 0) above = Math.max(0, Math.round(vh - br.top) + 8);
                } catch (e) {}
                if (above > 0) { menu.style.bottom = above + 'px'; menu.classList.add('fsvs-raised'); }
                const maxH = Math.floor((vh - above) * 0.9);
                if (maxH > 0 && menu.scrollHeight > maxH) { menu.style.maxHeight = maxH + 'px'; menu.style.overflowY = 'auto'; }
                return;
            }
            const extra = (!phone && typeof ctx.menuRoomAbove === 'function') ? Math.max(0, Math.floor(Number(ctx.menuRoomAbove()) || 0)) : 0;
            // 좁은 플레이어(휴대폰 세로 등 — 남는 폭 400px 미만)는 좌우 8px 만 띄우고 꽉 차게(300px 로 두면 한쪽에 치우쳐 보였다 — 시험에서 확인)
            if (availW < 400) { menu.style.width = Math.max(0, (wrap.clientWidth || 0) - 16) + 'px'; menu.style.right = '8px'; }
            else menu.style.width = Math.min(300, availW) + 'px';
            if (menu.scrollHeight > availH) {
                const h = (extra > 0) ? Math.min(availH + extra, menu.scrollHeight) : availH;
                menu.style.maxHeight = h + 'px';
                if (menu.scrollHeight > h) menu.style.overflowY = 'auto';
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
        // ★ (2026-10-09) ⚙ 설정 — 목록형(유튜브처럼, 펜닐 요청). 목록: [아이콘 · 이름 · 현재 값 · ›] → 누르면 하위 목록([‹ 제목] + 고른 것에 ✓).
        //   항목이 실제로 하는 일(속도·화질·재생 방식·음성·자막 파일·자막 크기/위치/싱크·더 보기의 정보·음소거·반복·구간 반복)은 종전 칩과 **같은 호출**.
        //   재생 속도는 고르면 목록으로 돌아가 바뀐 값이 보이고, 화질·재생 방식·음성·자막 파일은 종전처럼 고르면 창을 닫는다(영상을 다시 불러옴).
        //   ★ 보안(2026-09-25 그대로) — 항목 글자는 HTML 이 아니라 글자 그대로(textContent): 음성 이름 등은 동영상 파일 안의 정보라 HTML 로 넣으면
        //   제목에 스크립트를 넣은 파일로 ⚙ 에서 실행될 수 있었다. 아이콘만 이 파일의 고정 SVG(innerHTML).
        // ★ (2026-09-23) 누른 칩(지금은 자막 −/+ 단추)은 메뉴를 다시 그린 뒤에도 잠깐 강조한다(flashKey) — 누른 반응이 보이게.
        let flashKey = null;
        const txt = (cls, t) => { const e = el('span', cls); e.textContent = (t == null) ? '' : String(t); return e; };
        const ico = (svg) => { const e = el('span', 'fsvs-mi'); if (svg) e.innerHTML = svg; return e; };
        const rebuild = () => { buildMenu(); fitMenu(); render(); };
        const goPage = (p) => {   // 키보드로 고르던 중이면(포커스가 창 안) 새 목록의 첫 줄로 포커스를 옮긴다 — 다시 그리면 누른 단추가 사라지므로
            // 키보드로 온 포커스만(:focus-visible) — 마우스로 누른 줄에도 포커스가 남아, 그때 옮기면 다음 단축키에 테두리가 생겼다(시험에서 발견)
            let kb = false; try { const ae = document.activeElement; kb = !!(ae && menu.contains(ae) && ae.matches && ae.matches(':focus-visible')); } catch (e) {}
            menuPage = p; rebuild();
            if (kb) { try { const f = menu.querySelector('.fsvs-mback, .fsvs-mrow'); if (f) f.focus(); } catch (e) {} }
        };
        let menuSelRef = null;   // ★ (2026-10-09) 재검토 — 화질·재생 방식·음성 하위 목록은 '몇 번째 상자'가 아니라 상자 자체로 기억(열린 사이 상자가 늘거나 줄어도 엉뚱한 목록이 안 나오게)
        const navRow = (icon, label, value, page, pre) => {   // 목록 → 하위 목록
            const b = btn('fsvs-mrow fsvs-mnav', null, label);
            b.append(ico(icon), txt('fsvs-ml', label), txt('fsvs-mv', value));
            const c = el('span', 'fsvs-mc'); c.innerHTML = ICON.chevR; b.append(c);
            b.addEventListener('click', () => { if (pre) pre(); goPage(page); });
            menu.append(b);
        };
        const toggleRow = (icon, label, on_, fn) => {   // 켬/끔
            const b = btn('fsvs-mrow fsvs-mtog' + (on_ ? ' is-on' : ''), null, label);
            b.setAttribute('role', 'switch'); b.setAttribute('aria-checked', on_ ? 'true' : 'false');
            b.append(ico(icon), txt('fsvs-ml', label), el('span', 'fsvs-sw'));
            b.addEventListener('click', () => { try { fn(); } catch (e) {} rebuild(); });
            menu.append(b);
        };
        const actRow = (icon, label, value, fn, keepOpen) => {   // 누르면 바로 실행
            const b = btn('fsvs-mrow fsvs-mact', null, label);
            b.append(ico(icon), txt('fsvs-ml', label), txt('fsvs-mv', value));
            b.addEventListener('click', () => { try { fn(); } catch (e) {} if (keepOpen && !menu.hidden) rebuild(); else render(); });
            menu.append(b);
        };
        const backHead = (title) => {   // 하위 목록 머리줄 — ‹ 제목(누르면 목록으로) + 휴대폰 판에서만 ✕(CSS)
            const h = el('div', 'fsvs-mhead');
            const b = btn('fsvs-mback', null, T('fsvs_back', '뒤로'));
            const c = el('span', 'fsvs-mbi'); c.innerHTML = ICON.chevL;
            b.append(c, txt('fsvs-mt', title));
            b.addEventListener('click', () => goPage('main'));
            const x = btn('fsvs-menuclose fsvs-mx', ICON.close, T('close', '닫기'));
            x.addEventListener('click', () => { setMenuOpen(false); render(); });
            h.append(b, x);
            menu.append(h);
        };
        const optRow = (label, active, fn) => {   // 하위 목록의 선택지(고른 것에 ✓)
            const b = btn('fsvs-mrow fsvs-mopt' + (active ? ' is-on' : ''), null, label);
            b.setAttribute('role', 'menuitemradio'); b.setAttribute('aria-checked', active ? 'true' : 'false');
            const c = el('span', 'fsvs-mck'); if (active) c.innerHTML = ICON.check;
            b.append(c, txt('fsvs-ml', label));
            b.addEventListener('click', () => { try { fn(); } catch (e) {} });
            menu.append(b);
        };
        const stepBtn = (label, name) => {   // 자막 −/+ (종전 칩과 같은 ctx.subAct)
            const b = btn('fsvs-mstepb', null, label);
            b.textContent = label;
            if (flashKey === name) { b.classList.add('is-flash'); setTimeout(() => b.classList.remove('is-flash'), 450); }
            b.addEventListener('click', () => { flashKey = name; try { if (ctx.subAct) ctx.subAct(name); } catch (e) {} rebuild(); flashKey = null; });
            return b;
        };
        const stepRow = (label, value, down, up) => {
            const r = el('div', 'fsvs-mstep');
            r.append(txt('fsvs-ml', label), stepBtn('−', down), txt('fsvs-msv', value), stepBtn('+', up));
            menu.append(r);
        };
        const rateLabel = (v) => (Math.abs(v - 1) < 0.001 ? T('fsvs_normal', '보통') + ' (1x)' : (+v.toFixed(2)) + 'x');
        // 페이지가 넘겨준 선택 상자 분류(종전과 같은 기준) — 탐색기는 class, 공유 페이지는 id 로 구분
        const selKind = (sel) => {
            const isAudio = sel.classList.contains('audio-track-select') || sel.id === 'audio-track-picker' || sel.id === 'share-audio-select';
            const isMode = sel.classList.contains('playback-mode-select') || /playback-mode/.test(sel.id || '');
            return isAudio ? 'audio' : (isMode ? 'mode' : 'quality');
        };
        const subVals = () => {   // 자막 크기·위치·싱크 현재 값(종전과 같이 자막 요소 인라인 스타일에서 읽음)
            const ov = wrap.querySelector('.subtitle-overlay');
            const em = ov ? parseFloat(ov.style.fontSize) : NaN;
            const bt = ov ? parseFloat(ov.style.bottom) : NaN;
            const off = (ctx.subSync && ctx.subSync()) || 0;
            return {
                size: isFinite(em) ? Math.round(em * 100) + '%' : T('fsvs_default', '기본'),
                pos: isFinite(bt) ? Math.round(bt) + '%' : T('fsvs_default', '기본'),
                sync: (off > 0 ? '+' : '') + off.toFixed(1) + T('sec', '초')
            };
        };
        // ★ (2026-10-09) 재검토 — Esc 로 설정 창만(유튜브처럼): 하위 목록이면 목록으로, 목록이면 창을 닫는다. 처리했으면 true.
        //   탐색기는 앱의 Esc 처리(미리보기 창 닫기)가 먼저 돌므로 앱이 FSVideoSkin.menuEscape(wrap) 로 먼저 묻는다(app.js). 공유는 아래 창(window) 단계 처리.
        const menuEscape = () => {
            if (menu.hidden) return false;
            if (menuPage !== 'main') goPage('main'); else { setMenuOpen(false); render(); }
            return true;
        };
        on(window, 'keydown', (e) => {
            if (e.key !== 'Escape' || menu.hidden || e.defaultPrevented) return;
            if (menuEscape()) { e.preventDefault(); e.stopImmediatePropagation(); }
        }, true);
        function buildMenu() {
            menu.textContent = '';
            const sels = ((ctx.selects && ctx.selects()) || []).filter((sel) => sel && sel.options && sel.options.length >= 2);
            const hasAudioSel = sels.some((s) => selKind(s) === 'audio');
            const na = (!hasAudioSel && ctx.nativeAudio) ? ctx.nativeAudio() : null;   // ★ (2026-09-24) 일반 재생 중 다국어 음성(트랜스코딩 상자가 없을 때만)
            const naOk = !!(na && Array.isArray(na.tracks) && na.tracks.length >= 2 && typeof na.select === 'function');
            const ccOn = !!(ctx.hasCc && ctx.hasCc());
            const st = ccOn && ctx.subTracks ? ctx.subTracks() : null;   // ★ (2026-10-08) 자막 파일 고르기 — 2개 이상일 때만
            const stOk = !!(st && Array.isArray(st.tracks) && st.tracks.length >= 2 && typeof st.select === 'function');
            const page = menuPage;
            // ── 하위 목록 ──
            if (page === 'speed') {
                backHead(T('playback_speed', '재생 속도'));
                SPEEDS.forEach((v) => optRow(rateLabel(v), Math.abs(video.playbackRate - v) < 0.001, () => {
                    if (ctx.setSpeed) ctx.setSpeed(v); else video.playbackRate = v;
                    announce('speed', v);
                    goPage('main');
                }));
                return;
            }
            if (page === 'sel') {
                const sel = (menuSelRef && sels.indexOf(menuSelRef) >= 0) ? menuSelRef : null;
                if (sel) {
                    const k = selKind(sel);
                    backHead(k === 'audio' ? T('audio_track', '오디오') : (k === 'mode' ? T('playback_mode', '재생 방식') : T('quality', '화질')));
                    Array.from(sel.options).forEach((o) => optRow(o.textContent, sel.value === o.value, () => {
                        if (sel.value === o.value) { goPage('main'); return; }
                        sel.value = o.value;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                        setMenuOpen(false); render();
                    }));
                    return;
                }
                menuPage = 'main';   // 상자가 사라졌으면(영상 전환 등) 목록으로
            }
            if (page === 'naudio' && naOk) {
                backHead(T('audio_track', '오디오'));
                na.tracks.forEach((tk, i) => optRow(String(tk.label || ('Track ' + (i + 1))), !!tk.on, () => {
                    if (tk.on) { goPage('main'); return; }
                    try { na.select(i); } catch (e) {}
                    setMenuOpen(false); render();
                }));
                return;
            }
            if (page === 'subfile' && stOk) {
                backHead(T('fsvs_sub_file', '자막 파일'));
                st.tracks.forEach((tk, i) => optRow(String(tk.label || ('#' + (i + 1))), !!tk.on, () => {
                    if (tk.on) { goPage('main'); return; }
                    try { st.select(i); } catch (e) {}
                    setMenuOpen(false); render();
                }));
                return;
            }
            if (page === 'subset' && ccOn) {
                backHead(T('fsvs_sub_settings', '자막 설정'));
                const v = subVals();
                stepRow(T('fsvs_sub_size', '크기'), v.size, 'size-down', 'size-up');
                stepRow(T('fsvs_sub_pos', '위치'), v.pos, 'pos-down', 'pos-up');
                stepRow(T('fsvs_sub_sync', '싱크'), v.sync, 'sync-down', 'sync-up');
                actRow(null, '↺ ' + T('fsvs_sync_reset', '싱크 0초로'), '', () => { if (ctx.subAct) ctx.subAct('sync-reset'); }, true);
                return;
            }
            menuPage = 'main';
            // ── 목록 ──
            // ★ (2026-09-23) 머리줄(제목 + ✕ 닫기) — 화면 아래 판일 때만 보인다(CSS). 판이 ⚙ 를 가려도 닫을 수 있게.
            const head = el('div', 'fsvs-menuhead');
            head.append(el('div', 'fsvs-menutitle', T('settings', '설정')));
            const bClose = btn('fsvs-menuclose', ICON.close, T('close', '닫기'));
            bClose.addEventListener('click', () => { setMenuOpen(false); render(); });
            head.append(bClose);
            menu.append(head);
            // ★ (2026-09-23) 자리가 모자라 조작 줄에서 접힌 버튼(fitRow) — 상태와 함께 목록 맨 위에
            const folded = collapsible.filter((b) => b.classList.contains('fsvs-collapsed'));
            if (folded.includes(bInfo)) actRow(ICON.info, T('vi_dlg_title', '동영상 정보'), '', () => { setMenuOpen(false); try { ctx.showInfo(); } catch (e) {} });
            if (folded.includes(bVol)) toggleRow(ICON.vol, T('mute', '음소거'), video.muted || video.volume === 0, () => { video.muted = !video.muted; });
            if (folded.includes(bLoop)) toggleRow(ICON.loop, T('video_loop_one', '이 영상만 반복'), !!(ctx.isLoop ? ctx.isLoop() : video.loop), () => { if (ctx.toggleLoop) ctx.toggleLoop(); announce('loop'); });
            if (folded.includes(bAb)) {
                const ab_ = (ctx.abState && ctx.abState()) || {};
                const a = ab_.a != null, b = ab_.b != null;
                const val = !a ? T('fsvs_ab_set_a', '구간 반복: A 지정') : (!b ? T('fsvs_ab_set_b', '구간 반복: B 지정') : T('fsvs_ab_clear', '구간 반복 해제'));
                const abIco = '<b class="fsvs-mab">A-B</b>';
                actRow(abIco, T('video_ab', '구간 반복') + ((a && b) ? ' ' + fmt(ab_.a) + ' ~ ' + fmt(ab_.b) : (a ? ' A ' + fmt(ab_.a) : '')), val.replace(/^.*?:\s*/, ''), () => { if (ctx.clickAb) ctx.clickAb(); announce('ab'); }, true);
            }
            navRow(ICON.speed, T('playback_speed', '재생 속도'), rateLabel(video.playbackRate).replace(' (1x)', ''), 'speed');
            sels.forEach((sel) => {
                const k = selKind(sel);
                const cur = sel.options[sel.selectedIndex];
                navRow(k === 'audio' ? ICON.vol : (k === 'mode' ? ICON.mode : ICON.gear),
                    k === 'audio' ? T('audio_track', '오디오') : (k === 'mode' ? T('playback_mode', '재생 방식') : T('quality', '화질')),
                    cur ? cur.textContent : '', 'sel', () => { menuSelRef = sel; });
            });
            if (naOk) { const on_ = na.tracks.find((t) => t.on); navRow(ICON.vol, T('audio_track', '오디오'), on_ ? String(on_.label || '') : '', 'naudio'); }
            if (stOk) { const on_ = st.tracks.find((t) => t.on); navRow(ICON.subf, T('fsvs_sub_file', '자막 파일'), on_ ? String(on_.label || '') : '', 'subfile'); }
            if (ccOn) { const v = subVals(); navRow(ICON.subs, T('fsvs_sub_settings', '자막 설정'), v.size + ' · ' + v.pos + ' · ' + v.sync, 'subset'); }
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
        // ★ (2026-10-09) 포커스가 플레이어 밖(문서 본문·미리보기 창)에 있어도 단축키(스페이스·방향키 등)를 누르면 숨은 조작 줄을 다시 보인다.
        //   종전엔 위 wrap 의 keydown 만 있어 포커스가 플레이어 안 버튼에 있을 때만 떴다(탐색기는 미리보기 창으로 포커스가 옮겨져 두 번째 키부터 안 뜸,
        //   공유는 버튼 포커스를 빼는 테두리 수정 뒤 같은 상태). 플레이어가 화면에 보일 때만, 입력칸 입력·조합 키(Ctrl/⌘+)·수정 키 단독은 제외.
        //   캡처 단계라 다른 단축키 처리가 전파를 막아도 받는다. 보이기만 하고 키 동작은 건드리지 않음.
        on(document, 'keydown', (e) => {
            try {
                if (!wrap.isConnected || !wrap.getClientRects().length) return;
                if (e.ctrlKey || e.metaKey || e.key === 'Shift' || e.key === 'Control' || e.key === 'Alt' || e.key === 'Meta') return;
                const t = e.target;
                if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
                if (t && wrap.contains(t)) return;   // 플레이어 안이면 위 wrap 처리가 이미 함
                wake();
            } catch (x) {}
        }, true);
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
            menuEscape,               // ★ (2026-10-09) Esc — 설정 창이 열려 있으면 그 창만(FSVideoSkin.menuEscape(wrap))
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
    // ★ (2026-10-09) Esc — 설정 창이 열려 있으면 하위 목록 → 목록 → 닫기 순으로 처리하고 true(탐색기 앱의 Esc 처리가 먼저 묻는다). 아니면 false.
    function menuEscape(wrap) { try { return !!(wrap && wrap._fsvs && typeof wrap._fsvs.menuEscape === 'function' && wrap._fsvs.menuEscape()); } catch (e) { return false; } }
    window.FSVideoSkin = { attach, detach, showVolume, showMessage, menuEscape, version: '1' };
})();
