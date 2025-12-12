<?php
/**
 * Data Handler - Fetches and saves post data
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Data_Handler {
    
    private $field_scanner;
    
    public function __construct($field_scanner) {
        $this->field_scanner = $field_scanner;
    }
    
    /**
     * Get table data
     */
    public function get_table_data($table_id, $args = []) {
        $config = PDS_Post_Tables_Post_Type::get_table_config($table_id);
        
        if (empty($config['source_post_type'])) {
            return ['rows' => [], 'total' => 0];
        }
        
        // Determine posts per page
        $pagination_enabled = $config['settings']['pagination'] ?? true;
        if (!$pagination_enabled || ($args['per_page'] ?? 0) >= 1000) {
            // Load all posts when pagination is off or requesting large amount
            $posts_per_page = -1;
        } else {
            $posts_per_page = $args['per_page'] ?? $config['settings']['page_size'] ?? 25;
        }
        
        // Build query args
        $query_args = array_merge([
            'post_type' => $config['source_post_type'],
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $posts_per_page,
            'paged' => $args['page'] ?? 1,
            'orderby' => $args['orderby'] ?? 'date',
            'order' => $args['order'] ?? 'DESC',
        ], $config['query_args'] ?? []);
        
        // Handle search
        if (!empty($args['search'])) {
            $query_args['s'] = sanitize_text_field($args['search']);
        }
        
        // Handle column sorting
        if (!empty($args['sort_field'])) {
            $field_info = $this->field_scanner->get_field_info(
                $config['source_post_type'], 
                $args['sort_field']
            );
            
            if ($field_info) {
                if ($field_info['source'] === 'post_fields') {
                    $query_args['orderby'] = $args['sort_field'];
                } else {
                    $query_args['meta_key'] = $args['sort_field'];
                    $query_args['orderby'] = 'meta_value';
                }
                $query_args['order'] = strtoupper($args['sort_dir'] ?? 'ASC');
            }
        }
        
        // Handle filters
        if (!empty($args['filters']) && is_array($args['filters'])) {
            $meta_query = [];
            $tax_query = [];
            
            foreach ($args['filters'] as $field => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                
                $field_info = $this->field_scanner->get_field_info(
                    $config['source_post_type'],
                    $field
                );
                
                if (!$field_info) {
                    continue;
                }
                
                if ($field_info['source'] === 'taxonomies') {
                    $tax_query[] = [
                        'taxonomy' => $field_info['taxonomy'],
                        'field' => 'term_id',
                        'terms' => $value,
                    ];
                } elseif ($field_info['source'] !== 'post_fields') {
                    $meta_query[] = [
                        'key' => $field,
                        'value' => $value,
                        'compare' => is_array($value) ? 'IN' : '=',
                    ];
                } else {
                    // Handle core field filters
                    if ($field === 'post_status') {
                        $query_args['post_status'] = $value;
                    } elseif ($field === 'post_author') {
                        $query_args['author'] = $value;
                    }
                }
            }
            
            if (!empty($meta_query)) {
                $query_args['meta_query'] = $meta_query;
            }
            
            if (!empty($tax_query)) {
                $query_args['tax_query'] = $tax_query;
            }
        }
        
        // Apply pre-configured query filters
        $query_args = $this->apply_query_filters($query_args, $config);
        
        $query = new WP_Query($query_args);
        $rows = [];
        
        foreach ($query->posts as $post) {
            $rows[] = $this->build_row_data($post, $config['columns'], $config['source_post_type']);
        }
        
        return [
            'rows' => $rows,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
            'page' => $query_args['paged'],
            'per_page' => $query_args['posts_per_page'],
        ];
    }
    
    /**
     * Build row data from post
     */
    private function build_row_data($post, $columns, $post_type) {
        $row = [
            'ID' => $post->ID,
            '_edit_link' => get_edit_post_link($post->ID, 'raw'),
        ];
        
        foreach ($columns as $column) {
            $field_key = $column['field_key'];
            $source = $column['source'];
            
            $row[$field_key] = $this->get_field_value($post, $field_key, $source);
        }
        
        // Include custom formatting data
        $cell_formats = get_post_meta($post->ID, '_pds_table_cell_formats', true);
        if ($cell_formats) {
            $row['_cell_formats'] = json_decode($cell_formats, true) ?: [];
        }
        
        $row_format = get_post_meta($post->ID, '_pds_table_row_format', true);
        if ($row_format) {
            $row['_row_format'] = json_decode($row_format, true) ?: [];
        }
        
        return $row;
    }
    
    /**
     * Apply pre-configured query filters from table config
     */
    private function apply_query_filters($query_args, $config) {
        $filters = $config['query_filters'] ?? [];
        $logic = $config['filter_logic'] ?? 'AND';
        
        if (empty($filters)) {
            return $query_args;
        }
        
        $meta_query = [];
        $tax_query = [];
        $post_status_filter = null;
        $author_filter = null;
        
        foreach ($filters as $filter) {
            $field = $filter['field'] ?? '';
            $source = $filter['source'] ?? '';
            $operator = $filter['operator'] ?? 'equals';
            $value = $filter['value'] ?? '';
            
            if (empty($field)) {
                continue;
            }
            
            // Handle post fields directly
            if ($source === 'post_fields') {
                switch ($field) {
                    case 'post_status':
                        $post_status_filter = $this->get_filter_value($operator, $value);
                        break;
                    case 'post_author':
                        $author_filter = $this->get_filter_value($operator, $value);
                        break;
                    case 'post_date':
                        $date_query = $this->build_date_query($operator, $value);
                        if ($date_query) {
                            $query_args['date_query'] = $date_query;
                        }
                        break;
                    case 'post_title':
                    case 'post_content':
                    case 'post_excerpt':
                        // These need to be handled with search or post__in
                        // For now, add to meta_query with a workaround
                        $meta_query[] = $this->build_meta_compare($field, $operator, $value);
                        break;
                    default:
                        // Other post fields
                        break;
                }
            }
            // Handle taxonomy fields
            elseif ($source === 'taxonomies') {
                $tax_compare = $this->build_tax_compare($field, $operator, $value);
                if ($tax_compare) {
                    $tax_query[] = $tax_compare;
                }
            }
            // Handle meta/ACF fields
            else {
                $meta_compare = $this->build_meta_compare($field, $operator, $value);
                if ($meta_compare) {
                    $meta_query[] = $meta_compare;
                }
            }
        }
        
        // Apply post status filter
        if ($post_status_filter !== null) {
            $query_args['post_status'] = $post_status_filter;
        }
        
        // Apply author filter
        if ($author_filter !== null) {
            if (is_array($author_filter)) {
                $query_args['author__in'] = $author_filter;
            } else {
                $query_args['author'] = $author_filter;
            }
        }
        
        // Merge meta queries
        if (!empty($meta_query)) {
            if (!isset($query_args['meta_query'])) {
                $query_args['meta_query'] = [];
            }
            $query_args['meta_query']['relation'] = $logic;
            $query_args['meta_query'] = array_merge($query_args['meta_query'], $meta_query);
        }
        
        // Merge tax queries
        if (!empty($tax_query)) {
            if (!isset($query_args['tax_query'])) {
                $query_args['tax_query'] = [];
            }
            $query_args['tax_query']['relation'] = $logic;
            $query_args['tax_query'] = array_merge($query_args['tax_query'], $tax_query);
        }
        
        return $query_args;
    }
    
    /**
     * Build meta compare array for WP_Query
     */
    private function build_meta_compare($field, $operator, $value) {
        $compare_map = [
            'equals' => '=',
            'not_equals' => '!=',
            'contains' => 'LIKE',
            'not_contains' => 'NOT LIKE',
            'starts_with' => 'LIKE',
            'ends_with' => 'LIKE',
            'greater_than' => '>',
            'less_than' => '<',
            'greater_equal' => '>=',
            'less_equal' => '<=',
            'is_empty' => 'NOT EXISTS',
            'is_not_empty' => 'EXISTS',
            'in' => 'IN',
            'not_in' => 'NOT IN',
        ];
        
        $compare = $compare_map[$operator] ?? '=';
        
        // Handle special operators
        if ($operator === 'is_empty') {
            return [
                'relation' => 'OR',
                [
                    'key' => $field,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => $field,
                    'value' => '',
                    'compare' => '=',
                ],
            ];
        }
        
        if ($operator === 'is_not_empty') {
            return [
                'key' => $field,
                'value' => '',
                'compare' => '!=',
            ];
        }
        
        if ($operator === 'starts_with') {
            $value = $value . '%';
        } elseif ($operator === 'ends_with') {
            $value = '%' . $value;
        } elseif ($operator === 'contains' || $operator === 'not_contains') {
            $value = '%' . $value . '%';
        }
        
        if ($operator === 'in' || $operator === 'not_in') {
            $value = array_map('trim', explode(',', $value));
        }
        
        return [
            'key' => $field,
            'value' => $value,
            'compare' => $compare,
        ];
    }
    
    /**
     * Build taxonomy compare array for WP_Query
     */
    private function build_tax_compare($taxonomy, $operator, $value) {
        if (empty($value) && !in_array($operator, ['is_empty', 'is_not_empty'])) {
            return null;
        }
        
        $terms = array_map('trim', explode(',', $value));
        
        switch ($operator) {
            case 'equals':
            case 'in':
                return [
                    'taxonomy' => $taxonomy,
                    'field' => 'slug',
                    'terms' => $terms,
                    'operator' => 'IN',
                ];
            case 'not_equals':
            case 'not_in':
                return [
                    'taxonomy' => $taxonomy,
                    'field' => 'slug',
                    'terms' => $terms,
                    'operator' => 'NOT IN',
                ];
            case 'is_empty':
                return [
                    'taxonomy' => $taxonomy,
                    'operator' => 'NOT EXISTS',
                ];
            case 'is_not_empty':
                return [
                    'taxonomy' => $taxonomy,
                    'operator' => 'EXISTS',
                ];
            default:
                return null;
        }
    }
    
    /**
     * Build date query for WP_Query
     */
    private function build_date_query($operator, $value) {
        if (empty($value)) {
            return null;
        }
        
        $date = strtotime($value);
        if (!$date) {
            return null;
        }
        
        $year = date('Y', $date);
        $month = date('m', $date);
        $day = date('d', $date);
        
        switch ($operator) {
            case 'equals':
                return [
                    [
                        'year' => $year,
                        'month' => $month,
                        'day' => $day,
                    ]
                ];
            case 'greater_than':
            case 'greater_equal':
                return [
                    [
                        'after' => [
                            'year' => $year,
                            'month' => $month,
                            'day' => $day,
                        ],
                        'inclusive' => ($operator === 'greater_equal'),
                    ]
                ];
            case 'less_than':
            case 'less_equal':
                return [
                    [
                        'before' => [
                            'year' => $year,
                            'month' => $month,
                            'day' => $day,
                        ],
                        'inclusive' => ($operator === 'less_equal'),
                    ]
                ];
            default:
                return null;
        }
    }
    
    /**
     * Get filter value based on operator
     */
    private function get_filter_value($operator, $value) {
        if ($operator === 'in' || $operator === 'not_in') {
            return array_map('trim', explode(',', $value));
        }
        return $value;
    }
    
    /**
     * Get field value from post
     */
    public function get_field_value($post, $field_key, $source = 'auto') {
        if (is_numeric($post)) {
            $post = get_post($post);
        }
        
        if (!$post) {
            return null;
        }
        
        // Core post fields
        if ($source === 'post_fields' || $source === 'auto') {
            if (isset($post->$field_key)) {
                $value = $post->$field_key;
                
                // Format dates
                if (in_array($field_key, ['post_date', 'post_modified'])) {
                    return $value ? date('Y-m-d', strtotime($value)) : null;
                }
                
                // Format author
                if ($field_key === 'post_author') {
                    return (int) $value;
                }
                
                return $value;
            }
        }
        
        // Taxonomy fields
        if ($source === 'taxonomies' || ($source === 'auto' && strpos($field_key, 'tax_') === 0)) {
            $taxonomy = str_replace('tax_', '', $field_key);
            $terms = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'ids']);
            
            if (!is_wp_error($terms) && !empty($terms)) {
                return $terms[0]; // Return first term ID for now
            }
            return null;
        }
        
        // ACF fields
        if ($source === 'acf' || $source === 'auto') {
            if (function_exists('get_field')) {
                $value = get_field($field_key, $post->ID);
                
                if ($value !== null && $value !== false) {
                    // Handle ACF date fields
                    if (is_string($value) && preg_match('/^\d{8}$/', $value)) {
                        return date('Y-m-d', strtotime($value));
                    }
                    return $value;
                }
            }
        }
        
        // Regular meta
        if ($source === 'meta' || $source === 'auto') {
            return get_post_meta($post->ID, $field_key, true);
        }
        
        return null;
    }
    
    /**
     * Update field value
     */
    public function update_field_value($post_id, $field_key, $value, $source = 'auto') {
        $post = get_post($post_id);
        
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }
        
        // Check capabilities
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('forbidden', 'You do not have permission to edit this post');
        }
        
        // Determine source if auto
        if ($source === 'auto') {
            $post_type = $post->post_type;
            $field_info = $this->field_scanner->get_field_info($post_type, $field_key);
            $source = $field_info['source'] ?? 'meta';
        }
        
        // Core post fields
        if ($source === 'post_fields') {
            return $this->update_post_field($post_id, $field_key, $value);
        }
        
        // Taxonomy fields
        if ($source === 'taxonomies' || strpos($field_key, 'tax_') === 0) {
            $taxonomy = str_replace('tax_', '', $field_key);
            $result = wp_set_object_terms($post_id, [(int) $value], $taxonomy);
            
            if (is_wp_error($result)) {
                return $result;
            }
            return true;
        }
        
        // ACF fields
        if ($source === 'acf' && function_exists('update_field')) {
            $field_info = $this->field_scanner->get_field_info($post->post_type, $field_key);
            
            // Handle boolean values
            if (isset($field_info['acf_type']) && $field_info['acf_type'] === 'true_false') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
            
            // Handle date values
            if (isset($field_info['acf_type']) && $field_info['acf_type'] === 'date_picker') {
                // ACF expects Ymd format
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    $value = str_replace('-', '', $value);
                }
            }
            
            $result = update_field($field_key, $value, $post_id);
            return $result !== false;
        }
        
        // Regular meta
        $result = update_post_meta($post_id, $field_key, $value);
        return $result !== false;
    }
    
    /**
     * Update core post field
     */
    private function update_post_field($post_id, $field_key, $value) {
        $allowed_fields = ['post_title', 'post_status', 'post_date', 'post_author', 'post_excerpt', 'menu_order'];
        
        if (!in_array($field_key, $allowed_fields)) {
            return new WP_Error('invalid_field', 'This field cannot be edited');
        }
        
        $post_data = ['ID' => $post_id];
        
        switch ($field_key) {
            case 'post_title':
                $post_data['post_title'] = sanitize_text_field($value);
                break;
                
            case 'post_status':
                $valid_statuses = ['publish', 'draft', 'pending', 'private'];
                if (!in_array($value, $valid_statuses)) {
                    return new WP_Error('invalid_status', 'Invalid post status');
                }
                $post_data['post_status'] = $value;
                break;
                
            case 'post_date':
                $post_data['post_date'] = date('Y-m-d H:i:s', strtotime($value));
                break;
                
            case 'post_author':
                $post_data['post_author'] = (int) $value;
                break;
                
            case 'post_excerpt':
                $post_data['post_excerpt'] = sanitize_textarea_field($value);
                break;
                
            case 'menu_order':
                $post_data['menu_order'] = (int) $value;
                break;
        }
        
        $result = wp_update_post($post_data, true);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return true;
    }
}
