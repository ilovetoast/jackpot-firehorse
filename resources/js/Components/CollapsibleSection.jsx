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
    className = "" 
}) {
    const [isExpanded, setIsExpanded] = useState(defaultExpanded)

    return (
        <div className={`${className}`}>
            <button
                type="button"
                onClick={() => setIsExpanded(!isExpanded)}
                className="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-inset"
            >
                {typeof title === 'string' ? (
                    <h3 className="text-sm font-medium text-gray-900">{title}</h3>
                ) : (
                    <div className="text-sm font-medium text-gray-900">{title}</div>
                )}
                {isExpanded ? (
                    <ChevronUpIcon className="h-4 w-4 text-gray-500" />
                ) : (
                    <ChevronDownIcon className="h-4 w-4 text-gray-500" />
                )}
            </button>
            
            {isExpanded && (
                <div className="px-4 pb-3">
                    {children}
                </div>
            )}
        </div>
    )
}