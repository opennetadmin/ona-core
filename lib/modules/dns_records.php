<?php



/////////////////////

// Return subnets from the database
// Allows filtering and other options to narrow the data down

/////////////////////
function dns_records($options="") {
    global $self, $onadb;
    printmsg('Called with options: ('.implode (";",$options).')', 'info');

    // Version - UPDATE on every edit!
    $version = '2.00';

    $text_array = array();
    $text_array['module_version'] = $version;



    // Start building the "where" clause for the sql query to find the records to display
    $where = '';
    $and = '';
    $orderby = '';

    // enable or disable wildcards
    $wildcard = '';
    #if ($form['nowildcard']) $wildcard = '';

    // RECORD ID
    if (isset($options['id'])) {
        $where .= $and . "id = " . $onadb->qstr($options['id']);
        $and = " AND ";
    }

    // DNS VIEW ID
    if (isset($options['dns_view'])) {
        if (is_string($options['dns_view'])) list($status, $rows, $dnsview) = ona_get_dns_view_record(array('name' => $options['dns_view']));
        if (is_numeric($options['dns_view'])) list($status, $rows, $dnsview) = ona_get_dns_view_record(array('id' => $options['dns_view']));
        $where .= $and . "dns_view_id = " . $onadb->qstr($dnsview['id']);
        $and = " AND ";
    }

    // INTERFACE ID
    if (isset($options['interface_id'])) {
        $where .= $and . "interface_id = " . $onadb->qstr($options['interface_id']);
        $and = " AND ";
    }

    // DNS RECORD note
    if (isset($options['notes'])) {
        $where .= $and . "notes LIKE " . $onadb->qstr($wildcard.$options['notes'].$wildcard);
        $and = " AND ";
    }

    // DNS RECORD TYPE
    if (isset($options['type'])) {
        $where .= $and . "type = " . $onadb->qstr($options['type']);
        $and = " AND ";
    }

    // HOSTNAME
    if (isset($options['hostname'])) {
        $where .= $and . "id IN (SELECT id " .
                                "  FROM dns " .
                                "  WHERE name LIKE " . $onadb->qstr($wildcard.$options['hostname'].$wildcard) ." )";
        $and = " AND ";
    }


    // DOMAIN
    if (isset($options['domain'])) {
        list($status,$rows,$tmpdomain) = ona_find_domain($options['domain']);
        if (!$rows) {
          $tmpdomain['id'] = 0;
        }
        $where .= $and . "domain_id = " . $onadb->qstr($tmpdomain['id']);
        $orderby .= "name, domain_id";
        $and = " AND ";
    }

    // DOMAIN ID
    if (isset($options['domain_id'])) {
        //$where .= $and . "primary_dns_id IN ( SELECT id " .
        //                                    "  FROM dns " .
        //                                    "  WHERE domain_id = " . $onadb->qstr($options['domain_id']) . " )  ";
        $where .= $and . "domain_id = " . $onadb->qstr($options['domain_id']);
        $orderby .= "name, domain_id";
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
            $where .= $and . "interface_id IN ( SELECT id " .
                             "        FROM interfaces " .
                             "        WHERE ip_addr >= " . $onadb->qstr($ip) . " AND ip_addr <= " . $onadb->qstr($ip_end) . " )";
            $and = " AND ";
        }
    }


    // Wild card .. if $while is still empty, add a 'ID > 0' to it so you see everything.
    if ($where == '')
        $where = 'id > 0';

    // If we dont have DNS views turned on, limit data to just the default view.
    // Even if there is data associated with other views, ignore it
    if (!isset($options['dns_views'])) {
        $where .= ' AND dns_view_id = 0';
    }


    // If we get a specific host to look for we must do the following
    // 1. get (A) records that match any interface_id associated with the host
    // 2. get CNAMES that point to dns records that are using an interface_id associated with the host
    if (isset($options['host_id'])) {
        // If we dont have DNS views turned on, limit data to just the default view.
        // Even if there is data associated with other views, ignore it
        // MP: something strange with this, it should only limit to default view.. sometimes it does not???
        if (!isset($options['dns_views'])) {
            $hwhere .= 'dns_view_id = 0 AND ';
        }

        // Get the host record so we know what the primary interface is
        list($status, $rows, $host) = ona_get_host_record(array('id' => $options['host_id']), '');

        list ($status, $rows, $dns_records) =
        db_get_records(
            $onadb,
            'dns',
            $hwhere.'interface_id in (select id from interfaces where host_id = '. $onadb->qstr($options['host_id']) .') OR interface_id in (select interface_id from interface_clusters where host_id = '. $onadb->qstr($options['host_id']) .')',
            "type",
            $conf['search_results_per_page'],
            $offset
        );


    } else {
        list ($status, $rows, $dns_records) =
            db_get_records(
                $onadb,
                'dns',
                $where,
                $orderby
               # $conf['search_results_per_page'],
               # $offset
            );
    }


    if (!$rows) {
      $text_array['status_msg'] = "No DNS records were found";
      return(array(0, $text_array));
    }



    $i=0;
    foreach ($dns_records as $dns_record) {
      // get subnet type name
      list($status, $rows, $domain) = ona_get_domain_record(array('id' => $dns_record['domain_id']));
      $dns_record['fqdn'] = $dns_record['name'].'.'.$domain['fqdn'];

      // Select just the fields requested
      if (isset($options['fields'])) {
        $fields = explode(',', $options['fields']);
        $dns_record = array_intersect_key($dns_record, array_flip($fields));
      }

      ksort($dns_record);
      $text_array['dns_records'][$i]=$dns_record;

      $i++;
    }

    $text_array['count'] = count($dns_records);

    // Return the success notice
    return(array(0, $text_array));
}


///////////////////////////////////////////////////////////////////////
//  Function: dns_record_add (string $options='')
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
//  Example: list($status, $result) = dns_record_add('host=test&type=something');
///////////////////////////////////////////////////////////////////////
function dns_record_add($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (!(isset($options['name']) and isset($options['type'])) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,
<<<EOM

dns_record_add-v{$version}
Add a new DNS record

  Synopsis: dns_record_add [KEY=VALUE] ...

  Required:
    name=NAME[.DOMAIN]        hostname for new DNS record
    type=TYPE                 record type (A,CNAME,PTR...)

  Optional:
    notes=NOTES               textual notes
    ip=ADDRESS                ip address (numeric or dotted)
    ttl=NUMBER                time in seconds, defaults to ttl from domain
    pointsto=NAME[.DOMAIN]    hostname that a CNAME,NS,MX etc points to
    addptr                    auto add a PTR record when adding A records
    mx_preference=NUMBER      preference for the MX record
    txt=STRING                text value of a TXT record
    srv_pri=NUMBER            SRV Priority
    srv_weight=NUMBER         SRV Weight
    srv_port=NUMBER           SRV Port
    ebegin=date               Set the begin date for record, 0 disables, default now
    domain=DOMAIN             use only if you need to explicitly set a parent domain
    view=STRING               DNS view identifier. AKA Split horizon.

  Examples:
    dns_record_add name=newhost.something.com type=A ip=10.1.1.2 addptr
    dns_record_add name=somedomain.com type=NS pointsto=ns.somedomain.com
    dns_record_add name=cname.domain.com type=CNAME pointsto=host.domain.com
    dns_record_add name=host.something.com type=TXT txt="my text value"
    dns_record_add name=domain.com type=MX pointsto=mxhost.domain.com mx_preference=10
    dns_record_add name=_foo._tcp.example.com type=SRV pointsto=host.domain.com srv_port=80
    dns_record_add name=newhost.something.com type=PTR ip=10.1.1.10

    DOMAIN will default to {$conf['dns_defaultdomain']} if not specified.


EOM
        ));
    }


/*
thoughts on the flow of things:

a records:
    check if there is an A record with that name/domain and IP already.
    check that name/domain does not match a CNAME entry
    will not have a dns_id value.. blank it out
    if autoptr is set, create a ptr record too
cname records:
    check that name/domain does not match an A entry
    check that name/domain does not match an CNAME entry
    name/domain and dns_id columns must be unique---< implied by the previous check of no cnames using this name
    do I need interface_id??????, yes its used to assoicate it with the host.  this will come via the A record it points to via a lookup
ptr records:
    must be unique in interface_id column, ie. one PTR per interface/ip




FIXME: do some validation of the different options, pointsto only with cname type etc etc

FIXME: what about when you add an entry with a name that matches a primary dns record that already exists.  while adding
multiple A records that have the same name is ok, its not really a good thing to have the primary name for a host be duplicated.  the
primary name for a host should be unique in all cases I'm aware of

*/

    // Sanitize addptr.. set it to Y if it is not set
    if (isset($options['addptr']))
      $options['addptr'] = sanitize_YN($options['addptr'], 'Y');

    // clean up what is passed in
    if (isset($options['ip']))
      $options['ip'] = trim($options['ip']);
    if (isset($options['pointsto']))
      $options['pointsto'] = trim($options['pointsto']);
    if (isset($options['name']))
      $options['name'] = trim($options['name']);
    if (isset($options['domain']))
      $options['domain'] = trim($options['domain']);
    if (isset($options['txt']))
      $options['txt'] = trim($options['txt']);
    if (isset($options['view']))
      $options['view'] = trim($options['view']);

    // Check the date formatting etc
    if (isset($options['ebegin'])) {
        // format the time that was passed in for the database, leave it as 0 if they pass it as 0
        $options['ebegin']=($options['ebegin'] == '0' ? 0 : date('Y-m-j G:i:s',strtotime($options['ebegin'])) );
    } else {
        // If I got no date, use right now as the date/time
        $options['ebegin'] = date('Y-m-j G:i:s');
    }

    // Switch the type setting to uppercase
    $options['type'] = strtoupper($options['type']);
    $add_txt = '';
    $add_mx_preference = 0;
    $add_srv_pri = 0;
    $add_srv_weight = 0;
    $add_srv_port = 0;

    // force AAAA to A to keep it consistant.. we'll display it properly as needed
    if ($options['type'] == 'AAAA')  $options['type'] = 'A';

    // If the name we were passed has a leading or trailing . in it then remove the dot.
    $options['name'] = preg_replace("/^\./", '', $options['name']);
    $options['name'] = preg_replace("/\.$/", '', $options['name']);

    // Determine the real hostname and domain name to be used --
    // i.e. add .something.com, or find the part of the name provided
    // that will be used as the "domain".  This means testing many
    // domain names against the DB to see what's valid.
    //

    // If we are specifically passing in a domain, use its value.  If we dont have a domain
    // then try to find it in the name that we are setting.
    if(isset($options['domain'])) {
        // Find the domain name piece of $search
        list($status, $rows, $domain) = ona_find_domain($options['domain'],0);
    } else {
        list($status, $rows, $domain) = ona_find_domain($options['name'],0);
    }

    // Find the domain name piece of $search.
    if (!isset($domain['id'])) {
        $self['error'] = "Unable to determine domain name portion of ({$options['name']})!";
        printmsg($self['error'], 'error');
        return(array(3, $self['error']));
    }

    printmsg("ona_find_domain({$options['name']}) returned: {$domain['fqdn']}", 'debug');

    // Now find what the host part of $search is
    $hostname = str_replace(".{$domain['fqdn']}", '', $options['name']);

    // Validate that the DNS name has only valid characters in it
    $hostname = sanitize_hostname($hostname);
    if (!$hostname) {
        $self['error'] = "Invalid host name ({$options['name']})!";
        printmsg($self['error'], 'error');
        return(array(4, $self['error']));
    }

    // If the hostname we came up with and the domain name are the same, then assume this is
    // meant to be a domain specific record, like A, MX, NS type records.
    if ($hostname == $domain['fqdn']) $hostname = '';

    // Debugging
    printmsg("Using hostname: {$hostname} Domainname: {$domain['fqdn']}, Domain ID: {$domain['id']}", 'debug');


    // If we are using anything but in-addr.arpa for PTR or NS records, fail out!
    if ((strpos($domain['fqdn'], "ip6.arpa") or strpos($domain['fqdn'], "in-addr.arpa")) and $options['type'] != 'PTR' and $options['type'] != 'NS') {
        $self['error'] = "Only PTR and NS records should use in-addr.arpa or ip6.arpa domains!";
        printmsg($self['error'], 'error');
        return(array(4, $self['error']));
    }



    // Gather DNS view information
    $add_pointsto_viewid = $add_viewid = 0;
    if (isset($options['view'])) {
        if (is_numeric($options['view'])) {
            $viewsearch = array('id' => $options['view']);
        } else {
            $viewsearch = array('name' => strtoupper($options['view']));
        }
        // find the view record,
        list($status, $rows, $dnsview) = ona_get_dns_view_record($viewsearch);
        if (!$rows) {
            $self['error'] = "Unable to find DNS view: {$options['view']}.";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }

        $add_pointsto_viewid = $add_viewid = $dnsview['id'];
    }

    // lets test out if it has a / in it to strip the view name portion
    if (strstr($options['pointsto'],'/')) {
        list($dnsview,$options['pointsto']) = explode('/', $options['pointsto']);
        list($status, $rows, $view) = db_get_record($onadb, 'dns_views', array('name' => strtoupper($dnsview)));
        if($rows) $add_pointsto_viewid = $view['id'];
    }

    // Set a message to display when using dns views
    if (isset($conf['dns_views'])) $viewmsg = ' Ensure you are selecting the proper DNS view for this record.';

    // Process A or AAAA record types
    if ($options['type'] == 'A' or $options['type'] == 'AAAA') {
        // find the IP interface record,
        list($status, $rows, $interface) = ona_find_interface($options['ip']);
        if (!$rows) {
            $self['error'] = "Unable to find IP interface: {$options['ip']}. A records must point to existing IP addresses. Please add an interface with this IP address first.";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }

        // Validate that there isn't already any dns record named $hostname in the domain $domain_id.
        list($d_status, $d_rows, $d_record) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'],'interface_id' => $interface['id'],'type' => 'A', 'dns_view_id' => $add_viewid));
        if ($d_status or $d_rows) {
            $self['error'] = "Another DNS A record named {$hostname}.{$domain['fqdn']} with IP {$interface['ip_addr_text']} already exists!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }

        // Validate that there are no CNAMES already with this fqdn
        list($c_status, $c_rows, $c_record) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'],'type' => 'CNAME','dns_view_id' => $add_viewid));
        if ($c_rows or $c_status) {
            $self['error'] = "Another DNS CNAME record named {$hostname}.{$domain['fqdn']} already exists!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }


        $add_name = $hostname;
        $add_domainid = $domain['id'];
        $add_interfaceid = $interface['id'];
        // A records should not have parent dns records
        $add_dnsid = 0;

        // Dont print a dot unless hostname has a value
        if (isset($hostname)) $hostname = $hostname.'.';

        $info_msg = "{$hostname}{$domain['fqdn']} -> " . ip_mangle($interface['ip_addr'],'dotted');

        // Just to be paranoid, I'm doing the ptr checks here as well if addptr is set
        if ($options['addptr'] == 'Y') {
            // Check that no other PTR records are set up for this IP
            list($status, $rows, $record) = ona_get_dns_record(array('interface_id' => $interface['id'], 'type' => 'PTR','dns_view_id' => $add_viewid));
            if ($rows) {
                $self['error'] = "Another DNS PTR record already exists for this IP interface!{$viewmsg}";
                printmsg($self['error'], 'error');
                return(array(5, $self['error']));
            }
        }

    }

// I think I fixed this.. more testing needed.. no GUI option yet either.
    // MP: FIXME: there is an issue that you cant have just a ptr record with no A record.  so you cant add something like:
/*
router.example.com  A  10.1.1.1

10.1.2.1   PTR  router.example.com
10.1.3.1   PTR  router.example.com
10.1.4.1   PTR  router.example.com

This is a senario where you want just the loopback interface of a router to respond as the A record,
but you still want to reverse lookup all the other interfaces to know they are on router.example.com

--- I think if I add a "build A record" flag so that the A record wont build in DNS but the PTR could.. doesnt work since multiple a record entries will exist and they are not really tied together.??
*/
    // Process PTR record types
    else if ($options['type'] == 'PTR') {
        // find the IP interface record,
        list($status, $rows, $interface) = ona_find_interface($options['ip']);
        if (!$rows) {
            $self['error'] = "Unable to find IP interface: {$options['ip']}. PTR records must point to existing IP addresses. Please add an interface with this IP address first.";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }


        // Check that no other PTR records are set up for this IP
        list($status, $rows, $record) = ona_get_dns_record(array('interface_id' => $interface['id'], 'type' => 'PTR','dns_view_id' => $add_viewid));
        if ($rows) {
            $self['error'] = "Another DNS PTR record already exists for this IP interface!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }

        // Find the dns record that it will point to

        list($status, $rows, $arecord) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'],'interface_id' => $interface['id'], 'type' => 'A','dns_view_id' => $add_viewid));
        if ($status or !$rows) {
            $self['error'] = "Unable to find DNS A record to point PTR entry to! Check that the IP you chose is associated with the name you chose.{$viewmsg}";
            printmsg($self['error'], 'error');

            // As a last resort just find a matching A record no matter the IP.  
            // This is for PTRs that point to an A record that uses a different IP (loopback example)

            // MP: since there could be multiple A records, I'm going to fail out if there is not JUST ONE A record. 
            // this is limiting in a way but allows cleaner data.
            list($status, $rows, $arecord) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'], 'type' => 'A','dns_view_id' => $add_viewid));
            if (($rows > 1)) {
                $self['error'] = "Unable to find a SINGLE DNS A record to point PTR entry to! In this case, you are only allowed to do this if there is one A record using this name.{$viewmsg}";
                printmsg($self['error'], 'error');
            }

            if ($rows != 1)
                return(array(66, $self['error']));
        }


        $ipflip = ip_mangle($interface['ip_addr'],'flip');
        $octets = explode(".",$ipflip);
        if (count($octets) > 4) {
            $arpa = '.ip6.arpa';
            $octcount = 31;
        } else {
            $arpa = '.in-addr.arpa';
            $octcount = 3;
        }
        // Find a pointer zone for this record to associate with.
        list($status, $rows, $ptrdomain) = ona_find_domain($ipflip.$arpa);
        if (!isset($ptrdomain['id'])) {
            $self['error'] = "This operation tried to create a PTR record that is the first in this address space.  You must first create at least the following DNS domain: {$octets[$octcount]}{$arpa}.  You could also create domains for deeper level reverse zones.";
            printmsg($self['error'], 'error');
            return(array(9, $self['error']));
        }

        // PTR records dont need a name set.
        $add_name = '';
        // PTR records should not have domain_ids
        $add_domainid = $ptrdomain['id'];
        $add_interfaceid = $interface['id'];
        $add_dnsid = $arecord['id'];

        // Dont print a dot unless hostname has a value
        if (isset($hostname)) $hostname = $hostname.'.';

        $info_msg = "{$ipflip}{$arpa} -> {$hostname}{$domain['fqdn']}";

    }


/*
FIXME: MP
So there is this fun problem with CNAMES.  I can associate them with a single A record
such that if that A record is changed, or gets removed then I can cleanup/update the CNAME entry.

The problem comes when there are multiple A records that use the same name but different IP addresses.
I can only assoicate the CNAME with one of those A records.  This also means I need to provided
the IP address as well when adding a CNAME so I can choose the correct A record.

The same sort of issue applies to PTR records as well.

In a similar (reverse) issue.  If I have those same multiple A records, the assumption is that
they are all the same name and thus "tied" together in that if I was to change the name to something else
all the A records should all change at once.  Currently I'd have to change ALL the A record entries with the same name manually


Its almost like I'd need a dns_record to name many to one type table.  that would be very annoying!


For now, I'm going to keep going forward as is and hope that even though it is allowed, most people will not create such
complex DNS messes for themselves.


*/



    // Process CNAME record types
    else if ($options['type'] == 'CNAME') {
        // Determine the host and domain name portions of the pointsto option
        // Find the domain name piece of $search
        list($status, $rows, $pdomain) = ona_find_domain($options['pointsto']);
        printmsg("ona_find_domain({$options['pointsto']}) returned: {$domain['fqdn']} for pointsto.", 'debug');

        // Now find what the host part of $search is
        $phostname = str_replace(".{$pdomain['fqdn']}", '', $options['pointsto']);

        // Validate that the DNS name has only valid characters in it
        $phostname = sanitize_hostname($phostname);
        if (!isset($phostname)) {
            $self['error'] = "Invalid pointsto host name ({$options['pointsto']})!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }
        // Debugging
        printmsg("Using 'pointsto' hostname: {$phostname}.{$pdomain['fqdn']}, Domain ID: {$pdomain['id']}", 'debug');

        // Validate that the CNAME I'm adding doesnt match an existing A record.
        list($d_status, $d_rows, $d_record) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'],'type' => 'A','dns_view_id' => $add_viewid));
        if ($d_status or $d_rows) {
            $self['error'] = "Another DNS A record named {$hostname}.{$domain['fqdn']} already exists!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }


        // Validate that there are no CNAMES already with this fqdn
        list($c_status, $c_rows, $c_record) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'],'type' => 'CNAME','dns_view_id' => $add_viewid));
        if ($c_rows or $c_status) {
            $self['error'] = "Another DNS CNAME record named {$hostname}.{$domain['fqdn']} already exists!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }

        // Find the dns record that it will point to
        list($status, $rows, $pointsto_record) = ona_get_dns_record(array('name' => $phostname, 'domain_id' => $pdomain['id'], 'type' => 'A','dns_view_id' => $add_viewid));
        if ($status or !$rows) {
            $self['error'] = "Unable to find DNS A record to point CNAME entry to!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }



        $add_name = $hostname;
        $add_domainid = $domain['id'];
        $add_interfaceid = $pointsto_record['interface_id'];
        $add_dnsid = $pointsto_record['id'];

        // Dont print a dot unless hostname has a value
        if (isset($hostname)) $hostname = $hostname.'.';

        $info_msg = "{$hostname}{$domain['fqdn']} -> {$phostname}.{$pdomain['fqdn']}";

    }



    // Process NS record types
    // NS is a domain_id that points to another dns_id A record
    // this will give you "mydomain.com    IN   NS   server.somedomain.com"
    else if ($options['type'] == 'NS') {
        // find the domain
        list($status, $rows, $domain) = ona_find_domain($options['name'],0);
        if (!$domain['id']) {
            $self['error'] = "Invalid domain name ({$options['name']})!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }
        // Determine the host and domain name portions of the pointsto option
        // Find the domain name piece of $search
        list($status, $rows, $pdomain) = ona_find_domain($options['pointsto']);
        printmsg("ona_find_domain({$options['pointsto']}) returned: {$pdomain['fqdn']} for pointsto.", 'debug');

        // Now find what the host part of $search is
        $phostname = str_replace(".{$pdomain['fqdn']}", '', $options['pointsto']);

        // lets test out if it has a / in it to strip the view name portion
//         if (strstr($phostname,'/')) {
//             list($dnsview,$phostname) = explode('/', $phostname);
//             list($status, $rows, $view) = db_get_record($onadb, 'dns_views', array('name' => strtoupper($dnsview)));
//             if($rows) $add_pointsto_viewid = $view['id'];
//         }

        // Validate that the DNS name has only valid characters in it
        $phostname = sanitize_hostname($phostname);
        if (!$phostname) {
            $self['error'] = "Invalid pointsto host name ({$options['pointsto']})!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }
        // Debugging
        printmsg("Using 'pointsto' hostname: {$phostname}.{$pdomain['fqdn']}, Domain ID: {$pdomain['id']}", 'debug');

        // Find the dns record that it will point to
        list($status, $rows, $pointsto_record) = ona_get_dns_record(array('name' => $phostname, 'domain_id' => $pdomain['id'], 'type' => 'A','dns_view_id' => $add_pointsto_viewid));
        if ($status or !$rows) {
            $self['error'] = "Unable to find DNS A record to point NS entry to!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }

        // Validate that there are no NS already with this domain and host
        list($status, $rows, $record) = ona_get_dns_record(array('dns_id' => $pointsto_record['id'], 'domain_id' => $domain['id'],'type' => 'NS','dns_view_id' => $add_viewid));
        if ($rows or $status) {
            $self['error'] = "Another DNS NS record for {$domain['fqdn']} pointing to {$options['pointsto']} already exists!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }


        $add_name = ''; //$options['name'];
        $add_domainid = $domain['id'];
        $add_interfaceid = $pointsto_record['interface_id'];
        $add_dnsid = $pointsto_record['id'];

        $info_msg = "{$options['name']} -> {$phostname}.{$pdomain['fqdn']}";

    }
    // Process MX record types
    // MX is a domain_id or host/domain_id that points to another dns_id A record
    else if ($options['type'] == 'MX') {
        // If there is no mx_preference set then stop
        if (!isset($options['mx_preference']) or ($options['mx_preference'] < 0 or $options['mx_preference'] > 65536)) {
            $self['error'] = "You must provide an MX preference value when creating MX records!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }
        // Lets try to find the name as a domain first.. if it matches a domain use that, othewise search for an A record
        $hostname = '';
        list($status, $rows, $domain) = ona_get_domain_record(array('name' => $options['name']));
        if (!$domain['id']) {
            // Determine the host and domain name portions of the pointsto option
            // Find the domain name piece of $search
            list($status, $rows, $domain) = ona_find_domain($options['name']);
            printmsg("ona_find_domain({$options['name']}) returned: {$domain['fqdn']}.", 'debug');

            // Now find what the host part of $search is
            $hostname = str_replace(".{$domain['fqdn']}", '', $options['name']);

            // Validate that the DNS name has only valid characters in it
            $hostname = sanitize_hostname($hostname);
            if (!$hostname) {
                $self['error'] = "Invalid host name ({$options['name']})!";
                printmsg($self['error'], 'error');
                return(array(4, $self['error']));
            }
            // Debugging
            printmsg("Using hostname: {$hostname}.{$domain['fqdn']}, Domain ID: {$domain['id']}", 'debug');
        }

        // Determine the host and domain name portions of the pointsto option
        // Find the domain name piece of $search
        list($status, $rows, $pdomain) = ona_find_domain($options['pointsto']);
        printmsg("ona_find_domain({$options['pointsto']}) returned: {$pdomain['fqdn']} for pointsto.", 'debug');

        // Now find what the host part of $search is
        $phostname = str_replace(".{$pdomain['fqdn']}", '', $options['pointsto']);

        // Validate that the DNS name has only valid characters in it
        $phostname = sanitize_hostname($phostname);
        if (!$phostname) {
            $self['error'] = "Invalid pointsto host name ({$options['pointsto']})!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }
        // Debugging
        printmsg("Using 'pointsto' hostname: {$phostname}.{$pdomain['fqdn']}, Domain ID: {$pdomain['id']}", 'debug');

        // Find the dns record that it will point to
        list($status, $rows, $pointsto_record) = ona_get_dns_record(array('name' => $phostname, 'domain_id' => $pdomain['id'], 'type' => 'A','dns_view_id' => $add_viewid));
        if ($status or !$rows) {
            $self['error'] = "Unable to find DNS A record to point NS entry to!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }


        $add_name = $hostname;
        $add_domainid = $domain['id'];
        $add_interfaceid = $pointsto_record['interface_id'];
        $add_dnsid = $pointsto_record['id'];
        $add_mx_preference = $options['mx_preference'];

        // Dont print a dot unless hostname has a value
        if (isset($hostname)) $hostname = $hostname.'.';

        $info_msg = "{$hostname}{$domain['fqdn']} -> {$phostname}.{$pdomain['fqdn']}";


    }

    // Process SRV record types
    // SRV is a domain_id that points to another dns_id A record
    // this will give you "_crap._tcp.mydomain.com    IN   SRV 0 2 80  server.somedomain.com"
    else if ($options['type'] == 'SRV') {

        // If there is no srv_pri set then stop
        if (!isset($options['srv_pri']) or ($options['srv_pri'] < 0 or $options['srv_pri'] > 65536)) {
            $self['error'] = "You must provide an SRV priority value between 0-65535 when creating SRV records!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }

        // If there is no srv_weight set then stop
        if (!isset($options['srv_weight']) or ($options['srv_weight'] < 0 or $options['srv_weight'] > 65536)) {
            $self['error'] = "You must provide an SRV weight value between 0-65535 when creating SRV records!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }

        // If there is no srv_port set then stop
        if (!isset($options['srv_port']) or ($options['srv_port'] < 0 or $options['srv_port'] > 65536)) {
            $self['error'] = "You must provide an SRV port value between 0-65535 when creating SRV records!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }

        // find the domain
        list($status, $rows, $domain) = ona_find_domain($options['name'],0);
        if (!$domain['id']) {
            $self['error'] = "Invalid domain name ({$options['name']})!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }
        // Determine the host and domain name portions of the pointsto option
        // Find the domain name piece of $search
        list($status, $rows, $pdomain) = ona_find_domain($options['pointsto']);
        printmsg("ona_find_domain({$options['pointsto']}) returned: {$pdomain['fqdn']} for pointsto.", 'debug');

        // Now find what the host part of $search is
        $phostname = str_replace(".{$pdomain['fqdn']}", '', $options['pointsto']);

        // Validate that the DNS name has only valid characters in it
        $phostname = sanitize_hostname($phostname);
        if (!$phostname) {
            $self['error'] = "Invalid pointsto host name ({$options['pointsto']})!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }
        // Debugging
        printmsg("Using 'pointsto' hostname: {$phostname}.{$pdomain['fqdn']}, Domain ID: {$pdomain['id']}", 'debug');

        // Find the dns record that it will point to
        list($status, $rows, $pointsto_record) = ona_get_dns_record(array('name' => $phostname, 'domain_id' => $pdomain['id'], 'type' => 'A','dns_view_id' => $add_viewid));
        if ($status or !$rows) {
            $self['error'] = "Unable to find DNS A record to point SRV entry to!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }

        // Validate that there are no records already with this domain and host
        list($status, $rows, $record) = ona_get_dns_record(array('dns_id' => $pointsto_record['id'], 'name' => $hostname, 'domain_id' => $domain['id'],'type' => 'SRV','dns_view_id' => $add_viewid));
        if ($rows or $status) {
            $self['error'] = "Another DNS SRV record for {$hostname}.{$domain['fqdn']} pointing to {$options['pointsto']} already exists!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }


        $add_name = $hostname;
        $add_domainid = $domain['id'];
        $add_interfaceid = $pointsto_record['interface_id'];
        $add_dnsid = $pointsto_record['id'];
        $add_srv_pri = $options['srv_pri'];
        $add_srv_weight = $options['srv_weight'];
        $add_srv_port = $options['srv_port'];

        // Dont print a dot unless hostname has a value
        if (isset($hostname)) $hostname = $hostname.'.';

        $info_msg = "{$hostname}{$domain['fqdn']} -> {$phostname}.{$pdomain['fqdn']}";

    }


    // Process TXT record types
    else if ($options['type'] == 'TXT') {
        // There are 3 types of txt record storage
        // 1. txt that is associated to another A record.  So when that A name gets changed so does this TXT
        // 2. txt associated to just a domain.  I.e. no hostname only a domain_id
        // 3. txt that is arbitrary and not associated with another A record.  has name, domain_id but no dns_id

        // Set interface id to zero by default, only needed if associating with an IP address
        $add_interfaceid = 0;

        // Blank dnsid first.. normally it wont get set, unless it does match up to another record
        $add_dnsid = 0;

        // lets try and determine the interface record using the name passed in.   Only works if we get one record back
        // this is all to help associate if it can so that when the A record is removed, so is this TXT record.
        if ($hostname != '') {
            list($status, $rows, $hostint) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'],'type' => 'A','dns_view_id' => $add_viewid));
            if ($rows == 1) {
                $add_interfaceid = $hostint['interface_id'];
                $add_dnsid = $hostint['id'];
            }
        }


        // If you want to associate a TXT record with a host you need to provide an IP.. otherwise it will just be associated with the domain its in.
        // I might also check here that if there is no $hostname, then dont use the IP address value even if it is passed
        if ($options['ip']) {
            // find the IP interface record,
            list($status, $rows, $interface) = ona_find_interface($options['ip']);
            if (!$rows) {
                $self['error'] = "Unable to find IP interface: {$options['ip']}. TXT records must point to existing IP addresses.  Please add an interface with this IP address first.";
                printmsg($self['error'], 'error');
                return(array(4, $self['error']));
            }

            $add_interfaceid = $interface['id'];
        }



        // Validate that there are no TXT already with this domain and host
        list($status, $rows, $record) = ona_get_dns_record(array('txt' => $options['txt'], 'name' => $hostname, 'domain_id' => $domain['id'],'type' => 'TXT','dns_view_id' => $add_viewid));
        if ($rows or $status) {
            $self['error'] = "Another DNS TXT record for {$options['name']} with that text value already exists!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }



        $add_name = $hostname;
        $add_domainid = $domain['id'];

        $options['txt'] = str_replace('\\=','=',$options['txt']);
        $options['txt'] = str_replace('\\&','&',$options['txt']);
        $add_txt = $options['txt'];

        // Dont print a dot unless hostname has a value
        if (isset($hostname)) $hostname = $hostname.'.';

        $info_msg = "{$hostname}{$domain['fqdn']}";

    }
    // If it is not a recognized record type, bail out!
    else {
            $self['error'] = "Invalid DNS record type: {$options['type']}!";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
    }





    //FIXME: MP, will this use its own dns_record_add permission? or use host_add?
    // Check permissions
    if (!auth('host_add')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(10, $self['error']));
    }

    // Get the next ID for the new dns record
    $id = ona_get_next_id('dns');
    if (!$id) {
        $self['error'] = "The ona_get_next_id('dns') call failed!";
        printmsg($self['error'], 'error');
        return(array(7, $self['error']));
    }
    printmsg("ID for new dns record: $id", 'debug');

    // If a ttl was passed use it, otherwise use what was in the domain minimum
    if ($options['ttl']) { $add_ttl = $options['ttl']; } else { $add_ttl = 0; }

    // There is an issue with escaping '=' and '&'.  We need to avoid adding escape characters
    $options['notes'] = str_replace('\\=','=',$options['notes']);
    $options['notes'] = str_replace('\\&','&',$options['notes']);

    // Add the dns record
    list($status, $rows) = db_insert_record(
        $onadb,
        'dns',
        array(
            'id'                   => $id,
            'domain_id'            => $add_domainid,
            'interface_id'         => $add_interfaceid,
            'dns_id'               => $add_dnsid,
            'type'                 => $options['type'],
            'ttl'                  => $add_ttl,
            'name'                 => $add_name,
            'mx_preference'        => $add_mx_preference,
            'txt'                  => $add_txt,
            'srv_pri'              => $add_srv_pri,
            'srv_weight'           => $add_srv_weight,
            'srv_port'             => $add_srv_port,
            'ebegin'               => $options['ebegin'],
            'notes'                => $options['notes'],
            'dns_view_id'          => $add_viewid
       )
    );
    if ($status or !$rows) {
        $self['error'] = "SQL Query failed adding dns record: " . $self['error'];
        printmsg($self['error'], 'error');
        return(array(6, $self['error']));
    }

    // If it is an A record and they have specified to auto add the PTR record for it.
    if ($options['addptr'] == 'Y' and $options['type'] == 'A') {
        printmsg("Auto adding a PTR record for {$options['name']}.", 'notice');
        // Run dns_record_add as a PTR type
        list($status, $output) = run_module('dns_record_add', array('dns_record' => $options['name'],'domain' => $domain['fqdn'],'ip' => $options['ip'],'ebegin' => $options['ebegin'],'type' => 'PTR','view' => $add_viewid));
        if ($status) {
            return(array($status, $output));
            #printmsg($output,3);
        }
    }

    // TRIGGER: Since we are adding a new record, lets mark the domain for rebuild on its servers
    list($status, $rows) = db_update_record($onadb, 'dns_server_domains', array('domain_id' => $add_domainid), array('rebuild_flag' => 1));
    if ($status) {
        $self['error'] = "Unable to update rebuild flags for domain.: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(7, $self['error']));
    }

    $result['status_msg'] = 'DNS record added.';
    $result['module_version'] = $version;
    list($status, $rows, $result['dns_records'][0]) = ona_get_dns_record(array('id' => $id));

    ksort($result['dns_records'][0]);

    $msg = "DNS {$options['type']} record ADDED: {$info_msg}";
    printmsg($msg, 'notice');

    // Return the success notice
    return(array(0, $result));
}











///////////////////////////////////////////////////////////////////////
//  Function: dns_record_modify (string $options='')
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
//  Example: list($status, $result) = dns_record_modify('FIXME: blah blah blah');
///////////////////////////////////////////////////////////////////////
function dns_record_modify($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if ((!isset($options['set_name']) and
        !isset($options['set_ip']) and
        !isset($options['set_ttl']) and
        !isset($options['set_pointsto']) and
        !isset($options['set_srv_pri']) and
        !isset($options['set_srv_weight']) and
        !isset($options['set_srv_port']) and
        !isset($options['set_mx_preference']) and
        !isset($options['set_notes']) and
        !isset($options['set_view'])
       ) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,
<<<EOM

dns_record_modify-v{$version}
Modify a DNS record

  Synopsis: dns_record_modify [KEY=VALUE] ...

  Where:
    name=NAME[.DOMAIN] or ID    select dns record by name or ID

  Update:
    set_name=NAME[.DOMAIN]      change name and/or domain
    set_ip=ADDRESS              change IP the record points to
    set_ttl=NUMBER              change the TTL value, 0 = use domains TTL value
    set_pointsto=NAME[.DOMAIN]  change where a CNAME points
    set_notes=NOTES             change the textual notes
    set_mx_preference=NUMBER    change the MX record preference value
    set_txt=STRING              change the value of the TXT record
    set_srv_pri=NUMBER          change SRV Priority
    set_srv_weight=NUMBER       change SRV Weight
    set_srv_port=NUMBER         change SRV Port
    set_ebegin                  change the begin date for record, 0 disables
    set_domain=DOMAIN           use if you need to explicitly set domain
    set_view=STRING             change DNS view identifier. AKA Split horizon.

  Note:
    * You are not allowed to change the type of the DNS record, to do that
      you must delete and re-add the record with the new type.
    * DOMAIN will default to {$conf['dns_defaultdomain']} if not specified
\n
EOM
        ));
    }


/* Modify logic

1. find the dns record we are editing
2. If it is an A, check that the name we are changing to does not already match an existing A/ip or CNAME
3. if its a CNAME, check that it is not the same as any other records.

*/

    // Check permissions
    if (!auth('host_modify')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(10, $self['error']));
    }


    // Sanitize addptr.. set it to Y if it is not set
    if (isset($options['set_addptr']))
      $options['set_addptr'] = sanitize_YN($options['set_addptr'], 'Y');

    // clean up what is passed in
    if (isset($options['set_ip']))
      $options['set_ip'] = trim($options['set_ip']);
    if (isset($options['set_pointsto']))
      $options['set_pointsto'] = trim($options['set_pointsto']);
    if (isset($options['set_name']))
      $options['set_name'] = trim($options['set_name']);
    if (isset($options['set_domain']))
      $options['set_domain'] = trim($options['set_domain']);
    if (isset($options['set_txt']))
      $options['set_txt'] = trim($options['set_txt']);
    //$options['set_view'] = trim($options['set_view']);
    //
    // Find the dns record we're modifying
    //

    // If the name we were passed has a leading . in it then remove the dot.
    if (isset($options['set_name']))
      $options['set_name'] = preg_replace("/^\./", '', $options['set_name']);

    // Find the DNS record from $options['dns_record']
    list($status, $rows, $dns) = ona_find_dns_record($options['dns_record']);
    printmsg("DNS record: {$dns['fqdn']}", 'debug');
    if ($rows > 1) {
        $self['error'] = "Found more than one DNS record for: {$options['dns_record']}";
        printmsg($self['error'], 'error');
        return(array(2, $self['error']));
    }


    // If we didn't get a record then exit
    if (!$dns['id']) {
        $self['error'] = "DNS record not found ({$options['dns_record']})!";
        printmsg($self['error'], 'error');
        return(array(4, $self['error']));
    }

    // Set the current_name variable with the records current name
    // Used by the add pointer function below since it runs before any names are updated
    $current_name = $dns['fqdn'];
    $current_int_id = $dns['interface_id'];
    $check_dns_view_id = $dns['dns_view_id'];
    $current_dns_view_id = $dns['dns_view_id'];

    // Set status on if we are chaning IP addresses
    $changingint = 0;
    $changingview = 0;

    // Set a message to display when using dns views
    if ($conf['dns_views']) $viewmsg = ' Ensure you are selecting the proper DNS view for this record.';

    //
    // Define the records we're updating
    //

    // This variable will contain the updated info we'll insert into the DB
    $SET = array();

    // Gather DNS view information
    if (array_key_exists('set_view',$options)) {
        if (is_numeric($options['set_view'])) {
            $viewsearch = array('id' => $options['set_view']);
        } else {
            $viewsearch = array('name' => strtoupper($options['set_view']));
        }
        // find the IP interface record,
        list($status, $rows, $dnsview) = ona_get_dns_view_record($viewsearch);
        if (!$rows) {
            $self['error'] = "Unable to find DNS view: {$options['set_view']}.";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }

        // If we have a new dns view, add it to the SET array and update the check view variable used in all the checks.
        if($dns['dns_view_id'] != $dnsview['id']) {

            // You can only change the view on parent records.. if this record has a dns_id, you must change the parent
            if ($dns['dns_id']) {
                $self['error'] = "You must change the parent DNS A record to the new view.  This record will follow.";
                printmsg($self['error'], 'error');
                return(array(5, $self['error']));
            }

            $SET['dns_view_id'] = $dnsview['id'];
            $check_dns_view_id = $dnsview['id'];
            $changingview = 1;
        }
    }

    // Checking the IP setting first to estabilish if we are changing the IP so I can check the new combo of A/ip later
    if (isset($options['set_ip']) and ($options['set_ip'] != '0.0.0.0')) {
        // find the IP interface record, to ensure it is valid
        list($status, $rows, $interface) = ona_find_interface($options['set_ip']);
        if (!$rows) {
            $self['error'] = "Unable to find IP interface: {$options['set_ip']}\n";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }

        // If they actually changed the ip address
        if ($interface['id'] != $dns['interface_id']) {
            // check for child records that would match our new values
            // I think they will always be just PTR records so I am only selecting that type for now?
            list($status, $rows, $dnschild) = ona_get_dns_record(array('dns_id' => $dns['id'], 'interface_id' => $interface['id'], 'type' => 'PTR'));
            if ($rows) {
                $self['error'] = "This change results in a duplicate child DNS record: PTR {$options['set_ip']}. Delete existing PTR record first.";
                printmsg($self['error'], 'error');
                return(array(4, $self['error']));
            }

            $changingint = 1;
            $SET['interface_id'] = $interface['id'];

            // get the info on the original interface
            list($status, $rows, $origint) = ona_get_interface_record(array('id' => $dns['interface_id']));
        }
    }

    // Set options['set_name']?
    // Validate that the DNS name has only valid characters in it
    if (isset($options['set_name'])) {

        // If we are specifically passing in a domain, use its value.  If we dont have a domain
        // then try to find it in the name that we are setting.
        if(isset($options['set_domain'])) {
            // Find the domain name piece of $search
            list($status, $rows, $domain) = ona_find_domain($options['set_domain'],0);
        } else {
            list($status, $rows, $domain) = ona_find_domain($options['set_name'],0);
        }

        // Find the domain name piece of $search
        if (!isset($domain['id'])) {
            $self['error'] = "Unable to determine domain name portion of ({$options['set_name']})!";
            printmsg($self['error'], 'error');
            return(array(3, $self['error']));
        }
        printmsg("ona_find_domain({$options['set_name']}) returned: {$domain['fqdn']} for new name.", 'debug');

        // Now find what the host part of $search is
        $hostname = str_replace(".{$domain['fqdn']}", '', $options['set_name']);

        // Validate that the DNS name has only valid characters in it
        $hostname = sanitize_hostname($hostname);
        if (!$hostname) {
            $self['error'] = "Invalid host name ({$options['set_name']})!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }

        // If the hostname we came up with and the domain name are the same, then assume this is
        // meant to be a domain specific record, like A, MX, NS type records.
        if ($hostname == $domain['fqdn']) $hostname = '';

        // Debugging
        printmsg("Using hostname: {$hostname}.{$domain['fqdn']}, Domain ID: {$domain['id']}", 'debug');


        // if it is an a record and we are changing the name.. make sure there is not already an A with that name/ip combo
        if ($dns['type'] == 'A') {
            // If we are changing the interface id as determined above, check using that value
            if ($changingint) $dns['interface_id'] = $SET['interface_id'];
            list($status, $rows, $tmp) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'], 'interface_id' => $dns['interface_id'], 'type' => 'A','dns_view_id' => $check_dns_view_id));
            if ($rows) {
                if ($tmp['id'] != $dns['id'] or $rows > 1) {
                    $self['error'] = "There is already an A record with that name and IP address!{$viewmsg}";
                    printmsg($self['error'], 'error');
                    return(array(5, $self['error']));
                }
            }
        }

        // make sure that name/pointsto combo doesnt already exist
        if ($dns['type'] == 'CNAME' or $dns['type'] == 'MX' or $dns['type'] == 'NS' or $dns['type'] == 'SRV') {
            list($status, $rows, $tmp) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'], 'dns_id' => $dns['dns_id'], 'type' => $dns['type'],'dns_view_id' => $check_dns_view_id));
            if ($rows) {
                if ($tmp['id'] != $dns['id'] or $rows > 1) {
                    $self['error'] = "There is already a {$dns['type']} with that name pointing to that A record!{$viewmsg}";
                    printmsg($self['error'], 'error');
                    return(array(6, $self['error']));
                }
            }
        }

        if ($dns['type'] == 'CNAME') {
            // if it is a CNAME, make sure the new name is not an A record name already
            list($status, $rows, $tmp) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'], 'type' => 'A','dns_view_id' => $check_dns_view_id));
            if ($status or $rows) {
                $self['error'] = "There is already an A record with that name!{$viewmsg}";
                printmsg($self['error'], 'error');
                return(array(7, $self['error']));
            }
        }

        // lets try and determine the interface record using the name passed in.   Only works if we get one record back
        // this is all to help associate if it can so that when the A record is removed, so is this TXT record.
        if ($dns['type'] == 'TXT') {
            // if we are dealing with a change to a domain only.. then blank the interface id and dns_id
            if ($hostname == '') {
                $SET['interface_id'] = '';
                $SET['dns_id'] = '';
            } else {
                list($status, $rows, $hostint) = ona_get_dns_record(array('name' => $hostname, 'domain_id' => $domain['id'],'type' => 'A','dns_view_id' => $check_dns_view_id));
                if ($rows == 1) {
                    $SET['interface_id'] = $hostint['interface_id'];
                    $SET['dns_id'] = $hostint['id'];
                    $SET['name']   = $hostname;
                }
            }
        }


        // If you have actually changed the name from what it was, set the new variable $SET
        if($dns['name']      != $hostname)
            $SET['name']      = $hostname;
        if($dns['domain_id'] != $domain['id'])
            $SET['domain_id'] = $domain['id'];
    }




    // If we are modifying a pointsto option
    if (array_key_exists('set_pointsto', $options) and ($options['set_type'] == 'CNAME' or $options['set_type'] == 'MX' or $options['set_type'] == 'NS' or $options['set_type'] == 'SRV')) {
        // Determine the host and domain name portions of the pointsto option
        // Find the domain name piece of $search
        list($status, $rows, $pdomain) = ona_find_domain($options['set_pointsto']);
        printmsg("ona_find_domain({$options['set_pointsto']}) returned: {$domain['fqdn']} for pointsto.", 'debug');

        // Now find what the host part of $search is
        $phostname = str_replace(".{$pdomain['fqdn']}", '', $options['set_pointsto']);

        // Validate that the DNS name has only valid characters in it
        $phostname = sanitize_hostname($phostname);
        if (!$phostname) {
            $self['error'] = "Invalid pointsto host name ({$options['set_pointsto']})!";
            printmsg($self['error'], 'error');
            return(array(4, $self['error']));
        }
        // Debugging
        printmsg("Using 'pointsto' hostname: {$phostname}.{$pdomain['fqdn']}, Domain ID: {$pdomain['id']}", 'debug');


        // Find the dns record that it will point to
        list($status, $rows, $pointsto_record) = ona_get_dns_record(array('name' => $phostname, 'domain_id' => $pdomain['id'], 'type' => 'A','dns_view_id' => $check_dns_view_id));
        if ($status or !$rows) {
            $self['error'] = "Unable to find DNS A record to point {$options['set_type']} entry to!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }


        // Validate that there are no entries already pointed to the new A record
        list($c_status, $c_rows, $c_record) = ona_get_dns_record(array('name' => $dns['name'], 'domain_id' => $dns['domain_id'], 'dns_id' => $pointsto_record['id'], 'type' => $options['set_type'],'dns_view_id' => $check_dns_view_id));
        if ($c_record['id'] != $dns['id'] and $c_rows) {
            $self['error'] = "Another DNS {$options['set_type']} record exists with the values you've selected!{$viewmsg}";
            printmsg($self['error'], 'error');
            return(array(5, $self['error']));
        }



        $SET['dns_id'] = $pointsto_record['id'];
        $SET['interface_id'] = $pointsto_record['interface_id'];


    }



    // Set options['set_notes'] (it can be a null string!)
    if (array_key_exists('set_notes', $options)) {
        // There is an issue with escaping '=' and '&'.  We need to avoid adding escape characters
        $options['set_notes'] = str_replace('\\=','=',$options['set_notes']);
        $options['set_notes'] = str_replace('\\&','&',$options['set_notes']);
        // If it changed...
        if ($dns['notes'] != $options['set_notes'])
            $SET['notes'] = $options['set_notes'];
    }

    // Check the date formatting etc
    if (isset($options['set_ebegin']) and $options['set_ebegin'] != $dns['ebegin']) {
        // format the time that was passed in for the database, leave it as 0 if they pass it as 0
        $options['set_ebegin'] = ($options['set_ebegin'] == '0' ? 0 : date('Y-m-j G:i:s',strtotime($options['set_ebegin'])) );
        // Force the SET variable if its ont 0 and the current record is not 0000:00:00 00:00
        if (!(($options['set_ebegin'] == '0') and ($dns['ebegin'] == '0000-00-00 00:00:00')))
            $SET['ebegin'] = $options['set_ebegin'];
    } else {
        // If I got no date, use right now as the date/time
        $options['set_ebegin'] = date('Y-m-j G:i:s');
    }

    // Add the remaining items to the $SET variable
    // if there is a ttl setting and it is not the same as the existing record
    if (array_key_exists('set_ttl', $options) and $options['set_ttl'] != $dns['ttl'])
        $SET['ttl'] = $options['set_ttl'];

    if (array_key_exists('set_mx_preference', $options) and $options['set_mx_preference'] != $dns['mx_preference'])
        $SET['mx_preference'] = $options['set_mx_preference'];

    if (array_key_exists('set_srv_pri', $options) and $options['set_srv_pri'] != $dns['srv_pri'])
        $SET['srv_pri'] = $options['set_srv_pri'];
    if (array_key_exists('set_srv_weight', $options) and $options['set_srv_weight'] != $dns['srv_weight'])
        $SET['srv_weight'] = $options['set_srv_weight'];
    if (array_key_exists('set_srv_port', $options) and $options['set_srv_port'] != $dns['srv_port'])
        $SET['srv_port'] = $options['set_srv_port'];

    if (array_key_exists('set_txt', $options)) {
        // There is an issue with escaping '=' and '&'.  We need to avoid adding escape characters
        $options['set_txt'] = str_replace('\\=','=',$options['set_txt']);
        $options['set_txt'] = str_replace('\\&','&',$options['set_txt']);
        // If it changed...
        if ($dns['txt'] != $options['set_txt'])
            $SET['txt'] = $options['set_txt'];
    }



    // If it is an A record and they have specified to auto add the PTR record for it.
    if ($options['set_addptr'] == 'Y' and $options['set_type'] == 'A') {
        printmsg("Auto adding a PTR record for {$options['set_name']}.", 'notice');
        // Run dns_record_add as a PTR type
        // Always use the $current_name variable as the name might change during the update
        list($status, $output) = run_module('dns_record_add', array('dns_record' => $current_name, 'domain' => $domain['fqdn'], 'ip' => $options['set_ip'],'ebegin' => $options['set_ebegin'],'type' => 'PTR','view' => $check_dns_view_id));
        if ($status)
            return(array($status, $output));
            #printmsg($text);
    }


    // Get the dns record before updating (logging)
    $original_record = $dns;


    // Update the host record if necessary
    //if(count($SET) > 0 and $options['set_ebegin'] != $dns['ebegin']) {
    if(count($SET) > 0) {

        // Use the ebegin value set above
        $SET['ebegin'] = $options['set_ebegin'];

        // If we are changing the interface id as determined above, check using that value
        if ($changingint) {
            // If the interface id has changed, make sure any child records are updated first
            if ($SET['interface_id'] != $current_int_id) {
                printmsg("Updating child interfaces to new interface.", 'notice');
                list($status, $rows) = db_update_record($onadb, 'dns', array('dns_id' => $dns['id'], 'interface_id' => $current_int_id), array('interface_id' => $SET['interface_id']));
                if ($status) {
                    $self['error'] = "SQL Query failed for dns record: " . $self['error'];
                    printmsg($self['error'], 'error');
                    return(array(11, $self['error']));
                }
                // TODO: may need set rebuild flag on each of the domains related to  these child records that just changed
            }

            // Check the PTR record has the proper domain still
            $ipflip = ip_mangle($interface['ip_addr_text'],'flip');
            $octets = explode(".",$ipflip);
            if (count($octets) > 4) {
                $arpa = '.ip6.arpa';
                $octcount = 31;
            } else {
                $arpa = '.in-addr.arpa';
                $octcount = 3;
            }
            // Find a pointer zone for this record to associate with.
            list($status, $prows, $ptrdomain) = ona_find_domain($ipflip.$arpa);
            list($status, $drrows, $dnsrec) = ona_get_dns_record(array('type' => 'PTR','interface_id' => $SET['interface_id'],'dns_view_id' => $check_dns_view_id));

            // TRIGGER: we made a change and need to update the CURRENT PTR record as well, only sets it if the ptrdomain changes
            list($status, $rows) = db_update_record($onadb, 'dns_server_domains', array('domain_id' => $dnsrec['domain_id']), array('rebuild_flag' => 1));
            if ($status) {
                $self['error'] = "Unable to update rebuild flags for domain.: {$self['error']}";
                printmsg($self['error'],'error');
                return(array(7, $self['error']));
            }

            // if we find any PTR records and the domain has chaned, make sure the child PTR records have the updated PTR domain info.
            if (isset($ptrdomain['id']) and $drrows>0 and $dnsrec['domain_id'] != $ptrdomain['id']) {
                list($status, $rows) = db_update_record($onadb, 'dns', array('id' => $dnsrec['id']), array('domain_id' => $ptrdomain['id'], 'ebegin' => $SET['ebegin']));
                if ($status or !$rows) {
                    $self['error'] = "Child PTR record domain update failed: " . $self['error'];
                    printmsg($self['error'], 'error');
                    return(array(14, $self['error']));
                }



                // TRIGGER: we made a change and need to update the NEW PTR record as well, only sets it if the ptrdomain changes
                list($status, $rows) = db_update_record($onadb, 'dns_server_domains', array('domain_id' => $ptrdomain['id']), array('rebuild_flag' => 1));
                if ($status) {
                    $self['error'] = "Unable to update rebuild flags for domain.: {$self['error']}";
                    printmsg($self['error'],'error');
                    return(array(7, $self['error']));
                }
            }
        }

        // If we are changing the view, we must change all other DNS records that point to this one to the same view.
        if ($changingview) {
            if ($SET['dns_view_id'] != $current_dns_view_id) {
                printmsg("Updating child DNS records to new dns view.", 'notice');
                list($status, $rows) = db_update_record($onadb, 'dns', array('dns_id' => $dns['id']), array('dns_view_id' => $SET['dns_view_id']));
                if ($status) {
                    $self['error'] = "SQL Query failed for dns record child view updates: " . $self['error'];
                    printmsg($self['error'], 'error');
                    return(array(11, $self['error']));
                }
            }

            // TRIGGER: yep I probably need one here  FIXME

        }


       // Make sure we us A type for both A and AAAA
       if ($SET['type'] == 'AAAA') $SET['type'] = 'A';

        // Change the actual DNS record
        list($status, $rows) = db_update_record($onadb, 'dns', array('id' => $dns['id']), $SET);
        if ($status or !$rows) {
            $self['error'] = "SQL Query failed for dns record: " . $self['error'];
            printmsg($self['error'], 'error');
            return(array(12, $self['error']));
        }

        // TRIGGER: we made a change, lets mark the domain for rebuild on its servers
        list($status, $rows) = db_update_record($onadb, 'dns_server_domains', array('domain_id' => $dns['domain_id']), array('rebuild_flag' => 1));
        if ($status) {
            $self['error'] = "Unable to update rebuild flags for domain.: {$self['error']}";
            printmsg($self['error'],'error');
            return(array(7, $self['error']));
        }

        // TRIGGER: If we are changing domains, lets flag the new domain as well, lets mark the domain for rebuild on its servers
        if($SET['domain_id']) {
            list($status, $rows) = db_update_record($onadb, 'dns_server_domains', array('domain_id' => $SET['domain_id']), array('rebuild_flag' => 1));
            if ($status) {
                $self['error'] = "Unable to update rebuild flags for domain.: {$self['error']}";
                printmsg($self['error'],'error');
                return(array(7, $self['error']));
            }
        }


    }

    // Get the host record after updating (logging)
    list($status, $rows, $new_record) = ona_get_dns_record(array('id' => $dns['id']));

    // Return the success notice
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
      $log_msg = "DNS record UPDATED:{$dns['id']}: {$log_msg}";
    } else {
      $log_msg = "DNS record UPDATED:{$dns['id']}: Update attempt produced no changes.";
    }

    printmsg($log_msg, 'notice');
    return(array(0, $log_msg));
}










///////////////////////////////////////////////////////////////////////
//  Function: dns_record_del (string $options='')
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
//  Example: list($status, $result) = dns_record_del('name=test');
///////////////////////////////////////////////////////////////////////
function dns_record_del($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    // Return the usage summary if we need to
    if (!isset($options['dns_record'])) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,
<<<EOM

dns_record_del-v{$version}
Deletes a DNS record from the database

  Synopsis: dns_record_del [KEY=VALUE] ...

  Required:
    name=NAME[.DOMAIN] or ID      hostname or ID of the record to delete
    type=TYPE                     record type (A,CNAME,PTR...)

  Optional:
    ip=ADDRESS                    ip address (numeric or dotted)
    commit=[yes|no]               commit db transaction (no)

\n
EOM
        ));
    }
/*
thoughts on the flow of things:

A records:
    remove any CNAMES using this A record
    remove any PTR records using this A record
    test that it is not a primary_dns_id, if it is, it must be reassigned


should make a find_dns_record(s) function.  a find by host option would be good.

need to do a better delete of DNS records when deleting a host.. currently its a problem.

MP: TODO:  this delete will not handle DNS views unless you use the ID of the record to delete.  add a view option at some point.

*/

    // If the name we were passed has a leading . in it then remove the dot.
    $options['dns_record'] = preg_replace("/^\./", '', $options['dns_record']);

    if (!isset($options['type']))
      $options['type'] = '';

    // FIXME: MP Fix this to use a find_dns_record function  ID only for now
    // Find the DNS record from $options['dns_record']
    list($status, $rows, $dns) = ona_find_dns_record($options['dns_record'], $options['type']);
    printmsg("DNS record: {$options['dns_record']}", 'debug');
    if (!$dns['id']) {
        $self['error'] = "Unknown DNS record: {$options['dns_record']} ({$options['type']})";
        printmsg($self['error'], 'error');
        return(array(2, $self['error']));
    }


    // Check permissions
    if (!auth('host_del')) {
        $self['error'] = "Permission denied!";
        printmsg($self['error'], 'error');
        return(array(10, $self['error']));
    }

    $text = '';
    $add_to_error = '';

    // SUMMARY:
    //   Display any associated PTR records for an A record
    //   Display any associated CNAMEs for an A record


    // Test if it is used as a primary_dns_id unless it is the host_del module calling
    if (!isset($options['delete_by_module'])) {
        list($status, $rows, $srecord) = db_get_record($onadb, 'hosts', array('primary_dns_id' => $dns['id']));
        if ($rows) {
            $self['error'] = "The DNS record, {$dns['dns_record']}.{$dns['domain_fqdn']}[{$dns['id']}], is a primary A record for a host! You can not delete it until you associate a new primary record, or delete the host.";
            printmsg($self['error'],'error');
            return(array(5, $self['error']));
        }
    }


    // Delete related Points to records
    // get list for logging
    list($status, $rows, $records) = db_get_records($onadb, 'dns', array('dns_id' => $dns['id']));
    // do the delete
    list($status, $rows) = db_delete_records($onadb, 'dns', array('dns_id' => $dns['id']));
    if ($status) {
        $self['error'] = "Child record delete SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(5, $self['error']));
    }
    if ($rows) {
        // log deletions
        // FIXME: do better logging here
        $add_to_error .= "{$rows} child record(s) DELETED from {$dns['fqdn']}";
        printmsg($add_to_error,'notice');
    }

    // TRIGGER: flag the domains for rebuild
    foreach($records as $record) {
        list($status, $rows) = db_update_record($onadb, 'dns_server_domains', array('domain_id' => $record['domain_id']), array('rebuild_flag' => 1));
        if ($status) {
            $self['error'] = "Unable to update rebuild flags for domain.: {$self['error']}";
            printmsg($self['error'],'error');
            return(array(7, $self['error']));
        }
    }


    // Delete the DNS record
    list($status, $rows) = db_delete_records($onadb, 'dns', array('id' => $dns['id']));
    if ($status) {
        $self['error'] = "DNS record delete SQL Query failed: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(5, $add_to_error . $self['error']));
    }

    // TRIGGER: flag the current dnsrecords domain for rebuild
    list($status, $rows) = db_update_record($onadb, 'dns_server_domains', array('domain_id' => $dns['domain_id']), array('rebuild_flag' => 1));
    if ($status) {
        $self['error'] = "Unable to update rebuild flags for domain.: {$self['error']}";
        printmsg($self['error'],'error');
        return(array(7, $self['error']));
    }

    // FIXME: if it is a NS or something display a proper FQDN message here
    // Display proper PTR information
    if ($dns['type'] == 'PTR') {
        list($status, $rows, $pointsto) = ona_get_dns_record(array('id' => $dns['dns_id']), '');
        list($status, $rows, $ptrint) = ona_get_interface_record(array('id' => $dns['interface_id']), '');

        $ipflip = ip_mangle($ptrint['ip_addr'],'flip');
        $octets = explode(".",$ipflip);
        if (count($octets) > 4) {
            $arpa = '.ip6.arpa';
            $octcount = 31;
        } else {
            $arpa = '.in-addr.arpa';
            $octcount = 3;
        }
        $dns['fqdn'] = "{$ipflip}{$arpa} -> {$pointsto['fqdn']}";
    }

    // Return the success notice
    $self['error'] = "DNS {$dns['type']} record DELETED: {$dns['fqdn']}";
    printmsg($self['error'], 'notice');
    return(array(0, $add_to_error . $self['error']));
}











///////////////////////////////////////////////////////////////////////
//  Function: dns_record_display (string $options='')
//
//  $options = key=value pairs of options for this function.
//             multiple sets of key=value pairs should be separated
//             by an "&" symbol.
//
//  Input Options:
//    name=HOSTNAME[.DOMAIN] or ID
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = dns_record_display('name=test');
///////////////////////////////////////////////////////////////////////
function dns_record_display($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    $text_array = array();
    $text_array['module_version'] = $version;

    // Sanitize options[verbose] (default is yes)
    if (isset($options['verbose']))
      $options['verbose'] = sanitize_YN($options['verbose'], 'Y');

    // Return the usage summary if we need to
    if (!isset($options['dns_record']) ) {
        $self['error'] = 'Insufficient parameters';
        return(array(1,$self['error']));
    }


    // If the name we were passed has a leading . in it then remove the dot.
    $options['dns_record'] = preg_replace("/^\./", '', $options['dns_record']);

    // Find the DNS record from $options['dns_record']
    list($status, $rows, $record) = ona_find_dns_record($options['dns_record']);
    if (!$record['id']) {
        $self['error'] = "Unknown DNS record: {$options['dns_record']}";
        printmsg($self['error'], 'error');
        return(array(2, $self['error']));
    }

    // Build text to return
 #   $text  = "DNS {$record['type']} RECORD ({$record['fqdn']})\n";
 #   $text .= format_array($record);

    // If 'verbose' is enabled, grab some additional info to display
    if ($options['verbose'] == 'Y') {

        // PTR record(s)
        $i = 0;
        do {
            list($status, $rows, $ptr) = ona_get_dns_record(array('dns_id' => $record['id'],'type' => 'PTR'));
            if ($rows == 0) { break; }
            ksort($ptr);
            $text_array['associated_dns_records']['ptr'][$i] = $ptr;
            $i++;
        } while ($i < $rows);

        // CNAME record(s)
        $i = 0;
        do {
            list($status, $rows, $cname) = ona_get_dns_record(array('dns_id' => $record['id'],'type' => 'CNAME'));
            if ($rows == 0) { break; }
            ksort($cname);
            $text_array['associated_dns_records']['cname'][$i] = $cname;
            $i++;
        } while ($i < $rows);

// FIXME: MP display other types of records like NS,MX,SRV etc etc, also support dns views better


    }

    // Select just the fields requested
    if (isset($options['fields'])) {
      $fields = explode(',', $options['fields']);
      $record = array_intersect_key($record, array_flip($fields));
    }

    ksort($record);
    $text_array['dns_records'][0] = $record;

    // Return the success notice
    return(array(0, $text_array));

}
