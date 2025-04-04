<?php
/**
 * Triggered when the plugin is uninstalled
 *
 * @package SiteMail
 */

// If this file is called directly, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete the plugin options
delete_option('sitemail_api_key'); 