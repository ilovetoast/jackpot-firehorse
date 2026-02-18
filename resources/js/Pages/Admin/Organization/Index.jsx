import { useState, useEffect, useRef } from 'react'
import { Link, router } from '@inertiajs/react'
import { usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import {
    BuildingOffice2Icon as BuildingOfficeIcon,
    UsersIcon,
    ChartBarIcon,
    ArrowLeftIcon,
    DocumentIcon,
} from '@heroicons/react/24/outline'

/**
 * Organization Management - Companies and Users.
 * Moved from main Admin Dashboard per Command Center refactor.
 */
export default function AdminOrganizationIndex({
    companies: initialCompanies,
    pagination,
    stats,
}) {
    const { auth } = usePage().props
    const urlParams = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : new URLSearchParams()
    const initialTab = urlParams.get('tab') === 'users' ? 'users' : 'companies'
    const [companies, setCompanies] = useState(initialCompanies || [])
    const [activeTab, setActiveTab] = useState(initialTab)
    const [companySearchQuery, setCompanySearchQuery] = useState(urlParams.get('search') || '')
    const companySearchTimeoutRef = useRef(null)
    const [users, setUsers] = useState([])
    const [loadingUsers, setLoadingUsers] = useState(false)
    const [usersPagination, setUsersPagination] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: 0,
    })
    const [userSearchQuery, setUserSearchQuery] = useState('')

    useEffect(() => {
        setCompanies(initialCompanies || [])
    }, [initialCompanies])

    useEffect(() => {
        if (companySearchQuery === '') return
        if (companySearchTimeoutRef.current) clearTimeout(companySearchTimeoutRef.current)
        companySearchTimeoutRef.current = setTimeout(() => {
            router.get('/app/admin/organization', {
                search: companySearchQuery,
                per_page: pagination?.per_page || 10,
            }, { preserveState: true, only: ['companies', 'pagination'] })
        }, 300)
        return () => clearTimeout(companySearchTimeoutRef.current)
    }, [companySearchQuery])

    const loadUsers = (page = 1, perPage = 50, search = '') => {
        setLoadingUsers(true)
        const params = new URLSearchParams({ page, per_page: perPage })
        if (search) params.append('search', search)
        fetch(`/app/admin/api/users?${params}`)
            .then(res => res.json())
            .then(data => {
                setUsers(data.data || [])
                setUsersPagination({
                    current_page: data.current_page || 1,
                    last_page: data.last_page || 1,
                    per_page: data.per_page || 50,
                    total: data.total || 0,
                })
                setLoadingUsers(false)
            })
            .catch(() => setLoadingUsers(false))
    }

    useEffect(() => {
        if (activeTab === 'users') {
            loadUsers(1, 50, userSearchQuery)
        }
    }, [activeTab])

    const s = stats || {}

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-slate-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <Link
                        href="/app/admin"
                        className="inline-flex items-center gap-1 text-sm font-medium text-slate-600 hover:text-slate-900 mb-6"
                    >
                        <ArrowLeftIcon className="h-4 w-4" />
                        Back to Command Center
                    </Link>

                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-slate-900">Organization Management</h1>
                        <p className="mt-2 text-sm text-slate-600">Companies and users</p>
                    </div>

                    {/* Top metrics */}
                    <div className="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-medium text-slate-500 uppercase">Total Tenants</p>
                            <p className="mt-1 text-2xl font-bold text-slate-900">{s.total_companies ?? 0}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-medium text-slate-500 uppercase">Total Users</p>
                            <p className="mt-1 text-2xl font-bold text-slate-900">{s.total_users ?? 0}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-medium text-slate-500 uppercase">Active Subscriptions</p>
                            <p className="mt-1 text-2xl font-bold text-slate-900">{s.active_subscriptions ?? 0}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-medium text-slate-500 uppercase">Total Brands</p>
                            <p className="mt-1 text-2xl font-bold text-slate-900">{s.total_brands ?? 0}</p>
                        </div>
                    </div>

                    {/* Tabs */}
                    <div className="rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <nav className="flex border-b border-slate-200">
                            <button
                                onClick={() => setActiveTab('companies')}
                                className={`px-6 py-4 text-sm font-medium border-b-2 ${
                                    activeTab === 'companies'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-slate-500 hover:text-slate-700'
                                }`}
                            >
                                Companies
                                <span className="ml-2 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs">
                                    {pagination?.total ?? companies.length}
                                </span>
                            </button>
                            <button
                                onClick={() => setActiveTab('users')}
                                className={`px-6 py-4 text-sm font-medium border-b-2 ${
                                    activeTab === 'users'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-slate-500 hover:text-slate-700'
                                }`}
                            >
                                Users
                                <span className="ml-2 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs">
                                    {activeTab === 'users' ? usersPagination.total : s.total_users ?? 0}
                                </span>
                            </button>
                        </nav>

                        {activeTab === 'companies' && (
                            <div className="p-6">
                                <div className="mb-4">
                                    <input
                                        type="text"
                                        placeholder="Search companies..."
                                        value={companySearchQuery}
                                        onChange={(e) => setCompanySearchQuery(e.target.value)}
                                        className="block w-full max-w-md rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    />
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <thead>
                                            <tr>
                                                <th className="py-3 text-left text-sm font-semibold text-slate-900">Company</th>
                                                <th className="py-3 text-left text-sm font-semibold text-slate-900">Plan</th>
                                                <th className="py-3 text-left text-sm font-semibold text-slate-900">Created</th>
                                                <th className="py-3 text-right text-sm font-semibold text-slate-900">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-200">
                                            {companies.map((c) => (
                                                <tr key={c.id}>
                                                    <td className="py-3">
                                                        <Link
                                                            href={`/app/admin/companies/${c.id}`}
                                                            className="font-medium text-indigo-600 hover:text-indigo-500"
                                                        >
                                                            {c.name}
                                                        </Link>
                                                        {c.slug && (
                                                            <span className="ml-2 text-sm text-slate-500">({c.slug})</span>
                                                        )}
                                                    </td>
                                                    <td className="py-3 text-sm text-slate-600">{c.plan_name ?? '—'}</td>
                                                    <td className="py-3 text-sm text-slate-500">{c.created_at ?? '—'}</td>
                                                    <td className="py-3 text-right">
                                                        <Link
                                                            href={`/app/admin/companies/${c.id}`}
                                                            className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                                                        >
                                                            View →
                                                        </Link>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {pagination && pagination.last_page > 1 && (
                                    <div className="mt-4 flex justify-between">
                                        <p className="text-sm text-slate-500">
                                            Page {pagination.current_page} of {pagination.last_page}
                                        </p>
                                        <div className="flex gap-2">
                                            {pagination.current_page > 1 && (
                                                <Link
                                                    href={`/app/admin/organization?page=${pagination.current_page - 1}${companySearchQuery ? `&search=${encodeURIComponent(companySearchQuery)}` : ''}`}
                                                    className="rounded border border-slate-300 px-3 py-1 text-sm hover:bg-slate-50"
                                                >
                                                    Previous
                                                </Link>
                                            )}
                                            {pagination.current_page < pagination.last_page && (
                                                <Link
                                                    href={`/app/admin/organization?page=${pagination.current_page + 1}${companySearchQuery ? `&search=${encodeURIComponent(companySearchQuery)}` : ''}`}
                                                    className="rounded border border-slate-300 px-3 py-1 text-sm hover:bg-slate-50"
                                                >
                                                    Next
                                                </Link>
                                            )}
                                        </div>
                                    </div>
                                )}
                                {companies.length === 0 && (
                                    <p className="py-8 text-center text-sm text-slate-500">No companies found</p>
                                )}
                            </div>
                        )}

                        {activeTab === 'users' && (
                            <div className="p-6">
                                <div className="mb-4 flex gap-4">
                                    <input
                                        type="text"
                                        placeholder="Search users..."
                                        value={userSearchQuery}
                                        onChange={(e) => setUserSearchQuery(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && loadUsers(1, 50, userSearchQuery)}
                                        className="block w-full max-w-md rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => loadUsers(1, 50, userSearchQuery)}
                                        className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                                    >
                                        Search
                                    </button>
                                </div>
                                {loadingUsers ? (
                                    <p className="py-8 text-center text-sm text-slate-500">Loading users...</p>
                                ) : (
                                    <>
                                        <div className="overflow-x-auto">
                                            <table className="min-w-full divide-y divide-slate-200">
                                                <thead>
                                                    <tr>
                                                        <th className="py-3 text-left text-sm font-semibold text-slate-900">User</th>
                                                        <th className="py-3 text-left text-sm font-semibold text-slate-900">Email</th>
                                                        <th className="py-3 text-right text-sm font-semibold text-slate-900">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-200">
                                                    {users.map((u) => (
                                                        <tr key={u.id}>
                                                            <td className="py-3">
                                                                <span className="font-medium text-slate-900">
                                                                    {u.first_name} {u.last_name}
                                                                </span>
                                                            </td>
                                                            <td className="py-3 text-sm text-slate-600">{u.email}</td>
                                                            <td className="py-3 text-right">
                                                                <Link
                                                                    href={`/app/admin/users/${u.id}`}
                                                                    className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                                                                >
                                                                    View →
                                                                </Link>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                        {usersPagination.last_page > 1 && (
                                            <div className="mt-4 flex justify-between">
                                                <p className="text-sm text-slate-500">
                                                    Page {usersPagination.current_page} of {usersPagination.last_page}
                                                </p>
                                                <button
                                                    type="button"
                                                    onClick={() => loadUsers(usersPagination.current_page - 1, 50, userSearchQuery)}
                                                    disabled={usersPagination.current_page <= 1}
                                                    className="rounded border border-slate-300 px-3 py-1 text-sm hover:bg-slate-50 disabled:opacity-50"
                                                >
                                                    Previous
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => loadUsers(usersPagination.current_page + 1, 50, userSearchQuery)}
                                                    disabled={usersPagination.current_page >= usersPagination.last_page}
                                                    className="rounded border border-slate-300 px-3 py-1 text-sm hover:bg-slate-50 disabled:opacity-50"
                                                >
                                                    Next
                                                </button>
                                            </div>
                                        )}
                                        {users.length === 0 && (
                                            <p className="py-8 text-center text-sm text-slate-500">No users found</p>
                                        )}
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
