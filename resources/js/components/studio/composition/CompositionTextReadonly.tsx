import { useLayoutEffect, useRef } from 'react'
import type { CSSProperties } from 'react'
import type { BrandContext, TextLayer } from '../../../Pages/Editor/documentModel'
import { computeAutoFitTextFontSize } from '../../../Pages/Editor/documentModel'
import { ensureCanvasFontLoaded, formatCssFontFamilyStack, resolveCanvasFontFamily } from '../../../Pages/Editor/editorBrandFonts'

/**
 * Read-only canvas text — same CSS stack as {@link TextLayerEditable} read mode (no contentEditable).
 */
export function CompositionTextReadonly(props: {
    layer: TextLayer
    brandContext: BrandContext | null
    brandFontsEpoch?: number
    /** Editor only: mutates font size to fit box (export omits — uses saved `style.fontSize`). */
    onAutoFitFontSize?: (size: number) => void
}) {
    const { layer, brandContext, brandFontsEpoch = 0, onAutoFitFontSize } = props
    const readRef = useRef<HTMLDivElement>(null)
    const autoFit = layer.style.autoFit === true
    const resolvedFontFamily = resolveCanvasFontFamily(brandContext, layer.style.fontFamily)
    const cssFontFamilyStack = formatCssFontFamilyStack(resolvedFontFamily)

    const lh = layer.style.lineHeight ?? 1.25
    const ls = layer.style.letterSpacing ?? 0
    const vAlign = layer.style.verticalAlign ?? 'top'
    const alignItems: CSSProperties['alignItems'] =
        vAlign === 'middle' ? 'center' : vAlign === 'bottom' ? 'flex-end' : 'flex-start'

    const strokeWidth = (layer.style as { strokeWidth?: number }).strokeWidth
    const strokeColor = (layer.style as { strokeColor?: string }).strokeColor
    const webkitTextStroke =
        typeof strokeWidth === 'number' && strokeWidth > 0
            ? `${strokeWidth}px ${strokeColor ?? layer.style.color}`
            : undefined

    const textStyle: CSSProperties = {
        fontFamily: cssFontFamilyStack,
        fontSize: layer.style.fontSize,
        fontWeight: layer.style.fontWeight ?? 400,
        lineHeight: `${lh}`,
        letterSpacing: `${ls}px`,
        color: layer.style.color,
        textAlign: layer.style.textAlign ?? 'left',
        whiteSpace: 'pre-wrap',
        wordBreak: 'break-word',
        width: '100%',
        minHeight: autoFit ? '100%' : undefined,
        WebkitTextStroke: webkitTextStroke,
    }

    useLayoutEffect(() => {
        let cancelled = false
        const run = async () => {
            await ensureCanvasFontLoaded(resolvedFontFamily, layer.style.fontSize, layer.style.fontWeight ?? 400)
            if (cancelled || !readRef.current) {
                return
            }
            if (!autoFit) {
                readRef.current.style.height = 'auto'
                const h = readRef.current.scrollHeight
                readRef.current.style.height = `${h}px`
            }
        }
        void run()
        return () => {
            cancelled = true
        }
    }, [
        autoFit,
        resolvedFontFamily,
        cssFontFamilyStack,
        layer.style.fontSize,
        layer.style.fontWeight,
        layer.content,
        brandFontsEpoch,
    ])

    useLayoutEffect(() => {
        if (!autoFit || !onAutoFitFontSize) {
            return
        }
        const fitFamily = resolvedFontFamily
        const next = computeAutoFitTextFontSize(
            layer.content,
            layer.transform.width,
            layer.transform.height,
            layer.style.fontSize,
            {
                fontFamily: formatCssFontFamilyStack(fitFamily),
                fontWeight: layer.style.fontWeight,
                lineHeight: layer.style.lineHeight,
                letterSpacing: layer.style.letterSpacing,
                textAlign: layer.style.textAlign,
            },
        )
        if (Math.round(next) !== Math.round(layer.style.fontSize)) {
            onAutoFitFontSize(next)
        }
    }, [
        autoFit,
        onAutoFitFontSize,
        brandContext,
        resolvedFontFamily,
        cssFontFamilyStack,
        layer.id,
        layer.content,
        layer.transform.width,
        layer.transform.height,
        layer.style.fontSize,
        layer.style.fontFamily,
        layer.style.fontWeight,
        layer.style.lineHeight,
        layer.style.letterSpacing,
        layer.style.textAlign,
    ])

    return (
        <div className={`relative h-full min-h-0 w-full`}>
            <div
                className={`flex h-full w-full min-h-0 min-w-0 flex-row ${autoFit ? 'overflow-hidden' : ''}`}
                style={{ alignItems }}
            >
                <div
                    ref={readRef}
                    className="pointer-events-none min-h-0 min-w-0 w-full max-w-full flex-1 select-none"
                    style={{
                        ...textStyle,
                        overflow: autoFit ? 'hidden' : 'visible',
                    }}
                >
                    {layer.content}
                </div>
            </div>
        </div>
    )
}
