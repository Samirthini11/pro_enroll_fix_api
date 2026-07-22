-- Presence for online listing: stale pros auto-hide from customer search.
ALTER TABLE professionals
  ADD COLUMN last_seen_at DATETIME NULL AFTER is_available;

-- Existing online pros: seed last_seen so they expire if app never heartbeats.
UPDATE professionals
SET last_seen_at = NOW()
WHERE is_available = 1 AND last_seen_at IS NULL;
