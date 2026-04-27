import { useLayoutEffect, useRef, useState } from 'react'
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react'
import {
    Bars3Icon,
    DocumentTextIcon,
    EllipsisVerticalIcon,
    FilmIcon,
    PaintBrushIcon,
    PhotoIcon,
    ScissorsIcon,
    SparklesIcon,
    Square2StackIcon,
    TrashIcon,
} from '@heroicons/react/24/outline'
import { ChevronDoubleDownIcon, ChevronDoubleUpIcon } from '@heroicons/react/24/outline'
import { EyeIcon, EyeSlashIcon } from '@heroicons/react/24/outline'
import { LockClosedIcon, LockOpenIcon } from '@heroicons/react/24/outline'
import type { Layer } from '../../documentModel'
import { isGenerativeImageLayer, isImageLayer, isMaskLayer, isTextLayer, isVideoLayer } from '../../documentModel'
import { StudioIconButton } from './StudioIconButton'
import { studioPanelSurfaces } from './studioPanelUi'

function layerTypeLabel(layer: Layer): string {
    if (isGenerativeImageLayer(layer)) return 'AI Image'
    if (isImageLayer(layer)) return 'Image'
    if (isTextLayer(layer)) return 'Text'
    if (isVideoLayer(layer)) return 'Video'
    if (isMaskLayer(layer)) return 'Mask'
    if (layer.type === 'fill') return 'Fill'
    return (layer as Layer).type
}

function LayerTypeIcon({ layer, className }: { layer: Layer; className: string }) {
    const cls = className
    if (isGenerativeImageLayer(layer)) return <SparklesIcon className={cls} aria-hidden />
    if (isImageLayer(layer)) return <PhotoIcon className={cls} aria-hidden />
    if (isTextLayer(layer)) return <DocumentTextIcon className={cls} aria-hidden />
    if (isVideoLayer(layer)) return <FilmIcon className={cls} aria-hidden />
    if (isMaskLayer(layer)) return <ScissorsIcon className={cls} aria-hidden />
    if (layer.type === 'fill') return <PaintBrushIcon className={cls} aria-hidden />
    return <Bars3Icon className={cls} aria-hidden />
}

type LayerHeaderDensity = 'default' | 'cozy' | 'compact'

function useLayerHeaderToolbarDensity() {
    const ref = useRef<HTMLDivElement>(null)
    const [density, setDensity] = useState<LayerHeaderDensity>('default')

    useLayoutEffect(() => {
        const el = ref.current
        if (!el || typeof ResizeObserver === 'undefined') {
            return
        }
        const apply = (width: number) => {
            if (width < 252) {
                setDensity('compact')
            } else if (width < 360) {
                setDensity('cozy')
            } else {
                setDensity('default')
            }
        }
        apply(el.getBoundingClientRect().width)
        const ro = new ResizeObserver((entries) => {
            const w = entries[0]?.contentRect.width
            if (w != null) {
                apply(w)
            }
        })
        ro.observe(el)
        return () => {
            ro.disconnect()
        }
    }, [])

    return { ref, density }
}

export function StudioLayerHeader({
    layer,
    name,
    onNameChange,
    onToggleVisible,
    onToggleLock,
    onDuplicate,
    onDelete,
    onBringToFront,
    onSendToBack,
    disabled,
}: {
    layer: Layer
    name: string
    onNameChange: (v: string) => void
    onToggleVisible: () => void
    onToggleLock: () => void
    onDuplicate: () => void
    onDelete: () => void
    onBringToFront: () => void
    onSendToBack: () => void
    disabled?: boolean
}) {
    const locked = layer.locked || disabled
    const { ref: densityRef, density } = useLayerHeaderToolbarDensity()
    const btnSize = density === 'default' ? 'lg' : density === 'cozy' ? 'md' : 'sm'
    const typeBox =
        density === 'default' ? 'h-8 w-8' : density === 'cozy' ? 'h-7 w-7' : 'h-6 w-6'
    const typeGlyph =
        density === 'default' ? 'h-4 w-4' : density === 'cozy' ? 'h-3.5 w-3.5' : 'h-3 w-3'
    const actionGlyph =
        density === 'default' ? 'h-5 w-5' : density === 'cozy' ? 'h-4 w-4' : 'h-3.5 w-3.5'
    const moreMenuBtn =
        density === 'default'
            ? 'h-10 w-10'
            : density === 'cozy'
              ? 'h-8 w-8'
              : 'h-7 w-7'
    const toolbarGap = density === 'default' ? 'gap-1.5' : density === 'cozy' ? 'gap-1' : 'gap-0.5'

    const toolbar = (
        <div
            className={`flex min-w-0 flex-wrap items-center justify-end ${toolbarGap} ${
                density === 'compact' ? 'w-full pt-0' : 'pt-0.5'
            }`}
            role="toolbar"
            aria-label="Layer actions"
        >
                    <StudioIconButton
                        size={btnSize}
                        title={layer.visible ? 'Hide layer' : 'Show layer'}
                        aria-label={layer.visible ? 'Hide layer' : 'Show layer'}
                        active={layer.visible}
                        disabled={disabled}
                        onClick={onToggleVisible}
                    >
                        {layer.visible ? (
                            <EyeIcon className={actionGlyph} />
                        ) : (
                            <EyeSlashIcon className={actionGlyph} />
                        )}
                    </StudioIconButton>
                    <StudioIconButton
                        size={btnSize}
                        title={layer.locked ? 'Unlock layer' : 'Lock layer'}
                        aria-label={layer.locked ? 'Unlock layer' : 'Lock layer'}
                        active={layer.locked}
                        disabled={disabled}
                        onClick={onToggleLock}
                    >
                        {layer.locked ? (
                            <LockClosedIcon className={actionGlyph} />
                        ) : (
                            <LockOpenIcon className={actionGlyph} />
                        )}
                    </StudioIconButton>
                    <StudioIconButton
                        size={btnSize}
                        title="Duplicate layer"
                        aria-label="Duplicate layer"
                        disabled={disabled}
                        onClick={onDuplicate}
                    >
                        <Square2StackIcon className={actionGlyph} />
                    </StudioIconButton>
                    <StudioIconButton
                        size={btnSize}
                        title="Delete layer"
                        aria-label="Delete layer"
                        subtleDanger
                        disabled={disabled}
                        onClick={onDelete}
                    >
                        <TrashIcon className={actionGlyph} />
                    </StudioIconButton>
                    <Menu as="div" className="relative">
                        <MenuButton
                            type="button"
                            disabled={disabled}
                            className={`inline-flex items-center justify-center rounded-md border border-gray-800/90 bg-gray-900/35 text-gray-300 transition-colors hover:border-gray-700 hover:bg-gray-800/50 hover:text-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400/45 disabled:cursor-not-allowed disabled:opacity-40 ${moreMenuBtn}`}
                            title="More layer actions"
                            aria-label="More layer actions"
                        >
                            <EllipsisVerticalIcon className={actionGlyph} aria-hidden />
                        </MenuButton>
                        <MenuItems
                            transition
                            className="absolute right-0 z-20 mt-1 w-44 origin-top-right rounded-lg border border-gray-700 bg-gray-900 py-1 text-[11px] text-gray-200 shadow-xl ring-1 ring-black/40 transition data-closed:scale-95 data-closed:opacity-0"
                        >
                            <MenuItem>
                                {({ focus }) => (
                                    <button
                                        type="button"
                                        className={`flex w-full items-center gap-2 px-2 py-1.5 text-left ${focus ? 'bg-white/[0.04]' : ''}`}
                                        onClick={onBringToFront}
                                    >
                                        <ChevronDoubleUpIcon className="h-4 w-4 text-gray-400" aria-hidden />
                                        Bring to front
                                    </button>
                                )}
                            </MenuItem>
                            <MenuItem>
                                {({ focus }) => (
                                    <button
                                        type="button"
                                        className={`flex w-full items-center gap-2 px-2 py-1.5 text-left ${focus ? 'bg-white/[0.04]' : ''}`}
                                        onClick={onSendToBack}
                                    >
                                        <ChevronDoubleDownIcon className="h-4 w-4 text-gray-400" aria-hidden />
                                        Send to back
                                    </button>
                                )}
                            </MenuItem>
                        </MenuItems>
                    </Menu>
        </div>
    )

    const nameAndType = (
        <>
            <div
                className={`mt-0.5 flex shrink-0 items-center justify-center rounded-lg border border-gray-700 bg-gray-800/50 text-gray-100 shadow-inner ring-1 ring-inset ring-black/20 ${typeBox}`}
                title={layerTypeLabel(layer)}
            >
                <LayerTypeIcon layer={layer} className={typeGlyph} />
            </div>
            <div className="min-w-0 flex-1 pt-0.5">
                <input
                    type="text"
                    value={name}
                    disabled={locked}
                    onChange={(e) => onNameChange(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            ;(e.target as HTMLInputElement).blur()
                        }
                    }}
                    className="w-full rounded-md border border-transparent bg-transparent px-1 py-0.5 text-[15px] font-semibold leading-tight text-gray-100 placeholder:text-gray-500 hover:border-gray-700 focus:border-indigo-400/40 focus:outline-none focus:ring-1 focus:ring-indigo-400/30 disabled:opacity-50"
                    placeholder="Layer name"
                />
            </div>
        </>
    )

    return (
        <div className={studioPanelSurfaces.layerAnchor}>
            {density === 'compact' ? (
                <div ref={densityRef} className="flex min-w-0 flex-col gap-1.5">
                    <div className="flex min-w-0 items-start gap-2.5">{nameAndType}</div>
                    {toolbar}
                </div>
            ) : (
                <div ref={densityRef} className="flex min-w-0 items-start gap-2.5">
                    {nameAndType}
                    {toolbar}
                </div>
            )}
        </div>
    )
}
