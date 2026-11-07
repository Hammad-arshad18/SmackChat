import api from '@/api';

const blobUrls = new Map();
const inflight = new Map();

export function attachmentIdFromUrl(url) {
    const match = String(url || '').match(/\/attachments\/(\d+)/);

    return match ? Number(match[1]) : null;
}

export async function fetchAttachmentBlobUrl(id) {
    if (!id) {
        return null;
    }

    if (blobUrls.has(id)) {
        return blobUrls.get(id);
    }

    if (inflight.has(id)) {
        return inflight.get(id);
    }

    const promise = api.get(`/attachments/${id}`, { responseType: 'blob' })
        .then(({ data }) => {
            const url = URL.createObjectURL(data);
            blobUrls.set(id, url);

            return url;
        })
        .catch(() => null)
        .finally(() => {
            inflight.delete(id);
        });

    inflight.set(id, promise);

    return promise;
}

export function releaseAttachmentBlob(id) {
    const url = blobUrls.get(id);
    if (!url) {
        return;
    }

    URL.revokeObjectURL(url);
    blobUrls.delete(id);
}
