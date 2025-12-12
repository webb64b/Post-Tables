<?php
/**
 * Automation Admin - Admin UI for managing automations
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Automation_Admin {

    /**
     * Field scanner instance
     */
    private $field_scanner;

    /**
     * Automation engine instance
     */
    private $engine;

    public function __construct($field_scanner, $engine = null) {
        $this->field_scanner = $field_scanner;
        $this->engine = $engine;

        // Admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        // Add submenu pages
        add_action('admin_menu', [$this, 'add_admin_pages']);

        // AJAX handlers
        add_action('wp_ajax_pds_get_automation_fields', [$this, 'ajax_get_fields']);
        add_action('wp_ajax_pds_test_automation', [$this, 'ajax_test_automation']);
        add_action('wp_ajax_pds_run_automation_now', [$this, 'ajax_run_now']);
    }

    /**
     * Set the engine instance
     */
    public function set_engine($engine) {
        $this->engine = $engine;
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        global $post_type;

        // Only load on automation pages
        if ($post_type !== 'pds_automation' && strpos($hook, 'pds-automation') === false) {
            return;
        }

        // Enqueue WordPress dependencies
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // Automation builder CSS
        wp_enqueue_style(
            'pds-automation-admin',
            PDS_POST_TABLES_URL . 'assets/css/admin-automation.css',
            [],
            PDS_POST_TABLES_VERSION
        );

        // Automation builder JS
        wp_enqueue_script(
            'pds-automation-admin',
            PDS_POST_TABLES_URL . 'assets/js/admin-automation.js',
            ['jquery', 'wp-color-picker'],
            PDS_POST_TABLES_VERSION,
            true
        );

        // Get post types for dropdown
        $post_types = get_post_types(['public' => true], 'objects');
        $post_type_options = [];
        foreach ($post_types as $pt) {
            if (!in_array($pt->name, ['pds_post_table', 'pds_automation', 'pds_automation_log', 'attachment'])) {
                $post_type_options[$pt->name] = $pt->label;
            }
        }

        // Get tables
        $tables = get_posts([
            'post_type' => 'pds_post_table',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);
        $table_options = [];
        foreach ($tables as $table) {
            $table_options[$table->ID] = $table->post_title;
        }

        wp_localize_script('pds-automation-admin', 'pdsAutomationAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('pds-tables/v1'),
            'nonce' => wp_create_nonce('pds_automation_nonce'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'postTypes' => $post_type_options,
            'tables' => $table_options,
            'triggerTypes' => PDS_Post_Tables_Automation_Triggers::get_trigger_types(),
            'actionTypes' => PDS_Post_Tables_Automation_Actions::get_action_types(),
            'operators' => PDS_Post_Tables_Automation_Conditions::get_operators(),
            'placeholders' => PDS_Post_Tables_Automation_Placeholders::get_available_placeholders(),
            'frequencies' => PDS_Post_Tables_Automation_Scheduler::get_frequencies(),
            'i18n' => $this->get_i18n_strings(),
        ]);
    }

    /**
     * Get internationalization strings
     */
    private function get_i18n_strings() {
        return [
            'addCondition' => __('Add Condition', 'pds-post-tables'),
            'addConditionGroup' => __('Add Condition Group', 'pds-post-tables'),
            'addAction' => __('Add Action', 'pds-post-tables'),
            'addBranch' => __('Add ELSE IF Branch', 'pds-post-tables'),
            'remove' => __('Remove', 'pds-post-tables'),
            'and' => __('AND', 'pds-post-tables'),
            'or' => __('OR', 'pds-post-tables'),
            'if' => __('IF', 'pds-post-tables'),
            'elseIf' => __('ELSE IF', 'pds-post-tables'),
            'else' => __('ELSE', 'pds-post-tables'),
            'then' => __('THEN', 'pds-post-tables'),
            'selectField' => __('Select field...', 'pds-post-tables'),
            'selectOperator' => __('Select operator...', 'pds-post-tables'),
            'selectTrigger' => __('Select trigger type...', 'pds-post-tables'),
            'selectAction' => __('Select action type...', 'pds-post-tables'),
            'enterValue' => __('Enter value...', 'pds-post-tables'),
            'loading' => __('Loading...', 'pds-post-tables'),
            'testSuccess' => __('Test completed successfully', 'pds-post-tables'),
            'testFailed' => __('Test failed', 'pds-post-tables'),
            'confirmDelete' => __('Are you sure you want to delete this?', 'pds-post-tables'),
            'noFieldsAvailable' => __('No fields available for this post type', 'pds-post-tables'),
            'triggerConditions' => __('Additional Conditions', 'pds-post-tables'),
            'triggerConditionsHelp' => __('Only trigger when these additional conditions are met', 'pds-post-tables'),
            'actionConditions' => __('Action Conditions', 'pds-post-tables'),
            'actionConditionsHelp' => __('Only execute this action when these conditions are met', 'pds-post-tables'),
            'consolidation' => __('Consolidation', 'pds-post-tables'),
            'consolidationHelp' => __('When multiple items trigger at once, consolidate into one email', 'pds-post-tables'),
            'schedule' => __('Schedule', 'pds-post-tables'),
            'scheduleHelp' => __('When to check for this trigger', 'pds-post-tables'),
        ];
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_pages() {
        // History page
        add_submenu_page(
            'edit.php?post_type=pds_post_table',
            __('Automation History', 'pds-post-tables'),
            __('Automation History', 'pds-post-tables'),
            'edit_posts',
            'pds-automation-history',
            [$this, 'render_history_page']
        );

        // System status page
        add_submenu_page(
            'edit.php?post_type=pds_post_table',
            __('Automation Status', 'pds-post-tables'),
            __('Automation Status', 'pds-post-tables'),
            'manage_options',
            'pds-automation-status',
            [$this, 'render_status_page']
        );
    }

    /**
     * Render the history page
     */
    public function render_history_page() {
        $automation_id = isset($_GET['automation_id']) ? absint($_GET['automation_id']) : null;
        $page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $per_page = 25;

        $history = $this->engine ? $this->engine->get_history()->get_history($automation_id, [
            'limit' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ]) : ['entries' => [], 'total' => 0];

        ?>
        <div class="wrap">
            <h1><?php _e('Automation History', 'pds-post-tables'); ?></h1>

            <?php if ($automation_id) : ?>
                <p>
                    <a href="<?php echo admin_url('edit.php?post_type=pds_post_table&page=pds-automation-history'); ?>">&larr; <?php _e('All Automations', 'pds-post-tables'); ?></a>
                    |
                    <a href="<?php echo get_edit_post_link($automation_id); ?>"><?php _e('Edit Automation', 'pds-post-tables'); ?></a>
                </p>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Automation', 'pds-post-tables'); ?></th>
                        <th><?php _e('Post', 'pds-post-tables'); ?></th>
                        <th><?php _e('Trigger', 'pds-post-tables'); ?></th>
                        <th><?php _e('Status', 'pds-post-tables'); ?></th>
                        <th><?php _e('Duration', 'pds-post-tables'); ?></th>
                        <th><?php _e('Time', 'pds-post-tables'); ?></th>
                        <th><?php _e('Details', 'pds-post-tables'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history['entries'])) : ?>
                        <tr>
                            <td colspan="7"><?php _e('No execution history found.', 'pds-post-tables'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($history['entries'] as $entry) : ?>
                            <?php
                            $automation = get_post($entry['automation_id']);
                            $post = get_post($entry['post_id']);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($automation) : ?>
                                        <a href="<?php echo get_edit_post_link($entry['automation_id']); ?>">
                                            <?php echo esc_html($automation->post_title); ?>
                                        </a>
                                    <?php else : ?>
                                        #<?php echo $entry['automation_id']; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($post) : ?>
                                        <a href="<?php echo get_edit_post_link($entry['post_id']); ?>">
                                            <?php echo esc_html($post->post_title); ?>
                                        </a>
                                    <?php else : ?>
                                        #<?php echo $entry['post_id']; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($entry['trigger_type']); ?></td>
                                <td>
                                    <span class="pds-status-badge pds-status-<?php echo esc_attr($entry['status']); ?>">
                                        <?php echo esc_html($entry['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $entry['duration_ms']; ?>ms</td>
                                <td>
                                    <?php echo date_i18n(
                                        get_option('date_format') . ' ' . get_option('time_format'),
                                        strtotime($entry['triggered_at'])
                                    ); ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small pds-view-details"
                                            data-details="<?php echo esc_attr(wp_json_encode($entry['execution_data'])); ?>">
                                        <?php _e('View', 'pds-post-tables'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($history['total'] > $per_page) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'current' => $page,
                            'total' => ceil($history['total'] / $per_page),
                        ]);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Details Modal -->
        <div id="pds-details-modal" class="pds-modal" style="display: none;">
            <div class="pds-modal-content">
                <span class="pds-modal-close">&times;</span>
                <h2><?php _e('Execution Details', 'pds-post-tables'); ?></h2>
                <pre id="pds-details-content"></pre>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.pds-view-details').on('click', function() {
                var details = $(this).data('details');
                $('#pds-details-content').text(JSON.stringify(details, null, 2));
                $('#pds-details-modal').show();
            });

            $('.pds-modal-close, #pds-details-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#pds-details-modal').hide();
                }
            });
        });
        </script>

        <style>
        .pds-modal { position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .pds-modal-content { background-color: #fff; margin: 10% auto; padding: 20px; width: 60%; max-width: 800px; max-height: 70vh; overflow: auto; position: relative; }
        .pds-modal-close { position: absolute; right: 10px; top: 5px; font-size: 24px; cursor: pointer; }
        #pds-details-content { background: #f5f5f5; padding: 15px; overflow: auto; max-height: 50vh; }
        .pds-status-badge { padding: 3px 8px; border-radius: 3px; font-size: 12px; }
        .pds-status-success { background: #d4edda; color: #155724; }
        .pds-status-error { background: #f8d7da; color: #721c24; }
        .pds-status-partial { background: #fff3cd; color: #856404; }
        .pds-status-warning { background: #fff3cd; color: #856404; }
        </style>
        <?php
    }

    /**
     * Render the system status page
     */
    public function render_status_page() {
        $cron_status = PDS_Post_Tables_Automation_Scheduler::get_cron_status();
        $cron_log = PDS_Post_Tables_Automation_Scheduler::get_cron_log(20);

        ?>
        <div class="wrap">
            <h1><?php _e('Automation System Status', 'pds-post-tables'); ?></h1>

            <div class="card">
                <h2><?php _e('Cron Status', 'pds-post-tables'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php _e('Cron Scheduled', 'pds-post-tables'); ?></th>
                        <td>
                            <?php if ($cron_status['scheduled']) : ?>
                                <span style="color: green;">&#10004; <?php _e('Yes', 'pds-post-tables'); ?></span>
                            <?php else : ?>
                                <span style="color: red;">&#10006; <?php _e('No', 'pds-post-tables'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Next Run', 'pds-post-tables'); ?></th>
                        <td>
                            <?php if ($cron_status['next_run']) : ?>
                                <?php echo esc_html($cron_status['next_run']); ?>
                                (<?php _e('in', 'pds-post-tables'); ?> <?php echo esc_html($cron_status['next_run_human']); ?>)
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Interval', 'pds-post-tables'); ?></th>
                        <td><?php echo esc_html($cron_status['interval_human']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('WP Cron', 'pds-post-tables'); ?></th>
                        <td>
                            <?php if ($cron_status['wp_cron_disabled']) : ?>
                                <span style="color: orange;">
                                    <?php _e('Disabled (DISABLE_WP_CRON is true)', 'pds-post-tables'); ?>
                                </span>
                                <p class="description">
                                    <?php _e('This is fine if you have a real server cron configured.', 'pds-post-tables'); ?>
                                </p>
                            <?php else : ?>
                                <span style="color: green;">&#10004; <?php _e('Enabled', 'pds-post-tables'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" class="button" id="pds-run-cron-now">
                        <?php _e('Run Scheduled Check Now', 'pds-post-tables'); ?>
                    </button>
                </p>
            </div>

            <div class="card">
                <h2><?php _e('Recent Cron Activity', 'pds-post-tables'); ?></h2>

                <?php if (empty($cron_log)) : ?>
                    <p><?php _e('No recent cron activity logged.', 'pds-post-tables'); ?></p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Time', 'pds-post-tables'); ?></th>
                                <th><?php _e('Status', 'pds-post-tables'); ?></th>
                                <th><?php _e('Details', 'pds-post-tables'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse($cron_log) as $entry) : ?>
                                <tr>
                                    <td><?php echo esc_html($entry['time']); ?></td>
                                    <td><?php echo esc_html($entry['status']); ?></td>
                                    <td>
                                        <?php if ($entry['data']) : ?>
                                            <code><?php echo esc_html(is_string($entry['data']) ? $entry['data'] : wp_json_encode($entry['data'])); ?></code>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <p>
                    <button type="button" class="button" id="pds-clear-cron-log">
                        <?php _e('Clear Log', 'pds-post-tables'); ?>
                    </button>
                </p>
            </div>

            <div class="card">
                <h2><?php _e('Server Cron Setup (Recommended)', 'pds-post-tables'); ?></h2>
                <p><?php _e('For more reliable automation execution, set up a real server cron job:', 'pds-post-tables'); ?></p>
                <pre>*/5 * * * * wget -q -O - <?php echo esc_url(home_url('/wp-cron.php?doing_wp_cron')); ?> &gt;/dev/null 2&gt;&amp;1</pre>
                <p><?php _e('Then add this to wp-config.php:', 'pds-post-tables'); ?></p>
                <pre>define('DISABLE_WP_CRON', true);</pre>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#pds-run-cron-now').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php _e('Running...', 'pds-post-tables'); ?>');

                $.post(ajaxurl, {
                    action: 'pds_run_automation_now',
                    nonce: '<?php echo wp_create_nonce('pds_automation_nonce'); ?>'
                }, function(response) {
                    location.reload();
                });
            });

            $('#pds-clear-cron-log').on('click', function() {
                if (confirm('<?php _e('Clear all cron log entries?', 'pds-post-tables'); ?>')) {
                    $.post(ajaxurl, {
                        action: 'pds_clear_cron_log',
                        nonce: '<?php echo wp_create_nonce('pds_automation_nonce'); ?>'
                    }, function() {
                        location.reload();
                    });
                }
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Get fields for a post type
     */
    public function ajax_get_fields() {
        check_ajax_referer('pds_automation_nonce', 'nonce');

        $post_type = sanitize_text_field($_POST['post_type'] ?? '');
        $table_id = absint($_POST['table_id'] ?? 0);

        if (empty($post_type) && $table_id) {
            $table_config = PDS_Post_Tables_Post_Type::get_table_config($table_id);
            $post_type = $table_config['source_post_type'] ?? '';
        }

        if (empty($post_type)) {
            wp_send_json_error(['message' => __('No post type specified', 'pds-post-tables')]);
        }

        $fields = $this->field_scanner->get_available_fields($post_type);

        wp_send_json_success(['fields' => $fields]);
    }

    /**
     * AJAX: Test an automation
     */
    public function ajax_test_automation() {
        check_ajax_referer('pds_automation_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'pds-post-tables')]);
        }

        $automation_id = absint($_POST['automation_id'] ?? 0);
        $post_id = absint($_POST['post_id'] ?? 0);

        if (!$automation_id) {
            wp_send_json_error(['message' => __('No automation specified', 'pds-post-tables')]);
        }

        if (!$post_id) {
            wp_send_json_error(['message' => __('No test post specified', 'pds-post-tables')]);
        }

        if (!$this->engine) {
            wp_send_json_error(['message' => __('Automation engine not initialized', 'pds-post-tables')]);
        }

        $automation = PDS_Post_Tables_Automation_Post_Type::get_automation_config($automation_id);
        $automation['id'] = $automation_id;

        $result = $this->engine->test_automation($automation, $post_id);

        wp_send_json_success($result);
    }

    /**
     * AJAX: Run scheduled automations now
     */
    public function ajax_run_now() {
        check_ajax_referer('pds_automation_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'pds-post-tables')]);
        }

        // Trigger the cron manually
        do_action(PDS_Post_Tables_Automation_Scheduler::CRON_HOOK);

        wp_send_json_success(['message' => __('Scheduled check completed', 'pds-post-tables')]);
    }
}

// Add clear cron log AJAX handler
add_action('wp_ajax_pds_clear_cron_log', function() {
    check_ajax_referer('pds_automation_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    PDS_Post_Tables_Automation_Scheduler::clear_cron_log();
    wp_send_json_success();
});
