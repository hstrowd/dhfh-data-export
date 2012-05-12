<div class="dhfh_data_export_admin">
<div id="icon-options-general" class="icon32"><br></div>
<h2>DHFH Data Export</h2>

<div class="export_actions">
  <h3>Export Actions</h3>
  <p>To export content to be imported into Raisers edge, please select from the options below:</p>

  <form id="dhfh-export-content" method="post">
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
  if(count($exported_files) > 0) { 
 ?>
<div class="exported_files">
  <h3>Exported Files</h3>
  <p>The following files are available for downloading:</p>
  <span class="warning">To clean up these files, check the checkbox next to the associated files and click the Delete button below.</span>
  <form id="dhfh-delete-output-files" method="post">
    <input name="action" type="hidden" value="dhfh-delete-output-files">
    <input type="hidden" name="_wp_http_referer" 
      value="/restore_dev/wp-admin/options-general.php?page=dhfh-data-export">

    <div class="output_files">
      <ul>
      <?php
        foreach(array_reverse($exported_files) as $output_filename) {
       ?>
        <li>
          <input type="checkbox" name="output-files-to-delete[]" value="<?php echo $output_filename; ?>">
          <a href="<?php echo DHFH_DATA_EXPORT_URL . '/exported_files/' . $output_filename; ?>">
            <?php echo $output_filename; ?>
          </a>
        </li>
      <?php
    	}
       ?>
      </ul>
    </div>

    <div>
      <input type="submit" value="Delete" class="button" />
    </div>
  </form>
</div>
<?php
  }
 ?>
