import api from '@/api';

const channelKeys = new Map();
let activeUserId = null;
let identity = null;

function storageKey(userId) {
    return `smackchat.e2e.v1.${userId}`;
}

function bytesToB64(bytes) {
    let binary = '';
    const chunk = 0x8000;

    for (let index = 0; index < bytes.length; index += chunk) {
        binary += String.fromCharCode(...bytes.subarray(index, index + chunk));
    }

    return btoa(binary);
}

function b64ToBytes(value) {
    const binary = atob(value);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

async function aesFromShared(shared, salt, info) {
    const material = await crypto.subtle.importKey('raw', shared, 'HKDF', false, ['deriveKey']);

    return crypto.subtle.deriveKey(
        {
            name: 'HKDF',
            hash: 'SHA-256',
            salt,
            info: new TextEncoder().encode(info),
        },
        material,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
}

async function wrapRaw(rawKey, publicKey, deviceId) {
    const recipient = await crypto.subtle.importKey(
        'raw',
        b64ToBytes(publicKey),
        { name: 'ECDH', namedCurve: 'P-256' },
        false,
        [],
    );
    const ephemeral = await crypto.subtle.generateKey(
        { name: 'ECDH', namedCurve: 'P-256' },
        true,
        ['deriveBits'],
    );
    const shared = await crypto.subtle.deriveBits(
        { name: 'ECDH', public: recipient },
        ephemeral.privateKey,
        256,
    );
    const salt = new Uint8Array(await crypto.subtle.exportKey('raw', ephemeral.publicKey));
    const aes = await aesFromShared(shared, salt, `smackchat.wrap.v1.${deviceId}`);
    const nonce = crypto.getRandomValues(new Uint8Array(12));
    const cipher = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv: nonce }, aes, rawKey));
    const out = new Uint8Array(1 + salt.length + nonce.length + cipher.length);
    out[0] = 1;
    out.set(salt, 1);
    out.set(nonce, 1 + salt.length);
    out.set(cipher, 1 + salt.length + nonce.length);

    return bytesToB64(out);
}

async function unwrapRaw(wrapped, me) {
    const blob = b64ToBytes(wrapped);
    const salt = blob.slice(1, 66);
    const nonce = blob.slice(66, 78);
    const cipher = blob.slice(78);
    const ephemeral = await crypto.subtle.importKey(
        'raw',
        salt,
        { name: 'ECDH', namedCurve: 'P-256' },
        false,
        [],
    );
    const shared = await crypto.subtle.deriveBits(
        { name: 'ECDH', public: ephemeral },
        me.privateKey,
        256,
    );
    const aes = await aesFromShared(shared, salt, `smackchat.wrap.v1.${me.deviceId}`);

    return new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: nonce }, aes, cipher));
}

export function forgetEncryption() {
    activeUserId = null;
    identity = null;
    channelKeys.clear();
}

export async function ensureDevice(userId) {
    if (!userId || !globalThis.crypto?.subtle || !globalThis.localStorage) {
        return null;
    }

    if (identity?.userId === userId && identity.privateKey) {
        return identity;
    }

    if (identity?.userId !== userId) {
        channelKeys.clear();
    }

    activeUserId = userId;
    const key = storageKey(userId);
    let stored = null;

    try {
        stored = JSON.parse(localStorage.getItem(key) || 'null');
    } catch {
        stored = null;
    }

    let privateKey;
    let publicKey;

    if (stored?.jwk && stored.publicKey) {
        privateKey = await crypto.subtle.importKey(
            'jwk',
            stored.jwk,
            { name: 'ECDH', namedCurve: 'P-256' },
            true,
            ['deriveBits'],
        );
        publicKey = stored.publicKey;
    } else {
        const pair = await crypto.subtle.generateKey(
            { name: 'ECDH', namedCurve: 'P-256' },
            true,
            ['deriveBits'],
        );
        publicKey = bytesToB64(new Uint8Array(await crypto.subtle.exportKey('raw', pair.publicKey)));
        stored = {
            jwk: await crypto.subtle.exportKey('jwk', pair.privateKey),
            publicKey,
        };
        localStorage.setItem(key, JSON.stringify(stored));
        privateKey = pair.privateKey;
    }

    const { data } = await api.post('/e2e/devices', { public_key: publicKey });
    stored.deviceId = data.device.id;
    localStorage.setItem(key, JSON.stringify(stored));
    identity = {
        userId,
        deviceId: data.device.id,
        privateKey,
        publicKey,
    };

    return identity;
}

async function bundleFor(office, channel) {
    const { data } = await api.get(`/workspaces/${office.slug}/channels/${channel.slug}/keys`);

    return data;
}

export async function sessionKey(office, channel) {
    if (!office?.slug || !channel?.id || !activeUserId) {
        return null;
    }

    if (channelKeys.has(channel.id)) {
        return channelKeys.get(channel.id);
    }

    const me = await ensureDevice(activeUserId);
    if (!me) {
        return null;
    }

    let bundle = await bundleFor(office, channel);
    let wrap = bundle.wraps.find((item) => item.device_id === me.deviceId);

    if (!wrap && bundle.wraps.length === 0 && bundle.devices.some((device) => device.id === me.deviceId)) {
        const raw = crypto.getRandomValues(new Uint8Array(32));
        const wraps = [];

        for (const device of bundle.devices) {
            wraps.push({
                device_id: device.id,
                wrapped: await wrapRaw(raw, device.public_key, device.id),
            });
        }

        try {
            const { data } = await api.put(`/workspaces/${office.slug}/channels/${channel.slug}/keys`, { wraps });
            bundle = data;
        } catch (error) {
            if (error?.response?.status !== 409) {
                throw error;
            }
            bundle = await bundleFor(office, channel);
        }

        wrap = bundle.wraps.find((item) => item.device_id === me.deviceId);
    }

    if (!wrap) {
        return null;
    }

    const raw = await unwrapRaw(wrap.wrapped, me);
    channelKeys.set(channel.id, raw);
    const missing = bundle.devices.filter((device) => !bundle.wraps.some((item) => item.device_id === device.id));

    if (missing.length) {
        const wraps = [];

        for (const device of missing) {
            wraps.push({
                device_id: device.id,
                wrapped: await wrapRaw(raw, device.public_key, device.id),
            });
        }

        await api.put(`/workspaces/${office.slug}/channels/${channel.slug}/keys`, { wraps });
    }

    return raw;
}

export async function sealDocument(office, channel, document) {
    const raw = await sessionKey(office, channel);

    if (!raw) {
        throw new Error('This conversation is still waiting for a key from a teammate who already has it.');
    }

    const aes = await crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['encrypt']);
    const nonce = crypto.getRandomValues(new Uint8Array(12));
    const cipher = new Uint8Array(await crypto.subtle.encrypt({
        name: 'AES-GCM',
        iv: nonce,
        additionalData: new TextEncoder().encode(`smackchat.message.v1.${channel.id}`),
    }, aes, new TextEncoder().encode(JSON.stringify(document))));
    const out = new Uint8Array(1 + nonce.length + cipher.length);
    out[0] = 1;
    out.set(nonce, 1);
    out.set(cipher, 13);

    return bytesToB64(out);
}

export function plainText(node) {
    if (!node || typeof node !== 'object') {
        return '';
    }

    const type = node.type || '';

    if (type === 'text') {
        return String(node.text || '');
    }

    if (type === 'mention') {
        return `@${node.attrs?.label || 'someone'}`;
    }

    if (type === 'hardBreak') {
        return '\n';
    }

    let text = '';

    for (const child of node.content || []) {
        text += plainText(child);
    }

    if (['paragraph', 'heading', 'listItem', 'blockquote', 'codeBlock'].includes(type)) {
        text += '\n';
    }

    return text;
}

function excerptFor(text, attachmentCount) {
    const flat = text.replace(/\s+/g, ' ').trim();

    if (flat) {
        return flat.length > 90 ? `${flat.slice(0, 89)}…` : flat;
    }

    if (attachmentCount === 1) {
        return 'Sent an attachment';
    }

    if (attachmentCount > 1) {
        return `Sent ${attachmentCount} attachments`;
    }

    return '';
}

export function snippetAround(text, needle) {
    const flat = text.replace(/\s+/g, ' ').trim();
    const at = flat.toLowerCase().indexOf(needle.trim().toLowerCase());
    const start = at < 0 ? 0 : Math.max(0, at - 46);
    const slice = flat.slice(start, start + 150);

    return `${start > 0 ? '…' : ''}${slice}${flat.length > start + 150 ? '…' : ''}`;
}

export async function revealMessage(office, message) {
    if (!message || message.deleted || message.cipher !== 'e2e' || message.document || !message.sealed) {
        return message;
    }

    const channel = office.channels?.find((item) => item.id === message.channel_id);

    if (!channel) {
        return { ...message, locked: true };
    }

    try {
        const raw = await sessionKey(office, channel);

        if (!raw) {
            return { ...message, locked: true };
        }

        const aes = await crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['decrypt']);
        const blob = b64ToBytes(message.sealed);
        const plain = await crypto.subtle.decrypt({
            name: 'AES-GCM',
            iv: blob.slice(1, 13),
            additionalData: new TextEncoder().encode(`smackchat.message.v1.${channel.id}`),
        }, aes, blob.slice(13));
        const document = JSON.parse(new TextDecoder().decode(plain));
        const text = plainText(document).trim();

        return {
            ...message,
            document,
            text,
            excerpt: excerptFor(text, message.attachments?.length || 0),
            locked: false,
        };
    } catch {
        return { ...message, locked: true };
    }
}

export async function revealChannels(office) {
    for (const channel of office.channels || []) {
        if (!channel.latest_sealed) {
            continue;
        }

        const message = await revealMessage(office, {
            channel_id: channel.id,
            cipher: 'e2e',
            sealed: channel.latest_sealed,
            deleted: false,
            attachments: [],
        });

        if (message.excerpt) {
            channel.excerpt = message.excerpt;
        }
    }
}

export async function searchMessages(office, needle, channelSlug = null) {
    const params = {};

    if (channelSlug) {
        params.channel = channelSlug;
    }

    const { data } = await api.get(`/workspaces/${office.slug}/search`, { params });
    const query = needle.trim().toLowerCase();
    const results = [];

    for (const result of data.results || []) {
        const message = await revealMessage(office, result.message);
        const text = (message.text || '').replace(/\s+/g, ' ').trim();

        if (!text.toLowerCase().includes(query)) {
            continue;
        }

        results.push({
            ...result,
            message,
            snippet: snippetAround(text, needle.trim()),
        });

        if (results.length >= 60) {
            break;
        }
    }

    return results;
}
