<?php
/**
 * Offline NTA Bridge capture-gate tests. All inputs synthetic; no WordPress
 * server, network, database or real respondent data is accessed.
 */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['nta_test_source'] = '1175';
$GLOBALS['nta_test_entry'] = array();
$GLOBALS['nta_test_options'] = array();
$GLOBALS['nta_test_inserts'] = 0;

function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function get_post_type($id) { return (int)$id === 99901 ? 'feedback' : 'post'; }
function get_post_meta($id, $key, $single = true) {
    if ($key === '_feedback_source_post_id') return $GLOBALS['nta_test_source'];
    if ($key === '_feedback_extra_fields') return $GLOBALS['nta_test_entry'];
    return '';
}
function update_option($key,$value,$autoload=false) {
    $GLOBALS['nta_test_options'][$key] = $value;
    return true;
}
function get_post($id) { return (object)array('post_date'=>'2026-12-08 12:00:00'); }

class ARI_Test_DB {
    public $prefix = 'test_';
    public function insert(...$args) { $GLOBALS['nta_test_inserts']++; return 1; }
}
$GLOBALS['wpdb'] = new ARI_Test_DB();

require_once __DIR__ . '/../ari-nta-bridge/ari-nta-bridge.php';
function expect_gate($condition, $message) {
    if (!$condition) { fwrite(STDERR,"FAIL: {$message}\n"); exit(1); }
    echo "PASS: {$message}\n";
}
function synthetic_entry() {
    return array(
        'Project'=>'search_quality',
        'Vendor'=>'telus',
        'Country (ISO code)'=>'IT',
        'Project language / locale'=>'it-IT',
        'Week beginning Monday'=>'2026-12-07',
        'Days attempted to work'=>'3',
        'Days with NTA'=>'2',
        'Completed paid-equivalent hours (0.5-hour steps)'=>'6.0',
        'How are hours measured?'=>'vendor_report',
        'Source of weekly figures'=>'time_log',
        'Account access status'=>'active'
    );
}

$GLOBALS['nta_test_entry'] = synthetic_entry();
ARI_NTA_Bridge::process_saved_feedback(99902);
expect_gate(count($GLOBALS['nta_test_options'])===0, 'unrelated post type ignored');

$GLOBALS['nta_test_source'] = '1204';
ARI_NTA_Bridge::process_saved_feedback(99901);
expect_gate(count($GLOBALS['nta_test_options'])===0, 'incorrect Jetpack source ignored');

$GLOBALS['nta_test_source'] = '1200';
ARI_NTA_Bridge::process_saved_feedback(99901);
expect_gate(count($GLOBALS['nta_test_options'])===0, 'legacy draft page 1200 is not authorized');

$GLOBALS['nta_test_source'] = '1175';
ARI_NTA_Bridge::process_saved_feedback(99901);
$shape = $GLOBALS['nta_test_options']['ari_nta_last_hook_shape'] ?? array();
expect_gate(($shape['expected_fields_present']??0)===11, '11 whitelisted labels recognized');
expect_gate(($shape['passes_normalizer']??false)===true, 'valid synthetic form normalizes');
expect_gate($GLOBALS['nta_test_inserts']===0, 'capture disabled: no database inserts');

$GLOBALS['nta_test_entry']['Days with NTA']='4';
ARI_NTA_Bridge::process_saved_feedback(99901);
$shape = $GLOBALS['nta_test_options']['ari_nta_last_hook_shape'] ?? array();
expect_gate(($shape['passes_normalizer']??true)===false, 'inconsistent NTA counts rejected');
expect_gate(($shape['cross_field_flags']['nta_exceeds_attempted']??false)===true, 'cross-field reason classified');
expect_gate($GLOBALS['nta_test_inserts']===0, 'invalid responses never saved with capture disabled');
