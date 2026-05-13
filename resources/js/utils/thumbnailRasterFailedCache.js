/**
 * Raster thumbnail URLs that failed to load (404, etc.), including `preview_3d_poster_url`.
 * Shared by {@link ../Components/ThumbnailPreview.jsx} and surfaces that call
 * {@link ./thumbnailRasterPrimaryUrl.js} so poster failure fallback stays consistent.
 */
export const failedRasterThumbnailUrls = new Set()
