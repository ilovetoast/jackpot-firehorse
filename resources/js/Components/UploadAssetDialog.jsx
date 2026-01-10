// Phase 3.4 — Upload Asset Dialog with Phase 3 Upload Manager
// Replaces legacy Phase 2 verification UI

import { useState, useCallback, useRef, useEffect, useMemo } from 'react'
import { usePage, router } from '@inertiajs/react'
import { XMarkIcon, CloudArrowUpIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'
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
 */
export default function UploadAssetDialog({ open, onClose, defaultAssetType = 'asset', categories = [] }) {
    const { auth } = usePage().props
    const [isDragging, setIsDragging] = useState(false)
    const fileInputRef = useRef(null)
    const dropZoneRef = useRef(null)

    // Initialize Phase 3 Upload Manager
    const uploadContext = {
        companyId: auth.activeCompany?.id || auth.companies?.[0]?.id || null,
        brandId: auth.activeBrand?.id || null,
        categoryId: null // Will be set via GlobalMetadataPanel
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
            // This prevents the filter from removing uploads that just started
            // (the upload might have started before Phase 3 items state updated)
            const filteredUploads = allUploads.filter(upload => {
                const belongsToPhase3 = phase3ClientIds.has(upload.clientReference)
                const isCurrentlyAdding = currentlyAddingIds.has(upload.clientReference)
                const isActivelyUploading = upload.status === 'uploading' || upload.status === 'initiating'
                
                // Include if it belongs to Phase 3, is being added, OR is actively uploading
                // This ensures active uploads are never filtered out
                const include = belongsToPhase3 || isCurrentlyAdding || isActivelyUploading
                
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
    useEffect(() => {
        if (open) {
            const phase2Manager = phase2ManagerRef.current
            const allUploads = Array.from(phase2Manager.uploads.entries())
            const currentPhase3Items = phase3ManagerRef.current.items
            const phase3ClientIds = new Set(currentPhase3Items.map(item => item.clientId))
            
            // Remove uploads that:
            // 1. Don't have a file attached (old rehydrated uploads)
            // 2. Don't belong to current Phase 3 items
            // 3. Are not currently being added
            const uploadsToRemove = allUploads.filter(([key, upload]) => {
                const belongsToPhase3 = phase3ClientIds.has(upload.clientReference)
                const isCurrentlyAdding = currentlyAddingRef.current.has(upload.clientReference)
                const hasNoFile = !upload.file
                
                // Remove if it doesn't belong to Phase 3 AND has no file (old rehydrated upload)
                return !belongsToPhase3 && !isCurrentlyAdding && hasNoFile
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
            phase2Manager.persistToStorage()
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
        
        if (phase2UploadsWithNewIds.length !== newClientIdsSet.size) {
            console.error('[FileSelect] Phase 2 uploads missing or incorrect clientReference!', {
                expectedCount: newClientIdsSet.size,
                actualCount: phase2UploadsWithNewIds.length,
                newClientIds: Array.from(newClientIdsSet),
                phase2UploadClientRefs: phase2UploadsWithNewIds.map(u => u.clientReference),
                allUploadClientRefs: allUploads.map(u => u.clientReference).slice(0, 10)
            })
        }
        
        console.log('[FileSelect] Added files and updated phase2Uploads', {
            filesCount: fileArray.length,
            clientIds,
            existingPhase3ClientIds: Array.from(existingPhase3ClientIds),
            newClientIds: Array.from(newClientIdsSet),
            allPhase3ClientIds: Array.from(allPhase3ClientIds),
            allUploadsCount: allUploads.length,
            filteredUploadsCount: filteredUploads.length,
            newlyAddedUploadsCount: newlyAddedUploads.length,
            phase3ItemsCount: currentPhase3Items.length,
            phase3ItemsClientIds: currentPhase3Items.map(i => i.clientId),
            allUploadClientRefs: allUploads.map(u => u.clientReference).slice(0, 10),
            filteredUploadClientRefs: filteredUploads.map(u => u.clientReference),
            newlyAddedClientRefs: newlyAddedUploads.map(u => u.clientReference)
        })
        
        // Set state with filtered uploads (ensures new uploads are included, excludes old uploads)
        setPhase2Uploads(filteredUploads)
        
        // DO NOT clear currentlyAddingRef yet - keep it until uploads are confirmed in Phase 3 items
        // The subscription filter needs this to include newly added uploads until state updates
        // We'll clear it in the next effect cycle after Phase 3 items are confirmed updated
        
        // Notify listeners after state update
        // This triggers the subscription, which will update state again (but should be idempotent)
        phase2Manager.notifyListeners()
        
        // Clear currently adding ref after React state update cycle
        // Use requestAnimationFrame to wait for React to process state updates
        // This ensures phase3Manager.items is updated before we clear the ref
        // CRITICAL: Only clear if all uploads are in Phase 3 items AND they're not actively uploading
        // Active uploads should stay in the ref to prevent them from being filtered out
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                // After two animation frames, React state should be updated
                const currentPhase3Items = phase3Manager.items
                const phase3ClientIds = new Set(currentPhase3Items.map(item => item.clientId))
                const currentlyAddingIds = Array.from(currentlyAddingRef.current)
                
                // Check if all currently adding uploads are in Phase 3 items
                const allAreInPhase3 = currentlyAddingIds.every(id => phase3ClientIds.has(id))
                
                // Check if any are actively uploading - if so, don't clear yet
                // This prevents the subscription filter from removing active uploads
                const allManagerUploads = Array.from(phase2ManagerRef.current.getUploads())
                const hasActiveUploads = currentlyAddingIds.some(id => {
                    const upload = allManagerUploads.find(u => u.clientReference === id)
                    return upload && (upload.status === 'uploading' || upload.status === 'initiating')
                })
                
                if (allAreInPhase3 && !hasActiveUploads && currentlyAddingIds.length > 0) {
                    console.log('[FileSelect] Clearing currentlyAddingRef - all uploads are in Phase 3 items and none are actively uploading')
                    currentlyAddingRef.current.clear()
                } else if (hasActiveUploads) {
                    console.log('[FileSelect] Keeping currentlyAddingRef - some uploads are actively uploading', {
                        currentlyAdding: currentlyAddingIds,
                        hasActiveUploads
                    })
                }
                // If not all are in Phase 3 items yet, keep the ref - will retry in next effect cycle
                // The subscription filter will continue to include them via currentlyAddingRef
            })
        })
        
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

    // Store stable references to Phase 3 manager methods
    const { 
        items: phase3Items,
        setUploadSessionId,
        updateUploadProgress,
        markUploadComplete,
        markUploadFailed,
        markUploadStarted
    } = phase3Manager

    useEffect(() => {
        // Guard: Only sync Phase 2 uploads that belong to Phase 3 items
        // Phase 2's clientReference should match Phase 3's clientId (we set this in handleFileSelect)
        
        console.log('[Sync] Effect running', {
            phase2UploadsCount: phase2Uploads.length,
            phase3ItemsCount: phase3Items.length,
            phase2Uploads: phase2Uploads.map(u => ({ clientRef: u.clientReference, status: u.status, progress: u.progress })),
            phase3Items: phase3Items.map(i => ({ clientId: i.clientId, status: i.uploadStatus }))
        })
        
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
            
            // Guard: Only update if something actually changed
            const hasProgressChanged = phase2Upload.progress !== lastSynced.progress
            const hasSessionIdChanged = phase2Upload.uploadSessionId !== lastSynced.uploadSessionId
            const hasStatusChanged = phase2Upload.status !== lastSynced.status
            
            // Debug: Only log if something actually changed to reduce console noise
            if (hasProgressChanged || hasSessionIdChanged || hasStatusChanged || 
                phase2Upload.status === 'uploading' || phase2Upload.status === 'initiating') {
                console.log('[Sync] Checking upload', {
                    clientId,
                    phase2Status: phase2Upload.status,
                    phase3Status: phase3Item.uploadStatus,
                    phase2Progress: phase2Upload.progress,
                    phase3Progress: phase3Item.progress,
                    lastSyncedStatus: lastSynced.status,
                    hasProgressChanged,
                    hasSessionIdChanged,
                    hasStatusChanged
                })
            }
            
            // Additional guard: Check if Phase 3 item actually needs updating
            // CRITICAL: Always update progress if it's different from Phase 3 (not just from lastSynced)
            // This ensures progress bar updates in real-time during upload
            // Compare directly to Phase 3 progress, not just lastSynced, to handle stale lastSyncedRef
            const needsProgressUpdate = phase2Upload.progress !== phase3Item.progress && 
                                       phase2Upload.progress !== undefined && 
                                       phase2Upload.progress !== null
            const needsSessionUpdate = hasSessionIdChanged && phase2Upload.uploadSessionId && !phase3Item.uploadSessionId
            // CRITICAL: Check status changes based on current Phase 3 status, not just if status changed
            // This handles cases where Phase 3 is out of sync (e.g., 'uploading' when Phase 2 is 'failed')
            const needsStatusUpdate = (
                (phase2Upload.status === 'completed' && phase3Item.uploadStatus !== 'complete') ||
                (phase2Upload.status === 'failed' && phase3Item.uploadStatus !== 'failed') ||
                ((phase2Upload.status === 'uploading' || phase2Upload.status === 'initiating') && 
                 phase3Item.uploadStatus !== 'uploading' && phase3Item.uploadStatus !== 'complete' && phase3Item.uploadStatus !== 'failed') ||
                (phase2Upload.status === 'pending' && phase3Item.uploadStatus === 'uploading' && !phase2Upload.uploadSessionId) // Revert if upload was reset
            )
            
            if (!needsProgressUpdate && !needsSessionUpdate && !needsStatusUpdate) {
                // Nothing changed - skip update to prevent infinite loop
                // Still update lastSyncedRef to track current state
                lastSyncedRef.current.set(clientId, {
                    progress: phase2Upload.progress,
                    uploadSessionId: phase2Upload.uploadSessionId,
                    status: phase2Upload.status
                })
                return
            }

            // Update session ID if needed (always do this first)
            if (needsSessionUpdate) {
                setUploadSessionId(clientId, phase2Upload.uploadSessionId)
            }

            // Update progress if needed (always update if different from Phase 3, regardless of lastSynced)
            // This ensures progress bar updates in real-time during upload
            // CRITICAL: Update progress even if lastSynced hasn't changed, as long as Phase 3 progress is stale
            if (needsProgressUpdate) {
                console.log('[Sync] Updating progress', {
                    clientId,
                    phase2Progress: phase2Upload.progress,
                    phase3Progress: phase3Item.progress,
                    lastSyncedProgress: lastSynced.progress
                })
                updateUploadProgress(clientId, phase2Upload.progress)
            }

            // Update status if needed (always sync Phase 3 status to Phase 2 status)
            if (needsStatusUpdate) {
                if (phase2Upload.status === 'completed' && phase3Item.uploadStatus !== 'complete') {
                    console.log('[Sync] Marking upload complete', { clientId, phase2Status: phase2Upload.status, phase3Status: phase3Item.uploadStatus })
                    markUploadComplete(clientId, phase2Upload.uploadSessionId || phase3Item.uploadSessionId)
                } else if (phase2Upload.status === 'failed' && phase3Item.uploadStatus !== 'failed') {
                    console.log('[Sync] Marking upload failed', { clientId, phase2Status: phase2Upload.status, phase3Status: phase3Item.uploadStatus, error: phase2Upload.error })
                    markUploadFailed(clientId, {
                        message: phase2Upload.error || phase2Upload.errorInfo?.message || 'Upload failed',
                        type: phase2Upload.errorInfo?.type || 'unknown',
                        httpStatus: phase2Upload.errorInfo?.http_status,
                        rawError: phase2Upload.errorInfo?.raw_error
                    })
                } else if ((phase2Upload.status === 'uploading' || phase2Upload.status === 'initiating') && 
                           phase3Item.uploadStatus !== 'uploading' && phase3Item.uploadStatus !== 'complete' && phase3Item.uploadStatus !== 'failed') {
                    // Update to uploading status - do this even if uploadSessionId doesn't exist yet
                    // The status change happens immediately when startUpload is called
                    console.log('[Sync] Updating Phase 3 status to uploading', {
                        clientId,
                        phase2Status: phase2Upload.status,
                        phase3Status: phase3Item.uploadStatus,
                        uploadSessionId: phase2Upload.uploadSessionId
                    })
                    
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
                    console.log('[Sync] Upload reset - reverting Phase 3 status to queued', { clientId })
                    // Don't need to do anything - Phase 3 should already be queued
                }
            }

            // Update last synced state AFTER all updates are complete
            // This ensures we track the final state after all updates
            lastSyncedRef.current.set(clientId, {
                progress: phase2Upload.progress,
                uploadSessionId: phase2Upload.uploadSessionId,
                status: phase2Upload.status
            })
        })
    }, [phase2Uploads, phase3Items, setUploadSessionId, updateUploadProgress, markUploadComplete, markUploadFailed, markUploadStarted])
    
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
    
    // Track queued items count to ensure effect runs when it changes
    const queuedItemsCount = phase3Items.filter(item => item.uploadStatus === 'queued').length
    const activeUploadsCount = phase2Uploads.filter(upload => 
        upload.status === 'uploading' || upload.status === 'initiating'
    ).length

    useEffect(() => {
        const phase2Manager = phase2ManagerRef.current
        
        console.log('[Auto-start] Effect running', {
            phase3ItemsCount: phase3Items.length,
            phase2UploadsCount: phase2Uploads.length,
            queuedItemsCount,
            activeUploadsCount,
            phase3Items: phase3Items.map(i => ({ clientId: i.clientId, status: i.uploadStatus }))
        })
        
        // Get queued Phase 3 items (only items that can be started)
        // Always pick the oldest queued item first (array order is chronological)
        // phase3Items comes from phase3Manager.items, which is updated when files are added
        // The effect runs when phase3Items changes (via queuedItemsCount dependency)
        const queuedItems = phase3Items.filter(item => item.uploadStatus === 'queued')
        
        console.log('[Auto-start] Queued items found', {
            queuedItemsCount: queuedItems.length,
            queuedItems: queuedItems.map(i => ({ clientId: i.clientId, status: i.uploadStatus }))
        })
        
        if (queuedItems.length === 0) {
            console.log('[Auto-start] No queued items - exiting')
            return // No queued items - nothing to start
        }

        // Count currently active uploads
        // ONLY count uploads as active if status is 'uploading' or 'initiating'
        // DO NOT count: 'queued', 'complete', 'failed'
        const activeUploads = phase2Uploads.filter(upload => 
            upload.status === 'uploading' || upload.status === 'initiating'
        )
        
        // CRITICAL: Only count Phase 2 uploads with active status
        // Phase 3 status can be out of sync (e.g., 'uploading' when Phase 2 is 'failed'),
        // so we should NOT use Phase 3 status for slot calculation
        // Only 'uploading' and 'initiating' count as active
        // 'failed', 'completed', 'pending', 'paused' are NOT active and should free slots
        const activeCount = activeUploads.length
        
        // CANONICAL RULE: Calculate available slots
        // availableSlots = MAX_CONCURRENT_UPLOADS - activeUploads
        const availableSlots = MAX_CONCURRENT_UPLOADS - activeCount
        
        // CANONICAL RULE: If activeUploads < MAX_CONCURRENT_UPLOADS AND queuedUploads > 0
        // → start next queued upload
        console.log('[Auto-start] Slot calculation', {
            activeCount,
            availableSlots,
            MAX_CONCURRENT_UPLOADS,
            activeUploads: activeUploads.map(u => ({ clientRef: u.clientReference, status: u.status })),
            totalPhase2Uploads: phase2Uploads.length,
            phase2Statuses: phase2Uploads.map(u => ({ clientRef: u.clientReference, status: u.status }))
        })
        
        if (availableSlots <= 0) {
            console.log('[Auto-start] No available slots - queue is full')
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
            
            // Verify this upload belongs to current Phase 3 items (not an old upload)
            // Also check currentlyAddingRef for newly added uploads that might not be in state yet
            const belongsToPhase3 = phase3Items.some(item => item.clientId === clientId)
            const isCurrentlyAdding = currentlyAddingRef.current.has(clientId)
            
            if (!belongsToPhase3 && !isCurrentlyAdding) {
                // Phase 2 upload exists but doesn't belong to current Phase 3 items and isn't being added
                // This is likely an old upload - skip it
                // console.log('[Auto-start] Phase 2 upload exists but doesn\'t belong to current Phase 3 items - skipping (old upload)', { clientId })
                return
            }

            // Guard: Only start if Phase 2 upload is in a startable state
            // Do NOT restart failed or completed uploads - they're terminal
            if (phase2Upload.status !== 'pending' && phase2Upload.status !== 'paused') {
                console.log('[Auto-start] Skipping - Phase 2 upload not in startable state', {
                    clientId,
                    phase2Status: phase2Upload.status,
                    expected: ['pending', 'paused']
                })
                return
            }

            // Guard: Do NOT restart if Phase 3 item is already terminal
            // This prevents trying to restart failed/completed items
            if (item.uploadStatus !== 'queued') {
                console.log('[Auto-start] Skipping - Phase 3 item not in queued state', {
                    clientId,
                    phase3Status: item.uploadStatus
                })
                return
            }

            // Mark as starting to prevent double-start
            startingUploadsRef.current.add(clientId)
            
            // CRITICAL: Verify we're about to start the correct upload
            // Check that it's our new upload (no uploadSessionId) and not an old one
            if (phase2Upload.uploadSessionId) {
                console.error('[Auto-start] WARNING: Upload has uploadSessionId before start - this is an old upload!', {
                    clientId,
                    uploadSessionId: phase2Upload.uploadSessionId,
                    status: phase2Upload.status,
                    hasFile: !!phase2Upload.file,
                    fileName: phase2Upload.fileName,
                    clientReference: phase2Upload.clientReference
                })
                // Remove this old upload and mark as failed - don't try to resume it
                phase2Manager.removeUpload(clientId)
                startingUploadsRef.current.delete(clientId)
                markUploadFailed(clientId, {
                    message: 'Old upload detected - please try again',
                    type: 'unknown'
                })
                return
            }
            
            console.log('[Auto-start] Starting upload', { 
                clientId, 
                phase2Status: phase2Upload.status,
                uploadSessionId: phase2Upload.uploadSessionId,
                hasFile: !!phase2Upload.file,
                fileName: phase2Upload.fileName,
                clientReference: phase2Upload.clientReference
            })

            // Start the upload
            phase2Manager.startUpload(clientId)
                .then(() => {
                    // Upload started successfully
                    console.log('[Auto-start] Upload started successfully', { clientId })
                    startingUploadsRef.current.delete(clientId)
                    
                    // CRITICAL: Keep upload in currentlyAddingRef until it's confirmed in Phase 3 items with 'uploading' status
                    // This prevents the subscription filter from removing it before Phase 3 state updates
                    currentlyAddingRef.current.add(clientId)
                    
                    // CRITICAL: Wait a tiny bit for Phase 2 to update status after startUpload()
                    // startUpload() is async and might not update status synchronously
                    setTimeout(() => {
                        // Get the upload directly from manager (most reliable source)
                        let phase2UploadAfterStart = phase2Manager.uploads.get(clientId)
                        
                        if (!phase2UploadAfterStart) {
                            // Try finding by clientReference (fallback) - shouldn't happen but handle it
                            const allManagerUploads = Array.from(phase2Manager.uploads.entries())
                            const uploadByClientRef = allManagerUploads.find(([key, upload]) => upload.clientReference === clientId)
                            if (uploadByClientRef) {
                                console.log('[Auto-start] Found upload by clientReference after start', {
                                    clientId,
                                    key: uploadByClientRef[0],
                                    status: uploadByClientRef[1].status
                                })
                                phase2UploadAfterStart = uploadByClientRef[1]
                            }
                        }
                        
                        if (!phase2UploadAfterStart) {
                            console.error('[Auto-start] Upload not found after startUpload() - this should not happen', { clientId })
                            markUploadFailed(clientId, {
                                message: 'Upload disappeared after starting',
                                type: 'unknown'
                            })
                            return
                        }
                        
                        // Update Phase 3 status based on Phase 2 status
                        if (phase2UploadAfterStart.status === 'uploading' || phase2UploadAfterStart.status === 'initiating') {
                            console.log('[Auto-start] Manually updating Phase 3 status to uploading', {
                                clientId,
                                phase2Status: phase2UploadAfterStart.status,
                                uploadSessionId: phase2UploadAfterStart.uploadSessionId
                            })
                            
                            if (phase2UploadAfterStart.uploadSessionId) {
                                setUploadSessionId(clientId, phase2UploadAfterStart.uploadSessionId)
        } else {
                                markUploadStarted(clientId)
                            }
                        } else if (phase2UploadAfterStart.status === 'completed') {
                            // Upload was already completed (old upload that was rehydrated)
                            console.warn('[Auto-start] Upload already completed - this is an old upload that was rehydrated', {
                                clientId,
                                uploadSessionId: phase2UploadAfterStart.uploadSessionId,
                                lastUpdatedAt: phase2UploadAfterStart.lastUpdatedAt
                            })
                            // Mark as complete in Phase 3 (but don't finalize - let user retry if needed)
                            markUploadComplete(clientId)
                        } else if (phase2UploadAfterStart.status === 'failed') {
                            // Upload failed to start
                            console.error('[Auto-start] Upload failed to start', {
                                clientId,
                                error: phase2UploadAfterStart.error
                            })
                            markUploadFailed(clientId, {
                                message: phase2UploadAfterStart.error || 'Upload failed to start',
                                type: 'unknown'
                            })
                        } else {
                            // Unexpected status (pending, paused, etc.)
                            console.warn('[Auto-start] Upload started but status not uploading/initiating', {
                                clientId,
                                phase2Status: phase2UploadAfterStart.status,
                                uploadSessionId: phase2UploadAfterStart.uploadSessionId
                            })
                            // Still mark as started - sync effect will handle status update
                            markUploadStarted(clientId)
                        }
                    }, 50) // Small delay to let Phase 2 update status
                    
                    // Effect will re-run when phase2Uploads or phase3Items change
                    // and check for more queued items
                })
                .catch(error => {
                    // Upload failed to start
                    console.error('[Auto-start] Upload failed to start', { clientId, error })
                    startingUploadsRef.current.delete(clientId)
                    
                    // Mark as failed in Phase 3 (this frees up a slot)
                    // Failed uploads are terminal and MUST free a slot
                    markUploadFailed(clientId, {
                        message: error.message || 'Failed to start upload',
                        type: 'unknown'
                    })
                    // Effect will re-run when phase3Items changes (item status → 'failed')
                    // and start the next queued item (slot is now available)
                })
        })
    }, [phase3Items, phase2Uploads, markUploadFailed, queuedItemsCount, activeUploadsCount, setUploadSessionId, markUploadStarted])

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

                // Call backend endpoint
                // Include title and resolvedFilename (filename) for backend persistence
                const response = await window.axios.post('/app/assets/upload/complete', {
                    upload_session_id: item.uploadSessionId,
                    asset_type: defaultAssetType === 'asset' ? 'asset' : 'marketing',
                    title: item.title, // Human-facing title (no extension)
                    filename: item.resolvedFilename, // Derived filename (title + extension)
                })

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
            setIsFinalizing(false)
            
            // Log error for debugging
            // console.error('Finalize error:', error)
        }
    }, [phase3Manager, defaultAssetType, isFinalizing, onClose])

    // Check if finalize button should be enabled
    const canFinalize = phase3Manager.canFinalize && 
                       !isFinalizing && 
                       phase3Manager.completedItems.length > 0 &&
                       phase3Manager.uploadingItems.length === 0

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
        
        // No completed uploads
        if (phase3Manager.completedItems.length === 0 && phase3Manager.items.length > 0) {
            const hasUploading = phase3Manager.uploadingItems.length > 0
            const hasQueued = phase3Manager.queuedItems.length > 0
            if (hasUploading || hasQueued) {
                errors.push('Uploads are still in progress.')
        } else {
                errors.push('At least one upload must complete before finalizing.')
            }
        }
        
        // Uploads still in progress (additional check beyond canFinalize)
        if (phase3Manager.uploadingItems.length > 0) {
            errors.push('Uploads are still in progress.')
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
        phase3Manager.completedItems.length,
        phase3Manager.uploadingItems.length,
        phase3Manager.queuedItems.length,
        phase3Manager.items.length,
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

                            {/* Asset Type (read-only) */}
                            <div className="mb-4">
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Asset Type
                                </label>
                                <input
                                    type="text"
                                value={defaultAssetType === 'asset' ? 'Asset' : 'Marketing Asset'}
                                    readOnly
                                    disabled
                                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm bg-gray-50"
                                />
                            </div>

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

                        {/* Form-level validation alert */}
                        {hasFiles && blockingErrors.length > 0 && (
                            <div 
                                role="alert"
                                className="mt-6 rounded-md border border-yellow-300 bg-yellow-50 p-4"
                            >
                                    <div className="flex">
                                    <div className="flex-shrink-0">
                                        <ExclamationTriangleIcon className="h-5 w-5 text-yellow-600" aria-hidden="true" />
                                    </div>
                                    <div className="ml-3 flex-1">
                                        <h3 className="text-sm font-medium text-yellow-900">
                                            Please resolve the following before finalizing:
                                            </h3>
                                        <div className="mt-2 text-sm text-yellow-900">
                                                <ul className="list-disc list-inside space-y-1">
                                                {blockingErrors.map((error, index) => (
                                                    <li key={index}>{error}</li>
                                                ))}
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                        {/* Footer Actions */}
                        <div className="mt-6">
                            <div className="flex justify-end gap-3">
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
                                        className={`rounded-md px-4 py-2 text-sm font-medium ${
                                            canFinalize && !isFinalizing
                                                ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                                                : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                                        }`}
                                    >
                                        {isFinalizing ? 'Finalizing...' : 'Finalize Assets'}
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
