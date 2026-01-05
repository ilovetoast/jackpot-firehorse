import './bootstrap'
import '../css/app.css'

import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'

const pages = import.meta.glob('./Pages/**/*.jsx', { eager: false })

createInertiaApp({
    resolve: name => {
        const path = `./Pages/${name}.jsx`
        const component = pages[path]
        if (!component) {
            throw new Error(`Page not found: ${name}`)
        }
        return component()
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />)
    },
})
