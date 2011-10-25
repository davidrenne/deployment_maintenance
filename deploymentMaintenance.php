<?php
set_time_limit(0);
ini_set("memory_limit","250M");

class DeploymentMaintenance
{
   function __construct()
   {
      $this->deployment_file='';
      $this->processing_action='';
      $this->descriptive_log=array();
      $this->short_log=array();
      $this->all_errors=array();
      $this->current_databases=array();
      $this->emails=array();
      $this->transaction_number = 0;
      $this->currently_in_transaction = false;
      $this->in_transaction_error=array();
      $this->current_db_type = 'mysql';
      $this->debug_mode = false;
      $this->wd = dirname(__FILE__)."/";
      $this->pid = getmypid();
      $this->pid_fle = $this->wd."deployment_maintenance.pid";
      $this->person_file = $this->wd."deployment_maintenance_person.pid";
      if (file_exists($this->pid_fle) && !empty($_REQUEST['svnrev']))
      {
         die('DeploymentMaintenance is currently processing a request from <strong>'.file_get_contents($this->person_file).'\'s</strong> last request.  Please repost you request in a second.');
      }

      if (file_exists($this->wd."svndeploy/auto_deploy.sh") && !is_null($_REQUEST['auto_deploy']) && !empty($_REQUEST['svnrev']))
      {
         die('DeploymentMaintenance cron is waiting for the last auto deployment to execute.  Please repost you request in a second.  Only 1 auto deployment at a time.');
      }

      if (!empty($_REQUEST['svnrev']))
      {
         if (!$handle = fopen($this->pid_fle, 'w'))
         {
            echo "Cannot open file ($filename)";
         }

         if (fwrite($handle, $this->pid) === FALSE)
         {
            echo "Cannot write to file ($filename)";
         }
      }

      if ($_SESSION['username'])
      {
         if (!$handle = fopen($this->person_file, 'w'))
         {
            echo "Cannot open file ($filename)";
         }
         if (fwrite($handle, $_SESSION['username']) === FALSE)
         {
            echo "Cannot write to file ($filename)";
         }
         fclose($handle);
      }

      if (file_exists('files'.$_SESSION['username'].'.svn') && !empty($_REQUEST['svnfiles']))
      {
         if ($_REQUEST['branch'] != "")
         {
            $this->deployment_svn_region = "branches/".$_REQUEST['branch'];
         }
         else
         {
            $this->deployment_svn_region = "trunk";
         }
      }
   }

   function configure()
   {
      $this->testing_flags = array('TESTING_DONE'=>'Done Testing','NEEDS_MORE_DEV_TESTING'=>'More Dev Testing Needed','NEEDS_VERIFICATION'=>'Needs Testing Verification','UNIT_TESTED'=>'Unit Tested');
      $this->change_type_flags = array('NONE'=>'No Tag','BUG_FIX'=>'Bug fix','REFACTORED'=>'Refactored Logic','REQUIREMENT'=>'Business Requirement','MAINTENANCE'=>'Internal Maintenance','ZERO_IMPACT'=>'Zero Impact Modification');
      if (!is_array($this->web_notify['developers']))
      {
         $this->web_notify['developers'] = array($this->web_notify['developers']);
      }
      if (!is_array($this->web_notify['support']))
      {
         $this->web_notify['support'] = array($this->web_notify['support']);
      }
   }

   function diff_microtime($mt_old,$mt_new)
   {
      list($old_usec, $old_sec) = explode(' ',$mt_old);
      list($new_usec, $new_sec) = explode(' ',$mt_new);
      $old_mt = ((float)$old_usec + (float)$old_sec);
      $new_mt = ((float)$new_usec + (float)$new_sec);
      return $new_mt - $old_mt;
   }

   function DeleteFile($file)
   {
      if (file_exists($file))
      {
         return unlink($file);
      }
      else
      {
         return false;
      }
   }

   function __destruct()
   {
      if (is_resource($this->server['mysql']['connection']))
      {
         $db = $this->maintenance_database;
         if ($db != "")
         {
            mysql_close($this->server['mysql']['connection']);
         }
      }
      $this->DeleteFile($this->pid_fle);
      $this->DeleteFile($this->person_file);
   }

   function DeploymentMaintenanceLogIn($username,$password)
   {
      if (!session_id())
      {
         session_start();
      }
      $db = $this->maintenance_database;
      if ($db != "")
      {
         $this->server['mysql']['connection'] = mysql_connect($this->server['mysql']['server'],$this->server['mysql']['username'],$this->server['mysql']['password']);
         if (mysql_select_db($db,$this->server['mysql']['connection']))
         {
            $loginQuery = "select * from ".$this->maintenance_database.".".$this->table_names['users']." where username = '$username' and password ='".md5($password)."';";
            $results = mysql_query($loginQuery,$this->server['mysql']['connection']);
            $row = mysql_fetch_array($results);
            if ($row != null)
            {
               $_SESSION['username'] = $username;
            }
         }
      }
   }

   function ConnectMaintenance()
   {
      $db = $this->maintenance_database;
      $this->current_database = $this->maintenance_database;
      $success = true;
      if ($db != "")
      {
         if (! is_resource($this->server['mysql']['connection']) )
         {
            foreach ($this->server as $type=>$vals)
            {
               switch ($type)
               {
                  case 'mysql' :
                     $this->server['mysql']['connection'] = mysql_connect($vals['server'],$vals['username'],$vals['password']);
                     if (!$this->server['mysql']['connection'])
                     {
                        $success = false;
                     }
                     break;
               }
            }
         }

         if (!mysql_select_db($db,$this->server['mysql']['connection']))
         {
            $success = false;
         }
      }
      else
      {
         $success = false;
      }
      return $success;
   }
   
   /*
    * @method void getDeploymentRegions()
    */
   function getDeploymentRegions()
   {
      // -- this is a custom function where you need to generate an array of databases and deployment areas
      $db = $this->maintenance_database;
      $this->current_database = $this->maintenance_database;
      if ($db != "")
      {
         $this->client_paths = array();
         $this->current_databases = array();
         $this->client_deployment_regions = array();
         $this->deployment_display = null;
         $deploy = $_REQUEST['deploy'];
         if (empty($deploy))
         {
            $deploy = "ALL";
         }

         if (!$this->server['mysql']['connection'])
         {
            foreach ($this->server as $type=>$vals)
            {
               switch ($type)
               {
                  case 'mysql' :
                     $this->server['mysql']['connection'] = mysql_connect($vals['server'],$vals['username'],$vals['password']);
                     if (!$this->server['mysql']['connection'])
                     {
                        die('Cannot connect to mysql:'.$vals['server']);
                     }
                     break;
               }
            }
         }

         if (mysql_select_db($db,$this->server['mysql']['connection']))
         {
            $results = mysql_query("
            SELECT
               *, IF(deployment_type='PRODUCTION','0PRODUCTION',deployment_type) as deployment_sort
            FROM
               ".$this->maintenance_database.".".$this->table_names['clients']."
            WHERE
               active = 1
            ORDER BY deployment_sort, db_name ASC
            ",$this->server['mysql']['connection']);
            while ($row = mysql_fetch_assoc($results))
            {
               $this->client_deployment_regions['ALL'][$row['id']] = array('DB'=>$row['db_name'],'TYPE'=>$row['deployment_type'],'NAME'=>$row['name'],'ID'=>$row['id'],'PATH'=>$row['host_path']);
               $this->client_deployment_regions[$row['db_name']][$row['id']] = array('DB'=>$row['db_name'],'TYPE'=>$row['deployment_type'],'NAME'=>$row['name'],'ID'=>$row['id'],'PATH'=>$row['host_path']);
               $this->client_deployment_regions[$row['deployment_type']][$row['id']] = array('DB'=>$row['db_name'],'TYPE'=>$row['deployment_type'],'NAME'=>$row['name'],'ID'=>$row['id'],'PATH'=>$row['host_path']);
               if ($row['deployment_type'] != "PRODUCTION" && $row['deployment_type'] != "PRODUCTION_STAGING")
               {
                  $this->client_deployment_regions["ALL_NON_PRODUCTION"][$row['id']] = array('DB'=>$row['db_name'],'TYPE'=>$row['deployment_type'],'NAME'=>$row['name'],'ID'=>$row['id'],'PATH'=>$row['host_path']);
               }

               if ($row['deployment_type'] == "INTEGRATION" || $row['deployment_type'] == "PRODUCTION_STAGING")
               {
                  $this->client_deployment_regions["INTEGRATION-STAGING"][$row['id']] = array('DB'=>$row['db_name'],'TYPE'=>$row['deployment_type'],'NAME'=>$row['name'],'ID'=>$row['id'],'PATH'=>$row['host_path']);
               }

               if ($row['deployment_special'])
               {
                  $this->client_deployment_regions[$row['deployment_special']][$row['id']] = array('DB'=>$row['db_name'],'TYPE'=>$row['deployment_type'],'NAME'=>$row['name'],'ID'=>$row['id'],'PATH'=>'/home/sites/'.$row['host_path'].'/');
               }
            }
            if ($this->client_deployment_regions)
            {
               if (!empty($_REQUEST['customRegions']))
               {
                  foreach ($this->client_deployment_regions['ALL'] as $k=>$v)
                  {
                     foreach ($_REQUEST['customRegions'] as $regionID)
                     {
                        if ($regionID == $v['ID'])
                        {
                           if (!empty($v['PATH']))
                           {
                              $this->client_paths[$v['PATH']] = $v['PATH'];
                           }
                           if (!empty($v['DB']))
                           {
                              $this->current_databases[$v['PATH']] = $v['DB'];
                           }
                           $this->current_info[$v['PATH']] = $v;
                        }
                     }
                     $this->deployment_display = implode(", ",$this->current_databases);
                  }
               }
               else if (is_array($this->client_deployment_regions[$deploy]))
               {
                  $this->deployment_display = $deploy;
                  foreach ($this->client_deployment_regions[$deploy] as $k=>$v)
                  {
                     if (!empty($v['PATH']))
                     {
                        $this->client_paths[$v['PATH']] = $v['PATH'];
                     }
                     if (!empty($v['DB']))
                     {
                        $this->current_databases[$v['PATH']] = $v['DB'];
                     }
                     $this->current_info[$v['PATH']] = $v;
                  }
               }
               else
               {
                  die('No client deployment regions PATHs found for this region: "'.$deploy.'"');
               }
            }

            $pointers = array('svn_block_deploy','svn_skip_deploy');

            if (!empty($_REQUEST['svnrev']))
            {

               foreach ($pointers as $pointer)
               {
                  if (is_array($this->$pointer) && sizeof($this->$pointer) > 0)
                  {
                     foreach ($this->$pointer as $region=>$conditionals)
                     {
                        if (!is_array($conditionals))
                        {
                           if ($conditionals == $this->deployment_svn_region)
                           {
                              foreach ($this->client_paths as $cPath=>$cRegion)
                              {
                                 foreach ($this->client_deployment_regions[$region] as $row)
                                 {
                                    if ($cPath == $row['PATH'])
                                    {
                                       if ($pointer == 'svn_skip_deploy')
                                       {
                                          unset($this->current_databases[$row['PATH']],$this->client_paths[$row['PATH']]);
                                       }
                                       else
                                       {
                                          die("{$row['PATH']} is a \"$region\" region and according to the \$this->svn_block_deploy rule, you cannot launch from $conditionals.");
                                       }
                                    }
                                 }
                              }
                           }
                        }
                        else
                        {
                           foreach ($conditionals as $conditional)
                           {
                              if ($conditional == $this->deployment_svn_region)
                              {
                                 foreach ($this->client_paths as $cPath=>$cRegion)
                                 {
                                    foreach ($this->client_deployment_regions[$region] as $row)
                                    {
                                       if ($cPath == $row['PATH'])
                                       {
                                          if ($pointer == 'svn_skip_deploy')
                                          {
                                             unset($this->current_databases[$row['PATH']],$this->client_paths[$row['PATH']]);
                                          }
                                          else
                                          {
                                             die("{$row['PATH']} is a \"$region\" region and according to \$this->svn_block_deploy rule, you cannot launch from ".implode(',',$conditionals));
                                          }
                                       }
                                    }
                                 }
                              }
                           }
                        }
                     }
                  }
               }
            }
         }
      }
      else
      {
         $this->client_paths[] = "/home/website/www/";
         $this->client_names[] = "website1";
         $this->current_databases[] = "database1";
      }
   }

   /*
    * @method void getSQLHistory()
    */
   function getSQLHistory()
   {
      $db = $this->maintenance_database;
      $this->current_database = $this->maintenance_database;
      if ($db != "")
      {
         if (!$this->server['mysql']['connection'])
         {
            foreach ($this->server as $type=>$vals)
            {
               switch ($type)
               {
                  case 'mysql' :
                     $this->server['mysql']['connection'] = mysql_connect($vals['server'],$vals['username'],$vals['password']);
                     if (!$this->server['mysql']['connection'])
                     {
                        die('Cannot connect to mysql:'.$vals['server']);
                     }
                     break;
               }
            }
         }

         if (mysql_select_db($db,$this->server['mysql']['connection']))
         {
            if (array_key_exists('offset',$_GET))
            {
               $offset  = $_GET['offset'] + 1;
               $now     = strtotime('-'.$_GET['offset'].' month');
            }
            else
            {
               $offset  = 1;
               $now     = time();
            }
            $results = mysql_query("select distinct deployment_results, concat(YEAR( deployment_time ),'-' , MONTH( deployment_time ),'-' , DAY( deployment_time )) as date_of_query, deployment_type from ".$this->maintenance_database.".".$this->table_names['clients_updates']." where deployment_username = '".$_SESSION['username']."' AND deployment_type LIKE 'QUERY_IN%' AND deployment_time BETWEEN '".date('Y-m-d H:i:s',strtotime('-'.$offset.' month'))."' AND '".date('Y-m-d H:i:s',$now)."' ORDER BY deployment_time DESC ",$this->server['mysql']['connection']);
            while ($row = mysql_fetch_assoc($results))
            {
               if (strlen($row['deployment_results']) < 100000)
               {
                  $this->query_cache[$row['date_of_query']][] = $row['deployment_results'];
                  $this->query_cache_regions[$row['date_of_query']][] = $row['deployment_type'];
               }
               else
               {
                  //$this->FileLogger("Skipping #{$row['id']} from query cache as it exceeds 100K bytes");
               }
            }
         }
      }
   }


   function CustomFlagDisplay()
   {
      switch($this->special_setup)
      {
         case "setup1":
            return "<tr class=\"deployment\"><td>Custom SVN Release:</td><td>(llc_app):<input type='checkbox' name='custom_flag' id='custom_flag'/></td></tr>";
            break;
         default:
            return "";
      }
   }

   function GetProjectStatus($projectName)
   {
      $db = $this->maintenance_database;
      if ($db != "")
      {
         $this->server['mysql']['connection'] = mysql_connect($this->server['mysql']['server'],$this->server['mysql']['username'],$this->server['mysql']['password']);
         if (mysql_select_db($db,$this->server['mysql']['connection']))
         {
            $q = "select MAX(id) as id from ".$this->maintenance_database.".".$this->table_names['clients_updates']." where deployment_project = '{$projectName}'";
            $results = mysql_query($q,$this->server['mysql']['connection']);
            $row = mysql_fetch_assoc($results);

            $q = "select deployment_project, deployment_project_status, deployment_type from ".$this->maintenance_database.".".$this->table_names['clients_updates']." where id = '{$row['id']}'";
            $results = mysql_query($q,$this->server['mysql']['connection']);
            $row = mysql_fetch_assoc($results);
            $selectedStatus = $row['deployment_project_status'];
            $selectedRegion = $row['deployment_type'];
            if (empty($selectedStatus))
            {
               $selectedStatus = 'DEVELOPMENT';
            }
            if (empty($selectedRegion) || $selectedRegion == 'ALL' || $selectedRegion == 'PRODUCTION')
            {
               $selectedRegion = 'INTEGRATION';
            }
            return "\$('project_status').value = '$selectedStatus';\$('deploy').value = '$selectedRegion';";
         }
      }
   }

   function CustomProjectDisplay($allCheckins)
   {

      $db = $this->maintenance_database;
      if ($db != "")
      {
         $this->server['mysql']['connection'] = mysql_connect($this->server['mysql']['server'],$this->server['mysql']['username'],$this->server['mysql']['password']);
         if (mysql_select_db($db,$this->server['mysql']['connection']))
         {
            $q = "select MAX(id) as id from ".$this->maintenance_database.".".$this->table_names['clients_updates']." where deployment_username = '{$_SESSION['username']}' AND deployment_project != ''";
            $results = mysql_query($q,$this->server['mysql']['connection']);
            $row = mysql_fetch_assoc($results);

            $q = "select deployment_project, deployment_project_status from ".$this->maintenance_database.".".$this->table_names['clients_updates']." where id = '{$row['id']}'";
            $results = mysql_query($q,$this->server['mysql']['connection']);
            $row = mysql_fetch_assoc($results);
            $selected = $row['deployment_project'];
            //$selectedStatus = $row['deployment_project_status'];


            $q = "select MAX(id) as id from ".$this->maintenance_database.".".$this->table_names['clients_updates']." where deployment_username = '{$_SESSION['username']}' AND `deployment_message` NOT LIKE '%Files Released%' AND `deployment_type` NOT LIKE 'QUERY_IN%'";
            $results = mysql_query($q,$this->server['mysql']['connection']);
            $row = mysql_fetch_assoc($results);

            $q = "select deployment_message from ".$this->maintenance_database.".".$this->table_names['clients_updates']." where id = '{$row['id']}'";
            $results = mysql_query($q,$this->server['mysql']['connection']);
            $row = mysql_fetch_assoc($results);

            $this->lastDeploymentMessage = $row['deployment_message'];
         }
      }

      $paths = array();
      $paths = $this->project_folder_paths;
      $allFiles = array();
      foreach ($paths as $path)
      {
         if (is_dir($path))
         {
            if ($handle = opendir($path))
            {
                while (false !== ($file = readdir($handle)))
                {
                   if (!array_key_exists($file,$allFiles) && !in_array($file,array('.','..','.svn')) && is_dir($path.$file))
                   {
                      $allFiles[$file] = $file;
                   }
                }
                closedir($handle);
            }
         }
      }

      $options .= "<option value=''>Select A Project</option>";

      if (!empty($allCheckins))
      {
         foreach ($allCheckins as $branchName=>$branch)
         {
            $options .= "<optgroup label=\"SVN Checkins ".$branchName.":\">";
            foreach ($branch as $rev=>$messageArr)
            {
               $message = $messageArr['message'];
               $disp = $dotdotdot = "";
               if (empty($message))
               {
                  $disp = " style='display:none;' ";
               }
               else
               {
                  $dotdotdot = '...';
               }
               $options .= "<option $disp value='$rev' message='$message'>".substr($message,0,50).$dotdotdot."</option>";
            }
         }
      }

      if(!empty($allFiles))
      {
         $options .= "<optgroup label=\"Project Folders:\">";
         arsort($allFiles);
         foreach ($allFiles as $theFile)
         {
            $sel = '';
            if ($selected == $theFile)
            {
               $sel = 'selected';
            }
            $options .= "<option $sel value='$theFile'>$theFile</option>";
         }
         eval('$'.str_replace(array(' ','/'),array('_','_'),$selectedStatus).'_SEL = \'selected\';');
      }

      return "
      <tr>
         <td class='deployment'>Project & Status:<br/>
            <select id='project_status' name='project_status'>
               <option $DEVELOPMENT_SEL value='DEVELOPMENT'>DEVELOPMENT</option>
               <option $ENVISIONING_SEL value='ENVISIONING'>ENVISIONING</option>
               <option $READY_FOR_TESTING_SEL value='READY FOR TESTING'>READY FOR TESTING</option>
               <option $DEV_UNIT_TESTING_SEL value='DEV/UNIT TESTING'>DEV/UNIT TESTING</option>
               <option $IN_PRODUCTION_SEL value='IN PRODUCTION'>IN PRODUCTION</option>
               <option $ROLLED_BACK_SEL value='ROLLED BACK'>ROLLED BACK</option>
               <option $CANCELED_SEL value='CANCELED'>CANCELED</option>
               <option $ON_HOLD_SEL value='ON HOLD'>ON HOLD</option>
            </select>
         </td>
         <td class='deployment'><br/>
            <select onchange='getProjectStatus();' id='project_name' name='project_name'>$options</select>
            <input type='hidden' id='project_name_hidden' name='project_name_hidden'/>
         </td>
      </tr>";
   }

   function CustomFlagHandler()
   {
      if ($_REQUEST['custom_flag'] == null)
      {
         $customFlag = 0;
      }
      else
      {
         $customFlag = 1;
      }
      // add your custom logic for any special HTML flags you want to customize

      if ($customFlag == 1)
      {
         switch($this->special_setup)
         {
            case "setup1":

               break;
         }
         return $msg;
      }
   }

   function CustomDeploymentTracking($type=null)
   {
      // -- keep track of all your updates and releases to testing and prod regions (optional)
      $db = $this->maintenance_database;
      $this->current_database = $this->maintenance_database;

      if ($db != "")
      {
         if (empty($this->current_databases))
         {
            $this->current_databases[] = "No database updates";
         }
         if (empty($this->deployment_file))
         {
            $this->deployment_file = "No database file upload";
         }

         if (mysql_select_db($db,$this->server['mysql']['connection']) && $this->debug_mode == 0)
         {
            if (empty($_REQUEST['project_name_hidden']))
            {
               $_REQUEST['project_name_hidden'] = $_REQUEST['deploy_message'];
            }
            if ($type != null)
            {
               $deploymentType = $type;
            }
            else
            {
               $deploymentType = $_REQUEST['deploy'];
            }

            $this->executeUpdate("insert into ".$this->maintenance_database.".".$this->table_names['clients_updates']." (deployment_svn_branch,deployment_project,deployment_project_id,deployment_project_status,deployment_results,deployment_type,deployment_message,deployment_sql_file,deployment_hosts,deployment_databases,deployment_emailed_to,deployment_svn,deployment_files,deployment_file_hashes,deployment_local_files,deployment_username) VALUES ('".mysql_real_escape_string($this->deployment_svn_region)."','".mysql_real_escape_string($_REQUEST['project_name_hidden'])."','".mysql_real_escape_string($this->project_id)."','{$_REQUEST['project_status']}','".mysql_real_escape_string($this->email_body)."','{$deploymentType}','".mysql_real_escape_string($_REQUEST['deploy_message'])."','".mysql_real_escape_string(basename($this->deployment_file))."',\"".implode("\n",$this->client_paths)."\",\"".implode("\n",$this->current_databases)."\",'".implode("\n",$this->email_to)."','{$_REQUEST['svnrev']}','".implode("\n",$this->deploying_files)."','".implode("\n",$this->deployment_files_hashes)."','".implode("\n",$this->deployment_local_files)."','".$_SESSION['username']."')");
            $id = mysql_insert_id();

            if ( (($_REQUEST['deploy'] == 'ALL' || $_REQUEST['deploy'] == 'PRODUCTION') && $this->project_id) && ($_REQUEST['svnrev'] || (strlen(basename($this->deployment_file)) > 0 || $this->deployment_file != 'No database file upload')) )
            {
               mysql_query("
               UPDATE
                  ".$this->maintenance_database.".".$this->table_names['clients_updates']."
               SET
                  deployment_project_status = 'IN PRODUCTION'
               WHERE
                  deployment_project_id = '".mysql_real_escape_string($this->project_id)."'
               ");
            }
            return $id;
         }
      }
   }

   function CustomExitHandler()
   {
      $db = $this->maintenance_database;
      $this->current_database = $this->maintenance_database;
      if ($db != "")
      {
         if (mysql_select_db($db,$this->server['mysql']['connection']))
         {

            $results = mysql_query("select * from ".$this->maintenance_database.".".$this->table_names['clients_updates']." where deployment_sql_file = '".basename($this->deployment_file)."' and deployment_type = '{$_REQUEST['deploy']}'",$this->server['mysql']['connection']);
            $count = mysql_num_rows($results);
            if ($count==0)
            {
               return true;
            }
            else
            {
               $data = mysql_fetch_object($results);
               if ($this->allow_same_file_to_region_twice && $this->debug_mode == false)
               {
                  return "You cannot proceed.  File: <strong>". $data->deployment_sql_file ."</strong> was executed in ". $data->deployment_type . " on <strong>".$data->deployment_time."</strong>";
               }
               else
               {
                  return true;
               }
            }
         }
         else
         {
            return true;
         }
      }
      else
      {
         return true;
      }
   }

   function GetCountCheckMonitors()
   {
      $db = $this->maintenance_database;
      $this->current_database = $this->maintenance_database;
      if ($db != "")
      {
         if (mysql_select_db($db,$this->server['mysql']['connection']))
         {

            $results = mysql_query("select * from ".$this->maintenance_database.".".$this->table_names['clients_updates']." where deployment_cron_count_check IN (1,2)",$this->server['mysql']['connection']);
            $count = mysql_num_rows($results);
            while ($row = mysql_fetch_assoc($results))
            {
               // -- multi queries and custom regions not supported
               if (stristr($row['deployment_type'],'_custom') || strpos($row['deployment_results'],";;") !== false)
               {
                  continue;
               }
               $row['deployment_type'] = str_replace("QUERY_IN_","",$row['deployment_type']);
               $allRows[$row['deployment_results']] = $row;
            }
            return $allRows;
         }
      }
   }

   function GetRowThresholdChangeMonitors()
   {
      $db = $this->maintenance_database;
      $this->current_database = $this->maintenance_database;
      if ($db != "")
      {
         if (mysql_select_db($db,$this->server['mysql']['connection']))
         {

            $results = mysql_query("select * from ".$this->maintenance_database.".".$this->table_names['clients_updates']." where deployment_cron_count_check = 2",$this->server['mysql']['connection']);
            $count = mysql_num_rows($results);
            while ($row = mysql_fetch_assoc($results))
            {
               // -- multi queries and custom regions not supported
               if (stristr($row['deployment_type'],'_custom') || strpos($row['deployment_results'],";;") !== false)
               {
                  continue;
               }
               $regionName = str_replace("QUERY_IN_","",$row['deployment_type']);
               $regions[$regionName] = $row['deployment_results'];
               $allRows[$row['deployment_results']] = $row;
            }
            return array($regions,$allRows);
         }
      }
   }


   function GetLastTwoWeeksDeployments()
   {
      $db = $this->maintenance_database;
      $this->current_database = $this->maintenance_database;
      if ($db != "")
      {
         if (mysql_select_db($db,$this->server['mysql']['connection']))
         {

            $results = mysql_query("
            SELECT
               *,
               IF( deployment_time < date_add(CURRENT_TIMESTAMP, interval 1 hour),'is_hourly','is_weekly') as row_type
            FROM
               ".$this->maintenance_database.".".$this->table_names['clients_updates']."
            WHERE
               deployment_time < date_add(CURRENT_TIMESTAMP, interval 2 week) AND
               deployment_hosts != '' AND
               deployment_file_hashes != '' AND
               deployment_files != ''",$this->server['mysql']['connection']);
            $count = mysql_num_rows($results);
            while ($row = mysql_fetch_assoc($results))
            {
               //$allRows[$row['id']] = ;
            }
            return array($regions,$allRows);
         }
      }
   }

   function DisableCountCheckMonitor($id)
   {
      $db = $this->maintenance_database;
      $this->current_database = $this->maintenance_database;
      if ($db != "")
      {
         if (mysql_select_db($db,$this->server['mysql']['connection']))
         {
            $sql = "UPDATE ".$this->maintenance_database.".".$this->table_names['clients_updates']." set deployment_cron_count_check_time=NOW(), deployment_cron_count_check = <!--VALUE--> WHERE id  = '{$id}'";
            $results = mysql_query(str_replace('<!--VALUE-->','0',$sql),$this->server['mysql']['connection']);
            return str_replace('<!--VALUE-->','1',$sql);
         }
      }
   }

   function GetAffectedBlocksHTML($mode,$file,$projectId,$extraFilter='')
   {
      $this->ConnectMaintenance();
      if ($mode == 'timestamp')
      {
         $orderBy = 'ORDER BY block_of_code_modified_time,change_type_flag DESC';
      }
      $rows = $this->GetImpactedBlockRows("$extraFilter project_id ='$projectId' AND file_name='$file' AND ignore_flag = 0 AND testing_title <> '' $orderBy");
      $rows2 = $this->GetImpactedBlockRows("$extraFilter project_id ='$projectId' AND file_name='$file' AND ignore_flag = 0 AND testing_title = '' $orderBy");
      $return = '';
      if (!empty($rows))
      {
         foreach ($rows as $row)
         {
            $i++;
            $testDesc = "";
            $timeDesc = "";
            $verificationDesc = "";
            $strikeThrough = "";
            $strikeThrough2 = "";
            $revInfo = "";
            if (!empty($row['testing_description']))
            {
               if ($row['testing_flag'] == 'NEEDS_VERIFICATION')
               {
                  $strikeThrough = 'color:red';
                  $verificationDesc = ' <strong style="color:red">('.$this->testing_flags[$row['testing_flag']].')</strong>';
               }

               if ($row['testing_flag'] == 'TESTING_DONE')
               {
                  $strikeThrough = 'text-decoration: line-through';
                  $strikeThrough2 = ' <strong style="color:green">('.$this->testing_flags[$row['testing_flag']].')</strong>';
               }

               if ($row['testing_flag'] == 'NEEDS_MORE_DEV_TESTING')
               {
                  $strikeThrough2 = ' <strong style="color:grey">('.$this->testing_flags[$row['testing_flag']].')</strong>';
               }

               if (array_key_exists('svnrev',$_REQUEST) && $_REQUEST['svnrev'] == $row['revision'])
               {
                  $revInfo = "<span style='color:lime'>(+NEW)</span>";
               }
               elseif (array_key_exists('svnrev',$_REQUEST))
               {
                  $revInfo = "<span style='color:blue'>(REV {$row['revision']})</span>";
               }
               $testDesc = "<ul style='font-style:italic;'><li>{$revInfo}{$verificationDesc}{$strikeThrough2}<div style='margin-top: 10px; margin-bottom: 10px; margin-left: 21px;'>{$row['testing_description']}</div></li></ul>";
            }
            if (!empty($row['block_of_code_modified_time']) && $row['block_of_code_modified_time'] != '0000-00-00 00:00:00')
            {
               $timeDesc = " on ".date("m/d/Y",strtotime($row['block_of_code_modified_time']));
            }
            if ($row['change_type_flag'] != 'NONE')
            {
               $typeDesc = $this->change_type_flags[$row['change_type_flag']].' Change';
            }
            else
            {
               $typeDesc = "Change";
            }

            $testTitle = '<strong title="View Block Of Code" style="'.$strikeThrough.'">'.str_replace(array('<!--NAME-->','<!--SQL-->'),array("\"".$row['testing_title']."\"",'SELECT block_of_code FROM '.$this->maintenance_database.'.'.$this->table_names['blocks_affected'].' WHERE id = \''.$row['id'].'\'&use_pre=1'),$this->query_link_template).'</strong>';

            $return .= "
            <ul>
               <li>
                  $typeDesc #$i Created: {$testTitle} - (coded by {$row['developer']}{$timeDesc})
                  $testDesc
               </li>
            </ul>
            ";
         }


         if (!empty($rows2))
         {
            foreach ($rows2 as $row2)
            {
               $otherIds[$row2['id']] = $row2['id'];
            }

            $testTitle = '<strong title="View Block Of Code" style="'.$strikeThrough.'">'.str_replace(array('<!--NAME-->','<!--SQL-->'),array("View ".count($otherIds)." Other Non-Tagged Blocks",'SELECT block_of_code FROM '.$this->maintenance_database.'.'.$this->table_names['blocks_affected'].' WHERE id IN ('.implode(',',$otherIds).')&use_pre=1'),$this->query_link_template).'</strong>';
            $return .= "
            <ul>
               <li>
                  $testTitle
               </li>
            </ul>
            ";
         }
      }
      return $return;
   }


   function ClockProjectTime($userName,$stopTime)
   {
      $row = $this->getSQLObject(
         "
         SELECT
           MAX(rev_modified_time) as rev_modified_time
         FROM
           ".$this->maintenance_database.".".$this->table_names['checkins']."
         WHERE
           developer = '{$userName}' AND
           project_id > 0
         GROUP BY
           developer
         "
      );

      $row = $this->getSQLObject(
         "
         SELECT
           rev_modified_time,
           id as max_id,
           project_id,
           revision,
           developer
         FROM
           ".$this->maintenance_database.".".$this->table_names['checkins']."
         WHERE
           developer         = '{$userName}' AND
           rev_modified_time = '{$row->rev_modified_time}' AND
           project_id > 0
         GROUP BY
           developer
         "
      );



      if ($row->max_id != null)
      {
         $newTime  = strtotime($stopTime);
         $lastTime = strtotime($row->rev_modified_time);
         $dateOfLastCheckin = date('Y-m-d',$lastTime);
         if ($dateOfLastCheckin != date('Y-m-d'))
         {
            $lastTime = mktime($this->developer_start_of_day,0,0,date('m'),date('d'),date('Y'));
         }
         $difference = round(($newTime - $lastTime) / 60);
         $sql = "UPDATE ".$this->maintenance_database.".".$this->table_names['checkins']." SET minutes_logged = '$difference' WHERE id = '{$row->max_id}'";
         $this->executeUpdate($sql);
      }
   }

   function AddCheckin($projectId,$revision,$branch,$timeModified,$userName,$message)
   {
      $this->ConnectMaintenance();
      if ($this->getRowCount("SELECT 1 FROM ".$this->maintenance_database.".".$this->table_names['checkins']." WHERE revision = '".$revision."'") == 0)
      {
         $this->ClockProjectTime($userName,$timeModified);
         $sql = "INSERT INTO ".$this->maintenance_database.".".$this->table_names['checkins']." (rev_modified_time, branch, project_id, revision, developer, date_added, checkin_message) VALUES('".$timeModified."','".$branch."','".mysql_real_escape_string($projectId)."','".mysql_real_escape_string($revision)."','{$userName}',CURRENT_TIMESTAMP,'".mysql_real_escape_string($message)."')";
         $this->executeUpdate($sql);
         return true;
      }
      else
      {
         return false;
      }
   }

   function AddImpactedBlockOfCode($projectId,$blockOfCode,$lineIds,$revision,$branch,$fileName,$timeModified)
   {
      $this->ConnectMaintenance();
      $blockHash = md5($blockOfCode);
      if ($this->getRowCount("SELECT 1 FROM ".$this->maintenance_database.".".$this->table_names['blocks_affected']." WHERE hash_id = '".$blockHash."'") == 0)
      {
         $sql = "INSERT INTO ".$this->maintenance_database.".".$this->table_names['blocks_affected']." (block_of_code_modified_time, branch, testing_flag, hash_id, project_id, block_of_code, line_info, revision, testing_description, developer, date_added, file_name) VALUES('".$timeModified."','".$branch."','UNIT_TESTED','".mysql_real_escape_string($blockHash)."','".mysql_real_escape_string($projectId)."','".mysql_real_escape_string($blockOfCode)."','".mysql_real_escape_string($lineIds)."','".mysql_real_escape_string($revision)."','','{$_SESSION['username']}',CURRENT_TIMESTAMP,'".$fileName."')";
         $this->executeUpdate($sql);
         return array(mysql_insert_id(),true);
      }
      else
      {
         $obj = $this->getSQLObject("SELECT * FROM ".$this->maintenance_database.".".$this->table_names['blocks_affected']." WHERE hash_id = '".$blockHash."'");
         return array($obj,false);
      }
   }

   function UpdateProjectAsComplete($projectId)
   {
      $this->ConnectMaintenance();
      $sql = "UPDATE ".$this->maintenance_database.".".$this->table_names['clients_updates']." SET deployment_project_status = 'IN PRODUCTION' WHERE deployment_project_id = '{$projectId}'";
      $this->executeUpdate($sql);
   }

   function EditImpactedBlockOfCode()
   {
      $this->ConnectMaintenance();
      $sql = "UPDATE ".$this->maintenance_database.".".$this->table_names['blocks_affected']." SET testing_flag = '".mysql_real_escape_string($_REQUEST['test_flag'])."', change_type_flag = '".mysql_real_escape_string($_REQUEST['tag'])."', testing_title = '".mysql_real_escape_string($_REQUEST['title'])."', testing_description = '".mysql_real_escape_string(str_replace("\n","<br />",$_REQUEST['description']))."' WHERE id = '{$_REQUEST['id']}'";
      $this->executeUpdate($sql);
      return "true";
   }

   function GetImpactedBlockRows($where)
   {
      $this->ConnectMaintenance();
      return $this->getRecords("SELECT * FROM ".$this->maintenance_database.".".$this->table_names['blocks_affected']." WHERE $where");
   }

   function addDeploymentCompleteRecord()
   {
      global $argv;
      $db = $this->maintenance_database;
      $this->current_database = $this->maintenance_database;
      if ($db != "")
      {
         if (mysql_select_db($db,$this->server['mysql']['connection']))
         {
            $args = $argv;
            unset($args[0]);
            $deploy = $args[1];
            $svn = $args[2];
            $type = $args[3];
            $q1 = "SELECT max(id) as id FROM ".$this->maintenance_database.".".$this->table_names['clients_updates']." WHERE deployment_svn = '$svn' AND deployment_message NOT LIKE '%Files Released%'";
            $id = $this->getSQLObject($q1);
            $q2 = "SELECT cu.*, u.email FROM ".$this->maintenance_database.".".$this->table_names['clients_updates']." cu, ".$this->maintenance_database.".".$this->table_names['users']." u WHERE cu.deployment_username = u.username AND cu.id = '{$id->id}'";
            $deploymentRow = $this->getSQLObject($q2);

            $this->executeUpdate("insert into ".$this->maintenance_database.".".$this->table_names['clients_updates']." (deployment_results,deployment_type,deployment_message,deployment_sql_file,deployment_hosts,deployment_databases,deployment_emailed_to,deployment_svn,deployment_username) VALUES (\"Files {$type}\",\"{$deploy}\",\"Files {$type} for release {$deploy}\",\"{$deploy}\",\"\",\"\",\"\",\"{$svn}\",\"\")",$this->server['mysql']['connection']);

            $user = ucwords($deploymentRow->deployment_username);
            if ($deploymentRow->deployment_type == "ALL")
            {
               $deploymentRow->deployment_type = "ALL DEPLOYMENT REGIONS";
            }
            if ($deploymentRow->id > 0)
            {
               $name = (array_key_exists('support',$this->web_notify)) ? implode(", ",$this->web_notify['support']).',<br /><br />' : '';
               $filesAffected = '';
               foreach(explode("\n",$deploymentRow->deployment_files) as $file)
               {
                  $filesAffected .= "<li>$file";
                  $filesAffected .= $this->GetAffectedBlocksHTML('timestamp',$file,$deploymentRow->deployment_project_id);
                  $filesAffected .= "</li>";
               }
               if ($type == 'Reverted')
               {
                  $subject = "Ticket #{$deploymentRow->deployment_project_id} Full Reverted From ". $deploymentRow->deployment_type;
                  $template =
                  "
                  $name
                  Ticket <a href='{$this->ticketing_system_url}{$deploymentRow->deployment_project_id}'>#{$deploymentRow->deployment_project_id} ({$this->ticketing_system_url}{$deploymentRow->deployment_project_id})</a> was <strong style='color:red'>reverted FROM {$deploymentRow->deployment_type}</strong> by {$user} on {$deploymentRow->deployment_time}.<br /><br />
                  Please:<br />
                  <ul>
                     <li>Verify this functionality has been reverted and everything seems normal</li>
                     <li>Notify any appropriate clients affected of this functionality removal</li>
                     <li>Re-open the ticket and work to resolve any action items and/or testing items with the reverion.</li>
                  </ul>
                  Files Affected:
                  <ul>
                     $filesAffected
                  </ul>
                  Sorry,<br /><br />
                  {$user}
                  ";
               }
               else
               {
                  $subject = "Ticket #{$deploymentRow->deployment_project_id} Successfully Deployed To ". $deploymentRow->deployment_type;
                  $template =
                  "
                  $name
                  Ticket <a href='{$this->ticketing_system_url}{$deploymentRow->deployment_project_id}'>#{$deploymentRow->deployment_project_id} ({$this->ticketing_system_url}{$deploymentRow->deployment_project_id})</a> was launched successfully into {$deploymentRow->deployment_type} by {$user} on {$deploymentRow->deployment_time}.<br /><br />
                  Notes: <i><h4 style='color:grey'>\"{$deploymentRow->deployment_project}\"</h4></i>
                  Please:<br />
                  <ul>
                     <li>Verify any portion of the project needed to be validated in PRODUCTION</li>
                     <li>Notify any client of this release availability</li>
                     <li>Review the ticket information and close/reopen as applicable</li>
                  </ul>
                  Files Affected:
                  <ul>
                     $filesAffected
                  </ul>
                  Thanks,<br /><br />
                  {$user}
                  ";
               }
               $notify   = array_merge($this->web_notify['developers'] ,$this->web_notify['support'] );
               $from     = "deployment_maintenance@".$_SERVER['HTTP_HOST'];
            }
            else
            {
               $template = "Error pulling records.  No deployment email sent to support email.<br /><br />Query 1:$q1<br /><br />Query 2:$q2";
               $subject  = "Error finding deploymentMaintenance record(s) no email sent";
               $notify   = $this->web_notify['developers'];
               $from     = "deployment_maintenance@".$_SERVER['HTTP_HOST'];
            }

            $email = new EmailSender( $template );
            $email->set_from( $from );
            $email->set_content_type( 'text/html' );
            $email->set_to( $notify );
            $email->set_subject( $subject );
            $ret = $email->sendEmail();
         }
      }
   }

   function addBatchQuery($database,$query)
   {
      $this->batch_queries[$database][] = $query;
   }

   function getThreadsConnected()
   {
      if ($this->poll_threads_and_throttle)
      {
         $results = mysql_query("show status where Variable_name = 'Threads_running'");
         $row = mysql_fetch_assoc($results);
         return $row['Value'];
      }
      else
      {
         return 0;
      }
   }

   function executeBatchQueries()
   {
      foreach ($this->batch_queries as $db=>$queries)
      {
         //mysql_selectdb($db);
         echo "Processing ".count($queries)." Queries on $db<br/><br/>\n\n";
         $this->current_database = $db;

         foreach ($queries as $query)
         {
            $currentExecution++;
            $this->current_query = $query;
            $results = $this->runGenericQuery();

            echo "Ran Query in $db: $query ($this->affected_html)<br/><br/>\n\n";
            if ($this->database_error)
            {
               echo "ERROR!!! -> ".$this->database_error."<br/><br/>\n\n";
            }

            if ($currentExecution == 100)
            {
               $currentExecution=0;
               //echo "SLEEPING 5 seconds after";
               //sleep(2);
               $threads = $this->getThreadsConnected();
               echo "100 Queries - Current threads = {{$threads}}<br/><br/>\n\n";
               //sleep(5);
            }

            if ($currentExecution == 25)
            {
               //sleep(1);
               $threads = $this->getThreadsConnected();
               echo "75 Queries - Current threads = {{$threads}}<br/><br/>\n\n";
            }

            if ($currentExecution == 50)
            {
               //sleep(1);
               $threads = $this->getThreadsConnected();
               echo "75 Queries - Current threads = {{$threads}}<br/><br/>\n\n";
            }

            if ($currentExecution == 75)
            {
               //sleep(1);
               $threads = $this->getThreadsConnected();
               echo "75 Queries - Current threads = {{$threads}}<br/><br/>\n\n";
            }
            $this->balanceDiskLoad($threads);
         }
      }
   }

   function balanceDiskLoad($threads='')
   {
      // -- currently will sleep if there are more than 10 threads running it will slow the process down
      if (!$threads)
      {
         $threads = $this->getThreadsConnected();
      }
      if ($threads > 10)
      {
         echo "SLEEPING 15 seconds because threads greater than 10";
         echo "<br/><br/>\n\n";
         while (true)
         {
            sleep(15);
            $threads = $this->getThreadsConnected();
            echo "Infinite Wait - Current threads = {{$threads}}<br/><br/>\n\n";
            if ($threads < 5)
            {
               echo "Threads down to less than 5 proceeding with execution of queries";
               break;
            }
            echo "<br/><br/>\n\n";
         }
      }
   }

   function getAllFunctionNames($codeFileContents)
   {
      if (file_exists($codeFileContents))
      {
         $codeFileContents = file_get_contents($codeFileContents);
      }
      $return = array();
      //preg_match_all('(\sfunction\s)',$codeFileContents,$matches1,PREG_OFFSET_CAPTURE );
      //preg_match_all('(\nfunction\s)',$codeFileContents,$matches2,PREG_OFFSET_CAPTURE );
      preg_match_all('(function\s)'  ,$codeFileContents,$matches,PREG_OFFSET_CAPTURE );

      /*$allMatches = array_merge($matches1 , $matches2, $matches3);
      foreach ($allMatches as $match)
      {
         $s++;
         if (!empty($match))
         {
            $offset = ($s==3) ? 9 : 10;
            $theMatch = $match;
         }
      }*/

      foreach ($matches[0] as $match)
      {
         $funcStartPost        = $match[1];
         // -- function names have a max of 50 chars
         $possibleFunctionName = substr($codeFileContents,$funcStartPost,60);
         $funcEndPos           = strpos($possibleFunctionName,"(");
         if ($funcEndPos > 0)
         {
            $functionName      = trim(str_replace(array('function',' '),array('',''),substr($possibleFunctionName,9,($funcEndPos - 9))));
            // -- not supporting magic php functions
            if (!empty($functionName) && substr($functionName,0,2) != '__')
            {
               $return[]       = $functionName;
            }
         }
      }
      return $return;
   }

   function queryDeploymentRegions($sql,$hideData=0,$exportCSVFlag=0,$combineResultsFlag=1,$separateData=0)
   {
      if (isset($_REQUEST['saveHTML']))
      {
         if (!stristr($_REQUEST['save_to'],'.ht'))
         {
            $_REQUEST['save_to'] .= '.html';
         }
         $newFile = str_replace(array(' '),array('_'),$_REQUEST['save_to']);
         rename($this->wd."svndeploy/".$_REQUEST['saveHTML'],$this->wd."svndeploy/".$newFile);
         if (stristr($_REQUEST['hashes'],','))
         {
            $allHashes = explode(',',$_REQUEST['hashes']);
         }
         else
         {
            $allHashes = array($_REQUEST['hashes']);
         }
         foreach ($allHashes as $hash)
         {
            exec("cp {$this->wd}archive/*$hash* {$this->wd}svndeploy/");
         }
         if ($_REQUEST['comments'] != 'null')
         {
            file_put_contents( $this->wd."svndeploy/".$newFile ,str_replace("<!--COMMENT_AREA-->","<div class=\"pullquote\">".$_SESSION['username']."'s Description: ".$_REQUEST['comments']."</div>", file_get_contents($this->wd."svndeploy/".$newFile)));
         }
         header("Location: ".dirname($_SERVER['SCRIPT_NAME'])."/svndeploy/".$newFile);
         die();
      }
      // -- make connections to all dbtypes (mysql is the only one i tested)
      if (!isset($_REQUEST['export_csv_type']))
      {
         if (strpos($sql,";;") !== false)
         {
            $sqls = explode(";;",$sql);
         }
         else
         {
            if (empty($sql))
            {
               return false;
            }
            $sqls[] = $sql;
         }

         foreach ($sqls as $id=>$theQuery)
         {
            if (stristr($theQuery,';') && !stristr($theQuery,'procedure') && !stristr($theQuery,'trigger') && !stristr($theQuery,'function'))
            {
               unset($sqls[$id]);
               $queries = explode('\n;',$theQuery);
               foreach ($queries as $q)
               {
                  $q = trim($q);
                  if (!empty($q))
                  {
                     $sqls[] = $q.';';
                  }
               }
            }
         }
      }
      else
      {
         if (substr(trim($sql),-1) == ',')
         {
            $sql = substr(trim($sql),0,-1);
         }

         $exportType = $_REQUEST['export_csv_type'];
         if (array_key_exists($exportType,$this->predefined_queries))
         {
            $sqls[] = str_replace("<!--INPUT-->",$sql,$this->predefined_queries[$exportType]);
         }
         else
         {
            die("SELECT AN EXPORT TYPE");
         }
      }

      $isWritableQuery = false;
      $queryType       = array();
      foreach ($sqls as $sql)
      {
         if ((stristr($sql,'create') !== false || stristr($sql,'truncate') !== false || stristr($sql,'alter') !== false  || stristr($sql,'insert') !== false || stristr($sql,'update') !== false || stristr($sql,'delete') !== false || stristr($sql,'drop') !== false || stristr($sql,'call') !== false || stristr($sql,'set') !== false))
         {
            // -- if update/add use main server
            $isWritableQuery = true;
            $queryType[$sql] = 'write';
         }
         else
         {
            // -- read off of slave
            $isWritableQuery = false;
            $queryType[$sql] = 'read';
         }
      }
      // -- uncomment for all queries to master server
      if ($isWritableQuery || $this->sendToMaster || empty($this->server['mysql']['use_slave']))
      {
         $server = $this->server['mysql']['server'];
      }
      else
      {
         $server = $this->server['mysql']['use_slave'];
      }

      $this->server['mysql']['connection'] = mysql_connect($server,$this->server['mysql']['username'],$this->server['mysql']['password']);
      $this->getDeploymentRegions();

      // -- large group concat support configuration
      $this->current_query = 'SET group_concat_max_len=4294967295';
      $results = $this->runGenericQuery();
      $this->current_query = 'SET max_allowed_packet=1073741824';
      $results = $this->runGenericQuery();

      $hasShownAlert = false;
      $countAllDatabases = count($this->current_databases);
      $output = $this->startDefaultHeader()."</head><body>";

      foreach ($sqls as $sql)
      {
         if (file_exists($this->wd."archive/".md5($sql)."_all.csv"))
         {
            unlink($this->wd."archive/".md5($sql)."_all.csv");
         }
         system("rm ".$this->wd."archive/".md5($sql)."*");
      }

      foreach($this->current_databases as $k=>$region)
      {
         $currentDatabaseCount++;
         $output .= "<table border='1'><tr>";

         $htmlFile = md5(implode('',$sqls));
         $this->DeleteFile($this->wd."svndeploy/$htmlFile.html");
         foreach ($sqls as $sql)
         {
            $allCnts = 0;
            $db = $this->maintenance_database;
            $this->current_query = $sql;
            $this->current_database = $region;
            $results = $this->runGenericQuery();
            $numrows = mysql_numrows($results);
            $isCountQuery = false;
            if ($numrows == 1 && stristr($sql,'count(') && !stristr($sql,'group') && !stristr($sql,'by'))
            {
               $isCountQuery = true;
               // -- running a select count for totals use value of row
               $row = mysql_fetch_array($results);
               $numrows = $row[0];
               mysql_data_seek($results,0);
            }
            $this->balanceDiskLoad();
            if ($numrows)
            {
               $counts[$region] = $numrows;
               $numRowsHTML = "(#$numrows rows)";
            }
            else
            {
               if ($this->num_affected > 0)
               {
                  $this->FileLogger("Num affected:".$this->num_affected);
                  $counts[$region] = $this->num_affected;
                  $numRowsHTML = $this->affected_html;
               }
               else
               {
                  $counts[$region] = 0;
                  $numRowsHTML = "";
               }
            }

            if($currentDatabaseCount == 1 && $queryType[$sql] == 'write' && $combineResultsFlag)
            {
               file_put_contents($this->wd."archive/".md5($sql)."_all.csv","SQL:,".str_replace(array("\n","\r",","),array('','','`'),$sql).",\r\nDatabase,Rows Updated\r\n",FILE_APPEND);
            }



            if ($_REQUEST['region_name'])
            {
               $anchor = $_REQUEST['region_name'];
            }

            if (!array_key_exists('hide_sql',$_REQUEST))
            {
               $sqlDisplay = "<br/><span style='font-size:10px'><strong>[$this->time_of_execution Seconds]</strong><pre style='overflow: auto;width: 400px;height:200px;'>$sql</pre></span><br/>";
            }

            $cnt=0;
            $numrowscheck = $numrows;
            if ($hideData == 0 || $exportCSVFlag == 1)
            {
               while ($row = mysql_fetch_assoc($results))
               {
                  if ($cnt == 0)
                  {
                     $headerIteration[$sql]++;
                     $output .= ($hideData == 0)  ? "<td><h1>$numRowsHTML$sqlDisplay</h1><table border='1'>" : "";
                     $numrowscheck = $numrows;
                     $header = array_keys($row);
                     if ($exportCSVFlag == 1)
                     {
                        if ($combineResultsFlag && $headerIteration[$sql] == 1)
                        {
                           file_put_contents($this->wd."archive/".md5($sql)."_all.csv",implode(",",array_merge(array('Database'),$header)).",\r\n",FILE_APPEND);
                        }
                        if ($separateData)
                        {
                           file_put_contents($this->wd."archive/".md5($sql)."_".$region.".csv",implode(",",$header).",\r\n",FILE_APPEND);
                        }
                     }
                     $output .= ($hideData == 0)  ? "<th>".implode("</th><th>",$header)."</th>" : "";
                  }



                  if ($exportCSVFlag == 1)
                  {
                     if ($separateData)
                     {
                        file_put_contents($this->wd."archive/".md5($sql)."_".$region.".csv",implode(
                        ",",
                              str_replace(
                              array(",","\t","\n","\r"),
                              array(" "," ","",""),
                              $row)
                        ).",\r\n",FILE_APPEND);
                     }
                     if ($combineResultsFlag)
                     {
                        file_put_contents($this->wd."archive/".md5($sql)."_all.csv",implode(
                        ",",
                              str_replace(
                              array(",","\t","\n","\r"),
                              array(" "," ","",""),
                              array_merge(array($region),$row))
                        ).",\r\n",FILE_APPEND);
                     }
                  }



                  if (is_array($_REQUEST['lookup_mapping_columns']))
                  {
                     foreach ($row as $k=>$v)
                     {
                        foreach($_REQUEST['lookup_mapping_columns'] as $colName=>$whereColumn)
                        {
                           if ($colName == $k)
                           {
                              if (stristr($v,','))
                              {
                                 $values = explode(',',$v);
                                 $whereValue = " IN ('".implode("','",$values)."')";
                              }
                              else
                              {
                                 $whereValue = "= '$v'";
                              }
                              $row[$k] = str_replace(array('<!--NAME-->','<!--SQL-->'),array('<span style="color:green;">'.$v.'</span>',"SELECT * FROM {$_REQUEST['lookup_mapping_table'][$colName]} WHERE $whereColumn $whereValue"),$this->query_link_template);
                           }
                        }
                     }
                  }

                  $preStart = '';
                  $preEnd = '';

                  if ($_REQUEST['use_pre'])
                  {
                     $preStart = '<pre style="overflow:auto;width:500px;">';
                     $preEnd   = '</pre>';
                     foreach ($row as $kRow=>$vRow)
                     {
                        $row[$kRow] = htmlentities($vRow);
                     }
                  }
                  $output .= ($hideData == 0)  ? "<tr><td valign=\"top\" style=\"max-width:200px;word-wrap:break-word;\">$preStart".implode("$preEnd</td><td style=\"max-width:200px;word-wrap:break-word;\">$preStart",$row)."$preEnd</td>$specialShit</tr>" : "";
                  $cnt++;
               }


               if ($this->num_affected > 0 && $combineResultsFlag)
               {
                  file_put_contents($this->wd."archive/".md5($sql)."_all.csv","$region,$this->num_affected,\r\n",FILE_APPEND);
               }
            }

            if ($exportCSVFlag == 1 && $cnt !== 0)
            {
               if ($isCountQuery && $numrows == '0')
               {
                  continue;
               }
               $allHashes[] = md5($sql);

               if ($_REQUEST['region_name'])
               {
                  $datbaseName = $_REQUEST['region_name'];
               }
               else
               {
                  $datbaseName = $this->current_database;
               }

               if ($separateData)
               {
                  $tmp = "";
                  if ($hideData == 1)
                  {
                     $tmp .= "<h1>$datbaseName Records Exported (#$cnt Rows Total in <strong>[$this->time_of_execution Seconds]</strong>)</h1>";
                  }
                  $tmp .= "<a href=\"deploymentMaintenanceDownload.php?folder=archive/&f=".md5($sql)."_".$datbaseName.".csv\"><img style=\"width:80px;height:80px;\" src=\"images/csv.png\"/><br/>Download $datbaseName Results</a>";

                  if ($hideData == 0)
                  {
                     $tmp .="</td></table>";
                  }
                  else
                  {
                     $tmp .="<br/><br/>";
                  }

                  $outputCSVHTML .= $tmp;
                  $output .= $tmp;
               }
            }
            else if ($exportCSVFlag == 1 && $cnt === 0)
            {
               $outputCSVHTML .= "</table></td>";
               $output .=  "</table></td>";
            }
            else
            {
               $output .= "</table></td>";
            }
            foreach ($counts as $region=>$count)
            {
               if ($count != $numrowscheck && $hasShownAlert==false && $_REQUEST['show_count_difference'])
               {
                  if ($hideData == 1)
                  {
                     $countsOutput .= "<script>alert(\"WARNING: $region has $count when it should be $numrowscheck for $sql\")</script>";
                  }
                  else
                  {
                     $output .= "<script>alert(\"WARNING: $region has $count when it should be $numrowscheck for $sql\")</script>";
                  }
                  //echo "<script>alert(\"DIFFERENCE $sql $region -$count- -$numrowscheck-\")</script>";
                  $hasShownAlert = true;
               }
            }

            if ($currentDatabaseCount == $countAllDatabases)
            {
               foreach ($counts as $cnt)
               {
                  $allCnts += $cnt;
               }

               if (!array_key_exists('hide_sql',$_REQUEST))
               {
                  $mainSQL = "<br/>$sql";
               }
               foreach($this->current_databases as $k=>$region)
               {
                  if (empty($region))
                  {
                     continue;
                  }
                  if (!isset($regionsAll[$region]))
                  {
                     $regionsAll[$region] = $region;
                  }
               }

               if (!$saveLinkShown)
               {
                  $countsOutput .= "<h3 style='color:yellow;'><a href=\"javascript: var comments = window.prompt('Any comments for this query?',''); if (comments != null) { var defaultTxt = comments; } else {var defaultTxt = 'Name';} var name = window.prompt('Enter A File Name.',defaultTxt); document.location = '?customQuery=1&saveHTML=$htmlFile.html&save_to=' + name + '&comments=' + comments;\">(Save To HTML)</a></h3>";
                  $saveLinkShown = true;
               }

               $countsOutput .= "<h1>Counts For All</h1>";
               $countsOutput .= "<pre>$mainSQL</pre><table border='1'>";
               if ($combineResultsFlag)
               {
                  $countsOutput .= "<th>Download</th>";
               }
               $countsOutput  .= "<th>Total Counts</th><th>".implode("</th><th>",$regionsAll)."</th>";
               $countsOutput .= "<tr><!--ALL_DB".md5($sql)."--><td>$allCnts</td><td>".implode("</td><td>",$counts)."</td></tr>";
               $countsOutput .= "</table><br/>";
            }
         }

         if ( $numrows > 0 || sizeof($sqls) > 1)
         {
            $output .= "</tr></table>";
         }
      }
      $output .= "</tr></table>";
      // -- log a row to the master server for what you did to the database if you have the tracking tables up
      foreach ($sqls as $sql)
      {
         if ($combineResultsFlag)
         {
            $qq++;

            if (file_exists($this->wd."archive/".md5($sql)."_all.csv"))
            {
               if ($queryType[$sql] == 'write')
               {
                  $msgDownload = 'View Affected';
               }
               else
               {
                  $msgDownload = 'Download All Results';
               }
               $downloadHtml = "<td><a href=\"deploymentMaintenanceDownload.php?folder=archive/&f=".md5($sql)."_all.csv\"><img style=\"width:40px;height:40px;\" src=\"images/csv.png\"/><br/>$msgDownload</a></td>";
            }
            else
            {
               $downloadHtml = "<td>No File</td>";
            }
            $countsOutput = str_replace(array("<!--ALL_DB".md5($sql)."-->"),array($downloadHtml),$countsOutput);
         }
         $db = $this->maintenance_database;
         if ($db != "")
         {

         }
         $this->server['mysql']['connection'] = mysql_connect($this->server['mysql']['server'],$this->server['mysql']['username'],$this->server['mysql']['password']);
         if (mysql_select_db($db,$this->server['mysql']['connection']) && !isset($_REQUEST['is_cron']))
         {
            if ($_GET['deploy'])
            {
               // -- posting via get
               $url = "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
            }
            if ($_POST['addZeroCount'] == 'on')
            {
               $cron = $_POST['count_type_id'];
            }
            elseif($_POST['addThresholdCount'] == 'on')
            {
               $cron = $_POST['count_type_id'];
            }
            else
            {
               $cron = 0;
            }
            $this->executeUpdate("insert into ".$this->maintenance_database.".".$this->table_names['clients_updates']." (deployment_results,deployment_type,deployment_message,deployment_files,deployment_username,deployment_cron_count_check) VALUES ('".mysql_real_escape_string($sql)."','QUERY_IN_{$_REQUEST['deploy']}','".mysql_real_escape_string($_REQUEST['deploy_message'])."','".mysql_real_escape_string($url)."','".mysql_real_escape_string($_SESSION['username'])."','$cron')");
         }
      }

      if ($this->all_errored_queries)
      {
         foreach($this->all_errored_queries as $html)
         {
            $errors .= $html;
         }
      }

      if ($_REQUEST['hide_counts'] == 1)
      {
         $countsOutput = "";
      }
      $countsAndErrors = $errors.str_replace("customQuery=1&saveHTML","hashes=".implode(',',$allHashes)."&customQuery=1&saveHTML",$countsOutput);
      $output = $countsAndErrors.$output;
      $outputCSVHTML = $countsAndErrors.$outputCSVHTML;

      $svnDeployFile = "
      <style>
      .pullquote {
          width: 500px;
          color: SlateGrey;
          margin: 5px;
          font-family: Georgia, \"Times New Roman\", Times, serif;
          font-style: italic;
      }

      .pullquote:before {
          content: '\"';
          font-size: xx-large;
          font-weight: bold;
      }

      .pullquote:after {
          content: '\"';
          font-size: xx-large;
          font-weight: bold;
      }
      </style>
      <h4 style='color:red;'>This query report was extracted by ".$_SESSION['username']." on ".date("m/d/Y H:i:s")." from the <strong>".$this->deployment_display."</strong> region (the data is a stale and out-dated and is just a static web page)</h4>
      <!--COMMENT_AREA-->
      ";
      /*file_put_contents($this->wd."svndeploy/$htmlFile.html",$svnDeployFile,FILE_APPEND);
      file_put_contents($this->wd."svndeploy/$htmlFile.html",$countsOutput,FILE_APPEND);*/

      if ($hideData == 0)
      {
         file_put_contents($this->wd."svndeploy/$htmlFile.html",str_replace(array("images/csv.png","deploymentMaintenanceDownload.php","folder=archive"),array("../images/csv.png","../deploymentMaintenanceDownload.php","folder=svndeploy"),$svnDeployFile.$output)."</body></html>",FILE_APPEND);
         return array($allCnts,$counts,$output);
      }
      else
      {
         file_put_contents($this->wd."svndeploy/$htmlFile.html",str_replace(array("images/csv.png","deploymentMaintenanceDownload.php","folder=archive"),array("../images/csv.png","../deploymentMaintenanceDownload.php","folder=svndeploy"),$svnDeployFile.$outputCSVHTML)."</body></html>",FILE_APPEND);
         return array($allCnts,$counts,$outputCSVHTML);
      }
   }

   function startDefaultHeader ()
   {

      return '
      <?xml version="1.0" encoding="UTF-8"?>
         <!DOCTYPE html
            PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
            "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
         <html xmlns="http://www.w3.org/1999/xhtml"
            xml:lang="en" lang="en">
         <head>
         <link rel="stylesheet" href="css/style.css" />
         ';
   }

   function GetProjectIdFromValue($value)
   {
      $allProjectMatches = array();
      foreach ($this->project_preg_matches as $preg)
      {
         preg_match_all($preg,$value,$matches );
         if ($matches)
         {
            $allProjectMatches += $matches;
         }
      }
      return $allProjectMatches[0][0];
   }

   /*
   * @method void runDeploymentMaintenanceFile() runDeploymentMaintenanceFile() main
   */
   function runDeploymentMaintenanceFile($file,$mysqlServer='',$mysqlUserName='',$mysqlPassword='',$debugMode=false,$svnRepo='')
   {
      $this->short_log = array();
      $this->all_errored_queries = array();
      $this->all_errors = array();
      $this->descriptive_log = array();

      $this->all_skips = 0;
      $this->all_successes = 0;

      $this->current_db   = "";

      if ($debugMode===false)
      {
         $debugMode = 0;
      }
      else
      {
         $debugMode = 1;
      }
      $this->debug_mode = $debugMode;

      if ($mysqlServer && $mysqlUserName && $mysqlPassword)
      {
         $this->server['mysql']['server']     = $mysqlServer;
         $this->server['mysql']['username']   = $mysqlUserName;
         $this->server['mysql']['password']   = $mysqlPassword;
         $this->server['mysql']['connection'] = null;
      }

      // -- make connections to all dbtypes (mysql is the only one i tested)
      foreach ($this->server as $type=>$vals)
      {
         switch ($type)
         {
            case 'mysql' :
               $this->server['mysql']['connection'] = mysql_connect($vals['server'],$vals['username'],$vals['password']);
               if (!$this->server['mysql']['connection'])
               {
                  die('Cannot connect to mysql:'.$vals['server']);
               }
               break;
         }
      }

      if (!$this->client_paths)
      {
         $this->getDeploymentRegions();
      }

      $this->project_id =  $this->GetProjectIdFromValue($_REQUEST['project_name_hidden']);

      $this->wd = dirname(__FILE__)."/";

      $_REQUEST['svnrev'] = trim(str_replace('Completed: At revision: ','',$_REQUEST['svnrev']));
      if (stristr($_REQUEST['svnrev'],","))
      {
         $revs = explode(",",$_REQUEST['svnrev']);
         $shellScript = "mutliple_".date("m_d_Y");
      }
      elseif (stristr($_REQUEST['svnrev'],"-"))
      {
         $revs2 = explode("-",$_REQUEST['svnrev']);
         for ($i=$revs2[0];$i<=$revs2[1];$i++)
         {
            $revs[] = $i;
         }
         $shellScript = "mutliple_".date("m_d_Y");
      }
      else
      {
         $revs[] = $_REQUEST['svnrev'];
         $shellScript = $_REQUEST['svnrev'];
      }
      $this->release_hash      = substr(md5(date('mdYhis').strtolower($shellScript)),10);


      if ($file)
      {
         $this->deployment_file         = $file;
         $msg = $this->CustomExitHandler();
         if ($msg !== true)
         {
            die($msg);
         }
         $this->deployment_lock_file    = $this->deployment_file.".web.queries.lck";

         if (stristr($this->deployment_file,".web.queries.lck") !== false)
         {
            echo "<strong style='color:red;'>Query file $this->deployment_file has a lock!  Is currently running a process on it.</strong>";
            exit;
         }

         if (file_exists($this->deployment_lock_file))
         {
            echo "<strong style='color:red;'>Query file $this->deployment_file has a lock!  Is currently running a process on it.</strong>";
            exit;
         }
         if (!$handle = fopen($this->deployment_lock_file, 'w'))
         {
               echo "Cannot open file ($this->deployment_lock_file)";
         }
         if (fwrite($handle, "\r\n\r\n") === FALSE)
         {
               echo "Cannot write to file ($this->deployment_lock_file)";
         }
         fclose($handle);

         if (!file_exists($this->deployment_file))
         {
            $this->DeploymentNotify($this->web_notify['developers'],"No file exists for {$this->deployment_file}, but was passed to DeploymentMaintenance();");
         }

         $this->descriptive_log[] = "Begining processing for file \"{$this->deployment_file}\"".$this->ln().$this->ln();
         $this->short_log[] = "<tr><td><i>server:</i></td><td> \"{$this->server['mysql']['server']}\"</td></tr>";
         $this->short_log[] = "<tr><td><i>input file:</i></td><td> \"{$this->deployment_file}\"</td></tr>";

         // -- database file parsing

         // -- fetch file to string
         $deploymentFileContents = file_get_contents($this->deployment_file);
         // -- split into logical parts of queries delimited by ;; or declarations delimited by a \n#
         $sqlParts = preg_split('/\n[\s]+\;\;|\n#/',$deploymentFileContents, -1, PREG_SPLIT_OFFSET_CAPTURE);

         if (is_array($sqlParts))
         {

            // -- in this foreach block I am putting the pounds that were exploded out back in
            foreach ($sqlParts as $lineId=>$lineInformation)
            {
               $byte = $lineInformation[1] - 1;
               if ($deploymentFileContents[$byte] == '#')
               {
                  // -- put back the keyword and pound for later detection and possible queries not separated by ;;
                  $sqlParts[$lineId][0] = '#'.$sqlParts[$lineId][0];
               }
            }

            foreach ($sqlParts as $lineId=>$lineInformation)
            {

               // -- continue through the rest of the file and break out
               if ($this->skip_all === true)
               {
                  break;
               }

               // -- eachLine output of DatabaseProcessLine will usually contain the same data passsed
               // -- but sometimes if a declaration and a query is NOT delimited by a ;;, this function will split it into two
               $eachLine = $this->DatabaseProcessLine($lineInformation[0]);
               foreach ($eachLine as $line)
               {

                  // -- DatabaseDecipherAction will log a bunch of things and determine the main thing to do
                  $this->DatabaseDecipherAction($line);
                  switch ($this->processing_action)
                  {
                     case 'start_transaction':
                        $this->beginTransaction();
                        break;
                     case 'end_transaction':
                        $this->performCommit();
                        break;
                     case 'rollback_transaction':
                        $this->performRollback();
                        break;
                     case 'execute_query':
                        if (!empty($this->current_databases))
                        {
                           foreach($this->current_databases as $region)
                           {
                              $this->FileLogger("Running Query in $region $this->current_query");
                              $this->current_database = $region;
                              $this->runGenericQuery();
                           }
                        }
                        else
                        {
                           $this->descriptive_log[] = "You never stated which databases (in CSV) to use with the #DATABASES= declaration at the top of each DB2 queries.  Exiting remaining file operations.".$this->ln().$this->ln();
                           $this->skip_all = true;
                           break;
                        }
                        break;
                     case 'skip':

                        break;
                  }
               }
            }


            // -- just commit or rollback if developer forgot the last #TRANS-END
            if ($this->ended_transaction[$this->transaction_number] === false && $this->in_transaction_error[$this->transaction_number] === true)
            {
               $this->descriptive_log[] = $this->ln()."Warning: You forgot your #TRANS-END declaration (AUTO-ROLLBACK because of errors)".$this->ln().$this->ln();
               $this->performRollback();
            }
            elseif ($this->ended_transaction[$this->transaction_number] === false && $this->in_transaction_error[$this->transaction_number] === false)
            {
                $this->descriptive_log[] = $this->ln()."Warning: You forgot your #TRANS-END declaration (AUTO-COMMITTING)".$this->ln().$this->ln();
               $this->performCommit();
            }
         }

         // -- process email data for DeploymentMaintenance
         foreach ($this->descriptive_log as $log)
         {
            $emailBody2 .= $log;
         }

         foreach ($this->short_log as $log)
         {
            $emailBody .= $log;
         }

         $filename = str_replace("inbound","archive",$this->deployment_file.".details.html");
         if (!$handle = fopen($filename, 'w'))
         {
               echo "Cannot open file ($filename)";
         }
         if (fwrite($handle, $emailBody2) === FALSE)
         {
               echo "Cannot write to file ($filename)";
         }

         fclose($handle);

         if ($this->all_skips > 0)
         {
            $emailBody = "<tr><td><i>skips:</i></td><td>".$this->all_skips."</td><br/></tr>".$emailBody;
         }
         if ($this->all_successes > 0)
         {
            $emailBody = "<tr><td><i>success:</i></td><td>".$this->all_successes."</td></tr>".$emailBody;
         }

         if (!empty($this->all_errored_queries))
         {
            // -- cat a file to archive for attachment in email when there are errors
            $errorsFile =  "\r\n#EMAILS=".implode(",",$this->emails)."\r\n";
            $errorsFile .=  "#DATABASES=".implode(",",$this->current_databases)."\r\n";

            $keys = array_keys($this->all_errored_queries);
            foreach ($keys as $db_type)
            {
               $errorsFile .=  "\r\n\r\n#DB=$db_type\r\n\r\n";
               foreach ($this->all_errored_queries[$db_type] as $hash =>$query)
               {
                  $errorsFile .= "\r\n\r\n#BELOW QUERY ERRORED OUT IN THE FOLLOWING REGIONS\r\n\r\n#DATABASES=".implode(",",$this->all_errored_queries_regions[$db_type][$hash])."\r\n\r\n";
                  $errorsFile .= "\r\n\r\n".$query."\r\n\r\n;;\r\n\r\n";
               }
            }
            $fails = sizeof($this->all_errors);
            $emailBody = "<h1>SQL Deployment</h1><table><tr><td><i>fails:</i></td><td>".$fails."</td></tr>".$emailBody;
            if ($fails > 0)
            {
               $emailBody .= "<script>alert('Warning!  There were $fails failures total on all the SQL statements and databases you attempted to run using deploymentMaintenance.');</script>";
            }
            $filename = str_replace("inbound","archive",$this->deployment_file.".errors.sql");
            if (!$handle = fopen($filename, 'w'))
            {
                  echo "Cannot open file ($filename)";
            }
            if (fwrite($handle, $errorsFile) === FALSE)
            {
                  echo "Cannot write to file ($filename)";
            }
            fclose($handle);
         }
         else
         {
            $emailBody = "<h1>SQL Deployment</h1><table>".$emailBody;
         }
      }

      // -- deploy svn file
      $emailBodyOriginal = $emailBody;

      if (file_exists('files'.$_SESSION['username'].'.svn') && !empty($_REQUEST['svnfiles']))
      {
         if (!is_dir($this->wd.'svndeploy/'))
         {
            if (!mkdir($this->wd.'svndeploy/',0777,true))
            {
               $emailBody .= "<div style='color:red'>\"Dir creation failed for \"svndeploy\" folder</div><br/>";
            }
         }

         if (!is_dir($this->wd.'svndeploy/'.$deployLocation))
         {
            if (!mkdir($this->wd.'svndeploy/'.$deployLocation,0777,true))
            {
               $emailBody .= "<div style='color:red'>\"Dir creation failed for \"svndeploy".$deployLocation."\" folder</div><br/>";
            }
         }
         if (!is_dir($this->wd.'peer_review/'.$deployLocation))
         {
            if (!mkdir($this->wd.'peer_review/'.$deployLocation,0777,true))
            {
               $emailBody .= "<div style='color:red'>\"Dir creation failed for \"svndeploy".$deployLocation."\" folder</div><br/>";
            }
         }
         $deploymentFiles = explode("\n",$_REQUEST['svnfiles']);
         $filesModified = file('files'.$_SESSION['username'].'.svn');
         $filesModifiedAll = file_get_contents('files'.$_SESSION['username'].'.svn');

         if (stristr($_REQUEST['svnfiles'],'No revisions found') || stristr($filesModifiedAll,'No revisions found'))
         {
            die('There are no revisions found.  Please check your branch/repo contains the revisions you wanted.');
         }

         if (!empty($_REQUEST['svnrev']))
         {
            foreach ($deploymentFiles as $k=>$v)
            {
               if (stristr($v, "======================="))
               {
                  break;
               }
               $deploymentFilesnew[$k] = trim($v);
            }
            $deploymentFiles = $deploymentFilesnew;
         }
         $_REQUEST['deploy'] = trim($_REQUEST['deploy']);
         $emailBody .= "<h1>SVN Deployment</h1>";

         $this->DeleteFile($this->wd."svndeploy/{$this->release_hash}_diff.out");
         $this->DeleteFile($this->wd."svndeploy/{$this->release_hash}_diff.html");
         $failedToParsePHP          = false;
         $isAllowableBranchToRegion = true;

         $this->project_id =  $this->GetProjectIdFromValue($_REQUEST['project_name_hidden']);

         $functionsModified = array();
         $functionsAdded    = array();
         $pagesAdded        = array();

         if (is_array($filesModified))
         {
            $revcnt = 0;
            $trunkpath = $this->deployment_svn_region;
            $threshold=0;
            foreach ($revs as $deployLocation)
            {
               foreach ($filesModified as $fileSvn)
               {
                  $fileOkay = false;
                  $fileSvn = trim($fileSvn);
                  if (stristr($fileSvn, ".") && (stristr($fileSvn,"M /") || stristr($fileSvn,"A /") || stristr($fileSvn,"D /") || stristr($fileSvn,"R /")))
                  {
                     $newLocation = trim(str_replace(array("M /$trunkpath/","A /$trunkpath/","D /$trunkpath/","R /$trunkpath/"),array("","","",""),$fileSvn));
                     if (substr($fileSvn,0,1) == 'D')
                     {
                        $type = 'D';
                     }
                     else
                     {
                        $type = 'M';
                     }
                     //echo "match $fileSvn<br/>";
                     if (substr($newLocation,0,1) == '/')
                     {
                        $newLocationSlash=$newLocation;
                     }
                     else
                     {
                        $newLocationSlash='/'.$newLocation;
                     }
                  }
                  else
                  {
                     //echo "no match $fileSvn<br/>";
                     continue;
                  }

                  if (stristr($newLocation,"(from /"))
                  {
                     // -- a merged file with a different message
                     $newLocation = str_replace(array("R ","//","trunk/"),array("","",""),trim(substr($newLocation,0,strpos($newLocation,"(from /"))));
                     $newLocationSlash = '/'.$newLocation;
                  }
                  $tmpNewLocation = $newLocation;

                  $url = $svnRepo."$trunkpath/".$newLocation;
                  foreach ($deploymentFiles as $deployFile)
                  {
                     if (stristr($deployFile,"(from /"))
                     {
                        // -- a merged file with a different message
                        $deployFile = str_replace(array("R ","//","trunk/"),array("","",""),trim(substr($deployFile,0,strpos($deployFile,"(from /"))));
                     }

                     if (trim($tmpNewLocation) == trim($deployFile))
                     {
                        $fileOkay = true;
                        break;
                     }
                  }

                  if (isset($filesDeployed[$newLocation]))
                  {
                     $fileOkay = false;
                  }

                  if ($fileOkay===true)
                  {
                     $totalFiles ++;
                     $filesDeployed[$newLocation] = $newLocation;
                     if (dirname($newLocation) != '.')
                     {
                        $dirOfLocation = dirname($newLocation);
                     }
                     else
                     {
                        $dirOfLocation = "";
                     }
                     switch ($type)
                     {
                        case 'M' :
                        case 'A' :
                        case 'R' :
                        case '' :

                           if (!is_dir($this->wd.'svndeploy/'.$deployLocation.'/'.$dirOfLocation))
                           {
                              if (!mkdir($this->wd.'svndeploy/'.$deployLocation.'/'.$dirOfLocation,0750,true))
                              {
                                 $emailBodySvn .= "<div style='color:red'>\"Dir creation failed before export to ".$this->wd.'svndeploy/'.$dirOfLocation."\"</div><br/>";
                                 continue;
                              }
                           }
                           if (!is_dir($this->wd.'peer_review/'.$deployLocation.'/'.$dirOfLocation))
                           {
                              if (!mkdir($this->wd.'peer_review/'.$deployLocation.'/'.$dirOfLocation,0750,true))
                              {
                                 $emailBodySvn .= "<div style='color:red'>\"Dir creation failed before export to ".$this->wd.'peer_review/'.$dirOfLocation."\"</div><br/>";
                                 continue;
                              }
                           }

                           $this->DeleteFile($this->wd."svndeploy/{$deployLocation}/{$newLocation}");
                           $shell = "svn --username $this->svn_user export --force '$url' '{$this->wd}svndeploy/".$deployLocation."/".$newLocation."'";
                           $this->FileLogger("GET SVN FILE PRE ($newLocation): ".__LINE__);
                           exec($shell,$outputShell);
                           $this->FileLogger("GET SVN FILE POST ($newLocation): ".__LINE__);
                           copy($this->wd."svndeploy/".$deployLocation."/".$newLocation,$this->wd."peer_review/".$deployLocation."/".$newLocation.".new");
                           if (file_exists($this->wd."svndeploy/".$deployLocation."/".$newLocation))
                           {
                              $this->FileLogger("exists ".$this->wd."svndeploy/".$deployLocation."/".$newLocation);
                              if (stristr($this->wd."svndeploy/".$deployLocation."/".$newLocation,".php") == true || stristr($this->wd."svndeploy/".$deployLocation."/".$newLocation,".class") == true )
                              {
                                 $outputShellExec = array();
                                 exec("php -l '".$this->wd."svndeploy/".$deployLocation."/".$newLocation."'",$outputShellExec);
                                 if (strpos($outputShellExec[0],"Errors parsing") !== false)
                                 {
                                    $emailBodySvn    .= $this->messageHTML("<h1 style='color:red'>\"FAILED TO PARSE PHP!!!\" {$this->wd}svndeploy/".$deployLocation."/".$newLocation."</h1>",'error');
                                    $failedToParsePHP = true;
                                 }
                              }

                              $clientLoop = 0;
                              $outputFinal .= $out[$threshold][] = "\n\n#Processing next file '$newLocationSlash'\n\n";
                              foreach ($this->client_paths as $client)
                              {
                                 $outputFinal .= $out[$threshold][] = "\n\n#Next Action\n\n";
                                 if (empty($client))
                                 {
                                    continue;
                                 }

                                 if ( $clientLoop == 0 && file_exists($client.$newLocationSlash))
                                 {

                                    $outputFinal .= $out[$threshold][] = str_replace("//","/","cp '".$client.$newLocationSlash."' '".$this->wd."svndeploy/".$deployLocation."/".$newLocation.".client' &&\n echo '[$this->release_hash] - Copying ".$client.$newLocationSlash."' >> {$this->wd}debug.log && \n");
                                    $this->peerReviewDeployLocation = $deployLocation;
                                    $this->DeleteFile($this->wd."peer_review/".$deployLocation."/".$newLocation.".production");
                                    $this->FileLogger('cp '.$client.$newLocationSlash);
                                    $this->FileLogger('cp '.$this->wd."peer_review/".$deployLocation."/".$newLocation.".production");
                                    copy($client.$newLocationSlash,$this->wd."peer_review/".$deployLocation."/".$newLocation.".production");

                                    exec("printf \"".str_pad('',121,'=')."\\n|\\n|\\n|         Diff of ".$newLocation."\\n|\\n|\\n".str_pad('',121,'=')."\\n\\n\\n\\n\\n\\n\\n\\n\\n\\n\" >> '".$this->wd."svndeploy/{$this->release_hash}_diff.out' &&  echo '<a id=\"$newLocation\"></a><h1><a href=\"javascript:scroll(0,0)\">Back To Top</a></h1><h1>$newLocation</h1><pre>' >> '".$this->wd."svndeploy/{$this->release_hash}_diff.html'");
                                    exec("diff -bwc '".$this->wd."peer_review/".$deployLocation."/".$newLocation.".new' '".$this->wd."peer_review/".$deployLocation."/".$newLocation.".production' >> '".$this->wd."svndeploy/{$this->release_hash}_diff.out' >> '".$this->wd."svndeploy/{$this->release_hash}_diff.html'");
                                    $this->appendices .= "<h3><a href=\"#$newLocation\">$newLocation</a></h3>";
                                    exec("echo '</pre></br>' >> '".$this->wd."svndeploy/{$this->release_hash}_diff.html'");
                                 }

                                 if (!is_dir($client."/".$dirOfLocation) && !isset($dirs[md5($client."/".$dirOfLocation)]))
                                 {
                                    $dirs[md5($client."/".$dirOfLocation)] = $client."/".$dirOfLocation;
                                    $outputFinal .= $out[$threshold][] = str_replace("//","/","mkdir -p '".$client."/".$dirOfLocation."' &&\n chown ".$this->chown_user." '".$client."/".$dirOfLocation."' &&\n "); //\n echo 'Creating Directory ".$client."/".$dirOfLocation."' &&
                                    //$emailBodySvn .= "<div style='color:orange'>Install process will first create \"".$client.$dirOfLocation."\" (currently does not exist in system)</div><br/>";
                                 }

                                 if (!$deployFiles[$newLocation])
                                 {
                                    $comparison .= "\"$this->svn_local_comparison_tool\" \"$this->svn_local_base_path1".str_replace("/","\\",$newLocation)."\" \"$this->svn_local_base_path2".str_replace("/","\\",$newLocation)."\"\r\n";
                                 }

                                 $deployFiles[$newLocation]       = $newLocation;
                                 $fileCode                        = file_get_contents($this->wd."svndeploy/".$deployLocation."/".$newLocation);
                                 $fileCodeLines                   = explode("\n",$fileCode);
                                 $deployFilesHashes[$newLocation] = md5($fileCode);
                                 $deployFilesLocal[$newLocation]  = $this->wd."svndeploy/".$deployLocation."/".$newLocation;

                                 if ( $clientLoop == 0 )
                                 {
                                    if (!empty($this->code_warnings))
                                    {
                                       foreach ($this->code_warnings as $codeSnippet)
                                       {
                                          if (strstr($fileCode,$codeSnippet))
                                          {
                                             $emailBodySvn .= $this->messageHTML("<div style='color:cadetBlue;font-size:18px;'>Warning: File \"".$newLocation."\" contains the string \"$codeSnippet\".  Ensure this is OKAY.</div>","message");
                                          }
                                       }
                                    }
                                 }

                                 if ($this->unstable_feature_testing['pages_functions'])
                                 {
                                    // -- process internal database information after parsing code functions
                                    if ($this->getRowCount("SELECT 1 FROM ".$this->maintenance_database.".".$this->table_names['files']." WHERE file_path = '".$newLocation."'") == 0)
                                    {
                                       if (stristr($newLocation,'.css'))
                                       {
                                          $type = 'CSS';
                                       }
                                       elseif (stristr($newLocation,'.jpg') || stristr($newLocation,'.png') || stristr($newLocation,'.gif'))
                                       {
                                          $type = 'IMAGE';
                                       }
                                       elseif (stristr($newLocation,'.js') || stristr($newLocation,'function') || stristr($newLocation,'class') )
                                       {
                                          $type = 'FUNC_DEF';
                                       }
                                       elseif ( stristr($newLocation,'ajax'))
                                       {
                                          $type = 'AJAX';
                                       }
                                       else
                                       {
                                          $type = 'PAGE';
                                       }


                                       $this->executeUpdate("INSERT INTO ".$this->maintenance_database.".".$this->table_names['files']." (file_path,file_type) VALUES('$newLocation','$type')");
                                       $file_id = mysql_insert_id();
                                       $pagesAdded[$file_id] = $file_id;
                                    }
                                    else
                                    {
                                       $obj = $this->getSQLObject("SELECT id FROM ".$this->maintenance_database.".".$this->table_names['files']." WHERE file_path = '".$newLocation."'");
                                       $file_id = $obj->id;
                                       $pagesModified[$file_id] = $file_id;
                                    }

                                    $hasFunctions = false;
                                    // -- parse any functions and update page type if we detect it has a function
                                    foreach ($this->getAllFunctionNames($fileCode) as $functionName)
                                    {
                                       $hasFunctions = true;
                                       if ($this->getRowCount("SELECT 1 FROM ".$this->maintenance_database.".".$this->table_names['functions']." WHERE function_name = '".$functionName."'") == 0)
                                       {
                                          $this->executeUpdate("INSERT INTO ".$this->maintenance_database.".".$this->table_names['functions']." (function_name,number_times_changed) VALUES('$functionName','1')");
                                          $newId = mysql_insert_id();
                                          $functionsAdded[$newId] = $newId;
                                       }
                                       else
                                       {
                                          $obj = $this->getSQLObject("SELECT function_id FROM ".$this->maintenance_database.".".$this->table_names['functions']." WHERE function_name = '".$functionName."'");
                                          $newId = $obj->function_id;
                                       }

                                       if ($this->getRowCount("SELECT 1 FROM ".$this->maintenance_database.".".$this->table_names['function_locations']." WHERE file_id = '".$file_id."' AND function_id = '$newId'") == 0)
                                       {
                                          $this->executeUpdate("INSERT INTO ".$this->maintenance_database.".".$this->table_names['function_locations']." (file_id,function_id) VALUES('$file_id','$newId')");
                                       }
                                    }

                                    // -- use diff results to determine whether function was modified
                                    $diffInfo = file_get_contents($this->wd."svndeploy/{$this->release_hash}_diff.local");
                                    $foundFunctionName = false;
                                    $functionMatch = '';

                                    $matchArray = array('(\-\-\-\s[0-9]{0,10}\,[0-9]{0,10}\s\-\-\-\-)','(\*\*\*\s[0-9]{0,10}\,[0-9]{0,10}\s\*\*\*\*)');
                                    foreach ($matchArray as $theMatch)
                                    {
                                       preg_match_all($theMatch,$diffInfo,$matches);

                                       $this->FileLogger("FUNC CODE FILE: ".$this->wd."svndeploy/".$deployLocation."/".$newLocation);
                                       if (sizeof($matches)>0 && !$foundFunctionName && !empty($$matches[0][0]))
                                       {
                                          $this->FileLogger("FUNC CODE MATCH: ".var_export($matches[0][0],true)." - ".var_export(!empty($matches[0]),true));
                                          $this->FileLogger("FUNC BEGIN: ".__LINE__);
                                          list($low,$high) = explode(',',str_replace(array('-',' ','*'),array('',''),$matches[0][0]));
                                          for($i=$low;$i>1;$i--)
                                          {
                                             $ret = $this->getAllFunctionNames($fileCodeLines[$i]);
                                             $this->FileLogger("FUNC BEGIN $ret: ".__LINE__);
                                             if (!empty($ret))
                                             {
                                                $foundFunctionName = true;
                                                $functionMatch     = $ret;
                                                break;
                                             }
                                          }
                                          if (!$foundFunctionName)
                                          {
                                             for($i=$high;$i>1;$i--)
                                             {
                                                $ret = $this->getAllFunctionNames($fileCodeLines[$i]);
                                                $this->FileLogger("FUNC BEGIN HIGH $ret: ".__LINE__);
                                                if (!empty($ret))
                                                {
                                                   $foundFunctionName = true;
                                                   $functionMatch     = $ret;
                                                   break;
                                                }
                                             }
                                          }
                                          $this->FileLogger("FUNC $theMatch END: ".__LINE__);
                                       }
                                    }
                                    $this->FileLogger("FUNC FINISH: ".__LINE__);

                                    if ($foundFunctionName)
                                    {
                                       $obj = $this->getSQLObject("SELECT function_id FROM ".$this->maintenance_database.".".$this->table_names['functions']." WHERE function_name = '".$functionMatch[0]."'");
                                       if ($obj->function_id > 0)
                                       {
                                          $this->executeUpdate("UPDATE ".$this->maintenance_database.".".$this->table_names['functions']." SET last_modified = NOW(), number_times_changed = number_times_changed + 1 WHERE function_id = '$obj->function_id'");
                                          $functionsModified[$obj->function_id] = $obj->function_id;
                                          $this->executeUpdate("INSERT INTO ".$this->maintenance_database.".".$this->table_names['function_changes']." (function_id,project_id) VALUES('$obj->function_id','$this->project_id')");
                                       }
                                    }

                                    if ($hasFunctions)
                                    {
                                       $this->executeUpdate("UPDATE ".$this->maintenance_database.".".$this->table_names['files']." SET file_type = 'FUNC_DEF' WHERE id = '$file_id'");
                                    }
                                 }

                                 if ($this->debug_mode == 0)
                                 {
                                    $outputFinal .= $out[$threshold][] = str_replace("//","/","cp '".$this->wd."svndeploy/".$deployLocation."/".$newLocation."' '".$client.$newLocationSlash."' &&\n  chown ".$this->chown_user." '".$client.$newLocationSlash."' &&  chmod 755 '".$client.$newLocationSlash."' && \n echo '[$this->release_hash] - Copying ".$client.$newLocationSlash."' >> {$this->wd}debug.log    \n echo '[$this->release_hash] - Chowning ".$client.$newLocationSlash."' >> {$this->wd}debug.log  &&\n  \n echo '[$this->release_hash] - Chmoding ".$client.$newLocationSlash."' >> {$this->wd}debug.log &&");
                                    if (file_exists($client.$newLocationSlash))
                                    {
                                       $outputFinalReverse .= "cp '".$this->wd."peer_review/".$deployLocation."/".$newLocation.".production"."' '".$client.$newLocationSlash."' &&\nchown ".$this->chown_user." '".$client.$newLocationSlash."' &&\n";
                                    }
                                 }
                                 else
                                 {
                                       $emailBodySvn .= "<div style='color:orange'>Will copy \"".dirname(__FILE__).'/'.basename($url)."\" to \"$client$newLocation\"</div><br/>";
                                 }
                                 $clientLoop++;
                              }
                              chdir("{$this->wd}peer_review/{$this->peerReviewDeployLocation}/");
                           }
                           else
                           {
                              echo "<div style='color:red'>Error getting file from SVN  - I hope this is OKAY for release - \"svn export '$url' failed!  File will not be deployed ($shell)\"</div><br/>";
                           }
                           break;
                        case 'D' :
                           foreach ($this->client_paths as $client)
                           {
                              if (empty($client))
                              {
                                 continue;
                              }
                              if (file_exists($client.$newLocation))
                              {
                                 $outputFinal .= $out[$threshold][] = str_replace("//","/","rm '".$client.$newLocation."' &&\n ");//echo 'Removing ".$client.$newLocation."' &&
                                 //$emailBodySvn .= "<div style='color:orange'>Installer will delete file: \"$client$newLocation\" because in SVN this has been deleted</div><br/>";
                              }
                           }
                           break;
                     }
                  }
                  else
                  {
                     //$emailBodySvn .= "<div style='color:orange'>Skipping (could just be a dupe in the main list) \"$client$newLocationSlash\".</div><br/>";
                  }
                  $fileSizeThreshold = 3145728;
                  // -- red hat craps out and Seg Faults when we have large file sizes of shell scripts.  3 MEG cap
                  if ($fileSizeThreshold < strlen($outputFinal) && ($fileSizeThreshold * 2) > strlen($outputFinal))
                  {
                     // -- between 3 and 6 megs
                     $threshold=1;
                  }
                  elseif(($fileSizeThreshold * 2) < strlen($outputFinal) && ($fileSizeThreshold * 3) > strlen($outputFinal))
                  {
                     // -- between 6 and 9 megs
                     $threshold=2;
                  }
                  elseif(($fileSizeThreshold * 3) < strlen($outputFinal) && ($fileSizeThreshold * 4) > strlen($outputFinal))
                  {
                     // -- between 12 and 16 megs
                     $threshold=3;
                  }
                  elseif(($fileSizeThreshold * 4) < strlen($outputFinal) && ($fileSizeThreshold * 5) > strlen($outputFinal))
                  {
                     // -- between 16 and 20 megs
                     $threshold=4;
                  }
                  elseif(($fileSizeThreshold * 5) < strlen($outputFinal) && ($fileSizeThreshold * 6) > strlen($outputFinal))
                  {
                     // -- between 20 and 24 megs
                     $threshold=5;
                  }
                  elseif(($fileSizeThreshold * 6) < strlen($outputFinal) && ($fileSizeThreshold * 7) > strlen($outputFinal))
                  {
                     // -- between 24 and 28 megs
                     $threshold=6;
                  }
                  elseif(($fileSizeThreshold * 7) < strlen($outputFinal) && ($fileSizeThreshold * 8) > strlen($outputFinal))
                  {
                     // -- between 28 and 32 megs
                     $threshold=7;
                  }
                  elseif(($fileSizeThreshold * 8) < strlen($outputFinal) && ($fileSizeThreshold * 9) > strlen($outputFinal))
                  {
                     // -- between 32 and 36 megs
                     $threshold=8;
                  }
                  elseif(($fileSizeThreshold * 9) < strlen($outputFinal) && ($fileSizeThreshold * 10) > strlen($outputFinal))
                  {
                     // -- between 36 and 40 megs
                     $threshold=9;
                  }
               }
            }

	    if ($this->tarball_peer_review)
	    {
	       @exec("tar -zcvf {$this->wd}peer_review/peer_review_{$this->peerReviewDeployLocation}.tar.gz .",$outputShell);
	    }

	    $outputFinal .= $out[$threshold][] = str_replace("//","/","echo 'SVN Install Complete!' && php {$this->wd}deploymentMaintenanceComplete.php '{$_REQUEST['deploy']}' '{$_REQUEST['svnrev']}' 'Released' && ");
            if ($this->debug_mode == 0)
            {
               $outputFinalReverse .= " echo 'SVN Reversion Complete!' && php {$this->wd}deploymentMaintenanceComplete.php '{$_REQUEST['deploy']}' '{$_REQUEST['svnrev']}' 'Reverted'";

               foreach ($out as $chunkId=>$commands)
               {
                  $outputFinal  = substr(implode('',$commands),0,-4); // last command has " && "
                  $fnTemp       = $this->wd."svndeploy/".$this->release_hash."_svn_deoploy_".$_REQUEST['deploy']."_$chunkId.sh";
                  $filenameAll .= "chmod u+x $fnTemp && sh $fnTemp && ";
                  $this->fn     = $fnTemp;
                  if (!$handle = fopen($fnTemp, 'w'))
                  {
                        echo "Cannot open file ($filename)";
                  }
                  if (fwrite($handle, $outputFinal) === FALSE)
                  {
                        echo "Cannot write to file ($filename)";
                  }
                  fclose($handle);
               }
               $filenameAll  = substr($filenameAll,0,-4);
               if ( $isAllowableBranchToRegion && ! $failedToParsePHP && !is_null($_REQUEST['auto_deploy']))
               {
                  if (!$handle = fopen($this->wd."svndeploy/auto_deploy.sh", 'w'))
                  {
                     echo "Cannot open file ($filename)";
                  }
                  if (fwrite($handle, $filenameAll) === FALSE)
                  {
                     echo "Cannot write to file ($filename)";
                  }
                  fclose($handle);
               }

               $filename2 = $this->wd."svndeploy/{$this->release_hash}_svn_revert_".strtolower($_REQUEST['deploy']).".sh";
               if (!$handle = fopen($filename2, 'w'))
               {
                  echo "Cannot open file ($filename)";
               }

               if (fwrite($handle, $outputFinalReverse) === FALSE)
               {
                     echo "Cannot write to file ($filename)";
               }
               fclose($handle);
            }

            $this->svnCompare = $this->wd."svndeploy/{$this->release_hash}_svn_deoploy_".strtolower($_REQUEST['deploy']).".bat";
            if (!$handle = fopen($this->svnCompare, 'w'))
            {
               echo "Cannot open file ($filename)";
            }
            if (fwrite($handle, $comparison) === FALSE)
            {
               echo "Cannot write to file ($filename)";
            }
            fclose($handle);
            $count = count($this->client_paths);
            $this->deploying_files         = $deployFiles;
            $this->deployment_files_hashes = $deployFilesHashes;
            $this->deployment_local_files  = $deployFilesLocal;

            if ($totalFiles)
            {
               $totals = "<tr><td>Total Files In Package:</td><td>{$totalFiles}</td></tr>";
            }

            $findRegexp =
            array(
               '@(?<![.*">])\b(?:(?:https?|ftp|file)://|[a-z]\.)[-A-Z0-9+&#/%=~_|$?!:,.]*[A-Z0-9+&#/%=~_|$]@i',
               '/\s\s+/'
            );

            $replaceRegexp =
            array(
               '<a href="\0" target="_blank">(View Attached Web Page)</a>',
               ' '
            );

            foreach ($this->project_preg_matches as $v)
            {
               $findRegexp[] = $v;
               $replaceRegexp[] = '<a href="'.$this->ticketing_system_url.'\0">\0 View Ticket</a>';
            }


            if (!empty($_REQUEST['deploy_message']))
            {
               $comment = "<tr><td>Comment:</td><td><strong style='color:grey'>\"".preg_replace( $findRegexp , $replaceRegexp , $_REQUEST['deploy_message'])."\"</strong></td></tr>";
            }

            $filesAffected = '';

            $this->FileLogger("about to get impacted blocks of code");
            foreach ($deployFiles as $file)
            {
               $otherIds = array();
               $rows2 = $this->GetImpactedBlockRows("project_id ='{$this->project_id}' AND file_name='$file' AND ignore_flag = 0");
               if (!empty($rows2))
               {
                  foreach ($rows2 as $row2)
                  {
                     $otherIds[$row2['id']] = $row2['id'];
                  }
               }
               $fileHTMLLink = (!empty($otherIds)) ? str_replace(array('<!--NAME-->','<!--SQL-->'),array($file,'SELECT block_of_code FROM '.$this->maintenance_database.'.'.$this->table_names['blocks_affected'].' WHERE id IN ('.implode(',',$otherIds).')&use_pre=1'),$this->query_link_template)  : $file;

               $filesAffected .= "<ul><li>$fileHTMLLink";
               $filesAffected .= $this->GetAffectedBlocksHTML('timestamp',$file,$this->project_id);
               $filesAffected .= "</li></ul>";
            }
            $this->FileLogger("done with impacted blocks of code");

            $emailBodySvn .= "
            <table style='width:100%;'>
               <tr>
                  <td>
                     <h3>Release Overview</h3>
                     <table>
                        <tr><td>SVN Revision:</td><td>{$_REQUEST['svnrev']}</td></tr>
                        <tr><td>SVN Repository Name:</td><td>{$trunkpath}</td></tr>
                        <tr><td>Project Name:</td><td>".preg_replace( $findRegexp , $replaceRegexp ,$_REQUEST['project_name_hidden'])."</td></tr>
                        <tr><td>Project Status:</td><td>{$_REQUEST['project_status']}</td></tr>
                        <tr><td>Deploying to:</td><td>{$_REQUEST['deploy']}</td></tr>
                        $comment
                        $totals
                        <tr><td>Deploying Files:</td><td>$filesAffected</td></tr>
                     </table>
                     exectute this command via root:<br/><div style='color:lime'>$filenameAll</div><br/><br/></div>revert command:<div style='color:red'>chmod u+x $filename2 && sh $filename2</div><br/><br/></div>compare command:<div style='color:lightblue'>chmod u+x {$filename2}diff.sh && sh {$filename2}diff.sh</div><br/><br/>Which will include ($count client paths):<br/><div style='color:green'>-".implode("<BR/>-",$this->client_paths)."</div><br/>
                  </td>
                  <td>
                     <!--FILES_INTERFACE-->
                  </td>
               </tr>
            </table>
               ";
            if (empty($deployFiles) && empty($this->deployment_file))
            {
               die("<script>alert('Error... nothing to deploy... no SVN files were pulled. No Database execution file uploaded either.  Please refresh and try again....');</script>");
            }
         }
         else
         {
            echo "Error....";
         }
      }

      $emailBodySvn .= $this->CustomFlagHandler();
      $emailBody = $emailBodyOriginal.$emailBodySvn;
      if ($this->deployment_file)
      {
         $for = '"'.basename($this->deployment_file).'"';
      }
      else
      {
         $for = $_REQUEST['deploy']." deploying SVN Rev(s):".implode(',',$revs);
      }
      // -- notify everyone
      if ($_SESSION['username'])
      {
         $user = "{$_SESSION['username']}'s ";
      }

      if (!is_null($_REQUEST['auto_deploy']))
      {
         $user = "(Auto-Deploy) ".$user;
      }
      $comment = ($_REQUEST['project_name_hidden']) ? $_REQUEST['project_name_hidden'] : $_REQUEST['deploy_message'];

      // -- for now disabling emailing people from your SQL files

      /*if (!empty($this->emails))
      {
         $this->DeploymentNotify($this->emails,"DeploymentMaintenance: {$user}Execution complete for ".$for." \"".$comment."\"",$emailBody);
      }*/

      $emailBody = "No email specified in file, sending final results to default email instead (".implode(",",$this->web_notify['developers']).").<br/><br/>".$emailBody;
      $this->FileLogger("about to notify group");
      $id = $this->DeploymentNotify($this->web_notify['developers'],"DeploymentMaintenance: {$user}Execution complete for ".$for." \"".$comment."\"",$emailBody);
      $this->FileLogger("done notifying");

      $row = $this->getSQLObject("SELECT * FROM ".$this->maintenance_database.".".$this->table_names['clients_updates']." WHERE id = '$id'");

      if ($this->unstable_feature_testing['pages_functions'])
      {
         $pagesAddedAll = array_merge($pagesAdded,$pagesModified);
         $newFilesH1Row = (sizeof($pagesAdded) > 0) ? '<tr><td colspan="2"><h3>'.(sizeof($pagesAdded)-1).' New Files/Pages Added</h3></td></tr>' : '';
         foreach ($pagesAddedAll as $fileIdRow)
         {
            $rowFile  = $this->getSQLObject("SELECT * FROM ".$this->maintenance_database.".".$this->table_names['files']." WHERE id = '$fileIdRow'");

            eval('$'.$rowFile->file_type.'_SEL = \'selected\';');

            if (array_key_exists($fileIdRow,$pagesAdded))
            {
               $pageDesc = "<strong style='color:red'>Page Added</strong>";
            }
            else
            {
               $pageDesc = "Modified Page";
            }

            $pageFeatures = '';
            $firstFeature = true;
            foreach ($this->getRecords("SELECT * FROM ".$this->maintenance_database.".".$this->table_names['page_features']." WHERE page_id = '$fileIdRow'") as $rowFeatures)
            {
               $header = ($firstFeature) ? '<h1 style="text-decoration:underline;">Page Features</h1>' : '';
               $pageFeatures .= '
               <tr id="feature_tr['.$rowFeatures['id'].']">
                  <td style="text-align:right;">'.$header.'<br /><br /><h3>'.$rowFile->file_path.': Page Feature ('.$rowFeatures['id'].')</h3></td>
                  <td>
                     <table>
                        <tr>
                           <td>
                              Feature Desc:
                           </td>
                           <td>
                              <textarea id="feature_desc['.$rowFeatures['id'].']">'.$rowFeatures['feature_description'].'</textarea>
                           </td>
                        </tr>
                        <tr>
                           <td>
                              Impacted By Release:
                           </td>
                           <td>
                              <input type="checkbox" id="feature_impacted['.$rowFeatures['id'].']">
                           </td>
                        </tr>
                        <tr>
                           <td>
                           </td>
                           <td>
                              (Edit Feature)
                           </td>
                        </tr>
                     </table>
                  </td>
               </tr>';
               $firstFeature = false;
            }

            $functionFeatures = '';
            $firstFunction = true;
            foreach ($this->getRecords("
            SELECT
               f.*
            FROM
               ".$this->maintenance_database.".".$this->table_names['function_locations']." fl,
               ".$this->maintenance_database.".".$this->table_names['functions']." f
            WHERE
               fl.function_id = f.function_id AND
               fl.file_id = '$fileIdRow'
            ORDER BY
               f.last_modified,f.function_name
            ") as $functionsInPage)
            {
               // -- if read as impacted expand else hide and be able to toggle to edit main description and impacted function
               $id             = $functionsInPage['function_id'];
               $header         = ($firstFunction) ? '<h1 style="text-decoration:underline;">Impacted Functions</h1>' : '';
               $textColor      = '';
               $breaks         = '<br /><br /><br />';
               $padding        = '';
               $functionClass  = '';
               $functionClass2 = '';
               if (array_key_exists($id,$functionsAdded))
               {
                  $addedFunction = '<strong style="color:green">+(Added New)</strong><br />';
                  $functionChk   = 'checked';
               }
               elseif(array_key_exists($id,$functionsModified))
               {
                  $addedFunction = '<strong style="color:blue">~(Edited)</strong><br />';
                  $functionChk   = 'checked';
               }
               else
               {
                  $functionChk    = '';
                  $functionClass  = 'class="hideFunction"';
                  $functionClass2 = 'class="hideFunctionArea"';
                  $addedFunction  = '<strong style="color:#EBEBEB;">(Unchanged)</strong><br />';
                  $breaks         = '';
                  $textColor      = 'style="color:lightgrey"';
                  $padding        = 'padding:0px;';
               }

               $functionFeatures .= '
               <tr id="function_tr['.$id.']">
                  <td style="text-align:right;'.$padding.'">'.$breaks.$header.'
                     <h3 '.$textColor.'>
                        '.$addedFunction.'
                        <span style="cursor:pointer;" onclick="if ($(\'function_edited['.$id.']\').checked == true){ $(\'function_edited['.$id.']\').checked = false; } else { $(\'function_edited['.$id.']\').checked = true;}">function '.$functionsInPage['function_name'].'()</span>
                        <input onchange="if (this.checked==false) { jq(\'#function_edit\['.$id.'\]\').hide();  } else { jq(\'#function_edit\['.$id.'\]\').fadeIn(\'fast\'); }" type="checkbox" '.$functionChk.' id="function_edited['.$id.']">
                     </h3>
                  </td>
                  <td '.$functionClass.' id="function_edit['.$id.']">
                     <table>
                        <tr>
                           <td>
                              Function Main Purpose:
                           </td>
                           <td>
                              <textarea id="function_desc['.$id.']">'.$functionsInPage['function_purpose'].'</textarea>
                           </td>
                        </tr>
                        <tr>
                           <td>
                              Latest Change Description:
                           </td>
                           <td>
                              <textarea id="latest_change_desc['.$id.']"></textarea>
                           </td>
                        </tr>
                        <tr>
                           <td>
                           </td>
                           <td>
                              (Edit Function Info)
                           </td>
                        </tr>
                     </table>
                  </td>
               </tr>';
               $firstFunction = false;
            }

            $newFilesWidgets .= '
            <tr>
               <td><h2>'.$pageDesc.':</h2>'.$rowFile->file_path.'</td>
               <td></td>
            </tr>
            <tr>
               <td style="max-width:150px;text-align:right;"><br /><br /><h1 style="text-decoration:underline;">Page Description</h1></td>
               <td>
                  <table>
                     <tr>
                        <td>
                           File Type:
                        </td>
                        <td>
                           <select id="file_type['.$fileIdRow.']">
                              <option value="PAGE" '.$PAGE_SEL.'>Regular Page</option>
                              <option value="IMAGE" '.$IMAGE_SEL.'>Image File</option>
                              <option value="CSS" '.$CSS_SEL.'>CSS File</option>
                              <option value="FUNC_DEF" '.$FUNC_DEF_SEL.'>Function Definition</option>
                              <option value="AJAX_DEF" '.$AJAX_SEL.'>AJAX File</option>
                           </select>
                        </td>
                     </tr>
                     <tr>
                        <td>
                           File Description:
                        </td>
                        <td>
                           <textarea id="file_desc['.$fileIdRow.']">'.$rowFile->file_description.'</textarea>
                        </td>
                     </tr>
                     <tr>
                        <td>
                        </td>
                        <td>
                           (Save Info)
                        </td>
                     </tr>
                  </table>
                  '.$pageFeatures.'
                  '.$functionFeatures.'
               </td>
            </tr>
            <tr>
               <td></td>
               <td>
                  <table>
                     <tr>
                        <td>
                           (Add New Page Feature)
                        </td>
                     </tr>
                  </table>
               </td>
            </tr>
            <tr><td colspan="2"><hr style="background-color: yellow;color: yellow;height: 5px;"></td></tr>
            ';
         }

         $filesInterface = "
         <h3>Files & Functions Maintenance</h3>
         <table>
            $newFilesH1Row
            $newFilesWidgets
         </table>
         <script>
            jq(document).ready(function(){
               jq('.hideFunction,.hideFunctionArea').hide();
            });
         </script>
         <br /><br />";

         $this->email_body = str_replace('<!--FILES_INTERFACE-->',$filesInterface,$this->email_body);
      }

      // -- make connections to all dbtypes (mysql is the only one i tested)
      foreach ($this->server as $type=>$vals)
      {
         switch ($type)
         {
            case 'mysql' :
               mysql_close($this->server['mysql']['connection']);
               break;
         }
      }
      $this->DeleteFile($this->deployment_lock_file);
      echo "Email results for $file sent to ".implode(",",$this->emails)."\n\n<br/><br/>";
   }

   function FileLogger($msg)
   {
      file_put_contents("{$this->wd}debug.log","[".date('Y-m-d H:i:s')."][$this->release_hash] - $msg\n",FILE_APPEND);
   }

   /*
    * @method void DeploymentNotify(array $emails,string $subject,string $body) DeploymentNotify()
    * @description - email people or default to web-notify
    */
   function DeploymentNotify($emails,$subject,$body,$type=null)
   {
      if (empty($this->all_errored_queries) && $this->deployment_file)
      {
         $body = "COMPLETE SUCCESS!  NO ERRORS IN ALL QUERIES!  DISREGARD.<br/><br/>".$body;
      }
      $useAttachments = false; // -- up to you...
      $this->email_to = $emails;
      if (dirname($_SERVER['SCRIPT_NAME']) == '/')
      {
         $directory = "/";
      }
      else
      {
         $directory = dirname($_SERVER['SCRIPT_NAME']).'/';
      }
      if ($useAttachments === false)
      {
         $body .= "<h3>Output Files</h3>";
         if (!empty($this->all_errored_queries))
         {
            $errorsFile = str_replace("inbound","archive",$this->deployment_file.".errors.sql");
            $body .= "<br/><br/><a href=\"http://".$_SERVER['SERVER_NAME']."{$directory}archive{$this->deployment_file}.errors.sql\">Errors Attachment</a>";
         }

         if (file_exists($this->svnCompare))
         {
            $body .= "
            <br/><br/><a href=\"http://".$_SERVER['SERVER_NAME']."{$directory}svndeploy/".basename($this->svnCompare)."\">Local SVN Compare</a>
            <ul><li>http://".$_SERVER['SERVER_NAME']."{$directory}svndeploy/".basename($this->svnCompare)."</li></ul>
            ";
         }

         if (file_exists($this->wd."svndeploy/{$this->release_hash}_diff.out") && file_get_contents($this->wd."svndeploy/{$this->release_hash}_diff.out"))
         {
            $body .= "
            <br/><br/><a href=\"http://".$_SERVER['SERVER_NAME']."{$directory}svndeploy/{$this->release_hash}_diff.out\">Comparison Diff</a>
            <ul><li>http://".$_SERVER['SERVER_NAME']."{$directory}svndeploy/{$this->release_hash}_diff.out</li></ul>
            ";
         }

         if (file_exists($this->wd."svndeploy/{$this->release_hash}_diff.html"))
         {
            file_put_contents($this->wd."svndeploy/{$this->release_hash}_diff.html","<h1><strong>Appendix</strong></h1>" . $this->appendices . file_get_contents($this->wd."svndeploy/{$this->release_hash}_diff.html"));
            $body .= "
            <br/><br/><a href=\"http://".$_SERVER['SERVER_NAME']."{$directory}svndeploy/{$this->release_hash}_diff.html\">Comparison Diff (HTML)</a>
            <ul><li>http://".$_SERVER['SERVER_NAME']."{$directory}svndeploy/{$this->release_hash}_diff.html</li></ul>
            ";
         }

         if (file_exists($this->wd.'peer_review/peer_review_'.$this->peerReviewDeployLocation.'.tar.gz') && !empty($_REQUEST['svnfiles']))
         {
            $body .= "
            <br/><br/><a href=\"http://".$_SERVER['SERVER_NAME']."{$directory}peer_review/peer_review_".$this->peerReviewDeployLocation.'.tar.gz'."\">Production Tar Ball</a>
            <ul><li>http://".$_SERVER['SERVER_NAME']."{$directory}peer_review/peer_review_".$this->peerReviewDeployLocation."tar.gz</li></ul>
            ";
         }

         $detailsFile = str_replace("inbound","archive",$this->deployment_file.".details.html");
         if (file_exists($detailsFile) && $useAttachments === true)
         {
            $body .= "<br/><br/><a href=\"http://".$_SERVER['SERVER_NAME']."{$directory}archive/".$this->deployment_file.".details.html"."\">Processing Details File</a>";
            $email->add_attachment( basename($detailsFile), $detailsFile,'text/html');
         }
      }

      $email = new EmailSender( $body );
      $email->set_from( 'deployment_maintenance@'.$_SERVER['HTTP_HOST']);
      $email->set_content_type( 'text/html' );
      $email->set_to( $emails );

      if (!empty($this->all_errored_queries) && $useAttachments === true)
      {
         $errorsFile = str_replace("inbound","archive",$this->deployment_file.".errors.sql");
         if (file_exists($errorsFile))
         {
            $email->add_attachment( basename($errorsFile), $errorsFile,'text/plain');
         }
      }

      $detailsFile = str_replace("inbound","archive",$this->deployment_file.".details.html");
      if (file_exists($detailsFile) && $useAttachments === true)
      {
         $email->add_attachment( basename($detailsFile), $detailsFile,'text/html');
      }

      if (file_exists($this->svnCompare) && $useAttachments === true)
      {
         $email->add_attachment( basename($this->svnCompare), $this->svnCompare);
      }

      if (file_exists($this->wd.'peer_review/peer_review_'.$this->peerReviewDeployLocation.'.tar.gz') && !empty($_REQUEST['svnfiles'])  && $useAttachments === true)
      {
         $email->add_attachment( basename($this->wd.'peer_review/peer_review_'.$this->peerReviewDeployLocation.'.tar.gz'), $this->wd.'peer_review/peer_review_'.$this->peerReviewDeployLocation.'.tar.gz');
      }
      if ($useAttachments === true)
      {
         $email->add_attachment( basename($this->deployment_file), $this->deployment_file,'text/plain');
      }
      $this->email_body = $body;
      $email->set_subject( $subject );

      if ($this->debug_mode == 0)
      {
         $ret = $email->sendEmail();
      }

      $this->DeleteFile($detailsFile);
      if (!empty($this->all_errored_queries))
      {
         if (file_exists($filename))
         {
            $this->DeleteFile($this->deployment_file.".errors.sql");
         }
      }
      $newId = $this->CustomDeploymentTracking($type);
      return $newId;
   }

   /*
    * @method void DatabaseProcessLine(array $lineInformation)
    * @description - split a line if not delimited and two actions are carried with one array index
    */
   function DatabaseProcessLine($lineInformation)
   {
      $lineInformation = trim($lineInformation);
      if (strpos($lineInformation,'#') !== false)
      {
         // -- sometimes developer will not delimit each command with a ;; after a #DECLARATION, so a keyword can be mixed with a query and delimited by line endings.  This will split those lines accordingly so that both records are valid still
         $beginingSQLKeywords = array('truncate ','drop ','insert ','delete ','update ','use ','alter ','create ','call ');
         foreach ($beginingSQLKeywords as $keyWord)
         {
            if (($pos = strpos(strtolower($lineInformation),$keyWord)) !== false )
            {
               return array(substr($lineInformation,0,$pos),substr($lineInformation,$pos));
            }
         }
         return array($lineInformation);
      }
      else
      {
         return array($lineInformation);
      }
   }

   /*
    * @method void DatabaseDecipherAction(string $line)
    * @description - figure out what to do
    */
   function DatabaseDecipherAction($line)
   {
      if (strtolower(substr($line,0,8)) == '#emails=')
      {

         $this->processing_action='skip';
         $this->currently_in_transaction = false;
         $emails = substr($line,8);
         if (stristr(",",$emails) !== false)
         {
            $this->emails= explode(",",$emails);
         }
         else
         {
            $this->emails= array($emails);
         }
         $log ="<tr><td><i>notify:</i></td><td>";
         foreach ($this->emails as $email)
         {
            $log .= $email.",";
         }
         $log .= "</td></tr>";
         $this->descriptive_log[] = $log;
         $this->short_log[] = $log;

      }
      elseif (strtolower(substr($line,0,8)) == '#backup=')
      {
         $this->processing_action='skip';
         $backupTables = substr($line,8);
         $this->descriptive_log[] = "Backing up $backupTables".$this->ln().$this->ln();
         if (stristr($backupTables,",") !== false)
         {
            $backups   = explode(",",$backupTables);
         }
         else
         {
            $backups[] = $backupTables;
         }
         foreach ($backups as $backup)
         {
            list($db,$table) = explode(".",$backup);
            $where = "";
            if (stristr($table,"(") !== false)
            {
               $tableTmp = $table;
               $pos = strpos($table,"(");
               $table = substr($tableTmp,0,$pos);
               $whereTmp = substr($tableTmp,$pos,strlen($tableTmp)-1);
               $where = "\"--where=$whereTmp\"";
            }
            $dbOutputFile = str_replace(array("inbound","archive"),array("database_backups","database_backups"),$this->deployment_file.".$db.$table.sql");

            if (strtolower($db)=='all')
            {
               $results = mysql_list_dbs($this->server['mysql']['connection']);
               while($row = mysql_fetch_array($results))
               {
                  $database = $row['Database'];
                  if ($database == 'information_schema' || $database == 'mysql' || in_array($database,$this->all_exceptions))
                  {
                     continue;
                  }
                  $dbArray[] = $database;
               }
            }
            else
            {
               $dbArray[] = $db;
            }
            $this->short_log[] = "<table>";
            foreach ($dbArray as $db)
            {
               $mysqlDumpCommand = "mysqldump --host={$this->server['mysql']['server']} --user={$this->server['mysql']['username']} --password={$this->server['mysql']['password']} $db $table $where > '$dbOutputFile'";
               system($mysqlDumpCommand,$output);
               if ($output == '0')
               {
                  $outputShow = "<span style='color:green'>SUCCESS - $dbOutputFile</span>";
               }
               else
               {
                  $mysqlDumpCommand = str_replace(array($this->server['mysql']['username'],$this->server['mysql']['password']),array('username-hidden','password-hidden'),$mysqlDumpCommand);
                  $outputShow = "<span style='color:red'>FAILED - COMMAND DIDNT WORK: $mysqlDumpCommand</span>";
                  $this->backupFails[$table] = $table;
               }
               $this->short_log[]       = "<tr><td><i>backup:</i></td><td> mysql : <strong>$db.$table</strong> </td><td>(<i>$outputShow</i>)<br/></td></tr>";
            }
            $this->short_log[] = "</table>";
         }
      }
      elseif (strtolower(substr($line,0,11)) == '#databases=')
      {

         $this->processing_action='skip';
         $this->currently_in_transaction = false;

         $regions = substr($line,11);
         if (!$this->client_deployment_regions)
         {
            if (stristr($regions,",") !== false && stristr($regions,"(except=") === false)
            {
               $this->current_databases= explode(",",$regions);
            }
            else
            {
               if (strtolower(substr($regions,0,3)) == 'all')
               {
                  if (strpos(strtolower($regions),'except') !== false)
                  {
                     // -- people can use #databases=all(except=database1,database2)
                     $end = strpos(substr($regions,11),")");
                     $exceptions = substr($regions,11,$end);
                     if (stristr($exceptions,",") !== false)
                     {
                        $this->all_exceptions = explode(",",$exceptions);
                     }
                     else
                     {
                        $this->all_exceptions[] = $exceptions;
                     }
                  }
                  $results = mysql_list_dbs($this->server['mysql']['connection']);
                  while($row = mysql_fetch_array($results))
                  {
                     $database = $row['Database'];
                     if ($database == 'information_schema' || $database == 'mysql' || in_array($database,$this->all_exceptions))
                     {
                        continue;
                     }
                     $this->current_databases[] = $database;
                  }
               }
               else
               {
                  $this->current_databases= array($regions);
               }
            }
         }
         $log ="<tr><td><i>databases&#160;:</i></td><td> <strong>";
         foreach ($this->current_databases as $region)
         {
            $log .= $region.",";
         }
         $log .= "</strong></td></tr></table>";
         $this->descriptive_log[] = $log;
         $this->short_log[] = $log;

      }
      elseif (strtolower(substr($line,0,4)) == '#db=')
      {
         //set database type for all of the remaining below SQL statments in the file
         $this->processing_action='skip';
         $this->currently_in_transaction = false;
         $this->current_db_type = strtolower(trim(substr($line,4)));
         $execIn = "(Exec In: ".implode(",",$this->current_databases).")";

         $this->short_log[] = $this->ln().$this->ln()."<i>db type&#160;:</i> <strong>".strtoupper($this->current_db_type)."</strong> ".$execIn.$this->ln()."----------------------------------------------".$this->ln().$this->ln();
         $this->descriptive_log[] = $this->ln()."=========================================================NEW ".strtoupper($this->current_db_type)." QUERIES=========================================================".$this->ln()."Switching the database dbType as \"{$this->current_db_type}\"".$this->ln().$this->ln();
         // -- validate db_type
         switch ($this->current_db_type)
         {
            case 'mysql':
               break;
            case 'db2':
               break;
            default:
               // -- exit out of processing
               $this->descriptive_log[] = "Currently only MYSQL and DB2 (Not \"$this->current_db_type\") are supported for this process.  Additional types will be supported later".$this->ln().$this->ln();
               $this->skip_all = true;
               break;

         }
      }
      elseif (strtolower(substr($line,0,12)) == '#trans-start')
      {

         if ($this->ended_transaction[$this->transaction_number] === false)
         {
            $this->descriptive_log[] = "This file is trying to start another TRANSACTION without ending the first.  Rolling back what has been added in this transaction and ending processing of this file.".$this->ln().$this->ln();
            $this->skip_all=true;
            $this->performRollback();
            $this->processing_action='skip';
         }
         else
         {
            $this->processing_action='start_transaction';
            $this->currently_in_transaction = true;
            $this->transaction_number++;
            $this->started_transaction[$this->transaction_number] = true;
            $this->ended_transaction[$this->transaction_number] = false;
            $this->descriptive_log[] = "Detected Start of Transaction<br/><br/>";
            $this->short_log[] = "(Execute Below Queries In Transaction Block)<br/>".$this->ln();
         }

      }
      elseif (strtolower(substr($line,0,10)) == '#trans-end')
      {

         if ($this->in_transaction_error[$this->transaction_number] == true)
         {
            $this->processing_action='rollback_transaction';
         }
         else
         {
            $this->processing_action='end_transaction';
            $this->currently_in_transaction = false;
         }

      }
      elseif (trim($line) == '')
      {

         $this->processing_action='skip';

      }
      elseif ($ret = $this->isValidQuery($line))
      {

         if ($ret === true)
         {
            $this->processing_action='execute_query';
            $line = trim($line);
            $this->descriptive_log[] = $this->ln()."------------------------------------------------------------".$this->ln()."Attempting Query:".$this->ln().$this->ln()."\t\t".$line;
            $this->current_query = $line;
         }

      }
      else
      {

         $this->descriptive_log[] = "Line contains data, but skipping as is not a valid query or keyword command:".$this->ln().$this->ln()."\t\t\t".$line.$this->ln().$this->ln();
         $this->processing_action='skip';

      }
   }

   /*
    * @method boolean isValidQuery(string $line)
    * @description - based on 6 keywords of supported queries figure out if you are a query or not
    *                also skip the query if transaction is in an error
    */
   function isValidQuery($line)
   {
      $flag = false;
      if (stristr($line,'insert') !== false  || stristr($line,'drop') !== false || stristr($line,'update') !== false || stristr($line,'delete') !== false)
      {
         $flag = true;
      }

      if ($this->current_db_type == 'mysql')
      {
         if (stristr($line,'create') !== false || stristr($line,'call') !== false || stristr($line,'truncate') !== false || stristr($line,'alter') !== false || stristr($line,'use') !== false)
         {
            $flag = true;
         }
      }

      if ($flag === true)
      {
         $this->all_queries[] = $line;
      }
      if ($this->current_db_type == '' && $flag === true)
      {
         $this->descriptive_log[] = "Logical error in file:  Attempted to run a query without first specifying a db_type".$this->ln().$this->ln()."\t\t\tQUERY:$line".$this->ln().$this->ln();
         $this->short_log[] = "<strong>DeploymentMaintenance Format Error (please specify a #DB=XX)</strong>: ".$this->ln().$this->ln()."\t\t\tQUERY:$line".$this->ln().$this->ln();
      }

      if ($this->in_transaction_error[$this->transaction_number] == true && $flag === true)
      {
         $this->processing_action = 'skip';
         $this->descriptive_log[] = "Previous Query in Transaction in error. Skipping: ".$this->ln()."\t\t\tQUERY:$line".$this->ln().$this->ln();
         $this->short_log[] = "<span style=\"color:grey\"><i>skipping:</i> ".$this->shortQuery($line)."</span>".$this->ln().$this->ln();
         $this->all_skips++;
         $flag = 2;
      }

      if (is_array($this->backupFails))
      {
         foreach ($this->backupFails as $tableName)
         {
            if (stristr($line,$tableName) !== false)
            {
               $flag = 2;
               $this->processing_action = 'skip';
               $this->short_log[] = "<span style=\"color:grey\"><i>skipping:</i>backup: ".$this->shortQuery($line)." </span>(mysqldump failed on this table backup...skipping) ".$this->ln().$this->ln();
               $this->all_skips++;
            }
         }
      }

      return $flag;
   }

   /*
    * @method string ln()
    * @description - output a line ending in the email and pre-pend #TRANS-## if in a transaction block
    */
   function ln()
   {
      $out = "<br/>";
      if ($this->currently_in_transaction === true && !empty($this->transaction_number))
      {
         $out .= "<i>trans #{$this->transaction_number} :</i> ~ ";
      }
      return $out;
   }

   /*
    * @method string shortQuery()
    * @description - shorten a query... for now just show 50 bytes
    */
   function shortQuery($query)
   {
      return "<strong>".substr($query,0,50)."</strong>....";
   }

   function getRowCount($query)
   {
      return mysql_numrows(mysql_query($query));
   }

   function getRecords($query)
   {
      $results = mysql_query($query);
      $allRows = array();
      while ($row = mysql_fetch_assoc($results))
      {
         $allRows[] = $row;
      }
      return $allRows;
   }

   function getSQLObject($query)
   {
      return mysql_fetch_object(mysql_query($query));
   }

   function executeUpdate($query)
   {
      $this->current_query = $query;
      $results = $this->runGenericQuery();
      if ($this->database_error)
      {
         die("ERROR ON $query!!! <br /><br />-> ".$this->database_error."<br/><br/>");
      }
   }

   function messageHTML($message,$type='error')
   {
      if ($type=='error')
      {
         $class1 = 'ui-state-error';
         $class2 = 'ui-icon-alert';
      }
      else
      {
         $class1 = 'ui-state-focus';
         $class2 = 'ui-icon-info';
      }
      return '
      <div class="ui-widget" style="margin-top:10px;margin-bottom:10px;">
         <div class="'.$class1.' ui-corner-all" style="padding: 0 .7em; padding-top:15px; padding-bottom:15px;">
            <span class="ui-icon '.$class2.'" style="float: left; margin-right: .3em;"></span>
            '.$message.'
         </div>
      </div>';
   }

   /*
    * @method void runGenericQuery()
    * @description - run all types of queries
    */
   function runGenericQuery()
   {
      if ($this->debug_mode == 0)
      {
         $results = $this->executeQuery();
      }
      else
      {
         $this->descriptive_log[] = $this->ln()."\t\t\t\t(DEBUG MODE ON)".$this->ln();
         $this->short_log[] = "<i>debug&#160;&#160;&#160;:</i> on".$this->ln();
      }
      $this->handleQueryErrors();
      return $results;
   }

   function executeQuery()
   {
      switch ($this->current_db_type)
      {
         case 'mysql':
            if (!$this->server['mysql']['connection'])
            {
               $this->server['mysql']['connection'] = mysql_connect($this->server['mysql']['server'],$this->server['mysql']['username'],$this->server['mysql']['password']);
            }
            $this->database_error = null;
            if (!mysql_select_db($this->current_database,$this->server['mysql']['connection']))
            {
               $this->all_errors[] = "COULD NOT SELECT DB: ".$this->current_database;
            }
            else
            {

               $start = microtime();
               $results = mysql_query($this->current_query,$this->server['mysql']['connection']);
               $this->time_of_execution = number_format($this->diff_microtime($start,microtime()), 4);
               $errors  = mysql_error($this->server['mysql']['connection']);
               if ($errors)
               {
                  $this->database_error = $this->messageHTML('<strong>Query Failed on: <pre>'.$this->current_query.'</pre> with reason: <strong style=\'color:red;\'>'."ERR#".mysql_errno($this->server['mysql']['connection']) . ": " . mysql_error($this->server['mysql']['connection']).'</strong>','error');
               }
            }
            break;
         case 'db2':

            break;
      }
      return $results;
   }

   /*
    * @method void handleQueryErrors()
    */
   function handleQueryErrors()
   {
      $region = $this->current_database;
      if ($this->database_error)
      {
         $this->descriptive_log[] = $this->ln()."\t\t\t\t\t($region) (!!FAILED!!)".$this->ln()."\t\t\t\t\tReason(s): ".$this->ln()."\t\t\t\t\t".$this->ln().$this->database_error;
         $this->short_log[] = "<span style=\"color:red\"><i>failed&#160;&#160;:</i> $region : ".$this->shortQuery($this->current_query).$this->ln()."reason : $region : ".$this->database_error."</span>".$this->ln().$this->ln();
         $this->all_errors[] = "<strong>$region</strong>: ".$this->database_error;
         $this->all_errored_queries[md5($this->current_query)] = "<strong>$region</strong>: ".$this->database_error;
         $this->all_errored_queries_regions[$this->current_db_type][md5($this->current_query)][$region] = $region;
         $this->errors = null;
         if ($this->currently_in_transaction === true)
         {
            // -- skip the rest until end of transaction is found, then rollback
            $this->in_transaction_error[$this->transaction_number] = true;
         }
      }
      else
      {
         $this->all_successes++;
         $this->descriptive_log[] = $this->ln()."\t\t\t\t(".$this->current_db_type.") (SUCCESS)".$this->ln();
         $this->affected_html = "";
         $this->num_affected = 0;
         if (stripos($this->current_query,'insert') !== false || stripos($this->current_query,'drop') !== false || stripos($this->current_query,'update') !== false || stripos($this->current_query,'delete') !== false || stripos($this->current_query,'alter') !== false)
         {
            $aff = mysql_affected_rows($this->server['mysql']['connection']);
            $this->affected_html = '<span style="color:grey">('.$aff.' affected)</span>';
            $this->num_affected = $aff;
         }
         $this->short_log[] = "<span style=\"color:green;\"><strong><i>success&#160;:</strong></i> $region : ".$this->shortQuery($this->current_query)." [$this->time_of_execution seconds]</span>".$this->affected_html.$this->ln().$this->ln();
         if ($this->currently_in_transaction === true)
         {
            $this->in_transaction_error[$this->transaction_number] = false;
         }
      }
   }

   /*
    * @method void beginTransaction()
    * @description - START or BEGIN a transaction depending on DB type and log everything
    */
   function beginTransaction()
   {
      if ($this->current_db_type == 'mysql')
      {
         $start = "START";
      }
      else
      {
         $start = "BEGIN";
      }
      $this->current_query = "$start TRANSACTION";
      $this->executeQuery();
      $this->descriptive_log[] = $this->ln()."$start TRANSACTION".$this->ln();
      $this->short_log[]       = $this->ln()."$start TRANSACTION".$this->ln();
   }

   /*
    * @method void performRollback()
    * @description - ROLLBACK a transaction
    */
   function performRollback()
   {
      $this->current_query = "ROLLBACK";
      $this->executeQuery();
      $this->descriptive_log[] = $this->ln()."ROLLBACK".$this->ln();
      $this->short_log[]       = $this->ln()."ROLLBACK".$this->ln();
      $this->currently_in_transaction = false;
      $this->in_transaction_error[$this->transaction_number] = false;
      $this->ended_transaction[$this->transaction_number] = true;
   }

   /*
    * @method void performCommit()
    * @description - COMMIT a transaction
    */
   function performCommit()
   {
      $this->current_query = "COMMIT";
      $this->executeQuery();
      $this->descriptive_log[] = $this->ln()."COMMIT".$this->ln();
      $this->short_log[] = $this->ln()."COMMIT".$this->ln();
      $this->ended_transaction[$this->transaction_number] = true;
   }



   /*
    * @method string getQueries()
    */
   function getQueries()
   {
      $this->current_db_type = "mysql";
      $this->current_query   =
      "
         SELECT
            *
         FROM
            ".$this->maintenance_database.".".$this->table_names['sql']."
         WHERE
            project = '".$_GET["project"]."'
      ";
      $results = $this->runGenericQuery();
      mysql_close($this->server['mysql']['connection']);
      return $results;
   }

   /*
    * @method string addQueries()
    */
   function addQueries()
   {
      $this->current_db_type = "mysql";
      $this->current_query   =
      "
         INSERT INTO
            ".$this->maintenance_database.".".$this->table_names['sql']."
         (project,query,db_type)
         VALUES
         ('".$_GET["project"]."','".$_GET["query"]."','".$_GET["dbtype"]."')
      ";
      $results = $this->runGenericQuery();
      mysql_close($this->server['mysql']['connection']);
      return $results;
   }

   function getSVNFiles($revision,$branchName,$checkinMessage,$checkinTime='',$filesOnly=false)
   {
      /*if (empty($checkinTime))
      {
         $checkinTime = date('Y-m-d H:i:s');
      }

      if (intval($checkinTime) > 0)
      {
         $checkinTime = date('Y-m-d H:i:s',$checkinTime);
      }*/

      $rev = str_replace('Completed: At revision: ','',$rev);
      $this->DeleteFile('files'.$_SESSION['username'].'.svn');
      $compareBlocksOfCode = false;
      if (stristr($revision,","))
      {
         $revs = explode(",",$revision);
      }
      elseif (stristr($revision,"-"))
      {
         $revs2 = explode("-",$revision);
         for ($i=$revs2[0];$i<=$revs2[1];$i++)
         {
            $revs[] = $i;
         }
      }
      else
      {
         $minRev = $revision - 1;
         $maxRev = $revision;
         $compareBlocksOfCode = true;
         $revs[] = $revision;
      }

      foreach ($revs as $rev)
      {
         if ($branchName != "" && $branchName != "trunk")
         {
            $path = "branches/".$branchName;
         }
         else
         {
            $path = "trunk";
         }
         exec('svn --username '.$this->svn_user.' log '.$this->svn_root.$path.' -r'.$rev.' -v >> files'.$_SESSION['username'].'.svn 2>&1');
      }
      $filesModified = file('files'.$_SESSION['username'].'.svn');
      if (is_array($filesModified))
      {
         foreach ($filesModified as $file)
         {
            if (stristr($file, "r{$revision}"))
            {
               //$outputFinal2[$file] = $file;
               $end .= "\n".$file;
               continue;
            }
            $continue = false;
            if (!empty($this->svn_skip_like_these))
            {
               foreach ($this->svn_skip_like_these as $skips)
               {
                  if (stristr($file, $skips))
                  {
                     $continue = true;
                  }
               }
            }
            if ($continue)
            {
               continue;
            }
            $file = trim($file);
            if (stristr($file, ".") && (stristr($file,"M /") || stristr($file,"A /") || stristr($file,"R /") || stristr($file,"D /")) )
            {
               $fileInfo = trim(str_replace(array("M /$path/","A /$path/","D /$path/","R /$path/"),array("","","",""),$file));
               $continue = false;
               if (!empty($this->svn_first_files))
               {
                  foreach ($this->svn_first_files as $firsts)
                  {
                     if ($fileInfo == $firsts)
                     {
                        $outputFirsts[$fileInfo] = $fileInfo;
                        $outputFirsts2[$file] = $file;
                        $continue = true;
                     }
                  }
               }
               if (!empty($this->svn_last_files))
               {
                  foreach ($this->svn_last_files as $lasts)
                  {
                     if ($fileInfo == $lasts)
                     {
                        $outputLasts[$fileInfo] = $fileInfo;
                        $outputLasts2[$file] = $file;
                        $continue = true;
                     }
                  }
               }

               if (!isset($output[$fileInfo]))
               {
                  if ($compareBlocksOfCode)
                  {
                     $affectedFiles[$fileInfo]['total_blocks_new'] = 0;
                     $affectedFiles[$fileInfo]['total_blocks'] = 0;
                     unlink('changes_'.$_SESSION['username'].'.svn');
                     if (stristr($fileInfo,"(from /"))
                     {
                        $fileInfo = str_replace(array("R ","//","trunk/"),array("","",""),trim(substr($fileInfo,0,strpos($fileInfo,"(from /"))));
                     }
                     exec('svn diff --username '.$this->svn_user.' \''.$this->svn_root.$path.'/'.$fileInfo.'@'.$minRev.'\' \''.$this->svn_root.$path.'/'.$fileInfo.'@'.$maxRev.'\' > changes_'.$_SESSION['username'].'.svn 2>&1');
                     $projectId = $this->GetProjectIdFromValue($checkinMessage);
                     $codeBlocksRaw = file_get_contents('changes_'.$_SESSION['username'].'.svn');
                     if (stristr($codeBlocksRaw,'==================================================================='))
                     {
                        $blocks = explode("\n@@ ",$codeBlocksRaw);
                        unset($blocks[0]);
                        foreach($blocks as $codeBlock)
                        {
                           list($affectedLines, $theBlock) = explode('@@',$codeBlock);
                           $affectedLines = trim($affectedLines);
                           $affectedFiles[$fileInfo]['total_blocks_new']                               += 1;
                           $affectedFiles[$fileInfo]['total_blocks']                                   += 1;
                           $affectedFiles[$fileInfo][md5($theBlock)]['hash_id']                         = md5($theBlock);
                           $affectedFiles[$fileInfo][md5($theBlock)]['branch']                          = $path;
                           $affectedFiles[$fileInfo][md5($theBlock)]['file_name']                       = $fileInfo;
                           $affectedFiles[$fileInfo][md5($theBlock)]['project_id']                      = $projectId;
                           $affectedFiles[$fileInfo][md5($theBlock)]['revision']                        = $maxRev;
                           $affectedFiles[$fileInfo][md5($theBlock)]['block_of_code_modified_time']     = $checkinTime;
                           $affectedFiles[$fileInfo][md5($theBlock)]['block_of_code']                   = $theBlock;
                           $affectedFiles[$fileInfo][md5($theBlock)]['line_info']                       = $affectedLines;
                           $affectedFiles[$fileInfo][md5($theBlock)]['developer']                       = $_SESSION['username'];
                           list($id, $isNew) = $this->AddImpactedBlockOfCode($projectId,$theBlock,$affectedLines,$maxRev,$path,$fileInfo,$checkinTime);
                           if (!$isNew)
                           {
                              $affectedFiles[$fileInfo][md5($theBlock)]['id']                              = $id->id;
                              $affectedFiles[$fileInfo][md5($theBlock)]['testing_description']             = $id->testing_description;
                              $affectedFiles[$fileInfo][md5($theBlock)]['testing_flag']                    = $id->testing_flag;
                              $affectedFiles[$fileInfo][md5($theBlock)]['testing_title']                   = $id->testing_title;
                              $affectedFiles[$fileInfo][md5($theBlock)]['change_type_flag']                = $id->change_type_flag;
                           }
                           else
                           {
                              $affectedFiles[$fileInfo][md5($theBlock)]['id']     = $id;
                              $affectedFiles[$fileInfo][md5($theBlock)]['testing_description']             = '';
                              $affectedFiles[$fileInfo][md5($theBlock)]['testing_flag']                    = 'UNIT_TESTED';
                           }
                           $affectedFiles[$fileInfo][md5($theBlock)]['is_new'] = $isNew;
                           $affectedFiles[$fileInfo][md5($theBlock)]['from_other_rev'] = false;
                           $allIds[] = $affectedFiles[$fileInfo][md5($theBlock)]['id'];
                        }
                     }

                     if (is_array($allIds))
                     {
                        $notIn = " project_id = '$projectId' AND id NOT IN (".implode(',',$allIds).")";
                     }
                     else
                     {
                        $notIn = " project_id = '$projectId'";
                     }
                     $rows = $this->GetImpactedBlockRows($notIn);
                     foreach ($rows as $row)
                     {
                        $affectedFiles[$row['file_name']]['total_blocks']                   += 1;
                        $affectedFiles[$row['file_name']][$row['hash_id']]['is_new']         = false;
                        $affectedFiles[$row['file_name']][$row['hash_id']]['from_other_rev'] = true;
                        $keys = array_keys($row);
                        foreach ($keys as $key)
                        {
                           $affectedFiles[$row['file_name']][$row['hash_id']][$key] = $row[$key];
                        }
                     }
                  }


                  if ($continue)
                  {
                     continue;
                  }
                  else
                  {
                     //Add to main output indexes
                     $output[$fileInfo] = $fileInfo;
                     $output2[$file] = $file;
                  }
               }
            }
         }

         if (!$filesOnly && (is_array($outputFirsts) || is_array($output) || is_array($outputLasts)))
         {
            $html .= "<ul><span style='cursor:pointer;color:lightblue;' onclick='if (this.innerHTML == \"View All Code Blocks\") { jq(\".all_code_blocks\").fadeIn(\"slow\");jq(\".view_code\").fadeOut(\"slow\");this.innerHTML = \"Hide All Code Blocks\"; } else {jq(\".all_code_blocks\").fadeOut(\"slow\");jq(\".view_code\").fadeIn(\"slow\");this.innerHTML = \"Hide All Code Blocks\"; } jq(\".fullblock\").click();'>View All Code Blocks</span>";
            $allOutput = array($outputFirsts,$output,$outputLasts);
            foreach ($allOutput as $out)
            {
               if (is_array($out))
               {
                  foreach ($out as $file)
                  {
                     $affected = "";
                     //$affected .= $dataObj->GetAffectedBlocksHTML('timestamp',$file,$projectId);

                     if (is_array($affectedFiles[$file]))
                     {
                        $totalNew = $affectedFiles[$file]['total_blocks_new'];
                        $total = $affectedFiles[$file]['total_blocks'];
                        unset($affectedFiles[$file]['total_blocks'],$affectedFiles[$file]['total_blocks_new']);
                        $i=0;
                        foreach ($affectedFiles[$file] as $impactedBlock)
                        {
                           if ($i==0)
                           {
                              $totalDiff = $total - $totalNew;
                              $affected .= "<ul><span style='cursor:pointer;color:lightblue;' onclick='jq(\".".md5($file)."_code_blocks\").fadeIn(\"slow\");' class='view_code'>View {$totalNew} New Code Block Changes ($totalDiff Others For Project)</span>";
                           }
                           $i++;
                           //$impactedBlock = $affectedFiles[$file][$blockId];
                           //$affected .= print_r($impactedBlock,true);
                           $new = '';
                           if ($impactedBlock['is_new'])
                           {
                           }
                           $otherRev = '';
                           if ($impactedBlock['from_other_rev'])
                           {
                              $otherRev = '<span style="color:blue">From Rev '.$impactedBlock['revision'].'</span><br />';
                           }
                           else
                           {
                              $otherRev = '<span style="color:green">From This Rev '.$impactedBlock['revision'].'</span>';
                           }

                           $options = '';
                           foreach ($this->testing_flags as $option=>$optionTxt)
                           {
                              $sel = '';
                              if ($option == $impactedBlock['testing_flag'])
                              {
                                 $sel = 'selected';
                              }
                              $options .= "<option value='$option' $sel>$optionTxt</option>";
                           }
                           $options2 = '';
                           foreach ($this->change_type_flags as $option=>$optionTxt)
                           {
                              $sel = '';
                              if ($option == $impactedBlock['change_type_flag'])
                              {
                                 $sel = 'selected';
                              }
                              $options2 .= "<option value='$option' $sel>$optionTxt</option>";
                           }


                           $preview = "";
                           $lines = explode("\n",$impactedBlock['block_of_code']);
                           foreach ($lines as $line)
                           {
                              if (substr($line,0,1) == '+')
                              {
                                 $line = trim(substr($line,1));
                                 if (!empty($line))
                                 {
                                    $preview = $line;
                                    break;
                                 }
                              }
                           }
                           $saveEvent = "SaveImpactedBlock({$impactedBlock['id']});";
                           $onclick = "if (this.value!=\"Hide Block\") { jq(\"#{$impactedBlock['hash_id']}_code\").fadeIn(\"slow\"); jq(\"#{$impactedBlock['hash_id']}_preview\").fadeOut(\"fast\");this.value=\"Hide Block\";} else { jq(\"#{$impactedBlock['hash_id']}_code\").fadeOut(\"slow\");this.value=\"View Full Block\"; jq(\"#{$impactedBlock['hash_id']}_preview\").fadeIn(\"fast\"); }";
                           $affected .= "
                           <li class='".md5($file)."_code_blocks all_code_blocks' style='display:none;'>
                              <table>
                                 <tr>
                                    <td>
                                       {$otherRev}
                                       <br />
                                       <i>{$impactedBlock['line_info']}</i>
                                       <br />
                                       <input type='button' style='margin-top:21px;padding:5px;' class='fullblock' value='View Full Block' onclick='$onclick'>
                                    </td>
                                    <td>
                                       <table>
                                          <tr>
                                             <td>
                                                Tag:
                                                <select onchange='$saveEvent' id='{$impactedBlock['id']}_tag'>
                                                   $options2
                                                </select>
                                             </td>
                                             <td>
                                               Title:
                                             </td>
                                             <td>
                                                <input type='text' onblur='$saveEvent' style='width:180px;' value='{$impactedBlock['testing_title']}' id='{$impactedBlock['id']}_title'/>
                                             </td>
                                          </tr>
                                          <tr>
                                             <td>
                                                Flag:
                                                <select onchange='$saveEvent' id='{$impactedBlock['id']}_testing_flag'>
                                                   $options
                                                </select>
                                             </td>
                                             <td>
                                                HTML<br />Desc:
                                             </td>
                                             <td>
                                                <textarea onblur='$saveEvent' style='height:66px;' id='{$impactedBlock['id']}_testing_description'>{$impactedBlock['testing_description']}</textarea>
                                             </td>
                                          </tr>
                                       </table>
                                    </td>
                                 </tr>
                                 <tr id='{$impactedBlock['hash_id']}_preview'><td colspan='5'><pre style='font-style:italic;color:lightblue;overflow:hidden;width:700px;'>".htmlentities($preview)."</pre></td></tr>
                                 <tr id='{$impactedBlock['hash_id']}_code' style='display:none;'><td colspan='5'><pre>".htmlentities($impactedBlock['block_of_code'])."</pre></td></tr>
                              </table>
                           </li>";
                        }
                        $affected .= "</ul>";
                     }

                     $html .= "<li class='files' title='".$file."' id='".md5($file)."'><span style='cursor:pointer;color:red' onclick='jq(\"#".md5($file)."\").remove();'>(x)</span> - ".$file.$affected."</li>";
                  }
               }
            }
            $html .= "</ul>";
         }
         elseif (($filesOnly && (is_array($outputFirsts) || is_array($output) || is_array($outputLasts))))
         {
            if (is_array($outputFirsts))
            {
               $html .= implode("\n",$outputFirsts)."\n";
            }
            if (is_array($output))
            {
               $html .= implode("\n",$output);
               if (is_array($outputLasts))
               {
                  $html .= "\n";
               }
            }
            if (is_array($outputLasts))
            {
               $html .= implode("\n",$outputLasts)."\n";
            }
         }
         else
         {
            $html .=  "<span style='color:red'>No revisions found (Tried ".implode(",",$revs)." in $path repo)<br /><br />You may have entered an incorrect SVN rev you can use commas or dashes</span>";
         }
      }
      else
      {
         $html .=  "<span style='color:red'>Error....</span>";
      }

      if (is_array($outputFirsts2) || is_array($outputLasts2))
      {
         if (is_array($outputFirsts2))
         {
            $html2 .= implode("\n",$outputFirsts2)."\n";
         }
         if (is_array($output2))
         {
            $html2 .= implode("\n",$output2);
            if (is_array($outputLasts))
            {
               $html2 .= "\n";
            }
         }
         if (is_array($outputLasts2))
         {
            $html2 .= implode("\n",$outputLasts2)."\n";
         }
         file_put_contents('files'.$_SESSION['username'].'.svn',$html2);
      }
      return $html;
   }

   function GetSVNCheckins($interval,$branchName,$intervalType='month')
   {
      // -- limit by a couple of months
      if ($interval)
      {
         $extraFlags = '-r {'.date('Y-m-d',strtotime("-".$interval." ".$intervalType)).'}:HEAD';
      }
      exec('svn log '.$this->svn_root.'trunk/ '.$extraFlags.' > svnlog.txt');
      exec('echo BRANCHDELIMITER >> svnlog.txt');
      exec('svn log '.$this->svn_root.'branches/'.$branchName.' '.$extraFlags.' >> svnlog.txt');
      $filesModified = file('svnlog.txt');
      $this->FileLogger('svn log '.$this->svn_root.'branches/'.$branchName.' '.$extraFlags.' >> svnlog.txt');
      $this->DeleteFile('svnlog.txt');
      if (is_array($filesModified))
      {
         $processingBranch = false;
         foreach ($filesModified as $file)
         {
            if (stristr($file,"BRANCHDELIMITER"))
            {
               $processingBranch = true;
               $place = $branchName;
               continue;
            }
            if ($processingBranch == false)
            {
               $place = "trunk";
            }
            if (substr($file,0,1) == 'r' && stristr($file,"|"))
            {
               list($rev,$user,$stamp,$lines) = explode("|",$file);
               $rev = str_replace("r","",trim($rev));
               $user = trim($user);
               $stamp = substr(trim($stamp),0,19);
               $tmp = "<option value=\"$place|$rev\" title=\"$stamp\">$place - Rev #$rev - by $user - on $stamp</option>";
               $allCheckins[$place][$rev]['message'] = "";
               $allCheckins[$place][$rev]['time'] = strtotime($stamp);
               $allCheckins[$place][$rev]['user'] = $user;
               if ($processingBranch == true)
               {
                  $svnOptionsBranch[] = $tmp;
               }
               else
               {
                  $svnOptions[] = $tmp;
               }

            }
            elseif (!stristr($file,"----") && trim(str_replace("\n","",$file)) != "")
            {
               $tmp = "<option disabled>&#160;&#160;&#160;Rev#$rev:\"".substr($file,0,60)."\" &darr;</option>";
               $allCheckins[$place][$rev]['message'] = "$file";
               if ($processingBranch == true)
               {
                  $svnOptionsBranch[] = $tmp;
               }
               else
               {
                  $svnOptions[] = $tmp;
               }
            }
         }
      }
      return array($svnOptions,$svnOptionsBranch,$allCheckins);
   }

}

class EmailSender
{

   var $error_messages = array();
   var $silent_error_messages = array();
   var $tags;
   var $to;
   var $cc;
   var $bcc;
   var $from;
   var $replyTo;
   var $subject;
   var $attachments;
   var $img_parts;
   var $cache;
   var $img_parts_keys;
   var $img_matches;
   var $boundary;
   var $template;
   var $template_type;
   var $from_name;
   var $replyTo_name;
   var $replyTo_mail_insert;
   var $additional_headers;
   var $send_html;
   var $html;
   var $text;
   var $get_file_data_output = array();
   var $threshhold_handling;
   var $content_type;
   var $email_id;

   // ---------------------------------------------------------------------------------
   //
   // Return 'OK' or an error message.
   //
   // $template_type:
   // 'string'  = a string of the actual template (default)
   // 'include' = a file that should be pulled in via include()
   //
   // ---------------------------------------------------------------------------------
   //
   // EXAMPLE 1:
   // $email = new EmailSender("This is the body for the email.");
   // $email->set_to("email@address.com");
   // $email->set_cc("email@address.com");
   // $email->set_from("email@address.com");
   // $email->set_subject("TEST Submit");
   // $email->add_attachment("/usr/temp/test.zip", "test.zip", "zip");
   // $email->sendEmail();
   //
   // EXAMPLE 2:
   // $email = new EmailSender();
   // $email->set_to("email@address.com", "John Doe");
   // $email->set_cc("email@address.com", "John Doe");
   // $email->set_from("email@address.com", "John Doe");
   // $email->set_subject("TEST Submit");
   // $email->set_tags($tags_array);
   // $email->set_template_file($file_name);
   // $email->add_attachment("/usr/temp/test.zip", "test.zip", "zip");
   // $email->sendEmail();
   //
   // EXAMPLE 3:
   // $email = new EmailSender();
   // $email->set_to($to_array);  // to_array = array("email@address.com" => "John Doe", "email@address.com" => "Jane Doe");
   // $email->set_cc($cc_array);
   // $email->set_from("email@address.com", "John Doe");
   // $email->set_subject("TEST Submit");
   // $email->set_tags($tags_array);
   // $email->set_template_file($file_name);
   // $email->add_attachment("/usr/temp/test.zip", "test.zip", "zip");
   // $email->sendEmail();
   //
   // ---------------------------------------------------------------------------------

   function EmailSender($template=false, $template_type="string", $from=null, $subject=null, $to=null, $cc=null, $replyTo=null, $bcc=null)
   {

      $this->template              = $template;
      $this->template_type         = $template_type;
      $this->tags                  = array();
      $this->attachments           = array();
      $this->img_parts             = array();
      $this->img_parts_keys        = array();
      $this->boundary              = "______MIME_BOUNDARY______";
      $this->from                  = $from;
      $this->subject               = $subject;
      $this->replyTo               = $replyTo;
      $this->from_name             = null;
      $this->replyTo_name          = null;
      $this->additional_headers    = array();
      $this->content_type          = false; //if this is supplied it will override the content_type detection - valid type MUST be supplied
      $this->threshhold_handling   = false;
      $this->email_id              = uniqid(time());
      $this->error_messages        = array();
      $this->silent_error_messages = array();

      if ( $template == "INCLUDE" )
      {
         $this->template_type = "include";
      }

      $this->set_to($to);
      $this->set_cc($cc);
      $this->set_bcc($bcc);

      $this->send_html = null; //this should be vastly outdated but is still set by calling logic so leave it to avoid any issues..

   }

   function set_content_type($type)
   {

      $valid_types = array(
         'text' => true,
         'text/html' => true,
      );

      if($valid_types[$type])
      {

         $this->content_type = $type;

      }
      else
      {

         $this->content_type = false; //auto-detection will occur

      }

   }

   function add_header($header)
   {
      array_push($this->additional_headers, $header);
   }

   function set_template($template)
   {
      $this->template = $template;
   }

   function set_template_file($template)
   {
      $this->set_template($template);
      $this->template_type = "include";
   }

   function set_to($to, $to_name=false)
   {
      if ( strstr($to, "<") && strstr($to, ">") )
      {
         list($to_name, $to) = explode("<", $to, 2);
         $to = str_replace(">", "", $to);
      }

      if ( is_array($to) )
      {
         foreach ($to as $id=>$person)
         {
            if (empty($person))
            {
               unset($to[$id]);
            }
         }
         $this->to = $to;
      }
      else
      {
         $this->to = array();
         if ( $to_name )
         {
            $this->to[$to] = $to_name;
         }
         else if ($to != null && strpos($to , ","))
         {
            $this->to = split(",",$to);
         }
         else if ($to != null)
         {
            array_push($this->to, $to);
         }
      }
   }

   function add_cc($cc, $cc_name=false)
   {
      if ( strstr($cc, "<") && strstr($cc, ">") )
      {
         list($cc_name, $cc) = explode("<", $cc, 2);
         $cc = str_replace(">", "", $cc);
      }
      if ( is_array($cc) )
      {
         $this->cc = $cc;
      }
      else
      {
         if ( $cc_name )
         {
            $this->cc[$cc] = $cc_name;
         }
         else if ($cc != null && strpos($cc, ","))
         {
            $this->cc = split(",", $cc);
         }
         else if ($cc != null)
         {
            array_push($this->cc, $cc);
         }
      }
   }

   function set_cc($cc, $cc_name=false)
   {
      $this->cc = array();
      $this->add_cc($cc, $cc_name);
   }

   function clear_cc()
   {
      $this->cc = array();
   }

   function add_bcc($bcc, $bcc_name=false)
   {
      if ( strstr($bcc, "<") && strstr($bcc, ">") )
      {
         list($bcc_name, $bcc) = explode("<", $bcc, 2);
         $bcc = str_replace(">", "", $bcc);
      }
      if ( is_array($bcc) )
      {
         $this->bcc = $bcc;
      }
      else
      {
         $this->bcc = array();
         if ( $bcc_name )
         {
            $this->bcc[$bcc] = $bcc_name;
         }
         else if ($bcc != null && strpos($bcc, ","))
         {
            $this->bcc = split("," ,$bcc);
         }
         else if ($bcc != null)
         {
            array_push($this->bcc, $bcc);
         }
      }
   }

   function set_bcc($bcc, $bcc_name=false)
   {
      $this->bcc = array();
      $this->add_bcc($bcc, $bcc_name);
   }

   function clear_bcc()
   {
      $this->bcc = array();
   }

   function set_tags($tags)
   {
      if ( $tags == null )
      {
         $tags = array();
      }
      $this->tags = $tags;
   }

   function set_attachments($attachments)
   {
      $this->attachments = $attachments;
   }

   function add_attachment($name, $file, $type)
   {
      $this->attachments[$name] = array();
      $this->attachments[$name]["fileLocation"]  = $file;
      $this->attachments[$name]["attachmentType"]= $type;
   }

   function clear_attachments()
   {
      $this->attachments = array();
   }

   function set_from($from, $from_name=false)
   {
      if ( strstr($from, "<") && strstr($from, ">") )
      {
         list($from_name, $from) = explode("<", $from, 2);
         $from = str_replace(">", "", $from);
      }
      if(trim($from) == '')
      {
         $from = 'nobody@'.$_SERVER['HTTP_HOST'];
      }
      $this->from = $from;
      if ( $from_name )
      {
         $this->from_name = $from_name;
      }
      else
      {
         $this->from_name = $from;
      }
   }

   function set_subject($subject)
   {
      $this->subject = $subject;
   }

   function set_replyTo($replyTo, $replyTo_name)
   {
      $this->replyTo = $replyTo;
      if ( $replyTo_name )
      {
         $this->replyTo_name = $replyTo_name;
      }
      else
      {
         $this->replyTo_name = $replyTo;
      }
      $this->replyTo_mail_insert = '-f' . $this->replyTo;
   }

   function get_comma_string($arr)
   {
      $string = "";
      foreach($arr as $key=>$element)
      {

         if ( is_int($key) )
         {
            if ( $string )
            {
               $string .= ", ";
            }
            $string .= "\"".$element."\" <".$element.">";
         }
         else
         {
            if ( $string )
            {
               $string .= ", ";
            }
            $string .= "\"".$element."\" <".$key.">";
         }
      }
      return $string;
   }

   function set_html ($htm, $txt=NULL)
   {
      $this->set_template($htm); //html vs text is detected automatically now
      $this->content_type = 'text/html';
   }

   function getHeaders()
   {

      $headers = '';

      $headers .= "MIME-Version: 1.0\n";

      if ( $this->replyTo == null )
      {
         $this->set_replyTo($this->from, $this->from_name);
      }
      $headers  .= "From: \"".$this->from_name."\" <".$this->from.">\n";
      if(is_array($this->to))
      {

         $tocount = count($this->to);

         for($tc = 0; $tc < $tocount; $tc++)
         {

            $headers .= "Rcpt-To: \"".$this->to[$tc]."\" <".$this->to[$tc].">\n";

         }

      }
      else
      {

         $headers .= "Rcpt-To: \"".$this->to."\" <".$this->to.">\n";

      }

      $headers .= "Reply-To: \"".$this->replyTo_name."\" <".$this->replyTo.">\n";
      $headers .= "Date: " . date('r') . "\n";
      $headers .= "Message-ID: <" . $this->email_id . ">\n";
      if(false !== $this->threshhold_handling)
      { //only if we are doing detection

         $headers .= "Threshhold-Handling: " . $this->threshhold_handling . "\n";

      }

      $headers .= "Content-Type: multipart/mixed; boundary=\"" . $this->boundary . "\"\n";
      $headers .= "Content-Transfer-Encoding: 7bit\n";


      // -- CC ----------------------------------
      if ( $this->cc )
      {
         $headers .= "Cc: ". $this->get_comma_string($this->cc) . "\n";
      }

      // -- BCC ----------------------------------
      if ( $this->bcc )
      {
         $headers .= "Bcc: ". $this->get_comma_string($this->bcc) . "\n";
      }

      return $headers;
   }

   function buildBody()
   {
      // -- Get the template -----------------------
      $template = "";
      if ( $this->template_type != "string" )
      {
         ob_start();
         include_once($this->template);
         $template = ob_get_contents();
         ob_end_clean();
         $this->template_type = "string";
      }
      else
      {
         $template = $this->template;
      }
      // -- Replace all the tags -------------------
      foreach ( $this->tags as $tag => $data )
      {
         $template = str_replace("<".$tag.">", $data, $template);
      }
      return $template;
   }

   function getFileData($filename)
   {
      //if https, use curl, otherwise use fopen
      if(false !== strpos($filename, 'https://'))
      { //use curl
         //die('trying to use curl..');
         $ch = curl_init($filename);
         if(curl_errno($ch) == 0)
         {
            ob_start();
            curl_exec($ch);
            $return = ob_get_contents();
            ob_end_clean();
            return $return;
         }
         else
         {
            $this->recordSilentError('ERROR: curl failed on ' . trim($filename));
            return false;
         }
      }
      else
      { //use fopen
         ob_start();
         $fp = fopen($filename, 'rb');
         $tmp = ob_get_contents();
         ob_end_clean();
         $this->get_file_data_output[] = $tmp;
         unset($tmp);
         if($fp)
         {
            while(!feof($fp))
            {
               $return .= fread($fp, 1024);
            }
            fclose($fp);
            return $return;
         }
         else
         {
            $this->recordSilentError('ERROR: fopen failed on ' . trim($filename));
            return false;
         }
      }
   }

   function append_to_img_parts($fname, $filetype, $data_base64)
   {
      $content_id = uniqid(time());
      $this->img_parts[$fname][] = array(
         'cid'         => $content_id,
         'filetype'    => $filetype,
         'data_base64' => $data_base64,
      );
      return $content_id;
   }

   function add_image_mime_part($img_location)
   {
      $supported_filetypes = array(
         'jpg'  => true,
         'jpeg' => true,
         'gif'  => true
      );
      //first check to see if we know what kindof image this is
      //this would be much easier (not to mention more reliable) if we had the GD lib's loaded...
      $img_location = trim($img_location);
      $tmparr       = split("\.", $img_location);
      $filetype     = strtolower(trim($tmparr[(count($tmparr)-1)]));
      if($supported_filetypes[$filetype])
      {
         //now try to get to the file
         $tmp = split("\\\\", stripslashes($img_location));
         $tmp2 = split("/", $tmp[(count($tmp)-1)]);
         if(!$fname = $tmp2[(count($tmp2)-1)])
         {
            $this->recordSilentError('ERROR: could not get image fname from ' . trim($img_location));
            return false;
         }
         $data = $this->getFileData($img_location);
         $data_base64 = chunk_split(base64_encode($data), 68, "\n");
         if($data !== false)
         {
            //check to see if we've recieved this file already; if so and the data matches, don't duplicate just return the cid - if the filename is the same but the image is different we will of course need to add another one
            if(!is_array($this->img_parts[$fname]))
            { //doesn't exist, create it
               $content_id = $this->append_to_img_parts($fname, $filetype, $data_base64);
            }
            else
            { //spin through and see if we find a match to the current image
               $fname_count = count($this->img_parts[$fname]);
               for($fx = 0; $fx < $fname_count; $fx++)
               {
                  if($this->img_parts[$fname][$fx]['data_base64'] == $data_base64)
                  { //found it
                     $content_id = $this->img_parts[$fname][$fx]['cid'];
                  }
               }
               if($content_id == '')
               { //we didn't find a compatible image - add a new one (same name, whatever)
                  $content_id = $this->append_to_img_parts($fname, $filetype, $data_base64);
               }
            }
            return $content_id;
         }
         else
         {
            $this->recordSilentError('ERROR: getFileData failed somehow');
            return false;
         }
      }
      else
      {
         $this->recordSilentError('ERROR: Unsupported file type ' . $filetype);
         return false;
      }
   }

   function send_email($to = null, $tags = null)
   {
      $this->sendEmail($to, $tags);
   }

   function sendEmail($to = null, $tags = null)
   {
      $body = $this->buildBody() . $images_tags;
      if ( $to != null   )
      {
         $this->set_to($to);
      }
      if ( $tags != null )
      {
         $this->set_tags($tags);
      }
      //intercept img's in the body of the email, suck them into 'img_parts' and then send the email
      $match_on = '/<.*img.*src=([\'"])?([^\'" >]+)([\'" >])/i';
      preg_match_all($match_on, $body, $this->img_matches);
      $matchesCount = count($this->img_matches[2]);
      if($matchesCount > 0)
      {
         for($x = 0; $x < $matchesCount; $x++)
         {
            //pump the image through the add_image_mime_part() and get back the cid for this image
            $cid = $this->add_image_mime_part($this->img_matches[2][$x]);
            if($cid !== false)
            { //image found and obtained; reference it to the mime part
               $body = str_replace($this->img_matches[1][$x] . $this->img_matches[2][$x] . $this->img_matches[1][$x], $this->img_matches[1][$x] . "cid:" . $cid . $this->img_matches[1][$x], $body);
            }
            else
            { //image could not be obtained; put back the original reference with an alternate title message - doubtful developers will notice... but hopefully we aren't building html for emails that doesn't reference images absolutely (which breaks email)
               $body = str_replace($this->img_matches[1][$x] . $this->img_matches[2][$x] . $this->img_matches[1][$x], $this->img_matches[1][$x] . $this->img_matches[2][$x] . $this->img_matches[1][$x] . " alt=" . $this->img_matches[1][$x] . "[IMAGE_UNAVAILABLE]" . $this->img_matches[1][$x], $body);
            }
         }
      }
      $this->template = $body;
      return $this->sendEmailNoAttachment();
   }

   function sendEmailNoAttachment()
   {
      if(false === $this->content_type)
      {
         $threshhold = '16.5'; //percentage threshhold to determine whether or not this is an html or text email ( > $threshhold = html else = text )
         $stripped_body = strip_tags($this->buildBody());
         $body_length = strlen($this->buildBody());
         $body_length_stripped = strlen($stripped_body);
         $threshhold_result = ( 100 - ( ( 100 * $body_length_stripped ) / $body_length ) );
         if($threshhold_result > $threshhold)
         {
            $content_type = 'text/html';
         }
         else
         {
            $content_type = 'text';
         }
         $this->threshhold_handling = 'threshhold=' . $threshhold . '; body_length=' . $body_length . '; body_length_stripped=' . $body_length_stripped . '; threshhold_result=' . $threshhold_result . '; (detected) content_type=' . $content_type;
      }
      else
      {
         $content_type = $this->content_type;
      }
      // -- Send the email ---------------------------------------
      $result = "OK";
      $to = $this->get_comma_string($this->to);
      $body = '';
      $body .= "This is a multipart message in MIME format.  If you are reading this, that means your email client does not understand the MIME format.\n";
      $body .= "\n";
      $body .= "Original message reduced to text:\n";
      $body .= $stripped_body . "\n";
      $body .= "--" . $this->boundary . "\n";
      $body .= "Content-Type: " . $content_type . "; charset=ISO-8859-1\n";
      $body .= "Content-Transfer-Encoding: 7bit\n\n";
      $body .= $this->buildBody() . "\n";
      //add any intercepted images
      foreach($this->img_parts as $key => $val)
      {
         $img_parts_count = count($this->img_parts[$key]);
         //if they exist, add in the img_parts seperated by the boundary
         if($img_parts_count > 0)
         {
            for($pc = 0; $pc < $img_parts_count; $pc++)
            {
               $body .= "--" . $this->boundary . "\n";
               $body .= "Content-Type: image/" . $this->img_parts[$key][$pc]['filetype'] . "; name=\"" . $key . "\"\n";
               $body .= "Content-Transfer-Encoding: base64\n";
               $body .= "Content-ID: <" . $this->img_parts[$key][$pc]['cid'] . ">\n";
               $body .= "Content-Disposition: inline;\n";
               $body .= "\n";
               $body .= $this->img_parts[$key][$pc]['data_base64'];
            }
         }
      }
      //then do actual attachments (little different storage within the array.. personally i like this one better - pulls the data now instead of stuffing it into an array - less overhead..)
      foreach($this->attachments as $name => $arr)
      {
         $data = $this->getFileData($arr['fileLocation']);
         if($data !== false)
         {
            $body .= "--" . $this->boundary . "\n";
            $body .= "Content-Type: " . $arr['attachmentType'] . "; name=\"" . $name . "\"\n";
            $body .= "Content-Transfer-Encoding: base64\n";
            $body .= "Content-Disposition: attachment;\n";
            $body .= "\n";
            $body .= chunk_split( base64_encode($data), 68, "\n");
         }
         else
         {
            $result = "ERROR : attachment '$name' could not be obtained!";
         }
      }
      $body .= "--" . $this->boundary . "--\n";
      if(!mail(trim($to), trim($this->subject), trim($body), trim($this->getHeaders()), $this->replyTo_mail_insert) )
      {
         $this->recordError('ERROR : Failed to send email.');
      }
      //log gives us the ultimate return
      $return = $this->log($to, $this->subject, $body, $this->getHeaders());
      //then reset important vars like when coming into _construct
      $this->attachments           = array();
      $this->img_parts             = array();
      $this->img_parts_keys        = array();
      $this->from                  = $from;
      $this->subject               = $subject;
      $this->replyTo               = $replyTo;
      $this->from_name             = null;
      $this->replyTo_name          = null;
      $this->additional_headers    = array();
      $this->content_type          = false; //if this is supplied it will override the content_type detection - valid type MUST be supplied
      $this->threshhold_handling   = false;
      $this->email_id              = uniqid(time());
      $this->error_messages        = array();
      $this->silent_error_messages = array();
      //then give the calling logic its answer as to what transpired
      return $return;
   }

   function recordError($error)
   {
      $this->error_messages[] = $error;
   }

   function recordSilentError($error)
   {
      $this->silent_error_messages[] = $error;
   }

   function log($to, $subject, $body, $headers)
   {
      $return = 'OK';
      //log emails @todo write email information to a local file
      return $return;
   }

   function sendEmailAttachment()
   {
      return $this->sendEmailNoAttachment();
   }

   function primeDirectoryTree($dirTree)
   {
      //take dirTree, check out all dir's it's made of if any don't exist, make them
      $dirNames = split("/", $dirTree);
      if(trim($dirNames[0]) == '')
      { //building from root
         $wholeDir = "/";
      }
      else
      { //building from current dir
         $wholeDir = getcwd() . "/";
         if(trim($dirNames[0]) != '.' && trim($dirNames[0]) != '..')
         {
            $wholeDir .= $dirNames[0] . "/";
            if(!is_dir($wholeDir))
            {
               mkdir($wholeDir);
            }
         }
      }
      $count = count($dirNames);
      for($x = 1; $x < $count; $x++)
      {
         $curDirName = $dirNames[$x];
         if(trim($curDirName) != '')
         {
            $wholeDir .= $curDirName . "/";
            if(!is_dir($wholeDir))
            {
               mkdir($wholeDir);
            }
         }
      }
   }
}


class DeploymentMaintenanceWebForm extends DeploymentMaintenance
{

   function DeploymentMaintenanceWebForm($dataObj)
   {
      $svnRepo    = $dataObj->svn_root;
      $mainFolder = getcwd().'/';
      $archiveFolder = $mainFolder.'archive/';

      if ($_GET['branch'])
      {
         $dataObj->svn_default_branch_name = $_GET['branch'];
      }

      if (!isset($_GET['ajax']))
      {
         if (!$dataObj->client_paths)
         {
            $dataObj->getDeploymentRegions();
            $dataObj->getSQLHistory();
         }
         $randomRegion  = array_keys($dataObj->client_deployment_regions['ALL']);
         $randomRegionId = $randomRegion[0];

         $dataObj->query_link_url = $dataObj->base_url.'?region_name='.$dataObj->maintenance_database.'&hide_sql=1&deploy=CUSTOM&customRegions[]='.$randomRegionId.'&hideData=&exportCSV=on&customQuery=<!--SQL-->';

         $dataObj->query_link_template = '<a style="cursor:pointer" onclick="document.location = \''.$dataObj->query_link_url.'\';" href="'.$dataObj->query_link_url.'"><!--NAME--></a>';

         foreach ($dataObj->menu_queries as $name=>$sql)
         {
            $viewHTML .= '<li style="border: 1px solid #aaaaaa; background: #ffffff url(images/ui-bg_glass_65_ffffff_1x400.png) 50% 50% repeat-x; font-weight: normal; color: #212121;">'.str_replace(array('<!--NAME-->','<!--SQL-->'),array($name,$sql),$dataObj->query_link_template).'</li>';
         }

         $html .=

            $this->startDefaultHeader().'

            <title>Deployment Maintenance</title>
            <style>
               #demo1 a { white-space:normal !important; height: auto; padding:1px 2px; }
               #demo1 li > ins { vertical-align:top; }
               #demo1 .jstree-hovered, #demo4 .jstree-clicked { border:0; }
            </style>
            <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.6.1/jquery.js"></script>
            <script type="text/javascript">
               var jq = jQuery.noConflict();
            </script>
            <link rel="stylesheet" type="text/css" media="all" href="js/jquery-ui/css/smoothness/jquery-ui-1.8.12.custom.css"/>
            <script type="text/javascript" src="js/jquery-ui/js/jquery-ui-1.8.12.custom.min.js"></script>
            <script type="text/javascript" src="js/jstree/_lib/jquery.cookie.js"></script>
            <script type="text/javascript" src="js/jstree/_lib/jquery.hotkeys.js"></script>
            <script type="text/javascript" src="js/jstree/jquery.jstree.js"></script>
            <script type="text/javascript" class="source below">
            jq(function () {
               jq("#demo1")
                  .jstree({
                     "plugins" : ["themes","html_data","ui","crrm","hotkeys"],
                     "core" : { "initially_open" : [ "phtml_1" ] }
                  })
                  // EVENTS
                  .bind("loaded.jstree", function (event, data) {
                  });
               jq("#demo1").bind("open_node.jstree", function (e, data) {
                  data.inst.select_node("#phtml_2", true);
               });
               jq("#tabs").tabs();
            });
            </script>

            <script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/prototype/1.6.0.2/prototype.js"></script>
            <SCRIPT LANGUAGE="JavaScript" TYPE="text/javascript">
               function validateDeployment()
               {
                  var hasFiles = false;
                  $("svnfiles").innerHTML = "";
                  jq(".files").each(function(index) {
                     hasFiles = true;
                     $("svnfiles").innerHTML += jq(this).attr("title") + "\\n";
                  });
                  //alert($("svnfiles").innerHTML);

                  if ($("svnrev").value != "" && hasFiles == false)
                  {
                     alert("You forgot to click on the Fetch Rev button for this deployment.  This is required.");
                     return false;
                  }

                  if ($("deploy").value == "")
                  {
                     alert("Select a deployment region.");
                     return false;
                  }

                  var branch = "trunk";
                  if ($("branch").disabled == false)
                  {
                     branch = $("branch").value;
                  }

                  if (window.confirm("Are you completely sure/ready to deploy from \'" + branch + "\' to \'" + $("deploy").value + "\' with a project status of \'" + $("project_status").value + "\'?"))
                  {
                     if ($("deploy_message") && $("deploy_message").value == "" && ($("deploy").value == "ALL" || $("deploy").value == "@") && ($("svnrev").value != "" || $("file").value != ""))
                     {
                        alert("Deployment message must be set when you are launching to PROD/ALL");
                        return false;
                     }
                     else
                     {
                        if ($("svnrev").value != "")
                        {
                           var comment = null;
                           while( (comment == null || comment == "") == true)
                           {
                              comment = window.prompt("Adjust your project or checkin comment.",jq("#project_name :selected").attr("message"));
                           }
                           jq("#project_name_hidden").val(comment);
                        }
                     }
                     return true;
                  }
                  else
                  {
                      return false;
                  }

               }

               function getQueries()
               {
                  var url = "http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'?ajax=getQueries&project=" + $("project").value;
                  new Ajax.Request(
                     url,
                  {
                     method: "get",
                     onSuccess: function(transport)
                     {
                        var response = transport.responseText || false;
                        if (response != false)
                        {
                           eval(response);
                        }
                     },
                     onFailure: function() { alert("An unexpected error occurred."); }
                  });
               }

               function SaveImpactedBlock(id)
               {
                  var desc = $(id + "_testing_description").value;
                  var tag  = $(id + "_tag").value;
                  var title  = $(id + "_title").value;
                  var test_flag = $(id + "_testing_flag").value;
                  jq("#" + id + "_testing_description,#" + id + "_testing_flag,#" + id + "_tag,#" + id + "_title").css("background-color","grey");
                  jq("#" + id + "_button").val("Saving...").fadeOut("slow");
                  var url = "http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'?ajax=save_block_info&id=" + id + "&title=" + title + "&tag=" + tag + "&description=" + desc + "&test_flag=" + test_flag;
                  new Ajax.Request(
                     url,
                  {
                     method: "get",
                     onSuccess: function(transport)
                     {
                        var response = transport.responseText || false;
                        if (response != false)
                        {
                           jq("#" + id + "_testing_flag,#" + id + "_tag,").css("background-color","black");
                           jq("#" + id + "_testing_description, #" + id + "_title").css("background-color","white");
                           jq("#" + id + "_button").val("Save Block").fadeIn("fast");
                        }
                     },
                     onFailure: function() { alert("An unexpected error occurred."); }
                  });
               }

               function getProjectStatus()
               {
                  var url = "http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'?ajax=get_project_status&project=" + $("project_name").value;
                  new Ajax.Request(
                     url,
                  {
                     method: "get",
                     onSuccess: function(transport)
                     {
                        var response = transport.responseText || false;
                        if (response != false)
                        {
                           eval(response);
                        }
                     },
                     onFailure: function() { alert("An unexpected error occurred."); }
                  });
               }

            function compareSvnRev(thebranch,obj)
            {
               var branch = "";
               if ($("branch").disabled == false)
               {
                  branch = $("branch").value;
               }

               if (thebranch)
               {
                  branch = thebranch;
               }
               var time = "";

               if (obj.selectedIndex)
               {
                  time = "&time=" + obj.options[obj.selectedIndex].title;
               }
               var url = "http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'?ajax=svnrev" + time + "&rev=" + $("svnrev").value + "&branch=" + branch + "&checkin_message=" + jq(\'#project_name option:selected\').text() + "&repo='.$svnRepo.'";
               new Ajax.Request(
                  url,
               {
                  method: "get",
                  onSuccess: function(transport)
                  {
                     var response = transport.responseText || false;
                     if (response != false)
                     {
                        $("svnfiles").style.display = "none";
                        $("filesaffected").innerHTML = response;
                     }
                  },
                  onFailure: function() { alert("An unexpected error occurred."); }
               });
            }

            function getCheckins(thebranch,search) {
               if(typeof(search) == "undefined")
               {
                  var search = "";
               }
               var branch = "";
               if ($("branch").disabled == false)
               {
                  branch = $("branch").value;
               }

               if (thebranch)
               {
                  branch = thebranch;
               }
               var url = "http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'?ajax=getcheckins&search=" + search + "&branch=" + branch + "&repo='.$svnRepo.'";
               new Ajax.Request(
                  url,
               {
                  method: "get",
                  onSuccess: function(transport) {
                     var response = transport.responseText || false;
                     if (response != false) {
                        alert(response);
                        //$("svnfiles").value = response;
                     }
                  },
                  onFailure: function() { alert("An unexpected error occurred."); }
               });
            }

            function DeselectAllList(CONTROL)
            {
               for(var i = 0;i < CONTROL.length;i++)
               {
                  CONTROL.options[i].selected = false;
               }
            }

            function addQueries(typeOfDB,Query)
            {
               if ($("store_queries").value==1)
               {
                  var url = "http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'?ajax=addQueries&query=" + Query + "&dbtype=" + typeOfDB + "&project=" + $("project").value;
                  new Ajax.Request(
                     url,
                  {
                     method: "get",
                     onSuccess: function(transport) {
                        var response = transport.responseText || false;
                        if (response != false) {
                           alert(response);
                        }
                     },
                     onFailure: function() { alert("An unexpected error occurred."); }
                  });
               }
            }

            function changeSVN(branchName,obj,fromButton)
            {
               if(jq(obj).val() != \'\')
               {
                  //$(\'svnfiles\').style.display=\'block\';
                  var rev  = "";
                  //In selectbox mode there are two items in value split them else you are from the textbox
                  if (obj.id == \'svnrevselect2\' || obj.id == \'svnrevselect\')
                  {
                     var parts = jq(obj).val().split(\'|\');
                     rev = parts[1];
                  }
                  else
                  {
                     if ($(\'branch\').disabled == true)
                     {
                        branchName = \'trunk\';
                     }
                     rev = jq(obj).val();
                     fromButton = true;
                  }

                  if ($("svnrev").value != rev || fromButton)
                  {
                     $(\'filesaffected\').innerHTML=\'Pulling Revs From \' + branchName + \' Rev#(\' + rev + \')<br /><br />Please Wait....\';
                     var branch = false;
                     if (branchName != \'trunk\')
                     {
                        branch = branchName;
                        $(\'branch\').disabled = false;
                     }
                     else
                     {
                        $(\'branch\').disabled = true;
                     }
                     $(\'svnrev\').value = rev;
                     var test1 = rev.search(new RegExp("-", "gi" ));
                     var test2 = rev.search(new RegExp(",", "gi" ));

                     var project_id = "";
                     if (test1 > 0)
                     {
                        var tmp = rev.split(\'-\');
                        project_id = tmp[1];
                     }
                     else if (test2 > 0)
                     {
                        var tmp = rev.split(\',\');
                        project_id = tmp[1];
                     }
                     else
                     {
                        project_id = rev;
                     }
                     jq(\'#project_name\').val(project_id);
                     showSVNFilesBox();
                     compareSvnRev(branch,obj);

                  }
               }
            }

            function showSVNFilesBox()
            {
               jq(\'#run_query_td1\').html(\'SVN Files To Deploy:\');
               jq(\'#sql_area\').fadeOut(\'slow\');
               //jq(\'#svnfiles\').fadeIn(\'slow\');
            }
            </SCRIPT>
         </head>
         <body style="background-color:black" onload="if ($(\'branch_click\')) { $(\'branch_click\').onclick(); }">
         <div id="tabs">
         <ul>
            <li style="border: 1px solid #aaaaaa; background: #ffffff url(images/ui-bg_glass_65_ffffff_1x400.png) 50% 50% repeat-x; font-weight: normal; color: #212121;"><a style="cursor:pointer" onclick="document.location = \'http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'\';" href="#">Home</a></li>
            '.$viewHTML.'
            <li style="border: 1px solid #aaaaaa; background: #ffffff url(images/ui-bg_glass_65_ffffff_1x400.png) 50% 50% repeat-x; font-weight: normal; color: #212121;"><a style="cursor:pointer" onclick="document.location = \'http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'?create=1\';" href="#">New SQL</a></li>
         </ul>
         <div id="tabs-1">
         <form method="POST" id="mainForm" enctype="multipart/form-data" style="font-size:20px;" onsubmit="return validateDeployment();">
         <table align="center" width="100%" style="border : 1px solid black;" cellspacing="0" cellpadding="5">
         <tr>
            <Td colspan="3">';

                $loginForm = '

                <script type="text/javascript">
                function login()
                {
                    jq.ajax({
                          type: "GET",
                          url:  "'.$dataObj->base_url.'",
                          data: "username=" + jq(\'#username\').val() + "&url=" + jq(\'#url\').val() + "&password=" + jq(\'#password\').val(),
                          success: function(msg)
                          {
                             document.location = "http://'.$_SERVER['HTTP_HOST'].'" + jq(\'#url\').val();
                          }
                   });
                }

                jq(function()
                {
                   jq("input").keypress(function (e)
                   {
                      if ((e.which && e.which == 13) || (e.keyCode && e.keyCode == 13))
                      {
                         login();
                      }
                   });
                });

                </script>
                '.$dataObj->messageHTML('
                <div id="dialog" title="Login">
                  <p>
                     <input type="hidden" id="url" value="'.$_GET['url'].'">
                     <h3>Username:<input type="text" id="username"></h3>
                     <h3>Password:&#160;<input type="password" id="password"></h3>
                     <input type="button" onclick="login();" style="font-size:20px;" value="Login"/>
                  </p>
               </div>','success');

               $maintenanceForm .= '
                  <div>
                     <table  align="center" style="height : 100%; width : 100%;">
                        <tr>
                           <td valign="top" width="100%" id="left_pane">';
                        if( !isset($_GET['create']) || isset($_GET['customQuery'] ))
                        {
                           if (!isset($_REQUEST['transaction']))
                           {
                              $sqlCache = '<ul>';
                              $iii = 0;
                              if (sizeof($dataObj->query_cache) > 0)
                              {
                                 foreach ($dataObj->query_cache as $day=>$queries)
                                 {
                                    $sqlCache .= '<li><a onclick="jq.jstree._focused().select_node(\'#phtml_'.$iii.'\');">'.$day.'</a><ul>';
                                    foreach ($queries as $k=>$q)
                                    {
                                       $iii++;
                                       $sqlCache .= '
                                       <textarea id="'.$iii.'" style="display:none">'.$q.'</textarea>
                                       <li id="phtml_'.$iii.'"><a onclick="$(\'customQuery\').innerHTML = $(\''.$iii.'\').innerHTML;">'.str_replace('QUERY_IN_','',$dataObj->query_cache_regions[$day][$k]).' - '.substr($q,0,50).'...</a></li>
                                       ';
                                    }
                                    $sqlCache .= '</ul></li>';
                                 }
                              }
                              $offset  = (array_key_exists('offset',$_GET)) ? $_GET['offset'] + 1: 1;
                              $sqlCache .= '<li><a onclick="document.location = \'http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'?offset='.$offset.'\';">More SQL History...</a></li></ul>';

                              $customRegionsTest = "<optgroup label=\"TEST includes:\">";
                              foreach($dataObj->client_deployment_regions['TEST'] as $val)
                              {
                                 $customRegionsTest .= '<option disabled>'.$val['DB'].'</option>';
                                 $name = (!empty($val['DB'])) ? $val['DB'] : $val['NAME'];
                                 $customRegions .= '<option value="'.$val['ID'].'">'.$name.' (TEST)</option>';
                              }
                              $customRegionsTest .= "</optgroup>";

                              $customRegionsInt = "<optgroup label=\"INTEGRATION includes:\">";
                              foreach($dataObj->client_deployment_regions['INTEGRATION'] as $val)
                              {
                                 $customRegionsInt .= '<option disabled>'.$val['DB'].'</option>';
                                 $name = (!empty($val['DB'])) ? $val['DB'] : $val['NAME'];
                                 $customRegions .= '<option value="'.$val['ID'].'">'.$name.' (INTEGRATION)</option>';
                              }
                              $customRegionsInt .= "</optgroup>";

                              $customRegionsStage = "<optgroup label=\"TEST_STAGING includes:\">";
                              foreach($dataObj->client_deployment_regions['TEST_STAGING'] as $val)
                              {
                                 $customRegionsStage .= '<option disabled>'.$val['DB'].'</option>';
                                 $name = (!empty($val['DB'])) ? $val['DB'] : $val['NAME'];
                                 $customRegions .= '<option value="'.$val['ID'].'">'.$name.' (TEST_STAGING)</option>';
                              }
                              $customRegionsStage .= "</optgroup>";

                              foreach($dataObj->client_deployment_regions['PRODUCTION_STAGING'] as $val)
                              {
                                 $name = (!empty($val['DB'])) ? $val['DB'] : $val['NAME'];
                                 $customRegions .= '<option value="'.$val['ID'].'" >'.$name.' (PROD_STAGING)</option>';
                              }

                              $customRegionsProd = "<optgroup label=\"PRODUCTION includes:\">";
                              foreach($dataObj->client_deployment_regions['PRODUCTION'] as $val)
                              {
                                 $name = (!empty($val['DB'])) ? $val['DB'] : $val['NAME'];
                                 $customRegionsFirst .= '<option value="'.$val['ID'].'" style="background-color:lightgreen;">'.$val['DB'].'</option>';
                                 $customRegionsProd .= '<option disabled>'.$val['DB'].'</option>';
                              }
                              $customRegionsProd .= "</optgroup>";

                              if (!isset($_FILES['maintenance']) && !isset($_GET['customQuery']))
                              {
                                 if ($svnRepo)
                                 {
                                    list($svnOptions,$svnOptionsBranch,$allCheckins) = $dataObj->GetSVNCheckins($dataObj->svn_last_x_months,$dataObj->svn_default_branch_name);
                                    if ($_REQUEST['releaseLatest'])
                                    {
                                       if (!isset($_SESSION['username']))
                                       {
                                          die('No session found');
                                       }
                                       $branchToRegion    = $dataObj->svn_preferred_deploy;
                                       if (!isset($_REQUEST['branch']) && $dataObj->svn_default_branch_name != $_REQUEST['releaseLatest'] && $_REQUEST['releaseLatest'] != 'trunk')
                                       {
                                          if ($_REQUEST['autoDeploy'])
                                          {
                                             $extra             .= "&autoDeploy=1";
                                          }
                                          header("Location: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?branch=".$_REQUEST['releaseLatest']."&releaseLatest=".$_REQUEST['releaseLatest'].$extra);
                                          exit;
                                       }
                                       if ($_REQUEST['releaseLatest'] != 'trunk')
                                       {
                                          $_REQUEST['branch'] = $_REQUEST['releaseLatest'];
                                          $extra             .= "&branch=".$_REQUEST['releaseLatest'];
                                       }

                                       if ($_REQUEST['autoDeploy'])
                                       {
                                          $extra             .= "&auto_deploy=on";
                                       }
                                       $tmp               = array_reverse($allCheckins[$_REQUEST['releaseLatest']],true);
                                       $svnRev            = key($tmp);
                                       $_REQUEST['rev']   = $svnRev;
                                       $_REQUEST['repo']  = $svnRepo;
                                       $messageRev        = $allCheckins[$_REQUEST['releaseLatest']][$svnRev]['message'];
                                       $response          = $dataObj->getSVNFiles($_REQUEST['rev'],$_REQUEST['branch'],$_REQUEST['checkin_message'],$_REQUEST['time'],true);
                                       $url               = "http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?customQuery=&svnrevselect2=&deploy=".$branchToRegion[$_REQUEST['releaseLatest']]."&svnrev=$svnRev&svnrevselect=".$_REQUEST['releaseLatest']."|$svnRev".$branch."&svnfiles=".urlencode($response).$extra;
                                       if ($allCheckins[$_REQUEST['releaseLatest']][$svnRev]['message']=='Unknown comment')
                                       {
                                          die('
                                          <script>
                                             var response = window.prompt("No SVN Comment. Enter A Comment/Project","Enter A Comment");
                                             document.location = "'.$url.'&deploy_message=" + escape(response);
                                          </script>');
                                       }
                                       else
                                       {
                                          $url .= "&deploy_message=".urlencode($messageRev)."&project_name_hidden=".urlencode($messageRev)."&project_name_hidden=".urlencode($messageRev);
                                       }
                                       header("Location: $url");
                                       exit;
                                    }


                                    // -- hack for getting a CSV of checkin items
                                    $branchName     = (empty($_GET['branch'])) ? $dataObj->svn_default_branch_name : $_GET['branch'];
                                    $searchCriteria = $_REQUEST['search'];
                                    if (!empty($searchCriteria))
                                    {
                                       foreach ($allCheckins[$branchName] as $revision=>$checkinMessageArr)
                                       {
                                          $checkinMessage = $checkinMessageArr['message'];
                                          if (strpos($checkinMessage,$searchCriteria) !== false)
                                          {
                                             $revsSearched[] = $revision;
                                             $revsSearched2[$revision] = $checkinMessage;
                                          }
                                       }
                                       if (sizeof($revsSearched) > 0)
                                       {
                                          echo "Here is your release:<br/><br/>";
                                          echo implode(",",$revsSearched);exit;
                                       }
                                    }

                                    if (!empty($svnOptions))
                                    {
                                       $svnOptions = array_reverse($svnOptions);
                                       $svnOptionsHTML = implode("\n",$svnOptions);
                                       $trunkSelect = "Select a Trunk Checkin";
                                    }
                                    else
                                    {
                                       $trunkSelect = "Error May Not Be Connected To SVN ({$svnRepo}trunk/)";
                                    }

                                    if (!empty($svnOptionsBranch))
                                    {
                                       $svnOptionsBranch = array_reverse($svnOptionsBranch);
                                       $svnOptionsBranchHTML = implode("\n",$svnOptionsBranch);
                                       $branchSelect = "Select a Branch Checkin";
                                    }
                                    else
                                    {
                                       $branchSelect = "Error May Not Be Connected To SVN ({$svnRepo}branches/$dataObj->svn_default_branch_name/)";
                                    }
                                    //var branch = \'\'; if ($(\'branch\').disabled == false) { branch = \'trunk\'; } else { branch = \''.$dataObj->svn_default_branch_name.'\'; } getCheckins(branch,$(\'svnsearch\').value);
                                    $svnHTML = '
                                    <tr class="deployment">
                                          <td>SVN Rev#<br/>(CSV,REV-REV):</td>
                                          <td>
                                             <table style="border:0;">
                                                <tr>
                                                   <td style="padding: 0px;">

                                                      <input  onkeyup=\'$("mainForm").method= "POST";\' type="text" style="font-size: 20px; margin-top: 14px;" value="'.str_replace("Completed: At revision: ","",$_GET['revision']).'" name="svnrev" id="svnrev" onblur="changeSVN(\''.$dataObj->svn_default_branch_name.'\',this,false);"/>
                                                   </td>
                                                   <td style="padding: 0px;">
                                                      <input type="button" style="font-size:20px;" onclick="changeSVN(\''.$dataObj->svn_default_branch_name.'\',jq(\'#svnrev\'),true);" id="fetch_revs" value="Fetch Rev"/><input type="button" style="font-size:20px;" onclick="document.location = \'index.php?branch='.$_REQUEST['branch'].'&search=\' + jq(\'#svnrev\').val();" id="fetch_revs" value="Search Rev"/>
                                                   </td>
                                                </tr>
                                             </table>
                                          </td>
                                    </tr>

                                    <tr class="deployment">
                                          <td>Checkins Trunk:</td><td><select onkeyup="this.onchange();" onkeydown="this.onchange();" name="svnrevselect" id="svnrevselect" onchange="changeSVN(\'trunk\',this,false);"/>
                                             <option value="">'.$trunkSelect.'</option>
                                             '.$svnOptionsHTML.'
                                          </select>
                                          <span id="trunk_search"></span>
                                          </td>
                                    </tr>
                                    <tr class="deployment">
                                          <td>Checkins Branch "'.$dataObj->svn_default_branch_name.'":</td><td><select onkeyup="this.onchange();" onkeydown="this.onchange();" name="svnrevselect2" id="svnrevselect2" onchange="changeSVN(\''.$dataObj->svn_default_branch_name.'\',this,false)"/>
                                             <option value="">'.$branchSelect.'</option>
                                             '.$svnOptionsBranchHTML.'
                                          </select>
                                          <span id="'.$dataObj->svn_default_branch_name.'_search"></span>
                                          </td>
                                    </tr>';
                                 }

                                 $this->current_database = $dataObj->maintenance_database;

                                 if ($dataObj->default_region == 'INTEGRATION')
                                 {
                                    $selInt = "selected";
                                 }
                                 elseif ($dataObj->default_region == 'ALL')
                                 {
                                    $selAll = "selected";
                                 }
                                 elseif ($dataObj->default_region == 'INTEGRATION-STAGING')
                                 {
                                    $selIntProd = "selected";
                                 }
                                 elseif ($dataObj->default_region == 'TEST')
                                 {
                                    $selTest = "selected";
                                 }
                                 elseif ($dataObj->default_region == 'ALL_NON_PRODUCTION')
                                 {
                                    $selTestInt = "selected";
                                 }
                                 elseif ($dataObj->default_region == 'TEST_STAGING')
                                 {
                                    $selStage = "selected";
                                 }
                                 elseif ($dataObj->default_region == 'PRODUCTION_STAGING')
                                 {
                                    $selProdStage = "selected";
                                 }
                                 elseif ($dataObj->default_region == 'PRODUCTION')
                                 {
                                    $selProd = "selected";
                                 }


                                 if (! $dataObj->svn_root)
                                 {
                                    $messageHTML =
                                    '<tr>
                                          <td>Message:</td><td>
                                             <textarea onkeyup=\'$("mainForm").method= "POST";\' name="deploy_message" id="deploy_message" style="width:342px;heigh:99px;">'.$dataObj->lastDeploymentMessage.'</textarea>
                                          </td>
                                     </tr>';

                                 }
                                 $preferredBranches = '';
                                 if (!empty($dataObj->svn_preferred_branches))
                                 {
                                    $preferredBranches = '<select onchange="document.location=\'?branch=\' + this.value;"><option value="">Select Preferred</option>';
                                    foreach ($dataObj->svn_preferred_branches as $branch)
                                    {
                                       $sel = '';
                                       if ($_GET['branch'] == $branch)
                                       {
                                          $sel = 'selected';
                                       }
                                       $preferredBranches .= "<option $sel value=\"$branch\">$branch</option>";
                                    }
                                    $preferredBranches .= "</select>";
                                 }

                                 if ($_REQUEST['mark_project'])
                                 {
                                    $dataObj->UpdateProjectAsComplete($_REQUEST['mark_project']);
                                    $maintenanceForm .= $dataObj->messageHTML('Project #'.$_REQUEST['mark_project'].' was marked as completed.','success');
                                 }

                                 $maintenanceForm .= '
                                    <h3 style="position:absolute;left:540px;margin-top:10px;" id="deployment_maintenance_desc">SUBMIT NEW</br>DEPLOYMENT MAINTENANCE</h3>
                                    <table>
                                    <tr>
                                       <td>Run In:</td><td>
                                       <select name="deploy" id="deploy" onkeydown=\'this.onchange();\' onkeyup=\'this.onchange();$("mainForm").method= "POST";\' onchange="
                                       if (this.value == \'ALL\' || this.value == \'PRODUCTION\')
                                       {
                                          $(\'customRegions\').style.display = \'none\';
                                          DeselectAllList($(\'customRegions\'));
                                       }
                                       else if (this.value == \'CUSTOM\')
                                       {
                                          $(\'customRegions\').style.display = \'inline\';
                                          jq(\'#deployment_maintenance_desc\').html(\'\');
                                       }
                                       else
                                       {
                                          $(\'customRegions\').style.display = \'none\';
                                          DeselectAllList($(\'customRegions\'));
                                       }" >
                                          <option value="">Select One</option>
                                          <option '.$selAll.' value="ALL">ALL</option>
                                          <option value="CUSTOM">CUSTOM</option>
                                          <option '.$selTest.' value="TEST" style="color:green;">TEST</option>
                                          '.$customRegionsTest.'
                                          <option '.$selInt.' value="INTEGRATION" style="color:brown;">INTEGRATION</option>
                                          '.$customRegionsInt.'
                                          <option '.$selIntProd.' value="INTEGRATION-STAGING" style="color:brown;">INTEGRATION-STAGING</option>
                                          <option '.$selStage.' value="TEST_STAGING" style="color:lightblue;">TEST_STAGING</option>
                                          '.$customRegionsStage.'
                                          <option '.$selTestInt.' value="ALL_NON_PRODUCTION" style="color:brown;">ALL_NON_PRODUCTION</option>
                                          <option '.$selProdStage.' value="PRODUCTION_STAGING" style="color:purple;">PRODUCTION_STAGING</option>
                                          <option '.$selProd.' value="PRODUCTION" style="color:red;">PRODUCTION</option>
                                          '.$customRegionsProd.'
                                       </select>
                                       <select style="display:none;" name="customRegions[]" id="customRegions" multiple="mutliple" size="10" style="color:black;">
                                          '.$customRegionsFirst.$customRegions.'
                                       </select>
                                       </td>
                                    </tr>
                                    <tr class="deployment"><td>Deploy Branch:<br/>'.$preferredBranches.'</td><td><br /><a id=\'branch_click\' onclick=\'if($("branch").disabled == true) { $("branch").disabled = false;$("branch_deploy").innerHTML = "Deploying SVN Release using Branch <br/>('.$svnRepo.'branches/'.$dataObj->svn_default_branch_name.')";} else { $("branch").disabled = true;$("branch_deploy").innerHTML = "Deploying SVN Release using Trunk"; }\' href=\'#\'>('.$dataObj->svn_default_branch_name.')</a>:<input type=\'text\' style=\'display:none;\' disabled name=\'branch\' id=\'branch\' value=\''.$dataObj->svn_default_branch_name.'\'/><span id=\'branch_deploy\'></span></td></tr>
                                    <tr class="deployment">
                                       <td>SQL File:</td><td><input type="file" style="font-size:20px;" name="maintenance" id="maintenance"/></td>
                                    </tr>
                                    '.$svnHTML.'
                                    <tr>
                                          <td id="run_query_td1">Run Query:</td>

                                          <td id="run_query_td2" valign="top">
                                          <textarea id="svnfiles" name="svnfiles" style="width:578px;height:150px;display:none;font-size:18px"></textarea>
                                          <div id="filesaffected"></div>
                                          <table id="sql_area">
                                             <tr>
                                                <td>
                                                   <span>Hide Data<input checked type=\'checkbox\' name=\'hideData\' id=\'hideData\'/>
                                                </td>
                                                <td>
                                                   Export CSV<input type=\'checkbox\' checked name=\'exportCSV\' id=\'exportCSV\' onchange=\'if (this.checked == true) { $("hideData").checked = true; } else { $("hideData").checked = false; } \'/></span>
                                                </td>
                                                <td>
                                                   Break Out Files<input type=\'checkbox\' name=\'separateData\' id=\'separateData\' onchange=\'if (this.checked == true) { $("separateData").checked = true; } else { $("separateData").checked = false; } \'/></span>
                                                </td>
                                             </tr>
                                             <tr>
                                                <td>
                                                   <input type="hidden" id="count_type_id" name="count_type_id" value="">
                                                   <span>Add Count Monitor<input onchange=\'$("count_type_id").value = "1";\' type=\'checkbox\' name=\'addZeroCount\' id=\'addZeroCount\'/>
                                                </td>
                                                <td>
                                                   <span>Add Threshold Monitor<input onchange=\'$("count_type_id").value = "2";\' type=\'checkbox\' name=\'addThresholdCount\' id=\'addThresholdCount\'/>
                                                </td>
                                                <td>
                                                   <span>Post Via $_GET<input type=\'checkbox\' onchange=\'$("mainForm").method = "GET";\'/>
                                                </td>
                                                <td>

                                                </td>
                                             </tr>
                                             <tr>
                                                <td colspan="3">
                                                   <textarea onclick="
                                                   $(\'sql_area\').style.border=\'0px solid white\';
                                                   jq(\'#left_pane\').css(\'width\',\'600px\');
                                                   jq(\'.deployment\').fadeOut(\'slow\');
                                                   jq(\'#deploy_message\').val(\'\');
                                                   jq(\'#demo1\').fadeIn(\'slow\');
                                                   jq(\'#run_query_td2, #sql_area\').css(\'width\',\'900px\');
                                                   this.style.width =\'800px\';
                                                   this.style.height=\'600px\';
                                                   if (jq(\'#deploy\').val() != \'CUSTOM\')
                                                   {
                                                      jq(\'#deployment_maintenance_desc\').html(\'RUN SOME SQL IN ... \' + jq(\'#deploy\').val());
                                                   }
                                                   " name=\'customQuery\' id=\'customQuery\' style=\'font-size:20px\'></textarea>
                                                </td>
                                             </tr>
                                          </table>
                                       </td>
                                    </tr>
                                       '.$dataObj->CustomProjectDisplay($allCheckins).'
                                       '.$dataObj->CustomFlagDisplay().'
                                       '.$messageHTML.'
                                    <tr class="deployment">
                                       <td>Debug Mode:</td><td><input type="checkbox" name="debug" /></td>
                                    </tr>
                                    <tr class="deployment">
                                       <td>Auto-Deploy:</td><td><input type="checkbox" checked name="auto_deploy" /></td>
                                    </tr>
                                    </table>
                                    <input type="submit"  style="font-size:20px;" value="RUN MAINTENANCE"/>';
                              }
                              else
                              {
                                 //echo "<span style=\"font-size:20px;\">";
                                 $tmpName = $_FILES['maintenance']['tmp_name'];
                                 $target_path_final = $archiveFolder . $_FILES['maintenance']['name'];
                                 if (!empty($tmpName))
                                 {
                                    if(!move_uploaded_file($tmpName, $target_path_final))
                                    {
                                       die('Could not upload file to '.$archiveFolder);
                                    }
                                    else
                                    {
                                       if ($_REQUEST['debug'])
                                       {
                                          $dataObj->runDeploymentMaintenanceFile($target_path_final,"","","",true,$svnRepo);
                                          $maintenanceForm .= "Email sent with debug/parsing results";
                                       }
                                       else
                                       {
                                          $dataObj->runDeploymentMaintenanceFile($target_path_final,"","","",false,$svnRepo);
                                          $maintenanceForm .= "Email sent with the following results.<br/><br/>".$dataObj->email_body;
                                       }
                                    }
                                 }
                                 else
                                 {
                                    if (!empty($_REQUEST['customQuery']) || !empty($_GET['customQuery']))
                                    {
                                       $sql = $_REQUEST['customQuery'];
                                       $hideData = $_REQUEST['hideData'];
                                       if ($hideData == null)
                                       {
                                          $hideDataFlag = 0;
                                       }
                                       else
                                       {
                                          $hideDataFlag = 1;
                                       }
                                       $exportCSV = $_REQUEST['exportCSV'];
                                       if ($exportCSV == null)
                                       {
                                          $exportCSVFlag = 0;
                                       }
                                       else
                                       {
                                          $exportCSVFlag = 1;
                                       }
                                       $separateData = $_REQUEST['separateData'];
                                       if ($separateData == null)
                                       {
                                          $separateDataFlag = 0;
                                       }
                                       else
                                       {
                                          $separateDataFlag = 1;
                                       }
                                       list($void,$void,$output) = $dataObj->queryDeploymentRegions($sql,$hideDataFlag,$exportCSVFlag,1,$separateDataFlag);
                                       $maintenanceForm .= $output;
                                    }
                                    else
                                    {
                                       if (!empty($_REQUEST['debug']))
                                       {
                                          $dataObj->runDeploymentMaintenanceFile("","","","",true,$svnRepo);
                                          $maintenanceForm .= "Email sent with debug/parsing results<br/><br/>".$dataObj->email_body;
                                       }
                                       else
                                       {
                                          $dataObj->runDeploymentMaintenanceFile("","","","",false,$svnRepo);
                                          $maintenanceForm .= "Email sent with the following results.<br/><br/>".$dataObj->email_body;
                                       }
                                    }
                                 }
                              }
                           }
                           else
                           {
                              $find = array("-TICK-","-LINETOKEN-","-POUNDTOKEN-");
                              $replace = array("'","\n","#");
                              $sqlFile .= "#EMAILS=".$_REQUEST['emails']."\r\n\r\n";
                              $sqlFile .= "#DATABASES=FROM_SELECT_BOX\r\n\r\n";
                              if (sizeof($_REQUEST['mysql']) > 0)
                              {
                                 $sqlFile .= "#DB=mysql\r\n\r\n";
                                 if ($_REQUEST['transaction'] == 'Yes')
                                 {
                                    $sqlFile .= "\r\n\r\n#TRANS-START\r\n\r\n";
                                 }
                                 foreach ($_REQUEST['mysql'] as $k=>$query)
                                 {
                                    $_REQUEST['mysql'][$k] = str_replace($find,$replace,$query);
                                 }
                                 $sqlFile .= implode("\r\n\r\n;;\r\n\r\n",$_REQUEST['mysql']);
                                 if ($_REQUEST['transaction'] == 'Yes')
                                 {
                                    $sqlFile .= "\r\n\r\n#TRANS-END\r\n\r\n";
                                 }
                              }

                              if ($_REQUEST['databases'])
                              {
                                 $sqlFile .= "#BACKUP=".implode(",",$_REQUEST['databases'])."\r\n\r\n";
                              }
                              $filename = $archiveFolder.date("d_m_G_i_s_Y")."_data_maintenace.sql";
                              if (!$handle = fopen($filename, 'a'))
                              {
                                    $maintenanceForm .= "Cannot open file ($filename)";
                              }
                              if (fwrite($handle, $sqlFile) === FALSE)
                              {
                                    $maintenanceForm .= "Cannot write to file ($filename)";
                              }
                              fclose($handle);
                              $finalQueries = "<?php\n\n// -- your php array\n\n";
                              foreach ($_REQUEST['mysql'] as $k=>$query)
                              {
                                 $finalQueries .= "\$queries[] = \"$query\";\n\n";
                              }
                              $email = new EmailSender( "Attached is your Data Maintenance SQL File\n\n$finalQueries");
                              $email->set_from( "deployment_maintenance@".$_SERVER['HTTP_HOST'] );
                              $email->set_content_type( 'text' );
                              $email->add_attachment( basename($filename), $filename ,'text/plain');
                              if (stristr($_REQUEST['emails'],",") !== false)
                              {
                                 $emails = explode(",",$_REQUEST['emails']);
                              }
                              else
                              {
                                 $emails[] = $_REQUEST['emails'];
                              }
                              foreach ($emails as $theemail)
                              {
                                 $email->set_to( $theemail );
                              }
                              $email->set_subject( 'Data Maintenance SQL File');
                              $ret = $email->sendEmail();
                              if ($ret)
                              {
                                 $this->DeleteFile($filename);
                                 $maintenanceForm .= "Your Data Maintenance SQL File has been emailed<br/><br/><textarea>$sqlFile</textarea>";
                              }
                              else
                              {
                                 $maintenanceForm .= "An error occurred emailing.  Please send manually";
                              }
                           }
                        }
                        elseif (isset($_GET['create']))
                        {
                           define("GET_TABLES_SQL", "show full tables");
                           define("GET_DATABASES_SQL", "show databases");
                           $selectOptions .= "<option value=\"\">Select a Project for Query History</option>";

                           // -- get projects from your own custom project listing delimited by line ending
                           // -- unit test screenshots
                           switch($dataObj->special_setup)
                           {
                              case "setup1":
                                 ob_start();
                                 include('../screenshots/fetch_projects.php');
                                 ob_end_clean();
                                 while($row = mysql_fetch_array($results))
                                 {
                                    $selectOptions .= "<option value=\"{$row['task_desc']}\">{$row['task_desc']}</option>";
                                 }
                              break;
                           }

                           /*
                           $projects = file('projects.php');
                           foreach ($projects as $project) {
                              $selectOptions .= "<option value=\"$project\">$project</option>";
                           }
                           */

                           $projectWidget = "
                           <tr>
                              <td>Project To Relate:</td>
                              <td><select id=\"project\" onchange=\"getQueries()\">$selectOptions</select></td>
                           </tr>";
                           $maintenanceForm .= "<form method=\"POST\" id=\"go\" name=\"go\" action=\"http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}\">
                           <input type=\"hidden\" id=\"store_queries\" value=\"0\"/>
                           <table>
                              $projectWidget
                              <tr><td>Email To:</td><td><input type='text' style='width:240px' name='emails' id='emails' value='".implode($this->web_notify['developers'])."'></td></tr>";
                           $maintenanceForm .= "<tr><td>Backup before:</td><td>
                           <select id='databases' multiple name='databases[]' size='10' style='display:none;'><option value=''>Select some tables to backup</option>";

                           $link = mysql_connect($dataObj->server['mysql']['server'],$dataObj->server['mysql']['username'],$dataObj->server['mysql']['password']);
                           $results = mysql_list_dbs($link);
                           while($row = mysql_fetch_array($results))
                           {
                              $database = $row['Database'];
                              if ($database == 'information_schema' || $database == 'mysql')
                              {
                                 continue;
                              }
                              $results2 = mysql_list_tables($database,$link);
                              $maintenanceForm .= "<optgroup label='$database'>";
                              while($tables = mysql_fetch_array($results2))
                              {
                                 $tableName = $tables['Tables_in_'.$database];
                                 $maintenanceForm .= "<option value='$database.$tableName'>$tableName</option>";
                              }
                           }
                           mysql_close($link);
                           $maintenanceForm .= "</select>";
                           $maintenanceForm .= '<span id="dbflag"><input type="radio" onchange="if(this.checked==true) { document.getElementById(\'mysqltmp\').style.height =\'52px\';document.getElementById(\'databases\').style.display =\'inline\'; document.getElementById(\'dbflag\').style.display =\'none\';}" name="db_bool" value="yes"/>Yes<input type="radio" name="db_bool" value="no" checked/>No</span>';
                           $maintenanceForm .= "</td></tr>";
                           $maintenanceForm .= "<tr><td>All Queries In Transaction?</td><td><select name='transaction'><option>Yes</option><option selected>No</option></select></td></tr>";
                           $maintenanceForm .= "<tr>
                                    <td colspan='2'>
                                       MYSQL <span onclick =\"if (document.getElementById('mysqltmp').value != '') {document.getElementById('mysqlcnt').value++;document.getElementById('mysqlSpan').innerHTML += '<input name=\'mysql[]\' value=\'' + document.getElementById('mysqltmp').value.replace(new RegExp('\'', 'gi' ), '-TICK-') + '\'>';addQueries('mysql',document.getElementById('mysqltmp').value.replace(new RegExp('\'', 'gi' ), '-TICK-').replace(new RegExp('#', 'gi' ), '-POUNDTOKEN-'));document.getElementById('mysqltmp').value ='';}\" style=\"cursor:pointer;color:#6690BC\">(Add Query)</span>
                                       <br/>
                                       <textarea id='mysqltmp' style='width:480px;height:140px;'></textarea>
                                       <br/>Total Queries: <input type='text' size='1' id='mysqlcnt' value='0'/>
                                       <span id=\"mysqlSpan\" style=\"display:none;\"></span>
                                    </td>
                              </tr>
                              </table>
                              <input type='submit' value='CREATE FILE'/>
                              </form>
                                    ";

                           $sqlCacheHTML = '
                              <td width="30%" id="svnfiles_area" style="padding-top: 162px;">
                                 <div id="demo1" class="demo" style="display:none;">
                                    '.$sqlCache.'
                                 </div>
                              </td>';

                        }

                        if (array_key_exists('username', $_SESSION))
                        {
                           $html .= $maintenanceForm;
                        }
                        else
                        {
                           $html .= $loginForm;
                        }

                              $html .= '
                              </td>
                              '.$sqlCacheHTML.'
                           </tr>
                        </table>
                     </div>
               </td>
            </tr>
         </table>
         </div>
         </div>
         </form>
         </body>
         </html>
         ';
      }
      else
      {
         if ($_REQUEST['ajax'] == 'getQueries')
         {
            $results = $dataObj->getQueries();
            $cnt=mysql_numrows($results);
            if ($cnt)
            {
               $mysqlCount=0;
               $db2Count=0;
               while($row = mysql_fetch_array($results))
               {
                  if ($row['db_type']=='mysql')
                  {
                     $mysqlCount++;
                     $JS .= "document.getElementById('mysqlSpan').innerHTML += '<input name=\'mysql[]\' value=\'".$row['query']."\'>';";
                  }
               }
               $html .=
               "
               $('mysqlcnt').value = '$mysqlCount';
               $('store_queries').value='1';
               alert(\"Loaded $mysqlCount mysql queries to your deployment maintenance form.\");
               $JS
               ";
            }
            else
            {
               $html .= "
               if (window.confirm(\"No queries found for this project '{$_REQUEST['project']}'.  Would you like to continuously add new queries?  This will help so you can maintain history and add new queries as they come up in a project and you can come back here and restore your deployment maintenance queries.\")) {
                  $('store_queries').value='1';
               } else {
                  $('store_queries').value='0';
               }
               ";
            }

         }
         elseif ($_REQUEST['ajax'] == 'svnrev')
         {
            $html .= $dataObj->getSVNFiles($_REQUEST['rev'],$_REQUEST['branch'],$_REQUEST['checkin_message'],$_REQUEST['time'],false);
         }
         elseif ($_REQUEST['ajax'] == 'save_block_info')
         {
            $html .= $dataObj->EditImpactedBlockOfCode();
         }
         elseif ($_REQUEST['ajax'] == 'get_project_status')
         {
            $html .= $dataObj->GetProjectStatus($_GET['project']);
         }
         elseif ($_REQUEST['ajax'] == 'getcheckins')
         {
            if ($dataObj->svn_last_x_months)
            {
               $extraFlags = '-r {'.date('Y-m-d',strtotime("-".$dataObj->svn_last_x_months." month")).'}:HEAD';
            }
            if ($_REQUEST['repo'] == 'trunk')
            {
               exec('svn log '.$svnRepo.'trunk/ '.$extraFlags.' > svnlog.txt');
            }
            else
            {
               exec('svn log '.$svnRepo.'branches/'.$_REQUEST['repo'].' '.$extraFlags.' > svnlog.txt');
            }
            $filesModified = file('svnlog.txt');
            $this->DeleteFile('svnlog.txt');
            if (is_array($filesModified))
            {
               foreach ($filesModified as $file)
               {
                  if (substr($file,0,1) == 'r' && stristr($file,"|"))
                  {
                     list($rev,$user,$stamp,$lines) = explode("|",$file);
                     $rev = str_replace("r","",trim($rev));
                     $user = trim($user);
                     $stamp = substr(trim($stamp),0,19);
                     $svnOptions[] = "<option value=\"$place|$rev\">$place - Rev #$rev - by $user - on $stamp</option>";

                  }
                  elseif (!stristr($file,"----") && trim(str_replace("\n","",$file)) != "")
                  {
                     $svnOptions[] = "<option disabled>&#160;&#160;&#160;Rev#$rev:\"".substr($file,0,60)."\" &darr;</option>";
                  }
               }
            }

            if ($_REQUEST['repo'] == 'trunk')
            {
               if (!empty($svnOptions))
               {
                  $svnOptions = array_reverse($svnOptions);
                  $svnOptionsHTML = implode("\n",$svnOptions);
                  $trunkSelect = "Select a {$_REQUEST['repo']} Checkin";
               }
               else
               {
                  $trunkSelect = "Error May Not Be Connected To SVN ({$svnRepo}trunk/)";
               }
            }

            $html = '<select onkeyup="this.onchange();" onkeydown="this.onchange();" name="svnrevselectmulti" id="svnrevselectmulti" onchange="alert(0);" multiple="multiple" size="10"/>
               <option value="">'.$trunkSelect.'</option>
               '.$svnOptionsHTML.'
            </select>';

         }
         elseif ($_REQUEST['ajax'] == 'addQueries')
         {
            $dataObj->addQueries();
            if ($dataObj->database_error || $dataObj->all_errors)
            {
               $html .= "An error occurred inserting this record.  ".$dataObj->database_error."  ".implode(",",$dataObj->all_errors);
            }
            else
            {
               $html .= "Added 1 query to mysql ".$dataObj->current_database.".".$dataObj->table_names['sql']." table.";
            }
         }
      }
      $this->html = $html;
   }
}


function DeploymentMaintenanceCron ($dataObj)
{
   $mainFolder = getcwd().'/';
   $files = getDeploymentDirectoryFiles($mainFolder."inbound/");
   if (is_array($files))
   {
      foreach ($files as $file)
      {
         $dataObj->runDeploymentMaintenanceFile($file);
         if (!rename($file,$mainFolder."archive/".basename($file)))
         {
            if (!$this->DeleteFile($file))
            {
               echo "Couldnt Move File","File:\n\n".$file."\n\nCouldnt be moved to:".$mainFolder."archive/".basename($file)."\n\nThe file couldnt be deleted either.  So it is going to be picked up on the next RUN!!!  Please manually delete.";
            }
         }
      }
   }

   // -- clean up last weeks mySQL table dumps
   $files = array();
   $index = array();
   $lastWeek = strtotime('-1 week');
   if ($handle = opendir($mainFolder."database_backups/"))
   {
      clearstatcache();
      while (false !== ($file = readdir($handle)))
      {
         if ($file != "." && $file != "..")
         {
            $files[] = $file;
            $index[] = filemtime( $mainFolder."database_backups/".$file );
         }
      }
      closedir($handle);
   }
   if (is_array($index))
   {
      asort( $index );
      foreach($index as $i => $t)
      {
         if($t < $lastWeek)
         {
            $this->DeleteFile($mainFolder."database_backups/".$files[$i]);
         }
      }
   }
}

function getDeploymentDirectoryFiles($dir)
{
   if (!is_dir($dir))
   {
      echo "Error: \"$dir\" is not a valid deployment type and directory and we cannot get any files out of that directory\n";
   }
   $dhandle = opendir($dir);
   $files = array();
   if ($dhandle)
   {
      while (false !== ($fname = readdir($dhandle)))
      {
         if (($fname != '.') && ($fname != '..') && ($fname != 'stub') && ($fname != '.metadata'))
         {
            if ( !is_dir( "$dir/$fname" ) )
            {
               $files[] = $dir.$fname;
            }
         }
      }
      closedir($dhandle);
   }
   return $files;
}
?>
