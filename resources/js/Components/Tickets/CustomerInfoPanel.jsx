import { Link } from '@inertiajs/react'
import { BuildingOffice2Icon } from '@heroicons/react/24/outline'

/**
 * CustomerInfoPanel Component
 * 
 * Displays customer/tenant information for tenant tickets.
 * Provides quick context about the customer for support staff.
 * Only displays for tenant tickets (not internal tickets).
 * 
 * @param {Object} props
 * @param {Object} props.ticket - Ticket object
 */
export default function CustomerInfoPanel({ ticket }) {
    // Only show for tenant tickets
    if (ticket.type !== 'tenant' || !ticket.tenant) {
        return null
    }

    return (
        <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden mb-6">
            <div className="px-6 py-4 border-b border-gray-200">
                <div className="flex items-center gap-2">
                    <BuildingOffice2Icon className="h-5 w-5 text-gray-400" />
                    <h3 className="text-lg font-semibold text-gray-900">Customer Information</h3>
                </div>
            </div>
            <div className="px-6 py-4">
                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <dt className="text-sm font-medium text-gray-500">Company</dt>
                        <dd className="mt-1 text-sm text-gray-900">
                            {ticket.tenant.name}
                        </dd>
                    </div>
                    {ticket.tenant.slug && (
                        <div>
                            <dt className="text-sm font-medium text-gray-500">Slug</dt>
                            <dd className="mt-1 text-sm text-gray-900 font-mono">
                                {ticket.tenant.slug}
                            </dd>
                        </div>
                    )}
                    {ticket.created_by && (
                        <div>
                            <dt className="text-sm font-medium text-gray-500">Created By</dt>
                            <dd className="mt-1 text-sm text-gray-900">
                                {ticket.created_by.name || ticket.created_by.email}
                            </dd>
                        </div>
                    )}
                </dl>
                {ticket.brands && ticket.brands.length > 0 && (
                    <div className="mt-4">
                        <dt className="text-sm font-medium text-gray-500 mb-2">Brands</dt>
                        <dd className="text-sm text-gray-900">
                            <div className="flex flex-wrap gap-2">
                                {ticket.brands.map((brand) => (
                                    <span
                                        key={brand.id}
                                        className="inline-flex items-center rounded-md bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800"
                                    >
                                        {brand.name}
                                    </span>
                                ))}
                            </div>
                        </dd>
                    </div>
                )}
            </div>
        </div>
    )
}
