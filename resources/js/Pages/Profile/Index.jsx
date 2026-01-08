import { useForm, usePage, router } from '@inertiajs/react'
import { useState, useRef, useEffect } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import Avatar from '../../Components/Avatar'
import ImageCropModal from '../../Components/ImageCropModal'

// Common countries list
const countries = [
    'United States',
    'Canada',
    'United Kingdom',
    'Australia',
    'Germany',
    'France',
    'Italy',
    'Spain',
    'Netherlands',
    'Belgium',
    'Switzerland',
    'Austria',
    'Sweden',
    'Norway',
    'Denmark',
    'Finland',
    'Poland',
    'Portugal',
    'Greece',
    'Ireland',
    'New Zealand',
    'Japan',
    'South Korea',
    'Singapore',
    'Hong Kong',
    'Mexico',
    'Brazil',
    'Argentina',
    'Chile',
    'South Africa',
    'India',
    'China',
    'Other',
]

// Common timezones
const timezones = [
    'UTC',
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'America/Phoenix',
    'America/Anchorage',
    'America/Honolulu',
    'America/Toronto',
    'America/Vancouver',
    'Europe/London',
    'Europe/Paris',
    'Europe/Berlin',
    'Europe/Rome',
    'Europe/Madrid',
    'Europe/Amsterdam',
    'Europe/Brussels',
    'Europe/Vienna',
    'Europe/Stockholm',
    'Europe/Oslo',
    'Europe/Copenhagen',
    'Europe/Helsinki',
    'Europe/Warsaw',
    'Europe/Lisbon',
    'Europe/Athens',
    'Europe/Dublin',
    'Asia/Tokyo',
    'Asia/Shanghai',
    'Asia/Hong_Kong',
    'Asia/Singapore',
    'Asia/Seoul',
    'Asia/Dubai',
    'Asia/Kolkata',
    'Australia/Sydney',
    'Australia/Melbourne',
    'Australia/Brisbane',
    'Australia/Perth',
    'Pacific/Auckland',
]

export default function ProfileIndex({ user: userData }) {
    const { auth } = usePage().props
    // Use userData from props if available, otherwise fall back to auth.user
    const user = userData || auth.user
    const [cropModalOpen, setCropModalOpen] = useState(false)
    const [imageToCrop, setImageToCrop] = useState(null)
    const fileInputRef = useRef(null)
    
    const { data, setData, put, processing, errors, reset } = useForm({
        first_name: user?.first_name || '',
        last_name: user?.last_name || '',
        email: user?.email || '',
        avatar: null,
        avatar_preview: user?.avatar_url || '',
        country: user?.country || '',
        timezone: user?.timezone || '',
        address: user?.address || '',
        city: user?.city || '',
        state: user?.state || '',
        zip: user?.zip || '',
    })

    const { data: passwordData, setData: setPasswordData, put: putPassword, processing: passwordProcessing, errors: passwordErrors, reset: resetPassword } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    })

    const [showDeleteConfirmation, setShowDeleteConfirmation] = useState(false)
    const [activeSection, setActiveSection] = useState('profile-picture')

    // Handle hash navigation on mount and hash change
    useEffect(() => {
        const handleHashChange = () => {
            const hash = window.location.hash.replace('#', '')
            if (hash) {
                setActiveSection(hash)
                const element = document.getElementById(hash)
                if (element) {
                    setTimeout(() => {
                        element.scrollIntoView({ behavior: 'smooth', block: 'start' })
                    }, 100)
                }
            } else {
                // Default to first section if no hash
                setActiveSection('profile-picture')
            }
        }

        // Check initial hash
        const initialHash = window.location.hash.replace('#', '')
        if (initialHash) {
            setActiveSection(initialHash)
        }

        // Listen for hash changes
        window.addEventListener('hashchange', handleHashChange)
        return () => window.removeEventListener('hashchange', handleHashChange)
    }, [])

    const handleSectionClick = (sectionId) => {
        setActiveSection(sectionId)
        window.location.hash = sectionId
        const element = document.getElementById(sectionId)
        if (element) {
            setTimeout(() => {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' })
            }, 100)
        }
    }

    const handleProfileSubmit = (e) => {
        e.preventDefault()
        put('/app/profile', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                // Cleanup preview URL if it was a blob
                if (data.avatar_preview && data.avatar_preview.startsWith('blob:')) {
                    URL.revokeObjectURL(data.avatar_preview)
                }
                // Update avatar_preview with the new URL from server response
                if (page.props.user?.avatar_url) {
                    setData('avatar_preview', page.props.user.avatar_url)
                }
            },
        })
    }

    const handleAvatarSelect = (e) => {
        const file = e.target.files?.[0]
        if (file) {
            const reader = new FileReader()
            reader.onload = (event) => {
                setImageToCrop(event.target.result)
                setCropModalOpen(true)
            }
            reader.readAsDataURL(file)
        }
    }

    const handleCropComplete = (croppedImageBlob) => {
        const previewUrl = URL.createObjectURL(croppedImageBlob)
        setData('avatar', croppedImageBlob)
        setData('avatar_preview', previewUrl)
        setCropModalOpen(false)
        setImageToCrop(null)
    }

    const handleRemoveAvatar = () => {
        if (confirm('Are you sure you want to remove your profile picture?')) {
            router.delete('/app/profile/avatar', {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (data.avatar_preview && data.avatar_preview.startsWith('blob:')) {
                        URL.revokeObjectURL(data.avatar_preview)
                    }
                    setData('avatar', null)
                    setData('avatar_preview', '')
                    // Update from server response
                    if (page.props.user && !page.props.user.avatar_url) {
                        setData('avatar_preview', '')
                    }
                    if (fileInputRef.current) {
                        fileInputRef.current.value = ''
                    }
                },
            })
        }
    }

    const handlePasswordSubmit = (e) => {
        e.preventDefault()
        putPassword('/app/profile/password', {
            preserveScroll: true,
            onSuccess: () => {
                resetPassword()
            },
        })
    }

    const handleDeleteAccount = () => {
        if (confirm('Are you sure you want to delete your account? Once your account is deleted, all of its resources and data will be permanently deleted.')) {
            // TODO: Implement delete account
            alert('Account deletion not yet implemented')
        }
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Profile</h1>
                        <p className="mt-2 text-sm text-gray-700">Manage your account settings and preferences</p>
                    </div>

                    {/* Navigation Bar */}
                    <div className="mb-8 border-b border-gray-200">
                        <nav className="-mb-px flex space-x-8 overflow-x-auto" aria-label="Profile sections">
                            <button
                                type="button"
                                onClick={() => handleSectionClick('profile-picture')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                    activeSection === 'profile-picture'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                Profile Picture
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSectionClick('personal-information')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                    activeSection === 'personal-information'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                Personal Information
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSectionClick('update-password')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                    activeSection === 'update-password'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                Update Password
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSectionClick('notifications')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                    activeSection === 'notifications'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                Notifications
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSectionClick('danger-zone')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                    activeSection === 'danger-zone'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                Danger Zone
                            </button>
                        </nav>
                    </div>

                    {/* Profile Picture */}
                    <div id="profile-picture" className="mb-12 scroll-mt-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                {/* Left: Header */}
                                <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Profile Picture</h2>
                                    <p className="mt-1 text-sm text-gray-500">Upload a profile picture to personalize your account.</p>
                                </div>
                                {/* Right: Content */}
                                <div className="lg:col-span-2 px-6 py-6">
                                    <div className="flex items-center gap-6">
                                        <Avatar
                                            avatarUrl={data.avatar_preview || user?.avatar_url}
                                            firstName={data.first_name || user?.first_name}
                                            lastName={data.last_name || user?.last_name}
                                            email={data.email || user?.email}
                                            size="xl"
                                        />
                                        <div className="flex-1">
                                            <div className="flex items-center gap-3">
                                                <label
                                                    htmlFor="avatar"
                                                    className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 cursor-pointer"
                                                >
                                                    <svg className="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                                                    </svg>
                                                    {data.avatar_preview || user?.avatar_url ? 'Change Photo' : 'Upload Photo'}
                                                </label>
                                                <input
                                                    ref={fileInputRef}
                                                    type="file"
                                                    id="avatar"
                                                    name="avatar"
                                                    accept="image/*"
                                                    onChange={handleAvatarSelect}
                                                    className="hidden"
                                                />
                                                {(data.avatar_preview || user?.avatar_url) && (
                                                    <button
                                                        type="button"
                                                        onClick={handleRemoveAvatar}
                                                        className="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                    >
                                                        Remove
                                                    </button>
                                                )}
                                            </div>
                                            <p className="mt-2 text-xs text-gray-500">
                                                JPG, PNG, GIF or WEBP. Max size 2MB. Square images work best.
                                            </p>
                                            {errors.avatar && <p className="mt-2 text-sm text-red-600">{errors.avatar}</p>}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Personal Information */}
                    <div id="personal-information" className="mb-12 scroll-mt-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                {/* Left: Header */}
                                <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Personal Information</h2>
                                    <p className="mt-1 text-sm text-gray-500">Use a permanent address where you can receive mail.</p>
                                </div>
                                {/* Right: Content */}
                                <div className="lg:col-span-2 px-6 py-6">
                                    <form onSubmit={handleProfileSubmit}>
                                        <div className="space-y-6">
                                            {/* First Name and Last Name */}
                                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                                <div>
                                                    <label htmlFor="first_name" className="block text-sm font-medium leading-6 text-gray-900">
                                                        First name
                                                    </label>
                                                    <div className="mt-2">
                                                        <input
                                                            type="text"
                                                            name="first_name"
                                                            id="first_name"
                                                            required
                                                            value={data.first_name}
                                                            onChange={(e) => setData('first_name', e.target.value)}
                                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                        />
                                                        {errors.first_name && <p className="mt-2 text-sm text-red-600">{errors.first_name}</p>}
                                                    </div>
                                                </div>

                                                <div>
                                                    <label htmlFor="last_name" className="block text-sm font-medium leading-6 text-gray-900">
                                                        Last name
                                                    </label>
                                                    <div className="mt-2">
                                                        <input
                                                            type="text"
                                                            name="last_name"
                                                            id="last_name"
                                                            required
                                                            value={data.last_name}
                                                            onChange={(e) => setData('last_name', e.target.value)}
                                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                        />
                                                        {errors.last_name && <p className="mt-2 text-sm text-red-600">{errors.last_name}</p>}
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Email */}
                                            <div>
                                                <label htmlFor="email" className="block text-sm font-medium leading-6 text-gray-900">
                                                    Email address
                                                </label>
                                                <div className="mt-2">
                                                    <input
                                                        type="email"
                                                        name="email"
                                                        id="email"
                                                        required
                                                        value={data.email}
                                                        onChange={(e) => setData('email', e.target.value)}
                                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                    />
                                                    {errors.email && <p className="mt-2 text-sm text-red-600">{errors.email}</p>}
                                                </div>
                                            </div>

                                            {/* Country */}
                                            <div>
                                                <label htmlFor="country" className="block text-sm font-medium leading-6 text-gray-900">
                                                    Country
                                                </label>
                                                <div className="mt-2">
                                                    <select
                                                        name="country"
                                                        id="country"
                                                        value={data.country}
                                                        onChange={(e) => setData('country', e.target.value)}
                                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                    >
                                                        <option value="">Select a country</option>
                                                        {countries.map((country) => (
                                                            <option key={country} value={country}>
                                                                {country}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    {errors.country && <p className="mt-2 text-sm text-red-600">{errors.country}</p>}
                                                </div>
                                            </div>

                                            {/* Timezone */}
                                            <div>
                                                <label htmlFor="timezone" className="block text-sm font-medium leading-6 text-gray-900">
                                                    Timezone
                                                </label>
                                                <div className="mt-2">
                                                    <select
                                                        name="timezone"
                                                        id="timezone"
                                                        value={data.timezone}
                                                        onChange={(e) => setData('timezone', e.target.value)}
                                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                    >
                                                        <option value="">Select a timezone</option>
                                                        {timezones.map((tz) => (
                                                            <option key={tz} value={tz}>
                                                                {tz}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    {errors.timezone && <p className="mt-2 text-sm text-red-600">{errors.timezone}</p>}
                                                </div>
                                            </div>

                                            {/* Street Address */}
                                            <div>
                                                <label htmlFor="address" className="block text-sm font-medium leading-6 text-gray-900">
                                                    Street address
                                                </label>
                                                <div className="mt-2">
                                                    <input
                                                        type="text"
                                                        name="address"
                                                        id="address"
                                                        value={data.address}
                                                        onChange={(e) => setData('address', e.target.value)}
                                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                    />
                                                    {errors.address && <p className="mt-2 text-sm text-red-600">{errors.address}</p>}
                                                </div>
                                            </div>

                                            {/* City, State, ZIP */}
                                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                                                <div>
                                                    <label htmlFor="city" className="block text-sm font-medium leading-6 text-gray-900">
                                                        City
                                                    </label>
                                                    <div className="mt-2">
                                                        <input
                                                            type="text"
                                                            name="city"
                                                            id="city"
                                                            value={data.city}
                                                            onChange={(e) => setData('city', e.target.value)}
                                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                        />
                                                        {errors.city && <p className="mt-2 text-sm text-red-600">{errors.city}</p>}
                                                    </div>
                                                </div>

                                                <div>
                                                    <label htmlFor="state" className="block text-sm font-medium leading-6 text-gray-900">
                                                        State / Province
                                                    </label>
                                                    <div className="mt-2">
                                                        <input
                                                            type="text"
                                                            name="state"
                                                            id="state"
                                                            value={data.state}
                                                            onChange={(e) => setData('state', e.target.value)}
                                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                        />
                                                        {errors.state && <p className="mt-2 text-sm text-red-600">{errors.state}</p>}
                                                    </div>
                                                </div>

                                                <div>
                                                    <label htmlFor="zip" className="block text-sm font-medium leading-6 text-gray-900">
                                                        ZIP / Postal code
                                                    </label>
                                                    <div className="mt-2">
                                                        <input
                                                            type="text"
                                                            name="zip"
                                                            id="zip"
                                                            value={data.zip}
                                                            onChange={(e) => setData('zip', e.target.value)}
                                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                        />
                                                        {errors.zip && <p className="mt-2 text-sm text-red-600">{errors.zip}</p>}
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="flex justify-end">
                                                <button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                                >
                                                    Save
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Update Password */}
                    <div id="update-password" className="mb-12 scroll-mt-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                {/* Left: Header */}
                                <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Update Password</h2>
                                    <p className="mt-1 text-sm text-gray-500">Ensure your account is using a long, random password to stay secure.</p>
                                </div>
                                {/* Right: Content */}
                                <div className="lg:col-span-2 px-6 py-6">
                                    <form onSubmit={handlePasswordSubmit}>
                                        <div className="space-y-6">
                                            <div>
                                                <label htmlFor="current_password" className="block text-sm font-medium leading-6 text-gray-900">
                                                    Current Password
                                                </label>
                                                <div className="mt-2">
                                                    <input
                                                        type="password"
                                                        name="current_password"
                                                        id="current_password"
                                                        required
                                                        value={passwordData.current_password}
                                                        onChange={(e) => setPasswordData('current_password', e.target.value)}
                                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                    />
                                                    {passwordErrors.current_password && <p className="mt-2 text-sm text-red-600">{passwordErrors.current_password}</p>}
                                                </div>
                                            </div>

                                            <div>
                                                <label htmlFor="password" className="block text-sm font-medium leading-6 text-gray-900">
                                                    New Password
                                                </label>
                                                <div className="mt-2">
                                                    <input
                                                        type="password"
                                                        name="password"
                                                        id="password"
                                                        required
                                                        value={passwordData.password}
                                                        onChange={(e) => setPasswordData('password', e.target.value)}
                                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                    />
                                                    {passwordErrors.password && <p className="mt-2 text-sm text-red-600">{passwordErrors.password}</p>}
                                                </div>
                                            </div>

                                            <div>
                                                <label htmlFor="password_confirmation" className="block text-sm font-medium leading-6 text-gray-900">
                                                    Confirm Password
                                                </label>
                                                <div className="mt-2">
                                                    <input
                                                        type="password"
                                                        name="password_confirmation"
                                                        id="password_confirmation"
                                                        required
                                                        value={passwordData.password_confirmation}
                                                        onChange={(e) => setPasswordData('password_confirmation', e.target.value)}
                                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex justify-end">
                                                <button
                                                    type="submit"
                                                    disabled={passwordProcessing}
                                                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                                >
                                                    Update Password
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Notifications */}
                    <div id="notifications" className="mb-12 scroll-mt-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                {/* Left: Header */}
                                <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Notifications</h2>
                                    <p className="mt-1 text-sm text-gray-500">
                                        We'll always let you know about important changes, but you pick what else you want to hear about.
                                    </p>
                                </div>
                                {/* Right: Content */}
                                <div className="lg:col-span-2 px-6 py-6">
                                    <p className="text-sm text-gray-500">Notification preferences will be available soon.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Delete Account */}
                    <div id="danger-zone" className="mb-12 scroll-mt-8">
                        <div className="overflow-hidden rounded-lg bg-red-50 border-2 border-red-200 shadow-sm ring-1 ring-gray-200">
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                {/* Left: Header */}
                                <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-red-200">
                                    <h2 className="text-lg font-semibold text-red-900">Delete Account</h2>
                                    <p className="mt-1 text-sm text-red-700">Once your account is deleted, all of its resources and data will be permanently deleted.</p>
                                </div>
                                {/* Right: Content */}
                                <div className="lg:col-span-2 px-6 py-6">
                                    <button
                                        type="button"
                                        onClick={handleDeleteAccount}
                                        className="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                    >
                                        Delete Account
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />

            {/* Image Crop Modal */}
            <ImageCropModal
                open={cropModalOpen}
                imageSrc={imageToCrop}
                onClose={() => {
                    setCropModalOpen(false)
                    setImageToCrop(null)
                }}
                onCropComplete={handleCropComplete}
                aspectRatio={{ width: 1, height: 1 }}
                minWidth={200}
                minHeight={200}
            />
        </div>
    )
}
