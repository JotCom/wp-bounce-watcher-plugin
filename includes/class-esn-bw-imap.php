<?php
if (!defined('ABSPATH')) {
    exit;
}

class ESN_BW_Imap {
    public static function get_wpms_status() {
        $has_plugin = is_plugin_active('wp-mail-smtp-pro/wp_mail_smtp.php') || is_plugin_active('wp-mail-smtp/wp_mail_smtp.php');
        $opts   = get_option('wp_mail_smtp', []);
        $mailer = $opts['mail']['mailer'] ?? '';
        $is_smtp = (strtolower($mailer) === 'smtp');

        return [
            'has_plugin' => $has_plugin,
            'is_smtp'    => $is_smtp,
            'opts'       => $opts,
        ];
    }

    public static function build_imap_mailbox_string($host, $port, $encryption, $autotls, $mailbox, $force_tls = null) {
        $flags = '/imap';
        $enc = strtolower($encryption);
        if ($enc === 'ssl') {
            $flags .= '/ssl';
        } elseif ($enc === 'tls') {
            $flags .= ($force_tls === false) ? '/notls' : '/tls';
        } else {
            if ($force_tls === true) {
                $flags .= '/tls';
            } elseif ($force_tls === false) {
                $flags .= '/notls';
            }
        }

        return sprintf('{%s:%d%s}%s', $host, (int) $port, $flags, $mailbox);
    }

    public static function get_effective_imap_port() {
        $status = self::get_wpms_status();
        $smtp = $status['opts']['smtp'] ?? [];
        $smtp_port = isset($smtp['port']) ? (int) $smtp['port'] : 0;

        $locals = get_option(ESN_BW_Core::OPTION_LOCAL_SETTINGS, []);
        $override = !empty($locals['override_port']);
        $imap_port = isset($locals['imap_port']) ? (int) $locals['imap_port'] : 993;

        if ($override) {
            if ($imap_port >= 1 && $imap_port <= 65535) {
                return $imap_port;
            }
            return 993;
        }

        return $smtp_port;
    }

    public static function get_wpms_password_plain() {
        if (function_exists('wp_mail_smtp') && method_exists(wp_mail_smtp(), 'get_options')) {
            $options = wp_mail_smtp()->get_options();
            if (is_object($options)) {
                if (method_exists($options, 'get')) {
                    $pass = $options->get('smtp', 'pass');
                    if (is_string($pass) && $pass !== '') {
                        return $pass;
                    }
                }
                if (method_exists($options, 'get_group')) {
                    $grp = (array) $options->get_group('smtp');
                    $raw = $grp['pass'] ?? '';
                }
            }
        }

        if (!isset($raw)) {
            $raw = get_option('wp_mail_smtp', [])['smtp']['pass'] ?? '';
        }
        if ($raw === '') {
            return '';
        }

        if (class_exists('\\WPMailSMTP\\Helpers\\Crypto')) {
            try {
                $dec = \WPMailSMTP\Helpers\Crypto::decrypt($raw);
                if (is_string($dec) && $dec !== '') {
                    return $dec;
                }
            } catch (\Throwable $e) {
            }
        }

        return $raw;
    }

    public static function run_check() {
        if (get_transient(ESN_BW_Core::LOCK_TRANSIENT)) {
            return false;
        }
        set_transient(ESN_BW_Core::LOCK_TRANSIENT, time(), 60 * 5);

        $status = self::get_wpms_status();
        if (!$status['has_plugin']) {
            update_option(ESN_BW_Core::OPTION_LAST_ERROR, 'WP Mail SMTP niet actief.');
            delete_transient(ESN_BW_Core::LOCK_TRANSIENT);
            return false;
        }
        if (!$status['is_smtp']) {
            update_option(ESN_BW_Core::OPTION_LAST_ERROR, 'WP Mail SMTP mailer is geen SMTP.');
            delete_transient(ESN_BW_Core::LOCK_TRANSIENT);
            return false;
        }

        ESN_BW_DB::ensure_tables();

        $smtp = $status['opts']['smtp'] ?? [];
        $host     = $smtp['host'] ?? '';
        $enc_raw  = $smtp['encryption'] ?? '';
        $auth     = array_key_exists('auth', $smtp) ? (bool) $smtp['auth'] : true;
        $autotls  = array_key_exists('autotls', $smtp) ? (bool) $smtp['autotls'] : true;
        $username = $smtp['user'] ?? '';
        $password = self::get_wpms_password_plain();

        $locals   = get_option(ESN_BW_Core::OPTION_LOCAL_SETTINGS, []);
        $mailbox  = $locals['mailbox'] ?? 'INBOX';
        $subject  = $locals['subject'] ?? 'Undelivered Mail Returned to Sender';

        $port     = self::get_effective_imap_port();

        if (empty($host) || empty($port)) {
            update_option(ESN_BW_Core::OPTION_LAST_ERROR, 'Ontbrekende host/poort. Controleer WP Mail SMTP en/of je IMAP-poortoverride.');
            delete_transient(ESN_BW_Core::LOCK_TRANSIENT);
            return false;
        }
        if (!function_exists('imap_open')) {
            update_option(ESN_BW_Core::OPTION_LAST_ERROR, 'PHP imap-extensie ontbreekt. Activeer deze op de server.');
            delete_transient(ESN_BW_Core::LOCK_TRANSIENT);
            return false;
        }

        $user_for_login = $auth ? $username : '';
        $pass_for_login = $auth ? $password : '';
        if ($auth && ($user_for_login === '' || $pass_for_login === '')) {
            update_option(ESN_BW_Core::OPTION_LAST_ERROR, 'Authentication staat aan in WP Mail SMTP, maar user/pass ontbreken.');
            delete_transient(ESN_BW_Core::LOCK_TRANSIENT);
            return false;
        }

        $count = 0;
        $err = '';

        try {
            $enc = strtolower($enc_raw);
            $inbox = false;

            if ($enc === 'ssl') {
                $mbox = self::build_imap_mailbox_string($host, $port, 'ssl', $autotls, $mailbox);
                $inbox = @imap_open($mbox, $user_for_login, $pass_for_login, 0, 1);
            } elseif ($enc === 'tls') {
                if ($autotls === false) {
                    $mbox = self::build_imap_mailbox_string($host, $port, 'tls', false, $mailbox, false);
                    $inbox = @imap_open($mbox, $user_for_login, $pass_for_login, 0, 1);
                } else {
                    $mbox = self::build_imap_mailbox_string($host, $port, 'tls', true, $mailbox, true);
                    $inbox = @imap_open($mbox, $user_for_login, $pass_for_login, 0, 1);
                }
            } else {
                if ($autotls) {
                    $mbox_tls = self::build_imap_mailbox_string($host, $port, 'none', true, $mailbox, true);
                    $inbox = @imap_open($mbox_tls, $user_for_login, $pass_for_login, 0, 1);
                    if (!$inbox) {
                        $mbox_plain = self::build_imap_mailbox_string($host, $port, 'none', false, $mailbox, false);
                        $inbox = @imap_open($mbox_plain, $user_for_login, $pass_for_login, 0, 1);
                    }
                } else {
                    $mbox_plain = self::build_imap_mailbox_string($host, $port, 'none', false, $mailbox, false);
                    $inbox = @imap_open($mbox_plain, $user_for_login, $pass_for_login, 0, 1);
                }
            }

            if (!$inbox) {
                $err = imap_last_error() ?: 'Onbekende verbindingsfout';
                throw new \RuntimeException($err);
            }

            $criteria = 'UNSEEN';
            if (!empty($subject)) {
                $q = '"' . str_replace('"', '\\"', $subject) . '"';
                $criteria .= ' SUBJECT ' . $q;
            }

            $mails = @imap_search($inbox, $criteria, 0, 'UTF-8');

            if ($mails === false) {
                $imap_errs = imap_errors();
                if (!empty($imap_errs)) {
                    $err = implode('; ', $imap_errs);
                    throw new \RuntimeException($err);
                }
                $count = 0;
            } else {
                $count = count($mails);

                if ($count > 0) {
                    $range = implode(',', $mails);
                    $overviews = @imap_fetch_overview($inbox, $range, 0);
                    foreach ((array) $overviews as $ov) {
                        $msgno = isset($ov->msgno) ? (int) $ov->msgno : null;
                        $msgid = isset($ov->message_id) ? trim($ov->message_id, "<> \t\r\n") : null;
                        $uid   = (function_exists('imap_uid') && $msgno) ? @imap_uid($inbox, $msgno) : null;

                        $date = isset($ov->date) ? date('Y-m-d H:i:s', strtotime($ov->date)) : null;

                        ESN_BW_DB::upsert_bounce([
                            'message_id' => $msgid,
                            'uid'        => $uid ?: null,
                            'mailbox'    => $mailbox,
                            'subject'    => isset($ov->subject) ? $ov->subject : null,
                            'imap_date'  => $date,
                            'unseen'     => !empty($ov->seen) ? 0 : 1,
                            'source_host'=> $host,
                            'source_user'=> $username,
                        ]);
                    }

                    $delay_step = 10;
                    $max_jobs   = 50;
                    $scheduled  = 0;

                    foreach ((array) $overviews as $idx => $ov) {
                        if ($scheduled >= $max_jobs) {
                            break;
                        }

                        $msgno = isset($ov->msgno) ? (int) $ov->msgno : null;
                        if (!$msgno) {
                            continue;
                        }

                        $uid = (function_exists('imap_uid') && $msgno) ? @imap_uid($inbox, $msgno) : null;
                        if (!$uid) {
                            continue;
                        }

                        global $wpdb;
                        $table = ESN_BW_DB::table_name();
                        $exists = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT parsed FROM {$table} WHERE (uid=%d OR uid IS NULL) AND mailbox=%s ORDER BY id DESC LIMIT 1",
                            $uid,
                            $mailbox
                        ));
                        if ($exists === 1) {
                            continue;
                        }

                        $ts = wp_next_scheduled(ESN_BW_Core::PARSE_HOOK, [$uid, $mailbox]);
                        if ($ts) {
                            continue;
                        }

                        wp_schedule_single_event(time() + ($scheduled + 1) * $delay_step, ESN_BW_Core::PARSE_HOOK, [$uid, $mailbox]);
                        $scheduled++;
                    }
                }
            }

            @imap_close($inbox);
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }

        if ($err !== '') {
            $safe_err = ESN_BW_DB::sanitize_sensitive_error($err, $username, $pass_for_login);
            update_option(ESN_BW_Core::OPTION_LAST_ERROR, $safe_err);
            delete_transient(ESN_BW_Core::LOCK_TRANSIENT);
            return false;
        }

        update_option(ESN_BW_Core::OPTION_COUNT, (int) $count, false);
        update_option(ESN_BW_Core::OPTION_LAST_RUN, wp_date('Y-m-d H:i:s'), false);
        update_option(ESN_BW_Core::OPTION_LAST_ERROR, '', false);

        delete_transient(ESN_BW_Core::LOCK_TRANSIENT);
        return true;
    }
}
