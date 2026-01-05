import { Link, router } from '@inertiajs/react'
import { useForm } from '@inertiajs/react'
import PlanLimitIndicator from '../../Components/PlanLimitIndicator'

export default function BrandsIndex({ brands, limits }) {
    const { post, processing } = useForm()

    const handleDelete = (brandId) => {
        if (confirm('Are you sure you want to delete this brand?')) {
            router.delete(`/brands/${brandId}`, {
                preserveScroll: true,
            })
        }
    }

    const formatLimit = (limit) => {
        if (limit === Number.MAX_SAFE_INTEGER || limit === 2147483647) {
            return 'Unlimited'
        }
        return limit
    }

    return (
        <div className="min-h-full bg-gray-50">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8 flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Brands</h1>
                        <p className="mt-2 text-sm text-gray-700">Manage your brands</p>
                    </div>
                    {limits.can_create ? (
                        <Link
                            href="/brands/create"
                            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        >
                            Create Brand
                        </Link>
                    ) : (
                        <div className="text-sm text-gray-500">
                            Limit reached ({limits.current}/{formatLimit(limits.max)})
                            <Link
                                href="/billing"
                                className="ml-2 font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                Upgrade →
                            </Link>
                        </div>
                    )}
                </div>

                {/* Limit Indicator */}
                <PlanLimitIndicator
                    current={limits.current}
                    max={limits.max}
                    label="Brands"
                    upgradeUrl="/billing"
                />

                {/* Brands List */}
                <div className="overflow-hidden bg-white shadow sm:rounded-md">
                    <ul className="divide-y divide-gray-200">
                        {brands?.length === 0 ? (
                            <li className="px-6 py-4 text-center text-sm text-gray-500">
                                No brands yet. Create your first brand to get started.
                            </li>
                        ) : (
                            brands?.map((brand) => (
                                <li key={brand.id} className="px-6 py-4">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center">
                                            {brand.logo_path ? (
                                                <img
                                                    src={brand.logo_path}
                                                    alt={brand.name}
                                                    className="h-10 w-10 rounded-full object-cover border border-gray-200"
                                                />
                                            ) : (
                                                <div
                                                    className="flex h-10 w-10 items-center justify-center rounded-full"
                                                    style={{
                                                        backgroundColor: brand.primary_color || '#6366f1',
                                                    }}
                                                >
                                                    <span className="text-sm font-medium text-white">
                                                        {brand.name.charAt(0).toUpperCase()}
                                                    </span>
                                                </div>
                                            )}
                                            <div className="ml-4">
                                                <div className="flex items-center">
                                                    <p className="text-sm font-medium text-gray-900">{brand.name}</p>
                                                    {brand.is_default && (
                                                        <span className="ml-2 rounded-full bg-indigo-100 px-2 py-1 text-xs font-medium text-indigo-800">
                                                            Default
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="text-sm text-gray-500">{brand.slug}</p>
                                                {(brand.primary_color || brand.secondary_color || brand.accent_color) && (
                                                    <div className="mt-1 flex gap-1">
                                                        {brand.primary_color && (
                                                            <div
                                                                className="h-3 w-3 rounded-full border border-gray-300"
                                                                style={{ backgroundColor: brand.primary_color }}
                                                                title="Primary Color"
                                                            />
                                                        )}
                                                        {brand.secondary_color && (
                                                            <div
                                                                className="h-3 w-3 rounded-full border border-gray-300"
                                                                style={{ backgroundColor: brand.secondary_color }}
                                                                title="Secondary Color"
                                                            />
                                                        )}
                                                        {brand.accent_color && (
                                                            <div
                                                                className="h-3 w-3 rounded-full border border-gray-300"
                                                                style={{ backgroundColor: brand.accent_color }}
                                                                title="Accent Color"
                                                            />
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex gap-2">
                                            <Link
                                                href={`/brands/${brand.id}/edit`}
                                                className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                            >
                                                Edit
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={() => handleDelete(brand.id)}
                                                disabled={processing || brand.is_default}
                                                className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-red-300 hover:bg-red-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </li>
                            ))
                        )}
                    </ul>
                </div>
            </div>
        </div>
    )
}
