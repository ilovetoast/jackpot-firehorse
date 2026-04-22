import { useCallback, useEffect, useRef, useState } from 'react'
import { PencilSquareIcon, XMarkIcon } from '@heroicons/react/24/outline'

export interface EditorTextInputOptions {
    title: string
    message?: string
    label?: string
    initialValue: string
    confirmText?: string
    cancelText?: string
    placeholder?: string
}

interface TextInputState extends EditorTextInputOptions {
    open: boolean
}

export function useEditorTextInput() {
    const [state, setState] = useState<TextInputState>({
        open: false,
        title: '',
        initialValue: '',
    })
    const resolveRef = useRef<((value: string | null) => void) | null>(null)

    const promptForText = useCallback((opts: EditorTextInputOptions): Promise<string | null> => {
        return new Promise<string | null>((resolve) => {
            resolveRef.current = resolve
            setState({ ...opts, open: true })
        })
    }, [])

    const handleClose = useCallback((result: string | null) => {
        setState((prev) => ({ ...prev, open: false }))
        resolveRef.current?.(result)
        resolveRef.current = null
    }, [])

    return { textInputState: state, promptForText, handleTextInputClose: handleClose }
}

interface EditorTextInputDialogProps {
    state: TextInputState
    onClose: (result: string | null) => void
}

export default function EditorTextInputDialog({ state, onClose }: EditorTextInputDialogProps) {
    const [draft, setDraft] = useState('')

    useEffect(() => {
        if (state.open) {
            setDraft(state.initialValue)
        }
    }, [state.open, state.initialValue])

    if (!state.open) {
        return null
    }

    const confirmText = state.confirmText ?? 'Save'
    const cancelText = state.cancelText ?? 'Cancel'

    return (
        <div
            className="fixed inset-0 z-[61] flex items-center justify-center"
            onKeyDown={(e) => {
                if (e.key === 'Escape') {
                    onClose(null)
                }
            }}
        >
            <div
                className="absolute inset-0 bg-black/60 backdrop-blur-sm"
                onClick={() => onClose(null)}
            />

            <div className="relative mx-4 w-full max-w-md animate-[jp-text-dialog-enter_0.15s_ease-out] overflow-hidden rounded-xl border border-gray-700 bg-gray-900 shadow-2xl ring-1 ring-white/5">
                <style>{`@keyframes jp-text-dialog-enter { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }`}</style>

                <div className="flex items-start gap-3 px-5 pt-5 pb-0">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-500/20">
                        <PencilSquareIcon className="h-5 w-5 text-indigo-400" />
                    </div>
                    <div className="min-w-0 flex-1 pt-0.5">
                        <h3 className="text-sm font-semibold text-white">{state.title}</h3>
                        {state.message ? (
                            <p className="mt-1 text-[13px] leading-relaxed text-gray-400">{state.message}</p>
                        ) : null}
                    </div>
                    <button
                        type="button"
                        onClick={() => onClose(null)}
                        className="shrink-0 rounded-md p-1 text-gray-500 transition-colors hover:bg-gray-800 hover:text-gray-300"
                    >
                        <XMarkIcon className="h-4 w-4" />
                    </button>
                </div>

                <div className="px-5 pb-2 pt-4">
                    {state.label ? (
                        <label htmlFor="jp-editor-text-input" className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                            {state.label}
                        </label>
                    ) : null}
                    <input
                        id="jp-editor-text-input"
                        type="text"
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault()
                                onClose(draft)
                            }
                        }}
                        placeholder={state.placeholder}
                        className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-white shadow-inner placeholder:text-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        autoFocus
                    />
                </div>

                <div className="flex items-center justify-end gap-2 px-5 pb-4 pt-2">
                    <button
                        type="button"
                        onClick={() => onClose(null)}
                        className="rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2 text-xs font-semibold text-gray-300 transition-colors hover:bg-gray-700 hover:text-white"
                    >
                        {cancelText}
                    </button>
                    <button
                        type="button"
                        onClick={() => onClose(draft)}
                        className="rounded-lg bg-indigo-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-indigo-500"
                    >
                        {confirmText}
                    </button>
                </div>
            </div>
        </div>
    )
}
