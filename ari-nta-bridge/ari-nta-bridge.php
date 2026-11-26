<?php
/**
 * Plugin Name: AI Rater Insider — NTA Bridge
 * Description: Staged, private weekly NTA data bridge. Capture is disabled until explicitly enabled and verified.
 * Version: 0.2.2
 * Requires PHP: 7.4
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/includes/class-ari-nta-schema.php';

final class ARI_NTA_Bridge {
    private const TABLE = 'ari_nta_weekly';
    private const EXPECTED_FORM = 1204;
    private const EXPECTED_PAGE = 1175;
    private const LAUNCH_DATE = '2026-12-01';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
        add_filter( 'rest_authentication_errors', array( __CLASS__, 'observe_auth_transport' ), 99, 1 );
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
    public static function is_capture_enabled() {
        return self::capture_enabled();
    }

    private static function source_verified( $feedback_id ) {
        // Fail closed unless Jetpack metadata explicitly establishes source.
        // Dashboard page 1175 embeds reusable form 1204. A 2026-11-26
        // Jetpack response (#1252) shows this dashboard as its source in UI.
        // Confirm exact _feedback_source_post_id via admin diagnostic before live deploy.
        // Fail closed for legacy page 1200 and unrelated source pages.
        $source_id = get_post_meta( $feedback_id, '_feedback_source_post_id', true );
        return (string) $source_id === (string) self::EXPECTED_PAGE;
    }

    /**
     * Jetpack may provide hook arguments in a different internal shape than
     * the persisted dropdown values. Defer processing until shutdown so the
     * saved _feedback_extra_fields meta has finished writing.
     *
     * Never copy raw or unknown fields into our separate table or diagnostics.
     */
    public static function on_feedback( $feedback_id, $visible_fields, $is_spam, $entry_values ) {
        if ( $is_spam || ! is_numeric($feedback_id) ) return;
        $feedback_id = (int) $feedback_id;
        if ( $feedback_id <= 0 ) return;

        add_action( 'shutdown', static function() use ( $feedback_id ) {
            ARI_NTA_Bridge::process_saved_feedback( $feedback_id );
        }, 20 );
    }

    public static function process_saved_feedback( $feedback_id ) {
        if ( get_post_type($feedback_id) !== 'feedback' ||
            ! self::source_verified($feedback_id) ) return;

        // The source key, not the fourth hook argument, was verified on
        // both actual NTA feedback records #1201 and #1208.
        $stored = get_post_meta( $feedback_id, '_feedback_extra_fields', true );
        $shape = ARI_NTA_Schema::field_shape( $stored );
        $clean = ARI_NTA_Schema::from_jetpack( $stored );
        $validation = ARI_NTA_Schema::validate_shape_and_values( $stored );

        // Diagnostic contains ONLY permitted field names, boolean presence,
        // and counts; never response values, IDs, IPs or submission metadata.
        update_option( 'ari_nta_last_hook_shape', array(
            'hook_seen' => true,
            'entry_values_is_array' => is_array($stored),
            'expected_fields_present' => $shape['count'],
            'expected_fields_total' => count( ARI_NTA_Schema::FIELDS ),
            'present' => $shape['present'],
            'duplicate_labels' => $shape['duplicates'],
            'passes_normalizer' => $clean !== null,
            'field_value_valid' => $validation['valid'],
            'cross_field_flags' => $validation['flags'],
        ), false );

        // Data capture remains disabled until separately enabled on server.
        if ( ! self::capture_enabled() || $clean === null ) return;
        $post = get_post( $feedback_id );
        if ( ! $post || substr($post->post_date,0,10) < self::LAUNCH_DATE ) return;
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::TABLE, array(
            'feedback_id' => $feedback_id,
            'created_at' => $post->post_date,
            'payload' => wp_json_encode($clean),
        ), array('%d','%s','%s') );
        // The primary key prevents duplicate imports.
    }

    /**
     * Temporary auth-transport probe for the dedicated NTA route only.
     * Keep the original authentication result unchanged. Do not record
     * usernames, passwords, tokens, authorization values, IPs or bodies.
     */
    public static function observe_auth_transport( $result ) {
        $path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(
            wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH
        ) : '';
        if ( ! is_string($path) || ! in_array($path, array(
            '/wp-json/ari-nta/v1/auth-check',
            '/index.php/wp-json/ari-nta/v1/auth-check',
        ), true) ) {
            return $result;
        }
        $has_basic = isset($_SERVER['HTTP_AUTHORIZATION']) &&
            stripos((string) $_SERVER['HTTP_AUTHORIZATION'], 'Basic ') === 0;
        $has_redirect_basic = isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) &&
            stripos((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], 'Basic ') === 0;
        $snapshot = array(
            'probe_seen' => true,
            'http_basic_arrived' => (bool)($has_basic || $has_redirect_basic),
            'php_auth_user_present' => isset($_SERVER['PHP_AUTH_USER']) &&
                is_string($_SERVER['PHP_AUTH_USER']) &&
                $_SERVER['PHP_AUTH_USER'] !== '',
            'php_auth_pw_present' => isset($_SERVER['PHP_AUTH_PW']) &&
                is_string($_SERVER['PHP_AUTH_PW']) &&
                $_SERVER['PHP_AUTH_PW'] !== '',
            'auth_result_kind' => is_wp_error($result) ? 'WP_Error' :
                ( $result === null ? 'null' : ( $result === true ? 'true' : 'other' ) ),
            'authenticated_at_probe' => is_user_logged_in(),
        );
        update_option( 'ari_nta_auth_transport_probe', $snapshot, false );
        return $result;
    }

    public static function register_routes() {
        // Temporary transport-only diagnostic. Client sends literal
        // "Authorization: ARIProbe v1" (NEVER an actual password).
        // Reports only whether that exact harmless header survived the proxy.
        // No authentication, user, secret or request metadata is returned.
        register_rest_route('ari-nta/v1','/transport-check',array(
            'methods'=>'GET',
            'permission_callback'=>'__return_true',
            'callback'=>static function() {
                $one = isset($_SERVER['HTTP_AUTHORIZATION']) &&
                    $_SERVER['HTTP_AUTHORIZATION'] === 'ARIProbe v1';
                $two = isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) &&
                    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] === 'ARIProbe v1';
                return rest_ensure_response(array(
                    'probe_header_arrived'=>($one || $two),
                    'via_http_authorization'=>$one,
                    'via_redirect_http_authorization'=>$two,
                ));
            },
        ));
        // Diagnostic endpoint returns no identity, token or private data.
        // It distinguishes core authentication from the NTA permission check.
        register_rest_route('ari-nta/v1','/auth-check',array(
            'methods'=>'GET',
            'permission_callback'=>static function() {
                return is_user_logged_in();
            },
            'callback'=>static function() {
                return rest_ensure_response(array(
                    'authenticated'=>true,
                    'can_export_nta'=>current_user_can('ari_nta_export'),
                    'capture_enabled'=>ARI_NTA_Bridge::is_capture_enabled(),
                ));
            },
        ));
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
        // Inspect WordPress core application-password availability for the
        // dedicated export user. Only feature booleans are shown, never secrets.
        $export_user = get_user_by('login','nta-exporter');
        echo '<h2>Last GitHub Basic Auth transport check</h2>';
        $auth_probe = get_option('ari_nta_auth_transport_probe', false);
        if ( ! is_array($auth_probe) || empty($auth_probe['probe_seen']) ) {
            echo '<p>No eligible NTA auth-check request has been observed since v0.2.2.</p>';
        } else {
            $boolean_keys = array(
                'http_basic_arrived' => 'Basic Authorization header reached PHP',
                'php_auth_user_present' => 'PHP_AUTH_USER populated',
                'php_auth_pw_present' => 'PHP_AUTH_PW populated',
                'authenticated_at_probe' => 'WordPress user authenticated at probe',
            );
            foreach ($boolean_keys as $key => $label) {
                echo '<p>' . esc_html($label) . ': ' .
                    (!empty($auth_probe[$key]) ? 'Yes' : 'No') . '</p>';
            }
            $kind = isset($auth_probe['auth_result_kind']) &&
                in_array($auth_probe['auth_result_kind'],array('WP_Error','null','true','other'),true)
                ? $auth_probe['auth_result_kind'] : 'unavailable';
            echo '<p>REST authentication result type: ' . esc_html($kind) . '</p>';
        }

        echo '<h2>Application Password eligibility</h2>';
        echo '<p>Export user exists: ' . ( $export_user ? 'Yes' : 'No' ) . '</p>';
        echo '<p>Core application passwords available globally: ' .
            ( function_exists('wp_is_application_passwords_available') &&
              wp_is_application_passwords_available() ? 'Yes' : 'No' ) . '</p>';
        echo '<p>Core application passwords available for nta-exporter: ' .
            ( $export_user && function_exists('wp_is_application_passwords_available_for_user') &&
              wp_is_application_passwords_available_for_user($export_user) ? 'Yes' : 'No' ) . '</p>';
        // Existence only. Do not expose password hashes, UUIDs or timestamps.
        if ( $export_user && class_exists('WP_Application_Passwords') ) {
            $stored_passwords = WP_Application_Passwords::get_user_application_passwords($export_user->ID);
            echo '<p>Application password records for exporter: ' .
                ( is_array($stored_passwords) ? count($stored_passwords) : 'Unknown' ) . '</p>';
        }

        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th scope="row">Version</th><td>0.2.2 (staged)</td></tr>';
        echo '<tr><th scope="row">Capture enabled</th><td>' . ( self::capture_enabled() ? 'Yes' : 'No' ) . '</td></tr>';
        echo '<tr><th scope="row">Expected reusable form</th><td>' . esc_html( (string) self::EXPECTED_FORM ) . '</td></tr>';
        echo '<tr><th scope="row">Expected source page</th><td>' . esc_html( (string) self::EXPECTED_PAGE ) . '</td></tr>';
        echo '</tbody></table>';
        foreach ( array(1213, 1212, 1208, 1201) as $feedback_id ) {
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
            echo '<li>Actual source equals expected public dashboard: ' . ( (string) $actual_source === (string) self::EXPECTED_PAGE ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Actual source equals reusable form 1204: ' . ( (string) $actual_source === (string) self::EXPECTED_FORM ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Current source verification rule matches: ' . ( self::source_verified( $feedback_id ) ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Feedback metadata key names: ' . ( $safe_keys ? esc_html( implode( ', ', $safe_keys ) ) : 'None found' ) . '</li>';
            // Read saved Jetpack extra fields only to classify key shapes.
            // Do NOT render values or unrecognized keys to the browser.
            $stored = get_post_meta( $feedback_id, '_feedback_extra_fields', true );
            $stored_shape = ARI_NTA_Schema::field_shape( $stored );
            echo '<li>Stored extra-field labels recognized: ' . esc_html( (string) $stored_shape['count'] ) . ' / 11</li>';
            echo '<li>Stored extra-field labels duplicated: ' . ( $stored_shape['duplicates'] ? 'Yes' : 'No' ) . '</li>';
            echo '<li>Stored extra fields pass schema: ' . ( ARI_NTA_Schema::from_jetpack($stored) !== null ? 'Yes' : 'No' ) . '</li>';
            $stored_validation = ARI_NTA_Schema::validate_shape_and_values($stored);
            foreach( ARI_NTA_Schema::FIELDS as $diagnostic_label ) {
                echo '<li>Saved field validity — ' . esc_html($diagnostic_label) . ': ' .
                    ( ! empty($stored_validation['valid'][$diagnostic_label]) ? 'Valid' : 'Invalid' ) . '</li>';
            }
            foreach ( array('duplicate_labels','nta_exceeds_attempted','paid_hours_without_attempts') as $rule ) {
                echo '<li>Saved cross-field check — ' . esc_html($rule) . ': ' .
                    ( ! empty($stored_validation['flags'][$rule]) ? 'FAILED' : 'Not failed' ) . '</li>';
            }
            foreach ( ARI_NTA_Schema::FIELDS as $safe_label ) {
                echo '<li>Stored ' . esc_html($safe_label) . ': ' .
                    ( ! empty($stored_shape['present'][$safe_label]) ? 'Present' : 'Missing' ) . '</li>';
            }
            echo '</ul>';
        }
        echo '<h2>Most recent form hook: field-label shape only</h2>';
        $shape = get_option( 'ari_nta_last_hook_shape', false );
        if ( ! is_array($shape) || empty($shape['hook_seen']) ) {
            echo '<p>No matching Jetpack hook has been observed since this probe was installed.</p>';
        } else {
            echo '<p>Hook observed: Yes</p>';
            echo '<p>Saved extra fields is an array: ' . ( ! empty($shape['entry_values_is_array']) ? 'Yes' : 'No' ) . '</p>';
            echo '<p>Recognized field labels: ' . esc_html( (string) ($shape['expected_fields_present'] ?? 0) ) . ' / 11</p>';
            echo '<p>Entire payload passes schema: ' . ( ! empty($shape['passes_normalizer']) ? 'Yes' : 'No' ) . '</p>';
            // Historical diagnostic options from v0.1.6 lack per-field validity.
            // Missing diagnostics must never be misrepresented as failures.
            $has_validity = isset($shape['field_value_valid']) &&
                is_array($shape['field_value_valid']) &&
                count($shape['field_value_valid']) === count(ARI_NTA_Schema::FIELDS);
            $has_flags = isset($shape['cross_field_flags']) &&
                is_array($shape['cross_field_flags']);
            if ( ! $has_validity || ! $has_flags ) {
                echo '<p><em>Per-field validity was not recorded by the earlier diagnostic version. ';
                echo 'This historical hook snapshot cannot determine why validation failed. ';
                echo 'Use the saved-response diagnostics above for authoritative field results.</em></p>';
            } else {
                echo '<h3>Per-field validity (no values)</h3>';
                foreach ( ARI_NTA_Schema::FIELDS as $label ) {
                    echo '<p>' . esc_html($label) . ': ' .
                        ( ! empty($shape['field_value_valid'][$label]) ? 'Valid' : 'Invalid' ) . '</p>';
                }
                echo '<h3>Cross-field rules</h3>';
                foreach ( array('duplicate_labels','nta_exceeds_attempted','paid_hours_without_attempts') as $rule ) {
                    echo '<p>' . esc_html($rule) . ': ' .
                        ( ! empty($shape['cross_field_flags'][$rule]) ? 'FAILED' : 'Not failed' ) . '</p>';
                }
            }
            echo '<ul>';
            $present = isset($shape['present']) && is_array($shape['present']) ? $shape['present'] : array();
            foreach ( ARI_NTA_Schema::FIELDS as $label ) {
                echo '<li>' . esc_html($label) . ': ' .
                    ( ! empty($present[$label]) ? 'Present' : 'Missing' ) . '</li>';
            }
            echo '</ul>';
        }
        echo '<p>Do not enable capture until source attribution and Jetpack field-label mapping are verified.</p></div>';
    }

}
register_activation_hook(__FILE__,array('ARI_NTA_Bridge','activate'));
ARI_NTA_Bridge::init();
