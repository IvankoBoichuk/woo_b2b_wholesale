<?php
/**
Plugin Name: Gravity Forms Wholeseller Add-On
Version: 1.0.0
Author: Yaroslav
**/

defined( 'ABSPATH' ) || die();
require_once plugin_dir_path( __FILE__ ) . 'WOO_Wholeseller.php';

add_action('plugins_loaded', function() {
    new WOO_Wholeseller();
});