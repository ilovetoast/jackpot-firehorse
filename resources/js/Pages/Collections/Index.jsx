import { useState } from 'react'
import { usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import {
    RectangleStackIcon as CollectionIcon,
    FolderIcon,
} from '@heroicons/react/24/outline'

export default function CollectionsIndex({ collections = [] }) {
    const { auth } = usePage().props

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

    return (
        <div className="h-screen flex flex-col overflow-hidden">
            <AppNav brand={auth.activeBrand} tenant={null} />
            
            <div className="flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                {/* Sidebar - Full Height */}
                <div className="hidden lg:flex lg:flex-shrink-0">
                    <div className="flex flex-col w-72 h-full" style={{ backgroundColor: sidebarColor }}>
                        <div className="flex-1 flex flex-col pt-5 pb-4 overflow-y-auto">
                            <nav className="mt-5 flex-1 px-2 space-y-1">
                                {/* Collections Actions - Placeholder for future */}
                                {auth?.user && (
                                    <div className="px-3 py-2 mb-4">
                                        <button 
                                            className="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                                            disabled
                                        >
                                            <CollectionIcon className="h-4 w-4 mr-2" />
                                            Create Collection
                                        </button>
                                    </div>
                                )}
                                
                                {/* No categories section for Collections - future enhancement */}
                                <div className="px-3 py-2">
                                    <h3 className="px-3 text-xs font-semibold uppercase tracking-wider" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}>
                                        Collections
                                    </h3>
                                    <div className="mt-2 space-y-1">
                                        <div className="px-3 py-2 text-sm" style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)' }}>
                                            No collections yet
                                        </div>
                                    </div>
                                </div>
                            </nav>
                        </div>
                    </div>
                </div>

                {/* Main Content - Full Height with Scroll */}
                <div className="flex-1 overflow-hidden bg-gray-50 h-full relative">
                    <div className="h-full overflow-y-auto">
                        <div className="py-6 px-4 sm:px-6 lg:px-8">
                            {/* Temporary placeholder content */}
                            <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                <div className="mb-8">
                                    <CollectionIcon className="mx-auto h-16 w-16 text-gray-300" />
                                </div>
                                <h2 className="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                    Collections - TMP
                                </h2>
                                <p className="mt-4 text-base leading-7 text-gray-600">
                                    Temporary placeholder page for Collections feature. This page will be developed in a future phase.
                                </p>
                                <div className="mt-8">
                                    <p className="text-sm text-gray-500">
                                        Coming Soon: Organize and manage your asset collections
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}