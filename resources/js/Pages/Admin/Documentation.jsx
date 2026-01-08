import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import { BookOpenIcon, ExclamationTriangleIcon, TrashIcon } from '@heroicons/react/24/outline'

export default function Documentation() {
    const { auth } = usePage().props
    return (
        <div className="min-h-full">
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <div className="flex items-center gap-3 mb-2">
                            <BookOpenIcon className="h-8 w-8 text-indigo-600" />
                            <h1 className="text-3xl font-bold tracking-tight text-gray-900">Admin Documentation</h1>
                        </div>
                        <p className="text-sm text-gray-700">System documentation and operational procedures</p>
                    </div>

                    {/* Brand Deletion Section */}
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden mb-6">
                        <div className="px-6 py-4 border-b border-gray-200 bg-indigo-50">
                            <div className="flex items-center gap-2">
                                <TrashIcon className="h-5 w-5 text-indigo-600" />
                                <h2 className="text-lg font-semibold text-gray-900">Brand Deletion</h2>
                            </div>
                        </div>
                        <div className="px-6 py-6">
                            <div className="prose prose-sm max-w-none">
                                <h3 className="text-base font-semibold text-gray-900 mb-3">What happens when a brand is deleted?</h3>
                                
                                <div className="space-y-4">
                                    <div className="bg-red-50 border-l-4 border-red-400 p-4">
                                        <div className="flex">
                                            <div className="flex-shrink-0">
                                                <ExclamationTriangleIcon className="h-5 w-5 text-red-400" />
                                            </div>
                                            <div className="ml-3">
                                                <h4 className="text-sm font-medium text-red-800">Warning: This action is irreversible</h4>
                                                <p className="mt-2 text-sm text-red-700">
                                                    Deleting a brand permanently removes all associated data. This cannot be undone.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">Data Deletion:</h4>
                                        <ul className="list-disc list-inside space-y-1 text-sm text-gray-700">
                                            <li><strong>Original Asset Files:</strong> All original asset files stored in S3 are permanently deleted</li>
                                            <li><strong>Categories:</strong> All categories associated with the brand are deleted</li>
                                            <li><strong>Brand Invitations:</strong> All pending invitations for the brand are deleted</li>
                                            <li><strong>Brand Logo:</strong> The brand's logo file is deleted from storage</li>
                                        </ul>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">User Relationships:</h4>
                                        <ul className="list-disc list-inside space-y-1 text-sm text-gray-700">
                                            <li><strong>Brand Access Removed:</strong> All users are automatically detached from the brand</li>
                                            <li><strong>Company/Tenant Access Preserved:</strong> Users remain members of the company/tenant and retain their tenant-level roles</li>
                                            <li><strong>Other Brand Access Unaffected:</strong> Users' access to other brands is not affected</li>
                                        </ul>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">Activity & History:</h4>
                                        <ul className="list-disc list-inside space-y-1 text-sm text-gray-700">
                                            <li><strong>Activity Events:</strong> Activity events referencing the deleted brand have their brand_id set to null (events are preserved for audit purposes)</li>
                                        </ul>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">Restrictions:</h4>
                                        <ul className="list-disc list-inside space-y-1 text-sm text-gray-700">
                                            <li><strong>Default Brand:</strong> Cannot delete the default brand - another brand must be set as default first</li>
                                            <li><strong>Last Brand:</strong> Cannot delete the only remaining brand for a tenant</li>
                                        </ul>
                                    </div>

                                    <div className="mt-6 pt-6 border-t border-gray-200">
                                        <p className="text-xs text-gray-500 italic">
                                            <strong>Note:</strong> This documentation will be expanded in the future as additional deletion procedures are defined and asset management systems are fully implemented.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Back Link */}
                    <div className="flex justify-end">
                        <Link
                            href="/app/admin"
                            className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                        >
                            Back to Admin Dashboard
                        </Link>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
