import { useState, useRef, useEffect } from 'react'

export default function UserSelector({ 
    users = [], 
    selectedUser, 
    onSelect, 
    placeholder = "Select a user...",
    label = "Assigned to"
}) {
    const [query, setQuery] = useState('')
    const [isOpen, setIsOpen] = useState(false)
    const [highlightedIndex, setHighlightedIndex] = useState(-1)
    const wrapperRef = useRef(null)
    const inputRef = useRef(null)

    // Filter users based on query
    const filteredUsers = users.filter((user) => {
        const fullName = `${user.first_name || ''} ${user.last_name || ''}`.toLowerCase()
        const email = (user.email || '').toLowerCase()
        const searchTerm = query.toLowerCase()
        return fullName.includes(searchTerm) || email.includes(searchTerm)
    })

    // Get initials for avatar
    const getInitials = (user) => {
        if (user.first_name && user.last_name) {
            return `${user.first_name.charAt(0)}${user.last_name.charAt(0)}`.toUpperCase()
        }
        if (user.first_name) {
            return user.first_name.charAt(0).toUpperCase()
        }
        if (user.email) {
            return user.email.charAt(0).toUpperCase()
        }
        return '?'
    }

    // Handle click outside
    useEffect(() => {
        function handleClickOutside(event) {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setIsOpen(false)
                setHighlightedIndex(-1)
            }
        }
        document.addEventListener('mousedown', handleClickOutside)
        return () => document.removeEventListener('mousedown', handleClickOutside)
    }, [])

    // Handle keyboard navigation
    const handleKeyDown = (e) => {
        if (!isOpen && (e.key === 'ArrowDown' || e.key === 'Enter')) {
            setIsOpen(true)
            return
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault()
            setHighlightedIndex((prev) => 
                prev < filteredUsers.length - 1 ? prev + 1 : prev
            )
        } else if (e.key === 'ArrowUp') {
            e.preventDefault()
            setHighlightedIndex((prev) => (prev > 0 ? prev - 1 : -1))
        } else if (e.key === 'Enter' && highlightedIndex >= 0) {
            e.preventDefault()
            handleSelect(filteredUsers[highlightedIndex])
        } else if (e.key === 'Escape') {
            setIsOpen(false)
            setHighlightedIndex(-1)
        }
    }

    const handleSelect = (user) => {
        onSelect(user)
        setQuery('')
        setIsOpen(false)
        setHighlightedIndex(-1)
    }

    const handleInputChange = (e) => {
        setQuery(e.target.value)
        setIsOpen(true)
        setHighlightedIndex(-1)
    }

    const handleInputFocus = () => {
        setIsOpen(true)
    }

    return (
        <div ref={wrapperRef} className="relative">
            <label htmlFor="user-selector" className="block text-sm font-medium leading-6 text-gray-900 mb-2">
                {label}
            </label>
            
            {/* Selected User Display */}
            {selectedUser ? (
                <div className="relative">
                    <button
                        type="button"
                        onClick={() => {
                            setIsOpen(!isOpen)
                            inputRef.current?.focus()
                        }}
                        className="relative w-full cursor-default rounded-md bg-gray-50 py-2 pl-3 pr-10 text-left text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6"
                    >
                        <div className="flex items-center">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-sm font-medium text-white flex-shrink-0">
                                {getInitials(selectedUser)}
                            </div>
                            <span className="ml-3 block truncate">
                                {selectedUser.first_name && selectedUser.last_name
                                    ? `${selectedUser.first_name} ${selectedUser.last_name}`
                                    : selectedUser.first_name || selectedUser.email}
                            </span>
                        </div>
                        <span className="pointer-events-none absolute inset-y-0 right-0 ml-3 flex items-center pr-2">
                            <svg className="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fillRule="evenodd" d="M10 3a.75.75 0 01.55.24l3.25 3.5a.75.75 0 11-1.1 1.02L10 4.852 7.3 7.76a.75.75 0 01-1.1-1.02l3.25-3.5A.75.75 0 0110 3zm-3.76 9.2a.75.75 0 011.06.04l2.7 2.908 2.7-2.908a.75.75 0 111.1 1.02l-3.25 3.5a.75.75 0 01-1.1 0l-3.25-3.5a.75.75 0 01.04-1.06z" clipRule="evenodd" />
                            </svg>
                        </span>
                    </button>
                </div>
            ) : (
                <div className="relative">
                    <input
                        ref={inputRef}
                        type="text"
                        id="user-selector"
                        value={query}
                        onChange={handleInputChange}
                        onFocus={handleInputFocus}
                        onKeyDown={handleKeyDown}
                        placeholder={placeholder}
                        className="block w-full rounded-md border-0 py-2 pl-3 pr-10 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                    />
                    <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                        <svg className="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fillRule="evenodd" d="M10 3a.75.75 0 01.55.24l3.25 3.5a.75.75 0 11-1.1 1.02L10 4.852 7.3 7.76a.75.75 0 01-1.1-1.02l3.25-3.5A.75.75 0 0110 3zm-3.76 9.2a.75.75 0 011.06.04l2.7 2.908 2.7-2.908a.75.75 0 111.1 1.02l-3.25 3.5a.75.75 0 01-1.1 0l-3.25-3.5a.75.75 0 01.04-1.06z" clipRule="evenodd" />
                        </svg>
                    </div>
                </div>
            )}

            {/* Dropdown List */}
            {isOpen && (
                <div className="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm">
                    {filteredUsers.length === 0 ? (
                        <div className="relative cursor-default select-none px-4 py-2 text-gray-700">
                            No users found
                        </div>
                    ) : (
                        filteredUsers.map((user, index) => {
                            const isSelected = selectedUser?.id === user.id
                            const isHighlighted = index === highlightedIndex

                            return (
                                <div
                                    key={user.id}
                                    onClick={() => handleSelect(user)}
                                    onMouseEnter={() => setHighlightedIndex(index)}
                                    className={`relative cursor-default select-none py-2 pl-3 pr-9 ${
                                        isHighlighted ? 'bg-indigo-600 text-white' : 'text-gray-900'
                                    }`}
                                >
                                    <div className="flex items-center">
                                        <div className={`flex h-8 w-8 items-center justify-center rounded-full flex-shrink-0 ${
                                            isHighlighted ? 'bg-indigo-500' : 'bg-indigo-600'
                                        } text-sm font-medium text-white`}>
                                            {getInitials(user)}
                                        </div>
                                        <span className={`ml-3 block truncate ${
                                            isSelected ? 'font-semibold' : 'font-normal'
                                        }`}>
                                            {user.first_name && user.last_name
                                                ? `${user.first_name} ${user.last_name}`
                                                : user.first_name || user.email}
                                        </span>
                                    </div>
                                    {isSelected && (
                                        <span className={`absolute inset-y-0 right-0 flex items-center pr-4 ${
                                            isHighlighted ? 'text-white' : 'text-indigo-600'
                                        }`}>
                                            <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clipRule="evenodd" />
                                            </svg>
                                        </span>
                                    )}
                                </div>
                            )
                        })
                    )}
                </div>
            )}
        </div>
    )
}
