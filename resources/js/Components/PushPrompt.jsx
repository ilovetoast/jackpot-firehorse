import { usePushNotifications } from '../hooks/usePushNotifications'

/**
 * Soft onboarding banner for web push (no automatic browser permission).
 * Shown once per user until dismissed or acted on; see usePushNotifications.
 */
export default function PushPrompt() {
    const { showPrompt, enablePush, dismissNotNow, busy } = usePushNotifications()

    if (!showPrompt) {
        return null
    }

    return (
        <div
            className="pointer-events-none fixed inset-x-0 bottom-0 z-[2147483640] flex justify-center p-4 sm:p-6"
            role="dialog"
            aria-label="Notification preferences"
        >
            <div className="pointer-events-auto flex max-w-lg flex-col gap-3 rounded-xl border border-violet-200 bg-white p-4 shadow-lg dark:border-violet-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <p className="text-sm text-gray-800 dark:text-gray-100">
                    Get notified when uploads, approvals, or generative content is ready.
                </p>
                <div className="flex shrink-0 flex-col gap-2 sm:flex-row">
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => void dismissNotNow()}
                        className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        Not now
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => void enablePush()}
                        className="rounded-lg bg-violet-600 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-violet-700 disabled:opacity-50"
                    >
                        {busy ? 'Please wait…' : 'Enable notifications'}
                    </button>
                </div>
            </div>
        </div>
    )
}
