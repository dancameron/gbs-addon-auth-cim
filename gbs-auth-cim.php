<?php
/*
Plugin Name: Group Buying Payment Processor - Authorize.net CIM
Version: 1.4.5
Plugin URI: http://sproutventure.com/wordpress/group-buying
Description: Authorize.net CIM Add-on.
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
*/

add_action( 'gb_register_processors', 'gb_load_cim' );

function gb_load_cim() {
	require_once 'groupBuyingAuthnetCIM.class.php';
}
