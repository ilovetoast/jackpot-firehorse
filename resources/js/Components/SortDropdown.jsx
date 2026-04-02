/**
 * SortDropdown Component
 *
 * Tailwind-style dropdown for sort criteria (radio options) with direction at bottom.
 * Similar to Shopify-style sort UI: criteria list + separator + Oldest/Newest first.
 *
 * @param {Object} props
 * @param {string} props.sortBy - Current sort field
 * @param {string} props.sortDirection - asc | desc
 * @param {Function} props.onSortChange - (sortBy, sortDirection) => void
 * @param {Array} props.options - [{ value, label }] - sort criteria
 * @param {boolean} [props.showComplianceFilter] - If true, include compliance options
 * @param {string} [props.className] - Additional classes for container
 */
import { useState, useRef, useEffect, useLayoutEffect } from 'react'
import { createPortal } from 'react-dom'
import { usePage } from '@inertiajs/react'
import { BarsArrowUpIcon, BarsArrowDownIcon } from '@heroicons/react/24/outline'

const MENU_WIDTH_PX = 224 // matches w-56
const MENU_VIEWPORT_PAD = 8

const SORT_OPTIONS = [
    { value: 'featured', label: 'Featured', tooltip: 'Starred items first, then by upload date' },
    { value: 'created', label: 'Created', tooltip: 'Upload date' },
    { value: 'quality', label: 'Quality', tooltip: 'Asset quality score' },
    { value: 'modified', label: 'Modified', tooltip: 'Last modified date' },
    { value: 'alphabetical', label: 'Alphabetical', tooltip: 'A–Z by title' },
    { value: 'most_downloaded', label: 'Most Downloaded', tooltip: 'Total download count' },
    { value: 'most_viewed', label: 'Most Viewed', tooltip: 'Total view count' },
    { value: 'trending', label: 'Trending', tooltip: 'Views + downloads combined, recent weighted higher (14 days)' },
]

const COMPLIANCE_OPTIONS = [
    { value: 'compliance_high', label: 'Highest Brand Score', tooltip: 'Best brand compliance first' },
    { value: 'compliance_low', label: 'Lowest Brand Score', tooltip: 'Needs attention first' },
]

export default function SortDropdown({
    sortBy = 'created',
    sortDirection = 'desc',
    onSortChange = () => {},
    showComplianceFilter = false,
    primaryColor,
    className = '',
}) {
    const { auth } = usePage().props
    const accent = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    const [isOpen, setIsOpen] = useState(false)
    const [menuPos, setMenuPos] = useState({ top: 0, left: 0 })
    const containerRef = useRef(null)
    const triggerRef = useRef(null)
    const menuRef = useRef(null)

    const allOptions = [...SORT_OPTIONS]
    if (showComplianceFilter) {
        allOptions.push(...COMPLIANCE_OPTIONS)
    }

    const updateMenuPosition = () => {
        const el = triggerRef.current
        if (!el) return
        const rect = el.getBoundingClientRect()
        let left = rect.right - MENU_WIDTH_PX
        left = Math.max(
            MENU_VIEWPORT_PAD,
            Math.min(left, window.innerWidth - MENU_WIDTH_PX - MENU_VIEWPORT_PAD)
        )
        setMenuPos({ top: rect.bottom + 4, left })
    }

    useLayoutEffect(() => {
        if (!isOpen) return
        updateMenuPosition()
        const onScrollOrResize = () => updateMenuPosition()
        window.addEventListener('resize', onScrollOrResize)
        document.addEventListener('scroll', onScrollOrResize, true)
        return () => {
            window.removeEventListener('resize', onScrollOrResize)
            document.removeEventListener('scroll', onScrollOrResize, true)
        }
    }, [isOpen])

    useEffect(() => {
        const handleClickOutside = (e) => {
            const t = e.target
            if (containerRef.current?.contains(t) || menuRef.current?.contains(t)) return
            setIsOpen(false)
        }
        if (isOpen) {
            document.addEventListener('click', handleClickOutside)
        }
        return () => document.removeEventListener('click', handleClickOutside)
    }, [isOpen])

    const handleCriteriaSelect = (value) => {
        onSortChange(value, sortDirection)
    }

    const handleDirectionSelect = (dir) => {
        onSortChange(sortBy, dir)
    }

    const menu = isOpen && (
        <div
            ref={menuRef}
            className="fixed z-[200] w-56 rounded-lg border border-gray-200 bg-white py-1 shadow-lg max-h-[min(70vh,28rem)] overflow-y-auto"
            style={{ top: menuPos.top, left: menuPos.left }}
            role="listbox"
        >
                    <div className="px-3 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Sort by
                    </div>
                    {allOptions.map((opt) => (
                        <button
                            key={opt.value}
                            type="button"
                            role="option"
                            aria-selected={sortBy === opt.value}
                            aria-label={opt.tooltip ? `${opt.label}: ${opt.tooltip}` : opt.label}
                            title={opt.tooltip}
                            onClick={() => handleCriteriaSelect(opt.value)}
                            className={`w-full px-3 py-2 text-left text-sm flex items-center gap-2 ${
                                sortBy === opt.value
                                    ? 'bg-gray-50 text-gray-900 font-medium'
                                    : 'text-gray-700 hover:bg-gray-50'
                            }`}
                        >
                            <span
                                className={`w-4 h-4 rounded-full border flex items-center justify-center flex-shrink-0 ${sortBy !== opt.value ? 'border-gray-300' : ''}`}
                                style={sortBy === opt.value ? { borderColor: accent } : {}}
                            >
                                {sortBy === opt.value && (
                                    <span className="w-2 h-2 rounded-full" style={{ backgroundColor: accent }} />
                                )}
                            </span>
                            {opt.label}
                        </button>
                    ))}

                    <div className="border-t border-gray-200 my-1" />

                    <div className="px-3 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Order
                    </div>
                    <button
                        type="button"
                        onClick={() => handleDirectionSelect('asc')}
                        className={`w-full px-3 py-2 text-left text-sm flex items-center gap-2 ${
                            sortDirection === 'asc' ? 'bg-gray-50 font-medium' : ''
                        } hover:bg-gray-50`}
                    >
                        <BarsArrowUpIcon className="h-4 w-4 text-gray-500" />
                        Ascending
                    </button>
                    <button
                        type="button"
                        onClick={() => handleDirectionSelect('desc')}
                        className={`w-full px-3 py-2 text-left text-sm flex items-center gap-2 ${
                            sortDirection === 'desc' ? 'bg-gray-50 font-medium' : ''
                        } hover:bg-gray-50`}
                    >
                        <BarsArrowDownIcon className="h-4 w-4 text-gray-500" />
                        Descending
                    </button>
        </div>
    )

    return (
        <div ref={containerRef} className={`relative ${className}`} style={{ '--sort-accent': accent }}>
            <button
                ref={triggerRef}
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="inline-flex h-9 min-h-[2.25rem] min-w-9 items-center justify-center rounded border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--sort-accent)]"
                aria-label="Sort"
                aria-expanded={isOpen}
                aria-haspopup="listbox"
                title="Sort"
            >
                <BarsArrowDownIcon className="h-4 w-4 shrink-0" />
            </button>

            {typeof document !== 'undefined' && menu ? createPortal(menu, document.body) : null}
        </div>
    )
}
