const DEFAULT_TITLE = 'SmackChat — your team\'s day, in one place';
const DEFAULT_DESCRIPTION = 'SmackChat brings team chat, attendance, and office schedules together so your crew stays aligned through the day.';

function upsertMeta(attr, key, content) {
    const selector = `meta[${attr}="${key}"]`;
    let el = document.head.querySelector(selector);
    if (!el) {
        el = document.createElement('meta');
        el.setAttribute(attr, key);
        document.head.appendChild(el);
    }
    el.setAttribute('content', content);
}

function upsertLink(rel, href) {
    let el = document.head.querySelector(`link[rel="${rel}"]`);
    if (!el) {
        el = document.createElement('link');
        el.setAttribute('rel', rel);
        document.head.appendChild(el);
    }
    el.setAttribute('href', href);
}

export function applySeo({ title, description, path, noindex = false } = {}) {
    const pageTitle = title || DEFAULT_TITLE;
    const pageDescription = description || DEFAULT_DESCRIPTION;
    const url = new URL(path || window.location.pathname, window.location.origin).toString();

    document.title = pageTitle;

    upsertMeta('name', 'description', pageDescription);
    upsertMeta('name', 'robots', noindex ? 'noindex, nofollow' : 'index, follow');

    upsertMeta('property', 'og:title', pageTitle);
    upsertMeta('property', 'og:description', pageDescription);
    upsertMeta('property', 'og:url', url);
    upsertMeta('property', 'og:type', 'website');

    upsertMeta('name', 'twitter:title', pageTitle);
    upsertMeta('name', 'twitter:description', pageDescription);

    upsertLink('canonical', url);
}

export { DEFAULT_TITLE, DEFAULT_DESCRIPTION };
