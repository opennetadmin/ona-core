<?php

///////////////////////////////////////////////////////////////////////
//  Function: domain_add (string $options='')
//
//  $options = key=value pairs of options for this function.
//             multiple sets of key=value pairs should be separated
//             by an "&" symbol.
//
//  Input Options:
//    name=STRING
//    server=NAME[.DOMAIN]
//    auth=[Y|N]
//   optional:
//    admin=STRING
//    ptr=Y or N
//    primary=STRING
//    refresh=NUMBER
//    retry=NUMBER
//    expiry=NUMBER
//    minimum=NUMBER
//    parent=DOMAIN_NAME
//
//  Output:
//    Adds a domain entry into the IP database with a name of 'name'. All
//    other values are optional and can reley on their defaults.
//
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = domain_add('name=something.com');
///////////////////////////////////////////////////////////////////////
function domain_add($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if ( !(
                                (isset($options['name']))
                                 or
                                (isset($options['admin_email']) or isset($options['ptr']) or isset($options['primary_master']))
                              )
        )
    {
        $self['error'] = 'Insufficient parameters';
        return(array(1,
<<<EOM

domain_add-v{$version}
Adds a DNS domain into the database

  Synopsis: domain_add [KEY=VALUE] ...

  Required:
    name=STRING                             full name of new domain
                                            (i.e. name.something.com)

  Optional:
    admin=STRING                            Default ({$conf['dns_admin_email']})
    primary_master=STRING                   Default ({$conf['dns_primary_master']})
    refresh=NUMBER                          Default ({$conf['dns_refresh']})
    retry=NUMBER                            Default ({$conf['dns_retry']})
    expiry=NUMBER                           Default ({$conf['dns_expiry']})
    minimum=NUMBER                          Default ({$conf['dns_minimum']})
    parent=DOMAIN_NAME                      Default ({$conf['dns_parent']})
    ttl=NUMBER                              Default ({$conf['dns_default_ttl']})


EOM

        ));
    }

    // Use default if something was not passed on command line
    if (isset($options['admin_email']))   { $admin   = $options['admin_email'];  } else { $admin   = $conf['dns_admin_email'];   }
    if (isset($options['primary_master'])) { $primary = $options['primary_master'];} else { $primary = $conf['dns_primary_master'];  }
    if (isset($options['refresh'])) { $refresh = $options['refresh'];} else { $refresh = $conf['dns_refresh']; }
    if (isset($options['retry']))   { $retry   = $options['retry'];  } else { $retry   = $conf['dns_retry'];   }
    if (isset($options['expiry']))  { $expiry  = $options['expiry']; } else { $expiry  = $conf['dns_expiry'];  }
    if (isset($options['minimum'])) { $minimum = $options['minimum'];} else { $minimum = $conf['dns_minimum']; }
    if (isset($options['ttl']))     { $ttl     = $options['ttl'];}     else { $ttl     = $conf['dns_default_ttl']; }

    $options['name'] = trim($options['name']);
    if (isset($options['primary_master']))
      $options['primary_master'] = trim($options['primary_master']);
    if (isset($options['admin_email']))
      $options['admin_email'] = trim($options['admin_email']);

    // Setup array for searching existing domains
    $exist_domain = array('name' => $options['name']);

    // get parent domain info
    if (isset($options['parent'])) {
        $options['parent'] = trim($options['parent']);
        list($status, $rows, $parent_domain)  = ona_find_domain($options['parent'],0);
        if (!isset($parent_domain['id'])) {
            $self['error'] = "The parent domain specified, {$options['parent']}, does not exist!";
            printmsg($self['error'],'error');
            return(array(5, $self['error']));
        }
        // Set up the parent part of the search if there was one
        $exist_domain['parent_id'] = $parent_domain['id'];
    } else {
        $parent_domain['id'] = 0;
        $parent_domain['fqdn'] = '';
    }


    // Validate that this domain doesnt already exist
    list($status, $rows, $record) = ona_get_domain_record($exist_domain);

    if ($rows) {
        $self['error'] = "The domain specified, {$options['name']}, already exists!";
        printmsg($self['error'], 'error');
        return(array(11, $self['error']));
    }




    if (is_string($options['name'])) {
        // FIXME: not sure if its needed but this was calling sanitize_domainname, which did not exist
        $domain_name = sanitize_hostname($options['name']);
        if (!is_string($domain_name)) {
            $self['error'] = "The domain name ({$options['name']}) is invalid!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }
    }


// FIXME: MP for now this is removed.  it is a chicken/egg issue on setting this name
//   Also it cant use find_host as the name is not always primary dns name.

//     if ($primary) {
//         // Determine the primary master is a valid host
//         list($status, $rows, $ohost) = ona_find_host($primary);
// 
//         if (!$ohost['id']) {
//             printmsg("DEBUG => The primary master host specified ({$primary}) does not exist!", 3);
//             $self['error'] = "ERROR => The primary master host specified ({$primary}) does not exist!";
//             return(array(2, $self['error'] . "\n"));
//         }
// 
//     }


    // Check permissions
    if (!auth('advanced')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(10, $self['error']));
    }



    // Get the next ID
    $first_id = $id = ona_get_next_id('domains');
    if (!$id) {
        $self['error'] = "The ona_get_next_id('domains') call failed!";
        printmsg($self['error'], 'error');
        return(array(6, $self['error']));
    }


    // come up with a serial_number
    // Calculate a serial based on time
    // concatinate year,month,day,percentage of day
    // FIXME: MP this needs more work to be more accurate.  maybe not use date.. pretty limiting at 10 characters as suggested here: http://www.zytrax.com/books/dns/ch8/soa.html
    // for now I'm going with non zero padded(zp) month,zp day, zp hour, zp minute, zp second.  The only issue I can see at this point with this is when it rolls to january..
    // will that be too much of an increment for it to properly zone xfer?  i.e.  1209230515 = 12/09 23:05:15 in time format

    // MP: FOR NOW SERIAL WONT EVER GET USED...  LEFT IT IN HERE FOR AWHILE THOUGH
    $serial_number = date('njHis');


    // Add the record
    list($status, $rows) =
        db_insert_record(
            $onadb,
            'domains',
            array(
                'id'              => $id,
                'name'            => $domain_name,
                'primary_master'  => $primary,
                'admin_email'     => $admin,
                'refresh'         => $refresh,
                'retry'           => $retry,
                'expiry'          => $expiry,
                'minimum'         => $minimum,
                'default_ttl'     => $ttl,
                'parent_id'       => $parent_domain['id'],
                'serial'          => $serial_number
            )
        );

    if ($status or !$rows) {
        $self['error'] = "SQL Query failed: " . $self['error'];
        printmsg($self['error'],'error');
        return(array(7, $self['error']));
    }

    $result['status_msg'] = 'Domain added.';
    $result['module_version'] = $version;
    list($status, $rows, $result['domains'][0]) = ona_get_domain_record(array('id' => $id));

    ksort($result['domains'][0]);

    // Return the success notice
    printmsg("New domain added. ID: {$id} name: {$domain_name}.{$parent_domain['fqdn']}", 'notice');
    return(array(0, $result));
}












///////////////////////////////////////////////////////////////////////
//  Function: domain_del (string $options='')
//
//  $options = key=value pairs of options for this function.
//             multiple sets of key=value pairs should be separated
//             by an "&" symbol.
//
//  Input Options:
//    domain=NAME or ID
//
//  Output:
//    Deletes a domain from the IP database.
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = domain_del('domain=test');
///////////////////////////////////////////////////////////////////////
function domain_del($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (!isset($options['domain']) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }


    // Check if it is an ID or NAME
    if (is_numeric($options['domain'])) {
        $domainsearch = array('id' => $options['domain']);
    } else {
        $domainsearch = array('name' => $options['domain']);
    }

    // Test that the domain actually exists.
    list($status, $tmp_rows, $entry) = ona_get_domain_record($domainsearch);
    if (!$entry['id']) {
        $self['error'] = "Unable to find a domain record using ID {$options['domain']}!";
        printmsg($self['error'],'error');
        return(array(4, $self['error']));
    }

    // Debugging
    list($status, $tmp_rows, $tmp_parent) = ona_get_domain_record(array('id'=>$entry['parent_id']));
    // set a blank name value if there is no parent
    if (!isset($tmp_parent['name']))
      $tmp_parent['name'] = '';

    printmsg("Domain selected: {$entry['name']}.{$tmp_parent['name']}", 'debug');


    // Display an error if DNS records are using this domain
    list($status, $rows, $dns) = db_get_records($onadb, 'dns', array('domain_id' => $entry['id']));
    if ($rows) {
        $self['error'] = "Domain ({$entry['name']}) can't be deleted, it is in use by {$rows} DNS entries!";
        printmsg($self['error'],'error');
        return(array(5, $self['error']));
    }

    // Display an error if it is a parent of other domains
    list($status, $rows, $parent) = db_get_records($onadb, 'domains', array('parent_id' => $entry['id']));
    if ($rows) {
        $self['error'] = "Domain ({$entry['name']}) can't be deleted, it is the parent of {$rows} other domain(s)!";
        printmsg($self['error'],'error');
        return(array(7, $self['error']));
    }

    // Check permissions
    if (!auth('advanced')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(10, $self['error']));
    }

    // Delete association with any servers
    list($status, $rows) = db_delete_records($onadb, 'dns_server_domains', array('domain_id' => $entry['id']));
    if ($status) {
        $self['error'] = "SQL Query (dns_server_domains) failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(8, $self['error']));
    }

    // Delete actual domain
    list($status, $rows) = db_delete_records($onadb, 'domains', array('id' => $entry['id']));
    if ($status) {
        $self['error'] = "SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(9, $self['error']));
    }

    // Return the success notice
    $self['error'] = "Domain DELETED: {$entry['name']}";
    printmsg($self['error'],'notice');
    return(array(0, $self['error']));

}









///////////////////////////////////////////////////////////////////////
//  Function: domain_modify (string $options='')
//
//  $options = key=value pairs of options for this function.
//             multiple sets of key=value pairs should be separated
//             by an "&" symbol.
//
//  Input Options:
//  Where:
//    domain=STRING or ID         full name of domain (i.e. name.something.com)

//  Optional:
//    set_name=STRING           new domain name
//    set_admin=STRING          Default ({$conf['dns_admin_email']})
//    set_ptr=[Y|N]             Default ({$conf['dns_ptr']})
//    set_primary=STRING         Default ({$conf['dns_primary_master']})
//    set_refresh=NUMBER        Default ({$conf['dns_refresh']})
//    set_retry=NUMBER          Default ({$conf['dns_retry']})
//    set_expiry=NUMBER         Default ({$conf['dns_expir']})
//    set_minimum=NUMBER        Default ({$conf['dns_minimum']})
//    set_parent=DOMAIN_NAME      Default ({$conf['dns_parent']})
//
//  Output:
//    Updates an domain record in the IP database.
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = domain_modify('alias=test&host=q1234.something.com');
///////////////////////////////////////////////////////////////////////
function domain_modify($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (!(
          (isset($options['domain']))
            and
          (isset($options['set_admin_email']) or
           isset($options['set_name']) or
           isset($options['set_primary_master']) or
           isset($options['set_refresh']) or
           isset($options['set_retry']) or
           isset($options['set_expiry']) or
           isset($options['set_minimum']) or
           isset($options['set_ttl']) or
           isset($options['set_parent']))
          )
        )
    {
        $self['error'] = 'Insufficient parameters';
        return(array(1,
<<<EOM

domain_modify-v{$version}
Modifies a DNS domain in the database

  Synopsis: domain_modify [KEY=VALUE] ...

  Where:
    domain=STRING or ID         full name of domain (i.e. name.something.com)

  Optional:
    set_name=STRING           new domain name
    set_admin=STRING          Default ({$conf['dns_admin_email']})
    set_primary_master=STRING Default ({$conf['dns_primary_master']})
    set_refresh=NUMBER        Default ({$conf['dns_refresh']})
    set_retry=NUMBER          Default ({$conf['dns_retry']})
    set_expiry=NUMBER         Default ({$conf['dns_expiry']})
    set_minimum=NUMBER        Default ({$conf['dns_minimum']})
    set_ttl=NUMBER            Default ({$conf['dns_default_ttl']})
    set_parent=DOMAIN_NAME    Default ({$conf['dns_parent']})


EOM
        ));
    }

    $options['domain'] = trim($options['domain']);
    if (isset($options['set_name']))
      $options['set_name'] = trim($options['set_name']);
    if (isset($options['set_parent']))
      $options['set_parent'] = trim($options['set_parent']);
    if (isset($options['set_admin_email']))
      $options['set_admin_email'] = trim($options['set_admin_email']);

    $domainsearch = array();
    // setup a domain search based on name or id
    if (is_numeric($options['domain'])) {
        $domainsearch['id'] = $options['domain'];
    } else {
        $domainsearch['name'] = $options['domain'];
    }

    // Determine the entry itself exists
    list($status, $rows, $entry) = ona_get_domain_record($domainsearch);

    // Test to see that we were able to find the specified record
    if (!$entry['id']) {
        $self['error'] = "Unable to find the domain record using {$options['domain']}!";
        printmsg($self['error'],'error');
        return(array(4, $self['error']));
    }

    printmsg("Found entry, {$entry['name']}", 'debug');


    // This variable will contain the updated info we'll insert into the DB
    $SET = array();


    if (array_key_exists('set_parent',$options) and isset($options['set_parent'])) {
        $parentsearch = array();
        // setup a domain search based on name or id
        if (is_numeric($options['set_parent'])) {
            $parentsearch['id'] = $options['set_parent'];
        } else {
            $parentsearch['name'] = $options['set_parent'];
        }

        // Determine the host is valid
        list($status, $rows, $domain) = ona_get_domain_record($parentsearch);

        if (!$domain['id']) {
            $self['error'] = "The parent domain specified ({$options['set_parent']}) does not exist!";
            printmsg($self['error'],'error');
            return(array(2, $self['error']));
        }

        if ($entry['parent_id'] != $domain['id']) $SET['parent_id'] = $domain['id'];
    } else {
        if ($entry['parent_id'] != 0) $SET['parent_id'] = 0;
    }

    // FIXME: currently renaming zones may not work when using
    // parent zones. https://github.com/opennetadmin/ona/issues/36
    if (isset($options['set_name']) and is_string($options['set_name'])) {
        // trim leading and trailing whitespace from 'value'
        if ($entry['name'] != trim($options['set_name'])) $SET['name'] = trim($options['set_name']);

        // Determine the entry itself exists
        list($status, $rows, $domain) = ona_get_domain_record(array('name' => $options['set_name']));

        // Test to see that the new entry isnt already used
        if ($domain['id'] and $domain['id'] != $entry['id']) {
            $self['error'] = "The domain specified ({$options['set_name']}) already exists!";
            printmsg($self['error'],'error');
            return(array(6, $self['error']));
        }

    }

    // define the remaining entries
    if (isset($options['set_primary_master']) and $entry['primary_master'] != $options['set_primary_master']) $SET['primary_master'] = trim($options['set_primary_master']);
    if (isset($options['set_admin_email']) and $entry['admin_email'] != $options['set_admin_email'])   $SET['admin_email'] = $options['set_admin_email'];
    if (isset($options['set_refresh']) and $entry['refresh'] != $options['set_refresh']) $SET['refresh']     = $options['set_refresh'];
    if (isset($options['set_retry']) and $entry['retry'] != $options['set_retry'])   $SET['retry']       = $options['set_retry'];
    if (isset($options['set_expiry']) and $entry['expiry'] != $options['set_expiry'])  $SET['expiry']      = $options['set_expiry'];
    if (isset($options['set_minimum']) and $entry['minimum'] != $options['set_minimum']) $SET['minimum']     = $options['set_minimum'];
    if (isset($options['set_ttl']) and $entry['default_ttl'] != $options['set_ttl'])     $SET['default_ttl'] = $options['set_ttl'];


// FIXME: MP for now this is removed.  it is a chicken/egg issue on setting this name
//   Also it cant use find_host as the name is not always primary.

/*    if ($SET['primary_master']) {
        // Determine if the primary master is a valid host
        list($status, $rows, $host) = ona_find_host($SET['primary_master']);

        if (!$host['id']) {
            $self['error'] = "The primary master host specified ({$SET['primary_master']}) does not exist!";
            printmsg($self['error'],'error');
            return(array(2, $self['error'] . "\n"));
        }

    }
*/


    // Check permissions
    if (!auth('advanced')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'notice');
        return(array(10, $self['error']));
    }

    // Get the domain record before updating (logging)
    list($status, $rows, $original_domain) = ona_get_domain_record(array('id'=>$entry['id']));

    // Update the record
    if (count($SET) > 0) {
      list($status, $rows) = db_update_record($onadb, 'domains', array('id' => $entry['id']), $SET);
      if ($status or !$rows) {
        $self['error'] = "SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(6, $self['error']));
      }
    }

    // Get the entry again to display details
    list($status, $rows, $new_domain) = ona_get_domain_record(array('id'=>$entry['id']));

    // TRIGGER:Now that we have updated the domain, lets mark the domain on all the servers for a rebuild to pick up any new SOA info.
    list($status, $rows) = db_update_record($onadb, 'dns_server_domains', array('domain_id' => $entry['id']), array('rebuild_flag' => 1));
    if ($status) {
        $self['error'] = "Unable to update rebuild flags for domain. SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(7, $self['error']));
    }

    $more='';
    $log_msg='';
    foreach(array_keys($original_domain) as $key) {
        if($original_domain[$key] != $new_domain[$key]) {
            $log_msg .= $more . $key . "[" .$original_domain[$key] . "=>" . $new_domain[$key] . "]";
            $more= ';';
        }
    }

    // only print to logfile if a change has been made to the record
    if($more != '') {
      printmsg("Domain UPDATED:{$entry['id']}: ". $log_msg, 'notice');
    } else {
      $log_msg = "Domain UPDATED:{$entry['id']}: Update attempt produced no changes.";
      printmsg($log_msg, 'notice');
    }

    return(array(0, $log_msg));
}








///////////////////////////////////////////////////////////////////////
//  Function: domain_display (string $options='')
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
//  Example: list($status, $result) = domain_display('domain=test');
///////////////////////////////////////////////////////////////////////
function domain_display($options="") {
    global $self ;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if ( !isset($options['domain']) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }

    $domainsearch = array();
    // setup a domain search based on name or id
    if (is_numeric($options['domain'])) {
        $domainsearch['id'] = $options['domain'];
    } else {
        $domainsearch['name'] = $options['domain'];
    }

    // Determine the entry itself exists
    list($status, $rows, $domain) = ona_get_domain_record($domainsearch);

    // Test to see that we were able to find the specified record
    if (!$domain['id']) {
        $self['error'] = "Unable to find the domain record using {$options['domain']}!";
        printmsg($self['error'],'error');
        return(array(4, $self['error']));
    }

    // Debugging
    printmsg("Found {$domain['name']}", 'debug');

    $text_array = array();
    $text_array['module_version'] = $version;

    // Select just the fields requested
    if (isset($options['fields'])) {
      $fields = explode(',', $options['fields']);
      $domain = array_intersect_key($domain, array_flip($fields));
    }
 
    ksort($domain);
    $text_array['domains'][0] = $domain;

    // Return 
    return(array(0, $text_array));
}
