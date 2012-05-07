<?php
/*
Plugin Name: DHFH Data Export
Plugin URI: https://github.com/hstrowd/dhfh-data-export
Description: Exports and converts the data captured in the site's forms into separate CSV files that are able to be imported into the Dupage Habitat for Humanity's Raiser's Edge database.
Version: 0.01
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

/* TODO List:
    - Separate out the admin support from the export functionality.
    - Add a readme.
    - List the dependency on generic exporter.
*/

define("DHFH_DATA_EXPORT_DIR", WP_PLUGIN_DIR."/dhfh-data-export");
define("DHFH_DATA_EXPORT_URL", WP_PLUGIN_URL."/dhfh-data-export");

require_once( DHFH_DATA_EXPORT_DIR . '/dhfh-exporter.php' );

// Handlers for user actions
if(($_POST['page'] == 'dhfh-data-export') && $_POST['action']) {
  $dhfh_export = new DHFHExporter();
  $raw_cvs_file = $_POST['raw-csv'];
  switch ($_POST['action']) {
  case 'dhfh-export-content':
    $content_to_export = $_POST['content-to-export'];
    $mark_as_exported = $_POST['mark-as-exported'];
    $csv_content = $dhfh_export->export_content($content_to_export, $mark_as_exported);
    $re_content = $dhfh_export->transform($csv_content); 
    $dhfh_export->save_output($re_content, $content_to_export);
    // Add a notice here.
    echo "SUCCESS. Please Reload.";
    break;
  // TODO: Adds support for transforming an existing file.
  case 'dhfh-transform-csv': 
    $csv_content = $dhfh_export->extract_content($raw_cvs_file);
    $re_content = $dhfh_export->transform($csv_content); 
    $dhfh_export->save_output($re_content, 'all');
    break;
  }
}

if($_GET['page'] == 'dhfh-data-export') {
  // Ensure the export output directory exists.
  if(!is_dir(DHFHExporter::output_dir()) && !mkdir(DHFHExporter::output_dir())) {
    // Set notice to notify the user that the backups directory could not be created.
    add_action('admin_notices', 'unable_to_create_output_dir' );
  }
}

/* Required WordPress Hooks -- BEGIN */

if ( is_admin() ){
  //Actions
  add_action( 'admin_menu', 'dhfh_data_export_menu' );
  add_action( 'admin_head', 'dhfh_data_export_styles' );
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

  // Build a list of all backup files.
  $exported_files= array();
  if($dir_handle = opendir(DHFHExporter::output_dir())) {
    while (false !== ($output_filename = readdir($dir_handle))) {
	// Don't include directories.
      if(!is_dir(DHFHExporter::output_dir() . '/' . $output_filename)) {
	  $exported_files[] = $output_filename;
	}
    }
  }

  // TODO: This is really ugly! Find another way of producing this content.
  ?>

  <div class="dhfh_data_export_admin">
  <div id="icon-options-general" class="icon32"><br></div>
  <h2>DHFH Data Export</h2>

  <?php
    if(count($exported_files) > 0) { 
   ?>
  <div class="export">
    <h3>Exported Files</h3>
    <p>The following files are available for downloading:</p>
    <ul>
    <?php
	foreach($exported_files as $output_filename) {
     ?>
      <li>
        <a href="<?php echo plugins_url( 'exported_files/' . $output_filename, __FILE__ ); ?>"><?php echo $output_filename; ?></a>
      </li>
    <?php
	}
     ?>
    </ul>
  </div>
  <?php
    }
   ?>

  <div class="export_actions">
    <h3>Export Actions</h3>
    <p>To export content to be imported into Raisers edge, please select from the options below:</p>

    <form id="dhfh-export-content" action method="post">
      <input name="action" type="hidden" value="dhfh-export-content">       
      <input type="hidden" name="_wp_http_referer" 
        value="/restore_dev/wp-admin/options-general.php?page=dhfh-data-export">

      <div id="content_to_export" class="export_option">
        <div class="label">Content to Export: </div>
        <div class="content_to_export_selection">
          <label for="export-non-exported">
            <input type="radio" name="content-to-export" value="non-exported" checked="true">
            <span>Records Not Previously Exported</span>
          </label>
          <label for="export-all">
            <input type="radio" name="content-to-export" value="all">
            <span>All Records</span>
          </label>
        </div>
      </div>

      <div id="mark_as_exported" class="export_option">
        <div class="label">Mark Content as Exported: </div>
        <div class="mark_as_exported_selection">
          <input type="checkbox" name="mark-as-exported" value="1" checked>
        </div>
      </div>

      <div>
        <input type="submit" value="Export" class="button" />
      </div>
    </form>
  </div>

  <?php
}

/* Basic CSS styling for the admin page. */
function dhfh_data_export_styles() {
  wp_enqueue_style( 'dhfh_data_export_admin_css', DHFH_DATA_EXPORT_URL .'/css/dhfh_data_export_admin.css');
}

/* Required WordPress Hooks -- END */

function unable_to_create_output_dir() {
  echo "<div class=\"warning\">Unable to create the export output directory in the " . DHFHExporter::output_dir() . " directory. Please make sure that the web server has write access to this directory.</div>";
  remove_action('admin_notices', 'unable_to_create_output_dir' );
}
