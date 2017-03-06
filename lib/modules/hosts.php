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
    $wildcard = '';
   # if (isset($options['nowildcard'])) $wildcard = '';


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
        $rows = 0;
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
        if (array_key_exists('id', $domain) and !isset($options['domain'])) {
            $withdomain = "AND b.domain_id = {$domain['id']}";
            // Now find what the host part of $search is
            $hostname = str_replace(".{$domain['fqdn']}", '', $options['hostname']);
        }
        // If we have a hostname and a domain name then use them both
        if (isset($options['domain'])) {
            list($status, $rows, $record) = ona_find_domain($options['domain']);
            if ($record['id']) {
              $withdomain = "AND b.domain_id = {$record['id']}";
              // Now find what the host part of $search is
              $hostname = str_replace(".{$record['fqdn']}", '', $options['hostname']);
            }
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

        // If it has dots in it like an FQDN, Just look for the single host itself
        if (strpos($options['hostname'], '.') === true) {
          list($status, $rows, $r) = ona_find_host($options['hostname']);
          if ($rows) $hostids  .= ','.$r['id'];
        }

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
        // Find the domain name piece of the hostname.
        // FIXME: MP this was taken from the ona_find_domain function. make that function have the option
        // to NOT return a default domain.

        // Split it up on '.' and put it in an array backwards
        $parts = array_reverse(explode('.', $options['domain']));

        // Find the domain name that best matches
        $name = '';
        $rows = 0;
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
                         "FROM interfaces " .
                         "WHERE mac_addr LIKE " . $onadb->qstr($wildcard.$options['mac'].$wildcard) . " ) ";
        $and = " AND ";

    }


    // IP ADDRESS
    $ip = $ip_end = '';
    if (isset($options['ip'])) {
        // Build $ip and $ip_end from $options['ip'] and $options['endip']
        $ip = ip_complete($options['ip'], '0');
        if (isset($options['endip'])) { $ip_end = ip_complete($options['endip'], '255'); }
        else { $ip_end = ip_complete($options['ip'], '255'); }

        // Find out if $ip and $ip_end are valid
        $ip = ip_mangle($ip, 'numeric');
        $ip_end = ip_mangle($ip_end, 'numeric');
        if ($ip != -1 and $ip_end != -1) {
            // We do a sub-select to find interface id's between the specified ranges
            $where .= $and . "h.id IN ( SELECT host_id " .
                             "FROM interfaces " .
                             "WHERE ip_addr >= " . $onadb->qstr($ip) . " AND ip_addr <= " . $onadb->qstr($ip_end) . " )";
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
        $where .= $and . "h.id in (select reference from tags where type like 'host' and name in ('". implode('\',\'', explode (',',$options['tag']) ) . "'))";
        $and = " AND ";
    }

    // custom attribute type
    if (isset($options['catype'])) {
        $where .= $and . "h.id in (select table_id_ref from custom_attributes where table_name_ref like 'hosts' and custom_attribute_type_id = (SELECT id FROM custom_attribute_types WHERE name = " . $onadb->qstr($options['catype']) . "))";
        $and = " AND ";
        $cavaluetype = "and custom_attribute_type_id = (SELECT id FROM custom_attribute_types WHERE name = " . $onadb->qstr($options['catype']) . ")";

    }

    // custom attribute value
    if (isset($options['cavalue'])) {
        $where .= $and . "h.id in (select table_id_ref from custom_attributes where table_name_ref like 'hosts' {$cavaluetype} and value like " . $onadb->qstr($options['cavalue']) . ")";
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

    printmsg("Query: [from] $from [where] $where", 'debug');

    list ($status, $rows, $hosts) =
        db_get_records(
            $onadb,
            $from,
            $where,
            $orderby
        );


    if (!$rows) {
      $text_array['status_msg'] = "No host records were found";
      return(array(0, $text_array));
    }

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
    $version = '2.00';

    // Return the usage summary if we need to
    if (!(isset($options['host']) and isset($options['type']) and isset($options['ip'])) ) {
        $self['error'] = 'Insufficient parameters';
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

    // Sanitize addptr.. default it to Y if it is not set by the user
    if (isset($options['addptr'])) {
      $options['addptr'] = sanitize_YN($options['addptr'], 'Y');
    } else {
      $options['addptr'] = 'Y';
    }

    // clean up what is passed in
    if (isset($options['ip']))
      $options['ip'] = trim($options['ip']);
    if (isset($options['mac']))
      $options['mac'] = trim($options['mac']);
    if (isset($options['name']))
      $options['name'] = trim($options['name']);
    if (isset($options['host']))
      $options['host'] = trim($options['host']);

    // Validate that there isn't already another interface with the same IP address
    list($status, $rows, $interface) = ona_get_interface_record(array('ip_addr' => $options['ip']));
    if ($rows) {
        $self['error'] = "IP conflict: That IP address (" . ip_mangle($orig_ip,'dotted') . ") is already in use!";
        printmsg($self['error'],'error');
        return(array(4, $self['error']));
    }

    // Find the Location ID to use
    if (isset($options['location'])) {
        list($status, $rows, $loc) = ona_find_location($options['location']);
        if ($status or !$rows) {
            $self['error'] = "The location specified, {$options['location']}, does not exist!";
            printmsg($self['error'],'error');
            return(array(2, $self['error']));
        }
    } else {
        $loc['id'] = 0;
    }

    // Find the Device Type ID (i.e. Type) to use
    list($status, $rows, $device_type) = ona_find_device_type($options['type']);
    if ($status or $rows != 1 or !$device_type['id']) {
        $self['error'] = "The device type specified, {$options['type']}, does not exist!";
        printmsg($self['error'],'error');
        return(array(3, $self['error']));
    }
    printmsg("Device type selected: {$device_type['model_description']} Device ID: {$device_type['id']}", 'info');


/*
    // Sanitize "security_level" option
    if (isset($options['security_level']))
      $options['security_level'] = sanitize_security_level($options['security_level']);
    if ($options['security_level'] == -1) {
        printmsg("DEBUG => Sanitize security level failed either ({$options['security_level']}) is invalid or is higher than user's level!", 3);
        return(array(3, $self['error'] . "\n"));
    }
*/


    // Determine the real hostname to be used --
    // i.e. add .something.com, or find the part of the name provided
    // that will be used as the "domain".  This means testing many
    // domain names against the DB to see what's valid.
    //
    // Find the domain name piece of $search.
    // If we are specifically passing in a domain, use its value.  If we dont have a domain
    // then try to find it in the name that we are setting.
    if(isset($options['domain'])) {
        // Find the domain name piece of $search
        list($status, $rows, $domain) = ona_find_domain($options['domain'],0);
    } else {
        list($status, $rows, $domain) = ona_find_domain($options['host'],0);
    }
    if (!isset($domain['id'])) {
        $self['error'] = "Unable to determine domain name portion of ({$options['host']})!";
        printmsg($self['error'],'error');
        return(array(3, $self['error']));
    }

    printmsg("ona_find_domain({$options['host']}) returned: {$domain['fqdn']}", 'debug');

    // Now find what the host part of $search is
    $hostname = str_replace(".{$domain['fqdn']}", '', $options['host']);

    // Validate that the DNS name has only valid characters in it
    $hostname = sanitize_hostname($hostname);
    if (!$hostname) {
        $self['error'] = "Invalid host name ({$options['host']})!";
        printmsg($self['error'],'error');
        return(array(4, $self['error']));
    }


    // Debugging
    printmsg("Using hostname: {$hostname} Domainname: {$domain['fqdn']}, Domain ID: {$domain['id']}", 'debug');

    // Validate that there isn't already any dns record named $host['name'] in the domain $host_domain_id.
    $h_status = $h_rows = 0;
    // does the domain $host_domain_id even exist?
    list($d_status, $d_rows, $d_record) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id']));
    if ($d_status or $d_rows) {
        $self['error'] = "Another DNS record named {$hostname}.{$domain['fqdn']} is already in use, the primary name for a host must be unique!";
        printmsg($self['error'],'error');
        return(array(5, $self['error']));
    }

    // Check permissions
    if (!auth('host_add')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(10, $self['error']));
    }

    // Get the next ID for the new host record
    $id = ona_get_next_id('hosts');
    if (!$id) {
        $self['error'] = "The ona_get_next_id('hosts') call failed!";
        printmsg($self['error'], 'error');
        return(array(7, $self['error']));
    }
    printmsg("ID for new host record: $id", 'debug');

    // Get the next ID for the new device record or use the one passed in the CLI
    if (!isset($options['device'])) {
        $host['device_id'] = ona_get_next_id('devices');
        if (!$id) {
            $self['error'] = "The ona_get_next_id('device') call failed!";
            printmsg($self['error'], 'error');
            return(array(7, $self['error']));
        }
        printmsg("ID for new device record: $id", 'debug');
    } else {
        list($status, $rows, $devid) = ona_find_device($options['device']);
        if (!$rows) {
            $self['error'] = "The device specified, {$options['device']}, does not exist!";
            printmsg($self['error'], 'error');
            return(array(7, $self['error']));
        }
        $host['device_id'] = $devid['id'];
    }


    // There is an issue with escaping '=' and '&'.  We need to avoid adding escape characters
    if (isset($options['notes'])) {
      $options['notes'] = str_replace('\\=','=',$options['notes']);
      $options['notes'] = str_replace('\\&','&',$options['notes']);
    } else {
      $options['notes'] = '';
    }

    // Add the device record
    // FIXME: (MP) quick add of device record. more detail should be looked at here to ensure it is done right
    // FIXME: MP this should use the run_module('device_add')!!! when it is ready
    //        create a device module
    list($status, $rows) = db_insert_record(
        $onadb,
        'devices',
        array(
            'id'                => $host['device_id'],
            'device_type_id'    => $device_type['id'],
            'location_id'       => $loc['id'],
            'primary_host_id'   => $id
        )
    );
    if ($status or !$rows) {
        $self['error'] = "SQL Query failed adding device: " . $self['error'];
        printmsg($self['error'], 'error');
        return(array(6, $self['error']));
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
        $self['error'] = "SQL Query failed adding host: " . $self['error'];
        printmsg($self['error'], 'error');
        return(array(6, $self['error']));
    }


    // ---- Add the interface -----
    // We must always have an IP now to add an interface, call that module now:
    // since we have no name yet, we need to use the ID of the new host as the host option for the following module calls
    $options['host'] = $id;
    // Since interface adds can add PTR records as well, lets temporarily set that to NO and let the DNS add in the next step do it for us.
    $ipoptions = $options;
    $ipoptions['addptr'] = 'N';
    unset($ipoptions['notes']);
    unset($ipoptions['type']);

    printmsg("({$hostname}.{$domain['fqdn']}) calling interface_add() ({$ipoptions['ip']})", 'debug');
    list($status, $output) = run_module('interface_add', $ipoptions);
    if ($status)
        return(array($status, $output));
    // Get new interface info from output
    $int = $output['interfaces'][0];

    // ----- Add the DNS record -----
    // make the dns record type A
    $options['type'] = 'A';
    // The user input called 'name' is for the interface name. We have to manually build the name input for the dns add
    // Non optimal but the only way to do it since there is an overloading of the name use case.
    $options['name'] = "{$hostname}.{$domain['fqdn']}";
    $options['domain'] = $domain['fqdn'];

    // Add the DNS entry with the IP address etc
    printmsg("({$hostname}.{$domain['fqdn']}) calling dns_record_add() ({$options['ip']})", 'debug');
    list($status, $output) = run_module('dns_record_add', $options);
    if ($status)
        return(array($status, $output));

    // Get new dns record info from output
    $dnsrecord = $output['dns_records'][0];

    // Set the primary_dns_id to the dns record that was just added
    list($status, $rows) = db_update_record($onadb, 'hosts', array('id' => $id), array('primary_dns_id' => $dnsrecord['id']));
    if ($status or !$rows) {
        $self['error'] = "SQL Query failed to update primary_dns_id for host: " . $self['error'];
        printmsg($self['error'], 'error');
        return(array(8, $self['error']));
    }

    // Return the success notice
    $result['status_msg'] = 'Host added.';
    $result['module_version'] = $version;
    list($status, $rows, $result['hosts'][0]) = ona_get_host_record(array('id' => $id));
    #$result['subnets'][0]['ip_mask_cidr'] = ip_mangle($result['subnets'][0]['ip_mask'], 'cidr');

    ksort($result['hosts'][0]);

    printmsg("Host added: {$result['hosts'][0]['fqdn']}", 'notice');

    return(array(0, $result));

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
    $version = '2.00';

    // Return the usage summary if we need to
    if ((!isset($options['interface']) and !isset($options['host'])) or
       (!isset($options['set_host']) and
        !isset($options['set_type']) and
        !isset($options['set_location']) and
        !isset($options['set_notes'])
       ) ) {
        $self['error'] = 'Insufficient parameters';
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
    if (isset($options['interface']))
      $options['interface'] = trim($options['interface']);
    if (isset($options['host']))
      $options['host'] = trim($options['host']);

    //
    // Find the host record we're modifying
    //

    // If they provided a hostname / ID let's look it up
    if (isset($options['host']))
        list($status, $rows, $host) = ona_find_host($options['host']);

    // If they provided a interface ID, IP address, interface name, or MAC address
    else if (isset($options['interface'])) {
        // Find an interface record by something in that interface's record
        list($status, $rows, $interface) = ona_find_interface($options['interface']);
        if ($status or !$rows) {
            $self['error'] = "Interface not found ({$options['interface']})!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }
        // Load the associated host record
        list($status, $rows, $host) = ona_get_host_record(array('id' => $interface['host_id']));
    }

    // If we didn't get a record then exit
    if (!$host['id']) {
        $self['error'] = "Host not found ({$options['host']})!";
        printmsg($self['error'], 'error');
        return(array(4, $self['error']));
    }

    // Save the record before updating (logging)
    $original_record = $host;

    // Get related Device record info
    list($status, $rows, $device) = ona_get_device_record(array('id' => $host['device_id']));

    // add the device info to the original record so we can compare later
    $original_record['device_type_id'] = $device['device_type_id'];
    $original_record['location_id'] = $device['location_id'];

    //
    // Define the records we're updating
    //

    // This variable will contain the updated info we'll insert into the DB
    $SET = array();
    $SET_DEV = array();

    // Set options['set_type']?
    if (isset($options['set_type'])) {
        // Find the Device Type ID (i.e. Type) to use
        list($status, $rows, $device_type) = ona_find_device_type($options['set_type']);
        if ($status or $rows != 1 or !$device_type['id']) {
            $self['error'] = "The device type specified, {$options['set_type']}, does not exist!";
            printmsg($self['error'], 'error');
            return(array(6, $self['error']));
        }
        printmsg("Device type ID: {$device_type['id']}", 'debug');

        // Everything looks ok, add it to $SET_DEV if it changed...
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
            $self['error'] = "The device specified, {$options['set_device']}, does not exist!";
            printmsg($self['error'], 'error');
            return(array(7, $self['error']));
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
                $self['error'] = "The location specified, {$options['set_location']}, does not exist!";
                printmsg($self['error'], 'error');
                return(array(7, $self['error']));
            }
            // If location is changing, then set the variable
            if ($device['location_id'] != $loc['id']) $SET_DEV['location_id'] = $loc['id'];
        }
    }

    // Check permissions
    if (!auth('host_modify')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(10, $self['error']));
    }

    // Update the host record if necessary
    if(count($SET) > 0) {
        list($status, $rows) = db_update_record($onadb, 'hosts', array('id' => $host['id']), $SET);
        if ($status or !$rows) {
            $self['error'] = "SQL Query failed for host: " . $self['error'];
            printmsg($self['error'], 'error');
            return(array(8, $self['error']));
        }
    }

    // Update device table if necessary
    if(count($SET_DEV) > 0) {
        list($status, $rows) = db_update_record($onadb, 'devices', array('id' => $host['device_id']), $SET_DEV);
        if ($status or !$rows) {
            $self['error'] = "SQL Query failed for device type: " . $self['error'];
            printmsg($self['error'], 'error');
            return(array(9, $self['error']));
        }
    }

    // Return the success notice
    $result['status_msg'] = 'Host UPDATED.';
    $result['module_version'] = $version;
    list($status, $rows, $new_record) = ona_get_host_record(array('id' => $host['id']));
    list($status, $rows, $new_dev_record) = ona_get_device_record(array('id' => $host['device_id']));
    // add the device info to the new record to compare
    $new_record['device_type_id'] = $new_dev_record['device_type_id'];
    $new_record['location_id'] = $new_dev_record['location_id'];

    $result['hosts'][0] = $new_record;

    ksort($result['hosts'][0]);

    // Return the success notice with changes
    $more='';
    $log_msg='';
    foreach(array_keys($original_record) as $key) {
        if($original_record[$key] != $new_record[$key]) {
            $log_msg .= $more . $key . "[" .$original_record[$key] . "=>" . $new_record[$key] . "]";
            $more= ';';
        }
    }

    // Get success info for dev record
    list($status, $rows, $new_record) = ona_get_device_record(array('id' => $host['id']));

    // only print to logfile if a change has been made to the record
    if($more != '') {
      $log_msg = "Host record UPDATED:{$host['id']}: {$log_msg}";
    } else {
      $log_msg = "Host record UPDATED:{$host['id']}: Update attempt produced no changes.";
    }

    printmsg($log_msg, 'notice');

    return(array(0, $result));
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

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (!isset($options['host'])) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }


    // Find the host (and domain) record from $options['host']
    list($status, $rows, $host) = ona_find_host($options['host']);
    if (!$rows) {
        $self['error'] = "Unable to find host: {$options['host']}";
        printmsg($self['error'], 'error');
        return(array(2, $self['error']));
    } else {
      printmsg("Host: {$host['fqdn']} ({$host['id']})", 'debug');
    }


    // Check permissions
    if (!auth('host_del')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(10, $self['error']));
    }

    $text = '';
    $add_to_error = '';
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
        $self['error'] = "Host ({$host['fqdn']}) cannot be deleted, it is performing duties as a DHCP server!";
        printmsg($self['error'], 'error');
        return(array(5, $self['error']));
    }


    // Check if host is a dns server
    $serverrow = 0;
    list($status, $rows, $srecord) = db_get_record($onadb, 'dns_server_domains', array('host_id' => $host['id']));
    if ($rows) $serverrow++;

    if ($serverrow > 0) {
        $self['error'] = "Host ({$host['fqdn']}) cannot be deleted, it is performing duties as a DNS server!";
        printmsg($self['error'], 'error');
        return(array(5, $self['error']));
    }

    // Display an error if it has any entries in configurations
    list($status, $rows, $server) = db_get_record($onadb, 'configurations', array('host_id' => $host['id']));
    if ($rows) {
        $self['error'] = "Host ({$host['fqdn']}) cannot be deleted, it has config archives!";
        printmsg($self['error'], 'error');
        return(array(5, $self['error']));
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
        $self['error'] = "An interface on this host is primary for some interface shares, delete the share or move the interface first.";
        printmsg($self['error'],'error');
        return(array(5, $self['error']));
    }

    // do the interface_cluster delete.  This just removes this host from the cluster, not the whole cluster itself
    // It will error out as well if this interface is the primary in the cluster
    list($status, $rows) = db_delete_records($onadb, 'interface_clusters', array('host_id' => $host['id']));
    if ($status) {
        $self['error'] = "Interface_cluster delete SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(5, $self['error']));
    }
    // log deletions
    $add_to_error .= "{$rows} Shared interface(s) DELETED from {$host['fqdn']}";
    printmsg($add_to_error,'debug');


    // Delete each DNS record associated with this hosts interfaces.
//    foreach ($interfaces as $int) {
//       // Loop through each dns record associated with this interface.
//       list($status, $rows, $records) = db_get_records($onadb, 'dns', array('interface_id' => $int['id']));
//       if ($rows) {
//           foreach($records as $record) {
//               // Run the module
//               list($status, $output) = run_module('dns_record_del', array('name' => $record['id'], 'type' => $record['type'], 'commit' => 'Y', 'delete_by_module' => 'Y'));
//               $add_to_error .= $output;
//               $add_to_status = $add_to_status + $status;
//             }
//         }
//     }

    // Delete messages
    // get list for logging
    list($status, $rows, $records) = db_get_records($onadb, 'messages', array('table_name_ref' => 'hosts','table_id_ref' => $host['id']));
    // do the delete
    list($status, $rows) = db_delete_records($onadb, 'messages', array('table_name_ref' => 'hosts','table_id_ref' => $host['id']));
    if ($status) {
        $self['error'] = "Message delete SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(5, $self['error']));
    }
    // log deletions
    $add_to_error .= "{$rows} Message(s) DELETED from {$host['fqdn']}";
    printmsg($add_to_error,'debug');


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
            $self['error'] = "Device delete SQL Query failed: {$self['error']}";
            printmsg($self['error'],'error');
            return(array(5, $add_to_error . $self['error']));
        }
        // log deletions
        printmsg("Device record DELETED: [{$record['id']}] no remaining hosts using this device",'notice');
    } else {
        printmsg("Device record NOT DELETED: [{$record['id']}] there are other hosts using this device.",'notice');
    }


    // Delete tag entries
    list($status, $rows, $records) = db_get_records($onadb, 'tags', array('type' => 'host', 'reference' => $host['id']));
    $log=array(); $i=0;
    foreach ($records as $record) {
        $log[$i]= "Tag DELETED: {$record['name']} from {$host['fqdn']}";
        $i++;
    }
    //do the delete
    list($status, $rows) = db_delete_records($onadb, 'tags', array('type' => 'host', 'reference' => $host['id']));
    if ($status) {
        $self['error'] = "Tag delete SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(5, $add_to_error . $self['error']));
    }
    //log deletions
    foreach($log as $log_msg) {
        printmsg($log_msg,'notice');
        $add_to_error .= $log_msg;
    }

    // Delete custom attribute entries
    // get list for logging
    list($status, $rows, $records) = db_get_records($onadb, 'custom_attributes', array('table_name_ref' => 'hosts', 'table_id_ref' => $host['id']));
    $log=array(); $i=0;
    foreach ($records as $record) {
        list($status, $rows, $ca) = ona_get_custom_attribute_record(array('id' => $record['id']));
        $log[$i]= "Custom Attribute DELETED: {$ca['name']} ({$ca['value']}) from {$host['fqdn']}";
        $i++;
    }

    //do the delete
    list($status, $rows) = db_delete_records($onadb, 'custom_attributes', array('table_name_ref' => 'hosts', 'table_id_ref' => $host['id']));
    if ($status) {
        $self['error'] = "Custom attribute delete SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(5, $add_to_error . $self['error']));
    }

    //log deletions
    foreach($log as $log_msg) {
        printmsg($log_msg,'notice');
        $add_to_error .= $log_msg;
    }




    // Delete DHCP options
    // get list for logging
    list($status, $rows, $records) = db_get_records($onadb, 'dhcp_option_entries', array('host_id' => $host['id']));
    $log=array(); $i=0;
    foreach ($records as $record) {
        list($status, $rows, $dhcp) = ona_get_dhcp_option_entry_record(array('id' => $record['id']));
        $log[$i]= "DHCP entry DELETED: {$dhcp['display_name']}={$dhcp['value']} from {$host['fqdn']}";
        $i++;
    }
    // do the delete
    list($status, $rows) = db_delete_records($onadb, 'dhcp_option_entries', array('host_id' => $host['id']));
    if ($status) {
        $self['error'] = "DHCP option entry delete SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(5, $add_to_error . $self['error']));
    }
    // log deletions
    foreach($log as $log_msg) {
        printmsg($log_msg,'notice');
        $add_to_error .= $log_msg;
    }

    // Delete the host
    list($status, $rows) = db_delete_records($onadb, 'hosts', array('id' => $host['id']));
    if ($status) {
        $self['error'] = "Host delete SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(5, $add_to_error . $self['error']));
    }

    // Return the success notice
    if ($add_to_status == 0) $self['error'] = "Host DELETED: {$host['fqdn']}";
    printmsg($self['error'], 'notice');
    return(array($add_to_status, $self['error']));
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
    $ona_ints = '';

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Sanitize options[verbose] (default is yes)
    if (isset($options['verbose'])) {
      $options['verbose'] = sanitize_YN($options['verbose'], 'Y');
    } else {

    }

    // Return the usage summary if we need to
    if (!isset($options['host']) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error'] ));
    }


    // Find the host (and domain) record from $options['host']
    list($status, $rows, $host) = ona_find_host($options['host']);
    if (!$host['id']) {
        $self['error'] = "Unknown host: {$options['host']}";
        printmsg($self['error'], 'error');
        return(array(2, $self['error']));
    }


    // If 'verbose' is enabled, grab some additional info to display
    if (isset($options['verbose']) and $options['verbose'] == 'Y') {

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

        ksort($location);
        ksort($device);
        $host['location'] = $location;
        $host['device'] = $device;
      }

      // Interface record(s)
      $i = 0;
      list($status, $introws, $interfaces) = db_get_records($onadb, 'interfaces', "host_id = {$host['id']}");

      // Now lets find interfaces we share with other hosts as subordinate
      list($status, $clustsubrows, $clustsub) = db_get_records($onadb, 'interface_clusters', "host_id = {$host['id']}");
      foreach($clustsub as $sub) {
        list($status, $clustintrows, $clustint) = ona_get_interface_record(array('id' => $sub['interface_id']));
        $interfaces[] = $clustint;
      }

      // Loop all our interfaces and gather information
      foreach($interfaces as $interface) {

        // Find out if the interface is a NAT interface and skip it
        list ($isnatstatus, $isnatrows, $isnat) = ona_get_interface_record(array('nat_interface_id' => $interface['id']));
        if ($isnatrows > 0) { continue; }
        $i++;

        // get subnet information
        list($status, $srows, $subnet) = ona_get_subnet_record(array('id' => $interface['subnet_id']));

        // check for shared interfaces
        list($status, $clust_rows, $clust) = db_get_records($onadb, 'interface_clusters', array('interface_id' => $interface['id']));
        if ($clust_rows) {
            list($status, $sharedhostrows, $sharedhost) = ona_get_host_record(array('id' => $clust[0]['host_id']));
            $interface['shared_host_secondary'] = $sharedhost['fqdn'];
            $interface['shared_host_primary'] = $host['fqdn'];
            // if this is the secondary we need to figure out what the primary host is
            if ($sharedhost['fqdn'] == $host['fqdn']) {
                list($status, $hostprirows, $hostpri) = ona_get_host_record(array('id' => $interface['host_id']));
                $interface['shared_host_primary'] = $hostpri['fqdn'];
            }
        }

        // check for nat IPs
        if ($interface['nat_interface_id']) {
            list($status, $natrows, $natinterface) = ona_get_interface_record(array('id' => $interface['nat_interface_id']));
            $interface['nat_ip'] = $natinterface['ip_addr_text'];
        }

        // fixup some subnet data
        $subnet['ip_addr_text'] = ip_mangle($subnet['ip_addr'],'dotted');
        $subnet['ip_mask_text'] = ip_mangle($subnet['ip_mask'],'dotted');
        $subnet['ip_mask_cidr'] = ip_mangle($subnet['ip_mask'],'cidr');
        $interface['ip_addr_text'] = ip_mangle($interface['ip_addr'],'dotted');

        // Keep track of interface names
        $ona_ints .= "{$interface['name']},";

#        foreach ($interface as $key=>$val) if (strripos($key,'_id') !== false) unset($interface[$key]);
#        foreach ($subnet as $key=>$val) if (strripos($key,'_id') !== false) unset($subnet[$key]);

        // gather interface and subnets into an array for later
        $allints[$i] = $interface;
        $subnets['subnet_'.$subnet['id']] = $subnet;

      }

      // Add a list of interface names to the array
      $host['interface_names'] = implode(',',array_unique(explode(',',rtrim($ona_ints,','))));
      $host['interface_count'] = $i;

      // Tag records
      list($status, $rows, $tags) = db_get_records($onadb, 'tags', array('type' => 'host', 'reference' => $host['id']));
      if ($rows) {
        foreach ($tags as $tag) {
          $host['tags'][] = $tag['name'];
        }
      }

      // CA records
      list($status, $rows, $custom_attributes) = db_get_records($onadb, 'custom_attributes', array('table_name_ref' => 'hosts', 'table_id_ref' => $host['id']));
      if ($rows) {
        foreach ($custom_attributes as $att) {
          list($status, $rows, $ca) = ona_get_custom_attribute_record(array('id' => $att['id']));
          $host['custom_attributes'][$ca['name']] = $ca['value'];
        }
      }

      // Append our interface info to the end
      $i=0;
      foreach ($allints as $int) {
        ksort($int);
        $host['interfaces'][$i] = $int;
        unset($host['interfaces'][$i]['host_id']);
        $i++;
      }

      // Append our subnet info to the end
      $i=0;
      foreach ($subnets as $net) {
        ksort($net);
        $host['subnets'][$i] = $net;
        $i++;
      }


    // Get rid of database id values
    // TODO: maybe make this an option to keep or remove values
# this breaks the subnet info due to _id being in it
#    foreach ($details as $key=>$val) if (strripos($key,'_id') !== false) unset($details[$key]);

    }

    // Cleanup unused info
    unset($host['device_id']);
    unset($host['device']['asset_tag']);
    unset($host['device']['location_id']);
    unset($host['device']['serial_number']);
    unset($host['subnet']['network_role_id']);

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
