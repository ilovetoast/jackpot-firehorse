import { router } from '@inertiajs/react'
import { useState } from 'react'
import { SparklesIcon, CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/outline'
import AISuggestionBadge from './AISuggestionBadge'

/**
 * SuggestionPanel Component
 *
 * Displays all AI suggestions for a ticket, grouped by type.
 */
export default function SuggestionPanel({ suggestions = [] }) {
    const [loading, setLoading] = useState(false)

    if (!suggestions || suggestions.length === 0) {
        return null
    }

    const handleAccept = (suggestion) => {
        if (suggestion.suggestion_type === 'ticket_creation') {
            // For ticket creation suggestions, redirect to create ticket action
            router.post(`/app/admin/support/tickets/suggestions/${suggestion.id}/create-ticket`, {}, {
                preserveScroll: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            })
        } else {
            // For other suggestions, accept them
            router.post(`/app/admin/support/tickets/suggestions/${suggestion.id}/accept`, {}, {
                preserveScroll: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            })
        }
    }

    const handleReject = (suggestion) => {
        router.post(`/app/admin/support/tickets/suggestions/${suggestion.id}/reject`, {}, {
            preserveScroll: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        })
    }

    // Group suggestions by type
    const groupedSuggestions = suggestions.reduce((acc, suggestion) => {
        const type = suggestion.suggestion_type
        if (!acc[type]) {
            acc[type] = []
        }
        acc[type].push(suggestion)
        return acc
    }, {})

    return (
        <div className="bg-white border border-gray-200 rounded-lg shadow-sm">
            <div className="px-6 py-4 border-b border-gray-200">
                <div className="flex items-center space-x-2">
                    <SparklesIcon className="h-5 w-5 text-indigo-600" />
                    <h3 className="text-lg font-semibold text-gray-900">AI Suggestions</h3>
                    <span className="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">
                        {suggestions.length}
                    </span>
                </div>
                <p className="mt-1 text-sm text-gray-600">
                    AI-generated suggestions to help manage this ticket. All suggestions require your approval before being applied.
                </p>
            </div>

            <div className="px-6 py-4 space-y-4">
                {Object.entries(groupedSuggestions).map(([type, typeSuggestions]) => (
                    <div key={type} className="space-y-2">
                        <h4 className="text-sm font-medium text-gray-700 uppercase tracking-wide">
                            {type.replace('_', ' ')} Suggestions
                        </h4>
                        {typeSuggestions.map((suggestion) => (
                            <AISuggestionBadge
                                key={suggestion.id}
                                suggestion={suggestion}
                                onAccept={handleAccept}
                                onReject={handleReject}
                                loading={loading}
                            />
                        ))}
                    </div>
                ))}
            </div>
        </div>
    )
}
