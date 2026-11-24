<?php
/**
 * Plugin Name: AI Rater Insider — NTA Bridge
 * Description: Staged, private weekly NTA data bridge. Capture is disabled until explicitly enabled and verified.
 * Version: 0.1.2
 * Requires PHP: 7.4
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/includes/class-ari-nta-schema.php';

final class ARI_NTA_Bridge {
    private const TABLE = 'ari_nta_weekly';
    private const EXPECTED_FORM = 1204;
    private const EXPECTED_PAGE = 1200;
    private const LAUNCH_DATE = '2026-12-01';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
        add_action( 'grunion_after_feedback_post_inserted', array( __CLASS__, 'on_feedback' ), 20, 4 );
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
          feedback_id bigint(20) unsigned NOT NULL,
          created_at datetime NOT NULL,
          payload longtext NOT NULL,
          PRIMARY KEY  (feedback_id)
        ) {$charset};" );
        add_role( 'ari_nta_export_reader', 'ARI NTA Export Reader',
            array( 'read' => true, 'ari_nta_export' => true ) );
        $admin = get_role('administrator');
        if ( $admin ) $admin->add_cap('ari_nta_export');
    }

    private static function capture_enabled() {
        return defined('ARI_NTA_CAPTURE_ENABLED') && ARI_NTA_CAPTURE_ENABLED === true;
    }

    private static function source_verified( $feedback_id ) {
        // Fail closed unless Jetpack metadata explicitly establishes source.
        $form_id = get_post_meta( $feedback_id, '_feedback_form_id', true );
        $source_id = get_post_meta( $feedback_id, '_feedback_post_id', true );
        return (string) $form_id === (string) self::EXPECTED_FORM ||
            (string) $source_id === (string) self::EXPECTED_PAGE;
    }

    public static function on_feedback( $feedback_id, $visible_fields, $is_spam, $entry_values ) {
        if ( ! self::capture_enabled() || $is_spam || ! is_numeric($feedback_id) ) return;
        $feedback_id = (int) $feedback_id;
        if ( $feedback_id <= 0 || get_post_type($feedback_id) !== 'feedback' ||
            ! self::source_verified($feedback_id) ) return;
        $post = get_post($feedback_id);
        if ( ! $post || substr($post->post_date,0,10) < self::LAUNCH_DATE ) return;

        // Deliberately accept only field-label keys; no fallback to arbitrary
        // Jetpack post_content, attachments, identity or technical metadata.
        $clean = ARI_NTA_Schema::normalize($entry_values);
        if ( ! $clean ) return;
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::TABLE, array(
            'feedback_id' => $feedback_id,
            'created_at' => $post->post_date,
            'payload' => wp_json_encode($clean)
        ), array('%d','%s','%s') );
        // Duplicate feedback_id fails the primary-key constraint; no overwrite.
    }

    public static function register_routes() {
        register_rest_route('ari-nta/v1','/reports',array(
            'methods'=>'GET',
            'callback'=>array(__CLASS__,'reports'),
            'permission_callback'=>static function(){ return current_user_can('ari_nta_export'); },
            'args'=>array(
                'page'=>array('default'=>1,'sanitize_callback'=>'absint'),
                'per_page'=>array('default'=>100,'sanitize_callback'=>'absint')
            )
        ));
        register_rest_route('ari-nta/v1','/diagnostic',array(
            'methods'=>'GET',
            'callback'=>array(__CLASS__,'diagnostic'),
            'permission_callback'=>static function(){ return current_user_can('manage_options'); },
            'args'=>array('feedback_id'=>array('sanitize_callback'=>'absint','required'=>false))
        ));
    }

    public static function reports( $request ) {
        if ( ! self::capture_enabled() ) {
            return new WP_Error('ari_capture_disabled','NTA capture is not enabled.',
                array('status'=>409));
        }
        global $wpdb;
        $page = max(1,(int) $request['page']);
        $limit = min(200,max(1,(int) $request['per_page']));
        $table = $wpdb->prefix . self::TABLE;
        $offset = ($page-1)*$limit;
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT created_at,payload FROM {$table} ORDER BY feedback_id ASC LIMIT %d OFFSET %d",
            $limit,$offset
        ), ARRAY_A);
        $rows = array();
        foreach ( $records as $record ) {
            $values = json_decode($record['payload'],true);
            $clean = ARI_NTA_Schema::normalize($values);
            if ( ! $clean ) continue;
            $rows[] = array_merge(array('Date'=>$record['created_at']),$clean);
        }
        return rest_ensure_response(array('page'=>$page,'reports'=>$rows,'count'=>count($rows)));
    }

    public static function diagnostic( $request ) {
        $id = isset($request['feedback_id']) ? (int) $request['feedback_id'] : 0;
        $result = array(
            'capture_enabled'=>self::capture_enabled(),
            'expected_form_id'=>self::EXPECTED_FORM,
            'expected_page_id'=>self::EXPECTED_PAGE,
            'hook'=>'grunion_after_feedback_post_inserted',
        );
        if ( $id > 0 && get_post_type($id)==='feedback' ) {
            // Return only non-sensitive key names and provenance booleans.
            // Never return field values, IDs, IP addresses or file URLs.
            $result['source_verified'] = self::source_verified($id);
            $result['meta_key_names'] = array_values(array_filter(
                array_keys( get_post_meta($id) ),
                static function($key) { return strpos($key,'_feedback_')===0; }
            ));
        }
        return rest_ensure_response($result);
    }
    /**
     * Read-only diagnostics inside wp-admin, without browser REST nonce issues.
     * No answer values, file references, cookies, tokens or visitor identifiers.
     */
    public static function register_admin_page() {
        add_management_page(
            'NTA Bridge Diagnostics',
            'NTA Bridge Diagnostics',
            'manage_options',
            'ari-nta-diagnostics',
            array( __CLASS__, 'admin_diagnostics' )
        );
    }

    public static function admin_diagnostics() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.' ) );
        }
        echo '<div class="wrap"><h1>NTA Bridge Diagnostics</h1>';
        echo '<p>Read-only technical checks. No response values or personal metadata are displayed.</p>';
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th scope="row">Version</th><td>0.1.2 (staged)</td></tr>';
        echo '<tr><th scope="row">Capture enabled</th><td>' . ( self::capture_enabled() ? 'Yes' : 'No' ) . '</td></tr>';
        echo '<tr><th scope="row">Expected reusable form</th><td>' . esc_html( (string) self::EXPECTED_FORM ) . '</td></tr>';
        echo '<tr><th scope="row">Expected source page</th><td>' . esc_html( (string) self::EXPECTED_PAGE ) . '</td></tr>';
        echo '</tbody></table>';
        foreach ( array(1208, 1201) as $feedback_id ) {
            echo '<h2>Historical test response #' . esc_html( (string) $feedback_id ) . '</h2>';
            if ( get_post_type( $feedback_id ) !== 'feedback' ) {
                echo '<p>Response not found or no longer accessible.</p>';
                continue;
            }
            $form_value = get_post_meta( $feedback_id, '_feedback_form_id', true );
            $source_value = get_post_meta( $feedback_id, '_feedback_post_id', true );
            // Jetpack on this site writes _feedback_source_post_id. Read it only
            // to classify provenance; never display its raw value or any answers.
            $actual_source = get_post_meta( $feedback_id, '_feedback_source_post_id', true );
            $keys = array_keys( get_post_meta( $feedback_id ) );
            $safe_keys = array_values( array_filter( $keys, static function( $k ) {
                return strpos( $k, '_feedback_' ) === 0;
            } ) );
            echo '<ul>';
            echo '<li>Form metadata key present: ' . ( $form_value !== '' ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Matches expected form ID: ' . ( (string) $form_value === (string) self::EXPECTED_FORM ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Page metadata key present: ' . ( $source_value !== '' ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Matches expected source page: ' . ( (string) $source_value === (string) self::EXPECTED_PAGE ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Actual Jetpack source key present: ' . ( $actual_source !== '' ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Actual source equals NTA page 1200: ' . ( (string) $actual_source === (string) self::EXPECTED_PAGE ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Actual source equals reusable form 1204: ' . ( (string) $actual_source === (string) self::EXPECTED_FORM ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Current source verification rule matches: ' . ( self::source_verified( $feedback_id ) ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Feedback metadata key names: ' . ( $safe_keys ? esc_html( implode( ', ', $safe_keys ) ) : 'None found' ) . '</li>';
            echo '</ul>';
        }
        echo '<p>Do not enable capture until source attribution and Jetpack field-label mapping are verified.</p></div>';
    }

}
register_activation_hook(__FILE__,array('ARI_NTA_Bridge','activate'));
ARI_NTA_Bridge::init();
