/**
 * User Select Component
 * 
 * A styled user select dropdown with avatars and search functionality.
 * Designed to match Tailwind UI Plus patterns while maintaining our style.
 */

import { useState, useRef, useEffect, useMemo } from 'react'
import { ChevronUpIcon, ChevronDownIcon, CheckIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import Avatar from './Avatar'

export default function UserSelect({ 
    users = [], 
    value = null, 
    onChange, 
    placeholder = 'All Users',
    label = 'Created By'
}) {
    const [isOpen, setIsOpen] = useState(false)
    const [searchQuery, setSearchQuery] = useState('')
    const dropdownRef = useRef(null)
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

    // Close dropdown when clicking outside
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target) &&
                buttonRef.current && !buttonRef.current.contains(event.target)) {
                setIsOpen(false)
                setSearchQuery('')
            }
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

    const handleClear = (e) => {
        e.stopPropagation()
        onChange(null)
        setIsOpen(false)
        setSearchQuery('')
    }

    const getUserDisplayName = (user) => {
        return user.name || 
               (user.first_name && user.last_name ? `${user.first_name} ${user.last_name}` : null) ||
               user.email || 
               `User ${user.id}`
    }

    return (
        <div className="relative">
            <label className="text-xs font-medium text-gray-700 mb-2 block">{label}</label>
            <div className="relative">
                <button
                    ref={buttonRef}
                    type="button"
                    onClick={() => setIsOpen(!isOpen)}
                    className="relative w-full bg-white border border-gray-300 rounded-md shadow-sm pl-3 pr-10 py-2 text-left cursor-pointer focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                >
                    <div className="flex items-center gap-2">
                        {selectedUser ? (
                            <>
                                <Avatar
                                    avatarUrl={selectedUser.avatar_url}
                                    firstName={selectedUser.first_name}
                                    lastName={selectedUser.last_name}
                                    email={selectedUser.email}
                                    size="sm"
                                />
                                <span className="block truncate text-sm text-gray-900">
                                    {getUserDisplayName(selectedUser)}
                                </span>
                            </>
                        ) : (
                            <span className="block truncate text-sm text-gray-500">
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

                {isOpen && (
                    <div
                        ref={dropdownRef}
                        className="absolute z-10 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm"
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
                            <div className="flex items-center">
                                <span className="block truncate text-sm text-gray-900">
                                    All Users
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
                                        <div className="flex items-center gap-2">
                                            <Avatar
                                                avatarUrl={user.avatar_url}
                                                firstName={user.first_name}
                                                lastName={user.last_name}
                                                email={user.email}
                                                size="sm"
                                            />
                                            <span className="block truncate text-sm text-gray-900">
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
                )}
            </div>
        </div>
    )
}
