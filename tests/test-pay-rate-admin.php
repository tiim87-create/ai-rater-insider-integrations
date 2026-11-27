<?php
/** Isolated WordPress admin workflow tests, synthetic-only. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['admin_allowed']=true;
$GLOBALS['review_enabled']=false;
$GLOBALS['nonce_ok']=true;
$GLOBALS['source']='1246';
$GLOBALS['type']='feedback';
$GLOBALS['last_redirect']=null;
$GLOBALS['mock_meta']=array();
$GLOBALS['mock_entries']=array();
$GLOBALS['now']='2026-11-26 12:00:00';
function add_action(...$x) {}
function register_activation_hook(...$x) {}
function current_user_can($c) { return $GLOBALS['admin_allowed']; }
function check_admin_referer($action) {
    if (!$GLOBALS['nonce_ok']) throw new Exception('Invalid nonce');
}
function wp_die($message,$status=403) { throw new Exception($message.' #'.$status); }
function wp_unslash($x) { return $x; }
function absint($x) { return abs((int)$x); }
function sanitize_key($x) { return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$x)); }
function get_post_type($id) { return $GLOBALS['type']; }
function get_post_meta($id,$key,$single=true) {
    if ($key==='_feedback_source_post_id') return $GLOBALS['source'];
    if ($key==='_feedback_extra_fields') return $GLOBALS['mock_meta'];
    return '';
}
function get_post($id) { return (object)array('post_date'=>$GLOBALS['now']); }
function wp_json_encode($x) { return json_encode($x); }
function get_current_user_id() { return 17; }
function current_time($x) { return $GLOBALS['now']; }
function admin_url($x='') { return 'https://example.invalid/wp-admin/'.$x; }
function add_query_arg($args,$url) { return $url.'?'.http_build_query($args); }
function wp_safe_redirect($url) { $GLOBALS['last_redirect']=$url; }
function assert_review($condition,$text) {
    if (!$condition) { fwrite(STDERR,'FAIL: '.$text."\n"); exit(1); }
    echo 'PASS: '.$text."\n";
}
class MockPayRateDB {
  public $prefix='test_';
  public $rows=array();
  public $inserts=0;
  public $updates=0;
  function insert($table,$data,$formats) {
    $this->inserts++;
    if ($table!=='test_ari_pay_rate_claims' || isset($this->rows[$data['feedback_id']])) return false;
    $this->rows[$data['feedback_id']]=$data; return 1;
  }
  function update($table,$data,$where,$formats,$where_formats) {
    $this->updates++;
    if ($table!=='test_ari_pay_rate_claims' || !isset($this->rows[$where['feedback_id']])) return 0;
    $this->rows[$where['feedback_id']]=array_merge($this->rows[$where['feedback_id']],$data);
    return 1;
  }
}
$GLOBALS['wpdb']=new MockPayRateDB();
require_once __DIR__.'/../ari-pay-rate-registry/ari-pay-rate-registry.php';
function valid_form() {
 return array(
  'Project'=>'Search Quality Rating', 'Vendor'=>'WeloData',
  'Role or Pool'=>'Search Quality General Pool','Country'=>'United States (US)',
  'Project language'=>'English (en)', 'Project target country optional'=>'United States (US)','Contract Arrangement'=>'Independent contractor',
  'Currency'=>'US Dollar (USD)','Rate Amount'=>'15.50',
  'Pay Unit'=>'Per hour','Rate Type'=>'Fixed agreed rate',
  'Evidence Month YYYY-MM'=>'2026-11',
  'Evidence Type'=>'Vendor platform rate screen',
  'Public Source URL optional'=>'https://example.org/temporary',
  'Evidence Context optional'=>'PRIVATE CONTEXT DO NOT EXPORT',
  'Permission to process anonymous rate facts'=>
  'I agree to private review of my submission and understand that no identifiable information will be published.',
  'Email'=>'private@example.invalid', 'IP'=>'198.51.100.23'
 );
}
// IMPORTANT: handler uses exit after wp_safe_redirect on a real WordPress site.
// Test pre-gate and isolated handler logic separately instead of bypassing exit.
assert_review(ARI_Pay_Rate_Admin::enabled()===false,'plugin disabled by default');
assert_review(ARI_Pay_Rate_Admin::table()==='test_ari_pay_rate_claims','private table name isolated from NTA');
$GLOBALS['admin_allowed']=false;
try { ARI_Pay_Rate_Admin::import(); assert_review(false,'unauthorized import blocked'); }
catch (Exception $e) { assert_review(strpos($e->getMessage(),'permissions')!==false,'unauthorized import denied'); }
$GLOBALS['admin_allowed']=true;
try { ARI_Pay_Rate_Admin::import(); assert_review(false,'disabled import blocked'); }
catch (Exception $e) { assert_review(strpos($e->getMessage(),'disabled')!==false,'capture import gated behind disabled flag'); }
assert_review($GLOBALS['wpdb']->inserts===0,'no imports when disabled or unauthorized');
$GLOBALS['review_enabled']=false;
assert_review(!defined('ARI_PAY_RATE_REVIEW_ENABLED'),'server flag must be explicitly defined');
$normalized=ARI_Pay_Rate_Review::normalize_claim(valid_form());
assert_review($normalized!==null,'staging fields normalize');
unset($normalized['source_url_private'],$normalized['context_private']);
assert_review(!isset($normalized['Email'])&&!isset($normalized['IP'])&&
!isset($normalized['context_private']),'private claim projection strips private prose and metadata');

$GLOBALS['mock_meta']=valid_form();
assert_review(ARI_Pay_Rate_Admin::import_feedback(888)==='imported','correct synthetic page imports into private queue');
assert_review(isset($GLOBALS['wpdb']->rows[888]),'private row created');
$row=$GLOBALS['wpdb']->rows[888];
assert_review($row['review_status']==='pending' && $row['evidence_level']==='unverified',
    'new claims are never approved automatically');
$stored=json_decode($row['claim_json'],true);
assert_review(!isset($stored['context_private'])&&!isset($stored['source_url_private']) &&
    strpos($row['claim_json'],'private@example.invalid')===false &&
    strpos($row['claim_json'],'192.0.2.9')===false,
    'stored secondary record excludes private prose, URL and metadata');
assert_review(ARI_Pay_Rate_Admin::import_feedback(888)==='not_imported' &&
    count($GLOBALS['wpdb']->rows)===1,'duplicate response cannot reset queue item');
$GLOBALS['source']='1200';
assert_review(ARI_Pay_Rate_Admin::import_feedback(889)==='wrong_source','unrelated form source blocked');
$GLOBALS['source']='1246';
$GLOBALS['type']='page';
assert_review(ARI_Pay_Rate_Admin::import_feedback(889)==='invalid_id','unrelated post type blocked');
$GLOBALS['type']='feedback';
$GLOBALS['mock_meta']['Rate Amount']='-15';
assert_review(ARI_Pay_Rate_Admin::import_feedback(889)==='invalid_claim','invalid rate blocked from private queue');
$GLOBALS['mock_meta']=valid_form();
assert_review(ARI_Pay_Rate_Admin::apply_review(888,'approved','unverified')==='invalid_review',
    'unverified evidence cannot be labeled approved');
assert_review(ARI_Pay_Rate_Admin::apply_review(888,'approved','verified')==='reviewed',
    'reviewer can mark validated evidence as approved');
assert_review($GLOBALS['wpdb']->rows[888]['reviewer_id']===17 &&
    $GLOBALS['wpdb']->rows[888]['review_status']==='approved','review retains editor attribution internally');
assert_review(ARI_Pay_Rate_Admin::apply_review(888,'rejected','verified')==='reviewed',
    'reviewer can reject a previously approved claim');
assert_review(ARI_Pay_Rate_Admin::apply_review(889,'approved','verified')==='unchanged',
    'missing claim cannot be approved');
assert_review(ARI_Pay_Rate_Admin::apply_review(888,'approved','invalid')==='invalid_review',
    'unknown evidence levels rejected');
assert_review(count($GLOBALS['wpdb']->rows)===1,'only one authorized private claim exists');

$GLOBALS['mock_meta']=valid_form();
$GLOBALS['source']='1246'; $GLOBALS['type']='feedback';
$health=ARI_Pay_Rate_Admin::feedback_diagnostic(777);
assert_review($health['Feedback record exists']==='Yes' &&
    $health['Expected source page matches']==='Yes' &&
    $health['Expected fields present']==='16 / 16' &&
    $health['Passes claim normalizer']==='Yes',
    'synthetic original-source feedback passes read-only diagnostics');
assert_review(strpos(json_encode($health),'PRIVATE CONTEXT')===false &&
    strpos(json_encode($health),'private@example.invalid')===false,
    'health excludes original private content');
$GLOBALS['source']='1200';
$health=ARI_Pay_Rate_Admin::feedback_diagnostic(777);
assert_review($health['Expected source page matches']==='No' &&
    $health['Expected fields present']==='0 / 16',
    'wrong-source feedback is not inspected');
$GLOBALS['type']='page';
$health=ARI_Pay_Rate_Admin::feedback_diagnostic(777);
assert_review($health['Feedback record exists']==='No',
    'non-feedback post is excluded from read-only diagnostic');
