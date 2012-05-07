<?php


class DHFHExporter {
  public static $form_prefixes = array('Volunteer Form', 'Newsletter Form', 'Donation Form');

  public static function output_dir() {
    DHFH_DATA_EXPORT_DIR . '/exported_files';
  }

  public function __construct() {
    // Ensure the export output directory exists.
    if(!is_dir(self::output_dir()) && !mkdir(self::output_dir())) {
      // Set notice to notify the user that the backups directory could not be created.
      add_action('admin_notices', array( &$this, 'unable_to_create_output_dir' ));
    }
  }

  public function export_content($content_to_export, $mark_as_exported) {
    include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    if(!is_plugin_active('generic-export/generic-export.php')) {
      add_action('admin_notices', array( &$this, 'generic_export_not_active' ));
      return;
    }

    include_once( WP_PLUGIN_DIR . '/generic-export/generic-exporter.php' );

    $generic_exporter = new GenericExporter();
    $export_result = $generic_exporter->export_content('visual-form-builder', $content_to_export, $mark_as_exported, true);

    return $export_result;
  }

  public function transform($export_result) {
    require_once( trailingslashit( plugin_dir_path( __FILE__ ) ) . 
		  '/parsecsv-0.4.3-beta/parsecsv.lib.php' );

    // Convert the CSV into an array of arrays.
    $parse_csv = new ParseCSV();
    $parse_csv->parse($export_result[1]);
    $rows = $parse_csv->data;

    // Sort the rows based on the forms we know and care about.
    $sorted_rows = array('Unrecognized' => array());
    foreach(self::$form_prefixes as $prefix) {
      $sorted_rows[$prefix] = array();
    }

    foreach($rows as $row) {
      $sorted = false;
      foreach(self::$form_prefixes as $prefix) {
	if(strrpos($row['Form'], $prefix) === 0) {
	  $sorted_rows[$prefix][] = $row;
	  $sorted = true;
	}
      }
      if(!$sorted) {
	$sorted_rows['Unrecognized'][] = $row;
      }
    }

    //echo "Organized rows";
    //print_r($sorted_rows);

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
    require_once( trailingslashit( plugin_dir_path( __FILE__ ) ) . 
		  '/parsecsv-0.4.3-beta/parsecsv.lib.php' );

    // Use this prefix for all files exported as part of this execution.
    $filename_prefix = date("Y-m-d_H.i.s") . '-' . $content_to_export;

    $volunteer_data = $re_content['Volunteer Form'];
    $this->save_csv($volunteer_data, $filename_prefix, 'volunteer');

    $volunteer_data = $re_content['Donation Form'];
    $this->save_csv($volunteer_data, $filename_prefix, 'donation');

    $volunteer_data = $re_content['Newsletter Form'];
    $this->save_csv($volunteer_data, $filename_prefix, 'newsletter');

    $volunteer_data = $re_content['Unrecognized'];
    $this->save_csv($volunteer_data, $filename_prefix, 'unrecognized');
  }

  public function save_csv($csv_data, $filename_prefix, $keyword) {
    if(isset($csv_data) && count($csv_data) > 0) {
      $filename = self::output_dir() . "/$filename_prefix-$keyword.csv";
      $headers = array_keys($csv_data[0]);
      array_unshift($csv_data, $headers);
      // Must create the file before ParseCSV can save into it.
      if($file_handle = fopen($filename, 'w')) {
	fclose($file_handle);
	// Save the Volunteer Form data.
	$parse_csv = new ParseCSV();
	$result = $parse_csv->save($filename, $csv_data);
      } else {
	// Add notice here
	echo "unable to open file";
	return;
      }
    } else {
      // Add notice here
      echo "Not data to be written for $keyword";
      return;
    }
  }

  public function unable_to_create_output_dir() {
    echo "<div class=\"warning\">Unable to create the export output directory in the " . self::output_dir() . " directory. Please make sure that the web server has write access to this directory.</div>";
    remove_action('admin_notices', array( &$this, 'unable_to_create_output_dir' ));
  }

  public function generic_export_not_active() {
    echo "<div class=\"warning\">The generic export plugin is required to properly export content using this plugin. Please verify that it is installed and activated.</div>";
    remove_action('admin_notices', array( &$this, 'generic_export_not_active' ));
  }

}
