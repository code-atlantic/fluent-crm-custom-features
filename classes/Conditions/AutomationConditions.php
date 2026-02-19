<?php

namespace CustomCRM\Conditions;

use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

/**
 * Registers custom automation condition groups for the funnel condition block.
 *
 * Adds:
 * - Event Tracking conditions (check if contact has performed a specific event)
 * - Automation Completion conditions (check if contact has completed a specific funnel)
 */
class AutomationConditions {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// Add condition groups to the funnel condition block UI.
		add_filter( 'fluentcrm_automation_condition_groups', [ $this, 'addConditionGroups' ], 10, 2 );

		// Handle evaluation of our custom "automation completed" condition.
		add_filter( 'fluentcrm_automation_conditions_assess_automations', [ $this, 'assessAutomationConditions' ], 10, 5 );
	}

	/**
	 * Add Event Tracking and Automation Completion condition groups to the funnel condition block.
	 *
	 * @param array<string,mixed> $groups Existing condition groups.
	 * @param mixed               $funnel The current funnel.
	 *
	 * @return array<string,mixed>
	 */
	public function addConditionGroups( array $groups, $funnel ): array {
		// Add Event Tracking group (evaluation handled by FunnelConditionHelper::assessEventTrackingConditions).
		if ( Helper::isExperimentalEnabled( 'event_tracking' ) ) {
			$groups['event_tracking'] = [
				'label'    => __( 'Event Tracking', 'fluent-crm-custom-features' ),
				'value'    => 'event_tracking',
				'children' => $this->getEventTrackingChildren(),
			];
		}

		// Add Automation Completion group.
		$groups['automations'] = [
			'label'    => __( 'Automations', 'fluent-crm-custom-features' ),
			'value'    => 'automations',
			'children' => $this->getAutomationChildren(),
		];

		return $groups;
	}

	/**
	 * Get event tracking condition options.
	 *
	 * Provides a list of tracked event keys that can be used as conditions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function getEventTrackingChildren(): array {
		$events = fluentCrmDb()->table( 'fc_event_tracking' )
			->select( 'event_key', 'title' )
			->groupBy( 'event_key' )
			->get();

		$children = [];
		foreach ( $events as $event ) {
			$children[] = [
				'label'             => $event->title ?: $event->event_key,
				'value'             => $event->event_key,
				'type'              => 'selections',
				'options'           => [
					'yes' => __( 'Yes - Has performed', 'fluent-crm-custom-features' ),
					'no'  => __( 'No - Has not performed', 'fluent-crm-custom-features' ),
				],
				'is_multiple'       => false,
				'is_singular_value' => true,
			];
		}

		return $children;
	}

	/**
	 * Get automation completion condition options.
	 *
	 * Provides a list of funnels that can be checked for completion.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function getAutomationChildren(): array {
		$funnels = fluentCrmDb()->table( 'fc_funnels' )
			->select( 'id', 'title' )
			->orderBy( 'title', 'ASC' )
			->get();

		$children = [];
		foreach ( $funnels as $funnel_item ) {
			$children[] = [
				'label'             => $funnel_item->title,
				'value'             => 'funnel_completed_' . $funnel_item->id,
				'type'              => 'selections',
				'options'           => [
					'yes' => __( 'Yes - Has completed', 'fluent-crm-custom-features' ),
					'no'  => __( 'No - Has not completed', 'fluent-crm-custom-features' ),
				],
				'is_multiple'       => false,
				'is_singular_value' => true,
			];
		}

		return $children;
	}

	/**
	 * Assess automation completion conditions.
	 *
	 * @param bool   $result              Current result.
	 * @param array  $conditions           Condition rules to evaluate.
	 * @param object $subscriber           The subscriber being evaluated.
	 * @param object $sequence             The current funnel sequence.
	 * @param int    $funnelSubscriberId   The funnel subscriber ID.
	 *
	 * @return bool
	 */
	public function assessAutomationConditions( $result, $conditions, $subscriber, $sequence, $funnelSubscriberId ): bool {
		foreach ( $conditions as $condition ) {
			$prop     = $condition['data_key'];
			$operator = $condition['operator'] ?? '=';
			$value    = $condition['data_value'] ?? 'yes';

			// Extract funnel ID from the property name (funnel_completed_{id}).
			if ( strpos( $prop, 'funnel_completed_' ) !== 0 ) {
				continue;
			}

			$funnel_id = (int) str_replace( 'funnel_completed_', '', $prop );
			if ( ! $funnel_id ) {
				continue;
			}

			$has_completed = FunnelSubscriber::where( 'subscriber_id', $subscriber->id )
				->where( 'funnel_id', $funnel_id )
				->where( 'status', 'completed' )
				->exists();

			$expects_completed = ( $value === 'yes' );

			if ( $operator === '=' || $operator === 'in' ) {
				if ( $has_completed !== $expects_completed ) {
					return false;
				}
			} elseif ( $operator === '!=' || $operator === 'not_in' ) {
				if ( $has_completed === $expects_completed ) {
					return false;
				}
			} else {
				// Unknown operator — fail safe.
				return false;
			}
		}

		return $result;
	}
}
