import { useForm, Link, router, usePage } from '@inertiajs/react'
import { useState, useEffect, useRef } from 'react'
import axios from 'axios'
import AppNav from '../../Components/AppNav'
import { ARCHETYPES } from '../../constants/brandOptions'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import PlanLimitCallout from '../../Components/PlanLimitCallout'
import CategoryUpgradeModal from '../../Components/CategoryUpgradeModal'
import CategoryIconSelector from '../../Components/CategoryIconSelector'
import { CategoryIcon } from '../../Helpers/categoryIcons'
import { getImageBackgroundStyle } from '../../utils/imageUtils'
import { getContrastTextColor } from '../../utils/colorUtils'
import { DELIVERABLES_PAGE_LABEL_SINGULAR } from '../../utils/uiLabels'
import BrandAvatar from '../../Components/BrandAvatar'
import DownloadBrandingSelector from '../../Components/branding/DownloadBrandingSelector'
import AssetImagePickerField from '../../Components/media/AssetImagePickerField'
import AssetImagePickerFieldMulti from '../../Components/media/AssetImagePickerFieldMulti'
import BrandMembersSection from '../../Components/brand/BrandMembersSection'
import PublicPageTheme from '../../Components/branding/PublicPageTheme'
import EntryExperience from '../../Components/portal/EntryExperience'
import PublicAccess from '../../Components/portal/PublicAccess'
import SharingLinks from '../../Components/portal/SharingLinks'
import InviteExperience from '../../Components/portal/InviteExperience'
import AgencyTemplates from '../../Components/portal/AgencyTemplates'

// Phase 1: Categories and Metadata sections hidden from Brand Identity page (will be re-homed later)
const SHOW_CATEGORIES_AND_METADATA = false

// CategoryCard component matching Categories/Index clean design
function CategoryCard({ category, brandId, brand_users, brand_roles, private_category_limits, can_edit_system_categories, onUpgradeClick, editingId, setEditingId, onEditStart, onEditSave, onEditCancel }) {
    const [deleteProcessing, setDeleteProcessing] = useState(false)
    const [editName, setEditName] = useState(category.name)
    const [editIcon, setEditIcon] = useState(category.icon || 'folder')
    const [editIsPrivate, setEditIsPrivate] = useState(category.is_private || false)
    const [editIsHidden, setEditIsHidden] = useState(category.is_hidden || false)
    // is_locked is site admin only - not editable by tenants, so we don't need state for it
    const [editAccessRules, setEditAccessRules] = useState(category.access_rules || [])
    const editInputRef = useRef(null)
    
    const isEditing = editingId === category.id
    const isCustomCategory = !category.is_system
    
    useEffect(() => {
        if (isEditing && editInputRef.current) {
            editInputRef.current.focus()
            editInputRef.current.select()
        }
    }, [isEditing])
    
    useEffect(() => {
        if (isEditing) {
            // Only set editable fields based on category type
            if (category.is_system && can_edit_system_categories) {
                // System categories: only hide (is_locked is site admin only)
                setEditIsHidden(category.is_hidden || false)
            } else {
                // Custom categories: full editing
                setEditName(category.name)
                setEditIcon(category.icon || 'folder')
                setEditIsPrivate(category.is_private || false)
                setEditAccessRules(category.access_rules || [])
            }
        }
    }, [isEditing, category.name, category.icon, category.is_private, category.is_hidden, category.access_rules, category.is_system, can_edit_system_categories])
    
    const handleEditStart = () => {
        if (setEditingId) {
            setEditingId(category.id)
        }
        // Only set editable fields based on category type
        if (category.is_system && can_edit_system_categories) {
            // System categories: only hide (is_locked is site admin only)
            setEditIsHidden(category.is_hidden || false)
        } else {
            // Custom categories: full editing
            setEditName(category.name)
            setEditIcon(category.icon || 'folder')
            setEditIsPrivate(category.is_private || false)
            setEditAccessRules(category.access_rules || [])
        }
    }
    
    const handleEditSave = () => {
        // For system categories, skip name validation (name is immutable)
        if (!category.is_system && !editName.trim()) {
            handleEditCancel()
            return
        }
        
        // Validate private category has access rules (only for custom categories)
        if (!category.is_system && editIsPrivate && (!editAccessRules || editAccessRules.length === 0)) {
            alert('Private categories must have at least one access rule (role or user).')
            return
        }
        
        // For system categories, only send hide field (name/icon are immutable, is_locked is site admin only)
        if (category.is_system && can_edit_system_categories) {
            const updateData = {
                is_hidden: editIsHidden,
                // is_locked is site admin only - not editable by tenants
            }
            
            if (onEditSave) {
                onEditSave(category.id, updateData)
            } else {
                router.put(`/app/brands/${brandId}/categories/${category.id}`, updateData, {
                    preserveScroll: true,
                    onSuccess: () => {
                        setEditingId(null)
                        router.reload({ preserveScroll: true })
                    },
                })
            }
            return
        }
        
        // For custom categories, allow full editing
        const updateData = {
            name: editName.trim(),
            icon: editIcon,
            is_private: editIsPrivate,
            access_rules: editIsPrivate ? editAccessRules : [],
        }
        
        if (onEditSave) {
            onEditSave(category.id, updateData)
        } else {
            router.put(`/app/brands/${brandId}/categories/${category.id}`, updateData, {
                preserveScroll: true,
                onSuccess: () => {
                    setEditingId(null)
                    router.reload({ preserveScroll: true })
                },
            })
        }
    }
    
    const handleEditCancel = () => {
        if (onEditCancel) {
            onEditCancel()
        } else {
            setEditingId(null)
        }
        // Reset fields based on category type
        if (category.is_system && can_edit_system_categories) {
            setEditIsHidden(category.is_hidden || false)
        } else {
            setEditName(category.name)
            setEditIcon(category.icon || 'folder')
            setEditIsPrivate(category.is_private || false)
            setEditAccessRules(category.access_rules || [])
        }
    }
    
    const handleEditKeyDown = (e) => {
        if (e.key === 'Enter') {
            handleEditSave()
        } else if (e.key === 'Escape') {
            handleEditCancel()
        }
    }

    const handleDelete = () => {
        if (confirm(`Are you sure you want to delete "${category.name}"? This action cannot be undone.`)) {
            setDeleteProcessing(true)
            router.delete(`/app/brands/${brandId}/categories/${category.id}`, {
                preserveScroll: true,
                onSuccess: () => {
                    router.reload({ preserveScroll: true })
                },
                onFinish: () => {
                    setDeleteProcessing(false)
                },
            })
        }
    }

    // Use backend-provided flag to determine if category can be edited/deleted
    // Backend checks: template exists, is_locked, etc.
    // For system categories that can be deleted (template deleted), don't allow editing
    // But allow editing system categories if plan allows (for hide/lock functionality)
    // System categories can be edited even if locked, as long as plan allows and template exists
    const canEditSystem = category.is_system && can_edit_system_categories && category.id && category.template_exists
    const canEditCustom = category.id && category.can_be_deleted !== false && 
                    !(category.is_system && !category.template_exists) &&
                    !category.is_locked
    const canEdit = canEditSystem || canEditCustom
    const processing = deleteProcessing

    return (
        <>
            <div className="px-6 py-4 hover:bg-gray-50 relative">
                <div className="flex items-center justify-between">
                    <div className="flex items-center flex-1 min-w-0">
                        {/* Category Icon */}
                        <div className="mr-3 flex-shrink-0">
                            {category.is_system || category.is_locked ? (
                                <CategoryIcon 
                                    iconId={category.icon || 'folder'} 
                                    className="h-5 w-5" 
                                    color="text-gray-400"
                                />
                            ) : (
                                <CategoryIcon 
                                    iconId={category.icon || 'plus-circle'} 
                                    className="h-5 w-5" 
                                    color="text-indigo-500"
                                />
                            )}
                        </div>

                        <div className="flex-1 min-w-0">
                            {isEditing ? (
                                <div className="space-y-4 relative z-10">
                                    {/* System categories: Only show hide/lock controls, no name/icon editing */}
                                    {category.is_system && can_edit_system_categories ? (
                                        <div className="space-y-4">
                                            <div className="rounded-md bg-blue-50 p-3 border border-blue-200">
                                                <p className="text-sm text-blue-800">
                                                    <strong>System categories are immutable.</strong> You can hide this category, but cannot rename it or change its icon. Lock status is managed by site administrators only.
                                                </p>
                                            </div>
                                            <div className="space-y-3">
                                                <div className="flex items-center">
                                                    <input
                                                        id={`edit_category_is_hidden_${category.id}`}
                                                        type="checkbox"
                                                        checked={editIsHidden}
                                                        onChange={(e) => setEditIsHidden(e.target.checked)}
                                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                    <label htmlFor={`edit_category_is_hidden_${category.id}`} className="ml-2 block text-sm text-gray-900">
                                                        Hide this category
                                                    </label>
                                                </div>
                                                {/* is_locked is site admin only - not shown to tenants */}
                                            </div>
                                        </div>
                                    ) : (
                                        <>
                                            {/* Custom categories: Full editing (name, icon, private settings) */}
                                            <div>
                                                <input
                                                    ref={editInputRef}
                                                    type="text"
                                                    value={editName}
                                                    onChange={(e) => setEditName(e.target.value)}
                                                    onKeyDown={handleEditKeyDown}
                                                    className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                />
                                            </div>
                                            <div className="relative">
                                                <CategoryIconSelector
                                                    value={editIcon}
                                                    onChange={setEditIcon}
                                                />
                                            </div>
                                        </>
                                    )}
                                    
                                    {/* Private Category Access Controls - Only for custom categories */}
                                    {isCustomCategory && (
                                        <div className="space-y-4 border-t border-gray-200 pt-4">
                                            <div className="flex items-center">
                                                <input
                                                    id={`edit_category_is_private_${category.id}`}
                                                    type="checkbox"
                                                    checked={editIsPrivate}
                                                    onChange={(e) => {
                                                        setEditIsPrivate(e.target.checked)
                                                        if (!e.target.checked) {
                                                            setEditAccessRules([])
                                                        }
                                                    }}
                                                    disabled={!private_category_limits?.plan_allows}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                                                />
                                                <label htmlFor={`edit_category_is_private_${category.id}`} className="ml-2 block text-sm text-gray-900">
                                                    Restrict access to this category
                                                </label>
                                            </div>
                                            {!private_category_limits?.plan_allows && (
                                                <p className="text-xs text-gray-500 ml-6">
                                                    Private categories require Pro or Enterprise plan.
                                                </p>
                                            )}
                                            {editIsPrivate && private_category_limits?.plan_allows && (
                                                <div className="ml-6 space-y-4 border-l-2 border-indigo-200 pl-4">
                                                    {(() => {
                                                        const hasRoleRules = editAccessRules?.some(r => r.type === 'role')
                                                        const hasUserRules = editAccessRules?.some(r => r.type === 'user')
                                                        return (
                                                            <>
                                                                <div>
                                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                                        Brand Roles
                                                                    </label>
                                                                    <div className="space-y-2">
                                                                        {(brand_roles || []).map((role) => {
                                                                            const isSelected = editAccessRules?.some(r => r.type === 'role' && r.role === role)
                                                                            return (
                                                                                <label key={role} className="flex items-center">
                                                                                    <input
                                                                                        type="checkbox"
                                                                                        checked={isSelected}
                                                                                        disabled={hasUserRules}
                                                                                        onChange={(e) => {
                                                                                            const currentRules = editAccessRules || []
                                                                                            if (e.target.checked) {
                                                                                                // Clear user rules if selecting a role
                                                                                                const roleRules = currentRules.filter(r => r.type === 'role')
                                                                                                setEditAccessRules([...roleRules, { type: 'role', role }])
                                                                                            } else {
                                                                                                setEditAccessRules(currentRules.filter(r => !(r.type === 'role' && r.role === role)))
                                                                                            }
                                                                                        }}
                                                                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                                    />
                                                                                    <span className="ml-2 text-sm text-gray-700 capitalize">{role}</span>
                                                                                </label>
                                                                            )
                                                                        })}
                                                                        {(!brand_roles || brand_roles.length === 0) && (
                                                                            <p className="text-xs text-gray-500">No brand roles available</p>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                                <div>
                                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                                        Individual Users
                                                                    </label>
                                                                    <div className="space-y-2 max-h-48 overflow-y-auto">
                                                                        {(brand_users || []).map((user) => {
                                                                            const isSelected = editAccessRules?.some(r => r.type === 'user' && r.user_id === user.id)
                                                                            return (
                                                                                <label key={user.id} className="flex items-center">
                                                                                    <input
                                                                                        type="checkbox"
                                                                                        checked={isSelected}
                                                                                        disabled={hasRoleRules}
                                                                                        onChange={(e) => {
                                                                                            const currentRules = editAccessRules || []
                                                                                            if (e.target.checked) {
                                                                                                // Clear role rules if selecting a user
                                                                                                const userRules = currentRules.filter(r => r.type === 'user')
                                                                                                setEditAccessRules([...userRules, { type: 'user', user_id: user.id }])
                                                                                            } else {
                                                                                                setEditAccessRules(currentRules.filter(r => !(r.type === 'user' && r.user_id === user.id)))
                                                                                            }
                                                                                        }}
                                                                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                                    />
                                                                                    <span className="ml-2 text-sm text-gray-700">{user.name} ({user.email})</span>
                                                                                </label>
                                                                            )
                                                                        })}
                                                                        {(!brand_users || brand_users.length === 0) && (
                                                                            <p className="text-xs text-gray-500">No users available</p>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                                {(hasRoleRules && hasUserRules) && (
                                                                    <p className="text-xs text-amber-600">You can select either roles or users, but not both.</p>
                                                                )}
                                                                {editIsPrivate && (!editAccessRules || editAccessRules.length === 0) && (
                                                                    <p className="text-xs text-amber-600">At least one role or user must be selected for private categories.</p>
                                                                )}
                                                            </>
                                                        )
                                                    })()}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                    
                                    <div className="flex items-center gap-2 pt-1">
                                        <button
                                            type="button"
                                            onClick={handleEditSave}
                                            disabled={
                                                // For system categories, no validation needed (just hide/lock)
                                                // For custom categories, validate name and private access rules
                                                (!category.is_system && !editName.trim()) || 
                                                (!category.is_system && editIsPrivate && (!editAccessRules || editAccessRules.length === 0))
                                            }
                                            className="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            <svg className="h-3.5 w-3.5 mr-1" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                            Save
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handleEditCancel}
                                            className="inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                        >
                                            <svg className="h-3.5 w-3.5 mr-1" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <p className="text-sm font-medium text-gray-900 truncate">
                                            {category.name}
                                        </p>
                                        {category.is_hidden && (
                                            <span className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-600 ring-gray-600/20" title="This category is hidden and will not appear in the sidebar">
                                                <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 01-4.243-4.243m4.242 4.242L9.88 9.88" />
                                                </svg>
                                                Hidden
                                            </span>
                                        )}
                                        {category.is_system && (
                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-blue-50 text-blue-700 ring-blue-600/20">
                                                System
                                            </span>
                                        )}
                                        {category.upgrade_available && category.is_system && (
                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-600/20">
                                                Update available
                                            </span>
                                        )}
                                        {category.deletion_available && category.is_system && (
                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-red-50 text-red-700 ring-red-600/20">
                                                Deletion required
                                            </span>
                                        )}
                                        {category.is_private && (
                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-indigo-100 text-indigo-800 ring-indigo-600/20">
                                                Private
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-sm text-gray-500 truncate">
                                        {category.slug} {category.system_version && `(v${category.system_version})`}
                                    </p>
                                </>
                            )}
                        </div>
                    </div>
                    <div className="flex items-center gap-2 ml-4">
                        {category.upgrade_available && category.is_system && category.id && (
                            <button
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    if (onUpgradeClick) onUpgradeClick(category)
                                }}
                                className="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600"
                                title="Review update"
                            >
                                Review update
                            </button>
                        )}
                        {category.deletion_available && category.is_system && category.id && (
                            <button
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    if (confirm(`The system category template for "${category.name}" has been deleted. Do you want to delete this category? This action cannot be undone.`)) {
                                        router.post(`/app/brands/${brandId}/categories/${category.id}/accept-deletion`, {}, {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                router.reload({ preserveScroll: true })
                                            },
                                        })
                                    }
                                }}
                                className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                title="Accept deletion"
                            >
                                Accept deletion
                            </button>
                        )}
                        {canEdit && !isEditing && (
                            <button
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    handleEditStart()
                                }}
                                className="rounded-md bg-white px-2 py-1.5 text-sm font-semibold text-gray-600 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                title={category.is_system && can_edit_system_categories ? "Configure hide/lock settings" : "Edit category"}
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                </svg>
                            </button>
                        )}
                        {canEdit && !isEditing && (
                            <button
                                type="button"
                                onClick={handleDelete}
                                className="rounded-md bg-white px-2 py-1.5 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-red-300 hover:bg-red-50"
                                title="Delete category"
                                disabled={processing}
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                </svg>
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </>
    )
}

function unwrapAi(val) {
    if (val && typeof val === 'object' && !Array.isArray(val) && 'value' in val && 'source' in val) return val.value
    return val
}

function deepUnwrap(obj) {
    if (!obj || typeof obj !== 'object') return obj
    if (Array.isArray(obj)) return obj.map(deepUnwrap)
    if ('value' in obj && 'source' in obj) {
        const inner = obj.value
        return Array.isArray(inner) ? inner.map(deepUnwrap) : inner
    }
    const result = {}
    for (const [k, v] of Object.entries(obj)) {
        result[k] = deepUnwrap(v)
    }
    return result
}

function modelPayloadToForm(payload) {
    if (!payload || typeof payload !== 'object') payload = {}
    payload = deepUnwrap(payload)
    const identity = payload.identity || {}
    const personality = payload.personality || {}
    const typography = payload.typography || {}
    const scoringRules = payload.scoring_rules || {}
    const visual = payload.visual || {}
    const palette = scoringRules.allowed_color_palette || []
    const allowedColors = Array.isArray(palette)
        ? palette.map((c) => (typeof c === 'string' ? c : c?.hex ?? ''))
        : []
    return {
        strategy: {
            archetype: personality.archetype || personality.primary_archetype || null,
            tone: personality.tone || null,
            traits: Array.isArray(personality.traits) ? personality.traits : [],
            voice_description: personality.voice_description || null,
        },
        purpose: {
            why: identity.mission || null,
            what: identity.positioning || null,
        },
        positioning: {
            industry: identity.industry || null,
            target_audience: identity.target_audience || null,
            market_category: identity.market_category || null,
            competitive_position: identity.competitive_position || null,
            tagline: identity.tagline || null,
        },
        expression: {
            brand_look: personality.brand_look || visual.brand_look || visual.photography_style || null,
            brand_voice: personality.brand_voice || visual.brand_voice || null,
            tone_keywords: Array.isArray(scoringRules.tone_keywords) ? scoringRules.tone_keywords : (Array.isArray(personality.tone_keywords) ? personality.tone_keywords : []),
            photography_attributes: Array.isArray(scoringRules.photography_attributes) ? scoringRules.photography_attributes : [],
        },
        standards: {
            primary_font: typography.primary_font || null,
            secondary_font: typography.secondary_font || null,
            heading_style: typography.heading_style || null,
            body_style: typography.body_style || null,
            allowed_colors: allowedColors,
            banned_colors: Array.isArray(scoringRules.banned_colors) ? scoringRules.banned_colors : [],
            allowed_fonts: Array.isArray(scoringRules.allowed_fonts) ? scoringRules.allowed_fonts : [],
            visual_references: Array.isArray(payload.visual_references) ? payload.visual_references : [],
            reference_categories: (visual.reference_categories && typeof visual.reference_categories === 'object') ? visual.reference_categories : {
                photography: { asset_ids: [], use_for_scoring: false },
                graphics: { asset_ids: [], use_for_scoring: false },
            },
            show_logo_visual_treatment: visual.show_logo_visual_treatment !== false,
            logo_usage_guidelines: (visual.logo_usage_guidelines && typeof visual.logo_usage_guidelines === 'object') ? visual.logo_usage_guidelines : {},
        },
        beliefs: Array.isArray(identity.beliefs) ? identity.beliefs : [],
        values: Array.isArray(identity.values) ? identity.values : [],
        scoring_config: {
            color_weight: payload.scoring_config?.color_weight ?? 0.1,
            typography_weight: payload.scoring_config?.typography_weight ?? 0.2,
            tone_weight: payload.scoring_config?.tone_weight ?? 0.2,
            imagery_weight: payload.scoring_config?.imagery_weight ?? 0.5,
        },
        scoring_rules_extra: {
            banned_keywords: Array.isArray(scoringRules.banned_keywords) ? scoringRules.banned_keywords : [],
        },
    }
}

// Map form structure back to backend model_payload (merge with existing)
function formToModelPayload(form, existingPayload) {
    const existing = existingPayload && typeof existingPayload === 'object' ? deepUnwrap({ ...existingPayload }) : {}
    const identity = { ...(existing.identity || {}) }
    const personality = { ...(existing.personality || {}) }
    const typography = { ...(existing.typography || {}) }
    const scoringRules = { ...(existing.scoring_rules || {}) }
    const visual = { ...(existing.visual || {}) }

    identity.mission = form.purpose?.why ?? identity.mission
    identity.positioning = form.purpose?.what ?? identity.positioning
    identity.industry = form.positioning?.industry ?? identity.industry
    identity.target_audience = form.positioning?.target_audience ?? identity.target_audience
    identity.market_category = form.positioning?.market_category ?? identity.market_category
    identity.competitive_position = form.positioning?.competitive_position ?? identity.competitive_position
    identity.tagline = form.positioning?.tagline ?? identity.tagline
    identity.beliefs = form.beliefs ?? identity.beliefs
    identity.values = form.values ?? identity.values

    personality.archetype = form.strategy?.archetype ?? personality.archetype
    personality.primary_archetype = form.strategy?.archetype ?? personality.primary_archetype
    personality.tone = form.strategy?.tone ?? personality.tone
    personality.traits = form.strategy?.traits ?? personality.traits
    personality.voice_description = form.strategy?.voice_description ?? personality.voice_description
    personality.brand_look = form.expression?.brand_look ?? personality.brand_look
    personality.brand_voice = form.expression?.brand_voice ?? personality.brand_voice

    typography.primary_font = form.standards?.primary_font ?? typography.primary_font
    typography.secondary_font = form.standards?.secondary_font ?? typography.secondary_font
    typography.heading_style = form.standards?.heading_style ?? typography.heading_style
    typography.body_style = form.standards?.body_style ?? typography.body_style

    const palette = (form.standards?.allowed_colors || []).map((hex) =>
        typeof hex === 'string' && hex ? { hex, role: null } : null
    ).filter(Boolean)
    scoringRules.allowed_color_palette = palette.length ? palette : (scoringRules.allowed_color_palette || [])
    scoringRules.banned_colors = form.standards?.banned_colors ?? scoringRules.banned_colors
    scoringRules.allowed_fonts = form.standards?.allowed_fonts ?? scoringRules.allowed_fonts
    scoringRules.tone_keywords = form.expression?.tone_keywords ?? scoringRules.tone_keywords
    scoringRules.photography_attributes = form.expression?.photography_attributes ?? scoringRules.photography_attributes

    visual.brand_look = form.expression?.brand_look ?? visual.brand_look
    visual.brand_voice = form.expression?.brand_voice ?? visual.brand_voice
    visual.photography_style = form.expression?.brand_look ?? visual.photography_style
    if (form.standards?.show_logo_visual_treatment !== undefined) {
        visual.show_logo_visual_treatment = form.standards.show_logo_visual_treatment
    }
    if (form.standards?.logo_usage_guidelines !== undefined) {
        visual.logo_usage_guidelines = form.standards.logo_usage_guidelines
    }
    if (form.standards?.reference_categories) {
        visual.reference_categories = form.standards.reference_categories
        const scoringIds = []
        Object.values(form.standards.reference_categories).forEach((cat) => {
            if (cat?.use_for_scoring && Array.isArray(cat.asset_ids)) {
                scoringIds.push(...cat.asset_ids)
            }
        })
        visual.approved_references = scoringIds
    }

    const scoringConfig = { ...(existing.scoring_config || {}) }
    if (form.scoring_config) {
        scoringConfig.color_weight = form.scoring_config.color_weight ?? scoringConfig.color_weight
        scoringConfig.typography_weight = form.scoring_config.typography_weight ?? scoringConfig.typography_weight
        scoringConfig.tone_weight = form.scoring_config.tone_weight ?? scoringConfig.tone_weight
        scoringConfig.imagery_weight = form.scoring_config.imagery_weight ?? scoringConfig.imagery_weight
    }
    scoringRules.banned_keywords = form.scoring_rules_extra?.banned_keywords ?? scoringRules.banned_keywords

    return {
        ...existing,
        identity,
        personality,
        typography,
        scoring_config: scoringConfig,
        scoring_rules: scoringRules,
        visual,
        visual_references: form.standards?.visual_references ?? existing.visual_references,
    }
}

const REFERENCE_CATEGORIES = [
    { key: 'photography', label: 'Photography', contextCategory: 'photography' },
    { key: 'graphics', label: 'Graphics', contextCategory: 'graphics' },
]

function VisualReferenceCategoryPicker({ brandId, referenceCategories, onChange }) {
    const [activeCategory, setActiveCategory] = useState('photography')
    const [categoryAssets, setCategoryAssets] = useState({})

    const cats = referenceCategories && typeof referenceCategories === 'object' ? referenceCategories : {}

    const fetchAssetsForRefs = (opts) => {
        const params = new URLSearchParams({ format: 'json' })
        if (opts?.category) params.set('category', opts.category)
        return fetch(`/app/assets?${params}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then((r) => r.json())
    }

    useEffect(() => {
        const allIds = []
        REFERENCE_CATEGORIES.forEach((cat) => {
            const catData = cats[cat.key]
            if (catData?.asset_ids?.length) allIds.push(...catData.asset_ids)
        })
        if (allIds.length === 0) return

        // Fetch all assets (no category filter) so we can match any asset regardless of category/state
        fetchAssetsForRefs({}).then((data) => {
            const allAssets = data?.assets ?? data?.data ?? (Array.isArray(data) ? data : [])
            // Also try reference materials source
            return fetch(`/app/assets?format=json&source=research`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            }).then((r) => r.json()).then((refData) => {
                const refAssets = refData?.assets ?? refData?.data ?? (Array.isArray(refData) ? refData : [])
                return [...allAssets, ...refAssets]
            }).catch(() => allAssets)
        }).then((combined) => {
            const updates = {}
            REFERENCE_CATEGORIES.forEach((cat) => {
                const catData = cats[cat.key]
                if (catData?.asset_ids?.length && !categoryAssets[cat.key]?.length) {
                    updates[cat.key] = catData.asset_ids.map((id) => {
                        const found = combined.find((a) => String(a.id) === String(id))
                        return found
                            ? { asset_id: found.id, preview_url: found.thumbnail_url ?? found.final_thumbnail_url ?? null, title: found.title }
                            : { asset_id: id, preview_url: null, title: null }
                    })
                }
            })
            if (Object.keys(updates).length > 0) {
                setCategoryAssets((prev) => ({ ...prev, ...updates }))
            }
        })
    }, [])

    const handleCategoryAssetsChange = (catKey, assets) => {
        setCategoryAssets((prev) => ({ ...prev, [catKey]: assets }))
        const assetIds = assets.filter((a) => a?.asset_id).map((a) => a.asset_id)
        const updated = { ...cats }
        updated[catKey] = { ...(updated[catKey] || {}), asset_ids: assetIds }
        if (updated[catKey].use_for_scoring === undefined) updated[catKey].use_for_scoring = false
        onChange(updated)
    }

    const handleScoringToggle = (catKey, checked) => {
        const updated = { ...cats }
        updated[catKey] = { ...(updated[catKey] || { asset_ids: [] }), use_for_scoring: checked }
        onChange(updated)
    }

    const activeCatDef = REFERENCE_CATEGORIES.find((c) => c.key === activeCategory) || REFERENCE_CATEGORIES[0]

    return (
        <div className="pt-6 border-t border-gray-200">
            <div className="mb-4">
                <h4 className="text-sm font-semibold text-gray-900">Visual References</h4>
                <p className="text-xs text-gray-500 mt-0.5">Select reference images by category for brand guidelines and scoring.</p>
            </div>
            <div className="flex gap-1 mb-4 bg-gray-100 rounded-lg p-1">
                {REFERENCE_CATEGORIES.map((cat) => {
                    const count = cats[cat.key]?.asset_ids?.length || 0
                    return (
                        <button
                            key={cat.key}
                            type="button"
                            onClick={() => setActiveCategory(cat.key)}
                            className={`flex-1 text-xs font-medium px-3 py-2 rounded-md transition-colors ${activeCategory === cat.key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}
                        >
                                {cat.label}
                            {count > 0 && <span className="ml-1 text-[10px] bg-indigo-100 text-indigo-700 rounded-full px-1.5">{count}</span>}
                        </button>
                    )
                })}
            </div>
            <AssetImagePickerFieldMulti
                key={activeCatDef.key}
                value={categoryAssets[activeCatDef.key] || []}
                onChange={(assets) => handleCategoryAssetsChange(activeCatDef.key, assets)}
                fetchAssets={(opts) => fetchAssetsForRefs(opts)}
                title={`Select ${activeCatDef.label}`}
                defaultCategoryLabel={activeCatDef.label}
                contextCategory={activeCatDef.contextCategory}
                maxSelection={12}
                label={activeCatDef.label}
                brandId={brandId}
            />
            <label className="flex items-center gap-2 mt-3 cursor-pointer">
                <input
                    type="checkbox"
                    checked={!!cats[activeCatDef.key]?.use_for_scoring}
                    onChange={(e) => handleScoringToggle(activeCatDef.key, e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                />
                <span className="text-xs text-gray-600">Use <strong>{activeCatDef.label}</strong> for brand scoring</span>
            </label>
        </div>
    )
}

export default function BrandsEdit({ brand, categories, available_system_templates, category_limits, brand_users, brand_roles, available_users, pending_invitations, private_category_limits, can_edit_system_categories, tenant_settings, current_plan, model_payload, brand_model, active_version, all_versions = [], compliance_aggregate, top_executions, bottom_executions, portal_settings, portal_features, portal_url }) {
    const { auth } = usePage().props
    const effectivePermissions = Array.isArray(auth?.effective_permissions) ? auth.effective_permissions : []
    const can = (p) => effectivePermissions.includes(p)
    const canAccessCategoriesAndFields = can('metadata.registry.view') || can('metadata.tenant.visibility.manage')
    const [iconBackgroundStyle, setIconBackgroundStyle] = useState({ background: 'transparent', isWhite: false })
    const [activeCategoryTab, setActiveCategoryTab] = useState('asset')
    // Parse ?tab= from URL to restore state after redirects (e.g. auto-save)
    const getInitialTabState = () => {
        if (typeof window === 'undefined') return { topLevelNav: 'brand_settings', activeTab: 'identity' }
        const params = new URLSearchParams(window.location.search)
        const tabParam = params.get('tab')
        const brandSettingsSections = ['identity', 'workspace', 'brand-portal', 'members']
        const brandModelSections = ['strategy', 'positioning', 'expression', 'standards', 'scoring']
        if (brandSettingsSections.includes(tabParam)) {
            return { topLevelNav: 'brand_settings', activeTab: tabParam }
        }
        if (tabParam === 'brand_model' || brandModelSections.includes(tabParam)) {
            return { topLevelNav: 'brand_model', activeTab: brandModelSections.includes(tabParam) ? tabParam : 'strategy' }
        }
        return { topLevelNav: 'brand_settings', activeTab: 'identity' }
    }
    const initialTab = getInitialTabState()
    const [topLevelNav, setTopLevelNav] = useState(initialTab.topLevelNav) // brand_model | brand_settings
    const [activeTab, setActiveTab] = useState(initialTab.activeTab) // brand_model: strategy|positioning|expression|standards; brand_settings: identity|workspace|brand-portal|members

    const updateTabInUrl = (tab) => {
        const url = new URL(window.location.href)
        url.searchParams.set('tab', tab)
        window.history.replaceState({}, '', url.toString())
    }
    const [upgradeModalOpen, setUpgradeModalOpen] = useState(false)
    const [selectedCategoryForUpgrade, setSelectedCategoryForUpgrade] = useState(null)
    const [editingCategoryId, setEditingCategoryId] = useState(null)
    const [showCreateCategoryForm, setShowCreateCategoryForm] = useState(false)
    const [scoringRuleInputs, setScoringRuleInputs] = useState({})
    const [newColorInput, setNewColorInput] = useState('')
    const [selectedVersionId, setSelectedVersionId] = useState(null)
    const [executionAlignmentOpen, setExecutionAlignmentOpen] = useState(false)
    
    // Category creation form
    const { data: categoryFormData, setData: setCategoryFormData, post: postCategory, processing: creatingCategory, reset: resetCategoryForm } = useForm({
        name: '',
        icon: 'folder',
        asset_type: 'asset',
        is_private: false,
        access_rules: [],
    })
    
    // Update form asset_type when tab changes
    useEffect(() => {
        if (activeCategoryTab === 'asset') {
            setCategoryFormData('asset_type', 'asset')
        } else if (activeCategoryTab === 'deliverable') {
            setCategoryFormData('asset_type', 'deliverable')
        }
    }, [activeCategoryTab, setCategoryFormData])
    
    const handleCreateCategory = (e) => {
        e.preventDefault()
        postCategory(`/app/brands/${brand.id}/categories`, {
            onSuccess: () => {
                setShowCreateCategoryForm(false)
                resetCategoryForm()
                router.reload({ preserveScroll: true })
            },
        })
    }
    
    // Derive icon_bg_style from stored icon_bg_color (preserve saved values, no schema change)
    const normalizeHex = (h) => (h || '').replace(/^#/, '').toLowerCase()
    const deriveIconBgStyle = (stored, primary, secondary, accent) => {
        const s = normalizeHex(stored)
        if (!s) return 'primary'
        if (s === normalizeHex(primary)) return 'primary'
        if (s === normalizeHex(secondary)) return 'secondary'
        if (s === normalizeHex(accent)) return 'accent'
        return 'custom'
    }
    const [iconBgStyle, setIconBgStyle] = useState(() =>
        deriveIconBgStyle(brand.icon_bg_color, brand.primary_color, brand.secondary_color, brand.accent_color)
    )

    const { data, setData, put, processing, errors } = useForm({
        name: brand.name,
        slug: brand.slug,
        logo_id: brand.logo_id ?? null,
        logo_preview: brand.logo_thumbnail_url || brand.logo_path || '',
        clear_logo: false,
        clear_icon: false,
        icon_id: brand.icon_id ?? null,
        icon_preview: brand.icon_thumbnail_url || brand.icon_path || '',
        icon_bg_color: brand.icon_bg_color || brand.primary_color || '#6366f1',
        show_in_selector: brand.show_in_selector !== undefined ? brand.show_in_selector : true,
        primary_color: brand.primary_color || '',
        secondary_color: brand.secondary_color || '',
        accent_color: brand.accent_color || '',
        nav_color: brand.nav_color || brand.primary_color || '',
        workspace_button_style: brand.workspace_button_style ?? brand.settings?.button_style ?? 'primary',
        logo_filter: brand.logo_filter || 'none',
        settings: {
            // Preserve any other settings that might exist first
            ...(brand.settings || {}),
            // Then explicitly set boolean values (convert string '0'/'1' to boolean)
            metadata_approval_enabled: brand.settings?.metadata_approval_enabled === true || brand.settings?.metadata_approval_enabled === '1' || brand.settings?.metadata_approval_enabled === 1, // Phase M-2
            contributor_upload_requires_approval: brand.settings?.contributor_upload_requires_approval === true || brand.settings?.contributor_upload_requires_approval === '1' || brand.settings?.contributor_upload_requires_approval === 1, // Phase J.3.1
            asset_grid_style: brand.settings?.asset_grid_style || 'clean', // clean | impact
            nav_display_mode: brand.settings?.nav_display_mode || 'logo', // logo | text
        },
        // D10: Brand-level download landing branding (logo from assets, color from palette, no raw URL/hex)
        download_landing_settings: {
            enabled: brand.download_landing_settings?.enabled !== false,
            logo_mode: brand.download_landing_settings?.logo_mode ?? (brand.download_landing_settings?.logo_asset_id ? 'custom' : 'brand'),
            logo_asset_id: brand.download_landing_settings?.logo_asset_id ?? null,
            color_role: brand.download_landing_settings?.color_role || 'primary',
            custom_color: brand.download_landing_settings?.custom_color || '',
            default_headline: brand.download_landing_settings?.default_headline || '',
            default_subtext: brand.download_landing_settings?.default_subtext || '',
            background_asset_ids: Array.isArray(brand.download_landing_settings?.background_asset_ids) ? brand.download_landing_settings.background_asset_ids : [],
        },
        portal_settings: portal_settings || {},
    })

    // Brand DNA: model_payload from active version (Strategy, Positioning, Expression, Standards tabs)
    const [modelPayload, setModelPayload] = useState(() => modelPayloadToForm(model_payload))
    const [dnaSaving, setDnaSaving] = useState(false)
    useEffect(() => {
        setModelPayload(modelPayloadToForm(model_payload))
    }, [model_payload])

    const setModelPayloadField = (path, value) => {
        setModelPayload((prev) => {
            const next = JSON.parse(JSON.stringify(prev))
            const parts = path.split('.')
            let cur = next
            for (let i = 0; i < parts.length - 1; i++) {
                const p = parts[i]
                if (!cur[p]) cur[p] = {}
                cur = cur[p]
            }
            cur[parts[parts.length - 1]] = value
            return next
        })
    }

    const handleSaveDna = (e) => {
        e.preventDefault()
        setDnaSaving(true)
        const payload = formToModelPayload(modelPayload, model_payload)
        const url = typeof route === 'function' ? route('brands.dna.store', { brand: brand.id }) : `/app/brands/${brand.id}/dna`
        router.post(url, { model_payload: payload, return_to: 'edit' }, {
            preserveScroll: true,
            onFinish: () => setDnaSaving(false),
        })
    }

    const handleToggleEnabled = () => {
        const url = typeof route === 'function'
            ? route('brands.dna.store', { brand: brand.id })
            : `/app/brands/${brand.id}/dna`
        router.post(url, { is_enabled: !brand_model?.is_enabled, return_to: 'edit' }, { preserveScroll: true })
    }

    const handleVersionSelect = async (versionId) => {
        setSelectedVersionId(versionId)
        if (!versionId) {
            setModelPayload(modelPayloadToForm(active_version ? model_payload : {}))
            return
        }
        const activeId = active_version?.id
        if (versionId == activeId) {
            setModelPayload(modelPayloadToForm(model_payload))
            return
        }
        try {
            const url = typeof route === 'function'
                ? route('brands.dna.versions.show', { brand: brand.id, version: versionId })
                : `/app/brands/${brand.id}/dna/versions/${versionId}`
            const { data: respData } = await axios.get(url)
            setModelPayload(modelPayloadToForm(respData.version?.model_payload || {}))
        } catch {
            setModelPayload(modelPayloadToForm({}))
        }
    }

    const COLOR_ROLES = [
        { value: null, label: '—' },
        { value: 'primary', label: 'Primary' },
        { value: 'secondary', label: 'Secondary' },
        { value: 'accent', label: 'Accent' },
        { value: 'neutral', label: 'Neutral' },
    ]

    const addScoringRuleItem = (ruleKey, value) => {
        const v = (typeof value === 'string' ? value : '').trim()
        if (!v) return
        const getItems = (key) => {
            if (key === 'banned_keywords') return modelPayload.scoring_rules_extra?.banned_keywords ?? []
            if (key === 'tone_keywords' || key === 'photography_attributes') return modelPayload.expression?.[key] ?? []
            return modelPayload.standards?.[key] ?? []
        }
        const arr = getItems(ruleKey)
        if (arr.includes(v)) return
        if (ruleKey === 'banned_keywords') {
            setModelPayloadField('scoring_rules_extra.banned_keywords', [...arr, v])
        } else if (ruleKey === 'tone_keywords' || ruleKey === 'photography_attributes') {
            setModelPayloadField(`expression.${ruleKey}`, [...arr, v])
        } else {
            setModelPayloadField(`standards.${ruleKey}`, [...arr, v])
        }
        setScoringRuleInputs((prev) => ({ ...prev, [ruleKey]: '' }))
    }

    const removeScoringRuleItem = (ruleKey, idx) => {
        if (ruleKey === 'banned_keywords') {
            const arr = (modelPayload.scoring_rules_extra?.banned_keywords ?? []).filter((_, i) => i !== idx)
            setModelPayloadField('scoring_rules_extra.banned_keywords', arr)
        } else if (ruleKey === 'tone_keywords' || ruleKey === 'photography_attributes') {
            const arr = (modelPayload.expression?.[ruleKey] ?? []).filter((_, i) => i !== idx)
            setModelPayloadField(`expression.${ruleKey}`, [...arr])
        } else {
            const arr = (modelPayload.standards?.[ruleKey] ?? []).filter((_, i) => i !== idx)
            setModelPayloadField(`standards.${ruleKey}`, [...arr])
        }
    }

    const addColorToPalette = (hex, role = null) => {
        let h = (hex || '').trim()
        if (!h) return
        if (!h.startsWith('#')) h = '#' + h
        const arr = modelPayload.standards?.allowed_colors ?? []
        if (arr.some((c) => c === h)) return
        setModelPayloadField('standards.allowed_colors', [...arr, h])
        setNewColorInput('')
    }

    const renderTagArrayField = (ruleKey, label, placeholder) => {
        const getItems = (key) => {
            if (key === 'banned_keywords') return modelPayload.scoring_rules_extra?.banned_keywords ?? []
            if (key === 'tone_keywords' || key === 'photography_attributes') return modelPayload.expression?.[key] ?? []
            return modelPayload.standards?.[key] ?? []
        }
        const items = getItems(ruleKey)
        const inputVal = scoringRuleInputs[ruleKey] ?? ''
        return (
            <div key={ruleKey}>
                <label className="block text-sm font-medium text-gray-700">{label}</label>
                <div className="mt-1 flex flex-wrap gap-2">
                    {items.map((t, i) => (
                        <span key={i} className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-sm text-gray-800">
                            {t}
                            <button type="button" onClick={() => removeScoringRuleItem(ruleKey, i)} className="text-gray-500 hover:text-gray-700">&times;</button>
                        </span>
                    ))}
                </div>
                <div className="mt-2 flex gap-2">
                    <input
                        type="text"
                        value={inputVal}
                        onChange={(e) => setScoringRuleInputs((prev) => ({ ...prev, [ruleKey]: e.target.value }))}
                        onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addScoringRuleItem(ruleKey, inputVal))}
                        placeholder={placeholder}
                        className="block flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    />
                    <button type="button" onClick={() => addScoringRuleItem(ruleKey, inputVal)} className="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200">Add</button>
                </div>
            </div>
        )
    }

    const weightTotal = Math.round(
        ((modelPayload.scoring_config?.color_weight ?? 0.1) +
        (modelPayload.scoring_config?.typography_weight ?? 0.2) +
        (modelPayload.scoring_config?.tone_weight ?? 0.2) +
        (modelPayload.scoring_config?.imagery_weight ?? 0.5)) * 100
    )

    const autoSaveBrandField = (overrides) => {
        const payload = { ...data, ...overrides }
        router.put(`/app/brands/${brand.id}`, payload, {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
        })
    }

    const getFilterStyleForColor = (hex) => {
        if (!hex) return { filter: 'brightness(0)' }
        const c = hex.replace('#', '')
        const r = parseInt(c.substr(0, 2), 16) / 255
        const g = parseInt(c.substr(2, 2), 16) / 255
        const b = parseInt(c.substr(4, 2), 16) / 255
        const max = Math.max(r, g, b), min = Math.min(r, g, b)
        let h = 0
        if (max !== min) {
            const d = max - min
            if (max === r) h = (g - b) / d + (g < b ? 6 : 0)
            else if (max === g) h = (b - r) / d + 2
            else h = (r - g) / d + 4
            h *= 60
        }
        return { filter: `brightness(0) sepia(1) saturate(5) hue-rotate(${h - 30}deg)` }
    }

    const submit = (e) => {
        e.preventDefault()
        
        put(`/app/brands/${brand.id}`, {
            forceFormData: true,
            onSuccess: () => {
                if (data.logo_preview && data.logo_preview.startsWith('blob:')) {
                    URL.revokeObjectURL(data.logo_preview)
                }
                if (data.icon_preview && data.icon_preview.startsWith('blob:')) {
                    URL.revokeObjectURL(data.icon_preview)
                }
            },
            onError: (errors) => {
                console.error('[Brands/Edit] Form submission errors:', errors)
            },
        })
    }

    // Cleanup preview URLs on unmount
    useEffect(() => {
        return () => {
            if (data.logo_preview && data.logo_preview.startsWith('blob:')) {
                URL.revokeObjectURL(data.logo_preview)
            }
            if (data.icon_preview && data.icon_preview.startsWith('blob:')) {
                URL.revokeObjectURL(data.icon_preview)
            }
        }
    }, [data.logo_preview, data.icon_preview])

    // Sync icon_bg_color when palette changes and style is not custom
    useEffect(() => {
        if (iconBgStyle === 'primary') setData('icon_bg_color', data.primary_color || brand.primary_color || '')
        else if (iconBgStyle === 'secondary') setData('icon_bg_color', data.secondary_color || brand.secondary_color || '')
        else if (iconBgStyle === 'accent') setData('icon_bg_color', data.accent_color || brand.accent_color || '')
    }, [iconBgStyle, data.primary_color, data.secondary_color, data.accent_color])

    // Detect if icon is white and set background style
    useEffect(() => {
        if (data.icon_preview && !data.icon_preview.includes('svg')) {
            getImageBackgroundStyle(data.icon_preview)
                .then(style => {
                    setIconBackgroundStyle(style)
                })
                .catch(error => {
                    console.error('Error detecting icon color:', error)
                    setIconBackgroundStyle({ background: 'transparent', isWhite: false })
                })
        } else {
            setIconBackgroundStyle({ background: 'transparent', isWhite: false })
        }
    }, [data.icon_preview])


    return (
        <div className="min-h-full">
            <AppHead title="Brand Settings" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <Link
                        href="/app"
                        className="text-sm font-medium text-gray-500 hover:text-gray-700"
                    >
                        ← Back to Company
                    </Link>
                    <h1 className="mt-4 text-2xl font-bold tracking-tight text-gray-900">Brand Settings</h1>
                    <p className="mt-1 text-sm text-gray-600">
                        Manage identity, workspace appearance, and team access.
                    </p>

                    {/* Top-level navigation: Brand Model vs Brand Settings */}
                    <div className="mt-6 flex flex-wrap items-center gap-4">
                        <div className="inline-flex p-1 rounded-xl bg-gray-100 shadow-sm" role="tablist" aria-label="Configuration type">
                            <button
                                type="button"
                                role="tab"
                                aria-selected={topLevelNav === 'brand_settings'}
                                onClick={() => { setTopLevelNav('brand_settings'); setActiveTab('identity'); updateTabInUrl('identity') }}
                                className={`px-4 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 ease-out ${
                                    topLevelNav === 'brand_settings' ? 'bg-white text-gray-900 shadow-md ring-1 ring-gray-200/60' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50/80'
                                }`}
                            >
                                Brand Settings
                            </button>
                            <button
                                type="button"
                                role="tab"
                                aria-selected={topLevelNav === 'brand_model'}
                                onClick={() => { setTopLevelNav('brand_model'); setActiveTab('strategy'); updateTabInUrl('strategy') }}
                                className={`px-4 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 ease-out ${
                                    topLevelNav === 'brand_model' ? 'bg-white text-gray-900 shadow-md ring-1 ring-gray-200/60' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50/80'
                                }`}
                            >
                                Brand Model
                            </button>
                        </div>
                        {canAccessCategoriesAndFields && (
                            <Link
                                href={typeof route === 'function' ? route('tenant.metadata.registry.index', { brand: brand.id }) : `/app/tenant/metadata/registry?brand=${brand.id}`}
                                className="text-sm font-medium text-gray-600 hover:text-gray-900"
                            >
                                Categories & Fields →
                            </Link>
                        )}
                        {can('brand_settings.manage') && (
                            <Link
                                href={typeof route === 'function' ? route('analytics.metadata') : '/app/analytics/metadata'}
                                className="text-sm font-medium text-gray-600 hover:text-gray-900"
                            >
                                Analytics →
                            </Link>
                        )}
                    </div>
                </div>

                {/* Two-column layout: left sidebar nav + main content */}
                <div className="flex flex-col lg:flex-row gap-8">
                    {/* Left sidebar nav */}
                    <aside className="lg:w-56 flex-shrink-0">
                        <nav className="sticky top-8 space-y-1" aria-label={topLevelNav === 'brand_model' ? 'Brand Model sections' : 'Brand Settings sections'}>
                            {(topLevelNav === 'brand_model'
                                ? [
                                    { id: 'strategy', label: 'Strategy' },
                                    { id: 'positioning', label: 'Positioning' },
                                    { id: 'expression', label: 'Expression' },
                                    { id: 'standards', label: 'Standards' },
                                    { id: 'scoring', label: 'Scoring' },
                                ]
                                : [
                                    { id: 'identity', label: 'Identity' },
                                    { id: 'workspace', label: 'Workspace' },
                                    { id: 'brand-portal', label: 'Brand Portal' },
                                    { id: 'members', label: 'Members' },
                                ]
                            ).map((item) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => { setActiveTab(item.id); updateTabInUrl(item.id) }}
                                    className={`w-full text-left rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                                        activeTab === item.id
                                            ? 'bg-indigo-50 text-indigo-700'
                                            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                                    }`}
                                >
                                    {item.label}
                                </button>
                            ))}
                        </nav>
                    </aside>

                    {/* Main content */}
                    <div className="flex-1 min-w-0">

                    {/* Brand Builder entry panel — with enabled toggle, version selector, AI research */}
                    {topLevelNav === 'brand_model' && (() => {
                        const draftVersion = (all_versions || []).find((v) => v.status === 'draft')
                        const hasActiveVersion = !!active_version && active_version.status === 'active'
                        const formatDate = (iso) => {
                            if (!iso) return '—'
                            try {
                                const d = new Date(iso)
                                return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
                            } catch { return '—' }
                        }
                        return (
                            <div className="mt-4 mb-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/20">
                                {/* Top row: enabled toggle + version selector */}
                                <div className="flex flex-wrap items-center justify-between gap-4 mb-4">
                                    <div className="flex items-center gap-4">
                                        <span className="text-sm text-gray-600">Enabled</span>
                                        <button
                                            type="button"
                                            onClick={handleToggleEnabled}
                                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                                brand_model?.is_enabled ? 'bg-indigo-600' : 'bg-gray-200'
                                            }`}
                                            role="switch"
                                            aria-checked={brand_model?.is_enabled}
                                        >
                                            <span
                                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                                    brand_model?.is_enabled ? 'translate-x-5' : 'translate-x-0'
                                                }`}
                                            />
                                        </button>
                                    </div>
                                    {all_versions.length > 0 && (
                                        <div className="flex items-center gap-3">
                                            {hasActiveVersion && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                                    <svg className="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                    Active: v{active_version.version_number}
                                                </span>
                                            )}
                                            <select
                                                value={selectedVersionId ?? ''}
                                                onChange={(e) => handleVersionSelect(e.target.value ? Number(e.target.value) : null)}
                                                className="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                                <option value="">Select version</option>
                                                {all_versions.map((v) => (
                                                    <option key={v.id} value={v.id}>
                                                        v{v.version_number} ({v.status}) {v.created_at ? new Date(v.created_at).toLocaleDateString() : ''}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    )}
                                </div>

                                {draftVersion ? (
                                    <>
                                        {hasActiveVersion && (
                                            <div className="mb-3 flex items-center gap-2 text-sm">
                                                <span className="text-gray-500">Last updated {formatDate(active_version.updated_at)}</span>
                                            </div>
                                        )}
                                        <h3 className="text-sm font-semibold text-amber-800">Draft Version Available</h3>
                                        <p className="mt-1 text-sm text-gray-600">You have unpublished changes.</p>
                                        <div className="mt-4 flex flex-wrap gap-3">
                                            <Link
                                                href={typeof route === 'function' ? route('brands.brand-guidelines.builder', { brand: brand.id }) : `/app/brands/${brand.id}/brand-guidelines/builder`}
                                                className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                                            >
                                                Resume Draft Builder
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={() => router.post(typeof route === 'function' ? route('brands.dna.versions.activate', { brand: brand.id, version: draftVersion.id }) : `/app/brands/${brand.id}/dna/versions/${draftVersion.id}/activate`, {}, { preserveScroll: true })}
                                                className="inline-flex items-center rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                                            >
                                                Publish Draft
                                            </button>
                                            <Link
                                                href={typeof route === 'function' ? route('brands.dna.bootstrap.index', { brand: brand.id }) : `/app/brands/${brand.id}/dna/bootstrap`}
                                                className="inline-flex items-center rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                                            >
                                                Run AI Brand Research
                                            </Link>
                                        </div>
                                    </>
                                ) : hasActiveVersion ? (
                                    <>
                                        <p className="text-sm text-gray-600">
                                            Your brand model is live. Run the Brand Builder again to create a new draft version.
                                        </p>
                                        <div className="mt-4 flex flex-wrap gap-3">
                                            <Link
                                                href={typeof route === 'function' ? route('brands.brand-guidelines.builder', { brand: brand.id }) : `/app/brands/${brand.id}/brand-guidelines/builder`}
                                                className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                                            >
                                                Run Brand Builder
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={() => router.post(typeof route === 'function' ? route('brands.dna.versions.store', { brand: brand.id }) : `/app/brands/${brand.id}/dna/versions`, {}, { preserveScroll: true })}
                                                className="inline-flex items-center rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                                            >
                                                Create Draft Version
                                            </button>
                                            <Link
                                                href={typeof route === 'function' ? route('brands.dna.bootstrap.index', { brand: brand.id }) : `/app/brands/${brand.id}/dna/bootstrap`}
                                                className="inline-flex items-center rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                                            >
                                                Run AI Brand Research
                                            </Link>
                                        </div>
                                    </>
                                ) : (
                                    <>
                                        <div className="flex flex-wrap items-center gap-4 text-sm">
                                            <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
                                                Not Published
                                            </span>
                                        </div>
                                        <p className="mt-3 text-sm text-gray-600">
                                            Define your brand DNA using the guided Brand Builder or edit fields manually below.
                                        </p>
                                        <div className="mt-4 flex flex-wrap gap-3">
                                            <Link
                                                href={typeof route === 'function' ? route('brands.brand-guidelines.builder', { brand: brand.id }) : `/app/brands/${brand.id}/brand-guidelines/builder`}
                                                className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                                            >
                                                Run Brand Builder
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={() => router.post(typeof route === 'function' ? route('brands.dna.versions.store', { brand: brand.id }) : `/app/brands/${brand.id}/dna/versions`, {}, { preserveScroll: true })}
                                                className="inline-flex items-center rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                                            >
                                                Create Draft Version
                                            </button>
                                            <Link
                                                href={typeof route === 'function' ? route('brands.dna.bootstrap.index', { brand: brand.id }) : `/app/brands/${brand.id}/dna/bootstrap`}
                                                className="inline-flex items-center rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                                            >
                                                Run AI Brand Research
                                            </Link>
                                        </div>
                                    </>
                                )}
                            </div>
                        )
                    })()}

                    {/* Execution Alignment Overview — collapsible */}
                    {topLevelNav === 'brand_model' && compliance_aggregate && (
                        <div className="mt-4">
                            <button
                                type="button"
                                onClick={() => setExecutionAlignmentOpen(!executionAlignmentOpen)}
                                className="w-full flex items-center justify-between rounded-xl bg-gradient-to-br from-indigo-50/80 to-slate-50/80 px-6 py-4 ring-1 ring-indigo-100/50 text-left hover:from-indigo-50 hover:to-slate-50 transition-colors"
                            >
                                <h2 className="text-sm font-semibold uppercase tracking-wide text-indigo-800/90">Execution Alignment Overview</h2>
                                <svg className={`h-5 w-5 text-indigo-400 transition-transform ${executionAlignmentOpen ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                            {executionAlignmentOpen && (
                                <div className="rounded-b-xl bg-gradient-to-br from-indigo-50/80 to-slate-50/80 px-6 pb-6 ring-1 ring-indigo-100/50 -mt-1">
                                    <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                                        <div className="rounded-lg bg-white/70 px-4 py-3 backdrop-blur-sm">
                                            <p className="text-xs font-medium text-slate-500">Average On-Brand Score</p>
                                            <p className="mt-1 text-2xl font-bold text-indigo-700">
                                                {compliance_aggregate.avg_score != null ? `${compliance_aggregate.avg_score.toFixed(1)}%` : 'No data yet.'}
                                            </p>
                                        </div>
                                        <div className="rounded-lg bg-white/70 px-4 py-3 backdrop-blur-sm">
                                            <p className="text-xs font-medium text-slate-500">Total Executions</p>
                                            <p className="mt-1 text-2xl font-bold text-slate-800">{compliance_aggregate.execution_count ?? 0}</p>
                                        </div>
                                        <div className="rounded-lg bg-white/70 px-4 py-3 backdrop-blur-sm">
                                            <p className="text-xs font-medium text-slate-500">% High Alignment (&ge;85)</p>
                                            <p className="mt-1 text-2xl font-bold text-emerald-600">
                                                {compliance_aggregate.execution_count > 0 && compliance_aggregate.avg_score != null
                                                    ? ((compliance_aggregate.high_score_count / compliance_aggregate.execution_count) * 100).toFixed(0) + '%'
                                                    : '—'}
                                            </p>
                                        </div>
                                        <div className="rounded-lg bg-white/70 px-4 py-3 backdrop-blur-sm">
                                            <p className="text-xs font-medium text-slate-500">% Low Alignment (&lt;60)</p>
                                            <p className="mt-1 text-2xl font-bold text-amber-600">
                                                {compliance_aggregate.execution_count > 0 && compliance_aggregate.avg_score != null
                                                    ? ((compliance_aggregate.low_score_count / compliance_aggregate.execution_count) * 100).toFixed(0) + '%'
                                                    : '—'}
                                            </p>
                                        </div>
                                    </div>
                                    {(top_executions?.length > 0 || bottom_executions?.length > 0) && (
                                        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div className="rounded-lg bg-white/60 p-4 backdrop-blur-sm">
                                                <p className="text-xs font-semibold text-emerald-700">Top 3 Aligned</p>
                                                <ul className="mt-2 space-y-1.5">
                                                    {top_executions?.map((e, i) => (
                                                        <li key={i} className="flex items-center justify-between gap-2 text-sm">
                                                            <span className="truncate text-slate-700">{e.title || 'Untitled'}</span>
                                                            <span className="flex-shrink-0 rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">{e.score != null ? `${e.score}%` : '—'}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                            <div className="rounded-lg bg-white/60 p-4 backdrop-blur-sm">
                                                <p className="text-xs font-semibold text-amber-700">Bottom 3 — Review</p>
                                                <ul className="mt-2 space-y-1.5">
                                                    {bottom_executions?.map((e, i) => (
                                                        <li key={i} className="flex items-center justify-between gap-2 text-sm">
                                                            <span className="truncate text-slate-700">{e.title || 'Untitled'}</span>
                                                            <span className="flex-shrink-0 rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{e.score != null ? `${e.score}%` : '—'}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        </div>
                                    )}
                                    {compliance_aggregate.last_scored_at && (
                                        <p className="mt-3 text-xs text-slate-500">Last scored: {new Date(compliance_aggregate.last_scored_at).toLocaleString()}</p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}


                {activeTab === 'members' ? (
                    /* Members tab: outside form to avoid nested <form> (UserInviteForm has its own form) */
                    <div id="members" className="scroll-mt-8 space-y-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-1">
                                    <h2 className="text-xl font-semibold text-gray-900">Members</h2>
                                    <p className="mt-3 text-sm text-gray-600 leading-relaxed max-w-xl">
                                        Invite team members and manage their access to this brand.
                                    </p>
                                </div>
                                <div className="mt-8">
                                    <BrandMembersSection
                                        brandId={brand.id}
                                        users={brand_users || []}
                                        availableUsers={available_users || []}
                                        pendingInvitations={pending_invitations || []}
                                        brandRoles={brand_roles || []}
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                ) : (activeTab === 'strategy' || activeTab === 'positioning' || activeTab === 'expression' || activeTab === 'standards' || activeTab === 'scoring') ? (
                /* DNA tabs: separate form, saves to model_payload */
                <form onSubmit={handleSaveDna} className="mt-8 space-y-8">
                    {activeTab === 'strategy' && (
                    <div id="strategy" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Strategy</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Define your brand archetype, tone, traits, and voice. These inform creative alignment and scoring.
                                    </p>
                                </div>
                                <div className="mt-6 space-y-6">
                                    <div>
                                        <label htmlFor="archetype" className="block text-sm font-medium text-gray-900">Archetype</label>
                                        <select
                                            id="archetype"
                                            value={modelPayload.strategy?.archetype ?? ''}
                                            onChange={(e) => setModelPayloadField('strategy.archetype', e.target.value || null)}
                                            className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm"
                                        >
                                            <option value="">Select archetype</option>
                                            {ARCHETYPES.map((a) => (
                                                <option key={a.id} value={a.id}>{a.id} — {a.desc}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label htmlFor="tone" className="block text-sm font-medium text-gray-900">Tone</label>
                                        <input type="text" id="tone" value={modelPayload.strategy?.tone ?? ''} onChange={(e) => setModelPayloadField('strategy.tone', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm" placeholder="e.g. Professional, Playful" />
                                    </div>
                                    <div>
                                        <label htmlFor="voice_description" className="block text-sm font-medium text-gray-900">Voice description</label>
                                        <textarea id="voice_description" rows={5} value={modelPayload.strategy?.voice_description ?? ''} onChange={(e) => setModelPayloadField('strategy.voice_description', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="How your brand sounds in communication" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-900">Traits</label>
                                        <p className="text-xs text-gray-500 mt-1 mb-2">Comma-separated or add one per line</p>
                                        <textarea rows={3} value={(modelPayload.strategy?.traits || []).join(', ')} onChange={(e) => setModelPayloadField('strategy.traits', e.target.value.split(/[,\n]/).map((s) => s.trim()).filter(Boolean))} className="block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="e.g. Bold, Innovative, Trustworthy" />
                                    </div>
                                    <div>
                                        <label htmlFor="purpose_why" className="block text-sm font-medium text-gray-900">Purpose — Why</label>
                                        <textarea id="purpose_why" rows={3} value={modelPayload.purpose?.why ?? ''} onChange={(e) => setModelPayloadField('purpose.why', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="Why does your brand exist?" />
                                    </div>
                                    <div>
                                        <label htmlFor="purpose_what" className="block text-sm font-medium text-gray-900">Purpose — What</label>
                                        <textarea id="purpose_what" rows={3} value={modelPayload.purpose?.what ?? ''} onChange={(e) => setModelPayloadField('purpose.what', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="What does your brand do?" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-900">Beliefs</label>
                                        <textarea rows={4} value={(modelPayload.beliefs || []).join('\n')} onChange={(e) => setModelPayloadField('beliefs', e.target.value.split('\n').map((s) => s.trim()).filter(Boolean))} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="One belief per line" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-900">Values</label>
                                        <textarea rows={4} value={(modelPayload.values || []).join('\n')} onChange={(e) => setModelPayloadField('values', e.target.value.split('\n').map((s) => s.trim()).filter(Boolean))} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="One value per line" />
                                    </div>
                                    <button type="submit" disabled={dnaSaving} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                        {dnaSaving ? 'Saving…' : 'Save Brand DNA'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    )}
                    {activeTab === 'positioning' && (
                    <div id="positioning" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Positioning</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Industry, target audience, market category, and competitive position.
                                    </p>
                                </div>
                                <div className="mt-6 space-y-6">
                                    <div>
                                        <label htmlFor="industry" className="block text-sm font-medium text-gray-900">Industry</label>
                                        <input type="text" id="industry" value={modelPayload.positioning?.industry ?? ''} onChange={(e) => setModelPayloadField('positioning.industry', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm" />
                                    </div>
                                    <div>
                                        <label htmlFor="target_audience" className="block text-sm font-medium text-gray-900">Target audience</label>
                                        <textarea id="target_audience" rows={3} value={modelPayload.positioning?.target_audience ?? ''} onChange={(e) => setModelPayloadField('positioning.target_audience', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" />
                                    </div>
                                    <div>
                                        <label htmlFor="market_category" className="block text-sm font-medium text-gray-900">Market category</label>
                                        <input type="text" id="market_category" value={modelPayload.positioning?.market_category ?? ''} onChange={(e) => setModelPayloadField('positioning.market_category', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm" />
                                    </div>
                                    <div>
                                        <label htmlFor="competitive_position" className="block text-sm font-medium text-gray-900">Competitive position</label>
                                        <input type="text" id="competitive_position" value={modelPayload.positioning?.competitive_position ?? ''} onChange={(e) => setModelPayloadField('positioning.competitive_position', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm" />
                                    </div>
                                    <div>
                                        <label htmlFor="tagline" className="block text-sm font-medium text-gray-900">Tagline</label>
                                        <input type="text" id="tagline" value={modelPayload.positioning?.tagline ?? ''} onChange={(e) => setModelPayloadField('positioning.tagline', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm" />
                                    </div>
                                    <button type="submit" disabled={dnaSaving} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                        {dnaSaving ? 'Saving…' : 'Save Brand DNA'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    )}
                    {activeTab === 'expression' && (
                    <div id="expression" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Expression</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Brand look, voice, tone keywords, and photography attributes for creative alignment.
                                    </p>
                                </div>
                                <div className="mt-6 space-y-6">
                                    <div>
                                        <label htmlFor="brand_look" className="block text-sm font-medium text-gray-900">Brand look</label>
                                        <textarea id="brand_look" rows={4} value={modelPayload.expression?.brand_look ?? ''} onChange={(e) => setModelPayloadField('expression.brand_look', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="Visual style, photography style" />
                                    </div>
                                    <div>
                                        <label htmlFor="brand_voice" className="block text-sm font-medium text-gray-900">Brand voice</label>
                                        <textarea id="brand_voice" rows={4} value={modelPayload.expression?.brand_voice ?? ''} onChange={(e) => setModelPayloadField('expression.brand_voice', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-900">Tone keywords</label>
                                        <textarea rows={3} value={(modelPayload.expression?.tone_keywords || []).join(', ')} onChange={(e) => setModelPayloadField('expression.tone_keywords', e.target.value.split(/[,\n]/).map((s) => s.trim()).filter(Boolean))} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="Comma-separated" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-900">Photography attributes</label>
                                        <textarea rows={3} value={(modelPayload.expression?.photography_attributes || []).join(', ')} onChange={(e) => setModelPayloadField('expression.photography_attributes', e.target.value.split(/[,\n]/).map((s) => s.trim()).filter(Boolean))} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="Comma-separated" />
                                    </div>
                                    <button type="submit" disabled={dnaSaving} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                        {dnaSaving ? 'Saving…' : 'Save Brand DNA'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    )}
                    {activeTab === 'standards' && (
                    <div id="standards" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Standards</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Typography, colors, fonts, and visual references for compliance scoring.
                                    </p>
                                </div>
                                <div className="mt-6 space-y-6">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                        <div>
                                            <label htmlFor="primary_font" className="block text-sm font-medium text-gray-900">Primary font</label>
                                            <input type="text" id="primary_font" value={modelPayload.standards?.primary_font ?? ''} onChange={(e) => setModelPayloadField('standards.primary_font', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm" />
                                        </div>
                                        <div>
                                            <label htmlFor="secondary_font" className="block text-sm font-medium text-gray-900">Secondary font</label>
                                            <input type="text" id="secondary_font" value={modelPayload.standards?.secondary_font ?? ''} onChange={(e) => setModelPayloadField('standards.secondary_font', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm" />
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                        <div>
                                            <label htmlFor="heading_style" className="block text-sm font-medium text-gray-900">Heading style</label>
                                            <input type="text" id="heading_style" value={modelPayload.standards?.heading_style ?? ''} onChange={(e) => setModelPayloadField('standards.heading_style', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm" />
                                        </div>
                                        <div>
                                            <label htmlFor="body_style" className="block text-sm font-medium text-gray-900">Body style</label>
                                            <input type="text" id="body_style" value={modelPayload.standards?.body_style ?? ''} onChange={(e) => setModelPayloadField('standards.body_style', e.target.value || null)} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm" />
                                        </div>
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-900">Allowed colors</label>
                                        <textarea rows={3} value={(modelPayload.standards?.allowed_colors || []).join(', ')} onChange={(e) => setModelPayloadField('standards.allowed_colors', e.target.value.split(/[,\n]/).map((s) => s.trim()).filter(Boolean))} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="Hex codes, comma-separated (e.g. #6366f1, #8b5cf6)" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-900">Banned colors</label>
                                        <textarea rows={3} value={(modelPayload.standards?.banned_colors || []).join(', ')} onChange={(e) => setModelPayloadField('standards.banned_colors', e.target.value.split(/[,\n]/).map((s) => s.trim()).filter(Boolean))} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="Hex codes, comma-separated" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-900">Allowed fonts</label>
                                        <textarea rows={3} value={(modelPayload.standards?.allowed_fonts || []).join(', ')} onChange={(e) => setModelPayloadField('standards.allowed_fonts', e.target.value.split(/[,\n]/).map((s) => s.trim()).filter(Boolean))} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="Comma-separated" />
                                    </div>
                                    {/* Visual References by Category */}
                                    <VisualReferenceCategoryPicker
                                        brandId={brand.id}
                                        referenceCategories={modelPayload.standards?.reference_categories || {}}
                                        onChange={(updated) => setModelPayloadField('standards.reference_categories', updated)}
                                    />

                                    {/* Logo Usage Guidelines */}
                                    <div className="pt-6 border-t border-gray-200">
                                        <div className="flex items-center justify-between mb-4">
                                            <div>
                                                <h4 className="text-sm font-semibold text-gray-900">Logo Usage Guidelines</h4>
                                                <p className="text-xs text-gray-500 mt-0.5">Rules for how the logo should and shouldn&apos;t be used in brand guidelines.</p>
                                            </div>
                                        </div>

                                        {/* Visual Treatment Toggle */}
                                        <div className="flex items-center gap-3 mb-6 p-4 rounded-lg bg-gray-50 ring-1 ring-gray-200/50">
                                            <button
                                                type="button"
                                                role="switch"
                                                aria-checked={modelPayload.standards?.show_logo_visual_treatment !== false}
                                                onClick={() => setModelPayloadField('standards.show_logo_visual_treatment', !(modelPayload.standards?.show_logo_visual_treatment !== false))}
                                                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${modelPayload.standards?.show_logo_visual_treatment !== false ? 'bg-indigo-600' : 'bg-gray-200'}`}
                                            >
                                                <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${modelPayload.standards?.show_logo_visual_treatment !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                                            </button>
                                            <div>
                                                <span className="text-sm font-medium text-gray-900">Show visual treatment</span>
                                                <p className="text-xs text-gray-500">Display logo proofs alongside each guideline in brand guidelines (e.g., stretched, rotated, cropped examples).</p>
                                            </div>
                                        </div>

                                        {/* Editable guideline rules */}
                                        {(() => {
                                            const guidelines = modelPayload.standards?.logo_usage_guidelines || {}
                                            const guidelineKeys = [
                                                { key: 'clear_space', label: 'Clear Space', category: 'do' },
                                                { key: 'minimum_size', label: 'Minimum Size', category: 'do' },
                                                { key: 'color_usage', label: 'Color Usage', category: 'do' },
                                                { key: 'background_contrast', label: 'Background Contrast', category: 'do' },
                                                { key: 'dont_crop', label: "Don't Crop", category: 'dont' },
                                                { key: 'dont_stretch', label: "Don't Stretch", category: 'dont' },
                                                { key: 'dont_rotate', label: "Don't Rotate", category: 'dont' },
                                                { key: 'dont_recolor', label: "Don't Recolor", category: 'dont' },
                                                { key: 'dont_add_effects', label: "Don't Add Effects", category: 'dont' },
                                            ]
                                            const hasGuidelines = Object.keys(guidelines).length > 0
                                            return (
                                                <div className="space-y-4">
                                                    {hasGuidelines ? (
                                                        <>
                                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                                {guidelineKeys.map(({ key, label, category }) => {
                                                                    const val = guidelines[key] ?? ''
                                                                    const isActive = !!val
                                                                    return (
                                                                        <div key={key} className={`rounded-lg border p-3 transition-all ${isActive ? (category === 'dont' ? 'border-red-200 bg-red-50/30' : 'border-indigo-200 bg-indigo-50/30') : 'border-gray-200 bg-gray-50/50'}`}>
                                                                            <div className="flex items-center justify-between mb-2">
                                                                                <div className="flex items-center gap-2">
                                                                                    <input
                                                                                        type="checkbox"
                                                                                        checked={isActive}
                                                                                        onChange={(e) => {
                                                                                            const next = { ...guidelines }
                                                                                            if (e.target.checked) {
                                                                                                next[key] = `${label} guideline description.`
                                                                                            } else {
                                                                                                delete next[key]
                                                                                            }
                                                                                            setModelPayloadField('standards.logo_usage_guidelines', next)
                                                                                        }}
                                                                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                                                    />
                                                                                    <span className={`text-xs font-semibold uppercase tracking-wide ${category === 'dont' ? 'text-red-600' : 'text-gray-700'}`}>{label}</span>
                                                                                </div>
                                                                            </div>
                                                                            {isActive && (
                                                                                <textarea
                                                                                    rows={2}
                                                                                    value={typeof val === 'string' ? val : ''}
                                                                                    onChange={(e) => {
                                                                                        const next = { ...guidelines, [key]: e.target.value }
                                                                                        setModelPayloadField('standards.logo_usage_guidelines', next)
                                                                                    }}
                                                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-600 sm:text-xs resize-none"
                                                                                    placeholder={`Describe the ${label.toLowerCase()} rule...`}
                                                                                />
                                                                            )}
                                                                        </div>
                                                                    )
                                                                })}
                                                            </div>

                                                            {/* Visual preview */}
                                                            {modelPayload.standards?.show_logo_visual_treatment !== false && (data.logo_preview || brand.logo_thumbnail_url || brand.logo_path) && (
                                                                <div className="mt-6 pt-4 border-t border-gray-200">
                                                                    <h5 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Visual Treatment Preview</h5>
                                                                    <div className="grid grid-cols-3 gap-3">
                                                                        {guidelineKeys.filter(({ key }) => !!guidelines[key]).slice(0, 6).map(({ key, label, category }) => {
                                                                            const logoSrc = data.logo_preview || brand.logo_thumbnail_url || brand.logo_path
                                                                            const isDont = category === 'dont'
                                                                            const treatments = {
                                                                                clear_space: (src) => (
                                                                                    <div className="relative w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-center">
                                                                                        <div className="relative">
                                                                                            <div className="absolute inset-0 -m-3 border-2 border-dashed border-blue-400/50 rounded" />
                                                                                            <img src={src} alt="" className="h-6 max-w-[60px] object-contain" />
                                                                                        </div>
                                                                                    </div>
                                                                                ),
                                                                                minimum_size: (src) => (
                                                                                    <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-center gap-2 px-2">
                                                                                        <img src={src} alt="" className="h-6 max-w-[50px] object-contain" />
                                                                                        <img src={src} alt="" className="h-3 max-w-[25px] object-contain" />
                                                                                        <img src={src} alt="" className="h-1.5 max-w-[12px] object-contain opacity-30" />
                                                                                    </div>
                                                                                ),
                                                                                color_usage: (src) => (
                                                                                    <div className="w-full aspect-[3/2] rounded-lg overflow-hidden grid grid-cols-2">
                                                                                        <div className="bg-white flex items-center justify-center p-1"><img src={src} alt="" className="h-5 max-w-[40px] object-contain" /></div>
                                                                                        <div className="flex items-center justify-center p-1" style={{ backgroundColor: brand.primary_color || '#1a1a2e' }}><img src={src} alt="" className="h-5 max-w-[40px] object-contain brightness-0 invert" /></div>
                                                                                    </div>
                                                                                ),
                                                                                background_contrast: (src) => (
                                                                                    <div className="w-full aspect-[3/2] rounded-lg overflow-hidden grid grid-cols-2">
                                                                                        <div className="flex items-center justify-center p-1" style={{ backgroundColor: brand.primary_color || '#002A3A' }}><img src={src} alt="" className="h-5 max-w-[40px] object-contain brightness-0 invert" /></div>
                                                                                        <div className="flex items-center justify-center p-1 bg-[repeating-conic-gradient(#e0e0e0_0%_25%,#fff_0%_50%)] bg-[length:10px_10px]"><img src={src} alt="" className="h-5 max-w-[40px] object-contain opacity-30" /></div>
                                                                                    </div>
                                                                                ),
                                                                                dont_stretch: (src) => (
                                                                                    <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-center relative">
                                                                                        <img src={src} alt="" className="h-6 max-w-[40px] object-contain" style={{ transform: 'scaleX(1.6)' }} />
                                                                                    </div>
                                                                                ),
                                                                                dont_rotate: (src) => (
                                                                                    <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-center relative">
                                                                                        <img src={src} alt="" className="h-6 max-w-[50px] object-contain" style={{ transform: 'rotate(-15deg)' }} />
                                                                                    </div>
                                                                                ),
                                                                                dont_recolor: (src) => (
                                                                                    <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-center relative">
                                                                                        <img src={src} alt="" className="h-6 max-w-[50px] object-contain" style={{ filter: 'hue-rotate(180deg) saturate(2)' }} />
                                                                                    </div>
                                                                                ),
                                                                                dont_crop: (src) => (
                                                                                    <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-end overflow-hidden relative">
                                                                                        <img src={src} alt="" className="h-6 max-w-[50px] object-contain mr-[-12px]" />
                                                                                    </div>
                                                                                ),
                                                                                dont_add_effects: (src) => (
                                                                                    <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-center relative">
                                                                                        <img src={src} alt="" className="h-6 max-w-[50px] object-contain" style={{ filter: 'drop-shadow(3px 3px 4px rgba(0,0,0,0.5))' }} />
                                                                                    </div>
                                                                                ),
                                                                            }
                                                                            const renderTreatment = treatments[key]
                                                                            if (!renderTreatment) return null
                                                                            return (
                                                                                <div key={key} className={`rounded-lg overflow-hidden border ${isDont ? 'border-red-200' : 'border-gray-200'}`}>
                                                                                    {renderTreatment(logoSrc)}
                                                                                    <div className={`px-2 py-1.5 text-[10px] font-semibold uppercase tracking-wide ${isDont ? 'bg-red-50 text-red-600' : 'bg-gray-50 text-gray-600'}`}>
                                                                                        {label}
                                                                                    </div>
                                                                                </div>
                                                                            )
                                                                        })}
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </>
                                                    ) : (
                                                        <div className="text-center py-6 border border-dashed border-gray-300 rounded-lg">
                                                            <p className="text-sm text-gray-500 mb-3">No logo usage guidelines configured yet.</p>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setModelPayloadField('standards.logo_usage_guidelines', {
                                                                        clear_space: 'Maintain a minimum clear space equal to the height of the logo mark on all sides.',
                                                                        minimum_size: 'The logo should never be displayed smaller than 24px in height on digital, or 0.5 inches in print.',
                                                                        color_usage: 'Use the primary brand color version on light backgrounds. Use the reversed (white) version on dark or busy backgrounds.',
                                                                        dont_stretch: 'Never stretch, compress, or distort the logo in any direction.',
                                                                        dont_rotate: 'Never rotate or tilt the logo at an angle.',
                                                                        dont_recolor: 'Never apply unapproved colors, gradients, or effects to the logo.',
                                                                        dont_crop: 'Never crop or partially obscure the logo.',
                                                                        dont_add_effects: 'Never add shadows, outlines, glows, or other visual effects to the logo.',
                                                                        background_contrast: 'Ensure sufficient contrast between the logo and its background. Avoid placing on busy imagery without a container.',
                                                                    })
                                                                }}
                                                                className="rounded-md bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-600 hover:bg-indigo-100 transition-colors"
                                                            >
                                                                Add Standard Defaults
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            )
                                        })()}
                                    </div>

                                    <button type="submit" disabled={dnaSaving} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                        {dnaSaving ? 'Saving…' : 'Save Brand DNA'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    )}

                    {activeTab === 'scoring' && (
                    <div id="scoring" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Scoring Rules</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Define rules for deterministic compliance scoring. Used when Brand DNA is enabled.
                                    </p>
                                </div>
                                <div className="mt-6 space-y-6">
                                    <div className="rounded-lg border border-gray-200 bg-gray-50/50 p-4">
                                        <h3 className="text-sm font-medium text-gray-700 mb-3">Scoring Weights (must total 100%)</h3>
                                        {[
                                            { key: 'color_weight', label: 'Color Weight' },
                                            { key: 'typography_weight', label: 'Typography Weight' },
                                            { key: 'tone_weight', label: 'Tone Weight' },
                                            { key: 'imagery_weight', label: 'Imagery Weight' },
                                        ].map(({ key, label }) => {
                                            const val = Math.round((modelPayload.scoring_config?.[key] ?? 0.2) * 100)
                                            return (
                                                <div key={key} className="flex items-center gap-3 mb-3">
                                                    <label className="w-40 text-sm text-gray-700">{label}</label>
                                                    <input
                                                        type="range"
                                                        min={0}
                                                        max={100}
                                                        value={val}
                                                        onChange={(e) => {
                                                            const v = Number(e.target.value) / 100
                                                            setModelPayloadField(`scoring_config.${key}`, v)
                                                        }}
                                                        className="flex-1 h-2 rounded-lg appearance-none cursor-pointer bg-gray-200 accent-indigo-600"
                                                    />
                                                    <span className="w-10 text-sm font-medium text-gray-700">{val}%</span>
                                                </div>
                                            )
                                        })}
                                        <div className={`mt-2 text-sm font-medium ${weightTotal === 100 ? 'text-green-600' : 'text-red-600'}`}>
                                            Total: {weightTotal}% {weightTotal !== 100 && '— Must equal 100% to save'}
                                        </div>
                                    </div>

                                    {renderTagArrayField('allowed_fonts', 'Allowed Fonts', 'e.g. Helvetica, Inter')}
                                    {renderTagArrayField('banned_colors', 'Banned Colors', 'Colors to penalize')}
                                    {renderTagArrayField('tone_keywords', 'Tone Keywords', 'Words that match brand tone')}
                                    {renderTagArrayField('banned_keywords', 'Banned Keywords', 'Words to penalize')}
                                    {renderTagArrayField('photography_attributes', 'Photography Attributes', 'e.g. minimal, lifestyle')}

                                    <button type="submit" disabled={dnaSaving || weightTotal !== 100} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                        {dnaSaving ? 'Saving…' : 'Save Brand DNA'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    )}
                </form>
                ) : (
                <form onSubmit={submit} className="space-y-8">
                    {/* Tab: Identity */}
                    {activeTab === 'identity' && (
                    <>
                    {/* Section 1: Brand Identity */}
                    <div id="basic-information" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/30 overflow-hidden">
                            <div className="px-6 py-8 sm:px-8 sm:py-10">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Brand Identity</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        These settings define how your brand appears in creative, exports, and brand guidelines.
                                    </p>
                                </div>
                                <div className="mt-6 space-y-6">
                                <div>
                                    <label htmlFor="name" className="block text-sm font-medium leading-6 text-gray-900">
                                        Brand Name
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
                                    <label htmlFor="show_in_selector" className="block text-sm font-medium leading-6 text-gray-900 mb-2">
                                        Show in brand selector
                                    </label>
                                    <button
                                        type="button"
                                        onClick={() => setData('show_in_selector', !data.show_in_selector)}
                                        className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                            data.show_in_selector ? 'bg-indigo-600' : 'bg-gray-200'
                                        }`}
                                        role="switch"
                                        aria-checked={data.show_in_selector}
                                    >
                                        <span
                                            className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                                data.show_in_selector ? 'translate-x-5' : 'translate-x-0'
                                            }`}
                                        />
                                    </button>
                                    <p className="mt-2 text-sm text-gray-500">
                                        When enabled, this brand will appear in the brand selector dropdown in the top navigation. Useful for hiding auto-created default brands.
                                    </p>
                                </div>

                                {/* Brand Images Section — Part 1.4: Equal boxes, Live Preview below */}
                                <div className="pt-6 border-t border-gray-200">
                                    <h4 className="text-sm font-semibold text-gray-900 mb-1">Brand Images</h4>
                                    <p className="text-sm text-gray-500 mb-4">
                                        This logo will be used in creative generation and brand guidelines.
                                    </p>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        {/* Logo — equal height box */}
                                        <div className="flex flex-col">
                                            <label htmlFor="logo" className="block text-sm font-medium text-gray-900 mb-2">
                                                Logo
                                            </label>
                                            <div className="flex-1 min-h-[180px]">
                                                <AssetImagePickerField
                                                    value={{
                                                        preview_url: data.logo_preview ?? (data.logo_id && data.logo_id === brand.logo_id ? (brand.logo_thumbnail_url ?? brand.logo_path) : null),
                                                        asset_id: data.logo_id ?? null,
                                                    }}
                                                    onChange={(v) => {
                                                        if (v == null) {
                                                            setData('logo_id', null)
                                                            setData('logo_preview', null)
                                                            setData('clear_logo', true)
                                                        } else if (v?.asset_id) {
                                                            setData('logo_id', v.asset_id)
                                                            setData('logo_preview', v.preview_url ?? v.thumbnail_url ?? null)
                                                            setData('logo', null)
                                                            setData('clear_logo', false)
                                                        }
                                                    }}
                                                    fetchAssets={(opts) => {
                                                        const params = new URLSearchParams({ format: 'json' })
                                                        if (opts?.category) params.set('category', opts.category)
                                                        return fetch(`/app/assets?${params}`, {
                                                            credentials: 'same-origin',
                                                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                                        }).then((r) => r.json())
                                                    }}
                                                    getAssetDownloadUrl={(id) => `/app/assets/${id}/download`}
                                                    title="Select logo"
                                                    defaultCategoryLabel="Logos"
                                                    contextCategory="logos"
                                                    aspectRatio={{ width: 265, height: 64 }}
                                                    minWidth={265}
                                                    minHeight={64}
                                                    placeholder="Click to choose from library or upload"
                                                    helperText="Recommended: 265×64 px or similar aspect ratio"
                                                    className="h-full"
                                                />
                                            </div>
                                            {errors.logo && <p className="mt-2 text-sm text-red-600">{errors.logo}</p>}
                                        </div>

                                        {/* Icon — equal height box */}
                                        <div className="flex flex-col">
                                            <label htmlFor="icon" className="block text-sm font-medium text-gray-900 mb-2">
                                                Icon
                                            </label>
                                            <p className="text-xs text-gray-500 mb-2">
                                                Square (1:1) format. Used in compact displays.
                                            </p>
                                            <div className="flex-1 min-h-[180px]">
                                                <AssetImagePickerField
                                                    value={{
                                                        preview_url: data.icon_preview ?? (data.icon_id && data.icon_id === brand.icon_id ? (brand.icon_thumbnail_url ?? brand.icon_path) : null),
                                                        asset_id: data.icon_id ?? null,
                                                    }}
                                                    onChange={(v) => {
                                                        if (v == null) {
                                                            setData('icon_id', null)
                                                            setData('icon_preview', null)
                                                            setData('clear_icon', true)
                                                        } else if (v?.asset_id) {
                                                            setData('icon_id', v.asset_id)
                                                            setData('icon_preview', v.preview_url ?? v.thumbnail_url ?? null)
                                                            setData('icon', null)
                                                            setData('clear_icon', false)
                                                        }
                                                    }}
                                                    fetchAssets={(opts) => {
                                                        const params = new URLSearchParams({ format: 'json' })
                                                        if (opts?.category) params.set('category', opts.category)
                                                        return fetch(`/app/assets?${params}`, {
                                                            credentials: 'same-origin',
                                                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                                        }).then((r) => r.json())
                                                    }}
                                                    getAssetDownloadUrl={(id) => `/app/assets/${id}/download`}
                                                    title="Select icon"
                                                    defaultCategoryLabel="Logos"
                                                    contextCategory="logos"
                                                    aspectRatio={{ width: 1, height: 1 }}
                                                    minWidth={64}
                                                    minHeight={64}
                                                    placeholder="Click to choose from library or upload"
                                                    helperText="Square format recommended"
                                                    className="h-full"
                                                />
                                            </div>
                                            {errors.icon && <p className="mt-2 text-sm text-red-600">{errors.icon}</p>}
                                        </div>
                                    </div>

                                    {/* Live Preview — full width below both columns */}
                                    <div className="mt-6 p-4 bg-gray-50/80 rounded-xl border border-gray-200/80">
                                        <p className="text-sm font-medium text-gray-700 mb-4">Live Preview</p>
                                        <div className="flex items-center gap-6">
                                            <BrandAvatar
                                                iconPath={data.icon_preview ?? brand.icon_thumbnail_url ?? brand.icon_path}
                                                name={brand.name}
                                                primaryColor={data.primary_color ?? brand.primary_color ?? '#6366f1'}
                                                iconBgColor={(() => {
                                                    if (iconBgStyle === 'primary') return data.primary_color ?? brand.primary_color ?? '#6366f1'
                                                    if (iconBgStyle === 'secondary') return data.secondary_color ?? brand.secondary_color ?? '#64748b'
                                                    if (iconBgStyle === 'accent') return data.accent_color ?? brand.accent_color ?? '#6366f1'
                                                    return data.icon_bg_color ?? brand.icon_bg_color ?? data.primary_color ?? '#6366f1'
                                                })()}
                                                showIcon={true}
                                                size="xl"
                                                className="shadow-md"
                                            />
                                            <div className="flex-1">
                                                <label className="block text-xs font-medium text-gray-700 mb-2">
                                                    Background Style
                                                </label>
                                                <div className="flex flex-wrap gap-2">
                                                    {['primary', 'secondary', 'accent', 'custom'].map((style) => (
                                                        <button
                                                            key={style}
                                                            type="button"
                                                            onClick={() => {
                                                                setIconBgStyle(style)
                                                                if (style === 'primary') setData('icon_bg_color', data.primary_color || brand.primary_color || '')
                                                                else if (style === 'secondary') setData('icon_bg_color', data.secondary_color || brand.secondary_color || '')
                                                                else if (style === 'accent') setData('icon_bg_color', data.accent_color || brand.accent_color || '')
                                                                else if (style === 'custom') setData('icon_bg_color', data.icon_bg_color || data.primary_color || '#6366f1')
                                                            }}
                                                            className={`px-3 py-1.5 rounded-md text-xs font-medium border-2 transition-all ${
                                                                iconBgStyle === style
                                                                    ? 'border-indigo-600 ring-2 ring-indigo-600 ring-offset-1 bg-indigo-50 text-indigo-700'
                                                                    : 'border-gray-200 hover:border-gray-300 text-gray-700'
                                                            }`}
                                                        >
                                                            {style.charAt(0).toUpperCase() + style.slice(1)}
                                                        </button>
                                                    ))}
                                                </div>
                                                {iconBgStyle === 'custom' && (
                                                    <div className="mt-3 flex gap-2 items-center">
                                                        <input
                                                            type="color"
                                                            value={(() => {
                                                                const v = data.icon_bg_color || data.primary_color || '#6366f1'
                                                                return v.startsWith('#') ? v : '#' + v
                                                            })()}
                                                            onChange={(e) => setData('icon_bg_color', e.target.value)}
                                                            className="h-8 w-14 rounded border border-gray-300 cursor-pointer flex-shrink-0"
                                                        />
                                                        <input
                                                            type="text"
                                                            name="icon_bg_color"
                                                            value={data.icon_bg_color || ''}
                                                            onChange={(e) => setData('icon_bg_color', e.target.value)}
                                                            className="block w-24 rounded-md border py-1.5 px-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600"
                                                            placeholder="#6366f1"
                                                            pattern="^#[0-9A-Fa-f]{6}$"
                                                        />
                                                    </div>
                                                )}
                                                {errors.icon_bg_color && <p className="mt-1 text-xs text-red-600">{errors.icon_bg_color}</p>}
                                            </div>
                                        </div>
                                        {!(data.icon_preview ?? brand.icon_thumbnail_url ?? brand.icon_path) && (
                                            <p className="text-xs text-gray-400 text-center py-4 mt-2">
                                                Upload an icon to see preview
                                            </p>
                                        )}
                                    </div>
                                </div>

                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Section 2: Brand Colors */}
                    <div id="brand-colors" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/30 overflow-hidden">
                            <div className="px-6 py-8 sm:px-8 sm:py-10">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Brand Colors</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Define your brand's color palette. These colors will be used throughout the application.
                                    </p>
                                </div>
                                <div className="mt-6">
                                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                                        <div>
                                    <label htmlFor="primary_color" className="block text-sm font-medium leading-6 text-gray-900">
                                        Primary Color
                                    </label>
                                    <div className="mt-2 flex gap-2">
                                        <input
                                            type="color"
                                            id="primary_color_picker"
                                            value={data.primary_color || '#6366f1'}
                                            onChange={(e) => {
                                                setData('primary_color', e.target.value)
                                                // Auto-update nav_color if it's empty or matches old primary
                                                if (!data.nav_color || data.nav_color === data.primary_color) {
                                                    setData('nav_color', e.target.value)
                                                }
                                            }}
                                            className="h-10 w-20 rounded border border-gray-300 cursor-pointer"
                                        />
                                        <input
                                            type="text"
                                            name="primary_color"
                                            id="primary_color"
                                            value={data.primary_color}
                                            onChange={(e) => {
                                                setData('primary_color', e.target.value)
                                                // Auto-update nav_color if it's empty or matches old primary
                                                if (!data.nav_color || data.nav_color === data.primary_color) {
                                                    setData('nav_color', e.target.value)
                                                }
                                            }}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder="#6366f1"
                                            pattern="^#[0-9A-Fa-f]{6}$"
                                        />
                                    </div>
                                            {errors.primary_color && <p className="mt-2 text-sm text-red-600">{errors.primary_color}</p>}
                                        </div>

                                        <div>
                                    <label htmlFor="secondary_color" className="block text-sm font-medium leading-6 text-gray-900">
                                        Secondary Color
                                    </label>
                                    <div className="mt-2 flex gap-2">
                                        <input
                                            type="color"
                                            id="secondary_color_picker"
                                            value={data.secondary_color || '#8b5cf6'}
                                            onChange={(e) => setData('secondary_color', e.target.value)}
                                            className="h-10 w-20 rounded border border-gray-300 cursor-pointer"
                                        />
                                        <input
                                            type="text"
                                            name="secondary_color"
                                            id="secondary_color"
                                            value={data.secondary_color}
                                            onChange={(e) => setData('secondary_color', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder="#8b5cf6"
                                            pattern="^#[0-9A-Fa-f]{6}$"
                                        />
                                    </div>
                                            {errors.secondary_color && <p className="mt-2 text-sm text-red-600">{errors.secondary_color}</p>}
                                        </div>

                                        <div>
                                    <label htmlFor="accent_color" className="block text-sm font-medium leading-6 text-gray-900">
                                        Accent Color
                                    </label>
                                    <div className="mt-2 flex gap-2">
                                        <input
                                            type="color"
                                            id="accent_color_picker"
                                            value={data.accent_color || '#ec4899'}
                                            onChange={(e) => setData('accent_color', e.target.value)}
                                            className="h-10 w-20 rounded border border-gray-300 cursor-pointer"
                                        />
                                        <input
                                            type="text"
                                            name="accent_color"
                                            id="accent_color"
                                            value={data.accent_color}
                                            onChange={(e) => setData('accent_color', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder="#ec4899"
                                            pattern="^#[0-9A-Fa-f]{6}$"
                                        />
                                    </div>
                                            {errors.accent_color && <p className="mt-2 text-sm text-red-600">{errors.accent_color}</p>}
                                        </div>
                                    </div>

                                    {/* Color Preview */}
                            {(data.primary_color || data.secondary_color || data.accent_color) && (
                                <div className="mt-6 pt-6 border-t border-gray-200">
                                    <p className="text-sm font-medium text-gray-700 mb-3">Color Preview:</p>
                                    <div className="flex gap-2">
                                        {data.primary_color && (
                                            <div className="flex-1">
                                                <div
                                                    className="h-16 rounded-md border border-gray-200"
                                                    style={{ backgroundColor: data.primary_color }}
                                                />
                                                <p className="mt-2 text-xs text-center text-gray-600">Primary</p>
                                            </div>
                                        )}
                                        {data.secondary_color && (
                                            <div className="flex-1">
                                                <div
                                                    className="h-16 rounded-md border border-gray-200"
                                                    style={{ backgroundColor: data.secondary_color }}
                                                />
                                                <p className="mt-2 text-xs text-center text-gray-600">Secondary</p>
                                            </div>
                                        )}
                                        {data.accent_color && (
                                            <div className="flex-1">
                                                <div
                                                    className="h-16 rounded-md border border-gray-200"
                                                    style={{ backgroundColor: data.accent_color }}
                                                />
                                                <p className="mt-2 text-xs text-center text-gray-600">Accent</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Metadata Section - HIDDEN: Will be re-homed later */}
                    {SHOW_CATEGORIES_AND_METADATA && (
                    <div id="metadata" className="scroll-mt-8 grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div className="lg:col-span-1">
                            <h3 className="text-base font-semibold leading-6 text-gray-900">Metadata</h3>
                            <p className="mt-2 text-sm text-gray-500">
                                Configure metadata approval workflows and manage metadata fields for this brand.
                            </p>
                        </div>
                        <div className="lg:col-span-2">
                            <div className="overflow-hidden bg-white shadow sm:rounded-lg">
                                <div className="px-4 py-5 sm:p-6">
                                    <div className="space-y-6">
                                        {/* Phase M-2: Metadata Approval Toggle */}
                                        {['pro', 'premium', 'enterprise'].includes(current_plan) && tenant_settings?.enable_metadata_approval && (
                                            <div>
                                                <div className="flex items-center justify-between">
                                                    <div className="flex-1">
                                                        <label htmlFor="metadata_approval_enabled" className="block text-sm font-medium leading-6 text-gray-900">
                                                            Enable metadata approval for this brand
                                                        </label>
                                                        <p className="mt-1 text-sm text-gray-500">
                                                            Metadata approval is available for this plan and can be enabled per brand. When enabled, metadata edits by contributors and viewers will require approval from brand managers or admins.
                                                        </p>
                                                    </div>
                                                    <div className="ml-4">
                                                        <button
                                                            type="button"
                                                            onClick={() => setData('settings.metadata_approval_enabled', !data.settings?.metadata_approval_enabled)}
                                                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                                                data.settings?.metadata_approval_enabled ? 'bg-indigo-600' : 'bg-gray-200'
                                                            }`}
                                                            role="switch"
                                                            aria-checked={data.settings?.metadata_approval_enabled}
                                                        >
                                                            <span
                                                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                                                    data.settings?.metadata_approval_enabled ? 'translate-x-5' : 'translate-x-0'
                                                                }`}
                                                            />
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                        {(!['pro', 'premium', 'enterprise'].includes(current_plan) || !tenant_settings?.enable_metadata_approval) && (
                                            <div className="rounded-md bg-gray-50 p-4">
                                                <p className="text-sm text-gray-600">
                                                    {!['pro', 'premium', 'enterprise'].includes(current_plan) 
                                                        ? 'Metadata approval workflows require a Pro or Enterprise plan.'
                                                        : 'Metadata approval must be enabled at the company level first.'}
                                                </p>
                                            </div>
                                        )}

                                        {/* Phase J.3.1: Contributor Upload Approval Toggle */}
                                        {current_plan === 'enterprise' && tenant_settings?.features?.contributor_asset_approval && (
                                            <div className="border-t border-gray-200 pt-6">
                                                <div className="flex items-center justify-between">
                                                    <div className="flex-1">
                                                        <label htmlFor="contributor_upload_requires_approval" className="block text-sm font-medium leading-6 text-gray-900">
                                                            Require approval before contributor uploads are published
                                                        </label>
                                                        <p className="mt-1 text-sm text-gray-500">
                                                            When enabled, assets uploaded by contributors will require approval from an admin or brand manager before they are published and visible in the asset grid.
                                                        </p>
                                                    </div>
                                                    <div className="ml-4">
                                                        <button
                                                            type="button"
                                                            onClick={(e) => {
                                                                e.preventDefault()
                                                                e.stopPropagation()
                                                                // Handle both boolean and string '0'/'1' values
                                                                const currentValue = data.settings?.contributor_upload_requires_approval
                                                                const isCurrentlyEnabled = currentValue === true || currentValue === '1' || currentValue === 1
                                                                const newValue = !isCurrentlyEnabled
                                                                console.log('[Brands/Edit] Toggling contributor_upload_requires_approval:', isCurrentlyEnabled, '->', newValue, 'Current data.settings:', data.settings)
                                                                // Ensure settings object exists, then update the specific field
                                                                const updatedSettings = {
                                                                    ...(data.settings || {}),
                                                                    contributor_upload_requires_approval: newValue,
                                                                }
                                                                setData('settings', updatedSettings)
                                                                console.log('[Brands/Edit] Updated settings:', updatedSettings)
                                                            }}
                                                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                                                (data.settings?.contributor_upload_requires_approval === true || data.settings?.contributor_upload_requires_approval === '1' || data.settings?.contributor_upload_requires_approval === 1) ? 'bg-indigo-600' : 'bg-gray-200'
                                                            }`}
                                                            role="switch"
                                                            aria-checked={data.settings?.contributor_upload_requires_approval === true || data.settings?.contributor_upload_requires_approval === '1' || data.settings?.contributor_upload_requires_approval === 1}
                                                        >
                                                            <span
                                                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                                                    (data.settings?.contributor_upload_requires_approval === true || data.settings?.contributor_upload_requires_approval === '1' || data.settings?.contributor_upload_requires_approval === 1) ? 'translate-x-5' : 'translate-x-0'
                                                                }`}
                                                            />
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                        {(!(current_plan === 'enterprise' && tenant_settings?.features?.contributor_asset_approval)) && (
                                            <div className="border-t border-gray-200 pt-6">
                                                <div className="rounded-md bg-gray-50 p-4">
                                                    <p className="text-sm text-gray-600">
                                                        {current_plan !== 'enterprise'
                                                            ? 'Contributor upload approval requires an Enterprise plan.'
                                                            : 'Contributor upload approval must be enabled at the company level first.'}
                                                    </p>
                                                </div>
                                            </div>
                                        )}

                                        {/* Metadata Fields Management */}
                                        <div className="border-t border-gray-200 pt-6">
                                            <div>
                                                <h4 className="text-sm font-medium leading-6 text-gray-900 mb-2">
                                                    Metadata Fields
                                                </h4>
                                                <p className="text-sm text-gray-500 mb-4">
                                                    Manage metadata fields and visibility settings for this brand. Configure where fields appear in upload, edit, and filter interfaces.
                                                </p>
                                                <Link
                                                    href="/app/tenant/metadata/registry"
                                                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
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
                    </div>
                    )}

                    </>
                    )}

                    {/* Tab: Brand Portal — Unified portal control surface */}
                    {activeTab === 'brand-portal' && (
                    <div id="brand-portal" className="scroll-mt-8 space-y-6">
                        {/* Quick Actions Bar */}
                        {portal_url && (
                            <div className="rounded-xl bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-100 px-5 py-4 flex items-center justify-between">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-gray-800">Public Portal</p>
                                    <a
                                        href={portal_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-xs font-mono text-indigo-600 hover:text-indigo-700 truncate block"
                                    >
                                        {portal_url}
                                    </a>
                                </div>
                                <a
                                    href={portal_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex-shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-medium hover:bg-indigo-700 transition-colors"
                                >
                                    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    View as Client
                                </a>
                            </div>
                        )}

                        {/* Section A: Entry Experience */}
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <EntryExperience
                                    data={data}
                                    setData={setData}
                                    portalFeatures={portal_features}
                                    brand={brand}
                                />
                            </div>
                        </div>

                        {/* Section B: Public Access (absorbs old Public Pages) */}
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <PublicAccess
                                    data={data}
                                    setData={setData}
                                    portalFeatures={portal_features}
                                    brand={brand}
                                    portalUrl={portal_url}
                                    route={typeof route === 'function' ? route : (name, params) => {
                                        const p = params && typeof params === 'object' && !Array.isArray(params) ? params : {}
                                        if (name === 'brands.download-background-candidates') return `/app/brands/${p.brand ?? params ?? brand.id}/download-background-candidates`
                                        return '#'
                                    }}
                                />
                            </div>
                        </div>

                        {/* Section C: Sharing & Links */}
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <SharingLinks
                                    data={data}
                                    setData={setData}
                                    portalFeatures={portal_features}
                                />
                            </div>
                        </div>

                        {/* Section D: Invite Experience */}
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <InviteExperience
                                    data={data}
                                    setData={setData}
                                    portalFeatures={portal_features}
                                    brand={brand}
                                />
                            </div>
                        </div>

                        {/* Section E: Agency Templates */}
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <AgencyTemplates
                                    data={data}
                                    setData={setData}
                                    portalFeatures={portal_features}
                                />
                            </div>
                        </div>
                    </div>
                    )}

                    {/* Tab: Workspace Appearance */}
                    {activeTab === 'workspace' && (
                    <div id="workspace-appearance" className="scroll-mt-8 space-y-6">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-1">
                                    <h2 className="text-xl font-semibold text-gray-900">Workspace Appearance</h2>
                                    <p className="mt-3 text-sm text-gray-600 leading-relaxed max-w-xl">
                                        Control how the DAM interface looks for this brand.
                                    </p>
                                </div>

                                {/* Navigation Display */}
                                <div className="mt-10">
                                    <h3 className="text-base font-semibold text-gray-900 mb-1">Navigation Display</h3>
                                    <p className="text-sm text-gray-500 mb-5">
                                        Choose what appears in the top navigation bar for this brand.
                                    </p>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-lg">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                const newSettings = { ...data.settings, nav_display_mode: 'logo' }
                                                setData('settings', newSettings)
                                                autoSaveBrandField({ settings: newSettings })
                                            }}
                                            className={`relative flex items-center gap-3 p-4 rounded-lg border-2 transition-all text-left ${
                                                (data.settings?.nav_display_mode || 'logo') === 'logo' ? 'border-indigo-600 ring-2 ring-indigo-600 bg-indigo-50/30' : 'border-gray-200 hover:border-gray-300'
                                            }`}
                                        >
                                            <div className="flex-shrink-0 w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center">
                                                <svg className="h-5 w-5 text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" /></svg>
                                            </div>
                                            <div>
                                                <span className="text-sm font-medium text-gray-900">Logo</span>
                                                <p className="text-xs text-gray-500 mt-0.5">Show your brand logo</p>
                                            </div>
                                            {(data.settings?.nav_display_mode || 'logo') === 'logo' && (
                                                <div className="absolute top-2 right-2">
                                                    <svg className="h-4 w-4 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                </div>
                                            )}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                const newSettings = { ...data.settings, nav_display_mode: 'text' }
                                                setData('settings', newSettings)
                                                autoSaveBrandField({ settings: newSettings })
                                            }}
                                            className={`relative flex items-center gap-3 p-4 rounded-lg border-2 transition-all text-left ${
                                                data.settings?.nav_display_mode === 'text' ? 'border-indigo-600 ring-2 ring-indigo-600 bg-indigo-50/30' : 'border-gray-200 hover:border-gray-300'
                                            }`}
                                        >
                                            <div className="flex-shrink-0 w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center">
                                                <svg className="h-5 w-5 text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                                            </div>
                                            <div>
                                                <span className="text-sm font-medium text-gray-900">Brand Name</span>
                                                <p className="text-xs text-gray-500 mt-0.5">Show text instead of logo</p>
                                            </div>
                                            {data.settings?.nav_display_mode === 'text' && (
                                                <div className="absolute top-2 right-2">
                                                    <svg className="h-4 w-4 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                </div>
                                            )}
                                        </button>
                                    </div>
                                    {!(data.logo_preview || brand.logo_thumbnail_url || brand.logo_path) && (data.settings?.nav_display_mode || 'logo') === 'logo' && (
                                        <p className="mt-3 text-xs text-amber-600 flex items-center gap-1.5">
                                            <svg className="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                            No logo uploaded. Upload a logo on the Identity tab or switch to Brand Name mode.
                                        </p>
                                    )}
                                </div>

                                {/* Logo Filter — only when logo mode is selected and a logo exists */}
                                {(data.settings?.nav_display_mode || 'logo') === 'logo' && (data.logo_preview || brand.logo_thumbnail_url || brand.logo_path) && (
                                    <div className="mt-8">
                                        <h4 className="text-sm font-medium text-gray-900 mb-1">Logo Appearance in Navigation</h4>
                                        <p className="text-sm text-gray-500 mb-4">
                                            Apply a filter to ensure your logo is visible on the white navigation bar.
                                        </p>
                                        <div className="flex gap-3 max-w-2xl flex-wrap">
                                            {[
                                                { value: 'none', label: 'Original', desc: 'No filter applied' },
                                                { value: 'black', label: 'Dark', desc: 'Force dark version' },
                                                { value: 'white', label: 'Light', desc: 'Force light version' },
                                                { value: 'primary', label: 'Primary', desc: 'Use brand primary color' },
                                            ].map((opt) => {
                                                const primaryColor = data.primary_color || brand.primary_color || '#6366f1'
                                                const filterStyle = opt.value === 'white'
                                                    ? { filter: 'brightness(0) invert(1)' }
                                                    : opt.value === 'black'
                                                    ? { filter: 'brightness(0)' }
                                                    : opt.value === 'primary'
                                                    ? getFilterStyleForColor(primaryColor)
                                                    : {}
                                                return (
                                                    <button
                                                        key={opt.value}
                                                        type="button"
                                                        onClick={() => {
                                                            setData('logo_filter', opt.value)
                                                            autoSaveBrandField({ logo_filter: opt.value })
                                                        }}
                                                        className={`flex-1 min-w-[120px] flex flex-col items-center p-3 rounded-lg border-2 transition-all ${
                                                            (data.logo_filter || 'none') === opt.value ? 'border-indigo-600 ring-2 ring-indigo-600' : 'border-gray-200 hover:border-gray-300'
                                                        }`}
                                                    >
                                                        <div className="w-full h-10 rounded-md mb-2 bg-white border border-gray-100 flex items-center justify-center overflow-hidden">
                                                            <img
                                                                src={data.logo_preview || brand.logo_thumbnail_url || brand.logo_path}
                                                                alt=""
                                                                className="h-6 w-auto object-contain"
                                                                style={filterStyle}
                                                            />
                                                        </div>
                                                        <span className="text-xs font-medium text-gray-900">{opt.label}</span>
                                                        <span className="text-[10px] text-gray-500">{opt.desc}</span>
                                                    </button>
                                                )
                                            })}
                                        </div>
                                        {(data.logo_filter || 'none') === 'none' && (
                                            <div className="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-start gap-2 max-w-lg">
                                                <svg className="h-4 w-4 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                                <p className="text-xs text-amber-700 leading-relaxed">
                                                    If your logo uses light colors it may be hard to read on the white navigation bar. Consider applying the <strong>Dark</strong> filter for better visibility.
                                                </p>
                                            </div>
                                        )}
                                        {(data.logo_filter || 'none') === 'white' && (
                                            <div className="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-start gap-2 max-w-lg">
                                                <svg className="h-4 w-4 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                                <p className="text-xs text-amber-700 leading-relaxed">
                                                    The <strong>Light</strong> filter will make the logo white, which will not be visible on the white navigation bar. Use <strong>Original</strong> or <strong>Dark</strong> instead.
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                )}

                                <hr className="my-10 border-gray-200" />

                                <div className="grid grid-cols-1 lg:grid-cols-2 gap-12">
                                    {/* Button style selection */}
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-900 mb-1">Button Style</h4>
                                        <p className="text-sm text-gray-500 mb-4">
                                            Color for Add Asset and primary action buttons in the workspace.
                                        </p>
                                        <div className="flex gap-2">
                                            {['primary', 'secondary', 'accent'].map((style) => {
                                                const hex = style === 'primary' ? (data.primary_color || brand.primary_color || '#6366f1') : style === 'secondary' ? (data.secondary_color || brand.secondary_color || '#64748b') : (data.accent_color || brand.accent_color || '#6366f1')
                                                return (
                                                    <button
                                                        key={style}
                                                        type="button"
                                                        onClick={() => {
                                                            setData('workspace_button_style', style)
                                                            autoSaveBrandField({ workspace_button_style: style })
                                                        }}
                                                        className={`flex-1 flex flex-col items-center p-3 rounded-lg border-2 transition-all ${
                                                            (data.workspace_button_style ?? data.settings?.button_style ?? 'primary') === style ? 'border-indigo-600 ring-2 ring-indigo-600' : 'border-gray-200 hover:border-gray-300'
                                                        }`}
                                                    >
                                                        <div className="w-full h-10 rounded-md mb-1.5 flex items-center justify-center text-white text-xs font-medium" style={{ backgroundColor: hex }}>
                                                            {style.charAt(0).toUpperCase() + style.slice(1)}
                                                        </div>
                                                        <span className="text-xs font-medium text-gray-900">{style.charAt(0).toUpperCase() + style.slice(1)}</span>
                                                    </button>
                                                )
                                            })}
                                        </div>
                                    </div>
                                    {/* Asset grid styling */}
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-900 mb-1">Asset Grid Styling</h4>
                                        <p className="text-sm text-gray-500 mb-4">
                                            How asset tiles appear in the Assets grid. Clean is minimal with floating labels; Impact uses shadows and attached titles.
                                        </p>
                                        <div className="flex gap-2">
                                            {[
                                                { value: 'clean', label: 'Clean', desc: 'Minimal, floating labels' },
                                                { value: 'impact', label: 'Impact', desc: 'Shadows, attached titles' },
                                            ].map((opt) => (
                                                <button
                                                    key={opt.value}
                                                    type="button"
                                                    onClick={() => {
                                                        const newSettings = { ...data.settings, asset_grid_style: opt.value }
                                                        setData('settings', newSettings)
                                                        autoSaveBrandField({ settings: newSettings })
                                                    }}
                                                    className={`flex-1 flex flex-col items-center p-3 rounded-lg border-2 transition-all ${
                                                        (data.settings?.asset_grid_style ?? 'clean') === opt.value ? 'border-indigo-600 ring-2 ring-indigo-600' : 'border-gray-200 hover:border-gray-300'
                                                    }`}
                                                >
                                                    <span className="text-sm font-medium text-gray-900">{opt.label}</span>
                                                    <span className="text-xs text-gray-500 mt-0.5">{opt.desc}</span>
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                    {/* Sidebar color selection */}
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-900 mb-1">Sidebar color</h4>
                                        <p className="text-sm text-gray-500 mb-4">
                                            Choose a color from your brand palette. Leave empty to use the primary color.
                                        </p>
                                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    const val = data.primary_color || ''
                                                    setData('nav_color', val)
                                                    autoSaveBrandField({ nav_color: val })
                                                }}
                                                className={`relative flex flex-col items-center p-3 rounded-lg border-2 transition-all ${
                                                    (data.nav_color && data.nav_color === data.primary_color) ? 'border-indigo-600 ring-2 ring-indigo-600' : 'border-gray-200 hover:border-gray-300'
                                                }`}
                                            >
                                                <div className="w-full h-12 rounded-md mb-1.5" style={{ backgroundColor: data.primary_color || '#6366f1' }} />
                                                <span className="text-xs font-medium text-gray-900">Primary</span>
                                                {(data.nav_color && data.nav_color === data.primary_color) && (
                                                    <div className="absolute top-1.5 right-1.5">
                                                        <svg className="h-4 w-4 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                    </div>
                                                )}
                                            </button>
                                            {data.secondary_color ? (
                                                <button type="button" onClick={() => {
                                                    setData('nav_color', data.secondary_color)
                                                    autoSaveBrandField({ nav_color: data.secondary_color })
                                                }}
                                                    className={`relative flex flex-col items-center p-3 rounded-lg border-2 transition-all ${data.nav_color === data.secondary_color ? 'border-indigo-600 ring-2 ring-indigo-600' : 'border-gray-200 hover:border-gray-300'}`}>
                                                    <div className="w-full h-12 rounded-md mb-1.5" style={{ backgroundColor: data.secondary_color }} />
                                                    <span className="text-xs font-medium text-gray-900">Secondary</span>
                                                    {data.nav_color === data.secondary_color && (
                                                        <div className="absolute top-1.5 right-1.5">
                                                            <svg className="h-4 w-4 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                        </div>
                                                    )}
                                                </button>
                                            ) : (
                                                <div className="flex flex-col items-center p-3 rounded-lg border-2 border-gray-100 opacity-50">
                                                    <div className="w-full h-12 rounded-md mb-1.5 bg-gray-50 border-2 border-dashed border-gray-200" />
                                                    <span className="text-xs font-medium text-gray-400">Secondary</span>
                                                </div>
                                            )}
                                            {data.accent_color ? (
                                                <button type="button" onClick={() => {
                                                    setData('nav_color', data.accent_color)
                                                    autoSaveBrandField({ nav_color: data.accent_color })
                                                }}
                                                    className={`relative flex flex-col items-center p-3 rounded-lg border-2 transition-all ${data.nav_color === data.accent_color ? 'border-indigo-600 ring-2 ring-indigo-600' : 'border-gray-200 hover:border-gray-300'}`}>
                                                    <div className="w-full h-12 rounded-md mb-1.5" style={{ backgroundColor: data.accent_color }} />
                                                    <span className="text-xs font-medium text-gray-900">Accent</span>
                                                    {data.nav_color === data.accent_color && (
                                                        <div className="absolute top-1.5 right-1.5">
                                                            <svg className="h-4 w-4 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                        </div>
                                                    )}
                                                </button>
                                            ) : (
                                                <div className="flex flex-col items-center p-3 rounded-lg border-2 border-gray-100 opacity-50">
                                                    <div className="w-full h-12 rounded-md mb-1.5 bg-gray-50 border-2 border-dashed border-gray-200" />
                                                    <span className="text-xs font-medium text-gray-400">Accent</span>
                                                </div>
                                            )}
                                            <button type="button" onClick={() => {
                                                setData('nav_color', '')
                                                // "Use Primary" = store primary color so validation passes
                                                autoSaveBrandField({ nav_color: data.primary_color || brand.primary_color || '#6366f1' })
                                            }}
                                                className={`relative flex flex-col items-center p-3 rounded-lg border-2 transition-all ${(!data.nav_color || data.nav_color === '') ? 'border-indigo-600 ring-2 ring-indigo-600' : 'border-gray-200 hover:border-gray-300'}`}>
                                                <div className="w-full h-12 rounded-md mb-1.5 bg-gray-100 border-2 border-dashed border-gray-300 flex items-center justify-center">
                                                    <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" /></svg>
                                                </div>
                                                <span className="text-xs font-medium text-gray-900">Use Primary</span>
                                                {(!data.nav_color || data.nav_color === '') && (
                                                    <div className="absolute top-1.5 right-1.5">
                                                        <svg className="h-4 w-4 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                    </div>
                                                )}
                                            </button>
                                        </div>
                                        {errors.nav_color && <p className="mt-2 text-sm text-red-600">{errors.nav_color}</p>}
                                    </div>
                                    {/* DAM Appearance Preview */}
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-900 mb-1">Preview</h4>
                                        <p className="text-sm text-gray-500 mb-4">
                                            A preview of how the workspace will appear in the DAM.
                                        </p>
                                        {(() => {
                                            const sidebarColor = data.nav_color || data.primary_color || brand.primary_color || '#6366f1'
                                            const sidebarTextColor = getContrastTextColor(sidebarColor)
                                            const btnStyle = data.workspace_button_style ?? data.settings?.button_style ?? 'primary'
                                            const btnColor = btnStyle === 'primary' ? (data.primary_color || brand.primary_color || '#6366f1') : btnStyle === 'secondary' ? (data.secondary_color || brand.secondary_color || '#64748b') : (data.accent_color || brand.accent_color || '#6366f1')
                                            const previewLogoSrc = data.logo_preview || brand.logo_thumbnail_url || brand.logo_path
                                            const previewNavMode = data.settings?.nav_display_mode || 'logo'
                                            const previewFilterValue = data.logo_filter || 'none'
                                            const previewLogoFilter = previewFilterValue === 'white'
                                                ? { filter: 'brightness(0) invert(1)' }
                                                : previewFilterValue === 'black'
                                                ? { filter: 'brightness(0)' }
                                                : previewFilterValue === 'primary'
                                                ? getFilterStyleForColor(data.primary_color || brand.primary_color || '#6366f1')
                                                : {}
                                            return (
                                                <div className="rounded-lg border border-gray-200 overflow-hidden bg-gray-50 shadow-inner">
                                                    {/* Top navigation bar (white) */}
                                                    <div className="bg-white border-b border-gray-200 px-3 py-1.5 flex items-center gap-2">
                                                        {previewNavMode === 'logo' && previewLogoSrc ? (
                                                            <img src={previewLogoSrc} alt="" className="h-5 w-auto max-w-[80px] object-contain" style={previewLogoFilter} />
                                                        ) : (
                                                            <div className="flex items-center gap-1.5">
                                                                <div className="w-4 h-4 rounded-full flex-shrink-0" style={{ backgroundColor: data.primary_color || brand.primary_color || '#6366f1' }} />
                                                                <span className="text-[9px] font-semibold text-gray-800 truncate max-w-[70px]">{data.name || brand.name}</span>
                                                            </div>
                                                        )}
                                                        <div className="flex-1" />
                                                        <div className="flex gap-2">
                                                            {['Overview', 'Assets', DELIVERABLES_PAGE_LABEL_SINGULAR + 's'].map((l) => (
                                                                <span key={l} className="text-[8px] text-gray-400 font-medium">{l}</span>
                                                            ))}
                                                        </div>
                                                    </div>
                                                    <div className="flex" style={{ minHeight: 190 }}>
                                                        {/* Sidebar */}
                                                        <aside
                                                            className="w-[52px] flex flex-col flex-shrink-0"
                                                            style={{ backgroundColor: sidebarColor, color: sidebarTextColor }}
                                                        >
                                                            <nav className="flex-1 py-2 space-y-0.5">
                                                                {['All', 'Logos', 'Photos', 'Graphics'].map((label, idx) => (
                                                                    <div key={label} className={`px-2 py-1 text-[8px] font-medium truncate ${idx === 0 ? 'opacity-100' : 'opacity-60'}`} style={{ color: 'inherit' }}>
                                                                        {label}
                                                                    </div>
                                                                ))}
                                                            </nav>
                                                        </aside>
                                                        {/* Main content */}
                                                        <main className="flex-1 flex flex-col bg-[#f8f9fa] min-w-0">
                                                            <div className="flex items-center gap-2 px-3 py-1.5 flex-shrink-0">
                                                                <span
                                                                    className="px-2 py-0.5 rounded text-[9px] font-medium text-white"
                                                                    style={{ backgroundColor: btnColor }}
                                                                >
                                                                    Add Asset
                                                                </span>
                                                                <div className="flex-1" />
                                                                <div className="h-5 bg-white border border-gray-200 rounded w-full max-w-[100px]" />
                                                            </div>
                                                            <div className="flex-1 px-3 pb-2 overflow-hidden">
                                                                <div className="grid grid-cols-4 gap-1.5">
                                                                    {[1, 2, 3, 4, 5, 6, 7, 8].map((i) => (
                                                                        <div key={i} className="aspect-square bg-white border border-gray-100 rounded" />
                                                                    ))}
                                                                </div>
                                                            </div>
                                                        </main>
                                                    </div>
                                                </div>
                                            )
                                        })()}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    )}

                    {/* Categories Section - HIDDEN: Will be re-homed later */}
                    {SHOW_CATEGORIES_AND_METADATA && (
                    <div id="categories" className="scroll-mt-8 grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div className="lg:col-span-1">
                            <div className="flex items-center justify-between">
                                <h3 className="text-base font-semibold leading-6 text-gray-900">Categories</h3>
                                {((categories && categories.some(cat => cat.upgrade_available && cat.is_system)) || 
                                  (categories && categories.some(cat => cat.deletion_available && cat.is_system))) && (
                                    <>
                                        {categories && categories.some(cat => cat.upgrade_available && cat.is_system) && (
                                            <span className="inline-flex items-center rounded-md px-2.5 py-1.5 text-xs font-medium ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-600/20">
                                                Update available
                                            </span>
                                        )}
                                        {categories && categories.some(cat => cat.deletion_available && cat.is_system) && (
                                            <span className="inline-flex items-center rounded-md px-2.5 py-1.5 text-xs font-medium ring-1 ring-inset bg-red-50 text-red-700 ring-red-600/20">
                                                Deletion required
                                            </span>
                                        )}
                                    </>
                                )}
                            </div>
                            <p className="mt-2 text-sm text-gray-500">
                                Manage categories for this brand. Categories are brand-specific and help organize your assets.
                            </p>
                        </div>
                        <div className="lg:col-span-2">
                            <div className="overflow-hidden bg-white shadow sm:rounded-lg">
                                <div className="px-4 py-5 sm:p-6">
                                    <div className="flex items-center justify-end mb-4">
                                        {category_limits && category_limits.can_create && !showCreateCategoryForm && (
                                            <button
                                                type="button"
                                                onClick={() => setShowCreateCategoryForm(true)}
                                                className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                            >
                                                <svg className="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                                </svg>
                                                Add Category
                                            </button>
                                        )}
                                    </div>

                                    {/* Create Category Form */}
                                    {showCreateCategoryForm && category_limits && category_limits.can_create && (
                                        <div className="mb-6 rounded-lg border border-gray-200 bg-gray-50 shadow-sm">
                                            <div className="px-4 py-5 sm:p-6">
                                                <div className="space-y-4">
                                                    <div>
                                                        <label htmlFor="category_name" className="block text-sm font-medium text-gray-700">
                                                            Name
                                                        </label>
                                                        <input
                                                            type="text"
                                                            id="category_name"
                                                            required
                                                            value={categoryFormData.name}
                                                            onChange={(e) => setCategoryFormData('name', e.target.value)}
                                                            className="mt-1 block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                            placeholder="Enter category name"
                                                            autoFocus
                                                        />
                                                    </div>
                                                    <div>
                                                        <label htmlFor="category_icon" className="block text-sm font-medium text-gray-700 mb-2">
                                                            Icon
                                                        </label>
                                                        <div className="relative">
                                                            <CategoryIconSelector
                                                                value={categoryFormData.icon}
                                                                onChange={(icon) => setCategoryFormData('icon', icon)}
                                                            />
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label htmlFor="category_asset_type" className="block text-sm font-medium text-gray-700">
                                                            Category type:
                                                        </label>
                                                        <select
                                                            id="category_asset_type"
                                                            required
                                                            value={categoryFormData.asset_type}
                                                            onChange={(e) => setCategoryFormData('asset_type', e.target.value)}
                                                            className="mt-1 block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                        >
                                                            <option value="asset">Asset</option>
                                                            <option value="deliverable">{DELIVERABLES_PAGE_LABEL_SINGULAR}</option>
                                                        </select>
                                                    </div>
                                                    <div className="space-y-4">
                                                        <div className="flex items-center">
                                                            <input
                                                                id="category_is_private"
                                                                type="checkbox"
                                                                checked={categoryFormData.is_private}
                                                                onChange={(e) => {
                                                                    setCategoryFormData('is_private', e.target.checked)
                                                                    if (!e.target.checked) {
                                                                        setCategoryFormData('access_rules', [])
                                                                    }
                                                                }}
                                                                disabled={!private_category_limits?.plan_allows}
                                                                className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                                                            />
                                                            <label htmlFor="category_is_private" className="ml-2 block text-sm text-gray-900">
                                                                Restrict access to this category
                                                            </label>
                                                        </div>
                                                        {!private_category_limits?.plan_allows && (
                                                            <p className="text-xs text-gray-500 ml-6">
                                                                Private categories require Pro or Enterprise plan.
                                                            </p>
                                                        )}
                                                        {categoryFormData.is_private && private_category_limits?.plan_allows && (
                                                            <div className="ml-6 space-y-4 border-l-2 border-indigo-200 pl-4">
                                                                {private_category_limits && !private_category_limits.can_create && (
                                                                    <div className="rounded-md bg-amber-50 p-3 border border-amber-200">
                                                                        <p className="text-sm text-amber-800">
                                                                            Private category limit reached ({private_category_limits.current} / {private_category_limits.max}). 
                                                                            {private_category_limits.max < 10 ? ' Upgrade to Enterprise for more.' : ''}
                                                                        </p>
                                                                    </div>
                                                                )}
                                                                {(() => {
                                                                    const hasRoleRules = categoryFormData.access_rules?.some(r => r.type === 'role')
                                                                    const hasUserRules = categoryFormData.access_rules?.some(r => r.type === 'user')
                                                                    return (
                                                                        <>
                                                                            <div>
                                                                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                                                                    Brand Roles
                                                                                </label>
                                                                                <div className="space-y-2">
                                                                                    {(brand_roles || []).map((role) => {
                                                                                        const isSelected = categoryFormData.access_rules?.some(r => r.type === 'role' && r.role === role)
                                                                                        return (
                                                                                            <label key={role} className="flex items-center">
                                                                                                <input
                                                                                                    type="checkbox"
                                                                                                    checked={isSelected}
                                                                                                    disabled={hasUserRules}
                                                                                                    onChange={(e) => {
                                                                                                        const currentRules = categoryFormData.access_rules || []
                                                                                                        if (e.target.checked) {
                                                                                                            // Clear user rules if selecting a role
                                                                                                            const roleRules = currentRules.filter(r => r.type === 'role')
                                                                                                            setCategoryFormData('access_rules', [...roleRules, { type: 'role', role }])
                                                                                                        } else {
                                                                                                            setCategoryFormData('access_rules', currentRules.filter(r => !(r.type === 'role' && r.role === role)))
                                                                                                        }
                                                                                                    }}
                                                                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                                                />
                                                                                                <span className="ml-2 text-sm text-gray-700 capitalize">{role}</span>
                                                                                            </label>
                                                                                        )
                                                                                    })}
                                                                                    {(!brand_roles || brand_roles.length === 0) && (
                                                                                        <p className="text-xs text-gray-500">No brand roles available</p>
                                                                                    )}
                                                                                </div>
                                                                            </div>
                                                                            <div>
                                                                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                                                                    Individual Users
                                                                                </label>
                                                                                <div className="space-y-2 max-h-48 overflow-y-auto">
                                                                                    {(brand_users || []).map((user) => {
                                                                                        const isSelected = categoryFormData.access_rules?.some(r => r.type === 'user' && r.user_id === user.id)
                                                                                        return (
                                                                                            <label key={user.id} className="flex items-center">
                                                                                                <input
                                                                                                    type="checkbox"
                                                                                                    checked={isSelected}
                                                                                                    disabled={hasRoleRules}
                                                                                                    onChange={(e) => {
                                                                                                        const currentRules = categoryFormData.access_rules || []
                                                                                                        if (e.target.checked) {
                                                                                                            // Clear role rules if selecting a user
                                                                                                            const userRules = currentRules.filter(r => r.type === 'user')
                                                                                                            setCategoryFormData('access_rules', [...userRules, { type: 'user', user_id: user.id }])
                                                                                                        } else {
                                                                                                            setCategoryFormData('access_rules', currentRules.filter(r => !(r.type === 'user' && r.user_id === user.id)))
                                                                                                        }
                                                                                                    }}
                                                                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                                                />
                                                                                                <span className="ml-2 text-sm text-gray-700">{user.name} ({user.email})</span>
                                                                                            </label>
                                                                                        )
                                                                                    })}
                                                                                    {(!brand_users || brand_users.length === 0) && (
                                                                                        <p className="text-xs text-gray-500">No users available</p>
                                                                                    )}
                                                                                </div>
                                                                            </div>
                                                                            {(hasRoleRules && hasUserRules) && (
                                                                                <p className="text-xs text-amber-600">You can select either roles or users, but not both.</p>
                                                                            )}
                                                                            {categoryFormData.is_private && (!categoryFormData.access_rules || categoryFormData.access_rules.length === 0) && (
                                                                                <p className="text-xs text-amber-600">At least one role or user must be selected for private categories.</p>
                                                                            )}
                                                                        </>
                                                                    )
                                                                })()}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="mt-5 flex items-center justify-end gap-3">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setShowCreateCategoryForm(false)
                                                            resetCategoryForm()
                                                        }}
                                                        className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                    >
                                                        Cancel
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={handleCreateCategory}
                                                        disabled={creatingCategory || !categoryFormData.name.trim() || (categoryFormData.is_private && (!categoryFormData.access_rules || categoryFormData.access_rules.length === 0))}
                                                        className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        {creatingCategory ? 'Creating...' : 'Create Category'}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {category_limits && !category_limits.can_create && (
                                <PlanLimitCallout
                                    title="Category limit reached"
                                    message={`You have reached the maximum number of custom categories (${category_limits.current} of ${category_limits.max === Number.MAX_SAFE_INTEGER || category_limits.max === 2147483647 ? 'unlimited' : category_limits.max}) for your plan. Please upgrade your plan to create more categories.`}
                                />
                            )}

                                    {category_limits && category_limits.can_create && (
                                        <div className="mb-4 text-sm text-gray-600">
                                            Custom categories: {category_limits.current} / {category_limits.max === Number.MAX_SAFE_INTEGER || category_limits.max === 2147483647 ? 'Unlimited' : category_limits.max}
                                        </div>
                                    )}

                                    {categories && categories.length > 0 ? (
                                <div>
                                    {/* Tab Navigation */}
                                    <div className="mb-4 border-b border-gray-200">
                                        <nav className="-mb-px flex space-x-8" aria-label="Tabs">
                                            <button
                                                type="button"
                                                onClick={() => setActiveCategoryTab('asset')}
                                                className={`
                                                    group inline-flex items-center border-b-2 py-3 px-1 text-sm font-medium transition-colors
                                                    ${activeCategoryTab === 'asset'
                                                        ? 'border-indigo-500 text-indigo-600'
                                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                                    }
                                                `}
                                            >
                                                <svg
                                                    className={`
                                                        -ml-0.5 mr-2 h-5 w-5
                                                        ${activeCategoryTab === 'asset' ? 'text-indigo-500' : 'text-gray-400 group-hover:text-gray-500'}
                                                    `}
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    strokeWidth="1.5"
                                                    stroke="currentColor"
                                                >
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                                                </svg>
                                                Asset
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setActiveCategoryTab('deliverable')}
                                                className={`
                                                    group inline-flex items-center border-b-2 py-3 px-1 text-sm font-medium transition-colors
                                                    ${activeCategoryTab === 'deliverable'
                                                        ? 'border-indigo-500 text-indigo-600'
                                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                                    }
                                                `}
                                            >
                                                <svg
                                                    className={`
                                                        -ml-0.5 mr-2 h-5 w-5
                                                        ${activeCategoryTab === 'deliverable' ? 'text-indigo-500' : 'text-gray-400 group-hover:text-gray-500'}
                                                    `}
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    strokeWidth="1.5"
                                                    stroke="currentColor"
                                                >
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                                                </svg>
                                                {DELIVERABLES_PAGE_LABEL_SINGULAR}
                                            </button>
                                        </nav>
                                    </div>
                                    {/* Categories List */}
                                    <div className={`bg-white rounded-lg border border-gray-200 ${editingCategoryId ? 'overflow-visible' : 'overflow-hidden'}`}>
                                        <div className="divide-y divide-gray-200">
                                            {categories
                                                .filter(cat => cat.asset_type === activeCategoryTab)
                                                .map((category) => (
                                                    <CategoryCard
                                                        key={category.id}
                                                        category={category}
                                                        brandId={brand.id}
                                                        brand_users={brand_users}
                                                        brand_roles={brand_roles}
                                                        private_category_limits={private_category_limits}
                                                        can_edit_system_categories={can_edit_system_categories}
                                                        editingId={editingCategoryId}
                                                        setEditingId={setEditingCategoryId}
                                                        onUpgradeClick={(cat) => {
                                                            setSelectedCategoryForUpgrade(cat)
                                                            setUpgradeModalOpen(true)
                                                        }}
                                                        onEditSave={(categoryId, updateData) => {
                                                            router.put(`/app/brands/${brand.id}/categories/${categoryId}`, updateData, {
                                                                preserveScroll: true,
                                                                onSuccess: () => {
                                                                    setEditingCategoryId(null)
                                                                    router.reload({ preserveScroll: true })
                                                                },
                                                            })
                                                        }}
                                                        onEditCancel={() => {
                                                            setEditingCategoryId(null)
                                                        }}
                                                    />
                                                ))}
                                            
                                            {/* Available System Category Templates */}
                                            {(available_system_templates || [])
                                                .filter(template => template.asset_type === activeCategoryTab)
                                                .map((template) => (
                                                    <div key={`template-${template.system_category_id}`} className="px-6 py-4 hover:bg-gray-50 relative bg-blue-50/30">
                                                        <div className="flex items-center justify-between">
                                                            <div className="flex items-center flex-1 min-w-0">
                                                                {/* Category Icon */}
                                                                <div className="mr-3 flex-shrink-0">
                                                                    <CategoryIcon 
                                                                        iconId={template.icon || 'folder'} 
                                                                        className="h-5 w-5" 
                                                                        color="text-gray-400"
                                                                    />
                                                                </div>

                                                                <div className="flex-1 min-w-0">
                                                                    <div className="flex items-center gap-2 flex-wrap">
                                                                        <p className="text-sm font-medium text-gray-900 truncate">
                                                                            {template.name}
                                                                        </p>
                                                                        <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-blue-50 text-blue-700 ring-blue-600/20">
                                                                            System
                                                                        </span>
                                                                        <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-green-50 text-green-700 ring-green-600/20">
                                                                            New
                                                                        </span>
                                                                        {template.is_private && (
                                                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-indigo-100 text-indigo-800 ring-indigo-600/20">
                                                                                Private
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                    <p className="text-sm text-gray-500 truncate">
                                                                        {template.slug} (v{template.system_version})
                                                                    </p>
                                                                </div>
                                                            </div>
                                                            <div className="flex items-center gap-2 ml-4">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        router.post(`/app/brands/${brand.id}/categories/add-system-template`, {
                                                                            system_category_id: template.system_category_id,
                                                                        }, {
                                                                            preserveScroll: true,
                                                                            onSuccess: () => {
                                                                                router.reload({ preserveScroll: true })
                                                                            },
                                                                        })
                                                                    }}
                                                                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                                                    >
                                                                        Add Category
                                                                    </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                ))}
                                            
                                            {categories.filter(cat => cat.asset_type === activeCategoryTab).length === 0 && 
                                             (available_system_templates || []).filter(t => t.asset_type === activeCategoryTab).length === 0 && (
                                                <div className="px-6 py-8 text-center text-sm text-gray-500">
                                                    No {activeCategoryTab === 'asset' ? 'Asset' : DELIVERABLES_PAGE_LABEL_SINGULAR} categories yet.
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="text-center py-12 border border-gray-200 rounded-lg">
                                    <svg
                                        className="mx-auto h-12 w-12 text-gray-400"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7a1.994 1.994 0 01-.586-1.414V7a4 4 0 014-4z"
                                        />
                                    </svg>
                                    <h3 className="mt-2 text-sm font-semibold text-gray-900">No categories</h3>
                                    <p className="mt-1 text-sm text-gray-500">
                                        Get started by creating your first category for this brand.
                                    </p>
                                </div>
                            )}
                                </div>
                            </div>
                        </div>
                    </div>
                    )}

                    {errors.error && (
                        <div className="rounded-md bg-red-50 p-4">
                            <p className="text-sm text-red-800">{errors.error}</p>
                        </div>
                    )}

                    {/* Form Actions */}
                    <div className="flex items-center justify-end gap-3 pt-6 border-t border-gray-200">
                        <Link
                            href="/app"
                            className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                        >
                            {processing ? 'Updating...' : 'Update Brand'}
                        </button>
                    </div>
                </form>
                )}
                    </div>{/* end main content */}
                </div>{/* end two-column layout */}
                </div>
                    </main>
                    <AppFooter />
                    {upgradeModalOpen && selectedCategoryForUpgrade && (
                        <CategoryUpgradeModal
                            open={upgradeModalOpen}
                            setOpen={setUpgradeModalOpen}
                            category={selectedCategoryForUpgrade}
                            brandId={brand.id}
                            onUpgradeSuccess={() => {
                                setUpgradeModalOpen(false)
                                setSelectedCategoryForUpgrade(null)
                                router.reload({ preserveScroll: true })
                            }}
                        />
                    )}
                </div>
            )
        }
