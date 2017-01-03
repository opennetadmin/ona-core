<?php


///////////////////////////////////////////////////////////////////////
//  Function: tag_add (string $options='')
//
//  $options = key=value pairs of options for this function.
//             multiple sets of key=value pairs should be separated
//             by an "&" symbol.
//
//  Input Options:
//    name=STRING
//    type=STRING
//    reference=NUMBER
//
//  Output:
//    Adds an tag into the database called 'name' 
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = tag_add('name=test&type=blah&reference=1');
///////////////////////////////////////////////////////////////////////
function tag_add($options="") {

    // The important globals
    global $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    printmsg('Called with options: ('.implode (";",$options).')', 'info');

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Possible types
    $allowed_types = array('subnet', 'host');

    $typetext=implode(', ',$allowed_types);
    // Return the usage summary if we need to
    if (!($options['type'] and $options['name'] and $options['reference']) ) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOM

tag_add-v{$version}
Adds a tag into the database assigned to the specified type of data.

  Synopsis: tag_add [KEY=VALUE] ...

  Required:
    name=STRING            Name of new tag.
    type=STRING            Type of thing to tag, Possible types listed below.
    reference=ID|STRING    Reference to apply the tag to. ID or name used to find
                           a record to attatch to.

  Possible types of tags:
    {$typetext}
\n
EOM

        ));
    }

    // Check if provided type is in the allowed types
    $options['type'] = strtolower(trim($options['type']));
    if (!in_array($options['type'], $allowed_types)) {
        $self['error'] = "Invalid tag type: {$options['type']}";
        printmsg($self['error'], 'notice');
        return(array(1, $self['error']));
    }

    // The formatting rule on tag input
    $options['name'] = preg_replace('/\s+/', '-', trim($options['name']));
    if (preg_match('/[@$%^*!\|,`~<>{}]+/', $options['name'])) {
        $self['error'] = "Invalid character in tag name";
        printmsg($self['error'], 'notice');
        return(array(1, $self['error']));
    }
    $options['reference'] = (trim($options['reference']));

    // Use the find functions based on the type 
    // this requires allowed types to have an 'ona_find_' related function
    eval("list(\$status, \$rows, \$reference) = ona_find_".$options['type']."('".$options['reference']."');");

    if ($status or !$rows) {
        $self['error'] = "Unable to find a {$options['type']} matching {$options['reference']}";
        printmsg($self['error'], 'notice');
        return(array(1, $self['error']));
    }

    // Validate that there isn't already an tag of this type associated to the reference
    list($status, $rows, $tags) = db_get_records($onadb, 'tags',array('type' => $options['type'],'reference' => $reference['id']));

    foreach ($tags as $t) {
      if (in_array($options['name'], $t)) {
        $self['error'] = "The tag {$options['name']} is already associated with this {$options['type']}!";
        printmsg($self['error'], 'notice');
        return(array(3, $self['error']));
      }
    }


    // Check permissions
#    if (! (auth('subnet_add') or auth('host_add')) ) {
#        $self['error'] = "Permission denied!";
#        printmsg($self['error'], 0);
#        return(array(10, $self['error']));
#    }

    // Get the next ID for the new tag
    $id = ona_get_next_id('tags');
    if (!$id) {
        $self['error'] = "The ona_get_next_id() call failed!";
        printmsg($self['error'], 'notice');
        return(array(5, $self['error']));
    }
    printmsg("DEBUG => ID for new tag: $id", 3);

    // Add the tag
    list($status, $rows) =
        db_insert_record(
            $onadb,
            'tags',
            array(
                'id'             => $id,
                'name'           => $options['name'],
                'type'           => $options['type'],
                'reference'      => $reference['id']
            )
        );
    if ($status or !$rows) {
        $self['error'] = "SQL Query failed: " . $self['error'];
        printmsg($self['error'], 'error');
        return(array(6, $self['error']));
    }

    // Return the success notice
    $self['error'] = "{$options['type']} TAG ADDED: {$options['name']} to {$reference['name']}({$reference['id']}).";
    printmsg($self['error'],'info');
    return(array(0, $self['error']));
}












///////////////////////////////////////////////////////////////////////
//  Function: tag_del (string $options='')
//
//  $options = key=value pairs of options for this function.
//             multiple sets of key=value pairs should be separated
//             by an "&" symbol.
//
//  Input Options:
//    tag=ID
//
//  Output:
//    Deletes an tag from the database.
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = tag_del('tag=19328');
///////////////////////////////////////////////////////////////////////
function tag_del($options="") {

    // The important globals
    global $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '2.00';

    printmsg('Called with options: ('.implode (";",$options).')', 'info');

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if ((!$options['tag']) and !($options['type'] and $options['name'] and $options['reference']) ) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOM

tag_del-v{$version}
Deletes an tag from the database

  Synopsis: tag_del [KEY=VALUE] ...

  Required:
    tag=ID             ID of the tag to delete

EOM

        ));
    }


    // If the tag provided is numeric, check to see if it's an tag
    if (is_numeric($options['tag'])) {
        // See if it's a tag_id
        list($status, $rows, $tag) = db_get_record($onadb,'tags', array('id' => $options['tag']));
    }

    if (isset($options['name'])) {

        list($status, $rows, $tag) = db_get_record($onadb,'tags', array('name' => $options['name'], 'type' => $options['type'], 'reference' => $options['reference']));

    }

    if (!$tag['id']) {
        $self['error'] = "Unable to find tag {$options['name']}";
        printmsg($self['error'], 'notice');
        return(array(2, $self['error']));
    }



#        // Check permissions
#        if (! (auth('host_del') or auth('subnet_del')) ) {
#            $self['error'] = "Permission denied!";
#            printmsg($self['error'], 'notice');
#            return(array(10, $self['error']));
#        }

    list($status, $rows) = db_delete_records($onadb, 'tags', array('id' => $tag['id']));
    if ($status or !$rows) {
        $self['error'] = "SQL Query failed: " . $self['error'];
        printmsg($self['error'], 'error');
        return(array(4, $self['error']));
   }

    // Return the success notice
    $self['error'] = "TAG DELETED: {$tag['name']} from {$tag['type']}[{$tag['reference']}]";
    printmsg($self['error'], 'info');
    return(array(0, $self['error']));


    // Otherwise display the record that would have been deleted
    $text = <<<EOL
Record(s) NOT DELETED (see "commit" option)
Displaying record(s) that would have been deleted:

    NAME:      {$tag['name']}
    TYPE:      {$tag['type']}
    REFERENCE: {$tag['reference']}


EOL;

    return(array(6, $text));

}
