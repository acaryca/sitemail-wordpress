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
        
        // Add filter to handle GitHub downloads
        add_filter('upgrader_pre_download', array($this, 'upgrader_pre_download'), 10, 4);
        
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
        
        // Setup request arguments
        $request_args = array(
            'timeout' => 10,     // Increase timeout to 10 seconds
            'sslverify' => true, // Verify SSL
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        );
        
        // Add authorization header if token is provided
        if ($this->authorize_token) {
            $request_args['headers'] = array(
                'Authorization' => 'token ' . $this->authorize_token
            );
            $this->log_debug('Using GitHub API with authorization token');
        }
        
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
            
            // Ensure zipball_url is properly formatted
            if (isset($response_data['zipball_url'])) {
                // For GitHub API, we need to use the download URL format
                $direct_download_url = 'https://github.com/' . $this->github_repo . '/archive/' . 
                                      (isset($response_data['tag_name']) ? $response_data['tag_name'] : 'master') . '.zip';
                $response_data['download_url'] = $direct_download_url;
                
                $this->log_debug('Using direct download URL: ' . $direct_download_url);
            }
            
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
            
            // Set up the response for WordPress update system
            $response = new stdClass();
            
            // Critical: Set both slug values
            // Some parts of WP use plugin slug (directory), others use full path
            $response->slug = dirname($this->basename);     // Just the directory
            $response->plugin = $this->basename;            // Full path with filename
            
            // Basic information
            $response->new_version = $this->get_version_from_tag($remote['tag_name']);
            $response->url = $this->plugin['PluginURI'] ?: 'https://github.com/' . $this->github_repo;
            $response->tested = isset($remote['tested']) ? $remote['tested'] : get_bloginfo('version');
            $response->requires = isset($remote['requires']) ? $remote['requires'] : '5.0';
            $response->requires_php = isset($remote['requires_php']) ? $remote['requires_php'] : '7.0';
            
            // Important: Add this to enable the "View details" button
            $response->id = $this->github_repo;
            
            // Use the direct download URL preferentially (important for GitHub API)
            if (isset($remote['download_url'])) {
                $download_url = $remote['download_url'];
                $this->log_debug('Using direct download URL from GitHub: ' . $download_url);
            } else {
                $download_url = isset($remote['zipball_url']) ? $remote['zipball_url'] : '';
                $this->log_debug('Using zipball URL from GitHub API: ' . $download_url);
            }
            
            // For GitHub API authenticated requests
            if ($this->authorize_token && !empty($download_url) && strpos($download_url, 'api.github.com') !== false) {
                $request_args = array(
                    'headers' => array(
                        'Authorization' => 'token ' . $this->authorize_token
                    )
                );
                $this->log_debug('Adding authorization token to download URL');
            }
            
            // Set the package URL for downloading the update
            $response->package = $download_url;
            
            // Add more details for the WordPress updater interface
            $response->sections = [
                'description' => $this->plugin['Description'],
                'changelog' => isset($remote['body']) ? $remote['body'] : __('See GitHub repository for changelog', 'sitemail')
            ];
            
            // Set icons if available
            $response->icons = [
                '1x' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/assets/icon-128x128.png',
                '2x' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/assets/icon-256x256.png'
            ];
            
            // Add the update information to the transient
            $transient->response[$this->basename] = $response;
            $this->log_debug('Added update information to transient');
            
            // Log the complete update object for debugging
            if ($this->debug) {
                $this->log_debug('Update object: ' . print_r($response, true));
            }
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

        // Ensure WordPress filesystem is initialized
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $this->log_debug('Running after_install cleanup');
        
        // Make sure we have the correct target plugin directory
        $plugin_folder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname($this->basename);
        $wp_filesystem->delete($plugin_folder, true);
        
        // GitHub places the plugin in a subfolder with the repository name and commit/tag hash
        // We need to find that folder and move content from there
        $repo_name = explode('/', $this->github_repo);
        $repo_name = end($repo_name); // Get the repository name part
        
        // Build a pattern to match the GitHub-created directory
        $pattern = $result['destination'] . DIRECTORY_SEPARATOR . $repo_name . '-*';
        $matches = glob($pattern);
        
        if (!empty($matches) && is_array($matches)) {
            $source_dir = $matches[0]; // The first match should be our directory
            $this->log_debug('Found GitHub directory: ' . $source_dir);
            
            // Move from the GitHub directory to the correct plugin directory
            if (is_dir($source_dir)) {
                $this->log_debug('Moving from ' . $source_dir . ' to ' . $plugin_folder);
                $wp_filesystem->move($source_dir, $plugin_folder);
            }
        } else {
            $this->log_debug('No GitHub directory found matching pattern: ' . $pattern);
        }
        
        // Set the destination to the plugin folder to ensure WordPress knows where it is
        $result['destination'] = $plugin_folder;

        // Activate the plugin if it was active before the update
        if ($this->active) {
            $this->log_debug('Reactivating plugin after update');
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
        
        // First test the update functionality
        $this->test_update_functionality();
        
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
            $download_url = isset($remote['download_url']) ? $remote['download_url'] : 
                           (isset($remote['zipball_url']) ? $remote['zipball_url'] : '');
                           
            $this->log_debug('Download URL: ' . $download_url);
            
            $this->messages->add_message(
                sprintf(
                    __('Mise à jour disponible pour %s: version %s. Votre version actuelle est %s. <a href="%s">Mettre à jour maintenant</a>', 'sitemail'),
                    $this->plugin_name,
                    $remote_version,
                    $current_version,
                    admin_url('update-core.php')
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

    /**
     * Process the download URL before WordPress upgrader downloads the package
     * This is needed because GitHub API URLs may need special handling
     *
     * @param bool|WP_Error $reply Whether to bail without returning the package. Default false.
     * @param string $package The package file name or URL.
     * @param WP_Upgrader $upgrader The WP_Upgrader instance.
     * @param array $hook_extra Extra arguments passed to hooked filters.
     * @return bool|WP_Error
     */
    public function upgrader_pre_download($reply, $package, $upgrader, $hook_extra) {
        // Check if this is our plugin
        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === $this->basename) {
            $this->log_debug('Pre-download hook triggered for ' . $this->basename);
            $this->log_debug('Package URL: ' . $package);
            
            // If the URL matches our GitHub repository, ensure it has the right parameters
            if (strpos($package, 'github.com/' . $this->github_repo) !== false) {
                $this->log_debug('GitHub package URL detected. Processing...');
                
                // No additional processing needed for now, just log that we're handling it
                // This hook allows us to intercept if needed in the future
            }
        }
        
        // Return the original reply (false) to allow the download to proceed
        return $reply;
    }

    /**
     * Test the download URL to make sure it's accessible
     * 
     * @param string $url The download URL to test
     * @return bool Whether the URL is accessible
     */
    private function test_download_url($url) {
        $this->log_debug('Testing download URL: ' . $url);
        
        // We'll just check the headers to see if the file exists
        $request_args = array(
            'method' => 'HEAD',
            'timeout' => 5,
            'sslverify' => true,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        );
        
        // Add authorization if needed
        if ($this->authorize_token && strpos($url, 'api.github.com') !== false) {
            $request_args['headers'] = array(
                'Authorization' => 'token ' . $this->authorize_token
            );
        }
        
        $response = wp_remote_head($url, $request_args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_debug('Download URL test failed: ' . $error_message);
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            $this->log_debug('Download URL test successful. Response code: ' . $response_code);
            return true;
        }
        
        $this->log_debug('Download URL test failed. Response code: ' . $response_code);
        return false;
    }
    
    /**
     * Test the update functionality
     * 
     * @return bool Whether the update functionality is working
     */
    public function test_update_functionality() {
        $this->log_debug('Testing update functionality');
        
        // First test GitHub connection
        if (!$this->test_connection()) {
            $this->log_debug('GitHub connection test failed');
            return false;
        }
        
        // Fetch repository info
        $remote = $this->get_repository_info();
        
        if (!$remote) {
            $this->log_debug('Failed to get repository info');
            $this->messages->add_message(
                __('Impossible de récupérer les informations du dépôt GitHub.', 'sitemail'),
                'error'
            );
            return false;
        }
        
        // Get download URL
        $download_url = '';
        if (isset($remote['download_url'])) {
            $download_url = $remote['download_url'];
        } elseif (isset($remote['zipball_url'])) {
            $download_url = $remote['zipball_url'];
        }
        
        if (empty($download_url)) {
            $this->log_debug('No download URL found in repository info');
            $this->messages->add_message(
                __('URL de téléchargement non trouvée dans les informations du dépôt.', 'sitemail'),
                'error'
            );
            return false;
        }
        
        // Test download URL
        if (!$this->test_download_url($download_url)) {
            $this->messages->add_message(
                sprintf(
                    __('URL de téléchargement inaccessible: %s', 'sitemail'),
                    $download_url
                ),
                'error'
            );
            return false;
        }
        
        $this->messages->add_message(
            __('Tous les tests de fonctionnalité de mise à jour ont réussi!', 'sitemail'),
            'success'
        );
        
        return true;
    }
} 