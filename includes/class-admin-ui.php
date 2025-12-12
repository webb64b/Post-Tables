<?php
/**
 * Admin UI - Meta boxes and column builder
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Admin_UI {
    
    private $field_scanner;
    
    public function __construct($field_scanner) {
        $this->field_scanner = $field_scanner;
        
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_pds_post_table', [$this, 'save_meta_boxes'], 10, 2);
        add_filter('manage_pds_post_table_posts_columns', [$this, 'admin_columns']);
        add_action('manage_pds_post_table_posts_custom_column', [$this, 'admin_column_content'], 10, 2);
    }
    
    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'pds_table_data_source',
            __('Data Source', 'pds-post-tables'),
            [$this, 'render_data_source_meta_box'],
            'pds_post_table',
            'normal',
            'high'
        );
        
        add_meta_box(
            'pds_table_query_filters',
            __('Data Filters', 'pds-post-tables'),
            [$this, 'render_query_filters_meta_box'],
            'pds_post_table',
            'normal',
            'high'
        );
        
        add_meta_box(
            'pds_table_columns',
            __('Table Columns', 'pds-post-tables'),
            [$this, 'render_columns_meta_box'],
            'pds_post_table',
            'normal',
            'high'
        );
        
        add_meta_box(
            'pds_table_column_defaults',
            __('Column Formatting Defaults', 'pds-post-tables'),
            [$this, 'render_column_defaults_meta_box'],
            'pds_post_table',
            'normal',
            'default'
        );
        
        add_meta_box(
            'pds_table_conditional_rules',
            __('Conditional Formatting Rules', 'pds-post-tables'),
            [$this, 'render_conditional_rules_meta_box'],
            'pds_post_table',
            'normal',
            'default'
        );
        
        add_meta_box(
            'pds_table_settings',
            __('Table Settings', 'pds-post-tables'),
            [$this, 'render_settings_meta_box'],
            'pds_post_table',
            'side',
            'default'
        );
        
        add_meta_box(
            'pds_table_shortcode',
            __('Shortcode', 'pds-post-tables'),
            [$this, 'render_shortcode_meta_box'],
            'pds_post_table',
            'side',
            'high'
        );
        
        add_meta_box(
            'pds_table_permissions',
            __('Access Permissions', 'pds-post-tables'),
            [$this, 'render_permissions_meta_box'],
            'pds_post_table',
            'side',
            'default'
        );
    }
    
    /**
     * Render data source meta box
     */
    public function render_data_source_meta_box($post) {
        wp_nonce_field('pds_post_tables_save', 'pds_post_tables_nonce');
        
        $source_post_type = get_post_meta($post->ID, '_pds_table_source_post_type', true);
        $post_types = get_post_types(['public' => true], 'objects');
        ?>
        <div class="pds-meta-box">
            <p>
                <label for="pds_source_post_type"><strong><?php _e('Post Type', 'pds-post-tables'); ?></strong></label>
                <select name="pds_source_post_type" id="pds_source_post_type" class="widefat">
                    <option value=""><?php _e('Select a post type...', 'pds-post-tables'); ?></option>
                    <?php foreach ($post_types as $pt) : ?>
                        <?php if ($pt->name !== 'pds_post_table') : ?>
                            <option value="<?php echo esc_attr($pt->name); ?>" <?php selected($source_post_type, $pt->name); ?>>
                                <?php echo esc_html($pt->label); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="description">
                <?php _e('Select the post type that this table will display.', 'pds-post-tables'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Render query filters meta box
     */
    public function render_query_filters_meta_box($post) {
        $filters = json_decode(get_post_meta($post->ID, '_pds_table_query_filters', true), true) ?: [];
        $filter_logic = get_post_meta($post->ID, '_pds_table_filter_logic', true) ?: 'AND';
        $source_post_type = get_post_meta($post->ID, '_pds_table_source_post_type', true);
        ?>
        <div class="pds-meta-box pds-query-filters">
            <p class="description">
                <?php _e('Filter which posts are loaded into the table. Only posts matching these conditions will be displayed.', 'pds-post-tables'); ?>
            </p>
            
            <div class="pds-filter-logic">
                <label><strong><?php _e('Match:', 'pds-post-tables'); ?></strong></label>
                <select name="pds_filter_logic" id="pds_filter_logic">
                    <option value="AND" <?php selected($filter_logic, 'AND'); ?>><?php _e('All conditions (AND)', 'pds-post-tables'); ?></option>
                    <option value="OR" <?php selected($filter_logic, 'OR'); ?>><?php _e('Any condition (OR)', 'pds-post-tables'); ?></option>
                </select>
            </div>
            
            <div id="pds-filters-container">
                <?php if (empty($filters)) : ?>
                    <p class="pds-no-filters"><?php _e('No filters added. All posts will be displayed.', 'pds-post-tables'); ?></p>
                <?php else : ?>
                    <?php foreach ($filters as $index => $filter) : ?>
                        <?php $this->render_filter_row($filter, $index); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="pds-filters-actions">
                <button type="button" class="button button-secondary" id="pds-add-filter" <?php echo empty($source_post_type) ? 'disabled' : ''; ?>>
                    <?php _e('+ Add Filter', 'pds-post-tables'); ?>
                </button>
            </div>
            
            <input type="hidden" name="pds_table_query_filters" id="pds_table_query_filters" value="<?php echo esc_attr(wp_json_encode($filters)); ?>">
        </div>
        
        <!-- Filter Row Template -->
        <script type="text/html" id="tmpl-pds-filter-row">
            <?php $this->render_filter_row([], '{{index}}'); ?>
        </script>
        <?php
    }
    
    /**
     * Render a single filter row
     */
    private function render_filter_row($filter, $index) {
        $filter = wp_parse_args($filter, [
            'id' => 'filter_' . wp_generate_uuid4(),
            'field' => '',
            'source' => '',
            'operator' => 'equals',
            'value' => '',
        ]);
        ?>
        <div class="pds-filter-row" data-index="<?php echo esc_attr($index); ?>">
            <div class="pds-filter-fields">
                <div class="pds-filter-field-select">
                    <label><?php _e('Field', 'pds-post-tables'); ?></label>
                    <select class="pds-filter-field widefat">
                        <option value=""><?php _e('Select field...', 'pds-post-tables'); ?></option>
                    </select>
                    <input type="hidden" class="pds-filter-field-key" value="<?php echo esc_attr($filter['field']); ?>">
                    <input type="hidden" class="pds-filter-source" value="<?php echo esc_attr($filter['source']); ?>">
                    <input type="hidden" class="pds-filter-id" value="<?php echo esc_attr($filter['id']); ?>">
                </div>
                
                <div class="pds-filter-operator-select">
                    <label><?php _e('Operator', 'pds-post-tables'); ?></label>
                    <select class="pds-filter-operator widefat">
                        <option value="equals" <?php selected($filter['operator'], 'equals'); ?>><?php _e('Equals', 'pds-post-tables'); ?></option>
                        <option value="not_equals" <?php selected($filter['operator'], 'not_equals'); ?>><?php _e('Not Equals', 'pds-post-tables'); ?></option>
                        <option value="contains" <?php selected($filter['operator'], 'contains'); ?>><?php _e('Contains', 'pds-post-tables'); ?></option>
                        <option value="not_contains" <?php selected($filter['operator'], 'not_contains'); ?>><?php _e('Does Not Contain', 'pds-post-tables'); ?></option>
                        <option value="starts_with" <?php selected($filter['operator'], 'starts_with'); ?>><?php _e('Starts With', 'pds-post-tables'); ?></option>
                        <option value="ends_with" <?php selected($filter['operator'], 'ends_with'); ?>><?php _e('Ends With', 'pds-post-tables'); ?></option>
                        <option value="greater_than" <?php selected($filter['operator'], 'greater_than'); ?>><?php _e('Greater Than', 'pds-post-tables'); ?></option>
                        <option value="less_than" <?php selected($filter['operator'], 'less_than'); ?>><?php _e('Less Than', 'pds-post-tables'); ?></option>
                        <option value="greater_equal" <?php selected($filter['operator'], 'greater_equal'); ?>><?php _e('Greater Than or Equal', 'pds-post-tables'); ?></option>
                        <option value="less_equal" <?php selected($filter['operator'], 'less_equal'); ?>><?php _e('Less Than or Equal', 'pds-post-tables'); ?></option>
                        <option value="is_empty" <?php selected($filter['operator'], 'is_empty'); ?>><?php _e('Is Empty', 'pds-post-tables'); ?></option>
                        <option value="is_not_empty" <?php selected($filter['operator'], 'is_not_empty'); ?>><?php _e('Is Not Empty', 'pds-post-tables'); ?></option>
                        <option value="in" <?php selected($filter['operator'], 'in'); ?>><?php _e('In List (comma-separated)', 'pds-post-tables'); ?></option>
                        <option value="not_in" <?php selected($filter['operator'], 'not_in'); ?>><?php _e('Not In List (comma-separated)', 'pds-post-tables'); ?></option>
                    </select>
                </div>
                
                <div class="pds-filter-value-input">
                    <label><?php _e('Value', 'pds-post-tables'); ?></label>
                    <input type="text" class="pds-filter-value widefat" value="<?php echo esc_attr($filter['value']); ?>" placeholder="<?php _e('Enter value...', 'pds-post-tables'); ?>">
                </div>
            </div>
            
            <div class="pds-filter-actions">
                <button type="button" class="button pds-remove-filter" title="<?php _e('Remove Filter', 'pds-post-tables'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render columns meta box
     */
    public function render_columns_meta_box($post) {
        $columns = json_decode(get_post_meta($post->ID, '_pds_table_columns', true), true) ?: [];
        $source_post_type = get_post_meta($post->ID, '_pds_table_source_post_type', true);
        ?>
        <div class="pds-meta-box pds-columns-builder">
            <div id="pds-columns-container">
                <?php if (empty($columns)) : ?>
                    <p class="pds-no-columns"><?php _e('No columns added yet. Select a post type above and add columns.', 'pds-post-tables'); ?></p>
                <?php else : ?>
                    <?php foreach ($columns as $index => $column) : ?>
                        <?php $this->render_column_row($column, $index); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="pds-columns-actions">
                <button type="button" class="button button-secondary" id="pds-add-column" <?php echo empty($source_post_type) ? 'disabled' : ''; ?>>
                    <?php _e('+ Add Column', 'pds-post-tables'); ?>
                </button>
            </div>
            
            <input type="hidden" name="pds_table_columns" id="pds_table_columns" value="<?php echo esc_attr(wp_json_encode($columns)); ?>">
        </div>
        
        <!-- Column Row Template -->
        <script type="text/html" id="tmpl-pds-column-row">
            <?php $this->render_column_row([], '{{index}}'); ?>
        </script>
        <?php
    }
    
    /**
     * Render a single column row
     */
    private function render_column_row($column = [], $index = 0) {
        $defaults = [
            'id' => 'col_' . wp_generate_uuid4(),
            'field_key' => '',
            'source' => '',
            'label' => '',
            'type' => 'text',
            'editable' => true,
            'sortable' => true,
            'filterable' => false,
            'frozen' => false,
            'options' => [],
        ];
        $column = wp_parse_args($column, $defaults);
        ?>
        <div class="pds-column-row" data-index="<?php echo esc_attr($index); ?>">
            <div class="pds-column-handle">
                <span class="dashicons dashicons-menu"></span>
            </div>
            
            <div class="pds-column-field">
                <select class="pds-column-field-select" data-field="field_key">
                    <option value=""><?php _e('Select field...', 'pds-post-tables'); ?></option>
                </select>
                <input type="hidden" class="pds-column-source" data-field="source" value="<?php echo esc_attr($column['source']); ?>">
                <input type="hidden" class="pds-column-type" data-field="type" value="<?php echo esc_attr($column['type']); ?>">
                <input type="hidden" class="pds-column-options" data-field="options" value="<?php echo esc_attr(wp_json_encode($column['options'])); ?>">
            </div>
            
            <div class="pds-column-label">
                <input type="text" class="pds-column-label-input" data-field="label" value="<?php echo esc_attr($column['label']); ?>" placeholder="<?php _e('Column Label', 'pds-post-tables'); ?>">
            </div>
            
            <div class="pds-column-options-toggles">
                <label class="pds-toggle" title="<?php _e('Freeze this column (stays visible when scrolling)', 'pds-post-tables'); ?>">
                    <input type="checkbox" class="pds-column-frozen" data-field="frozen" <?php checked($column['frozen']); ?>>
                    <span><?php _e('Freeze', 'pds-post-tables'); ?></span>
                </label>
                <label class="pds-toggle">
                    <input type="checkbox" class="pds-column-editable" data-field="editable" <?php checked($column['editable']); ?>>
                    <span><?php _e('Edit', 'pds-post-tables'); ?></span>
                </label>
                <label class="pds-toggle">
                    <input type="checkbox" class="pds-column-sortable" data-field="sortable" <?php checked($column['sortable']); ?>>
                    <span><?php _e('Sort', 'pds-post-tables'); ?></span>
                </label>
                <label class="pds-toggle">
                    <input type="checkbox" class="pds-column-filterable" data-field="filterable" <?php checked($column['filterable']); ?>>
                    <span><?php _e('Filter', 'pds-post-tables'); ?></span>
                </label>
            </div>
            
            <div class="pds-column-remove">
                <button type="button" class="button-link pds-remove-column" title="<?php _e('Remove column', 'pds-post-tables'); ?>">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            
            <input type="hidden" class="pds-column-id" data-field="id" value="<?php echo esc_attr($column['id']); ?>">
            <input type="hidden" class="pds-column-field-key" value="<?php echo esc_attr($column['field_key']); ?>">
        </div>
        <?php
    }
    
    /**
     * Render column defaults meta box
     */
    public function render_column_defaults_meta_box($post) {
        $column_defaults = json_decode(get_post_meta($post->ID, '_pds_table_column_defaults', true), true) ?: [];
        $columns = json_decode(get_post_meta($post->ID, '_pds_table_columns', true), true) ?: [];
        ?>
        <div class="pds-meta-box pds-column-defaults">
            <div id="pds-column-defaults-container">
                <?php if (empty($columns)) : ?>
                    <p class="pds-no-columns"><?php _e('Add columns above to configure their formatting.', 'pds-post-tables'); ?></p>
                <?php else : ?>
                    <?php foreach ($columns as $column) : ?>
                        <?php 
                        $defaults = $column_defaults[$column['id']] ?? [];
                        $this->render_column_defaults_row($column, $defaults); 
                        ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <input type="hidden" name="pds_table_column_defaults" id="pds_table_column_defaults" value="<?php echo esc_attr(wp_json_encode($column_defaults)); ?>">
        </div>
        
        <!-- Column Defaults Row Template -->
        <script type="text/html" id="tmpl-pds-column-defaults-row">
            <?php $this->render_column_defaults_row(['id' => '{{id}}', 'label' => '{{label}}', 'type' => '{{type}}'], []); ?>
        </script>
        <?php
    }
    
    /**
     * Render column defaults row
     * All type-specific fields are included but hidden by default, JS shows relevant ones
     */
    private function render_column_defaults_row($column, $defaults = []) {
        $default_values = [
            'align' => 'left',
            'width' => '',
            'max_chars' => '',
            'sort_order' => '',
            'date_format' => 'm/d/Y',
            'number_decimals' => 0,
            'number_thousands' => true,
            'number_prefix' => '',
            'number_suffix' => '',
            'background' => '',
            'color' => '',
            'font_weight' => 'normal',
        ];
        $defaults = wp_parse_args($defaults, $default_values);
        $type = $column['type'];
        ?>
        <div class="pds-column-defaults-row" data-column-id="<?php echo esc_attr($column['id']); ?>" data-column-type="<?php echo esc_attr($type); ?>">
            <div class="pds-defaults-header">
                <strong><?php echo esc_html($column['label'] ?: $column['id']); ?></strong>
                <span class="pds-field-type">(<?php echo esc_html($type); ?>)</span>
            </div>
            
            <div class="pds-defaults-fields">
                <div class="pds-defaults-row">
                    <label><?php _e('Align', 'pds-post-tables'); ?></label>
                    <select data-default="align">
                        <option value="left" <?php selected($defaults['align'], 'left'); ?>><?php _e('Left', 'pds-post-tables'); ?></option>
                        <option value="center" <?php selected($defaults['align'], 'center'); ?>><?php _e('Center', 'pds-post-tables'); ?></option>
                        <option value="right" <?php selected($defaults['align'], 'right'); ?>><?php _e('Right', 'pds-post-tables'); ?></option>
                    </select>
                    
                    <label><?php _e('Width', 'pds-post-tables'); ?></label>
                    <input type="number" data-default="width" value="<?php echo esc_attr($defaults['width']); ?>" placeholder="Auto" style="width: 70px;">
                    <span>px</span>
                </div>
                
                <!-- Text options - shown for text, textarea, wysiwyg -->
                <div class="pds-defaults-row pds-text-options" data-show-for="text,textarea,wysiwyg" style="<?php echo in_array($type, ['text', 'textarea', 'wysiwyg']) ? '' : 'display:none;'; ?>">
                    <label><?php _e('Max Characters', 'pds-post-tables'); ?></label>
                    <input type="number" data-default="max_chars" value="<?php echo esc_attr($defaults['max_chars']); ?>" placeholder="<?php esc_attr_e('No limit', 'pds-post-tables'); ?>" style="width: 80px;" min="0">
                    <span class="description" style="margin-left: 10px; color: #666;"><?php _e('Truncate long text (click cell to expand)', 'pds-post-tables'); ?></span>
                </div>
                
                <!-- Date options - shown for date, datetime -->
                <div class="pds-defaults-row pds-date-options" data-show-for="date,datetime" style="<?php echo in_array($type, ['date', 'datetime']) ? '' : 'display:none;'; ?>">
                    <label><?php _e('Date Format', 'pds-post-tables'); ?></label>
                    <select data-default="date_format">
                        <option value="m/d/Y" <?php selected($defaults['date_format'], 'm/d/Y'); ?>>12/31/2024</option>
                        <option value="d/m/Y" <?php selected($defaults['date_format'], 'd/m/Y'); ?>>31/12/2024</option>
                        <option value="Y-m-d" <?php selected($defaults['date_format'], 'Y-m-d'); ?>>2024-12-31</option>
                        <option value="F j, Y" <?php selected($defaults['date_format'], 'F j, Y'); ?>>December 31, 2024</option>
                        <option value="M j, Y" <?php selected($defaults['date_format'], 'M j, Y'); ?>>Dec 31, 2024</option>
                    </select>
                </div>
                
                <!-- Number options - shown for number -->
                <div class="pds-defaults-row pds-number-options" data-show-for="number" style="<?php echo $type === 'number' ? '' : 'display:none;'; ?>">
                    <label><?php _e('Decimals', 'pds-post-tables'); ?></label>
                    <input type="number" data-default="number_decimals" value="<?php echo esc_attr($defaults['number_decimals']); ?>" min="0" max="10" style="width: 60px;">
                    
                    <label>
                        <input type="checkbox" data-default="number_thousands" <?php checked($defaults['number_thousands']); ?>>
                        <?php _e('Thousands separator', 'pds-post-tables'); ?>
                    </label>
                    
                    <label><?php _e('Prefix', 'pds-post-tables'); ?></label>
                    <input type="text" data-default="number_prefix" value="<?php echo esc_attr($defaults['number_prefix']); ?>" placeholder="$" style="width: 40px;">
                    
                    <label><?php _e('Suffix', 'pds-post-tables'); ?></label>
                    <input type="text" data-default="number_suffix" value="<?php echo esc_attr($defaults['number_suffix']); ?>" placeholder="%" style="width: 40px;">
                </div>
                
                <!-- Select options - shown for select -->
                <div class="pds-defaults-row pds-select-options" data-show-for="select" style="<?php echo $type === 'select' ? '' : 'display:none;'; ?>">
                    <label><?php _e('Custom Sort Order', 'pds-post-tables'); ?></label>
                    <input type="text" data-default="sort_order" value="<?php echo esc_attr($defaults['sort_order'] ?? ''); ?>" placeholder="<?php esc_attr_e('e.g., Draft,Pending,Published', 'pds-post-tables'); ?>" style="width: 300px;">
                    <span class="description" style="margin-left: 10px; color: #666;"><?php _e('Comma-separated values in desired order', 'pds-post-tables'); ?></span>
                </div>
                
                <div class="pds-defaults-row pds-style-options">
                    <label><?php _e('Background', 'pds-post-tables'); ?></label>
                    <input type="text" class="pds-color-picker" data-default="background" value="<?php echo esc_attr($defaults['background']); ?>">
                    
                    <label><?php _e('Text Color', 'pds-post-tables'); ?></label>
                    <input type="text" class="pds-color-picker" data-default="color" value="<?php echo esc_attr($defaults['color']); ?>">
                    
                    <label><?php _e('Font', 'pds-post-tables'); ?></label>
                    <select data-default="font_weight">
                        <option value="normal" <?php selected($defaults['font_weight'], 'normal'); ?>><?php _e('Normal', 'pds-post-tables'); ?></option>
                        <option value="bold" <?php selected($defaults['font_weight'], 'bold'); ?>><?php _e('Bold', 'pds-post-tables'); ?></option>
                    </select>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render conditional rules meta box
     */
    public function render_conditional_rules_meta_box($post) {
        $rules = json_decode(get_post_meta($post->ID, '_pds_table_conditional_rules', true), true) ?: [];
        $columns = json_decode(get_post_meta($post->ID, '_pds_table_columns', true), true) ?: [];
        ?>
        <div class="pds-meta-box pds-conditional-rules">
            <div id="pds-rules-container">
                <?php if (empty($rules)) : ?>
                    <p class="pds-no-rules"><?php _e('No formatting rules added yet.', 'pds-post-tables'); ?></p>
                <?php else : ?>
                    <?php foreach ($rules as $index => $rule) : ?>
                        <?php $this->render_rule_row($rule, $index, $columns); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="pds-rules-actions">
                <button type="button" class="button button-secondary" id="pds-add-cell-rule">
                    <?php _e('+ Add Cell Rule', 'pds-post-tables'); ?>
                </button>
                <button type="button" class="button button-secondary" id="pds-add-row-rule">
                    <?php _e('+ Add Row Rule', 'pds-post-tables'); ?>
                </button>
            </div>
            
            <input type="hidden" name="pds_table_conditional_rules" id="pds_table_conditional_rules" value="<?php echo esc_attr(wp_json_encode($rules)); ?>">
        </div>
        
        <!-- Rule Row Template -->
        <script type="text/html" id="tmpl-pds-rule-row">
            <?php $this->render_rule_row(['scope' => '{{scope}}'], '{{index}}', []); ?>
        </script>
        <?php
    }
    
    /**
     * Render rule row
     */
    private function render_rule_row($rule, $index, $columns) {
        $defaults = [
            'id' => 'rule_' . wp_generate_uuid4(),
            'scope' => 'cell',
            'target_column' => '',
            'condition' => [
                'field' => '',
                'operator' => 'equals',
                'value' => '',
            ],
            'style' => [
                'background' => '',
                'color' => '',
                'font_weight' => 'normal',
            ],
        ];
        $rule = wp_parse_args($rule, $defaults);
        $rule['condition'] = wp_parse_args($rule['condition'], $defaults['condition']);
        $rule['style'] = wp_parse_args($rule['style'], $defaults['style']);
        ?>
        <div class="pds-rule-row" data-index="<?php echo esc_attr($index); ?>" data-scope="<?php echo esc_attr($rule['scope']); ?>">
            <div class="pds-rule-header">
                <span class="pds-rule-type">
                    <?php echo $rule['scope'] === 'cell' ? __('Cell Rule', 'pds-post-tables') : __('Row Rule', 'pds-post-tables'); ?>
                </span>
                <button type="button" class="button-link pds-remove-rule" title="<?php _e('Remove rule', 'pds-post-tables'); ?>">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            
            <div class="pds-rule-content">
                <div class="pds-rule-target" style="<?php echo ($rule['scope'] !== 'cell' && $rule['scope'] !== '{{scope}}') ? 'display:none;' : ''; ?>">
                    <label><?php _e('Apply to column:', 'pds-post-tables'); ?></label>
                    <select class="pds-rule-target-column" data-rule="target_column">
                        <option value=""><?php _e('Select column...', 'pds-post-tables'); ?></option>
                        <?php foreach ($columns as $column) : ?>
                            <option value="<?php echo esc_attr($column['id']); ?>" <?php selected($rule['target_column'], $column['id']); ?>>
                                <?php echo esc_html($column['label'] ?: $column['field_key']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="pds-rule-condition">
                    <label><?php _e('When', 'pds-post-tables'); ?></label>
                    
                    <select class="pds-rule-condition-field" data-rule="condition.field" style="<?php echo ($rule['scope'] !== 'row' && $rule['scope'] !== '{{scope}}') ? 'display:none;' : ''; ?>">
                        <option value=""><?php _e('Select field...', 'pds-post-tables'); ?></option>
                        <?php foreach ($columns as $column) : ?>
                            <option value="<?php echo esc_attr($column['field_key']); ?>" <?php selected($rule['condition']['field'], $column['field_key']); ?>>
                                <?php echo esc_html($column['label'] ?: $column['field_key']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select class="pds-rule-condition-operator" data-rule="condition.operator">
                        <option value="equals" <?php selected($rule['condition']['operator'], 'equals'); ?>><?php _e('equals', 'pds-post-tables'); ?></option>
                        <option value="not_equals" <?php selected($rule['condition']['operator'], 'not_equals'); ?>><?php _e('does not equal', 'pds-post-tables'); ?></option>
                        <option value="contains" <?php selected($rule['condition']['operator'], 'contains'); ?>><?php _e('contains', 'pds-post-tables'); ?></option>
                        <option value="not_contains" <?php selected($rule['condition']['operator'], 'not_contains'); ?>><?php _e('does not contain', 'pds-post-tables'); ?></option>
                        <option value="greater_than" <?php selected($rule['condition']['operator'], 'greater_than'); ?>><?php _e('is greater than', 'pds-post-tables'); ?></option>
                        <option value="less_than" <?php selected($rule['condition']['operator'], 'less_than'); ?>><?php _e('is less than', 'pds-post-tables'); ?></option>
                        <option value="is_empty" <?php selected($rule['condition']['operator'], 'is_empty'); ?>><?php _e('is empty', 'pds-post-tables'); ?></option>
                        <option value="is_not_empty" <?php selected($rule['condition']['operator'], 'is_not_empty'); ?>><?php _e('is not empty', 'pds-post-tables'); ?></option>
                        <option value="is_true" <?php selected($rule['condition']['operator'], 'is_true'); ?>><?php _e('is checked', 'pds-post-tables'); ?></option>
                        <option value="is_false" <?php selected($rule['condition']['operator'], 'is_false'); ?>><?php _e('is not checked', 'pds-post-tables'); ?></option>
                    </select>
                    
                    <input type="text" class="pds-rule-condition-value" data-rule="condition.value" value="<?php echo esc_attr($rule['condition']['value']); ?>" placeholder="<?php _e('Value (or {{TODAY}})', 'pds-post-tables'); ?>">
                </div>
                
                <div class="pds-rule-style">
                    <label><?php _e('Style:', 'pds-post-tables'); ?></label>
                    
                    <span class="pds-rule-style-item">
                        <label><?php _e('BG', 'pds-post-tables'); ?></label>
                        <input type="text" class="pds-color-picker pds-rule-style-bg" data-rule="style.background" value="<?php echo esc_attr($rule['style']['background']); ?>">
                    </span>
                    
                    <span class="pds-rule-style-item">
                        <label><?php _e('Text', 'pds-post-tables'); ?></label>
                        <input type="text" class="pds-color-picker pds-rule-style-color" data-rule="style.color" value="<?php echo esc_attr($rule['style']['color']); ?>">
                    </span>
                    
                    <span class="pds-rule-style-item">
                        <label>
                            <input type="checkbox" class="pds-rule-style-bold" data-rule="style.font_weight" <?php checked($rule['style']['font_weight'], 'bold'); ?>>
                            <?php _e('Bold', 'pds-post-tables'); ?>
                        </label>
                    </span>
                </div>
            </div>
            
            <input type="hidden" class="pds-rule-id" data-rule="id" value="<?php echo esc_attr($rule['id']); ?>">
            <input type="hidden" class="pds-rule-scope" data-rule="scope" value="<?php echo esc_attr($rule['scope']); ?>">
        </div>
        <?php
    }
    
    /**
     * Render settings meta box
     */
    public function render_settings_meta_box($post) {
        $settings = json_decode(get_post_meta($post->ID, '_pds_table_settings', true), true) ?: [];
        $defaults = PDS_Post_Tables_Post_Type::get_default_settings();
        $settings = wp_parse_args($settings, $defaults);
        
        // Get columns for default sort dropdown
        $columns = json_decode(get_post_meta($post->ID, '_pds_table_columns', true), true) ?: [];
        ?>
        <div class="pds-meta-box pds-settings">
            <p>
                <label>
                    <input type="checkbox" name="pds_settings[pagination]" value="1" <?php checked($settings['pagination']); ?>>
                    <?php _e('Enable pagination', 'pds-post-tables'); ?>
                </label>
            </p>
            
            <p>
                <label for="pds_page_size"><?php _e('Rows per page:', 'pds-post-tables'); ?></label>
                <select name="pds_settings[page_size]" id="pds_page_size">
                    <option value="10" <?php selected($settings['page_size'], 10); ?>>10</option>
                    <option value="25" <?php selected($settings['page_size'], 25); ?>>25</option>
                    <option value="50" <?php selected($settings['page_size'], 50); ?>>50</option>
                    <option value="100" <?php selected($settings['page_size'], 100); ?>>100</option>
                </select>
            </p>
            
            <p>
                <label for="pds_row_height"><?php _e('Row height:', 'pds-post-tables'); ?></label>
                <select name="pds_settings[row_height]" id="pds_row_height">
                    <option value="compact" <?php selected($settings['row_height'], 'compact'); ?>><?php _e('Compact', 'pds-post-tables'); ?></option>
                    <option value="normal" <?php selected($settings['row_height'], 'normal'); ?>><?php _e('Normal', 'pds-post-tables'); ?></option>
                    <option value="comfortable" <?php selected($settings['row_height'], 'comfortable'); ?>><?php _e('Comfortable', 'pds-post-tables'); ?></option>
                </select>
            </p>
            
            <hr>
            
            <p><strong><?php _e('Default Sort', 'pds-post-tables'); ?></strong></p>
            
            <p>
                <label for="pds_default_sort_column"><?php _e('Sort by:', 'pds-post-tables'); ?></label>
                <select name="pds_settings[default_sort_column]" id="pds_default_sort_column">
                    <option value=""><?php _e('None', 'pds-post-tables'); ?></option>
                    <?php foreach ($columns as $column) : ?>
                        <option value="<?php echo esc_attr($column['field_key']); ?>" <?php selected($settings['default_sort_column'] ?? '', $column['field_key']); ?>>
                            <?php echo esc_html($column['label'] ?: $column['field_key']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            
            <p>
                <label for="pds_default_sort_dir"><?php _e('Sort direction:', 'pds-post-tables'); ?></label>
                <select name="pds_settings[default_sort_dir]" id="pds_default_sort_dir">
                    <option value="asc" <?php selected($settings['default_sort_dir'] ?? 'asc', 'asc'); ?>><?php _e('Ascending (A-Z, 0-9)', 'pds-post-tables'); ?></option>
                    <option value="desc" <?php selected($settings['default_sort_dir'] ?? 'asc', 'desc'); ?>><?php _e('Descending (Z-A, 9-0)', 'pds-post-tables'); ?></option>
                </select>
            </p>
            
            <hr>
            
            <p><strong><?php _e('Row Grouping', 'pds-post-tables'); ?></strong></p>
            
            <p>
                <label for="pds_group_by"><?php _e('Group rows by:', 'pds-post-tables'); ?></label>
                <select name="pds_settings[group_by]" id="pds_group_by">
                    <option value=""><?php _e('No grouping', 'pds-post-tables'); ?></option>
                    <?php foreach ($columns as $column) : ?>
                        <option value="<?php echo esc_attr($column['field_key']); ?>" <?php selected($settings['group_by'] ?? '', $column['field_key']); ?>>
                            <?php echo esc_html($column['label'] ?: $column['field_key']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            
            <p>
                <label>
                    <input type="checkbox" name="pds_settings[group_start_open]" value="1" <?php checked($settings['group_start_open'] ?? true); ?>>
                    <?php _e('Groups expanded by default', 'pds-post-tables'); ?>
                </label>
            </p>
            
            <hr>
            
            <p><strong><?php _e('User Features', 'pds-post-tables'); ?></strong></p>
            
            <p>
                <label>
                    <input type="checkbox" name="pds_settings[allow_column_toggle]" value="1" <?php checked($settings['allow_column_toggle'] ?? true); ?>>
                    <?php _e('Allow users to show/hide columns', 'pds-post-tables'); ?>
                </label>
            </p>
            
            <p>
                <label>
                    <input type="checkbox" name="pds_settings[persist_state]" value="1" <?php checked($settings['persist_state'] ?? true); ?>>
                    <?php _e('Remember user preferences (widths, sort, filters)', 'pds-post-tables'); ?>
                </label>
            </p>
            
            <p>
                <label>
                    <input type="checkbox" name="pds_settings[export_csv]" value="1" <?php checked($settings['export_csv']); ?>>
                    <?php _e('Enable CSV export', 'pds-post-tables'); ?>
                </label>
            </p>

            <hr>

            <p><strong><?php _e('Scrollbar Position', 'pds-post-tables'); ?></strong></p>

            <p>
                <label>
                    <input type="radio" name="pds_settings[scrollbar_position]" value="default" <?php checked($settings['scrollbar_position'] ?? 'default', 'default'); ?>>
                    <?php _e('Default - Scrollbar at bottom of table', 'pds-post-tables'); ?>
                </label>
            </p>

            <p>
                <label>
                    <input type="radio" name="pds_settings[scrollbar_position]" value="sticky" <?php checked($settings['scrollbar_position'] ?? 'default', 'sticky'); ?>>
                    <?php _e('Sticky - Scrollbar fixed to bottom of screen', 'pds-post-tables'); ?>
                </label>
            </p>

            <p class="description" style="margin-left: 20px;">
                <?php _e('Sticky scrollbar is useful for long tables where you would otherwise need to scroll to the bottom to access the horizontal scrollbar.', 'pds-post-tables'); ?>
            </p>

            <hr>

            <p><strong><?php _e('Save Behavior', 'pds-post-tables'); ?></strong></p>
            
            <p>
                <label>
                    <input type="radio" name="pds_settings[save_mode]" value="immediate" <?php checked($settings['save_mode'] ?? 'immediate', 'immediate'); ?>>
                    <?php _e('Immediate - Save each change instantly', 'pds-post-tables'); ?>
                </label>
            </p>
            
            <p>
                <label>
                    <input type="radio" name="pds_settings[save_mode]" value="batch" <?php checked($settings['save_mode'] ?? 'immediate', 'batch'); ?>>
                    <?php _e('Batch - Manual save with auto-save', 'pds-post-tables'); ?>
                </label>
            </p>
            
            <p class="pds-batch-save-options" style="<?php echo ($settings['save_mode'] ?? 'immediate') === 'batch' ? '' : 'display:none;'; ?>margin-left: 20px;">
                <label for="pds_autosave_interval"><?php _e('Auto-save interval:', 'pds-post-tables'); ?></label>
                <select name="pds_settings[autosave_interval]" id="pds_autosave_interval">
                    <option value="0" <?php selected($settings['autosave_interval'] ?? 5, 0); ?>><?php _e('Disabled', 'pds-post-tables'); ?></option>
                    <option value="1" <?php selected($settings['autosave_interval'] ?? 5, 1); ?>><?php _e('1 minute', 'pds-post-tables'); ?></option>
                    <option value="2" <?php selected($settings['autosave_interval'] ?? 5, 2); ?>><?php _e('2 minutes', 'pds-post-tables'); ?></option>
                    <option value="5" <?php selected($settings['autosave_interval'] ?? 5, 5); ?>><?php _e('5 minutes', 'pds-post-tables'); ?></option>
                    <option value="10" <?php selected($settings['autosave_interval'] ?? 5, 10); ?>><?php _e('10 minutes', 'pds-post-tables'); ?></option>
                </select>
            </p>
            
            <script>
            jQuery(function($) {
                $('input[name="pds_settings[save_mode]"]').on('change', function() {
                    if ($(this).val() === 'batch') {
                        $('.pds-batch-save-options').show();
                    } else {
                        $('.pds-batch-save-options').hide();
                    }
                });
            });
            </script>
            
            <input type="hidden" name="pds_table_settings" id="pds_table_settings" value="<?php echo esc_attr(wp_json_encode($settings)); ?>">
        </div>
        <?php
    }
    
    /**
     * Render shortcode meta box
     */
    public function render_shortcode_meta_box($post) {
        if ($post->post_status === 'auto-draft') {
            echo '<p class="description">' . __('Save the table to get the shortcode.', 'pds-post-tables') . '</p>';
            return;
        }
        ?>
        <div class="pds-shortcode-box">
            <code id="pds-shortcode-display">[pds_table id="<?php echo $post->ID; ?>"]</code>
            <button type="button" class="button button-small" id="pds-copy-shortcode" title="<?php _e('Copy to clipboard', 'pds-post-tables'); ?>">
                <span class="dashicons dashicons-clipboard"></span>
            </button>
        </div>
        <p class="description">
            <?php _e('Use this shortcode to display the table on any page or post.', 'pds-post-tables'); ?>
        </p>
        <hr style="margin: 15px 0;">
        <p class="description">
            <strong><?php _e('Table Selector:', 'pds-post-tables'); ?></strong><br>
            <code>[pds_table_selector]</code><br>
            <?php _e('Shows a dropdown to choose from all tables the user has access to.', 'pds-post-tables'); ?>
        </p>
        <?php
    }
    
    /**
     * Render permissions meta box
     */
    public function render_permissions_meta_box($post) {
        $permissions = json_decode(get_post_meta($post->ID, '_pds_table_permissions', true), true) ?: [];
        $access_type = $permissions['access_type'] ?? 'all';
        $allowed_roles = $permissions['roles'] ?? [];
        $allowed_users = $permissions['users'] ?? [];
        
        // Get all roles
        global $wp_roles;
        $roles = $wp_roles->roles;
        
        // Get users for the selector (limit to avoid performance issues)
        $users = get_users(['number' => 100, 'orderby' => 'display_name']);
        ?>
        <div class="pds-permissions-box">
            <p>
                <label><strong><?php _e('Who can view this table?', 'pds-post-tables'); ?></strong></label>
            </p>
            
            <p>
                <label>
                    <input type="radio" name="pds_permissions[access_type]" value="all" <?php checked($access_type, 'all'); ?>>
                    <?php _e('All logged-in users', 'pds-post-tables'); ?>
                </label>
            </p>
            
            <p>
                <label>
                    <input type="radio" name="pds_permissions[access_type]" value="roles" <?php checked($access_type, 'roles'); ?>>
                    <?php _e('Specific roles', 'pds-post-tables'); ?>
                </label>
            </p>
            
            <div class="pds-roles-list" style="margin-left: 20px; <?php echo $access_type !== 'roles' ? 'display:none;' : ''; ?>">
                <?php foreach ($roles as $role_key => $role) : ?>
                    <label style="display: block; margin: 3px 0;">
                        <input type="checkbox" name="pds_permissions[roles][]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $allowed_roles)); ?>>
                        <?php echo esc_html($role['name']); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            
            <p>
                <label>
                    <input type="radio" name="pds_permissions[access_type]" value="users" <?php checked($access_type, 'users'); ?>>
                    <?php _e('Specific users', 'pds-post-tables'); ?>
                </label>
            </p>
            
            <div class="pds-users-list" style="margin-left: 20px; <?php echo $access_type !== 'users' ? 'display:none;' : ''; ?>">
                <select name="pds_permissions[users][]" multiple style="width: 100%; height: 150px;">
                    <?php foreach ($users as $user) : ?>
                        <option value="<?php echo esc_attr($user->ID); ?>" <?php selected(in_array($user->ID, $allowed_users)); ?>>
                            <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_email); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e('Hold Ctrl/Cmd to select multiple users.', 'pds-post-tables'); ?></p>
            </div>
        </div>
        
        <script>
        jQuery(function($) {
            $('input[name="pds_permissions[access_type]"]').on('change', function() {
                var val = $(this).val();
                $('.pds-roles-list').toggle(val === 'roles');
                $('.pds-users-list').toggle(val === 'users');
            });
        });
        </script>
        <?php
    }
    
    /**
     * Save meta boxes
     */
    public function save_meta_boxes($post_id, $post) {
        // Verify nonce
        if (!isset($_POST['pds_post_tables_nonce']) || !wp_verify_nonce($_POST['pds_post_tables_nonce'], 'pds_post_tables_save')) {
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
        
        // Save source post type
        if (isset($_POST['pds_source_post_type'])) {
            update_post_meta($post_id, '_pds_table_source_post_type', sanitize_text_field($_POST['pds_source_post_type']));
        }
        
        // Save columns (from hidden JSON field)
        if (isset($_POST['pds_table_columns'])) {
            $raw_columns = stripslashes($_POST['pds_table_columns']);
            $columns = json_decode($raw_columns, true);
            
            if (is_array($columns)) {
                update_post_meta($post_id, '_pds_table_columns', wp_json_encode($columns));
            }
        }
        
        // Save column defaults
        if (isset($_POST['pds_table_column_defaults'])) {
            $defaults = json_decode(stripslashes($_POST['pds_table_column_defaults']), true);
            if (is_array($defaults)) {
                update_post_meta($post_id, '_pds_table_column_defaults', wp_json_encode($defaults));
            }
        }
        
        // Save conditional rules
        if (isset($_POST['pds_table_conditional_rules'])) {
            $rules = json_decode(stripslashes($_POST['pds_table_conditional_rules']), true);
            if (is_array($rules)) {
                update_post_meta($post_id, '_pds_table_conditional_rules', wp_json_encode($rules));
            }
        }
        
        // Save query filters
        if (isset($_POST['pds_table_query_filters'])) {
            $filters = json_decode(stripslashes($_POST['pds_table_query_filters']), true);
            if (is_array($filters)) {
                update_post_meta($post_id, '_pds_table_query_filters', wp_json_encode($filters));
            }
        }
        
        // Save filter logic
        if (isset($_POST['pds_filter_logic'])) {
            update_post_meta($post_id, '_pds_table_filter_logic', sanitize_text_field($_POST['pds_filter_logic']));
        }
        
        // Save settings
        if (isset($_POST['pds_settings'])) {
            $settings = [
                'pagination' => !empty($_POST['pds_settings']['pagination']),
                'page_size' => absint($_POST['pds_settings']['page_size']),
                'row_height' => sanitize_text_field($_POST['pds_settings']['row_height']),
                'export_csv' => !empty($_POST['pds_settings']['export_csv']),
                'default_sort_column' => sanitize_text_field($_POST['pds_settings']['default_sort_column'] ?? ''),
                'default_sort_dir' => in_array($_POST['pds_settings']['default_sort_dir'] ?? 'asc', ['asc', 'desc']) ? $_POST['pds_settings']['default_sort_dir'] : 'asc',
                'group_by' => sanitize_text_field($_POST['pds_settings']['group_by'] ?? ''),
                'group_start_open' => !empty($_POST['pds_settings']['group_start_open']),
                'allow_column_toggle' => !empty($_POST['pds_settings']['allow_column_toggle']),
                'persist_state' => !empty($_POST['pds_settings']['persist_state']),
                'scrollbar_position' => in_array($_POST['pds_settings']['scrollbar_position'] ?? 'default', ['default', 'sticky']) ? $_POST['pds_settings']['scrollbar_position'] : 'default',
                'save_mode' => in_array($_POST['pds_settings']['save_mode'] ?? 'immediate', ['immediate', 'batch']) ? $_POST['pds_settings']['save_mode'] : 'immediate',
                'autosave_interval' => absint($_POST['pds_settings']['autosave_interval'] ?? 5),
            ];
            update_post_meta($post_id, '_pds_table_settings', wp_json_encode($settings));
        }
        
        // Save permissions
        if (isset($_POST['pds_permissions'])) {
            $permissions = [
                'access_type' => sanitize_text_field($_POST['pds_permissions']['access_type'] ?? 'all'),
                'roles' => array_map('sanitize_text_field', $_POST['pds_permissions']['roles'] ?? []),
                'users' => array_map('absint', $_POST['pds_permissions']['users'] ?? []),
            ];
            update_post_meta($post_id, '_pds_table_permissions', wp_json_encode($permissions));
        }
    }
    
    /**
     * Admin columns
     */
    public function admin_columns($columns) {
        $new_columns = [];
        
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            
            if ($key === 'title') {
                $new_columns['post_type'] = __('Post Type', 'pds-post-tables');
                $new_columns['columns'] = __('Columns', 'pds-post-tables');
                $new_columns['shortcode'] = __('Shortcode', 'pds-post-tables');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Admin column content
     */
    public function admin_column_content($column, $post_id) {
        switch ($column) {
            case 'post_type':
                $post_type = get_post_meta($post_id, '_pds_table_source_post_type', true);
                if ($post_type) {
                    $pt_object = get_post_type_object($post_type);
                    echo esc_html($pt_object ? $pt_object->label : $post_type);
                } else {
                    echo '—';
                }
                break;
                
            case 'columns':
                $columns = json_decode(get_post_meta($post_id, '_pds_table_columns', true), true);
                echo $columns ? count($columns) : 0;
                break;
                
            case 'shortcode':
                echo '<code>[pds_table id="' . $post_id . '"]</code>';
                break;
        }
    }
}
