<?php
require_once __DIR__.'/../ari-nta-bridge/includes/class-ari-nta-schema.php';
function entry() {
    return array(
      'Project'=>'search_quality','Vendor'=>'telus','Country (ISO code)'=>'IT',
      'Project language / locale'=>'it-IT','Week beginning Monday'=>'2026-12-07',
      'Days attempted to work'=>'3','Days with NTA'=>'2',
      'Completed paid-equivalent hours (0.5-hour steps)'=>'6.0',
      'How are hours measured?'=>'vendor_report',
      'Source of weekly figures'=>'time_log','Account access status'=>'active'
    );
}
function expect($ok,$name) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$name}\n"); exit(1); }
    echo "PASS: {$name}\n";
}
expect(count(ARI_NTA_Schema::normalize(entry()))===11,'accept 11 fields');
$x=entry();$x[' IP Address']='198.51.100.1';
expect(!isset(ARI_NTA_Schema::normalize($x)[' IP Address']),'strip extra metadata');
$x=entry();unset($x['Project']);
expect(ARI_NTA_Schema::normalize($x)===null,'missing field rejected');
$x=entry();$x['Week beginning Monday']='2026-12-08';
expect(ARI_NTA_Schema::normalize($x)===null,'non-Monday rejected');
$x=entry();$x['Days with NTA']='4';
expect(ARI_NTA_Schema::normalize($x)===null,'NTA exceeds attempts rejected');
$x=entry();$x['Completed paid-equivalent hours (0.5-hour steps)']='6.3';
expect(ARI_NTA_Schema::normalize($x)===null,'invalid half-hour rejected');
$x=entry();$x['Account access status']='quality_hold';
expect(ARI_NTA_Schema::normalize($x)!==null,'quality hold preserved for downstream exclusion');
$x=entry();$x['Days attempted to work']='0';$x['Days with NTA']='0';
expect(ARI_NTA_Schema::normalize($x)===null,'paid hours without attempts rejected');
$x=entry();$x['Project']='<script>';
expect(ARI_NTA_Schema::normalize($x)===null,'unknown category rejected');

$x=entry();$indexed=array();$i=0;
foreach($x as $key=>$value){$indexed[(string)$i.'_'.$key]=$value;$i++;}
expect(ARI_NTA_Schema::from_jetpack($indexed)!==null,'Jetpack indexed labels normalized');
$shape=ARI_NTA_Schema::field_shape($indexed);
expect($shape['count']===11 && !$shape['duplicates'],'Jetpack indexed labels shape recognizes 11');
$indexed['999_Project']='ads_quality';
expect(ARI_NTA_Schema::from_jetpack($indexed)===null,'duplicate Jetpack labels rejected');
$indexed=array('4_Project'=>'search_quality',' IP Address'=>'private');
expect(ARI_NTA_Schema::field_shape($indexed)['count']===1,'unknown private keys ignored');

$details=ARI_NTA_Schema::validate_shape_and_values(entry());
expect(count(array_filter($details['valid']))===11,'all eleven values individually valid');
expect(!in_array(true,$details['flags'],true),'valid report has no cross-field errors');
$x=entry();$x['Vendor']='nonsense';
$details=ARI_NTA_Schema::validate_shape_and_values($x);
expect(!$details['valid']['Vendor'] && $details['valid']['Project'],
  'invalid vendor reported without values');
$x=entry();$x['Days attempted to work']='2';$x['Days with NTA']='3';
$details=ARI_NTA_Schema::validate_shape_and_values($x);
expect($details['flags']['nta_exceeds_attempted'],'cross-field NTA violation reported');
$x=entry();$x['Days attempted to work']='0';
$details=ARI_NTA_Schema::validate_shape_and_values($x);
expect($details['flags']['paid_hours_without_attempts'],'zero-attempt hours violation reported');
$x=entry();$x['Vendor']=array('secret'=>'not-for-output');
$details=ARI_NTA_Schema::validate_shape_and_values($x);
expect(!$details['valid']['Vendor'],'non-scalar field rejected without inspecting value');
