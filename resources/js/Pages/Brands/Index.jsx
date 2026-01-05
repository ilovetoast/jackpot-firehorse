import { Link, router, usePage } from '@inertiajs/react'
import { useForm } from '@inertiajs/react'
import { useState } from 'react'
import PlanLimitIndicator from '../../Components/PlanLimitIndicator'
import AppNav from '../../Components/AppNav'

export default function BrandsIndex({ brands, limits }) {
    const { auth } = usePage().props
    const { post, processing } = useForm()
    const [expandedBrand, setExpandedBrand] = useState(null)

    const handleDelete = (brandId, brandName) => {
        if (confirm(`Are you sure you want to delete "${brandName}"?`)) {
            router.delete(`/app/brands/${brandId}`, {
                preserveScroll: true,
            })
        }
    }

    const toggleExpand = (brandId) => {
        setExpandedBrand(expandedBrand === brandId ? null : brandId)
    }

    return (
        <div className="min-h-full flex flex-col">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="flex-1 bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8 flex items-center justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-gray-900">Brands</h1>
                            <p className="mt-2 text-sm text-gray-700">Manage your brands</p>
                        </div>
                        {limits.can_create && (
                            <Link
                                href="/app/brands/create"
                                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                            >
                                Create Brand
                            </Link>
                        )}
                    </div>

                    {/* Limit Indicator */}
                    {!limits.can_create && (
                        <div className="mb-6">
                            <PlanLimitIndicator
                                current={limits.current}
                                max={limits.max}
                                label="Brands"
                            />
                        </div>
                    )}

                    {/* Brands List */}
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                        {brands.length === 0 ? (
                            <div className="text-center py-12">
                                <p className="text-sm text-gray-500">No brands found. Create your first one!</p>
                            </div>
                        ) : (
                            <ul className="divide-y divide-gray-200">
                                {brands.map((brand) => (
                                    <li key={brand.id} className="hover:bg-gray-50 transition-colors">
                                        {/* Brand Row */}
                                        <div
                                            className="flex items-center px-6 py-4 cursor-pointer"
                                            onClick={() => toggleExpand(brand.id)}
                                        >
                                            {/* Logo */}
                                            <div className="flex-shrink-0 mr-4">
                                                {brand.logo_path ? (
                                                    <img
                                                        src={brand.logo_path}
                                                        alt={brand.name}
                                                        className="h-12 w-12 rounded-full object-cover"
                                                    />
                                                ) : (
                                                    <div className="h-12 w-12 rounded-full bg-indigo-600 flex items-center justify-center text-white font-semibold text-lg">
                                                        {brand.name.charAt(0).toUpperCase()}
                                                    </div>
                                                )}
                                            </div>

                                            {/* Brand Info */}
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <p className="text-sm font-semibold text-gray-900 truncate">
                                                        {brand.name}
                                                    </p>
                                                    {brand.is_default && (
                                                        <span className="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">
                                                            Default
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="mt-1 flex items-center gap-4 text-sm text-gray-500">
                                                    {brand.categories && brand.categories.length > 0 && (
                                                        <span>{brand.categories.length} categories</span>
                                                    )}
                                                    {brand.users && brand.users.length > 0 && (
                                                        <span>{brand.users.length} users</span>
                                                    )}
                                                </div>
                                            </div>

                                            {/* Edit Button */}
                                            <div className="flex-shrink-0 ml-4">
                                                <Link
                                                    href={`/app/brands/${brand.id}/edit`}
                                                    onClick={(e) => e.stopPropagation()}
                                                    className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                                >
                                                    Edit
                                                </Link>
                                            </div>

                                            {/* Expand/Collapse Icon */}
                                            <div className="flex-shrink-0 ml-4">
                                                <svg
                                                    className={`h-5 w-5 text-gray-400 transition-transform ${
                                                        expandedBrand === brand.id ? 'rotate-180' : ''
                                                    }`}
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fillRule="evenodd"
                                                        d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                                                        clipRule="evenodd"
                                                    />
                                                </svg>
                                            </div>
                                        </div>

                                        {/* Expanded Content */}
                                        {expandedBrand === brand.id && (
                                            <div className="px-6 py-4 bg-gray-50 border-t border-gray-200">
                                                <div className="space-y-6">
                                                    {/* Branding Section */}
                                                    <div>
                                                        <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                                            Branding
                                                        </h3>
                                                        <dl className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2">
                                                            <div>
                                                                <dt className="text-sm font-medium text-gray-500">Brand Name</dt>
                                                                <dd className="mt-1 text-sm text-gray-900">{brand.name}</dd>
                                                            </div>
                                                            {brand.primary_color && (
                                                                <div>
                                                                    <dt className="text-sm font-medium text-gray-500">Primary Color</dt>
                                                                    <dd className="mt-1 flex items-center gap-2">
                                                                        <div
                                                                            className="h-6 w-6 rounded border border-gray-300"
                                                                            style={{ backgroundColor: brand.primary_color }}
                                                                        />
                                                                        <span className="text-sm text-gray-900">{brand.primary_color}</span>
                                                                    </dd>
                                                                </div>
                                                            )}
                                                            {brand.secondary_color && (
                                                                <div>
                                                                    <dt className="text-sm font-medium text-gray-500">Secondary Color</dt>
                                                                    <dd className="mt-1 flex items-center gap-2">
                                                                        <div
                                                                            className="h-6 w-6 rounded border border-gray-300"
                                                                            style={{ backgroundColor: brand.secondary_color }}
                                                                        />
                                                                        <span className="text-sm text-gray-900">{brand.secondary_color}</span>
                                                                    </dd>
                                                                </div>
                                                            )}
                                                            {brand.accent_color && (
                                                                <div>
                                                                    <dt className="text-sm font-medium text-gray-500">Accent Color</dt>
                                                                    <dd className="mt-1 flex items-center gap-2">
                                                                        <div
                                                                            className="h-6 w-6 rounded border border-gray-300"
                                                                            style={{ backgroundColor: brand.accent_color }}
                                                                        />
                                                                        <span className="text-sm text-gray-900">{brand.accent_color}</span>
                                                                    </dd>
                                                                </div>
                                                            )}
                                                        </dl>
                                                    </div>

                                                    {/* Categories Section */}
                                                    <div>
                                                        <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                                            Categories
                                                        </h3>
                                                        {brand.categories && brand.categories.length > 0 ? (
                                                            <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                                                                <ul className="divide-y divide-gray-200">
                                                                    {brand.categories.map((category) => (
                                                                        <li key={category.id} className="px-4 py-3">
                                                                            <div className="flex items-center justify-between">
                                                                                <div>
                                                                                    <p className="text-sm font-medium text-gray-900">
                                                                                        {category.name}
                                                                                    </p>
                                                                                    <div className="mt-1 flex items-center gap-2 text-xs text-gray-500">
                                                                                        <span>{category.asset_type}</span>
                                                                                        {category.is_system && (
                                                                                            <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800">
                                                                                                System
                                                                                            </span>
                                                                                        )}
                                                                                        {category.is_private && (
                                                                                            <span className="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">
                                                                                                Private
                                                                                            </span>
                                                                                        )}
                                                                                        {category.is_locked && (
                                                                                            <span className="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">
                                                                                                Locked
                                                                                            </span>
                                                                                        )}
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </li>
                                                                    ))}
                                                                </ul>
                                                            </div>
                                                        ) : (
                                                            <p className="text-sm text-gray-500">No categories yet.</p>
                                                        )}
                                                    </div>

                                                    {/* Users Section */}
                                                    <div>
                                                        <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                                            Users
                                                        </h3>
                                                        {brand.users && brand.users.length > 0 ? (
                                                            <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                                                                <ul className="divide-y divide-gray-200">
                                                                    {brand.users.map((user) => (
                                                                        <li key={user.id} className="px-4 py-3">
                                                                            <div className="flex items-center">
                                                                                <div className="flex-shrink-0 mr-4">
                                                                                    <div className="h-10 w-10 rounded-full bg-indigo-600 flex items-center justify-center text-white font-semibold">
                                                                                        {user.first_name?.charAt(0).toUpperCase() || user.email?.charAt(0).toUpperCase()}
                                                                                    </div>
                                                                                </div>
                                                                                <div className="flex-1 min-w-0">
                                                                                    <p className="text-sm font-medium text-gray-900">
                                                                                        {user.name || user.email}
                                                                                    </p>
                                                                                    <p className="text-sm text-gray-500 truncate">{user.email}</p>
                                                                                </div>
                                                                                <div className="flex-shrink-0 ml-4">
                                                                                    {user.role && (
                                                                                        <span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                                                                                            {user.role}
                                                                                        </span>
                                                                                    )}
                                                                                </div>
                                                                            </div>
                                                                        </li>
                                                                    ))}
                                                                </ul>
                                                            </div>
                                                        ) : (
                                                            <p className="text-sm text-gray-500">No users assigned to this brand yet.</p>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </main>
        </div>
    )
}
