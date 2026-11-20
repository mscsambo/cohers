/*
 * COHERS Mini App: reports collected without internet.
 *
 * A report written while offline (or when sending fails on a bad connection) is saved in
 * this phone's IndexedDB with its photos, and sent automatically when the phone is back
 * online: on page load, on the "online" event, every minute while the app is open, or with
 * "Send now". Each report keeps the idempotency key it was given when it was written, so a
 * retry after a half-finished upload never creates a second report on the server.
 *
 * Saved reports belong to the COHERS account that wrote them: if someone else signs in on
 * the same phone they are neither shown nor sent under the other account.
 *
 * Configuration comes from window.COHERS_OFFLINE (set in layouts/miniapp.blade.php).
 */
(function () {
    'use strict';
    var cfg = window.COHERS_OFFLINE || {};
    var DB_NAME = 'cohers-offline', STORE = 'reports';
    var listeners = [];
    var syncing = null, again = false;
    var lastState = { pending: 0, failed: 0, needsSignIn: false };

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        var b = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(b);
        b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
        var h = Array.prototype.map.call(b, function (x) { return (x + 0x100).toString(16).slice(1); }).join('');
        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }

    function openDb() {
        return new Promise(function (resolve, reject) {
            if (!window.indexedDB) { reject(new Error('no-indexeddb')); return; }
            var req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = function () {
                var store = req.result.createObjectStore(STORE, { keyPath: 'key' });
                store.createIndex('user', 'user');
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
    }

    function tx(mode, fn) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var t = db.transaction(STORE, mode);
                var result = fn(t.objectStore(STORE));
                t.oncomplete = function () { db.close(); resolve(result && 'result' in result ? result.result : result); };
                t.onerror = t.onabort = function () { db.close(); reject(t.error); };
            });
        });
    }

    function all() {
        return tx('readonly', function (s) { return s.getAll(); }).then(function (rows) {
            return (rows || []).filter(function (r) { return String(r.user) === String(cfg.user); })
                .sort(function (a, b) { return a.savedAt < b.savedAt ? -1 : 1; });
        });
    }

    function put(row) { return tx('readwrite', function (s) { return s.put(row); }); }
    function remove(key) { return tx('readwrite', function (s) { return s.delete(key); }).then(refresh); }

    /**
     * Saves one report. `fields` are the form fields (strings), `photos` an array of Blobs,
     * `label` the place name shown in the list. Photos are stored as ArrayBuffers, which
     * every WebView's IndexedDB accepts (some older iOS versions reject Blobs).
     */
    function save(fields, photos, label) {
        return Promise.all((photos || []).map(function (b) {
            return (b.arrayBuffer ? b.arrayBuffer() : new Response(b).arrayBuffer()).then(function (buf) {
                return { data: buf, type: b.type || 'image/jpeg' };
            });
        })).then(function (stored) {
            var row = {
                key: fields.idempotency_key || uuid(),
                user: String(cfg.user),
                fields: fields,
                photos: stored,
                label: label || '',
                savedAt: new Date().toISOString(),
                status: 'pending',
                error: null,
            };
            row.fields.idempotency_key = row.key;
            row.fields.captured_at = row.fields.captured_at || row.savedAt;
            return put(row).then(function () { return refresh(); }).then(function () { return row; });
        });
    }

    function emit(state) {
        lastState = state;
        listeners.forEach(function (fn) { try { fn(state); } catch (e) {} });
    }

    function refresh(extra) {
        return all().then(function (rows) {
            var state = {
                rows: rows,
                pending: rows.filter(function (r) { return r.status === 'pending'; }).length,
                failed: rows.filter(function (r) { return r.status === 'failed'; }).length,
                needsSignIn: !!(extra && extra.needsSignIn),
                sent: (extra && extra.sent) || 0,
                lastSent: extra && extra.lastSent,
            };
            emit(state);
            return state;
        }).catch(function () { return lastState; });
    }

    /** A fresh CSRF token for the signed-in person, and who that is. */
    function session() {
        return fetch(cfg.tokenUrl, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
            .then(function (r) {
                if (r.status === 401 || r.status === 419 || r.redirected) return { signedOut: true };
                if (!r.ok) throw new Error('http ' + r.status);
                return r.json();
            });
    }

    function send(row, token) {
        var fd = new FormData();
        Object.keys(row.fields).forEach(function (k) {
            var v = row.fields[k];
            if (v !== null && v !== undefined && v !== '') fd.append(k, v);
        });
        row.photos.forEach(function (p, i) {
            fd.append('photos[]', new Blob([p.data], { type: p.type }), 'photo' + (i + 1) + '.jpg');
        });
        return fetch(cfg.storeUrl, {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
        });
    }

    /** Sends every pending report of this account. Safe to call often: only one run at a time. */
    function sync() {
        // Already sending: run once more afterwards, so a report saved meanwhile is not left waiting.
        if (syncing) { again = true; return syncing.then(function () { return again ? (again = false, sync()) : lastState; }); }
        if (navigator.onLine === false) return refresh();
        syncing = all().then(function (rows) {
            var queue = rows.filter(function (r) { return r.status === 'pending'; });
            if (!queue.length) return refresh();
            return session().then(function (s) {
                if (s.signedOut) return refresh({ needsSignIn: true });
                if (String(s.user) !== String(cfg.user)) return refresh();   // another account is signed in
                var token = s.token, sent = 0, lastSent = null;
                var next = function (i, retried) {
                    if (i >= queue.length) return refresh({ sent: sent, lastSent: lastSent });
                    var row = queue[i];
                    return send(row, token).then(function (res) {
                        return res.json().catch(function () { return {}; }).then(function (json) {
                            if (res.ok) {
                                sent++; lastSent = json.redirect || null;
                                return tx('readwrite', function (st) { return st.delete(row.key); }).then(function () { return next(i + 1); });
                            }
                            if (res.status === 419 && !retried) {
                                return session().then(function (s2) { token = s2.token; return next(i, true); });
                            }
                            if (res.status === 401 || res.status === 419) return refresh({ needsSignIn: true, sent: sent });
                            if (res.status === 422) {
                                // The server will never accept it as it is: keep it, show why, let the person delete it.
                                row.status = 'failed';
                                row.error = json.errors ? Object.values(json.errors)[0][0] : (json.message || 'Error');
                                return put(row).then(function () { return next(i + 1); });
                            }
                            // 429 (too many at once), 5xx: stop and try again later.
                            return refresh({ sent: sent, lastSent: lastSent });
                        });
                    });
                };
                return next(0);
            });
        }).catch(function () { return refresh(); }).then(function (state) {
            syncing = null;
            return state;
        });
        return syncing;
    }

    function onChange(fn) { listeners.push(fn); fn(lastState); }

    window.CohersQueue = { save: save, all: all, remove: remove, sync: sync, refresh: refresh, onChange: onChange, uuid: uuid };

    // Send automatically whenever there is a chance.
    window.addEventListener('online', function () { sync(); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) sync(); });
    setInterval(function () { if (lastState.pending) sync(); }, 60000);
    sync();
})();
