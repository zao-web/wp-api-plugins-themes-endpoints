<?php
/**
 * Plugin Name: Zao WP REST API Plugins Endpoints
 * Description: Plugin endpoints for the WP REST API
 * Version:     0.1.0
 * Plugin URI:  https://github.com/zao-web/zao-wp-rest-api-plugins-endpoints
 * Author:      Zao
 * Author URI:  https://zao.is
 * Donate link: https://zao.is
 * License:     GPLv2
 * Text Domain: zao-wp-api-plugins
 * Domain Path: /languages
 */

function plugins_themes_rest_api_init() {
	if ( class_exists( 'WP_REST_Controller' )
			&& ! class_exists( 'WP_REST_Plugins_Controller' ) ) {
		require_once dirname( __FILE__ ) . '/lib/class-wp-rest-plugins-controller.php';
	}

	$plugins_controller = new Zao_REST_Plugins_Controller;
	$plugins_controller->register_routes();
}

add_action( 'rest_api_init', 'plugins_themes_rest_api_init' );
