<?php
/**
 * GitHub Updater Messages
 * 
 * Class to handle notification messages for the GitHub Updater.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SiteMail_GitHub_Updater_Messages {
    private $update_messages = array();
    private $debug = false;

    /**
     * Constructor
     *
     * @param bool $debug Whether to enable debug logging
     */
    public function __construct($debug = false) {
        $this->debug = $debug || (defined('SITEMAIL_DEBUG') && SITEMAIL_DEBUG);
    }

    /**
     * Add update message
     * 
     * @param string $message Message text
     * @param string $type Message type (success, error, warning)
     */
    public function add_message($message, $type = 'success') {
        $this->update_messages[] = array(
            'message' => $message,
            'type' => $type
        );
        
        // Store messages as transient
        set_transient('sitemail_update_messages', $this->update_messages, 30);
        
        if ($this->debug) {
            $this->log_debug('Added message: ' . $message . ' [' . $type . ']');
        }
    }
    
    /**
     * Display stored update messages
     */
    public static function display_messages() {
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
     * Get all current messages
     * 
     * @return array Array of messages
     */
    public function get_messages() {
        return $this->update_messages;
    }
    
    /**
     * Clear all messages
     */
    public function clear_messages() {
        $this->update_messages = array();
        delete_transient('sitemail_update_messages');
        
        if ($this->debug) {
            $this->log_debug('Cleared all messages');
        }
    }
    
    /**
     * Log debug messages
     *
     * @param string $message Debug message
     */
    private function log_debug($message) {
        if ($this->debug) {
            error_log('SiteMail GitHub Updater Messages: ' . $message);
        }
    }
} 