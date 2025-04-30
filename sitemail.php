<?php
/**
 * Plugin Name: SiteMail
 * Description: Replace WordPress email function with SiteMail or SMTP
 * Version: 1.0.1
 * Author: ACARY
 * Author URI: https://acary.ca
 * Text Domain: sitemail
 * License: GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('SITEMAIL_PLUGIN_FILE', __FILE__);

// Translations
add_action( 'plugins_loaded', function () {
    load_plugin_textdomain('sitemail', false, dirname(plugin_basename(__FILE__)) . '/languages/');
});

// Include Updater if not already included
require_once plugin_dir_path(__FILE__) . '/includes/plugin-update-checker.php';

// Include admin functionality
require_once plugin_dir_path(__FILE__) . '/admin/admin.php';

// Import PHPMailer namespace
use PHPMailer\PHPMailer\PHPMailer;

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
        if (empty($this->api_key) && get_option('sitemail_mailer_type', 'sitemail') === 'sitemail') {
            add_action('admin_notices', [$this, 'display_api_key_notice']);
        }

        // Replace WordPress mail function
        add_filter('pre_wp_mail', [$this, 'intercept_wp_mail'], 10, 2);
        
        // Log email failures
        add_action('wp_mail_failed', [$this, 'log_email_error']);

        // Configure PHPMailer for SMTP if needed
        if (get_option('sitemail_mailer_type', 'sitemail') === 'smtp') {
            add_action('phpmailer_init', [$this, 'configure_smtp']);
        }
    }

    /**
     * Configure PHPMailer for SMTP
     * 
     * @param PHPMailer $phpmailer The PHPMailer instance
     */
    public function configure_smtp($phpmailer) {
        if (get_option('sitemail_mailer_type', 'sitemail') !== 'smtp') {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = get_option('sitemail_smtp_host', '');
        $phpmailer->Port = get_option('sitemail_smtp_port', '587');
        $phpmailer->Username = get_option('sitemail_smtp_username', '');
        $phpmailer->Password = get_option('sitemail_smtp_password', '');
        
        $encryption = get_option('sitemail_smtp_encryption', 'tls');
        if ($encryption === 'tls') {
            $phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $phpmailer->SMTPSecure = '';
        }
        
        $phpmailer->SMTPAuth = true;

        // Set the sender name and email
        $sender_name = get_option('sitemail_sender_name', get_bloginfo('name'));
        $sender_email = get_option('sitemail_smtp_from_email', '');
        
        if (!empty($sender_name)) {
            $phpmailer->FromName = $sender_name;
        }
        
        if (!empty($sender_email)) {
            $phpmailer->From = $sender_email;
        }
    }

    /**
     * Display a notice if the API key is not configured
     */
    public function display_api_key_notice() {
        $class = 'notice notice-error';
        $message = sprintf(
            __('SiteMail: Please <a href="%s">configure your SiteMail API key</a> to enable email sending features or change the email sending method to SMTP.', 'sitemail'),
            admin_url('options-general.php?page=sitemail-settings')
        );
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    }

    /**
     * Intercept wp_mail before it gets processed by PHPMailer
     * 
     * @param null|bool $return Short-circuit return value
     * @param array $atts Array of the `wp_mail()` arguments
     * @return bool|null
     */
    public function intercept_wp_mail($return, $atts) {
        // If SMTP is selected, let WordPress handle the email
        if (get_option('sitemail_mailer_type', 'sitemail') === 'smtp') {
            return null;
        }

        // Extract email data
        $to = $atts['to'];
        $subject = $atts['subject'];
        $message = $atts['message'];
        $headers = $atts['headers'];
        $attachments = $atts['attachments'];
        
        $this->log_message('debug', 'Intercepting wp_mail: ' . json_encode([
            'to' => $to,
            'subject' => $subject
        ]));
        
        // Handle multiple recipients
        if (!is_array($to)) {
            $to = explode(',', $to);
        }
        
        // Parse headers
        $cc = [];
        $bcc = [];
        $reply_to = '';
        $content_type = '';
        $from_email = '';
        $from_name = '';
        
        if (!is_array($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }
        
        foreach ($headers as $header) {
            if (strpos($header, ':') === false) {
                continue;
            }
            
            list($name, $content) = explode(':', trim($header), 2);
            $name = trim($name);
            $content = trim($content);
            
            switch (strtolower($name)) {
                case 'content-type':
                    $content_type = $content;
                    break;
                case 'cc':
                    $cc = array_merge($cc, explode(',', $content));
                    break;
                case 'bcc':
                    $bcc = array_merge($bcc, explode(',', $content));
                    break;
                case 'reply-to':
                    $reply_to = $content;
                    break;
                case 'from':
                    if (preg_match('/(.*)<(.*)>/', $content, $matches)) {
                        $from_name = trim($matches[1]);
                        $from_email = trim($matches[2]);
                    } else {
                        $from_email = trim($content);
                    }
                    break;
            }
        }
        
        // If content type is not set, default to HTML
        if (empty($content_type)) {
            $content_type = 'text/html';
        }
        
        // Default from email and name if not set
        if (empty($from_email)) {
            $from_email = 'wordpress@' . parse_url(site_url(), PHP_URL_HOST);
        }
        
        if (empty($from_name)) {
            $from_name = get_option('sitemail_sender_name', get_bloginfo('name'));
        }
        
        // Handle file attachments
        $api_attachments = [];
        if (!empty($attachments)) {
            if (!is_array($attachments)) {
                $attachments = explode("\n", str_replace("\r\n", "\n", $attachments));
            }
            
            foreach ($attachments as $attachment) {
                if (file_exists($attachment)) {
                    $filename = basename($attachment);
                    $file_content = base64_encode(file_get_contents($attachment));
                    
                    // Get MIME type
                    $file_info = wp_check_filetype($filename);
                    $content_type = !empty($file_info['type']) ? $file_info['type'] : 'application/octet-stream';
                    
                    $api_attachments[] = [
                        'filename' => $filename,
                        'content' => $file_content,
                        'contentType' => $content_type
                    ];
                } else {
                    $this->log_message('warning', 'Pièce jointe non trouvée: ' . $attachment);
                }
            }
        }
        
        // Build recipient list including CC/BCC
        $all_recipients = array_merge($to, $cc, $bcc);
        $all_recipients = array_map('trim', $all_recipients);
        $all_recipients = array_filter($all_recipients);
        
        if (empty($all_recipients)) {
            $this->log_message('error', 'Aucun destinataire valide spécifié');
            
            // Créer une erreur WordPress
            $error = new \WP_Error('wp_mail_failed', __('No valid recipient', 'sitemail'));
            do_action('wp_mail_failed', $error);
            
            return false;
        }
        
        // Format message based on content type
        if (strpos($content_type, 'text/html') === false) {
            // If not HTML, convert newlines to <br>
            $message = nl2br($message);
        }
        
        // Build the payload for the API
        $payload = [
            'key' => $this->api_key,
            'host' => parse_url(site_url(), PHP_URL_HOST),
            'email' => [
                'from' => $from_name . ' <' . $from_email . '>',
                'to' => implode(', ', $to),
                'subject' => $subject,
                'message' => $message,
                'attachments' => $api_attachments
            ]
        ];
        
        // Add CC if present
        if (!empty($cc)) {
            $payload['email']['cc'] = implode(', ', $cc);
        }
        
        // Add BCC if present
        if (!empty($bcc)) {
            $payload['email']['bcc'] = implode(', ', $bcc);
        }
        
        // Add reply-to if present
        if (!empty($reply_to)) {
            $this->log_message('debug', 'Reply-to before processing: ' . $reply_to);
            
            // Ignore the WordPress automatic reply-to
            $site_domain = parse_url(site_url(), PHP_URL_HOST);
            if (strpos($reply_to, 'wordpress@' . $site_domain) === 0) {
                $this->log_message('debug', 'Reply-to WordPress ignored: ' . $reply_to);
            } else {
                // Parse the reply-to to extract the email address
                if (preg_match('/(.*)<(.*)>/', $reply_to, $matches)) {
                    $payload['email']['replyTo'] = trim($matches[2]); // Just use the email part
                    $this->log_message('debug', 'Reply-to extracted: ' . $payload['email']['replyTo']);
                } else {
                    $payload['email']['replyTo'] = $reply_to;
                    $this->log_message('debug', 'Reply-to used as is: ' . $payload['email']['replyTo']);
                }
            }
        }

        $this->log_message('debug', 'Payload complet: ' . json_encode($payload));
        
        $this->log_message('info', 'Envoi d\'email via SiteMail API: ' . json_encode([
            'to' => implode(', ', $to),
            'subject' => $subject,
            'from' => $from_name . ' <' . $from_email . '>',
        ]));
        
        // Send the request to the API
        $response = $this->send_api_request($payload);
        
        // Handle the response
        if (isset($response['status']) && $response['status'] === 200) {
            // Success
            $this->log_message('info', 'Email envoyé avec succès via SiteMail API');
            return true;
        } else {
            // Failure
            $error_message = isset($response['message']) ? $response['message'] : __('Unknown error', 'sitemail');
            
            // Log the detailed error
            $error_details = '';
            if (isset($response['data']) && is_array($response['data'])) {
                $error_details = json_encode($response['data']);
            }
            
            $this->log_message('error', 'Échec d\'envoi d\'email via SiteMail API: ' . $error_message . ' - Détails: ' . $error_details);
            
            // Créer une erreur WordPress
            $error = new \WP_Error('wp_mail_failed', $error_message);
            do_action('wp_mail_failed', $error);
            
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
        // Validate API URL
        if (empty($this->api_url)) {
            $this->log_message('error', 'SiteMail API URL not configured');
            return [
                'status' => 500,
                'message' => __('SiteMail API URL not configured', 'sitemail')
            ];
        }

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
        
        // Log request attempt (without sensitive data)
        $log_payload = $payload;
        unset($log_payload['key']); // Remove API key from logs
        
        $this->log_message('debug', 'Requête vers SiteMail API: ' . $this->api_url . ' - Payload: ' . json_encode($log_payload));
        
        // Perform the HTTP POST request
        $response = wp_remote_post($this->api_url, $args);
        
        // Handle the response
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_message('error', 'Connection error: ' . $error_message);
            
            return [
                'status' => 500,
                'message' => sprintf(__('Connection error: %s', 'sitemail'), $error_message)
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);
        
        // Log the raw response for debugging
        $this->log_message('debug', 'Réponse de SiteMail API: Code ' . $status . ' - ' . $body);
        
        // Try to decode the JSON response
        $decoded_body = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_message('error', 'Invalid API response: ' . json_last_error_msg());
            return [
                'status' => 500,
                'message' => sprintf(__('Invalid API response: %s', 'sitemail'), json_last_error_msg())
            ];
        }
        
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

    /**
     * Get the API key
     *
     * @return string The API key
     */
    public function get_api_key() {
        return $this->api_key;
    }

    /**
     * Get the API URL
     *
     * @return string The API URL
     */
    public function get_api_url() {
        return $this->api_url;
    }

    /**
     * Log a message to WordPress error log
     * 
     * @param string $level Log level (info, warning, error)
     * @param string $message Message to log
     */
    private function log_message($level, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('SiteMail [' . strtoupper($level) . ']: ' . $message);
        }
    }
}

/**
 * Initialize the service
 */
function sitemail_init() {
    global $sitemail_service;
    $sitemail_service = new SiteMail_Service();
}
add_action('plugins_loaded', 'sitemail_init');

/**
 * Handle update check requests
 */
function sitemail_handle_update_check() {
    if (
        isset($_GET['action']) && $_GET['action'] === 'sitemail_check_update' &&
        isset($_GET['plugin']) && $_GET['plugin'] === plugin_basename(SITEMAIL_PLUGIN_FILE) &&
        check_admin_referer('sitemail-check-update')
    ) {
        global $sitemail_service;
        
        // Initialize a temporary updater instance
        if (class_exists('SiteMail_GitHub_Updater')) {
            $debug = defined('WP_DEBUG') && WP_DEBUG;
            $updater = new SiteMail_GitHub_Updater(
                SITEMAIL_PLUGIN_FILE, 
                SITEMAIL_GITHUB_REPO, 
                'SiteMail', 
                '', 
                $debug
            );
            
            // First test update functionality - includes connectivity test
            $updater->test_update_functionality();
            
            // If connection is successful, force update check
            $updater->force_update_check();
        }
        
        // Redirect to the plugins page
        wp_redirect(admin_url('plugins.php?plugin_status=all&settings-updated=true'));
        exit;
    }
}
add_action('admin_init', 'sitemail_handle_update_check');

/**
 * Display update messages on the plugins page
 */
function sitemail_display_update_messages() {
    if (class_exists('SiteMail_GitHub_Updater_Messages')) {
        SiteMail_GitHub_Updater_Messages::display_messages();
    }
}
add_action('admin_notices', 'sitemail_display_update_messages');

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
