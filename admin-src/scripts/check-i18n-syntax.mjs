#!/usr/bin/env node
// Pre-build guard for vue-i18n message-compiler quirks.
//
// vue-i18n v11 uses a message compiler that reserves a handful of
// characters with special semantics inside translation strings:
//
//   '@'  → start of "linked format" syntax. ANY `@` is parsed as the
//          opening of `@:path.to.key` or `@.modifier:path`. A bare `@`
//          (think email placeholders, "@username", "@" in help text)
//          crashes the compile with INVALID_LINKED_FORMAT (code 10).
//          To emit a literal @: write `{'@'}`.
//
//   '\'' → escape introducer when followed by `{` or `}`. In Italian
//          this collides with elision: `"L'anteprima ... {save}"` is
//          parsed as a malformed literal-escape and crashes with
//          UNEXPECTED_LEXICAL_ANALYSIS (code 14). Easiest fix: use
//          the typographic apostrophe `’` (U+2019).
//
//   '|'  → top-level plural separator. Inside a single translation,
//          `|` splits forms (`zero|singular|plural`). Plain text with
//          a pipe in non-plural context throws.
//
// All of these crash AT MESSAGE COMPILE TIME, which vue-i18n triggers
// eagerly on the active locale at bundle init. A single broken string
// crashes the whole i18n setup, which in turn breaks every route that
// uses `t()` — the user sees a blank view with no obvious cause (the
// SyntaxError appears in the browser console with the numeric code as
// the message, e.g. `SyntaxError: 10`).
//
// This script scans every JSON file under `src/locales/`, flags the
// patterns above, and exits non-zero so `npm run build` fails fast.
// Run it locally before committing; CI runs it as part of `build`.
//
// Usage:  node scripts/check-i18n-syntax.mjs

import { readFileSync, readdirSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const localesDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'src', 'locales')

/** Recursively yield [path, value] for every string leaf in a JSON tree. */
function* walk(node, path = '') {
  if (node && typeof node === 'object' && !Array.isArray(node)) {
    for (const [k, v] of Object.entries(node)) {
      yield* walk(v, path ? `${path}.${k}` : k)
    }
  } else if (typeof node === 'string') {
    yield [path, node]
  }
}

/** True if a `@` at offset i is part of a valid `{'@'}` literal escape. */
function isEscaped(str, i) {
  // Look back: must be preceded by `{'`
  if (i < 2 || str[i - 1] !== "'" || str[i - 2] !== '{') return false
  // Look forward: must be followed by `'}`
  if (i + 2 >= str.length || str[i + 1] !== "'" || str[i + 2] !== '}') return false
  return true
}

/** True if the string has a `{<placeholder>}` token anywhere. */
function hasPlaceholder(str) {
  // Match `{` followed by an identifier-like word, followed by `}`.
  return /\{[A-Za-z_][A-Za-z0-9_.]*\}/.test(str)
}

const issues = []

for (const file of readdirSync(localesDir)) {
  if (!file.endsWith('.json')) continue
  const fullPath = join(localesDir, file)
  const data = JSON.parse(readFileSync(fullPath, 'utf-8'))
  for (const [path, val] of walk(data)) {
    // Rule 1: a bare `@` (not part of a `{'@'}` escape) crashes the
    //         linked-format parser.
    for (let i = 0; i < val.length; i++) {
      if (val[i] === '@' && !isEscaped(val, i)) {
        issues.push({
          file,
          path,
          rule: '@ not escaped',
          fix: "use {'@'} to emit a literal @",
          snippet: val.slice(Math.max(0, i - 25), Math.min(val.length, i + 25)),
        })
        break // one report per string is enough
      }
    }
    // Rule 2: ASCII apostrophe `'` combined with a placeholder triggers
    //         the literal-escape lexer. Typographic `’` is safe.
    if (val.includes("'") && hasPlaceholder(val)) {
      issues.push({
        file,
        path,
        rule: "' + {placeholder} (lex conflict)",
        fix: 'replace ASCII apostrophe with typographic ’ (U+2019)',
        snippet: val.slice(0, 80),
      })
    }
    // Rule 3: a `|` at the top level is interpreted as plural separator.
    //         Treat as suspicious unless the string clearly is a plural
    //         form (heuristic: contains `0 |`, `1 |`, etc.).
    if (val.includes('|') && !/\b\d+\s*\|/.test(val) && !/^\s*\w+(\s*\|\s*\w+){1,3}\s*$/.test(val)) {
      issues.push({
        file,
        path,
        rule: '| in non-plural string',
        fix: "replace with another separator (e.g. '·', '/', '—')",
        snippet: val.slice(0, 80),
      })
    }
  }
}

if (issues.length === 0) {
  console.log('✓ i18n locales: no vue-i18n syntax pitfalls detected')
  process.exit(0)
}

console.error(`✗ i18n locales: ${issues.length} issue(s) that will crash vue-i18n at runtime:\n`)
for (const it of issues) {
  console.error(`  ${it.file} · ${it.path}`)
  console.error(`    rule:    ${it.rule}`)
  console.error(`    fix:     ${it.fix}`)
  console.error(`    snippet: ${it.snippet}`)
  console.error()
}
process.exit(1)
