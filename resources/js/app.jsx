import './bootstrap'
import './inertiaGlobalErrorHandling'
import '../css/app.css'
import { initPerformanceTracking } from './utils/performanceTracking'

initPerformanceTracking()

import { createInertiaApp, router } from '@inertiajs/react'
import { removeWorkspaceSwitchingOverlay } from './utils/workspaceSwitchOverlay'
import { maybeLogJackpotConsoleBanner } from './utils/jackpotConsoleBanner'
import PermissionDeniedHost from './Components/PermissionDeniedHost'
import GlobalErrorDialog from './Components/GlobalErrorDialog'

// Grid timing: record visit start for navigation-to-render diagnostic
router.on('start', () => {
    if (typeof window !== 'undefined') {
        window.__inertiaVisitStart = performance.now()
    }
})

// Company/brand switches use full page navigation; overlay is shown via sessionStorage + blade (see app.blade.php)
router.on('finish', (event) => {
    removeWorkspaceSwitchingOverlay()
    const pageProps = event.detail?.page?.props ?? router.page?.props
    maybeLogJackpotConsoleBanner(pageProps)
})

// Logged-in 403 on inertia:invalid is handled in inertiaGlobalErrorHandling.js

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
// import PushServiceInit from './Components/PushServiceInit' // off while PUSH_CLIENT_DISABLED — see pushService.js
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

const pages = import.meta.glob('./Pages/**/*.{jsx,tsx}', { eager: false })

/**
 * Load default export for an Inertia page. In dev, Vite's glob map is fixed at dev-server start — new files
 * under Pages/ are missing until restart; dynamic import fallback avoids "Page not found" for those pages.
 */
async function loadPageDefaultExport(name) {
    if (name.includes('..') || name.startsWith('/') || name.startsWith('.')) {
        throw new Error(`Invalid page name: ${name}`)
    }
    const pathJsx = `./Pages/${name}.jsx`
    const pathTsx = `./Pages/${name}.tsx`
    const fromGlob = pages[pathJsx] ?? pages[pathTsx]
    if (fromGlob) {
        const mod = await fromGlob()
        return mod.default || mod
    }
    if (import.meta.env.DEV) {
        try {
            const mod = await import(/* @vite-ignore */ pathJsx)
            return mod.default || mod
        } catch {
            try {
                const mod = await import(/* @vite-ignore */ pathTsx)
                return mod.default || mod
            } catch {
                // fall through
            }
        }
    }
    throw new Error(`Page not found: ${name}`)
}

createInertiaApp({
    resolve: async (name) => {
        const PageComponent = await loadPageDefaultExport(name)

        // Standalone cinematic pages: no global UI (FlashMessage, tray, download bar)
        const isExperience =
            name.startsWith('Experience/') ||
            name.startsWith('Gateway/') ||
            name.startsWith('Auth/CollectionInvite') ||
            name.startsWith('Auth/VerifyEmail') ||
            name.startsWith('Onboarding/')
        if (isExperience) {
            return (props) => (
                <>
                    <PageComponent {...props} />
                    <GlobalErrorDialog />
                    <PermissionDeniedHost />
                </>
            )
        }

        // Wrap the page component with FlashMessage, AssetProcessingTray, and app-level download bucket bar.
        // DownloadBucketBarGlobal uses BucketContext so state is shared; rendering this here keeps it in the
        // same DOM tree as the page so it's visible (fixed bottom bar). Bucket state lives in BucketProvider
        // so the bar shows the correct count without refetch on category change.
        return (props) => (
            <>
                <PageComponent {...props} />
                {props.auth?.user && <PWAInstallPopover auth={props.auth} />}
                {/* Push / OneSignal pre-prompt: disabled — uncomment PushServiceInit import + line below when re-enabling (pushService.js PUSH_CLIENT_DISABLED). */}
                {/* {props.auth?.user && <PushServiceInit />} */}
                <FlashMessage />
                <AssetProcessingTray />
                {false && <DownloadBucketBarGlobal />}
                <GlobalErrorDialog />
                <PermissionDeniedHost />
            </>
        )
    },
    setup({ el, App, props }) {
        maybeLogJackpotConsoleBanner(props.initialPage?.props)
        const root = createRoot(el)
        root.render(
            <BrandThemeProvider initialPage={props.initialPage}>
                <BucketProvider initialPage={props.initialPage}>
                    <SelectionProvider>
                        <App {...props} />
                    </SelectionProvider>
                </BucketProvider>
            </BrandThemeProvider>
        )
    },
})
