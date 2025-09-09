<?php
namespace Substack_Importer;

if (!defined('ABSPATH')) { exit; }

class Logger {

    const TABLE = 'substack_import_log';

    public function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public function maybe_create_table() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->table_name() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            source_url TEXT NOT NULL,
            post_title TEXT NOT NULL,
            wp_post_id BIGINT UNSIGNED NULL,
            status VARCHAR(32) NOT NULL,
            message TEXT NULL,
            PRIMARY KEY (id),
            KEY status (status)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function log($source_url, $title, $status, $msg = '', $post_id = null) {
        global $wpdb;
        $wpdb->insert($this->table_name(), [
            'created_at' => current_time('mysql'),
            'source_url' => (string)$source_url,
            'post_title' => (string)$title,
            'wp_post_id' => $post_id ? (int)$post_id : null,
            'status'     => (string)$status,
            'message'    => (string)$msg,
        ], ['%s','%s','%s','%d','%s','%s']);
    }

    public function render_log_page() {
        if (!current_user_can(\SSI_OOP_CAP)) { wp_die(esc_html__('You do not have permission.', 'substack-importer')); }
        global $wpdb;
        
        // Pagination setup
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $total_rows = $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table_name());
        $total_pages = ceil($total_rows / $per_page);
        
        // Get paginated results
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . $this->table_name() . " ORDER BY id DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Substack Import Log', 'substack-importer'); ?></h1>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(esc_html__('%s items', 'substack-importer'), number_format_i18n($total_rows)); ?>
                    </span>
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $total_pages,
                        'current' => $current_page,
                        'type' => 'plain',
                    ]);
                    ?>
                </div>
            <?php endif; ?>
            
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__('Date', 'substack-importer'); ?></th>
                    <th><?php echo esc_html__('Title', 'substack-importer'); ?></th>
                    <th><?php echo esc_html__('Source URL', 'substack-importer'); ?></th>
                    <th><?php echo esc_html__('WP Post', 'substack-importer'); ?></th>
                    <th><?php echo esc_html__('Status', 'substack-importer'); ?></th>
                    <th><?php echo esc_html__('Message', 'substack-importer'); ?></th>
                </tr></thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php 
                            $date = \DateTime::createFromFormat('Y-m-d H:i:s', $r->created_at);
                            if ($date) {
                                echo esc_html($date->format('j. F Y, H:i:s'));
                            } else {
                                echo esc_html($r->created_at);
                            }
                        ?></td>
                        <td><?php echo esc_html(wp_strip_all_tags(wp_trim_words($r->post_title, 15))); ?></td>
                        <td><a href="<?php echo esc_url($r->source_url); ?>" target="_blank" rel="noopener"><?php echo esc_html__('source link', 'substack-importer'); ?></a></td>
                        <td><?php 
                            if ($r->wp_post_id) {
                                $post = get_post((int)$r->wp_post_id);
                                if ($post) {
                                    $status = $post->post_status;
                                    $status_colors = [
                                        'publish' => '#46b450',
                                        'draft' => '#ffb900',
                                        'pending' => '#ffb900',
                                        'private' => '#a0a5aa',
                                        'trash' => '#dc3232'
                                    ];
                                    $status_labels = [
                                        'publish' => __('Published', 'substack-importer'),
                                        'draft' => __('Draft', 'substack-importer'),
                                        'pending' => __('Pending', 'substack-importer'),
                                        'private' => __('Private', 'substack-importer'),
                                        'trash' => __('Deleted', 'substack-importer')
                                    ];
                                    
                                    if ($status === 'trash') {
                                        echo '<span style="color: ' . $status_colors[$status] . '; font-style: italic;">' . esc_html($status_labels[$status]) . '</span>';
                                    } else {
                                        echo '<a href="'. esc_url(get_edit_post_link((int)$r->wp_post_id)) .'">'. (int)$r->wp_post_id . '</a>';
                                        echo '<br><small style="color: ' . ($status_colors[$status] ?? '#666') . ';">' . esc_html($status_labels[$status] ?? $status) . '</small>';
                                    }
                                } else {
                                    echo '<span style="color: #dc3232; font-style: italic;">' . esc_html__('N/A', 'substack-importer') . '</span>';
                                }
                            } else {
                                echo '&mdash;';
                            }
                        ?></td>
                        <td><?php echo esc_html($r->status); ?></td>
                        <td><?php echo esc_html($r->message); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6"><?php echo esc_html__('No logs yet.', 'substack-importer'); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(esc_html__('%s items', 'substack-importer'), number_format_i18n($total_rows)); ?>
                    </span>
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $total_pages,
                        'current' => $current_page,
                        'type' => 'plain',
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
