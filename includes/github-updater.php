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
    private $plugin_info;
    private $messages;

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
        
        // Load required components
        require_once plugin_dir_path($file) . 'includes/github-plugin-info.php';
        require_once plugin_dir_path($file) . 'includes/github-updater-messages.php';
        
        $this->plugin_info = new SiteMail_GitHub_Plugin_Info($file, $github_repo, $plugin_name);
        $this->messages = new SiteMail_GitHub_Updater_Messages($this->debug);
        
        // Add WordPress hooks
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this->plugin_info, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        
        // Add action to display messages
        add_action('admin_notices', array('SiteMail_GitHub_Updater_Messages', 'display_messages'));
        
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
            $this->messages->add_message(
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
            $this->messages->add_message(
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
        $this->messages->add_message(
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
            $this->messages->add_message(
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
            
            // Important: Add this to enable the "View details" button
            $response->id = $this->github_repo;
            
            $transient->response[$this->basename] = $response;
            $this->log_debug('Added update information to transient');
        } else {
            $this->log_debug('No update available');
            
            // Check if this was triggered manually by the user
            if (isset($_GET['action']) && $_GET['action'] === 'sitemail_check_update') {
                $this->messages->add_message(
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
        $this->messages->add_message(
            __('Vérification des mises à jour déclenchée avec succès.', 'sitemail'),
            'success'
        );
        
        return true;
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
            $this->messages->add_message(
                sprintf(
                    __('Mise à jour disponible pour %s: version %s. Votre version actuelle est %s.', 'sitemail'),
                    $this->plugin_name,
                    $remote_version,
                    $current_version
                ),
                'info'
            );
        } else {
            $this->messages->add_message(
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
            
            $this->messages->add_message(
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
            
            $this->messages->add_message(
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
        $this->messages->add_message(
            sprintf(
                __('Connexion à GitHub réussie pour le dépôt %s.', 'sitemail'),
                $this->github_repo
            ),
            'success'
        );
        
        return true;
    }
} 