import { Link } from '@inertiajs/react'

export default function AdminIndex({ companies, stats }) {
    return (
        <div className="min-h-full bg-gray-50">
            <nav className="bg-white shadow-sm">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex items-center">
                            <Link href="/dashboard" className="text-xl font-bold text-gray-900">
                                Site Admin
                            </Link>
                        </div>
                        <div className="flex items-center">
                            <Link
                                href="/dashboard"
                                className="text-sm font-medium text-gray-700 hover:text-gray-900"
                            >
                                Back to Dashboard
                            </Link>
                        </div>
                    </div>
                </div>
            </nav>

            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900">Site Administration</h1>
                    <p className="mt-2 text-sm text-gray-700">Overview of all companies, brands, and users</p>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-3 mb-8">
                    <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                        <dt className="truncate text-sm font-medium text-gray-500">Total Companies</dt>
                        <dd className="mt-1 text-3xl font-semibold tracking-tight text-gray-900">
                            {stats.total_companies}
                        </dd>
                    </div>
                    <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                        <dt className="truncate text-sm font-medium text-gray-500">Total Brands</dt>
                        <dd className="mt-1 text-3xl font-semibold tracking-tight text-gray-900">
                            {stats.total_brands}
                        </dd>
                    </div>
                    <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                        <dt className="truncate text-sm font-medium text-gray-500">Total Users</dt>
                        <dd className="mt-1 text-3xl font-semibold tracking-tight text-gray-900">
                            {stats.total_users}
                        </dd>
                    </div>
                </div>

                {/* Companies List */}
                <div className="bg-white shadow sm:rounded-lg">
                    <div className="px-4 py-5 sm:p-6">
                        <h3 className="text-lg font-medium leading-6 text-gray-900 mb-4">Companies</h3>
                        <div className="space-y-6">
                            {companies.length === 0 ? (
                                <p className="text-sm text-gray-500">No companies found.</p>
                            ) : (
                                companies.map((company) => (
                                    <div
                                        key={company.id}
                                        className="border border-gray-200 rounded-lg p-6"
                                    >
                                        <div className="flex items-center justify-between mb-4">
                                            <div>
                                                <h4 className="text-lg font-semibold text-gray-900">
                                                    {company.name}
                                                </h4>
                                                <p className="text-sm text-gray-500">{company.slug}</p>
                                            </div>
                                            <div className="flex gap-4 text-sm text-gray-600">
                                                <span>
                                                    <span className="font-medium">{company.brands_count}</span> Brands
                                                </span>
                                                <span>
                                                    <span className="font-medium">{company.users_count}</span> Users
                                                </span>
                                            </div>
                                        </div>

                                        {/* Brands */}
                                        <div className="mb-4">
                                            <h5 className="text-sm font-medium text-gray-700 mb-2">Brands</h5>
                                            {company.brands.length === 0 ? (
                                                <p className="text-sm text-gray-500">No brands</p>
                                            ) : (
                                                <div className="flex flex-wrap gap-2">
                                                    {company.brands.map((brand) => (
                                                        <span
                                                            key={brand.id}
                                                            className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ${
                                                                brand.is_default
                                                                    ? 'bg-indigo-100 text-indigo-800'
                                                                    : 'bg-gray-100 text-gray-800'
                                                            }`}
                                                        >
                                                            {brand.name}
                                                            {brand.is_default && (
                                                                <span className="ml-1">(Default)</span>
                                                            )}
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        {/* Users */}
                                        <div>
                                            <h5 className="text-sm font-medium text-gray-700 mb-2">Users</h5>
                                            {company.users.length === 0 ? (
                                                <p className="text-sm text-gray-500">No users</p>
                                            ) : (
                                                <div className="space-y-1">
                                                    {company.users.map((user) => (
                                                        <div
                                                            key={user.id}
                                                            className="text-sm text-gray-600"
                                                        >
                                                            <span className="font-medium">{user.name}</span>
                                                            <span className="text-gray-400 mx-2">•</span>
                                                            <span>{user.email}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </main>
        </div>
    )
}
