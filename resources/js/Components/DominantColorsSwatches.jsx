/**
 * Dominant Colors Swatches Component
 *
 * Displays dominant image colors as small, read-only color squares (swatches).
 * Uses existing asset.metadata['dominant_colors'] data.
 *
 * Features:
 * - Read-only display (no edit, no filter)
 * - Up to 3 color swatches
 * - Tooltip with hex and coverage percentage
 * - Graceful empty state (renders nothing if data missing)
 */

export default function DominantColorsSwatches({ dominantColors }) {
    // Empty state: render nothing if data is missing or empty
    if (!dominantColors || !Array.isArray(dominantColors) || dominantColors.length === 0) {
        return null;
    }

    return (
        <div className="flex items-center gap-2">
            {dominantColors.map((color, index) => {
                // Validate color object structure
                if (!color || !color.hex || !Array.isArray(color.rgb) || color.rgb.length < 3) {
                    return null;
                }

                const hex = color.hex;
                const coverage = color.coverage ?? 0;
                const coveragePercent = Math.round(coverage * 100);
                
                // Build tooltip text: hex value and coverage percentage
                const tooltipText = `${hex} · ${coveragePercent}%`;

                return (
                    <div
                        key={index}
                        className="w-4 h-4 rounded-sm border border-gray-300 flex-shrink-0"
                        style={{ backgroundColor: hex }}
                        title={tooltipText}
                        aria-label={`Dominant color ${index + 1}: ${hex}, ${coveragePercent}% coverage`}
                    />
                );
            })}
        </div>
    );
}
