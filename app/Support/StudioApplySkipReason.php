<?php

namespace App\Support;

/**
 * Machine-readable codes when a semantic apply command cannot be applied to a sibling composition.
 * Human copy lives in API `skipped[].reason` and logs.
 */
final class StudioApplySkipReason
{
    public const COMPOSITION_NOT_FOUND_OR_INACCESSIBLE = 'composition_not_found_or_inaccessible';

    public const INVALID_COMMAND_PAYLOAD = 'invalid_command_payload';

    public const UNSUPPORTED_COMMAND_TYPE = 'unsupported_command_type';

    /** Source document has no layer matching the requested sync role (or CTA group anchor). */
    public const SOURCE_ROLE_LAYER_NOT_FOUND = 'source_role_layer_not_found';

    /** Resolver could not map a source layer id onto the sibling stack (z-index / name mismatch). */
    public const TARGET_LAYER_MAPPING_FAILED = 'target_layer_mapping_failed';

    /** Target layer exists but is not the expected type for the patch. */
    public const TARGET_LAYER_TYPE_MISMATCH = 'target_layer_type_mismatch';

    /** Patch was empty after allowlisting, or merge failed. */
    public const TARGET_PATCH_REJECTED = 'target_patch_rejected';

    public const INVALID_DOCUMENT_STRUCTURE = 'invalid_document_structure';
}
