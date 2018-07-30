<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Methods related to WooCommerce Cart
 *
 * @class WCCF_WC_Cart
 * @package WooCommerce Custom Fields
 * @author RightPress
 */
if (!class_exists('WCCF_WC_Cart')) {

class WCCF_WC_Cart
{

    private $custom_woocommerce_price_num_decimals;

    private $extra_cart_items = array();

    // Singleton instance
    protected static $instance = false;

    /**
     * Singleton control
     */
    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor class
     *
     * @access public
     * @return void
     */
    public function __construct()
    {
        // Add field values to cart item meta data
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_product_field_values'), 10, 3);

        // Add extra cart items after splitting quantity-based field data
        add_action('woocommerce_add_to_cart', array($this, 'add_extra_cart_items'), 99, 6);

        // Add to cart validation
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_cart_item_product_field_values'), 10, 6);
        add_action('wp_loaded', array($this, 'maybe_redirect_to_product_page_after_failed_validation'), 20);

        // Adjust cart item pricing
        add_filter('woocommerce_add_cart_item', array($this, 'adjust_cart_item_pricing'), 12);

        // Remove cart items with invalid configuration
        add_action('woocommerce_cart_loaded_from_session', array($this, 'remove_cart_items_with_invalid_configuration'), 1);

        // Cart item loaded from session
        add_filter('woocommerce_get_cart_item_from_session', array($this, 'get_cart_item_from_session'), 12, 3);

        // Get values for display in cart
        add_filter('woocommerce_get_item_data', array($this, 'get_values_for_display'), 12, 2);

        // Add configuration query vars to product link
        add_filter('woocommerce_cart_item_permalink', array($this, 'add_query_vars_to_cart_item_link'), 99, 3);

        // Copy product field values from order item meta to cart item meta on Order Again
        add_filter('woocommerce_order_again_cart_item_data', array($this, 'move_product_field_values_on_order_again'), 10, 3);
    }

    /**
     * Add product field values to cart item meta
     *
     * Splits cart item into multiple cart items if cart item has different
     * configurations for different quantity units (quantity-based fields)
     *
     * @access public
     * @param array $cart_item_data
     * @param int $product_id
     * @param int $variation_id
     * @return array
     */
    public function add_cart_item_product_field_values($cart_item_data, $product_id, $variation_id)
    {
        // Only do this for first add to cart event during one request (issue #384)
        if (defined('WCCF_ADD_TO_CART_PROCESSED')) {
            return $cart_item_data;
        }
        else {
            define('WCCF_ADD_TO_CART_PROCESSED', true);
        }

        // Allow developers to skip adding product field values to cart item
        if (!apply_filters('wccf_add_cart_item_product_field_values', true, $cart_item_data, $product_id, $variation_id)) {
            return $cart_item_data;
        }

        // Maybe skip product fields for this product based on various conditions
        if (WCCF_WC_Product::skip_product_fields($product_id, $variation_id)) {
            return $cart_item_data;
        }

        // Get fields to save values for
        $fields = WCCF_Product_Field_Controller::get_filtered(null, array('item_id' => $product_id, 'child_id' => $variation_id));

        // Get quantity
        $quantity = empty($_REQUEST['quantity']) ? 1 : wc_stock_amount($_REQUEST['quantity']);

        // Sanitize field values
        // Note - we will need to pass $variation_id here somehow if we ever implement variation-level conditions
        $values = WCCF_Field_Controller::sanitize_posted_field_values('product_field', array(
            'object_id'         => $product_id,
            'fields'            => $fields,
            'quantity'          => $quantity,
        ));

        // Check if any values were found
        if ($values) {

            // Group value sets by quantity index if quantity-based fields are used
            $value_sets = $this->group_value_sets_by_quantity_index($values, $fields, $quantity);

            // Itentifier by which we attribute extra cart items to the correct cart item
            $parent_hash = null;

            // Iterate over value sets
            foreach ($value_sets as $value_set) {

                $set_values = $value_set['values'];

                // First set of values are added to parent cart item
                if ($parent_hash === null) {

                    // Set values to cart item
                    if (!empty($set_values)) {
                        $cart_item_data['wccf'] = $set_values;
                        $cart_item_data['wccf_version'] = WCCF_VERSION;
                    }

                    // Generate parent data hash
                    $parent_hash = md5(json_encode(array(
                        (int) $product_id,
                        (int) $variation_id,
                        (array) $set_values,
                    )));
                }
                // Data for extra cart items is saved in memory to be used when woocommerce_add_to_cart is called
                else {

                    // Set values and quantities by parent hash
                    $this->extra_cart_items[$parent_hash][] = array(
                        'values'    => $set_values,
                        'quantity'  => count($value_set['quantity_indexes']),
                    );
                }
            }
        }

        return $cart_item_data;
    }

    /**
     * Add extra cart items after splitting quantity-based field data
     *
     * @access public
     * @param string $cart_item_key
     * @param int $product_id
     * @param int $quantity
     * @param int $variation_id
     * @param array $variation
     * @param array $cart_item_data
     * @return void
     */
    public function add_extra_cart_items($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        global $woocommerce;

        // Get parent item quantity
        $parent_quantity = $woocommerce->cart->cart_contents[$cart_item_key]['quantity'];

        // Unable to determine parent quantity
        if (!$parent_quantity) {
            return;
        }

        // Ensure we don't use more quantity than we have if quantity was reduced programmatically by developers
        $limit = ($parent_quantity < $quantity ? $parent_quantity : $quantity) - 1;

        // Generate parent data hash
        $parent_hash = md5(json_encode(array(
            (int) $product_id,
            (int) $variation_id,
            (isset($cart_item_data['wccf']) && is_array($cart_item_data['wccf'])) ? $cart_item_data['wccf'] : array(),
        )));

        // Check if this item is parent to any extra items
        if (!empty($this->extra_cart_items[$parent_hash])) {

            // Iterate over extra cart items
            foreach ($this->extra_cart_items[$parent_hash] as $key => $data) {

                // Limit not sufficient
                if (!$limit) {
                    break;
                }

                // Get current quantity to use
                $current_quantity = $limit < $data['quantity'] ? $limit : $data['quantity'];

                // Reduce quantity of parent cart item
                $new_parent_quantity = $woocommerce->cart->cart_contents[$cart_item_key]['quantity'] - $current_quantity;
                $woocommerce->cart->set_quantity($cart_item_key, $new_parent_quantity, false);

                // Note: not sure if we better reset cart item data array here so that 3rd party plugins can
                // add their custom data again or we better keep their values and just reset ours (resetting for now)
                $cart_item_data = array();

                // Add values if any
                // Note: we can't add empty values to cart item data because these units may need to be merged
                // with existing cart item which was added in a regular way (with no values submitted and no 'wccf' data present
                if (!empty($data['values'])) {
                    $cart_item_data['wccf'] = $data['values'];
                    $cart_item_data['wccf_version'] = WCCF_VERSION;
                }

                // Prevent infinite loop
                remove_action('woocommerce_add_to_cart', array($this, 'add_extra_cart_items'));

                // Add to cart
                do_action('wccf_before_extra_item_add_to_cart', $product_id, $current_quantity, $variation_id, $variation, $cart_item_data);
                $woocommerce->cart->add_to_cart($product_id, $current_quantity, $variation_id, $variation, $cart_item_data);
                do_action('wccf_after_extra_item_add_to_cart', $product_id, $current_quantity, $variation_id, $variation, $cart_item_data);

                // Prevent infinite loop
                add_action('woocommerce_add_to_cart', array($this, 'add_extra_cart_items'), 99, 6);

                // Update limit
                $limit -= $current_quantity;
            }

            // Unset extra items from memory
            unset($this->extra_cart_items[$parent_hash]);
        }
    }

    /**
     * Group values by quantity indexes and identical sets of values (quantity-based fields)
     *
     * @access protected
     * @param array $values
     * @param array $fields
     * @param int $quantity
     * @return array
     */
    protected function group_value_sets_by_quantity_index($values, $fields, $quantity)
    {
        $value_sets = array();

        // Group values by quantity indexes
        $grouped = array();
        $shared  = array();

        // Fill array with all quantity indexes
        for ($i = 0; $i < $quantity; $i++) {
            $grouped[$i] = array();
        }

        // Iterate over all values
        foreach ($values as $field_id => $field_value) {

            // Get quantity index and clean field id
            $quantity_index = WCCF_Field_Controller::get_quantity_index_from_field_id($field_id, 0);
            $clean_field_id = WCCF_Field_Controller::clean_field_id($field_id);

            // Load field
            if ($field = WCCF_Field_Controller::cache($clean_field_id)) {

                // Field is quantity based
                if ($field->is_quantity_based()) {

                    // Add value to array
                    $grouped[$quantity_index][$clean_field_id] = $field_value;
                }
                // Field is not quantity based
                else {

                    // Add to shared values array
                    $shared[$clean_field_id] = $field_value;
                }
            }
        }

        // Sort shared values by field id
        ksort($shared);

        // Sort groups array by quantity index
        ksort($grouped);

        // Iterate over groups
        foreach ($grouped as $quantity_index => $group) {

            // Sort values by field id
            ksort($group);

            // Add shared values to the end of the values list
            foreach ($shared as $field_id => $field_value) {
                $group[$field_id] = $field_value;
            }

            // Generate hash
            $hash = md5(json_encode($group));

            // Add current values if not yet added
            if (!isset($value_sets[$hash])) {
                $value_sets[$hash] = array(
                    'quantity_indexes'  => array(),
                    'values'            => $group,
                );
            }

            // Track quantity indexes
            $value_sets[$hash]['quantity_indexes'][] = $quantity_index;
        }

        return $value_sets;
    }

    /**
     * Validate product field values on add to cart
     *
     * @access public
     * @param bool $is_valid
     * @param int $product_id
     * @param int $quantity
     * @param int $variation_id
     * @param array $variation
     * @param array $cart_item_data
     * @return bool
     */
    public function validate_cart_item_product_field_values($is_valid, $product_id, $quantity, $variation_id = null, $variation = null, $cart_item_data = null)
    {
        // Maybe skip product fields for this product based on various conditions
        if (WCCF_WC_Product::skip_product_fields($product_id, $variation_id)) {
            return $is_valid;
        }

        // Get fields for validation
        $fields = WCCF_Product_Field_Controller::get_filtered(null, array('item_id' => $product_id, 'child_id' => $variation_id));

        // Validate all fields
        // Note - we will need to pass $variation_id here somehow if we ever implement variation-level conditions
        $validation_result = WCCF_Field_Controller::validate_posted_field_values('product_field', array(
            'object_id' => $product_id,
            'fields'    => $fields,
            'quantity'  => $quantity,
            'values'    => (is_array($cart_item_data) && !empty($cart_item_data['wccf'])) ? $cart_item_data['wccf'] : null,
        ));

        if (!$validation_result) {
            define('WCCF_ADD_TO_CART_VALIDATION_FAILED', true);
            return false;
        }

        return $is_valid;
    }

    /**
     * Maybe redirect to product page if add to cart action was initiated via
     * URL and its validation failed and URL does not include product URL
     *
     * @access public
     * @return void
     */
    public function maybe_redirect_to_product_page_after_failed_validation()
    {
        // Our validation failed
        if (defined('WCCF_ADD_TO_CART_VALIDATION_FAILED') && WCCF_ADD_TO_CART_VALIDATION_FAILED) {

            // Add to cart was from link as opposed to regular add to cart when data is posted
            if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['add-to-cart'])) {

                // Get product
                $product = wc_get_product($_GET['add-to-cart']);

                // Product was not loaded
                if (!$product) {
                    return;
                }

                // Get urls to compare
                $request_url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                $product_url = untrailingslashit(get_permalink($product->get_id()));

                // Current request url does not contain product url
                if (strpos($request_url, str_replace(array('http://', 'https://'), array('', ''), $product_url)) === false) {

                    // Add query string to product url
                    if (strpos($product_url, '?') === false) {
                        $redirect_url = $product_url . $_SERVER['REQUEST_URI'];
                    }
                    else {
                        $redirect_url = $product_url . str_replace('?', '&', $_SERVER['REQUEST_URI']);
                    }

                    // Unset notices since we will repeat the same exact process and all notices will be added again
                    wc_clear_notices();

                    // Redirect to product page
                    wp_redirect($redirect_url);
                    exit;
                }
            }
        }
    }

    /**
     * Adjust cart item pricing
     *
     * @access public
     * @param array $cart_item
     * @return
     */
    public function adjust_cart_item_pricing($cart_item)
    {
        // Flag cart item product so that other methods do not apply pricing rules
        $cart_item['data']->wccf_cart_item_product = true;

        // Allow developers to skip pricing adjustment
        if (apply_filters('wccf_skip_pricing_for_cart_item', false, $cart_item)) {
            return $cart_item;
        }

        // Get quantity
        $quantity = !empty($cart_item['quantity']) ? (float) $cart_item['quantity'] : 1;

        // Get variation id
        $variation_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : null;

        // Get product price
        $price = $cart_item['data']->get_price();

        // Get cart item data
        $cart_item_data = !empty($cart_item['wccf']) ? $cart_item['wccf'] : array();

        // Get adjusted price
        $adjusted_price = WCCF_Pricing::get_adjusted_price($price, $cart_item['product_id'], $variation_id, $cart_item_data, $quantity, false, false, $cart_item['data'], true);

        // Check if price was actually adjusted
        if ($adjusted_price !== (float) $price) {

            // Set new price
            $cart_item['data']->set_price($adjusted_price);
        }

        // Return item
        return $cart_item;
    }

    /**
     * Cart loaded from session
     *
     * @access public
     * @param array $cart_item
     * @param array $values
     * @param string $key
     * @return array
     */
    public function get_cart_item_from_session($cart_item, $values, $key)
    {
        // Check if we have any product field data stored in cart
        if (!empty($values['wccf'])) {

            // Migrate data if needed
            if (WCCF_Migration::support_for('1')) {
                foreach ($values['wccf'] as $key => $value) {
                    if (isset($value['key']) && !isset($value['data'])) {
                        $values['wccf'] = WCCF_Migration::product_fields_in_cart_from_1_to_2($values['wccf']);
                        break;
                    }
                }
            }

            // Set field values
            $cart_item['wccf'] = $values['wccf'];

            // Set plugin version
            if (!empty($values['wccf_version'])) {
                $cart_item['wccf_version'] = $values['wccf_version'];
            }
        }

        // Maybe adjust pricing
        $cart_item = $this->adjust_cart_item_pricing($cart_item);

        // Return item
        return $cart_item;
    }

    /**
     * Get product field values to display in cart
     *
     * @access public
     * @param array $data
     * @param array $cart_item
     * @return array
     */
    public function get_values_for_display($data, $cart_item)
    {
        if (!empty($cart_item['wccf'])) {
            foreach ($cart_item['wccf'] as $field_id => $field_value) {

                // Get field
                $field = WCCF_Field_Controller::get($field_id, 'wccf_product_field');

                // Make sure this field exists
                if (!$field) {
                    continue;
                }

                // Check if pricing can be displayed for this product
                $product_id = RightPress_Help::get_wc_product_absolute_id($cart_item['data']);
                $variation_id = $cart_item['data']->is_type('variation') ? $cart_item['data']->get_id() : null;
                $display_pricing = !WCCF_WC_Product::skip_pricing($product_id, $variation_id);

                // Get display value
                $display_value = $field->format_display_value($field_value, $display_pricing, true);

                // Add to data array
                $data[] = array(
                    'name'      => $field->get_label(),
                    'value'     => $display_value,
                    'display'   => $display_value,
                );
            }
        }

        return $data;
    }

    /**
     * Add configuration query vars to product link
     *
     * @access public
     * @param string $link
     * @param array $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function add_query_vars_to_cart_item_link($link, $cart_item, $cart_item_key)
    {
        // No link provided
        if (empty($link)) {
            return $link;
        }

        // Do not add query vars
        if (!apply_filters('wccf_preconfigured_cart_item_product_link', true, $link, $cart_item, $cart_item_key)) {
            return $link;
        }

        $new_link = $link;
        $quantity_based_field_found = false;

        // Add a flag to indicate that this link is cart item link to product
        $new_link = add_query_arg('wccf_qv_conf', 1, $new_link);

        // Cart item does not have custom fields
        if (empty($cart_item['wccf'])) {
            return $new_link;
        }

        // Iterate over field values
        foreach ($cart_item['wccf'] as $field_id => $field_value) {

            // Load field
            $field = WCCF_Field_Controller::cache(WCCF_Field_Controller::clean_field_id($field_id));

            // Unable to load field - if we can't get full configuration, don't add anything at all
            if (!$field) {
                return $link;
            }

            // Check if field is quantity based
            $quantity_based_field_found = $quantity_based_field_found ?: $field->is_quantity_based();

            // Get query var key
            $query_var_key = 'wccf_' . $field->get_context() . '_' . $field->get_id();

            // Handle array values
            if (is_array($field_value['value'])) {

                // Fix query var key
                $query_var_key .= '[]';

                $is_first = true;

                foreach ($field_value['value'] as $single_value) {

                    // Encode current value
                    $current_value = rawurlencode($single_value);

                    // Handle first value
                    if ($is_first) {

                        // Add query var
                        $new_link = add_query_arg($query_var_key, $current_value, $new_link);

                        // Check if query var was added
                        if (strpos($new_link, $query_var_key) !== false) {
                            $is_first = false;
                        }
                    }
                    // Handle subsequent values - add_query_arg does not allow duplicate query vars
                    else {

                        if ($frag = strstr($new_link, '#')) {
                            $new_link = substr($new_link, 0, -strlen($frag));
                        }

                        $new_link .= '&' . $query_var_key . '=' . $current_value;

                        if ($frag) {
                            $new_link .= $frag;
                        }
                    }

                }
            }
            else {
                $new_link = add_query_arg($query_var_key, rawurlencode($field_value['value']), $new_link);
            }
        }

        // Add quantity
        if ($quantity_based_field_found && strpos($new_link, 'wccf_') !== false && !empty($cart_item['quantity']) && $cart_item['quantity'] > 1) {
            $new_link .= '&wccf_quantity=' . $cart_item['quantity'];
        }

        // Bail if our URL is longer than URL length limit of 2000
        if (strlen($new_link) > 2000) {
            return $link;
        }

        // Return new link
        return $new_link;
    }

    /**
     * Copy product field values from order item meta to cart item meta on Order Again
     *
     * @access public
     * @param array $cart_item_data
     * @param object|array $order_item
     * @param object $order
     * @return array
     */
    public function move_product_field_values_on_order_again($cart_item_data, $order_item, $order)
    {
        // Get order item meta
        $order_item_meta = $order_item['item_meta'];

        // Iterate over order item meta
        foreach ($order_item_meta as $key => $value) {

            // Check if this is our field id entry
            if (RightPress_Help::string_begins_with_substring($key, '_wccf_pf_id_')) {

                // Attempt to load field
                if ($field = WCCF_Field_Controller::cache($value)) {

                    $current = array();

                    // Field is disabled
                    if (!$field->is_enabled()) {
                        continue;
                    }

                    // Get field key
                    $field_key = $field->get_key();

                    // Quantity index
                    $quantity_index = null;

                    // Attempt to get quantity index from meta entry
                    if ($key !== ('_wccf_pf_id_' . $field_key)) {

                        $quantity_index = str_replace(('_wccf_pf_id_' . $field_key . '_'), '', $key);
                        $extra_data_access_key = $field->get_extra_data_access_key($quantity_index);

                        // Result is not numeric
                        if (!is_numeric($quantity_index)) {
                            continue;
                        }

                        // Unable to validate quantity index
                        if (!isset($order_item_meta[$extra_data_access_key]['quantity_index']) || ((string) $order_item_meta[$extra_data_access_key]['quantity_index'] !== (string) $quantity_index)) {
                            continue;
                        }
                    }

                    // Cart/order items with quantity indexes are no longer supported
                    if ($quantity_index) {

                        // Unset any properties set earlier
                        unset($cart_item_data['wccf']);
                        unset($cart_item_data['wccf_version']);

                        // Do not check subsequent items
                        break;
                    }

                    // Get access keys
                    $value_access_key = $field->get_value_access_key();
                    $extra_data_access_key = $field->get_extra_data_access_key();

                    // Value or extra data entry is not present
                    if (!isset($order_item_meta[$value_access_key]) || !isset($order_item_meta[$extra_data_access_key])) {
                        continue;
                    }

                    // Reference value
                    $current_value = $order_item_meta[$value_access_key];

                    // Remove no longer existent options
                    if ($field->uses_options()) {

                        // Get options
                        $options = $field->get_options_list();

                        // Field can have multiple values
                        if ($field->accepts_multiple_values()) {

                            // Value is not array
                            if (!is_array($current_value)) {
                                continue;
                            }

                            // Value is not empty
                            if (!empty($current_value)) {

                                // Unset non existent options
                                foreach ($current_value as $index => $option_key) {
                                    if (!isset($options[(string) $option_key])) {
                                        unset($current_value[$index]);
                                    }
                                }

                                // No remaining values
                                if (empty($current_value)) {
                                    continue;
                                }
                            }
                        }
                        // Field always has one value
                        else {

                            // Option no longer exists
                            if (!isset($options[(string) $current_value])) {
                                continue;
                            }
                        }
                    }

                    // Remove no longer existent files and prepare file data array
                    if ($field->field_type_is('file')) {

                        $all_file_data = array();

                        // Value is not array
                        if (!is_array($current_value)) {
                            continue;
                        }

                        // Value is not empty
                        if (!empty($current_value)) {

                            // Unset non existent files
                            foreach ($current_value as $index => $access_key) {

                                $file_data_access_key = $field->get_file_data_access_key($access_key);

                                // File data not present in meta
                                if (!isset($order_item_meta[$file_data_access_key])) {
                                    unset($current_value[$index]);
                                    continue;
                                }

                                // Reference file data
                                $file_data = $order_item_meta[$file_data_access_key];

                                // File not available
                                if (!WCCF_Files::locate_file($file_data['subdirectory'], $file_data['storage_key'])) {
                                    unset($current_value[$index]);
                                    continue;
                                }

                                // Add to file data array
                                $all_file_data[$access_key] = $file_data;
                            }

                            // No remaining values
                            if (empty($current_value)) {
                                continue;
                            }
                        }
                    }

                    // Add value
                    $current['value'] = $current_value;

                    // Add extra data
                    $current['data'] = array();

                    // Add files
                    $current['files'] = $field->field_type_is('file') ? $all_file_data : array();

                    // Add to main array
                    $cart_item_data['wccf'][$field->get_id()] = $current;

                    // Add version number
                    $cart_item_data['wccf_version'] = WCCF_VERSION;
                }
            }
        }

        return $cart_item_data;
    }

    /**
     * Remove cart items with invalid configuration
     *
     * @access public
     * @param object $cart
     * @return void
     */
    public function remove_cart_items_with_invalid_configuration($cart)
    {
        // Iterate over cart items
        if (is_array($cart->cart_contents) && !empty($cart->cart_contents)) {
            foreach ($cart->cart_contents as $cart_item_key => $cart_item) {

                // Remove cart items added before version 2.2.4 that have quantity based fields
                // Note: Pre-2.2.4 items did not have version number set at all
                if (isset($cart_item['wccf']) && !isset($cart_item['wccf_version'])) {

                    $remove = false;

                    // Iterate over values
                    foreach ($cart_item['wccf'] as $field_id => $value) {

                        // Get quantity index and clean field id
                        $quantity_index = WCCF_Field_Controller::get_quantity_index_from_field_id($field_id);
                        $clean_field_id = WCCF_Field_Controller::clean_field_id($field_id);

                        // Load field
                        $field = WCCF_Field_Controller::cache($clean_field_id);

                        // Flag for removal
                        if ($quantity_index || !is_object($field) || $field->is_quantity_based()) {
                            $remove = true;
                        }
                    }

                    // Remove cart item
                    if ($remove) {
                        $cart->remove_cart_item($cart_item_key);
                    }
                    // Add version number so that we only run this once
                    else {
                        $cart->cart_contents[$cart_item_key]['wccf_version'] = WCCF_VERSION;
                    }
                }
            }
        }
    }





}

WCCF_WC_Cart::get_instance();

}
