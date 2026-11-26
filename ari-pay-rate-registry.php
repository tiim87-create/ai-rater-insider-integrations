<?php
/**
 * Plugin Name: AI Rater Insider — Pay Rate Review (Staging)
 * Description: Admin-only, opt-in review queue for privacy-minimized Jetpack rate claims. No public APIs or automatic publishing.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/includes/class-ari-pay-rate-review.php';

final class ARI_Pay_Rate_Admin {
    private const TABLE = 'ari_pay_rate_claims';
    private const SOURCE_PAGE = 1246;
    private const ENABLE_FLAG = 'ARI_PAY_RATE_REVIEW_ENABLED';
    private const STATUSES = array('pending','needs_evidence','approved','rejected');
    private const LEVELS = array('unverified','corroborated','verified');

    public static function enabled() {
        return defined(self::ENABLE_FLAG) && constant(self::ENABLE_FLAG) === true;
    }
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }
    public static function activate() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table();
        $collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            feedback_id bigint(20) unsigned NOT NULL,
            claim_json longtext NOT NULL,
            review_status varchar(32) NOT NULL DEFAULT 'pending',
            evidence_level varchar(32) NOT NULL DEFAULT 'unverified',
            reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (feedback_id),
            KEY review_status (review_status)
        ) {$collate};");
    }
    public static function init() {
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_post_ari_pay_rate_import',array(__CLASS__,'import'));
        add_action('admin_post_ari_pay_rate_review',array(__CLASS__,'review'));
    }
    public static function menu() {
        add_management_page('Pay Rate Reviews','Pay Rate Reviews','manage_options',
            'ari-pay-rate-reviews',array(__CLASS__,'screen'));
    }
    private static function gate($action) {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.',403);
        }
        check_admin_referer($action);
        if (!self::enabled()) {
            wp_die('Pay Rate Review staging is disabled.',403);
        }
    }
    private static function go($message) {
        wp_safe_redirect(add_query_arg(
            array('page'=>'ari-pay-rate-reviews','ari_result'=>$message),
            admin_url('tools.php')));
        exit;
    }
    /**
     * Explicit manual import only, no Jetpack background hook.
     * Never copy raw feedback data, private source URL, prose or attachments.
     */
    public static function import() {
        self::gate('ari_pay_rate_import');
        $id=isset($_POST['feedback_id'])?absint(wp_unslash($_POST['feedback_id'])):0;
        self::go(self::import_feedback($id));
    }
    /**
     * Isolated import logic; called only after the capability, nonce and OFF-by-default gate.
     * Exposed for synthetic unit testing. No public action or unauthenticated route.
     */
    public static function import_feedback($id) {
        $id=(int)$id;
        if ($id<=0 || get_post_type($id)!=='feedback') return 'invalid_id';
        if ((string)get_post_meta($id,'_feedback_source_post_id',true)!==(string)self::SOURCE_PAGE) return 'wrong_source';
        $post=get_post($id);
        if (!$post) return 'missing_feedback';
        $fields=get_post_meta($id,'_feedback_extra_fields',true);
        $claim=ARI_Pay_Rate_Review::normalize_claim($fields);
        if (!$claim) return 'invalid_claim';
        // Secondary database holds ONLY normalized wage facts.
        unset($claim['source_url_private'],$claim['context_private']);
        global $wpdb;
        $insert=$wpdb->insert(self::table(),array(
            'feedback_id'=>$id,
            'claim_json'=>wp_json_encode($claim),
            'review_status'=>'pending',
            'evidence_level'=>'unverified',
            'reviewer_id'=>0,
            'created_at'=>$post->post_date,
        ),array('%d','%s','%s','%s','%d','%s'));
        // Feedback primary key protects review status against duplicate imports.
        return $insert===1?'imported':'not_imported';
    }
    /**
     * Human moderation only. "Approved" NEVER publishes and cannot imply
     * verified evidence if reviewer explicitly selected "unverified".
     */
    public static function review() {
        self::gate('ari_pay_rate_review');
        $id=isset($_POST['feedback_id'])?absint(wp_unslash($_POST['feedback_id'])):0;
        $status=isset($_POST['review_status'])?sanitize_key(wp_unslash($_POST['review_status'])):'';
        $level=isset($_POST['evidence_level'])?sanitize_key(wp_unslash($_POST['evidence_level'])):'';
        self::go(self::apply_review($id,$status,$level));
    }
    public static function apply_review($id,$status,$level) {
        $id=(int)$id;
        if ($id<=0 || !in_array($status,self::STATUSES,true) ||
            !in_array($level,self::LEVELS,true) ||
            ($status==='approved' && $level==='unverified')) return 'invalid_review';
        global $wpdb;
        $res=$wpdb->update(self::table(),array(
            'review_status'=>$status,
            'evidence_level'=>$level,
            'reviewer_id'=>get_current_user_id(),
            'reviewed_at'=>current_time('mysql'),
        ),array('feedback_id'=>$id),
          array('%s','%s','%d','%s'),array('%d'));
        return $res!==false && $res>0?'reviewed':'unchanged';
    }
    public static function screen() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        echo '<div class="wrap"><h1>Pay Rate Reviews — Staging</h1>';
        if (!self::enabled()) {
            echo '<p><strong>Disabled.</strong> No imports or review changes are permitted. Configure the explicit server-side review flag after a controlled test.</p></div>';
            return;
        }
        echo '<p>Private admin queue only. No contributor uploads, automatic publishing, or calculator synchronization.</p>';
        if (isset($_GET['ari_result'])) {
            echo '<div class="notice notice-info"><p>'.esc_html(sanitize_key(wp_unslash($_GET['ari_result']))).'</p></div>';
        }
        echo '<h2>Import a validated Jetpack response</h2>';
        echo '<p>Accepts only feedback attributed to staging source page 1246; import deliberately discards free text and URLs.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="ari_pay_rate_import">';
        wp_nonce_field('ari_pay_rate_import');
        echo '<label>Feedback ID <input type="number" name="feedback_id" min="1" required></label> ';
        submit_button('Import private claim','secondary','submit',false);
        echo '</form>';
        global $wpdb;
        $table=self::table();
        $rows=$wpdb->get_results("SELECT feedback_id,claim_json,review_status,evidence_level,reviewed_at
                 FROM {$table} ORDER BY feedback_id DESC LIMIT 100",ARRAY_A);
        echo '<h2>Latest claims (up to 100)</h2><table class="widefat striped"><thead><tr>';
        echo '<th>Feedback</th><th>Project / Vendor</th><th>Market / Role</th><th>Claimed rate</th><th>Evidence / period</th><th>Review status</th><th>Action</th>';
        echo '</tr></thead><tbody>';
        foreach((array)$rows as $row) {
            $claim=json_decode($row['claim_json'],true);
            if (!is_array($claim)) continue;
            $id=(int)$row['feedback_id'];
            // This private wp-admin link is visible only to manage_options users.
            // No private feedback content or uploads are copied into this table.
            $feedback_url=admin_url('admin.php?page=jetpack-forms-responses-wp-admin&p='.rawurlencode('/response/'.$id));
            echo '<tr><td><a href="'.esc_url($feedback_url).'" target="_blank" rel="noopener noreferrer">'.esc_html((string)$id).'</a></td>';
            echo '<td>'.esc_html(($claim['project']??'').' / '.($claim['vendor']??'')).'</td>';
            echo '<td>'.esc_html(($claim['country_iso2']??'').' / '.($claim['locale']??'').' / '.($claim['role_pool']??'')).'</td>';
            echo '<td>'.esc_html((string)($claim['rate_amount']??'').' '.($claim['currency']??'').' / '.($claim['pay_unit']??'')).'</td>';
            echo '<td>'.esc_html(($claim['evidence_kind']??'').' / '.($claim['evidence_month']??'')).'</td>';
            echo '<td>'.esc_html($row['review_status'].' / '.$row['evidence_level']).'<br><small>'.esc_html($row['reviewed_at']??'').'</small></td>';
            echo '<td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="ari_pay_rate_review">';
            echo '<input type="hidden" name="feedback_id" value="'.esc_attr((string)$id).'">';
            wp_nonce_field('ari_pay_rate_review');
            echo '<select name="review_status">';
            foreach(self::STATUSES as $value) {
                echo '<option value="'.esc_attr($value).'" '.selected($row['review_status'],$value,false).'>'.esc_html($value).'</option>';
            }
            echo '</select> <select name="evidence_level">';
            foreach(self::LEVELS as $value) {
                echo '<option value="'.esc_attr($value).'" '.selected($row['evidence_level'],$value,false).'>'.esc_html($value).'</option>';
            }
            echo '</select> ';
            submit_button('Save review','small','submit',false);
            echo '</form></td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="7">No imported claims.</td></tr>';
        echo '</tbody></table></div>';
    }
}
register_activation_hook(__FILE__,array('ARI_Pay_Rate_Admin','activate'));
ARI_Pay_Rate_Admin::init();
