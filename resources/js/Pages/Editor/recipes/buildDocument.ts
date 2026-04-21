/**
 * buildRecipeDocument
 *
 * Glue between the recipe engine + the editor's {@link DocumentModel}. Given
 * a recipe key, a BrandAdStyle, a format, and content slots, produce a
 * `DocumentModel` that Studio can load identically to any other composition.
 *
 * Lives next to the recipes module (rather than in `templateConfig.ts` or
 * `documentModel.ts`) because it's the natural seam between the two worlds:
 * recipes on one side, editor documents on the other. Callers that don't
 * need this (the in-app wizard, for example, drops blueprints directly into
 * the already-loaded editor state) shouldn't have to import it.
 */

import type { DocumentModel } from '../documentModel'
import { generateId } from '../documentModel'
import { blueprintToLayersAndGroups } from '../templateConfig'
import { composeRecipe } from './registry'
import type { BrandAdStyle, RecipeContent, RecipeKey } from './types'

export type BuildRecipeDocumentInput = {
    recipeKey: RecipeKey
    style: BrandAdStyle
    format: { width: number; height: number }
    content?: RecipeContent
    /** Optional brand primary — forwarded to materializer for defaults that read it. */
    brandPrimaryColor?: string
}

export function buildRecipeDocument({
    recipeKey,
    style,
    format,
    content,
    brandPrimaryColor,
}: BuildRecipeDocumentInput): DocumentModel {
    const { blueprints } = composeRecipe(recipeKey, {
        style,
        format,
        content: content ?? {},
    })
    const { layers, groups } = blueprintToLayersAndGroups(
        blueprints,
        format.width,
        format.height,
        brandPrimaryColor,
    )

    const now = new Date().toISOString()
    return {
        id: generateId(),
        width: format.width,
        height: format.height,
        preset: 'custom',
        layers,
        groups,
        created_at: now,
        updated_at: now,
    }
}
