<?php
/**
 * REST API Controller
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_REST_Controller {
    
    private $data_handler;
    private $field_scanner;
    private $namespace = 'pds-tables/v1';
    
    public function __construct($data_handler, $field_scanner) {
        $this->data_handler = $data_handler;
        $this->field_scanner = $field_scanner;
    }
    
    public function register_routes() {
        // Get table data
        register_rest_route($this->namespace, '/tables/(?P<id>\d+)/data', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_table_data'],
                'permission_callback' => [$this, 'check_read_permission'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        },
                    ],
                    'page' => [
                        'default' => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'default' => 25,
                        'sanitize_callback' => 'absint',
                    ],
                    'search' => [
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'sort_field' => [
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'sort_dir' => [
                        'default' => 'asc',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'filters' => [
                        'default' => [],
                    ],
                ],
            ],
        ]);
        
        // Update cell value
        register_rest_route($this->namespace, '/tables/(?P<id>\d+)/data', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'update_cell_value'],
                'permission_callback' => [$this, 'check_edit_permission'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        },
                    ],
                    'post_id' => [
                        'required' => true,
                        'sanitize_callback' => 'absint',
                    ],
                    'field_key' => [
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'value' => [
                        'required' => false,
                    ],
                    'source' => [
                        'default' => 'auto',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);
        
        // Get table config
        register_rest_route($this->namespace, '/tables/(?P<id>\d+)/config', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_table_config'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);
        
        // Get fields for post type
        register_rest_route($this->namespace, '/fields/(?P<post_type>[a-z0-9_-]+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_post_type_fields'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);
        
        // Save formatting
        register_rest_route($this->namespace, '/tables/(?P<id>\d+)/format', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_format'],
                'permission_callback' => [$this, 'check_edit_permission'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        },
                    ],
                    'type' => [
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'post_id' => [
                        'required' => false,
                        'sanitize_callback' => 'absint',
                    ],
                    'field_key' => [
                        'required' => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'style' => [
                        'required' => false,
                    ],
                    'clear' => [
                        'required' => false,
                        'default' => false,
                    ],
                ],
            ],
        ]);
    }
    
    /**
     * Check read permission
     */
    public function check_read_permission($request) {
        return current_user_can('read');
    }
    
    /**
     * Check edit permission
     */
    public function check_edit_permission($request) {
        $post_id = $request->get_param('post_id');
        
        if ($post_id) {
            return current_user_can('edit_post', $post_id);
        }
        
        return current_user_can('edit_posts');
    }
    
    /**
     * Check admin permission
     */
    public function check_admin_permission($request) {
        return current_user_can('edit_posts');
    }
    
    /**
     * Get table data endpoint
     */
    public function get_table_data($request) {
        $table_id = $request->get_param('id');
        
        // Verify table exists
        $table = get_post($table_id);
        if (!$table || $table->post_type !== 'pds_post_table') {
            return new WP_Error('not_found', 'Table not found', ['status' => 404]);
        }
        
        $args = [
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
            'search' => $request->get_param('search'),
            'sort_field' => $request->get_param('sort_field'),
            'sort_dir' => $request->get_param('sort_dir'),
            'filters' => $request->get_param('filters'),
        ];
        
        $data = $this->data_handler->get_table_data($table_id, $args);
        
        return rest_ensure_response($data);
    }
    
    /**
     * Update cell value endpoint
     */
    public function update_cell_value($request) {
        $table_id = $request->get_param('id');
        $post_id = $request->get_param('post_id');
        $field_key = $request->get_param('field_key');
        $value = $request->get_param('value');
        $source = $request->get_param('source');
        
        // Verify table exists
        $table = get_post($table_id);
        if (!$table || $table->post_type !== 'pds_post_table') {
            return new WP_Error('not_found', 'Table not found', ['status' => 404]);
        }
        
        // Verify the field is editable in this table config
        $config = PDS_Post_Tables_Post_Type::get_table_config($table_id);
        $column = array_filter($config['columns'], function($col) use ($field_key) {
            return $col['field_key'] === $field_key;
        });
        
        if (empty($column)) {
            return new WP_Error('invalid_field', 'Field not found in table configuration', ['status' => 400]);
        }
        
        $column = array_values($column)[0];
        
        if (!$column['editable']) {
            return new WP_Error('not_editable', 'This field is not editable', ['status' => 403]);
        }
        
        // Use source from column config if available
        $source = $column['source'] ?? $source;

        // Get old value for change logging
        $old_value = $this->data_handler->get_field_value($post_id, $field_key, $source);

        // Update the value
        $result = $this->data_handler->update_field_value($post_id, $field_key, $value, $source);

        if (is_wp_error($result)) {
            return $result;
        }

        // Return the new value
        $new_value = $this->data_handler->get_field_value($post_id, $field_key, $source);

        // Log change for real-time sync
        if (class_exists('PDS_Post_Tables_Realtime_Sync')) {
            PDS_Post_Tables_Realtime_Sync::log_change($table_id, $post_id, $field_key, $old_value, $new_value);
        }

        return rest_ensure_response([
            'success' => true,
            'post_id' => $post_id,
            'field_key' => $field_key,
            'value' => $new_value,
        ]);
    }
    
    /**
     * Get table config endpoint
     */
    public function get_table_config($request) {
        $table_id = $request->get_param('id');
        
        // Verify table exists
        $table = get_post($table_id);
        if (!$table || $table->post_type !== 'pds_post_table') {
            return new WP_Error('not_found', 'Table not found', ['status' => 404]);
        }
        
        $config = PDS_Post_Tables_Post_Type::get_table_config($table_id);
        
        // Add field options for dropdowns
        foreach ($config['columns'] as &$column) {
            if (empty($column['options'])) {
                $field_info = $this->field_scanner->get_field_info(
                    $config['source_post_type'],
                    $column['field_key'],
                    $column['source']
                );
                
                if ($field_info && !empty($field_info['options'])) {
                    $column['options'] = $field_info['options'];
                }
            }
        }
        
        return rest_ensure_response($config);
    }
    
    /**
     * Get fields for post type endpoint
     */
    public function get_post_type_fields($request) {
        $post_type = $request->get_param('post_type');
        
        // Verify post type exists
        if (!post_type_exists($post_type)) {
            return new WP_Error('invalid_post_type', 'Post type not found', ['status' => 404]);
        }
        
        $fields = $this->field_scanner->get_fields_for_post_type($post_type);
        
        return rest_ensure_response($fields);
    }
    
    /**
     * Save formatting endpoint
     */
    public function save_format($request) {
        $table_id = $request->get_param('id');
        $type = $request->get_param('type'); // 'cell', 'row', 'column'
        $post_id = $request->get_param('post_id');
        $field_key = $request->get_param('field_key');
        $style = $request->get_param('style');
        $clear = $request->get_param('clear');
        
        // Verify table exists
        $table = get_post($table_id);
        if (!$table || $table->post_type !== 'pds_post_table') {
            return new WP_Error('not_found', 'Table not found', ['status' => 404]);
        }
        
        // Sanitize style
        $sanitized_style = [];
        if ($style && is_array($style)) {
            if (!empty($style['background'])) {
                $sanitized_style['background'] = sanitize_hex_color($style['background']);
            }
            if (!empty($style['color'])) {
                $sanitized_style['color'] = sanitize_hex_color($style['color']);
            }
            if (!empty($style['font_weight'])) {
                $sanitized_style['font_weight'] = $style['font_weight'] === 'bold' ? 'bold' : 'normal';
            }
        }
        
        switch ($type) {
            case 'cell':
                if (!$post_id || !$field_key) {
                    return new WP_Error('missing_params', 'post_id and field_key required for cell formatting', ['status' => 400]);
                }
                return $this->save_cell_format($post_id, $field_key, $sanitized_style, $clear);
                
            case 'row':
                if (!$post_id) {
                    return new WP_Error('missing_params', 'post_id required for row formatting', ['status' => 400]);
                }
                return $this->save_row_format($post_id, $sanitized_style, $clear);
                
            case 'column':
                if (!$field_key) {
                    return new WP_Error('missing_params', 'field_key required for column formatting', ['status' => 400]);
                }
                return $this->save_column_format($table_id, $field_key, $sanitized_style, $clear);
                
            default:
                return new WP_Error('invalid_type', 'Invalid format type', ['status' => 400]);
        }
    }
    
    /**
     * Save cell format
     */
    private function save_cell_format($post_id, $field_key, $style, $clear) {
        $formats = json_decode(get_post_meta($post_id, '_pds_table_cell_formats', true), true) ?: [];
        
        if ($clear) {
            unset($formats[$field_key]);
        } else {
            $formats[$field_key] = $style;
        }
        
        if (empty($formats)) {
            delete_post_meta($post_id, '_pds_table_cell_formats');
        } else {
            update_post_meta($post_id, '_pds_table_cell_formats', wp_json_encode($formats));
        }
        
        return rest_ensure_response([
            'success' => true,
            'type' => 'cell',
            'post_id' => $post_id,
            'field_key' => $field_key,
            'style' => $style,
        ]);
    }
    
    /**
     * Save row format
     */
    private function save_row_format($post_id, $style, $clear) {
        if ($clear) {
            delete_post_meta($post_id, '_pds_table_row_format');
        } else {
            update_post_meta($post_id, '_pds_table_row_format', wp_json_encode($style));
        }
        
        return rest_ensure_response([
            'success' => true,
            'type' => 'row',
            'post_id' => $post_id,
            'style' => $style,
        ]);
    }
    
    /**
     * Save column format
     */
    private function save_column_format($table_id, $field_key, $style, $clear) {
        $formats = json_decode(get_post_meta($table_id, '_pds_table_custom_column_formats', true), true) ?: [];
        
        if ($clear) {
            unset($formats[$field_key]);
        } else {
            $formats[$field_key] = $style;
        }
        
        if (empty($formats)) {
            delete_post_meta($table_id, '_pds_table_custom_column_formats');
        } else {
            update_post_meta($table_id, '_pds_table_custom_column_formats', wp_json_encode($formats));
        }
        
        return rest_ensure_response([
            'success' => true,
            'type' => 'column',
            'field_key' => $field_key,
            'style' => $style,
        ]);
    }
}
