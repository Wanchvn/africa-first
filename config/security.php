<?php
/**
 * Qarota — security configuration.
 *
 * TRUST_PROXY_HEADERS: set to true ONLY when deployed behind a trusted
 * reverse proxy (nginx on the same machine, Cloudflare, or a load balancer).
 *
 * When false (default), client_ip() and start_secure_session() ignore
 * X-Forwarded-For and X-Forwarded-Proto entirely — an attacker can spoof
 * those headers to bypass rate limits and force insecure cookies.
 *
 * TRUSTED_PROXY_CIDRS: only used when TRUST_PROXY_HEADERS is true.
 * Defaults to localhost (for nginx on the same machine).
 * For Cloudflare, replace with Cloudflare's published IP ranges.
 */

define('TRUST_PROXY_HEADERS', false);

define('TRUSTED_PROXY_CIDRS', [
    '127.0.0.1/32',
    '::1/128',
]);
