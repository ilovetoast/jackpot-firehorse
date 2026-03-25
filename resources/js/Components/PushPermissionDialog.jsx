import { router } from '@inertiajs/react'
import { useCallback, useEffect, useState } from 'react'
import {
    dismissPushPermissionPrompt,
    requestPushPermission,
} from '../services/pushService'

/**
 * Shown before the browser permission prompt: explains that accepting registers this device for push.
 */
const NS = '[JackpotPush]'

export default function PushPermissionDialog({ user }) {
    const [busy, setBusy] = useState(false)
    const [error, setError] = useState(null)

    useEffect(() => {
        if (typeof console !== 'undefined' && console.log) {
            console.log(NS, 'PushPermissionDialog mounted', { userId: user?.id })
        }
    }, [user?.id])

    const reloadAuth = useCallback(() => {
        router.reload({ only: ['auth'] })
    }, [])

    const onEnable = useCallback(async () => {
        setError(null)
        setBusy(true)
        try {
            await requestPushPermission(user)
            reloadAuth()
        } catch (e) {
            console.error('[JackpotPush] PushPermissionDialog enable failed', e)
            setError(e?.message || 'Something went wrong')
        } finally {
            setBusy(false)
        }
    }, [user, reloadAuth])

    const onNotNow = useCallback(async () => {
        setError(null)
        setBusy(true)
        try {
            await dismissPushPermissionPrompt()
            reloadAuth()
        } catch (e) {
            console.error('[JackpotPush] PushPermissionDialog dismiss failed', e)
            setError(e?.message || 'Something went wrong')
        } finally {
            setBusy(false)
        }
    }, [reloadAuth])

    return (
        <div
            className="fixed inset-0 z-[60000] flex items-center justify-center p-4"
            role="presentation"
        >
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" aria-hidden />
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="jackpot-push-dialog-title"
                className="relative z-10 w-full max-w-md rounded-xl border border-gray-200 bg-white p-6 shadow-xl"
            >
                <h2 id="jackpot-push-dialog-title" className="text-lg font-semibold text-gray-900">
                    Turn on notifications
                </h2>
                <p className="mt-3 text-sm leading-relaxed text-gray-600">
                    Allow notifications so we can register this browser for push alerts. Until you accept, your account
                    is not linked for delivery on this device — you will see a second prompt from the browser to allow
                    or block.
                </p>
                {error && (
                    <p className="mt-3 text-sm text-red-600" role="alert">
                        {error}
                    </p>
                )}
                <div className="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        disabled={busy}
                        onClick={onNotNow}
                        className="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
                    >
                        Not now
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={onEnable}
                        className="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {busy ? 'Please wait…' : 'Continue — enable notifications'}
                    </button>
                </div>
            </div>
        </div>
    )
}
