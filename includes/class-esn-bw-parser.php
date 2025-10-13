<?php
if (!defined('ABSPATH')) {
    exit;
}

class ESN_BW_Parser {
    public static function handle_parse_dsn_job($uid, $mailbox) {
        $status = ESN_BW_Imap::get_wpms_status();
        if (!$status['has_plugin'] || !$status['is_smtp']) {
            return;
        }

        $smtp = $status['opts']['smtp'] ?? [];
        $host     = $smtp['host'] ?? '';
        $enc_raw  = $smtp['encryption'] ?? '';
        $auth     = array_key_exists('auth', $smtp) ? (bool) $smtp['auth'] : true;
        $autotls  = array_key_exists('autotls', $smtp) ? (bool) $smtp['autotls'] : true;
        $username = $smtp['user'] ?? '';
        $password = ESN_BW_Imap::get_wpms_password_plain();
        $port     = ESN_BW_Imap::get_effective_imap_port();

        if (!function_exists('imap_open') || empty($host) || empty($port)) {
            return;
        }
        $user_for_login = $auth ? $username : '';
        $pass_for_login = $auth ? $password : '';

        $mbox = null;
        try {
            $enc = strtolower($enc_raw);
            if ($enc === 'ssl') {
                $mbox = ESN_BW_Imap::build_imap_mailbox_string($host, $port, 'ssl', $autotls, $mailbox);
            } elseif ($enc === 'tls') {
                $mbox = ESN_BW_Imap::build_imap_mailbox_string($host, $port, 'tls', $autotls, $mailbox, $autotls ? true : false);
            } else {
                $mbox = ESN_BW_Imap::build_imap_mailbox_string($host, $port, 'none', $autotls, $mailbox, $autotls ? true : false);
            }
            $imap = @imap_open($mbox, $user_for_login, $pass_for_login, 0, 1);
            if (!$imap && $enc === 'none' && $autotls) {
                $mbox = ESN_BW_Imap::build_imap_mailbox_string($host, $port, 'none', false, $mailbox, false);
                $imap = @imap_open($mbox, $user_for_login, $pass_for_login, 0, 1);
            }
            if (!$imap) {
                return;
            }

            $msgno = @imap_msgno($imap, (int) $uid);
            if (!$msgno) {
                @imap_close($imap);
                return;
            }

            [, $txt] = self::imap_find_dsn_text($imap, $msgno);
            if (!$txt) {
                @imap_close($imap);
                return;
            }

            $data = self::parse_delivery_report_text($txt);

            $sender  = '';
            if (!empty($data['X-Postfix-Sender'])) {
                $sender = trim(preg_replace('~^rfc822;\s*~i', '', $data['X-Postfix-Sender']));
            }
            $final   = '';
            if (!empty($data['Final-Recipient'])) {
                $final = trim(preg_replace('~^rfc822;\s*~i', '', $data['Final-Recipient']));
            }
            $arr = $data['Arrival-Date'] ?? '';
            if ($arr !== '') {
                try {
                    $dt = new DateTimeImmutable($arr);
                    $tz = wp_timezone();
                    $arrival = $dt->setTimezone($tz)->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {
                    $arrival = null;
                }
            } else {
                $arrival = null;
            }

            global $wpdb;
            $table = ESN_BW_DB::table_name();
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET
                     dr_sender_email = %s,
                     dr_final_recipient = %s,
                     dr_arrival_date = %s,
                     from_email = %s,
                     to_email   = %s,
                     imap_date  = %s,
                     parsed     = 1,
                     updated_at = %s
                 WHERE uid = %d AND mailbox = %s",
                $sender,
                $final,
                $arrival,
                $sender,
                $final,
                $arrival,
                current_time('mysql'),
                (int) $uid,
                $mailbox
            ));

            @imap_close($imap);
        } catch (\Throwable $e) {
        }
    }

    public static function imap_find_dsn_text($imap, $msgno, $structure = null, $prefix = '') {
        if (!$structure) {
            $structure = @imap_fetchstructure($imap, $msgno);
        }
        if (!$structure) {
            return [null, null];
        }

        [$dsn, $plain] = self::imap_find_dsn_candidates($imap, $msgno, $structure, $prefix);

        if (!empty($dsn[0])) {
            return $dsn;
        }

        if (!empty($plain[0])) {
            return $plain;
        }

        return [null, null];
    }

    protected static function imap_find_dsn_candidates($imap, $msgno, $structure, $prefix = '') {
        $foundDsn   = [null, null];
        $foundPlain = [null, null];

        if (empty($structure->parts)) {
            $type = (int) $structure->type;
            $sub  = strtolower($structure->subtype ?? '');
            $name = '';
            $filename = '';

            if (!empty($structure->parameters)) {
                foreach ($structure->parameters as $p) {
                    if (strtolower($p->attribute) === 'name') {
                        $name = $p->value;
                    }
                }
            }

            if (!empty($structure->dparameters)) {
                foreach ($structure->dparameters as $p) {
                    if (strtolower($p->attribute) === 'filename') {
                        $filename = $p->value;
                    }
                }
            }

            $has_ext = (bool) preg_match('/\\.[a-z0-9]+$/i', ($filename ?: $name));
            $enc     = (int) ($structure->encoding ?? 0);

            $is_text_plain = ($type === TYPETEXT && $sub === 'plain');
            $is_dsn_msg    = ($type === TYPEMESSAGE && $sub === 'delivery-status');

            if (!$has_ext) {
                $partNo = $prefix === '' ? '1' : rtrim($prefix, '.');

                if ($is_dsn_msg) {
                    $raw = @imap_fetchbody($imap, $msgno, $partNo);
                    if ($raw !== false) {
                        $foundDsn = [$partNo, self::imap_decode_body($raw, $enc)];
                        return [$foundDsn, $foundPlain];
                    }
                }

                if ($is_text_plain) {
                    $raw = @imap_fetchbody($imap, $msgno, $partNo);
                    if ($raw !== false) {
                        $foundPlain = [$partNo, self::imap_decode_body($raw, $enc)];
                    }
                }
            }

            return [$foundDsn, $foundPlain];
        }

        foreach ($structure->parts as $i => $part) {
            $pn = $prefix . ($i + 1) . '.';
            [$childDsn, $childPlain] = self::imap_find_dsn_candidates($imap, $msgno, $part, $pn);

            if (!$foundDsn[0] && !empty($childDsn[0])) {
                $foundDsn = $childDsn;
            }

            if (!$foundPlain[0] && !empty($childPlain[0])) {
                $foundPlain = $childPlain;
            }

            if ($foundDsn[0]) {
                break;
            }
        }

        return [$foundDsn, $foundPlain];
    }

    public static function imap_decode_body($raw, $encoding) {
        switch ((int) $encoding) {
            case ENCBASE64:
                return base64_decode($raw);
            case ENCQUOTEDPRINTABLE:
                return quoted_printable_decode($raw);
            default:
                return $raw;
        }
    }

    public static function parse_delivery_report_text($txt) {
        $out = [];
        foreach (preg_split('/\R+/', (string) $txt) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            if ($k !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
