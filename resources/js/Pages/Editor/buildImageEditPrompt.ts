import type { BrandContext } from './documentModel'

/**
 * Full prompt sent to the image-edit API (mirrors server-side {@see EditorEditImageController::buildEditPrompt}).
 */
export function buildImageEditPrompt(userPrompt: string, brandContext?: BrandContext | null): string {
    const instruction = userPrompt.trim()
    const lines = [
        'Modify the provided image according to the instruction below.',
        '',
        'Instruction:',
        instruction,
        '',
        'Rules:',
        '- Keep the subject, identity, pose, and composition EXACTLY the same',
        '- Do NOT change the person or main object',
        '- Only modify the requested elements',
        '- Preserve realism and lighting consistency',
    ]

    if (brandContext) {
        const style = brandContext.visual_style?.trim()
        const tone = brandContext.tone?.filter(Boolean)
        if (style || (tone && tone.length > 0)) {
            lines.push('')
            lines.push('Brand context:')
            if (style) {
                lines.push(`- Style: ${style}`)
            }
            if (tone && tone.length > 0) {
                lines.push(`- Tone: ${tone.join(', ')}`)
            }
        }
    }

    return lines.join('\n')
}
