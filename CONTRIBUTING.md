# Contributing to tylio

Thanks for the interest! Contributions are welcome, especially:

- **Bug fixes** with a clear repro
- **New tile types** — see below
- **New theme presets** in `admin-src/src/presets.ts`
- **i18n improvements** (UI is currently in Italian)
- **Tests** covering non-trivial cases
- **Docs**: clarifications about install, deploy, troubleshooting

## Development setup

See the README, "Install" section. Make sure you install dev dependencies too:

```bash
composer install                # use --no-dev ONLY in production
cd admin-src && npm install
```

## Workflow

1. **Fork** + branch from `main`
2. Implement the change
3. **Run local checks**:
   ```bash
   composer check        # phpstan + phpunit
   cd admin-src && npm run build && npx vue-tsc --noEmit && cd ..
   ```
4. Open a **Pull Request** with a clear description: what changes, why, how to test it
5. CI runs phpstan + phpunit — must be green

### Code style

- **PHP**: PSR-12, strict type hints (`declare(strict_types=1)`)
- **TypeScript**: `tsc --noEmit` must pass, no implicit `any`
- **Vue**: `<script setup lang="ts">`, no Options API
- **CSS**: Tailwind utilities where practical, custom CSS in `public.css` (site) or `style.css` (admin)

No auto-formatters in the pipeline right now (no prettier). Try to match the existing style — the diff will be clearer.

## Adding a new tile

Example: a `quote` tile.

1. **Schema** in `app/Services/BlockRegistry.php`:

   ```php
   'quote' => [
       'id' => 'quote',
       'label' => 'Quote',
       'category' => 'Text',
       'icon' => 'lucide:quote',
       'description' => 'A highlighted quote with author.',
       'span' => 'full',
       'fields' => [
           ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'required' => true],
           ['key' => 'author', 'label' => 'Author', 'type' => 'text'],
       ],
   ],
   ```

2. **Template** in `app/Templates/blocks/quote.php` — server-side HTML output.

3. **Content filter** in `Renderer::blockHasContent()` so empty tiles don't render publicly:

   ```php
   case 'quote':
       return !empty($data['text']);
   ```

4. **CSS** in `app/Templates/public.css` if you need new classes.

The admin SPA reads the schema from the backend at runtime — no SPA rebuild is needed for new blocks that use existing field types.

## Migrations

Migrations live in `app/Database/migrations/NNNN_description.{sql,php}`.

### Create a new migration

```bash
php scripts/make-migration.php descriptive_name           # → NNNN_descriptive_name.sql
php scripts/make-migration.php descriptive_name --php     # → NNNN_descriptive_name.php
```

### Golden rules

1. **Don't modify an already-applied migration.** Once your migration is merged into `main` and applied in CI / production / other checkouts, it must NOT be edited. To change the schema, add a **new** migration after it.

   Editing an applied migration causes schema drift across installations and subtle bugs.

2. **Idempotency where possible.** Use `CREATE TABLE IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`, `INSERT OR IGNORE`. For `ALTER TABLE ADD COLUMN`, SQLite has no native "IF NOT EXISTS", but the runner tracks applied migrations so re-runs are skipped:

   ```sql
   -- Not idempotent on its own, but safe because the runner records
   -- the migration in the `migrations` table after success.
   ALTER TABLE users ADD COLUMN avatar_url TEXT;
   ```

3. **Automatic transactions.** Each migration runs inside a `BEGIN…COMMIT`. On failure the tracking row is not inserted, so the next run retries the migration.

4. **Numbering**: zero-padded four-digit prefix, starting at `0001`. The `make-migration.php` generator computes the next number automatically.

5. **PHP migrations** for data transforms that pure SQL can't express (e.g. read rows, JSON decode, recompute, write back). They must `return function (PDO $pdo, DB $db): void`.

### Example: add a column

```bash
php scripts/make-migration.php add_user_recovery_email
```

Edit the generated file:

```sql
-- 0010_add_user_recovery_email.sql
ALTER TABLE users ADD COLUMN recovery_email TEXT;
CREATE INDEX IF NOT EXISTS idx_users_recovery_email ON users(recovery_email)
    WHERE recovery_email IS NOT NULL;
```

Apply:

```bash
php scripts/migrate.php           # apply pending
php scripts/migrate.php status    # verify
```

### Example: data migration in PHP

```bash
php scripts/make-migration.php recalc_block_positions --php
```

```php
<?php
return function (PDO $pdo, DB $db): void {
    $rows = $db->all('SELECT id FROM blocks ORDER BY position ASC, id ASC');
    $pos = 10;
    foreach ($rows as $row) {
        $db->update('blocks', ['position' => $pos], 'id = :id', ['id' => $row['id']]);
        $pos += 10;
    }
};
```

## Reporting a bug

Open a GitHub issue with:

- Version (commit hash or tag)
- PHP version + installed extensions (`php -m`)
- Browser/device for UI issues
- Steps to reproduce
- Error log in `data/logs/php-error.log` if relevant

## Security vulnerabilities

**Do not open public issues for security vulnerabilities.** See [SECURITY.md](SECURITY.md).

## License

By contributing you agree that your contribution is released under the same MIT license as the project.
