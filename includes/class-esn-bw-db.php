<?php
if (!defined('ABSPATH')) {
    exit;
}

class ESN_BW_DB {
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'esn_bounces';
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    public static function ensure_tables() {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        self::create_tables();
        self::backfill_from_dr_fields();
        $ensured = true;
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = self::table_name();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id VARCHAR(255) NULL,
            uid BIGINT UNSIGNED NULL,
            mailbox VARCHAR(128) NOT NULL,
            subject TEXT NULL,
            from_email VARCHAR(255) NULL,
            to_email VARCHAR(255) NULL,
            imap_date DATETIME NULL,
            unseen TINYINT(1) NOT NULL DEFAULT 1,
            parsed TINYINT(1) NOT NULL DEFAULT 0,
            dr_sender_email VARCHAR(255) NULL,
            dr_final_recipient VARCHAR(255) NULL,
            dr_arrival_date DATETIME NULL,
            source_host VARCHAR(255) NULL,
            source_user VARCHAR(255) NULL,
            hash CHAR(40) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_hash (hash),
            KEY idx_unseen (unseen),
            KEY idx_date (imap_date),
            KEY idx_uid_mailbox (uid, mailbox),
            KEY idx_parsed (parsed)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function backfill_from_dr_fields() {
        global $wpdb;
        $table = self::table_name();

        $wpdb->query("
            UPDATE {$table}
            SET
                from_email = COALESCE(NULLIF(dr_sender_email, ''), from_email),
                to_email   = COALESCE(NULLIF(dr_final_recipient, ''), to_email),
                imap_date  = COALESCE(dr_arrival_date, imap_date)
            WHERE parsed = 1
              AND (from_email IS NULL OR from_email = ''
                   OR to_email IS NULL OR to_email = ''
                   OR imap_date IS NULL)
        ");
    }

    public static function upsert_bounce($row) {
        global $wpdb;
        $table = self::table_name();

        $row = wp_parse_args($row, [
            'message_id' => null,
            'uid'        => null,
            'mailbox'    => 'INBOX',
            'subject'    => null,
            'from_email' => null,
            'to_email'   => null,
            'imap_date'  => null,
            'unseen'     => 1,
            'parsed'     => 0,
            'dr_sender_email'    => null,
            'dr_final_recipient' => null,
            'dr_arrival_date'    => null,
            'source_host'=> null,
            'source_user'=> null,
        ]);

        $hash = sha1(($row['message_id'] ?: '') . '|' . ($row['uid'] ?: '') . '|' . $row['mailbox']);

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE hash=%s", $hash));
        $now = current_time('mysql');

        if ($existing) {
            $wpdb->update($table, [
                'subject'    => $row['subject'],
                'from_email' => $row['from_email'],
                'to_email'   => $row['to_email'],
                'imap_date'  => $row['imap_date'],
                'unseen'     => (int) $row['unseen'],
                'updated_at' => $now,
            ], ['id' => (int) $existing], ['%s','%s','%s','%s','%d','%s'], ['%d']);

            if ($wpdb->last_error) {
                update_option(ESN_BW_Core::OPTION_LAST_ERROR, 'DB update error: ' . esc_html($wpdb->last_error));
            }
            return (int) $existing;
        }

        $wpdb->insert($table, [
            'message_id' => $row['message_id'],
            'uid'        => $row['uid'],
            'mailbox'    => $row['mailbox'],
            'subject'    => $row['subject'],
            'from_email' => $row['from_email'],
            'to_email'   => $row['to_email'],
            'imap_date'  => $row['imap_date'],
            'unseen'     => (int) $row['unseen'],
            'parsed'     => (int) $row['parsed'],
            'dr_sender_email'    => $row['dr_sender_email'],
            'dr_final_recipient' => $row['dr_final_recipient'],
            'dr_arrival_date'    => $row['dr_arrival_date'],
            'source_host'=> $row['source_host'],
            'source_user'=> $row['source_user'],
            'hash'       => $hash,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s']);

        if ($wpdb->last_error) {
            update_option(ESN_BW_Core::OPTION_LAST_ERROR, 'DB insert error: ' . esc_html($wpdb->last_error));
        }
        return (int) $wpdb->insert_id;
    }

    public static function sanitize_sensitive_error($msg, $username = '', $password = '') {
        if (!is_string($msg) || $msg === '') {
            return $msg;
        }

        $replacements = [];
        if ($username !== '') {
            $replacements[$username] = '[redacted-username]';
        }
        if ($password !== '') {
            $replacements[$password] = '[redacted-password]';
        }
        foreach ([$username, $password] as $value) {
            if ($value !== '') {
                $replacements[rawurlencode($value)] = '[redacted]';
            }
        }

        return strtr($msg, $replacements);
    }
}
