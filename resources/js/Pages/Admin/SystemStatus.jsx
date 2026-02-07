import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    ClockIcon,
    ServerIcon,
    PhotoIcon,
    QueueListIcon,
    ExclamationCircleIcon,
    ArrowPathIcon,
    LinkIcon,
    SparklesIcon,
} from '@heroicons/react/24/outline'

export default function AdminSystemStatus({ systemHealth, recentFailedJobs, assetsWithIssues, latestAIInsight, scheduledTasks, queueNextRun, horizonAvailable, horizonUrl }) {
    const { auth } = usePage().props

    // Get status badge config
    const getStatusBadge = (status) => {
        switch (status) {
            case 'healthy':
                return {
                    label: 'Healthy',
                    className: 'bg-green-100 text-green-800',
                    icon: CheckCircleIcon,
                }
            case 'warning':
                return {
                    label: 'Warning',
                    className: 'bg-amber-100 text-amber-800',
                    icon: ExclamationTriangleIcon,
                }
            case 'unhealthy':
            case 'not_running':
                return {
                    label: 'Unhealthy',
                    className: 'bg-red-100 text-red-800',
                    icon: XCircleIcon,
                }
            case 'delayed':
                return {
                    label: 'Delayed',
                    className: 'bg-amber-100 text-amber-800',
                    icon: ClockIcon,
                }
            default:
                return {
                    label: 'Unknown',
                    className: 'bg-gray-100 text-gray-800',
                    icon: ExclamationCircleIcon,
                }
        }
    }

    // Format date for display
    const formatDate = (dateString) => {
        if (!dateString) return 'Never'
        try {
            const date = new Date(dateString)
            return date.toLocaleString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            })
        } catch (e) {
            return dateString
        }
    }

    // Truncate text
    const truncate = (text, maxLength = 100) => {
        if (!text || text.length <= maxLength) return text
        return text.substring(0, maxLength) + '...'
    }

    const queueStatus = getStatusBadge(systemHealth?.queue?.status || 'unknown')
    const schedulerStatus = getStatusBadge(systemHealth?.scheduler?.status || 'unknown')
    const storageStatus = getStatusBadge(systemHealth?.storage?.status || 'unknown')
    const thumbnailStatus = getStatusBadge(systemHealth?.thumbnails?.status || 'unknown')

    const QueueStatusIcon = queueStatus.icon
    const SchedulerStatusIcon = schedulerStatus.icon
    const StorageStatusIcon = storageStatus.icon
    const ThumbnailStatusIcon = thumbnailStatus.icon

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <Link
                            href="/app/admin"
                            className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block"
                        >
                            ← Back to Admin Dashboard
                        </Link>
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">System Status</h1>
                        <p className="mt-2 text-sm text-gray-700">
                            Monitor system health, queues, scheduler, storage, and asset processing
                        </p>
                    </div>

                    {/* System Health Cards */}
                    <div className="mb-8">
                        <h2 className="text-lg font-semibold text-gray-900 mb-4">System Health</h2>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {/* Queue Health */}
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="p-6">
                                    <div className="flex items-center justify-between mb-4">
                                        <div className="flex items-center">
                                            <QueueListIcon className="h-6 w-6 text-gray-400 mr-3" />
                                            <h3 className="text-sm font-medium text-gray-900">Queue</h3>
                                            {systemHealth?.queue?.queue_driver && (
                                                <span className="ml-2 text-xs text-gray-500">({systemHealth.queue.queue_driver})</span>
                                            )}
                                        </div>
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${queueStatus.className}`}>
                                            <QueueStatusIcon className="h-4 w-4 mr-1" />
                                            {queueStatus.label}
                                        </span>
                                    </div>
                                    <div className="space-y-2">
                                        <div className="flex justify-between text-sm">
                                            <span className="text-gray-500">Pending Jobs</span>
                                            <span className="font-medium text-gray-900">{systemHealth?.queue?.pending_count ?? 0}</span>
                                        </div>
                                        <div className="flex justify-between text-sm">
                                            <span className="text-gray-500">Failed Jobs</span>
                                            <span className={`font-medium ${systemHealth?.queue?.failed_count > 0 ? 'text-red-600' : 'text-gray-900'}`}>
                                                {systemHealth?.queue?.failed_count ?? 0}
                                            </span>
                                        </div>
                                        {systemHealth?.queue?.last_processed_at && (
                                            <div className="text-xs text-gray-500 mt-2">
                                                Last processed: {formatDate(systemHealth.queue.last_processed_at)}
                                            </div>
                                        )}
                                        {queueNextRun && (
                                            <div className="text-xs text-gray-500 mt-2">
                                                Next job: {queueNextRun.job_name} in {queueNextRun.next_run_in}
                                            </div>
                                        )}
                                        {horizonAvailable && horizonUrl && (
                                            <div className="pt-2 mt-2 border-t border-gray-100">
                                                <a
                                                    href={horizonUrl}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center text-xs font-medium text-indigo-600 hover:text-indigo-800"
                                                >
                                                    <LinkIcon className="h-3.5 w-3.5 mr-1" />
                                                    Open Horizon Dashboard
                                                </a>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Scheduler Health */}
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="p-6">
                                    <div className="flex items-center justify-between mb-4">
                                        <div className="flex items-center">
                                            <ClockIcon className="h-6 w-6 text-gray-400 mr-3" />
                                            <h3 className="text-sm font-medium text-gray-900">Scheduler</h3>
                                        </div>
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${schedulerStatus.className}`}>
                                            <SchedulerStatusIcon className="h-4 w-4 mr-1" />
                                            {schedulerStatus.label}
                                        </span>
                                    </div>
                                    <div className="space-y-2">
                                        {systemHealth?.scheduler?.last_heartbeat ? (
                                            <div className="text-sm">
                                                <span className="text-gray-500">Last heartbeat: </span>
                                                <span className="font-medium text-gray-900">
                                                    {formatDate(systemHealth.scheduler.last_heartbeat)}
                                                </span>
                                            </div>
                                        ) : (
                                            <div className="text-sm text-gray-500">No heartbeat recorded</div>
                                        )}
                                        {systemHealth?.scheduler?.message && (
                                            <div className="text-xs text-gray-600 mt-1">{systemHealth.scheduler.message}</div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Storage Health */}
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="p-6">
                                    <div className="flex items-center justify-between mb-4">
                                        <div className="flex items-center">
                                            <ServerIcon className="h-6 w-6 text-gray-400 mr-3" />
                                            <h3 className="text-sm font-medium text-gray-900">Storage</h3>
                                        </div>
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${storageStatus.className}`}>
                                            <StorageStatusIcon className="h-4 w-4 mr-1" />
                                            {storageStatus.label}
                                        </span>
                                    </div>
                                    <div className="space-y-2">
                                        {systemHealth?.storage?.bucket && (
                                            <div className="text-sm">
                                                <span className="text-gray-500">Bucket: </span>
                                                <span className="font-medium text-gray-900">{systemHealth.storage.bucket}</span>
                                            </div>
                                        )}
                                        {systemHealth?.storage?.error && (
                                            <div className="text-xs text-red-600 mt-2">{systemHealth.storage.error}</div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Thumbnail Health */}
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="p-6">
                                    <div className="flex items-center justify-between mb-4">
                                        <div className="flex items-center">
                                            <PhotoIcon className="h-6 w-6 text-gray-400 mr-3" />
                                            <h3 className="text-sm font-medium text-gray-900">Thumbnails</h3>
                                        </div>
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${thumbnailStatus.className}`}>
                                            <ThumbnailStatusIcon className="h-4 w-4 mr-1" />
                                            {thumbnailStatus.label}
                                        </span>
                                    </div>
                                    <div className="space-y-2">
                                        <div className="flex justify-between text-sm">
                                            <span className="text-gray-500">Pending</span>
                                            <span className="font-medium text-gray-900">{systemHealth?.thumbnails?.pending ?? 0}</span>
                                        </div>
                                        <div className="flex justify-between text-sm">
                                            <span className="text-gray-500">Processing</span>
                                            <span className="font-medium text-gray-900">{systemHealth?.thumbnails?.processing ?? 0}</span>
                                        </div>
                                        <div className="flex justify-between text-sm">
                                            <span className="text-gray-500">Completed</span>
                                            <span className="font-medium text-green-600">{systemHealth?.thumbnails?.completed ?? 0}</span>
                                        </div>
                                        <div className="flex justify-between text-sm">
                                            <span className="text-gray-500">Failed</span>
                                            <span className={`font-medium ${systemHealth?.thumbnails?.failed > 0 ? 'text-red-600' : 'text-gray-900'}`}>
                                                {systemHealth?.thumbnails?.failed ?? 0}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* AI System Reliability Insight */}
                    {latestAIInsight && (
                        <div className="mb-8">
                            <h2 className="text-lg font-semibold text-gray-900 mb-4">AI System Insight</h2>
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="p-6">
                                    <div className="flex items-start justify-between mb-4">
                                        <div className="flex items-center">
                                            <SparklesIcon className="h-6 w-6 text-purple-500 mr-3" />
                                            <h3 className="text-sm font-semibold text-gray-900">System Reliability Analysis</h3>
                                        </div>
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                            latestAIInsight.severity === 'critical' ? 'bg-red-100 text-red-800' :
                                            latestAIInsight.severity === 'high' ? 'bg-orange-100 text-orange-800' :
                                            latestAIInsight.severity === 'medium' ? 'bg-amber-100 text-amber-800' :
                                            'bg-green-100 text-green-800'
                                        }`}>
                                            {latestAIInsight.severity?.charAt(0).toUpperCase() + latestAIInsight.severity?.slice(1) || 'Medium'}
                                        </span>
                                    </div>
                                    <div className="space-y-4">
                                        <div>
                                            <p className="text-sm text-gray-700">{latestAIInsight.summary}</p>
                                        </div>
                                        {latestAIInsight.recommendations && latestAIInsight.recommendations.length > 0 && (
                                            <div>
                                                <h4 className="text-xs font-semibold text-gray-900 uppercase mb-2">Recommendations</h4>
                                                <ul className="list-disc list-inside space-y-1 text-sm text-gray-600">
                                                    {latestAIInsight.recommendations.map((rec, index) => (
                                                        <li key={index}>{rec}</li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}
                                        {latestAIInsight.created_at && (
                                            <div className="text-xs text-gray-500 pt-2 border-t border-gray-200">
                                                Generated: {formatDate(latestAIInsight.created_at)}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Recent Failed Jobs */}
                    <div className="mb-8">
                        <h2 className="text-lg font-semibold text-gray-900 mb-4">Recent Failed Jobs</h2>
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            {recentFailedJobs && recentFailedJobs.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Job Name
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Queue
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Failed At
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Error
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {recentFailedJobs.map((job) => (
                                                <tr key={job.id} className="hover:bg-gray-50">
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        {job.job_name}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {job.queue}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {formatDate(job.failed_at)}
                                                    </td>
                                                    <td className="px-6 py-4 text-sm text-gray-500">
                                                        <div className="max-w-md">
                                                            <span className="text-red-600" title={job.exception_message}>
                                                                {truncate(job.exception_message, 100)}
                                                            </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="px-6 py-8 text-center text-sm text-gray-500">
                                    No failed jobs
                                </div>
                            )}
                        </div>
                        {/* TODO: Add retry button for failed jobs in future phase */}
                    </div>

                    {/* Scheduled Tasks */}
                    <div className="mb-8">
                        <h2 className="text-lg font-semibold text-gray-900 mb-4">Scheduled Tasks</h2>
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            {scheduledTasks && scheduledTasks.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Task
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Schedule
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Next Run
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    In
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {scheduledTasks.map((task, index) => (
                                                <tr key={index} className="hover:bg-gray-50">
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm font-medium text-gray-900">
                                                            {task.description || task.command || 'Unknown task'}
                                                        </div>
                                                        {task.command && task.command !== task.description && (
                                                            <div className="text-xs text-gray-500 font-mono mt-1">
                                                                {task.command}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500 font-mono">
                                                            {task.expression || 'N/A'}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {task.next_run_at ? formatDate(task.next_run_at) : 'N/A'}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {task.next_run_in || 'N/A'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="px-6 py-8 text-center text-sm text-gray-500">
                                    No scheduled tasks found
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Assets with Issues */}
                    <div className="mb-8">
                        <h2 className="text-lg font-semibold text-gray-900 mb-4">Assets with Processing Issues</h2>
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            {assetsWithIssues && assetsWithIssues.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Asset
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Issues
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Created At
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Error Details
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {assetsWithIssues.map((asset) => (
                                                <tr key={asset.id} className="hover:bg-gray-50">
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex items-center">
                                                            <Link
                                                                href={`/app/assets?asset=${asset.id}`}
                                                                className="text-sm font-medium text-indigo-600 hover:text-indigo-900"
                                                            >
                                                                {asset.title}
                                                            </Link>
                                                            <LinkIcon className="h-4 w-4 ml-2 text-gray-400" />
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex flex-wrap gap-2">
                                                            {asset.issues.includes('thumbnail_generation_failed') && (
                                                                <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-red-100 text-red-800">
                                                                    Thumbnail Failed
                                                                </span>
                                                            )}
                                                            {asset.issues.includes('promotion_failed') && (
                                                                <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-amber-100 text-amber-800">
                                                                    Promotion Failed
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {formatDate(asset.created_at)}
                                                    </td>
                                                    <td className="px-6 py-4 text-sm text-gray-500">
                                                        <div className="max-w-md space-y-1">
                                                            {asset.thumbnail_error && (
                                                                <div>
                                                                    <span className="font-medium text-red-600">Thumbnail: </span>
                                                                    <span className="text-red-600" title={asset.thumbnail_error}>
                                                                        {truncate(asset.thumbnail_error, 80)}
                                                                    </span>
                                                                </div>
                                                            )}
                                                            {asset.promotion_error && (
                                                                <div>
                                                                    <span className="font-medium text-amber-600">Promotion: </span>
                                                                    <span className="text-amber-600" title={asset.promotion_error}>
                                                                        {truncate(asset.promotion_error, 80)}
                                                                    </span>
                                                                </div>
                                                            )}
                                                            {!asset.thumbnail_error && !asset.promotion_error && (
                                                                <span className="text-gray-400">No error details</span>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="px-6 py-8 text-center text-sm text-gray-500">
                                    No assets with processing issues
                                </div>
                            )}
                        </div>
                        {/* TODO: Add retry buttons and asset lifecycle timeline integration in future phase */}
                    </div>

                    {/* Footer Notes */}
                    <div className="text-xs text-gray-500">
                        <p>
                            • Last updated: {new Date().toLocaleString()}
                        </p>
                        <p className="mt-1">
                            • TODO: Add alerting, retry buttons, and job execution history in future phases
                        </p>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}