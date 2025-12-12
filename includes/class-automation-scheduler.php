<?php
/**
 * Automation Scheduler - Manages WP Cron for scheduled automations
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Automation_Scheduler {

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'pds_automation_scheduled_check';

    /**
     * Cron interval name
     */
    const CRON_INTERVAL = 'pds_every_five_minutes';

    /**
     * Automation engine instance
     */
    private $engine;

    public function __construct($engine = null) {
        $this->engine = $engine;

        // Add custom cron interval
        add_filter('cron_schedules', [$this, 'add_cron_interval']);

        // Register cron hook
        add_action(self::CRON_HOOK, [$this, 'run_scheduled_check']);

        // Handle plugin activation/deactivation
        register_activation_hook(PDS_POST_TABLES_PATH . 'pds-post-tables.php', [$this, 'activate']);
        register_deactivation_hook(PDS_POST_TABLES_PATH . 'pds-post-tables.php', [$this, 'deactivate']);

        // Ensure cron is scheduled on init
        add_action('init', [$this, 'maybe_schedule_cron']);
    }

    /**
     * Set the engine instance
     */
    public function set_engine($engine) {
        $this->engine = $engine;
    }

    /**
     * Add custom cron interval
     */
    public function add_cron_interval($schedules) {
        $schedules[self::CRON_INTERVAL] = [
            'interval' => 300, // 5 minutes
            'display' => __('Every 5 Minutes', 'pds-post-tables'),
        ];

        return $schedules;
    }

    /**
     * Schedule cron on plugin activation
     */
    public function activate() {
        $this->schedule_cron();
    }

    /**
     * Unschedule cron on plugin deactivation
     */
    public function deactivate() {
        $this->unschedule_cron();
    }

    /**
     * Schedule the cron job
     */
    public function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    /**
     * Unschedule the cron job
     */
    public function unschedule_cron() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Ensure cron is scheduled (runs on init)
     */
    public function maybe_schedule_cron() {
        // Check if there are any enabled automations with scheduled triggers
        $automations = PDS_Post_Tables_Automation_Post_Type::get_enabled_automations();
        $has_scheduled = false;

        foreach ($automations as $automation) {
            $trigger_type = $automation['trigger']['type'] ?? '';
            if (PDS_Post_Tables_Automation_Triggers::is_scheduled_trigger($trigger_type)) {
                $has_scheduled = true;
                break;
            }
        }

        if ($has_scheduled) {
            $this->schedule_cron();
        }
    }

    /**
     * Run the scheduled check (called by cron)
     */
    public function run_scheduled_check() {
        // Log that we're running
        $this->log_cron_run('started');

        if (!$this->engine) {
            $this->log_cron_run('error', 'Engine not initialized');
            return;
        }

        try {
            // Run scheduled automations
            $results = $this->engine->run_scheduled_automations();

            // Log results
            $this->log_cron_run('completed', [
                'automations_run' => count($results),
                'results' => $results,
            ]);

        } catch (Exception $e) {
            $this->log_cron_run('error', $e->getMessage());
        }
    }

    /**
     * Log cron run for debugging
     */
    private function log_cron_run($status, $data = null) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $log_entry = [
            'time' => current_time('mysql'),
            'status' => $status,
            'data' => $data,
        ];

        // Get existing log
        $log = get_option('pds_automation_cron_log', []);

        // Add new entry
        $log[] = $log_entry;

        // Keep only last 100 entries
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }

        update_option('pds_automation_cron_log', $log, false);
    }

    /**
     * Get cron log entries
     */
    public static function get_cron_log($limit = 50) {
        $log = get_option('pds_automation_cron_log', []);
        return array_slice($log, -$limit);
    }

    /**
     * Clear cron log
     */
    public static function clear_cron_log() {
        delete_option('pds_automation_cron_log');
    }

    /**
     * Get cron status information
     */
    public static function get_cron_status() {
        $next_run = wp_next_scheduled(self::CRON_HOOK);

        return [
            'scheduled' => $next_run !== false,
            'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : null,
            'next_run_human' => $next_run ? human_time_diff($next_run) : null,
            'interval' => 300,
            'interval_human' => __('Every 5 minutes', 'pds-post-tables'),
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        ];
    }

    /**
     * Manually trigger scheduled check (for testing)
     */
    public static function trigger_manual_run() {
        do_action(self::CRON_HOOK);
    }

    /**
     * Reschedule cron with a different interval
     */
    public function reschedule($interval = null) {
        $this->unschedule_cron();

        if ($interval === null) {
            $this->schedule_cron();
        } else {
            // Schedule with custom interval
            wp_schedule_event(time(), $interval, self::CRON_HOOK);
        }
    }

    /**
     * Get available schedule frequencies
     */
    public static function get_frequencies() {
        return [
            'every_5_minutes' => [
                'label' => __('Every 5 Minutes', 'pds-post-tables'),
                'interval' => 300,
            ],
            'hourly' => [
                'label' => __('Hourly', 'pds-post-tables'),
                'interval' => 3600,
            ],
            'daily' => [
                'label' => __('Daily', 'pds-post-tables'),
                'interval' => 86400,
            ],
        ];
    }
}
