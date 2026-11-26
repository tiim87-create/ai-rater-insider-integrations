<?php
/**
 * Pure, WordPress-independent validation and publication projection.
 * No form hook, DB access, public route or automatic publishing is defined here.
 */
final class ARI_Pay_Rate_Review {
    public const FIELDS = array(
        'Project', 'Vendor', 'Role or Pool', 'Country ISO2', 'Project Locale',
        'Contract Arrangement', 'Currency ISO4217', 'Rate Amount', 'Pay Unit',
        'Rate Type', 'Evidence Month YYYY-MM', 'Evidence Type',
        'Public Source URL optional', 'Evidence Context optional',
        'Permission to process anonymous rate facts',
    );

    private const PROJECTS = array('Search Quality Rating', 'Ads Quality Rating', 'Internet Safety', 'Maps and Local', 'Other');
    private const VENDORS = array('TELUS Digital', 'WeloData', 'RWS TrainAI', 'OneForma', 'Other');
    private const ROLES = array('Search Quality General Pool', 'Search Quality Factuality', 'Search Quality AI Helpfulness', 'Ads Quality Rater', 'Internet Safety Rater', 'Maps Analyst', 'Other or unspecified');
    private const CONTRACTS = array('Independent contractor', 'Employee', 'Unknown');
    private const UNITS = array('Per hour', 'Per task', 'Per 1000 tasks', 'Other or unclear');
    private const RATE_TYPES = array('Fixed agreed rate', 'Task-based equivalent estimate', 'Advertised up-to ceiling', 'Other or unclear');
    private const EVIDENCE = array('Vendor platform rate screen', 'Online contract or agreement', 'Vendor email or rate notice', 'Invoice or payment statement', 'Earnings report or payslip', 'Official job posting', 'Other screenshot or document', 'Personal report without documentation');
    private const CONSENT = 'I agree to private review of my submission and understand that no identifiable information will be published.';

    public static function normalize_claim( $input ) {
        if ( !is_array($input) ) return null;
        $fields = array();
        foreach ($input as $key=>$value) {
            if (!is_string($key)) continue;
            $label = $key;
            if (!in_array($label,self::FIELDS,true)) {
                if (!preg_match('/^[0-9]+_(.+)$/D',$key,$match)) continue;
                $label = $match[1];
            }
            if (!in_array($label,self::FIELDS,true)) continue;
            if (array_key_exists($label,$fields) || (!is_string($value) && !is_numeric($value))) return null;
            $fields[$label] = trim((string)$value);
        }
        foreach(self::FIELDS as $field) {
            if (!array_key_exists($field,$fields)) return null;
            if (strlen($fields[$field]) > 500) return null;
        }
        if (!in_array($fields['Project'],self::PROJECTS,true) ||
            !in_array($fields['Vendor'],self::VENDORS,true) ||
            !in_array($fields['Role or Pool'],self::ROLES,true) ||
            !in_array($fields['Contract Arrangement'],self::CONTRACTS,true) ||
            !in_array($fields['Pay Unit'],self::UNITS,true) ||
            !in_array($fields['Rate Type'],self::RATE_TYPES,true) ||
            !in_array($fields['Evidence Type'],self::EVIDENCE,true)) return null;

        if (!preg_match('/^[A-Z]{2}$/D',$fields['Country ISO2']) ||
            !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$fields['Project Locale']) ||
            !preg_match('/^[A-Z]{3}$/D',$fields['Currency ISO4217'])) return null;
        // Dates must be real calendar months, not arbitrary input strings.
        $month=$fields['Evidence Month YYYY-MM'];
        if (!preg_match('/^20\\d\\d-(0[1-9]|1[0-2])$/D',$month)) return null;
        $rate=$fields['Rate Amount'];
        if (!preg_match('/^(?:0|[1-9]\\d{0,7})(?:\\.\\d{1,4})?$/D',$rate) ||
            (float)$rate<=0 || (float)$rate>10000000) return null;
        if ($fields['Permission to process anonymous rate facts']!==self::CONSENT) return null;
        $url=$fields['Public Source URL optional'];
        if ($url!=='' && (!preg_match('~^https://[a-z0-9.-]+(?::443)?(?:/[^\\s]*)?$~iD',$url) || strlen($url)>300)) return null;
        // Private prose may be stored only for reviewer use; must never enter public output.
        return array(
          'project'=>$fields['Project'], 'vendor'=>$fields['Vendor'],
          'role_pool'=>$fields['Role or Pool'], 'country_iso2'=>$fields['Country ISO2'],
          'locale'=>$fields['Project Locale'], 'contract_type'=>$fields['Contract Arrangement'],
          'currency'=>$fields['Currency ISO4217'], 'rate_amount'=>(float)$rate,
          'pay_unit'=>$fields['Pay Unit'], 'rate_type'=>$fields['Rate Type'],
          'evidence_month'=>$month, 'evidence_kind'=>$fields['Evidence Type'],
          'source_url_private'=>$url, 'context_private'=>$fields['Evidence Context optional']
        );
    }

    /**
     * Build a snapshot only from explicit human-reviewed, publication-approved claims.
     * Evidence source labels MUST be chosen by the reviewer from an allowlist.
     * Any personal fields or free-text source URLs remain private.
     */
    public static function public_snapshot( $claims, $version, $generated_at ) {
        if (!is_array($claims) || !preg_match('/^20\\d{2}-\\d\\d-\\d\\d\\.\\d+$/D',$version) ||
            !preg_match('/^20\\d{2}-\\d\\d-\\d\\dT\\d\\d:\\d\\d:\\d\\dZ$/D',$generated_at)) return null;
        $rates=array();
        $defaults=array();
        foreach($claims as $entry) {
            if (!is_array($entry) ||
                ($entry['review_status']??'')!=='approved' ||
                ($entry['approved_for_publication']??false)!==true ||
                !in_array($entry['review_evidence_level']??'',array('verified','corroborated'),true)) continue;
            $source=$entry['source_label']??'';
            if (!in_array($source,array('Reviewed platform rate','Reviewed contract terms','Reviewed vendor notice','Reviewed payment evidence','Reviewed official posting','Independently corroborated rate'),true)) continue;
            if (!isset($entry['claim']) || !is_array($entry['claim'])) continue;
            $c=$entry['claim'];
            $mandatory=array('project','vendor','role_pool','country_iso2','locale','contract_type','currency','rate_amount','pay_unit','rate_type','evidence_month');
            if (array_diff($mandatory,array_keys($c))) continue;
            if (!is_string($entry['public_id']??null) || !preg_match('/^[a-z0-9-]{3,64}$/D',$entry['public_id'])) continue;
            // No invented hourly conversion: non-hourly sources are not presets.
            $default=($entry['selected_as_default']??false)===true;
            if ($default && ($c['pay_unit']!=='Per hour' ||
                 $c['rate_type']!=='Fixed agreed rate')) continue;
            $key=implode('|',array($c['vendor'],$c['project'],$c['role_pool'],$c['country_iso2'],$c['locale'],$c['contract_type']));
            if ($default && isset($defaults[$key])) return null; // Refuse ambiguous defaults.
            if ($default) $defaults[$key]=true;
            if (!is_numeric($c['rate_amount']) || (float)$c['rate_amount']<=0 || (float)$c['rate_amount']>10000000) continue;
            if (!preg_match('/^[A-Z]{2}$/D',$c['country_iso2']) ||
                !preg_match('/^[A-Z]{3}$/D',$c['currency']) ||
                !preg_match('/^20\\d\\d-(0[1-9]|1[0-2])$/D',$c['evidence_month'])) continue;
            $rates[]=array(
              'id'=>$entry['public_id'],
              'vendor'=>$c['vendor'], 'project'=>$c['project'],
              'role_pool'=>$c['role_pool'], 'country_iso2'=>$c['country_iso2'],
              'locale'=>$c['locale'], 'contract_type'=>$c['contract_type'],
              'currency'=>$c['currency'], 'rate_amount'=>(float)$c['rate_amount'],
              'pay_unit'=>$c['pay_unit'], 'rate_type'=>$c['rate_type'],
              'evidence_month'=>$c['evidence_month'], 'source_label'=>$source,
              'default_preset'=>$default
            );
        }
        return array(
          'schema_version'=>'1.0', 'published_version'=>$version,
          'generated_at'=>$generated_at, 'rates'=>$rates
        );
    }
}
