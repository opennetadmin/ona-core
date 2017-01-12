var hooks = require('hooks');
var stash = {};

function sleep(milliseconds) {
  var start = new Date().getTime();
  for (var i = 0; i < 1e7; i++) {
    if ((new Date().getTime() - start) > milliseconds){
      break;
    }
  }
}

hooks.log('Starting ONA hooks');

// hook to retrieve session on a login
hooks.after('Login > Auth > Login', function (transaction) {
  stash['token'] = JSON.parse(transaction.real.body)['token'];
  hooks.log('found token: ' + stash['token']);

  sleep(10000);

});

// hook to set the session cookie in all following requests
hooks.beforeEach(function (transaction) {
  if(stash['token'] != undefined){
//  stash['token'] = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJsb2NhbGhvc3QtMTI3LjAuMC4xIiwiYXVkIjoiMTI3LjAuMC4xIiwiaWF0IjoxNDg0MjQzNTUzLCJuYmYiOjE0ODQyNDM1NTgsImV4cCI6MTQ4NDI0NzE1MywidXNlcm5hbWUiOiJhZG1pbiJ9.GXRShYc6GFUyHSW4CcIBEJJA8UnX1NTDajDxJuyhEJg';
  transaction.request['headers']['Authorization'] = stash['token'];
 // hooks.log('---Using token: ' + stash['token']);
  };
});
