<?php
/**
 * Automation REST API - REST endpoints for automation management
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Automation_REST {

    /**
     * Namespace for REST routes
     */
    const NAMESPACE = 'pds-tables/v1';

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
    }

    /**
     * Set the engine instance
     */
    public function set_engine($engine) {
        $this->engine = $engine;
    }

    /**
     * Register REST routes
     */
    public function register_routes() {
        // Get fields for a post type
        register_rest_route(self::NAMESPACE, '/automation/fields/(?P<post_type>[a-z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_fields'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'post_type' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Get fields for a table
        register_rest_route(self::NAMESPACE, '/automation/table-fields/(?P<table_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_table_fields'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'table_id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
            ],
        ]);

        // Test automation
        register_rest_route(self::NAMESPACE, '/automation/(?P<id>\d+)/test', [
            'methods' => 'POST',
            'callback' => [$this, 'test_automation'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
            ],
        ]);

        // Execute automation manually
        register_rest_route(self::NAMESPACE, '/automation/(?P<id>\d+)/execute', [
            'methods' => 'POST',
            'callback' => [$this, 'execute_automation'],
            'permission_callback' => [$this, 'can_manage'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
            ],
        ]);

        // Get automation history
        register_rest_route(self::NAMESPACE, '/automation/(?P<id>\d+)/history', [
            'methods' => 'GET',
            'callback' => [$this, 'get_history'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
                'page' => [
                    'default' => 1,
                    'type' => 'integer',
                ],
                'per_page' => [
                    'default' => 25,
                    'type' => 'integer',
                ],
            ],
        ]);

        // Get automation statistics
        register_rest_route(self::NAMESPACE, '/automation/(?P<id>\d+)/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
            ],
        ]);

        // Preview placeholder parsing
        register_rest_route(self::NAMESPACE, '/automation/preview-placeholders', [
            'methods' => 'POST',
            'callback' => [$this, 'preview_placeholders'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'text' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
            ],
        ]);

        // Get matching posts for trigger
        register_rest_route(self::NAMESPACE, '/automation/(?P<id>\d+)/matching-posts', [
            'methods' => 'GET',
            'callback' => [$this, 'get_matching_posts'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
                'limit' => [
                    'default' => 10,
                    'type' => 'integer',
                ],
            ],
        ]);

        // Get posts for testing
        register_rest_route(self::NAMESPACE, '/automation/test-posts/(?P<post_type>[a-z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_test_posts'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'post_type' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'search' => [
                    'default' => '',
                    'type' => 'string',
                ],
            ],
        ]);

        // Clear execution tracking
        register_rest_route(self::NAMESPACE, '/automation/(?P<id>\d+)/clear-tracking', [
            'methods' => 'POST',
            'callback' => [$this, 'clear_tracking'],
            'permission_callback' => [$this, 'can_manage'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
            ],
        ]);
    }

    /**
     * Permission check: can edit posts
     */
    public function can_edit() {
        return current_user_can('edit_posts');
    }

    /**
     * Permission check: can manage options
     */
    public function can_manage() {
        return current_user_can('manage_options');
    }

    /**
     * Get fields for a post type
     */
    public function get_fields($request) {
        $post_type = $request->get_param('post_type');

        if (!post_type_exists($post_type)) {
            return new WP_Error('invalid_post_type', __('Invalid post type', 'pds-post-tables'), ['status' => 400]);
        }

        $fields = $this->field_scanner->get_available_fields($post_type);

        // Organize fields by source
        $organized = [
            'post_fields' => [],
            'meta' => [],
            'acf' => [],
            'taxonomies' => [],
        ];

        foreach ($fields as $field) {
            $source = $field['source'] ?? 'meta';
            if (isset($organized[$source])) {
                $organized[$source][] = $field;
            } else {
                $organized['meta'][] = $field;
            }
        }

        return rest_ensure_response([
            'fields' => $fields,
            'organized' => $organized,
        ]);
    }

    /**
     * Get fields for a table
     */
    public function get_table_fields($request) {
        $table_id = $request->get_param('table_id');

        $table = get_post($table_id);
        if (!$table || $table->post_type !== 'pds_post_table') {
            return new WP_Error('invalid_table', __('Invalid table', 'pds-post-tables'), ['status' => 400]);
        }

        $config = PDS_Post_Tables_Post_Type::get_table_config($table_id);
        $post_type = $config['source_post_type'] ?? '';

        if (empty($post_type)) {
            return new WP_Error('no_post_type', __('Table has no source post type', 'pds-post-tables'), ['status' => 400]);
        }

        $fields = $this->field_scanner->get_available_fields($post_type);

        // Also include columns from the table config
        $columns = $config['columns'] ?? [];

        return rest_ensure_response([
            'post_type' => $post_type,
            'fields' => $fields,
            'columns' => $columns,
        ]);
    }

    /**
     * Test an automation
     */
    public function test_automation($request) {
        $automation_id = $request->get_param('id');
        $post_id = $request->get_param('post_id');

        if (!$this->engine) {
            return new WP_Error('engine_not_ready', __('Automation engine not initialized', 'pds-post-tables'), ['status' => 500]);
        }

        $automation = PDS_Post_Tables_Automation_Post_Type::get_automation_config($automation_id);
        $automation['id'] = $automation_id;
        $automation['name'] = get_the_title($automation_id);

        $result = $this->engine->test_automation($automation, $post_id);

        return rest_ensure_response($result);
    }

    /**
     * Execute an automation manually
     */
    public function execute_automation($request) {
        $automation_id = $request->get_param('id');
        $post_id = $request->get_param('post_id');

        if (!$this->engine) {
            return new WP_Error('engine_not_ready', __('Automation engine not initialized', 'pds-post-tables'), ['status' => 500]);
        }

        $automation = PDS_Post_Tables_Automation_Post_Type::get_automation_config($automation_id);
        $automation['id'] = $automation_id;
        $automation['name'] = get_the_title($automation_id);

        $context = [
            'trigger_source' => 'manual',
            'manual_trigger' => true,
        ];

        $result = $this->engine->execute($automation, $post_id, $context);

        return rest_ensure_response($result);
    }

    /**
     * Get automation history
     */
    public function get_history($request) {
        $automation_id = $request->get_param('id');
        $page = $request->get_param('page');
        $per_page = $request->get_param('per_page');

        if (!$this->engine) {
            return new WP_Error('engine_not_ready', __('Automation engine not initialized', 'pds-post-tables'), ['status' => 500]);
        }

        $history = $this->engine->get_history()->get_history($automation_id, [
            'limit' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ]);

        return rest_ensure_response($history);
    }

    /**
     * Get automation statistics
     */
    public function get_stats($request) {
        $automation_id = $request->get_param('id');

        if (!$this->engine) {
            return new WP_Error('engine_not_ready', __('Automation engine not initialized', 'pds-post-tables'), ['status' => 500]);
        }

        $stats = $this->engine->get_history()->get_stats($automation_id);

        return rest_ensure_response($stats);
    }

    /**
     * Preview placeholder parsing
     */
    public function preview_placeholders($request) {
        $text = $request->get_param('text');
        $post_id = $request->get_param('post_id');

        if (!$this->engine) {
            return new WP_Error('engine_not_ready', __('Automation engine not initialized', 'pds-post-tables'), ['status' => 500]);
        }

        $placeholders = $this->engine->get_placeholders();
        $parsed = $placeholders->parse($text, $post_id, []);

        return rest_ensure_response([
            'original' => $text,
            'parsed' => $parsed,
        ]);
    }

    /**
     * Get posts matching a trigger
     */
    public function get_matching_posts($request) {
        $automation_id = $request->get_param('id');
        $limit = $request->get_param('limit');

        if (!$this->engine) {
            return new WP_Error('engine_not_ready', __('Automation engine not initialized', 'pds-post-tables'), ['status' => 500]);
        }

        $automation = PDS_Post_Tables_Automation_Post_Type::get_automation_config($automation_id);
        $automation['id'] = $automation_id;

        $triggers = $this->engine->get_triggers();
        $post_ids = $triggers->get_posts_for_date_trigger($automation);

        // Limit results
        $post_ids = array_slice($post_ids, 0, $limit);

        // Get post details
        $posts = [];
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if ($post) {
                $posts[] = [
                    'ID' => $post->ID,
                    'post_title' => $post->post_title,
                    'post_date' => $post->post_date,
                    'edit_link' => get_edit_post_link($post->ID, 'raw'),
                ];
            }
        }

        return rest_ensure_response([
            'total' => count($post_ids),
            'posts' => $posts,
        ]);
    }

    /**
     * Get posts for testing
     */
    public function get_test_posts($request) {
        $post_type = $request->get_param('post_type');
        $search = $request->get_param('search');

        if (!post_type_exists($post_type)) {
            return new WP_Error('invalid_post_type', __('Invalid post type', 'pds-post-tables'), ['status' => 400]);
        }

        $args = [
            'post_type' => $post_type,
            'posts_per_page' => 20,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        if (!empty($search)) {
            $args['s'] = sanitize_text_field($search);
        }

        $query = new WP_Query($args);
        $posts = [];

        foreach ($query->posts as $post) {
            $posts[] = [
                'ID' => $post->ID,
                'post_title' => $post->post_title,
                'post_date' => $post->post_date,
                'post_status' => $post->post_status,
            ];
        }

        return rest_ensure_response([
            'posts' => $posts,
            'total' => $query->found_posts,
        ]);
    }

    /**
     * Clear execution tracking for an automation
     */
    public function clear_tracking($request) {
        $automation_id = $request->get_param('id');

        if (!$this->engine) {
            return new WP_Error('engine_not_ready', __('Automation engine not initialized', 'pds-post-tables'), ['status' => 500]);
        }

        $this->engine->get_history()->clear_execution_tracking($automation_id);

        return rest_ensure_response([
            'success' => true,
            'message' => __('Execution tracking cleared', 'pds-post-tables'),
        ]);
    }
}
