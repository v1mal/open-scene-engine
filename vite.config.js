import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  root: 'assets/app',
  build: {
    outDir: '../../build',
    emptyOutDir: false,
    manifest: true,
    rollupOptions: {
      input: {
        app: 'assets/app/src/main.jsx'
      },
      output: {
        entryFileNames: 'assets/[name].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name][extname]'
      }
    }
  }
});
