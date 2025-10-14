<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ESN_BW_Bounces_List_Table extends WP_List_Table {
    public function get_columns() {
        return [
            'cb'                 => '<input type="checkbox" />',
            'dr_sender_email'    => 'Sender',
            'dr_final_recipient' => 'Recipient',
            'dr_arrival_date'    => 'Arrival date',
            'subject'            => 'Subject',
            'unseen'             => 'Unread',
            'mailbox'            => 'Mailbox',
        ];
    }

    protected function get_sortable_columns() {
        return [
            'dr_arrival_date' => ['dr_arrival_date', true],
            'subject'         => ['subject', false],
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
        return '<strong>' . $title . '</strong>' . $meta;
    }

    protected function column_unseen($item) {
        return $item['unseen'] ? '<span class="dashicons dashicons-email" title="Unread"></span>' : '';
    }

    protected function column_dr_sender_email($item) {
        return esc_html($item['dr_sender_email'] ?: '');
    }
    protected function column_dr_final_recipient($item) {
        return esc_html($item['dr_final_recipient'] ?: '');
    }
    protected function column_dr_arrival_date($item) {
        // Mooier geformatteerd (respecteert WP-tijdzone)
        if (empty($item['dr_arrival_date']) || $item['dr_arrival_date'] === '0000-00-00 00:00:00') return '';
        $ts = strtotime($item['dr_arrival_date']);
        return esc_html( date_i18n( get_option('date_format').' '.get_option('time_format'), $ts ) );
    }
    protected function column_mailbox($item) {
        return esc_html($item['mailbox'] ?: '');
    }

    public function prepare_items() {
        global $wpdb;
        $table = $wpdb->prefix . 'esn_bounces';

        $per_page = 20;
        $paged    = max(1, intval($_GET['paged'] ?? 1));
        $offset   = ($paged - 1) * $per_page;

        $orderby  = $_GET['orderby'] ?? 'dr_arrival_date';
        $order    = strtoupper($_GET['order'] ?? 'DESC');

        $allowed_orderby = ['dr_arrival_date','subject','id'];
        $orderby = in_array($orderby, $allowed_orderby, true) ? $orderby : 'dr_arrival_date';
        $order   = ($order === 'ASC') ? 'ASC' : 'DESC';

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        // Haal de juiste kolommen op (inclusief de nieuwe dr_* velden)
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, message_id, subject, unseen, mailbox,
                    dr_sender_email, dr_final_recipient, dr_arrival_date
            FROM {$table}
            ORDER BY {$orderby} {$order}, id DESC
            LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A);

        $this->items = $rows ?: [];
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => max(1, ceil($total / $per_page)),
        ]);
    }

    public static function render_page() {
        echo '<div class="wrap"><h1>All bounces</h1>';

        $table = new self();
        $table->prepare_items();

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr(ESN_BW_Core::SLUG) . '" />';
        $table->display();
        echo '</form>';

        global $wpdb;
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . ESN_BW_DB::table_name());
        if ($total === 0) {
            echo '<p class="description">Nog geen bounces opgeslagen. Klik op <em>Instellingen → Handmatig synchroniseren</em> om te importeren.</p>';
        }
        echo '</div>';
    }
}
