<?php
namespace Substack_Importer;

if (!defined('ABSPATH')) { exit; }

class Admin {

    /** @var Plugin */
    protected $plugin;

    public function __construct(Plugin $plugin) {
        $this->plugin = $plugin;
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        add_filter('post_row_actions', [$this, 'row_action_push_linkedin'], 10, 2);
        add_filter('display_post_states', [$this, 'add_post_state_badge'], 10, 2);
        add_action('admin_footer', [$this, 'enable_revision_comparison']);
        add_filter('wp_revisions_to_keep', [$this, 'ensure_revision_comparison_default'], 10, 2);
        add_action('admin_head', [$this, 'force_revision_comparison_default']);
    
    }

    public function register_menu() {
        // Only show menu to Administrators and Editors
        if (!current_user_can(\SSI_OOP_CAP)) {
            return;
        }
        
        add_menu_page(
            esc_html__('Substack Importer', 'substack-importer'),
            esc_html__('Substack Importer', 'substack-importer'),
            \SSI_OOP_CAP,
            'substack-importer',
            [$this, 'render_page'],
            'dashicons-rss',
            65
        );
        add_submenu_page(
            'substack-importer',
            esc_html__('Import Log', 'substack-importer'),
            esc_html__('Import Log', 'substack-importer'),
            \SSI_OOP_CAP,
            'substack-importer-log',
            [$this->plugin->logger, 'render_log_page']
        );
        add_submenu_page(
            'substack-importer',
            esc_html__('Imported Posts', 'substack-importer'),
            esc_html__('Imported Posts', 'substack-importer'),
            \SSI_OOP_CAP,
            'substack-importer-imported',
            [$this, 'render_imported_page']
        );
    }

    public function register_settings() {
        register_setting('substack_importer_settings_group', 'substack_importer_feed_urls');
        register_setting('substack_importer_settings_group', 'substack_importer_cron_enabled');
        register_setting('substack_importer_settings_group', 'substack_importer_cron_interval');
        register_setting('substack_importer_settings_group', 'substack_importer_cron_interval_unit');
        register_setting('substack_importer_settings_group', 'substack_importer_cron_import_limit');
        register_setting('substack_importer_settings_group', 'substack_importer_cron_offset');
        register_setting('substack_importer_settings_group', 'substack_importer_enhanced_gutenberg');
        register_setting('substack_importer_settings_group', 'substack_importer_cron_check_updates');
        register_setting('substack_importer_settings_group', 'substack_importer_cron_auto_resync');
        register_setting('substack_importer_settings_group', 'substack_importer_utm_enabled');
        register_setting('substack_importer_settings_group', 'substack_importer_utm_source');
        register_setting('substack_importer_settings_group', 'substack_importer_utm_medium');
        register_setting('substack_importer_settings_group', 'substack_importer_utm_campaign_template');
        register_setting('substack_importer_settings_group', 'substack_importer_utm_external_only');
        register_setting('substack_importer_settings_group', 'substack_importer_utm_domain_whitelist');
        register_setting('substack_importer_settings_group', 'substack_importer_utm_rules');
        register_setting('substack_importer_settings_group', 'substack_importer_default_status', [
            'type' => 'string',
            'sanitize_callback' => function($v){ return in_array($v, ['draft','publish'], true) ? $v : 'draft'; },
            'default' => 'draft',
        ]);
        register_setting('substack_importer_settings_group', 'substack_importer_term_map', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_term_map'],
            'default' => [],
        ]);
        register_setting('substack_importer_settings_group', 'substack_importer_clear_on_uninstall', [
            'type' => 'integer',
            'sanitize_callback' => function($v){ return (int)!!$v; },
            'default' => 0,
        ]);
    }



    public function sanitize_term_map($raw) {
        $rows = [];
        if (isset($raw['label'], $raw['term_id'], $raw['type']) && is_array($raw['label']) && is_array($raw['term_id']) && is_array($raw['type'])) {
            $count = min(count($raw['label']), count($raw['term_id']), count($raw['type']));
            for ($i=0; $i<$count; $i++) {
                $label = sanitize_text_field($raw['label'][$i] ?? '');
                $tid   = intval($raw['term_id'][$i] ?? 0);
                $type  = sanitize_key($raw['type'][$i] ?? 'exact');
                if ($label === '' || $tid <= 0) continue;
                if (!in_array($type, ['exact','ci','regex'], true)) $type = 'exact';
                $term = get_term($tid, 'category');
                if ($term && !is_wp_error($term)) {
                    if ($type === 'regex') {
                        $pattern = '#' . str_replace('#','\\#',$label) . '#u';
                        if (@preg_match($pattern, '') === false) continue;
                    }
                    $rows[] = ['label'=>$label,'term_id'=>(int)$term->term_id,'type'=>$type];
                }
            }
        } elseif (is_array($raw) && $raw) {
            foreach ($raw as $k=>$v) {
                if (is_array($v) && isset($v['label'], $v['term_id'])) {
                    $label = sanitize_text_field($v['label']);
                    $tid   = intval($v['term_id']);
                    $type  = isset($v['type']) ? sanitize_key($v['type']) : 'exact';
                } else {
                    $label = sanitize_text_field($k);
                    $tid   = intval($v);
                    $type  = 'exact';
                }
                if ($label !== '' && $tid > 0 && get_term($tid, 'category')) {
                    $rows[] = ['label'=>$label, 'term_id'=>$tid, 'type'=>$type];
                }
            }
        }
        return $rows;
    }

    public function enqueue_assets($hook) {
        if (!in_array($hook, ['toplevel_page_substack-importer','substack-importer_page_substack-importer-log','substack-importer_page_substack-importer-imported','edit.php'], true)) return;
        wp_enqueue_style('substack-importer-admin', \SSI_OOP_URL.'assets/admin.css', [], \SSI_OOP_VER);
        wp_enqueue_script('substack-importer-admin', \SSI_OOP_URL.'assets/admin.js', ['jquery'], \SSI_OOP_VER, true);

        $cats = get_categories(['hide_empty'=>0]);
        $cats_slim = array_map(function($c){ return ['id'=>(int)$c->term_id,'name'=>$c->name]; }, $cats);

        wp_localize_script('substack-importer-admin', 'SubstackImporter', [
            'ajaxurl'              => admin_url('admin-ajax.php'),
            'nonce_fetch'          => wp_create_nonce('substack_importer_fetch'),
            'nonce_import'         => wp_create_nonce('substack_importer_import'),
            'nonce_check'          => wp_create_nonce('substack_importer_check'),
            'nonce_resync'         => wp_create_nonce('substack_importer_resync'),
            'nonce_check_all'      => wp_create_nonce('substack_importer_check_all'),
            'nonce_resync_changed' => wp_create_nonce('substack_importer_resync_changed'),
            'nonce_diff'           => wp_create_nonce('substack_importer_diff'),
            'nonce_reset_offset'   => wp_create_nonce('substack_importer_reset_offset'),
            'categories'           => wp_json_encode($cats_slim),
            'cap_ok'               => $this->plugin->can_use(),
        ]);
    }

    public function render_page() {
        if (!$this->plugin->can_use()) { wp_die(esc_html__('You do not have permission.', 'substack-importer')); }
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        $term_map = get_option('substack_importer_term_map', []);
        $all_cats = get_categories(['hide_empty'=>0]);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Substack Importer', 'substack-importer'); ?></h1>
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=substack-importer&tab=settings')); ?>" class="nav-tab <?php echo $tab==='settings'?'nav-tab-active':''; ?>"><?php echo esc_html__('Settings', 'substack-importer'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=substack-importer&tab=manual')); ?>" class="nav-tab <?php echo $tab==='manual'?'nav-tab-active':''; ?>"><?php echo esc_html__('Manual Import', 'substack-importer'); ?></a>
            </h2>

            <?php if ($tab === 'settings'): ?>
                <?php
                if (isset($_GET['settings-updated'])) {
                    add_settings_error('substack_importer_messages','substack_importer_message', esc_html__('Settings saved.', 'substack-importer'), 'updated');
                }
                settings_errors('substack_importer_messages');
                ?>
                <form method="post" action="options.php" class="ssi-form">
                    <?php settings_fields('substack_importer_settings_group'); ?>

                    <div class="ssi-card">
                        <div class="ssi-card-header">
                            <div>
                                <h2><?php echo esc_html__('General', 'substack-importer'); ?></h2>
                                <p class="desc"><?php echo esc_html__('Add one or more Substack RSS feeds to import from.', 'substack-importer'); ?></p>
                            </div>
                            <div class="ssi-badge"><?php echo esc_html__('Required', 'substack-importer'); ?></div>
                        </div>
                        <div class="ssi-field">
                            <label for="ssi-feed-urls"><?php echo esc_html__('Feed URLs', 'substack-importer'); ?></label>
                            <textarea id="ssi-feed-urls" name="substack_importer_feed_urls" rows="6" placeholder="<?php echo esc_attr__("https://example1.substack.com/feed\nhttps://sportsinvestmentstudio.substack.com/feed", 'substack-importer'); ?>"><?php echo esc_textarea(get_option('substack_importer_feed_urls','')); ?></textarea>
                            <p class="help"><?php printf(esc_html__('One URL per line. Use the full RSS URL (usually ends with %s).', 'substack-importer'), '<code>/feed</code>'); ?></p>
                        </div>
                    </div>














                    <div class="ssi-card">
                        <div class="ssi-card-header">
                            <div>
                                <h2><?php echo esc_html__('Automation', 'substack-importer'); ?></h2>
                                <p class="desc"><?php echo esc_html__('Enable background imports on a repeating schedule.', 'substack-importer'); ?></p>
                            </div>
                            <div class="ssi-badge ssi-badge-optional"><?php echo esc_html__('Optional', 'substack-importer'); ?></div>
                        </div>
                        <div class="ssi-grid">

                            <div class="ssi-field">
                                <label><?php echo esc_html__('Auto Import (Cron)', 'substack-importer'); ?></label>
                                <label class="ssi-switch">
                                    <input type="checkbox" name="substack_importer_cron_enabled" value="1" <?php checked(get_option('substack_importer_cron_enabled'), 1); ?>>
                                    <span class="ssi-slider" aria-hidden="true"></span>
                                    <span class="ssi-switch-label"><?php echo esc_html__('Enabled', 'substack-importer'); ?></span>
                                </label>
                                <p class="help"><?php echo esc_html__('When enabled, WordPress Cron will import new feed items periodically. The cron status shows remaining time in hours and minutes.', 'substack-importer'); ?></p>
                            </div>
                            <div class="ssi-field">
                                <label for="ssi-interval"><?php echo esc_html__('Schedule Interval', 'substack-importer'); ?></label>
                                <div class="ssi-input-group">
                                    <input id="ssi-interval" type="number" min="2" max="600" step="1" name="substack_importer_cron_interval" value="<?php echo esc_attr(get_option('substack_importer_cron_interval', 6)); ?>" class="ssi-input">
                                    <select id="ssi-interval-unit" name="substack_importer_cron_interval_unit" class="ssi-select">
                                        <?php $unit = get_option('substack_importer_cron_interval_unit', 'hours'); ?>
                                        <option value="minutes" <?php selected($unit, 'minutes'); ?>><?php echo esc_html__('Minutes', 'substack-importer'); ?></option>
                                        <option value="hours" <?php selected($unit, 'hours'); ?>><?php echo esc_html__('Hours', 'substack-importer'); ?></option>
                                    </select>
                                </div>
                                <p class="help"><?php echo esc_html__('Range: 2 minutes to 10 hours (600 minutes). For hours, multiply by 60 to get minutes (e.g., 6 hours = 360 minutes).', 'substack-importer'); ?></p>
                            </div>
                            <div class="ssi-field">
                                <label for="ssi-import-limit"><?php echo esc_html__('Import Limit per Run', 'substack-importer'); ?></label>
                                <div class="ssi-input-group">
                                    <input id="ssi-import-limit" type="number" min="1" max="100" step="1" name="substack_importer_cron_import_limit" value="<?php echo esc_attr(get_option('substack_importer_cron_import_limit', 10)); ?>" class="ssi-input">
                                    <span class="ssi-input-suffix"><?php echo esc_html__('posts', 'substack-importer'); ?></span>
                                </div>
                                <p class="help"><?php echo esc_html__('Maximum number of new posts to import per cron job run. Range: 1-100 posts.', 'substack-importer'); ?></p>
                            </div>
                            <div class="ssi-field">
                                <label for="ssi-cron-offset"><?php echo esc_html__('Current Cron Offset', 'substack-importer'); ?></label>
                                <div class="ssi-input-group">
                                    <input id="ssi-cron-offset" type="number" min="0" step="1" name="substack_importer_cron_offset" value="<?php echo esc_attr(get_option('substack_importer_cron_offset', 0)); ?>" class="ssi-input" readonly>
                                    <button type="button" class="button button-secondary" id="ssi-reset-cron-offset">
                                        <?php echo esc_html__('Reset Offset', 'substack-importer'); ?>
                                    </button>
                                </div>
                                <p class="help"><?php echo esc_html__('Current position in the feed for cron imports. Automatically increments to prevent duplicate imports. Reset to start from the beginning.', 'substack-importer'); ?></p>
                            </div>
                            <div class="ssi-field">
                                <label><?php echo esc_html__('Enhanced Gutenberg Support', 'substack-importer'); ?></label>
                                <label class="ssi-switch">
                                    <input type="checkbox" name="substack_importer_enhanced_gutenberg" value="1" <?php checked(get_option('substack_importer_enhanced_gutenberg', 1), 1); ?>>
                                    <span class="ssi-slider" aria-hidden="true"></span>
                                    <span class="ssi-switch-label"><?php echo esc_html__('Enabled', 'substack-importer'); ?></span>
                                </label>
                                <p class="help"><?php echo esc_html__('When enabled, imported content will be fully compatible with Gutenberg editor formatting, including proper block structure, responsive images, and modern HTML elements.', 'substack-importer'); ?></p>
                            </div>
                            <div class="ssi-field">
                                <label><?php echo esc_html__('Cron Status', 'substack-importer'); ?></label>
                                <div class="ssi-cron-status">
                                    <div id="ssi-cron-status-content">
                                        <?php
                                        $next_scheduled = wp_next_scheduled(\SSI_OOP_CRON_HOOK);
                                        if ($next_scheduled) {
                                            $remaining = $next_scheduled - time();
                                            $remaining_formatted = '';
                                            if ($remaining > 0) {
                                                if ($remaining >= 3600) {
                                                    $hours = floor($remaining / 3600);
                                                    $minutes = floor(($remaining % 3600) / 60);
                                                    $remaining_formatted = sprintf(__('%d hours, %d minutes', 'substack-importer'), $hours, $minutes);
                                                } elseif ($remaining >= 60) {
                                                    $minutes = floor($remaining / 60);
                                                    $remaining_formatted = sprintf(__('%d minutes', 'substack-importer'), $minutes);
                                                } else {
                                                    $remaining_formatted = sprintf(__('%d seconds', 'substack-importer'), $remaining);
                                                }
                                            }
                                            
                                            // Get last cron run info
                                            $last_run = get_option('substack_importer_last_cron_run', 0);
                                            $last_import_count = get_option('substack_importer_last_import_count', 0);
                                            
                                            echo '<div class="ssi-status-item"><strong>' . esc_html__('Next Run:', 'substack-importer') . '</strong> ' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_scheduled)) . '</div>';
                                            echo '<div class="ssi-status-item"><strong>' . esc_html__('Remaining:', 'substack-importer') . '</strong> ' . esc_html($remaining_formatted) . '</div>';
                                            
                                            if ($last_run > 0) {
                                                echo '<div class="ssi-status-item"><strong>' . esc_html__('Last Run:', 'substack-importer') . '</strong> ' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_run)) . '</div>';
                                                if ($last_import_count > 0) {
                                                    echo '<div class="ssi-status-item"><strong>' . esc_html__('Last Import:', 'substack-importer') . '</strong> ' . esc_html(sprintf(__('%d new posts', 'substack-importer'), $last_import_count)) . '</div>';
                                                }
                                            }
                                            
                                            // Show current offset
                                            $current_offset = (int)get_option('substack_importer_cron_offset', 0);
                                            echo '<div class="ssi-status-item"><strong>' . esc_html__('Current Offset:', 'substack-importer') . '</strong> ' . esc_html($current_offset) . '</div>';
                                        } else {
                                            echo '<div class="ssi-status-item"><em>' . esc_html__('No cron job scheduled', 'substack-importer') . '</em></div>';
                                        }
                                        ?>
                                    </div>
                                    <button type="button" class="button button-secondary" id="ssi-refresh-cron-status" style="margin-top: 8px;">
                                        <?php echo esc_html__('Refresh Status', 'substack-importer'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ssi-card">
    <div class="ssi-card-header">
        <div>
            <h2><?php echo esc_html__('Uninstall & Data', 'substack-importer'); ?></h2>
            <p class="desc"><?php echo esc_html__('By default, logs and settings are kept even if you uninstall. Check this to clear ALL plugin data on uninstall.', 'substack-importer'); ?></p>
        </div>
        <div class="ssi-badge ssi-badge-warning"><?php echo esc_html__('Caution', 'substack-importer'); ?></div>
    </div>
    <div class="ssi-field">
        <label class="ssi-switch">
            <input type="checkbox" name="substack_importer_clear_on_uninstall" value="1" <?php checked(get_option('substack_importer_clear_on_uninstall'), 1); ?>>
            <span class="ssi-slider" aria-hidden="true"></span>
            <span class="ssi-switch-label"><?php echo esc_html__('Clear all plugin data on uninstall', 'substack-importer'); ?></span>
        </label>
        <p class="help"><?php echo esc_html__('This will drop the import log table and delete plugin options and Substack-specific post meta.', 'substack-importer'); ?></p>
    </div>
</div>

<div class="ssi-toolbar-bottom">
                        <?php submit_button(esc_html__('Save Settings', 'substack-importer'), 'primary', 'submit', false); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=substack-importer&tab=manual')); ?>" class="button ssi-ghost"><?php echo esc_html__('Go to Manual Import', 'substack-importer'); ?></a>
                    </div>
                </form>
            <?php else: ?>
                <p><?php echo esc_html__('Click "Fetch Feed" to load recent posts, select which ones to import. Categories are mapped by explicit rules (Exact/CI/Regex) or created automatically.', 'substack-importer'); ?></p>
                

                
                <div class="ssi-toolbar" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                    <button class="button button-primary" id="substack-fetch"><?php echo esc_html__('Fetch Feed', 'substack-importer'); ?></button>
                    <button type="button" class="button button-secondary" id="substack-import-top" style="display:none;"><?php echo esc_html__('Import Selected', 'substack-importer'); ?></button>
                </div>
                <form id="substack-import-form">
                    <?php settings_fields('substack_importer_settings_group'); ?>
                    <table class="widefat" id="substack-table" style="display:none; margin-top:12px;">
                        <thead>
                        <tr>
                            <th style="width:80px;"><?php echo esc_html__('Select', 'substack-importer'); ?></th>
                            <th><?php echo esc_html__('Title', 'substack-importer'); ?></th>
                            <th style="width:180px;"><?php echo esc_html__('Date', 'substack-importer'); ?></th>
                            <th style="width:240px;"><?php echo esc_html__('Categories (from feed)', 'substack-importer'); ?></th>
                            <th style="width:160px;"><?php echo esc_html__('Status', 'substack-importer'); ?></th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <button type="submit" class="button button-secondary" id="substack-import" style="display:none;"><?php echo esc_html__('Import Selected', 'substack-importer'); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_imported_page() {
        if (!$this->plugin->can_use()) { wp_die(esc_html__('You do not have permission.', 'substack-importer')); }

        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $q = new \WP_Query([
            'post_type'      => 'post',
            'posts_per_page' => 20,
            'paged'          => $paged,
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_substack_guid', 'compare' => 'EXISTS'],
                ['key' => '_substack_source_link', 'compare' => 'EXISTS'],
            ],
        ]);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Imported Posts (Substack)', 'substack-importer'); ?></h1>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Title', 'substack-importer'); ?></th>
                        <th style="width:20%"><?php echo esc_html__('Source', 'substack-importer'); ?></th>
                        <th style="width:12%"><?php echo esc_html__('Last Modified', 'substack-importer'); ?></th>
                        <th style="width:10%"><?php echo esc_html__('Status', 'substack-importer'); ?></th>
                        <th style="width:22%"><?php echo esc_html__('Actions', 'substack-importer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($q->have_posts()) : while ($q->have_posts()) : $q->the_post();
                    $pid  = get_the_ID();
                    $guid = get_post_meta($pid, '_substack_guid', true);
                    $src  = get_post_meta($pid, '_substack_source_link', true);
                    $hash = get_post_meta($pid, '_substack_hash', true);
                    $src_disp = $src ? $src : $guid;
                    $flag = get_post_meta($pid, '_substack_out_of_sync', true);
                    ?>
                    <tr id="ssi-row-<?php echo (int)$pid; ?>">
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($pid)); ?>"><strong><?php echo esc_html(get_the_title()); ?></strong></a><br>
                            <small><?php echo esc_html__('Hash:', 'substack-importer'); ?> <?php echo esc_html(substr((string)$hash,0,10)); ?>…</small>
                            <?php if ($flag): ?>
                                <span class="ssi-badge ssi-badge-warning" style="margin-left:6px;"><?php echo esc_html__('Out of sync', 'substack-importer'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($src_disp): ?>
                                <a href="<?php echo esc_url($src_disp); ?>" target="_blank" rel="noopener"><?php echo esc_html(parse_url($src_disp, PHP_URL_HOST)); ?></a>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(get_post_modified_time('Y-m-d H:i')); ?></td>
                        <td>
                            <?php 
                            $post_status = get_post_status($pid);
                            if ($post_status === 'trash') {
                                echo '<span class="ssi-badge ssi-badge-deleted">' . esc_html__('Deleted', 'substack-importer') . '</span>';
                            } elseif ($post_status === 'publish') {
                                echo '<span class="ssi-badge ssi-badge-published">' . esc_html__('Published', 'substack-importer') . '</span>';
                            } elseif ($post_status === 'draft') {
                                echo '<span class="ssi-badge ssi-badge-draft">' . esc_html__('Draft', 'substack-importer') . '</span>';
                            } elseif ($post_status === 'pending') {
                                echo '<span class="ssi-badge ssi-badge-pending">' . esc_html__('Pending', 'substack-importer') . '</span>';
                            } else {
                                echo '<span class="ssi-badge ssi-badge-other">' . esc_html(ucfirst($post_status)) . '</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <button class="button button-primary ssi-import-post" data-post="<?php echo (int)$pid; ?>"><?php echo esc_html__('Import', 'substack-importer'); ?></button>
                            <button class="button button-secondary ssi-resync-now" data-post="<?php echo (int)$pid; ?>" style="<?php echo $flag ? '' : 'display:none;'; ?>"><?php echo esc_html__('Re-sync now', 'substack-importer'); ?></button>
                            <span class="ssi-status-text" style="margin-left:8px;"></span>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="5"><?php echo esc_html__('No imported posts found.', 'substack-importer'); ?></td></tr>
                <?php endif; wp_reset_postdata(); ?>
                </tbody>
            </table>

            <?php if ($q->max_num_pages > 1): ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $q->max_num_pages,
                    ]);
                    ?>
                </div></div>
            <?php endif; ?>
        </div>

        <style>
        /* Row update visual feedback */
        .ssi-row-updated {
            animation: ssi-row-blink 0.6s ease-in-out 3;
            background-color: #f0f9ff !important;
            border-left: 4px solid #3b82f6 !important;
        }
        
        @keyframes ssi-row-blink {
            0%, 100% { background-color: #f0f9ff; }
            50% { background-color: #dbeafe; }
        }
        
        /* Ensure the blinking effect works with WordPress table styles */
        .widefat .ssi-row-updated {
            background-color: #f0f9ff !important;
        }
        </style>

        <script>
        (function($){
            function rowStatus($row, text, changed){
                $row.find('.ssi-status-text').text(text || '');
                $row.find('.ssi-resync-now').toggle(!!changed);
            }
            
            // Import functionality with confirmation popup
            $(document).on('click', '.ssi-import-post', function(e){
                e.preventDefault();
                var pid = $(this).data('post');
                var $button = $(this);
                
                // Create modern confirmation popup
                var $popup = $('<div id="ssi-import-confirm" class="ssi-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;display:none;"><div class="ssi-modal-inner" style="background:#fff;max-width:500px;margin:10vh auto;padding:24px;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.3);"><div class="ssi-modal-header" style="display:flex;align-items:center;margin-bottom:20px;"><div class="ssi-modal-icon" style="width:48px;height:48px;border-radius:12px;background:#fef3c7;border:1px solid #f59e0b;display:flex;align-items:center;justify-content:center;margin-right:16px;"><span style="font-size:24px;">⚠️</span></div><div><h2 style="margin:0;font-size:20px;color:#111827;"><?php echo esc_js(__('Confirm Import', 'substack-importer')); ?></h2><p style="margin:4px 0 0 0;color:#6b7280;font-size:14px;"><?php echo esc_js(__('Import Feed Content', 'substack-importer')); ?></p></div></div><div class="ssi-modal-body" style="margin-bottom:24px;"><p style="margin:0;color:#374151;line-height:1.6;"><?php echo esc_js(__('If you import, the current post will be overwritten. However, a revision will be created so you can compare changes.', 'substack-importer')); ?></p></div><div class="ssi-modal-actions" style="display:flex;gap:12px;justify-content:flex-end;"><button class="button ssi-cancel-import" style="padding:8px 16px;"><?php echo esc_js(__('Cancel', 'substack-importer')); ?></button><button class="button button-primary ssi-confirm-import" style="padding:8px 16px;background:#dc2626;border-color:#dc2626;"><?php echo esc_js(__('Import Anyway', 'substack-importer')); ?></button></div></div></div>');
                
                if (!$('#ssi-import-confirm').length) {
                    $('body').append($popup);
                    
                    // Close popup handlers
                    $('#ssi-import-confirm').on('click', '.ssi-cancel-import', function(){
                        $('#ssi-import-confirm').hide();
                        $('body').css('overflow', '');
                    });
                    
                    $('#ssi-import-confirm').on('click', function(e){
                        if (e.target.id === 'ssi-import-confirm') {
                            $('#ssi-import-confirm').hide();
                            $('body').css('overflow', '');
                        }
                    });
                }
                
                // Show popup
                $('#ssi-import-confirm').show();
                $('body').css('overflow', 'hidden');
                
                // Handle confirmation
                $('#ssi-import-confirm').off('click.ssiConfirm').on('click.ssiConfirm', '.ssi-confirm-import', function(){
                    $('#ssi-import-confirm').hide();
                    $('body').css('overflow', '');
                    
                    // Disable button and show loading
                    $button.prop('disabled', true).text('<?php echo esc_js(__('Importing...', 'substack-importer')); ?>');
                    
                    // Proceed with import
                $.post(ajaxurl, {
                        action: 'substack_importer_import_feed_version',
                        nonce: '<?php echo esc_js(wp_create_nonce('substack_importer_import_feed')); ?>',
                    post_id: pid
                }, function(r){
                        if (r && r.success && r.data) {
                            // Show success confirmation popup overlay
                            var $successPopup = $('<div id="ssi-import-success" class="ssi-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;display:none;"><div class="ssi-modal-inner" style="background:#fff;max-width:500px;margin:10vh auto;padding:24px;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.3);"><div class="ssi-modal-header" style="display:flex;align-items:center;margin-bottom:20px;"><div class="ssi-modal-icon" style="width:48px;height:48px;border-radius:12px;background:#d1fae5;border:1px solid #10b981;display:flex;align-items:center;justify-content:center;margin-right:16px;"><span style="font-size:24px;">✅</span></div><div><h2 style="margin:0;font-size:20px;color:#111827;"><?php echo esc_js(__('Import Successful!', 'substack-importer')); ?></h2><p style="margin:4px 0 0 0;color:#6b7280;font-size:14px;"><?php echo esc_js(__('Feed content has been imported', 'substack-importer')); ?></p></div></div><div class="ssi-modal-body" style="margin-bottom:24px;"><p style="margin:0;color:#374151;line-height:1.6;">' + r.data.message + '</p></div><div class="ssi-modal-actions" style="display:flex;gap:12px;justify-content:flex-end;"><button class="button button-primary ssi-close-success" style="padding:8px 16px;"><?php echo esc_js(__('Close', 'substack-importer')); ?></button></div></div></div>');
                            
                            if (!$('#ssi-import-success').length) {
                                $('body').append($successPopup);
                                
                                // Close popup handler
                                $('#ssi-import-success').on('click', '.ssi-close-success', function(){
                                    $('#ssi-import-success').hide();
                                    $('body').css('overflow', '');
                                });
                                
                                $('#ssi-import-success').on('click', function(e){
                                    if (e.target.id === 'ssi-import-success') {
                                        $('#ssi-import-success').hide();
                                        $('body').css('overflow', '');
                                    }
                                });
                            }
                            
                            // Show success popup
                            $('#ssi-import-success').show();
                            $('body').css('overflow', 'hidden');
                            
                            // Re-enable button
                            $button.prop('disabled', false).text('<?php echo esc_js(__('Import', 'substack-importer')); ?>');
                            
                            // Update the row in the Imported Posts table
                            var $row = $('#ssi-row-' + pid);
                            
                            // Refresh the Title field with new title
                            if (r.data.post_title) {
                                $row.find('td:first-child a strong').text(r.data.post_title);
                            }
                            
                            // Update the Action button to "Compare Now"
                            var $actionButton = $row.find('.ssi-import-post');
                            $actionButton.removeClass('ssi-import-post').addClass('ssi-compare-now')
                                        .text('<?php echo esc_js(__('Compare Now', 'substack-importer')); ?>')
                                        .removeAttr('data-post')
                                        .attr('href', r.data.revision_link || r.data.edit_link);
                            
                            // Update row status
                            rowStatus($row, '<?php echo esc_js(__('Import completed', 'substack-importer')); ?>', false);
                            
                            // Add visual feedback - blink the updated row
                            $row.addClass('ssi-row-updated');
                            setTimeout(function() {
                                $row.removeClass('ssi-row-updated');
                            }, 3000);
                            
                    } else {
                            alert('<?php echo esc_js(__('Failed to import feed version. Please try again.', 'substack-importer')); ?>');
                            $button.prop('disabled', false).text('<?php echo esc_js(__('Import', 'substack-importer')); ?>');
                    }
                    }).fail(function(){
                        alert('<?php echo esc_js(__('Failed to import feed version. Please try again.', 'substack-importer')); ?>');
                        $button.prop('disabled', false).text('<?php echo esc_js(__('Import', 'substack-importer')); ?>');
                });
            });
            });


            
            $(document).on('click', '.ssi-resync-now', function(e){
                e.preventDefault();
                var pid = $(this).data('post'), $row = $('#ssi-row-'+pid);
                rowStatus($row, '<?php echo esc_js(__('Re-syncing…','substack-importer')); ?>', false);
                $.post(ajaxurl, {
                    action: 'substack_importer_resync_post',
                    nonce: '<?php echo esc_js(wp_create_nonce('substack_importer_resync')); ?>',
                    post_id: pid
                }, function(r){
                    if (!r || !r.success) { rowStatus($row, '<?php echo esc_js(__('Error','substack-importer')); ?>', false); return; }
                    rowStatus($row, '<?php echo esc_js(__('Re-synced','substack-importer')); ?>', false);
                    $row.find('.ssi-badge-warning').remove();
                });
            });
            
            // Handle Compare Now button clicks
            $(document).on('click', '.ssi-compare-now', function(e){
                e.preventDefault();
                var href = $(this).attr('href');
                if (href) {
                    window.location.href = href;
                }
            });
            
            // Import feed version functionality
            $(document).on('click', '.ssi-import-feed-version', function(e){
                e.preventDefault();
                var pid = $(this).data('post-id');
                var $button = $(this);
                
                if (!confirm('<?php echo esc_js(__('Are you sure you want to import the feed version? This will update the post content and change the status to Draft.', 'substack-importer')); ?>')) {
                    return;
                }
                
                $button.prop('disabled', true).text('<?php echo esc_js(__('Importing...', 'substack-importer')); ?>');
                
                $.post(ajaxurl, {
                    action: 'substack_importer_import_feed_version',
                    nonce: '<?php echo esc_js(wp_create_nonce('substack_importer_import_feed')); ?>',
                    post_id: pid
                }, function(r){
                    if (r && r.success && r.data) {
                        // Show success notification
                        var $notification = $('<div class="notice notice-success is-dismissible" style="position:fixed;top:20px;right:20px;z-index:100001;max-width:400px;box-shadow:0 4px 12px rgba(0,0,0,0.15);"><p><strong><?php echo esc_js(__('Success!', 'substack-importer')); ?></strong> ' + r.data.message + '</p><p><a href="' + r.data.edit_link + '" class="button button-primary" style="margin-top:8px;"><?php echo esc_js(__('Edit Post', 'substack-importer')); ?></a></p></div>');
                        $('body').append($notification);
                        
                        // Auto-dismiss after 8 seconds
                        setTimeout(function(){
                            $notification.fadeOut(function(){ $(this).remove(); });
                        }, 8000);
                        
                        // Close the review modal
                        $('#ssi-review-modal').hide();
                        $('body').css('overflow', '');
                        
                        // Refresh the page to show updated status
                        setTimeout(function(){
                            location.reload();
                        }, 1000);
                    } else {
                        alert('<?php echo esc_js(__('Failed to import feed version. Please try again.', 'substack-importer')); ?>');
                        $button.prop('disabled', false).text('<?php echo esc_js(__('Import Feed Version', 'substack-importer')); ?>');
                    }
                }).fail(function(){
                    alert('<?php echo esc_js(__('Failed to import feed version. Please try again.', 'substack-importer')); ?>');
                    $button.prop('disabled', false).text('<?php echo esc_js(__('Import Feed Version', 'substack-importer')); ?>');
                });
            });
            
            // LinkedIn push functionality
            $(document).on('click', '.ssi-push-linkedin', function(e){
                e.preventDefault();
                var pid = $(this).data('post');
                if (!confirm('<?php echo esc_js(__('Push this published post to LinkedIn now?', 'substack-importer')); ?>')) return;
                $.post(ajaxurl, {action:'substack_importer_push_linkedin', nonce:'<?php echo esc_js(wp_create_nonce('substack_importer_li')); ?>', post_id: pid}, function(r){
                    if (r && r.success) { alert('<?php echo esc_js(__('Posted to LinkedIn', 'substack-importer')); ?>'); }
                    else { alert('<?php echo esc_js(__('Failed to post to LinkedIn', 'substack-importer')); ?>'); }
                });
            });
            
            // Row action: LinkedIn push from Posts table
                        $(document).off('click.ssiPushLI').on('click.ssiPushLI', '.ssi-row-push-linkedin', function(e){
                            e.preventDefault();
                            var id = $(this).data('post');
                            if (!confirm('<?php echo esc_js(__('Push this published post to LinkedIn now?', 'substack-importer')); ?>')) return;
                            $.post(ajaxurl, {action:'substack_importer_push_linkedin', nonce:'<?php echo esc_js(wp_create_nonce('substack_importer_li')); ?>', post_id:id}, function(rr){
                                if (rr && rr.success) { alert('<?php echo esc_js(__('Posted to LinkedIn', 'substack-importer')); ?>'); }
                                else { alert('<?php echo esc_js(__('Failed to post to LinkedIn', 'substack-importer')); ?>'); }
                            });
                        });
        })(jQuery);
        </script>
        <?php
    }

    public function render_review_page() {
        if (!$this->plugin->can_use()) { 
            wp_die(esc_html__('You do not have permission.', 'substack-importer')); 
        }

        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        if (!$post_id) {
            wp_die(esc_html__('No post ID specified.', 'substack-importer'));
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_die(esc_html__('Post not found.', 'substack-importer'));
        }

        // Check if this is a Substack imported post
        $guid = get_post_meta($post_id, '_substack_guid', true);
        $src = get_post_meta($post_id, '_substack_source_link', true);
        if (!$guid && !$src) {
            wp_die(esc_html__('This post is not a Substack imported post.', 'substack-importer'));
        }

        // Get feed item for comparison
        $feed_item = $this->plugin->importer->find_feed_item_for_post($post_id);
        if (!$feed_item) {
            wp_die(esc_html__('Feed item not found for this post.', 'substack-importer'));
        }

        // Prepare feed content
        $feed_content = $this->plugin->importer->sanitize_html(html_entity_decode((string)$feed_item->get_content()));
        $feed_title = $feed_item->get_title();
        
        // Create a temporary revision for the feed content
        $revision_id = $this->create_feed_revision($post_id, $feed_title, $feed_content);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Review Mode', 'substack-importer'); ?></h1>
            <p class="description"><?php echo esc_html__('Comparing current post with feed version using WordPress revisions.', 'substack-importer'); ?></p>
            
            <div class="ssi-review-header">
                <div class="ssi-post-info">
                    <h2><?php echo esc_html($post->post_title); ?></h2>
                    <p><strong><?php echo esc_html__('Status:', 'substack-importer'); ?></strong> <?php echo esc_html(ucfirst($post->post_status)); ?></p>
                    <p><strong><?php echo esc_html__('Last Modified:', 'substack-importer'); ?></strong> <?php echo esc_html(get_the_modified_date('F j, Y g:i a', $post_id)); ?></p>
                </div>
                <div class="ssi-actions">
                    <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" class="button"><?php echo esc_html__('Edit Post', 'substack-importer'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=substack-importer-imported')); ?>" class="button"><?php echo esc_html__('Back to Imported Posts', 'substack-importer'); ?></a>
                </div>
            </div>

            <div class="ssi-revision-comparison">
                <div class="ssi-comparison-tabs">
                    <button class="ssi-tab-button ssi-tab-active" data-tab="side-by-side"><?php echo esc_html__('Side by Side', 'substack-importer'); ?></button>
                    <button class="ssi-tab-button" data-tab="compare-revision"><?php echo esc_html__('Compare Revision', 'substack-importer'); ?></button>
                </div>
                
                <div class="ssi-tab-content ssi-tab-active" id="side-by-side">
                    <?php
                    // Use WordPress revision comparison
                    $this->render_revision_comparison($post_id, $revision_id);
                    ?>
                </div>
                
                <div class="ssi-tab-content" id="compare-revision">
                    <?php
                    // Use detailed diff comparison
                    $this->render_diff_comparison($post_id, $revision_id);
                    ?>
                </div>
            </div>

            <div class="ssi-review-actions">
                <button type="button" class="button button-primary ssi-import-feed-standalone" data-post-id="<?php echo esc_attr($post_id); ?>">
                    <?php echo esc_html__('Import Feed Version', 'substack-importer'); ?>
                </button>
                <p class="ssi-import-help"><?php echo esc_html__('This will import the feed version and change the post status to Draft for review.', 'substack-importer'); ?></p>
            </div>
        </div>

        <script>
            (function($){
            // Tab functionality
            $('.ssi-tab-button').on('click', function(e){
                    e.preventDefault();
                var tab = $(this).data('tab');
                
                // Remove active class from all tabs and content
                $('.ssi-tab-button').removeClass('ssi-tab-active');
                $('.ssi-tab-content').removeClass('ssi-tab-active');
                
                // Add active class to clicked tab and corresponding content
                $(this).addClass('ssi-tab-active');
                $('#' + tab).addClass('ssi-tab-active');
            });
            
            $('.ssi-import-feed-standalone').on('click', function(e){
                            e.preventDefault();
                var pid = $(this).data('post-id');
                var $button = $(this);
                
                if (!confirm('<?php echo esc_js(__('Are you sure you want to import the feed version? This will update the post content and change the status to Draft.', 'substack-importer')); ?>')) {
                    return;
                }
                
                $button.prop('disabled', true).text('<?php echo esc_js(__('Importing...', 'substack-importer')); ?>');
                
                $.post(ajaxurl, {
                    action: 'substack_importer_import_feed_version',
                    nonce: '<?php echo esc_js(wp_create_nonce('substack_importer_import_feed')); ?>',
                    post_id: pid
                }, function(r){
                    if (r && r.success && r.data) {
                        // Show success notification
                        var $notification = $('<div class="notice notice-success is-dismissible" style="position:fixed;top:20px;right:20px;z-index:100001;max-width:400px;box-shadow:0 4px 12px rgba(0,0,0,0.15);"><p><strong><?php echo esc_js(__('Success!', 'substack-importer')); ?></strong> ' + r.data.message + '</p><p><a href="' + r.data.edit_link + '" class="button button-primary" style="margin-top:8px;"><?php echo esc_js(__('Edit Post', 'substack-importer')); ?></a></p></div>');
                        $('body').append($notification);
                        
                        // Auto-dismiss after 8 seconds
                        setTimeout(function(){
                            $notification.fadeOut(function(){ $(this).remove(); });
                        }, 8000);
                        
                        // Redirect to edit page
                        setTimeout(function(){
                            window.location.href = r.data.edit_link;
                        }, 1000);
                    } else {
                        alert('<?php echo esc_js(__('Failed to import feed version. Please try again.', 'substack-importer')); ?>');
                        $button.prop('disabled', false).text('<?php echo esc_js(__('Import Feed Version', 'substack-importer')); ?>');
                    }
                }).fail(function(){
                    alert('<?php echo esc_js(__('Failed to import feed version. Please try again.', 'substack-importer')); ?>');
                    $button.prop('disabled', false).text('<?php echo esc_js(__('Import Feed Version', 'substack-importer')); ?>');
                    });
                });
            })(jQuery);
        </script>
        <?php
    }

    protected function create_feed_revision($post_id, $feed_title, $feed_content) {
        // Create a temporary revision for the feed content
        $revision_data = array(
            'post_parent' => $post_id,
            'post_title' => $feed_title,
            'post_content' => $feed_content,
            'post_status' => 'inherit',
            'post_type' => 'revision',
            'post_name' => $post_id . '-feed-version',
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', 1),
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1),
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_author' => get_post_field('post_author', $post_id),
        );

        $revision_id = wp_insert_post($revision_data);
        
        if ($revision_id && !is_wp_error($revision_id)) {
            // Add meta to identify this as a feed revision
            update_post_meta($revision_id, '_substack_feed_revision', 1);
            update_post_meta($revision_id, '_substack_feed_title', $feed_title);
            update_post_meta($revision_id, '_substack_feed_content', $feed_content);
            return $revision_id;
        }
        
        return false;
    }

    protected function render_revision_comparison($post_id, $revision_id) {
        if (!$revision_id) {
            echo '<p>' . esc_html__('Failed to create feed revision for comparison.', 'substack-importer') . '</p>';
            return;
        }

        // Get the current post and revision
        $post = get_post($post_id);
        $revision = get_post($revision_id);
        
        if (!$post || !$revision) {
            echo '<p>' . esc_html__('Failed to load post or revision for comparison.', 'substack-importer') . '</p>';
            return;
        }

        // Use WordPress revision comparison
        $left_revision_id = $post_id;
        $right_revision_id = $revision_id;
        
        // Include WordPress revision comparison
        require_once(ABSPATH . 'wp-admin/includes/revision.php');
        
        // Set up the comparison
        $left_revision = $post;
        $right_revision = $revision;
        
        // Custom comparison display
        ?>
        <div class="ssi-revision-diff">
            <div class="ssi-diff-header">
                <div class="ssi-diff-left">
                    <h3><?php echo esc_html__('Current Version', 'substack-importer'); ?></h3>
                    <p><?php echo esc_html__('Published/Draft Content', 'substack-importer'); ?></p>
                </div>
                <div class="ssi-diff-right">
                    <h3><?php echo esc_html__('Feed Version', 'substack-importer'); ?></h3>
                    <p><?php echo esc_html__('Latest from Substack Feed', 'substack-importer'); ?></p>
                </div>
            </div>
            
            <div class="ssi-diff-content">
                <div class="ssi-diff-left-content">
                    <h4><?php echo esc_html($left_revision->post_title); ?></h4>
                    <div class="ssi-content-preview">
                        <?php echo wp_kses_post($left_revision->post_content); ?>
                    </div>
                </div>
                <div class="ssi-diff-right-content">
                    <h4><?php echo esc_html($right_revision->post_title); ?></h4>
                    <div class="ssi-content-preview ssi-feed-highlight">
                        <?php echo wp_kses_post($right_revision->post_content); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    protected function render_diff_comparison($post_id, $revision_id) {
        if (!$revision_id) {
            echo '<p>' . esc_html__('Failed to create feed revision for comparison.', 'substack-importer') . '</p>';
            return;
        }

        // Get the current post and revision
        $post = get_post($post_id);
        $revision = get_post($revision_id);
        
        if (!$post || !$revision) {
            echo '<p>' . esc_html__('Failed to load post or revision for comparison.', 'substack-importer') . '</p>';
            return;
        }

        // Prepare content for diff
        $left_content = $post->post_content;
        $right_content = $revision->post_content;
        
        // Generate diff using WordPress diff functions
        $diff = $this->generate_text_diff($left_content, $right_content);
        
        ?>
        <div class="ssi-diff-comparison">
            <div class="ssi-diff-header">
                <div class="ssi-diff-left">
                    <h3><?php echo esc_html__('Current Version', 'substack-importer'); ?></h3>
                    <p><?php echo esc_html__('Published/Draft Content', 'substack-importer'); ?></p>
                </div>
                <div class="ssi-diff-right">
                    <h3><?php echo esc_html__('Feed Version', 'substack-importer'); ?></h3>
                    <p><?php echo esc_html__('Latest from Substack Feed', 'substack-importer'); ?></p>
                </div>
            </div>
            
            <div class="ssi-diff-body">
                <?php if (!empty($diff)): ?>
                    <div class="ssi-diff-content">
                        <?php echo wp_kses_post($diff); ?>
                    </div>
                <?php else: ?>
                    <div class="ssi-no-differences">
                        <p><?php echo esc_html__('No differences found between the current version and feed version.', 'substack-importer'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    protected function generate_text_diff($left_text, $right_text) {
        // Include WordPress diff functions
        require_once(ABSPATH . 'wp-admin/includes/diff.php');
        
        // Prepare text for diff
        $left_lines = explode("\n", $left_text);
        $right_lines = explode("\n", $right_text);
        
        // Generate diff
        $diff = new \Text_Diff($left_lines, $right_lines);
        $renderer = new \Text_Diff_Renderer_inline();
        
        $diff_output = $renderer->render($diff);
        
        // Clean up the diff output
        $diff_output = str_replace('<ins>', '<span class="ssi-diff-added">', $diff_output);
        $diff_output = str_replace('</ins>', '</span>', $diff_output);
        $diff_output = str_replace('<del>', '<span class="ssi-diff-removed">', $diff_output);
        $diff_output = str_replace('</del>', '</span>', $diff_output);
        
        return $diff_output;
    }

    public function add_post_state_badge($states, $post) {
        $flag = get_post_meta((int)$post->ID, '_substack_out_of_sync', true);
        $is_substack = get_post_meta((int)$post->ID, '_substack_guid', true) || get_post_meta((int)$post->ID, '_substack_source_link', true);
        if ($is_substack && $flag) {
            $states['ssi_out_of_sync'] = esc_html__('Out of sync', 'substack-importer');
        }
        return $states;
    }
    public function row_action_push_linkedin($actions, $post) {
        $is_substack = get_post_meta((int)$post->ID, '_substack_guid', true) || get_post_meta((int)$post->ID, '_substack_source_link', true);
        if (!$is_substack) return $actions;
        if ($post->post_status !== 'publish') return $actions;
        $actions['ssi_push_linkedin'] = '<a href="#" class="ssi-row-push-linkedin" data-post="'. (int)$post->ID .'">'. esc_html__('Push to LinkedIn','substack-importer') .'</a>';
        return $actions;
    }

    public function enable_revision_comparison() {
        ?>
        <script>
        (function($){
            // Function to check and enable comparison checkbox
            function enableComparisonCheckbox() {
                var $checkboxes = $('input[name="compare"]');
                $checkboxes.each(function() {
                    var $checkbox = $(this);
                    if (!$checkbox.prop('checked')) {
                        $checkbox.prop('checked', true);
                        $checkbox.trigger('change');
                        console.log('SSI: Comparison checkbox enabled');
                    }
                });
            }
            
            // Function to select pre-import revision
            function selectPreImportRevision() {
                var $revisionSelect = $('select[name="left"]');
                if ($revisionSelect.length) {
                    $revisionSelect.find('option').each(function(){
                        var $option = $(this);
                        var text = $option.text();
                        if (text.includes('before-import') || text.includes('Pre-import')) {
                            $option.prop('selected', true);
                            $revisionSelect.trigger('change');
                            console.log('SSI: Pre-import revision selected');
                            return false;
                        }
                    });
                }
            }
            
            // Run immediately
            enableComparisonCheckbox();
            selectPreImportRevision();
            
            // Run on document ready
            $(document).ready(function(){
                enableComparisonCheckbox();
                selectPreImportRevision();
                
                // Run periodically to catch late-loading elements
                setInterval(function() {
                    enableComparisonCheckbox();
                }, 1000);
                
                // Monitor for dynamically added elements
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'childList') {
                            mutation.addedNodes.forEach(function(node) {
                                if (node.nodeType === 1) { // Element node
                                    var $newCheckboxes = $(node).find('input[name="compare"]');
                                    if ($newCheckboxes.length) {
                                        $newCheckboxes.prop('checked', true);
                                        $newCheckboxes.trigger('change');
                                        console.log('SSI: Dynamic comparison checkbox enabled');
                                    }
                                }
                            });
                        }
                    });
                });
                
                // Start observing
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            });
            
            // Also run on window load
            $(window).on('load', function() {
                enableComparisonCheckbox();
                selectPreImportRevision();
            });
            
            // Handle AJAX-loaded content
            $(document).ajaxComplete(function() {
                enableComparisonCheckbox();
                selectPreImportRevision();
            });
            
        })(jQuery);
        </script>
        <?php
    }

    public function ensure_revision_comparison_default($num, $post) {
        // Add JavaScript to ensure comparison checkbox is always checked
        if (is_admin() && $post && $post->post_type === 'post') {
            add_action('admin_footer', function() {
                ?>
                <script>
                (function($){
                    // Ensure comparison checkbox is checked by default
                    $(document).on('DOMNodeInserted', function(e) {
                        var $target = $(e.target);
                        if ($target.is('input[name="compare"]') || $target.find('input[name="compare"]').length) {
                            var $checkbox = $target.is('input[name="compare"]') ? $target : $target.find('input[name="compare"]');
                            if (!$checkbox.prop('checked')) {
                                $checkbox.prop('checked', true).trigger('change');
                            }
                        }
                    });
                    
                    // Also check on page load
                    $(document).ready(function() {
                        $('input[name="compare"]').prop('checked', true).trigger('change');
                    });
                })(jQuery);
                </script>
                <?php
            });
        }
        
        return $num;
    }

    public function force_revision_comparison_default() {
        // Only run on revision pages or post edit pages
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['post', 'revision'])) {
            return;
        }
        
        ?>
        <style>
        /* Force checkbox to be checked by default */
        input[name="compare"] {
            /* Override any existing styles */
            opacity: 1 !important;
            visibility: visible !important;
        }
        </style>
        <script>
        (function($){
            // Override WordPress default behavior
            $(document).ready(function() {
                // Force check the comparison checkbox
                function forceCheckComparison() {
                    var $checkboxes = $('input[name="compare"]');
                    $checkboxes.each(function() {
                        var $checkbox = $(this);
                        if (!$checkbox.prop('checked')) {
                            $checkbox.prop('checked', true);
                            $checkbox.attr('checked', 'checked');
                            $checkbox.trigger('change');
                            console.log('SSI: Forced comparison checkbox check');
                        }
                    });
                }
                
                // Run immediately
                forceCheckComparison();
                
                // Run after a short delay to catch any late-loading elements
                setTimeout(forceCheckComparison, 500);
                setTimeout(forceCheckComparison, 1000);
                setTimeout(forceCheckComparison, 2000);
                
                // Override WordPress's default checkbox behavior
                $(document).on('click', 'input[name="compare"]', function(e) {
                    // If someone tries to uncheck it, re-check it after a brief delay
                    var $checkbox = $(this);
                    setTimeout(function() {
                        if (!$checkbox.prop('checked')) {
                            $checkbox.prop('checked', true);
                            $checkbox.trigger('change');
                            console.log('SSI: Re-checked comparison checkbox');
                        }
                    }, 100);
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}
