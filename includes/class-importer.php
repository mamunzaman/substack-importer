<?php
namespace Substack_Importer;

if (!defined('ABSPATH')) { exit; }

class Importer {
    protected $last_utm_stats = [];

    /** @var Plugin */
    protected $plugin;

    public function __construct(Plugin $plugin) {
        $this->plugin = $plugin;
    }

    // ===== AJAX: Fetch Feed =====
    public function ajax_fetch_feed() {
        if (!$this->plugin->can_use()) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('substack_importer_fetch', 'nonce');
        $items = $this->fetch_feed_items();
        wp_send_json_success($items);
    }

    // ===== AJAX: Import Selected =====
    public function ajax_import_selected() {
        if (!$this->plugin->can_use()) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('substack_importer_import', 'nonce');

        $payload = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
        if (!$payload) wp_send_json_error('No items');
        $items = json_decode($payload, true);
        if (!is_array($items)) wp_send_json_error('Invalid payload');

        $result = $this->import_items($items);
        wp_send_json_success($result);
    }

    // ===== Re-sync AJAX endpoints =====
    public function ajax_check_update() {
        if (!$this->plugin->can_use()) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('substack_importer_check', 'nonce');
        $pid = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$pid) wp_send_json_error('Invalid');
        $res = $this->check_post_has_updates($pid);
        if ($res['found']) {
            if (!empty($res['changed'])) { update_post_meta($pid, '_substack_out_of_sync', 1); }
            else { delete_post_meta($pid, '_substack_out_of_sync'); }
        }
        wp_send_json_success($res);
    }

    public function ajax_resync_post() {
        if (!$this->plugin->can_use()) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('substack_importer_resync', 'nonce');
        $pid = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$pid) wp_send_json_error('Invalid');
        $done = $this->resync_post($pid);
        if (!$done) wp_send_json_error('Failed');
        delete_post_meta($pid, '_substack_out_of_sync');
        wp_send_json_success(['ok'=>true]);
    }

    public function ajax_check_all() {
        if (!$this->plugin->can_use()) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('substack_importer_check_all', 'nonce');
        $ids = isset($_POST['post_ids']) ? (array)$_POST['post_ids'] : [];
        $ids = array_map('intval', $ids);
        $results = [];
        foreach ($ids as $pid) {
            if ($pid <= 0) continue;
            $res = $this->check_post_has_updates($pid);
            if ($res['found']) {
                if (!empty($res['changed'])) { update_post_meta($pid, '_substack_out_of_sync', 1); }
                else { delete_post_meta($pid, '_substack_out_of_sync'); }
            }
            $results[$pid] = $res;
        }
        wp_send_json_success(['results'=>$results]);
    }

    public function ajax_preview_html_diff() {
        if (!$this->plugin->can_use()) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('substack_importer_diff', 'nonce');
        $pid = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$pid) wp_send_json_error('Invalid');
        $it = $this->find_feed_item_for_post($pid);
        if (!$it) wp_send_json_error('Not found');
        $new = $this->sanitize_html(html_entity_decode((string)$it->get_content()));
        $old_post = get_post($pid);
        $old = $old_post ? $old_post->post_content : '';
        $html = $this->render_html_diff_split($old, $new);
        wp_send_json_success(['html'=>$html]);
    }

    public function ajax_preview_html_diff_bulk() {
        if (!$this->plugin->can_use()) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('substack_importer_diff', 'nonce');
        $ids = isset($_POST['post_ids']) ? (array)$_POST['post_ids'] : [];
        $ids = array_map('intval', $ids);
        $out = '';
        foreach ($ids as $pid) {
            if ($pid <= 0) continue;
            $it = $this->find_feed_item_for_post($pid);
            if (!$it) continue;
            $new = $this->sanitize_html(html_entity_decode((string)$it->get_content()));
            $old_post = get_post($pid);
            $old = $old_post ? $old_post->post_content : '';
            $title = get_the_title($pid);
            $out .= '<h3 style="margin-top:24px;">'. esc_html($title) .' (#'. (int)$pid .')</h3>';
            $out .= $this->render_html_diff_split($old, $new);
        }
        if ($out==='') $out = '<p>'. esc_html__('No diffs available for the selected posts.', 'substack-importer') .'</p>';
        wp_send_json_success(['html'=>$out]);
    }

    public function ajax_preview_diff() {
        if (!$this->plugin->can_use()) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('substack_importer_diff', 'nonce');
        $pid = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$pid) wp_send_json_error('Invalid');
        $it = $this->find_feed_item_for_post($pid);
        if (!$it) wp_send_json_error('Not found');
        $new = $this->sanitize_html(html_entity_decode((string)$it->get_content()));
        $old_post = get_post($pid);
        $old = $old_post ? $old_post->post_content : '';
        // Convert both to text for clearer diff
        $new_txt = wp_strip_all_tags($new);
        $old_txt = wp_strip_all_tags($old);
        $html = wp_text_diff($old_txt, $new_txt, ['show_split_view'=>true]);
        if (!$html) $html = '<p>'.esc_html__('No visual differences found.', 'substack-importer').'</p>';
        wp_send_json_success(['html'=>$html]);
    }

    public function ajax_resync_changed() {
        if (!$this->plugin->can_use()) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('substack_importer_resync_changed', 'nonce');
        $ids = isset($_POST['post_ids']) ? (array)$_POST['post_ids'] : [];
        $ids = array_map('intval', $ids);
        $done = 0; $skipped = 0; $errors = 0;
        foreach ($ids as $pid) {
            if ($pid <= 0) continue;
            $flag = get_post_meta($pid, '_substack_out_of_sync', true);
            if (!$flag) { $skipped++; continue; }
            $ok = $this->resync_post($pid);
            if ($ok) { delete_post_meta($pid, '_substack_out_of_sync'); $done++; } else { $errors++; }
        }
        wp_send_json_success(['resynced'=>$done,'skipped'=>$skipped,'errors'=>$errors]);
    }



    protected function generate_side_by_side_comparison($current_title, $current_content, $feed_title, $feed_content) {
        $html = '<div class="ssi-review-comparison">';
        
        // Title comparison
        $html .= '<div class="ssi-review-section">';
        $html .= '<h3>' . esc_html__('Title Comparison', 'substack-importer') . '</h3>';
        $html .= '<div class="ssi-comparison-grid">';
        $html .= '<div class="ssi-comparison-column">';
        $html .= '<h4>' . esc_html__('Current (Published/Draft)', 'substack-importer') . '</h4>';
        $html .= '<div class="ssi-content-box">' . esc_html($current_title) . '</div>';
        $html .= '</div>';
        $html .= '<div class="ssi-comparison-column">';
        $html .= '<h4>' . esc_html__('Feed Version', 'substack-importer') . '</h4>';
        $html .= '<div class="ssi-content-box ssi-highlight-differences">' . esc_html($feed_title) . '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Content comparison
        $html .= '<div class="ssi-review-section">';
        $html .= '<h3>' . esc_html__('Content Comparison', 'substack-importer') . '</h3>';
        $html .= '<div class="ssi-comparison-grid">';
        $html .= '<div class="ssi-comparison-column">';
        $html .= '<h4>' . esc_html__('Current (Published/Draft)', 'substack-importer') . '</h4>';
        $html .= '<div class="ssi-content-box ssi-content-current">' . wp_kses_post($current_content) . '</div>';
        $html .= '</div>';
        $html .= '<div class="ssi-comparison-column">';
        $html .= '<h4>' . esc_html__('Feed Version', 'substack-importer') . '</h4>';
        $html .= '<div class="ssi-content-box ssi-content-feed ssi-highlight-differences">' . wp_kses_post($feed_content) . '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Import button section
        $html .= '<div class="ssi-review-section ssi-import-section">';
        $html .= '<div class="ssi-import-actions">';
        $html .= '<button type="button" class="button button-primary ssi-import-feed-version" data-post-id="' . esc_attr($this->current_post_id) . '">';
        $html .= esc_html__('Import Feed Version', 'substack-importer');
        $html .= '</button>';
        $html .= '<p class="ssi-import-help">' . esc_html__('This will import the feed version and change the post status to Draft for review.', 'substack-importer') . '</p>';
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '</div>';
        
        return $html;
    }

    public function ajax_import_feed_version() {
        if (!$this->plugin->can_use()) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('substack_importer_import_feed', 'nonce');
        
        $pid = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$pid) wp_send_json_error('Invalid post ID');
        
        $post = get_post($pid);
        if (!$post) wp_send_json_error('Post not found');
        
        // Get feed item
        $feed_item = $this->find_feed_item_for_post($pid);
        if (!$feed_item) wp_send_json_error('Feed item not found');
        
        // Create a revision of the current post before importing
        $revision_data = array(
            'post_parent' => $pid,
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_status' => 'inherit',
            'post_type' => 'revision',
            'post_name' => $pid . '-before-import-' . time(),
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', 1),
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1),
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_author' => $post->post_author,
        );
        
        $revision_id = wp_insert_post($revision_data);
        
        if ($revision_id && !is_wp_error($revision_id)) {
            // Add meta to identify this as a pre-import revision
            update_post_meta($revision_id, '_substack_pre_import_revision', 1);
            update_post_meta($revision_id, '_substack_import_timestamp', time());
        }
        
        // Import the feed item as a new post, bypassing duplicate checks
        $result = $this->import_single_feed_item($feed_item, $pid);
        
        if (!$result) {
            wp_send_json_error('Failed to import feed version. Please try again.');
        }
        
        // Get updated post info
        $updated_post = get_post($pid);
        $post_title = $updated_post ? $updated_post->post_title : '';
        
        // Update post meta to reflect the import
        update_post_meta($pid, '_substack_last_import', time());
        update_post_meta($pid, '_substack_imported_from_feed', 1);
        update_post_meta($pid, '_substack_pre_import_revision_id', $revision_id);
        
        // Get edit link and revision link for the updated post
        $edit_link = get_edit_post_link($pid, 'raw');
        $revision_link = admin_url('revision.php?revision=' . $revision_id);
        
        wp_send_json_success([
            'message' => __('Feed version imported successfully. Post has been updated with latest content from the feed.', 'substack-importer'),
            'edit_link' => $edit_link,
            'revision_link' => $revision_link,
            'post_id' => $pid,
            'post_title' => $post_title
        ]);
    }

    public function ajax_reset_cron_offset() {
        if (!$this->plugin->can_use()) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('substack_importer_reset_offset', 'nonce');
        
        update_option('substack_importer_cron_offset', 0);
        
        wp_send_json_success([
            'message' => __('Cron offset has been reset to 0. The next cron run will start from the beginning of the feed.', 'substack-importer')
        ]);
    }

    // ===== Cron task =====
    public function cron_task() {
        $do_check = (int)get_option('substack_importer_cron_check_updates', 0) === 1;
        $do_auto  = (int)get_option('substack_importer_cron_auto_resync', 0) === 1;
        $enabled = (int)get_option('substack_importer_cron_enabled', 0) === 1;
        if (!$enabled) return;
        
        // Track cron run time
        update_option('substack_importer_last_cron_run', time());
        
        // Get current offset and import limit
        $current_offset = (int)get_option('substack_importer_cron_offset', 0);
        $import_limit = (int)get_option('substack_importer_cron_import_limit', 10);
        
        // Fetch items with current offset
        $items = $this->fetch_feed_items($current_offset);
        
        // Apply import limit
        if ($import_limit > 0 && count($items) > $import_limit) {
            $items = array_slice($items, 0, $import_limit);
        }
        
        $result = $this->import_items($items);
        
        // Only increment offset if there were actual items processed
        // (imported, skipped, or errors - not just empty results)
        $total_processed = ($result['imported'] ?? 0) + ($result['skipped'] ?? 0) + ($result['errors'] ?? 0);
        if ($total_processed > 0) {
            $new_offset = $current_offset + $total_processed;
            update_option('substack_importer_cron_offset', $new_offset);
            $this->plugin->logger->log('cron', 'Cron Task', 'info', "Offset incremented from {$current_offset} to {$new_offset} (processed {$total_processed} items)");
        } else {
            // Log when no items were processed and offset remains unchanged
            $this->plugin->logger->log('cron', 'Cron Task', 'info', "No items processed - offset remains at {$current_offset}");
        }
        
        // Track import count
        if (isset($result['imported']) && $result['imported'] > 0) {
            update_option('substack_importer_last_import_count', $result['imported']);
        } else {
            update_option('substack_importer_last_import_count', 0);
        }
        if ($do_check) {
            $q = new \WP_Query([
                'post_type' => 'post',
                'posts_per_page' => 50,
                'meta_query' => [
                    'relation' => 'OR',
                    ['key' => '_substack_guid', 'compare' => 'EXISTS'],
                    ['key' => '_substack_source_link', 'compare' => 'EXISTS'],
                ],
                'orderby' => 'modified',
                'order' => 'DESC',
            ]);
            if ($q->have_posts()) {
                while ($q->have_posts()) { $q->the_post(); $pid = get_the_ID();
                    $res = $this->check_post_has_updates($pid);
                    if ($res['found'] && !empty($res['changed'])) {
                        update_post_meta($pid, '_substack_out_of_sync', 1);
                        if ($do_auto) { $ok = $this->resync_post($pid); if ($ok) delete_post_meta($pid, '_substack_out_of_sync'); }
                    } else { delete_post_meta($pid, '_substack_out_of_sync'); }
                }
                wp_reset_postdata();
            }
        }
    }

    // ===== Re-sync helpers =====
    public function find_feed_item_for_post($pid) {
        $guid = get_post_meta($pid, '_substack_guid', true);
        $link = get_post_meta($pid, '_substack_source_link', true);
        $feeds_raw = get_option('substack_importer_feed_urls', '');
        $lines = array_filter(array_map('trim', explode("\n", $feeds_raw)));
        if (empty($lines)) return null;
        require_once ABSPATH . WPINC . '/feed.php';
        foreach ($lines as $feed_url) {
            $rss = fetch_feed($feed_url);
            if (is_wp_error($rss)) continue;
            $maxitems = $rss->get_item_quantity(50);
            $rss_items = $rss->get_items(0, $maxitems);
            if (!$rss_items) continue;
            foreach ($rss_items as $it) {
                $it_guid = $it->get_id() ?: $it->get_link();
                $it_link = $it->get_link();
                if (($guid && $it_guid === $guid) || ($link && $it_link === $link)) {
                    return $it;
                }
            }
        }
        return null;
    }

    public function check_post_has_updates($pid) : array {
        $it = $this->find_feed_item_for_post($pid);
        if (!$it) return ['found'=>false, 'changed'=>false];
        $content = $it->get_content();
        $content = $this->sanitize_html(html_entity_decode((string)$content));
        $new_hash = md5($content);
        $old_hash = (string)get_post_meta($pid, '_substack_hash', true);
        return ['found'=>true, 'changed'=> ($new_hash !== $old_hash), 'new_hash'=>$new_hash, 'old_hash'=>$old_hash];
    }

    public function resync_post($pid) : bool {
        $it = $this->find_feed_item_for_post($pid);
        if (!$it) return false;
        $title   = get_the_title($pid);
        $guid    = get_post_meta($pid, '_substack_guid', true) ?: $it->get_id() ?: $it->get_link();
        $link    = get_post_meta($pid, '_substack_source_link', true) ?: $it->get_link();
        $date    = $it->get_date('Y-m-d H:i:s');
        $content = $this->sanitize_html(html_entity_decode((string)$it->get_content()));
        $hash    = md5($content);

        $old_hash = (string)get_post_meta($pid, '_substack_hash', true);
        if ($hash === $old_hash) return true;

        $p = get_post($pid);
        $cats = wp_get_post_categories($pid);

        $img_urls = $this->collect_image_urls($content);
        $first_local_url = '';
        if (!empty($img_urls)) {
            $featured_set = has_post_thumbnail($pid);
            foreach ($img_urls as $img_url) {
                $attach_id = $this->sideload_image_hardened($img_url, $pid);
                if ($attach_id) {
                    $new_url = wp_get_attachment_url($attach_id);
                    if ($new_url) {
                        $content = preg_replace('/'. preg_quote($img_url,'/') .'(\?[^\s"\']*)?/i', $new_url, $content);
                        if (!$featured_set) { set_post_thumbnail($pid, $attach_id); $featured_set = true; $first_local_url = $new_url; }
                    }
                }
            }
        }

        if ($first_local_url) {
            $content = $this->remove_image_from_content($content, $first_local_url);
        }

        $content = $this->normalize_substack_captioned_images($content);
        
        // Use enhanced Gutenberg conversion if enabled
        $use_enhanced_blocks = (int)get_option('substack_importer_enhanced_gutenberg', 1) === 1;
        if ($use_enhanced_blocks) {
            $blocks_content = $this->convert_html_to_blocks_enhanced($content, $pid);
        } else {
            $blocks_content = $this->convert_html_to_blocks($content, $pid);
        }

        // Final validation to ensure Gutenberg compatibility
        if ($blocks_content) {
            $validation = $this->validate_gutenberg_content($blocks_content);
            if (!$validation['valid']) {
                // Log validation errors
                $this->plugin->logger->log($link ?: $guid, $title, 'warning', 'Gutenberg validation errors during resync: ' . implode(', ', $validation['errors']), $pid);
            }
        }
        
        $upd = wp_update_post([
            'ID'           => $pid,
            'post_content' => $blocks_content ?: $content,
            'post_date'    => $date ?: current_time('mysql'),
            'post_category'=> $cats,
            'post_status'  => $p->post_status,
        ], true);
        if (is_wp_error($upd)) return false;

        update_post_meta($pid, '_substack_guid',  $guid);
        update_post_meta($pid, '_substack_hash',  $hash);
        update_post_meta($pid, '_substack_title', $title);
        if ($link) update_post_meta($pid, '_substack_source_link', esc_url_raw($link));

        $stats = !empty($this->last_utm_stats) ? ' | utm=' . wp_json_encode($this->last_utm_stats) : ''; $this->plugin->logger->log($link ?: $guid, $title, 'resynced', 'Content updated' . $stats, $pid);
        return true;
    }

    // ===== Core: fetch items from all feeds =====
    protected function fetch_feed_items($offset = 0) : array {
        $feeds_raw = get_option('substack_importer_feed_urls', '');
        $lines = array_filter(array_map('trim', explode("\n", $feeds_raw)));
        if (empty($lines)) return [];

        require_once ABSPATH . WPINC . '/feed.php';

        $items = [];
        foreach ($lines as $feed_url) {
            $rss = fetch_feed($feed_url);
            if (is_wp_error($rss)) continue;

            $maxitems = $rss->get_item_quantity(50);
            $rss_items = $rss->get_items($offset, $maxitems);
            if (!$rss_items) continue;

            foreach ($rss_items as $it) {
                $guid    = $it->get_id() ?: $it->get_link();
                $title   = $it->get_title();
                $link    = $it->get_link();
                $date    = $it->get_date('Y-m-d H:i:s');
                $content = $it->get_content();

                $cats = [];
                $ic = $it->get_categories();
                if (is_array($ic)) {
                    foreach ($ic as $cobj) {
                        $label = trim($cobj->get_label());
                        if ($label !== '') $cats[] = $label;
                    }
                    $cats = array_values(array_unique($cats));
                }

                $exists  = $this->post_exists_by_meta($guid, $title, md5((string)$content));

                $items[] = [
                    'guid'       => $guid,
                    'title'      => $title,
                    'link'       => $link,
                    'date'       => $date,
                    'content'    => $content,
                    'exists'     => $exists,
                    'feed_terms' => $cats,
                ];
            }
        }
        return $items;
    }

    // ===== Core: import items array =====
    protected function import_items(array $items) : array {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        $imported=0; $skipped=0; $errors=0;
        $default_status = get_option('substack_importer_default_status','draft');

        foreach ($items as $row) {
            $title = sanitize_text_field($row['title'] ?? '');
            $guid  = esc_url_raw($row['guid'] ?? '');
            $date  = sanitize_text_field($row['date'] ?? '');
            $link  = esc_url_raw($row['link'] ?? '');
            $feed_terms = isset($row['feed_terms']) && is_array($row['feed_terms']) ? $row['feed_terms'] : [];
            $cats_manual = isset($row['categories']) && is_array($row['categories']) ? array_map('intval', $row['categories']) : [];
            $content_raw = (string)($row['content'] ?? '');

            $content = $this->sanitize_html(html_entity_decode($content_raw));
            $hash = md5($content);
            if ($this->post_exists_by_meta($guid, $title, $hash)) { $skipped++; $this->plugin->logger->log($link ?: $guid, $title, 'skipped', 'Duplicate'); continue; }

            $cats = $cats_manual;
            if (empty($cats) && !empty($feed_terms)) {
                $mapped = $this->apply_term_mapping($feed_terms);
                if (!empty($mapped)) $cats = $mapped;
            }
            if (empty($cats) && !empty($feed_terms)) {
                $cats = $this->map_categories($feed_terms);
            }
            if (empty($cats)) $cats = [ (int)get_option('default_category', 1) ];

            $post_id = wp_insert_post([
                'post_title'    => $title,
                'post_content'  => $content,
                'post_status'   => $default_status,
                'post_date'     => $date ?: current_time('mysql'),
                'post_category' => $cats,
            ], true);

            if (is_wp_error($post_id) || !$post_id) { $errors++; $this->plugin->logger->log($link ?: $guid, $title, 'error', is_wp_error($post_id)?$post_id->get_error_message():'Insert failed'); continue; }

            update_post_meta($post_id, '_substack_guid',  $guid);
            update_post_meta($post_id, '_substack_hash',  $hash);
            update_post_meta($post_id, '_substack_title', $title);
            if ($link) update_post_meta($post_id, '_substack_source_link', esc_url_raw($link));

            $img_urls = $this->collect_image_urls($content);
            $first_local_url = '';
            if (!empty($img_urls)) {
                $featured_set=false;
                foreach ($img_urls as $img_url) {
                    $attach_id = $this->sideload_image_hardened($img_url, $post_id);
                    if ($attach_id) {
                        $new_url = wp_get_attachment_url($attach_id);
                        if ($new_url) {
                            $content = preg_replace('/'. preg_quote($img_url,'/') .'(\?[^\s"\']*)?/i', $new_url, $content);
                            if (!$featured_set) {
                                set_post_thumbnail($post_id, $attach_id);
                                $featured_set=true;
                                $first_local_url=$new_url;
                            }
                        }
                    }
                }
                wp_update_post(['ID'=>$post_id, 'post_content'=>$content]);
            }

            if ($first_local_url) {
                $content = $this->remove_image_from_content($content, $first_local_url);
                wp_update_post(['ID'=>$post_id, 'post_content'=>$content]);
            }

            $content = $this->normalize_substack_captioned_images($content);
            
            // Use enhanced Gutenberg conversion if enabled
            $use_enhanced_blocks = (int)get_option('substack_importer_enhanced_gutenberg', 1) === 1;
            if ($use_enhanced_blocks) {
                $blocks_content = $this->convert_html_to_blocks_enhanced($content, $post_id);
            } else {
                $blocks_content = $this->convert_html_to_blocks($content, $post_id);
            }
            
            if (!empty($blocks_content)) {
                // Final validation to ensure Gutenberg compatibility
                $validation = $this->validate_gutenberg_content($blocks_content);
                if (!$validation['valid']) {
                    // Log validation errors
                    $this->plugin->logger->log($link ?: $guid, $title, 'warning', 'Gutenberg validation errors: ' . implode(', ', $validation['errors']), $post_id);
                }
                
                wp_update_post(['ID'=>$post_id, 'post_content'=>$blocks_content]);
            }

            $imported++;
            $stats = !empty($this->last_utm_stats) ? ' | utm=' . wp_json_encode($this->last_utm_stats) : ''; $this->plugin->logger->log($link ?: $guid, $title, 'imported', ucfirst($default_status) . $stats, $post_id);
        }

        return ['imported'=>$imported,'skipped'=>$skipped,'errors'=>$errors];
    }

    /**
     * Import a single feed item, bypassing duplicate checks
     * This method is used by the Import action button to force import regardless of duplicates
     */
    protected function import_single_feed_item($feed_item, $existing_post_id = 0) : bool {
        if (!$feed_item) return false;
        
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        
        $guid = $feed_item->get_id() ?: $feed_item->get_link();
        $title = $feed_item->get_title();
        $link = $feed_item->get_link();
        $date = $feed_item->get_date('Y-m-d H:i:s');
        $content_raw = $feed_item->get_content();
        
        // Get categories from feed
        $feed_terms = [];
        $ic = $feed_item->get_categories();
        if (is_array($ic)) {
            foreach ($ic as $cobj) {
                $label = trim($cobj->get_label());
                if ($label !== '') $feed_terms[] = $label;
            }
            $feed_terms = array_values(array_unique($feed_terms));
        }
        
        $content = $this->sanitize_html(html_entity_decode($content_raw));
        $hash = md5($content);
        
        // Determine post status - if updating existing post, keep its status
        $post_status = 'draft';
        if ($existing_post_id) {
            $existing_post = get_post($existing_post_id);
            if ($existing_post) {
                $post_status = $existing_post->post_status;
            }
        }
        
        // Map categories
        $cats = [];
        if (!empty($feed_terms)) {
            $mapped = $this->apply_term_mapping($feed_terms);
            if (!empty($mapped)) {
                $cats = $mapped;
            } else {
                $cats = $this->map_categories($feed_terms);
            }
        }
        if (empty($cats)) {
            $cats = [(int)get_option('default_category', 1)];
        }
        
        if ($existing_post_id) {
            // Update existing post
            $update_data = [
                'ID' => $existing_post_id,
                'post_title' => $title,
                'post_content' => $content,
                'post_date' => $date ?: current_time('mysql'),
                'post_category' => $cats,
                'post_status' => $post_status,
            ];
            
            $result = wp_update_post($update_data, true);
            if (is_wp_error($result)) return false;
            
            $post_id = $existing_post_id;
        } else {
            // Create new post
            $post_id = wp_insert_post([
                'post_title' => $title,
                'post_content' => $content,
                'post_status' => $post_status,
                'post_date' => $date ?: current_time('mysql'),
                'post_category' => $cats,
            ], true);
            
            if (is_wp_error($post_id) || !$post_id) return false;
        }
        
        // Update post meta
        update_post_meta($post_id, '_substack_guid', $guid);
        update_post_meta($post_id, '_substack_hash', $hash);
        update_post_meta($post_id, '_substack_title', $title);
        if ($link) {
            update_post_meta($post_id, '_substack_source_link', esc_url_raw($link));
        }
        
        // Handle images
        $img_urls = $this->collect_image_urls($content);
        $first_local_url = '';
        if (!empty($img_urls)) {
            $featured_set = has_post_thumbnail($post_id);
            foreach ($img_urls as $img_url) {
                $attach_id = $this->sideload_image_hardened($img_url, $post_id);
                if ($attach_id) {
                    $new_url = wp_get_attachment_url($attach_id);
                    if ($new_url) {
                        $content = preg_replace('/'. preg_quote($img_url,'/') .'(\?[^\s"\']*)?/i', $new_url, $content);
                        if (!$featured_set) {
                            set_post_thumbnail($post_id, $attach_id);
                            $featured_set = true;
                            $first_local_url = $new_url;
                        }
                    }
                }
            }
            
            if ($first_local_url) {
                $content = $this->remove_image_from_content($content, $first_local_url);
            }
            
            // Update content with processed images
            wp_update_post(['ID' => $post_id, 'post_content' => $content]);
        }
        
        // Normalize captioned images
        $content = $this->normalize_substack_captioned_images($content);
        
        // Convert to Gutenberg blocks
        $use_enhanced_blocks = (int)get_option('substack_importer_enhanced_gutenberg', 1) === 1;
        if ($use_enhanced_blocks) {
            $blocks_content = $this->convert_html_to_blocks_enhanced($content, $post_id);
        } else {
            $blocks_content = $this->convert_html_to_blocks($content, $post_id);
        }
        
        if (!empty($blocks_content)) {
            // Final validation to ensure Gutenberg compatibility
            $validation = $this->validate_gutenberg_content($blocks_content);
            if (!$validation['valid']) {
                // Log validation errors
                $this->plugin->logger->log($link ?: $guid, $title, 'warning', 'Gutenberg validation errors: ' . implode(', ', $validation['errors']), $post_id);
            }
            
            wp_update_post(['ID' => $post_id, 'post_content' => $blocks_content]);
        }
        
        // Log the import
        $stats = !empty($this->last_utm_stats) ? ' | utm=' . wp_json_encode($this->last_utm_stats) : '';
        $this->plugin->logger->log($link ?: $guid, $title, 'imported', ucfirst($post_status) . $stats, $post_id);
        
        return true;
    }



    // ===== Mapping helpers (Exact/CI/Regex) =====
    protected function apply_term_mapping(array $labels) : array {
        $rows = get_option('substack_importer_term_map', []);
        if (!$rows) return [];
        $ids = [];
        foreach ($labels as $lab) {
            $L = (string)$lab;
            foreach ($rows as $row) {
                $type = $row['type'] ?? 'exact';
                $label = (string)($row['label'] ?? '');
                $tid = (int)($row['term_id'] ?? 0);
                if ($tid <= 0 || $label === '') continue;
                if ($type === 'exact' && $L === $label) {
                    $ids[] = $tid; continue;
                }
                if ($type === 'ci' && strtolower($L) === strtolower($label)) {
                    $ids[] = $tid; continue;
                }
                if ($type === 'regex') {
                    $pattern = '#' . str_replace('#','\\#',$label) . '#u';
                    if (@preg_match($pattern, $L)) {
                        if (preg_match($pattern, $L)) $ids[] = $tid;
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    protected function map_categories(array $labels) : array {
        $term_ids = [];
        foreach ($labels as $label) {
            $label = sanitize_text_field($label);
            if ($label === '') continue;
            $term = get_term_by('name', $label, 'category');
            if (!$term) $term = wp_insert_term($label, 'category');
            if (is_wp_error($term)) continue;
            $term_ids[] = (int)(is_array($term) ? $term['term_id'] : $term->term_id);
        }
        return array_values(array_unique(array_filter($term_ids)));
    }

    protected function post_exists_by_meta($guid, $title, $hash) : bool {
        global $wpdb;
        if ($guid) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT p.ID FROM $wpdb->posts p INNER JOIN $wpdb->postmeta pm ON p.ID=pm.post_id
                 WHERE pm.meta_key='_substack_guid' AND pm.meta_value=%s LIMIT 1", $guid
            ));
            if ($id) return true;
        }
        $id2 = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_substack_hash' AND meta_value=%s LIMIT 1", $hash
        ));
        return !empty($id2);
    }

    // ===== Sanitization =====
    public function sanitize_html($html) : string {
        if ($html === '' || $html === null) return '';
        $html = preg_replace('#<(script|style)[^>]*>.*?</\\1>#is', '', $html);
        
        // Clean up hr tags to prevent Gutenberg validation errors
        $html = preg_replace_callback('#<hr[^>]*>#i', function($m) {
            $tag = $m[0];
            // Remove any potentially problematic attributes except class, style, and id
            $tag = preg_replace('/\s+(?!(class|style|id)\s*=)[a-z-]+\s*=\s*(["\'])[^"\']*\2/i', '', $tag);
            // Ensure proper closing
            if (substr($tag, -1) !== '>') {
                $tag = rtrim($tag, '/') . '>';
            }
            // Ensure proper spacing
            $tag = preg_replace('/\s+/', ' ', $tag);
            return $tag;
        }, $html);
        
        // Replace <div><hr></div> patterns with native Gutenberg separator blocks
        $html = preg_replace_callback('#<div[^>]*>\s*<hr[^>]*>\s*</div>#i', function($m) {
            // Extract any attributes from the hr tag
            if (preg_match('/<hr([^>]*)>/i', $m[0], $hr_matches)) {
                $hr_attributes = $hr_matches[1];
                
                // Build clean hr tag without inline styles for better Gutenberg compatibility
                $clean_hr = '<hr';
                if (preg_match('/class\s*=\s*["\']([^"\']*)["\']/i', $hr_attributes, $class_match)) {
                    $classes = array_filter(array_map('trim', explode(' ', $class_match[1])));
                    $classes[] = 'wp-block-separator';
                    $clean_hr .= ' class="' . esc_attr(implode(' ', array_unique($classes))) . '"';
                } else {
                    $clean_hr .= ' class="wp-block-separator"';
                }
                
                $clean_hr .= '>';
                
                return $clean_hr;
            }
            
            // Fallback if no hr attributes found
            return '<hr class="wp-block-separator">';
        }, $html);
        
        // Clean up other potentially problematic elements for better editor compatibility
        $html = preg_replace_callback('#<(div|span|p)[^>]*>#i', function($m) {
            $tag = $m[0];
            // Remove any potentially problematic attributes
            $tag = preg_replace('/\s+(?!(class|style|id|align)\s*=)[a-z-]+\s*=\s*(["\'])[^"\']*\2/i', '', $tag);
            // Ensure proper spacing
            $tag = preg_replace('/\s+/', ' ', $tag);
            return $tag;
        }, $html);
        
        // Clean up malformed HTML entities
        $html = preg_replace('/&([a-zA-Z0-9]+);/', '&amp;$1;', $html);
        $html = str_replace(['&amp;amp;', '&amp;quot;', '&amp;lt;', '&amp;gt;'], ['&amp;', '&quot;', '&lt;', '&gt;'], $html);
        
        $allowed_iframe = [
            'iframe' => [
                'src'=>true,'width'=>true,'height'=>true,'frameborder'=>true,'allow'=>true,'allowfullscreen'=>true,'loading'=>true,'title'=>true
            ],
        ];
        $allowed = wp_kses_allowed_html('post');
        $allowed = array_merge($allowed, $allowed_iframe);
        $html = wp_kses($html, $allowed);
        $html = preg_replace('/\son[a-z]+\s*=\s*(["\']).*?\1/i', '', $html);
        $html = preg_replace_callback('#<iframe[^>]+src=["\']([^"\']+)#i', function($m){
            $ok = preg_match('#^(https?:)?//(www\.)?(youtube\.com|youtu\.be|player\.vimeo\.com|w\.soundcloud\.com)/#i', $m[1]);
            return $ok ? $m[0] : str_replace($m[1], '', $m[0]);
        }, $html);
        $html = preg_replace_callback('#<img([^>]*?)>#i', function($m){
            $tag = $m[0];
            if (!preg_match('/\balt=/i', $tag) && preg_match('/\bsrc=["\']([^"\']+)["\']/', $tag, $srcm)) {
                $alt = esc_attr($this->human_alt_from_url($srcm[1]));
                $tag = rtrim($tag, '>') . ' alt="' . $alt . '">';
            }
            return $tag;
        }, $html);
        return $html;
    }

    // ===== Media helpers =====
    protected function collect_image_urls($html) : array {
        $urls = [];
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m1)) $urls = array_merge($urls, $m1[1]);
        if (preg_match_all('/<img[^>]+data-src=["\']([^"\']+)["\']/i', $html, $m2)) $urls = array_merge($urls, $m2[1]);
        if (preg_match_all('/<img[^>]+srcset=["\']([^"\']+)["\']/i', $html, $m3)) {
            foreach ($m3[1] as $srcset) {
                $best=''; $best_w=0;
                foreach (array_map('trim', explode(',', $srcset)) as $cand) {
                    if (preg_match('/^\s*([^ ]+)\s+(\d+)w/i', $cand, $mm)) {
                        $url=$mm[1]; $w=(int)$mm[2]; if ($w>$best_w){$best_w=$w;$best=$url;}
                    } else $best = trim(preg_split('/\s+/', $cand)[0]);
                }
                if ($best) $urls[]=$best;
            }
        }
        $urls = array_map(function($u){ return strpos($u,'//')===0 ? 'https:'.$u : $u; }, $urls);
        $urls = array_values(array_filter($urls, function($u){ return stripos($u,'http://')===0 || stripos($u,'https://')===0; }));
        return array_values(array_unique($urls));
    }

    protected function sideload_image_hardened($url, $post_id) {
        static $cache = [];
        if (!$url) return false;
        $normalized = strpos($url,'//')===0 ? 'https:'.$url : $url;
        if (isset($cache[$normalized])) return $cache[$normalized];

        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        // First, try to find existing image by URL in media library
        $existing_attachment = $this->find_existing_image($normalized);
        if ($existing_attachment) {
            return $cache[$normalized] = $existing_attachment;
        }

        $tmp = download_url($normalized);
        if (is_wp_error($tmp)) return $cache[$normalized]=false;

        $hash = @md5_file($tmp);
        if (!$hash) { @unlink($tmp); return $cache[$normalized]=false; }

        // Check if image with same hash already exists
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_substack_image_hash' AND meta_value=%s LIMIT 1", $hash
        ));
        if ($existing) { @unlink($tmp); return $cache[$normalized]=(int)$existing; }

        $path = parse_url($normalized, PHP_URL_PATH);
        $base = $path ? basename($path) : 'image.jpg';
        if (!preg_match('/\.(jpe?g|png|gif|webp|bmp|tiff?)$/i', $base)) $base .= '.jpg';

        $file_array = ['name'=>$base,'tmp_name'=>$tmp];
        $attach_id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($attach_id)) { @unlink($tmp); return $cache[$normalized]=false; }

        update_post_meta($attach_id, '_substack_image_hash', $hash);
        update_post_meta($attach_id, '_substack_source_url', esc_url_raw($normalized));
        $alt = $this->human_alt_from_url($normalized);
        if ($alt) update_post_meta($attach_id, '_wp_attachment_image_alt', $alt);

        return $cache[$normalized]=(int)$attach_id;
    }

    /**
     * Find existing image in media library by URL or similar attributes
     */
    protected function find_existing_image($url) {
        global $wpdb;
        
        // Try to find by exact URL match
        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id) {
            return $attachment_id;
        }
        
        // Try to find by source URL meta
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_substack_source_url' AND meta_value=%s LIMIT 1",
            $url
        ));
        if ($attachment_id) {
            return (int)$attachment_id;
        }
        
        // Try to find by filename
        $filename = basename(parse_url($url, PHP_URL_PATH));
        if ($filename) {
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND guid LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like($filename) . '%'
            ));
            if ($attachment_id) {
                return (int)$attachment_id;
            }
        }
        
        return false;
    }

    protected function human_alt_from_url($url) : string {
        $path = parse_url($url, PHP_URL_PATH);
        $base = $path ? basename($path) : '';
        $name = $base ? pathinfo($base, PATHINFO_FILENAME) : '';
        $name = preg_replace('/[-_]+/',' ', $name);
        $name = preg_replace('/\s+(\d+|[a-f0-9]{6,})$/i','', trim($name));
        $name = trim($name);
        return $name !== '' ? ucwords($name) : __('Substack Image', 'substack-importer');
    }

    // ===== HTML transforms =====
    protected function normalize_substack_captioned_images($html) : string {
        if (empty($html)) return $html;
        $prev = libxml_use_internal_errors(true);
        $dom  = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<!doctype html><meta charset="utf-8"><body>' . $html . '</body>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);
        $body  = $dom->getElementsByTagName('body')->item(0);
        if (!$body) { libxml_clear_errors(); libxml_use_internal_errors($prev); return $html; }

        $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' captioned-image-container ')]//figure");
        foreach ($nodes as $figure) {
            /** @var \DOMElement $figure */
            $imgs = $figure->getElementsByTagName('img');
            if (!$imgs->length) continue;
            $img = $imgs->item(0);
            $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
            if ($src && strpos($src, '//')===0) $src = 'https:'.$src;
            if (!$src) continue;
            $alt = $img->getAttribute('alt');
            if ($alt==='') $alt = $this->human_alt_from_url($src);

            $caption_html = '';
            $fcs = $figure->getElementsByTagName('figcaption');
            if ($fcs->length) {
                foreach ($fcs->item(0)->childNodes as $child) $caption_html .= $dom->saveHTML($child);
                $caption_html = trim($caption_html);
            }

            $newFigure = $dom->createElement('figure');
            $newFigure->setAttribute('class','wp-block-image size-full');

            $newImg = $dom->createElement('img');
            $newImg->setAttribute('src', $src);
            if ($alt !== '') $newImg->setAttribute('alt', $alt);
            $newFigure->appendChild($newImg);

            if ($caption_html !== '') {
                $newCap = $dom->createElement('figcaption');
                // Convert HTML to plain text to prevent raw HTML display
                $caption_text = wp_strip_all_tags($caption_html);
                $newCap->textContent = $caption_text;
                $newCap->setAttribute('class', 'wp-element-caption');
                $newFigure->appendChild($newCap);
            }

            $figure->parentNode->replaceChild($newFigure, $figure);
            $parent = $newFigure->parentNode;
            if ($parent && $parent->nodeName==='div') {
                $parent->parentNode->insertBefore($newFigure->cloneNode(true), $parent);
                $parent->parentNode->removeChild($parent);
            }
        }

        $out=''; foreach ($body->childNodes as $child) $out .= $dom->saveHTML($child);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        return $out;
    }

    protected function convert_html_to_blocks($html, $post_id = 0) : string {
        if (empty($html)) return '';
        $html = str_replace(["\r\n","\r"], "\n", $html);
        
        // Pre-process HTML to clean up any problematic hr tags
        $html = $this->clean_hr_tags($html);
        
        $prev = libxml_use_internal_errors(true);
        $dom  = new \DOMDocument('1.0','UTF-8');
        $dom->loadHTML('<!doctype html><meta charset="utf-8"><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) { libxml_clear_errors(); libxml_use_internal_errors($prev); return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->"; }

        $innerHTML = function(\DOMNode $node) use ($dom){ $o=''; foreach($node->childNodes as $c) $o.=$dom->saveHTML($c); return $o; };
        $bestImgSrc = function(\DOMElement $img){
            $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
            if (!$src && ($srcset=$img->getAttribute('srcset'))) {
                $best='';$w=0; foreach (array_map('trim', explode(',', $srcset)) as $cand) {
                    if (preg_match('/^\s*([^ ]+)\s+(\d+)w/i', $cand, $m)) { if ((int)$m[2]>$w){$w=(int)$m[2];$best=$m[1];} }
                    else { $parts = preg_split('/\s+/', $cand); if (!empty($parts[0])) $best = $parts[0]; }
                } $src=$best;
            }
            if ($src && strpos($src,'//')===0) $src='https:'.$src;
            return $src;
        };
        // Enhanced image block creation with better Gutenberg compatibility
        $imageBlock = function($src, $alt='', $caption='') use ($post_id){
            if ($alt==='') $alt = $this->human_alt_from_url($src);
            $att_id = attachment_url_to_postid($src);
            
            // Enhanced attributes for better Gutenberg compatibility
            $attr = [
                'sizeSlug' => 'full',
                'linkDestination' => 'none',
                'align' => 'center',
                'className' => 'is-style-default'
            ];
            
            if ($att_id) {
                $attr['id'] = (int)$att_id;
                // Get image dimensions for better responsive handling
                $image_data = wp_get_attachment_image_src($att_id, 'full');
                if ($image_data) {
                    $attr['width'] = $image_data[1];
                    $attr['height'] = $image_data[2];
                }
            }
            
            $attr_json = ' ' . wp_json_encode($attr);
            $alt_attr = $alt!=='' ? ' alt="'.esc_attr($alt).'"' : '';
            
            // Enhanced image HTML with better responsive classes
            $fig_inner = '<img src="'.esc_url($src).'"'.$alt_attr.' class="wp-image-'.($att_id ?: 'placeholder').'" />';
            if ($caption!=='') {
                $fig_inner .= '<figcaption class="wp-element-caption">'.esc_html($caption).'</figcaption>';
            }
            
            $fig = '<figure class="wp-block-image size-full aligncenter">'.$fig_inner.'</figure>';
            return "<!-- wp:image{$attr_json} -->\n{$fig}\n<!-- /wp:image -->";
        };

        $blocks=[];

        foreach (iterator_to_array($body->childNodes) as $node) {
            if ($node->nodeType===XML_TEXT_NODE) {
                $text = trim($node->textContent);
                if ($text!=='') $blocks[]="<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
                continue;
            }
            if ($node->nodeType!==XML_ELEMENT_NODE) continue;
            $tag = strtolower($node->nodeName);

            if ($tag==='figure') {
                $imgs = $node->getElementsByTagName('img');
                if ($imgs->length) {
                    $img=$imgs->item(0);
                    $src=$bestImgSrc($img);
                    if ($src) {
                        $alt = $img->getAttribute('alt'); if ($alt==='') $alt = $this->human_alt_from_url($src);
                        $caption = '';
                        $fcs = $node->getElementsByTagName('figcaption');
                        if ($fcs->length) { 
                            // Extract caption text content only, not HTML
                            $caption = trim($fcs->item(0)->textContent); 
                        }
                        $blocks[]=$imageBlock($src,$alt,$caption); continue;
                    }
                }
                $blocks[]="<!-- wp:html -->\n".$dom->saveHTML($node)."\n<!-- /wp:html -->"; continue;
            }

            if (in_array($tag, ['h1','h2','h3','h4','h5','h6'], true)) {
                $level=(int)substr($tag,1); $content=trim($innerHTML($node));
                $attr=$level!==2 ? ' '.wp_json_encode(['level'=>$level]) : '';
                $blocks[]="<!-- wp:heading{$attr} -->\n<{$tag}>{$content}</{$tag}>\n<!-- /wp:heading -->"; continue;
            }

            if ($tag==='ul' || $tag==='ol') {
                $ordered = ($tag==='ol');
                $lis=$node->getElementsByTagName('li'); $list_inner='';
                foreach($lis as $li) $list_inner.='<li>'.trim($innerHTML($li)).'</li>';
                $attr = $ordered ? ' '.wp_json_encode(['ordered'=>true]) : '';
                $blocks[]="<!-- wp:list{$attr} -->\n<{$tag}>\n{$list_inner}\n</{$tag}>\n<!-- /wp:list -->"; continue;
            }

            if ($tag==='blockquote') {
                $content=trim($innerHTML($node));
                $blocks[]="<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">{$content}</blockquote>\n<!-- /wp:quote -->"; continue;
            }

            if ($tag==='img') {
                $src=$bestImgSrc($node);
                if ($src) { $alt=$node->getAttribute('alt'); if ($alt==='') $alt=$this->human_alt_from_url($src); $blocks[]=$imageBlock($src,$alt,''); continue; }
            }

            if ($tag==='p') {
                $content=trim($innerHTML($node));
                if ($content!=='') $blocks[]="<!-- wp:paragraph -->\n<p>{$content}</p>\n<!-- /wp:paragraph -->";
                continue;
            }

            if ($tag==='hr') {
                // Convert hr tags to proper Gutenberg separator blocks to avoid validation errors
                // Create clean separator block without inline styles for better Gutenberg compatibility
                $blocks[]="<!-- wp:separator -->\n<hr class=\"wp-block-separator\">\n<!-- /wp:separator -->";
                continue;
            }

            $blocks[]="<!-- wp:html -->\n".$dom->saveHTML($node)."\n<!-- /wp:html -->";
        }

        libxml_clear_errors(); libxml_use_internal_errors($prev);
        
        $output = implode("\n\n", $blocks);
        
        // Final cleanup to ensure all hr tags are properly formatted
        $output = $this->finalize_hr_blocks($output);
        
        // Ensure compatibility with both Gutenberg and Classic Editor
        $output = $this->ensure_editor_compatibility($output);
        
        return $output;
    }

    /**
     * Enhanced HTML to Gutenberg blocks conversion with better compatibility
     */
    protected function convert_html_to_blocks_enhanced($html, $post_id = 0) : string {
        if (empty($html)) return '';
        $html = str_replace(["\r\n","\r"], "\n", $html);
        
        // Pre-process HTML to clean up any problematic hr tags
        $html = $this->clean_hr_tags($html);
        
        $prev = libxml_use_internal_errors(true);
        $dom  = new \DOMDocument('1.0','UTF-8');
        $dom->loadHTML('<!doctype html><meta charset="utf-8"><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) { libxml_clear_errors(); libxml_use_internal_errors($prev); return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->"; }

        $innerHTML = function(\DOMNode $node) use ($dom){ $o=''; foreach($node->childNodes as $c) $o.=$dom->saveHTML($c); return $o; };
        $bestImgSrc = function(\DOMElement $img){
            $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
            if (!$src && ($srcset=$img->getAttribute('srcset'))) {
                $best='';$w=0; foreach (array_map('trim', explode(',', $srcset)) as $cand) {
                    if (preg_match('/^\s*([^ ]+)\s+(\d+)w/i', $cand, $m)) { if ((int)$m[2]>$w){$w=(int)$m[2];$best=$m[1];} }
                    else { $parts = preg_split('/\s+/', $cand); if (!empty($parts[0])) $best = $parts[0]; }
                } $src=$best;
            }
            if ($src && strpos($src,'//')===0) $src='https:'.$src;
            return $src;
        };
        
        // Enhanced image block creation with better Gutenberg compatibility
        $imageBlock = function($src, $alt='', $caption='') use ($post_id){
            if ($alt==='') $alt = $this->human_alt_from_url($src);
            $att_id = attachment_url_to_postid($src);
            
            // Enhanced attributes for better Gutenberg compatibility
            $attr = [
                'sizeSlug' => 'full',
                'linkDestination' => 'none',
                'align' => 'center',
                'className' => 'is-style-default'
            ];
            
            if ($att_id) {
                $attr['id'] = (int)$att_id;
                // Get image dimensions for better responsive handling
                $image_data = wp_get_attachment_image_src($att_id, 'full');
                if ($image_data) {
                    $attr['width'] = $image_data[1];
                    $attr['height'] = $image_data[2];
                }
            }
            
            $attr_json = ' ' . wp_json_encode($attr);
            $alt_attr = $alt!=='' ? ' alt="'.esc_attr($alt).'"' : '';
            
            // Enhanced image HTML with better responsive classes
            $fig_inner = '<img src="'.esc_url($src).'"'.$alt_attr.' class="wp-image-'.($att_id ?: 'placeholder').'" />';
            if ($caption!=='') {
                $fig_inner .= '<figcaption class="wp-element-caption">'.esc_html($caption).'</figcaption>';
            }
            
            $fig = '<figure class="wp-block-image size-full aligncenter">'.$fig_inner.'</figure>';
            return "<!-- wp:image{$attr_json} -->\n{$fig}\n<!-- /wp:image -->";
        };

        // Enhanced block creation with better Gutenberg compatibility
        $blocks = [];
        $current_paragraph_content = '';

        foreach (iterator_to_array($body->childNodes) as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = trim($node->textContent);
                if ($text !== '') {
                    $current_paragraph_content .= $text . ' ';
                }
                continue;
            }
            
            if ($node->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($node->nodeName);

            // Flush accumulated paragraph content
            if ($current_paragraph_content !== '' && !in_array($tag, ['p', 'div', 'span'])) {
                $current_paragraph_content = trim($current_paragraph_content);
                if ($current_paragraph_content !== '') {
                    $blocks[] = "<!-- wp:paragraph -->\n<p>{$current_paragraph_content}</p>\n<!-- /wp:paragraph -->";
                }
                $current_paragraph_content = '';
            }

            if ($tag === 'figure') {
                $imgs = $node->getElementsByTagName('img');
                if ($imgs->length) {
                    $img = $imgs->item(0);
                    $src = $bestImgSrc($img);
                    if ($src) {
                        $alt = $img->getAttribute('alt'); 
                        if ($alt === '') $alt = $this->human_alt_from_url($src);
                        $caption = '';
                        $fcs = $node->getElementsByTagName('figcaption');
                        if ($fcs->length) { 
                            // Extract caption text content only, not HTML
                            $caption = trim($fcs->item(0)->textContent); 
                        }
                        $blocks[] = $imageBlock($src, $alt, $caption); 
                        continue;
                    }
                }
                $blocks[] = "<!-- wp:html -->\n".$dom->saveHTML($node)."\n<!-- /wp:html -->"; 
                continue;
            }

            if (in_array($tag, ['h1','h2','h3','h4','h5','h6'], true)) {
                $level = (int)substr($tag,1); 
                $content = trim($innerHTML($node));
                $attr = $level !== 2 ? ' '.wp_json_encode(['level'=>$level]) : '';
                $blocks[] = "<!-- wp:heading{$attr} -->\n<{$tag}>{$content}</{$tag}>\n<!-- /wp:heading -->"; 
                continue;
            }

            if ($tag === 'ul' || $tag === 'ol') {
                $ordered = ($tag === 'ol');
                $lis = $node->getElementsByTagName('li'); 
                $list_inner = '';
                foreach($lis as $li) {
                    $list_inner .= '<li>'.trim($innerHTML($li)).'</li>';
                }
                $attr = $ordered ? ' '.wp_json_encode(['ordered'=>true]) : '';
                $blocks[] = "<!-- wp:list{$attr} -->\n<{$tag}>\n{$list_inner}\n</{$tag}>\n<!-- /wp:list -->"; 
                continue;
            }

            if ($tag === 'blockquote') {
                $content = trim($innerHTML($node));
                $blocks[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">{$content}</blockquote>\n<!-- /wp:quote -->"; 
                continue;
            }

            if ($tag === 'img') {
                $src = $bestImgSrc($node);
                if ($src) { 
                    $alt = $node->getAttribute('alt'); 
                    if ($alt === '') $alt = $this->human_alt_from_url($src); 
                    $blocks[] = $imageBlock($src, $alt, ''); 
                    continue; 
                }
            }

            if ($tag === 'p') {
                $content = trim($innerHTML($node));
                if ($content !== '') {
                    // Preserve inline HTML formatting (em, strong, a, etc.) while creating paragraph blocks
                    $blocks[] = "<!-- wp:paragraph -->\n<p>{$content}</p>\n<!-- /wp:paragraph -->";
                }
                continue;
            }

            // Handle other HTML elements with better Gutenberg compatibility
            if (in_array($tag, ['div', 'section', 'article', 'aside'])) {
                $content = trim($innerHTML($node));
                if ($content !== '') {
                    // Convert divs to group blocks for better Gutenberg handling
                    $blocks[] = "<!-- wp:group -->\n<div class=\"wp-block-group\">{$content}</div>\n<!-- /wp:group -->";
                }
                continue;
            }

            if ($tag === 'hr') {
                // Use proper Gutenberg separator block format to avoid validation errors
                // Ensure clean HTML structure for Gutenberg compatibility
                // Create clean separator block without inline styles for better Gutenberg compatibility
                $blocks[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator\">\n<!-- /wp:separator -->";
                continue;
            }

            if ($tag === 'pre') {
                $content = trim($innerHTML($node));
                if ($content !== '') {
                    $blocks[] = "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>".esc_html($content)."</code></pre>\n<!-- /wp:code -->";
                }
                continue;
            }

            if ($tag === 'code' && $node->parentNode && strtolower($node->parentNode->nodeName) !== 'pre') {
                $content = trim($innerHTML($node));
                if ($content !== '') {
                    $blocks[] = "<!-- wp:code -->\n<code class=\"wp-block-code\">".esc_html($content)."</code>\n<!-- /wp:code -->";
                }
                continue;
            }

            // Default fallback for other elements
            $blocks[] = "<!-- wp:html -->\n".$dom->saveHTML($node)."\n<!-- /wp:html -->";
        }

        // Flush any remaining paragraph content
        if ($current_paragraph_content !== '') {
            $current_paragraph_content = trim($current_paragraph_content);
            if ($current_paragraph_content !== '') {
                $blocks[] = "<!-- wp:paragraph -->\n<p>{$current_paragraph_content}</p>\n<!-- /wp:paragraph -->";
            }
        }

        libxml_clear_errors(); 
        libxml_use_internal_errors($prev);
        
        $output = implode("\n\n", $blocks);
        
        // Final cleanup to ensure all hr tags are properly formatted
        $output = $this->finalize_hr_blocks($output);
        
        // Ensure compatibility with both Gutenberg and Classic Editor
        $output = $this->ensure_editor_compatibility($output);
        
        return $output;
    }

    protected function remove_image_from_content($html, $img_url) : string {
        if (empty($html) || empty($img_url)) return $html;
        $prev = libxml_use_internal_errors(true);
        $dom  = new \DOMDocument('1.0','UTF-8');
        $dom->loadHTML('<!doctype html><meta charset="utf-8"><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        $body = $dom->getElementsByTagName('body')->item(0);
        $xp   = new \DOMXPath($dom);
        if (!$body) { libxml_clear_errors(); libxml_use_internal_errors($prev); return $html; }

        $target_base = preg_replace('/\?.*$/','', $img_url);
        $found = null;
        foreach ($xp->query('//img') as $img) {
            /** @var \DOMElement $img */
            $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
            $src_base = preg_replace('/\?.*$/','', $src);
            if ($src === $img_url || $src_base === $target_base) { $found = $img; break; }
        }
        if ($found) {
            $remove = $found;
            $p = $found->parentNode;
            while ($p && $p->nodeType === XML_ELEMENT_NODE) {
                if (strtolower($p->nodeName)==='div' && preg_match('/\bcaptioned-image-container\b/i', $p->getAttribute('class'))) { $remove = $p; break; }
                $p = $p->parentNode;
            }
            if ($remove === $found) {
                $p = $found->parentNode;
                while ($p && $p->nodeType === XML_ELEMENT_NODE) {
                    if (strtolower($p->nodeName)==='figure') { $remove = $p; break; }
                    $p = $p->parentNode;
                }
            }
            if ($remove === $found && $found->parentNode && strtolower($found->parentNode->nodeName)==='p') {
                $only=true;
                foreach (iterator_to_array($found->parentNode->childNodes) as $ch) {
                    if ($ch->isSameNode($found)) continue;
                    if ($ch->nodeType===XML_TEXT_NODE && trim($ch->textContent)==='') continue;
                    if ($ch->nodeType===XML_ELEMENT_NODE && strtolower($ch->nodeName)==='br') continue;
                    $only=false; break;
                }
                if ($only) $remove = $found->parentNode;
            }
            if ($remove && $remove->parentNode) $remove->parentNode->removeChild($remove);
        }
        $out=''; foreach ($body->childNodes as $child) $out.=$dom->saveHTML($child);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        return $out;
    }

    /**
     * Clean up hr tags to ensure Gutenberg compatibility
     */
    protected function clean_hr_tags($html) : string {
        if (empty($html)) return $html;
        
        // Replace <div><hr></div> patterns with clean hr tags first
        $html = preg_replace_callback('#<div[^>]*>\s*<hr[^>]*>\s*</div>#i', function($matches) {
            // Extract any attributes from the hr tag
            if (preg_match('/<hr([^>]*)>/i', $matches[0], $hr_matches)) {
                $hr_attributes = $hr_matches[1];
                
                // Build clean hr tag without inline styles for better Gutenberg compatibility
                $clean_hr = '<hr';
                if (preg_match('/class\s*=\s*["\']([^"\']*)["\']/i', $hr_attributes, $class_match)) {
                    $classes = array_filter(array_map('trim', explode(' ', $class_match[1])));
                    $classes[] = 'wp-block-separator';
                    $clean_hr .= ' class="' . esc_attr(implode(' ', array_unique($classes))) . '"';
                } else {
                    $clean_hr .= ' class="wp-block-separator"';
                }
                
                $clean_hr .= '>';
                
                return $clean_hr;
            }
            
            // Fallback if no hr attributes found
            return '<hr class="wp-block-separator">';
        }, $html);
        
        // Remove any self-closing hr tags that might cause issues
        $html = preg_replace('/<hr\s*\/?>/i', '<hr>', $html);
        
        // Clean up any hr tags with problematic attributes
        $html = preg_replace_callback('/<hr([^>]*)>/i', function($matches) {
            $attributes = $matches[1];
            
            // Keep only safe attributes (class, id) - remove style to avoid Gutenberg compatibility issues
            $safe_attrs = [];
            if (preg_match('/class\s*=\s*["\']([^"\']*)["\']/i', $attributes, $class_match)) {
                $classes = array_filter(array_map('trim', explode(' ', $class_match[1])));
                $classes[] = 'wp-block-separator';
                $safe_attrs[] = 'class="' . esc_attr(implode(' ', array_unique($classes))) . '"';
            } else {
                $safe_attrs[] = 'class="wp-block-separator"';
            }
            
            if (preg_match('/id\s*=\s*["\']([^"\']*)["\']/i', $attributes, $id_match)) {
                $safe_attrs[] = 'id="' . esc_attr($id_match[1]) . '"';
            }
            
            return '<hr ' . implode(' ', $safe_attrs) . '>';
        }, $html);
        
        return $html;
    }

    /**
     * Final cleanup of hr blocks to ensure Gutenberg compatibility
     */
    protected function finalize_hr_blocks($content) : string {
        if (empty($content)) return $content;
        
        // Ensure all hr blocks are properly formatted
        $content = preg_replace_callback('/<!-- wp:separator -->\s*\n<hr([^>]*)>\s*\n<!-- \/wp:separator -->/i', function($matches) {
            $attributes = $matches[1];
            
            // Ensure proper class attribute
            if (!preg_match('/class\s*=\s*["\']([^"\']*)["\']/i', $attributes)) {
                $attributes = trim($attributes) . ' class="wp-block-separator"';
            } else {
                // Ensure wp-block-separator class is present
                $attributes = preg_replace_callback('/class\s*=\s*["\']([^"\']*)["\']/i', function($class_matches) {
                    $classes = array_filter(array_map('trim', explode(' ', $class_matches[1])));
                    if (!in_array('wp-block-separator', $classes)) {
                        $classes[] = 'wp-block-separator';
                    }
                    return 'class="' . esc_attr(implode(' ', array_unique($classes))) . '"';
                }, $attributes);
                
                // Remove style attribute to avoid Gutenberg compatibility issues
                $attributes = preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/i', '', $attributes);
            }
            
            return "<!-- wp:separator -->\n<hr{$attributes}>\n<!-- /wp:separator -->";
        }, $content);
        
        return $content;
    }

    /**
     * Test method to verify hr tag handling (for debugging)
     */
    public function test_hr_handling($html) : string {
        $cleaned = $this->clean_hr_tags($html);
        $converted = $this->convert_html_to_blocks($cleaned);
        return $converted;
    }

    /**
     * Test method to verify editor compatibility (for debugging)
     */
    public function test_editor_compatibility($html) : array {
        $cleaned = $this->clean_hr_tags($html);
        $converted = $this->convert_html_to_blocks($cleaned);
        $compatible = $this->ensure_editor_compatibility($converted);
        $validation = $this->validate_gutenberg_content($compatible);
        
        return [
            'original' => $html,
            'cleaned' => $cleaned,
            'converted' => $converted,
            'compatible' => $compatible,
            'validation' => $validation
        ];
    }

    /**
     * Test method to verify div-hr-div pattern replacement (for debugging)
     */
    public function test_div_hr_pattern_replacement($html) : array {
        $original = $html;
        $cleaned = $this->clean_hr_tags($html);
        $sanitized = $this->sanitize_html($html);
        
        return [
            'original' => $original,
            'cleaned_by_clean_hr_tags' => $cleaned,
            'sanitized_by_sanitize_html' => $sanitized,
            'patterns_found' => [
                'div_hr_div' => preg_match_all('#<div[^>]*>\s*<hr[^>]*>\s*</div>#i', $original),
                'hr_tags' => preg_match_all('#<hr[^>]*>#i', $original)
            ],
            'patterns_after_cleaning' => [
                'div_hr_div' => preg_match_all('#<div[^>]*>\s*<hr[^>]*>\s*</div>#i', $cleaned),
                'hr_tags' => preg_match_all('#<hr[^>]*>#i', $cleaned)
            ]
        ];
    }

    /**
     * Add CSS for full-width separators without inline styles
     */
    public function add_separator_styles() {
        if (!is_admin()) return;
        
        $css = '
        <style>
        .wp-block-separator {
            width: 100% !important;
            max-width: 100% !important;
            border: none !important;
            height: 1px !important;
            background-color: #ccc !important;
            margin: 2em 0 !important;
        }
        </style>';
        
        echo $css;
    }

    /**
     * Validate content for Gutenberg compatibility
     */
    public function validate_gutenberg_content($content) : array {
        $errors = [];
        $warnings = [];
        
        // Check for problematic hr tags
        if (preg_match_all('/<hr[^>]*>/i', $content, $matches)) {
            foreach ($matches[0] as $hr_tag) {
                // Check if hr tag has proper class
                if (!preg_match('/class\s*=\s*["\'][^"\']*wp-block-separator[^"\']*["\']/i', $hr_tag)) {
                    $warnings[] = 'HR tag missing wp-block-separator class: ' . $hr_tag;
                }
                
                // Check for potentially problematic attributes
                if (preg_match('/\s(on\w+|javascript:)/i', $hr_tag)) {
                    $errors[] = 'HR tag contains potentially unsafe attributes: ' . $hr_tag;
                }
            }
        }
        
        // Check for div-hr-div patterns that should have been replaced
        if (preg_match_all('/<div[^>]*>\s*<hr[^>]*>\s*<\/div>/i', $content, $matches)) {
            foreach ($matches[0] as $pattern) {
                $warnings[] = 'Found div-hr-div pattern that should be replaced: ' . $pattern;
            }
        }
        
        // Check for other potentially problematic elements
        if (preg_match_all('/<(div|span|p)[^>]*>/i', $content, $matches)) {
            foreach ($matches[0] as $tag) {
                // Check for potentially problematic attributes
                if (preg_match('/\s(on\w+|javascript:)/i', $tag)) {
                    $errors[] = 'Tag contains potentially unsafe attributes: ' . $tag;
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'compatibility' => [
                'gutenberg' => empty($errors) && count($warnings) < 5,
                'classic_editor' => empty($errors)
            ]
        ];
    }

    /**
     * Ensure content compatibility with both Gutenberg and Classic Editor
     */
    public function ensure_editor_compatibility($content) : string {
        if (empty($content)) return $content;
        
        // Add fallback content for problematic elements without inline styles
        $content = preg_replace_callback('/<!-- wp:separator -->\s*\n<hr([^>]*)>\s*\n<!-- \/wp:separator -->/i', function($matches) {
            $attributes = $matches[1];
            
            // Ensure minimal styling that won't cause Gutenberg compatibility issues
            if (!preg_match('/style\s*=\s*["\']([^"\']*)["\']/i', $attributes)) {
                $attributes .= ' style="margin: 2em 0;"';
            }
            
            return "<!-- wp:separator -->\n<hr{$attributes}>\n<!-- /wp:separator -->";
        }, $content);
        
        return $content;
    }



// ===== Word-level diff inside blocks =====
    protected function tokenize_words($text) : array {
        if ($text === '' || $text === null) return [];
        $parts = preg_split('~(\s+|[^\pL\pN]+)~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        return $parts ?: [];
    }

    protected function lcs_words($a, $b) {
        $m = count($a); $n = count($b);
        $L = array_fill(0, $m+1, array_fill(0, $n+1, 0));
        for ($i=1; $i<=$m; $i++) {
            for ($j=1; $j<=$n; $j++) {
                if ($a[$i-1] === $b[$j-1]) $L[$i][$j] = $L[$i-1][$j-1] + 1;
                else $L[$i][$j] = max($L[$i-1][$j], $L[$i][$j-1]);
            }
        }
        $i=$m; $j=$n; $ops=[];
        while ($i>0 && $j>0) {
            if ($a[$i-1] === $b[$j-1]) { array_unshift($ops, ['op'=>'eq','t'=>$a[$i-1]]); $i--; $j--; }
            elseif ($L[$i-1][$j] >= $L[$i][$j-1]) { array_unshift($ops, ['op'=>'del','t'=>$a[$i-1]]); $i--; }
            else { array_unshift($ops, ['op'=>'add','t'=>$b[$j-1]]); $j--; }
        }
        while ($i>0) { array_unshift($ops, ['op'=>'del','t'=>$a[$i-1]]); $i--; }
        while ($j>0) { array_unshift($ops, ['op'=>'add','t'=>$b[$j-1]]); $j--; }
        return $ops;
    }

    protected function render_word_diff($old_html, $new_html) : array {
        $old_txt = trim(wp_strip_all_tags($old_html));
        $new_txt = trim(wp_strip_all_tags($new_html));
        $a = $this->tokenize_words($old_txt);
        $b = $this->tokenize_words($new_txt);
        $ops = $this->lcs_words($a, $b);
        $old_out=''; $new_out='';
        foreach ($ops as $o) {
            if ($o['op']==='eq') { $old_out .= esc_html($o['t']); $new_out .= esc_html($o['t']); }
            elseif ($o['op']==='del') { $old_out .= '<span class="ssi-wdel">'. esc_html($o['t']) .'</span>'; }
            elseif ($o['op']==='add') { $new_out .= '<span class="ssi-wadd">'. esc_html($o['t']) .'</span>'; }
        }
        return [$old_out, $new_out];
    }


    /**
     * Append UTM parameters to anchor hrefs according to settings.
     */
    protected function append_utm_to_links($html, $post_id) {
        if (!$html) return $html;
        $enabled = (int) get_option('substack_importer_utm_enabled', 0) === 1;
        if (!$enabled) { $this->last_utm_stats = ['scanned'=>0,'tagged'=>0,'skipped_existing'=>0,'skipped_nonhttp'=>0,'skipped_internal'=>0,'by_domain'=>[]]; return $html; }

        $source   = (string) get_option('substack_importer_utm_source', 'wordpress');
        $medium   = (string) get_option('substack_importer_utm_medium', 'referral');
        $tmpl     = (string) get_option('substack_importer_utm_campaign_template', '{post_slug}');
        $ext_only = (int) get_option('substack_importer_utm_external_only', 1) === 1;
        $wl       = (string) get_option('substack_importer_utm_domain_whitelist', '');

        $rules    = $this->parse_utm_rules();
        $stats = ['scanned'=>0,'tagged'=>0,'skipped_existing'=>0,'skipped_nonhttp'=>0,'skipped_internal'=>0,'by_domain'=>[]];

        $whitelist = array_filter(array_map('trim', explode(',', $wl)));
        $site_host = parse_url(home_url(), PHP_URL_HOST);

        $substack_link = get_post_meta((int)$post_id, '_substack_source_link', true);
        if (!$substack_link) $substack_link = get_post_meta((int)$post_id, '_substack_guid', true);
        $substack_host = $substack_link ? parse_url($substack_link, PHP_URL_HOST) : '';

        $post   = get_post($post_id);
        $slug   = $post ? $post->post_name : '';
        $y = get_the_date('Y', $post_id); $m = get_the_date('m', $post_id); $d = get_the_date('d', $post_id);

        $campaign = $tmpl;
        $repl = [
            '{post_slug}'    => $slug,
            '{post_id}'      => (string) $post_id,
            '{substack_host}' => (string) $substack_host,
            '{y}'            => (string) $y,
            '{m}'            => (string) $m,
            '{d}'            => (string) $d,
        ];
        $campaign = strtr($campaign, $repl);
        $campaign = sanitize_title($campaign);

        $prev = libxml_use_internal_errors(true);
        $dom  = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<!doctype html><meta charset="utf-8"><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);
        $body  = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            libxml_clear_errors(); libxml_use_internal_errors($prev);
            return $html;
        }
        $anchors = $xpath->query('//a[@href]');
        $stats['scanned'] = $anchors ? $anchors->length : 0;
        foreach ($anchors as $a) {
            /** @var \DOMElement $a */
            $href = $a->getAttribute('href');
            if (!$href) continue;
            $lh = strtolower($href);
            if (strpos($lh, 'mailto:') === 0 or strpos($lh, 'tel:') === 0 or strpos($lh, 'javascript:') === 0) { $stats['skipped_nonhttp']++; continue; }

            // Resolve relative to site URL
            $abs = $href;
            if (strpos($href, '//') === 0) { $abs = 'https:'.$href; }
            elseif (!preg_match('~^https?://~i', $href)) { $abs = home_url($href); }

            $parts = wp_parse_url($abs);
            if (!$parts || empty($parts['host'])) continue;

            $is_external = ($site_host && strtolower($parts['host']) !== strtolower($site_host));
            if ($ext_only && !$is_external) { $stats['skipped_internal']++; continue; }

            if (!empty($whitelist)) {
                $allowed = false;
                foreach ($whitelist as $pattern) {
                    $pattern = strtolower($pattern);
                    if ($pattern && stripos($parts['host'], $pattern) !== false) { $allowed = true; break; }
                }
                if (!$allowed) continue;
            }

            // match per-domain rule
            $effective_source = $source; $effective_medium = $medium; $effective_campaign = $campaign; $effective_ext_only = $ext_only;
            foreach ($rules as $r) {
                if (!empty($r['domain']) && stripos($parts['host'], $r['domain']) !== false) {
                    if (!is_null($r['source']))   $effective_source = $r['source'];
                    if (!is_null($r['medium']))   $effective_medium = $r['medium'];
                    if (!is_null($r['campaign'])) $effective_campaign = strtr($r['campaign'], $repl);
                    if (!is_null($r['external_only'])) $effective_ext_only = (int)$r['external_only'] === 1;
                    break; // first match wins
                }
            }
            if ($effective_ext_only && !$is_external) { $stats['skipped_internal']++; continue; }

            // parse existing query
            $q = [];
            if (!empty($parts['query'])) parse_str($parts['query'], $q);

            // Only add if not already present
            $had_utm = isset($q['utm_source']) || isset($q['utm_medium']) || isset($q['utm_campaign']);
            if (!isset($q['utm_source']))   $q['utm_source']   = $effective_source;
            if (!isset($q['utm_medium']))   $q['utm_medium']   = $effective_medium;
            if (!isset($q['utm_campaign'])) $q['utm_campaign'] = $effective_campaign;

                        // tally domain
            $host_key = strtolower($parts['host']);
            if (!isset($stats['by_domain'][$host_key])) $stats['by_domain'][$host_key] = 0;
            if ($had_utm) { $stats['skipped_existing']++; } else { $stats['tagged']++; }
            $stats['by_domain'][$host_key]++;

            // rebuild
            $parts['query'] = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
            $new = (isset($parts['scheme']) ? $parts['scheme'].'://' : '') . $parts['host'] . (isset($parts['port']) ? ':'.$parts['port'] : '') . ($parts['path'] ?? '');
            if (!empty($parts['query'])) $new .= '?'.$parts['query'];
            if (!empty($parts['fragment'])) $new .= '#'.$parts['fragment'];

            // If original used // or relative, keep original form when same host
            if (!$is_external && $ext_only) {
                // shouldn't reach due to continue, but guard
                continue;
            }

            $a->setAttribute('href', esc_url($new));
        }

        $out = '';
        foreach ($body->childNodes as $child) $out .= $dom->saveHTML($child);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $this->last_utm_stats = $stats;
        return $out;
    }


    protected function parse_utm_rules() : array {
        $raw = (string) get_option('substack_importer_utm_rules', '');
        $out = [];
        if (!$raw) return $out;
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '' || strpos($ln, '#') === 0) continue;
            $parts = array_map('trim', explode('|', $ln));
            if (empty($parts)) continue;
            $rule = ['domain'=>'', 'source'=>null, 'medium'=>null, 'campaign'=>null, 'external_only'=>null];
            if (!empty($parts[0])) $rule['domain'] = strtolower($parts[0]);
            foreach (array_slice($parts,1) as $p) {
                if (strpos($p,'=') !== false) {
                    list($k,$v) = array_map('trim', explode('=', $p, 2));
                    $k = strtolower($k);
                    if ($k === 'source') $rule['source'] = $v;
                    elseif ($k === 'medium') $rule['medium'] = $v;
                    elseif ($k === 'campaign') $rule['campaign'] = $v;
                    elseif ($k === 'external_only') $rule['external_only'] = (int)!!$v;
                }
            }
            if ($rule['domain'] !== '') $out[] = $rule;
        }
        return $out;
    }

}