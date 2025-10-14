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

ESN_BW_Core::init(__FILE__);

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