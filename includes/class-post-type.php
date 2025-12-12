<?php
/**
 * Post Type Registration
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Post_Type {
    
    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
    }
    
    public function register_post_type() {
        $labels = [
            'name'                  => _x('Post Tables', 'Post type general name', 'pds-post-tables'),
            'singular_name'         => _x('Post Table', 'Post type singular name', 'pds-post-tables'),
            'menu_name'             => _x('Post Tables', 'Admin Menu text', 'pds-post-tables'),
            'name_admin_bar'        => _x('Post Table', 'Add New on Toolbar', 'pds-post-tables'),
            'add_new'               => __('Add New', 'pds-post-tables'),
            'add_new_item'          => __('Add New Table', 'pds-post-tables'),
            'new_item'              => __('New Table', 'pds-post-tables'),
            'edit_item'             => __('Edit Table', 'pds-post-tables'),
            'view_item'             => __('View Table', 'pds-post-tables'),
            'all_items'             => __('All Tables', 'pds-post-tables'),
            'search_items'          => __('Search Tables', 'pds-post-tables'),
            'parent_item_colon'     => __('Parent Tables:', 'pds-post-tables'),
            'not_found'             => __('No tables found.', 'pds-post-tables'),
            'not_found_in_trash'    => __('No tables found in Trash.', 'pds-post-tables'),
        ];
        
        $args = [
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => 30,
            'menu_icon'          => 'dashicons-editor-table',
            'supports'           => ['title'],
            'show_in_rest'       => true,
        ];
        
        register_post_type('pds_post_table', $args);
    }
    
    /**
     * Get table configuration
     */
    public static function get_table_config($table_id) {
        // Get saved settings and merge with defaults
        $saved_settings = json_decode(get_post_meta($table_id, '_pds_table_settings', true), true) ?: [];
        $settings = wp_parse_args($saved_settings, self::get_default_settings());
        
        $config = [
            'id' => $table_id,
            'title' => get_the_title($table_id),
            'source_post_type' => get_post_meta($table_id, '_pds_table_source_post_type', true),
            'columns' => json_decode(get_post_meta($table_id, '_pds_table_columns', true), true) ?: [],
            'column_defaults' => json_decode(get_post_meta($table_id, '_pds_table_column_defaults', true), true) ?: [],
            'conditional_rules' => json_decode(get_post_meta($table_id, '_pds_table_conditional_rules', true), true) ?: [],
            'query_filters' => json_decode(get_post_meta($table_id, '_pds_table_query_filters', true), true) ?: [],
            'filter_logic' => get_post_meta($table_id, '_pds_table_filter_logic', true) ?: 'AND',
            'query_args' => json_decode(get_post_meta($table_id, '_pds_table_query_args', true), true) ?: [],
            'settings' => $settings,
        ];
        
        return $config;
    }
    
    /**
     * Save table configuration
     */
    public static function save_table_config($table_id, $config) {
        if (isset($config['source_post_type'])) {
            update_post_meta($table_id, '_pds_table_source_post_type', sanitize_text_field($config['source_post_type']));
        }
        
        if (isset($config['columns'])) {
            update_post_meta($table_id, '_pds_table_columns', wp_json_encode($config['columns']));
        }
        
        if (isset($config['column_defaults'])) {
            update_post_meta($table_id, '_pds_table_column_defaults', wp_json_encode($config['column_defaults']));
        }
        
        if (isset($config['conditional_rules'])) {
            update_post_meta($table_id, '_pds_table_conditional_rules', wp_json_encode($config['conditional_rules']));
        }
        
        if (isset($config['query_args'])) {
            update_post_meta($table_id, '_pds_table_query_args', wp_json_encode($config['query_args']));
        }
        
        if (isset($config['settings'])) {
            update_post_meta($table_id, '_pds_table_settings', wp_json_encode($config['settings']));
        }
    }
    
    /**
     * Default table settings
     */
    public static function get_default_settings() {
        return [
            'pagination' => true,
            'page_size' => 25,
            'page_sizes' => [10, 25, 50, 100],
            'sortable' => true,
            'filterable' => true,
            'export_csv' => true,
            'export_xlsx' => false,
            'row_height' => 'normal',
            'header_visible' => true,
            'default_sort_column' => '',
            'default_sort_dir' => 'asc',
            'group_by' => '',
            'group_start_open' => true,
            'allow_column_toggle' => true,
            'persist_state' => true,
            'save_mode' => 'immediate',
            'autosave_interval' => 5,
        ];
    }
}
