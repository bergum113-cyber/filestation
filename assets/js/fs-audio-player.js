/**
 * ============================================================================
 * 🌐 [공유 스트리밍 MP3 플레이어] FSAudioPlayer
 * ============================================================================
 * 
 * ⚠️ 주의: 이 파일은 *공유 페이지(share.php) 전용*입니다.
 * 
 * - 사용처: share.php (외부 공유 링크에서 stream 모드로 재생)
 * - 짝 파일: assets/js/app.js 안의 FSAudioPlayer (메인 페이지용 일반 플레이어)
 * - 두 곳 모두 같은 클래스명(FSAudioPlayer)이지만 ★독립★ 입니다.
 *   하나 수정하면 다른 쪽도 수정해야 일관성 유지됩니다.
 * 
 * ⚠️ 이 파일을 수정하면 *공유 스트리밍*만 영향받습니다.
 *    메인 미리보기 플레이어를 수정하려면 → assets/js/app.js 라인 352 부근
 * ============================================================================
 * 
 * 원본 위치: assets/js/app.js (라인 352~2786)
 * 추출 사유: 공유 스트리밍(share.php)에서 동일 플레이어 사용
 * 
 * 의존성:
 *   - 외부 API: api.php?action=session_ping (실패 시 무시 — 비로그인 환경 OK)
 *   - 브라우저: Audio API, MediaSession, WakeLock, localStorage
 *   - i18n: document.documentElement.lang (한/영 분기)
 * 
 * 사용법:
 *   const player = new FSAudioPlayer({
 *     container: document.getElementById('audio-player'),
 *     playlist: [{ name, fullName, ext, path, url, coverApiUrl }],
 *     startIndex: 0,
 *     volume: 0.8,
 *     loop: 'all',
 *     cover: '...',
 *   });
 * 
 * 정리:
 *   player.destroy();
 */

// === FSCoverCacheDB — MP3 앨범 커버 IndexedDB 영구 캐시 ===
// 목적: 모달 닫혀도 blob 데이터 유지 → 재열기 시 즉시 표시
// 사용자 환경 (Apache Cache-Control 강제 덮어쓰기 등)에 무관하게 작동
//
// ★ 펜닐님 결정 (2026-04-30): 공유 페이지에도 IDB 추가 (메인과 동일 정의 + 같은 DB 공유)
//   같은 DB_NAME 사용 → 메인에서 캐시한 cover를 공유에서도 hit 가능 (PC만)
//   iOS는 메인과 동일하게 _disabled = true (IDB 손상 케이스 회피)
//
// API:
//   FSCoverCacheDB.get(url) → Promise<Blob|null>
//   FSCoverCacheDB.set(url, blob) → Promise<void>
//   FSCoverCacheDB.cleanup() → Promise<number>
//
// 정책:
//   - 만료: 30일 (lastAccess 기준)
//   - 최대 항목: 500개 (LRU)
//   - 키: 원본 API URL
//   - 값: { blob, mime, addedAt, lastAccess }
//   - DB 미지원 환경에선 모든 메서드 null/no-op (호출자는 정상 fetch fallback)
const FSCoverCacheDB = (function() {
    const DB_NAME = 'fs_cover_cache';
    const DB_VERSION = 1;
    const STORE_NAME = 'covers';
    const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;  // 30일
    const MAX_ITEMS = 500;
    
    let _db = null;
    let _dbPromise = null;
    let _disabled = false;
    
    // ★ iOS 환경에서 IDB 영구 비활성 (펜닐님 결정 2026-04-30 — 메인과 동일 패턴)
    //   본질: iOS Safari/Chrome에서 IDB blob 직렬화/역직렬화 후 손상 케이스 발생
    //   결과: iOS는 메모리 캐시만 사용, PC는 30일 IDB 영구 캐시
    const _isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent || '') 
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (_isIOS) {
        _disabled = true;
    }
    
    function _openDB() {
        if (_disabled) return Promise.resolve(null);
        if (_db) return Promise.resolve(_db);
        if (_dbPromise) return _dbPromise;
        if (!window.indexedDB) {
            _disabled = true;
            return Promise.resolve(null);
        }
        _dbPromise = new Promise((resolve) => {
            try {
                const req = indexedDB.open(DB_NAME, DB_VERSION);
                req.onupgradeneeded = (e) => {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains(STORE_NAME)) {
                        const store = db.createObjectStore(STORE_NAME, { keyPath: 'url' });
                        store.createIndex('lastAccess', 'lastAccess', { unique: false });
                    }
                };
                req.onsuccess = (e) => {
                    _db = e.target.result;
                    _db.onversionchange = () => { try { _db.close(); } catch(_){} _db = null; };
                    resolve(_db);
                };
                req.onerror = () => {
                    _disabled = true;
                    resolve(null);
                };
                req.onblocked = () => {
                    _disabled = true;
                    resolve(null);
                };
            } catch (e) {
                _disabled = true;
                resolve(null);
            }
        });
        return _dbPromise;
    }
    
    async function get(url) {
        const db = await _openDB();
        if (!db) return null;
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                const req = store.get(url);
                req.onsuccess = () => {
                    const entry = req.result;
                    if (!entry) { resolve(null); return; }
                    const now = Date.now();
                    if (now - entry.addedAt > MAX_AGE_MS) {
                        try { store.delete(url); } catch(_){}
                        resolve(null);
                        return;
                    }
                    try {
                        entry.lastAccess = now;
                        store.put(entry);
                    } catch(_){}
                    resolve(entry.blob || null);
                };
                req.onerror = () => resolve(null);
            } catch (e) {
                resolve(null);
            }
        });
    }
    
    async function set(url, blob) {
        const db = await _openDB();
        if (!db) return;
        if (!blob || blob.size > 5 * 1024 * 1024) return;
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                const now = Date.now();
                const entry = {
                    url: url,
                    blob: blob,
                    mime: blob.type || 'image/jpeg',
                    addedAt: now,
                    lastAccess: now
                };
                const req = store.put(entry);
                req.onsuccess = () => resolve();
                req.onerror = () => resolve();
            } catch (e) {
                resolve();
            }
        });
    }
    
    async function cleanup() {
        const db = await _openDB();
        if (!db) return 0;
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                const cutoff = Date.now() - MAX_AGE_MS;
                let deleted = 0;
                const expReq = store.index('lastAccess').openCursor(IDBKeyRange.upperBound(cutoff));
                expReq.onsuccess = (e) => {
                    const cursor = e.target.result;
                    if (cursor) {
                        try { cursor.delete(); deleted++; } catch(_){}
                        cursor.continue();
                    } else {
                        const countReq = store.count();
                        countReq.onsuccess = () => {
                            const cnt = countReq.result;
                            if (cnt <= MAX_ITEMS) { resolve(deleted); return; }
                            const toDelete = cnt - MAX_ITEMS;
                            let removed = 0;
                            const lruReq = store.index('lastAccess').openCursor();
                            lruReq.onsuccess = (e2) => {
                                const c = e2.target.result;
                                if (c && removed < toDelete) {
                                    try { c.delete(); removed++; deleted++; } catch(_){}
                                    c.continue();
                                } else {
                                    resolve(deleted);
                                }
                            };
                            lruReq.onerror = () => resolve(deleted);
                        };
                        countReq.onerror = () => resolve(deleted);
                    }
                };
                expReq.onerror = () => resolve(deleted);
            } catch (e) {
                resolve(0);
            }
        });
    }
    
    let _cleanupDone = false;
    function maybeCleanup() {
        if (_cleanupDone) return;
        _cleanupDone = true;
        setTimeout(() => {
            cleanup().then(n => {
                if (n > 0 && window.console) console.log('[FSCoverCacheDB] cleanup removed', n);
            });
        }, 5000);
    }
    
    async function deleteEntry(url) {
        const db = await _openDB();
        if (!db) return;
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                const req = store.delete(url);
                req.onsuccess = () => resolve();
                req.onerror = () => resolve();
            } catch (e) {
                resolve();
            }
        });
    }
    
    return { get, set, cleanup, maybeCleanup, delete: deleteEntry };
})();

// === FSAudioPlayer — 순수 JS/CSS 커스텀 오디오 플레이어 ===
class FSAudioPlayer {
    constructor(opts) {
        this.container = opts.container;
        this.playlist = opts.playlist || [];
        this.currentIndex = opts.startIndex || 0;
        // ★ 반복 모드: localStorage 우선, 없으면 opts.loop, 그것도 없으면 'all'
        //   유효값 검사 ('all'/'one'/'none' 외엔 무시) — corrupt 데이터 안전
        let _initLoop = opts.loop || 'all';
        try {
            const savedLoop = localStorage.getItem('fap-loop');
            if (savedLoop && ['all', 'one', 'none'].includes(savedLoop)) {
                _initLoop = savedLoop;
            }
        } catch (e) { /* localStorage 비활성 — 기본값 사용 */ }
        this.loop = _initLoop;
        // ★ 셔플: localStorage 우선
        let _initShuffle = false;
        try {
            const savedShuffle = localStorage.getItem('fap-shuffle');
            if (savedShuffle === '1') _initShuffle = true;
        } catch (e) { /* localStorage 비활성 — 기본값 사용 */ }
        this.shuffle = _initShuffle;
        this.cover = opts.cover || '';
        this.onTrackChange = opts.onTrackChange || null;
        this._destroyed = false;

        // Audio element
        this.audio = new Audio();
        this.audio.preload = 'auto';
        // 주의: crossOrigin 설정 시 CORS 헤더 없는 외부 스토리지에서 재생 실패 가능
        // 비주얼라이저는 동일 출처 MP3에서만 정상 동작 (외부는 막대가 안 움직여도 재생은 OK)
        this._draggingSeek = false;
        this._draggingVol = false;
        this._shuffleOrder = [];

        // iOS 감지
        this._isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
                      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        this._gainNode = null;
        this._audioCtx = null;
        // ★ 볼륨: localStorage 저장값 우선, 없으면 opts.volume, 그것도 없으면 0.8
        //   플레이어 껐다 켜도 마지막 볼륨 유지
        let _initVolume = opts.volume ?? 0.8;
        try {
            const saved = localStorage.getItem('fap-volume');
            if (saved !== null) {
                const v = parseFloat(saved);
                if (!isNaN(v) && v >= 0 && v <= 1) _initVolume = v;
            }
        } catch (e) { /* localStorage 비활성 — 기본값 사용 */ }
        this._volume = _initVolume;
        this._prevVolume = this._volume > 0 ? this._volume : 0.8;

        // iOS: AudioContext+GainNode를 사용하면 백그라운드 재생이 불가하므로
        // iOS에서는 audio.volume을 사용 (iOS 정책상 volume 변경이 무시되지만 소리 자체는 출력됨)
        // 볼륨 조절보다 백그라운드 재생 + MediaSession이 더 중요
        this.audio.volume = this._volume;

        // 스토리지 타입 저장 (동시 tmpAudio 다운로드 수 조절용)
        this._storageType = opts.storageType || 'local';
        this._isRemote = !['home', 'shared', 'local'].includes(this._storageType);
        
        // 커버 로드 토큰 (빠른 트랙 전환 시 이전 로드 결과 무시용)
        this._coverLoadToken = 0;
        
        // 마퀴 토큰 (빠른 트랙 전환 시 이전 setTimeout 무시용)
        this._marqueeToken = 0;
        
        // ★ 커버 이미지 메모리 캐시 (Map: 원본 URL → blob URL)
        //   - 가상 스크롤로 li 재생성 시 같은 URL의 blob 재사용 → 서버 재요청 방지
        //   - 모든 환경(Apache/Nginx/배포) 일관되게 동작
        //   - destroy() 시 모든 blob URL 해제 (메모리 누수 방지)
        //   - fetch 실패 시 원본 URL 그대로 반환 (fallback 안전)
        this._coverBlobCache = new Map();
        // 진행 중인 fetch promise 캐시 (동일 URL 동시 요청 시 한 번만 fetch)
        this._coverBlobPending = new Map();
        // ★ iOS 동시 fetch 제한 큐 (펜닐님 결정 — iOS 메모리 압박/abort 회피)
        //   PC는 큐 미사용 (브라우저 동시 6개 fetch 한계 그대로 활용)
        //   ★ 펜닐님 결정 (2026-04-30): iOS 큐 제한도 해제 (Infinity)
        //     이유: 재생목록 스크롤 시 큐 대기 트랙 썸네일 늦게 보임 → 무제한이 UX 더 좋음
        //     iOS 메모리 압박 우려는 있지만, IDB 비활성화로 본질 해결됨 → 큐 안전망 불필요
        this._coverFetchQueue = [];           // 대기 중인 fetch 작업: [{ run, resolve, reject }]
        this._coverFetchActive = 0;            // 현재 실행 중인 fetch 수
        this._COVER_FETCH_LIMIT = Infinity;    // 모든 환경 무제한 (브라우저 자체 동시 fetch 한계 활용)

        this._render();
        this._bind();
        this._initSkinSelector();  // 스킨 선택기 초기화 (기본 스킨 외 추가 스킨 적용)
        if (this.playlist.length) this._loadTrack(this.currentIndex, false);
        
        this._vsDurations = this._vsDurations || [];
        
        // 플레이리스트 각 트랙 재생 시간 로드
        // 전략: 서버 응답을 우선 기다림 (최대 30초), 응답 오면 부족한 것만 tmpAudio로 측정
        // 서버 응답이 제때 오면 대량 다운로드를 피할 수 있음
        this._durationLoadScheduled = true;
        const checkAndLoad = () => {
            if (this._destroyed) return;
            if (this._serverResponseReceived) {
                // 서버 응답 도착 → 즉시 시작 (부족한 것만 측정)
                this._loadPlaylistDurations();
            } else {
                // 아직 안 왔으면 500ms마다 재확인
                setTimeout(checkAndLoad, 500);
            }
        };
        // 초기 2초 대기 (서버가 대부분의 경우 2초 내 응답) 후 폴링 시작
        setTimeout(checkAndLoad, 2000);
        // 최종 타임아웃: 30초 후에는 무조건 시작 (서버가 응답 안 해도)
        setTimeout(() => {
            if (!this._destroyed && !this._durationLoadStarted) {
                this._loadPlaylistDurations();
            }
        }, 30000);
    }
    
    // 서버에서 받은 duration 일괄 적용 (비동기로 호출됨)
    _applyServerDurations(serverDurations, serverDetails) {
        if (this._destroyed || !serverDurations) return;
        this._vsDurations = this._vsDurations || [];
        this._serverAudioDetails = serverDetails || this._serverAudioDetails || {};
        this._serverResponseReceived = true; // 서버 응답 도착 플래그
        let changed = false;
        
        // 서버 캐시 hit 파일들 - tmpAudio로 측정 중이면 취소 (중복 다운로드 방지)
        // _durLoaderMap: track.fullName → tmpAudio 인스턴스
        const cachedFullNames = new Set();
        
        for (let i = 0; i < this.playlist.length; i++) {
            const track = this.playlist[i];
            // track.fullName으로 직접 매칭 (fallback: 파일명 매칭)
            let dur = null;
            let matchedKey = null;
            if (track.fullName && serverDurations[track.fullName]) {
                dur = serverDurations[track.fullName];
                matchedKey = track.fullName;
            } else {
                for (const key in serverDurations) {
                    const keyBase = key.replace(/\.[^.]+$/, '');
                    if (keyBase === track.name || key === track.name) {
                        dur = serverDurations[key];
                        matchedKey = key;
                        break;
                    }
                }
            }
            if (dur && dur > 0) {
                // 서버가 duration을 아는 파일 → _vsDurations 설정 (기존 값 덮어쓰기 OK)
                if (!this._vsDurations[i] || this._vsDurations[i] !== this._fmt(dur)) {
                    this._vsDurations[i] = this._fmt(dur);
                    changed = true;
                    const li = this.$.plList && this.$.plList.querySelector(`.fap-pl-item[data-index="${i}"]`);
                    if (li) {
                        const durEl = li.querySelector('.fap-pl-dur');
                        if (durEl) durEl.textContent = this._vsDurations[i];
                    }
                }
                // 이 파일에 대한 in-flight tmpAudio 있으면 취소 (중복 다운로드 방지)
                if (track.fullName) cachedFullNames.add(track.fullName);
            }
        }
        
        // tmpAudio 측정 중인 것들 중 캐시 hit인 것 취소
        if (this._durLoaders && this._durLoaders.length && cachedFullNames.size) {
            for (const tmpAudio of this._durLoaders) {
                if (tmpAudio._trackFullName && cachedFullNames.has(tmpAudio._trackFullName)) {
                    try {
                        tmpAudio.onloadedmetadata = null;
                        tmpAudio.onerror = null;
                        tmpAudio.src = '';
                        tmpAudio.load(); // 현재 다운로드 중단
                    } catch(e) {}
                }
            }
        }
        
        // _pendingSave에서도 서버 캐시 있는 항목 제거 (중복 저장 방지)
        if (this._pendingSave && this._pendingSave.length) {
            this._pendingSave = this._pendingSave.filter(p => !cachedFullNames.has(p.fullName));
        }
        
        // ★ 펜닐님 진단 (2026-04-30): duration 갱신은 라인 398~402에서 li.querySelector로 직접 처리됨
        //   여기서 _vsRender 강제 호출하면 모든 li 재생성 → 진행 중인 비동기 cover fetch가
        //   IMG_DISCONNECTED 떼로 발생 → 일부 트랙 썸네일 누락 (메인 app.js와 동일 본질)
        //   따라서 _vsRender 강제 호출 제거. duration 만 갱신되면 충분.
        //   메인+공유 일관성 — 메인 app.js 라인 775~778과 동일 패턴
        // if (changed && this._vsRender) {
        //     this._vsRenderedRange = { start: -1, end: -1 };
        //     this._vsRender();
        // }
        this._updateTrackMeta();
    }

    // ── Render ──
    _render() {
        const isKo = (document.documentElement.lang || navigator.language || '').startsWith('ko');
        this.container.innerHTML = `
        <div class="fap">
            <div class="fap-now">
                <div class="fap-now-cover">${this.cover ? `<img src="${this._esc(this.cover)}" alt="" draggable="false">` : '<span class="fap-now-cover-icon">🎵</span>'}</div>
                <div class="fap-now-info">
                    <div class="fap-now-title">${isKo ? '선택된 곡 없음' : 'No track selected'}</div>
                    <div class="fap-now-meta"></div>
                </div>
            </div>
            <canvas class="fap-visualizer" width="600" height="40"></canvas>
            <div class="fap-lyrics-wrap" style="display:none;">
                <div class="fap-lyrics-scroll">
                    <div class="fap-lyrics-content"></div>
                </div>
            </div>
            <div class="fap-seek">
                <span class="fap-time fap-time-cur">0:00</span>
                <div class="fap-seek-bar" title="${isKo ? '←/→로 5초 이동' : '←/→ to seek 5s'}">
                    <div class="fap-seek-loaded"></div>
                    <div class="fap-seek-played"></div>
                    <div class="fap-seek-thumb"></div>
                </div>
                <span class="fap-time fap-time-dur">0:00</span>
            </div>
            <div class="fap-controls">
                <button class="fap-btn fap-btn-shuffle" title="${isKo ? '셔플 (S)' : 'Shuffle (S)'}">
                    <svg viewBox="0 0 24 24" width="18" height="18"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z" fill="currentColor"/></svg>
                </button>
                <button class="fap-btn fap-btn-prev" title="${isKo ? '이전 곡 (Shift+←)' : 'Previous (Shift+←)'}">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z" fill="currentColor"/></svg>
                </button>
                <button class="fap-btn fap-btn-play" title="${isKo ? '재생/일시정지 (Space)' : 'Play/Pause (Space)'}">
                    <svg class="fap-icon-play" viewBox="0 0 24 24" width="28" height="28"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>
                    <svg class="fap-icon-pause" viewBox="0 0 24 24" width="28" height="28" style="display:none"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z" fill="currentColor"/></svg>
                </button>
                <button class="fap-btn fap-btn-next" title="${isKo ? '다음 곡 (Shift+→)' : 'Next (Shift+→)'}">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z" fill="currentColor"/></svg>
                </button>
                <button class="fap-btn fap-btn-loop" title="${isKo ? '반복 모드 (L)' : 'Repeat (L)'}">
                    <svg viewBox="0 0 24 24" width="18" height="18"><path d="M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4z" fill="currentColor"/></svg>
                </button>
                <button class="fap-btn fap-btn-lyrics" title="${isKo ? '가사 보기 (Ctrl+L)' : 'Show lyrics (Ctrl+L)'}" style="display:none;">
                    <svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 6h16v2H4V6zm0 4h12v2H4v-2zm0 4h16v2H4v-2zm0 4h12v2H4v-2z" fill="currentColor"/></svg>
                </button>
            </div>
            <div class="fap-volume">
                <button class="fap-btn fap-btn-vol" title="${isKo ? '음소거 (M) · ↑↓로 볼륨 조절' : 'Mute (M) · ↑↓ to adjust'}">
                    <svg class="fap-icon-vol-on" viewBox="0 0 24 24" width="18" height="18"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z" fill="currentColor"/></svg>
                    <svg class="fap-icon-vol-off" viewBox="0 0 24 24" width="18" height="18" style="display:none"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z" fill="currentColor"/></svg>
                </button>
                <div class="fap-vol-bar" title="${isKo ? '↑/↓로 5%씩 조절' : '↑/↓ to adjust 5%'}">
                    <div class="fap-vol-level"></div>
                    <div class="fap-vol-thumb"></div>
                </div>
            </div>
            <div class="fap-playlist">
                <div class="fap-playlist-header">
                    <span class="fap-playlist-title">${isKo ? '재생 목록' : 'Playlist'}</span>
                    <span class="fap-playlist-count">${this.playlist.length}</span>
                </div>
                <ul class="fap-playlist-list"><div class="fap-pl-virtual-spacer"></div></ul>
            </div>
            <!-- ★ 가사 모달 (v5.8.1c — 옵션 A) -->
            <!--   동기화 안 된 가사(USLT/TXT)는 모달에서만 정적 표시 — Apple Music 방식 -->
            <!--   동기화된 가사(LRC)는 인라인 표시 + 모달도 가능 (둘 다 지원) -->
            <div class="fap-lyrics-modal" style="display:none;">
                <div class="fap-lyrics-modal-overlay"></div>
                <div class="fap-lyrics-modal-content">
                    <div class="fap-lyrics-modal-header">
                        <div class="fap-lyrics-modal-title-wrap">
                            <div class="fap-lyrics-modal-title">${isKo ? '가사' : 'Lyrics'}</div>
                            <div class="fap-lyrics-modal-track"></div>
                        </div>
                        <button class="fap-lyrics-modal-close" title="${isKo ? '닫기 (Esc)' : 'Close (Esc)'}" aria-label="Close">
                            <svg viewBox="0 0 24 24" width="22" height="22"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/></svg>
                        </button>
                    </div>
                    <div class="fap-lyrics-modal-body">
                        <div class="fap-lyrics-modal-text"></div>
                    </div>
                </div>
            </div>
        </div>`;
        // Cache DOM
        this.$ = {
            root: this.container.querySelector('.fap'),
            cover: this.container.querySelector('.fap-now-cover'),
            title: this.container.querySelector('.fap-now-title'),
            meta: this.container.querySelector('.fap-now-meta'),
            curTime: this.container.querySelector('.fap-time-cur'),
            durTime: this.container.querySelector('.fap-time-dur'),
            seekBar: this.container.querySelector('.fap-seek-bar'),
            seekPlayed: this.container.querySelector('.fap-seek-played'),
            seekLoaded: this.container.querySelector('.fap-seek-loaded'),
            seekThumb: this.container.querySelector('.fap-seek-thumb'),
            btnPlay: this.container.querySelector('.fap-btn-play'),
            iconPlay: this.container.querySelector('.fap-icon-play'),
            iconPause: this.container.querySelector('.fap-icon-pause'),
            btnPrev: this.container.querySelector('.fap-btn-prev'),
            btnNext: this.container.querySelector('.fap-btn-next'),
            btnLoop: this.container.querySelector('.fap-btn-loop'),
            btnShuffle: this.container.querySelector('.fap-btn-shuffle'),
            btnVol: this.container.querySelector('.fap-btn-vol'),
            iconVolOn: this.container.querySelector('.fap-icon-vol-on'),
            iconVolOff: this.container.querySelector('.fap-icon-vol-off'),
            volBar: this.container.querySelector('.fap-vol-bar'),
            volLevel: this.container.querySelector('.fap-vol-level'),
            volThumb: this.container.querySelector('.fap-vol-thumb'),
            plList: this.container.querySelector('.fap-playlist-list'),
            visualizer: this.container.querySelector('.fap-visualizer'),
            lyricsWrap: this.container.querySelector('.fap-lyrics-wrap'),
            lyricsScroll: this.container.querySelector('.fap-lyrics-scroll'),
            lyricsContent: this.container.querySelector('.fap-lyrics-content'),
            // ★ 가사 모달 (v5.8.1c — 옵션 A)
            btnLyrics: this.container.querySelector('.fap-btn-lyrics'),
            lyricsModal: this.container.querySelector('.fap-lyrics-modal'),
            lyricsModalOverlay: this.container.querySelector('.fap-lyrics-modal-overlay'),
            lyricsModalClose: this.container.querySelector('.fap-lyrics-modal-close'),
            lyricsModalTrack: this.container.querySelector('.fap-lyrics-modal-track'),
            lyricsModalText: this.container.querySelector('.fap-lyrics-modal-text'),
        };
        // ★ iOS: 시스템 정책상 audio.volume 변경 불가 (Apple 공식 정책)
        //   YouTube iOS 웹 등과 동일하게 볼륨 영역 전체 숨김
        //   사용자는 기기 물리 볼륨 버튼으로 조절
        if (this._isIOS && this.$.root) {
            this.$.root.classList.add('fap-ios');
        }
        this._updateLoopUI();
        this._updateVolUI();
        // ★ 저장된 셔플 상태 UI 반영 (localStorage에서 셔플=true 로드된 경우)
        if (this.shuffle) {
            this.$.btnShuffle.classList.add('active');
            this._buildShuffleOrder();
        }
        // ★ IndexedDB 만료 항목 정리 (페이지당 1회, 5초 후 백그라운드 실행)
        try { FSCoverCacheDB.maybeCleanup(); } catch (e) {}
        this._vsInit();
    }

    // ── Skin System ──
    _initSkinSelector() {
        // 사용 가능한 스킨 목록
        this._skins = [
            { id: 'default',    name: 'Default' },
            { id: 'soundcloud', name: 'SoundCloud' },
            { id: 'cassette',   name: 'Cassette' },
            { id: 'terminal',   name: 'Terminal' },
            { id: 'pixel',      name: 'Pixel Art' },
            { id: 'ap-fixed',   name: 'APlayer Fixed' },
        ];
        
        // 비주얼라이저 색상 팔레트 (audioMotion-analyzer 레퍼런스)
        // 각 색상은 하단→상단 그라디언트 정의
        this._visColors = [
            { id: 'default',    name: 'Default',    colors: ['#667eea', '#ec4899'] },  // 보라→분홍 (기본)
            { id: 'classic',    name: 'Classic',    colors: ['#22c55e', '#eab308', '#ef4444'] },  // 초록→노랑→빨강 (전통 하이파이)
            { id: 'orangered',  name: 'OrangeRed',  colors: ['#f59e0b', '#dc2626'] },  // 주황→빨강
            { id: 'prism',      name: 'Prism',      colors: ['#ef4444', '#f59e0b', '#eab308', '#22c55e', '#3b82f6', '#8b5cf6'] },  // 무지개 6색
            { id: 'rainbow',    name: 'Rainbow',    colors: ['#ff006e', '#fb5607', '#ffbe0b', '#8ac926', '#00b4d8', '#9d4edd'] },  // 네온 무지개
            { id: 'steelblue',  name: 'SteelBlue',  colors: ['#0ea5e9', '#1e40af'] },  // 청록→남색
            { id: 'matrix',     name: 'Matrix',     colors: ['#064e3b', '#10b981', '#a7f3d0'] },  // 어두운→밝은 초록 (터미널 느낌)
        ];
        
        // 저장된 스킨 복원 (기본: default)
        let savedSkin = 'default';
        try {
            savedSkin = localStorage.getItem('fap_skin') || 'default';
        } catch(e) {}
        // 유효성 체크
        if (!this._skins.find(s => s.id === savedSkin)) savedSkin = 'default';
        this._currentSkin = savedSkin;
        
        // 저장된 비주얼라이저 색상 복원 (기본: default)
        let savedVisColor = 'default';
        try {
            savedVisColor = localStorage.getItem('fap_vis_color') || 'default';
        } catch(e) {}
        if (!this._visColors.find(c => c.id === savedVisColor)) savedVisColor = 'default';
        this._currentVisColor = savedVisColor;
        
        // 스킨 선택 UI 추가 (플레이어 상단)
        this._renderSkinSelector();
        
        // 저장된 스킨 적용
        if (savedSkin !== 'default') {
            this._applySkin(savedSkin);
        }
    }
    
    _renderSkinSelector() {
        // 플레이어 루트가 없으면 (render 실패 등) 스킨 선택기 생성 안 함
        if (!this.$ || !this.$.root) return;
        const selector = document.createElement('div');
        selector.className = 'fap-skin-selector';
        const isKo = (document.documentElement.lang || navigator.language || '').startsWith('ko');
        selector.innerHTML = `
            <button class="fap-skin-btn" title="${isKo ? '스킨 선택' : 'Choose Skin'}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
                <span class="fap-skin-label">${this._skins.find(s => s.id === this._currentSkin)?.name || 'Default'}</span>
            </button>
            <div class="fap-skin-menu" style="display:none;">
                <div class="fap-skin-section-title">${isKo ? '스킨' : 'Skin'}</div>
                ${this._skins.map(s => `
                    <div class="fap-skin-item${s.id === this._currentSkin ? ' active' : ''}" data-skin="${s.id}">
                        ${s.name}
                    </div>
                `).join('')}
                <div class="fap-skin-section-title">${isKo ? '비주얼라이저 색상' : 'Visualizer Color'}</div>
                ${this._visColors.map(c => `
                    <div class="fap-vis-color-item${c.id === this._currentVisColor ? ' active' : ''}" data-vis-color="${c.id}">
                        <span class="fap-vis-color-swatch" style="background: linear-gradient(to right, ${c.colors.join(', ')});"></span>
                        <span class="fap-vis-color-name">${c.name}</span>
                    </div>
                `).join('')}
            </div>
        `;
        // 플레이어 맨 앞에 삽입
        this.$.root.insertBefore(selector, this.$.root.firstChild);
        
        // 이벤트
        const btn = selector.querySelector('.fap-skin-btn');
        const menu = selector.querySelector('.fap-skin-menu');
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        });
        // 외부 클릭 시 메뉴 닫기 (destroy 시 해제를 위해 참조 저장)
        this._skinMenuCloseHandler = () => {
            if (menu) menu.style.display = 'none';
        };
        document.addEventListener('click', this._skinMenuCloseHandler);
        // 메뉴 내부 클릭 (스킨 + 비주얼라이저 색상)
        menu.addEventListener('click', (e) => {
            // 스킨 아이템
            const skinItem = e.target.closest('.fap-skin-item');
            if (skinItem) {
                e.stopPropagation();
                const skinId = skinItem.dataset.skin;
                this._applySkin(skinId);
                // 레이블 업데이트
                const label = selector.querySelector('.fap-skin-label');
                if (label) label.textContent = this._skins.find(s => s.id === skinId)?.name || 'Default';
                // active 표시 업데이트
                menu.querySelectorAll('.fap-skin-item').forEach(el => {
                    el.classList.toggle('active', el.dataset.skin === skinId);
                });
                menu.style.display = 'none';
                return;
            }
            // 비주얼라이저 색상 아이템
            const visColorItem = e.target.closest('.fap-vis-color-item');
            if (visColorItem) {
                e.stopPropagation();
                const colorId = visColorItem.dataset.visColor;
                this._applyVisColor(colorId);
                // active 표시 업데이트
                menu.querySelectorAll('.fap-vis-color-item').forEach(el => {
                    el.classList.toggle('active', el.dataset.visColor === colorId);
                });
                menu.style.display = 'none';
                return;
            }
        });
        this._skinSelectorEl = selector;
    }
    
    // 비주얼라이저 색상 변경 (localStorage 저장 + 즉시 반영)
    _applyVisColor(colorId) {
        if (!this._visColors.find(c => c.id === colorId)) colorId = 'default';
        this._currentVisColor = colorId;
        try { localStorage.setItem('fap_vis_color', colorId); } catch(e) {}
        // 캐시된 그라디언트/피크 색상 폐기 (다음 draw에서 재생성)
        this._visCachedGradient = null;
        this._visCachedGradientKey = '';
        this._visPeakColor = null;
    }
    
    _applySkin(skinId) {
        if (!this.$) return;
        const root = this.$.root;
        if (!root) return;
        
        // 이전 스킨 장식 요소 제거
        this._removeSkinDecorations();
        
        // 이전 skin 클래스 제거
        this._skins.forEach(s => root.classList.remove('fap-skin-' + s.id));
        
        // 새 스킨 클래스 추가
        if (skinId !== 'default') {
            root.classList.add('fap-skin-' + skinId);
        }
        
        // 스킨별 추가 HTML 요소 삽입
        this._addSkinDecorations(skinId);
        
        // localStorage 저장
        this._currentSkin = skinId;
        try { localStorage.setItem('fap_skin', skinId); } catch(e) {}
        
        // 스킨 전환으로 canvas 크기가 변경될 수 있으므로 비주얼라이저 resize
        // (ap-fixed: display:none → default: display:block 복귀 시 필수)
        if (this._visResizeHandler) {
            // 다음 프레임에서 레이아웃 안정화 후 resize
            requestAnimationFrame(() => {
                if (!this._destroyed && this._visResizeHandler) {
                    this._visResizeHandler();
                }
            });
        }
        
        // 스킨 전환 시 현재 트랙 커버 + 마퀴 재적용
        // (ap-fixed로 전환하면 fap-ap-cover-big이 새로 생기고, 영역 크기도 바뀌므로 재체크 필요)
        if (this.playlist.length && typeof this.currentIndex === 'number') {
            const currentTrack = this.playlist[this.currentIndex];
            if (currentTrack) {
                // 커버 재적용 (작은/큰 커버 모두)
                this._updateTrackCover(currentTrack);
                
                // 재생 중일 때만 마퀴 재체크 (재생 중 아니면 textContent 상태)
                if (!this.audio.paused) {
                    // 상단 제목 마퀴 재설정 (레이아웃 안정화 위해 2프레임 대기)
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            if (!this._destroyed) this._setMarqueeTitle(currentTrack.name);
                        });
                    });
                    // 플레이리스트 재생 중 항목 마퀴 재설정 (가상 스크롤이 재렌더 후)
                    setTimeout(() => {
                        if (this._destroyed) return;
                        const activeItem = this.container.querySelector('.fap-pl-item.playing .fap-pl-name');
                        if (activeItem) this._setPlMarquee(activeItem);
                    }, 100);
                }
            }
        }
    }
    
    _removeSkinDecorations() {
        if (!this.$) return;
        const root = this.$.root;
        if (!root) return;
        root.querySelectorAll('.fap-skin-decoration').forEach(el => el.remove());
    }
    
    _addSkinDecorations(skinId) {
        if (!this.$) return;
        const root = this.$.root;
        if (!root) return;
        
        // 각 스킨별 고유 장식 요소 삽입
        switch (skinId) {
            case 'cassette':
                // 카세트: 상단 라벨 + 릴 2개가 있는 창
                this._injectDecoration('beforebegin-fap-now', `
                    <div class="fap-skin-decoration fap-cass-window">
                        <div class="fap-cass-reel"></div>
                        <div class="fap-cass-center">
                            <div>TDK-60</div>
                            <div>NORMAL POSITION</div>
                        </div>
                        <div class="fap-cass-reel"></div>
                    </div>
                `);
                break;
                
            case 'terminal':
                // 터미널: 타이틀바 (macOS 스타일 dot 3개)
                this._injectDecoration('prepend', `
                    <div class="fap-skin-decoration fap-term-titlebar">
                        <span class="fap-term-dot r"></span>
                        <span class="fap-term-dot y"></span>
                        <span class="fap-term-dot g"></span>
                        <span class="fap-term-title">user@player:~/music</span>
                    </div>
                `);
                // 하단 프롬프트
                this._injectDecoration('append', `
                    <div class="fap-skin-decoration fap-term-prompt">
                        <span class="fap-term-p">$</span> play --now<span class="fap-term-cursor"></span>
                    </div>
                `);
                break;
                
            case 'ap-fixed': {
                // APlayer Fixed: 큰 앨범 커버만 (가사 없음)
                const coverSrc = this.cover || '';
                const coverHtml = coverSrc
                    ? `<img src="${this._esc(coverSrc)}" alt="" draggable="false">`
                    : `<span class="fap-ap-cover-icon">🎵</span>`;
                this._injectDecoration('after-now', `
                    <div class="fap-skin-decoration fap-ap-cover-big">
                        ${coverHtml}
                    </div>
                `);
                break;
            }
        }
    }
    
    _injectDecoration(position, html) {
        if (!this.$) return;
        const root = this.$.root;
        if (!root) return;
        const temp = document.createElement('div');
        temp.innerHTML = html.trim();
        const el = temp.firstChild;
        if (!el) return;
        
        switch (position) {
            case 'prepend': {
                // 스킨 셀렉터 다음에 삽입 (즉 요소 맨 앞쪽)
                const selectorEl = root.querySelector('.fap-skin-selector');
                if (selectorEl && selectorEl.nextSibling) {
                    root.insertBefore(el, selectorEl.nextSibling);
                } else {
                    root.insertBefore(el, root.firstChild);
                }
                break;
            }
            case 'append':
                root.appendChild(el);
                break;
            case 'beforebegin-fap-now': {
                // fap-now 바로 앞에 삽입 (카세트 릴 창)
                const now = root.querySelector('.fap-now');
                if (now) root.insertBefore(el, now);
                break;
            }
            case 'after-seek': {
                // seek bar 다음에 삽입
                const seek = root.querySelector('.fap-seek');
                if (seek && seek.nextSibling) root.insertBefore(el, seek.nextSibling);
                else if (seek) seek.parentNode.appendChild(el);
                break;
            }
            case 'after-now': {
                // fap-now 직후에 삽입 (헤더 바로 아래 큰 커버)
                const nowAfter = root.querySelector('.fap-now');
                if (nowAfter && nowAfter.nextSibling) root.insertBefore(el, nowAfter.nextSibling);
                else if (nowAfter) nowAfter.parentNode.appendChild(el);
                break;
            }
            case 'before-seek': {
                // seek bar 직전에 삽입 (커버와 seek 사이 가사 영역)
                const seekBefore = root.querySelector('.fap-seek');
                if (seekBefore) seekBefore.parentNode.insertBefore(el, seekBefore);
                break;
            }
        }
    }

    // ── Event Bindings ──
    _bind() {
        const a = this.audio;
        // Play/Pause
        this.$.btnPlay.addEventListener('click', () => this.togglePlay());
        // Prev/Next
        this.$.btnPrev.addEventListener('click', () => this.prev());
        this.$.btnNext.addEventListener('click', () => this.next());
        // Loop
        this.$.btnLoop.addEventListener('click', () => {
            if (this.loop === 'all') this.loop = 'one';
            else if (this.loop === 'one') this.loop = 'none';
            else this.loop = 'all';
            this._updateLoopUI();
            this._saveLoopPref();  // ★ localStorage 저장
        });
        // ★ 가사 모달 (v5.8.1c — 옵션 A)
        if (this.$.btnLyrics) {
            this.$.btnLyrics.addEventListener('click', () => this._openLyricsModal());
        }
        if (this.$.lyricsModalClose) {
            this.$.lyricsModalClose.addEventListener('click', () => this._closeLyricsModal());
        }
        // ★ overlay 클릭 시 가사 모달 안 닫힘 (펜닐님 결정 v5.8.1c)
        //    다른 모달과 일관되게 외부 클릭으로 안 닫히도록 (X 버튼 또는 Esc로만 닫기)
        if (this.$.lyricsModalOverlay) {
            this.$.lyricsModalOverlay.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }
        // 모달 콘텐츠 영역 클릭도 mp3 모달로 전파 차단
        if (this.$.lyricsModal) {
            this.$.lyricsModal.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }
        // Shuffle
        this.$.btnShuffle.addEventListener('click', () => {
            this.shuffle = !this.shuffle;
            this.$.btnShuffle.classList.toggle('active', this.shuffle);
            if (this.shuffle) this._buildShuffleOrder();
            this._saveShufflePref();  // ★ localStorage 저장
        });
        // Volume button (mute toggle)
        this.$.btnVol.addEventListener('click', () => {
            if (this._getVolume() > 0) { this._prevVolume = this._getVolume(); this._setVolume(0); }
            else { this._setVolume(this._prevVolume || 0.8); }
            this._updateVolUI();
            this._showVolumeToast();
            this._saveVolumePref();
        });
        // Volume bar drag
        this._bindDrag(this.$.volBar, '_draggingVol', (ratio) => {
            this._setVolume(ratio);
            this._updateVolUI();
            this._showVolumeToast();
            this._saveVolumePref();
        });
        // Seek bar drag
        this._bindDrag(this.$.seekBar, '_draggingSeek', (ratio) => {
            if (!a.duration) return;
            const pct = Math.max(0, Math.min(1, ratio));
            this.$.seekPlayed.style.width = (pct * 100) + '%';
            this.$.seekThumb.style.left = (pct * 100) + '%';
            this.$.curTime.textContent = this._fmt(pct * a.duration);
        }, (ratio) => {
            if (!a.duration) return;
            a.currentTime = Math.max(0, Math.min(a.duration, ratio * a.duration));
        });
        // Audio events
        a.addEventListener('timeupdate', () => {
            if (this._draggingSeek || !a.duration) return;
            const pct = a.currentTime / a.duration;
            this.$.seekPlayed.style.width = (pct * 100) + '%';
            this.$.seekThumb.style.left = (pct * 100) + '%';
            this.$.curTime.textContent = this._fmt(a.currentTime);
            // 동기화 가사 활성 라인 갱신
            // 가사 갱신: synced=true → 활성 라인 변경 / synced=false → 자동 스크롤만
            if (this._lyrics) this._updateLyricsActiveLine();
        });
        a.addEventListener('loadedmetadata', () => {
            this.$.durTime.textContent = this._fmt(a.duration);
            this._updateTrackMeta();
            
            // ★ 재생 시 duration 자동 캐시 (FTP 등 사전 측정 실패한 곡 대비)
            // 플레이리스트에 duration 표시 + 서버 캐시에 저장
            if (a.duration && isFinite(a.duration) && a.duration > 0) {
                const i = this.currentIndex;
                const track = this.playlist[i];
                if (track && !this._vsDurations[i]) {
                    this._vsDurations[i] = this._fmt(a.duration);
                    // 화면에 보이면 즉시 업데이트
                    const li = this.$.plList.querySelector(`.fap-pl-item[data-index="${i}"]`);
                    if (li) {
                        const durEl = li.querySelector('.fap-pl-dur');
                        if (durEl) durEl.textContent = this._vsDurations[i];
                    }
                    // 원격 스토리지면 서버에 캐시 저장
                    if (this._isRemote && this._saveEndpoint && this._serverFileMeta) {
                        const meta = this._serverFileMeta[track.fullName];
                        if (meta && typeof App !== 'undefined' && App.api) {
                            App.api('save_audio_durations', {
                                storage_id: this._saveStorageId || 0,
                                items: [{
                                    path: meta.path,
                                    duration: Math.round(a.duration * 100) / 100,
                                    size: meta.size,
                                    mtime: meta.mtime,
                                }],
                            }, 'POST').catch(() => {});
                        }
                    }
                }
            }
        });
        a.addEventListener('progress', () => this._updateBuffered());
        a.addEventListener('ended', () => this._onEnded());
        a.addEventListener('play', () => {
            this._updatePlayUI(true);
            // 첫 재생 시 비주얼라이저 초기화 (autoplay policy 우회 위해 사용자 상호작용 후)
            if (!this._visInitialized && !this._visFailed) this._initVisualizer();
            this._startVisualizer();
            // 재생 중 세션 keepalive + 화면 자동 잠금 방지
            this._startMediaKeepalive();
            this._acquireWakeLock();
        });
        a.addEventListener('pause', () => {
            this._updatePlayUI(false);
            this._stopVisualizer();
            // 일시정지 시 keepalive/wake lock 해제
            this._stopMediaKeepalive();
            this._releaseWakeLock();
        });
        a.addEventListener('ended', () => {
            // 마지막 곡 종료 시 keepalive/wake lock 해제 (다음 곡이 자동 재생되면 play 이벤트가 다시 켬)
            this._stopMediaKeepalive();
            this._releaseWakeLock();
        });
        
        // 재생 에러 처리 (404, 네트워크 오류, 파일 손상 등)
        // 에러 발생 시 다음 트랙으로 자동 스킵 (사용자 경험 향상)
        // 무한 스킵 방지: 연속 에러 3회 이상 시 중단
        this._errorSkipCount = 0;
        a.addEventListener('error', () => {
            if (this._destroyed) return;
            // src 설정 안 됐거나 빈 문자열일 때는 무시 (destroy 시 src='' 설정 시 error 발생)
            if (!a.src || a.src === window.location.href) return;
            
            // 재생 관련 리소스 정리
            this._stopMediaKeepalive();
            this._releaseWakeLock();
            this._stopVisualizer();
            
            // 연속 에러 제한
            this._errorSkipCount++;
            if (this._errorSkipCount >= 3) {
                // 연속 3곡 이상 실패 → 더 스킵 시도 안 함 (전체 플레이리스트가 깨졌을 가능성)
                this._errorSkipCount = 0;
                return;
            }
            
            // 플레이리스트에 다른 곡이 있으면 다음으로 (없으면 그냥 정지)
            // 에러로 인한 자동 스킵이므로 다음 곡은 자동 재생
            if (this.playlist.length > 1) {
                setTimeout(() => {
                    if (this._destroyed) return;
                    // next 내부는 audio.paused로 autoplay 판단하는데
                    // 에러 상태는 paused=true이므로 수동으로 재생 시도
                    let idx;
                    if (this.shuffle) {
                        const pos = this._shuffleOrder.indexOf(this.currentIndex);
                        idx = this._shuffleOrder[(pos + 1) % this._shuffleOrder.length];
                    } else {
                        idx = (this.currentIndex + 1) % this.playlist.length;
                    }
                    // autoplay=true로 강제 재생 시도 (에러 시엔 항상 다음 곡 재생)
                    this._loadTrack(idx, true);
                }, 500);  // 짧은 지연 후 다음 곡 (UX 고려)
            }
        });
        
        // 재생 성공적으로 시작되면 에러 카운터 리셋
        a.addEventListener('playing', () => {
            this._errorSkipCount = 0;
        });
        
        // 비주얼라이저 클릭/탭 → 모드 순환
        if (this.$.visualizer) {
            this.$.visualizer.addEventListener('click', (e) => {
                e.stopPropagation();
                this._cycleVisualizerMode();
            });
        }
        // Playlist click
        this.$.plList.addEventListener('click', (e) => {
            const li = e.target.closest('.fap-pl-item');
            if (!li) return;
            const idx = parseInt(li.dataset.index, 10);
            if (idx === this.currentIndex && !this.audio.paused) return;
            this._loadTrack(idx, true);
        });
        // Media Session API (잠금화면/알림센터 컨트롤)
        if ('mediaSession' in navigator) {
            navigator.mediaSession.setActionHandler('play', () => this.togglePlay());
            navigator.mediaSession.setActionHandler('pause', () => this.togglePlay());
            navigator.mediaSession.setActionHandler('previoustrack', () => this.prev());
            navigator.mediaSession.setActionHandler('nexttrack', () => this.next());
            try {
                navigator.mediaSession.setActionHandler('seekbackward', (details) => {
                    this.audio.currentTime = Math.max(0, this.audio.currentTime - (details.seekOffset || 10));
                });
                navigator.mediaSession.setActionHandler('seekforward', (details) => {
                    this.audio.currentTime = Math.min(this.audio.duration || 0, this.audio.currentTime + (details.seekOffset || 10));
                });
                navigator.mediaSession.setActionHandler('seekto', (details) => {
                    if (details.seekTime != null) this.audio.currentTime = details.seekTime;
                });
            } catch(e) {}
            // positionState 업데이트
            a.addEventListener('timeupdate', () => {
                if (!a.duration || !isFinite(a.duration)) return;
                try {
                    navigator.mediaSession.setPositionState({
                        duration: a.duration,
                        playbackRate: a.playbackRate || 1,
                        position: Math.min(a.currentTime, a.duration)
                    });
                } catch(e) {}
            });
            
            // ★ BF Cache 복원 시 MediaMetadata 재설정 (z_music/simple_mp3_player 참조)
            // iOS Safari에서 뒤로가기로 페이지 복원 시 MediaMetadata가 날아감 → 썸네일 사라짐
            this._pageshowHandler = (event) => {
                if (this._destroyed) return;
                if (event.persisted) {
                    // BF Cache에서 복원된 경우
                    const track = this.playlist[this.currentIndex];
                    if (track) {
                        this._updateMediaSession(track);
                        try {
                            navigator.mediaSession.playbackState = a.paused ? 'paused' : 'playing';
                        } catch(e) {}
                    }
                }
            };
            window.addEventListener('pageshow', this._pageshowHandler);
            
            // ★ visibilitychange 시 MediaMetadata 재설정 (iOS Safari 썸네일 사라짐 방지)
            // 탭 전환/복귀 시 iOS가 MediaMetadata를 지우는 경우가 있어서 복귀할 때 재설정
            this._mediaSessionVisHandler = () => {
                if (this._destroyed) return;
                if (!document.hidden) {
                    // 복귀: 500ms 지연 후 재설정 (iOS Safari가 처리할 시간 확보)
                    setTimeout(() => {
                        if (this._destroyed) return;
                        const track = this.playlist[this.currentIndex];
                        if (track) {
                            this._updateMediaSession(track);
                            try {
                                navigator.mediaSession.playbackState = a.paused ? 'paused' : 'playing';
                            } catch(e) {}
                        }
                    }, 500);
                }
            };
            document.addEventListener('visibilitychange', this._mediaSessionVisHandler);
        }
    }

    // ── Drag helper (seek & volume) ──
    _bindDrag(barEl, flagProp, onMove, onEnd) {
        const getR = (e) => {
            const r = barEl.getBoundingClientRect();
            const x = (e.touches && e.touches[0]) ? e.touches[0].clientX
                     : (e.changedTouches && e.changedTouches[0]) ? e.changedTouches[0].clientX
                     : e.clientX;
            return (x - r.left) / (r.width || 1);
        };
        const move = (e) => { e.preventDefault(); onMove(getR(e)); };
        const up = (e) => {
            this[flagProp] = false;
            const ratio = getR(e);
            onMove(ratio);
            if (onEnd) onEnd(ratio);
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', up);
            document.removeEventListener('touchmove', move);
            document.removeEventListener('touchend', up);
        };
        barEl.addEventListener('mousedown', (e) => {
            this[flagProp] = true;
            onMove(getR(e));
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
        });
        barEl.addEventListener('touchstart', (e) => {
            this[flagProp] = true;
            onMove(getR(e));
            document.addEventListener('touchmove', move, { passive: false });
            document.addEventListener('touchend', up);
        }, { passive: false });
    }

    // ── Track loading ──
    _loadTrack(idx, autoplay) {
        if (idx < 0 || idx >= this.playlist.length) return;
        this.currentIndex = idx;
        const track = this.playlist[idx];
        // ★ [HLS_DIAG] 음악 공유 폴더 트랙 로드
        if (window._diagLog) {
            window._diagLog('share_audio_load_track', {
                idx: idx,
                name: track.name,
                autoplay: !!autoplay,
                hasLyricsApi: !!track.lyricsApiUrl,
                hasCoverApi: !!track.coverApiUrl,
                playlistLength: this.playlist.length
            });
        }
        this.audio.src = track.url;
        // 재생 중이면 마퀴, 아니면 일반 텍스트
        if (!this.audio.paused || autoplay) {
            this._setMarqueeTitle(track.name);
        } else {
            this.$.title.classList.remove('fap-marquee');
            this.$.title.textContent = track.name;
        }
        this.$.seekPlayed.style.width = '0%';
        this.$.seekThumb.style.left = '0%';
        this.$.seekLoaded.style.width = '0%';
        this.$.curTime.textContent = '0:00';
        this.$.durTime.textContent = '0:00';
        // 트랙 메타 정보 업데이트 (서버 캐시가 있으면 즉시, 없으면 loadedmetadata 대기)
        this._updateTrackMeta();
        // 트랙 커버 업데이트 (ID3 우선, 폴더 이미지 fallback)
        this._updateTrackCover(track);
        // Update playlist active (virtual scroll)
        this._vsUpdateActive();
        this._vsRender();
        // Scroll active into view
        this._vsScrollToIndex(idx);
        if (autoplay) {
            this.audio.play().catch(() => {});
        }
        if (this.onTrackChange) this.onTrackChange(idx);
        // Media Session 즉시 업데이트 (title/artist — 잠금화면 즉시 반영)
        // artwork는 이 시점에 이전 트랙 artwork가 남을 수 있지만,
        // _updateTrackCover의 비동기 콜백에서 다시 호출되어 확정됨 (iOS Safari 대응)
        this._updateMediaSession(track);
        // 가사 로드 (LRC > USLT > TXT) — 비동기, 실패해도 무시
        this._loadLyrics(track);
    }
    
    // ── 가사 (LRC + USLT + TXT) ──
    
    /**
     * 가사 모달 열기 (v5.8.1c — 옵션 A)
     * - 정적 가사: 전체 텍스트 정적 표시 (사용자가 스크롤)
     * - 동기화 가사: 활성 라인 표시 (LRC) — 모달에서도 동기화 표시
     */
    _openLyricsModal() {
        if (!this.$.lyricsModal || !this._lyrics) return;
        // ★ display = '' 먼저 (v5.8.1c) — _renderLyricsModalContent에서 scrollTop/clientHeight 계산하려면
        //    모달이 보여야 정확한 값이 나옴. display: none 상태에선 clientHeight=0, scrollTop도 동작 불안정
        this.$.lyricsModal.style.display = '';
        // ★ 헤더 드래그 위치 초기화 (PC만, 매번 열 때 가운데로 복귀)
        //    이전 드래그 시 position:fixed/margin:0 등이 남아있을 수 있어 모두 리셋
        //    렌더 전에 위치 리셋해야 활성라인 scrollTop 계산 시 정확한 위치
        const _content = this.$.lyricsModal.querySelector('.fap-lyrics-modal-content');
        if (_content) {
            _content.style.position = '';
            _content.style.left = '';
            _content.style.top = '';
            _content.style.margin = '';
            _content.style.transform = '';
            _content.style.transition = '';
        }
        // 렌더 + 활성라인 표시 + 스크롤 위치 설정
        this._renderLyricsModalContent();
        // 다음 프레임에 보이는 클래스 추가 → CSS transition 작동
        requestAnimationFrame(() => {
            this.$.lyricsModal.classList.add('fap-lyrics-modal-open');
        });
        this._bindLyricsModalDrag();
    }
    
    /**
     * 가사 모달 헤더 드래그 (PC만, 모바일은 풀스크린이라 불필요)
     */
    _bindLyricsModalDrag() {
        if (this._lyricsModalDragBound) return;
        this._lyricsModalDragBound = true;
        
        const modal = this.$.lyricsModal;
        const content = modal?.querySelector('.fap-lyrics-modal-content');
        const header = modal?.querySelector('.fap-lyrics-modal-header');
        if (!modal || !content || !header) return;
        
        let isDragging = false;
        let startX = 0, startY = 0;
        let startLeft = 0, startTop = 0;
        
        const onDown = (e) => {
            // 모바일은 풀스크린이라 드래그 비활성화
            if (window.innerWidth <= 768) return;
            // 닫기 버튼 클릭 시는 드래그 안 함
            if (e.target.closest('.fap-lyrics-modal-close')) return;
            
            isDragging = true;
            const rect = content.getBoundingClientRect();
            startLeft = rect.left;
            startTop = rect.top;
            startX = e.clientX;
            startY = e.clientY;
            
            // 드래그 중 transition 비활성화 (부드럽지만 즉시 따라가게)
            content.style.transition = 'none';
            // fixed 위치로 전환 (.fap-lyrics-modal의 flex 가운데 정렬에서 분리)
            content.style.position = 'fixed';
            content.style.left = startLeft + 'px';
            content.style.top = startTop + 'px';
            content.style.margin = '0';
            content.style.transform = 'none';
            
            e.preventDefault();
        };
        
        const onMove = (e) => {
            if (!isDragging) return;
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            let newLeft = startLeft + dx;
            let newTop = startTop + dy;
            // 화면 밖으로 너무 나가지 않게 (헤더 일부는 항상 보이게)
            const minLeft = -content.offsetWidth + 100;  // 100px 보장
            const maxLeft = window.innerWidth - 100;
            const minTop = 0;
            const maxTop = window.innerHeight - 60;       // 헤더만큼은 보이게
            newLeft = Math.max(minLeft, Math.min(maxLeft, newLeft));
            newTop = Math.max(minTop, Math.min(maxTop, newTop));
            content.style.left = newLeft + 'px';
            content.style.top = newTop + 'px';
        };
        
        const onUp = () => {
            if (!isDragging) return;
            isDragging = false;
            content.style.transition = '';  // transition 복원
        };
        
        header.addEventListener('mousedown', onDown);
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        
        // 핸들러 참조 저장 (destroy 시 정리)
        this._lyricsModalDragHandlers = { onMove, onUp };
    }
    
    /**
     * 가사 모달 닫기
     */
    _closeLyricsModal() {
        if (!this.$.lyricsModal) return;
        this.$.lyricsModal.classList.remove('fap-lyrics-modal-open');
        // transition 끝나면 display:none
        setTimeout(() => {
            if (this.$.lyricsModal && !this.$.lyricsModal.classList.contains('fap-lyrics-modal-open')) {
                this.$.lyricsModal.style.display = 'none';
            }
        }, 200);
    }
    
    /**
     * 가사 모달 내용 렌더 (트랙 변경 시 갱신)
     */
    _renderLyricsModalContent() {
        if (!this.$.lyricsModalText || !this._lyrics) return;
        
        // 트랙 정보 표시 — 헤더 우측 상단에 노래 제목/아티스트
        if (this.$.lyricsModalTrack) {
            const track = this.playlist?.[this.currentIndex];
            if (track) {
                const title = track.title || track.name || '';
                const artist = track.artist || '';
                if (artist) {
                    this.$.lyricsModalTrack.textContent = title + ' — ' + artist;
                } else {
                    this.$.lyricsModalTrack.textContent = title;
                }
            } else {
                this.$.lyricsModalTrack.textContent = '';
            }
        }
        
        // 가사 라인 렌더 (인라인과 동일 클래스 → CSS 동기화 활성 라인 작동)
        const html = this._lyrics.map((line, idx) => {
            const text = this._esc(line.text || '');
            const empty = !text;
            return `<div class="fap-lyrics-modal-line${empty ? ' fap-lyrics-empty' : ''}" data-idx="${idx}">${text || '&nbsp;'}</div>`;
        }).join('');
        
        this.$.lyricsModalText.innerHTML = html;
        this.$.lyricsModalText.setAttribute('data-synced', this._lyricsSynced ? 'true' : 'false');
        // 모달 스크롤 위치 리셋
        this.$.lyricsModalText.scrollTop = 0;
        
        // ★ 모달 열 때 현재 활성라인 즉시 표시 (v5.8.1c — 다음 timeupdate 기다리지 않음)
        //    이전엔 _updateLyricsActiveLine이 _lyricsActiveLine 변경 안 됐으면 early return해서
        //    모달이 처음 열렸을 때 활성라인이 다음 줄 진행될 때까지 표시 안 됐음
        if (this._lyricsSynced && this._lyricsActiveLine >= 0) {
            const modalLines = this.$.lyricsModalText.querySelectorAll('.fap-lyrics-modal-line');
            const activeIdx = this._lyricsActiveLine;
            if (modalLines[activeIdx]) {
                modalLines[activeIdx].classList.add('fap-lyrics-active');
                if (activeIdx > 0 && modalLines[activeIdx - 1]) {
                    modalLines[activeIdx - 1].classList.add('fap-lyrics-prev');
                }
                if (activeIdx + 1 < modalLines.length && modalLines[activeIdx + 1]) {
                    modalLines[activeIdx + 1].classList.add('fap-lyrics-next');
                }
                // 활성 라인을 모달 가운데로 (수동 scrollTop — _updateLyricsActiveLine과 동일 패턴)
                // ※ scrollIntoView는 페이지 ancestor도 스크롤시킬 위험 있어 사용 안 함
                const modalEl = this.$.lyricsModalText;
                const lineEl = modalLines[activeIdx];
                const targetTop = lineEl.offsetTop - modalEl.clientHeight / 2 + lineEl.offsetHeight / 2;
                modalEl.scrollTop = Math.max(0, targetTop);
            }
        }
    }
    
    /**
     * 트랙의 가사 로드 — track.lyricsApiUrl이 있으면 fetch
     * 응답 형식: { source: 'lrc'|'uslt'|'txt', synced: bool, text: string, language?: string }
     */
    async _loadLyrics(track) {
        if (this._destroyed) return;
        
        // 토큰: 빠른 트랙 전환 시 이전 fetch 결과 무시
        if (this._lyricsLoadToken === undefined) this._lyricsLoadToken = 0;
        this._lyricsLoadToken += 1;
        const token = this._lyricsLoadToken;
        
        // ★ 펜닐님 결정 (옵션 A): 시작 시점에 wrap 숨김 안 함 → 레이아웃 시프트 0
        //   이전 트랙 가사가 잠깐 보이다가 새 가사로 교체됨 (또는 가사 없음 시 그때 숨김)
        //   이전 동기화 활성 라인은 즉시 해제 (타임 시프트 따라 잘못된 라인 활성화 방지)
        this._lyricsActiveLine = -1;
        
        // ★ 가사 버튼 초기화 (v5.8.1c — 옵션 A) — 새 트랙 로드 시 일단 숨김, 가사 있으면 _renderLyrics에서 보임
        if (this.$.btnLyrics) this.$.btnLyrics.style.display = 'none';
        // ★ 부모 root에 no-inline-lyrics 클래스 추가 — APlayer Fixed 등 grid 스킨에서 빈 row 압축용
        if (this.$.root) this.$.root.classList.add('fap-no-inline-lyrics');
        
        // API URL 없으면 즉시 가사 비우기 + wrap 숨김 (가사 없는 트랙)
        if (!track || !track.lyricsApiUrl) {
            this._lyrics = null;
            this._lyricsSynced = false;
            if (this.$.lyricsWrap) this.$.lyricsWrap.style.display = 'none';
            if (this.$.lyricsContent) this.$.lyricsContent.innerHTML = '';
            // 모달이 열려있던 경우 닫기
            if (this.$.lyricsModal && this.$.lyricsModal.style.display !== 'none') {
                this._closeLyricsModal();
            }
            return;
        }
        
        try {
            const res = await fetch(track.lyricsApiUrl, { credentials: 'same-origin' });
            if (this._destroyed || token !== this._lyricsLoadToken) return;
            
            // 가사 없음 (204) → 그때 비로소 wrap 숨김 + 가사 비우기
            if (res.status === 204) {
                this._lyrics = null;
                this._lyricsSynced = false;
                if (this.$.lyricsWrap) this.$.lyricsWrap.style.display = 'none';
                if (this.$.lyricsContent) this.$.lyricsContent.innerHTML = '';
                return;
            }
            if (!res.ok) {
                // 에러도 동일 처리 (가사 영역 비움)
                this._lyrics = null;
                this._lyricsSynced = false;
                if (this.$.lyricsWrap) this.$.lyricsWrap.style.display = 'none';
                if (this.$.lyricsContent) this.$.lyricsContent.innerHTML = '';
                return;
            }
            
            const data = await res.json();
            if (this._destroyed || token !== this._lyricsLoadToken) return;
            if (!data || !data.text) {
                this._lyrics = null;
                this._lyricsSynced = false;
                if (this.$.lyricsWrap) this.$.lyricsWrap.style.display = 'none';
                if (this.$.lyricsContent) this.$.lyricsContent.innerHTML = '';
                return;
            }
            
            // 가사 파싱 (이전 가사가 있다면 이 시점에 교체됨)
            this._lyricsSynced = !!data.synced;
            if (this._lyricsSynced) {
                this._lyrics = this._parseLrc(data.text);
            } else {
                this._lyrics = this._parsePlainLyrics(data.text);
            }
            
            if (!this._lyrics || this._lyrics.length === 0) {
                this._lyrics = null;
                if (this.$.lyricsWrap) this.$.lyricsWrap.style.display = 'none';
                if (this.$.lyricsContent) this.$.lyricsContent.innerHTML = '';
                return;
            }
            
            this._renderLyrics();
        } catch (e) {
            // 가사 로드 실패는 조용히 무시 (이전 가사 유지)
        }
    }
    
    /**
     * LRC 파서 — [mm:ss.xx]가사 패턴
     * 같은 가사가 여러 시간에 반복: [00:12.50][01:30.00]후렴 → 두 항목으로 분리
     * 메타데이터 ([ti:], [ar:] 등)는 무시
     * 반환: [{time: 12.5, text: '가사'}, ...] (시간 오름차순 정렬)
     */
    _parseLrc(text) {
        const lines = text.split(/\r?\n/);
        const result = [];
        const timeTagRe = /\[(\d{1,3}):(\d{1,2})(?:[.:](\d{1,3}))?\]/g;
        
        for (const line of lines) {
            // 메타데이터 라인 (시간 태그 없는 [ti:], [ar:] 등) 건너뜀
            if (/^\s*\[[a-z]+:/i.test(line) && !/\[\d{1,3}:\d{1,2}/.test(line)) continue;
            
            // 시간 태그 모두 추출
            const matches = [...line.matchAll(timeTagRe)];
            if (matches.length === 0) continue;
            
            // 가사 텍스트 = 마지막 시간 태그 이후
            const lastMatch = matches[matches.length - 1];
            const lyricText = line.substring(lastMatch.index + lastMatch[0].length).trim();
            if (!lyricText) continue;  // 빈 라인 (간주 표시 등)
            
            // 같은 가사를 여러 시간에 등록
            for (const m of matches) {
                const min = parseInt(m[1], 10);
                const sec = parseInt(m[2], 10);
                const ms = m[3] ? parseInt(m[3].padEnd(3, '0').substring(0, 3), 10) : 0;
                const time = min * 60 + sec + ms / 1000;
                if (time >= 0 && time < 36000) {  // 10시간 미만
                    result.push({ time, text: lyricText });
                }
            }
        }
        
        // 시간 오름차순 정렬
        result.sort((a, b) => a.time - b.time);
        return result;
    }
    
    /**
     * 정적 가사 파서 — 줄바꿈으로만 분리
     */
    _parsePlainLyrics(text) {
        const lines = text.split(/\r?\n/);
        const result = [];
        for (const line of lines) {
            const trimmed = line.trim();
            // 빈 줄도 포함 (가독성 위해)
            result.push({ time: -1, text: trimmed });
        }
        // 마지막 빈 줄 trim
        while (result.length > 0 && result[result.length - 1].text === '') {
            result.pop();
        }
        return result;
    }
    
    /**
     * 가사 렌더 — DOM 구성
     */
    _renderLyrics() {
        if (!this.$.lyricsContent || !this.$.lyricsWrap || !this._lyrics) return;
        
        const html = this._lyrics.map((line, idx) => {
            const text = this._esc(line.text || '');
            const empty = !text;
            return `<div class="fap-lyrics-line${empty ? ' fap-lyrics-empty' : ''}" data-idx="${idx}">${text || '&nbsp;'}</div>`;
        }).join('');
        
        this.$.lyricsContent.innerHTML = html;
        // ★ data-synced 속성: 한 줄 가사 CSS가 동기화/정적 가사 구분
        this.$.lyricsContent.setAttribute('data-synced', this._lyricsSynced ? 'true' : 'false');
        // ★ 정적 가사(USLT/TXT)면 wrap에 백업 클래스 추가 (:has() 미지원 브라우저 대비)
        if (this._lyricsSynced) {
            this.$.lyricsWrap.classList.remove('fap-lyrics-static');
        } else {
            this.$.lyricsWrap.classList.add('fap-lyrics-static');
        }
        // ★ 가사 표시 정책 (v5.8.1c — 펜닐님 결정 옵션 A):
        //    - 동기화된 가사 (LRC, synced=true): 인라인 표시 + 가사 버튼 활성화 (모달 같이 가능)
        //    - 정적 가사 (USLT/TXT, synced=false): 인라인 숨김 + 가사 버튼만 활성화 (모달에서만 표시)
        if (this._lyricsSynced) {
            this.$.lyricsWrap.style.display = '';
            // ★ 인라인 가사 표시 → 부모 grid에서 자리 차지하도록 클래스 제거
            if (this.$.root) this.$.root.classList.remove('fap-no-inline-lyrics');
        } else {
            this.$.lyricsWrap.style.display = 'none';
            // ★ 정적 가사: 인라인 숨김 → 부모 grid에서 row 압축 위해 클래스 유지
            if (this.$.root) this.$.root.classList.add('fap-no-inline-lyrics');
        }
        // ★ 가사 버튼 활성화 — 가사가 있으니 버튼 표시
        if (this.$.btnLyrics) this.$.btnLyrics.style.display = '';
        // ★ 모달이 열려있으면 새 트랙 가사로 갱신
        if (this.$.lyricsModal && this.$.lyricsModal.style.display !== 'none') {
            this._renderLyricsModalContent();
        }
        
        // ★ 수동 스크롤 이벤트 — 한 번만 등록 (중복 방지 플래그)
        if (!this._lyricsScrollHandlerBound && this.$.lyricsScroll) {
            this._lyricsScrollHandlerBound = true;
            const onManual = () => this._onLyricsManualScroll();
            this.$.lyricsScroll.addEventListener('wheel', onManual, { passive: true });
            this.$.lyricsScroll.addEventListener('touchmove', onManual, { passive: true });
        }
        
        // 새 트랙 로드 시 자동 스크롤 일시 중지 해제 + 스크롤 위치 리셋
        this._lyricsManualScrollUntil = 0;
        if (this.$.lyricsScroll) this.$.lyricsScroll.scrollTop = 0;
    }
    
    /**
     * 시간 동기화된 LRC/SYLT/TXT(synced): 활성 라인 + 이전/다음 라인 표시 (3줄 고정)
     * USLT/TXT(plain): 사용자 수동 스크롤 중이 아니면 시간 비례 자동 스크롤
     * 
     * timeupdate 이벤트에서 매번 호출됨
     */
    _updateLyricsActiveLine() {
        if (!this._lyrics || this._destroyed) return;
        if (!this.$.lyricsContent || !this.$.lyricsScroll) return;
        
        const currentTime = this.audio.currentTime || 0;
        
        // ── synced=true (LRC/SYLT/TXT synced): 3줄 고정 표시 ──
        if (this._lyricsSynced) {
            // 이진 탐색으로 현재 라인 찾기
            let activeIdx = -1;
            let lo = 0, hi = this._lyrics.length - 1;
            while (lo <= hi) {
                const mid = (lo + hi) >> 1;
                if (this._lyrics[mid].time <= currentTime) {
                    activeIdx = mid;
                    lo = mid + 1;
                } else {
                    hi = mid - 1;
                }
            }
            
            if (activeIdx === this._lyricsActiveLine) return;
            this._lyricsActiveLine = activeIdx;
            
            const allLines = this.$.lyricsContent.querySelectorAll('.fap-lyrics-line');
            allLines.forEach(el => {
                el.classList.remove('fap-lyrics-active', 'fap-lyrics-prev', 'fap-lyrics-next');
            });
            
            if (activeIdx >= 0 && allLines[activeIdx]) {
                allLines[activeIdx].classList.add('fap-lyrics-active');
                if (activeIdx > 0 && allLines[activeIdx - 1]) {
                    allLines[activeIdx - 1].classList.add('fap-lyrics-prev');
                }
                if (activeIdx + 1 < allLines.length && allLines[activeIdx + 1]) {
                    allLines[activeIdx + 1].classList.add('fap-lyrics-next');
                }
            }
            
            // ★ 모달도 동기화 활성라인 갱신 (v5.8.1c) — 모달 열려있을 때만
            if (this.$.lyricsModalText && this.$.lyricsModal && this.$.lyricsModal.style.display !== 'none') {
                const modalLines = this.$.lyricsModalText.querySelectorAll('.fap-lyrics-modal-line');
                modalLines.forEach(el => {
                    el.classList.remove('fap-lyrics-active', 'fap-lyrics-prev', 'fap-lyrics-next');
                });
                if (activeIdx >= 0 && modalLines[activeIdx]) {
                    modalLines[activeIdx].classList.add('fap-lyrics-active');
                    if (activeIdx > 0 && modalLines[activeIdx - 1]) {
                        modalLines[activeIdx - 1].classList.add('fap-lyrics-prev');
                    }
                    if (activeIdx + 1 < modalLines.length && modalLines[activeIdx + 1]) {
                        modalLines[activeIdx + 1].classList.add('fap-lyrics-next');
                    }
                    // 활성 라인을 모달 가운데로 부드럽게 스크롤 (수동 scrollTo — 메인 app.js와 동일 패턴)
                    // ※ scrollIntoView는 페이지 ancestor도 스크롤시킬 위험 있어 사용 안 함 (v5.8.1c 일관성)
                    const modalEl = this.$.lyricsModalText;
                    const lineEl = modalLines[activeIdx];
                    const targetTop = lineEl.offsetTop - modalEl.clientHeight / 2 + lineEl.offsetHeight / 2;
                    if (Math.abs(modalEl.scrollTop - targetTop) > 10) {
                        modalEl.scrollTo({ top: targetTop, behavior: 'smooth' });
                    }
                }
            }
            return;
        }
        
        // ★ synced=false (USLT/TXT plain): 자동 스크롤 비활성 (v5.8.1c — 펜닐님 결정 옵션 A)
        //    이전엔 시간 비례 자동 스크롤이었으나, 정적 가사는 사용자가 자유롭게 스크롤하도록 변경
        //    → 인라인은 어차피 _renderLyrics에서 숨김 (정적 가사는 모달에서만 보임)
        return;
    }
    
    /**
     * 사용자 수동 스크롤 감지 — 자동 스크롤 5초간 일시 중지
     */
    _onLyricsManualScroll() {
        this._lyricsManualScrollUntil = Date.now() + 5000;
    }
    
    // ★ 커버 이미지 메모리 캐시 헬퍼
    //   원본 URL을 받아서 blob URL 반환 (한 번 fetch한 건 메모리에 보관, 재요청 시 즉시 반환)
    //   - 같은 세션 내에선 100% 캐시 hit (브라우저 캐시 정책 무관)
    //   - 모바일 셀룰러에서 데이터 사용량 대폭 감소
    //   - 페이지 새로고침 시엔 캐시 비워짐 (정상)
    //
    // 동작:
    // 커버 이미지 캐시 헬퍼 — 같은 URL은 한 번만 fetch하고 blob URL로 변환
    // 동작 (3단계 캐시):
    //   1. 메모리 캐시 hit → 즉시 반환
    //   2. 진행 중 fetch 있으면 그 promise 재사용 (중복 요청 방지)
    //   3. IndexedDB 영구 캐시 조회 (모달 닫혀도 유지) — 펜닐님 환경 Apache가 HTTP 캐시 무효화하는 경우 대비
    //   4. 새 fetch → blob → URL.createObjectURL → 메모리+IDB 저장 → 반환
    //   5. fetch 실패 시 원본 URL 그대로 반환 (fallback)
    //
    // 호출자: applyCover()와 가상 스크롤 li 렌더 시
    // ★ 동시 fetch 제한 큐 처리 (펜닐님 결정 — iOS만 4개 제한)
    //   PC는 _COVER_FETCH_LIMIT = Infinity → 즉시 실행
    //   iOS는 _COVER_FETCH_LIMIT = 4 → 큐에 대기, 응답 도착 시 다음 처리
    _coverFetchProcess() {
        while (this._coverFetchActive < this._COVER_FETCH_LIMIT && this._coverFetchQueue.length > 0) {
            const task = this._coverFetchQueue.shift();
            this._coverFetchActive++;
            // task.run() 실행 → 완료/실패 시 active 감소 + 다음 처리
            task.run()
                .then(result => task.resolve(result))
                .catch(err => task.reject(err))
                .finally(() => {
                    this._coverFetchActive--;
                    // 다음 큐 처리 (재귀 — 마이크로태스크에서 안전)
                    this._coverFetchProcess();
                });
        }
    }
    // 큐를 거쳐 fetch 실행 (Promise<Response> 반환)
    _coverFetchQueued(url) {
        // PC (Infinity 제한): 큐 거치지 않고 즉시 fetch
        if (this._COVER_FETCH_LIMIT === Infinity) {
            return fetch(url, { credentials: 'same-origin' });
        }
        // iOS: 큐에 추가하고 처리 시작
        return new Promise((resolve, reject) => {
            this._coverFetchQueue.push({
                run: () => fetch(url, { credentials: 'same-origin' }),
                resolve,
                reject
            });
            this._coverFetchProcess();
        });
    }
    
    async _getCachedCoverUrl(originalUrl) {
        if (!originalUrl) return null;
        if (this._destroyed) return originalUrl;
        // 1. 메모리 캐시 hit
        if (this._coverBlobCache.has(originalUrl)) {
            return this._coverBlobCache.get(originalUrl);
        }
        // 2. 진행 중인 fetch 있으면 그 결과 기다림
        if (this._coverBlobPending.has(originalUrl)) {
            return this._coverBlobPending.get(originalUrl);
        }
        // 3. 새 작업 시작 (IDB 조회 → 미스면 fetch)
        const fetchPromise = (async () => {
            try {
                // 3-A. IndexedDB 영구 캐시 조회
                let blob = null;
                try {
                    blob = await FSCoverCacheDB.get(originalUrl);
                } catch (e) { blob = null; }
                
                if (!blob) {
                    // 3-B. IDB miss → 서버에서 fetch (iOS는 큐 거침, PC는 즉시)
                    const res = await this._coverFetchQueued(originalUrl);
                    if (!res.ok) {
                        // 404/204 등 — 원본 URL 그대로 반환 (브라우저가 onerror 처리하도록)
                        return originalUrl;
                    }
                    blob = await res.blob();
                    // IDB 저장 (비동기, 결과 안 기다림 — 응답 지연 방지)
                    try { FSCoverCacheDB.set(originalUrl, blob); } catch (e) {}
                }
                
                if (this._destroyed) return originalUrl;
                const blobUrl = URL.createObjectURL(blob);
                this._coverBlobCache.set(originalUrl, blobUrl);
                return blobUrl;
            } catch (e) {
                // 네트워크 에러 등 - 원본 URL 그대로 반환 (fallback)
                return originalUrl;
            } finally {
                this._coverBlobPending.delete(originalUrl);
            }
        })();
        this._coverBlobPending.set(originalUrl, fetchPromise);
        return fetchPromise;
    }
    
    // 트랙별 커버 업데이트 로직 (ID3 우선, 폴더 이미지 fallback, 🎵 아이콘 마지막)
    // 동작:
    // 1. track.coverApiUrl(ID3 커버) 있으면 Image()로 로드 시도
    //    - 성공(onload) → 이 URL을 커버로 사용
    //    - 실패(onerror, 404 등) → 폴더 이미지(this.cover)로 fallback
    // 2. coverApiUrl 없으면 → 바로 폴더 이미지 사용
    // 3. 폴더 이미지도 없으면 → 🎵 아이콘
    //
    // 적용 대상:
    //  - .fap-now-cover : 작은 커버 (모든 스킨 공통)
    //  - .fap-ap-cover-big : APlayer Fixed 스킨의 큰 커버 (있으면)
    _updateTrackCover(track) {
        if (!this.$ || !this.$.cover) return;
        
        // 현재 트랙의 커버 토큰 (경쟁 조건 방지 — 트랙 빠르게 전환 시 이전 로드 결과 무시)
        const token = ++this._coverLoadToken;
        
        const applyCover = (url) => {
            if (token !== this._coverLoadToken) return;  // 이미 다음 트랙으로 넘어감
            if (!this.$ || !this.$.cover) return;
            
            // 현재 활성 커버 URL 저장 (Media Session artwork용)
            // iOS Safari는 artwork 배열 fallback 안 함 → 확인된 URL만 단일 설정 필요
            this._activeCoverUrl = url || null;
            // 커버 확정 후 Media Session artwork 갱신 (iOS/Android 잠금화면 반영)
            // token 체크로 이미 "현재 트랙" 확정된 상태 → 안전하게 호출 가능
            if (track) this._updateMediaSession(track);
            
            // 1) 작은 커버 (메인) - 모든 스킨 공통
            if (url) {
                this.$.cover.innerHTML = `<img src="${this._esc(url)}" alt="" draggable="false">`;
            } else {
                this.$.cover.innerHTML = '<span class="fap-now-cover-icon">🎵</span>';
            }
            
            // 2) APlayer Fixed 스킨의 큰 커버 (장식 요소) - 있을 때만
            const apBigCover = this.container.querySelector('.fap-ap-cover-big');
            if (apBigCover) {
                if (url) {
                    apBigCover.innerHTML = `<img src="${this._esc(url)}" alt="" draggable="false">`;
                } else {
                    apBigCover.innerHTML = '<span class="fap-ap-cover-icon">🎵</span>';
                }
            }
        };
        
        // applyCover에 폴더 커버 URL 전달 시 메모리 캐시 사용
        //   가상 스크롤과 같은 이유: 같은 폴더 100곡 재생 시 폴더 커버 URL은 모두 동일
        //   → 첫 곡 재생 시 캐시 hit으로 즉시 적용 (재요청 없음)
        const applyCoverCached = (url) => {
            if (token !== this._coverLoadToken || this._destroyed) return;
            if (!url) {
                applyCover(null);
                return;
            }
            // 메모리 캐시 조회 (있으면 즉시, 없으면 fetch 후 적용)
            this._getCachedCoverUrl(url).then(cachedUrl => {
                if (token !== this._coverLoadToken || this._destroyed) return;
                applyCover(cachedUrl || url);
            });
        };
        
        // 1단계: ID3 커버 URL 있으면 먼저 시도 (메모리 캐시 활용)
        if (track.coverApiUrl) {
            // 이전 진행 중인 테스트 이미지가 있으면 취소 (메모리 누수 방지)
            // 빠른 트랙 전환 시 이전 로드가 메모리에 남지 않도록
            if (this._coverTestImg) {
                this._coverTestImg.onload = null;
                this._coverTestImg.onerror = null;
                this._coverTestImg.src = '';  // 로드 중단
                this._coverTestImg = null;
            }
            // 메모리 캐시에서 blob URL 조회 (없으면 fetch + 캐시)
            //   세션 내 같은 URL 재요청 안 함 → 데이터 절감
            this._getCachedCoverUrl(track.coverApiUrl).then(cachedUrl => {
                if (token !== this._coverLoadToken || this._destroyed) return;
                if (!cachedUrl) {
                    applyCoverCached(this.cover || null);
                    return;
                }
                // 이미지 로드 가능 여부 확인 (404/204면 폴더 이미지로 fallback)
                const testImg = new Image();
                this._coverTestImg = testImg;
                testImg.onload = () => {
                    if (this._destroyed || token !== this._coverLoadToken) return;
                    applyCover(cachedUrl);
                    if (this._coverTestImg === testImg) this._coverTestImg = null;
                };
                testImg.onerror = () => {
                    if (this._destroyed || token !== this._coverLoadToken) return;
                    // ID3 커버 없음 또는 404 → 폴더 이미지로 fallback (캐시 활용)
                    applyCoverCached(this.cover || null);
                    if (this._coverTestImg === testImg) this._coverTestImg = null;
                };
                testImg.src = cachedUrl;
            });
        } else {
            // ID3 커버 시도 대상 아님 (mp3 아님/원격 스토리지/vault) → 바로 폴더 이미지 (캐시 활용)
            //   FTP/SFTP 환경 핵심 케이스: 같은 폴더의 100곡 모두 같은 cover.jpg → 캐시로 1번만 받음
            applyCoverCached(this.cover || null);
        }
    }

    // ── Playback controls ──
    togglePlay() {
        if (!this.audio.src) return;
        this.audio.paused ? this.audio.play().catch(() => {}) : this.audio.pause();
    }
    prev() {
        if (!this.playlist.length) return;
        let idx;
        if (this.shuffle) {
            const pos = this._shuffleOrder.indexOf(this.currentIndex);
            idx = this._shuffleOrder[(pos - 1 + this._shuffleOrder.length) % this._shuffleOrder.length];
        } else {
            idx = (this.currentIndex - 1 + this.playlist.length) % this.playlist.length;
        }
        this._loadTrack(idx, !this.audio.paused);
    }
    next() {
        if (!this.playlist.length) return;
        let idx;
        if (this.shuffle) {
            const pos = this._shuffleOrder.indexOf(this.currentIndex);
            idx = this._shuffleOrder[(pos + 1) % this._shuffleOrder.length];
        } else {
            idx = (this.currentIndex + 1) % this.playlist.length;
        }
        this._loadTrack(idx, !this.audio.paused);
    }

    // ── End handler ──
    _onEnded() {
        if (this.loop === 'one') {
            this.audio.currentTime = 0;
            this.audio.play().catch(() => {});
        } else if (this.loop === 'all') {
            // ended 시 audio.paused=true이므로 next()의 !paused=false → 직접 loadTrack
            const nextIdx = this.shuffle
                ? this._shuffleOrder[(this._shuffleOrder.indexOf(this.currentIndex) + 1) % this._shuffleOrder.length]
                : (this.currentIndex + 1) % this.playlist.length;
            this._loadTrack(nextIdx, true);
        } else {
            // 'none': play next unless last
            if (this.shuffle) {
                const pos = this._shuffleOrder.indexOf(this.currentIndex);
                if (pos < this._shuffleOrder.length - 1) {
                    const nextIdx = this._shuffleOrder[pos + 1];
                    this._loadTrack(nextIdx, true);
                }
            } else {
                if (this.currentIndex < this.playlist.length - 1) {
                    this._loadTrack(this.currentIndex + 1, true);
                }
            }
        }
    }

    // ── UI updates ──
    _updatePlayUI(playing) {
        // 방어: this.$ 또는 개별 요소 null (destroy 중/후 잔여 호출) 대응
        if (!this.$ || !this.$.iconPlay || !this.$.iconPause) return;
        this.$.iconPlay.style.display = playing ? 'none' : '';
        this.$.iconPause.style.display = playing ? '' : 'none';
        // Media Session playbackState
        if ('mediaSession' in navigator) {
            navigator.mediaSession.playbackState = playing ? 'playing' : 'paused';
        }
        // 상단 제목 마퀴: 재생 중이면 활성, 정지 시 해제
        if (playing) {
            const track = this.playlist[this.currentIndex];
            if (track) this._setMarqueeTitle(track.name);
        } else {
            this.$.title.classList.remove('fap-marquee');
            const track = this.playlist[this.currentIndex];
            if (track) this.$.title.textContent = track.name;
        }
        // Playlist active item animation + marquee (virtual scroll)
        this._vsUpdateActive();
    }
    _updateVolUI() {
        const v = this._getVolume();
        this.$.volLevel.style.width = (v * 100) + '%';
        this.$.volThumb.style.left = (v * 100) + '%';
        this.$.iconVolOn.style.display = v > 0 ? '' : 'none';
        this.$.iconVolOff.style.display = v > 0 ? 'none' : '';
    }
    
    // ★ 볼륨 변경 시 화면에 잠깐 표시되는 토스트 (Spotify/YouTube Music 데스크톱 스타일)
    //   - 볼륨 슬라이더 드래그 / 키보드 단축키 / 음소거 버튼 시 호출
    //   - 1.2초 후 자동 페이드아웃
    //   - 볼륨바 중앙 바로 위에 표시 (조작 위치와 피드백 위치 일치)
    _showVolumeToast() {
        if (!this.$ || !this.$.volBar) return;
        let toast = this.$.volBar.querySelector('.fap-vol-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'fap-vol-toast';
            this.$.volBar.appendChild(toast);
        }
        const v = this._getVolume();
        const pct = Math.round(v * 100);
        // 음소거 시 0% 대신 🔇 아이콘 표시
        toast.textContent = v > 0 ? `🔊 ${pct}%` : `🔇 0%`;
        toast.classList.add('show');
        // 이전 타이머 취소
        if (this._volToastTimer) clearTimeout(this._volToastTimer);
        this._volToastTimer = setTimeout(() => {
            if (!this._destroyed && toast) toast.classList.remove('show');
        }, 1200);
    }
    
    // ★ 볼륨 영구 저장 (localStorage) — 플레이어 껐다 켜도 유지
    //   키: 'fap-volume', 값: '0.0' ~ '1.0' 문자열
    //   에러 시 무시 (localStorage 비활성/quota exceeded 등)
    _saveVolumePref() {
        try {
            localStorage.setItem('fap-volume', String(this._volume));
        } catch (e) { /* localStorage 비활성/quota — 무시 */ }
    }
    
    // ★ 반복 모드 영구 저장
    _saveLoopPref() {
        try {
            localStorage.setItem('fap-loop', this.loop);
        } catch (e) { /* localStorage 비활성/quota — 무시 */ }
    }
    
    // ★ 셔플 영구 저장
    _saveShufflePref() {
        try {
            localStorage.setItem('fap-shuffle', this.shuffle ? '1' : '0');
        } catch (e) { /* localStorage 비활성/quota — 무시 */ }
    }
    _updateBuffered() {
        const a = this.audio;
        if (a.buffered.length && a.duration) {
            this.$.seekLoaded.style.width = (a.buffered.end(a.buffered.length - 1) / a.duration * 100) + '%';
        }
    }
    _updateLoopUI() {
        const btn = this.$.btnLoop;
        btn.classList.remove('active', 'active-one');
        // 한국어/영어 자동 감지 (constructor에서 캐시 안 했으니 매번 체크)
        const _ko = (document.documentElement.lang || navigator.language || '').startsWith('ko');
        if (this.loop === 'all') {
            btn.classList.add('active');
            btn.title = _ko ? '반복: 전체 (L)' : 'Repeat: All (L)';
        } else if (this.loop === 'one') {
            btn.classList.add('active', 'active-one');
            btn.title = _ko ? '반복: 한 곡 (L)' : 'Repeat: One (L)';
        } else {
            btn.title = _ko ? '반복: 끔 (L)' : 'Repeat: Off (L)';
        }
    }

    // ── Shuffle ──
    _buildShuffleOrder() {
        this._shuffleOrder = this.playlist.map((_, i) => i);
        for (let i = this._shuffleOrder.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [this._shuffleOrder[i], this._shuffleOrder[j]] = [this._shuffleOrder[j], this._shuffleOrder[i]];
        }
    }

    // ── Volume helpers (iOS GainNode / PC audio.volume) ──
    _getVolume() { return this._volume; }
    _setVolume(v) {
        this._volume = Math.max(0, Math.min(1, v));
        this.audio.volume = this._volume;
    }

    // ── Visualizer (5가지 모드: 막대/미러막대/파형/물결/피크막대) ──
    _initVisualizer() {
        if (this._visInitialized || this._visFailed) return;
        if (!this.$.visualizer) return;
        
        // iOS: MediaElementSource 연결 시 백그라운드 재생이 끊기는 이슈 있음
        // 백그라운드 재생 + MediaSession이 비주얼보다 중요하므로 iOS에서는 비활성화
        if (this._isIOS) {
            this._visFailed = true;
            this.$.visualizer.style.display = 'none';
            return;
        }
        
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) { this._visFailed = true; return; }
            
            this._audioCtx = new AC();
            this._analyser = this._audioCtx.createAnalyser();
            // fftSize 2048: 저음 해상도 증가 (22Hz까지 세밀)
            // audioMotion-analyzer는 기본 8192 사용, 하지만 40px 높이엔 2048도 충분
            this._analyser.fftSize = 2048;
            // audioMotion 기본 smoothing (0.7) — 너무 높으면 둔하고 너무 낮으면 떨림
            this._analyser.smoothingTimeConstant = 0.7;
            // 동적 범위 — MDN 공식 예제와 동일
            this._analyser.minDecibels = -85;
            this._analyser.maxDecibels = -25;
            
            // MediaElementSource는 audio 당 한 번만 생성 가능
            this._mediaSource = this._audioCtx.createMediaElementSource(this.audio);
            this._mediaSource.connect(this._analyser);
            this._analyser.connect(this._audioCtx.destination);
            
            const bufferLength = this._analyser.frequencyBinCount;  // 512
            this._visFreqData = new Uint8Array(bufferLength);
            this._visTimeData = new Uint8Array(bufferLength);
            
            // 1/6 옥타브 밴드 매핑 (_visBars는 _buildLogBandMap 내부에서 설정됨)
            // 44.1kHz 기준 약 55개 밴드 (30Hz ~ 16kHz)
            this._visBandMap = this._buildLogBandMap(bufferLength);
            
            // 각 밴드의 현재값/피크 (밴드 수 확정 후 할당)
            this._visBandValues = new Float32Array(this._visBars);
            this._visPeaks = new Float32Array(this._visBars);
            this._visPeakVelocity = new Float32Array(this._visBars);
            
            // 모드 설정 (localStorage에서 복원, 기본: bars)
            this._visModes = ['bars', 'mirror', 'wave', 'ripple', 'peak'];
            try {
                const saved = localStorage.getItem('fap_vis_mode');
                this._visModeIdx = saved ? Math.max(0, this._visModes.indexOf(saved)) : 0;
                if (this._visModeIdx < 0) this._visModeIdx = 0;
            } catch(e) { this._visModeIdx = 0; }
            
            this._visInitialized = true;
            // 호출자(play 이벤트)에서 _startVisualizer 호출 — 여기서는 중복 호출 안 함
        } catch(e) {
            // 부분 초기화 실패: AudioContext 정리하여 audio 출력 복구
            // createMediaElementSource 이후 connect 실패 시 audio가 막힐 수 있음
            console.warn('[FSAudioPlayer] Visualizer init failed:', e.message);
            this._visFailed = true;
            try {
                if (this._mediaSource) {
                    this._mediaSource.disconnect();
                    this._mediaSource = null;
                }
                if (this._analyser) {
                    this._analyser.disconnect();
                    this._analyser = null;
                }
                if (this._audioCtx) {
                    this._audioCtx.close();
                    this._audioCtx = null;
                }
            } catch(e2) {}
            if (this.$.visualizer) this.$.visualizer.style.display = 'none';
        }
    }
    
    // FFT bin → 로그 스케일 밴드 매핑
    // 표준 1/3 옥타브 밴드 중심 주파수 기반 (ANSI S1.11-2004 / IEC 61260)
    // 이는 전문 스펙트럼 분석기에서 사용하는 방식과 동일
    _buildLogBandMap(binCount) {
        const sampleRate = (this._audioCtx && this._audioCtx.sampleRate) || 44100;
        const nyquist = sampleRate / 2;
        const hzPerBin = nyquist / binCount;
        
        // 1/6 옥타브 밴드 (표준 1/3옥타브보다 2배 세밀)
        // 30Hz에서 시작해 매 스텝마다 2^(1/6) 배씩 증가
        // 30 ~ 16000Hz 범위 → 약 55개 밴드
        const minHz = 30;
        const maxHz = Math.min(16000, nyquist * 0.9);  // nyquist보다 여유 있게
        const octaveFraction = 6;  // 1/6 옥타브
        const ratio = Math.pow(2, 1 / octaveFraction);
        
        const map = [];
        let centerFreq = minHz;
        while (centerFreq <= maxHz) {
            // 밴드의 경계 주파수 (중심의 -1/2 ~ +1/2 스텝)
            const freqLow = centerFreq / Math.pow(ratio, 0.5);
            const freqHigh = centerFreq * Math.pow(ratio, 0.5);
            const binLow = Math.max(1, Math.floor(freqLow / hzPerBin));
            const binHigh = Math.min(binCount - 1, Math.max(binLow + 1, Math.ceil(freqHigh / hzPerBin)));
            map.push([binLow, binHigh]);
            centerFreq *= ratio;
        }
        // 실제 밴드 수로 업데이트
        this._visBars = map.length;
        return map;
    }
    
    // 주파수 데이터를 로그 밴드로 변환 (0~1 float)
    // audioMotion-analyzer 방식: 밴드 내 peak 값 사용, 인위적 보정 없음
    // (AnalyserNode의 minDecibels/maxDecibels로 이미 dB → byte 변환 시 스케일링됨)
    _updateBandValues() {
        const data = this._visFreqData;
        const map = this._visBandMap;
        const values = this._visBandValues;
        const bars = this._visBars;
        for (let i = 0; i < bars; i++) {
            const [lo, hi] = map[i];
            // 해당 밴드에서 가장 강한 bin 값 (표준 스펙트럼 분석기 방식)
            let peak = 0;
            for (let j = lo; j < hi; j++) {
                if (data[j] > peak) peak = data[j];
            }
            values[i] = peak / 255;
        }
    }
    
    _startVisualizer() {
        if (!this._visInitialized || this._visRunning) return;
        this._visRunning = true;
        
        if (this._audioCtx && this._audioCtx.state === 'suspended') {
            this._audioCtx.resume().catch(() => {});
        }
        
        const canvas = this.$.visualizer;
        const ctx = canvas.getContext('2d');
        
        const resize = () => {
            const dpr = window.devicePixelRatio || 1;
            const cssW = canvas.offsetWidth || 600;
            const cssH = canvas.offsetHeight || 40;
            canvas.width = cssW * dpr;
            canvas.height = cssH * dpr;
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            this._visCssW = cssW;
            this._visCssH = cssH;
        };
        resize();
        this._visResizeHandler = resize;
        window.addEventListener('resize', resize);
        
        const draw = () => {
            if (!this._visRunning || this._destroyed) return;
            this._visRafId = requestAnimationFrame(draw);
            
            if (document.hidden) return;
            
            const cssW = this._visCssW;
            const cssH = this._visCssH;
            const mode = this._visModes[this._visModeIdx];
            
            // 파형 모드는 time domain 데이터 사용, 나머지는 주파수 데이터
            if (mode === 'wave') {
                this._analyser.getByteTimeDomainData(this._visTimeData);
            } else {
                this._analyser.getByteFrequencyData(this._visFreqData);
                this._updateBandValues();
            }
            
            ctx.clearRect(0, 0, cssW, cssH);
            
            switch (mode) {
                case 'bars': this._drawBars(ctx, cssW, cssH); break;
                case 'mirror': this._drawMirror(ctx, cssW, cssH); break;
                case 'wave': this._drawWave(ctx, cssW, cssH); break;
                case 'ripple': this._drawRipple(ctx, cssW, cssH); break;
                case 'peak': this._drawPeakBars(ctx, cssW, cssH); break;
            }
        };
        draw();
    }
    
    // 공통: 하단→상단 그라디언트 (사용자 선택 색상)
    _visGradient(ctx, y0, y1) {
        // 현재 선택된 색상 팔레트 가져오기
        const palette = (this._visColors || []).find(c => c.id === this._currentVisColor);
        const colors = palette ? palette.colors : ['#667eea', '#ec4899'];
        const g = ctx.createLinearGradient(0, y0, 0, y1);
        // 색상 배열을 0~1 범위에 고르게 분배
        const n = colors.length;
        if (n === 1) {
            g.addColorStop(0, colors[0]);
            g.addColorStop(1, colors[0]);
        } else {
            for (let i = 0; i < n; i++) {
                g.addColorStop(i / (n - 1), colors[i]);
            }
        }
        return g;
    }
    
    // 색상 팔레트의 첫/마지막 색상 (Ripple 등에서 사용)
    _visGradientColors() {
        const palette = (this._visColors || []).find(c => c.id === this._currentVisColor);
        const colors = palette ? palette.colors : ['#667eea', '#ec4899'];
        return {
            bottom: colors[0],
            top: colors[colors.length - 1],
        };
    }
    
    // hex 색상을 rgba(r,g,b,a)로 변환 (Ripple 반투명 채움용)
    _hexToRgba(hex, alpha) {
        if (!hex || hex[0] !== '#') return `rgba(102,126,234,${alpha})`;
        let h = hex.substring(1);
        if (h.length === 3) h = h.split('').map(c => c + c).join('');
        if (h.length !== 6) return `rgba(102,126,234,${alpha})`;
        const r = parseInt(h.substring(0, 2), 16);
        const g = parseInt(h.substring(2, 4), 16);
        const b = parseInt(h.substring(4, 6), 16);
        return `rgba(${r},${g},${b},${alpha})`;
    }
    
    // 모드 1: 막대 그래프 (기본)
    _drawBars(ctx, w, h) {
        const bars = this._visBars;
        const barWidth = w / bars;
        const barGap = Math.max(1, Math.floor(barWidth * 0.2));
        const drawWidth = Math.max(1, barWidth - barGap);
        for (let i = 0; i < bars; i++) {
            const v = this._visBandValues[i];
            const barHeight = Math.max(1, v * h);
            const x = i * barWidth;
            const y = h - barHeight;
            ctx.fillStyle = this._visGradient(ctx, h, y);
            ctx.fillRect(x, y, drawWidth, barHeight);
        }
    }
    
    // 모드 2: 미러 막대 (상하 대칭)
    _drawMirror(ctx, w, h) {
        const bars = this._visBars;
        const barWidth = w / bars;
        const barGap = Math.max(1, Math.floor(barWidth * 0.2));
        const drawWidth = Math.max(1, barWidth - barGap);
        const center = h / 2;
        for (let i = 0; i < bars; i++) {
            const v = this._visBandValues[i];
            const half = (v * h) / 2;
            const x = i * barWidth;
            ctx.fillStyle = this._visGradient(ctx, center + half, center - half);
            ctx.fillRect(x, center - half, drawWidth, half * 2);
        }
    }
    
    // 모드 3: 파형 (오실로스코프)
    _drawWave(ctx, w, h) {
        const data = this._visTimeData;
        const len = data.length;
        const sliceWidth = w / len;
        ctx.lineWidth = 1.5;
        ctx.strokeStyle = this._visGradient(ctx, h, 0);
        ctx.beginPath();
        let x = 0;
        for (let i = 0; i < len; i++) {
            const v = data[i] / 128.0;  // 0~2 (128이 중심)
            const y = (v * h) / 2;
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
            x += sliceWidth;
        }
        ctx.stroke();
    }
    
    // 모드 4: 물결 (영역 채움 스펙트럼)
    _drawRipple(ctx, w, h) {
        const bars = this._visBars;
        const step = w / (bars - 1);
        ctx.beginPath();
        ctx.moveTo(0, h);
        // 상단 곡선
        for (let i = 0; i < bars; i++) {
            const v = this._visBandValues[i];
            const x = i * step;
            const y = h - v * h;
            if (i === 0) ctx.lineTo(x, y);
            else {
                // quadraticCurveTo로 부드럽게
                const prevV = this._visBandValues[i - 1];
                const prevX = (i - 1) * step;
                const prevY = h - prevV * h;
                const cx = (prevX + x) / 2;
                const cy = (prevY + y) / 2;
                ctx.quadraticCurveTo(prevX, prevY, cx, cy);
            }
        }
        ctx.lineTo(w, h);
        ctx.closePath();
        // 채우기 (현재 색상 팔레트 적용)
        const palColors = this._visGradientColors();
        const fillGrad = ctx.createLinearGradient(0, h, 0, 0);
        fillGrad.addColorStop(0, this._hexToRgba(palColors.bottom, 0.2));
        fillGrad.addColorStop(1, this._hexToRgba(palColors.top, 0.9));
        ctx.fillStyle = fillGrad;
        ctx.fill();
        // 상단 라인 강조 (팔레트 상단 색상)
        ctx.strokeStyle = palColors.top;
        ctx.lineWidth = 1.2;
        ctx.stroke();
    }
    
    // 모드 5: 피크 막대 (막대 + 상단 피크 dot hold)
    _drawPeakBars(ctx, w, h) {
        const bars = this._visBars;
        const barWidth = w / bars;
        const barGap = Math.max(1, Math.floor(barWidth * 0.2));
        const drawWidth = Math.max(1, barWidth - barGap);
        const peakHeight = 2;  // 피크 dot 두께
        
        for (let i = 0; i < bars; i++) {
            const v = this._visBandValues[i];
            const barHeight = Math.max(1, v * h);
            const x = i * barWidth;
            const y = h - barHeight;
            
            // 막대 (약간 투명)
            ctx.fillStyle = this._visGradient(ctx, h, y);
            ctx.globalAlpha = 0.85;
            ctx.fillRect(x, y, drawWidth, barHeight);
            ctx.globalAlpha = 1;
            
            // 피크 업데이트 (중력 시뮬레이션)
            if (v > this._visPeaks[i]) {
                this._visPeaks[i] = v;
                this._visPeakVelocity[i] = 0;
            } else {
                // 중력으로 천천히 떨어짐
                this._visPeakVelocity[i] += 0.0008;
                this._visPeaks[i] = Math.max(0, this._visPeaks[i] - this._visPeakVelocity[i]);
            }
            
            // 피크 dot (팔레트 상단 색상)
            const peakY = h - this._visPeaks[i] * h;
            if (!this._visPeakColor) this._visPeakColor = this._visGradientColors().top;
            ctx.fillStyle = this._visPeakColor;
            ctx.fillRect(x, Math.max(0, peakY - peakHeight), drawWidth, peakHeight);
        }
    }
    
    // 클릭/탭으로 모드 순환
    _cycleVisualizerMode() {
        // 초기화 안 됐어도 모드는 바꿀 수 있음 (재생 시작 후 즉시 적용)
        // _visModes가 없으면 기본 배열 사용
        if (!this._visModes) this._visModes = ['bars', 'mirror', 'wave', 'ripple', 'peak'];
        if (typeof this._visModeIdx !== 'number') this._visModeIdx = 0;
        this._visModeIdx = (this._visModeIdx + 1) % this._visModes.length;
        // 피크 초기화
        if (this._visPeaks) this._visPeaks.fill(0);
        if (this._visPeakVelocity) this._visPeakVelocity.fill(0);
        try {
            localStorage.setItem('fap_vis_mode', this._visModes[this._visModeIdx]);
        } catch(e) {}
        // 모드 변경 라벨 표시
        this._showVisModeLabel();
    }
    
    _showVisModeLabel() {
        if (!this.$ || !this.$.visualizer) return;
        const labels = { bars: '막대', mirror: '미러', wave: '파형', ripple: '물결', peak: '피크' };
        const mode = this._visModes[this._visModeIdx];
        const label = labels[mode] || mode;
        // 기존 라벨 제거
        const old = this.container.querySelector('.fap-vis-label');
        if (old) old.remove();
        const el = document.createElement('div');
        el.className = 'fap-vis-label';
        el.textContent = label;
        this.$.visualizer.parentElement.appendChild(el);
        // destroy되면 el이 container 밖에 있을 수 있으니 방어적으로 처리
        setTimeout(() => {
            if (this._destroyed) return;
            if (el && el.parentElement) el.classList.add('fap-vis-label-fade');
        }, 10);
        setTimeout(() => {
            if (el && el.parentElement) el.remove();
        }, 1200);
    }
    
    // ── 재생 중 세션 keepalive (자동 로그아웃 방지) ──
    // 5분마다 서버에 session_ping 요청 → $_SESSION['last_activity'] 갱신
    // 조건: 재생 중 + 탭 활성 (숨김 상태에서는 진짜 자리 비운 것으로 간주)
    _startMediaKeepalive() {
        if (this._mediaKeepaliveInterval) return;  // 이미 실행 중이면 중복 방지
        const ping = () => {
            if (this._destroyed) return;
            if (this.audio.paused) return;  // 일시정지 상태면 skip
            if (document.hidden) return;     // 탭 숨김 상태면 skip
            // fetch with credentials (세션 쿠키 포함)
            fetch('api.php?action=session_ping', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            }).catch(() => {});  // 실패해도 조용히 무시 (네트워크 일시 끊김 등)
        };
        // 5분마다 ping (세션 타임아웃 최소값이 보통 10분 이상이라 5분 주기면 안전)
        this._mediaKeepaliveInterval = setInterval(ping, 5 * 60 * 1000);
    }
    
    _stopMediaKeepalive() {
        if (this._mediaKeepaliveInterval) {
            clearInterval(this._mediaKeepaliveInterval);
            this._mediaKeepaliveInterval = null;
        }
    }
    
    // ── 화면 자동 잠금 방지 (Wake Lock API) ──
    // 재생 중엔 화면 안 꺼지게 (모바일 필수, PC에도 도움)
    // visibilitychange 시 자동 재획득 (탭 복귀 시 Wake Lock 자동 풀림 대응)
    async _acquireWakeLock() {
        if (!('wakeLock' in navigator)) return;  // 미지원 브라우저 (iOS 16 미만 등)
        try {
            if (this._wakeLock) return;  // 이미 획득됨
            this._wakeLock = await navigator.wakeLock.request('screen');
            // Wake Lock이 시스템에 의해 풀릴 수 있음 (탭 숨김, 최소화 등)
            this._wakeLock.addEventListener('release', () => {
                this._wakeLock = null;
            });
            // 탭 복귀 시 재획득
            if (!this._wakeLockVisHandler) {
                this._wakeLockVisHandler = async () => {
                    if (document.visibilityState === 'visible' && 
                        !this.audio.paused && 
                        !this._wakeLock) {
                        try {
                            this._wakeLock = await navigator.wakeLock.request('screen');
                            this._wakeLock.addEventListener('release', () => {
                                this._wakeLock = null;
                            });
                        } catch(e) {}
                    }
                };
                document.addEventListener('visibilitychange', this._wakeLockVisHandler);
            }
        } catch(e) {
            // Wake Lock 요청 실패 (배터리 세이버 모드, 권한 거부 등) - 조용히 무시
        }
    }
    
    _releaseWakeLock() {
        if (this._wakeLock) {
            try { this._wakeLock.release(); } catch(e) {}
            this._wakeLock = null;
        }
        if (this._wakeLockVisHandler) {
            document.removeEventListener('visibilitychange', this._wakeLockVisHandler);
            this._wakeLockVisHandler = null;
        }
    }
    
    _stopVisualizer() {
        this._visRunning = false;
        if (this._visRafId) {
            cancelAnimationFrame(this._visRafId);
            this._visRafId = null;
        }
        if (this._visResizeHandler) {
            window.removeEventListener('resize', this._visResizeHandler);
            this._visResizeHandler = null;
        }
        // 막대 지우기 (일시정지 시 깨끗한 상태)
        if (this.$.visualizer) {
            const ctx = this.$.visualizer.getContext('2d');
            ctx.clearRect(0, 0, this.$.visualizer.width, this.$.visualizer.height);
        }
    }

    // ── Marquee title (좌→우 스크롤, 텍스트가 넘칠 때만) ──
    // 스킨 전환, 커버 이미지 로드 등으로 레이아웃이 변동되면 마퀴 판정이 어긋날 수 있음
    // → ResizeObserver로 크기 변화 감지 시 자동 재체크
    _setMarqueeTitle(text) {
        // this.$ null 방어 (생성자 중 호출 또는 destroy 직후 잔여 호출)
        const el = this.$ && this.$.title;
        if (!el) return;
        if (this._destroyed) return;
        
        // 현재 설정 토큰 (빠른 트랙 전환 시 이전 타이머 무시용)
        ++this._marqueeToken;
        
        el.classList.remove('fap-marquee');
        el.textContent = text;
        el._marqueeText = text;  // 재체크 시 참조용 (ResizeObserver 등)
        
        // 1차: 두 프레임 대기 후 체크 (레이아웃 안정화)
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                if (!this._destroyed) this._checkMarqueeTitle();
            });
        });
        
        // 2차: ResizeObserver로 제목 영역 크기 변화 감지 (스킨 전환, 커버 로드 시 재체크)
        // 한 번만 설치 (메모리 누수 방지) — 콜백이 el._marqueeText를 참조하므로 클로저 문제 없음
        if (!this._marqueeResizeObserver && typeof ResizeObserver !== 'undefined') {
            this._marqueeResizeObserver = new ResizeObserver(() => {
                if (this._destroyed) return;
                // debounce (다음 프레임에 한 번만 실행)
                if (this._marqueeResizeTimer) cancelAnimationFrame(this._marqueeResizeTimer);
                this._marqueeResizeTimer = requestAnimationFrame(() => {
                    this._marqueeResizeTimer = null;
                    if (!this._destroyed) this._checkMarqueeTitle();
                });
            });
            this._marqueeResizeObserver.observe(el);
        }
        
        // 3차: 커버 이미지 로드 등 추가 지연 로딩 대응 (500ms 후 최종 체크)
        setTimeout(() => {
            if (this._destroyed) return;
            this._checkMarqueeTitle();
        }, 500);
    }
    
    // 마퀴 적용/해제 재판정 (여러 시점에서 호출됨)
    // el._marqueeText를 읽어서 판정 → 클로저 없이 현재 상태로 작동
    _checkMarqueeTitle() {
        const el = this.$ && this.$.title;
        if (!el || !el.isConnected) return;
        const currentText = el._marqueeText;
        if (!currentText) return;
        
        // 마퀴 상태면 해제 후 측정 (scrollWidth 정확히 나오도록)
        const wasMarquee = el.classList.contains('fap-marquee');
        if (wasMarquee) {
            el.classList.remove('fap-marquee');
            el.textContent = currentText;
        }
        
        const needsMarquee = el.scrollWidth > el.clientWidth + 2;
        
        if (needsMarquee) {
            const escaped = this._esc(currentText);
            const gap = '<span style="display:inline-block;width:60px;"></span>';
            el.innerHTML = `<span class="fap-marquee-inner">${escaped}${gap}${escaped}${gap}</span>`;
            const dur = Math.max(6, currentText.length * 0.28);
            el.style.setProperty('--fap-marquee-dur', dur + 's');
            el.classList.add('fap-marquee');
            // ★ prefers-reduced-motion 등 글로벌 animation:none !important 회피
            //   자식 요소 (.fap-marquee-inner)에 개별 속성 !important 설정
            //   shorthand 사용 시 :hover의 animation-play-state:paused가 무시됨 → 개별 속성 사용
            const innerEl = el.querySelector('.fap-marquee-inner');
            if (innerEl) {
                innerEl.style.setProperty('animation-name', 'fapMarquee', 'important');
                innerEl.style.setProperty('animation-duration', dur + 's', 'important');
                innerEl.style.setProperty('animation-timing-function', 'linear', 'important');
                innerEl.style.setProperty('animation-iteration-count', 'infinite', 'important');
            }
        } else if (wasMarquee) {
            // 마퀴였는데 이제 필요 없으면 해제 (스킨 전환으로 영역 커진 경우)
            el.textContent = currentText;
        }
    }

    // ── Playlist marquee (재생 중인 항목만, hover 시 일시정지 — CSS에서 처리) ──
    // 커버 이미지 로딩/스킨 전환으로 인한 크기 변동에 안전하게 대응
    _setPlMarquee(nameEl) {
        if (!nameEl) return;
        nameEl.classList.remove('pl-scrolling');
        const text = nameEl.textContent || nameEl._origText || '';
        nameEl._origText = text;
        nameEl.textContent = text;
        
        // 1차: 두 프레임 뒤 체크 (레이아웃 안정화)
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                if (!this._destroyed) this._checkPlMarquee(nameEl);
            });
        });
        
        // 2차: 500ms 후 재체크 (커버 이미지 로드 후 영역 크기 확정 대응)
        setTimeout(() => {
            if (!this._destroyed) this._checkPlMarquee(nameEl);
        }, 500);
    }
    
    // 플레이리스트 항목 마퀴 재판정
    _checkPlMarquee(nameEl) {
        if (!nameEl || !nameEl.isConnected) return;
        const currentText = nameEl._origText;
        if (!currentText) return;
        
        // 마퀴 상태라면 해제 후 재측정 (정확한 scrollWidth 위해)
        const wasScrolling = nameEl.classList.contains('pl-scrolling');
        if (wasScrolling) {
            nameEl.classList.remove('pl-scrolling');
            nameEl.textContent = currentText;
        }
        
        if (nameEl.scrollWidth > nameEl.clientWidth + 2) {
            const escaped = this._esc(currentText);
            const gap = '<span style="display:inline-block;width:50px;"></span>';
            nameEl.innerHTML = `<span class="fap-pl-text">${escaped}${gap}${escaped}${gap}</span>`;
            const dur = Math.max(5, currentText.length * 0.25);
            nameEl.style.setProperty('--fap-pl-marquee-dur', dur + 's');
            nameEl.classList.add('pl-scrolling');
            // ★ prefers-reduced-motion 등 글로벌 animation:none !important 회피
            //   자식 요소 (.fap-pl-text)에 개별 속성 !important 설정
            //   shorthand 사용 시 :hover의 animation-play-state:paused가 무시됨 → 개별 속성 사용
            const textEl = nameEl.querySelector('.fap-pl-text');
            if (textEl) {
                textEl.style.setProperty('animation-name', 'fapMarquee', 'important');
                textEl.style.setProperty('animation-duration', dur + 's', 'important');
                textEl.style.setProperty('animation-timing-function', 'linear', 'important');
                textEl.style.setProperty('animation-iteration-count', 'infinite', 'important');
            }
        } else if (wasScrolling) {
            // 이제 필요 없으면 해제
            nameEl.textContent = currentText;
        }
    }
    _clearPlMarquee(nameEl) {
        if (!nameEl.classList.contains('pl-scrolling')) return;
        nameEl.classList.remove('pl-scrolling');
        nameEl.textContent = nameEl._origText || nameEl.textContent;
    }

    // ── Virtual Scroll (가상 스크롤) ──
    _VS_ITEM_H = 48;
    _VS_OVERSCAN = 5;
    _vsDurations = {};

    _vsInit() {
        const list = this.$.plList;
        if (!list) return;
        this._vsSpacer = list.querySelector('.fap-pl-virtual-spacer');
        if (this._vsSpacer) {
            this._vsSpacer.style.height = (this.playlist.length * this._VS_ITEM_H) + 'px';
            this._vsSpacer.style.width = '1px';
            this._vsSpacer.style.pointerEvents = 'none';
            this._vsSpacer.style.position = 'relative';
        }
        this._vsRenderedRange = { start: -1, end: -1 };
        this._vsItems = [];
        list.addEventListener('scroll', () => this._vsRender(), { passive: true });
        this._vsRender();
    }

    _vsRender() {
        const list = this.$.plList;
        if (!list || !this.playlist.length) return;
        const scrollTop = list.scrollTop;
        const viewH = list.clientHeight;
        const total = this.playlist.length;
        const startRaw = Math.floor(scrollTop / this._VS_ITEM_H);
        const endRaw = Math.ceil((scrollTop + viewH) / this._VS_ITEM_H);
        const start = Math.max(0, startRaw - this._VS_OVERSCAN);
        const end = Math.min(total, endRaw + this._VS_OVERSCAN);
        // 범위 동일하면 스킵
        if (start === this._vsRenderedRange.start && end === this._vsRenderedRange.end) return;
        this._vsRenderedRange = { start, end };
        // 기존 아이템 제거
        this._vsItems.forEach(el => el.remove());
        this._vsItems = [];
        const frag = document.createDocumentFragment();
        
        // 루프 전 계산 (this.cover는 변하지 않으므로 반복 계산 불필요)
        const folderCover = this.cover || '';
        const folderCoverHtml = folderCover ? this._esc(folderCover) : '';
        // JS 문자열 리터럴 + HTML 속성 중첩 이스케이프
        // 1) & → &amp; (먼저, URL의 '&'를 HTML 속성에서 안전하게)
        // 2) \ → \\ (JS 이스케이프)
        // 3) ' → \' (JS 문자열 구분자)
        // 4) " → &quot; (HTML 속성 구분자 충돌 방지)
        // 5) < > → &lt; &gt; (HTML 파서 안전)
        const escForOnerror = (s) => String(s)
            .replace(/&/g, '&amp;')
            .replace(/\\/g, '\\\\')
            .replace(/'/g, "\\'")
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
        const folderCoverJs = folderCover ? escForOnerror(folderCover) : '';
        
        // ★ 폴더 커버 prewarm: 같은 폴더의 모든 곡이 공유하는 폴더 커버 URL을
        //   가상 스크롤 루프 시작 전 한 번만 캐시 미리 로드 → 모든 li에서 hit
        //   FTP/SFTP 환경에서 100곡 같은 cover.jpg 매번 받던 문제 해결
        if (folderCover && !this._coverBlobCache.has(folderCover) && !this._coverBlobPending.has(folderCover)) {
            this._getCachedCoverUrl(folderCover);  // fire-and-forget (다음 호출 시 hit)
        }
        
        // ★ 트랙 번호 자릿수 (전체 곡 수에 맞춰 0 padding)
        //   예: 9곡 → 1자리 (1, 2, ..., 9)
        //       100곡 → 3자리 (001, 002, ..., 100)
        //       500곡 → 3자리 (001, 002, ..., 500)
        //   tabular-nums와 함께 정렬 깔끔
        const _numPadWidth = String(this.playlist.length).length;
        
        // ★ 비동기 캐시 로드 대기용 1x1 투명 PNG 데이터 URL
        //   <img src=""> 사용 시 일부 브라우저(Chrome 등)가 즉시 onerror 발동 →
        //   비동기 src 세팅 전에 onerror 트리거되어 첫 진입 시 썸네일 누락
        //   1x1 투명 PNG = 가장 호환성 좋은 표준 placeholder (모든 브라우저 정상 로드)
        const _imgPlaceholder = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';
        
        for (let i = start; i < end; i++) {
            const t = this.playlist[i];
            const li = document.createElement('li');
            li.className = 'fap-pl-item' + (i === this.currentIndex ? ' active' : '') + (i === this.currentIndex && !this.audio.paused ? ' playing' : '');
            li.dataset.index = i;
            li.style.position = 'absolute';
            li.style.top = (i * this._VS_ITEM_H) + 'px';
            li.style.left = '0';
            li.style.right = '0';
            li.style.height = this._VS_ITEM_H + 'px';
            // 커버 이미지: 트랙별 ID3 커버 우선, 실패 시 폴더 커버로 fallback
            // virtual scroll 친화적인 <img onerror> 방식 사용 (각 li 재사용되어도 안전)
            // 우선순위: track.coverApiUrl (ID3) → this.cover (폴더 이미지) → 🎵 아이콘
            // ★ ID3 커버 + 폴더 커버 모두 메모리 캐시 사용 (빈 img로 만든 후 비동기로 src 세팅)
            //   같은 세션 내에서 같은 URL 재요청 안 함 → 데이터 절감
            //   FTP/SFTP 환경: 폴더 cover.jpg가 100곡에 모두 같은 URL → 캐시 매우 효과적
            //
            // 폴더 커버 prewarm: 폴더 커버 캐시를 미리 로드 (한 번만 → 모든 li 공유)
            //   ID3 fallback 시 onerror가 동기적이라 async 캐시 조회 못 함 → 미리 캐시해두면 hit
            const folderCoverCachedSync = folderCover ? this._coverBlobCache.get(folderCover) : null;
            const folderCoverFallbackJs = folderCoverCachedSync 
                ? escForOnerror(folderCoverCachedSync) 
                : folderCoverJs;
            let coverHtml;
            let asyncCoverUrl = null;  // 비동기로 캐시 조회할 URL (없으면 null)
            if (t.coverApiUrl) {
                // ID3 커버 시도 (mp3 + 로컬)
                if (folderCover) {
                    // ID3 실패 → 폴더 커버로 전환 (캐시된 URL 우선), 그것도 실패 → 🎵 아이콘
                    coverHtml = `<img src="${_imgPlaceholder}" alt="" draggable="false" onerror="this.onerror=null;this.src='${folderCoverFallbackJs}';this.onerror=function(){this.parentNode.innerHTML='&#x1F3B5;';};">`;
                } else {
                    // ID3 실패 → 바로 🎵 아이콘
                    coverHtml = `<img src="${_imgPlaceholder}" alt="" draggable="false" onerror="this.parentNode.innerHTML='&#x1F3B5;';">`;
                }
                asyncCoverUrl = t.coverApiUrl;
            } else if (folderCoverHtml) {
                // ID3 시도 대상 아님 (mp3 아님/원격/vault) + 폴더 커버 있음
                //   FTP/SFTP에서 폴더 cover.jpg 사용하는 케이스 → 메모리 캐시 적용
                coverHtml = `<img src="${_imgPlaceholder}" alt="" draggable="false" onerror="this.parentNode.innerHTML='&#x1F3B5;';">`;
                asyncCoverUrl = folderCover;  // 원본 URL (escape 안 된 것)
            } else {
                // 폴더 커버도 없음 → 🎵 아이콘
                coverHtml = '<span>🎵</span>';
            }
            // ★ 트랙 번호 (1부터, 자릿수 padding) — 별도 span으로 분리해서 파일명 번호와 시각 구분
            //   예: 1자리 → "1", 3자리 → "001"
            //   CSS에서 작은 회색 박스로 표시 → 파일명에 이미 번호가 있어도 명확히 다름
            const _trackNum = String(i + 1).padStart(_numPadWidth, '0');
            li.innerHTML = `<div class="fap-pl-cover">${coverHtml}</div><span class="fap-pl-num">${_trackNum}</span><span class="fap-pl-name">${this._esc(t.name)}</span><span class="fap-pl-dur">${this._vsDurations[i] || ''}</span>`;
            // ★ 메모리 캐시에서 가져와 비동기로 src 세팅
            //   캐시 hit → 즉시 (네트워크 X) / 캐시 miss → 한 번 fetch 후 캐시 저장
            if (asyncCoverUrl) {
                const imgEl = li.querySelector('.fap-pl-cover img');
                const origUrl = asyncCoverUrl;
                this._getCachedCoverUrl(origUrl).then(cachedUrl => {
                    // li가 이미 DOM에서 제거됐으면 무시 (가상 스크롤에서 빠르게 스크롤 시)
                    if (this._destroyed || !imgEl || !imgEl.isConnected) return;
                    imgEl.src = cachedUrl || origUrl;
                });
            }
            // 재생 중인 곡 마퀴
            if (i === this.currentIndex && !this.audio.paused) {
                const nameEl = li.querySelector('.fap-pl-name');
                if (nameEl) setTimeout(() => this._setPlMarquee(nameEl), 50);
            }
            frag.appendChild(li);
            this._vsItems.push(li);
        }
        list.appendChild(frag);
    }

    _vsScrollToIndex(idx) {
        const list = this.$.plList;
        if (!list) return;
        
        // 레이아웃이 아직 계산 안 됐으면 다음 프레임에 재시도 (초기 로드 대응)
        if (!list.clientHeight) {
            requestAnimationFrame(() => this._vsScrollToIndex(idx));
            return;
        }
        
        const itemTop = idx * this._VS_ITEM_H;
        const viewH = list.clientHeight;
        const total = this.playlist.length * this._VS_ITEM_H;
        
        // 현재 곡을 뷰포트 가운데에 배치
        // (뷰포트 높이 - 아이템 높이) / 2 만큼 위로 올려서 아이템이 중앙에 오도록
        let targetScroll = itemTop - (viewH - this._VS_ITEM_H) / 2;
        
        // 범위 제한: 0 이상, 최대 스크롤 이하
        const maxScroll = Math.max(0, total - viewH);
        targetScroll = Math.max(0, Math.min(targetScroll, maxScroll));
        
        list.scrollTop = targetScroll;
    }

    _vsUpdateActive() {
        this._vsItems.forEach(li => {
            const idx = parseInt(li.dataset.index, 10);
            const isActive = idx === this.currentIndex;
            const isPlaying = isActive && !this.audio.paused;
            li.classList.toggle('active', isActive);
            li.classList.toggle('playing', isPlaying);
            const nameEl = li.querySelector('.fap-pl-name');
            if (!nameEl) return;
            if (isPlaying) {
                this._setPlMarquee(nameEl);
            } else {
                this._clearPlMarquee(nameEl);
            }
        });
    }

    // ── Media Session (잠금화면/백그라운드 컨트롤) ──
    _updateMediaSession(track) {
        if (!('mediaSession' in navigator)) return;
        try {
            const artwork = [];
            // iOS Safari는 artwork 배열의 첫 번째만 시도하고 실패해도 fallback 안 함
            // 따라서 _updateTrackCover에서 확인된 _activeCoverUrl을 단일 artwork로 설정
            // 
            // _activeCoverUrl은 ID3 커버 확인 완료 후 설정됨:
            //   - ID3 로드 성공 → coverApiUrl
            //   - ID3 실패/미지원 → 폴더 이미지 URL (this.cover)
            //   - 둘 다 없음 → null → 기본 SVG 아이콘
            //
            // 첫 호출 시 (ID3 로드 전) _activeCoverUrl이 undefined일 수 있음 — 폴더 이미지로 시작
            const resolved = (typeof this._activeCoverUrl !== 'undefined')
                ? this._activeCoverUrl
                : (this.cover || null);
            
            if (resolved) {
                // 이미지 MIME 자동 감지 (z_music/simple_mp3_player 참조)
                // FileStation은 주로 API URL이라 확장자 못 보지만, fallback으로 jpeg
                let imgType = 'image/jpeg';
                const lower = resolved.toLowerCase();
                if (lower.indexOf('.png') !== -1) imgType = 'image/png';
                else if (lower.indexOf('.webp') !== -1) imgType = 'image/webp';
                else if (lower.indexOf('.gif') !== -1) imgType = 'image/gif';
                
                // 여러 사이즈 제공 (z_music 방식 — iOS Safari가 적절한 걸 선택)
                artwork.push({ src: resolved, sizes: '96x96', type: imgType });
                artwork.push({ src: resolved, sizes: '128x128', type: imgType });
                artwork.push({ src: resolved, sizes: '192x192', type: imgType });
                artwork.push({ src: resolved, sizes: '256x256', type: imgType });
                artwork.push({ src: resolved, sizes: '384x384', type: imgType });
                artwork.push({ src: resolved, sizes: '512x512', type: imgType });
            } else {
                // 기본 음표 아이콘 (SVG → Data URL, 모든 플랫폼 동일)
                if (!this._defaultArtwork) {
                    this._defaultArtwork = 'data:image/svg+xml,' + encodeURIComponent(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">' +
                        '<rect width="512" height="512" rx="64" fill="#1e1e2e"/>' +
                        '<g fill="#7c6ef6" transform="translate(256,256) scale(0.55) translate(-256,-256)">' +
                        '<path d="M390 100v236c0 0-4 18-24 32s-46 22-66 18-38-22-38-48 14-44 34-54 38-12 50-8V164l-160 40v196c0 0-4 18-24 32s-46 22-66 18-38-22-38-48 14-44 34-54 38-12 50-8V140c0-12 8-22 20-26l168-44c16-4 30 4 30 20z"/>' +
                        '</g></svg>'
                    );
                }
                artwork.push({ src: this._defaultArtwork, sizes: '512x512', type: 'image/svg+xml' });
            }
            navigator.mediaSession.metadata = new MediaMetadata({
                title: track.name || '',
                artist: '',
                album: '',
                artwork: artwork
            });
        } catch(e) {}
    }

    // ── 플레이리스트 재생 시간 로드 ──
    _loadPlaylistDurations() {
        if (!this.playlist.length) return;
        if (this._durationLoadStarted) return; // 중복 실행 방지
        this._durationLoadStarted = true;
        
        // ★ FTP는 클라이언트 개별 측정(<audio> download) skip
        //   서버의 readPartial(CURLOPT_WRITEFUNCTION으로 정확히 64KB만 받음)만 사용
        //   - 성공한 곡: 시간 표시됨
        //   - 실패한 곡: 빈 칸, 재생 시 자동 캐시되어 다음부터 표시됨
        //   - 데이터 전송량 폭증 방지 (배포 사용자 912MB 이슈)
        //   - HTTP/2 프로토콜 에러 방지 (Content-Length 불일치)
        if (this._storageType === 'ftp') {
            this._skipClientMeasure = true; // 클라이언트 개별 측정 skip 플래그
        }
        
        // 스토리지 타입별 동시 연결 수 조절
        // - local/home/shared: 4 (빠름)
        // - sftp/smb/webdav: 2 (중간)
        // - ftp/s3: 1 (순차)
        let BATCH = 4;
        if (this._storageType === 'ftp' || this._storageType === 's3') {
            BATCH = 1;
        } else if (this._isRemote) {
            BATCH = 2;
        }
        let idx = 0;
        this._durLoaders = this._durLoaders || [];
        // 측정 완료한 duration 누적 (원격 스토리지일 때 서버에 일괄 저장용)
        this._pendingSave = [];
        const loadNext = () => {
            if (this._destroyed) return;
            // 이미 duration이 있는 항목은 스킵 (서버 캐시 hit)
            while (idx < this.playlist.length && this._vsDurations[idx]) {
                idx++;
            }
            if (idx >= this.playlist.length) {
                // 모든 로드 완료 → 원격 스토리지면 서버에 캐시 저장
                this._flushDurationsToServer();
                return;
            }
            const i = idx++;
            const track = this.playlist[i];
            
            // 시작 직전 한번 더 확인: 서버 응답이 방금 도착해서 _vsDurations가 채워졌을 수 있음
            if (this._vsDurations[i]) {
                loadNext();
                return;
            }
            // 시작 직전 한번 더 확인: 서버 details에 이 파일이 있으면 이미 서버가 처리
            if (track && track.fullName && this._serverAudioDetails && this._serverAudioDetails[track.fullName]) {
                loadNext();
                return;
            }
            
            // ★ FTP는 클라이언트 개별 측정 skip (Range 지원 불안정 → 전체 파일 다운로드로 퇴보)
            //   서버 readPartial로 성공한 것만 표시, 나머지는 재생 시 자동 캐시됨
            if (this._skipClientMeasure) {
                loadNext();
                return;
            }
            
            const tmpAudio = new Audio();
            tmpAudio._trackFullName = track ? track.fullName : null; // 서버 응답 시 취소용 식별자
            tmpAudio._trackIndex = i;
            this._durLoaders.push(tmpAudio);
            tmpAudio.preload = 'metadata';
            tmpAudio.addEventListener('loadedmetadata', () => {
                if (this._destroyed) { tmpAudio.src = ''; tmpAudio.load(); return; }
                // 취소된 tmpAudio인지 확인 (서버 응답에 의해 취소됐을 수 있음)
                if (!tmpAudio.src) { loadNext(); return; }
                if (tmpAudio.duration && isFinite(tmpAudio.duration)) {
                    // 서버가 이미 더 정확한 값을 제공했는지 확인 (race condition 방지)
                    if (track && track.fullName && this._serverAudioDetails && this._serverAudioDetails[track.fullName]) {
                        // 서버 값 우선 → 클라이언트 측정값 버림
                        tmpAudio.src = '';
                        tmpAudio.load();
                        loadNext();
                        return;
                    }
                    this._vsDurations[i] = this._fmt(tmpAudio.duration);
                    // 현재 화면에 보이면 즉시 업데이트
                    const li = this.$.plList.querySelector(`.fap-pl-item[data-index="${i}"]`);
                    if (li) {
                        const durEl = li.querySelector('.fap-pl-dur');
                        if (durEl) durEl.textContent = this._vsDurations[i];
                    }
                    // 원격 스토리지 캐시용: 측정 결과를 _pendingSave에 누적 (서버 file_meta 올 때까지 보관)
                    if (track) {
                        this._pendingSave.push({
                            fullName: track.fullName,
                            duration: Math.round(tmpAudio.duration * 100) / 100,
                        });
                    }
                }
                tmpAudio.src = '';
                tmpAudio.load();
                loadNext();
            }, { once: true });
            tmpAudio.addEventListener('error', () => {
                if (this._destroyed) return;
                tmpAudio.src = '';
                tmpAudio.load();
                loadNext();
            }, { once: true });
            tmpAudio.src = this.playlist[i].url;
        };
        for (let b = 0; b < BATCH; b++) loadNext();
    }
    
    // 클라이언트가 측정한 duration을 서버에 저장 (원격 스토리지용)
    _flushDurationsToServer() {
        if (!this._pendingSave || !this._pendingSave.length) return;
        
        // _saveEndpoint가 아직 설정되지 않았으면 (서버 응답 대기 중) 최대 5초 대기
        if (!this._saveEndpoint) {
            if (!this._flushRetries) this._flushRetries = 0;
            if (this._flushRetries < 10) {
                this._flushRetries++;
                setTimeout(() => {
                    if (this._destroyed) return;
                    this._flushDurationsToServer();
                }, 500);
                return;
            }
            // 5초 대기 후에도 원격 정보 없음 → 로컬 스토리지로 판정 → 저장 건너뜀
            this._pendingSave = [];
            return;
        }
        
        if (!this._serverFileMeta) {
            this._pendingSave = [];
            return;
        }
        
        // 클라이언트 측정값을 서버 file_meta와 결합
        const items = [];
        for (const p of this._pendingSave) {
            const meta = this._serverFileMeta[p.fullName];
            if (!meta) continue; // 서버가 모르는 파일 (이미 삭제됐거나 추가된 후) 스킵
            items.push({
                path: meta.path,
                duration: p.duration,
                size: meta.size,
                mtime: meta.mtime,
            });
        }
        this._pendingSave = [];
        
        if (!items.length) return;
        
        // 서버에 일괄 저장 (fire-and-forget, CSRF는 App.api()가 자동 처리)
        if (typeof App !== 'undefined' && App.api) {
            App.api('save_audio_durations', {
                storage_id: this._saveStorageId || 0,
                items: items,
            }, 'POST').then(res => {
                if (window.FS_DEBUG_TRACKMETA) {
                    // console.log('[SaveAudioDurations]', {
                        // sent_items: items.length,
                        // response: res,
                    // });
                }
            }).catch(() => {});
        }
    }

    // ── Helpers ──
    // 현재 재생 중인 트랙 메타 정보 표시 (포맷, 비트레이트 등)
    _updateTrackMeta() {
        if (!this.$.meta) return;
        const track = this.playlist[this.currentIndex];
        if (!track) { this.$.meta.textContent = ''; return; }
        
        // 서버에서 받은 detailed 정보 조회 (track.fullName으로 직접 매칭)
        let detail = null;
        if (this._serverAudioDetails && track.fullName) {
            detail = this._serverAudioDetails[track.fullName];
        }
        
        // 디버그 로그 (콘솔에서 확인 가능)
        if (window.FS_DEBUG_TRACKMETA) {
            const keys = this._serverAudioDetails ? Object.keys(this._serverAudioDetails) : [];
            // console.log('[TrackMeta]', {
                // track_fullName: track.fullName,
                // track_name: track.name,
                // has_serverAudioDetails: !!this._serverAudioDetails,
                // totalDetailKeys: keys.length,
                // sampleKeys: keys.slice(0, 5),
                // keyExists: keys.includes(track.fullName),
                // detail_found: !!detail,
                // detail: detail,
                // has_serverFileMeta: !!this._serverFileMeta,
                // fileMeta: this._serverFileMeta ? this._serverFileMeta[track.fullName] : null,
            // });
        }
        
        // 확장자 표시
        const ext = (track.ext || '').toUpperCase() || 'AUDIO';
        const parts = [ext];
        
        if (detail) {
            // 서버에서 파싱한 정확한 정보 사용
            if (detail.sample_rate) parts.push(Math.round(detail.sample_rate / 1000) + 'kHz');
            if (detail.bitrate) parts.push(Math.round(detail.bitrate / 1000) + 'kbps');
            if (detail.channels === 1) parts.push('Mono');
            else if (detail.channels === 2) parts.push('Stereo');
            else if (detail.channels > 2) parts.push(detail.channels + 'ch');
        } else if (this._serverResponseReceived) {
            // 서버 응답은 도착했으나 이 파일의 details는 없음 → fallback bitrate 계산
            let fileSize = 0;
            if (this._serverFileMeta && track.fullName && this._serverFileMeta[track.fullName]) {
                fileSize = this._serverFileMeta[track.fullName].size || 0;
            }
            if (fileSize > 0 && this.audio && this.audio.duration && isFinite(this.audio.duration) && this.audio.duration > 0) {
                const estBitrate = Math.round((fileSize * 8) / this.audio.duration / 1000);
                if (estBitrate > 0 && estBitrate < 10000) {
                    parts.push('~' + estBitrate + 'kbps');
                }
            }
        }
        // else: 서버 응답 대기 중 → 확장자만 표시 (깜빡임 방지)
        
        this.$.meta.textContent = parts.join(' · ');
    }
    
    _fmt(s) {
        if (!s || !isFinite(s)) return '0:00';
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = Math.floor(s % 60);
        const ss = (sec < 10 ? '0' : '') + sec;
        return h > 0 ? h + ':' + (m < 10 ? '0' : '') + m + ':' + ss : m + ':' + ss;
    }
    _esc(t) {
        const d = document.createElement('div');
        d.textContent = t;
        return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ── Destroy ──
    destroy() {
        if (this._destroyed) return;
        this._destroyed = true;
        this.audio.pause();
        // 비주얼라이저 정리
        this._stopVisualizer();
        // 미디어 keepalive / Wake Lock 정리 (자동 로그아웃/화면 잠금 방지 기능)
        this._stopMediaKeepalive();
        this._releaseWakeLock();
        // 커버 테스트 이미지 정리 (메모리 누수 방지)
        if (this._coverTestImg) {
            this._coverTestImg.onload = null;
            this._coverTestImg.onerror = null;
            this._coverTestImg.src = '';
            this._coverTestImg = null;
        }
        // ★ 커버 메모리 캐시 정리 (blob URL revoke로 메모리 해제)
        //   페이지 새로고침/플레이어 종료 시 모든 blob URL 즉시 해제
        if (this._coverBlobCache) {
            this._coverBlobCache.forEach(blobUrl => {
                try { URL.revokeObjectURL(blobUrl); } catch(e) {}
            });
            this._coverBlobCache.clear();
        }
        if (this._coverBlobPending) {
            this._coverBlobPending.clear();
        }
        // ★ iOS 동시 fetch 큐 정리 (대기 중인 작업 reject)
        if (this._coverFetchQueue && this._coverFetchQueue.length > 0) {
            const _pending = this._coverFetchQueue.slice();
            this._coverFetchQueue.length = 0;
            _pending.forEach(task => {
                try { task.reject(new Error('destroyed')); } catch(e) {}
            });
        }
        // 마퀴 ResizeObserver 정리 (메모리 누수 방지)
        if (this._marqueeResizeObserver) {
            try { this._marqueeResizeObserver.disconnect(); } catch(e) {}
            this._marqueeResizeObserver = null;
        }
        if (this._marqueeResizeTimer) {
            cancelAnimationFrame(this._marqueeResizeTimer);
            this._marqueeResizeTimer = null;
        }
        // ★ 볼륨 토스트 타이머 정리 (메모리 누수 방지)
        if (this._volToastTimer) {
            clearTimeout(this._volToastTimer);
            this._volToastTimer = null;
        }
        // 스킨 선택기 document 리스너 해제 (메모리 누수 방지)
        if (this._skinMenuCloseHandler) {
            document.removeEventListener('click', this._skinMenuCloseHandler);
            this._skinMenuCloseHandler = null;
        }
        // ★ 가사 모달 드래그 document 리스너 해제 (메모리 누수 방지 v5.8.1c)
        //    _bindLyricsModalDrag에서 document에 등록한 mousemove/mouseup 정리
        if (this._lyricsModalDragHandlers) {
            try { document.removeEventListener('mousemove', this._lyricsModalDragHandlers.onMove); } catch(e) {}
            try { document.removeEventListener('mouseup', this._lyricsModalDragHandlers.onUp); } catch(e) {}
            this._lyricsModalDragHandlers = null;
        }
        this._lyricsModalDragBound = false;
        if (this._audioCtx) {
            try { this._audioCtx.close(); } catch(e) {}
            this._audioCtx = null;
            this._analyser = null;
            this._mediaSource = null;
        }
        this.audio.removeAttribute('src');
        this.audio.load();
        // 진행 중인 duration 로더 정리 (핸들러 제거 + src 클리어로 다운로드 중단)
        if (this._durLoaders && this._durLoaders.length) {
            this._durLoaders.forEach(a => {
                try { 
                    a.onloadedmetadata = null;
                    a.onerror = null;
                    // src=''+load()로 in-flight 다운로드 즉시 중단 (네트워크 낭비 방지)
                    a.removeAttribute('src');
                    a.load();
                } catch(e) {}
            });
            this._durLoaders = [];
        }
        // Media Session 정리
        if ('mediaSession' in navigator) {
            navigator.mediaSession.metadata = null;
            navigator.mediaSession.playbackState = 'none';
            try {
                ['play','pause','previoustrack','nexttrack','seekbackward','seekforward','seekto'].forEach(a => {
                    navigator.mediaSession.setActionHandler(a, null);
                });
            } catch(e) {}
        }
        // ★ MediaSession 복원 핸들러 제거 (메모리 누수 방지)
        if (this._pageshowHandler) {
            window.removeEventListener('pageshow', this._pageshowHandler);
            this._pageshowHandler = null;
        }
        if (this._mediaSessionVisHandler) {
            document.removeEventListener('visibilitychange', this._mediaSessionVisHandler);
            this._mediaSessionVisHandler = null;
        }
        this.container.innerHTML = '';
    }
}
