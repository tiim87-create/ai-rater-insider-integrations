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
