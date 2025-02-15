<?php

/**
 * Plugin Name: WooCommerce to ActiveCampaign List Sync
 * Plugin URI: 
 * Description: Automatically adds customers to an ActiveCampaign list after WooCommerce purchase
 * Version: 1.0.18
 * Author: Micheal Colhoun
 * Author URI: https://github.com/colhountech/wordpress/
 * Text Domain: woo-to-ac
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */



// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class WooToAC_Plugin
{
    private static $instance = null;
    private $settings = [];
    private $logs = [];

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Order status change hook
        add_action('woocommerce_order_status_changed', [$this, 'handle_order_status_change'], 10, 4);

        // Register the scheduled event handler - THIS WAS MISSING
        add_action('woo_to_ac_process_order', [$this, 'process_order']);

        // AJAX handlers
        add_action('wp_ajax_test_ac_connection', [$this, 'test_connection']);
        add_action('wp_ajax_get_ac_lists', [$this, 'get_lists']);
    }

    public function add_admin_menu()
    {
        add_menu_page(
            'WooCommerce to AC',
            'Woo to AC',
            'manage_options',
            'woo-to-ac',
            [$this, 'render_settings_page'],
            'dashicons-update'
        );
    }

    public function register_settings()
    {
        register_setting('woo-to-ac-settings', 'woo_to_ac_settings');

        add_settings_section(
            'woo_to_ac_main',
            'ActiveCampaign Connection Settings',
            null,
            'woo-to-ac'
        );

        add_settings_field(
            'ac_api_url',
            'API URL',
            [$this, 'render_api_url_field'],
            'woo-to-ac',
            'woo_to_ac_main'
        );

        add_settings_field(
            'ac_api_key',
            'API Key',
            [$this, 'render_api_key_field'],
            'woo-to-ac',
            'woo_to_ac_main'
        );

        add_settings_field(
            'ac_list_id',
            'List',
            [$this, 'render_list_field'],
            'woo-to-ac',
            'woo_to_ac_main'
        );


        add_settings_field(
            'verbose_logging',
            'Verbose Logging',
            [$this, 'render_verbose_logging_field'],
            'woo-to-ac',
            'woo_to_ac_main'
        );
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->settings = get_option('woo_to_ac_settings', [
            'api_url' => '',
            'api_key' => '',
            'list_id' => ''
        ]);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form action="options.php" method="post">
                <?php
                settings_fields('woo-to-ac-settings');
                do_settings_sections('woo-to-ac');
                submit_button('Save Settings');
                ?>
            </form>

            <button type="button" id="test-connection" class="button button-secondary">
                Test Connection
            </button>

            <div id="connection-status" style="margin-top: 20px;"></div>

            <h2>Recent Logs</h2>
            <div id="sync-logs" style="margin-top: 20px;">
                <?php $this->display_logs(); ?>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                $('#test-connection').on('click', function () {
                    var button = $(this);
                    var statusDiv = $('#connection-status');

                    button.prop('disabled', true);
                    statusDiv.html('Testing connection...');

                    $.post(ajaxurl, {
                        action: 'test_ac_connection'
                    }, function (response) {
                        statusDiv.html(response.success ?
                            '<div style="color: green;">Connection successful!</div>' :
                            '<div style="color: red;">Connection failed: ' + response.data + '</div>'
                        );

                        if (response.success) {
                            // Refresh list dropdown
                            $.post(ajaxurl, {
                                action: 'get_ac_lists'
                            }, function (listsResponse) {
                                if (listsResponse.success) {
                                    var select = $('#ac_list_id');
                                    var currentValue = select.val();
                                    select.empty();
                                    $.each(listsResponse.data, function (id, name) {
                                        var option = $('<option>', {
                                            value: id,
                                            text: name
                                        });
                                        if (id === currentValue) {
                                            option.prop('selected', true);
                                        }
                                        select.append(option);
                                    });
                                }
                            });
                        }
                    }).always(function () {
                        button.prop('disabled', false);
                    });
                });
            });
        </script>
        <?php
    }

    public function render_api_url_field()
    {
        ?>
        <input type="text" id="ac_api_url" name="woo_to_ac_settings[api_url]"
            value="<?php echo esc_attr($this->settings['api_url'] ?? ''); ?>" class="regular-text">
        <?php
    }

    public function render_api_key_field()
    {
        ?>
        <input type="password" id="ac_api_key" name="woo_to_ac_settings[api_key]"
            value="<?php echo esc_attr($this->settings['api_key'] ?? ''); ?>" class="regular-text">
        <?php
    }

    public function render_list_field()
    {
        ?>
        <select id="ac_list_id" name="woo_to_ac_settings[list_id]" class="regular-text">
            <option value="">Select a list...</option>
            <?php
            if (!empty($this->settings['api_url']) && !empty($this->settings['api_key'])) {
                $lists = $this->get_ac_lists();
                foreach ($lists as $id => $name) {
                    $selected = ($this->settings['list_id'] ?? '') == $id ? 'selected' : '';
                    //echo "<option value='{$id}' {$selected}>{$name}</option>";
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($id),
                        selected($this->settings['list_id'], $id, false),
                        esc_html($name)
                    );
                }
            }
            ?>
        </select>
        <?php
    }

    public function render_verbose_logging_field()
    {
        ?>
        <input type="checkbox" id="verbose_logging" name="woo_to_ac_settings[verbose_logging]" value="1" <?php checked(($this->settings['verbose_logging'] ?? false), 1); ?>>
        <label for="verbose_logging">Enable detailed logging for debugging</label>
        <?php
    }

    // check we can connect to active campaign with api key and url

    public function test_connection()
    {
        $settings = get_option('woo_to_ac_settings');

        if (empty($settings['api_url']) || empty($settings['api_key'])) {
            wp_send_json_error('API URL and Key are required');
            return;
        }

        $response = wp_remote_get($settings['api_url'] . '/api/3/lists', [
            'headers' => [
                'Api-Token' => $settings['api_key']
            ]
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['lists'])) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Invalid response from ActiveCampaign');
        }
    }

    // get all lists from active campaign
    public function get_lists()
    {
        $lists = $this->get_ac_lists();
        wp_send_json_success($lists);
    }

    private function get_ac_lists()
    {
        $settings = get_option('woo_to_ac_settings');
        $lists = [];

        if (empty($settings['api_url']) || empty($settings['api_key'])) {
            return $lists;
        }

        $response = wp_remote_get($settings['api_url'] . '/api/3/lists', [
            'headers' => [
                'Api-Token' => $settings['api_key']
            ]
        ]);

        if (is_wp_error($response)) {
            return $lists;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['lists'])) {
            foreach ($body['lists'] as $list) {
                $lists[$list['id']] = $list['name'];
            }
        }

        return $lists;
    }
    public function handle_order_status_change($order_id, $old_status, $new_status, $order)
    {
        $processed = get_post_meta($order_id, '_ac_sync_processed', true);
        if ($processed) {
            $this->log("Order {$order_id} already processed", true);
            return;
        }

        if ($new_status === 'processing' || $new_status === 'completed') {
            $this->log("Scheduling sync for order {$order_id}", true);
            wp_schedule_single_event(time(), 'woo_to_ac_process_order', array($order_id));
        }
    }


    public function process_order($order_id)
    {
        $this->log("Processing order: {$order_id}", true);

        try {
            if (!$this->validate_settings()) {
                $this->log("Settings validation failed - sync aborted");
                return;
            }

            $contact_data = $this->get_contact_data_from_order($order_id);
            $contact_id = $this->find_existing_contact($contact_data['email']);

            if ($contact_id) {
                $this->add_contact_to_list($contact_id, $contact_data['email']);
                update_post_meta($order_id, '_ac_sync_processed', true);
                $this->log("Order {$order_id} synced successfully", true);
            } else {
                $this->log("No contact found for email: {$contact_data['email']}", true);
            }

        } catch (Exception $e) {
            $this->log("Error processing order: " . $e->getMessage());
        }
    }


    private function validate_settings()
    {
        $settings = get_option('woo_to_ac_settings');
        $this->log("Validating settings", true);

        if (empty($settings['api_url']) || empty($settings['api_key']) || empty($settings['list_id'])) {
            $this->log("Missing required settings");
            return false;
        }
        return true;
    }

    private function get_contact_data_from_order($order_id)
    {
        $order = wc_get_order($order_id);
        return [
            'email' => $order->get_billing_email(),
            'firstName' => $order->get_billing_first_name(),
            'lastName' => $order->get_billing_last_name()
        ];
    }

    private function ensure_contact_exists($contact_data)
    {
        $contact_id = $this->find_existing_contact($contact_data['email']);

        if ($contact_id) {
            return $this->update_contact($contact_id, $contact_data);
        }

        return $this->create_new_contact($contact_data);
    }

    private function find_existing_contact($email)
    {
        $settings = get_option('woo_to_ac_settings');
        $search_response = wp_remote_get(
            $settings['api_url'] . '/api/3/contacts?' . http_build_query(['email' => $email]),
            ['headers' => ['Api-Token' => $settings['api_key']]]
        );

        $search_body = json_decode(wp_remote_retrieve_body($search_response), true);

        if (!is_wp_error($search_response) && isset($search_body['contacts'][0]['id'])) {
            $contact_id = $search_body['contacts'][0]['id'];
            $this->log("Found contact ID: {$contact_id}", true);
            return $contact_id;
        }

        return null;
    }


    private function update_contact($contact_id, $contact_data)
    {
        $this->log("Starting update_contact function", true);
        $settings = get_option('woo_to_ac_settings');

        $update_response = wp_remote_put(
            $settings['api_url'] . '/api/3/contacts/' . $contact_id,
            [
                'headers' => [
                    'Api-Token' => $settings['api_key'],
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode(['contact' => $contact_data])
            ]
        );

        $this->log("Update API call completed", true);

        if (is_wp_error($update_response)) {
            $this->log("Failed to update contact: " . $update_response->get_error_message(), true);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($update_response);
        $response_body = wp_remote_retrieve_body($update_response);

        $this->log("Update response code: " . $response_code, true);
        $this->log("Update response body: " . $response_body, true);
        $this->log("Finishing update_contact function", true);

        return $contact_id;
    }
    private function create_new_contact($contact_data)
    {
        $settings = get_option('woo_to_ac_settings');
        $create_response = wp_remote_post(
            $settings['api_url'] . '/api/3/contacts',
            [
                'headers' => [
                    'Api-Token' => $settings['api_key'],
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode(['contact' => $contact_data])
            ]
        );

        if (is_wp_error($create_response)) {
            $this->log("Failed to create contact: " . $create_response->get_error_message());
            return null;
        }

        $create_body = json_decode(wp_remote_retrieve_body($create_response), true);
        if (isset($create_body['contact']['id'])) {
            $contact_id = $create_body['contact']['id'];
            $this->log("Created new contact with ID: " . $contact_id);
            return $contact_id;
        }

        return null;
    }

    private function add_contact_to_list($contact_id, $email)
    {
        $settings = get_option('woo_to_ac_settings');
        $list_data = [
            'contactList' => [
                'list' => $settings['list_id'],
                'contact' => $contact_id,
                'status' => 1
            ]
        ];

        try {
            $response = wp_remote_post(
                $settings['api_url'] . '/api/3/contactLists',
                [
                    'timeout' => 30,
                    'headers' => [
                        'Api-Token' => $settings['api_key'],
                        'Content-Type' => 'application/json'
                    ],
                    'body' => json_encode($list_data)
                ]
            );

            if (is_wp_error($response)) {
                $this->log("Failed to add contact to list: " . $response->get_error_message());
                return;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200 && $response_code !== 201) {
                $this->log("Failed to add contact - response code: {$response_code}");
                return;
            }

            $this->log("Added {$email} to list {$settings['list_id']} successfully");
        } catch (Exception $e) {
            $this->log("Error adding contact to list: " . $e->getMessage());
        }
    }
    // Modify the log function to use verbose setting
    private function log($message, $verbose = false)
    {
        $settings = get_option('woo_to_ac_settings');
        if ($verbose && empty($settings['verbose_logging'])) {
            return;
        }

        $logs = get_option('woo_to_ac_logs', []);
        array_unshift($logs, [
            'time' => current_time('mysql'),
            'message' => $message
        ]);

        // Keep only last 100 logs
        $logs = array_slice($logs, 0, 100);

        update_option('woo_to_ac_logs', $logs);
    }


    private function display_logs()
    {
        $logs = get_option('woo_to_ac_logs', []);

        if (empty($logs)) {
            echo '<p>No logs yet.</p>';
            return;
        }

        echo '<table class="widefat">';
        echo '<thead><tr><th>Time</th><th>Message</th></tr></thead>';
        echo '<tbody>';

        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . esc_html($log['time']) . '</td>';
            echo '<td>' . esc_html($log['message']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}

// Initialize the plugin
add_action('plugins_loaded', function () {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-error">
                <p>WooCommerce to ActiveCampaign List Sync requires WooCommerce to be installed and activated.</p>
            </div>
            <?php
        });
        return;
    }

    WooToAC_Plugin::get_instance();
});


// Uninstall hook
register_uninstall_hook(__FILE__, 'woo_to_ac_uninstall');

function woo_to_ac_uninstall()
{
    // Clean up plugin options
    delete_option('woo_to_ac_settings');

    // Clean up post meta
    global $wpdb;
    $wpdb->delete($wpdb->postmeta, array('meta_key' => '_ac_sync_processed'));

    // Clear any scheduled hooks
    wp_clear_scheduled_hook('woo_to_ac_sync_cron');
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'woo_to_ac_deactivate');

function woo_to_ac_deactivate()
{
    // Clear any scheduled hooks
    wp_clear_scheduled_hook('woo_to_ac_sync_cron');
}