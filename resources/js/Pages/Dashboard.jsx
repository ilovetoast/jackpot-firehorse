import { useState } from 'react'
import { useForm, Link, router, usePage } from '@inertiajs/react'
import PlanLimitIndicator from '../Components/PlanLimitIndicator'
import AppFooter from '../Components/AppFooter'
import AppNav from '../Components/AppNav'

export default function Dashboard({ auth, tenant, brand, plan_limits }) {
    const { auth: authFromPage } = usePage().props

    return (
        <div className="min-h-full">
            <AppNav brand={authFromPage?.activeBrand || auth.activeBrand} tenant={tenant} />

            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <h2 className="text-3xl font-bold tracking-tight text-gray-900">Dashboard</h2>
                    <p className="mt-2 text-sm text-gray-700">Welcome to your asset management dashboard</p>
                </div>

                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                        <dt className="truncate text-sm font-medium text-gray-500">Total Assets</dt>
                        <dd className="mt-1 text-3xl font-semibold tracking-tight text-gray-900">0</dd>
                    </div>
                    <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                        <dt className="truncate text-sm font-medium text-gray-500">Storage (size)</dt>
                        <dd className="mt-1 text-3xl font-semibold tracking-tight text-gray-900">0 MB</dd>
                    </div>
                    <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6">
                        <dt className="truncate text-sm font-medium text-gray-500">Downloads</dt>
                        <dd className="mt-1 text-3xl font-semibold tracking-tight text-gray-900">0</dd>
                    </div>
                </div>

                <div className="mt-8">
                    <div className="overflow-hidden rounded-lg bg-white shadow">
                        <div className="px-4 py-5 sm:p-6">
                            <h3 className="text-base font-semibold leading-6 text-gray-900">Recent Activity</h3>
                            <div className="mt-5">
                                <div className="text-center py-12">
                                    <svg
                                        className="mx-auto h-12 w-12 text-gray-400"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"
                                        />
                                    </svg>
                                    <h3 className="mt-2 text-sm font-semibold text-gray-900">No activity</h3>
                                    <p className="mt-1 text-sm text-gray-500">Get started by adding your first asset.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <AppFooter />
        </div>
    )
}
