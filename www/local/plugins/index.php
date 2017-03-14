<?php

echo <<<EOL
<html>
  <body id="pluginlist" style='border: 1px solid #d9d9d9; border-radius: 6px; padding: 4px; margin: 0px; font: icon;'>
<table style='width: 100%'>
  <tr style='background-color: #f2f2f2;'><td>Plugin Name</td><td>Version</td><td>Description</td></tr>
EOL;

// Gather directory names in the plugin directory
foreach (new DirectoryIterator('.') as $fileInfo) {

    // Set some default values
    $plugin_version = '??';
    $plugin_description = 'No Description found. No plugin_info.';
    $disabled = '';

    // ignore . files
    if($fileInfo->isDot()) continue;
    // process directories
    if($fileInfo->isDir()) {
      $pluginname = $fileInfo->getFilename();
      if (file_exists($pluginname.'/disabled')) $disabled = ' (Disabled)';
      @include($pluginname.'/plugin_info.php');
      echo "<tr>";
      if (is_file($pluginname.'/docs.html')) {
        echo  "<td><a target='_top' href='{$pluginname}/docs.html'>{$pluginname}</a>{$disabled}</td>";
      } else {
        echo  "<td>{$pluginname}</a>{$disabled}</td>";
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
