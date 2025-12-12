<?php
/**
 * Field Scanner - Discovers available fields for post types
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Field_Scanner {
    
    /**
     * Core post fields available for all post types
     */
    private $core_fields = [
        'ID' => [
            'label' => 'ID',
            'type' => 'text',
            'editable' => false,
        ],
        'post_title' => [
            'label' => 'Title',
            'type' => 'text',
            'editable' => true,
        ],
        'post_status' => [
            'label' => 'Status',
            'type' => 'select',
            'editable' => true,
            'options' => [
                'publish' => 'Published',
                'draft' => 'Draft',
                'pending' => 'Pending Review',
                'private' => 'Private',
            ],
        ],
        'post_date' => [
            'label' => 'Date',
            'type' => 'date',
            'editable' => true,
        ],
        'post_modified' => [
            'label' => 'Modified Date',
            'type' => 'date',
            'editable' => false,
        ],
        'post_author' => [
            'label' => 'Author',
            'type' => 'select',
            'editable' => true,
            'options' => [], // Populated dynamically
        ],
        'post_excerpt' => [
            'label' => 'Excerpt',
            'type' => 'text',
            'editable' => true,
        ],
        'menu_order' => [
            'label' => 'Menu Order',
            'type' => 'text',
            'editable' => true,
        ],
    ];
    
    /**
     * Get all available fields for a post type
     */
    public function get_fields_for_post_type($post_type) {
        $fields = [
            'post_fields' => $this->get_core_fields(),
            'taxonomies' => $this->get_taxonomy_fields($post_type),
            'meta' => $this->get_registered_meta($post_type),
        ];
        
        // Add ACF fields if available
        if (function_exists('acf_get_field_groups')) {
            $fields['acf'] = $this->get_acf_fields($post_type);
        }
        
        return $fields;
    }
    
    /**
     * Get core post fields with author options populated
     */
    private function get_core_fields() {
        $fields = $this->core_fields;
        
        // Populate author options
        $users = get_users(['fields' => ['ID', 'display_name']]);
        foreach ($users as $user) {
            $fields['post_author']['options'][$user->ID] = $user->display_name;
        }
        
        return $fields;
    }
    
    /**
     * Get taxonomy fields for a post type
     */
    private function get_taxonomy_fields($post_type) {
        $fields = [];
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy->name,
                'hide_empty' => false,
            ]);
            
            $options = [];
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $options[$term->term_id] = $term->name;
                }
            }
            
            $fields['tax_' . $taxonomy->name] = [
                'label' => $taxonomy->label,
                'type' => 'select',
                'editable' => true,
                'options' => $options,
                'taxonomy' => $taxonomy->name,
            ];
        }
        
        return $fields;
    }
    
    /**
     * Get registered meta keys for a post type
     */
    private function get_registered_meta($post_type) {
        global $wpdb;
        
        $fields = [];
        
        // WordPress internal meta keys to exclude
        $wp_internal_keys = [
            '_edit_lock',
            '_edit_last',
            '_wp_page_template',
            '_wp_trash_meta_status',
            '_wp_trash_meta_time',
            '_wp_desired_post_slug',
            '_encloseme',
            '_pingme',
            '_trackbackme',
            '_wp_old_slug',
            '_wp_old_date',
        ];
        
        // Get ALL meta keys from database (including underscore-prefixed)
        $meta_keys = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_key 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = %s
            ORDER BY pm.meta_key",
            $post_type
        ));
        
        // Also get registered meta
        $registered = get_registered_meta_keys('post', $post_type);
        
        // Create a lookup of non-underscore keys
        $non_underscore_keys = [];
        foreach ($meta_keys as $key) {
            if (strpos($key, '_') !== 0) {
                $non_underscore_keys[$key] = true;
            }
        }
        
        foreach ($meta_keys as $key) {
            // Skip WordPress internal keys
            if (in_array($key, $wp_internal_keys)) {
                continue;
            }
            
            // Skip ACF internal field reference keys (start with field_)
            if (strpos($key, 'field_') === 0) {
                continue;
            }
            
            // Skip ACF reference keys (underscore-prefixed keys that have matching non-underscore version)
            // These contain field key references like "field_abc123" not actual data
            if (strpos($key, '_') === 0) {
                $without_underscore = substr($key, 1);
                if (isset($non_underscore_keys[$without_underscore])) {
                    continue;
                }
            }
            
            $fields[$key] = [
                'label' => $this->format_meta_key_label($key),
                'type' => 'text',
                'editable' => (strpos($key, '_') !== 0), // Underscore-prefixed are read-only
            ];
            
            // Check if it's registered with a specific type
            if (isset($registered[$key])) {
                $fields[$key]['type'] = $this->map_meta_type($registered[$key]['type'] ?? 'string');
            }
        }
        
        return $fields;
    }
    
    /**
     * Get ACF fields for a post type
     */
    private function get_acf_fields($post_type) {
        $fields = [];
        
        // Get field groups for this post type
        $groups = acf_get_field_groups(['post_type' => $post_type]);
        
        foreach ($groups as $group) {
            $group_fields = acf_get_fields($group['key']);
            
            if (!$group_fields) {
                continue;
            }
            
            foreach ($group_fields as $field) {
                $field_config = [
                    'label' => $field['label'],
                    'type' => $this->map_acf_type($field['type']),
                    'editable' => true,
                    'acf_key' => $field['key'],
                    'acf_type' => $field['type'],
                ];
                
                // Handle select/checkbox/radio options
                if (isset($field['choices']) && !empty($field['choices'])) {
                    $field_config['options'] = $field['choices'];
                }
                
                // Handle true/false default
                if ($field['type'] === 'true_false') {
                    $field_config['type'] = 'boolean';
                }
                
                $fields[$field['name']] = $field_config;
            }
        }
        
        return $fields;
    }
    
    /**
     * Map ACF field type to our internal type
     */
    private function map_acf_type($acf_type) {
        $map = [
            'text' => 'text',
            'textarea' => 'textarea',
            'number' => 'number',
            'range' => 'number',
            'email' => 'text',
            'url' => 'text',
            'password' => 'text',
            'date_picker' => 'date',
            'date_time_picker' => 'datetime',
            'time_picker' => 'time',
            'true_false' => 'boolean',
            'select' => 'select',
            'checkbox' => 'select',
            'radio' => 'select',
            'button_group' => 'select',
            'wysiwyg' => 'wysiwyg',
        ];
        
        return $map[$acf_type] ?? 'text';
    }
    
    /**
     * Map WordPress meta type to our internal type
     */
    private function map_meta_type($wp_type) {
        $map = [
            'string' => 'text',
            'integer' => 'number',
            'number' => 'number',
            'boolean' => 'boolean',
            'array' => 'text',
            'object' => 'text',
        ];
        
        return $map[$wp_type] ?? 'text';
    }
    
    /**
     * Format meta key as readable label
     */
    private function format_meta_key_label($key) {
        // Remove common prefixes
        $key = preg_replace('/^(wpcf-|_|wp_)/', '', $key);
        
        // Replace underscores and dashes with spaces
        $key = str_replace(['_', '-'], ' ', $key);
        
        // Title case
        return ucwords($key);
    }
    
    /**
     * Get field info by key
     */
    public function get_field_info($post_type, $field_key, $source = 'auto') {
        $all_fields = $this->get_fields_for_post_type($post_type);
        
        if ($source === 'auto') {
            // Search all sources
            foreach (['post_fields', 'acf', 'meta', 'taxonomies'] as $src) {
                if (isset($all_fields[$src][$field_key])) {
                    return array_merge($all_fields[$src][$field_key], ['source' => $src]);
                }
            }
        } else {
            if (isset($all_fields[$source][$field_key])) {
                return array_merge($all_fields[$source][$field_key], ['source' => $source]);
            }
        }
        
        return null;
    }
    
    /**
     * Get flat list of all fields
     */
    public function get_flat_field_list($post_type) {
        $all_fields = $this->get_fields_for_post_type($post_type);
        $flat = [];
        
        foreach ($all_fields as $source => $fields) {
            foreach ($fields as $key => $field) {
                $flat[$key] = array_merge($field, [
                    'source' => $source,
                    'field_key' => $key,
                ]);
            }
        }
        
        return $flat;
    }
}
