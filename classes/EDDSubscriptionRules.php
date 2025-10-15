<?php
/**
 * Description: FluentCRM - EDD Subscription Rules
 * Author: Code Atlantic LLC
 * Author URI: https://code-atlantic.com/
 *
 * @package FluentCRM\CustomFeatures
 */

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

namespace CustomCRM;

use FluentCrm\Framework\Support\Arr;
use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCrm\App\Models\Subscriber;

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
	 * @return array<int<0,max>,array<string,mixed>>
	 */
	public function getConditionItems() {
		return [
			[
				'value'            => 'has_active_subscription',
				'label'            => __( 'Has Active Subscription', 'fluent-crm-custom-features' ),
				'type'             => 'selections',
				'component'        => 'product_selector',
				'is_multiple'      => true,
				'disabled'         => ! Commerce::isEnabled( 'edd' ) || ! defined( 'EDD_RECURRING_VERSION' ),
				'help'             => __( 'Filter contacts who have an active subscription for selected products', 'fluent-crm-custom-features' ),
				'custom_operators' => [
					'in'     => __( 'Has Active', 'fluent-crm-custom-features' ),
					'not_in' => __( 'Does Not Have Active', 'fluent-crm-custom-features' ),
				],
			],
		];
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

			if ( 'has_active_subscription' === $property && ! defined( 'EDD_RECURRING_VERSION' ) ) {
				continue;
			}

			switch ( $filter['property'] ) {
				case 'has_active_subscription':
					$product_ids = (array) $filter['value'];
					$operator    = $filter['operator'];

					if ( empty( $product_ids ) ) {
						break;
					}

					$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

					if ( 'in' === $operator ) {
						// Has active subscription - query directly against EDD tables.
						$query->whereRaw(
							$wpdb->prepare(
								"EXISTS (
									SELECT 1
									FROM {$wpdb->prefix}edd_subscriptions AS sub
									JOIN {$wpdb->prefix}edd_customers AS cust ON cust.id = sub.customer_id
									WHERE (cust.user_id = {$wpdb->prefix}fc_subscribers.user_id OR cust.email = {$wpdb->prefix}fc_subscribers.email)
									AND sub.product_id IN ($placeholders)
									AND sub.status = %s
								)",
								array_merge( $product_ids, [ 'active' ] )
							)
						);
					} else {
						// Does not have active subscription.
						$query->whereRaw(
							$wpdb->prepare(
								"NOT EXISTS (
									SELECT 1
									FROM {$wpdb->prefix}edd_subscriptions AS sub
									JOIN {$wpdb->prefix}edd_customers AS cust ON cust.id = sub.customer_id
									WHERE (cust.user_id = {$wpdb->prefix}fc_subscribers.user_id OR cust.email = {$wpdb->prefix}fc_subscribers.email)
									AND sub.product_id IN ($placeholders)
									AND sub.status = %s
								)",
								array_merge( $product_ids, [ 'active' ] )
							)
						);
					}
					break;
			}
		}

		return $query;
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
			if ( empty( $condition['data_key'] ) || 'has_active_subscription' !== $condition['data_key'] ) {
				continue;
			}

			$product_ids = (array) $condition['data_value'];
			$operator    = $condition['operator'];

			if ( ! defined( 'EDD_RECURRING_VERSION' ) || empty( $product_ids ) ) {
				return $result;
			}

			$has_subscription = $this->has_active_subscription( $subscriber, $product_ids );

			if ( ( 'in' === $operator && ! $has_subscription ) || ( 'not_in' === $operator && $has_subscription ) ) {
				return false;
			}
		}

		return $result;
	}

	/**
	 * Check if subscriber has active subscription.
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber Subscriber instance.
	 * @param array                            $product_ids Product IDs to check.
	 * @return bool Whether subscriber has active subscription.
	 */
	protected function has_active_subscription( $subscriber, $product_ids ) {
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

		$subscription = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'edd_subscriptions WHERE customer_id = %d AND product_id IN (' . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ') AND status = %s LIMIT 1',
				array_merge( [ $customer->id ], $product_ids, [ 'active' ] )
			)
		);

		return (bool) $subscription;
	}
}
