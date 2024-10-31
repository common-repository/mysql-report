<?php
/*
******************************************************************

Plugin Name:       MySQL Report
Plugin URI:        http://www.drupal001.com
Description:       Show mysql status in Wordpress admin page.
Author:            Robbin Zhao
Author URI:        http://www.drupal001.com
Version:           1.0

******************************************************************
*/

define('MY_REPORT_DIR', dirname(__FILE__));

function wp_mysqlreport_menu() {
  global $menu;
  // get the first available menu placement around 30, trivial, I know
  $menu_placement = 1000;
  for($i=30;$i<100;$i++){
    if(!isset($menu[$i])){ $menu_placement = $i; break; }
  }
  // http://codex.wordpress.org/Function_Reference/add_menu_page
  $list_page    = add_menu_page( 'MySQL Report', 'MySQL Report', 'manage_options', 'wp-mysqlreport', 'wp_mysqlreport', '', $menu_placement);
}

function wp_mysqlreport() {
  $report = new mysqlreport(new wp_mysqlreport_driver());
  echo $report->report();
}

if (is_admin()) {
  include_once MY_REPORT_DIR . '/mysqlreport.php';
 
  class wp_mysqlreport_driver implements mysqlreport_driver {
 
   function dbResults($sql) {
      global $wpdb;
      return $wpdb->get_results($sql);
    }
    
    function reportGuideUrl() {
      $mysql_guide = "mysqlreportguide.html";
      
      $url = plugin_dir_url(__FILE__);
      return $url . $mysql_guide;
    }
  }
  
  add_action( 'admin_menu', 'wp_mysqlreport_menu' );
}



