<?php

echo <<<EOL
<html>
  <body>
TODO: make a better stylesheet!<br>
Currently installed plugins: 
<br>
<br>
<table style='border: 1px solid'>
  <tr><td>Plugin Name</td><td>Version</td><td>Description</td></tr>
EOL;

// Gather directory names in the plugin directory
foreach (new DirectoryIterator('.') as $fileInfo) {

    // Set some default values
    $plugin_version = '??';
    $plugin_description = 'No Description found. No plugin_info.';

    // ignore . files
    if($fileInfo->isDot()) continue;
    // process directories
    if($fileInfo->isDir()) {
      $pluginname = $fileInfo->getFilename();
      @include($pluginname.'/plugin_info.php');
      echo "<tr>";
      if (is_file($pluginname.'/docs.html')) {
        echo  "<td><a href='{$pluginname}/docs.html'>{$pluginname}</a></td>";
      } else {
        echo  "<td>{$pluginname}</a></td>";
      }
      echo  "<td>{$plugin_version}</td>";
      echo  "<td>{$plugin_description}</td>";
      echo "</tr>";
    }
}

echo <<<EOL
    </table>
  </body>
</html>
EOL;
