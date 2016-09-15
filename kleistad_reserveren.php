<?php
/*
Plugin Name: Kleistad reserveren
Plugin URI: http://www.kleistad.nl/
Description: Een plugin voor het reserveren van de ovens
Version: 2.0
Author: Eric Sprangers
Author URI: http://www.sprako.nl/
License: GPL2
*/

require_once ( dirname( __FILE__ ) . '/class/kleistad.php' );
 
register_activation_hook( __FILE__, ['Kleistad','activate'] );

register_deactivation_hook( __FILE__, ['Kleistad','deactivate'] );

$kleistad = new Kleistad();

