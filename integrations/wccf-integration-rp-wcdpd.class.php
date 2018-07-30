<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integration
 * Plugin: WooCommerce Dynamic Pricing & Discounts
 * Author: RightPress
 *
 * @class WCCF_Integration_RP_WCDPD
 * @package WooCommerce Custom Fields
 * @author RightPress
 */
if (!class_exists('WCCF_Integration_RP_WCDPD')) {

class WCCF_Integration_RP_WCDPD
{
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
        // Maybe prevent automatic add to cart
        add_filter('rp_wcdpd_add_free_product_to_cart', array($this, 'allow_automatic_add_to_cart'), 10, 4);

        // Prevent extra cart items (quantity based fields) to trigger automatic add to cart (BOGO type rules)
        add_action('wccf_before_extra_item_add_to_cart', array($this, 'disable_auto_add_to_cart'));
        add_action('wccf_after_extra_item_add_to_cart', array($this, 'enable_auto_add_to_cart'));
    }

    /**
     * Maybe prevent automatic add to cart if required product fields are
     * enabled for product
     *
     * @access public
     * @param bool $allow
     * @param object $product
     * @param array $rule
     * @param string $cart_item_key
     * @return bool
     */
    public function allow_automatic_add_to_cart($allow, $product, $rule, $cart_item_key)
    {
        // Check if product has any required fields to fill in
        if (WCCF_WC_Product::product_has_fields_to_display($product, true)) {
            return false;
        }

        return $allow;
    }

    /**
     * Disable automatic add to cart while adding extra cart items
     *
     * @access public
     * @return void
     */
    public function disable_auto_add_to_cart()
    {
        add_filter('rp_wcdpd_add_free_product_to_cart', '__return_false', 999999);
    }

    /**
     * Enable automatic add to cart after extra cart items are added
     *
     * @access public
     * @return void
     */
    public function enable_auto_add_to_cart()
    {
        remove_filter('rp_wcdpd_add_free_product_to_cart', '__return_false');
    }



}

WCCF_Integration_RP_WCDPD::get_instance();

}
