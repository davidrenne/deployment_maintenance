<?php
// -- set database that contains the deployment_maintenance table
define('CLIENT_LONG_PASSWORD',1);         /* new more secure passwords */
define('CLIENT_FOUND_ROWS',2);            /* Found instead of affected rows */
define('CLIENT_LONG_FLAG',4);             /* Get all column flags */
define('CLIENT_CONNECT_WITH_DB',8);       /* One can specify db on connect */
define('CLIENT_NO_SCHEMA',16);            /* Don't allow database.table.column */
define('CLIENT_COMPRESS',32);             /* Can use compression protocol */
define('CLIENT_ODBC',64);                 /* Odbc client */
define('CLIENT_LOCAL_FILES',128);         /* Can use LOAD DATA LOCAL */
define('CLIENT_IGNORE_SPACE',256);        /* Ignore spaces before '(' */
define('CLIENT_PROTOCOL_41',512);         /* New 4.1 protocol */
define('CLIENT_INTERACTIVE',1024);        /* This is an interactive client */
define('CLIENT_SSL',2048);                /* Switch to SSL after handshake */
define('CLIENT_IGNORE_SIGPIPE',4096);     /* IGNORE sigpipes */
define('CLIENT_TRANSACTIONS',8192);       /* Client knows about transactions */
define('CLIENT_RESERVED',16384);          /* Old flag for 4.1 protocol */
define('CLIENT_SECURE_CONNECTION',32768); /* New 4.1 authentication */
define('CLIENT_MULTI_STATEMENTS',65536);  /* Enable/disable multi-stmt support */
define('CLIENT_MULTI_RESULTS',131072);    /* Enable/disable multi-results */
$dataObj = new DeploymentMaintenance();
$dataObj->base_url                        = "http://yourserver.com/deployment_maintenance/index.php";
$dataObj->server['mysql']['server']       = "main_db_server_name";
$dataObj->server['mysql']['username']     = "username";
$dataObj->server['mysql']['password']     = "password";
$dataObj->server['mysql']['client_flags'] = CLIENT_FOUND_ROWS + CLIENT_MULTI_STATEMENTS + CLIENT_MULTI_RESULTS;

// -- large group concat support configuration
$dataObj->mysql_session_preferences[] = 'group_concat_max_len=4294967295';
$dataObj->mysql_session_preferences[] = 'max_allowed_packet=1073741824';
// -- low priority updates dont seem to work right in this script.  USE Create CMD Line Script checkbox, it will create an inline SQL script with this directive with all your associated regions you have selected.
$dataObj->mysql_session_preferences[] = 'low_priority_updates=1';

// -- block anyone from ever sending up the same file twice.
$dataObj->allow_same_file_to_region_twice = false;

// -- block anyone from ever sending up the same file twice.
$dataObj->poll_threads_and_throttle = false;

// -- if you use a slave, enter below.  else comment out
$dataObj->server['mysql']['use_slave'] = "main_slave_server_name";

// -- during deployment, send results to various people based on where new code is being deployed
if (in_array('PRODUCTION',array($argv[1],$_POST['deploy'])) || in_array('ALL',array($argv[1],$_POST['deploy'])) )
{
   $dataObj->web_notify['developers'] = array("developers@yourdomain.com");
   $dataObj->web_notify['support']    = array("support@yourdomain.com");
}
else
{
   $dataObj->web_notify['developers'] = array("developers@yourdomain.com");
}

// -- debug email events
/*$dataObj->web_notify['developers'] = "xxxxx@xxxxx.com";
$dataObj->web_notify['support'] = array("xxxxx@xxxxx.com");*/

// -- svn location
$dataObj->svn_root              = 'http://mysvnserver/myroot/';

// -- special flag to do customizations
$dataObj->special_setup             = false;

// -- how many months back do you want to list checkins for release?
$dataObj->svn_last_x_months     = "2";

// -- deployment_maintenance will always allow trunk release and one other alternative branch to release from
$dataObj->svn_default_branch_name   = "dev";

// -- you can also toggle between other branches as your "dev branch" in a select box in the main form
$dataObj->svn_preferred_branches= array(
                                                 'dev',
                                        );

// -- if you enter in multiple indexes here.  the listing of files will be skipped with a stristr() for each index
$dataObj->svn_skip_like_these   = array(
                                                 '',
                                       );

// -- release these files first (function definions).  include full path output by green list box of files
$dataObj->svn_first_files       = array(
                                                 '',
                                       );

// -- release these last
$dataObj->svn_last_files        = array(
                                                 '',
                                       );

// -- i think mostly used in the quick link autodeployments
$dataObj->svn_preferred_deploy  = array(
                                                'dev'                   => 'INTEGRATION',
                                        );

// -- fatal error out if someone tries to deploy from a branch other than the below
$dataObj->svn_block_deploy      = array(
                                                'PRODUCTION'    => array('branches/dev'),
                                        );

// -- skip deploying trunk files to these regions
$dataObj->svn_skip_deploy       = array(
                                                'INTEGRATION'   => array('trunk'),
                                        );

// -- at the command line when an SVN export is pulling files, you usually need to pass a username via exec()
$dataObj->svn_user              = 'username';
$dataObj->svn_local_comparison_tool   = "C:\\Program Files (x86)\\Beyond Compare 3\\BCompare.exe";
$dataObj->svn_local_base_path1        = "C:\\wamp\\www\\home\\sites\\dev\\";
$dataObj->svn_local_base_path2        = "C:\\wamp\\www\\home\\sites\\trunk\\";

// -- you might need to adjust what happens when deployment_maintenance is building a shell script to execute and one of the actions hard coded in the class is a chown action against each file
$dataObj->chown_user            = 'username';

// -- specify the database where the views and tables reside
$dataObj->maintenance_database  = 'deployment_maintenance';

// -- create an array of folders in your file system that have "ticket names" for usage to tag releases with projects
$dataObj->project_folder_paths  = array(
                                                '/path/to/projects/folders',
                                       );

// -- here are the default table names
$dataObj->table_names           = array(
                                                'clients'              => 'deployment_maintenance_clients',
                                                'checkins'             => 'deployment_maintenance_checkins',
                                                'clients_updates'      => 'deployment_maintenance_clients_updates',
                                                'users'                => 'deployment_maintenance_users',
                                                'sql'                  => 'deployment_maintenance_sql',
                                                'counts'               => 'deployment_maintenance_table_counts',
                                                'files'                => 'deployment_maintenance_files',
                                                'functions'            => 'deployment_maintenance_function_info',
                                                'function_changes'     => 'deployment_maintenance_function_changes',
                                                'function_locations'   => 'deployment_maintenance_function_locations',
                                                'page_features'        => 'deployment_maintenance_page_features',
                                                'page_feature_changes' => 'deployment_maintenance_page_feature_changes',
                                                'blocks_affected'      => 'deployment_maintenance_blocks_affected',
                                       );

// -- output a warning upon release when these are found
$dataObj->code_warnings        = array(
                                                'DebugBreak(',
                                                'console.log('
                                       );

// -- here are the default view names

$dataObj->view_names           = array(
                                                'distinct_projects'      => 'clients_distinct_projects',
                                                'sql_counts'             => 'clients_weekly_sql_counts',
                                                'testing_list'           => 'clients_projects_waiting_verification_testing',
                                                'prod_releases'          => 'clients_weekly_production_releases',
                                                'test_releases'          => 'clients_weekly_development_efforts',
                                                'test_prod'              => 'clients_weekly_production_and_dev_releases',
                                                'project_listing'        => 'clients_projects_all_overview',
                                                'project_listing_all'    => 'clients_projects_all',
                                                'project_minutes'        => 'clients_project_minutes_all_by_date',
                                                'project_minutes_weekly' => 'clients_project_minutes_weekly',
                                       );

// -- when page loads which region is selected first
$dataObj->default_region        = 'INTEGRATION';

// -- if the web server is using fast CGI with -flush enabled.  this will keep it alive so HTML is always shown on long running processes
$dataObj->is_fast_cgi = false;

// -- if your project/checkins begin with a number a regex will link to your ticketing system
// -- example project name/checkin would be "743 - My Project Message".  This would pass http://google.com/?project_id=743 and store 743 as the project for all releases in deployment_maintenance_clients_updates
$dataObj->ticketing_system_url  = 'http://google.com/?project_id=';

// -- email off a tar ball of the deployment
$dataObj->tarball_peer_review   = true;

// -- how to parse your checkin/project matches with your project ID. see above description for example
$dataObj->project_preg_matches = array(
                                       '(^[0-9]{1,5})'
                                       );

// -- for percent change of any one table (lets say you want to monitor records dropping out by a percentage), you have a monitor record in place that if a table is deleted or drops out of your query greater or less than your specifications, send a warning to the emails
// -- FYI $percentChange value is on the LEFT side for the eval() inside of AutoDeployment.php for this string so < -10 is any table that was deleted with more than 10% of it's records because the percent will be negative you would use "<"

$dataObj->percent_change = '< -10';
$dataObj->percent_change_execution_time = 6;

// -- start and end of day is for automatic time tracking capabilities
$dataObj->developer_start_of_day = '08';
$dataObj->developer_end_of_day   = '17';

// -- For menu tabs
$dataObj->view_actions           = 'CONCAT("<!--CUSTOM--><a href=\''.$dataObj->ticketing_system_url.'",project_id,"\'>(View Ticket)</a>") as actions,';
$dataObj->menu_queries         = array(
                                                'Test Queue'          => urlencode('SELECT '.str_replace('<!--CUSTOM-->','<a href=\''.$dataObj->base_url.'?mark_project=",project_id,"\'>(Mark As Complete)</a><br /><br />',$dataObj->view_actions).' a.* FROM '.$dataObj->maintenance_database.'.'.$dataObj->view_names['testing_list']).' a&lookup_mapping_columns[project_id]=deployment_project_id&lookup_mapping_table[project_id]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_listing_all'].'&lookup_mapping_columns[developer]=deployment_username&lookup_mapping_table[developer]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_listing_all'],
                                                'Projects'            => urlencode('SELECT '.str_replace('<!--CUSTOM-->','',$dataObj->view_actions).' a.* FROM '.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_listing']).' a&lookup_mapping_columns[project_id]=deployment_project_id&lookup_mapping_table[project_id]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_listing_all'].'&lookup_mapping_columns[developer]=deployment_username&lookup_mapping_table[developer]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_listing_all'],
                                                'Prod Releases'       => urlencode('SELECT '.str_replace('<!--CUSTOM-->','',$dataObj->view_actions).' a.* FROM '.$dataObj->maintenance_database.'.'.$dataObj->view_names['prod_releases']).' a&lookup_mapping_columns[project_id]=deployment_project_id&lookup_mapping_table[project_id]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_listing_all'].'&lookup_mapping_columns[developer]=deployment_username&lookup_mapping_table[developer]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_listing_all'],
                                                'Test Releases'       => urlencode('SELECT '.str_replace('<!--CUSTOM-->','',$dataObj->view_actions).' a.* FROM '.$dataObj->maintenance_database.'.'.$dataObj->view_names['test_releases']).' a&lookup_mapping_columns[project_id]=deployment_project_id&lookup_mapping_table[project_id]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_listing_all'].'&lookup_mapping_columns[developer]=deployment_username&lookup_mapping_table[developer]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_listing_all'],
                                                'Dev Minutes'       => urlencode('SELECT '.str_replace('<!--CUSTOM-->','',$dataObj->view_actions).' a.* FROM '.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_minutes']).' a&lookup_mapping_columns[project_id]=project_id&lookup_mapping_table[project_id]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_minutes'].'&lookup_mapping_columns[developer]=developer&lookup_mapping_table[developer]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_minutes'].'&lookup_mapping_columns[date_of_development]=date_of_development&lookup_mapping_table[date_of_development]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_minutes'].'&lookup_mapping_columns[week_of_year]=week_of_year&lookup_mapping_table[week_of_year]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_minutes'].'&lookup_mapping_columns[project_status]=project_status&lookup_mapping_table[project_status]='.$dataObj->maintenance_database.'.'.$dataObj->view_names['project_minutes'].'&lookup_mapping_columns[revisions]=revision&lookup_mapping_table[revisions]='.$dataObj->maintenance_database.'.'.$dataObj->table_names['blocks_affected'],
                                      );

// -- use in a case of calling pages where you want someone to paste in a CSV or other input that you want to pass to a canned query below
$dataObj->predefined_queries  = array(
                                       "XXXXXXX"=>"SELECT * FROM XXXXXXX WHERE XXXXXXX IN (<!--INPUT-->)",
                                     );
// -- routing rules will change the connection when you read from these tables when any query contains these, your connection will go to the appropriate server

$dataObj->routing_rules['master'] = array('my_master_only_mysql_table');
$dataObj->routing_rules['slave']  = array('my_slave_only_mysql_table');

// -- if you wish to shut down count check monitors after each email event set to true.
$dataObj->monitor_disable  = false;

// -- for project/checkin messages.  If you put !! or any other keyword.  The release will not be emailed to anyone.
$dataObj->release_email_skip_string  = '!!';

// -- for a little extra time/CPU deploymentMaintenance will compare blocks of your code by project/checkin and tag blocks of code with the project
$dataObj->compare_blocks_of_code  = true;


$dataObj->configure();
?>