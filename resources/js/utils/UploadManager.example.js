/**
 * UploadManager Usage Example
 * 
 * This file demonstrates how to use the UploadManager in a React component.
 * This is NOT included in the build - it's just for reference.
 */

import { useUploadManager } from '../hooks/useUploadManager'
import { useState } from 'react'

function FileUploadExample() {
    const {
        uploads,
        activeUploads,
        completedUploads,
        failedUploads,
        addFiles,
        startUpload,
        resumeUpload,
        cancelUpload,
        retryUpload,
        removeUpload,
        getUpload,
        getAggregateProgress,
    } = useUploadManager()

    const [fileInput, setFileInput] = useState(null)

    // Handle file selection
    const handleFileSelect = (e) => {
        const files = Array.from(e.target.files)
        if (files.length === 0) return

        // Add files to upload manager
        const clientReferences = addFiles(files, {
            brandId: 'optional-brand-id',
            batchReference: `batch_${Date.now()}`, // Optional batch reference
            fileInput: e.target, // Store reference for rehydration
        })

        // Automatically start uploads
        clientReferences.forEach(ref => {
            startUpload(ref)
        })
    }

    // Example: Manual start
    const handleStart = (clientReference) => {
        startUpload(clientReference)
    }

    // Example: Resume
    const handleResume = (clientReference) => {
        resumeUpload(clientReference)
    }

    // Example: Cancel
    const handleCancel = (clientReference) => {
        cancelUpload(clientReference)
    }

    // Example: Retry failed upload
    const handleRetry = (clientReference) => {
        retryUpload(clientReference)
    }

    // Example: Remove from list
    const handleRemove = (clientReference) => {
        removeUpload(clientReference)
    }

    return (
        <div>
            {/* File input */}
            <input
                type="file"
                multiple
                onChange={handleFileSelect}
                ref={setFileInput}
            />

            {/* Aggregate progress */}
            <div>
                Overall Progress: {getAggregateProgress()}%
            </div>

            {/* Upload list */}
            <div>
                <h3>Active Uploads ({activeUploads.length})</h3>
                {activeUploads.map(upload => (
                    <div key={upload.clientReference}>
                        <div>{upload.fileName}</div>
                        <div>Progress: {upload.progress}%</div>
                        <button onClick={() => handleCancel(upload.clientReference)}>
                            Cancel
                        </button>
                    </div>
                ))}
            </div>

            {/* Failed uploads */}
            <div>
                <h3>Failed Uploads ({failedUploads.length})</h3>
                {failedUploads.map(upload => (
                    <div key={upload.clientReference}>
                        <div>{upload.fileName}</div>
                        <div>Error: {upload.error}</div>
                        <button onClick={() => handleRetry(upload.clientReference)}>
                            Retry
                        </button>
                        <button onClick={() => handleRemove(upload.clientReference)}>
                            Remove
                        </button>
                    </div>
                ))}
            </div>

            {/* Completed uploads */}
            <div>
                <h3>Completed Uploads ({completedUploads.length})</h3>
                {completedUploads.map(upload => (
                    <div key={upload.clientReference}>
                        <div>{upload.fileName}</div>
                        <div>Status: {upload.status}</div>
                        <button onClick={() => handleRemove(upload.clientReference)}>
                            Remove
                        </button>
                    </div>
                ))}
            </div>

            {/* All uploads */}
            <div>
                <h3>All Uploads ({uploads.length})</h3>
                {uploads.map(upload => (
                    <div key={upload.clientReference}>
                        <div>File: {upload.fileName}</div>
                        <div>Status: {upload.status}</div>
                        <div>Progress: {upload.progress}%</div>
                        {upload.error && <div>Error: {upload.error}</div>}
                        {upload.status === 'pending' && (
                            <button onClick={() => handleStart(upload.clientReference)}>
                                Start
                            </button>
                        )}
                        {upload.status === 'paused' && (
                            <button onClick={() => handleResume(upload.clientReference)}>
                                Resume
                            </button>
                        )}
                        {(upload.status === 'uploading' || upload.status === 'initiating') && (
                            <button onClick={() => handleCancel(upload.clientReference)}>
                                Cancel
                            </button>
                        )}
                        {upload.status === 'failed' && (
                            <button onClick={() => handleRetry(upload.clientReference)}>
                                Retry
                            </button>
                        )}
                        <button onClick={() => handleRemove(upload.clientReference)}>
                            Remove
                        </button>
                    </div>
                ))}
            </div>
        </div>
    )
}

export default FileUploadExample
