const CACHE_NAME = 'afyanova-patient-offline-v2';
const OFFLINE_URL = '/offline.html';
const PATIENT_NAVIGATION_PATHS = new Set(['/patients']);
const STATIC_PATH_PREFIXES = ['/build/'];
const STATIC_PATHS = [
    OFFLINE_URL,
    '/favicon.ico',
    '/favicon.svg',
    '/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_PATHS))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key !== CACHE_NAME)
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

function isSameOrigin(url) {
    return url.origin === self.location.origin;
}

function isStaticAsset(url) {
    return (
        STATIC_PATH_PREFIXES.some((prefix) => url.pathname.startsWith(prefix)) ||
        STATIC_PATHS.includes(url.pathname)
    );
}

function isExcludedRequest(url) {
    return (
        url.pathname.startsWith('/api/') ||
        url.pathname.startsWith('/sanctum/') ||
        url.pathname.startsWith('/login') ||
        url.pathname.startsWith('/logout') ||
        url.pathname.startsWith('/register') ||
        url.pathname.startsWith('/two-factor') ||
        url.pathname.startsWith('/user/')
    );
}

function patientNavigationCacheRequest(url) {
    return new Request(`${url.origin}${url.pathname}`, {
        method: 'GET',
        headers: {
            Accept: 'text/html,application/xhtml+xml',
        },
        credentials: 'same-origin',
    });
}

async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) return cached;

    const response = await fetch(request);
    if (response.ok) {
        const cache = await caches.open(CACHE_NAME);
        await cache.put(request, response.clone());
    }

    return response;
}

async function networkFirstPatientNavigation(request) {
    const cache = await caches.open(CACHE_NAME);

    try {
        const response = await fetch(request);
        const contentType = response.headers.get('content-type') ?? '';

        if (response.ok && contentType.includes('text/html')) {
            await cache.put(request, response.clone());
        }

        return response;
    } catch {
        return (
            (await cache.match(request)) ||
            (await caches.match(OFFLINE_URL)) ||
            Response.error()
        );
    }
}

async function cachePatientNavigation(urlString) {
    const url = new URL(urlString, self.location.origin);
    if (!isSameOrigin(url) || !PATIENT_NAVIGATION_PATHS.has(url.pathname)) {
        return;
    }

    const request = patientNavigationCacheRequest(url);
    const response = await fetch(request);
    const contentType = response.headers.get('content-type') ?? '';

    if (response.ok && contentType.includes('text/html')) {
        const cache = await caches.open(CACHE_NAME);
        await cache.put(request, response.clone());
    }
}

self.addEventListener('message', (event) => {
    if (event.data?.type !== 'CACHE_PATIENT_NAVIGATION') return;

    event.waitUntil(cachePatientNavigation(event.data.url));
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (!isSameOrigin(url) || isExcludedRequest(url)) return;

    if (request.mode === 'navigate') {
        if (PATIENT_NAVIGATION_PATHS.has(url.pathname)) {
            event.respondWith(networkFirstPatientNavigation(request));
        }
        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(cacheFirst(request));
    }
});
