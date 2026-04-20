/**
 * Brand logo resolver — pick the right logo URL for a given display surface.
 *
 * The brand model has four logo slots:
 *   - primary (required, source of truth, used by Studio/generative)
 *   - light   (optional, for white / light backgrounds)
 *   - dark    (optional, for dark backgrounds / cinematic hero)
 *   - horizontal (optional, landscape / wordmark — orthogonal to light/dark)
 *
 * Call sites should declare the visual surface they render on — 'light', 'dark',
 * or 'primary' — rather than hand-rolling `logo_dark_path ?? logo_path` logic.
 * That keeps fallback + future-variant rollout (e.g. monochrome, holiday) in
 * exactly one place and makes the nav / assets / overview consistent.
 *
 * Mirror of Brand::logoForSurface() on the backend.
 *
 * @param {object|null|undefined} brand  Inertia activeBrand / brand row
 * @param {'light'|'dark'|'primary'} surface
 * @returns {string|null} URL or null when no logo is set
 */
export function getBrandLogoForSurface(brand, surface = 'primary') {
    if (!brand) return null;

    const primary = brand.logo_path ?? null;

    switch (surface) {
        case 'dark':
            return brand.logo_dark_path || primary;
        case 'light':
            return brand.logo_light_path || primary;
        case 'primary':
        default:
            return primary;
    }
}

/**
 * True when the given surface is using a dedicated variant (not the primary fallback).
 * Useful for deciding whether to apply CSS filter hacks like `brightness(0) invert(1)`
 * — if a dedicated dark variant exists, don't filter the primary.
 */
export function hasDedicatedVariantForSurface(brand, surface) {
    if (!brand) return false;
    if (surface === 'dark') return Boolean(brand.logo_dark_path);
    if (surface === 'light') return Boolean(brand.logo_light_path);
    return false;
}
