<?php
// -- run cron every 1 minute and let this script check each second and then exit
set_time_limit(0);
ob_start();
require(dirname(__FILE__)."/index.php");
$autoDeployShell = dirname(__FILE__).'/svndeploy/auto_deploy.sh';
$autoDeployShell2 = dirname(__FILE__).'/svndeploy/auto_deoploy_is_running.sh';
$hourOfDay    = date('H');
$topOfTheHour = (date('i') == '00');

$executeShellDeploy = true;

// -- debug mode
if ($argv[1] == 1)
{
   $executeShellDeploy = false;
}

if ($executeShellDeploy)
{
   for($i=1;$i<60;$i++)
   {
      if (file_exists($autoDeployShell) && !file_exists($autoDeployShell2))
      {
         file_put_contents($autoDeployShell2,'Running...');
         $shell = file_get_contents($autoDeployShell);
         exec($shell);
         unlink($autoDeployShell);
         unlink($autoDeployShell2);
      }
      sleep(1);
   }
}

ob_end_clean();
// -- if top of the hour loop through
if ($topOfTheHour || !$executeShellDeploy)
{

   // -- clock out developer for end of the day, he is heading home and last project has a stop timer on it if it exists
   if ($hourOfDay == $dataObj->developer_end_of_day)
   {
      $rows = $dataObj->getRecords(
         "
         SELECT
           MAX(id) as max_id,
           rev_modified_time,
           project_id,
           developer
         FROM
           ".$dataObj->maintenance_database.".".$dataObj->table_names['checkins']."
         WHERE
            project_id != 0
         GROUP BY
           developer
         "
      );
      foreach ($rows as $row)
      {
         //mail("dave@truemarketingpartners.com","End of day logic for {$row['developer']}","End of day logic for {$row['developer']}");
         $dataObj->ClockProjectTime($row['developer'],date('Y-m-d H:i:s'));
      }
   }

   // -- insert all checkins and email developers per hour what is going on.
   $processedTrunk = false;
   foreach ($dataObj->svn_preferred_branches as $branchRepo)
   {
      list($svnOptions,$svnOptionsBranch,$allCheckins) = $dataObj->GetSVNCheckins(1,$branchRepo,'hour');
      $allCheckins = array_reverse($allCheckins);
      foreach ($allCheckins as $branchName=>$branch)
      {
         if (!$processedTrunk && $branchName == 'trunk')
         {
            $filesAffected[$branchRepo]['html'] .= "<h1>trunk Checkins</h1>";
            $processedTrunk = true;
         }
         elseif($processedTrunk && $branchName == 'trunk')
         {
            continue;
         }

         elseif(!array_key_exists('title',$filesAffected[$branchRepo]))
         {
            $filesAffected[$branchRepo]['html'] .= "<h1>$branchRepo Checkins</h1>";
            $filesAffected[$branchRepo]['title'] = true;
         }

         if (!isset($filesAffected[$branchName]['hasNewCheckin']))
         {
            $filesAffected[$branchRepo]['hasNewCheckin'] = false;
         }

         foreach ($branch as $rev=>$checkinInfo)
         {
            // -- HACK FOR NOW as this thing is processing really old revs.
            if ($rev < 7500)
            {
               continue;
            }
            $checkinInfo['time'] = date('Y-m-d H:i:s',$checkinInfo['time']);
            $projectId = $dataObj->GetProjectIdFromValue($checkinInfo['message']);
            if ($dataObj->AddCheckin($projectId,$rev,$branchName,$checkinInfo['time'],$checkinInfo['user'],$checkinInfo['message']))
            {
               // -- Add block of code meta data
               $filesRaw = $dataObj->getSVNFiles($rev,$branchName,$checkinInfo['message'],$checkinInfo['time'],true);
               $filesAffected[$branchRepo]['hasNewCheckin'] = true;
               $q2 = "SELECT DISTINCT file_name, blocks.project_id FROM ".$dataObj->maintenance_database.".".$dataObj->table_names['blocks_affected']." blocks, ".$dataObj->maintenance_database.".".$dataObj->table_names['checkins']." checkins WHERE blocks.revision = checkins.revision AND checkins.revision = '$rev'";
               $deploymentRows = $dataObj->getRecords($q2);

               $projectHTML = "";
               if ($projectId > 0 && !empty($projectId))
               {
                  $projectHTML = "
                  <li>
                     Project: <a href='{$dataObj->ticketing_system_url}{$projectId}'>#{$projectId} ({$dataObj->ticketing_system_url}{$projectId})</a>
                  </li>
                  ";
               }
               if (!empty($checkinInfo['message']))
               {
                  $messageHTML = " - \"{$checkinInfo['message']}\"";
               }
               else
               {
                  $messageHTML = " <span style='color:grey;'>(No Message)</span>";
               }
               $filesAffected[$branchRepo]['html'] .= "
               <h3>Rev #{$rev}{$messageHTML}</h3>
               <ul>
                  <li>
                     Developer: {$checkinInfo['user']}
                  </li>
                  <li>
                     Time Stamp: {$checkinInfo['time']}
                  </li>
                  $projectHTML";

               $filesAffected[$branchRepo]['html'] .="
               <li>
               Files Affected:
                  <ul>";

               if (sizeof($deploymentRows) == 0)
               {
                  foreach (explode("\n",$filesRaw) as $fName)
                  {
                     $deploymentRows[]['file_name'] = $fName;
                  }
               }

               foreach($deploymentRows as $row)
               {
                  $otherIds          = array();
                  $fileBlocksCode    = array();
                  $fileBlocksId      = array();
                  $rows2 = $dataObj->GetImpactedBlockRows("revision = '$rev' AND project_id ='{$row['project_id']}' AND file_name='{$row['file_name']}' AND ignore_flag = 0");
                  if (!empty($rows2))
                  {
                     foreach ($rows2 as $row2)
                     {
                        $fileBlocksId[$row2['id']] = $row2['line_info'];
                        $fileBlocksCode[$row2['id']] = $row2['block_of_code'];
                        $otherIds[$row2['id']] = $row2['id'];
                     }
                  }
                  $fileHTMLLink = (!empty($otherIds)) ? str_replace(array('<!--NAME-->','<!--SQL-->'),array($row['file_name'],'SELECT block_of_code FROM '.$dataObj->maintenance_database.'.'.$dataObj->table_names['blocks_affected'].' WHERE id IN ('.implode(',',$otherIds).')&use_pre=1'),$dataObj->query_link_template)  : $row['file_name'];
                  $filesAffected[$branchRepo]['html'] .= "<li>{$fileHTMLLink}";
                  $filesAffected[$branchRepo]['html'] .= $dataObj->GetAffectedBlocksHTML('timestamp',$row['file_name'], $row['project_id']);
                  if (!empty($fileBlocksCode))
                  {
                     $filesAffected[$branchRepo]['html'] .= "<ul>";
                     foreach ($fileBlocksCode as $id=>$code)
                     {
                        $filesAffected[$branchRepo]['html'] .= "<li><h2>{$fileBlocksId[$id]}</h2><pre>".htmlentities($code)."</pre></li>";
                     }
                     $filesAffected[$branchRepo]['html'] .= "</ul>";
                  }
                  $filesAffected[$branchRepo]['html'] .= "</li>";
               }
               $filesAffected[$branchRepo]['html'] .= "</li></ul>";
               $filesAffected[$branchRepo]['html'] .= "</ul>";
            }
            else
            {
               echo "Skipping Rev #$rev\n";
            }
         }
      }
   }

   $emailMessage = null;
   foreach ($filesAffected as $branchName=>$rowInfo)
   {
      if ($rowInfo['hasNewCheckin'])
      {
         $emailMessage .= $rowInfo['html'];
         $rowInfo['html'] = null;
      }
   }

   if ($emailMessage)
   {
      $dataObj->DeploymentNotify(
      array("dave@truemarketingpartners.com","matt@truemarketingpartners.com","trevor@truemarketingpartners.com"),
      "DeploymentMaintenance Hourly SVN Checkins",
      $emailMessage,
      "HOURLY_CHECKINS"
      );
   }

   $deploymentMaintenanceRows = $dataObj->GetCountCheckMonitors();
   if (sizeof($deploymentMaintenanceRows) > 0)
   {
      foreach ($deploymentMaintenanceRows as $theQuery=>$monitorRow)
      {
         $_REQUEST['is_cron']     = true;
         $_REQUEST['deploy']      = $monitorRow['deployment_type'];

         $dataObj->sendToMaster = false;

         if (in_array($monitorRow['id'],array(34808, 35962, 36036)))
         {
            if ($hourOfDay == 4 || $hourOfDay == 5)
            {
               continue;
            }
            // -- slave data not 100% matching master.
            $dataObj->sendToMaster = true;
         }
         list($countAll,$countsByRegion) = $dataObj->queryDeploymentRegions($theQuery,1,1,1);
         $countsByRegion2 = array();
         switch ($monitorRow['deployment_cron_count_check'])
         {
            case '1':
               if ( $countAll  > 0)
               {
                  $dataObj->fileLogger($theQuery. ' => count all monitor = '.$countAll);
                  $dataObj->fileLogger($theQuery. ' => counts by region = '.print_r($countsByRegion,true));
                  foreach ($countsByRegion as $regionId=>$cnt)
                  {
                     if ( $cnt  > 0)
                     {
                        $countsByRegion2[$regionId] = $regionId." ($cnt)";
                     }
                  }
                  $newSQL = $dataObj->DisableCountCheckMonitor($monitorRow['id']);
                  $dataObj->DeploymentNotify(
                     $dataObj->web_notify['developers'],
                     "{$monitorRow['deployment_username']}'s DeploymentMaintenance SQL Monitor: Found ($countAll) Rows",
                     "
                     DeploymentMaintenance found ($countAll) Rows in <strong>".implode(",",$countsByRegion2)."</strong><br /><br /> When Running the following Query #{$monitorRow['id']}:<br/>
                     Developer Description:<pre>{$monitorRow['deployment_message']}</pre>
                     Very important to Re-Enable Run This Update after you fix any data: <strong>$newSQL</strong>
                     <hr>
                     <br/>
                     <pre>
                        $theQuery
                     </pre>
                     <hr>
                     <br/>
                     This query runs at the top of the hour and was created by {$monitorRow['deployment_username']} on {$monitorRow['deployment_time']}.
                     <br/><br/>
                     This report is actively monitoring for a zero row count HIT and you were emailed because the count is GREATER than zero.
                     <br/>
                     {$monitorRow['deployment_files']}
                     ",
                     $theRegion."_ZERO_COUNT_ERR"
                     );
               }
            break;

            case '2':
               // -- daily count threshold run each day at 6AM
               if (date('G') == $dataObj->percent_change_execution_time)
               {
                  //counts by region mode with threshold comparison
                  //
                  foreach ($countsByRegion as $regionId=>$counts)
                  {
                     $dataObj->executeUpdate("INSERT INTO ".$dataObj->maintenance_database.".".$dataObj->table_names['counts']." (database_name,query_run,date_of_count,total_rows) VALUES ('".mysql_real_escape_string($regionId)."','".mysql_real_escape_string($theQuery)."',NOW(),'".mysql_real_escape_string($counts)."')");
                     $row = $dataObj->getSQLObject
                     (
                        "
                        SELECT
                           total_rows,
                           id,
                           date_of_count
                        FROM
                           ".$dataObj->maintenance_database.".".$dataObj->table_names['counts']."
                        WHERE
                           database_name = '".mysql_real_escape_string($regionId)."' AND
                           date_of_count = '".date('Y-m-d',strtotime('-1 day'))."' AND
                           query_run     = '".mysql_real_escape_string($theQuery)."'
                        "
                     );

                     if (!is_null($row->total_rows))
                     {
                        $percentChange = ((($counts - $row->total_rows) / $row->total_rows) * 100);

                        eval('$percentTrue = ('.$percentChange.' '.$dataObj->percent_change.');');

                        if ($percentTrue)
                        {
                           $percentChange = round($percentChange,2);
                           $dataObj->DeploymentNotify(
                           $dataObj->web_notify['developers'],
                           "{$monitorRow['deployment_username']}'s DeploymentMaintenance SQL Monitor: Found $percentChange% {$dataObj->percent_change}% Change in Record Count on $regionId",
                           "
                           DeploymentMaintenance found ($percentChange%) change that is outside your threshold monitor in $regionId When Running the following Query #{$monitorRow['id']}:<br/>
                           Previous Day ($row->date_of_count) Had: $row->total_rows Records<br />
                           Current Count Is : $counts Records<br />
                           Developer Description:<pre>{$monitorRow['deployment_message']}</pre>
                           <hr>
                           <br/>
                           <pre>
                              $theQuery
                           </pre>
                           <hr>
                           <br/>
                           This query runs each day to determine if out of the ordinary count percentages have exceeded the threshold of \"{$dataObj->percent_change}\"
                           <br/><br/>
                           This report is actively monitoring for a percent threshold and you were emailed because the threshold exceeds the percentage in the configuration.
                           <br/>
                           {$monitorRow['deployment_files']}
                           ",
                           $regionId."_PERCENT_THRESHOLD_ERR"
                           );
                        }
                     }
                  }
               }
            break;
         }
      }
   }
}
ob_end_clean();
?>
