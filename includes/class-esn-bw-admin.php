<?php
if (!defined('ABSPATH')) {
    exit;
}

class ESN_BW_Admin {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_notices', [__CLASS__, 'maybe_show_dependency_notice']);

        add_action('admin_post_esn_bw_manual_sync', [__CLASS__, 'handle_manual_sync_post']);
        add_action('admin_post_esn_bw_truncate', [__CLASS__, 'handle_truncate_bounces']);
        add_action('wp_ajax_esn_bw_manual_sync', [__CLASS__, 'handle_manual_sync_ajax']);
        add_action('wp_ajax_esn_bw_debug_parse', [__CLASS__, 'handle_debug_parse_ajax']);

        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }

    public static function add_menu() {
        add_menu_page(
            'Bounce Watcher',
            'Bounce Watcher',
            'manage_options',
            ESN_BW_Core::SLUG,
            [__CLASS__, 'render_bounces_page'],
            'dashicons-email-alt2',
            58
        );

        add_submenu_page(
            ESN_BW_Core::SLUG,
            'All bounces',
            'All bounces',
            'manage_options',
            ESN_BW_Core::SLUG,
            [__CLASS__, 'render_bounces_page']
        );

        add_submenu_page(
            ESN_BW_Core::SLUG,
            'Instellingen',
            'Instellingen',
            'manage_options',
            ESN_BW_Core::SLUG_SETTINGS,
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting('esn_bw_local_group', ESN_BW_Core::OPTION_LOCAL_SETTINGS, [
            'type' => 'array',
            'sanitize_callback' => function ($input) {
                $mailbox = sanitize_text_field($input['mailbox'] ?? 'INBOX');
                $subject = sanitize_text_field($input['subject'] ?? 'Undelivered Mail Returned to Sender');

                $override_port = !empty($input['override_port']) ? true : false;
                $imap_port_raw = isset($input['imap_port']) ? (int) $input['imap_port'] : 993;
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
        }, ESN_BW_Core::SLUG_SETTINGS);

        add_settings_field('esn_bw_local_mailbox', 'Mailbox (IMAP map)', function () {
            $opts = get_option(ESN_BW_Core::OPTION_LOCAL_SETTINGS, []);
            $val  = $opts['mailbox'] ?? 'INBOX';
            printf('<input type="text" name="%s[mailbox]" value="%s" class="regular-text" />',
                esc_attr(ESN_BW_Core::OPTION_LOCAL_SETTINGS), esc_attr($val));
            echo '<p class="description">Meestal <code>INBOX</code>.</p>';
        }, ESN_BW_Core::SLUG_SETTINGS, 'esn_bw_section_local');

        add_settings_field('esn_bw_local_subject', 'Onderwerpfilter', function () {
            $opts = get_option(ESN_BW_Core::OPTION_LOCAL_SETTINGS, []);
            $val  = $opts['subject'] ?? 'Undelivered Mail Returned to Sender';
            printf('<input type="text" name="%s[subject]" value="%s" class="regular-text" />',
                esc_attr(ESN_BW_Core::OPTION_LOCAL_SETTINGS), esc_attr($val));
            echo '<p class="description">Case-insensitive, exacte string matching door IMAP.</p>';
        }, ESN_BW_Core::SLUG_SETTINGS, 'esn_bw_section_local');

        add_settings_field('esn_bw_local_port_override', 'Alternatief poortnummer', function () {
            $opts = get_option(ESN_BW_Core::OPTION_LOCAL_SETTINGS, []);
            $checked = !empty($opts['override_port']) ? 'checked' : '';
            $port    = isset($opts['imap_port']) ? (int) $opts['imap_port'] : 993;

            $name = esc_attr(ESN_BW_Core::OPTION_LOCAL_SETTINGS);
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
        }, ESN_BW_Core::SLUG_SETTINGS, 'esn_bw_section_local');
    }

    public static function maybe_show_dependency_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $status = ESN_BW_Imap::get_wpms_status();
        if (!$status['has_plugin']) {
            echo '<div class="notice notice-error"><p><strong>ESN Bounce Watcher:</strong> WP Mail SMTP (Pro/Free) is vereist. Activeer die plugin eerst.</p></div>';
        } elseif (!$status['is_smtp']) {
            echo '<div class="notice notice-warning"><p><strong>ESN Bounce Watcher:</strong> WP Mail SMTP is actief, maar de mailer staat niet op <em>SMTP</em>. Stel de mailer in op SMTP om IMAP te kunnen gebruiken.</p></div>';
        }
    }

    public static function enqueue_admin_assets($hook) {
        $current_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if (!in_array($current_page, [ESN_BW_Core::SLUG, ESN_BW_Core::SLUG_SETTINGS], true)) {
            return;
        }

        $css = '
        .esn-bw-inline { margin-top:8px; }
        .esn-bw-spinner { display:inline-block;width:16px;height:16px;border:2px solid #ccd0d4;border-top-color:#2271b1;border-radius:50%;animation:esnspin 0.6s linear infinite;margin-left:8px;vertical-align:middle; }
        @keyframes esnspin { to { transform: rotate(360deg); } }
        ';
        wp_register_style('esn-bw-admin', false);
        wp_enqueue_style('esn-bw-admin');
        wp_add_inline_style('esn-bw-admin', $css);

        if ($current_page === ESN_BW_Core::SLUG_SETTINGS) {
            wp_register_script('esn-bw-admin', false, [], false, true); // in_footer = true
            wp_enqueue_script('esn-bw-admin');
            $data = [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(ESN_BW_Core::NONCE_ACTION_AJAX),
            ];
            wp_add_inline_script('esn-bw-admin', 'window.ESNBW=' . wp_json_encode($data) . ';', 'before');

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

    public static function handle_manual_sync_post() {
        if (!current_user_can('manage_options')) {
            wp_die('Geen toegang.');
        }
        check_admin_referer(ESN_BW_Core::NONCE_ACTION_FORM);

        $status = ESN_BW_Imap::get_wpms_status();
        if (!$status['has_plugin'] || !$status['is_smtp']) {
            wp_die('WP Mail SMTP moet actief zijn en mailer moet op SMTP staan.');
        }

        $ok = ESN_BW_Imap::run_check();
        $redirect = add_query_arg(['page' => ESN_BW_Core::SLUG_SETTINGS, 'synced' => $ok ? '1' : '0'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_manual_sync_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Geen toegang.'], 403);
        }
        check_ajax_referer(ESN_BW_Core::NONCE_ACTION_AJAX);

        $status = ESN_BW_Imap::get_wpms_status();
        if (!$status['has_plugin']) {
            wp_send_json_error(['message' => 'WP Mail SMTP niet actief.'], 400);
        }
        if (!$status['is_smtp']) {
            wp_send_json_error(['message' => 'WP Mail SMTP mailer is geen SMTP.'], 400);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(25);
        }
        if (function_exists('imap_timeout')) {
            @imap_timeout(IMAP_OPENTIMEOUT, 15);
            @imap_timeout(IMAP_READTIMEOUT, 15);
            @imap_timeout(IMAP_WRITETIMEOUT, 15);
        }

        $ok = ESN_BW_Imap::run_check();
        $data = [
            'count'               => (int) get_option(ESN_BW_Core::OPTION_COUNT, 0),
            'last_run'            => get_option(ESN_BW_Core::OPTION_LAST_RUN, '—'),
            'last_error'          => get_option(ESN_BW_Core::OPTION_LAST_ERROR, ''),
            'effective_imap_port' => ESN_BW_Imap::get_effective_imap_port(),
        ];
        if ($ok) {
            wp_send_json_success($data);
        }
        $msg = $data['last_error'] ?: 'Synchronisatie mislukt.';
        wp_send_json_error(['message' => $msg] + $data, 500);
    }

    public static function handle_debug_parse_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Geen toegang.'], 403);
        }
        check_ajax_referer(ESN_BW_Core::NONCE_ACTION_AJAX);

        $status = ESN_BW_Imap::get_wpms_status();
        if (!$status['has_plugin']) {
            wp_send_json_error(['message' => 'WP Mail SMTP niet actief.'], 400);
        }
        if (!$status['is_smtp']) {
            wp_send_json_error(['message' => 'WP Mail SMTP mailer is geen SMTP.'], 400);
        }

        $smtp = $status['opts']['smtp'] ?? [];
        $host     = $smtp['host'] ?? '';
        $enc_raw  = $smtp['encryption'] ?? '';
        $auth     = array_key_exists('auth', $smtp) ? (bool) $smtp['auth'] : true;
        $autotls  = array_key_exists('autotls', $smtp) ? (bool) $smtp['autotls'] : true;
        $username = $smtp['user'] ?? '';
        $password = ESN_BW_Imap::get_wpms_password_plain();
        $port     = ESN_BW_Imap::get_effective_imap_port();

        $locals   = get_option(ESN_BW_Core::OPTION_LOCAL_SETTINGS, []);
        $mailbox  = $locals['mailbox'] ?? 'INBOX';
        $subject  = $locals['subject'] ?? 'Undelivered Mail Returned to Sender';

        if (!function_exists('imap_open')) {
            wp_send_json_error(['message' => 'PHP imap-extensie ontbreekt.'], 500);
        }
        if (empty($host) || empty($port)) {
            wp_send_json_error(['message' => 'Ontbrekende host/poort.'], 400);
        }

        $user_for_login = $auth ? $username : '';
        $pass_for_login = $auth ? $password : '';

        $imap = null;
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
                $err = imap_last_error() ?: 'IMAP open fout';
                wp_send_json_error(['message' => $err], 500);
            }

            $crit = 'UNSEEN';
            if (!empty($subject)) {
                $crit .= ' SUBJECT "' . str_replace('"', '\"', $subject) . '"';
            }
            $mails = @imap_search($imap, $crit, 0, 'UTF-8');
            if ($mails === false || empty($mails)) {
                @imap_close($imap);
                wp_send_json_error(['message' => 'Geen UNSEEN bounce gevonden voor debug.'], 404);
            }

            rsort($mails);
            $msgno = (int) $mails[0];
            $uid   = function_exists('imap_uid') ? @imap_uid($imap, $msgno) : null;

            $structure = @imap_fetchstructure($imap, $msgno);
            [$part, $txt] = ESN_BW_Parser::imap_find_dsn_text($imap, $msgno, $structure);

            $raw = '';
            if ($txt !== null) {
                if (function_exists('mb_substr')) {
                    $raw = mb_substr($txt, 0, 2000);
                } else {
                    $raw = substr($txt, 0, 2000);
                }
            }

            $parsed = is_string($txt) ? ESN_BW_Parser::parse_delivery_report_text($txt) : [];

            @imap_close($imap);

            wp_send_json_success([
                'uid'     => $uid,
                'mailbox' => $mailbox,
                'part'    => $part,
                'raw'     => $raw,
                'parsed'  => $parsed,
            ]);
        } catch (\Throwable $e) {
            if (is_resource($imap)) {
                @imap_close($imap);
            }
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    public static function handle_truncate_bounces() {
        if (!current_user_can('manage_options')) {
            wp_die('Geen toegang');
        }
        check_admin_referer('esn_bw_truncate');

        global $wpdb;
        $table = ESN_BW_DB::table_name();
        $wpdb->query("TRUNCATE TABLE {$table}");

        update_option(ESN_BW_Core::OPTION_COUNT, 0, false);
        update_option(ESN_BW_Core::OPTION_LAST_ERROR, '', false);

        wp_safe_redirect(add_query_arg(['page' => ESN_BW_Core::SLUG_SETTINGS, 'truncated' => '1'], admin_url('admin.php')));
        exit;
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!empty($_GET['truncated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Bounces tabel is geleegd.</p></div>';
        }

        $count      = (int) get_option(ESN_BW_Core::OPTION_COUNT, 0);
        $last_run   = get_option(ESN_BW_Core::OPTION_LAST_RUN, '—');
        $last_error = get_option(ESN_BW_Core::OPTION_LAST_ERROR, '');

        $status   = ESN_BW_Imap::get_wpms_status();
        $disabled = (!$status['has_plugin'] || !$status['is_smtp']);

        $smtp = $status['opts']['smtp'] ?? [];
        $smtp_host = $smtp['host'] ?? '';
        $smtp_port = isset($smtp['port']) ? (int) $smtp['port'] : 0;
        $smtp_enc  = $smtp['encryption'] ?? '';
        $smtp_user = $smtp['user'] ?? '';
        $has_pass  = !empty($smtp['pass'] ?? '');
        $smtp_auth = array_key_exists('auth', $smtp) ? (bool) $smtp['auth'] : true;
        $smtp_autotls = array_key_exists('autotls', $smtp) ? (bool) $smtp['autotls'] : true;

        $locals = get_option(ESN_BW_Core::OPTION_LOCAL_SETTINGS, []);
        $override_port = !empty($locals['override_port']);
        $imap_port_opt = isset($locals['imap_port']) ? (int) $locals['imap_port'] : 993;
        $effective_imap_port = $override_port ? $imap_port_opt : $smtp_port;

        echo '<div class="wrap"><h1>Instellingen — Bounce Watcher</h1>';

        echo '<div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;margin-top:10px;">';

        echo '<div style="padding:16px 20px;border:1px solid #ccd0d4;background:#fff;border-radius:8px;min-width:300px;max-width:440px;">';
        echo '<h2 style="margin-top:0;">Huidige telling</h2>';
        echo '<p style="font-size:24px;margin:0;"><strong id="esn-bw-count">' . esc_html($count) . '</strong> ongeopende bounces</p>';
        echo '<p style="margin:8px 0 0;color:#555;">Laatste run: <span id="esn-bw-last-run">' . esc_html($last_run) . '</span></p>';
        $err_style = empty($last_error) ? 'display:none;color:#b32d2e;margin-top:8px;' : 'color:#b32d2e;margin-top:8px;';
        echo '<p id="esn-bw-last-error-wrap" style="' . esc_attr($err_style) . '"><strong>Laatste fout:</strong> <span id="esn-bw-last-error">' . esc_html($last_error) . '</span></p>';

        echo '<p class="esn-bw-inline" id="esn-bw-inline-msg" style="min-height:20px;">';
        if (!empty($_GET['synced'])) {
            echo '✅ Handmatige synchronisatie voltooid.';
        } else {
            echo '';
        }
        echo '</p>';

        $nonce_field = wp_nonce_field(ESN_BW_Core::NONCE_ACTION_FORM, '_wpnonce', true, false);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo $nonce_field;
        echo '<input type="hidden" name="action" value="esn_bw_manual_sync">';
        $button_attrs = [
            'id' => 'esn-bw-sync-btn',
            'style' => 'margin-right:8px;',
            'data-disabled' => $disabled ? '1' : '0',
        ];
        if ($disabled) {
            $button_attrs['disabled'] = 'disabled';
        }
        submit_button('Handmatig synchroniseren', 'primary', 'submit', false, $button_attrs);
        echo '</form>';

        echo '<p style="margin-top:8px;color:#555;">Effectieve IMAP-poort: <strong id="esn-bw-effective-imap-port">' . esc_html($effective_imap_port ?: '—') . '</strong></p>';

        echo '</div>';

        echo '<div style="flex:1;min-width:320px;max-width:520px;">';
        echo '<h2 style="margin-top:0;">WP Mail SMTP configuratie (read-only)</h2>';
        echo '<table class="widefat striped" style="max-width:520px;">';
        echo '<tbody>';
        echo '<tr><td>Host</td><td>' . esc_html($smtp_host ?: '—') . '</td></tr>';
        echo '<tr><td>Poort</td><td>' . esc_html($smtp_port ?: '—') . '</td></tr>';
        echo '<tr><td>Encryptie</td><td>' . esc_html($smtp_enc ?: '—') . '</td></tr>';
        echo '<tr><td>Authenticatie</td><td>' . ($smtp_auth ? 'aan' : 'uit') . '</td></tr>';
        echo '<tr><td>Auto TLS</td><td>' . ($smtp_autotls ? 'aan' : 'uit') . '</td></tr>';
        echo '<tr><td>Gebruikersnaam</td><td>' . esc_html($smtp_user ?: '—') . '</td></tr>';
        echo '<tr><td>Wachtwoord</td><td>' . ($has_pass ? '&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;' : '—') . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2 style="margin-top:16px;">Plugin-instellingen</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
        settings_fields('esn_bw_local_group');
        do_settings_sections(ESN_BW_Core::SLUG_SETTINGS);
        submit_button('Opslaan');
        echo '</form>';
        echo '<hr style="margin:16px 0;">';
        echo '<h2>Testhulpmiddel</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('esn_bw_truncate');
        echo '<input type="hidden" name="action" value="esn_bw_truncate">';
        submit_button('Bounces tabel legen', 'delete');
        echo '</form>';
        echo '<hr style="margin:16px 0;">';
        echo '<h2>Debug: parse voorbeeld</h2>';
        echo '<p class="description">Haalt 1 recente UNSEEN bounce op, toont DSN-TXT (eerste 2 kB) en het JSON-resultaat van de parser.</p>';
        echo '<div id="esn-bw-debug-out" class="esn-bw-inline" style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #ccd0d4;padding:10px;border-radius:6px;max-height:300px;overflow:auto;"></div>';
        echo '<p><button id="esn-bw-debug-btn" type="button" class="button">Run debug parse</button></p>';

        wp_add_inline_script('esn-bw-admin', <<<JS
document.addEventListener('DOMContentLoaded', function(){
  (function(){
    const btn = document.getElementById('esn-bw-debug-btn');
    const out = document.getElementById('esn-bw-debug-out');
    if(!btn||!out||!window.ESNBW) return;
    btn.addEventListener('click', function(){
        btn.disabled = true;
        out.textContent = 'Bezig…';
        const body = new URLSearchParams();
        body.set('action','esn_bw_debug_parse');
        body.set('_ajax_nonce', window.ESNBW.nonce);
        fetch(window.ESNBW.ajaxUrl, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body: body.toString()
        }).then(r=>r.json()).then(j=>{
        if(!j||!j.success){ out.textContent = '❌ ' + (j && j.data && j.data.message ? j.data.message : 'Onbekende fout'); return;}
        const d = j.data;
        let txt = '';
        txt += 'UID: ' + (d.uid||'—') + '\n';
        txt += 'Mailbox: ' + (d.mailbox||'—') + '\n';
        txt += 'Gevonden part: ' + (d.part||'—') + '\n';
        txt += '\n=== RAW DSN TEXT (eerste 2 kB) ===\n';
        txt += (d.raw||'(leeg)') + '\n';
        txt += '\n=== PARSED JSON ===\n';
        txt += JSON.stringify(d.parsed||{}, null, 2);
        out.textContent = txt;
        }).catch(()=>{ out.textContent = '❌ Netwerkfout'; })
        .finally(()=>{ btn.disabled=false; });
    });
  })();
});
JS
, 'after');
        echo '</div>';

        echo '</div>';
        echo '<p style="margin-top:16px;color:#666;">Tip: Als je provider verschillende poorten gebruikt voor inkomend en uitgaand, zet dan <em>Alternatief poortnummer</em> aan en vul de juiste IMAP-poort in (vaak 993 voor SSL, 143 voor STARTTLS/plain).</p>';
        echo '</div>';
    }

    public static function render_bounces_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        ESN_BW_DB::ensure_tables();

        if (!ESN_BW_DB::table_exists()) {
            echo '<div class="notice notice-error"><p><strong>Fout:</strong> De tabel voor bounces ontbreekt en kon niet worden aangemaakt. Controleer of de databasegebruiker CREATE-rechten heeft.</p></div>';
            return;
        }

        ESN_BW_Bounces_List_Table::render_page();
    }
}
