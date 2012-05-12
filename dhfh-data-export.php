<?php
/*
Plugin Name: DHFH Data Export
Plugin URI: https://github.com/hstrowd/dhfh-data-export
Description: Exports and converts the data captured in the site's forms into separate CSV files that are able to be imported into the Dupage Habitat for Humanity's Raiser's Edge database.
Depends: Generic Export
Version: 0.1
Author: Harrison Strowd
Author URI: http://www.hstrowd.com/
License: GPL2
*/

/*  Copyright 2012  Harrison Strowd  (email : h.strowd@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Define plugin constants.
define("DHFH_DATA_EXPORT_DIR", WP_PLUGIN_DIR."/dhfh-data-export");
define("DHFH_DATA_EXPORT_URL", WP_PLUGIN_URL."/dhfh-data-export");

// Require internal files.
require_once( DHFH_DATA_EXPORT_DIR . '/dhfh-exporter.php' );


/**
 *  BEGIN: Handle user actions.
 */

if($_POST['action']) {
  switch ($_POST['action']) {
  case 'dhfh-export-content':
    $dhfh_export = new DHFHExporter();

    // Isolate Inputs
    $content_to_export = $_POST['content-to-export'];
    $mark_as_exported = $_POST['mark-as-exported'];

    // Produce CSV Output
    $csv_content = $dhfh_export->export_content($content_to_export, $mark_as_exported);
    if(isset($csv_content) && (count($csv_content) > 0)) {
      $re_content = $dhfh_export->transform($csv_content); 
      if(($output_filenames = $dhfh_export->save_output($re_content, $content_to_export)) &&
	 (count($output_filenames) > 0)) {
	// Notify the User.
	add_action('admin_notices', 'successfully_exported_content');
      }
    }
    break;
  case 'dhfh-delete-output-files':
    $dhfh_export = new DHFHExporter();
    
    $dhfh_export->clean_output_files($_POST['output-files-to-delete']);

    break;
  }
}

if($_GET['page'] == 'dhfh-data-export') {
  // Ensure the export output directory exists.
  if(!is_dir(DHFHExporter::output_dir()) && !mkdir(DHFHExporter::output_dir())) {
    // Set notice to notify the user that the output directory could not be created.
    add_action('admin_notices', 'unable_to_create_output_dir' );
  }
}

/**
 *  END: Handle user actions.
 */


/**
 *  BEGIN: Required WordPress Hooks
 */

if ( is_admin() ) {
  // Actions
  add_action( 'admin_menu', 'dhfh_data_export_menu' );
  add_action( 'admin_head', 'dhfh_data_export_styles' );
  add_action( 'admin_head', 'dhfh_data_export_scripts' );

  register_activation_hook( __FILE__, 'verify_dependencies' );

} else {
  // non-admin enqueues, actions, and filters
}

function dhfh_data_export_menu() {
  add_options_page( __('DHFH Data Export Options', 'dhfh data export'), 
		      __('DHFH Data Export', 'dhfh data exporter'),
		      'manage_options', 
		      'dhfh-data-export',
		      'dhfh_data_export_options',
		      '',
		      '' );
}

// Defines the content for the admin page.
function dhfh_data_export_options() {
  if ( !current_user_can( 'manage_options' ) )  {
    wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
  }

  // Build a list of all output files.
  $exported_files = array();
  if($dir_handle = opendir(DHFHExporter::output_dir())) {
    while (false !== ($output_filename = readdir($dir_handle))) {
      // Don't include directories.
      if(!is_dir(DHFHExporter::output_dir() . '/' . $output_filename)) {
        $exported_files[] = $output_filename;
      }
    }
  }

  // Defines the structure of the admin page.
  require_once(DHFH_DATA_EXPORT_DIR . '/pages/admin.php');
}

/* Basic CSS styling for the admin page. */
function dhfh_data_export_styles() {
  wp_enqueue_style( 'dhfh_data_export_admin_css', DHFH_DATA_EXPORT_URL .'/css/dhfh_data_export_admin.css');
}

function dhfh_data_export_scripts() {
  wp_enqueue_script( 'dhfh_data_export_admin_js', DHFH_DATA_EXPORT_URL .'/js/dhfh_data_export_admin.js', 
		     array('jquery') );
}

function verify_dependencies() {
  require_once( ABSPATH . '/wp-admin/includes/plugin.php' );

  if ( !is_plugin_active( 'generic-export/generic-export.php' ) ) {
    // deactivate dependent plugin
    deactivate_plugins( __FILE__);
    exit ('Requires Generic Export plugin to be installed and active.');
  }
}

/**
 *  END: Required WordPress Hooks
 */


/**
 *  BEGIN: Admin Notices
 */

function successfully_exported_content() {
  echo "<div class=\"success notice\">The requested content was successfully exported and links to the newly created files should appear in the Exported Files list below.</div>";
  remove_action('admin_notices', 'successfully_exported_content' );
}

function unable_to_create_output_dir() {
  echo "<div class=\"warning notice\">Unable to create the export output directory in the " . DHFHExporter::output_dir() . " directory. Please make sure that the web server has write access to this directory.</div>";
  remove_action('admin_notices', 'unable_to_create_output_dir' );
}

/**
 *  END: Admin Notices
 */

