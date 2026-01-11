import { Link } from '@inertiajs/react'
import { LinkIcon, ArrowsRightLeftIcon } from '@heroicons/react/24/outline'

/**
 * RelatedTicketsPanel Component
 * 
 * Displays related tickets in a consolidated, easy-to-scan format.
 * Groups converted tickets and linked tickets together for better visibility.
 * 
 * @param {Object} props
 * @param {Object} props.ticket - Ticket object with converted_from, converted_to, and links
 */
export default function RelatedTicketsPanel({ ticket }) {
    const hasConvertedFrom = ticket.converted_from
    const hasConvertedTo = ticket.converted_to && ticket.converted_to.length > 0
    const hasLinks = ticket.links && ticket.links.length > 0
    // Filter for ticket links - handle both full class name and short name formats
    const ticketLinks = ticket.links?.filter(link => 
        link.linkable_type === 'App\\Models\\Ticket' || 
        link.linkable_type === 'ticket' ||
        (link.linkable_type && link.linkable_type.includes('Ticket'))
    ) || []

    // Don't render if no related tickets
    if (!hasConvertedFrom && !hasConvertedTo && ticketLinks.length === 0) {
        return null
    }

    return (
        <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden mb-6">
            <div className="px-6 py-4 border-b border-gray-200">
                <div className="flex items-center gap-2">
                    <LinkIcon className="h-5 w-5 text-gray-400" />
                    <h3 className="text-lg font-semibold text-gray-900">Related Tickets</h3>
                </div>
            </div>
            <div className="px-6 py-4 space-y-4">
                {/* Converted From */}
                {hasConvertedFrom && (
                    <div>
                        <div className="flex items-center gap-2 mb-2">
                            <ArrowsRightLeftIcon className="h-4 w-4 text-gray-400" />
                            <dt className="text-sm font-medium text-gray-700">Converted From</dt>
                        </div>
                        <dd>
                            <Link
                                href={`/app/admin/support/tickets/${ticket.converted_from.id}`}
                                className="inline-flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-900 font-medium"
                            >
                                {ticket.converted_from.ticket_number}
                                {ticket.converted_from.status && (
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                                        ticket.converted_from.status === 'resolved' ? 'bg-green-100 text-green-800' :
                                        ticket.converted_from.status === 'closed' ? 'bg-gray-100 text-gray-800' :
                                        'bg-blue-100 text-blue-800'
                                    }`}>
                                        {ticket.converted_from.status.replace('_', ' ')}
                                    </span>
                                )}
                            </Link>
                        </dd>
                    </div>
                )}

                {/* Converted To */}
                {hasConvertedTo && (
                    <div>
                        <div className="flex items-center gap-2 mb-2">
                            <ArrowsRightLeftIcon className="h-4 w-4 text-gray-400" />
                            <dt className="text-sm font-medium text-gray-700">Converted To</dt>
                        </div>
                        <dd>
                            <div className="flex flex-col gap-2">
                                {ticket.converted_to.map((converted) => (
                                    <Link
                                        key={converted.id}
                                        href={`/app/admin/support/tickets/${converted.id}`}
                                        className="inline-flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-900 font-medium"
                                    >
                                        {converted.ticket_number}
                                        {converted.status && (
                                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                                                converted.status === 'resolved' ? 'bg-green-100 text-green-800' :
                                                converted.status === 'closed' ? 'bg-gray-100 text-gray-800' :
                                                'bg-blue-100 text-blue-800'
                                            }`}>
                                                {converted.status.replace('_', ' ')}
                                            </span>
                                        )}
                                    </Link>
                                ))}
                            </div>
                        </dd>
                    </div>
                )}

                {/* Linked Tickets */}
                {ticketLinks.length > 0 && (
                    <div>
                        <div className="flex items-center gap-2 mb-2">
                            <LinkIcon className="h-4 w-4 text-gray-400" />
                            <dt className="text-sm font-medium text-gray-700">Linked Tickets</dt>
                        </div>
                        <dd>
                            <div className="flex flex-col gap-2">
                                {ticketLinks.map((link) => (
                                    <div key={link.id} className="flex items-center justify-between">
                                        <Link
                                            href={`/app/admin/support/tickets/${link.linkable_id}`}
                                            className="inline-flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-900 font-medium"
                                        >
                                            Ticket #{link.linkable_id}
                                            {link.designation && (
                                                <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                                                    link.designation === 'primary' ? 'bg-indigo-100 text-indigo-800' :
                                                    link.designation === 'duplicate' ? 'bg-yellow-100 text-yellow-800' :
                                                    'bg-gray-100 text-gray-800'
                                                }`}>
                                                    {link.designation}
                                                </span>
                                            )}
                                        </Link>
                                    </div>
                                ))}
                            </div>
                        </dd>
                    </div>
                )}
            </div>
        </div>
    )
}
