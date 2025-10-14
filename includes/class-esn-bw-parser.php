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

            $parsed = self::parse_delivery_report_text($txt);

            $data  = $parsed['flat'] ?? [];
            $first = $parsed['per_recipient'][0] ?? null;

            $sender  = '';
            if (!empty($data['X-Postfix-Sender'])) {
                $sender = trim(preg_replace('~^rfc822;\s*~i', '', $data['X-Postfix-Sender']));
            }
            $final   = '';
            if ($first && !empty($first['Final-Recipient']['address'])) {
                $final = $first['Final-Recipient']['address'];
            } elseif (!empty($data['Final-Recipient'])) {
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

        if (empty($structure->parts)) {
            $type = (int) $structure->type;
            $sub  = strtolower($structure->subtype ?? '');
            $enc  = (int) ($structure->encoding ?? 0);

            $is_dsn_msg = ($type === TYPEMESSAGE && $sub === 'delivery-status');

            if ($is_dsn_msg) {
                $partNo = $prefix === '' ? '1' : rtrim($prefix, '.');
                $raw = @imap_fetchbody($imap, $msgno, $partNo);
                if ($raw !== false) {
                    return [$partNo, self::imap_decode_body($raw, $enc)];
                }
            }
            return [null, null];
        }

        foreach ($structure->parts as $i => $p) {
            $pn = $prefix . ($i + 1) . '.';
            [$foundPart, $text] = self::imap_find_dsn_text($imap, $msgno, $p, $pn);
            if ($foundPart) {
                return [$foundPart, $text];
            }
        }
        return [null, null];
    }

    public static function imap_decode_body($raw, $encoding) {
        switch ((int) $encoding) {
            case ENCBASE64:
                return base64_decode($raw);
            case ENCQUOTEDPRINTABLE:
                return quoted_printable_decode($raw);
            case ENCBINARY:
            case ENC8BIT:
            case ENC7BIT:
            default:
                return $raw;
        }
    }

    private static function dsn_unfold_lines(string $text): array {
        $lines = preg_split("/\r\n|\n|\r/", $text);
        $out = [];
        foreach ($lines as $line) {
            if ($line !== '' && isset($out[count($out) - 1]) && isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t")) {
                $out[count($out) - 1] .= ' ' . ltrim($line);
            } else {
                $out[] = $line;
            }
        }
        return $out;
    }

    private static function dsn_parse_header_block(array $lines): array {
        $headers = [];
        foreach ($lines as $l) {
            if ($l === '' || strpos($l, ':') === false) {
                continue;
            }
            [$k, $v] = explode(':', $l, 2);
            $k = trim($k);
            $v = trim($v);
            $k = preg_replace_callback('/(^|-)[a-z]/', fn($m) => strtoupper($m[0]), strtolower($k));
            $headers[$k] = $v;
        }
        return $headers;
    }

    private static function dsn_split_blocks(string $dsn): array {
        $unfolded = self::dsn_unfold_lines($dsn);
        $blocks = [];
        $current = [];
        foreach ($unfolded as $l) {
            if ($l === '') {
                if ($current) {
                    $blocks[] = $current;
                    $current = [];
                }
            } else {
                $current[] = $l;
            }
        }
        if ($current) {
            $blocks[] = $current;
        }
        return array_map([self::class, 'dsn_parse_header_block'], $blocks);
    }

    private static function dsn_split_type_value(?string $raw): ?array {
        if (!$raw) {
            return null;
        }
        $t = explode(';', $raw, 2);
        $type = trim($t[0]);
        $val = isset($t[1]) ? trim($t[1]) : null;
        return [
            'type' => $type !== '' ? $type : null,
            'value' => $val,
            'raw' => $raw,
        ];
    }

    public static function parse_delivery_report_text($txt) {
        $blocks = self::dsn_split_blocks((string) $txt);
        $perMessage = $blocks[0] ?? [];
        $recBlocks = array_slice($blocks, 1);

        $recipients = [];
        foreach ($recBlocks as $b) {
            $fr = self::dsn_split_type_value($b['Final-Recipient'] ?? null);
            $or = self::dsn_split_type_value($b['Original-Recipient'] ?? null);
            $dc = self::dsn_split_type_value($b['Diagnostic-Code'] ?? null);

            $recipients[] = [
                'Final-Recipient' => [
                    'type' => $fr['type'] ?? null,
                    'address' => $fr['value'] ?? null,
                    'raw' => $fr['raw'] ?? null,
                ],
                'Original-Recipient' => [
                    'type' => $or['type'] ?? null,
                    'address' => $or['value'] ?? null,
                    'raw' => $or['raw'] ?? null,
                ],
                'Action' => $b['Action'] ?? null,
                'Status' => $b['Status'] ?? null,
                'Diagnostic-Code' => [
                    'type' => $dc['type'] ?? null,
                    'text' => $dc['value'] ?? null,
                    'raw' => $dc['raw'] ?? null,
                ],
            ];
        }

        $flat = $perMessage;
        if (!empty($recBlocks[0])) {
            foreach ($recBlocks[0] as $k => $v) {
                $flat[$k] = $v;
            }
        }

        $json = json_encode([
            'per_message' => $perMessage,
            'per_recipient' => $recipients,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = null;
        }

        return [
            'per_message' => $perMessage,
            'per_recipient' => $recipients,
            'flat' => $flat,
            'json' => $json,
        ];
    }
}
