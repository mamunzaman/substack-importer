<?php
namespace Substack_Importer;

if (!defined('ABSPATH')) { exit; }

final class Plugin {
    const LI_CRON_HOOK = 'ssi_oop_li_cron';

    /** @var Plugin */
    private static $instance = null;

    /** @var Admin */
    public $admin;

    /** @var Importer */
    public $importer;

    /** @var LinkedIn_Service */
    public $linkedin;

    /** @var Logger */
    public $logger;

    private function __construct() {
        $this->logger   = new Logger();
        $this->admin    = new Admin($this);
        $this->importer = new Importer($this);
        if (class_exists(__NAMESPACE__ . '\\LinkedIn_Service')) { $this->linkedin = new LinkedIn_Service($this); }

        // AJAX
        add_action('wp_ajax_substack_importer_fetch_feed',      [$this->importer, 'ajax_fetch_feed']);
        add_action('wp_ajax_substack_importer_import_selected', [$this->importer, 'ajax_import_selected']);
        add_action('wp_ajax_substack_importer_check_update',    [$this->importer, 'ajax_check_update']);
        add_action('wp_ajax_substack_importer_resync_post',     [$this->importer, 'ajax_resync_post']);
        add_action('wp_ajax_substack_importer_check_all',       [$this->importer, 'ajax_check_all']);
        add_action('wp_ajax_substack_importer_resync_changed',  [$this->importer, 'ajax_resync_changed']);

        add_action('wp_ajax_substack_importer_import_feed_version', [$this->importer, 'ajax_import_feed_version']);
        add_action('wp_ajax_substack_importer_reset_cron_offset', [$this->importer, 'ajax_reset_cron_offset']);

        add_action('wp_ajax_substack_importer_refresh_cron_status',      [$this, 'ajax_refresh_cron_status']);

        // Cron
        add_filter('cron_schedules', [$this, 'register_cron_schedule']);
        add_action(\SSI_OOP_CRON_HOOK, [$this->importer, 'cron_task']);
        add_action('init', [$this, 'ensure_cron_state']); // keep schedule consistent

        // Reschedule on settings change
        add_action('update_option_substack_importer_cron_enabled', [$this, 'maybe_reschedule_cron'], 10, 3);
        add_action('update_option_substack_importer_cron_interval', [$this, 'maybe_reschedule_cron'], 10, 3);
        add_action('update_option_substack_importer_cron_interval_unit', [$this, 'maybe_reschedule_cron'], 10, 3);
        add_action('update_option_substack_importer_cron_import_limit', [$this, 'maybe_reschedule_cron'], 10, 3);
    }

    public static function instance() : Plugin {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function activate() {
        $this->logger->maybe_create_table();
                    add_option('substack_importer_default_status', 'draft'); // draft|publish
            add_option('substack_importer_term_map', []);            // mapping rows
        
        add_option('substack_importer_cron_enabled', 0);
        add_option('substack_importer_cron_interval', 6);        // hours
        add_option('substack_importer_cron_interval_unit', 'hours'); // hours|minutes
        add_option('substack_importer_cron_import_limit', 10);   // max posts per cron run
        add_option('substack_importer_cron_offset', 0);          // current offset for cron imports
        add_option('substack_importer_last_cron_run', 0);        // timestamp of last cron run
        add_option('substack_importer_last_import_count', 0);    // number of posts imported in last cron run
        add_option('substack_importer_enhanced_gutenberg', 1);   // enable enhanced Gutenberg compatibility
    }

    public function can_use() : bool {
        return current_user_can(\SSI_OOP_CAP);
    }

    // ===== Cron utilities =====
    public function get_interval_seconds() : int {
        $interval = max(2, (int)get_option('substack_importer_cron_interval', 6));
        $unit = get_option('substack_importer_cron_interval_unit', 'hours');
        
        if ($unit === 'minutes') {
            // Convert minutes to seconds, with max of 600 minutes (10 hours)
            $interval = min($interval, 600);
            return $interval * MINUTE_IN_SECONDS;
        } else {
            // Convert hours to seconds, with max of 10 hours
            $interval = min($interval, 10);
            return $interval * HOUR_IN_SECONDS;
        }
    }

    public function register_cron_schedule($schedules) {
        $schedules['ssi_oop_custom'] = [
            'interval' => $this->get_interval_seconds(),
            'display'  => __('Substack Importer Schedule', 'substack-importer'),
        ];
        return $schedules;
    }

    public function ensure_cron_state() {
        $enabled = (int)get_option('substack_importer_cron_enabled', 0) === 1;
        $next = wp_next_scheduled(\SSI_OOP_CRON_HOOK);
        if ($enabled && !$next) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'ssi_oop_custom', \SSI_OOP_CRON_HOOK);
        } elseif (!$enabled && $next) {
            $this->clear_cron();
        }
    }

    public function clear_cron() {
        while ($timestamp = wp_next_scheduled(\SSI_OOP_CRON_HOOK)) {
            wp_unschedule_event($timestamp, \SSI_OOP_CRON_HOOK);
        }
        wp_clear_scheduled_hook(\SSI_OOP_CRON_HOOK);
    }

    public function maybe_reschedule_cron($old_value, $new_value, $option) {
        $this->clear_cron();
        $this->ensure_cron_state();
    }
    
    public function ajax_refresh_cron_status() {
        if (!current_user_can($this->capability)) {
            wp_die('Unauthorized', 403);
        }
        
        $next_scheduled = wp_next_scheduled(\SSI_OOP_CRON_HOOK);
        $html = '';
        
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
            
            $html .= '<div class="ssi-status-item"><strong>' . __('Next Run:', 'substack-importer') . '</strong> ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_scheduled) . '</div>';
            $html .= '<div class="ssi-status-item"><strong>' . __('Remaining:', 'substack-importer') . '</strong> ' . $remaining_formatted . '</div>';
            
            if ($last_run > 0) {
                $html .= '<div class="ssi-status-item"><strong>' . __('Last Run:', 'substack-importer') . '</strong> ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_run) . '</div>';
                if ($last_import_count > 0) {
                    $html .= '<div class="ssi-status-item"><strong>' . __('Last Import:', 'substack-importer') . '</strong> ' . sprintf(__('%d new posts', 'substack-importer'), $last_import_count) . '</div>';
                }
            }
            
            // Show current offset
            $current_offset = (int)get_option('substack_importer_cron_offset', 0);
            $html .= '<div class="ssi-status-item"><strong>' . __('Current Offset:', 'substack-importer') . '</strong> ' . $current_offset . '</div>';
        } else {
            $html .= '<div class="ssi-status-item"><em>' . __('No cron job scheduled', 'substack-importer') . '</em></div>';
        }
        
        wp_send_json_success(['html' => $html]);
    }
public function render_linkedin_page() {
        if (!$this->can_use()) { wp_die('Unauthorized', 403); }
        $connected = $this->linkedin->is_connected();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('LinkedIn', 'substack-importer'); ?></h1>
            <form method="post" action="options.php" class="ssi-form">
                <?php settings_fields('substack_importer_settings_group'); ?>
                <div class="ssi-card">
                    <div class="ssi-card-header">
                        <div>
                            <h2><?php echo esc_html__('Connection', 'substack-importer'); ?></h2>
                            <p class="desc"><?php echo esc_html__('Connect your LinkedIn app to post approved WordPress posts.', 'substack-importer'); ?></p>
                        </div>
                        <div class="ssi-badge"><?php echo $connected ? esc_html__('Connected','substack-importer') : esc_html__('Not connected','substack-importer'); ?></div>
                    </div>

                    <div class="ssi-grid">
                        <div class="ssi-field">
                            <label for="ssi-li-pages"><?php echo esc_html__('Select Organization (optional)', 'substack-importer'); ?></label>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <select id="ssi-li-pages" style="min-width:320px"></select>
                                <button type="button" class="button" id="ssi-li-fetch-pages"><?php echo esc_html__('Fetch my Pages', 'substack-importer'); ?></button>
                                <button type="button" class="button" id="ssi-li-set-actor"><?php echo esc_html__('Use selected as Default Actor', 'substack-importer'); ?></button>
                            </div>
                            <p class="help"><?php echo esc_html__('Requires w_organization_social scope and admin access to the page.', 'substack-importer'); ?></p>
                        </div>
                        <div class="ssi-field">
                            <label for="ssi-li-client-id"><?php echo esc_html__('Client ID', 'substack-importer'); ?></label>
                            <input id="ssi-li-client-id" type="text" name="substack_importer_li_client_id" value="<?php echo esc_attr(get_option('substack_importer_li_client_id','')); ?>">
                        </div>
                        <div class="ssi-field">
                            <label for="ssi-li-client-secret"><?php echo esc_html__('Client Secret', 'substack-importer'); ?></label>
                            <input id="ssi-li-client-secret" type="password" name="substack_importer_li_client_secret" value="<?php echo esc_attr(get_option('substack_importer_li_client_secret','')); ?>">
                        </div>
                        <div class="ssi-field">
                            <label for="ssi-li-scopes"><?php echo esc_html__('Scopes', 'substack-importer'); ?></label>
                            <input id="ssi-li-scopes" type="text" name="substack_importer_li_scopes" value="<?php echo esc_attr(get_option('substack_importer_li_scopes','w_member_social r_liteprofile')); ?>">
                            <p class="help"><?php echo esc_html__('For pages, add w_organization_social and set actor URN to an organization URN.', 'substack-importer'); ?></p>
                        </div>
                        <div class="ssi-field">
                            <label for="ssi-li-actor"><?php echo esc_html__('Default Actor URN', 'substack-importer'); ?></label>
                            <input id="ssi-li-actor" type="text" name="substack_importer_li_actor_urn" value="<?php echo esc_attr(get_option('substack_importer_li_actor_urn','')); ?>" placeholder="urn:li:person:XXXXX or urn:li:organization:XXXXX">
                            <p class="help"><?php echo esc_html__('Click "Fetch my URN" after connecting to auto-fill your person URN.', 'substack-importer'); ?></p>
                        </div>
                    </div>

                    <div class="ssi-toolbar-bottom">
                        <?php submit_button(esc_html__('Save', 'substack-importer'), 'primary', 'submit', false); ?>
                        <button type="button" class="button" id="ssi-li-test"><?php echo esc_html__('Send test', 'substack-importer'); ?></button>
                        <button type="button" class="button" id="ssi-li-run-queue"><?php echo esc_html__('Process queue now', 'substack-importer'); ?></button>
                        <?php if ($connected): ?>
                            <button type="button" class="button" id="ssi-li-fetch-me"><?php echo esc_html__('Fetch my URN', 'substack-importer'); ?></button>
                            <button type="button" class="button ssi-ghost" id="ssi-li-disconnect"><?php echo esc_html__('Disconnect', 'substack-importer'); ?></button>
                        <?php else: ?>
                            <button type="button" class="button button-primary" id="ssi-li-connect"><?php echo esc_html__('Connect LinkedIn', 'substack-importer'); ?></button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <form method="post" action="options.php" class="ssi-form" style="margin-top:16px;">
                <?php settings_fields('substack_importer_settings_group'); ?>
                <div class="ssi-card">
                    <div class="ssi-card-header">
                        <div>
                            <h2><?php echo esc_html__('Auto-push', 'substack-importer'); ?></h2>
                            <p class="desc"><?php echo esc_html__('Automatically queue LinkedIn posts when WordPress posts are published.', 'substack-importer'); ?></p>
                        </div>
                        <div class="ssi-badge ssi-badge-optional"><?php echo esc_html__('Optional','substack-importer'); ?></div>
                    </div>
                    <div class="ssi-grid">
                        <div class="ssi-field">
                            <label class="ssi-switch">
                                <input type="checkbox" name="substack_importer_li_autopush" value="1" <?php checked(get_option('substack_importer_li_autopush'), 1); ?>>
                                <span class="ssi-slider" aria-hidden="true"></span>
                                <span class="ssi-switch-label"><?php echo esc_html__('Enable auto-push on publish', 'substack-importer'); ?></span>
                            </label>
                        </div>
                        <div class="ssi-field">
                            <label class="ssi-switch">
                                <input type="checkbox" name="substack_importer_li_autopush_substack_only" value="1" <?php checked(get_option('substack_importer_li_autopush_substack_only',1), 1); ?>>
                                <span class="ssi-slider" aria-hidden="true"></span>
                                <span class="ssi-switch-label"><?php echo esc_html__('Only for Substack-imported posts', 'substack-importer'); ?></span>
                            </label>
                        </div>
                        <div class="ssi-field">
                            <label for="ssi-li-cats"><?php echo esc_html__('Limit to categories (optional)', 'substack-importer'); ?></label>
                            <?php $cats = get_categories(['hide_empty'=>0]); $sel=(array)get_option('substack_importer_li_autopush_categories',[]); ?>
                            <select id="ssi-li-cats" name="substack_importer_li_autopush_categories[]" multiple style="min-width:320px; height:140px;">
                                <?php foreach ($cats as $c): ?>
                                <option value="<?php echo (int)$c->term_id; ?>" <?php echo in_array($c->term_id, $sel, true)?'selected':''; ?>><?php echo esc_html($c->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ssi-field">
                            <label class="ssi-switch">
                                <input type="checkbox" name="substack_importer_li_include_media" value="1" <?php checked(get_option('substack_importer_li_include_media',1), 1); ?>>
                                <span class="ssi-slider" aria-hidden="true"></span>
                                <span class="ssi-switch-label"><?php echo esc_html__('Include featured image when pushing', 'substack-importer'); ?></span>
                            </label>
                        </div>
                    </div>
                    <div class="ssi-toolbar-bottom">
                        <?php submit_button(esc_html__('Save Auto-push Settings', 'substack-importer'), 'primary', 'submit', false); ?>
                    </div>
                </div>
            </form>
        </div>
        <script>
        (function($){
            $('#ssi-li-connect').on('click', function(){
                $.post(ajaxurl, {action:'substack_importer_linkedin_oauth_start', nonce: '<?php echo esc_js(wp_create_nonce('substack_importer_li')); ?>'}, function(r){
                    if (r && r.success && r.data && r.data.url) { window.location = r.data.url; }
                    else { alert('Failed to start OAuth'); }
                });
            });
            $('#ssi-li-disconnect').on('click', function(){
                if (!confirm('<?php echo esc_js(__('Disconnect LinkedIn?', 'substack-importer')); ?>')) return;
                $.post(ajaxurl, {action:'substack_importer_linkedin_disconnect', nonce: '<?php echo esc_js(wp_create_nonce('substack_importer_li')); ?>'}, function(r){
                    location.reload();
                });
            });
            $('#ssi-li-fetch-pages').on('click', function(){
                $.post(ajaxurl, {action:'substack_importer_linkedin_fetch_pages', nonce:'<?php echo esc_js(wp_create_nonce('substack_importer_li')); ?>'}, function(r){
                    if (r && r.success && r.data && r.data.pages) {
                        var $sel = $('#ssi-li-pages').empty();
                        r.data.pages.forEach(function(pg){ $sel.append('<option value="'+pg.urn+'">'+pg.name+' ('+pg.urn+')</option>'); });
                        if (!r.data.pages.length) alert('<?php echo esc_js(__('No admin pages found', 'substack-importer')); ?>');
                    } else { alert('Failed to fetch pages'); }
                });
            });
            $('#ssi-li-set-actor').on('click', function(){ var v=$('#ssi-li-pages').val(); if (!v) return alert('Select a page first'); $('#ssi-li-actor').val(v); alert('Default actor set to '+v); });
            $('#ssi-li-test').on('click', function(){
                $.post(ajaxurl, {action:'substack_importer_linkedin_test_post', nonce:'<?php echo esc_js(wp_create_nonce('substack_importer_li')); ?>', actor: $('#ssi-li-actor').val()}, function(r){
                    if (r && r.success) alert('Test sent (HTTP '+r.data.code+')'); else alert('Failed');
                });
            });
            $('#ssi-li-run-queue').on('click', function(){
                $.post(ajaxurl, {action:'substack_importer_linkedin_process_queue', nonce:'<?php echo esc_js(wp_create_nonce('substack_importer_li')); ?>'}, function(r){ alert('Queue processed. Remaining: '+(r && r.success && r.data ? r.data.remaining : '?')); });
            });
            $('#ssi-li-fetch-me').on('click', function(){
                $.post(ajaxurl, {action:'substack_importer_linkedin_fetch_me', nonce: '<?php echo esc_js(wp_create_nonce('substack_importer_li')); ?>'}, function(r){
                    if (r && r.success && r.data && r.data.urn) { $('#ssi-li-actor').val(r.data.urn); alert('Set actor URN: '+r.data.urn); }
                    else { alert('Failed to fetch.'); }
                });
            });
        })(jQuery);
        </script>
        <?php
    
    }
public function maybe_autopush_linkedin($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish') return;
        if (get_post_type($post) !== 'post') return;
        $on = (int) get_option('substack_importer_li_autopush', 0) === 1;
        if (!$on) return;
        $substack_only = (int) get_option('substack_importer_li_autopush_substack_only', 1) === 1;
        if ($substack_only) {
            $is_substack = get_post_meta((int)$post->ID, '_substack_guid', true) || get_post_meta((int)$post->ID, '_substack_source_link', true);
            if (!$is_substack) return;
        }
        $cats = (array) get_option('substack_importer_li_autopush_categories', []);
        if (!empty($cats)) {
            $post_cats = wp_get_post_categories($post->ID);
            if (empty(array_intersect($cats, $post_cats))) return;
        }
        // enqueue
        $this->linkedin->enqueue_post((int)$post->ID);
    }
    }
