import { useState } from 'react'
import { Link, router } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout'
import { 
    ExclamationTriangleIcon, 
    ExclamationCircleIcon,
    InformationCircleIcon,
    CheckCircleIcon,
    ArrowPathIcon,
    ArrowLeftIcon,
    DocumentTextIcon,
    CalendarIcon,
    UserIcon,
    CodeBracketIcon,
} from '@heroicons/react/24/outline'

export default function Show({ auth, deletionError }) {
    const [processing, setProcessing] = useState(false)
    const [showTechnicalDetails, setShowTechnicalDetails] = useState(false)
    const [resolutionNotes, setResolutionNotes] = useState('')
    const [showResolveForm, setShowResolveForm] = useState(false)

    const getSeverityIcon = (severity) => {
        switch (severity) {
            case 'critical':
                return <ExclamationTriangleIcon className="h-8 w-8 text-red-600" />
            case 'error':
                return <ExclamationCircleIcon className="h-8 w-8 text-red-500" />
            case 'warning':
                return <InformationCircleIcon className="h-8 w-8 text-yellow-500" />
            default:
                return <ExclamationCircleIcon className="h-8 w-8 text-gray-500" />
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

    const handleResolve = async (e) => {
        e.preventDefault()
        if (processing) return

        setProcessing(true)

        try {
            await router.post(route('deletion-errors.resolve', deletionError.id), {
                resolution_notes: resolutionNotes,
            })
        } finally {
            setProcessing(false)
            setShowResolveForm(false)
        }
    }

    const handleRetry = async () => {
        if (processing) return

        setProcessing(true)

        try {
            await router.post(route('deletion-errors.retry', deletionError.id))
        } finally {
            setProcessing(false)
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
                    <div className="flex items-center gap-4">
                        <Link
                            href={route('deletion-errors.index')}
                            className="text-gray-500 hover:text-gray-700"
                        >
                            <ArrowLeftIcon className="h-6 w-6" />
                        </Link>
                        <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                            Deletion Error Details
                        </h2>
                    </div>
                </div>
            }
        >
            <AppHead title={`Deletion Error - ${deletionError.original_filename}`} suffix="Admin" />

            <div className="py-12">
                <div className="max-w-4xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            {/* Header */}
                            <div className="flex items-start gap-4 mb-6">
                                {getSeverityIcon(deletionError.severity_level)}
                                <div className="flex-1">
                                    <div className="flex items-center gap-3 mb-2">
                                        <h1 className="text-2xl font-bold text-gray-900">
                                            {deletionError.original_filename}
                                        </h1>
                                        <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ring-1 ring-inset ${getSeverityBadgeColor(deletionError.severity_level)}`}>
                                            {deletionError.severity_level}
                                        </span>
                                        {deletionError.resolved_at && (
                                            <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 ring-green-600/20">
                                                <CheckCircleIcon className="h-4 w-4 mr-1" />
                                                Resolved
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-lg text-gray-600 mb-4">
                                        {deletionError.user_friendly_message}
                                    </p>
                                </div>
                            </div>

                            {/* Error Information */}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">
                                            Asset ID
                                        </label>
                                        <p className="text-sm text-gray-900 font-mono bg-gray-50 p-2 rounded">
                                            {deletionError.asset_id}
                                        </p>
                                    </div>
                                    
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">
                                            Error Type
                                        </label>
                                        <p className="text-sm text-gray-900">
                                            {formatErrorType(deletionError.error_type)}
                                        </p>
                                    </div>

                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">
                                            Deletion Type
                                        </label>
                                        <p className="text-sm text-gray-900 capitalize">
                                            {deletionError.deletion_type}
                                        </p>
                                    </div>
                                </div>

                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">
                                            <CalendarIcon className="inline h-4 w-4 mr-1" />
                                            First Occurred
                                        </label>
                                        <p className="text-sm text-gray-900">
                                            {new Date(deletionError.created_at).toLocaleDateString()} at{' '}
                                            {new Date(deletionError.created_at).toLocaleTimeString()}
                                        </p>
                                    </div>

                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">
                                            Retry Attempts
                                        </label>
                                        <p className="text-sm text-gray-900">
                                            {deletionError.attempts} attempt{deletionError.attempts !== 1 ? 's' : ''}
                                        </p>
                                    </div>

                                    {deletionError.resolved_at && (
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                                <UserIcon className="inline h-4 w-4 mr-1" />
                                                Resolved By
                                            </label>
                                            <p className="text-sm text-gray-900">
                                                {deletionError.resolver?.name || 'System'} on{' '}
                                                {new Date(deletionError.resolved_at).toLocaleDateString()}
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Error Message */}
                            <div className="mb-6">
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Error Message
                                </label>
                                <div className="bg-red-50 border border-red-200 rounded-md p-4">
                                    <p className="text-sm text-red-800 font-mono">
                                        {deletionError.error_message}
                                    </p>
                                </div>
                            </div>

                            {/* Technical Details */}
                            {deletionError.error_details && (
                                <div className="mb-6">
                                    <button
                                        onClick={() => setShowTechnicalDetails(!showTechnicalDetails)}
                                        className="flex items-center gap-2 text-sm font-medium text-gray-700 hover:text-gray-900 mb-2"
                                    >
                                        <CodeBracketIcon className="h-4 w-4" />
                                        Technical Details
                                        <span className="text-xs text-gray-500">
                                            {showTechnicalDetails ? '(hide)' : '(show)'}
                                        </span>
                                    </button>
                                    
                                    {showTechnicalDetails && (
                                        <div className="bg-gray-50 border border-gray-200 rounded-md p-4">
                                            <pre className="text-xs text-gray-700 whitespace-pre-wrap overflow-x-auto">
                                                {JSON.stringify(deletionError.error_details, null, 2)}
                                            </pre>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Resolution Notes */}
                            {deletionError.resolved_at && deletionError.resolution_notes && (
                                <div className="mb-6">
                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                        <DocumentTextIcon className="inline h-4 w-4 mr-1" />
                                        Resolution Notes
                                    </label>
                                    <div className="bg-green-50 border border-green-200 rounded-md p-4">
                                        <p className="text-sm text-green-800">
                                            {deletionError.resolution_notes}
                                        </p>
                                    </div>
                                </div>
                            )}

                            {/* Actions */}
                            {!deletionError.resolved_at && (
                                <div className="flex items-center gap-4 pt-6 border-t border-gray-200">
                                    <button
                                        onClick={handleRetry}
                                        disabled={processing}
                                        className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                                    >
                                        <ArrowPathIcon className={`h-4 w-4 mr-2 ${processing ? 'animate-spin' : ''}`} />
                                        Retry Deletion
                                    </button>

                                    {!showResolveForm ? (
                                        <button
                                            onClick={() => setShowResolveForm(true)}
                                            className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                                        >
                                            <CheckCircleIcon className="h-4 w-4 mr-2" />
                                            Mark as Resolved
                                        </button>
                                    ) : (
                                        <form onSubmit={handleResolve} className="flex-1 max-w-md">
                                            <div className="flex gap-2">
                                                <input
                                                    type="text"
                                                    placeholder="Resolution notes (optional)"
                                                    value={resolutionNotes}
                                                    onChange={(e) => setResolutionNotes(e.target.value)}
                                                    className="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                                                />
                                                <button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
                                                >
                                                    Resolve
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setShowResolveForm(false)}
                                                    className="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </form>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    )
}