/**
 * Dashboard Widget Settings Component
 * 
 * Allows admins to configure which dashboard widgets are visible for each role.
 */

import { useState } from 'react'
import { router } from '@inertiajs/react'
import { CheckIcon, XMarkIcon } from '@heroicons/react/24/outline'

const WIDGETS = [
    { key: 'total_assets', label: 'Total Assets', description: 'Shows total number of assets' },
    { key: 'storage', label: 'Storage', description: 'Shows storage usage and limits' },
    { key: 'download_links', label: 'Download Links', description: 'Shows download link usage' },
    { key: 'most_viewed', label: 'Most Viewed Assets', description: 'Shows most viewed assets' },
    { key: 'most_downloaded', label: 'Most Downloaded Assets', description: 'Shows most downloaded assets' },
]

const ROLES = [
    { key: 'owner', label: 'Owner', description: 'Company owner' },
    { key: 'admin', label: 'Admin', description: 'Company administrator' },
    { key: 'brand_manager', label: 'Brand Manager', description: 'Brand manager' },
    { key: 'contributor', label: 'Contributor', description: 'Contributor' },
    { key: 'viewer', label: 'Viewer', description: 'Viewer' },
]

export default function DashboardWidgetSettings({ tenant, canEdit }) {
    // Get current widget configuration from tenant settings
    const currentConfig = tenant?.settings?.dashboard_widgets || {}
    
    // Initialize state with current config or defaults
    const [widgetConfig, setWidgetConfig] = useState(() => {
        const config = {}
        ROLES.forEach(role => {
            config[role.key] = {}
            WIDGETS.forEach(widget => {
                // Use saved config if exists, otherwise use sensible defaults
                if (currentConfig[role.key]?.[widget.key] !== undefined) {
                    config[role.key][widget.key] = currentConfig[role.key][widget.key]
                } else {
                    // Defaults: company widgets hidden for contributor/viewer, visible for others
                    const isCompanyWidget = ['total_assets', 'storage', 'download_links'].includes(widget.key)
                    if (isCompanyWidget) {
                        config[role.key][widget.key] = !['contributor', 'viewer'].includes(role.key)
                    } else {
                        config[role.key][widget.key] = true // Most viewed/downloaded visible to all
                    }
                }
            })
        })
        return config
    })

    const [saving, setSaving] = useState(false)
    const [saveError, setSaveError] = useState(null)
    const [saveSuccess, setSaveSuccess] = useState(false)

    const toggleWidget = (roleKey, widgetKey) => {
        if (!canEdit) return
        
        setWidgetConfig(prev => ({
            ...prev,
            [roleKey]: {
                ...prev[roleKey],
                [widgetKey]: !prev[roleKey][widgetKey],
            },
        }))
        setSaveError(null)
        setSaveSuccess(false)
    }

    const handleSave = async () => {
        if (!canEdit) return
        
        setSaving(true)
        setSaveError(null)
        setSaveSuccess(false)

        try {
            await router.put('/app/companies/settings/widgets', {
                dashboard_widgets: widgetConfig,
            }, {
                preserveScroll: true,
                onSuccess: () => {
                    setSaveSuccess(true)
                    setTimeout(() => setSaveSuccess(false), 3000)
                },
                onError: (errors) => {
                    setSaveError(errors.message || 'Failed to save widget settings')
                },
                onFinish: () => {
                    setSaving(false)
                },
            })
        } catch (error) {
            setSaveError(error.message || 'Failed to save widget settings')
            setSaving(false)
        }
    }

    const handleReset = () => {
        if (!canEdit) return
        
        // Reset to defaults
        const defaultConfig = {}
        ROLES.forEach(role => {
            defaultConfig[role.key] = {}
            WIDGETS.forEach(widget => {
                const isCompanyWidget = ['total_assets', 'storage', 'download_links'].includes(widget.key)
                if (isCompanyWidget) {
                    defaultConfig[role.key][widget.key] = !['contributor', 'viewer'].includes(role.key)
                } else {
                    defaultConfig[role.key][widget.key] = true
                }
            })
        })
        setWidgetConfig(defaultConfig)
        setSaveError(null)
        setSaveSuccess(false)
    }

    return (
        <div className="bg-white shadow rounded-lg">
            <div className="px-4 py-5 sm:p-6">
                <div className="mb-6">
                    <h3 className="text-lg font-medium leading-6 text-gray-900">
                        Dashboard Widget Visibility
                    </h3>
                    <p className="mt-1 text-sm text-gray-500">
                        Configure which dashboard widgets are visible for each role. Changes take effect immediately.
                    </p>
                </div>

                {saveError && (
                    <div className="mb-4 rounded-md bg-red-50 p-4">
                        <div className="flex">
                            <div className="flex-shrink-0">
                                <XMarkIcon className="h-5 w-5 text-red-400" aria-hidden="true" />
                            </div>
                            <div className="ml-3">
                                <h3 className="text-sm font-medium text-red-800">{saveError}</h3>
                            </div>
                        </div>
                    </div>
                )}

                {saveSuccess && (
                    <div className="mb-4 rounded-md bg-green-50 p-4">
                        <div className="flex">
                            <div className="flex-shrink-0">
                                <CheckIcon className="h-5 w-5 text-green-400" aria-hidden="true" />
                            </div>
                            <div className="ml-3">
                                <h3 className="text-sm font-medium text-green-800">
                                    Widget settings saved successfully
                                </h3>
                            </div>
                        </div>
                    </div>
                )}

                {/* Widget Configuration Table */}
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                            <tr>
                                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Role
                                </th>
                                {WIDGETS.map(widget => (
                                    <th key={widget.key} scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <div className="flex flex-col">
                                            <span>{widget.label}</span>
                                            <span className="text-xs font-normal text-gray-400 mt-0.5">{widget.description}</span>
                                        </div>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="bg-white divide-y divide-gray-200">
                            {ROLES.map((role, roleIndex) => (
                                <tr key={role.key} className={roleIndex % 2 === 0 ? 'bg-white' : 'bg-gray-50'}>
                                    <td className="px-4 py-4 whitespace-nowrap">
                                        <div className="flex flex-col">
                                            <span className="text-sm font-medium text-gray-900">{role.label}</span>
                                            <span className="text-xs text-gray-500">{role.description}</span>
                                        </div>
                                    </td>
                                    {WIDGETS.map(widget => (
                                        <td key={widget.key} className="px-4 py-4 whitespace-nowrap text-center">
                                            <button
                                                type="button"
                                                onClick={() => toggleWidget(role.key, widget.key)}
                                                disabled={!canEdit || saving}
                                                className={`
                                                    inline-flex items-center justify-center w-10 h-10 rounded-md
                                                    ${widgetConfig[role.key]?.[widget.key]
                                                        ? 'bg-green-100 text-green-800 hover:bg-green-200'
                                                        : 'bg-gray-100 text-gray-400 hover:bg-gray-200'
                                                    }
                                                    ${!canEdit || saving ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}
                                                    focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2
                                                    transition-colors
                                                `}
                                                title={widgetConfig[role.key]?.[widget.key] ? 'Visible' : 'Hidden'}
                                            >
                                                {widgetConfig[role.key]?.[widget.key] ? (
                                                    <CheckIcon className="h-5 w-5" />
                                                ) : (
                                                    <XMarkIcon className="h-5 w-5" />
                                                )}
                                            </button>
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Action Buttons */}
                {canEdit && (
                    <div className="mt-6 flex items-center justify-end gap-3">
                        <button
                            type="button"
                            onClick={handleReset}
                            disabled={saving}
                            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Reset to Defaults
                        </button>
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={saving}
                            className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {saving ? 'Saving...' : 'Save Changes'}
                        </button>
                    </div>
                )}

                {!canEdit && (
                    <div className="mt-4 rounded-md bg-yellow-50 p-4">
                        <p className="text-sm text-yellow-800">
                            You don't have permission to edit widget settings. Contact an administrator.
                        </p>
                    </div>
                )}
            </div>
        </div>
    )
}
