import { Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import React from 'react'
import AppNav from '../../Components/AppNav'
import CompanyTabs from '../../Components/Company/CompanyTabs'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/react/24/outline'

export default function CompanyPermissions({ 
    tenant,
    company_roles, 
    company_permissions,
    company_role_permissions = {}
}) {
    const { auth } = usePage().props
    const [tooltipVisible, setTooltipVisible] = useState(null)
    const [collapsedSections, setCollapsedSections] = useState({
        companyManagement: false,
        assetManagement: false,
        metadataManagement: false,
        categoryManagement: false,
        ticketManagement: false,
        aiManagement: false,
        other: false,
    })

    const formatPermissionName = (permission) => {
        // Handle both underscore and dot notation
        return permission
            .replace(/[._]/g, ' ')
            .split(' ')
            .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
    }

    const isRestrictedCategoryPermission = (permission) => {
        return permission === 'view.restricted.categories'
    }

    // Categorize permissions into company-level and brand-level
    // Company-level permissions apply at the tenant level and override brand restrictions
    // Brand-level permissions can be scoped to specific brands but may be inherited from company roles
    
    const companyLevelGroups = {
        companyManagement: {
            title: 'Company Management',
            type: 'company',
            permissions: [
                'company.view',
                'company_settings.view',
                'company_settings.edit',
                'company_settings.manage_download_policy',
                'company_settings.manage_dashboard_widgets',
                'company_settings.manage_ai_settings',
                'company_settings.view_tag_quality',
                'company_settings.ownership_transfer',
                'company_settings.delete_company',
                'team.manage',
                'billing.view',
                'billing.manage',
                'activity_logs.view',
            ],
        },
        categoryManagement: {
            title: 'Category Management',
            type: 'company',
            permissions: ['brand_categories.manage', 'view.restricted.categories'],
        },
        ticketManagement: {
            title: 'Ticket Management',
            type: 'company',
            permissions: ['tickets.create', 'tickets.reply', 'tickets.view_tenant', 'tickets.view_any'],
        },
        aiManagement: {
            title: 'AI Features',
            type: 'company',
            permissions: ['ai.dashboard.view', 'ai.dashboard.manage', 'ai.budgets.view', 'ai.budgets.manage'],
        },
        governance: {
            title: 'Metadata Governance',
            type: 'company',
            permissions: [
                'metadata.registry.view',
                'metadata.system.visibility.manage',
                'metadata.tenant.visibility.manage',
                'metadata.tenant.field.create',
                'metadata.tenant.field.manage',
            ],
        },
    }

    const brandLevelGroups = {
        assetManagement: {
            title: 'Asset Management',
            type: 'brand',
            permissions: ['asset.view', 'asset.download', 'asset.upload', 'assets.retry_thumbnails', 'asset.publish', 'asset.unpublish', 'asset.archive', 'asset.restore'],
        },
        metadataManagement: {
            title: 'Metadata Management',
            type: 'brand',
            permissions: [
                'metadata.set_on_upload',
                'metadata.edit_post_upload',
                'metadata.bypass_approval',
                'metadata.override_automatic',
                'metadata.review_candidates',
                'metadata.bulk_edit',
            ],
        },
    }

    // Combine for backward compatibility
    const permissionGroups = { ...companyLevelGroups, ...brandLevelGroups }

    // Get all permissions from groups
    const groupedPermissionNames = Object.values(permissionGroups).flatMap(g => g.permissions)
    
    // Get permissions not in any group
    const otherPermissions = (company_permissions || []).filter(p => !groupedPermissionNames.includes(p))

    const toggleSection = (sectionKey) => {
        setCollapsedSections(prev => ({
            ...prev,
            [sectionKey]: !prev[sectionKey],
        }))
    }

    return (
        <div className="min-h-screen flex flex-col bg-gray-50">
            <AppHead title="Company Permissions" />
            <AppNav brand={auth.activeBrand} tenant={tenant} />
            <main className="flex-1">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-6">
                        <h1 className="text-3xl font-bold text-gray-900">Company Permissions</h1>
                        <p className="mt-2 text-sm text-gray-600">
                            View which permissions each role has for {tenant.name}. Owners and admins can view all restricted categories.
                        </p>
                    </div>

                    <CompanyTabs />

                    {/* Permissions Overview */}
                    <div className="mb-8 rounded-lg bg-gray-50 border border-gray-200 px-6 py-4">
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                            <div className="flex items-start gap-2.5">
                                <span className="mt-0.5 flex-shrink-0 text-base">🏢</span>
                                <div>
                                    <span className="font-semibold text-gray-900">Company-level</span>
                                    <span className="text-gray-500"> — billing, team, settings. Apply to all brands.</span>
                                </div>
                            </div>
                            <div className="flex items-start gap-2.5">
                                <span className="mt-0.5 flex-shrink-0 text-base">🏷️</span>
                                <div>
                                    <span className="font-semibold text-gray-900">Brand-level</span>
                                    <span className="text-gray-500"> — assets, metadata. Scoped per brand.</span>
                                </div>
                            </div>
                            <div className="flex items-start gap-2.5">
                                <svg className="mt-0.5 h-4 w-4 flex-shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                                </svg>
                                <div>
                                    <span className="font-semibold text-gray-900">Owner & Admin override</span>
                                    <span className="text-gray-500"> — full access to all brands and restricted categories.</span>
                                </div>
                            </div>
                        </div>
                        <p className="mt-3 text-xs text-gray-400">This page is read-only. Permissions are pulled from the database.</p>
                    </div>

                    {/* Company Roles & Permissions */}
                    <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="border-b border-gray-200 px-6 py-4">
                            <div className="flex items-center">
                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 mr-3">
                                    <span className="text-indigo-600">🏢</span>
                                </div>
                                <div>
                                    <h2 className="text-lg font-semibold text-gray-900">Company Roles & Permissions</h2>
                                    <p className="mt-1 text-sm text-gray-500">View which permissions each role has for company-wide features</p>
                                </div>
                            </div>
                        </div>
                        <div className="px-6 py-4 overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Permission</th>
                                        {company_roles.map((role) => (
                                            <th key={role.id} className="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">
                                                <div className="flex items-center justify-center gap-1">
                                                    {role.icon && <span>{role.icon}</span>}
                                                    <span>{role.name}</span>
                                                </div>
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {/* Render Company-Level Permissions */}
                                    {Object.entries(companyLevelGroups).map(([groupKey, group]) => {
                                        const groupPermissions = group.permissions.filter(p => company_permissions.includes(p))
                                        if (groupPermissions.length === 0) return null

                                        return (
                                            <React.Fragment key={groupKey}>
                                                <tr className="bg-indigo-50 border-t-2 border-indigo-200">
                                                    <td colSpan={company_roles.length + 1} className="px-4 py-3">
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleSection(groupKey)}
                                                            className="flex items-center gap-2 text-sm font-semibold text-indigo-900 hover:text-indigo-700 w-full text-left"
                                                        >
                                                            {collapsedSections[groupKey] ? (
                                                                <ChevronRightIcon className="h-5 w-5" />
                                                            ) : (
                                                                <ChevronDownIcon className="h-5 w-5" />
                                                            )}
                                                            <span className="mr-2">🏢</span>
                                                            <span>{group.title}</span>
                                                            <span className="ml-2 text-xs font-normal text-indigo-600 bg-indigo-100 px-2 py-0.5 rounded">Company-Level</span>
                                                        </button>
                                                    </td>
                                                </tr>
                                                {!collapsedSections[groupKey] && groupPermissions.map((permission) => (
                                                    <tr key={permission}>
                                                        <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                                                            <div className="flex items-center gap-2">
                                                                <span>{formatPermissionName(permission)}</span>
                                                                {isRestrictedCategoryPermission(permission) && (
                                                                    <div className="relative inline-block">
                                                                        <button
                                                                            type="button"
                                                                            className="text-gray-400 hover:text-gray-600 focus:outline-none transition-colors"
                                                                            onMouseEnter={() => setTooltipVisible(permission)}
                                                                            onMouseLeave={() => setTooltipVisible(null)}
                                                                            onClick={() => setTooltipVisible(tooltipVisible === permission ? null : permission)}
                                                                            aria-label="View who can access restricted categories"
                                                                        >
                                                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                                                            </svg>
                                                                        </button>
                                                                        {tooltipVisible === permission && (
                                                                            <div className="absolute left-0 bottom-full mb-2 w-72 p-3 bg-gray-900 text-white text-xs rounded-lg shadow-xl z-50 pointer-events-none">
                                                                                <div className="font-semibold mb-2 text-white">Who can view restricted categories:</div>
                                                                                <ul className="list-disc list-inside space-y-1 text-gray-200">
                                                                                    <li><strong>Owners</strong> - automatic access</li>
                                                                                    <li><strong>Admins</strong> - automatic access</li>
                                                                                    <li><strong>Users with this permission</strong> - explicit access</li>
                                                                                </ul>
                                                                                <div className="absolute left-4 top-full w-0 h-0 border-l-[6px] border-r-[6px] border-t-[6px] border-transparent border-t-gray-900"></div>
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </td>
                                                        {company_roles.map((role) => {
                                                            const isChecked = company_role_permissions[role.id]?.[permission] ?? false
                                                            // Owners and admins automatically have access to restricted categories
                                                            const hasAutomaticAccess = isRestrictedCategoryPermission(permission) && 
                                                                (role.id === 'owner' || role.id === 'admin')
                                                            // Company owner/admin have full company-level access
                                                            const isCompanyOwnerOrAdmin = (role.id === 'owner' || role.id === 'admin')
                                                            return (
                                                                <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                                                    {isChecked || hasAutomaticAccess ? (
                                                                        <div className="flex items-center justify-center gap-1">
                                                                            <svg className={`h-5 w-5 ${isCompanyOwnerOrAdmin ? 'text-indigo-600' : 'text-green-500'}`} fill="currentColor" viewBox="0 0 20 20">
                                                                                <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                                            </svg>
                                                                            {isCompanyOwnerOrAdmin && (
                                                                                <svg className="h-4 w-4 text-indigo-500" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor" title="Company-level access (overrides brand restrictions)">
                                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                                                                                </svg>
                                                                            )}
                                                                            {hasAutomaticAccess && !isCompanyOwnerOrAdmin && (
                                                                                <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" title="Automatic access">
                                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                                                                </svg>
                                                                            )}
                                                                        </div>
                                                                    ) : (
                                                                        <span className="text-gray-300">—</span>
                                                                    )}
                                                                </td>
                                                            )
                                                        })}
                                                    </tr>
                                                ))}
                                            </React.Fragment>
                                        )
                                    })}

                                    {/* Render Brand-Level Permissions */}
                                    {Object.entries(brandLevelGroups).map(([groupKey, group]) => {
                                        const groupPermissions = group.permissions.filter(p => company_permissions.includes(p))
                                        if (groupPermissions.length === 0) return null

                                        return (
                                            <React.Fragment key={groupKey}>
                                                <tr className="bg-purple-50 border-t-2 border-purple-200">
                                                    <td colSpan={company_roles.length + 1} className="px-4 py-3">
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleSection(groupKey)}
                                                            className="flex items-center gap-2 text-sm font-semibold text-purple-900 hover:text-purple-700 w-full text-left"
                                                        >
                                                            {collapsedSections[groupKey] ? (
                                                                <ChevronRightIcon className="h-5 w-5" />
                                                            ) : (
                                                                <ChevronDownIcon className="h-5 w-5" />
                                                            )}
                                                            <span className="mr-2">🏷️</span>
                                                            <span>{group.title}</span>
                                                            <span className="ml-2 text-xs font-normal text-purple-600 bg-purple-100 px-2 py-0.5 rounded">Brand-Level</span>
                                                            <span className="ml-auto text-xs font-normal text-purple-500 italic">
                                                                (Company Owner/Admin override brand restrictions)
                                                            </span>
                                                        </button>
                                                    </td>
                                                </tr>
                                                {!collapsedSections[groupKey] && groupPermissions.map((permission) => (
                                                    <tr key={permission} className="bg-purple-50/30">
                                                        <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                                                            <div className="flex items-center gap-2">
                                                                <span>{formatPermissionName(permission)}</span>
                                                                {isRestrictedCategoryPermission(permission) && (
                                                                    <div className="relative inline-block">
                                                                        <button
                                                                            type="button"
                                                                            className="text-gray-400 hover:text-gray-600 focus:outline-none transition-colors"
                                                                            onMouseEnter={() => setTooltipVisible(permission)}
                                                                            onMouseLeave={() => setTooltipVisible(null)}
                                                                            onClick={() => setTooltipVisible(tooltipVisible === permission ? null : permission)}
                                                                            aria-label="View who can access restricted categories"
                                                                        >
                                                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                                                            </svg>
                                                                        </button>
                                                                        {tooltipVisible === permission && (
                                                                            <div className="absolute left-0 bottom-full mb-2 w-72 p-3 bg-gray-900 text-white text-xs rounded-lg shadow-xl z-50 pointer-events-none">
                                                                                <div className="font-semibold mb-2 text-white">Who can view restricted categories:</div>
                                                                                <ul className="list-disc list-inside space-y-1 text-gray-200">
                                                                                    <li><strong>Owners</strong> - automatic access</li>
                                                                                    <li><strong>Admins</strong> - automatic access</li>
                                                                                    <li><strong>Users with this permission</strong> - explicit access</li>
                                                                                </ul>
                                                                                <div className="absolute left-4 top-full w-0 h-0 border-l-[6px] border-r-[6px] border-t-[6px] border-transparent border-t-gray-900"></div>
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </td>
                                                        {company_roles.map((role) => {
                                                            const isChecked = company_role_permissions[role.id]?.[permission] ?? false
                                                            // Owners and admins automatically have access to restricted categories
                                                            const hasAutomaticAccess = isRestrictedCategoryPermission(permission) && 
                                                                (role.id === 'owner' || role.id === 'admin')
                                                            // Company owner/admin permissions override brand restrictions
                                                            const hasCompanyOverride = (role.id === 'owner' || role.id === 'admin') && isChecked
                                                            return (
                                                                <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                                                    {isChecked || hasAutomaticAccess ? (
                                                                        <div className="flex items-center justify-center gap-1">
                                                                            <svg className={`h-5 w-5 ${hasCompanyOverride ? 'text-indigo-600' : 'text-green-500'}`} fill="currentColor" viewBox="0 0 20 20">
                                                                                <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                                            </svg>
                                                                            {hasCompanyOverride && (
                                                                                <svg className="h-4 w-4 text-indigo-500" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor" title="Company-level override">
                                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                                                                                </svg>
                                                                            )}
                                                                            {hasAutomaticAccess && (
                                                                                <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" title="Automatic access">
                                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                                                                </svg>
                                                                            )}
                                                                        </div>
                                                                    ) : (
                                                                        <span className="text-gray-300">—</span>
                                                                    )}
                                                                </td>
                                                            )
                                                        })}
                                                    </tr>
                                                ))}
                                            </React.Fragment>
                                        )
                                    })}

                                    {/* Other permissions not in groups */}
                                    {otherPermissions.length > 0 && (
                                        <>
                                            <tr className="bg-gray-50">
                                                <td colSpan={company_roles.length + 1} className="px-4 py-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => toggleSection('other')}
                                                        className="flex items-center gap-2 text-sm font-semibold text-gray-900 hover:text-indigo-600 w-full text-left"
                                                    >
                                                        {collapsedSections.other ? (
                                                            <ChevronRightIcon className="h-5 w-5" />
                                                        ) : (
                                                            <ChevronDownIcon className="h-5 w-5" />
                                                        )}
                                                        Other Permissions
                                                    </button>
                                                </td>
                                            </tr>
                                            {!collapsedSections.other && otherPermissions.map((permission) => (
                                                <tr key={permission}>
                                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                                                        {formatPermissionName(permission)}
                                                    </td>
                                                    {company_roles.map((role) => {
                                                        const isChecked = company_role_permissions[role.id]?.[permission] ?? false
                                                        return (
                                                            <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                                                {isChecked ? (
                                                                    <svg className="h-5 w-5 text-green-500 mx-auto" fill="currentColor" viewBox="0 0 20 20">
                                                                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                                    </svg>
                                                                ) : (
                                                                    <span className="text-gray-300">—</span>
                                                                )}
                                                            </td>
                                                        )
                                                    })}
                                                </tr>
                                            ))}
                                        </>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
