import eslint from '@eslint/js'
import tseslint from 'typescript-eslint'
import vue from 'eslint-plugin-vue'
import vueParser from 'vue-eslint-parser'
import prettierConfig from 'eslint-config-prettier'
import globals from 'globals'

export default tseslint.config(
  {
    ignores: ['dist/', 'node_modules/', '../public/admin/', '*.config.js', '*.config.ts'],
  },
  eslint.configs.recommended,
  ...tseslint.configs.recommended,
  ...vue.configs['flat/recommended'],
  {
    languageOptions: {
      globals: {
        ...globals.browser,
      },
    },
  },
  {
    files: ['**/*.vue'],
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        parser: tseslint.parser,
        extraFileExtensions: ['.vue'],
        ecmaVersion: 'latest',
        sourceType: 'module',
      },
    },
  },
  {
    rules: {
      // Tradeoff temporaneo: il payload dei blocchi è ancora `Record<string, any>`
      // (vedi PR-4 per discriminated unions). Tieni come warn, non error.
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
      // Le view sono single-word (Login, Settings, Theme…) — convenzione del progetto.
      'vue/multi-word-component-names': 'off',
      // Permette `<script setup>` senza top-level await constraints rigide.
      'vue/no-v-html': 'warn',
    },
  },
  // Prettier ULTIMO: disabilita le rule che entrano in conflitto col formatter.
  prettierConfig,
)
