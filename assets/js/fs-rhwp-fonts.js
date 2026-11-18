/**
 * rhwp(한글 뷰어·편집기) 글꼴 도우미 — 설치된 글꼴 확인 + 서버 글꼴 폴더(rhwp_fonts.php) 글꼴 불러오기
 *
 * ★ (2026-11-18 21:29, 349차 펜닐 요청 "웹 폰트가 아니라 일반 폰트 인식, 특정 경로에 넣어도 인식")
 *   - installed(name): 이 기기에 그 이름의 글꼴이 있는지(안 보이는 글자 칸 폭 비교 — rhwp 편집기의 '시스템 글꼴' 확인과 같은 방식).
 *     같은 이름 웹 글꼴을 document.fonts 에 넣기 '전'에 불러야 함(넣은 뒤엔 그 웹 글꼴을 재게 됨)
 *   - ensure(names): 문서에 적힌 글꼴 이름 중 이 기기에 없고 서버 글꼴 폴더에 있는 것만 받아 등록(다 받을 때까지 기다림, 시간 제한).
 *     서버 폴더가 비어 있으면 아무것도 안 함(요청 1번으로 끝)
 *   - namesFromPlan(doc): rhwp HwpDocument.getDocumentFontSelectionPlan() 에서 문서 글꼴 이름(한글·영문 별칭) 뽑기
 */
(function () {
    'use strict';
    if (window.FsRhwpFonts) return;
    var script = document.currentScript;
    var root = (script && script.src) ? script.src.replace(/assets\/js\/fs-rhwp-fonts\.js.*$/, '') : '';
    var listPromise = null;
    var loaded = {};      // 소문자 이름 → true (이미 등록함)
    var failed = {};      // ★ (2026-11-18 22:49, 353차 펜닐 "글꼴 감지 안 해도 글꼴 폴더 설정으로 운영돼야") 받기 실패한 이름(아래 served 에서 뺌)
    var installedCache = {};
    var SAMPLE = 'mmmmmmmmmmlliWW 가나다라마바사 0123456789';

    function norm(n) { return String(n || '').trim().replace(/^['"]|['"]$/g, '').toLowerCase(); }

    // 화면에 안 보이는 글자 칸의 폭으로 비교 — 캔버스는 rhwp 편집기가 글꼴 지정(ctx.font)을 가로채 대체 글꼴로 바꿔 놓아서 모든 이름이 '있음'으로 나왔음
    //   (349차 시험에서 확인, rhwp 편집기 자신의 '시스템 글꼴' 확인도 이 방식)
    function installed(name) {
        var key = norm(name);
        if (!key) return false;
        if (key in installedCache) return installedCache[key];
        var ok = false, span = null;
        try {
            var host = document.body || document.documentElement;
            span = document.createElement('span');
            span.setAttribute('aria-hidden', 'true');
            span.style.cssText = 'position:absolute;left:-10000px;top:-10000px;visibility:hidden;white-space:nowrap;font-size:72px;font-weight:400;font-style:normal;letter-spacing:0;line-height:normal';
            span.textContent = SAMPLE;
            host.appendChild(span);
            var fam = '"' + String(name).replace(/["\\]/g, '') + '"';
            var bases = ['monospace', 'serif', 'sans-serif'];
            for (var i = 0; i < bases.length && !ok; i++) {
                span.style.fontFamily = bases[i];
                var w0 = span.offsetWidth;
                span.style.fontFamily = fam + ', ' + bases[i];
                if (span.offsetWidth !== w0) ok = true;
            }
        } catch (e) { ok = false; }
        finally { if (span && span.parentNode) span.parentNode.removeChild(span); }
        installedCache[key] = ok;
        return ok;
    }

    function list() {
        if (!listPromise) {
            listPromise = fetch(root + 'rhwp_fonts.php?action=list', { credentials: 'same-origin', cache: 'no-cache' })
                .then(function (r) { return r.ok ? r.json() : { fonts: [] }; })
                .then(function (j) { return (j && Array.isArray(j.fonts)) ? j.fonts : []; })
                .catch(function () { return []; });
        }
        return listPromise;
    }

    function namesFromPlan(doc) {
        var out = [];
        try {
            var plan = JSON.parse(doc.getDocumentFontSelectionPlan());
            (plan.requests || []).forEach(function (r) {
                (r.primary || []).forEach(function (n) { out.push(n); });
                if (r.source) out.push(r.source);
            });
        } catch (e) { /* 옛 엔진 등 — 빈 목록 */ }
        var seen = {}, uniq = [];
        out.forEach(function (n) { var k = norm(n); if (k && !seen[k]) { seen[k] = 1; uniq.push(String(n).trim()); } });
        return uniq;
    }

    /**
     * names 중 서버 글꼴 폴더에 있는 것을 등록하고 다 받을 때까지 기다림.
     * opts.skipInstalled(기본 true): 이 기기에 설치된 이름은 건너뜀(설치 글꼴 우선)
     * opts.beforeAdd(name): 등록 직전에 부름(뷰어의 기본 대체 글꼴 빼기용)
     * 돌려주는 값: 새로 등록한 이름 목록
     */
    function ensure(names, opts) {
        opts = opts || {};
        var timeoutMs = opts.timeoutMs || 20000;
        return list().then(function (fonts) {
            if (!fonts.length || !names || !names.length) return [];
            var byName = {};
            fonts.forEach(function (f) { (f.names || []).forEach(function (n) { var k = norm(n); (byName[k] = byName[k] || []).push(f); }); });
            var jobs = [], added = [];
            names.forEach(function (n) {
                var k = norm(n);
                if (!k || loaded[k] || !byName[k]) return;
                if (opts.skipInstalled !== false && installed(n)) return;
                loaded[k] = true;
                if (opts.beforeAdd) { try { opts.beforeAdd(n); } catch (e) {} }
                added.push(n);
                var mine = [];   // ★ (2026-11-18 23:44, 355차 점검) 이 이름의 글꼴 파일 중 하나라도 받으면 '쓸 수 있음' — 종전(353차)은 하나만 실패해도 served 에서 뺐음(같은 이름 깨진 파일이 섞이면 재현)
                byName[k].forEach(function (f) {
                    try {
                        var fam = String(n).replace(/["\\]/g, '');
                        var face = new FontFace(fam, 'url("' + root + 'rhwp_fonts.php?action=font&id=' + encodeURIComponent(f.id) + '") format("' + f.format + '")',
                            { weight: String(f.weight || 400), style: f.style === 'italic' ? 'italic' : 'normal' });
                        document.fonts.add(face);
                        var job = face.load().then(function () { return 1; }, function () { try { document.fonts.delete(face); } catch (e) {} return 0; });
                        jobs.push(job); mine.push(job);
                    } catch (e) { /* 잘못된 글꼴은 건너뜀 */ }
                });
                if (mine.length) Promise.all(mine).then(function (r) { if (!r.some(Boolean)) failed[k] = true; });
                else failed[k] = true;
            });
            if (!jobs.length) return added;
            return Promise.race([
                Promise.all(jobs),
                new Promise(function (res) { setTimeout(res, timeoutMs); })
            ]).then(function () { return added; });
        }).catch(function () { return []; });
    }

    /** ★ (2026-11-18 22:49, 353차 펜닐 "글꼴 감지 안 해도 글꼴 폴더 설정으로 운영돼야")
     *  names 중 '서버 글꼴 폴더 덕분에 이 브라우저에서 쓸 수 있는' 이름 — 이번에 받아 등록한 것 + 서버에도 있고 이 PC 에도 설치된 것(설치 글꼴이 쓰임).
     *  편집기가 rhwp 에 '이 글꼴은 있음'으로 알려 줄 목록(rhwp_editor.php fsRhwpSeedServerFonts) */
    function served(names) {
        return list().then(function (fonts) {
            var has = {};
            fonts.forEach(function (f) { (f.names || []).forEach(function (n) { has[norm(n)] = true; }); });
            var out = [], seen = {};
            (names || []).forEach(function (n) {
                var k = norm(n);
                if (!k || seen[k] || failed[k] || !has[k]) return;
                if (loaded[k] || installed(n)) { seen[k] = 1; out.push(String(n).trim()); }
            });
            return out;
        }).catch(function () { return []; });
    }

    // ★ (2026-11-18 21:53, 350차) 관리자가 글꼴 폴더 설정을 바꾸면 목록을 다시 받게(이미 등록한 글꼴은 그대로)
    function reset() { listPromise = null; }
    window.FsRhwpFonts = { installed: installed, list: list, ensure: ensure, served: served, namesFromPlan: namesFromPlan, reset: reset, root: root };
})();
