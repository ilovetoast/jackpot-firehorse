/**
 * Phase 3.2 Upload Tray Usage Example
 * 
 * Example showing how to use the UploadTray component with Phase 3 Upload Manager.
 * 
 * This is an EXAMPLE FILE - not meant to be imported directly.
 */

import { usePhase3UploadManager } from '../hooks/usePhase3UploadManager';
import UploadTray from './UploadTray';

function ExampleUsage() {
    // Initialize upload manager with context
    const context = {
        companyId: 1,
        brandId: 2,
        categoryId: 3
    };

    const uploadManager = usePhase3UploadManager(context);

    // Add files (example)
    const handleFileSelect = (event) => {
        const files = Array.from(event.target.files);
        uploadManager.addFiles(files);
    };

    // The UploadTray will automatically show/hide based on hasItems
    return (
        <div>
            <input
                type="file"
                multiple
                onChange={handleFileSelect}
            />

            {/* Upload Tray - read-only display */}
            <UploadTray uploadManager={uploadManager} />
        </div>
    );
}

/**
 * Integration with Phase 2 UploadManager (example)
 * 
 * To connect Phase 2 upload progress to Phase 3 state:
 */
function ExampleWithPhase2Integration() {
    const phase3Manager = usePhase3UploadManager(context);
    const phase2Manager = useUploadManager(); // Phase 2 hook

    // Sync Phase 2 progress to Phase 3 state
    useEffect(() => {
        phase2Manager.uploads.forEach(upload => {
            // Find Phase 3 item by uploadSessionId
            const item = phase3Manager.items.find(i => 
                i.uploadSessionId === upload.uploadSessionId
            );

            if (item) {
                // Update progress
                phase3Manager.updateUploadProgress(item.clientId, upload.progress);

                // Update status
                if (upload.status === 'completed') {
                    phase3Manager.markUploadComplete(item.clientId, upload.uploadSessionId);
                } else if (upload.status === 'failed') {
                    phase3Manager.markUploadFailed(item.clientId, {
                        message: upload.error || 'Upload failed',
                        type: upload.errorInfo?.type || 'unknown'
                    });
                }
            }
        });
    }, [phase2Manager.uploads]);

    return (
        <UploadTray uploadManager={phase3Manager} />
    );
}

export default ExampleUsage;
