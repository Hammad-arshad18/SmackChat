import api from '@/api';

const CACHE_NAME = 'smack-attachments-v1';
const MAX_MEMORY = 48;
const blobUrls = new Map();
const inflight = new Map();
const refs = new Map();
const lru = [];

function touch(id) {
    const at = lru.indexOf(id);
    if (at !== -1) {
        lru.splice(at, 1);
    }
    lru.push(id);
}

function evictIdle() {
    while (blobUrls.size > MAX_MEMORY && lru.length) {
        const oldest = lru[0];
        if ((refs.get(oldest) || 0) > 0) {
            touch(oldest);
            if (lru[0] === oldest) {
                break;
            }
            continue;
        }

        lru.shift();
        const url = blobUrls.get(oldest);
        if (url) {
            URL.revokeObjectURL(url);
            blobUrls.delete(oldest);
        }
    }
}

export function attachmentIdFromUrl(url) {
    const match = String(url || '').match(/\/attachments\/(\d+)/);

    return match ? Number(match[1]) : null;
}

async function readDisk(id) {
    if (typeof caches === 'undefined') {
        return null;
    }

    try {
        const cache = await caches.open(CACHE_NAME);
        const hit = await cache.match(diskKey(id));
        if (!hit || !hit.ok) {
            return null;
        }

        const type = hit.headers.get('Content-Type') || '';
        if (type.includes('application/json') || type.includes('text/html')) {
            return null;
        }

        return hit.blob();
    } catch {
        return null;
    }
}

async function writeDisk(id, blob) {
    if (typeof caches === 'undefined' || !blob) {
        return;
    }

    const type = blob.type || 'application/octet-stream';
    if (type.includes('application/json') || type.includes('text/html')) {
        return;
    }

    try {
        const cache = await caches.open(CACHE_NAME);
        await cache.put(diskKey(id), new Response(blob.slice(0, blob.size, type), {
            status: 200,
            headers: {
                'Content-Type': type,
                'Cache-Control': 'private, max-age=604800, immutable',
            },
        }));
    } catch {
        /* quota / private mode */
    }
}

function diskKey(id) {
    return `/api/attachments/${id}`;
}

function remember(id, blob) {
    const existing = blobUrls.get(id);
    if (existing) {
        touch(id);

        return existing;
    }

    const url = URL.createObjectURL(blob);
    blobUrls.set(id, url);
    touch(id);
    evictIdle();
    writeDisk(id, blob);

    return url;
}

export async function fetchAttachmentBlobUrl(id) {
    if (!id) {
        return null;
    }

    if (blobUrls.has(id)) {
        touch(id);

        return blobUrls.get(id);
    }

    if (inflight.has(id)) {
        return inflight.get(id);
    }

    const promise = (async () => {
        const cached = await readDisk(id);
        if (cached && cached.size > 0) {
            return remember(id, cached);
        }

        const response = await api.get(`/attachments/${id}`, {
            responseType: 'blob',
            headers: { Accept: '*/*' },
        });
        const data = response.data;
        const type = String(response.headers?.['content-type'] || data?.type || '');

        if (!data || !(data instanceof Blob) || data.size === 0) {
            return null;
        }

        if (type.includes('application/json') || type.includes('text/html')) {
            return null;
        }

        const blob = data.type ? data : new Blob([data], { type: type || 'application/octet-stream' });

        return remember(id, blob);
    })()
        .catch(() => null)
        .finally(() => {
            inflight.delete(id);
        });

    inflight.set(id, promise);

    return promise;
}

export function retainAttachment(id) {
    if (!id) {
        return;
    }

    refs.set(id, (refs.get(id) || 0) + 1);
}

export function releaseAttachment(id) {
    if (!id) {
        return;
    }

    const next = (refs.get(id) || 1) - 1;
    if (next <= 0) {
        refs.delete(id);

        return;
    }

    refs.set(id, next);
}

/** @deprecated use releaseAttachment — kept for older call sites */
export function releaseAttachmentBlob(id) {
    releaseAttachment(id);
}

export function cachedAttachmentUrl(id) {
    if (!id || !blobUrls.has(id)) {
        return null;
    }

    touch(id);

    return blobUrls.get(id);
}

export function warmAttachmentCache(id, blob) {
    if (!id || !(blob instanceof Blob) || blob.size === 0) {
        return null;
    }

    return remember(id, blob);
}

export function prefetchAttachments(items = []) {
    const ids = [];

    for (const item of items) {
        const id = item?.id || attachmentIdFromUrl(item?.url);
        if (id && !blobUrls.has(id) && !inflight.has(id)) {
            ids.push(id);
        }
    }

    ids.slice(0, 12).forEach((id) => {
        fetchAttachmentBlobUrl(id).catch(() => {});
    });
}

export async function clearAttachmentCache() {
    for (const url of blobUrls.values()) {
        URL.revokeObjectURL(url);
    }

    blobUrls.clear();
    inflight.clear();
    refs.clear();
    lru.splice(0, lru.length);

    if (typeof caches === 'undefined') {
        return;
    }

    try {
        await caches.delete(CACHE_NAME);
    } catch {
        /* private mode */
    }
}

export function attachmentFrameStyle(item, { maxWidth = 420 } = {}) {
    const width = Number(item?.width) || 0;
    const height = Number(item?.height) || 0;

    if (width > 0 && height > 0) {
        return {
            width: '100%',
            maxWidth: `${Math.min(maxWidth, width)}px`,
            aspectRatio: `${width} / ${height}`,
        };
    }

    if (item?.kind === 'video') {
        return {
            width: '100%',
            maxWidth: `${maxWidth}px`,
            aspectRatio: '16 / 9',
        };
    }

    return {
        width: '100%',
        maxWidth: '280px',
        aspectRatio: '4 / 3',
    };
}
