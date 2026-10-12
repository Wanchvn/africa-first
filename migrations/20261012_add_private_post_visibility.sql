ALTER TABLE posts
    MODIFY COLUMN visibility ENUM('public', 'followers', 'private') NOT NULL DEFAULT 'public';
