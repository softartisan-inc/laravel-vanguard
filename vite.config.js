import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],

  publicDir: false,

  build: {
    outDir:    resolve(__dirname, 'public'),
    emptyOutDir: true,

    rollupOptions: {
      input: {
        vanguard: resolve(__dirname, 'resources/js/vanguard/app.js'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: '[name]-chunk.js',
        assetFileNames: '[name][extname]',  // → vanguard.css
      },
    },

    minify: process.env.NODE_ENV === 'production' ? 'esbuild' : false,
  },

  css: {
    // Include our CSS so Vite extracts it to vanguard.css automatically
  },

  resolve: {
    alias: { '@vanguard': resolve(__dirname, 'resources/js/vanguard') },
  },
})
