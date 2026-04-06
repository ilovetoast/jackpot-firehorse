import InsightsLayout from '../../layouts/InsightsLayout'
import UploadApprovalsPanel from '../../Components/insights/UploadApprovalsPanel'

export default function CreatorApprovals() {
    return (
        <InsightsLayout title="Creator approvals" activeSection="creator">
            <div className="space-y-6">
                <div>
                    <h2 className="text-2xl font-semibold text-gray-900">Creator approvals</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Deliverables from your creator program that are waiting for brand review and publish.
                    </p>
                </div>
                <UploadApprovalsPanel queue="creator" />
            </div>
        </InsightsLayout>
    )
}
