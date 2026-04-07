/** @type {Record<'original' | 'enhanced' | 'presentation', string[]>} */
export const EXECUTION_VERSION_DETAIL_BULLETS = {
    original: [
        "Shows the asset's source thumbnail from the main pipeline (no studio framing or AI).",
        'Best for checking crop marks, bleed, and how the file actually looks on disk.',
    ],
    enhanced: [
        'Starts from a clean, subject-aware crop when the pipeline has produced one.',
        'Adds studio framing—template, background, and shadow—when that output exists.',
    ],
    presentation: [
        'AI-generated presentation treatment for decks and on-screen reviews.',
        'Separate from the source thumbnail and from studio-framed previews.',
    ],
}
