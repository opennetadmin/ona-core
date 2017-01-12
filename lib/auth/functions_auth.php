<?php

$auth = '';


/**
 * Loads the specified Authentication class
 *
 * Authentication classes are located in lib/auth
 *
 * if no Authentication type is passed, it will use the system
 * configured 'authtype'
 *
 * @author  Matt Pascoe <matt@opennetadmin.com>
 * @return  struct  Auth class structure
 */
function load_auth_class($authtype='') {
    global $base, $conf;
    // define a variable having the path to our auth classes
    define('ONA_AUTH', $base.'/lib/auth');

    // use the system configured authtype if one was not passed in
    if (!$authtype) $authtype = $conf['authtype'];

    // If we STILL dont have an auth type set, use the local one as default
    if (!$authtype) $authtype = 'local';

    // clear out the auth variable
    unset($auth);

    // load the the backend auth functions and instantiate the auth object
    if (@file_exists(ONA_AUTH.'/'.$authtype.'.class.php')) {
        require_once(ONA_AUTH.'/local.class.php');
        require_once(ONA_AUTH.'/'.$authtype.'.class.php');

        $auth_class = "auth_".$authtype;
        if (class_exists($auth_class)) {
            $auth = new $auth_class();
            if ($auth->success == false) {
                // degrade to unauthenticated user
                unset($auth);
                unset($_SESSION['ona']['auth']);
                printmsg("Failure loading auth module: {$conf['authtype']}.", 'error');
            }
        } else {
            printmsg("Unable to find auth class: {$auth_class}.", 'error');
        }
    } else {
        printmsg("Auth module {$authtype} not in path: ".ONA_AUTH, 'error');
    }
    return($auth);
}



/*
* Authenticate the user and password combo.
* will use the auth method that is configured (ie. ldap, local)
* will get back a true/false as to success of user/pass auth
*/
function get_authentication($login_name, $login_password) {
    global $self, $conf;

    // Validate the userid was passed and is "clean"
    if (!preg_match('/^[A-Za-z0-9.\-_]+$/', $login_name)) {
        $self['error'] = "Login failure for {$login_name}: Bad username format";
        printmsg($self['error'], 'error');
        return(array(1, $self['error']));
    }

    $auth = load_auth_class();

    // Check user/pass authentication
    $authresult = $auth->checkPass($login_name,$login_password);

    // If we do not find a valid user, fall back to local auth
    if ($auth->founduser === false) {
        // Fall back to local database to see if we have something there
        if ($conf['authtype'] != 'local') {
            printmsg("Unable to find user via auth_{$conf['authtype']}, falling back to local auth_local.",'info');
            $auth = load_auth_class('local');
            $authresult = $auth->checkPass($login_name,$login_password);
            if ($auth->founduser === false) {
                $self['error'] = "Login failure for {$login_name}: Unknown user";
                printmsg($self['error'], 'error');
                return(array(false, $self['error']));
            }
            // override the system configured authtype for now
            $conf['authtype']='local';
        }
    }

    // If we do not get a positive authentication of user/pass then fail
    if ($authresult === false) {
        $self['error'] = "Login failure for {$login_name} using authtype {$conf['authtype']}: Password incorrect";
        printmsg($self['error'], 'error');
        return(array(false, $self['error']));
    }

    // If the password is good.. return success.
    $self['error'] = "Authentication Successful for {$login_name} using authtype: {$conf['authtype']}";
    printmsg($self['error'], 'notice');
    return(array(true, $self['error']));

}










/**
 * Authorizes a user for specific permissions
 *
 * Populates session variable with permissions. no
 * data is returned to the calling function
 *
 * @author  Matt Pascoe <matt@opennetadmin.com>
 * @return  TRUE
 */
function get_perms($login_name='') {
    global $onadb;

    $permissions = array();

    printmsg("Authorization Starting for {$login_name}", 'debug');

    $auth = load_auth_class();

    // get user information and groups
    $userinfo = $auth->getUserData($login_name);
    if ($userinfo === false) printmsg("Failed to get user information for user: {$login_name}", 'error');

    // Load the users permissions based on their user_id.
    // this is specific permissions for user, outside of group permissions
    list($status, $rows, $records) = db_get_records($onadb, 'permission_assignments', array('user_id' => $userinfo['id']));
    foreach ($records as $record) {
        list($status, $rows, $perm) = db_get_record($onadb, 'permissions', array('id' => $record['perm_id']));
        $permissions[$perm['name']] = $perm['id'];
    }


    // Load the users permissions based on their group ids
    foreach ((array)$userinfo['grps'] as $group => $grpid) {
        // Look up the group id stored in local tables using the name
        list($status, $rows, $grp) = db_get_record($onadb, 'groups', array('name' => $group));
        // get permission assignments per group id
        list($status, $rows, $records) = db_get_records($onadb, 'permission_assignments', array('group_id' => $grp['id']));
        foreach ($records as $record) {
            list($status, $rows, $perm) = db_get_record($onadb, 'permissions', array('id' => $record['perm_id']));
            $permissions[$perm['name']] = $perm['id'];
        }
    }

    // Save stuff in the session
    // Basically this is just a superglobal to use.. not really using sessions as we use JWT tokens
    $_SESSION['ona']['auth'] = '';
    $_SESSION['ona']['auth']['user']   = $userinfo;
    $_SESSION['ona']['auth']['perms']  = $permissions;

    // Log that the user logged in
    printmsg("Loaded permissions for " . $login_name, 'debug');
    #return (array(0,$_SESSION['ona']['auth']));
    return true;

}
