<?php


///////////////////////////////////////////////////////////////////////
//  Function: custom_attribute_add (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = custom_attribute_add('host=test');
//
//  Exit codes:
//    0  :: No error
//    1  :: Help text printed - Insufficient or invalid input received
//    4  :: SQL Query failed
//
//
//  History:
//
//
///////////////////////////////////////////////////////////////////////
function custom_attribute_add($options="") {

    // The important globals
    global $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if ( (!isset($options['subnet']) and !isset($options['host']) and !isset($options['vlan'])) or
       (!isset($options['type']) and
        !isset($options['value']))) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,
<<<EOM

custom_attribute_add-v{$version}
Adds the custom attribute to the host or subnet specified

  Synopsis: custom_attribute_add

  Required:
    host=NAME[.DOMAIN]|IP     hostname or IP of the host
    OR
    subnet=NAME|IP            name or IP of the subnet
    OR
    vlan=NAME                 name of the VLAN

    type=ID|STRING            the name or ID of the attribute type
    value="STRING"            the value of the attribute

\n
EOM

        ));
    }

    // Check permissions
    if (!auth('custom_attribute_add')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(5, $self['error']));
    }

    // If they provided a hostname / ID let's look it up
    if (isset($options['host'])) {
        list($status, $rows, $host) = ona_find_host($options['host']);
        $table_name_ref = 'hosts';
        $table_id_ref = $host['id'];
        $desc = 'Host '. $host['fqdn'];
    }

    // If they provided a subnet name or ip
    else if (isset($options['subnet'])) {
        list($status, $rows, $subnet) = ona_find_subnet($options['subnet']);
        $table_name_ref = 'subnets';
        $table_id_ref = $subnet['id'];
        $desc = 'Subnet '. $subnet['name'];
    }

    // If they provided a vlan name
    else if (isset($options['vlan'])) {
        list($status, $rows, $vlan) = ona_find_vlan($options['vlan']);
        $table_name_ref = 'vlans';
        $table_id_ref = $vlan['id'];
        $desc = 'VLAN '. $vlan['name'];
    }

    // If we didn't get a record then exit
    if (!isset($host['id']) and !isset($subnet['id']) and !isset($vlan['id'])) {
        $self['error'] = "No host, subnet or vlan found!";
        printmsg($self['error'], 'error');
        return(array(4, $self['error']));
    }

    // determine how we are searching for the type
    $typesearch = 'name';
    if (is_numeric($options['type'])) {
        $typesearch = 'id';
    }

    // find the attribute type
    list($status, $rows, $catype) = ona_get_custom_attribute_type_record(array($typesearch => $options['type']));
    if (!$rows) {
        $self['error'] = "Unable to find custom attribute type: {$options['type']}";
        printmsg($self['error'], 'error');
        return(array(5, $self['error']));
    }


    // check for existing attributes like this
    list($status, $rows, $record) = ona_get_custom_attribute_record(array('table_name_ref' => $table_name_ref, 'table_id_ref' => $table_id_ref, 'custom_attribute_type_id' => $catype['id']));
    if ($rows) {
        $self['error'] = "The type '{$catype['name']}' is already in use on {$desc}";
        printmsg($self['error'], 'error');
        return(array(6, $self['error']));
    }

    if (!$catype['failed_rule_text']) $catype['failed_rule_text'] = "Not specified.";

    // validate the inpute value against the field_validation_rule.
    if ($catype['field_validation_rule'] and !preg_match($catype['field_validation_rule'], $options['value'])) {
        $self['error'] = "The value: '{$options['value']}', does not match field validation rule: {$catype['field_validation_rule']} Reason: {$catype['failed_rule_text']}";
        printmsg($self['error'], 'error');
        return(array(7, $self['error']));
    }

    // There is an issue with escaping '=' and '&'.  We need to avoid adding escape characters
    $options['value'] = str_replace('\\=','=',trim($options['value']));
    $options['value'] = str_replace('\\&','&',$options['value']);

    // add it
    list($status, $rows) = db_insert_record(
        $onadb,
        'custom_attributes',
        array(
            'table_name_ref'       => $table_name_ref,
            'table_id_ref'         => $table_id_ref,
            'custom_attribute_type_id' => $catype['id'],
            'value'                => $options['value']
        )
    );
    if ($status or !$rows) {
        $self['error'] = "SQL Query failed: " . $self['error'];
        printmsg($self['error'], 'error');
        return(array(8, $self['error']));
    }

    $text = "Custom Attribute {$catype['name']} ({$options['value']}) ADDED to: {$desc}";
    printmsg($text, 'notice');

    // Return the message file
    return(array(0, $text));

}






///////////////////////////////////////////////////////////////////////
//  Function: custom_attribute_del (string $options='')
//
//  $options = key=value pairs of options for this function.
//             multiple sets of key=value pairs should be separated
//             by an "&" symbol.
//
//  Input Options:
//    name=ID
//
//  Output:
//    Deletes an custom_attribute from the database.
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = custom_attribute_del('name=1223543');
///////////////////////////////////////////////////////////////////////
function custom_attribute_del($options="") {

    // The important globals
    global $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if ((!isset($options['subnet']) and !isset($options['host']) and !isset($options['vlan'])) or (!isset($options['type']) )) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }

    // Check permissions
    if (!auth('custom_attribute_del')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(5, $self['error']));
    }

    // If they provided a hostname / ID let's look it up
    if (isset($options['host'])) {
        list($status, $rows, $host) = ona_find_host($options['host']);
        $table_name_ref = 'hosts';
        $table_id_ref = $host['id'];
        $desc = 'Host '. $host['fqdn'];
    }

    // If they provided a subnet name or ip
    else if (isset($options['subnet'])) {
        list($status, $rows, $subnet) = ona_find_subnet($options['subnet']);
        $table_name_ref = 'subnets';
        $table_id_ref = $subnet['id'];
        $desc = 'Subnet '. $subnet['name'];
    }

    // If they provided a vlan name
    else if (isset($options['vlan'])) {
        list($status, $rows, $vlan) = ona_find_vlan($options['vlan']);
        $table_name_ref = 'vlans';
        $table_id_ref = $vlan['id'];
        $desc = 'VLAN '. $vlan['name'];
    }

    // If we didn't get a record then exit
    if (!isset($host['id']) and !isset($subnet['id']) and !isset($vlan['id'])) {
        $self['error'] = "No host, subnet or vlan found!";
        printmsg($self['error'], 'notice');
        return(array(1, $self['error']));
    }

    // If the type provided is numeric
    if (is_numeric($options['type'])) {

        // See if it's valid
        list($status, $rows, $catype) = ona_get_custom_attribute_type_record(array('id' => $options['type']));

        if (!$catype['id']) {
            $self['error'] = "Unable to find custom attribute type using the ID {$options['name']}!";
            printmsg($self['error'], 'notice');
            return(array(2, $self['error']));
        }
    }
    else {
        $options['type'] = trim($options['type']);
        list($status, $rows, $catype) = ona_get_custom_attribute_type_record(array('name' => $options['type']));
        if (!$catype['id']) {
            $self['error'] = "Unable to find custom attribute type using the name {$options['type']}!";
            printmsg($self['error'], 'notice');
            return(array(3, $self['error']));
        }
    }

    list($status, $rows, $record) = ona_get_custom_attribute_record(array('table_name_ref' => $table_name_ref, 'table_id_ref' => $table_id_ref, 'custom_attribute_type_id' => $catype['id']));
    if (!$rows) {
        $self['error'] = "Unable to find custom attribute type using the name {$catype['name']}!";
        printmsg($self['error'], 'notice');
        return(array(4, $self['error']));
    }

    list($status, $rows) = db_delete_records($onadb, 'custom_attributes', array('id' => $record['id']));
    if ($status or !$rows) {
        $self['error'] = "SQL Query failed: " . $self['error'];
        printmsg($self['error'], 'error');
        return(array(6, $self['error']));
    }

    // Return the success notice
    $self['error'] = "Custom Attribute {$record['name']} ({$record['value']}) DELETED from: {$desc}";
    printmsg($self['error'],'notice');
    return(array(0, $self['error']));


}











///////////////////////////////////////////////////////////////////////
//  Function: custom_attribute_modify (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = custom_attribute_modify('host=test');
//
//  Exit codes:
//    0  :: No error
//    1  :: Help text printed - Insufficient or invalid input received
//    4  :: SQL Query failed
//
//
//  History:
//
//
///////////////////////////////////////////////////////////////////////
function custom_attribute_modify($options="") {

    // The important globals
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if ((!isset($options['subnet']) and !isset($options['host']) and !isset($options['vlan'])) or (!isset($options['type']) and !isset($options['set_value']) )) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }

    // Check permissions
    if (!auth('custom_attribute_modify')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(5, $self['error']));
    }

    // If they provided a hostname / ID let's look it up
    if (isset($options['host'])) {
        list($status, $rows, $host) = ona_find_host($options['host']);
        $table_name_ref = 'hosts';
        $table_id_ref = $host['id'];
        $desc = 'Host '. $host['fqdn'];
    }

    // If they provided a subnet name or ip
    else if (isset($options['subnet'])) {
        list($status, $rows, $subnet) = ona_find_subnet($options['subnet']);
        $table_name_ref = 'subnets';
        $table_id_ref = $subnet['id'];
        $desc = 'Subnet '. $subnet['name'];
    }

    // If they provided a vlan name
    else if (isset($options['vlan'])) {
        list($status, $rows, $vlan) = ona_find_vlan($options['vlan']);
        $table_name_ref = 'vlans';
        $table_id_ref = $vlan['id'];
        $desc = 'VLAN '. $vlan['name'];
    }

    // If we didn't get a record then exit
    if (!isset($host['id']) and !isset($subnet['id']) and !isset($vlan['id'])) {
        $self['error'] = "No host, subnet or vlan found!";
        printmsg($self['error'], 'notice');
        return(array(1, $self['error']));
    }

    // If the type provided is numeric
    if (is_numeric($options['type'])) {

        // See if it's valid
        list($status, $rows, $catype) = ona_get_custom_attribute_type_record(array('id' => $options['type']));

        if (!$catype['id']) {
            $self['error'] = "Unable to find custom attribute type using the ID {$options['name']}!";
            printmsg($self['error'], 'notice');
            return(array(2, $self['error']));
        }
    }
    else {
        $options['type'] = trim($options['type']);
        list($status, $rows, $catype) = ona_get_custom_attribute_type_record(array('name' => $options['type']));
        if (!$catype['id']) {
            $self['error'] = "Unable to find custom attribute type using the name {$options['type']}!";
            printmsg($self['error'], 'notice');
            return(array(3, $self['error']));
        }
    }

    list($status, $rows, $record) = ona_get_custom_attribute_record(array('table_name_ref' => $table_name_ref, 'table_id_ref' => $table_id_ref, 'custom_attribute_type_id' => $catype['id']));
    if (!$rows) {
        $self['error'] = "Unable to find custom attribute type using the name {$catype['name']}!";
        printmsg($self['error'], 'notice');
        return(array(4, $self['error']));
    }




    // This variable will contain the updated info we'll insert into the DB
    $SET = array();

    // default to whatever was in the record you are editing
    $SET['value'] = $record['value'];

    if (array_key_exists('set_value', $options)) {
        // There is an issue with escaping '=' and '&'.  We need to avoid adding escape characters
        $options['set_value'] = str_replace('\\=','=',$options['set_value']);
        $options['set_value'] = str_replace('\\&','&',$options['set_value']);

        // trim leading and trailing whitespace from 'value'
        $SET['value'] = $valinfo = trim($options['set_value']);
    }

    if (!$catype['failed_rule_text']) $catype['failed_rule_text'] = "Not specified.";

    // validate the inpute value against the field_validation_rule.
    if ($catype['field_validation_rule'] and !preg_match($catype['field_validation_rule'], $SET['value'])) {
        $self['error'] = "The value: '{$SET['value']}', does not match field validation rule: {$catype['field_validation_rule']} Reason: {$catype['failed_rule_text']}";
        printmsg($self['error'], 'error');
        return(array(4, $self['error']));
    }

    // if the value has not changed, skip it
    if ($SET['value'] == $record['value']) { unset($SET['value']); $valinfo = "Value Not Changed";}


    $msg = "Updated Custom Attribute type: {$catype['name']} => '{$valinfo}'.";

    // If nothing at all changed up to this point, bail out
    if (!$SET) {
        $self['error'] = "You didn't change anything. Make sure you have a new value.";
        printmsg($self['error'], 'error');
        return(array(6, $self['error']));
    }

    // Update the record
    list($status, $rows) = db_update_record($onadb, 'custom_attributes', array('id' => $record['id']), $SET);
    if ($status or !$rows) {
        $self['error'] = "SQL Query failed: " . $self['error'];
        printmsg($self['error'], 'error');
        return(array(7, $self['error']));
    }


    // Return the success notice
    $self['error'] = $msg;
    printmsg($self['error'], 'notice');
    return(array(0, $self['error']));

}







///////////////////////////////////////////////////////////////////////
//  Function: custom_attribute_display (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = custom_attribute_modify('host=test');
//
//  Exit codes:
//    0  :: No error
//    1  :: Help text printed - Insufficient or invalid input received
//
//
//  History:
//
//
///////////////////////////////////////////////////////////////////////
function custom_attribute_display($options="") {

    // The important globals
    global $self, $onadb;

    $text_array = array();

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if ((!isset($options['host']) and !isset($options['id']) and !isset($options['subnet']) and !isset($options['vlan']))) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }


    // if a type was set, check if it is associated with the host or subnet and return 1 or 0
    if (isset($options['type'])) {
        $field = (is_numeric($options['type'])) ? 'id' : 'name';
        list($status, $rows, $catype) = ona_get_custom_attribute_type_record(array($field => $options['type']));
        // error if we cant find the type specified
        if (!$catype['id']) {
            $self['error'] = "The custom attribute type specified, {$options['type']}, does not exist!";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }

        $where['custom_attribute_type_id'] = $catype['id'];
    }

    // Search for the host first
    if (isset($options['host'])) {
        list($status, $rows, $host) = ona_find_host($options['host']);

        // Error if the host doesn't exist
        if (!$host['id']) {
            $self['error'] = "The host specified, {$options['host']}, does not exist!";
            printmsg($self['error'], 'error');
            return(array(2, $self['error']));
        } else {
            $where['table_id_ref'] = $host['id'];
            $where['table_name_ref'] = 'hosts';
            list($status, $rows, $cas) = db_get_records($onadb,'custom_attributes', $where );
        }

        //$anchor = 'host';
        //$desc = $host['fqdn'];

    }

    // Search for subnet
    if (isset($options['subnet'])) {
        list($status, $rows, $subnet) = ona_find_subnet($options['subnet']);

        // Error if the record doesn't exist
        if (!$subnet['id']) {
            $self['error'] = "The subnet specified, {$options['subnet']}, does not exist!";
            printmsg($self['error'], 'error');
            return(array(3, $self['error']));
        } else {
            $where['table_id_ref'] = $subnet['id'];
            $where['table_name_ref'] = 'subnets';
            list($status, $rows, $cas) = db_get_records($onadb,'custom_attributes', $where );
        }

        //$anchor = 'subnet';
        //$desc = $subnet['description'];

    }

    // Search for vlan
    if (isset($options['vlan'])) {
        list($status, $rows, $vlan) = ona_find_vlan($options['vlan']);

        // Error if the record doesn't exist
        if (!$vlan['id']) {
            $self['error'] = "The VLAN specified, {$options['vlan']}, does not exist!";
            printmsg($self['error'], 'error');
            return(array(3, $self['error']));
        } else {
            $where['table_id_ref'] = $vlan['id'];
            $where['table_name_ref'] = 'vlans';
            list($status, $rows, $cas) = db_get_records($onadb,'custom_attributes', $where );
        }

        //$anchor = 'vlan';
        //$desc = $vlan['description'];

    }


    // display details about specific type
    if (isset($options['type']) and isset($cas[0])) {
        list($status, $rows, $ca) = ona_get_custom_attribute_record(array('id' => $cas[0]['id']));
        if (!$ca['id']) {
            $self['error'] = "The custom attribute specified, {$options['id']}, is invalid!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }

	      $text_array['custom_attributes'] = $ca;
    } else {
        // Display all custom attributes and their values for this object
        $i = 0;
        do {
            list($status, $carows, $ca) = ona_get_custom_attribute_type_record(array('id' => $cas[$i]['custom_attribute_type_id']));
            $text_array['custom_attributes'][$ca['name']]=$cas[$i]['value'];
            $i++;
        } while ($i < $rows);
    }

    // display a count of CA records we found
    $text_array['custom_attribute_count'] = $rows;

    // Return the success notice
    return(array(0, $text_array));

}





///////////////////////////////////////////////////////////////////////
//  Function: custom_attribute_type_display (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = custom_attribute_modify('host=test');
//
//  Exit codes:
//    0  :: No error
//    1  :: Help text printed - Insufficient or invalid input received
//
//
//  History:
//
//
///////////////////////////////////////////////////////////////////////
function custom_attribute_type_display($options="") {

    // The important globals
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (!isset($options['name'])) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }

    // Now find the ID or NAME of the record
    if (isset($options['name'])) {
        $field = (is_numeric($options['name'])) ? 'id' : 'name';
        list($status, $rows, $catype) = ona_get_custom_attribute_type_record(array($field => $options['name']));
        if (!$catype['id']) {
            $self['error'] = "The custom attribute type specified, {$options['name']}, is invalid!";
            printmsg($self['error'], 'error');
            return(array(2, $self['error']));
        }
    }

    // Return the success notice
    return(array(0, $text));
}
