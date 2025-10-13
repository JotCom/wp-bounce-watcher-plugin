<?php
if (!defined('ABSPATH')) {
    exit;
}

class ESN_BW_Core {
    public const OPTION_LOCAL_SETTINGS = 'esn_bw_local_settings';
    public const OPTION_COUNT      = 'esn_bw_count';
    public const OPTION_LAST_RUN   = 'esn_bw_last_run';
    public const OPTION_LAST_ERROR = 'esn_bw_last_error';

    public const CRON_HOOK         = 'esn_bw_cron_check';
    public const PARSE_HOOK        = 'esn_bw_parse_dsn_job';
    public const NONCE_ACTION_FORM = 'esn_bw_manual_sync_form';
    public const NONCE_ACTION_AJAX = 'esn_bw_manual_sync_ajax';
    public const LOCK_TRANSIENT    = 'esn_bw_lock';

    public const SLUG          = 'esn-bounce-watcher';
    public const SLUG_SETTINGS = 'esn-bounce-watcher-settings';

    public static function init($plugin_file) {
        register_activation_hook($plugin_file, [__CLASS__, 'on_activate']);
        register_deactivation_hook($plugin_file, [__CLASS__, 'on_deactivate']);

        add_action(self::CRON_HOOK, [ESN_BW_Imap::class, 'run_check']);
        add_action(self::PARSE_HOOK, [ESN_BW_Parser::class, 'handle_parse_dsn_job'], 10, 2);

        if (is_admin()) {
            ESN_BW_Admin::init();
        }
    }

    public static function ensure_tables() {
        ESN_BW_DB::ensure_tables();
    }

    public static function on_activate() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::CRON_HOOK);
        }

        ESN_BW_DB::ensure_tables();
    }

    public static function on_deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }

        delete_transient(self::LOCK_TRANSIENT);
    }
}
