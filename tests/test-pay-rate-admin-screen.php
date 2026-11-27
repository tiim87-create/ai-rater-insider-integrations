<?php
/** Synthetic admin-screen rendering checks; no WordPress site or real feedback. */
define('ABSPATH',__DIR__.'/');
define('ARI_PAY_RATE_REVIEW_ENABLED',true);
function add_action(...$x) {}
function register_activation_hook(...$x) {}
function current_user_can($cap) { return $cap==='manage_options'; }
function add_management_page(...$args) {}
function admin_url($path='') {return 'https://example.invalid/wp-admin/'.$path;}
function esc_html($v) {return htmlspecialchars((string)$v, ENT_QUOTES,'UTF-8');}
function esc_attr($v) {return htmlspecialchars((string)$v, ENT_QUOTES,'UTF-8');}
function esc_url($v) {return htmlspecialchars((string)$v, ENT_QUOTES,'UTF-8');}
function selected($a,$b,$echo=true) {return $a===$b?'selected="selected"':'';}
function submit_button($text,$type='primary',$name='submit',$wrap=true) {
    echo '<button type="submit">'.esc_html($text).'</button>';
}
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="synthetic">'; }
class ReviewerMockDB {
    public $prefix='test_';
    public $table_exists=true;
    public function prepare($sql,$value) { return str_replace('%s',"'".addslashes($value)."'",$sql); }
    public function get_var($sql) {
        return $this->table_exists?'test_ari_pay_rate_claims':null;
    }
    public function get_results($sql,$output) {
        return array(array(
          'feedback_id'=>72501,
          'claim_json'=>json_encode(array(
            'project'=>'Search Quality Rating', 'vendor'=>'WeloData',
            'country_iso2'=>'US','locale'=>'en-US',
            'role_pool'=>'Search Quality General Pool',
            'rate_amount'=>15.50,'currency'=>'USD','pay_unit'=>'Per hour',
            'evidence_kind'=>'Vendor platform rate screen',
            'evidence_month'=>'2026-11',
            'context_private'=>'PRIVATE_NOT_FOR_HTML',
            'source_url_private'=>'https://internal.example.invalid/',
          )),
          'review_status'=>'pending',
          'evidence_level'=>'unverified',
          'reviewed_at'=>null
        ));
    }
}
$GLOBALS['wpdb']=new ReviewerMockDB();
if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
require_once __DIR__.'/../ari-pay-rate-registry/ari-pay-rate-registry.php';
ob_start();
ARI_Pay_Rate_Admin::screen();
$html=ob_get_clean();
function ensure($ok,$label) {
  if (!$ok) {fwrite(STDERR,"FAIL: $label\n");exit(1);}
  echo "PASS: $label\n";
}
ensure(strpos($html,'Pay Rate Reviews — Staging')!==false,'admin screen heading');
ensure(strpos($html,'Feedback ID')!==false,'manual import form rendered');
ensure(strpos($html,'jetpack-forms-responses-wp-admin')!==false &&
       strpos($html,'response%2F72501')!==false,'reviewer links to original private Jetpack response');
ensure(strpos($html,'2026-11')!==false && strpos($html,'WeloData')!==false,
       'evidence category and period appear for review');
ensure(strpos($html,'PRIVATE_NOT_FOR_HTML')===false &&
       strpos($html,'internal.example.invalid')===false,
       'private raw context and source URL never rendered');
ensure(strpos($html,'approved_for_publication')===false &&
       strpos($html,'selected_as_default')===false,
       'moderation UI cannot trigger public publishing or default preset');
ensure(strpos($html,'ari_pay_rate_review')!==false &&
       strpos($html,'_wpnonce')!==false,
       'moderation includes CSRF protected form');

$diagnostics=ARI_Pay_Rate_Admin::diagnostics();
ensure($diagnostics['Private review table exists']==='Yes' &&
       $diagnostics['Expected Jetpack source page']==='1246',
       'read-only diagnostics show table presence and expected page');
ensure(!array_key_exists('Email',$diagnostics) && !array_key_exists('IP',$diagnostics),
       'diagnostics do not include respondent identity');
$GLOBALS['wpdb']->table_exists=false;
$diagnostics=ARI_Pay_Rate_Admin::diagnostics();
ensure($diagnostics['Private review table exists']==='No',
       'missing table is reported instead of pretending activation succeeded');
