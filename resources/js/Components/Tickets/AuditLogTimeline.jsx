export default function AuditLogTimeline({ auditLog }) {
    if (!auditLog || auditLog.length === 0) {
        return <p className="text-sm text-gray-500">No audit log entries.</p>
    }

    return (
        <div className="flow-root">
            <ul className="-mb-8">
                {auditLog.map((entry, index) => (
                    <li key={entry.id}>
                        <div className="relative pb-8">
                            {index !== auditLog.length - 1 && (
                                <span className="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true" />
                            )}
                            <div className="relative flex space-x-3">
                                <div>
                                    <span className="h-8 w-8 rounded-full bg-gray-100 flex items-center justify-center ring-8 ring-white">
                                        <svg className="h-5 w-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clipRule="evenodd" />
                                        </svg>
                                    </span>
                                </div>
                                <div className="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                    <div>
                                        <p className="text-sm text-gray-900">{entry.description}</p>
                                        {entry.actor && (
                                            <p className="mt-1 text-xs text-gray-500">
                                                by {entry.actor.name} ({entry.actor.email})
                                            </p>
                                        )}
                                        {entry.metadata && Object.keys(entry.metadata).length > 0 && (
                                            <div className="mt-2 text-xs text-gray-600 bg-gray-50 p-2 rounded">
                                                <pre className="whitespace-pre-wrap">{JSON.stringify(entry.metadata, null, 2)}</pre>
                                            </div>
                                        )}
                                    </div>
                                    <div className="text-right text-sm whitespace-nowrap text-gray-500">
                                        {new Date(entry.timestamp).toLocaleString()}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    )
}
