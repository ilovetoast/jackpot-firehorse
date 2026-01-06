import { Link, usePage, router, useForm } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function AdminPermissions({ 
    site_roles, 
    company_roles, 
    site_permissions, 
    company_permissions,
    site_role_permissions = {},
    company_role_permissions = {}
}) {
    const { auth } = usePage().props
    const [saving, setSaving] = useState(false)
    const [showAddPermissionModal, setShowAddPermissionModal] = useState(false)
    const [newPermissionName, setNewPermissionName] = useState('')
    const [newPermissionType, setNewPermissionType] = useState('company') // 'company' or 'site'
    const { post: postForm, processing: creatingPermission, errors } = useForm()

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
        { id: 'brand_manager', name: 'Brand Manager', icon: '' },
        { id: 'member', name: 'Member', icon: '' },
    ]

    const defaultSitePermissions = site_permissions || [
        'company.manage',
        'permissions.manage',
    ]

    const defaultCompanyPermissions = company_permissions || [
        'billing.view',
        'billing.manage',
        'company_settings.view',
        'team.manage',
        'activity_logs.view',
        'brand_settings.manage',
        'brand_categories.manage',
    ]

    // State for permissions (loaded from backend props)
    const [sitePermissionsState, setSitePermissionsState] = useState(site_role_permissions || {})
    const [companyPermissionsState, setCompanyPermissionsState] = useState(company_role_permissions || {})

    // Sync state when props update (after save)
    useEffect(() => {
        setSitePermissionsState(site_role_permissions || {})
    }, [site_role_permissions])

    useEffect(() => {
        setCompanyPermissionsState(company_role_permissions || {})
    }, [company_role_permissions])

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

    const handleSaveSiteRoles = () => {
        setSaving(true)
        
        // Save all site roles (except site_owner)
        const rolesToSave = defaultSiteRoles.filter((role) => role.id !== 'site_owner')
        
        if (rolesToSave.length === 0) {
            setSaving(false)
            return
        }

        // Save roles sequentially
        let currentIndex = 0
        
        const saveNext = () => {
            if (currentIndex >= rolesToSave.length) {
                // All saves complete, reload permissions
                router.reload({ 
                    only: ['site_role_permissions', 'company_role_permissions'],
                    preserveScroll: true,
                    onFinish: () => {
                        setSaving(false)
                    }
                })
                return
            }

            const role = rolesToSave[currentIndex]
            currentIndex++

            router.post('/app/admin/permissions/site-role', {
                role_id: role.id,
                permissions: sitePermissionsState[role.id] || {},
            }, {
                preserveScroll: true,
                onFinish: () => {
                    // Continue to next role
                    saveNext()
                },
                onError: () => {
                    setSaving(false)
                }
            })
        }

        saveNext()
    }

    const handleSaveCompanyRoles = () => {
        setSaving(true)
        
        // Save all company roles (except owner)
        const rolesToSave = defaultCompanyRoles.filter((role) => role.id !== 'owner')
        
        if (rolesToSave.length === 0) {
            setSaving(false)
            return
        }

        // Save roles sequentially
        let currentIndex = 0
        
        const saveNext = () => {
            if (currentIndex >= rolesToSave.length) {
                // All saves complete, reload permissions
                router.reload({ 
                    only: ['site_role_permissions', 'company_role_permissions'],
                    preserveScroll: true,
                    onFinish: () => {
                        setSaving(false)
                    }
                })
                return
            }

            const role = rolesToSave[currentIndex]
            currentIndex++

            router.post('/app/admin/permissions/company-role', {
                role_id: role.id,
                permissions: companyPermissionsState[role.id] || {},
            }, {
                preserveScroll: true,
                onFinish: () => {
                    // Continue to next role
                    saveNext()
                },
                onError: () => {
                    setSaving(false)
                }
            })
        }

        saveNext()
    }

    const formatPermissionName = (permission) => {
        // Handle both underscore and dot notation
        return permission
            .replace(/[._]/g, ' ')
            .split(' ')
            .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
    }

    const handleAddPermission = (e) => {
        e.preventDefault()
        
        if (!newPermissionName.trim()) {
            return
        }

        // Convert to slug format (lowercase, dots/underscores allowed)
        const permissionSlug = newPermissionName
            .toLowerCase()
            .trim()
            .replace(/\s+/g, '.')
            .replace(/[^a-z0-9._]/g, '')

        postForm('/app/admin/permissions/create', {
            name: permissionSlug,
            type: newPermissionType,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setNewPermissionName('')
                setShowAddPermissionModal(false)
                router.reload({ 
                    only: ['site_permissions', 'company_permissions'],
                    preserveScroll: true,
                })
            },
        })
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
                            <div className="flex items-center justify-between">
                                <div className="flex items-center">
                                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 mr-3">
                                        <span className="text-indigo-600">🔒</span>
                                    </div>
                                    <div>
                                        <h2 className="text-lg font-semibold text-gray-900">Site Roles & Permissions</h2>
                                        <p className="mt-1 text-sm text-gray-500">Manage which site-wide permissions each site role has access to</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setNewPermissionType('site')
                                        setShowAddPermissionModal(true)
                                    }}
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                >
                                    ➕ Add Permission
                                </button>
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
                                                const isChecked = sitePermissionsState[role.id]?.[permission] ?? false
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
                        <div className="border-t border-gray-200 px-6 py-4 flex justify-end">
                            <button
                                type="button"
                                onClick={handleSaveSiteRoles}
                                disabled={saving}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                            >
                                {saving ? 'Saving...' : '💾 Save Site Permissions'}
                            </button>
                        </div>
                    </div>

                    {/* Company Roles & Permissions */}
                    <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="border-b border-gray-200 px-6 py-4">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center">
                                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 mr-3">
                                        <span className="text-indigo-600">🏢</span>
                                    </div>
                                    <div>
                                        <h2 className="text-lg font-semibold text-gray-900">Company Roles & Permissions</h2>
                                        <p className="mt-1 text-sm text-gray-500">Manage which permissions each company role has access to</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setNewPermissionType('company')
                                        setShowAddPermissionModal(true)
                                    }}
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                >
                                    ➕ Add Permission
                                </button>
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
                                                const isChecked = companyPermissionsState[role.id]?.[permission] ?? false
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
                        <div className="border-t border-gray-200 px-6 py-4 flex justify-end">
                            <button
                                type="button"
                                onClick={handleSaveCompanyRoles}
                                disabled={saving}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                            >
                                {saving ? 'Saving...' : '💾 Save Company Permissions'}
                            </button>
                        </div>
                    </div>

                    {/* Add Permission Modal */}
                    {showAddPermissionModal && (
                        <>
                            <div
                                className="fixed inset-0 z-50 bg-gray-500 bg-opacity-75 transition-opacity"
                                onClick={() => setShowAddPermissionModal(false)}
                            />
                            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                                <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                                    <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                                        <button
                                            type="button"
                                            className="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                            onClick={() => setShowAddPermissionModal(false)}
                                        >
                                            <span className="sr-only">Close</span>
                                            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                    <div className="sm:flex sm:items-start">
                                        <div className="mt-3 text-center sm:mt-0 sm:text-left w-full">
                                            <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                                Add New {newPermissionType === 'company' ? 'Company' : 'Site'} Permission
                                            </h3>
                                            <form onSubmit={handleAddPermission}>
                                                <div className="mb-4">
                                                    <label htmlFor="permission-name" className="block text-sm font-medium text-gray-700 mb-1">
                                                        Permission Name
                                                    </label>
                                                    <input
                                                        type="text"
                                                        id="permission-name"
                                                        value={newPermissionName}
                                                        onChange={(e) => setNewPermissionName(e.target.value)}
                                                        placeholder="e.g., reports.view or reports.manage"
                                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                        required
                                                    />
                                                    <p className="mt-1 text-xs text-gray-500">
                                                        Use dot notation (e.g., "reports.view"). Spaces will be converted to dots.
                                                    </p>
                                                    {errors.name && (
                                                        <p className="mt-1 text-sm text-red-600">{errors.name}</p>
                                                    )}
                                                </div>
                                                <div className="mb-4">
                                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                                        Permission Slug (for frontend)
                                                    </label>
                                                    <div className="mt-1 flex rounded-md shadow-sm">
                                                        <span className="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500">
                                                            Use in frontend:
                                                        </span>
                                                        <input
                                                            type="text"
                                                            readOnly
                                                            value={newPermissionName
                                                                .toLowerCase()
                                                                .trim()
                                                                .replace(/\s+/g, '.')
                                                                .replace(/[^a-z0-9._]/g, '') || 'permission.slug'}
                                                            className="block w-full flex-1 rounded-none rounded-r-md border-gray-300 bg-gray-50 px-3 py-2 text-sm font-mono text-gray-900 focus:border-indigo-500 focus:ring-indigo-500"
                                                        />
                                                    </div>
                                                </div>
                                                <div className="flex justify-end gap-3">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setShowAddPermissionModal(false)
                                                            setNewPermissionName('')
                                                        }}
                                                        className="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                    >
                                                        Cancel
                                                    </button>
                                                    <button
                                                        type="submit"
                                                        disabled={creatingPermission || !newPermissionName.trim()}
                                                        className="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                                    >
                                                        {creatingPermission ? 'Creating...' : 'Create Permission'}
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
