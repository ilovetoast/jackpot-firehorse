import { Link, useForm, usePage, router } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function CompanySettings({ tenant, billing, team_members_count, brands_count }) {
    const { auth } = usePage().props
    const [activeSection, setActiveSection] = useState('company-information')
    const { data, setData, put, processing, errors } = useForm({
        name: tenant.name || '',
        timezone: tenant.timezone || 'UTC',
    })

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
            }
        }

        // Check initial hash
        handleHashChange()

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

                    {/* Navigation Bar */}
                    <div className="mb-8 border-b border-gray-200">
                        <nav className="-mb-px flex space-x-8 overflow-x-auto" aria-label="Company settings sections">
                            <button
                                type="button"
                                onClick={() => handleSectionClick('company-information')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                    activeSection === 'company-information'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                Company Information
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSectionClick('plan-billing')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                    activeSection === 'plan-billing'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                Plan & Billing
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSectionClick('team-members')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                    activeSection === 'team-members'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                Team Members
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSectionClick('brands-settings')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                    activeSection === 'brands-settings'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                Brands Settings
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

                    {/* Company Information */}
                    <div id="company-information" className="mb-12 scroll-mt-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                {/* Left: Header */}
                                <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Company Information</h2>
                                    <p className="mt-1 text-sm text-gray-500">Update your company name and details</p>
                                </div>
                                {/* Right: Content */}
                                <div className="lg:col-span-2 px-6 py-6">
                                    <form onSubmit={submit}>
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
                                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                    >
                                        Save Changes
                                    </button>
                                </div>
                            </div>
                        </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Plan & Billing */}
                    <div id="plan-billing" className="mb-12 scroll-mt-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                {/* Left: Header */}
                                <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Plan & Billing</h2>
                                    <p className="mt-1 text-sm text-gray-500">Manage your subscription and billing information</p>
                                </div>
                                {/* Right: Content */}
                                <div className="lg:col-span-2 px-6 py-6">
                                    <div className="space-y-6">
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <label className="block text-sm font-medium text-gray-500">Current Plan</label>
                                                <p className="mt-1 text-sm font-semibold text-gray-900">{formatPlanName(billing.current_plan)}</p>
                                            </div>
                                            <div className="flex gap-2">
                                                <Link
                                                    href="/app/billing/overview"
                                                    className="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                >
                                                    Billing Overview
                                                </Link>
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
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-500">Subscription Status</label>
                                            <p className="mt-1 text-sm font-semibold text-gray-900">{formatSubscriptionStatus(billing.subscription_status)}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Team Members */}
                    <div id="team-members" className="mb-12 scroll-mt-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                {/* Left: Header */}
                                <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Team Members</h2>
                                    <p className="mt-1 text-sm text-gray-500">Manage team members and their roles</p>
                                </div>
                                {/* Right: Content */}
                                <div className="lg:col-span-2 px-6 py-6">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <label className="block text-sm font-medium text-gray-500">Members</label>
                                            <p className="mt-1 text-sm font-semibold text-gray-900">
                                                {team_members_count} {team_members_count === 1 ? 'team member' : 'team members'}
                                            </p>
                                        </div>
                                        <Link
                                            href="/app/companies/team"
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
                        </div>
                    </div>

                    {/* Brands Settings */}
                    <div id="brands-settings" className="mb-12 scroll-mt-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                {/* Left: Header */}
                                <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Brands Settings</h2>
                                    <p className="mt-1 text-sm text-gray-500">Manage your brands and their settings</p>
                                </div>
                                {/* Right: Content */}
                                <div className="lg:col-span-2 px-6 py-6">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <label className="block text-sm font-medium text-gray-500">Brands</label>
                                            <p className="mt-1 text-sm font-semibold text-gray-900">
                                                {brands_count} {brands_count === 1 ? 'brand' : 'brands'}
                                            </p>
                                        </div>
                                        <Link
                                            href="/app/brands"
                                            className="inline-flex items-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
                                        >
                                            Manage Brands
                                            <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                            </svg>
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Danger Zone */}
                    <div id="danger-zone" className="mb-12 scroll-mt-8">
                        <div className="overflow-hidden rounded-lg border-2 border-red-200 bg-red-50 shadow-sm ring-1 ring-gray-200">
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                {/* Left: Header */}
                                <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-red-200">
                                    <h2 className="text-lg font-semibold text-red-900">Danger Zone</h2>
                                    <p className="mt-1 text-sm text-red-700">Irreversible and destructive actions</p>
                                </div>
                                {/* Right: Content */}
                                <div className="lg:col-span-2 px-6 py-6">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <h3 className="text-base font-semibold text-red-900">Delete Company</h3>
                                            <p className="mt-1 text-sm text-red-700">
                                                Permanently delete your company and all associated data. This action cannot be undone.
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (confirm(`WARNING: Are you sure you want to PERMANENTLY DELETE "${tenant.name}"? This action cannot be undone. All data, brands, assets, and team members will be permanently deleted.`)) {
                                                    if (confirm(`Final confirmation: This will permanently delete "${tenant.name}" and all associated data. Continue?`)) {
                                                        router.delete('/app/companies/settings', {
                                                            onError: (errors) => {
                                                                if (errors.error) {
                                                                    alert(errors.error)
                                                                }
                                                            },
                                                        })
                                                    }
                                                }
                                            }}
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
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
