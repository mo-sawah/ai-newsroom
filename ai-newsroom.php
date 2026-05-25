<?php
/**
 * Plugin Name: AI Newsroom
 * Description: Smart story-first AI newsroom automation for WordPress: campaign sources, story clustering, OpenRouter web research, editorial writing, media, SEO, and social copy.
 * Version: 2.0.11
 * Author: Mohamed Sawah
 * Text Domain: ai-newsroom
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AIN_VERSION', '2.0.11' );
define( 'AIN_FILE', __FILE__ );
define( 'AIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIN_OPTION_KEY', 'ain_settings' );

require_once AIN_DIR . 'includes/helpers.php';
require_once AIN_DIR . 'includes/class-db.php';
require_once AIN_DIR . 'includes/class-ai.php';
require_once AIN_DIR . 'includes/class-media.php';
require_once AIN_DIR . 'includes/class-sources.php';
require_once AIN_DIR . 'includes/class-writer.php';
require_once AIN_DIR . 'includes/class-campaigns.php';
require_once AIN_DIR . 'includes/class-admin.php';
require_once AIN_DIR . 'includes/class-ajax.php';

register_activation_hook( __FILE__, array( 'AIN_DB', 'activate' ) );
register_deactivation_hook( __FILE__, 'ain_deactivate' );

function ain_deactivate() {
    wp_clear_scheduled_hook( 'ain_cron_run_due_campaigns' );
    wp_clear_scheduled_hook( 'ain_cron_write_due_items' );
}

add_filter( 'cron_schedules', 'ain_cron_schedules' );
function ain_cron_schedules( $schedules ) {
    $schedules['ain_five_minutes'] = array(
        'interval' => 5 * MINUTE_IN_SECONDS,
        'display'  => __( 'Every 5 Minutes', 'ai-newsroom' ),
    );
    $schedules['ain_fifteen_minutes'] = array(
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display'  => __( 'Every 15 Minutes', 'ai-newsroom' ),
    );
    return $schedules;
}

add_action( 'plugins_loaded', 'ain_bootstrap' );
function ain_bootstrap() {
    if ( get_option( 'ain_version' ) !== AIN_VERSION ) {
        AIN_DB::activate();
    }
    AIN_Admin::init();
    AIN_Ajax::init();
}

add_action( 'init', 'ain_setup_cron' );
function ain_setup_cron() {
    if ( ! wp_next_scheduled( 'ain_cron_run_due_campaigns' ) ) {
        wp_schedule_event( time() + 60, 'ain_five_minutes', 'ain_cron_run_due_campaigns' );
    }
    if ( ! wp_next_scheduled( 'ain_cron_write_due_items' ) ) {
        wp_schedule_event( time() + 120, 'ain_fifteen_minutes', 'ain_cron_write_due_items' );
    }
}

add_action( 'ain_cron_run_due_campaigns', 'ain_cron_run_due_campaigns' );
function ain_cron_run_due_campaigns() {
    if ( get_transient( 'ain_campaign_runner_lock' ) ) {
        return;
    }
    set_transient( 'ain_campaign_runner_lock', 1, 10 * MINUTE_IN_SECONDS );
    AIN_Campaigns::run_due_campaigns();
    delete_transient( 'ain_campaign_runner_lock' );
}

add_action( 'ain_cron_write_due_items', 'ain_cron_write_due_items' );
function ain_cron_write_due_items() {
    if ( get_transient( 'ain_writer_lock' ) ) {
        return;
    }
    set_transient( 'ain_writer_lock', 1, 15 * MINUTE_IN_SECONDS );
    AIN_Writer::write_due_items();
    delete_transient( 'ain_writer_lock' );
}


/**
 * Background workers for long-running AI actions.
 * Admin AJAX returns immediately; WP-Cron handles the heavy work.
 */
add_action( 'ain_async_write_item', 'ain_async_write_item_worker', 10, 1 );
function ain_async_write_item_worker( $item_id ) {
    $item_id = (int) $item_id;
    if ( ! $item_id ) return;
    if ( get_transient( 'ain_async_write_lock_' . $item_id ) ) return;
    set_transient( 'ain_async_write_lock_' . $item_id, 1, 30 * MINUTE_IN_SECONDS );
    set_transient( 'ain_async_item_status_' . $item_id, array( 'state' => 'running', 'message' => 'Writing article…' ), HOUR_IN_SECONDS );
    $item = AIN_DB::get_item( $item_id );
    if ( ! $item ) {
        set_transient( 'ain_async_item_status_' . $item_id, array( 'state' => 'error', 'message' => 'Queue item not found.' ), HOUR_IN_SECONDS );
        delete_transient( 'ain_async_write_lock_' . $item_id );
        return;
    }
    $result = AIN_Writer::write_item( $item );
    if ( is_wp_error( $result ) ) {
        set_transient( 'ain_async_item_status_' . $item_id, array( 'state' => 'error', 'message' => $result->get_error_message() ), HOUR_IN_SECONDS );
    } else {
        set_transient( 'ain_async_item_status_' . $item_id, array( 'state' => 'complete', 'message' => $result['message'] ?? 'Article generated.', 'post_id' => (int) ( $result['post_id'] ?? 0 ) ), HOUR_IN_SECONDS );
    }
    delete_transient( 'ain_async_write_lock_' . $item_id );
}

add_action( 'ain_async_run_campaign', 'ain_async_run_campaign_worker', 10, 1 );
function ain_async_run_campaign_worker( $campaign_id ) {
    $campaign_id = (int) $campaign_id;
    if ( ! $campaign_id ) return;
    if ( get_transient( 'ain_async_campaign_lock_' . $campaign_id ) ) return;
    set_transient( 'ain_async_campaign_lock_' . $campaign_id, 1, 30 * MINUTE_IN_SECONDS );
    set_transient( 'ain_async_campaign_status_' . $campaign_id, array( 'state' => 'running', 'message' => 'Running campaign discovery…' ), HOUR_IN_SECONDS );
    $result = AIN_Campaigns::run_campaign( $campaign_id );
    if ( is_wp_error( $result ) ) {
        set_transient( 'ain_async_campaign_status_' . $campaign_id, array( 'state' => 'error', 'message' => $result->get_error_message() ), HOUR_IN_SECONDS );
    } else {
        set_transient( 'ain_async_campaign_status_' . $campaign_id, array( 'state' => 'complete', 'message' => $result['message'] ?? 'Campaign run complete.' ), HOUR_IN_SECONDS );
    }
    delete_transient( 'ain_async_campaign_lock_' . $campaign_id );
}

function ain_spawn_cron_async() {
    $cron_url = site_url( 'wp-cron.php?doing_wp_cron=' . rawurlencode( microtime( true ) ) );
    wp_remote_post( $cron_url, array(
        'timeout'   => 0.01,
        'blocking'  => false,
        'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
    ) );
}

/**
 * Background campaign writer. Triggered after campaign discovery when "run writer" is enabled.
 */
add_action( 'ain_async_write_campaign', 'ain_async_write_campaign_worker', 10, 1 );
function ain_async_write_campaign_worker( $campaign_id ) {
    $campaign_id = (int) $campaign_id;
    if ( ! $campaign_id ) return;
    if ( get_transient( 'ain_async_campaign_writer_lock_' . $campaign_id ) ) return;
    set_transient( 'ain_async_campaign_writer_lock_' . $campaign_id, 1, 30 * MINUTE_IN_SECONDS );

    $campaign = AIN_Campaigns::get( $campaign_id );
    if ( ! $campaign ) {
        delete_transient( 'ain_async_campaign_writer_lock_' . $campaign_id );
        return;
    }

    if ( empty( $campaign->schedule_config['run_writer'] ) ) {
        delete_transient( 'ain_async_campaign_writer_lock_' . $campaign_id );
        return;
    }

    $max = max( 0, (int) ( $campaign->publishing_config['max_posts_per_run'] ?? 1 ) );
    $written_count = 0;
    for ( $i = 0; $i < $max; $i++ ) {
        $result = AIN_Writer::write_next( $campaign_id );
        if ( is_wp_error( $result ) ) {
            if ( 'empty_queue' !== $result->get_error_code() ) {
                ain_log( 'warning', 'Background campaign writer stopped.', array( 'error' => $result->get_error_message() ), $campaign_id );
            }
            break;
        }
        $written_count++;
    }

    if ( $written_count > 0 ) {
        AIN_DB::update_campaign( $campaign_id, array( 'total_written' => (int) $campaign->total_written + $written_count ) );
    }
    delete_transient( 'ain_async_campaign_writer_lock_' . $campaign_id );
}
