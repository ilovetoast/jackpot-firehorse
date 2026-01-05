import { Link, usePage, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function AdminPermissions({ site_roles, company_roles, site_permissions, company_permissions }) {
    const { auth } = usePage().props
    const { post, processing } = useForm()

    // Default roles and permissions structure
    const defaultSiteRoles = site_roles || [
        { id: 'site_owner', name: 'Site Owner', icon: '👑' },
        { id: 'site_admin', name: 'Site Admin', icon: '' },
        { id: 'site_support', name: 'Site Support', icon: '' },
        { id: 'compliance', name: 'Compliance', icon: '' },
    ]

    const defaultCompanyRoles = company_roles || [
        { id: 'owner', name: 'Owner', icon: '👑' },
        { id: 'admin', name: 'Admin', icon: '' },
        { id: 'member', name: 'Member', icon: '' },
    ]

    const defaultSitePermissions = site_permissions || [
        'billing_manage',
        'bridges_manage',
        'users_manage',
        'companies_manage',
        'plans_manage',
        'permissions_manage',
        'data_retention_legal_hold',
    ]

    const defaultCompanyPermissions = company_permissions || [
        'settings_view',
        'settings_update',
        'settings_delete',
        'api_key_rotate',
        'members_view',
        'members_invite',
        'members_remove',
        'members_update_role',
        'bridges_view',
        'bridges_create',
        'bridges_update',
        'bridges_delete',
        'bridges_test',
        'billing_view',
        'billing_manage',
        'logs_view',
    ]

    // State for permissions (will be loaded from backend)
    const [sitePermissionsState, setSitePermissionsState] = useState({})
    const [companyPermissionsState, setCompanyPermissionsState] = useState({})

    const handleSitePermissionChange = (roleId, permission) => {
        setSitePermissionsState((prev) => ({
            ...prev,
            [roleId]: {
                ...prev[roleId],
                [permission]: !prev[roleId]?.[permission],
            },
        }))
    }

    const handleCompanyPermissionChange = (roleId, permission) => {
        setCompanyPermissionsState((prev) => ({
            ...prev,
            [roleId]: {
                ...prev[roleId],
                [permission]: !prev[roleId]?.[permission],
            },
        }))
    }

    const handleSaveSiteRole = (roleId) => {
        post('/app/admin/permissions/site-role', {
            role_id: roleId,
            permissions: sitePermissionsState[roleId] || {},
        })
    }

    const handleSaveCompanyRole = (roleId) => {
        post('/app/admin/permissions/company-role', {
            role_id: roleId,
            permissions: companyPermissionsState[roleId] || {},
        })
    }

    const formatPermissionName = (permission) => {
        return permission.split('_').map((word) => word.charAt(0).toUpperCase() + word.slice(1)).join(' ')
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Permissions</h1>
                        <p className="mt-2 text-sm text-gray-700">Manage role permissions for site and company levels</p>
                    </div>

                    {/* Site Roles & Permissions */}
                    <div className="mb-8 rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="border-b border-gray-200 px-6 py-4">
                            <div className="flex items-center">
                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 mr-3">
                                    <span className="text-indigo-600">🔒</span>
                                </div>
                                <div>
                                    <h2 className="text-lg font-semibold text-gray-900">Site Roles & Permissions</h2>
                                    <p className="mt-1 text-sm text-gray-500">Manage which site-wide permissions each site role has access to</p>
                                </div>
                            </div>
                        </div>
                        <div className="px-6 py-4 overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Permission</th>
                                        {defaultSiteRoles.map((role) => (
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
                                    {defaultSitePermissions.map((permission) => (
                                        <tr key={permission}>
                                            <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900">{formatPermissionName(permission)}</td>
                                            {defaultSiteRoles.map((role) => {
                                                const isChecked = sitePermissionsState[role.id]?.[permission] ?? (role.id === 'site_owner')
                                                return (
                                                    <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                                        <input
                                                            type="checkbox"
                                                            checked={isChecked}
                                                            onChange={() => handleSitePermissionChange(role.id, permission)}
                                                            disabled={role.id === 'site_owner'}
                                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                        />
                                                    </td>
                                                )
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="border-t border-gray-200 px-6 py-4 flex gap-3">
                            {defaultSiteRoles.filter((role) => role.id !== 'site_owner').map((role) => (
                                <button
                                    key={role.id}
                                    type="button"
                                    onClick={() => handleSaveSiteRole(role.id)}
                                    disabled={processing}
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                >
                                    💾 Save {role.name}
                                </button>
                            ))}
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
                                    <p className="mt-1 text-sm text-gray-500">Manage which permissions each company role has access to</p>
                                </div>
                            </div>
                        </div>
                        <div className="px-6 py-4 overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Permission</th>
                                        {defaultCompanyRoles.map((role) => (
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
                                    {defaultCompanyPermissions.map((permission) => (
                                        <tr key={permission}>
                                            <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900">{formatPermissionName(permission)}</td>
                                            {defaultCompanyRoles.map((role) => {
                                                const isChecked = companyPermissionsState[role.id]?.[permission] ?? (role.id === 'owner')
                                                return (
                                                    <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                                        <input
                                                            type="checkbox"
                                                            checked={isChecked}
                                                            onChange={() => handleCompanyPermissionChange(role.id, permission)}
                                                            disabled={role.id === 'owner'}
                                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                        />
                                                    </td>
                                                )
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="border-t border-gray-200 px-6 py-4 flex gap-3">
                            {defaultCompanyRoles.filter((role) => role.id !== 'owner').map((role) => (
                                <button
                                    key={role.id}
                                    type="button"
                                    onClick={() => handleSaveCompanyRole(role.id)}
                                    disabled={processing}
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                >
                                    💾 Save {role.name}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
