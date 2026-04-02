/**
 * Decoupled search input for the asset grid toolbar.
 * Retains focus across Inertia reloads by:
 * - Owning its value in local state
 * - Syncing from server only when the change is external (e.g. back button), not after our own apply
 * Stable identity: parent should not pass a key that changes when assets reload.
 *
 * Optional `tagAutocompleteTenantId`: dropdown of tenant tag suggestions (same API as TagPrimaryFilter).
 */
import { useState, useEffect, useRef, useCallback } from 'react'
import { MagnifyingGlassIcon } from '@heroicons/react/24/outline'

export default function AssetGridSearchInput({
    serverQuery = '',
    onSearchApply = () => {},
    isSearchPending = false,
    placeholder = 'Search filename, title, tags…',
    className = '',
    inputClassName = '',
    onFocus = () => {},
    onBlur = () => {},
    inputRef = null,
    tagAutocompleteTenantId = null,
    primaryColor = '#6366f1',
}) {
    const [value, setValue] = useState(serverQuery)
    const [suggestions, setSuggestions] = useState([])
    const [showSuggestions, setShowSuggestions] = useState(false)
    const [selectedIndex, setSelectedIndex] = useState(-1)
    const lastAppliedRef = useRef(null)
    const valueRef = useRef(serverQuery)
    const debounceRef = useRef(null)
    const internalInputRef = useRef(null)
    const rootRef = useRef(null)

    valueRef.current = value

    const setInputRef = useCallback(
        (el) => {
            internalInputRef.current = el
            if (typeof inputRef === 'function') {
                inputRef(el)
            } else if (inputRef) {
                inputRef.current = el
            }
        },
        [inputRef]
    )

    const tenantId = tagAutocompleteTenantId != null ? Number(tagAutocompleteTenantId) : null
    const tagAutocompleteEnabled = Number.isFinite(tenantId) && tenantId > 0

    // Initialize from server on mount; sync only when server change is external (not from our apply)
    useEffect(() => {
        if (lastAppliedRef.current !== null) {
            if (serverQuery === lastAppliedRef.current && valueRef.current === lastAppliedRef.current) {
                setValue(serverQuery)
            }
            lastAppliedRef.current = null
        } else {
            setValue(serverQuery)
        }
    }, [serverQuery])

    const fetchTagSuggestions = useCallback(
        async (q) => {
            if (!tagAutocompleteEnabled) return []
            try {
                const url = `/app/api/tenants/${tenantId}/tags/autocomplete?q=${encodeURIComponent(q)}`
                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    credentials: 'same-origin',
                })
                if (!response.ok) return []
                const data = await response.json()
                return Array.isArray(data.suggestions) ? data.suggestions : []
            } catch {
                return []
            }
        },
        [tagAutocompleteEnabled, tenantId]
    )

    // Debounced tag search while typing (2+ chars)
    useEffect(() => {
        if (!tagAutocompleteEnabled) {
            setSuggestions([])
            setShowSuggestions(false)
            return
        }
        const trimmed = value.trim()
        if (trimmed.length < 2) {
            if (trimmed.length === 0) {
                setSuggestions([])
                setShowSuggestions(false)
            }
            return
        }

        const timeoutId = setTimeout(() => {
            fetchTagSuggestions(trimmed).then((list) => {
                setSuggestions(list)
                setShowSuggestions(list.length > 0)
                setSelectedIndex(-1)
            })
        }, 200)

        return () => clearTimeout(timeoutId)
    }, [value, tagAutocompleteEnabled, fetchTagSuggestions])

    // Click outside closes suggestions
    useEffect(() => {
        if (!showSuggestions) return
        const onDocDown = (e) => {
            if (rootRef.current && !rootRef.current.contains(e.target)) {
                setShowSuggestions(false)
                setSelectedIndex(-1)
            }
        }
        document.addEventListener('mousedown', onDocDown)
        return () => document.removeEventListener('mousedown', onDocDown)
    }, [showSuggestions])

    const applySearch = useCallback(
        (val, hadFocus) => {
            const trimmed = (typeof val === 'string' ? val : '').trim()
            lastAppliedRef.current = trimmed
            onSearchApply(trimmed, hadFocus)
        },
        [onSearchApply]
    )

    const applySearchImmediate = useCallback(
        (val, hadFocus) => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current)
                debounceRef.current = null
            }
            setValue(val)
            applySearch(val, hadFocus)
        },
        [applySearch]
    )

    const pickSuggestionTag = useCallback(
        (tag) => {
            if (!tag) return
            const base = valueRef.current.trim()
            const tokens = base.split(/\s+/).filter(Boolean)
            if (tokens.includes(tag)) {
                setShowSuggestions(false)
                setSelectedIndex(-1)
                return
            }
            const next = base ? `${base} ${tag}` : tag
            const hadFocus = internalInputRef.current && document.activeElement === internalInputRef.current
            applySearchImmediate(next, hadFocus)
            setShowSuggestions(false)
            setSelectedIndex(-1)
            setSuggestions([])
        },
        [applySearchImmediate]
    )

    const handleChange = useCallback(
        (e) => {
            const v = e.target.value
            setValue(v)
            if (debounceRef.current) clearTimeout(debounceRef.current)
            debounceRef.current = setTimeout(() => {
                debounceRef.current = null
                const hadFocus = internalInputRef.current && document.activeElement === internalInputRef.current
                applySearch(v, hadFocus)
            }, 320)
        },
        [applySearch]
    )

    const handleFocusInput = useCallback(
        (e) => {
            onFocus(e)
            if (!tagAutocompleteEnabled) return
            const trimmed = valueRef.current.trim()
            if (trimmed.length >= 2) return
            fetchTagSuggestions('')
                .then((list) => {
                    setSuggestions(list)
                    if (list.length > 0) {
                        setShowSuggestions(true)
                        setSelectedIndex(-1)
                    }
                })
                .catch(() => setSuggestions([]))
        },
        [onFocus, tagAutocompleteEnabled, fetchTagSuggestions]
    )

    const handleKeyDown = useCallback(
        (e) => {
            if (tagAutocompleteEnabled && showSuggestions && suggestions.length > 0) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault()
                    setSelectedIndex((i) => Math.min(i + 1, suggestions.length - 1))
                    return
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault()
                    setSelectedIndex((i) => Math.max(i - 1, -1))
                    return
                }
                if (e.key === 'Enter' && selectedIndex >= 0) {
                    e.preventDefault()
                    const row = suggestions[selectedIndex]
                    if (row?.tag) pickSuggestionTag(row.tag)
                    return
                }
            }
            if (e.key === 'Escape') {
                if (showSuggestions) {
                    e.preventDefault()
                    setShowSuggestions(false)
                    setSelectedIndex(-1)
                    return
                }
                setValue('')
                applySearch('', false)
                e.target.blur()
            }
        },
        [
            tagAutocompleteEnabled,
            showSuggestions,
            suggestions,
            selectedIndex,
            pickSuggestionTag,
            applySearch,
        ]
    )

    const focusStyle = {
        '--search-focus': primaryColor,
    }

    return (
        <div className={`asset-grid-search-root relative z-10 ${className}`} ref={rootRef}>
            <MagnifyingGlassIcon
                className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 shrink-0"
                aria-hidden
            />
            <input
                ref={setInputRef}
                type="search"
                value={value}
                onChange={handleChange}
                onKeyDown={handleKeyDown}
                onFocus={handleFocusInput}
                onBlur={onBlur}
                placeholder={placeholder}
                style={{ ...focusStyle, paddingLeft: '2.5rem', paddingRight: isSearchPending ? '2rem' : undefined }}
                className={`block w-full pr-2.5 py-1.5 text-sm bg-gray-50 rounded-lg border border-gray-200 text-gray-900 placeholder-gray-400 focus:outline-none focus:border-[var(--search-focus)] focus:ring-1 focus:ring-[var(--search-focus)] transition-colors ${inputClassName}`}
                aria-label="Search assets"
                aria-expanded={tagAutocompleteEnabled && showSuggestions}
                aria-controls={tagAutocompleteEnabled && showSuggestions ? 'asset-grid-search-tag-suggestions' : undefined}
                aria-autocomplete={tagAutocompleteEnabled ? 'list' : undefined}
                autoComplete="off"
            />
            {isSearchPending && (
                <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2" aria-hidden>
                    <svg className="animate-spin h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path
                            className="opacity-75"
                            fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                        />
                    </svg>
                </span>
            )}
            {tagAutocompleteEnabled && showSuggestions && suggestions.length > 0 && (
                <ul
                    id="asset-grid-search-tag-suggestions"
                    role="listbox"
                    className="absolute left-0 right-0 top-full z-50 mt-1 max-h-48 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 text-left text-sm shadow-lg"
                >
                    {suggestions.map((s, idx) => (
                        <li key={`${s.tag}-${idx}`} role="option" aria-selected={idx === selectedIndex}>
                            <button
                                type="button"
                                className={`flex w-full items-center justify-between gap-2 px-3 py-2 text-left hover:bg-gray-50 ${
                                    idx === selectedIndex ? 'bg-gray-50' : ''
                                }`}
                                onMouseDown={(ev) => ev.preventDefault()}
                                onClick={() => pickSuggestionTag(s.tag)}
                            >
                                <span className="truncate text-gray-900">{s.tag}</span>
                                {typeof s.usage_count === 'number' && s.usage_count > 0 ? (
                                    <span className="shrink-0 text-xs text-gray-400">{s.usage_count}</span>
                                ) : null}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    )
}
