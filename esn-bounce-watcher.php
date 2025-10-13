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

ESN_BW_Core::init(__FILE__);
