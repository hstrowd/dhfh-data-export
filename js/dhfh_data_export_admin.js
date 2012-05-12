function checkAll(field_name)
{
    fields = jQuery('input[name|="output-files-to-delete[]"]');
    for (i = 0; i < fields.length; i++)
	fields[i].checked = true ;
}