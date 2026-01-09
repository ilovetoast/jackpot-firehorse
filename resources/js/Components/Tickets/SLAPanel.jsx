import { ExclamationTriangleIcon, CheckCircleIcon } from '@heroicons/react/24/outline'

export default function SLAPanel({ slaData }) {
    if (!slaData) return null

    const formatTimeRemaining = (minutes) => {
        if (minutes === null || minutes === undefined) return 'N/A'
        if (minutes < 0) return 'Overdue'
        if (minutes < 60) return `${Math.round(minutes)} minutes`
        const hours = Math.floor(minutes / 60)
        const mins = Math.round(minutes % 60)
        return `${hours}h ${mins}m`
    }

    const getStatusColor = (breached, timeRemaining) => {
        if (breached) return 'text-red-600'
        if (timeRemaining !== null && timeRemaining < 120) return 'text-yellow-600' // Less than 2 hours
        return 'text-green-600'
    }

    const getStatusIcon = (breached, timeRemaining) => {
        if (breached) return <ExclamationTriangleIcon className="h-5 w-5 text-red-600" />
        if (timeRemaining !== null && timeRemaining < 120) return <ExclamationTriangleIcon className="h-5 w-5 text-yellow-600" />
        return <CheckCircleIcon className="h-5 w-5 text-green-600" />
    }

    return (
        <div className="px-6 py-4">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">SLA Status</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                {/* First Response */}
                <div>
                    <div className="flex items-center justify-between mb-2">
                        <label className="text-sm font-medium text-gray-700">First Response</label>
                        {getStatusIcon(slaData.breached_first_response, slaData.first_response_time_remaining)}
                    </div>
                    <div className="space-y-1">
                        <p className="text-xs text-gray-500">
                            Target: {slaData.first_response_target_minutes} minutes
                        </p>
                        {slaData.first_response_deadline && (
                            <p className="text-xs text-gray-500">
                                Deadline: {new Date(slaData.first_response_deadline).toLocaleString()}
                            </p>
                        )}
                        {slaData.first_response_at ? (
                            <p className="text-sm text-green-600">
                                Responded: {new Date(slaData.first_response_at).toLocaleString()}
                            </p>
                        ) : (
                            <p className={`text-sm ${getStatusColor(slaData.breached_first_response, slaData.first_response_time_remaining)}`}>
                                Time Remaining: {formatTimeRemaining(slaData.first_response_time_remaining)}
                            </p>
                        )}
                        {slaData.breached_first_response && (
                            <p className="text-xs text-red-600 font-medium">BREACHED</p>
                        )}
                    </div>
                </div>

                {/* Resolution */}
                <div>
                    <div className="flex items-center justify-between mb-2">
                        <label className="text-sm font-medium text-gray-700">Resolution</label>
                        {getStatusIcon(slaData.breached_resolution, slaData.resolution_time_remaining)}
                    </div>
                    <div className="space-y-1">
                        <p className="text-xs text-gray-500">
                            Target: {slaData.resolution_target_minutes} minutes
                        </p>
                        {slaData.resolution_deadline && (
                            <p className="text-xs text-gray-500">
                                Deadline: {new Date(slaData.resolution_deadline).toLocaleString()}
                            </p>
                        )}
                        {slaData.resolved_at ? (
                            <p className="text-sm text-green-600">
                                Resolved: {new Date(slaData.resolved_at).toLocaleString()}
                            </p>
                        ) : (
                            <p className={`text-sm ${getStatusColor(slaData.breached_resolution, slaData.resolution_time_remaining)}`}>
                                Time Remaining: {formatTimeRemaining(slaData.resolution_time_remaining)}
                            </p>
                        )}
                        {slaData.breached_resolution && (
                            <p className="text-xs text-red-600 font-medium">BREACHED</p>
                        )}
                    </div>
                </div>
            </div>

            {slaData.paused_at && (
                <div className="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <p className="text-sm text-yellow-800">
                        SLA is currently paused (since {new Date(slaData.paused_at).toLocaleString()})
                    </p>
                    {slaData.total_paused_minutes > 0 && (
                        <p className="text-xs text-yellow-700 mt-1">
                            Total paused time: {Math.round(slaData.total_paused_minutes)} minutes
                        </p>
                    )}
                </div>
            )}
        </div>
    )
}
