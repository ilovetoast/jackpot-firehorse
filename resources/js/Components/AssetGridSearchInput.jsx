/**
 * Decoupled search input for the asset grid toolbar.
 * Retains focus across Inertia reloads by:
 * - Owning its value in local state
 * - Syncing from server only when the change is external (e.g. back button), not after our own apply
 * Stable identity: parent should not pass a key that changes when assets reload.
 *
 * Optional `tagAutocompleteTenantId`: dropdown of tenant tag suggestions (same API as TagPrimaryFilter).
 */
import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { MagnifyingGlassIcon, XMarkIcon, ClockIcon } from '@heroicons/react/24/outline'

/**
 * Persisted recent-searches cache (per tenant when available, else a shared "default" bucket).
 * Kept small (8 entries) so the dropdown stays fast + doesn't dominate the UI.
 */
const RECENT_SEARCH_LIMIT = 8
const RECENT_SEARCH_STORAGE_PREFIX = 'jp.assetGrid.recentSearches'

function recentStorageKey(tenantId) {
    const suffix = tenantId != null && Number.isFinite(Number(tenantId)) ? String(tenantId) : 'default'
    return `${RECENT_SEARCH_STORAGE_PREFIX}.${suffix}`
}

function loadRecentSearches(tenantId) {
    if (typeof window === 'undefined') return []
    try {
        const raw = window.localStorage.getItem(recentStorageKey(tenantId))
        if (!raw) return []
        const parsed = JSON.parse(raw)
        if (!Array.isArray(parsed)) return []
        return parsed
            .map((s) => (typeof s === 'string' ? s : ''))
            .map((s) => s.trim())
            .filter(Boolean)
            .slice(0, RECENT_SEARCH_LIMIT)
    } catch {
        return []
    }
}

function saveRecentSearches(tenantId, list) {
    if (typeof window === 'undefined') return
    try {
        window.localStorage.setItem(recentStorageKey(tenantId), JSON.stringify(list.slice(0, RECENT_SEARCH_LIMIT)))
    } catch {
        // quota / access errors are non-fatal for a UX nicety
    }
}

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
    const [recentSearches, setRecentSearches] = useState(() => loadRecentSearches(tagAutocompleteTenantId))
    const lastAppliedRef = useRef(null)
    const valueRef = useRef(serverQuery)
    const debounceRef = useRef(null)
    const internalInputRef = useRef(null)
    const rootRef = useRef(null)
    // Tracks the last serverQuery we accepted into local state. Used to decide whether
    // an incoming serverQuery change is "external" (browser back/forward, another tab,
    // programmatic nav) vs. a response to our own apply. Without this, a late-arriving
    // partial reload could overwrite the user's in-progress typing.
    const lastSyncedServerRef = useRef(serverQuery)

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

    // Re-hydrate recent searches if the tenant scope changes mid-session (e.g. switching brand/tenant).
    useEffect(() => {
        setRecentSearches(loadRecentSearches(tagAutocompleteTenantId))
    }, [tagAutocompleteTenantId])

    const pushRecentSearch = useCallback(
        (term) => {
            const trimmed = typeof term === 'string' ? term.trim() : ''
            if (!trimmed) return
            setRecentSearches((prev) => {
                const normalized = trimmed.toLowerCase()
                const deduped = prev.filter((s) => s.toLowerCase() !== normalized)
                const next = [trimmed, ...deduped].slice(0, RECENT_SEARCH_LIMIT)
                saveRecentSearches(tagAutocompleteTenantId, next)
                return next
            })
        },
        [tagAutocompleteTenantId]
    )

    const removeRecentSearch = useCallback(
        (term) => {
            const normalized = (typeof term === 'string' ? term : '').toLowerCase()
            setRecentSearches((prev) => {
                const next = prev.filter((s) => s.toLowerCase() !== normalized)
                saveRecentSearches(tagAutocompleteTenantId, next)
                return next
            })
        },
        [tagAutocompleteTenantId]
    )

    const clearRecentSearches = useCallback(() => {
        setRecentSearches([])
        saveRecentSearches(tagAutocompleteTenantId, [])
    }, [tagAutocompleteTenantId])

    // Sync strategy:
    //   - If serverQuery matches our own last apply, accept it and clear the flag.
    //   - Otherwise treat it as external. Only overwrite local `value` if the user
    //     has not edited since the last accepted server value. Never clobber
    //     active typing with a stale response.
    useEffect(() => {
        const isOwnApply =
            lastAppliedRef.current !== null && serverQuery === lastAppliedRef.current
        if (isOwnApply) {
            if (valueRef.current === lastAppliedRef.current) {
                setValue(serverQuery)
            }
            lastAppliedRef.current = null
            lastSyncedServerRef.current = serverQuery
            return
        }

        if (valueRef.current === lastSyncedServerRef.current) {
            setValue(serverQuery)
        }
        lastSyncedServerRef.current = serverQuery
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

    // Filter recent searches for the current typing (case-insensitive "includes").
    const filteredRecentSearches = useMemo(() => {
        const trimmed = value.trim().toLowerCase()
        if (!trimmed) return recentSearches
        return recentSearches.filter((s) => s.toLowerCase() !== trimmed && s.toLowerCase().includes(trimmed))
    }, [value, recentSearches])

    // Debounced tag search while typing (2+ chars). Recent searches are always available
    // independent of this network call.
    useEffect(() => {
        if (!tagAutocompleteEnabled) {
            setSuggestions([])
            return
        }
        const trimmed = value.trim()
        if (trimmed.length < 2) {
            setSuggestions([])
            return
        }

        const timeoutId = setTimeout(() => {
            fetchTagSuggestions(trimmed).then((list) => {
                setSuggestions(list)
                setSelectedIndex(-1)
            })
        }, 200)

        return () => clearTimeout(timeoutId)
    }, [value, tagAutocompleteEnabled, fetchTagSuggestions])

    // Flat index of selectable rows, in visual order: recents first, then tag suggestions.
    // Used for keyboard navigation (ArrowUp/Down + Enter).
    const dropdownItems = useMemo(() => {
        const items = []
        filteredRecentSearches.forEach((term) => items.push({ type: 'recent', value: term }))
        suggestions.forEach((s) => {
            if (s && s.tag) items.push({ type: 'tag', value: s.tag, meta: s })
        })
        return items
    }, [filteredRecentSearches, suggestions])

    const hasDropdownItems = dropdownItems.length > 0

    // Close the dropdown automatically whenever it becomes empty, keep it open when there is content
    // and the input is focused. The input's onFocus handler opens it.
    useEffect(() => {
        if (!hasDropdownItems && showSuggestions) {
            setShowSuggestions(false)
            setSelectedIndex(-1)
        }
    }, [hasDropdownItems, showSuggestions])

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
            if (trimmed) {
                pushRecentSearch(trimmed)
            }
            onSearchApply(trimmed, hadFocus)
        },
        [onSearchApply, pushRecentSearch]
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

    const pickRecentSearch = useCallback(
        (term) => {
            const trimmed = typeof term === 'string' ? term.trim() : ''
            if (!trimmed) return
            const hadFocus = internalInputRef.current && document.activeElement === internalInputRef.current
            applySearchImmediate(trimmed, hadFocus)
            setShowSuggestions(false)
            setSelectedIndex(-1)
            setSuggestions([])
        },
        [applySearchImmediate]
    )

    const pickDropdownItem = useCallback(
        (item) => {
            if (!item) return
            if (item.type === 'recent') {
                pickRecentSearch(item.value)
            } else if (item.type === 'tag') {
                pickSuggestionTag(item.value)
            }
        },
        [pickRecentSearch, pickSuggestionTag]
    )

    const handleChange = useCallback((e) => {
        // Explicit-submit UX: typing only updates local state. The search is
        // committed via Enter (form submit), the magnifier submit button, the
        // clear "×" button, or Escape. This avoids mid-keystroke partial reloads
        // clobbering the input (the bug where text disappeared after firing).
        setValue(e.target.value)
        // Re-open the dropdown on subsequent typing even if it was dismissed (e.g. after Escape).
        setShowSuggestions(true)
        setSelectedIndex(-1)
    }, [])

    const submitCurrent = useCallback(() => {
        if (debounceRef.current) {
            clearTimeout(debounceRef.current)
            debounceRef.current = null
        }
        const hadFocus =
            internalInputRef.current && document.activeElement === internalInputRef.current
        applySearch(valueRef.current, hadFocus)
        setShowSuggestions(false)
        setSelectedIndex(-1)
    }, [applySearch])

    const handleFormSubmit = useCallback(
        (e) => {
            e.preventDefault()
            submitCurrent()
        },
        [submitCurrent]
    )

    const handleClearClick = useCallback(() => {
        setValue('')
        applySearchImmediate('', true)
        const el = internalInputRef.current
        if (el && el.focus) el.focus()
    }, [applySearchImmediate])

    const handleFocusInput = useCallback(
        (e) => {
            onFocus(e)
            // Always open the dropdown on focus if recent searches exist — gives a useful
            // empty-state even without network results.
            if (filteredRecentSearches.length > 0) {
                setShowSuggestions(true)
                setSelectedIndex(-1)
            }
            if (!tagAutocompleteEnabled) return
            const trimmed = valueRef.current.trim()
            if (trimmed.length >= 2) return
            fetchTagSuggestions('')
                .then((list) => {
                    setSuggestions(list)
                    if (list.length > 0 || filteredRecentSearches.length > 0) {
                        setShowSuggestions(true)
                        setSelectedIndex(-1)
                    }
                })
                .catch(() => setSuggestions([]))
        },
        [onFocus, tagAutocompleteEnabled, fetchTagSuggestions, filteredRecentSearches.length]
    )

    const handleKeyDown = useCallback(
        (e) => {
            if (showSuggestions && dropdownItems.length > 0) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault()
                    setSelectedIndex((i) => Math.min(i + 1, dropdownItems.length - 1))
                    return
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault()
                    setSelectedIndex((i) => Math.max(i - 1, -1))
                    return
                }
                if (e.key === 'Enter' && selectedIndex >= 0) {
                    e.preventDefault()
                    pickDropdownItem(dropdownItems[selectedIndex])
                    return
                }
            }
            // Enter without a highlighted suggestion falls through to the <form>'s
            // onSubmit handler, which calls submitCurrent(). No preventDefault here.
            if (e.key === 'Escape') {
                if (showSuggestions) {
                    e.preventDefault()
                    setShowSuggestions(false)
                    setSelectedIndex(-1)
                    return
                }
                if (debounceRef.current) {
                    clearTimeout(debounceRef.current)
                    debounceRef.current = null
                }
                setValue('')
                applySearch('', false)
                e.target.blur()
            }
        },
        [
            showSuggestions,
            dropdownItems,
            selectedIndex,
            pickDropdownItem,
            applySearch,
        ]
    )

    const focusStyle = {
        '--search-focus': primaryColor,
    }

    const trimmedValue = value.trim()
    const trimmedServer = (typeof serverQuery === 'string' ? serverQuery : '').trim()
    const hasText = trimmedValue.length > 0
    const hasUnsubmittedChanges = trimmedValue !== trimmedServer
    const rightPadRem = isSearchPending ? 4.25 : hasText ? 2.25 : 0.625

    return (
        <div className={`asset-grid-search-root relative z-10 w-full min-w-0 ${className}`} ref={rootRef}>
            <form onSubmit={handleFormSubmit} role="search" className="contents">
                <button
                    type="submit"
                    className="absolute left-2 top-1/2 -translate-y-1/2 flex h-6 w-6 items-center justify-center rounded-md text-gray-400 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--search-focus)]"
                    aria-label={hasUnsubmittedChanges ? 'Run search' : 'Search'}
                    title={hasUnsubmittedChanges ? 'Press Enter or click to search' : 'Search'}
                    style={hasUnsubmittedChanges ? { color: primaryColor } : undefined}
                >
                    <MagnifyingGlassIcon className="h-4 w-4 shrink-0" aria-hidden />
                </button>
                <input
                    ref={setInputRef}
                    // Intentionally "text" (not "search"): native type="search" renders its own
                    // clear "×" in WebKit/Edge, which duplicated our custom clear button.
                    // We provide role="search" on the wrapping <form> for a11y semantics.
                    type="text"
                    value={value}
                    onChange={handleChange}
                    onKeyDown={handleKeyDown}
                    onFocus={handleFocusInput}
                    onBlur={onBlur}
                    placeholder={placeholder}
                    style={{ ...focusStyle, paddingLeft: '2.25rem', paddingRight: `${rightPadRem}rem` }}
                    className={`block w-full py-1.5 text-sm bg-gray-50 rounded-lg border border-gray-200 text-gray-900 placeholder-gray-400 focus:outline-none focus:border-[var(--search-focus)] focus:ring-1 focus:ring-[var(--search-focus)] transition-colors ${inputClassName}`}
                    aria-label="Search assets"
                    aria-expanded={showSuggestions && hasDropdownItems}
                    aria-controls={showSuggestions && hasDropdownItems ? 'asset-grid-search-suggestions' : undefined}
                    aria-activedescendant={
                        showSuggestions && selectedIndex >= 0 && selectedIndex < dropdownItems.length
                            ? `asset-grid-search-opt-${selectedIndex}`
                            : undefined
                    }
                    aria-autocomplete="list"
                    autoComplete="off"
                    enterKeyHint="search"
                />
                {hasText && !isSearchPending && (
                    <button
                        type="button"
                        onClick={handleClearClick}
                        onMouseDown={(e) => e.preventDefault()}
                        className="absolute right-2 top-1/2 -translate-y-1/2 flex h-6 w-6 items-center justify-center rounded-md text-gray-400 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--search-focus)]"
                        aria-label="Clear search"
                        title="Clear search"
                    >
                        <XMarkIcon className="h-4 w-4 shrink-0" aria-hidden />
                    </button>
                )}
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
            </form>
            {showSuggestions && hasDropdownItems && (
                <ul
                    id="asset-grid-search-suggestions"
                    role="listbox"
                    className="absolute left-0 right-0 top-full z-50 mt-1 max-h-80 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 text-left text-sm shadow-lg"
                >
                    {filteredRecentSearches.length > 0 && (
                        <>
                            <li
                                role="presentation"
                                className="flex items-center justify-between gap-2 px-3 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400"
                            >
                                <span>Recent searches</span>
                                <button
                                    type="button"
                                    onMouseDown={(ev) => ev.preventDefault()}
                                    onClick={clearRecentSearches}
                                    className="text-[10px] font-medium normal-case tracking-normal text-gray-400 hover:text-gray-600"
                                >
                                    Clear
                                </button>
                            </li>
                            {filteredRecentSearches.map((term, idx) => {
                                const flatIdx = idx
                                const isActive = selectedIndex === flatIdx
                                return (
                                    <li
                                        key={`recent-${term}-${idx}`}
                                        id={`asset-grid-search-opt-${flatIdx}`}
                                        role="option"
                                        aria-selected={isActive}
                                    >
                                        <div
                                            className={`flex w-full items-center gap-2 pl-3 pr-1 ${
                                                isActive ? 'bg-gray-50' : 'hover:bg-gray-50'
                                            }`}
                                        >
                                            <button
                                                type="button"
                                                className="flex flex-1 min-w-0 items-center gap-2 py-2 text-left"
                                                onMouseDown={(ev) => ev.preventDefault()}
                                                onClick={() => pickRecentSearch(term)}
                                            >
                                                <ClockIcon className="h-3.5 w-3.5 shrink-0 text-gray-400" aria-hidden />
                                                <span className="truncate text-gray-900">{term}</span>
                                            </button>
                                            <button
                                                type="button"
                                                onMouseDown={(ev) => ev.preventDefault()}
                                                onClick={(ev) => {
                                                    ev.stopPropagation()
                                                    removeRecentSearch(term)
                                                }}
                                                aria-label={`Remove "${term}" from recent searches`}
                                                title="Remove from recent searches"
                                                className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                            >
                                                <XMarkIcon className="h-3.5 w-3.5" aria-hidden />
                                            </button>
                                        </div>
                                    </li>
                                )
                            })}
                        </>
                    )}
                    {suggestions.length > 0 && (
                        <>
                            {filteredRecentSearches.length > 0 && (
                                <li role="presentation" className="mx-3 my-1 border-t border-gray-100" aria-hidden />
                            )}
                            <li
                                role="presentation"
                                className="px-3 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400"
                            >
                                Tags
                            </li>
                            {suggestions.map((s, idx) => {
                                const flatIdx = filteredRecentSearches.length + idx
                                const isActive = selectedIndex === flatIdx
                                return (
                                    <li
                                        key={`tag-${s.tag}-${idx}`}
                                        id={`asset-grid-search-opt-${flatIdx}`}
                                        role="option"
                                        aria-selected={isActive}
                                    >
                                        <button
                                            type="button"
                                            className={`flex w-full items-center justify-between gap-2 px-3 py-2 text-left ${
                                                isActive ? 'bg-gray-50' : 'hover:bg-gray-50'
                                            }`}
                                            onMouseDown={(ev) => ev.preventDefault()}
                                            onClick={() => pickSuggestionTag(s.tag)}
                                        >
                                            <span className="truncate text-gray-900">
                                                <span className="mr-1 text-gray-400">#</span>
                                                {s.tag}
                                            </span>
                                            {typeof s.usage_count === 'number' && s.usage_count > 0 ? (
                                                <span className="shrink-0 text-xs text-gray-400">{s.usage_count}</span>
                                            ) : null}
                                        </button>
                                    </li>
                                )
                            })}
                        </>
                    )}
                </ul>
            )}
        </div>
    )
}
