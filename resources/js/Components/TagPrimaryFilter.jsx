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

import { useState, useRef, useEffect, useMemo, useCallback } from 'react'
import { XMarkIcon, TagIcon } from '@heroicons/react/24/outline'

export default function TagPrimaryFilter({
    value = [],
    onChange = () => {},
    tenantId = null,
    placeholder = "Filter by tags...",
    className = "",
    compact = true,
    fullWidth = false
}) {
    const [inputValue, setInputValue] = useState('')
    const [suggestions, setSuggestions] = useState([])
    const [showSuggestions, setShowSuggestions] = useState(false)
    const [selectedIndex, setSelectedIndex] = useState(-1)
    const [loadingSuggestions, setLoadingSuggestions] = useState(false)
    const inputRef = useRef(null)

    // Ensure value is always an array (memoized to prevent recreation)
    const selectedTags = useMemo(() => Array.isArray(value) ? value : [], [value])

    // Fetch tag suggestions (used tags when q is empty, search when q has 2+ chars)
    const fetchSuggestions = useCallback(async (q) => {
        const tid = tenantId ?? undefined
        if (!tid) return []
        setLoadingSuggestions(true)
        try {
            const url = `/app/api/tenants/${tid}/tags/autocomplete?q=${encodeURIComponent(q)}`
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                credentials: 'same-origin',
            })
            if (!response.ok) return []
            const data = await response.json()
            const list = Array.isArray(data.suggestions) ? data.suggestions : []
            return list.filter(suggestion => suggestion && !selectedTags.includes(suggestion.tag))
        } catch (error) {
            console.error('[TagPrimaryFilter] Autocomplete failed:', error)
            return []
        } finally {
            setLoadingSuggestions(false)
        }
    }, [tenantId, selectedTags])

    // On focus with empty input: show list of used tags
    const handleFocus = useCallback(() => {
        const trimmed = inputValue.trim()
        if (trimmed.length >= 2) {
            if (suggestions.length > 0) setShowSuggestions(true)
            return
        }
        if (loadingSuggestions) return
        const tid = tenantId ?? undefined
        if (!tid) return
        setLoadingSuggestions(true)
        fetch(`/app/api/tenants/${tid}/tags/autocomplete?q=`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            credentials: 'same-origin',
        })
            .then((res) => res.ok ? res.json() : { suggestions: [] })
            .then((data) => {
                const list = Array.isArray(data.suggestions) ? data.suggestions : []
                const filtered = list.filter(s => s && !selectedTags.includes(s.tag))
                setSuggestions(filtered)
                setShowSuggestions(true)
                setSelectedIndex(-1)
            })
            .catch(() => setSuggestions([]))
            .finally(() => setLoadingSuggestions(false))
    }, [inputValue, tenantId, selectedTags, suggestions.length, loadingSuggestions])

    // Debounced autocomplete when user types (2+ chars)
    useEffect(() => {
        const trimmed = inputValue.trim()
        if (trimmed.length < 2) {
            if (trimmed.length === 0) {
                setSuggestions([])
                setShowSuggestions(false)
            }
            return
        }

        const timeoutId = setTimeout(() => {
            fetchSuggestions(trimmed).then((filteredSuggestions) => {
                setSuggestions(filteredSuggestions)
                setShowSuggestions(true)
                setSelectedIndex(-1)
            })
        }, 200)

        return () => clearTimeout(timeoutId)
    }, [inputValue, tenantId, selectedTags, fetchSuggestions])

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

                {/* Single border only (no inner outline): one visible box; primary bar: match height of other selects */}
                <div className={`flex items-center flex-wrap gap-1 px-2 rounded-md bg-white text-xs border border-gray-300 focus-within:border-indigo-500 focus-within:ring-0 transition-colors ${fullWidth ? 'w-full min-w-0 max-w-[220px] py-1' : 'w-[140px] max-w-[140px] min-w-[100px] min-h-[1.75rem] py-1 shadow-sm'}`}>
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

                    {/* Input - no border/outline/ring so only the wrapper shows one box */}
                    <input
                        ref={inputRef}
                        type="text"
                        value={inputValue}
                        onChange={(e) => setInputValue(e.target.value)}
                        onKeyDown={handleKeyDown}
                        onBlur={handleBlur}
                        onFocus={handleFocus}
                        placeholder={selectedTags.length > 0 ? "Add more..." : placeholder}
                        className="flex-1 min-w-[3rem] bg-transparent text-xs placeholder-gray-400 border-none outline-none ring-0 focus:border-none focus:outline-none focus:ring-0 focus:ring-offset-0 appearance-none"
                        style={{ boxShadow: 'none', minWidth: '3rem' }}
                        aria-label="Tag filter input"
                        aria-expanded={showSuggestions}
                        aria-activedescendant={selectedIndex >= 0 ? `tag-primary-suggestion-${selectedIndex}` : undefined}
                    />
                </div>
            </div>

            {/* Autocomplete suggestions - width matches input container; show loading when fetching used tags */}
            {showSuggestions && (
                <div className={`absolute z-50 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-auto ${fullWidth ? 'w-full min-w-0' : 'w-[200px] min-w-[140px]'}`}>
                    {loadingSuggestions && suggestions.length === 0 ? (
                        <div className="px-3 py-2 text-sm text-gray-500">Loading tags...</div>
                    ) : suggestions.length === 0 ? (
                        <div className="px-3 py-2 text-sm text-gray-500">No tags in use yet</div>
                    ) : (
                        suggestions.map((suggestion, index) => (
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
                    ))
                    )}
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