import './bootstrap'
import '../css/app.css'
import { initPerformanceTracking } from './utils/performanceTracking'

initPerformanceTracking()

import { createInertiaApp, router } from '@inertiajs/react'
import { removeWorkspaceSwitchingOverlay } from './utils/workspaceSwitchOverlay'
import { parsePermissionDeniedHtml } from './utils/parsePermissionDeniedHtml'
import { resolvePermissionTheme } from './utils/resolvePermissionTheme'
import PermissionDeniedHost from './Components/PermissionDeniedHost'

// Grid timing: record visit start for navigation-to-render diagnostic
router.on('start', () => {
    if (typeof window !== 'undefined') {
        window.__inertiaVisitStart = performance.now()
    }
})

// Company/brand switches use full page navigation; overlay is shown via sessionStorage + blade (see app.blade.php)
router.on('finish', () => {
    removeWorkspaceSwitchingOverlay()
})

// Logged-in 403 (HTML/JSON non-Inertia response): show in-app modal instead of Inertia's full-screen error
if (typeof document !== 'undefined') {
    document.addEventListener('inertia:invalid', (event) => {
        const res = event.detail.response
        if (!res || res.status !== 403) return

        let user = null
        try {
            user = router.page?.props?.auth?.user
        } catch {
            /* ignore */
        }
        if (!user) return

        event.preventDefault()

        const raw = res.data
        let title = 'Access denied'
        let message = 'You do not have permission to perform this action.'

        if (typeof raw === 'string' && raw.includes('<')) {
            const parsed = parsePermissionDeniedHtml(raw)
            title = parsed.title
            message = parsed.message
        } else if (raw && typeof raw === 'object') {
            if (raw.message) message = String(raw.message)
            if (raw.title) title = String(raw.title)
        } else if (typeof raw === 'string' && raw.trim().length) {
            message = raw.trim()
        }

        const theme = resolvePermissionTheme(
            router.page?.url || '',
            router.page?.props?.auth?.activeBrand
        )

        window.dispatchEvent(
            new CustomEvent('jackpot:permission-denied', {
                detail: { title, message, theme, source: 'inertia' },
            })
        )
    })
}

// Full page reload: `finish` may not run on first paint — hide overlay after shell is interactive (fallback if still visible)
if (typeof document !== 'undefined') {
    document.addEventListener(
        'DOMContentLoaded',
        () => {
            if (document.getElementById('jackpot-workspace-switch-overlay')) {
                setTimeout(() => removeWorkspaceSwitchingOverlay(), 450)
            }
        },
        { once: true }
    )
}
import { createRoot } from 'react-dom/client'
import BrandThemeProvider from './Components/BrandThemeProvider'
import FlashMessage from './Components/FlashMessage'
import AssetProcessingTray from './Components/AssetProcessingTray'
import DownloadBucketBarGlobal from './Components/DownloadBucketBarGlobal'
import PWAInstallPopover from './Components/PWAInstallPopover'
import { BucketProvider } from './contexts/BucketContext'
import { SelectionProvider } from './contexts/SelectionContext'

if (typeof window !== 'undefined' && import.meta.env.PROD && !window.__jackpotPwaInitialized) {
    window.__jackpotPwaInitialized = true
    window.__jackpotDeferredInstallPrompt = null

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault()
        window.__jackpotDeferredInstallPrompt = event
        window.dispatchEvent(new CustomEvent('jackpot:pwa-installable'))
    })

    window.addEventListener('appinstalled', () => {
        window.__jackpotDeferredInstallPrompt = null
        window.dispatchEvent(new CustomEvent('jackpot:pwa-installed'))
    })

    const registerServiceWorker = () => {
        if (!('serviceWorker' in navigator)) {
            return
        }

        navigator.serviceWorker.register('/sw.js').catch((error) => {
            console.error('Service worker registration failed', error)
        })
    }

    if (document.readyState === 'complete') {
        registerServiceWorker()
    } else {
        window.addEventListener('load', registerServiceWorker, { once: true })
    }
}

const pages = import.meta.glob('./Pages/**/*.jsx', { eager: false })

createInertiaApp({
    resolve: async name => {
        const path = `./Pages/${name}.jsx`
        const component = pages[path]
        if (!component) {
            throw new Error(`Page not found: ${name}`)
        }
        
        // Load the page component
        const pageModule = await component()
        const PageComponent = pageModule.default || pageModule
        
        // Standalone cinematic pages: no global UI (FlashMessage, tray, download bar)
        const isExperience = name.startsWith('Experience/') || name.startsWith('Gateway/')
        if (isExperience) {
            return (props) => (
                <>
                    <PageComponent {...props} />
                    <PermissionDeniedHost />
                </>
            )
        }
        
        // Wrap the page component with FlashMessage, AssetProcessingTray, and app-level download bucket bar.
        // DownloadBucketBarGlobal uses BucketContext so state is shared; rendering it here keeps it in the
        // same DOM tree as the page so it's visible (fixed bottom bar). Bucket state lives in BucketProvider
        // so the bar shows the correct count without refetch on category change.
        return (props) => (
            <>
                <PageComponent {...props} />
                {props.auth?.user && <PWAInstallPopover auth={props.auth} />}
                <FlashMessage />
                <AssetProcessingTray />
                {false && <DownloadBucketBarGlobal />}
                <PermissionDeniedHost />
            </>
        )
    },
    setup({ el, App, props }) {
        const root = createRoot(el)
        root.render(
            <BrandThemeProvider initialPage={props.initialPage}>
                <BucketProvider>
                    <SelectionProvider>
                        <App {...props} />
                    </SelectionProvider>
                </BucketProvider>
            </BrandThemeProvider>
        )
    },
})
