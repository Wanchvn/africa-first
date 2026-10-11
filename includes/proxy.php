<?php

require_once __DIR__ . '/../config/security.php';

function is_ip_in_cidr($ip, $cidr) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    $parts = explode('/', $cidr, 2);
    $network = $parts[0];
    if (!filter_var($network, FILTER_VALIDATE_IP)) {
        return false;
    }

    $ip_bytes = inet_pton($ip);
    $network_bytes = inet_pton($network);
    if ($ip_bytes === false || $network_bytes === false || strlen($ip_bytes) !== strlen($network_bytes)) {
        return false;
    }

    $max_bits = strlen($ip_bytes) * 8;
    $prefix = isset($parts[1]) ? $parts[1] : (string)$max_bits;
    if ($prefix === '' || !ctype_digit($prefix)) {
        return false;
    }

    $prefix = (int)$prefix;
    if ($prefix < 0 || $prefix > $max_bits) {
        return false;
    }

    $full_bytes = intdiv($prefix, 8);
    $remaining_bits = $prefix % 8;

    if ($full_bytes > 0 && substr($ip_bytes, 0, $full_bytes) !== substr($network_bytes, 0, $full_bytes)) {
        return false;
    }

    if ($remaining_bits > 0) {
        $mask = (0xff << (8 - $remaining_bits)) & 0xff;
        if ((ord($ip_bytes[$full_bytes]) & $mask) !== (ord($network_bytes[$full_bytes]) & $mask)) {
            return false;
        }
    }

    return true;
}

function is_trusted_proxy_ip($ip) {
    if (!TRUST_PROXY_HEADERS || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    foreach (TRUSTED_PROXY_CIDRS as $cidr) {
        if (is_ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }

    return false;
}

function request_is_from_trusted_proxy() {
    return is_trusted_proxy_ip($_SERVER['REMOTE_ADDR'] ?? '');
}

function client_ip() {
    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!request_is_from_trusted_proxy()) {
        return $remote_ip;
    }

    $forwarded_for = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($forwarded_for) && trim($forwarded_for) !== '') {
        $forwarded_ips = array_map('trim', explode(',', $forwarded_for));
        foreach ($forwarded_ips as $forwarded_ip) {
            if (!filter_var($forwarded_ip, FILTER_VALIDATE_IP)) {
                return $remote_ip;
            }
        }

        $client_ip = $remote_ip;
        foreach (array_reverse($forwarded_ips) as $forwarded_ip) {
            if (!is_trusted_proxy_ip($client_ip)) {
                break;
            }
            $client_ip = $forwarded_ip;
        }

        return $client_ip;
    }

    $real_ip = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if (is_string($real_ip) && filter_var($real_ip, FILTER_VALIDATE_IP)) {
        return $real_ip;
    }

    return $remote_ip;
}
