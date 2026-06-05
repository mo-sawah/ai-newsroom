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
        'enable_topic_hub_links'         => 1,
        'enable_production_editor'       => 1,
        'max_internal_links'             => 3,
        'enable_fact_check_box'          => 1,
        'fact_check_title'               => 'Fact Check & Sources',
        'fact_check_default_open'        => 0,
        'fact_check_max_sources'         => 6,
        'fact_check_show_verified_facts' => 1,
        'fact_check_show_uncertain_claims' => 1,
        'site_voice'                     => 'Professional wire-service news voice. Accurate, neutral, concise, and specific. Use inverted-pyramid structure: lede first, attribution high, context and background lower. Prefer short paragraphs and active voice. Avoid SEO filler, generic AI transitions, commentary tone, and templated section labels such as Why it matters, What happens next, What remains uncertain, Context, The bottom line, or Key takeaways. Do not place external source hyperlinks inside article bodies.',
        'editor_prompt'                  => 'You are the senior assignment editor for a professional digital newsroom. Your job is to review raw source material and turn it into clear reporting assignments. Treat sources as reporting material, not articles to rewrite. First, understand each source item separately: main entities, topic area, event type, core action, core claim, news peg, confirmed facts, facts that still need checking, source quality, and whether the item is original reporting, an official document, a press release, or republished coverage. Then group only sources that cover the same specific development. Do not group stories only because they share the same person, company, country, industry, or broad topic. A specific development may be an announcement, filing, arrest, lawsuit, attack, policy decision, earnings result, launch, partnership, investigation, court action, security incident, market-moving statement, or official confirmation. Keep separate developments separate, even if they involve the same person or company. For each approved story cluster, create a reporting brief with a working label, one-sentence summary of the actual news, why these sources belong together, why the story is worth covering, strongest reporting angle, likely lede, nut graph in one sentence, facts that need verification, sources that should be treated carefully, recommended research depth, suggested category and author. Reject thin, duplicate, outdated, promotional, weakly sourced, or low-value stories. Prefer fewer strong, well-supported stories over many thin articles.',
        'writer_prompt'                  => 'You are an expert wire-service journalist writing for a premier global news agency. Synthesize the provided research pack into an objective, authoritative, and fast-paced news article. Do NOT write a book report summarizing individual sources. The first paragraph MUST start with a standard journalistic dateline in bold HTML, followed by an em dash. Format: <p><strong>CITY, Month Date</strong> — The lede continues here...</p>. Infer the most relevant city based on where the story is taking place or where markets/politics are reacting, and use today\'s date. The lede must be 25-35 words and deliver the newest verified development: who, what, when, and where. Use inverted pyramid structure: lede first, then a nut graph explaining broader market, political, public-interest, or local significance, then supporting details and quotes. Keep paragraphs extremely tight: 1 to 2 sentences maximum. Output clean HTML using <p>, <strong>, and <blockquote> only. Do NOT use <h2> or <h3> headings for standard news reports. Never use formulaic headers such as Why it matters, Background, Takeaways, Context, The bottom line, or What happens next. Never use filler phrases such as underscores, highlights, delve into, landscape, in a significant development, rapidly evolving, or it remains to be seen. Report verified facts directly as newsroom reporting. Use attribution only when necessary for disputed claims, quotes, legal allegations, forward-looking statements, or facts that belong clearly to an official document or named source. Do NOT create any external hyperlinks, source links, references lists, Sources section, Reporting based on paragraph, or link-based attribution. Do not fabricate facts, quotes, numbers, dates, names, links, motives, or allegations.',
        'production_prompt'              => 'You are the production editor for AI Newsroom. Your job is to protect the finished article and prepare it for WordPress and Rank Math. Do not rewrite the article body. Do not change paragraph order, voice, lede, nut graph, quotes, numbers, or claims. Return production metadata only: clean SEO title, 150-160 character meta description, one natural focus keyword, 2-5 WordPress tags, optional image metadata, optional table/chart data, and a concise fact_check object for the collapsible Fact Check & Sources box. You may suggest internal links only when the exact anchor text already exists naturally in the article and the internal URL is supplied. Never add, suggest, request, or preserve external source links in the article body. Source URLs may appear only inside the fact_check object. Tables and charts are optional and should appear only when the story clearly contains structured facts or real comparable numbers. Never invent data. Do not generate social posts, push text, related boxes, references, source lists, or any SEO score.',
    );
}

function ain_no_external_link_prompt_markers() {
    return array(
        'Smart Links',
        'source links',
        'source link',
        'external source links',
        'external links',
        'external link',
        'Add links only',
        'safe link instructions',
        'verified_external_source_links',
        'max_external_source_links',
        '<a href',
        '<a>',
        'Reuters reports',
        'Bloomberg reported',
    );
}

function ain_prompt_has_external_link_instructions( $prompt ) {
    $prompt = (string) $prompt;
    foreach ( ain_no_external_link_prompt_markers() as $marker ) {
        if ( false !== stripos( $prompt, $marker ) ) {
            return true;
        }
    }
    return false;
}

function ain_clean_external_link_prompt( $prompt, $key ) {
    $prompt = trim( (string) $prompt );
    $defaults = ain_default_settings();
    if ( in_array( $key, array( 'writer_prompt', 'production_prompt' ), true ) && ( '' === $prompt || ain_prompt_has_external_link_instructions( $prompt ) ) ) {
        return $defaults[ $key ];
    }
    return $prompt;
}

function ain_upgrade_no_external_article_links_218() {
    if ( get_option( 'ain_no_external_article_links_218_done' ) ) {
        return;
    }
    $installed_version = get_option( 'ain_version', '' );
    if ( $installed_version && version_compare( $installed_version, '2.0.18', '>=' ) ) {
        update_option( 'ain_no_external_article_links_218_done', 1, false );
        return;
    }

    $settings = get_option( AIN_OPTION_KEY, array() );
    if ( ! is_array( $settings ) ) {
        $settings = array();
    }
    $defaults = ain_default_settings();

    $settings['site_voice'] = $defaults['site_voice'];
    $settings['editor_prompt'] = $defaults['editor_prompt'];
    $settings['writer_prompt'] = $defaults['writer_prompt'];
    $settings['production_prompt'] = $defaults['production_prompt'];
    update_option( AIN_OPTION_KEY, $settings, false );

    global $wpdb;
    $table = ain_table( 'campaigns' );
    $rows = $wpdb->get_results( "SELECT id, ai_config FROM {$table}" );
    foreach ( $rows as $row ) {
        $ai = ain_decode_json_field( $row->ai_config );
        if ( ! is_array( $ai ) ) {
            $ai = array();
        }
        $ai['editor_prompt'] = $defaults['editor_prompt'];
        $ai['writer_prompt'] = $defaults['writer_prompt'];
        $ai['production_prompt'] = $defaults['production_prompt'];
        if ( ! isset( $ai['production_editor_mode'] ) ) {
            $ai['production_editor_mode'] = 'global';
        }
        $wpdb->update( $table, array(
            'ai_config' => wp_json_encode( $ai, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            'updated_at' => current_time( 'mysql' ),
        ), array( 'id' => (int) $row->id ) );
    }
    update_option( 'ain_no_external_article_links_218_done', 1, false );
}


function ain_upgrade_fact_check_box_219() {
    $settings = get_option( AIN_OPTION_KEY, array() );
    if ( ! is_array( $settings ) ) {
        $settings = array();
    }
    $defaults = ain_default_settings();
    $changed = false;
    foreach ( array( 'enable_fact_check_box', 'fact_check_title', 'fact_check_default_open', 'fact_check_max_sources', 'fact_check_show_verified_facts', 'fact_check_show_uncertain_claims' ) as $key ) {
        if ( ! array_key_exists( $key, $settings ) ) {
            $settings[ $key ] = $defaults[ $key ];
            $changed = true;
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
        if ( ! is_array( $ai ) ) {
            $ai = array();
        }
        $row_changed = false;
        if ( ! isset( $ai['fact_check_mode'] ) ) {
            $ai['fact_check_mode'] = 'global';
            $row_changed = true;
        }
        if ( ! isset( $ai['fact_check_title'] ) ) {
            $ai['fact_check_title'] = '';
            $row_changed = true;
        }
        if ( ! isset( $ai['fact_check_max_sources'] ) ) {
            $ai['fact_check_max_sources'] = '';
            $row_changed = true;
        }
        if ( $row_changed ) {
            $wpdb->update( $table, array(
                'ai_config' => wp_json_encode( $ai, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                'updated_at' => current_time( 'mysql' ),
            ), array( 'id' => (int) $row->id ) );
        }
    }
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

function ain_upgrade_production_prompt_213() {
    $settings = get_option( AIN_OPTION_KEY, array() );
    if ( ! is_array( $settings ) ) $settings = array();
    $defaults = ain_default_settings();
    $changed = false;
    if ( empty( $settings['production_prompt'] ) ) {
        $settings['production_prompt'] = $defaults['production_prompt'];
        $changed = true;
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
        if ( empty( $ai['production_prompt'] ) ) {
            $ai['production_prompt'] = $settings['production_prompt'];
            $wpdb->update( $table, array( 'ai_config' => wp_json_encode( $ai, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row->id ) );
        }
    }
}


function ain_upgrade_production_safety_214() {
    $settings = get_option( AIN_OPTION_KEY, array() );
    if ( ! is_array( $settings ) ) $settings = array();
    $defaults = ain_default_settings();
    $changed = false;

    if ( ! isset( $settings['enable_topic_hub_links'] ) ) {
        $settings['enable_topic_hub_links'] = $defaults['enable_topic_hub_links'];
        $changed = true;
    }
    if ( empty( $settings['production_prompt'] ) || false !== strpos( $settings['production_prompt'], 'Make only small corrections for clarity, grammar, or broken HTML' ) ) {
        $settings['production_prompt'] = $defaults['production_prompt'];
        $changed = true;
    }
    if ( $changed ) update_option( AIN_OPTION_KEY, $settings, false );

    global $wpdb;
    $table = ain_table( 'campaigns' );
    $rows = $wpdb->get_results( "SELECT id, ai_config, media_config FROM {$table}" );
    foreach ( $rows as $row ) {
        $row_changed = false;
        $ai = ain_decode_json_field( $row->ai_config );
        if ( ! is_array( $ai ) ) $ai = array();
        if ( empty( $ai['production_prompt'] ) || false !== strpos( $ai['production_prompt'], 'Make only small corrections for clarity, grammar, or broken HTML' ) ) {
            $ai['production_prompt'] = $settings['production_prompt'];
            $row_changed = true;
        }
        $media = ain_decode_json_field( $row->media_config );
        if ( ! is_array( $media ) ) $media = array();
        if ( ! isset( $media['enable_smart_tables'] ) ) {
            $media['enable_smart_tables'] = 1;
            $row_changed = true;
        }
        if ( ! isset( $media['enable_smart_charts'] ) ) {
            $media['enable_smart_charts'] = 1;
            $row_changed = true;
        }
        if ( $row_changed ) {
            $wpdb->update( $table, array(
                'ai_config' => wp_json_encode( $ai, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                'media_config' => wp_json_encode( $media, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                'updated_at' => current_time( 'mysql' ),
            ), array( 'id' => (int) $row->id ) );
        }
    }
}



function ain_upgrade_production_editor_toggle_215() {
    $settings = get_option( AIN_OPTION_KEY, array() );
    if ( ! is_array( $settings ) ) $settings = array();
    $defaults = ain_default_settings();
    $changed = false;

    if ( ! isset( $settings['enable_production_editor'] ) ) {
        $settings['enable_production_editor'] = $defaults['enable_production_editor'];
        $changed = true;
    }
    if ( $changed ) update_option( AIN_OPTION_KEY, $settings, false );

    global $wpdb;
    $table = ain_table( 'campaigns' );
    $rows = $wpdb->get_results( "SELECT id, ai_config FROM {$table}" );
    foreach ( $rows as $row ) {
        $ai = ain_decode_json_field( $row->ai_config );
        if ( ! is_array( $ai ) ) $ai = array();
        if ( ! isset( $ai['production_editor_mode'] ) ) {
            $ai['production_editor_mode'] = 'global';
            $wpdb->update( $table, array(
                'ai_config' => wp_json_encode( $ai, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                'updated_at' => current_time( 'mysql' ),
            ), array( 'id' => (int) $row->id ) );
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
    $checkbox_keys = array( 'enable_openrouter_web_search', 'enable_internal_links', 'enable_topic_hub_links', 'enable_production_editor', 'enable_fact_check_box', 'fact_check_default_open', 'fact_check_show_verified_facts', 'fact_check_show_uncertain_claims' );
    foreach ( $defaults as $key => $default ) {
        if ( ! array_key_exists( $key, $incoming ) ) {
            if ( in_array( $key, $checkbox_keys, true ) ) {
                $clean[ $key ] = 0;
            }
            continue;
        }
        if ( is_numeric( $default ) ) {
            $clean[ $key ] = (int) $incoming[ $key ];
        } elseif ( in_array( $key, array( 'editor_prompt', 'writer_prompt', 'production_prompt', 'site_voice' ), true ) ) {
            $prompt_value = wp_kses_post( wp_unslash( $incoming[ $key ] ) );
            $clean[ $key ] = function_exists( 'ain_clean_external_link_prompt' ) ? ain_clean_external_link_prompt( $prompt_value, $key ) : $prompt_value;
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
    $allowed['table'] = array( 'class' => true );
    $allowed['thead'] = array();
    $allowed['tbody'] = array();
    $allowed['tr'] = array();
    $allowed['th'] = array( 'scope' => true );
    $allowed['td'] = array();
    $allowed['details'] = array( 'class' => true, 'open' => true );
    $allowed['summary'] = array( 'class' => true, 'aria-label' => true );
    $allowed['section'] = array( 'class' => true, 'aria-label' => true );
    foreach ( array( 'div', 'span', 'small', 'p', 'strong', 'em', 'ul', 'ol', 'li', 'h4' ) as $tag ) {
        if ( ! isset( $allowed[ $tag ] ) || ! is_array( $allowed[ $tag ] ) ) {
            $allowed[ $tag ] = array();
        }
        $allowed[ $tag ]['class'] = true;
    }
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
