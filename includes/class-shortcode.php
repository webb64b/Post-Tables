<?php
/**
 * Shortcode Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Shortcode {
    
    private $data_handler;
    
    public function __construct($data_handler) {
        $this->data_handler = $data_handler;
        
        add_shortcode('pds_table', [$this, 'render_shortcode']);
        add_shortcode('pds_table_selector', [$this, 'render_selector_shortcode']);
    }
    
    /**
     * Render the table shortcode
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'limit' => null,
            'class' => '',
        ], $atts, 'pds_table');
        
        $table_id = absint($atts['id']);
        
        if (!$table_id) {
            return $this->render_error(__('No table ID specified.', 'pds-post-tables'));
        }
        
        // Verify table exists
        $table = get_post($table_id);
        if (!$table || $table->post_type !== 'pds_post_table') {
            return $this->render_error(__('Table not found.', 'pds-post-tables'));
        }
        
        // Check permissions
        if (!$this->user_can_view_table($table_id)) {
            if (!is_user_logged_in()) {
                return '<div class="pds-table-access-denied">' . __('Please log in to view this table.', 'pds-post-tables') . '</div>';
            }
            return '<div class="pds-table-access-denied">' . __('You do not have permission to view this table.', 'pds-post-tables') . '</div>';
        }
        
        // Get table config
        $config = PDS_Post_Tables_Post_Type::get_table_config($table_id);
        
        if (empty($config['source_post_type'])) {
            return $this->render_error(__('Table has no data source configured.', 'pds-post-tables'));
        }
        
        if (empty($config['columns'])) {
            return $this->render_error(__('Table has no columns configured.', 'pds-post-tables'));
        }
        
        // Build column definitions for Tabulator
        $tabulator_columns = $this->build_tabulator_columns($config);
        
        // Get custom formats
        $custom_column_formats = json_decode(get_post_meta($table_id, '_pds_table_custom_column_formats', true), true) ?: [];
        
        // Build table config for JS
        $js_config = [
            'tableId' => $table_id,
            'columns' => $tabulator_columns,
            'columnDefaults' => $config['column_defaults'],
            'conditionalRules' => $config['conditional_rules'],
            'customColumnFormats' => $custom_column_formats,
            'settings' => $config['settings'],
            'canEdit' => current_user_can('edit_posts'),
        ];
        
        // Override page size if limit specified
        if ($atts['limit']) {
            $js_config['settings']['page_size'] = absint($atts['limit']);
        }
        
        // Generate unique ID for this instance
        $instance_id = 'pds-table-' . $table_id . '-' . wp_generate_uuid4();
        
        // Build CSS classes
        $classes = ['pds-post-table-wrap'];
        if ($atts['class']) {
            $classes[] = esc_attr($atts['class']);
        }
        $classes[] = 'pds-row-height-' . ($config['settings']['row_height'] ?? 'normal');
        
        $can_edit = current_user_can('edit_posts');
        
        ob_start();
        ?>
        <div class="<?php echo implode(' ', $classes); ?>" id="<?php echo esc_attr($instance_id); ?>-wrap">
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
            
            <!-- DEBUG: Column Configuration -->
            <?php if (current_user_can('manage_options') && isset($_GET['pds_debug'])) : ?>
            <div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border: 1px solid #ccc; font-family: monospace; font-size: 12px; overflow-x: auto;">
                <strong>DEBUG: Column Configuration (Table ID: <?php echo $table_id; ?>)</strong>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php 
                    foreach ($tabulator_columns as $i => $col) {
                        echo "\n--- Column $i ---\n";
                        echo "field: " . ($col['field'] ?? 'null') . "\n";
                        echo "title: " . ($col['title'] ?? 'null') . "\n";
                        echo "fieldType: " . ($col['fieldType'] ?? 'null') . "\n";
                        echo "editable: " . (isset($col['editable']) ? ($col['editable'] ? 'true' : 'false') : 'not set') . "\n";
                        echo "fieldOptions: " . (isset($col['fieldOptions']) ? print_r($col['fieldOptions'], true) : 'not set') . "\n";
                    }
                ?></pre>
                <strong>Raw Config columns from DB:</strong>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php 
                    foreach ($config['columns'] as $i => $col) {
                        echo "\n--- Raw Column $i ---\n";
                        echo "id: " . ($col['id'] ?? 'null') . "\n";
                        echo "field_key: " . ($col['field_key'] ?? 'null') . "\n";
                        echo "type: " . ($col['type'] ?? 'null') . "\n";
                        echo "options: " . (isset($col['options']) ? print_r($col['options'], true) : 'not set') . "\n";
                    }
                ?></pre>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Build Tabulator column definitions
     */
    private function build_tabulator_columns($config) {
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
                'sorter' => $this->get_sorter($column_type),
                'headerFilter' => $column['filterable'] ? $this->get_header_filter($column) : false,
                'source' => $column['source'],
                'colId' => $column['id'],
            ];
            
            // Frozen column - stays visible when scrolling horizontally
            if (!empty($column['frozen'])) {
                $col_def['frozen'] = true;
            }
            
            // Mark as editable (JS will handle the actual editor)
            if ($column['editable'] && current_user_can('edit_posts')) {
                $col_def['editable'] = true;
            }
            
            // Add width if specified in defaults
            if (!empty($config['column_defaults'][$column['id']]['width'])) {
                $col_def['width'] = (int) $config['column_defaults'][$column['id']]['width'];
            }
            
            // Add horizontal align
            if (!empty($config['column_defaults'][$column['id']]['align'])) {
                $col_def['hozAlign'] = $config['column_defaults'][$column['id']]['align'];
            }
            
            // Add max characters for truncation
            if (!empty($config['column_defaults'][$column['id']]['max_chars'])) {
                $col_def['maxChars'] = (int) $config['column_defaults'][$column['id']]['max_chars'];
            }
            
            // Add custom sort order for select fields
            if (!empty($config['column_defaults'][$column['id']]['sort_order'])) {
                $col_def['sortOrder'] = $config['column_defaults'][$column['id']]['sort_order'];
            }
            
            // Store column type for formatting (use detected type)
            $col_def['fieldType'] = $column_type;
            
            // Store options for select fields ONLY
            if (!empty($column['options']) && $column_type === 'select') {
                $col_def['fieldOptions'] = $column['options'];
            }
            
            $columns[] = $col_def;
        }
        
        return $columns;
    }
    
    /**
     * Get Tabulator sorter for field type
     */
    private function get_sorter($type) {
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
    
    /**
     * Get Tabulator editor for field type
     */
    private function get_editor($column) {
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
        
        return $editors[$column['type']] ?? 'input';
    }
    
    /**
     * Get header filter config
     */
    private function get_header_filter($column) {
        if ($column['type'] === 'select' && !empty($column['options'])) {
            return 'list';
        }
        
        if ($column['type'] === 'boolean') {
            return 'tickCross';
        }
        
        return 'input';
    }
    
    /**
     * Render the table selector shortcode
     */
    public function render_selector_shortcode($atts) {
        $atts = shortcode_atts([
            'class' => '',
            'placeholder' => __('Select a table...', 'pds-post-tables'),
            'show_description' => 'no',
        ], $atts, 'pds_table_selector');
        
        // Must be logged in
        if (!is_user_logged_in()) {
            return '<div class="pds-table-selector-login">' . __('Please log in to view available tables.', 'pds-post-tables') . '</div>';
        }
        
        // Get all published tables
        $tables = get_posts([
            'post_type' => 'pds_post_table',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        
        // Filter tables by permission
        $accessible_tables = [];
        foreach ($tables as $table) {
            if ($this->user_can_view_table($table->ID)) {
                $accessible_tables[] = $table;
            }
        }
        
        if (empty($accessible_tables)) {
            return '<div class="pds-table-selector-empty">' . __('No tables available.', 'pds-post-tables') . '</div>';
        }
        
        // Build CSS classes
        $classes = ['pds-table-selector-wrap'];
        if ($atts['class']) {
            $classes[] = esc_attr($atts['class']);
        }
        
        // Generate unique ID
        $selector_id = 'pds-selector-' . wp_generate_uuid4();
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>" id="<?php echo esc_attr($selector_id); ?>">
            <div class="pds-table-selector-header">
                <select class="pds-table-selector-dropdown">
                    <option value=""><?php echo esc_html($atts['placeholder']); ?></option>
                    <?php foreach ($accessible_tables as $table) : ?>
                        <?php 
                        $description = '';
                        if ($atts['show_description'] === 'yes') {
                            $source_type = get_post_meta($table->ID, '_pds_table_source_post_type', true);
                            $columns = json_decode(get_post_meta($table->ID, '_pds_table_columns', true), true) ?: [];
                            $description = sprintf(' (%s, %d columns)', $source_type, count($columns));
                        }
                        ?>
                        <option value="<?php echo esc_attr($table->ID); ?>">
                            <?php echo esc_html($table->post_title . $description); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pds-table-selector-content">
                <div class="pds-table-selector-placeholder">
                    <?php _e('Select a table above to display it here.', 'pds-post-tables'); ?>
                </div>
            </div>
        </div>
        
        <style>
        .pds-table-selector-wrap {
            margin: 20px 0;
        }
        .pds-table-selector-header {
            margin-bottom: 15px;
        }
        .pds-table-selector-dropdown {
            padding: 10px 15px;
            font-size: 16px;
            min-width: 300px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fff;
        }
        .pds-table-selector-placeholder {
            padding: 40px;
            text-align: center;
            background: #f9f9f9;
            border: 1px dashed #ddd;
            border-radius: 4px;
            color: #666;
        }
        .pds-table-selector-loading {
            padding: 40px;
            text-align: center;
        }
        .pds-table-selector-loading::after {
            content: '';
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #ddd;
            border-top-color: #2271b1;
            border-radius: 50%;
            animation: pds-selector-spin 0.8s linear infinite;
            margin-left: 10px;
            vertical-align: middle;
        }
        @keyframes pds-selector-spin {
            to { transform: rotate(360deg); }
        }
        </style>
        
        <script>
        jQuery(function($) {
            var $wrap = $('#<?php echo esc_js($selector_id); ?>');
            var $select = $wrap.find('.pds-table-selector-dropdown');
            var $content = $wrap.find('.pds-table-selector-content');
            
            $select.on('change', function() {
                var tableId = $(this).val();
                
                if (!tableId) {
                    $content.html('<div class="pds-table-selector-placeholder"><?php echo esc_js(__('Select a table above to display it here.', 'pds-post-tables')); ?></div>');
                    return;
                }
                
                // Show loading
                $content.html('<div class="pds-table-selector-loading"><?php echo esc_js(__('Loading table...', 'pds-post-tables')); ?></div>');
                
                // Load table via AJAX
                $.ajax({
                    url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                    type: 'POST',
                    data: {
                        action: 'pds_load_table',
                        table_id: tableId,
                        nonce: '<?php echo wp_create_nonce('pds_load_table'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $content.html(response.data.html);
                            // Initialize the table - find the element with data-config and create instance
                            var $tableEl = $content.find('.pds-post-table[data-config]');
                            if ($tableEl.length && typeof PDSPostTable !== 'undefined') {
                                new PDSPostTable($tableEl[0]);
                            }
                        } else {
                            $content.html('<div class="pds-table-error">' + (response.data || 'Error loading table') + '</div>');
                        }
                    },
                    error: function() {
                        $content.html('<div class="pds-table-error"><?php echo esc_js(__('Failed to load table.', 'pds-post-tables')); ?></div>');
                    }
                });
            });
        });
        </script>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Check if current user can view a table
     */
    public function user_can_view_table($table_id) {
        // Admins can always view
        if (current_user_can('manage_options')) {
            return true;
        }
        
        $permissions = json_decode(get_post_meta($table_id, '_pds_table_permissions', true), true) ?: [];
        $access_type = $permissions['access_type'] ?? 'all';
        
        // All logged-in users
        if ($access_type === 'all') {
            return is_user_logged_in();
        }
        
        // Check roles
        if ($access_type === 'roles') {
            $allowed_roles = $permissions['roles'] ?? [];
            $user = wp_get_current_user();
            
            foreach ($user->roles as $role) {
                if (in_array($role, $allowed_roles)) {
                    return true;
                }
            }
            return false;
        }
        
        // Check specific users
        if ($access_type === 'users') {
            $allowed_users = $permissions['users'] ?? [];
            return in_array(get_current_user_id(), $allowed_users);
        }
        
        return false;
    }
    
    /**
     * Render error message
     */
    private function render_error($message) {
        if (current_user_can('edit_posts')) {
            return '<div class="pds-table-error">' . esc_html($message) . '</div>';
        }
        return '';
    }
}
