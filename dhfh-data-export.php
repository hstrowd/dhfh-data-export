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

if(true) {
  echo "<br/><br/>";
}

$dhfh_export = new DHFHExport();

// Handlers for user actions
if($_POST['action']); {
  $raw_cvs_file = $_POST['raw-csv'];
  switch ($_POST['action']) {
  case 'dhfh-export-content':
    $content_to_export = $_POST['content-to-export'];
    $mark_as_exported = $_POST['mark-as-exported'];
    $csv_content = $dhfh_export->export_content($content_to_export, $mark_as_exported);
    $re_content = $dhfh_export->transform($csv_content); 
    $dhfh_export->save_output($re_content);
    break;
  // TODO: Adds support for transforming an existing file.
  case 'dhfh-transform-csv': 
    $csv_content = $dhfh_export->extract_content($raw_cvs_file);
    $re_content = $dhfh_export->transform($csv_content); 
    $dhfh_export->save_output($re_content);
    break;
  }
}


class DHFHExport {

  /* Required WordPress Hooks -- BEGIN */

  public function __construct() {
    if ( is_admin() ){
      //Actions
      add_action( 'admin_menu', array( &$this, 'dhfh_data_export_menu' ) );
      add_action( 'admin_head', array( &$this, 'dhfh_data_export_styles' ) );
    } else {
      // non-admin enqueues, actions, and filters
    }

    // Ensure the export backup directory exists.
    // TODO: Make this a constant.
    $this->output_dir = plugin_dir_path( __FILE__ ) . 'exported_files';
    if(!is_dir($this->output_dir) && !mkdir($this->output_dir)) {
      // Set notice to notify the user that the backups directory could not be created.
      add_action('admin_notices', array( &$this, 'unable_to_create_output_dir' ));
    }
  }

  public function dhfh_data_export_menu() {
    add_options_page( __('DHFH Data Export Options', 'dhfh data export'), 
		      __('DHFH Data Export', 'dhfh data exporter'),
		      'manage_options', 
		      'dhfh-data-export',
		      array( &$this, 'dhfh_data_export_options' ),
		      '',
		      '' );
  }

  /* Required WordPress Hooks -- END */

  // Defines the content for the admin page.
  public function dhfh_data_export_options() {
    if ( !current_user_can( 'manage_options' ) )  {
      wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    // Build a list of all backup files.
    $exported_files= array();
    if($dir_handle = opendir($this->output_dir)) {
      while (false !== ($output_filename = readdir($dir_handle))) {
	// Don't include directories.
        if(!is_dir($this->output_dir . '/' . $output_filename)) {
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
          <a href="<?php echo $this->output_dir . '/' . $backup_filename; ?>"><?php echo $output_filename; ?></a>
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
    // TODO: This is ugly! find a better way to isolate this and pull it into the admin page.
    ?>
    <style type="text/css">
      .error, .warning {
        max-width: 600px;
        margin: 10px 0 20px 0;
        padding: 6px 10px;
        border: 1px solid #D8D8D8;
        -webkit-border-radius: 4px
        -moz-border-radius: 4px;
        border-radius: 4px;
        font-size: 11px;
      }
      .error {
        border-color: #EED3D7;
        background-color: #F2DEDE;
      }
      .warning {
        background-color: #FFFFCC;
      }

      .dhfh_data_export_admin h3 {
        margin-top: 35px;
      }
      .dhfh_data_export_admin .label {
        margin-right: 10px;
        float: left;
        vertical-align: middle;
        font-weight: bold;
      }

      .export_option {
        margin: 10px;
      }
      .export .button {
        margin: 5px 0 0 20px;
      }

      .clear {
        clear:both;
      }
    </style>
    <?php
  }

  public function export_content($content_to_export, $mark_as_exported) {
    include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    if(!is_plugin_active('generic-export/generic-export.php')) {
      add_action('admin_notices', array( &$this, 'generic_export_not_active' ));
      return;
    }

    include_once( WP_PLUGIN_DIR . '/generic-export/generic-exporter.php' );

    $generic_exporter = new GenericExporter();
    $result = $generic_exporter->export_content('visual-form-builder', $content_to_export, $mark_as_exported, true);

    return $result;
  }

  public function transform($csv_content) {
    echo "Transform content. ";
  }

  public function save_output($re_content) {
    echo "Save resulting content. ";
  }

  public function unable_to_create_output_dir() {
    echo "<div class=\"warning\">Unable to create the export output directory in the " . $this->output_dir . " directory. Please make sure that the web server has write access to this directory.</div>";
    remove_action('admin_notices', array( &$this, 'unable_to_create_output_dir' ));
  }

  public function generic_export_not_active() {
    echo "<div class=\"warning\">The generic export plugin is required to properly export content using this plugin. Please verify that it is installed and activated.</div>";
    remove_action('admin_notices', array( &$this, 'generic_export_not_active' ));
  }

}