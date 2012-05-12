<?php

// Include the plugin constants.
require_once( plugin_dir_path( __FILE__ ) . 'constants.php' );

// Encapsulates all Export Functionality for the DuPage Habitat For Humanity forms.
class DHFHExporter {

  /**
   *  BEGIN: Static Content
   */

  const UNRECOGNIZED_KEY = 'unrecognized';
  const UNRECOGNIZED_NAME = 'Unrecognized';

  public static $dhfh_forms = array( 'volunteer' => 'Volunteer Form', 
				     'newsletter' => 'Newsletter Form', 
				     'donation' => 'Donation Form');

  public static function output_dir() {
    return DHFH_DATA_EXPORT_DIR . '/exported_files';
  }

  /**
   *  END: Static Content
   */


  public function __construct() {
    // Ensure the export output directory exists.
    if(!is_dir(self::output_dir()) && !mkdir(self::output_dir())) {
      // Set notice to notify the user that the output directory could not be created.
      $this->unable_to_create_output_dir = true;
    }
  }


  /**
   *  BEGIN: Export content to array
   */

  // Extracts the data from the database in the form of an array of associative array.
  public function export_content($content_to_export, $mark_as_exported) {
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    if(!is_plugin_active('generic-export/generic-export.php')) {
      add_action('admin_notices', array( &$this, 'generic_export_not_active' ));
      return;
    }

    require_once( WP_PLUGIN_DIR . '/generic-export/generic-exporter.php' );
    $generic_exporter = new GenericExporter();
    $export_result = $generic_exporter->export_content('visual-form-builder', $content_to_export, $mark_as_exported);

    switch($export_result[0]) {
    case 'success':
      $filename = $export_result[1];
      $csv_content = $export_result[2];

      // Convert the CSV into an array of associative arrays.
      require_once( DHFH_DATA_EXPORT_DIR . '/lib/parsecsv-0.4.3-beta/parsecsv.lib.php' );
      $parse_csv = new ParseCSV();
      $parse_csv->parse($csv_content);
      $rows = $parse_csv->data;

      return array('success', $rows);
    default:
      return $export_result;
    }
  }

  /**
   *  END: Export content to array
   */


  /**
   *  BEGIN: Transform Form Data
   */

  // Organizes the raw data and transforms it as necessary, producing an array that groups the 
  // records as desired.
  public function transform($rows) {
    // Sort the rows based on the forms we know and care about.
    $sorted_rows = array(UNRECOGNIZED_NAME => array());
    foreach(self::$dhfh_forms as $prefix) {
      $sorted_rows[$prefix] = array();
    }

    foreach($rows as $row) {
      $sorted = false;
      foreach(self::$dhfh_forms as $prefix) {
	if(strrpos($row['Form'], $prefix) === 0) {
	  $sorted_rows[$prefix][] = $row;
	  $sorted = true;
	}
      }
      if(!$sorted) {
	$sorted_rows[UNRECOGNIZED_NAME][] = $row;
      }
    }

    $this->prepare_volunteer_forms($sorted_rows['Volunteer Form']);
    $this->prepare_donation_forms($sorted_rows['Donation Form']);
    // No transformation is needed for the Newsletter Form.

    return $sorted_rows;
  }

  public function prepare_donation_forms(&$donation_forms) {
    foreach($donation_forms as &$form_data) {
      // Extract the pickup options into separate columns.
      if(isset($form_data[21])) {
	$pickup_options = explode(',', $form_data[21]);
	foreach($pickup_options as $option) {
	  $form_data[trim($option)] = true;
	}
      }

      // Extract the parts of the address.
      $this->parse_address($form_data, 'Pickup Address');
    }
  }

  public function prepare_volunteer_forms(&$donation_forms) {
    foreach($donation_forms as &$form_data) {
      // Extract the parts of the address.
      $this->parse_address($form_data);
    }
  }

  // NOTE: This is very brittle and error prone, but given the data Visual Form Bulider provides, 
  // it will work to satisfy our immediate needs.
  // Visual form builder does not use any structure in storing their addresses. They just shove all
  // the values into a string, making it very hard to parse accurately because the fields are not 
  // validated well either. 
  public function parse_address(&$form_data, $address_key = 'Address') {
    $address_data = $form_data[$address_key];

    $address_rows = explode('<br>', $address_data);

    $address = '';
    $city = '';
    $state = '';
    $zip_code = '';
    $country = '';

    // Check if we have all rows provided.
    if(count($address_rows) == 4) {
      $address = str_replace('"', '', $address_rows[0]) . ', ' . str_replace('"', '', $address_rows[1]);

      $matches = array();
      if(preg_match('/([[:alnum:][:space:]]*), ([A-Z]{2,3}). ([0-9]{4,12})/', $address_rows[2], $matches) > 0) {
	$city = $matches[1];
	$state = $matches[2];
	$zip_code = $matches[3];
      } elseif(preg_match('/([[:alnum:][:space:]]*), ([A-Z]{2,3})./', $address_rows[2], $matches) > 0) {
	$city = $matches[1];
	$state = $matches[2];
      }

      $country = str_replace('"', '', $address_rows[3]);
    } elseif(count($address_rows) == 3) {
      // Assume the first row is the Address.
      $address = str_replace('"', '', $address_rows[0]) . ', ' . str_replace('"', '', $address_rows[1]);

      $matches = array();
      if(preg_match('/([[:alnum:][:space:]]*), ([A-Z]{2,3}). ([0-9]{4,12})/', $address_rows[1], $matches) > 0) {
	$city = $matches[1];
	$state = $matches[2];
	$zip_code = $matches[3];
      } elseif(preg_match('/([[:alnum:][:space:]]*), ([A-Z]{2,3})./', $address_rows[1], $matches) > 0) {
	$city = $matches[1];
	$state = $matches[2];
      }

      $country = str_replace('"', '', $address_rows[2]);
    } elseif(count($address_rows) == 2) {
      // Assume the street address was left out
      $matches = array();
      if(preg_match('/([[:alnum:][:space:]]*), ([A-Z]{2,3}). ([0-9]{4,12})/', $address_rows[0], $matches) > 0) {
	$city = $matches[1];
	$state = $matches[2];
	$zip_code = $matches[3];
      } elseif(preg_match('/([[:alnum:][:space:]]*), ([A-Z]{2,3})./', $address_rows[0], $matches) > 0) {
	$city = $matches[1];
	$state = $matches[2];
      }

      $country = str_replace('"', '', $address_rows[1]);
    } // No point in handling the case when only one row is given.

    $form_data['street_address'] = $address;
    $form_data['city'] = $city;
    $form_data['state'] = $state;
    $form_data['zip_code'] = $zip_code;
    $form_data['country'] = $country;
  }

  /**
   *  END: Transform Form Data
   */


  /**
   *  BEGIN: Write Results to Files
   */

  public function save_output($re_content, $content_to_export) {
    require_once( DHFH_DATA_EXPORT_DIR . '/lib/parsecsv-0.4.3-beta/parsecsv.lib.php' );

    $output_files = array();

    // Use this prefix for all files exported as part of this execution.
    $filename_prefix = date("Y-m-d_H.i.s") . '-' . $content_to_export;

    $unrecognized_data = $re_content[UNRECOGNIZED_NAME];
    if(($unrecognized_file = $this->save_csv($unrecognized_data, $filename_prefix, UNRECOGNIZED_KEY)) === false)
      return $output_files;
    elseif(isset($unrecognized_file))
      $output_files[UNRECOGNIZED_NAME] = $unrecognized_file;

    foreach(self::$dhfh_forms as $keyword => $form_name) {
      $data = $re_content[$form_name];
      if(($filename = $this->save_csv($data, $filename_prefix, $keyword)) === false)
	return $output_files;
      elseif(isset($filename))
	$output_files[$form_name] = $filename;
    }

    // Removes any forms that were not found in $dhfh_forms
    $output_files = array_filter($output_files);

    return $output_files;
  }

  // Returns false if an error occured that should prevent all remaining files from being saved.
  public function save_csv($csv_data, $filename_prefix, $keyword) {
    // If there is no data to save, skip this one, but let the rest continue. 
    if(isset($csv_data) && count($csv_data) > 0) {
      $filename = self::output_dir() . "/$filename_prefix-$keyword.csv";

      // Make the first row the headers.
      $headers = array_keys($csv_data[0]);
      array_unshift($csv_data, $headers);

      // Must create the file before ParseCSV can save into it.
      if($file_handle = fopen($filename, 'w')) {
	fclose($file_handle);

	// Save the Form data.
	$parse_csv = new ParseCSV();
	$result = $parse_csv->save($filename, $csv_data);

	return $filename;
      } else {
	// If the file cannot be written to, skip this on and prevent the rest from continuing. 
	// Add notice here
	add_action('admin_notices', array( &$this, 'unable_to_create_output_file' ));
	return false;
      }
    // No need to notify the user if no content was found in the unrecognized set. 
    } elseif($keyword != UNRECOGNIZED_KEY) {
      if(!isset($this->no_content_forms)) {
	add_action('admin_notices', array( &$this, 'no_content_to_be_output' ));
	$this->no_content_forms = array($keyword);
      } else {
	$this->no_content_forms[] = $keyword;
      }
      return NULL;
    }
    return NULL;
  }

  /**
   *  END: Write Results to Files
   */


  /**
   *  BEGIN: Delete Old Output Files
   */

  public function clean_output_files($filenames) {
    // Track the files that were deleted and those that could not be found.
    $this->files_deleted = array();
    $this->files_not_found = array();

    // Check if each file exists. If so, delete it. If not, mark it as unknown.
    foreach($filenames as $filename) {
      $full_path = self::output_dir() . '/' . $filename;
      if(file_exists($full_path)) {
	unlink($full_path);
	$this->files_deleted[] = $filename;
      } else
	$this->files_not_found[] = $filename;
    }

    if(count($this->files_deleted) > 0) {
      add_action('admin_notices', array( &$this, 'files_deleted' ));
    }

    if(count($this->files_not_found) > 0) {
      add_action('admin_notices', array( &$this, 'files_not_found' ));
    }
  }

  /**
   *  END: Delete Old Output Files
   */


  /**
   *  BEGIN: Admin Notices
   */

  public function generic_export_not_active() {
    echo "<div class=\"warning notice\">The generic export plugin is required to properly export content using this plugin. Please verify that it is installed and activated.</div>";
    remove_action('admin_notices', array( &$this, 'generic_export_not_active' ));
  }

  public function unable_to_create_output_file() {
    echo "<div class=\"warning notice\">Unable to create an output file to write the exported content to. Please verify that the web server has write access to the self::output_dir() directory. NOTE: The content was successfully exported from the database, so it has been marked as 'exported' if you requested that. You may need to manually transform the file stored by the Generic Exporter plugin.</div>";
    remove_action('admin_notices', array( &$this, 'unable_to_create_output_file' ));
  }

  public function no_content_to_be_output() {
    // Identify the names of the forms that had no content.
    $form_names = array();
    foreach($this->no_content_forms as $form_keyword) {
      $form_names[] = self::$dhfh_forms[$form_keyword];
    }

    // Removes any forms that were not found in $dhfh_forms
    $form_names = array_filter($form_names);
    
    echo "<div class=\"warning notice\">No content was found to be exported for the following forms: " . join(', ', $form_names) . ".</div>";
    remove_action('admin_notices', array( &$this, 'no_content_to_be_output' ));
  }

  public function files_deleted() {
    // Identify the files that were successfully deleted.
    $filenames = $this->files_deleted;
    
    echo "<div class=\"success notice\">The following files were successfully deleted from the output directory: <ul><li>" . join('</li><li>', $filenames) . "</li></ul></div>";
    remove_action('admin_notices', array( &$this, 'files_deleted' ));
  }

  public function files_not_found() {
    // Identify the files that were not able to be found.
    $filenames = $this->files_not_found;
    
    echo "<div class=\"error notice\">The following files could not be found in the output directory in order to delete them: <ul><li>" . join('</li><li>', $filenames) . "</li></ul></div>";
    remove_action('admin_notices', array( &$this, 'files_not_found' ));
  }

  /**
   *  END: Admin Notices
   */

}
