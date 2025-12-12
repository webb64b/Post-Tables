<?php
/**
 * Automation Conditions - Evaluates conditions for triggers and actions
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Automation_Conditions {

    /**
     * Data handler instance
     */
    private $data_handler;

    /**
     * Field scanner instance
     */
    private $field_scanner;

    public function __construct($data_handler = null, $field_scanner = null) {
        $this->data_handler = $data_handler;
        $this->field_scanner = $field_scanner;
    }

    /**
     * Evaluate a condition group
     *
     * @param array $conditions Condition group with 'logic' and 'rules'
     * @param int $post_id Post ID to evaluate against
     * @param array $context Additional context (old_value, new_value, etc.)
     * @return bool
     */
    public function evaluate($conditions, $post_id, $context = []) {
        // No conditions = always true
        if (empty($conditions) || empty($conditions['rules'])) {
            return true;
        }

        $logic = strtoupper($conditions['logic'] ?? 'AND');
        $results = [];

        foreach ($conditions['rules'] as $rule) {
            // Check if this is a nested group
            if (isset($rule['logic']) && isset($rule['rules'])) {
                $results[] = $this->evaluate($rule, $post_id, $context);
            } else {
                $results[] = $this->evaluate_single($rule, $post_id, $context);
            }
        }

        if (empty($results)) {
            return true;
        }

        if ($logic === 'AND') {
            return !in_array(false, $results, true);
        } else { // OR
            return in_array(true, $results, true);
        }
    }

    /**
     * Evaluate a single condition rule
     *
     * @param array $rule Single condition rule
     * @param int $post_id Post ID
     * @param array $context Additional context
     * @return bool
     */
    private function evaluate_single($rule, $post_id, $context = []) {
        $field = $rule['field'] ?? '';
        $operator = $rule['operator'] ?? 'equals';
        $compare_value = $rule['value'] ?? null;

        if (empty($field)) {
            return true; // No field specified = pass
        }

        // Get the actual value
        $actual_value = $this->get_value($field, $post_id, $context);

        // Perform comparison
        return $this->compare($actual_value, $operator, $compare_value, $context);
    }

    /**
     * Get field value, supporting special context values
     *
     * @param string $field Field name or special key
     * @param int $post_id Post ID
     * @param array $context Context data
     * @return mixed
     */
    private function get_value($field, $post_id, $context) {
        // Special context values
        $special_fields = [
            '_old_value' => 'old_value',
            '_new_value' => 'new_value',
            '_changed_field' => 'changed_field',
            '_changed_by' => 'changed_by',
            '_trigger_date' => 'trigger_date',
        ];

        if (isset($special_fields[$field])) {
            return $context[$special_fields[$field]] ?? null;
        }

        // Get post
        $post = get_post($post_id);
        if (!$post) {
            return null;
        }

        // Core post fields
        if (property_exists($post, $field)) {
            return $post->$field;
        }

        // Use data handler if available
        if ($this->data_handler) {
            return $this->data_handler->get_field_value($post, $field, 'auto');
        }

        // Fallback to post meta
        return get_post_meta($post_id, $field, true);
    }

    /**
     * Compare values using the specified operator
     *
     * @param mixed $actual Actual value
     * @param string $operator Comparison operator
     * @param mixed $expected Expected value
     * @param array $context Additional context
     * @return bool
     */
    public function compare($actual, $operator, $expected, $context = []) {
        // Normalize values for comparison
        $actual = $this->normalize_value($actual);
        $expected = $this->normalize_value($expected);

        switch ($operator) {
            // Equality
            case 'equals':
            case '=':
            case '==':
                return $this->loose_equals($actual, $expected);

            case 'not_equals':
            case '!=':
            case '<>':
                return !$this->loose_equals($actual, $expected);

            // Numeric comparisons
            case 'greater_than':
            case '>':
                return is_numeric($actual) && is_numeric($expected) && floatval($actual) > floatval($expected);

            case 'less_than':
            case '<':
                return is_numeric($actual) && is_numeric($expected) && floatval($actual) < floatval($expected);

            case 'greater_equal':
            case '>=':
                return is_numeric($actual) && is_numeric($expected) && floatval($actual) >= floatval($expected);

            case 'less_equal':
            case '<=':
                return is_numeric($actual) && is_numeric($expected) && floatval($actual) <= floatval($expected);

            case 'between':
                if (!is_array($expected) || count($expected) < 2) {
                    return false;
                }
                $val = floatval($actual);
                return $val >= floatval($expected[0]) && $val <= floatval($expected[1]);

            // String operations
            case 'contains':
                return is_string($actual) && is_string($expected) && stripos($actual, $expected) !== false;

            case 'not_contains':
                return !is_string($actual) || !is_string($expected) || stripos($actual, $expected) === false;

            case 'starts_with':
                return is_string($actual) && is_string($expected) && stripos($actual, $expected) === 0;

            case 'ends_with':
                if (!is_string($actual) || !is_string($expected)) {
                    return false;
                }
                $len = strlen($expected);
                return $len === 0 || substr(strtolower($actual), -$len) === strtolower($expected);

            case 'matches_regex':
                if (!is_string($actual) || !is_string($expected)) {
                    return false;
                }
                // Suppress errors for invalid regex
                return @preg_match($expected, $actual) === 1;

            // Empty checks
            case 'is_empty':
                return $this->is_empty($actual);

            case 'is_not_empty':
                return !$this->is_empty($actual);

            // Boolean
            case 'is_true':
                return $this->to_bool($actual) === true;

            case 'is_false':
                return $this->to_bool($actual) === false;

            // List operations
            case 'in':
                $list = $this->to_list($expected);
                return in_array($actual, $list, false) || in_array(strval($actual), $list, false);

            case 'not_in':
                $list = $this->to_list($expected);
                return !in_array($actual, $list, false) && !in_array(strval($actual), $list, false);

            // Date operations
            case 'is_today':
                return $this->compare_date($actual, 'today');

            case 'is_past':
                return $this->compare_date($actual, 'past');

            case 'is_future':
                return $this->compare_date($actual, 'future');

            case 'is_within_days':
                return $this->is_within_days($actual, intval($expected));

            case 'date_equals':
                return $this->dates_equal($actual, $expected);

            case 'date_before':
                return $this->date_compare($actual, $expected) < 0;

            case 'date_after':
                return $this->date_compare($actual, $expected) > 0;

            // Change detection (for field change triggers)
            case 'changed':
                return isset($context['old_value']) && isset($context['new_value'])
                       && $context['old_value'] !== $context['new_value'];

            case 'changed_to':
                return isset($context['new_value']) && $this->loose_equals($context['new_value'], $expected);

            case 'changed_from':
                return isset($context['old_value']) && $this->loose_equals($context['old_value'], $expected);

            default:
                // Unknown operator - fail safe
                return false;
        }
    }

    /**
     * Normalize a value for comparison
     */
    private function normalize_value($value) {
        if (is_array($value) && isset($value['ID'])) {
            // ACF user/post object
            return $value['ID'];
        }

        if (is_object($value) && isset($value->ID)) {
            // WP_User or WP_Post
            return $value->ID;
        }

        return $value;
    }

    /**
     * Loose equality comparison
     */
    private function loose_equals($a, $b) {
        // Handle null
        if ($a === null && $b === null) {
            return true;
        }

        // String comparison (case-insensitive)
        if (is_string($a) && is_string($b)) {
            return strtolower(trim($a)) === strtolower(trim($b));
        }

        // Numeric comparison
        if (is_numeric($a) && is_numeric($b)) {
            return floatval($a) === floatval($b);
        }

        // Boolean
        if (is_bool($a) || is_bool($b)) {
            return $this->to_bool($a) === $this->to_bool($b);
        }

        // Fallback
        return $a == $b;
    }

    /**
     * Check if value is empty
     */
    private function is_empty($value) {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        if (is_array($value) && empty(array_filter($value, function($v) {
            return $v !== '' && $v !== null;
        }))) {
            return true;
        }

        return false;
    }

    /**
     * Convert value to boolean
     */
    private function to_bool($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'yes', 'on'])) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'off', ''])) {
                return false;
            }
        }

        return (bool) $value;
    }

    /**
     * Convert value to list
     */
    private function to_list($value) {
        if (is_array($value)) {
            return array_map('trim', $value);
        }

        if (is_string($value)) {
            return array_map('trim', explode(',', $value));
        }

        return [$value];
    }

    /**
     * Compare a date value
     */
    private function compare_date($date, $comparison) {
        $timestamp = $this->to_timestamp($date);
        if ($timestamp === false) {
            return false;
        }

        $today_start = strtotime('today midnight');
        $today_end = strtotime('tomorrow midnight') - 1;

        switch ($comparison) {
            case 'today':
                return $timestamp >= $today_start && $timestamp <= $today_end;

            case 'past':
                return $timestamp < $today_start;

            case 'future':
                return $timestamp > $today_end;

            default:
                return false;
        }
    }

    /**
     * Check if date is within X days from today
     */
    private function is_within_days($date, $days) {
        $timestamp = $this->to_timestamp($date);
        if ($timestamp === false) {
            return false;
        }

        $today = strtotime('today midnight');
        $future = strtotime("+{$days} days midnight");

        return $timestamp >= $today && $timestamp <= $future;
    }

    /**
     * Check if two dates are equal (ignoring time)
     */
    private function dates_equal($date1, $date2) {
        $ts1 = $this->to_timestamp($date1);
        $ts2 = $this->to_timestamp($date2);

        if ($ts1 === false || $ts2 === false) {
            return false;
        }

        return date('Y-m-d', $ts1) === date('Y-m-d', $ts2);
    }

    /**
     * Compare two dates
     * Returns -1 if date1 < date2, 0 if equal, 1 if date1 > date2
     */
    private function date_compare($date1, $date2) {
        $ts1 = $this->to_timestamp($date1);
        $ts2 = $this->to_timestamp($date2);

        if ($ts1 === false || $ts2 === false) {
            return 0;
        }

        $d1 = date('Y-m-d', $ts1);
        $d2 = date('Y-m-d', $ts2);

        return strcmp($d1, $d2);
    }

    /**
     * Convert various date formats to timestamp
     */
    private function to_timestamp($date) {
        if (empty($date)) {
            return false;
        }

        if (is_numeric($date)) {
            return intval($date);
        }

        // ACF date format (Ymd)
        if (preg_match('/^\d{8}$/', $date)) {
            $date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        }

        $timestamp = strtotime($date);
        return $timestamp !== false ? $timestamp : false;
    }

    /**
     * Get all available operators
     */
    public static function get_operators() {
        return [
            // Equality
            'equals' => __('equals', 'pds-post-tables'),
            'not_equals' => __('does not equal', 'pds-post-tables'),

            // Numeric
            'greater_than' => __('is greater than', 'pds-post-tables'),
            'less_than' => __('is less than', 'pds-post-tables'),
            'greater_equal' => __('is greater than or equal to', 'pds-post-tables'),
            'less_equal' => __('is less than or equal to', 'pds-post-tables'),
            'between' => __('is between', 'pds-post-tables'),

            // String
            'contains' => __('contains', 'pds-post-tables'),
            'not_contains' => __('does not contain', 'pds-post-tables'),
            'starts_with' => __('starts with', 'pds-post-tables'),
            'ends_with' => __('ends with', 'pds-post-tables'),
            'matches_regex' => __('matches pattern', 'pds-post-tables'),

            // Empty
            'is_empty' => __('is empty', 'pds-post-tables'),
            'is_not_empty' => __('is not empty', 'pds-post-tables'),

            // Boolean
            'is_true' => __('is checked/true', 'pds-post-tables'),
            'is_false' => __('is unchecked/false', 'pds-post-tables'),

            // List
            'in' => __('is one of', 'pds-post-tables'),
            'not_in' => __('is not one of', 'pds-post-tables'),

            // Date
            'is_today' => __('is today', 'pds-post-tables'),
            'is_past' => __('is in the past', 'pds-post-tables'),
            'is_future' => __('is in the future', 'pds-post-tables'),
            'is_within_days' => __('is within X days', 'pds-post-tables'),
            'date_equals' => __('date equals', 'pds-post-tables'),
            'date_before' => __('date is before', 'pds-post-tables'),
            'date_after' => __('date is after', 'pds-post-tables'),

            // Change detection
            'changed' => __('has changed', 'pds-post-tables'),
            'changed_to' => __('changed to', 'pds-post-tables'),
            'changed_from' => __('changed from', 'pds-post-tables'),
        ];
    }

    /**
     * Get operators suitable for a field type
     */
    public static function get_operators_for_type($field_type) {
        $common = ['equals', 'not_equals', 'is_empty', 'is_not_empty', 'in', 'not_in'];

        switch ($field_type) {
            case 'number':
                return array_merge($common, ['greater_than', 'less_than', 'greater_equal', 'less_equal', 'between']);

            case 'date':
            case 'datetime':
                return array_merge($common, ['is_today', 'is_past', 'is_future', 'is_within_days', 'date_equals', 'date_before', 'date_after']);

            case 'boolean':
                return ['is_true', 'is_false'];

            case 'text':
            case 'textarea':
            case 'wysiwyg':
            case 'email':
            case 'url':
                return array_merge($common, ['contains', 'not_contains', 'starts_with', 'ends_with', 'matches_regex']);

            case 'select':
            case 'user':
                return array_merge($common, ['in', 'not_in']);

            default:
                return $common;
        }
    }
}
