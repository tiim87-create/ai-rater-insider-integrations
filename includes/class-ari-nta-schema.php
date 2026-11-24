<?php
/**
 * Pure validation of 11 Jetpack dropdown values.
 * Does not depend on WordPress APIs; safe for offline tests.
 */
if ( ! class_exists( 'ARI_NTA_Schema', false ) ) {
    final class ARI_NTA_Schema {
        public const FIELDS = array(
            'Project', 'Vendor', 'Country (ISO code)', 'Project language / locale',
            'Week beginning Monday', 'Days attempted to work', 'Days with NTA',
            'Completed paid-equivalent hours (0.5-hour steps)',
            'How are hours measured?', 'Source of weekly figures', 'Account access status'
        );

        /**
         * Jetpack may index field keys as "0_Project", "1_Vendor", etc.
         * Normalize only allowlisted fields. Ignore unknown keys and never
         * infer field labels from values. Reject duplicate label collisions.
         */
        public static function from_jetpack( $input ) {
            if ( ! is_array( $input ) ) return null;
            $mapped = array();
            foreach ( $input as $key => $value ) {
                if ( ! is_string($key) ) continue;
                $label = $key;
                if ( ! in_array( $label, self::FIELDS, true ) ) {
                    if ( ! preg_match( '/^[0-9]+_(.+)$/D', $key, $matches ) ) continue;
                    $label = $matches[1];
                }
                if ( ! in_array( $label, self::FIELDS, true ) ) continue;
                if ( array_key_exists( $label, $mapped ) ) return null;
                if ( ! is_string( $value ) && ! is_numeric( $value ) ) return null;
                $mapped[ $label ] = $value;
            }
            return self::normalize( $mapped );
        }

        /**
         * Diagnostic shape only: field labels matched, never any response values.
         */
        public static function field_shape( $input ) {
            $present = array_fill_keys( self::FIELDS, false );
            $duplicates = false;
            if ( is_array($input) ) {
                foreach ( $input as $key => $_unused ) {
                    if ( ! is_string($key) ) continue;
                    $label = $key;
                    if ( ! in_array($label,self::FIELDS,true) ) {
                        if ( ! preg_match('/^[0-9]+_(.+)$/D',$key,$m) ) continue;
                        $label = $m[1];
                    }
                    if ( ! array_key_exists($label,$present) ) continue;
                    if ( $present[$label] ) $duplicates = true;
                    $present[$label] = true;
                }
            }
            return array('present'=>$present,
                'count'=>count(array_filter($present)),
                'duplicates'=>$duplicates);
        }

        /**
         * Admin-only validation diagnostics without exposing field values.
         * Only the 11 explicitly allowlisted labels are ever represented.
         */
        public static function validate_shape_and_values( $input ) {
            $valid = array_fill_keys( self::FIELDS, false );
            $flags = array(
                'duplicate_labels' => false,
                'nta_exceeds_attempted' => false,
                'paid_hours_without_attempts' => false,
            );
            if ( ! is_array($input) ) return array('valid'=>$valid, 'flags'=>$flags);
            $mapped = array();
            foreach ($input as $key=>$value) {
                if (!is_string($key)) continue;
                $label = $key;
                if (!in_array($label,self::FIELDS,true)) {
                    if (!preg_match('/^[0-9]+_(.+)$/D',$key,$m)) continue;
                    $label = $m[1];
                }
                if (!in_array($label,self::FIELDS,true)) continue;
                if (array_key_exists($label,$mapped)) {
                    $flags['duplicate_labels'] = true;
                    continue;
                }
                $mapped[$label]=$value;
            }
            if ($flags['duplicate_labels']) return array('valid'=>$valid,'flags'=>$flags);
            foreach (self::FIELDS as $label) {
                if (!array_key_exists($label,$mapped)) continue;
                $value = $mapped[$label];
                if ((!is_string($value) && !is_numeric($value))) continue;
                $v = trim((string)$value);
                if ($v==='' || strlen($v)>100) continue;
                switch ($label) {
                    case 'Project':
                        $valid[$label]=in_array($v,array('search_quality','ads_quality'),true);break;
                    case 'Vendor':
                        $valid[$label]=in_array($v,array('telus','welodata','rws','other'),true);break;
                    case 'Country (ISO code)':
                        $valid[$label]=(bool)preg_match('/^[A-Z]{2}$/D',$v);break;
                    case 'Project language / locale':
                        $valid[$label]=(bool)preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$v);break;
                    case 'Week beginning Monday':
                        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
                        $valid[$label]=(bool)(preg_match('/^20\\d\\d-\\d\\d-\\d\\d$/D',$v) &&
                          $date && $date->format('Y-m-d')===$v &&
                          (int)$date->format('N')===1 && $v>='2026-12-07');break;
                    case 'Days attempted to work':
                    case 'Days with NTA':
                        $valid[$label]=(bool)preg_match('/^[0-7]$/D',$v);break;
                    case 'Completed paid-equivalent hours (0.5-hour steps)':
                        $valid[$label]=(bool)preg_match('/^(?:\\d{1,2}(?:\\.[05])?|100(?:\\.0)?)$/D',$v) &&
                          (float)$v<=100;break;
                    case 'How are hours measured?':
                        $valid[$label]=in_array($v,array('payroll','vendor_report','task_aet_estimate','self_estimate'),true);break;
                    case 'Source of weekly figures':
                        $valid[$label]=in_array($v,array('platform_record','time_log','memory_estimate'),true);break;
                    case 'Account access status':
                        $valid[$label]=in_array($v,array('active','quality_hold'),true);break;
                }
            }
            if ($valid['Days attempted to work'] && $valid['Days with NTA']) {
                $flags['nta_exceeds_attempted'] =
                    (int)$mapped['Days with NTA']>(int)$mapped['Days attempted to work'];
            }
            if ($valid['Days attempted to work'] &&
                $valid['Completed paid-equivalent hours (0.5-hour steps)']) {
                $flags['paid_hours_without_attempts'] =
                    (int)$mapped['Days attempted to work']===0 &&
                    (float)$mapped['Completed paid-equivalent hours (0.5-hour steps)']>0;
            }
            return array('valid'=>$valid,'flags'=>$flags);
        }

        public static function normalize( $input ) {
            if ( ! is_array( $input ) ) {
                return null;
            }
            $v = array();
            foreach ( self::FIELDS as $label ) {
                if ( ! array_key_exists( $label, $input ) || ! is_string( $input[ $label ] ) && ! is_numeric( $input[ $label ] ) ) {
                    return null;
                }
                $value = trim( (string) $input[ $label ] );
                if ( $value === '' || strlen( $value ) > 100 ) {
                    return null;
                }
                $v[ $label ] = $value;
            }
            if ( ! in_array( $v['Project'], array('search_quality','ads_quality'), true ) ) return null;
            if ( ! in_array( $v['Vendor'], array('telus','welodata','rws','other'), true ) ) return null;
            if ( ! preg_match('/^[A-Z]{2}$/D', $v['Country (ISO code)']) ) return null;
            if ( ! preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $v['Project language / locale']) ) return null;
            $week = $v['Week beginning Monday'];
            if ( ! preg_match('/^20\d\d-\d\d-\d\d$/D', $week) ) return null;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $week);
            if ( ! $date || $date->format('Y-m-d') !== $week ||
                (int) $date->format('N') !== 1 || $week < '2026-12-07' ) return null;
            foreach ( array('Days attempted to work','Days with NTA') as $key ) {
                if ( ! preg_match('/^[0-7]$/D', $v[$key]) ) return null;
            }
            $attempted = (int) $v['Days attempted to work'];
            $nta = (int) $v['Days with NTA'];
            if ( $nta > $attempted ) return null;
            $hours_text = $v['Completed paid-equivalent hours (0.5-hour steps)'];
            if ( ! preg_match('/^(?:\d{1,2}(?:\.[05])?|100(?:\.0)?)$/D', $hours_text) ) return null;
            $hours = (float) $hours_text;
            if ( $hours > 100 || ( $attempted === 0 && $hours > 0 ) ) return null;
            if ( ! in_array( $v['How are hours measured?'], array('payroll','vendor_report','task_aet_estimate','self_estimate'), true ) ) return null;
            if ( ! in_array( $v['Source of weekly figures'], array('platform_record','time_log','memory_estimate'), true ) ) return null;
            if ( ! in_array( $v['Account access status'], array('active','quality_hold'), true ) ) return null;
            return $v;
        }
    }
}
