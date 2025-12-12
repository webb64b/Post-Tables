<?php
/**
 * Real-time Sync Handler - Uses WordPress Heartbeat API for live updates
 */

if (!defined('ABSPATH')) {
    exit;
}

class PDS_Post_Tables_Realtime_Sync {

    private $data_handler;

    /**
     * How long to keep change records (in seconds)
     */
    const CHANGE_RETENTION = 300; // 5 minutes

    public function __construct($data_handler) {
        $this->data_handler = $data_handler;

        // Heartbeat hooks for logged-in users (frontend)
        add_filter('heartbeat_received', [$this, 'heartbeat_received'], 10, 2);

        // Heartbeat hooks for admin area
        add_filter('heartbeat_received', [$this, 'heartbeat_received'], 10, 2);

        // Track active users viewing tables
        add_filter('heartbeat_received', [$this, 'track_active_users'], 10, 2);
    }

    /**
     * Handle incoming heartbeat - check if tables have changes
     */
    public function heartbeat_received($response, $data) {
        // Client sends: { pds_table_sync: { table_id: 123, last_sync: timestamp } }
        if (empty($data['pds_table_sync'])) {
            return $response;
        }

        $sync_data = $data['pds_table_sync'];
        $table_id = absint($sync_data['table_id'] ?? 0);
        $last_sync = absint($sync_data['last_sync'] ?? 0);
        $current_user_id = get_current_user_id();

        if (!$table_id) {
            return $response;
        }

        // Get changes since client's last sync
        $changes = $this->get_changes_since($table_id, $last_sync, $current_user_id);

        // Get active users viewing this table
        $active_users = $this->get_active_users($table_id, $current_user_id);

        $response['pds_table_sync'] = [
            'table_id' => $table_id,
            'timestamp' => time(),
            'changes' => $changes,
            'active_users' => $active_users,
        ];

        return $response;
    }

    /**
     * Track users actively viewing a table
     */
    public function track_active_users($response, $data) {
        if (empty($data['pds_table_sync']['table_id'])) {
            return $response;
        }

        $table_id = absint($data['pds_table_sync']['table_id']);
        $user_id = get_current_user_id();

        if (!$user_id) {
            return $response;
        }

        $user = get_userdata($user_id);
        $active_users = get_transient('pds_table_active_users_' . $table_id) ?: [];

        // Add/update current user
        $active_users[$user_id] = [
            'id' => $user_id,
            'name' => $user->display_name,
            'avatar' => get_avatar_url($user_id, ['size' => 32]),
            'last_seen' => time(),
        ];

        // Remove stale users (not seen in 60 seconds)
        $active_users = array_filter($active_users, function($u) {
            return (time() - $u['last_seen']) < 60;
        });

        // Store for 2 minutes
        set_transient('pds_table_active_users_' . $table_id, $active_users, 120);

        return $response;
    }

    /**
     * Get changes for a table since a timestamp
     */
    private function get_changes_since($table_id, $since_timestamp, $exclude_user_id = 0) {
        $changes = get_post_meta($table_id, '_pds_table_changes', true) ?: [];

        if (empty($changes)) {
            return null;
        }

        // Filter to changes after client's timestamp, excluding current user's own changes
        $recent = array_filter($changes, function($change) use ($since_timestamp, $exclude_user_id) {
            $is_newer = $change['timestamp'] > $since_timestamp;
            $is_other_user = $change['user_id'] != $exclude_user_id;
            return $is_newer && $is_other_user;
        });

        if (empty($recent)) {
            return null;
        }

        // Get unique post IDs that changed
        $changed_post_ids = array_unique(array_column($recent, 'post_id'));

        // Fetch current data for those rows
        $config = PDS_Post_Tables_Post_Type::get_table_config($table_id);
        $rows = [];

        foreach ($changed_post_ids as $post_id) {
            $post = get_post($post_id);
            if ($post) {
                $row_data = ['ID' => $post->ID];

                // Get all column values
                foreach ($config['columns'] as $column) {
                    $row_data[$column['field_key']] = $this->data_handler->get_field_value(
                        $post,
                        $column['field_key'],
                        $column['source']
                    );
                }

                $rows[] = $row_data;
            }
        }

        // Get info about the changes (which fields, who edited)
        $change_details = [];
        foreach ($recent as $change) {
            $change_details[] = [
                'post_id' => $change['post_id'],
                'field_key' => $change['field_key'],
                'user_id' => $change['user_id'],
                'user_name' => $change['user_name'],
                'timestamp' => $change['timestamp'],
            ];
        }

        return [
            'rows' => $rows,
            'details' => $change_details,
        ];
    }

    /**
     * Get active users viewing a table
     */
    private function get_active_users($table_id, $exclude_user_id = 0) {
        $active_users = get_transient('pds_table_active_users_' . $table_id) ?: [];

        // Remove current user from list (they know they're viewing it)
        unset($active_users[$exclude_user_id]);

        // Return as indexed array
        return array_values($active_users);
    }

    /**
     * Log a change to a table (called when data is saved)
     */
    public static function log_change($table_id, $post_id, $field_key, $old_value, $new_value) {
        $user_id = get_current_user_id();
        $user = get_userdata($user_id);

        $changes = get_post_meta($table_id, '_pds_table_changes', true) ?: [];

        // Add new change
        $changes[] = [
            'post_id' => $post_id,
            'field_key' => $field_key,
            'old_value' => $old_value,
            'new_value' => $new_value,
            'user_id' => $user_id,
            'user_name' => $user ? $user->display_name : 'Unknown',
            'timestamp' => time(),
        ];

        // Clean up old changes (older than retention period)
        $cutoff = time() - self::CHANGE_RETENTION;
        $changes = array_filter($changes, function($change) use ($cutoff) {
            return $change['timestamp'] > $cutoff;
        });

        // Re-index array
        $changes = array_values($changes);

        // Save
        update_post_meta($table_id, '_pds_table_changes', $changes);

        // Also update last modified timestamp
        update_post_meta($table_id, '_pds_table_last_modified', time());
    }

    /**
     * Clear all changes for a table
     */
    public static function clear_changes($table_id) {
        delete_post_meta($table_id, '_pds_table_changes');
    }
}
