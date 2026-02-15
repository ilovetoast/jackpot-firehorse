import { useForm, router } from '@inertiajs/react'
import { useState } from 'react'
import Avatar from '../Avatar'
import ConfirmDialog from '../ConfirmDialog'

/**
 * Brand member management section — invite, add, roles, remove.
 * Used in Brands/Edit Members tab.
 */
export default function BrandMembersSection({ brandId, users = [], availableUsers = [], pendingInvitations = [], brandRoles = ['viewer', 'contributor', 'brand_manager', 'admin'] }) {
    return (
        <div className="space-y-8">
            {/* Add User Form */}
            <div className="rounded-xl border border-gray-200/80 bg-gray-50/40 p-6">
                <div className="flex items-center gap-3 mb-4">
                    <div className="flex-shrink-0">
                        <svg className="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.375 21c-2.115 0-4.1-.56-5.375-1.765Z" />
                        </svg>
                    </div>
                    <div className="flex-1">
                        <h4 className="text-sm font-semibold text-gray-900">Add team members</h4>
                        <p className="text-xs text-gray-500 mt-0.5">Invite new users or add existing company members</p>
                    </div>
                </div>
                <UserInviteForm brandId={brandId} defaultRole="viewer" brandRoles={brandRoles} />
            </div>

            {/* Recommended Users (company users not on brand) */}
            {availableUsers && availableUsers.length > 0 && (
                <div>
                    <h4 className="text-sm font-semibold text-gray-900 mb-3">Add existing company members</h4>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {availableUsers.map((user) => (
                            <RecommendedUserCard key={user.id} user={user} brandId={brandId} brandRoles={brandRoles} />
                        ))}
                    </div>
                </div>
            )}

            {/* Pending Invitations */}
            {pendingInvitations && pendingInvitations.length > 0 && (
                <div>
                    <h4 className="text-sm font-semibold text-gray-900 mb-3">Pending invitations</h4>
                    <div className="rounded-xl border border-amber-200/80 bg-amber-50/40 divide-y divide-amber-200/60 overflow-hidden">
                        {pendingInvitations.map((invitation) => (
                            <PendingInvitationCard key={invitation.id} invitation={invitation} brandId={brandId} />
                        ))}
                    </div>
                </div>
            )}

            {/* Existing Members */}
            <div>
                <h4 className="text-sm font-semibold text-gray-900 mb-3">Brand members</h4>
                {users && users.length > 0 ? (
                    <ul className="divide-y divide-gray-100 rounded-xl border border-gray-200/80 bg-white overflow-hidden">
                        {users.map((user) => (
                            <UserManagementCard key={user.id} user={user} brandId={brandId} brandRoles={brandRoles} />
                        ))}
                    </ul>
                ) : (
                    <div className="rounded-xl border border-gray-200/80 bg-gray-50/50 py-12 text-center">
                        <p className="text-sm text-gray-500">No members assigned to this brand yet.</p>
                    </div>
                )}
            </div>
        </div>
    )
}

function UserInviteForm({ brandId, defaultRole = 'viewer', brandRoles }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        role: defaultRole === 'member' ? 'viewer' : defaultRole,
    })

    const handleSubmit = (e) => {
        e.preventDefault()
        post(`/app/brands/${brandId}/users/invite`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        })
    }

    const roles = brandRoles && brandRoles.length > 0 ? brandRoles : ['viewer', 'contributor', 'brand_manager', 'admin']

    return (
        <form onSubmit={handleSubmit} className="space-y-3">
            <div className="flex gap-2">
                <div className="flex-1">
                    <input
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="Enter email address"
                        className="block w-full rounded-md border-0 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        required
                    />
                    {errors.email && <p className="mt-1 text-xs text-red-600">{errors.email}</p>}
                </div>
                <div className="flex-shrink-0">
                    <select
                        value={data.role}
                        onChange={(e) => setData('role', e.target.value)}
                        className="block rounded-md border-0 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                    >
                        {roles.map((r) => (
                            <option key={r} value={r}>{r.replace('_', ' ')}</option>
                        ))}
                    </select>
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="flex-shrink-0 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                >
                    {processing ? 'Sending…' : 'Send invite'}
                </button>
            </div>
        </form>
    )
}

function RecommendedUserCard({ user, brandId, brandRoles }) {
    const { post, processing } = useForm()
    const [role, setRole] = useState('viewer')

    const handleAdd = () => {
        post(`/app/brands/${brandId}/users/${user.id}/add`, {
            data: { role },
            preserveScroll: true,
        })
    }

    const roles = brandRoles && brandRoles.length > 0 ? brandRoles : ['viewer', 'contributor', 'brand_manager', 'admin']

    return (
        <div className="flex items-center gap-3 p-3 bg-white rounded-lg border border-gray-200 hover:border-indigo-200 transition-colors">
            <Avatar
                avatarUrl={user.avatar_url}
                firstName={user.first_name}
                lastName={user.last_name}
                email={user.email}
                size="sm"
            />
            <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-900 truncate">{user.name || user.email}</p>
                {user.name && <p className="text-xs text-gray-500 truncate">{user.email}</p>}
            </div>
            <div className="flex items-center gap-2">
                <select
                    value={role}
                    onChange={(e) => setRole(e.target.value)}
                    className="text-xs rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                    onClick={(e) => e.stopPropagation()}
                >
                    {roles.map((r) => (
                        <option key={r} value={r}>{r.replace('_', ' ')}</option>
                    ))}
                </select>
                <button
                    type="button"
                    onClick={handleAdd}
                    disabled={processing}
                    className="flex-shrink-0 rounded-full bg-indigo-600 p-1.5 text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                    title="Add to brand"
                >
                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                </button>
            </div>
        </div>
    )
}

function PendingInvitationCard({ invitation, brandId }) {
    const { post, processing } = useForm()

    const handleResend = () => {
        post(`/app/brands/${brandId}/invitations/${invitation.id}/resend`, {
            preserveScroll: true,
        })
    }

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A'
        const d = new Date(dateString)
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    }

    return (
        <div className="p-4 flex items-center justify-between">
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                    <p className="text-sm font-medium text-gray-900">{invitation.email}</p>
                    {invitation.role && (
                        <span className="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                            {invitation.role}
                        </span>
                    )}
                </div>
                <p className="mt-1 text-xs text-gray-500">
                    {invitation.sent_at ? `Sent: ${formatDate(invitation.sent_at)}` : `Created: ${formatDate(invitation.created_at)}`}
                </p>
            </div>
            <button
                type="button"
                onClick={handleResend}
                disabled={processing}
                className="ml-4 text-sm font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-50"
            >
                {processing ? 'Resending…' : 'Resend'}
            </button>
        </div>
    )
}

function UserManagementCard({ user, brandId, brandRoles }) {
    const { delete: destroy, processing } = useForm()
    const [isEditing, setIsEditing] = useState(false)
    const [role, setRole] = useState(user.role || 'viewer')
    const [updatingRole, setUpdatingRole] = useState(false)
    const [showRemoveConfirm, setShowRemoveConfirm] = useState(false)

    const handleRoleUpdate = () => {
        setUpdatingRole(true)
        router.put(`/app/brands/${brandId}/users/${user.id}/role`, { role }, {
            preserveScroll: true,
            onSuccess: () => {
                setIsEditing(false)
                setUpdatingRole(false)
            },
            onError: () => setUpdatingRole(false),
        })
    }

    const confirmRemove = () => {
        destroy(`/app/brands/${brandId}/users/${user.id}`, {
            preserveScroll: true,
            onSuccess: () => setShowRemoveConfirm(false),
        })
    }

    const roles = brandRoles && brandRoles.length > 0 ? brandRoles : ['viewer', 'contributor', 'brand_manager', 'admin']

    return (
        <li className="px-4 py-3">
            <div className="flex items-center gap-3">
                <Avatar
                    avatarUrl={user.avatar_url}
                    firstName={user.first_name}
                    lastName={user.last_name}
                    email={user.email}
                    size="md"
                />
                <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-gray-900">{user.name || user.email}</p>
                    <p className="text-sm text-gray-500 truncate">{user.email}</p>
                </div>
                <div className="flex items-center gap-2">
                    {isEditing ? (
                        <>
                            <select
                                value={role}
                                onChange={(e) => setRole(e.target.value)}
                                className="text-sm rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                            >
                                {roles.map((r) => (
                                    <option key={r} value={r}>{r.replace('_', ' ')}</option>
                                ))}
                            </select>
                            <button
                                type="button"
                                onClick={handleRoleUpdate}
                                disabled={updatingRole}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-50"
                            >
                                {updatingRole ? 'Saving…' : 'Save'}
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setIsEditing(false)
                                    setRole(user.role || 'viewer')
                                }}
                                className="text-sm font-medium text-gray-600 hover:text-gray-800"
                            >
                                Cancel
                            </button>
                        </>
                    ) : (
                        <>
                            {user.role && (
                                <span className="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                                    {user.role.replace('_', ' ')}
                                </span>
                            )}
                            <button
                                type="button"
                                onClick={() => setIsEditing(true)}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                            >
                                Edit role
                            </button>
                            <button
                                type="button"
                                onClick={() => setShowRemoveConfirm(true)}
                                disabled={processing}
                                className="text-sm font-medium text-red-600 hover:text-red-800 disabled:opacity-50"
                            >
                                Remove
                            </button>
                        </>
                    )}
                </div>
            </div>
            <ConfirmDialog
                open={showRemoveConfirm}
                onClose={() => setShowRemoveConfirm(false)}
                onConfirm={confirmRemove}
                title="Remove member"
                message={`Remove ${user.name || user.email} from this brand?`}
                variant="warning"
                confirmText="Remove"
            />
        </li>
    )
}
