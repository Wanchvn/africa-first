CREATE TABLE conversation_members (
    conversation_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    role ENUM('member','admin') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_read_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (conversation_id, user_id),
    KEY idx_user (user_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE conversations
    ADD COLUMN is_group TINYINT(1) NOT NULL DEFAULT 0 AFTER id,
    ADD COLUMN group_name VARCHAR(100) NULL DEFAULT NULL AFTER is_group,
    ADD COLUMN group_avatar VARCHAR(255) NULL DEFAULT NULL AFTER group_name,
    ADD COLUMN created_by INT NULL DEFAULT NULL AFTER group_avatar,
    ADD INDEX idx_is_group (is_group);

INSERT INTO conversation_members (conversation_id, user_id, role)
SELECT id, user1_id, 'member' FROM conversations WHERE user1_id IS NOT NULL
UNION ALL
SELECT id, user2_id, 'member' FROM conversations WHERE user2_id IS NOT NULL
ON DUPLICATE KEY UPDATE conversation_id = conversation_id;
