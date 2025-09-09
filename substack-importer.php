<?php
/**
 * Plugin Name: Substack Importer
 * Description: Import Substack posts into WordPress with category mapping (Exact/CI/Regex), draft-first review, sanitization, image-alt generation, Admin/Editor restriction, cron automation, re-sync, and import logs.
 * Version: 1.9.0
 * Author: Mamun
 * Text Domain: substack-importer
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) { exit; }

define('SSI_OOP_VER', '1.9.0');
define('SSI_OOP_CAP', 'edit_others_posts'); // Admin + Editor
define('SSI_OOP_PATH', plugin_dir_path(__FILE__));
define('SSI_OOP_URL',  plugin_dir_url(__FILE__));
define('SSI_OOP_CRON_HOOK', 'ssi_oop_cron_hook');

// PSR-4-ish autoloader
spl_autoload_register(function ($class) {
    $prefix = 'Substack_Importer\\';
    $base_dir = SSI_OOP_PATH . 'includes/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace('\\', '-', $relative_class)) . '.php';
    if (file_exists($file)) require $file;
});

// Bootstrap plugin
add_action('plugins_loaded', function(){
    load_plugin_textdomain('substack-importer', false, dirname(plugin_basename(__FILE__)) . '/languages');
    Substack_Importer\Plugin::instance();
});

// Activation / Deactivation
register_activation_hook(__FILE__, function(){
    Substack_Importer\Plugin::instance()->activate();
    Substack_Importer\Plugin::instance()->ensure_cron_state();
});
register_deactivation_hook(__FILE__, function(){
    Substack_Importer\Plugin::instance()->clear_cron();
});