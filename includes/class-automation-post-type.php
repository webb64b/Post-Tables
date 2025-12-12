<?php
/**
 * Automation Post Type - Registers and manages the automation custom post type
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Automation_Post_Type {

    /**
     * Post type name
     */
    const POST_TYPE = 'pds_automation';

    /**
     * Meta keys
     */
    const META_TABLE_ID = '_pds_automation_table_id';
    const META_POST_TYPE = '_pds_automation_post_type';
    const META_ENABLED = '_pds_automation_enabled';
    const META_TRIGGER = '_pds_automation_trigger';
    const META_ACTIONS = '_pds_automation_actions';
    const META_SETTINGS = '_pds_automation_settings';
    const META_SCHEDULE = '_pds_automation_schedule';
    const META_LAST_RUN = '_pds_automation_last_run';
    const META_NEXT_RUN = '_pds_automation_next_run';
    const META_RUN_COUNT = '_pds_automation_run_count';

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save_meta_boxes'], 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'add_admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_columns'], 10, 2);
    }

    /**
     * Register the automation post type
     */
    public function register_post_type() {
        $labels = [
            'name'               => __('Automations', 'pds-post-tables'),
            'singular_name'      => __('Automation', 'pds-post-tables'),
            'menu_name'          => __('Automations', 'pds-post-tables'),
            'add_new'            => __('Add New', 'pds-post-tables'),
            'add_new_item'       => __('Add New Automation', 'pds-post-tables'),
            'edit_item'          => __('Edit Automation', 'pds-post-tables'),
            'new_item'           => __('New Automation', 'pds-post-tables'),
            'view_item'          => __('View Automation', 'pds-post-tables'),
            'search_items'       => __('Search Automations', 'pds-post-tables'),
            'not_found'          => __('No automations found', 'pds-post-tables'),
            'not_found_in_trash' => __('No automations found in trash', 'pds-post-tables'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=pds_post_table',
            'query_var'          => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'supports'           => ['title'],
            'show_in_rest'       => true,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'pds_automation_builder',
            __('Automation Builder', 'pds-post-tables'),
            [$this, 'render_builder_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'pds_automation_status',
            __('Status & History', 'pds-post-tables'),
            [$this, 'render_status_meta_box'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    /**
     * Render the automation builder meta box
     */
    public function render_builder_meta_box($post) {
        wp_nonce_field('pds_automation_save', 'pds_automation_nonce');

        $config = self::get_automation_config($post->ID);
        ?>
        <div id="pds-automation-builder"
             data-config="<?php echo esc_attr(wp_json_encode($config)); ?>"
             data-post-id="<?php echo esc_attr($post->ID); ?>">
            <div class="pds-automation-loading">
                <span class="spinner is-active"></span>
                <?php _e('Loading automation builder...', 'pds-post-tables'); ?>
            </div>
        </div>

        <!-- Hidden fields to store the actual data -->
        <input type="hidden" name="pds_automation_table_id" id="pds_automation_table_id"
               value="<?php echo esc_attr($config['table_id']); ?>">
        <input type="hidden" name="pds_automation_post_type" id="pds_automation_post_type"
               value="<?php echo esc_attr($config['post_type']); ?>">
        <input type="hidden" name="pds_automation_enabled" id="pds_automation_enabled"
               value="<?php echo esc_attr($config['enabled'] ? '1' : '0'); ?>">
        <input type="hidden" name="pds_automation_trigger" id="pds_automation_trigger"
               value="<?php echo esc_attr(wp_json_encode($config['trigger'])); ?>">
        <input type="hidden" name="pds_automation_actions" id="pds_automation_actions"
               value="<?php echo esc_attr(wp_json_encode($config['actions'])); ?>">
        <input type="hidden" name="pds_automation_settings" id="pds_automation_settings"
               value="<?php echo esc_attr(wp_json_encode($config['settings'])); ?>">
        <input type="hidden" name="pds_automation_schedule" id="pds_automation_schedule"
               value="<?php echo esc_attr(wp_json_encode($config['schedule'])); ?>">
        <?php
    }

    /**
     * Render the status meta box
     */
    public function render_status_meta_box($post) {
        $config = self::get_automation_config($post->ID);
        $last_run = get_post_meta($post->ID, self::META_LAST_RUN, true);
        $next_run = get_post_meta($post->ID, self::META_NEXT_RUN, true);
        $run_count = get_post_meta($post->ID, self::META_RUN_COUNT, true) ?: 0;
        ?>
        <div class="pds-automation-status-box">
            <p>
                <strong><?php _e('Status:', 'pds-post-tables'); ?></strong>
                <span class="pds-status-badge pds-status-<?php echo $config['enabled'] ? 'enabled' : 'disabled'; ?>">
                    <?php echo $config['enabled'] ? __('Enabled', 'pds-post-tables') : __('Disabled', 'pds-post-tables'); ?>
                </span>
            </p>

            <p>
                <strong><?php _e('Total Runs:', 'pds-post-tables'); ?></strong>
                <?php echo number_format($run_count); ?>
            </p>

            <?php if ($last_run) : ?>
            <p>
                <strong><?php _e('Last Run:', 'pds-post-tables'); ?></strong><br>
                <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_run)); ?>
            </p>
            <?php endif; ?>

            <?php if ($next_run && $config['enabled']) : ?>
            <p>
                <strong><?php _e('Next Run:', 'pds-post-tables'); ?></strong><br>
                <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($next_run)); ?>
            </p>
            <?php endif; ?>

            <hr>

            <p>
                <a href="<?php echo admin_url('edit.php?post_type=pds_post_table&page=pds-automation-history&automation_id=' . $post->ID); ?>" class="button">
                    <?php _e('View Execution History', 'pds-post-tables'); ?>
                </a>
            </p>

            <p>
                <button type="button" class="button pds-test-automation" data-automation-id="<?php echo $post->ID; ?>">
                    <?php _e('Test Automation', 'pds-post-tables'); ?>
                </button>
            </p>
        </div>
        <?php
    }

    /**
     * Save meta boxes
     */
    public function save_meta_boxes($post_id, $post) {
        // Verify nonce
        if (!isset($_POST['pds_automation_nonce']) || !wp_verify_nonce($_POST['pds_automation_nonce'], 'pds_automation_save')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save table ID
        if (isset($_POST['pds_automation_table_id'])) {
            update_post_meta($post_id, self::META_TABLE_ID, absint($_POST['pds_automation_table_id']));
        }

        // Save post type
        if (isset($_POST['pds_automation_post_type'])) {
            update_post_meta($post_id, self::META_POST_TYPE, sanitize_text_field($_POST['pds_automation_post_type']));
        }

        // Save enabled status
        $enabled = isset($_POST['pds_automation_enabled']) && $_POST['pds_automation_enabled'] === '1';
        update_post_meta($post_id, self::META_ENABLED, $enabled ? '1' : '0');

        // Save trigger
        if (isset($_POST['pds_automation_trigger'])) {
            $trigger = json_decode(stripslashes($_POST['pds_automation_trigger']), true);
            update_post_meta($post_id, self::META_TRIGGER, $trigger);
        }

        // Save actions
        if (isset($_POST['pds_automation_actions'])) {
            $actions = json_decode(stripslashes($_POST['pds_automation_actions']), true);
            update_post_meta($post_id, self::META_ACTIONS, $actions);
        }

        // Save settings
        if (isset($_POST['pds_automation_settings'])) {
            $settings = json_decode(stripslashes($_POST['pds_automation_settings']), true);
            update_post_meta($post_id, self::META_SETTINGS, $settings);
        }

        // Save schedule
        if (isset($_POST['pds_automation_schedule'])) {
            $schedule = json_decode(stripslashes($_POST['pds_automation_schedule']), true);
            update_post_meta($post_id, self::META_SCHEDULE, $schedule);

            // Update next run time based on schedule
            if ($enabled && !empty($schedule)) {
                $next_run = self::calculate_next_run($schedule);
                update_post_meta($post_id, self::META_NEXT_RUN, $next_run);
            } else {
                delete_post_meta($post_id, self::META_NEXT_RUN);
            }
        }
    }

    /**
     * Get automation configuration
     */
    public static function get_automation_config($automation_id) {
        $default_trigger = [
            'type' => '',
            'field' => '',
            'conditions' => [
                'logic' => 'AND',
                'rules' => [],
            ],
        ];

        $default_settings = [
            'prevent_loops' => true,
            'run_once_per_post' => false,
            'log_executions' => true,
        ];

        $default_schedule = [
            'frequency' => 'daily',
            'time' => '09:00',
            'timezone' => wp_timezone_string(),
        ];

        return [
            'table_id' => get_post_meta($automation_id, self::META_TABLE_ID, true) ?: 0,
            'post_type' => get_post_meta($automation_id, self::META_POST_TYPE, true) ?: '',
            'enabled' => get_post_meta($automation_id, self::META_ENABLED, true) === '1',
            'trigger' => get_post_meta($automation_id, self::META_TRIGGER, true) ?: $default_trigger,
            'actions' => get_post_meta($automation_id, self::META_ACTIONS, true) ?: [],
            'settings' => array_merge($default_settings, get_post_meta($automation_id, self::META_SETTINGS, true) ?: []),
            'schedule' => array_merge($default_schedule, get_post_meta($automation_id, self::META_SCHEDULE, true) ?: []),
        ];
    }

    /**
     * Get all enabled automations
     */
    public static function get_enabled_automations($trigger_type = null) {
        $args = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => self::META_ENABLED,
                    'value' => '1',
                ],
            ],
        ];

        $automations = get_posts($args);
        $result = [];

        foreach ($automations as $automation) {
            $config = self::get_automation_config($automation->ID);
            $config['id'] = $automation->ID;
            $config['name'] = $automation->post_title;

            // Filter by trigger type if specified
            if ($trigger_type && isset($config['trigger']['type']) && $config['trigger']['type'] !== $trigger_type) {
                continue;
            }

            $result[] = $config;
        }

        return $result;
    }

    /**
     * Get automations for a specific post type
     */
    public static function get_automations_for_post_type($post_type, $trigger_type = null) {
        $automations = self::get_enabled_automations($trigger_type);

        return array_filter($automations, function($automation) use ($post_type) {
            // Check if automation targets this post type directly
            if ($automation['post_type'] === $post_type) {
                return true;
            }

            // Check if automation's table targets this post type
            if ($automation['table_id']) {
                $table_config = PDS_Post_Tables_Post_Type::get_table_config($automation['table_id']);
                if (isset($table_config['source_post_type']) && $table_config['source_post_type'] === $post_type) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Calculate next run time based on schedule
     */
    public static function calculate_next_run($schedule) {
        $timezone = new DateTimeZone($schedule['timezone'] ?? wp_timezone_string());
        $now = new DateTime('now', $timezone);

        switch ($schedule['frequency'] ?? 'daily') {
            case 'every_5_minutes':
                $next = clone $now;
                $minutes = (int) $next->format('i');
                $next_5 = ceil($minutes / 5) * 5;
                if ($next_5 >= 60) {
                    $next->modify('+1 hour');
                    $next->setTime((int) $next->format('H'), 0, 0);
                } else {
                    $next->setTime((int) $next->format('H'), $next_5, 0);
                }
                break;

            case 'hourly':
                $next = clone $now;
                $next->modify('+1 hour');
                $next->setTime((int) $next->format('H'), 0, 0);
                break;

            case 'daily':
            default:
                $time_parts = explode(':', $schedule['time'] ?? '09:00');
                $hour = (int) ($time_parts[0] ?? 9);
                $minute = (int) ($time_parts[1] ?? 0);

                $next = clone $now;
                $next->setTime($hour, $minute, 0);

                // If the time has passed today, schedule for tomorrow
                if ($next <= $now) {
                    $next->modify('+1 day');
                }
                break;
        }

        return $next->format('Y-m-d H:i:s');
    }

    /**
     * Add admin columns
     */
    public function add_admin_columns($columns) {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            if ($key === 'title') {
                $new_columns['automation_status'] = __('Status', 'pds-post-tables');
                $new_columns['automation_trigger'] = __('Trigger', 'pds-post-tables');
                $new_columns['automation_table'] = __('Table', 'pds-post-tables');
                $new_columns['automation_runs'] = __('Runs', 'pds-post-tables');
            }
        }

        return $new_columns;
    }

    /**
     * Render admin columns
     */
    public function render_admin_columns($column, $post_id) {
        $config = self::get_automation_config($post_id);

        switch ($column) {
            case 'automation_status':
                $status_class = $config['enabled'] ? 'enabled' : 'disabled';
                $status_text = $config['enabled'] ? __('Enabled', 'pds-post-tables') : __('Disabled', 'pds-post-tables');
                echo '<span class="pds-status-badge pds-status-' . $status_class . '">' . $status_text . '</span>';
                break;

            case 'automation_trigger':
                echo $this->get_trigger_label($config['trigger']);
                break;

            case 'automation_table':
                if ($config['table_id']) {
                    $table = get_post($config['table_id']);
                    if ($table) {
                        echo '<a href="' . get_edit_post_link($config['table_id']) . '">' . esc_html($table->post_title) . '</a>';
                    } else {
                        echo '—';
                    }
                } elseif ($config['post_type']) {
                    $pt_obj = get_post_type_object($config['post_type']);
                    echo $pt_obj ? esc_html($pt_obj->labels->name) : esc_html($config['post_type']);
                } else {
                    echo '—';
                }
                break;

            case 'automation_runs':
                $run_count = get_post_meta($post_id, self::META_RUN_COUNT, true) ?: 0;
                echo number_format($run_count);
                break;
        }
    }

    /**
     * Get human-readable trigger label
     */
    private function get_trigger_label($trigger) {
        $trigger_labels = [
            'post_created' => __('Post Created', 'pds-post-tables'),
            'post_updated' => __('Post Updated', 'pds-post-tables'),
            'field_changed' => __('Field Changed', 'pds-post-tables'),
            'field_changed_to' => __('Field Changed To', 'pds-post-tables'),
            'field_changed_from' => __('Field Changed From', 'pds-post-tables'),
            'field_transition' => __('Field Transition', 'pds-post-tables'),
            'date_equals_today' => __('Date Equals Today', 'pds-post-tables'),
            'date_days_before' => __('Days Before Date', 'pds-post-tables'),
            'date_days_after' => __('Days After Date', 'pds-post-tables'),
            'date_is_overdue' => __('Date Overdue', 'pds-post-tables'),
            'field_matches' => __('Field Matches', 'pds-post-tables'),
        ];

        $type = $trigger['type'] ?? '';
        $label = $trigger_labels[$type] ?? $type;

        if (!empty($trigger['field'])) {
            $label .= ': ' . $trigger['field'];
        }

        return $label ?: '—';
    }

    /**
     * Increment run count
     */
    public static function increment_run_count($automation_id) {
        $current = get_post_meta($automation_id, self::META_RUN_COUNT, true) ?: 0;
        update_post_meta($automation_id, self::META_RUN_COUNT, $current + 1);
        update_post_meta($automation_id, self::META_LAST_RUN, current_time('mysql'));
    }
}
