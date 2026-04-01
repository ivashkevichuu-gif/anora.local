import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import fs from 'fs'
import path from 'path'

function readBuildVersion() {
  try {
    const md = fs.readFileSync(path.resolve(__dirname, '../lifecycle.md'), 'utf-8')
    const m = md.match(/v(\d+\.\d+\.\d+)/)
    return m ? m[1] : '0.0.0'
  } catch { return '0.0.0' }
}

export default defineConfig({
  plugins: [react(), tailwindcss()],
  define: {
    __BUILD_VERSION__: JSON.stringify(readBuildVersion()),
  },
  build: {
    outDir: '../',
    emptyOutDir: false,
  },
})
