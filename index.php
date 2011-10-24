<?php
ini_set('session.gc_maxlifetime', 30*60);
session_start();
header("Cache-control: private");

require("deploymentMaintenance.php");
require("deploymentMaintenanceConfiguration.php");

// -- instantiate the form and pass your configured dataMaintenance object
if ($dataObj->ConnectMaintenance())
{
   $dataMaintenanceForm = new DeploymentMaintenanceWebForm($dataObj);
}
$parameters = array('jump_to','username','create_build');

$showMaintenanceForm = (!isset($showMaintenanceForm)) ? true : $showMaintenanceForm; ;
foreach ($parameters as $parm)
{
   $showMaintenanceForm &= (!array_key_exists($parm,$_REQUEST));
}

if ($_SERVER['REMOTE_ADDR'] && $showMaintenanceForm && $dataObj->ConnectMaintenance())
{
   if (!array_key_exists('username', $_SESSION) && !array_key_exists('url', $_REQUEST))
   {
      header("Location: {$dataObj->base_url}?url=".urlencode($_SERVER['REQUEST_URI']));
   }
   else
   {
      echo $dataMaintenanceForm->html;
   }
}
elseif (array_key_exists('username', $_REQUEST) && array_key_exists('password', $_REQUEST))
{
   $dataObj->DeploymentMaintenanceLogIn($_REQUEST['username'],$_REQUEST['password']);
}
elseif (array_key_exists('jump_to', $_REQUEST))
{
   $query = $dataObj->menu_queries[$_REQUEST['jump_to']];
   if (!empty($query))
   {
      header("Location: ".str_replace(array('<!--NAME-->','<!--SQL-->'),array('',$query),$dataObj->query_link_url));
   }
}
elseif (array_key_exists('create_build', $_REQUEST) || !$dataObj->ConnectMaintenance())
{
   require("deploymentMaintenanceInstall.php");
}
?>
