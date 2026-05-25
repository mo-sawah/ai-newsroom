<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIN_DB {
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset   = $wpdb->get_charset_collate();
        $campaigns = ain_table( 'campaigns' );
        $queue     = ain_table( 'queue' );
        $logs      = ain_table( 'logs' );
        $seen      = ain_table( 'seen_sources' );

        $sql_campaigns = "CREATE TABLE {$campaigns} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            type varchar(60) NOT NULL DEFAULT 'rss',
            status varchar(30) NOT NULL DEFAULT 'paused',
            source_config longtext DEFAULT NULL,
            ai_config longtext DEFAULT NULL,
            publishing_config longtext DEFAULT NULL,
            media_config longtext DEFAULT NULL,
            social_config longtext DEFAULT NULL,
            schedule_config longtext DEFAULT NULL,
            last_run_at datetime DEFAULT NULL,
            next_run_at datetime DEFAULT NULL,
            last_error text DEFAULT NULL,
            total_found bigint(20) unsigned DEFAULT 0,
            total_queued bigint(20) unsigned DEFAULT 0,
            total_written bigint(20) unsigned DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY type (type),
            KEY status (status),
            KEY next_run_at (next_run_at)
        ) {$charset};";

        $sql_queue = "CREATE TABLE {$queue} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_id varchar(255) NOT NULL,
            source_mode varchar(60) NOT NULL DEFAULT '',
            source_name varchar(255) DEFAULT '',
            source_url text DEFAULT NULL,
            source_title text DEFAULT NULL,
            source_excerpt longtext DEFAULT NULL,
            raw_payload longtext DEFAULT NULL,
            research_pack longtext DEFAULT NULL,
            suggested_title text DEFAULT NULL,
            ai_summary longtext DEFAULT NULL,
            image_prompt longtext DEFAULT NULL,
            author_id bigint(20) unsigned DEFAULT 0,
            category_id bigint(20) unsigned DEFAULT 0,
            priority int(11) DEFAULT 50,
            quality_score int(11) DEFAULT 0,
            status varchar(50) NOT NULL DEFAULT 'planned',
            post_id bigint(20) unsigned DEFAULT 0,
            error_message text DEFAULT NULL,
            scheduled_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY campaign_source (campaign_id, source_id),
            KEY campaign_status (campaign_id, status),
            KEY status (status),
            KEY created_at (created_at),
            KEY priority (priority)
        ) {$charset};";

        $sql_logs = "CREATE TABLE {$logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned DEFAULT 0,
            queue_id bigint(20) unsigned DEFAULT 0,
            level varchar(30) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id),
            KEY queue_id (queue_id),
            KEY level (level),
            KEY created_at (created_at)
        ) {$charset};";

        $sql_seen = "CREATE TABLE {$seen} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_id varchar(255) NOT NULL DEFAULT '',
            url_hash varchar(64) NOT NULL DEFAULT '',
            source_url text DEFAULT NULL,
            source_title text DEFAULT NULL,
            source_name varchar(255) DEFAULT '',
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            seen_count int(11) NOT NULL DEFAULT 1,
            status varchar(30) NOT NULL DEFAULT 'seen',
            PRIMARY KEY (id),
            UNIQUE KEY campaign_source (campaign_id, source_id),
            KEY campaign_url_hash (campaign_id, url_hash),
            KEY last_seen_at (last_seen_at)
        ) {$charset};";

        dbDelta( $sql_campaigns );
        dbDelta( $sql_queue );
        dbDelta( $sql_logs );
        dbDelta( $sql_seen );

        if ( ! get_option( AIN_OPTION_KEY ) ) {
            update_option( AIN_OPTION_KEY, ain_default_settings(), false );
        }
        if ( function_exists( 'ain_upgrade_smart_grouping_prompts_205' ) ) {
            ain_upgrade_smart_grouping_prompts_205();
        }
        if ( function_exists( 'ain_upgrade_wire_prompts_206' ) ) {
            ain_upgrade_wire_prompts_206();
        }
        if ( function_exists( 'ain_upgrade_two_step_writer_prompts_212' ) ) {
            ain_upgrade_two_step_writer_prompts_212();
        }
        if ( function_exists( 'ain_upgrade_production_prompt_213' ) ) {
            ain_upgrade_production_prompt_213();
        }
        if ( function_exists( 'ain_upgrade_production_safety_214' ) ) {
            ain_upgrade_production_safety_214();
        }
        if ( function_exists( 'ain_upgrade_production_editor_toggle_215' ) ) {
            ain_upgrade_production_editor_toggle_215();
        }

        self::maybe_create_sample_campaign();
        update_option( 'ain_version', AIN_VERSION, false );
    }

    private static function maybe_create_sample_campaign() {
        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . ain_table( 'campaigns' ) );
        if ( $count > 0 ) {
            return;
        }
        $settings = ain_get_settings();
        $legacy_rss = get_option( 'mna_known_sources', '' );
        $source = array(
            'rss_feeds'     => $legacy_rss ? $legacy_rss : "https://timesofmalta.com/rss\nhttps://www.maltatoday.com.mt/rss",
            'topic_query'   => 'latest news',
            'language_code' => 'en',
            'country_code'  => '',
            'max_items'     => 20,
        );
        self::insert_campaign( array(
            'name'              => 'Starter RSS News Campaign',
            'type'              => 'rss',
            'status'            => 'paused',
            'source_config'     => $source,
            'ai_config'         => array(
                'editor_prompt'  => $settings['editor_prompt'],
                'writer_prompt'  => $settings['writer_prompt'],
                'tone'           => $settings['site_voice'],
                'research_depth' => 'balanced',
            ),
            'publishing_config' => array(
                'publish_mode'      => 'draft',
                'min_quality_score' => 75,
                'words_target'      => 700,
                'author_mode'       => 'auto',
                'default_author'    => 'auto',
                'category_mode'     => 'auto',
                'default_category'  => 'auto',
                'max_posts_per_run' => 1,
                'max_posts_per_day' => 20,
            ),
            'media_config'      => array(
                'generate_images'     => 1,
                'use_source_image'    => 1,
                'use_pexels'          => 0,
                'insert_inline_media' => 1,
                'image_style'         => 'modern editorial news image',
            ),
            'schedule_config'   => array(
                'interval_minutes' => 60,
                'run_writer'       => 1,
                'active_hours'     => '',
            ),
        ) );
    }

    public static function insert_campaign( $data ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $defaults = array(
            'name'              => 'Untitled Campaign',
            'type'              => 'rss',
            'status'            => 'paused',
            'source_config'     => array(),
            'ai_config'         => array(),
            'publishing_config' => array(),
            'media_config'      => array(),
            'social_config'     => array(),
            'schedule_config'   => array(),
            'last_run_at'       => null,
            'next_run_at'       => $now,
            'last_error'        => '',
            'created_at'        => $now,
            'updated_at'        => $now,
        );
        $data = wp_parse_args( $data, $defaults );
        $inserted = $wpdb->insert( ain_table( 'campaigns' ), self::sanitize_campaign_row( $data ) );
        return $inserted ? (int) $wpdb->insert_id : new WP_Error( 'campaign_insert_failed', $wpdb->last_error ?: 'Could not create campaign.' );
    }

    public static function update_campaign( $id, $data ) {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql' );
        $clean = self::sanitize_campaign_row( $data, false );
        return $wpdb->update( ain_table( 'campaigns' ), $clean, array( 'id' => (int) $id ) );
    }

    private static function sanitize_campaign_row( $data, $for_insert = true ) {
        $json_fields = array( 'source_config', 'ai_config', 'publishing_config', 'media_config', 'social_config', 'schedule_config' );
        $row = array();
        foreach ( $data as $key => $value ) {
            if ( in_array( $key, $json_fields, true ) ) {
                $row[ $key ] = is_string( $value ) ? $value : wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            } elseif ( 'id' === $key ) {
                continue;
            } elseif ( in_array( $key, array( 'total_found', 'total_queued', 'total_written' ), true ) ) {
                $row[ $key ] = (int) $value;
            } elseif ( in_array( $key, array( 'last_run_at', 'next_run_at', 'created_at', 'updated_at' ), true ) ) {
                $row[ $key ] = $value ? sanitize_text_field( $value ) : null;
            } elseif ( 'last_error' === $key ) {
                $row[ $key ] = sanitize_textarea_field( $value );
            } elseif ( 'status' === $key || 'type' === $key ) {
                $row[ $key ] = sanitize_key( $value );
            } else {
                $row[ $key ] = sanitize_text_field( $value );
            }
        }
        return $row;
    }

    public static function get_campaign( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . ain_table( 'campaigns' ) . " WHERE id = %d", (int) $id ) );
    }

    public static function get_campaigns( $args = array() ) {
        global $wpdb;
        $where = '1=1';
        if ( ! empty( $args['status'] ) ) {
            $where .= $wpdb->prepare( ' AND status = %s', sanitize_key( $args['status'] ) );
        }
        if ( ! empty( $args['type'] ) ) {
            $where .= $wpdb->prepare( ' AND type = %s', sanitize_key( $args['type'] ) );
        }
        return $wpdb->get_results( "SELECT * FROM " . ain_table( 'campaigns' ) . " WHERE {$where} ORDER BY updated_at DESC" );
    }

    public static function delete_campaign( $id ) {
        global $wpdb;
        $wpdb->delete( ain_table( 'queue' ), array( 'campaign_id' => (int) $id ) );
        return $wpdb->delete( ain_table( 'campaigns' ), array( 'id' => (int) $id ) );
    }

    public static function due_campaigns() {
        global $wpdb;
        $now = current_time( 'mysql' );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . ain_table( 'campaigns' ) . " WHERE status = 'active' AND (next_run_at IS NULL OR next_run_at <= %s) ORDER BY next_run_at ASC LIMIT 10", $now ) );
    }

    public static function count_by_status() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT status, COUNT(*) as total FROM " . ain_table( 'queue' ) . " GROUP BY status", ARRAY_A );
        $out = array();
        foreach ( $rows as $row ) {
            $out[ $row['status'] ] = (int) $row['total'];
        }
        return $out;
    }

    public static function campaign_counts() {
        global $wpdb;
        return array(
            'campaigns' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . ain_table( 'campaigns' ) ),
            'active'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . ain_table( 'campaigns' ) . " WHERE status = 'active'" ),
            'queue'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . ain_table( 'queue' ) . " WHERE status IN ('queued','writing','planned','approved','failed')" ),
            'published' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . ain_table( 'queue' ) . " WHERE status = 'published'" ),
        );
    }

    public static function insert_queue_item( $item ) {
        global $wpdb;
        $defaults = array(
            'campaign_id'     => 0,
            'source_id'       => '',
            'source_mode'     => '',
            'source_name'     => '',
            'source_url'      => '',
            'source_title'    => '',
            'source_excerpt'  => '',
            'raw_payload'     => '',
            'research_pack'   => '',
            'suggested_title' => '',
            'ai_summary'      => '',
            'image_prompt'    => '',
            'author_id'       => 0,
            'category_id'     => 0,
            'priority'        => 50,
            'quality_score'   => 0,
            'status'          => 'planned',
            'post_id'         => 0,
            'error_message'   => '',
            'scheduled_at'    => null,
            'created_at'      => current_time( 'mysql' ),
            'updated_at'      => current_time( 'mysql' ),
        );
        $item = wp_parse_args( $item, $defaults );

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . ain_table( 'queue' ) . " WHERE campaign_id = %d AND source_id = %s", (int) $item['campaign_id'], $item['source_id'] ) );
        if ( $exists ) {
            return new WP_Error( 'duplicate', 'Already in this campaign queue.' );
        }

        $inserted = $wpdb->insert(
            ain_table( 'queue' ),
            array(
                'campaign_id'     => (int) $item['campaign_id'],
                'source_id'       => sanitize_text_field( $item['source_id'] ),
                'source_mode'     => sanitize_key( $item['source_mode'] ),
                'source_name'     => sanitize_text_field( $item['source_name'] ),
                'source_url'      => esc_url_raw( $item['source_url'] ),
                'source_title'    => sanitize_text_field( $item['source_title'] ),
                'source_excerpt'  => wp_kses_post( $item['source_excerpt'] ),
                'raw_payload'     => is_string( $item['raw_payload'] ) ? $item['raw_payload'] : wp_json_encode( $item['raw_payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                'research_pack'   => is_string( $item['research_pack'] ) ? $item['research_pack'] : wp_json_encode( $item['research_pack'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                'suggested_title' => sanitize_text_field( $item['suggested_title'] ),
                'ai_summary'      => wp_kses_post( $item['ai_summary'] ),
                'image_prompt'    => sanitize_textarea_field( $item['image_prompt'] ),
                'author_id'       => (int) $item['author_id'],
                'category_id'     => (int) $item['category_id'],
                'priority'        => (int) $item['priority'],
                'quality_score'   => (int) $item['quality_score'],
                'status'          => sanitize_key( $item['status'] ),
                'post_id'         => (int) $item['post_id'],
                'error_message'   => sanitize_text_field( $item['error_message'] ),
                'scheduled_at'    => $item['scheduled_at'],
                'created_at'      => $item['created_at'],
                'updated_at'      => $item['updated_at'],
            )
        );

        return $inserted ? (int) $wpdb->insert_id : new WP_Error( 'db_insert_failed', $wpdb->last_error ?: 'Could not insert queue item.' );
    }

    public static function get_item( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . ain_table( 'queue' ) . " WHERE id = %d", (int) $id ) );
    }

    public static function update_item( $id, $fields ) {
        global $wpdb;
        $fields['updated_at'] = current_time( 'mysql' );
        $json_fields = array( 'raw_payload', 'research_pack' );
        foreach ( $fields as $key => $value ) {
            if ( in_array( $key, $json_fields, true ) && ! is_string( $value ) ) {
                $fields[ $key ] = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            } elseif ( in_array( $key, array( 'campaign_id', 'author_id', 'category_id', 'priority', 'quality_score', 'post_id' ), true ) ) {
                $fields[ $key ] = (int) $value;
            } elseif ( in_array( $key, array( 'source_id', 'source_name', 'source_title', 'suggested_title' ), true ) ) {
                $fields[ $key ] = sanitize_text_field( $value );
            } elseif ( in_array( $key, array( 'source_mode', 'status' ), true ) ) {
                $fields[ $key ] = sanitize_key( $value );
            } elseif ( 'source_url' === $key ) {
                $fields[ $key ] = esc_url_raw( $value );
            } elseif ( in_array( $key, array( 'source_excerpt', 'ai_summary' ), true ) ) {
                $fields[ $key ] = wp_kses_post( $value );
            } elseif ( in_array( $key, array( 'image_prompt', 'error_message' ), true ) ) {
                $fields[ $key ] = sanitize_textarea_field( $value );
            }
        }
        return $wpdb->update( ain_table( 'queue' ), $fields, array( 'id' => (int) $id ) );
    }

    public static function delete_item( $id ) {
        global $wpdb;
        return $wpdb->delete( ain_table( 'queue' ), array( 'id' => (int) $id ) );
    }

    public static function next_writable_item( $campaign_id = 0, $specific_id = 0 ) {
        global $wpdb;
        if ( $specific_id ) {
            return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . ain_table( 'queue' ) . " WHERE id = %d AND status IN ('queued','planned','approved','failed','needs_review')", (int) $specific_id ) );
        }
        if ( $campaign_id ) {
            return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . ain_table( 'queue' ) . " WHERE campaign_id = %d AND status IN ('planned','approved') ORDER BY priority DESC, created_at ASC LIMIT 1", (int) $campaign_id ) );
        }
        return $wpdb->get_row( "SELECT * FROM " . ain_table( 'queue' ) . " WHERE status IN ('planned','approved') ORDER BY priority DESC, created_at ASC LIMIT 1" );
    }


    public static function filter_unseen_sources( $campaign_id, $items ) {
        global $wpdb;
        $campaign_id = (int) $campaign_id;
        if ( empty( $items ) || ! $campaign_id ) return $items;
        $out = array();
        foreach ( $items as $item ) {
            $sid = sanitize_text_field( $item['source_id'] ?? '' );
            $url_hash = ain_url_hash( $item['url'] ?? '' );
            if ( ! $sid && ! $url_hash ) continue;
            $exists = 0;
            if ( $sid ) {
                $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . ain_table( 'seen_sources' ) . " WHERE campaign_id = %d AND source_id = %s LIMIT 1", $campaign_id, $sid ) );
            }
            if ( ! $exists && $url_hash ) {
                $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . ain_table( 'seen_sources' ) . " WHERE campaign_id = %d AND url_hash = %s LIMIT 1", $campaign_id, $url_hash ) );
            }
            if ( ! $exists ) $out[] = $item;
        }
        return $out;
    }

    public static function mark_sources_seen( $campaign_id, $items, $status = 'processed' ) {
        global $wpdb;
        $campaign_id = (int) $campaign_id;
        if ( empty( $items ) || ! $campaign_id ) return 0;
        $now = current_time( 'mysql' );
        $count = 0;
        foreach ( $items as $item ) {
            $sid = sanitize_text_field( $item['source_id'] ?? '' );
            $url = esc_url_raw( $item['url'] ?? '' );
            $hash = ain_url_hash( $url );
            if ( ! $sid && ! $hash ) continue;
            $existing = 0;
            if ( $sid ) {
                $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . ain_table( 'seen_sources' ) . " WHERE campaign_id = %d AND source_id = %s LIMIT 1", $campaign_id, $sid ) );
            }
            if ( ! $existing && $hash ) {
                $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . ain_table( 'seen_sources' ) . " WHERE campaign_id = %d AND url_hash = %s LIMIT 1", $campaign_id, $hash ) );
            }
            $row = array(
                'campaign_id'   => $campaign_id,
                'source_id'     => $sid ?: $hash,
                'url_hash'      => $hash,
                'source_url'    => $url,
                'source_title'  => sanitize_text_field( $item['title'] ?? '' ),
                'source_name'   => sanitize_text_field( $item['source_name'] ?? ain_safe_url_host( $url ) ),
                'last_seen_at'  => $now,
                'status'        => sanitize_key( $status ),
            );
            if ( $existing ) {
                $wpdb->query( $wpdb->prepare( "UPDATE " . ain_table( 'seen_sources' ) . " SET last_seen_at = %s, seen_count = seen_count + 1, status = %s WHERE id = %d", $now, sanitize_key( $status ), $existing ) );
            } else {
                $row['first_seen_at'] = $now;
                $row['seen_count'] = 1;
                $wpdb->insert( ain_table( 'seen_sources' ), $row );
            }
            $count++;
        }
        return $count;
    }

    public static function recent_queue_items_for_dedupe( $campaign_id, $limit = 250 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT id, source_id, source_title, source_excerpt, ai_summary, raw_payload, research_pack, status, created_at FROM " . ain_table( 'queue' ) . " WHERE campaign_id = %d ORDER BY created_at DESC LIMIT %d", (int) $campaign_id, max( 20, (int) $limit ) ) );
    }

    public static function queue_items( $args = array() ) {
        global $wpdb;
        $where = '1=1';
        if ( ! empty( $args['campaign_id'] ) ) {
            $where .= $wpdb->prepare( ' AND q.campaign_id = %d', (int) $args['campaign_id'] );
        }
        if ( ! empty( $args['status'] ) ) {
            $where .= $wpdb->prepare( ' AND q.status = %s', sanitize_key( $args['status'] ) );
        }
        $limit = ! empty( $args['limit'] ) ? (int) $args['limit'] : 100;
        return $wpdb->get_results( "SELECT q.*, c.name AS campaign_name, c.type AS campaign_type FROM " . ain_table( 'queue' ) . " q LEFT JOIN " . ain_table( 'campaigns' ) . " c ON c.id = q.campaign_id WHERE {$where} ORDER BY q.created_at DESC LIMIT {$limit}" );
    }
}
