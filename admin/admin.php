<?php
/**
 * SiteMail admin functionality
 * 
 * @package SiteMail
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class that handles all admin functionality
 */
class SiteMail_Admin {
    /**
     * Initialize the admin functionality
     */
    public function __construct() {
        // Add admin menus
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Add a link to the settings in the plugins list
        add_filter('plugin_action_links_' . plugin_basename(SITEMAIL_PLUGIN_FILE), [$this, 'add_settings_link']);
        
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Add hook to show stored error messages after redirect
        add_action('admin_notices', [$this, 'show_stored_messages']);
        
        // Enqueue admin styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Setup test routes
        $this->setup_test_routes();
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
     * Enqueue admin styles
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_sitemail-settings') {
            return;
        }
        
        wp_enqueue_style(
            'sitemail-admin-styles',
            plugin_dir_url(SITEMAIL_PLUGIN_FILE) . 'admin/style.css',
            [],
            filemtime(plugin_dir_path(SITEMAIL_PLUGIN_FILE) . 'admin/style.css')
        );
        
        wp_enqueue_script('jquery');
    }
    
    /**
     * Register the plugin settings
     */
    public function register_settings() {
        register_setting(
            'sitemail_settings',
            'sitemail_api_key',
            [
                'sanitize_callback' => [$this, 'validate_settings']
            ]
        );
        
        register_setting(
            'sitemail_settings',
            'sitemail_sender_name',
            [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );
    }
    
    /**
     * Validate settings and add confirmation message
     * 
     * @param string $input The value to validate
     * @return string The sanitized value
     */
    public function validate_settings($input) {
        // Sanitize the API key
        $api_key = sanitize_text_field($input);
        
        // Add a success message
        add_settings_error(
            'sitemail_settings',
            'sitemail_settings_updated',
            __('Paramètres SiteMail enregistrés avec succès.', 'sitemail'),
            'updated'
        );
        
        return $api_key;
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
        <div class="sitemail__container">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p>
                <?php _e('Cette page vous permet de configurer l\'envoi d\'emails depuis votre site via SiteMail.', 'sitemail'); ?>
            </p>
            
            <div class="sitemail__notice sitemail__notice--info">
                <p><?php _e('SiteMail est un service d\'envoi d\'emails fiable qui garantit une meilleure délivrabilité de vos emails.', 'sitemail'); ?></p>
            </div>
            
            <form method="post" action="options.php" class="sitemail__block">
                <?php
                settings_fields('sitemail_settings');
                do_settings_sections('sitemail_settings');
                ?>
                <div class="sitemail__block-header">
                    <h3><?php _e('Configuration SiteMail', 'sitemail'); ?></h3>
                </div>
                <div class="sitemail__block-content">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><?php _e('Clé API SiteMail', 'sitemail'); ?></th>
                            <td>
                                <input type="text" name="sitemail_api_key" value="<?php echo esc_attr(get_option('sitemail_api_key')); ?>" class="regular-text" />
                                <p class="description"><?php _e('Entrez votre clé API SiteMail. Vous pouvez également définir la clé dans wp-config.php avec <code>define(\'SITEMAIL_API_KEY\', \'votre-clé-api\');</code>', 'sitemail'); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Nom de l\'expéditeur', 'sitemail'); ?></th>
                            <td>
                                <input type="text" name="sitemail_sender_name" value="<?php echo esc_attr(get_option('sitemail_sender_name', get_bloginfo('name'))); ?>" class="regular-text" />
                                <p class="description"><?php _e('Le nom qui apparaîtra comme expéditeur de vos emails.', 'sitemail'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(); ?>
                </div>
            </form>
        
            <div class="sitemail__block">
                <div class="sitemail__block-header">
                    <h3><?php _e('Tester l\'envoi d\'emails', 'sitemail'); ?></h3>
                </div>
                <div class="sitemail__block-content">
                    <p>
                        <?php _e('Vous pouvez tester votre configuration en utilisant les options ci-dessous.', 'sitemail'); ?>
                    </p>
                    
                    <div class="sitemail-test-options">
                        <a href="<?php echo esc_url(add_query_arg(['sitemail_test' => '1'])); ?>" class="button"><?php _e('Envoyer un email de test', 'sitemail'); ?></a>
                        <a href="<?php echo esc_url(add_query_arg(['sitemail_test_api' => '1'])); ?>" class="button"><?php _e('Tester la connexion API', 'sitemail'); ?></a>
                        <a href="<?php echo esc_url(add_query_arg(['sitemail_direct_api' => '1'])); ?>" class="button"><?php _e('Test direct API', 'sitemail'); ?></a>
                    </div>
                    
                    <form id="sitemail-custom-test-form" method="post">
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><?php _e('Email du destinataire', 'sitemail'); ?></th>
                                <td>
                                    <input type="email" id="sitemail-test-email" name="sitemail_test_email" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text" required />
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('Sujet de l\'email', 'sitemail'); ?></th>
                                <td>
                                    <input type="text" id="sitemail-test-subject" name="sitemail_test_subject" value="<?php echo esc_attr(__('Test email via SiteMail', 'sitemail')); ?>" class="regular-text" required />
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" class="button button-primary" id="sitemail-send-test"><?php _e('Envoyer l\'email de test', 'sitemail'); ?></button>
                            <span class="spinner" id="sitemail-test-spinner" style="float: none; margin-top: 0;"></span>
                        </p>
                    </form>
                    
                    <div id="sitemail-test-result" style="display: none; margin-top: 15px;"></div>
                    
                    <script>
                        jQuery(document).ready(function($) {
                            $('#sitemail-custom-test-form').on('submit', function(e) {
                                e.preventDefault();
                                
                                var email = $('#sitemail-test-email').val();
                                var subject = $('#sitemail-test-subject').val();
                                
                                $('#sitemail-send-test').prop('disabled', true);
                                $('#sitemail-test-spinner').addClass('is-active');
                                $('#sitemail-test-result').hide();
                                
                                $.post(ajaxurl, {
                                    action: 'sitemail_send_test_email',
                                    email: email,
                                    subject: subject,
                                    nonce: '<?php echo wp_create_nonce('sitemail_test_nonce'); ?>'
                                }, function(response) {
                                    $('#sitemail-test-result').html(response).show();
                                    $('#sitemail-send-test').prop('disabled', false);
                                    $('#sitemail-test-spinner').removeClass('is-active');
                                }).fail(function() {
                                    $('#sitemail-test-result').html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Une erreur s\'est produite lors de l\'envoi de l\'email de test.', 'sitemail')); ?></p></div>').show();
                                    $('#sitemail-send-test').prop('disabled', false);
                                    $('#sitemail-test-spinner').removeClass('is-active');
                                });
                            });
                        });
                    </script>
                </div>
            </div>
            
            <div class="sitemail__block">
                <div class="sitemail__block-header">
                    <h3><?php _e('À propos de SiteMail', 'sitemail'); ?></h3>
                </div>
                <div class="sitemail__block-content">
                    <p>
                        <?php _e('SiteMail est un service d\'envoi d\'emails pour les sites WordPress. Ce plugin vous permet de connecter votre site à SiteMail pour bénéficier d\'un envoi d\'emails fiable.', 'sitemail'); ?>
                    </p>
                    <p>
                        <?php _e('Pour plus d\'informations, visitez <a href="https://sitemail.ca" target="_blank">sitemail.ca</a>.', 'sitemail'); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Setup test routes for admin functionality
     */
    private function setup_test_routes() {
        if (is_admin()) {
            if (isset($_GET['sitemail_test']) && $_GET['sitemail_test'] === '1') {
                add_action('admin_init', [$this, 'test_email']);
            }
            
            if (isset($_GET['sitemail_test_api']) && $_GET['sitemail_test_api'] === '1') {
                add_action('admin_init', [$this, 'test_api_connection']);
            }
            
            if (isset($_GET['sitemail_direct_api']) && $_GET['sitemail_direct_api'] === '1') {
                add_action('admin_init', [$this, 'test_direct_api']);
            }
            
            // Add AJAX endpoint for custom test email
            add_action('wp_ajax_sitemail_send_test_email', [$this, 'ajax_test_email']);
        }
    }
    
    /**
     * AJAX handler for sending test email
     */
    public function ajax_test_email() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sitemail_test_nonce')) {
            wp_send_json_error(__('Sécurité: Nonce invalide.', 'sitemail'));
            wp_die();
        }
        
        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Vous n\'avez pas les permissions suffisantes.', 'sitemail'));
            wp_die();
        }
        
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $subject = isset($_POST['subject']) ? sanitize_text_field($_POST['subject']) : __('Test email via SiteMail', 'sitemail');
        
        if (empty($email)) {
            echo '<div class="notice notice-error inline"><p>' . __('Veuillez saisir une adresse email valide.', 'sitemail') . '</p></div>';
            wp_die();
        }
        
        global $sitemail_service;
        
        // Check if API key is configured
        $api_key = $sitemail_service->get_api_key();
        if (empty($api_key)) {
            echo '<div class="notice notice-error inline"><p>' . __('Erreur: Clé API SiteMail non configurée. Veuillez d\'abord configurer votre clé API.', 'sitemail') . '</p></div>';
            wp_die();
        }
        
        $message = __('Ceci est un email de test envoyé via SiteMail. Si vous recevez cet email, la configuration fonctionne correctement. Veuillez vérifier l\'adresse de l\'expéditeur pour vous assurer qu\'elle correspond à votre configuration attendue.', 'sitemail');
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        add_action('wp_mail_failed', function($wp_error) {
            if (is_wp_error($wp_error)) {
                echo '<div class="notice notice-error inline"><p>' . esc_html($wp_error->get_error_message()) . '</p></div>';
                wp_die();
            }
        });
        
        $result = wp_mail($email, $subject, $message, $headers);
        
        if ($result) {
            echo '<div class="notice notice-success inline"><p>' . sprintf(__('Email de test envoyé avec succès à %s !', 'sitemail'), esc_html($email)) . '</p></div>';
        } else {
            echo '<div class="notice notice-error inline"><p>' . __('Échec de l\'envoi de l\'email de test. Vérifiez la configuration et les journaux pour plus de détails.', 'sitemail') . '</p></div>';
        }
        
        wp_die();
    }
    
    /**
     * Show stored settings error messages
     */
    public function show_stored_messages() {
        // Only on our settings page
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_sitemail-settings') {
            return;
        }
        
        // Check if we have stored messages
        $stored_errors = get_transient('sitemail_settings_errors');
        if ($stored_errors) {
            // Afficher directement les messages stockés
            foreach ($stored_errors as $error) {
                $class = ($error['type'] === 'error') ? 'notice notice-error' : 'notice notice-success';
                printf(
                    '<div class="%1$s"><p>%2$s</p></div>',
                    esc_attr($class),
                    esc_html($error['message'])
                );
            }
            
            // Clean up the transient
            delete_transient('sitemail_settings_errors');
        }
    }
    
    /**
     * Function to test email sending
     */
    public function test_email() {
        // Only accessible to administrators
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sitemail'));
        }
        
        global $sitemail_service;
        
        // Check if API key is configured
        $api_key = $sitemail_service->get_api_key();
        if (empty($api_key)) {
            add_settings_error(
                'sitemail_settings',
                'sitemail_api_key_missing',
                __('Erreur: Clé API SiteMail non configurée. Veuillez d\'abord configurer votre clé API.', 'sitemail'),
                'error'
            );
            
            // Store the messages so they can be displayed after redirect
            set_transient('sitemail_settings_errors', get_settings_errors('sitemail_settings'), 30);
            
            // Redirect to the settings page
            wp_redirect(admin_url('options-general.php?page=sitemail-settings&settings-updated=true'));
            exit;
        }
        
        // Add a hook to capture mail errors
        add_action('wp_mail_failed', [$this, 'capture_test_mail_error']);
        
        // Send a test email
        $to = get_option('admin_email');
        $subject = __('Test email via SiteMail', 'sitemail');
        $message = __('This is a test email sent via SiteMail. If you receive this email, the configuration is working correctly. Please verify the sender email address to ensure it matches your expected configuration.', 'sitemail');
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        $result = wp_mail($to, $subject, $message, $headers);
        
        // Remove the hook to avoid affecting other emails
        remove_action('wp_mail_failed', [$this, 'capture_test_mail_error']);
        
        // Check if an error was captured
        $mail_error = get_transient('sitemail_test_mail_error');
        delete_transient('sitemail_test_mail_error');
        
        if ($mail_error) {
            // We have a specific error from the mail system
            add_settings_error(
                'sitemail_settings',
                'sitemail_test_error',
                sprintf(
                    __('Échec de l\'envoi du courriel de test: %s', 'sitemail'),
                    $mail_error
                ),
                'error'
            );
        } elseif (!$result) {
            // Generic failure
            add_settings_error(
                'sitemail_settings',
                'sitemail_test_error',
                __('Échec de l\'envoi du courriel de test. Vérifiez la configuration et les journaux pour plus de détails.', 'sitemail'),
                'error'
            );
        } else {
            // Success
            add_settings_error(
                'sitemail_settings',
                'sitemail_test_success',
                sprintf(
                    __('Courriel de test envoyé avec succès à %s! Veuillez vérifier votre boîte de réception.', 'sitemail'),
                    esc_html($to)
                ),
                'updated'
            );
        }
        
        // Store the messages so they can be displayed after redirect
        set_transient('sitemail_settings_errors', get_settings_errors('sitemail_settings'), 30);
        
        // Redirect to the settings page
        wp_redirect(admin_url('options-general.php?page=sitemail-settings&settings-updated=true'));
        exit;
    }
    
    /**
     * Capture mail error during test
     * 
     * @param \WP_Error $wp_error WordPress error
     */
    public function capture_test_mail_error($wp_error) {
        // Store the error message in a transient
        set_transient('sitemail_test_mail_error', $wp_error->get_error_message(), 30);
        
        // Also log it
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('SiteMail - Test email error: ' . $wp_error->get_error_message());
        }
    }
    
    /**
     * Function to test API connection
     */
    public function test_api_connection() {
        // Only accessible to administrators
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sitemail'));
        }
        
        global $sitemail_service;
        
        // Check if API key is configured
        $api_key = $sitemail_service->get_api_key();
        if (empty($api_key)) {
            add_settings_error(
                'sitemail_settings',
                'sitemail_api_key_missing',
                __('Erreur: Clé API SiteMail non configurée. Veuillez d\'abord configurer votre clé API.', 'sitemail'),
                'error'
            );
            
            // Store the messages so they can be displayed after redirect
            set_transient('sitemail_settings_errors', get_settings_errors('sitemail_settings'), 30);
            
            // Redirect to the settings page
            wp_redirect(admin_url('options-general.php?page=sitemail-settings&settings-updated=true'));
            exit;
        }
        
        // Test the API connection
        $result = $sitemail_service->test_api_connection();
        
        if (isset($result['status']) && $result['status'] === 200) {
            // Success
            add_settings_error(
                'sitemail_settings',
                'sitemail_api_test_success',
                sprintf(
                    __('Connexion API réussie! URL: %s - Réponse: %s', 'sitemail'),
                    esc_html($sitemail_service->get_api_url()),
                    isset($result['message']) ? esc_html($result['message']) : __('OK', 'sitemail')
                ),
                'updated'
            );
        } else {
            // Failure
            $error_message = isset($result['message']) ? $result['message'] : __('Erreur inconnue', 'sitemail');
            $error_details = '';
            
            // Add response code if available
            if (isset($result['status'])) {
                $error_details .= sprintf(__('Code: %s', 'sitemail'), $result['status']);
            }
            
            // Add raw response data if available
            if (!empty($result['data']) && is_array($result['data'])) {
                $error_data = json_encode($result['data'], JSON_PRETTY_PRINT);
                if ($error_data) {
                    $error_details .= '<br>' . sprintf(__('Détails: %s', 'sitemail'), '<pre>' . esc_html($error_data) . '</pre>');
                }
            }
            
            // Add API URL to error message
            $api_url_info = sprintf(__('URL API: %s', 'sitemail'), esc_html($sitemail_service->get_api_url()));
            
            add_settings_error(
                'sitemail_settings',
                'sitemail_api_test_error',
                sprintf(
                    __('Échec de la connexion API: %s<br>%s<br>%s', 'sitemail'),
                    esc_html($error_message),
                    $api_url_info,
                    $error_details
                ),
                'error'
            );
        }
        
        // Store the messages so they can be displayed after redirect
        set_transient('sitemail_settings_errors', get_settings_errors('sitemail_settings'), 30);
        
        // Redirect to the settings page
        wp_redirect(admin_url('options-general.php?page=sitemail-settings&settings-updated=true'));
        exit;
    }
    
    /**
     * Function to test direct API usage
     */
    public function test_direct_api() {
        // Only accessible to administrators
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sitemail'));
        }
        
        global $sitemail_service;
        
        // Check if API key is configured
        $api_key = $sitemail_service->get_api_key();
        if (empty($api_key)) {
            add_settings_error(
                'sitemail_settings',
                'sitemail_api_key_missing',
                __('Erreur: Clé API SiteMail non configurée. Veuillez d\'abord configurer votre clé API.', 'sitemail'),
                'error'
            );
            
            // Store the messages so they can be displayed after redirect
            set_transient('sitemail_settings_errors', get_settings_errors('sitemail_settings'), 30);
            
            // Redirect to the settings page
            wp_redirect(admin_url('options-general.php?page=sitemail-settings&settings-updated=true'));
            exit;
        }
        
        // Envoyer un email directement via l'API
        $to = get_option('admin_email');
        $subject = __('Test direct API SiteMail', 'sitemail');
        $message = __('Ceci est un test direct de l\'API SiteMail. Si vous recevez cet email, la configuration fonctionne correctement.', 'sitemail');
        
        // Préparer les données pour l'API
        $payload = [
            'key' => $api_key,
            'host' => parse_url(site_url(), PHP_URL_HOST),
            'email' => [
                'from' => get_option('sitemail_sender_name', get_bloginfo('name')) . ' <wordpress@' . parse_url(site_url(), PHP_URL_HOST) . '>',
                'to' => $to,
                'subject' => $subject,
                'message' => $message
            ]
        ];
        
        // Envoyer directement
        $result = $sitemail_service->send_direct_api_request($payload);
        
        if (isset($result['status']) && $result['status'] === 200) {
            // Success
            add_settings_error(
                'sitemail_settings',
                'sitemail_direct_api_success',
                sprintf(
                    __('Email envoyé avec succès via API directe à %s! URL: %s', 'sitemail'),
                    esc_html($to),
                    esc_html($sitemail_service->get_api_url())
                ),
                'updated'
            );
        } else {
            // Failure
            $error_message = isset($result['message']) ? $result['message'] : __('Erreur inconnue', 'sitemail');
            $error_details = '';
            
            // Add response code if available
            if (isset($result['status'])) {
                $error_details .= sprintf(__('Code: %s', 'sitemail'), $result['status']);
            }
            
            // Add raw response data if available
            if (!empty($result['data']) && is_array($result['data'])) {
                $error_data = json_encode($result['data'], JSON_PRETTY_PRINT);
                if ($error_data) {
                    $error_details .= '<br>' . sprintf(__('Détails: %s', 'sitemail'), '<pre>' . esc_html($error_data) . '</pre>');
                }
            }
            
            // Add API URL to error message
            $api_url_info = sprintf(__('URL API: %s', 'sitemail'), esc_html($sitemail_service->get_api_url()));
            
            add_settings_error(
                'sitemail_settings',
                'sitemail_direct_api_error',
                sprintf(
                    __('Échec de l\'envoi direct via API: %s<br>%s<br>%s', 'sitemail'),
                    esc_html($error_message),
                    $api_url_info,
                    $error_details
                ),
                'error'
            );
        }
        
        // Store the messages so they can be displayed after redirect
        set_transient('sitemail_settings_errors', get_settings_errors('sitemail_settings'), 30);
        
        // Redirect to the settings page
        wp_redirect(admin_url('options-general.php?page=sitemail-settings&settings-updated=true'));
        exit;
    }
}

/**
 * Initialize admin functionality
 */
function sitemail_admin_init() {
    global $sitemail_admin;
    $sitemail_admin = new SiteMail_Admin();
}
add_action('admin_init', 'sitemail_admin_init'); 