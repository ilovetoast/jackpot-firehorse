/**
 * System-style notice when the session workspace is a demo (shared prop `demo_workspace`).
 */
export default function DemoWorkspaceBanner({ demoWorkspace }) {
    if (!demoWorkspace) {
        return null
    }

    const expiresAt = demoWorkspace.expires_at
    let expiryFragment = null
    if (expiresAt) {
        try {
            const d = new Date(expiresAt)
            const dateLabel = Number.isNaN(d.getTime())
                ? expiresAt
                : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
            const days = demoWorkspace.days_remaining
            const daysLabel =
                typeof days === 'number'
                    ? days === 0
                        ? '0 days remaining'
                        : `${days} day${days === 1 ? '' : 's'} remaining`
                    : null
            expiryFragment = (
                <>
                    {' '}
                    Demo expires {dateLabel}
                    {daysLabel ? ` (${daysLabel})` : ''}.
                </>
            )
        } catch {
            expiryFragment = null
        }
    }

    return (
        <div
            className="border-b border-slate-200 bg-slate-100 px-4 py-2 text-center text-sm text-slate-800 sm:px-6"
            role="status"
            aria-live="polite"
        >
            <p className="mx-auto max-w-4xl font-medium">
                You&apos;re viewing a demo workspace. Changes may be reset after the demo expires.
                {expiryFragment}
            </p>
        </div>
    )
}
