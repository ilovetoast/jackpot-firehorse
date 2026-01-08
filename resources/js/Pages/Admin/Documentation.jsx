import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import { BookOpenIcon, ExclamationTriangleIcon, TrashIcon, InformationCircleIcon, TagIcon, KeyIcon } from '@heroicons/react/24/outline'

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

                    {/* System Categories Section */}
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden mb-6">
                        <div className="px-6 py-4 border-b border-gray-200 bg-indigo-50">
                            <div className="flex items-center gap-2">
                                <TagIcon className="h-5 w-5 text-indigo-600" />
                                <h2 className="text-lg font-semibold text-gray-900">System Categories</h2>
                            </div>
                        </div>
                        <div className="px-6 py-6">
                            <div className="prose prose-sm max-w-none">
                                <h3 className="text-base font-semibold text-gray-900 mb-3">What happens when you update a system category template?</h3>
                                
                                <div className="space-y-4">
                                    <div className="rounded-md bg-blue-50 p-4">
                                        <div className="flex">
                                            <div className="flex-shrink-0">
                                                <InformationCircleIcon className="h-5 w-5 text-blue-400" aria-hidden="true" />
                                            </div>
                                            <div className="ml-3">
                                                <p className="text-sm text-blue-700">
                                                    When you update a system category template, a new version is created. Existing brands will see an "Update available" badge and can choose to upgrade their category to the latest version while preserving any customizations they've made.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">Versioning System:</h4>
                                        <ul className="list-disc list-inside space-y-1 text-sm text-gray-700">
                                            <li><strong>New Versions:</strong> Each update creates a new version of the template, preserving the old version for reference</li>
                                            <li><strong>No Auto-Updates:</strong> Existing brand categories are never automatically modified - tenant admins must explicitly choose to upgrade</li>
                                            <li><strong>Customization Preservation:</strong> When upgrading, tenant admins can select which fields to update, preserving any customizations they've made</li>
                                            <li><strong>Upgrade Detection:</strong> Brands with categories based on older versions will see an "Update available" badge</li>
                                        </ul>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">Tenant Admin Experience:</h4>
                                        <ul className="list-disc list-inside space-y-1 text-sm text-gray-700">
                                            <li><strong>Review Changes:</strong> Tenant admins can preview what has changed between their current version and the latest version</li>
                                            <li><strong>Selective Updates:</strong> They can choose which fields to update (name, icon, privacy settings, etc.)</li>
                                            <li><strong>Customizations Protected:</strong> Fields that have been customized are clearly marked, and admins can choose whether to keep their customizations or adopt the new system values</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Tenant Ownership Transfer Section */}
                    <div className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden mb-6">
                        <div className="px-6 py-4 border-b border-gray-200 bg-indigo-50">
                            <div className="flex items-center gap-2">
                                <KeyIcon className="h-5 w-5 text-indigo-600" />
                                <h2 className="text-lg font-semibold text-gray-900">Tenant Ownership Transfer Process</h2>
                            </div>
                        </div>
                        <div className="px-6 py-6">
                            <div className="prose prose-sm max-w-none">
                                <h3 className="text-base font-semibold text-gray-900 mb-3">Overview</h3>
                                
                                <div className="space-y-4">
                                    <div className="rounded-md bg-blue-50 p-4">
                                        <div className="flex">
                                            <div className="flex-shrink-0">
                                                <InformationCircleIcon className="h-5 w-5 text-blue-400" aria-hidden="true" />
                                            </div>
                                            <div className="ml-3">
                                                <p className="text-sm text-blue-700">
                                                    Tenant ownership transfer is a secure, multi-step workflow that requires explicit confirmation and acceptance. This is NOT a simple role change - it is a governance-critical process designed to prevent accidental or malicious ownership changes.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">Who Can Initiate Ownership Transfers?</h4>
                                        <ul className="list-disc list-inside space-y-1 text-sm text-gray-700">
                                            <li><strong>Current Tenant Owner:</strong> Only the current tenant owner can initiate an ownership transfer</li>
                                            <li><strong>Site Admins:</strong> Site administrators CANNOT initiate ownership transfers - this is a hard-coded security rule</li>
                                            <li><strong>Break-Glass Option:</strong> Only the platform super-owner (user ID 1) may force a transfer in emergency situations</li>
                                        </ul>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">Required Steps:</h4>
                                        <ol className="list-decimal list-inside space-y-2 text-sm text-gray-700">
                                            <li><strong>Initiation:</strong> Current owner initiates the transfer and selects the new owner</li>
                                            <li><strong>Email Confirmation:</strong> Current owner receives a confirmation email with a signed URL (expires in 7 days)</li>
                                            <li><strong>Confirmation:</strong> Current owner clicks the confirmation link to verify the transfer</li>
                                            <li><strong>Email Acceptance:</strong> New owner receives an acceptance email with a signed URL (expires in 7 days)</li>
                                            <li><strong>Acceptance:</strong> New owner clicks the acceptance link to accept ownership</li>
                                            <li><strong>Completion:</strong> System automatically completes the transfer:
                                                <ul className="list-disc list-inside ml-4 mt-1 space-y-1">
                                                    <li>Previous owner is downgraded to Admin role</li>
                                                    <li>New owner is upgraded to Owner role</li>
                                                    <li>Both parties receive completion emails</li>
                                                </ul>
                                            </li>
                                        </ol>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">Email Security Requirements:</h4>
                                        <ul className="list-disc list-inside space-y-1 text-sm text-gray-700">
                                            <li><strong>Signed URLs:</strong> All confirmation and acceptance links use cryptographically signed URLs</li>
                                            <li><strong>Expiration:</strong> Links expire after 7 days for security</li>
                                            <li><strong>Single Use:</strong> Links are validated on each use to prevent replay attacks</li>
                                            <li><strong>User Verification:</strong> Users must be logged in and match the expected user for the transfer</li>
                                        </ul>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">Audit Logging Behavior:</h4>
                                        <ul className="list-disc list-inside space-y-1 text-sm text-gray-700">
                                            <li><strong>All Steps Logged:</strong> Every step of the transfer process is logged in the activity history</li>
                                            <li><strong>Event Types:</strong> The following events are recorded:
                                                <ul className="list-disc list-inside ml-4 mt-1 space-y-1">
                                                    <li>tenant.owner_transfer.initiated</li>
                                                    <li>tenant.owner_transfer.confirmed</li>
                                                    <li>tenant.owner_transfer.accepted</li>
                                                    <li>tenant.owner_transfer.completed</li>
                                                    <li>tenant.owner_transfer.cancelled (if applicable)</li>
                                                </ul>
                                            </li>
                                            <li><strong>Metadata Captured:</strong> Each event includes from_user_id, to_user_id, and transfer_id for complete audit trail</li>
                                        </ul>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">Why Site Admins Cannot Perform This Action:</h4>
                                        <ul className="list-disc list-inside space-y-1 text-sm text-gray-700">
                                            <li><strong>Governance Control:</strong> Ownership transfer is a tenant-level governance decision that must be made by the tenant owner</li>
                                            <li><strong>Prevent Abuse:</strong> Prevents site admins from maliciously or accidentally transferring ownership without tenant consent</li>
                                            <li><strong>Explicit Consent:</strong> Ensures both parties (current and new owner) explicitly consent to the transfer via email</li>
                                            <li><strong>Audit Trail:</strong> Maintains a clear audit trail showing the transfer was initiated by the legitimate owner</li>
                                        </ul>
                                    </div>

                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-900 mb-2">Break-Glass Policy (Emergency Only):</h4>
                                        <ul className="list-disc list-inside space-y-1 text-sm text-gray-700">
                                            <li><strong>Platform Super-Owner Only:</strong> Only user ID 1 with site_owner role can force a transfer</li>
                                            <li><strong>Emergency Situations:</strong> This should only be used in cases where the current owner is unavailable or compromised</li>
                                            <li><strong>Full Audit Trail:</strong> All forced transfers are logged with special metadata indicating they were forced by the platform super-owner</li>
                                            <li><strong>Documentation Required:</strong> Platform super-owners should document the reason for any forced transfer</li>
                                        </ul>
                                    </div>

                                    <div className="mt-6 pt-6 border-t border-gray-200">
                                        <div className="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                                            <div className="flex">
                                                <div className="flex-shrink-0">
                                                    <ExclamationTriangleIcon className="h-5 w-5 text-yellow-400" />
                                                </div>
                                                <div className="ml-3">
                                                    <h4 className="text-sm font-medium text-yellow-800">Important Security Notes</h4>
                                                    <ul className="mt-2 text-sm text-yellow-700 space-y-1">
                                                        <li>• Ownership transfer is a workflow, not a role edit - it cannot be done through the standard role management interface</li>
                                                        <li>• No silent or instant ownership changes are possible</li>
                                                        <li>• All transfers require explicit email confirmation and acceptance</li>
                                                        <li>• The system prevents multiple active transfers per tenant</li>
                                                        <li>• Transfers are automatically cancelled if the initiating owner loses ownership mid-process</li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
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
                                        <div className="rounded-md bg-blue-50 p-4">
                                            <div className="flex">
                                                <div className="flex-shrink-0">
                                                    <InformationCircleIcon className="h-5 w-5 text-blue-400" aria-hidden="true" />
                                                </div>
                                                <div className="ml-3">
                                                    <p className="text-sm text-blue-700">
                                                        <strong>Note:</strong> This documentation will be expanded in the future as additional deletion procedures are defined and asset management systems are fully implemented.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
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
