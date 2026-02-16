/**
 * Option Icon Selector
 * Small predefined set of icons for metadata option customization.
 */

import {
    CheckCircleIcon,
    XCircleIcon,
    StarIcon,
    HeartIcon,
    FlagIcon,
    TagIcon,
    BookmarkIcon,
    LightBulbIcon,
    SparklesIcon,
    FireIcon,
    BoltIcon,
    TrophyIcon,
} from '@heroicons/react/24/outline'

export const OPTION_ICONS = [
    { value: '', label: 'None', Icon: null },
    { value: 'check-circle', label: 'Check', Icon: CheckCircleIcon },
    { value: 'x-circle', label: 'X', Icon: XCircleIcon },
    { value: 'star', label: 'Star', Icon: StarIcon },
    { value: 'heart', label: 'Heart', Icon: HeartIcon },
    { value: 'flag', label: 'Flag', Icon: FlagIcon },
    { value: 'tag', label: 'Tag', Icon: TagIcon },
    { value: 'bookmark', label: 'Bookmark', Icon: BookmarkIcon },
    { value: 'light-bulb', label: 'Light', Icon: LightBulbIcon },
    { value: 'sparkles', label: 'Sparkles', Icon: SparklesIcon },
    { value: 'fire', label: 'Fire', Icon: FireIcon },
    { value: 'bolt', label: 'Bolt', Icon: BoltIcon },
    { value: 'trophy', label: 'Trophy', Icon: TrophyIcon },
]

/**
 * Render an icon by name (for display in chips, filters, etc.)
 */
export function OptionIcon({ icon, className = 'h-4 w-4' }) {
    if (!icon) return null
    const entry = OPTION_ICONS.find((i) => i.value === icon)
    if (!entry?.Icon) return null
    const IconComponent = entry.Icon
    return <IconComponent className={className} aria-hidden />
}

/**
 * Icon selector dropdown for option editor
 */
export default function OptionIconSelector({ value, onChange, className = '' }) {
    return (
        <select
            value={value || ''}
            onChange={(e) => onChange(e.target.value || null)}
            className={`rounded-md border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500 ${className}`}
            title="Optional icon"
        >
            {OPTION_ICONS.map(({ value: v, label }) => (
                <option key={v || 'none'} value={v}>
                    {label}
                </option>
            ))}
        </select>
    )
}
