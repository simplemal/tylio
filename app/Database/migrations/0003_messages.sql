-- Read-status tracking + email-forwarding state for contact-form
-- submissions. Pre-existing rows keep read_at=NULL (= "unread") and
-- mail_status=NULL (= "no attempt recorded"), so the unread badge also
-- reflects the historical backlog the first time this migration is deployed.
--
-- No indexes here: 0003 runs BEFORE the SaaS 1xxx migrations (alphabetical
-- sort), so tenant_id might not exist yet; and on standalone OSS tenant_id
-- never exists at all. At normal volumes (<200 rows per tenant) the filter
-- `WHERE read_at IS NULL` is already sub-ms without an index.
ALTER TABLE submissions ADD COLUMN read_at TEXT;
ALTER TABLE submissions ADD COLUMN mail_status TEXT;
ALTER TABLE submissions ADD COLUMN mail_error TEXT;
