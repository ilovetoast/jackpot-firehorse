/**
 * PWAInstallPopover
 *
 * Shows a popover when the user logs in and the app is installable as a PWA.
 * Supports Chrome/Edge (beforeinstallprompt) on mobile and Windows desktop,
 * and iOS (manual Share > Add to Home Screen instructions).
 *
 * Only shows once per session when user first lands on an app page.
 */
import { useState, useEffect, useRef } from 'react'
import { usePage } from '@inertiajs/react'
import { ArrowDownCircleIcon, XMarkIcon } from '@heroicons/react/24/outline'

const PWA_POPOVER_DISMISSED_KEY = 'jackpot:pwa_popover_dismissed'

export default function PWAInstallPopover({ auth }) {
    const page = usePage()
    const timeoutRef = useRef(null)
    const [show, setShow] = useState(false)
    const [isInstallable, setIsInstallable] = useState(false)
    const [isIos, setIsIos] = useState(false)
    const [isInstalling, setIsInstalling] = useState(false)

    useEffect(() => {
        if (typeof window === 'undefined' || !auth?.user) return
        // Only show on app pages (not login, public, etc.)
        const pathStr = typeof window !== 'undefined' ? window.location.pathname : (page?.url ? new URL(page.url, 'http://localhost').pathname : '')
        if (!pathStr.startsWith('/app') || pathStr.startsWith('/app/admin')) return

        const isStandalone = window.matchMedia?.('(display-mode: standalone)')?.matches || window.navigator?.standalone === true
        if (isStandalone) return

        const dismissed = sessionStorage.getItem(PWA_POPOVER_DISMISSED_KEY)
        if (dismissed) return

        const checkIos = () => /iphone|ipad|ipod/i.test(window.navigator.userAgent || '')

        const scheduleShow = () => {
            if (timeoutRef.current) clearTimeout(timeoutRef.current)
            timeoutRef.current = setTimeout(() => setShow(true), 800)
        }

        const updateInstallState = () => {
            const hasPrompt = Boolean(window.__jackpotDeferredInstallPrompt)
            const ios = checkIos()
            setIsInstallable(hasPrompt)
            setIsIos(ios)
            if (hasPrompt || ios) scheduleShow()
        }

        updateInstallState()

        const onInstallable = () => {
            setIsInstallable(true)
            scheduleShow()
        }

        window.addEventListener('jackpot:pwa-installable', onInstallable)
        window.addEventListener('jackpot:pwa-installed', () => {
            setShow(false)
            sessionStorage.setItem(PWA_POPOVER_DISMISSED_KEY, '1')
        })

        return () => {
            window.removeEventListener('jackpot:pwa-installable', onInstallable)
            if (timeoutRef.current) clearTimeout(timeoutRef.current)
        }
    }, [auth?.user, page?.url])

    const handleInstall = async () => {
        if (typeof window === 'undefined') return

        const deferredPrompt = window.__jackpotDeferredInstallPrompt
        if (deferredPrompt) {
            setIsInstalling(true)
            try {
                await deferredPrompt.prompt()
                await deferredPrompt.userChoice
                if (window.__jackpotDeferredInstallPrompt === deferredPrompt) {
                    window.__jackpotDeferredInstallPrompt = null
                }
                setShow(false)
                sessionStorage.setItem(PWA_POPOVER_DISMISSED_KEY, '1')
            } catch (err) {
                console.error('PWA install prompt failed', err)
            } finally {
                setIsInstalling(false)
            }
        }
    }

    const handleDismiss = () => {
        setShow(false)
        sessionStorage.setItem(PWA_POPOVER_DISMISSED_KEY, '1')
    }

    if (!show) return null

    return (
        <div
            className="fixed inset-0 z-[100] flex items-start justify-center pt-4 px-4 sm:pt-6 sm:px-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="pwa-install-title"
        >
            {/* Backdrop - subtle, allows interaction to dismiss */}
            <div
                className="absolute inset-0 bg-gray-900/20"
                onClick={handleDismiss}
                aria-hidden="true"
            />

            <div className="relative w-full max-w-sm rounded-xl bg-white shadow-xl ring-1 ring-black/5 p-4 animate-in fade-in slide-in-from-top-2 duration-200">
                <button
                    type="button"
                    onClick={handleDismiss}
                    className="absolute right-3 top-3 rounded-md p-1 text-gray-400 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    aria-label="Dismiss"
                >
                    <XMarkIcon className="h-5 w-5" />
                </button>

                <div className="flex items-start gap-3 pr-8">
                    <div className="flex-shrink-0 rounded-lg bg-indigo-100 p-2">
                        <ArrowDownCircleIcon className="h-6 w-6 text-indigo-600" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <h2 id="pwa-install-title" className="text-sm font-semibold text-gray-900">
                            Install app
                        </h2>
                        <p className="mt-1 text-sm text-gray-600">
                            {isIos
                                ? 'Add this app to your home screen for quick access. Tap the Share button, then "Add to Home Screen".'
                                : 'Install this app on your device for a better experience. It works offline and loads faster.'}
                        </p>
                        <div className="mt-4 flex gap-2">
                            {isInstallable && (
                                <button
                                    type="button"
                                    onClick={handleInstall}
                                    disabled={isInstalling}
                                    className="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                                >
                                    {isInstalling ? 'Installing…' : 'Install'}
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={handleDismiss}
                                className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                Not now
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
