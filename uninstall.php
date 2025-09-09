<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

$clear = (int) get_option('substack_importer_clear_on_uninstall', 0) === 1;
if (!$clear) {
    // Keep everything (logs, options, post meta)
    return;
}

// Drop log table and remove options + metas
global $wpdb;
$table = $wpdb->prefix . 'substack_import_log';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

$opts = [
    'substack_importer_feed_urls',
    'substack_importer_cron_enabled',
    'substack_importer_cron_interval',
    'substack_importer_cron_interval_unit',
    'substack_importer_cron_import_limit',
    'substack_importer_cron_offset',
    'substack_importer_last_cron_run',
    'substack_importer_last_import_count',
            'substack_importer_enhanced_gutenberg',
        'substack_importer_default_status',
        'substack_importer_term_map',
    
    'substack_importer_clear_on_uninstall',
];
foreach ($opts as $o) { delete_option($o); }

// Remove Substack-specific post meta for posts and attachments
$meta_keys = [
    '_substack_guid',
    '_substack_hash',
    '_substack_title',
    '_substack_source_link',
    '_substack_out_of_sync',
    '_substack_image_hash',
    '_substack_source_url',
];

foreach ($meta_keys as $mk) {
    $wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $mk) );
}
