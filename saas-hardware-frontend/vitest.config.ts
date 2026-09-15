import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

// Tests de frontend (INF-5). Aparte de `vite.config.ts` para que el build no
// cargue nada de Vitest.
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
  },
})
