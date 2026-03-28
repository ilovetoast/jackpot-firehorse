import { useForm, Link, router, usePage } from '@inertiajs/react'
import { useState, useEffect, useRef, useCallback } from 'react'
import { createPortal } from 'react-dom'
import axios from 'axios'
import AppNav from '../../Components/AppNav'
import { ARCHETYPES } from '../../constants/brandOptions'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import PlanLimitCallout from '../../Components/PlanLimitCallout'
import CategoryUpgradeModal from '../../Components/CategoryUpgradeModal'
import CategoryIconSelector from '../../Components/CategoryIconSelector'
import { CategoryIcon } from '../../Helpers/categoryIcons'
import { getContrastTextColor } from '../../utils/colorUtils'
import { DELIVERABLES_PAGE_LABEL_SINGULAR } from '../../utils/uiLabels'
import BrandIconUnified from '../../Components/BrandIconUnified'
import FontManager from '../../Components/BrandGuidelines/FontManager'
import HeadlineAppearancePicker from '../../Components/BrandGuidelines/HeadlineAppearancePicker'
import VersionPanel from '../../Components/BrandGuidelines/VersionPanel'
import ConfirmDialog from '../../Components/ConfirmDialog'
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

/** Normalize to #RRGGBB for <input type="color">, or null when unset / invalid */
function hexForColorInput(value) {
    if (value == null || typeof value !== 'string') return null
    const s = value.trim()
    if (/^#[0-9A-Fa-f]{6}$/i.test(s)) return s
    if (/^#[0-9A-Fa-f]{3}$/i.test(s)) {
        const r = s[1], g = s[2], b = s[3]
        return `#${r}${r}${g}${g}${b}${b}`.toLowerCase()
    }
    return null
}

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

// ——— JSON Syntax Highlighter ———
function JsonSyntaxHighlighted({ data }) {
    if (data === null || data === undefined) return <span className="text-gray-400 italic">null</span>

    const json = typeof data === 'string' ? data : JSON.stringify(data, null, 2)

    const highlighted = json.replace(
        /("(?:\\.|[^"\\])*")\s*:/g,
        '<span class="text-indigo-600 font-medium">$1</span>:'
    ).replace(
        /:\s*("(?:\\.|[^"\\])*")/g,
        ': <span class="text-emerald-600">$1</span>'
    ).replace(
        /:\s*(\d+\.?\d*)/g,
        ': <span class="text-amber-600">$1</span>'
    ).replace(
        /:\s*(true|false)/g,
        ': <span class="text-blue-600 font-medium">$1</span>'
    ).replace(
        /:\s*(null)/g,
        ': <span class="text-gray-400 italic">$1</span>'
    )

    return (
        <pre
            className="text-xs leading-relaxed text-gray-700 whitespace-pre-wrap break-words font-mono"
            dangerouslySetInnerHTML={{ __html: highlighted }}
        />
    )
}

// ——— Research Data Modal ———
function ResearchDataModal({ open, onClose, title, loading, data, error }) {
    const tabs = data ? Object.keys(data).filter(k => data[k] != null) : []
    const [activeTab, setActiveTabState] = useState(tabs[0] || '')

    useEffect(() => {
        if (data) {
            const keys = Object.keys(data).filter(k => data[k] != null)
            if (keys.length > 0 && !keys.includes(activeTab)) setActiveTabState(keys[0])
        }
    }, [data])

    const tabLabel = (key) => key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

    if (!open) return null

    return createPortal(
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={onClose}>
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" />
            <div
                className="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[85vh] flex flex-col overflow-hidden"
                onClick={e => e.stopPropagation()}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <h3 className="text-base font-semibold text-gray-900">{title}</h3>
                    <button type="button" onClick={onClose} className="p-1.5 rounded-lg hover:bg-gray-100 transition text-gray-400 hover:text-gray-600">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                {/* Content */}
                {loading && (
                    <div className="flex-1 flex items-center justify-center py-20">
                        <div className="flex flex-col items-center gap-3">
                            <div className="animate-spin rounded-full h-8 w-8 border-2 border-indigo-500 border-t-transparent" />
                            <span className="text-sm text-gray-500">Loading data…</span>
                        </div>
                    </div>
                )}

                {error && (
                    <div className="flex-1 flex items-center justify-center py-20">
                        <div className="text-center">
                            <p className="text-sm text-red-600">{error}</p>
                        </div>
                    </div>
                )}

                {!loading && !error && data && (
                    <>
                        {/* Tabs */}
                        {tabs.length > 1 && (
                            <div className="flex gap-1 px-6 pt-3 pb-0 overflow-x-auto">
                                {tabs.map(key => (
                                    <button
                                        type="button"
                                        key={key}
                                        onClick={() => setActiveTabState(key)}
                                        className={`px-3 py-1.5 rounded-md text-xs font-medium whitespace-nowrap transition ${
                                            activeTab === key
                                                ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
                                                : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50'
                                        }`}
                                    >
                                        {tabLabel(key)}
                                    </button>
                                ))}
                            </div>
                        )}

                        {/* JSON */}
                        <div className="flex-1 overflow-auto px-6 py-4">
                            <div className="bg-gray-50 rounded-lg border border-gray-200 p-4 overflow-auto max-h-[60vh]">
                                <JsonSyntaxHighlighted data={data[activeTab]} />
                            </div>
                        </div>
                    </>
                )}

                {!loading && !error && !data && (
                    <div className="flex-1 flex items-center justify-center py-20">
                        <p className="text-sm text-gray-400">No data available for this item.</p>
                    </div>
                )}
            </div>
        </div>,
        document.body
    )
}

// ——— Research Insights Panel ———
function ResearchInsightsPanel({ insights, brandId }) {
    const [modalOpen, setModalOpen] = useState(false)
    const [modalTitle, setModalTitle] = useState('')
    const [modalLoading, setModalLoading] = useState(false)
    const [modalData, setModalData] = useState(null)
    const [modalError, setModalError] = useState(null)

    if (!insights) return (
        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden px-6 py-10 sm:px-10 sm:py-12 text-center">
            <p className="text-gray-400 text-sm">No research data yet. Run the Brand Guidelines Builder or Research page to generate insights.</p>
            <Link href={`/app/brands/${brandId}/research`} className="mt-3 inline-flex items-center text-sm font-medium text-indigo-600 hover:text-indigo-500">
                Go to Research →
            </Link>
        </div>
    )

    const { runs = [], snapshots = [], latest_snapshot_data } = insights

    const statusBadge = (status) => {
        const map = {
            completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            processing: 'bg-blue-50 text-blue-700 ring-blue-600/20',
            running: 'bg-blue-50 text-blue-700 ring-blue-600/20',
            pending: 'bg-gray-50 text-gray-600 ring-gray-500/10',
            failed: 'bg-red-50 text-red-700 ring-red-600/10',
        }
        return (
            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${map[status] || map.pending}`}>
                {status}
            </span>
        )
    }

    const formatTime = (iso) => {
        if (!iso) return '—'
        const d = new Date(iso)
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
    }

    const formatDuration = (seconds) => {
        if (!seconds) return '—'
        if (seconds < 60) return `${seconds}s`
        const m = Math.floor(seconds / 60)
        const s = seconds % 60
        return `${m}m ${s}s`
    }

    const openRunDetail = async (run) => {
        setModalTitle(`Pipeline Run #${run.id} — ${run.has_asset ? 'PDF Extraction' : 'Website Analysis'}`)
        setModalData(null)
        setModalError(null)
        setModalLoading(true)
        setModalOpen(true)
        try {
            const { data } = await axios.get(`/app/brands/${brandId}/brand-dna/builder/brand-pipeline/${run.id}/detail`)
            const sections = {}
            if (data.merged_extraction) sections.merged_extraction = data.merged_extraction
            if (data.raw_api_response) sections.raw_api_response = data.raw_api_response
            sections.run_metadata = {
                id: data.id,
                status: data.status,
                stage: data.stage,
                extraction_mode: data.extraction_mode,
                pages_total: data.pages_total,
                pages_processed: data.pages_processed,
                error_message: data.error_message,
                created_at: data.created_at,
                completed_at: data.completed_at,
            }
            setModalData(Object.keys(sections).length > 0 ? sections : null)
        } catch (err) {
            setModalError(err?.response?.data?.message || 'Failed to load run data.')
        } finally {
            setModalLoading(false)
        }
    }

    const openSnapshotDetail = async (snap) => {
        setModalTitle(`Snapshot — ${snap.source_url || `#${snap.id}`}`)
        setModalData(null)
        setModalError(null)
        setModalLoading(true)
        setModalOpen(true)
        try {
            const { data } = await axios.get(`/app/brands/${brandId}/brand-dna/builder/brand-pipeline-snapshot/${snap.id}/detail`)
            const sections = {}
            if (data.snapshot) sections.snapshot = data.snapshot
            if (data.suggestions) sections.suggestions = data.suggestions
            if (data.coherence) sections.coherence = data.coherence
            if (data.alignment) sections.alignment = data.alignment
            sections.metadata = {
                id: data.id,
                status: data.status,
                source_url: data.source_url,
                created_at: data.created_at,
            }
            setModalData(Object.keys(sections).length > 0 ? sections : null)
        } catch (err) {
            setModalError(err?.response?.data?.message || 'Failed to load snapshot data.')
        } finally {
            setModalLoading(false)
        }
    }

    const closeModal = () => {
        setModalOpen(false)
        setModalData(null)
        setModalError(null)
    }

    return (
        <div className="space-y-6">
            <ResearchDataModal
                open={modalOpen}
                onClose={closeModal}
                title={modalTitle}
                loading={modalLoading}
                data={modalData}
                error={modalError}
            />

            {/* Latest Extracted Data */}
            {latest_snapshot_data && (
                <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                    <div className="px-6 py-5 sm:px-8 border-b border-gray-100">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="text-lg font-semibold text-gray-900">Extracted Insights</h2>
                                <p className="mt-0.5 text-xs text-gray-500">
                                    Latest completed research
                                    {latest_snapshot_data.source_url && <> from <span className="font-medium text-gray-700">{latest_snapshot_data.source_url}</span></>}
                                    {latest_snapshot_data.created_at && <> · {formatTime(latest_snapshot_data.created_at)}</>}
                                </p>
                            </div>
                            {latest_snapshot_data.coherence_score != null && (
                                <div className="text-right">
                                    <p className="text-[10px] uppercase tracking-wider text-gray-400 font-medium">Coherence</p>
                                    <p className="text-2xl font-bold text-indigo-600">{Math.round(latest_snapshot_data.coherence_score)}%</p>
                                </div>
                            )}
                        </div>
                    </div>
                    <div className="px-6 py-5 sm:px-8 space-y-5">
                        {/* Identity fields */}
                        <div className="grid grid-cols-2 gap-4">
                            {[
                                { label: 'Mission', value: latest_snapshot_data.mission },
                                { label: 'Vision', value: latest_snapshot_data.vision },
                                { label: 'Tagline', value: latest_snapshot_data.tagline },
                                { label: 'Industry', value: latest_snapshot_data.industry },
                                { label: 'Target Audience', value: latest_snapshot_data.target_audience },
                                { label: 'Positioning', value: latest_snapshot_data.positioning },
                            ].filter((f) => f.value).map(({ label, value }) => (
                                <div key={label} className="col-span-2 sm:col-span-1">
                                    <p className="text-[10px] uppercase tracking-wider text-gray-400 font-medium mb-1">{label}</p>
                                    <p className="text-sm text-gray-800 leading-relaxed">{typeof value === 'string' ? value.slice(0, 200) : JSON.stringify(value)}</p>
                                </div>
                            ))}
                        </div>

                        {/* Brand bio */}
                        {latest_snapshot_data.brand_bio && (
                            <div>
                                <p className="text-[10px] uppercase tracking-wider text-gray-400 font-medium mb-1">Brand Bio</p>
                                <p className="text-sm text-gray-700 leading-relaxed bg-gray-50 rounded-lg p-3">{latest_snapshot_data.brand_bio.slice(0, 500)}</p>
                            </div>
                        )}

                        {/* Voice & Look */}
                        {(latest_snapshot_data.voice_description || latest_snapshot_data.brand_look) && (
                            <div className="grid grid-cols-2 gap-4">
                                {latest_snapshot_data.voice_description && (
                                    <div>
                                        <p className="text-[10px] uppercase tracking-wider text-gray-400 font-medium mb-1">Voice</p>
                                        <p className="text-sm text-gray-700">{latest_snapshot_data.voice_description.slice(0, 200)}</p>
                                    </div>
                                )}
                                {latest_snapshot_data.brand_look && (
                                    <div>
                                        <p className="text-[10px] uppercase tracking-wider text-gray-400 font-medium mb-1">Brand Look</p>
                                        <p className="text-sm text-gray-700">{latest_snapshot_data.brand_look.slice(0, 200)}</p>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Visual: Colors */}
                        {(latest_snapshot_data.primary_colors?.length > 0 || latest_snapshot_data.secondary_colors?.length > 0) && (
                            <div>
                                <p className="text-[10px] uppercase tracking-wider text-gray-400 font-medium mb-2">Detected Colors</p>
                                <div className="flex flex-wrap gap-2">
                                    {[...(latest_snapshot_data.primary_colors || []), ...(latest_snapshot_data.secondary_colors || [])].map((c, i) => {
                                        const hex = typeof c === 'string' ? c : c?.hex || ''
                                        return (
                                            <div key={i} className="flex items-center gap-1.5 px-2 py-1 rounded-md bg-gray-50 border border-gray-200">
                                                <div className="w-4 h-4 rounded border border-gray-300" style={{ backgroundColor: hex }} />
                                                <span className="text-xs font-mono text-gray-600">{hex}</span>
                                            </div>
                                        )
                                    })}
                                </div>
                            </div>
                        )}

                        {/* Visual: Fonts */}
                        {latest_snapshot_data.detected_fonts?.length > 0 && (
                            <div>
                                <p className="text-[10px] uppercase tracking-wider text-gray-400 font-medium mb-2">Detected Fonts</p>
                                <div className="flex flex-wrap gap-2">
                                    {latest_snapshot_data.detected_fonts.map((f, i) => (
                                        <span key={i} className="px-2.5 py-1 rounded-md bg-gray-50 border border-gray-200 text-xs font-medium text-gray-700">{f}</span>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Headlines */}
                        {latest_snapshot_data.hero_headlines?.length > 0 && (
                            <div>
                                <p className="text-[10px] uppercase tracking-wider text-gray-400 font-medium mb-2">Hero Headlines</p>
                                <div className="space-y-1">
                                    {latest_snapshot_data.hero_headlines.map((h, i) => (
                                        <p key={i} className="text-sm text-gray-600 pl-3 border-l-2 border-gray-200">{h}</p>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Coherence strengths / risks */}
                        {(latest_snapshot_data.coherence_strengths?.length > 0 || latest_snapshot_data.coherence_risks?.length > 0) && (
                            <div className="grid grid-cols-2 gap-4 pt-2 border-t border-gray-100">
                                {latest_snapshot_data.coherence_strengths?.length > 0 && (
                                    <div>
                                        <p className="text-[10px] uppercase tracking-wider text-emerald-500 font-medium mb-2">Strengths</p>
                                        <ul className="space-y-1">
                                            {latest_snapshot_data.coherence_strengths.map((s, i) => (
                                                <li key={i} className="text-xs text-gray-600 flex items-start gap-1.5">
                                                    <span className="w-1 h-1 rounded-full bg-emerald-400 mt-1.5 flex-shrink-0" />
                                                    {s}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                                {latest_snapshot_data.coherence_risks?.length > 0 && (
                                    <div>
                                        <p className="text-[10px] uppercase tracking-wider text-amber-500 font-medium mb-2">Risks</p>
                                        <ul className="space-y-1">
                                            {latest_snapshot_data.coherence_risks.map((r, i) => (
                                                <li key={i} className="text-xs text-gray-600 flex items-start gap-1.5">
                                                    <span className="w-1 h-1 rounded-full bg-amber-400 mt-1.5 flex-shrink-0" />
                                                    {r}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Pipeline Runs */}
            <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                <div className="px-6 py-5 sm:px-8 border-b border-gray-100">
                    <h2 className="text-lg font-semibold text-gray-900">Pipeline Runs</h2>
                    <p className="mt-0.5 text-xs text-gray-500">History of PDF extractions and website analyses</p>
                </div>
                {runs.length === 0 ? (
                    <div className="px-6 py-8 sm:px-8 text-center">
                        <p className="text-sm text-gray-400">No pipeline runs yet</p>
                    </div>
                ) : (
                    <div className="divide-y divide-gray-100">
                        {runs.map((run) => (
                            <button
                                key={run.id}
                                type="button"
                                onClick={() => openRunDetail(run)}
                                className="w-full text-left px-6 sm:px-8 py-4 flex items-center gap-4 hover:bg-gray-50/80 transition cursor-pointer group"
                            >
                                <div className="flex-shrink-0">
                                    {run.has_asset ? (
                                        <div className="w-8 h-8 rounded-lg bg-red-50 flex items-center justify-center" title="PDF Extraction">
                                            <svg className="w-4 h-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                        </div>
                                    ) : (
                                        <div className="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center" title="Website Crawl">
                                            <svg className="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" /></svg>
                                        </div>
                                    )}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium text-gray-800">
                                            {run.has_asset ? 'PDF Extraction' : 'Website Analysis'}
                                        </span>
                                        {statusBadge(run.status)}
                                        {run.extraction_mode === 'vision' && (
                                            <span className="text-[10px] px-1.5 py-0.5 rounded bg-purple-50 text-purple-600 font-medium ring-1 ring-purple-500/10">Vision</span>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-3 mt-0.5 text-xs text-gray-500">
                                        <span>{formatTime(run.created_at)}</span>
                                        {run.duration_seconds && <span>· {formatDuration(run.duration_seconds)}</span>}
                                        {run.pages_total > 0 && <span>· {run.pages_processed}/{run.pages_total} pages</span>}
                                    </div>
                                    {run.error_message && run.status === 'failed' && (
                                        <p className="mt-1 text-xs text-red-600 bg-red-50 rounded px-2 py-1">{run.error_message.slice(0, 200)}</p>
                                    )}
                                </div>
                                <div className="flex-shrink-0 opacity-0 group-hover:opacity-100 transition">
                                    <svg className="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M17.25 8.25L21 12m0 0l-3.75 3.75M21 12H3" /></svg>
                                </div>
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {/* Website Snapshots */}
            {snapshots.length > 0 && (
                <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                    <div className="px-6 py-5 sm:px-8 border-b border-gray-100">
                        <h2 className="text-lg font-semibold text-gray-900">Website Research Snapshots</h2>
                        <p className="mt-0.5 text-xs text-gray-500">Results from website crawls and AI analysis</p>
                    </div>
                    <div className="divide-y divide-gray-100">
                        {snapshots.map((snap) => (
                            <button
                                key={snap.id}
                                type="button"
                                onClick={() => openSnapshotDetail(snap)}
                                className="w-full text-left px-6 sm:px-8 py-4 flex items-center gap-4 hover:bg-gray-50/80 transition cursor-pointer group"
                            >
                                <div className="flex-shrink-0 w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center">
                                    <svg className="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium text-gray-800 truncate">{snap.source_url || 'ingestion'}</span>
                                        {statusBadge(snap.status)}
                                    </div>
                                    <div className="flex items-center gap-3 mt-0.5 text-xs text-gray-500">
                                        <span>{formatTime(snap.created_at)}</span>
                                        {snap.suggestion_count > 0 && <span>· {snap.suggestion_count} suggestions</span>}
                                        {snap.coherence_score != null && <span>· Coherence: {Math.round(snap.coherence_score)}%</span>}
                                    </div>
                                </div>
                                <div className="flex-shrink-0 opacity-0 group-hover:opacity-100 transition">
                                    <svg className="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M17.25 8.25L21 12m0 0l-3.75 3.75M21 12H3" /></svg>
                                </div>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* Link to full research page */}
            <div className="text-center pt-2">
                <Link
                    href={`/app/brands/${brandId}/research`}
                    className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-500"
                >
                    Open Full Research Page
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                </Link>
            </div>
        </div>
    )
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
            headline_treatment: typography.headline_treatment || null,
            headline_appearance_features: Array.isArray(typography.headline_appearance_features) ? typography.headline_appearance_features : [],
            body_style: typography.body_style || null,
            fonts: Array.isArray(typography.fonts) ? typography.fonts : [],
            external_font_links: Array.isArray(typography.external_font_links) ? typography.external_font_links : [],
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
            auto_generate_logo_on_dark: visual.auto_generate_logo_on_dark === true,
            auto_generate_logo_on_light: visual.auto_generate_logo_on_light === true,
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
        presentation: {
            style: payload.presentation?.style || 'clean',
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
    typography.headline_treatment = form.standards?.headline_treatment ?? typography.headline_treatment
    if (form.standards?.headline_appearance_features !== undefined) {
        typography.headline_appearance_features = Array.isArray(form.standards.headline_appearance_features)
            ? form.standards.headline_appearance_features
            : []
    }
    typography.body_style = form.standards?.body_style ?? typography.body_style
    if (form.standards?.fonts !== undefined) typography.fonts = form.standards.fonts
    if (form.standards?.external_font_links !== undefined) typography.external_font_links = form.standards.external_font_links

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
    if (form.standards?.auto_generate_logo_on_dark !== undefined) {
        visual.auto_generate_logo_on_dark = form.standards.auto_generate_logo_on_dark
    }
    if (form.standards?.auto_generate_logo_on_light !== undefined) {
        visual.auto_generate_logo_on_light = form.standards.auto_generate_logo_on_light
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

    const presentation = { ...(existing.presentation || {}) }
    if (form.presentation?.style !== undefined) {
        presentation.style = form.presentation.style
    }

    return {
        ...existing,
        identity,
        personality,
        typography,
        scoring_config: scoringConfig,
        scoring_rules: scoringRules,
        visual,
        visual_references: form.standards?.visual_references ?? existing.visual_references,
        presentation,
    }
}

const REFERENCE_CATEGORIES = [
    { key: 'photography', label: 'Photography', contextCategory: 'photography' },
    { key: 'graphics', label: 'Graphics', contextCategory: 'graphics' },
]

function VisualReferenceCategoryPicker({ brandId, referenceCategories, onChange, noTopDivider = false }) {
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
        <div className={noTopDivider ? '' : 'pt-6 border-t border-gray-200'}>
            <div className="mb-4">
                <h4 className="text-sm font-semibold text-gray-900">Reference categories</h4>
                <p className="text-xs text-gray-500 mt-0.5">Select reference images by category for guidelines and on-brand alignment.</p>
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
                <span className="text-xs text-gray-600">Use <strong>{activeCatDef.label}</strong> for alignment checks</span>
            </label>
        </div>
    )
}

export default function BrandsEdit({ brand, categories, available_system_templates, category_limits, brand_users, brand_roles, available_users, pending_invitations, private_category_limits, can_edit_system_categories, tenant_settings, current_plan, model_payload, brand_model, active_version, all_versions = [], research_insights, compliance_aggregate, top_executions, bottom_executions, portal_settings, portal_features, portal_url }) {
    const { auth, headlineAppearanceCatalog = [] } = usePage().props
    const effectivePermissions = Array.isArray(auth?.effective_permissions) ? auth.effective_permissions : []
    const isFreePlan = current_plan === 'free'
    const can = (p) => effectivePermissions.includes(p)
    const canAccessCategoriesAndFields = can('metadata.registry.view') || can('metadata.tenant.visibility.manage')
    const [activeCategoryTab, setActiveCategoryTab] = useState('asset')
    const DNA_TABS = ['strategy', 'positioning', 'expression', 'standards', 'alignment', 'references', 'presentation', 'research']
    const ALL_TABS = ['identity', 'workspace', 'public-site', ...DNA_TABS, 'members']
    const getInitialTab = () => {
        if (typeof window === 'undefined') return 'identity'
        const params = new URLSearchParams(window.location.search)
        const tabParam = params.get('tab')
        if (tabParam === 'brand-portal') return 'public-site'
        if (tabParam === 'brand_model' || tabParam === 'brand-dna') return 'strategy'
        if (tabParam === 'scoring') return 'alignment'
        if (ALL_TABS.includes(tabParam)) return tabParam
        return 'identity'
    }
    const [activeTab, setActiveTab] = useState(getInitialTab)
    const isDnaTab = DNA_TABS.includes(activeTab)

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
    const [showDiscardDraftConfirm, setShowDiscardDraftConfirm] = useState(false)
    
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
    
    

    const { data, setData, put, processing, errors } = useForm({
        name: brand.name,
        slug: brand.slug,
        logo_id: brand.logo_id ?? null,
        logo_preview: brand.logo_thumbnail_url || brand.logo_path || '',
        clear_logo: false,
        logo_dark_id: brand.logo_dark_id ?? null,
        logo_dark_preview: brand.logo_dark_thumbnail_url || brand.logo_dark_path || '',
        clear_logo_dark: false,
        logo_horizontal_id: brand.logo_horizontal_id ?? null,
        logo_horizontal_preview: brand.logo_horizontal_thumbnail_url || brand.logo_horizontal_path || '',
        clear_logo_horizontal: false,
        icon_bg_color: brand.icon_bg_color || brand.primary_color || '#6366f1',
        icon_style: brand.icon_style || 'subtle',
        show_in_selector: brand.show_in_selector !== undefined ? brand.show_in_selector : true,
        primary_color: brand.primary_color ?? '',
        secondary_color: brand.secondary_color ?? '',
        accent_color: brand.accent_color ?? '',
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

    const [logoVariantGenerating, setLogoVariantGenerating] = useState(false)
    const [logoVariantError, setLogoVariantError] = useState(null)

    /** Deliverables grid as JSON for AssetImagePicker — same session brand as this edit page. */
    const fetchDeliverablesForPicker = useCallback((opts) => {
        const params = new URLSearchParams({ format: 'json' })
        if (opts?.category) params.set('category', opts.category)
        return fetch(`/app/executions?${params}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then((r) => r.json())
    }, [])

    const runLogoVariantGeneration = async (onDark, onLight) => {
        setLogoVariantGenerating(true)
        setLogoVariantError(null)
        try {
            const { data } = await axios.post(`/app/brands/${brand.id}/logo-variants/generate`, {
                on_dark: onDark,
                on_light: onLight,
            })
            if (data.logo_dark_preview_url && data.on_dark_asset_id) {
                setData('logo_dark_id', data.on_dark_asset_id)
                setData('logo_dark_preview', data.logo_dark_preview_url)
                setData('clear_logo_dark', false)
            }
            if (Array.isArray(data.errors) && data.errors.length > 0 && !data.ok) {
                setLogoVariantError(data.errors.join(' '))
            }
        } catch (e) {
            const d = e.response?.data
            const errList = Array.isArray(d?.errors) ? d.errors : []
            setLogoVariantError(errList.join(' ') || d?.message || e.message || 'Generation failed.')
        } finally {
            setLogoVariantGenerating(false)
        }
    }

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
        }
    }, [data.logo_preview])


    return (
        <div className="min-h-screen flex flex-col bg-gray-50">
            <AppHead title="Brand Portal" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="flex-1">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <h1 className="text-2xl font-bold tracking-tight text-gray-900">Brand Settings</h1>
                    <p className="mt-1 text-sm text-gray-600">
                        Manage your brand identity, workspace, public site, and brand DNA.
                    </p>

                    <div className="mt-6 flex flex-wrap items-center gap-4">
                        <div className="border-b border-gray-200 -mb-px" role="tablist" aria-label="Brand Portal sections">
                            <nav className="flex flex-wrap items-center gap-x-6">
                                {[
                                    { id: 'identity', label: 'Identity' },
                                    { id: 'workspace', label: 'Workspace' },
                                    { id: 'public-site', label: 'Public Site' },
                                    { id: 'brand-dna', label: 'Brand DNA', resolvedTab: 'strategy' },
                                    ...(can('team.manage') ? [{ id: 'members', label: 'Team' }] : []),
                                ].map((tab) => {
                                    const isActive = tab.id === 'brand-dna' ? isDnaTab : activeTab === tab.id
                                    return (
                                        <button
                                            key={tab.id}
                                            type="button"
                                            role="tab"
                                            aria-selected={isActive}
                                            onClick={() => { const t = tab.resolvedTab || tab.id; setActiveTab(t); updateTabInUrl(t) }}
                                            className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium ${
                                                isActive ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                            }`}
                                        >
                                            {tab.label}
                                        </button>
                                    )
                                })}
                            </nav>
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
                                href={typeof route === 'function' ? route('insights.metadata') : '/app/insights/metadata'}
                                className="text-sm font-medium text-gray-600 hover:text-gray-900"
                            >
                                Analytics →
                            </Link>
                        )}
                    </div>
                </div>

                <div className={`flex flex-col lg:flex-row gap-8`}>
                    {isDnaTab && (
                        <aside className="lg:w-56 flex-shrink-0">
                            <nav className="sticky top-8 space-y-1" aria-label="Brand DNA sections">
                                {[
                                    { id: 'strategy', label: 'Strategy' },
                                    { id: 'positioning', label: 'Positioning' },
                                    { id: 'expression', label: 'Expression' },
                                    { id: 'standards', label: 'Standards' },
                                    { id: 'alignment', label: 'Alignment' },
                                    { id: 'references', label: 'References' },
                                    { id: 'presentation', label: 'Presentation' },
                                    { id: 'research', label: 'Research' },
                                ].map((item) => (
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
                    )}

                    <div className="flex-1 min-w-0">

                    {/* Upgrade banner for free plan users */}
                    {isDnaTab && isFreePlan && (
                        <div className="mb-6 rounded-xl border border-indigo-200 bg-gradient-to-r from-indigo-50 to-purple-50 p-5">
                            <div className="flex items-start gap-3">
                                <div className="flex-shrink-0 mt-0.5 rounded-lg bg-indigo-100 p-2">
                                    <svg className="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z" />
                                    </svg>
                                </div>
                                <div className="flex-1">
                                    <h3 className="text-sm font-semibold text-indigo-900">You're on the Free plan</h3>
                                    <p className="mt-1 text-sm text-indigo-700/70">
                                        You can manually configure your Brand DNA settings below. Upgrade to unlock the AI-powered Brand Guidelines Builder, automated research, and all presentation styles.
                                    </p>
                                    <div className="mt-3 flex items-center gap-3">
                                        <Link
                                            href={route('billing.index')}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 transition"
                                        >
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
                                            Upgrade Plan
                                        </Link>
                                        <span className="text-xs text-indigo-600/50">Presentation style limited to Clean on the free plan</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {isDnaTab && (() => {
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
                                    {hasActiveVersion && (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                            <svg className="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                            Active: v{active_version.version_number}
                                        </span>
                                    )}
                                </div>

                                {/* Version list with lifecycle-aware CTAs */}
                                {all_versions.length > 0 ? (
                                    <VersionPanel
                                        versions={all_versions}
                                        activeVersionId={active_version?.id}
                                        brandId={brand.id}
                                        selectedVersionId={selectedVersionId}
                                        onSelect={handleVersionSelect}
                                        isFreePlan={isFreePlan}
                                    />
                                ) : (
                                    <p className="text-sm text-gray-500 mb-3">No versions yet.</p>
                                )}

                                {/* New version CTA — only when no draft exists */}
                                {!draftVersion && (
                                    <div className="mt-4 pt-4 border-t border-gray-100">
                                        {isFreePlan ? (
                                            <div className="flex items-center gap-3">
                                                <span className="text-sm text-gray-500">AI-powered builder requires a paid plan.</span>
                                                <Link
                                                    href={route('billing.index')}
                                                    className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                                                >
                                                    Upgrade
                                                </Link>
                                            </div>
                                        ) : (
                                            <Link
                                                href={typeof route === 'function' ? route('brands.research.show', { brand: brand.id }) : `/app/brands/${brand.id}/research`}
                                                className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                                            >
                                                {hasActiveVersion ? 'Start New Version' : 'Start Brand Builder'}
                                            </Link>
                                        )}
                                    </div>
                                )}

                                {draftVersion && !isFreePlan && can('brand_settings.manage') && (
                                    <div className="mt-4 pt-4 border-t border-red-100">
                                        <p className="text-sm text-gray-600 mb-2">
                                            Remove the current in-progress draft (research and builder). Published guidelines are not affected.
                                        </p>
                                        <button
                                            type="button"
                                            onClick={() => setShowDiscardDraftConfirm(true)}
                                            className="text-sm font-medium text-red-600 hover:text-red-700 underline underline-offset-2"
                                        >
                                            Delete in-progress guidelines
                                        </button>
                                        <ConfirmDialog
                                            open={showDiscardDraftConfirm}
                                            onClose={() => setShowDiscardDraftConfirm(false)}
                                            onConfirm={() => {
                                                setShowDiscardDraftConfirm(false)
                                                router.post(
                                                    typeof route === 'function'
                                                        ? route('brands.brand-dna.builder.discard-draft', { brand: brand.id })
                                                        : `/app/brands/${brand.id}/brand-dna/builder/discard-draft`,
                                                    {},
                                                    { preserveScroll: true }
                                                )
                                            }}
                                            title="Delete in-progress guidelines?"
                                            message="This removes the draft version and any unsaved research/builder progress. Your published brand guidelines (if any) stay on the site."
                                            confirmText="Delete draft"
                                            cancelText="Cancel"
                                            variant="danger"
                                        />
                                    </div>
                                )}
                            </div>
                        )
                    })()}

                    {/* Execution Alignment Overview — collapsible */}
                    {isDnaTab && compliance_aggregate && (
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
                ) : (activeTab === 'strategy' || activeTab === 'positioning' || activeTab === 'expression' || activeTab === 'standards' || activeTab === 'alignment' || activeTab === 'references' || activeTab === 'presentation' || activeTab === 'research') ? (
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
                    <div id="standards" className="scroll-mt-8 space-y-8">
                        {/* ——— Typography & Fonts ——— */}
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Typography</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Manage brand fonts — select from Google Fonts, add custom/licensed fonts, or link external stylesheets.
                                    </p>
                                </div>

                                {/* FontManager in dark container */}
                                <div className="mt-6 rounded-xl bg-[#14131a] border border-white/10 p-5">
                                    <FontManager
                                        brandId={brand.id}
                                        fonts={modelPayload.standards?.fonts || []}
                                        onChange={(fonts) => {
                                            setModelPayloadField('standards.fonts', fonts)
                                            const primary = fonts.find((f) => f.role === 'primary' || f.role === 'display')
                                            const secondary = fonts.find((f) => f.role === 'secondary' || f.role === 'body')
                                            if (primary) setModelPayloadField('standards.primary_font', primary.name)
                                            if (secondary) setModelPayloadField('standards.secondary_font', secondary.name)
                                            const fontNames = fonts.map((f) => typeof f === 'string' ? f : f?.name).filter(Boolean)
                                            setModelPayloadField('standards.allowed_fonts', fontNames)
                                        }}
                                    />

                                    {/* External Font URLs */}
                                    <div className="mt-4 pt-4 border-t border-white/10">
                                        <label className="block text-xs text-white/60 mb-1.5">External Font URLs</label>
                                        <div className="space-y-2">
                                            {(modelPayload.standards?.external_font_links || []).map((url, i) => (
                                                <div key={i} className="flex items-center gap-2">
                                                    <input
                                                        type="url"
                                                        value={url}
                                                        onChange={(e) => {
                                                            const links = [...(modelPayload.standards?.external_font_links || [])]
                                                            links[i] = e.target.value
                                                            setModelPayloadField('standards.external_font_links', links)
                                                        }}
                                                        className="flex-1 rounded-lg border border-white/15 bg-white/[0.04] px-3 py-2 text-sm text-white placeholder-white/30 focus:ring-1 focus:ring-white/30 focus:border-white/30"
                                                    />
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            const links = (modelPayload.standards?.external_font_links || []).filter((_, j) => j !== i)
                                                            setModelPayloadField('standards.external_font_links', links)
                                                        }}
                                                        className="p-2 rounded-lg text-white/40 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                                                    >
                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                                    </button>
                                                </div>
                                            ))}
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    const links = [...(modelPayload.standards?.external_font_links || []), '']
                                                    setModelPayloadField('standards.external_font_links', links)
                                                }}
                                                className="flex items-center gap-1.5 text-xs text-white/40 hover:text-white/60 transition-colors"
                                            >
                                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                                Add font CSS URL
                                            </button>
                                        </div>
                                        <p className="mt-1.5 text-[11px] text-white/30">Google Fonts or self-hosted font CSS URLs (HTTPS only).</p>
                                    </div>
                                </div>

                                {/* Font source alerts */}
                                {(() => {
                                    const fonts = modelPayload.standards?.fonts || []
                                    const missingSource = fonts.filter((f) => {
                                        if (typeof f === 'string') return true
                                        return f.source === 'unknown' || (f.source === 'custom' && !f.purchase_url && (!f.file_urls || f.file_urls.length === 0))
                                    })
                                    if (missingSource.length === 0) return null
                                    return (
                                        <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                                            <div className="flex gap-3">
                                                <svg className="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                                                <div>
                                                    <p className="text-sm font-medium text-amber-800">Missing font source files</p>
                                                    <p className="mt-1 text-xs text-amber-700">
                                                        {missingSource.length} font{missingSource.length > 1 ? 's' : ''} ({missingSource.map((f) => typeof f === 'string' ? f : f.name).join(', ')}) {missingSource.length > 1 ? 'have' : 'has'} no source files or license URL.
                                                        Edit the font to add WOFF2/OTF files or a purchase link so team members can access them.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    )
                                })()}

                                {/* Heading / body style overrides */}
                                <div className="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-6">
                                    <div>
                                        <label htmlFor="heading_style" className="block text-sm font-medium text-gray-900">Heading style</label>
                                        <p className="text-xs text-gray-500 mb-1.5">How headings should appear (e.g. "Bold uppercase", "32px semi-bold").</p>
                                        <input type="text" id="heading_style" value={modelPayload.standards?.heading_style ?? ''} onChange={(e) => setModelPayloadField('standards.heading_style', e.target.value || null)} className="block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm" placeholder="e.g. Bold, uppercase, 2rem" />
                                    </div>
                                    <div>
                                        <label htmlFor="body_style" className="block text-sm font-medium text-gray-900">Body style</label>
                                        <p className="text-xs text-gray-500 mb-1.5">How body text should appear (e.g. "Regular 16px/1.6", "Light 14px").</p>
                                        <input type="text" id="body_style" value={modelPayload.standards?.body_style ?? ''} onChange={(e) => setModelPayloadField('standards.body_style', e.target.value || null)} className="block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm" placeholder="e.g. Regular, 16px / 1.6 line height" />
                                    </div>
                                </div>

                                <div className="mt-6 space-y-3">
                                    <div>
                                        <span className="block text-sm font-medium text-gray-900">Headline appearance</span>
                                        <p className="text-xs text-gray-500 mb-2 mt-0.5">
                                            Select patterns that match your guidelines. Add more anytime in{' '}
                                            <code className="text-[11px] bg-gray-100 px-1 rounded">config/headline_appearance.php</code>.
                                        </p>
                                        <HeadlineAppearancePicker
                                            catalog={headlineAppearanceCatalog}
                                            variant="light"
                                            value={modelPayload.standards?.headline_appearance_features ?? []}
                                            onChange={(ids) => setModelPayloadField('standards.headline_appearance_features', ids)}
                                        />
                                    </div>
                                    <div>
                                        <label htmlFor="headline_treatment" className="block text-sm font-medium text-gray-900">Headline treatment notes</label>
                                        <p className="text-xs text-gray-500 mb-1.5">Free-form detail: spacing, do / don&apos;ts, exceptions, or anything not covered by the tags above.</p>
                                        <textarea
                                            id="headline_treatment"
                                            rows={4}
                                            value={modelPayload.standards?.headline_treatment ?? ''}
                                            onChange={(e) => setModelPayloadField('standards.headline_treatment', e.target.value.trim() || null)}
                                            className="block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed"
                                            placeholder="e.g. Leading em dash or accent bar; display type in ALL CAPS; optional pill container on dark backgrounds; hairline rule below…"
                                        />
                                    </div>
                                </div>

                                <div className="mt-6">
                                    <button type="submit" disabled={dnaSaving} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                        {dnaSaving ? 'Saving…' : 'Save Brand DNA'}
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* ——— Colors ——— */}
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Colors</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Define allowed and banned color palettes for brand compliance scoring.
                                    </p>
                                </div>
                                <div className="mt-6 space-y-6">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-900">Allowed colors</label>
                                        <textarea rows={3} value={(modelPayload.standards?.allowed_colors || []).join(', ')} onChange={(e) => setModelPayloadField('standards.allowed_colors', e.target.value.split(/[,\n]/).map((s) => s.trim()).filter(Boolean))} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="Hex codes, comma-separated (e.g. #6366f1, #8b5cf6)" />
                                        {(modelPayload.standards?.allowed_colors || []).length > 0 && (
                                            <div className="flex flex-wrap gap-2 mt-2">
                                                {(modelPayload.standards?.allowed_colors || []).map((c, i) => (
                                                    <div key={i} className="flex items-center gap-1.5 rounded-md border border-gray-200 bg-gray-50 px-2 py-1">
                                                        <span className="w-4 h-4 rounded-sm border border-gray-300" style={{ backgroundColor: c }} />
                                                        <span className="text-xs text-gray-600 font-mono">{c}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-900">Banned colors</label>
                                        <textarea rows={3} value={(modelPayload.standards?.banned_colors || []).join(', ')} onChange={(e) => setModelPayloadField('standards.banned_colors', e.target.value.split(/[,\n]/).map((s) => s.trim()).filter(Boolean))} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="Hex codes, comma-separated" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-900">Allowed fonts (for scoring)</label>
                                        <p className="text-xs text-gray-500 mb-1.5">Auto-populated from the Typography section above. Add additional names if needed.</p>
                                        <textarea rows={3} value={(modelPayload.standards?.allowed_fonts || []).join(', ')} onChange={(e) => setModelPayloadField('standards.allowed_fonts', e.target.value.split(/[,\n]/).map((s) => s.trim()).filter(Boolean))} className="mt-2 block w-full rounded-lg border-gray-300 bg-white px-4 py-3 shadow-sm focus:ring-2 focus:ring-indigo-600 focus:border-indigo-500 text-sm leading-relaxed" placeholder="Comma-separated" />
                                    </div>

                                    <button type="submit" disabled={dnaSaving} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                        {dnaSaving ? 'Saving…' : 'Save Brand DNA'}
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* ——— Logo Variants ——— */}
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Logo Variants</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Logo versions for different contexts. Upload, pick from library or executions, or generate raster variants in{' '}
                                        <button type="button" onClick={() => { setActiveTab('identity'); updateTabInUrl('identity') }} className="text-indigo-600 hover:text-indigo-800 font-medium underline underline-offset-2">Identity &rarr; Brand Images</button>.
                                    </p>
                                </div>
                                <div className="mt-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                    {[
                                        { label: 'Primary', preview: data.logo_preview || brand.logo_path, bg: 'bg-white', desc: 'Light backgrounds' },
                                        { label: 'On Dark', preview: data.logo_dark_preview || brand.logo_dark_path, bg: 'bg-gray-900', desc: 'Dark backgrounds' },
                                        { label: 'Horizontal', preview: data.logo_horizontal_preview || brand.logo_horizontal_path, bg: 'bg-white', desc: 'Wide placements' },
                                    ].map(({ label, preview, bg, desc }) => (
                                        <div key={label} className="rounded-lg border border-gray-200 overflow-hidden">
                                            <div className={`${bg} flex items-center justify-center h-20 p-3`}>
                                                {preview ? (
                                                    <img src={preview} alt={label} className={`max-h-14 max-w-full object-contain ${bg === 'bg-gray-900' ? 'brightness-0 invert' : ''}`} style={bg === 'bg-gray-900' && (data.logo_dark_preview || brand.logo_dark_path) ? { filter: 'none' } : undefined} />
                                                ) : (
                                                    <span className="text-xs text-gray-400">Not set</span>
                                                )}
                                            </div>
                                            <div className="px-3 py-2 bg-gray-50 border-t border-gray-200">
                                                <p className="text-xs font-medium text-gray-700">{label}</p>
                                                <p className="text-[10px] text-gray-500">{desc}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                {!data.logo_preview && !brand.logo_path && (
                                    <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 flex items-start gap-2">
                                        <svg className="w-4 h-4 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                                        <p className="text-xs text-amber-700">No primary logo uploaded. <button type="button" onClick={() => { setActiveTab('identity'); updateTabInUrl('identity') }} className="text-amber-800 font-medium underline underline-offset-2">Upload in Identity</button></p>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* ——— Logo usage ——— (visual reference imagery lives under Brand DNA → References) */}
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Logo usage</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Rules and proofs for how your logo appears in guidelines. Reference images for Brand Intelligence live in{' '}
                                        <button type="button" onClick={() => { setActiveTab('references'); updateTabInUrl('references') }} className="text-indigo-600 hover:text-indigo-800 font-medium underline underline-offset-2">Brand DNA → References</button>.
                                    </p>
                                </div>
                                <div className="mt-6 space-y-6">
                                    {/* Logo Usage Guidelines */}
                                    <div>
                                        <div className="flex items-center justify-between mb-4">
                                            <div>
                                                <h4 className="text-sm font-semibold text-gray-900">Logo Usage Guidelines</h4>
                                                <p className="text-xs text-gray-500 mt-0.5">Rules for how the logo should and shouldn&apos;t be used in brand guidelines. Uses the logo uploaded in <button type="button" onClick={() => { setActiveTab('identity'); updateTabInUrl('identity') }} className="text-indigo-600 hover:text-indigo-800 font-medium underline underline-offset-2">Identity</button>.</p>
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

                                        <div className="mb-6 p-4 rounded-lg bg-slate-50 ring-1 ring-slate-200/80">
                                            <h5 className="text-sm font-semibold text-gray-900">Automated logo variants</h5>
                                            <p className="text-xs text-gray-500 mt-0.5 mb-4">When enabled, the system generates missing raster variants from your primary logo after saves, uploads, or pipeline runs (white mark for dark backgrounds; primary-color wash for light). SVG logos use the same generated thumbnail (WebP/PNG) as the asset grid.</p>
                                            <div className="space-y-3">
                                                <label className="flex items-start gap-3 cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                        checked={!!modelPayload.standards?.auto_generate_logo_on_dark}
                                                        onChange={(e) => setModelPayloadField('standards.auto_generate_logo_on_dark', e.target.checked)}
                                                    />
                                                    <span className="text-sm text-gray-800">
                                                        <span className="font-medium">Auto-generate on-dark (white) logo</span>
                                                        <span className="block text-xs text-gray-500 mt-0.5">Creates a white silhouette when no on-dark asset exists.</span>
                                                    </span>
                                                </label>
                                                <label className="flex items-start gap-3 cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                        checked={!!modelPayload.standards?.auto_generate_logo_on_light}
                                                        onChange={(e) => setModelPayloadField('standards.auto_generate_logo_on_light', e.target.checked)}
                                                    />
                                                    <span className="text-sm text-gray-800">
                                                        <span className="font-medium">Auto-generate on-light (primary color) logo</span>
                                                        <span className="block text-xs text-gray-500 mt-0.5">Recolors the mark with your primary brand color when no on-light asset exists.</span>
                                                    </span>
                                                </label>
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

                    {activeTab === 'alignment' && (
                    <div id="alignment" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Brand DNA rules</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Configure fonts, colors, and keywords used by Brand Intelligence. Visual reference images are chosen under{' '}
                                        <button type="button" onClick={() => { setActiveTab('references'); updateTabInUrl('references') }} className="text-indigo-600 hover:text-indigo-800 font-medium underline underline-offset-2">References</button>.
                                    </p>
                                </div>
                                <div className="mt-6 space-y-6">
                                    {renderTagArrayField('allowed_fonts', 'Allowed Fonts', 'e.g. Helvetica, Inter')}
                                    {renderTagArrayField('banned_colors', 'Banned Colors', 'Colors to penalize')}
                                    {renderTagArrayField('tone_keywords', 'Tone Keywords', 'Words that match brand tone')}
                                    {renderTagArrayField('banned_keywords', 'Banned Keywords', 'Words to penalize')}
                                    {renderTagArrayField('photography_attributes', 'Photography Attributes', 'e.g. minimal, lifestyle')}

                                    <button type="submit" disabled={dnaSaving} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                        {dnaSaving ? 'Saving…' : 'Save Brand DNA'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    )}

                    {activeTab === 'references' && (
                    <div id="references" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Visual references</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed max-w-2xl">
                                        Pick example assets that represent your brand look. When enabled per category, they anchor Brand Intelligence when measuring on-brand alignment. Typography, color, and keyword rules live under{' '}
                                        <button type="button" onClick={() => { setActiveTab('alignment'); updateTabInUrl('alignment') }} className="text-indigo-600 hover:text-indigo-800 font-medium underline underline-offset-2">Alignment</button>.
                                    </p>
                                </div>
                                <div className="mt-6 space-y-6">
                                    <VisualReferenceCategoryPicker
                                        brandId={brand.id}
                                        referenceCategories={modelPayload.standards?.reference_categories || {}}
                                        onChange={(updated) => setModelPayloadField('standards.reference_categories', updated)}
                                        noTopDivider
                                    />
                                    <button type="submit" disabled={dnaSaving} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                        {dnaSaving ? 'Saving…' : 'Save Brand DNA'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    )}

                    {activeTab === 'presentation' && (
                    <div id="presentation" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Presentation Style</h2>
                                    <p className="mt-3 text-sm text-gray-600 leading-relaxed max-w-2xl">
                                        Choose how your brand guidelines are visually presented. This controls the layout, typography treatment, backgrounds, and overall feel of your published brand guidelines.
                                    </p>
                                </div>

                                <div className="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-3">
                                    {[
                                        {
                                            id: 'clean',
                                            label: 'Clean',
                                            description: 'Minimal and refined. Thin accent lines beside headings, open whitespace between sections, and flat backgrounds with no textures.',
                                            features: ['Thin accent rules', 'Flat section backgrounds', 'Generous whitespace', 'Understated typography'],
                                            preview: (
                                                <div className="h-40 rounded-lg bg-white border border-gray-200 p-4 flex flex-col gap-2.5 overflow-hidden">
                                                    <div className="flex items-center gap-2">
                                                        <div className="w-0.5 h-4 bg-indigo-500 rounded-full" />
                                                        <div className="h-2.5 w-20 bg-gray-800 rounded" />
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <div className="h-1.5 w-full bg-gray-100 rounded" />
                                                        <div className="h-1.5 w-4/5 bg-gray-100 rounded" />
                                                        <div className="h-1.5 w-3/5 bg-gray-100 rounded" />
                                                    </div>
                                                    <div className="mt-auto flex gap-2">
                                                        <div className="h-8 w-8 rounded bg-gray-50 border border-gray-100" />
                                                        <div className="h-8 w-8 rounded bg-gray-50 border border-gray-100" />
                                                        <div className="h-8 w-8 rounded bg-gray-50 border border-gray-100" />
                                                    </div>
                                                </div>
                                            ),
                                        },
                                        {
                                            id: 'textured',
                                            label: 'Textured',
                                            description: 'Rich and immersive. Full-bleed photography behind text with overlay blends, textured section backgrounds, and layered depth.',
                                            features: ['Background imagery', 'Overlay & multiply blends', 'Textured sections', 'Atmospheric depth'],
                                            preview: (
                                                <div className="h-40 rounded-lg bg-gray-900 p-4 flex flex-col gap-2.5 overflow-hidden relative">
                                                    <div className="absolute inset-0 opacity-30" style={{ background: 'linear-gradient(135deg, #6366f1 0%, #0f172a 50%, #6366f1 100%)' }} />
                                                    <div className="absolute inset-0 opacity-20" style={{ backgroundImage: 'url("data:image/svg+xml,%3Csvg width=\'40\' height=\'40\' viewBox=\'0 0 40 40\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'%23fff\' fill-opacity=\'0.08\'%3E%3Cpath d=\'M0 0h20v20H0zM20 20h20v20H20z\'/%3E%3C/g%3E%3C/svg%3E")' }} />
                                                    <div className="relative z-10 flex flex-col gap-2.5">
                                                        <div className="h-3 w-24 bg-white/80 rounded" />
                                                        <div className="space-y-1.5">
                                                            <div className="h-1.5 w-full bg-white/20 rounded" />
                                                            <div className="h-1.5 w-4/5 bg-white/20 rounded" />
                                                        </div>
                                                    </div>
                                                    <div className="relative z-10 mt-auto flex gap-2">
                                                        <div className="h-8 w-12 rounded bg-white/10 backdrop-blur-sm" />
                                                        <div className="h-8 w-12 rounded bg-white/10 backdrop-blur-sm" />
                                                    </div>
                                                </div>
                                            ),
                                        },
                                        {
                                            id: 'bold',
                                            label: 'Bold',
                                            description: 'Strong and graphic. Prominent container shapes around headings, offset layouts, heavy type, and textured backgrounds with high contrast.',
                                            features: ['Container shapes', 'Offset / asymmetric layouts', 'Heavy display type', 'High-contrast blocks'],
                                            preview: (
                                                <div className="h-40 rounded-lg bg-gray-950 p-4 flex flex-col gap-2 overflow-hidden relative">
                                                    <div className="absolute inset-0 opacity-10" style={{ background: 'repeating-linear-gradient(45deg, transparent, transparent 8px, rgba(255,255,255,0.03) 8px, rgba(255,255,255,0.03) 16px)' }} />
                                                    <div className="relative z-10">
                                                        <div className="inline-block bg-red-600 px-2 py-1 mb-2">
                                                            <div className="h-2.5 w-16 bg-white rounded-sm" />
                                                        </div>
                                                    </div>
                                                    <div className="relative z-10 flex gap-2">
                                                        <div className="flex-1 bg-white/5 border border-white/10 rounded p-2">
                                                            <div className="h-2 w-12 bg-white/60 rounded mb-1.5" />
                                                            <div className="h-1 w-full bg-white/10 rounded" />
                                                            <div className="h-1 w-3/4 bg-white/10 rounded mt-1" />
                                                        </div>
                                                        <div className="flex-1 bg-white/5 border border-white/10 rounded p-2">
                                                            <div className="h-2 w-10 bg-white/60 rounded mb-1.5" />
                                                            <div className="h-1 w-full bg-white/10 rounded" />
                                                            <div className="h-1 w-2/3 bg-white/10 rounded mt-1" />
                                                        </div>
                                                    </div>
                                                    <div className="relative z-10 mt-auto">
                                                        <div className="h-6 w-full rounded bg-white/5 border-l-4 border-red-600 flex items-center px-2">
                                                            <div className="h-1.5 w-20 bg-white/40 rounded" />
                                                        </div>
                                                    </div>
                                                </div>
                                            ),
                                        },
                                    ].map((style) => {
                                        const isSelected = (modelPayload.presentation?.style || 'clean') === style.id
                                        const isLocked = isFreePlan && style.id !== 'clean'
                                        return (
                                            <button
                                                key={style.id}
                                                type="button"
                                                onClick={() => !isLocked && setModelPayloadField('presentation.style', style.id)}
                                                disabled={isLocked}
                                                className={`relative flex flex-col rounded-xl border-2 p-0 text-left transition-all overflow-hidden ${
                                                    isLocked
                                                        ? 'border-gray-200 opacity-50 cursor-not-allowed'
                                                        : isSelected
                                                            ? 'border-indigo-600 ring-2 ring-indigo-600 shadow-md'
                                                            : 'border-gray-200 hover:border-gray-300 hover:shadow-sm'
                                                }`}
                                            >
                                                {isLocked && (
                                                    <div className="absolute top-2 right-2 z-10 rounded-full bg-gray-800/80 px-2 py-0.5 text-[10px] font-medium text-white">Paid</div>
                                                )}
                                                {style.preview}
                                                <div className="p-4 flex-1 flex flex-col">
                                                    <div className="flex items-center justify-between mb-1">
                                                        <h3 className="text-sm font-semibold text-gray-900">{style.label}</h3>
                                                        {isSelected && !isLocked && (
                                                            <svg className="h-5 w-5 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                        )}
                                                    </div>
                                                    <p className="text-xs text-gray-500 leading-relaxed mb-3">{style.description}</p>
                                                    <ul className="mt-auto space-y-1">
                                                        {style.features.map((f) => (
                                                            <li key={f} className="flex items-center gap-1.5 text-xs text-gray-600">
                                                                <span className="h-1 w-1 rounded-full bg-gray-400 flex-shrink-0" />
                                                                {f}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            </button>
                                        )
                                    })}
                                </div>

                                <div className="mt-8 rounded-lg bg-amber-50 border border-amber-200 p-4">
                                    <div className="flex gap-3">
                                        <svg className="h-5 w-5 text-amber-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" /></svg>
                                        <div>
                                            <p className="text-sm font-medium text-amber-800">More styles coming soon</p>
                                            <p className="text-xs text-amber-700 mt-1">Additional presentation styles will be available in future updates. If you upload brand guidelines as a PDF, the style may be automatically inferred from your document.</p>
                                        </div>
                                    </div>
                                </div>

                                <div className="mt-6">
                                    <button type="submit" disabled={dnaSaving} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                        {dnaSaving ? 'Saving…' : 'Save Brand DNA'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    )}

                    {activeTab === 'research' && (
                    <ResearchInsightsPanel insights={research_insights} brandId={brand.id} />
                    )}

                </form>
                ) : (
                <form onSubmit={submit} className="space-y-8">
                    {/* Tab: Identity */}
                    {activeTab === 'identity' && (
                    <>
                    <div className="flex gap-8">
                        {/* Left: Section navigation */}
                        <nav className="hidden lg:block w-44 flex-shrink-0">
                            <div className="sticky top-8 space-y-1">
                                <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-3 px-3">On this page</p>
                                {[
                                    { id: 'basic-information', label: 'Brand Identity' },
                                    { id: 'brand-images', label: 'Brand Images' },
                                    { id: 'brand-colors', label: 'Brand Colors' },
                                ].map((s) => (
                                    <a
                                        key={s.id}
                                        href={`#${s.id}`}
                                        onClick={(e) => {
                                            e.preventDefault()
                                            document.getElementById(s.id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
                                        }}
                                        className="block px-3 py-2 text-sm text-gray-600 hover:text-gray-900 hover:bg-gray-50 rounded-lg transition-colors"
                                    >
                                        {s.label}
                                    </a>
                                ))}
                            </div>
                        </nav>

                        {/* Center: Main content */}
                        <div className="flex-1 min-w-0 space-y-8">

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

                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Section 2: Brand Images */}
                    <div id="brand-images" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/30 overflow-hidden">
                            <div className="px-6 py-8 sm:px-8 sm:py-10">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Brand Images</h2>
                                    <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                                        Upload your logos, pick from the asset library or {DELIVERABLES_PAGE_LABEL_SINGULAR.toLowerCase()}s, or generate raster variants from your primary logo (same options as Brand DNA → Standards).
                                    </p>
                                </div>
                                <div className="mt-6 rounded-lg border border-indigo-100 bg-indigo-50/50 px-4 py-4">
                                    <h3 className="text-sm font-semibold text-gray-900">Generate raster variants</h3>
                                    <p className="mt-1 text-xs text-gray-600">
                                        Creates PNGs from your <strong>primary</strong> logo: raster files use the original; <strong>SVG</strong> uses the processed thumbnail (same image as the grid). On-dark updates <strong>Logo (dark background)</strong> below. Primary-color variant is stored for Brand DNA guidelines.
                                    </p>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            disabled={logoVariantGenerating || processing}
                                            onClick={() => runLogoVariantGeneration(true, false)}
                                            className="rounded-md bg-white px-3 py-2 text-xs font-semibold text-indigo-700 shadow-sm ring-1 ring-indigo-200 hover:bg-indigo-50 disabled:opacity-50"
                                        >
                                            {logoVariantGenerating ? 'Working…' : 'Generate on-dark logo'}
                                        </button>
                                        <button
                                            type="button"
                                            disabled={logoVariantGenerating || processing}
                                            onClick={() => runLogoVariantGeneration(false, true)}
                                            className="rounded-md bg-white px-3 py-2 text-xs font-semibold text-indigo-700 shadow-sm ring-1 ring-indigo-200 hover:bg-indigo-50 disabled:opacity-50"
                                        >
                                            {logoVariantGenerating ? 'Working…' : 'Generate primary-color variant'}
                                        </button>
                                    </div>
                                    {logoVariantError && (
                                        <p className="mt-2 text-xs text-amber-800" role="alert">{logoVariantError}</p>
                                    )}
                                </div>
                                <div className="mt-6 space-y-6">
                                            {/* Logo (Light) */}
                                            <div>
                                                <label className="block text-sm font-medium text-gray-900 mb-1">Logo</label>
                                                <p className="text-xs text-gray-500 mb-2">Primary logo for light backgrounds.</p>
                                                <div className="rounded-lg border border-gray-200 bg-white p-2">
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
                                                            } else if (v?.preview_url) {
                                                                setData('logo_preview', v.preview_url)
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
                                                        fetchDeliverables={fetchDeliverablesForPicker}
                                                        getAssetDownloadUrl={(id) => `/app/assets/${id}/download`}
                                                        title="Select logo"
                                                        defaultCategoryLabel="Logos"
                                                        contextCategory="logos"
                                                        aspectRatio={{ width: 265, height: 64 }}
                                                        minWidth={265}
                                                        minHeight={64}
                                                        placeholder="Click to choose from library or upload"
                                                        helperText="Recommended: 265×64 px or similar aspect ratio"
                                                        brandId={brand.id}
                                                    />
                                                </div>
                                                {errors.logo && <p className="mt-2 text-sm text-red-600">{errors.logo}</p>}
                                            </div>
                                            {/* Logo (Dark) */}
                                            <div>
                                                <label className="block text-sm font-medium text-gray-900 mb-1">Logo (Dark Background)</label>
                                                <p className="text-xs text-gray-500 mb-2">Optional. Used on dark backgrounds like the brand overview hero.</p>
                                                <div className="rounded-lg border border-gray-700 bg-gray-900 p-2">
                                                    <AssetImagePickerField
                                                        value={{
                                                            preview_url: data.logo_dark_preview ?? (data.logo_dark_id && data.logo_dark_id === brand.logo_dark_id ? (brand.logo_dark_thumbnail_url ?? brand.logo_dark_path) : null),
                                                            asset_id: data.logo_dark_id ?? null,
                                                        }}
                                                        onChange={(v) => {
                                                            if (v == null) {
                                                                setData('logo_dark_id', null)
                                                                setData('logo_dark_preview', null)
                                                                setData('clear_logo_dark', true)
                                                            } else if (v?.asset_id) {
                                                                setData('logo_dark_id', v.asset_id)
                                                                setData('logo_dark_preview', v.preview_url ?? v.thumbnail_url ?? null)
                                                                setData('clear_logo_dark', false)
                                                            } else if (v?.preview_url) {
                                                                setData('logo_dark_preview', v.preview_url)
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
                                                        fetchDeliverables={fetchDeliverablesForPicker}
                                                        getAssetDownloadUrl={(id) => `/app/assets/${id}/download`}
                                                        title="Select dark logo"
                                                        defaultCategoryLabel="Logos"
                                                        contextCategory="logos"
                                                        aspectRatio={{ width: 265, height: 64 }}
                                                        minWidth={265}
                                                        minHeight={64}
                                                        placeholder="Click to choose from library or upload"
                                                        helperText="Light-colored logo for dark backgrounds"
                                                        theme="dark"
                                                        brandId={brand.id}
                                                    />
                                                </div>
                                            </div>
                                            {/* Horizontal Logo */}
                                            <div>
                                                <label className="block text-sm font-medium text-gray-900 mb-1">Horizontal Logo</label>
                                                <p className="text-xs text-gray-500 mb-2">Optional. Landscape/wordmark version for wide placements like navigation bars.</p>
                                                <div className="rounded-lg border border-gray-200 bg-white p-2">
                                                    <AssetImagePickerField
                                                        value={{
                                                            preview_url: data.logo_horizontal_preview ?? (data.logo_horizontal_id && data.logo_horizontal_id === brand.logo_horizontal_id ? (brand.logo_horizontal_thumbnail_url ?? brand.logo_horizontal_path) : null),
                                                            asset_id: data.logo_horizontal_id ?? null,
                                                        }}
                                                        onChange={(v) => {
                                                            if (v == null) {
                                                                setData('logo_horizontal_id', null)
                                                                setData('logo_horizontal_preview', null)
                                                                setData('clear_logo_horizontal', true)
                                                            } else if (v?.asset_id) {
                                                                setData('logo_horizontal_id', v.asset_id)
                                                                setData('logo_horizontal_preview', v.preview_url ?? v.thumbnail_url ?? null)
                                                                setData('clear_logo_horizontal', false)
                                                            } else if (v?.preview_url) {
                                                                setData('logo_horizontal_preview', v.preview_url)
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
                                                        fetchDeliverables={fetchDeliverablesForPicker}
                                                        getAssetDownloadUrl={(id) => `/app/assets/${id}/download`}
                                                        title="Select horizontal logo"
                                                        defaultCategoryLabel="Logos"
                                                        contextCategory="logos"
                                                        aspectRatio={{ width: 4, height: 1 }}
                                                        minWidth={200}
                                                        minHeight={50}
                                                        placeholder="Click to choose from library or upload"
                                                        helperText="Wide/landscape format recommended (e.g. 4:1 ratio)"
                                                        brandId={brand.id}
                                                    />
                                                </div>
                                            </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Section 3: Brand Colors */}
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
                                        {hexForColorInput(data.primary_color) ? (
                                            <input
                                                type="color"
                                                id="primary_color_picker"
                                                value={hexForColorInput(data.primary_color)}
                                                onChange={(e) => {
                                                    setData('primary_color', e.target.value)
                                                    if (!data.nav_color || data.nav_color === data.primary_color) {
                                                        setData('nav_color', e.target.value)
                                                    }
                                                }}
                                                className="h-10 w-20 rounded border border-gray-300 cursor-pointer"
                                            />
                                        ) : (
                                            <div
                                                className="h-10 w-20 shrink-0 rounded border-2 border-dashed border-gray-300 bg-gray-50"
                                                title="No primary color"
                                                aria-hidden
                                            />
                                        )}
                                        <input
                                            type="text"
                                            name="primary_color"
                                            id="primary_color"
                                            value={data.primary_color}
                                            onChange={(e) => {
                                                setData('primary_color', e.target.value)
                                                if (!data.nav_color || data.nav_color === data.primary_color) {
                                                    setData('nav_color', e.target.value)
                                                }
                                            }}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder="e.g. #502c6d"
                                            autoComplete="off"
                                        />
                                    </div>
                                            {errors.primary_color && <p className="mt-2 text-sm text-red-600">{errors.primary_color}</p>}
                                        </div>

                                        <div>
                                    <label htmlFor="secondary_color" className="block text-sm font-medium leading-6 text-gray-900">
                                        Secondary Color
                                    </label>
                                    <div className="mt-2 flex gap-2">
                                        {hexForColorInput(data.secondary_color) ? (
                                            <input
                                                type="color"
                                                id="secondary_color_picker"
                                                value={hexForColorInput(data.secondary_color)}
                                                onChange={(e) => setData('secondary_color', e.target.value)}
                                                className="h-10 w-20 rounded border border-gray-300 cursor-pointer"
                                            />
                                        ) : (
                                            <div
                                                className="h-10 w-20 shrink-0 rounded border-2 border-dashed border-gray-300 bg-gray-50"
                                                title="No secondary color"
                                                aria-hidden
                                            />
                                        )}
                                        <input
                                            type="text"
                                            name="secondary_color"
                                            id="secondary_color"
                                            value={data.secondary_color}
                                            onChange={(e) => setData('secondary_color', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder="e.g. #ffffff"
                                            autoComplete="off"
                                        />
                                    </div>
                                            {errors.secondary_color && <p className="mt-2 text-sm text-red-600">{errors.secondary_color}</p>}
                                        </div>

                                        <div>
                                    <label htmlFor="accent_color" className="block text-sm font-medium leading-6 text-gray-900">
                                        Accent Color
                                    </label>
                                    <div className="mt-2 flex gap-2">
                                        {hexForColorInput(data.accent_color) ? (
                                            <input
                                                type="color"
                                                id="accent_color_picker"
                                                value={hexForColorInput(data.accent_color)}
                                                onChange={(e) => setData('accent_color', e.target.value)}
                                                className="h-10 w-20 rounded border border-gray-300 cursor-pointer"
                                            />
                                        ) : (
                                            <div
                                                className="h-10 w-20 shrink-0 rounded border-2 border-dashed border-gray-300 bg-gray-50"
                                                title="No accent color"
                                                aria-hidden
                                            />
                                        )}
                                        <input
                                            type="text"
                                            name="accent_color"
                                            id="accent_color"
                                            value={data.accent_color}
                                            onChange={(e) => setData('accent_color', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder="Optional"
                                            autoComplete="off"
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

                                    {/* Letter / tile style (when logo not shown in compact tile) */}
                                    <div className="mt-6 pt-6 border-t border-gray-200">
                                        <label className="block text-sm font-medium leading-6 text-gray-900 mb-1">Tile style</label>
                                        <p className="text-sm text-gray-500 mb-3">How the letter tile looks in the brand selector and other compact previews when the logo is not used there.</p>
                                        <div className="grid grid-cols-3 gap-3">
                                            {[
                                                { value: 'subtle', label: 'Subtle', desc: 'Soft single-color gradient' },
                                                { value: 'gradient', label: 'Gradient', desc: 'Primary to secondary' },
                                                { value: 'solid', label: 'Solid', desc: 'Flat primary color' },
                                            ].map((opt) => {
                                                const pri = data.primary_color || '#6366f1'
                                                const sec = data.secondary_color || '#8b5cf6'
                                                const previewBg = opt.value === 'subtle'
                                                    ? `linear-gradient(135deg, ${pri}CC, ${pri}55)`
                                                    : opt.value === 'gradient'
                                                        ? `linear-gradient(135deg, ${sec !== pri ? sec : pri}, ${pri})`
                                                        : pri
                                                return (
                                                    <button
                                                        key={opt.value}
                                                        type="button"
                                                        onClick={() => setData('icon_style', opt.value)}
                                                        className={`relative rounded-lg border-2 p-3 text-left transition-all ${
                                                            data.icon_style === opt.value
                                                                ? 'border-indigo-500 ring-1 ring-indigo-500'
                                                                : 'border-gray-200 hover:border-gray-300'
                                                        }`}
                                                    >
                                                        <div className="flex items-center gap-3">
                                                            <div
                                                                className="h-10 w-10 rounded-lg flex items-center justify-center flex-shrink-0"
                                                                style={{ background: previewBg }}
                                                            >
                                                                <span className="text-sm font-bold text-white">
                                                                    {(data.name || brand.name || 'B').charAt(0).toUpperCase()}
                                                                </span>
                                                            </div>
                                                            <div className="min-w-0">
                                                                <p className="text-sm font-medium text-gray-900">{opt.label}</p>
                                                                <p className="text-xs text-gray-500 truncate">{opt.desc}</p>
                                                            </div>
                                                        </div>
                                                    </button>
                                                )
                                            })}
                                        </div>
                                    </div>
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

                        </div>{/* end center column */}

                        {/* Right: Live Preview sidebar */}
                        <div className="hidden lg:block w-64 flex-shrink-0">
                            <div className="sticky top-8">
                                <div className="rounded-2xl bg-gray-950 p-4 shadow-lg ring-1 ring-white/10">
                                    <div className="flex items-center gap-2 mb-4">
                                        <div className="flex gap-1">
                                            <span className="h-2 w-2 rounded-full bg-red-400/70" />
                                            <span className="h-2 w-2 rounded-full bg-yellow-400/70" />
                                            <span className="h-2 w-2 rounded-full bg-green-400/70" />
                                        </div>
                                        <span className="text-[10px] text-white/30 font-medium uppercase tracking-wider">Live Preview</span>
                                    </div>

                                    <div className="space-y-3">
                                        {/* Navigation Bar preview */}
                                        <div>
                                            <p className="text-[9px] text-white/25 uppercase tracking-wider mb-1.5 px-1">Navigation</p>
                                            <div className="rounded-lg bg-white px-3 py-2.5 flex items-center gap-2.5">
                                                <BrandIconUnified
                                                    brand={{
                                                        ...brand,
                                                        logo_path: data.logo_preview ?? brand.logo_path,
                                                        primary_color: data.primary_color || brand.primary_color,
                                                        secondary_color: data.secondary_color || brand.secondary_color,
                                                        icon_style: data.icon_style,
                                                        name: data.name || brand.name,
                                                    }}
                                                    size="md"
                                                />
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-[11px] font-medium text-gray-900 truncate">{data.name || brand.name || 'Brand'}</p>
                                                    <p className="text-[9px] text-gray-400">Brand Selector</p>
                                                </div>
                                                <svg className="h-3 w-3 text-gray-300" viewBox="0 0 20 20" fill="currentColor"><path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clipRule="evenodd" /></svg>
                                            </div>
                                        </div>

                                        {/* Overview Hero preview */}
                                        <div>
                                            <p className="text-[9px] text-white/25 uppercase tracking-wider mb-1.5 px-1">Overview Hero</p>
                                            <div
                                                className="rounded-lg px-4 py-6 flex flex-col items-center justify-center min-h-[100px]"
                                                style={{
                                                    background: `radial-gradient(ellipse at 30% 20%, ${data.primary_color || brand.primary_color || '#6366f1'}66, transparent 70%), radial-gradient(ellipse at 70% 80%, ${data.secondary_color || brand.secondary_color || '#8b5cf6'}66, transparent 70%), #0B0B0D`,
                                                }}
                                            >
                                                {(() => {
                                                    const darkLogoUrl = data.logo_dark_preview ?? brand.logo_dark_path
                                                    const lightLogoUrl = data.logo_preview ?? brand.logo_path
                                                    if (darkLogoUrl) {
                                                        return <img src={darkLogoUrl} alt="" className="h-8 w-auto max-w-[120px] object-contain" />
                                                    }
                                                    if (lightLogoUrl) {
                                                        return <img src={lightLogoUrl} alt="" className="h-8 w-auto max-w-[120px] object-contain" style={{ filter: 'brightness(0) invert(1)' }} />
                                                    }
                                                    return (
                                                        <span className="text-lg font-bold text-white/90">{(data.name || brand.name || 'B').charAt(0).toUpperCase()}</span>
                                                    )
                                                })()}
                                                <p className="mt-1.5 text-[9px] text-white/30">{data.name || brand.name || 'Brand Name'}</p>
                                            </div>
                                        </div>

                                        {/* Tile sizes */}
                                        <div>
                                            <p className="text-[9px] text-white/25 uppercase tracking-wider mb-1.5 px-1">Tile Sizes</p>
                                            <div className="rounded-lg bg-white/5 px-3 py-3">
                                                <div className="flex items-end gap-2 flex-wrap">
                                                    {['xs', 'sm', 'md', 'lg', 'xl'].map((sz) => (
                                                        <div key={sz} className="flex flex-col items-center gap-1">
                                                            <BrandIconUnified
                                                                brand={{
                                                                    ...brand,
                                                                    logo_path: data.logo_preview ?? brand.logo_path,
                                                                    primary_color: data.primary_color || brand.primary_color,
                                                                    secondary_color: data.secondary_color || brand.secondary_color,
                                                                    icon_style: data.icon_style,
                                                                    name: data.name || brand.name,
                                                                }}
                                                                size={sz}
                                                            />
                                                            <span className="text-[8px] text-white/20">{sz}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>

                                        {/* Color palette preview */}
                                        {(data.primary_color || data.secondary_color || data.accent_color) && (
                                        <div>
                                            <p className="text-[9px] text-white/25 uppercase tracking-wider mb-1.5 px-1">Colors</p>
                                            <div className="flex gap-1.5">
                                                {data.primary_color && (
                                                    <div className="flex-1 h-8 rounded-md ring-1 ring-white/10" style={{ backgroundColor: data.primary_color }} />
                                                )}
                                                {data.secondary_color && (
                                                    <div className="flex-1 h-8 rounded-md ring-1 ring-white/10" style={{ backgroundColor: data.secondary_color }} />
                                                )}
                                                {data.accent_color && (
                                                    <div className="flex-1 h-8 rounded-md ring-1 ring-white/10" style={{ backgroundColor: data.accent_color }} />
                                                )}
                                            </div>
                                        </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>{/* end 3-column flex */}
                    </>
                    )}

                    {/* Tab: Public Site — External-facing portal settings */}
                    {activeTab === 'public-site' && (
                    <div id="public-site" className="scroll-mt-8 space-y-6">
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
                                    onSave={autoSaveBrandField}
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
                                    onSave={autoSaveBrandField}
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
                                    onSave={autoSaveBrandField}
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
                                    onSave={autoSaveBrandField}
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
                                    onSave={autoSaveBrandField}
                                />
                            </div>
                        </div>
                    </div>
                    )}

                    {/* Tab: Workspace Appearance */}
                    {activeTab === 'workspace' && (
                    <div id="workspace-appearance" className="scroll-mt-8 space-y-6">
                        <div className="flex gap-8">
                        {/* Left: Settings */}
                        <div className="flex-1 min-w-0 rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
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
                                                const hex = style === 'primary' ? (data.primary_color || brand.primary_color || '#6366f1') : style === 'secondary' ? (data.secondary_color || brand.secondary_color || '#64748b') : (data.accent_color || brand.accent_color || data.primary_color || brand.primary_color || '#6366f1')
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
                                    {/* Sidebar color selection — locked to brand palette + neutral options */}
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-900 mb-1">Sidebar color</h4>
                                        <p className="text-sm text-gray-500 mb-4">
                                            Choose from your brand palette or a neutral option.
                                        </p>
                                        <div className="grid grid-cols-3 sm:grid-cols-5 gap-3">
                                            {[
                                                { label: 'Primary', color: data.primary_color || '#6366f1', available: true },
                                                { label: 'Secondary', color: data.secondary_color, available: !!data.secondary_color },
                                                { label: 'Accent', color: data.accent_color, available: !!data.accent_color },
                                                { label: 'Dark', color: '#1f2937', available: true },
                                                { label: 'White', color: '#ffffff', available: true },
                                            ].map((opt) => {
                                                const isSelected = data.nav_color === opt.color
                                                const isWhite = opt.color === '#ffffff'
                                                if (!opt.available) {
                                                    return (
                                                        <div key={opt.label} className="flex flex-col items-center p-3 rounded-lg border-2 border-gray-100 opacity-40">
                                                            <div className="w-full h-12 rounded-md mb-1.5 bg-gray-50 border-2 border-dashed border-gray-200" />
                                                            <span className="text-xs font-medium text-gray-400">{opt.label}</span>
                                                        </div>
                                                    )
                                                }
                                                return (
                                                    <button
                                                        key={opt.label}
                                                        type="button"
                                                        onClick={() => {
                                                            setData('nav_color', opt.color)
                                                            autoSaveBrandField({ nav_color: opt.color })
                                                        }}
                                                        className={`relative flex flex-col items-center p-3 rounded-lg border-2 transition-all ${
                                                            isSelected ? 'border-indigo-600 ring-2 ring-indigo-600' : 'border-gray-200 hover:border-gray-300'
                                                        }`}
                                                    >
                                                        <div
                                                            className={`w-full h-12 rounded-md mb-1.5 ${isWhite ? 'border border-gray-200' : ''}`}
                                                            style={{ backgroundColor: opt.color }}
                                                        />
                                                        <span className="text-xs font-medium text-gray-900">{opt.label}</span>
                                                        {isSelected && (
                                                            <div className="absolute top-1.5 right-1.5">
                                                                <svg className="h-4 w-4 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                            </div>
                                                        )}
                                                    </button>
                                                )
                                            })}
                                        </div>
                                        {errors.nav_color && <p className="mt-2 text-sm text-red-600">{errors.nav_color}</p>}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Right: Sticky Workspace Preview */}
                        <div className="hidden lg:block w-80 flex-shrink-0">
                            <div className="sticky top-8">
                                <h4 className="text-sm font-medium text-gray-900 mb-1">Preview</h4>
                                <p className="text-sm text-gray-500 mb-4">
                                    How the workspace will appear in the DAM.
                                </p>
                                {(() => {
                                    const sidebarColor = data.nav_color || data.primary_color || brand.primary_color || '#6366f1'
                                    const sidebarTextColor = getContrastTextColor(sidebarColor)
                                    const btnStyle = data.workspace_button_style ?? data.settings?.button_style ?? 'primary'
                                    const btnColor = btnStyle === 'primary' ? (data.primary_color || brand.primary_color || '#6366f1') : btnStyle === 'secondary' ? (data.secondary_color || brand.secondary_color || '#64748b') : (data.accent_color || brand.accent_color || data.primary_color || brand.primary_color || '#6366f1')
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
                                        <div className="rounded-lg border border-gray-200 overflow-hidden bg-gray-50 shadow-lg">
                                            {/* Top navigation bar (white) */}
                                            <div className="bg-white border-b border-gray-200 px-3 py-2 flex items-center gap-2">
                                                {previewNavMode === 'logo' && previewLogoSrc ? (
                                                    <img src={previewLogoSrc} alt="" className="h-5 w-auto max-w-[100px] object-contain" style={previewLogoFilter} />
                                                ) : (
                                                    <div className="flex items-center gap-1.5">
                                                        <div className="w-4 h-4 rounded-full flex-shrink-0" style={{ backgroundColor: data.primary_color || brand.primary_color || '#6366f1' }} />
                                                        <span className="text-[10px] font-semibold text-gray-800 truncate max-w-[80px]">{data.name || brand.name}</span>
                                                    </div>
                                                )}
                                                <div className="flex-1" />
                                                <div className="flex gap-2">
                                                    {['Overview', 'Assets', DELIVERABLES_PAGE_LABEL_SINGULAR + 's'].map((l) => (
                                                        <span key={l} className="text-[8px] text-gray-400 font-medium">{l}</span>
                                                    ))}
                                                </div>
                                            </div>
                                            <div className="flex" style={{ minHeight: 260 }}>
                                                {/* Sidebar */}
                                                <aside
                                                    className="w-[56px] flex flex-col flex-shrink-0"
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
                                                    <div className="flex items-center gap-2 px-3 py-2 flex-shrink-0">
                                                        <span
                                                            className="px-2.5 py-1 rounded text-[9px] font-medium text-white"
                                                            style={{ backgroundColor: btnColor }}
                                                        >
                                                            Add Asset
                                                        </span>
                                                        <div className="flex-1" />
                                                        <div className="h-5 bg-white border border-gray-200 rounded w-full max-w-[80px]" />
                                                    </div>
                                                    <div className="flex-1 px-3 pb-3 overflow-hidden">
                                                        <div className="grid grid-cols-3 gap-2">
                                                            {[1, 2, 3, 4, 5, 6].map((i) => (
                                                                <div key={i} className="aspect-square bg-white border border-gray-100 rounded shadow-sm" />
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
                        </div>{/* end flex row */}
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
