import { useEditor, EditorContent } from '@tiptap/react'
import { BubbleMenu } from '@tiptap/react/menus'
import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import { useEffect, useRef } from 'react'

export default function WysiwygField({ value, onChange, placeholder, className = '' }) {
    const debounceRef = useRef(null)

    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                heading: false,
                bulletList: false,
                orderedList: false,
                codeBlock: false,
                code: false,
                blockquote: false,
                horizontalRule: false,
            }),
            Link.configure({
                openOnClick: false,
                HTMLAttributes: { class: 'text-indigo-600 underline' },
            }),
        ],
        content: value || '',
        editorProps: {
            attributes: {
                class: `prose prose-sm max-w-none focus:outline-none min-h-[2rem] ${className}`,
                ...(placeholder ? { 'data-placeholder': placeholder } : {}),
            },
        },
        onUpdate: ({ editor: ed }) => {
            if (debounceRef.current) clearTimeout(debounceRef.current)
            debounceRef.current = setTimeout(() => {
                const html = ed.getHTML()
                onChange?.(html === '<p></p>' ? '' : html)
            }, 300)
        },
    })

    useEffect(() => {
        if (editor && value !== undefined) {
            const currentHtml = editor.getHTML()
            const normalized = value || ''
            if (currentHtml !== normalized && normalized !== (currentHtml === '<p></p>' ? '' : currentHtml)) {
                editor.commands.setContent(normalized, false)
            }
        }
    }, [value, editor])

    useEffect(() => {
        return () => {
            if (debounceRef.current) clearTimeout(debounceRef.current)
        }
    }, [])

    if (!editor) return null

    return (
        <div className="relative">
            <BubbleMenu editor={editor} tippyOptions={{ duration: 150 }} className="flex items-center gap-0.5 bg-gray-900 rounded-lg shadow-xl px-1 py-0.5">
                <button
                    type="button"
                    onClick={() => editor.chain().focus().toggleBold().run()}
                    className={`px-2 py-1 text-xs font-bold rounded transition-colors ${editor.isActive('bold') ? 'bg-white/20 text-white' : 'text-gray-300 hover:text-white'}`}
                >
                    B
                </button>
                <button
                    type="button"
                    onClick={() => editor.chain().focus().toggleItalic().run()}
                    className={`px-2 py-1 text-xs italic rounded transition-colors ${editor.isActive('italic') ? 'bg-white/20 text-white' : 'text-gray-300 hover:text-white'}`}
                >
                    I
                </button>
            </BubbleMenu>
            <EditorContent editor={editor} />
        </div>
    )
}
