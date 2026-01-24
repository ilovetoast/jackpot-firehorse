import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout'
import { 
    ExclamationTriangleIcon, 
    ExclamationCircleIcon,
    InformationCircleIcon,
    CheckCircleIcon,
    ArrowPathIcon,
    TrashIcon,
    EyeIcon,
    MagnifyingGlassIcon,
    FunnelIcon,
} from '@heroicons/react/24/outline'

export default function DeletionErrors({ auth, errors, filters, errorTypeCounts }) {
    const [search, setSearch] = useState(filters.search || '')
    const [processing, setProcessing] = useState({})

    const getSeverityIcon = (severity) => {
        switch (severity) {
            case 'critical':
                return <ExclamationTriangleIcon className="h-5 w-5 text-red-600" />
            case 'error':
                return <ExclamationCircleIcon className="h-5 w-5 text-red-500" />
            case 'warning':
                return <InformationCircleIcon className="h-5 w-5 text-yellow-500" />
            default:
                return <ExclamationCircleIcon className="h-5 w-5 text-gray-500" />
        }
    }

    const getSeverityBadgeColor = (severity) => {
        switch (severity) {
            case 'critical':
                return 'bg-red-100 text-red-800 ring-red-600/20'
            case 'error':
                return 'bg-red-50 text-red-700 ring-red-600/10'
            case 'warning':
                return 'bg-yellow-50 text-yellow-700 ring-yellow-600/20'
            default:
                return 'bg-gray-50 text-gray-700 ring-gray-600/20'
        }
    }

    const handleFilter = (key, value) => {
        router.get(route('deletion-errors.index'), {
            ...filters,
            [key]: value,
            page: 1, // Reset to first page
        })
    }

    const handleSearch = (e) => {
        e.preventDefault()
        handleFilter('search', search)
    }

    const handleResolve = async (errorId, notes = null) => {
        if (processing[errorId]) return

        setProcessing(prev => ({ ...prev, [errorId]: true }))

        try {
            await router.post(route('deletion-errors.resolve', errorId), {
                resolution_notes: notes,
            })
        } finally {
            setProcessing(prev => ({ ...prev, [errorId]: false }))
        }
    }

    const handleRetry = async (errorId) => {
        if (processing[errorId]) return

        setProcessing(prev => ({ ...prev, [errorId]: true }))

        try {
            await router.post(route('deletion-errors.retry', errorId))
        } finally {
            setProcessing(prev => ({ ...prev, [errorId]: false }))
        }
    }

    const formatErrorType = (type) => {
        return type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
    }

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        Deletion Errors
                    </h2>
                    <div className="flex items-center gap-2">
                        {Object.keys(errorTypeCounts).length > 0 && (
                            <span className="text-sm text-gray-500">
                                {Object.values(errorTypeCounts).reduce((a, b) => a + b, 0)} unresolved errors
                            </span>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Deletion Errors" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {/* Filters */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                        <div className="p-6 border-b border-gray-200">
                            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                {/* Search */}
                                <form onSubmit={handleSearch} className="flex-1 max-w-md">
                                    <div className="relative">
                                        <MagnifyingGlassIcon className="h-5 w-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400" />
                                        <input
                                            type="text"
                                            placeholder="Search by filename..."
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                                        />
                                    </div>
                                </form>

                                {/* Status Filter */}
                                <div className="flex items-center gap-2">
                                    <FunnelIcon className="h-5 w-5 text-gray-400" />
                                    <select
                                        value={filters.status || 'unresolved'}
                                        onChange={(e) => handleFilter('status', e.target.value)}
                                        className="border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                        <option value="unresolved">Unresolved</option>
                                        <option value="resolved">Resolved</option>
                                        <option value="">All</option>
                                    </select>
                                </div>

                                {/* Error Type Filter */}
                                {Object.keys(errorTypeCounts).length > 0 && (
                                    <select
                                        value={filters.error_type || ''}
                                        onChange={(e) => handleFilter('error_type', e.target.value)}
                                        className="border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                        <option value="">All Error Types</option>
                                        {Object.entries(errorTypeCounts).map(([type, count]) => (
                                            <option key={type} value={type}>
                                                {formatErrorType(type)} ({count})
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Errors List */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        {errors.data.length === 0 ? (
                            <div className="p-12 text-center">
                                <CheckCircleIcon className="mx-auto h-12 w-12 text-green-500" />
                                <h3 className="mt-2 text-sm font-medium text-gray-900">No deletion errors</h3>
                                <p className="mt-1 text-sm text-gray-500">
                                    {filters.status === 'resolved' ? 
                                        'No resolved deletion errors found.' : 
                                        'Great! No pending deletion errors to resolve.'
                                    }
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y divide-gray-200">
                                {errors.data.map((error) => (
                                    <div key={error.id} className="p-6">
                                        <div className="flex items-start justify-between">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-3 mb-2">
                                                    {getSeverityIcon(error.severity_level)}
                                                    <h3 className="text-lg font-medium text-gray-900">
                                                        {error.original_filename}
                                                    </h3>
                                                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ring-1 ring-inset ${getSeverityBadgeColor(error.severity_level)}`}>
                                                        {error.severity_level}
                                                    </span>
                                                </div>
                                                
                                                <p className="text-sm text-gray-600 mb-2">
                                                    {error.user_friendly_message}
                                                </p>
                                                
                                                <div className="flex items-center gap-4 text-sm text-gray-500">
                                                    <span>Asset ID: {error.asset_id}</span>
                                                    <span>Attempts: {error.attempts}</span>
                                                    <span>
                                                        {new Date(error.created_at).toLocaleDateString()} at{' '}
                                                        {new Date(error.created_at).toLocaleTimeString()}
                                                    </span>
                                                </div>

                                                {error.resolved_at && (
                                                    <div className="mt-2 p-3 bg-green-50 rounded-md">
                                                        <p className="text-sm text-green-800">
                                                            <CheckCircleIcon className="inline h-4 w-4 mr-1" />
                                                            Resolved on {new Date(error.resolved_at).toLocaleDateString()}
                                                            {error.resolver && ` by ${error.resolver.name}`}
                                                        </p>
                                                        {error.resolution_notes && (
                                                            <p className="text-sm text-green-700 mt-1">
                                                                {error.resolution_notes}
                                                            </p>
                                                        )}
                                                    </div>
                                                )}
                                            </div>

                                            {!error.resolved_at && (
                                                <div className="flex items-center gap-2 ml-4">
                                                    <Link
                                                        href={route('deletion-errors.show', error.id)}
                                                        className="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                                    >
                                                        <EyeIcon className="h-4 w-4 mr-1" />
                                                        Details
                                                    </Link>
                                                    
                                                    <button
                                                        onClick={() => handleRetry(error.id)}
                                                        disabled={processing[error.id]}
                                                        className="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                                                    >
                                                        <ArrowPathIcon className={`h-4 w-4 mr-1 ${processing[error.id] ? 'animate-spin' : ''}`} />
                                                        Retry
                                                    </button>
                                                    
                                                    <button
                                                        onClick={() => handleResolve(error.id, 'Manually resolved via admin interface')}
                                                        disabled={processing[error.id]}
                                                        className="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
                                                    >
                                                        <CheckCircleIcon className="h-4 w-4 mr-1" />
                                                        Resolve
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Pagination */}
                        {errors.data.length > 0 && (
                            <div className="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                                <div className="flex items-center justify-between">
                                    <div className="flex-1 flex justify-between sm:hidden">
                                        {errors.prev_page_url && (
                                            <Link
                                                href={errors.prev_page_url}
                                                className="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                                            >
                                                Previous
                                            </Link>
                                        )}
                                        {errors.next_page_url && (
                                            <Link
                                                href={errors.next_page_url}
                                                className="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                                            >
                                                Next
                                            </Link>
                                        )}
                                    </div>
                                    <div className="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                        <div>
                                            <p className="text-sm text-gray-700">
                                                Showing <span className="font-medium">{errors.from}</span> to{' '}
                                                <span className="font-medium">{errors.to}</span> of{' '}
                                                <span className="font-medium">{errors.total}</span> errors
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    )
}