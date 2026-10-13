/**
 * FSEQ — FileStation 이퀄라이저 (음악·동영상 플레이어) ★ (2026-10-11, 펜닐 요청)
 *
 * [구성]
 *  · 10밴드 그래픽 EQ — ISO 266 옥타브 대역(31.5·63·125·250·500·1k·2k·4k·8k·16kHz, VLC 의 ISO 대역과 같음), 밴드마다 ±12dB(0.1dB 단위),
 *    켬/끔, 프리셋 18개 + 사용자 설정.
 *  · ★ 프리셋 값은 VLC 소스(modules/audio_filter/equalizer_presets.h, LGPL 2.1+ — Winamp 에서 내려온 사실상 표준 값)를 그대로 옮겼다.
 *    VLC 의 -1.11022e-15(사실상 0)는 0 으로, 우리 범위(±12dB)를 넘는 값(Full treble 16·16.8, Headphones 12.8·14.4)은 12 로 잘랐다.
 *    VLC 의 프리앰프 값은 VLC 내부 음량 맞춤용이라 옮기지 않고, 대신 가장 많이 올린 만큼 앞단 음량을 낮춘다(아래 프리앰프 자동).
 *    VLC 도 ISO 대역 모드에서 같은 프리셋 값을 그대로 쓴다.
 *  · ★ 음악·동영상 설정은 따로(펜닐 요청) — 상태 안에 music·video 두 묶음(각각 켬/끔·프리셋·사용자 값). 종전(공통 1개, v1) 값은 읽을 때 양쪽에 복사.
 *  · 저장: 바꾸면 곧바로 브라우저(localStorage 'fs_eq'), 탐색기(로그인)는 앱이 넘겨준 저장 함수(setRemoteSaver)로 계정(user_preferences
 *    'eq_settings')에도 0.7초 뒤 한 번. 공유 페이지는 브라우저에만. 계정 값과 브라우저 값 중 나중에 바꾼 것(t)이 이긴다(syncRemote).
 *    브라우저 값에 저장한 계정(u)을 함께 남겨, 다른 계정이 저장한 값은 내 계정에 올리지 않는다. 같은 브라우저의 다른 탭도 바로 따라온다.
 *  · 소리 경로: 음악 플레이어는 이미 비주얼라이저 때문에 Web Audio 를 거치므로(app.js·fs-audio-player.js _initVisualizer)
 *    그 사이에 createChain(ctx, 'music') 필터 줄을 끼운다. 동영상은 bindMedia(video) — **동영상 EQ 를 켰을 때만, 그리고 오디오 처리기가
 *    실제로 돌아갈 수 있을 때만(running)** 영상의 소리를 Web Audio 로 돌린다. 한 번도 안 켠 사람은 종전과 완전히 같다.
 *    (돌아가지 않는 처리기로 돌리면 소리가 아예 안 나므로 — 자동 재생 정책 — 그때는 다음 사용자 조작까지 기다린다.)
 *  · 끄면 필터를 0dB(통과)·앞단 음량 1 로 되돌린다(이미 돌린 소리 경로는 요소당 한 번뿐이라 되돌릴 수 없어 그대로 두고 통과만 시킴).
 *  · 아이폰·아이패드는 지원하지 않는다(supported=false) — iOS 의 모든 브라우저는 WebKit 이고, Web Audio 를 거치면 잠금화면 백그라운드
 *    재생이 끊긴다(README B-5: 음악 비주얼라이저를 iOS 26.5 실기기에서 시험해 확인, 차단 유지). 앱(네이티브)은 다른 오디오 엔진이라 된다.
 *
 * [보안] 화면 글자는 모두 textContent(번역 글자 포함). 저장 값은 읽을 때마다 형식·범위를 검사(normalize)한다 — 서버(api.php)도 같은 검사.
 *
 * [사용]  profile = 'music' | 'video'
 *  FSEQ.supported
 *  FSEQ.createChain(audioCtx, 'music') → {input, output, dispose()}    // 음악 플레이어 그래프에 끼울 필터 줄
 *  FSEQ.bindMedia(video) / FSEQ.unbindMedia(video)                    // 동영상(스킨이 붙고 뗄 때) — 'video' 설정
 *  FSEQ.buildPanel({ profile, cls }) → {el, destroy()}                // 설정 화면(동영상 ⚙ 목록 안)
 *  FSEQ.openPopover(parent, { profile }) / closePopover() / escape()  // 음악 플레이어 팝업
 *  FSEQ.subscribe(fn) → 해제 함수, FSEQ.summary(profile)              // 상태 글자(예: '록', '끔')
 *  FSEQ.setRemoteSaver(fn), FSEQ.syncRemote(serverValue, uid)         // 계정 저장(탐색기)
 */
(function () {
    'use strict';
    if (window.FSEQ) return;

    // 번역: 탐색기는 window.t, 공유 페이지는 PHP 가 넣어 준 window.LANG_STRINGS (동영상 스킨과 같은 방식)
    const T = (k, d) => {
        try {
            if (typeof window.t === 'function') return window.t(k, d);
            if (window.LANG_STRINGS && window.LANG_STRINGS[k] !== undefined) return window.LANG_STRINGS[k];
        } catch (e) {}
        return d;
    };

    // ISO 266 옥타브 대역(표시 글자 겸 필터 중심 주파수)
    const BANDS = [31.5, 63, 125, 250, 500, 1000, 2000, 4000, 8000, 16000];
    const N = BANDS.length;
    const MAXDB = 12, STEP = 0.1, Q = 1.41;   // Q 1.41 ≈ 한 옥타브 폭(이웃 밴드와 고르게 겹침). 0.1dB 단위 — VLC 프리셋 값(7.2·5.6 등)을 그대로 담으려고
    // ★ (2026-10-11) 막대는 0.5dB 단위(방향키 한 번 0.5dB — 0.1dB 로 두니 끝에서 끝까지 240번이었다). 프리셋·저장 값은 0.1dB 그대로라
    //   프리셋을 고른 직후 막대 손잡이만 0.5 단위로 최대 0.2dB 어긋나 보일 수 있다(오른쪽 숫자와 실제 소리는 정확한 값).
    const SLIDER_STEP = 0.5;
    const ZEROS = () => new Array(N).fill(0);
    const PROFILES = ['music', 'video'];
    // 프리셋(dB) — VLC equalizer_presets.h (eqz_preset_10b) 그대로, ±12 초과만 12 로 자름
    const PRESETS = {
        flat:           [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        classical:      [0, 0, 0, 0, 0, 0, -7.2, -7.2, -7.2, -9.6],
        club:           [0, 0, 8.0, 5.6, 5.6, 5.6, 3.2, 0, 0, 0],
        dance:          [9.6, 7.2, 2.4, 0, 0, -5.6, -7.2, -7.2, 0, 0],
        fullbass:       [-8.0, 9.6, 9.6, 5.6, 1.6, -4.0, -8.0, -10.4, -11.2, -11.2],
        fullbasstreble: [7.2, 5.6, 0, -7.2, -4.8, 1.6, 8.0, 11.2, 12.0, 12.0],
        fulltreble:     [-9.6, -9.6, -9.6, -4.0, 2.4, 11.2, 12.0, 12.0, 12.0, 12.0],   // VLC 16·16·16·16.8 → 12
        headphones:     [4.8, 11.2, 5.6, -3.2, -2.4, 1.6, 4.8, 9.6, 12.0, 12.0],       // VLC 12.8·14.4 → 12
        largehall:      [10.4, 10.4, 5.6, 5.6, 0, -4.8, -4.8, -4.8, 0, 0],
        live:           [-4.8, 0, 4.0, 5.6, 5.6, 5.6, 4.0, 2.4, 2.4, 2.4],
        party:          [7.2, 7.2, 0, 0, 0, 0, 0, 0, 7.2, 7.2],
        pop:            [-1.6, 4.8, 7.2, 8.0, 5.6, 0, -2.4, -2.4, -1.6, -1.6],
        reggae:         [0, 0, 0, -5.6, 0, 6.4, 6.4, 0, 0, 0],
        rock:           [8.0, 4.8, -5.6, -8.0, -3.2, 4.0, 8.8, 11.2, 11.2, 11.2],
        ska:            [-2.4, -4.8, -4.0, 0, 4.0, 5.6, 8.8, 9.6, 11.2, 9.6],
        soft:           [4.8, 1.6, 0, -2.4, 0, 4.0, 8.0, 9.6, 11.2, 12.0],
        softrock:       [4.0, 4.0, 2.4, 0, -4.0, -5.6, -3.2, 0, 2.4, 8.8],
        techno:         [8.0, 5.6, 0, -5.6, -4.8, 0, 8.0, 9.6, 9.6, 8.8]
    };
    const ORDER = ['flat', 'classical', 'club', 'dance', 'fullbass', 'fullbasstreble', 'fulltreble', 'headphones', 'largehall', 'live',
        'party', 'pop', 'reggae', 'rock', 'ska', 'soft', 'softrock', 'techno', 'custom'];
    const NAMES = {
        flat: ['eq_p_flat', '플랫'], classical: ['eq_p_classical', '클래식'], club: ['eq_p_club', '클럽'], dance: ['eq_p_dance', '댄스'],
        fullbass: ['eq_p_fullbass', '저음 강조'], fullbasstreble: ['eq_p_fullbasstreble', '저음·고음 강조'], fulltreble: ['eq_p_fulltreble', '고음 강조'],
        headphones: ['eq_p_headphones', '헤드폰'], largehall: ['eq_p_largehall', '대형 홀'], live: ['eq_p_live', '라이브'], party: ['eq_p_party', '파티'],
        pop: ['eq_p_pop', '팝'], reggae: ['eq_p_reggae', '레게'], rock: ['eq_p_rock', '록'], ska: ['eq_p_ska', '스카'], soft: ['eq_p_soft', '소프트'],
        softrock: ['eq_p_softrock', '소프트 록'], techno: ['eq_p_techno', '테크노'], custom: ['eq_custom', '사용자 설정']
    };
    // 종전(첫 판) 프리셋 중 이름이 없어진 것 — 저장돼 있으면 소리가 바뀌지 않게 그 값을 '사용자 설정'으로 옮긴다
    const OLD_PRESETS = {
        bass: [6, 5, 4, 2, 0, 0, 0, 0, 0, 0], treble: [0, 0, 0, 0, 0, 1, 2, 4, 5, 6],
        vocal: [-2, -2, -1, 1, 3, 4, 3, 1, 0, -1], jazz: [3, 2, 1, 2, -1, -1, 0, 1, 2, 3]
    };
    const presetName = (p) => { const n = NAMES[p] || NAMES.flat; return T(n[0], n[1]); };
    const hzLabel = (f) => (f >= 1000 ? (f / 1000) + 'k' : String(f));
    const LS_KEY = 'fs_eq';

    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent || '') || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const AC = window.AudioContext || window.webkitAudioContext;
    const supported = !!AC && !isIOS;

    // ── 상태 ──────────────────────────────────────────────────────────────
    const clampG = (v) => { v = Number(v); if (!isFinite(v)) return 0; v = Math.max(-MAXDB, Math.min(MAXDB, v)); return Math.round(v * 10) / 10; };
    const profOk = (p) => (PROFILES.indexOf(p) >= 0 ? p : 'music');
    const defProf = () => ({ on: false, preset: 'flat', custom: ZEROS() });
    // 한 묶음(켬/끔·프리셋·사용자 값) 검사
    const normProf = (s) => {
        if (!s || typeof s !== 'object') return defProf();
        let custom = (Array.isArray(s.custom) && s.custom.length === N) ? s.custom.map(clampG) : ZEROS();
        let preset = ORDER.indexOf(s.preset) >= 0 ? s.preset : 'flat';
        // ★ (2026-10-11) 이름표에 직접 있는 이름만 — 'constructor'·'toString' 같은 내부 이름이 저장돼 있으면 읽다가 오류가 났다(재검토에서 재현)
        if (typeof s.preset === 'string' && Object.prototype.hasOwnProperty.call(OLD_PRESETS, s.preset)) { preset = 'custom'; custom = OLD_PRESETS[s.preset].slice(); }
        return { on: s.on === true, preset: preset, custom: custom };
    };
    // 저장 값 검사 — 형식이 틀리면 null(무시). v1(공통 1개)은 음악·동영상 양쪽에 같은 값으로.
    const normalize = (s) => {
        if (!s || typeof s !== 'object') return null;
        const t = Number(s.t);
        // u: 이 브라우저 값을 저장한 계정(탐색기 로그인 때만, 사용자 번호) — 한 브라우저를 여러 계정이 쓸 때 남의 값을 내 계정에 올리지 않으려고
        const u = (typeof s.u === 'string' && /^[\w-]{1,64}$/.test(s.u)) ? s.u : '';
        let music, video;
        if (s.music || s.video) { music = normProf(s.music); video = normProf(s.video); }
        else { music = normProf(s); video = normProf(s); }
        return { music: music, video: video, t: (isFinite(t) && t > 0) ? Math.floor(t) : 0, u: u };
    };
    const freshState = (u) => ({ music: defProf(), video: defProf(), t: 0, u: u || '' });
    let state = freshState('');
    try { const raw = localStorage.getItem(LS_KEY); if (raw) { const n = normalize(JSON.parse(raw)); if (n) state = n; } } catch (e) {}

    const gains = (p) => { const s = state[profOk(p)]; return (s.preset === 'custom' ? s.custom.slice() : PRESETS[s.preset].slice()); };
    const cp = (s) => ({ on: s.on, preset: s.preset, custom: s.custom.slice() });
    const serial = () => ({ v: 2, music: cp(state.music), video: cp(state.video), t: state.t, u: state.u || '' });
    const serialRemote = () => ({ v: 2, music: cp(state.music), video: cp(state.video), t: state.t });   // 계정에는 소유 표시 없이
    const getState = (p) => { p = profOk(p); const s = state[p]; return { profile: p, on: s.on, preset: s.preset, custom: s.custom.slice(), gains: gains(p), t: state.t }; };

    const listeners = new Set();   // fn(profile 상태) — 등록할 때 profile 을 함께 기억
    const chains = new Set();
    const emit = () => {
        chains.forEach((c) => { try { c.apply(); } catch (e) {} });
        listeners.forEach((fn) => { try { fn(getState(fn._fseqProfile)); } catch (e) {} });
    };

    let remoteSaver = null, remoteTimer = 0;
    const flushRemote = () => { if (remoteTimer) { clearTimeout(remoteTimer); remoteTimer = 0; } if (remoteSaver) { try { remoteSaver(serialRemote()); } catch (e) {} } };
    const persist = (remote) => {
        try { localStorage.setItem(LS_KEY, JSON.stringify(serial())); } catch (e) {}
        if (remote !== false && remoteSaver) {
            if (remoteTimer) clearTimeout(remoteTimer);
            remoteTimer = setTimeout(() => { remoteTimer = 0; try { remoteSaver(serialRemote()); } catch (e) {} }, 700);
        }
    };
    const update = (profile, patch) => {
        profile = profOk(profile);
        const next = Object.assign({}, state);
        next[profile] = Object.assign({}, state[profile], patch);
        next.t = Math.max(Date.now(), state.t + 1);
        state = next;
        persist();
        emit();
        connectAll();
    };
    // 다른 탭에서 바꾸면 따라간다(같은 브라우저)
    try {
        window.addEventListener('storage', (e) => {
            if (e.key !== LS_KEY || !e.newValue) return;
            let n = null; try { n = normalize(JSON.parse(e.newValue)); } catch (x) {}
            if (!n || n.t === state.t) return;
            state = n; emit(); connectAll();
        });
    } catch (e) {}
    // 페이지를 떠날 때 계정 저장이 남아 있으면 바로 보낸다
    try { window.addEventListener('pagehide', () => { if (remoteTimer) flushRemote(); }); } catch (e) {}

    // ── 필터 줄 ───────────────────────────────────────────────────────────
    //   앞단 음량(GainNode) → 피킹 필터 10개 → (호출한 쪽이 output 을 다음으로 잇는다)
    function createChain(ctx, profile) {
        if (!ctx || typeof ctx.createBiquadFilter !== 'function') return null;
        profile = profOk(profile);
        const pre = ctx.createGain();
        const filters = BANDS.map((f) => {
            const b = ctx.createBiquadFilter();
            b.type = 'peaking'; b.frequency.value = f; b.Q.value = Q; b.gain.value = 0;
            return b;
        });
        pre.connect(filters[0]);
        for (let i = 0; i < N - 1; i++) filters[i].connect(filters[i + 1]);
        let first = true;
        const c = {
            input: pre,
            output: filters[N - 1],
            apply() {
                const on = state[profile].on;
                const g = on ? gains(profile) : ZEROS();
                const p = on ? Math.pow(10, -Math.max(0, Math.max.apply(null, g)) / 20) : 1;
                const now = ctx.currentTime;
                const set = (param, v) => {
                    if (first) { param.value = v; return; }
                    try { param.setTargetAtTime(v, now, 0.015); } catch (e) { param.value = v; }   // 바꿀 때 '틱' 소리 없게 짧게 따라감
                };
                filters.forEach((b, i) => set(b.gain, g[i]));
                set(pre.gain, p);
                first = false;
            },
            dispose() {
                chains.delete(c);
                try { pre.disconnect(); } catch (e) {}
                filters.forEach((b) => { try { b.disconnect(); } catch (e) {} });
            }
        };
        c.apply();
        chains.add(c);
        return c;
    }

    // ── 동영상 소리 연결 ───────────────────────────────────────────────────
    //   요소 하나당 createMediaElementSource 는 한 번뿐이고 되돌릴 수 없다 → 켤 때(그리고 처리기가 running 일 때)만 연결.
    //   media: 요소 → { src, chain, bypass } (WeakMap — 요소가 사라지면 같이 정리), bound: 지금 스킨이 붙은 요소.
    let vctx = null;
    const media = new WeakMap();
    const bound = new Set();
    const orphans = new Set();   // 스킨은 떨어졌지만 소리 경로가 남은 요소(필터 없이 스피커로 바로) — 문서에서 빠지고 멈추면 정리
    let sweepTimer = 0, gestureArmed = false;
    const getCtx = () => { if (!vctx) { try { vctx = new AC(); } catch (e) { vctx = null; } } return vctx; };
    const armGesture = () => {   // 처리기가 사용자 조작 전이라 못 돌면 다음 클릭·키 입력 때 다시 시도
        if (gestureArmed) return;
        gestureArmed = true;
        const go = () => {
            gestureArmed = false;
            document.removeEventListener('pointerdown', go, true);
            document.removeEventListener('keydown', go, true);
            connectAll();
        };
        document.addEventListener('pointerdown', go, true);
        document.addEventListener('keydown', go, true);
    };
    const routeThroughChain = (el, ent) => {   // 이미 잡힌 요소를 다시 필터 줄로
        if (!ent || !ent.src || !vctx) return;
        if (ent.chain && !ent.bypass) return;
        try { if (vctx.state === 'suspended') vctx.resume(); } catch (e) {}   // 쉬게 해 둔 처리기 깨우기(아래 idleCtx)
        try {
            const chain = ent.chain || createChain(vctx, 'video');
            if (!chain) return;
            ent.src.disconnect();
            ent.src.connect(chain.input);
            chain.output.connect(vctx.destination);
            ent.chain = chain; ent.bypass = false;
            orphans.delete(el);
        } catch (e) {
            try { ent.src.connect(vctx.destination); } catch (x) {}
        }
    };
    const connectMedia = (el) => {
        if (!supported || !state.video.on || !bound.has(el)) return;
        const ent = media.get(el);
        if (ent) { if (ent.src) routeThroughChain(el, ent); return; }   // failed 이면 다시 안 함
        const ctx = getCtx();
        if (!ctx) return;
        const go = () => {
            if (!state.video.on || !bound.has(el) || media.get(el)) return;
            if (ctx.state !== 'running') { armGesture(); return; }   // 돌지 않는 처리기로 돌리면 소리가 끊긴다 — 기다림
            let src = null, chain = null;
            try {
                src = ctx.createMediaElementSource(el);
            } catch (e) {
                media.set(el, { failed: true });   // 다른 곳에서 이미 잡은 요소 등 — 손대지 않음(소리는 그대로)
                return;
            }
            try {
                chain = createChain(ctx, 'video');
                src.connect(chain.input);
                chain.output.connect(ctx.destination);
                media.set(el, { src: src, chain: chain, bypass: false });
            } catch (e) {
                // 연결 실패 — 필터 없이라도 스피커로 바로 이어 소리를 살린다
                try { if (chain) chain.dispose(); } catch (x) {}
                try { src.disconnect(); src.connect(ctx.destination); } catch (x) {}
                media.set(el, { src: src, chain: null, bypass: true });
            }
        };
        if (ctx.state === 'running') { go(); return; }
        armGesture();   // 크롬은 사용자 조작 전 resume() 약속이 끝나지 않고 기다리기만 할 수 있어, 다음 조작 때 다시 시도할 길을 먼저 열어 둔다
        try {
            const p = ctx.resume();
            if (p && typeof p.then === 'function') p.then(go, armGesture); else go();
        } catch (e) { armGesture(); }
    };
    function connectAll() { bound.forEach((el) => { try { connectMedia(el); } catch (e) {} }); }
    const onMediaPlay = (e) => { try { const el = e.currentTarget; if (vctx && vctx.state !== 'running') vctx.resume(); connectMedia(el); } catch (x) {} };
    function bindMedia(el) {
        if (!supported || !el) return;
        bound.add(el);
        el.removeEventListener('play', onMediaPlay);
        el.addEventListener('play', onMediaPlay);
        const ent = media.get(el);
        if (ent && ent.src) routeThroughChain(el, ent);
        else connectMedia(el);
    }
    const sweep = () => {
        orphans.forEach((el) => {
            if (el.isConnected || !el.paused) return;
            const ent = media.get(el);
            try { if (ent && ent.src) ent.src.disconnect(); } catch (e) {}
            try { if (ent && ent.chain) ent.chain.dispose(); } catch (e) {}
            if (ent) { ent.chain = null; ent.bypass = true; ent.dead = true; }
            orphans.delete(el);
        });
        if (!orphans.size && sweepTimer) { clearInterval(sweepTimer); sweepTimer = 0; }
        idleCtx();
    };
    // 붙은 영상도 남은 소리 경로도 없으면 동영상용 처리기를 쉬게 한다(돌기만 해도 오디오 스레드가 CPU 를 조금 씀). 다시 연결할 때 깨운다.
    const idleCtx = () => { try { if (vctx && !bound.size && !orphans.size && vctx.state === 'running') vctx.suspend(); } catch (e) {} };
    function unbindMedia(el) {
        if (!el) return;
        bound.delete(el);
        try { el.removeEventListener('play', onMediaPlay); } catch (e) {}
        const ent = media.get(el);
        if (!ent || !ent.src || !vctx) return;
        // 잡힌 요소는 소리가 이 경로로만 나온다 — 필터를 빼고 스피커로 바로 이어 소리를 지킨다
        try { ent.src.disconnect(); ent.src.connect(vctx.destination); } catch (e) {}
        try { if (ent.chain) ent.chain.dispose(); } catch (e) {}
        ent.chain = null; ent.bypass = true;
        orphans.add(el);
        if (!sweepTimer) sweepTimer = setInterval(sweep, 10000);
        sweep();
    }

    // ── 설정 화면 ─────────────────────────────────────────────────────────
    const mk = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = String(text); return e; };
    const dbText = (v) => (v > 0 ? '+' : '') + (Math.abs(v) < 0.001 ? '0' : v.toFixed(1));
    function buildPanel(opts) {
        opts = opts || {};
        const profile = profOk(opts.profile);
        const root = mk('div', 'fseq' + (opts.cls ? ' ' + opts.cls : ''));
        root.setAttribute('data-profile', profile);
        // 켬/끔
        const sw = mk('button', 'fseq-sw');
        sw.type = 'button';
        sw.setAttribute('role', 'switch');
        sw.append(mk('span', 'fseq-swl', T('eq_title', '이퀄라이저')), mk('span', 'fseq-swt'), mk('span', 'fseq-swk'));
        sw.addEventListener('click', () => update(profile, { on: !state[profile].on }));
        // 프리셋
        const prow = mk('label', 'fseq-prow');
        prow.append(mk('span', 'fseq-pl', T('eq_preset', '프리셋')));
        const sel = mk('select', 'fseq-preset');
        ORDER.forEach((p) => { const o = mk('option', null, presetName(p)); o.value = p; sel.append(o); });
        sel.addEventListener('change', () => { if (ORDER.indexOf(sel.value) >= 0) update(profile, { preset: sel.value }); });
        prow.append(sel);
        // 밴드
        const bandsEl = mk('div', 'fseq-bands');
        const ranges = [], dbs = [];
        BANDS.forEach((f, i) => {
            const row = mk('div', 'fseq-band');
            const r = mk('input', 'fseq-range');
            r.type = 'range'; r.min = String(-MAXDB); r.max = String(MAXDB); r.step = String(SLIDER_STEP); r.value = '0';
            r.setAttribute('aria-label', hzLabel(f) + 'Hz');
            r.addEventListener('input', () => {
                const g = gains(profile);
                g[i] = clampG(r.value);
                update(profile, { preset: 'custom', custom: g });
            });
            const d = mk('span', 'fseq-db', '0');
            row.append(mk('span', 'fseq-hz', hzLabel(f)), r, d);
            ranges.push(r); dbs.push(d);
            bandsEl.append(row);
        });
        // 초기화
        const foot = mk('div', 'fseq-foot');
        const reset = mk('button', 'fseq-reset', T('eq_reset', '초기화'));
        reset.type = 'button';
        reset.addEventListener('click', () => update(profile, { preset: 'flat', custom: ZEROS() }));
        foot.append(mk('span', 'fseq-note', profile === 'video' ? T('eq_note_video', '동영상용 설정') : T('eq_note_music', '음악용 설정')), reset);
        root.append(sw, prow, bandsEl, foot);
        // 패널 안의 키는 플레이어 단축키로 새지 않게(스페이스로 켬/끔 단추를 누르면 재생까지 멈추던 것 방지). Esc·Tab 은 그대로.
        root.addEventListener('keydown', (e) => { if (e.key !== 'Escape' && e.key !== 'Tab') e.stopPropagation(); });
        const refresh = (s) => {
            s = s || getState(profile);
            root.classList.toggle('is-off', !s.on);
            sw.classList.toggle('is-on', s.on);
            sw.setAttribute('aria-checked', s.on ? 'true' : 'false');
            sw.querySelector('.fseq-swt').textContent = s.on ? T('eq_on', '켬') : T('eq_off', '끔');
            if (sel.value !== s.preset) sel.value = s.preset;
            s.gains.forEach((v, i) => {
                if (Number(ranges[i].value) !== v) ranges[i].value = String(v);
                dbs[i].textContent = dbText(v);
            });
        };
        refresh._fseqProfile = profile;
        refresh();
        listeners.add(refresh);
        return { el: root, destroy() { listeners.delete(refresh); } };
    }

    // ── 음악 플레이어 팝업 ──────────────────────────────────────────────────
    let pop = null;
    function closePopover() {
        if (!pop) return false;
        const p = pop; pop = null;
        try { p.panel.destroy(); } catch (e) {}
        try { document.removeEventListener('pointerdown', p.outside, true); } catch (e) {}
        try { if (p.fit) window.removeEventListener('resize', p.fit); } catch (e) {}
        try { p.el.remove(); } catch (e) {}
        try { if (p.onClose) p.onClose(); } catch (e) {}
        return true;
    }
    function openPopover(parent, opts) {
        opts = opts || {};
        closePopover();
        if (!parent) return null;
        const el = mk('div', 'fseq-pop' + (opts.cls ? ' ' + opts.cls : ''));
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-label', T('eq_title', '이퀄라이저'));
        const head = mk('div', 'fseq-pophead');
        head.append(mk('span', 'fseq-poptitle', T('eq_title', '이퀄라이저')));
        const x = mk('button', 'fseq-popclose', '✕');
        x.type = 'button';
        x.setAttribute('aria-label', T('close', '닫기'));
        x.title = T('close', '닫기');
        x.addEventListener('click', (e) => { e.stopPropagation(); closePopover(); });
        head.append(x);
        const panel = buildPanel({ cls: 'fseq-in-pop', profile: opts.profile || 'music' });
        el.append(head, panel.el);
        // 팝업 안 클릭이 플레이어(스킨 메뉴 닫기 등)로 새지 않게
        el.addEventListener('click', (e) => e.stopPropagation());
        parent.append(el);
        const outside = (e) => { if (pop && !el.contains(e.target) && !(opts.keepOpenFor && opts.keepOpenFor.contains(e.target))) closePopover(); };
        document.addEventListener('pointerdown', outside, true);
        // ★ (2026-10-11) 보이는 영역에 맞춘 높이 — CSS 최대 높이(화면 70%)만으로는, 넘치는 부분을 잘라내는 바깥 틀(탐색기 미리보기 창) 안에서
        //   아래가 잘려 '초기화'를 누를 수 없었다(낮은 창 650px 실측). 잘라내는 조상(overflow 가 visible 이 아닌 것)과 화면 중 가장 위쪽 아래 끝까지만
        //   높이를 잡고 그 안에서 스크롤. 창 크기가 바뀌면 다시 잰다(닫을 때 해제).
        const fit = () => {
            if (!pop || pop.el !== el) return;
            el.style.maxHeight = '';
            const r = el.getBoundingClientRect();
            let bottom = window.innerHeight;
            for (let a = el.parentElement; a && a !== document.body && a !== document.documentElement; a = a.parentElement) {
                const cs = getComputedStyle(a);
                if (cs.overflowY !== 'visible' || cs.overflowX !== 'visible') bottom = Math.min(bottom, a.getBoundingClientRect().bottom);
            }
            const avail = Math.floor(bottom - r.top - 8);
            if (avail < el.offsetHeight) el.style.maxHeight = Math.max(160, avail) + 'px';
        };
        pop = { el: el, panel: panel, outside: outside, onClose: opts.onClose, fit: fit };
        window.addEventListener('resize', fit);
        fit();
        try { sw0Focus(el, opts.focus); } catch (e) {}
        return el;
    }
    const sw0Focus = (el, want) => { if (!want) return; const f = el.querySelector('.fseq-sw'); if (f) f.focus(); };
    const escape = () => closePopover();
    // 공유 페이지 등 앱이 Esc 를 먼저 묻지 않는 곳 — 창(window) 단계에서 팝업만 닫는다(탐색기는 앱 Esc 처리가 먼저 FSEQ.escape() 를 부름)
    window.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || !pop || e.defaultPrevented) return;
        if (closePopover()) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);

    // ── 계정 저장(탐색기) ──────────────────────────────────────────────────
    function setRemoteSaver(fn) { remoteSaver = (typeof fn === 'function') ? fn : null; }
    function syncRemote(server, uid) {
        const n = normalize(server);
        const me = (uid != null && String(uid) !== '' && /^[\w-]{1,64}$/.test(String(uid))) ? String(uid) : '';
        // ★ (2026-10-11) 이 브라우저 값을 다른 계정이 저장했으면(같은 PC 를 여러 계정이 씀) 그 값은 올리지 않고 내 계정 값으로 — 없으면 기본(끔·플랫).
        //   소유 표시가 없는 값(이 수정 전에 저장됐거나 공유 페이지에서만 바꾼 것)은 종전처럼 '나중에 바꾼 쪽'.
        if (me && state.u && state.u !== me) {
            state = n ? Object.assign(n, { u: me }) : freshState(me);
            persist(false); emit(); connectAll();
            return 'server';
        }
        const ownerChanged = !!(me && state.u !== me);
        if (me) state.u = me;
        if (n && n.t > state.t) { state = Object.assign(n, { u: me || state.u }); persist(false); emit(); connectAll(); return 'server'; }
        if (ownerChanged) persist(false);   // 소유 표시만 남김
        if (state.t > 0 && (!n || state.t > n.t)) { flushRemote(); return 'local'; }
        return 'same';
    }

    // 상태 알림 — profile 을 주면 그 묶음 상태로 부른다(기본 음악)
    const subscribe = (fn, profile) => { if (typeof fn !== 'function') return () => {}; fn._fseqProfile = profOk(profile); listeners.add(fn); return () => listeners.delete(fn); };
    const summary = (profile) => { const s = state[profOk(profile)]; return s.on ? presetName(s.preset) : T('eq_off', '끔'); };

    window.FSEQ = {
        supported, BANDS: BANDS.slice(), PRESETS: ORDER.slice(), PROFILES: PROFILES.slice(),
        getState, subscribe, summary, presetName,
        setOn: (b, profile) => update(profile, { on: !!b }),
        setPreset: (p, profile) => { if (ORDER.indexOf(p) >= 0) update(profile, { preset: p }); },
        setBand: (i, db, profile) => { i = Math.floor(Number(i)); if (i >= 0 && i < N) { const g = gains(profile); g[i] = clampG(db); update(profile, { preset: 'custom', custom: g }); } },
        reset: (profile) => update(profile, { preset: 'flat', custom: ZEROS() }),
        createChain, bindMedia, unbindMedia,
        buildPanel, openPopover, closePopover, escape,
        isOpen: () => !!pop,
        setRemoteSaver, syncRemote, normalize,
        _debug: () => ({ bound: bound.size, orphans: orphans.size, chains: chains.size, ctx: vctx ? vctx.state : null }),
        version: '2'
    };
})();
