<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIN_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_post_ain_save_campaign', array( __CLASS__, 'save_campaign' ) );
        add_action( 'admin_post_ain_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'show_user_profile', array( __CLASS__, 'user_fields' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'user_fields' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save_user_fields' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_fields' ) );
        add_action( 'category_add_form_fields', array( __CLASS__, 'category_add_fields' ) );
        add_action( 'category_edit_form_fields', array( __CLASS__, 'category_edit_fields' ) );
        add_action( 'created_category', array( __CLASS__, 'save_category_fields' ) );
        add_action( 'edited_category', array( __CLASS__, 'save_category_fields' ) );
    }

    public static function menu() {
        add_menu_page( 'AI Newsroom', 'AI Newsroom', 'manage_options', 'ain-dashboard', array( __CLASS__, 'dashboard' ), 'dashicons-megaphone', 22 );
        add_submenu_page( 'ain-dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'ain-dashboard', array( __CLASS__, 'dashboard' ) );
        add_submenu_page( 'ain-dashboard', 'Campaigns', 'Campaigns', 'manage_options', 'ain-campaigns', array( __CLASS__, 'campaigns' ) );
        add_submenu_page( 'ain-dashboard', 'Add Campaign', 'Add Campaign', 'manage_options', 'ain-campaign-edit', array( __CLASS__, 'campaign_edit' ) );
        add_submenu_page( 'ain-dashboard', 'Story Queue', 'Story Queue', 'manage_options', 'ain-queue', array( __CLASS__, 'queue' ) );
        add_submenu_page( 'ain-dashboard', 'Article Studio', 'Article Studio', 'manage_options', 'ain-studio', array( __CLASS__, 'studio' ) );
        add_submenu_page( 'ain-dashboard', 'Logs', 'Logs', 'manage_options', 'ain-logs', array( __CLASS__, 'logs' ) );
        add_submenu_page( 'ain-dashboard', 'Settings', 'Settings', 'manage_options', 'ain-settings', array( __CLASS__, 'settings' ) );
    }

    public static function assets( $hook ) {
        if ( false === strpos( $hook, 'ain-' ) && false === strpos( $hook, 'ai-newsroom' ) ) return;
        wp_enqueue_style( 'ain-admin', AIN_URL . 'assets/admin.css', array(), AIN_VERSION );
        wp_enqueue_script( 'ain-admin', AIN_URL . 'assets/admin.js', array( 'jquery' ), AIN_VERSION, true );
        wp_localize_script( 'ain-admin', 'AIN', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ain_ajax' ),
        ) );
    }

    private static function nav_link( $page, $label, $icon ) {
        $current = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'ain-dashboard';
        $active  = ( $current === $page ) ? ' is-active' : '';
        $url     = admin_url( 'admin.php?page=' . $page );
        echo '<a class="ain-nav-link' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '"><span class="dashicons ' . esc_attr( $icon ) . '"></span><span>' . esc_html( $label ) . '</span></a>';
    }

    private static function shell_start( $title, $subtitle = '' ) {
        $current = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'ain-dashboard';
        ?>
        <div class="wrap ain-wrap ain-screen-<?php echo esc_attr( $current ); ?>">
            <div class="ain-app-shell">
                <aside class="ain-sidebar" aria-label="AI Newsroom navigation">
                    <div class="ain-brand">
                        <span class="ain-brand-mark">AI</span>
                        <span class="ain-brand-text"><strong>AI Newsroom</strong><small>SmartDesk</small></span>
                    </div>
                    <nav class="ain-nav">
                        <?php
                        self::nav_link( 'ain-dashboard', 'Dashboard', 'dashicons-grid-view' );
                        self::nav_link( 'ain-campaigns', 'Campaigns', 'dashicons-megaphone' );
                        self::nav_link( 'ain-queue', 'Story Queue', 'dashicons-list-view' );
                        self::nav_link( 'ain-studio', 'Article Studio', 'dashicons-edit-page' );
                        self::nav_link( 'ain-campaign-edit', 'Sources', 'dashicons-rss' );
                        self::nav_link( 'ain-settings', 'AI Models', 'dashicons-admin-generic' );
                        self::nav_link( 'ain-logs', 'Logs', 'dashicons-clipboard' );
                        self::nav_link( 'ain-settings', 'Settings', 'dashicons-admin-settings' );
                        ?>
                    </nav>
                    <div class="ain-sidebar-footer">
                        <a class="ain-back-wp" href="<?php echo esc_url( admin_url() ); ?>"><span class="dashicons dashicons-arrow-left-alt"></span><span>Back to WordPress</span></a>
                    </div>
                </aside>
                <main class="ain-main">
                    <div class="ain-hero">
                        <div>
                            <h1><?php echo esc_html( 'ain-dashboard' === $current ? 'AI Newsroom SmartDesk' : $title ); ?></h1>
                            <?php if ( $subtitle ) : ?><p><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
                        </div>
                        <div class="ain-top-actions">
                            <a class="ain-btn ain-btn-dark" href="<?php echo esc_url( admin_url( 'admin.php?page=ain-campaign-edit' ) ); ?>"><span>+</span> New Campaign</a>
                            <a class="ain-btn ain-btn-light" href="<?php echo esc_url( admin_url( 'admin.php?page=ain-campaigns' ) ); ?>"><span class="dashicons dashicons-controls-play"></span> Run Story Desk</a>
                            <a class="ain-btn ain-btn-light" href="<?php echo esc_url( admin_url( 'admin.php?page=ain-settings' ) ); ?>"><span class="dashicons dashicons-admin-settings"></span> Settings</a>
                        </div>
                    </div>
                    <?php if ( isset( $_GET['ain_saved'] ) ) : ?><div class="notice notice-success is-dismissible ain-native-notice"><p>Saved.</p></div><?php endif; ?>
        <?php
    }

    private static function shell_end() {
        echo '</main></div></div>';
    }

    public static function dashboard() {
        $counts = AIN_DB::campaign_counts();
        $campaigns = AIN_Campaigns::all();
        $recent = AIN_DB::queue_items( array( 'limit' => 8 ) );
        $settings = ain_get_settings();
        $avg_score = 0; $score_items = 0;
        foreach ( $recent as $r ) { if ( (int) $r->quality_score > 0 ) { $avg_score += (int) $r->quality_score; $score_items++; } }
        $avg_score = $score_items ? round( $avg_score / $score_items ) : 0;
        self::shell_start( 'AI Newsroom SmartDesk', 'Story-first AI newsroom automation for WordPress' );
        ?>
        <div class="ain-kpi-grid">
            <div class="ain-kpi"><small>Active Campaigns</small><strong><?php echo esc_html( $counts['active'] ); ?></strong></div>
            <div class="ain-kpi"><small>Stories Queued</small><strong><?php echo esc_html( $counts['queue'] ); ?></strong></div>
            <div class="ain-kpi"><small>Articles Published</small><strong><?php echo esc_html( $counts['published'] ); ?></strong><span class="ain-mini-spark"></span></div>
            <div class="ain-kpi"><small>Average Quality Score</small><strong><?php echo esc_html( $avg_score ? $avg_score : '—' ); ?><?php echo $avg_score ? '<span class="ain-up">▲</span>' : ''; ?></strong><span class="ain-mini-spark ain-mini-spark-2"></span></div>
            <div class="ain-kpi"><small>Estimated AI Cost Today</small><strong>—</strong><em>Usage</em><span class="ain-mini-spark ain-mini-spark-3"></span></div>
        </div>

        <div class="ain-card ain-pipeline-card">
            <h2>Newsroom Pipeline</h2>
            <div class="ain-pipeline">
                <span><i class="dashicons dashicons-rss"></i> Source Discovery <b class="dot blue"></b></span>
                <em>→</em><span><i class="dashicons dashicons-networking"></i> Story Grouping <b class="dot purple"></b></span>
                <em>→</em><span><i class="dashicons dashicons-media-document"></i> Research Pack <b class="dot green"></b></span>
                <em>→</em><span><i class="dashicons dashicons-edit"></i> Article Writer <b class="bar"></b></span>
                <em>→</em><span><i class="dashicons dashicons-upload"></i> Publish <b class="dot purple"></b></span>
            </div>
        </div>

        <div class="ain-card ain-table-card ain-campaign-dashboard-card">
            <?php self::campaign_table( $campaigns ); ?>
        </div>

        <div class="ain-dashboard-bottom">
            <div class="ain-card ain-table-card">
                <h2>Story Queue</h2>
                <?php self::queue_table( $recent, false, true ); ?>
            </div>
            <div class="ain-card ain-studio-preview">
                <h2>Article Studio</h2>
                <?php $item = ! empty( $recent ) ? $recent[0] : null; if ( $item ) : $rp = ain_decode_json_field( $item->research_pack ); ?>
                    <div class="ain-preview-tabs"><span>Working</span><span>Headline</span></div>
                    <h3><?php echo esc_html( $item->suggested_title ?: $item->source_title ); ?></h3>
                    <div class="ain-preview-box"><strong>Research summary</strong><p><?php echo esc_html( wp_trim_words( $item->ai_summary ?: $item->source_excerpt, 35 ) ); ?></p></div>
                    <div class="ain-preview-meta"><span class="ain-status ain-status-<?php echo esc_attr( $item->status ); ?>"><?php echo esc_html( ain_status_label( $item->status ) ); ?></span><span>Score <?php echo esc_html( (int) $item->quality_score ); ?>/100</span></div>
                    <div class="ain-preview-box"><strong>Source pack details</strong><p><?php echo esc_html( ! empty( $rp ) ? wp_trim_words( wp_json_encode( $rp ), 26 ) : 'Research pack will appear here after the story is processed.' ); ?></p></div>
                    <a class="ain-btn ain-btn-light" href="<?php echo esc_url( admin_url( 'admin.php?page=ain-studio&item_id=' . $item->id ) ); ?>">Open Studio</a>
                <?php else : ?>
                    <p class="ain-muted">No story selected yet. Run a campaign to fill the Article Studio preview.</p>
                <?php endif; ?>
                <div class="ain-model-card">
                    <strong>Settings / Models</strong>
                    <small>Story Desk: <?php echo esc_html( $settings['openrouter_research_model'] ); ?></small>
                    <small>Writer: <?php echo esc_html( $settings['openrouter_writer_model'] ); ?></small>
                    <small>Image Model: <?php echo esc_html( $settings['openrouter_image_model'] ); ?></small>
                    <small>Web Search: <?php echo ! empty( $settings['enable_openrouter_web_search'] ) ? 'Enabled' : 'Disabled'; ?></small>
                </div>
            </div>
        </div>
        <?php
        self::shell_end();
    }

    public static function campaigns() {
        self::shell_start( 'Campaigns', 'Create independent campaigns for RSS, GNews, Firecrawl, Perplexity research, press releases, YouTube, and manual URLs.' );
        self::campaign_table( AIN_Campaigns::all() );
        self::shell_end();
    }

    private static function campaign_table( $campaigns ) {
        $strategy_labels = array( 'source_first' => 'Source-first', 'smart' => 'Smart Grouping', 'aggressive' => 'Aggressive' );
        ?>
        <table class="ain-table ain-campaign-table">
            <thead><tr><th>Campaign</th><th>Source Type</th><th>Story Strategy</th><th>Research Mode</th><th>Status</th><th>Last Run</th><th>Next Run</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ( empty( $campaigns ) ) : ?>
                <tr><td colspan="8" class="ain-muted">No campaigns yet.</td></tr>
            <?php endif; ?>
            <?php foreach ( $campaigns as $c ) : ?>
                <?php $strategy = $c->ai_config['story_strategy'] ?? 'smart'; $research = $c->ai_config['research_depth'] ?? 'balanced'; ?>
                <tr id="ain-campaign-row-<?php echo esc_attr( $c->id ); ?>">
                    <td><strong><?php echo esc_html( $c->name ); ?></strong><?php if ( $c->last_error ) : ?><br><small class="ain-error"><?php echo esc_html( $c->last_error ); ?></small><?php endif; ?></td>
                    <td><span class="ain-source-type-cell"><span class="ain-source-icon"><?php echo esc_html( strtoupper( substr( $c->type, 0, 1 ) ) ); ?></span><span><?php echo esc_html( ain_campaign_types()[ $c->type ] ?? $c->type ); ?></span></span></td>
                    <td><?php echo esc_html( $strategy_labels[ $strategy ] ?? $strategy ); ?></td>
                    <td><?php echo esc_html( ucfirst( $research ) ); ?></td>
                    <td><span class="ain-status ain-status-<?php echo esc_attr( $c->status ); ?>"><?php echo esc_html( ain_status_label( $c->status ) ); ?></span></td>
                    <td><small><?php echo esc_html( $c->last_run_at ? mysql2date( 'M j, g:i a', $c->last_run_at ) : 'Planned' ); ?></small></td>
                    <td><small><?php echo esc_html( $c->next_run_at ? mysql2date( 'M j, g:i a', $c->next_run_at ) : '—' ); ?></small></td>
                    <td class="ain-actions-cell">
                        <button class="ain-icon-btn ain-js-action" title="Run Now" data-action="ain_run_campaign" data-id="<?php echo esc_attr( $c->id ); ?>"><span class="dashicons dashicons-controls-play"></span></button>
                        <a class="ain-icon-btn" title="Edit" href="<?php echo esc_url( admin_url( 'admin.php?page=ain-campaign-edit&campaign_id=' . $c->id ) ); ?>"><span class="dashicons dashicons-edit"></span></a>
                        <a class="ain-icon-btn" title="Queue" href="<?php echo esc_url( admin_url( 'admin.php?page=ain-queue&campaign_id=' . $c->id ) ); ?>"><span class="dashicons dashicons-list-view"></span></a>
                        <button class="ain-icon-btn ain-js-action" title="Duplicate" data-action="ain_duplicate_campaign" data-id="<?php echo esc_attr( $c->id ); ?>"><span class="dashicons dashicons-admin-page"></span></button>
                        <button class="ain-icon-btn ain-js-action" title="<?php echo 'active' === $c->status ? 'Pause' : 'Activate'; ?>" data-action="<?php echo 'active' === $c->status ? 'ain_pause_campaign' : 'ain_activate_campaign'; ?>" data-id="<?php echo esc_attr( $c->id ); ?>"><span class="dashicons <?php echo 'active' === $c->status ? 'dashicons-controls-pause' : 'dashicons-yes'; ?>"></span></button>
                        <button class="ain-icon-btn ain-icon-danger ain-js-action" title="Delete" data-confirm="Delete this campaign and its queue items?" data-action="ain_delete_campaign" data-id="<?php echo esc_attr( $c->id ); ?>"><span class="dashicons dashicons-trash"></span></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function campaign_edit() {
        $id = isset( $_GET['campaign_id'] ) ? (int) $_GET['campaign_id'] : 0;
        $campaign = $id ? AIN_Campaigns::get( $id ) : null;
        $type = $campaign ? $campaign->type : ( isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'rss' );
        $defaults = AIN_Campaigns::defaults_for_type( $type );
        if ( ! $campaign ) {
            $campaign = (object) array(
                'id' => 0,
                'name' => '',
                'type' => $type,
                'status' => 'paused',
                'source_config' => $defaults['source_config'],
                'ai_config' => $defaults['ai_config'],
                'publishing_config' => $defaults['publishing_config'],
                'media_config' => $defaults['media_config'],
                'social_config' => $defaults['social_config'],
                'schedule_config' => $defaults['schedule_config'],
            );
        }
        self::shell_start( $id ? 'Edit Campaign' : 'Add New Campaign', 'Each campaign runs separately with its own source, AI, schedule, media, publishing, and social settings.' );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ain-form">
            <?php wp_nonce_field( 'ain_save_campaign' ); ?>
            <input type="hidden" name="action" value="ain_save_campaign">
            <input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign->id ); ?>">

            <div class="ain-card ain-campaign-hero">
                <div class="ain-grid ain-grid-4">
                    <?php self::input( 'name', 'Campaign Name', $campaign->name, 'text', 'Malta Politics RSS' ); ?>
                    <?php self::select_raw( 'type', 'Campaign Type', ain_campaign_types(), $campaign->type ); ?>
                    <?php self::select_raw( 'status', 'Status', array( 'paused' => 'Paused', 'active' => 'Active' ), $campaign->status ); ?>
                    <?php self::input( 'interval_minutes', 'Run Every Minutes', $campaign->schedule_config['interval_minutes'], 'number' ); ?>
                </div>
            </div>

            <div class="ain-tabs">
                <button type="button" class="is-active" data-tab="source">1 Source</button>
                <button type="button" data-tab="ai">2 AI Reporter</button>
                <button type="button" data-tab="publish">3 Publishing</button>
                <button type="button" data-tab="media">4 Media/Social</button>
            </div>

            <div class="ain-tab-panel is-active" data-panel="source">
                <div class="ain-grid ain-grid-2">
                    <div class="ain-card"><h2>Source Inputs</h2>
                        <?php self::input( 'topic_query', 'Topic / Search Query', $campaign->source_config['topic_query'] ); ?>
                        <?php self::textarea( 'rss_feeds', 'RSS Feeds', $campaign->source_config['rss_feeds'], 'One feed per line. Used by RSS campaigns and PR RSS sources.' ); ?>
                        <?php self::textarea( 'urls', 'Website / Firecrawl URLs', $campaign->source_config['urls'], 'One category/listing URL per line.' ); ?>
                        <?php self::textarea( 'press_release_urls', 'Press Release Sources', $campaign->source_config['press_release_urls'], 'PR feeds or listing pages, one per line.' ); ?>
                        <?php self::textarea( 'manual_urls', 'Manual URLs', $campaign->source_config['manual_urls'], 'For manual research campaign.' ); ?>
                    </div>
                    <div class="ain-card"><h2>Discovery Filters</h2>
                        <?php self::input( 'youtube_query', 'YouTube Query', $campaign->source_config['youtube_query'] ); ?>
                        <div class="ain-grid-inline"><?php self::input( 'country_code', 'Country Code', $campaign->source_config['country_code'] ); ?><?php self::input( 'language_code', 'Language Code', $campaign->source_config['language_code'] ); ?></div>
                        <?php self::input( 'max_items', 'Max Items Per Run', $campaign->source_config['max_items'], 'number' ); ?>
                        <?php self::textarea( 'include_domains', 'Only Include Domains', $campaign->source_config['include_domains'], 'Optional: one domain per line.' ); ?>
                        <?php self::textarea( 'exclude_domains', 'Exclude Domains', $campaign->source_config['exclude_domains'], 'Optional: one domain per line.' ); ?>
                        <?php self::input( 'active_hours', 'Active Hours', $campaign->schedule_config['active_hours'], 'text', 'Example: 8-23, blank = all day' ); ?>
                    </div>
                </div>
            </div>

            <div class="ain-tab-panel" data-panel="ai">
                <div class="ain-grid ain-grid-2">
                    <div class="ain-card"><h2>AI Reporter Brain</h2>
                        <?php self::textarea( 'tone', 'Campaign Tone / Voice', $campaign->ai_config['tone'] ); ?>
                        <?php self::textarea( 'editor_prompt', 'Editor Prompt', $campaign->ai_config['editor_prompt'] ); ?>
                    </div>
                    <div class="ain-card"><h2>Writing Rules</h2>
                        <?php self::textarea( 'writer_prompt', 'Writer Prompt', $campaign->ai_config['writer_prompt'] ); ?>
                        <?php self::select_raw( 'story_strategy', 'Story Strategy', array( 'source_first' => 'Source-first: 1 source = 1 article', 'smart' => 'Smart Story Grouping', 'aggressive' => 'Aggressive Newsroom Mode' ), $campaign->ai_config['story_strategy'] ); ?>
                        <?php self::select_raw( 'research_depth', 'Research Depth', array( 'fast' => 'Fast', 'balanced' => 'Balanced', 'deep' => 'Deep' ), $campaign->ai_config['research_depth'] ); ?>
                        <?php self::checkbox( 'enable_research_pack', 'Build Research Pack Before Writing', $campaign->ai_config['enable_research_pack'] ); ?>
                        <?php self::checkbox( 'enable_web_search', 'Use OpenRouter Web Search In Research Pack', $campaign->ai_config['enable_web_search'] ); ?>
                        <?php self::input( 'search_result_count', 'Max Outside Sources For Research', $campaign->ai_config['search_result_count'], 'number' ); ?>
                        <?php self::input( 'temperature', 'Creativity / Temperature', $campaign->ai_config['temperature'] ); ?>
                    </div>
                </div>
            </div>

            <div class="ain-tab-panel" data-panel="publish">
                <div class="ain-grid ain-grid-2">
                    <div class="ain-card"><h2>Publishing Rules</h2>
                        <?php self::select_raw( 'publish_mode', 'Publishing Mode', array( 'draft' => 'Draft', 'pending' => 'Pending Review', 'publish' => 'Publish Immediately' ), $campaign->publishing_config['publish_mode'] ); ?>
                        <?php self::input( 'min_quality_score', 'Minimum Quality Score', $campaign->publishing_config['min_quality_score'], 'number' ); ?>
                        <?php self::input( 'words_target', 'Target Word Count', $campaign->publishing_config['words_target'], 'number' ); ?>
                        <?php self::input( 'max_posts_per_run', 'Max Posts Written Per Run', $campaign->publishing_config['max_posts_per_run'], 'number' ); ?>
                        <?php self::input( 'max_posts_per_day', 'Max Posts Per Day', $campaign->publishing_config['max_posts_per_day'], 'number' ); ?>
                    </div>
                    <div class="ain-card"><h2>Author & Category Logic</h2>
                        <?php self::select_raw( 'author_mode', 'Author Mode', array( 'auto' => 'AI Chooses Author', 'fixed' => 'Use Fixed Author' ), $campaign->publishing_config['author_mode'] ); ?>
                        <?php self::select_users( 'default_author', 'Fixed Author', $campaign->publishing_config['default_author'] ); ?>
                        <?php self::select_raw( 'category_mode', 'Category Mode', array( 'auto' => 'AI Chooses Category', 'fixed' => 'Use Fixed Category' ), $campaign->publishing_config['category_mode'] ); ?>
                        <?php self::select_categories( 'default_category', 'Fixed Category', $campaign->publishing_config['default_category'] ); ?>
                    </div>
                </div>
            </div>

            <div class="ain-tab-panel" data-panel="media">
                <div class="ain-grid ain-grid-2">
                    <div class="ain-card"><h2>Media Engine</h2>
                        <?php self::checkbox( 'generate_images', 'Generate / Sideload Featured Image', $campaign->media_config['generate_images'] ); ?>
                        <?php self::checkbox( 'use_source_image', 'Prefer Source Image When Available', $campaign->media_config['use_source_image'] ); ?>
                        <?php self::checkbox( 'use_pexels', 'Use Pexels Free Image Search', $campaign->media_config['use_pexels'] ); ?>
                        <?php self::checkbox( 'use_openrouter_image', 'Use OpenRouter Image Generation When Needed', $campaign->media_config['use_openrouter_image'] ); ?>
                        <?php self::checkbox( 'insert_inline_media', 'Allow Charts, Tables & Inline Media', $campaign->media_config['insert_inline_media'] ); ?>
                        <?php self::select_raw( 'image_aspect_ratio', 'OpenRouter Image Aspect Ratio', array( '16:9' => '16:9 Wide', '4:3' => '4:3 Standard', '1:1' => '1:1 Square', '9:16' => '9:16 Vertical' ), $campaign->media_config['image_aspect_ratio'] ); ?>
                        <?php self::select_raw( 'image_size', 'OpenRouter Image Size', array( '0.5K' => '0.5K', '1K' => '1K', '2K' => '2K', '4K' => '4K' ), $campaign->media_config['image_size'] ); ?>
                        <?php self::input( 'image_style', 'Image Style Prompt', $campaign->media_config['image_style'] ); ?>
                    </div>
                    <div class="ain-card"><h2>Social / Hook</h2>
                        <?php self::checkbox( 'generate_social', 'Generate Social Captions', $campaign->social_config['generate_social'] ); ?>
                        <?php self::input( 'social_hook_action', 'Optional Social Plugin Hook Action', $campaign->social_config['social_hook_action'], 'text', 'example: my_social_plugin_share' ); ?>
                        <?php self::checkbox( 'run_writer', 'Auto-write After Campaign Finds Stories', $campaign->schedule_config['run_writer'] ); ?>
                    </div>
                </div>
            </div>

            <p class="ain-sticky-save"><button type="submit" class="ain-btn ain-btn-primary ain-btn-large">Save Campaign</button></p>
        </form>
        <?php
        self::shell_end();
    }

    public static function save_campaign() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ain_save_campaign' ) ) wp_die( 'Unauthorized.' );
        $id = AIN_Campaigns::save_from_request( $_POST );
        $url = admin_url( 'admin.php?page=ain-campaigns&ain_saved=1' );
        if ( ! is_wp_error( $id ) ) $url = admin_url( 'admin.php?page=ain-campaign-edit&campaign_id=' . (int) $id . '&ain_saved=1' );
        wp_safe_redirect( $url );
        exit;
    }

    public static function queue() {
        $campaign_id = isset( $_GET['campaign_id'] ) ? (int) $_GET['campaign_id'] : 0;
        $status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        self::shell_start( 'Story Queue', 'Review planned, drafted, published, and failed items across campaigns.' );
        echo '<div class="ain-card ain-filterbar"><form method="get"><input type="hidden" name="page" value="ain-queue">';
        self::select_campaign_filter( $campaign_id );
        echo '<select name="status"><option value="">All statuses</option>';
        foreach ( array( 'queued','writing','planned','approved','drafted','needs_review','published','failed','rejected' ) as $st ) echo '<option value="' . esc_attr( $st ) . '" ' . selected( $status, $st, false ) . '>' . esc_html( ain_status_label( $st ) ) . '</option>';
        echo '</select><button class="ain-btn">Filter</button></form></div>';
        self::queue_table( AIN_DB::queue_items( array( 'campaign_id' => $campaign_id, 'status' => $status, 'limit' => 200 ) ), true );
        self::shell_end();
    }

    private static function queue_item_sources( $item, $raw ) {
        $sources = array();
        if ( is_array( $raw ) && ! empty( $raw['sources'] ) && is_array( $raw['sources'] ) ) {
            foreach ( $raw['sources'] as $src ) {
                if ( empty( $src['url'] ) ) continue;
                $sources[] = array(
                    'title'     => sanitize_text_field( $src['title'] ?? ( $src['source_name'] ?? ain_safe_url_host( $src['url'] ) ) ),
                    'url'       => esc_url_raw( $src['url'] ),
                    'publisher' => sanitize_text_field( $src['source_name'] ?? ain_safe_url_host( $src['url'] ) ),
                );
            }
        }
        if ( empty( $sources ) && ! empty( $item->source_url ) ) {
            $sources[] = array(
                'title'     => sanitize_text_field( $item->source_title ?: $item->source_name ),
                'url'       => esc_url_raw( $item->source_url ),
                'publisher' => sanitize_text_field( $item->source_name ?: ain_safe_url_host( $item->source_url ) ),
            );
        }
        $seen = array();
        $out = array();
        foreach ( $sources as $src ) {
            if ( empty( $src['url'] ) || isset( $seen[ $src['url'] ] ) ) continue;
            $seen[ $src['url'] ] = true;
            $out[] = $src;
        }
        return $out;
    }

    private static function render_source_cell( $item, $raw, $cluster_count = 1 ) {
        $sources = self::queue_item_sources( $item, $raw );
        $count   = max( 1, count( $sources ), (int) $cluster_count );
        $label   = $count > 1 ? 'Multisource' : ( $item->source_name ?: 'Source' );
        $target  = 'ain-sources-' . (int) $item->id;
        echo '<span class="ain-source-name">' . esc_html( $label ) . '</span>';
        if ( ! empty( $sources ) ) {
            echo '<br><a href="#" class="ain-source-toggle" data-target="' . esc_attr( $target ) . '">Open source/s (' . esc_html( count( $sources ) ) . ')</a>';
            echo '<div id="' . esc_attr( $target ) . '" class="ain-source-list"><ul>';
            foreach ( $sources as $src ) {
                $title = $src['title'] ?: ( $src['publisher'] ?: ain_safe_url_host( $src['url'] ) );
                echo '<li><a href="' . esc_url( $src['url'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html( wp_trim_words( $title, 12 ) ) . '</a>';
                if ( ! empty( $src['publisher'] ) ) echo '<span class="ain-source-publisher">' . esc_html( $src['publisher'] ) . '</span>';
                echo '</li>';
            }
            echo '</ul></div>';
        }
    }

    private static function queue_table( $items, $show_campaign = true, $compact = false ) {
        ?>
        <table class="ain-table <?php echo $compact ? 'ain-compact-table' : ''; ?>">
            <?php if ( $compact ) : ?>
                <thead><tr><th>Story label</th><th>Source cluster</th><th>Research status</th><th>Quality score</th><th>Author/category</th><th>Background job</th><th></th></tr></thead>
            <?php else : ?>
                <thead><tr><?php if ( $show_campaign ) : ?><th>Campaign</th><?php endif; ?><th>Story</th><th>Source</th><th>Score</th><th>Author / Category</th><th>Status</th><th>Actions</th></tr></thead>
            <?php endif; ?>
            <tbody>
            <?php if ( empty( $items ) ) : ?><tr><td colspan="<?php echo $compact ? 7 : 7; ?>" class="ain-muted">No items found.</td></tr><?php endif; ?>
            <?php foreach ( $items as $item ) : ?>
                <?php
                $raw = ain_decode_json_field( $item->raw_payload );
                $cluster_count = 1;
                if ( is_array( $raw ) && ! empty( $raw['sources'] ) && is_array( $raw['sources'] ) ) $cluster_count = count( $raw['sources'] );
                elseif ( is_array( $raw ) && ! empty( $raw['story_cluster']['source_ids'] ) && is_array( $raw['story_cluster']['source_ids'] ) ) $cluster_count = count( $raw['story_cluster']['source_ids'] );
                $cat = get_term( (int) $item->category_id, 'category' );
                $author_name = get_the_author_meta( 'display_name', (int) $item->author_id ) ?: 'Auto';
                $cat_name = $cat && ! is_wp_error( $cat ) ? $cat->name : 'Auto';
                ?>
                <tr id="ain-row-<?php echo esc_attr( $item->id ); ?>">
                    <?php if ( $compact ) : ?>
                        <td><span class="ain-label-chip">Working story</span><strong><?php echo esc_html( wp_trim_words( $item->suggested_title ?: $item->source_title, 9 ) ); ?></strong></td>
                        <td><span class="ain-pill-soft"><?php echo esc_html( $cluster_count ); ?> source<?php echo 1 === $cluster_count ? '' : 's'; ?></span></td>
                        <td><span class="dashicons dashicons-admin-site"></span> <?php echo ! empty( $item->research_pack ) ? 'Research ready' : 'Research…'; ?></td>
                        <td><div class="ain-score"><span style="width:<?php echo esc_attr( max(0,min(100,(int)$item->quality_score)) ); ?>%"></span></div></td>
                        <td><small><?php echo esc_html( $author_name ); ?><br><?php echo esc_html( $cat_name ); ?></small></td>
                        <td class="ain-row-status"><?php if ( 'writing' === $item->status || 'queued' === $item->status ) : ?><span class="ain-spinner ain-spinner-dark"></span><?php elseif ( 'published' === $item->status ) : ?><span class="ain-checkmark">✓</span><?php elseif ( 'failed' === $item->status ) : ?><span class="ain-warning">!</span><?php else : ?><span class="ain-status ain-status-<?php echo esc_attr( $item->status ); ?>"><?php echo esc_html( ain_status_label( $item->status ) ); ?></span><?php endif; ?></td>
                        <td><button class="ain-btn ain-btn-dark ain-js-action" data-action="ain_write_item" data-id="<?php echo esc_attr( $item->id ); ?>">Write</button> <a class="ain-btn ain-btn-light" href="<?php echo esc_url( admin_url( 'admin.php?page=ain-studio&item_id=' . $item->id ) ); ?>">Studio</a></td>
                    <?php else : ?>
                        <?php if ( $show_campaign ) : ?><td><strong><?php echo esc_html( $item->campaign_name ?: '#' . $item->campaign_id ); ?></strong><br><small><?php echo esc_html( ain_campaign_types()[ $item->campaign_type ] ?? $item->campaign_type ); ?></small></td><?php endif; ?>
                        <td><strong><?php echo esc_html( $item->suggested_title ?: $item->source_title ); ?></strong><br><small><?php echo esc_html( wp_trim_words( $item->ai_summary, 22 ) ); ?></small></td>
                        <td><?php self::render_source_cell( $item, $raw, $cluster_count ); ?></td>
                        <td><div class="ain-score"><span style="width:<?php echo esc_attr( max(0,min(100,(int)$item->quality_score)) ); ?>%"></span></div><small><?php echo esc_html( (int) $item->quality_score ); ?>/100</small></td>
                        <td><?php echo esc_html( $author_name ); ?><br><small><?php echo esc_html( $cat_name ); ?></small></td>
                        <td class="ain-row-status"><span class="ain-status ain-status-<?php echo esc_attr( $item->status ); ?>"><?php echo esc_html( ain_status_label( $item->status ) ); ?></span><?php if ( $item->post_id ) : ?><br><a href="<?php echo esc_url( get_edit_post_link( $item->post_id ) ); ?>">Edit post</a><?php endif; ?><?php if ( $item->error_message ) : ?><br><small class="ain-error"><?php echo esc_html( $item->error_message ); ?></small><?php endif; ?></td>
                        <td class="ain-actions-cell"><button class="ain-btn ain-btn-success ain-js-action" data-action="ain_write_item" data-id="<?php echo esc_attr( $item->id ); ?>">Write</button><a class="ain-btn ain-btn-light" href="<?php echo esc_url( admin_url( 'admin.php?page=ain-studio&item_id=' . $item->id ) ); ?>">Studio</a><button class="ain-btn ain-btn-danger ain-js-action" data-confirm="Delete this queue item?" data-action="ain_delete_item" data-id="<?php echo esc_attr( $item->id ); ?>">Delete</button></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function studio() {
        $id = isset( $_GET['item_id'] ) ? (int) $_GET['item_id'] : 0;
        $item = $id ? AIN_DB::get_item( $id ) : null;
        self::shell_start( 'Article Studio', 'Inspect a campaign queue item, research pack, raw payload, and generation status.' );
        if ( ! $item ) { echo '<div class="ain-card"><p class="ain-muted">Choose an item from the Story Queue.</p></div>'; self::shell_end(); return; }
        $campaign = AIN_Campaigns::get( $item->campaign_id );
        ?>
        <div class="ain-grid ain-grid-2">
            <div class="ain-card"><h2>Assignment</h2><p><strong>Campaign:</strong> <?php echo esc_html( $campaign ? $campaign->name : '#' . $item->campaign_id ); ?></p><h3><?php echo esc_html( $item->suggested_title ?: $item->source_title ); ?></h3><p><?php echo nl2br( esc_html( $item->ai_summary ) ); ?></p><button class="ain-btn ain-btn-success ain-js-action" data-action="ain_write_item" data-id="<?php echo esc_attr( $item->id ); ?>">Generate / Regenerate</button></div>
            <div class="ain-card"><h2>Source</h2><p><strong><?php echo esc_html( $item->source_title ); ?></strong></p><p><?php echo esc_html( $item->source_excerpt ); ?></p><?php if ( $item->source_url ) : ?><p><a href="<?php echo esc_url( $item->source_url ); ?>" target="_blank" rel="noopener">Open source</a></p><?php endif; ?><p><strong>Status:</strong> <?php echo esc_html( ain_status_label( $item->status ) ); ?> · <strong>Score:</strong> <?php echo esc_html( $item->quality_score ); ?>/100</p></div>
        </div>
        <div class="ain-card"><h2>Research Pack / Raw Data</h2><pre class="ain-pre"><?php echo esc_html( wp_json_encode( array( 'research_pack' => ain_decode_json_field( $item->research_pack ), 'raw_payload' => ain_decode_json_field( $item->raw_payload ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></div>
        <?php
        self::shell_end();
    }

    public static function logs() {
        global $wpdb;
        $campaign_id = isset( $_GET['campaign_id'] ) ? (int) $_GET['campaign_id'] : 0;
        self::shell_start( 'Logs', 'Campaign runs, API errors, discovery events, and article generation history.' );
        echo '<div class="ain-card ain-filterbar"><form method="get"><input type="hidden" name="page" value="ain-logs">'; self::select_campaign_filter( $campaign_id ); echo '<button class="ain-btn">Filter</button></form></div>';
        $where = $campaign_id ? $wpdb->prepare( 'WHERE l.campaign_id = %d', $campaign_id ) : '';
        $logs = $wpdb->get_results( "SELECT l.*, c.name AS campaign_name FROM " . ain_table( 'logs' ) . " l LEFT JOIN " . ain_table( 'campaigns' ) . " c ON c.id = l.campaign_id {$where} ORDER BY l.created_at DESC LIMIT 200" );
        echo '<div class="ain-card ain-table-card"><table class="ain-table"><thead><tr><th>Date</th><th>Campaign</th><th>Level</th><th>Message</th><th>Context</th></tr></thead><tbody>';
        if ( empty( $logs ) ) echo '<tr><td colspan="5" class="ain-muted">No logs yet.</td></tr>';
        foreach ( $logs as $log ) {
            echo '<tr><td>' . esc_html( mysql2date( 'M j, H:i', $log->created_at ) ) . '</td><td>' . esc_html( $log->campaign_name ?: '—' ) . '</td><td><span class="ain-pill ain-pill-' . esc_attr( $log->level ) . '">' . esc_html( $log->level ) . '</span></td><td>' . esc_html( $log->message ) . '</td><td><pre class="ain-mini-pre">' . esc_html( $log->context ) . '</pre></td></tr>';
        }
        echo '</tbody></table></div>';
        self::shell_end();
    }

    public static function settings() {
        $s = ain_get_settings();
        self::shell_start( 'Settings', 'Global API keys and defaults. Campaign-specific settings override these.' );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ain-form">
            <?php wp_nonce_field( 'ain_save_settings' ); ?><input type="hidden" name="action" value="ain_save_settings">
            <div class="ain-grid ain-grid-2">
                <div class="ain-card"><h2>API Keys</h2><?php self::input( 'openrouter_api_key', 'OpenRouter API Key', $s['openrouter_api_key'], 'password' ); ?><?php self::input( 'perplexity_api_key', 'Perplexity API Key', $s['perplexity_api_key'], 'password' ); ?><?php self::input( 'gnews_api_key', 'GNews API Key', $s['gnews_api_key'], 'password' ); ?><?php self::input( 'firecrawl_api_key', 'Firecrawl API Key', $s['firecrawl_api_key'], 'password' ); ?><?php self::input( 'youtube_api_key', 'YouTube API Key', $s['youtube_api_key'], 'password' ); ?><?php self::input( 'pexels_api_key', 'Pexels API Key', $s['pexels_api_key'], 'password' ); ?></div>
                <div class="ain-card"><h2>Models & Defaults</h2><?php self::input( 'openrouter_text_model', 'Fallback OpenRouter Text Model', $s['openrouter_text_model'] ); ?><?php self::input( 'openrouter_research_model', 'Research / Story Desk Model', $s['openrouter_research_model'] ); ?><?php self::input( 'openrouter_writer_model', 'Article Writer Model', $s['openrouter_writer_model'] ); ?><?php self::input( 'openrouter_image_model', 'OpenRouter Image Model', $s['openrouter_image_model'] ); ?><?php self::select_raw( 'openrouter_image_aspect_ratio', 'Default Image Aspect Ratio', array( '16:9' => '16:9 Wide', '4:3' => '4:3 Standard', '1:1' => '1:1 Square', '9:16' => '9:16 Vertical' ), $s['openrouter_image_aspect_ratio'] ); ?><?php self::select_raw( 'openrouter_image_size', 'Default Image Size', array( '0.5K' => '0.5K', '1K' => '1K', '2K' => '2K', '4K' => '4K' ), $s['openrouter_image_size'] ); ?><?php self::input( 'perplexity_model', 'Perplexity Model', $s['perplexity_model'] ); ?><?php self::select_raw( 'default_story_strategy', 'Default Story Strategy', array( 'source_first' => 'Source-first', 'smart' => 'Smart Story Grouping', 'aggressive' => 'Aggressive Newsroom Mode' ), $s['default_story_strategy'] ); ?><?php self::checkbox( 'enable_openrouter_web_search', 'Enable OpenRouter Web Search By Default', $s['enable_openrouter_web_search'] ); ?><?php self::input( 'default_search_result_count', 'Default Outside Sources Per Research Pack', $s['default_search_result_count'], 'number' ); ?><?php self::select_raw( 'default_publish_mode', 'Default Publish Mode', array( 'draft' => 'Draft', 'pending' => 'Pending Review', 'publish' => 'Publish Immediately' ), $s['default_publish_mode'] ); ?><?php self::input( 'default_min_quality', 'Default Minimum Quality', $s['default_min_quality'], 'number' ); ?><?php self::input( 'default_words_target', 'Default Word Count', $s['default_words_target'], 'number' ); ?></div>
            </div>
            <div class="ain-grid ain-grid-2"><div class="ain-card"><h2>Global Voice</h2><?php self::textarea( 'site_voice', 'Site Voice', $s['site_voice'] ); ?><?php self::textarea( 'editor_prompt', 'Default Editor Prompt', $s['editor_prompt'] ); ?><?php self::textarea( 'writer_prompt', 'Default Writer Prompt', $s['writer_prompt'] ); ?></div><div class="ain-card"><h2>SEO / Internal Links</h2><?php self::checkbox( 'enable_internal_links', 'Enable Internal Link Suggestions', $s['enable_internal_links'] ); ?><?php self::input( 'max_internal_links', 'Max Internal Links', $s['max_internal_links'], 'number' ); ?><?php self::select_users( 'default_author', 'Default Author', $s['default_author'] ); ?><?php self::select_categories( 'default_category', 'Default Category', $s['default_category'] ); ?></div></div>
            <p><button type="submit" class="ain-btn ain-btn-primary ain-btn-large">Save Settings</button></p>
        </form>
        <?php
        self::shell_end();
    }

    public static function save_settings() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ain_save_settings' ) ) wp_die( 'Unauthorized.' );
        ain_update_settings( $_POST );
        wp_safe_redirect( admin_url( 'admin.php?page=ain-settings&ain_saved=1' ) ); exit;
    }

    private static function input( $name, $label, $value, $type = 'text', $placeholder = '' ) { ?>
        <label class="ain-field"><span><?php echo esc_html( $label ); ?></span><input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"></label>
    <?php }
    private static function textarea( $name, $label, $value, $help = '' ) { ?>
        <label class="ain-field"><span><?php echo esc_html( $label ); ?></span><textarea name="<?php echo esc_attr( $name ); ?>"><?php echo esc_textarea( $value ); ?></textarea><?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?></label>
    <?php }
    private static function checkbox( $name, $label, $checked ) { ?>
        <label class="ain-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( 1, (int) $checked ); ?>> <span><?php echo esc_html( $label ); ?></span></label>
    <?php }
    private static function select_raw( $name, $label, $options, $value ) { ?>
        <label class="ain-field"><span><?php echo esc_html( $label ); ?></span><select name="<?php echo esc_attr( $name ); ?>"><?php foreach ( $options as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $value, (string) $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></label>
    <?php }
    private static function select_users( $name, $label, $value ) {
        $opts = array( 'auto' => 'Automatic / Current User' ); foreach ( get_users() as $u ) $opts[ $u->ID ] = $u->display_name; self::select_raw( $name, $label, $opts, $value );
    }
    private static function select_categories( $name, $label, $value ) {
        $opts = array( 'auto' => 'Automatic / Default Category' ); foreach ( get_categories( array( 'hide_empty' => 0 ) ) as $c ) $opts[ $c->term_id ] = $c->name; self::select_raw( $name, $label, $opts, $value );
    }
    private static function select_campaign_filter( $selected ) {
        echo '<select name="campaign_id"><option value="0">All campaigns</option>'; foreach ( AIN_Campaigns::all() as $c ) echo '<option value="' . esc_attr( $c->id ) . '" ' . selected( (int) $selected, (int) $c->id, false ) . '>' . esc_html( $c->name ) . '</option>'; echo '</select>';
    }

    public static function user_fields( $user ) { ?>
        <h2>AI Newsroom Author Voice</h2><table class="form-table"><tr><th><label for="ain_author_beats">Reporter Beats</label></th><td><input type="text" class="regular-text" name="ain_author_beats" id="ain_author_beats" value="<?php echo esc_attr( ain_author_beats( $user->ID ) ); ?>"><p class="description">Example: politics, crime, economy, tourism.</p></td></tr><tr><th><label for="ain_author_tone">Author Tone Prompt</label></th><td><textarea class="large-text" rows="5" name="ain_author_tone" id="ain_author_tone"><?php echo esc_textarea( ain_author_tone( $user->ID ) ); ?></textarea><p class="description">Used when AI assigns this author to a story.</p></td></tr></table>
    <?php }
    public static function save_user_fields( $user_id ) { if ( ! current_user_can( 'edit_user', $user_id ) ) return; update_user_meta( $user_id, 'ain_author_beats', sanitize_text_field( $_POST['ain_author_beats'] ?? '' ) ); update_user_meta( $user_id, 'ain_author_tone', sanitize_textarea_field( $_POST['ain_author_tone'] ?? '' ) ); }
    public static function category_add_fields() { ?><div class="form-field"><label for="ain_category_rules">AI Newsroom Category Rules</label><textarea name="ain_category_rules" id="ain_category_rules" rows="5"></textarea><p>Rules for AI category assignment and tone.</p></div><?php }
    public static function category_edit_fields( $term ) { ?><tr class="form-field"><th><label for="ain_category_rules">AI Newsroom Category Rules</label></th><td><textarea name="ain_category_rules" id="ain_category_rules" rows="5" class="large-text"><?php echo esc_textarea( ain_category_rules( $term->term_id ) ); ?></textarea><p class="description">Example: Use for local politics only. Avoid crime stories. Tone must be formal and neutral.</p></td></tr><?php }
    public static function save_category_fields( $term_id ) { if ( isset( $_POST['ain_category_rules'] ) ) update_term_meta( $term_id, 'ain_category_rules', sanitize_textarea_field( $_POST['ain_category_rules'] ) ); }
}
