<?php

/**
 * Convert a topic name into a URL-safe slug.
 */
function topic_slug($name) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

/**
 * Get a topic by slug. Returns null if not found.
 */
function get_topic_by_slug($pdo, $slug) {
    $stmt = $pdo->prepare("
        SELECT t.*, u.username AS curator_username, u.display_name AS curator_display_name
        FROM topics t
        INNER JOIN users u ON t.curator_id = u.id
        WHERE t.slug = :slug
    ");
    $stmt->execute([':slug' => $slug]);
    return $stmt->fetch() ?: null;
}

/**
 * Check if a user is a member of a topic.
 */
function is_topic_member($pdo, $topic_id, $user_id) {
    $stmt = $pdo->prepare("SELECT 1 FROM topic_members WHERE topic_id = :t AND user_id = :u");
    $stmt->execute([':t' => $topic_id, ':u' => $user_id]);
    return (bool)$stmt->fetch();
}

/**
 * Get the count of members in a topic.
 */
function topic_member_count($pdo, $topic_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM topic_members WHERE topic_id = :t");
    $stmt->execute([':t' => $topic_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Get the count of posts in a topic.
 */
function topic_post_count($pdo, $topic_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_topics WHERE topic_id = :t");
    $stmt->execute([':t' => $topic_id]);
    return (int)$stmt->fetchColumn();
}