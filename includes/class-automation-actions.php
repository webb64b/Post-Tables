<?php
/**
 * Automation Actions - Executes automation actions (email, update field, conditional)
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Automation_Actions {

    /**
     * Data handler instance
     */
    private $data_handler;

    /**
     * Placeholder parser instance
     */
    private $placeholders;

    /**
     * Condition evaluator instance
     */
    private $conditions;

    /**
     * History logger instance
     */
    private $history;

    public function __construct($data_handler, $placeholders, $conditions, $history = null) {
        $this->data_handler = $data_handler;
        $this->placeholders = $placeholders;
        $this->conditions = $conditions;
        $this->history = $history;
    }

    /**
     * Execute an array of actions
     *
     * @param array $actions Actions to execute
     * @param int $post_id Post ID
     * @param array $context Execution context
     * @param array $automation Automation config (for logging)
     * @return array Results of each action
     */
    public function execute_actions($actions, $post_id, $context = [], $automation = []) {
        $results = [];

        foreach ($actions as $index => $action) {
            // Check action conditions
            if (!empty($action['conditions'])) {
                $condition_group = [
                    'logic' => $action['conditions']['logic'] ?? 'AND',
                    'rules' => $action['conditions']['rules'] ?? $action['conditions'],
                ];

                if (!$this->conditions->evaluate($condition_group, $post_id, $context)) {
                    $results[] = [
                        'index' => $index,
                        'type' => $action['type'] ?? 'unknown',
                        'status' => 'skipped',
                        'reason' => 'Conditions not met',
                    ];
                    continue;
                }
            }

            // Execute the action
            $result = $this->execute_single_action($action, $post_id, $context);
            $result['index'] = $index;
            $results[] = $result;

            // Check for stop action
            if (($action['type'] ?? '') === 'stop' || ($result['stop'] ?? false)) {
                break;
            }
        }

        return $results;
    }

    /**
     * Execute a single action
     *
     * @param array $action Action configuration
     * @param int $post_id Post ID
     * @param array $context Execution context
     * @return array Result
     */
    public function execute_single_action($action, $post_id, $context = []) {
        $type = $action['type'] ?? '';

        switch ($type) {
            case 'send_email':
                return $this->execute_send_email($action, $post_id, $context);

            case 'update_field':
                return $this->execute_update_field($action, $post_id, $context);

            case 'copy_field':
                return $this->execute_copy_field($action, $post_id, $context);

            case 'clear_field':
                return $this->execute_clear_field($action, $post_id, $context);

            case 'increment_field':
                return $this->execute_increment_field($action, $post_id, $context);

            case 'change_status':
                return $this->execute_change_status($action, $post_id, $context);

            case 'conditional':
                return $this->execute_conditional($action, $post_id, $context);

            case 'stop':
                return ['type' => 'stop', 'status' => 'success', 'stop' => true];

            default:
                return [
                    'type' => $type,
                    'status' => 'error',
                    'message' => sprintf(__('Unknown action type: %s', 'pds-post-tables'), $type),
                ];
        }
    }

    /**
     * Execute send email action
     */
    private function execute_send_email($action, $post_id, $context) {
        $result = [
            'type' => 'send_email',
            'status' => 'success',
        ];

        try {
            // Resolve recipients (may be conditional)
            $recipients = $this->resolve_conditional_value($action['recipients'] ?? '', $post_id, $context);
            $recipients = $this->placeholders->parse($recipients, $post_id, $context);

            // Parse recipient list
            $recipient_list = $this->parse_recipient_list($recipients);

            if (empty($recipient_list)) {
                return [
                    'type' => 'send_email',
                    'status' => 'error',
                    'message' => __('No valid recipients', 'pds-post-tables'),
                ];
            }

            // Resolve and parse subject and body
            $subject = $this->resolve_conditional_value($action['subject'] ?? '', $post_id, $context);
            $subject = $this->placeholders->parse($subject, $post_id, $context);

            $body = $this->resolve_conditional_value($action['body'] ?? '', $post_id, $context);
            $body = $this->placeholders->parse($body, $post_id, $context);

            // Determine content type
            $is_html = !empty($action['html']) || strpos($body, '<') !== false;

            // Build headers
            $headers = [];
            if ($is_html) {
                $headers[] = 'Content-Type: text/html; charset=UTF-8';
            }

            // From header
            if (!empty($action['from_name']) || !empty($action['from_email'])) {
                $from_name = $action['from_name'] ?? get_bloginfo('name');
                $from_email = $action['from_email'] ?? get_option('admin_email');
                $headers[] = sprintf('From: %s <%s>', $from_name, $from_email);
            }

            // Reply-to
            if (!empty($action['reply_to'])) {
                $reply_to = $this->placeholders->parse($action['reply_to'], $post_id, $context);
                $headers[] = 'Reply-To: ' . $reply_to;
            }

            // CC
            if (!empty($action['cc'])) {
                $cc = $this->placeholders->parse($action['cc'], $post_id, $context);
                $cc_list = $this->parse_recipient_list($cc);
                if (!empty($cc_list)) {
                    $headers[] = 'Cc: ' . implode(', ', $cc_list);
                }
            }

            // BCC
            if (!empty($action['bcc'])) {
                $bcc = $this->placeholders->parse($action['bcc'], $post_id, $context);
                $bcc_list = $this->parse_recipient_list($bcc);
                if (!empty($bcc_list)) {
                    $headers[] = 'Bcc: ' . implode(', ', $bcc_list);
                }
            }

            // Wrap body in HTML template if needed
            if ($is_html) {
                $body = $this->wrap_html_email($body, $subject);
            }

            // Send email
            $sent = wp_mail($recipient_list, $subject, $body, $headers);

            if ($sent) {
                $result['recipients'] = $recipient_list;
                $result['subject'] = $subject;
            } else {
                $result['status'] = 'error';
                $result['message'] = __('Failed to send email', 'pds-post-tables');
            }

        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Execute update field action
     */
    private function execute_update_field($action, $post_id, $context) {
        $result = [
            'type' => 'update_field',
            'status' => 'success',
        ];

        $field_key = $action['field_key'] ?? '';
        if (empty($field_key)) {
            return [
                'type' => 'update_field',
                'status' => 'error',
                'message' => __('No field specified', 'pds-post-tables'),
            ];
        }

        try {
            // Get the value to set
            $value = $this->resolve_field_value($action, $post_id, $context);

            // Get old value for logging
            $old_value = $this->data_handler->get_field_value(get_post($post_id), $field_key, 'auto');

            // Determine field source
            $source = $action['field_source'] ?? 'auto';

            // Update the field
            $update_result = $this->data_handler->update_field_value($post_id, $field_key, $value, $source);

            if (is_wp_error($update_result)) {
                $result['status'] = 'error';
                $result['message'] = $update_result->get_error_message();
            } else {
                $result['field'] = $field_key;
                $result['old_value'] = $old_value;
                $result['new_value'] = $value;
            }

        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Execute copy field action
     */
    private function execute_copy_field($action, $post_id, $context) {
        $source_field = $action['source_field'] ?? '';
        $target_field = $action['target_field'] ?? '';

        if (empty($source_field) || empty($target_field)) {
            return [
                'type' => 'copy_field',
                'status' => 'error',
                'message' => __('Source and target fields required', 'pds-post-tables'),
            ];
        }

        $post = get_post($post_id);
        $value = $this->data_handler->get_field_value($post, $source_field, 'auto');

        $update_result = $this->data_handler->update_field_value($post_id, $target_field, $value, 'auto');

        if (is_wp_error($update_result)) {
            return [
                'type' => 'copy_field',
                'status' => 'error',
                'message' => $update_result->get_error_message(),
            ];
        }

        return [
            'type' => 'copy_field',
            'status' => 'success',
            'source_field' => $source_field,
            'target_field' => $target_field,
            'value' => $value,
        ];
    }

    /**
     * Execute clear field action
     */
    private function execute_clear_field($action, $post_id, $context) {
        $field_key = $action['field_key'] ?? '';

        if (empty($field_key)) {
            return [
                'type' => 'clear_field',
                'status' => 'error',
                'message' => __('No field specified', 'pds-post-tables'),
            ];
        }

        $update_result = $this->data_handler->update_field_value($post_id, $field_key, '', 'auto');

        if (is_wp_error($update_result)) {
            return [
                'type' => 'clear_field',
                'status' => 'error',
                'message' => $update_result->get_error_message(),
            ];
        }

        return [
            'type' => 'clear_field',
            'status' => 'success',
            'field' => $field_key,
        ];
    }

    /**
     * Execute increment field action
     */
    private function execute_increment_field($action, $post_id, $context) {
        $field_key = $action['field_key'] ?? '';
        $amount = floatval($action['amount'] ?? 1);

        if (empty($field_key)) {
            return [
                'type' => 'increment_field',
                'status' => 'error',
                'message' => __('No field specified', 'pds-post-tables'),
            ];
        }

        $post = get_post($post_id);
        $current_value = floatval($this->data_handler->get_field_value($post, $field_key, 'auto'));
        $new_value = $current_value + $amount;

        $update_result = $this->data_handler->update_field_value($post_id, $field_key, $new_value, 'auto');

        if (is_wp_error($update_result)) {
            return [
                'type' => 'increment_field',
                'status' => 'error',
                'message' => $update_result->get_error_message(),
            ];
        }

        return [
            'type' => 'increment_field',
            'status' => 'success',
            'field' => $field_key,
            'old_value' => $current_value,
            'new_value' => $new_value,
            'amount' => $amount,
        ];
    }

    /**
     * Execute change status action
     */
    private function execute_change_status($action, $post_id, $context) {
        $new_status = $action['status'] ?? '';

        if (empty($new_status)) {
            return [
                'type' => 'change_status',
                'status' => 'error',
                'message' => __('No status specified', 'pds-post-tables'),
            ];
        }

        $post = get_post($post_id);
        $old_status = $post->post_status;

        $result = wp_update_post([
            'ID' => $post_id,
            'post_status' => $new_status,
        ], true);

        if (is_wp_error($result)) {
            return [
                'type' => 'change_status',
                'status' => 'error',
                'message' => $result->get_error_message(),
            ];
        }

        return [
            'type' => 'change_status',
            'status' => 'success',
            'old_status' => $old_status,
            'new_status' => $new_status,
        ];
    }

    /**
     * Execute conditional action (if/else branching)
     */
    private function execute_conditional($action, $post_id, $context) {
        $branches = $action['branches'] ?? [];

        if (empty($branches)) {
            return [
                'type' => 'conditional',
                'status' => 'error',
                'message' => __('No branches defined', 'pds-post-tables'),
            ];
        }

        foreach ($branches as $index => $branch) {
            $conditions = $branch['conditions'] ?? [];

            // Empty conditions = else branch (always matches)
            if (empty($conditions)) {
                // Execute this branch's actions
                $branch_actions = $branch['actions'] ?? [];
                $branch_results = $this->execute_actions($branch_actions, $post_id, $context);

                return [
                    'type' => 'conditional',
                    'status' => 'success',
                    'branch_index' => $index,
                    'branch_type' => 'else',
                    'actions_executed' => $branch_results,
                ];
            }

            // Evaluate conditions
            $condition_group = [
                'logic' => $conditions['logic'] ?? 'AND',
                'rules' => $conditions['rules'] ?? $conditions,
            ];

            // Normalize if conditions is just an array of rules
            if (isset($conditions[0]) && !isset($conditions['logic'])) {
                $condition_group = [
                    'logic' => 'AND',
                    'rules' => $conditions,
                ];
            }

            if ($this->conditions->evaluate($condition_group, $post_id, $context)) {
                // Execute this branch's actions
                $branch_actions = $branch['actions'] ?? [];
                $branch_results = $this->execute_actions($branch_actions, $post_id, $context);

                return [
                    'type' => 'conditional',
                    'status' => 'success',
                    'branch_index' => $index,
                    'branch_type' => $index === 0 ? 'if' : 'else_if',
                    'actions_executed' => $branch_results,
                ];
            }
        }

        // No branch matched
        return [
            'type' => 'conditional',
            'status' => 'success',
            'branch_index' => -1,
            'branch_type' => 'none',
            'message' => __('No branch conditions matched', 'pds-post-tables'),
        ];
    }

    /**
     * Resolve a field value based on value_type
     */
    private function resolve_field_value($action, $post_id, $context) {
        $value_type = $action['value_type'] ?? 'static';
        $value = $action['value'] ?? '';

        switch ($value_type) {
            case 'static':
                return $value;

            case 'dynamic':
                // Value is a placeholder reference
                return $this->placeholders->get_placeholder_value(
                    trim($value, '{}'),
                    $post_id,
                    $context
                );

            case 'formula':
                return $this->resolve_formula_value($value, $post_id, $context);

            case 'conditional':
                return $this->resolve_conditional_field_value($action, $post_id, $context);

            default:
                return $this->placeholders->parse($value, $post_id, $context);
        }
    }

    /**
     * Resolve a formula value
     */
    private function resolve_formula_value($formula, $post_id, $context) {
        $formula = strtoupper(trim($formula));

        // NOW() - current datetime
        if ($formula === 'NOW()' || $formula === 'NOW') {
            return current_time('mysql');
        }

        // TODAY() - current date
        if ($formula === 'TODAY()' || $formula === 'TODAY') {
            return current_time('Y-m-d');
        }

        // Parse as placeholder with formula
        return $this->placeholders->parse('{{' . $formula . '}}', $post_id, $context);
    }

    /**
     * Resolve a conditional field value
     */
    private function resolve_conditional_field_value($action, $post_id, $context) {
        $conditions = $action['conditions'] ?? [];

        foreach ($conditions as $condition) {
            $if_conditions = $condition['if'] ?? [];
            $then_value = $condition['then'] ?? '';

            if (empty($if_conditions)) {
                // This is the else case
                if (isset($condition['else'])) {
                    return $this->placeholders->parse($condition['else'], $post_id, $context);
                }
                continue;
            }

            $condition_group = [
                'logic' => 'AND',
                'rules' => is_array($if_conditions) ? $if_conditions : [$if_conditions],
            ];

            if ($this->conditions->evaluate($condition_group, $post_id, $context)) {
                return $this->placeholders->parse($then_value, $post_id, $context);
            }
        }

        return '';
    }

    /**
     * Resolve a conditional value (for recipients, subject, etc.)
     */
    private function resolve_conditional_value($value, $post_id, $context) {
        // If value is an array with 'type' => 'conditional', resolve it
        if (is_array($value) && ($value['type'] ?? '') === 'conditional') {
            $branches = $value['branches'] ?? [];

            foreach ($branches as $branch) {
                $conditions = $branch['conditions'] ?? [];

                // Empty conditions = else branch
                if (empty($conditions)) {
                    return $branch['value'] ?? '';
                }

                $condition_group = [
                    'logic' => $conditions['logic'] ?? 'AND',
                    'rules' => $conditions['rules'] ?? $conditions,
                ];

                if (isset($conditions[0]) && !isset($conditions['logic'])) {
                    $condition_group = [
                        'logic' => 'AND',
                        'rules' => $conditions,
                    ];
                }

                if ($this->conditions->evaluate($condition_group, $post_id, $context)) {
                    return $branch['value'] ?? '';
                }
            }

            return '';
        }

        return $value;
    }

    /**
     * Parse a recipient list string into array of valid emails
     */
    private function parse_recipient_list($recipients) {
        if (is_array($recipients)) {
            $list = $recipients;
        } else {
            $list = array_map('trim', explode(',', $recipients));
        }

        return array_filter($list, function($email) {
            return is_email($email);
        });
    }

    /**
     * Wrap email body in HTML template
     */
    private function wrap_html_email($body, $subject) {
        $site_name = get_bloginfo('name');

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($subject) . '</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        h1, h2, h3 { color: #1a1a1a; }
        a { color: #0066cc; }
        table { border-collapse: collapse; width: 100%; }
        th, td { padding: 8px 12px; text-align: left; border: 1px solid #ddd; }
        th { background: #f5f5f5; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    ' . wp_kses_post($body) . '
    <div class="footer">
        <p>' . sprintf(__('This email was sent by %s', 'pds-post-tables'), esc_html($site_name)) . '</p>
    </div>
</body>
</html>';
    }

    /**
     * Send consolidated email for multiple triggered items
     */
    public function send_consolidated_email($action, $items, $context = []) {
        $consolidation = $action['consolidation'] ?? [];

        if (empty($items)) {
            return [
                'type' => 'send_email_consolidated',
                'status' => 'error',
                'message' => __('No items to consolidate', 'pds-post-tables'),
            ];
        }

        // Build consolidated context
        $consolidated_context = array_merge($context, [
            'count' => count($items),
            'items_list' => PDS_Post_Tables_Automation_Placeholders::generate_items_list(
                $items,
                $consolidation['item_template'] ?? '• {{post_title}}'
            ),
            'items_table' => PDS_Post_Tables_Automation_Placeholders::generate_items_table(
                $items,
                $consolidation['table_columns'] ?? ['post_title', 'post_date']
            ),
        ]);

        // Use first item's post_id for base placeholder resolution
        $first_item = reset($items);
        $base_post_id = is_array($first_item) ? ($first_item['post_id'] ?? $first_item['ID'] ?? 0) : $first_item;

        // Build consolidated action
        $consolidated_action = [
            'type' => 'send_email',
            'recipients' => $consolidation['recipients'] ?? $action['recipients'] ?? '',
            'subject' => $consolidation['subject'] ?? $consolidation['consolidated_subject'] ?? $action['subject'] ?? '',
            'body' => $consolidation['body'] ?? $consolidation['consolidated_body'] ?? $action['body'] ?? '',
            'html' => $action['html'] ?? true,
        ];

        return $this->execute_send_email($consolidated_action, $base_post_id, $consolidated_context);
    }

    /**
     * Get available action types
     */
    public static function get_action_types() {
        return [
            'send_email' => [
                'label' => __('Send Email', 'pds-post-tables'),
                'description' => __('Send an email notification', 'pds-post-tables'),
                'icon' => 'dashicons-email',
            ],
            'update_field' => [
                'label' => __('Update Field', 'pds-post-tables'),
                'description' => __('Set a field to a specific value', 'pds-post-tables'),
                'icon' => 'dashicons-edit',
            ],
            'copy_field' => [
                'label' => __('Copy Field', 'pds-post-tables'),
                'description' => __('Copy value from one field to another', 'pds-post-tables'),
                'icon' => 'dashicons-admin-page',
            ],
            'clear_field' => [
                'label' => __('Clear Field', 'pds-post-tables'),
                'description' => __('Clear/empty a field value', 'pds-post-tables'),
                'icon' => 'dashicons-dismiss',
            ],
            'increment_field' => [
                'label' => __('Increment Field', 'pds-post-tables'),
                'description' => __('Add to a numeric field', 'pds-post-tables'),
                'icon' => 'dashicons-plus-alt',
            ],
            'change_status' => [
                'label' => __('Change Post Status', 'pds-post-tables'),
                'description' => __('Change the post status', 'pds-post-tables'),
                'icon' => 'dashicons-flag',
            ],
            'conditional' => [
                'label' => __('Conditional (If/Else)', 'pds-post-tables'),
                'description' => __('Execute different actions based on conditions', 'pds-post-tables'),
                'icon' => 'dashicons-randomize',
            ],
            'stop' => [
                'label' => __('Stop Processing', 'pds-post-tables'),
                'description' => __('Stop executing further actions', 'pds-post-tables'),
                'icon' => 'dashicons-controls-pause',
            ],
        ];
    }
}
