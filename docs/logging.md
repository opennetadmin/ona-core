Logging
=======

Logging is handled by the php monolog module. It should be included automatically by the composer configuration.

The following settings are possible and are shown with default values

* Enable or disable logging to a local file, Must set 'log_file' path
    'log_to_file'            => '0',
* Enable or disable logging to syslog.
    'log_to_syslog'          => '1',
* Enable or disable logging to a database (still needs implemented)
    #'log_to_db'              => '0',
* Path to local log file if 'log_to_file' is enabled
    'log_file'               => './logs/ona.log',
* Sets the lowest level of logging per rfc 5424, set to DEBUG if you want to see that level
    'log_level'              => 'INFO',
* Syslog facility to log messages to.
    'log_syslog_facility'    => 'local6',


The 'printmsg' function is used for log data. By default the following extra metadata is logged with the log message in a json format:

* user: The username that produced the log entry
* func: Calling function name that logged the message
* context: The ONA database context. Will be 'DEFAULT' unless you have switched contexts
* client_ip: The ip address reported as the remote IP.
