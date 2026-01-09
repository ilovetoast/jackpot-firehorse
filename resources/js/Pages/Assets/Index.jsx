import { useState } from 'react'
import { usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AddAssetButton from '../../Components/AddAssetButton'
import {
    FolderIcon,
    TagIcon,
    LockClosedIcon,
} from '@heroicons/react/24/outline'
import { CategoryIcon } from '../../Helpers/categoryIcons'

export default function AssetsIndex({ categories, categories_by_type, selected_category, show_all_button = false }) {
    const { auth } = usePage().props
    const [selectedCategoryId, setSelectedCategoryId] = useState(selected_category ? parseInt(selected_category) : null)
    const [tooltipVisible, setTooltipVisible] = useState(null)

    // Get brand sidebar color (nav_color) for sidebar background, fallback to primary color
    const sidebarColor = auth.activeBrand?.nav_color || auth.activeBrand?.primary_color || '#1f2937' // Default to gray-800 if no brand color
    const isLightColor = (color) => {
        if (!color || color === '#ffffff' || color === '#FFFFFF') return true
        const hex = color.replace('#', '')
        const r = parseInt(hex.substr(0, 2), 16)
        const g = parseInt(hex.substr(2, 2), 16)
        const b = parseInt(hex.substr(4, 2), 16)
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255
        return luminance > 0.5
    }
    const textColor = isLightColor(sidebarColor) ? '#000000' : '#ffffff'
    const hoverBgColor = isLightColor(sidebarColor) ? 'rgba(0, 0, 0, 0.1)' : 'rgba(255, 255, 255, 0.1)'
    const activeBgColor = isLightColor(sidebarColor) ? 'rgba(0, 0, 0, 0.2)' : 'rgba(255, 255, 255, 0.2)'

    return (
        <div className="h-screen flex flex-col overflow-hidden">
            <AppNav brand={auth.activeBrand} tenant={null} />
            
            <div className="flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                {/* Sidebar - Full Height */}
                <div className="hidden lg:flex lg:flex-shrink-0">
                    <div className="flex flex-col w-72 h-full" style={{ backgroundColor: sidebarColor }}>
                        <div className="flex-1 flex flex-col pt-5 pb-4 overflow-y-auto">
                            <nav className="mt-5 flex-1 px-2 space-y-1">
                                {/* Categories */}
                                <div className="px-3 py-2">
                                    <h3 className="px-3 text-xs font-semibold uppercase tracking-wider" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}>
                                        Categories
                                    </h3>
                                    <div className="mt-2 space-y-1">
                                        {/* "All" button - only shown for non-free plans */}
                                        {show_all_button && (
                                            <button
                                                onClick={() => setSelectedCategoryId(null)}
                                                className="group flex items-center px-3 py-2 text-sm font-medium rounded-md w-full text-left"
                                                style={{
                                                    backgroundColor: selectedCategoryId === null || selectedCategoryId === undefined ? activeBgColor : 'transparent',
                                                    color: textColor,
                                                }}
                                                onMouseEnter={(e) => {
                                                    if (selectedCategoryId !== null && selectedCategoryId !== undefined) {
                                                        e.currentTarget.style.backgroundColor = hoverBgColor
                                                    }
                                                }}
                                                onMouseLeave={(e) => {
                                                    if (selectedCategoryId !== null && selectedCategoryId !== undefined) {
                                                        e.currentTarget.style.backgroundColor = 'transparent'
                                                    }
                                                }}
                                            >
                                                <TagIcon className="mr-3 flex-shrink-0 h-5 w-5" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }} />
                                                All
                                            </button>
                                        )}
                                        {/* Show all categories (both basic and marketing) in a single list */}
                                        {categories && categories.length > 0 ? (
                                            categories
                                                .filter(category => category.id != null && category.id !== undefined && category.id !== 0)
                                                .map((category) => {
                                                    const isSelected = selectedCategoryId === category.id && selectedCategoryId !== null && selectedCategoryId !== undefined
                                                    return (
                                                    <button
                                                        key={category.id}
                                                        onClick={() => setSelectedCategoryId(category.id)}
                                                        className="group flex items-center px-3 py-2 text-sm font-medium rounded-md w-full text-left"
                                                        style={{
                                                            backgroundColor: isSelected ? activeBgColor : 'transparent',
                                                            color: textColor,
                                                        }}
                                                        onMouseEnter={(e) => {
                                                            if (!isSelected) {
                                                                e.currentTarget.style.backgroundColor = hoverBgColor
                                                            }
                                                        }}
                                                        onMouseLeave={(e) => {
                                                            if (!isSelected) {
                                                                e.currentTarget.style.backgroundColor = 'transparent'
                                                            }
                                                        }}
                                                    >
                                                        <CategoryIcon 
                                                            iconId={category.icon || 'folder'} 
                                                            className="mr-3 flex-shrink-0 h-5 w-5" 
                                                            style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}
                                                        />
                                                        <span className="flex-1">{category.name}</span>
                                                        {category.is_private && (
                                                            <div className="relative ml-2 group">
                                                                <LockClosedIcon 
                                                                    className="h-4 w-4 flex-shrink-0 cursor-help" 
                                                                    style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}
                                                                    onMouseEnter={() => setTooltipVisible(category.id)}
                                                                    onMouseLeave={() => setTooltipVisible(null)}
                                                                />
                                                                {tooltipVisible === category.id && (
                                                                    <div 
                                                                        className="absolute right-full mr-2 top-1/2 transform -translate-y-1/2 bg-gray-900 text-white text-xs rounded-lg shadow-xl z-[9999] pointer-events-none whitespace-normal"
                                                                        style={{
                                                                            transform: 'translateY(-50%)',
                                                                            width: '250px',
                                                                        }}
                                                                    >
                                                                        <div className="p-3">
                                                                            <div className="font-semibold mb-2.5 text-white">Restricted Category</div>
                                                                            <div className="space-y-2">
                                                                                <div className="text-gray-200">Accessible by:</div>
                                                                                <ul className="list-disc list-outside ml-4 space-y-1 text-gray-200">
                                                                                    <li>Owners</li>
                                                                                    <li>Admins</li>
                                                                                    {category.access_rules && category.access_rules.length > 0 && category.access_rules
                                                                                        .filter(rule => rule.type === 'role')
                                                                                        .map((rule, idx) => (
                                                                                            <li key={idx} className="capitalize">{rule.role.replace('_', ' ')}</li>
                                                                                        ))
                                                                                    }
                                                                                </ul>
                                                                            </div>
                                                                        </div>
                                                                        <div className="absolute left-full top-1/2 transform -translate-y-1/2 w-0 h-0 border-t-[6px] border-b-[6px] border-l-[6px] border-transparent border-l-gray-900"></div>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        )}
                                                    </button>
                                                    )
                                                })
                                        ) : (
                                            <div className="px-3 py-2 text-sm" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}>
                                                No categories yet
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </nav>
                        </div>
                    </div>
                </div>

                {/* Main Content - Full Height with Scroll */}
                <div className="flex-1 overflow-y-auto bg-gray-50 h-full">
                    <div className="py-6 px-4 sm:px-6 lg:px-8">
                        {/* Assets Content - Empty State */}
                        <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                            <div className="mb-8">
                                <FolderIcon className="mx-auto h-16 w-16 text-gray-300" />
                            </div>
                            <h2 className="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                {selectedCategoryId ? 'No assets in this category yet' : 'No assets yet'}
                            </h2>
                            <p className="mt-4 text-base leading-7 text-gray-600">
                                {selectedCategoryId
                                    ? 'Get started by uploading your first asset to this category. Organize your brand assets and keep everything in one place.'
                                    : 'Get started by selecting a category or uploading your first asset. Organize your brand assets and keep everything in sync.'}
                            </p>
                            <div className="mt-8">
                                <AddAssetButton 
                                    defaultAssetType="basic" 
                                    categories={categories || []}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
