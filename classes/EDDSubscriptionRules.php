<?php
/**
 * Description: FluentCRM - EDD Subscription Rules
 * Author: Code Atlantic LLC
 * Author URI: https://code-atlantic.com/
 *
 * @package FluentCRM\CustomFeatures
 */

namespace CustomCRM;

use FluentCampaign\App\Services\Commerce\Commerce;

/**
 * Class EDDSubscriptionRules
 *
 * Adds EDD subscription-based conditional rules to FluentCRM's filtering system.
 *
 * @package CustomCRM
 */
class EDDSubscriptionRules {

	/**
	 * Register hooks and filters.
	 *
	 * @return void
	 */
	public function register() {
		// Add custom filter group (not added to existing 'edd' group).
		add_filter( 'fluentcrm_advanced_filter_options', [ $this, 'addCustomFilterGroup' ], 11, 1 );
		add_filter( 'fluentcrm_contacts_filter_edd_subscriptions', [ $this, 'applySubscriptionFilters' ], 10, 2 );

		// Add custom rule types for automation event tracking conditions.
		add_filter( 'fluent_crm/event_tracking_condition_groups', [ $this, 'addCustomFilterGroup' ], 11, 1 );

		// Apply conditional subscription rules in automations.
		add_filter( 'fluentcrm_automation_conditions_assess_edd_subscriptions', [ $this, 'assess_subscription_condition' ], 10, 3 );

		// Register AJAX endpoint for product selector.
		add_filter( 'fluentcrm_ajax_options_product_selector_edd_subscriptions', [ $this, 'get_product_selector_options' ], 10, 3 );
	}


	/**
	 * Add custom filter group for EDD subscriptions.
	 *
	 * Creates a separate filter group instead of adding to existing 'edd' group
	 * to avoid FluentCRM's built-in EDD relationship table constraints.
	 *
	 * @param array<string,mixed> $groups Groups.
	 *
	 * @return array<string,mixed>
	 */
	public function addCustomFilterGroup( $groups ) {
		// Add our own custom group for subscription filtering.
		$groups['edd_subscriptions'] = [
			'label'    => __( 'EDD Subscriptions', 'fluent-crm-custom-features' ),
			'value'    => 'edd_subscriptions',
			'children' => $this->getConditionItems(),
		];

		return $groups;
	}

	/**
	 * Get subscription filter items.
	 *
	 * Creates a separate filter for each subscription status.
	 *
	 * @return array<int<0,max>,array<string,mixed>>
	 */
	public function getConditionItems() {
		$statuses = [
			'any'       => __( 'Any Status', 'fluent-crm-custom-features' ),
			'active'    => __( 'Active', 'fluent-crm-custom-features' ),
			'expired'   => __( 'Expired', 'fluent-crm-custom-features' ),
			'cancelled' => __( 'Cancelled', 'fluent-crm-custom-features' ),
			'pending'   => __( 'Pending', 'fluent-crm-custom-features' ),
			'failing'   => __( 'Failing', 'fluent-crm-custom-features' ),
			'completed' => __( 'Completed', 'fluent-crm-custom-features' ),
		];

		$items = [];

		foreach ( $statuses as $status => $label ) {
			$items[] = [
				'value'            => 'has_subscription_' . $status,
				/* translators: %s: Subscription status label */
				'label'            => sprintf( __( 'Has %s Subscription', 'fluent-crm-custom-features' ), $label ),
				'type'             => 'selections',
				'component'        => 'product_selector',
				'option_key'       => 'product_selector_edd_subscriptions',
				'is_multiple'      => true,
				'disabled'         => ! Commerce::isEnabled( 'edd' ) || ! defined( 'EDD_RECURRING_VERSION' ),
				/* translators: %s: Subscription status label in lowercase */
				'help'             => sprintf( __( 'Filter contacts who have a %s subscription for selected products', 'fluent-crm-custom-features' ), strtolower( $label ) ),
				'custom_operators' => [
					/* translators: %s: Subscription status label */
					'in'     => sprintf( __( 'Has %s', 'fluent-crm-custom-features' ), $label ),
					/* translators: %s: Subscription status label */
					'not_in' => sprintf( __( 'Does Not Have %s', 'fluent-crm-custom-features' ), $label ),
				],
			];
		}

		// Add special "expiring soon" filters with fixed timeframes.
		$expiring_timeframes = [
			7  => __( '7 Days', 'fluent-crm-custom-features' ),
			14 => __( '14 Days', 'fluent-crm-custom-features' ),
			30 => __( '30 Days', 'fluent-crm-custom-features' ),
			60 => __( '60 Days', 'fluent-crm-custom-features' ),
		];

		foreach ( $expiring_timeframes as $days => $label ) {
			$items[] = [
				'value'            => 'subscription_expiring_' . $days . 'd',
				/* translators: %s: Time period label (e.g., "7 Days", "14 Days") */
				'label'            => sprintf( __( 'Subscription Expiring in %s', 'fluent-crm-custom-features' ), $label ),
				'type'             => 'selections',
				'component'        => 'product_selector',
				'option_key'       => 'product_selector_edd_subscriptions',
				'is_multiple'      => true,
				'disabled'         => ! Commerce::isEnabled( 'edd' ) || ! defined( 'EDD_RECURRING_VERSION' ),
				/* translators: %s: Time period label in lowercase */
				'help'             => sprintf( __( 'Filter contacts whose active subscription will expire within %s', 'fluent-crm-custom-features' ), strtolower( $label ) ),
				'custom_operators' => [
					/* translators: %s: Time period label */
					'in'     => sprintf( __( 'Expiring in %s', 'fluent-crm-custom-features' ), $label ),
					/* translators: %s: Time period label */
					'not_in' => sprintf( __( 'Not Expiring in %s', 'fluent-crm-custom-features' ), $label ),
				],
			];
		}

		return $items;
	}

	/**
	 * Apply subscription filter to the query.
	 *
	 * This is called via 'fluentcrm_contacts_filter_edd_subscriptions' filter
	 * when our custom filter group is used.
	 *
	 * @param \FluentCrm\Framework\Database\Query\Builder $query Query builder instance.
	 * @param array                                       $filters Array of filter conditions.
	 * @return \FluentCrm\Framework\Database\Query\Builder Modified query builder instance.
	 */
	public function applySubscriptionFilters( $query, $filters ) {
		global $wpdb;

		foreach ( $filters as $filter ) {
			$property = $filter['property'] ?? '';

			if ( ! $property ) {
				continue;
			}

			if ( ! defined( 'EDD_RECURRING_VERSION' ) ) {
				continue;
			}

			// Handle "expiring soon" filters separately (e.g., subscription_expiring_7d, subscription_expiring_30d).
			if ( strpos( $property, 'subscription_expiring_' ) === 0 ) {
				$this->apply_expiring_filter( $query, $filter, $property );
				continue;
			}

			// Check if this is a subscription status filter.
			if ( strpos( $property, 'has_subscription_' ) !== 0 ) {
				continue;
			}

			// Extract status from property name (e.g., 'has_subscription_active' → 'active').
			$status = str_replace( 'has_subscription_', '', $property );

			$product_values = (array) $filter['value'];
			$operator       = $filter['operator'] ?? 'in';

			if ( empty( $product_values ) ) {
				continue;
			}

			// Determine statuses to filter - 'any' means all statuses (no filter).
			if ( 'any' === $status ) {
				$statuses = []; // Empty array = no status filter.
			} else {
				$statuses = [ $status ];
			}

			// Parse product values - may be "download_id" or "download_id:price_id" format.
			$conditions = [];
			foreach ( $product_values as $value ) {
				if ( strpos( $value, ':' ) !== false ) {
					// Variable pricing: "download_id:price_id".
					list( $download_id, $price_id ) = explode( ':', $value, 2 );
					$conditions[]                   = [
						'download_id' => (int) $download_id,
						'price_id'    => (int) $price_id,
					];
				} else {
					// Single price product: just download_id.
					$conditions[] = [
						'download_id' => (int) $value,
						'price_id'    => null,
					];
				}
			}

			// Build WHERE conditions for product/price combinations.
			$where_conditions = [];
			$prepare_values   = [];

			foreach ( $conditions as $condition ) {
				if ( null === $condition['price_id'] ) {
					// No price_id specified - match ALL price variants for this product.
					$where_conditions[] = '(sub.product_id = %d)';
					$prepare_values[]   = $condition['download_id'];
				} else {
					// Specific price variant - match on both product_id AND price_id.
					$where_conditions[] = '(sub.product_id = %d AND sub.price_id = %d)';
					$prepare_values[]   = $condition['download_id'];
					$prepare_values[]   = $condition['price_id'];
				}
			}

			$where_clause = '(' . implode( ' OR ', $where_conditions ) . ')';

			// Build status filter if statuses are specified.
			$status_filter = '';
			if ( ! empty( $statuses ) ) {
				$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
				$prepare_values      = array_merge( $prepare_values, $statuses );
				$status_filter       = "AND sub.status IN ($status_placeholders)";
			}

			// PERFORMANCE OPTIMIZATION: Query EDD tables first (4k subs), then filter subscribers.
			// This is 70x faster than correlated subquery approach (2 seconds vs 142 seconds).
			// NOTE: Don't use DISTINCT here - multiple customers may share same user_id/email.
			// We'll deduplicate when building the whereIn arrays.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$matching_sql = $wpdb->prepare(
				"SELECT cust.user_id, cust.email 
				FROM {$wpdb->prefix}edd_subscriptions AS sub 
				INNER JOIN {$wpdb->prefix}edd_customers AS cust 
					ON cust.id = sub.customer_id 
				WHERE $where_clause
				$status_filter",
				$prepare_values
			);
			// phpcs:enable

			$results = $wpdb->get_results( $matching_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			// Extract user_ids and emails.
			$user_ids = [];
			$emails   = [];

			foreach ( $results as $row ) {
				if ( ! empty( $row->user_id ) && $row->user_id > 0 ) {
					$user_ids[] = (int) $row->user_id;
				}
				if ( ! empty( $row->email ) ) {
					$emails[] = $row->email;
				}
			}

			// Deduplicate arrays.
			$user_ids = array_values( array_unique( $user_ids ) );
			$emails   = array_values( array_unique( $emails ) );

			if ( 'in' === $operator ) {
				// Has subscription with specified status(es).
				if ( ! empty( $user_ids ) || ! empty( $emails ) ) {
					$query->where(
						function ( $q ) use ( $user_ids, $emails ) {
							if ( ! empty( $user_ids ) ) {
								$q->whereIn( 'user_id', $user_ids );
							}
							if ( ! empty( $emails ) ) {
								$q->orWhereIn( 'email', $emails );
							}
						}
					);
				} else {
					// No matches - return empty result.
					$query->where( 'id', '=', 0 );
				}
			} elseif ( 'not_in' === $operator ) {
				// Does NOT have subscription with specified status(es).
				if ( ! empty( $user_ids ) || ! empty( $emails ) ) {
					$query->where(
						function ( $q ) use ( $user_ids, $emails ) {
							if ( ! empty( $user_ids ) ) {
								$q->whereNotIn( 'user_id', $user_ids );
							}
							if ( ! empty( $emails ) ) {
								$q->whereNotIn( 'email', $emails );
							}
						}
					);
				}
				// If no matches found, all subscribers match "does not have".
			}
		}

		return $query;
	}

	/**
	 * Apply "expiring soon" filter to the query.
	 *
	 * Filters for active subscriptions that will expire within the specified number of days.
	 *
	 * @param \FluentCrm\Framework\Database\Query\Builder $query Query builder instance.
	 * @param array                                       $filter Filter configuration.
	 * @param string                                      $property Property name (e.g., subscription_expiring_30d).
	 * @return void
	 */
	protected function apply_expiring_filter( $query, $filter, $property ) {
		global $wpdb;

		// Extract days from property name (e.g., 'subscription_expiring_30d' → 30).
		preg_match( '/subscription_expiring_(\d+)d/', $property, $matches );
		$days = isset( $matches[1] ) ? (int) $matches[1] : 30;

		$product_values = (array) $filter['value'];
		$operator       = $filter['operator'] ?? 'in';

		if ( empty( $product_values ) ) {
			return;
		}

		// Parse product values - may be "download_id" or "download_id:price_id" format.
		$conditions = [];
		foreach ( $product_values as $value ) {
			if ( strpos( $value, ':' ) !== false ) {
				list( $download_id, $price_id ) = explode( ':', $value, 2 );
				$conditions[]                   = [
					'download_id' => (int) $download_id,
					'price_id'    => (int) $price_id,
				];
			} else {
				$conditions[] = [
					'download_id' => (int) $value,
					'price_id'    => null,
				];
			}
		}

		// Build WHERE conditions for product/price combinations.
		$where_conditions = [];
		$prepare_values   = [];

		foreach ( $conditions as $condition ) {
			if ( null === $condition['price_id'] ) {
				$where_conditions[] = '(sub.product_id = %d)';
				$prepare_values[]   = $condition['download_id'];
			} else {
				$where_conditions[] = '(sub.product_id = %d AND sub.price_id = %d)';
				$prepare_values[]   = $condition['download_id'];
				$prepare_values[]   = $condition['price_id'];
			}
		}

		$where_clause = '(' . implode( ' OR ', $where_conditions ) . ')';

		// Calculate date range: now to now + days.
		$now              = current_time( 'mysql' );
		$future           = gmdate( 'Y-m-d 23:59:59', strtotime( "+{$days} days" ) + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		$prepare_values[] = $now;
		$prepare_values[] = $future;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$matching_sql = $wpdb->prepare(
			"SELECT cust.user_id, cust.email
			FROM {$wpdb->prefix}edd_subscriptions AS sub
			INNER JOIN {$wpdb->prefix}edd_customers AS cust ON cust.id = sub.customer_id
			WHERE $where_clause
			AND sub.status = 'active'
			AND sub.expiration >= %s
			AND sub.expiration <= %s",
			$prepare_values
		);
		// phpcs:enable

		$results = $wpdb->get_results( $matching_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Extract and deduplicate user_ids and emails.
		$user_ids = [];
		$emails   = [];

		foreach ( $results as $row ) {
			if ( ! empty( $row->user_id ) && $row->user_id > 0 ) {
				$user_ids[] = (int) $row->user_id;
			}
			if ( ! empty( $row->email ) ) {
				$emails[] = $row->email;
			}
		}

		$user_ids = array_values( array_unique( $user_ids ) );
		$emails   = array_values( array_unique( $emails ) );

		if ( 'in' === $operator ) {
			if ( ! empty( $user_ids ) || ! empty( $emails ) ) {
				$query->where(
					function ( $q ) use ( $user_ids, $emails ) {
						if ( ! empty( $user_ids ) ) {
							$q->whereIn( 'user_id', $user_ids );
						}
						if ( ! empty( $emails ) ) {
							$q->orWhereIn( 'email', $emails );
						}
					}
				);
			} else {
				$query->where( 'id', '=', 0 );
			}
		} elseif ( ! empty( $user_ids ) || ! empty( $emails ) ) {
				$query->where(
					function ( $q ) use ( $user_ids, $emails ) {
						if ( ! empty( $user_ids ) ) {
							$q->whereNotIn( 'user_id', $user_ids );
						}
						if ( ! empty( $emails ) ) {
							$q->whereNotIn( 'email', $emails );
						}
					}
				);
		}
	}

	/**
	 * Assess subscription condition.
	 *
	 * @param bool                             $result Previous result.
	 * @param array                            $conditions Condition data.
	 * @param \FluentCrm\App\Models\Subscriber $subscriber Subscriber instance.
	 * @return bool Whether the condition is met.
	 */
	public function assess_subscription_condition( $result, $conditions, $subscriber ) {
		foreach ( $conditions as $condition ) {
			$data_key = $condition['data_key'] ?? '';

			// Check if this is a subscription filter using pattern matching.
			if ( empty( $data_key ) || strpos( $data_key, 'has_subscription_' ) !== 0 ) {
				continue;
			}

			$operator = $condition['operator'];

			if ( ! defined( 'EDD_RECURRING_VERSION' ) ) {
				return $result;
			}

			// Extract status from property name (e.g., 'has_subscription_active' → 'active').
			$status = str_replace( 'has_subscription_', '', $data_key );

			// Products are in 'data_value', status extracted from filter name.
			$product_ids = (array) $condition['data_value'];
			$statuses    = [ $status ];

			if ( empty( $product_ids ) ) {
				return $result;
			}

			$has_subscription = $this->has_subscription_with_status( $subscriber, $product_ids, $statuses );

			if ( ( 'in' === $operator && ! $has_subscription ) || ( 'not_in' === $operator && $has_subscription ) ) {
				return false;
			}
		}

		return $result;
	}

	/**
	 * Check if subscriber has subscription with specified status(es).
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber Subscriber instance.
	 * @param array                            $product_values Product values to check (may be "download_id" or "download_id:price_id").
	 * @param array                            $statuses Subscription statuses to check (default: ['active']).
	 * @return bool Whether subscriber has subscription with specified status.
	 */
	protected function has_subscription_with_status( $subscriber, $product_values, $statuses = [ 'active' ] ) {
		global $wpdb;

		$user_id = $subscriber->user_id;
		$email   = $subscriber->email;

		if ( ! $user_id && ! $email ) {
			return false;
		}

		$customer = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'edd_customers WHERE user_id = %d OR email = %s LIMIT 1',
				$user_id,
				$email
			)
		);

		if ( ! $customer ) {
			return false;
		}

		// Parse product values - may be "download_id" or "download_id:price_id" format.
		$conditions = [];
		foreach ( $product_values as $value ) {
			if ( strpos( $value, ':' ) !== false ) {
				// Variable pricing: "download_id:price_id".
				list( $download_id, $price_id ) = explode( ':', $value, 2 );
				$conditions[]                   = [
					'download_id' => (int) $download_id,
					'price_id'    => (int) $price_id,
				];
			} else {
				// Single price product: just download_id.
				$conditions[] = [
					'download_id' => (int) $value,
					'price_id'    => null,
				];
			}
		}

		// Build WHERE conditions for product/price combinations.
		$where_conditions = [];
		$prepare_values   = [ $customer->id ];

		foreach ( $conditions as $condition ) {
			if ( null === $condition['price_id'] ) {
				// No price_id specified - match ALL price variants for this product.
				$where_conditions[] = '(product_id = %d)';
				$prepare_values[]   = $condition['download_id'];
			} else {
				// Specific price variant - match on both product_id AND price_id.
				$where_conditions[] = '(product_id = %d AND price_id = %d)';
				$prepare_values[]   = $condition['download_id'];
				$prepare_values[]   = $condition['price_id'];
			}
		}

		$where_clause = '(' . implode( ' OR ', $where_conditions ) . ')';

		// Build status filter if statuses are specified.
		$status_filter = '';
		if ( ! empty( $statuses ) ) {
			$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$prepare_values      = array_merge( $prepare_values, $statuses );
			$status_filter       = 'AND status IN (' . $status_placeholders . ')';
		}

		$subscription = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT id FROM ' . $wpdb->prefix . 'edd_subscriptions WHERE customer_id = %d AND ' . $where_clause . ' ' . $status_filter . ' LIMIT 1',
				$prepare_values
			)
		);

		return (bool) $subscription;
	}

	/**
	 * Get product selector options for AJAX dropdown.
	 *
	 * Returns EDD products with variable pricing in format: download_id:price_id
	 *
	 * @param array  $options      Existing options array (unused, required by filter signature).
	 * @param string $search      Search term for filtering products.
	 * @param array  $included_ids IDs to include (unused, required by filter signature).
	 * @return array Product options for dropdown.
	 */
	public function get_product_selector_options( $options, $search = '', $included_ids = [] ) {

		$download_ids = [];

		// Query 1: Search results (up to 50).
		$args = [
			'post_type'      => 'download',
			'fields'         => 'ids',
			'posts_per_page' => 50,
			'post_status'    => 'publish',
			'no_found_rows'  => true,
		];

		if ( ! empty( $search ) ) {
			$args['s'] = sanitize_text_field( $search );
			// When searching, use WordPress relevance ordering (default).
		} else {
			// When NOT searching, sort alphabetically.
			$args['orderby'] = 'title';
			$args['order']   = 'ASC';
		}

		$search_results = get_posts( $args );
		$download_ids   = array_merge( $download_ids, $search_results );

		// Query 2: IncludedIds (ensure pre-selected items are always included).
		if ( ! empty( $included_ids ) ) {
			$included_ids = array_map( 'intval', (array) $included_ids );
			$download_ids = array_merge( $download_ids, $included_ids );
		}

		// Deduplicate and preserve order.
		$download_ids = array_values( array_unique( $download_ids ) );
		$result       = [];

		foreach ( $download_ids as $download_id ) {
			$download = new \EDD_Download( $download_id );

			// Skip if download doesn't exist.
			if ( ! $download->ID ) {
				continue;
			}

			$has_variable_pricing = edd_has_variable_prices( $download->ID );

			if ( $has_variable_pricing ) {
				// Get all price variations.
				$prices = edd_get_variable_prices( $download->ID );

				if ( ! empty( $prices ) ) {
					foreach ( $prices as $price_id => $price ) {
						$price_name = ! empty( $price['name'] ) ? $price['name'] : sprintf( 'Price Option %d', $price_id );
						$result[]   = [
							'id'    => $download->ID . ':' . $price_id,
							'title' => $download->post_title . ' - ' . $price_name,
						];
					}
				}
			} else {
				// Single price product.
				$result[] = [
					'id'    => (string) $download->ID,
					'title' => $download->post_title,
				];
			}
		}

		return $result;
	}
}
