ALTER TABLE moderation_log
    MODIFY action_type ENUM(
        'post_removed',
        'report_dismissed',
        'user_banned',
        'message_reported'
    ) NOT NULL;
