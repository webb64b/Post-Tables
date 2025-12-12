<?php
/**
 * Automation Triggers - Handles trigger evaluation for automations
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Automation_Triggers {

    /**
     * Data handler instance
     */
    private $data_handler;

    /**
     * Condition evaluator instance
     */
    private $conditions;

    public function __construct($data_handler, $conditions) {
        $this->data_handler = $data_handler;
        $this->conditions = $conditions;
    }

    /**
     * Check if a trigger should fire for a post
     *
     * @param array $trigger Trigger configuration
     * @param int $post_id Post ID
     * @param array $context Additional context
     * @return bool|array False if not triggered, context array if triggered
     */
    public function check_trigger($trigger, $post_id, $context = []) {
        $type = $trigger['type'] ?? '';

        if (empty($type)) {
            return false;
        }

        // First check trigger-specific conditions
        $triggered = false;
        $trigger_context = $context;

        switch ($type) {
            // Post lifecycle triggers
            case 'post_created':
                $triggered = $this->check_post_created($trigger, $post_id, $context);
                break;

            case 'post_updated':
                $triggered = $this->check_post_updated($trigger, $post_id, $context);
                break;

            // Field change triggers
            case 'field_changed':
                $triggered = $this->check_field_changed($trigger, $post_id, $context);
                if ($triggered) {
                    $trigger_context = array_merge($context, $triggered);
                    $triggered = true;
                }
                break;

            case 'field_changed_to':
                $triggered = $this->check_field_changed_to($trigger, $post_id, $context);
                if ($triggered) {
                    $trigger_context = array_merge($context, $triggered);
                    $triggered = true;
                }
                break;

            case 'field_changed_from':
                $triggered = $this->check_field_changed_from($trigger, $post_id, $context);
                if ($triggered) {
                    $trigger_context = array_merge($context, $triggered);
                    $triggered = true;
                }
                break;

            case 'field_transition':
                $triggered = $this->check_field_transition($trigger, $post_id, $context);
                if ($triggered) {
                    $trigger_context = array_merge($context, $triggered);
                    $triggered = true;
                }
                break;

            // Date-based triggers
            case 'date_equals_today':
                $triggered = $this->check_date_equals_today($trigger, $post_id, $context);
                if ($triggered) {
                    $trigger_context = array_merge($context, $triggered);
                    $triggered = true;
                }
                break;

            case 'date_days_before':
                $triggered = $this->check_date_days_before($trigger, $post_id, $context);
                if ($triggered) {
                    $trigger_context = array_merge($context, $triggered);
                    $triggered = true;
                }
                break;

            case 'date_days_after':
                $triggered = $this->check_date_days_after($trigger, $post_id, $context);
                if ($triggered) {
                    $trigger_context = array_merge($context, $triggered);
                    $triggered = true;
                }
                break;

            case 'date_is_overdue':
                $triggered = $this->check_date_is_overdue($trigger, $post_id, $context);
                if ($triggered) {
                    $trigger_context = array_merge($context, $triggered);
                    $triggered = true;
                }
                break;

            case 'date_is_upcoming':
                $triggered = $this->check_date_is_upcoming($trigger, $post_id, $context);
                if ($triggered) {
                    $trigger_context = array_merge($context, $triggered);
                    $triggered = true;
                }
                break;

            // Comparison triggers
            case 'field_matches':
                $triggered = $this->check_field_matches($trigger, $post_id, $context);
                break;

            default:
                $triggered = false;
        }

        if (!$triggered) {
            return false;
        }

        // Check additional conditions if specified
        if (!empty($trigger['conditions'])) {
            $condition_group = [
                'logic' => $trigger['conditions']['logic'] ?? 'AND',
                'rules' => $trigger['conditions']['rules'] ?? $trigger['conditions'],
            ];

            if (!$this->conditions->evaluate($condition_group, $post_id, $trigger_context)) {
                return false;
            }
        }

        return $trigger_context;
    }

    /**
     * Check post_created trigger
     */
    private function check_post_created($trigger, $post_id, $context) {
        // This trigger should only fire from the post creation hook
        return isset($context['is_new_post']) && $context['is_new_post'] === true;
    }

    /**
     * Check post_updated trigger
     */
    private function check_post_updated($trigger, $post_id, $context) {
        // This trigger fires from the post update hook
        return isset($context['is_update']) && $context['is_update'] === true;
    }

    /**
     * Check field_changed trigger
     */
    private function check_field_changed($trigger, $post_id, $context) {
        $field = $trigger['field'] ?? '';

        if (empty($field)) {
            return false;
        }

        // Check if this field was changed
        if (!isset($context['changed_field']) || $context['changed_field'] !== $field) {
            return false;
        }

        if (!isset($context['old_value']) || !isset($context['new_value'])) {
            return false;
        }

        // Verify values are actually different
        if ($context['old_value'] === $context['new_value']) {
            return false;
        }

        return [
            'changed_field' => $field,
            'old_value' => $context['old_value'],
            'new_value' => $context['new_value'],
        ];
    }

    /**
     * Check field_changed_to trigger
     */
    private function check_field_changed_to($trigger, $post_id, $context) {
        $field = $trigger['field'] ?? '';
        $target_value = $trigger['value'] ?? $trigger['to_value'] ?? '';

        if (empty($field)) {
            return false;
        }

        // Check if this field was changed
        if (!isset($context['changed_field']) || $context['changed_field'] !== $field) {
            return false;
        }

        if (!isset($context['new_value'])) {
            return false;
        }

        // Check if the new value matches the target
        if (!$this->conditions->compare($context['new_value'], 'equals', $target_value)) {
            return false;
        }

        return [
            'changed_field' => $field,
            'old_value' => $context['old_value'] ?? null,
            'new_value' => $context['new_value'],
            'target_value' => $target_value,
        ];
    }

    /**
     * Check field_changed_from trigger
     */
    private function check_field_changed_from($trigger, $post_id, $context) {
        $field = $trigger['field'] ?? '';
        $from_value = $trigger['value'] ?? $trigger['from_value'] ?? '';

        if (empty($field)) {
            return false;
        }

        // Check if this field was changed
        if (!isset($context['changed_field']) || $context['changed_field'] !== $field) {
            return false;
        }

        if (!isset($context['old_value'])) {
            return false;
        }

        // Check if the old value matches
        if (!$this->conditions->compare($context['old_value'], 'equals', $from_value)) {
            return false;
        }

        return [
            'changed_field' => $field,
            'old_value' => $context['old_value'],
            'new_value' => $context['new_value'] ?? null,
            'from_value' => $from_value,
        ];
    }

    /**
     * Check field_transition trigger (specific from -> to)
     */
    private function check_field_transition($trigger, $post_id, $context) {
        $field = $trigger['field'] ?? '';
        $from_value = $trigger['from_value'] ?? '';
        $to_value = $trigger['to_value'] ?? '';

        if (empty($field)) {
            return false;
        }

        // Check if this field was changed
        if (!isset($context['changed_field']) || $context['changed_field'] !== $field) {
            return false;
        }

        if (!isset($context['old_value']) || !isset($context['new_value'])) {
            return false;
        }

        // Check both from and to values
        $from_matches = $this->conditions->compare($context['old_value'], 'equals', $from_value);
        $to_matches = $this->conditions->compare($context['new_value'], 'equals', $to_value);

        if (!$from_matches || !$to_matches) {
            return false;
        }

        return [
            'changed_field' => $field,
            'old_value' => $context['old_value'],
            'new_value' => $context['new_value'],
            'from_value' => $from_value,
            'to_value' => $to_value,
        ];
    }

    /**
     * Check date_equals_today trigger
     */
    private function check_date_equals_today($trigger, $post_id, $context) {
        $field = $trigger['field'] ?? '';

        if (empty($field)) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $date_value = $this->data_handler->get_field_value($post, $field, 'auto');
        $date_timestamp = $this->to_timestamp($date_value);

        if ($date_timestamp === false) {
            return false;
        }

        $today = strtotime('today midnight');
        $tomorrow = strtotime('tomorrow midnight');

        if ($date_timestamp >= $today && $date_timestamp < $tomorrow) {
            return [
                'trigger_date' => date('Y-m-d', $date_timestamp),
                'trigger_field' => $field,
                'days_until' => 0,
                'days_since' => 0,
            ];
        }

        return false;
    }

    /**
     * Check date_days_before trigger
     */
    private function check_date_days_before($trigger, $post_id, $context) {
        $field = $trigger['field'] ?? '';
        $days = intval($trigger['days'] ?? $trigger['value'] ?? 0);

        if (empty($field) || $days < 0) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $date_value = $this->data_handler->get_field_value($post, $field, 'auto');
        $date_timestamp = $this->to_timestamp($date_value);

        if ($date_timestamp === false) {
            return false;
        }

        // Calculate the trigger date (X days before the field date)
        $target_date = strtotime("-{$days} days", $date_timestamp);
        $today = strtotime('today midnight');
        $tomorrow = strtotime('tomorrow midnight');

        if ($target_date >= $today && $target_date < $tomorrow) {
            return [
                'trigger_date' => date('Y-m-d', $date_timestamp),
                'trigger_field' => $field,
                'days_until' => $days,
                'days_before' => $days,
            ];
        }

        return false;
    }

    /**
     * Check date_days_after trigger
     */
    private function check_date_days_after($trigger, $post_id, $context) {
        $field = $trigger['field'] ?? '';
        $days = intval($trigger['days'] ?? $trigger['value'] ?? 0);

        if (empty($field) || $days < 0) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $date_value = $this->data_handler->get_field_value($post, $field, 'auto');
        $date_timestamp = $this->to_timestamp($date_value);

        if ($date_timestamp === false) {
            return false;
        }

        // Calculate the trigger date (X days after the field date)
        $target_date = strtotime("+{$days} days", $date_timestamp);
        $today = strtotime('today midnight');
        $tomorrow = strtotime('tomorrow midnight');

        if ($target_date >= $today && $target_date < $tomorrow) {
            return [
                'trigger_date' => date('Y-m-d', $date_timestamp),
                'trigger_field' => $field,
                'days_since' => $days,
                'days_after' => $days,
            ];
        }

        return false;
    }

    /**
     * Check date_is_overdue trigger
     */
    private function check_date_is_overdue($trigger, $post_id, $context) {
        $field = $trigger['field'] ?? '';

        if (empty($field)) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $date_value = $this->data_handler->get_field_value($post, $field, 'auto');
        $date_timestamp = $this->to_timestamp($date_value);

        if ($date_timestamp === false) {
            return false;
        }

        $today = strtotime('today midnight');

        if ($date_timestamp < $today) {
            $days_overdue = floor(($today - $date_timestamp) / 86400);

            return [
                'trigger_date' => date('Y-m-d', $date_timestamp),
                'trigger_field' => $field,
                'days_since' => $days_overdue,
                'days_overdue' => $days_overdue,
                'is_overdue' => true,
            ];
        }

        return false;
    }

    /**
     * Check date_is_upcoming trigger
     */
    private function check_date_is_upcoming($trigger, $post_id, $context) {
        $field = $trigger['field'] ?? '';
        $days = intval($trigger['days'] ?? $trigger['value'] ?? 7);

        if (empty($field) || $days < 0) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $date_value = $this->data_handler->get_field_value($post, $field, 'auto');
        $date_timestamp = $this->to_timestamp($date_value);

        if ($date_timestamp === false) {
            return false;
        }

        $today = strtotime('today midnight');
        $future = strtotime("+{$days} days midnight");

        if ($date_timestamp >= $today && $date_timestamp <= $future) {
            $days_until = floor(($date_timestamp - $today) / 86400);

            return [
                'trigger_date' => date('Y-m-d', $date_timestamp),
                'trigger_field' => $field,
                'days_until' => $days_until,
                'is_upcoming' => true,
                'within_days' => $days,
            ];
        }

        return false;
    }

    /**
     * Check field_matches trigger
     */
    private function check_field_matches($trigger, $post_id, $context) {
        $field = $trigger['field'] ?? '';
        $operator = $trigger['operator'] ?? 'equals';
        $value = $trigger['value'] ?? '';

        if (empty($field)) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $actual_value = $this->data_handler->get_field_value($post, $field, 'auto');

        return $this->conditions->compare($actual_value, $operator, $value, $context);
    }

    /**
     * Get posts matching a date-based trigger
     *
     * @param array $automation Automation config
     * @return array Array of matching post IDs
     */
    public function get_posts_for_date_trigger($automation) {
        $trigger = $automation['trigger'] ?? [];
        $type = $trigger['type'] ?? '';
        $field = $trigger['field'] ?? '';

        if (empty($field)) {
            return [];
        }

        // Determine post type
        $post_type = $automation['post_type'] ?? '';
        if (empty($post_type) && !empty($automation['table_id'])) {
            $table_config = PDS_Post_Tables_Post_Type::get_table_config($automation['table_id']);
            $post_type = $table_config['source_post_type'] ?? '';
        }

        if (empty($post_type)) {
            return [];
        }

        // Build date query based on trigger type
        $today = current_time('Y-m-d');
        $meta_query = [];

        switch ($type) {
            case 'date_equals_today':
                $meta_query = [
                    'relation' => 'OR',
                    // Standard date format
                    [
                        'key' => $field,
                        'value' => $today,
                        'compare' => '=',
                        'type' => 'DATE',
                    ],
                    // ACF date format (Ymd)
                    [
                        'key' => $field,
                        'value' => str_replace('-', '', $today),
                        'compare' => '=',
                    ],
                ];
                break;

            case 'date_days_before':
                $days = intval($trigger['days'] ?? $trigger['value'] ?? 0);
                $target_date = date('Y-m-d', strtotime("+{$days} days"));
                $meta_query = [
                    'relation' => 'OR',
                    [
                        'key' => $field,
                        'value' => $target_date,
                        'compare' => '=',
                        'type' => 'DATE',
                    ],
                    [
                        'key' => $field,
                        'value' => str_replace('-', '', $target_date),
                        'compare' => '=',
                    ],
                ];
                break;

            case 'date_days_after':
                $days = intval($trigger['days'] ?? $trigger['value'] ?? 0);
                $target_date = date('Y-m-d', strtotime("-{$days} days"));
                $meta_query = [
                    'relation' => 'OR',
                    [
                        'key' => $field,
                        'value' => $target_date,
                        'compare' => '=',
                        'type' => 'DATE',
                    ],
                    [
                        'key' => $field,
                        'value' => str_replace('-', '', $target_date),
                        'compare' => '=',
                    ],
                ];
                break;

            case 'date_is_overdue':
                $meta_query = [
                    'relation' => 'OR',
                    [
                        'key' => $field,
                        'value' => $today,
                        'compare' => '<',
                        'type' => 'DATE',
                    ],
                    [
                        'key' => $field,
                        'value' => str_replace('-', '', $today),
                        'compare' => '<',
                    ],
                ];
                break;

            case 'date_is_upcoming':
                $days = intval($trigger['days'] ?? $trigger['value'] ?? 7);
                $future_date = date('Y-m-d', strtotime("+{$days} days"));
                $meta_query = [
                    'relation' => 'AND',
                    [
                        'relation' => 'OR',
                        [
                            'key' => $field,
                            'value' => [$today, $future_date],
                            'compare' => 'BETWEEN',
                            'type' => 'DATE',
                        ],
                        [
                            'key' => $field,
                            'value' => [str_replace('-', '', $today), str_replace('-', '', $future_date)],
                            'compare' => 'BETWEEN',
                        ],
                    ],
                ];
                break;

            case 'field_matches':
                $operator = $trigger['operator'] ?? 'equals';
                $value = $trigger['value'] ?? '';
                $compare_map = [
                    'equals' => '=',
                    'not_equals' => '!=',
                    'greater_than' => '>',
                    'less_than' => '<',
                    'contains' => 'LIKE',
                ];
                $compare = $compare_map[$operator] ?? '=';

                if ($operator === 'contains') {
                    $value = '%' . $value . '%';
                }

                $meta_query = [
                    [
                        'key' => $field,
                        'value' => $value,
                        'compare' => $compare,
                    ],
                ];
                break;

            default:
                return [];
        }

        // Query posts
        $args = [
            'post_type' => $post_type,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => $meta_query,
        ];

        // Apply additional filter conditions from trigger
        if (!empty($trigger['conditions']['rules'])) {
            foreach ($trigger['conditions']['rules'] as $rule) {
                if (!empty($rule['field']) && isset($rule['value'])) {
                    $compare_map = [
                        'equals' => '=',
                        'not_equals' => '!=',
                        'greater_than' => '>',
                        'less_than' => '<',
                        'contains' => 'LIKE',
                    ];
                    $compare = $compare_map[$rule['operator'] ?? 'equals'] ?? '=';
                    $value = $rule['value'];

                    if (($rule['operator'] ?? '') === 'contains') {
                        $value = '%' . $value . '%';
                    }

                    $args['meta_query'][] = [
                        'key' => $rule['field'],
                        'value' => $value,
                        'compare' => $compare,
                    ];
                }
            }
        }

        return get_posts($args);
    }

    /**
     * Convert various date formats to timestamp
     */
    private function to_timestamp($value) {
        if (empty($value)) {
            return false;
        }

        if (is_numeric($value)) {
            return intval($value);
        }

        // ACF date format (Ymd)
        if (is_string($value) && preg_match('/^\d{8}$/', $value)) {
            $value = substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
        }

        $timestamp = strtotime($value);
        return $timestamp !== false ? $timestamp : false;
    }

    /**
     * Get available trigger types
     */
    public static function get_trigger_types() {
        return [
            'post_lifecycle' => [
                'label' => __('Post Lifecycle', 'pds-post-tables'),
                'triggers' => [
                    'post_created' => [
                        'label' => __('Post Created', 'pds-post-tables'),
                        'description' => __('When a new post is created', 'pds-post-tables'),
                        'realtime' => true,
                    ],
                    'post_updated' => [
                        'label' => __('Post Updated', 'pds-post-tables'),
                        'description' => __('When a post is updated', 'pds-post-tables'),
                        'realtime' => true,
                    ],
                ],
            ],
            'field_changes' => [
                'label' => __('Field Changes', 'pds-post-tables'),
                'triggers' => [
                    'field_changed' => [
                        'label' => __('Field Changed', 'pds-post-tables'),
                        'description' => __('When a specific field changes', 'pds-post-tables'),
                        'requires_field' => true,
                        'realtime' => true,
                    ],
                    'field_changed_to' => [
                        'label' => __('Field Changed To', 'pds-post-tables'),
                        'description' => __('When a field changes to a specific value', 'pds-post-tables'),
                        'requires_field' => true,
                        'requires_value' => true,
                        'realtime' => true,
                    ],
                    'field_changed_from' => [
                        'label' => __('Field Changed From', 'pds-post-tables'),
                        'description' => __('When a field changes from a specific value', 'pds-post-tables'),
                        'requires_field' => true,
                        'requires_value' => true,
                        'realtime' => true,
                    ],
                    'field_transition' => [
                        'label' => __('Field Transition', 'pds-post-tables'),
                        'description' => __('When a field changes from one value to another', 'pds-post-tables'),
                        'requires_field' => true,
                        'requires_from_to' => true,
                        'realtime' => true,
                    ],
                ],
            ],
            'date_based' => [
                'label' => __('Date Based', 'pds-post-tables'),
                'triggers' => [
                    'date_equals_today' => [
                        'label' => __('Date Is Today', 'pds-post-tables'),
                        'description' => __('When a date field equals today', 'pds-post-tables'),
                        'requires_field' => true,
                        'scheduled' => true,
                    ],
                    'date_days_before' => [
                        'label' => __('Days Before Date', 'pds-post-tables'),
                        'description' => __('X days before a date field', 'pds-post-tables'),
                        'requires_field' => true,
                        'requires_days' => true,
                        'scheduled' => true,
                    ],
                    'date_days_after' => [
                        'label' => __('Days After Date', 'pds-post-tables'),
                        'description' => __('X days after a date field', 'pds-post-tables'),
                        'requires_field' => true,
                        'requires_days' => true,
                        'scheduled' => true,
                    ],
                    'date_is_overdue' => [
                        'label' => __('Date Is Overdue', 'pds-post-tables'),
                        'description' => __('When a date field is in the past', 'pds-post-tables'),
                        'requires_field' => true,
                        'scheduled' => true,
                    ],
                    'date_is_upcoming' => [
                        'label' => __('Date Is Upcoming', 'pds-post-tables'),
                        'description' => __('When a date is within the next X days', 'pds-post-tables'),
                        'requires_field' => true,
                        'requires_days' => true,
                        'scheduled' => true,
                    ],
                ],
            ],
            'comparisons' => [
                'label' => __('Field Comparisons', 'pds-post-tables'),
                'triggers' => [
                    'field_matches' => [
                        'label' => __('Field Matches Condition', 'pds-post-tables'),
                        'description' => __('When a field matches a condition', 'pds-post-tables'),
                        'requires_field' => true,
                        'requires_operator' => true,
                        'requires_value' => true,
                        'scheduled' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Check if a trigger type is realtime (fires immediately) or scheduled
     */
    public static function is_realtime_trigger($type) {
        $realtime_types = [
            'post_created',
            'post_updated',
            'field_changed',
            'field_changed_to',
            'field_changed_from',
            'field_transition',
        ];

        return in_array($type, $realtime_types);
    }

    /**
     * Check if a trigger type is scheduled (runs on cron)
     */
    public static function is_scheduled_trigger($type) {
        $scheduled_types = [
            'date_equals_today',
            'date_days_before',
            'date_days_after',
            'date_is_overdue',
            'date_is_upcoming',
            'field_matches',
        ];

        return in_array($type, $scheduled_types);
    }
}
