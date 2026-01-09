import { useForm, Link, usePage, router } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import ConfirmDialog from '../../../Components/ConfirmDialog'
import Avatar from '../../../Components/Avatar'
import BrandAvatar from '../../../Components/BrandAvatar'
import { 
    CreditCardIcon, 
    WrenchScrewdriverIcon, 
    LightBulbIcon, 
    BugAntIcon,
    KeyIcon,
} from '@heroicons/react/24/outline'

export default function TicketsShow({ ticket, plan_limits }) {
    const { auth, old } = usePage().props

    const { data, setData, post, processing, errors } = useForm({
        body: '',
        attachments: [],
    })

    const [attachmentFiles, setAttachmentFiles] = useState([])
    const [showCloseConfirm, setShowCloseConfirm] = useState(false)

    // Sync form data with old input when validation errors occur
    // This ensures form data is preserved after validation errors
    useEffect(() => {
        if (old?.body !== undefined) {
            setData('body', old.body)
        }
    }, [old, setData])

    const handleSubmit = (e) => {
        e.preventDefault()
        
        // Set attachments in form data
        setData('attachments', attachmentFiles)

        post(`/app/support/tickets/${ticket.id}/reply`, {
            forceFormData: true,
            preserveScroll: true,
            // Inertia should preserve form data automatically on validation errors
            // The useEffect above will sync with old values if they exist
        })
    }

    const handleFileChange = (e) => {
        const files = Array.from(e.target.files)
        setAttachmentFiles(files)
    }

    const handleCloseTicket = () => {
        setShowCloseConfirm(true)
    }

    const confirmCloseTicket = () => {
        router.post(`/app/support/tickets/${ticket.id}/close`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                setShowCloseConfirm(false)
            },
        })
    }

    const getStatusBadge = (status) => {
        const statusConfig = {
            open: { label: 'Open', color: 'bg-blue-100 text-blue-800' },
            waiting_on_support: { label: 'Waiting on Support', color: 'bg-yellow-100 text-yellow-800' },
            in_progress: { label: 'In Progress', color: 'bg-purple-100 text-purple-800' },
            resolved: { label: 'Resolved', color: 'bg-green-100 text-green-800' },
            closed: { label: 'Closed', color: 'bg-gray-100 text-gray-800' },
        }

        const config = statusConfig[status] || { label: status, color: 'bg-gray-100 text-gray-800' }
        return (
            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${config.color}`}>
                {config.label}
            </span>
        )
    }

    const getCategoryIcon = (categoryValue) => {
        const iconConfig = {
            billing: { Icon: CreditCardIcon, color: 'text-green-600' },
            technical_issue: { Icon: WrenchScrewdriverIcon, color: 'text-blue-600' },
            bug: { Icon: BugAntIcon, color: 'text-red-600' },
            feature_request: { Icon: LightBulbIcon, color: 'text-yellow-600' },
            account_access: { Icon: KeyIcon, color: 'text-purple-600' },
        }

        const config = iconConfig[categoryValue] || { Icon: WrenchScrewdriverIcon, color: 'text-gray-600' }
        const { Icon, color } = config
        return <Icon className={`h-5 w-5 ${color}`} />
    }

    return (
        <div className="min-h-full bg-gray-50">
            <AppNav brand={auth.activeBrand} tenant={auth.tenant} />
            <main className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-6">
                    <Link
                        href="/app/support/tickets"
                        className="text-sm text-gray-500 hover:text-gray-700 mb-4 inline-block"
                    >
                        ← Back to tickets
                    </Link>
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-gray-900">{ticket.ticket_number}</h1>
                            {ticket.subject && (
                                <p className="mt-2 text-lg text-gray-900">{ticket.subject}</p>
                            )}
                            <div className="mt-2 flex items-center gap-4 flex-wrap">
                                {ticket.category && (
                                    <div className="flex items-center gap-2">
                                        {getCategoryIcon(ticket.category_value)}
                                        <span className="text-sm text-gray-700">Category: {ticket.category}</span>
                                    </div>
                                )}
                                {ticket.brands.length > 0 && (
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="text-sm text-gray-700">Brands:</span>
                                        {ticket.brands.map((brand) => (
                                            <div key={brand.id} className="flex items-center gap-1.5">
                                                <BrandAvatar
                                                    logoPath={brand.logo_path}
                                                    iconPath={brand.icon_path}
                                                    name={brand.name}
                                                    primaryColor={brand.primary_color}
                                                    icon={brand.icon}
                                                    iconBgColor={brand.icon_bg_color}
                                                    showIcon={true}
                                                    size="sm"
                                                />
                                                <span className="text-sm text-gray-700">{brand.name}</span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                        <div className="text-right">
                            {getStatusBadge(ticket.status)}
                            <p className="mt-2 text-xs text-gray-500">Created {ticket.created_at}</p>
                            {ticket.status !== 'closed' && ticket.status !== 'resolved' && (
                                <button
                                    onClick={handleCloseTicket}
                                    className="mt-3 inline-flex items-center rounded-md bg-gray-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-600"
                                >
                                    Close Ticket
                                </button>
                            )}
                        </div>
                    </div>
                </div>

                {/* Ticket Messages */}
                <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden mb-6">
                    <div className="px-6 py-4 border-b border-gray-200">
                        <h2 className="text-lg font-semibold text-gray-900">Conversation</h2>
                    </div>
                    <div className="divide-y divide-gray-200">
                        {ticket.messages.map((message) => (
                            <div key={message.id} className="px-6 py-4">
                                <div className="flex items-start gap-3">
                                    {message.user ? (
                                        <Avatar
                                            avatarUrl={message.user.avatar_url}
                                            firstName={message.user.first_name}
                                            lastName={message.user.last_name}
                                            email={message.user.email}
                                            size="sm"
                                        />
                                    ) : (
                                        <div className="h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center flex-shrink-0">
                                            <span className="text-xs text-gray-500">S</span>
                                        </div>
                                    )}
                                    <div className="flex-1">
                                        <div className="flex items-start justify-between mb-2">
                                            <div>
                                                <p className="text-sm font-medium text-gray-900">
                                                    {message.user?.name || 'System'}
                                                </p>
                                                <p className="text-xs text-gray-500">{message.created_at}</p>
                                            </div>
                                        </div>
                                        <div className="mt-2 text-sm text-gray-700 whitespace-pre-wrap">
                                            {message.body}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Attachments */}
                {ticket.attachments.length > 0 && (
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden mb-6">
                        <div className="px-6 py-4 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Attachments</h2>
                        </div>
                        <div className="px-6 py-4">
                            <ul className="divide-y divide-gray-200">
                                {ticket.attachments.map((attachment) => (
                                    <li key={attachment.id} className="py-3 flex items-center justify-between">
                                        <div className="flex items-center">
                                            <svg
                                                className="h-5 w-5 text-gray-400 mr-3"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    strokeWidth={2}
                                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                />
                                            </svg>
                                            <div>
                                                <p className="text-sm font-medium text-gray-900">{attachment.file_name}</p>
                                                <p className="text-xs text-gray-500">{attachment.file_size}</p>
                                            </div>
                                        </div>
                                        <a
                                            href={attachment.download_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-sm text-indigo-600 hover:text-indigo-900"
                                        >
                                            Download
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )}

                {/* Reply Form */}
                {ticket.status !== 'closed' && ticket.status !== 'resolved' && (
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                        <div className="px-6 py-4 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Reply</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                Your reply will update the ticket status to "Waiting on Support"
                            </p>
                        </div>
                        <form onSubmit={handleSubmit} className="px-6 py-6 space-y-4">
                            <div>
                                <label htmlFor="body" className="block text-sm font-medium leading-6 text-gray-900">
                                    Message <span className="text-red-500">*</span>
                                </label>
                                <textarea
                                    id="body"
                                    rows={6}
                                    maxLength={250}
                                    value={data.body}
                                    onChange={(e) => setData('body', e.target.value)}
                                    className="mt-2 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                    placeholder="Type your reply here..."
                                />
                                <div className="mt-1 flex justify-between items-center">
                                    {errors.body ? (
                                        <p className="text-sm text-red-600">{errors.body}</p>
                                    ) : (
                                        <div></div>
                                    )}
                                    <p className={`text-xs ${
                                        data.body.length > 237 
                                            ? 'text-red-600 font-semibold' 
                                            : data.body.length > 200 
                                            ? 'text-orange-600' 
                                            : 'text-gray-500'
                                    }`}>
                                        {data.body.length.toLocaleString()} / 250 characters
                                    </p>
                                </div>
                            </div>

                            {plan_limits.can_attach_files && (
                                <div>
                                    <label htmlFor="attachments" className="block text-sm font-medium leading-6 text-gray-900">
                                        Attachments (Optional)
                                    </label>
                                    <input
                                        type="file"
                                        id="attachments"
                                        multiple
                                        onChange={handleFileChange}
                                        className="mt-2 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                                    />
                                    <p className="mt-1 text-xs text-gray-500">
                                        Maximum {plan_limits.max_attachment_size} per file, up to {plan_limits.max_attachments} files
                                    </p>
                                    {errors.attachments && <p className="mt-1 text-sm text-red-600">{errors.attachments}</p>}
                                </div>
                            )}

                            <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-200">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                >
                                    {processing ? 'Sending...' : 'Send Reply'}
                                </button>
                            </div>
                        </form>
                    </div>
                )}

                {ticket.status === 'closed' || ticket.status === 'resolved' ? (
                    <div className="mt-6 rounded-md bg-gray-50 p-4 border border-gray-200">
                        <p className="text-sm text-gray-600">
                            This ticket is {ticket.status}. You can no longer reply to it.
                        </p>
                    </div>
                ) : null}
            </main>
            <AppFooter />
            <ConfirmDialog
                open={showCloseConfirm}
                onClose={() => setShowCloseConfirm(false)}
                onConfirm={confirmCloseTicket}
                title="Close Ticket"
                message="Are you sure you want to close this ticket? You will not be able to reply to it after closing."
                variant="warning"
                confirmText="Close Ticket"
            />
        </div>
    )
}
