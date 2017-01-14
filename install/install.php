#!/usr/bin/php
<?php

// Load our initialization library
require_once(__DIR__.'/../lib/initialize.php');

require_once(__DIR__.'/../vendor/adodb/adodb-php/adodb-xmlschema03.inc.php');

// Init and setup some variables.
$text = '';
$status = 0;
$runinstall = $base.'/www/local/config/run_install';
$xmlfile_tables = $base.'/install/ona-table_schema.xml';
$xmlfile_data = $base.'/install/ona-data.xml';
$new_ver = trim(@file_get_contents($base.'/VERSION'));
$curr_ver = '';

# junk output from included functions
#$stdout = '';
#$syslog = '';
#$log_to_db = '';

$install_complete=1;






echo "\nWELCOME TO THE OPENNETADMIN INSTALLER..\n";

if (!@file_exists($dbconffile)) {
  // Start the menu loop
  while($install_complete) {


    // License info
    echo "ONA is licensed under GPL v2.0.\n";
    $showlicense = promptUser("Would you like to view license? [y/N] ", 'n');
    if ($showlicense == 'y') { system("more -80 {$base}/../docs/LICENSE"); }
    // TODO: Do you agree to the license

    check_requirements();

    new_install();

    echo "\nDONE..\n";

    exit;
  }

} else {
  upgrade();
  exit;
}





// Gather requirement information
function check_requirements() {

  global $base;

  system('clear');
  // Get some pre-requisite information
  $phpversion = phpversion() > '5.0' ? 'PASS' : 'FAIL';
  $hasgmp = function_exists( 'gmp_init' ) ? 'PASS' : 'FAIL';
  //echo function_exists( 'gmp_init' ) ? '' : 'PHP GMP module is missing.';
  $hasmysql = function_exists( 'mysqli_connect' ) ? 'PASS' : 'FAIL';
  $hasxml = function_exists( 'xml_parser_create' ) ? 'PASS' : 'FAIL';
  $hasmbstring = function_exists( 'mb_internal_encoding' ) ? 'PASS' : 'FAIL';
  $dbconfwrite = @is_writable($base.'/etc/') ? 'PASS' : 'FAIL';

  echo <<<EOL

CHECKING PREREQUISITES...

  PHP version greater than 5.0:               $phpversion
  PHP GMP module:                             $hasgmp
  PHP XML module:                             $hasxml
  PHP mysqli function:                        $hasmysql
  PHP mbstring function:                      $hasmbstring
  $base/etc dir writable:                     $dbconfwrite


EOL;
}




/*
// Initial text for the greeting div
$greet_txt = "It looks as though this is your first time running OpenNetAdmin. Please answer a few questions and we'll initialize the system for you. We've pre-populated some of the fields with suggested values.  If the database you specify below already exists, it will be overwritten entirely.";


*/



function upgrade() {
    global $text,$new_ver,$xmlfile_data,$xmlfile_tables,$dbconffile,$status,$base;


    // Get the existing database config so we can connect using its settings
    include($dbconffile);

    $context_count = count($ona_contexts);

    echo "
It looks as though you already have a version of OpenNetAdmin installed.
You should make a backup of the data for each context listed below before proceeding with this upgrade.

We will be upgrading to version '{$new_ver}'.

We have found {$context_count} context(s) in your current db configuration file.

";

    printf("%-20s %-10s %-20s %-20s %-20s %-5s\n", 'Context Name', 'DB type', 'Server', 'DB name', 'Upgrade Index', 'Version');
    echo "--------------------------------------------------------------------------------------------------------------\n";


    // Loop through each context and identify the Databases within
    foreach(array_keys($ona_contexts) as $cname) {

        foreach($ona_contexts[$cname]['databases'] as $cdbs) {
            $curr_ver = 'Unable to determine';
            // Make an initial connection to a DB server without specifying a database
            $db = ADONewConnection($cdbs['db_type']);
            $db->Connect( $cdbs['db_host'], $cdbs['db_login'], $cdbs['db_passwd'], '' );

            if (!$db->IsConnected()) {
                $status++;
                printmsg("Unable to connect to server '{$cdbs['db_host']}'. ".$db->ErrorMsg(),'error');
                $err_txt .= "[{$cname}] Failed to connect as '{$cdbs['db_login']}'\n\n".$db->ErrorMsg()."\n";
            } else {
                if ($db->SelectDB($cdbs['db_database'])) {
                    $rs = $db->Execute("SELECT value FROM sys_config WHERE name like 'version'");
                    $array = $rs->FetchRow();
                    $curr_ver = $array['value'];

                    if ($curr_ver == $new_ver) { $curr_ver = "$curr_ver (no changes will be done)"; }

                    $rs = $db->Execute("SELECT value FROM sys_config WHERE name like 'upgrade_index'");
                    $array = $rs->FetchRow();
                    $upgrade_index = $array['value'];

                    $levelinfo = $upgrade_index;
                } else {
                    $status++;
                    $err_txt .= "[{$cname}] Failed to select DB '{$cdbs['db_database']}'\n\n".$db->ErrorMsg()."\n";
                }
            }
            // Close the database connection
            $db->Close();


            printf("%-20s %-10s %-20s %-20s %-20s %-5s\n", $cname, $cdbs['db_type'], $cdbs['db_host'], $cdbs['db_database'], $levelinfo, $curr_ver);
        }

    }


    if ($status == 0) {
      $upgrade_submit = promptUser("\nPerform the upgrade (y/N)? ", 'N');
    } else {
        echo <<<EOL
There was an error determining database context versions. Please correct them before proceeding.
Check that the content of your database configuration file is accurate
and that the databases themselves are configured properly.

CONTENTS:
'{$dbconffile}'

ERROR:
{$err_txt}
EOL;
      $upgrade_submit = promptUser("\nRetry (y/N)? ", 'N');
// TODO this needs to just start over the process??
    }






    $upgrade_submit = sanitize_YN($upgrade_submit);
    // If they have selected to keep the tables then remove the run_install file
    if ($upgrade_submit == 'N') {
      exit;
    } else {

      echo "\nPlease provide admin credentials to make database updates.\n";

      $admin_login = promptUser("Database admin? ", 'root');
      $admin_passwd = promptUser("Database admin password? ", '');


      // Loop through each context and upgrade the Databases within
      foreach(array_keys($ona_contexts) as $cname) {

        foreach($ona_contexts[$cname]['databases'] as $cdbs) {
            printmsg("[{$cname}/{$cdbs['db_host']}] Performing an upgrade.",'notice');

            // switch from mysqlt to mysql becuase of adodb problems with innodb and opt stuff when doing xml
            $adotype = $cdbs['db_type'];

            // Make an initial connection to a DB server without specifying a database
            $db = ADONewConnection($adotype);
            $db->NConnect( $cdbs['db_host'], $cdbs['db_login'], $cdbs['db_passwd'], '' );

            if (!$db->IsConnected()) {
                $status++;
                printmsg("Unable to connect to server '{$cdbs['db_host']}'. ".$db->ErrorMsg(),'error');
                $text .= "[{$cname}] Failed to connect to '{$cdbs['db_host']}' as '{$cdbs['db_login']}'\n\n".$db->ErrorMsg()."\n";
            } else {
                $db->Close();
                if ($db->NConnect( $cdbs['db_host'], $admin_login, $admin_passwd, $cdbs['db_database'])) {

                    // Get the current upgrade index if there is one.
                    $rs = $db->Execute("SELECT value FROM sys_config WHERE name like 'upgrade_index'");
                    $array = $rs->FetchRow();
                    $upgrade_index = $array['value'];

                    $text .= "[{$cname}/{$cdbs['db_host']}] Keeping your original data.\n";

                    // update existing tables in our database to match our baseline xml schema
                    // create a schema object and build the query array.
                    $schema = new adoSchema( $db );
                    $schema->executeInline( FALSE );
                    // Build the SQL array from the schema XML file
                    $sql = $schema->ParseSchema($xmlfile_tables);
                    // Execute the SQL on the database
                    //$text .= "<pre>".$schema->PrintSQL('TEXT')."</pre>";
                    if ($schema->ExecuteSchema( $sql ) == 2) {
                        $text .= "[{$cname}/{$cdbs['db_host']}] Upgrading tables within database '{$cdbs['db_database']}'\n";
                        printmsg("[{$cname}/{$cdbs['db_host']}] Upgrading tables within database: {$cdbs['db_database']}",'notice');
                    } else {
                        $status++;
                        $text .= "There was an error upgrading tables.\n\n".$db->ErrorMsg()."\n";
                        printmsg("There was an error processing tables: ".$db->ErrorMsg(),'error');
                        break;
                    }





                    $script_text = '';
                    if ($upgrade_index == '') {
                        $text .= "[{$cname}/{$cdbs['db_host']}] Auto upgrades not yet supported. Please see docs/UPGRADES\n";
                    } else {
                        // loop until we have processed all the upgrades
                        while(1 > 0) {
                            // Find out what the next index will be
                            $new_index = $upgrade_index + 1;
                            // Determine file name
                            $upgrade_xmlfile = "{$base}/install/{$upgrade_index}-to-{$new_index}.xml";
                            $upgrade_phpfile = "{$base}/install/{$upgrade_index}-to-{$new_index}.php";
                            // Check that the upgrade script exists
                            if (file_exists($upgrade_phpfile)) {
                                $script_text .= "Please go to a command prompt and execute 'php {$upgrade_phpfile}' manually to complete the upgrade!\n";
                            }
                            // Check that the upgrade file exists
                            if (file_exists($upgrade_xmlfile)) {
                                // get the contents of the sql update file
                                // create new tables in our database
                                // create a schema object and build the query array.
                                $schema = new adoSchema( $db );
                                // Build the SQL array from the schema XML file
                                $sql = $schema->ParseSchema($upgrade_xmlfile);
                                // Execute the SQL on the database
                                if ($schema->ExecuteSchema( $sql ) == 2) {
                                    $text .= "[{$cname}/{$cdbs['db_host']}] Processed XML update file.\n";
                                    printmsg("[{$cname}/{$cdbs['db_host']}] Processed XML update file.",'notice');

                                    // update index info in the DB
                                    $text .= "[{$cname}/{$cdbs['db_host']}] Upgraded from index {$upgrade_index} to {$new_index}.\n";
                                    // Update the upgrade_index element in the sys_config table
                                    if($db->Execute("UPDATE sys_config SET value='{$new_index}' WHERE name like 'upgrade_index'")) {
                                        $text .= "[{$cname}/{$cdbs['db_host']}] Updated DB upgrade_index variable to '{$new_index}'.\n";
                                    }
                                    else {
                                        $status++;
                                        $text .= "[{$cname}/{$cdbs['db_host']}] Failed to update upgrade_index variable in table 'sys_config'.\n";
                                        break;
                                    }
                                    $upgrade_index++;
                                } else {
                                    $status++;
                                    $text .= "[{$cname}/{$cdbs['db_host']}] Failed to process XML update file.\n\n".$db->ErrorMsg()."\n";
                                    printmsg("[{$cname}/{$cdbs['db_host']}] Failed to process XML update file.  ".$db->ErrorMsg(),'error');
                                    break;
                                }
                            } else {
                                break;
                            }
                        }

                    }


                    // Update the version element in the sys_config table if there were no previous errors
                    if($status == 0) {
                        if($db->Execute("UPDATE sys_config SET value='{$new_ver}' WHERE name like 'version'")) {
                            $text .= "[{$cname}/{$cdbs['db_host']}] Updated DB version variable to '{$new_ver}'.\n";
                        }
                        else {
                            $status++;
                            $text .= "[{$cname}/{$cdbs['db_host']}] Failed to update version info in table 'sys_config'.\n";
                        }
                    }
                } else {
                    $status++;
                    $text .= "[{$cname}/{$cdbs['db_host']}] Failed to select DB '{$cdbs['db_database']}'.\n".$db->ErrorMsg()."\n";
                }
            }
            // Close the database connection
            $db->Close();

        }

      } // End loop contexts


      if($status == 0) {
        $text .= $script_text;

        if (@file_exists($runinstall)) {
          if (!@unlink($runinstall)) {
            $text .= "Failed to delete the file '{$runinstall}'.\n";
            $text .= "Please remove '{$runinstall}' manually.\n";
          }
        }
      } else {
        $text .= "There was a fatal error. Upgrade may be incomplete. Fix the issue and try again\n";
      }

  } // End of if upgrade_submit Y option

echo $text;

} // end function 'upgrade'





// This is the section for an brand new install
function new_install() {

    global $text,$xmlfile_data,$xmlfile_tables,$dbconffile,$status;

    // Gather info
    $dbtype = 'mysqli'; $adotype = $dbtype;
    $database_host = promptUser("Database host? ", 'localhost');
    $admin_login = promptUser("Database admin? ", 'root');
    $admin_passwd = promptUser("Database admin password? ", '');
    $sys_login = promptUser("Application Database user name? ", 'ona_sys');
    $sys_passwd = promptUser("Application Database user password? ", 'changeme');
    $database_name = promptUser("Database name? ona_", 'default');
    $default_domain = promptUser("Default DNS domain? ", 'example.com');

    // Just to keep things a little bit grouped, lets prepend the database with ona_
    $database_name = 'ona_'.$database_name;

    // set up initial context connection information
    $context_name = 'DEFAULT';
    $ona_contexts[$context_name]['databases']['0']['db_type']     = $dbtype;
    $ona_contexts[$context_name]['databases']['0']['db_host']     = $database_host;
    $ona_contexts[$context_name]['databases']['0']['db_login']    = $sys_login;
    $ona_contexts[$context_name]['databases']['0']['db_passwd']   = $sys_passwd;
    $ona_contexts[$context_name]['databases']['0']['db_database'] = $database_name;
    $ona_contexts[$context_name]['databases']['0']['db_debug']    = FALSE;
    $ona_contexts[$context_name]['description']   = 'Default data context';
    $ona_contexts[$context_name]['context_color'] = '#D3DBFF';

    $text .= "\n";

    // Make an initial connection to a DB server without specifying a database
    $db = ADONewConnection($adotype);
    $db->NConnect( $database_host, $admin_login, $admin_passwd, '' );

    if (!$db->IsConnected()) {
        $status++;
        $text .= "Failed to connect to '{$database_host}' as '{$admin_login}'.\n".$db->ErrorMsg()."\n";
        printmsg("Unable to connect to server '$database_host'. ".$db->ErrorMsg(),'error');
    } else {
        $text .= "Connected to '{$database_host}' as '{$admin_login}'.\n";

        // Drop out any existing database and user
        if ($db->Execute("DROP DATABASE IF EXISTS {$database_name}")) {
            //@$db->Execute("DROP USER IF EXISTS '{$sys_login}'@'%'");
            $text .= "Dropped existing instance of '{$database_name}'.\n";
            printmsg("Dropped existing DB: $database_name",'notice');
        }
        else {
            $status++;
            $text .= "Failed to drop existing instance of '{$database_name}'.\n".$db->ErrorMsg()."\n";
        }

        // MP TODO: when this is done as part of an add conext, we must copy the system_config data from the default context to populate it
        // so that plugins that have created options will show up etc.  Prompt the user that this happened so they can change what they want.

        // Create the new database
        $datadict = NewDataDictionary($db);
        $sqlarray = $datadict->CreateDatabase($database_name);
        if ($datadict->ExecuteSQLArray($sqlarray) == 2) {
            $text .= "Created new database '{$database_name}'.\n";
            printmsg("Added new DB: $database_name",'notice');
        }
        else {
            $status++;
            $text .= "Failed to create new database '{$database_name}'.\n".$db->ErrorMsg()."\n";
            printmsg("Failed to create new database '{$database_name}'. ".$db->ErrorMsg(),'error');
        }


        // select the new database we just created
        $db->Close();
        if ($db->NConnect( $database_host, $admin_login, $admin_passwd, $database_name)) {

            $text .= "Selected existing DB: '{$database_name}'.\n";

            // create new tables in our database
            // create a schema object and build the query array.
            $schema = new adoSchema( $db );
            // Build the SQL array from the schema XML file
            $sql = $schema->ParseSchema($xmlfile_tables);
// TODO: offer an option to print the raw SQL so it can be done 'manually';
//$text .= "<pre>".$schema->PrintSQL('TEXT')."</pre>";
            // Execute the SQL on the database
            if ($schema->ExecuteSchema( $sql ) == 2) {
                $text .= "Creating and updating tables within database '{$database_name}'.\n";
                printmsg("Creating and updating tables within new DB: {$database_name}",'notice');
            } else {
                $status++;
                $text .= "There was an error processing tables.\n".$db->ErrorMsg()."\n";
                printmsg("There was an error processing tables: ".$db->ErrorMsg(),'error');
            }

             // Load initial data into the new tables
            if ($status == 0) {
                $schema = new adoSchema( $db );
                // Build the SQL array from the schema XML file
                $sql = $schema->ParseSchema($xmlfile_data);
                //$text .= "<pre>".$schema->PrintSQL('TEXT')."</pre>";
                // Execute the SQL on the database
                if ($schema->ExecuteSchema( $sql ) == 2) {
                    $text .= "Loaded tables with default data.\n";
                    printmsg("Loaded data to new DB: {$database_name}",'notice');
                } else {
                    $status++;
                    $text .= "Failed load default data.\n".$db->ErrorMsg()."\n";
                    printmsg("There was an error loading the data: ".$db->ErrorMsg(),'error');
                }
            }

            // Add the system user to the database
            // Run the query
          if ($status == 0) {
            // it is likely that this method here is mysql only?
            if($db->Execute("GRANT ALL ON {$database_name}.* TO '{$sys_login}'@'localhost' IDENTIFIED BY '{$sys_passwd}'")) {
                $db->Execute("GRANT ALL ON {$database_name}.* TO '{$sys_login}'@'%' IDENTIFIED BY '{$sys_passwd}'");
                $db->Execute("GRANT ALL ON {$database_name}.* TO '{$sys_login}'@'{$database_host}' IDENTIFIED BY '{$sys_passwd}'");
                $db->Execute("FLUSH PRIVILEGES");
                $text .= "Created system user '{$sys_login}'.\n";
                printmsg("Created new DB user: {$sys_login}",'notice');
            }
            else {
                $status++;
                $text .= "Failed to create system user '{$sys_login}'.\n".$db->ErrorMsg()."\n";
                printmsg("There was an error creating DB user: ".$db->ErrorMsg(),'error');
            }

            // add the default domain to the system
            // This is a manual add with hard coded values for timers.
            $xmldefdomain = <<<EOL
<?xml version="1.0"?>
<schema version="0.3">
<sql>
    <query>INSERT INTO domains (id,name,admin_email,default_ttl,refresh,retry,expiry,minimum,parent_id,serial,primary_master) VALUES (1,'{$default_domain}','hostmaster', 86400, 86400, 3600, 3600, 3600,0,0,0)</query>
    <query>UPDATE sys_config SET value='{$default_domain}' WHERE name like 'dns_defaultdomain'</query>
</sql>
</schema>
EOL;
            $schema = new adoSchema( $db );

            // Build the SQL array from the schema XML file
            $domainsql = $schema->ParseSchemaString($xmldefdomain);

            // Execute the SQL on the database
            if ($schema->ExecuteSchema( $domainsql ) == 2) {
                $text .= "Created default DNS domain '{$default_domain}'.\n";
            } else {
                $status++;
                $text .= "Failed to create default DNS domain '{$default_domain}'.\n".$db->ErrorMsg()."\n";
            }


            // Open the database config and write the contents to it.
            if (!$fh = @fopen($dbconffile, 'w')) {
                $status++;
                $text .= "Failed to open config file for writing: '{$dbconffile}'.\n";
            }
            else {
                fwrite($fh, "<?php\n\n\$ona_contexts=".var_export($ona_contexts,TRUE).";\n\n?>");
                fclose($fh);
                $text .= "Created database connection config file.\n";
            }

            // Update the version element in the sys_config table
            if(@$db->Execute("UPDATE sys_config SET value='{$new_ver}' WHERE name like 'version'")) {
               // $text .= "<img src=\"{$images}/silk/accept.png\" border=\"0\" /> Updated local version info.<br>";
            }
            else {
                $status++;
                $text .= "Failed to update version info in table 'sys_config'.\n".$db->ErrorMsg()."\n";
            }
          }

        } else {
            $status++;
            $text .= "Failed to select DB '{$database_name}'.\n".$db->ErrorMsg()."\n";
            printmsg("Failed to select DB: {$database_name}.  ".$db->ErrorMsg(),'error');
        }



//TODO: fix this
        if ($status > 0) {
            $text .= "There was a fatal error. Install may be incomplete. Fix the issue and try again\n";
        } else {
            // remove the run_install file in the install dir
            if (@file_exists($runinstall)) {
              if (!@unlink($runinstall)) {
                $text .= "Failed to delete the file '{$runinstall}'.\n";
                $text .= "Please remove '{$runinstall}' manually.\n";
              }
            }
            $text .= "\nYou can log in as 'admin' with a password of 'admin'\nEnjoy!";
        }

        // Close the database connection
        @$db->Close();
    }

// Print out the text to the end user
echo $text;

}












//#######################################################################
//# Function: Prompt user and get user input, returns value input by user.
//#           Or if return pressed returns a default if used e.g usage
//# $name = promptUser("Enter your name");
//# $serverName = promptUser("Enter your server name", "localhost");
//# Note: Returned value requires validation 
// from http://wiki.uniformserver.com/index.php/PHP_CLI:_User_Input
//#.......................................................................
function promptUser($promptStr,$defaultVal=false){;

  if($defaultVal) {                             // If a default set
     echo $promptStr. "[". $defaultVal. "] : "; // print prompt and default
  }
  else {                                        // No default set
     echo $promptStr. ": ";                     // print prompt only
  } 
  $name = chop(fgets(STDIN));                   // Read input. Remove CR
  if(empty($name)) {                            // No value. Enter was pressed
     return $defaultVal;                        // return default
  }
  else {                                        // Value entered
     return $name;                              // return value
  }
}
//========================================= End promptUser ============



?>
