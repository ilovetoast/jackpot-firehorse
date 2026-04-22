/** Studio UI: "Versions" — backend {@link CreativeSet} / {@link CreativeSetVariant}. */

export type StudioVariantGroupMemberDto = {
    id: string
    composition_id: string | null
    slot_key: string | null
    label: string | null
    status: string
    generation_status: string | null
    spec_json: Record<string, unknown>
    generation_job_item_id: string | null
    result_asset_id: string | null
    sort_order: number
}

export type StudioVariantGroupDto = {
    id: string
    uuid: string
    type: string
    label: string | null
    status: string
    source_composition_id: string
    source_composition_version_id: string | null
    creative_set_id: string | null
    settings_json: Record<string, unknown>
    target_spec_json: unknown
    shared_mask_asset_id: string | null
    sort_order: number
    member_count: number
    members: StudioVariantGroupMemberDto[]
}

export type StudioCreativeSetVariantDto = {
    id: string
    composition_id: string
    label: string | null
    sort_order: number
    status: string
    axis: Record<string, unknown>
    thumbnail_url: string | null
    /** Present when status is `failed` and a retry can be enqueued for the latest failed job item. */
    retryable_generation_job_item_id?: string | null
    studio_variant_group_id?: string | null
    legacy_ungrouped_label?: string | null
}

export type StudioCreativeSetDto = {
    id: string
    name: string
    status: string
    /** Marked “hero” composition for this set (export / best-version cue); at most one. */
    hero_composition_id?: string | null
    variants: StudioCreativeSetVariantDto[]
    /** When {@code STUDIO_VARIANT_GROUPS_V1} is on — sibling families (color / layout / generic). */
    variant_groups?: StudioVariantGroupDto[]
}

export type StudioGenerationPresetColor = { id: string; label: string; hex?: string | null }

export type StudioGenerationPresetScene = { id: string; label: string; instruction: string }

export type StudioGenerationPresetFormat = {
    id: string
    label: string
    width: number
    height: number
    /** Taxonomy bucket for grouped format picker (Generate modal). */
    group?: string
    description?: string
    recommended?: boolean
}

export type StudioGenerationPresetsDto = {
    preset_colors: StudioGenerationPresetColor[]
    preset_scenes: StudioGenerationPresetScene[]
    preset_formats: StudioGenerationPresetFormat[]
    /** Version Builder format-pack shortcut; each id must exist in `preset_formats`. */
    format_pack_quick_ids?: string[]
    format_group_order?: string[]
    format_group_labels?: Record<string, string>
    limits: {
        max_colors: number
        max_scenes: number
        max_formats: number
        max_outputs_per_request: number
        max_versions_per_set: number
    }
}

export type StudioGenerationJobItemDto = {
    id: string
    status: string
    combination_key: string
    attempts: number
    error: { message?: string } | null
    superseded_at?: string | null
    retried_from_item_id?: string | null
}

/** One sibling composition that could not receive the full command chain. */
export type CreativeSetApplySkippedDto = {
    composition_id: string
    reason: string
    reason_code: string
    command_index?: number
    command_type?: string
}

/** API body / response: which sibling cohort semantic apply runs against. */
export type CreativeSetSemanticApplyScopeApi = 'all_versions' | 'selected_versions'

/** POST /app/api/creative-sets/{id}/apply-preview — dry-run counts for confirm copy. */
export type CreativeSetApplyPreviewDto = {
    scope?: CreativeSetSemanticApplyScopeApi
    skipped: CreativeSetApplySkippedDto[]
    skipped_by_reason: Record<string, number>
    sibling_compositions_targeted: number
    sibling_compositions_eligible: number
    sibling_compositions_would_skip: number
    commands_considered: number
}

/** POST /app/api/creative-sets/{id}/apply — allowlisted semantic sync between sibling compositions. */
export type SyncTextContentRole = 'headline' | 'subheadline' | 'cta' | 'disclaimer'

export type SyncVisibilityRole = 'logo' | 'badge' | 'disclaimer' | 'cta'

export type SyncTextAlignRole = 'headline' | 'subheadline' | 'cta'

export type SyncTransformRole = 'logo' | 'badge' | 'cta' | 'disclaimer' | 'headline' | 'subheadline'

export type CreativeSetApplyCommand =
    | {
          type: 'update_text_content'
          role: SyncTextContentRole
          text: string
      }
    | {
          type: 'update_layer_visibility'
          role: SyncVisibilityRole
          visible: boolean
      }
    | {
          type: 'update_text_alignment'
          role: SyncTextAlignRole
          alignment: 'left' | 'center' | 'right'
      }
    | {
          type: 'update_role_transform'
          role: SyncTransformRole
          x: number
          y: number
          width?: number
          height?: number
      }

export type StudioGenerationJobDto = {
    id: string
    creative_set_id: string
    status: string
    meta: Record<string, unknown>
    items: StudioGenerationJobItemDto[]
}
