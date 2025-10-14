<?php
if (!defined('ABSPATH')) {
  exit;
}

class ESN_BW_GF {

  /** Probeert entries te vinden en te markeren als Bounce. */
  public static function maybe_mark_bounce(string $email, ?string $arrival_mysql, array $cfg, array $opts = []) : int {
    if (empty($cfg['enabled']) || empty($cfg['form_id']) || empty($cfg['email_field_id']) || empty($cfg['status_field_id'])) {
      return 0;
    }
    if (!class_exists('GFAPI')) {
      esn_bw_dbg('gf: GFAPI missing');
      return 0;
    }
    $email_norm = strtolower(trim($email));
    if ($email_norm === '') return 0;

    // Arrival naar UTC voor GF filter (GF date_created is UTC)
    $win = max(1, (int)$cfg['window_minutes']);
    $arrival_utc = null;
    if (!empty($arrival_mysql)) {
      // $arrival_mysql is in WP-tijdzone; converteer naar UTC
      $ts_local = strtotime($arrival_mysql);
      $ts_utc   = $ts_local - (int)get_option('gmt_offset') * 3600;
      $arrival_utc = gmdate('Y-m-d H:i:s', $ts_utc);
    }

    $start = $arrival_utc ? gmdate('Y-m-d H:i:s', strtotime($arrival_utc . ' -'.$win.' minutes')) : null;
    $end   = $arrival_utc ? gmdate('Y-m-d H:i:s', strtotime($arrival_utc . ' +'.$win.' minutes')) : null;

    $search = [
      'status'        => 'active',
      'field_filters' => [
        'mode' => 'all',
        [
          'key'      => (string)$cfg['email_field_id'],
          'value'    => $email_norm,
          'operator' => 'is', // exacte match
        ],
      ],
    ];
    if ($arrival_utc) {
      $search['start_date'] = $start;
      $search['end_date']   = $end;
    }

    $sorting = [ 'key' => 'date_created', 'direction' => 'DESC', 'is_numeric' => false ];
    $paging  = [ 'offset' => 0, 'page_size' => 50 ];

    $entries = GFAPI::get_entries((int)$cfg['form_id'], $search, $sorting, $paging);
    if (is_wp_error($entries)) {
      esn_bw_dbg('gf: get_entries error', ['err' => $entries->get_error_message()]);
      return 0;
    }

    $updated = 0;
    foreach ($entries as $entry) {
      $eid = (int)$entry['id'];

      // huidige statuswaarde lezen
      $current = rgar($entry, (string)$cfg['status_field_id']);
      $current_norm = is_string($current) ? trim($current) : '';
      if (strcasecmp($current_norm, (string)$cfg['status_verified']) === 0) {
        // Niet overschrijven als Verified
        continue;
      }

      // Zet op Bounce
      $ok = GFAPI::update_entry_field($eid, (string)$cfg['status_field_id'], (string)$cfg['status_bounce']);
      if (is_wp_error($ok)) {
        esn_bw_dbg('gf: update_entry_field error', ['entry_id' => $eid, 'err' => $ok->get_error_message()]);
        continue;
      }

      // ✅ Audit meta opslaan (met juiste GF helper)
      if (function_exists('gform_update_meta')) {
        if (!empty($opts['uid'])) {
          gform_update_meta($eid, 'esn_bw_bounce_uid', (int)$opts['uid']);
        }
        if (!empty($opts['recipient'])) {
          gform_update_meta($eid, 'esn_bw_bounce_rcpt', (string)$opts['recipient']);
        }
        if (!empty($arrival_mysql)) {
          gform_update_meta($eid, 'esn_bw_bounce_arrival', $arrival_mysql);
        }
      } else {
        esn_bw_dbg('gf: gform_update_meta missing');
      }

      $updated++;
    }

    esn_bw_dbg('gf: updated entries', ['count' => $updated, 'email' => $email_norm, 'window_min' => $win]);
    return $updated;
  }
}