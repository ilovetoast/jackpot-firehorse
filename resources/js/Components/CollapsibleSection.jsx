/**
 * Collapsible Section Component
 *
 * Reusable component for collapsible sections in the asset drawer
 */

import { useState } from 'react'
import { ChevronDownIcon, ChevronUpIcon } from '@heroicons/react/24/outline'

export default function CollapsibleSection({
    title,
    defaultExpanded = true,
    children,
    className = '',
    onToggle,
    /** 'dark' = light text on dark bg (e.g. lightbox details column) */
    variant = 'default',
    /** When true with dark variant, the section title + chevron sit inside the bordered card */
    titleInCard = false,
    /**
     * 'padded' = horizontal px on trigger/content (standalone).
     * 'flush' = no horizontal padding — use when parent already provides px (e.g. asset drawer body).
     */
    contentInset = 'padded',
}) {
    const [isExpanded, setIsExpanded] = useState(defaultExpanded)

    const handleToggle = () => {
        const next = !isExpanded
        setIsExpanded(next)
        onToggle?.(next)
    }

    const isDark = variant === 'dark'
    const inCard = isDark && titleInCard

    const flush = contentInset === 'flush' && !inCard
    const btnPad = inCard ? 'px-4 py-3' : isDark ? 'px-5 py-3.5' : flush ? 'px-0 py-3' : 'px-4 py-3'
    const contentPad = inCard ? 'px-4 pb-4 pt-0' : isDark ? 'px-5 pb-4' : flush ? 'px-0 pb-3' : 'px-4 pb-3'

    const shellClass = inCard
        ? 'rounded-lg border border-neutral-800/70 bg-neutral-900/85 mb-4 overflow-hidden'
        : ''

    return (
        <div className={`${shellClass} ${className}`}>
            <button
                type="button"
                onClick={handleToggle}
                className={`w-full flex items-center justify-between ${btnPad} text-left focus:outline-none focus:ring-2 focus:ring-inset ${
                    isExpanded && !inCard ? 'mb-3' : ''
                } ${
                    inCard
                        ? 'border-b border-neutral-800/80 hover:bg-neutral-800/50 text-neutral-100 focus:ring-neutral-600'
                        : isDark
                          ? 'hover:bg-neutral-800/40 text-neutral-100 focus:ring-neutral-600'
                          : 'hover:bg-gray-50 focus:ring-indigo-500'
                }`}
            >
                {typeof title === 'string' ? (
                    <h3 className={`text-sm font-semibold ${isDark ? 'text-neutral-100' : 'text-gray-900'}`}>{title}</h3>
                ) : (
                    <div
                        className={`flex min-w-0 flex-1 items-center text-sm font-semibold ${isDark ? 'text-neutral-100' : 'text-gray-900'}`}
                    >
                        {title}
                    </div>
                )}
                {isExpanded ? (
                    <ChevronUpIcon className={`h-4 w-4 ${isDark ? 'text-neutral-500' : 'text-gray-500'}`} />
                ) : (
                    <ChevronDownIcon className={`h-4 w-4 ${isDark ? 'text-neutral-500' : 'text-gray-500'}`} />
                )}
            </button>

            {isExpanded && <div className={contentPad}>{children}</div>}
        </div>
    )
}
