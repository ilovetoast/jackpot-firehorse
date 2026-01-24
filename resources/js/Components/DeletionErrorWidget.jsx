import { useState, useEffect } from 'react'
import { Link } from '@inertiajs/react'
import { 
    ExclamationTriangleIcon, 
    ExclamationCircleIcon,
    ChevronRightIcon,
} from '@heroicons/react/24/outline'

export default function DeletionErrorWidget() {
    const [stats, setStats] = useState(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)

    useEffect(() => {
        fetchStats()
    }, [])

    const fetchStats = async () => {
        try {
            const response = await fetch(route('deletion-errors.stats'))
            if (!response.ok) {
                throw new Error('Failed to fetch deletion error stats')
            }
            const data = await response.json()
            setStats(data)
        } catch (err) {
            setError(err.message)
        } finally {
            setLoading(false)
        }
    }

    if (loading) {
        return (
            <div className="bg-white overflow-hidden shadow rounded-lg">
                <div className="p-5">
                    <div className="flex items-center">
                        <div className="flex-shrink-0">
                            <ExclamationCircleIcon className="h-6 w-6 text-gray-400" />
                        </div>
                        <div className="ml-5 w-0 flex-1">
                            <dl>
                                <dt className="text-sm font-medium text-gray-500 truncate">
                                    Deletion Errors
                                </dt>
                                <dd className="text-lg font-medium text-gray-900">
                                    Loading...
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    if (error) {
        return (
            <div className="bg-white overflow-hidden shadow rounded-lg">
                <div className="p-5">
                    <div className="flex items-center">
                        <div className="flex-shrink-0">
                            <ExclamationCircleIcon className="h-6 w-6 text-red-400" />
                        </div>
                        <div className="ml-5 w-0 flex-1">
                            <dl>
                                <dt className="text-sm font-medium text-gray-500 truncate">
                                    Deletion Errors
                                </dt>
                                <dd className="text-sm text-red-600">
                                    Error loading stats
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    const hasErrors = stats?.total_unresolved > 0
    const hasCriticalErrors = stats?.critical_errors > 0

    return (
        <div className="bg-white overflow-hidden shadow rounded-lg">
            <div className="p-5">
                <div className="flex items-center">
                    <div className="flex-shrink-0">
                        {hasCriticalErrors ? (
                            <ExclamationTriangleIcon className="h-6 w-6 text-red-600" />
                        ) : hasErrors ? (
                            <ExclamationCircleIcon className="h-6 w-6 text-yellow-500" />
                        ) : (
                            <ExclamationCircleIcon className="h-6 w-6 text-green-500" />
                        )}
                    </div>
                    <div className="ml-5 w-0 flex-1">
                        <dl>
                            <dt className="text-sm font-medium text-gray-500 truncate">
                                Deletion Errors
                            </dt>
                            <dd className={`text-lg font-medium ${
                                hasCriticalErrors ? 'text-red-900' : 
                                hasErrors ? 'text-yellow-900' : 
                                'text-green-900'
                            }`}>
                                {stats?.total_unresolved || 0} unresolved
                            </dd>
                        </dl>
                    </div>
                    <div className="ml-5 flex-shrink-0">
                        <Link href={route('deletion-errors.index')}>
                            <ChevronRightIcon className="h-5 w-5 text-gray-400" />
                        </Link>
                    </div>
                </div>
            </div>
            
            {hasErrors && (
                <div className="bg-gray-50 px-5 py-3">
                    <div className="text-sm">
                        <div className="flex items-center justify-between text-gray-500 mb-1">
                            <span>Recent (7 days)</span>
                            <span className="font-medium text-gray-900">
                                {stats?.recent_errors || 0}
                            </span>
                        </div>
                        
                        {hasCriticalErrors && (
                            <div className="flex items-center justify-between text-red-600">
                                <span>Critical</span>
                                <span className="font-medium">
                                    {stats?.critical_errors}
                                </span>
                            </div>
                        )}
                        
                        <div className="mt-2">
                            <Link 
                                href={route('deletion-errors.index', { status: 'unresolved' })}
                                className="text-indigo-600 hover:text-indigo-500 text-sm font-medium"
                            >
                                View all unresolved →
                            </Link>
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}