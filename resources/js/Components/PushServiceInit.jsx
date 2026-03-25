import { usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import PushPermissionDialog from './PushPermissionDialog'
import { initPush, shouldShowPushPermissionDialog } from '../services/pushService'

const NS = '[JackpotPush]'
const SERVER_OFF_NOTICE_KEY = 'jackpot_push_server_off_notice_dismissed'

/**
 * Runs on authenticated Inertia pages (see app.jsx).
 * Site dialog when DB has no consent yet (`push_prompted_at` null) + browser permission still "default".
 * Does NOT wait for OneSignal init — that race was hiding the dialog; SDK is waited for inside pushService.
 */
export default function PushServiceInit() {
    const page = usePage()
    const { auth, oneSignal } = page.props
    const user = auth?.user
    const enabled = Boolean(oneSignal?.client_enabled)
    const [serverOffNoticeDismissed, setServerOffNoticeDismissed] = useState(() => {
        if (typeof sessionStorage === 'undefined') {
            return false
        }
        return sessionStorage.getItem(SERVER_OFF_NOTICE_KEY) === '1'
    })

    useEffect(() => {
        if (!user?.id) {
            return
        }
        if (typeof console === 'undefined' || !console.log) {
            return
        }
        console.log(NS, 'PushServiceInit mounted (logged-in shell)', {
            url: page.url,
            client_enabled: enabled,
            oneSignal_app_id_set: Boolean(oneSignal?.app_id),
        })
        if (!enabled) {
            console.warn(
                NS,
                'Push is OFF: oneSignal.client_enabled is false. Set PUSH_NOTIFICATIONS_ENABLED=true and ONESIGNAL_APP_ID in .env, then reload.'
            )
        }
    }, [user?.id, page.url, enabled, oneSignal?.app_id])

    useEffect(() => {
        if (!enabled || !user?.id) {
            return
        }

        initPush(user)
    }, [enabled, user?.id, user?.push_prompted_at, user?.push_enabled])

    const eligibleForConsent = Boolean(user && shouldShowPushPermissionDialog(user))
    const showPrePrompt = Boolean(enabled && eligibleForConsent)
    const showServerOffNotice =
        Boolean(!enabled && eligibleForConsent && !serverOffNoticeDismissed)

    useEffect(() => {
        if (showPrePrompt && typeof console !== 'undefined' && console.log) {
            console.log(NS, 'Showing PushPermissionDialog (DB: push_prompted_at not set, Notification.permission=default)', {
                push_prompted_at: user?.push_prompted_at,
            })
        }
    }, [showPrePrompt, user?.push_prompted_at])

    useEffect(() => {
        if (!user?.id) {
            return
        }
        if (typeof console === 'undefined' || !console.log) {
            return
        }
        console.log(NS, 'PushServiceInit state', {
            client_enabled: enabled,
            eligibleForConsent: user ? shouldShowPushPermissionDialog(user) : false,
            showPrePrompt,
            push_prompted_at: user.push_prompted_at,
            push_enabled: user.push_enabled,
            notificationPermission: typeof Notification !== 'undefined' ? Notification.permission : 'n/a',
        })
    }, [user?.id, user?.push_prompted_at, user?.push_enabled, enabled, showPrePrompt])

    const dismissServerOffNotice = () => {
        try {
            sessionStorage.setItem(SERVER_OFF_NOTICE_KEY, '1')
        } catch {
            // ignore
        }
        setServerOffNoticeDismissed(true)
    }

    return (
        <>
            {showPrePrompt ? <PushPermissionDialog user={user} /> : null}
            {showServerOffNotice ? (
                <div
                    className="pointer-events-auto fixed bottom-4 left-1/2 z-[59900] w-[max(280px,calc(100vw-2rem))] max-w-lg -translate-x-1/2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 shadow-lg"
                    role="status"
                >
                    <p className="font-medium">Push is disabled on the server</p>
                    <p className="mt-1 text-xs leading-relaxed text-amber-900/90">
                        <code className="rounded bg-amber-100/80 px-1">client_enabled</code> is false, so the app will not
                        load OneSignal or show the consent dialog. In <code className="rounded bg-amber-100/80 px-1">.env</code>{' '}
                        set <strong>PUSH_NOTIFICATIONS_ENABLED=true</strong> and <strong>ONESIGNAL_APP_ID</strong> (your
                        OneSignal app id). Run <code className="rounded bg-amber-100/80 px-1">php artisan config:clear</code>{' '}
                        if you use config cache, then reload.
                    </p>
                    <button
                        type="button"
                        onClick={dismissServerOffNotice}
                        className="mt-2 text-xs font-medium text-amber-900 underline hover:no-underline"
                    >
                        Dismiss
                    </button>
                </div>
            ) : null}
        </>
    )
}
