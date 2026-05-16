-- Initial tylio schema.

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    csrf_token TEXT NOT NULL,
    user_agent TEXT,
    ip TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    last_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    username TEXT,
    success INTEGER NOT NULL DEFAULT 0,
    attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts(ip, attempted_at);

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    resource TEXT,
    metadata TEXT,
    ip TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at);

-- Settings: global key/value JSON store (site title, SEO, contacts, etc.)
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Theme: a single row, global theme options (palette, fonts, etc.)
CREATE TABLE IF NOT EXISTS theme (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    data TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Blocks: modular composition of the public page
CREATE TABLE IF NOT EXISTS blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    enabled INTEGER NOT NULL DEFAULT 1,
    data TEXT NOT NULL DEFAULT '{}',
    style TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_blocks_position ON blocks(position);

-- Media: files uploaded by the admin (under public/uploads/)
CREATE TABLE IF NOT EXISTS media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    original_name TEXT NOT NULL,
    mime TEXT NOT NULL,
    size INTEGER NOT NULL,
    width INTEGER,
    height INTEGER,
    uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Visits (simple counter, no cookies/tracking)
CREATE TABLE IF NOT EXISTS visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    day TEXT NOT NULL,
    referer TEXT,
    user_agent TEXT,
    block_id INTEGER REFERENCES blocks(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_visits_day ON visits(day);

-- Submissions from the contact / newsletter form
CREATE TABLE IF NOT EXISTS submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    block_id INTEGER REFERENCES blocks(id) ON DELETE SET NULL,
    type TEXT NOT NULL,
    payload TEXT NOT NULL,
    ip TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Default theme
INSERT OR IGNORE INTO theme (id, data) VALUES (1, json_object(
    'palette', json_object(
        'name', 'terra',
        'bg', '#0f0d0a',
        'surface', '#1a1612',
        'surface_alt', '#221c17',
        'text', '#f4ede1',
        'text_muted', '#9c8e7c',
        'accent', '#d4a574',
        'accent_alt', '#e8c598',
        'border', 'rgba(244,237,225,0.08)'
    ),
    'font', json_object(
        'heading', 'Fraunces',
        'body', 'Inter'
    ),
    'tile', json_object(
        'radius', 18,
        'gap', 14,
        'border', 1,
        'shadow', 'soft',
        'tessellate', 1,
        'mobile_spacing', 'minimal'
    ),
    'background', json_object(
        'pattern', 'mosaic',
        'intensity', 0.06
    ),
    'mode', 'auto'
));

-- Default settings.
-- `site.locale` is left empty: the public site falls back to the locale
-- negotiated from `Accept-Language` (and finally to English). Set an
-- explicit value from the admin UI to lock the public site to one
-- language regardless of the visitor's browser.
INSERT OR IGNORE INTO settings (key, value) VALUES
    ('site.title', json_quote('tylio')),
    ('site.tagline', json_quote('Your home, one tile at a time.')),
    ('site.description', json_quote('A modular personal page built with tylio.')),
    ('site.author', json_quote('')),
    ('site.locale', json_quote('')),
    ('seo.og_image', json_quote('')),
    ('seo.favicon', json_quote(''));
