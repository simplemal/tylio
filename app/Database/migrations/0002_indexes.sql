-- Additional indexes on columns frequently used in WHERE / JOIN / ORDER.
-- At low volume the impact isn't noticeable; above a few thousand rows the
-- difference becomes visible (LEFT JOIN visits, ORDER BY created_at, audit per user).

-- submissions: the admin lists by date (DESC) and filters by block
CREATE INDEX IF NOT EXISTS idx_submissions_created ON submissions(created_at);
CREATE INDEX IF NOT EXISTS idx_submissions_block ON submissions(block_id);

-- media: the library shows ORDER BY id DESC; reserved for a future filter-by-date
CREATE INDEX IF NOT EXISTS idx_media_created ON media(created_at);

-- visits: per-block stats do LEFT JOIN visits ON v.block_id = b.id
CREATE INDEX IF NOT EXISTS idx_visits_block ON visits(block_id);

-- audit_log: filtering by user is frequent (who did what)
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_log(user_id);
