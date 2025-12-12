<?php
/**
 * Automation Engine - Main orchestrator for automation execution
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Automation_Engine {

    /**
     * Data handler instance
     */
    private $data_handler;

    /**
     * Field scanner instance
     */
    private $field_scanner;

    /**
     * Condition evaluator
     */
    private $conditions;

    /**
     * Placeholder parser
     */
    private $placeholders;

    /**
     * Trigger handler
     */
    private $triggers;

    /**
     * Action executor
     */
    private $actions;

    /**
     * History logger
     */
    private $history;

    /**
     * Execution stack for loop prevention
     */
    private static $execution_stack = [];

    /**
     * Flag to indicate if we're currently executing
     */
    private static $executing = false;

    public function __construct($data_handler, $field_scanner) {
        $this->data_handler = $data_handler;
        $this->field_scanner = $field_scanner;

        // Initialize components
        $this->conditions = new PDS_Post_Tables_Automation_Conditions($data_handler, $field_scanner);
        $this->placeholders = new PDS_Post_Tables_Automation_Placeholders($data_handler, $this->conditions);
        $this->triggers = new PDS_Post_Tables_Automation_Triggers($data_handler, $this->conditions);
        $this->history = new PDS_Post_Tables_Automation_History();
        $this->actions = new PDS_Post_Tables_Automation_Actions(
            $data_handler,
            $this->placeholders,
            $this->conditions,
            $this->history
        );

        // Register hooks for realtime triggers
        $this->register_hooks();
    }

    /**
     * Register WordPress hooks for realtime triggers
     */
    private function register_hooks() {
        // Post created trigger
        add_action('wp_insert_post', [$this, 'on_post_created'], 100, 3);

        // Field change trigger (hook into the realtime sync log_change)
        add_action('pds_field_changed', [$this, 'on_field_changed'], 10, 1);

        // Also hook into save_post for external changes (outside of Post Tables)
        add_action('save_post', [$this, 'on_post_saved'], 100, 3);
    }

    /**
     * Handle post created
     */
    public function on_post_created($post_id, $post, $update) {
        // Skip if this is an update, not a new post
        if ($update) {
            return;
        }

        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Skip our own post types
        if (in_array($post->post_type, ['pds_post_table', 'pds_automation', 'pds_automation_log'])) {
            return;
        }

        // Skip if we're already executing to prevent loops
        if (self::$executing) {
            return;
        }

        // Get automations for this post type with post_created trigger
        $automations = PDS_Post_Tables_Automation_Post_Type::get_automations_for_post_type(
            $post->post_type,
            'post_created'
        );

        if (empty($automations)) {
            return;
        }

        $context = [
            'is_new_post' => true,
            'trigger_source' => 'post_created',
        ];

        foreach ($automations as $automation) {
            $this->maybe_execute($automation, $post_id, $context);
        }
    }

    /**
     * Handle field changed (from Post Tables UI)
     */
    public function on_field_changed($change_data) {
        $table_id = $change_data['table_id'] ?? 0;
        $post_id = $change_data['post_id'] ?? 0;
        $field_key = $change_data['field_key'] ?? '';
        $old_value = $change_data['old_value'] ?? null;
        $new_value = $change_data['new_value'] ?? null;

        if (!$post_id || !$field_key) {
            return;
        }

        // Skip if we're already executing to prevent loops
        if (self::$executing) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        // Get the user who made the change
        $user_id = get_current_user_id();
        $user = get_userdata($user_id);

        $context = [
            'changed_field' => $field_key,
            'old_value' => $old_value,
            'new_value' => $new_value,
            'changed_by' => $user ? $user->display_name : '',
            'changed_by_email' => $user ? $user->user_email : '',
            'change_timestamp' => current_time('mysql'),
            'trigger_source' => 'post_tables',
            'table_id' => $table_id,
        ];

        // Get automations for field change triggers
        $automations = PDS_Post_Tables_Automation_Post_Type::get_automations_for_post_type($post->post_type);

        foreach ($automations as $automation) {
            $trigger_type = $automation['trigger']['type'] ?? '';

            // Only process field change triggers
            if (!in_array($trigger_type, ['field_changed', 'field_changed_to', 'field_changed_from', 'field_transition'])) {
                continue;
            }

            $this->maybe_execute($automation, $post_id, $context);
        }
    }

    /**
     * Handle post saved (for external changes)
     */
    public function on_post_saved($post_id, $post, $update) {
        // Only process updates
        if (!$update) {
            return;
        }

        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Skip our own post types
        if (in_array($post->post_type, ['pds_post_table', 'pds_automation', 'pds_automation_log'])) {
            return;
        }

        // Skip if we're already executing to prevent loops
        if (self::$executing) {
            return;
        }

        // Get automations that want to track external changes
        $automations = PDS_Post_Tables_Automation_Post_Type::get_automations_for_post_type($post->post_type);

        foreach ($automations as $automation) {
            // Check if automation wants external changes
            if (empty($automation['trigger']['include_external_changes'])) {
                continue;
            }

            $trigger_type = $automation['trigger']['type'] ?? '';

            // For post_updated trigger
            if ($trigger_type === 'post_updated') {
                $context = [
                    'is_update' => true,
                    'trigger_source' => 'external',
                ];
                $this->maybe_execute($automation, $post_id, $context);
            }

            // For field change triggers, we'd need to track old values
            // This is more complex and would require storing snapshots
            // For now, external field change detection is limited
        }
    }

    /**
     * Execute automation if trigger conditions are met
     *
     * @param array $automation Automation config
     * @param int $post_id Post ID
     * @param array $context Execution context
     * @return array|false Execution result or false if not triggered
     */
    public function maybe_execute($automation, $post_id, $context = []) {
        $automation_id = $automation['id'] ?? 0;

        // Check if automation is enabled
        if (empty($automation['enabled'])) {
            return false;
        }

        // Loop prevention
        $execution_key = "{$automation_id}_{$post_id}";
        if (in_array($execution_key, self::$execution_stack)) {
            $this->history->log_warning($automation_id, $post_id, 'Loop prevented');
            return false;
        }

        // Check trigger conditions
        $trigger_context = $this->triggers->check_trigger(
            $automation['trigger'],
            $post_id,
            $context
        );

        if ($trigger_context === false) {
            return false;
        }

        // Merge contexts
        $full_context = array_merge($context, is_array($trigger_context) ? $trigger_context : []);
        $full_context['automation_id'] = $automation_id;
        $full_context['automation_name'] = $automation['name'] ?? '';

        // Check run_once_per_post setting
        if (!empty($automation['settings']['run_once_per_post'])) {
            if ($this->history->has_run_for_post($automation_id, $post_id, $full_context)) {
                return false;
            }
        }

        // Execute the automation
        return $this->execute($automation, $post_id, $full_context);
    }

    /**
     * Execute an automation
     *
     * @param array $automation Automation config
     * @param int $post_id Post ID
     * @param array $context Execution context
     * @return array Execution result
     */
    public function execute($automation, $post_id, $context = []) {
        $automation_id = $automation['id'] ?? 0;
        $execution_key = "{$automation_id}_{$post_id}";

        // Add to execution stack
        self::$execution_stack[] = $execution_key;
        self::$executing = true;

        $start_time = microtime(true);

        $result = [
            'automation_id' => $automation_id,
            'automation_name' => $automation['name'] ?? '',
            'post_id' => $post_id,
            'triggered_at' => current_time('mysql'),
            'trigger_type' => $automation['trigger']['type'] ?? '',
            'status' => 'success',
            'actions_executed' => [],
        ];

        try {
            // Execute actions
            $actions = $automation['actions'] ?? [];
            $action_results = $this->actions->execute_actions($actions, $post_id, $context, $automation);

            $result['actions_executed'] = $action_results;

            // Check if any actions failed
            $has_errors = false;
            foreach ($action_results as $action_result) {
                if (($action_result['status'] ?? '') === 'error') {
                    $has_errors = true;
                    break;
                }
            }

            if ($has_errors) {
                $result['status'] = 'partial';
            }

        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }

        // Calculate duration
        $result['duration_ms'] = round((microtime(true) - $start_time) * 1000);

        // Remove from execution stack
        array_pop(self::$execution_stack);
        self::$executing = empty(self::$execution_stack);

        // Log execution
        if (!empty($automation['settings']['log_executions'])) {
            $this->history->log_execution($result);
        }

        // Update run count
        PDS_Post_Tables_Automation_Post_Type::increment_run_count($automation_id);

        // Fire action for extensibility
        do_action('pds_automation_executed', $result, $automation, $post_id, $context);

        return $result;
    }

    /**
     * Execute scheduled automations (called by cron)
     *
     * @return array Results of all executions
     */
    public function run_scheduled_automations() {
        $results = [];

        // Get all enabled automations with scheduled triggers
        $automations = PDS_Post_Tables_Automation_Post_Type::get_enabled_automations();

        foreach ($automations as $automation) {
            $trigger_type = $automation['trigger']['type'] ?? '';

            // Skip realtime triggers
            if (PDS_Post_Tables_Automation_Triggers::is_realtime_trigger($trigger_type)) {
                continue;
            }

            // Check if it's time to run this automation
            if (!$this->should_run_now($automation)) {
                continue;
            }

            // Get posts matching the trigger
            $post_ids = $this->triggers->get_posts_for_date_trigger($automation);

            if (empty($post_ids)) {
                continue;
            }

            // Check consolidation settings
            $consolidation = $automation['actions'][0]['consolidation'] ?? [];
            $should_consolidate = !empty($consolidation['enabled']) &&
                                  count($post_ids) >= ($consolidation['threshold'] ?? 2);

            if ($should_consolidate) {
                // Send consolidated email
                $result = $this->execute_consolidated($automation, $post_ids);
                $results[] = $result;
            } else {
                // Execute for each post individually
                foreach ($post_ids as $post_id) {
                    $context = [
                        'trigger_source' => 'scheduled',
                    ];

                    $result = $this->maybe_execute($automation, $post_id, $context);
                    if ($result) {
                        $results[] = $result;
                    }
                }
            }

            // Update next run time
            $schedule = $automation['schedule'] ?? [];
            if (!empty($schedule)) {
                $next_run = PDS_Post_Tables_Automation_Post_Type::calculate_next_run($schedule);
                update_post_meta($automation['id'], '_pds_automation_next_run', $next_run);
            }
        }

        return $results;
    }

    /**
     * Execute consolidated action for multiple posts
     */
    private function execute_consolidated($automation, $post_ids) {
        $automation_id = $automation['id'] ?? 0;

        $result = [
            'automation_id' => $automation_id,
            'automation_name' => $automation['name'] ?? '',
            'post_ids' => $post_ids,
            'post_count' => count($post_ids),
            'triggered_at' => current_time('mysql'),
            'trigger_type' => $automation['trigger']['type'] ?? '',
            'consolidated' => true,
            'status' => 'success',
        ];

        $start_time = microtime(true);

        try {
            // Build items array for consolidation
            $items = [];
            foreach ($post_ids as $post_id) {
                $items[] = [
                    'post_id' => $post_id,
                    'ID' => $post_id,
                ];
            }

            // Find email action with consolidation
            $actions = $automation['actions'] ?? [];
            foreach ($actions as $action) {
                if (($action['type'] ?? '') === 'send_email' && !empty($action['consolidation']['enabled'])) {
                    $email_result = $this->actions->send_consolidated_email($action, $items);
                    $result['email_result'] = $email_result;

                    if (($email_result['status'] ?? '') !== 'success') {
                        $result['status'] = 'error';
                    }
                    break;
                }
            }

        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }

        $result['duration_ms'] = round((microtime(true) - $start_time) * 1000);

        // Log execution
        if (!empty($automation['settings']['log_executions'])) {
            $this->history->log_execution($result);
        }

        // Update run count
        PDS_Post_Tables_Automation_Post_Type::increment_run_count($automation_id);

        return $result;
    }

    /**
     * Check if an automation should run now based on its schedule
     */
    private function should_run_now($automation) {
        $schedule = $automation['schedule'] ?? [];
        $frequency = $schedule['frequency'] ?? 'daily';

        // Check next_run timestamp
        $next_run = get_post_meta($automation['id'], '_pds_automation_next_run', true);

        if ($next_run) {
            $next_run_time = strtotime($next_run);
            if ($next_run_time > current_time('timestamp')) {
                return false;
            }
        }

        // For daily schedules, also check the time
        if ($frequency === 'daily') {
            $scheduled_time = $schedule['time'] ?? '09:00';
            $timezone = $schedule['timezone'] ?? wp_timezone_string();

            $tz = new DateTimeZone($timezone);
            $now = new DateTime('now', $tz);
            $scheduled = DateTime::createFromFormat('H:i', $scheduled_time, $tz);

            if ($scheduled === false) {
                return true; // Run if we can't parse the time
            }

            // Allow a 10-minute window
            $diff = abs($now->getTimestamp() - $scheduled->getTimestamp());
            return $diff <= 600; // 10 minutes
        }

        return true;
    }

    /**
     * Test an automation without actually executing actions
     *
     * @param array $automation Automation config
     * @param int $post_id Post ID to test against
     * @return array Test results
     */
    public function test_automation($automation, $post_id) {
        $result = [
            'automation_id' => $automation['id'] ?? 0,
            'post_id' => $post_id,
            'trigger_would_fire' => false,
            'trigger_details' => [],
            'conditions_met' => [],
            'actions_would_execute' => [],
        ];

        // Check trigger
        $trigger = $automation['trigger'] ?? [];
        $trigger_type = $trigger['type'] ?? '';

        // For date-based triggers, check if post would match
        if (PDS_Post_Tables_Automation_Triggers::is_scheduled_trigger($trigger_type)) {
            $matching_posts = $this->triggers->get_posts_for_date_trigger($automation);
            $result['trigger_would_fire'] = in_array($post_id, $matching_posts);
            $result['trigger_details']['matching_posts_count'] = count($matching_posts);
        }

        // Check conditions
        if (!empty($trigger['conditions'])) {
            $context = [];
            $conditions_result = $this->conditions->evaluate($trigger['conditions'], $post_id, $context);
            $result['conditions_met'] = [
                'passed' => $conditions_result,
                'conditions' => $trigger['conditions'],
            ];
        }

        // Preview actions
        $actions = $automation['actions'] ?? [];
        foreach ($actions as $index => $action) {
            $action_preview = [
                'index' => $index,
                'type' => $action['type'] ?? 'unknown',
                'would_execute' => true,
            ];

            // Check action conditions
            if (!empty($action['conditions'])) {
                $condition_group = [
                    'logic' => $action['conditions']['logic'] ?? 'AND',
                    'rules' => $action['conditions']['rules'] ?? $action['conditions'],
                ];
                $action_preview['would_execute'] = $this->conditions->evaluate($condition_group, $post_id, []);
            }

            // Preview placeholders
            if (($action['type'] ?? '') === 'send_email') {
                $action_preview['preview'] = [
                    'recipients' => $this->placeholders->parse($action['recipients'] ?? '', $post_id, []),
                    'subject' => $this->placeholders->parse($action['subject'] ?? '', $post_id, []),
                    'body_preview' => substr($this->placeholders->parse($action['body'] ?? '', $post_id, []), 0, 200) . '...',
                ];
            } elseif (($action['type'] ?? '') === 'update_field') {
                $action_preview['preview'] = [
                    'field' => $action['field_key'] ?? '',
                    'value' => $this->placeholders->parse($action['value'] ?? '', $post_id, []),
                ];
            }

            $result['actions_would_execute'][] = $action_preview;
        }

        return $result;
    }

    /**
     * Get component instances for external use
     */
    public function get_conditions() {
        return $this->conditions;
    }

    public function get_placeholders() {
        return $this->placeholders;
    }

    public function get_triggers() {
        return $this->triggers;
    }

    public function get_actions() {
        return $this->actions;
    }

    public function get_history() {
        return $this->history;
    }
}
