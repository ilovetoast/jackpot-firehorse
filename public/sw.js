const CACHE_VERSION = 'jackpot-pwa-v1'
const APP_SHELL_CACHE = `app-shell-${CACHE_VERSION}`
const RUNTIME_CACHE = `runtime-${CACHE_VERSION}`

const APP_SHELL_ASSETS = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icons/pwa-192.png',
    '/icons/pwa-512.png',
    '/icons/pwa-512-maskable.png',
    '/icons/apple-touch-icon.png',
]

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(APP_SHELL_CACHE).then((cache) => cache.addAll(APP_SHELL_ASSETS))
    )
    self.skipWaiting()
})

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key !== APP_SHELL_CACHE && key !== RUNTIME_CACHE)
                    .map((key) => caches.delete(key))
            )
        )
    )
    self.clients.claim()
})

self.addEventListener('fetch', (event) => {
    const { request } = event
    const url = new URL(request.url)

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return
    }

    // HTML navigation: network-first so authenticated pages stay fresh.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const responseClone = response.clone()
                    caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, responseClone))
                    return response
                })
                .catch(async () => {
                    const cached = await caches.match(request)
                    if (cached) {
                        return cached
                    }
                    return caches.match('/offline.html')
                })
        )
        return
    }

    const isStaticAsset = ['style', 'script', 'image', 'font'].includes(request.destination)

    if (isStaticAsset) {
        event.respondWith(
            caches.match(request).then((cached) => {
                const fetchPromise = fetch(request)
                    .then((response) => {
                        const responseClone = response.clone()
                        caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, responseClone))
                        return response
                    })
                    .catch((err) => {
                        if (cached) return cached
                        throw err
                    })
                return cached || fetchPromise
            })
        )
    }
})
