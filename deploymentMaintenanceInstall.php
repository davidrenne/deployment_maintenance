<?php

echo <<<EOD

<pre>
--      create 3 directories
--      inbound
--      archive
--      database_backups
--      svndeploy
--    chmod 777 inbound and svndeploy folder

--      or run this
--      mkdir inbound && mkdir archive && mkdir database_backups && mkdir svndeploy && chmod 777 inbound && chmod 777 svndeploy

--     Next install this in your mysql database

CREATE DATABASE `{$dataObj->maintenance_database}`;

;;

USE `{$dataObj->maintenance_database}`;

;;

CREATE TABLE IF NOT EXISTS `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients']}` (
   `id` int(11) NOT NULL auto_increment,
   `name` varchar(100) NOT NULL,
   `deployment_type` enum('TEST','INTEGRATION','PRODUCTION','CUSTOM','PRODUCTION_STAGING') NOT NULL default 'PRODUCTION',
   `host_name` varchar(100) NOT NULL,
   `host_path` varchar(100) NOT NULL,
   `host_user` varchar(100) NOT NULL,
   `host_pass` varchar(100) NOT NULL,
   `db_host` varchar(100) NOT NULL,
   `db_name` varchar(100) NOT NULL,
   `db_user` varchar(100) NOT NULL,
   `db_pass` varchar(100) NOT NULL,
   `created` timestamp NULL default CURRENT_TIMESTAMP,
   `modified` datetime default NULL,
   `active` int(11) NOT NULL default '1',
   PRIMARY KEY  (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

;;

CREATE TABLE `{$dataObj->maintenance_database}`.`{$dataObj->table_names['sql']}`
(
   `id` INT( 11 ) NOT NULL AUTO_INCREMENT PRIMARY KEY ,
   `project` VARCHAR( 150 ) NOT NULL ,
   `query` MEDIUMTEXT NOT NULL ,
   `db_type` VARCHAR( 20 ) NOT NULL
) ENGINE = InnoDB

;;

CREATE TABLE `{$dataObj->maintenance_database}`.`{$dataObj->table_names['files']}` (
`id` INT( 11 ) NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`file_path` TEXT NOT NULL ,
`file_type` ENUM( 'PAGE', 'CSS', 'FUNC_DEF', 'AJAX' ) NOT NULL DEFAULT 'PAGE',
`file_description` TEXT NOT NULL
) ENGINE = InnoDB;

;;

CREATE TABLE IF NOT EXISTS `{$dataObj->maintenance_database}`.`{$dataObj->table_names['function_locations']}` (
  `id` int(11) NOT NULL auto_increment,
  `file_id` int(11) NOT NULL,
  `function_id` int(11) NOT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

;;

CREATE TABLE `{$dataObj->maintenance_database}`.`{$dataObj->table_names['functions']}` (
`function_id` INT( 11 ) NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`function_name` VARCHAR( 60 ) NOT NULL ,
`last_modified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ,
`number_times_changed` INT( 11 ) NOT NULL ,
`function_purpse_description` TEXT NOT NULL
) ENGINE = InnoDB;

;;

CREATE TABLE IF NOT EXISTS `{$dataObj->maintenance_database}`.`{$dataObj->table_names['function_changes']}` (
  `id` int(11) NOT NULL auto_increment,
  `function_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `change_description` varchar(255) NOT NULL,
  `change_time` timestamp NOT NULL default CURRENT_TIMESTAMP,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

;;

CREATE TABLE IF NOT EXISTS `{$dataObj->maintenance_database}`.`{$dataObj->table_names['page_feature_changes']}` (
  `id` int(11) NOT NULL auto_increment,
  `page_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `change_description` varchar(255) NOT NULL,
  `change_time` timestamp NOT NULL default CURRENT_TIMESTAMP,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

;;

CREATE TABLE `{$dataObj->maintenance_database}`.`{$dataObj->table_names['page_features']}` (
`id` INT( 11 ) NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`page_id` INT( 11 ) NOT NULL ,
`feature_description` TEXT NOT NULL ,
`created_on` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB;

;;

CREATE TABLE IF NOT EXISTS `{$dataObj->maintenance_database}`.`{$dataObj->table_names['counts']}` (
  `id` int(11) NOT NULL auto_increment,
  `database_name` varchar(150) NOT NULL,
  `query_run` text NOT NULL,
  `date_of_count` date NOT NULL,
  `total_rows` int(11) NOT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

;;


CREATE TABLE IF NOT EXISTS `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients_updates']}` (
  `id` int(11) NOT NULL auto_increment,
  `deployment_results` mediumtext NOT NULL,
  `deployment_type` text NOT NULL,
  `deployment_message` varchar(255) NOT NULL,
  `deployment_sql_file` varchar(255) NOT NULL,
  `deployment_hosts` mediumtext NOT NULL,
  `deployment_databases` mediumtext NOT NULL,
  `deployment_files` mediumtext NOT NULL,
  `deployment_file_hashes` mediumtext NOT NULL,
  `deployment_local_files` mediumtext NOT NULL,
  `deployment_time` timestamp NOT NULL default CURRENT_TIMESTAMP,
  `deployment_emailed_to` varchar(100) NOT NULL,
  `deployment_svn` varchar(255) NOT NULL,
  `deployment_svn_branch` varchar(255) NOT NULL,
  `deployment_username` varchar(20) NOT NULL,
  `deployment_project` varchar(255) NOT NULL,
  `deployment_project_id` varchar(30) NOT NULL,
  `deployment_project_status` enum('CANCELED','ON HOLD','ENVISIONING','DEVELOPMENT','READY FOR TESTING','DEV/UNIT TESTING','IN PRODUCTION','ROLLED BACK') NOT NULL default 'DEVELOPMENT',
  `deployment_cron_count_check` tinyint(1) NOT NULL default '0',
  `deployment_cron_count_check_time` timestamp NULL default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

;;

CREATE TABLE IF NOT EXISTS `{$dataObj->maintenance_database}`.`{$dataObj->table_names['users']}` (
   `id` int(11) NOT NULL auto_increment,
   `username` varchar(50) NOT NULL,
   `password` varchar(50) NOT NULL,
   `email` varchar(100) NOT NULL,
   `created` datetime default NULL,
   `modified` datetime default NULL,
   PRIMARY KEY  (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

;;

CREATE TABLE IF NOT EXISTS `{$dataObj->maintenance_database}`.`{$dataObj->table_names['blocks_affected']}` (
  `id` int(11) NOT NULL auto_increment,
  `hash_id` varchar(100) NOT NULL,
  `file_name` VARCHAR( 250 ) NOT NULL,
  `branch` varchar(50) NOT NULL,
  `project_id` int(11) NOT NULL,
  `line_info` varchar(250) NOT NULL,
  `block_of_code` text NOT NULL,
  `block_of_code_modified_time` TIMESTAMP NULL DEFAULT NULL,
  `revision` varchar(100) NOT NULL,
  `testing_title` VARCHAR( 250 ) NOT NULL,
  `testing_response` TEXT NOT NULL,
  `testing_description` TEXT NOT NULL,
  `testing_flag` enum('TESTING_DONE','NEEDS_MORE_DEV_TESTING','NEEDS_VERIFICATION','UNIT_TESTED') NOT NULL,
  `change_type_flag` ENUM( 'NONE', 'BUG_FIX', 'REFACTORED', 'REQUIREMENT', 'MAINTENANCE', 'ZERO_IMPACT' ) NOT NULL DEFAULT 'NONE',
  `developer` varchar(50) NOT NULL,
  `date_added` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
  `ignore_flag` TINYINT( 1 ) NOT NULL DEFAULT '0',
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1;

;;

ALTER TABLE `{$dataObj->maintenance_database}`.`{$dataObj->table_names['blocks_affected']}` ADD INDEX ( `project_id` )


;;


CREATE TABLE IF NOT EXISTS `{$dataObj->maintenance_database}`.`{$dataObj->table_names['checkins']}` (
  `id` int(11) NOT NULL auto_increment,
  `branch` varchar(50) NOT NULL,
  `project_id` int(11) NOT NULL,
  `rev_modified_time` TIMESTAMP NULL DEFAULT NULL,
  `revision` varchar(100) NOT NULL,
  `checkin_message` TEXT NOT NULL,
  `minutes_logged` INT( 11 ) NOT NULL,
  `developer` varchar(50) NOT NULL,
  `date_added` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1;

;;

ALTER TABLE `{$dataObj->maintenance_database}`.`{$dataObj->table_names['checkins']}` ADD INDEX ( `project_id` )

;;


-- Views and sample data

USE `{$dataObj->maintenance_database}`;

;;



CREATE OR REPLACE VIEW `{$dataObj->view_names['test_prod']}` AS
SELECT
   'PROD RELEASES' as release_type,
   v1.*
FROM
   clients_weekly_production_releases v1
UNION ALL
SELECT
   'TEST RELEASES' as release_type,
   v2.*
FROM
   clients_weekly_development_efforts v2


;;


CREATE OR REPLACE VIEW `{$dataObj->view_names['prod_releases']}` AS
SELECT
   id,
   deployment_project_id as project_id,
   IF(deployment_project='','N/A',deployment_project) as project,
   deployment_project_status as project_status,
   deployment_username as developer,
   deployment_time as release_time,
   'PRODUCTION' release_location,
   deployment_svn as svn_rev,
   IF(deployment_message=deployment_project OR deployment_message ='','N/A',deployment_message)  as message,
   deployment_files as files
FROM
   `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients_updates']}`
WHERE
   `deployment_time` > date_add(CURRENT_TIMESTAMP, interval -7 DAY) AND
   `deployment_type` IN ('ALL','PRODUCTION') AND
   `deployment_type` NOT LIKE ('%ZERO_COUNT_ERR%') AND
   `deployment_type` NOT LIKE ('%PERCENT_THRESHOLD%') AND
   `deployment_message` NOT LIKE '%Files Released%'

;;


CREATE OR REPLACE VIEW `{$dataObj->view_names['test_releases']}` AS
SELECT
   id,
   deployment_project_id as project_id,
   IF(deployment_project='','N/A',deployment_project) as project,
   deployment_project_status as project_status,
   deployment_username as developer,
   deployment_time as release_time,
   IF(deployment_type='CUSTOM', IF(deployment_databases='',deployment_hosts,deployment_databases),deployment_type) as release_location,
   deployment_svn as svn_rev,
   IF(deployment_message=deployment_project OR deployment_message ='','N/A',deployment_message)  as message,
   deployment_files as files
FROM
   `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients_updates']}`
WHERE
   (
      `deployment_time` > date_add(CURRENT_TIMESTAMP, interval -7 DAY) AND
      `deployment_type` NOT IN ('NEW_BUILD','ALL','PRODUCTION') AND
      `deployment_type` NOT LIKE ('%ZERO_COUNT_ERR%') AND
      `deployment_type` NOT LIKE ('%PERCENT_THRESHOLD%') AND
      `deployment_type` NOT LIKE 'QUERY_IN%' AND
      `deployment_message` NOT LIKE '%Files Released%'
   )
   OR
   (
      `deployment_project_status` IN ('CANCELED','ON HOLD')
   )

;;


CREATE OR REPLACE VIEW `{$dataObj->view_names['testing_list']}` AS
SELECT
   MIN(deployment_time) as in_test_date,
   deployment_username as developer,
   deployment_project_id as project_id,
   deployment_project_status as project_status,
   deployment_project as project_description,
   DATEDIFF( CURRENT_TIMESTAMP, MIN(deployment_time)) as days_in_test_queue
FROM
   `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients_updates']}`
WHERE
   (deployment_type = 'INTEGRATION' AND
   deployment_project_status <> 'IN PRODUCTION' AND
   deployment_project_id <> '')
   OR
   (
      `deployment_project_status` IN ('CANCELED','ON HOLD')
   )
GROUP BY deployment_project_id
ORDER BY id ASC


;;


CREATE OR REPLACE VIEW `{$dataObj->view_names['project_listing']}` AS
SELECT
   deployment_project_id as project_id,
   deployment_username as developer,
   deployment_project_status as project_status,
   deployment_project as project_description,
   MAX(deployment_time) as last_deployment_time,
   count(1) as total_releases,
   GROUP_CONCAT(deployment_svn) as all_revisions,
   GROUP_CONCAT(deployment_files) as files,
   deployment_svn_branch as branch
FROM
   `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients_updates']}`
WHERE
   `deployment_project_id` <> ''
GROUP BY
   CONCAT(deployment_project_id,deployment_svn_branch)
ORDER BY
   id DESC

;;

CREATE OR REPLACE VIEW `{$dataObj->view_names['project_listing_all']}` AS
SELECT
   `id`,
   `deployment_username` as developer,
   `deployment_project_id`,
   `deployment_project_status`,
   `deployment_results`,
   `deployment_type`,
   `deployment_message`,
   `deployment_sql_file`,
   `deployment_hosts`,
   `deployment_databases`,
   `deployment_files`,
   `deployment_time`,
   `deployment_svn`,
   `deployment_svn_branch`
FROM
   `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients_updates']}`
WHERE
   `deployment_project_id` <> ''
ORDER BY
   id DESC

;;

CREATE OR REPLACE VIEW `{$dataObj->view_names['sql_counts']}`  AS
SELECT
   'Custom Queries Executed' as action,
   `deployment_username` as user,
   count(*) as count
FROM
   `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients_updates']}`
WHERE
   `deployment_time` > date_add(CURRENT_TIMESTAMP, interval -7 DAY) AND
   `deployment_type` LIKE 'QUERY_IN%' AND
   `deployment_results` LIKE '%select%'
GROUP BY
   `deployment_username`
UNION ALL
SELECT
   'Custom Updates Executed',
   `deployment_username`,
   count(*)
FROM
   `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients_updates']}`
WHERE
   `deployment_time` > date_add(CURRENT_TIMESTAMP, interval -7 DAY) AND
   `deployment_type` LIKE 'QUERY_IN%' AND
   `deployment_results` LIKE '%update%'
GROUP BY
   `deployment_username`
UNION ALL
SELECT
   'Custom Deletes Executed',
   `deployment_username`,
   count(*)
FROM
   `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients_updates']}`
WHERE
   `deployment_time` > date_add(CURRENT_TIMESTAMP, interval -7 DAY) AND
   `deployment_type` LIKE 'QUERY_IN%' AND
   `deployment_results` LIKE '%delete from%'
GROUP BY
   `deployment_username`

;;

CREATE OR REPLACE VIEW `{$dataObj->view_names['distinct_projects']}` AS
SELECT
   deployment_project_status,
   deployment_project,
   deployment_project_id
FROM
   `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients_updates']}`
GROUP BY deployment_project_id
ORDER BY deployment_time DESC

;;

CREATE OR REPLACE VIEW `{$dataObj->view_names['project_minutes']}` AS
SELECT
   branch as development_location,
   sum(minutes_logged) as total_minutes,
   developer,
   project_id,
   DATE(rev_modified_time) as date_of_development,
   DATE_FORMAT(rev_modified_time,'%W') as day_of_development,
   WEEKOFYEAR(rev_modified_time) as week_of_year,
   IF ( deployment_project_status IS NOT NULL , deployment_project_status , 'Never Pushed') as project_status,
   IF ( deployment_project IS NOT NULL ,deployment_project, 'N/A') as project_description,
   GROUP_CONCAT(revision) as revisions,
   GROUP_CONCAT(checkin_message) as checkin_messages
FROM
   `{$dataObj->maintenance_database}`.`{$dataObj->table_names['checkins']}`
   LEFT OUTER JOIN
   `{$dataObj->maintenance_database}`.`{$dataObj->view_names['distinct_projects']}` ON (project_id = deployment_project_id)
WHERE
   project_id > 0 AND
   minutes_logged > 0
GROUP BY concat(developer,project_id,DATE(rev_modified_time),deployment_project)
ORDER BY
   developer,date_added DESC


;;


CREATE OR REPLACE VIEW `{$dataObj->view_names['project_minutes_weekly']}` AS
SELECT
   sum(minutes_logged) as total_minutes,
   developer,
   project_id,
   IF ( deployment_project_status IS NOT NULL , deployment_project_status , 'Never Pushed') as project_status,
   IF ( deployment_project IS NOT NULL ,deployment_project, 'N/A') as project_description
FROM
   `{$dataObj->maintenance_database}`.`{$dataObj->table_names['checkins']}`
   LEFT OUTER JOIN
   `{$dataObj->maintenance_database}`.`{$dataObj->view_names['distinct_projects']}` ON (project_id = deployment_project_id)
WHERE
   rev_modified_time > date_add(CURRENT_TIMESTAMP, interval -7 DAY) AND
   project_id > 0 AND
   minutes_logged > 0
GROUP BY concat(developer,project_id,DATE(rev_modified_time),deployment_project)
ORDER BY
   developer, minutes_logged DESC

;;

INSERT INTO `{$dataObj->maintenance_database}`.`{$dataObj->table_names['clients']}` (`id`, `name`, `deployment_type`, `host_name`, `host_path`, `host_user`, `host_pass`, `db_host`, `db_name`, `db_user`, `db_pass`, `created`, `modified`, `active`) VALUES
(1, 'website-test.com', 'TEST', 'ftpserverip', 'website-test.com', 'ftpusername', 'ftppassword', 'mysqlserverip', 'databasename', 'databaseuser', 'databasepass', NOW(), NOW(), 1),
(2, 'website-prod.com', 'PRODUCTION', 'ftpserverip', 'website-prod.com', 'ftpusername', 'ftppassword', 'mysqlserverip', 'databasename', 'databaseuser', 'databasepass', NOW(), NOW(), 1);

EOD;

?>