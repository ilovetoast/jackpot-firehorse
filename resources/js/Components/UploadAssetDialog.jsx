/**
 * ⚠️ PHASE 3 LOCKED — COMPLETE AND LOCKED
 *
 * This uploader (Phase 3) is production-ready and frozen.
 * Do NOT refactor logic, effects, or data flow.
 * Only cosmetic UI changes are allowed without explicit approval.
 *
 * Persistence verified:
 * - Title normalization (never "Unknown", null if empty)
 * - Category ID stored in metadata->category_id
 * - Metadata fields stored in metadata->fields
 * - Slug-based category filtering (?category=rarr)
 * - Extensive logging for debugging
 * - Guardrails prevent silent failures
 *
 * See project notes for Phase 4+ work.
 */

// Replaces legacy Phase 2 verification UI

import { useState, useCallback, useRef, useEffect, useMemo } from 'react'
import { usePage, router } from '@inertiajs/react'
import { XMarkIcon, CloudArrowUpIcon, ArrowPathIcon, CheckCircleIcon } from '@heroicons/react/24/outline'
import { usePhase3UploadManager } from '../hooks/usePhase3UploadManager'
import GlobalMetadataPanel from './GlobalMetadataPanel'
import UploadTray from './UploadTray'
import UploadManager from '../utils/UploadManager' // Phase 2 singleton - import directly
import { normalizeUploadError } from '../utils/uploadErrorNormalizer' // Phase 2.5 Step 1: Error normalization
import DevUploadDiagnostics from './DevUploadDiagnostics' // Phase 2.5 Step 3: Dev-only diagnostics panel

/**
 * ⚠️ LEGACY UPLOADER FREEZE — STEP 0
 * 
 * Feature flag to disable legacy Phase 2/Phase 3 upload execution logic.
 * This flag freezes all legacy uploader behavior to prepare for rewrite.
 * 
 * When set to false:
 * - Phase 2 UploadManager integration is disabled
 * - Upload execution, sync, auto-start, and backend checks are frozen
 * - UI still renders (dialog, file selection, metadata panels)
 * - Uploading may temporarily do nothing (acceptable for this step)
 * 
 * This logic will be removed after Step 7.
 */
const USE_LEGACY_UPLOADER = false

/**
 * UploadAssetDialog - Phase 3 Upload Dialog
 * 
 * Uses Phase 3 Upload Manager for state management and Phase 2 UploadManager for actual uploads.
 * 
 * @param {boolean} open - Whether dialog is open
 * @param {function} onClose - Callback when dialog closes
 * @param {string} defaultAssetType - Default asset type ('asset' or 'marketing')
 * @param {Array} categories - Categories array from page props
 * @param {number|null} initialCategoryId - Optional initial category ID to prepopulate
 * @param {function} onFinalizeComplete - Optional callback when finalize completes successfully (batchStatus === 'complete')
 */
export default function UploadAssetDialog({ open, onClose, defaultAssetType = 'asset', categories = [], initialCategoryId = null, onFinalizeComplete = null, initialFiles = null }) {
    // Phase 2 invariant: This component assumes it is mounted only when visible.
    // Lifecycle (mount/unmount) controls visibility — not internal state.
    const { auth } = usePage().props
    const [isDragging, setIsDragging] = useState(false)
    const fileInputRef = useRef(null)
    const dropZoneRef = useRef(null)

    /**
     * ═══════════════════════════════════════════════════════════════
     * NEW CANONICAL UPLOADER STATE MODEL — STEP 1 & STEP 2
     * ═══════════════════════════════════════════════════════════════
     * 
     * STEP 1: State model introduced
     * STEP 2: Upload phase (bytes only) - uploads files to S3
     * 
     * Files array: Each file entry represents an upload file with its state.
     * Batch status: Tracks the overall batch upload state.
     */

    /**
     * New canonical files state array.
     * Each file entry follows this shape:
     * {
     *   clientId: string,           // Unique identifier for this file
     *   file: File,                 // The actual File object
     *   uploadKey?: string,         // Backend upload key (set during upload)
     *   uploadStatus:               // Current upload status
     *     | 'selected'              // File selected but not yet uploading
     *     | 'uploading'             // File is currently uploading
     *     | 'uploaded'              // File upload complete, ready for finalize
     *     | 'finalizing'            // Finalization in progress
     *     | 'finalized'             // Finalization complete
     *     | 'failed',               // Upload or finalization failed
     *   uploadProgress: number,     // Upload progress 0-100
     *   metadata: Record<string, any>, // File metadata
     *   error: null | {             // Error information if failed
     *     stage: 'upload' | 'validation' | 'finalize',
     *     code: string,
     *     message: string,
     *     fields?: Record<string, string>
     *   }
     * }
     */
    const [files, setFiles] = useState([])

    /**
     * New canonical batch status state.
     * Tracks the overall batch upload state:
     * - 'idle': No files selected
     * - 'uploading': One or more files are uploading
     * - 'ready': All files uploaded, ready to finalize
     * - 'finalizing': Finalization in progress
     * - 'partial_success': Some files failed, some succeeded
     * - 'complete': All files finalized successfully
     */
    // batchStatus is now computed deterministically from v2Files - no useState needed

    /**
     * CLEAN UPLOADER V2 — Selected Category ID
     * 
     * Category selection for finalization prerequisite.
     * Must be selected before finalizing uploads.
     * Does NOT affect upload behavior.
     */
    const [selectedCategoryId, setSelectedCategoryId] = useState(initialCategoryId || null)

    /**
     * CLEAN UPLOADER V2 — Global Metadata Draft
     * 
     * Global metadata that applies to all files in the batch.
     * Stored independently from files.
     * Empty override on a file = inherits global value.
     */
    const [globalMetadataDraft, setGlobalMetadataDraft] = useState({})

    /**
     * ═══════════════════════════════════════════════════════════════
     * CLEAN UPLOADER V2 — NEW CLEAN STATE
     * ═══════════════════════════════════════════════════════════════
     * 
     * Clean uploader v2 state - completely independent of legacy logic.
     * Each file object:
     * - clientId: string (crypto.randomUUID())
     * - file: File object
     * - status: 'selected' | 'uploading' | 'uploaded' | 'finalizing' | 'finalized' | 'failed'
     * - progress: number (0-100)
     * - uploadKey?: string (set when upload completes, needed for finalize)
     * - metadataDraft: Record<string, any> (per-file metadata overrides, empty = inherit global)
     * - error: null | Error object
     */
    const [v2Files, setV2Files] = useState([])
    
    // FINAL FIX: Track mapping between v2File.clientId and UploadManager.clientReference for multipart uploads
    // This allows us to read status/progress from UploadManager instead of v2Files for chunked uploads
    const v2ToUploadManagerMapRef = useRef(new Map()) // Map<v2File.clientId, UploadManager.clientReference>
    
    // FINAL FIX: Prevent AUTO_CLOSE_V2 from executing multiple times per dialog open cycle
    const autoClosedRef = useRef(false)
    
    // Track when finalize succeeds (all files finalized, no failures) - used to disable form during auto-close delay
    const [isFinalizeSuccess, setIsFinalizeSuccess] = useState(false)

    // ═══════════════════════════════════════════════════════════════
    // CLEAN UPLOADER V2 — TEMPORARILY DISABLED LEGACY
    // ═══════════════════════════════════════════════════════════════
    
    // TEMPORARILY DISABLED: Legacy Phase 3 Upload Manager
    // const uploadContext = {
    //     companyId: auth.activeCompany?.id || auth.companies?.[0]?.id || null,
    //     brandId: auth.activeBrand?.id || null,
    //     categoryId: initialCategoryId || null
    // }
    // const phase3Manager = usePhase3UploadManager(uploadContext)
    
    // TEMPORARY MOCK: Minimal phase3Manager mock for UI rendering (upload logic disabled)
    const phase3Manager = {
        items: [],
        completedItems: [],
        uploadingItems: [],
        hasItems: false,
        canFinalize: false,
        context: { categoryId: initialCategoryId || null },
        warnings: [],
        availableMetadataFields: [],
        setUploadSessionId: () => {},
        updateUploadProgress: () => {},
        markUploadComplete: () => {},
        markUploadFailed: () => {},
        markUploadStarted: () => {},
        getEffectiveMetadata: () => ({}),
        addFiles: () => [], // NO-OP: Returns empty array
    }
    
    // TEMPORARILY DISABLED: Legacy Phase 2 UploadManager
    // const phase2ManagerRef = useRef(UploadManager)
    // const currentlyAddingRef = useRef(new Set())
    // const phase3ManagerRef = useRef(phase3Manager)
    // useEffect(() => {
    //     if (phase3ManagerRef.current !== phase3Manager) {
    //         phase3ManagerRef.current = phase3Manager
    //     }
    // }, [phase3Manager])
    
    // TEMPORARY MOCK REFS: Minimal refs for UI compatibility (upload logic disabled)
    const phase2ManagerRef = { current: null } // TEMPORARY NO-OP
    const currentlyAddingRef = { current: new Set() } // TEMPORARY NO-OP - mock Set for .clear() calls
    const phase3ManagerRef = { current: phase3Manager } // TEMPORARY NO-OP
    
    // Track Phase 2 uploads via subscription instead of hook state
    const [phase2Uploads, setPhase2Uploads] = useState(() => {
        // Initial state - filter to only current Phase 3 items (but phase3Manager might not be ready yet)
        // So start empty, subscription will populate it
        return []
    })
    
    // TEMPORARILY DISABLED: Legacy Phase 2 subscription
    // ⚠️ CLEAN UPLOADER V2: Legacy upload logic disabled
    useEffect(() => {
        // NO-OP: Legacy logic disabled for clean uploader v2
        return
    }, [])
    
    // DISABLED LEGACY CODE (commented out for clean uploader v2):
    /*
    // LEGACY — DO NOT USE: Subscribe to Phase 2 updates (without triggering rehydration)
    // CRITICAL: This subscription updates phase2Uploads state, which triggers the sync effect below
    // Run only once on mount - use refs to access latest values in subscription callback
    // ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
    useEffect(() => {
        // Freeze legacy Phase 2 subscription logic
        if (!USE_LEGACY_UPLOADER) {
            return // Early return - legacy logic frozen
        }
        // Initial state - filter to only current Phase 3 items (use ref to avoid dependency)
        const initialAllUploads = Array.from(UploadManager.getUploads())
        const initialPhase3Manager = phase3ManagerRef.current
        const initialPhase3ClientIds = new Set(initialPhase3Manager.items.map(item => item.clientId))
        const initialCurrentlyAddingIds = currentlyAddingRef.current
        const initialFilteredUploads = initialAllUploads.filter(upload =>
            initialPhase3ClientIds.has(upload.clientReference) ||
            initialCurrentlyAddingIds.has(upload.clientReference)
        )
        
        // Set initial state synchronously (before subscription)
        if (initialFilteredUploads.length > 0) {
            setPhase2Uploads(initialFilteredUploads)
        }
        
        // Subscribe to changes
        // Use refs to access latest phase3Manager.items - avoids infinite loops from dependencies
        const unsubscribe = UploadManager.subscribe(() => {
            // Get all uploads from manager
            const allUploads = Array.from(UploadManager.getUploads())
            
            // Filter to only uploads that belong to current Phase 3 items OR are currently being added
            // Access latest phase3Manager through ref to avoid closure issues
            const currentPhase3Manager = phase3ManagerRef.current
            const currentPhase3Items = currentPhase3Manager.items
            const phase3ClientIds = new Set(currentPhase3Items.map(item => item.clientId))
            const currentlyAddingIds = currentlyAddingRef.current
            
            // Include uploads that belong to Phase 3 items OR are currently being added
            // CRITICAL: Also include uploads that are actively uploading/initiating
            // CRITICAL: Also include ALL completed uploads (needed for finalization stability check)
            // Completed uploads are needed even if phase3ClientIds is temporarily empty (ref timing issue)
            // The stability check will verify they match Phase 3 items using phase3Manager.items (hook state)
            // This prevents the filter from removing uploads that just started
            // (the upload might have started before Phase 3 items state updated)
            const filteredUploads = allUploads.filter(upload => {
                const belongsToPhase3 = phase3ClientIds.has(upload.clientReference)
                const isCurrentlyAdding = currentlyAddingIds.has(upload.clientReference)
                const isActivelyUploading = upload.status === 'uploading' || upload.status === 'initiating'
                const isCompleted = upload.status === 'completed'
                
                // Include if it belongs to Phase 3, is being added, is actively uploading, OR is completed
                // Completed uploads are preserved for stability check (even if phase3ClientIds is empty due to ref timing)
                // This ensures active uploads are never filtered out
                // AND completed uploads needed for finalization stability check are preserved
                const include = belongsToPhase3 || isCurrentlyAdding || isActivelyUploading || isCompleted
                
                if (!include && (upload.status === 'uploading' || upload.status === 'initiating')) {
                    console.warn('[Subscription] Filter removing ACTIVE upload!', {
                        clientReference: upload.clientReference,
                        status: upload.status,
                        belongsToPhase3,
                        isCurrentlyAdding,
                        phase3ClientIds: Array.from(phase3ClientIds),
                        currentlyAddingIds: Array.from(currentlyAddingIds),
                        phase3ItemsCount: currentPhase3Items.length
                    })
                }
                
                
                return include
            })
            
            // Only update state if something actually changed (prevent unnecessary re-renders)
            setPhase2Uploads(prev => {
                // Compare arrays to see if anything changed
                if (prev.length !== filteredUploads.length) {
                    return filteredUploads
                }
                
                // Check if any upload changed
                const hasChanges = filteredUploads.some(upload => {
                    const prevUpload = prev.find(p => p.clientReference === upload.clientReference)
                    if (!prevUpload) return true
                    return (
                        prevUpload.progress !== upload.progress ||
                        prevUpload.status !== upload.status ||
                        prevUpload.uploadSessionId !== upload.uploadSessionId
                    )
                })
                
                return hasChanges ? filteredUploads : prev
            })
        })
        
        // DO NOT call rehydrateUploads - we're in a new modal context
        // Old uploads should not be rehydrated here (prevents 400 errors on /resume)
        
        return unsubscribe
    }, []) // Empty deps - run only once on mount, use refs to access latest values
    */
    
    // TEMPORARILY DISABLED: Legacy cleanup effect
    // ⚠️ CLEAN UPLOADER V2: Legacy upload logic disabled
    useEffect(() => {
        // NO-OP: Legacy logic disabled for clean uploader v2
        return
    }, [open])
    
    // FINAL FIX: Reset auto-closed flag when dialog opens (allows retry after errors)
    useEffect(() => {
        if (open) {
            autoClosedRef.current = false
            setIsFinalizeSuccess(false) // Reset success state when dialog opens
        }
    }, [open])
    
    // Handle initial files from drag-and-drop when dialog opens
    useEffect(() => {
        if (open && initialFiles && initialFiles.length > 0) {
            // Add files automatically when dialog opens with initial files
            handleFileSelect(initialFiles)
        }
    }, [open, initialFiles]) // eslint-disable-line react-hooks/exhaustive-deps
    
    // DISABLED LEGACY CODE (commented out for clean uploader v2):
    /*
    // LEGACY — DO NOT USE: Clean up old uploads when dialog opens
    // Remove any uploads without files (rehydrated old uploads) to prevent conflicts
    // CRITICAL: Do NOT remove completed Phase 2 uploads - they're needed for finalization stability check
    // ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
    useEffect(() => {
        // Freeze legacy Phase 2 cleanup logic
        if (!USE_LEGACY_UPLOADER) {
            // Clear refs on close (safe to do even when frozen)
            //     currentlyAddingRef.current.clear()
            // }
            return // Early return - legacy logic frozen
        }
        
        if (open) {
            const phase2Manager = phase2ManagerRef.current
            const allUploads = Array.from(phase2Manager.uploads.entries())
            const currentPhase3Items = phase3ManagerRef.current.items
            const phase3ClientIds = new Set(currentPhase3Items.map(item => item.clientId))
            
            // Remove uploads that:
            // 1. Don't have a file attached (old rehydrated uploads) AND
            // 2. Don't belong to Phase 3 AND
            // 3. Are not currently being added AND
            // 4. Are NOT completed (completed uploads are needed for finalization)
            // This prevents old uploads from interfering with new uploads
            // BUT preserves completed uploads needed for stability check
            const uploadsToRemove = allUploads.filter(([key, upload]) => {
                const belongsToPhase3 = phase3ClientIds.has(upload.clientReference)
                const isCurrentlyAdding = currentlyAddingRef.current.has(upload.clientReference)
                const hasNoFile = !upload.file
                const isCompleted = upload.status === 'completed'
                
                // Remove if:
                // - Doesn't belong to Phase 3 AND
                // - Is not currently being added AND
                // - Has no file (rehydrated old uploads) AND
                // - Is NOT completed (completed uploads are needed even if they don't belong to Phase 3 yet)
                // This prevents old rehydrated uploads from interfering, but preserves completed uploads
                return !belongsToPhase3 && !isCurrentlyAdding && hasNoFile && !isCompleted
            })
            
            if (uploadsToRemove.length > 0) {
                uploadsToRemove.forEach(([key, upload]) => {
                    phase2Manager.removeUpload(key)
                })
            }
        } else {
            // Clear currently adding ref when dialog closes
            currentlyAddingRef.current.clear()
        }
    }, [open])
    */

    // Filter categories by asset type and exclude deleted system categories
    const filteredCategories = (categories || []).filter(cat => {
        // Filter by asset type
        const matchesAssetType = defaultAssetType === 'asset' 
            ? (cat.asset_type === 'asset' || cat.asset_type === 'basic')
            : cat.asset_type === 'marketing'
        
        if (!matchesAssetType) {
            return false
        }
        
        // Exclude system categories where the template has been deleted
        // Check both template_exists flag and deletion_available flag
        if (cat.is_system === true) {
            // If template_exists is explicitly false, exclude it
            if (cat.template_exists === false) {
                return false
            }
            // Also check deletion_available flag if present
            if (cat.deletion_available === true) {
                return false
            }
        }
        
        return true
    })

    /**
     * ═══════════════════════════════════════════════════════════════
     * CLEAN UPLOADER V2 — Upload Single File Function
     * ═══════════════════════════════════════════════════════════════
     * 
     * Clean upload function that proves uploads execute and hit the network.
     * Does NOT reference any legacy upload logic.
     * 
     * @param {Object} fileEntry - File entry from v2Files state
     * @param {string} fileEntry.clientId - Unique client ID
     * @param {File} fileEntry.file - The File object
     */
    const uploadSingleFile = useCallback(async (fileEntry) => {
        const { clientId, file } = fileEntry
        
        // a. Log start
        console.log('[UPLOAD_V2] start', clientId)
        
        try {
            // b. POST to /app/uploads/initiate-batch
            const payload = {
                files: [{
                    file_name: file.name,
                    file_size: file.size,
                    mime_type: file.type || 'application/octet-stream',
                    client_reference: clientId,
                }],
                brand_id: auth.activeBrand?.id,
            }
            
            const response = await fetch('/app/uploads/initiate-batch', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            })
            
            if (!response.ok) {
                // Phase 3.0C: Improved error handling for HTML error pages (419 CSRF, etc.)
                // Phase 2.5 Step 1: Extract error message, then normalize in catch block
                const contentType = response.headers.get('content-type') || ''
                let errorMessage = `Upload initiation failed: ${response.status} ${response.statusText}`
                
                if (contentType.includes('text/html')) {
                    // Server returned HTML error page (e.g., 419 Page Expired)
                    if (response.status === 419) {
                        errorMessage = 'Session expired. Please refresh the page and try again.'
                    } else {
                        errorMessage = `Server error (${response.status}). Please refresh the page and try again.`
                    }
                } else {
                    // Try to parse JSON error response
                    try {
                        const errorData = await response.json()
                        errorMessage = errorData.message || errorData.error || errorMessage
                    } catch {
                        // Not JSON, try text but limit length to prevent HTML dump
                        const errorText = await response.text().catch(() => '')
                        if (errorText && errorText.length < 200 && !errorText.includes('<html')) {
                            errorMessage = errorText
                        }
                    }
                }
                
                // Create error with Response attached for normalization
                const error = new Error(errorMessage)
                error.status = response.status
                error.response = response
                throw error
            }
            
            const responseData = await response.json()
            
            // c. Log session response
            console.log('[UPLOAD_V2] session response', responseData)
            
            const result = responseData.uploads[0]
            if (result.error) {
                throw new Error(result.error)
            }
            
            // HOTFIX: upload_url is only required for direct uploads
            // Multipart (chunked) uploads use the multipart init flow instead
            const uploadType = result.upload_type || 'direct'
            const uploadUrl = result.upload_url
            
            if (uploadType === 'direct') {
                // Direct uploads require upload_url
                if (!uploadUrl) {
                    throw new Error('No presigned URL returned for direct upload')
                }
                
                // d. PUT the file to the returned presigned URL
                const putResponse = await fetch(uploadUrl, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': file.type || 'application/octet-stream',
                    },
                    body: file,
                })
                
                if (!putResponse.ok) {
                    throw new Error(`S3 upload failed: ${putResponse.status} ${putResponse.statusText}`)
                }
                
                // e. Log complete
                console.log('[UPLOAD_V2] complete', { clientId, status: putResponse.status })
            } else if (uploadType === 'chunked') {
                // HOTFIX: Explicitly start multipart upload via UploadManager
                // Multipart uploads are owned by UploadManager and must be started explicitly
                console.log('[UPLOAD_V2] multipart upload delegated to UploadManager', {
                    clientId,
                    upload_session_id: result.upload_session_id
                })
                
                // Add file to UploadManager and get the clientReference
                const clientReferences = UploadManager.addFiles([file], {
                    brandId: auth.activeBrand?.id,
                })
                
                if (clientReferences.length === 0) {
                    console.error('[UPLOAD_V2] Failed to add file to UploadManager', { clientId })
                    throw new Error('Failed to add file to UploadManager')
                }
                
                const uploadManagerClientRef = clientReferences[0]
                
                // FINAL FIX: Store mapping between v2File.clientId and UploadManager.clientReference
                // This allows UI to read status/progress from UploadManager for multipart uploads
                v2ToUploadManagerMapRef.current.set(clientId, uploadManagerClientRef)
                
                // Find the upload entry (getUploads() returns array, need to find by clientReference)
                const allUploads = UploadManager.getUploads()
                const upload = allUploads.find(u => u.clientReference === uploadManagerClientRef)
                
                if (!upload) {
                    console.error('[UPLOAD_V2] Upload entry not found in UploadManager after addFiles', {
                        clientId,
                        uploadManagerClientRef
                    })
                    throw new Error('Upload entry not found in UploadManager')
                }
                
                // Update upload entry with session info from initiate-batch response
                upload.uploadSessionId = result.upload_session_id
                upload.uploadType = 'chunked'
                upload.chunkSize = result.chunk_size || upload.chunkSize
                upload.brandId = auth.activeBrand?.id
                
                // Ensure file object is attached
                UploadManager.reattachFile(uploadManagerClientRef, file)
                
                // Start the multipart upload
                // startUpload() will see uploadSessionId exists and call resumeUpload(),
                // which will then call performMultipartUpload()
                UploadManager.startUpload(uploadManagerClientRef).catch((error) => {
                    console.error('[UPLOAD_V2] Failed to start multipart upload in UploadManager', {
                        clientId,
                        uploadManagerClientRef,
                        error: error.message
                    })
                })
                
                // IMPORTANT:
                // Multipart uploads are handled exclusively by UploadManager.
                // This legacy path must exit early and not:
                // - Update upload progress
                // - Mark upload as completed
                // - Call finalize logic
                // UploadManager is responsible for:
                // - Calling /multipart/init
                // - Uploading parts
                // - Tracking progress
                // - Marking complete after multipart completion
                return
            } else {
                throw new Error(`Unknown upload type: ${uploadType}`)
            }
            
            // Store uploadKey from response for finalize (only reached for direct uploads)
            const uploadKey = result.upload_key || `temp/uploads/${result.upload_session_id}/original`
            
            // Update v2Files state: set status to 'uploaded', progress to 100, and store uploadKey
            setV2Files((prevFiles) => {
                return prevFiles.map((f) => {
                    if (f.clientId === clientId) {
                        console.log('[UPLOAD_V2] Updating file state to uploaded', { clientId, uploadKey })
                        return {
                            ...f,
                            status: 'uploaded',
                            progress: 100,
                            uploadKey: uploadKey,
                            error: null
                        }
                    }
                    return f
                })
            })
            
            return {
                success: true,
                uploadKey: uploadKey,
            }
        } catch (error) {
            console.error('[UPLOAD_V2] error', { clientId, error: error.message })
            
            // Phase 2.5 Step 1: Normalize error for consistent AI-ready format
            const normalizedError = normalizeUploadError(error, {
                httpStatus: error.response?.status || error.status,
                uploadSessionId: null, // Not yet available at initiation stage
                fileName: file.name,
                file: file,
                stage: 'upload',
            })
            
            // Update v2Files state: set status to 'failed' and store normalized error
            // Store both normalized shape (for AI agents) and backward-compatible shape (for UI)
            setV2Files((prevFiles) => {
                return prevFiles.map((f) => {
                    if (f.clientId === clientId) {
                        return {
                            ...f,
                            status: 'failed',
                            error: {
                                // Backward-compatible shape for UI
                                message: normalizedError.message,
                                stage: 'upload',
                                code: normalizedError.error_code,
                                // Normalized shape preserved for AI agents and future aggregation
                                normalized: normalizedError,
                            }
                        }
                    }
                    return f
                })
            })
            
            throw error
        }
    }, [auth.activeBrand?.id])

    /**
     * ═══════════════════════════════════════════════════════════════
     * CLEAN UPLOADER V2 — File Selection Handler
     * ═══════════════════════════════════════════════════════════════
     * 
     * Clean file selection handler that populates v2Files state and triggers upload.
     * Does NOT reference any legacy upload logic, hooks, managers, or effects.
     */
    const handleFileSelect = useCallback((selectedFiles) => {
        console.log('[FILE_SELECT] handler called', { fileCount: selectedFiles?.length || 0 })
        
        if (!selectedFiles || selectedFiles.length === 0) {
            console.log('[FILE_SELECT] No files selected, returning early')
            return
        }

        const fileArray = Array.from(selectedFiles)
        console.log('[FILE_SELECT] Processing files', { count: fileArray.length, fileNames: fileArray.map(f => f.name) })
        
        // Helper to derive initial resolvedFilename from filename
        const deriveInitialResolvedFilename = (filename) => {
            const lastDotIndex = filename.lastIndexOf('.')
            const extension = lastDotIndex > 0 ? filename.substring(lastDotIndex + 1) : ''
            const baseName = lastDotIndex > 0 ? filename.substring(0, lastDotIndex) : filename
            const slugified = baseName.toLowerCase().trim()
                .replace(/[^\w\s-]/g, '')
                .replace(/[\s_-]+/g, '-')
                .replace(/^-+|-+$/g, '')
            return extension ? `${slugified}.${extension}` : slugified
        }

        // Create clean file entries for v2Files state
        const newV2FileEntries = fileArray.map((file) => ({
            clientId: (window.crypto?.randomUUID || (() => {
                // Fallback UUID generation if crypto.randomUUID is not available
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                    const r = Math.random() * 16 | 0
                    const v = c === 'x' ? r : (r & 0x3 | 0x8)
                    return v.toString(16)
                })
            }))(),
            file,
            status: 'selected', // 'selected' | 'uploading' | 'uploaded' | 'finalizing' | 'finalized' | 'failed'
            progress: 0, // 0-100
            uploadKey: null, // Set when upload completes
            title: null, // Will be derived from filename, can be edited
            resolvedFilename: null, // Will be derived initially, can be edited directly
            metadataDraft: {}, // Per-file metadata overrides (empty = inherit global)
            error: null
        }))

        console.log('[FILE_SELECT] Created file entries', { count: newV2FileEntries.length, clientIds: newV2FileEntries.map(e => e.clientId) })

        // Add new file entries to v2Files state ONLY
        // Upload coordinator useEffect will handle starting uploads automatically
        setV2Files((prevFiles) => {
            const updated = [...prevFiles, ...newV2FileEntries]
            console.log('[FILE_SELECT] Updated v2Files state', { totalCount: updated.length })
            return updated
        })
    }, [selectedCategoryId])
    
    // DISABLED LEGACY CODE (commented out for clean uploader v2):
    /*
        // LEGACY — DO NOT USE: Track these clientIds as currently adding (prevents subscription filter from removing them)
        // ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
        if (USE_LEGACY_UPLOADER) {
            clientIds.forEach(clientId => currentlyAddingRef.current.add(clientId))
        }

        // LEGACY — DO NOT USE: Phase 2 UploadManager integration
        // ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
        if (!USE_LEGACY_UPLOADER) {
            return // Early return - legacy Phase 2 integration frozen
        }

        const phase2Manager = phase2ManagerRef.current
        const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024 // 5 MB

        // Start uploads via Phase 2 manager using Phase 3's clientIds
        fileArray.forEach((file, index) => {
            const clientId = clientIds[index] // Phase 3 UUID (this is what backend expects)
            
            // CRITICAL: Check if an upload with this clientId OR clientReference already exists
            // This prevents old uploads from interfering with new uploads
            // Check by key (clientId) first
            const existingUploadByKey = phase2Manager.uploads.get(clientId)
            if (existingUploadByKey) {
                console.warn('[FileSelect] Removing old upload with same clientId (key)', {
                    clientId,
                    oldUploadSessionId: existingUploadByKey.uploadSessionId,
                    oldStatus: existingUploadByKey.status,
                    oldClientRef: existingUploadByKey.clientReference
                })
                phase2Manager.removeUpload(clientId)
            }
            
            // Also check if any upload exists with the same clientReference (different key)
            // This catches old uploads that were keyed differently
            const allManagerUploads = Array.from(phase2Manager.uploads.entries())
            const existingUploadByRef = allManagerUploads.find(([key, upload]) => upload.clientReference === clientId)
            if (existingUploadByRef) {
                console.warn('[FileSelect] Removing old upload with same clientReference (different key)', {
                    clientId,
                    existingKey: existingUploadByRef[0],
                    oldUploadSessionId: existingUploadByRef[1].uploadSessionId,
                    oldStatus: existingUploadByRef[1].status
                })
                phase2Manager.removeUpload(existingUploadByRef[0])
            }
            
            // Create upload entry in Phase 2's internal map with Phase 3's clientId
            // We bypass addFiles() because it generates its own UUID, but we need Phase 3's UUID
            const upload = {
                clientReference: clientId, // Use Phase 3's UUID - critical for backend
                uploadSessionId: null, // CRITICAL: Always null for new uploads (prevents resume)
            file,
                fileName: file.name,
                fileSize: file.size,
                mimeType: file.type || 'application/octet-stream',
                uploadType: file.size > DEFAULT_CHUNK_SIZE ? 'chunked' : 'direct',
                chunkSize: file.size > DEFAULT_CHUNK_SIZE ? DEFAULT_CHUNK_SIZE : undefined,
                multipartUploadId: null,
                status: 'pending', // CRITICAL: Always start as 'pending' for new uploads
                progress: 0,
                error: null,
                errorInfo: null,
                diagnostics: null,
                lastUpdatedAt: Date.now(),
                brandId: auth.activeBrand?.id,
                batchReference: null,
            }

            // Add directly to Phase 2's uploads map using Phase 3's clientId as the key
            // Overwrite any existing upload (shouldn't exist after cleanup above)
            phase2Manager.uploads.set(clientId, upload)
            // OPTIMIZATION 3: Don't persist during file add - large files cause blocking serialization
            // Persistence will happen on start/complete/fail when needed
            // Don't call notifyListeners() here - we'll do it manually after state update
        })
        
        // Manually update phase2Uploads state FIRST (before notifyListeners)
        // This prevents the subscription filter from removing new uploads
        // CRITICAL: Use the clientIds we just got from addFiles directly
        // phase3Manager.items might not be updated yet (React state update is async)
        const currentPhase3Items = phase3Manager.items
        const existingPhase3ClientIds = new Set(currentPhase3Items.map(item => item.clientId))
        const newClientIdsSet = new Set(clientIds)
        
        // Merge: existing Phase 3 clientIds + new clientIds from addFiles
        // This ensures we include newly added items even if state hasn't updated yet
        const allPhase3ClientIds = new Set([...existingPhase3ClientIds, ...newClientIdsSet])
        
        // Get all uploads from manager
        const allUploads = Array.from(phase2ManagerRef.current.getUploads())
        
        // Filter to only uploads that belong to current Phase 3 items (exclude old uploads)
        // MUST include the new clientIds we just added
        const filteredUploads = allUploads.filter(upload => {
            const matches = allPhase3ClientIds.has(upload.clientReference)
            // if (!matches && newClientIdsSet.has(upload.clientReference)) {
            //     console.error('[FileSelect] New upload filtered out but should be included!', {
            //         uploadClientReference: upload.clientReference,
            //         newClientIds: Array.from(newClientIdsSet),
            //         allPhase3ClientIds: Array.from(allPhase3ClientIds),
            //         existingPhase3ClientIds: Array.from(existingPhase3ClientIds)
            //     })
            // }
            return matches
        })
        
        // Verify new uploads are included (they should be since we use newClientIds)
        const newlyAddedUploads = allUploads.filter(upload => 
            newClientIdsSet.has(upload.clientReference)
        )
        
        // Debug: Verify Phase 2 uploads were added with correct clientReference
        const phase2UploadsWithNewIds = allUploads.filter(upload => 
            newClientIdsSet.has(upload.clientReference)
        )
        
        // OPTIMIZATION 6: Reduce logging during file add - only log errors
        if (phase2UploadsWithNewIds.length !== newClientIdsSet.size) {
            console.error('[FileSelect] Phase 2 uploads missing or incorrect clientReference!', {
                expectedCount: newClientIdsSet.size,
                actualCount: phase2UploadsWithNewIds.length,
                newClientIds: Array.from(newClientIdsSet)
            })
        }
        
        // Set state with filtered uploads (ensures new uploads are included, excludes old uploads)
        setPhase2Uploads(filteredUploads)
        
        // OPTIMIZATION 5: Simplify currentlyAddingRef lifecycle
        // currentlyAddingRef will be cleared automatically when uploads start (in auto-start effect)
        // No need for complex requestAnimationFrame logic - auto-start effect handles it
        
        // Notify listeners after state update
        // This triggers the subscription, which will update state again (but should be idempotent)
        phase2Manager.notifyListeners()
        
        // DO NOT call startUpload here - let the auto-start effect handle it
        // This ensures slot-based queue advancement works correctly
        // The effect will detect the new queued item and start it when a slot is available
    }, [phase3Manager, auth.activeBrand?.id])
    */

    /**
     * Handle drag events
     */
    const handleDragEnter = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        setIsDragging(true)
    }, [])

    const handleDragLeave = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        if (!dropZoneRef.current?.contains(e.relatedTarget)) {
            setIsDragging(false)
        }
    }, [])

    const handleDragOver = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
    }, [])

    const handleDrop = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        setIsDragging(false)
        
        const files = e.dataTransfer.files
        if (files.length > 0) {
            handleFileSelect(files)
        }
    }, [handleFileSelect])

    /**
     * ═══════════════════════════════════════════════════════════════
     * STEP 2 — Upload phase (bytes only)
     * ═══════════════════════════════════════════════════════════════
     * 
     * Implements file upload to S3 using presigned URLs.
     * - Uploads files with uploadStatus === 'selected'
     * - Tracks progress and updates file state
     * - Transitions files to 'uploaded' on success
     * - Handles failures and allows retry
     * - Updates batchStatus based on file states
     * 
     * NO finalize calls - only byte upload to S3.
     */

    // Track active uploads (clientId -> AbortController)
    const activeUploadsRef = useRef(new Map())
    const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024 // 5 MB

    /**
     * STEP 2.4/2.5 — Initiate upload session with backend
     * 
     * STEP 2.5: Bypass axios to verify network path.
     * Uses fetch() directly to debug network request issues.
     * Returns upload session data (upload_url, upload_session_id, etc.)
     */
    const initiateUpload = useCallback(async (fileEntry) => {
        // STEP 2.6 — Hard assertion to verify execution path
        throw new Error('[ASSERT] initiateUpload ENTERED');
        
        // DEV-only logging
        console.log('[INITIATE] called', { clientId: fileEntry.clientId, fileName: fileEntry.file.name })

        const payload = {
            files: [{
                file_name: fileEntry.file.name,
                file_size: fileEntry.file.size,
                mime_type: fileEntry.file.type || 'application/octet-stream',
                client_reference: fileEntry.clientId,
            }],
            brand_id: auth.activeBrand?.id,
            ...(selectedCategoryId !== null && selectedCategoryId !== undefined && { category_id: selectedCategoryId }),
        }
        
        // DEV-only logging
        console.log('[INITIATE] sending request', { endpoint: '/app/uploads/initiate-batch', payload })
        
        // STEP 2.5: Use fetch() directly to bypass axios and verify network path
        const response = await fetch('/app/uploads/initiate-batch', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        })
        
        // DEV-only logging
        console.log('[INITIATE] fetch response status', { status: response.status, statusText: response.statusText })
        
        if (!response.ok) {
            // Phase 3.0C: Improved error handling for HTML error pages (419 CSRF, etc.)
            const contentType = response.headers.get('content-type') || ''
            let errorMessage = `Upload initiation failed: ${response.status} ${response.statusText}`
            
            if (contentType.includes('text/html')) {
                // Server returned HTML error page (e.g., 419 Page Expired)
                if (response.status === 419) {
                    errorMessage = 'Session expired. Please refresh the page and try again.'
                } else {
                    errorMessage = `Server error (${response.status}). Please refresh the page and try again.`
                }
            } else {
                // Try to parse JSON error response
                try {
                    const errorData = await response.json()
                    errorMessage = errorData.message || errorData.error || errorMessage
                } catch {
                    // Not JSON, try text but limit length to prevent HTML dump
                    const errorText = await response.text().catch(() => '')
                    if (errorText && errorText.length < 200 && !errorText.includes('<html')) {
                        errorMessage = errorText
                    }
                }
            }
            
            throw new Error(errorMessage)
        }
        
        // Parse JSON response
        const responseData = await response.json()
        
        // DEV-only logging
        console.log('[INITIATE] fetch response json', responseData)

        const result = responseData.uploads[0]
        if (result.error) {
            throw new Error(result.error)
        }

        const sessionData = {
            uploadSessionId: result.upload_session_id,
            uploadType: result.upload_type, // 'direct' or 'multipart'
            uploadUrl: result.upload_url || null,
            multipartUploadId: result.multipart_upload_id || null,
            chunkSize: result.chunk_size || DEFAULT_CHUNK_SIZE,
        }
        
        // DEV-only logging
        console.log('[INITIATE] returning session data', sessionData)
        
        return sessionData
    }, [auth.activeBrand?.id, selectedCategoryId])

    /**
     * STEP 2.1 — Perform direct upload (PUT to presigned URL)
     * 
     * Handles XHR upload with progress tracking.
     * Progress handler calls onProgress callback with 0-100 integer.
     */
    const performDirectUpload = useCallback(async (fileEntry, uploadUrl, onProgress) => {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest()

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable && onProgress) {
                    // STEP 2.1: Calculate progress as integer 0-100, capped at 100
                    const progress = Math.min(100, Math.max(0, Math.round((e.loaded / e.total) * 100)))
                    // Call progress callback (wired to setFiles in startFileUpload)
                    onProgress(progress)
                }
            })

            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve({
                        uploadKey: `temp/uploads/${fileEntry.uploadSessionId}/original`, // S3 key pattern
                    })
                } else {
                    reject(new Error(`Upload failed: ${xhr.statusText}`))
                }
            })

            xhr.addEventListener('error', () => {
                reject(new Error('Network error during upload'))
            })

            xhr.addEventListener('abort', () => {
                reject(new Error('Upload cancelled'))
            })

            xhr.open('PUT', uploadUrl)
            xhr.setRequestHeader('Content-Type', fileEntry.file.type || 'application/octet-stream')
            xhr.send(fileEntry.file)
        })
    }, [])

    /**
     * STEP 2.3 — Start upload for a single file
     * 
     * This is NEW canonical uploader logic that ALWAYS runs.
     * Legacy upload logic is frozen separately and does NOT affect this function.
     * 
     * Flow:
     * 1. Validate fileEntry exists
     * 2. Set uploadStatus → 'uploading'
     * 3. Call initiateUpload (POST /app/uploads/initiate-batch)
     * 4. Perform direct upload (PUT to S3)
     * 5. Update progress and status
     */
    const startFileUpload = useCallback(async (clientId) => {
        // Get current file entry from state using a functional update
        let fileEntry = null
        setFiles((prevFiles) => {
            fileEntry = prevFiles.find((f) => f.clientId === clientId)
            if (!fileEntry || fileEntry.uploadStatus === 'uploading' || fileEntry.uploadStatus === 'uploaded') {
                return prevFiles // No change needed
            }

            // Create abort controller for this upload
            const abortController = new AbortController()
            activeUploadsRef.current.set(clientId, abortController)

            // Update status to uploading
            return prevFiles.map((f) =>
                f.clientId === clientId
                    ? { ...f, uploadStatus: 'uploading', uploadProgress: 0, error: null }
                    : f
            )
        })

        if (!fileEntry || fileEntry.uploadStatus === 'uploading' || fileEntry.uploadStatus === 'uploaded') {
            return // Already uploading or uploaded
        }

        try {
            // Initiate upload session
            // Note: batchStatus is now computed from v2Files, no manual update needed
            const sessionData = await initiateUpload(fileEntry)

            // Store uploadSessionId
            setFiles((prev) =>
                prev.map((f) =>
                    f.clientId === clientId
                        ? { ...f, uploadSessionId: sessionData.uploadSessionId }
                        : f
                )
            )

            // Get updated file entry for upload
            let currentFileEntry = { ...fileEntry, uploadSessionId: sessionData.uploadSessionId }

            // Perform upload based on type
            if (sessionData.uploadType === 'direct') {
                // STEP 2.1 — Upload progress fix
                // Direct upload with progress tracking
                await performDirectUpload(
                    currentFileEntry,
                    sessionData.uploadUrl,
                    (progress) => {
                        // STEP 2.1: Ensure progress updates use functional setState
                        // Match file strictly by clientId and update uploadProgress only
                        setFiles((prev) =>
                            prev.map((f) => {
                                if (f.clientId === clientId) {
                                    // DEV-only console log for progress tracking
                                    console.log('[Upload Progress]', { clientId, progress })
                                    return { ...f, uploadProgress: progress }
                                }
                                return f
                            })
                        )
                    }
                )

                // Upload successful - mark as uploaded
                setFiles((prev) =>
                    prev.map((f) =>
                        f.clientId === clientId
                            ? {
                                  ...f,
                                  uploadStatus: 'uploaded',
                                  uploadProgress: 100,
                                  uploadKey: `temp/uploads/${sessionData.uploadSessionId}/original`,
                                  error: null,
                              }
                            : f
                    )
                )
            } else {
                // Multipart upload - for Step 2, we'll handle basic multipart
                // For now, mark as failed if multipart (can be enhanced later)
                throw new Error('Multipart upload not yet implemented in Step 2')
            }
        } catch (error) {
            // Upload failed
            setFiles((prev) =>
                prev.map((f) =>
                    f.clientId === clientId
                        ? {
                              ...f,
                              uploadStatus: 'failed',
                              error: {
                                  stage: 'upload',
                                  code: 'upload_failed',
                                  message: error.message || 'Upload failed. Please retry.',
                              },
                          }
                        : f
                )
            )
        } finally {
            // Clean up abort controller
            activeUploadsRef.current.delete(clientId)
            // STEP 2.2: Clean up started tracking (allows retry if needed)
            startedUploadsRef.current.delete(clientId)
        }
    }, [initiateUpload, performDirectUpload])

    /**
     * STEP 2 — Retry upload for a failed file (re-upload bytes)
     * 
     * Resets file to 'selected' status, which triggers re-upload.
     */
    const retryFileUpload = useCallback((clientId) => {
        setFiles((prevFiles) =>
            prevFiles.map((f) =>
                f.clientId === clientId
                    ? {
                          ...f,
                          uploadStatus: 'selected',
                          uploadProgress: 0,
                          error: null,
                      }
                    : f
            )
        )
    }, [])

    /**
     * ═══════════════════════════════════════════════════════════════
     * STEP 5 — Partial success UX
     * ═══════════════════════════════════════════════════════════════
     * 
     * Implements retry and remove behavior for failed files.
     * Allows users to resolve failures without losing successes.
     */

    /**
     * STEP 5 — Retry failed finalize for a file
     * 
     * Allowed ONLY when:
     * - file.uploadStatus === 'failed'
     * - error.stage !== 'upload'
     * 
     * Does NOT re-upload bytes - resets to 'uploaded' status for re-finalization.
     */
    const retryFailedFinalize = useCallback((clientId) => {
        setFiles((prevFiles) =>
            prevFiles.map((f) => {
                if (f.clientId !== clientId) {
                    return f
                }

                // Guard: Only allow retry for failed files with non-upload errors
                if (f.uploadStatus !== 'failed' || f.error?.stage === 'upload') {
                    return f
                }

                // Reset to 'uploaded' status (not 'selected' - don't re-upload)
                return {
                    ...f,
                    uploadStatus: 'uploaded',
                    error: null,
                }
            })
        )

        // Update batchStatus if needed (will be updated by batchStatus effect)
    }, [])

    /**
     * STEP 5 — Remove a file from the upload list
     * 
     * Allowed for:
     * - Failed files
     * - Uploaded files (not finalized)
     * 
     * Does NOT affect finalized assets (they're already created).
     */
    /**
     * ═══════════════════════════════════════════════════════════════
     * CLEAN UPLOADER V2 — Metadata Management
     * ═══════════════════════════════════════════════════════════════
     * 
     * Global and per-file metadata editing (draft state only).
     * Effective metadata = globalMetadataDraft merged with file.metadataDraft.
     */
    
    /**
     * CLEAN UPLOADER V2 — Set Global Metadata Field
     */
    const setGlobalMetadataV2 = useCallback((fieldKey, value) => {
        setGlobalMetadataDraft((prev) => ({
            ...prev,
            [fieldKey]: value,
        }))
    }, [])

    /**
     * CLEAN UPLOADER V2 — Get Effective Metadata for a File
     * 
     * Merges globalMetadataDraft with file.metadataDraft.
     * Per-file overrides take precedence.
     */
    const getEffectiveMetadataV2 = useCallback((clientId) => {
        const file = v2Files.find((f) => f.clientId === clientId)
        if (!file) return {}
        
        return {
            ...globalMetadataDraft,
            ...(file.metadataDraft || {}),
        }
    }, [v2Files, globalMetadataDraft])

    /**
     * CLEAN UPLOADER V2 — Override Item Metadata Field
     * 
     * Sets a per-file metadata override.
     */
    const overrideItemMetadataV2 = useCallback((clientId, fieldKey, value) => {
        setV2Files((prevFiles) =>
            prevFiles.map((f) =>
                f.clientId === clientId
                    ? {
                          ...f,
                          metadataDraft: {
                              ...(f.metadataDraft || {}),
                              [fieldKey]: value,
                          },
                      }
                    : f
            )
        )
    }, [])

    /**
     * CLEAN UPLOADER V2 — Clear Item Metadata Override
     * 
     * Removes a per-file override, making it inherit from global.
     */
    const clearItemOverrideV2 = useCallback((clientId, fieldKey) => {
        setV2Files((prevFiles) =>
            prevFiles.map((f) => {
                if (f.clientId !== clientId) return f
                
                const newMetadataDraft = { ...(f.metadataDraft || {}) }
                delete newMetadataDraft[fieldKey]
                
                return {
                    ...f,
                    metadataDraft: newMetadataDraft,
                }
            })
        )
    }, [])

    /**
     * ═══════════════════════════════════════════════════════════════
     * CLEAN UPLOADER V2 — Retry Functions
     * ═══════════════════════════════════════════════════════════════
     * 
     * Retry upload: Resets failed upload to 'selected' status for re-upload.
     * Retry finalize: Resets failed finalize to 'uploaded' status for re-finalize.
     */
    
    /**
     * CLEAN UPLOADER V2 — Retry Upload (for stage === 'upload' failures)
     * 
     * Resets file to 'selected' status so coordinator can pick it up.
     * Upload will restart automatically.
     */
    const retryUpload = useCallback((clientId) => {
        console.log('[RETRY_UPLOAD] Retrying upload', { clientId })
        setV2Files((prevFiles) =>
            prevFiles.map((f) =>
                f.clientId === clientId
                    ? {
                          ...f,
                          status: 'selected',
                          progress: 0,
                          error: null,
                      }
                    : f
            )
        )
    }, [])

    /**
     * CLEAN UPLOADER V2 — Retry Finalize (for stage === 'finalize' failures)
     * 
     * Resets file to 'uploaded' status (preserves uploadKey).
     * File becomes eligible for next finalize operation.
     */
    const retryFinalize = useCallback((clientId) => {
        console.log('[RETRY_FINALIZE] Retrying finalize', { clientId })
        setV2Files((prevFiles) =>
            prevFiles.map((f) =>
                f.clientId === clientId
                    ? {
                          ...f,
                          status: 'uploaded',
                          error: null,
                      }
                    : f
            )
        )
    }, [])

    /**
     * CLEAN UPLOADER V2 — Remove File
     * 
     * Removes a file from v2Files state.
     */
    // Phase 2.8: Remove file and cancel any active uploads
    const removeFile = useCallback((clientId) => {
        console.log('[REMOVE_FILE] Removing file', { clientId })
        
        // Phase 2.8: Cancel UploadManager upload if active
        const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(clientId)
        if (uploadManagerClientRef) {
            const uploadManagerUpload = UploadManager.getUploads().find(u => u.clientReference === uploadManagerClientRef)
            if (uploadManagerUpload && 
                (uploadManagerUpload.status === 'initiating' || 
                 uploadManagerUpload.status === 'uploading' || 
                 uploadManagerUpload.status === 'completing')) {
                // Cancel active multipart upload
                console.log('[REMOVE_FILE] Cancelling active multipart upload', { 
                    clientId, 
                    uploadManagerClientRef,
                    status: uploadManagerUpload.status 
                })
                UploadManager.cancelUpload(uploadManagerClientRef).catch((error) => {
                    console.error('[REMOVE_FILE] Failed to cancel UploadManager upload', {
                        clientId,
                        uploadManagerClientRef,
                        error: error.message
                    })
                })
            }
        }
        
        // Clean up mapping
        v2ToUploadManagerMapRef.current.delete(clientId)
        
        // Remove from v2Files
        setV2Files((prevFiles) => prevFiles.filter((f) => f.clientId !== clientId))
        
        // Clean up active uploads ref
        activeUploadsRef.current.delete(clientId)
        // STEP 2.2: Clean up started tracking
        startedUploadsRef.current.delete(clientId)
    }, [])

    // STEP 2.2 — Track which uploads have been started (to prevent duplicate starts)
    const startedUploadsRef = useRef(new Set())

    /**
     * STEP 2.2 — Explicit upload auto-start
     * 
     * This effect triggers uploads for files with uploadStatus === 'selected'.
     * 
     * Why not use legacy auto-start logic:
     * - Legacy logic is frozen and must remain disabled
     * - Legacy logic depends on Phase 2/Phase 3 manager integration
     * - This is a clean, explicit implementation for the canonical uploader
     * 
     * Behavior:
     * - Watches files array for changes
     * - Finds files with uploadStatus === 'selected'
     * - Calls startFileUpload(clientId) for each
     * - Uses ref to prevent duplicate starts
     */
    useEffect(() => {
        const selectedFiles = files.filter((f) => f.uploadStatus === 'selected')
        
        selectedFiles.forEach((fileEntry) => {
            const clientId = fileEntry.clientId
            
            // Only start if not already started
            if (!startedUploadsRef.current.has(clientId)) {
                startedUploadsRef.current.add(clientId)
                
                // DEV-only logging
                console.log('[AUTO-START] Starting upload', { clientId, fileName: fileEntry.file.name })
                
                // Start upload (errors are handled in startFileUpload)
                startFileUpload(clientId).catch(() => {
                    // Error handling is done in startFileUpload
                })
            }
        })

        // Clean up tracking for files that are no longer in the files array
        const currentClientIds = new Set(files.map((f) => f.clientId))
        startedUploadsRef.current.forEach((clientId) => {
            if (!currentClientIds.has(clientId)) {
                startedUploadsRef.current.delete(clientId)
            }
        })
    }, [files, startFileUpload])

    /**
     * LEGACY — DISABLED: Update batchStatus based on file states (UI-only)
     * 
     * DISABLED: batchStatus is now computed deterministically from v2Files.
     * This legacy effect is no longer needed and has been disabled to prevent
     * reference errors (batchStatus is computed later in the file).
     * 
     * Original behavior (preserved for reference):
     * - When uploads begin → 'uploading'
     * - When no files are uploading AND at least one file is 'uploaded' → 'ready'
     * - 'finalizing' and 'complete' states are set by handleFinalize handler, not by this effect
     * - After partial_success, if conditions are met, transitions back to 'ready'
     */
    // DISABLED: batchStatus is now computed from v2Files, not manually set
    // useEffect(() => {
    //     if (files.length === 0) {
    //         setBatchStatus('idle')
    //         return
    //     }
    //     // ... legacy code disabled
    // }, [files, batchStatus])

    /**
     * LEGACY — DO NOT USE: Sync Phase 2 upload progress to Phase 3 state
     * 
     * CRITICAL: Use useRef to track last synced values to prevent infinite loops.
     * Only sync when values actually change, not on every render.
     * Only sync uploads that belong to Phase 3 items (by clientId match).
     * 
     * ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
     */
    const lastSyncedRef = useRef(new Map()) // Track last synced state per clientId
    // Track session ID change timestamps to enforce backend stability settle window
    // Maps clientId -> { timestamp: number, sessionId: string } of last session ID change
    // This allows us to check if the sessionId has actually changed, not just if it's different from lastSynced
    const sessionIdChangeTimestampsRef = useRef(new Map())

    // Store stable references to Phase 3 manager methods
    const { 
        items: phase3Items,
        setUploadSessionId,
        updateUploadProgress,
        markUploadComplete,
        markUploadFailed,
        markUploadStarted
    } = phase3Manager

    // OPTIMIZATION 2: Memoize Phase 3 clientId set to avoid repeated Set() and map() calls
    const phase3ClientIdSet = useMemo(() => {
        return new Set(phase3Items.map(item => item.clientId))
    }, [phase3Items])
    
    // LEGACY — DO NOT USE: Sync effect
    // ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
    useEffect(() => {
        // Freeze legacy Phase 2 sync logic
        if (!USE_LEGACY_UPLOADER) {
            return // Early return - legacy sync logic frozen
        }
        // OPTIMIZATION 6: Remove verbose logging from tight loops
        // Only log when there's a significant state change or error
        
        phase2Uploads.forEach(phase2Upload => {
            // Phase 2's clientReference now matches Phase 3's clientId (we set it in handleFileSelect)
            const clientId = phase2Upload.clientReference
            
            // Find Phase 3 item by clientId (not uploadSessionId, since that's set later)
            const phase3Item = phase3Items.find(item => item.clientId === clientId)
            
            if (!phase3Item) {
                // Phase 2 upload exists but no Phase 3 item - ignore (might be from previous session or rehydrated)
                // This prevents syncing old uploads that don't belong to this modal session
                console.log('[Sync] Phase 2 upload has no matching Phase 3 item - skipping', { clientId, phase2Status: phase2Upload.status })
                return
            }

            // Get last synced state for this clientId
            const lastSynced = lastSyncedRef.current.get(clientId) || {}
            
            // OPTIMIZATION 6: Reduce logging - only log on state transitions and errors
            // Don't log every progress tick for large files
            const hasSessionIdChanged = phase2Upload.uploadSessionId !== lastSynced.uploadSessionId
            
            // Track session ID changes for backend stability check
            // CRITICAL: Reset settle window timestamp whenever sessionId changes
            // hasSessionIdChanged means sessionId is different from lastSynced (which updates after sync)
            // So if hasSessionIdChanged is true, the sessionId just changed and we need to reset the settle window
            if (hasSessionIdChanged && phase2Upload.uploadSessionId) {
                const lastRecorded = sessionIdChangeTimestampsRef.current.get(clientId)
                const lastRecordedSessionId = lastRecorded?.sessionId
                
                // Reset timestamp if sessionId is different from what we last recorded
                // This ensures the settle window restarts whenever the sessionId actually changes
                if (lastRecordedSessionId !== phase2Upload.uploadSessionId) {
                    sessionIdChangeTimestampsRef.current.set(clientId, {
                        timestamp: Date.now(),
                        sessionId: phase2Upload.uploadSessionId
                    })
                }
            }
            const hasStatusChanged = phase2Upload.status !== lastSynced.status
            const isStateTransition = hasStatusChanged || hasSessionIdChanged || 
                                     (phase2Upload.status === 'failed' || phase2Upload.status === 'completed')
            
            // Only log on state transitions or errors, not on every progress update
            if (isStateTransition) {
                console.log('[Sync] State transition', {
                    clientId,
                    phase2Status: phase2Upload.status,
                    phase3Status: phase3Item.uploadStatus,
                    phase2Progress: phase2Upload.progress,
                    hasSessionIdChanged,
                    hasStatusChanged
                })
            }
            
            // OPTIMIZATION 1: Throttle progress syncing for large files (relaxed for visibility + heartbeat)
            // Sync progress when any of these are true:
            // 1. Terminal state (completed/failed) - always sync
            // 2. First update (Phase 3 progress is 0 and Phase 2 has progress > 0)
            // 3. Meaningful visual change (delta >= 0.5%)
            // 4. UI heartbeat (timeElapsed >= 750ms) - prevents frozen bar during slow multipart phases
            // Compare against Phase 3's actual progress (what's displayed) to ensure visual updates
            const phase3CurrentProgress = phase3Item.progress ?? 0
            const currentProgress = phase2Upload.progress ?? 0
            const delta = Math.abs(currentProgress - phase3CurrentProgress)
            const lastProgressSyncAt = lastSynced.lastProgressSyncAt ?? 0
            const timeElapsed = Date.now() - lastProgressSyncAt
            const isTerminalState = phase2Upload.status === 'completed' || phase2Upload.status === 'failed'
            const isFirstUpdate = phase3CurrentProgress === 0 && currentProgress > 0
            const shouldSync = (
                isTerminalState ||
                isFirstUpdate ||
                delta >= 0.5 ||
                timeElapsed >= 750
            )
            const needsProgressUpdate = (
                phase2Upload.progress !== undefined && 
                phase2Upload.progress !== null &&
                currentProgress !== phase3CurrentProgress &&
                shouldSync
            )
            const needsSessionUpdate = hasSessionIdChanged && phase2Upload.uploadSessionId && !phase3Item.uploadSessionId
            // CRITICAL: Check status changes based on current Phase 3 status, not just if status changed
            // This handles cases where Phase 3 is out of sync (e.g., 'uploading' when Phase 2 is 'failed')
            // Always sync Phase 3 to Phase 2's actual status - priority order: failed > complete > uploading > pending
            const phase2Status = phase2Upload.status
            const phase3Status = phase3Item.uploadStatus
            
            // OPTIMIZATION 4: Reduce "smart correction" during active uploads
            // Only sync status for terminal states (failed/completed) during active upload
            // Trust Phase 2 status for uploading/initiating - avoid progress-based failure detection
            const isActiveUpload = phase2Status === 'uploading' || phase2Status === 'initiating'
            const needsStatusUpdate = 
                // Terminal states - always sync
                (phase2Status === 'failed' && phase3Status !== 'failed') ||
                (phase2Status === 'completed' && phase3Status !== 'complete') ||
                // Status transitions - only sync if not already active (avoid mid-upload corrections)
                (!isActiveUpload && (
                    // Uploading/initiating - sync if Phase 3 is queued/pending (initial transition only)
                    ((phase2Status === 'uploading' || phase2Status === 'initiating') && 
                     phase3Status !== 'uploading' && phase3Status !== 'complete' && phase3Status !== 'failed') ||
                    // Revert if Phase 2 is pending but Phase 3 thinks it's uploading (upload was reset)
                    (phase2Status === 'pending' && phase3Status === 'uploading' && !phase2Upload.uploadSessionId)
                ))
            
            // CRITICAL: Always sync failed status immediately, even if nothing else changed
            // Failed is a terminal state and must take priority over all other status checks
            const isFailedMismatch = phase2Upload.status === 'failed' && phase3Item.uploadStatus !== 'failed'
            
            // CRITICAL FIX: Immediately transition Phase 3 from queued to uploading when Phase 2 enters initiating
            // This must happen BEFORE early return check to ensure large files show "Uploading" immediately
            // Do NOT wait for progress or uploadSessionId - just transition status
            const needsInitiatingTransition = phase2Upload.status === 'initiating' && phase3Item.uploadStatus === 'queued'
            
            // CRITICAL: Force status update if there's a failed mismatch (bypass normal needsStatusUpdate check)
            // This ensures failed status always syncs, even if needsStatusUpdate calculation is wrong
            const shouldUpdateStatus = needsStatusUpdate || isFailedMismatch
            
            if (!needsProgressUpdate && !needsInitiatingTransition && !needsSessionUpdate && !shouldUpdateStatus) {
                // Nothing changed - skip update to prevent infinite loop
                // Don't update lastSyncedRef here - we only update it after actually syncing values
                return
            }
            
            // OPTIMIZATION 6: Only log errors and critical state transitions
            if (isFailedMismatch && !needsStatusUpdate) {
                console.warn('[Sync] Forcing failed status sync (critical terminal state)', {
                    clientId,
                    phase2Status: phase2Upload.status,
                    phase3Status: phase3Item.uploadStatus
                })
            }

            // Update session ID if needed (always do this first)
            if (needsSessionUpdate) {
                setUploadSessionId(clientId, phase2Upload.uploadSessionId)
            }

            // CRITICAL FIX: Immediately transition Phase 3 from queued to uploading when Phase 2 enters initiating
            // This must happen BEFORE progress/status updates to ensure large files show "Uploading" immediately
            // Do NOT wait for progress or uploadSessionId - just transition status
            if (needsInitiatingTransition) {
                currentlyAddingRef.current.delete(clientId)
                markUploadStarted(clientId)
            }

            // OPTIMIZATION 1: Update progress only when throttled threshold is met (≥0.5% or terminal)
            // Progress updates are throttled to reduce React churn while maintaining visible updates
            if (needsProgressUpdate) {
                updateUploadProgress(clientId, phase2Upload.progress)
            }

            // Update status if needed (always sync Phase 3 status to Phase 2 status)
            // Priority order: failed first (terminal), then completed, then uploading
            if (shouldUpdateStatus) {
                // CRITICAL: Check failed status FIRST (terminal state)
                // This must take priority over any other status mismatch
                if (phase2Upload.status === 'failed' && phase3Item.uploadStatus !== 'failed') {
                    console.log('[Sync] Marking upload failed', { 
                        clientId, 
                        phase2Status: phase2Upload.status, 
                        phase3Status: phase3Item.uploadStatus, 
                        error: phase2Upload.error,
                        errorInfo: phase2Upload.errorInfo
                    })
                    markUploadFailed(clientId, {
                        message: phase2Upload.error || phase2Upload.errorInfo?.message || 'Upload failed',
                        type: phase2Upload.errorInfo?.type || 'unknown',
                        httpStatus: phase2Upload.errorInfo?.http_status,
                        rawError: phase2Upload.errorInfo?.raw_error
                    })
                } else if (phase2Upload.status === 'completed' && phase3Item.uploadStatus !== 'complete') {
                    // Defensive check: Ensure upload has uploadSessionId before marking complete
                    // This prevents marking as complete if Phase 2 incorrectly reported completion
                    // (e.g., timeout during multipart upload where S3 object doesn't exist)
                    const sessionId = phase2Upload.uploadSessionId || phase3Item.uploadSessionId
                    if (!sessionId) {
                        // OPTIMIZATION 6: Only log errors (missing session is an error)
                        console.error('[Sync] Phase 2 marked as completed but no uploadSessionId', { clientId })
                        // If Phase 2 says completed but no session ID, something went wrong
                        // Mark as failed instead to prevent S3 "object does not exist" error
                        markUploadFailed(clientId, {
                            message: 'Upload session was interrupted. Please retry the upload.',
                            type: 'session_missing',
                        })
                    } else {
                        // OPTIMIZATION 4: Trust Phase 2 status - don't use progress to detect failures
                        // Check if Phase 2 has an error (backend /complete call might have failed)
                        if (phase2Upload.error || phase2Upload.errorInfo) {
                            // OPTIMIZATION 6: Only log errors (completion errors are errors)
                            console.error('[Sync] Phase 2 marked as completed but has error', {
                                clientId,
                                error: phase2Upload.error || phase2Upload.errorInfo?.message
                            })
                            // If there's an error, treat as failed even if status is 'completed'
                            // This catches cases where /complete endpoint failed but status wasn't updated correctly
                            markUploadFailed(clientId, {
                                message: phase2Upload.error || phase2Upload.errorInfo?.message || 'Upload completion failed',
                                type: phase2Upload.errorInfo?.type || 'completion_error',
                                httpStatus: phase2Upload.errorInfo?.http_status,
                            })
                        } else {
                            // OPTIMIZATION 4: Trust Phase 2 status - don't use progress to detect failures
                            // Backend finalize is the true validator for multipart uploads
                            // If Phase 2 says completed, trust it (backend will validate S3 object exists)
                            markUploadComplete(clientId, sessionId)
                        }
                    }
                } else if ((phase2Upload.status === 'uploading' || phase2Upload.status === 'initiating') && 
                           phase3Item.uploadStatus !== 'uploading' && phase3Item.uploadStatus !== 'complete' && phase3Item.uploadStatus !== 'failed') {
                    // OPTIMIZATION 5: Clear currentlyAddingRef when upload starts
                    currentlyAddingRef.current.delete(clientId)
                    
                    // Update to uploading status - do this even if uploadSessionId doesn't exist yet
                    // The status change happens immediately when startUpload is called
                    if (phase2Upload.uploadSessionId) {
                        // Update status and session ID together
                        setUploadSessionId(clientId, phase2Upload.uploadSessionId)
                    } else {
                        // Update status to 'uploading' even without sessionId yet
                        // This happens immediately when upload starts, before session is initiated
                        markUploadStarted(clientId)
                    }
                } else if (phase2Upload.status === 'pending' && phase3Item.uploadStatus === 'uploading' && !phase2Upload.uploadSessionId) {
                    // Upload was reset - revert Phase 3 status to queued
                    // Don't need to do anything - Phase 3 should already be queued
                }
            }

            // Update last synced state AFTER all updates are complete
            // Track what we actually synced to Phase 3 (use Phase 3's current progress after update)
            // Also track lastProgressSyncAt timestamp for heartbeat calculation (only update if we synced)
            lastSyncedRef.current.set(clientId, {
                progress: needsProgressUpdate ? phase2Upload.progress : phase3Item.progress,
                uploadSessionId: phase2Upload.uploadSessionId,
                status: phase2Upload.status,
                lastProgressSyncAt: needsProgressUpdate ? Date.now() : (lastSynced.lastProgressSyncAt ?? 0)
            })
        })
    }, [phase2Uploads, phase3Items, phase3ClientIdSet, setUploadSessionId, updateUploadProgress, markUploadComplete, markUploadFailed, markUploadStarted, currentlyAddingRef])
    
    // Clean up ref when dialog closes
    useEffect(() => {
        // TEMPORARILY COMMENTED FOR DIAGNOSTIC: if (!open) {
        //     lastSyncedRef.current.clear()
        // }
    }, [open])

    /**
     * LEGACY — DO NOT USE: Slot-based queue advancement
     * 
     * CANONICAL RULE: If activeUploads < MAX_CONCURRENT_UPLOADS AND queuedUploads > 0
     * → start next queued upload.
     * 
     * Purely slot-based: always checks for available slots and starts queued uploads.
     * Does NOT rely on status transitions - just checks if slots are available.
     * 
     * This ensures reliable queue progression:
     * - When uploads complete/fail, slots open → next queued item starts
     * - When new files are added, if slots available → start immediately
     * - Failed uploads don't block queue (they free up slots)
     * 
     * Sequential uploads by default (1 at a time).
     * 
     * ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
     */
    const startingUploadsRef = useRef(new Set()) // Track uploads currently being started
    const MAX_CONCURRENT_UPLOADS = 1 // Sequential by default (can be increased later)
    
    // OPTIMIZATION 2: Memoize queued items and active uploads to avoid repeated filtering
    const queuedItems = useMemo(() => 
        phase3Items.filter(item => item.uploadStatus === 'queued'),
        [phase3Items]
    )
    
    const activeUploads = useMemo(() =>
        phase2Uploads.filter(upload => 
            upload.status === 'uploading' || upload.status === 'initiating'
        ),
        [phase2Uploads]
    )
    
    const activeCount = activeUploads.length
    const availableSlots = MAX_CONCURRENT_UPLOADS - activeCount

    // LEGACY — DO NOT USE: Auto-start effect
    // ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
    useEffect(() => {
        // Freeze legacy auto-start queue advancement logic
        if (!USE_LEGACY_UPLOADER) {
            return // Early return - legacy auto-start logic frozen
        }
        const phase2Manager = phase2ManagerRef.current
        
        // OPTIMIZATION 6: Remove verbose logging from auto-start effect
        // Only log on actual start attempts or errors
        
        if (queuedItems.length === 0) {
            return // No queued items - nothing to start
        }

        if (availableSlots <= 0) {
            return // No available slots - queue is full
        }
        
        // Debug: Log auto-start attempt
        // console.log('[Auto-start] Attempting to start uploads', {
        //     queuedItems: queuedItems.length,
        //     activeCount,
        //     availableSlots,
        //     phase2UploadsCount: phase2Uploads.length
        // })

        // Start uploads for queued items (up to available slots)
        // Always pick the oldest queued item first (array order is chronological)
        // OPTIMIZATION 2: Use memoized queuedItems
        queuedItems.slice(0, availableSlots).forEach(item => {
            const clientId = item.clientId
            
            // Guard: Don't start if already starting (prevents double-start)
            if (startingUploadsRef.current.has(clientId)) {
                return
            }

            // Guard: Check Phase 2 upload status
            // Always check manager directly first (most reliable source of truth)
            // State might be stale or filtered
            // CRITICAL: Use clientId as the key (this is what we set when adding the upload)
            let phase2Upload = phase2Manager.uploads.get(clientId)
            
            if (!phase2Upload) {
                // Upload might be keyed differently - check all uploads to find matching clientReference
                const allManagerUploads = Array.from(phase2Manager.uploads.entries())
                const uploadByClientRef = allManagerUploads.find(([key, upload]) => upload.clientReference === clientId)
                
                console.log('[Auto-start] Phase 2 upload not found in manager by clientId', { 
                    clientId,
                    phase2UploadsInState: phase2Uploads.length,
                    phase2UploadsInManager: phase2Manager.uploads.size,
                    managerKeys: Array.from(phase2Manager.uploads.keys()).slice(0, 10),
                    managerClientRefs: allManagerUploads.map(([key, u]) => ({ key, clientRef: u.clientReference })).slice(0, 10),
                    phase2UploadsInStateClientRefs: phase2Uploads.map(u => u.clientReference).slice(0, 10),
                    foundByClientRef: uploadByClientRef ? { key: uploadByClientRef[0], upload: uploadByClientRef[1] } : null
                })
                
                // If we found it by clientReference but under a different key, use that
                if (uploadByClientRef) {
                    phase2Upload = uploadByClientRef[1]
                    console.log('[Auto-start] Found upload by clientReference (different key)', {
                        expectedKey: clientId,
                        actualKey: uploadByClientRef[0],
                        upload: uploadByClientRef[1]
                    })
                } else {
                    // Phase 2 upload doesn't exist - this might be an old upload or it was removed
                    // Skip and let the effect retry when state updates
                    return
                }
            }
            
            console.log('[Auto-start] Phase 2 upload found', {
                clientId,
                phase2Status: phase2Upload.status,
                phase2ClientRef: phase2Upload.clientReference
            })
            
            // OPTIMIZATION 2: Use memoized clientId set for fast lookup
            const belongsToPhase3 = phase3ClientIdSet.has(clientId)
            const isCurrentlyAdding = currentlyAddingRef.current.has(clientId)
            
            if (!belongsToPhase3 && !isCurrentlyAdding) {
                // Phase 2 upload exists but doesn't belong to current Phase 3 items and isn't being added
                // This is likely an old upload - skip it
                return
            }

            // OPTIMIZATION 6: Reduced logging - only log errors
            // CRITICAL: Check for rehydrated uploads BEFORE checking status
            if (!phase2Upload.file) {
                console.error('[Auto-start] Rehydrated upload detected (no file object)', { clientId })
                phase2Manager.removeUpload(clientId)
                startingUploadsRef.current.delete(clientId)
                markUploadFailed(clientId, {
                    message: 'Previous upload session expired. Please upload again.',
                    type: 'rehydrated_expired',
                })
                return
            }
            
            // Guard: Only start if Phase 2 upload is in a startable state
            if (phase2Upload.status !== 'pending' && phase2Upload.status !== 'paused') {
                return
            }

            // Guard: Do NOT restart if Phase 3 item is already terminal
            if (item.uploadStatus !== 'queued') {
                return
            }

            // Mark as starting to prevent double-start
            startingUploadsRef.current.add(clientId)
            
            // CRITICAL: Verify it's a new upload (no uploadSessionId) - old uploads have sessionId
            if (phase2Upload.uploadSessionId) {
                console.error('[Auto-start] Old upload detected (has uploadSessionId before start)', { clientId })
                phase2Manager.removeUpload(clientId)
                startingUploadsRef.current.delete(clientId)
                markUploadFailed(clientId, {
                    message: 'Previous upload session expired. Please upload the file again.',
                    type: 'old_upload_expired',
                })
                return
            }

            // Start the upload
            phase2Manager.startUpload(clientId)
                .then(() => {
                    startingUploadsRef.current.delete(clientId)
                    
                    // OPTIMIZATION 5: Simplify currentlyAddingRef lifecycle - clear immediately when upload starts
                    // Wait briefly for Phase 2 to update status, then clear ref and update Phase 3
                    setTimeout(() => {
                        // Get the upload directly from manager (most reliable source)
                        let phase2UploadAfterStart = phase2Manager.uploads.get(clientId)
                        
                        if (!phase2UploadAfterStart) {
                            // Try finding by clientReference (fallback) - shouldn't happen but handle it
                            const allManagerUploads = Array.from(phase2Manager.uploads.entries())
                            const uploadByClientRef = allManagerUploads.find(([key, upload]) => upload.clientReference === clientId)
                            if (uploadByClientRef) {
                                phase2UploadAfterStart = uploadByClientRef[1]
                            }
                        }
                        
                        if (!phase2UploadAfterStart) {
                            console.error('[Auto-start] Upload not found after startUpload()', { clientId })
                            markUploadFailed(clientId, {
                                message: 'Upload disappeared after starting',
                                type: 'unknown'
                            })
                            currentlyAddingRef.current.delete(clientId)
                            return
                        }
                        
                        // OPTIMIZATION 5: Clear currentlyAddingRef immediately when upload starts
                        // Upload is now in uploading/initiating state - safe to clear
                        currentlyAddingRef.current.delete(clientId)
                        
                        // Update Phase 3 status based on Phase 2 status
                        if (phase2UploadAfterStart.status === 'uploading' || phase2UploadAfterStart.status === 'initiating') {
                            if (phase2UploadAfterStart.uploadSessionId) {
                                setUploadSessionId(clientId, phase2UploadAfterStart.uploadSessionId)
        } else {
                                markUploadStarted(clientId)
                            }
                        } else if (phase2UploadAfterStart.status === 'completed') {
                            // Upload was already completed (old upload that was rehydrated)
                            // OPTIMIZATION 6: Only log errors/warnings for unexpected states
                            console.warn('[Auto-start] Upload already completed (rehydrated)', { clientId })
                            markUploadComplete(clientId, phase2UploadAfterStart.uploadSessionId)
                        } else if (phase2UploadAfterStart.status === 'failed') {
                            // Upload failed to start - classify error for better user messaging
                            const errorMessage = phase2UploadAfterStart.error || phase2UploadAfterStart.errorInfo?.message || 'Upload failed to start'
                            const isCorsError = errorMessage.includes('browser security') || 
                                               errorMessage.includes('dev config issue') ||
                                               (phase2UploadAfterStart.errorInfo?.type === 'cors')
                            
                            console.error('[Auto-start] Upload failed to start', {
                                clientId,
                                error: errorMessage,
                                errorType: phase2UploadAfterStart.errorInfo?.type
                            })
                            
                            markUploadFailed(clientId, {
                                message: isCorsError 
                                    ? 'Upload blocked by browser security. This is typically a development environment configuration issue (CORS).'
                                    : errorMessage,
                                type: phase2UploadAfterStart.errorInfo?.type || (isCorsError ? 'cors' : 'unknown'),
                                httpStatus: phase2UploadAfterStart.errorInfo?.http_status
                            })
                            
                            // Remove from Phase 2 if it's a CORS error (likely config issue, won't retry successfully)
                            if (isCorsError) {
                                phase2Manager.removeUpload(clientId)
                            }
                        } else {
                            // Unexpected status - sync effect will handle
                            markUploadStarted(clientId)
                        }
                    }, 50) // Small delay to let Phase 2 update status
                    
                    // Effect will re-run when phase2Uploads or phase3Items change
                    // and check for more queued items
                })
                .catch(error => {
                    // OPTIMIZATION 6: Reduced logging - only log errors (not debug info)
                    console.error('[Auto-start] Upload failed to start', { 
                        clientId, 
                        errorMessage: error.message, 
                        httpStatus: error.response?.status 
                    })
                    startingUploadsRef.current.delete(clientId)
                    currentlyAddingRef.current.delete(clientId)
                    
                    // Classify error type for better user messaging
                    const errorMessage = error.response?.data?.message || error.message || error.toString() || ''
                    const isS3NotFoundError = errorMessage.includes('does not exist in S3') || 
                                            errorMessage.includes('object does not exist') ||
                                            error.response?.status === 400
                    const isExpiredUpload = isS3NotFoundError || 
                                          errorMessage.includes('expired') ||
                                          (errorMessage.includes('session') && errorMessage.includes('expired'))
                    const isCorsError = errorMessage.includes('browser security') || 
                                       errorMessage.includes('dev config issue') ||
                                       (error instanceof TypeError && errorMessage.includes('fetch'))
                    
                    // Mark as failed in Phase 3 (this frees up a slot)
                    // Failed uploads are terminal and MUST free a slot
                    let failureType = 'unknown'
                    let failureMessage = errorMessage || 'Failed to start upload'
                    
                    if (isExpiredUpload) {
                        failureType = 'old_upload_expired'
                        failureMessage = 'Previous upload session expired. Please upload the file again.'
                    } else if (isCorsError) {
                        failureType = 'cors'
                        failureMessage = 'Upload blocked by browser security. This is typically a development environment configuration issue (CORS).'
                    }
                    
                    markUploadFailed(clientId, {
                        message: failureMessage,
                        type: failureType,
                        httpStatus: error.response?.status,
                        rawError: error.response?.data
                    })
                    
                    // If this was a rehydrated/expired upload, remove it from Phase 2
                    // This prevents it from being retried
                    if (isExpiredUpload || isCorsError) {
                        phase2Manager.removeUpload(clientId)
                    }
                })
        })
    }, [queuedItems, activeUploads, activeCount, availableSlots, phase3ClientIdSet, markUploadFailed, setUploadSessionId, markUploadStarted])

    // Clean up starting ref and currently adding ref when dialog closes
    useEffect(() => {
        // TEMPORARILY COMMENTED FOR DIAGNOSTIC: if (!open) {
        //     startingUploadsRef.current.clear()
        //     currentlyAddingRef.current.clear()
        // }
    }, [open])

    /**
     * ═══════════════════════════════════════════════════════════════
     * CLEAN UPLOADER V2 — Finalize button rules
     * ═══════════════════════════════════════════════════════════════
     * 
     * Implements Finalize button enable/disable logic and labels using v2Files.
     * NO backend calls - UI state only.
     */

    /**
     * CLEAN UPLOADER V2 — Check if Finalize button should be enabled
     * 
     * Enabled if and only if:
     * - At least one v2File has status === 'uploaded'
     * - No v2File has status === 'uploading' or 'finalizing'
     * - selectedCategoryId !== null (category must be selected)
     */
    // Phase 2.8: Track UploadManager state changes to trigger UI updates for multipart uploads
    // MUST be defined before useMemo hooks that depend on it
    const [uploadManagerStateVersion, setUploadManagerStateVersion] = useState(0)
    
    useEffect(() => {
        // Subscribe to UploadManager changes to trigger UI updates for multipart uploads
        const unsubscribe = UploadManager.subscribe(() => {
            setUploadManagerStateVersion(prev => prev + 1)
        })
        return unsubscribe
    }, [])
    
    /**
     * Phase 2.8: Batch status computation includes UploadManager state for multipart uploads
     * 
     * Rules (evaluated in order):
     * 1. If any file.status === 'finalizing' → batchStatus = 'finalizing'
     * 2. Else if all files finalized/completed → batchStatus = 'complete'
     * 3. Else if some completed and some failed → 'partial_success'
     * 4. Else if any uploading (direct or multipart) → 'uploading'
     * 5. Else if any uploaded/completed → 'ready'
     * 6. Else → 'idle'
     */
    const batchStatus = useMemo(() => {
        if (v2Files.length === 0) {
            return 'idle'
        }

        // Get all UploadManager uploads for multipart check
        const allUploadManagerUploads = UploadManager.getUploads()
        const uploadManagerMap = new Map(
            allUploadManagerUploads.map(u => [u.clientReference, u])
        )

        // Filter out cancelled uploads from consideration
        const activeV2Files = v2Files.filter(f => {
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
            if (uploadManagerClientRef) {
                const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                // Exclude if cancelled in UploadManager
                if (uploadManagerUpload?.status === 'cancelled') {
                    return false
                }
            }
            // Exclude if cancelled in v2Files (for direct uploads)
            return f.status !== 'cancelled'
        })

        if (activeV2Files.length === 0) {
            return 'idle' // All uploads cancelled
        }

        const hasFinalizing = activeV2Files.some((f) => f.status === 'finalizing')
        if (hasFinalizing) {
            return 'finalizing'
        }

        // Count finalized files (direct uploads)
        const finalizedCount = activeV2Files.filter((f) => f.status === 'finalized').length
        
        // Count completed multipart uploads (from UploadManager)
        const completedMultipartCount = activeV2Files.filter((f) => {
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
            if (uploadManagerClientRef) {
                const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                return uploadManagerUpload?.status === 'completed'
            }
            return false
        }).length
        
        // Count uploaded direct uploads (ready for finalize)
        const uploadedDirectCount = activeV2Files.filter((f) => {
            // Only count direct uploads (not multipart)
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
            if (!uploadManagerClientRef) {
                return f.status === 'uploaded'
            }
            return false
        }).length

        const failedCount = activeV2Files.filter((f) => {
            // Direct uploads: check v2Files status
            if (f.status === 'failed') {
                return true
            }
            // Multipart uploads: check UploadManager status
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
            if (uploadManagerClientRef) {
                const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                return uploadManagerUpload?.status === 'failed'
            }
            return false
        }).length
        
        const totalCount = activeV2Files.length

        // Check if all files are finalized/completed
        const totalCompleted = finalizedCount + completedMultipartCount + uploadedDirectCount
        if (totalCompleted === totalCount && totalCount > 0) {
            return 'complete'
        }

        // Check if some completed and some failed
        if (totalCompleted > 0 && failedCount > 0) {
            return 'partial_success'
        }

        // Check for active uploads (direct or multipart)
        const hasUploading = activeV2Files.some((f) => {
            // Direct uploads: check v2Files status
            if (f.status === 'uploading') {
                return true
            }
            // Multipart uploads: check UploadManager status
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
            if (uploadManagerClientRef) {
                const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                if (uploadManagerUpload && 
                    (uploadManagerUpload.status === 'initiating' || 
                     uploadManagerUpload.status === 'uploading' || 
                     uploadManagerUpload.status === 'completing')) {
                    return true
                }
            }
            return false
        })
        
        if (hasUploading) {
            return 'uploading'
        }

        // Check for uploaded/completed files ready for finalize
        if (totalCompleted > 0) {
            return 'ready'
        }

        return 'idle'
    }, [v2Files, uploadManagerStateVersion])

    // Phase 2.8: Finalize gating logic
    // An upload is considered COMPLETE if:
    // - v2File.status === 'uploaded' (direct uploads)
    // - UploadManager upload.status === 'completed' (multipart uploads)
    // Cancelled uploads are excluded from gating
    const canFinalizeV2 = useMemo(() => {
        // Get all UploadManager uploads for multipart check
        const allUploadManagerUploads = UploadManager.getUploads()
        const uploadManagerMap = new Map(
            allUploadManagerUploads.map(u => [u.clientReference, u])
        )
        
        // Filter out cancelled uploads from consideration
        const activeV2Files = v2Files.filter(f => {
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
            if (uploadManagerClientRef) {
                const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                // Exclude if cancelled in UploadManager
                if (uploadManagerUpload?.status === 'cancelled') {
                    return false
                }
            }
            // Exclude if cancelled in v2Files (for direct uploads)
            return f.status !== 'cancelled'
        })
        
        if (activeV2Files.length === 0) {
            return false // No active uploads
        }
        
        // Phase 3.0: Enhanced finalize gating - require ALL uploads to complete
        // Check if ALL files are completed (not just some)
        const allCompleted = activeV2Files.every((f) => {
            // Direct uploads: must be 'uploaded'
            if (f.status === 'uploaded') {
                return true
            }
            
            // Multipart uploads: must be 'completed' in UploadManager
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
            if (uploadManagerClientRef) {
                const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                if (uploadManagerUpload?.status === 'completed') {
                    return true
                }
                return false
            }
            
            // Not a multipart upload, so must be direct upload with status 'uploaded'
            return false
        })
        
        // Check for active uploads (direct or multipart) - ANY active upload blocks finalize
        const hasUploading = activeV2Files.some((f) => {
            // Direct uploads: check v2Files status
            if (f.status === 'uploading' || f.status === 'selected') {
                return true
            }
            
            // Multipart uploads: check UploadManager status
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
            if (uploadManagerClientRef) {
                const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                if (uploadManagerUpload && 
                    (uploadManagerUpload.status === 'initiating' || 
                     uploadManagerUpload.status === 'uploading' || 
                     uploadManagerUpload.status === 'completing')) {
                    return true
                }
            }
            
            return false
        })
        
        // Check for failed uploads (block finalize)
        const hasFailed = activeV2Files.some((f) => {
            // Direct uploads: check v2Files status
            if (f.status === 'failed') {
                return true
            }
            
            // Multipart uploads: check UploadManager status
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
            if (uploadManagerClientRef) {
                const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                if (uploadManagerUpload?.status === 'failed') {
                    return true
                }
            }
            
            return false
        })
        
        const hasFinalizing = v2Files.some((f) => f.status === 'finalizing')
        const hasCategory = selectedCategoryId !== null

        // Phase 3.0: Can finalize ONLY when:
        // - ALL uploads are completed (not just some)
        // - No active uploads (no uploading, no queued/selected)
        // - No failed uploads (failed blocks finalize)
        // - Not currently finalizing
        // - Category is selected
        return allCompleted && !hasUploading && !hasFailed && !hasFinalizing && hasCategory
    }, [v2Files, selectedCategoryId, uploadManagerStateVersion])

    /**
     * CLEAN UPLOADER V2 — Get warnings for missing required fields
     */
    const v2Warnings = useMemo(() => {
        const warnings = []
        const hasUploaded = v2Files.some((f) => f.status === 'uploaded')

        // Only show warnings if there are uploaded files ready to finalize
        if (!hasUploaded) {
            return warnings
        }

        // Category is required
        if (selectedCategoryId === null) {
            warnings.push('Category is required before finalizing assets.')
        }

        return warnings
    }, [v2Files, selectedCategoryId])

    /**
     * CLEAN UPLOADER V2 — Get Finalize button label based on batchStatus
     */
    // Phase 3.0: Enhanced button label - clearly indicate when uploads are in progress
    // Button label reflects current state and prevents confusion about when finalize is available
    const finalizeButtonLabelV2 = useMemo(() => {
        if (batchStatus === 'finalizing') {
            return 'Finalizing uploads…'
        } else if (batchStatus === 'uploading') {
            return 'Uploading…'
        } else if (canFinalizeV2) {
            // Button is enabled - show finalize label
            return 'Finalize uploads'
        } else {
            // Button is disabled - show why (uploads in progress or category missing)
            if (batchStatus === 'ready' || batchStatus === 'partial_success') {
                // Uploads complete but category missing
                return 'Select category'
            } else {
                // Uploads still in progress
                return 'Uploading…'
            }
        }
    }, [batchStatus, canFinalizeV2])

    /**
     * ═══════════════════════════════════════════════════════════════
     * CLEAN UPLOADER V2 — Finalize execution (manifest-driven)
     * ═══════════════════════════════════════════════════════════════
     * 
     * Implements finalize execution using a manifest-driven backend call.
     * Handles responses per file and updates state accordingly.
     * Uses v2Files state - NO legacy upload logic.
     */

    /**
     * CLEAN UPLOADER V2 — Handle Finalize button click
     * 
     * Builds manifest from uploaded v2Files and calls backend finalize endpoint.
     * Handles responses per file and updates batchStatus accordingly.
     */
    const handleFinalizeV2 = useCallback(async () => {
        // Only allow finalize if button is enabled
        if (!canFinalizeV2) {
            return
        }

        // Phase 2.8: Build manifest from v2Files with status === 'uploaded'
        // Also include multipart uploads that are completed in UploadManager
        const allUploadManagerUploads = UploadManager.getUploads()
        const uploadManagerMap = new Map(
            allUploadManagerUploads.map(u => [u.clientReference, u])
        )
        
        const uploadedFiles = v2Files.filter((f) => {
            // Direct uploads: check v2Files status
            if (f.status === 'uploaded') {
                return true
            }
            
            // Multipart uploads: check UploadManager status
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
            if (uploadManagerClientRef) {
                const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                // Include if completed in UploadManager (even if not yet synced to 'uploaded' in v2Files)
                if (uploadManagerUpload?.status === 'completed') {
                    return true
                }
            }
            
            return false
        })
        
        if (uploadedFiles.length === 0) {
            return // Should not happen if canFinalizeV2 is true, but guard anyway
        }

        console.log('[FINALIZE_V2] Starting finalize', { uploadedCount: uploadedFiles.length })

        // Helper functions to derive title and resolvedFilename (matching usePhase3UploadManager logic)
        const normalizeTitle = (title) => {
            if (!title || typeof title !== 'string') return '';
            let normalized = title.trim();
            normalized = normalized.replace(/[-_]/g, ' ');
            normalized = normalized.replace(/\s+/g, ' ');
            normalized = normalized.replace(/[^a-zA-Z0-9\s]/g, '');
            normalized = normalized.replace(/\s+/g, ' ').trim();
            normalized = normalized
                .split(' ')
                .map(word => word ? word.charAt(0).toUpperCase() + word.slice(1).toLowerCase() : '')
                .filter(word => word.length > 0)
                .join(' ');
            return normalized || '';
        };
        
        const slugify = (str) => {
            if (!str || typeof str !== 'string') return 'untitled';
            return str.toLowerCase().trim()
                .replace(/[^\w\s-]/g, '')
                .replace(/[\s_-]+/g, '-')
                .replace(/^-+|-+$/g, '');
        };
        
        const deriveResolvedFilename = (title, extension) => {
            const slugified = slugify(title || 'untitled');
            return extension ? `${slugified}.${extension}` : slugified;
        };

        // Phase 2.8: Build manifest items
        // Derive title and resolvedFilename from file.name
        // For multipart uploads, ensure uploadKey is set from UploadManager if missing
        const manifest = uploadedFiles.map((fileEntry) => {
            // Phase 2.8: For multipart uploads, get uploadKey from UploadManager if not set in v2File
            let uploadKey = fileEntry.uploadKey
            if (!uploadKey) {
                const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(fileEntry.clientId)
                if (uploadManagerClientRef) {
                    const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                    if (uploadManagerUpload?.uploadSessionId) {
                        uploadKey = `temp/uploads/${uploadManagerUpload.uploadSessionId}/original`
                    }
                }
            }
            
            // Get filename without extension for title
            const fileName = fileEntry.file.name || 'unknown'
            const lastDotIndex = fileName.lastIndexOf('.')
            const extension = lastDotIndex > 0 ? fileName.substring(lastDotIndex + 1) : ''
            const baseName = lastDotIndex > 0 ? fileName.substring(0, lastDotIndex) : fileName
            
            // Use user-edited title from v2File if available, otherwise derive from filename
            // This ensures user edits are preserved during finalize
            const userTitle = fileEntry.title || null
            const normalizedTitle = userTitle || normalizeTitle(baseName) || normalizeTitle(fileName) || null
            
            // Use resolvedFilename from v2File if set (user edited), otherwise derive it
            const resolvedFilename = fileEntry.resolvedFilename || deriveResolvedFilename(normalizedTitle || baseName, extension)
            
            return {
                upload_key: uploadKey,
                expected_size: fileEntry.file.size,
                category_id: selectedCategoryId,
                metadata: getEffectiveMetadataV2(fileEntry.clientId),
                title: normalizedTitle, // This now includes user-edited title if available
                resolved_filename: resolvedFilename,
            }
        })

        console.log('[FINALIZE_V2] Built manifest', { manifestCount: manifest.length })

        // Set status to 'finalizing' for manifest files
        // Note: batchStatus is now computed from v2Files, no manual update needed
        const uploadedClientIds = new Set(uploadedFiles.map((f) => f.clientId))
        setV2Files((prevFiles) =>
            prevFiles.map((f) =>
                uploadedClientIds.has(f.clientId)
                    ? { ...f, status: 'finalizing', error: null }
                    : f
            )
        )

        try {
            // Call backend finalize endpoint using fetch
            const response = await fetch('/app/assets/upload/finalize', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
                body: JSON.stringify({ manifest }),
            })

            if (!response.ok) {
                const errorText = await response.text().catch(() => 'Unknown error')
                throw new Error(`Finalize failed: ${response.status} ${response.statusText} - ${errorText}`)
            }

            const responseData = await response.json()
            console.log('[FINALIZE_V2] Response received', responseData)

            // Handle backend response per file
            const results = responseData.results || []
            const resultsByUploadKey = new Map()
            results.forEach((result) => {
                if (result.upload_key) {
                    resultsByUploadKey.set(result.upload_key, result)
                }
            })

            // Phase 2.8: Update v2Files states based on results
            // For multipart uploads, also match by uploadSessionId if uploadKey doesn't match
            let finalizedCount = 0
            let failedCount = 0

            setV2Files((prevFiles) => {
                return prevFiles.map((f) => {
                    if (!uploadedClientIds.has(f.clientId)) {
                        return f // Not in manifest, leave unchanged
                    }

                    // Phase 2.8: Match result to file using upload_key
                    // For multipart uploads, also try matching by uploadSessionId if uploadKey doesn't match
                    let result = resultsByUploadKey.get(f.uploadKey)
                    let uploadManagerUpload = null
                    
                    // If no match and this is a multipart upload, try matching by uploadSessionId
                    if (!result) {
                        const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
                        if (uploadManagerClientRef) {
                            uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                            if (uploadManagerUpload?.uploadSessionId) {
                                // Try matching by upload session ID path
                                const sessionUploadKey = `temp/uploads/${uploadManagerUpload.uploadSessionId}/original`
                                result = resultsByUploadKey.get(sessionUploadKey)
                            }
                        }
                    } else {
                        // Get uploadManagerUpload for reference even if result was found by uploadKey
                        const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
                        if (uploadManagerClientRef) {
                            uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                        }
                    }

                    if (!result) {
                        // No result for this file - treat as failure
                        failedCount++
                        console.log('[FINALIZE_V2] No result for file', { 
                            clientId: f.clientId, 
                            uploadKey: f.uploadKey,
                            isMultipart: !!v2ToUploadManagerMapRef.current.get(f.clientId)
                        })
                        
                        // Phase 2.5 Step 1: Normalize error for consistent AI-ready format
                        const normalizedError = normalizeUploadError(
                            new Error('Finalize did not return a result for this file.'),
                            {
                                uploadSessionId: f.uploadSessionId || uploadManagerUpload?.uploadSessionId || null,
                                fileName: f.file?.name || 'unknown',
                                file: f.file,
                                stage: 'finalize',
                                assetId: null,
                            }
                        )
                        
                        return {
                            ...f,
                            status: 'failed',
                            error: {
                                stage: 'finalize',
                                code: normalizedError.error_code,
                                message: normalizedError.message,
                                normalized: normalizedError,
                            },
                        }
                    }

                    // Log matched clientId for each result
                    console.log('[FINALIZE_V2] Matched result to file', { 
                        clientId: f.clientId, 
                        uploadKey: f.uploadKey, 
                        resultStatus: result.status,
                        isMultipart: !!v2ToUploadManagerMapRef.current.get(f.clientId)
                    })

                    // Phase 2.8: Check result.status explicitly (not result.success)
                    // For BOTH direct and multipart uploads, mark as finalized on success
                    if (result.status === 'success') {
                        // Success: set file.status = 'finalized', clear file.error
                        finalizedCount++
                        console.log('[FINALIZE_V2] File marked as finalized', { 
                            clientId: f.clientId, 
                            uploadKey: f.uploadKey,
                            isMultipart: !!v2ToUploadManagerMapRef.current.get(f.clientId)
                        })
                        return {
                            ...f,
                            status: 'finalized',
                            error: null,
                            // Phase 2.8: Preserve uploadKey for multipart uploads (ensure it's set)
                            uploadKey: f.uploadKey || (uploadManagerUpload?.uploadSessionId ? 
                                `temp/uploads/${uploadManagerUpload.uploadSessionId}/original` : 
                                f.uploadKey)
                        }
                    } else if (result.status === 'failed') {
                        // Failure: set file.status = 'failed', set file.error with stage: 'finalize'
                        failedCount++
                        console.log('[FINALIZE_V2] File marked as failed', { clientId: f.clientId, uploadKey: f.uploadKey, error: result.error })
                        
                        // Phase 2.5 Step 1: Normalize error for consistent AI-ready format
                        const errorObj = new Error(result.error?.message || 'Finalize failed.')
                        if (result.error?.code) {
                            errorObj.code = result.error.code
                        }
                        const normalizedError = normalizeUploadError(errorObj, {
                            uploadSessionId: f.uploadSessionId || uploadManagerUpload?.uploadSessionId || null,
                            fileName: f.file?.name || 'unknown',
                            file: f.file,
                            stage: 'finalize',
                            assetId: result.asset_id || null,
                        })
                        
                        return {
                            ...f,
                            status: 'failed',
                            error: {
                                stage: 'finalize',
                                code: normalizedError.error_code,
                                message: normalizedError.message,
                                fields: result.error?.fields,
                                normalized: normalizedError,
                            },
                        }
                    } else {
                        // Unknown status - treat as failure
                        failedCount++
                        console.warn('[FINALIZE_V2] Unknown result status', { clientId: f.clientId, uploadKey: f.uploadKey, status: result.status })
                        
                        // Phase 2.5 Step 1: Normalize error for consistent AI-ready format
                        const normalizedError = normalizeUploadError(
                            new Error(`Finalize returned unknown status: ${result.status}`),
                            {
                                uploadSessionId: f.uploadSessionId || uploadManagerUpload?.uploadSessionId || null,
                                fileName: f.file?.name || 'unknown',
                                file: f.file,
                                stage: 'finalize',
                                assetId: null,
                            }
                        )
                        
                        return {
                            ...f,
                            status: 'failed',
                            error: {
                                stage: 'finalize',
                                code: normalizedError.error_code,
                                message: normalizedError.message,
                                normalized: normalizedError,
                            },
                        }
                    }
                })
            })

            // batchStatus is now computed from v2Files, no manual update needed
            
            // Set success state if all files finalized and no failures (for UI overlay during auto-close delay)
            if (finalizedCount > 0 && failedCount === 0) {
                setIsFinalizeSuccess(true)
            }
        } catch (error) {
            console.error('[FINALIZE_V2] Error during finalize', error)
            // Network / unexpected errors - mark ALL manifest files as failed
            
            // Phase 2.5 Step 1: Normalize error for consistent AI-ready format
            // We'll normalize per-file below, but first extract common error info
            const httpStatus = error.response?.status || error.status
            const errorMessage = error.response?.data?.message || error.message || 'Finalize failed'
            
            setV2Files((prevFiles) =>
                prevFiles.map((f) =>
                    uploadedClientIds.has(f.clientId)
                        ? (() => {
                            // Normalize error for this specific file
                            const normalizedError = normalizeUploadError(error, {
                                httpStatus,
                                uploadSessionId: f.uploadSessionId || null,
                                fileName: f.file?.name || 'unknown',
                                file: f.file,
                                stage: 'finalize',
                                assetId: null,
                            })
                            
                            return {
                                ...f,
                                status: 'failed',
                                error: {
                                    stage: 'finalize',
                                    code: normalizedError.error_code,
                                    message: normalizedError.message,
                                    fields: undefined, // Network errors don't have field-level errors
                                    normalized: normalizedError,
                                },
                            }
                        })()
                        : f
                )
            )
            // Note: batchStatus is now computed from v2Files, no manual update needed
        }
    }, [canFinalizeV2, v2Files, selectedCategoryId, getEffectiveMetadataV2])

    /**
     * LEGACY — DO NOT USE: Finalize Assets
     * 
     * Finalizes all completed uploads by calling the backend endpoint.
     * Sends upload_session_id and asset_type for each completed upload.
     * 
     * ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
     */
    const [isFinalizing, setIsFinalizing] = useState(false)
    const [finalizeError, setFinalizeError] = useState(null)

    const handleFinalizeLegacy = useCallback(async () => {
        // Freeze legacy finalize logic
        if (!USE_LEGACY_UPLOADER) {
            setFinalizeError('Finalize is temporarily disabled during rewrite')
            return // Early return - legacy finalize logic frozen
        }
        
        if (isFinalizing) return

        // Get completed items
        const completedItems = phase3Manager.completedItems
        
        if (completedItems.length === 0) {
            setFinalizeError('No completed uploads to finalize')
            return
        }

        // Check if category is selected
        if (!phase3Manager.context.categoryId) {
            setFinalizeError('Please select a category before finalizing')
            return
        }

        setIsFinalizing(true)
        setFinalizeError(null)

        try {
            // Finalize each completed upload
            const finalizePromises = completedItems.map(async (item) => {
                if (!item.uploadSessionId) {
                    throw new Error(`Upload session ID missing for ${item.originalFilename}`)
                }

                // Get effective metadata for this item (combines global + per-file metadata)
                const effectiveMetadata = phase3Manager.getEffectiveMetadata(item.clientId)

                // Build payload - only include metadata if non-empty, otherwise send empty object
                const payloadData = {
                    upload_session_id: item.uploadSessionId,
                    asset_type: defaultAssetType === 'asset' ? 'asset' : 'marketing',
                    title: item.title || null,
                    filename: item.resolvedFilename,
                    category_id: phase3Manager.context.categoryId || null,
                    metadata: Object.keys(effectiveMetadata).length > 0 ? { fields: effectiveMetadata } : {},
                }
                
                console.log('[Finalize Payload] Full payload being sent:', JSON.stringify(payloadData, null, 2))
                console.log('[Finalize Payload] Phase 3 Manager Context:', {
                    categoryId: phase3Manager.context.categoryId,
                    brandId: phase3Manager.context.brandId,
                    companyId: phase3Manager.context.companyId,
                })
                console.log('[Finalize Payload] Item data:', {
                    clientId: item.clientId,
                    title: item.title,
                    resolvedFilename: item.resolvedFilename,
                    uploadSessionId: item.uploadSessionId,
                    uploadStatus: item.uploadStatus,
                    progress: item.progress,
                })
                console.log('[Finalize Payload] Effective metadata:', effectiveMetadata)

                // Call backend endpoint
                // Include title, resolvedFilename, category_id, and metadata for backend persistence
                const response = await window.axios.post('/app/assets/upload/complete', payloadData)

                return response.data
            })

            // Wait for all finalizations to complete
            await Promise.all(finalizePromises)

            // Success - close modal, clear state, refresh asset list
            // Get all item IDs before removing (to avoid iteration issues)
            const itemIds = phase3Manager.items.map(item => item.clientId)
            
            // Remove all items from Phase 3 manager
            itemIds.forEach(clientId => {
                phase3Manager.removeItem(clientId)
            })
            
            // Close modal
            onClose()
            
            // Refresh asset list (reload current page to show new assets)
            router.reload({ only: ['assets'] })
        } catch (error) {
            // Error handling - don't destroy upload state, allow retry
            const errorMessage = error.response?.data?.message || 
                                error.message || 
                                'Failed to finalize assets'
            
            setFinalizeError(errorMessage)
        } finally {
            // CRITICAL: Always reset finalizing state, regardless of success or failure
            setIsFinalizing(false)
            console.log('[Finalize] isFinalizing reset')
        }
    }, [phase3Manager, defaultAssetType, isFinalizing, onClose])

    // LEGACY — DO NOT USE: Check if finalize button should be enabled
    // CRITICAL: Check if S3 uploads are actually complete (uploaded_size >= expected_size)
    // Phase 2 completion only means frontend thinks S3 finished - verify with backend
    // Track backend session status (uploaded_size, expected_size) for each upload session
    // Maps uploadSessionId -> { uploaded_size: number, expected_size: number }
    // ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
    const [backendUploadSizes, setBackendUploadSizes] = useState(new Map())
    
    // Force re-render to trigger status checks
    const [sizeCheckTick, setSizeCheckTick] = useState(0)
    
    // Track which sessions are currently being checked (prevent duplicate requests)
    const checkingSessionsRef = useRef(new Set())
    
    // LEGACY — DO NOT USE: Check backend upload sizes for completed items (backend session polling)
    // ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
    useEffect(() => {
        // Freeze legacy backend session checking/polling logic
        if (!USE_LEGACY_UPLOADER) {
            return // Early return - legacy backend checks frozen
        }
        const completedItems = phase3Manager.items.filter(item => item.uploadStatus === 'complete' && item.uploadSessionId)
        
        if (completedItems.length === 0) {
            return
        }
        
        let hasIncomplete = false
        
        // Check sizes for all completed items
        completedItems.forEach((item) => {
            const sessionId = item.uploadSessionId
            const currentSizes = backendUploadSizes.get(sessionId)
            const isComplete = currentSizes && currentSizes.uploaded_size >= currentSizes.expected_size && currentSizes.expected_size > 0
            
            // Skip if already known to be complete
            if (isComplete) {
                return
            }
            
            // Skip if already checking (prevent duplicate requests)
            if (checkingSessionsRef.current.has(sessionId)) {
                hasIncomplete = true
                return
            }
            
            // Mark as checking
            checkingSessionsRef.current.add(sessionId)
            hasIncomplete = true
            
            // Check backend upload sizes
            window.axios.get(`/app/uploads/${sessionId}/resume`)
                .then(response => {
                    const uploadedSize = response.data.uploaded_size ?? 0
                    const expectedSize = response.data.expected_size ?? 0
                    const s3ObjectExists = response.data.s3_object_exists ?? false
                    
                    // Update sizes
                    setBackendUploadSizes(prev => {
                        const next = new Map(prev)
                        next.set(sessionId, { 
                            uploaded_size: uploadedSize, 
                            expected_size: expectedSize,
                            s3_object_exists: s3ObjectExists
                        })
                        return next
                    })
                    
                    // Upload is complete if: s3_object_exists AND uploaded_size >= expected_size AND expected_size > 0
                    const isComplete = s3ObjectExists && uploadedSize >= expectedSize && expectedSize > 0
                    
                    // If not complete, continue polling
                    if (!isComplete) {
                        setTimeout(() => {
                            setSizeCheckTick(prev => prev + 1)
                        }, 500)
                    }
                })
                .catch(error => {
                    console.error('[Backend Size Check] Failed to check upload sizes', {
                        sessionId,
                        error: error.message
                    })
                    
                    // Retry on error
                    setTimeout(() => {
                        setSizeCheckTick(prev => prev + 1)
                    }, 1000)
                })
                .finally(() => {
                    // Remove from checking set
                    checkingSessionsRef.current.delete(sessionId)
                })
        })
        
        // If there are incomplete uploads, schedule another check
        if (hasIncomplete) {
            const timeout = setTimeout(() => {
                setSizeCheckTick(prev => prev + 1)
            }, 500) // Check every 500ms
            
            return () => clearTimeout(timeout)
        }
    }, [phase3Manager.items, sizeCheckTick, backendUploadSizes])
    
    const allUploadsBackendStable = useMemo(() => {
        if (phase3Manager.items.length === 0) return false
        
        // Only check completed items
        const completedItems = phase3Manager.items.filter(item => item.uploadStatus === 'complete')
        
        if (completedItems.length === 0) {
            return false
        }
        
        // All completed items must have uploadSessionId AND S3 object exists AND uploaded_size >= expected_size
        const result = completedItems.every(item => {
            if (!item.uploadSessionId) {
                return false
            }
            
            const sizes = backendUploadSizes.get(item.uploadSessionId)
            if (!sizes) {
                return false // Not checked yet
            }
            
            // Upload is complete if: s3_object_exists AND uploaded_size >= expected_size AND expected_size > 0
            return sizes.s3_object_exists && 
                   sizes.uploaded_size >= sizes.expected_size && 
                   sizes.expected_size > 0
        })
        
        return result
    }, [phase3Manager.items, backendUploadSizes])
    
    // Check if all uploads are complete (in terminal state)
    const allUploadsComplete = useMemo(() => {
        if (phase3Manager.items.length === 0) return false
        return phase3Manager.items.every(item => 
            item.uploadStatus === 'complete' || item.uploadStatus === 'failed'
        )
    }, [phase3Manager.items])
    
    // LEGACY — DO NOT USE: Check if button should be enabled (all complete AND backend-stable)
    // ⚠️ FROZEN: Disabled by USE_LEGACY_UPLOADER flag — will be removed after Step 7
    // STEP 3: New canFinalize logic is defined above based on new files state
    const canFinalizeLegacy = phase3Manager.canFinalize && 
                       !isFinalizing && 
                       allUploadsComplete &&
                       allUploadsBackendStable
    
    // Check if waiting for backend stability (all complete but not yet stable)
    const isWaitingForStability = allUploadsComplete && !allUploadsBackendStable

    // Collect blocking validation errors for form-level alert
    // These explain why the Finalize button is disabled
    const blockingErrors = useMemo(() => {
        const errors = []
        
        // Category not selected
        if (!phase3Manager.context.categoryId) {
            errors.push('Category is required before finalizing assets.')
        }
        
        // Required metadata fields missing
        const missingRequiredWarnings = phase3Manager.warnings.filter(
            w => w.type === 'missing_required_field' && w.severity === 'error'
        )
        if (missingRequiredWarnings.length > 0) {
            // Aggregate missing fields from all warnings
            const missingFields = new Set()
            missingRequiredWarnings.forEach(warning => {
                if (warning.affectedFields) {
                    warning.affectedFields.forEach(fieldKey => {
                        const field = phase3Manager.availableMetadataFields.find(f => f.key === fieldKey)
                        if (field) {
                            missingFields.add(field.label || fieldKey)
                        }
                    })
                }
            })
            if (missingFields.size > 0) {
                errors.push(`One or more required metadata fields are missing: ${Array.from(missingFields).join(', ')}.`)
            } else {
                errors.push('One or more required metadata fields are missing.')
            }
        }
        
        // Uploads still in progress or not backend-stable
        // Check that ALL uploads are backend-stable (backend session status must be 'completed')
        if (!allUploadsBackendStable && phase3Manager.items.length > 0) {
            const hasNonTerminalUploads = phase3Manager.items.some(item => 
                item.uploadStatus !== 'complete' && item.uploadStatus !== 'failed'
            )
            
            if (hasNonTerminalUploads) {
                errors.push('Waiting for all uploads to finish...')
            } else {
                // All uploads are complete but backend sessions may still be processing
                errors.push('Finalizing will unlock once uploads finish processing')
            }
        }
        
        // No completed uploads (only show if all uploads failed)
        if (phase3Manager.completedItems.length === 0 && phase3Manager.items.length > 0) {
            const allFailed = phase3Manager.items.every(item => item.uploadStatus === 'failed')
            if (allFailed) {
                errors.push('At least one upload must complete before finalizing.')
            }
        }
        
        // Finalize error (from previous finalize attempt)
        if (finalizeError) {
            // Only show user-friendly message, not technical errors
            const userFriendlyError = finalizeError.includes('Upload session ID missing') 
                ? 'One or more uploads are missing required information.'
                : finalizeError.includes('category')
                ? 'Please select a category before finalizing.'
                : finalizeError.includes('No completed uploads')
                ? 'At least one upload must complete before finalizing.'
                : finalizeError
            errors.push(userFriendlyError)
        }
        
        // Remove duplicates
        return Array.from(new Set(errors))
    }, [
        phase3Manager.context.categoryId,
        phase3Manager.warnings,
        phase3Manager.availableMetadataFields,
        phase3Manager.items,
        phase3Manager.completedItems.length,
        allUploadsBackendStable,
        finalizeError
    ])

    /**
     * CLEAN UPLOADER V2 — Handle category change callback
     * 
     * Updates selectedCategoryId state for finalization prerequisite.
     * Does NOT affect upload behavior.
     */
    const handleCategoryChangeV2 = useCallback((categoryId) => {
        console.log('[CATEGORY_V2] Category changed', { categoryId })
        setSelectedCategoryId(categoryId)
    }, [])

    /**
     * Handle category change callback (LEGACY - for Phase 3 manager)
     * Fetches metadata fields for the category (placeholder - should be implemented by parent)
     */
    const handleCategoryChange = useCallback((categoryId) => {
        if (!categoryId) {
            phase3Manager.setAvailableMetadataFields([])
            return
        }

        // TODO: In real implementation, fetch metadata fields from backend or category config
        // For now, using empty array - parent component should provide fields
        // This is a placeholder that can be extended
        const metadataFields = [] // Should be fetched from category configuration
        phase3Manager.changeCategory(categoryId, metadataFields)
    }, [phase3Manager])

    // Set initial category when dialog opens (if provided)
    useEffect(() => {
        if (open && initialCategoryId) {
            // Set category when dialog opens to prepopulate
            // Use empty metadata fields array for now (metadata fields should be fetched from category config)
            // Only set if not already set (avoid unnecessary updates)
            if (phase3Manager.context.categoryId !== initialCategoryId) {
                phase3Manager.changeCategory(initialCategoryId, [])
            }
        }
    }, [open, initialCategoryId]) // eslint-disable-line react-hooks/exhaustive-deps - phase3Manager is stable, don't include to avoid re-runs

    // Reset when dialog closes
    useEffect(() => {
        // TEMPORARILY COMMENTED FOR DIAGNOSTIC: if (!open) {
        //     // Note: We don't clear Phase 3 state on close to preserve uploads
        //     // If you want to clear, you'd need to add a reset method to the manager
        //     setIsDragging(false)
        // }
    }, [open])

    // DISABLED: batchStatus is now computed deterministically from v2Files using useMemo
    // This legacy effect is no longer needed since batchStatus is computed, not manually set
    // useEffect(() => {
    //     // ... legacy code disabled - batchStatus is computed from v2Files
    // }, [v2Files, batchStatus])

    /**
     * Phase 2.8: Sequential Upload Coordinator
     * 
     * Coordinates sequential file uploads:
     * - Only one file uploads at a time (direct or multipart)
     * - When a file finishes (uploaded/completed or failed), the next queued file starts automatically
     * - Finds files with status === 'selected' and starts them in order
     * - Checks both v2Files and UploadManager for active uploads
     */
    useEffect(() => {
        // Get all UploadManager uploads to check for active multipart uploads
        const allUploadManagerUploads = UploadManager.getUploads()
        const uploadManagerMap = new Map(
            allUploadManagerUploads.map(u => [u.clientReference, u])
        )
        
        // Check if any file is currently uploading (direct or multipart)
        const isUploading = v2Files.some((f) => {
            // Direct uploads: check v2Files status
            if (f.status === 'uploading') {
                return true
            }
            
            // Multipart uploads: check UploadManager status
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(f.clientId)
            if (uploadManagerClientRef) {
                const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
                if (uploadManagerUpload && 
                    (uploadManagerUpload.status === 'initiating' || 
                     uploadManagerUpload.status === 'uploading' || 
                     uploadManagerUpload.status === 'completing')) {
                    return true
                }
            }
            
            return false
        })
        
        if (isUploading) {
            // Already uploading - wait for current upload to finish
            return
        }
        
        // Find the next file with status === 'selected' to upload
        const nextFile = v2Files.find((f) => f.status === 'selected')
        
        if (!nextFile) {
            // No files waiting to upload
            return
        }
        
        // Guard: Ensure file still exists in v2Files before starting (handle removed files)
        const fileStillExists = v2Files.some((f) => f.clientId === nextFile.clientId && f.status === 'selected')
        if (!fileStillExists) {
            // File was removed - coordinator will pick up next file on next render
            return
        }
        
        console.log('[UPLOAD_COORDINATOR] Starting next upload', { 
            clientId: nextFile.clientId, 
            fileName: nextFile.file?.name || 'unknown',
            queuePosition: v2Files.filter((f) => f.status === 'selected').length
        })
        
        // Update status to 'uploading' before starting upload
        setV2Files((prevFiles) => {
            return prevFiles.map((f) => {
                if (f.clientId === nextFile.clientId) {
                    return {
                        ...f,
                        status: 'uploading',
                        progress: 0
                    }
                }
                return f
            })
        })
        
        // Start the upload
        uploadSingleFile(nextFile).catch((error) => {
            console.error('[UPLOAD_COORDINATOR] Upload failed', { 
                clientId: nextFile.clientId, 
                error: error.message 
            })
            // Error handling is done in uploadSingleFile (sets status to 'failed')
            // This effect will re-run and pick up the next file
        })
    }, [v2Files, uploadSingleFile, uploadManagerStateVersion]) // Phase 2.8: Include uploadManagerStateVersion to react to multipart upload completion

    /**
     * ═══════════════════════════════════════════════════════════════
     * CLEAN UPLOADER V2 — State Reset Function
     * ═══════════════════════════════════════════════════════════════
     * 
     * Resets v2Files and batchStatus to initial state.
     * Used when closing dialog to clean up state.
     */
    const resetV2State = useCallback(() => {
        setV2Files([])
        setSelectedCategoryId(initialCategoryId || null) // Reset to initial category
        setGlobalMetadataDraft({}) // Reset global metadata
        // FINAL FIX: Clear mapping when resetting state
        v2ToUploadManagerMapRef.current.clear()
        // Note: batchStatus is now computed from v2Files, will be 'idle' when v2Files is empty
    }, [initialCategoryId])

    /**
     * ═══════════════════════════════════════════════════════════════
     * CLEAN UPLOADER V2 — Auto-Close on Complete
     * ═══════════════════════════════════════════════════════════════
     * 
     * FINAL FIX: Moved to useEffect to avoid stale state.
     * Runs ONLY after React commits state updates.
     * 
     * Auto-closes dialog ONLY when:
     * - Dialog is open
     * - At least one file finalized
     * - No failed files
     * 
     * Delay: 400-700ms for perceived completion.
     * Does NOT auto-close on partial_success.
     */
    useEffect(() => {
        // Only run when dialog is open
        if (!open) {
            return
        }

        // FINAL FIX: Prevent double execution - only run once per dialog open cycle
        if (autoClosedRef.current) {
            return
        }

        // FINAL FIX: Use derived state directly from v2Files (not batchStatus)
        // This ensures we read committed state, not stale state
        const finalizedCount = v2Files.filter((f) => f.status === 'finalized').length
        const failedCount = v2Files.filter((f) => f.status === 'failed').length

        // CRITICAL: Reset auto-closed flag if there are failures (allow user to retry)
        if (failedCount > 0) {
            autoClosedRef.current = false
            return
        }

        if (finalizedCount === 0) {
            // Conditions not met - don't auto-close
            return
        }

        // FINAL FIX: Mark as auto-closed BEFORE setting timeout to prevent double execution
        autoClosedRef.current = true

        // Delay 400-700ms for perceived completion
        const delay = 400 + Math.random() * 300 // 400-700ms
        const timeoutId = setTimeout(() => {
            // Trigger refresh callback if provided (e.g., to refresh asset grid)
            if (onFinalizeComplete) {
                try {
                    onFinalizeComplete()
                } catch (error) {
                    console.error('[AUTO_CLOSE_V2] Error calling onFinalizeComplete:', error)
                }
            }
            
            resetV2State()
            onClose()
        }, delay)

        return () => {
            // CRITICAL FIX: Only clear timeout if we haven't already marked as auto-closed
            // If autoClosedRef is true, the timeout is about to fire - don't clear it
            if (!autoClosedRef.current) {
                clearTimeout(timeoutId)
            }
        }
    }, [v2Files, uploadManagerStateVersion, open, onClose, resetV2State, onFinalizeComplete])

    // CLEAN UPLOADER V2: Helper function to update title in v2Files
    const setTitleV2 = useCallback((clientId, newTitle) => {
        setV2Files((prevFiles) => 
            prevFiles.map((f) => 
                f.clientId === clientId 
                    ? { ...f, title: newTitle }
                    : f
            )
        )
    }, [])

    // CLEAN UPLOADER V2: Helper function to update resolvedFilename in v2Files (direct edit)
    const setResolvedFilenameV2 = useCallback((clientId, newResolvedFilename) => {
        setV2Files((prevFiles) => 
            prevFiles.map((f) => {
                if (f.clientId === clientId) {
                    return { ...f, resolvedFilename: newResolvedFilename }
                }
                return f
            })
        )
    }, [])

    // CLEAN UPLOADER V2: Map v2Files to UploadTray items format
    // FINAL FIX: For multipart uploads, read status/progress from UploadManager instead of v2Files
    // MUST be before conditional return to follow Rules of Hooks
    const v2UploadManager = useMemo(() => {
        // Get all UploadManager uploads for lookup
        // Depend on uploadManagerStateVersion to recalculate when UploadManager state changes
        const allUploadManagerUploads = UploadManager.getUploads()
        const uploadManagerMap = new Map(
            allUploadManagerUploads.map(u => [u.clientReference, u])
        )
        
        const items = v2Files.map((v2File) => {
            // FINAL FIX: Check if this v2File corresponds to a multipart upload in UploadManager
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(v2File.clientId)
            const uploadManagerUpload = uploadManagerClientRef ? uploadManagerMap.get(uploadManagerClientRef) : null
            
            // For multipart uploads, use UploadManager as source of truth
            if (uploadManagerUpload && uploadManagerUpload.uploadType === 'chunked') {
                // Phase 3.0: Enhanced status mapping with processing state
                // Map UploadManager status to UploadTray status
                let uploadStatus = 'queued'
                if (uploadManagerUpload.status === 'initiating') {
                    uploadStatus = 'uploading' // Show as uploading during init
                } else if (uploadManagerUpload.status === 'uploading') {
                    uploadStatus = 'uploading'
                } else if (uploadManagerUpload.status === 'completing') {
                    uploadStatus = 'processing' // Phase 3.0: Show as processing during completion
                } else if (uploadManagerUpload.status === 'completed') {
                    uploadStatus = 'complete'
                } else if (uploadManagerUpload.status === 'failed') {
                    uploadStatus = 'failed'
                } else if (uploadManagerUpload.status === 'cancelled') {
                    uploadStatus = 'failed' // Show cancelled as failed in UI
                    // Phase 2.8: Cancelled uploads are excluded from finalize gating
                }
                
                // Derive resolvedFilename if not set in v2File (use same logic as finalize)
                const fileName = v2File.file.name || 'unknown'
                const lastDotIndex = fileName.lastIndexOf('.')
                const extension = lastDotIndex > 0 ? fileName.substring(lastDotIndex + 1) : ''
                const baseName = lastDotIndex > 0 ? fileName.substring(0, lastDotIndex) : fileName
                const slugified = baseName.toLowerCase().trim()
                    .replace(/[^\w\s-]/g, '')
                    .replace(/[\s_-]+/g, '-')
                    .replace(/^-+|-+$/g, '')
                const defaultResolvedFilename = extension ? `${slugified}.${extension}` : slugified
                
                return {
                    clientId: v2File.clientId,
                    uploadStatus: uploadStatus,
                    progress: uploadManagerUpload.progress || 0,
                    originalFilename: v2File.file.name,
                    file: v2File.file,
                    title: v2File.title || null,
                    resolvedFilename: v2File.resolvedFilename || defaultResolvedFilename,
                    metadataDraft: v2File.metadataDraft || {},
                    error: uploadManagerUpload.error || uploadManagerUpload.errorInfo?.message || null,
                }
            }
            
            // For direct uploads, use v2Files state (legacy behavior)
            // Derive resolvedFilename if not set in v2File (use same logic as finalize)
            const fileName = v2File.file.name || 'unknown'
            const lastDotIndex = fileName.lastIndexOf('.')
            const extension = lastDotIndex > 0 ? fileName.substring(lastDotIndex + 1) : ''
            const baseName = lastDotIndex > 0 ? fileName.substring(0, lastDotIndex) : fileName
            const slugified = baseName.toLowerCase().trim()
                .replace(/[^\w\s-]/g, '')
                .replace(/[\s_-]+/g, '-')
                .replace(/^-+|-+$/g, '')
            const defaultResolvedFilename = extension ? `${slugified}.${extension}` : slugified
            
            // Phase 3.0: Enhanced status mapping for better UX feedback
            // Map finalizing to processing state for clearer visual feedback
            return {
                clientId: v2File.clientId,
                uploadStatus: v2File.status === 'selected' ? 'queued' : 
                             v2File.status === 'uploading' ? 'uploading' :
                             v2File.status === 'uploaded' ? 'complete' :
                             v2File.status === 'finalizing' ? 'processing' : // Phase 3.0: Show as processing during finalize
                             v2File.status === 'finalized' ? 'complete' :
                             v2File.status === 'failed' ? 'failed' : 'queued',
                progress: v2File.progress,
                originalFilename: v2File.file.name,
                file: v2File.file,
                title: v2File.title || null, // Use title from v2File if available
                resolvedFilename: v2File.resolvedFilename || defaultResolvedFilename,
                metadataDraft: v2File.metadataDraft || {}, // Per-file metadata overrides
                error: v2File.error || null, // Include error object for failed uploads
            }
        })
        
        return {
            hasItems: items.length > 0,
            items: items,
            // NO-OP methods for compatibility
            setUploadSessionId: () => {},
            updateUploadProgress: () => {},
            markUploadComplete: () => {},
            markUploadFailed: () => {},
            markUploadStarted: () => {},
            // Title method
            setTitle: setTitleV2,
            // Resolved filename method (direct edit)
            setResolvedFilename: setResolvedFilenameV2,
            // Metadata methods
            getEffectiveMetadata: (clientId) => getEffectiveMetadataV2(clientId),
            setGlobalMetadata: (fieldKey, value) => setGlobalMetadataV2(fieldKey, value),
            overrideItemMetadata: (clientId, fieldKey, value) => overrideItemMetadataV2(clientId, fieldKey, value),
            clearItemOverride: (clientId, fieldKey) => clearItemOverrideV2(clientId, fieldKey),
            // Category state for GlobalMetadataPanel compatibility
            context: {
                categoryId: selectedCategoryId,
            },
            changeCategory: (categoryId, metadataFields) => {
                // Update selectedCategoryId when category changes via GlobalMetadataPanel
                setSelectedCategoryId(categoryId)
            },
            // Metadata state
            globalMetadataDraft: globalMetadataDraft,
            availableMetadataFields: [], // TODO: Fetch from category config (for now empty)
            warnings: [], // No warnings for v2 (validation not implemented)
            validateMetadata: () => {}, // NO-OP - validation not implemented
        }
    }, [v2Files, uploadManagerStateVersion, selectedCategoryId, globalMetadataDraft]) // FINAL FIX: Include uploadManagerStateVersion to react to UploadManager changes

    // Phase 2.8: Sync completed multipart uploads to v2Files status
    // When a multipart upload completes in UploadManager, update v2File status to 'uploaded' for finalization
    useEffect(() => {
        const allUploadManagerUploads = UploadManager.getUploads()
        const uploadManagerMap = new Map(
            allUploadManagerUploads.map(u => [u.clientReference, u])
        )
        
        // Check each v2File for completed multipart uploads
        let hasChanges = false
        const updatedV2Files = v2Files.map((v2File) => {
            const uploadManagerClientRef = v2ToUploadManagerMapRef.current.get(v2File.clientId)
            if (!uploadManagerClientRef) {
                return v2File // Not a multipart upload
            }
            
            const uploadManagerUpload = uploadManagerMap.get(uploadManagerClientRef)
            if (!uploadManagerUpload || uploadManagerUpload.uploadType !== 'chunked') {
                return v2File // Not a multipart upload
            }
            
            // If UploadManager says completed but v2File is not 'uploaded', update it
            if (uploadManagerUpload.status === 'completed' && v2File.status !== 'uploaded') {
                hasChanges = true
                return {
                    ...v2File,
                    status: 'uploaded',
                    progress: 100,
                    uploadKey: uploadManagerUpload.uploadSessionId ? 
                        `temp/uploads/${uploadManagerUpload.uploadSessionId}/original` : 
                        v2File.uploadKey
                }
            }
            
            return v2File
        })
        
        if (hasChanges) {
            setV2Files(updatedV2Files)
        }
    }, [v2Files, uploadManagerStateVersion]) // Phase 2.8: React to UploadManager state changes

    const hasFiles = v2UploadManager.hasItems
    const hasUploadingItems = v2Files.some(f => f.status === 'uploading')



    return (
        <div
            data-upload-dialog-root
            className="fixed inset-0 z-50 overflow-y-auto"
            aria-labelledby="modal-title"
            role="dialog"
            aria-modal="true"
        >
            <div className="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {/* Background overlay */}
                <div
                    className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    onClick={!hasUploadingItems && batchStatus !== 'finalizing' ? onClose : undefined}
                />

                {/* Modal panel - wider for Phase 3 layout */}
                <div className="relative inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-4xl sm:align-middle">
                    <div className={`bg-white px-4 pt-5 pb-4 sm:p-6 relative ${isFinalizeSuccess ? 'opacity-20 pointer-events-none' : ''}`}>
                        {/* Header */}
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-lg font-medium leading-6 text-gray-900" id="modal-title">
                                Add {defaultAssetType === 'asset' ? 'Asset' : 'Marketing Asset'}
                            </h3>
                            {!hasUploadingItems && batchStatus !== 'finalizing' && (
                                <button
                                    type="button"
                                    className="text-gray-400 hover:text-gray-500"
                                    onClick={onClose}
                                    title="Close dialog"
                                >
                                    <XMarkIcon className="h-6 w-6" />
                                </button>
                            )}
                            {batchStatus === 'finalizing' && (
                                <button
                                    type="button"
                                    className="text-gray-300 cursor-not-allowed"
                                    disabled
                                    title="Finalizing uploads…"
                                >
                                    <XMarkIcon className="h-6 w-6" />
                                </button>
                            )}
                        </div>

                            {/* Asset Type - Hidden (used internally for filtering and finalization) */}

                        {/* File Drop Zone - always visible */}
                        {!hasFiles ? (
                            <div className="mb-4">
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Files
                                </label>
                                <div
                                    ref={dropZoneRef}
                                    onDragEnter={isFinalizeSuccess ? undefined : handleDragEnter}
                                    onDragLeave={isFinalizeSuccess ? undefined : handleDragLeave}
                                    onDragOver={isFinalizeSuccess ? undefined : handleDragOver}
                                    onDrop={isFinalizeSuccess ? undefined : handleDrop}
                                    className={`border-2 border-dashed rounded-lg text-center transition-colors p-6 ${
                                        isFinalizeSuccess
                                            ? 'border-gray-200 bg-gray-50 cursor-not-allowed opacity-50'
                                            : isDragging
                                                ? 'border-indigo-500 bg-indigo-50 cursor-pointer'
                                                : 'border-gray-300 hover:border-gray-400 cursor-pointer'
                                    }`}
                                    onClick={isFinalizeSuccess ? undefined : () => fileInputRef.current?.click()}
                                >
                                    <CloudArrowUpIcon className="mx-auto h-12 w-12 text-gray-400" />
                                    <p className="mt-2 text-sm text-gray-600">
                                        {isDragging
                                            ? 'Drop files here'
                                            : 'Drag and drop files here, or click to select'}
                                    </p>
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        multiple
                                        className="hidden"
                                        onChange={(e) => handleFileSelect(e.target.files)}
                                        disabled={isFinalizeSuccess}
                                    />
                                </div>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {/* Phase 3 Components - show when files exist */}
                                {/* Global Metadata Panel */}
                                <GlobalMetadataPanel
                                    uploadManager={phase3Manager}
                                    categories={filteredCategories}
                                    onCategoryChange={handleCategoryChange}
                                    disabled={batchStatus === 'finalizing'}
                                />

                                {/* Compact Drop Zone - above uploads list, always visible */}
                                <div className="mb-4">
                                <div
                                    ref={dropZoneRef}
                                    onDragEnter={isFinalizeSuccess ? undefined : handleDragEnter}
                                    onDragLeave={isFinalizeSuccess ? undefined : handleDragLeave}
                                    onDragOver={isFinalizeSuccess ? undefined : handleDragOver}
                                    onDrop={isFinalizeSuccess ? undefined : handleDrop}
                                    className={`border-2 border-dashed rounded-lg text-center transition-colors p-3 border-gray-300 bg-gray-50 ${
                                        isFinalizeSuccess
                                            ? 'cursor-not-allowed opacity-50'
                                            : isDragging
                                                ? 'border-indigo-400 bg-indigo-50 cursor-pointer hover:bg-gray-100'
                                                : 'cursor-pointer hover:bg-gray-100'
                                    }`}
                                    onClick={isFinalizeSuccess ? undefined : () => fileInputRef.current?.click()}
                                >
                                        <p className="text-sm text-gray-600">
                                            {isDragging
                                                ? 'Drop files here'
                                                : 'Add more files (drag & drop or click)'}
                                        </p>
                                        <input
                                            ref={fileInputRef}
                                            type="file"
                                            multiple
                                            className="hidden"
                                            onChange={(e) => handleFileSelect(e.target.files)}
                                            disabled={isFinalizeSuccess}
                                        />
                                                </div>
                                            </div>

                                {/* CLEAN UPLOADER V2 — Category Selector (Finalize Prerequisite) */}
                                <GlobalMetadataPanel
                                    uploadManager={v2UploadManager}
                                    categories={filteredCategories}
                                    onCategoryChange={handleCategoryChangeV2}
                                    disabled={batchStatus === 'finalizing' || isFinalizeSuccess}
                                />

                                {/* Upload Tray */}
                                {/* CLEAN UPLOADER V2: UploadTray now uses v2Files state */}
                                <UploadTray 
                                    uploadManager={v2UploadManager}
                                    onRemoveItem={(clientId) => {
                                        // Remove file from v2Files state
                                        removeFile(clientId)
                                    }}
                                    disabled={batchStatus === 'finalizing' || isFinalizeSuccess}
                                />

                                {/* Phase 2.5 Step 3: Dev-only diagnostics panel */}
                                {/* Read-only panel showing normalized errors and upload session info */}
                                <DevUploadDiagnostics files={v2Files} />
                                    </div>
                                )}


                        {/* Footer Actions */}
                        <div className="mt-6">
                            {/* CLEAN UPLOADER V2 — Warnings for missing required fields */}
                            {v2Files.length > 0 && v2Warnings.length > 0 && (
                                <div className="mb-4 rounded-md bg-yellow-50 border border-yellow-200 p-3">
                                    <div className="flex">
                                        <div className="flex-shrink-0">
                                            <svg className="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                            </svg>
                                        </div>
                                        <div className="ml-3">
                                            <div className="text-sm text-yellow-800">
                                                {v2Warnings.map((warning, index) => (
                                                    <p key={index}>{warning}</p>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* CLEAN UPLOADER V2 — Partial success banner */}
                            {batchStatus === 'partial_success' && (
                                <div className="mb-4 rounded-md bg-yellow-50 border border-yellow-200 p-3">
                                    <div className="flex">
                                        <div className="flex-shrink-0">
                                            <svg className="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                            </svg>
                                        </div>
                                        <div className="ml-3">
                                            <p className="text-sm text-yellow-800">
                                                {(() => {
                                                    const finalizedCount = v2Files.filter((f) => f.status === 'finalized').length
                                                    const failedCount = v2Files.filter((f) => f.status === 'failed').length
                                                    return (
                                                        <>
                                                            {finalizedCount} asset{finalizedCount !== 1 ? 's' : ''} created successfully. {failedCount} failed — review and retry.
                                                        </>
                                                    )
                                                })()}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <div className="flex items-center justify-end gap-3">
                                {/* Status indicator - Show v2 counter when v2Files exist, otherwise legacy */}
                                {v2Files.length > 0 ? (
                                    <div className="flex-1 text-sm text-gray-600">
                                        {v2Files.length} / {v2Files.length} upload{v2Files.length !== 1 ? 's' : ''}
                                    </div>
                                ) : files.length > 0 ? (
                                    <div className="flex-1 text-sm text-gray-600">
                                        {(() => {
                                            const completedCount = phase3Manager.completedItems.length
                                            const totalCount = phase3Manager.items.length
                                            const allComplete = completedCount === totalCount && totalCount > 0
                                            
                                            if (allComplete && !allUploadsBackendStable) {
                                                return (
                                                    <span className="text-gray-500">
                                                        Finalizing uploads... ({completedCount} / {totalCount})
                                                    </span>
                                                )
                                            }
                                            
                                            if (allComplete && allUploadsBackendStable) {
                                                return (
                                                    <span className="text-green-600 font-medium">
                                                        Ready to finalize ({completedCount} / {totalCount})
                                                    </span>
                                                )
                                            }
                                            
                                            return (
                                                <span>
                                                    {completedCount} / {totalCount} complete
                                                </span>
                                            )
                                        })()}
                                    </div>
                                ) : null}
                                
                                {(() => {
                                    const disableClose = batchStatus === 'finalizing' || isFinalizeSuccess
                                    
                                    return (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                resetV2State()
                                                onClose()
                                            }}
                                            disabled={disableClose}
                                            className={`rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium ${
                                                disableClose
                                                    ? 'text-gray-400 cursor-not-allowed opacity-50'
                                                    : 'text-gray-700 hover:bg-gray-50'
                                            }`}
                                            title={disableClose ? (isFinalizeSuccess ? 'Assets created successfully. Dialog closing...' : 'Finalizing uploads…') : undefined}
                                        >
                                            {hasFiles ? 'Close' : 'Cancel'}
                                        </button>
                                    )
                                })()}
                                
                                {/* CLEAN UPLOADER V2 — Finalize Assets Button */}
                                {v2Files.length > 0 && (
                                <button
                                        type="button"
                                        onClick={handleFinalizeV2}
                                        disabled={!canFinalizeV2 || batchStatus === 'finalizing' || isFinalizeSuccess}
                                        className={`rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2 ${
                                            canFinalizeV2 && batchStatus !== 'finalizing' && !isFinalizeSuccess
                                                ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                                                : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                                        }`}
                                        title={!selectedCategoryId ? 'Select a category to finalize uploads' : undefined}
                                    >
                                        {batchStatus === 'finalizing' && (
                                            <ArrowPathIcon className="h-4 w-4 animate-spin" />
                                        )}
                                        {finalizeButtonLabelV2}
                                </button>
                                )}
                            </div>
                            
                            {/* Error message (only show if not already in blockingErrors) */}
                            {finalizeError && !blockingErrors.some(e => finalizeError.includes(e) || e.includes(finalizeError)) && (
                                <div className="mt-2 text-right text-sm text-red-600">
                                    {finalizeError}
                                </div>
                            )}
                        </div>
                    </div>
                    
                    {/* Success overlay - shown after finalize succeeds, before auto-close */}
                    {isFinalizeSuccess && (
                        <div className="absolute inset-0 bg-white bg-opacity-95 flex items-center justify-center z-50 rounded-lg">
                            <div className="text-center">
                                <CheckCircleIcon className="mx-auto h-16 w-16 text-green-500 mb-4" />
                                <h3 className="text-lg font-semibold text-gray-900 mb-2">
                                    Assets created successfully!
                                </h3>
                                <p className="text-sm text-gray-600">
                                    The dialog will close automatically...
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    )
}
