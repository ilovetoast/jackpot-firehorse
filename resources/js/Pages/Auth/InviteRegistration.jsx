import { useForm, router } from '@inertiajs/react'
import { useState } from 'react'

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

export default function InviteRegistration({ invitation }) {
    const { data, setData, post, processing, errors } = useForm({
        first_name: '',
        last_name: '',
        password: '',
        password_confirmation: '',
        country: '',
        timezone: '',
    })

    const [showOptional, setShowOptional] = useState(false)

    const handleSubmit = (e) => {
        e.preventDefault()
        post(`/invite/complete/${invitation.token}/${invitation.tenant.id}`)
    }

    return (
        <div className="flex min-h-full flex-1 flex-col justify-center bg-gray-50 px-6 py-12 lg:px-8">
            <div className="sm:mx-auto sm:w-full sm:max-w-2xl">
                {/* Header with brand information */}
                <div className="mb-8 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div className="text-center">
                        <h2 className="text-3xl font-bold leading-9 tracking-tight text-gray-900">
                            Complete Your Registration
                        </h2>
                        <p className="mt-2 text-sm leading-6 text-gray-600">
                            You've been invited to join <span className="font-semibold text-gray-900">{invitation.tenant.name}</span>
                        </p>
                        {invitation.inviter && (
                            <p className="mt-1 text-sm text-gray-500">
                                Invited by {invitation.inviter.name} ({invitation.inviter.email})
                            </p>
                        )}
                    </div>

                    {/* Brands section */}
                    {invitation.brands && invitation.brands.length > 0 && (
                        <div className="mt-6 border-t border-gray-200 pt-6">
                            <h3 className="text-sm font-semibold text-gray-900 mb-3">Brands you'll have access to:</h3>
                            <div className="space-y-2">
                                {invitation.brands.map((brand) => (
                                    <div key={brand.id} className="flex items-center justify-between rounded-md bg-gray-50 px-4 py-2 border border-gray-200">
                                        <div className="flex items-center gap-3">
                                            <div className="flex-shrink-0">
                                                <div className="h-8 w-8 rounded-full bg-indigo-100 flex items-center justify-center">
                                                    <span className="text-sm font-semibold text-indigo-600">
                                                        {brand.name.charAt(0).toUpperCase()}
                                                    </span>
                                                </div>
                                            </div>
                                            <div>
                                                <p className="text-sm font-medium text-gray-900">{brand.name}</p>
                                                <p className="text-xs text-gray-500">Role: <span className="capitalize">{brand.role}</span></p>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* Registration Form */}
                <div className="mt-8 rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200">
                    <div className="mb-6">
                        <h3 className="text-lg font-semibold text-gray-900">Create Your Account</h3>
                        <p className="mt-1 text-sm text-gray-500">
                            Please provide the following information to complete your registration. Your email address is already set: <span className="font-medium text-gray-700">{invitation.email}</span>
                        </p>
                    </div>

                    <form className="space-y-6" onSubmit={handleSubmit}>
                        {/* Required Fields */}
                        <div className="space-y-6">
                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                <div>
                                    <label htmlFor="first_name" className="block text-sm font-medium leading-6 text-gray-900">
                                        First name <span className="text-red-500">*</span>
                                    </label>
                                    <div className="mt-2">
                                        <input
                                            type="text"
                                            name="first_name"
                                            id="first_name"
                                            required
                                            autoComplete="given-name"
                                            value={data.first_name}
                                            onChange={(e) => setData('first_name', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        />
                                        {errors.first_name && (
                                            <p className="mt-2 text-sm text-red-600">{errors.first_name}</p>
                                        )}
                                    </div>
                                </div>

                                <div>
                                    <label htmlFor="last_name" className="block text-sm font-medium leading-6 text-gray-900">
                                        Last name <span className="text-red-500">*</span>
                                    </label>
                                    <div className="mt-2">
                                        <input
                                            type="text"
                                            name="last_name"
                                            id="last_name"
                                            required
                                            autoComplete="family-name"
                                            value={data.last_name}
                                            onChange={(e) => setData('last_name', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        />
                                        {errors.last_name && (
                                            <p className="mt-2 text-sm text-red-600">{errors.last_name}</p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label htmlFor="password" className="block text-sm font-medium leading-6 text-gray-900">
                                    Password <span className="text-red-500">*</span>
                                </label>
                                <div className="mt-2">
                                    <input
                                        type="password"
                                        name="password"
                                        id="password"
                                        required
                                        autoComplete="new-password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                    />
                                    <p className="mt-1 text-xs text-gray-500">Must be at least 8 characters</p>
                                    {errors.password && (
                                        <p className="mt-2 text-sm text-red-600">{errors.password}</p>
                                    )}
                                </div>
                            </div>

                            <div>
                                <label htmlFor="password_confirmation" className="block text-sm font-medium leading-6 text-gray-900">
                                    Confirm Password <span className="text-red-500">*</span>
                                </label>
                                <div className="mt-2">
                                    <input
                                        type="password"
                                        name="password_confirmation"
                                        id="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                        value={data.password_confirmation}
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                    />
                                    {errors.password_confirmation && (
                                        <p className="mt-2 text-sm text-red-600">{errors.password_confirmation}</p>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Optional Fields Toggle */}
                        <div className="border-t border-gray-200 pt-6">
                            <button
                                type="button"
                                onClick={() => setShowOptional(!showOptional)}
                                className="flex items-center justify-between w-full text-sm font-medium text-gray-700 hover:text-gray-900"
                            >
                                <span>Optional Information</span>
                                <svg
                                    className={`h-5 w-5 transform transition-transform ${showOptional ? 'rotate-180' : ''}`}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    strokeWidth="1.5"
                                    stroke="currentColor"
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                </svg>
                            </button>

                            {showOptional && (
                                <div className="mt-4 space-y-6">
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
                                                autoComplete="country"
                                                className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            >
                                                <option value="">Select a country</option>
                                                {countries.map((country) => (
                                                    <option key={country} value={country}>
                                                        {country}
                                                    </option>
                                                ))}
                                            </select>
                                            {errors.country && (
                                                <p className="mt-2 text-sm text-red-600">{errors.country}</p>
                                            )}
                                        </div>
                                    </div>

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
                                                        {tz.replace(/_/g, ' ')}
                                                    </option>
                                                ))}
                                            </select>
                                            {errors.timezone && (
                                                <p className="mt-2 text-sm text-red-600">{errors.timezone}</p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>

                        {errors.invitation && (
                            <div className="rounded-md bg-red-50 p-4">
                                <p className="text-sm text-red-800">{errors.invitation}</p>
                            </div>
                        )}

                        <div>
                            <button
                                type="submit"
                                disabled={processing}
                                className="flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold leading-6 text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {processing ? 'Creating account...' : 'Complete Registration'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    )
}
