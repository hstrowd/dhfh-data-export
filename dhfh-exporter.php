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
    if(!is_plugin_active('generic-export/generic-export.php'))
      return array('generic_export_not_active');

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
    $this->prepare_newsletter_forms($sorted_rows['Newsletter Form']);

    return $sorted_rows;
  }

  public function prepare_donation_forms(&$donation_forms) {
    foreach($donation_forms as &$form_data) {
      $this->set_import_id($form_data, 'don');
      $this->set_key_indicator($form_data);
      $this->set_title($form_data);
      $this->parse_email_fields($form_data);
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

  public function prepare_volunteer_forms(&$volunteer_forms) {
    foreach($volunteer_forms as &$form_data) {
      $this->set_import_id($form_data, 'vol');
      $this->set_key_indicator($form_data);
      $this->set_title($form_data);
      $this->parse_email_fields($form_data);
      // Extract the parts of the address.
      $this->parse_address($form_data);
    }
  }

  public function prepare_newsletter_forms(&$newsletter_forms) {
    foreach($newsletter_forms as &$form_data) {
      $this->set_import_id($form_data, 'news');
      $this->set_key_indicator($form_data);
      $this->set_title($form_data);
      $this->parse_email_fields($form_data);
      $this->add_contact_category_attribute($form_data, 'ReStore Newsletter');
    }
  }


  public function set_import_id(&$form_data, $form_id, $record_id_key = 'Entries ID') {
    $record_id = $form_data[$record_id_key];
    $form_data['import_id'] = "wp" . $form_id . $record_id;
  }

  public function set_key_indicator(&$form_data) {
    $form_data['key_indicator'] = '';
  }

  public function set_title(&$form_data) {
    $form_data['title'] = 'Unknown';
  }

  public function parse_email_fields(&$form_data, $email_key = 'Email', $import_id_key = 'import_id') {
    $email_address = $form_data[$email_key];
    $import_id = $form_data['import_id'];
    $form_data['address_import_id'] = $import_id;
    $form_data['phone_address_import_id'] = $import_id;
    $form_data['phone_import_id'] = $import_id;
    $form_data['phone_type'] = 'Email';
  }

  public function add_contact_category_attribute(&$form_data, $attribute_value) {
    $form_data['attribute_import_id'] = $form_data['import_id'];
    $form_data['attribute_category'] = 'Contact Category';
    $form_data['attribute_description'] = $attribute_value;
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

    $results = array('saved' => array(), 'no_content' => array());

    // Use this prefix for all files exported as part of this execution.
    $filename_prefix = date("Y-m-d_H.i.s") . '-' . $content_to_export;

    $unrecognized_data = $re_content[UNRECOGNIZED_NAME];
    $save_result = $this->save_csv($unrecognized_data, $filename_prefix, UNRECOGNIZED_KEY);
    switch($save_result[0]) {
    case 'saved':
      $results['saved'][$form_name] = $save_result[1];
      break;
    case 'unable_to_create_output_file':
      $results['unable_to_create_output_file'] = TRUE;
      return $results;
      break;
    }

    foreach(self::$dhfh_forms as $keyword => $form_name) {
      $data = $re_content[$form_name];
      $save_result = $this->save_csv($data, $filename_prefix, $keyword);
      switch($save_result[0]) {
      case 'saved':
	$results['saved'][$form_name] = $save_result[1];
	break;
      case 'no_content_found':
	$results['no_content'][] = $form_name;
	break;
      case 'unable_to_create_output_file':
	$results['unable_to_create_output_file'] = TRUE;
	return $results;
	break;
      }
    }

    return $results;
  }

  // Returns false if an error occured that should prevent all remaining files from being saved.
  public function save_csv($csv_data, $filename_prefix, $keyword) {
    // If there is no data to save, skip this one, but let the rest continue. 
    if(isset($csv_data) && count($csv_data) > 0) {
      $filename = "$filename_prefix-$keyword.csv";
      $full_path = self::output_dir() . '/' . $filename;

      // Make the first row the headers.
      $headers = array_keys($csv_data[0]);
      array_unshift($csv_data, $headers);

      // Must create the file before ParseCSV can save into it.
      if($file_handle = fopen($full_path, 'w')) {
	fclose($file_handle);

	// Save the Form data.
	$parse_csv = new ParseCSV();
	$result = $parse_csv->save($full_path, $csv_data);

	return array('saved', $filename);
      } else {
	// If the file cannot be written to, skip this on and prevent the rest from continuing. 
	// Add notice here
	return array('unable_to_create_output_file');
      }
    // No need to notify the user if no content was found in the unrecognized set. 
    } else {
      return array('no_content_found');
    }
  }

  /**
   *  END: Write Results to Files
   */


  /**
   *  BEGIN: Delete Old Output Files
   */

  public function clean_output_files($filenames) {
    // Track the files that were deleted and those that could not be found.
    $files_deleted = array();
    $files_not_found = array();

    // Check if each file exists. If so, delete it. If not, mark it as unknown.
    foreach($filenames as $filename) {
      $full_path = self::output_dir() . '/' . $filename;
      if(file_exists($full_path)) {
	unlink($full_path);
	$files_deleted[] = $filename;
      } else
	$files_not_found[] = $filename;
    }

    return array('files_deleted' => $files_deleted, 'files_not_found' => $files_not_found);
  }

  /**
   *  END: Delete Old Output Files
   */
}
