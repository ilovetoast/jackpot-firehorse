/**
 * TagInputUnified Component
 * 
 * Phase J.2.8: Unified, reusable tag input component
 * 
 * Features:
 * - Works in all contexts: upload, asset drawer, asset details, filters
 * - Autocomplete existing canonical tags (tenant-scoped)
 * - Freeform entry for new tags
 * - Multiple tags as pills/badges
 * - Enter/comma to add, backspace to remove last
 * - Debounced search, keyboard accessible
 * - Configurable for different use cases
 * 
 * Modes:
 * - 'asset': Post-asset creation (uses /api/assets/{id}/tags endpoints)
 * - 'upload': Pre-asset creation (uses tenant autocomplete, local storage)
 * - 'filter': For primary filter rendering (values only, no API calls)
 */

import { useState, useRef, useEffect, useMemo, useCallback } from 'react'
import { XMarkIcon, TagIcon } from '@heroicons/react/24/outline'

export default function TagInputUnified({ 
    // Mode configuration
    mode = 'asset', // 'asset' | 'upload' | 'filter'
    
    // Asset mode props
    assetId = null,
    onTagAdded = () => {},
    onTagRemoved = () => {},
    
    // Upload/Filter mode props  
    value = [],
    onChange = () => {},
    tenantId = null,
    
    // Common props
    placeholder = "Add tags...",
    className = "",
    disabled = false,
    maxTags = null,
    
    // UI customization
    showTitle = false,
    title = "Tags",
    showCounter = false,
    compact = false,
    inline = false, // For filter mode - pills inline with input
    
    // Accessibility
    ariaLabel = "Tag input"
}) {
    const [inputValue, setInputValue] = useState('')
    const [suggestions, setSuggestions] = useState([])
    const [showSuggestions, setShowSuggestions] = useState(false)
    const [selectedIndex, setSelectedIndex] = useState(-1)
    const [loading, setLoading] = useState(false)
    const [localTags, setLocalTags] = useState(value) // For upload/filter mode
    
    const inputRef = useRef(null)
    const suggestionsRef = useRef(null)

    // Update local tags when value prop changes (for controlled components)
    useEffect(() => {
        if (mode === 'upload' || mode === 'filter') {
            setLocalTags(value)
        }
    }, [value, mode])

    // Get autocomplete URL based on mode
    const getAutocompleteUrl = (query) => {
        switch (mode) {
                case 'asset':
                    return `/app/api/assets/${assetId}/tags/autocomplete?q=${encodeURIComponent(query)}`
                case 'upload':
                case 'filter':
                    return `/app/api/tenants/${tenantId}/tags/autocomplete?q=${encodeURIComponent(query)}`
            default:
                return null
        }
    }

    // Filter out already selected tags from suggestions (memoized to prevent stale closures)
    const filterExistingSuggestions = useCallback((suggestions) => {
        const existingTags = mode === 'asset' ? [] : localTags
        return suggestions.filter(suggestion => !existingTags.includes(suggestion.tag))
    }, [mode, localTags])

    // Debounced autocomplete
    useEffect(() => {
        if (!inputValue.trim() || inputValue.length < 2) {
            setSuggestions([])
            setShowSuggestions(false)
            return
        }

        // Don't autocomplete in filter mode if API is not available
        const url = getAutocompleteUrl(inputValue)
        if (!url) return

        const timeoutId = setTimeout(async () => {
            try {
                const response = await fetch(url, {
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    credentials: 'same-origin',
                })

                if (response.ok) {
                    const data = await response.json()
                    const filtered = filterExistingSuggestions(data.suggestions || [])
                    setSuggestions(filtered)
                    setShowSuggestions(true)
                    setSelectedIndex(-1)
                }
            } catch (error) {
                console.error('[TagInputUnified] Autocomplete failed:', error)
            }
        }, 300) // 300ms debounce

        return () => clearTimeout(timeoutId)
    }, [inputValue, assetId, tenantId, mode, filterExistingSuggestions])

    // Handle tag creation/addition
    const addTag = async (tagValue) => {
        if (!tagValue.trim() || loading) return
        
        // Check max tags limit
        if (maxTags && ((mode === 'asset' ? 0 : localTags.length) >= maxTags)) return

        const trimmedTag = tagValue.trim()

        if (mode === 'asset') {
            // Asset mode: API call to create tag
            setLoading(true)

            try {
                const response = await fetch(`/app/api/assets/${assetId}/tags`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ tag: trimmedTag }),
                })

                if (response.ok) {
                    const data = await response.json()
                    onTagAdded(data.tag)
                    clearInput()
                } else {
                    const errorData = await response.json()
                    if (response.status === 409) {
                        // Tag already exists - just clear input
                        clearInput()
                    } else if (response.status === 403 && errorData.limit_type === 'tags_per_asset') {
                        // Plan limit exceeded - show detailed message with upgrade suggestion
                        alert(`${errorData.message}\n\nUpgrade your plan to add more tags per asset. Visit the billing page to see available plans.`)
                    } else {
                        alert(errorData.message || 'Failed to add tag')
                    }
                }
            } catch (error) {
                console.error('[TagInputUnified] Tag creation failed:', error)
                alert('Failed to add tag. Please try again.')
            } finally {
                setLoading(false)
            }
        } else {
            // Upload/Filter mode: Local state management
            if (!localTags.includes(trimmedTag)) {
                const newTags = [...localTags, trimmedTag]
                setLocalTags(newTags)
                onChange(newTags)
                clearInput()
            } else {
                clearInput()
            }
        }
    }

    // Remove tag
    const removeTag = async (tagToRemove, tagId = null) => {
        if (mode === 'asset' && tagId) {
            // Asset mode: API call to remove tag
            try {
                const response = await fetch(`/api/assets/${assetId}/tags/${tagId}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    credentials: 'same-origin',
                })

                if (response.ok) {
                    onTagRemoved({ id: tagId, tag: tagToRemove })
                } else {
                    const errorData = await response.json()
                    alert(errorData.message || 'Failed to remove tag')
                }
            } catch (error) {
                console.error('[TagInputUnified] Tag removal failed:', error)
                alert('Failed to remove tag. Please try again.')
            }
        } else {
            // Upload/Filter mode: Local state management
            const newTags = localTags.filter(tag => tag !== tagToRemove)
            setLocalTags(newTags)
            onChange(newTags)
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
        } else if (e.key === 'Backspace' && !inputValue && localTags.length > 0) {
            // Remove last tag if input is empty
            e.preventDefault()
            const lastTag = localTags[localTags.length - 1]
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

    // Get display tags based on mode
    const displayTags = mode === 'asset' ? [] : localTags
    const isAtMaxTags = maxTags && displayTags.length >= maxTags

    return (
        <div className={className}>
            {/* Title */}
            {showTitle && (
                <div className="flex items-center gap-2 mb-2">
                    <TagIcon className={`text-gray-500 ${compact ? 'h-4 w-4' : 'h-5 w-5'}`} />
                    <label className={`block font-medium text-gray-700 ${compact ? 'text-sm' : 'text-base'}`}>
                        {title}
                    </label>
                    {showCounter && displayTags.length > 0 && (
                        <span className={`text-gray-500 ${compact ? 'text-xs' : 'text-sm'}`}>
                            ({displayTags.length}{maxTags ? `/${maxTags}` : ''})
                        </span>
                    )}
                </div>
            )}

            {/* Tag display and input container */}
            <div className={`${inline ? 'flex flex-wrap items-center gap-2' : ''}`}>
                {/* Existing tags (for upload/filter mode) */}
                {displayTags.length > 0 && (
                    <div className={`flex flex-wrap gap-2 ${inline ? '' : 'mb-2'}`}>
                        {displayTags.map((tag, index) => (
                            <div
                                key={`${tag}-${index}`}
                                className={`inline-flex items-center gap-1 bg-gray-100 border border-gray-300 rounded-lg text-gray-900 ${
                                    compact ? 'px-1.5 py-0.5 text-xs' : 'px-1.5 py-0.5 text-xs'
                                }`}
                            >
                                <span className="font-medium">{tag.charAt(0).toUpperCase() + tag.slice(1)}</span>
                                {!disabled && (
                                    <button
                                        type="button"
                                        onClick={() => removeTag(tag)}
                                        className="inline-flex items-center p-0.5 text-gray-600 hover:text-gray-800 rounded focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-1"
                                        title="Remove tag"
                                        aria-label={`Remove tag "${tag}"`}
                                    >
                                        <XMarkIcon className="h-2.5 w-2.5" />
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                {/* Input */}
                <div className={`relative ${inline ? 'flex-1 min-w-0' : ''}`}>
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
                        placeholder={isAtMaxTags ? `Maximum ${maxTags} tags` : placeholder}
                        disabled={disabled || loading || isAtMaxTags}
                        className={`block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-500 transition-colors ${
                            compact ? 'text-sm' : 'text-sm'
                        }`}
                        aria-label={ariaLabel}
                        aria-expanded={showSuggestions}
                        aria-activedescendant={selectedIndex >= 0 ? `unified-suggestion-${selectedIndex}` : undefined}
                    />

                    {/* Loading indicator */}
                    {loading && (
                        <div className="absolute right-3 top-1/2 transform -translate-y-1/2">
                            <div className="animate-spin rounded-full h-4 w-4 border-2 border-gray-300 border-t-indigo-600"></div>
                        </div>
                    )}

                    {/* Autocomplete suggestions */}
                    {showSuggestions && suggestions.length > 0 && (
                        <div
                            ref={suggestionsRef}
                            className="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-auto"
                            role="listbox"
                        >
                            {suggestions.map((suggestion, index) => (
                                <button
                                    key={`${suggestion.tag}-${index}`}
                                    id={`unified-suggestion-${index}`}
                                    type="button"
                                    onClick={() => handleSuggestionClick(suggestion)}
                                    className={`w-full text-left px-3 py-2 text-sm hover:bg-gray-50 focus:bg-gray-50 focus:outline-none ${
                                        index === selectedIndex ? 'bg-indigo-50 text-indigo-900' : 'text-gray-900'
                                    }`}
                                    role="option"
                                    aria-selected={index === selectedIndex}
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="font-medium">{suggestion.tag.charAt(0).toUpperCase() + suggestion.tag.slice(1)}</span>
                                        <div className="flex items-center gap-2 text-xs text-gray-500">
                                            {suggestion.usage_count > 0 && (
                                                <span>{suggestion.usage_count} uses</span>
                                            )}
                                            <span className={`px-1.5 py-0.5 rounded text-xs font-medium ${
                                                suggestion.type === 'existing' 
                                                    ? 'bg-green-100 text-green-800' 
                                                    : 'bg-blue-100 text-blue-800'
                                            }`}>
                                                {suggestion.type === 'existing' ? 'Existing' : 'New'}
                                            </span>
                                        </div>
                                    </div>
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* Helper text */}
            {mode === 'upload' && !compact && (
                <div className="mt-1 text-xs text-gray-500">
                    Add tags to help with discovery. Press Enter or comma to add.
                </div>
            )}
        </div>
    )
}