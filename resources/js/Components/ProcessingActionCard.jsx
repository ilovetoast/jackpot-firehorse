/**
 * Card-style control for Processing & Automation (drawer + bulk modal).
 * Matches BulkActionsModal pipeline card visuals: rounded-xl, shadow, icon well.
 */
import {
    ArrowPathIcon,
    PhotoIcon,
    SparklesIcon,
    TrashIcon,
    VideoCameraIcon,
} from '@heroicons/react/24/outline'

const ICONS = {
    sparkles: { Icon: SparklesIcon, box: 'bg-violet-100', color: 'text-violet-700' },
    photo: { Icon: PhotoIcon, box: 'bg-indigo-100', color: 'text-indigo-700' },
    video: { Icon: VideoCameraIcon, box: 'bg-sky-100', color: 'text-sky-700' },
    refresh: { Icon: ArrowPathIcon, box: 'bg-emerald-100', color: 'text-emerald-700' },
    refreshDanger: { Icon: ArrowPathIcon, box: 'bg-red-100', color: 'text-red-700' },
    trash: { Icon: TrashIcon, box: 'bg-red-100', color: 'text-red-700' },
}

/**
 * @param {Object} props
 * @param {'sparkles'|'photo'|'video'|'refresh'|'refreshDanger'|'trash'} props.icon
 * @param {string} props.title
 * @param {string} props.description
 * @param {() => void} props.onClick
 * @param {boolean} [props.disabled]
 * @param {'default'|'danger'} [props.variant]
 * @param {import('react').ReactNode} [props.footer]
 * @param {boolean} [props.loading]
 * @param {string} [props.buttonTitle] — native tooltip (do not confuse with visible `title`)
 */
export default function ProcessingActionCard({
    icon,
    title,
    description,
    onClick,
    disabled = false,
    variant = 'default',
    footer = null,
    loading = false,
    buttonTitle,
}) {
    const preset = ICONS[icon] || ICONS.sparkles
    const { Icon, box, color } = preset

    const isDanger = variant === 'danger'

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled || loading}
            title={buttonTitle}
            className={[
                'w-full text-left rounded-xl transition-all disabled:cursor-not-allowed disabled:opacity-50',
                'p-3 shadow-sm hover:translate-y-[-1px] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500',
                isDanger
                    ? 'border border-red-200 bg-red-50 hover:bg-red-100'
                    : 'border border-gray-200 bg-white hover:bg-gray-50/80',
            ]
                .filter(Boolean)
                .join(' ')}
        >
            <div className="flex items-start gap-3">
                <span
                    className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${box}`}
                    aria-hidden
                >
                    {loading ? (
                        <ArrowPathIcon className={`h-5 w-5 animate-spin ${color}`} />
                    ) : (
                        <Icon className={`h-5 w-5 ${color}`} />
                    )}
                </span>
                <div className="min-w-0 flex-1">
                    <span
                        className={`block text-sm font-medium ${isDanger ? 'text-red-900' : 'text-gray-900'}`}
                    >
                        {title}
                    </span>
                    <span
                        className={`mt-0.5 block text-xs leading-snug ${
                            isDanger ? 'text-red-800/90' : 'text-gray-500'
                        }`}
                    >
                        {description}
                    </span>
                </div>
            </div>
            {footer != null && footer !== false && (
                <div className="mt-1 border-t border-gray-100 pt-1 text-[11px] text-gray-400">{footer}</div>
            )}
        </button>
    )
}

/**
 * @param {string|null|undefined} iso
 * @param {(s: string) => string|null|undefined} formatIso
 * @returns {string}
 */
export function formatProcessingLastRunLine(iso, formatIso) {
    if (!iso) {
        return ''
    }
    const formatted = formatIso(iso)
    return formatted ? `Last run: ${formatted}` : ''
}
