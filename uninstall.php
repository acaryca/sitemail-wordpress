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

// Delete all plugin options
delete_option('sitemail_api_key');
delete_option('sitemail_mailer_type');
delete_option('sitemail_sender_name');
delete_option('sitemail_smtp_host');
delete_option('sitemail_smtp_port');
delete_option('sitemail_smtp_username');
delete_option('sitemail_smtp_password');
delete_option('sitemail_smtp_encryption');
delete_option('sitemail_smtp_from_email'); 