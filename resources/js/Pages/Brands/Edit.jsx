import { useForm, Link, router, usePage } from '@inertiajs/react'
import { useState, useEffect, useRef } from 'react'
import AppNav from '../../Components/AppNav'
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
import BrandMembersSection from '../../Components/brand/BrandMembersSection'
import PublicPageTheme from '../../Components/branding/PublicPageTheme'

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

export default function BrandsEdit({ brand, categories, available_system_templates, category_limits, brand_users, brand_roles, available_users, pending_invitations, private_category_limits, can_edit_system_categories, tenant_settings, current_plan }) {
    const { auth } = usePage().props
    const [iconBackgroundStyle, setIconBackgroundStyle] = useState({ background: 'transparent', isWhite: false })
    const [activeCategoryTab, setActiveCategoryTab] = useState('asset')
    const [activeSection, setActiveSection] = useState('basic-information')
    const [activeTab, setActiveTab] = useState('identity') // identity | workspace | public-pages | members
    const [upgradeModalOpen, setUpgradeModalOpen] = useState(false)
    const [selectedCategoryForUpgrade, setSelectedCategoryForUpgrade] = useState(null)
    const [editingCategoryId, setEditingCategoryId] = useState(null)
    const [showCreateCategoryForm, setShowCreateCategoryForm] = useState(false)
    
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
        settings: {
            // Preserve any other settings that might exist first
            ...(brand.settings || {}),
            // Then explicitly set boolean values (convert string '0'/'1' to boolean)
            metadata_approval_enabled: brand.settings?.metadata_approval_enabled === true || brand.settings?.metadata_approval_enabled === '1' || brand.settings?.metadata_approval_enabled === 1, // Phase M-2
            contributor_upload_requires_approval: brand.settings?.contributor_upload_requires_approval === true || brand.settings?.contributor_upload_requires_approval === '1' || brand.settings?.contributor_upload_requires_approval === 1, // Phase J.3.1
        },
        // D10: Brand-level download landing branding (logo from assets, color from palette, no raw URL/hex)
        download_landing_settings: {
            enabled: brand.download_landing_settings?.enabled === true,
            logo_asset_id: brand.download_landing_settings?.logo_asset_id ?? null,
            color_role: brand.download_landing_settings?.color_role || 'primary',
            custom_color: brand.download_landing_settings?.custom_color || '',
            default_headline: brand.download_landing_settings?.default_headline || '',
            default_subtext: brand.download_landing_settings?.default_subtext || '',
            background_asset_ids: Array.isArray(brand.download_landing_settings?.background_asset_ids) ? brand.download_landing_settings.background_asset_ids : [],
        },
    })

    const submit = (e) => {
        e.preventDefault()
        
        console.log('[Brands/Edit] Submitting form with data:', {
            settings: data.settings,
            contributor_upload_requires_approval: data.settings?.contributor_upload_requires_approval,
        })
        
        put(`/app/brands/${brand.id}`, {
            forceFormData: true, // Important for file uploads
            // Inertia flattens nested objects; download_landing_settings.* sent as nested fields
            onSuccess: () => {
                // Cleanup preview URLs
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

    // Handle hash-based navigation and scrolling
    useEffect(() => {
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
    }, [])

    // Update active section on scroll
    useEffect(() => {
        const handleScroll = () => {
            const sections = ['basic-information', 'brand-colors', 'public-pages', 'workspace-appearance', 'metadata', 'categories']
            const scrollPosition = window.scrollY + 100

            for (let i = sections.length - 1; i >= 0; i--) {
                const section = document.getElementById(sections[i])
                if (section && section.offsetTop <= scrollPosition) {
                    setActiveSection(sections[i])
                    break
                }
            }
        }

        window.addEventListener('scroll', handleScroll)
        return () => window.removeEventListener('scroll', handleScroll)
    }, [])

    const handleSectionClick = (sectionId) => {
        setActiveSection(sectionId)
        window.location.hash = sectionId
        const element = document.getElementById(sectionId)
        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'start' })
        }
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <Link
                        href="/app/brands"
                        className="text-sm font-medium text-gray-500 hover:text-gray-700"
                    >
                        ← Back to Brands
                    </Link>
                    <h1 className="mt-4 text-2xl font-bold tracking-tight text-gray-900">Brand Settings</h1>
                    <p className="mt-1 text-sm text-gray-600">
                        Manage identity, workspace appearance, and team access.
                    </p>

                    {/* Tab navigation — pill-style segmented control */}
                    <nav className="mt-6 p-1 rounded-xl bg-gray-100 inline-flex flex-wrap gap-0.5 shadow-sm" aria-label="Brand settings tabs">
                        {[
                            { id: 'identity', label: 'Identity' },
                            { id: 'workspace', label: 'Workspace Appearance' },
                            { id: 'public-pages', label: 'Public Pages' },
                            { id: 'members', label: 'Members' },
                        ].map((tab) => (
                            <button
                                key={tab.id}
                                type="button"
                                onClick={() => setActiveTab(tab.id)}
                                className={`px-4 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 ease-out ${
                                    activeTab === tab.id
                                        ? 'bg-white text-gray-900 shadow-md ring-1 ring-gray-200/60'
                                        : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50/80'
                                }`}
                            >
                                {tab.label}
                            </button>
                        ))}
                        <Link
                            href={typeof route === 'function' ? route('brands.dna.index', { brand: brand.id }) : `/app/brands/${brand.id}/dna`}
                            className="px-4 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 ease-out text-gray-600 hover:text-gray-900 hover:bg-gray-50/80"
                        >
                            Brand DNA
                        </Link>
                        <Link
                            href={typeof route === 'function' ? route('brands.guidelines.index', { brand: brand.id }) : `/app/brands/${brand.id}/guidelines`}
                            className="px-4 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 ease-out text-gray-600 hover:text-gray-900 hover:bg-gray-50/80"
                        >
                            Brand Guidelines
                        </Link>
                    </nav>
                </div>

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
                                        {['pro', 'enterprise'].includes(current_plan) && tenant_settings?.enable_metadata_approval && (
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
                                        {(!['pro', 'enterprise'].includes(current_plan) || !tenant_settings?.enable_metadata_approval) && (
                                            <div className="rounded-md bg-gray-50 p-4">
                                                <p className="text-sm text-gray-600">
                                                    {!['pro', 'enterprise'].includes(current_plan) 
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

                    {/* Tab: Public Pages — Public page theming (design system) */}
                    {activeTab === 'public-pages' && (
                    <div id="public-pages" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-2">
                                    <h2 className="text-xl font-semibold text-gray-900">Public Page Theme</h2>
                                    <p className="mt-3 text-sm text-gray-600 leading-relaxed max-w-xl">
                                        Define how brand-facing pages look — downloads, shared links, collections, and campaign pages.
                                    </p>
                                </div>
                                <PublicPageTheme
                                    brand={brand}
                                    data={data}
                                    setData={setData}
                                    route={typeof route === 'function' ? route : (name, params) => {
                                        const p = params && typeof params === 'object' && !Array.isArray(params) ? params : {}
                                        if (name === 'brands.download-background-candidates') return `/app/brands/${p.brand ?? params ?? brand.id}/download-background-candidates`
                                        if (name === 'assets.thumbnail.final') return `/app/assets/${p.asset}/thumbnail/final/${p.style || 'medium'}`
                                        return '#'
                                    }}
                                />
                            </div>
                        </div>
                    </div>
                    )}

                    {/* Tab: Workspace Appearance */}
                    {activeTab === 'workspace' && (
                    <div id="workspace-appearance" className="scroll-mt-8">
                        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-200/20 overflow-hidden">
                            <div className="px-6 py-10 sm:px-10 sm:py-12">
                                <div className="mb-1">
                                    <h2 className="text-xl font-semibold text-gray-900">Workspace Appearance</h2>
                                    <p className="mt-3 text-sm text-gray-600 leading-relaxed max-w-xl">
                                        Control how the DAM interface looks for this brand.
                                    </p>
                                </div>
                                <div className="mt-10 grid grid-cols-1 lg:grid-cols-2 gap-12">
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
                                                        onClick={() => setData('workspace_button_style', style)}
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
                                    {/* Sidebar color selection */}
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-900 mb-1">Sidebar color</h4>
                                        <p className="text-sm text-gray-500 mb-4">
                                            Choose a color from your brand palette. Leave empty to use the primary color.
                                        </p>
                                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                            <button
                                                type="button"
                                                onClick={() => setData('nav_color', data.primary_color || '')}
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
                                                <button type="button" onClick={() => setData('nav_color', data.secondary_color)}
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
                                                <button type="button" onClick={() => setData('nav_color', data.accent_color)}
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
                                            <button type="button" onClick={() => setData('nav_color', '')}
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
                                            return (
                                                <div className="rounded-lg border border-gray-200 overflow-hidden bg-gray-50 shadow-inner">
                                                    <div className="flex" style={{ minHeight: 220 }}>
                                                        {/* Sidebar */}
                                                        <aside
                                                            className="w-14 flex flex-col flex-shrink-0"
                                                            style={{ backgroundColor: sidebarColor, color: sidebarTextColor }}
                                                        >
                                                            <div className="p-2 flex items-center justify-center border-b border-current/10">
                                                                {(data.logo_preview ?? brand.logo_thumbnail_url ?? brand.logo_path) ? (
                                                                    <img src={data.logo_preview ?? brand.logo_thumbnail_url ?? brand.logo_path} alt="" className="w-8 h-6 object-contain" />
                                                                ) : (
                                                                    <div className="w-8 h-6 rounded bg-black/10 flex items-center justify-center" style={{ color: 'inherit' }}>
                                                                        <span className="text-[8px] font-medium opacity-90">Logo</span>
                                                                    </div>
                                                                )}
                                                            </div>
                                                            <nav className="flex-1 py-2 space-y-0.5">
                                                                {['Assets', 'Collections', 'Deliverables'].map((label) => (
                                                                    <div key={label} className="px-2 py-1.5 text-[9px] font-medium truncate opacity-90" style={{ color: 'inherit' }}>
                                                                        {label}
                                                                    </div>
                                                                ))}
                                                            </nav>
                                                        </aside>
                                                        {/* Main content */}
                                                        <main className="flex-1 flex flex-col bg-white min-w-0">
                                                            {/* Header row — Add Asset top-left */}
                                                            <div className="flex items-center gap-2 px-3 py-2 border-b border-gray-200 flex-shrink-0">
                                                                <span
                                                                    className="px-2.5 py-1 rounded text-[10px] font-medium text-white"
                                                                    style={{ backgroundColor: btnColor }}
                                                                >
                                                                    Add Asset
                                                                </span>
                                                            </div>
                                                            {/* Search bar + filter row */}
                                                            <div className="px-3 py-2 space-y-2 border-b border-gray-100 flex-shrink-0">
                                                                <div className="h-6 bg-gray-100 rounded w-full max-w-[140px]" />
                                                                <div className="flex gap-1.5">
                                                                    <div className="h-5 w-12 bg-gray-100 rounded" />
                                                                    <div className="h-5 w-10 bg-gray-100 rounded" />
                                                                    <div className="h-5 w-14 bg-gray-100 rounded" />
                                                                </div>
                                                            </div>
                                                            {/* Asset grid blocks */}
                                                            <div className="flex-1 p-3 overflow-hidden">
                                                                <div className="grid grid-cols-4 gap-1.5">
                                                                    {[1, 2, 3, 4, 5, 6, 7, 8].map((i) => (
                                                                        <div key={i} className="aspect-square bg-gray-200 rounded" />
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
                            href="/app/brands"
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
