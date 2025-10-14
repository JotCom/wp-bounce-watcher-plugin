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

        esn_bw_dbg('parse_job: settings', [
            'host'    => $host,
            'port'    => $port,
            'enc'     => $enc_raw,
            'autotls' => $autotls,
            'auth'    => $auth,
            'user'    => $username,
            // GEEN password loggen
        ]);

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
                esn_bw_dbg('parse_job: imap_open', [
                    'mbox'    => $mbox,
                    'success' => (bool) $imap,
                    'last_error' => function_exists('imap_last_error') ? imap_last_error() : null,
                ]);
            }

            if (!$imap) {
                return;
            }

            $msgno = @imap_msgno($imap, (int) $uid);
            esn_bw_dbg('parse_job: resolved msgno', ['uid' => (int)$uid, 'msgno' => (int)$msgno, 'mailbox' => $mailbox]);

            if (!$msgno) {
                @imap_close($imap);
                return;
            }

            $res = self::extract_dsn_from_imap($imap, $msgno);
            //Log
            esn_bw_dbg('parse_job: after extract_dsn_from_imap', [
                'type' => gettype($res),
                'keys' => is_array($res) ? array_keys($res) : null,
                'raw_len' => is_array($res) && isset($res['raw']) ? strlen($res['raw']) : -1,
                'parsed_type' => is_array($res) && isset($res['parsed']) ? gettype($res['parsed']) : 'none',
            ]);

            if (empty($res['raw'])) {
                update_option(ESN_BW_Core::OPTION_LAST_ERROR, 'Parse: geen message/delivery-status gevonden (uid ' . $uid . ', mailbox ' . $mailbox . ')');
                @imap_close($imap);
                return;
            }

            $parsed = $res['parsed'];
            $data   = $parsed['flat'] ?? [];
            $first  = $parsed['per_recipient'][0] ?? null;

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

            //Log
            esn_bw_dbg('parse_job: mapped fields', [
                'sender'  => $sender,
                'final'   => $final,
                'arrival' => $arrival,
            ]);

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
            esn_bw_dbg('parse_job: exception', [
            'msg'  => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'trace'=> substr($e->getTraceAsString(), 0, 500),
            ]);
        }
    }

    public static function extract_dsn_from_imap($imap, int $msgno): array {
        // Haal raw RFC822 op
        $raw = self::imap_get_raw_message($imap, $msgno);
        // Log
        esn_bw_dbg('parse_job: raw lengths', [
            'raw_len' => is_string($raw) ? strlen($raw) : -1,
        ]);
        // Log uitgebreider
        if (function_exists('mailparse_msg_create') && is_string($raw) && strlen($raw) > 0) {
            $m = mailparse_msg_create();
            mailparse_msg_parse($m, $raw);
            $parts = [];
            foreach (mailparse_msg_get_structure($m) as $pid) {
                $p  = mailparse_msg_get_part($m, $pid);
                $pd = mailparse_msg_get_part_data($p);
                $parts[] = [
                    'id'   => $pid,
                    'ct'   => $pd['content-type'] ?? '',
                    'enc'  => $pd['transfer-encoding'] ?? '',
                    'name' => $pd['content-disposition-parameters']['filename']
                        ?? ($pd['content-type-parameters']['name'] ?? ''),
                ];
            }
            esn_bw_dbg('parse_job: mime parts', ['parts' => $parts]);
        } else {
            esn_bw_dbg('parse_job: mailparse unavailable or empty raw', [
                'mailparse' => function_exists('mailparse_msg_create'),
            ]);
        }
        
        $imapStruct = @imap_fetchstructure($imap, $msgno);
        if ($imapStruct) {
            // minimalistische flatten
            $dump = [];
            $stack = [ ['id' => '1', 'node' => $imapStruct] ];
            while ($stack) {
                $cur = array_pop($stack);
                $node = $cur['node'];
                $dump[] = [
                    'id'   => $cur['id'],
                    'type' => $node->type ?? null,
                    'sub'  => $node->subtype ?? null,
                    'enc'  => $node->encoding ?? null,
                    'params' => $node->parameters ?? [],
                    'dparams'=> $node->dparameters ?? [],
                ];
                if (!empty($node->parts)) {
                    foreach ($node->parts as $i => $child) {
                        $stack[] = ['id' => $cur['id'].'.'.($i+1), 'node' => $child];
                    }
                }
            }
            esn_bw_dbg('parse_job: imap structure', ['nodes' => $dump]);
        }

        $hdrStart = substr($raw, 0, 1500);
        preg_match('~^Content-Type:[^\r\n]+~im', $hdrStart, $mCT);
        esn_bw_dbg('parse_job: top-level Content-Type', ['ct_header' => $mCT[0] ?? '(none in first 1.5k)']);

        // Vind DSN via mailparse
        [$dsnPart, $dsnText] = self::find_dsn_with_mailparse($raw);
        // Log
        esn_bw_dbg('parse_job: DSN locate', [
            'dsn_part' => $dsnPart,
            'dsn_len'  => is_string($dsnText) ? strlen($dsnText) : -1,
        ]);
        if (!$dsnPart) {
            // Een extra aanwijzing: zit er wel multipart/report op top-niveau?
            esn_bw_dbg('parse_job: DSN not found hint', [
                'has_multipart_report' => (strpos(strtolower($raw), 'multipart/report') !== false),
                'has_delivery_status'  => (strpos(strtolower($raw), 'message/delivery-status') !== false),
            ]);
        }
        if (is_string($dsnText)) {
            esn_bw_dbg('parse_job: DSN head', [ 'dsn_head' => substr($dsnText, 0, 200) ]);
        }
        // Parse naar assoc
        // Let op: parse_delivery_report_text() moet 'flat' en 'per_recipient' teruggeven
        $parsed = is_string($dsnText) ? self::parse_delivery_report_text($dsnText) : ['flat'=>[], 'per_recipient'=>[]];

        // Log
        esn_bw_dbg('parse_job: parsed keys', [
            'flat_keys' => isset($parsed['flat']) ? array_slice(array_keys($parsed['flat']), 0, 10) : [],
            'recipients_count' => isset($parsed['per_recipient']) ? count($parsed['per_recipient']) : 0,
        ]);

        return [
            'part'   => $dsnPart,              // bijv. '2.1'
            'raw'    => $dsnText ?? '',     // volledige DSN-tekst
            'parsed' => $parsed,            // ['flat'=>..., 'per_recipient'=>...]
        ];
    }

    /** Haal de volledige raw RFC822 message op (headers + body, non-destructief) */
    private static function imap_get_raw_message($imap, $msgno) {
        // Voor headers: GEEN FT_PEEK gebruiken (bestaat niet voor fetchheader)
        // FT_PREFETCHTEXT is oké; of 0 voor default.
        $headers = @imap_fetchheader($imap, $msgno, FT_PREFETCHTEXT);
        // Voor body: FT_PEEK voorkomt dat de mail 'SEEN' wordt
        $body    = @imap_body($imap, $msgno, FT_PEEK);

        if (!is_string($headers)) $headers = '';
        if (!is_string($body))    $body = '';

        return $headers . "\r\n" . $body;
    }

    /** Geef gedeodeerde body terug voor een mailparse part */
    private static function mailparse_get_part_body(string $raw, array $pd) {
        $start = $pd['starting-pos-body'] ?? null;
        $end   = $pd['ending-pos-body'] ?? null;
        if ($start === null || $end === null || $end <= $start) {
            return '';
        }

        $slice = substr($raw, (int) $start, (int) ($end - $start));
        $enc = strtolower($pd['transfer-encoding'] ?? '');
        if ($enc === 'base64') {
            $slice = base64_decode($slice) ?: '';
        } elseif ($enc === 'quoted-printable') {
            $slice = quoted_printable_decode($slice);
        }

        $charset = '';
        if (!empty($pd['content-type-parameters']['charset'])) {
            $charset = strtolower($pd['content-type-parameters']['charset']);
        }
        if ($charset && $charset !== 'utf-8') {
            $conv = @iconv($charset, 'UTF-8//IGNORE', $slice);
            if ($conv !== false) {
                $slice = $conv;
            }
        }

        return $slice;
    }

    /** Vind de machine-readable DSN (message/delivery-status). Valt zonodig terug op text/plain met naam 'Delivery report'. */
    public static function find_dsn_with_mailparse(string $raw) {
        if (!function_exists('mailparse_msg_create')) {
            return [null, null];
        }

        $msg = mailparse_msg_create();
        mailparse_msg_parse($msg, $raw);
        $struct = mailparse_msg_get_structure($msg);
        $foundPartId = null;
        $foundData   = null;

        foreach ($struct as $partId) {
            $part = mailparse_msg_get_part($msg, $partId);
            $pd   = mailparse_msg_get_part_data($part);
            $ct   = strtolower($pd['content-type'] ?? '');
            if ($ct === 'message/delivery-status') {
                $foundPartId = $partId;
                $foundData   = $pd;
                break;
            }
        }

        if (!$foundPartId) {
            foreach ($struct as $partId) {
                $part = mailparse_msg_get_part($msg, $partId);
                $pd   = mailparse_msg_get_part_data($part);
                $ct   = strtolower($pd['content-type'] ?? '');
                if ($ct === 'text/plain') {
                    $name = '';
                    if (!empty($pd['content-disposition-parameters']['filename'])) {
                        $name = strtolower($pd['content-disposition-parameters']['filename']);
                    } elseif (!empty($pd['content-type-parameters']['name'])) {
                        $name = strtolower($pd['content-type-parameters']['name']);
                    }
                    if ($name === '' || strpos($name, 'delivery report') !== false) {
                        $foundPartId = $partId;
                        $foundData   = $pd;
                        break;
                    }
                }
            }
        }

        if (!$foundPartId) {
            mailparse_msg_free($msg);
            return [null, null];
        }

        $text = self::mailparse_get_part_body($raw, $foundData);
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);

        mailparse_msg_free($msg);

        return [$foundPartId, $text];
    }

    private static function dsn_parse_block(string $block): array {
        $lines = preg_split('/\n/', trim($block));
        $unfolded = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (!empty($unfolded) && isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t")) {
                $unfolded[count($unfolded) - 1] .= ' ' . trim($line);
            } else {
                $unfolded[] = $line;
            }
        }

        $headers = [];
        foreach ($unfolded as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if ($k !== '') {
                $normalizedKey = preg_replace_callback('/(^|-)[a-z]/', fn($m) => strtoupper($m[0]), strtolower($k));
                $headers[$normalizedKey] = $v;
            }
        }

        return $headers;
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
        $normalized = str_replace(["\r\n", "\r"], "\n", (string) $txt);
        $rawBlocks = preg_split("/\n{2,}/", trim($normalized));
        $blocks = [];
        foreach ($rawBlocks as $block) {
            $parsed = self::dsn_parse_block($block);
            if (!empty($parsed)) {
                $blocks[] = $parsed;
            }
        }

        $perMessage = $blocks[0] ?? [];
        $recBlocks = array_slice($blocks, 1);

        $recipients = [];
        foreach ($recBlocks as $b) {
            if (!is_array($b)) {
                continue;
            }
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
        if (!empty($recBlocks[0]) && is_array($recBlocks[0])) {
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
