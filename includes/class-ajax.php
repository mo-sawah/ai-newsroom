<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIN_Ajax {
    public static function init() {
        add_action( 'wp_ajax_ain_run_campaign', array( __CLASS__, 'run_campaign' ) );
        add_action( 'wp_ajax_ain_pause_campaign', array( __CLASS__, 'pause_campaign' ) );
        add_action( 'wp_ajax_ain_activate_campaign', array( __CLASS__, 'activate_campaign' ) );
        add_action( 'wp_ajax_ain_duplicate_campaign', array( __CLASS__, 'duplicate_campaign' ) );
        add_action( 'wp_ajax_ain_delete_campaign', array( __CLASS__, 'delete_campaign' ) );
        add_action( 'wp_ajax_ain_write_item', array( __CLASS__, 'write_item' ) );
        add_action( 'wp_ajax_ain_delete_item', array( __CLASS__, 'delete_item' ) );
        add_action( 'wp_ajax_ain_get_action_status', array( __CLASS__, 'get_action_status' ) );
        add_action( 'wp_ajax_ain_unlock_stale_items', array( __CLASS__, 'unlock_stale_items' ) );
    }

    private static function guard() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );
        check_ajax_referer( 'ain_ajax', 'nonce' );
    }

    public static function run_campaign() {
        self::guard();
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Missing campaign ID.' );
        set_transient( 'ain_async_campaign_status_' . $id, array( 'state' => 'queued', 'message' => 'Campaign run queued…' ), HOUR_IN_SECONDS );
        wp_schedule_single_event( time() + 1, 'ain_async_run_campaign', array( $id ) );
        if ( function_exists( 'ain_spawn_cron_async' ) ) ain_spawn_cron_async();
        wp_send_json_success( array( 'background' => true, 'type' => 'campaign', 'id' => $id, 'message' => 'Campaign run started in background.' ) );
    }

    public static function pause_campaign() {
        self::guard();
        $id = (int) ( $_POST['id'] ?? 0 );
        AIN_DB::update_campaign( $id, array( 'status' => 'paused' ) );
        wp_send_json_success( 'Campaign paused.' );
    }

    public static function activate_campaign() {
        self::guard();
        $id = (int) ( $_POST['id'] ?? 0 );
        $campaign = AIN_Campaigns::get( $id );
        $next = $campaign ? AIN_Campaigns::next_run_time( $campaign->schedule_config['interval_minutes'] ?? 60 ) : current_time( 'mysql' );
        AIN_DB::update_campaign( $id, array( 'status' => 'active', 'next_run_at' => $next ) );
        wp_send_json_success( 'Campaign activated.' );
    }

    public static function duplicate_campaign() {
        self::guard();
        $id = (int) ( $_POST['id'] ?? 0 );
        $result = AIN_Campaigns::duplicate( $id );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
        wp_send_json_success( 'Campaign duplicated.' );
    }

    public static function delete_campaign() {
        self::guard();
        $id = (int) ( $_POST['id'] ?? 0 );
        AIN_DB::delete_campaign( $id );
        wp_send_json_success( 'Campaign deleted.' );
    }

    public static function write_item() {
        self::guard();
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Missing queue item ID.' );
        if ( method_exists( 'AIN_DB', 'reset_stale_writer_items' ) ) {
            AIN_DB::reset_stale_writer_items( 45 );
        }
        $item = AIN_DB::get_item( $id );
        if ( ! $item ) wp_send_json_error( 'Queue item not found.' );
        if ( in_array( $item->status, array( 'writing', 'queued' ), true ) ) {
            wp_send_json_success( array( 'background' => true, 'type' => 'item', 'id' => $id, 'message' => 'Article is already being generated.' ) );
        }
        AIN_DB::update_item( $id, array( 'status' => 'queued', 'error_message' => '' ) );
        set_transient( 'ain_async_item_status_' . $id, array(
            'state'     => 'queued',
            'message'   => 'Article generation queued…',
            'queued_at' => current_time( 'timestamp', true ),
        ), HOUR_IN_SECONDS );

        $event_args = array( $id );
        if ( ! wp_next_scheduled( 'ain_async_write_item', $event_args ) ) {
            wp_schedule_single_event( time() + 1, 'ain_async_write_item', $event_args );
        }

        if ( ! wp_next_scheduled( 'ain_async_write_item', $event_args ) ) {
            $message = 'Could not queue the background writer. Please check WP-Cron/loopback requests on this server.';
            AIN_DB::update_item( $id, array( 'status' => 'failed', 'error_message' => $message ) );
            set_transient( 'ain_async_item_status_' . $id, array( 'state' => 'error', 'message' => $message ), HOUR_IN_SECONDS );
            wp_send_json_error( $message );
        }

        if ( function_exists( 'ain_spawn_cron_async' ) ) ain_spawn_cron_async();
        wp_send_json_success( array( 'background' => true, 'type' => 'item', 'id' => $id, 'message' => 'Article generation started in background.' ) );
    }


    public static function unlock_stale_items() {
        self::guard();
        $count = method_exists( 'AIN_DB', 'reset_stale_writer_items' ) ? AIN_DB::reset_stale_writer_items( 10 ) : 0;
        wp_send_json_success( sprintf( 'Unlocked %d stuck writing job(s).', (int) $count ) );
    }


    public static function get_action_status() {
        self::guard();
        $type = sanitize_key( $_POST['type'] ?? '' );
        $id   = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id || ! in_array( $type, array( 'item', 'campaign' ), true ) ) {
            wp_send_json_error( 'Invalid status request.' );
        }
        if ( 'item' === $type ) {
            $state = get_transient( 'ain_async_item_status_' . $id );
            $item  = AIN_DB::get_item( $id );
            if ( $item && method_exists( 'AIN_DB', 'is_writer_item_stale' ) && AIN_DB::is_writer_item_stale( $item, 45 ) ) {
                AIN_DB::reset_stale_writer_items( 45 );
                $item = AIN_DB::get_item( $id );
                $state = array( 'state' => 'error', 'message' => 'This writing job was stuck and has been unlocked. Click Write again to retry.' );
            }
            $data = is_array( $state ) ? $state : array();
            if ( $item && ! empty( $data['state'] ) && 'queued' === $data['state'] && ! empty( $data['queued_at'] ) ) {
                $queued_for = current_time( 'timestamp', true ) - (int) $data['queued_at'];
                if ( $queued_for > 300 && 'queued' === $item->status ) {
                    $message = 'The background writer did not start within 5 minutes. The job was unlocked. Please click Write again; if it repeats, check WP-Cron/loopback requests.';
                    AIN_DB::update_item( $id, array( 'status' => 'failed', 'error_message' => $message ) );
                    $item = AIN_DB::get_item( $id );
                    $data = array( 'state' => 'error', 'message' => $message );
                    set_transient( 'ain_async_item_status_' . $id, $data, HOUR_IN_SECONDS );
                }
            }
            if ( $item ) {
                $data['queue_status'] = $item->status;
                $data['queue_status_label'] = ain_status_label( $item->status );
                $data['quality_score'] = (int) $item->quality_score;
                $data['post_id'] = (int) $item->post_id;
                $data['edit_url'] = $item->post_id ? get_edit_post_link( $item->post_id, 'raw' ) : '';
                if ( empty( $data['state'] ) ) {
                    $data['state'] = in_array( $item->status, array( 'published', 'drafted', 'needs_review', 'rejected', 'failed' ), true ) ? 'complete' : $item->status;
                }
                if ( empty( $data['message'] ) ) {
                    $data['message'] = ain_status_label( $item->status );
                }
            }
            wp_send_json_success( $data );
        }
        $state = get_transient( 'ain_async_campaign_status_' . $id );
        wp_send_json_success( is_array( $state ) ? $state : array( 'state' => 'unknown', 'message' => 'Status unavailable. Refresh the page to check results.' ) );
    }


    public static function delete_item() {
        self::guard();
        $id = (int) ( $_POST['id'] ?? 0 );
        AIN_DB::delete_item( $id );
        wp_send_json_success( 'Queue item deleted.' );
    }
}
