// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upload dialogue for the Large file repository.
 *
 * Subscribes to the file picker's upload event, opens a dialogue offering a
 * chunked upload from the user's computer or a server-side fetch from a URL, and
 * refreshes the picker listing once a file has been staged. The chunk loop
 * retries transient failures with exponential backoff and reconciles its resume
 * position with the server, so a large upload survives a dropped chunk. If the
 * page is left mid-upload, the server keeps the partial file and this module
 * remembers it (in localStorage), so re-selecting the same file on return carries
 * on from where it stopped instead of starting over. Where the browser supports it
 * (Chrome/Edge, secure context), an optional Background Fetch mode uploads the file
 * through a service worker so it continues even after the tab is closed; those
 * chunks arrive out of order and the server reassembles them.
 *
 * @module     repository_largefile/upload
 * @copyright  2026 SCCA
 * @copyright  2020 Justus Dieckmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {subscribe} from 'core/pubsub';
import SaveCancelModal from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {getString} from 'core/str';
import Notification from 'core/notification';
import * as config from 'core/config';

/** @constant {string} Upload endpoint, relative to wwwroot. */
const ENDPOINT = '/repository/largefile/upload_ajax.php';

/** @constant {number} How many times to retry a chunk after a transient failure. */
const MAX_RETRIES = 5;

/** @constant {number} Base backoff between retries in ms; doubles each attempt up to the cap. */
const BACKOFF_BASE_MS = 1000;

/** @constant {number} Upper bound on a single backoff wait in ms. */
const BACKOFF_CAP_MS = 16000;

/** @constant {number} Server-side "upload completed" state (mirrors chunk_store). */
const STATE_COMPLETED = 2;

/** @constant {string} localStorage key holding per-context resume records. */
const RESUME_KEY = 'repository_largefile_resume';

/**
 * @constant {number} Discard a resume record older than this. Kept to one hour to
 * match the server's default retention of an unfinished upload (the
 * state1duration setting, default 3600s); the server is the authority — a resume
 * is only ever offered after the token is confirmed to still exist — so this just
 * avoids advertising a record whose partial file the cleanup task has removed.
 */
const RESUME_TTL_MS = 60 * 60 * 1000;

/**
 * @constant {boolean} Whether this browser can continue an upload after the tab is
 * closed: a secure context with a service worker and the Background Fetch API
 * (Chrome/Edge). Elsewhere the upload is foreground-only (with resume).
 */
const BG_SUPPORTED = typeof window !== 'undefined' && window.isSecureContext
    && 'serviceWorker' in navigator && 'BackgroundFetchManager' in window;

/** @var {boolean} Whether the pubsub listener has been registered. */
let listenersRegistered = false;

/** @var {Promise|null} Memoised service-worker registration for Background Fetch. */
let swRegistrationPromise = null;

/** @var {boolean} Whether the module-level service-worker message listener is set. */
let swMessageListenerRegistered = false;

/** @var {Array} Picker-refresh callbacks to run when a background upload completes. */
const bgCompletionCallbacks = [];

/**
 * Register (once, at module scope so it outlives any dialogue) a listener for the
 * service worker's background-fetch completion message, refreshing every picker
 * that handed off a background upload. The per-dialogue listener would be gone by
 * the time the upload finishes, so completion is handled here instead.
 *
 * @return {void}
 */
const ensureSwMessageListener = () => {
    if (swMessageListenerRegistered || !BG_SUPPORTED || !navigator.serviceWorker) {
        return;
    }
    swMessageListenerRegistered = true;
    navigator.serviceWorker.addEventListener('message', (e) => {
        if (e.data && e.data.type === 'repository_largefile_bgcomplete') {
            bgCompletionCallbacks.forEach((callback) => callback());
        }
    });
};

/**
 * Register (once) the plugin's service worker, which owns Background Fetch uploads
 * so they continue after the page closes. Its scope is the plugin directory, which
 * covers the upload endpoint.
 *
 * @return {Promise} Resolves with the ServiceWorkerRegistration.
 */
const getServiceWorker = () => {
    if (swRegistrationPromise === null) {
        const scope = config.wwwroot + '/repository/largefile/';
        swRegistrationPromise = navigator.serviceWorker.register(scope + 'sw.js', {scope})
            .then((registration) => waitForActive(registration).then(() => registration));
    }
    return swRegistrationPromise;
};

/**
 * Resolve once a service-worker registration has an active worker. This does not
 * use navigator.serviceWorker.ready, which waits for the worker that controls the
 * *current page* — our worker is scoped to the plugin directory and does not
 * control the file-picker page it is registered from, so `ready` would never
 * resolve there.
 *
 * @param {ServiceWorkerRegistration} registration The registration to await.
 * @return {Promise} Resolves when the registration is active.
 */
const waitForActive = (registration) => {
    if (registration.active) {
        return Promise.resolve();
    }
    const worker = registration.installing || registration.waiting;
    if (!worker) {
        return Promise.resolve();
    }
    return new Promise((resolve) => {
        worker.addEventListener('statechange', () => {
            if (worker.state === 'activated') {
                resolve();
            }
        });
    });
};

/**
 * Hand a local file to the browser's Background Fetch so its chunks keep uploading
 * even after this page is closed. Each chunk is an independent POST to bgchunk that
 * the server writes at its byte offset; the server marks the upload complete once
 * every byte has arrived, so the chunks may run in any order.
 *
 * @param {File} file The file to upload.
 * @param {object} token The {id, chunksize, maxbytes} allocated for it.
 * @return {Promise} Resolves once the background fetch is registered.
 */
const startBackgroundUpload = async(file, token) => {
    // Record the total length and file name up front — chunks arrive out of order,
    // possibly after the page has gone, so the accepted-type policy is enforced now.
    const started = await postRequest(
        {action: 'bgstart', id: token.id, length: file.size, filename: file.name}, null, null, null, null);
    const startedResponse = parseJson(started.text);
    if (started.status !== 200 || startedResponse === null || startedResponse.error !== undefined) {
        throw new Error(startedResponse && startedResponse.error
            ? startedResponse.error : await getString('erroruploadfailed', 'repository_largefile'));
    }
    const registration = await getServiceWorker();
    const chunkSize = token.chunksize;
    const requests = [];
    for (let start = 0; start < file.size; start += chunkSize) {
        const end = Math.min(start + chunkSize, file.size);
        const qs = 'action=bgchunk&id=' + encodeURIComponent(token.id)
            + '&start=' + start + '&end=' + end + '&sesskey=' + encodeURIComponent(config.sesskey);
        requests.push(new Request(config.wwwroot + ENDPOINT + '?' + qs, {
            method: 'post',
            body: file.slice(start, end),
            headers: {'Content-Type': 'application/octet-stream'},
        }));
    }
    // These requests upload the file slices (Background Fetch derives uploadTotal
    // from their bodies); the responses are only small JSON, so downloadTotal is
    // left at its default — declaring the file size there would make the browser
    // expect a whole file back and can fail the fetch with download-total-exceeded.
    await registration.backgroundFetch.fetch('repository_largefile-' + token.id, requests, {
        title: file.name,
    });
};

/**
 * Read the whole resume map from localStorage, tolerating storage being
 * unavailable or corrupt (private-browsing, cleared site data, quota).
 *
 * @return {object} Map of contextId to a resume record.
 */
const readResumeMap = () => {
    try {
        return JSON.parse(window.localStorage.getItem(RESUME_KEY) || '{}') || {};
    } catch (e) {
        return {};
    }
};

/**
 * Store (or, when rec is null, clear) the resume record for a context, pruning
 * expired entries. Any storage failure is ignored — resume is a convenience.
 *
 * @param {number} contextId The context id the upload belongs to.
 * @param {object|null} rec The record to store, or null to remove it.
 * @return {void}
 */
const writeResume = (contextId, rec) => {
    try {
        const map = readResumeMap();
        const now = Date.now();
        Object.keys(map).forEach((key) => {
            if (!map[key] || (now - (map[key].updated || 0)) > RESUME_TTL_MS) {
                delete map[key];
            }
        });
        if (rec) {
            map[contextId] = Object.assign({}, rec, {updated: now});
        } else {
            delete map[contextId];
        }
        window.localStorage.setItem(RESUME_KEY, JSON.stringify(map));
    } catch (e) {
        // Storage unavailable; resume-across-navigation simply is not offered.
    }
};

/**
 * The stored resume record for a context, or null when there is none or it has
 * aged out.
 *
 * @param {number} contextId The context id.
 * @return {object|null} The record, or null.
 */
const readResume = (contextId) => {
    const rec = readResumeMap()[contextId];
    if (!rec || (Date.now() - (rec.updated || 0)) > RESUME_TTL_MS) {
        return null;
    }
    return rec;
};

/**
 * A cheap content fingerprint of a file, so a resume only continues the *same*
 * file rather than any file that happens to share a name and byte length. It
 * hashes the first 64 KB (SHA-256 where the secure context provides SubtleCrypto)
 * and falls back to the last-modified time when it does not.
 *
 * @param {File} file The file to fingerprint.
 * @return {Promise} Resolves with a short opaque string.
 */
const fingerprintFile = async(file) => {
    const head = file.slice(0, Math.min(65536, file.size));
    if (window.crypto && window.crypto.subtle && window.isSecureContext) {
        try {
            const digest = await window.crypto.subtle.digest('SHA-256', await head.arrayBuffer());
            return 'sha256:' + Array.from(new Uint8Array(digest))
                .map((b) => b.toString(16).padStart(2, '0')).join('');
        } catch (e) {
            // Fall back to a coarser fingerprint below.
        }
    }
    return 'lm:' + (file.lastModified || 0) + ':' + file.size;
};

/**
 * POST one request to the endpoint. Resolves with the HTTP status and response
 * text; a network-level failure or an abort resolves with status 0 (treated as
 * transient by the retry path).
 *
 * The sensitive fields (a signed source URL) are sent in the request body, never
 * the query string, so they are not written to web-server/proxy access logs.
 *
 * @param {object} params Query-string parameters (action, id, ...); sesskey is added.
 * @param {Blob|string|null} body Request body, or null.
 * @param {string|null} contentType Content-Type for the body, or null for none.
 * @param {function|null} onprogress Optional callback given the bytes uploaded so far.
 * @param {object|null} controller Optional {cancelled, xhr} used to abort in-flight requests.
 * @return {Promise} Resolves with {status, text}.
 */
const postRequest = (params, body, contentType, onprogress, controller) => {
    return new Promise((resolve) => {
        if (controller && controller.cancelled) {
            resolve({status: 0, text: ''});
            return;
        }
        const query = Object.assign({sesskey: config.sesskey}, params);
        const qs = Object.keys(query).map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(query[k])).join('&');
        const xhr = new XMLHttpRequest();
        xhr.open('post', config.wwwroot + ENDPOINT + '?' + qs);
        if (controller) {
            controller.xhr = xhr;
        }
        if (onprogress && xhr.upload) {
            xhr.upload.onprogress = (e) => onprogress(e.loaded);
        }
        xhr.onreadystatechange = () => {
            if (xhr.readyState === 4) {
                resolve({status: xhr.status, text: xhr.responseText});
            }
        };
        xhr.onerror = () => resolve({status: 0, text: ''});
        xhr.onabort = () => resolve({status: 0, text: ''});
        if (contentType) {
            xhr.setRequestHeader('Content-Type', contentType);
        }
        xhr.send(body);
    });
};

/**
 * Parse a JSON response, returning null when it cannot be parsed.
 *
 * @param {string} text The response text.
 * @return {object|null} The parsed object, or null.
 */
const parseJson = (text) => {
    try {
        return JSON.parse(text);
    } catch (e) {
        return null;
    }
};

/**
 * Resolve after the given number of milliseconds.
 *
 * @param {number} ms Milliseconds to wait.
 * @return {Promise} Resolves once the delay elapses.
 */
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Whether an HTTP status is a terminal client-side rejection. 408 and 429 are
 * transient and handled by the retry path; other 4xx responses are terminal.
 *
 * @param {number} status The HTTP status code.
 * @return {boolean} True if the upload should abort on this status.
 */
const isTerminal = (status) => status >= 400 && status < 500 && status !== 408 && status !== 429;

/**
 * Allocate a new upload token for the current context.
 *
 * @param {number} contextId The context id.
 * @param {object} controller The {cancelled, xhr} abort controller.
 * @return {Promise} Resolves with {id, maxbytes, chunksize}, or rejects with a message.
 */
const newToken = async(contextId, controller) => {
    const result = await postRequest({action: 'newtoken', contextid: contextId}, null, null, null, controller);
    const response = parseJson(result.text);
    if (result.status !== 200 || response === null || response.error !== undefined || !response.id) {
        throw new Error(response && response.error ? response.error : await getString('erroruploadfailed', 'repository_largefile'));
    }
    return response;
};

/**
 * Ask the server how many bytes of this upload it has stored, so a retry after a
 * lost response resumes from the true position.
 *
 * @param {string} token The upload token id.
 * @param {object} controller The {cancelled, xhr} abort controller.
 * @return {Promise} Resolves with {state, currentpos, length}, or null if unknown.
 */
const queryStatus = async(token, controller) => {
    const result = await postRequest({action: 'status', id: token}, null, null, null, controller);
    if (result.status !== 200) {
        return null;
    }
    const snap = parseJson(result.text);
    if (snap === null || snap.error !== undefined || snap.currentpos === undefined) {
        return null;
    }
    return snap;
};

/**
 * Upload one file chunk by chunk. A chunk is retried through transient network or
 * 5xx failures with exponential backoff, reconciling the resume position with the
 * server after each failure. A 4xx response or explicit server error is terminal.
 * The loop stops as soon as the controller is cancelled.
 *
 * @param {File} file The file to upload.
 * @param {string} token The upload token id.
 * @param {number} chunkSize The chunk size in bytes.
 * @param {function} onProgress Callback given (bytesConfirmed, total).
 * @param {object} controller The {cancelled, xhr} abort controller.
 * @param {number} resumeFrom Byte offset already stored server-side (for a resumed upload).
 * @return {Promise} Resolves true on success, false if cancelled, or rejects with a message.
 */
const uploadFileChunked = async(file, token, chunkSize, onProgress, controller, resumeFrom = 0) => {
    let confirmed = resumeFrom;
    let retries = 0;
    // A resumed upload already has its first chunk stored, so every remaining
    // chunk uses "proceed"; only a fresh upload (offset 0) sends "start".
    let started = resumeFrom > 0;
    onProgress(confirmed, file.size);
    while (confirmed < file.size) {
        if (controller.cancelled) {
            return false;
        }
        const start = confirmed;
        const end = Math.min(start + chunkSize, file.size);
        const params = start === 0
            ? {action: 'start', start: start, end: end, length: file.size, filename: file.name, id: token}
            : {action: 'proceed', start: start, end: end, id: token};
        const slice = file.slice(start, end);
        const result = await postRequest(params, slice, 'application/octet-stream',
            (loaded) => onProgress(start + loaded, file.size), controller);

        if (controller.cancelled) {
            return false;
        }
        if (result.status === 200) {
            const response = parseJson(result.text);
            if (response !== null && response.error !== undefined) {
                throw new Error(response.error);
            }
            if (response !== null) {
                confirmed = end;
                started = true;
                retries = 0;
                onProgress(confirmed, file.size);
                continue;
            }
        } else if (isTerminal(result.status)) {
            const key = result.status === 413 ? 'errorchunktoolarge' : 'erroruploadfailed';
            throw new Error(await getString(key, 'repository_largefile'));
        }

        // Transient failure: back off, reconcile with the server, then retry.
        if (retries >= MAX_RETRIES) {
            throw new Error(await getString('erroruploadfailed', 'repository_largefile'));
        }
        retries++;
        await sleep(Math.min(BACKOFF_BASE_MS * Math.pow(2, retries - 1), BACKOFF_CAP_MS));
        if (started) {
            const snap = await queryStatus(token, controller);
            if (snap !== null && snap.length === file.size) {
                const advanced = snap.currentpos > confirmed;
                confirmed = (snap.state === STATE_COMPLETED || snap.currentpos >= file.size) ? file.size : snap.currentpos;
                if (advanced) {
                    retries = 0;
                }
                onProgress(confirmed, file.size);
            }
        }
    }
    return true;
};

/**
 * Fetch a remote URL server-side into the token. The URL is sent in the POST body
 * so a signed link's credentials are not exposed in request logs.
 *
 * @param {string} url The URL to fetch.
 * @param {string} token The upload token id.
 * @param {object} controller The {cancelled, xhr} abort controller.
 * @return {Promise} Resolves true on success, false if cancelled, or rejects with a message.
 */
const fetchUrl = async(url, token, controller) => {
    const body = 'url=' + encodeURIComponent(url);
    const result = await postRequest({action: 'fetchurl', id: token}, body,
        'application/x-www-form-urlencoded', null, controller);
    if (controller.cancelled) {
        return false;
    }
    const response = parseJson(result.text);
    if (result.status !== 200 || response === null || response.error !== undefined) {
        throw new Error(response && response.error ? response.error : await getString('errordownloadfailed', 'repository_largefile'));
    }
    return true;
};

/**
 * Open the upload dialogue.
 *
 * @param {object} data The pubsub payload: {repoId, contextId, callback}.
 * @return {Promise} Resolves once the modal is created.
 */
const openUploadModal = async(data) => {
    const body = await Templates.render('repository_largefile/upload_dialogue', {});
    const modal = await SaveCancelModal.create({
        title: getString('pluginname', 'repository_largefile'),
        body: body,
        large: true,
        buttons: {save: getString('addfile', 'repository_largefile')},
    });

    const root = modal.getRoot();
    // Shared abort state: cancelling the modal flips `cancelled`, aborts any
    // in-flight request, and drops the server-side token so a closed dialogue
    // does not keep staging the file or fire the completion callback behind the
    // user's back.
    const controller = {cancelled: false, xhr: null, token: null};
    let selectedFile = null;
    let busy = false;
    // An unfinished upload from a previous visit (same browser), offered for resume
    // once the user re-selects the matching file. resumeActive flips true when the
    // selected file matches it.
    let pendingResume = null;
    let resumeActive = false;
    // Set once a file has been handed to Background Fetch: the browser now owns the
    // upload, so closing the dialogue must not delete its token.
    let backgroundHandedOff = false;

    const el = (selector) => root.find(selector).get(0);
    const setStatus = (text) => {
        const status = el('[data-region="status"]');
        if (status) {
            status.textContent = text;
        }
    };
    const showResume = (text) => {
        const region = el('[data-region="resume"]');
        if (region) {
            region.textContent = text;
            region.hidden = false;
        }
    };
    const hideResume = () => {
        const region = el('[data-region="resume"]');
        if (region) {
            region.hidden = true;
        }
    };
    const setProgress = (loaded, total) => {
        const pct = total > 0 ? Math.round(loaded * 100 / total) : 0;
        const bar = el('[data-region="progressbar"]');
        if (bar) {
            bar.style.width = pct + '%';
            bar.setAttribute('aria-valuenow', pct);
        }
        const label = el('[data-region="progresspct"]');
        if (label) {
            label.textContent = pct + '%';
        }
    };
    const abort = () => {
        // A background-fetch upload keeps running on its own; never tear it down.
        if (backgroundHandedOff) {
            return;
        }
        controller.cancelled = true;
        if (controller.xhr) {
            controller.xhr.abort();
        }
        // Drop the server-side token so a closed dialogue leaves no orphaned partial
        // file — whether the upload had started (controller.token) or only a resume
        // was offered and then abandoned (pendingResume). Plain navigation does not
        // call abort, so an upload left that way stays resumable. Pass no controller
        // so the request is not short-circuited by the cancelled flag.
        const droptoken = controller.token || (pendingResume && pendingResume.token);
        if (droptoken) {
            postRequest({action: 'delete', id: droptoken}, null, null, null, null);
            controller.token = null;
            pendingResume = null;
        }
        writeResume(data.contextId, null);
    };

    /**
     * Offer to resume an unfinished upload left in localStorage from a previous
     * visit. The record is only cleared when the server *definitively* reports the
     * token gone or finished; a transient status failure (network, 5xx) leaves it
     * in place so a later open can still resume.
     *
     * @return {Promise} Resolves once the banner is shown or dismissed.
     */
    const maybeOfferResume = async() => {
        pendingResume = null;
        const rec = readResume(data.contextId);
        if (!rec) {
            hideResume();
            return;
        }
        const result = await postRequest({action: 'status', id: rec.token}, null, null, null, controller);
        if (result.status !== 200) {
            // Transient failure: keep the record, just do not offer it this time.
            hideResume();
            return;
        }
        const snap = parseJson(result.text);
        if (snap === null || snap.error !== undefined || snap.currentpos === undefined) {
            // The server has no such token: it is gone or already finished.
            writeResume(data.contextId, null);
            hideResume();
            return;
        }
        // Only trust the stored offset once the server has recorded a length that
        // matches this record's file; a not-yet-written token (length 0) resumes
        // from the start, and a non-zero length that differs is a different upload.
        if (snap.length && snap.length !== rec.size) {
            writeResume(data.contextId, null);
            hideResume();
            return;
        }
        const currentpos = snap.length === rec.size ? snap.currentpos : 0;
        if (snap.state === STATE_COMPLETED || currentpos >= rec.size) {
            writeResume(data.contextId, null);
            hideResume();
            return;
        }
        pendingResume = {
            token: rec.token,
            filename: rec.filename,
            size: rec.size,
            chunksize: rec.chunksize,
            currentpos: currentpos,
            fingerprint: rec.fingerprint,
        };
        const percent = rec.size > 0 ? Math.round(currentpos * 100 / rec.size) : 0;
        showResume(await getString('resumeprompt', 'repository_largefile',
            {filename: rec.filename, percent: percent}));
    };

    // A file on the user's computer is read by this page, so leaving it pauses a
    // running chunked upload (the bytes stop flowing). Warn before navigating away
    // while one is in flight — but the progress is kept, so returning and picking
    // the same file resumes it. (For a truly unattended transfer, use a URL or the
    // server-side scheduled transfers on the Transfers page.)
    const beforeUnload = (e) => {
        if (busy) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
        return undefined;
    };
    window.addEventListener('beforeunload', beforeUnload);

    root.on(ModalEvents.shown, async() => {
        selectedFile = null;
        resumeActive = false;
        busy = false;
        setProgress(0, 1);
        // Offer "keep uploading after I close this page" only where the browser can.
        if (BG_SUPPORTED) {
            const bgwrap = el('[data-region="bgwrap"]');
            if (bgwrap) {
                bgwrap.hidden = false;
            }
        }
        const input = el('[data-region="fileinput"]');
        if (input) {
            input.addEventListener('change', async() => {
                selectedFile = input.files.length ? input.files[0] : null;
                resumeActive = false;
                // Only continue a resume when the re-selected file is genuinely the
                // same one — same name, size and content fingerprint — so two
                // different files that share a name and length are never spliced.
                if (pendingResume && selectedFile
                        && selectedFile.name === pendingResume.filename
                        && selectedFile.size === pendingResume.size
                        && await fingerprintFile(selectedFile) === pendingResume.fingerprint) {
                    resumeActive = true;
                    setStatus(await getString('resumeready', 'repository_largefile', selectedFile.name));
                } else {
                    hideResume();
                    setStatus(selectedFile ? selectedFile.name : '');
                }
            });
        }
        await maybeOfferResume();
    });

    // Cancelling or closing the dialogue aborts any in-flight transfer.
    root.on(ModalEvents.cancel, abort);
    root.on(ModalEvents.hidden, () => {
        window.removeEventListener('beforeunload', beforeUnload);
        abort();
        modal.destroy();
    });

    root.on(ModalEvents.save, async(e) => {
        e.preventDefault();
        if (busy) {
            return;
        }
        // The active tab decides which source we commit.
        const urlTabActive = root.find('[data-region="tab-url"]').hasClass('active');
        try {
            busy = true;
            let staged;
            if (urlTabActive) {
                const urlInput = el('[data-region="urlinput"]');
                const url = urlInput ? urlInput.value.trim() : '';
                if (!url) {
                    busy = false;
                    return;
                }
                setStatus(await getString('uploading', 'repository_largefile'));
                const token = await newToken(data.contextId, controller);
                controller.token = token.id;
                staged = await fetchUrl(url, token.id, controller);
            } else {
                if (!selectedFile) {
                    busy = false;
                    return;
                }
                if (selectedFile.size === 0) {
                    // The chunk loop would send nothing for a zero-byte file and
                    // report a false success, so reject it up front.
                    throw new Error(await getString('erroremptyfile', 'repository_largefile'));
                }
                let tokenId;
                let chunkSize;
                let resumeFrom = 0;
                const resuming = resumeActive && pendingResume
                    && selectedFile.name === pendingResume.filename
                    && selectedFile.size === pendingResume.size;
                const bgCheck = el('[data-region="bgcheck"]');
                if (BG_SUPPORTED && bgCheck && bgCheck.checked && !resuming) {
                    // Hand the upload to Background Fetch: it keeps running after the
                    // page closes, so this dialogue's job is done once it is registered.
                    const bgtoken = await newToken(data.contextId, controller);
                    if (bgtoken.maxbytes > 0 && selectedFile.size > bgtoken.maxbytes) {
                        throw new Error(await getString('errordownloadtoobig', 'repository_largefile'));
                    }
                    // Track the token from now, so if service-worker registration or the
                    // fetch handoff fails (or the user cancels during setup) the catch or
                    // abort deletes its pre-sized file rather than orphaning it.
                    controller.token = bgtoken.id;
                    setStatus(await getString('bgstarting', 'repository_largefile'));
                    await startBackgroundUpload(selectedFile, bgtoken);
                    // Handoff succeeded: the browser owns the upload now. Refresh the
                    // picker when it completes (a module-level listener, since this
                    // dialogue will be gone by then).
                    ensureSwMessageListener();
                    bgCompletionCallbacks.push(data.callback);
                    backgroundHandedOff = true;
                    controller.token = null;
                    writeResume(data.contextId, null);
                    modal.hide();
                    const notice = await Promise.all([
                        getString('pluginname', 'repository_largefile'),
                        getString('bgstarted', 'repository_largefile'),
                        getString('ok', 'core'),
                    ]);
                    Notification.alert(notice[0], notice[1], notice[2]);
                    return;
                }
                if (resuming) {
                    // Carry on with the existing server-side token from where it stopped.
                    tokenId = pendingResume.token;
                    chunkSize = pendingResume.chunksize;
                    resumeFrom = pendingResume.currentpos;
                } else {
                    const token = await newToken(data.contextId, controller);
                    if (token.maxbytes > 0 && selectedFile.size > token.maxbytes) {
                        throw new Error(await getString('errordownloadtoobig', 'repository_largefile'));
                    }
                    tokenId = token.id;
                    chunkSize = token.chunksize;
                }
                controller.token = tokenId;
                // Remember this upload so it can be resumed if the page is left before
                // it finishes (the server keeps the partial file and its position); the
                // fingerprint lets a resume confirm the re-selected file is the same one.
                const fingerprint = resuming ? pendingResume.fingerprint : await fingerprintFile(selectedFile);
                writeResume(data.contextId, {
                    token: tokenId,
                    filename: selectedFile.name,
                    size: selectedFile.size,
                    chunksize: chunkSize,
                    fingerprint: fingerprint,
                });
                hideResume();
                setStatus(await getString('uploading', 'repository_largefile'));
                staged = await uploadFileChunked(selectedFile, tokenId, chunkSize, setProgress, controller, resumeFrom);
            }
            // A cancelled transfer returns false: leave the picker untouched.
            if (controller.cancelled || staged === false) {
                return;
            }
            // The file is staged and about to be listed for selection, so clear the
            // token — otherwise closing the modal would delete it before the user
            // can pick it — and forget the (now completed) resume record.
            controller.token = null;
            writeResume(data.contextId, null);
            modal.hide();
            data.callback();
        } catch (error) {
            busy = false;
            setProgress(0, 1);
            // Drop a partially staged token on failure so a retry does not orphan
            // it (a cancel has already deleted and cleared it via abort()), and
            // forget the resume record since the token is gone.
            if (controller.token) {
                postRequest({action: 'delete', id: controller.token}, null, null, null, null);
                controller.token = null;
            }
            writeResume(data.contextId, null);
            if (controller.cancelled) {
                return;
            }
            const strings = await Promise.all([getString('error', 'core'), getString('ok', 'core')]);
            Notification.alert(strings[0], error.message, strings[1]);
        }
    });

    modal.show();
    return modal;
};

/**
 * Register the pubsub listener once.
 *
 * @return {void}
 */
const registerEventListeners = () => {
    if (!listenersRegistered) {
        subscribe('repository_largefile_upload', (data) => {
            openUploadModal(data);
        });
        listenersRegistered = true;
    }
};

/**
 * Initialise the upload module.
 *
 * @return {void}
 */
export const init = () => {
    registerEventListeners();
};
