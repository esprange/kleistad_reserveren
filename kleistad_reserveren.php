<?php
/*
Plugin Name: Kleistad reserveren
Plugin URI: 
Description: Een plugin voor vereniging Kleistad. Reserveren van ovens, verwerken van stook saldo en cursus administratie
Version: 3.0
Author: Eric Sprangers
Author URI: 
License: GPL2
*/

require_once ( dirname( __FILE__ ) . '/class/kleistad.php' );
 
register_activation_hook( __FILE__, ['Kleistad','activate'] );

register_deactivation_hook( __FILE__, ['Kleistad','deactivate'] );

add_action('plugins_loaded', [ Kleistad::get_instance(), 'setup' ] );

//function kleistad_update_ovenkosten () {
//    $kleistad = new Kleistad();
//    $kleistad->update_ovenkosten();
//}
//
//$kleistad = new Kleistad();

