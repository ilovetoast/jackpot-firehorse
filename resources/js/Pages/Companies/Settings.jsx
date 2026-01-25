import { Link, useForm, usePage, router } from '@inertiajs/react'
import { useState, useEffect, useCallback } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import AiTaggingSettings from '../../Components/Companies/AiTaggingSettings'
import AiUsagePanel from '../../Components/Companies/AiUsagePanel'
import TagQuality from '../../Components/Companies/TagQuality'
import { usePermission } from '../../hooks/usePermission'
import { debounce } from 'lodash-es'

export default function CompanySettings({ tenant, billing, team_members_count, brands_count, is_current_user_owner, tenant_users = [], pending_transfer = null }) {
    const { auth } = usePage().props
    const { hasPermission: canViewAiUsage } = usePermission('ai.usage.view')
    const { hasPermission: canEditViaPermission } = usePermission('companies.settings.edit')
    // Company owners should always be able to edit settings
    const canEditCompanySettings = is_current_user_owner || canEditViaPermission
    const [activeSection, setActiveSection] = useState('company-information')
    
    // Debug permission check
    useEffect(() => {
        console.log('[AI Settings] Permission check:', {
            canViewAiUsage,
            canEditCompanySettings,
            is_current_user_owner,
            canEditViaPermission,
            tenantRole: auth?.tenant_role,
            rolePermissions: auth?.role_permissions,
            directPermissions: auth?.permissions
        })
    }, [canViewAiUsage, canEditCompanySettings, is_current_user_owner, canEditViaPermission, auth])
    const [showOwnershipTransferModal, setShowOwnershipTransferModal] = useState(false)
    const [selectedNewOwner, setSelectedNewOwner] = useState(null)
    const [initiatingTransfer, setInitiatingTransfer] = useState(false)
    const [slugCheckStatus, setSlugCheckStatus] = useState(null) // 'checking', 'available', 'taken', 'invalid'
    const [slugCheckMessage, setSlugCheckMessage] = useState('')
    const [isCheckingSlug, setIsCheckingSlug] = useState(false)
    const { data, setData, put, processing, errors } = useForm({
        name: tenant.name || '',
        slug: tenant.slug || '',
        timezone: tenant.timezone || 'UTC',
        settings: {
            enable_metadata_approval: tenant.settings?.enable_metadata_approval ?? false, // Phase M-2
        },
    })

    // Utility function to generate slug from company name
    const generateSlugFromName = (name) => {
        return name
            .toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '') // Remove special characters except spaces and hyphens
            .replace(/\s+/g, '-') // Replace spaces with hyphens
            .replace(/-+/g, '-') // Replace multiple hyphens with single hyphen
            .replace(/^-|-$/g, '') // Remove leading/trailing hyphens
    }

    // Check slug availability (debounced)
    const checkSlugAvailability = useCallback(
        debounce(async (slug) => {
            if (!slug || slug === tenant.slug) {
                setSlugCheckStatus(null)
                setSlugCheckMessage('')
                setIsCheckingSlug(false)
                return
            }

            // Basic validation
            if (slug.length < 3) {
                setSlugCheckStatus('invalid')
                setSlugCheckMessage('Slug must be at least 3 characters long')
                setIsCheckingSlug(false)
                return
            }

            if (!/^[a-z0-9-]+$/.test(slug)) {
                setSlugCheckStatus('invalid')
                setSlugCheckMessage('Slug can only contain lowercase letters, numbers, and hyphens')
                setIsCheckingSlug(false)
                return
            }

            if (slug.startsWith('-') || slug.endsWith('-')) {
                setSlugCheckStatus('invalid')
                setSlugCheckMessage('Slug cannot start or end with a hyphen')
                setIsCheckingSlug(false)
                return
            }

            setIsCheckingSlug(true)
            setSlugCheckStatus('checking')
            setSlugCheckMessage('Checking availability...')

            try {
                const response = await window.axios.get('/app/api/companies/check-slug', { 
                    params: { slug } 
                })
                if (response.data.available) {
                    setSlugCheckStatus('available')
                    setSlugCheckMessage('✓ Available')
                } else {
                    setSlugCheckStatus('taken')
                    setSlugCheckMessage('✗ Already taken')
                }
            } catch (error) {
                console.error('Error checking slug:', error)
                setSlugCheckStatus('invalid')
                setSlugCheckMessage('Error checking availability')
            } finally {
                setIsCheckingSlug(false)
            }
        }, 500),
        [tenant.slug]
    )

    // Handle company name change - auto-generate slug if it hasn't been manually edited
    const [hasManuallyEditedSlug, setHasManuallyEditedSlug] = useState(false)

    const handleNameChange = (name) => {
        setData('name', name)
        
        // Auto-generate slug only if user hasn't manually edited it
        if (!hasManuallyEditedSlug && name.trim()) {
            const newSlug = generateSlugFromName(name.trim())
            setData('slug', newSlug)
            checkSlugAvailability(newSlug)
        }
    }

    const handleSlugChange = (slug) => {
        setHasManuallyEditedSlug(true)
        setData('slug', slug)
        checkSlugAvailability(slug)
    }

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
        
        // Prevent submission if slug is not available
        if (slugCheckStatus === 'taken' || slugCheckStatus === 'invalid' || isCheckingSlug) {
            return
        }
        
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
                            {(Array.isArray(auth.permissions) && (auth.permissions.includes('metadata.registry.view') || auth.permissions.includes('metadata.tenant.visibility.manage'))) && (
                                <button
                                    type="button"
                                    onClick={() => handleSectionClick('metadata-settings')}
                                    className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                        activeSection === 'metadata-settings'
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                    }`}
                                >
                                    Metadata
                                </button>
                            )}
                            {canEditCompanySettings && (
                                <button
                                    type="button"
                                    onClick={() => handleSectionClick('ai-settings')}
                                    className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                        activeSection === 'ai-settings'
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                    }`}
                                >
                                    AI Settings
                                </button>
                            )}
                            {canEditCompanySettings && (
                                <button
                                    type="button"
                                    onClick={() => handleSectionClick('tag-quality')}
                                    className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                        activeSection === 'tag-quality'
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                    }`}
                                >
                                    Tag Quality
                                </button>
                            )}
                            {canViewAiUsage && (
                                <button
                                    type="button"
                                    onClick={() => handleSectionClick('ai-usage')}
                                    className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                        activeSection === 'ai-usage'
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                    }`}
                                >
                                    AI Usage
                                </button>
                            )}
                            {is_current_user_owner && (
                                <button
                                    type="button"
                                    onClick={() => handleSectionClick('ownership-transfer')}
                                    className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors ${
                                        activeSection === 'ownership-transfer'
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                    }`}
                                >
                                    Ownership Transfer
                                </button>
                            )}
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
                                            onChange={(e) => handleNameChange(e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        />
                                        {errors.name && <p className="mt-2 text-sm text-red-600">{errors.name}</p>}
                                    </div>
                                </div>

                                <div>
                                    <label htmlFor="slug" className="block text-sm font-medium leading-6 text-gray-900">
                                        Company URL Slug
                                    </label>
                                    <div className="mt-2">
                                        <div className="flex">
                                            <span className="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-gray-500 sm:text-sm">
                                                https://
                                            </span>
                                            <input
                                                type="text"
                                                name="slug"
                                                id="slug"
                                                required
                                                value={data.slug}
                                                onChange={(e) => handleSlugChange(e.target.value)}
                                                className="block w-full min-w-0 flex-1 rounded-none border-0 py-1.5 px-3 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                placeholder="your-company-name"
                                            />
                                            <span className="inline-flex items-center rounded-r-md border border-l-0 border-gray-300 bg-gray-50 px-3 text-gray-500 sm:text-sm">
                                                .jackpot.local
                                            </span>
                                        </div>
                                        
                                        {/* Slug status indicator */}
                                        {(slugCheckStatus || isCheckingSlug) && (
                                            <div className={`mt-2 flex items-center text-sm ${
                                                slugCheckStatus === 'available' ? 'text-green-600' :
                                                slugCheckStatus === 'taken' ? 'text-red-600' :
                                                slugCheckStatus === 'invalid' ? 'text-red-600' :
                                                'text-gray-500'
                                            }`}>
                                                {isCheckingSlug && (
                                                    <svg className="animate-spin -ml-1 mr-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                )}
                                                {slugCheckMessage}
                                            </div>
                                        )}
                                        
                                        <p className="mt-2 text-sm text-gray-500">
                                            This will be your unique company URL. Can only contain lowercase letters, numbers, and hyphens.
                                        </p>
                                        {errors.slug && <p className="mt-2 text-sm text-red-600">{errors.slug}</p>}
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

                                {/* Phase M-2: Metadata Approval Toggle */}
                                {['pro', 'enterprise'].includes(billing.current_plan) && (
                                    <div className="border-t border-gray-200 pt-6">
                                        <div className="flex items-center justify-between">
                                            <div className="flex-1">
                                                <label htmlFor="enable_metadata_approval" className="block text-sm font-medium leading-6 text-gray-900">
                                                    Enable metadata approval workflows
                                                </label>
                                                <p className="mt-1 text-sm text-gray-500">
                                                    When enabled, metadata edits by contributors and viewers will require approval from brand managers or admins.
                                                </p>
                                            </div>
                                            <div className="ml-4">
                                                <button
                                                    type="button"
                                                    onClick={() => setData('settings.enable_metadata_approval', !data.settings?.enable_metadata_approval)}
                                                    className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                                        data.settings?.enable_metadata_approval ? 'bg-indigo-600' : 'bg-gray-200'
                                                    }`}
                                                    role="switch"
                                                    aria-checked={data.settings?.enable_metadata_approval}
                                                >
                                                    <span
                                                        className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                                            data.settings?.enable_metadata_approval ? 'translate-x-5' : 'translate-x-0'
                                                        }`}
                                                    />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                )}

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
                                        disabled={processing || slugCheckStatus === 'taken' || slugCheckStatus === 'invalid' || isCheckingSlug}
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

                    {/* Metadata Settings */}
                    {(Array.isArray(auth.permissions) && (auth.permissions.includes('metadata.registry.view') || auth.permissions.includes('metadata.tenant.visibility.manage'))) && (
                        <div id="metadata-settings" className="mb-12 scroll-mt-8">
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                    {/* Left: Header */}
                                    <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                        <h2 className="text-lg font-semibold text-gray-900">Metadata Management</h2>
                                        <p className="mt-1 text-sm text-gray-500">Manage metadata fields and visibility settings</p>
                                    </div>
                                    {/* Right: Content */}
                                    <div className="lg:col-span-2 px-6 py-6">
                                        <div className="space-y-4">
                                            <div>
                                                <p className="text-sm text-gray-700 mb-4">
                                                    Configure metadata fields for your company. View system metadata fields, create custom fields, and control where fields appear in upload, edit, and filter interfaces.
                                                </p>
                                                <Link
                                                    href="/app/tenant/metadata/registry"
                                                    className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                                >
                                                    Manage Metadata Fields
                                                    <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                    </svg>
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Ownership Transfer */}
                    {is_current_user_owner && (
                        <div id="ownership-transfer" className="mb-12 scroll-mt-8">
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                    {/* Left: Header */}
                                    <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                        <h2 className="text-lg font-semibold text-gray-900">Ownership Transfer</h2>
                                        <p className="mt-1 text-sm text-gray-500">Transfer company ownership to another team member</p>
                                    </div>
                                    {/* Right: Content */}
                                    <div className="lg:col-span-2 px-6 py-6">
                                        <div className="space-y-6">
                                            {/* Pending Transfer Status */}
                                            {pending_transfer && (
                                                <div className="rounded-md bg-amber-50 border border-amber-200 p-4">
                                                    <div className="flex items-start">
                                                        <div className="flex-shrink-0">
                                                            <svg className="h-5 w-5 text-amber-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                                            </svg>
                                                        </div>
                                                        <div className="ml-3 flex-1">
                                                            <h3 className="text-sm font-medium text-amber-800">Pending Ownership Transfer</h3>
                                                            <div className="mt-2 text-sm text-amber-700">
                                                                <p className="font-medium">Status: {pending_transfer.status_label}</p>
                                                                <p className="mt-1">
                                                                    Transferring ownership from <strong>{pending_transfer.from_user.name}</strong> to <strong>{pending_transfer.to_user.name}</strong>.
                                                                </p>
                                                                {pending_transfer.status === 'pending' && (
                                                                    <p className="mt-2">
                                                                        Waiting for current owner to confirm the transfer via email.
                                                                    </p>
                                                                )}
                                                                {pending_transfer.status === 'confirmed' && (
                                                                    <p className="mt-2">
                                                                        Current owner has confirmed. Waiting for new owner to accept the transfer via email.
                                                                    </p>
                                                                )}
                                                                {pending_transfer.status === 'accepted' && (
                                                                    <p className="mt-2">
                                                                        New owner has accepted. Transfer will be completed shortly.
                                                                    </p>
                                                                )}
                                                                {pending_transfer.initiated_at && (
                                                                    <p className="mt-2 text-xs text-amber-600">
                                                                        Initiated: {new Date(pending_transfer.initiated_at).toLocaleString()}
                                                                    </p>
                                                                )}
                                                            </div>
                                                            {pending_transfer.can_cancel && (
                                                                <div className="mt-4">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => {
                                                                            if (confirm('Are you sure you want to cancel this ownership transfer?')) {
                                                                                router.post(`/app/ownership-transfer/${pending_transfer.id}/cancel`, {
                                                                                    preserveScroll: true,
                                                                                    onSuccess: () => {
                                                                                        router.reload({ preserveScroll: true })
                                                                                    },
                                                                                    onError: (errors) => {
                                                                                        if (errors.error) {
                                                                                            alert(errors.error)
                                                                                        }
                                                                                    }
                                                                                })
                                                                            }
                                                                        }}
                                                                        className="inline-flex items-center rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600"
                                                                    >
                                                                        Cancel Transfer
                                                                    </button>
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            )}

                                            <div className="rounded-md bg-blue-50 p-4">
                                                <div className="flex">
                                                    <div className="flex-shrink-0">
                                                        <svg className="h-5 w-5 text-blue-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                                                        </svg>
                                                    </div>
                                                    <div className="ml-3">
                                                        <h3 className="text-sm font-medium text-blue-800">Secure Ownership Transfer</h3>
                                                        <div className="mt-2 text-sm text-blue-700">
                                                            <p>Ownership transfers require a secure, multi-step process:</p>
                                                            <ol className="list-decimal list-inside mt-2 space-y-1">
                                                                <li>You'll receive a confirmation email</li>
                                                                <li>You must confirm the transfer via the email link</li>
                                                                <li>The new owner will receive an acceptance email</li>
                                                                <li>After they accept, ownership will be transferred</li>
                                                            </ol>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            {pending_transfer ? (
                                                <div className="rounded-md bg-gray-50 p-4">
                                                    <p className="text-sm text-gray-600">
                                                        A transfer is currently in progress. Please cancel the existing transfer before initiating a new one.
                                                    </p>
                                                </div>
                                            ) : tenant_users.length === 0 ? (
                                                <div className="rounded-md bg-yellow-50 p-4">
                                                    <p className="text-sm text-yellow-700">
                                                        There are no other team members to transfer ownership to. Please invite team members first.
                                                    </p>
                                                </div>
                                            ) : (
                                                <div>
                                                    <label htmlFor="new_owner" className="block text-sm font-medium leading-6 text-gray-900">
                                                        Select New Owner
                                                    </label>
                                                    <div className="mt-2">
                                                        <select
                                                            id="new_owner"
                                                            name="new_owner"
                                                            value={selectedNewOwner || ''}
                                                            onChange={(e) => setSelectedNewOwner(e.target.value)}
                                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                        >
                                                            <option value="">Select a team member...</option>
                                                            {tenant_users.map((user) => (
                                                                <option key={user.id} value={user.id}>
                                                                    {user.name} ({user.email})
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                    <div className="mt-4">
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                if (!selectedNewOwner) {
                                                                    alert('Please select a team member to transfer ownership to.')
                                                                    return
                                                                }
                                                                if (confirm(`Are you sure you want to initiate an ownership transfer to ${tenant_users.find(u => u.id === parseInt(selectedNewOwner))?.name}? You will receive a confirmation email to complete the transfer.`)) {
                                                                    setInitiatingTransfer(true)
                                                                    router.post(`/app/companies/${tenant.id}/ownership-transfer/initiate`, {
                                                                        new_owner_id: parseInt(selectedNewOwner)
                                                                    }, {
                                                                        preserveScroll: true,
                                                                        onSuccess: () => {
                                                                            setSelectedNewOwner(null)
                                                                            setInitiatingTransfer(false)
                                                                            router.reload({ preserveScroll: true })
                                                                        },
                                                                        onError: (errors) => {
                                                                            setInitiatingTransfer(false)
                                                                            if (errors.error) {
                                                                                alert(errors.error)
                                                                            }
                                                                        }
                                                                    })
                                                                }
                                                            }}
                                                            disabled={!selectedNewOwner || initiatingTransfer}
                                                            className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                                        >
                                                            {initiatingTransfer ? 'Initiating...' : 'Initiate Ownership Transfer'}
                                                        </button>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* AI Settings */}
                    {canEditCompanySettings && (
                        <div id="ai-settings" className="mb-12 scroll-mt-8">
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                    {/* Left: Header */}
                                    <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                        <h2 className="text-lg font-semibold text-gray-900">AI Settings</h2>
                                        <p className="mt-1 text-sm text-gray-500">Configure AI tagging behavior and controls</p>
                                    </div>
                                    {/* Right: Content */}
                                    <div className="lg:col-span-2 px-6 py-6">
                                        <AiTaggingSettings 
                                            canEdit={canEditCompanySettings} 
                                            currentPlan={billing?.current_plan}
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Tag Quality */}
                    {canEditCompanySettings && (
                        <div id="tag-quality" className="mb-12 scroll-mt-8">
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="px-6 py-6">
                                    <div className="mb-4">
                                        <h2 className="text-lg font-semibold text-gray-900">Tag Quality & Trust Metrics</h2>
                                        <p className="mt-1 text-sm text-gray-500">
                                            Understand how AI-generated tags perform and identify areas for improvement
                                        </p>
                                    </div>
                                    <TagQuality canView={canEditCompanySettings} />
                                </div>
                            </div>
                        </div>
                    )}

                    {/* AI Usage */}
                    {canViewAiUsage && (
                        <div id="ai-usage" className="mb-12 scroll-mt-8">
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                    {/* Left: Header */}
                                    <div className="lg:col-span-1 px-6 py-6 border-b lg:border-b-0 lg:border-r border-gray-200">
                                        <h2 className="text-lg font-semibold text-gray-900">AI Usage</h2>
                                        <p className="mt-1 text-sm text-gray-500">View current month's AI feature usage and caps</p>
                                    </div>
                                    {/* Right: Content */}
                                    <div className="lg:col-span-2 px-6 py-6">
                                        <AiUsagePanel canView={canViewAiUsage} />
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

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
