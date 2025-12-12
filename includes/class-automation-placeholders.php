<?php
/**
 * Automation Placeholders - Parses and replaces placeholders in text
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Automation_Placeholders {

    /**
     * Data handler instance
     */
    private $data_handler;

    /**
     * Condition evaluator instance
     */
    private $conditions;

    public function __construct($data_handler = null, $conditions = null) {
        $this->data_handler = $data_handler;
        $this->conditions = $conditions;
    }

    /**
     * Parse and replace all placeholders in text
     *
     * @param string $text Text containing placeholders
     * @param int $post_id Post ID for field values
     * @param array $context Additional context data
     * @return string Processed text
     */
    public function parse($text, $post_id, $context = []) {
        if (empty($text) || !is_string($text)) {
            return $text;
        }

        // Parse conditional placeholders first: {{IF:condition:then:else}}
        $text = $this->parse_conditionals($text, $post_id, $context);

        // Parse formula placeholders: {{field + 7 days}}, {{NOW}}, etc.
        $text = $this->parse_formulas($text, $post_id, $context);

        // Parse simple placeholders: {{field_name}}
        $text = $this->parse_simple($text, $post_id, $context);

        return $text;
    }

    /**
     * Parse simple {{placeholder}} patterns
     */
    private function parse_simple($text, $post_id, $context) {
        return preg_replace_callback(
            '/\{\{([a-zA-Z0-9_:]+)\}\}/',
            function($matches) use ($post_id, $context) {
                return $this->get_placeholder_value($matches[1], $post_id, $context);
            },
            $text
        );
    }

    /**
     * Parse conditional placeholders: {{IF:field=value:then_text:else_text}}
     */
    private function parse_conditionals($text, $post_id, $context) {
        // Pattern: {{IF:condition:then:else}}
        return preg_replace_callback(
            '/\{\{IF:([^:]+):([^:]*):([^}]*)\}\}/',
            function($matches) use ($post_id, $context) {
                $condition = $matches[1];
                $then_value = $matches[2];
                $else_value = $matches[3];

                $result = $this->evaluate_inline_condition($condition, $post_id, $context);
                $output = $result ? $then_value : $else_value;

                // Recursively parse placeholders in the result
                return $this->parse($output, $post_id, $context);
            },
            $text
        );
    }

    /**
     * Evaluate an inline condition like "field=value" or "field>100"
     */
    private function evaluate_inline_condition($condition, $post_id, $context) {
        // Parse operator and operands
        $operators = ['>=', '<=', '!=', '=', '>', '<'];
        $operator = null;
        $field = null;
        $value = null;

        foreach ($operators as $op) {
            if (strpos($condition, $op) !== false) {
                $parts = explode($op, $condition, 2);
                $field = trim($parts[0]);
                $value = trim($parts[1]);
                $operator = $op;
                break;
            }
        }

        if (!$operator || !$field) {
            return false;
        }

        // Map simple operators to our condition operators
        $operator_map = [
            '=' => 'equals',
            '!=' => 'not_equals',
            '>' => 'greater_than',
            '<' => 'less_than',
            '>=' => 'greater_equal',
            '<=' => 'less_equal',
        ];

        $actual_value = $this->get_placeholder_value($field, $post_id, $context);
        $mapped_operator = $operator_map[$operator] ?? 'equals';

        if ($this->conditions) {
            return $this->conditions->compare($actual_value, $mapped_operator, $value, $context);
        }

        // Fallback simple comparison
        switch ($operator) {
            case '=':
                return $actual_value == $value;
            case '!=':
                return $actual_value != $value;
            case '>':
                return floatval($actual_value) > floatval($value);
            case '<':
                return floatval($actual_value) < floatval($value);
            case '>=':
                return floatval($actual_value) >= floatval($value);
            case '<=':
                return floatval($actual_value) <= floatval($value);
            default:
                return false;
        }
    }

    /**
     * Parse formula placeholders: {{NOW}}, {{field + 7 days}}, etc.
     */
    private function parse_formulas($text, $post_id, $context) {
        // Date formulas: {{field_name + 7 days}}, {{field_name - 2 weeks}}
        $text = preg_replace_callback(
            '/\{\{([a-zA-Z0-9_]+)\s*([\+\-])\s*(\d+)\s*(days?|weeks?|months?|years?)\}\}/i',
            function($matches) use ($post_id, $context) {
                $field = $matches[1];
                $operator = $matches[2];
                $amount = intval($matches[3]);
                $unit = strtolower($matches[4]);

                // Normalize unit
                if (!str_ends_with($unit, 's')) {
                    $unit .= 's';
                }

                $base_value = $this->get_placeholder_value($field, $post_id, $context);
                $timestamp = $this->to_timestamp($base_value);

                if ($timestamp === false) {
                    return '';
                }

                $modifier = ($operator === '+' ? '+' : '-') . $amount . ' ' . $unit;
                $new_timestamp = strtotime($modifier, $timestamp);

                return date('Y-m-d', $new_timestamp);
            },
            $text
        );

        // Math formulas: {{field * 2}}, {{field1 + field2}}
        $text = preg_replace_callback(
            '/\{\{([a-zA-Z0-9_]+)\s*([\+\-\*\/])\s*([a-zA-Z0-9_]+)\}\}/',
            function($matches) use ($post_id, $context) {
                $field1 = $matches[1];
                $operator = $matches[2];
                $operand2 = $matches[3];

                $value1 = $this->get_placeholder_value($field1, $post_id, $context);

                // Check if operand2 is a number or field
                if (is_numeric($operand2)) {
                    $value2 = floatval($operand2);
                } else {
                    $value2 = $this->get_placeholder_value($operand2, $post_id, $context);
                }

                $value1 = floatval($value1);
                $value2 = floatval($value2);

                switch ($operator) {
                    case '+':
                        return $value1 + $value2;
                    case '-':
                        return $value1 - $value2;
                    case '*':
                        return $value1 * $value2;
                    case '/':
                        return $value2 != 0 ? $value1 / $value2 : 0;
                    default:
                        return $value1;
                }
            },
            $text
        );

        return $text;
    }

    /**
     * Get the value for a placeholder
     *
     * @param string $placeholder Placeholder name (without braces)
     * @param int $post_id Post ID
     * @param array $context Additional context
     * @return string
     */
    public function get_placeholder_value($placeholder, $post_id, $context = []) {
        // Handle format modifiers: {{field:formatted}}
        $format = null;
        if (strpos($placeholder, ':') !== false) {
            list($placeholder, $format) = explode(':', $placeholder, 2);
        }

        $value = $this->resolve_placeholder($placeholder, $post_id, $context);

        // Apply formatting
        if ($format) {
            $value = $this->apply_format($value, $format, $placeholder);
        }

        return $this->stringify($value);
    }

    /**
     * Resolve a placeholder to its value
     */
    private function resolve_placeholder($placeholder, $post_id, $context) {
        // System placeholders
        switch (strtoupper($placeholder)) {
            case 'NOW':
            case 'CURRENT_DATETIME':
                return current_time('mysql');

            case 'TODAY':
            case 'CURRENT_DATE':
                return current_time('Y-m-d');

            case 'CURRENT_TIME':
                return current_time('H:i:s');

            case 'CURRENT_USER':
                $user = wp_get_current_user();
                return $user->ID ? $user->display_name : '';

            case 'CURRENT_USER_ID':
                return get_current_user_id();

            case 'CURRENT_USER_EMAIL':
                $user = wp_get_current_user();
                return $user->ID ? $user->user_email : '';

            case 'SITE_NAME':
                return get_bloginfo('name');

            case 'SITE_URL':
                return home_url();

            case 'ADMIN_EMAIL':
                return get_option('admin_email');
        }

        // Context placeholders
        $context_map = [
            'old_value' => 'old_value',
            'new_value' => 'new_value',
            'changed_field' => 'changed_field',
            'changed_by' => 'changed_by',
            'changed_by_email' => 'changed_by_email',
            'change_timestamp' => 'change_timestamp',
            'trigger_date' => 'trigger_date',
            'days_until' => 'days_until',
            'days_since' => 'days_since',
            'automation_name' => 'automation_name',
            'count' => 'count',
            'items_list' => 'items_list',
            'items_table' => 'items_table',
        ];

        $placeholder_lower = strtolower($placeholder);
        if (isset($context_map[$placeholder_lower]) && isset($context[$context_map[$placeholder_lower]])) {
            return $context[$context_map[$placeholder_lower]];
        }

        // Post placeholders
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        switch (strtolower($placeholder)) {
            case 'post_id':
            case 'id':
                return $post->ID;

            case 'post_title':
            case 'title':
                return $post->post_title;

            case 'post_content':
            case 'content':
                return $post->post_content;

            case 'post_excerpt':
            case 'excerpt':
                return $post->post_excerpt ?: wp_trim_words($post->post_content, 55);

            case 'post_date':
                return $post->post_date;

            case 'post_modified':
                return $post->post_modified;

            case 'post_status':
                return $post->post_status;

            case 'post_type':
                return $post->post_type;

            case 'post_url':
            case 'permalink':
                return get_permalink($post->ID);

            case 'edit_url':
                return get_edit_post_link($post->ID, 'raw');

            case 'post_author':
            case 'author':
                $author = get_userdata($post->post_author);
                return $author ? $author->display_name : '';

            case 'post_author_email':
            case 'author_email':
                $author = get_userdata($post->post_author);
                return $author ? $author->user_email : '';

            case 'featured_image':
            case 'thumbnail':
                return get_the_post_thumbnail_url($post->ID, 'full') ?: '';
        }

        // Check if it's a user field reference (e.g., assigned_user_email)
        if (preg_match('/^(.+)_email$/', $placeholder, $matches)) {
            $user_field = $matches[1];
            $user_id = $this->get_field_value_from_post($post, $user_field);
            if ($user_id && is_numeric($user_id)) {
                $user = get_userdata($user_id);
                return $user ? $user->user_email : '';
            }
        }

        // Custom field / meta / ACF
        return $this->get_field_value_from_post($post, $placeholder);
    }

    /**
     * Get field value from post using data handler or fallback
     */
    private function get_field_value_from_post($post, $field_key) {
        if ($this->data_handler) {
            return $this->data_handler->get_field_value($post, $field_key, 'auto');
        }

        // Fallback: try ACF first, then post meta
        if (function_exists('get_field')) {
            $value = get_field($field_key, $post->ID);
            if ($value !== null && $value !== false) {
                return $value;
            }
        }

        return get_post_meta($post->ID, $field_key, true);
    }

    /**
     * Apply a format to a value
     */
    private function apply_format($value, $format, $field = '') {
        switch (strtolower($format)) {
            case 'formatted':
            case 'display':
                return $this->format_for_display($value);

            case 'date':
                return $this->format_date($value, get_option('date_format'));

            case 'datetime':
                return $this->format_date($value, get_option('date_format') . ' ' . get_option('time_format'));

            case 'time':
                return $this->format_date($value, get_option('time_format'));

            case 'uppercase':
            case 'upper':
                return strtoupper($value);

            case 'lowercase':
            case 'lower':
                return strtolower($value);

            case 'capitalize':
            case 'ucfirst':
                return ucfirst(strtolower($value));

            case 'titlecase':
            case 'ucwords':
                return ucwords(strtolower($value));

            case 'number':
                return number_format(floatval($value));

            case 'currency':
                return '$' . number_format(floatval($value), 2);

            case 'percentage':
            case 'percent':
                return number_format(floatval($value), 1) . '%';

            case 'html':
                return wp_kses_post($value);

            case 'plain':
            case 'text':
                return wp_strip_all_tags($value);

            case 'url':
            case 'urlencode':
                return urlencode($value);

            default:
                // Check if format is a date format string
                if (preg_match('/^[YymdDjFMnGgHhisaA\-\/\.\s:]+$/', $format)) {
                    return $this->format_date($value, $format);
                }
                return $value;
        }
    }

    /**
     * Format value for display
     */
    private function format_for_display($value) {
        if (is_array($value)) {
            // Handle ACF user/post arrays
            if (isset($value['display_name'])) {
                return $value['display_name'];
            }
            if (isset($value['post_title'])) {
                return $value['post_title'];
            }
            if (isset($value['label'])) {
                return $value['label'];
            }
            return implode(', ', array_map([$this, 'format_for_display'], $value));
        }

        if (is_object($value)) {
            if ($value instanceof WP_User) {
                return $value->display_name;
            }
            if ($value instanceof WP_Post) {
                return $value->post_title;
            }
        }

        if (is_bool($value)) {
            return $value ? __('Yes', 'pds-post-tables') : __('No', 'pds-post-tables');
        }

        return $value;
    }

    /**
     * Format a date value
     */
    private function format_date($value, $format) {
        $timestamp = $this->to_timestamp($value);
        if ($timestamp === false) {
            return $value;
        }
        return date_i18n($format, $timestamp);
    }

    /**
     * Convert value to timestamp
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
     * Convert a value to string for output
     */
    private function stringify($value) {
        if (is_null($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            // Handle arrays of scalars
            $strings = array_map(function($v) {
                if (is_array($v)) {
                    // ACF user/post array
                    if (isset($v['display_name'])) {
                        return $v['display_name'];
                    }
                    if (isset($v['post_title'])) {
                        return $v['post_title'];
                    }
                    if (isset($v['label'])) {
                        return $v['label'];
                    }
                    return '';
                }
                if (is_object($v)) {
                    if ($v instanceof WP_User) {
                        return $v->display_name;
                    }
                    if ($v instanceof WP_Post) {
                        return $v->post_title;
                    }
                    return '';
                }
                return strval($v);
            }, $value);

            return implode(', ', array_filter($strings));
        }

        if (is_object($value)) {
            if ($value instanceof WP_User) {
                return $value->display_name;
            }
            if ($value instanceof WP_Post) {
                return $value->post_title;
            }
            return '';
        }

        return strval($value);
    }

    /**
     * Get list of all available placeholders for documentation
     */
    public static function get_available_placeholders() {
        return [
            'system' => [
                '{{NOW}}' => __('Current date and time', 'pds-post-tables'),
                '{{TODAY}}' => __('Current date', 'pds-post-tables'),
                '{{CURRENT_TIME}}' => __('Current time', 'pds-post-tables'),
                '{{CURRENT_USER}}' => __('Current user display name', 'pds-post-tables'),
                '{{CURRENT_USER_EMAIL}}' => __('Current user email', 'pds-post-tables'),
                '{{SITE_NAME}}' => __('Site name', 'pds-post-tables'),
                '{{SITE_URL}}' => __('Site URL', 'pds-post-tables'),
                '{{ADMIN_EMAIL}}' => __('Admin email', 'pds-post-tables'),
            ],
            'post' => [
                '{{post_id}}' => __('Post ID', 'pds-post-tables'),
                '{{post_title}}' => __('Post title', 'pds-post-tables'),
                '{{post_content}}' => __('Post content', 'pds-post-tables'),
                '{{post_excerpt}}' => __('Post excerpt', 'pds-post-tables'),
                '{{post_date}}' => __('Post date', 'pds-post-tables'),
                '{{post_status}}' => __('Post status', 'pds-post-tables'),
                '{{post_url}}' => __('Post URL', 'pds-post-tables'),
                '{{edit_url}}' => __('Edit URL', 'pds-post-tables'),
                '{{post_author}}' => __('Author name', 'pds-post-tables'),
                '{{post_author_email}}' => __('Author email', 'pds-post-tables'),
            ],
            'context' => [
                '{{old_value}}' => __('Previous field value (for change triggers)', 'pds-post-tables'),
                '{{new_value}}' => __('New field value (for change triggers)', 'pds-post-tables'),
                '{{changed_field}}' => __('Name of changed field', 'pds-post-tables'),
                '{{changed_by}}' => __('User who made the change', 'pds-post-tables'),
                '{{changed_by_email}}' => __('Email of user who made change', 'pds-post-tables'),
                '{{days_until}}' => __('Days until trigger date', 'pds-post-tables'),
                '{{days_since}}' => __('Days since trigger date', 'pds-post-tables'),
            ],
            'consolidation' => [
                '{{count}}' => __('Number of items (for consolidated emails)', 'pds-post-tables'),
                '{{items_list}}' => __('Bulleted list of items', 'pds-post-tables'),
                '{{items_table}}' => __('HTML table of items', 'pds-post-tables'),
            ],
            'custom' => [
                '{{field_name}}' => __('Any custom field/meta value', 'pds-post-tables'),
                '{{field_name:formatted}}' => __('Formatted field value', 'pds-post-tables'),
                '{{field_name:date}}' => __('Field formatted as date', 'pds-post-tables'),
                '{{user_field_email}}' => __('Email of user in a user field', 'pds-post-tables'),
            ],
            'formulas' => [
                '{{field + 7 days}}' => __('Date plus days', 'pds-post-tables'),
                '{{field - 2 weeks}}' => __('Date minus weeks', 'pds-post-tables'),
                '{{field * 2}}' => __('Numeric multiplication', 'pds-post-tables'),
                '{{IF:field=value:Yes:No}}' => __('Conditional text', 'pds-post-tables'),
            ],
        ];
    }

    /**
     * Generate items list for consolidated emails
     */
    public static function generate_items_list($items, $template = '• {{post_title}}') {
        $placeholders = new self();
        $lines = [];

        foreach ($items as $item) {
            $post_id = is_array($item) ? ($item['post_id'] ?? $item['ID'] ?? 0) : $item;
            if ($post_id) {
                $lines[] = $placeholders->parse($template, $post_id, $item);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Generate items table for consolidated emails
     */
    public static function generate_items_table($items, $columns = ['post_title', 'post_date']) {
        if (empty($items)) {
            return '';
        }

        $placeholders = new self();
        $html = '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse;">';

        // Header
        $html .= '<thead><tr>';
        foreach ($columns as $col) {
            $label = ucwords(str_replace('_', ' ', $col));
            $html .= '<th style="background: #f5f5f5;">' . esc_html($label) . '</th>';
        }
        $html .= '</tr></thead>';

        // Body
        $html .= '<tbody>';
        foreach ($items as $item) {
            $post_id = is_array($item) ? ($item['post_id'] ?? $item['ID'] ?? 0) : $item;
            if (!$post_id) {
                continue;
            }

            $html .= '<tr>';
            foreach ($columns as $col) {
                $value = $placeholders->get_placeholder_value($col, $post_id, $item);
                $html .= '<td>' . esc_html($value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }
}
