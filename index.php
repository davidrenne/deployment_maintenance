<?php

/*
   setup:
      create 3 directories
      inbound
      archive
      database_backups
      svndeploy
      chmod 777 inbound and svndeploy folder

      or run this
      mkdir inbound && mkdir archive && mkdir database_backups && mkdir svndeploy && chmod 777 inbound && chmod 777 svndeploy
 */

session_start();
ini_set('session.gc_maxlifetime', 30*60);
header ("Cache-control: private");
require("deploymentMaintenance.php");
require("deploymentMaintenanceConfiguration.php");

// -- instantiate the form and pass your configured dataMaintenance object
$dataMaintenanceForm = new DeploymentMaintenanceWebForm($dataObj);

if (!array_key_exists('jump_to',$_REQUEST) && !array_key_exists('create_build',$_REQUEST))
{
   echo $dataMaintenanceForm->html;
}
elseif ($_REQUEST['jump_to'])
{
   $query = $dataObj->menu_queries[$_REQUEST['jump_to']];
   if (!empty($query))
   {
      header("Location: ".str_replace(array('<!--NAME-->','<!--SQL-->'),array('',$query),$dataObj->query_link_url));
   }
}
elseif ($_REQUEST['create_build'])
{
   require("deploymentMaintenanceInstall.php");
}
?>
