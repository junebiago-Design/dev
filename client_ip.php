<?php

if (!function_exists('getClientIp')) {
    function getClientIp(): string
    {
        /*
         * Kapag tama ang Nginx Cloudflare real-IP configuration,
         * REMOTE_ADDR ay original visitor IP na.
         */
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return 'unknown';
    }
}
