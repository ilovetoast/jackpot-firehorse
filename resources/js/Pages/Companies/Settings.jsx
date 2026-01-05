import { Link, useForm, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function CompanySettings({ tenant, billing, team_members_count }) {
    const { auth } = usePage().props
    const { data, setData, put, processing, errors } = useForm({
        name: tenant.name || '',
        timezone: tenant.timezone || 'UTC',
    })

    const submit = (e) => {
        e.preventDefault()
        put('/app/companies/settings', {
            preserveScroll: true,
        })
    }

    // Common timezones list
    const timezones = [
        { value: 'UTC', label: 'UTC (Coordinated Universal Time)' },
        { value: 'America/New_York', label: 'America/New_York (Eastern Time)' },
        { value: 'America/Chicago', label: 'America/Chicago (Central Time)' },
        { value: 'America/Denver', label: 'America/Denver (Mountain Time)' },
        { value: 'America/Los_Angeles', label: 'America/Los_Angeles (Pacific Time)' },
        { value: 'America/Phoenix', label: 'America/Phoenix (Arizona Time)' },
        { value: 'America/Anchorage', label: 'America/Anchorage (Alaska Time)' },
        { value: 'Pacific/Honolulu', label: 'Pacific/Honolulu (Hawaii Time)' },
        { value: 'Europe/London', label: 'Europe/London (GMT)' },
        { value: 'Europe/Paris', label: 'Europe/Paris (CET)' },
        { value: 'Europe/Berlin', label: 'Europe/Berlin (CET)' },
        { value: 'Asia/Tokyo', label: 'Asia/Tokyo (JST)' },
        { value: 'Asia/Shanghai', label: 'Asia/Shanghai (CST)' },
        { value: 'Asia/Hong_Kong', label: 'Asia/Hong_Kong (HKT)' },
        { value: 'Australia/Sydney', label: 'Australia/Sydney (AEST)' },
        { value: 'Australia/Melbourne', label: 'Australia/Melbourne (AEST)' },
    ]

    const formatPlanName = (planKey) => {
        const planNames = {
            free: 'Free',
            starter: 'Starter',
            pro: 'Pro',
            enterprise: 'Enterprise',
        }
        return planNames[planKey] || planKey
    }

    const formatSubscriptionStatus = (status) => {
        const statusMap = {
            active: 'Active',
            canceled: 'Canceled',
            incomplete: 'Incomplete',
            incomplete_expired: 'Incomplete Expired',
            past_due: 'Past Due',
            trialing: 'Trialing',
            unpaid: 'Unpaid',
            none: 'No Subscription',
        }
        return statusMap[status] || status
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={tenant} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Company Settings</h1>
                        <p className="mt-2 text-sm text-gray-700">Manage your company's settings and preferences</p>
                    </div>

                    {/* Company Information */}
                    <div className="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <div className="flex items-center">
                                <svg className="h-5 w-5 text-gray-400 mr-2" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-3.75 3h.75m6-3h.75m-6-3h.75M6.75 12h.75m6-3h.75m-6 3h.75m3.75-3h.75m-3.75 3h.75m3.75-3h.75m-3.75 3h.75" />
                                </svg>
                                <h2 className="text-lg font-semibold text-gray-900">Company Information</h2>
                            </div>
                            <p className="mt-1 text-sm text-gray-500">Update your company name and details</p>
                        </div>
                        <form onSubmit={submit} className="px-6 py-5">
                            <div className="space-y-6">
                                <div>
                                    <label htmlFor="name" className="block text-sm font-medium leading-6 text-gray-900">
                                        Company Name
                                    </label>
                                    <div className="mt-2">
                                        <input
                                            type="text"
                                            name="name"
                                            id="name"
                                            required
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        />
                                        {errors.name && <p className="mt-2 text-sm text-red-600">{errors.name}</p>}
                                    </div>
                                </div>

                                <div>
                                    <label htmlFor="timezone" className="block text-sm font-medium leading-6 text-gray-900">
                                        Timezone
                                    </label>
                                    <div className="mt-2">
                                        <select
                                            id="timezone"
                                            name="timezone"
                                            required
                                            value={data.timezone}
                                            onChange={(e) => setData('timezone', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        >
                                            {timezones.map((tz) => (
                                                <option key={tz.value} value={tz.value}>
                                                    {tz.label}
                                                </option>
                                            ))}
                                        </select>
                                        <p className="mt-2 text-sm text-gray-500">
                                            Controls how dates/times are displayed across the app. Backend timestamps remain stored in UTC.
                                        </p>
                                        {errors.timezone && <p className="mt-2 text-sm text-red-600">{errors.timezone}</p>}
                                    </div>
                                </div>

                                {errors.error && (
                                    <div className="rounded-md bg-red-50 p-4">
                                        <div className="flex">
                                            <div className="ml-3">
                                                <h3 className="text-sm font-medium text-red-800">Error</h3>
                                                <div className="mt-2 text-sm text-red-700">
                                                    <p>{errors.error}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                <div className="flex justify-end">
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="rounded-md bg-green-500 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 disabled:opacity-50"
                                    >
                                        Save Changes
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    {/* Plan & Billing */}
                    <div className="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <div className="flex items-center">
                                <svg className="h-5 w-5 text-gray-400 mr-2" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                                </svg>
                                <h2 className="text-lg font-semibold text-gray-900">Plan & Billing</h2>
                            </div>
                            <p className="mt-1 text-sm text-gray-500">Manage your subscription and billing information</p>
                        </div>
                        <div className="px-6 py-5">
                            <div className="space-y-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-500">Current Plan</label>
                                        <p className="mt-1 text-sm font-semibold text-gray-900">{formatPlanName(billing.current_plan)}</p>
                                    </div>
                                    <Link
                                        href="/app/billing"
                                        className="inline-flex items-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
                                    >
                                        Manage Plan
                                        <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                        </svg>
                                    </Link>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-500">Subscription Status</label>
                                    <p className="mt-1 text-sm font-semibold text-gray-900">{formatSubscriptionStatus(billing.subscription_status)}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Team Members */}
                    <div className="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <div className="flex items-center">
                                <svg className="h-5 w-5 text-gray-400 mr-2" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                </svg>
                                <h2 className="text-lg font-semibold text-gray-900">Team Members</h2>
                            </div>
                            <p className="mt-1 text-sm text-gray-500">Manage team members and their roles</p>
                        </div>
                        <div className="px-6 py-5">
                            <div className="flex items-center justify-between">
                                <div>
                                    <label className="block text-sm font-medium text-gray-500">Members</label>
                                    <p className="mt-1 text-sm font-semibold text-gray-900">
                                        {team_members_count} {team_members_count === 1 ? 'team member' : 'team members'}
                                    </p>
                                </div>
                                <Link
                                    href="/app/companies"
                                    className="inline-flex items-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
                                >
                                    Manage Team
                                    <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                    </svg>
                                </Link>
                            </div>
                        </div>
                    </div>

                    {/* Danger Zone */}
                    <div className="overflow-hidden rounded-lg border-2 border-red-200 bg-red-50 shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-red-200">
                            <div className="flex items-center">
                                <svg className="h-5 w-5 text-red-400 mr-2" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                </svg>
                                <h2 className="text-lg font-semibold text-red-900">Danger Zone</h2>
                            </div>
                            <p className="mt-1 text-sm text-red-700">Irreversible and destructive actions</p>
                        </div>
                        <div className="px-6 py-5">
                            <div className="flex items-center justify-between">
                                <div>
                                    <h3 className="text-base font-semibold text-red-900">Delete Company</h3>
                                    <p className="mt-1 text-sm text-red-700">
                                        Permanently delete your company and all associated data. This action cannot be undone.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    className="inline-flex items-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                >
                                    <svg className="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                    </svg>
                                    Delete Company
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
