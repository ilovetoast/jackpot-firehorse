import { useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

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
    const { data, setData, put, processing, errors, reset } = useForm({
        first_name: user?.first_name || '',
        last_name: user?.last_name || '',
        email: user?.email || '',
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

    const handleProfileSubmit = (e) => {
        e.preventDefault()
        put('/app/profile', {
            preserveScroll: true,
            onSuccess: () => {
                reset()
            },
        })
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

                    {/* Personal Information */}
                    <div className="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Personal Information</h2>
                            <p className="mt-1 text-sm text-gray-500">Use a permanent address where you can receive mail.</p>
                        </div>
                        <form onSubmit={handleProfileSubmit} className="px-6 py-6">
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

                    {/* Notifications Section */}
                    <div className="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Notifications</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                We'll always let you know about important changes, but you pick what else you want to hear about.
                            </p>
                        </div>
                        <div className="px-6 py-6">
                            <p className="text-sm text-gray-500">Notification preferences will be available soon.</p>
                        </div>
                    </div>

                    {/* Password Update */}
                    <div className="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Update Password</h2>
                            <p className="mt-1 text-sm text-gray-500">Ensure your account is using a long, random password to stay secure.</p>
                        </div>
                        <form onSubmit={handlePasswordSubmit} className="px-6 py-6">
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

                    {/* Delete Account */}
                    <div className="overflow-hidden rounded-lg bg-red-50 border-2 border-red-200 shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-red-200">
                            <h2 className="text-lg font-semibold text-red-900">Delete Account</h2>
                            <p className="mt-1 text-sm text-red-700">Once your account is deleted, all of its resources and data will be permanently deleted.</p>
                        </div>
                        <div className="px-6 py-5">
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
            </main>
            <AppFooter />
        </div>
    )
}
