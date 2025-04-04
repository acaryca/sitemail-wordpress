<?php
/**
 * GitHub Updater
 * 
 * Class to check for plugin updates from a GitHub repository.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SiteMail_GitHub_Updater {
    private $file;
    private $plugin;
    private $basename;
    private $active;
    private $github_repo;
    private $plugin_name;
    private $github_response;
    private $authorize_token;
    private $github_url = 'https://api.github.com/repos/';
    private $debug = false;
    private $update_messages = array();

    /**
     * Constructor
     *
     * @param string $file Plugin file path
     * @param string $github_repo GitHub repository name (username/repo)
     * @param string $plugin_name Plugin name
     * @param string $access_token GitHub access token (optional)
     * @param bool $debug Whether to enable debug logging (optional)
     */
    public function __construct($file, $github_repo, $plugin_name, $access_token = '', $debug = false) {
        $this->file = $file;
        $this->github_repo = $github_repo;
        $this->plugin_name = $plugin_name;
        $this->authorize_token = $access_token;
        $this->debug = $debug || (defined('SITEMAIL_DEBUG') && SITEMAIL_DEBUG);
        
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        
        $this->initialize();
        
        if ($this->debug) {
            $this->log_debug('GitHub Updater initialized for ' . $this->plugin_name);
            $this->log_debug('Repository: ' . $this->github_repo);
            $this->log_debug('Plugin version: ' . $this->plugin['Version']);
        }
    }

    /**
     * Initialize plugin data
     */
    private function initialize() {
        $this->plugin = get_plugin_data($this->file);
        $this->basename = plugin_basename($this->file);
        $this->active = is_plugin_active($this->basename);
    }

    /**
     * Get repository information from GitHub
     *
     * @return array|bool Repository info or false on failure
     */
    private function get_repository_info() {
        if (!empty($this->github_response)) {
            $this->log_debug('Using cached repository info');
            return $this->github_response;
        }

        $this->log_debug('Fetching repository info from GitHub');
        $request_uri = $this->github_url . $this->github_repo . '/releases/latest';
        
        if ($this->authorize_token) {
            $request_uri = add_query_arg('access_token', $this->authorize_token, $request_uri);
            $this->log_debug('Using GitHub API with authorization token');
        }

        // Add request args for better reliability
        $request_args = array(
            'timeout' => 10,     // Increase timeout to 10 seconds
            'sslverify' => true, // Verify SSL
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        );
        
        $this->log_debug('Making request to: ' . $request_uri);
        $response = wp_remote_get($request_uri, $request_args);
        
        // Check for errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_debug('GitHub API request error: ' . $error_message);
            error_log('SiteMail GitHub Updater Error: ' . $error_message);
            
            // Add error message for the user
            $this->add_update_message(
                sprintf(
                    __('Erreur lors de la connexion à GitHub: %s', 'sitemail'),
                    $error_message
                ),
                'error'
            );
            
            return false;
        }
        
        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        $this->log_debug('GitHub API response code: ' . $response_code);
        
        if ($response_code !== 200) {
            $error_message = 'Unexpected response code ' . $response_code;
            error_log('SiteMail GitHub Updater Error: ' . $error_message);
            
            // Add error message for the user
            $this->add_update_message(
                sprintf(
                    __('Erreur lors de la récupération des mises à jour: Code %s. Vérifiez que le dépôt GitHub %s existe et est accessible.', 'sitemail'),
                    $response_code,
                    $this->github_repo
                ),
                'error'
            );
            
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (is_array($response_data) && !empty($response_data)) {
            $this->log_debug('Successfully retrieved repository info');
            if (isset($response_data['tag_name'])) {
                $this->log_debug('Latest version: ' . $response_data['tag_name']);
            }
            $this->github_response = $response_data;
            return $response_data;
        }
        
        $this->log_debug('Invalid response from GitHub');
        error_log('SiteMail GitHub Updater Error: Invalid response from GitHub');
        
        // Add error message for the user
        $this->add_update_message(
            __('Réponse invalide reçue de GitHub. Impossible de vérifier les mises à jour.', 'sitemail'),
            'error'
        );
        
        return false;
    }

    /**
     * Check for plugin updates
     *
     * @param object $transient Transient data
     * @return object Modified transient data
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            $this->log_debug('No plugins to check for updates');
            return $transient;
        }

        $this->log_debug('Checking for updates for ' . $this->plugin_name);
        $this->initialize();
        $remote = $this->get_repository_info();

        if ($remote && version_compare($this->plugin['Version'], $this->get_version_from_tag($remote['tag_name']), '<')) {
            $this->log_debug('New version ' . $this->get_version_from_tag($remote['tag_name']) . ' available. Current: ' . $this->plugin['Version']);
            
            // Add message about new version
            $this->add_update_message(
                sprintf(
                    __('Nouvelle version %s disponible pour %s! Version actuelle: %s', 'sitemail'),
                    $this->get_version_from_tag($remote['tag_name']),
                    $this->plugin_name,
                    $this->plugin['Version']
                ),
                'info'
            );
            
            $response = new stdClass();
            $response->slug = $this->basename;
            $response->plugin = $this->basename;
            $response->new_version = $this->get_version_from_tag($remote['tag_name']);
            $response->url = $this->plugin['PluginURI'] ?: 'https://github.com/' . $this->github_repo;
            $response->package = $remote['zipball_url'];
            $response->tested = isset($remote['tested']) ? $remote['tested'] : get_bloginfo('version');
            $response->requires = isset($remote['requires']) ? $remote['requires'] : '5.0';
            $response->requires_php = isset($remote['requires_php']) ? $remote['requires_php'] : '7.0';
            $response->icons = [
                '1x' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/assets/icon-128x128.png',
                '2x' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/assets/icon-256x256.png'
            ];
            $response->banners = [
                'low' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/assets/banner-772x250.jpg',
                'high' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/assets/banner-1544x500.jpg'
            ];
            
            // Important: Add this to enable the "View details" button
            if (!isset($response->id)) {
                $response->id = $this->github_repo;
            }
            
            $transient->response[$this->basename] = $response;
            $this->log_debug('Added update information to transient');
        } else {
            $this->log_debug('No update available');
            
            // Check if this was triggered manually by the user
            if (isset($_GET['action']) && $_GET['action'] === 'sitemail_check_update') {
                $this->add_update_message(
                    sprintf(
                        __('Le plugin %s est à jour (version %s)', 'sitemail'),
                        $this->plugin_name,
                        $this->plugin['Version']
                    ),
                    'success'
                );
            }
        }

        return $transient;
    }

    /**
     * Extract version from GitHub tag name
     *
     * @param string $tag_name GitHub tag name
     * @return string Version number
     */
    private function get_version_from_tag($tag_name) {
        // Remove 'v' prefix if present
        return ltrim($tag_name, 'v');
    }

    /**
     * Clear cached GitHub responses
     * Forces the plugin to fetch fresh data from GitHub
     */
    public function clear_cache() {
        $this->log_debug('Clearing GitHub response cache');
        $this->github_response = null;
        delete_site_transient('update_plugins');
        
        // Add a message for the user
        $this->add_update_message(
            __('Vérification des mises à jour déclenchée avec succès.', 'sitemail'),
            'success'
        );
        
        return true;
    }

    /**
     * Add update message
     * 
     * @param string $message Message text
     * @param string $type Message type (success, error, warning)
     */
    public function add_update_message($message, $type = 'success') {
        $this->update_messages[] = array(
            'message' => $message,
            'type' => $type
        );
        
        // Store messages as transient
        set_transient('sitemail_update_messages', $this->update_messages, 30);
    }
    
    /**
     * Display stored update messages
     */
    public static function display_update_messages() {
        $messages = get_transient('sitemail_update_messages');
        
        if (!empty($messages) && is_array($messages)) {
            foreach ($messages as $message) {
                $class = 'notice notice-' . esc_attr($message['type']);
                printf(
                    '<div class="%1$s"><p>%2$s</p></div>',
                    $class,
                    esc_html($message['message'])
                );
            }
            
            // Clean up the transient
            delete_transient('sitemail_update_messages');
        }
    }

    /**
     * Fill the Plugin Information popup with custom data
     *
     * @param false|object|array $result Result object/array
     * @param string $action 'plugin_information'
     * @param object $args Arguments
     * @return object Plugin information
     */
    public function plugin_popup($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        // Check if this is our plugin
        if (!empty($args->slug) && ($args->slug === $this->basename || $args->slug === dirname($this->basename))) {
            $this->log_debug('Generating plugin information popup for: ' . $args->slug);
            $this->initialize();
            $remote = $this->get_repository_info();

            if ($remote) {
                $this->log_debug('Returning custom plugin info for ' . $this->plugin_name);
                $plugin = new stdClass();
                $plugin->name = $this->plugin['Name'];
                $plugin->slug = $this->basename;
                $plugin->version = $this->get_version_from_tag($remote['tag_name']);
                $plugin->author = $this->plugin['AuthorName'] ?? $this->plugin['Author'];
                $plugin->author_profile = $this->plugin['AuthorURI'] ?? '';
                $plugin->requires = isset($remote['requires']) ? $remote['requires'] : '5.0';
                $plugin->requires_php = isset($remote['requires_php']) ? $remote['requires_php'] : '7.0';
                $plugin->tested = isset($remote['tested']) ? $remote['tested'] : get_bloginfo('version');
                $plugin->downloaded = 0;
                $plugin->last_updated = isset($remote['published_at']) ? date('Y-m-d', strtotime($remote['published_at'])) : '';
                $plugin->homepage = $this->plugin['PluginURI'] ?: 'https://github.com/' . $this->github_repo;
                
                // Sections information
                $plugin->sections = [
                    'description' => $this->plugin['Description'],
                    'installation' => __('Install this plugin like you would install any other WordPress plugin.', 'sitemail'),
                    'changelog' => $this->get_changelog_html($remote),
                    'github' => sprintf(
                        '<p>' . __('View this plugin on GitHub: %s', 'sitemail') . '</p>', 
                        '<a href="https://github.com/' . $this->github_repo . '" target="_blank">https://github.com/' . $this->github_repo . '</a>'
                    )
                ];
                $plugin->download_link = $remote['zipball_url'];
                
                // Icons are important for the modal display
                $plugin->icons = [
                    '1x' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/assets/icon-128x128.png',
                    '2x' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/assets/icon-256x256.png'
                ];
                
                // Banners for the modal header
                $plugin->banners = [
                    'low' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/assets/banner-772x250.jpg',
                    'high' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/assets/banner-1544x500.jpg'
                ];
                
                // Add id property to ensure compatibility with WordPress
                $plugin->id = $this->github_repo;
                
                // Add important WordPress.org info that might be expected
                $plugin->rating = 100; // Default to 100% rating since it's our own plugin
                $plugin->num_ratings = 0;
                $plugin->active_installs = 0;
                
                return $plugin;
            } else {
                $this->log_debug('Failed to get plugin information from GitHub');
                $this->add_update_message(
                    __('Impossible de récupérer les informations détaillées du plugin depuis GitHub.', 'sitemail'),
                    'error'
                );
            }
        }

        return $result;
    }

    /**
     * Format release notes as HTML
     *
     * @param array $remote GitHub API response
     * @return string Formatted HTML
     */
    private function get_changelog_html($remote) {
        $changelog = '';
        
        // Add latest release info
        if (!empty($remote['body'])) {
            $changelog .= '<h4>' . sprintf(
                __('New in version %s', 'sitemail'),
                $this->get_version_from_tag($remote['tag_name'])
            ) . '</h4>';
            $changelog .= '<pre class="changelog">' . esc_html($remote['body']) . '</pre>';
        }
        
        // Try to get recent releases
        $releases_url = $this->github_url . $this->github_repo . '/releases';
        
        // Add request args for better reliability
        $request_args = array(
            'timeout' => 10,     // Increase timeout to 10 seconds
            'sslverify' => true, // Verify SSL
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        );
        
        $releases_response = wp_remote_get($releases_url, $request_args);
        
        if (!is_wp_error($releases_response) && wp_remote_retrieve_response_code($releases_response) === 200) {
            $releases = json_decode(wp_remote_retrieve_body($releases_response), true);
            
            if (is_array($releases) && count($releases) > 1) {
                // Skip the first one as we already displayed it
                $releases = array_slice($releases, 1, 5);
                
                foreach ($releases as $release) {
                    if (!empty($release['body'])) {
                        $changelog .= '<h4>' . sprintf(
                            __('Version %s', 'sitemail'),
                            $this->get_version_from_tag($release['tag_name'])
                        ) . '</h4>';
                        $changelog .= '<pre class="changelog">' . esc_html($release['body']) . '</pre>';
                    }
                }
            }
        } else {
            if (is_wp_error($releases_response)) {
                error_log('SiteMail GitHub Updater Error (changelog): ' . $releases_response->get_error_message());
            } else {
                error_log('SiteMail GitHub Updater Error (changelog): Unexpected response code ' . 
                    wp_remote_retrieve_response_code($releases_response));
            }
        }
        
        if (empty($changelog)) {
            $changelog = __('No detailed changelog available. Check the GitHub repository for more information.', 'sitemail');
        }
        
        return $changelog;
    }

    /**
     * Actions to perform after the plugin is updated
     *
     * @param bool $response Installation response
     * @param array $hook_extra Extra arguments
     * @param array $result Installation result data
     * @return array Result
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        $install_directory = plugin_dir_path($this->file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;

        if ($this->active) {
            activate_plugin($this->basename);
        }

        return $result;
    }

    /**
     * Log debug messages
     *
     * @param string $message Debug message
     */
    private function log_debug($message) {
        if ($this->debug) {
            error_log('SiteMail GitHub Updater: ' . $message);
        }
    }

    /**
     * Force update check
     * 
     * @return bool Whether the update check was successful
     */
    public function force_update_check() {
        $this->log_debug('Force checking for updates');
        
        // Clear all transients related to updates
        delete_site_transient('update_plugins');
        
        // Clear internal cache
        $this->github_response = null;
        
        // Refetch repository info
        $remote = $this->get_repository_info();
        
        if (!$remote) {
            $this->log_debug('Failed to get repository info during forced update check');
            return false;
        }
        
        // Check version
        $this->initialize();
        $current_version = $this->plugin['Version'];
        $remote_version = $this->get_version_from_tag($remote['tag_name']);
        
        $has_update = version_compare($current_version, $remote_version, '<');
        
        if ($has_update) {
            $this->add_update_message(
                sprintf(
                    __('Mise à jour disponible pour %s: version %s. Votre version actuelle est %s.', 'sitemail'),
                    $this->plugin_name,
                    $remote_version,
                    $current_version
                ),
                'info'
            );
        } else {
            $this->add_update_message(
                sprintf(
                    __('Vous utilisez la dernière version de %s (%s).', 'sitemail'),
                    $this->plugin_name,
                    $current_version
                ),
                'success'
            );
        }
        
        return true;
    }
    
    /**
     * Test GitHub connection
     * 
     * @return bool Whether the connection is working
     */
    public function test_connection() {
        $this->log_debug('Testing GitHub API connection');
        
        // Build a test request to the repository
        $request_uri = $this->github_url . $this->github_repo;
        
        $request_args = array(
            'timeout' => 5,
            'sslverify' => true,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        );
        
        $response = wp_remote_get($request_uri, $request_args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_debug('GitHub connection test failed: ' . $error_message);
            
            $this->add_update_message(
                sprintf(
                    __('Échec de la connexion à GitHub: %s', 'sitemail'),
                    $error_message
                ),
                'error'
            );
            
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $this->log_debug('GitHub connection test received unexpected response code: ' . $response_code);
            
            $this->add_update_message(
                sprintf(
                    __('Connexion à GitHub établie mais avec une réponse inattendue (code %s). Vérifiez que le dépôt %s existe.', 'sitemail'),
                    $response_code,
                    $this->github_repo
                ),
                'warning'
            );
            
            return false;
        }
        
        $this->log_debug('GitHub connection test successful');
        $this->add_update_message(
            sprintf(
                __('Connexion à GitHub réussie pour le dépôt %s.', 'sitemail'),
                $this->github_repo
            ),
            'success'
        );
        
        return true;
    }
} 