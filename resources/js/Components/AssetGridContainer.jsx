/**
 * AssetGridContainer Component
 * 
 * Container for asset grid with true CSS Grid scaling.
 * Uses CSS Grid with variable column sizing based on card size.
 * 
 * Grid automatically adjusts columns based on:
 * - Card size (controlled by AssetScaleControl)
 * - Container width
 * - Gap spacing
 * 
 * Features:
 * - CSS Grid with auto-fill columns
 * - Variable card sizing (no transform: scale())
 * - Smooth transitions
 * - Responsive breakpoints
 * 
 * @param {Object} props
 * @param {number} props.cardSize - Card size in pixels (160-360)
 * @param {Array} props.children - Grid items (AssetCard components)
 */
export default function AssetGridContainer({ cardSize = 220, children }) {
    // Clamp cardSize to valid range (160-600)
    const clampedSize = Math.max(160, Math.min(600, cardSize))
    
    return (
        <div
            className="grid gap-7 asset-grid-container"
            style={{
                '--asset-card-size': `${clampedSize}px`,
            }}
        >
            {children}
        </div>
    )
}
