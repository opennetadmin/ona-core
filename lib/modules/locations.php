<?php

///////////////////////////////////////////////////////////////////////
//  Function: location_add (string $options='')
//
//  $options = key=value pairs of options for this function.
//             multiple sets of key=value pairs should be separated
//             by an "&" symbol.
//
//  Input Options:
//    reference=STRING
//
//  Output:
//    Adds a location into the database called 'name'
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = location_add('name=blah');
///////////////////////////////////////////////////////////////////////
function location_add($options="") {

    // The important globals
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (!(isset($options['reference']) and isset($options['name'])) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,
<<<EOM

location_add-v{$version}
Adds a location into the database

  Synopsis: location_add [KEY=VALUE] ...

  Required:
    reference=STRING       reference for identifying and searching for location
    name=STRING            location descriptive name

  Optional:
    address=STRING
    city=STRING
    state=STRING
    zip_code=NUMBER
    latitude=STRING
    longitude=STRING
    misc=STRING

\n
EOM

        ));
    }


    // Check permissions
    if (!auth('location_add')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(10, $self['error']));
    }

    // The formatting rule on location reference is all upper and trim it
    $options['reference'] = strtoupper(trim($options['reference']));

    if (!isset($options['zip_code'])) { $options['zip_code'] = 0; }
    if (!isset($options['address']))
      $options['address'] = '';
    if (!isset($options['city']))
      $options['city'] = '';
    if (!isset($options['state']))
      $options['state'] = '';
    if (!isset($options['latitude']))
      $options['latitude'] = '';
    if (!isset($options['longitude']))
      $options['longitude'] = '';
    if (!isset($options['misc']))
      $options['misc'] = '';

    // check to see if the campus already exists
    list($status, $rows, $loc) = ona_get_location_record(array('reference' => $options['reference']));

    if ($status or $rows) {
        $self['error'] = "The location {$options['reference']} already exists!";
        printmsg($self['error'],'error');
        return(array(3, $self['error']));
    }

    // Get the next ID for the new location
    $id = ona_get_next_id('locations');
    if (!$id) {
        $self['error'] = "The ona_get_next_id() call failed!";
        printmsg($self['error'], 'error');
        return(array(5, $self['error']));
    }
    printmsg("ID for new location: $id", 'debug');

    // Add the record
    list($status, $rows) =
        db_insert_record(
            $onadb,
            'locations',
            array(
                'id'                  => $id,
                'reference'           => $options['reference'],
                'name'                => $options['name'],
                'address'             => $options['address'],
                'city'                => $options['city'],
                'state'               => $options['state'],
                'zip_code'            => $options['zip_code'],
                'latitude'            => $options['latitude'],
                'longitude'           => $options['longitude'],
                'misc'                => $options['misc']
            )
        );
    if ($status or !$rows) {
        $self['error'] = "location_add() SQL Query failed: " . $self['error'];
        printmsg($self['error'],'error');
        return(array(6, $self['error']));
    }


    // Return the success notice
    $result['status_msg'] = 'Location added.';
    $result['module_version'] = $version;
    list($status, $rows, $result['locations'][0]) = ona_get_location_record(array('id' => $id));

    ksort($result['locations'][0]);

    printmsg("Location ADDED: {$options['reference']}: {$options['name']}", 'notice');

    // Return the success notice
    return(array(0, $result));
}












///////////////////////////////////////////////////////////////////////
//  Function: location_del (string $options='')
//
//  $options = key=value pairs of options for this function.
//             multiple sets of key=value pairs should be separated
//             by an "&" symbol.
//
//  Input Options:
//    name=ID
//
//  Output:
//    Deletes a location from the database.
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = location_del('ref=1223543');
///////////////////////////////////////////////////////////////////////
function location_del($options="") {

    // The important globals
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (isset($options['help']) or !isset($options['reference']) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }

    // Check permissions
    if (!auth('location_del')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(10, $self['error']));
    }


    // Sanitize options[commit] (default is no)
    $options['commit'] = sanitize_YN($options['commit'], 'N');


    // Find the Location to use
    list($status, $rows, $loc) = ona_find_location($options['reference']);
    if ($status or !$rows) {
        $self['error'] = "The location specified, {$options['reference']}, does not exist!";
        printmsg($self['error'], 'error');
        return(array(2, $self['error']));
    }
    printmsg("Location selected: {$loc['reference']}, location name: {$loc['name']}", 'debug');


    list($status, $rows, $usage) = db_get_records($onadb, 'devices', array('location_id' => $loc['id']), '' ,0);
    if ($rows != 0) {
        $self['error'] = "The location ({$loc['reference']}) is in use by {$rows} devices(s)!";
        printmsg($self['error'], 'error');
        return(array(6, $self['error']));
    }

    list($status, $rows) = db_delete_records($onadb, 'locations', array('id' => $loc['id']));
    if ($status or !$rows) {
        $self['error'] = "SQL Query failed: " . $self['error'];
        printmsg($self['error'], 'error');
        return(array(4, $self['error']));
    }

    // Return the success notice
    $self['error'] = "Location DELETED: {$loc['reference']} ({$loc['name']})";
    printmsg($self['error'], 'notice');
    return(array(0, $self['error']));

}









///////////////////////////////////////////////////////////////////////
//  Function: location_modify (string $options='')
//
//  $options = key=value pairs of options for this function.
//             multiple sets of key=value pairs should be separated
//             by an "&" symbol.
//
//  Input Options:
//    reference=STRING or ID           location reference or ID
//    set_name=STRING                  change location name
//
//  Output:
//    Updates a location record in the IP database.
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = location_modify('reference=23452&name=blah');
///////////////////////////////////////////////////////////////////////
function location_modify($options="") {

    // The important globals
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (isset($options['help']) or (!isset($options['reference'])) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,
<<<EOM

location_modify-v{$version}
Modifies an existing location entry in the database

  Synopsis: location_modify [KEY=VALUE] ...

  Where:
    reference=STRING or ID         location reference or ID

  Update:
    set_reference=NAME             change location reference
    set_name=NAME                  change location name
    set_address=STRING
    set_city=STRING
    set_state=STRING
    set_zip_code=NUMBER
    set_latitude=STRING
    set_longitude=STRING
    set_misc=STRING

\n
EOM
        ));
    }

    // Check permissions
    if (!auth('location_add')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(2, $self['error']));
    }

    // See if it's an location
    list($status, $rows, $loc) = ona_find_location($options['reference']);

    if (!isset($loc['id'])) {
        $self['error'] = "Unable to find location using: {$options['reference']}!";
        printmsg($self['error'], 'error');
        return(array(1, $self['error']));
    }

    printmsg("Found location: {$loc['reference']}", 'debug');

    $original_record = $loc;

    // This variable will contain the updated info we'll insert into the DB
    $SET = array();

    // If they are specifying a new value, process it.
    if (isset($options['set_reference']))
      $SET['reference'] = $options['set_reference'];
    if (isset($options['set_name']))
      $SET['name'] = $options['set_name'];
    if (isset($options['set_address']))
      $SET['address'] = $options['set_address'];
    if (isset($options['set_city']))
      $SET['city'] = $options['set_city'];
    if (isset($options['set_state']))
      $SET['state'] = $options['set_state'];
    if (isset($options['set_zip_code']))
      $SET['zip_code'] = $options['set_zip_code'];
    if (isset($options['set_latitude']))
      $SET['latitude'] = $options['set_latitude'];
    if (isset($options['set_longitude']))
      $SET['longitude'] = $options['set_longitude'];
    if (isset($options['set_misc']))
      $SET['misc'] = $options['set_misc'];

    if ($SET) {
      // Update the record
      list($status, $rows) = db_update_record($onadb, 'locations', array('id' => $loc['id']), $SET);
      if ($status or !$rows) {
        $self['error'] = "SQL Query failed: " . $self['error'];
        printmsg($self['error'], 'error');
        return(array(3, $self['error']));
      }
    }

    // Return the success notice
    $result['module_version'] = $version;
    list($status, $rows, $new_record) = ona_get_location_record(array('id' => $loc['id']));
    $result['locations'][0] = $new_record;

    unset($result['locations'][0]['network_role_id']);
    ksort($result['locations'][0]);

    // Return the success notice with changes
    $more='';
    $log_msg='';
    foreach(array_keys($original_record) as $key) {
        if($original_record[$key] != $new_record[$key]) {
            $log_msg .= $more . $key . "[" .$original_record[$key] . "=>" . $new_record[$key] . "]";
            $more= ';';
        }
    }

    // only print to logfile if a change has been made to the record
    if($more != '') {
      $log_msg = "Location record UPDATED:{$loc['id']}: {$log_msg}";
    } else {
      $log_msg = "Location record UPDATED:{$loc['id']}: Update attempt produced no changes.";
      $result['status_msg'] = $log_msg;
    }

    printmsg($log_msg, 'notice');

    return(array(0, $result));
}




///////////////////////////////////////////////////////////////////////
//  Function: location_display (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = location_display('location=test');
///////////////////////////////////////////////////////////////////////
function location_display($options="") {
    global $self ;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if ( !isset($options['reference']) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }

    $locationsearch = array();
    // setup a location search based on name or id
    if (is_numeric($options['reference'])) {
        $locationsearch['id'] = $options['reference'];
    } else {
        $locationsearch['reference'] = $options['reference'];
    }

    // Determine the entry itself exists
    list($status, $rows, $location) = ona_get_location_record($locationsearch);

    // Test to see that we were able to find the specified record
    if (!$location['id']) {
        $self['error'] = "Unable to find the location record using {$options['location']}!";
        printmsg($self['error'],'error');
        return(array(4, $self['error']));
    }

    // Debugging
    printmsg("Found {$location['name']}", 'debug');

    $text_array = array();
    $text_array['module_version'] = $version;

    // Select just the fields requested
    if (isset($options['fields'])) {
      $fields = explode(',', $options['fields']);
      $location = array_intersect_key($location, array_flip($fields));
    }

    ksort($location);
    $text_array['locations'][0] = $location;

    // Return
    return(array(0, $text_array));
}



/////////////////////

// Return locations from the database
// Allows filtering and other options to narrow the data down

/////////////////////
function locations($options="") {
    global $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    $text_array = array();
    $text_array['module_version'] = $version;

    // Start building the "where" clause for the sql query to find the records to display
    // DISPLAY ALL
    $where = '';
    if (!$options)
      $where = "id > 0";
	
    $and = '';

    // enable or disable wildcards
    $wildcard = '';
#    $wildcard = '%';
#    if (isset($options['nowildcard'])) $wildcard = '';



    // RECORD ID
    if (isset($options['id'])) {
        $where .= $and . "id = " . $onadb->qstr($options['id']);
        $and = " AND ";
    }

    // city
    if (isset($options['city'])) {
        $where .= $and . "city LIKE " . $onadb->qstr($wildcard.$options['city'].$wildcard);
        $and = " AND ";
    }

    // NAME
    if (isset($options['name'])) {
        $where .= $and .  "name LIKE " . $onadb->qstr($wildcard.$options['name'].$wildcard);
        $and = " AND ";
    }

    // address
    if (isset($options['address'])) {
        $where .= $and .  "address LIKE " . $onadb->qstr($wildcard.$options['address'].$wildcard);
        $and = " AND ";
    }

    // misc
    if (isset($options['misc'])) {
        $where .= $and .  "misc LIKE " . $onadb->qstr($wildcard.$options['misc'].$wildcard);
        $and = " AND ";
    }

    // state
    if (isset($options['state'])) {
        $where .= $and .  "state LIKE " . $onadb->qstr($wildcard.$options['state'].$wildcard);
        $and = " AND ";
    }

    // zip
    if (isset($options['zip_code'])) {
        $where .= $and .  "zip_code LIKE " . $onadb->qstr($wildcard.$options['zip_code'].$wildcard);
        $and = " AND ";
    }


    printmsg("Query: [from] locations [where] $where", 'notice');

    $rows=0;
    if ($where)
      list ($status, $rows, $locations) = db_get_records( $onadb, 'locations', $where);

    if (!$rows) {
      $text_array['status_msg'] = "No location records were found with your query";
      return(array(0, $text_array));
    }

    $i=0;
    foreach ($locations as $location) {
      // Select just the fields requested
      if (isset($options['fields'])) {
        $fields = explode(',', $options['fields']);
        $location = array_intersect_key($location, array_flip($fields));
      }

      ksort($location);
      $text_array['locations'][$i]=$location;

      $i++;
    }

    $text_array['count'] = count($locations);

    // Return the success notice
    return(array(0, $text_array));

}
