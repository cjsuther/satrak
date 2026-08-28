import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['test/**/*.test.ts'],
    environment: 'node',
    coverage: {
      provider: 'v8',
      include: ['src/tracking/**', 'src/api/**', 'src/state/**'],
      // Las pantallas se prueban a mano en el simulador y en equipo real: sin
      // jsdom montado, medirlas acá daría una cobertura falsa.
      exclude: ['src/screens/**', 'src/components/**', 'src/main.tsx', 'src/App.tsx'],
      reporter: ['text-summary'],
    },
  },
});
