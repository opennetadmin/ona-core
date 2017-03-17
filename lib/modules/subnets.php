<?php


//////////////////////////////////////////////////////////////////////////////
// Calculates the percentage of a subnet that is in "use".
// Returns a three part list:
//    list($percentage_used, $number_used, $number_total)
//////////////////////////////////////////////////////////////////////////////
function get_subnet_usage($subnet_id) {
    global $conf, $self, $onadb;

    list($status, $rows, $subnet) = db_get_record($onadb, 'subnets', array('id' => $subnet_id));
    if ($status or !$rows) { return(0); }
    if (strlen($subnet['ip_addr']) > 11) {
        $sub = gmp_sub("340282366920938463463374607431768211455", $subnet['ip_mask']);
        $subnet['size'] = gmp_strval(gmp_sub($sub,1));
    } else {
        $subnet['size'] = (0xffffffff - ip_mangle($subnet['ip_mask'], 'numeric')) - 1;
        if ($subnet['ip_mask'] == 4294967295) $subnet['size'] = 1;
        if ($subnet['ip_mask'] == 4294967294) $subnet['size'] = 2;
    }

    // Calculate the percentage used (total size - allocated hosts - dhcp pool size)
    list($status, $hosts, $tmp) = db_get_records($onadb, 'interfaces', array('subnet_id' => $subnet['id']), "", 0);
    list($status, $rows, $pools) = db_get_records($onadb, 'dhcp_pools', array('subnet_id' => $subnet['id']));
    $pool_size = 0;
    foreach ($pools as $pool) {
        $pool_size += ($pool['ip_addr_end'] - $pool['ip_addr_start'] + 1);
    }
    $total_used = $hosts + $pool_size;
    $percentage = 100;
    if ($subnet['size']) $percentage = sprintf('%d', ($total_used / $subnet['size']) * 100);
    return(array($percentage, $total_used, $subnet['size']));
}


/////////////////////

// Return subnets from the database
// Allows filtering and other options to narrow the data down

/////////////////////
function subnets($options="") {
    global $self, $onadb;
    printmsg('Called with options: ('.implode (";",$options).')', 'info');

    // Version - UPDATE on every edit!
    $version = '2.00';

    $text_array = array();
    $text_array['module_version'] = $version;



    // Start building the "where" clause for the sql query to find the subnets to display
    // DISPLAY ALL
    $where = '';
    if (!$options)
      $where = "id > 0";
    $and = '';

    // enable or disable wildcards
    $wildcard = '';
#    $wildcard = '%';
#    if (isset($options['nowildcard'])) $wildcard = '';

    // VLAN ID
    if (isset($options['vlan'])) {
        $where .= $and . "vlan_id = " . $onadb->qstr($options['vlan_id']);
        $and = " AND ";
    }

    // SUBNET TYPE
    if (isset($options['type'])) {
        list($status, $rows, $net_type) = ona_find_subnet_type($options['type']);
        $where .= $and . "subnet_type_id = " . $onadb->qstr($net_type['id']);
        $and = " AND ";
    }

    // SUBNET NAME
    if (isset($options['name'])) {
        // This field is always upper case
        $options['name'] = strtoupper($options['name']);
        $where .= $and . "name LIKE " . $onadb->qstr($wildcard.$options['name'].$wildcard);
        $and = " AND ";
    }

    // IP ADDRESS
    if (isset($options['ip'])) {
        // Build $ip and $ip_end from $options['ip_subnet'] and $options['ip_subnet_thru']
        $ip = ip_complete($options['ip'], '0');
        if (isset($options['endip'])) {
            $ip = ip_complete($options['ip'], '0');
            $ip_end = ip_complete($options['endip'], '255');

            // Find out if $ip and $ip_end are valid
            $ip = ip_mangle($ip, 'numeric');
            $ip_end = ip_mangle($ip_end, 'numeric');
            if ($ip != -1 and $ip_end != -1) {
                // Find subnets between the specified ranges
                $where .= $and . " ip_addr >= " . $ip . " AND ip_addr <= " . $ip_end;
                $and = " AND ";
            }
        }
        else {
            list($status, $rows, $record) = ona_find_subnet($ip);
            if($rows) {
                $where .= $and . " id = " . $record['id'];
                $and = " AND ";
            }
       }
    }

    // tag
    if (isset($options['tag'])) {
        $where .= $and . "id in (select reference from tags where type like 'subnet' and name in ('". implode('\',\'', explode (',',$options['tag']) ) . "'))";
        $and = " AND ";

    }

    // custom attribute type
    if (isset($options['catype'])) {
        $where .= $and . "id in (select table_id_ref from custom_attributes where table_name_ref like 'subnets' and custom_attribute_type_id = (SELECT id FROM custom_attribute_types WHERE name = " . $onadb->qstr($options['catype']) . "))";
        $and = " AND ";
        $cavaluetype = "and custom_attribute_type_id = (SELECT id FROM custom_attribute_types WHERE name = " . $onadb->qstr($options['catype']) . ")";
    }

    // custom attribute value
    if (isset($options['cavalue'])) {
        $where .= $and . "id in (select table_id_ref from custom_attributes where table_name_ref like 'subnets' {$cavaluetype} and value like " . $onadb->qstr($options['cavalue']) . ")";
        $and = " AND ";
    }

    printmsg("Query: [from] subnets [where] $where", 'debug');

    $rows=0;
    if ($where)
      list ($status, $rows, $subnets) = db_get_records( $onadb, 'subnets', $where);

    if (!$rows) {
      $text_array['status_msg'] = "No subnet records were found with your query";
      return(array(0, $text_array));
    }

    $i=0;
    foreach ($subnets as $subnet) {
      // get subnet type name
      list($status, $rows, $sntype) = ona_get_subnet_type_record(array('id' => $subnet['subnet_type_id']));
      $subnet['subnet_type_name'] = $sntype['display_name'];
      $subnet['ip_addr_text'] = ip_mangle($subnet['ip_addr'], 'dotted');
      $subnet['ip_mask_text'] = ip_mangle($subnet['ip_mask'], 'dotted');
      $subnet['ip_mask_cidr'] = ip_mangle($subnet['ip_mask'], 'cidr');

      // Select just the fields requested
      if (isset($options['fields'])) {
        $fields = explode(',', $options['fields']);
        $subnet = array_intersect_key($subnet, array_flip($fields));
      }

      ksort($subnet);
      $text_array['subnets'][$i]=$subnet;

      // cleanup some un-used junk
      unset($text_array['subnets'][$i]['network_role_id']);
      unset($text_array['subnets'][$i]['vlan_id']);

      $i++;
    }

    $text_array['count'] = count($subnets);

    // Return the success notice
    return(array(0, $text_array));


}


///////////////////////////////////////////////////////////////////////
//  Function: subnet_display (string $options='')
//
//  Description:
//    Display an existing subnet.
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
//  Example: list($status, $text) = subnet_display('subnet=10.44.0.0');
///////////////////////////////////////////////////////////////////////
function subnet_display($options="") {
    global $self, $onadb;
    printmsg('Called with options: ('.implode (";",$options).')', 'info');

    // Version - UPDATE on every edit!
    $version = '2.00';

    $text_array = array();
    $text_array['module_version'] = $version;

    // Return the usage summary if we need to
    if (!$options['subnet']) {
      $self['error'] = 'Insufficient parameters';
      return(array(1,$self['error']));
    }

    // They provided a subnet ID or IP address
    // Find a subnet record
    list($status, $rows, $subnet) = ona_find_subnet($options['subnet']);
    if ($status or !$rows) {
      $self['error'] = "Subnet not found";
      return(array(2, $self['error']));
    }

    // get subnet type name
    list($status, $rows, $sntype) = ona_get_subnet_type_record(array('id' => $subnet['subnet_type_id']));
    $subnet['subnet_type_name'] = $sntype['display_name'];

    // Convert some data
    $subnet['ip_addr_text'] = ip_mangle($subnet['ip_addr'], 'dotted');
    $subnet['ip_mask_text'] = ip_mangle($subnet['ip_mask'], 'dotted');
    $subnet['ip_mask_cidr'] = ip_mangle($subnet['ip_mask'], 'cidr');

    if (isset($options['verbose']))
      $options['verbose'] = sanitize_YN($options['verbose'], 'N');

    if ($options['verbose'] == 'Y') {
      // Tag records
      list($status, $rows, $tags) = db_get_records($onadb, 'tags', array('type' => 'subnet', 'reference' => $subnet['id']));
      if ($rows) {
        foreach ($tags as $tag) {
          $subnet['tags'][] = $tag['name'];
        }
      }

      // VLAN record
      list($status, $rows, $vlan) = ona_get_vlan_record(array('id' => $subnet['vlan_id']));
      if ($rows) {
        $subnet['vlan'] = $vlan;
      }

      // Gather sizing
      list($percent,$total_used,$size) = get_subnet_usage($subnet['id']);
      $subnet['total_allocated_percent'] = $percent;
      $subnet['total_allocated'] = $total_used;
      $subnet['total_available'] = $size;

    }

    // cleanup some un-used junk
    unset($subnet['network_role_id']);
    unset($subnet['vlan_id']);

    // Select just the fields requested
    if (isset($options['fields'])) {
      $fields = explode(',', $options['fields']);
      $subnet = array_intersect_key($subnet, array_flip($fields));
    }

    ksort($subnet);
    $text_array['subnets'][0] = $subnet;

    // Return the success notice
    return(array(0, $text_array));
}










///////////////////////////////////////////////////////////////////////
//  Function: subnet_add (string $options='')
//
//  Description:
//    Add a new subnet.
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
//  Example: list($status, $result) = subnet_add('');
///////////////////////////////////////////////////////////////////////
function subnet_add($options="") {
    global $self, $onadb;
    printmsg('Called with options: ('.implode (";",$options).')', 'info');

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (!(isset($options['ip']) and
          isset($options['netmask']) and
          isset($options['type']) and
          isset($options['name']))
       ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }

    //
    // Define the fields we're inserting
    //
    // This variable will contain the info we'll insert into the DB
    $SET = array();
    $result = array();

    // Set vlan_id to 0 initially
    $SET['vlan_id'] = 0;

    // TODO: remove this column from db
    $SET['network_role_id'] = 0;


    // Prepare options[ip] - translate IP address to a number
    $options['ip'] = $ourip = ip_mangle($options['ip'], 'numeric');
    if ($ourip == -1) {
        $self['error'] = "The IP address specified is invalid!";
        return(array(2, $self['error']));
    }

    // Prepare options[netmask] - translate IP address to a number
    $options['netmask'] = ip_mangle($options['netmask'], 'numeric');
    if ($options['netmask'] == -1) {
        $self['error'] = "The netmask specified is invalid!";
        return(array(3, $self['error']));
    }

    // Validate the netmask is okay
    $cidr = ip_mangle($options['netmask'], 'cidr');
    if ($cidr == -1) {
        $self['error'] = "The netmask specified is invalid!";
        return(array(4, $self['error']));
    }

    if(is_ipv4($ourip))  {
       // echo "ipv4";
       $padding = 32;
       $fmt = 'dotted';
       $ip1 = ip_mangle($ourip, 'binary');
       $num_hosts = 0xffffffff - $options['netmask'];
       $last_host = ($options['ip'] + $num_hosts);
    } else {
       // echo "ipv6";
       $padding = 128;
       $fmt = 'ipv6gz';
       $ip1 = ip_mangle($ourip, 'bin128');
       $sub = gmp_sub("340282366920938463463374607431768211455", $options['netmask']);
       $num_hosts = gmp_strval($sub); 
       $last_host = gmp_strval(gmp_add($options['ip'],$num_hosts));
    }

    // Validate that the subnet IP & netmask combo are valid together.
    $ip2 = str_pad(substr($ip1, 0, $cidr), $padding, '0');
    $ip1 = ip_mangle($ip1, $fmt);
    $ip2 = ip_mangle($ip2, $fmt);
    if ($ip1 != $ip2) {
        $self['error'] = "Invalid subnet specified - did you mean: {$ip2}/{$cidr}?";
        return(array(5, $self['error']));
    }

    // *** Check to see if the new subnet overlaps any existing ONA subnets *** //
    // I convert the IP address to dotted format when calling ona_find_subnet()
    // because it saves it from doing a few unnecessary sql queries.

    // Look for overlaps like this (where new subnet address starts inside an existing subnet):
    //            [ -- new subnet -- ]
    //    [ -- old subnet --]
    list($status, $rows, $subnet) = ona_find_subnet(ip_mangle($options['ip'], 'dotted'));
    if ($rows != 0) {
        $self['error'] = "Subnet address conflict! New subnet starts inside an existing subnet.";
        return(array(6, $self['error'] . " Conflicting subnet record ID: {$subnet['id']}"));
    }


    // Look for overlaps like this (where the new subnet ends inside an existing subnet):
    //    [ -- new subnet -- ]
    //           [ -- old subnet --]
    // Find last address of our subnet, and see if it's inside of any other subnet:
    list($status, $rows, $subnet) = ona_find_subnet(ip_mangle($last_host, 'dotted'));
    if ($rows != 0) {
        $self['error'] = "Subnet address conflict! New subnet ends inside an existing subnet.";
        return(array(7, $self['error'] . " Conflicting subnet record ID: {$subnet['id']}"));
    }


    // Look for overlaps like this (where the new subnet entirely overlaps an existing subnet):
    //    [ -------- new subnet --------- ]
    //           [ -- old subnet --]
    //
    // Do a cool SQL query to find any subnets whoose start address is >= or <= the
    // new subnet base address.
    $where = "ip_addr >= {$options['ip']} AND ip_addr <= {$last_host}";
    list($status, $rows, $subnet) = ona_get_subnet_record($where);
    if ($rows != 0) {
        $self['error'] = "Subnet address conflict! New subnet would encompass an existing subnet.";
        return(array(8, $self['error'] . " Conflicting subnet record ID: {$subnet['id']}"));
    }

    // The IP/NETMASK look good, set them.
    $SET['ip_addr'] = $options['ip'];
    $SET['ip_mask'] = $options['netmask'];


    // Find the type from $options[type]
    list($status, $rows, $subnet_type) = ona_find_subnet_type($options['type']);
    if ($status or $rows != 1) {
        $self['error'] = "Invalid subnet type specified!";
        return(array(10, $self['error']));
    }
    printmsg("Subnet type selected: {$subnet_type['display_name']} ({$subnet_type['short_name']})", 'debug');
    $SET['subnet_type_id'] = $subnet_type['id'];



    // Find the VLAN ID from $options[vlan] and $options[campus]
    if (isset($options['vlan']) or isset($options['campus'])) {
        list($status, $rows, $vlan) = ona_find_vlan($options['vlan'], $options['campus']);
        if ($status or $rows != 1) {
            $self['error'] = "The vlan/campus pair specified is invalid!";
            return(array(11, $self['error']));
        }
        printmsg("VLAN selected: {$vlan['name']} in {$vlan['vlan_campus_name']} campus", 'debug');
        $SET['vlan_id'] = $vlan['id'];
    }

    // Sanitize "name" option
    // We require subnet names to be in upper case and spaces are converted to -'s.
    $options['name'] = trim($options['name']);
    $options['name'] = preg_replace('/\s+/', '-', $options['name']);
    $options['name'] = strtoupper($options['name']);
    // Make sure there's not another subnet with this name
    list($status, $rows, $tmp) = ona_get_subnet_record(array('name' => $options['name']));
    if ($status or $rows) {
        $self['error'] = "That name is already used by another subnet!";
        return(array(12, $self['error']));
    }
    $SET['name'] = $options['name'];

    // Check permissions
    if (!auth('subnet_add')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'alert');
        return(array(14, $self['error']));
    }

    // Get the next ID for the new interface
    $id = ona_get_next_id('subnets');
    if (!$id) {
        $self['error'] = "The ona_get_next_id() call failed!";
        return(array(15, $self['error']));
    }
    $SET['id'] = $id;

    // Insert the new subnet  record
    list($status, $rows) = db_insert_record(
       $onadb,
       'subnets',
       $SET
    );

    // Report errors
    if ($status or !$rows)
        return(array(16, $self['error']));

    // Return the success notice
    unset($SET['network_role_id']);
    $result['status_msg'] = 'Subnet added.';
    $result['module_version'] = $version;
    list($status, $rows, $result['subnets'][0]) = ona_get_subnet_record(array('id' => $SET['id']));
    $result['subnets'][0]['subnet_type_name'] = $subnet_type['display_name'];
    $result['subnets'][0]['ip_addr_text'] = ip_mangle($result['subnets'][0]['ip_addr'], 'dotted');
    $result['subnets'][0]['ip_mask_text'] = ip_mangle($result['subnets'][0]['ip_mask'], 'dotted');
    $result['subnets'][0]['ip_mask_cidr'] = ip_mangle($result['subnets'][0]['ip_mask'], 'cidr');

    unset($result['subnets'][0]['network_role_id']);
    ksort($result['subnets'][0]);

    printmsg("Subnet added: {$SET['name']} {$result['subnets'][0]['ip_addr_text']}", 'notice');

    return(array(0, $result));
}









///////////////////////////////////////////////////////////////////////
//  Function: subnet_modify (string $options='')
//
//  Description:
//    Modify an existing subnet.
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
//  Example: list($status, $result) = subnet_modify('');
///////////////////////////////////////////////////////////////////////
function subnet_modify($options="") {
    global $self, $onadb;
    printmsg('Called with options: ('.implode (";",$options).')', 'info');

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (!isset($options['subnet']) or
        !(isset($options['set_ip']) or
          isset($options['set_netmask']) or
          isset($options['set_type']) or
          isset($options['set_name']) or
          array_key_exists('set_vlan', $options) or
          isset($options['set_security_level']))
       ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }

    $check_boundaries = 0;

    // Find the subnet record we're modifying
    list($status, $rows, $subnet) = ona_find_subnet($options['subnet']);
    if ($status or !$rows) {
        $self['error'] = "Subnet not found";
        return(array(2, $self['error']));
    }

    // Save the record before updating (logging)
    $original_record = $subnet;

    // Check permissions
    if (!auth('subnet_modify')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'alert');
        return(array(3, $self['error']));
    }

    // Validate the ip address
    if (!$options['set_ip']) {
        $options['set_ip'] = $subnet['ip_addr'];
    }
    else {
        $check_boundaries = 1;
        $options['set_ip'] = $setip = ip_mangle($options['set_ip'], 'numeric');
        // FIXME: what if ip_mangle returns a GMP object?
            if ($options['set_ip'] == -1) {
            $self['error'] = "The IP address specified is invalid!";
            return(array(4, $self['error']));
        }
    }

    // Validate the netmask is okay
    if (!$options['set_netmask']) {
        $options['set_netmask'] = $subnet['ip_mask'];
        $cidr = ip_mangle($options['set_netmask'], 'cidr');
    }
    else {
        $check_boundaries = 1;
        $cidr = ip_mangle($options['set_netmask'], 'cidr');
        // FIXME: what if ip_mangle returns a GMP object?
        $options['set_netmask'] = ip_mangle($options['set_netmask'], 'numeric');
        if ($cidr == -1 or $options['set_netmask'] == -1) {
            $self['error'] = "The netmask specified is invalid!";
            return(array(5, $self['error']));
        }
    }

    if(is_ipv4($setip))  {
       $padding = 32;
       $fmt = 'dotted';
       $ip1 = ip_mangle($setip, 'binary');
       $num_hosts = 0xffffffff - $options['set_netmask'];
       $first_host=$options['set_ip'] + 1;
       $last_host = ($options['set_ip'] + $num_hosts);
       $str_last_host=$last_host;
       $last_last_host=$last_host -1;
    } else {
       $padding = 128;
       $fmt = 'ipv6gz';
       $ip1 = ip_mangle($setip, 'bin128');
       $first_host=gmp_strval(gmp_add($options['set_ip'] , 1));
       $sub = gmp_sub("340282366920938463463374607431768211455", $options['set_netmask']);
       $last_host = gmp_add($options['set_ip'] , $sub);
       $str_last_host=gmp_strval($last_host);
       $last_last_host=gmp_strval(gmp_sub($last_host ,1));
    }

    // Validate that the subnet IP & netmask combo are valid together.
    $ip2 = str_pad(substr($ip1, 0, $cidr), $padding, '0');
    $ip1 = ip_mangle($ip1, $fmt);
    $ip2 = ip_mangle($ip2, $fmt);
    if ($ip1 != $ip2) {
        $self['error'] = "Invalid subnet IP and MASK pair specified - did you mean: {$ip2}/{$cidr}?";
        printmsg($self['error'], 'error');
        return(array(6, $self['error']));
    }

    // If our IP or netmask changed we need to make sure that
    // we won't abandon any host interfaces.
    // We also need to verify that the new boundaries are valid and
    // don't interefere with any other subnets.
    if ($check_boundaries == 1) {

        // *** Check to see if the new subnet overlaps any existing ONA subnets *** //
        // I convert the IP address to dotted format when calling ona_find_subnet()
        // because it saves it from doing a few unnecessary sql queries.

        // Look for overlaps like this (where new subnet address starts inside an existing subnet):
        //            [ -- new subnet -- ]
        //    [ -- old subnet --]
        list($status, $rows, $record) = ona_find_subnet(ip_mangle($options['set_ip'], 'dotted'));
        if ($rows and $record['id'] != $subnet['id']) {
            $self['error'] = "Subnet address conflict! New subnet starts inside an existing subnet.";
            return(array(7, $self['error'] . "\n" .
                            "Conflicting subnet record ID: {$record['id']}\n"));
        }


        // Look for overlaps like this (where the new subnet ends inside an existing subnet):
        //    [ -- new subnet -- ]
        //           [ -- old subnet --]
        // Find last address of our subnet, and see if it's inside of any other subnet:
        list($status, $rows, $record) = ona_find_subnet(ip_mangle($str_last_host, 'dotted'));
        if ($rows and $record['id'] != $subnet['id']) {
            $self['error'] = "Subnet address conflict! New subnet ends inside an existing subnet.";
            return(array(8, $self['error'] . "\n" .
                            "Conflicting subnet record ID: {$record['id']}\n"));
        }


        // Look for overlaps like this (where the new subnet entirely overlaps an existing subnet):
        //    [ -------- new subnet --------- ]
        //           [ -- old subnet --]
        //
        // Do a cool SQL query to find all subnets whose start address is >= or <= the
        // new subnet base address.
        $where = "ip_addr >= {$options['set_ip']} AND ip_addr <= {$str_last_host}";
        list($status, $rows, $record) = ona_get_subnet_record($where);
        if ( ($rows > 1) or ($rows == 1 and $record['id'] != $subnet['id']) ) {
            $self['error'] = "Subnet address conflict! New subnet would encompass an existing subnet.";
            return(array(9, $self['error'] . "\n" .
                            "Conflicting subnet record ID: {$record['id']}\n"));
        }

        // Look for any hosts that are currently in our subnet that would be
        // abandoned if we were to make the proposed changes.
        // Look for hosts on either side of the new subnet boundaries:
        //            [--- new subnet ---]
        //         *      **   *            *   <-- Hosts: the first and last host would be a problem!
        //       [------- old subnet --------]
        //
        $where1 = "subnet_id = {$subnet['id']} AND ip_addr < {$first_host}";
        $where2 = "subnet_id = {$subnet['id']} AND ip_addr > {$last_last_host}";
        list($status, $rows1, $record) = ona_get_interface_record($where1);
        list($status, $rows2, $record) = ona_get_interface_record($where2);
        if ($rows1 or $rows2) {
            $num = $rows1 + $rows2;
            $self['error'] = "Changes would abandon {$num} hosts in an unallocated ip space";
            return(array(10, $self['error']));
        }


        // Look for any dhcp pools that are currently in our subnet that would be
        // abandoned if we were to make the proposed changes.
        // Look for existin pools with start/end values outside of new subnet range
        //            [--- new subnet ---]
        //                      [--cur pool--]
        //       [------- old subnet --------]
        //
        $where1 = "subnet_id = {$subnet['id']} AND ip_addr_start < {$options['set_ip']}";
        $where2 = "subnet_id = {$subnet['id']} AND ip_addr_end > {$str_last_host}";
        list($status, $rows1, $record) = ona_get_dhcp_pool_record($where1);
        list($status, $rows2, $record) = ona_get_dhcp_pool_record($where2);
        if ($rows1 or $rows2) {
            $num = $rows1 + $rows2;
            $self['error'] = "Changes would abandon a DHCP pool in an unallocated ip space, adjust pool sizes first";
            return(array(10, $self['error']));
        }

    }

    //
    // Define the fields we're updating
    //
    // This variable will contain the updated info we'll insert into the DB
    $SET = array();
    $SET['ip_addr'] = $options['set_ip'];
    $SET['ip_mask'] = $options['set_netmask'];



    // Set options['set_security_level']?
    // Sanitize "security_level" option
    if (array_key_exists('set_security_level', $options)) {
        $options['set_security_level'] = sanitize_security_level($options['set_security_level']);
        if ($options['set_security_level'] == -1)
            return(array(11, $self['error']));
        $SET['lvl'] = $options['set_security_level'];
    }


    // Set options['set_name']?
    if (isset($options['set_name'])) {
        // BUSINESS RULE: We require subnet names to be in upper case and spaces are converted to -'s.
        $options['set_name'] = trim($options['set_name']);
        $options['set_name'] = preg_replace('/\s+/', '-', $options['set_name']);
        $options['set_name'] = strtoupper($options['set_name']);
        // Make sure there's not another subnet with this name
        list($status, $rows, $tmp) = ona_get_subnet_record(array('name' => $options['set_name']));
        if ($status or $rows > 1 or ($rows == 1 and $tmp['id'] != $subnet['id'])) {
            $self['error'] = "That name is already used by another subnet!";
            return(array(12, $self['error']));
        }
        $SET['name'] = $options['set_name'];
    }


    // Set options['set_type']?
    if (isset($options['set_type'])) {
        // Find the type from $options[type]
        list($status, $rows, $subnet_type) = ona_find_subnet_type($options['set_type']);
        if ($status or $rows != 1) {
            $self['error'] = "Invalid subnet type specified!";
            return(array(13, $self['error']));
        }
        printmsg("Subnet type selected: {$subnet_type['display_name']} ({$subnet_type['short_name']})", 'debug');
        $SET['subnet_type_id'] = $subnet_type['id'];
    }


    // Set options['set_vlan']?
    if (array_key_exists('set_vlan', $options) or isset($options['campus'])) {
        if (!$options['set_vlan'])
            $SET['vlan_id'] = 0;
        else {
            // Find the VLAN ID from $options[set_vlan] and $options[campus]
            list($status, $rows, $vlan) = ona_find_vlan($options['set_vlan'], $options['campus']);
            if ($status or $rows != 1) {
                $self['error'] = "The vlan/campus pair specified is invalid!";
                return(array(15, $self['error']));
            }
            printmsg("VLAN selected: {$vlan['name']} in {$vlan['vlan_campus_name']} campus", 'debug');
            $SET['vlan_id'] = $vlan['id'];
        }
    }


    // Update the subnet record
    list($status, $rows) = db_update_record($onadb, 'subnets', array('id' => $subnet['id']), $SET);
    if ($status or !$rows)
        return(array(16, $self['error']));

    // Return the success notice
    unset($SET['network_role_id']);
    $result['status_msg'] = 'Subnet UPDATED.';
    $result['module_version'] = $version;
    list($status, $rows, $new_record) = ona_get_subnet_record(array('id' => $subnet['id']));
    $result['subnets'][0] = $new_record;
    $result['subnets'][0]['subnet_type_name'] = $subnet_type['display_name'];
    $result['subnets'][0]['ip_addr_text'] = ip_mangle($result['subnets'][0]['ip_addr'], 'dotted');
    $result['subnets'][0]['ip_mask_text'] = ip_mangle($result['subnets'][0]['ip_mask'], 'dotted');
    $result['subnets'][0]['ip_mask_cidr'] = ip_mangle($result['subnets'][0]['ip_mask'], 'cidr');

    unset($result['subnets'][0]['network_role_id']);
    ksort($result['subnets'][0]);

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
      $log_msg = "Subnet record UPDATED:{$subnet['id']}: {$log_msg}";
    } else {
      $log_msg = "Subnet record UPDATED:{$subnet['id']}: Update attempt produced no changes.";
    }

    printmsg($log_msg, 'notice');

    return(array(0, $result));
}









///////////////////////////////////////////////////////////////////////
//  Function: subnet_del (string $options='')
//
//  Description:
//    Delete an existing subnet.
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
//  Example: list($status, $result) = subnet_del('host=test');
///////////////////////////////////////////////////////////////////////
function subnet_del($options="") {
    global $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    printmsg('Called with options: ('.implode (";",$options).')', 'info');

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if (!isset($options['subnet']) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }


    // Find the subnet record we're deleting
    list($status, $rows, $subnet) = ona_find_subnet($options['subnet']);
    if ($status or !$rows) {
        $self['error'] = "Subnet not found";
        return(array(2, $self['error']));
    }


    // Check permissions
    if (!auth('subnet_del')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'alert');
        return(array(3, $self['error']));
    }


    $text = "";

    // SUMMARY:
    //   Delete assignments to any DHCP servers
    //   Delete any DHCP pools on the current subnet
    //   Delete any DHCP options associated with this subnet
    //   Delete any interfaces belonging to hosts with more than one interface
    //   Delete any hosts (and all their associated info) that have only one interface
    //   Delete subnet Record
    //   Delete custom attributes
    //
    //   FIXME: display a warning if there are no more subnets that a dhcp server is serving dhcp for?

    // Delete DHCP server assignments
    list($status, $rows) = db_delete_records($onadb, 'dhcp_server_subnets', array('subnet_id' => $subnet['id']));
    if ($status) {
        $self['error'] = "DHCP server assignment delete failed: {$self['error']}";
        return(array(5, $self['error']));
    }

    // Delete DHCP pools
    list($status, $rows) = db_delete_records($onadb, 'dhcp_pools', array('subnet_id' => $subnet['id']));
    if ($status) {
        $self['error'] = "DHCP pool delete failed: {$self['error']}";
        return(array(5, $self['error']));
    }

    // Delete DHCP options
    list($status, $rows) = db_delete_records($onadb, 'dhcp_option_entries', array('subnet_id' => $subnet['id']));
    if ($status) {
        $self['error'] = "DHCP parameter delete failed: {$self['error']}";
        return(array(5, $self['error']));
    }

    // Delete tag entries
    list($status, $rows, $records) = db_get_records($onadb, 'tags', array('type' => 'subnet', 'reference' => $subnet['id']));
    $log=array(); $i=0;
    foreach ($records as $record) {
        $log[$i]= "Tag DELETED: {$record['name']} from {$subnet['name']}";
        $i++;
    }
    //do the delete
    list($status, $rows) = db_delete_records($onadb, 'tags', array('type' => 'subnet', 'reference' => $subnet['id']));
    if ($status) {
        $self['error'] = "Tag delete SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(5, $add_to_error . $self['error']));
    }
    //log deletions
    foreach($log as $log_msg) {
        printmsg($log_msg,0);
        $add_to_error .= $log_msg;
    }

    // Delete custom attribute entries
    // get list for logging
    list($status, $rows, $records) = db_get_records($onadb, 'custom_attributes', array('table_name_ref' => 'subnets', 'table_id_ref' => $subnet['id']));
    $log=array(); $i=0;
    foreach ($records as $record) {
        list($status, $rows, $ca) = ona_get_custom_attribute_record(array('id' => $record['id']));
        $log[$i]= "Custom Attribute DELETED: {$ca['name']} ({$ca['value']}) from {$subnet['name']}";
        $i++;
    }

    //do the delete
    list($status, $rows) = db_delete_records($onadb, 'custom_attributes', array('table_name_ref' => 'subnets', 'table_id_ref' => $subnet['id']));
    if ($status) {
        $self['error'] = "Custom attribute delete SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(5, $self['error']));
    }

    //log deletions
    foreach($log as $log_msg) {
        printmsg($log_msg,0);
        //$add_to_error .= $log_msg;
    }



    // Delete associated host / interface records that need to be deleted
    // BUSINESS RULE: We delete hosts that have only one interface (and it's on this subnet)
    // BUSINESS RULE: We delete interfaces from hosts that have multiple interfaces
    list($status, $rows, $interfaces) = db_get_records($onadb, 'interfaces', array('subnet_id' => $subnet['id']));
    $hosts_to_delete = array();
    $interfaces_to_delete = array();
    foreach ($interfaces as $interface) {
        // Select all  interfaces for the associated host where the subnet ID is not our subnet ID
        $where = "host_id = {$interface['host_id']} AND subnet_id != {$subnet['id']}";
        list($status, $rows, $tmp) = db_get_records($onadb, 'interfaces', $where, '', 0);
        // We'll delete hosts that have only one interface (i.e. no interfaces on any other subnets)
        if ($rows == 0)
            array_push($hosts_to_delete, $interface['host_id']);
        // Otherwise .. we delete this interface since it belongs to a host with interfaces on other subnets
        else
            array_push($interfaces_to_delete, $interface['id']);
    }
    unset($interfaces);

    // make sure we only have one reference for each host and interface
    $interfaces_to_delete = array_unique($interfaces_to_delete);
    $hosts_to_delete = array_unique($hosts_to_delete);

    // Delete interfaces we have selected
    foreach ($interfaces_to_delete as $interface_id) {
        list($status, $output) = run_module('interface_del', array('interface' => $interface_id, 'commit' => 'Y'));
        if ($status) return(array(5, $output));
    }

    // Delete hosts we have selected
    foreach ($hosts_to_delete as $host_id) {
        list($status, $output) = run_module('host_del', array('host' => $host_id, 'commit' => 'Y'));
        if ($status) return(array(5, $output));
    }

    // Delete the subnet
    list($status, $rows) = db_delete_records($onadb, 'subnets', array('id' => $subnet['id']));
    if ($status or !$rows) {
        $self['error'] = "Subnet delete failed: {$self['error']}";
        return(array(5, $self['error']));
    }

    // Return the success notice
    $ip = ip_mangle($subnet['ip_addr'], 'dotted');
    $cidr = ip_mangle($subnet['ip_mask'], 'cidr');
    $self['error'] = "Subnet DELETED: {$subnet['name']} IP: {$ip}/{$cidr}";
    printmsg($self['error'], 'notice');
    return(array(0, $self['error']));

}










///////////////////////////////////////////////////////////////////////
//  Function: subnet_nextip (string $options='')
//
//  Description:
//    Return the next available IP address on a subnet.  Optionally
//    start the search from a starting offset.
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
//  Example: list($status, $result) = subnet_nextip('subnet=test');
///////////////////////////////////////////////////////////////////////
function subnet_nextip($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (!isset($options['subnet'])) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }

    // Find the subnet record we're deleting
    list($status, $rows, $subnet) = ona_find_subnet($options['subnet']);
    if ($status or !$rows) {
        $self['error'] = "Subnet not found";
        return(array(2, $self['error']));
    }

    // Create a few variables that will be handy later
    $num_ips = 0xffffffff - $subnet['ip_mask'];
    $last_ip = ($subnet['ip_addr'] + $num_ips) - 1;

    // check that offset is a number
    if (isset($options['offset']) and !is_numeric($options['offset'])) {
        $self['error'] = "Offset must be a numeric number";
        return(array(3, $self['error']));
    } else {
        $offsetmsg = " beyond offset {$options['offset']}";
    }

    // make sure the offset does not extend beyond the specified subnet
    if ($options['offset'] >= $num_ips - 1) {
        $self['error'] = "Offset extends beyond specified subnet boundary";
        return(array(4, $self['error']));
    }

    // Find the first number based on our subnet and offset
    // if it is an IP, use that as our starting point, otherwise use subnet base.
    if (preg_match("/[a-z]/i", $options['subnet'])) {
      $ip = $subnet['ip_addr'] + $options['offset'];
    } else {
      $ip = ip_mangle($options['subnet'], 'numeric') + $options['offset'];
    }

    // Make sure we skip past the subnet IP to the first usable IP
    if ($ip == $subnet['ip_addr']) $ip++;

    // Start looping through our IP addresses until we find an available one
    while ($ip <= $last_ip) {
        // Find out if the ip is used in an interface
        list($status, $rows, $interfaces) = db_get_records($onadb, 'interfaces', array('ip_addr' => $ip));

        // If we find a free address.. check that it is not in a DHCP pool
        if (!$rows) {
            list($status, $rows, $pool) = db_get_record($onadb, 'dhcp_pools', "{$ip} >= ip_addr_start AND {$ip} <= ip_addr_end");
            if ($rows) $ip = $pool['ip_addr_end'];
                else break;
        }
        $ip++;  // increment by one and check again
    }

    // If we checked all the IPs, make sure we are not on the broadcast IP of the subnet
    if ($ip == $last_ip + 1) {
        $self['error'] = "No available IP addresses found on subnet{$offsetmsg}";
        return(array(5, $self['error']));
    }

    $text_array = array();
    $text_array['ip_addr'] = ip_mangle($ip,'numeric');
    $text_array['ip_addr_text'] = ip_mangle($ip,'dotted');

    // return the IP
    return(array(0, $text_array));
}
