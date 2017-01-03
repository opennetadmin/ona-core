<?php

// These are default
$conf = array (
    /* Defaults for some user definable options normally in sys_config table */
   // "debug"                  => '2',
    'log_to_file'            => '0',
    'log_to_syslog'          => '1',
    #'log_to_db'              => '0',
    'log_file'               => './logs/ona.log',
    // Sets the lowest level of logging per rfc 5424
    'log_level'              => 'INFO',
    'log_syslog_facility'    => 'local6',
);


// Configuration for the slim framework
#$slimconfig = [
#    'settings' => [
#        'displayErrorDetails' => true,
#        'addContentLengthHeader' => false,
#    ],
#];

