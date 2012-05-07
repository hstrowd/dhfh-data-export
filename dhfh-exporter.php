<?php
// Encapsulates all Export Functionality for the DuPage Habitat For Humanity forms.
class DHFHExporter {
  public static $dhfh_forms = array( 'volunteer' => 'Volunteer Form', 
				     'newsletter' => 'Newsletter Form', 
				     'donation' => 'Donation Form');

  public static function output_dir() {
    return DHFH_DATA_EXPORT_DIR . '/exported_files';
  }

  // Extracts the data from the database in the form of an array of associative array.
  public function export_content($content_to_export, $mark_as_exported) {
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    if(!is_plugin_active('generic-export/generic-export.php')) {
      add_action('admin_notices', array( &$this, 'generic_export_not_active' ));
      return;
    }

    require_once( WP_PLUGIN_DIR . '/generic-export/generic-exporter.php' );
    $generic_exporter = new GenericExporter();
    $export_result = $generic_exporter->export_content('visual-form-builder', $content_to_export, $mark_as_exported, true);

    $filename = $export_result[0];
    $csv_content = $export_result[1];

    // Convert the CSV into an array of associative arrays.
    require_once( DHFH_DATA_EXPORT_DIR . '/lib/parsecsv-0.4.3-beta/parsecsv.lib.php' );
    $parse_csv = new ParseCSV();
    $parse_csv->parse($csv_content);
    $rows = $parse_csv->data;

    return $rows;
  }

  // Organizes the raw data and transforms it as necessary, producing an array that groups the 
  // records as desired.
  public function transform($rows) {
    // Sort the rows based on the forms we know and care about.
    $sorted_rows = array('Unrecognized' => array());
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
	$sorted_rows['Unrecognized'][] = $row;
      }
    }

    $this->prepare_volunteer_forms($sorted_rows['Volunteer Form']);
    $this->prepare_donation_forms($sorted_rows['Donation Form']);
    // No transformation is needed for the Newsletter Form.

    return $sorted_rows;
  }

  /*
   * BEGIN: Individual Form Parsing Logic
   */

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

  /*
   * END: Individual Form Parsing Logic
   */

  public function save_output($re_content, $content_to_export) {
    require_once( DHFH_DATA_EXPORT_DIR . '/lib/parsecsv-0.4.3-beta/parsecsv.lib.php' );

    $output_files = array();

    // Use this prefix for all files exported as part of this execution.
    $filename_prefix = date("Y-m-d_H.i.s") . '-' . $content_to_export;

    $unrecognized_data = $re_content['Unrecognized'];
    if(($unrecognized_file = $this->save_csv($unrecognized_data, $filename_prefix, 'unrecognized')) !== false)
      $output_files[] = $unrecognized_file;
    else
      return $output_files;

    foreach(self::$dhfh_forms as $keyword => $form_name) {
      $data = $re_content[$form_name];
      if(($filename = $this->save_csv($data, $filename_prefix, $keyword)) !== false)
	$output_files[] = $filename;
      else
	return $output_files;
    }

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
    } else {
      if(!isset($this->no_content_forms)) {
	add_action('admin_notices', array( &$this, 'no_content_to_be_output' ));
	$this->no_content_forms = array();
      } else {
	$this->no_content_forms[] = $keyword;
      }
      return '';
    }
  }

  public function generic_export_not_active() {
    echo "<div class=\"warning\">The generic export plugin is required to properly export content using this plugin. Please verify that it is installed and activated.</div>";
    remove_action('admin_notices', array( &$this, 'generic_export_not_active' ));
  }

  public function unable_to_create_output_file() {
    echo "<div class=\"warning\">Unable to create an output file to write the exported content to. Please verify that the web server has write access to the self::output_dir() directory. NOTE: The content was successfully exported from the database, so it has been marked as 'exported' if you requested that. You may need to manually transform the file stored by the Generic Exporter plugin.</div>";
    remove_action('admin_notices', array( &$this, 'unable_to_create_output_file' ));
  }

  public function no_content_to_be_output() {
    // Identify the names of the forms that had no content.
    $form_name_lookup = function($keyword) {
      if($keyword == 'unrecognized')
	return 'Unrecognized';
      else
	return self::$dhfh_forms[$keyword];
    };
    $form_names = array_map($form_name_lookup, $this->no_content_forms);
    
    echo "<div class=\"warning\">No content was found to be exported for the following forms: " . join(', ', $form_names) . ".</div>";
    remove_action('admin_notices', array( &$this, 'no_content_to_be_output' ));
  }

}
