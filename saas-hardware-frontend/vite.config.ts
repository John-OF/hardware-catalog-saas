import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig(({ command, mode }) => ({
  plugins: [react()],
  define: {
    // Cuentas de prueba de los logins, sólo con `npm run dev` (utils/cuentasDePrueba.ts).
    // La variable no lleva el prefijo VITE_ a propósito: así nunca entra en
    // `import.meta.env`, que acaba entero en el bundle. Sólo llega por aquí, y en
    // el build (`command === 'build'`) vale vacío esté en el archivo que esté.
    __CUENTAS_DE_PRUEBA__: JSON.stringify(
      command === 'serve' ? (loadEnv(mode, process.cwd(), '').CUENTAS_DE_PRUEBA ?? '') : '',
    ),
  },
}))
