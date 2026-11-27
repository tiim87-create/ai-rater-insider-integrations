<?php
/**
 * Pure, WordPress-independent validation and publication projection.
 * No form hook, DB access, public route or automatic publishing is defined here.
 */
final class ARI_Pay_Rate_Review {
    public const FIELDS = array(
        'Project', 'Vendor', 'Role or Pool', 'Country', 'Project language',
        'Project target country optional', 'Contract Arrangement', 'Currency', 'Rate Amount', 'Pay Unit',
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
    private const COUNTRY_CODES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';
    private const LANGUAGE_CODES = 'ar bg bn ca cs da de el en es et fa fi fil fr gu he hi hr hu id it ja ka kk km kn ko lt lv ml mr ms my ne nl no pa pl pt ro ru si sk sl sr sv sw ta te th tl tr uk ur uz vi zh';
    private const CURRENCY_CODES = 'AED AFN ALL AMD ANG AOA ARS AUD AWG AZN BAM BBD BDT BGN BHD BIF BMD BND BOB BRL BSD BTN BWP BYN BZD CAD CDF CHF CLP CNY COP CRC CUC CUP CVE CZK DJF DKK DOP DZD EGP ERN ETB EUR FJD FKP GBP GEL GHS GIP GMD GNF GTQ GYD HKD HNL HRK HTG HUF IDR ILS INR IQD IRR ISK JMD JOD JPY KES KGS KHR KMF KPW KRW KWD KYD KZT LAK LBP LKR LRD LSL LYD MAD MDL MGA MKD MMK MNT MOP MRU MUR MVR MWK MXN MYR MZN NAD NGN NIO NOK NPR NZD OMR PAB PEN PGK PHP PKR PLN PYG QAR RON RSD RUB RWF SAR SBD SCR SDG SEK SGD SHP SLL SOS SRD SSP STN SVC SYP SZL THB TJS TMT TND TOP TRY TTD TWD TZS UAH UGX USD UYU UZS VES VND VUV WST XAF XCD XDR XOF XPF XSU YER ZAR ZMW ZWL';
    private const CONSENT = 'I agree to private review of my submission and understand that no identifiable information will be published.';

    /**
     * Admin diagnostics: return shape booleans only, never respondent values.
     * Supports Jetpack's numbered labels (for example 0_Project).
     */
    public static function field_shape( $input ) {
        $seen=array_fill_keys(self::FIELDS,false);
        $duplicate=false;
        if (is_array($input)) {
            foreach ($input as $key=>$_discard) {
                if (!is_string($key)) continue;
                $label=$key;
                if (!array_key_exists($label,$seen)) {
                    if (!preg_match('/^[0-9]+_(.+)$/D',$key,$matches)) continue;
                    $label=$matches[1];
                }
                if (!array_key_exists($label,$seen)) continue;
                if ($seen[$label]) $duplicate=true;
                $seen[$label]=true;
            }
        }
        return array(
            'expected_field_count'=>count(self::FIELDS),
            'present_field_count'=>count(array_filter($seen)),
            // Schema labels ONLY. No raw values, unknown keys or attachment metadata.
            'missing_expected_labels'=>array_keys(array_filter($seen,static function($present) {return !$present;})),
            'duplicate_labels'=>$duplicate,
        );
    }

    private static function read_selected_code($display,$width,$allowed_codes) {
        if (!is_string($display)) return null;
        $pattern=$width===3?'/^.+ \\(([A-Z]{3})\\)$/D':'/^.+ \\(([A-Za-z]{2,3})\\)$/D';
        if (!preg_match($pattern,$display,$m)) return null;
        $code=$m[1];
        if (strlen($code)!==$width && !($allowed_codes===self::LANGUAGE_CODES && strlen($code)===3)) return null;
        // Codes may be selected only from the strict server-side allowlist.
        return in_array($code,explode(' ',$allowed_codes),true)?$code:null;
    }

    /**
     * Read-only validation reasons as expected FIELD LABELS, never input values.
     * Allows diagnosis of native Jetpack block shapes without dumping responses.
     */
    public static function invalid_field_labels($input) {
        if (!is_array($input)) return self::FIELDS;
        $data=array();
        $invalid=array();
        foreach($input as $key=>$value) {
            if (!is_string($key)) continue;
            $label=$key;
            if (!in_array($label,self::FIELDS,true)) {
                if (!preg_match('/^[0-9]+_(.+)$/D',$key,$m)) continue;
                $label=$m[1];
            }
            if (!in_array($label,self::FIELDS,true)) continue;
            if (array_key_exists($label,$data)) {
                $invalid[$label]=true;
                continue;
            }
            if ($label==='Permission to process anonymous rate facts' && is_array($value)) {
                $value=count($value)===1 && isset($value[0]) ? $value[0] : null;
            }
            $data[$label]=$value;
        }
        foreach(self::FIELDS as $label) {
            $optional=in_array($label,array(
                'Project target country optional','Public Source URL optional','Evidence Context optional'
            ),true);
            if (!array_key_exists($label,$data)) {
                if (!$optional) $invalid[$label]=true;
                continue;
            }
            $v=$data[$label];
            if ((!is_string($v) && !is_numeric($v)) || strlen((string)$v)>500) {
                $invalid[$label]=true; continue;
            }
            $v=trim((string)$v);
            if ($v==='' && !$optional) {$invalid[$label]=true;continue;}
            $valid=true;
            switch($label) {
                case 'Project': $valid=in_array($v,self::PROJECTS,true);break;
                case 'Vendor': $valid=in_array($v,self::VENDORS,true);break;
                case 'Role or Pool': $valid=in_array($v,self::ROLES,true);break;
                case 'Country': $valid=self::read_selected_code($v,2,self::COUNTRY_CODES)!==null;break;
                case 'Project language': $valid=self::read_selected_code($v,2,self::LANGUAGE_CODES)!==null;break;
                case 'Project target country optional':
                    $valid=$v==='' || $v==='Same as country of work' ||
                           self::read_selected_code($v,2,self::COUNTRY_CODES)!==null;break;
                case 'Contract Arrangement': $valid=in_array($v,self::CONTRACTS,true);break;
                case 'Currency': $valid=self::read_selected_code($v,3,self::CURRENCY_CODES)!==null;break;
                case 'Rate Amount':
                    $valid=(bool)preg_match('/^(?:0|[1-9]\\d{0,7})(?:\\.\\d{1,4})?$/D',$v)
                      && (float)$v>0 && (float)$v<=10000000;break;
                case 'Pay Unit': $valid=in_array($v,self::UNITS,true);break;
                case 'Rate Type': $valid=in_array($v,self::RATE_TYPES,true);break;
                case 'Evidence Month YYYY-MM':
                    $valid=(bool)preg_match('/^20\\d\\d-(0[1-9]|1[0-2])$/D',$v);break;
                case 'Evidence Type': $valid=in_array($v,self::EVIDENCE,true);break;
                case 'Public Source URL optional':
                    $valid=$v==='' || (strlen($v)<=300 &&
                      (bool)preg_match('~^https://[a-z0-9.-]+(?::443)?(?:/[^\\s]*)?$~iD',$v));break;
                case 'Permission to process anonymous rate facts':
                    $valid=$v===self::CONSENT;break;
            }
            if (!$valid) $invalid[$label]=true;
        }
        return array_keys($invalid);
    }

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
            if (array_key_exists($label,$fields)) return null; // Fail on duplicate label variants.
            if ($label==='Permission to process anonymous rate facts' && is_array($value)) {
                // Jetpack checkbox fields may be represented as a one-element array.
                if (count($value)!==1 || !isset($value[0]) || !is_string($value[0])) return null;
                $value=$value[0];
            }
            if (!is_string($value) && !is_numeric($value)) return null;
            $fields[$label] = trim((string)$value);
        }
        // Unchecked optional Jetpack fields can be omitted altogether.
        foreach (array('Public Source URL optional','Evidence Context optional','Project target country optional') as $optional) {
            if (!array_key_exists($optional,$fields)) $fields[$optional]='';
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

        $country=self::read_selected_code($fields['Country'],2,self::COUNTRY_CODES);
        $language=self::read_selected_code($fields['Project language'],2,self::LANGUAGE_CODES);
        $market=$fields['Project target country optional'];
        $target_country=$market==='' || $market==='Same as country of work'
            ? $country : self::read_selected_code($market,2,self::COUNTRY_CODES);
        $currency=self::read_selected_code($fields['Currency'],3,self::CURRENCY_CODES);
        if (!$country || !$language || !$target_country || !$currency) return null;
        $locale=$language.'-'.$target_country;
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
          'role_pool'=>$fields['Role or Pool'], 'country_iso2'=>$country,
          'locale'=>$locale, 'contract_type'=>$fields['Contract Arrangement'],
          'currency'=>$currency, 'rate_amount'=>(float)$rate,
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
            // Revalidate every public field even when a stored claim says "approved".
            if (!in_array($c['project'],self::PROJECTS,true) ||
                !in_array($c['vendor'],self::VENDORS,true) ||
                !in_array($c['role_pool'],self::ROLES,true) ||
                !in_array($c['contract_type'],self::CONTRACTS,true) ||
                !in_array($c['pay_unit'],self::UNITS,true) ||
                !in_array($c['rate_type'],self::RATE_TYPES,true)) continue;
            if (!is_numeric($c['rate_amount']) || !is_finite((float)$c['rate_amount']) ||
                (float)$c['rate_amount']<=0 || (float)$c['rate_amount']>10000000) continue;
            if (!preg_match('/^[A-Z]{2}$/D',$c['country_iso2']) ||
                !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$c['locale']) ||
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
