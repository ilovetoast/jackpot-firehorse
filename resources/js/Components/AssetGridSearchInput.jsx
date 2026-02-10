/**
 * Decoupled search input for the asset grid toolbar.
 * Retains focus across Inertia reloads by:
 * - Owning its value in local state
 * - Syncing from server only when the change is external (e.g. back button), not after our own apply
 * Stable identity: parent should not pass a key that changes when assets reload.
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
}) {
    const [value, setValue] = useState(serverQuery)
    const lastAppliedRef = useRef(null)
    const valueRef = useRef(serverQuery)
    const debounceRef = useRef(null)

    valueRef.current = value

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

    const applySearch = useCallback((val, hadFocus) => {
        const trimmed = (typeof val === 'string' ? val : '').trim()
        lastAppliedRef.current = trimmed
        onSearchApply(trimmed, hadFocus)
    }, [onSearchApply])

    const handleChange = useCallback((e) => {
        const v = e.target.value
        setValue(v)
        if (debounceRef.current) clearTimeout(debounceRef.current)
        debounceRef.current = setTimeout(() => {
            debounceRef.current = null
            const hadFocus = inputRef?.current && document.activeElement === inputRef.current
            applySearch(v, hadFocus)
        }, 320)
    }, [applySearch, inputRef])

    const handleKeyDown = useCallback((e) => {
        if (e.key === 'Escape') {
            setValue('')
            applySearch('', false)
            e.target.blur()
        }
    }, [applySearch])

    return (
        <div className={`relative ${className}`}>
            <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 shrink-0" aria-hidden />
            <input
                ref={inputRef}
                type="search"
                value={value}
                onChange={handleChange}
                onKeyDown={handleKeyDown}
                onFocus={onFocus}
                onBlur={onBlur}
                placeholder={placeholder}
                style={{ paddingLeft: '2.5rem', paddingRight: isSearchPending ? '2rem' : undefined }}
                className={`block w-full pr-2.5 py-1.5 text-sm bg-gray-50 rounded-lg border border-gray-200 text-gray-900 placeholder-gray-400 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors ${inputClassName}`}
                aria-label="Search assets"
                autoComplete="off"
            />
            {isSearchPending && (
                <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2" aria-hidden>
                    <svg className="animate-spin h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                    </svg>
                </span>
            )}
        </div>
    )
}
