/**
 * TagPrimaryFilter Component
 * 
 * Phase J.2.8: Specialized primary filter renderer for tags
 * 
 * Features:
 * - Renders as a compact searchable input (not dropdown)
 * - Supports multiple tag tokens/pills
 * - Autocomplete from canonical tags
 * - Applies filters immediately on selection
 * - Visually aligned with other Primary filters
 * 
 * This component plugs into the existing FilterFieldInput system
 * but provides tag-specific UX for Primary filter placement.
 */

import { useState, useRef, useEffect, useMemo } from 'react'
import { XMarkIcon, TagIcon } from '@heroicons/react/24/outline'

export default function TagPrimaryFilter({
    value = [],
    onChange = () => {},
    tenantId = null,
    placeholder = "Filter by tags...",
    className = "",
    compact = true
}) {
    const [inputValue, setInputValue] = useState('')
    const [suggestions, setSuggestions] = useState([])
    const [showSuggestions, setShowSuggestions] = useState(false)
    const [selectedIndex, setSelectedIndex] = useState(-1)
    const inputRef = useRef(null)

    // Ensure value is always an array (memoized to prevent recreation)
    const selectedTags = useMemo(() => Array.isArray(value) ? value : [], [value])

    // Debounced autocomplete for existing canonical tags
    useEffect(() => {
        if (!inputValue.trim() || inputValue.length < 2 || !tenantId) {
            setSuggestions([])
            setShowSuggestions(false)
            return
        }

        const timeoutId = setTimeout(async () => {
            try {
                const response = await fetch(
                    `/api/tenants/${tenantId}/tags/autocomplete?q=${encodeURIComponent(inputValue)}`,
                    {
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                        },
                        credentials: 'same-origin',
                    }
                )

                if (response.ok) {
                    const data = await response.json()
                    // Filter out already selected tags
                    const filteredSuggestions = (data.suggestions || []).filter(
                        suggestion => !selectedTags.includes(suggestion.tag)
                    )
                    setSuggestions(filteredSuggestions)
                    setShowSuggestions(true)
                    setSelectedIndex(-1)
                }
            } catch (error) {
                console.error('[TagPrimaryFilter] Autocomplete failed:', error)
            }
        }, 200) // Faster for filters (200ms vs 300ms)

        return () => clearTimeout(timeoutId)
    }, [inputValue, tenantId, selectedTags])

    // Add tag to filter
    const addTag = (tagValue) => {
        if (!tagValue.trim()) return
        
        const trimmedTag = tagValue.trim()
        
        if (!selectedTags.includes(trimmedTag)) {
            const newTags = [...selectedTags, trimmedTag]
            onChange('in', newTags) // Use 'in' operator for multiple tag filtering
            clearInput()
        } else {
            clearInput()
        }
    }

    // Remove tag from filter
    const removeTag = (tagToRemove) => {
        const newTags = selectedTags.filter(tag => tag !== tagToRemove)
        if (newTags.length > 0) {
            onChange('in', newTags)
        } else {
            onChange('in', null) // Remove filter entirely
        }
    }

    // Clear input and suggestions
    const clearInput = () => {
        setInputValue('')
        setSuggestions([])
        setShowSuggestions(false)
        setSelectedIndex(-1)
        inputRef.current?.focus()
    }

    // Handle input key events
    const handleKeyDown = (e) => {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault()
            
            if (showSuggestions && selectedIndex >= 0 && suggestions[selectedIndex]) {
                addTag(suggestions[selectedIndex].tag)
            } else if (inputValue.trim()) {
                addTag(inputValue.trim())
            }
        } else if (e.key === 'Backspace' && !inputValue && selectedTags.length > 0) {
            // Remove last tag if input is empty
            e.preventDefault()
            const lastTag = selectedTags[selectedTags.length - 1]
            removeTag(lastTag)
        } else if (e.key === 'ArrowDown') {
            e.preventDefault()
            if (showSuggestions) {
                setSelectedIndex(prev => 
                    prev < suggestions.length - 1 ? prev + 1 : prev
                )
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault()
            if (showSuggestions) {
                setSelectedIndex(prev => prev > 0 ? prev - 1 : -1)
            }
        } else if (e.key === 'Escape') {
            setShowSuggestions(false)
            setSelectedIndex(-1)
        }
    }

    // Handle suggestion click
    const handleSuggestionClick = (suggestion) => {
        addTag(suggestion.tag)
    }

    // Handle input blur
    const handleBlur = () => {
        setTimeout(() => {
            setShowSuggestions(false)
            setSelectedIndex(-1)
        }, 150)
    }

    return (
        <div className={`relative ${className}`}>
            {/* Filter container with selected tags and input */}
            <div className="relative flex items-center">
                {/* Field label (for primary filters) */}
                {!compact && (
                    <label className="flex items-center gap-1 text-sm font-medium text-gray-700 mr-2">
                        <TagIcon className="h-4 w-4 text-gray-500" />
                        Tags:
                    </label>
                )}

                {/* Tags and input container */}
                <div className="flex items-center flex-wrap gap-1 min-w-[100px] px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-500 bg-white transition-colors">
                    {/* Selected tag pills */}
                    {selectedTags.map((tag, index) => (
                        <div
                            key={`${tag}-${index}`}
                            className="inline-flex items-center gap-0.5 bg-indigo-100 border border-indigo-200 rounded text-indigo-900 px-1 py-0.5 text-xs"
                        >
                            <span className="font-medium">{tag}</span>
                            <button
                                type="button"
                                onClick={() => removeTag(tag)}
                                className="inline-flex items-center p-0.5 text-indigo-600 hover:text-indigo-800 rounded focus:outline-none"
                                title="Remove tag filter"
                                aria-label={`Remove tag filter "${tag}"`}
                            >
                                <XMarkIcon className="h-2 w-2" />
                            </button>
                        </div>
                    ))}

                    {/* Input */}
                    <input
                        ref={inputRef}
                        type="text"
                        value={inputValue}
                        onChange={(e) => setInputValue(e.target.value)}
                        onKeyDown={handleKeyDown}
                        onBlur={handleBlur}
                        onFocus={() => {
                            if (suggestions.length > 0) {
                                setShowSuggestions(true)
                            }
                        }}
                        placeholder={selectedTags.length > 0 ? "Add more..." : placeholder}
                        className="border-0 outline-0 ring-0 focus:border-0 focus:outline-0 focus:ring-0 bg-transparent text-sm flex-1 min-w-0 placeholder-gray-400"
                        style={{ boxShadow: 'none' }}
                        aria-label="Tag filter input"
                        aria-expanded={showSuggestions}
                        aria-activedescendant={selectedIndex >= 0 ? `tag-primary-suggestion-${selectedIndex}` : undefined}
                    />
                </div>
            </div>

            {/* Autocomplete suggestions */}
            {showSuggestions && suggestions.length > 0 && (
                <div className="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-auto">
                    {suggestions.map((suggestion, index) => (
                        <button
                            key={`${suggestion.tag}-${index}`}
                            id={`tag-primary-suggestion-${index}`}
                            type="button"
                            onClick={() => handleSuggestionClick(suggestion)}
                            className={`w-full text-left px-3 py-2 text-sm hover:bg-gray-50 focus:bg-gray-50 focus:outline-none ${
                                index === selectedIndex ? 'bg-indigo-50 text-indigo-900' : 'text-gray-900'
                            }`}
                            role="option"
                            aria-selected={index === selectedIndex}
                        >
                            <div className="flex items-center justify-between">
                                <span className="font-medium">{suggestion.tag}</span>
                                <div className="flex items-center gap-2 text-xs text-gray-500">
                                    {suggestion.usage_count > 0 && (
                                        <span>{suggestion.usage_count} uses</span>
                                    )}
                                    <span className="px-1.5 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                        Filter
                                    </span>
                                </div>
                            </div>
                        </button>
                    ))}
                </div>
            )}

            {/* Clear all button (when tags are selected) */}
            {selectedTags.length > 0 && (
                <button
                    type="button"
                    onClick={() => onChange('in', null)}
                    className="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 p-1"
                    title="Clear all tag filters"
                    aria-label="Clear all tag filters"
                >
                    <XMarkIcon className="h-4 w-4" />
                </button>
            )}
        </div>
    )
}