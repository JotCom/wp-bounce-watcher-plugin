<?php
/**
 * Plugin Name: ESN Bounce Watcher
 * Description: Telt en registreert ongeopende "Undelivered Mail Returned to Sender"-e-mails via IMAP. Gebruikt SMTP-inlog + Auto TLS + Authentication uit WP Mail SMTP (Pro/Free). Vereist WP Mail SMTP en mailer = SMTP. Ondersteunt optioneel een alternatief IMAP-poortnummer. Inclusief "All bounces"-overzicht.
 * Version:     2.0.0
 * Author:      Your Name
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;
if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

/**
 * WP_List_Table is niet altijd geladen; laad indien nodig.
 */
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class ESN_Bounce_Watcher {
    // Options
    const OPTION_LOCAL_SETTINGS = 'esn_bw_local_settings'; // subject + mailbox + override_port + imap_port
    const OPTION_COUNT      = 'esn_bw_count';
    const OPTION_LAST_RUN   = 'esn_bw_last_run';
    const OPTION_LAST_ERROR = 'esn_bw_last_error';

    // Cron / Nonce / Locks
    const CRON_HOOK         = 'esn_bw_cron_check';
    const PARSE_HOOK        = 'esn_bw_parse_dsn_job';
    const NONCE_ACTION_FORM = 'esn_bw_manual_sync_form';
    const NONCE_ACTION_AJAX = 'esn_bw_manual_sync_ajax';
    const LOCK_TRANSIENT    = 'esn_bw_lock';

    // Menu / Pages
    const SLUG              = 'esn-bounce-watcher';           // top-level: "All bounces"
    const SLUG_SETTINGS     = 'esn-bounce-watcher-settings';  // submenu: "Instellingen"

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_notices', [__CLASS__, 'maybe_show_dependency_notice']);

        // Fallback non-AJAX (verborgen formulier)
        add_action('admin_post_esn_bw_manual_sync', [__CLASS__, 'handle_manual_sync_post']);
        // AJAX endpoint
        add_action('wp_ajax_esn_bw_manual_sync', [__CLASS__, 'handle_manual_sync_ajax']);

        // Assets (alleen op onze paginas)
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);

        // Cron
        add_action(self::CRON_HOOK, [__CLASS__, 'run_check']);
        add_action(self::PARSE_HOOK, [__CLASS__, 'handle_parse_dsn_job'], 10, 2); // args: uid, mailbox

        register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'on_deactivate']);
    }

    /** Dependency check WP Mail SMTP */
    private static function wpms_status() {
        $has_plugin = is_plugin_active('wp-mail-smtp-pro/wp_mail_smtp.php') || is_plugin_active('wp-mail-smtp/wp_mail_smtp.php');
        $opts   = get_option('wp_mail_smtp', []);
        $mailer = $opts['mail']['mailer'] ?? '';
        $is_smtp = (strtolower($mailer) === 'smtp');
        return ['has_plugin'=>$has_plugin, 'is_smtp'=>$is_smtp, 'opts'=>$opts];
    }

    /** Menu: top-level = All bounces; submenu = Instellingen */
    public static function add_menu() {
        add_menu_page(
            'Bounce Watcher',
            'Bounce Watcher',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'render_bounces_page'], // hoofdweergave
            'dashicons-email-alt2',
            58
        );

        // Expliciet submenu "All bounces" (wijst naar dezelfde renderer)
        add_submenu_page(
            self::SLUG,
            'All bounces',
            'All bounces',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'render_bounces_page']
        );

        // Submenu "Instellingen"
        add_submenu_page(
            self::SLUG,
            'Instellingen',
            'Instellingen',
            'manage_options',
            self::SLUG_SETTINGS,
            [__CLASS__, 'render_settings_page']
        );
    }

    /** Settings registreren (alleen eigen velden) */
    public static function register_settings() {
        register_setting('esn_bw_local_group', self::OPTION_LOCAL_SETTINGS, [
            'type' => 'array',
            'sanitize_callback' => function ($input) {
                $mailbox = sanitize_text_field($input['mailbox'] ?? 'INBOX');
                $subject = sanitize_text_field($input['subject'] ?? 'Undelivered Mail Returned to Sender');

                $override_port = !empty($input['override_port']) ? true : false;
                $imap_port_raw = isset($input['imap_port']) ? (int)$input['imap_port'] : 993;
                $imap_port = ($imap_port_raw >= 1 && $imap_port_raw <= 65535) ? $imap_port_raw : 993;

                return [
                    'mailbox'       => $mailbox,
                    'subject'       => $subject,
                    'override_port' => $override_port,
                    'imap_port'     => $imap_port,
                ];
            },
            'default' => [
                'mailbox'       => 'INBOX',
                'subject'       => 'Undelivered Mail Returned to Sender',
                'override_port' => false,
                'imap_port'     => 993,
            ],
        ]);

        add_settings_section('esn_bw_section_local', 'Plugin-instellingen', function () {
            echo '<p>Gebruikt automatisch SMTP-inlog + Auto TLS + Authentication uit WP Mail SMTP. Stel hier het <strong>onderwerpfilter</strong>, de <strong>IMAP-mailbox</strong> en optioneel een <strong>alternatief IMAP-poortnummer</strong> in.</p>';
        }, self::SLUG_SETTINGS);

        add_settings_field('esn_bw_local_mailbox', 'Mailbox (IMAP map)', function () {
            $opts = get_option(self::OPTION_LOCAL_SETTINGS, []);
            $val  = $opts['mailbox'] ?? 'INBOX';
            printf('<input type="text" name="%s[mailbox]" value="%s" class="regular-text" />',
                esc_attr(self::OPTION_LOCAL_SETTINGS), esc_attr($val));
            echo '<p class="description">Meestal <code>INBOX</code>.</p>';
        }, self::SLUG_SETTINGS, 'esn_bw_section_local');

        add_settings_field('esn_bw_local_subject', 'Onderwerpfilter', function () {
            $opts = get_option(self::OPTION_LOCAL_SETTINGS, []);
            $val  = $opts['subject'] ?? 'Undelivered Mail Returned to Sender';
            printf('<input type="text" name="%s[subject]" value="%s" class="regular-text" />',
                esc_attr(self::OPTION_LOCAL_SETTINGS), esc_attr($val));
            echo '<p class="description">Case-insensitive, exacte string matching door IMAP.</p>';
        }, self::SLUG_SETTINGS, 'esn_bw_section_local');

        add_settings_field('esn_bw_local_port_override', 'Alternatief poortnummer', function () {
            $opts = get_option(self::OPTION_LOCAL_SETTINGS, []);
            $checked = !empty($opts['override_port']) ? 'checked' : '';
            $port    = isset($opts['imap_port']) ? (int)$opts['imap_port'] : 993;

            $name = esc_attr(self::OPTION_LOCAL_SETTINGS);
            echo '<label><input type="checkbox" id="esn_bw_override_port" name="'.$name.'[override_port]" value="1" '.$checked.'> Gebruik alternatief IMAP-poortnummer</label>';
            echo '<div style="margin-top:8px;">';
            printf('<input type="number" min="1" max="65535" id="esn_bw_imap_port" name="%s[imap_port]" value="%d" class="small-text" %s />',
                $name, $port, $checked ? '' : 'disabled');
            echo ' <span class="description">Standaard 993 (SSL). Sommige providers gebruiken bijv. 143 (STARTTLS/none).</span>';
            echo '</div>';

            echo '<script>
            document.addEventListener("DOMContentLoaded",function(){
              var cb = document.getElementById("esn_bw_override_port");
              var inp = document.getElementById("esn_bw_imap_port");
              if(cb && inp){
                cb.addEventListener("change", function(){
                  if(cb.checked){ inp.removeAttribute("disabled"); } else { inp.setAttribute("disabled","disabled"); }
                });
              }
            });
            </script>';
        }, self::SLUG_SETTINGS, 'esn_bw_section_local');
    }

    /** Dependency notices */
    public static function maybe_show_dependency_notice() {
        if (!current_user_can('manage_options')) return;
        $status = self::wpms_status();
        if (!$status['has_plugin']) {
            echo '<div class="notice notice-error"><p><strong>ESN Bounce Watcher:</strong> WP Mail SMTP (Pro/Free) is vereist. Activeer die plugin eerst.</p></div>';
        } elseif (!$status['is_smtp']) {
            echo '<div class="notice notice-warning"><p><strong>ESN Bounce Watcher:</strong> WP Mail SMTP is actief, maar de mailer staat niet op <em>SMTP</em>. Stel de mailer in op SMTP om IMAP te kunnen gebruiken.</p></div>';
        }
    }

    /** Alleen scripts/css laden op onze pagina's */
    public static function enqueue_admin_assets($hook) {
        // Robuuster: check de 'page' query param (werkt voor top-level én submenus)
        $current_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if (!in_array($current_page, [ self::SLUG, self::SLUG_SETTINGS ], true)) {
            return;
        }

        // CSS
        $css = '
        .esn-bw-inline { margin-top:8px; }
        .esn-bw-spinner { display:inline-block;width:16px;height:16px;border:2px solid #ccd0d4;border-top-color:#2271b1;border-radius:50%;animation:esnspin 0.6s linear infinite;margin-left:8px;vertical-align:middle; }
        @keyframes esnspin { to { transform: rotate(360deg); } }
        ';
        wp_register_style('esn-bw-admin', false);
        wp_enqueue_style('esn-bw-admin');
        wp_add_inline_style('esn-bw-admin', $css);

        // JS alleen nodig op Instellingen-pagina (AJAX-sync knop)
        if ($current_page === self::SLUG_SETTINGS) {
            wp_register_script('esn-bw-admin', false);
            wp_enqueue_script('esn-bw-admin');
            $data = [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(self::NONCE_ACTION_AJAX),
            ];
            wp_add_inline_script('esn-bw-admin', 'window.ESNBW='.wp_json_encode($data).';', 'before');

            $js = <<<JS
document.addEventListener('DOMContentLoaded', function(){
  function q(sel){return document.querySelector(sel);}
  const btn = q('#esn-bw-sync-btn');
  const out = q('#esn-bw-inline-msg');

  if(!btn || !out){
    return;
  }
  if(!window.ESNBW || !window.ESNBW.ajaxUrl || !window.ESNBW.nonce){
    out.className = 'esn-bw-inline';
    out.textContent = '❌ AJAX-configuratie ontbreekt.';
    return;
  }

  btn.addEventListener('click', function(e){
    e.preventDefault();
    if(btn.dataset.disabled === '1') return;

    out.className = 'esn-bw-inline';
    out.innerHTML = 'Synchroniseren bezig<span class="esn-bw-spinner"></span>';
    btn.dataset.disabled = '1';
    btn.setAttribute('disabled','disabled');

    const body = new URLSearchParams();
    body.set('action','esn_bw_manual_sync');
    body.set('_ajax_nonce', window.ESNBW.nonce);

    fetch(window.ESNBW.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    })
    .then(function(resp){ return resp.json().catch(function(){ throw new Error('Ongeldige serverrespons.'); }); })
    .then(function(json){
      if(json && json.success){
        out.className = 'esn-bw-inline';
        out.innerHTML = '✅ Klaar. Gevonden ongeopende bounces: <strong>'+ (json.data.count ?? 0) +'</strong>.';

        var countEl   = q('#esn-bw-count');
        var lastRunEl = q('#esn-bw-last-run');
        var lastErrEl = q('#esn-bw-last-error');
        var errWrap   = q('#esn-bw-last-error-wrap');

        if(countEl)   countEl.textContent = String(json.data.count ?? 0);
        if(lastRunEl) lastRunEl.textContent = json.data.last_run || '—';
        if(lastErrEl) lastErrEl.textContent = json.data.last_error || '';

        if(errWrap){
          if(json.data.last_error){
            errWrap.style.display = 'block';
          } else {
            errWrap.style.display = 'none';
          }
        }

        var effEl = q('#esn-bw-effective-imap-port');
        if (effEl && json.data.effective_imap_port) {
          effEl.textContent = String(json.data.effective_imap_port);
        }
      } else {
        var msg = (json && json.data && json.data.message) ? json.data.message : 'Onbekende fout.';
        out.className = 'esn-bw-inline';
        out.innerHTML = '❌ Mislukt: ' + msg;
      }
    })
    .catch(function(err){
      out.className = 'esn-bw-inline';
      out.textContent = '❌ Netwerkfout of onverwachte response.';
    })
    .finally(function(){
      btn.dataset.disabled = '0';
      btn.removeAttribute('disabled');
    });
  });
});
JS;
            wp_add_inline_script('esn-bw-admin', $js, 'after');
        }
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'esn_bounces';
    }

    private static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    /** Run dbDelta als de tabel ontbreekt (handig bij updates zonder re-activatie) */
    private static function ensure_tables() {
        if ( self::table_exists() ) return;
        self::create_tables();
    }

    /** CREATE TABLE on activate + cron schedule */
    public static function on_activate() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::CRON_HOOK);
        }
        self::create_tables();
    }

    public static function on_deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);
        delete_transient(self::LOCK_TRANSIENT);
    }

    private static function create_tables() {
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
            KEY idx_date (imap_date)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /** Helper: upsert in eigen tabel */
    private static function upsert_bounce($row) {
        global $wpdb;
        $table = $wpdb->prefix . 'esn_bounces';

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
                'unseen'     => (int)$row['unseen'],
                'updated_at' => $now,
            ], ['id' => (int)$existing], ['%s','%s','%s','%s','%d','%s'], ['%d']);

            if ( $wpdb->last_error ) {
                update_option( ESN_Bounce_Watcher::OPTION_LAST_ERROR, 'DB update error: ' . esc_html($wpdb->last_error) );
            }
            return (int)$existing;
        } else {
            $wpdb->insert($table, [
                'message_id' => $row['message_id'],
                'uid'        => $row['uid'],
                'mailbox'    => $row['mailbox'],
                'subject'    => $row['subject'],
                'from_email' => $row['from_email'],
                'to_email'   => $row['to_email'],
                'imap_date'  => $row['imap_date'],
                'unseen'     => (int)$row['unseen'],
                'parsed'     => (int)$row['parsed'],
                'dr_sender_email'    => $row['dr_sender_email'],
                'dr_final_recipient' => $row['dr_final_recipient'],
                'dr_arrival_date'    => $row['dr_arrival_date'],
                'source_host'=> $row['source_host'],
                'source_user'=> $row['source_user'],
                'hash'       => $hash,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s']);

            if ( $wpdb->last_error ) {
                update_option( ESN_Bounce_Watcher::OPTION_LAST_ERROR, 'DB insert error: ' . esc_html($wpdb->last_error) );
            }
            return (int)$wpdb->insert_id;
        }
    }

    /** Mask gevoelige info in foutstrings */
    private static function sanitize_sensitive_error($msg, $username = '', $password = '') {
        if (!is_string($msg) || $msg === '') return $msg;
        $replacements = [];
        if ($username !== '') $replacements[$username] = '[redacted-username]';
        if ($password !== '') $replacements[$password] = '[redacted-password]';
        foreach ([$username, $password] as $s) {
            if ($s !== '') $replacements[rawurlencode($s)] = '[redacted]';
        }
        return strtr($msg, $replacements);
    }

    /** Haal plain SMTP-wachtwoord uit WP Mail SMTP (gedecrypteerd) */
    private static function get_wpms_password_plain() {
        if (function_exists('wp_mail_smtp') && method_exists(wp_mail_smtp(), 'get_options')) {
            $options = wp_mail_smtp()->get_options();
            if (is_object($options)) {
                if (method_exists($options, 'get')) {
                    $pass = $options->get('smtp', 'pass'); // WPMS decrypteert intern
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
        if (class_exists('\WPMailSMTP\Helpers\Crypto')) {
            try {
                $dec = \WPMailSMTP\Helpers\Crypto::decrypt($raw);
                if (is_string($dec) && $dec !== '') {
                    return $dec;
                }
            } catch (\Throwable $e) {}
        }
        return $raw;
    }

    /** Bepaalt de IMAP-poort (override of SMTP-poort) */
    private static function get_effective_imap_port() {
        $status = self::wpms_status();
        $smtp = $status['opts']['smtp'] ?? [];
        $smtp_port = isset($smtp['port']) ? (int)$smtp['port'] : 0;

        $locals = get_option(self::OPTION_LOCAL_SETTINGS, []);
        $override = !empty($locals['override_port']);
        $imap_port = isset($locals['imap_port']) ? (int)$locals['imap_port'] : 993;

        if ($override) {
            if ($imap_port >= 1 && $imap_port <= 65535) {
                return $imap_port;
            }
            return 993;
        }
        return $smtp_port;
    }

    /** Bouw IMAP mailbox-string o.b.v. WPMS encryption/autotls. */
    private static function build_imap_mailbox_string($host, $port, $encryption, $autotls, $mailbox, $force_tls = null) {
        $flags = '/imap';
        $enc = strtolower($encryption);
        if ($enc === 'ssl') {
            $flags .= '/ssl';
        } elseif ($enc === 'tls') {
            $flags .= ($force_tls === false) ? '/notls' : '/tls';
        } else { // none
            if ($force_tls === true)      $flags .= '/tls';
            elseif ($force_tls === false) $flags .= '/notls';
        }
        return sprintf('{%s:%d%s}%s', $host, (int)$port, $flags, $mailbox);
    }

    /** Decode body volgens IMAP encoding */
    private static function imap_decode_body($raw, $encoding) {
        switch ((int)$encoding) {
            case ENCBASE64:
                return base64_decode($raw);
            case ENCQUOTEDPRINTABLE:
                return quoted_printable_decode($raw);
            default:
                return $raw;
        }
    }

    /** Vind de eerste DSN-achtige tekstbijlage (text/plain of message/delivery-status) en retourneer [partNo, text] */
    private static function imap_find_dsn_text($imap, $msgno, $structure = null, $prefix = '') {
        if (!$structure) $structure = @imap_fetchstructure($imap, $msgno);
        if (!$structure) return [null, null];

        // Leaf?
        if (empty($structure->parts)) {
            $type = (int)$structure->type;       // 0=text, 2=message, etc.
            $sub  = strtolower($structure->subtype ?? '');
            $name = ''; $filename = '';
            if (!empty($structure->parameters)) {
                foreach ($structure->parameters as $p) {
                    if (strtolower($p->attribute) === 'name') $name = $p->value;
                }
            }
            if (!empty($structure->dparameters)) {
                foreach ($structure->dparameters as $p) {
                    if (strtolower($p->attribute) === 'filename') $filename = $p->value;
                }
            }
            $has_ext = (bool)preg_match('/\.[a-z0-9]+$/i', ($filename ?: $name) );
            $enc = (int)($structure->encoding ?? 0);

            $is_text_plain = ($type === TYPETEXT && $sub === 'plain');
            $is_dsn_msg    = ($type === TYPEMESSAGE && ($sub === 'DELIVERY-STATUS' || $sub === 'delivery-status'));

            // We willen bij voorkeur de DSN / text/plain met geen of lege bestandsnaam/extensie
            if ( ($is_dsn_msg || $is_text_plain) && !$has_ext ) {
                $partNo = $prefix === '' ? '1' : rtrim($prefix, '.');
                $raw = @imap_fetchbody($imap, $msgno, $partNo);
                if ($raw !== false) {
                    return [$partNo, self::imap_decode_body($raw, $enc)];
                }
            }
            return [null, null];
        }

        // Multipart: loop children
        foreach ($structure->parts as $i => $p) {
            $pn = $prefix . ($i+1) . '.';
            [$foundPart, $text] = self::imap_find_dsn_text($imap, $msgno, $p, $pn);
            if ($foundPart) return [$foundPart, $text];
        }
        return [null, null];
    }

    /** Parse de DSN "delivery report" tekst naar assoc array */
    private static function parse_delivery_report_text($txt) {
        $out = [];
        foreach (preg_split('/\R+/', (string)$txt) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) continue;
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            if ($k !== '') $out[$k] = $v;
        }
        return $out;
    }

    /** Core: IMAP check + upsert */
    public static function run_check() {
        if (get_transient(self::LOCK_TRANSIENT)) return false;
        set_transient(self::LOCK_TRANSIENT, time(), 60 * 5);

        $status = self::wpms_status();
        if (!$status['has_plugin']) {
            update_option(self::OPTION_LAST_ERROR, 'WP Mail SMTP niet actief.');
            delete_transient(self::LOCK_TRANSIENT);
            return false;
        }
        if (!$status['is_smtp']) {
            update_option(self::OPTION_LAST_ERROR, 'WP Mail SMTP mailer is geen SMTP.');
            delete_transient(self::LOCK_TRANSIENT);
            return false;
        }

        self::ensure_tables();

        $smtp = $status['opts']['smtp'] ?? [];
        $host     = $smtp['host'] ?? '';
        $enc_raw  = $smtp['encryption'] ?? ''; // '', 'ssl', 'tls'
        $auth     = array_key_exists('auth', $smtp) ? (bool)$smtp['auth'] : true;
        $autotls  = array_key_exists('autotls', $smtp) ? (bool)$smtp['autotls'] : true;
        $username = $smtp['user'] ?? '';
        $password = self::get_wpms_password_plain();

        $locals   = get_option(self::OPTION_LOCAL_SETTINGS, []);
        $mailbox  = $locals['mailbox'] ?? 'INBOX';
        $subject  = $locals['subject'] ?? 'Undelivered Mail Returned to Sender';

        $port     = self::get_effective_imap_port();

        if (empty($host) || empty($port)) {
            update_option(self::OPTION_LAST_ERROR, 'Ontbrekende host/poort. Controleer WP Mail SMTP en/of je IMAP-poortoverride.');
            delete_transient(self::LOCK_TRANSIENT);
            return false;
        }
        if (!function_exists('imap_open')) {
            update_option(self::OPTION_LAST_ERROR, 'PHP imap-extensie ontbreekt. Activeer deze op de server.');
            delete_transient(self::LOCK_TRANSIENT);
            return false;
        }

        // Authenticatie
        $user_for_login = $auth ? $username : '';
        $pass_for_login = $auth ? $password : '';
        if ($auth && ($user_for_login === '' || $pass_for_login === '')) {
            update_option(self::OPTION_LAST_ERROR, 'Authentication staat aan in WP Mail SMTP, maar user/pass ontbreken.');
            delete_transient(self::LOCK_TRANSIENT);
            return false;
        }

        $count = 0; $err = '';
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
                // encryption = none
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

            // Zoek UNSEEN + SUBJECT en verzamel overzichten om te saven
            $criteria = 'UNSEEN';
            if (!empty($subject)) {
                $q = '"' . str_replace('"', '\"', $subject) . '"';
                $criteria .= ' SUBJECT ' . $q;
            }

            // Geen SE_FREE zodat we overview kunnen ophalen met msgno
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

                // Sla individuele items op in onze tabel
                if ($count > 0) {
                    $range = implode(',', $mails);
                    $overviews = @imap_fetch_overview($inbox, $range, 0); // stdClass[]
                    foreach ((array)$overviews as $ov) {
                        $msgno = isset($ov->msgno) ? (int)$ov->msgno : null;
                        $msgid = isset($ov->message_id) ? trim($ov->message_id, "<> \t\r\n") : null;
                        $uid   = (function_exists('imap_uid') && $msgno) ? @imap_uid($inbox, $msgno) : null;

                        $from = isset($ov->from) ? $ov->from : null;
                        $to   = isset($ov->to)   ? $ov->to   : null;
                        $date = isset($ov->date) ? date('Y-m-d H:i:s', strtotime($ov->date)) : null;

                        self::upsert_bounce([
                            'message_id' => $msgid,
                            'uid'        => $uid ?: null,
                            'mailbox'    => $mailbox,
                            'subject'    => isset($ov->subject) ? $ov->subject : null,
                            'from_email' => $from,
                            'to_email'   => $to,
                            'imap_date'  => $date,
                            'unseen'     => !empty($ov->seen) ? 0 : 1,
                            'source_host'=> $host,
                            'source_user'=> $username,
                        ]);
                    }

                    // Plan parse-jobs gespreid (bijv. elke 10 seconden één), max 50 per run
                    $delay_step = 10; // seconden tussen jobs
                    $max_jobs   = 50;
                    $scheduled  = 0;

                    foreach ((array)$overviews as $idx => $ov) {
                        if ($scheduled >= $max_jobs) break;

                        $msgno = isset($ov->msgno) ? (int)$ov->msgno : null;
                        if (!$msgno) continue;

                        $uid = (function_exists('imap_uid') && $msgno) ? @imap_uid($inbox, $msgno) : null;
                        if (!$uid) continue;

                        // Sla alleen jobs in voor nog on-geparste items (parsed=0)
                        global $wpdb;
                        $table = self::table_name();
                        $exists = (int)$wpdb->get_var( $wpdb->prepare(
                            "SELECT parsed FROM {$table} WHERE (uid=%d OR uid IS NULL) AND mailbox=%s ORDER BY id DESC LIMIT 1",
                            $uid, $mailbox
                        ));
                        if ($exists === 1) continue;

                        wp_schedule_single_event( time() + ($scheduled+1)*$delay_step, self::PARSE_HOOK, [ $uid, $mailbox ] );
                        $scheduled++;
                    }
                }
            }

            @imap_close($inbox);
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }

        if ($err !== '') {
            $safe_err = self::sanitize_sensitive_error($err, $username, $pass_for_login);
            update_option(self::OPTION_LAST_ERROR, $safe_err);
            delete_transient(self::LOCK_TRANSIENT);
            return false;
        }

        update_option(self::OPTION_COUNT, (int)$count, false);
        update_option(self::OPTION_LAST_RUN, wp_date('Y-m-d H:i:s'), false);
        update_option(self::OPTION_LAST_ERROR, '', false);

        delete_transient(self::LOCK_TRANSIENT);
        return true;
    }

    public static function handle_parse_dsn_job($uid, $mailbox) {
        // Open IMAP met huidige WPMS settings
        $status = self::wpms_status();
        if (!$status['has_plugin'] || !$status['is_smtp']) return;

        $smtp = $status['opts']['smtp'] ?? [];
        $host     = $smtp['host'] ?? '';
        $enc_raw  = $smtp['encryption'] ?? '';
        $auth     = array_key_exists('auth', $smtp) ? (bool)$smtp['auth'] : true;
        $autotls  = array_key_exists('autotls', $smtp) ? (bool)$smtp['autotls'] : true;
        $username = $smtp['user'] ?? '';
        $password = self::get_wpms_password_plain();
        $port     = self::get_effective_imap_port();

        if (!function_exists('imap_open') || empty($host) || empty($port)) return;
        $user_for_login = $auth ? $username : '';
        $pass_for_login = $auth ? $password : '';

        $mbox = null;
        try {
            $enc = strtolower($enc_raw);
            if ($enc === 'ssl') {
                $mbox = self::build_imap_mailbox_string($host, $port, 'ssl', $autotls, $mailbox);
            } elseif ($enc === 'tls') {
                $mbox = self::build_imap_mailbox_string($host, $port, 'tls', $autotls, $mailbox, $autotls ? true : false);
            } else {
                // none => probeer eerst tls indien autotls, anders notls
                $mbox = self::build_imap_mailbox_string($host, $port, 'none', $autotls, $mailbox, $autotls ? true : false);
            }
            $imap = @imap_open($mbox, $user_for_login, $pass_for_login, 0, 1);
            if (!$imap && $enc === 'none' && $autotls) {
                // fallback zonder TLS
                $mbox = self::build_imap_mailbox_string($host, $port, 'none', false, $mailbox, false);
                $imap = @imap_open($mbox, $user_for_login, $pass_for_login, 0, 1);
            }
            if (!$imap) return;

            // msgno opzoeken vanaf UID
            $msgno = @imap_msgno($imap, (int)$uid);
            if (!$msgno) { @imap_close($imap); return; }

            // Vind TXT/DSN part
            [, $txt] = self::imap_find_dsn_text($imap, $msgno);
            if (!$txt) { @imap_close($imap); return; }

            // Parse naar assoc
            $data = self::parse_delivery_report_text($txt);

            // Velden mappen
            $sender  = '';
            if (!empty($data['X-Postfix-Sender'])) {
                // "rfc822; noreply@..."
                $sender = trim( preg_replace('~^rfc822;\s*~i', '', $data['X-Postfix-Sender']) );
            }
            $final   = '';
            if (!empty($data['Final-Recipient'])) {
                $final = trim( preg_replace('~^rfc822;\s*~i', '', $data['Final-Recipient']) );
            }
            $arrival = !empty($data['Arrival-Date']) ? date('Y-m-d H:i:s', strtotime($data['Arrival-Date'])) : null;

            // Wegschrijven
            global $wpdb;
            $table = self::table_name();
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table}
                 SET dr_sender_email=%s, dr_final_recipient=%s, dr_arrival_date=%s,
                     from_email=COALESCE(NULLIF(%s,''), from_email),
                     to_email=COALESCE(NULLIF(%s,''), to_email),
                     imap_date=COALESCE(%s, imap_date),
                     parsed=1,
                     updated_at=%s
                 WHERE uid=%d AND mailbox=%s",
                $sender, $final, $arrival,
                $sender, $final, $arrival,
                current_time('mysql'),
                (int)$uid, $mailbox
            ));

            @imap_close($imap);
        } catch (\Throwable $e) {
            // Stil falen; desnoods loggen:
            // update_option(self::OPTION_LAST_ERROR, 'Parse job error: '.esc_html($e->getMessage()));
        }
    }

    /** Legacy POST fallback */
    public static function handle_manual_sync_post() {
        if (!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer(self::NONCE_ACTION_FORM);

        $status = self::wpms_status();
        if (!$status['has_plugin'] || !$status['is_smtp']) {
            wp_die('WP Mail SMTP moet actief zijn en mailer moet op SMTP staan.');
        }

        $ok = self::run_check();
        $redirect = add_query_arg(['page'=>self::SLUG_SETTINGS,'synced'=>$ok ? '1':'0'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    /** AJAX handler */
    public static function handle_manual_sync_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Geen toegang.'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION_AJAX);

        $status = self::wpms_status();
        if (!$status['has_plugin']) {
            wp_send_json_error(['message' => 'WP Mail SMTP niet actief.'], 400);
        }
        if (!$status['is_smtp']) {
            wp_send_json_error(['message' => 'WP Mail SMTP mailer is geen SMTP.'], 400);
        }

        if (function_exists('set_time_limit')) @set_time_limit(25);
        if (function_exists('imap_timeout')) {
            @imap_timeout(IMAP_OPENTIMEOUT, 15);
            @imap_timeout(IMAP_READTIMEOUT, 15);
            @imap_timeout(IMAP_WRITETIMEOUT, 15);
        }

        $ok = self::run_check();
        $data = [
            'count'               => (int)get_option(self::OPTION_COUNT, 0),
            'last_run'            => get_option(self::OPTION_LAST_RUN, '—'),
            'last_error'          => get_option(self::OPTION_LAST_ERROR, ''),
            'effective_imap_port' => self::get_effective_imap_port(),
        ];
        if ($ok) {
            wp_send_json_success($data);
        } else {
            $msg = $data['last_error'] ?: 'Synchronisatie mislukt.';
            wp_send_json_error(['message' => $msg] + $data, 500);
        }
    }

    /** INSTELLINGEN-pagina (voorheen render_admin_page) */
    public static function render_settings_page() {
        if (!current_user_can('manage_options')) return;

        $count      = (int) get_option(self::OPTION_COUNT, 0);
        $last_run   = get_option(self::OPTION_LAST_RUN, '—');
        $last_error = get_option(self::OPTION_LAST_ERROR, '');

        $status   = self::wpms_status();
        $disabled = (!$status['has_plugin'] || !$status['is_smtp']);

        $smtp = $status['opts']['smtp'] ?? [];
        $smtp_host = $smtp['host'] ?? '';
        $smtp_port = isset($smtp['port']) ? (int)$smtp['port'] : 0;
        $smtp_enc  = $smtp['encryption'] ?? '';
        $smtp_user = $smtp['user'] ?? '';
        $has_pass  = !empty($smtp['pass'] ?? '');
        $smtp_auth = array_key_exists('auth', $smtp) ? (bool)$smtp['auth'] : true;
        $smtp_autotls = array_key_exists('autotls', $smtp) ? (bool)$smtp['autotls'] : true;

        $locals = get_option(self::OPTION_LOCAL_SETTINGS, []);
        $override_port = !empty($locals['override_port']);
        $imap_port_opt = isset($locals['imap_port']) ? (int)$locals['imap_port'] : 993;
        $effective_imap_port = $override_port ? $imap_port_opt : $smtp_port;

        echo '<div class="wrap"><h1>Instellingen — Bounce Watcher</h1>';

        echo '<div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;margin-top:10px;">';

        // Stats + Sync
        echo '<div style="padding:16px 20px;border:1px solid #ccd0d4;background:#fff;border-radius:8px;min-width:300px;max-width:440px;">';
        echo '<h2 style="margin-top:0;">Huidige telling</h2>';
        echo '<p style="font-size:24px;margin:0;"><strong id="esn-bw-count">' . esc_html($count) . '</strong> ongeopende bounces</p>';
        echo '<p style="margin:8px 0 0;color:#555;">Laatste run: <span id="esn-bw-last-run">' . esc_html($last_run) . '</span></p>';
        $err_style = empty($last_error) ? 'display:none;color:#b32d2e;margin-top:8px;' : 'color:#b32d2e;margin-top:8px;';
        echo '<p id="esn-bw-last-error-wrap" style="' . esc_attr($err_style) . '"><strong>Laatste fout:</strong> <span id="esn-bw-last-error">' . esc_html($last_error) . '</span></p>';

        echo '<div class="esn-bw-inline" id="esn-bw-inline-msg"></div>';

        echo '<div style="margin-top:12px;">';
        printf('<button id="esn-bw-sync-btn" type="button" class="button button-primary" %s>Handmatig synchroniseren</button>',
            $disabled ? 'disabled' : '');
        if ($disabled) {
            echo '<p class="description" style="margin-top:8px;color:#aa0000;">Synchroniseren is uitgeschakeld totdat WP Mail SMTP actief is en de mailer op SMTP staat.</p>';
        }
        echo '</div>';

        // Hidden fallback form
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:none;">';
        wp_nonce_field(self::NONCE_ACTION_FORM);
        echo '<input type="hidden" name="action" value="esn_bw_manual_sync" />';
        echo '</form>';

        echo '</div>';

        // WPMS info + lokale settings
        echo '<div style="flex:1;min-width:360px;padding:16px 20px;border:1px solid #ccd0d4;background:#fff;border-radius:8px;">';
        echo '<h2 style="margin-top:0;">Gebruikte WP Mail SMTP instellingen</h2>';
        echo '<table class="widefat striped" style="margin-top:8px;"><tbody>';
        echo '<tr><td>Mailer</td><td>' . esc_html($status['opts']['mail']['mailer'] ?? '—') . '</td></tr>';
        echo '<tr><td>Host</td><td>' . esc_html($smtp_host ?: '—') . '</td></tr>';
        echo '<tr><td>SMTP-poort (uitgaand)</td><td>' . esc_html($smtp_port ?: '—') . '</td></tr>';
        echo '<tr><td>IMAP-poort (gebruikt)</td><td><span id="esn-bw-effective-imap-port">' . esc_html($effective_imap_port ?: '—') . '</span>' . ($override_port ? ' <em>(override)</em>' : ' <em>(volgt SMTP-poort)</em>') . '</td></tr>';
        echo '<tr><td>Encryptie</td><td>' . esc_html($smtp_enc !== '' ? $smtp_enc : 'none') . '</td></tr>';
        echo '<tr><td>Authentication</td><td>' . ($smtp_auth ? 'aan' : 'uit') . '</td></tr>';
        echo '<tr><td>Auto TLS</td><td>' . ($smtp_autotls ? 'aan' : 'uit') . '</td></tr>';
        echo '<tr><td>Gebruikersnaam</td><td>' . esc_html($smtp_user ?: '—') . '</td></tr>';
        echo '<tr><td>Wachtwoord</td><td>' . ($has_pass ? '&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;' : '—') . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2 style="margin-top:16px;">Plugin-instellingen</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('esn_bw_local_group');
        do_settings_sections(self::SLUG_SETTINGS);
        submit_button('Opslaan');
        echo '</form>';
        echo '</div>';

        echo '</div>'; // flex
        echo '<p style="margin-top:16px;color:#666;">Tip: Als je provider verschillende poorten gebruikt voor inkomend en uitgaand, zet dan <em>Alternatief poortnummer</em> aan en vul de juiste IMAP-poort in (vaak 993 voor SSL, 143 voor STARTTLS/plain).</p>';
        echo '</div>';
    }

    /** "All bounces" admin-lijst */
    public static function render_bounces_page() {
        if (!current_user_can('manage_options')) return;
        self::ensure_tables();

        // (Optioneel) toon een foutmelding als de tabel nog steeds niet bestaat
        if (!self::table_exists()) {
            echo '<div class="notice notice-error"><p><strong>Fout:</strong> De tabel voor bounces ontbreekt en kon niet worden aangemaakt. Controleer of de databasegebruiker CREATE-rechten heeft.</p></div>';
            return; // stop hier, zodat WP_List_Table niet crasht
        }
        
        echo '<div class="wrap"><h1>All bounces</h1>';

        $table = new ESN_Bounces_List_Table();
        $table->prepare_items();

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="'.esc_attr(self::SLUG).'" />';
        $table->display();
        echo '</form>';

        echo '</div>';
        global $wpdb;
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ESN_Bounce_Watcher::table_name() );
        if ($total === 0) {
            echo '<p class="description">Nog geen bounces opgeslagen. Klik op <em>Instellingen → Handmatig synchroniseren</em> om te importeren.</p>';
        }
    }
}

/** Lijst-table implementatie */
class ESN_Bounces_List_Table extends WP_List_Table {
    public function get_columns() {
        return [
            'cb'         => '<input type="checkbox" />',
            'subject'    => 'Subject',
            'from_email' => 'From',
            'to_email'   => 'To',
            'imap_date'  => 'Date',
            'unseen'     => 'Unread',
            'mailbox'    => 'Mailbox',
        ];
    }
    protected function get_sortable_columns() {
        return [
            'imap_date' => ['imap_date', true],
            'subject'   => ['subject', false],
        ];
    }
    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="ids[]" value="%d" />', $item['id']);
    }
    protected function column_subject($item) {
        $title = esc_html($item['subject'] ?: '(no subject)');
        $meta  = '';
        if (!empty($item['message_id'])) {
            $meta .= '<br><code>&lt;' . esc_html($item['message_id']) . '&gt;</code>';
        }
        return '<strong>'.$title.'</strong>'.$meta;
    }
    protected function column_unseen($item) {
        return $item['unseen'] ? '<span class="dashicons dashicons-email" title="Unread"></span>' : '';
    }
    public function prepare_items() {
        global $wpdb;
        $table = $wpdb->prefix . 'esn_bounces';

        $per_page = 20;
        $paged    = max(1, intval($_GET['paged'] ?? 1));
        $offset   = ($paged - 1) * $per_page;

        $orderby  = $_GET['orderby'] ?? 'imap_date';
        $order    = strtoupper($_GET['order'] ?? 'DESC');
        $allowed_orderby = ['imap_date','subject','id'];
        $orderby = in_array($orderby, $allowed_orderby, true) ? $orderby : 'imap_date';
        $order   = ($order === 'ASC') ? 'ASC' : 'DESC';

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, subject, from_email, to_email, imap_date, unseen, mailbox, message_id
             FROM {$table}
             ORDER BY {$orderby} {$order}, id DESC
             LIMIT %d OFFSET %d", $per_page, $offset
        ), ARRAY_A);

        $this->items = $rows ?: [];
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => max(1, ceil($total / $per_page)),
        ]);
    }
}

ESN_Bounce_Watcher::init();