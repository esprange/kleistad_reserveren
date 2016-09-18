<?php
/*
Plugin Name: Kleistad reserveren
Plugin URI: 
Description: Een plugin voor het reserveren van de ovens en verwerken van de stook saldo
Version: 2.0
Author: Eric Sprangers
Author URI: 
License: GPL2
*/

require_once ( dirname( __FILE__ ) . '/class/kleistad.php' );
 
register_activation_hook( __FILE__, ['Kleistad','activate'] );

register_deactivation_hook( __FILE__, ['Kleistad','deactivate'] );

$kleistad = new Kleistad();

