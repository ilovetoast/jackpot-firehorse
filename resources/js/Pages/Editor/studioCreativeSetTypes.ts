/** Studio UI: "Versions" — backend {@link CreativeSet} / {@link CreativeSetVariant}. */

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
}

export type StudioCreativeSetDto = {
    id: string
    name: string
    status: string
    variants: StudioCreativeSetVariantDto[]
}

export type StudioGenerationPresetColor = { id: string; label: string; hex?: string | null }

export type StudioGenerationPresetScene = { id: string; label: string; instruction: string }

export type StudioGenerationPresetsDto = {
    preset_colors: StudioGenerationPresetColor[]
    preset_scenes: StudioGenerationPresetScene[]
    limits: {
        max_colors: number
        max_scenes: number
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
