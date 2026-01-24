/**
 * TagInput Component
 * 
 * Phase J.2.3: Unified tag input with autocomplete and normalization
 * 
 * Features:
 * - Autocomplete canonical tags first
 * - Creates new tags if none match
 * - Tags pass through normalization
 * - Synonyms resolve silently
 * - Manual selection always wins over AI
 * - Keyboard accessible
 * - Optimistic UI updates
 */

import { useState, useRef, useEffect } from 'react'
import { XMarkIcon } from '@heroicons/react/24/outline'

export default function TagInput({ 
    assetId, 
    onTagAdded = () => {}, 
    onTagRemoved = () => {},
    placeholder = "Add tags...",
    className = "",
    disabled = false 
}) {
    const [inputValue, setInputValue] = useState('')
    const [suggestions, setSuggestions] = useState([])
    const [showSuggestions, setShowSuggestions] = useState(false)
    const [selectedIndex, setSelectedIndex] = useState(-1)
    const [loading, setLoading] = useState(false)
    const inputRef = useRef(null)
    const suggestionsRef = useRef(null)

    // Debounced autocomplete
    useEffect(() => {
        if (!inputValue.trim() || inputValue.length < 2) {
            setSuggestions([])
            setShowSuggestions(false)
            return
        }

        const timeoutId = setTimeout(async () => {
            try {
                const response = await fetch(
                    `/api/assets/${assetId}/tags/autocomplete?q=${encodeURIComponent(inputValue)}`,
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
                    setSuggestions(data.suggestions || [])
                    setShowSuggestions(true)
                    setSelectedIndex(-1)
                }
            } catch (error) {
                console.error('[TagInput] Autocomplete failed:', error)
            }
        }, 300) // 300ms debounce

        return () => clearTimeout(timeoutId)
    }, [inputValue, assetId])

    // Handle tag creation
    const createTag = async (tagValue) => {
        if (!tagValue.trim() || loading) return

        setLoading(true)

        try {
            const response = await fetch(`/api/assets/${assetId}/tags`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
                body: JSON.stringify({ tag: tagValue.trim() }),
            })

            if (response.ok) {
                const data = await response.json()
                
                // Clear input and suggestions
                setInputValue('')
                setSuggestions([])
                setShowSuggestions(false)
                setSelectedIndex(-1)

                // Notify parent
                onTagAdded(data.tag)

                // Focus back to input for continued typing
                inputRef.current?.focus()
            } else {
                const errorData = await response.json()
                
                if (response.status === 409) {
                    // Tag already exists - just clear input
                    setInputValue('')
                    setSuggestions([])
                    setShowSuggestions(false)
                    inputRef.current?.focus()
                } else {
                    // Other errors
                    alert(errorData.message || 'Failed to add tag')
                }
            }
        } catch (error) {
            console.error('[TagInput] Tag creation failed:', error)
            alert('Failed to add tag. Please try again.')
        } finally {
            setLoading(false)
        }
    }

    // Handle input key events
    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault()
            
            if (showSuggestions && selectedIndex >= 0 && suggestions[selectedIndex]) {
                // Use selected suggestion
                createTag(suggestions[selectedIndex].tag)
            } else if (inputValue.trim()) {
                // Create new tag with current input
                createTag(inputValue.trim())
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
        createTag(suggestion.tag)
    }

    // Handle input blur (with delay to allow suggestion clicks)
    const handleBlur = () => {
        setTimeout(() => {
            setShowSuggestions(false)
            setSelectedIndex(-1)
        }, 150)
    }

    return (
        <div className={`relative ${className}`}>
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
                placeholder={placeholder}
                disabled={disabled || loading}
                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm disabled:bg-gray-50 disabled:text-gray-500"
                aria-label="Tag input"
                aria-expanded={showSuggestions}
                aria-activedescendant={selectedIndex >= 0 ? `suggestion-${selectedIndex}` : undefined}
            />

            {/* Autocomplete suggestions dropdown */}
            {showSuggestions && suggestions.length > 0 && (
                <div
                    ref={suggestionsRef}
                    className="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-60 overflow-auto"
                    role="listbox"
                >
                    {suggestions.map((suggestion, index) => (
                        <button
                            key={`${suggestion.tag}-${index}`}
                            id={`suggestion-${index}`}
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

            {/* Loading indicator */}
            {loading && (
                <div className="absolute right-3 top-1/2 transform -translate-y-1/2">
                    <div className="animate-spin rounded-full h-4 w-4 border-2 border-gray-300 border-t-indigo-600"></div>
                </div>
            )}
        </div>
    )
}