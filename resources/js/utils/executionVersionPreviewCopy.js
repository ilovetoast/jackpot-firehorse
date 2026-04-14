/** @type {Record<'original' | 'enhanced' | 'presentation' | 'ai', string[]>} */
export const EXECUTION_VERSION_DETAIL_BULLETS = {
    original: [
        "The pipeline's source thumbnail — what the file actually looks like after rasterization.",
        'May include crop marks, bleed, print marks, or color bars. Best for QA and production checks.',
    ],
    enhanced: [
        'Studio View: you crop the large source thumbnail manually to remove unwanted framing.',
        'Saved as a real derivative image and used as the preferred input for Presentation styling and AI.',
    ],
    presentation: [
        'Polished on-screen look using CSS presets only — no AI and no extra rendered file in v1.',
        'Uses Studio when it exists, otherwise falls back to Source. Pick a preset below the tiles in the drawer.',
    ],
    ai: [
        'User-initiated AI scene or marketing-style render (separate from Presentation).',
        'Uses Studio first when available, otherwise Source. Produces a stored raster in the AI slot.',
    ],
}
