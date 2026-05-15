-- Group blocks. A block can now have a `parent_id` pointing at another
-- block of type 'group' — the group is a logical container with no
-- public HTML (no margin, no padding, no chrome): it only tells the
-- layout planner that its children must share a single mosaic column,
-- so a sibling on the other column can stretch its height to match the
-- sum of the children's heights (CSS Grid auto-rows + align-self stretch).
--
-- ON DELETE SET NULL: deleting a group detaches its children back to
-- top-level instead of cascading the delete. Decision 2026-05-15: less
-- surprising for the user — content is never lost.
--
-- `position` semantics: now scoped to (tenant_id, parent_id). Top-level
-- blocks have parent_id=NULL and order among themselves; group children
-- order within their group. The reorder API namespaces by parent_id.
--
-- Validation enforced server-side (BlocksController):
--   * a block with parent_id != NULL must have a parent of type='group'
--   * a group cannot have parent_id != NULL (no nested groups, for now)

ALTER TABLE blocks ADD COLUMN parent_id INTEGER NULL REFERENCES blocks(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_blocks_parent ON blocks(parent_id);
