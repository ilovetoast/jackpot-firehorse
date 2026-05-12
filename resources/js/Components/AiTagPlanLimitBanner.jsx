/**
 * In-app notice when accepting a tag hits the workspace plan cap (tags_per_asset).
 * Replaces window.alert for a consistent drawer-safe UI.
 */
import { XMarkIcon } from '@heroicons/react/24/outline'

/**
 * @param {object} props
 * @param {{ message?: string, maxAllowed?: number, currentCount?: number } | null} props.notice
 * @param {() => void} props.onDismiss
 * @param {string} [props.primaryColor]
 */
export default function AiTagPlanLimitBanner({ notice, onDismiss, primaryColor = '#6366f1' }) {
    if (!notice) {
        return null
    }
    const max = notice.maxAllowed
    const cur = notice.currentCount
    const detail =
        max != null && cur != null
            ? `This workspace allows up to ${max} tag${max === 1 ? '' : 's'} per asset. You currently have ${cur}.`
            : null

    return (
        <div
            role="alert"
            className="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 shadow-sm"
            style={{ borderLeftWidth: 4, borderLeftStyle: 'solid', borderLeftColor: primaryColor }}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 flex-1 space-y-1">
                    <p className="font-semibold leading-snug">Tag limit reached</p>
                    {notice.message ? <p className="leading-snug text-amber-900/95">{notice.message}</p> : null}
                    {detail ? <p className="text-xs leading-snug text-amber-900/85">{detail}</p> : null}
                    <p className="text-xs leading-snug text-amber-900/80">
                        Upgrade your plan on the billing page to raise per-asset tags, or remove an existing tag before
                        accepting this suggestion.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={onDismiss}
                    className="shrink-0 rounded p-1 text-amber-800 hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-1"
                    aria-label="Dismiss"
                >
                    <XMarkIcon className="h-4 w-4" />
                </button>
            </div>
        </div>
    )
}
