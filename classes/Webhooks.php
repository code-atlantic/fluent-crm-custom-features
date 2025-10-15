<?php
/**
 * Fix SmartLink redirects.
 *
 * @package CustomCRM
 */

namespace CustomCRM;

/**
 * Webhooks class handles incoming webhook data processing and routing.
 */
class Webhooks {

	/**
	 * Webhook mapping configuration
	 * Maps webhook IDs to their respective mapping methods.
	 *
	 * @var array<int, string>
	 */
	private $webhook_mappings = [
		61  => 'map_instawp_demo_webhook', // InstaWP Demo Site webhook.
		175 => 'map_instawp_demo_webhook', // InstaWP Pro Demo Site Webhook.
		176 => 'map_instawp_demo_webhook', // InstaWP Ecommerce Demo Site Webhook.
		// Add more webhook mappings here as needed.
		// 62 => 'map_another_webhook',.
		// 63 => 'map_third_webhook',.
	];

	/**
	 * Constructor - hooks into FluentCRM webhook filter.
	 */
	public function __construct() {
		add_filter( 'fluent_crm/incoming_webhook_data', [ $this, 'incoming_webhook_data' ], 10, 3 );
	}

	/**
	 * Main webhook data processing method
	 * Routes webhook data to appropriate mapping method based on webhook ID.
	 *
	 * @param array  $posted_data The incoming webhook data.
	 * @param object $webhook     The webhook object.
	 * @param object $request     The request object.
	 * @return array Modified webhook data.
	 */
	public function incoming_webhook_data( $posted_data, $webhook, $request ) {
		// Check if we have a mapping method for this webhook.
		if ( ! isset( $this->webhook_mappings[ $webhook->id ] ) ) {
			return $posted_data;
		}

		$mapping_method = $this->webhook_mappings[ $webhook->id ];

		// Call the specific mapping method if it exists.
		if ( method_exists( $this, $mapping_method ) ) {
			return $this->$mapping_method( $posted_data, $webhook, $request );
		}

		return $posted_data;
	}

	/**
	 * Map InstaWP Demo Site webhook data (webhook ID: 61)
	 * Remaps the data to match the custom contact fields for demo sites.
	 *
	 * @param array  $posted_data The incoming webhook data.
	 * @param object $webhook     The webhook object.
	 * @param object $request     The request object.
	 * @return array Modified webhook data.
	 */
	private function map_instawp_demo_webhook( $posted_data, $webhook, $request ) {
		// Remap the data to match the custom contact fields.
		$posted_data['demo_magic_login_url'] = isset( $posted_data['magic_login'] ) ? $this->correct_broken_url( $posted_data['magic_login'] ) : '';
		$posted_data['demo_site_admin']      = isset( $posted_data['site_admin'] ) ? sanitize_user( $posted_data['site_admin'] ) : '';
		$posted_data['demo_site_password']   = isset( $posted_data['site_password'] ) ? sanitize_key( $posted_data['site_password'] ) : '';
		$posted_data['demo_site_url']        = isset( $posted_data['site_url'] ) ? $posted_data['site_url'] : '';
		$posted_data['demo_site_template']   = isset( $posted_data['template_slug'] ) ? sanitize_key( $posted_data['template_slug'] ) : '';
		// Create new datetime field from created_date and created_time.
		$posted_data['demo_site_created'] = isset( $posted_data['created_date'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $posted_data['created_date'] . ' ' . $posted_data['created_time'] ) ) : '';

		return $posted_data;
	}

	/**
	 * Correct broken InstaWP URLs
	 * Fixes malformed URLs from InstaWP webhook data.
	 *
	 * @param string $url The URL to correct.
	 * @return string Corrected URL.
	 */
	private function correct_broken_url( $url ) {
		// Define the correct base and path components.
		$correct_base = 'https://app.instawp.io';
		$correct_path = '/wordpress-auto-login';

		// Check if the URL is broken by matching the incorrect structure.
		if ( preg_match( '#^https://app\.instawp\.io\?site=([^&]+)(/wordpress-auto-login&redir=/)$#', $url, $matches ) ) {
			// Reconstruct the correct URL.
			$correct_url = $correct_base . $correct_path . '?site=' . $matches[1] . '&redir=/';
			return $correct_url;
		}

		// If the URL is not broken, return it as is.
		return $url;
	}

	/**
	 * Example mapping method for another webhook
	 * Uncomment and modify as needed for additional webhooks.
	 *
	 * @param array  $posted_data The incoming webhook data.
	 * @param object $webhook The webhook object.
	 * @param object $request The request object.
	 * @return array Modified webhook data.
	 */
	/*
	private function map_another_webhook( $posted_data, $webhook, $request ) {
		// Example: Map different field names.
		$posted_data['custom_field_1'] = isset( $posted_data['source_field_1'] ) ? sanitize_text_field( $posted_data['source_field_1'] ) : '';
		$posted_data['custom_field_2'] = isset( $posted_data['source_field_2'] ) ? sanitize_email( $posted_data['source_field_2'] ) : '';

		// Example: Transform data.
		if ( isset( $posted_data['status'] ) ) {
			$posted_data['mapped_status'] = $this->transform_status( $posted_data['status'] );
		}

		return $posted_data;
	}

	private function transform_status( $status ) {
		$status_map = [
			'active' => 'subscribed',
			'inactive' => 'unsubscribed',
			'pending' => 'pending'
		];

		return isset( $status_map[ $status ] ) ? $status_map[ $status ] : $status;
	}
	*/
}
