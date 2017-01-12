<?php

///////////////////////   WARNING   /////////////////////////////
//           This is the bootstrap file.                       //
//                                                             //
//   It is not intended that this file be edited.  Any         //
//   user configurations should be in the etc/config.php file. //
//                                                             //
/////////////////////////////////////////////////////////////////

// Set our base install directory. This is assumed to be the parent of the www dir
$base = dirname(__DIR__);

// Load up our composer installed libraries
require_once($base.'/vendor/autoload.php');





// These variables are default values.  If you want to change them or add to them
// you should edit etc/config.php. Whatever you add there will be merged with this.
$conf_default = array (
    /* General Setup */
    // Database Context
    // For possible values see the $ona_contexts() array  in the database_settings.php file
    'default_context'        => 'DEFAULT',

    // set a default token lifetime
    'token_life'             => '3600',

    /* Settings for dcm.pl */
    'dcm_module_dir'         => "$base/modules",
    'plugin_dir'             => "$base/local/plugins",

    /* Defaults for some user definable options normally in sys_config table */
    'log_to_file'            => '0',
    'log_to_syslog'          => '1',
    'log_to_db'              => '0',
    'log_file'               => './logs/ona.log',
    // Sets the lowest level of logging per rfc 5424
    'log_level'              => 'NOTICE',
    'log_syslog_facility'    => 'local6',

    // A string for signing our auth tokens.  You should set this in your local conf
    'token_signing_key'      => 'CHANGEME!!',

    /* The output charset to be used in htmlentities() and htmlspecialchars() filtering */
    'charset'                => 'utf8',
    'php_charset'            => 'UTF-8',

    // enable the setting of the database character set using the "set name 'charset'" SQL command
    // This should work for mysql and postgres but may not work for Oracle.
    // it will be set to the value in 'charset' above.
    'set_db_charset'         => TRUE,
);


// Configuration for the slim framework, these are default values
// Set your own configurations in etc/config.php
// The entry in etc/config.php will override this one
$slimconfig = [
    'settings' => [
        'displayErrorDetails' => true,
        'addContentLengthHeader' => false,
    ],
];



// Include your own local configuration
@include("{$base}/etc/config.php");

// merge our two config arrays
// NOTE: below we layer in the conf values from sys_config table
$conf = array_merge($conf_default, $conf);





// Read in the version file to our conf variable
// It must have a v<year>.<month>.<day> format to match the check version code.
if (file_exists($base.'/VERSION')) { $conf['version'] = trim(file_get_contents($base.'/VERSION')); }

// The $self array is used to store globally available temporary data.
// Think of it as a cache or an easy way to pass data around ;)
// I've tried to define the entries that are commonly used:
$self = array (
    // Error messages will often get stored in here
    'error'                  => '',
    // All sorts of things get cached in here to speed things up
    'cache'                  => array(),
);



// Include the basic system functions
// any $conf settings used in this "require" should not be user adjusted in the sys_config table
require_once('functions_general.php');

// Include the basic database functions
require_once('functions_db.php');

// Include the AUTH functions
require_once('auth/functions_auth.php');




###### set up logging

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;

// Create the logger
$logger = new Logger('onacore');
if ($conf['log_to_file']) {
  $fileloghandler = new StreamHandler($conf['log_file'], $conf['log_level'] );
  $logger->pushHandler($fileloghandler);
}

if ($conf['log_to_syslog']) {
  $sysloghandler = new SyslogHandler('ona', $conf['log_syslog_facility'], $conf['log_level'] );
  $logger->pushHandler($sysloghandler);
}

// TODO add a database logger option










#### Do I need this check for mb_string if I'm using composer to do install?

// Set multibyte encoding to UTF-8
if (@function_exists('mb_internal_encoding')) {
    mb_internal_encoding("UTF-8");
} else {
    printmsg("Missing 'mb_internal_encoding' function. Please install PHP 'mbstring' functions for proper UTF-8 encoding.", 'notice');
}




##### Connect to the database and load extra config variables from sys_config


// Include the localized Database settings
$dbconffile = "{$base}/etc/database_settings.inc.php";
if (file_exists($dbconffile)) {
    if (substr(exec("php -l $dbconffile"), 0, 28) == "No syntax errors detected in") {
        @include($dbconffile);
    } else {
        $dbconferr = "Syntax error in your DB config file: {$dbconffile}. Please check that it contains a valid PHP formatted array, or check that you have the php cli tools installed. You can perform this check maually using the command 'php -l {$dbconffile}'.";
        printmsg($dbconferr,'alert');
        echo $dbconferr;
        exit(1);
    }
} else {
#    echo "Unable to open database config file: $dbconffile\n";
#    echo "Please run installer: php ${base}/install/install.php\n";
#    exit;
}

/*
TODO: figure out the installer process

// Check to see if the run_install file exists.
// If it does, run the install process.
if (file_exists($base.'/local/config/run_install') or @$runinstaller or @$install_submit == 'Y') {
    // Process the install script
    require_once($base.'/../install/install.php');
    exit;
}
*/

// If we dont have a ona_context set in the cookie, lets set a cookie with the default context
if (!isset($_COOKIE['ona_context_name'])) { $_COOKIE['ona_context_name'] = $conf['default_context']; setcookie("ona_context_name", $conf['default_context']); }

// (Re)Connect to the DB now.
#global $onadb;
$onadb = db_pconnect('', $_COOKIE['ona_context_name']);

// Load the actual user config from the database table sys_config
// These will override any of the defaults set above
list($status, $rows, $records) = db_get_records($onadb, 'sys_config', 'name like "%"', 'name');
foreach ($records as $record) {
    printmsg("Loaded config item from database: {$record['name']}=''{$record['value']}''",'debug');
    $conf[$record['name']] = $record['value'];
}



