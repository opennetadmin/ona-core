<?php


/////////////////////

// Return hosts from the database
// Allows filtering and other options to narrow the data down

/////////////////////
function hosts($options="") {
    global $self, $onadb;
    printmsg('Called with options: ('.implode (";",$options).')', 'info');

    // Version - UPDATE on every edit!
    $version = '2.00';

    $text_array = array();
    $text_array['module_version'] = $version;


    // Start building the "where" clause for the sql query to find the hosts to display
    $where = "";
    $and = "";
    $orderby = "";
    $from = 'hosts h';

    // enable or disable wildcards
    $wildcard = '%';
    if (isset($options['nowildcard'])) $wildcard = '';


    // DISPLAY ALL
    // MP: I dont think this is used.. remove it if you can
    if (isset($options['all_flag'])) {
        $where .= $and . "h.id > 0";
        $and = " AND ";
    }

    // HOST ID
    if (isset($options['host_id'])) {
        $where .= $and . "h.id = " . $onadb->qstr($options['host_id']);
        $and = " AND ";
    }

    // DEVICE ID
    if (isset($options['device_id'])) {
        $where .= $and . "h.device_id = " . $onadb->qstr($options['device_id']);
        $and = " AND ";
    }


    // HOSTNAME
    if (isset($options['hostname'])) {
        // Find the domain name piece of the hostname assuming it was passed in as an fqdn.
        // FIXME: MP this was taken from the ona_find_domain function. make that function have the option
        // to NOT return a default domain.

        // lets test out if it has a / in it to strip the view name portion
        $view['id'] = 0;
        if (strstr($options['hostname'],'/')) {
            list($dnsview,$options['hostname']) = explode('/', $options['hostname']);
            list($status, $viewrows, $view) = db_get_record($onadb, 'dns_views', array('name' => strtoupper($dnsview)));
            if(!$viewrows) $view['id'] = 0;
        }

        // Split it up on '.' and put it in an array backwards
        $parts = array_reverse(explode('.', $options['hostname']));

        // Find the domain name that best matches
        $name = '';
        $domain = array();
        foreach ($parts as $part) {
            if (!$rows) {
                if (!$name) $name = $part;
                else $name = "{$part}.{$name}";
                list($status, $rows, $record) = ona_get_domain_record(array('name' => $name));
                if ($rows)
                    $domain = $record;
            }
            else {
                list($status, $rows, $record) = ona_get_domain_record(array('name' => $part, 'parent_id' => $domain['id']));
                if ($rows)
                    $domain = $record;
            }
        }

        $withdomain = '';
        $hostname = $options['hostname'];
        // If you found a domain in the query, add it to the search, and strip the domain from the host portion.
        if (array_key_exists('id', $domain) and !$options['domain']) {
            $withdomain = "AND b.domain_id = {$domain['id']}";
            // Now find what the host part of $search is
            $hostname = str_replace(".{$domain['fqdn']}", '', $options['hostname']);
        }
        // If we have a hostname and a domain name then use them both
        if (isset($options['domain'])) {
            list($status, $rows, $record) = ona_find_domain($options['domain']);
            if ($record['id']) {
                $withdomain = "AND b.domain_id = {$record['id']}";
            }
            // Now find what the host part of $search is
            $hostname = trim($options['hostname']);
        }


        // MP: Doing the many select IN statements was too slow.. I did this kludge:
        //  1. get a list of all the interfaces
        //  2. loop through the array and build a list of comma delimited host_ids to use in the final select
        list($status, $rows, $tmp) = db_get_records($onadb, 'interfaces a, dns b', "a.id = b.interface_id and b.name LIKE '{$wildcard}{$hostname}{$wildcard}' {$withdomain}");
        $commait = '';
        $hostids = '';
        foreach ($tmp as $item) {
            $hostids .= $commait.$item['host_id'];
            $commait = ',';
        }

        // Just look for the host itself
        list($status, $rows, $r) = ona_find_host($options['hostname']);
        if ($rows) $hostids  .= ','.$r['id'];

        // MP: this is the old, slow query for reference.
        //
        // TODO: MP this seems to be kinda slow (gee I wonder why).. look into speeding things up somehow.
        //       This also does not search for CNAME records etc.  only things with interface_id.. how to fix that issue.......?
        //        $where .= $and . "id IN (select host_id from interfaces where id in (SELECT interface_id " .
        //                                "  FROM dns " .
        //                                "  WHERE name LIKE '%{$hostname}%' {$withdomain} ))";

        // Trim off extra commas
        $hostids = trim($hostids, ",");
        // If we got a list of hostids from interfaces then use them
        if ($hostids) {
            $idqry = "h.id IN ($hostids)";
        }
        // Otherwise assume we found nothing specific and should return all rows.
        else {
            $idqry = "";
        }

        $where .= $and . $idqry;
        $and = " AND ";

    }


    // DOMAIN
    if (isset($options['domain']) and !isset($options['hostname'])) {
        // FIXME: does this clause work correctly?
        printmsg("FIXME: => Does \$options['domain'] work correctly in list_hosts.inc.php?", 2);
        // Find the domain name piece of the hostname.
        // FIXME: MP this was taken from the ona_find_domain function. make that function have the option
        // to NOT return a default domain.

        // Split it up on '.' and put it in an array backwards
        $parts = array_reverse(explode('.', $options['domain']));

        // Find the domain name that best matches
        $name = '';
        $domain = array();
        foreach ($parts as $part) {
            if (!$rows) {
                if (!$name) $name = $part;
                else $name = "{$part}.{$name}";
                list($status, $rows, $record) = ona_get_domain_record(array('name' => $name));
                if ($rows)
                    $domain = $record;
            }
            else {
                list($status, $rows, $record) = ona_get_domain_record(array('name' => $part, 'parent_id' => $domain['id']));
                if ($rows)
                    $domain = $record;
            }
        }

        if (array_key_exists('id', $domain)) {

            // Crappy way of writing the query but it makes it fast.
            $from = "(
SELECT distinct a.*
from hosts as a, interfaces as i, dns as d
where a.id = i.host_id
and i.id = d.interface_id
and d.domain_id = ". $onadb->qstr($domain['id']). "
) h";

            $and = " AND ";
        }
    }

    // DOMAIN ID
    if (isset($options['domain_id']) and !isset($options['hostname'])) {
        $where .= $and . "h.primary_dns_id IN ( SELECT id " .
                                            "  FROM dns " .
                                            "  WHERE domain_id = " . $onadb->qstr($options['domain_id']) . " )  ";
        $and = " AND ";
    }




    // MAC
    if (isset($options['mac'])) {
        // Clean up the mac address
        $options['mac'] = strtoupper($options['mac']);
        $options['mac'] = preg_replace('/[^%0-9A-F]/', '', $options['mac']);

        // We do a sub-select to find interface id's that match
        $where .= $and . "h.id IN ( SELECT host_id " .
                         "        FROM interfaces " .
                         "        WHERE mac_addr LIKE " . $onadb->qstr($wildcard.$options['mac'].$wildcard) . " ) ";
        $and = " AND ";

    }


    // IP ADDRESS
    $ip = $ip_end = '';
    if (isset($options['ip'])) {
        // Build $ip and $ip_end from $options['ip'] and $options['ip_thru']
        $ip = ip_complete($options['ip'], '0');
        if (isset($options['ip_thru'])) { $ip_end = ip_complete($options['ip_thru'], '255'); }
        else { $ip_end = ip_complete($options['ip'], '255'); }

        // Find out if $ip and $ip_end are valid
        $ip = ip_mangle($ip, 'numeric');
        $ip_end = ip_mangle($ip_end, 'numeric');
        if ($ip != -1 and $ip_end != -1) {
            // We do a sub-select to find interface id's between the specified ranges
            $where .= $and . "h.id IN ( SELECT host_id " .
                             "        FROM interfaces " .
                             "        WHERE ip_addr >= " . $onadb->qstr($ip) . " AND ip_addr <= " . $onadb->qstr($ip_end) . " )";
            $and = " AND ";
        }
    }


    // NOTES
    if (isset($options['notes'])) {
        $where .= $and . "h.notes LIKE " . $onadb->qstr($wildcard.$options['notes'].$wildcard);
        $and = " AND ";
    }




    // DEVICE MODEL
    if (isset($options['model_id'])) {
        $where .= $and . "h.device_id in (select id from devices where device_type_id in (select id from device_types where model_id = {$options['model_id']}))";
        $and = " AND ";
    }

    if (isset($options['model'])) {
        $where .= $and . "h.device_id in (select id from devices where device_type_id in (select id from device_types where model_id in (select id from models where name like '{$options['model']}')))";
        $and = " AND ";
    }



    // DEVICE TYPE
    if (isset($options['role'])) {
        // Find model_id's that have a device_type_id of $options['role']
        list($status, $rows, $records) =
            db_get_records($onadb,
                           'roles',
                           array('name' => $options['role'])
                          );
        // If there were results, add each one to the $where clause
        if ($rows > 0) {
            $where .= $and . " ( ";
            $and = " AND ";
            $or = "";
            foreach ($records as $record) {
                // Yes this is one freakin nasty query but it works.
                $where .= $or . "h.device_id in (select id from devices where device_type_id in (select id from device_types where role_id = " . $onadb->qstr($record['id']) ."))";
                $or = " OR ";
            }
            $where .= " ) ";
        }
    }


    // DEVICE MANUFACTURER
    if (isset($options['manufacturer'])) {
        // Find model_id's that have a device_type_id of $options['manufacturer']
        if (is_numeric($options['manufacturer'])) {
            list($status, $rows, $records) = db_get_records($onadb, 'models', array('manufacturer_id' => $options['manufacturer']));
        } else {
            list($status, $rows, $manu) = db_get_record($onadb, 'manufacturers', array('name' => $options['manufacturer']));
            list($status, $rows, $records) = db_get_records($onadb, 'models', array('manufacturer_id' => $manu['id']));
        }

        // If there were results, add each one to the $where clause
        if ($rows > 0) {
            $where .= $and . " ( ";
            $and = " AND ";
            $or = "";
            foreach ($records as $record) {
                // Yes this is one freakin nasty query but it works.
                $where .= $or . "h.device_id in (select id from devices where device_type_id in (select id from device_types where model_id = " . $onadb->qstr($record['id']) ."))";
                $or = " OR ";
            }
            $where .= " ) ";
        }
    }

    // tag
    if (isset($options['tag'])) {
        $where .= $and . "h.id in (select reference from tags where type like 'host' and name like " . $onadb->qstr($options['tag']) . ")";
        $and = " AND ";

    }

    // custom attribute type
    if (isset($options['custom_attribute_type'])) {
        $where .= $and . "h.id in (select table_id_ref from custom_attributes where table_name_ref like 'hosts' and custom_attribute_type_id = (SELECT id FROM custom_attribute_types WHERE name = " . $onadb->qstr($options['custom_attribute_type']) . "))";
        $and = " AND ";
        $cavaluetype = "and custom_attribute_type_id = (SELECT id FROM custom_attribute_types WHERE name = " . $onadb->qstr($options['custom_attribute_type']) . ")";

    }

    // custom attribute value
    if (isset($options['ca_value'])) {
        $where .= $and . "h.id in (select table_id_ref from custom_attributes where table_name_ref like 'hosts' {$cavaluetype} and value like " . $onadb->qstr($wildcard.$options['ca_value'].$wildcard) . ")";
        $and = " AND ";
    }

    // LOCATION No.
    if (isset($options['location'])) {
        list($status, $rows, $loc) = ona_find_location($options['location']);
        $where .= $and . "h.device_id in (select id from devices where location_id = " . $onadb->qstr($loc['id']) . ")";
        $and = " AND ";
    }

    // subnet ID
    if (isset($options['subnet_id']) and is_numeric($options['subnet_id'])) {
        // We do a sub-select to find interface id's that match
        $from = "(
SELECT distinct a.*,b.ip_addr
from hosts as a, interfaces as b
where a.id = b.host_id
and b.subnet_id = ". $onadb->qstr($options['subnet_id']). "
order by b.ip_addr) h";

        $and = " AND ";
    }

    // Wild card .. if $while is still empty, add a 'ID > 0' to it so you see everything.
    if ($where == '') {
        $where = 'h.id > 0';
    }


    list ($status, $rows, $hosts) =
        db_get_records(
            $onadb,
            $from,
            $where,
            $orderby
        );


    $i=0;
    foreach ($hosts as $host) {
      // Device record
      list($status, $rows, $device) = ona_get_device_record(array('id' => $host['device_id']));
      if ($rows >= 1) {
          // Fill out some other device info
          list($status, $rows, $dns) = ona_get_dns_record(array('id' => $host['primary_dns_id']));
          list($status, $rows, $device_type) = ona_get_device_type_record(array('id' => $device['device_type_id']));
          list($status, $rows, $role) = ona_get_role_record(array('id' => $device_type['role_id']));
          list($status, $rows, $model) = ona_get_model_record(array('id' => $device_type['model_id']));
          list($status, $rows, $manufacturer) = ona_get_manufacturer_record(array('id' => $model['manufacturer_id']));
          $device['device_type'] = "{$manufacturer['name']}, {$model['name']} ({$role['name']})";

          list($status, $rows, $location) = ona_get_location_record(array('id' => $device['location_id']));

          ksort($location);
          ksort($device);
          $host['location'] = $location;
          $host['device'] = $device;
          $host['fqdn'] = $dns['fqdn'];
          $host['domain'] = $dns['domain_fqdn'];
          $host['hostname'] = $dns['name'];
      }

      unset($host['device']['asset_tag']);
      unset($host['device']['location_id']);
      unset($host['device']['serial_number']);


      // Select just the fields requested
      if (isset($options['fields'])) {
        $fields = explode(',', $options['fields']);
        $host = array_intersect_key($host, array_flip($fields));
      }

      ksort($host);
      $text_array['hosts'][$i]=$host;

      // cleanup some un-used junk
      unset($text_array['hosts'][$i]['network_role_id']);
      unset($text_array['hosts'][$i]['vlan_id']);

      $i++;
    }

    $text_array['count'] = count($hosts);

    // Return the success notice
    return(array(0, $text_array));

}



///////////////////////////////////////////////////////////////////////
//  Function: host_add (string $options='')
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
//  Example: list($status, $result) = host_add('host=test&type=something');
///////////////////////////////////////////////////////////////////////
function host_add($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '1.12';

    printmsg("DEBUG => host_add({$options}) called", 3);

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if ($options['help'] or !($options['host'] and $options['type'] and $options['ip']) ) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOM

host_add-v{$version}
Add a new host

  Synopsis: host_add [KEY=VALUE] ...

  Required:
    host=NAME[.DOMAIN]        Hostname for new DNS record
    type=TYPE or ID           Device/model type or ID
    ip=ADDRESS                IP address (numeric or dotted)

  Optional:
    notes=NOTES               Textual notes
    location=REF              Reference of location
    device=NAME|ID            The device this host is associated with

  Optional, add an interface too:
    mac=ADDRESS               Mac address (most formats are ok)
    name=NAME                 Interface name (i.e. "FastEthernet0/1.100")
    description=TEXT          Brief description of the interface
    addptr=Y|N                Auto add a PTR record for new host/IP (default: Y)


EOM
        ));
    }

    // Sanitize addptr.. set it to Y if it is not set
    $options['addptr'] = sanitize_YN($options['addptr'], 'Y');

    // clean up what is passed in
    $options['ip'] = trim($options['ip']);
    $options['mac'] = trim($options['mac']);
    $options['name'] = trim($options['name']);
    $options['host'] = trim($options['host']);

    // Validate that there isn't already another interface with the same IP address
    list($status, $rows, $interface) = ona_get_interface_record(array('ip_addr' => $options['ip']));
    if ($rows) {
        printmsg("DEBUG => host_add() IP conflict: That IP address (" . ip_mangle($orig_ip,'dotted') . ") is already in use!",3);
        $self['error'] = "ERROR => host_add() IP conflict: That IP address (" . ip_mangle($orig_ip,'dotted') . ") is already in use!";
        return(array(4, $self['error'] . "\n" .
                        "INFO => Conflicting interface record ID: {$interface['id']}\n"));
    }

    // Find the Location ID to use
    if ($options['location']) {
        list($status, $rows, $loc) = ona_find_location($options['location']);
        if ($status or !$rows) {
            printmsg("DEBUG => The location specified, {$options['location']}, does not exist!", 3);
            $self['error'] = "ERROR => The location specified, {$options['location']}, does not exist!";
            return(array(2, "{$self['error']}\n"));
        }
        printmsg("DEBUG => Location selected: {$loc['reference']}, location name: {$loc['name']}", 3);
    } else {
        $loc['id'] = 0;
    }

    // Find the Device Type ID (i.e. Type) to use
    list($status, $rows, $device_type) = ona_find_device_type($options['type']);
    if ($status or $rows != 1 or !$device_type['id']) {
        printmsg("DEBUG => The device type specified, {$options['type']}, does not exist!", 3);
        return(array(3, "ERROR => The device type specified, {$options['type']}, does not exist!\n"));
    }
    printmsg("DEBUG => Device type selected: {$device_type['model_description']} Device ID: {$device_type['id']}", 3);


    // Sanitize "security_level" option
    $options['security_level'] = sanitize_security_level($options['security_level']);
    if ($options['security_level'] == -1) {
        printmsg("DEBUG => Sanitize security level failed either ({$options['security_level']}) is invalid or is higher than user's level!", 3);
        return(array(3, $self['error'] . "\n"));
    }


    // Determine the real hostname to be used --
    // i.e. add .something.com, or find the part of the name provided
    // that will be used as the "domain".  This means testing many
    // domain names against the DB to see what's valid.
    //
    // Find the domain name piece of $search.
    // If we are specifically passing in a domain, use its value.  If we dont have a domain
    // then try to find it in the name that we are setting.
    if($options['domain']) {
        // Find the domain name piece of $search
        list($status, $rows, $domain) = ona_find_domain($options['domain'],0);
    } else {
        list($status, $rows, $domain) = ona_find_domain($options['host'],0);
    }
    if (!isset($domain['id'])) {
        printmsg("ERROR => Unable to determine domain name portion of ({$options['host']})!", 3);
        $self['error'] = "ERROR => Unable to determine domain name portion of ({$options['host']})!";
        return(array(3, $self['error'] . "\n"));
    }

    printmsg("DEBUG => ona_find_domain({$options['host']}) returned: {$domain['fqdn']}", 3);

    // Now find what the host part of $search is
    $hostname = str_replace(".{$domain['fqdn']}", '', $options['host']);

    // Validate that the DNS name has only valid characters in it
    $hostname = sanitize_hostname($hostname);
    if (!$hostname) {
        printmsg("ERROR => Invalid host name ({$options['host']})!", 3);
        $self['error'] = "ERROR => Invalid host name ({$options['host']})!";
        return(array(4, $self['error'] . "\n"));
    }


    // Debugging
    printmsg("DEBUG => Using hostname: {$hostname} Domainname: {$domain['fqdn']}, Domain ID: {$domain['id']}", 3);

    // Validate that there isn't already any dns record named $host['name'] in the domain $host_domain_id.
    $h_status = $h_rows = 0;
    // does the domain $host_domain_id even exist?
    list($d_status, $d_rows, $d_record) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id']));
    if ($d_status or $d_rows) {
        printmsg("DEBUG => The name {$hostname}.{$domain['fqdn']} is already in use, the primary name for a host should be unique!",3);
        $self['error'] = "ERROR => Another DNS record named {$hostname}.{$domain['fqdn']} is already in use, the primary name for a host should be unique!";
        return(array(5, $self['error'] . "\n"));
    }

    // Check permissions
    if (!auth('host_add')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 0);
        return(array(10, $self['error'] . "\n"));
    }

    // Get the next ID for the new host record
    $id = ona_get_next_id('hosts');
    if (!$id) {
        $self['error'] = "ERROR => The ona_get_next_id('hosts') call failed!";
        printmsg($self['error'], 0);
        return(array(7, $self['error'] . "\n"));
    }
    printmsg("DEBUG => ID for new host record: $id", 3);

    // Get the next ID for the new device record or use the one passed in the CLI
    if (!$options['device']) {
        $host['device_id'] = ona_get_next_id('devices');
        if (!$id) {
            $self['error'] = "ERROR => The ona_get_next_id('device') call failed!";
            printmsg($self['error'], 0);
            return(array(7, $self['error'] . "\n"));
        }
        printmsg("DEBUG => ID for new device record: $id", 3);
    } else {
        list($status, $rows, $devid) = ona_find_device($options['device']);
        if (!$rows) {
            printmsg("DEBUG => The device specified, {$options['device']}, does not exist!",3);
            $self['error'] = "ERROR => The device specified, {$options['device']}, does not exist!";
            return(array(7, $self['error'] . "\n"));
        }
        $host['device_id'] = $devid['id'];
    }


    // There is an issue with escaping '=' and '&'.  We need to avoid adding escape characters
    $options['notes'] = str_replace('\\=','=',$options['notes']);
    $options['notes'] = str_replace('\\&','&',$options['notes']);

    // Add the device record
    // FIXME: (MP) quick add of device record. more detail should be looked at here to ensure it is done right
// FIXME: MP this should use the run_module('device_add')!!! when it is ready
    list($status, $rows) = db_insert_record(
        $onadb,
        'devices',
        array(
            'id'                => $host['device_id'],
            'device_type_id'    => $device_type['id'],
            'location_id'       => $loc['id'],
            'primary_host_id'   => $id
            // FIXME: (MP) add in the asset tag and serial number stuff too
        )
    );
    if ($status or !$rows) {
        $self['error'] = "ERROR => host_add() SQL Query failed adding device: " . $self['error'];
        printmsg($self['error'], 0);
        return(array(6, $self['error'] . "\n"));
    }

    // Add the host record
    // FIXME: (PK) Needs to insert to multiple tables for e.g. name and domain_id.
    list($status, $rows) = db_insert_record(
        $onadb,
        'hosts',
        array(
            'id'                   => $id,
            'parent_id'            => 0,  // set to 0 for now until feature is implemented TODO
            'primary_dns_id'       => 0,  // Unknown at this point.. needs to be added afterwards
            'device_id'            => $host['device_id'],
            'notes'                => $options['notes']
        )
    );
    if ($status or !$rows) {
        $self['error'] = "ERROR => host_add() SQL Query failed adding host: " . $self['error'];
        printmsg($self['error'], 0);
        return(array(6, $self['error'] . "\n"));
    }

    // Else start an output message
    $text = "INFO => Host ADDED: {$hostname}.{$domain['fqdn']}";
    printmsg($text,0);
    $text .= "\n";


    // We must always have an IP now to add an interface, call that module now:
    // since we have no name yet, we need to use the ID of the new host as the host option for the following module calls
    $options['host'] = $id;
    // for annoying reasons we need to keep track of what was set first
    $options['addptrsave'] = $options['addptr'];
    // Interface adds can add PTR records, lets let the A record add that happens next add it instead.
    $options['addptr'] = '0';

    printmsg("DEBUG => host_add() ({$hostname}.{$domain['fqdn']}) calling interface_add() ({$options['ip']})", 3);
    list($status, $output) = run_module('interface_add', $options);
    if ($status)
        return(array($status, $output));
    $text .= $output;

    // Find the interface_id for the interface we just added
    list($status, $rows, $int) = ona_find_interface($options['ip']);

    // make the dns record type A
    $options['type'] = 'A';
    // FIXME: MP I had to force the name value here.  name is comming in as the interface name.  this is nasty!
    $options['name'] = "{$hostname}.{$domain['fqdn']}";
    $options['domain'] = $domain['fqdn'];
    // And we will go ahead and auto add the ptr.  the user can remove it later if they dont want it.  FIXME: maybe create a checkbox on the host edit
    $options['addptr'] = $options['addptrsave'];

    // Add the DNS entry with the IP address etc
    printmsg("DEBUG => host_add() ({$hostname}.{$domain['fqdn']}) calling dns_record_add() ({$options['ip']})", 3);
    list($status, $output) = run_module('dns_record_add', $options);
    if ($status)
        return(array($status, $output));
    $text .= $output;

    // find the dns record we just added so we can use its ID as the primary_dns_id for the host.
    list($status, $rows, $dnsrecord) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'], 'interface_id' => $int['id'], 'type' => 'A'));

    // Set the primary_dns_id to the dns record that was just added
    list($status, $rows) = db_update_record($onadb, 'hosts', array('id' => $id), array('primary_dns_id' => $dnsrecord['id']));
    if ($status or !$rows) {
        $self['error'] = "ERROR => host_add() SQL Query failed to update primary_dns_id for host: " . $self['error'];
        printmsg($self['error'], 0);
        return(array(8, $self['error'] . "\n"));
    }

    return(array(0, $text));

}











///////////////////////////////////////////////////////////////////////
//  Function: host_modify (string $options='')
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
//  Example: list($status, $result) = host_modify('FIXME: blah blah blah');
///////////////////////////////////////////////////////////////////////
function host_modify($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '1.07';

    printmsg("DEBUG => host_modify({$options}) called", 3);

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if ($options['help'] or
       (!$options['interface'] and !$options['host']) or
       (!$options['set_host'] and
        !$options['set_type'] and
        !$options['set_location'] and
        !$options['set_notes']
       ) ) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOM

host_modify-v{$version}
Modify a host record

  Synopsis: host_modify [KEY=VALUE] ...

  Where:
    host=NAME[.DOMAIN] or ID  Select host by hostname or ID
      or
    interface=[ID|IP|MAC]     Select host by IP or MAC

  Update:
    set_type=TYPE or ID       Change device/model type or ID
    set_notes=NOTES           Change the textual notes
    set_location=REF          Reference for location
    set_device=NAME|ID        Name or ID of the device this host is associated with

EOM
        ));
    }

    // clean up what is passed in
    $options['interface'] = trim($options['interface']);
    $options['host'] = trim($options['host']);

    //
    // Find the host record we're modifying
    //

    // If they provided a hostname / ID let's look it up
    if ($options['host'])
        list($status, $rows, $host) = ona_find_host($options['host']);

    // If they provided a interface ID, IP address, interface name, or MAC address
    else if ($options['interface']) {
        // Find an interface record by something in that interface's record
        list($status, $rows, $interface) = ona_find_interface($options['interface']);
        if ($status or !$rows) {
            printmsg("DEBUG => Interface not found ({$options['interface']})!",3);
            $self['error'] = "ERROR => Interface not found ({$options['interface']})!";
            return(array(4, $self['error'] . "\n"));
        }
        // Load the associated host record
        list($status, $rows, $host) = ona_get_host_record(array('id' => $interface['host_id']));
    }

    // If we didn't get a record then exit
    if (!$host['id']) {
        printmsg("DEBUG => Host not found ({$options['host']})!",3);
        $self['error'] = "ERROR => Host not found ({$options['host']})!";
        return(array(4, $self['error'] . "\n"));
    }

    // Get related Device record info
    list($status, $rows, $device) = ona_get_device_record(array('id' => $host['device_id']));


    //
    // Define the records we're updating
    //

    // This variable will contain the updated info we'll insert into the DB
    $SET = array();

    // Set options['set_type']?
    if ($options['set_type']) {
        // Find the Device Type ID (i.e. Type) to use
        list($status, $rows, $device_type) = ona_find_device_type($options['set_type']);
        if ($status or $rows != 1 or !$device_type['id']) {
            printmsg("DEBUG => The device type specified, {$options['set_type']}, does not exist!",3);
            $self['error'] = "ERROR => The device type specified, {$options['set_type']}, does not exist!";
            return(array(6, $self['error'] . "\n"));
        }
        printmsg("DEBUG => Device type ID: {$device_type['id']}", 3);

        // Everything looks ok, add it to $SET if it changed...
        if ($device['device_type_id'] != $device_type['id'])
            $SET_DEV['device_type_id'] = $device_type['id'];
    }

    // Set options['set_notes'] (it can be a null string!)
    if (array_key_exists('set_notes', $options)) {
        // There is an issue with escaping '=' and '&'.  We need to avoid adding escape characters
        $options['set_notes'] = str_replace('\\=','=',$options['set_notes']);
        $options['set_notes'] = str_replace('\\&','&',$options['set_notes']);
        // If it changed...
        if ($host['notes'] != $options['set_notes'])
            $SET['notes'] = $options['set_notes'];
    }

    if (array_key_exists('set_device', $options)) {
        list($status, $rows, $devid) = ona_find_device($options['set_device']);
        if (!$rows) {
            printmsg("DEBUG => The device specified, {$options['set_device']}, does not exist!",3);
            $self['error'] = "ERROR => The device specified, {$options['set_device']}, does not exist!";
            return(array(7, $self['error'] . "\n"));
        }

        // set the device id
        if ($host['device_id'] != $devid['id']) $SET['device_id'] = $devid['id'];
    }


    if (array_key_exists('set_location', $options)) {
        if (!$options['set_location'])
            unset($SET_DEV['location_id']);
        else {
            list($status, $rows, $loc) = ona_find_location($options['set_location']);
            if (!$rows) {
                printmsg("DEBUG => The location specified, {$options['set_location']}, does not exist!",3);
                $self['error'] = "ERROR => The location specified, {$options['set_location']}, does not exist!";
                return(array(7, $self['error'] . "\n"));
            }
            // If location is changing, then set the variable
            if ($device['location_id'] != $loc['id']) $SET_DEV['location_id'] = $loc['id'];
        }
    }

    // Check permissions
    if (!auth('host_modify')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 0);
        return(array(10, $self['error'] . "\n"));
    }

    // Get the host record before updating (logging)
    $original_host = $host;

    // Update the host record if necessary
    if(count($SET) > 0) {
        list($status, $rows) = db_update_record($onadb, 'hosts', array('id' => $host['id']), $SET);
        if ($status or !$rows) {
            $self['error'] = "ERROR => host_modify() SQL Query failed for host: " . $self['error'];
            printmsg($self['error'], 0);
            return(array(8, $self['error'] . "\n"));
        }
    }

    // Update device table if necessary
    if(count($SET_DEV) > 0) {
        list($status, $rows) = db_update_record($onadb, 'devices', array('id' => $host['device_id']), $SET_DEV);
        if ($status or !$rows) {
            $self['error'] = "ERROR => host_modify() SQL Query failed for device type: " . $self['error'];
            printmsg($self['error'], 0);
            return(array(9, $self['error'] . "\n"));
        }
    }

    // Get the host record after updating (logging)
    list($status, $rows, $new_host) = ona_get_host_record(array('id' => $host['id']));

    // Return the success notice
    $self['error'] = "INFO => Host UPDATED:{$host['id']}: {$new_host['fqdn']}";

    $log_msg = "INFO => Host UPDATED:{$host['id']}: ";
    $more="";
    foreach(array_keys($host) as $key) {
        if($host[$key] != $new_host[$key]) {
            $log_msg .= "{$more}{$key}: {$host[$key]} => {$new_host[$key]}";
            $more= "; ";
        }
    }

    // only print to logfile if a change has been made to the record
    if($more != '') {
        printmsg($self['error'], 0);
        printmsg($log_msg, 0);
    }

    return(array(0, $self['error'] . "\n"));
}










///////////////////////////////////////////////////////////////////////
//  Function: host_del (string $options='')
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
//  Example: list($status, $result) = host_del('host=test');
///////////////////////////////////////////////////////////////////////
function host_del($options="") {
    global $conf, $self, $onadb;
    printmsg("DEBUG => host_del({$options}) called", 3);

    // Version - UPDATE on every edit!
    $version = '1.19';

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Sanitize options[commit] (default is no)
    $options['commit'] = sanitize_YN($options['commit'], 'N');

    // Return the usage summary if we need to
    if ($options['help'] or !$options['host']) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOM

host_del-v{$version}
Deletes a host, and all related records from the database

  Synopsis: host_del [KEY=VALUE] ...

  Required:
    host=NAME[.DOMAIN] or ID      Hostname or ID of the host to delete

  Optional:
    commit=[yes|no]               Commit db transaction (no)

  Notes:
    * A host won't be deleted if it has config text records
    * A host won't be deleted if it's configured as a dns or dhcp server


EOM
        ));
    }


    // Find the host (and domain) record from $options['host']
    list($status, $rows, $host) = ona_find_host($options['host']);
    printmsg("DEBUG => host_del() Host: {$host['fqdn']} ({$host['id']})", 3);
    if (!$host['id']) {
        printmsg("DEBUG => Unknown host: {$host['fqdn']}",3);
        $self['error'] = "ERROR => Unknown host: {$host['fqdn']}";
        return(array(2, $self['error'] . "\n"));
    }


    // Check permissions
    if (!auth('host_del') or !authlvl($host['LVL'])) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 0);
        return(array(10, $self['error'] . "\n"));
    }

    // If "commit" is yes, delete the host
    if ($options['commit'] == 'Y') {
        $text = "";
        $add_to_error = "";
        $add_to_status = 0;

        // SUMMARY:
        //   Don't allow a delete if it is performing server duties
        //   Don't allow a delete if config text entries exist
        //   Delete Interfaces
        //   Delete interface cluster entries
        //   Delete dns records
        //   Delete custom attributes
        //   Delete DHCP entries
        //   Delete device record if it is the last host associated with it.
        //
        // IDEA: If it's the last host in a domain (maybe do the same for or a networks & vlans in the interface delete)
        //       It could just print a notice or something.

        // Check that it is the host is not performing server duties
        // FIXME: MP mostly fixed..needs testing
        $serverrow = 0;
        // check ALL the places server_id is used and remove the entry from server_b if it is not used
        list($status, $rows, $srecord) = db_get_record($onadb, 'dhcp_server_subnets', array('host_id' => $host['id']));
        if ($rows) $serverrow++;
        list($status, $rows, $srecord) = db_get_record($onadb, 'dhcp_failover_groups', array('primary_server_id' => $host['id']));
        if ($rows) $serverrow++;
        list($status, $rows, $srecord) = db_get_record($onadb, 'dhcp_failover_groups', array('secondary_server_id' => $host['id']));
        if ($rows) $serverrow++;
        if ($serverrow > 0) {
            printmsg("DEBUG => Host ({$host['fqdn']}) cannot be deleted, it is performing duties as a DHCP server!",3);
            $self['error'] = "ERROR => Host ({$host['fqdn']}) cannot be deleted, it is performing duties as a DHCP server!";
            return(array(5, $self['error'] . "\n"));
        }


        // Check if host is a dns server
        $serverrow = 0;
        list($status, $rows, $srecord) = db_get_record($onadb, 'dns_server_domains', array('host_id' => $host['id']));
        if ($rows) $serverrow++;

        if ($serverrow > 0) {
            printmsg("DEBUG => Host ({$host['fqdn']}) cannot be deleted, it is performing duties as a DNS server!",3);
            $self['error'] = "ERROR => Host ({$host['fqdn']}) cannot be deleted, it is performing duties as a DNS server!";
            return(array(5, $self['error'] . "\n"));
        }

        // Display an error if it has any entries in configurations
        list($status, $rows, $server) = db_get_record($onadb, 'configurations', array('host_id' => $host['id']));
        if ($rows) {
            printmsg("DEBUG => Host ({$host['fqdn']}) cannot be deleted, it has config archives!",3);
            $self['error'] = "ERROR => Host ({$host['fqdn']}) cannot be deleted, it has config archives!";
            return(array(5, $self['error'] . "\n"));
        }


        // Delete interface(s)
        // get list for logging
        $clustcount = 0;
        $dnscount = 0;
        list($status, $rows, $interfaces) = db_get_records($onadb, 'interfaces', array('host_id' => $host['id']));


        // Cant delete if one of the interfaces is primary for a cluster
        foreach ($interfaces as $int) {
            list($status, $rows, $records) = db_get_records($onadb, 'interface_clusters', array('interface_id' => $int['id']));
            $clustcount = $clustcount + $rows;
        }

        if ($clustcount) {
            $self['error'] = "ERROR => host_del() An interface on this host is primary for some interface shares, delete the share or move the interface first.";
            printmsg($self['error'],0);
            return(array(5, $self['error'] . "\n"));
        }

        // do the interface_cluster delete.  This just removes this host from the cluster, not the whole cluster itself
        // It will error out as well if this interface is the primary in the cluster
        list($status, $rows) = db_delete_records($onadb, 'interface_clusters', array('host_id' => $host['id']));
        if ($status) {
            $self['error'] = "ERROR => host_del() interface_cluster delete SQL Query failed: {$self['error']}";
            printmsg($self['error'],0);
            return(array(5, $self['error'] . "\n"));
        }
        // log deletions
        printmsg("INFO => {$rows} Shared interface(s) DELETED from {$host['fqdn']}",0);
        $add_to_error .= "INFO => {$rows} Shared interface(s) DELETED from {$host['fqdn']}\n";


        // Delete each DNS record associated with this hosts interfaces.
//         foreach ($interfaces as $int) {
//             // Loop through each dns record associated with this interface.
//             list($status, $rows, $records) = db_get_records($onadb, 'dns', array('interface_id' => $int['id']));
//             if ($rows) {
//                 foreach($records as $record) {
//                     // Run the module
//                     list($status, $output) = run_module('dns_record_del', array('name' => $record['id'], 'type' => $record['type'], 'commit' => 'Y', 'delete_by_module' => 'Y'));
//                     $add_to_error .= $output;
//                     $add_to_status = $add_to_status + $status;
//                 }
//             }
//         }

        // Delete messages
        // get list for logging
        list($status, $rows, $records) = db_get_records($onadb, 'messages', array('table_name_ref' => 'hosts','table_id_ref' => $host['id']));
        // do the delete
        list($status, $rows) = db_delete_records($onadb, 'messages', array('table_name_ref' => 'hosts','table_id_ref' => $host['id']));
        if ($status) {
            $self['error'] = "ERROR => host_del() message delete SQL Query failed: {$self['error']}";
            printmsg($self['error'],0);
            return(array(5, $self['error'] . "\n"));
        }
        // log deletions
        printmsg("INFO => {$rows} Message(s) DELETED from {$host['fqdn']}",0);
        $add_to_error .= "INFO => {$rows} Message(s) DELETED from {$host['fqdn']}\n";


        // Delete the interfaces.. this should delete dns names and other things associated with interfaces.. 
        foreach ($interfaces as $record) {
            // Run the module
            list($status, $output) = run_module('interface_del', array('interface' => $record['id'], 'commit' => 'on', 'delete_by_module' => 'Y'));
            $add_to_error .= $output;
            $add_to_status = $add_to_status + $status;
        }




        // Delete device record
        // Count how many hosts use this same device
        list($status, $rows, $records) = db_get_records($onadb, 'hosts', array('device_id' => $host['device_id']));
        // if device count is just 1 do the delete
        if ($rows == 1) {
            list($status, $rows) = db_delete_records($onadb, 'devices', array('id' => $host['device_id']));
            if ($status) {
                $self['error'] = "ERROR => host_del() device delete SQL Query failed: {$self['error']}";
                printmsg($self['error'],0);
                return(array(5, $add_to_error . $self['error'] . "\n"));
            }
            // log deletions
            printmsg("INFO => Device record DELETED: [{$record['id']}] no remaining hosts using this device",0);
        } else {
            printmsg("INFO => Device record NOT DELETED: [{$record['id']}] there are other hosts using this device.",1);
        }


        // Delete tag entries
        list($status, $rows, $records) = db_get_records($onadb, 'tags', array('type' => 'host', 'reference' => $host['id']));
        $log=array(); $i=0;
        foreach ($records as $record) {
            $log[$i]= "INFO => Tag DELETED: {$record['name']} from {$host['fqdn']}";
            $i++;
        }
        //do the delete
        list($status, $rows) = db_delete_records($onadb, 'tags', array('type' => 'host', 'reference' => $host['id']));
        if ($status) {
            $self['error'] = "ERROR => host_del() Tag delete SQL Query failed: {$self['error']}";
            printmsg($self['error'],0);
            return(array(5, $add_to_error . $self['error'] . "\n"));
        }
        //log deletions
        foreach($log as $log_msg) {
            printmsg($log_msg,0);
            $add_to_error .= $log_msg . "\n";
        }

        // Delete custom attribute entries
        // get list for logging
        list($status, $rows, $records) = db_get_records($onadb, 'custom_attributes', array('table_name_ref' => 'hosts', 'table_id_ref' => $host['id']));
        $log=array(); $i=0;
        foreach ($records as $record) {
            list($status, $rows, $ca) = ona_get_custom_attribute_record(array('id' => $record['id']));
            $log[$i]= "INFO => Custom Attribute DELETED: {$ca['name']} ({$ca['value']}) from {$host['fqdn']}";
            $i++;
        }

        //do the delete
        list($status, $rows) = db_delete_records($onadb, 'custom_attributes', array('table_name_ref' => 'hosts', 'table_id_ref' => $host['id']));
        if ($status) {
            $self['error'] = "ERROR => host_del() Custom attribute delete SQL Query failed: {$self['error']}";
            printmsg($self['error'],0);
            return(array(5, $add_to_error . $self['error'] . "\n"));
        }

        //log deletions
        foreach($log as $log_msg) {
            printmsg($log_msg,0);
            $add_to_error .= $log_msg . "\n";
        }




        // Delete DHCP options
        // get list for logging
        list($status, $rows, $records) = db_get_records($onadb, 'dhcp_option_entries', array('host_id' => $host['id']));
        $log=array(); $i=0;
        foreach ($records as $record) {
            list($status, $rows, $dhcp) = ona_get_dhcp_option_entry_record(array('id' => $record['id']));
            $log[$i]= "INFO => DHCP entry DELETED: {$dhcp['display_name']}={$dhcp['value']} from {$host['fqdn']}";
            $i++;
        }
        // do the delete
        list($status, $rows) = db_delete_records($onadb, 'dhcp_option_entries', array('host_id' => $host['id']));
        if ($status) {
            $self['error'] = "ERROR => host_del() DHCP option entry delete SQL Query failed: {$self['error']}";
            printmsg($self['error'],0);
            return(array(5, $add_to_error . $self['error'] . "\n"));
        }
        // log deletions
        foreach($log as $log_msg) {
            printmsg($log_msg,0);
            $add_to_error .= $log_msg . "\n";
        }

        // Delete the host
        list($status, $rows) = db_delete_records($onadb, 'hosts', array('id' => $host['id']));
        if ($status) {
            $self['error'] = "ERROR => host_del() host delete SQL Query failed: {$self['error']}";
            printmsg($self['error'],0);
            return(array(5, $add_to_error . $self['error'] . "\n"));
        }

        // Return the success notice
        if ($add_to_status == 0) $self['error'] = "INFO => Host DELETED: {$host['fqdn']}";
        printmsg($self['error'], 0);
        return(array($add_to_status, $add_to_error . $self['error'] . "\n"));
    }


    //
    // We are just displaying records that would have been deleted
    //

    // SUMMARY:
    //   Display a warning if it is a server
    //   Display a warning if it has config text entries
    //   Display Interfaces
    //   Display dns records
    //   Display custom attributes
    //   Display DHCP entries

    // Otherwise just display the host record for the host we would have deleted
    $text = "Record(s) NOT DELETED (see \"commit\" option)\n" .
            "Displaying record(s) that would have been deleted:\n";

    // Display a warning if host is performing server duties
    list($status, $rows, $srecord) = db_get_record($onadb, 'dhcp_server_subnets', array('host_id' => $host['id']));
    if ($rows) {
        $text .= "\nWARNING!  This host is a DHCP server for {$rows} subnet(s)\n";
    }
    list($status, $rows, $srecord) = db_get_record($onadb, 'dns_server_domains', array('host_id' => $host['id']));
    if ($rows) {
        $text .= "\nWARNING!  This host is a DNS server for one or more domains!\n";
    }
    list($status, $rows, $srecord) = db_get_record($onadb, 'dhcp_failover_groups', array('primary_server_id' => $host['id']));
    if ($rows) {
        $text .= "\nWARNING!  This host is a server that is primary in a DHCP failover group\n";
    }
    list($status, $rows, $srecord) = db_get_record($onadb, 'dhcp_failover_groups', array('secondary_server_id' => $host['id']));
    if ($rows) {
        $text .= "\nWARNING!  This host is a server that is secondary in a DHCP failover group\n";
    }

    // Display a warning if it has any configurations
    list($status, $rows, $server) = db_get_record($onadb, 'configurations', array('host_id' => $host['id']));
    if ($rows)
        $text .= "\nWARNING!  Host can not be deleted, it has config archives!\n";

    if ($rows)
        $text .= "\nWARNING!  Host will NOT be deleted, due to previous warnings!\n";

    // Display the Host's complete record
    list($status, $tmp) = host_display("host={$host['id']}&verbose=N");
    $text .= "\n" . $tmp;

    // Display count of messages
    list($status, $rows, $records) = db_get_records($onadb, 'messages', array('table_name_ref' => 'hosts','table_id_ref' => $host['id']));
    if ($rows) $text .= "\nASSOCIATED MESSAGE RECORDS ({$rows}):\n";

    // Display associated interface(s)
    list($status, $int_rows, $interfaces) = db_get_records($onadb, 'interfaces', array('host_id' => $host['id']));
    // show the dns records associated
    foreach ($interfaces as $record) {
        list($status, $rows, $dnsrec) = db_get_records($onadb, 'dns', array('interface_id' => $record['id']));
        if ($rows) {
            $text .= "\nASSOCIATED DNS RECORDS ({$rows}) ON INTERFACE (" . ip_mangle($record['ip_addr'], 'dotted') . "):\n";
            foreach ($dnsrec as $rec) {
                // show AAAA or A type as needed
                if ($record['ip_addr'] > 4294967295 and $rec['type'] == 'A') $rec['type'] = 'AAAA';
                $text .= "  TYPE: [ID:{$rec['id']}] {$rec['type']}, {$rec['name']} -> " . ip_mangle($record['ip_addr'], 'dotted') . "\n";
            }
        }
    }

    if ($int_rows) $text .= "\nASSOCIATED INTERFACE RECORDS ({$int_rows}):\n";
    foreach ($interfaces as $record) {
        $text .= "  [ID:{$record['id']}] " . ip_mangle($record['ip_addr'], 'dotted') . "\n";
    }

    // Display associated interface_clusters(s)
    list($status, $clust_rows, $interfaceclusters) = db_get_records($onadb, 'interface_clusters', array('host_id' => $host['id']));

    if ($clust_rows) $text .= "\nASSOCIATED SHARED INTERFACE RECORDS ({$clust_rows}):\n";
    foreach ($interfaceclusters as $record) {
        list($status, $rows, $int) = ona_get_interface_record(array('id' => $record['interface_id']));
        $text .= "  [ID:{$int['id']}] {$int['ip_addr_text']}\n";
    }

    // Display associated tags
    list($status, $rows, $records) = db_get_records($onadb, 'tags', array('type' => 'host', 'reference' => $host['id']));
    if ($rows) $text .= "\nASSOCIATED TAG RECORDS ({$rows}):\n";
    foreach ($records as $record) {
        $text .= "  {$record['name']}\n";
    }

    // Display associated custom attributes
    list($status, $rows, $records) = db_get_records($onadb, 'custom_attributes', array('table_name_ref' => 'hosts', 'table_id_ref' => $host['id']));
    if ($rows) $text .= "\nASSOCIATED CUSTOM ATTRIBUTE RECORDS ({$rows}):\n";
    foreach ($records as $record) {
        list($status, $rows, $ca) = ona_get_custom_attribute_record(array('id' => $record['id']));
        $text .= "  {$ca['name']} => {$ca['value']}\n";
    }

    // Display associated DHCP entries
    list($status, $rows, $records) = db_get_records($onadb, 'dhcp_option_entries', array('host_id' => $host['id']));
    if ($rows) $text .= "\nASSOCIATED DHCP OPTION RECORDS ({$rows}):\n";
    foreach ($records as $record) {
        list($status, $rows, $dhcp) = ona_get_dhcp_option_entry_record(array('id' => $record['id']));
        $text .= "  {$dhcp['display_name']} => {$dhcp['value']}\n";
    }



    return(array(7, $text));
}











///////////////////////////////////////////////////////////////////////
//  Function: host_display (string $options='')
//
//  $options = key=value pairs of options for this function.
//             multiple sets of key=value pairs should be separated
//             by an "&" symbol.
//
//  Input Options:
//    host=HOSTNAME[.DOMAIN] or ID
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = host_display('host=test');
///////////////////////////////////////////////////////////////////////
function host_display($options="") {
    global $conf, $self, $onadb;

    $text_array = array();

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Sanitize options[verbose] (default is yes)
    if (isset($options['verbose']))
      $options['verbose'] = sanitize_YN($options['verbose'], 'Y');

    // Return the usage summary if we need to
    if (!isset($options['host']) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error'] ));
    }


    // Find the host (and domain) record from $options['host']
    list($status, $rows, $host) = ona_find_host($options['host']);
    printmsg("Host: {$host['fqdn']}", 'debug');
    if (!$host['id']) {
        $self['error'] = "Unknown host: {$options['host']}";
        printmsg($self['error'], 'error');
        return(array(2, $self['error']));
    }


    // If 'verbose' is enabled, grab some additional info to display
    if ($options['verbose'] == 'Y') {

// TODO: if it is a nat interface, maybe process that IP and make it visible?

        // Interface record(s)
        $i = 0;
        do {
            list($status, $rows, $interface) = ona_get_interface_record(array('host_id' => $host['id']));
            if ($rows == 0) { break; }
            ksort($interface);
            $i++;
            $host['interfaces'][$i] = $interface;
            unset($host['interfaces'][$i]['host_id']);
        } while ($i < $rows);

        $host['interface_count'] = $rows;

        // Device record
        list($status, $rows, $device) = ona_get_device_record(array('id' => $host['device_id']));
        if ($rows >= 1) {
            // Fill out some other device info
            list($status, $rows, $device_type) = ona_get_device_type_record(array('id' => $device['device_type_id']));
            list($status, $rows, $role) = ona_get_role_record(array('id' => $device_type['role_id']));
            list($status, $rows, $model) = ona_get_model_record(array('id' => $device_type['model_id']));
            list($status, $rows, $manufacturer) = ona_get_manufacturer_record(array('id' => $model['manufacturer_id']));
            $device['device_type'] = "{$manufacturer['name']}, {$model['name']} ({$role['name']})";

            list($status, $rows, $location) = ona_get_location_record(array('id' => $device['location_id']));

            $host['location'] = $location;
            $host['device'] = $device;
        }

        // Tag records
        list($status, $rows, $tags) = db_get_records($onadb, 'tags', array('type' => 'host', 'reference' => $host['id']));
        if ($rows) {
            foreach ($tags as $tag) {
                $host['tags'][] = $tag['name'];
            }
        }

    }

    // Cleanup unused info
    unset($host['device_id']);
    unset($host['device']['asset_tag']);
    unset($host['device']['location_id']);
    unset($host['device']['serial_number']);

    // Select just the fields requested
    if (isset($options['fields'])) {
      $fields = explode(',', $options['fields']);
      $host = array_intersect_key($host, array_flip($fields));
    }

    ksort($host);
    $text_array['hosts'][0] = $host;

    // Return the success notice
    return(array(0, $text_array));

}
