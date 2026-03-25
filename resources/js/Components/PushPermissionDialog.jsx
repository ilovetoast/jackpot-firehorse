import { router } from '@inertiajs/react'
import { useCallback, useEffect, useState } from 'react'
import {
    dismissPushPermissionPrompt,
    requestPushPermission,
} from '../services/pushService'

const NS = '[JackpotPush]'

/**
 * First visit: friendly prompt before the browser’s own allow/block dialog.
 */
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

    const onAllow = useCallback(async () => {
        setError(null)
        setBusy(true)
        try {
            await requestPushPermission(user)
            reloadAuth()
        } catch (e) {
            console.error(NS, 'Allow notifications failed', e)
            setError(e?.message || 'Something went wrong. Try again.')
        } finally {
            setBusy(false)
        }
    }, [user, reloadAuth])

    const onMaybeLater = useCallback(async () => {
        setError(null)
        setBusy(true)
        try {
            await dismissPushPermissionPrompt()
            reloadAuth()
        } catch (e) {
            console.error(NS, 'Dismiss failed', e)
            setError(e?.message || 'Something went wrong. Try again.')
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
                aria-describedby="jackpot-push-dialog-desc"
                className="relative z-10 w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-2xl"
            >
                <h2 id="jackpot-push-dialog-title" className="text-xl font-semibold tracking-tight text-gray-900">
                    Stay in the loop
                </h2>
                <p id="jackpot-push-dialog-desc" className="mt-3 text-sm leading-relaxed text-gray-600">
                    Get timely updates on this device. When you continue, your browser will ask whether to allow
                    notifications — that’s normal, and you can change it anytime in settings.
                </p>
                {error && (
                    <p className="mt-3 text-sm text-red-600" role="alert">
                        {error}
                    </p>
                )}
                <div className="mt-6 flex flex-col gap-2 sm:flex-row-reverse sm:justify-end sm:gap-3">
                    <button
                        type="button"
                        disabled={busy}
                        onClick={onAllow}
                        className="inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 sm:w-auto"
                    >
                        {busy ? 'Working…' : 'Allow notifications'}
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={onMaybeLater}
                        className="inline-flex w-full items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-medium text-gray-800 hover:bg-gray-50 disabled:opacity-50 sm:w-auto"
                    >
                        Maybe later
                    </button>
                </div>
            </div>
        </div>
    )
}
