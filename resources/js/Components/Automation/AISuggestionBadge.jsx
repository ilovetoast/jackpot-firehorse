import { SparklesIcon } from '@heroicons/react/24/outline'

/**
 * AISuggestionBadge Component
 *
 * Displays an AI suggestion badge with icon and confidence score.
 */
export default function AISuggestionBadge({ suggestion, onAccept, onReject, loading = false }) {
    const confidencePercentage = Math.round((suggestion.confidence_score || 0) * 100)

    const getTypeLabel = (type) => {
        const labels = {
            classification: 'Classification',
            duplicate: 'Duplicate',
            ticket_creation: 'Create Ticket',
            severity: 'Severity',
        }
        return labels[type] || type
    }

    const getTypeColor = (type) => {
        const colors = {
            classification: 'bg-blue-100 text-blue-800',
            duplicate: 'bg-orange-100 text-orange-800',
            ticket_creation: 'bg-purple-100 text-purple-800',
            severity: 'bg-yellow-100 text-yellow-800',
        }
        return colors[type] || 'bg-gray-100 text-gray-800'
    }

    return (
        <div className="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
            <div className="flex items-start justify-between">
                <div className="flex items-start space-x-3 flex-1">
                    <div className={`flex-shrink-0 rounded-full p-2 ${getTypeColor(suggestion.suggestion_type)}`}>
                        <SparklesIcon className="h-5 w-5" />
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center space-x-2">
                            <span className="text-sm font-medium text-gray-900">
                                {getTypeLabel(suggestion.suggestion_type)}
                            </span>
                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-800`}>
                                {confidencePercentage}% confidence
                            </span>
                        </div>
                        {suggestion.suggested_value?.reasoning && (
                            <p className="mt-1 text-sm text-gray-600">
                                {suggestion.suggested_value.reasoning}
                            </p>
                        )}
                        {suggestion.ai_agent_run && (
                            <p className="mt-1 text-xs text-gray-500">
                                AI Agent: {suggestion.ai_agent_run.agent_id} • 
                                Cost: ${(suggestion.ai_agent_run.estimated_cost || 0).toFixed(6)}
                            </p>
                        )}
                    </div>
                </div>
                <div className="flex items-center space-x-2 ml-4">
                    {onAccept && (
                        <button
                            type="button"
                            onClick={() => onAccept(suggestion)}
                            disabled={loading}
                            className="inline-flex items-center rounded-md bg-green-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Accept
                        </button>
                    )}
                    {onReject && (
                        <button
                            type="button"
                            onClick={() => onReject(suggestion)}
                            disabled={loading}
                            className="inline-flex items-center rounded-md bg-gray-200 px-3 py-1.5 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-300 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Reject
                        </button>
                    )}
                </div>
            </div>
        </div>
    )
}
