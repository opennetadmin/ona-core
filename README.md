ONA-CORE
========

This is the new core OpenNetAdmin system.  It is intended to provide the base core logic and expose a rest API.

You can install and use this without a GUI interface if desired, or optionally install the new GUI interface.

INSTALL
=======

The following is the initial install process based on ubuntu 16.04

* apt-get install apache2 mysql-server php-gmp php-mysql libapache2-mod-php php-mbstring php-xml composer unzip
* vi /etc/php/7.0/apache2/php.ini # uncomment the line error_log = syslog
* cd /opt
* git clone https://github.com/opennetadmin/ona-core.git -b dev
* cd ona-core
* composer -vv install
* php $PWD/install/install.php

* set up apache
```
DocumentRoot /opt/ona-core/www
...

<Location "/">
    AllowOverride all
    Require all granted
</Location>

```

* I set up a symlink to the old ona for testing purposes as well: `ln -s /opt/ona-core/www/gui /opt/ona/www`
