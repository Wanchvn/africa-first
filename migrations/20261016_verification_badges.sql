ALTER TABLE users
    ADD COLUMN verification_type ENUM('none','personal','organization','staff')
        NOT NULL DEFAULT 'none' AFTER is_admin,
    ADD COLUMN verified_at DATETIME NULL AFTER verification_type,
    ADD COLUMN verified_by INT NULL AFTER verified_at;

ALTER TABLE moderation_log
    MODIFY COLUMN action_type ENUM(
        'post_removed',
        'report_dismissed',
        'user_banned',
        'user_unbanned',
        'message_reported',
        'appeal_approved',
        'appeal_rejected',
        'user_verified',
        'user_unverified'
    ) NOT NULL;
