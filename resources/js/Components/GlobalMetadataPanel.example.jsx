/**
 * Phase 3.3 Global Metadata Panel Usage Example
 * 
 * Example showing how to use the GlobalMetadataPanel component with Phase 3 Upload Manager.
 * 
 * This is an EXAMPLE FILE - not meant to be imported directly.
 */

import { usePhase3UploadManager } from '../hooks/usePhase3UploadManager';
import GlobalMetadataPanel from './GlobalMetadataPanel';

function ExampleUsage() {
    // Initialize upload manager with context
    const context = {
        companyId: 1,
        brandId: 2,
        categoryId: null // Will be set via category selector
    };

    const uploadManager = usePhase3UploadManager(context);

    // Example categories (typically from page props)
    const categories = [
        { id: 1, name: 'Photography', asset_type: 'asset' },
        { id: 2, name: 'Graphics', asset_type: 'asset' },
        { id: 3, name: 'Logos', asset_type: 'asset' }
    ];

    /**
     * Handle category change - fetch metadata fields for the category
     * In a real implementation, this would fetch from backend or use category config
     */
    const handleCategoryChange = (categoryId) => {
        if (!categoryId) {
            uploadManager.setAvailableMetadataFields([]);
            return;
        }

        // Example: Fetch metadata fields for category
        // In real implementation, this would be an API call or use category config
        const metadataFields = getMetadataFieldsForCategory(categoryId);
        uploadManager.changeCategory(categoryId, metadataFields);
    };

    /**
     * Example function to get metadata fields for a category
     * In real implementation, this would come from backend or category configuration
     */
    function getMetadataFieldsForCategory(categoryId) {
        // Example metadata fields
        return [
            {
                key: 'color',
                label: 'Color',
                type: 'select',
                options: [
                    { value: 'red', label: 'Red' },
                    { value: 'blue', label: 'Blue' },
                    { value: 'green', label: 'Green' }
                ],
                systemDefined: false,
                required: false
            },
            {
                key: 'tags',
                label: 'Tags',
                type: 'multiselect',
                options: [
                    { value: 'outdoor', label: 'Outdoor' },
                    { value: 'indoor', label: 'Indoor' },
                    { value: 'product', label: 'Product' }
                ],
                systemDefined: false,
                required: false
            },
            {
                key: 'description',
                label: 'Description',
                type: 'textarea',
                systemDefined: false,
                required: true
            },
            {
                key: 'is_featured',
                label: 'Featured',
                type: 'boolean',
                systemDefined: false,
                required: false,
                defaultValue: false
            }
        ];
    }

    return (
        <div className="space-y-4">
            {/* Global Metadata Panel */}
            <GlobalMetadataPanel
                uploadManager={uploadManager}
                categories={categories}
                onCategoryChange={handleCategoryChange}
            />
        </div>
    );
}

/**
 * Example with validation
 */
function ExampleWithValidation() {
    const uploadManager = usePhase3UploadManager(context);
    const categories = [/* ... */];

    // Validate before finalization
    const handleFinalize = () => {
        const warnings = uploadManager.validateMetadata();
        
        if (warnings.some(w => w.severity === 'error')) {
            // Show error - cannot finalize
            alert('Please fill in all required fields');
            return;
        }

        // Proceed with finalization
        // ...
    };

    return (
        <div>
            <GlobalMetadataPanel
                uploadManager={uploadManager}
                categories={categories}
            />
            
            {/* Finalize button (example - not part of GlobalMetadataPanel) */}
            {uploadManager.canFinalize && (
                <button onClick={handleFinalize}>
                    Finalize Upload
                </button>
            )}
        </div>
    );
}

export default ExampleUsage;
