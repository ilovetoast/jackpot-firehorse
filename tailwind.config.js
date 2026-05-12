import typography from '@tailwindcss/typography'

/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.jsx",
    // `.ts` / `.tsx` — the editor (AssetEditor.tsx, GridOverlay.tsx, PlacementPicker.tsx,
    // EditorConfirmDialog.tsx, FillGradientStopField.tsx) uses utility classes like
    // `border-gray-600` / `bg-gray-700/50` that aren't used anywhere else in the repo.
    // Without scanning .tsx, those rules never get generated and elements fall back to
    // the preflight / currentColor border, which inherits the brand accent and paints
    // red/pink in the Create-from-Template modal thumbnails.
    "./resources/**/*.ts",
    "./resources/**/*.tsx",
  ],
  // Belt-and-braces against the "white border / bright chrome" regression.
  // The editor (AssetEditor.tsx + friends) is the main consumer of these dark
  // utilities, and if the content scan ever regresses again (new file type,
  // a renamed .tsx moved outside resources/, stale build cache, etc.) the
  // properties panel falls back to Tailwind preflight's `currentColor` borders
  // and renders bright white against the dark panel. Keeping these explicit
  // costs ~1KB and makes the purge deterministic.
  safelist: [
    { pattern: /^border-gray-(500|600|700|800)$/ },
    { pattern: /^bg-gray-(700|800|900|950)(\/\d+)?$/ },
    { pattern: /^text-gray-(100|200|300|400)$/ },
    { pattern: /^divide-gray-(700|800)$/ },
  ],
  theme: {
    extend: {
      fontFamily: {
        display: ['"Proxima Nova"', 'ui-sans-serif', 'system-ui', '-apple-system', 'sans-serif'],
      },
      colors: {
        primary: 'var(--primary)',
        secondary: 'var(--secondary)',
        accent: 'var(--accent)',
      },
      maxWidth: {
        /** Site admin: ~1600px cap, centered — not full-bleed on ultra-wide; respects small viewports. */
        'admin-shell': 'min(1600px, calc(100vw - 2rem))',
      },
      keyframes: {
        'fade-slide-in': {
          '0%': { opacity: '0', transform: 'translateY(2px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
      },
      animation: {
        'fade-slide-in': 'fade-slide-in 200ms ease-out',
      },
    },
  },
  plugins: [typography],
}
