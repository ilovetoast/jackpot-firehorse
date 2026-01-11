import { XMarkIcon } from '@heroicons/react/24/outline'

/**
 * ConvertTicketModal Component
 * 
 * Modal dialog for converting a tenant ticket to an internal engineering ticket.
 * Allows staff to specify engineering-specific fields (severity, environment, component)
 * when converting customer-facing tickets to internal tracking tickets.
 * 
 * @param {Object} props
 * @param {boolean} props.open - Whether the modal is open
 * @param {Function} props.onClose - Callback to close the modal
 * @param {Object} props.form - Inertia form object with data, setData, post, processing, errors
 * @param {number} props.ticketId - ID of the ticket being converted
 * @param {Object} props.filterOptions - Options for severity, environment, component dropdowns
 */
export default function ConvertTicketModal({ open, onClose, form, ticketId, filterOptions }) {
    const { data, setData, post, processing, errors, reset } = form

    if (!open) return null

    const handleSubmit = (e) => {
        e.preventDefault()
        post(`/app/admin/support/tickets/${ticketId}/convert`, {
            preserveScroll: true,
            onSuccess: () => {
                onClose()
                reset()
            },
        })
    }

    const handleClose = () => {
        if (!processing) {
            onClose()
            reset()
        }
    }

    const handleBackdropClick = (e) => {
        if (e.target === e.currentTarget && !processing) {
            handleClose()
        }
    }

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                {/* Backdrop */}
                <div
                    className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    onClick={handleBackdropClick}
                />

                {/* Modal */}
                <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:p-6">
                    {/* Close button */}
                    <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                        <button
                            type="button"
                            className="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            onClick={handleClose}
                            disabled={processing}
                        >
                            <span className="sr-only">Close</span>
                            <XMarkIcon className="h-6 w-6" />
                        </button>
                    </div>

                    <div className="sm:flex sm:items-start">
                        <div className="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
                            <h3 className="text-base font-semibold leading-6 text-gray-900 mb-2">
                                Convert to Internal Engineering Ticket
                            </h3>
                            <p className="text-sm text-gray-500 mb-4">
                                This will create a new internal engineering ticket linked to the original tenant ticket.
                                The original ticket will remain accessible to the tenant for status updates.
                            </p>

                            <form onSubmit={handleSubmit} className="space-y-4">
                                {/* Engineering Fields Grid */}
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    {/* Severity */}
                                    <div>
                                        <label htmlFor="severity" className="block text-sm font-medium text-gray-700 mb-1">
                                            Severity
                                        </label>
                                        <select
                                            id="severity"
                                            value={data.severity}
                                            onChange={(e) => setData('severity', e.target.value)}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            disabled={processing}
                                        >
                                            <option value="">Optional</option>
                                            {filterOptions?.severities?.map((severity) => (
                                                <option key={severity.value} value={severity.value}>
                                                    {severity.label}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.severity && (
                                            <p className="mt-1 text-sm text-red-600">{errors.severity}</p>
                                        )}
                                    </div>

                                    {/* Environment */}
                                    <div>
                                        <label htmlFor="environment" className="block text-sm font-medium text-gray-700 mb-1">
                                            Environment
                                        </label>
                                        <select
                                            id="environment"
                                            value={data.environment}
                                            onChange={(e) => setData('environment', e.target.value)}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            disabled={processing}
                                        >
                                            <option value="">Optional</option>
                                            {filterOptions?.environments?.map((env) => (
                                                <option key={env.value} value={env.value}>
                                                    {env.label}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.environment && (
                                            <p className="mt-1 text-sm text-red-600">{errors.environment}</p>
                                        )}
                                    </div>

                                    {/* Component */}
                                    <div>
                                        <label htmlFor="component" className="block text-sm font-medium text-gray-700 mb-1">
                                            Component
                                        </label>
                                        <select
                                            id="component"
                                            value={data.component}
                                            onChange={(e) => setData('component', e.target.value)}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            disabled={processing}
                                        >
                                            <option value="">Optional</option>
                                            {filterOptions?.components?.map((comp) => (
                                                <option key={comp.value} value={comp.value}>
                                                    {comp.label}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.component && (
                                            <p className="mt-1 text-sm text-red-600">{errors.component}</p>
                                        )}
                                    </div>
                                </div>

                                {/* Action Buttons */}
                                <div className="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="inline-flex w-full justify-center rounded-md bg-purple-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-purple-600 sm:ml-3 sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {processing ? 'Converting...' : 'Convert Ticket'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={handleClose}
                                        disabled={processing}
                                        className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
