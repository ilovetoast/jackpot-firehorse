import { useState } from 'react'
import { Link, router } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import {
    BuildingOffice2Icon as BuildingOfficeIcon,
    MagnifyingGlassIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    CheckCircleIcon,
    ClockIcon,
    TrophyIcon,
    ArrowLeftIcon,
} from '@heroicons/react/24/outline'

/**
 * Admin Agencies Index
 * 
 * Phase AG-11 — Admin Agency Management & Oversight
 * 
 * Lists all agencies with filtering and search.
 * READ-ONLY list view.
 */
export default function AdminAgenciesIndex({ agencies, pagination, tiers, filters }) {
    const [search, setSearch] = useState(filters.search || '')
    const [tierFilter, setTierFilter] = useState(filters.tier || '')
    const [approvedFilter, setApprovedFilter] = useState(filters.approved || '')

    const handleSearch = (e) => {
        e.preventDefault()
        router.get('/app/admin/agencies', {
            search,
            tier: tierFilter,
            approved: approvedFilter,
        }, { preserveState: true })
    }

    const handleFilterChange = (key, value) => {
        const params = {
            search,
            tier: tierFilter,
            approved: approvedFilter,
            [key]: value,
        }
        router.get('/app/admin/agencies', params, { preserveState: true })
    }

    return (
        <>
            <AppNav />
            
            <div className="min-h-screen bg-gray-50 py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="mb-6">
                        <Link 
                            href="/app/admin" 
                            className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4"
                        >
                            <ArrowLeftIcon className="h-4 w-4 mr-1" />
                            Back to Admin Dashboard
                        </Link>
                        <h1 className="text-3xl font-bold text-gray-900">Agency Management</h1>
                        <p className="mt-2 text-sm text-gray-600">
                            View and manage agency partners
                        </p>
                    </div>

                    {/* Filters */}
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
                        <form onSubmit={handleSearch} className="flex flex-wrap gap-4">
                            {/* Search */}
                            <div className="flex-1 min-w-[200px]">
                                <label htmlFor="search" className="sr-only">Search</label>
                                <div className="relative">
                                    <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" />
                                    <input
                                        type="text"
                                        id="search"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder="Search agencies..."
                                        className="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                    />
                                </div>
                            </div>

                            {/* Tier Filter */}
                            <div className="w-40">
                                <label htmlFor="tier" className="sr-only">Tier</label>
                                <select
                                    id="tier"
                                    value={tierFilter}
                                    onChange={(e) => {
                                        setTierFilter(e.target.value)
                                        handleFilterChange('tier', e.target.value)
                                    }}
                                    className="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                                >
                                    <option value="">All Tiers</option>
                                    {tiers.map((tier) => (
                                        <option key={tier.id} value={tier.id}>{tier.name}</option>
                                    ))}
                                </select>
                            </div>

                            {/* Approval Filter */}
                            <div className="w-40">
                                <label htmlFor="approved" className="sr-only">Approval Status</label>
                                <select
                                    id="approved"
                                    value={approvedFilter}
                                    onChange={(e) => {
                                        setApprovedFilter(e.target.value)
                                        handleFilterChange('approved', e.target.value)
                                    }}
                                    className="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                                >
                                    <option value="">All Status</option>
                                    <option value="true">Approved</option>
                                    <option value="false">Pending</option>
                                </select>
                            </div>

                            <button
                                type="submit"
                                className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                            >
                                Search
                            </button>
                        </form>
                    </div>

                    {/* Agencies Table */}
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Agency
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Tier
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Activated
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Incubated
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Pending
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Created
                                    </th>
                                    <th scope="col" className="relative px-6 py-3">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {agencies.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="px-6 py-12 text-center text-gray-500">
                                            <BuildingOfficeIcon className="h-12 w-12 mx-auto mb-3 text-gray-300" />
                                            <p>No agencies found</p>
                                        </td>
                                    </tr>
                                ) : (
                                    agencies.map((agency) => (
                                        <tr key={agency.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="flex items-center">
                                                    <div className="flex-shrink-0 h-10 w-10 bg-indigo-100 rounded-lg flex items-center justify-center">
                                                        <BuildingOfficeIcon className="h-6 w-6 text-indigo-600" />
                                                    </div>
                                                    <div className="ml-4">
                                                        <div className="text-sm font-medium text-gray-900">{agency.name}</div>
                                                        <div className="text-sm text-gray-500">{agency.slug}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                                    <TrophyIcon className="h-3 w-3 mr-1" />
                                                    {agency.tier}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <span className="flex items-center">
                                                    <CheckCircleIcon className="h-4 w-4 text-green-500 mr-1" />
                                                    {agency.activated_clients}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {agency.incubated_clients}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {agency.pending_transfers > 0 ? (
                                                    <span className="flex items-center text-yellow-600">
                                                        <ClockIcon className="h-4 w-4 mr-1" />
                                                        {agency.pending_transfers}
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-500">0</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {agency.is_approved ? (
                                                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                        Approved
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                        Pending
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {agency.created_at}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <Link
                                                    href={`/app/admin/agencies/${agency.id}`}
                                                    className="text-indigo-600 hover:text-indigo-900"
                                                >
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {pagination.last_page > 1 && (
                        <div className="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6 mt-4 rounded-lg shadow-sm">
                            <div className="flex-1 flex justify-between sm:hidden">
                                <button
                                    onClick={() => router.get(`/app/admin/agencies?page=${pagination.current_page - 1}`)}
                                    disabled={pagination.current_page === 1}
                                    className="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
                                >
                                    Previous
                                </button>
                                <button
                                    onClick={() => router.get(`/app/admin/agencies?page=${pagination.current_page + 1}`)}
                                    disabled={pagination.current_page === pagination.last_page}
                                    className="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
                                >
                                    Next
                                </button>
                            </div>
                            <div className="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                <div>
                                    <p className="text-sm text-gray-700">
                                        Showing page <span className="font-medium">{pagination.current_page}</span> of{' '}
                                        <span className="font-medium">{pagination.last_page}</span>
                                        {' '}({pagination.total} total agencies)
                                    </p>
                                </div>
                                <div>
                                    <nav className="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                        <button
                                            onClick={() => router.get(`/app/admin/agencies?page=${pagination.current_page - 1}`)}
                                            disabled={pagination.current_page === 1}
                                            className="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                                        >
                                            <ChevronLeftIcon className="h-5 w-5" />
                                        </button>
                                        <button
                                            onClick={() => router.get(`/app/admin/agencies?page=${pagination.current_page + 1}`)}
                                            disabled={pagination.current_page === pagination.last_page}
                                            className="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                                        >
                                            <ChevronRightIcon className="h-5 w-5" />
                                        </button>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <AppFooter />
        </>
    )
}
