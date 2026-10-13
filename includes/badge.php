<?php
/**
 * Render a verification badge next to a username.
 *
 * @param string|null $verification_type  One of 'none'|'personal'|'organization'|'staff'
 * @param int         $size               Pixel size of the icon (default 14)
 * @return string     HTML — empty string if not verified
 */
function verification_badge(?string $verification_type, int $size = 14): string
{
    $type = $verification_type ?? 'none';
    if ($type === 'none' || $type === '') {
        return '';
    }

    $meta = [
        'personal'     => ['color' => '#C65D3B', 'title' => 'Verified — identity confirmed'],
        'organization' => ['color' => '#5A7A52', 'title' => 'Verified organization'],
        'staff'        => ['color' => '#C9A961', 'title' => 'Qarota team'],
    ];

    $color = $meta[$type]['color'] ?? '#C65D3B';
    $title = $meta[$type]['title'] ?? 'Verified';

    return sprintf(
        '<svg class="verified-badge" xmlns="http://www.w3.org/2000/svg" '
        . 'viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="%2$s" '
        . 'role="img" aria-label="%3$s"><title>%3$s</title>'
        . '<path d="M12 1.5l2.6 2.3 3.4-.4 1.4 3.1 3.1 1.4-.4 3.4 2.3 2.6-2.3 2.6.4 3.4-3.1 1.4-1.4 3.1-3.4-.4L12 22.5l-2.6-2.3-3.4.4-1.4-3.1-3.1-1.4.4-3.4L-.4 12l2.3-2.6-.4-3.4 3.1-1.4 1.4-3.1 3.4.4z"/>'
        . '<path d="M10.6 15.2l-3.3-3.3 1.4-1.4 1.9 1.9 4.9-4.9 1.4 1.4z" fill="#FFFFFF"/></svg>',
        $size,
        $color,
        htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
    );
}