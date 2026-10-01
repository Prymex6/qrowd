/**
 * The music library on disk - the browser side of the player.
 *
 * OVERRIDING RULE: the files never leave the host's computer.
 *
 * The host points at a folder through the system picker, the browser reads the
 * names and durations out of it, and only a listing travels to the server -
 * title, artist, duration, relative path. The sound is played straight from the
 * file, through a handle to the folder.
 *
 * This is what lets QRowd sit on any hosting: the server neither sees nor
 * stores the music, and a venue with no internet plays all the same.
 *
 * Requires the File System Access API (Chrome, Edge, Opera). The player runs on
 * the host's laptop anyway and needs the YouTube IFrame API, so that is a fair
 * price to pay.
 */

const DATABASE = 'qrowd-library';
const STORE    = 'handles';
const KEY      = 'folder';

/** The formats a browser can play without plugins. */
const EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'wav', 'webm'];

export function isSupported() {
    return typeof window !== 'undefined' && 'showDirectoryPicker' in window;
}

/**
 * A track's identifier - it has to give the same answer as LocalLibrary::idFor()
 * in PHP, otherwise the player will not find the file behind a queue entry.
 */
export async function trackId(path) {
    const data = new TextEncoder().encode(path.replace(/\\/g, '/'));
    const digest = await crypto.subtle.digest('SHA-256', data);

    const hex = Array.from(new Uint8Array(digest))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');

    return 'L' + hex.slice(0, 19);
}

// ---------------------------------------------------------------- the handle

/**
 * A folder handle outlives a closed tab, but the permission to read does not.
 *
 * We keep it in IndexedDB so the host points at the folder ONCE rather than
 * before every party. On the next visit the browser only asks to confirm the
 * access - one click instead of hunting through the directory tree.
 */
function database() {
    return new Promise((ok, error) => {
        const request = indexedDB.open(DATABASE, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(STORE);
        request.onsuccess = () => ok(request.result);
        request.onerror = () => error(request.error);
    });
}

async function storeHandle(handle) {
    const db = await database();

    return new Promise((ok, error) => {
        const t = db.transaction(STORE, 'readwrite');
        t.objectStore(STORE).put(handle, KEY);
        t.oncomplete = () => ok();
        t.onerror = () => error(t.error);
    });
}

async function readHandle() {
    try {
        const db = await database();

        return await new Promise((ok, error) => {
            const t = db.transaction(STORE, 'readonly');
            const request = t.objectStore(STORE).get(KEY);
            request.onsuccess = () => ok(request.result || null);
            request.onerror = () => error(request.error);
        });
    } catch (e) {
        return null;
    }
}

/** Opens the system folder picker. */
export async function chooseFolder() {
    const handle = await window.showDirectoryPicker({ id: 'qrowd-muzyka', mode: 'read' });

    await storeHandle(handle);

    return handle;
}

/**
 * Recovers the folder from an earlier session.
 *
 * `mayPrompt` decides whether we are allowed to raise the system dialog asking
 * for access. The browser permits that only in reaction to a click, so the
 * automatic check on page load asks silently.
 */
export async function restoreFolder({ mayPrompt = false } = {}) {
    const handle = await readHandle();

    if (!handle) return null;

    const state = await handle.queryPermission({ mode: 'read' });

    if (state === 'granted') return handle;

    if (state === 'prompt' && mayPrompt) {
        const fresh = await handle.requestPermission({ mode: 'read' });

        if (fresh === 'granted') return handle;
    }

    return null;
}

export async function forgetFolder() {
    const db = await database();

    return new Promise((ok) => {
        const t = db.transaction(STORE, 'readwrite');
        t.objectStore(STORE).delete(KEY);
        t.oncomplete = () => ok();
    });
}

// ---------------------------------------------------------------- walking the folder

/** Descends through the folder and hands back the paths of the music files. */
export async function walk(handle, onProgress = null) {
    const found = [];

    async function descend(directory, prefix) {
        for await (const [name, position] of directory.entries()) {
            const path = prefix ? `${prefix}/${name}` : name;

            if (position.kind === 'directory') {
                await descend(position, path);
                continue;
            }

            const extension = name.split('.').pop().toLowerCase();

            if (EXTENSIONS.includes(extension)) {
                found.push(path);

                if (onProgress && found.length % 50 === 0) {
                    onProgress(found.length);
                }
            }
        }
    }

    await descend(handle, '');

    return found;
}

/** Opens a file by its path relative to the chosen folder. */
export async function file(handle, path) {
    const parts = path.split('/');
    const name  = parts.pop();

    let directory = handle;

    for (const part of parts) {
        directory = await directory.getDirectoryHandle(part);
    }

    return (await directory.getFileHandle(name)).getFile();
}

/**
 * Pulls the artist and the title out of the file name.
 *
 * We deliberately do NOT read ID3 tags. I checked this against a real library:
 * the tags tend to be leftovers from YouTube ("sanah - To koniec (Official
 * audio) HD", artist "Nieznany_Artysta"), while the file name has been tidied
 * by hand. On top of that, reading tags in the browser would need another
 * library and would load every file into memory.
 */
export function fromFileName(path) {
    let name = path.split('/').pop().replace(/\.[^.]+$/, '');

    // A leading track number: "03 - Title", "03. Title", "03 Title".
    name = name.replace(/^\s*\d{1,3}\s*[-.)]?\s+/, '');

    for (const separator of [' - ', ' – ', ' — ', ' _ ']) {
        if (name.includes(separator)) {
            const [left, ...rest] = name.split(separator);

            return { artist: withoutPlaceholder(left.trim()), title: rest.join(separator).trim() };
        }
    }

    return { artist: null, title: name.trim() };
}

/** The placeholders tagging programs write in place of an artist. */
function withoutPlaceholder(artist) {
    const placeholders = [
        'unknown artist', 'unknown', 'nieznany artysta', 'nieznany',
        'various artists', 'various', 'va', 'none', 'n/a',
    ];

    const normalised = artist.toLowerCase().replace(/_/g, ' ').trim();

    return placeholders.includes(normalised) ? null : artist;
}

/**
 * Measures how long a track is.
 *
 * The only way without a tag reader: hand the file to an audio element and wait
 * for the metadata. The browser then loads the header alone, not the whole file.
 */
export function duration(fileToMeasure) {
    return new Promise((ok) => {
        const url = URL.createObjectURL(fileToMeasure);
        const el = new Audio();

        const done = (seconds) => {
            URL.revokeObjectURL(url);
            ok(seconds);
        };

        el.preload = 'metadata';
        el.onloadedmetadata = () => done(Number.isFinite(el.duration) ? Math.round(el.duration) : 0);
        el.onerror = () => done(0);
        el.src = url;
    });
}
