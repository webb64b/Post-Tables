<?php
/**
 * Automation History - Logs and tracks automation executions
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Automation_History {

    /**
     * Post type for log entries
     */
    const LOG_POST_TYPE = 'pds_automation_log';

    /**
     * Option key for execution tracking
     */
    const EXECUTION_TRACKING_OPTION = 'pds_automation_executions';

    /**
     * Maximum log entries to keep per automation
     */
    const MAX_LOG_ENTRIES = 100;

    /**
     * Register the log post type
     */
    public function __construct() {
        add_action('init', [$this, 'register_log_post_type']);
    }

    /**
     * Register the automation log post type
     */
    public function register_log_post_type() {
        $args = [
            'labels' => [
                'name' => __('Automation Logs', 'pds-post-tables'),
                'singular_name' => __('Automation Log', 'pds-post-tables'),
            ],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'query_var' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'supports' => ['title'],
        ];

        register_post_type(self::LOG_POST_TYPE, $args);
    }

    /**
     * Log an automation execution
     *
     * @param array $execution Execution data
     * @return int|false Log entry ID or false on failure
     */
    public function log_execution($execution) {
        $automation_id = $execution['automation_id'] ?? 0;
        $automation_name = $execution['automation_name'] ?? '';

        // Create log entry title
        $title = sprintf(
            '[%s] %s - %s',
            $execution['status'] ?? 'unknown',
            $automation_name,
            $execution['triggered_at'] ?? current_time('mysql')
        );

        // Create the log post
        $log_id = wp_insert_post([
            'post_type' => self::LOG_POST_TYPE,
            'post_title' => $title,
            'post_status' => 'publish',
            'post_date' => $execution['triggered_at'] ?? current_time('mysql'),
        ]);

        if (is_wp_error($log_id)) {
            return false;
        }

        // Store execution data as post meta
        update_post_meta($log_id, '_pds_log_automation_id', $automation_id);
        update_post_meta($log_id, '_pds_log_execution_data', $execution);
        update_post_meta($log_id, '_pds_log_status', $execution['status'] ?? 'unknown');
        update_post_meta($log_id, '_pds_log_post_id', $execution['post_id'] ?? 0);
        update_post_meta($log_id, '_pds_log_trigger_type', $execution['trigger_type'] ?? '');
        update_post_meta($log_id, '_pds_log_duration_ms', $execution['duration_ms'] ?? 0);

        // Track execution for duplicate prevention
        if (!empty($execution['post_id'])) {
            $this->track_execution($automation_id, $execution['post_id'], $execution);
        }

        // Cleanup old logs
        $this->cleanup_old_logs($automation_id);

        return $log_id;
    }

    /**
     * Log a warning (e.g., loop prevention)
     */
    public function log_warning($automation_id, $post_id, $message) {
        $this->log_execution([
            'automation_id' => $automation_id,
            'automation_name' => get_the_title($automation_id),
            'post_id' => $post_id,
            'triggered_at' => current_time('mysql'),
            'status' => 'warning',
            'message' => $message,
        ]);
    }

    /**
     * Track that an automation has run for a specific post
     */
    private function track_execution($automation_id, $post_id, $execution) {
        $tracking_key = $this->get_tracking_key($automation_id, $post_id, $execution);

        $tracking = get_option(self::EXECUTION_TRACKING_OPTION, []);

        $tracking[$tracking_key] = [
            'automation_id' => $automation_id,
            'post_id' => $post_id,
            'executed_at' => current_time('mysql'),
            'trigger_date' => $execution['trigger_date'] ?? null,
        ];

        // Cleanup old tracking entries (older than 30 days)
        $cutoff = strtotime('-30 days');
        $tracking = array_filter($tracking, function($entry) use ($cutoff) {
            return strtotime($entry['executed_at']) > $cutoff;
        });

        update_option(self::EXECUTION_TRACKING_OPTION, $tracking, false);
    }

    /**
     * Check if automation has already run for a post
     */
    public function has_run_for_post($automation_id, $post_id, $context = []) {
        $tracking_key = $this->get_tracking_key($automation_id, $post_id, $context);

        $tracking = get_option(self::EXECUTION_TRACKING_OPTION, []);

        return isset($tracking[$tracking_key]);
    }

    /**
     * Get tracking key for duplicate detection
     */
    private function get_tracking_key($automation_id, $post_id, $context) {
        // Include trigger date if available (for date-based triggers)
        $trigger_date = $context['trigger_date'] ?? '';

        return md5("{$automation_id}_{$post_id}_{$trigger_date}");
    }

    /**
     * Get execution history for an automation
     *
     * @param int $automation_id Automation ID
     * @param array $args Query arguments
     * @return array Log entries
     */
    public function get_history($automation_id = null, $args = []) {
        $defaults = [
            'limit' => 50,
            'offset' => 0,
            'status' => null,
            'post_id' => null,
            'date_from' => null,
            'date_to' => null,
        ];

        $args = wp_parse_args($args, $defaults);

        $query_args = [
            'post_type' => self::LOG_POST_TYPE,
            'posts_per_page' => $args['limit'],
            'offset' => $args['offset'],
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [],
        ];

        if ($automation_id) {
            $query_args['meta_query'][] = [
                'key' => '_pds_log_automation_id',
                'value' => $automation_id,
            ];
        }

        if ($args['status']) {
            $query_args['meta_query'][] = [
                'key' => '_pds_log_status',
                'value' => $args['status'],
            ];
        }

        if ($args['post_id']) {
            $query_args['meta_query'][] = [
                'key' => '_pds_log_post_id',
                'value' => $args['post_id'],
            ];
        }

        if ($args['date_from']) {
            $query_args['date_query'][] = [
                'after' => $args['date_from'],
                'inclusive' => true,
            ];
        }

        if ($args['date_to']) {
            $query_args['date_query'][] = [
                'before' => $args['date_to'],
                'inclusive' => true,
            ];
        }

        $query = new WP_Query($query_args);
        $entries = [];

        foreach ($query->posts as $log_post) {
            $execution_data = get_post_meta($log_post->ID, '_pds_log_execution_data', true);

            $entries[] = [
                'id' => $log_post->ID,
                'automation_id' => get_post_meta($log_post->ID, '_pds_log_automation_id', true),
                'post_id' => get_post_meta($log_post->ID, '_pds_log_post_id', true),
                'status' => get_post_meta($log_post->ID, '_pds_log_status', true),
                'trigger_type' => get_post_meta($log_post->ID, '_pds_log_trigger_type', true),
                'duration_ms' => get_post_meta($log_post->ID, '_pds_log_duration_ms', true),
                'triggered_at' => $log_post->post_date,
                'execution_data' => $execution_data,
            ];
        }

        return [
            'entries' => $entries,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
        ];
    }

    /**
     * Get statistics for an automation
     */
    public function get_stats($automation_id) {
        global $wpdb;

        $stats = [
            'total_runs' => 0,
            'successful_runs' => 0,
            'failed_runs' => 0,
            'average_duration_ms' => 0,
            'last_run' => null,
            'last_success' => null,
            'last_failure' => null,
        ];

        // Get total runs
        $stats['total_runs'] = get_post_meta($automation_id, '_pds_automation_run_count', true) ?: 0;

        // Get status counts
        $log_ids = get_posts([
            'post_type' => self::LOG_POST_TYPE,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_pds_log_automation_id',
                    'value' => $automation_id,
                ],
            ],
        ]);

        foreach ($log_ids as $log_id) {
            $status = get_post_meta($log_id, '_pds_log_status', true);

            if ($status === 'success') {
                $stats['successful_runs']++;
            } elseif ($status === 'error' || $status === 'partial') {
                $stats['failed_runs']++;
            }
        }

        // Get last run
        $stats['last_run'] = get_post_meta($automation_id, '_pds_automation_last_run', true);

        // Get last success
        $last_success = get_posts([
            'post_type' => self::LOG_POST_TYPE,
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_pds_log_automation_id',
                    'value' => $automation_id,
                ],
                [
                    'key' => '_pds_log_status',
                    'value' => 'success',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (!empty($last_success)) {
            $stats['last_success'] = $last_success[0]->post_date;
        }

        // Get last failure
        $last_failure = get_posts([
            'post_type' => self::LOG_POST_TYPE,
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_pds_log_automation_id',
                    'value' => $automation_id,
                ],
                [
                    'key' => '_pds_log_status',
                    'value' => ['error', 'partial'],
                    'compare' => 'IN',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (!empty($last_failure)) {
            $stats['last_failure'] = $last_failure[0]->post_date;
        }

        // Calculate average duration
        if (!empty($log_ids)) {
            $total_duration = 0;
            foreach ($log_ids as $log_id) {
                $duration = get_post_meta($log_id, '_pds_log_duration_ms', true);
                $total_duration += intval($duration);
            }
            $stats['average_duration_ms'] = round($total_duration / count($log_ids));
        }

        return $stats;
    }

    /**
     * Clean up old log entries for an automation
     */
    private function cleanup_old_logs($automation_id) {
        $log_ids = get_posts([
            'post_type' => self::LOG_POST_TYPE,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => '_pds_log_automation_id',
                    'value' => $automation_id,
                ],
            ],
        ]);

        // Delete logs beyond the limit
        if (count($log_ids) > self::MAX_LOG_ENTRIES) {
            $to_delete = array_slice($log_ids, self::MAX_LOG_ENTRIES);

            foreach ($to_delete as $log_id) {
                wp_delete_post($log_id, true);
            }
        }
    }

    /**
     * Delete all logs for an automation
     */
    public function delete_automation_logs($automation_id) {
        $log_ids = get_posts([
            'post_type' => self::LOG_POST_TYPE,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_pds_log_automation_id',
                    'value' => $automation_id,
                ],
            ],
        ]);

        foreach ($log_ids as $log_id) {
            wp_delete_post($log_id, true);
        }

        // Also clean up tracking entries
        $tracking = get_option(self::EXECUTION_TRACKING_OPTION, []);
        $tracking = array_filter($tracking, function($entry) use ($automation_id) {
            return $entry['automation_id'] != $automation_id;
        });
        update_option(self::EXECUTION_TRACKING_OPTION, $tracking, false);
    }

    /**
     * Clear all execution tracking (useful for re-running automations)
     */
    public function clear_execution_tracking($automation_id = null) {
        if ($automation_id) {
            $tracking = get_option(self::EXECUTION_TRACKING_OPTION, []);
            $tracking = array_filter($tracking, function($entry) use ($automation_id) {
                return $entry['automation_id'] != $automation_id;
            });
            update_option(self::EXECUTION_TRACKING_OPTION, $tracking, false);
        } else {
            delete_option(self::EXECUTION_TRACKING_OPTION);
        }
    }

    /**
     * Get recent activity across all automations
     */
    public function get_recent_activity($limit = 20) {
        return $this->get_history(null, ['limit' => $limit]);
    }

    /**
     * Export logs to CSV
     */
    public function export_to_csv($automation_id = null, $date_from = null, $date_to = null) {
        $history = $this->get_history($automation_id, [
            'limit' => -1,
            'date_from' => $date_from,
            'date_to' => $date_to,
        ]);

        $csv_data = [];

        // Header row
        $csv_data[] = [
            'ID',
            'Automation ID',
            'Automation Name',
            'Post ID',
            'Post Title',
            'Status',
            'Trigger Type',
            'Duration (ms)',
            'Triggered At',
        ];

        foreach ($history['entries'] as $entry) {
            $post = get_post($entry['post_id']);
            $automation = get_post($entry['automation_id']);

            $csv_data[] = [
                $entry['id'],
                $entry['automation_id'],
                $automation ? $automation->post_title : '',
                $entry['post_id'],
                $post ? $post->post_title : '',
                $entry['status'],
                $entry['trigger_type'],
                $entry['duration_ms'],
                $entry['triggered_at'],
            ];
        }

        return $csv_data;
    }
}
