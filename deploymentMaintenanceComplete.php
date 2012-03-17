<?php

ob_start();
$_GET['customQuery']=1;//hack to not load up SVN shell execs
include("index.php");
ob_end_clean();
$dataObj->addDeploymentCompleteRecord();
echo "Also Added Deployment Record To Database...\n";
?>
