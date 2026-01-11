import { useForm, Link, usePage, router } from '@inertiajs/react'
import { useState } from 'react'
import { useForm as useConvertForm } from '@inertiajs/react'
import AppNav from '../../../../Components/AppNav'
import AppFooter from '../../../../Components/AppFooter'
import Avatar from '../../../../Components/Avatar'
import BrandAvatar from '../../../../Components/BrandAvatar'
import SLAPanel from '../../../../Components/Tickets/SLAPanel'
import AssignmentControls from '../../../../Components/Tickets/AssignmentControls'
import TicketActionsToolbar from '../../../../Components/Tickets/TicketActionsToolbar'
import CustomerInfoPanel from '../../../../Components/Tickets/CustomerInfoPanel'
import RelatedTicketsPanel from '../../../../Components/Tickets/RelatedTicketsPanel'
import ConvertTicketModal from '../../../../Components/Tickets/ConvertTicketModal'
import InternalNoteForm from '../../../../Components/Tickets/InternalNoteForm'
import PublicReplyForm from '../../../../Components/Tickets/PublicReplyForm'
import TicketLinkForm from '../../../../Components/Tickets/TicketLinkForm'
import AuditLogTimeline from '../../../../Components/Tickets/AuditLogTimeline'
import SuggestionPanel from '../../../../Components/Automation/SuggestionPanel'
import { 
    CreditCardIcon, 
    WrenchScrewdriverIcon, 
    LightBulbIcon, 
    BugAntIcon,
    KeyIcon,
    ChatBubbleLeftRightIcon,
    DocumentTextIcon,
    PaperClipIcon,
    LinkIcon,
    ClockIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline'

export default function AdminTicketsShow({ ticket, publicMessages, internalNotes, slaData, auditLog, permissions, staffUsers, filterOptions, suggestions = [] }) {
    const { auth } = usePage().props
    const [activeTab, setActiveTab] = useState('messages')
    const [showConvertModal, setShowConvertModal] = useState(false)
    
    const convertForm = useConvertForm({
        severity: '',
        environment: '',
        component: '',
    })

    const getStatusBadge = (status) => {
        const statusConfig = {
            open: { label: 'Open', color: 'bg-blue-100 text-blue-800' },
            waiting_on_user: { label: 'Waiting on User', color: 'bg-yellow-100 text-yellow-800' },
            waiting_on_support: { label: 'Waiting on Support', color: 'bg-yellow-100 text-yellow-800' },
            in_progress: { label: 'In Progress', color: 'bg-purple-100 text-purple-800' },
            blocked: { label: 'Blocked', color: 'bg-red-100 text-red-800' },
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

    const getTypeBadge = (type) => {
        const typeConfig = {
            tenant: { label: 'Tenant', color: 'bg-blue-100 text-blue-800' },
            tenant_internal: { label: 'Tenant Internal', color: 'bg-orange-100 text-orange-800' },
            internal: { label: 'Internal', color: 'bg-purple-100 text-purple-800' },
        }

        const config = typeConfig[type] || { label: type, color: 'bg-gray-100 text-gray-800' }
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

    const handleConvert = () => {
        setShowConvertModal(true)
    }

    const confirmConvert = () => {
        convertForm.post(`/app/admin/support/tickets/${ticket.id}/convert`, {
            preserveScroll: true,
            onSuccess: () => {
                setShowConvertModal(false)
                convertForm.reset()
            },
        })
    }

    const tabs = [
        { id: 'messages', name: 'Public Messages', icon: ChatBubbleLeftRightIcon },
        { id: 'notes', name: 'Internal Notes', icon: DocumentTextIcon },
        { id: 'attachments', name: 'Attachments', icon: PaperClipIcon },
        { id: 'links', name: 'Linked Items', icon: LinkIcon },
    ]

    if (permissions?.canViewAuditLog) {
        tabs.push({ id: 'audit', name: 'Audit Log', icon: ClockIcon })
    }

    return (
        <div className="min-h-full bg-gray-50">
            <AppNav brand={auth.activeBrand} tenant={auth.tenant} />
            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-6">
                    <Link
                        href="/app/admin/support/tickets"
                        className="text-sm text-gray-500 hover:text-gray-700 mb-4 inline-block"
                    >
                        ← Back to tickets
                    </Link>
                    <div className="flex items-center justify-between">
                        <div>
                            <div className="flex items-center gap-3">
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">{ticket.ticket_number}</h1>
                                {getTypeBadge(ticket.type)}
                                {getStatusBadge(ticket.status)}
                            </div>
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
                                {ticket.tenant && (
                                    <span className="text-sm text-gray-700">Tenant: {ticket.tenant.name}</span>
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
                            <p className="text-xs text-gray-500">Created {new Date(ticket.created_at).toLocaleString()}</p>
                            <p className="text-xs text-gray-500">Updated {new Date(ticket.updated_at).toLocaleString()}</p>
                        </div>
                    </div>
                </div>

                {/* Info Panel */}
                <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden mb-6">
                    <div className="px-6 py-4 border-b border-gray-200">
                        <h2 className="text-lg font-semibold text-gray-900">Ticket Information</h2>
                    </div>
                    <div className="px-6 py-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="text-xs font-medium text-gray-500 uppercase">Created By</label>
                            <div className="mt-1 flex items-center gap-2">
                                {ticket.created_by ? (
                                    <>
                                        <Avatar
                                            avatarUrl={ticket.created_by.avatar_url}
                                            firstName={ticket.created_by.first_name}
                                            lastName={ticket.created_by.last_name}
                                            email={ticket.created_by.email}
                                            size="sm"
                                        />
                                        <span className="text-sm text-gray-900">{ticket.created_by.name}</span>
                                    </>
                                ) : (
                                    <span className="text-sm text-gray-500">—</span>
                                )}
                            </div>
                        </div>
                        <div>
                            <label className="text-xs font-medium text-gray-500 uppercase">Assigned Team</label>
                            <p className="mt-1 text-sm text-gray-900">{ticket.assigned_team ? ticket.assigned_team.charAt(0).toUpperCase() + ticket.assigned_team.slice(1) : '—'}</p>
                        </div>
                        <div>
                            <label className="text-xs font-medium text-gray-500 uppercase">Assigned User</label>
                            <p className="mt-1 text-sm text-gray-900">{ticket.assigned_to?.name || '—'}</p>
                        </div>
                        {/* Engineering Fields - Only show for internal engineering tickets */}
                        {ticket.type === 'internal' && ticket.assigned_team === 'engineering' && (
                            <>
                                {ticket.severity && (
                                    <div>
                                        <label className="text-xs font-medium text-gray-500 uppercase">Severity</label>
                                        <p className="mt-1 text-sm text-gray-900">
                                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                ticket.severity === 'P0' ? 'bg-red-100 text-red-800' :
                                                ticket.severity === 'P1' ? 'bg-orange-100 text-orange-800' :
                                                ticket.severity === 'P2' ? 'bg-yellow-100 text-yellow-800' :
                                                'bg-blue-100 text-blue-800'
                                            }`}>
                                                {ticket.severity}
                                            </span>
                                        </p>
                                    </div>
                                )}
                                {ticket.environment && (
                                    <div>
                                        <label className="text-xs font-medium text-gray-500 uppercase">Environment</label>
                                        <p className="mt-1 text-sm text-gray-900 capitalize">{ticket.environment}</p>
                                    </div>
                                )}
                                {ticket.component && (
                                    <div>
                                        <label className="text-xs font-medium text-gray-500 uppercase">Component</label>
                                        <p className="mt-1 text-sm text-gray-900 capitalize">{ticket.component}</p>
                                    </div>
                                )}
                                {ticket.error_fingerprint && (
                                    <div className="md:col-span-2">
                                        <label className="text-xs font-medium text-gray-500 uppercase">Error Fingerprint</label>
                                        <p className="mt-1 text-sm text-gray-900 font-mono">{ticket.error_fingerprint}</p>
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>

                {/* Related Tickets Panel */}
                <RelatedTicketsPanel ticket={ticket} />

                {/* Customer Information Panel (for tenant tickets) */}
                <CustomerInfoPanel ticket={ticket} />

                {/* Ticket Actions Toolbar */}
                <TicketActionsToolbar 
                    ticket={ticket} 
                    permissions={permissions} 
                    onConvert={handleConvert}
                />

                {/* Assignment Controls */}
                {permissions?.canAssign && (
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden mb-6">
                        <AssignmentControls ticket={ticket} staffUsers={staffUsers} />
                    </div>
                )}

                {/* SLA Panel */}
                {permissions?.canViewSLA && slaData && (
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden mb-6">
                        <SLAPanel slaData={slaData} />
                    </div>
                )}

                {/* AI Suggestions Panel */}
                {suggestions && suggestions.length > 0 && (
                    <div className="mb-6">
                        <SuggestionPanel suggestions={suggestions} />
                    </div>
                )}

                {/* Tabs */}
                <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                    <div className="border-b border-gray-200">
                        <nav className="-mb-px flex space-x-8 px-6" aria-label="Tabs">
                            {tabs.map((tab) => {
                                const Icon = tab.icon
                                return (
                                    <button
                                        key={tab.id}
                                        onClick={() => setActiveTab(tab.id)}
                                        className={`
                                            flex items-center gap-2 py-4 px-1 border-b-2 font-medium text-sm
                                            ${activeTab === tab.id
                                                ? 'border-indigo-500 text-indigo-600'
                                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                            }
                                        `}
                                    >
                                        <Icon className="h-5 w-5" />
                                        {tab.name}
                                    </button>
                                )
                            })}
                        </nav>
                    </div>

                    <div className="p-6">
                        {/* Public Messages Tab */}
                        {activeTab === 'messages' && (
                            <div className="space-y-6">
                                {permissions?.canAssign && (
                                    <PublicReplyForm ticketId={ticket.id} />
                                )}
                                <div className="space-y-4">
                                    {publicMessages.length === 0 ? (
                                        <p className="text-sm text-gray-500">No public messages yet.</p>
                                    ) : (
                                        publicMessages.map((message) => (
                                            <div key={message.id} className="flex items-start gap-3 pb-4 border-b border-gray-200 last:border-0">
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
                                                            <p className="text-xs text-gray-500">{new Date(message.created_at).toLocaleString()}</p>
                                                        </div>
                                                    </div>
                                                    <div className="mt-2 text-sm text-gray-700 whitespace-pre-wrap">
                                                        {message.body}
                                                    </div>
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Internal Notes Tab */}
                        {activeTab === 'notes' && (
                            <div className="space-y-6">
                                {permissions?.canAddInternalNote && (
                                    <InternalNoteForm ticketId={ticket.id} />
                                )}
                                <div className="space-y-4">
                                    {internalNotes.length === 0 ? (
                                        <p className="text-sm text-gray-500">No internal notes yet.</p>
                                    ) : (
                                        internalNotes.map((note) => (
                                            <div key={note.id} className="flex items-start gap-3 pb-4 border-b border-gray-200 last:border-0 bg-orange-50 p-4 rounded-lg">
                                                {note.user ? (
                                                    <Avatar
                                                        avatarUrl={note.user.avatar_url}
                                                        firstName={note.user.first_name}
                                                        lastName={note.user.last_name}
                                                        email={note.user.email}
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
                                                                {note.user?.name || 'System'}
                                                            </p>
                                                            <p className="text-xs text-gray-500">{new Date(note.created_at).toLocaleString()}</p>
                                                        </div>
                                                        <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-orange-100 text-orange-800">
                                                            Internal
                                                        </span>
                                                    </div>
                                                    <div className="mt-2 text-sm text-gray-700 whitespace-pre-wrap">
                                                        {note.body}
                                                    </div>
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Attachments Tab */}
                        {activeTab === 'attachments' && (
                            <div className="space-y-4">
                                {ticket.attachments && ticket.attachments.length > 0 ? (
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
                                ) : (
                                    <p className="text-sm text-gray-500">No attachments.</p>
                                )}
                            </div>
                        )}

                        {/* Linked Items Tab */}
                        {activeTab === 'links' && (
                            <div className="space-y-6">
                                {permissions?.canAddInternalNote && (
                                    <TicketLinkForm ticketId={ticket.id} />
                                )}
                                <div className="space-y-4">
                                    {ticket.links && ticket.links.length > 0 ? (
                                        ticket.links.map((link) => (
                                            <div key={link.id} className="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
                                                <div className="flex-1">
                                                    <div className="flex items-center gap-2 mb-1">
                                                        <p className="text-sm font-medium text-gray-900">
                                                            {link.link_type} - {link.linkable_type}
                                                        </p>
                                                        {link.designation && (
                                                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                                                                link.designation === 'primary' ? 'bg-indigo-100 text-indigo-800' :
                                                                link.designation === 'duplicate' ? 'bg-yellow-100 text-yellow-800' :
                                                                'bg-gray-100 text-gray-800'
                                                            }`}>
                                                                {link.designation}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className="text-xs text-gray-500">ID: {link.linkable_id}</p>
                                                    {link.metadata && Object.keys(link.metadata).length > 0 && (
                                                        <p className="text-xs text-gray-400 mt-1 font-mono">
                                                            {JSON.stringify(link.metadata)}
                                                        </p>
                                                    )}
                                                </div>
                                                <button className="text-sm text-red-600 hover:text-red-900">
                                                    Remove
                                                </button>
                                            </div>
                                        ))
                                    ) : (
                                        <p className="text-sm text-gray-500">No linked items.</p>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Audit Log Tab */}
                        {activeTab === 'audit' && (
                            <div>
                                {auditLog && auditLog.length > 0 ? (
                                    <AuditLogTimeline auditLog={auditLog} />
                                ) : (
                                    <p className="text-sm text-gray-500">No audit log entries.</p>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </main>
            <AppFooter />

            {/* Convert Ticket Modal */}
            <ConvertTicketModal
                open={showConvertModal}
                onClose={() => setShowConvertModal(false)}
                form={convertForm}
                ticketId={ticket.id}
                filterOptions={filterOptions}
            />
        </div>
    )
}
