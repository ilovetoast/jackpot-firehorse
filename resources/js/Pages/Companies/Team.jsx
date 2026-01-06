import { Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import PlanLimitCallout from '../../Components/PlanLimitCallout'

export default function Team({ tenant, members, current_user_count, max_users, user_limit_reached }) {
    const { auth } = usePage().props

    const handleRemoveMember = (userId) => {
        if (confirm('Are you sure you want to remove this team member?')) {
            router.delete(`/app/companies/${tenant.id}/team/${userId}`, {
                preserveScroll: true,
            })
        }
    }

    const formatDate = (dateString) => {
        const date = new Date(dateString)
        return date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        })
    }

    const getInitials = (firstName, lastName) => {
        const first = firstName?.charAt(0).toUpperCase() || ''
        const last = lastName?.charAt(0).toUpperCase() || ''
        return first + last || firstName?.charAt(0).toUpperCase() || '?'
    }

    return (
        <div className="min-h-screen bg-gray-50">
            <AppNav />
            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                {/* Header */}
                <div className="mb-8">
                    <h1 className="text-3xl font-bold text-gray-900">Team Members</h1>
                    <p className="mt-2 text-sm text-gray-600">
                        Manage members and invitations for {tenant.name} ({current_user_count} of {max_users === Number.MAX_SAFE_INTEGER || max_users > 1000 ? '∞' : max_users} users)
                    </p>
                </div>

                {/* Invite Button */}
                <div className="mb-6 flex justify-end">
                    <button
                        type="button"
                        className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        <svg className="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </svg>
                        Invite Member
                    </button>
                </div>

                {/* Active Members Section */}
                <div className="mb-6">
                    <div className="flex items-center justify-between mb-4">
                        <div>
                            <h2 className="text-lg font-semibold text-gray-900">Active Members</h2>
                            <p className="mt-1 text-sm text-gray-500">People who have joined {tenant.name}</p>
                        </div>
                        <span className="text-sm text-gray-500">{current_user_count} of {max_users === Number.MAX_SAFE_INTEGER || max_users > 1000 ? '∞' : max_users} users</span>
                    </div>

                    {/* User Limit Reached Banner */}
                    {user_limit_reached && (
                        <PlanLimitCallout
                            title="User limit reached"
                            message={`Users limit reached (${current_user_count} of ${max_users === Number.MAX_SAFE_INTEGER || max_users > 1000 ? 'unlimited' : max_users}). Please upgrade your plan.`}
                        />
                    )}

                    {/* Members List */}
                    <div className="space-y-4">
                        {members.map((member) => {
                            const isOwner = member.role === 'Owner'
                            const canRemove = !isOwner && member.id !== auth.user?.id

                            return (
                                <div key={member.id} className="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-4">
                                    <div className="flex items-center space-x-4">
                                        {/* Avatar */}
                                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gray-200 text-sm font-medium text-gray-700">
                                            {getInitials(member.first_name, member.last_name)}
                                        </div>

                                        {/* Member Info */}
                                        <div>
                                            <div className="flex items-center space-x-2">
                                                <span className="text-sm font-medium text-gray-900">
                                                    {member.first_name} {member.last_name}
                                                </span>
                                                <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${
                                                    isOwner 
                                                        ? 'bg-orange-100 text-orange-800' 
                                                        : 'bg-gray-100 text-gray-800'
                                                }`}>
                                                    {isOwner && (
                                                        <span className="mr-1">👑</span>
                                                    )}
                                                    {member.role}
                                                </span>
                                            </div>
                                            <p className="mt-1 text-sm text-gray-500">{member.email}</p>
                                            <p className="mt-1 text-xs text-gray-400">
                                                Joined {formatDate(member.joined_at)}
                                            </p>
                                        </div>
                                    </div>

                                    {/* Remove Button */}
                                    {canRemove && (
                                        <button
                                            type="button"
                                            onClick={() => handleRemoveMember(member.id)}
                                            className="text-red-600 hover:text-red-800"
                                        >
                                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.12m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                            </svg>
                                        </button>
                                    )}
                                </div>
                            )
                        })}
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
