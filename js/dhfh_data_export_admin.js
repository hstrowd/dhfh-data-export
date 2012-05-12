function checkAll(field_name)
{
    fields = jQuery('input[name|="' + field_name + '"]');
    for (i = 0; i < fields.length; i++)
	fields[i].checked = true ;
}