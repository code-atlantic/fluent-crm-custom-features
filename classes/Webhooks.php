<?php

/**
 * Fix SmartLink redirects.
 *
 * @package CustomCRM
 */

namespace CustomCRM;

class Webhooks {
    
    /**
     * Webhook mapping configuration
     * Maps webhook IDs to their respective mapping methods
     */
    private $webhook_mappings = [
        61 => 'map_instawp_demo_webhook', // InstaWP Demo Site webhook
        175 => 'map_instawp_demo_webhook', // InstaWP Pro Demo Site Webhook
        176 => 'map_instawp_demo_webhook', // InstaWP Ecommerce Demo Site Webhook
        // Add more webhook mappings here as needed
        // 62 => 'map_another_webhook',
        // 63 => 'map_third_webhook',
    ];

    public function __construct() {
        add_filter('fluent_crm/incoming_webhook_data', [$this, 'incoming_webhook_data'], 10, 3);
    }

    /**
     * Main webhook data processing method
     * Routes webhook data to appropriate mapping method based on webhook ID
     */
    public function incoming_webhook_data($postedData, $webhook, $request) {
        // Check if we have a mapping method for this webhook
        if (!isset($this->webhook_mappings[$webhook->id])) {
            return $postedData;
        }

        $mapping_method = $this->webhook_mappings[$webhook->id];
        
        // Call the specific mapping method if it exists
        if (method_exists($this, $mapping_method)) {
            return $this->$mapping_method($postedData, $webhook, $request);
        }

        return $postedData;
    }

    /**
     * Map InstaWP Demo Site webhook data (webhook ID: 61)
     * Remaps the data to match the custom contact fields for demo sites
     */
    private function map_instawp_demo_webhook($postedData, $webhook, $request) {
        // Remap the data to match the custom contact fields.
        $postedData['demo_magic_login_url'] = isset($postedData['magic_login']) ? $this->correct_broken_url($postedData['magic_login']) : '';
        $postedData['demo_site_admin']      = isset($postedData['site_admin']) ? sanitize_user($postedData['site_admin']) : '';
        $postedData['demo_site_password']   = isset($postedData['site_password']) ? sanitize_key($postedData['site_password']) : '';
        $postedData['demo_site_url']        = isset($postedData['site_url']) ? $postedData['site_url'] : '';
        $postedData['demo_site_template']   = isset($postedData['template_slug']) ? sanitize_key($postedData['template_slug']) : '';
        // Create new datetime field from created_date and created_time
        $postedData['demo_site_created']    = isset($postedData['created_date']) ? date('Y-m-d H:i:s', strtotime($postedData['created_date'] . ' ' . $postedData['created_time'])) : '';

        return $postedData;
    }

    /**
     * Correct broken InstaWP URLs
     * Fixes malformed URLs from InstaWP webhook data
     */
    private function correct_broken_url($url) {
        // Define the correct base and path components
        $correct_base = 'https://app.instawp.io';
        $correct_path = '/wordpress-auto-login';
    
        // Check if the URL is broken by matching the incorrect structure
        if (preg_match('#^https://app\.instawp\.io\?site=([^&]+)(/wordpress-auto-login&redir=/)$#', $url, $matches)) {
            // Reconstruct the correct URL
            $correct_url = $correct_base . $correct_path . '?site=' . $matches[1] . '&redir=/';
            return $correct_url;
        }
    
        // If the URL is not broken, return it as is
        return $url;
    }

    /**
     * Example mapping method for another webhook
     * Uncomment and modify as needed for additional webhooks
     * 
     * @param array $postedData The incoming webhook data
     * @param object $webhook The webhook object
     * @param object $request The request object
     * @return array Modified webhook data
     */
    /*
    private function map_another_webhook($postedData, $webhook, $request) {
        // Example: Map different field names
        $postedData['custom_field_1'] = isset($postedData['source_field_1']) ? sanitize_text_field($postedData['source_field_1']) : '';
        $postedData['custom_field_2'] = isset($postedData['source_field_2']) ? sanitize_email($postedData['source_field_2']) : '';
        
        // Example: Transform data
        if (isset($postedData['status'])) {
            $postedData['mapped_status'] = $this->transform_status($postedData['status']);
        }
        
        return $postedData;
    }
    
    private function transform_status($status) {
        $status_map = [
            'active' => 'subscribed',
            'inactive' => 'unsubscribed',
            'pending' => 'pending'
        ];
        
        return isset($status_map[$status]) ? $status_map[$status] : $status;
    }
    */
}
