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
import { useState, useRef, useEffect } from 'react'
import { BarsArrowUpIcon, BarsArrowDownIcon } from '@heroicons/react/24/outline'

const SORT_OPTIONS = [
    { value: 'featured', label: 'Featured', tooltip: 'Manual order set by admins' },
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
    className = '',
}) {
    const [isOpen, setIsOpen] = useState(false)
    const containerRef = useRef(null)

    const allOptions = [...SORT_OPTIONS]
    if (showComplianceFilter) {
        allOptions.push(...COMPLIANCE_OPTIONS)
    }

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                setIsOpen(false)
            }
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

    return (
        <div ref={containerRef} className={`relative ${className}`}>
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="p-1.5 rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                aria-label="Sort"
                aria-expanded={isOpen}
                aria-haspopup="listbox"
                title="Sort"
            >
                <BarsArrowDownIcon className="h-4 w-4" />
            </button>

            {isOpen && (
                <div
                    className="absolute right-0 top-full mt-1 z-50 w-56 rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
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
                                className={`w-4 h-4 rounded-full border flex items-center justify-center flex-shrink-0 ${
                                    sortBy === opt.value ? 'border-indigo-600' : 'border-gray-300'
                                }`}
                            >
                                {sortBy === opt.value && (
                                    <span className="w-2 h-2 rounded-full bg-indigo-600" />
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
                        Oldest first
                    </button>
                    <button
                        type="button"
                        onClick={() => handleDirectionSelect('desc')}
                        className={`w-full px-3 py-2 text-left text-sm flex items-center gap-2 ${
                            sortDirection === 'desc' ? 'bg-gray-50 font-medium' : ''
                        } hover:bg-gray-50`}
                    >
                        <BarsArrowDownIcon className="h-4 w-4 text-gray-500" />
                        Newest first
                    </button>
                </div>
            )}
        </div>
    )
}
