<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIN_Sources {
    public static function discover_for_campaign( $campaign ) {
        $campaign = AIN_Campaigns::parse( $campaign );
        if ( ! $campaign ) return new WP_Error( 'missing_campaign', 'Campaign not found.' );

        $items = self::collect_raw_items( $campaign );
        if ( is_wp_error( $items ) ) return $items;

        $found = count( $items );
        $items = self::filter_domains( $items, $campaign );
        $items = self::unique_source_items( $items );
        $before_seen_filter = count( $items );
        $items = AIN_DB::filter_unseen_sources( $campaign->id, $items );
        if ( empty( $items ) ) {
            ain_log( 'info', 'No new source items after seen-source filtering.', array( 'found' => $found, 'eligible_before_seen_filter' => $before_seen_filter ), $campaign->id );
            return array( 'found' => $found, 'added' => 0, 'message' => 'No new source items found.' );
        }
        $max_items = max( 1, (int) ( $campaign->source_config['max_items'] ?? 20 ) );
        $items = array_slice( $items, 0, $max_items );

        $stories = AIN_AI::story_clusters( $items, $campaign );
        if ( is_wp_error( $stories ) ) return $stories;
        AIN_DB::mark_sources_seen( $campaign->id, $items, 'processed' );
        if ( empty( $stories ) ) {
            ain_log( 'info', 'AI story desk rejected all items.', array( 'found' => $found, 'eligible' => count( $items ) ), $campaign->id );
            return array( 'found' => $found, 'added' => 0, 'message' => 'No story clusters approved.' );
        }

        $added = self::insert_story_items( $items, $stories, $campaign );
        ain_log( 'info', 'Campaign discovery complete.', array(
            'type' => $campaign->type,
            'found' => $found,
            'eligible' => count( $items ),
            'story_clusters' => count( $stories ),
            'added' => $added,
        ), $campaign->id );

        return array(
            'found' => $found,
            'added' => $added,
            'message' => "Campaign run complete: {$added} story cluster(s) added from {$found} source item(s).",
        );
    }

    private static function collect_raw_items( $campaign ) {
        switch ( $campaign->type ) {
            case 'x_twitter':
                return self::from_x_twitter( $campaign );
            case 'gnews':
                return self::from_gnews( $campaign );
            case 'firecrawl':
                return self::from_firecrawl_campaign( $campaign );
            case 'perplexity':
                return self::from_perplexity( $campaign );
            case 'press_release':
                return self::from_press_release( $campaign );
            case 'youtube':
                return self::from_youtube( $campaign );
            case 'manual':
                return self::from_manual( $campaign );
            case 'rss':
            default:
                return self::from_rss( $campaign );
        }
    }

    private static function unique_source_items( $items ) {
        $seen_ids = array();
        $seen_urls = array();
        $out = array();
        foreach ( $items as $item ) {
            if ( empty( $item['source_id'] ) ) continue;
            $sid = (string) $item['source_id'];
            $url = ! empty( $item['url'] ) ? strtolower( trim( $item['url'] ) ) : '';
            if ( isset( $seen_ids[ $sid ] ) ) continue;
            if ( $url && isset( $seen_urls[ $url ] ) ) continue;
            $seen_ids[ $sid ] = true;
            if ( $url ) $seen_urls[ $url ] = true;
            $out[] = $item;
        }
        return $out;
    }

    private static function filter_domains( $items, $campaign ) {
        $include = array_map( 'strtolower', ain_array_from_lines( $campaign->source_config['include_domains'] ?? '' ) );
        $exclude = array_map( 'strtolower', ain_array_from_lines( $campaign->source_config['exclude_domains'] ?? '' ) );
        if ( empty( $include ) && empty( $exclude ) ) return $items;
        $out = array();
        foreach ( $items as $item ) {
            $host = strtolower( ain_safe_url_host( $item['url'] ?? '' ) );
            if ( $host && ! empty( $include ) ) {
                $ok = false;
                foreach ( $include as $domain ) {
                    if ( false !== strpos( $host, trim( $domain ) ) ) { $ok = true; break; }
                }
                if ( ! $ok ) continue;
            }
            if ( $host && ! empty( $exclude ) ) {
                $skip = false;
                foreach ( $exclude as $domain ) {
                    if ( false !== strpos( $host, trim( $domain ) ) ) { $skip = true; break; }
                }
                if ( $skip ) continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    private static function insert_story_items( $items, $stories, $campaign ) {
        $by_id = array();
        foreach ( $items as $item ) {
            if ( ! empty( $item['source_id'] ) ) $by_id[ $item['source_id'] ] = $item;
        }
        $added = 0;
        foreach ( $stories as $story ) {
            if ( empty( $story['approved'] ) || empty( $story['source_ids'] ) ) continue;
            $sources = array();
            foreach ( (array) $story['source_ids'] as $sid ) {
                if ( isset( $by_id[ $sid ] ) ) $sources[] = $by_id[ $sid ];
            }
            if ( empty( $sources ) ) continue;

            $primary_id = $story['primary_source_id'] ?? $sources[0]['source_id'];
            $primary = $sources[0];
            foreach ( $sources as $source ) {
                if ( $source['source_id'] === $primary_id ) { $primary = $source; break; }
            }

            $fingerprint = ain_story_fingerprint( ( $story['working_label'] ?? $primary['title'] ?? '' ) . ' ' . ( $story['topic_area'] ?? '' ) . ' ' . ( $story['event_type'] ?? '' ) . ' ' . ( $story['core_action'] ?? '' ) . ' ' . ( $story['core_claim'] ?? '' ) . ' ' . ( $story['news_peg'] ?? '' ) . ' ' . ( $story['story_summary'] ?? '' ) . ' ' . ( $story['editorial_angle'] ?? '' ) );
            if ( ! $fingerprint ) {
                $fingerprint = md5( implode( '|', wp_list_pluck( $sources, 'source_id' ) ) );
            }
            if ( self::similar_story_already_exists( $campaign->id, $fingerprint, $story ) ) {
                ain_log( 'info', 'Skipped duplicate/similar story cluster.', array( 'fingerprint' => $fingerprint, 'working_label' => $story['working_label'] ?? '' ), $campaign->id );
                continue;
            }

            if ( 'x_twitter' === $campaign->type ) {
                $min_news_score = max( 0, min( 100, (int) ( $campaign->source_config['x_min_news_score'] ?? 55 ) ) );
                $score = max( (int) ( $story['quality_score'] ?? 0 ), (int) ( $story['priority'] ?? 0 ) );
                if ( $score < $min_news_score ) {
                    ain_log( 'info', 'Skipped low-score X/Twitter item.', array( 'score' => $score, 'minimum' => $min_news_score, 'working_label' => $story['working_label'] ?? '' ), $campaign->id );
                    continue;
                }
            }

            $source_id = 'story_' . md5( $campaign->id . '|' . $fingerprint );

            $research_seed = array(
                'finalized' => false,
                'story_cluster' => $story,
                'source_pack' => $sources,
                'story_fingerprint' => $fingerprint,
                'source_count' => count( $sources ),
            );

            $raw_payload = array(
                'type' => 'story_cluster',
                'story_cluster' => $story,
                'sources' => $sources,
                'primary_source' => $primary,
                'story_fingerprint' => $fingerprint,
            );

            $result = AIN_DB::insert_queue_item( array(
                'campaign_id'     => (int) $campaign->id,
                'source_id'       => $source_id,
                'source_mode'     => $campaign->type . '_story',
                'source_name'     => count( $sources ) > 1 ? 'Multisource' : ( $primary['source_name'] ?? ain_safe_url_host( $primary['url'] ?? '' ) ),
                'source_url'      => $primary['url'] ?? '',
                'source_title'    => $story['working_label'] ?? ( $primary['title'] ?? '' ),
                'source_excerpt'  => $story['story_summary'] ?? ( $primary['description'] ?? '' ),
                'raw_payload'     => $raw_payload,
                'research_pack'   => $research_seed,
                'suggested_title' => $story['working_label'] ?? ( $primary['title'] ?? '' ),
                'ai_summary'      => trim( 'Story desk brief: ' . ( $story['story_summary'] ?? '' ) . "\n\nGrouping logic: " . ( $story['grouping_logic'] ?? '' ) . "\n\nSplit logic: " . ( $story['split_logic'] ?? '' ) . "\n\nSpecific development: " . trim( ( $story['topic_area'] ?? '' ) . ' / ' . ( $story['event_type'] ?? '' ) . ' / ' . ( $story['core_action'] ?? '' ) . ' / ' . ( $story['core_claim'] ?? '' ) ) . "\n\nWhy selected: " . ( $story['selection_reason'] ?? '' ) . "\n\nRough angle: " . ( $story['editorial_angle'] ?? '' ) . "\n\nWhy it matters: " . ( $story['why_it_matters'] ?? '' ) . "\n\nTitle direction for research: " . ( $story['title_direction'] ?? '' ) ),
                'image_prompt'    => $story['image_prompt'] ?? '',
                'author_id'       => (int) ( $story['author_id'] ?? 0 ),
                'category_id'     => (int) ( $story['category_id'] ?? 0 ),
                'priority'        => (int) ( $story['priority'] ?? 50 ),
                'quality_score'   => (int) ( $story['quality_score'] ?? 0 ),
                'status'          => 'planned',
            ) );
            if ( ! is_wp_error( $result ) ) $added++;
        }
        return $added;
    }


    private static function similar_story_already_exists( $campaign_id, $fingerprint, $story ) {
        $recent = AIN_DB::recent_queue_items_for_dedupe( $campaign_id, 250 );
        if ( empty( $recent ) ) return false;
        $story_text = trim( ( $story['working_label'] ?? '' ) . ' ' . ( $story['topic_area'] ?? '' ) . ' ' . ( $story['event_type'] ?? '' ) . ' ' . ( $story['core_action'] ?? '' ) . ' ' . ( $story['core_claim'] ?? '' ) . ' ' . ( $story['news_peg'] ?? '' ) . ' ' . ( $story['story_summary'] ?? '' ) . ' ' . ( $story['editorial_angle'] ?? '' ) . ' ' . $fingerprint );
        foreach ( $recent as $row ) {
            $raw = ain_decode_json_field( $row->raw_payload );
            $rp  = ain_decode_json_field( $row->research_pack );
            $old_fp = $raw['story_fingerprint'] ?? ( $rp['story_fingerprint'] ?? '' );
            if ( $old_fp && $fingerprint && $old_fp === $fingerprint ) return true;
            $old_cluster = $raw['story_cluster'] ?? array();
            $old_text = trim( $row->source_title . ' ' . $row->source_excerpt . ' ' . $row->ai_summary . ' ' . ( $old_cluster['working_label'] ?? '' ) . ' ' . ( $old_cluster['topic_area'] ?? '' ) . ' ' . ( $old_cluster['event_type'] ?? '' ) . ' ' . ( $old_cluster['core_action'] ?? '' ) . ' ' . ( $old_cluster['core_claim'] ?? '' ) . ' ' . ( $old_cluster['news_peg'] ?? '' ) . ' ' . ( $old_cluster['story_summary'] ?? '' ) . ' ' . ( $old_cluster['editorial_angle'] ?? '' ) . ' ' . $old_fp );
            if ( ain_story_topics_conflict( $story_text, $old_text ) ) continue;
            $sim = ain_text_similarity( $story_text, $old_text );
            if ( $sim >= 0.52 ) return true;
        }
        return false;
    }

    public static function from_rss( $campaign ) {
        $feeds = ain_array_from_lines( $campaign->source_config['rss_feeds'] ?? '' );
        if ( empty( $feeds ) ) return new WP_Error( 'no_rss_feeds', 'No RSS feeds configured for this campaign.' );
        include_once ABSPATH . WPINC . '/feed.php';
        $items = array();
        foreach ( $feeds as $feed_url ) {
            $rss = fetch_feed( $feed_url );
            if ( is_wp_error( $rss ) ) {
                ain_log( 'warning', 'RSS feed failed.', array( 'url' => $feed_url, 'error' => $rss->get_error_message() ), $campaign->id );
                continue;
            }
            $max = min( 30, $rss->get_item_quantity( 30 ) );
            foreach ( $rss->get_items( 0, $max ) as $item ) {
                $url = $item->get_permalink();
                if ( ! $url ) continue;
                $items[] = array(
                    'source_id'   => 'rss_' . md5( $url ),
                    'source_name' => ain_safe_url_host( $url ),
                    'title'       => html_entity_decode( wp_strip_all_tags( $item->get_title() ) ),
                    'description' => wp_trim_words( wp_strip_all_tags( $item->get_description() ), 90 ),
                    'url'         => esc_url_raw( $url ),
                    'published'   => $item->get_date( 'c' ),
                    'image'       => $item->get_enclosure() ? $item->get_enclosure()->get_link() : '',
                );
            }
        }
        return $items;
    }

    public static function from_gnews( $campaign ) {
        $settings = ain_get_settings();
        if ( empty( $settings['gnews_api_key'] ) ) return new WP_Error( 'missing_gnews', 'GNews API key is missing.' );
        $query = $campaign->source_config['topic_query'] ?: $campaign->name;
        $args = array(
            'q'      => $query,
            'lang'   => $campaign->source_config['language_code'] ?: 'en',
            'max'    => 30,
            'apikey' => $settings['gnews_api_key'],
        );
        if ( ! empty( $campaign->source_config['country_code'] ) ) {
            $args['country'] = strtolower( $campaign->source_config['country_code'] );
        }
        $response = wp_remote_get( add_query_arg( $args, 'https://gnews.io/api/v4/search' ), array( 'timeout' => 30 ) );
        if ( is_wp_error( $response ) ) return $response;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $items = array();
        foreach ( $body['articles'] ?? array() as $article ) {
            if ( empty( $article['url'] ) ) continue;
            $items[] = array(
                'source_id'   => 'gnews_' . md5( $article['url'] ),
                'source_name' => ain_safe_url_host( $article['url'] ),
                'title'       => $article['title'] ?? '',
                'description' => $article['description'] ?? '',
                'url'         => esc_url_raw( $article['url'] ),
                'published'   => $article['publishedAt'] ?? '',
                'image'       => $article['image'] ?? '',
                'raw'         => $article,
            );
        }
        return $items;
    }

    public static function from_firecrawl_campaign( $campaign ) {
        $urls = ain_array_from_lines( $campaign->source_config['urls'] ?? '' );
        return self::from_firecrawl_urls( $urls, $campaign, 'firecrawl' );
    }

    public static function from_firecrawl_urls( $urls, $campaign, $mode = 'firecrawl' ) {
        $settings = ain_get_settings();
        if ( empty( $settings['firecrawl_api_key'] ) ) return new WP_Error( 'missing_firecrawl', 'Firecrawl API key is missing.' );
        if ( empty( $urls ) ) return new WP_Error( 'no_firecrawl_urls', 'No URLs configured for this campaign.' );

        $last_key = 'ain_campaign_' . (int) $campaign->id . '_last_index';
        $index = (int) get_option( $last_key, 0 );
        if ( $index >= count( $urls ) ) $index = 0;
        $target = $urls[ $index ];
        update_option( $last_key, $index + 1, false );

        $prompt = 'Extract the newest article/news links from this page. Ignore ads, menus, cookie banners, sidebars, popular posts, and unrelated navigation.';
        if ( 'press_release' === $mode ) {
            $prompt = 'Extract the newest press releases or organization announcements. Preserve organization name, title, URL, date, and short description. Ignore ads and navigation.';
        }
        $payload = array(
            'url'     => $target,
            'formats' => array( 'extract' ),
            'extract' => array(
                'prompt' => $prompt,
                'schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'articles' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'title'        => array( 'type' => 'string' ),
                                    'description'  => array( 'type' => 'string' ),
                                    'url'          => array( 'type' => 'string' ),
                                    'image'        => array( 'type' => 'string' ),
                                    'organization' => array( 'type' => 'string' ),
                                    'date'         => array( 'type' => 'string' ),
                                ),
                                'required' => array( 'title', 'url' ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $response = wp_remote_post( 'https://api.firecrawl.dev/v1/scrape', array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['firecrawl_api_key'],
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
        ) );
        if ( is_wp_error( $response ) ) return $response;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $articles = $body['data']['extract']['articles'] ?? array();
        $items = array();
        foreach ( $articles as $article ) {
            if ( empty( $article['title'] ) || empty( $article['url'] ) ) continue;
            $url = self::absolute_url( $article['url'], $target );
            $items[] = array(
                'source_id'    => $mode . '_' . md5( $url ),
                'source_name'  => $article['organization'] ?? ain_safe_url_host( $url ),
                'title'        => $article['title'],
                'description'  => $article['description'] ?? '',
                'url'          => $url,
                'published'    => $article['date'] ?? '',
                'image'        => $article['image'] ?? '',
                'organization' => $article['organization'] ?? '',
                'raw'          => $article,
            );
        }
        return $items;
    }

    public static function from_perplexity( $campaign ) {
        $query = $campaign->source_config['topic_query'] ?: $campaign->name;
        $depth = $campaign->ai_config['research_depth'] ?? 'balanced';
        $message = "Research fresh news opportunities for this campaign: {$query}. Research depth: {$depth}. Return 8-12 concrete story opportunities with title, summary, source URLs, dates, key facts, and why it matters. Focus on news value and current/fresh information.";
        $response = AIN_AI::perplexity_chat( array(
            array( 'role' => 'system', 'content' => 'You are a research desk. Find fresh news opportunities from reliable web sources. Be concise and source-aware.' ),
            array( 'role' => 'user', 'content' => $message ),
        ), array( 'timeout' => 100 ) );
        if ( is_wp_error( $response ) ) return $response;
        $content = $response['content'];
        $citations = $response['citations'] ?? array();
        return array(
            array(
                'source_id'     => 'perplexity_' . md5( $query . current_time( 'Y-m-d-H' ) ),
                'source_name'   => 'Perplexity Research',
                'title'         => 'Research Pack: ' . $query,
                'description'   => wp_trim_words( wp_strip_all_tags( $content ), 120 ),
                'url'           => home_url( '/' ),
                'published'     => current_time( 'c' ),
                'research_pack' => array( 'query' => $query, 'summary' => $content, 'citations' => $citations ),
                'raw'           => $response['raw'] ?? array(),
            ),
        );
    }

    public static function from_press_release( $campaign ) {
        $urls = ain_array_from_lines( $campaign->source_config['press_release_urls'] ?? '' );
        if ( empty( $urls ) ) return new WP_Error( 'no_pr_urls', 'No press release sources configured.' );
        $feed_like = array();
        $page_like = array();
        foreach ( $urls as $url ) {
            if ( preg_match( '/(rss|feed|xml)(\/|$|\?)/i', $url ) ) $feed_like[] = $url;
            else $page_like[] = $url;
        }
        $items = array();
        if ( ! empty( $feed_like ) ) {
            $rss_campaign = clone $campaign;
            $rss_campaign->source_config['rss_feeds'] = implode( "\n", $feed_like );
            $rss_items = self::from_rss( $rss_campaign );
            if ( ! is_wp_error( $rss_items ) ) {
                foreach ( $rss_items as $item ) {
                    $item['source_id'] = 'pr_' . md5( $item['url'] );
                    $item['source_mode'] = 'press_release';
                    $item['description'] = '[Press release source] ' . $item['description'];
                    $items[] = $item;
                }
            }
        }
        if ( ! empty( $page_like ) ) {
            $fc = self::from_firecrawl_urls( $page_like, $campaign, 'press_release' );
            if ( ! is_wp_error( $fc ) ) $items = array_merge( $items, $fc );
            elseif ( empty( $items ) ) return $fc;
        }
        return $items;
    }

    public static function from_youtube( $campaign ) {
        $settings = ain_get_settings();
        if ( empty( $settings['youtube_api_key'] ) ) return new WP_Error( 'missing_youtube', 'YouTube API key is missing.' );
        $query = $campaign->source_config['youtube_query'] ?: $campaign->source_config['topic_query'];
        if ( ! $query ) return new WP_Error( 'missing_youtube_query', 'YouTube query is missing.' );
        $url = add_query_arg( array(
            'part'       => 'snippet',
            'q'          => $query,
            'type'       => 'video',
            'order'      => 'date',
            'maxResults' => 20,
            'key'        => $settings['youtube_api_key'],
        ), 'https://www.googleapis.com/youtube/v3/search' );
        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $response ) ) return $response;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $items = array();
        foreach ( $body['items'] ?? array() as $video ) {
            $video_id = $video['id']['videoId'] ?? '';
            if ( ! $video_id ) continue;
            $snippet = $video['snippet'] ?? array();
            $items[] = array(
                'source_id'   => 'yt_' . $video_id,
                'source_name' => $snippet['channelTitle'] ?? 'YouTube',
                'title'       => $snippet['title'] ?? '',
                'description' => $snippet['description'] ?? '',
                'url'         => 'https://www.youtube.com/watch?v=' . $video_id,
                'published'   => $snippet['publishedAt'] ?? '',
                'image'       => $snippet['thumbnails']['high']['url'] ?? ( $snippet['thumbnails']['default']['url'] ?? '' ),
                'video_id'    => $video_id,
                'raw'         => $video,
            );
        }
        return $items;
    }

    public static function from_manual( $campaign ) {
        $urls = ain_array_from_lines( $campaign->source_config['manual_urls'] ?? '' );
        if ( empty( $urls ) ) return new WP_Error( 'no_manual_urls', 'No manual URLs configured.' );
        $items = array();
        foreach ( $urls as $url ) {
            $title = $url;
            $description = '';
            $response = wp_remote_get( $url, array( 'timeout' => 25, 'redirection' => 4 ) );
            if ( ! is_wp_error( $response ) ) {
                $html = wp_remote_retrieve_body( $response );
                if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
                    $title = html_entity_decode( wp_strip_all_tags( $m[1] ) );
                }
                if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)/i', $html, $m ) ) {
                    $description = html_entity_decode( $m[1] );
                }
            }
            $items[] = array(
                'source_id'   => 'manual_' . md5( $url ),
                'source_name' => ain_safe_url_host( $url ),
                'title'       => $title,
                'description' => $description,
                'url'         => esc_url_raw( $url ),
                'published'   => current_time( 'c' ),
            );
        }
        return $items;
    }


    public static function from_x_twitter( $campaign ) {
        $settings = ain_get_settings();
        $api_key = trim( (string) ( $settings['twitterapi_io_api_key'] ?? '' ) );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_twitterapi_io', 'TwitterAPI.io API key is missing. Add it in AI Newsroom → Settings → API Keys.' );
        }

        $handles = ain_array_from_lines( $campaign->source_config['x_handles'] ?? '' );
        $user_ids = ain_array_from_lines( $campaign->source_config['x_user_ids'] ?? '' );
        if ( empty( $handles ) && empty( $user_ids ) ) {
            return new WP_Error( 'missing_x_accounts', 'Add at least one X/Twitter handle or user ID.' );
        }

        $include_replies = ! empty( $campaign->source_config['x_include_replies'] );
        $include_retweets = ! empty( $campaign->source_config['x_include_retweets'] );
        $include_quotes = ! array_key_exists( 'x_include_quotes', $campaign->source_config ) || ! empty( $campaign->source_config['x_include_quotes'] );
        $max_per_account = max( 1, min( 20, (int) ( $campaign->source_config['x_max_per_account'] ?? 10 ) ) );
        $min_likes = max( 0, (int) ( $campaign->source_config['x_min_likes'] ?? 0 ) );
        $min_reposts = max( 0, (int) ( $campaign->source_config['x_min_reposts'] ?? 0 ) );
        $min_replies = max( 0, (int) ( $campaign->source_config['x_min_replies'] ?? 0 ) );
        $min_views = max( 0, (int) ( $campaign->source_config['x_min_views'] ?? 0 ) );

        $items = array();
        $requests = array();
        $seen_requests = array();

        foreach ( $handles as $handle ) {
            $normalized = self::x_normalize_handle_input( $handle );
            if ( ! $normalized ) continue;
            $key = 'userName:' . strtolower( $normalized );
            if ( isset( $seen_requests[ $key ] ) ) continue;
            $seen_requests[ $key ] = true;
            $requests[] = array( 'type' => 'userName', 'value' => $normalized, 'label' => '@' . $normalized );
        }
        foreach ( $user_ids as $user_id ) {
            $user_id = preg_replace( '/[^0-9]/', '', (string) $user_id );
            if ( ! $user_id ) continue;
            $key = 'userId:' . $user_id;
            if ( isset( $seen_requests[ $key ] ) ) continue;
            $seen_requests[ $key ] = true;
            $requests[] = array( 'type' => 'userId', 'value' => $user_id, 'label' => $user_id );
        }

        if ( empty( $requests ) ) {
            return new WP_Error( 'missing_x_accounts', 'No valid X/Twitter handles or user IDs were found. Use @handle, handle, x.com/handle, or numeric user IDs.' );
        }

        $requests = array_slice( $requests, 0, 25 );
        $totals = array(
            'accounts' => count( $requests ),
            'api_tweets' => 0,
            'kept' => 0,
            'skipped_reply' => 0,
            'skipped_retweet' => 0,
            'skipped_quote' => 0,
            'skipped_engagement' => 0,
            'skipped_empty' => 0,
            'fallback_user_id_used' => 0,
        );

        foreach ( $requests as $request ) {
            $fetch = self::x_fetch_last_tweets( $api_key, $request, $include_replies, $campaign );
            if ( is_wp_error( $fetch ) ) {
                ain_log( 'warning', 'TwitterAPI.io request failed.', array( 'account' => $request['label'] ?? $request['value'], 'error' => $fetch->get_error_message() ), $campaign->id );
                continue;
            }

            $tweets = $fetch['tweets'];

            // Some usernames can fail or return empty on third-party APIs even when the account exists.
            // If that happens, resolve the username to a user ID once and retry with userId, which TwitterAPI.io recommends as more stable.
            if ( empty( $tweets ) && 'userName' === $request['type'] ) {
                $resolved_id = self::x_resolve_user_id( $api_key, $request['value'], $campaign );
                if ( $resolved_id ) {
                    $fallback_request = array( 'type' => 'userId', 'value' => $resolved_id, 'label' => '@' . $request['value'] . ' / ' . $resolved_id );
                    $fallback_fetch = self::x_fetch_last_tweets( $api_key, $fallback_request, $include_replies, $campaign );
                    if ( ! is_wp_error( $fallback_fetch ) && ! empty( $fallback_fetch['tweets'] ) ) {
                        $tweets = $fallback_fetch['tweets'];
                        $totals['fallback_user_id_used']++;
                    }
                }
            }

            $totals['api_tweets'] += count( $tweets );
            $kept_for_account = 0;

            foreach ( $tweets as $tweet ) {
                if ( ! is_array( $tweet ) ) continue;
                if ( $kept_for_account >= $max_per_account ) break;

                $filter = self::x_tweet_filter_reason( $tweet, $include_replies, $include_retweets, $include_quotes, $min_likes, $min_reposts, $min_replies, $min_views );
                if ( 'keep' !== $filter ) {
                    if ( isset( $totals[ 'skipped_' . $filter ] ) ) {
                        $totals[ 'skipped_' . $filter ]++;
                    }
                    continue;
                }

                $id = sanitize_text_field( $tweet['id'] ?? '' );
                if ( ! $id ) { $totals['skipped_empty']++; continue; }

                $author = is_array( $tweet['author'] ?? null ) ? $tweet['author'] : array();
                $username = sanitize_text_field( $author['userName'] ?? ( 'userName' === $request['type'] ? $request['value'] : '' ) );
                $username = ltrim( $username, '@' );
                $display_name = sanitize_text_field( $author['name'] ?? ( $username ? '@' . $username : 'X' ) );
                $tweet_url = esc_url_raw( $tweet['url'] ?? ( $username ? 'https://twitter.com/' . rawurlencode( $username ) . '/status/' . rawurlencode( $id ) : 'https://twitter.com/i/web/status/' . rawurlencode( $id ) ) );
                $text = trim( wp_strip_all_tags( html_entity_decode( (string) ( $tweet['text'] ?? '' ) ) ) );
                if ( '' === $text ) { $totals['skipped_empty']++; continue; }

                $metrics = array(
                    'likes'   => self::x_metric( $tweet, array( 'likeCount', 'favorite_count', 'favoriteCount' ) ),
                    'reposts' => self::x_metric( $tweet, array( 'retweetCount', 'retweet_count', 'repostCount' ) ),
                    'replies' => self::x_metric( $tweet, array( 'replyCount', 'reply_count' ) ),
                    'quotes'  => self::x_metric( $tweet, array( 'quoteCount', 'quote_count' ) ),
                    'views'   => self::x_metric( $tweet, array( 'viewCount', 'view_count', 'views' ) ),
                );
                $post_type = 'original_post';
                if ( self::x_nested_tweet_present( $tweet['quoted_tweet'] ?? null ) ) $post_type = 'quote_post';
                if ( ! empty( $tweet['isReply'] ) || ! empty( $tweet['inReplyToId'] ) ) $post_type = 'reply';
                if ( self::x_nested_tweet_present( $tweet['retweeted_tweet'] ?? null ) ) $post_type = 'retweet';

                $tweet_image = self::x_extract_primary_image_url( $tweet );

                $items[] = array(
                    'source_id'   => 'x_' . $id,
                    'source_name' => 'X / @' . $username,
                    'title'       => '@' . $username . ': ' . wp_trim_words( $text, 18, '…' ),
                    'description' => self::x_tweet_description( $text, $metrics, $post_type ),
                    'url'         => $tweet_url,
                    'published'   => sanitize_text_field( $tweet['createdAt'] ?? '' ),
                    'image'       => $tweet_image,
                    'x_tweet_image' => $tweet_image,
                    'x_author_profile_image' => esc_url_raw( $author['profilePicture'] ?? '' ),
                    'x_tweet_id'  => $id,
                    'x_author_username' => $username,
                    'x_author_name' => $display_name,
                    'x_post_type' => $post_type,
                    'x_metrics'   => $metrics,
                    'raw'         => $tweet,
                );
                $kept_for_account++;
                $totals['kept']++;
            }
        }

        ain_log( 'info', 'X/Twitter source fetch complete.', $totals, $campaign->id );

        return $items;
    }


    private static function x_extract_primary_image_url( $tweet ) {
        $candidates = array();

        self::x_collect_media_image_candidates( $candidates, $tweet['media'] ?? null );
        self::x_collect_media_image_candidates( $candidates, $tweet['photos'] ?? null );
        self::x_collect_media_image_candidates( $candidates, $tweet['entities']['media'] ?? null );
        self::x_collect_media_image_candidates( $candidates, $tweet['extended_entities']['media'] ?? null );
        self::x_collect_media_image_candidates( $candidates, $tweet['extendedEntities']['media'] ?? null );
        self::x_collect_media_image_candidates( $candidates, $tweet['attachments']['media'] ?? null );

        if ( ! empty( $tweet['quoted_tweet'] ) && is_array( $tweet['quoted_tweet'] ) ) {
            self::x_collect_media_image_candidates( $candidates, $tweet['quoted_tweet']['media'] ?? null );
            self::x_collect_media_image_candidates( $candidates, $tweet['quoted_tweet']['photos'] ?? null );
            self::x_collect_media_image_candidates( $candidates, $tweet['quoted_tweet']['entities']['media'] ?? null );
            self::x_collect_media_image_candidates( $candidates, $tweet['quoted_tweet']['extended_entities']['media'] ?? null );
            self::x_collect_media_image_candidates( $candidates, $tweet['quoted_tweet']['extendedEntities']['media'] ?? null );
        }

        foreach ( $candidates as $url ) {
            if ( $url ) return esc_url_raw( $url );
        }
        return '';
    }

    private static function x_collect_media_image_candidates( &$candidates, $value ) {
        if ( empty( $value ) ) return;

        if ( is_string( $value ) ) {
            if ( preg_match( '#^https?://#i', $value ) ) {
                $candidates[] = $value;
            }
            return;
        }

        if ( is_array( $value ) ) {
            $is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
            if ( $is_assoc ) {
                $type = strtolower( (string) ( $value['type'] ?? '' ) );
                if ( $type && false !== strpos( $type, 'video' ) && empty( $value['media_url'] ) && empty( $value['media_url_https'] ) && empty( $value['url'] ) ) {
                    return;
                }
                foreach ( array( 'media_url_https', 'media_url', 'url', 'image', 'imageUrl', 'image_url', 'thumbnail_url', 'thumbnailUrl', 'preview_image_url', 'previewImageUrl' ) as $key ) {
                    if ( ! empty( $value[ $key ] ) && is_string( $value[ $key ] ) && preg_match( '#^https?://#i', $value[ $key ] ) ) {
                        $candidates[] = $value[ $key ];
                    }
                }
                foreach ( array( 'src', 'sizes', 'variants' ) as $nested_key ) {
                    if ( isset( $value[ $nested_key ] ) ) {
                        self::x_collect_media_image_candidates( $candidates, $value[ $nested_key ] );
                    }
                }
            } else {
                foreach ( $value as $item ) {
                    self::x_collect_media_image_candidates( $candidates, $item );
                }
            }
        }
    }

    private static function x_normalize_handle_input( $handle ) {
        $handle = trim( (string) $handle );
        if ( '' === $handle ) return '';
        if ( preg_match( '#(?:x\.com|twitter\.com)/(?:@)?([A-Za-z0-9_]{1,15})#i', $handle, $m ) ) {
            $handle = $m[1];
        }
        $handle = ltrim( $handle, '@' );
        $handle = preg_replace( '/[^A-Za-z0-9_]/', '', $handle );
        if ( ! preg_match( '/^[A-Za-z0-9_]{1,15}$/', $handle ) ) return '';
        return $handle;
    }

    private static function x_fetch_last_tweets( $api_key, $request, $include_replies, $campaign ) {
        $args = array(
            'includeReplies' => $include_replies ? 'true' : 'false',
            'cursor' => '',
        );
        $args[ $request['type'] ] = $request['value'];
        $url = add_query_arg( $args, 'https://api.twitterapi.io/twitter/user/last_tweets' );
        $response = wp_remote_get( $url, array(
            'timeout' => 30,
            'headers' => array( 'X-API-Key' => $api_key ),
        ) );
        if ( is_wp_error( $response ) ) return $response;

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw_body, true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || ( isset( $body['status'] ) && 'error' === $body['status'] ) ) {
            $message = is_array( $body ) ? ( $body['message'] ?? ( $body['msg'] ?? 'TwitterAPI.io request failed.' ) ) : 'TwitterAPI.io returned invalid JSON.';
            return new WP_Error( 'twitterapi_io_error', $message );
        }

        return array(
            'tweets' => self::x_extract_tweets_from_response( $body ),
            'body' => $body,
        );
    }

    private static function x_extract_tweets_from_response( $body ) {
        if ( ! is_array( $body ) ) return array();
        if ( isset( $body['tweets'] ) && is_array( $body['tweets'] ) ) return $body['tweets'];
        if ( isset( $body['data']['tweets'] ) && is_array( $body['data']['tweets'] ) ) return $body['data']['tweets'];
        if ( isset( $body['data'] ) && is_array( $body['data'] ) && isset( $body['data'][0] ) ) return $body['data'];
        if ( isset( $body['results'] ) && is_array( $body['results'] ) ) return $body['results'];
        return array();
    }

    private static function x_resolve_user_id( $api_key, $username, $campaign ) {
        $username = self::x_normalize_handle_input( $username );
        if ( ! $username ) return '';
        $url = add_query_arg( array( 'userName' => $username ), 'https://api.twitterapi.io/twitter/user/info' );
        $response = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array( 'X-API-Key' => $api_key ),
        ) );
        if ( is_wp_error( $response ) ) return '';
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || ( isset( $body['status'] ) && 'error' === $body['status'] ) ) return '';
        $data = is_array( $body['data'] ?? null ) ? $body['data'] : $body;
        return sanitize_text_field( $data['id'] ?? '' );
    }

    private static function x_metric( $tweet, $keys ) {
        foreach ( $keys as $key ) {
            if ( isset( $tweet[ $key ] ) ) return max( 0, (int) $tweet[ $key ] );
        }
        return 0;
    }

    private static function x_tweet_filter_reason( $tweet, $include_replies, $include_retweets, $include_quotes, $min_likes, $min_reposts, $min_replies, $min_views ) {
        if ( ! $include_replies && ( ! empty( $tweet['isReply'] ) || ! empty( $tweet['inReplyToId'] ) ) ) return 'reply';
        if ( ! $include_retweets && self::x_nested_tweet_present( $tweet['retweeted_tweet'] ?? null ) ) return 'retweet';
        if ( ! $include_quotes && self::x_nested_tweet_present( $tweet['quoted_tweet'] ?? null ) ) return 'quote';
        if ( $min_likes && self::x_metric( $tweet, array( 'likeCount', 'favorite_count', 'favoriteCount' ) ) < $min_likes ) return 'engagement';
        if ( $min_reposts && self::x_metric( $tweet, array( 'retweetCount', 'retweet_count', 'repostCount' ) ) < $min_reposts ) return 'engagement';
        if ( $min_replies && self::x_metric( $tweet, array( 'replyCount', 'reply_count' ) ) < $min_replies ) return 'engagement';
        if ( $min_views && self::x_metric( $tweet, array( 'viewCount', 'view_count', 'views' ) ) < $min_views ) return 'engagement';
        return 'keep';
    }

    private static function x_nested_tweet_present( $value ) {
        if ( empty( $value ) ) return false;
        if ( is_array( $value ) ) return ! empty( $value['id'] ) || ! empty( $value['text'] ) || ! empty( $value['url'] );
        if ( is_object( $value ) ) return true;
        if ( is_string( $value ) ) {
            $v = strtolower( trim( $value ) );
            return '' !== $v && 'null' !== $v && 'false' !== $v && '<unknown>' !== $v;
        }
        return true;
    }

    private static function x_tweet_allowed_by_filters( $tweet, $include_replies, $include_retweets, $include_quotes, $min_likes, $min_reposts, $min_replies, $min_views ) {
        if ( ! $include_replies && ( ! empty( $tweet['isReply'] ) || ! empty( $tweet['inReplyToId'] ) ) ) return false;
        if ( ! $include_retweets && self::x_nested_tweet_present( $tweet['retweeted_tweet'] ?? null ) ) return false;
        if ( ! $include_quotes && self::x_nested_tweet_present( $tweet['quoted_tweet'] ?? null ) ) return false;
        if ( $min_likes && (int) ( $tweet['likeCount'] ?? 0 ) < $min_likes ) return false;
        if ( $min_reposts && (int) ( $tweet['retweetCount'] ?? 0 ) < $min_reposts ) return false;
        if ( $min_replies && (int) ( $tweet['replyCount'] ?? 0 ) < $min_replies ) return false;
        if ( $min_views && (int) ( $tweet['viewCount'] ?? 0 ) < $min_views ) return false;
        return true;
    }

    private static function x_tweet_description( $text, $metrics, $post_type ) {
        $parts = array();
        $parts[] = 'X/Twitter ' . str_replace( '_', ' ', $post_type ) . ': ' . $text;
        $parts[] = 'Engagement: ' . number_format_i18n( (int) ( $metrics['likes'] ?? 0 ) ) . ' likes, ' . number_format_i18n( (int) ( $metrics['reposts'] ?? 0 ) ) . ' reposts, ' . number_format_i18n( (int) ( $metrics['replies'] ?? 0 ) ) . ' replies, ' . number_format_i18n( (int) ( $metrics['views'] ?? 0 ) ) . ' views.';
        return implode( ' ', $parts );
    }

    private static function absolute_url( $url, $base ) {
        if ( preg_match( '#^https?://#i', $url ) ) return esc_url_raw( $url );
        $parts = wp_parse_url( $base );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return esc_url_raw( $url );
        if ( 0 === strpos( $url, '//' ) ) return esc_url_raw( $parts['scheme'] . ':' . $url );
        if ( 0 === strpos( $url, '/' ) ) return esc_url_raw( $parts['scheme'] . '://' . $parts['host'] . $url );
        $path = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';
        return esc_url_raw( $parts['scheme'] . '://' . $parts['host'] . $path . $url );
    }
}
