<?php
/**
 * Plugin Name: ESN Bounce Watcher
 * Description: Telt en registreert ongeopende "Undelivered Mail Returned to Sender"-e-mails via IMAP. Gebruikt SMTP-inlog + Auto TLS + Authentication uit WP Mail SMTP (Pro/Free). Vereist WP Mail SMTP en mailer = SMTP. Ondersteunt optioneel een alternatief IMAP-poortnummer. Inclusief "All bounces"-overzicht.
 * Version:     2.0.0
 * Author:      Your Name
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}
/*
// --- Whitelist: alleen deze IP's mogen de plugin activeren/initiëren ---
if (!defined('ESN_BW_IP_WHITELIST')) {
    // VUL HIER JE (publieke) IP(S) IN
    define('ESN_BW_IP_WHITELIST', [
        '188.90.41.84', // <-- vervang door jouw IP
        // '2001:db8::1234', // voorbeeld IPv6
    ]);
}

// Bepaal client IP (houd rekening met proxies/CF)
function esn_bw_client_ip() : string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP']   ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR']    ?? '',
        $_SERVER['HTTP_CLIENT_IP']          ?? '',
        $_SERVER['REMOTE_ADDR']             ?? '',
    ];
    foreach ($candidates as $raw) {
        if (!$raw) continue;
        // Neem eerste IP uit evt. lijst
        $ip = trim(explode(',', $raw)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '';
}

function esn_bw_bootstrap_allowed() : bool {
    // Cron en WP-CLI altijd toestaan
    if (defined('DOING_CRON') && DOING_CRON) return true;
    if (defined('WP_CLI') && WP_CLI) return true;

    $ip = esn_bw_client_ip();
    return $ip && in_array($ip, ESN_BW_IP_WHITELIST, true);
}*/


if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

require_once __DIR__ . '/includes/class-esn-bw-db.php';
require_once __DIR__ . '/includes/class-esn-bw-imap.php';
require_once __DIR__ . '/includes/class-esn-bw-parser.php';
require_once __DIR__ . '/includes/class-esn-bw-list-table.php';
require_once __DIR__ . '/includes/class-esn-bw-admin.php';
require_once __DIR__ . '/includes/class-esn-bw-core.php';
require_once __DIR__ . '/includes/class-esn-bw-gf.php';

//if (esn_bw_bootstrap_allowed()) {
    ESN_BW_Core::init(__FILE__);
/*} else {
    // Optioneel: minimale admin-notice voor ingelogde admins buiten whitelist
    add_action('admin_notices', function () {
        if (!current_user_can('manage_options')) return;
        echo '<div class="notice notice-info"><p><strong>ESN Bounce Watcher:</strong> safe mode actief (IP niet whitelisted). Plugin is ingeschakeld maar niet geïnitialiseerd om de live site te beschermen.</p></div>';
    });
}*/

if (!function_exists('esn_bw_dbg')) {
    function esn_bw_dbg(string $msg, array $ctx = []): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) return;
        // gevoelige velden maskeren
        foreach (['password','pass','secret','Authorization'] as $k) {
            if (isset($ctx[$k]) && is_string($ctx[$k]) && $ctx[$k] !== '') {
                $ctx[$k] = '[redacted]';
            }
        }
        // lange strings afkappen
        foreach ($ctx as $k => $v) {
            if (is_string($v) && strlen($v) > 500) {
                $ctx[$k] = substr($v, 0, 500) . '…(truncated)';
            }
        }
        error_log('[ESN_BW] ' . $msg . ' ' . json_encode($ctx));
    }
}