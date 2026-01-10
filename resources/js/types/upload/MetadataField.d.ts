/**
 * Phase 3 Metadata Field Model
 * 
 * Represents a single metadata field that can be applied to assets.
 * Fields are derived from the selected category and may be system-defined
 * or custom fields configured for that category.
 * 
 * @interface MetadataField
 */
export interface MetadataField {
    /**
     * Unique key identifier for this field (e.g., 'color', 'tags', 'description')
     * Used as the key in metadata objects
     */
    key: string;

    /**
     * Human-readable label for display in UI
     */
    label: string;

    /**
     * Field type determines how the field is rendered and validated
     * 
     * - 'text': Single-line text input
     * - 'textarea': Multi-line text input
     * - 'select': Single selection from options
     * - 'multiselect': Multiple selections from options
     * - 'number': Numeric input
     * - 'date': Date picker
     * - 'boolean': Checkbox/toggle
     */
    type: 'text' | 'textarea' | 'select' | 'multiselect' | 'number' | 'date' | 'boolean';

    /**
     * Available options for select/multiselect fields
     * Undefined for non-select field types
     */
    options?: Array<{ value: string | number; label: string }>;

    /**
     * Whether this field is system-defined (cannot be removed/modified)
     * vs. custom field (may be editable/deletable)
     */
    systemDefined: boolean;

    /**
     * Whether this field is required
     * Required fields must have a value before finalization
     */
    required?: boolean;

    /**
     * Default value for this field (if any)
     * Applied when field is first added to metadata draft
     */
    defaultValue?: string | number | boolean | string[] | null;
}
