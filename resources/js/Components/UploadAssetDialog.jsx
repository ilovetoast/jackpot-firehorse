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
import { XMarkIcon, CloudArrowUpIcon, ArrowPathIcon } from '@heroicons/react/24/outline'
import { usePhase3UploadManager } from '../hooks/usePhase3UploadManager'
import GlobalMetadataPanel from './GlobalMetadataPanel'
import UploadTray from './UploadTray'
import UploadManager from '../utils/UploadManager' // Phase 2 singleton - import directly

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
 */
export default function UploadAssetDialog({ open, onClose, defaultAssetType = 'asset', categories = [], initialCategoryId = null }) {
    const { auth } = usePage().props
    const [isDragging, setIsDragging] = useState(false)
    const fileInputRef = useRef(null)
    const dropZoneRef = useRef(null)

    // Initialize Phase 3 Upload Manager with initial category if provided
    const uploadContext = {
        companyId: auth.activeCompany?.id || auth.companies?.[0]?.id || null,
        brandId: auth.activeBrand?.id || null,
        categoryId: initialCategoryId || null // Prepopulate if provided, otherwise will be set via GlobalMetadataPanel
    }

    const phase3Manager = usePhase3UploadManager(uploadContext)
    
    // Phase 2 UploadManager - use singleton directly to avoid rehydration
    // We don't use useUploadManager hook because it auto-rehydrates, which causes
    // 400 errors for uploads that don't exist or have invalid UUIDs in the modal context
    const phase2ManagerRef = useRef(UploadManager)
    
    // Track currently adding clientIds to prevent subscription filter from removing them
    const currentlyAddingRef = useRef(new Set())
    
    // Track latest phase3Manager in a ref so subscription callback can access it
    // This avoids closure issues and infinite loops from dependency arrays
    const phase3ManagerRef = useRef(phase3Manager)
    useEffect(() => {
        // Only update ref if phase3Manager actually changed (by comparing items length or identity)
        // This prevents unnecessary updates that could trigger re-renders
        if (phase3ManagerRef.current !== phase3Manager) {
            phase3ManagerRef.current = phase3Manager
        }
    }, [phase3Manager])
    
    // Track Phase 2 uploads via subscription instead of hook state
    const [phase2Uploads, setPhase2Uploads] = useState(() => {
        // Initial state - filter to only current Phase 3 items (but phase3Manager might not be ready yet)
        // So start empty, subscription will populate it
        return []
    })
    
    // Subscribe to Phase 2 updates (without triggering rehydration)
    // CRITICAL: This subscription updates phase2Uploads state, which triggers the sync effect below
    // Run only once on mount - use refs to access latest values in subscription callback
    useEffect(() => {
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
    
    // Clean up old uploads when dialog opens
    // Remove any uploads without files (rehydrated old uploads) to prevent conflicts
    // CRITICAL: Do NOT remove completed Phase 2 uploads - they're needed for finalization stability check
    useEffect(() => {
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
                console.log('[Dialog Open] Removing old rehydrated uploads', {
                    count: uploadsToRemove.length,
                    uploads: uploadsToRemove.map(([key, upload]) => ({
                        key,
                        clientReference: upload.clientReference,
                        status: upload.status,
                        hasFile: !!upload.file,
                        uploadSessionId: upload.uploadSessionId
                    }))
                })
                
                uploadsToRemove.forEach(([key, upload]) => {
                    phase2Manager.removeUpload(key)
                })
            }
        } else {
            // Clear currently adding ref when dialog closes
            currentlyAddingRef.current.clear()
        }
    }, [open])

    // Filter categories by asset type
    const filteredCategories = (categories || []).filter(cat => {
        if (defaultAssetType === 'asset') {
            return cat.asset_type === 'asset' || cat.asset_type === 'basic'
        } else {
            return cat.asset_type === 'marketing'
        }
    })

    /**
     * Handle file selection/drop
     * 
     * Phase 3 owns the UUID. Phase 2 must use Phase 3's clientId as client_reference.
     * We work around Phase 2's UUID generation by directly adding to Phase 2's uploads map.
     */
    const handleFileSelect = useCallback((files) => {
        if (!files || files.length === 0) return

        const fileArray = Array.from(files)
        
        // Add files to Phase 3 manager first (Phase 3 owns UUID generation)
        const clientIds = phase3Manager.addFiles(fileArray)
        
        // Track these clientIds as currently adding (prevents subscription filter from removing them)
        clientIds.forEach(clientId => currentlyAddingRef.current.add(clientId))

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
     * Sync Phase 2 upload progress to Phase 3 state
     * 
     * CRITICAL: Use useRef to track last synced values to prevent infinite loops.
     * Only sync when values actually change, not on every render.
     * Only sync uploads that belong to Phase 3 items (by clientId match).
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
    
    useEffect(() => {
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
        if (!open) {
            lastSyncedRef.current.clear()
        }
    }, [open])

    /**
     * Slot-based queue advancement
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

    useEffect(() => {
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
        if (!open) {
            startingUploadsRef.current.clear()
            currentlyAddingRef.current.clear()
        }
    }, [open])

    /**
     * Finalize Assets
     * 
     * Finalizes all completed uploads by calling the backend endpoint.
     * Sends upload_session_id and asset_type for each completed upload.
     */
    const [isFinalizing, setIsFinalizing] = useState(false)
    const [finalizeError, setFinalizeError] = useState(null)

    const handleFinalize = useCallback(async () => {
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

    // Check if finalize button should be enabled
    // CRITICAL: Check if S3 uploads are actually complete (uploaded_size >= expected_size)
    // Phase 2 completion only means frontend thinks S3 finished - verify with backend
    // Track backend session status (uploaded_size, expected_size) for each upload session
    // Maps uploadSessionId -> { uploaded_size: number, expected_size: number }
    const [backendUploadSizes, setBackendUploadSizes] = useState(new Map())
    
    // Force re-render to trigger status checks
    const [sizeCheckTick, setSizeCheckTick] = useState(0)
    
    // Track which sessions are currently being checked (prevent duplicate requests)
    const checkingSessionsRef = useRef(new Set())
    
    // Check backend upload sizes for completed items
    useEffect(() => {
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
    
    // Check if button should be enabled (all complete AND backend-stable)
    const canFinalize = phase3Manager.canFinalize && 
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
     * Handle category change callback
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
        if (!open) {
            // Note: We don't clear Phase 3 state on close to preserve uploads
            // If you want to clear, you'd need to add a reset method to the manager
            setIsDragging(false)
        }
    }, [open])

    if (!open) return null

    const hasFiles = phase3Manager.hasItems
    const hasUploadingItems = phase3Manager.uploadingItems.length > 0

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div className="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {/* Background overlay */}
                <div
                    className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    onClick={!hasUploadingItems ? onClose : undefined}
                />

                {/* Modal panel - wider for Phase 3 layout */}
                <div className="relative inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-4xl sm:align-middle">
                    <div className="bg-white px-4 pt-5 pb-4 sm:p-6">
                        {/* Header */}
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-lg font-medium leading-6 text-gray-900" id="modal-title">
                                Add {defaultAssetType === 'asset' ? 'Asset' : 'Marketing Asset'}
                            </h3>
                            {!hasUploadingItems && (
                                <button
                                    type="button"
                                    className="text-gray-400 hover:text-gray-500"
                                    onClick={onClose}
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
                                    onDragEnter={handleDragEnter}
                                    onDragLeave={handleDragLeave}
                                    onDragOver={handleDragOver}
                                    onDrop={handleDrop}
                                    className={`border-2 border-dashed rounded-lg text-center cursor-pointer transition-colors p-6 ${
                                        isDragging
                                            ? 'border-indigo-500 bg-indigo-50'
                                            : 'border-gray-300 hover:border-gray-400'
                                    }`}
                                    onClick={() => fileInputRef.current?.click()}
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
                                />

                                {/* Compact Drop Zone - above uploads list, always visible */}
                                <div className="mb-4">
                                    <div
                                        ref={dropZoneRef}
                                        onDragEnter={handleDragEnter}
                                        onDragLeave={handleDragLeave}
                                        onDragOver={handleDragOver}
                                        onDrop={handleDrop}
                                        className={`border-2 border-dashed rounded-lg text-center cursor-pointer transition-colors p-3 border-gray-300 bg-gray-50 hover:bg-gray-100 ${
                                            isDragging ? 'border-indigo-400 bg-indigo-50' : ''
                                        }`}
                                        onClick={() => fileInputRef.current?.click()}
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
                                        />
                                                </div>
                                            </div>

                                {/* Upload Tray */}
                                <UploadTray 
                                    uploadManager={phase3Manager}
                                    onRemoveItem={(clientId) => {
                                        // Remove from Phase 3 state
                                        phase3Manager.removeItem(clientId);
                                        
                                        // Remove from Phase 2 state
                                        const phase2Manager = phase2ManagerRef.current;
                                        phase2Manager.removeUpload(clientId);
                                    }}
                                />
                                    </div>
                                )}


                        {/* Footer Actions */}
                        <div className="mt-6">
                            <div className="flex items-center justify-end gap-3">
                                {/* Status indicator */}
                                {hasFiles && (
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
                                )}
                                
                                {!hasUploadingItems && (
                                    <button
                                        type="button"
                                        onClick={onClose}
                                        className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                    >
                                        {hasFiles ? 'Close' : 'Cancel'}
                                    </button>
                                )}
                                
                                {/* Finalize Assets Button */}
                                {hasFiles && (
                                <button
                                        type="button"
                                        onClick={handleFinalize}
                                        disabled={!canFinalize || isFinalizing}
                                        className={`rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2 ${
                                            canFinalize && !isFinalizing
                                                ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                                                : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                                        }`}
                                    >
                                        {isWaitingForStability && (
                                            <ArrowPathIcon className="h-4 w-4 animate-spin" />
                                        )}
                                        {isFinalizing ? 'Finalizing...' : isWaitingForStability ? 'Finalizing uploads...' : 'Finalize Assets'}
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
                </div>
            </div>
        </div>
    )
}
