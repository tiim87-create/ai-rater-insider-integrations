<?php
/**
 * Offline capture-ON staging checks, using synthetic values and an in-memory
 * database double. This NEVER enables capture on WordPress.
 */
define('ABSPATH', __DIR__ . '/');
define('ARI_NTA_CAPTURE_ENABLED', true);

$GLOBALS['test_source']='1200';
$GLOBALS['test_date']='2026-12-08 12:00:00';
$GLOBALS['test_post_type']='feedback';
$GLOBALS['test_meta']=array();
$GLOBALS['test_options']=array();

function add_action(...$a) {}
function add_filter(...$a) {}
function register_activation_hook(...$a) {}
function get_post_type($id) { return $GLOBALS['test_post_type']; }
function get_post_meta($id, $key, $single=true) {
    if ($key==='_feedback_source_post_id') return $GLOBALS['test_source'];
    if ($key==='_feedback_extra_fields') return $GLOBALS['test_meta'];
    return '';
}
function update_option($key,$value,$autoload=false) {
    $GLOBALS['test_options'][$key]=$value;
    return true;
}
function get_post($id) {
    if ($GLOBALS['test_date']===null) return false;
    return (object)array('post_date'=>$GLOBALS['test_date']);
}
function wp_json_encode($value) { return json_encode($value); }

final class InMemoryNtaDB {
    public $prefix='test_';
    public $records=array();
    public $calls=0;
    public function insert($table,$data,$format) {
        $this->calls++;
        if ($table!=='test_ari_nta_weekly') return false;
        if (isset($this->records[$data['feedback_id']])) return false;
        $this->records[$data['feedback_id']]=$data;
        return 1;
    }
}
$GLOBALS['wpdb']=new InMemoryNtaDB();
require_once __DIR__.'/../ari-nta-bridge/ari-nta-bridge.php';

function assert_gate($ok,$message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
function entry() {
    return array(
        'Project'=>'search_quality', 'Vendor'=>'telus',
        'Country (ISO code)'=>'IT','Project language / locale'=>'it-IT',
        'Week beginning Monday'=>'2026-12-07',
        'Days attempted to work'=>'3','Days with NTA'=>'2',
        'Completed paid-equivalent hours (0.5-hour steps)'=>'6.0',
        'How are hours measured?'=>'vendor_report',
        'Source of weekly figures'=>'time_log','Account access status'=>'active'
    );
}
$GLOBALS['test_meta']=entry();
assert_gate(ARI_NTA_Bridge::is_capture_enabled(),'capture-on test setting effective ONLY in isolated process');

$GLOBALS['test_post_type']='page';
ARI_NTA_Bridge::process_saved_feedback(99901);
assert_gate($GLOBALS['wpdb']->calls===0,'unrelated post type cannot insert');
$GLOBALS['test_post_type']='feedback';

$GLOBALS['test_source']='1204';
ARI_NTA_Bridge::process_saved_feedback(99901);
assert_gate($GLOBALS['wpdb']->calls===0,'incorrect source cannot insert');
$GLOBALS['test_source']='1200';

$GLOBALS['test_date']='2026-11-30 13:00:00';
ARI_NTA_Bridge::process_saved_feedback(99901);
assert_gate($GLOBALS['wpdb']->calls===0,'prelaunch November response cannot insert');

$GLOBALS['test_date']=null;
ARI_NTA_Bridge::process_saved_feedback(99901);
assert_gate($GLOBALS['wpdb']->calls===0,'missing feedback post cannot insert');

$GLOBALS['test_date']='2026-12-08 12:00:00';
$GLOBALS['test_meta']['Days with NTA']='4';
ARI_NTA_Bridge::process_saved_feedback(99901);
assert_gate($GLOBALS['wpdb']->calls===0,'invalid cross-field values cannot insert');
$GLOBALS['test_meta']=entry();
// Deliberately inject unexpected private metadata into the synthetic Jetpack payload.
$GLOBALS['test_meta']['email']='nobody@example.invalid';
$GLOBALS['test_meta']['IP Address']='198.51.100.2';

ARI_NTA_Bridge::process_saved_feedback(99901);
assert_gate(count($GLOBALS['wpdb']->records)===1,'valid post-launch synthetic response inserted');
$saved=$GLOBALS['wpdb']->records[99901];
assert_gate($saved['created_at']==='2026-12-08 12:00:00','original submission date retained');
$decoded=json_decode($saved['payload'],true);
assert_gate(is_array($decoded) && count($decoded)===11,'only 11 normalized fields stored');
assert_gate(!isset($decoded['email']) && !isset($decoded['IP Address']),
    'no identity or IP fields stored');

ARI_NTA_Bridge::process_saved_feedback(99901);
assert_gate(count($GLOBALS['wpdb']->records)===1,'database primary-key simulation prevents duplicate');
