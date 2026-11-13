import api from '@/api';

const channelKeys = new Map();
const backedUpChannels = new Set();
const shareInflight = new Set();
const shareTimers = new Map();
const shareCheckedAt = new Map();
let activeUserId = null;
let identity = null;
let masterKey = null;

function storageKey(userId) {
    return `smackchat.e2e.v1.${userId}`;
}

function masterStorageKey(userId) {
    return `smackchat.e2e.master.v1.${userId}`;
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

async function deriveKek(password, salt) {
    const material = await crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode(password),
        'PBKDF2',
        false,
        ['deriveKey'],
    );

    return crypto.subtle.deriveKey(
        {
            name: 'PBKDF2',
            salt,
            iterations: 600000,
            hash: 'SHA-256',
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

async function sealBackup(master, channelId, rawKey) {
    const aes = await crypto.subtle.importKey('raw', master, 'AES-GCM', false, ['encrypt']);
    const nonce = crypto.getRandomValues(new Uint8Array(12));
    const cipher = new Uint8Array(await crypto.subtle.encrypt({
        name: 'AES-GCM',
        iv: nonce,
        additionalData: new TextEncoder().encode(`smackchat.backup.v1.${channelId}`),
    }, aes, rawKey));
    const out = new Uint8Array(1 + nonce.length + cipher.length);
    out[0] = 2;
    out.set(nonce, 1);
    out.set(cipher, 13);

    return bytesToB64(out);
}

async function openBackup(master, channelId, wrapped) {
    const blob = b64ToBytes(wrapped);
    const aes = await crypto.subtle.importKey('raw', master, 'AES-GCM', false, ['decrypt']);

    return new Uint8Array(await crypto.subtle.decrypt({
        name: 'AES-GCM',
        iv: blob.slice(1, 13),
        additionalData: new TextEncoder().encode(`smackchat.backup.v1.${channelId}`),
    }, aes, blob.slice(13)));
}

function persistMaster(userId, raw) {
    masterKey = raw;

    try {
        sessionStorage.setItem(masterStorageKey(userId), bytesToB64(raw));
    } catch {
        /* private mode can block sessionStorage */
    }
}

function restoreMaster(userId) {
    if (masterKey) {
        return masterKey;
    }

    try {
        const stored = sessionStorage.getItem(masterStorageKey(userId));
        if (stored) {
            masterKey = b64ToBytes(stored);
        }
    } catch {
        masterKey = null;
    }

    return masterKey;
}

function clearMaster(userId) {
    masterKey = null;

    try {
        if (userId) {
            sessionStorage.removeItem(masterStorageKey(userId));
        }
    } catch {
        /* ignore */
    }
}

export function forgetEncryption() {
    clearMaster(activeUserId);
    activeUserId = null;
    identity = null;
    channelKeys.clear();
    backedUpChannels.clear();
    shareInflight.clear();
    shareCheckedAt.clear();
    shareTimers.forEach((timer) => clearTimeout(timer));
    shareTimers.clear();
}

async function openVaultRecord(vault, password) {
    const salt = b64ToBytes(vault.salt);
    const blob = b64ToBytes(vault.wrapped_master);
    const kek = await deriveKek(password, salt);

    return new Uint8Array(await crypto.subtle.decrypt({
        name: 'AES-GCM',
        iv: blob.slice(1, 13),
        additionalData: new TextEncoder().encode('smackchat.vault.v1'),
    }, kek, blob.slice(13)));
}

async function sealVaultRecord(master, password) {
    const salt = crypto.getRandomValues(new Uint8Array(16));
    const kek = await deriveKek(password, salt);
    const nonce = crypto.getRandomValues(new Uint8Array(12));
    const cipher = new Uint8Array(await crypto.subtle.encrypt({
        name: 'AES-GCM',
        iv: nonce,
        additionalData: new TextEncoder().encode('smackchat.vault.v1'),
    }, kek, master));
    const out = new Uint8Array(1 + nonce.length + cipher.length);
    out[0] = 1;
    out.set(nonce, 1);
    out.set(cipher, 13);

    return {
        salt: bytesToB64(salt),
        wrapped_master: bytesToB64(out),
    };
}

export async function unlockEncryption(password) {
    if (!password || !activeUserId || !globalThis.crypto?.subtle) {
        return false;
    }

    let { data } = await api.get('/e2e/vault');

    if (!data.vault) {
        const provisional = crypto.getRandomValues(new Uint8Array(32));
        const sealed = await sealVaultRecord(provisional, password);
        const created = await api.put('/e2e/vault', sealed);
        // Another browser may have won the create race — always unlock whatever the server kept.
        data = { vault: created.data.vault };
    }

    const master = await openVaultRecord(data.vault, password);
    persistMaster(activeUserId, master);
    backedUpChannels.clear();

    for (const [channelId, raw] of channelKeys.entries()) {
        await rememberBackup({ id: channelId }, raw).catch(() => {});
    }

    return true;
}

export async function ensureDevice(userId) {
    if (!userId || !globalThis.crypto?.subtle || !globalThis.localStorage) {
        return null;
    }

    if (identity?.userId === userId && identity.privateKey) {
        restoreMaster(userId);

        return identity;
    }

    if (identity?.userId !== userId) {
        channelKeys.clear();
        backedUpChannels.clear();
        shareCheckedAt.clear();
        clearMaster(identity?.userId);
    }

    activeUserId = userId;
    restoreMaster(userId);
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

async function rememberBackup(channel, raw) {
    const master = restoreMaster(activeUserId);
    if (!master || !channel?.id || backedUpChannels.has(channel.id)) {
        return;
    }

    const wrapped = await sealBackup(master, channel.id, raw);
    await api.put('/e2e/vault/channels', {
        channels: [{ channel_id: channel.id, wrapped }],
    });
    backedUpChannels.add(channel.id);
}

async function publishWraps(office, channel, wraps) {
    if (!wraps.length) {
        return bundleFor(office, channel);
    }

    try {
        const { data } = await api.put(`/workspaces/${office.slug}/channels/${channel.slug}/keys`, { wraps });

        return data;
    } catch (error) {
        if (error?.response?.status !== 409) {
            throw error;
        }

        return bundleFor(office, channel);
    }
}

async function wrapsForDevices(raw, devices) {
    const wraps = [];

    for (const device of devices) {
        wraps.push({
            device_id: device.id,
            wrapped: await wrapRaw(raw, device.public_key, device.id),
        });
    }

    return wraps;
}

async function shareMissing(office, channel, raw, { force = false } = {}) {
    if (!office?.slug || !channel?.slug || !channel?.id || shareInflight.has(channel.id)) {
        return;
    }

    const lastChecked = shareCheckedAt.get(channel.id) || 0;
    if (!force && Date.now() - lastChecked < 15000) {
        return;
    }

    shareInflight.add(channel.id);

    try {
        const bundle = await bundleFor(office, channel);
        shareCheckedAt.set(channel.id, Date.now());
        const missing = bundle.devices.filter((device) => !bundle.wraps.some((item) => item.device_id === device.id));

        if (!missing.length) {
            return;
        }

        await publishWraps(office, channel, await wrapsForDevices(raw, missing));
    } finally {
        shareInflight.delete(channel.id);
    }
}

function queueShareMissing(office, channel, raw, options = {}) {
    if (!channel?.id) {
        return;
    }

    clearTimeout(shareTimers.get(channel.id));
    shareTimers.set(channel.id, setTimeout(() => {
        shareMissing(office, channel, raw, options).catch(() => {});
    }, 75));
}

async function recoverFromVault(office, channel, me) {
    const master = restoreMaster(activeUserId);
    if (!master) {
        return null;
    }

    const { data } = await api.get(`/e2e/vault/channels/${channel.id}`);
    if (!data.backup?.wrapped) {
        return null;
    }

    const raw = await openBackup(master, channel.id, data.backup.wrapped);
    const bundle = await bundleFor(office, channel);
    const mine = bundle.devices.filter((device) => device.user_id === me.userId
        && !bundle.wraps.some((item) => item.device_id === device.id));

    if (mine.length) {
        await publishWraps(office, channel, await wrapsForDevices(raw, mine));
    }

    return raw;
}

export async function sessionKey(office, channel) {
    if (!office?.slug || !channel?.id || !activeUserId) {
        return null;
    }

    if (channelKeys.has(channel.id)) {
        const cached = channelKeys.get(channel.id);
        queueShareMissing(office, channel, cached);
        rememberBackup(channel, cached).catch(() => {});

        return cached;
    }

    const me = await ensureDevice(activeUserId);
    if (!me) {
        return null;
    }

    let bundle = await bundleFor(office, channel);
    let wrap = bundle.wraps.find((item) => item.device_id === me.deviceId);

    if (!wrap && bundle.wraps.length === 0 && bundle.devices.some((device) => device.id === me.deviceId)) {
        const raw = crypto.getRandomValues(new Uint8Array(32));
        bundle = await publishWraps(office, channel, await wrapsForDevices(raw, bundle.devices));
        wrap = bundle.wraps.find((item) => item.device_id === me.deviceId);

        if (wrap) {
            channelKeys.set(channel.id, raw);
            await rememberBackup(channel, raw).catch(() => {});

            return raw;
        }
    }

    if (!wrap) {
        try {
            const recovered = await recoverFromVault(office, channel, me);
            if (recovered) {
                channelKeys.set(channel.id, recovered);
                queueShareMissing(office, channel, recovered, { force: true });

                return recovered;
            }
        } catch {
            /* vault missing or password not unlocked yet */
        }

        return null;
    }

    const raw = await unwrapRaw(wrap.wrapped, me);
    channelKeys.set(channel.id, raw);
    queueShareMissing(office, channel, raw, { force: true });
    rememberBackup(channel, raw).catch(() => {});

    return raw;
}

export async function syncChannelKeys(office) {
    if (!office?.slug || !activeUserId) {
        return;
    }

    const me = await ensureDevice(activeUserId);
    if (!me) {
        return;
    }

    for (const channel of office.channels || []) {
        if (!channel?.id || !channel?.slug) {
            continue;
        }

        try {
            await sessionKey(office, channel);
        } catch {
            /* keep going; locked channels retry later */
        }
    }
}

export function shareKnownChannelKeys(office) {
    if (!office?.slug) {
        return;
    }

    for (const channel of office.channels || []) {
        if (!channel?.id || !channelKeys.has(channel.id)) {
            continue;
        }

        queueShareMissing(office, channel, channelKeys.get(channel.id));
    }
}

export async function sealDocument(office, channel, document) {
    const raw = await sessionKey(office, channel);

    if (!raw) {
        throw new Error('This conversation is still waiting for a key from a teammate who already has it. Sign in again on a device that can read the chat, or wait for that device to come online.');
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
