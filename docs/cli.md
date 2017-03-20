ONA CLI Usage
===============

In the past ONA provided a CLI tool called `dcm.pl`.  This had many shortcomings when it came to security and was really just a wrapper around HTTP requests.  Its goal was to gather the human input and pass it to ONA, then take the output and hand it back to a human.  It also helped provide a bit of documentation of what modules were available and their options.

Now that there is a true rest interface a few things will change as it relates to a CLI interface for humans.

To start with, I intend to use a tool called [http://github.com/micha/resty](resty).  This tool provides a simple wrapper to generating `curl` commands to the rest endpoint.  I then intend to use the tool [http://github.com/stedolan/jq](jq) to help with the json parsing and pretty printing.

Since the rest interface itself does not provide documentation inline like the old `dcm.pl` tool did, you will have to rely on the API docs located at the rest.php endpoint location.  This is typically http://localhost/rest.php.  This documentation shows the endpoint and which options you can pass in as parameters, and which would be input such as form-data.

Keep in mind that the rest endpoint requires authentication using JWT tokens.  This means that you must use the `/login` endpoint to obtain a token, store that token securely and then use it as part of your `resty` calls.  Below I show an example usage to log in, set `resty` to the token and then query subnets, add a subnet, delete a subnet.

For now, the ONA rest API only requres the use of POST, GET, DELETE operations.

Please read the `resty` documentation for alternate ways to configure `resty` as well as pass in options.  The examples below are not the only ways to interface with the API.  Also the `jq` tool provides many options to parsing and utilizing the JSON data.

Install
-------
* apt-get install jq
* curl -L http://github.com/micha/resty/raw/master/resty > resty

Usage Examples
--------------

* Configure secure storage for your token
    `touch ~/.onatoken;chmod 600 ~/.onatoken`
* Tell this shell instance where the base API endpoint is that `resty` should use
    `. resty -W http://localhost/rest.php/v1`

* Log in to get your token and store it in a secure place
    `POST /login -q "user=admin&pass=admin"|jq -r .token > ~/.onatoken`
* Tell `resty` to send your token each time you make a call
    `resty http://localhost/rest.php/v1 -H "Authorization: $(cat ~/.onatoken)"`

* A short form of login without using a local file is this. It likely assumes you have already have run dotted in resty and set a base url.. this would be for re-login?
    `resty http://localhost/rest.php/v1 -H "Authorization: $(POST /login -q "user=admin&pass=admin"|jq -r .token)"`


* Get a list of all the subnets and pretty print it
    `GET /subnets|jq`
* Get a list of all subnets but only return the id and name for each one.
    `GET /subnets -q "fields=id,name" |jq .subnets`
* Add a new subnet using form-data in URL format
    `POST /subnets -d 'name=BLAHTEST&type=13&ip=192.168.10.0&netmask=255.255.255.0'|jq`
* or use discreate `-d` data fields
    `POST /subnets -d name=BLAHTEST -d type=13 -d ip=192.168.10.0 -d netmask=255.255.255.0|jq`
* Delete the subnet we just added
    `DELETE /subnets/blahtest|jq`
* Get a list of hostnames for all hosts that have a manufacturer of Cisco
    `GET /hosts -q manufacturer=Cisco|jq -r '.hosts | .[].fqdn'`
* Get list of locations matching multiple query fields
    `GET /locations -q 'zip_code=8%&city=Aurora'|jq`


Templating
----------

At times you may have the desire to generate reports or other configuration from ONA. This can be done faily simply by using a ruby ERB template. Other templating solutions could be used as well but here is a quick example to get you started.

Lets say I've just added my router to ONA and it has several vlan interfaces. I'd like to auto generate the interface configurations for this router. You can quickly do that using the following ERB template:

``` erb
<%
# Usage
#    GET /interfaces -q host=host.example.com | erb -T - debug=true <thisfile.erb>
#
# Use the erb '-T -' option to tell erb to handle EOL properly for closing references
#
# Pass 'debug=true' if you want to see the content of your JSON processed input
#
# By default it will process STDIN.. or if you choose you can pass additional data in as variables
#    GET /interfaces -q host=host.example.com | erb -T - debug=true subnets=(GET /subnets) <thisfile.erb>
#
require 'json'
input = JSON.load(STDIN)
input.each do |key, value|
  instance_variable_set :"@#{key}", value
end

if defined? debug and debug == "true"
  puts JSON.pretty_generate(input)
  exit
end
-%>
<% @interfaces.each do |key, value| -%>
interface <%= key['name'] %>
  description <%= key['description'] %>
  ip address <%= key['ip_addr_text'] -%> 255.255.255.0
!
<% end -%>
```

The ouput from this will look something like:

```
interface Vlan99
  description LAN-EXAMPLE1
  ip address 10.1.99.1 255.255.255.0
!
interface Vlan100
  description LAN-EXAMPLE2
  ip address 10.1.100.1 255.255.255.0
!
interface Vlan101
  description LAN-EXAMPLE3
  ip address 10.1.101.1 255.255.255.0
!
```

As you can see, this is a simple way to pass the JSON output into an erb template and produce repeating configuration using a loop. This could be done for generating more user friendly reports or any other type of confgiuration.

By default this example is designed to take input on `STDIN`. This should work fine for many situations. Do note however that the subnet mask in the example is staticaly generated. This will not work if our subnets have differing subnet masks. This would require the subnet information from ONA as well since the mask is stored with the subnet, not the interface. You could simply try and build one input that is pushed into `STDIN`. One option here is to continue passing the interface list into `STDIN` but also provide the subnet list as an erb variable. The following extention to our example will do that:

``` erb
<%
#
# Usage
#   GET /interfaces -q host=router.example.com|erb -T - subnets="$(GET /subnets -q host=router.example.com)" debug=false router_interfaces.erb
#
# Use the erb '-T -' option to tell erb to handle EOL properly for closing references
#
# Pass 'debug=true' if you want to see the content of your JSON blob input
#
# By default it will process STDIN.. or if you choose you can pass additional data in as variables
#    GET /interfaces -q host=host.example.com | erb -T - debug=true subnets=(GET /subnets) <thisfile.erb>
#
require 'json'
# Process STDIN
stdinput = JSON.load(STDIN)
stdinput.each do |key, value|
  instance_variable_set :"@#{key}", value
end

if defined? debug and debug == "true"
  puts JSON.pretty_generate(stdinput)
end

# Process Subnets CLI variable
if defined? subnets
  subnets = JSON.load(subnets)
  subnets.each do |key, value|
    instance_variable_set :"@#{key}", value
  end

  if defined? debug and debug == "true"
    puts JSON.pretty_generate(subnets)
  end
end

# Begin loop of interfaces from STDIN
@interfaces.each do |interface|
   # Loop subnets from subnets CLI variable
   @subnets.each do |subnet|
     if subnet['id'] == interface['subnet_id']
       interface['mask'] = subnet['ip_mask_text']
       break
     end
   end
-%>
interface <%= interface['name'] %>
  description <%= interface['description'] %>
  ip address <%= interface['ip_addr_text'] -%> <%= interface['mask'] %>
!
<% end -%>
```

This just touches the surface of what could be done here. Things such as Nagios configuration, Puppet Node generation etc could be done in this way.
