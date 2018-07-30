<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version Control
 */
$version = '1002';

global $rightpress_version;

if (!$rightpress_version || $rightpress_version < $version) {
    $rightpress_version = $version;
}

/**
 * Main Loader Class
 */
if (!class_exists('RightPress_Loader_1002')) {

    final class RightPress_Loader_1002
    {

        /**
         * Load main shared files
         *
         * @access public
         * @return void
         */
        public static function load()
        {
            // Relative file paths to load
            $file_paths = array(
                '/helpers/*.class.php',
                '/abstracts/*.class.php',
                '/classes/*.class.php',
                '/interfaces/*.php',
            );

            // Load files
            foreach ($file_paths as $file_path) {
                foreach (glob(dirname(__FILE__) . $file_path) as $filename) {
                    require_once $filename;
                }
            }
        }

        /**
         * Load individual component
         *
         * @access public
         * @param string $name
         * @return void
         */
        public static function load_component($name)
        {
            // Get full component path
            $file_path = dirname(__FILE__) . '/components/' . $name . '/' . $name . '.class.php';

            // Load component if it exists
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }

        /**
         * Load individual jQuery plugin
         *
         * @access public
         * @param string $name
         * @return void
         */
        public static function load_jquery_plugin($name)
        {
            global $rightpress_version;

            // Get relative file path
            $file_path = 'jquery-plugins/' . $name . '/' . $name;

            // Enqueue script file
            wp_enqueue_script($name, plugins_url('', __FILE__) . '/' . $file_path . '.js', array('jquery'), $rightpress_version);

            // Enqueue optional styles file
            if (file_exists(plugin_dir_path(__FILE__) . $file_path . '.css')) {
                wp_enqueue_style($name, plugins_url('', __FILE__) . '/' . $file_path . '.css', array(), $rightpress_version);
            }
        }
    }
}

/**
 * Convenience Loader Class
 */
if (!class_exists('RightPress_Loader')) {

    final class RightPress_Loader
    {

        /**
         * Method overload
         *
         * @access public
         * @param string $method_name
         * @param array $arguments
         * @return mixed
         */
        public static function __callStatic($method_name, $arguments)
        {
            global $rightpress_version;

            // Call method of main class
            return call_user_func_array(array(('RightPress_Loader_' . $rightpress_version), $method_name), $arguments);
        }
    }
}
