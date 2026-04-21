/**
 * Brand-DNA Ad Recipes — public surface.
 *
 * Import from this module rather than individual files to get a stable API.
 */

export type {
    Recipe,
    RecipeKey,
    RecipeInput,
    RecipeOutput,
    RecipeContent,
    RecipeFormat,
    RecipeDescriptor,
    BrandAdStyle,
    GroupSpec,
} from './types'

export { deriveBrandAdStyle, resolveLogoAssetIdForSurface, luminanceOf, inkOnColor } from './brandAdStyle'
export type { BrandAdStyleHint, AdStyleOverrides, BrandAdReferenceHints } from './brandAdStyle'

export {
    RECIPE_REGISTRY,
    composeRecipe,
    getRecipe,
    getRecipeDescriptor,
    isRecipeAvailable,
} from './registry'

export {
    FORMAT_PACKS,
    IAB_STANDARD_PACK,
    SOCIAL_PACK,
    COMPREHENSIVE_PACK,
    getFormatPack,
    renderRecipeAcrossPack,
} from './formatPack'
export type { FormatPack, FormatPackSize, FormatPackRenderResult } from './formatPack'

export { buildRecipeDocument } from './buildDocument'
export type { BuildRecipeDocumentInput } from './buildDocument'

// Primitives are re-exported for recipe authors building new recipes.
export {
    hexToRgba,
    createTonedBackground,
    createPhotoBackground,
    createGhostFilledHeadline,
    createHoldingShape,
    createWatermarkPair,
    createFooterBar,
    createCtaPill,
    createTextBoost,
} from './primitives'
