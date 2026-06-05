<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIN_AI {
    public static function openrouter_chat( $messages, $args = array() ) {
        $settings = ain_get_settings();
        if ( empty( $settings['openrouter_api_key'] ) ) {
            return new WP_Error( 'missing_openrouter', 'OpenRouter API key is missing.' );
        }
        $args = wp_parse_args( $args, array(
            'model'       => $settings['openrouter_text_model'],
            'json'        => false,
            'temperature' => 0.35,
            'timeout'     => 90,
            'tools'       => array(),
            'plugins'     => array(),
            'max_tokens'  => 0,
            'modalities'  => array(),
            'image_config'=> array(),
        ) );

        $payload = array(
            'model'       => $args['model'],
            'messages'    => $messages,
            'temperature' => (float) $args['temperature'],
        );
        if ( ! empty( $args['json'] ) ) {
            $payload['response_format'] = array( 'type' => 'json_object' );
        }
        if ( ! empty( $args['tools'] ) && is_array( $args['tools'] ) ) {
            $payload['tools'] = $args['tools'];
        }
        if ( ! empty( $args['plugins'] ) && is_array( $args['plugins'] ) ) {
            $payload['plugins'] = $args['plugins'];
        }
        if ( ! empty( $args['max_tokens'] ) ) {
            $payload['max_tokens'] = (int) $args['max_tokens'];
        }
        if ( ! empty( $args['modalities'] ) && is_array( $args['modalities'] ) ) {
            $payload['modalities'] = array_values( $args['modalities'] );
        }
        if ( ! empty( $args['image_config'] ) && is_array( $args['image_config'] ) ) {
            $payload['image_config'] = $args['image_config'];
        }

        $response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
            'timeout' => (int) $args['timeout'],
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['openrouter_api_key'],
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url( '/' ),
                'X-Title'       => get_bloginfo( 'name' ) . ' AI Newsroom',
            ),
            'body'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'openrouter_error', $body['error']['message'] ?? 'OpenRouter request failed.' );
        }
        return array(
            'content' => $body['choices'][0]['message']['content'] ?? '',
            'message' => $body['choices'][0]['message'] ?? array(),
            'raw'     => $body,
            'usage'   => $body['usage'] ?? array(),
        );
    }

    public static function perplexity_chat( $messages, $args = array() ) {
        $settings = ain_get_settings();
        if ( empty( $settings['perplexity_api_key'] ) ) {
            return new WP_Error( 'missing_perplexity', 'Perplexity API key is missing.' );
        }
        $args = wp_parse_args( $args, array(
            'model'       => $settings['perplexity_model'],
            'temperature' => 0.2,
            'timeout'     => 80,
        ) );
        $payload = array(
            'model'       => $args['model'],
            'messages'    => $messages,
            'temperature' => (float) $args['temperature'],
        );
        $response = wp_remote_post( 'https://api.perplexity.ai/chat/completions', array(
            'timeout' => (int) $args['timeout'],
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['perplexity_api_key'],
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
        ) );
        if ( is_wp_error( $response ) ) return $response;
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'perplexity_error', $body['error']['message'] ?? 'Perplexity request failed.' );
        }
        return array(
            'content'   => $body['choices'][0]['message']['content'] ?? '',
            'raw'       => $body,
            'citations' => $body['citations'] ?? array(),
        );
    }

    public static function available_authors() {
        $authors = array();
        foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
            $authors[] = array(
                'id'    => (int) $u->ID,
                'name'  => $u->display_name,
                'beats' => ain_author_beats( $u->ID ),
                'tone'  => ain_author_tone( $u->ID ),
            );
        }
        return $authors;
    }

    public static function available_categories() {
        $categories = array();
        foreach ( get_categories( array( 'hide_empty' => 0 ) ) as $c ) {
            $categories[] = array(
                'id'    => (int) $c->term_id,
                'name'  => $c->name,
                'rules' => ain_category_rules( $c->term_id ),
            );
        }
        return $categories;
    }

    public static function editorial_plan( $items, $campaign ) {
        $campaign = AIN_Campaigns::parse( $campaign );
        $settings = ain_get_settings();
        if ( empty( $items ) ) return array();

        $payload = array(
            'campaign' => array(
                'id'      => (int) $campaign->id,
                'name'    => $campaign->name,
                'type'    => $campaign->type,
                'query'   => $campaign->source_config['topic_query'] ?? '',
                'tone'    => $campaign->ai_config['tone'] ?? $settings['site_voice'],
                'rules'   => self::mode_instructions( $campaign->type ),
            ),
            'available_authors'    => self::available_authors(),
            'available_categories' => self::available_categories(),
            'items'                => array_values( $items ),
            'required_output'      => array(
                array(
                    'source_id'       => 'must match source_id exactly',
                    'approved'        => true,
                    'suggested_title' => 'original human newsroom headline',
                    'ai_summary'      => 'editorial brief: angle, facts to verify, context to include, what to avoid',
                    'image_prompt'    => 'featured image prompt, no text overlay',
                    'author_id'       => 'integer from available_authors',
                    'category_id'     => 'integer from available_categories',
                    'priority'        => '0-100 urgency/news value',
                    'quality_score'   => '0-100 expected usefulness/original value',
                ),
            ),
        );

        $system = trim( $campaign->ai_config['editor_prompt'] ?? $settings['editor_prompt'] ) . "\n\n"
            . "You are operating one independent AI Newsroom campaign. Select only items that deserve coverage. "
            . "Avoid duplicate, thin, promotional, outdated, irrelevant, or low-value content. "
            . "Return ONLY a valid JSON object with key \"plans\" containing an array. Do not include markdown.\n\n"
            . self::mode_instructions( $campaign->type );

        $model = $settings['openrouter_research_model'] ?: $settings['openrouter_text_model'];
        $response = self::openrouter_chat(
            array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            ),
            array( 'model' => $model, 'json' => true, 'temperature' => (float) ( $campaign->ai_config['temperature'] ?? 0.25 ), 'timeout' => 120 )
        );
        if ( is_wp_error( $response ) ) return $response;
        $decoded = ain_json_decode_loose( $response['content'] );
        if ( isset( $decoded['plans'] ) && is_array( $decoded['plans'] ) ) {
            return $decoded['plans'];
        }
        if ( is_array( $decoded ) && ain_array_is_list_compat( $decoded ) ) {
            return $decoded;
        }
        return new WP_Error( 'bad_editor_json', 'AI editor returned invalid JSON.' );
    }

    public static function source_cards( $items, $campaign ) {
        $campaign = AIN_Campaigns::parse( $campaign );
        $settings = ain_get_settings();
        if ( empty( $items ) ) return array();

        $compact = array();
        foreach ( array_values( $items ) as $item ) {
            $compact[] = array(
                'source_id'   => $item['source_id'] ?? '',
                'source_name' => $item['source_name'] ?? '',
                'title'       => $item['title'] ?? '',
                'description' => $item['description'] ?? '',
                'url'         => $item['url'] ?? '',
                'published'   => $item['published'] ?? '',
            );
        }

        $payload = array(
            'campaign' => array(
                'id'            => (int) $campaign->id,
                'name'          => $campaign->name,
                'type'          => $campaign->type,
                'query'         => $campaign->source_config['topic_query'] ?? '',
                'language_code' => $campaign->source_config['language_code'] ?? 'en',
            ),
            'source_items' => $compact,
            'required_output' => array(
                'source_cards' => array(
                    array(
                        'source_id'         => 'must match one source_id exactly',
                        'main_entities'     => array( 'people, companies, countries, organizations mentioned' ),
                        'topic_area'        => 'specific beat/topic, e.g. defense, trade, legal, crypto, finance, health, sports',
                        'event_type'        => 'agreement|lawsuit|filing|arrest|attack|launch|earnings|policy|market_move|statement|analysis|other',
                        'core_action'       => 'the specific thing that happened or was claimed',
                        'core_claim'        => 'one sentence: what this source is actually reporting',
                        'news_peg'          => 'the concrete new development if one exists',
                        'date_or_timeframe' => 'date/timeframe stated or implied',
                        'location'          => 'location if relevant',
                        'reader_question'   => 'main question a reader expects this story to answer',
                        'grouping_key'      => 'short key based on entity + action + topic + claim, not just entity names',
                        'should_consider'   => true,
                        'notes'             => 'brief grouping notes',
                    ),
                ),
            ),
        );

        $system = "You are the intake editor for a newsroom story desk. Before grouping stories, convert each raw source item into a precise source card. "
            . "The card must identify the specific development being reported, not just the people or companies mentioned. "
            . "Very important: same entity does not always mean same story. For example, four articles about a president visiting China may split into two stories if two are about a defense agreement and two are about a trade agreement. "
            . "Focus on topic_area, event_type, core_action, core_claim, news_peg, and reader_question. Return one card per source_id. Return ONLY valid JSON with key source_cards.";

        $response = self::openrouter_chat(
            array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            ),
            array(
                'model'       => $settings['openrouter_research_model'] ?: $settings['openrouter_text_model'],
                'json'        => true,
                'temperature' => 0.08,
                'timeout'     => 120,
            )
        );

        if ( is_wp_error( $response ) ) {
            $fallback = array();
            foreach ( $items as $item ) $fallback[] = ain_source_card_fallback( $item );
            return $fallback;
        }
        $decoded = ain_json_decode_loose( $response['content'] );
        if ( ! isset( $decoded['source_cards'] ) || ! is_array( $decoded['source_cards'] ) ) {
            $fallback = array();
            foreach ( $items as $item ) $fallback[] = ain_source_card_fallback( $item );
            return $fallback;
        }

        $valid_ids = array();
        foreach ( $items as $item ) {
            if ( ! empty( $item['source_id'] ) ) $valid_ids[ $item['source_id'] ] = true;
        }
        $cards = array();
        foreach ( $decoded['source_cards'] as $card ) {
            $sid = sanitize_text_field( $card['source_id'] ?? '' );
            if ( ! $sid || ! isset( $valid_ids[ $sid ] ) ) continue;
            $cards[] = array(
                'source_id'         => $sid,
                'main_entities'     => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $card['main_entities'] ?? array() ) ) ) ),
                'topic_area'        => sanitize_text_field( $card['topic_area'] ?? '' ),
                'event_type'        => sanitize_text_field( $card['event_type'] ?? '' ),
                'core_action'       => sanitize_text_field( $card['core_action'] ?? '' ),
                'core_claim'        => sanitize_text_field( $card['core_claim'] ?? '' ),
                'news_peg'          => sanitize_text_field( $card['news_peg'] ?? '' ),
                'date_or_timeframe' => sanitize_text_field( $card['date_or_timeframe'] ?? '' ),
                'location'          => sanitize_text_field( $card['location'] ?? '' ),
                'reader_question'   => sanitize_text_field( $card['reader_question'] ?? '' ),
                'grouping_key'      => sanitize_text_field( $card['grouping_key'] ?? '' ),
                'should_consider'   => array_key_exists( 'should_consider', $card ) ? ! empty( $card['should_consider'] ) : true,
                'notes'             => sanitize_text_field( $card['notes'] ?? '' ),
            );
        }

        // Ensure every item has a card, even if the AI skipped one.
        $seen = array_fill_keys( wp_list_pluck( $cards, 'source_id' ), true );
        foreach ( $items as $item ) {
            $sid = $item['source_id'] ?? '';
            if ( $sid && empty( $seen[ $sid ] ) ) $cards[] = ain_source_card_fallback( $item );
        }
        return $cards;
    }

    public static function story_clusters( $items, $campaign ) {
        $campaign = AIN_Campaigns::parse( $campaign );
        $settings = ain_get_settings();
        if ( empty( $items ) ) return array();

        $strategy = $campaign->ai_config['story_strategy'] ?? $settings['default_story_strategy'] ?? 'smart';
        if ( in_array( $strategy, array( 'off', 'source_first' ), true ) ) {
            $plans = self::editorial_plan( $items, $campaign );
            if ( is_wp_error( $plans ) ) return $plans;
            $stories = array();
            foreach ( $plans as $plan ) {
                if ( empty( $plan['approved'] ) || empty( $plan['source_id'] ) ) continue;
                $stories[] = array(
                    'story_id'          => 'story_' . md5( $plan['source_id'] . ( $plan['suggested_title'] ?? '' ) ),
                    'source_ids'        => array( $plan['source_id'] ),
                    'primary_source_id' => $plan['source_id'],
                    'approved'          => true,
                    'working_label'     => $plan['suggested_title'] ?? 'Source story',
                    'story_summary'     => $plan['ai_summary'] ?? '',
                    'selection_reason'  => 'Approved in source-first mode.',
                    'editorial_angle'   => $plan['ai_summary'] ?? '',
                    'why_it_matters'    => '',
                    'research_questions'=> array(),
                    'facts_to_verify'   => array(),
                    'title_direction'   => 'The final headline should be created after research, using the strongest verified news peg.',
                    'what_to_avoid'     => array( 'Do not treat this working label as the final article title.' ),
                    'image_prompt'      => $plan['image_prompt'] ?? '',
                    'author_id'         => (int) ( $plan['author_id'] ?? 0 ),
                    'category_id'       => (int) ( $plan['category_id'] ?? 0 ),
                    'priority'          => (int) ( $plan['priority'] ?? 50 ),
                    'quality_score'     => (int) ( $plan['quality_score'] ?? 0 ),
                );
            }
            return $stories;
        }

        $compact = array();
        foreach ( array_values( $items ) as $item ) {
            $compact[] = array(
                'source_id'   => $item['source_id'] ?? '',
                'source_name' => $item['source_name'] ?? '',
                'title'       => $item['title'] ?? '',
                'description' => $item['description'] ?? '',
                'url'         => $item['url'] ?? '',
                'published'   => $item['published'] ?? '',
            );
        }

        $source_cards = self::source_cards( $items, $campaign );

        $payload = array(
            'campaign' => array(
                'id'             => (int) $campaign->id,
                'name'           => $campaign->name,
                'type'           => $campaign->type,
                'query'          => $campaign->source_config['topic_query'] ?? '',
                'language_code'  => $campaign->source_config['language_code'] ?? 'en',
                'story_strategy' => $strategy,
                'rules'          => self::mode_instructions( $campaign->type ),
            ),
            'available_authors'    => self::available_authors(),
            'available_categories' => self::available_categories(),
            'source_items'         => $compact,
            'source_cards'         => $source_cards,
            'required_output'      => array(
                'stories' => array(
                    array(
                        'story_id'           => 'stable short id generated from the specific development, not just entity names',
                        'approved'           => true,
                        'source_ids'         => array( 'source ids that cover the same specific development' ),
                        'primary_source_id'  => 'best source id from source_ids',
                        'working_label'      => 'short internal label for the story cluster, not a final headline',
                        'story_summary'      => '2-4 sentence editor brief explaining the specific development',
                        'selection_reason'   => 'why this story is worth covering and why these sources belong together',
                        'grouping_logic'     => 'state the shared core_action/core_claim/topic_area that makes this one story',
                        'split_logic'        => 'state any related items/entities that should NOT be merged and why',
                        'topic_area'         => 'specific topic area for this cluster',
                        'event_type'         => 'specific event/development type',
                        'core_action'        => 'the common action/development across grouped sources',
                        'core_claim'         => 'the common claim/fact being reported',
                        'news_peg'           => 'newest concrete verified/likely development to research',
                        'editorial_angle'    => 'rough angle for the reporter/researcher; identify whether this is hard news, analysis, explainer, or backgrounder',
                        'why_it_matters'     => 'reader value and context',
                        'research_questions' => array( 'questions the research editor should answer before writing' ),
                        'facts_to_verify'    => array( 'specific names/dates/numbers/claims to verify' ),
                        'title_direction'    => 'guidance for headline framing after research; do not write the final title here',
                        'what_to_avoid'      => array( 'unsupported claims, hype, source wording, generic trend framing, and treating the working label as a final title' ),
                        'image_prompt'       => 'editorial image prompt, no text overlay',
                        'author_id'          => 'integer from available_authors',
                        'category_id'        => 'integer from available_categories',
                        'priority'           => '0-100 urgency/news value',
                        'quality_score'      => '0-100 expected usefulness/original value',
                    ),
                ),
            ),
        );

        $current_date = date( 'l, F j, Y', current_time( 'timestamp' ) );
        $system = trim( $campaign->ai_config['editor_prompt'] ?? $settings['editor_prompt'] ) . "\n\n"
            . "CRITICAL CONTEXT: Today is {$current_date}. Treat all timelines, 'yesterdays', and 'tomorrows' in the sources relative to this date.\n\n"
            . "You are the Managing Editor of a human-style newsroom. You receive raw source items from various feeds. "
            . "Your job is intelligent editorial clustering. Group sources into logical stories.\n"
            . "- MAJOR EVENTS: Group sources covering the exact same concrete event (e.g., a specific lawsuit, a specific earnings call) into a single, focused story cluster.\n"
            . "- ROUNDUPS & DIGESTS: If you see multiple minor events sharing the same broad narrative arc (e.g., three different politicians making minor statements about the same topic), group them into a single 'Roundup' or 'Digest' story.\n"
            . "- Do NOT aggressively split sources just because they feature slightly different actions if they clearly belong in the same journalistic context.\n"
            . "Create a working_label, story_summary, editorial_angle, and research questions for the research desk. "
            . "Reject thin, duplicate, or purely promotional items. Return ONLY valid JSON with key \"stories\". No markdown.\n\n"
            . self::mode_instructions( $campaign->type );

        $model = $settings['openrouter_research_model'] ?: $settings['openrouter_text_model'];
        $response = self::openrouter_chat(
            array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            ),
            array( 'model' => $model, 'json' => true, 'temperature' => 0.12, 'timeout' => 180 )
        );
        if ( is_wp_error( $response ) ) return $response;
        $decoded = ain_json_decode_loose( $response['content'] );
        if ( isset( $decoded['stories'] ) && is_array( $decoded['stories'] ) ) {
            return self::clean_story_clusters( $decoded['stories'], $items, $strategy );
        }
        return new WP_Error( 'bad_story_desk_json', 'AI story desk returned invalid JSON.' );
    }

    private static function clean_story_clusters( $stories, $items, $strategy = 'smart' ) {
        $valid_ids = array();
        foreach ( $items as $item ) {
            if ( ! empty( $item['source_id'] ) ) $valid_ids[ $item['source_id'] ] = true;
        }
        $clean = array();
        foreach ( $stories as $story ) {
            if ( empty( $story['approved'] ) ) continue;
            $source_ids = array();
            foreach ( (array) ( $story['source_ids'] ?? array() ) as $sid ) {
                $sid = sanitize_text_field( $sid );
                if ( isset( $valid_ids[ $sid ] ) && ! in_array( $sid, $source_ids, true ) ) $source_ids[] = $sid;
            }
            if ( empty( $source_ids ) ) continue;
            $primary = sanitize_text_field( $story['primary_source_id'] ?? $source_ids[0] );
            if ( ! isset( $valid_ids[ $primary ] ) ) $primary = $source_ids[0];

            $working_label = sanitize_text_field( $story['working_label'] ?? ( $story['suggested_title'] ?? '' ) );
            if ( '' === $working_label ) {
                $working_label = 'Story cluster: ' . substr( md5( implode( '|', $source_ids ) ), 0, 8 );
            }

            $story['story_id'] = sanitize_text_field( $story['story_id'] ?? ( 'story_' . md5( implode( '|', $source_ids ) ) ) );
            $story['source_ids'] = $source_ids;
            $story['primary_source_id'] = $primary;
            $story['working_label'] = $working_label;
            unset( $story['suggested_title'] );
            $story['story_summary'] = wp_kses_post( $story['story_summary'] ?? '' );
            $story['selection_reason'] = wp_kses_post( $story['selection_reason'] ?? '' );
            $story['grouping_logic'] = wp_kses_post( $story['grouping_logic'] ?? '' );
            $story['split_logic'] = wp_kses_post( $story['split_logic'] ?? '' );
            $story['topic_area'] = sanitize_text_field( $story['topic_area'] ?? '' );
            $story['event_type'] = sanitize_text_field( $story['event_type'] ?? '' );
            $story['core_action'] = sanitize_text_field( $story['core_action'] ?? '' );
            $story['core_claim'] = sanitize_text_field( $story['core_claim'] ?? '' );
            $story['news_peg'] = sanitize_text_field( $story['news_peg'] ?? '' );
            $story['editorial_angle'] = wp_kses_post( $story['editorial_angle'] ?? '' );
            $story['why_it_matters'] = wp_kses_post( $story['why_it_matters'] ?? '' );
            $story['title_direction'] = wp_kses_post( $story['title_direction'] ?? 'Create the final title after the research pack identifies the strongest verified news peg.' );
            $story['research_questions'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $story['research_questions'] ?? array() ) ) ) );
            $story['facts_to_verify'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $story['facts_to_verify'] ?? array() ) ) ) );
            $story['what_to_avoid'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $story['what_to_avoid'] ?? array() ) ) ) );
            $story['author_id'] = (int) ( $story['author_id'] ?? 0 );
            $story['category_id'] = (int) ( $story['category_id'] ?? 0 );
            $story['priority'] = max( 0, min( 100, (int) ( $story['priority'] ?? 50 ) ) );
            $story['quality_score'] = max( 0, min( 100, (int) ( $story['quality_score'] ?? 0 ) ) );

            $clean[] = $story;
        }
        return self::merge_similar_story_clusters( $clean, $strategy );
    }

    private static function cluster_match_text( $story ) {
        return trim(
            ( $story['working_label'] ?? '' ) . ' ' .
            ( $story['topic_area'] ?? '' ) . ' ' .
            ( $story['event_type'] ?? '' ) . ' ' .
            ( $story['core_action'] ?? '' ) . ' ' .
            ( $story['core_claim'] ?? '' ) . ' ' .
            ( $story['news_peg'] ?? '' ) . ' ' .
            ( $story['story_summary'] ?? '' ) . ' ' .
            ( $story['editorial_angle'] ?? '' )
        );
    }

    private static function merge_similar_story_clusters( $stories, $strategy = 'smart' ) {
        if ( count( $stories ) < 2 ) return $stories;

        // Safety merge only. The AI should already group stories. Code should prevent duplicates,
        // not override editorial differences such as Trump-China trade vs Trump-China defense.
        $threshold = 'aggressive' === $strategy ? 0.35 : 0.45;
        $merged = array();
        foreach ( $stories as $story ) {
            $story_text = self::cluster_match_text( $story );
            $target = -1;
            foreach ( $merged as $idx => $existing ) {
                $existing_text = self::cluster_match_text( $existing );
                $shared_sources = array_intersect( (array) ( $story['source_ids'] ?? array() ), (array) ( $existing['source_ids'] ?? array() ) );
                if ( ! empty( $shared_sources ) ) {
                    $target = $idx;
                    break;
                }
                if ( ain_story_topics_conflict( $story_text, $existing_text ) ) {
                    continue;
                }
                $sim = ain_text_similarity( $story_text, $existing_text );
                if ( $sim >= $threshold ) {
                    $target = $idx;
                    break;
                }
            }
            if ( $target < 0 ) {
                $merged[] = $story;
                continue;
            }
            $existing =& $merged[ $target ];
            $existing['source_ids'] = array_values( array_unique( array_merge( (array) $existing['source_ids'], (array) $story['source_ids'] ) ) );
            if ( (int) ( $story['quality_score'] ?? 0 ) > (int) ( $existing['quality_score'] ?? 0 ) ) {
                $existing['quality_score'] = (int) $story['quality_score'];
                $existing['priority'] = max( (int) ( $existing['priority'] ?? 0 ), (int) ( $story['priority'] ?? 0 ) );
            }
            $existing['story_summary'] = trim( ( $existing['story_summary'] ?? '' ) . "\n" . ( $story['story_summary'] ?? '' ) );
            $existing['selection_reason'] = trim( ( $existing['selection_reason'] ?? '' ) . "\nMerged only because the clusters appeared to cover the same specific development: " . ( $story['selection_reason'] ?? $story['working_label'] ?? '' ) );
            $existing['grouping_logic'] = trim( ( $existing['grouping_logic'] ?? '' ) . "\n" . ( $story['grouping_logic'] ?? '' ) );
            $existing['research_questions'] = array_values( array_unique( array_merge( (array) ( $existing['research_questions'] ?? array() ), (array) ( $story['research_questions'] ?? array() ) ) ) );
            $existing['facts_to_verify'] = array_values( array_unique( array_merge( (array) ( $existing['facts_to_verify'] ?? array() ), (array) ( $story['facts_to_verify'] ?? array() ) ) ) );
            unset( $existing );
        }
        return $merged;
    }

    public static function build_research_pack( $item, $campaign, $source_contexts = array() ) {
        $campaign = AIN_Campaigns::parse( $campaign );
        $settings = ain_get_settings();
        $existing = ain_decode_json_field( $item->research_pack );
        if ( ! empty( $existing['finalized'] ) && ! empty( $existing['headline_options'] ) ) return $existing;

        $depth = $campaign->ai_config['research_depth'] ?? 'balanced';
        $search_enabled = array_key_exists( 'enable_web_search', $campaign->ai_config ) ? ! empty( $campaign->ai_config['enable_web_search'] ) : ! empty( $settings['enable_openrouter_web_search'] );
        $search_result_count = max( 3, min( 10, (int) ( $campaign->ai_config['search_result_count'] ?? $settings['default_search_result_count'] ?? 6 ) ) );
        $raw = ain_decode_json_field( $item->raw_payload );
        $story_desk = $raw['story_cluster'] ?? array();
        $working_label = $story_desk['working_label'] ?? ( $item->suggested_title ?: $item->source_title );

        $payload = array(
            'campaign' => array(
                'name' => $campaign->name,
                'type' => $campaign->type,
                'research_depth' => $depth,
                'language_code' => $campaign->source_config['language_code'] ?? 'en',
                'topic_query' => $campaign->source_config['topic_query'] ?? '',
            ),
            'story_desk_assignment' => array(
                'working_label'      => $working_label,
                'story_summary'      => $story_desk['story_summary'] ?? $item->source_excerpt,
                'selection_reason'   => $story_desk['selection_reason'] ?? '',
                'grouping_logic'     => $story_desk['grouping_logic'] ?? '',
                'split_logic'        => $story_desk['split_logic'] ?? '',
                'topic_area'         => $story_desk['topic_area'] ?? '',
                'event_type'         => $story_desk['event_type'] ?? '',
                'core_action'        => $story_desk['core_action'] ?? '',
                'core_claim'         => $story_desk['core_claim'] ?? '',
                'news_peg'           => $story_desk['news_peg'] ?? '',
                'editorial_angle'    => $story_desk['editorial_angle'] ?? $item->ai_summary,
                'why_it_matters'     => $story_desk['why_it_matters'] ?? '',
                'research_questions' => $story_desk['research_questions'] ?? array(),
                'facts_to_verify'    => $story_desk['facts_to_verify'] ?? array(),
                'title_direction'    => $story_desk['title_direction'] ?? 'Create the final headline only after verifying the strongest news peg.',
                'what_to_avoid'      => $story_desk['what_to_avoid'] ?? array(),
            ),
            'story' => array(
                'working_label' => $working_label,
                'source_title' => $item->source_title,
                'source_url' => $item->source_url,
                'excerpt' => $item->source_excerpt,
                'editorial_assignment' => $item->ai_summary,
                'raw_payload' => $raw,
            ),
            'source_contexts' => $source_contexts,
            'existing_research_pack' => $existing,
            'search_instructions' => $search_enabled ? "Use web search where needed. Prefer primary/official sources and reliable publishers. Use no more than {$search_result_count} high-value outside sources." : 'Do not use outside web search. Work only with supplied source contexts.',
            'required_output' => array(
                'finalized' => true,
                'strongest_news_peg' => 'the most concrete verified event, filing, disclosure, statement, launch, arrest, lawsuit, report, or development; empty only if this is truly an analysis/backgrounder',
                'story_angle' => 'specific wire-service news angle: what changed, who is affected, and what is verified; avoid generic trend framing unless no concrete event exists',
                'lede_direction' => 'one-sentence hard-news lede direction, preferably 35 words or fewer, with who/what/when/where and attribution need',
                'headline_options' => array(
                    'event-led headline option 1',
                    'event-led headline option 2',
                    'context/analysis headline option only if appropriate',
                ),
                'recommended_headline' => 'best article headline after research; specific, accurate, and human newsroom style',
                'headline_rationale' => 'why this headline is the strongest and what verified fact it uses',
                'seo_angle' => 'search-friendly angle without clickbait',
                'key_facts' => array(),
                'timeline' => array(),
                'people_organizations' => array(),
                'background_context' => array(),
                'verified_sources' => array( array( 'title' => '', 'url' => '', 'publisher' => '', 'why_used' => '' ) ),
                'uncertain_or_conflicting_claims' => array(),
                'claims_not_to_make' => array(),
                'value_added' => array(
                    'original_angle' => '',
                    'context_added' => '',
                    'reader_question_answered' => '',
                    'why_this_matters' => '',
                ),
                'suggested_internal_link_terms' => array(),
            ),
        );

        $current_date = date( 'l, F j, Y', current_time( 'timestamp' ) );
        $system = "CRITICAL CONTEXT: Today is {$current_date}. Base all your timeframes on this date.\n\n"
            . "You are a wire-service newsroom research editor. Build a source-grounded research pack for a reporter, not an article. "
            . "Do not invent facts, quotes, numbers, names, dates, or source URLs. "
            . "Identify the strongest verified news peg, the correct attribution chain, and the facts that must appear in the lede/nut graph. "
            . "The story desk working_label is NOT the final article title. Create headline_options and one recommended_headline only after checking the facts. "
            . "Prepare source guidance for natural in-story attribution and for the Fact Check & Sources box. Do not recommend external links inside article paragraphs and do not recommend a source list inside the article body. "
            . "If web search is available, prefer official/primary sources, reputable newsrooms, government/company pages, court/regulatory docs, and direct statements. "
            . "Return ONLY valid JSON.";

        $args = array(
            'model'       => $settings['openrouter_research_model'] ?: $settings['openrouter_text_model'],
            'json'        => true,
            'temperature' => 0.15,
            'timeout'     => 160,
        );
        if ( $search_enabled ) {
            $args['tools'] = array( array( 'type' => 'openrouter:web_search' ) );
        }
        $response = self::openrouter_chat(
            array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            ),
            $args
        );
        if ( is_wp_error( $response ) ) return $response;
        $decoded = ain_json_decode_loose( $response['content'] );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'bad_research_json', 'Research pack builder returned invalid JSON.' );
        }
        $decoded['finalized'] = true;
        $decoded['built_at'] = current_time( 'mysql' );
        $decoded['source_context_count'] = is_array( $source_contexts ) ? count( $source_contexts ) : 0;
        $decoded['working_label'] = $working_label;
        $decoded['story_desk_assignment'] = $payload['story_desk_assignment'];
        if ( empty( $decoded['headline_options'] ) ) {
            $decoded['headline_options'] = array();
        }
        if ( empty( $decoded['recommended_headline'] ) && ! empty( $decoded['headline_options'][0] ) ) {
            $decoded['recommended_headline'] = $decoded['headline_options'][0];
        }
        return $decoded;
    }

    public static function mode_instructions( $type ) {
        switch ( $type ) {
            case 'press_release':
                return 'PRESS RELEASE RULES: Treat every source as a press release or organization announcement. Extract the real news value. Remove promotional language, hype, slogans, and marketing claims. Attribute claims to the company or organization. Add neutral context. Do not make the article sound like PR.';
            case 'perplexity':
                return 'RESEARCH RULES: Use the research pack as a starting point. Prefer stories with multiple reliable sources, fresh facts, and a clear new angle. Add context and avoid unsupported claims.';
            case 'youtube':
                return 'VIDEO RULES: Create video-led news posts only when the video is relevant, recent, and useful. The article should explain the video, summarize key moments, and add context.';
            case 'gnews':
                return 'GNEWS RULES: Avoid thin rewrites of one source. Prefer stories where you can add context, background, timeline, or local relevance.';
            case 'rss':
                return 'RSS RULES: Monitor trusted feeds, pick fresh useful stories, and avoid repeating the same topic already covered.';
            case 'firecrawl':
                return 'SITE MONITOR RULES: Extract real article links from target pages and avoid navigation/sidebar/ads.';
            case 'manual':
                return 'MANUAL URL RULES: Treat provided URLs as source material. Build a stronger story from the supplied URLs and avoid copying source text.';
            default:
                return 'GENERAL NEWSROOM RULES: Be accurate, useful, original, and clear.';
        }
    }
}
