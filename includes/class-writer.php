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
        try {
            return self::write_item_inner( $item );
        } catch ( Throwable $e ) {
            $item_id = is_object( $item ) && isset( $item->id ) ? (int) $item->id : 0;
            $campaign_id = is_object( $item ) && isset( $item->campaign_id ) ? (int) $item->campaign_id : 0;
            $message = 'Writer crashed: ' . $e->getMessage();

            if ( $item_id ) {
                AIN_DB::update_item( $item_id, array(
                    'status'        => 'failed',
                    'error_message' => $message,
                ) );
            }

            ain_log( 'error', 'Article writer crashed.', array(
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ), $campaign_id, $item_id );

            return new WP_Error( 'writer_crashed', $message );
        }
    }

    private static function write_item_inner( $item ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }
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
        $youtube_embed = self::youtube_embed_for_item( $item, $raw, $campaign );
        $source_links = self::source_links_for_prompt( $research_pack, $raw, $item );
        $settings = ain_get_settings();

        $article_input = self::article_draft_prompt_data( $item, $campaign, $raw, $research_pack, $source_contexts, $source_links, $author_id, $author, $category_id, $category, $youtube_embed );
        $draft = self::generate_article_draft( $article_input, $campaign, $settings, $item );
        if ( is_wp_error( $draft ) ) {
            AIN_DB::update_item( $item->id, array( 'status' => 'failed', 'error_message' => $draft->get_error_message() ) );
            ain_log( 'error', 'Article draft writer failed.', array( 'error' => $draft->get_error_message() ), $campaign->id, $item->id );
            return $draft;
        }

        $quality = max( 0, min( 100, (int) ( $draft['quality_score'] ?? $item->quality_score ) ) );
        $production_enabled = self::is_production_editor_enabled( $campaign, $settings );
        $internal_link_map = array();
        $production = self::fallback_production_pack( $draft, $item, $campaign, $research_pack, $category );
        if ( $production_enabled ) {
            $internal_link_map = self::build_internal_link_map( $draft, $item, $campaign, $research_pack, $category_id, $category, $settings );
            $generated_production = self::generate_production_pack( $draft, $item, $campaign, $research_pack, $source_links, $internal_link_map, $category, $settings );
            if ( is_wp_error( $generated_production ) ) {
                ain_log( 'warning', 'Production editor failed; using fallback SEO/media metadata.', array( 'error' => $generated_production->get_error_message() ), $campaign->id, $item->id );
            } else {
                $production = $generated_production;
            }
        } else {
            ain_log( 'info', 'Production editor disabled; using PHP fallback SEO/media metadata without second AI call.', array(), $campaign->id, $item->id );
        }

        $final = self::merge_article_and_production( $draft, $production );
        $final['fact_check'] = self::build_fact_check_data( $final['fact_check'] ?? array(), $research_pack, $source_links, $raw, $item, $campaign, $settings );
        if ( empty( $final['content'] ) || empty( $final['final_title'] ) ) {
            AIN_DB::update_item( $item->id, array( 'status' => 'failed', 'error_message' => 'AI writer returned invalid article data.' ) );
            return new WP_Error( 'bad_writer_json', 'AI writer returned invalid article data.' );
        }

        $post_status = self::post_status_from_campaign( $campaign, $quality );

        $content = (string) $final['content'];
        $content = self::apply_link_insertions( $content, $final['link_insertions'] ?? array(), self::allowed_production_link_urls( $source_links, $internal_link_map ), $settings );
        $content = self::post_process_news_article( $content, $source_links );
        if ( $youtube_embed && false === strpos( $content, 'youtube.com/embed' ) ) {
            $content = $youtube_embed . "\n" . $content;
        }
        $content = self::strip_external_links_from_content( $content );
        if ( ! empty( $campaign->media_config['insert_inline_media'] ) ) {
            if ( ! empty( $campaign->media_config['enable_smart_tables'] ) && ! empty( $final['table']['rows'] ) && is_array( $final['table']['rows'] ) ) {
                $table_html = AIN_Media::generate_table_html( $final['table'] );
                if ( $table_html ) $content .= "\n" . $table_html;
            }
            if ( ! empty( $campaign->media_config['enable_smart_charts'] ) && ! empty( $final['chart']['rows'] ) && is_array( $final['chart']['rows'] ) ) {
                $chart_html = AIN_Media::generate_chart_svg( $final['chart']['title'] ?? 'Key figures', $final['chart']['rows'] );
                if ( $chart_html ) $content .= "\n" . $chart_html;
            }
        }

        $fact_check_html = self::render_fact_check_box( $final['fact_check'] ?? array(), $campaign, $settings );
        if ( $fact_check_html ) {
            $content .= "\n" . $fact_check_html;
        }

        $postarr = array(
            'post_title'    => sanitize_text_field( $final['final_title'] ),
            'post_name'     => sanitize_title( $final['slug'] ?? $final['final_title'] ),
            'post_excerpt'  => sanitize_textarea_field( $final['excerpt'] ?? '' ),
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

        self::apply_post_meta( $post_id, $final, $item, $campaign, $research_pack, $raw );
        self::apply_tags( $post_id, $final['tags'] ?? array() );
        self::maybe_featured_image( $post_id, $final, $item, $campaign );

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

        ain_log( 'info', 'Article generated through two-step writer.', array( 'post_id' => $post_id, 'status' => $post_status, 'quality' => $quality, 'production_editor' => $production_enabled ? 'ai' : 'disabled_fallback' ), $campaign->id, $item->id );
        return array( 'post_id' => $post_id, 'message' => 'Article created: ' . $final['final_title'] );
    }

    private static function article_draft_prompt_data( $item, $campaign, $raw, $research_pack, $source_contexts, $source_links, $author_id, $author, $category_id, $category, $youtube_embed ) {
        return array(
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
            'youtube_embed_allowed' => ! empty( $youtube_embed ),
            'press_release_rules' => 'press_release' === $campaign->type ? AIN_AI::mode_instructions( 'press_release' ) : '',
            'required_output' => array(
                'final_title' => 'final article headline based on the strongest verified news peg; do not reuse the working label unless it is truly strongest',
                'slug' => 'short-url-slug',
                'excerpt' => 'one or two sentence excerpt',
                'content' => 'Full HTML professional news article. Mostly p tags. h2/h3 only when truly useful for a complex factual section. No SEO metadata, no tables, no charts, no social copy, no source list.',
                'value_added' => array( 'original_angle' => '', 'context_added' => '', 'reader_question_answered' => '', 'why_this_matters' => '' ),
                'quality_score' => '0-100 editorial publishability score, not an SEO score',
                'quality_notes' => 'brief notes on article value and factual risk',
            ),
        );
    }

    private static function is_production_editor_enabled( $campaign, $settings ) {
        $mode = isset( $campaign->ai_config['production_editor_mode'] ) ? sanitize_key( $campaign->ai_config['production_editor_mode'] ) : 'global';

        if ( 'enabled' === $mode ) {
            return true;
        }

        if ( 'disabled' === $mode ) {
            return false;
        }

        return ! empty( $settings['enable_production_editor'] );
    }

    private static function generate_article_draft( $prompt_data, $campaign, $settings, $item ) {
        $current_date = date( 'l, F j, Y', current_time( 'timestamp' ) );
        $system = "CRITICAL CONTEXT: Today is {$current_date}. Treat all reporting relative to this date.\n\n"
            . trim( $campaign->ai_config['writer_prompt'] ?: $settings['writer_prompt'] ) . "\n\n"
            . "ARTICLE DRAFT PASS ONLY:\n"
            . "1. Write the article itself. Do not create SEO title, meta description, focus keyword, tags, charts, tables, image prompts, or social copy.\n"
            . "2. Write like a senior wire-service reporter: synthesize verified facts directly, use attribution only where journalism requires it, and avoid mechanical source-by-source summaries.\n"
            . "3. The lede should usually be 35 words or fewer and answer who/what/when/where. Put the nut graph in paragraph 2 or 3.\n"
            . "4. Use short paragraphs, active voice, concrete nouns, neutral language, and natural human transitions.\n"
            . "5. Do not add a final source list, references block, or generic headings such as 'Why it matters', 'Background', 'Context', 'Key takeaways', or 'The bottom line'.\n"
            . "6. Do not create any hyperlinks or <a> tags in the article draft. Use plain-text attribution only when journalism requires it. Internal links are handled later by production.\n"
            . "7. Do not fabricate quotes, data, names, dates, motives, or allegations.\n"
            . "8. Return ONLY valid JSON, no markdown fences.";

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
        if ( is_wp_error( $response ) ) return $response;
        $draft = ain_json_decode_loose( $response['content'] );
        if ( ! is_array( $draft ) || empty( $draft['content'] ) || empty( $draft['final_title'] ) ) {
            return new WP_Error( 'bad_writer_json', 'AI article draft writer returned invalid JSON.' );
        }
        return $draft;
    }

    private static function generate_production_pack( $draft, $item, $campaign, $research_pack, $source_links, $internal_link_map, $category, $settings ) {
        $max_internal = max( 0, (int) ( $settings['max_internal_links'] ?? 3 ) );
        $payload = array(
            'article_draft' => array(
                'final_title' => $draft['final_title'] ?? '',
                'slug' => $draft['slug'] ?? '',
                'excerpt' => $draft['excerpt'] ?? '',
                'content' => $draft['content'] ?? '',
            ),
            'story_context' => array(
                'source_title' => $item->source_title,
                'editorial_assignment' => $item->ai_summary,
                'category' => $category && ! is_wp_error( $category ) ? $category->name : '',
                'suggested_internal_link_terms' => $research_pack['suggested_internal_link_terms'] ?? array(),
                'people_organizations' => $research_pack['people_organizations'] ?? array(),
                'locations' => $research_pack['locations'] ?? array(),
                'key_numbers' => $research_pack['key_numbers'] ?? array(),
            ),
            'source_material_for_fact_check' => array(
                'article_source_links' => $source_links,
                'research_verified_sources' => $research_pack['verified_sources'] ?? array(),
                'openrouter_web_sources' => $research_pack['openrouter_web_sources'] ?? array(),
                'web_search_enabled' => $research_pack['web_search_enabled'] ?? 0,
                'web_search_source_count' => $research_pack['web_search_source_count'] ?? 0,
                'key_facts' => $research_pack['key_facts'] ?? array(),
                'timeline' => $research_pack['timeline'] ?? array(),
                'uncertain_or_conflicting_claims' => $research_pack['uncertain_or_conflicting_claims'] ?? array(),
                'claims_not_to_make' => $research_pack['claims_not_to_make'] ?? array(),
            ),
            'internal_link_map' => $internal_link_map,
            'fact_check_settings' => array(
                'enabled' => self::is_fact_check_enabled( $campaign, $settings ),
                'title' => self::fact_check_title( $campaign, $settings ),
                'max_sources' => self::fact_check_max_sources( $campaign, $settings ),
                'show_verified_facts' => ! empty( $settings['fact_check_show_verified_facts'] ),
                'show_uncertain_claims' => ! empty( $settings['fact_check_show_uncertain_claims'] ),
            ),
            'production_limits' => array(
                'max_internal_links' => $max_internal,
                'max_tables' => ! empty( $campaign->media_config['insert_inline_media'] ) && ! empty( $campaign->media_config['enable_smart_tables'] ) ? 1 : 0,
                'max_charts' => ! empty( $campaign->media_config['insert_inline_media'] ) && ! empty( $campaign->media_config['enable_smart_charts'] ) ? 1 : 0,
            ),
            'required_output' => array(
                'seo_title' => 'Rank Math SEO title; no clickbait and no keyword stuffing',
                'meta_description' => '150-160 character Rank Math meta description',
                'focus_keyword' => 'one natural main phrase only',
                'tags' => array( '2-5 clean WordPress tags: people, companies, places, or concrete topics' ),
                'link_insertions' => array(
                    array(
                        'type' => 'internal',
                        'url' => 'must exactly match one supplied internal URL',
                        'anchor_text' => 'exact visible words already present in the article draft',
                        'reason' => 'why this internal link helps readers',
                    ),
                ),
                'source_attribution' => '',
                'inline_media_query' => 'short free image search query if useful',
                'image_prompt' => 'featured image prompt if AI image is needed',
                'table' => array( 'title' => '', 'headers' => array(), 'rows' => array(), 'reason' => '' ),
                'chart' => array( 'title' => '', 'rows' => array(), 'reason' => '' ),
                'value_added' => array( 'original_angle' => '', 'context_added' => '', 'reader_question_answered' => '', 'why_this_matters' => '' ),
                'fact_check' => array(
                    'summary' => 'one concise sentence describing how the article was checked; do not overclaim independent verification',
                    'verified_facts' => array(
                        array(
                            'claim' => 'specific factual claim checked against the supplied source material',
                            'status' => 'Verified / Source checked / Needs caution',
                            'evidence' => 'short note explaining what confirmed it',
                            'source_title' => 'source used, preferably document/organization/title',
                            'url' => 'optional source URL from supplied source material only',
                        ),
                    ),
                    'sources' => array(
                        array(
                            'title' => 'source title',
                            'publisher' => 'publisher or organization',
                            'url' => 'source URL from supplied source material only',
                            'why_used' => 'what this source was used to verify',
                            'source_type' => 'Original source / Research source / Background source',
                        ),
                    ),
                    'caution_notes' => array( 'short uncertainty, disputed claim, missing confirmation, or claim deliberately not used' ),
                ),
            ),
        );

        $production_prompt = trim( $campaign->ai_config['production_prompt'] ?? '' );
        if ( '' === $production_prompt ) {
            $production_prompt = trim( $settings['production_prompt'] ?? '' );
        }
        if ( '' === $production_prompt ) {
            $defaults = ain_default_settings();
            $production_prompt = $defaults['production_prompt'];
        }

        $system = $production_prompt . "\n\n"
            . "Hard production/output contract:\n"
            . "1. DO NOT rewrite, replace, summarize, expand, shorten, reorder, or stylistically edit the article body. Do not return a rewritten content field.\n"
            . "2. Your job is metadata plus instructions: SEO fields, tags, image metadata, optional table/chart data, a small internal-only link_insertions array, and the fact_check object.\n"
            . "3. Link insertion is permission-based and INTERNAL ONLY. The URL must exactly match a supplied internal URL. The anchor_text must already appear in the article draft. If no natural internal anchor exists, return no link.\n"
            . "4. Use at most {$max_internal} internal links. Return zero links if the available internal links do not fit naturally.\n"
            . "5. Never add, suggest, preserve, or request external source links inside the article body. Do not write source paragraphs, source lists, reference sections, or link-based attribution in the article.\n"
            . "6. Create a professional fact_check object for the collapsible Fact Check & Sources box using supplied source URLs, research-pack sources, and OpenRouter web-search sources when available. Source URLs are allowed only inside fact_check. Do not overclaim: describe it as source-checked, not independently audited.\n"
            . "7. Create Rank Math fields: SEO title, meta description, focus keyword, and WordPress tags. Do not return any SEO score.\n"
            . "8. Tables are optional and only for genuinely structured facts already present with at least 3 comparable rows. Charts are optional and only for real comparable numbers with at least 3 rows. Never invent numbers or estimates.\n"
            . "9. Do not generate social media captions, push text, X posts, Facebook posts, Telegram posts, LinkedIn posts, or related-story boxes.\n"
            . "10. Return ONLY valid JSON, no markdown fences.";

        $response = AIN_AI::openrouter_chat(
            array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            ),
            array(
                'model' => $settings['openrouter_writer_model'] ?: $settings['openrouter_text_model'],
                'json' => true,
                'temperature' => 0.05,
                'timeout' => 120,
            )
        );
        if ( is_wp_error( $response ) ) return $response;
        $production = ain_json_decode_loose( $response['content'] );
        if ( ! is_array( $production ) ) {
            return new WP_Error( 'bad_production_json', 'AI production editor returned invalid JSON.' );
        }
        unset( $production['social'], $production['seo_score'], $production['rank_math_score'], $production['content'], $production['final_title'], $production['slug'], $production['excerpt'] );
        return $production;
    }

    private static function fallback_production_pack( $draft, $item, $campaign, $research_pack, $category ) {
        $plain = wp_strip_all_tags( $draft['excerpt'] ?? '' );
        if ( ! $plain ) $plain = wp_trim_words( wp_strip_all_tags( $draft['content'] ?? '' ), 28, '' );
        $title = sanitize_text_field( $draft['final_title'] ?? $item->suggested_title ?: $item->source_title );
        $focus = trim( preg_replace( '/[^\pL\pN\s-]+/u', '', $title ) );
        $focus = wp_trim_words( $focus, 5, '' );
        $tags = array();
        if ( ! empty( $research_pack['people_organizations'] ) && is_array( $research_pack['people_organizations'] ) ) {
            foreach ( $research_pack['people_organizations'] as $entity ) {
                if ( is_array( $entity ) ) $entity = $entity['name'] ?? '';
                if ( $entity ) $tags[] = $entity;
            }
        }
        if ( $category && ! is_wp_error( $category ) && ! empty( $category->name ) ) $tags[] = $category->name;
        return array(
            'content' => $draft['content'] ?? '',
            'seo_title' => $title,
            'meta_description' => substr( sanitize_text_field( $plain ), 0, 160 ),
            'focus_keyword' => $focus,
            'tags' => array_slice( array_values( array_unique( array_filter( $tags ) ) ), 0, 5 ),
            'source_attribution' => '',
            'inline_media_query' => $focus ?: $title,
            'image_prompt' => ( $draft['final_title'] ?? $item->image_prompt ?? $title ) . ', professional editorial news image, no text overlay',
            'link_insertions' => array(),
            'table' => array( 'title' => '', 'headers' => array(), 'rows' => array() ),
            'chart' => array( 'title' => '', 'rows' => array() ),
            'fact_check' => array(),
            'value_added' => $draft['value_added'] ?? ( $research_pack['value_added'] ?? array() ),
        );
    }

    private static function merge_article_and_production( $draft, $production ) {
        $final = is_array( $draft ) ? $draft : array();
        $allowed_production_keys = array(
            'seo_title', 'meta_description', 'focus_keyword', 'tags',
            'source_attribution', 'inline_media_query', 'image_prompt',
            'chart', 'table', 'link_insertions', 'value_added', 'fact_check'
        );
        if ( is_array( $production ) ) {
            foreach ( $allowed_production_keys as $key ) {
                if ( array_key_exists( $key, $production ) && null !== $production[ $key ] && '' !== $production[ $key ] ) {
                    $final[ $key ] = $production[ $key ];
                }
            }
        }
        foreach ( array( 'final_title', 'slug', 'excerpt', 'content' ) as $key ) {
            if ( empty( $final[ $key ] ) && ! empty( $draft[ $key ] ) ) $final[ $key ] = $draft[ $key ];
        }
        if ( empty( $final['seo_title'] ) ) $final['seo_title'] = $final['final_title'] ?? '';
        if ( empty( $final['meta_description'] ) ) $final['meta_description'] = wp_trim_words( wp_strip_all_tags( $final['excerpt'] ?? $final['content'] ?? '' ), 26, '' );
        if ( empty( $final['focus_keyword'] ) ) $final['focus_keyword'] = wp_trim_words( wp_strip_all_tags( $final['final_title'] ?? '' ), 5, '' );
        if ( empty( $final['tags'] ) || ! is_array( $final['tags'] ) ) $final['tags'] = array();
        if ( empty( $final['link_insertions'] ) || ! is_array( $final['link_insertions'] ) ) $final['link_insertions'] = array();
        if ( empty( $final['table'] ) || ! is_array( $final['table'] ) ) $final['table'] = array( 'title' => '', 'headers' => array(), 'rows' => array() );
        if ( empty( $final['chart'] ) || ! is_array( $final['chart'] ) ) $final['chart'] = array( 'title' => '', 'rows' => array() );
        if ( empty( $final['fact_check'] ) || ! is_array( $final['fact_check'] ) ) $final['fact_check'] = array();
        return $final;
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

    private static function build_internal_link_map( $draft, $item, $campaign, $research_pack, $category_id, $category, $settings ) {
        if ( empty( $settings['enable_internal_links'] ) ) return array();
        $max_internal = max( 0, (int) ( $settings['max_internal_links'] ?? 3 ) );
        if ( $max_internal <= 0 ) return array();

        $article_text = trim(
            ( $draft['final_title'] ?? '' ) . ' ' .
            ( $draft['excerpt'] ?? '' ) . ' ' .
            wp_strip_all_tags( $draft['content'] ?? '' ) . ' ' .
            ( $item->ai_summary ?? '' ) . ' ' .
            wp_json_encode( $research_pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
        );
        $terms = self::internal_link_terms_from_story( $draft, $item, $research_pack );

        $map = array(
            'current_category' => array(),
            'category_hubs' => array(),
            'tag_hubs' => array(),
            'related_posts' => array(),
        );

        if ( $category && ! is_wp_error( $category ) ) {
            $map['current_category'] = array(
                'label' => $category->name,
                'url' => get_category_link( $category ),
                'type' => 'current_category',
            );
        }

        if ( ! empty( $settings['enable_topic_hub_links'] ) ) {
            $map['category_hubs'] = self::rank_taxonomy_links( 'category', $article_text, $terms, $category_id, 8 );
            $map['tag_hubs'] = self::rank_taxonomy_links( 'post_tag', $article_text, $terms, 0, 8 );
        }
        $map['related_posts'] = self::find_relevant_post_links( $terms, $article_text, $category_id, max( 4, $max_internal + 3 ) );

        return $map;
    }

    private static function internal_link_terms_from_story( $draft, $item, $research_pack ) {
        $terms = array();
        $add = function( $value ) use ( &$terms ) {
            if ( is_array( $value ) ) {
                foreach ( $value as $v ) {
                    if ( is_array( $v ) ) $v = $v['name'] ?? $v['label'] ?? $v['term'] ?? '';
                    if ( $v ) $terms[] = sanitize_text_field( $v );
                }
            } elseif ( is_string( $value ) && '' !== trim( $value ) ) {
                $terms[] = sanitize_text_field( $value );
            }
        };
        $add( $research_pack['suggested_internal_link_terms'] ?? array() );
        $add( $research_pack['people_organizations'] ?? array() );
        $add( $research_pack['locations'] ?? array() );
        $add( $research_pack['topic_tags'] ?? array() );
        $add( $draft['final_title'] ?? '' );
        $add( $item->source_title ?? '' );

        $token_source = implode( ' ', array_slice( $terms, 0, 8 ) ) . ' ' . ( $draft['final_title'] ?? '' );
        foreach ( array_slice( ain_story_tokens( $token_source ), 0, 10 ) as $token ) {
            if ( strlen( $token ) >= 4 ) $terms[] = $token;
        }
        $terms = array_values( array_unique( array_filter( array_map( 'trim', $terms ) ) ) );
        return array_slice( $terms, 0, 12 );
    }

    private static function rank_taxonomy_links( $taxonomy, $article_text, $terms, $current_category_id = 0, $limit = 8 ) {
        $items = array();
        $tax_terms = get_terms( array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => 120,
            'orderby' => 'count',
            'order' => 'DESC',
        ) );
        if ( is_wp_error( $tax_terms ) || empty( $tax_terms ) ) return array();

        foreach ( $tax_terms as $term ) {
            $score = 0;
            if ( 'category' === $taxonomy && $current_category_id && (int) $term->term_id === (int) $current_category_id ) $score += 5;
            $hay = strtolower( remove_accents( $term->name . ' ' . $term->slug . ' ' . $term->description ) );
            foreach ( $terms as $candidate ) {
                $candidate = strtolower( remove_accents( $candidate ) );
                if ( strlen( $candidate ) < 3 ) continue;
                if ( false !== strpos( $hay, $candidate ) || false !== strpos( strtolower( remove_accents( $article_text ) ), $candidate ) && false !== strpos( $candidate, strtolower( remove_accents( $term->name ) ) ) ) {
                    $score += 3;
                }
            }
            $sim = ain_text_similarity( $article_text, $term->name . ' ' . $term->description );
            $score += $sim * 10;
            if ( $score <= 0 ) continue;
            $url = get_term_link( $term );
            if ( is_wp_error( $url ) ) continue;
            $items[] = array(
                'label' => $term->name,
                'url' => $url,
                'type' => 'category' === $taxonomy ? 'category_hub' : 'tag_hub',
                'score' => round( $score, 3 ),
            );
        }
        usort( $items, function( $a, $b ) { return $b['score'] <=> $a['score']; } );
        $items = array_slice( $items, 0, $limit );
        foreach ( $items as &$item ) unset( $item['score'] );
        return $items;
    }

    private static function find_relevant_post_links( $terms, $article_text, $category_id, $limit = 6 ) {
        $links = array();
        $seen = array();
        $search_terms = array_slice( array_filter( $terms, function( $term ) { return strlen( $term ) >= 4; } ), 0, 6 );
        if ( empty( $search_terms ) ) $search_terms = array_slice( ain_story_tokens( $article_text ), 0, 4 );

        foreach ( $search_terms as $term ) {
            foreach ( array( true, false ) as $with_category ) {
                $args = array(
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'posts_per_page' => 4,
                    's' => wp_strip_all_tags( $term ),
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'no_found_rows' => true,
                );
                if ( $with_category && $category_id ) $args['cat'] = (int) $category_id;
                $q = new WP_Query( $args );
                foreach ( $q->posts as $post ) {
                    $url = get_permalink( $post );
                    if ( ! $url || isset( $seen[ $url ] ) ) continue;
                    $seen[ $url ] = true;
                    $links[] = array(
                        'title' => get_the_title( $post ),
                        'url' => $url,
                        'date' => get_the_date( 'Y-m-d', $post ),
                        'type' => 'related_post',
                    );
                    if ( count( $links ) >= $limit ) break 3;
                }
                wp_reset_postdata();
            }
        }
        return array_slice( $links, 0, $limit );
    }


    private static function is_fact_check_enabled( $campaign, $settings ) {
        $mode = isset( $campaign->ai_config['fact_check_mode'] ) ? sanitize_key( $campaign->ai_config['fact_check_mode'] ) : 'global';
        if ( 'enabled' === $mode ) return true;
        if ( 'disabled' === $mode ) return false;
        return ! empty( $settings['enable_fact_check_box'] );
    }

    private static function fact_check_title( $campaign, $settings ) {
        $campaign_title = isset( $campaign->ai_config['fact_check_title'] ) ? trim( (string) $campaign->ai_config['fact_check_title'] ) : '';
        $title = $campaign_title !== '' ? $campaign_title : ( $settings['fact_check_title'] ?? 'Fact Check & Sources' );
        $title = sanitize_text_field( $title );
        return $title ? $title : 'Fact Check & Sources';
    }

    private static function fact_check_max_sources( $campaign, $settings ) {
        $campaign_max = isset( $campaign->ai_config['fact_check_max_sources'] ) ? (int) $campaign->ai_config['fact_check_max_sources'] : 0;
        if ( $campaign_max > 0 ) return max( 1, min( 12, $campaign_max ) );
        return max( 1, min( 12, (int) ( $settings['fact_check_max_sources'] ?? 6 ) ) );
    }

    private static function build_fact_check_data( $ai_fact_check, $research_pack, $source_links, $raw, $item, $campaign, $settings ) {
        if ( ! self::is_fact_check_enabled( $campaign, $settings ) ) {
            return array( 'enabled' => false );
        }

        $ai_fact_check = is_array( $ai_fact_check ) ? $ai_fact_check : array();
        $research_pack = is_array( $research_pack ) ? $research_pack : array();
        $source_links = is_array( $source_links ) ? $source_links : array();
        $raw = is_array( $raw ) ? $raw : array();
        $max_sources = self::fact_check_max_sources( $campaign, $settings );

        $facts = array();
        foreach ( (array) ( $ai_fact_check['verified_facts'] ?? array() ) as $fact ) {
            $normalized = self::normalize_fact_check_fact( $fact );
            if ( $normalized ) $facts[] = $normalized;
            if ( count( $facts ) >= 5 ) break;
        }
        if ( count( $facts ) < 5 && ! empty( $research_pack['key_facts'] ) && is_array( $research_pack['key_facts'] ) ) {
            foreach ( $research_pack['key_facts'] as $fact ) {
                $normalized = self::normalize_fact_check_fact( $fact );
                if ( $normalized ) $facts[] = $normalized;
                if ( count( $facts ) >= 5 ) break;
            }
        }

        $sources = array();
        $add_source = function( $src, $fallback_type = 'Source material' ) use ( &$sources ) {
            $normalized = AIN_Writer::normalize_fact_check_source( $src, $fallback_type );
            if ( ! $normalized ) return;
            $key = ! empty( $normalized['url'] ) ? strtolower( $normalized['url'] ) : strtolower( $normalized['title'] . '|' . $normalized['publisher'] );
            if ( isset( $sources[ $key ] ) ) {
                if ( empty( $sources[ $key ]['why_used'] ) && ! empty( $normalized['why_used'] ) ) {
                    $sources[ $key ]['why_used'] = $normalized['why_used'];
                }
                return;
            }
            $sources[ $key ] = $normalized;
        };

        foreach ( (array) ( $ai_fact_check['sources'] ?? array() ) as $src ) $add_source( $src, 'Source used' );
        foreach ( (array) ( $research_pack['openrouter_web_sources'] ?? array() ) as $src ) $add_source( $src, 'OpenRouter web search' );
        foreach ( (array) ( $research_pack['web_search_sources'] ?? array() ) as $src ) $add_source( $src, 'OpenRouter web search' );
        foreach ( (array) ( $research_pack['citation_sources'] ?? array() ) as $src ) $add_source( $src, 'Research citation' );
        foreach ( (array) ( $research_pack['citations'] ?? array() ) as $src ) $add_source( $src, 'Research citation' );
        foreach ( (array) ( $research_pack['verified_sources'] ?? array() ) as $src ) $add_source( $src, 'Research source' );
        foreach ( $source_links as $src ) $add_source( $src, 'Source material' );
        foreach ( (array) ( $raw['sources'] ?? array() ) as $src ) $add_source( $src, 'Original source' );
        if ( empty( $sources ) && ! empty( $item->source_url ) ) {
            $add_source( array( 'title' => $item->source_title, 'publisher' => $item->source_name, 'url' => $item->source_url, 'why_used' => 'Primary source material for this story.' ), 'Original source' );
        }
        $sources = array_slice( array_values( $sources ), 0, $max_sources );

        $cautions = array();
        foreach ( array( 'caution_notes', 'uncertain_claims', 'uncertain_or_conflicting_claims', 'claims_not_to_make' ) as $key ) {
            if ( ! empty( $ai_fact_check[ $key ] ) ) {
                foreach ( (array) $ai_fact_check[ $key ] as $note ) {
                    $text = self::fact_check_text_from_mixed( $note );
                    if ( $text ) $cautions[] = $text;
                }
            }
        }
        foreach ( array( 'uncertain_or_conflicting_claims', 'claims_not_to_make' ) as $key ) {
            if ( ! empty( $research_pack[ $key ] ) ) {
                foreach ( (array) $research_pack[ $key ] as $note ) {
                    $text = self::fact_check_text_from_mixed( $note );
                    if ( $text ) $cautions[] = $text;
                }
            }
        }
        $cautions = array_slice( array_values( array_unique( array_filter( $cautions ) ) ), 0, 5 );

        $summary = sanitize_textarea_field( $ai_fact_check['summary'] ?? '' );
        if ( ! $summary ) {
            $summary = 'This story was checked against the source material and research references used during AI Newsroom production.';
        }

        if ( empty( $facts ) && empty( $sources ) && empty( $cautions ) ) {
            return array( 'enabled' => false );
        }

        return array(
            'enabled' => true,
            'title' => self::fact_check_title( $campaign, $settings ),
            'summary' => $summary,
            'method' => count( $sources ) > 1 ? 'Source-checked' : 'Single-source check',
            'verified_facts' => $facts,
            'sources' => $sources,
            'caution_notes' => $cautions,
            'generated_at' => current_time( 'mysql' ),
        );
    }

    private static function normalize_fact_check_fact( $fact ) {
        if ( is_string( $fact ) ) {
            $claim = sanitize_text_field( $fact );
            return $claim ? array( 'claim' => $claim, 'status' => 'Source checked', 'evidence' => '', 'source_title' => '', 'url' => '' ) : array();
        }
        if ( ! is_array( $fact ) ) return array();
        $claim = self::fact_check_text_from_mixed( $fact['claim'] ?? ( $fact['fact'] ?? ( $fact['text'] ?? ( $fact['summary'] ?? '' ) ) ) );
        if ( ! $claim ) return array();
        return array(
            'claim' => $claim,
            'status' => sanitize_text_field( $fact['status'] ?? 'Source checked' ),
            'evidence' => self::fact_check_text_from_mixed( $fact['evidence'] ?? ( $fact['why'] ?? '' ) ),
            'source_title' => sanitize_text_field( $fact['source_title'] ?? ( $fact['source'] ?? '' ) ),
            'url' => esc_url_raw( $fact['url'] ?? '' ),
        );
    }

    public static function normalize_fact_check_source( $src, $fallback_type = 'Source material' ) {
        if ( is_string( $src ) ) {
            $url = esc_url_raw( $src );
            if ( ! $url ) return array();
            $host = ain_safe_url_host( $url );
            return array( 'title' => $host ?: $url, 'publisher' => $host, 'url' => $url, 'why_used' => '', 'source_type' => $fallback_type );
        }
        if ( ! is_array( $src ) ) return array();
        $url = esc_url_raw( $src['url'] ?? ( $src['source_url'] ?? '' ) );
        $publisher = sanitize_text_field( $src['publisher'] ?? ( $src['source_name'] ?? ( $url ? ain_safe_url_host( $url ) : '' ) ) );
        $title = sanitize_text_field( $src['title'] ?? ( $src['source_title'] ?? ( $src['name'] ?? '' ) ) );
        if ( ! $title ) $title = $publisher ?: ( $url ? ain_safe_url_host( $url ) : '' );
        if ( ! $title && ! $url ) return array();
        return array(
            'title' => $title ?: $url,
            'publisher' => $publisher,
            'url' => $url,
            'why_used' => self::fact_check_text_from_mixed( $src['why_used'] ?? ( $src['description'] ?? ( $src['reason'] ?? '' ) ) ),
            'source_type' => sanitize_text_field( $src['source_type'] ?? $fallback_type ),
        );
    }

    private static function fact_check_text_from_mixed( $value ) {
        if ( is_scalar( $value ) ) {
            return trim( sanitize_text_field( (string) $value ) );
        }
        if ( is_array( $value ) ) {
            $parts = array();
            foreach ( $value as $v ) {
                if ( is_scalar( $v ) ) $parts[] = trim( (string) $v );
            }
            return trim( sanitize_text_field( implode( ' ', array_filter( $parts ) ) ) );
        }
        return '';
    }

    public static function render_fact_check_box( $data, $campaign, $settings ) {
        if ( empty( $data['enabled'] ) || ! is_array( $data ) ) return '';
        $title = sanitize_text_field( $data['title'] ?? self::fact_check_title( $campaign, $settings ) );
        $summary = sanitize_textarea_field( $data['summary'] ?? '' );
        $facts = ! empty( $data['verified_facts'] ) && is_array( $data['verified_facts'] ) ? $data['verified_facts'] : array();
        $sources = ! empty( $data['sources'] ) && is_array( $data['sources'] ) ? $data['sources'] : array();
        $cautions = ! empty( $data['caution_notes'] ) && is_array( $data['caution_notes'] ) ? $data['caution_notes'] : array();
        $method = sanitize_text_field( $data['method'] ?? 'Source-checked' );
        $open = ! empty( $settings['fact_check_default_open'] ) ? ' open' : '';

        ob_start();
        ?>
        <details class="ain-fact-check-box"<?php echo $open; ?>>
            <summary class="ain-fact-check-summary" aria-label="Open fact check and sources">
                <span class="ain-fact-check-icon">✓</span>
                <span class="ain-fact-check-title-wrap">
                    <span class="ain-fact-check-kicker">Source transparency</span>
                    <span class="ain-fact-check-title"><?php echo esc_html( $title ); ?></span>
                </span>
                <span class="ain-fact-check-meta"><?php echo esc_html( $method ); ?> · <?php echo esc_html( count( $sources ) ); ?> source<?php echo 1 === count( $sources ) ? '' : 's'; ?></span>
            </summary>
            <section class="ain-fact-check-body" aria-label="Fact check notes and sources">
                <?php if ( $summary ) : ?><p class="ain-fact-check-lede"><?php echo esc_html( $summary ); ?></p><?php endif; ?>
                <div class="ain-fact-check-stats">
                    <span><strong><?php echo esc_html( count( $facts ) ); ?></strong><small>facts checked</small></span>
                    <span><strong><?php echo esc_html( count( $sources ) ); ?></strong><small>sources reviewed</small></span>
                    <span><strong><?php echo esc_html( count( $cautions ) ); ?></strong><small>caution notes</small></span>
                </div>
                <?php if ( ! empty( $settings['fact_check_show_verified_facts'] ) && $facts ) : ?>
                    <h4>What was checked</h4>
                    <ul class="ain-fact-check-facts">
                        <?php foreach ( array_slice( $facts, 0, 5 ) as $fact ) : ?>
                            <?php $fact = self::normalize_fact_check_fact( $fact ); if ( ! $fact ) continue; ?>
                            <li>
                                <span class="ain-fact-status"><?php echo esc_html( $fact['status'] ?: 'Source checked' ); ?></span>
                                <span><?php echo esc_html( $fact['claim'] ); ?></span>
                                <?php if ( ! empty( $fact['evidence'] ) ) : ?><small><?php echo esc_html( $fact['evidence'] ); ?></small><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ( $sources ) : ?>
                    <h4>Sources reviewed</h4>
                    <ul class="ain-fact-check-sources">
                        <?php foreach ( $sources as $src ) : ?>
                            <?php $src = self::normalize_fact_check_source( $src ); if ( ! $src ) continue; ?>
                            <li>
                                <span class="ain-source-type"><?php echo esc_html( $src['source_type'] ?: 'Source' ); ?></span>
                                <?php if ( ! empty( $src['url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $src['url'] ); ?>" rel="nofollow noopener" target="_blank"><?php echo esc_html( $src['title'] ); ?></a>
                                <?php else : ?>
                                    <strong><?php echo esc_html( $src['title'] ); ?></strong>
                                <?php endif; ?>
                                <?php if ( ! empty( $src['publisher'] ) ) : ?><small><?php echo esc_html( $src['publisher'] ); ?></small><?php endif; ?>
                                <?php if ( ! empty( $src['why_used'] ) ) : ?><em><?php echo esc_html( $src['why_used'] ); ?></em><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ( ! empty( $settings['fact_check_show_uncertain_claims'] ) && $cautions ) : ?>
                    <h4>Notes and limits</h4>
                    <ul class="ain-fact-check-cautions">
                        <?php foreach ( array_slice( $cautions, 0, 5 ) as $note ) : ?>
                            <?php $note = self::fact_check_text_from_mixed( $note ); if ( ! $note ) continue; ?>
                            <li><?php echo esc_html( $note ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p class="ain-fact-check-disclaimer">This panel is generated from the same source material and research pack used to produce the article. It is designed for transparency and does not replace independent editorial review.</p>
            </section>
        </details>
        <?php
        return trim( ob_get_clean() );
    }

    private static function allowed_production_link_urls( $source_links, $internal_link_map ) {
        // External source URLs are intentionally not allowed in article bodies.
        // This map is internal-only so production can improve SEO without turning
        // the article into a source-summary/link roundup.
        $allowed = array();
        $flatten = function( $items ) use ( &$allowed ) {
            foreach ( (array) $items as $item ) {
                if ( ! empty( $item['url'] ) ) $allowed[ esc_url_raw( $item['url'] ) ] = 'internal';
            }
        };
        if ( ! empty( $internal_link_map['current_category']['url'] ) ) $allowed[ esc_url_raw( $internal_link_map['current_category']['url'] ) ] = 'internal';
        $flatten( $internal_link_map['category_hubs'] ?? array() );
        $flatten( $internal_link_map['tag_hubs'] ?? array() );
        $flatten( $internal_link_map['related_posts'] ?? array() );
        return $allowed;
    }

    private static function apply_link_insertions( $content, $insertions, $allowed_urls, $settings ) {
        if ( empty( $insertions ) || ! is_array( $insertions ) || empty( $allowed_urls ) ) return $content;
        $max_internal = max( 0, (int) ( $settings['max_internal_links'] ?? 3 ) );
        $used_internal = 0;
        $used_urls = array();

        foreach ( $insertions as $link ) {
            if ( ! is_array( $link ) ) continue;
            $url = esc_url_raw( $link['url'] ?? '' );
            $anchor = trim( wp_strip_all_tags( (string) ( $link['anchor_text'] ?? '' ) ) );
            if ( ! $url || ! $anchor || ! isset( $allowed_urls[ $url ] ) || isset( $used_urls[ $url ] ) ) continue;
            if ( 'internal' !== $allowed_urls[ $url ] ) continue;
            if ( strlen( $anchor ) < 3 || strlen( $anchor ) > 90 ) continue;
            if ( $used_internal >= $max_internal ) continue;
            $new_content = self::link_phrase_once( $content, $anchor, $url, false );
            if ( $new_content !== $content ) {
                $content = $new_content;
                $used_urls[ $url ] = true;
                $used_internal++;
            }
        }
        return $content;
    }

    private static function link_phrase_once( $html, $anchor, $url, $external = false ) {
        $linked = false;
        $result = preg_replace_callback( '#<p\b[^>]*>.*?</p>#is', function( $m ) use ( $anchor, $url, $external, &$linked ) {
            if ( ! empty( $linked ) ) return $m[0];
            $paragraph = $m[0];
            $new = self::link_phrase_in_html_fragment( $paragraph, $anchor, $url, $external );
            if ( $new !== $paragraph ) $linked = true;
            return $new;
        }, $html );
        return is_string( $result ) ? $result : $html;
    }

    private static function link_phrase_in_html_fragment( $html, $anchor, $url, $external = false ) {
        $parts = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) ) return $html;
        $in_anchor = false;
        $done = false;
        $attrs = ' href="' . esc_url( $url ) . '"';
        if ( $external ) $attrs .= ' rel="nofollow noopener" target="_blank"';
        foreach ( $parts as &$part ) {
            if ( '' === $part ) continue;
            if ( '<' === $part[0] ) {
                if ( preg_match( '/^<a\s/i', $part ) ) $in_anchor = true;
                if ( preg_match( '#^</a>#i', $part ) ) $in_anchor = false;
                continue;
            }
            if ( $in_anchor || $done ) continue;
            $pattern = '/(?<![\pL\pN])(' . preg_quote( $anchor, '/' ) . ')(?![\pL\pN])/iu';
            $part = preg_replace( $pattern, '<a' . $attrs . '>$1</a>', $part, 1, $count );
            if ( $count ) $done = true;
        }
        return $done ? implode( '', $parts ) : $html;
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
        $content = self::strip_external_links_from_content( $content );
        return trim( $content );
    }

    private static function add_missing_natural_source_link( $content, $source_links = array() ) {
        // Deprecated: AI Newsroom no longer inserts external source links into articles.
        return $content;
    }

    private static function strip_external_links_from_content( $content ) {
        $home_host = strtolower( ain_safe_url_host( home_url( '/' ) ) );
        $home_host = preg_replace( '/^www\./', '', $home_host );

        return preg_replace_callback( '#<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function( $m ) use ( $home_host ) {
            $url = html_entity_decode( $m[1], ENT_QUOTES );
            $host = strtolower( ain_safe_url_host( $url ) );
            $host = preg_replace( '/^www\./', '', $host );

            // Keep relative links, anchors, mail/tel links, and same-site links.
            if ( ! preg_match( '#^https?://#i', $url ) || ! $host || $host === $home_host ) {
                return $m[0];
            }

            // External links are unwrapped, preserving the visible words.
            return $m[2];
        }, (string) $content );
    }

    private static function ensure_external_source_link_rels( $content ) {
        // Backward-compatible wrapper. External article links are removed, not decorated.
        return self::strip_external_links_from_content( $content );
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
        if ( ! empty( $draft['fact_check'] ) ) {
            update_post_meta( $post_id, '_ain_fact_check', wp_json_encode( $draft['fact_check'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        }
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
            $tag = trim( preg_replace( '/\s+/', ' ', $tag ) );
            if ( $tag && strlen( $tag ) <= 60 ) $clean[ strtolower( $tag ) ] = $tag;
        }
        $clean = array_slice( array_values( $clean ), 0, 5 );
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
