<?php
/**
 * Plugin Name: PDS Post Tables
 * Description: Display and edit WordPress posts in Excel-like tables with customizable columns and conditional formatting
 * Version: 1.0.0
 * Author: PDS Build
 * Text Domain: pds-post-tables
 * GitHub Plugin URI: https://github.com/webb64b/Post-Tables
 * Primary Branch:    main
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PDS_POST_TABLES_VERSION', '1.4.0');
define('PDS_POST_TABLES_PATH', plugin_dir_path(__FILE__));
define('PDS_POST_TABLES_URL', plugin_dir_url(__FILE__));

class PDS_Post_Tables {
    
    private static $instance = null;
    
    public $post_type;
    public $field_scanner;
    public $rest_controller;
    public $admin_ui;
    public $shortcode;
    public $data_handler;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->includes();
        $this->init_hooks();
    }
    
    private function includes() {
        require_once PDS_POST_TABLES_PATH . 'includes/class-post-type.php';
        require_once PDS_POST_TABLES_PATH . 'includes/class-field-scanner.php';
        require_once PDS_POST_TABLES_PATH . 'includes/class-data-handler.php';
        require_once PDS_POST_TABLES_PATH . 'includes/class-rest-controller.php';
        require_once PDS_POST_TABLES_PATH . 'includes/class-admin-ui.php';
        require_once PDS_POST_TABLES_PATH . 'includes/class-shortcode.php';
    }
    
    private function init_hooks() {
        add_action('init', [$this, 'init'], 0);
        add_action('rest_api_init', [$this, 'init_rest_api']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);
        
        // AJAX handlers for table selector
        add_action('wp_ajax_pds_load_table', [$this, 'ajax_load_table']);
    }
    
    public function init() {
        $this->post_type = new PDS_Post_Tables_Post_Type();
        $this->field_scanner = new PDS_Post_Tables_Field_Scanner();
        $this->data_handler = new PDS_Post_Tables_Data_Handler($this->field_scanner);
        $this->admin_ui = new PDS_Post_Tables_Admin_UI($this->field_scanner);
        $this->shortcode = new PDS_Post_Tables_Shortcode($this->data_handler);
    }
    
    public function init_rest_api() {
        $this->rest_controller = new PDS_Post_Tables_REST_Controller($this->data_handler, $this->field_scanner);
        $this->rest_controller->register_routes();
    }
    
    public function admin_scripts($hook) {
        global $post_type;
        
        if ($post_type !== 'pds_post_table') {
            return;
        }
        
        // Enqueue WordPress dependencies
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_script('jquery-ui-sortable');
        
        // Admin CSS
        wp_enqueue_style(
            'pds-post-tables-admin',
            PDS_POST_TABLES_URL . 'assets/css/admin.css',
            [],
            PDS_POST_TABLES_VERSION
        );
        
        // Admin JS
        wp_enqueue_script(
            'pds-post-tables-admin',
            PDS_POST_TABLES_URL . 'assets/js/admin-builder.js',
            ['jquery', 'jquery-ui-sortable', 'wp-color-picker'],
            PDS_POST_TABLES_VERSION,
            true
        );
        
        // Get available post types
        $post_types = get_post_types(['public' => true], 'objects');
        $post_type_options = [];
        foreach ($post_types as $pt) {
            if ($pt->name !== 'pds_post_table') {
                $post_type_options[$pt->name] = $pt->label;
            }
        }
        
        wp_localize_script('pds-post-tables-admin', 'pdsPostTablesAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('pds-tables/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'postTypes' => $post_type_options,
            'operators' => $this->get_operators(),
            'i18n' => [
                'addColumn' => __('Add Column', 'pds-post-tables'),
                'removeColumn' => __('Remove', 'pds-post-tables'),
                'addRule' => __('Add Rule', 'pds-post-tables'),
                'removeRule' => __('Remove', 'pds-post-tables'),
                'cellRule' => __('Cell Rule', 'pds-post-tables'),
                'rowRule' => __('Row Rule', 'pds-post-tables'),
                'selectField' => __('Select a field...', 'pds-post-tables'),
                'loading' => __('Loading fields...', 'pds-post-tables'),
            ]
        ]);
    }
    
    public function frontend_scripts() {
        global $post;
        
        // Only load if shortcode is present
        if (!is_a($post, 'WP_Post')) {
            return;
        }
        
        $has_table_shortcode = has_shortcode($post->post_content, 'pds_table');
        $has_selector_shortcode = has_shortcode($post->post_content, 'pds_table_selector');
        
        if (!$has_table_shortcode && !$has_selector_shortcode) {
            return;
        }
        
        // Tabulator CSS
        wp_enqueue_style(
            'tabulator',
            'https://unpkg.com/tabulator-tables@5.5.0/dist/css/tabulator.min.css',
            [],
            '5.5.0'
        );
        
        // Dashicons (for column toggle icon)
        wp_enqueue_style('dashicons');
        
        // Custom CSS
        wp_enqueue_style(
            'pds-post-tables-frontend',
            PDS_POST_TABLES_URL . 'assets/css/frontend.css',
            ['tabulator', 'dashicons'],
            PDS_POST_TABLES_VERSION
        );
        
        // Tabulator JS
        wp_enqueue_script(
            'tabulator',
            'https://unpkg.com/tabulator-tables@5.5.0/dist/js/tabulator.min.js',
            [],
            '5.5.0',
            true
        );
        
        // Frontend JS
        wp_enqueue_script(
            'pds-post-tables-frontend',
            PDS_POST_TABLES_URL . 'assets/js/table-frontend.js',
            ['tabulator'],
            PDS_POST_TABLES_VERSION,
            true
        );
        
        wp_localize_script('pds-post-tables-frontend', 'pdsPostTables', [
            'restUrl' => rest_url('pds-tables/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }
    
    private function get_operators() {
        return [
            'equals' => __('equals', 'pds-post-tables'),
            'not_equals' => __('does not equal', 'pds-post-tables'),
            'contains' => __('contains', 'pds-post-tables'),
            'not_contains' => __('does not contain', 'pds-post-tables'),
            'greater_than' => __('is greater than', 'pds-post-tables'),
            'less_than' => __('is less than', 'pds-post-tables'),
            'is_empty' => __('is empty', 'pds-post-tables'),
            'is_not_empty' => __('is not empty', 'pds-post-tables'),
            'is_true' => __('is checked', 'pds-post-tables'),
            'is_false' => __('is not checked', 'pds-post-tables'),
        ];
    }
    
    /**
     * AJAX handler for loading a table in the selector
     */
    public function ajax_load_table() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pds_load_table')) {
            wp_send_json_error(__('Invalid security token.', 'pds-post-tables'));
        }
        
        // Must be logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(__('You must be logged in.', 'pds-post-tables'));
        }
        
        $table_id = absint($_POST['table_id'] ?? 0);
        
        if (!$table_id) {
            wp_send_json_error(__('No table specified.', 'pds-post-tables'));
        }
        
        // Check table exists
        $table = get_post($table_id);
        if (!$table || $table->post_type !== 'pds_post_table') {
            wp_send_json_error(__('Table not found.', 'pds-post-tables'));
        }
        
        // Check permissions
        if (!$this->shortcode->user_can_view_table($table_id)) {
            wp_send_json_error(__('You do not have permission to view this table.', 'pds-post-tables'));
        }
        
        // Get table config
        $config = PDS_Post_Tables_Post_Type::get_table_config($table_id);
        
        if (empty($config['source_post_type']) || empty($config['columns'])) {
            wp_send_json_error(__('Table is not properly configured.', 'pds-post-tables'));
        }
        
        // Build the table HTML and config (similar to shortcode render)
        $tabulator_columns = $this->build_tabulator_columns_for_ajax($config);
        $custom_column_formats = json_decode(get_post_meta($table_id, '_pds_table_custom_column_formats', true), true) ?: [];
        
        $js_config = [
            'tableId' => $table_id,
            'columns' => $tabulator_columns,
            'columnDefaults' => $config['column_defaults'],
            'conditionalRules' => $config['conditional_rules'],
            'customColumnFormats' => $custom_column_formats,
            'settings' => $config['settings'],
            'canEdit' => current_user_can('edit_posts'),
        ];
        
        $instance_id = 'pds-table-' . $table_id . '-' . wp_generate_uuid4();
        $can_edit = current_user_can('edit_posts');
        
        ob_start();
        ?>
        <div class="pds-post-table-wrap pds-row-height-<?php echo esc_attr($config['settings']['row_height'] ?? 'normal'); ?>" id="<?php echo esc_attr($instance_id); ?>-wrap">
            <div class="pds-table-title">
                <h3><?php echo esc_html($table->post_title); ?></h3>
            </div>
            
            <?php if ($can_edit) : ?>
            <div class="pds-table-toolbar">
                <div class="pds-toolbar-group pds-toolbar-selection">
                    <span class="pds-toolbar-label"><?php _e('Selection:', 'pds-post-tables'); ?></span>
                    <span class="pds-selection-info"><?php _e('None', 'pds-post-tables'); ?></span>
                    <span class="pds-toolbar-help" title="<?php _e('Click cell to select. Click row # to select row. Click column header to select column. Double-click cell to edit. Click outside to deselect.', 'pds-post-tables'); ?>">?</span>
                </div>
                
                <div class="pds-toolbar-separator"></div>
                
                <div class="pds-toolbar-group pds-toolbar-formatting">
                    <label class="pds-toolbar-item" title="<?php _e('Background Color', 'pds-post-tables'); ?>">
                        <span class="dashicons dashicons-art"></span>
                        <input type="color" class="pds-format-bg-color" value="#ffffff">
                    </label>
                    
                    <label class="pds-toolbar-item" title="<?php _e('Text Color', 'pds-post-tables'); ?>">
                        <span class="dashicons dashicons-editor-textcolor"></span>
                        <input type="color" class="pds-format-text-color" value="#333333">
                    </label>
                    
                    <button type="button" class="pds-toolbar-btn pds-format-bold" title="<?php _e('Bold', 'pds-post-tables'); ?>">
                        <span class="dashicons dashicons-editor-bold"></span>
                    </button>
                    
                    <button type="button" class="pds-toolbar-btn pds-format-clear" title="<?php _e('Clear Formatting', 'pds-post-tables'); ?>">
                        <span class="dashicons dashicons-editor-removeformatting"></span>
                    </button>
                </div>
                
                <div class="pds-toolbar-separator"></div>
                
                <div class="pds-toolbar-group pds-toolbar-actions">
                    <?php if ($config['settings']['allow_column_toggle'] ?? true) : ?>
                    <div class="pds-column-toggle-container">
                        <button type="button" class="pds-toolbar-btn pds-column-toggle-btn" title="<?php _e('Show/Hide Columns', 'pds-post-tables'); ?>">
                            <span class="dashicons dashicons-columns"></span> <?php _e('Columns', 'pds-post-tables'); ?>
                        </button>
                        <div class="pds-column-toggle-dropdown">
                            <div class="pds-column-toggle-header">
                                <strong><?php _e('Show/Hide Columns', 'pds-post-tables'); ?></strong>
                                <button type="button" class="pds-column-reset-btn" title="<?php _e('Reset to default', 'pds-post-tables'); ?>">
                                    <?php _e('Reset', 'pds-post-tables'); ?>
                                </button>
                            </div>
                            <div class="pds-column-toggle-list"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($config['settings']['export_csv'] ?? false) : ?>
                    <button type="button" class="pds-toolbar-btn pds-export-csv" data-instance="<?php echo esc_attr($instance_id); ?>">
                        <?php _e('Export CSV', 'pds-post-tables'); ?>
                    </button>
                    <?php endif; ?>
                    
                    <?php if (($config['settings']['save_mode'] ?? 'immediate') === 'batch') : ?>
                    <div class="pds-toolbar-separator"></div>
                    <div class="pds-toolbar-group pds-batch-save-controls">
                        <span class="pds-pending-changes-indicator"><?php _e('All changes saved', 'pds-post-tables'); ?></span>
                        <button type="button" class="pds-toolbar-btn pds-discard-changes-btn" style="display:none;" title="<?php _e('Discard all unsaved changes', 'pds-post-tables'); ?>">
                            <span class="dashicons dashicons-undo"></span> <?php _e('Discard', 'pds-post-tables'); ?>
                        </button>
                        <button type="button" class="pds-toolbar-btn pds-save-changes-btn pds-btn-primary" disabled title="<?php _e('Save all changes', 'pds-post-tables'); ?>">
                            <span class="dashicons dashicons-saved"></span> <?php _e('Save', 'pds-post-tables'); ?>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else : ?>
            <?php 
            $show_toolbar = ($config['settings']['export_csv'] ?? false) || ($config['settings']['allow_column_toggle'] ?? true);
            if ($show_toolbar) : 
            ?>
            <div class="pds-table-toolbar pds-toolbar-simple">
                <?php if ($config['settings']['allow_column_toggle'] ?? true) : ?>
                <div class="pds-column-toggle-container">
                    <button type="button" class="pds-toolbar-btn pds-column-toggle-btn" title="<?php _e('Show/Hide Columns', 'pds-post-tables'); ?>">
                        <span class="dashicons dashicons-columns"></span> <?php _e('Columns', 'pds-post-tables'); ?>
                    </button>
                    <div class="pds-column-toggle-dropdown">
                        <div class="pds-column-toggle-header">
                            <strong><?php _e('Show/Hide Columns', 'pds-post-tables'); ?></strong>
                            <button type="button" class="pds-column-reset-btn" title="<?php _e('Reset to default', 'pds-post-tables'); ?>">
                                <?php _e('Reset', 'pds-post-tables'); ?>
                            </button>
                        </div>
                        <div class="pds-column-toggle-list"></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($config['settings']['export_csv'] ?? false) : ?>
                <button type="button" class="pds-export-csv button" data-instance="<?php echo esc_attr($instance_id); ?>">
                    <?php _e('Export CSV', 'pds-post-tables'); ?>
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            
            <div id="<?php echo esc_attr($instance_id); ?>" class="pds-post-table" data-config="<?php echo esc_attr(wp_json_encode($js_config)); ?>"></div>
            
            <?php if ($config['settings']['pagination'] ?? true) : ?>
            <div class="pds-table-footer">
                <div class="pds-table-info"></div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        $html = ob_get_clean();
        
        wp_send_json_success([
            'html' => $html,
            'container_id' => $instance_id,
        ]);
    }
    
    /**
     * Build Tabulator columns for AJAX response
     */
    private function build_tabulator_columns_for_ajax($config) {
        $columns = [];
        
        foreach ($config['columns'] as $column) {
            // For ACF fields, re-check the actual field type to catch WYSIWYG fields
            $column_type = $column['type'];
            if ($column['source'] === 'acf' && function_exists('acf_get_field')) {
                $acf_field = acf_get_field($column['field_key']);
                if ($acf_field && isset($acf_field['type'])) {
                    // Map ACF type to our type
                    $acf_type_map = [
                        'wysiwyg' => 'wysiwyg',
                        'textarea' => 'textarea',
                        'date_picker' => 'date',
                        'date_time_picker' => 'datetime',
                        'true_false' => 'boolean',
                        'select' => 'select',
                        'checkbox' => 'select',
                        'radio' => 'select',
                    ];
                    if (isset($acf_type_map[$acf_field['type']])) {
                        $column_type = $acf_type_map[$acf_field['type']];
                    }
                }
            }
            
            $col_def = [
                'field' => $column['field_key'],
                'title' => $column['label'] ?: $column['field_key'],
                'sorter' => $this->get_sorter_type($column_type),
                'headerFilter' => $column['filterable'] ? $this->get_header_filter_type($column) : false,
                'source' => $column['source'],
                'colId' => $column['id'],
            ];
            
            // Frozen column
            if (!empty($column['frozen'])) {
                $col_def['frozen'] = true;
            }
            
            // Mark as editable (JS will handle the actual editor)
            if ($column['editable'] && current_user_can('edit_posts')) {
                $col_def['editable'] = true;
            }
            
            // Width
            if (!empty($config['column_defaults'][$column['id']]['width'])) {
                $col_def['width'] = (int) $config['column_defaults'][$column['id']]['width'];
            }
            
            // Align
            if (!empty($config['column_defaults'][$column['id']]['align'])) {
                $col_def['hozAlign'] = $config['column_defaults'][$column['id']]['align'];
            }
            
            // Max characters for truncation
            if (!empty($config['column_defaults'][$column['id']]['max_chars'])) {
                $col_def['maxChars'] = (int) $config['column_defaults'][$column['id']]['max_chars'];
            }
            
            // Custom sort order for select fields
            if (!empty($config['column_defaults'][$column['id']]['sort_order'])) {
                $col_def['sortOrder'] = $config['column_defaults'][$column['id']]['sort_order'];
            }
            
            $col_def['fieldType'] = $column_type;
            
            // Store options for select fields ONLY
            if (!empty($column['options']) && $column_type === 'select') {
                $col_def['fieldOptions'] = $column['options'];
            }
            
            $columns[] = $col_def;
        }
        
        return $columns;
    }
    
    private function get_sorter_type($type) {
        $sorters = [
            'text' => 'string',
            'number' => 'number',
            'date' => 'date',
            'datetime' => 'datetime',
            'boolean' => 'boolean',
            'select' => 'string',
        ];
        return $sorters[$type] ?? 'string';
    }
    
    private function get_header_filter_type($column) {
        if ($column['type'] === 'select' && !empty($column['options'])) {
            return 'list';
        }
        if ($column['type'] === 'boolean') {
            return 'tickCross';
        }
        return 'input';
    }
    
    private function get_editor_type($type) {
        $editors = [
            'text' => 'input',
            'textarea' => 'input',
            'wysiwyg' => 'input',
            'number' => 'number',
            'date' => 'date',
            'datetime' => 'datetime',
            'boolean' => 'tickCross',
            'select' => 'list',
        ];
        return $editors[$type] ?? 'input';
    }
}

function pds_post_tables() {
    return PDS_Post_Tables::instance();
}

// Initialize
pds_post_tables();
