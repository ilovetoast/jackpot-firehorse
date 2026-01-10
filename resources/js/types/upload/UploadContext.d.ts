/**
 * Phase 3 Upload Context Model
 * 
 * Represents the canonical context for an upload session.
 * This context is immutable once set and applies to all files in the upload batch.
 * 
 * @interface UploadContext
 */
export interface UploadContext {
    /**
     * Tenant/Company ID - The organization that owns the upload
     * Required for tenant isolation
     */
    companyId: string | number;

    /**
     * Brand ID - The brand within the company that owns the assets
     * Required for brand scoping
     */
    brandId: string | number;

    /**
     * Category ID - The category these assets will be assigned to
     * Can be changed until finalization (see handleCategoryChange)
     * Required before finalization
     */
    categoryId: string | number | null;
}
