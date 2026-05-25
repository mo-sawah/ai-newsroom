<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function ain_table( $name ) {
    global $wpdb;
    return $wpdb->prefix . 'ain_' . sanitize_key( $name );
}

function ain_default_settings() {
    return array(
        'openrouter_api_key'             => '',
        'openrouter_text_model'          => 'openai/gpt-5-mini',
        'openrouter_research_model'      => 'openai/gpt-5-mini',
        'openrouter_writer_model'        => 'openai/gpt-5-mini',
        'openrouter_image_model'         => 'google/gemini-3.1-flash-image-preview',
        'openrouter_image_aspect_ratio'  => '16:9',
        'openrouter_image_size'          => '1K',
        'enable_openrouter_web_search'   => 1,
        'default_search_result_count'    => 6,
        'default_story_strategy'         => 'smart',
        'perplexity_api_key'             => '',
        'perplexity_model'               => 'sonar-pro',
        'gnews_api_key'                  => '',
        'firecrawl_api_key'              => '',
        'youtube_api_key'                => '',
        'pexels_api_key'                 => '',
        'default_publish_mode'           => 'draft',
        'default_min_quality'            => 80,
        'default_words_target'           => 850,
        'default_author'                 => 'auto',
        'default_category'               => 'auto',
        'enable_internal_links'          => 1,
        'max_internal_links'             => 3,
        'site_voice'                     => 'Professional wire-service news voice. Accurate, neutral, concise, and specific. Use inverted-pyramid structure: lede first, attribution high, context and background lower. Prefer short paragraphs and active voice. Avoid SEO filler, generic AI transitions, commentary tone, and templated section labels such as Why it matters, What happens next, What remains uncertain, Context, The bottom line, or Key takeaways.',
        'editor_prompt'                  => 'You are the assignment editor of a serious digital newsroom. Select and group only stories that have news value, freshness, relevance, and reader interest. Treat incoming URLs as raw reporting material. Group sources only when they describe the same specific development: the same event, announcement, filing, lawsuit, arrest, attack, policy decision, earnings result, launch, partnership, investigation, disaster, conflict update, or market-moving claim. Do not group sources only because they share the same person, company, country, industry, broad topic, or trip. If two stories share an entity but have different actions, claims, topic areas, consequences, or reader questions, keep them separate. Do not write the final article title; create only a working label, story brief, selection reason, research questions, and title direction. Reject thin, duplicate, irrelevant, promotional, or low-value items.',
        'writer_prompt'                  => 'You are a senior wire-service reporter writing only the article draft. Produce a polished, publication-ready news article in inverted-pyramid style, not a blog post, newsletter, explainer template, or SEO article. The lede must give the newest verified development in 35 words or fewer where possible. Use the nut graph shortly after the lede to explain significance without opinion. Use short paragraphs, active voice, neutral attribution, precise language, and natural human transitions. Do not create SEO metadata, social copy, charts, tables, image prompts, or source-list paragraphs. Do not fabricate facts, quotes, numbers, dates, names, links, or motives.',
    );
}


function ain_upgrade_smart_grouping_prompts_205() {
    $settings = get_option( AIN_OPTION_KEY, array() );
    if ( ! is_array( $settings ) ) return;
    $defaults = ain_default_settings();

    $old_bad_phrase = 'Group items about the same company/person/event/action into one cluster';
    if ( empty( $settings['editor_prompt'] ) || false !== strpos( $settings['editor_prompt'], $old_bad_phrase ) ) {
        $settings['editor_prompt'] = $defaults['editor_prompt'];
    }
    if ( empty( $settings['site_voice'] ) ) {
        $settings['site_voice'] = $defaults['site_voice'];
    }
    if ( empty( $settings['writer_prompt'] ) ) {
        $settings['writer_prompt'] = $defaults['writer_prompt'];
    }
    update_option( AIN_OPTION_KEY, $settings, false );

    global $wpdb;
    $table = ain_table( 'campaigns' );
    $rows = $wpdb->get_results( "SELECT id, ai_config FROM {$table}" );
    foreach ( $rows as $row ) {
        $ai = ain_decode_json_field( $row->ai_config );
        if ( ! is_array( $ai ) ) continue;
        $changed = false;
        if ( ! empty( $ai['editor_prompt'] ) && false !== strpos( $ai['editor_prompt'], $old_bad_phrase ) ) {
            $ai['editor_prompt'] = $defaults['editor_prompt'];
            $changed = true;
        }
        if ( $changed ) {
            $wpdb->update( $table, array( 'ai_config' => wp_json_encode( $ai, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row->id ) );
        }
    }
}


function ain_upgrade_wire_prompts_206() {
    $settings = get_option( AIN_OPTION_KEY, array() );
    if ( ! is_array( $settings ) ) return;
    $defaults = ain_default_settings();
    $old_writer_phrases = array(
        'You are a professional news reporter writing a publishable article, not an explainer template',
        'Write like an experienced newsroom editor',
        'Why it matters or What remains unclear unless the story genuinely requires them'
    );
    foreach ( $old_writer_phrases as $phrase ) {
        if ( ! empty( $settings['writer_prompt'] ) && false !== strpos( $settings['writer_prompt'], $phrase ) ) {
            $settings['writer_prompt'] = $defaults['writer_prompt'];
            break;
        }
    }
    if ( empty( $settings['site_voice'] ) || false !== strpos( $settings['site_voice'], 'formulaic section labels' ) ) {
        $settings['site_voice'] = $defaults['site_voice'];
    }
    update_option( AIN_OPTION_KEY, $settings, false );

    global $wpdb;
    $table = ain_table( 'campaigns' );
    $rows = $wpdb->get_results( "SELECT id, ai_config FROM {$table}" );
    foreach ( $rows as $row ) {
        $ai = ain_decode_json_field( $row->ai_config );
        if ( ! is_array( $ai ) ) continue;
        $changed = false;
        foreach ( $old_writer_phrases as $phrase ) {
            if ( ! empty( $ai['writer_prompt'] ) && false !== strpos( $ai['writer_prompt'], $phrase ) ) {
                $ai['writer_prompt'] = $defaults['writer_prompt'];
                $changed = true;
                break;
            }
        }
        if ( $changed ) {
            $wpdb->update( $table, array( 'ai_config' => wp_json_encode( $ai, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row->id ) );
        }
    }
}


function ain_upgrade_two_step_writer_prompts_212() {
    $settings = get_option( AIN_OPTION_KEY, array() );
    if ( ! is_array( $settings ) ) return;
    $defaults = ain_default_settings();
    $old_writer_markers = array(
        'Link sources naturally on attribution words or source names inside the relevant paragraph',
        'Write a professional news article in inverted-pyramid style, not a blog post, newsletter, explainer template, or SEO article'
    );
    $changed = false;
    foreach ( $old_writer_markers as $marker ) {
        if ( ! empty( $settings['writer_prompt'] ) && false !== strpos( $settings['writer_prompt'], $marker ) ) {
            $settings['writer_prompt'] = $defaults['writer_prompt'];
            $changed = true;
            break;
        }
    }
    if ( $changed ) {
        update_option( AIN_OPTION_KEY, $settings, false );
    }

    global $wpdb;
    $table = ain_table( 'campaigns' );
    $rows = $wpdb->get_results( "SELECT id, ai_config FROM {$table}" );
    foreach ( $rows as $row ) {
        $ai = ain_decode_json_field( $row->ai_config );
        if ( ! is_array( $ai ) ) continue;
        $campaign_changed = false;
        foreach ( $old_writer_markers as $marker ) {
            if ( ! empty( $ai['writer_prompt'] ) && false !== strpos( $ai['writer_prompt'], $marker ) ) {
                $ai['writer_prompt'] = $defaults['writer_prompt'];
                $campaign_changed = true;
                break;
            }
        }
        if ( $campaign_changed ) {
            $wpdb->update( $table, array( 'ai_config' => wp_json_encode( $ai, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row->id ) );
        }
    }
}

function ain_get_settings() {
    $settings = get_option( AIN_OPTION_KEY, array() );
    return wp_parse_args( is_array( $settings ) ? $settings : array(), ain_default_settings() );
}

function ain_update_settings( $incoming ) {
    $defaults = ain_default_settings();
    $current  = ain_get_settings();
    $clean    = $current;
    $checkbox_keys = array( 'enable_openrouter_web_search', 'enable_internal_links' );
    foreach ( $defaults as $key => $default ) {
        if ( ! array_key_exists( $key, $incoming ) ) {
            if ( in_array( $key, $checkbox_keys, true ) ) {
                $clean[ $key ] = 0;
            }
            continue;
        }
        if ( is_numeric( $default ) ) {
            $clean[ $key ] = (int) $incoming[ $key ];
        } elseif ( in_array( $key, array( 'editor_prompt', 'writer_prompt', 'site_voice' ), true ) ) {
            $clean[ $key ] = wp_kses_post( wp_unslash( $incoming[ $key ] ) );
        } else {
            $clean[ $key ] = sanitize_text_field( wp_unslash( $incoming[ $key ] ) );
        }
    }
    update_option( AIN_OPTION_KEY, $clean, false );
    return $clean;
}

function ain_log( $level, $message, $context = array(), $campaign_id = 0, $queue_id = 0 ) {
    global $wpdb;
    $wpdb->insert(
        ain_table( 'logs' ),
        array(
            'campaign_id' => (int) $campaign_id,
            'queue_id'    => (int) $queue_id,
            'level'       => sanitize_key( $level ),
            'message'     => sanitize_text_field( $message ),
            'context'     => wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            'created_at'  => current_time( 'mysql' ),
        )
    );
}

function ain_array_from_lines( $text ) {
    if ( is_array( $text ) ) {
        return array_values( array_filter( array_map( 'trim', $text ) ) );
    }
    $lines = preg_split( '/\r\n|\r|\n/', (string) $text );
    return array_values( array_filter( array_map( 'trim', $lines ) ) );
}

function ain_safe_url_host( $url ) {
    $host = wp_parse_url( $url, PHP_URL_HOST );
    return $host ? preg_replace( '/^www\./', '', $host ) : '';
}


function ain_array_is_list_compat( $array ) {
    if ( ! is_array( $array ) ) return false;
    $i = 0;
    foreach ( $array as $k => $_v ) {
        if ( $k !== $i++ ) return false;
    }
    return true;
}

function ain_json_decode_loose( $content ) {
    if ( is_array( $content ) ) {
        return $content;
    }
    $content = trim( (string) $content );
    $content = preg_replace( '/^```(?:json)?/i', '', $content );
    $content = preg_replace( '/```$/', '', $content );
    $content = trim( $content );
    $decoded = json_decode( $content, true );
    if ( json_last_error() === JSON_ERROR_NONE ) {
        return $decoded;
    }
    if ( preg_match( '/(\{.*\}|\[.*\])/s', $content, $m ) ) {
        $decoded = json_decode( $m[1], true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            return $decoded;
        }
    }
    return null;
}

function ain_decode_json_field( $value, $default = array() ) {
    if ( is_array( $value ) ) {
        return $value;
    }
    $decoded = json_decode( (string) $value, true );
    return is_array( $decoded ) ? $decoded : $default;
}

function ain_bool( $value ) {
    return ! empty( $value ) && '0' !== (string) $value && 'false' !== strtolower( (string) $value );
}



function ain_url_hash( $url ) {
    $url = trim( (string) $url );
    if ( '' === $url ) return '';
    $parts = wp_parse_url( $url );
    if ( empty( $parts['host'] ) ) return md5( strtolower( $url ) );
    $host = strtolower( preg_replace( '/^www\./', '', $parts['host'] ) );
    $path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
    $query = '';
    if ( ! empty( $parts['query'] ) ) {
        parse_str( $parts['query'], $q );
        foreach ( array_keys( $q ) as $key ) {
            if ( preg_match( '/^(utm_|fbclid|gclid|mc_|ref|source|cmp)/i', $key ) ) unset( $q[ $key ] );
        }
        if ( ! empty( $q ) ) {
            ksort( $q );
            $query = '?' . http_build_query( $q );
        }
    }
    return md5( $host . $path . $query );
}

function ain_story_tokens( $text ) {
    $text = strtolower( wp_strip_all_tags( (string) $text ) );
    $text = remove_accents( $text );
    $text = preg_replace( '/[^a-z0-9\s]+/', ' ', $text );
    $words = preg_split( '/\s+/', trim( $text ) );
    $stop = array_fill_keys( array( 'the','a','an','and','or','but','to','of','in','on','for','with','by','from','at','as','is','are','was','were','be','been','this','that','these','those','new','latest','update','news','report','reports','says','said','after','before','over','into','about','what','why','how','could','would','should','will','may','can','its','their','they','them','his','her','our','your' ), true );
    $out = array();
    foreach ( $words as $word ) {
        if ( strlen( $word ) < 3 || isset( $stop[ $word ] ) ) continue;
        $out[ $word ] = true;
    }
    return array_keys( $out );
}

function ain_text_similarity( $a, $b ) {
    $ta = ain_story_tokens( $a );
    $tb = ain_story_tokens( $b );
    if ( empty( $ta ) || empty( $tb ) ) return 0;
    $sa = array_fill_keys( $ta, true );
    $sb = array_fill_keys( $tb, true );
    $inter = array_intersect_key( $sa, $sb );
    $union = $sa + $sb;
    return count( $inter ) / max( 1, count( $union ) );
}

function ain_key_entity_overlap( $a, $b ) {
    $ta = ain_story_tokens( $a );
    $tb = ain_story_tokens( $b );
    if ( empty( $ta ) || empty( $tb ) ) return 0;
    $important_a = array_values( array_filter( $ta, function( $w ) { return strlen( $w ) >= 5; } ) );
    $important_b = array_values( array_filter( $tb, function( $w ) { return strlen( $w ) >= 5; } ) );
    if ( empty( $important_a ) || empty( $important_b ) ) return 0;
    $sa = array_fill_keys( $important_a, true );
    $sb = array_fill_keys( $important_b, true );
    $inter = array_intersect_key( $sa, $sb );
    return count( $inter ) / max( 1, min( count( $sa ), count( $sb ) ) );
}

function ain_story_fingerprint( $text ) {
    $text = strtolower( wp_strip_all_tags( (string) $text ) );
    $text = remove_accents( $text );
    $text = preg_replace( '/[^a-z0-9\s]+/', ' ', $text );
    $words = preg_split( '/\s+/', trim( $text ) );
    $stop = array_fill_keys( array( 'the','a','an','and','or','but','to','of','in','on','for','with','by','from','at','as','is','are','was','were','be','been','this','that','these','those','new','latest','update','news','report','says','said','after','before','over','into','about' ), true );
    $keep = array();
    foreach ( $words as $word ) {
        if ( strlen( $word ) < 3 || isset( $stop[ $word ] ) ) continue;
        $keep[] = $word;
    }
    $keep = array_slice( array_unique( $keep ), 0, 14 );
    return implode( '-', $keep );
}


/**
 * Returns broad editorial topic buckets found in text. Used only as a safety net
 * so the code does not merge two stories just because they share an entity.
 */
function ain_story_topic_buckets( $text ) {
    $text = strtolower( remove_accents( wp_strip_all_tags( (string) $text ) ) );
    $buckets = array(
        'defense' => array( 'defense','defence','military','army','navy','air force','weapon','weapons','missile','missiles','security pact','troops','nato','pentagon','warship','fighter jet' ),
        'trade' => array( 'trade','tariff','tariffs','import','imports','export','exports','supply chain','customs','duties','market access','commerce','trade deal' ),
        'finance' => array( 'earnings','revenue','profit','loss','shares','stock','valuation','ipo','merger','acquisition','bankruptcy','funding','investment','investors' ),
        'legal' => array( 'lawsuit','court','judge','trial','charges','charged','settlement','probe','investigation','indictment','regulator','sec','doj','police','arrest','arrested' ),
        'policy' => array( 'bill','law','policy','regulation','regulatory','ban','rules','government','minister','parliament','congress','senate','executive order' ),
        'technology' => array( 'launch','product','app','software','ai','model','chip','data center','update','platform','cyber','hack','breach','security flaw' ),
        'crypto' => array( 'crypto','bitcoin','ethereum','blockchain','token','tokens','wallet','exchange','stablecoin','defi','xrp','ledger' ),
        'sports' => array( 'match','game','team','league','player','coach','tournament','season','goal','score','win','loss' ),
    );
    $found = array();
    foreach ( $buckets as $bucket => $terms ) {
        foreach ( $terms as $term ) {
            if ( false !== strpos( $text, $term ) ) {
                $found[] = $bucket;
                break;
            }
        }
    }
    return array_values( array_unique( $found ) );
}

function ain_story_topics_conflict( $a, $b ) {
    $ta = ain_story_topic_buckets( $a );
    $tb = ain_story_topic_buckets( $b );
    if ( empty( $ta ) || empty( $tb ) ) return false;
    $shared = array_intersect( $ta, $tb );
    if ( ! empty( $shared ) ) return false;
    // Only treat as conflict when both sides have a clearly different topic bucket.
    return true;
}

function ain_source_card_fallback( $item ) {
    $title = $item['title'] ?? '';
    $desc  = $item['description'] ?? '';
    $text  = trim( $title . ' ' . $desc );
    $buckets = ain_story_topic_buckets( $text );
    return array(
        'source_id'          => $item['source_id'] ?? '',
        'main_entities'      => array(),
        'topic_area'         => ! empty( $buckets ) ? implode( ', ', $buckets ) : 'general',
        'event_type'         => 'unknown',
        'core_action'        => '',
        'core_claim'         => $title,
        'news_peg'           => $title,
        'date_or_timeframe'  => $item['published'] ?? '',
        'location'           => '',
        'reader_question'    => '',
        'grouping_key'       => ain_story_fingerprint( $text ),
        'should_consider'    => true,
        'notes'              => 'Fallback card created locally because AI source-card extraction was unavailable.',
    );
}

function ain_allowed_post_html() {
    $allowed = wp_kses_allowed_html( 'post' );
    if ( ! isset( $allowed['a'] ) ) $allowed['a'] = array();
    $allowed['a']['href'] = true;
    $allowed['a']['target'] = true;
    $allowed['a']['rel'] = true;
    $allowed['a']['title'] = true;
    $allowed['iframe'] = array(
        'src'             => true,
        'width'           => true,
        'height'          => true,
        'title'           => true,
        'frameborder'     => true,
        'allow'           => true,
        'allowfullscreen' => true,
        'loading'         => true,
    );
    $allowed['svg'] = array( 'viewbox' => true, 'viewBox' => true, 'xmlns' => true, 'role' => true, 'aria-label' => true, 'class' => true, 'width' => true, 'height' => true );
    $allowed['rect'] = array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'fill' => true, 'class' => true );
    $allowed['text'] = array( 'x' => true, 'y' => true, 'fill' => true, 'font-size' => true, 'font-weight' => true, 'class' => true );
    $allowed['line'] = array( 'x1' => true, 'x2' => true, 'y1' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true );
    return $allowed;
}

function ain_author_tone( $user_id ) {
    return get_user_meta( (int) $user_id, 'ain_author_tone', true );
}

function ain_author_beats( $user_id ) {
    return get_user_meta( (int) $user_id, 'ain_author_beats', true );
}

function ain_category_rules( $term_id ) {
    return get_term_meta( (int) $term_id, 'ain_category_rules', true );
}

function ain_campaign_types() {
    return array(
        'rss'           => 'RSS Monitor',
        'gnews'         => 'GNews Search',
        'firecrawl'     => 'Firecrawl Site Monitor',
        'perplexity'    => 'Perplexity Research',
        'press_release' => 'Press Release Monitor',
        'youtube'       => 'YouTube Video Desk',
        'manual'        => 'Manual URL Research',
    );
}

function ain_status_label( $status ) {
    $labels = array(
        'active'       => 'Active',
        'paused'       => 'Paused',
        'draft'        => 'Draft',
        'planned'      => 'Planned',
        'approved'     => 'Approved',
        'queued'       => 'Queued',
        'writing'      => 'Writing',
        'drafted'      => 'Drafted',
        'needs_review' => 'Needs Review',
        'published'    => 'Published',
        'failed'       => 'Failed',
        'rejected'     => 'Rejected',
    );
    return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
}

function ain_minutes_to_label( $minutes ) {
    $minutes = max( 5, (int) $minutes );
    if ( 0 === $minutes % 1440 ) return ( $minutes / 1440 ) . ' day(s)';
    if ( 0 === $minutes % 60 ) return ( $minutes / 60 ) . ' hour(s)';
    return $minutes . ' minutes';
}
