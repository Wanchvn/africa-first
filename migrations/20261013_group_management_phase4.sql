ALTER TABLE messages
    MODIFY COLUMN message_type ENUM('text','voice','image','video','system')
    NOT NULL DEFAULT 'text';
