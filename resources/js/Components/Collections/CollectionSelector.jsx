/**
 * C9.1: Custom Collection Selector Component
 * 
 * Tailwind-based, command-style dropdown selector for collections.
 * Replaces native <select> with a purpose-built component that:
 * - Supports multi-select with clear visual state
 * - Handles many collections gracefully (scrollable)
 * - Keyboard + mouse friendly
 * - Designed for future extensibility (icon/action slots)
 * - Uses portal for dropdown to avoid clipping inside modals (Bulk Edit Metadata, etc.)
 * 
 * Design intent: Similar to Tailwind UI command-style dropdowns.
 */
import { useState, useRef, useEffect } from 'react'
import { createPortal } from 'react-dom'
import { CheckIcon, ChevronDownIcon, ChevronUpIcon, GlobeAltIcon } from '@heroicons/react/24/outline'

export default function CollectionSelector({
    collections = [],
    selectedIds = [],
    onChange,
    disabled = false,
    placeholder = 'Select collections…',
    maxHeight = '200px',
    showCreateButton = false,
    onCreateClick = null,
}) {
    const [isOpen, setIsOpen] = useState(false)
    const [searchQuery, setSearchQuery] = useState('')
    const containerRef = useRef(null)
    const triggerRef = useRef(null)
    const dropdownRef = useRef(null)
    const searchInputRef = useRef(null)
    const [dropdownPosition, setDropdownPosition] = useState({ top: 0, left: 0, width: 200, openUpward: false })

    // Filter collections by search query
    const filteredCollections = collections.filter((c) =>
        c.name.toLowerCase().includes(searchQuery.toLowerCase())
    )

    // Close dropdown when clicking outside (container or portal dropdown)
    useEffect(() => {
        const handleClickOutside = (event) => {
            const inContainer = containerRef.current?.contains(event.target)
            const inDropdown = dropdownRef.current?.contains(event.target)
            if (!inContainer && !inDropdown) {
                setIsOpen(false)
                setSearchQuery('')
            }
        }

        if (isOpen) {
            document.addEventListener('mousedown', handleClickOutside)
            return () => document.removeEventListener('mousedown', handleClickOutside)
        }
    }, [isOpen])

    // Focus search input when dropdown opens
    useEffect(() => {
        if (isOpen && searchInputRef.current) {
            searchInputRef.current.focus()
        }
    }, [isOpen])

    // Calculate dropdown position for portal (avoids clipping inside modals)
    useEffect(() => {
        if (isOpen && triggerRef.current && typeof window !== 'undefined') {
            const rect = triggerRef.current.getBoundingClientRect()
            const spaceBelow = window.innerHeight - rect.bottom
            const dropdownHeight = 280
            const openUpward = spaceBelow < dropdownHeight && rect.top > spaceBelow
            setDropdownPosition({
                top: openUpward ? rect.top - dropdownHeight : rect.bottom + 4,
                left: rect.left,
                width: rect.width,
                openUpward,
            })
        }
    }, [isOpen])

    const handleToggle = (collectionId) => {
        if (disabled) return

        const isSelected = selectedIds.includes(collectionId)
        const newSelectedIds = isSelected
            ? selectedIds.filter((id) => id !== collectionId)
            : [...selectedIds, collectionId]
        
        onChange(newSelectedIds)
    }

    const handleKeyDown = (e, collectionId) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault()
            handleToggle(collectionId)
        }
    }

    const selectedCount = selectedIds.length
    const selectedNames = collections
        .filter((c) => selectedIds.includes(c.id))
        .map((c) => c.name)
        .join(', ')

    const dropdownContent = isOpen && typeof window !== 'undefined' && createPortal(
        <>
            <div
                className="fixed inset-0 z-[9998]"
                onClick={() => { setIsOpen(false); setSearchQuery('') }}
                aria-hidden="true"
            />
            <div
                ref={dropdownRef}
                className="fixed z-[9999] rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 flex flex-col"
                style={{
                    top: dropdownPosition.top,
                    left: dropdownPosition.left,
                    width: dropdownPosition.width,
                    maxHeight: maxHeight || '300px',
                }}
            >
                <div className="p-2 border-b border-gray-200 flex-shrink-0">
                    <input
                        ref={searchInputRef}
                        type="text"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        placeholder="Search collections…"
                        className="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                </div>
                <div
                    className="overflow-y-auto flex-1"
                    role="listbox"
                    aria-label="Collections"
                    style={{ maxHeight: showCreateButton && onCreateClick ? `calc(${maxHeight || '300px'} - 120px)` : `calc(${maxHeight || '300px'} - 60px)` }}
                >
                    {filteredCollections.length === 0 ? (
                        <div className="px-4 py-3 text-sm text-gray-500 text-center">
                            {searchQuery ? 'No collections found' : 'No collections available'}
                        </div>
                    ) : (
                        filteredCollections.map((collection) => {
                            const isSelected = selectedIds.includes(collection.id)
                            return (
                                <div
                                    key={collection.id}
                                    role="option"
                                    aria-selected={isSelected}
                                    tabIndex={0}
                                    onClick={() => handleToggle(collection.id)}
                                    onKeyDown={(e) => handleKeyDown(e, collection.id)}
                                    className={`relative cursor-pointer select-none px-4 py-2 text-sm hover:bg-gray-50 focus:bg-gray-50 focus:outline-none ${
                                        isSelected ? 'bg-indigo-50' : ''
                                    }`}
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center flex-1 min-w-0">
                                            <span
                                                className={`mr-3 flex-shrink-0 ${
                                                    isSelected ? 'text-indigo-600' : 'text-gray-400'
                                                }`}
                                            >
                                                {isSelected ? (
                                                    <CheckIcon className="h-5 w-5" aria-hidden="true" />
                                                ) : (
                                                    <div className="h-5 w-5 border-2 border-gray-300 rounded" />
                                                )}
                                            </span>
                                            <span
                                                className={`block truncate ${
                                                    isSelected ? 'font-medium text-indigo-900' : 'text-gray-900'
                                                }`}
                                            >
                                                {collection.name}
                                            </span>
                                            {collection.is_public && (
                                                <GlobeAltIcon
                                                    className="h-4 w-4 flex-shrink-0 text-gray-400 ml-1.5"
                                                    title="Public collection"
                                                    aria-hidden="true"
                                                />
                                            )}
                                        </div>
                                        {collection.icon && (
                                            <span className="ml-2 flex-shrink-0 text-gray-400">{collection.icon}</span>
                                        )}
                                        {collection.action && (
                                            <span className="ml-2 flex-shrink-0 text-gray-400">{collection.action}</span>
                                        )}
                                    </div>
                                </div>
                            )
                        })
                    )}
                </div>
                {showCreateButton && onCreateClick && (
                    <div className="border-t border-gray-200 p-2 flex-shrink-0 bg-white">
                        <button
                            type="button"
                            onClick={() => { setIsOpen(false); onCreateClick() }}
                            className="w-full text-left px-3 py-2 text-sm text-indigo-600 hover:bg-indigo-50 rounded-md focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        >
                            + Create new collection
                        </button>
                    </div>
                )}
            </div>
        </>,
        document.body
    )

    return (
        <div ref={containerRef} className="relative">
            {/* Trigger Button */}
            <button
                ref={triggerRef}
                type="button"
                onClick={() => !disabled && setIsOpen(!isOpen)}
                disabled={disabled}
                className="relative w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-left shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <span className="block truncate">
                    {selectedCount === 0
                        ? placeholder
                        : selectedCount === 1
                        ? selectedNames
                        : `${selectedCount} collections selected`}
                </span>
                <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                    {isOpen ? (
                        <ChevronUpIcon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                    ) : (
                        <ChevronDownIcon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                    )}
                </span>
            </button>

            {dropdownContent}

            {/* Selected Collections Summary (when multiple selected) */}
            {selectedCount > 1 && (
                <p className="mt-1 text-xs text-gray-500">
                    {selectedNames.length > 60 ? `${selectedNames.substring(0, 60)}...` : selectedNames}
                </p>
            )}
        </div>
    )
}
