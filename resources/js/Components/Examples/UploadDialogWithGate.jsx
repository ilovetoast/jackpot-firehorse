import { useState, useCallback } from 'react'
import { Dialog } from '@headlessui/react'
import { XMarkIcon, CloudArrowUpIcon } from '@heroicons/react/24/outline'
import UploadGate from '../UploadGate'

/**
 * Example component showing how to integrate UploadGate into an upload dialog
 * 
 * This is a simplified example - in a real implementation, you would integrate
 * this into your existing upload dialog (UploadAssetDialog.jsx)
 */
export default function UploadDialogWithGate({ open, onClose }) {
    const [selectedFiles, setSelectedFiles] = useState([])
    const [validationResults, setValidationResults] = useState(null)
    const [isUploading, setIsUploading] = useState(false)

    // Handle file selection
    const handleFileSelect = useCallback((event) => {
        const files = Array.from(event.target.files)
        setSelectedFiles(files)
    }, [])

    // Handle validation results from UploadGate
    const handleValidationChange = useCallback((results) => {
        setValidationResults(results)
    }, [])

    // Handle upload initiation
    const handleUpload = useCallback(async () => {
        if (!validationResults?.canProceed) {
            return
        }

        setIsUploading(true)
        
        try {
            // Here you would integrate with your existing upload logic
            // For now, just simulate the upload process
            
            for (const file of selectedFiles) {
                console.log('Uploading file:', file.name, file.size)
                
                // Simulate upload time
                await new Promise(resolve => setTimeout(resolve, 1000))
            }
            
            // Reset and close on success
            setSelectedFiles([])
            setValidationResults(null)
            onClose()
            
        } catch (error) {
            console.error('Upload failed:', error)
            // Handle upload errors here
        } finally {
            setIsUploading(false)
        }
    }, [validationResults, selectedFiles, onClose])

    const canProceed = validationResults?.canProceed ?? true
    const hasErrors = validationResults?.hasErrors ?? false

    return (
        <Dialog open={open} onClose={onClose} className="relative z-50">
            {/* Backdrop */}
            <div className="fixed inset-0 bg-black/30" aria-hidden="true" />
            
            {/* Dialog */}
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <Dialog.Panel className="mx-auto max-w-md w-full bg-white rounded-lg shadow-xl">
                    {/* Header */}
                    <div className="flex items-center justify-between p-6 border-b">
                        <Dialog.Title className="text-lg font-semibold">
                            Upload Assets
                        </Dialog.Title>
                        <button
                            onClick={onClose}
                            className="text-gray-400 hover:text-gray-600"
                        >
                            <XMarkIcon className="h-5 w-5" />
                        </button>
                    </div>
                    
                    {/* Content */}
                    <div className="p-6 space-y-4">
                        {/* File Selection */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Select Files
                            </label>
                            <input
                                type="file"
                                multiple
                                onChange={handleFileSelect}
                                className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                            />
                        </div>

                        {/* Selected Files List */}
                        {selectedFiles.length > 0 && (
                            <div>
                                <h4 className="text-sm font-medium text-gray-700 mb-2">
                                    Selected Files ({selectedFiles.length})
                                </h4>
                                <div className="space-y-1 max-h-32 overflow-y-auto">
                                    {selectedFiles.map((file, index) => (
                                        <div key={index} className="text-xs text-gray-600 flex justify-between">
                                            <span className="truncate">{file.name}</span>
                                            <span className="ml-2 font-mono">
                                                {(file.size / 1024 / 1024).toFixed(1)} MB
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Upload Gate - This is the key component */}
                        <UploadGate
                            selectedFiles={selectedFiles}
                            onValidationChange={handleValidationChange}
                            autoValidate={true}
                            showStorageDetails={true}
                        />
                    </div>
                    
                    {/* Footer */}
                    <div className="flex items-center justify-end gap-3 p-6 border-t bg-gray-50">
                        <button
                            onClick={onClose}
                            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={handleUpload}
                            disabled={!canProceed || isUploading || selectedFiles.length === 0}
                            className={`px-4 py-2 text-sm font-medium rounded-md inline-flex items-center gap-2 ${
                                canProceed && !isUploading && selectedFiles.length > 0
                                    ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                                    : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                            }`}
                        >
                            {isUploading ? (
                                <>
                                    <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white" />
                                    Uploading...
                                </>
                            ) : (
                                <>
                                    <CloudArrowUpIcon className="h-4 w-4" />
                                    Upload {selectedFiles.length > 0 && `(${selectedFiles.length})`}
                                </>
                            )}
                        </button>
                    </div>
                </Dialog.Panel>
            </div>
        </Dialog>
    )
}