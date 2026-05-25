<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIN_Writer {
    public static function write_due_items() {
        $campaigns = AIN_Campaigns::all( array( 'status' => 'active' ) );
        $written = array();
        foreach ( $campaigns as $campaign ) {
            if ( empty( $campaign->schedule_config['run_writer'] ) ) continue;
            $max = max( 0, (int) ( $campaign->publishing_config['max_posts_per_run'] ?? 1 ) );
            for ( $i = 0; $i < $max; $i++ ) {
                $result = self::write_next( $campaign->id );
                if ( is_wp_error( $result ) ) break;
                $written[] = $result;
            }
        }
        return $written;
    }

    public static function write_next( $campaign_id = 0, $specific_id = 0 ) {
        $item = AIN_DB::next_writable_item( $campaign_id, $specific_id );
        if ( ! $item ) return new WP_Error( 'empty_queue', 'No writable queue item found.' );
        return self::write_item( $item );
    }

    public static function write_item( $item ) {
        $campaign = AIN_Campaigns::get( $item->campaign_id );
        if ( ! $campaign ) return new WP_Error( 'missing_campaign', 'Campaign not found for queue item.' );

        AIN_DB::update_item( $item->id, array( 'status' => 'writing', 'error_message' => '' ) );
        $raw = ain_decode_json_field( $item->raw_payload );
        $source_contexts = self::source_contexts_for_item( $item, $raw );

        $research_pack = ain_decode_json_field( $item->research_pack );
        if ( ! empty( $campaign->ai_config['enable_research_pack'] ) ) {
            $built = AIN_AI::build_research_pack( $item, $campaign, $source_contexts );
            if ( is_wp_error( $built ) ) {
                ain_log( 'warning', 'Research pack builder failed; writer will use source contexts only.', array( 'error' => $built->get_error_message() ), $campaign->id, $item->id );
                $research_pack['research_error'] = $built->get_error_message();
            } else {
                $research_pack = $built;
                AIN_DB::update_item( $item->id, array( 'research_pack' => $research_pack ) );
            }
        }

        $author_id = self::resolve_author( $item, $campaign );
        $category_id = self::resolve_category( $item, $campaign );
        $author = get_user_by( 'id', $author_id );
        $category = get_term( $category_id, 'category' );
        $internal_links = self::find_internal_links( $item->suggested_title ?: $item->source_title, $category_id, $campaign );
        $youtube_embed = self::youtube_embed_for_item( $item, $raw, $campaign );
        $source_links = self::source_links_for_prompt( $research_pack, $raw, $item );
        $settings = ain_get_settings();

        $prompt_data = array(
            'campaign' => array(
                'name' => $campaign->name,
                'type' => $campaign->type,
                'tone' => $campaign->ai_config['tone'],
                'research_depth' => $campaign->ai_config['research_depth'],
                'story_strategy' => $campaign->ai_config['story_strategy'] ?? 'smart',
            ),
            'story' => array(
                'working_label' => $item->suggested_title,
                'source_title' => $item->source_title,
                'source_url' => $item->source_url,
                'excerpt' => $item->source_excerpt,
                'raw_payload' => $raw,
            ),
            'headline_guidance' => array(
                'story_desk_working_label_is_not_final_title' => true,
                'research_recommended_headline' => $research_pack['recommended_headline'] ?? '',
                'research_headline_options' => $research_pack['headline_options'] ?? array(),
                'strongest_news_peg' => $research_pack['strongest_news_peg'] ?? '',
                'headline_rationale' => $research_pack['headline_rationale'] ?? '',
                'title_direction' => $research_pack['story_desk_assignment']['title_direction'] ?? '',
            ),
            'source_contexts' => $source_contexts,
            'verified_source_links_for_natural_attribution' => $source_links,
            'research_pack' => $research_pack,
            'editorial_assignment' => $item->ai_summary,
            'target_word_count' => (int) ( $campaign->publishing_config['words_target'] ?? 700 ),
            'author' => array(
                'id' => $author_id,
                'name' => $author ? $author->display_name : '',
                'beats' => ain_author_beats( $author_id ),
                'tone' => ain_author_tone( $author_id ),
            ),
            'category' => array(
                'id' => $category_id,
                'name' => $category && ! is_wp_error( $category ) ? $category->name : '',
                'rules' => ain_category_rules( $category_id ),
            ),
            'internal_link_candidates' => $internal_links,
            'youtube_embed_allowed' => ! empty( $youtube_embed ),
            'press_release_rules' => 'press_release' === $campaign->type ? AIN_AI::mode_instructions( 'press_release' ) : '',
            'media_rules' => $campaign->media_config,
            'social_required' => ! empty( $campaign->social_config['generate_social'] ),
            'required_output' => array(
                'final_title' => 'final article headline, preferably based on research_recommended_headline and strongest_news_peg; do not reuse the story desk working label unless it is already the strongest verified headline',
                'slug' => 'short-url-slug',
                'excerpt' => 'one or two sentence excerpt',
                'seo_title' => 'SEO title',
                'meta_description' => '150-160 character meta description',
                'focus_keyword' => 'main keyword',
                'tags' => array( 'tag 1', 'tag 2' ),
                'content' => 'Full HTML professional news article in inverted-pyramid style. Mostly use p tags. Use h2/h3 only for concrete factual sections, not generic explainers. Natural nofollow source links must be embedded in the relevant attribution sentence, not dumped at the end. Do not fabricate facts.',
                'source_attribution' => 'short attribution note or empty string',
                'inline_media_query' => 'short free image search query if useful',
                'image_prompt' => 'featured image prompt if AI image is needed',
                'chart' => array( 'title' => '', 'rows' => array( array( 'label' => '', 'value' => 0 ) ) ),
                'social' => array( 'facebook' => '', 'x' => '', 'linkedin' => '', 'telegram' => '', 'push_title' => '', 'push_body' => '' ),
                'value_added' => array( 'original_angle' => '', 'context_added' => '', 'reader_question_answered' => '', 'why_this_matters' => '' ),
                'quality_score' => '0-100',
                'quality_notes' => 'brief notes on article value and risk',
            ),
        );

        $current_date = date( 'l, F j, Y', current_time( 'timestamp' ) );
        $system = "CRITICAL CONTEXT: Today is {$current_date}. Treat all reporting relative to this date.\n\n"
            . trim( $campaign->ai_config['writer_prompt'] ?: $settings['writer_prompt'] ) . "\n\n"
            . "Critical professional newsroom rules:\n"
            . "1. SYNTHESIZE, DO NOT SUMMARIZE: Write like a Reuters or AP reporter. State verified facts directly as your own reporting. Do not mechanically write 'According to Source A' in every sentence.\n"
            . "2. SMART ATTRIBUTION: Only use attribution for quotes, specific claims, exclusive scoops, or uncertain allegations. When you do attribute, hyperlink the text naturally (e.g., '<a href=...>court filings show</a>' or '<a href=...>police said</a>'). Never add a final source list or 'Sources:' section.\n"
            . "3. INVERTED PYRAMID: The lede must be 35 words or fewer, answering who/what/when/where. Put the nut graph in paragraph 2 or 3.\n"
            . "4. Use short paragraphs (1 to 3 sentences). Prefer active voice and concrete nouns. Avoid adjectives that editorialize.\n"
            . "5. NO TEMPLATES: Do not use h2/h3 headings unless absolutely necessary for long, complex reports. Never use formulaic headings like 'Why it matters', 'Background', or 'Takeaways'.\n"
            . "6. Do not fabricate quotes, data, names, dates, or source links.\n"
            . "7. Return ONLY valid JSON, no markdown fences.";

        $response = AIN_AI::openrouter_chat(
            array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => wp_json_encode( $prompt_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            ),
            array(
                'model' => $settings['openrouter_writer_model'] ?: $settings['openrouter_text_model'],
                'json' => true,
                'temperature' => (float) ( $campaign->ai_config['temperature'] ?? 0.35 ),
                'timeout' => 180,
            )
        );
        if ( is_wp_error( $response ) ) {
            AIN_DB::update_item( $item->id, array( 'status' => 'failed', 'error_message' => $response->get_error_message() ) );
            ain_log( 'error', 'Writer failed.', array( 'error' => $response->get_error_message() ), $campaign->id, $item->id );
            return $response;
        }

        $draft = ain_json_decode_loose( $response['content'] );
        if ( ! is_array( $draft ) || empty( $draft['content'] ) || empty( $draft['final_title'] ) ) {
            AIN_DB::update_item( $item->id, array( 'status' => 'failed', 'error_message' => 'AI writer returned invalid JSON.' ) );
            return new WP_Error( 'bad_writer_json', 'AI writer returned invalid JSON.' );
        }

        // No separate Quality Gate step. Use only the writer's own score/notes and campaign publish rules.
        $quality = max( 0, min( 100, (int) ( $draft['quality_score'] ?? $item->quality_score ) ) );

        $post_status = self::post_status_from_campaign( $campaign, $quality );

        $content = (string) $draft['content'];
        $content = self::post_process_news_article( $content, $source_links );
        if ( $youtube_embed && false === strpos( $content, 'youtube.com/embed' ) ) {
            $content = $youtube_embed . "\n" . $content;
        }
        $content = self::ensure_external_source_link_rels( $content );
        if ( ! empty( $campaign->media_config['insert_inline_media'] ) && ! empty( $draft['chart']['rows'] ) && is_array( $draft['chart']['rows'] ) ) {
            $chart_html = AIN_Media::generate_chart_svg( $draft['chart']['title'] ?? 'Key figures', $draft['chart']['rows'] );
            if ( $chart_html ) $content .= "\n" . $chart_html;
        }

        $postarr = array(
            'post_title'    => sanitize_text_field( $draft['final_title'] ),
            'post_name'     => sanitize_title( $draft['slug'] ?? $draft['final_title'] ),
            'post_excerpt'  => sanitize_textarea_field( $draft['excerpt'] ?? '' ),
            'post_content'  => wp_kses( $content, ain_allowed_post_html() ),
            'post_status'   => $post_status,
            'post_author'   => $author_id,
            'post_category' => array( $category_id ),
        );
        $post_id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $post_id ) ) {
            AIN_DB::update_item( $item->id, array( 'status' => 'failed', 'error_message' => $post_id->get_error_message() ) );
            return $post_id;
        }

        self::apply_post_meta( $post_id, $draft, $item, $campaign, $research_pack, $raw );
        self::apply_tags( $post_id, $draft['tags'] ?? array() );
        self::maybe_featured_image( $post_id, $draft, $item, $campaign );

        $new_status = in_array( $post_status, array( 'publish', 'future' ), true ) ? 'published' : 'drafted';
        if ( $quality < (int) ( $campaign->publishing_config['min_quality_score'] ?? 75 ) ) {
            $new_status = 'needs_review';
        }
        AIN_DB::update_item( $item->id, array(
            'status'        => $new_status,
            'post_id'       => $post_id,
            'quality_score' => $quality,
            'error_message' => '',
        ) );

        if ( ! empty( $campaign->social_config['social_hook_action'] ) ) {
            do_action( sanitize_key( $campaign->social_config['social_hook_action'] ), $post_id, $draft, $item, $campaign );
        }

        ain_log( 'info', 'Article generated.', array( 'post_id' => $post_id, 'status' => $post_status, 'quality' => $quality, 'quality_source' => 'writer' ), $campaign->id, $item->id );
        return array( 'post_id' => $post_id, 'message' => 'Article created: ' . $draft['final_title'] );
    }

    private static function source_contexts_for_item( $item, $raw ) {
        $sources = array();
        if ( ! empty( $raw['sources'] ) && is_array( $raw['sources'] ) ) {
            $sources = $raw['sources'];
        } else {
            $sources[] = array(
                'source_id' => $item->source_id,
                'source_name' => $item->source_name,
                'title' => $item->source_title,
                'description' => $item->source_excerpt,
                'url' => $item->source_url,
                'image' => $raw['image'] ?? '',
            );
        }

        $contexts = array();
        foreach ( array_slice( $sources, 0, 6 ) as $source ) {
            $url = $source['url'] ?? '';
            $text = self::fetch_url_context( $url );
            $contexts[] = array(
                'source_id' => $source['source_id'] ?? '',
                'source_name' => $source['source_name'] ?? ain_safe_url_host( $url ),
                'title' => $source['title'] ?? '',
                'description' => $source['description'] ?? '',
                'url' => $url,
                'published' => $source['published'] ?? '',
                'image' => $source['image'] ?? '',
                'text' => $text,
            );
        }
        return $contexts;
    }

    public static function fetch_url_context( $url ) {
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) return '';
        if ( 0 === strpos( $url, home_url( '/' ) ) ) return '';
        $response = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 4, 'user-agent' => 'AI Newsroom/' . AIN_VERSION . '; ' . home_url( '/' ) ) );
        if ( is_wp_error( $response ) ) return '';
        $html = wp_remote_retrieve_body( $response );
        $html = preg_replace( '#<script[^>]*>.*?</script>#is', ' ', $html );
        $html = preg_replace( '#<style[^>]*>.*?</style>#is', ' ', $html );
        $html = preg_replace( '#<noscript[^>]*>.*?</noscript>#is', ' ', $html );
        $text = html_entity_decode( wp_strip_all_tags( $html ) );
        $text = preg_replace( '/\s+/', ' ', $text );
        return wp_trim_words( $text, 1800, '...' );
    }

    private static function youtube_embed_for_item( $item, $raw, $campaign ) {
        if ( 'youtube' !== $campaign->type && false === strpos( (string) $item->source_mode, 'youtube' ) ) return '';
        return AIN_Media::youtube_embed_from_item( $item );
    }

    private static function resolve_author( $item, $campaign ) {
        $pub = $campaign->publishing_config;
        if ( 'fixed' === ( $pub['author_mode'] ?? 'auto' ) && ! empty( $pub['default_author'] ) && 'auto' !== $pub['default_author'] ) {
            return max( 1, (int) $pub['default_author'] );
        }
        return $item->author_id ? (int) $item->author_id : max( 1, get_current_user_id() );
    }

    private static function resolve_category( $item, $campaign ) {
        $pub = $campaign->publishing_config;
        if ( 'fixed' === ( $pub['category_mode'] ?? 'auto' ) && ! empty( $pub['default_category'] ) && 'auto' !== $pub['default_category'] ) {
            return max( 1, (int) $pub['default_category'] );
        }
        return $item->category_id ? (int) $item->category_id : (int) get_option( 'default_category' );
    }

    private static function post_status_from_campaign( $campaign, $quality ) {
        if ( $quality < (int) ( $campaign->publishing_config['min_quality_score'] ?? 75 ) ) return 'draft';
        switch ( $campaign->publishing_config['publish_mode'] ?? 'draft' ) {
            case 'publish': return 'publish';
            case 'pending': return 'pending';
            case 'draft':
            default: return 'draft';
        }
    }

    private static function find_internal_links( $title, $category_id, $campaign ) {
        $settings = ain_get_settings();
        if ( empty( $settings['enable_internal_links'] ) ) return array();
        $max = max( 1, (int) $settings['max_internal_links'] );
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $max + 4,
            's' => wp_strip_all_tags( $title ),
            'orderby' => 'date',
            'order' => 'DESC',
        );
        if ( $category_id ) $args['cat'] = (int) $category_id;
        $q = new WP_Query( $args );
        $links = array();
        foreach ( $q->posts as $post ) {
            $links[] = array( 'title' => get_the_title( $post ), 'url' => get_permalink( $post ), 'date' => get_the_date( 'Y-m-d', $post ) );
        }
        wp_reset_postdata();
        return array_slice( $links, 0, $max );
    }

    private static function post_process_news_article( $content, $source_links = array() ) {
        $content = (string) $content;
        // Remove source dump boxes/lists that make articles look like AI research notes.
        $content = preg_replace( '#<div[^>]*class=["\'][^"\']*ain-source-box[^"\']*["\'][^>]*>.*?</div>#is', '', $content );
        $content = preg_replace( '#<h[23][^>]*>\s*(Sources?|Sources and context|Source material|References)\s*</h[23]>\s*(?:<ul[^>]*>.*?</ul>)?#is', '', $content );
        $content = preg_replace( '#<p[^>]*>\s*(?:<strong>)?\s*(Sources?|Source material|References)\s*:.*?</p>#is', '', $content );
        $content = preg_replace( '#<p[^>]*>\s*(?:Reporting for this article|This article was based on|Source material for this article|Sources used for this article|The reporting reviewed for this article).*?</p>#is', '', $content );
        // Strip AI-template section labels. The paragraphs remain and read more like a normal news article.
        $bad_headings = '(Why\s+it\s+matters|What\s+happens\s+next|What\s+remains\s+uncertain|What\s+remains\s+unclear|Context|Background|Key\s+takeaways|The\s+bottom\s+line)';
        $content = preg_replace( '#<h[23][^>]*>\s*' . $bad_headings . '\s*</h[23]>\s*#i', '', $content );
        $content = self::add_missing_natural_source_link( $content, $source_links );
        return trim( $content );
    }

    private static function add_missing_natural_source_link( $content, $source_links = array() ) {
        if ( empty( $source_links ) || ! is_array( $source_links ) ) return $content;
        if ( preg_match( '/<a\s+[^>]*href=["\']https?:\/\//i', $content ) ) return $content;
        $first = reset( $source_links );
        $url = esc_url_raw( $first['url'] ?? '' );
        if ( ! $url ) return $content;
        $publisher = sanitize_text_field( $first['publisher'] ?? '' );
        if ( ! $publisher ) $publisher = sanitize_text_field( ain_safe_url_host( $url ) );
        $candidates = array_filter( array_unique( array( $publisher, preg_replace( '/\..*$/', '', $publisher ) ) ) );
        foreach ( $candidates as $candidate ) {
            if ( strlen( $candidate ) < 3 ) continue;
            $pattern = '/\b(' . preg_quote( $candidate, '/' ) . ')\b(?![^<]*>)/i';
            $linked = preg_replace( $pattern, '<a href="' . esc_url( $url ) . '" rel="nofollow noopener" target="_blank">$1</a>', $content, 1, $count );
            if ( $count ) return $linked;
        }
        // Fallback: link a neutral attribution word near the top, instead of appending a source paragraph.
        $pattern = '/\b(Reporting|reported|according to|filings show|documents show)\b(?![^<]*>)/i';
        $linked = preg_replace( $pattern, '<a href="' . esc_url( $url ) . '" rel="nofollow noopener" target="_blank">$1</a>', $content, 1, $count );
        return $count ? $linked : $content;
    }

    private static function ensure_external_source_link_rels( $content ) {
        $home_host = strtolower( ain_safe_url_host( home_url( '/' ) ) );
        return preg_replace_callback( '/<a\s+([^>]*href=["\']https?:\/\/[^"\']+["\'][^>]*)>/i', function( $m ) use ( $home_host ) {
            $attrs = $m[1];
            if ( preg_match( '/href=["\']([^"\']+)["\']/i', $attrs, $hm ) ) {
                $host = strtolower( ain_safe_url_host( $hm[1] ) );
                if ( $host && $host !== $home_host ) {
                    if ( ! preg_match( '/\srel=/i', $attrs ) ) {
                        $attrs .= ' rel="nofollow noopener"';
                    } elseif ( ! preg_match( '/nofollow/i', $attrs ) ) {
                        $attrs = preg_replace( '/rel=["\']([^"\']*)["\']/i', 'rel="$1 nofollow noopener"', $attrs );
                    }
                    if ( ! preg_match( '/\starget=/i', $attrs ) ) {
                        $attrs .= ' target="_blank"';
                    }
                }
            }
            return '<a ' . $attrs . '>';
        }, (string) $content );
    }

    private static function source_links_for_prompt( $research_pack, $raw, $item ) {
        $sources = array();
        if ( ! empty( $research_pack['verified_sources'] ) && is_array( $research_pack['verified_sources'] ) ) {
            foreach ( $research_pack['verified_sources'] as $src ) {
                if ( ! empty( $src['url'] ) ) $sources[] = array( 'title' => $src['title'] ?? ( $src['publisher'] ?? ain_safe_url_host( $src['url'] ) ), 'url' => $src['url'] );
            }
        }
        if ( ! empty( $raw['sources'] ) && is_array( $raw['sources'] ) ) {
            foreach ( $raw['sources'] as $src ) {
                if ( ! empty( $src['url'] ) ) $sources[] = array( 'title' => $src['title'] ?? ain_safe_url_host( $src['url'] ), 'url' => $src['url'] );
            }
        }
        if ( empty( $sources ) && ! empty( $item->source_url ) ) {
            $sources[] = array( 'title' => $item->source_title ?: ain_safe_url_host( $item->source_url ), 'url' => $item->source_url );
        }
        return array_slice( self::unique_source_urls( $sources ), 0, 8 );
    }

    private static function unique_source_urls( $sources ) {
        $seen = array();
        $out = array();
        foreach ( $sources as $src ) {
            $url = esc_url_raw( $src['url'] ?? '' );
            if ( ! $url || isset( $seen[ $url ] ) ) continue;
            $seen[ $url ] = true;
            $out[] = array( 'title' => sanitize_text_field( $src['title'] ?? ain_safe_url_host( $url ) ), 'url' => $url );
        }
        return $out;
    }

    private static function apply_post_meta( $post_id, $draft, $item, $campaign, $research_pack = array(), $raw = array() ) {
        update_post_meta( $post_id, '_ain_campaign_id', (int) $campaign->id );
        update_post_meta( $post_id, '_ain_queue_id', (int) $item->id );
        update_post_meta( $post_id, '_ain_source_url', esc_url_raw( $item->source_url ) );
        update_post_meta( $post_id, '_ain_source_mode', sanitize_key( $item->source_mode ) );
        update_post_meta( $post_id, '_ain_quality_score', (int) ( $draft['quality_score'] ?? 0 ) );
        update_post_meta( $post_id, '_ain_quality_notes', sanitize_textarea_field( $draft['quality_notes'] ?? '' ) );
        update_post_meta( $post_id, '_ain_research_pack', wp_json_encode( $research_pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        update_post_meta( $post_id, '_ain_social_pack', wp_json_encode( $draft['social'] ?? array(), JSON_UNESCAPED_UNICODE ) );
        update_post_meta( $post_id, '_ain_value_added', wp_json_encode( $draft['value_added'] ?? ( $research_pack['value_added'] ?? array() ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        if ( ! empty( $raw['story_fingerprint'] ) ) update_post_meta( $post_id, '_ain_story_fingerprint', sanitize_text_field( $raw['story_fingerprint'] ) );
        if ( ! empty( $raw['sources'] ) ) update_post_meta( $post_id, '_ain_source_urls', wp_json_encode( wp_list_pluck( $raw['sources'], 'url' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        if ( ! empty( $draft['seo_title'] ) ) {
            update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $draft['seo_title'] ) );
            update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $draft['seo_title'] ) );
        }
        if ( ! empty( $draft['meta_description'] ) ) {
            update_post_meta( $post_id, 'rank_math_description', sanitize_textarea_field( $draft['meta_description'] ) );
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field( $draft['meta_description'] ) );
        }
        if ( ! empty( $draft['focus_keyword'] ) ) {
            update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( $draft['focus_keyword'] ) );
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $draft['focus_keyword'] ) );
        }
    }

    private static function apply_tags( $post_id, $tags ) {
        if ( empty( $tags ) || ! is_array( $tags ) ) return;
        $clean = array();
        foreach ( $tags as $tag ) {
            $tag = sanitize_text_field( $tag );
            if ( $tag ) $clean[] = $tag;
        }
        if ( $clean ) wp_set_post_tags( $post_id, $clean, false );
    }

    private static function maybe_featured_image( $post_id, $draft, $item, $campaign ) {
        if ( empty( $campaign->media_config['generate_images'] ) ) return;
        $raw = ain_decode_json_field( $item->raw_payload );
        $image_url = '';

        if ( ! empty( $campaign->media_config['use_source_image'] ) ) {
            if ( ! empty( $raw['primary_source']['image'] ) ) $image_url = $raw['primary_source']['image'];
            elseif ( ! empty( $raw['image'] ) ) $image_url = $raw['image'];
            elseif ( ! empty( $raw['sources'][0]['image'] ) ) $image_url = $raw['sources'][0]['image'];
        }
        if ( ! $image_url && ! empty( $campaign->media_config['use_pexels'] ) ) {
            $image_url = AIN_Media::pexels_image_url( $draft['inline_media_query'] ?? $draft['focus_keyword'] ?? $draft['final_title'] );
        }
        if ( ! $image_url && ! empty( $campaign->media_config['use_openrouter_image'] ) ) {
            $prompt = ( $draft['image_prompt'] ?? $item->image_prompt ) . ' ' . ( $campaign->media_config['image_style'] ?? '' );
            $image_url = AIN_Media::openrouter_image_url( $prompt, $campaign->media_config );
        }
        if ( $image_url ) {
            if ( 0 === strpos( $image_url, 'data:image/' ) ) {
                AIN_Media::sideload_data_image( $post_id, $image_url, $draft['final_title'] ?? '' );
            } else {
                AIN_Media::sideload_featured_image( $post_id, $image_url, $draft['final_title'] ?? '' );
            }
        }
    }
}
