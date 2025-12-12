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
            // Check for type override in column defaults first
            $column_defaults = $config['column_defaults'][$column['id']] ?? [];
            $type_override = !empty($column_defaults['type_override']) ? $column_defaults['type_override'] : null;

            // Start with the stored column type
            $column_type = $column['type'];

            // For ACF fields, re-check the actual field type to catch WYSIWYG/user fields (unless overridden)
            $acf_field = null;
            if (!$type_override && $column['source'] === 'acf' && function_exists('acf_get_field')) {
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
                        'user' => 'user',
                    ];
                    if (isset($acf_type_map[$acf_field['type']])) {
                        $column_type = $acf_type_map[$acf_field['type']];
                    }
                }
            }

            // Apply type override if set (takes highest priority)
            if ($type_override) {
                $column_type = $type_override;
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
            if (!empty($column_defaults['width'])) {
                $col_def['width'] = (int) $column_defaults['width'];
            }

            // Add horizontal align
            if (!empty($column_defaults['align'])) {
                $col_def['hozAlign'] = $column_defaults['align'];
            }

            // Add max characters for truncation
            if (!empty($column_defaults['max_chars'])) {
                $col_def['maxChars'] = (int) $column_defaults['max_chars'];
            }

            // Add custom sort order for select fields
            if (!empty($column_defaults['sort_order'])) {
                $col_def['sortOrder'] = $column_defaults['sort_order'];
            }

            // Store column type for formatting (uses override if set)
            $col_def['fieldType'] = $column_type;

            // Store options for select fields
            if (!empty($column['options']) && $column_type === 'select') {
                $col_def['fieldOptions'] = $column['options'];
            }

            // Handle user fields - pass options and multiple setting
            if ($column_type === 'user') {
                // Get user options if not already set
                if (!empty($column['options'])) {
                    $col_def['fieldOptions'] = $column['options'];
                } elseif ($acf_field) {
                    // Build user options from ACF field settings
                    $col_def['fieldOptions'] = $this->get_user_options_for_field($acf_field);
                }
                $col_def['multiple'] = !empty($column['multiple']) || ($acf_field && !empty($acf_field['multiple']));
            }

            $columns[] = $col_def;
        }

        return $columns;
    }

    /**
     * Get user options for ACF user field
     */
    private function get_user_options_for_field($acf_field) {
        $options = [];

        // Build user query args based on ACF field settings
        $args = ['fields' => ['ID', 'display_name']];

        // ACF user field can restrict by role
        if (!empty($acf_field['role'])) {
            $roles = is_array($acf_field['role']) ? $acf_field['role'] : [$acf_field['role']];
            $roles = array_filter($roles);
            if (!empty($roles)) {
                $args['role__in'] = $roles;
            }
        }

        $users = get_users($args);

        foreach ($users as $user) {
            $options[$user->ID] = $user->display_name;
        }

        return $options;
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
            'user' => 'string',
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
                <label for="<?php echo esc_attr($selector_id); ?>-dropdown"><?php _e('Select Table', 'pds-post-tables'); ?></label>
                <select class="pds-table-selector-dropdown" id="<?php echo esc_attr($selector_id); ?>-dropdown">
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
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        .pds-table-selector-header {
            margin-bottom: 20px;
            padding: 20px 24px;
            background: linear-gradient(to bottom, #fafafa, #f5f5f5);
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }
        .pds-table-selector-header label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #555;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .pds-table-selector-dropdown {
            width: 100%;
            max-width: 400px;
            padding: 12px 16px;
            font-size: 15px;
            line-height: 1.4;
            color: #333;
            border: 1px solid #ccc;
            border-radius: 6px;
            background: #fff;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 12px;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .pds-table-selector-dropdown:hover {
            border-color: #999;
        }
        .pds-table-selector-dropdown:focus {
            outline: none;
            border-color: #666;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.08);
        }
        .pds-table-selector-dropdown option {
            padding: 8px;
        }
        .pds-table-selector-content {
            min-height: 200px;
        }
        .pds-table-selector-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 200px;
            padding: 40px 20px;
            text-align: center;
            background: #fafafa;
            border: 2px dashed #ddd;
            border-radius: 8px;
            color: #888;
            font-size: 15px;
        }
        .pds-table-selector-placeholder::before {
            content: '';
            display: block;
            width: 48px;
            height: 48px;
            margin-bottom: 16px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23ccc' stroke-width='1.5'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 0v1.5c0 .621-.504 1.125-1.125 1.125'/%3E%3C/svg%3E");
            background-size: contain;
            opacity: 0.6;
        }
        .pds-table-selector-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 200px;
            padding: 40px 20px;
            background: #fafafa;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            color: #666;
            font-size: 15px;
        }
        .pds-table-selector-loading::after {
            content: '';
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #e0e0e0;
            border-top-color: #666;
            border-radius: 50%;
            animation: pds-selector-spin 0.8s linear infinite;
            margin-left: 12px;
        }
        @keyframes pds-selector-spin {
            to { transform: rotate(360deg); }
        }
        .pds-table-selector-login,
        .pds-table-selector-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 120px;
            padding: 30px 20px;
            background: #fafafa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            color: #666;
            font-size: 15px;
            text-align: center;
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
