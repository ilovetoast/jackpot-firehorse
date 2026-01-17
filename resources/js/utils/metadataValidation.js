/**
 * Metadata Validation Utilities
 *
 * Phase 2 – Step 3: Client-side validation for required metadata fields.
 */

/**
 * Check if a required field is satisfied based on its value and type.
 *
 * @param {Object} field - Field object from schema
 * @param {any} value - Current field value
 * @returns {boolean} True if field is satisfied (or not required)
 */
export function isFieldSatisfied(field, value) {
    // If field is not required, it's always satisfied
    if (!field.is_required) {
        return true
    }

    // Check based on field type
    switch (field.type) {
        case 'text':
            // Non-empty string
            return typeof value === 'string' && value.trim().length > 0

        case 'number':
            // Non-null number
            return value !== null && value !== undefined && !isNaN(value)

        case 'boolean':
            // Boolean value (true OR false - presence, not truthy)
            return typeof value === 'boolean'

        case 'date':
            // Valid date value (non-empty string)
            return typeof value === 'string' && value.length > 0

        case 'select':
            // Value selected (non-empty string)
            return typeof value === 'string' && value.length > 0

        case 'multiselect':
            // At least one value selected
            return Array.isArray(value) && value.length > 0

        default:
            // Unknown type - consider satisfied to avoid false positives
            return true
    }
}

/**
 * Validate all required fields in a schema.
 *
 * @param {Array} groups - Array of metadata groups from schema
 * @param {Object} values - Current metadata values keyed by field key
 * @returns {Object} Validation result with errors keyed by field key
 */
export function validateMetadata(groups, values) {
    const errors = {}

    groups.forEach((group) => {
        group.fields.forEach((field) => {
            if (field.is_required && !isFieldSatisfied(field, values[field.key])) {
                errors[field.key] = 'This field is required.'
            }
        })
    })

    return errors
}

/**
 * Check if any required fields are unsatisfied.
 *
 * @param {Array} groups - Array of metadata groups from schema
 * @param {Object} values - Current metadata values keyed by field key
 * @returns {boolean} True if all required fields are satisfied
 */
export function areAllRequiredFieldsSatisfied(groups, values) {
    const errors = validateMetadata(groups, values)
    return Object.keys(errors).length === 0
}

/**
 * Find the first group containing an unsatisfied required field.
 *
 * @param {Array} groups - Array of metadata groups from schema
 * @param {Object} values - Current metadata values keyed by field key
 * @returns {string|null} Group key of first group with unsatisfied field, or null
 */
export function findFirstInvalidGroup(groups, values) {
    const errors = validateMetadata(groups, values)

    if (Object.keys(errors).length === 0) {
        return null
    }

    // Find first group containing a field with an error
    for (const group of groups) {
        const hasError = group.fields.some((field) => errors[field.key])
        if (hasError) {
            return group.key
        }
    }

    return null
}
