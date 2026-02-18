import { Link, usePage, router, useForm } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import { ChevronDownIcon, ChevronRightIcon, InformationCircleIcon } from '@heroicons/react/24/outline'

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
    const [creatingPermission, setCreatingPermission] = useState(false)
    const [errors, setErrors] = useState({})
    const [activeTab, setActiveTab] = useState('system') // 'system', 'tenant', or 'debug'
    const [collapsedSections, setCollapsedSections] = useState({
        systemManagement: false,
        ticketManagement: false,
        ticketViewing: false,
        ticketActions: false,
        ticketEngineering: false,
        aiDashboard: false,
        // Tenant permission groups
        companySettings: false,
        companyManagement: false,
        assetManagement: false,
        metadataManagement: false,
        categoryManagement: false,
        ticketManagementTenant: false,
        governance: false,
        tagManagement: false,
    })
    const [showTenantInfo, setShowTenantInfo] = useState(false)

    // Permission Debug (Admin Only)
    const [debugUserId, setDebugUserId] = useState('')
    const [debugTenantId, setDebugTenantId] = useState('')
    const [debugBrandId, setDebugBrandId] = useState('')
    const [debugPermission, setDebugPermission] = useState('assets.delete')
    const [debugResult, setDebugResult] = useState(null)
    const [debugLoading, setDebugLoading] = useState(false)

    // Default roles and permissions structure
    const defaultSiteRoles = site_roles || [
        { id: 'site_owner', name: 'Site Owner', icon: '👑' },
        { id: 'site_admin', name: 'Site Admin', icon: '' },
        { id: 'site_support', name: 'Site Support', icon: '' },
        { id: 'site_engineering', name: 'Site Engineering', icon: '' },
        { id: 'site_compliance', name: 'Site Compliance', icon: '' },
    ]

    // Load tenant and brand roles from API (canonical source)
    const [tenantRoles, setTenantRoles] = useState([])
    const [brandRoles, setBrandRoles] = useState([])
    const [tenantRolePermissions, setTenantRolePermissions] = useState({})
    const [brandRolePermissions, setBrandRolePermissions] = useState({})
    const [loadingRoles, setLoadingRoles] = useState(true)

    // Load roles and permissions from API on mount
    useEffect(() => {
        const loadRoles = async () => {
            try {
                // Load tenant roles and permissions
                const tenantRes = await fetch('/app/api/permissions/tenant')
                const tenantData = await tenantRes.json()
                setTenantRoles(tenantData.roles || [])
                
                // Build tenant role permissions map
                const tenantPerms = {}
                tenantData.roles?.forEach(roleData => {
                    tenantPerms[roleData.role] = roleData.permissions.reduce((acc, perm) => {
                        acc[perm] = true
                        return acc
                    }, {})
                })
                setTenantRolePermissions(tenantPerms)

                // Load brand roles and permissions
                const brandRes = await fetch('/app/api/permissions/brand')
                const brandData = await brandRes.json()
                setBrandRoles(brandData.roles || [])
                
                // Build brand role permissions map
                const brandPerms = {}
                brandData.roles?.forEach(roleData => {
                    brandPerms[roleData.role] = roleData.permissions.reduce((acc, perm) => {
                        acc[perm] = true
                        return acc
                    }, {})
                })
                setBrandRolePermissions(brandPerms)

                // NOTE: Do NOT overwrite companyPermissionsState with API data.
                // The API returns PermissionMap defaults; the actual DB state comes from
                // company_role_permissions (backend props). Merging brandPerms would overwrite
                // tenant roles (e.g. 'admin') with brand permissions, hiding team.manage etc.

                setLoadingRoles(false)
            } catch (error) {
                console.error('Failed to load roles from API:', error)
                // Fallback to props if API fails
                setTenantRoles(company_roles?.filter(r => r.id !== 'owner' && !r.id.startsWith('site_')) || [])
                setBrandRoles([])
                setLoadingRoles(false)
            }
        }

        loadRoles()
    }, [])

    // Fallback to props for backward compatibility (legacy roles not in API)
    const defaultCompanyRoles = company_roles || []
    
    // Combine API-loaded tenant roles with legacy roles from props (for backward compatibility)
    // Filter out owner (never shown) and site roles
    const allCompanyRoles = [
        ...tenantRoles.map(r => ({ id: r.role, name: r.role.charAt(0).toUpperCase() + r.role.slice(1), icon: '' })),
        ...defaultCompanyRoles.filter(r => 
            r.id !== 'owner' && 
            !r.id.startsWith('site_') && 
            !tenantRoles.find(tr => tr.role === r.id)
        )
    ]

    // Default permission assignments (from PermissionSeeder)
    const defaultSiteRolePermissions = {
        site_owner: [
            'company.manage',
            'permissions.manage',
            'tickets.view_any',
            'tickets.view_tenant',
            'tickets.create',
            'tickets.reply',
            'tickets.view_staff',
            'tickets.assign',
            'tickets.add_internal_note',
            'tickets.convert',
            'tickets.view_sla',
            'tickets.view_audit_log',
            'tickets.create_engineering',
            'tickets.view_engineering',
            'tickets.link_diagnostic',
        ],
        site_admin: [
            'tickets.view_staff',
            'tickets.assign',
            'tickets.add_internal_note',
            'tickets.convert',
            'tickets.view_sla',
            'tickets.create_engineering',
            'tickets.view_engineering',
            'tickets.link_diagnostic',
            'ai.dashboard.view',
            'ai.dashboard.manage',
        ],
        site_support: [
            'tickets.view_staff',
            'tickets.assign',
            'tickets.add_internal_note',
            'tickets.view_sla',
        ],
        site_engineering: [
            'tickets.view_staff',
            'tickets.view_engineering',
            'tickets.create_engineering',
            'tickets.add_internal_note',
            'tickets.link_diagnostic',
            'tickets.view_sla',
        ],
        site_compliance: [
            'tickets.view_staff',
            'tickets.view_engineering',
            'tickets.view_audit_log',
            'ai.dashboard.view',
        ],
    }

    // Company permission groups (collapsible, like system permissions)
    const companyPermissionGroups = {
        companySettings: {
            title: 'Company Settings',
            permissions: [
                'company_settings.view',
                'company_settings.edit',
                'company_settings.manage_download_policy',
                'company_settings.manage_dashboard_widgets',
                'company_settings.manage_ai_settings',
                'company_settings.view_tag_quality',
                'company_settings.ownership_transfer',
                'company_settings.delete_company',
            ],
        },
        companyManagement: {
            title: 'Company Management',
            permissions: ['team.manage', 'billing.view', 'billing.manage', 'activity_logs.view', 'brand_settings.manage'],
        },
        assetManagement: {
            title: 'Asset Management',
            permissions: ['asset.view', 'asset.download', 'asset.upload', 'assets.retry_thumbnails', 'asset.publish', 'asset.unpublish', 'asset.archive', 'asset.restore'],
        },
        metadataManagement: {
            title: 'Metadata Management',
            permissions: [
                'metadata.set_on_upload',
                'metadata.edit_post_upload',
                'metadata.bypass_approval',
                'metadata.override_automatic',
                'metadata.review_candidates',
                'metadata.bulk_edit',
                'metadata.suggestions.view',
                'metadata.suggestions.apply',
                'metadata.suggestions.dismiss',
                'assets.ai_metadata.regenerate',
                'metadata.fields.manage',
                'metadata.fields.values.manage',
                'ai.usage.view',
            ],
        },
        categoryManagement: {
            title: 'Category Management',
            permissions: ['brand_categories.manage', 'view.restricted.categories'],
        },
        ticketManagementTenant: {
            title: 'Tickets',
            permissions: ['tickets.create', 'tickets.reply', 'tickets.view_tenant', 'tickets.view_any'],
        },
        governance: {
            title: 'Metadata Governance',
            permissions: [
                'metadata.registry.view',
                'metadata.tenant.visibility.manage',
                'metadata.tenant.field.create',
                'metadata.tenant.field.manage',
            ],
        },
        tagManagement: {
            title: 'Tag Management',
            permissions: ['assets.tags.create', 'assets.tags.delete'],
        },
    }

    const defaultCompanyRolePermissions = {
        owner: [
            'billing.view',
            'billing.manage',
            'company_settings.view',
            'company_settings.edit',
            'company_settings.manage_download_policy',
            'company_settings.manage_dashboard_widgets',
            'company_settings.manage_ai_settings',
            'company_settings.view_tag_quality',
            'company_settings.ownership_transfer',
            'company_settings.delete_company',
            'team.manage',
            'activity_logs.view',
            'brand_settings.manage',
            'brand_categories.manage',
            'view.restricted.categories',
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
            'tickets.view_any',
        ],
        admin: [
            'billing.view',
            'billing.manage',
            'company_settings.view',
            'company_settings.edit',
            'company_settings.manage_download_policy',
            'company_settings.manage_dashboard_widgets',
            'company_settings.manage_ai_settings',
            'company_settings.view_tag_quality',
            'team.manage',
            'activity_logs.view',
            'brand_settings.manage',
            'brand_categories.manage',
            'view.restricted.categories',
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
            'tickets.view_any',
        ],
        manager: [
            'billing.view',
            'billing.manage',
            'company_settings.view',
            'team.manage',
            'activity_logs.view',
            'brand_settings.manage',
            'brand_categories.manage',
            'view.restricted.categories',
            'assets.retry_thumbnails',
            'asset.view',
            'asset.download',
            'asset.upload',
            'metadata.set_on_upload',
            'metadata.edit_post_upload',
            'metadata.bypass_approval',
            'metadata.override_automatic',
            'metadata.review_candidates',
            'metadata.bulk_edit',
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
        ],
        contributor: [
            'asset.view',
            'asset.download',
            'asset.upload',
            'metadata.set_on_upload',
            'metadata.edit_post_upload',
            'metadata.review_candidates',
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
        ],
        uploader: [
            'asset.view',
            'asset.download',
            'asset.upload',
            'metadata.set_on_upload',
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
        ],
        viewer: [
            'asset.view',
            'asset.download',
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
        ],
        brand_manager: [
            'brand_settings.manage',
            'brand_categories.manage',
            'billing.view',
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
        ],
        member: [
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
        ],
    }

    // Group site permissions by category
    const sitePermissionGroups = {
        systemManagement: {
            title: 'System Management',
            permissions: ['company.manage', 'permissions.manage'],
        },
        ticketViewing: {
            title: 'Ticket Viewing',
            permissions: ['tickets.view_any', 'tickets.view_tenant', 'tickets.view_staff', 'tickets.view_engineering'],
        },
        ticketActions: {
            title: 'Ticket Actions',
            permissions: ['tickets.create', 'tickets.reply', 'tickets.assign', 'tickets.add_internal_note', 'tickets.convert'],
        },
        ticketEngineering: {
            title: 'Engineering Tickets',
            permissions: ['tickets.create_engineering', 'tickets.view_engineering', 'tickets.link_diagnostic'],
        },
        ticketManagement: {
            title: 'Ticket Management',
            permissions: ['tickets.view_sla', 'tickets.view_audit_log'],
        },
        aiDashboard: {
            title: 'AI Dashboard',
            permissions: ['ai.dashboard.view', 'ai.dashboard.manage'],
        },
    }

    // Get all site permissions from groups and any additional ones
    const allSitePermissions = [
        ...sitePermissionGroups.systemManagement.permissions,
        ...sitePermissionGroups.ticketViewing.permissions,
        ...sitePermissionGroups.ticketActions.permissions,
        ...sitePermissionGroups.ticketEngineering.permissions,
        ...sitePermissionGroups.ticketManagement.permissions,
        // Add any permissions from backend that aren't in groups
        ...(site_permissions || []).filter(p => !Object.values(sitePermissionGroups).some(g => g.permissions.includes(p))),
    ]

    // State for permissions (loaded from backend props and API)
    const [sitePermissionsState, setSitePermissionsState] = useState(site_role_permissions || {})
    const [companyPermissionsState, setCompanyPermissionsState] = useState(() => {
        // Merge API-loaded permissions with backend props
        const merged = { ...company_role_permissions }
        // API permissions will be merged in useEffect after loading
        return merged
    })

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

    const toggleSection = (sectionKey) => {
        setCollapsedSections(prev => ({
            ...prev,
            [sectionKey]: !prev[sectionKey],
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
                onSuccess: () => {
                    saveNext()
                },
                onError: (errors) => {
                    setSaving(false)
                    if (Object.keys(errors || {}).length > 0) {
                        console.error('Failed to save site permissions for role:', role.id, errors)
                    }
                }
            })
        }

        saveNext()
    }

    const handleSaveCompanyRoles = () => {
        setSaving(true)
        
        // Save all company roles (except owner and site roles)
        const rolesToSave = allCompanyRoles.filter((role) => 
            role.id !== 'owner' && 
            !role.id.startsWith('site_')
        )
        
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
                onSuccess: () => {
                    saveNext()
                },
                onError: (errors) => {
                    setSaving(false)
                    if (Object.keys(errors || {}).length > 0) {
                        console.error('Failed to save permissions for role:', role.id, errors)
                    }
                },
                onFinish: () => {
                    // If we errored, onError already set saving=false
                }
            })
        }

        saveNext()
    }

    // Map permission slugs to display names (align with Company Settings section names)
    const permissionLabels = {
        'company_settings.view': 'View Company Settings Page',
        'company_settings.edit': 'Company Information',
        'company_settings.manage_download_policy': 'Enterprise Download Policy',
        'company_settings.manage_dashboard_widgets': 'Dashboard Widgets',
        'company_settings.manage_ai_settings': 'AI Settings',
        'company_settings.view_tag_quality': 'Tag Quality',
        'company_settings.ownership_transfer': 'Ownership Transfer',
        'company_settings.delete_company': 'Delete Company',
        'team.manage': 'Team Members',
        'billing.view': 'Plan & Billing (View)',
        'billing.manage': 'Plan & Billing (Manage)',
        'brand_settings.manage': 'Brands Settings',
        'activity_logs.view': 'Activity Logs',
        'ai.usage.view': 'AI Usage',
    }

    const formatPermissionName = (permission, isCompany = false) => {
        if (isCompany && permissionLabels[permission]) {
            return permissionLabels[permission]
        }
        return permission
            .replace(/[._]/g, ' ')
            .split(' ')
            .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
    }

    const isDefaultPermission = (roleId, permission, isSite = true) => {
        const defaults = isSite ? defaultSiteRolePermissions : defaultCompanyRolePermissions
        return defaults[roleId]?.includes(permission) ?? false
    }

    const handleAddPermission = (e) => {
        e.preventDefault()
        
        if (!newPermissionName.trim()) {
            return
        }

        // Ensure type is set (default to 'company' if not set)
        const permissionType = newPermissionType || 'company'

        // Convert to slug format (lowercase, dots/underscores allowed)
        const permissionSlug = newPermissionName
            .toLowerCase()
            .trim()
            .replace(/\s+/g, '.')
            .replace(/[^a-z0-9._]/g, '')

        if (!permissionSlug) {
            return
        }

        setCreatingPermission(true)
        setErrors({})

        router.post('/app/admin/permissions/create', {
            name: permissionSlug,
            type: permissionType,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setNewPermissionName('')
                setShowAddPermissionModal(false)
                setCreatingPermission(false)
                router.reload({ 
                    only: ['site_permissions', 'company_permissions'],
                    preserveScroll: true,
                })
            },
            onError: (pageErrors) => {
                setErrors(pageErrors)
                setCreatingPermission(false)
            },
        })
    }

    const renderSitePermissionsTable = () => {
        return (
            <div className="overflow-x-auto">
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
                        {/* System Management Section */}
                        <tr className="bg-gray-50">
                            <td colSpan={defaultSiteRoles.length + 1} className="px-4 py-2">
                                <button
                                    type="button"
                                    onClick={() => toggleSection('systemManagement')}
                                    className="flex items-center gap-2 text-sm font-semibold text-gray-900 hover:text-indigo-600"
                                >
                                    {collapsedSections.systemManagement ? (
                                        <ChevronRightIcon className="h-5 w-5" />
                                    ) : (
                                        <ChevronDownIcon className="h-5 w-5" />
                                    )}
                                    {sitePermissionGroups.systemManagement.title}
                                </button>
                            </td>
                        </tr>
                        {!collapsedSections.systemManagement && sitePermissionGroups.systemManagement.permissions.map((permission) => (
                            <tr key={permission}>
                                <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                                    {formatPermissionName(permission)}
                                </td>
                                {defaultSiteRoles.map((role) => {
                                    const isChecked = sitePermissionsState[role.id]?.[permission] ?? false
                                    const isDefault = isDefaultPermission(role.id, permission, true)
                                    return (
                                        <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                            <div className="flex items-center justify-center gap-1">
                                                <input
                                                    type="checkbox"
                                                    checked={isChecked}
                                                    onChange={() => handleSitePermissionChange(role.id, permission)}
                                                    disabled={role.id === 'site_owner'}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                                {isDefault && (
                                                    <span className="text-xs text-gray-400" title="Default permission">●</span>
                                                )}
                                            </div>
                                        </td>
                                    )
                                })}
                            </tr>
                        ))}

                        {/* Ticket Viewing Section */}
                        <tr className="bg-gray-50">
                            <td colSpan={defaultSiteRoles.length + 1} className="px-4 py-2">
                                <button
                                    type="button"
                                    onClick={() => toggleSection('ticketViewing')}
                                    className="flex items-center gap-2 text-sm font-semibold text-gray-900 hover:text-indigo-600"
                                >
                                    {collapsedSections.ticketViewing ? (
                                        <ChevronRightIcon className="h-5 w-5" />
                                    ) : (
                                        <ChevronDownIcon className="h-5 w-5" />
                                    )}
                                    {sitePermissionGroups.ticketViewing.title}
                                </button>
                            </td>
                        </tr>
                        {!collapsedSections.ticketViewing && sitePermissionGroups.ticketViewing.permissions.map((permission) => (
                            <tr key={permission}>
                                <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                                    {formatPermissionName(permission)}
                                </td>
                                {defaultSiteRoles.map((role) => {
                                    const isChecked = sitePermissionsState[role.id]?.[permission] ?? false
                                    const isDefault = isDefaultPermission(role.id, permission, true)
                                    return (
                                        <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                            <div className="flex items-center justify-center gap-1">
                                                <input
                                                    type="checkbox"
                                                    checked={isChecked}
                                                    onChange={() => handleSitePermissionChange(role.id, permission)}
                                                    disabled={role.id === 'site_owner'}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                                {isDefault && (
                                                    <span className="text-xs text-gray-400" title="Default permission">●</span>
                                                )}
                                            </div>
                                        </td>
                                    )
                                })}
                            </tr>
                        ))}

                        {/* Ticket Actions Section */}
                        <tr className="bg-gray-50">
                            <td colSpan={defaultSiteRoles.length + 1} className="px-4 py-2">
                                <button
                                    type="button"
                                    onClick={() => toggleSection('ticketActions')}
                                    className="flex items-center gap-2 text-sm font-semibold text-gray-900 hover:text-indigo-600"
                                >
                                    {collapsedSections.ticketActions ? (
                                        <ChevronRightIcon className="h-5 w-5" />
                                    ) : (
                                        <ChevronDownIcon className="h-5 w-5" />
                                    )}
                                    {sitePermissionGroups.ticketActions.title}
                                </button>
                            </td>
                        </tr>
                        {!collapsedSections.ticketActions && sitePermissionGroups.ticketActions.permissions.map((permission) => (
                            <tr key={permission}>
                                <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                                    {formatPermissionName(permission)}
                                </td>
                                {defaultSiteRoles.map((role) => {
                                    const isChecked = sitePermissionsState[role.id]?.[permission] ?? false
                                    const isDefault = isDefaultPermission(role.id, permission, true)
                                    return (
                                        <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                            <div className="flex items-center justify-center gap-1">
                                                <input
                                                    type="checkbox"
                                                    checked={isChecked}
                                                    onChange={() => handleSitePermissionChange(role.id, permission)}
                                                    disabled={role.id === 'site_owner'}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                                {isDefault && (
                                                    <span className="text-xs text-gray-400" title="Default permission">●</span>
                                                )}
                                            </div>
                                        </td>
                                    )
                                })}
                            </tr>
                        ))}

                        {/* Engineering Tickets Section */}
                        <tr className="bg-gray-50">
                            <td colSpan={defaultSiteRoles.length + 1} className="px-4 py-2">
                                <button
                                    type="button"
                                    onClick={() => toggleSection('ticketEngineering')}
                                    className="flex items-center gap-2 text-sm font-semibold text-gray-900 hover:text-indigo-600"
                                >
                                    {collapsedSections.ticketEngineering ? (
                                        <ChevronRightIcon className="h-5 w-5" />
                                    ) : (
                                        <ChevronDownIcon className="h-5 w-5" />
                                    )}
                                    {sitePermissionGroups.ticketEngineering.title}
                                </button>
                            </td>
                        </tr>
                        {!collapsedSections.ticketEngineering && sitePermissionGroups.ticketEngineering.permissions.map((permission) => (
                            <tr key={permission}>
                                <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                                    {formatPermissionName(permission)}
                                </td>
                                {defaultSiteRoles.map((role) => {
                                    const isChecked = sitePermissionsState[role.id]?.[permission] ?? false
                                    const isDefault = isDefaultPermission(role.id, permission, true)
                                    return (
                                        <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                            <div className="flex items-center justify-center gap-1">
                                                <input
                                                    type="checkbox"
                                                    checked={isChecked}
                                                    onChange={() => handleSitePermissionChange(role.id, permission)}
                                                    disabled={role.id === 'site_owner'}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                                {isDefault && (
                                                    <span className="text-xs text-gray-400" title="Default permission">●</span>
                                                )}
                                            </div>
                                        </td>
                                    )
                                })}
                            </tr>
                        ))}

                        {/* Ticket Management Section */}
                        <tr className="bg-gray-50">
                            <td colSpan={defaultSiteRoles.length + 1} className="px-4 py-2">
                                <button
                                    type="button"
                                    onClick={() => toggleSection('ticketManagement')}
                                    className="flex items-center gap-2 text-sm font-semibold text-gray-900 hover:text-indigo-600"
                                >
                                    {collapsedSections.ticketManagement ? (
                                        <ChevronRightIcon className="h-5 w-5" />
                                    ) : (
                                        <ChevronDownIcon className="h-5 w-5" />
                                    )}
                                    {sitePermissionGroups.ticketManagement.title}
                                </button>
                            </td>
                        </tr>
                        {!collapsedSections.ticketManagement && sitePermissionGroups.ticketManagement.permissions.map((permission) => (
                            <tr key={permission}>
                                <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                                    {formatPermissionName(permission)}
                                </td>
                                {defaultSiteRoles.map((role) => {
                                    const isChecked = sitePermissionsState[role.id]?.[permission] ?? false
                                    const isDefault = isDefaultPermission(role.id, permission, true)
                                    return (
                                        <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                            <div className="flex items-center justify-center gap-1">
                                                <input
                                                    type="checkbox"
                                                    checked={isChecked}
                                                    onChange={() => handleSitePermissionChange(role.id, permission)}
                                                    disabled={role.id === 'site_owner'}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                                {isDefault && (
                                                    <span className="text-xs text-gray-400" title="Default permission">●</span>
                                                )}
                                            </div>
                                        </td>
                                    )
                                })}
                            </tr>
                        ))}

                        {/* AI Dashboard Section */}
                        <tr className="bg-gray-50">
                            <td colSpan={defaultSiteRoles.length + 1} className="px-4 py-2">
                                <button
                                    type="button"
                                    onClick={() => toggleSection('aiDashboard')}
                                    className="flex items-center gap-2 text-sm font-semibold text-gray-900 hover:text-indigo-600"
                                >
                                    {collapsedSections.aiDashboard ? (
                                        <ChevronRightIcon className="h-5 w-5" />
                                    ) : (
                                        <ChevronDownIcon className="h-5 w-5" />
                                    )}
                                    {sitePermissionGroups.aiDashboard.title}
                                </button>
                            </td>
                        </tr>
                        {!collapsedSections.aiDashboard && sitePermissionGroups.aiDashboard.permissions.map((permission) => (
                            <tr key={permission}>
                                <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                                    {formatPermissionName(permission)}
                                </td>
                                {defaultSiteRoles.map((role) => {
                                    const isChecked = sitePermissionsState[role.id]?.[permission] ?? false
                                    const isDefault = isDefaultPermission(role.id, permission, true)
                                    return (
                                        <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                            <div className="flex items-center justify-center gap-1">
                                                <input
                                                    type="checkbox"
                                                    checked={isChecked}
                                                    onChange={() => handleSitePermissionChange(role.id, permission)}
                                                    disabled={role.id === 'site_owner'}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                                {isDefault && (
                                                    <span className="text-xs text-gray-400" title="Default permission">●</span>
                                                )}
                                            </div>
                                        </td>
                                    )
                                })}
                            </tr>
                        ))}

                        {/* Any additional permissions not in groups */}
                        {(site_permissions || []).filter(p => !Object.values(sitePermissionGroups).some(g => g.permissions.includes(p))).length > 0 && (
                            <>
                                <tr className="bg-gray-50">
                                    <td colSpan={defaultSiteRoles.length + 1} className="px-4 py-2">
                                        <span className="text-sm font-semibold text-gray-900">Other Permissions</span>
                                    </td>
                                </tr>
                                {(site_permissions || []).filter(p => !Object.values(sitePermissionGroups).some(g => g.permissions.includes(p))).map((permission) => (
                                    <tr key={permission}>
                                        <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                                            {formatPermissionName(permission)}
                                        </td>
                                        {defaultSiteRoles.map((role) => {
                                            const isChecked = sitePermissionsState[role.id]?.[permission] ?? false
                                            const isDefault = isDefaultPermission(role.id, permission, true)
                                            return (
                                                <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                                    <div className="flex items-center justify-center gap-1">
                                                        <input
                                                            type="checkbox"
                                                            checked={isChecked}
                                                            onChange={() => handleSitePermissionChange(role.id, permission)}
                                                            disabled={role.id === 'site_owner'}
                                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                        />
                                                        {isDefault && (
                                                            <span className="text-xs text-gray-400" title="Default permission">●</span>
                                                        )}
                                                    </div>
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
        )
    }

    const renderCompanyPermissionGroup = (sectionKey, group, rolesToDisplay) => {
        // Show all permissions in the group (don't filter by company_permissions - user may need to run seeder)
        const permissions = group.permissions || []
        if (permissions.length === 0) return null

        const isCollapsed = collapsedSections[sectionKey] ?? false
        return (
            <>
                <tr className="bg-gray-50">
                    <td colSpan={rolesToDisplay.length + 1} className="px-4 py-2">
                        <button
                            type="button"
                            onClick={() => toggleSection(sectionKey)}
                            className="flex items-center gap-2 text-sm font-semibold text-gray-900 hover:text-indigo-600"
                        >
                            {isCollapsed ? (
                                <ChevronRightIcon className="h-5 w-5" />
                            ) : (
                                <ChevronDownIcon className="h-5 w-5" />
                            )}
                            {group.title}
                        </button>
                    </td>
                </tr>
                {!isCollapsed && permissions.map((permission) => (
                    <tr key={permission}>
                        <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                            {formatPermissionName(permission, true)}
                        </td>
                        {rolesToDisplay.map((role) => {
                            const isChecked = companyPermissionsState[role.id]?.[permission] ?? false
                            const isDefault = isDefaultPermission(role.id, permission, false)
                            return (
                                <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                    <div className="flex items-center justify-center gap-1">
                                        <input
                                            type="checkbox"
                                            checked={isChecked}
                                            onChange={() => handleCompanyPermissionChange(role.id, permission)}
                                            disabled={role.id === 'owner'}
                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                        />
                                        {isDefault && (
                                            <span className="text-xs text-gray-400" title="Default permission">●</span>
                                        )}
                                    </div>
                                </td>
                            )
                        })}
                    </tr>
                ))}
            </>
        )
    }

    const renderCompanyPermissionsTable = () => {
        const rolesToDisplay = allCompanyRoles.filter(r => 
            r.id !== 'owner' && 
            !r.id.startsWith('site_')
        )

        if (loadingRoles) {
            return <div className="text-center py-8 text-gray-500">Loading roles...</div>
        }

        const groupedPermissionNames = Object.values(companyPermissionGroups).flatMap(g => g.permissions)
        const otherPermissions = (company_permissions || []).filter(p => !groupedPermissionNames.includes(p))

        return (
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Permission</th>
                            {rolesToDisplay.map((role) => (
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
                        {Object.entries(companyPermissionGroups).map(([sectionKey, group]) =>
                            renderCompanyPermissionGroup(sectionKey, group, rolesToDisplay)
                        )}
                        {otherPermissions.length > 0 && (
                            <>
                                <tr className="bg-gray-50">
                                    <td colSpan={rolesToDisplay.length + 1} className="px-4 py-2">
                                        <span className="text-sm font-semibold text-gray-900">Other Permissions</span>
                                    </td>
                                </tr>
                                {otherPermissions.map((permission) => (
                                    <tr key={permission}>
                                        <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 pl-8">
                                            {formatPermissionName(permission, true)}
                                        </td>
                                        {rolesToDisplay.map((role) => {
                                            const isChecked = companyPermissionsState[role.id]?.[permission] ?? false
                                            const isDefault = isDefaultPermission(role.id, permission, false)
                                            return (
                                                <td key={role.id} className="whitespace-nowrap px-4 py-3 text-center">
                                                    <div className="flex items-center justify-center gap-1">
                                                        <input
                                                            type="checkbox"
                                                            checked={isChecked}
                                                            onChange={() => handleCompanyPermissionChange(role.id, permission)}
                                                            disabled={role.id === 'owner'}
                                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                        />
                                                        {isDefault && (
                                                            <span className="text-xs text-gray-400" title="Default permission">●</span>
                                                        )}
                                                    </div>
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
        )
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

                    {/* Tabs */}
                    <div className="mb-6 border-b border-gray-200">
                        <nav className="-mb-px flex space-x-8" aria-label="Tabs">
                            <button
                                onClick={() => setActiveTab('system')}
                                className={`
                                    ${activeTab === 'system' 
                                        ? 'border-indigo-500 text-indigo-600' 
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                    }
                                    whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium
                                `}
                            >
                                System Permissions
                            </button>
                            <button
                                onClick={() => setActiveTab('tenant')}
                                className={`
                                    ${activeTab === 'tenant' 
                                        ? 'border-indigo-500 text-indigo-600' 
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                    }
                                    whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium
                                `}
                            >
                                Tenant Permissions
                            </button>
                            <button
                                onClick={() => setActiveTab('debug')}
                                className={`
                                    ${activeTab === 'debug' 
                                        ? 'border-indigo-500 text-indigo-600' 
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                    }
                                    whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium
                                `}
                            >
                                Permission Debug
                            </button>
                        </nav>
                    </div>

                    {/* System Permissions Tab */}
                    {activeTab === 'system' && (
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
                                            <p className="mt-1 text-xs text-gray-400">● indicates default permissions for each role</p>
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
                            <div className="px-6 py-4">
                                {renderSitePermissionsTable()}
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
                    )}

                    {/* Tenant Permissions Tab */}
                    {activeTab === 'tenant' && (
                        <>
                            {/* Collapsible Info Box */}
                            <div className="mb-6 rounded-lg bg-blue-50 border border-blue-200">
                                <button
                                    type="button"
                                    onClick={() => setShowTenantInfo(!showTenantInfo)}
                                    className="w-full px-6 py-4 flex items-center justify-between text-left"
                                >
                                    <div className="flex items-center gap-3">
                                        <InformationCircleIcon className="h-5 w-5 text-blue-400 flex-shrink-0" />
                                        <h3 className="text-sm font-semibold text-blue-900">Understanding Tenant Roles vs Brand Roles</h3>
                                    </div>
                                    {showTenantInfo ? (
                                        <ChevronDownIcon className="h-5 w-5 text-blue-400" />
                                    ) : (
                                        <ChevronRightIcon className="h-5 w-5 text-blue-400" />
                                    )}
                                </button>
                                {showTenantInfo && (
                                    <div className="px-6 pb-4">
                                        <div className="text-sm text-blue-800 space-y-2">
                                            <p>
                                                <strong>Tenant-level roles</strong> (shown below) and <strong>brand-level roles</strong> are <strong>separate and can be different</strong>. Both use the same role names (owner, admin, brand_manager, member), but they control different scopes:
                                            </p>
                                            <div className="ml-4 space-y-1">
                                                <p>
                                                    <strong>Tenant Roles:</strong> Control access to company-wide features like billing, team management, company settings, and activity logs. Each user has ONE tenant role per company.
                                                </p>
                                                <p>
                                                    <strong>Brand Roles:</strong> Control access to brand-specific features like brand settings and brand categories. Each user can have DIFFERENT roles in different brands within the same company.
                                                </p>
                                            </div>
                                            <div className="mt-3 p-3 bg-blue-100 rounded border border-blue-200">
                                                <p className="font-semibold mb-1">Example:</p>
                                                <p>
                                                    A user might be <strong>"admin"</strong> at the tenant level (full company access) but <strong>"member"</strong> in a specific brand (limited brand access). 
                                                    Conversely, a user might be <strong>"member"</strong> at the tenant level but <strong>"brand_manager"</strong> in a specific brand they manage.
                                                </p>
                                            </div>
                                            <div className="mt-3 p-3 bg-yellow-50 rounded border border-yellow-200">
                                                <p className="font-semibold mb-1 text-yellow-900">Important:</p>
                                                <p className="text-yellow-800">
                                                    Tenant-level <strong>admins</strong> and <strong>owners</strong> automatically have full access to ALL brands in that company, regardless of their brand-specific role assignments. This prevents locking out company administrators.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}
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
                                                <h2 className="text-lg font-semibold text-gray-900">Company Roles & Permissions (Tenant-Level)</h2>
                                                <p className="mt-1 text-sm text-gray-500">Manage which permissions each tenant-level role has. These permissions apply to company-wide features.</p>
                                                <p className="mt-1 text-xs text-gray-400">Note: The same role names are used for brand-level roles, but brand roles are assigned per-brand when adding users. ● indicates default permissions. Use the Company Settings group to control which roles can access each section on the Company Settings page (e.g. Tag Quality, AI Settings). If permissions are missing, run: <code className="rounded bg-gray-100 px-1">php artisan db:seed --class=PermissionSeeder</code></p>
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
                                <div className="px-6 py-4">
                                    {renderCompanyPermissionsTable()}
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
                        </>
                    )}

                    {/* Permission Debug Tab */}
                    {activeTab === 'debug' && (
                        <div className="mb-8 rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="border-b border-gray-200 px-6 py-4">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100">
                                        <span className="text-amber-600">🔍</span>
                                    </div>
                                    <div>
                                        <h2 className="text-lg font-semibold text-gray-900">Permission Debug (Admin Only)</h2>
                                        <p className="mt-1 text-sm text-gray-500">Check if a user has a permission for a tenant/brand. Result and source (PermissionMap vs Spatie) for troubleshooting.</p>
                                    </div>
                                </div>
                            </div>
                            <div className="px-6 py-4 space-y-4">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">User ID</label>
                                        <input
                                            type="text"
                                            value={debugUserId}
                                            onChange={(e) => setDebugUserId(e.target.value)}
                                            placeholder="e.g. 1"
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">Tenant ID (optional)</label>
                                        <input
                                            type="text"
                                            value={debugTenantId}
                                            onChange={(e) => setDebugTenantId(e.target.value)}
                                            placeholder="e.g. 1"
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">Brand ID (optional)</label>
                                        <input
                                            type="text"
                                            value={debugBrandId}
                                            onChange={(e) => setDebugBrandId(e.target.value)}
                                            placeholder="e.g. 1"
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">Permission</label>
                                        <input
                                            type="text"
                                            value={debugPermission}
                                            onChange={(e) => setDebugPermission(e.target.value)}
                                            placeholder="e.g. assets.delete"
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        />
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={async () => {
                                        if (!debugUserId || !debugPermission) return
                                        setDebugLoading(true)
                                        setDebugResult(null)
                                        try {
                                            const res = await fetch('/app/admin/permissions/debug', {
                                                method: 'POST',
                                                headers: {
                                                    'Content-Type': 'application/json',
                                                    'Accept': 'application/json',
                                                    'X-Requested-With': 'XMLHttpRequest',
                                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                                },
                                                body: JSON.stringify({
                                                    user_id: parseInt(debugUserId, 10),
                                                    tenant_id: debugTenantId ? parseInt(debugTenantId, 10) : null,
                                                    brand_id: debugBrandId ? parseInt(debugBrandId, 10) : null,
                                                    permission: debugPermission.trim(),
                                                }),
                                            })
                                            const data = await res.json()
                                            if (!res.ok) setDebugResult({ error: data.message || data.errors?.user_id?.[0] || 'Request failed' })
                                            else setDebugResult(data)
                                        } catch (e) {
                                            setDebugResult({ error: e.message })
                                        } finally {
                                            setDebugLoading(false)
                                        }
                                    }}
                                    disabled={debugLoading || !debugUserId || !debugPermission}
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                >
                                    {debugLoading ? 'Checking...' : 'Check Permission'}
                                </button>
                                {debugResult && !debugResult.error && (
                                    <div className="mt-4 p-4 rounded-lg border-2 border-gray-200 bg-gray-50">
                                        <div className="text-sm font-semibold text-gray-900 mb-2">Result</div>
                                        <div className="grid grid-cols-2 gap-2 text-sm">
                                            <span className="text-gray-600">User:</span>
                                            <span>{debugResult.user?.name} ({debugResult.user?.email})</span>
                                            <span className="text-gray-600">Tenant:</span>
                                            <span>{debugResult.tenant?.name ?? '—'}</span>
                                            <span className="text-gray-600">Brand:</span>
                                            <span>{debugResult.brand?.name ?? '—'}</span>
                                            <span className="text-gray-600">Permission:</span>
                                            <span>{debugResult.permission}</span>
                                            <span className="text-gray-600">Result:</span>
                                            <span className={debugResult.result ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'}>
                                                {debugResult.result ? 'true' : 'false'}
                                            </span>
                                            <span className="text-gray-600">Source:</span>
                                            <span className="font-mono">{debugResult.source}</span>
                                        </div>
                                    </div>
                                )}
                                {debugResult?.error && (
                                    <div className="mt-4 p-4 rounded-lg border-2 border-red-200 bg-red-50 text-red-700 text-sm">
                                        {debugResult.error}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

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
