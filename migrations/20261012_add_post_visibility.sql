ALTER TABLE posts
    ADD COLUMN visibility ENUM('public', 'followers') NOT NULL DEFAULT 'public',
    ADD INDEX idx_visibility (visibility);
