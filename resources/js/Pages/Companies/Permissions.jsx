import { Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function CompanyPermissions({ 
    tenant,
    company_roles, 
    company_permissions,
    company_role_permissions = {}
}) {
    const { auth } = usePage().props
    const [tooltipVisible, setTooltipVisible] = useState(null)

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

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={tenant} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Company Permissions</h1>
                        <p className="mt-2 text-sm text-gray-700">
                            View which permissions each role has for {tenant.name}. Owners and admins can view all restricted categories.
                        </p>
                    </div>

                    {/* Info Box */}
                    <div className="mb-8 rounded-lg bg-blue-50 border border-blue-200">
                        <div className="px-6 py-4">
                            <div className="flex items-start">
                                <div className="flex-shrink-0">
                                    <svg className="h-5 w-5 text-blue-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                                    </svg>
                                </div>
                                <div className="ml-3 flex-1">
                                    <h3 className="text-sm font-semibold text-blue-900 mb-2">About Restricted Categories</h3>
                                    <div className="text-sm text-blue-800 space-y-2">
                                        <p>
                                            <strong>Owners</strong> and <strong>Admins</strong> automatically have access to view all restricted (private) categories, regardless of category-level access rules. This ensures company administrators can always access important content.
                                        </p>
                                        <p>
                                            Users with the <strong>"View Any Restricted Categories"</strong> permission can also bypass category access rules and view all private categories.
                                        </p>
                                        <p className="mt-2 text-xs text-blue-700">
                                            Note: This page is read-only. To modify permissions, contact your system administrator.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
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
                                    {company_permissions.map((permission) => (
                                        <tr key={permission}>
                                            <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900">
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
                                                return (
                                                    <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                                        {isChecked || hasAutomaticAccess ? (
                                                            <div className="flex items-center justify-center gap-1">
                                                                <svg className="h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                                </svg>
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
