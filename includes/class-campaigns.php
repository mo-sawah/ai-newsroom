<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIN_Campaigns {
    public static function defaults_for_type( $type = 'rss' ) {
        $settings = ain_get_settings();
        $type = sanitize_key( $type );
        return array(
            'source_config' => array(
                'rss_feeds'          => '',
                'urls'               => '',
                'manual_urls'        => '',
                'topic_query'        => '',
                'country_code'       => '',
                'language_code'      => 'en',
                'max_items'          => 20,
                'include_domains'    => '',
                'exclude_domains'    => '',
                'youtube_query'      => '',
                'press_release_urls' => '',
                'x_handles'          => '',
                'x_user_ids'         => '',
                'x_include_quotes'   => 1,
                'x_include_replies'  => 0,
                'x_include_retweets' => 0,
                'x_max_per_account'  => 10,
                'x_min_likes'        => 0,
                'x_min_reposts'      => 0,
                'x_min_replies'      => 0,
                'x_min_views'        => 0,
                'x_min_news_score'   => 55,
                'x_embed_tweet'      => 1,
                'x_embed_position'   => 'after_lede',
            ),
            'ai_config' => array(
                'editor_prompt'       => $settings['editor_prompt'],
                'writer_prompt'       => $settings['writer_prompt'],
                'production_prompt'   => $settings['production_prompt'],
                'production_editor_mode' => 'global',
                'fact_check_mode'     => 'global',
                'fact_check_title'    => '',
                'fact_check_max_sources' => '',
                'tone'                => $settings['site_voice'],
                'story_strategy'      => $settings['default_story_strategy'],
                'research_depth'      => 'balanced',
                'enable_research_pack'=> 1,
                'enable_web_search'   => (int) $settings['enable_openrouter_web_search'],
                'search_result_count' => (int) $settings['default_search_result_count'],
                'temperature'         => '0.35',
            ),
            'publishing_config' => array(
                'publish_mode'      => $settings['default_publish_mode'],
                'min_quality_score' => (int) $settings['default_min_quality'],
                'words_target'      => (int) $settings['default_words_target'],
                'author_mode'       => 'auto',
                'default_author'    => $settings['default_author'],
                'category_mode'     => 'auto',
                'default_category'  => $settings['default_category'],
                'max_posts_per_run' => 1,
                'max_posts_per_day' => 20,
            ),
            'media_config' => array(
                'generate_images'       => 1,
                'use_source_image'      => 1,
                'use_pexels'            => 0,
                'use_openrouter_image'  => 1,
                'insert_inline_media'   => 1,
                'enable_smart_tables'   => 1,
                'enable_smart_charts'   => 1,
                'image_style'           => 'modern professional editorial news image, no text overlay',
                'image_aspect_ratio'    => $settings['openrouter_image_aspect_ratio'],
                'image_size'            => $settings['openrouter_image_size'],
            ),
            'schedule_config' => array(
                'interval_minutes' => 60,
                'run_writer'       => 1,
                'active_hours'     => '',
            ),
        );
    }

    public static function parse( $campaign ) {
        if ( ! $campaign ) return null;
        $defaults = self::defaults_for_type( $campaign->type );
        $campaign->source_config     = wp_parse_args( ain_decode_json_field( $campaign->source_config ), $defaults['source_config'] );
        $campaign->ai_config         = wp_parse_args( ain_decode_json_field( $campaign->ai_config ), $defaults['ai_config'] );
        $campaign->publishing_config = wp_parse_args( ain_decode_json_field( $campaign->publishing_config ), $defaults['publishing_config'] );
        $campaign->media_config      = wp_parse_args( ain_decode_json_field( $campaign->media_config ), $defaults['media_config'] );
        $campaign->social_config     = array(); // Deprecated: retained only so older DB rows do not break external code.
        $campaign->schedule_config   = wp_parse_args( ain_decode_json_field( $campaign->schedule_config ), $defaults['schedule_config'] );
        return $campaign;
    }

    public static function get( $id ) {
        return self::parse( AIN_DB::get_campaign( $id ) );
    }

    public static function all( $args = array() ) {
        return array_map( array( __CLASS__, 'parse' ), AIN_DB::get_campaigns( $args ) );
    }

    public static function save_from_request( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'unauthorized', 'Unauthorized.' );
        }

        $id   = isset( $request['campaign_id'] ) ? (int) $request['campaign_id'] : 0;
        $type = isset( $request['type'] ) ? sanitize_key( wp_unslash( $request['type'] ) ) : 'rss';
        $allowed_types = array_keys( ain_campaign_types() );
        if ( ! in_array( $type, $allowed_types, true ) ) {
            $type = 'rss';
        }
        $defaults = self::defaults_for_type( $type );

        $source = array(
            'rss_feeds'          => isset( $request['rss_feeds'] ) ? wp_kses_post( wp_unslash( $request['rss_feeds'] ) ) : '',
            'urls'               => isset( $request['urls'] ) ? wp_kses_post( wp_unslash( $request['urls'] ) ) : '',
            'manual_urls'        => isset( $request['manual_urls'] ) ? wp_kses_post( wp_unslash( $request['manual_urls'] ) ) : '',
            'topic_query'        => isset( $request['topic_query'] ) ? sanitize_text_field( wp_unslash( $request['topic_query'] ) ) : '',
            'country_code'       => isset( $request['country_code'] ) ? sanitize_text_field( wp_unslash( $request['country_code'] ) ) : '',
            'language_code'      => isset( $request['language_code'] ) ? sanitize_text_field( wp_unslash( $request['language_code'] ) ) : 'en',
            'max_items'          => isset( $request['max_items'] ) ? max( 1, (int) $request['max_items'] ) : 20,
            'include_domains'    => isset( $request['include_domains'] ) ? sanitize_textarea_field( wp_unslash( $request['include_domains'] ) ) : '',
            'exclude_domains'    => isset( $request['exclude_domains'] ) ? sanitize_textarea_field( wp_unslash( $request['exclude_domains'] ) ) : '',
            'youtube_query'      => isset( $request['youtube_query'] ) ? sanitize_text_field( wp_unslash( $request['youtube_query'] ) ) : '',
            'press_release_urls' => isset( $request['press_release_urls'] ) ? wp_kses_post( wp_unslash( $request['press_release_urls'] ) ) : '',
            'x_handles'          => isset( $request['x_handles'] ) ? sanitize_textarea_field( wp_unslash( $request['x_handles'] ) ) : '',
            'x_user_ids'         => isset( $request['x_user_ids'] ) ? sanitize_textarea_field( wp_unslash( $request['x_user_ids'] ) ) : '',
            'x_include_quotes'   => isset( $request['x_include_quotes'] ) ? 1 : 0,
            'x_include_replies'  => isset( $request['x_include_replies'] ) ? 1 : 0,
            'x_include_retweets' => isset( $request['x_include_retweets'] ) ? 1 : 0,
            'x_max_per_account'  => isset( $request['x_max_per_account'] ) ? max( 1, min( 20, (int) $request['x_max_per_account'] ) ) : 10,
            'x_min_likes'        => isset( $request['x_min_likes'] ) ? max( 0, (int) $request['x_min_likes'] ) : 0,
            'x_min_reposts'      => isset( $request['x_min_reposts'] ) ? max( 0, (int) $request['x_min_reposts'] ) : 0,
            'x_min_replies'      => isset( $request['x_min_replies'] ) ? max( 0, (int) $request['x_min_replies'] ) : 0,
            'x_min_views'        => isset( $request['x_min_views'] ) ? max( 0, (int) $request['x_min_views'] ) : 0,
            'x_min_news_score'   => isset( $request['x_min_news_score'] ) ? max( 0, min( 100, (int) $request['x_min_news_score'] ) ) : 55,
            'x_embed_tweet'      => isset( $request['x_embed_tweet'] ) ? 1 : 0,
            'x_embed_position'   => isset( $request['x_embed_position'] ) ? sanitize_key( wp_unslash( $request['x_embed_position'] ) ) : 'after_lede',
        );
        if ( ! in_array( $source['x_embed_position'], array( 'after_lede', 'after_second_paragraph', 'before_fact_check' ), true ) ) {
            $source['x_embed_position'] = 'after_lede';
        }

        $story_strategy = isset( $request['story_strategy'] ) ? sanitize_key( wp_unslash( $request['story_strategy'] ) ) : 'smart';
        if ( ! in_array( $story_strategy, array( 'source_first', 'smart', 'aggressive' ), true ) ) $story_strategy = 'smart';
        $research_depth = isset( $request['research_depth'] ) ? sanitize_key( wp_unslash( $request['research_depth'] ) ) : 'balanced';
        if ( ! in_array( $research_depth, array( 'fast', 'balanced', 'deep' ), true ) ) $research_depth = 'balanced';
        $production_editor_mode = isset( $request['production_editor_mode'] ) ? sanitize_key( wp_unslash( $request['production_editor_mode'] ) ) : 'global';
        if ( ! in_array( $production_editor_mode, array( 'global', 'enabled', 'disabled' ), true ) ) $production_editor_mode = 'global';
        $fact_check_mode = isset( $request['fact_check_mode'] ) ? sanitize_key( wp_unslash( $request['fact_check_mode'] ) ) : 'global';
        if ( ! in_array( $fact_check_mode, array( 'global', 'enabled', 'disabled' ), true ) ) $fact_check_mode = 'global';

        $editor_prompt = isset( $request['editor_prompt'] ) ? wp_kses_post( wp_unslash( $request['editor_prompt'] ) ) : $defaults['ai_config']['editor_prompt'];
        $writer_prompt = isset( $request['writer_prompt'] ) ? wp_kses_post( wp_unslash( $request['writer_prompt'] ) ) : $defaults['ai_config']['writer_prompt'];
        $production_prompt = isset( $request['production_prompt'] ) ? wp_kses_post( wp_unslash( $request['production_prompt'] ) ) : $defaults['ai_config']['production_prompt'];
        if ( function_exists( 'ain_clean_external_link_prompt' ) ) {
            $writer_prompt = ain_clean_external_link_prompt( $writer_prompt, 'writer_prompt' );
            $production_prompt = ain_clean_external_link_prompt( $production_prompt, 'production_prompt' );
        }

        $ai = array(
            'editor_prompt'       => $editor_prompt,
            'writer_prompt'       => $writer_prompt,
            'production_prompt'   => $production_prompt,
            'production_editor_mode' => $production_editor_mode,
            'fact_check_mode'     => $fact_check_mode,
            'fact_check_title'    => isset( $request['fact_check_title'] ) ? sanitize_text_field( wp_unslash( $request['fact_check_title'] ) ) : '',
            'fact_check_max_sources' => isset( $request['fact_check_max_sources'] ) ? sanitize_text_field( wp_unslash( $request['fact_check_max_sources'] ) ) : '',
            'tone'                => isset( $request['tone'] ) ? wp_kses_post( wp_unslash( $request['tone'] ) ) : $defaults['ai_config']['tone'],
            'story_strategy'      => $story_strategy,
            'research_depth'      => $research_depth,
            'enable_research_pack'=> isset( $request['enable_research_pack'] ) ? 1 : 0,
            'enable_web_search'   => isset( $request['enable_web_search'] ) ? 1 : 0,
            'search_result_count' => isset( $request['search_result_count'] ) ? max( 3, min( 10, (int) $request['search_result_count'] ) ) : 6,
            'temperature'         => isset( $request['temperature'] ) ? sanitize_text_field( wp_unslash( $request['temperature'] ) ) : '0.35',
        );

        $pub = array(
            'publish_mode'      => isset( $request['publish_mode'] ) ? sanitize_key( wp_unslash( $request['publish_mode'] ) ) : 'draft',
            'min_quality_score' => isset( $request['min_quality_score'] ) ? max( 0, min( 100, (int) $request['min_quality_score'] ) ) : 75,
            'words_target'      => isset( $request['words_target'] ) ? max( 250, (int) $request['words_target'] ) : 700,
            'author_mode'       => isset( $request['author_mode'] ) ? sanitize_key( wp_unslash( $request['author_mode'] ) ) : 'auto',
            'default_author'    => isset( $request['default_author'] ) ? sanitize_text_field( wp_unslash( $request['default_author'] ) ) : 'auto',
            'category_mode'     => isset( $request['category_mode'] ) ? sanitize_key( wp_unslash( $request['category_mode'] ) ) : 'auto',
            'default_category'  => isset( $request['default_category'] ) ? sanitize_text_field( wp_unslash( $request['default_category'] ) ) : 'auto',
            'max_posts_per_run' => isset( $request['max_posts_per_run'] ) ? max( 0, (int) $request['max_posts_per_run'] ) : 1,
            'max_posts_per_day' => isset( $request['max_posts_per_day'] ) ? max( 0, (int) $request['max_posts_per_day'] ) : 20,
        );

        $media = array(
            'generate_images'      => isset( $request['generate_images'] ) ? 1 : 0,
            'use_source_image'     => isset( $request['use_source_image'] ) ? 1 : 0,
            'use_pexels'           => isset( $request['use_pexels'] ) ? 1 : 0,
            'use_openrouter_image' => isset( $request['use_openrouter_image'] ) ? 1 : 0,
            'insert_inline_media'  => isset( $request['insert_inline_media'] ) ? 1 : 0,
            'enable_smart_tables'  => isset( $request['enable_smart_tables'] ) ? 1 : 0,
            'enable_smart_charts'  => isset( $request['enable_smart_charts'] ) ? 1 : 0,
            'image_style'          => isset( $request['image_style'] ) ? sanitize_text_field( wp_unslash( $request['image_style'] ) ) : '',
            'image_aspect_ratio'   => isset( $request['image_aspect_ratio'] ) ? sanitize_text_field( wp_unslash( $request['image_aspect_ratio'] ) ) : '16:9',
            'image_size'           => isset( $request['image_size'] ) ? sanitize_text_field( wp_unslash( $request['image_size'] ) ) : '1K',
        );

        $schedule = array(
            'interval_minutes' => isset( $request['interval_minutes'] ) ? max( 5, (int) $request['interval_minutes'] ) : 60,
            'run_writer'       => isset( $request['run_writer'] ) ? 1 : 0,
            'active_hours'     => isset( $request['active_hours'] ) ? sanitize_text_field( wp_unslash( $request['active_hours'] ) ) : '',
        );

        $status = isset( $request['status'] ) ? sanitize_key( wp_unslash( $request['status'] ) ) : 'paused';
        if ( ! in_array( $status, array( 'active', 'paused' ), true ) ) {
            $status = 'paused';
        }

        $row = array(
            'name'              => isset( $request['name'] ) ? sanitize_text_field( wp_unslash( $request['name'] ) ) : 'Untitled Campaign',
            'type'              => $type,
            'status'            => $status,
            'source_config'     => wp_parse_args( $source, $defaults['source_config'] ),
            'ai_config'         => wp_parse_args( $ai, $defaults['ai_config'] ),
            'publishing_config' => wp_parse_args( $pub, $defaults['publishing_config'] ),
            'media_config'      => wp_parse_args( $media, $defaults['media_config'] ),
            'schedule_config'   => wp_parse_args( $schedule, $defaults['schedule_config'] ),
            'next_run_at'       => self::next_run_time( $schedule['interval_minutes'] ),
            'last_error'        => '',
        );

        if ( $id ) {
            AIN_DB::update_campaign( $id, $row );
            return $id;
        }
        return AIN_DB::insert_campaign( $row );
    }

    public static function duplicate( $id ) {
        $campaign = self::get( $id );
        if ( ! $campaign ) {
            return new WP_Error( 'missing_campaign', 'Campaign not found.' );
        }
        return AIN_DB::insert_campaign( array(
            'name'              => $campaign->name . ' Copy',
            'type'              => $campaign->type,
            'status'            => 'paused',
            'source_config'     => $campaign->source_config,
            'ai_config'         => $campaign->ai_config,
            'publishing_config' => $campaign->publishing_config,
            'media_config'      => $campaign->media_config,
            'schedule_config'   => $campaign->schedule_config,
            'next_run_at'       => current_time( 'mysql' ),
        ) );
    }

    public static function next_run_time( $interval_minutes ) {
        return date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + max( 5, (int) $interval_minutes ) * MINUTE_IN_SECONDS );
    }

    public static function active_hours_allows_run( $active_hours ) {
        $active_hours = trim( (string) $active_hours );
        if ( '' === $active_hours ) return true;
        if ( ! preg_match( '/^(\d{1,2})\s*-\s*(\d{1,2})$/', $active_hours, $m ) ) return true;
        $start = max( 0, min( 23, (int) $m[1] ) );
        $end   = max( 0, min( 23, (int) $m[2] ) );
        $hour  = (int) current_time( 'G' );
        if ( $start <= $end ) return $hour >= $start && $hour <= $end;
        return $hour >= $start || $hour <= $end;
    }

    public static function run_due_campaigns() {
        $campaigns = AIN_DB::due_campaigns();
        $results = array();
        foreach ( $campaigns as $campaign ) {
            $results[] = self::run_campaign( $campaign->id );
        }
        return $results;
    }

    public static function run_campaign( $id ) {
        $campaign = self::get( $id );
        if ( ! $campaign ) {
            return new WP_Error( 'missing_campaign', 'Campaign not found.' );
        }
        if ( ! self::active_hours_allows_run( $campaign->schedule_config['active_hours'] ?? '' ) ) {
            AIN_DB::update_campaign( $campaign->id, array(
                'next_run_at' => self::next_run_time( $campaign->schedule_config['interval_minutes'] ),
                'last_run_at' => current_time( 'mysql' ),
            ) );
            return array( 'message' => 'Skipped because campaign is outside active hours.', 'added' => 0 );
        }

        $result = AIN_Sources::discover_for_campaign( $campaign );
        $update = array(
            'last_run_at' => current_time( 'mysql' ),
            'next_run_at' => self::next_run_time( $campaign->schedule_config['interval_minutes'] ?? 60 ),
        );
        if ( is_wp_error( $result ) ) {
            $update['last_error'] = $result->get_error_message();
            AIN_DB::update_campaign( $campaign->id, $update );
            ain_log( 'error', 'Campaign run failed.', array( 'error' => $result->get_error_message() ), $campaign->id );
            return $result;
        }

        $update['last_error'] = '';
        $update['total_found'] = (int) $campaign->total_found + (int) ( $result['found'] ?? 0 );
        $update['total_queued'] = (int) $campaign->total_queued + (int) ( $result['added'] ?? 0 );
        AIN_DB::update_campaign( $campaign->id, $update );

        if ( ! empty( $campaign->schedule_config['run_writer'] ) ) {
            $max = max( 0, (int) ( $campaign->publishing_config['max_posts_per_run'] ?? 1 ) );
            if ( $max > 0 ) {
                wp_schedule_single_event( time() + 5, 'ain_async_write_campaign', array( (int) $campaign->id ) );
                if ( function_exists( 'ain_spawn_cron_async' ) ) ain_spawn_cron_async();
                $result['message'] = ( $result['message'] ?? 'Campaign run complete.' ) . ' Writer queued in background.';
            }
        }

        return $result;
    }
}
