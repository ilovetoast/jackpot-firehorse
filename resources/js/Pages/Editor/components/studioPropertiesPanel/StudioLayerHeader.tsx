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

function LayerTypeIcon({ layer }: { layer: Layer }) {
    const cls = 'h-6 w-6'
    if (isGenerativeImageLayer(layer)) return <SparklesIcon className={cls} aria-hidden />
    if (isImageLayer(layer)) return <PhotoIcon className={cls} aria-hidden />
    if (isTextLayer(layer)) return <DocumentTextIcon className={cls} aria-hidden />
    if (isVideoLayer(layer)) return <FilmIcon className={cls} aria-hidden />
    if (isMaskLayer(layer)) return <ScissorsIcon className={cls} aria-hidden />
    if (layer.type === 'fill') return <PaintBrushIcon className={cls} aria-hidden />
    return <Bars3Icon className={cls} aria-hidden />
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
    return (
        <div className={studioPanelSurfaces.layerAnchor}>
            <div className="flex items-start gap-3">
                <div
                    className="mt-0.5 flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-gray-700 bg-gray-800/50 text-gray-100 shadow-inner ring-1 ring-inset ring-black/20"
                    title={layerTypeLabel(layer)}
                >
                    <LayerTypeIcon layer={layer} />
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
                        placeholder={layerTypeLabel(layer)}
                    />
                    <p className="mt-1.5">
                        <span className="inline-flex items-center rounded-md border border-gray-700 bg-gray-800/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-300">
                            {layerTypeLabel(layer)}
                        </span>
                    </p>
                </div>
                <div
                    className="flex shrink-0 flex-wrap items-center justify-end gap-1.5 pt-0.5"
                    role="toolbar"
                    aria-label="Layer actions"
                >
                    <StudioIconButton
                        size="lg"
                        title={layer.visible ? 'Hide layer' : 'Show layer'}
                        aria-label={layer.visible ? 'Hide layer' : 'Show layer'}
                        active={layer.visible}
                        disabled={disabled}
                        onClick={onToggleVisible}
                    >
                        {layer.visible ? <EyeIcon className="h-5 w-5" /> : <EyeSlashIcon className="h-5 w-5" />}
                    </StudioIconButton>
                    <StudioIconButton
                        size="lg"
                        title={layer.locked ? 'Unlock layer' : 'Lock layer'}
                        aria-label={layer.locked ? 'Unlock layer' : 'Lock layer'}
                        active={layer.locked}
                        disabled={disabled}
                        onClick={onToggleLock}
                    >
                        {layer.locked ? (
                            <LockClosedIcon className="h-5 w-5" />
                        ) : (
                            <LockOpenIcon className="h-5 w-5" />
                        )}
                    </StudioIconButton>
                    <StudioIconButton
                        size="lg"
                        title="Duplicate layer"
                        aria-label="Duplicate layer"
                        disabled={disabled}
                        onClick={onDuplicate}
                    >
                        <Square2StackIcon className="h-5 w-5" />
                    </StudioIconButton>
                    <StudioIconButton
                        size="lg"
                        title="Delete layer"
                        aria-label="Delete layer"
                        subtleDanger
                        disabled={disabled}
                        onClick={onDelete}
                    >
                        <TrashIcon className="h-5 w-5" />
                    </StudioIconButton>
                    <Menu as="div" className="relative">
                        <MenuButton
                            type="button"
                            disabled={disabled}
                            className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-gray-800/90 bg-gray-900/35 text-gray-300 transition-colors hover:border-gray-700 hover:bg-gray-800/50 hover:text-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400/45 disabled:cursor-not-allowed disabled:opacity-40"
                            title="More layer actions"
                            aria-label="More layer actions"
                        >
                            <EllipsisVerticalIcon className="h-5 w-5" aria-hidden />
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
            </div>
        </div>
    )
}
