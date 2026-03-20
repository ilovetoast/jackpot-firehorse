/**
 * Brand DNA — structure stored in brand_model_versions.model_payload (JSON).
 * Shared by Brand Builder wizard and Brand Settings UI.
 */

export interface BrandDNA {
  strategy: {
    archetype: string | null
    tone: string | null
    traits: string[]
    voice_description: string | null
  }

  purpose: {
    why: string | null
    what: string | null
  }

  positioning: {
    industry: string | null
    target_audience: string | null
    market_category: string | null
    competitive_position: string | null
  }

  expression: {
    brand_look: string | null
    brand_voice: string | null
    tone_keywords: string[]
    photography_attributes: string[]
  }

  standards: {
    primary_font: string | null
    secondary_font: string | null
    heading_style: string | null
    headline_treatment: string | null
    /** Stable IDs from config/headline_appearance.php */
    headline_appearance_features: string[]
    body_style: string | null
    allowed_colors: string[]
    banned_colors: string[]
    allowed_fonts: string[]
    visual_references: number[]  // asset ids
  }
}
