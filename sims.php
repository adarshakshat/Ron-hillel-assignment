<?php
/**
 * Plugin Name: Simple Item Management System
 * Description: WordPress plugin that implements 2 REST API endpoints for managing different types of records (like simple products, time-based services, and subscription plans).
 * Version: 0.0.1
 * Author: Adarsh Akshat
 * Text Domain: sims
 */

defined('ABSPATH') || exit;

class Sims {
    private static $instance = null;
    private $data;
    private $id_counters; // to create index for records
    private $valid_types; // Class property to hold valid record types

    /**
     * Private constructor to initialize data and counters.
     */
    private function __construct() {
        $this->data = [
            'products' => [],
            'services' => [],
            'subscriptions' => [],
        ];
        $this->id_counters = [
            'products' => 1,
            'services' => 1,
            'subscriptions' => 1,
        ];

        // Define valid record types
        $this->valid_types = ['product', 'service', 'subscription'];

        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Get the singleton Class instance.
     *
     * @return Sims The singleton instance of the class.
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register REST API routes of the plugin.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route('sims/v1', '/items', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_records'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'add_record'],
                'args' => $this->get_add_record_args(),
            ],
        ]);
    }

    /**
     * Fetch all records.
     *
     * @return WP_REST_Response All stored records.
     */
    public function get_records() {
        // Fetch all stored records, in this case just an array
        $records = $this->get_all_records();

        // Initialize an empty array to hold sanitized and structured records grouped by type
        $escaped_records = [
            'product' => [],
            'service' => [],
            'subscription' => [],
        ];

        // Loop through each type (products, services, subscriptions) to escape data and structure
        foreach ($records as $type => $items) {
            foreach ($items as $item) {
                // Initialize an empty array to hold the sanitized record for each item
                $record = [
                    'id'    => isset($item['id']) ? esc_html($item['id']) : null,
                    'name'  => isset($item['name']) ? esc_html($item['name']) : null,
                ];

                // Use switch to handle the different record types
                switch ($type) {
                    case 'products':
                        $record['price'] = isset($item['price']) ? esc_html($item['price']) : 0;
                        // Add the escaped record to the 'product' group
                        $escaped_records['product'][] = $record;
                        break;

                    case 'services':
                        $record['duration'] = isset($item['duration']) ? esc_html($item['duration']) : null;
                        // Add the escaped record to the 'service' group
                        $escaped_records['service'][] = $record;
                        break;

                    case 'subscriptions':
                        $record['price'] = isset($item['price']) ? esc_html($item['price']) : 0;
                        $record['frequency'] = isset($item['frequency']) ? esc_html($item['frequency']) : null;
                        // Add the escaped record to the 'subscription' group
                        $escaped_records['subscription'][] = $record;
                        break;
                }
            }
        }

        // Return the sanitized records grouped by type with a 200 OK status
        return new WP_REST_Response($escaped_records, 200);
    }

    /**
     * Retrieve all stored records.
     *
     * @return array An array containing all records grouped by type.
     */
    public function get_all_records() {
        //In normal scenario we generally pull the data here and return, instead of returning our in memory var
        return $this->data;
    }

    /**
     * Add a new record.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The created record or an error response.
     */
    public function add_record(WP_REST_Request $request) {
        // Sanitize inputs
        $type = sanitize_text_field($request->get_param('type'));
        $name = sanitize_text_field($request->get_param('name'));

        // Validate the name
        if (empty($name) || !is_string($name)) {
            return new WP_REST_Response(['error' => 'Name is either missing or is not in proper format'], 400);
        }

        // Validate record type using the class property
        if (!in_array($type, $this->valid_types)) {
            return new WP_REST_Response(['error' => 'Invalid record type'], 400);
        }

        // Handle type-specific fields and sanitize
        switch ($type) {
            case 'product':
                $price = $request->get_param('price');
                $price = floatval($price); // Sanitize price
                if (empty($price) || !is_numeric($price)) {
                    return new WP_REST_Response(['error' => 'Price is either missing or is not in proper format'], 400);
                }
                break;
            case 'service':
                $duration = $request->get_param('duration');
                $duration = intval($duration); // Sanitize duration
                if (empty($duration) || !is_numeric($duration)) {
                    return new WP_REST_Response(['error' => 'Duration is either missing or is not in proper format'], 400);
                }
                $this->add_service($name, $duration);
                break;
            case 'subscription':
                $price = $request->get_param('price');
                $price = floatval($price); // Sanitize price
                if (empty($price) || !is_numeric($price)) {
                    return new WP_REST_Response(['error' => 'Price is not in proper format'], 400);
                }
                $frequency = sanitize_text_field($request->get_param('frequency'));
                if (empty($frequency) || !in_array($frequency, ['monthly', 'yearly'])) {
                    return new WP_REST_Response(['error' => 'Frequency is either missing or is not in proper format'], 400);
                }
                $this->subscription($name, $price, $frequency);
                break;
        }

        // Generate a unique ID for the new record, required in as we are not saving in db , hence we need to ensure a unique ID Exists
        $index = $type . 's';
        $id = $this->id_counters[$index]++;
        $record = [
            'id'    => $id,
            'type'  => $type,
            'name'  => $name,
            'price' => $price,
        ];

        // Store the sanitized record at the unique id index
        $this->data[$index][] = $record;

        // Return the escaped record
        return new WP_REST_Response([
            'id'    => esc_html($record['id']),
            'type'  => esc_html($record['type']),
            'name'  => esc_html($record['name']),
            'price' => esc_html($record['price']),
        ], 201);
    }

    /**
     * Define arguments for adding a record.
     *
     * @return array Array of arguments with validation callbacks.
     */
    private function get_add_record_args() {
        return [
            'type' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return in_array($param, $this->valid_types);
                },
            ],
            'name' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param);
                },
            ],
            'price' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_numeric($param);
                },
            ],
            'frequency' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return in_array($param, ['monthly', 'yearly']);
                },
            ],
            'duration' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_numeric($param);
                },
            ],
        ];
    }
}

Sims::get_instance();
