import { LockClosedIcon } from '@heroicons/react/24/outline'
import ActivityActorAvatar from '../../Components/ActivityActorAvatar'
import InsightsLayout from '../../layouts/InsightsLayout'

export default function InsightsActivity({ activity = [], can_view_activity_logs = false }) {
    return (
        <InsightsLayout title="Activity" activeSection="activity">
            <div className="space-y-6">
                <p className="text-sm text-gray-600">
                    Recent actions for this brand (uploads, edits, downloads, and more). Scoped like the overview
                    dashboard; tenant-wide events without a brand may appear here when they affect shared resources.
                </p>

                {!can_view_activity_logs && (
                    <div
                        className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 flex gap-3"
                        role="status"
                    >
                        <LockClosedIcon className="h-5 w-5 shrink-0 text-amber-700" aria-hidden />
                        <div className="min-w-0 text-sm text-amber-950">
                            <p className="font-medium">Activity log not available for your role</p>
                            <p className="mt-1 text-amber-900/90">
                                Your account needs the <span className="font-medium">activity logs</span> permission
                                for this company to view this feed. Ask a company admin if you should have access.
                            </p>
                        </div>
                    </div>
                )}

                {can_view_activity_logs && activity.length === 0 && (
                    <div className="rounded-lg border border-gray-200 bg-white px-6 py-10 text-center text-sm text-gray-500">
                        No recent activity recorded for this brand yet.
                    </div>
                )}

                {can_view_activity_logs && activity.length > 0 && (
                    <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <ul className="divide-y divide-gray-100">
                            {activity.map((event) => (
                                <li key={event.id} className="px-4 py-4 sm:px-6">
                                    <div className="flex items-center gap-4">
                                        <ActivityActorAvatar actor={event.actor} />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm text-gray-900">
                                                <span className="font-medium">{event.actor?.name}</span>{' '}
                                                <span className="text-gray-500">
                                                    {(event.event_type_label || '').toLowerCase()}
                                                </span>{' '}
                                                <span className="font-medium">{event.subject?.name}</span>
                                            </p>
                                            {event.description && (
                                                <p className="mt-0.5 text-xs text-gray-500 line-clamp-2">
                                                    {event.description}
                                                </p>
                                            )}
                                        </div>
                                        <span className="shrink-0 text-xs text-gray-400">{event.created_at_human}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </InsightsLayout>
    )
}
