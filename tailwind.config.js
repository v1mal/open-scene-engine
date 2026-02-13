/** @type {import('tailwindcss').Config} */
export default {
  content: ['./assets/app/src/**/*.{js,jsx,ts,tsx}'],
  prefix: 'ose-',
  important: '#openscene-root',
  corePlugins: {
    preflight: false
  },
  theme: {
    extend: {
      colors: {
        obsidian: '#000000',
        ink: '#0a0a0a',
        neon: '#0df2cc',
        electric: '#bc13fe',
        muted: '#9aa3a8',
        line: '#1a1a1a'
      },
      fontFamily: {
        sans: ['Spline Sans', 'Inter', 'system-ui', 'sans-serif']
      }
    }
  },
  plugins: []
};
