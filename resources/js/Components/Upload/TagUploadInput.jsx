/**
 * TagUploadInput Component
 * 
 * Phase J.2.3: Tag input for asset upload dialog
 * 
 * Features:
 * - Pre-upload tag input (before asset exists)
 * - Autocomplete from existing canonical tags
 * - Tag normalization preview
 * - Can be used in upload metadata panels
 * - Stores tags locally until upload completes
 */

import { useState, useRef, useEffect } from 'react'
import { XMarkIcon, TagIcon } from '@heroicons/react/24/outline'

export default function TagUploadInput({ 
    value = [], 
    onChange = () => {},
    tenantId,
    placeholder = "Add tags for this upload...",
    className = "",
    disabled = false,
    showTitle = true,
    maxTags = 10
}) {
    const [inputValue, setInputValue] = useState('')
    const [suggestions, setSuggestions] = useState([])
    const [showSuggestions, setShowSuggestions] = useState(false)
    const [selectedIndex, setSelectedIndex] = useState(-1)
    const inputRef = useRef(null)

    // Debounced autocomplete for existing canonical tags
    useEffect(() => {
        if (!inputValue.trim() || inputValue.length < 2 || !tenantId) {
            setSuggestions([])
            setShowSuggestions(false)
            return
        }

        const timeoutId = setTimeout(async () => {
            try {
                // Use a dummy asset ID for autocomplete (we need tenant context)
                // This will get canonical tags from across the tenant
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
                    const filteredSuggestions = (data.suggestions || []).filter(
                        suggestion => !value.includes(suggestion.tag)
                    )
                    setSuggestions(filteredSuggestions)
                    setShowSuggestions(true)
                    setSelectedIndex(-1)
                }
            } catch (error) {
                console.error('[TagUploadInput] Autocomplete failed:', error)
                // Fallback: show normalized version as suggestion
                try {
                    const normalizedTag = inputValue.toLowerCase()
                        .trim()
                        .replace(/[^\w\s\-]/g, '')
                        .replace(/\s+/g, '-')
                        .replace(/-+/g, '-')
                        .replace(/^-|-$/g, '')
                    
                    if (normalizedTag && !value.includes(normalizedTag)) {
                        setSuggestions([{
                            tag: normalizedTag,
                            usage_count: 0,
                            type: 'normalized'
                        }])
                        setShowSuggestions(true)
                    }
                } catch (normError) {
                    console.error('[TagUploadInput] Normalization failed:', normError)
                }
            }
        }, 300)

        return () => clearTimeout(timeoutId)
    }, [inputValue, tenantId, value])

    // Add tag to list
    const addTag = (tagValue) => {
        if (!tagValue.trim()) return
        
        const normalizedTag = tagValue.toLowerCase()
            .trim()
            .replace(/[^\w\s\-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '')

        if (normalizedTag && !value.includes(normalizedTag) && value.length < maxTags) {
            onChange([...value, normalizedTag])
            
            // Clear input and suggestions
            setInputValue('')
            setSuggestions([])
            setShowSuggestions(false)
            setSelectedIndex(-1)
            
            // Focus back to input
            inputRef.current?.focus()
        }
    }

    // Remove tag from list
    const removeTag = (tagToRemove) => {
        onChange(value.filter(tag => tag !== tagToRemove))
    }

    // Handle key events
    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault()
            
            if (showSuggestions && selectedIndex >= 0 && suggestions[selectedIndex]) {
                addTag(suggestions[selectedIndex].tag)
            } else if (inputValue.trim()) {
                addTag(inputValue.trim())
            }
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
        <div className={`${className}`}>
            {/* Title */}
            {showTitle && (
                <div className="flex items-center gap-2 mb-2">
                    <TagIcon className="h-4 w-4 text-gray-500" />
                    <label className="block text-sm font-medium text-gray-700">
                        Tags
                    </label>
                    {value.length > 0 && (
                        <span className="text-xs text-gray-500">
                            ({value.length}/{maxTags})
                        </span>
                    )}
                </div>
            )}

            {/* Existing tags */}
            {value.length > 0 && (
                <div className="flex flex-wrap gap-2 mb-2">
                    {value.map((tag, index) => (
                        <div
                            key={`${tag}-${index}`}
                            className="inline-flex items-center gap-1 bg-gray-100 border border-gray-300 rounded-md px-2 py-1 text-sm"
                        >
                            <span className="text-gray-900">{tag}</span>
                            <button
                                type="button"
                                onClick={() => removeTag(tag)}
                                disabled={disabled}
                                className="inline-flex items-center p-0.5 text-gray-600 hover:text-gray-800 rounded focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-1 disabled:opacity-50"
                                title="Remove tag"
                                aria-label={`Remove tag "${tag}"`}
                            >
                                <XMarkIcon className="h-3 w-3" />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            {/* Input */}
            <div className="relative">
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
                    placeholder={value.length >= maxTags ? `Maximum ${maxTags} tags` : placeholder}
                    disabled={disabled || value.length >= maxTags}
                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm disabled:bg-gray-50 disabled:text-gray-500"
                    aria-label="Tag input"
                    aria-expanded={showSuggestions}
                    aria-activedescendant={selectedIndex >= 0 ? `upload-suggestion-${selectedIndex}` : undefined}
                />

                {/* Autocomplete suggestions */}
                {showSuggestions && suggestions.length > 0 && (
                    <div className="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-40 overflow-auto">
                        {suggestions.map((suggestion, index) => (
                            <button
                                key={`${suggestion.tag}-${index}`}
                                id={`upload-suggestion-${index}`}
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

            {/* Helper text */}
            <div className="mt-1 text-xs text-gray-500">
                Tags will be normalized (lowercase, no special characters). Press Enter to add.
            </div>
        </div>
    )
}