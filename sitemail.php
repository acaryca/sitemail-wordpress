<?php
/**
 * Plugin Name: SiteMail
 * Description: Replace WordPress email function with SiteMail API
 * Version: 1.0.1
 * Author: ACARY
 * Author URI: https://acary.ca
 * Text Domain: sitemail
 * License: GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// GitHub repository info for updates
define('SITEMAIL_GITHUB_REPO', 'acary/sitemail-wordpress');
define('SITEMAIL_PLUGIN_FILE', __FILE__);

// Include GitHub Updater if not already included
if (!class_exists('SiteMail_GitHub_Updater')) {
    require_once plugin_dir_path(__FILE__) . 'includes/github-updater.php';
}

class SiteMail_Service {
    /**
     * SiteMail API key
     * @var string
     */
    private $api_key;

    /**
     * SiteMail API URL
     * @var string
     */
    private $api_url = 'https://api.sitemail.ca/v2/send/';

    /**
     * Constructor - initializes the plugin
     */
    public function __construct() {
        // Define the API key (replace with your actual key)
        $this->api_key = defined('SITEMAIL_API_KEY') ? SITEMAIL_API_KEY : get_option('sitemail_api_key', '');
        
        // Check if the API key is defined
        if (empty($this->api_key)) {
            add_action('admin_notices', [$this, 'display_api_key_notice']);
        }

        // Replace WordPress mail function
        add_action('phpmailer_init', [$this, 'hijack_wp_mail'], 999);
        
        // Log email failures
        add_action('wp_mail_failed', [$this, 'log_email_error']);
        
        // Add admin menus
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Add a link to the settings in the plugins list
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
        
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Set up GitHub updater
        add_action('init', [$this, 'setup_github_updater']);
    }
    
    /**
     * Set up the GitHub updater
     */
    public function setup_github_updater() {
        if (class_exists('SiteMail_GitHub_Updater')) {
            new SiteMail_GitHub_Updater(SITEMAIL_PLUGIN_FILE, SITEMAIL_GITHUB_REPO, 'SiteMail');
            
            // Add update check button in plugin row
            add_filter('plugin_row_meta', [$this, 'add_plugin_meta_links'], 10, 2);
        }
    }
    
    /**
     * Add meta links to plugin row
     *
     * @param array $links Current links
     * @param string $file Current plugin file
     * @return array Modified links
     */
    public function add_plugin_meta_links($links, $file) {
        if ($file == plugin_basename(SITEMAIL_PLUGIN_FILE)) {
            $check_update_url = wp_nonce_url(
                add_query_arg(
                    [
                        'action' => 'sitemail_check_update',
                        'plugin' => plugin_basename(SITEMAIL_PLUGIN_FILE)
                    ],
                    admin_url('plugins.php')
                ),
                'sitemail-check-update'
            );
            
            $links[] = '<a href="' . esc_url($check_update_url) . '">' . __('Check for updates', 'sitemail') . '</a>';
        }
        
        return $links;
    }
    
    /**
     * Add an admin menu for the plugin
     */
    public function add_admin_menu() {
        add_options_page(
            'SiteMail',
            'SiteMail', 
            'manage_options', 
            'sitemail-settings', 
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Register the plugin settings
     */
    public function register_settings() {
        register_setting('sitemail_settings', 'sitemail_api_key');
    }
    
    /**
     * Add a link to the settings in the plugins list
     *
     * @param array $links Existing links
     * @return array Modified links
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=sitemail-settings">' . __('Paramètres', 'sitemail') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Display the plugin settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('sitemail_settings');
                do_settings_sections('sitemail_settings');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('Clé API SiteMail', 'sitemail'); ?></th>
                        <td>
                            <input type="text" name="sitemail_api_key" value="<?php echo esc_attr(get_option('sitemail_api_key')); ?>" class="regular-text" />
                            <p class="description"><?php _e('Entrez votre clé API SiteMail. Vous pouvez également définir la clé dans wp-config.php avec <code>define(\'SITEMAIL_API_KEY\', \'votre-clé-api\');</code>', 'sitemail'); ?></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <a href="<?php echo esc_url(add_query_arg(['sitemail_test' => '1'])); ?>" class="button"><?php _e('Envoyer un email de test', 'sitemail'); ?></a>
                </p>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Display a notice if the API key is not configured
     */
    public function display_api_key_notice() {
        $class = 'notice notice-error';
        $message = sprintf(
            __('SiteMail API : Veuillez <a href="%s">configurer votre clé API SiteMail</a> pour activer les fonctionnalités d\'envoi d\'email.', 'sitemail'),
            admin_url('options-general.php?page=sitemail-settings')
        );
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    }

    /**
     * Replace the default behavior of PHPMailer
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Instance PHPMailer
     */
    public function hijack_wp_mail($phpmailer) {
        // Disable the default SMTP transport of PHPMailer
        $phpmailer->Mailer = 'sitemail';
        
        // Define a custom function to send the email
        $phpmailer->actionFunction = function($phpmailer) {
            return $this->send_mail_via_api($phpmailer);
        };
    }

    /**
     * Send the email via the SiteMail API
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Instance PHPMailer
     * @return bool Success or failure of the email
     */
    private function send_mail_via_api($phpmailer) {
        // Extract PHPMailer data
        $from = $phpmailer->From;
        $from_name = $phpmailer->FromName;
        
        // Formatting the recipients
        $to = [];
        foreach ($phpmailer->getToAddresses() as $address) {
            $to[] = $address[0];
        }
        
        // Handling Reply-To
        $reply_to = null;
        if (!empty($phpmailer->getReplyToAddresses())) {
            $reply_addresses = [];
            foreach ($phpmailer->getReplyToAddresses() as $address) {
                $reply_addresses[] = $address[0];
            }
            $reply_to = implode(', ', $reply_addresses);
        }
        
        // Email content
        $subject = $phpmailer->Subject;
        $message = '';
        
        // Use HTML body if it exists, otherwise use plain text
        if (!empty($phpmailer->Body)) {
            $message = $phpmailer->isHTML() ? $phpmailer->Body : nl2br($phpmailer->Body);
        } else if (!empty($phpmailer->AltBody)) {
            $message = nl2br($phpmailer->AltBody);
        }
        
        // Handling attachments
        $attachments = [];
        if (!empty($phpmailer->getAttachments())) {
            foreach ($phpmailer->getAttachments() as $attachment) {
                // Expected format: [0] => file path, [1] => file name, [3] => encoding, [4] => MIME type
                $file_path = $attachment[0];
                $file_name = !empty($attachment[1]) ? $attachment[1] : basename($file_path);
                $file_type = !empty($attachment[4]) ? $attachment[4] : '';
                
                // Read the file content and encode it in base64
                $file_content = '';
                if (file_exists($file_path)) {
                    $file_content = base64_encode(file_get_contents($file_path));
                }
                
                if (!empty($file_content)) {
                    $attachments[] = [
                        'filename' => $file_name,
                        'content' => $file_content,
                        'contentType' => $file_type
                    ];
                }
            }
        }
        
        // Build the payload for the API
        $payload = [
            'key' => $this->api_key,
            'host' => parse_url(site_url(), PHP_URL_HOST),
            'email' => [
                'from' => !empty($from_name) ? $from_name : $from,
                'to' => implode(', ', $to),
                'subject' => $subject,
                'message' => $message,
                'attachments' => $attachments
            ]
        ];
        
        // Add reply-to if it exists
        if (!empty($reply_to)) {
            $payload['email']['replyTo'] = $reply_to;
        }
        
        // Send the request to the API
        $response = $this->send_api_request($payload);
        
        // Handle the response
        if (isset($response['status']) && $response['status'] === 200) {
            // Success
            return true;
        } else {
            // Failure - trigger an error so WordPress can handle it
            $error_message = isset($response['message']) ? $response['message'] : 'Unknown error';
            $phpmailer->setError($error_message);
            return false;
        }
    }

    /**
     * Send a request to the SiteMail API
     *
     * @param array $payload Data to send to the API
     * @return array API response
     */
    private function send_api_request($payload) {
        $args = [
            'body' => json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true
        ];
        
        // Perform the HTTP POST request
        $response = wp_remote_post($this->api_url, $args);
        
        // Handle the response
        if (is_wp_error($response)) {
            return [
                'status' => 500,
                'message' => $response->get_error_message()
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);
        
        // Try to decode the JSON response
        $decoded_body = json_decode($body, true);
        
        return [
            'status' => $status,
            'message' => isset($decoded_body['message']) ? $decoded_body['message'] : '',
            'data' => $decoded_body
        ];
    }

    /**
     * Log email errors
     *
     * @param \WP_Error $wp_error WordPress error
     */
    public function log_email_error($wp_error) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('SiteMail - Email sending error: ' . $wp_error->get_error_message());
        }
    }
}

// Initialize the service
function sitemail_init() {
    global $sitemail_service;
    $sitemail_service = new SiteMail_Service();
}
add_action('plugins_loaded', 'sitemail_init');

/**
 * Handle update check action
 */
function sitemail_handle_update_check() {
    if (
        isset($_GET['action']) && $_GET['action'] === 'sitemail_check_update' &&
        isset($_GET['plugin']) && $_GET['plugin'] === plugin_basename(SITEMAIL_PLUGIN_FILE) &&
        check_admin_referer('sitemail-check-update')
    ) {
        // Force WordPress to check for updates
        delete_site_transient('update_plugins');
        wp_redirect(admin_url('plugins.php?plugin_status=all'));
        exit;
    }
}
add_action('admin_init', 'sitemail_handle_update_check');

/**
 * Function to test email sending
 */
function sitemail_test_email() {
    // Only accessible to administrators
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'sitemail'));
    }
    
    // Send a test email
    $to = get_option('admin_email');
    $subject = __('Test email via SiteMail', 'sitemail');
    $message = __('This is a test email sent via SiteMail. If you receive this email, the configuration is working correctly.', 'sitemail');
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    
    $result = wp_mail($to, $subject, $message, $headers);
    
    // Display the result
    if ($result) {
        add_settings_error(
            'sitemail_settings',
            'sitemail_test_success',
            __('Test email sent successfully!', 'sitemail'),
            'updated'
        );
    } else {
        add_settings_error(
            'sitemail_settings',
            'sitemail_test_error',
            __('Failed to send the test email.', 'sitemail'),
            'error'
        );
    }
    
    // Redirect to the settings page
    wp_redirect(admin_url('options-general.php?page=sitemail-settings'));
    exit;
}

// Add an admin route to test email sending
if (is_admin() && isset($_GET['sitemail_test']) && $_GET['sitemail_test'] === '1') {
    add_action('admin_init', 'sitemail_test_email');
}

/**
 * Activation and deactivation functions for the plugin
 */
function sitemail_activate() {
    // Nothing to do for now
}
register_activation_hook(__FILE__, 'sitemail_activate');

function sitemail_deactivate() {
    // Nothing to do for now
}
register_deactivation_hook(__FILE__, 'sitemail_deactivate');
