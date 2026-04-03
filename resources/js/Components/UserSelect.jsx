/**
 * User Select Component
 *
 * A styled user select dropdown with avatars and search functionality.
 * Designed to match Tailwind UI Plus patterns while maintaining our style.
 *
 * The menu is rendered with a fixed-position portal so it is not clipped by
 * overflow-hidden / overflow-y-auto ancestors (e.g. More filters panel, asset grid scroll).
 */

import { useState, useRef, useEffect, useMemo, useLayoutEffect } from 'react'
import { createPortal } from 'react-dom'
import { ChevronUpIcon, ChevronDownIcon, CheckIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import Avatar from './Avatar'

const MENU_MAX_HEIGHT_PX = 240

export default function UserSelect({
    users = [],
    value = null,
    onChange,
    placeholder = 'All Users',
    label = 'Created By',
    /** Narrow trigger for dense filter rows; dropdown still gets a readable min width when open. */
    narrow = false,
}) {
    const [isOpen, setIsOpen] = useState(false)
    const [searchQuery, setSearchQuery] = useState('')
    const [menuPos, setMenuPos] = useState({ top: 0, left: 0, width: 0, maxHeight: MENU_MAX_HEIGHT_PX })
    const containerRef = useRef(null)
    const menuRef = useRef(null)
    const buttonRef = useRef(null)

    // Get selected user (handle both string and number IDs)
    const selectedUser = useMemo(() => {
        if (!value) return null
        return users.find(u => u.id === parseInt(value) || u.id === value) || null
    }, [users, value])

    // Filter users based on search query
    const filteredUsers = useMemo(() => {
        if (!searchQuery.trim()) {
            return users
        }
        const query = searchQuery.toLowerCase()
        return users.filter(user => {
            const name = (user.name || '').toLowerCase()
            const email = (user.email || '').toLowerCase()
            const firstName = (user.first_name || '').toLowerCase()
            const lastName = (user.last_name || '').toLowerCase()
            return name.includes(query) ||
                   email.includes(query) ||
                   firstName.includes(query) ||
                   lastName.includes(query)
        })
    }, [users, searchQuery])

    const updateMenuPosition = () => {
        const el = buttonRef.current
        if (!el) return
        const rect = el.getBoundingClientRect()
        const gap = 4
        const top = rect.bottom + gap
        const maxH = Math.max(120, Math.min(MENU_MAX_HEIGHT_PX, window.innerHeight - top - 8))
        let left = rect.left
        // Menu at least as wide as the trigger, but not cramped for names (narrow toolbar buttons are ~14rem).
        const minMenuWidth = narrow ? 288 : 300
        const width = Math.min(
            Math.max(rect.width, minMenuWidth),
            window.innerWidth - 16
        )
        // Keep menu in viewport horizontally
        if (left + width > window.innerWidth - 8) {
            left = Math.max(8, window.innerWidth - width - 8)
        }
        setMenuPos({ top, left, width, maxHeight: maxH })
    }

    useLayoutEffect(() => {
        if (!isOpen) return
        updateMenuPosition()
        const onScrollOrResize = () => updateMenuPosition()
        window.addEventListener('resize', onScrollOrResize)
        document.addEventListener('scroll', onScrollOrResize, true)
        return () => {
            window.removeEventListener('resize', onScrollOrResize)
            document.removeEventListener('scroll', onScrollOrResize, true)
        }
    }, [isOpen, narrow])

    // Close dropdown when clicking outside
    useEffect(() => {
        const handleClickOutside = (event) => {
            const t = event.target
            if (containerRef.current?.contains(t) || menuRef.current?.contains(t)) return
            setIsOpen(false)
            setSearchQuery('')
        }

        if (isOpen) {
            document.addEventListener('mousedown', handleClickOutside)
            return () => {
                document.removeEventListener('mousedown', handleClickOutside)
            }
        }
    }, [isOpen])

    const handleSelect = (userId) => {
        onChange(userId)
        setIsOpen(false)
        setSearchQuery('')
    }

    const getUserDisplayName = (user) => {
        return user.name ||
               (user.first_name && user.last_name ? `${user.first_name} ${user.last_name}` : null) ||
               user.email ||
               `User ${user.id}`
    }

    const menu = isOpen && (
        <div
            ref={menuRef}
            className="fixed z-[250] bg-white shadow-lg rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm"
            style={{
                top: menuPos.top,
                left: menuPos.left,
                width: menuPos.width,
                maxHeight: menuPos.maxHeight,
            }}
            role="listbox"
        >
            {/* Search input */}
            <div className="px-3 py-2 border-b border-gray-200">
                <div className="relative">
                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <MagnifyingGlassIcon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                    </div>
                    <input
                        type="text"
                        className="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                        placeholder="Search users..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        onClick={(e) => e.stopPropagation()}
                    />
                </div>
            </div>

            {/* All Users option */}
            <div
                onClick={() => handleSelect(null)}
                className={`cursor-pointer select-none relative py-2 pl-3 pr-9 hover:bg-indigo-50 ${
                    !selectedUser ? 'bg-indigo-50' : ''
                }`}
            >
                <div className="flex items-center min-w-0">
                    <span className="block min-w-0 flex-1 truncate text-sm text-gray-900">
                        {placeholder}
                    </span>
                    {!selectedUser && (
                        <span className="absolute inset-y-0 right-0 flex items-center pr-4">
                            <CheckIcon className="h-5 w-5 text-indigo-600" aria-hidden="true" />
                        </span>
                    )}
                </div>
            </div>

            {/* User list */}
            {filteredUsers.length > 0 ? (
                filteredUsers.map((user) => {
                    const isSelected = selectedUser && (selectedUser.id === user.id || selectedUser.id === parseInt(user.id))
                    return (
                        <div
                            key={user.id}
                            onClick={() => handleSelect(user.id)}
                            className={`cursor-pointer select-none relative py-2 pl-3 pr-9 hover:bg-indigo-50 ${
                                isSelected ? 'bg-indigo-50' : ''
                            }`}
                        >
                            <div className="flex items-center gap-2 min-w-0 pr-1">
                                <Avatar
                                    avatarUrl={user.avatar_url}
                                    firstName={user.first_name}
                                    lastName={user.last_name}
                                    email={user.email}
                                    size="sm"
                                    className="shrink-0"
                                />
                                <span className="block min-w-0 flex-1 truncate text-sm text-gray-900" title={getUserDisplayName(user)}>
                                    {getUserDisplayName(user)}
                                </span>
                            </div>
                            {isSelected && (
                                <span className="absolute inset-y-0 right-0 flex items-center pr-4">
                                    <CheckIcon className="h-5 w-5 text-indigo-600" aria-hidden="true" />
                                </span>
                            )}
                        </div>
                    )
                })
            ) : (
                <div className="px-3 py-2 text-sm text-gray-500">
                    No users found
                </div>
            )}
        </div>
    )

    return (
        <div ref={containerRef} className={`relative ${narrow ? 'min-w-[11rem] max-w-[20rem]' : ''}`}>
            <label className={`text-xs font-medium text-gray-700 block ${narrow ? 'mb-1' : 'mb-2'}`}>{label}</label>
            <div className="relative">
                <button
                    ref={buttonRef}
                    type="button"
                    onClick={() => setIsOpen(!isOpen)}
                    aria-expanded={isOpen}
                    aria-haspopup="listbox"
                    className={`relative w-full bg-white border border-gray-300 rounded-md shadow-sm pl-3 pr-10 text-left cursor-pointer focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm ${narrow ? 'py-1.5 text-xs' : 'py-2'}`}
                >
                    <div className="flex items-center gap-2 min-w-0">
                        {selectedUser ? (
                            <>
                                <Avatar
                                    avatarUrl={selectedUser.avatar_url}
                                    firstName={selectedUser.first_name}
                                    lastName={selectedUser.last_name}
                                    email={selectedUser.email}
                                    size="sm"
                                    className="shrink-0"
                                />
                                <span className="block min-w-0 flex-1 truncate text-sm text-gray-900" title={getUserDisplayName(selectedUser)}>
                                    {getUserDisplayName(selectedUser)}
                                </span>
                            </>
                        ) : (
                            <span className="block min-w-0 flex-1 truncate text-sm text-gray-500">
                                {placeholder}
                            </span>
                        )}
                    </div>
                    <span className="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                        {isOpen ? (
                            <ChevronUpIcon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                        ) : (
                            <ChevronDownIcon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                        )}
                    </span>
                </button>
            </div>

            {typeof document !== 'undefined' && menu ? createPortal(menu, document.body) : null}
        </div>
    )
}
