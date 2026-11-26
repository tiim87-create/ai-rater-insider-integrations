<?php
/** Synthetic-only offline tests: no WordPress or personal records. */
require_once __DIR__.'/../ari-pay-rate-registry/includes/class-ari-pay-rate-review.php';
function check($condition,$message) {
    if (!$condition) { fwrite(STDERR,"FAIL: {$message}\n"); exit(1); }
    echo "PASS: {$message}\n";
}
function example() {
 return array(
  'Project'=>'Search Quality Rating', 'Vendor'=>'WeloData',
  'Role or Pool'=>'Search Quality General Pool', 'Country ISO2'=>'US',
  'Project Locale'=>'en-US', 'Contract Arrangement'=>'Independent contractor',
  'Currency ISO4217'=>'USD', 'Rate Amount'=>'15.50',
  'Pay Unit'=>'Per hour', 'Rate Type'=>'Fixed agreed rate',
  'Evidence Month YYYY-MM'=>'2026-11',
  'Evidence Type'=>'Vendor platform rate screen',
  'Public Source URL optional'=>'https://example.org/official-rate',
  'Evidence Context optional'=>'PRIVATE reviewer-only context',
  'Permission to process anonymous rate facts'=>
   'I agree to private review of my submission and understand that no identifiable information will be published.'
 );
}
$clean=ARI_Pay_Rate_Review::normalize_claim(example());
check(is_array($clean) && $clean['rate_amount']===15.5,'valid synthetic rate normalized');
check(!isset($clean['Permission to process anonymous rate facts']),'consent not copied into canonical claim');
$bad=example(); $bad['Rate Amount']='1e6';
check(ARI_Pay_Rate_Review::normalize_claim($bad)===null,'exponent input rejected');
$bad=example(); $bad['Rate Amount']='-4';
check(ARI_Pay_Rate_Review::normalize_claim($bad)===null,'negative amount rejected');
$bad=example(); $bad['Evidence Month YYYY-MM']='2026-13';
check(ARI_Pay_Rate_Review::normalize_claim($bad)===null,'invalid month rejected');
$bad=example(); $bad['Country ISO2']='USA';
check(ARI_Pay_Rate_Review::normalize_claim($bad)===null,'invalid country rejected');
$bad=example(); $bad['Permission to process anonymous rate facts']='';
check(ARI_Pay_Rate_Review::normalize_claim($bad)===null,'missing consent rejected');
$bad=example(); $bad['Public Source URL optional']='javascript:alert(1)';
check(ARI_Pay_Rate_Review::normalize_claim($bad)===null,'non-HTTPS link rejected');
$bad=example(); $bad['IP']='192.0.2.9'; $bad['Email']='private@example.invalid';
check(ARI_Pay_Rate_Review::normalize_claim($bad)===$clean,'extra private Jetpack metadata ignored');
$claim=array('claim'=>$clean,'public_id'=>'us-welodata-sqr','review_status'=>'pending',
  'approved_for_publication'=>false,'review_evidence_level'=>'unverified',
  'source_label'=>'Reviewed platform rate','selected_as_default'=>true,
  'email'=>'private@example.invalid','feedback_id'=>1246);
$version='2026-11-26.1'; $date='2026-11-26T20:00:00Z';
$pub=ARI_Pay_Rate_Review::public_snapshot(array($claim),$version,$date);
check(is_array($pub) && $pub['rates']===array(),'pending submission cannot publish');
$claim['review_status']='approved';
$pub=ARI_Pay_Rate_Review::public_snapshot(array($claim),$version,$date);
check($pub['rates']===array(),'approved but not publication-approved remains private');
$claim['approved_for_publication']=true;
$pub=ARI_Pay_Rate_Review::public_snapshot(array($claim),$version,$date);
check($pub['rates']===array(),'unverified claim excluded even if approved');
$claim['review_evidence_level']='verified';
$pub=ARI_Pay_Rate_Review::public_snapshot(array($claim),$version,$date);
check(count($pub['rates'])===1 && $pub['rates'][0]['default_preset']===true,'approved verified claim published as preset');
$json=json_encode($pub);
check(strpos($json,'private@example.invalid')===false && strpos($json,'PRIVATE reviewer')===false
  && strpos($json,'feedback_id')===false && strpos($json,'example.org')===false,
  'public snapshot excludes all private fields and source URL');
$claim['claim']['pay_unit']='Per task';
$pub=ARI_Pay_Rate_Review::public_snapshot(array($claim),$version,$date);
check($pub['rates']===array(),'per-task claim cannot become hourly preset');
$claim['selected_as_default']=false;
$pub=ARI_Pay_Rate_Review::public_snapshot(array($claim),$version,$date);
check(count($pub['rates'])===1 && $pub['rates'][0]['default_preset']===false,
  'per-task verified claim can be published without hourly default');
$claim['claim']=$clean; $claim['selected_as_default']=true;
$second=$claim; $second['public_id']='us-welodata-sqr2';
check(ARI_Pay_Rate_Review::public_snapshot(array($claim,$second),$version,$date)===null,
  'duplicate default selection fails entire release');
$claim['review_status']='rejected';
check(ARI_Pay_Rate_Review::public_snapshot(array($claim),$version,$date)['rates']===array(),
  'rejected claim never published');

$optionalMissing=example();
unset($optionalMissing['Public Source URL optional'],$optionalMissing['Evidence Context optional']);
$optional=ARI_Pay_Rate_Review::normalize_claim($optionalMissing);
check(is_array($optional) && $optional['source_url_private']==='' && $optional['context_private']==='',
      'omitted optional Jetpack fields normalize to empty');
$checkbox=example();
$checkbox['Permission to process anonymous rate facts']=array(
    'I agree to private review of my submission and understand that no identifiable information will be published.'
);
check(ARI_Pay_Rate_Review::normalize_claim($checkbox)!==null,
      'one-element Jetpack checkbox array accepted');
$checkbox['Permission to process anonymous rate facts'][]='other';
check(ARI_Pay_Rate_Review::normalize_claim($checkbox)===null,
      'multi-valued checkbox rejected');
$prefixed=example();
$prefixed['0_Project']=$prefixed['Project'];
unset($prefixed['Project']);
check(ARI_Pay_Rate_Review::normalize_claim($prefixed)!==null,
      'Jetpack numeric label prefixes normalized');
$duplicate=example();
$duplicate['1_Project']='Ads Quality Rating';
check(ARI_Pay_Rate_Review::normalize_claim($duplicate)===null,
      'duplicate normalized field labels rejected');
