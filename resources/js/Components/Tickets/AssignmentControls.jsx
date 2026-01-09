import { useForm } from '@inertiajs/react'

export default function AssignmentControls({ ticket, staffUsers = [] }) {
    const { data, setData, put, processing } = useForm({
        assigned_to_user_id: ticket.assigned_to?.id || '',
        assigned_team: ticket.assigned_team || '',
        status: ticket.status,
    })


    const handleAssignmentSubmit = (e) => {
        e.preventDefault()
        put(`/app/admin/support/tickets/${ticket.id}/assignment`, {
            preserveScroll: true,
        })
    }

    const handleStatusSubmit = (e) => {
        e.preventDefault()
        put(`/app/admin/support/tickets/${ticket.id}/status`, {
            preserveScroll: true,
        })
    }

    return (
        <div className="px-6 py-4">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">Assignment & Status</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                {/* Assignment Form */}
                <form onSubmit={handleAssignmentSubmit} className="space-y-4">
                    <div>
                        <label htmlFor="assigned_team" className="block text-sm font-medium text-gray-700 mb-1">
                            Assigned Team
                        </label>
                        <select
                            id="assigned_team"
                            value={data.assigned_team}
                            onChange={(e) => setData('assigned_team', e.target.value)}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            required
                        >
                            <option value="">Select Team</option>
                            <option value="support">Support</option>
                            <option value="admin">Admin</option>
                            <option value="engineering">Engineering</option>
                        </select>
                    </div>
                    <div>
                        <label htmlFor="assigned_to_user_id" className="block text-sm font-medium text-gray-700 mb-1">
                            Assigned User (Optional)
                        </label>
                        <select
                            id="assigned_to_user_id"
                            value={data.assigned_to_user_id}
                            onChange={(e) => setData('assigned_to_user_id', e.target.value || '')}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        >
                            <option value="">Unassigned</option>
                            {staffUsers.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.first_name} {user.last_name} ({user.email})
                                </option>
                            ))}
                        </select>
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {processing ? 'Updating...' : 'Update Assignment'}
                    </button>
                </form>

                {/* Status Form */}
                <form onSubmit={handleStatusSubmit} className="space-y-4">
                    <div>
                        <label htmlFor="status" className="block text-sm font-medium text-gray-700 mb-1">
                            Status
                        </label>
                        <select
                            id="status"
                            value={data.status}
                            onChange={(e) => setData('status', e.target.value)}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            required
                        >
                            <option value="open">Open</option>
                            <option value="waiting_on_user">Waiting on User</option>
                            <option value="waiting_on_support">Waiting on Support</option>
                            <option value="in_progress">In Progress</option>
                            <option value="blocked">Blocked</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {processing ? 'Updating...' : 'Update Status'}
                    </button>
                </form>
            </div>
        </div>
    )
}
