<?php

/**
 * Simple Machines Forum (SMF)
 *
 * @package SMF
 * @author Simple Machines http://www.simplemachines.org
 * @copyright 2011 Simple Machines
 * @license http://www.simplemachines.org/about/smf/license.php BSD
 *
 * @version 2.0
 */

// !!! Polls.

$convert_data = array(
	'name' => 'E-Blah 10.x.x',
	'version' => 'SMF 2.0',
	'flatfile' => true,
	'settings' => array('/home/website/eblah_old/forum/Settings.pl'),
	'parameters' => array(
		array(
			'id' => 'db_purge',
			'type' => 'checked',
			'label' => 'Clear current SMF posts and members during conversion.',
		),
	),
);

if (!function_exists('convert_query'))
{
	if (file_exists(dirname(__FILE__) . '/convert.php'))
		header('Location: http://' . (empty($_SERVER['HTTP_HOST']) ? $_SERVER['SERVER_NAME'] . (empty($_SERVER['SERVER_PORT']) || $_SERVER['SERVER_PORT'] == '80' ? '' : ':' . $_SERVER['SERVER_PORT']) : $_SERVER['HTTP_HOST']) . (strtr(dirname($_SERVER['PHP_SELF']), '\\', '/') == '/' ? '' : strtr(dirname($_SERVER['PHP_SELF']), '\\', '/')) . '/convert.php?convert_script=' . basename(__FILE__));
	else
	{
		echo '<html>
	<head>
		<title>Unable to continue!</title>
	</head>
	<body>
		<h1>Sorry, this file can\'t work alone</h1>

		<p>Please download convert.php from <a href="http://www.simplemachines.org/">www.simplemachines.org</a> and use it.  This file should be in the same directory as it.</p>
	</body>
</html>';
	}

	exit;
}

if (empty($preparsing))
{
	// Memory, please!!
	@ini_set('memory_limit', '128M');

	function load_converter_settings()
	{
		global $eblah;

		if (isset($_SESSION['convert_parameters']['db_purge']))
			$_SESSION['purge'] = 1;
			//$_SESSION['purge'] = !empty($_SESSION['convert_parameters']['db_purge']);

		if (!isset($_POST['path_from']) || !file_exists($_POST['path_from'] . '/Settings.pl'))
			return;

		$data = file($_POST['path_from'] . '/Settings.pl');
		foreach ($data as $line)
		{
			$line = trim($line);
			if (empty($line) || substr($line, 0, 1) == '#')
				continue;

			if (preg_match('~\$([^ =]+?)\s*=\s*[q]?([\^"\']?)(.+?)\\2;~', $line, $match) != 0)
				$eblah[$match[1]] = $match[2] == '^' ? addslashes($match[3]) : $match[3];
		}

		$paths = array('root', 'code', 'boards', 'prefs', 'members', 'messages', 'uploaddir');
		foreach ($paths as $path)
		{
			if (isset($eblah[$path]))
				$eblah[$path] = fixRelativePath($eblah[$path], $_POST['path_from']);
		}
	}

	function convertStep1()
	{
		global $to_prefix, $eblah;

		echo 'Converting membergroups...';
		$knownGroups = array();
		$extraGroups = array();
		$newbie = false;

		// Add a temp column for members.
		if ($_REQUEST['start'] == 0)
		{
			alterDatabase('membergroups', 'remove column', 'temp_members');
			alterDatabase('membergroups', 'remove column', 'temp_id');
			alterDatabase('membergroups', 'add column', array(
				'name' => 'temp_members',
				'type' => 'longtext',
			));
			alterDatabase('membergroups', 'add column', array(
				'name' => 'temp_id',
				'type' => 'int',
				'size' => 10,
				'default' => 0,
			));
		}

		$groups = file($eblah['prefs'] . '/Ranks2.txt');
		$current_group = null;
		for ($i = 0, $n = count($groups); $i < $n; $i++)
		{
			$group = trim($groups[$i]);

			if (preg_match('~^(Administrator|Moderators) => \{$~', $group, $match) != 0)
			{
				$current_group = &$knownGroups[$match[1] == 'Administrator' ? 1 : 3];
				$current_group = array();
			}
			elseif (preg_match('~(\d+) => \{$~', $group, $match) != 0)
			{
				$current_group = &$extraGroups[];
				$current_group['temp_id'] = $match[1];
			}
			elseif (isset($current_group) && preg_match('~^(.+?) = [\'(](.+?)[\'\)]$~', $group, $match) != 0)
			{
				if ($match[1] == 'name')
					$current_group['group_name'] = addslashes($match[2]);
				elseif ($match[1] == 'members')
					$current_group['temp_members'] = addslashes($match[2]);
				elseif ($match[1] == 'pcount')
				{
					$current_group['min_posts'] = max(0, (int) $match[2]);
					if ($match[2] < 1)
						$newbie = true;
				}
				elseif ($match[1] == 'color')
					$current_group['online_color'] = addslashes($match[2]);
			}
		}

		unset($knownGroups[3]['temp_members']);

		if (!empty($_SESSION['purge']))
		{
			convert_query("
				DELETE FROM {$to_prefix}permissions
				WHERE id_group > " . ($newbie ? 3 : 4));
			convert_query("
				DELETE FROM {$to_prefix}membergroups
				WHERE id_group > " . ($newbie ? 3 : 4));
		}

		if (!empty($knownGroups))
		{
			foreach ($knownGroups as $i => $v){
				if(!isset($v['temp_members'])){
					$v['temp_members'] = '';
				}
				$knownGroups[$i] = array($i, substr($v['group_name'], 0, 80), substr(@$v['online_color'], 0, 20), -1, '', $v['temp_members']);
			}

			convert_insert('membergroups', array('id_group', 'group_name', 'online_color', 'min_posts', 'stars', 'temp_members'), $knownGroups, 'replace');
		}

		if (!empty($extraGroups))
		{
			foreach ($extraGroups as $i => $v){
				$extraGroups[$i] = array($v['temp_id'], substr(@$v['group_name'], 0, 80), substr(@$v['online_color'], 0, 20), (isset($v['min_posts']) ? $v['min_posts'] : -1), '', @$v['temp_members']);
			}
			convert_insert('membergroups', array('temp_id', 'group_name', 'online_color', 'min_posts', 'stars', 'temp_members'), $extraGroups, 'replace');
		}
	}

	function convertStep2()
	{
		global $to_prefix, $eblah;

		echo 'Converting members...';

		if ($_GET['substep'] == 0 && !empty($_SESSION['purge']))
		{
			convert_query("
				TRUNCATE {$to_prefix}members");
		}
		if ($_GET['substep'] == 0)
		{
              // Get rid of the primary key... we have to resort anyway.
			convert_query("ALTER TABLE {$to_prefix}members
				DROP PRIMARY KEY,                                                                    
				CHANGE COLUMN id_member id_member mediumint(8) unsigned NOT NULL default 0");
          
		}

		pastTime(0);

		$request = convert_query("
			SELECT id_group, temp_members
			FROM {$to_prefix}membergroups
			WHERE temp_members != ''");
		$groups = array();
		$addGroups = array();
		while ($row = convert_fetch_assoc($request))
		{
			$members = explode(',', $row['temp_members']);
			foreach ($members as $member)
			{
				if (trim($member) == '')
					continue;

				// Additional group?
				if (isset($groups[$member]))
					$addGroups[$member] = empty($addGroups[$member]) ? $row['id_group'] : $addGroups[$member] . ',' . $row['id_group'];
				else
					$groups[$member] = $row['id_group'];
			}
		}
		convert_free_result($request);

		$file_n = 0;
		$dir = dir($eblah['members']);
		$block = array();
		$text_columns = array(
			'id_member' => 12,
			'member_name' => 80,
			'lngfile' => 255,
			'real_name' => 255,
			'buddy_list' => 255,
			'pm_ignore_list' => 255,
			'message_labels' => 65534,
			'passwd' => 64,
			'email_address' => 255,
			'personal_text' => 255,
			'website_title' => 255,
			'website_url' => 255,
			'location' => 255,
			'ICQ' => 255,
			'AIM' => 16,
			'YIM' => 32,
			'MSN' => 255,
			'time_format' => 80,
			'signature' => 255,
			'avatar' => 255,
			'usertitle' => 255,
			'member_iP' => 255,
			'member_iP2' => 255,
			'secret_question' => 255,
			'secret_answer' => 64,
			'validation_code' => 10,
			'additional_groups' => 255,
			'smiley_set' => 48,
			'password_salt' => 5,
		);
		while ($entry = $dir->read())
		{
			if ($_GET['substep'] < 0)
				break;
			if ($file_n++ < $_GET['substep'])
				continue;
			if (strrchr($entry, '.') != '.dat')
				continue;

			$userData = file($eblah['members'] . '/' . $entry);
			foreach ($userData as $i => $v)
			{
				$temp = explode(' = |', $userData[$i]);

				$temp[1] = trim($temp[1]);
				if (substr($temp[1], -1) == '|')
					$temp[1] = substr($temp[1], 0, -1);
				$userData[trim($temp[0])] = $temp[1];
				
			}
			if (count($userData) < 3)
				continue;

			$name = substr($entry, 0, -4);
			$name_id = $name;
			if (is_numeric($name) && !empty($userData['sn']))
				$name = $userData['sn'];

			$row = array(
				'id_member' => $name_id,
				'member_name' => substr(htmlspecialchars($name), 0, 80),
				'id_group' => isset($groups[$name_id]) ? $groups[$name_id] : 0,
				'additional_groups' => isset($addGroups[$name_id]) ? $addGroups[$name_id] : '',
				'passwd' => $userData['password'],
				'real_name' => @$userData[1] == 'Guest' || @$userData['sn'] == '' ? htmlspecialchars($name) : htmlspecialchars($userData['sn']),
				'email_address' => htmlspecialchars(@$userData['email']),
				'posts' => (int) @$userData['posts'],
				'usertitle' => '', //htmlspecialchars(@$userData['']),
				'personal_text' => htmlspecialchars(@$userData['personaltxt']),
				'gender' => @$userData['sex'] <= 2 ? (int) @$userData['sex'] : 0,
				'ICQ' => htmlspecialchars(@$userData['icq']),
				'AIM' => substr(htmlspecialchars(@$userData['aim']), 0, 16),
				'MSN' => htmlspecialchars(@$userData['msn']),
				'signature' => str_replace(array('&lt;br&gt;'), array('<br />'), htmlspecialchars(@$userData['sig'], ENT_QUOTES)),
				'hide_email' => (int) @$userData['hidemail'],
				'date_registered' => @$userData['registered'],
				'time_offset' => (float) @$userData['timezone'],
				'birthdate' => @$userData['dob'] == '' || strtotime(@$userData['dob']) == 0 ? '0001-01-01' : strftime('%Y-%m-%d', strtotime($userData['dob'])),
				'show_online' => 1,
				'website_title' => htmlspecialchars(@$userData['sitename']),
				'website_url' => htmlspecialchars(@$userData['siteurl']),
				'location' => htmlspecialchars(@$userData['location']),
				'notify_announcements' => 1,
				'YIM' => substr(htmlspecialchars(@$userData['yim']), 0, 32),
			);
			//var_dump($row);
			if ($row['birthdate'] === '0001-01-01' && parse_time(@$userData[16], false) != 0)
				$row['birthdate'] = strftime('%Y-%m-%d', parse_time(@$userData[16], false));

			// Make sure these columns have a value and don't exceed max width.
			foreach ($text_columns as $text_column => $max_size)
				$row[$text_column] = isset($row[$text_column]) ? substr($row[$text_column], 0, $max_size) : '';

			if (file_exists($eblah['members'] . '/' . substr($entry, 0, -4) . '.prefs'))
			{
				$imconfig = file($eblah['members'] . '/' . substr($entry, 0, -4) . '.prefs');
				//$row['pm_ignore_list'] = !empty($imconfig[0]) ? strtr(trim($imconfig[0]), '|', ',') : '';
				$row['pm_email_notify'] = empty($imconfig[1]) || trim($imconfig[1]) == '' ? '0' : '1';
			}
			else
			{
				//$row['pm_ignore_list'] = '';
				$row['pm_email_notify'] = '1';
			}

			$block[] = addslashes_recursive($row);

			if (count($block) > 100)
			{
				doBlock('members', $block);
				pastTime($file_n);
			}
		}
		$dir->close();

		doBlock('members', $block);

		pastTime(-1);

		// Part 2: Now we get to resort the members table!
		if ($_GET['substep'] >= -1)
		{
			convert_query("
				ALTER TABLE {$to_prefix}members
				ORDER BY id_member, date_registered");
			pastTime(-2);
		}

		if ($_GET['substep'] >= -2)
		{

			convert_query("ALTER TABLE {$to_prefix}members
                  CHANGE COLUMN id_member id_member mediumint(8) unsigned NOT NULL default NULL auto_increment PRIMARY KEY");


			pastTime(-3);
		}
		if ($_GET['substep'] >= -3)
		{
			convert_query("
				ALTER TABLE {$to_prefix}members
				ORDER BY id_member");

			pastTime(-4);
		}
		if ($_GET['substep'] >= -3)
			alterDatabase('membergroups', 'remove column', 'temp_members');
	}

	function convertStep3()
	{
		global $to_prefix, $eblah;

		echo 'Converting settings...';

		$settings = array();
		$settings['news'] = addslashes(strtr(implode('', file($eblah['prefs'] . '/News.txt')), array("\r" => '')));
		$settings['requireAgreement'] = !empty($eblah['showreg']) ? 1 : 0;
		$settings['registration_method'] = 1;
		//$settings['registration_method'] = !empty($eblah['creg']) ? 3 : (!empty($eblah['vradmin']) ? ($eblah['vradmin'] == 2 ? 2 : 1) : 0);
		$settings['mail_type'] = empty($eblah['mailuse']) || $eblah['mailuse'] != 2 ? 0 : 1;
		$settings['smtp_host'] = isset($eblah['mailhost']) ? $eblah['mailhost'] : '';
		$settings['smtp_username'] = isset($eblah['mailusername']) ? $eblah['mailusername'] : '';
		$settings['smtp_password'] = isset($eblah['mailpassword']) ? $eblah['mailpassword'] : '';
		$settings['time_offset'] = isset($eblah['gtoff']) ? (int) $eblah['gtoff'] : 0;
		$settings['defaultMaxMembers'] = !empty($eblah['mmpp']) ? (int) $eblah['mmpp'] : 20;
		$settings['defaultMaxTopics'] = !empty($eblah['totalpp']) ? (int) $eblah['totalpp'] : 20;
		$settings['defaultMaxMessages'] = !empty($eblah['maxmess']) ? (int) $eblah['maxmess'] : 15;
		$settings['spamWaitTime'] = (int) $eblah['iptimeout'];
		$settings['avatar_max_width_external'] = (int) $eblah['picwidth'];
		$settings['avatar_max_height_external'] = (int) $eblah['picheight'];
		$settings['avatar_max_width_upload'] = (int) $eblah['picwidth'];
		$settings['avatar_max_height_upload'] = (int) $eblah['picheight'];

		$temp = file_exists($eblah['prefs'] . '/Names.txt') ? file($eblah['prefs'] . '/Names.txt') : array();
		$names = array();
		foreach ($temp as $name)
		{
			if (trim($name) == '')
				continue;

			list ($res_name) = explode('|', $name);
			$names[] = trim($res_name);
		}
		if (!empty($names))
			$settings['reserveNames'] = addslashes(implode("\n", $names));

		$vulgar = array();
		$proper = array();
		$temp = file($eblah['prefs'] . '/Censor.txt');
		foreach ($temp as $word)
		{
			if (trim($word) == '')
				continue;

			list ($word, $word2) = explode('|', $word);
			$vulgar[] = trim($word);
			$proper[] = trim($word2);
		}
		$settings['censor_vulgar'] = addslashes(implode("\n", $vulgar));
		$settings['censor_proper'] = addslashes(implode("\n", $proper));

		$setString = array();
		foreach ($settings as $var => $val)
			$setString[] = array($var, substr($val, 0, 65534));
		convert_insert('settings', array('variable', 'value'), $setString, 'replace');
	}

	function convertStep4()
	{
		global $to_prefix, $eblah;

		echo 'Converting personal messages...';

		if ($_GET['substep'] == 0 && !empty($_SESSION['purge']))
		{
			convert_query("
				TRUNCATE {$to_prefix}personal_messages");
			convert_query("
				TRUNCATE {$to_prefix}pm_recipients");
		}
		if ($_GET['substep'] == 0)
		{
              convert_query("
                  ALTER TABLE {$to_prefix}personal_messages
                  DROP COLUMN temp_to_name", true);
              convert_query("
				ALTER TABLE {$to_prefix}personal_messages
				CHANGE COLUMN id_pm id_pm mediumint(8) unsigned NOT NULL default 0,
				DROP PRIMARY KEY,
				ADD temp_to_name tinytext");                                                          
		}


		$names = array();

		$file_n = 0;
		$dir = dir($eblah['members']);
		$block = array();
		

		while ($entry = $dir->read())
		{
			if ($_GET['substep'] < 0)
				break;
			if ($file_n++ < $_GET['substep'])
				continue;
			if (strrchr($entry, '.') != '.pm')
				continue;

			$userData = file($eblah['members'] . '/' . $entry);
			$name_id = substr($entry, 0, -3);

			foreach ($userData as $i => $v)
			{
				$userData[$i] = explode('|', rtrim($userData[$i]));
				if ($userData[$i][0] == 2) //2=sent so skip?
					continue;

				$row = array(
					'msgtime' => $userData[$i][1],
					'subject' => substr($userData[$i][2], 0, 255),
					'from_name' => substr(htmlspecialchars($userData[$i][3]), 0, 255),
					'body' => substr(strtr($userData[$i][4], array('<br>' => '<br />')), 0, 65534),
					'id_member_from' => substr(htmlspecialchars($userData[$i][3]), 0, 255),
					'deleted_by_sender' => '1',
					'temp_to_name' => $name_id,
				);
				//look up sender name from id
				$result = convert_query("SELECT id_member, member_name FROM {$to_prefix}members where id_member = ".$row['from_name']);
				$row['from_name'] = convert_fetch_assoc($result)['member_name'];
				convert_free_result($result);

				$block[] = addslashes_recursive($row);
			}

			if (count($block) > 100)
			{

				doBlock('personal_messages', $block);
				pastTime($file_n);
			}

		}
		$dir->close();

		if (!empty($block))
		{

			doBlock('personal_messages', $block);
		}

		pastTime(-1);

		// Part 2: Now we get to resort the personal messages table!
		if ($_GET['substep'] >= -1)
		{
			convert_query("
				ALTER TABLE {$to_prefix}personal_messages
				ORDER BY id_pm, msgtime");

			pastTime(-2);
		}
		if ($_GET['substep'] >= -2)
		{
			convert_query("ALTER TABLE {$to_prefix}personal_messages
                  CHANGE COLUMN id_pm id_pm int unsigned NOT NULL default NULL auto_increment PRIMARY KEY");
			convert_query("UPDATE {$to_prefix}personal_messages SET id_pm_head = id_pm");
			pastTime(-3);
		}
		if ($_GET['substep'] >= -3)
		{
			/*!!! CONVERT THIS FROM MYSQL SPECIFIC QUERY!!! */
			convert_query("
				INSERT INTO {$to_prefix}pm_recipients
					(id_pm, id_member, labels)
				SELECT pm.id_pm, pm.temp_to_name,'-1' AS labels
				FROM {$to_prefix}personal_messages AS pm
					WHERE pm.temp_to_name != ''");

			pastTime(-4);
		}
		if ($_GET['substep'] >= -4)
		{
			alterDatabase('personal_messages', 'remove column', 'temp_to_name');

			pastTime(-5);
		}
		if ($_GET['substep'] >= -5)
		{
			convert_query("
				ALTER TABLE {$to_prefix}personal_messages
				ORDER BY id_pm");
		}
	}

	function convertStep5()
	{
		global $to_prefix, $eblah;

		echo 'Converting boards and categories...';

		if ($_GET['substep'] == 0 && !empty($_SESSION['purge']))
		{
			convert_query("
				TRUNCATE {$to_prefix}categories");
			convert_query("
				TRUNCATE {$to_prefix}boards");
			convert_query("
				TRUNCATE {$to_prefix}moderators");
		}
		if ($_GET['substep'] == 0)
		{
			alterDatabase('categories', 'remove column', 'temp_id');
			alterDatabase('boards', 'remove column', 'temp_id');
			alterDatabase('boards', 'remove column', 'temp_cat_id');

			alterDatabase('categories', 'add column', array(
				'name' => 'temp_id',
				'type' => 'tinytext',
			));
			alterDatabase('boards', 'add column', array(
				'name' => 'temp_id',
				'type' => 'tinytext',
			));
			alterDatabase('boards', 'add column', array(
				'name' => 'temp_cat_id',
				'type' => 'tinytext',
			));
		}

		pastTime(0);

		$request = convert_query("
			SELECT id_group, group_name, temp_id
			FROM {$to_prefix}membergroups
			WHERE id_group NOT IN (2, 3)");
		$groups = array('Administrator' => 1, 'member' => 0);
		while ($row = convert_fetch_assoc($request))
		{
			$groups[$row['group_name']] = $row['id_group'];
			if ($row['temp_id'] != '')
				$groups[$row['temp_id']] = $row['id_group'];
		}
		convert_free_result($request);

		$cats = file($eblah['boards'] . '/bdscats.db');
		$cat_rows = array();
		$board_cats = array();
		foreach ($cats as $i => $cat)
		{
			$data = explode('|', $cat);
			foreach ($data as $i => $v)
				$data[$i] = rtrim($data[$i]);

			$row = array(
				'name' => substr($data[0], 0, 255),
				'cat_order' => $i + 1,
				'temp_id' => trim($data[1]),
			);

			$cat_rows[] = $row;

			$boards = explode('/', $data[3]);
			foreach ($boards as $board)
				$board_cats[trim($board)] = trim($data[1]);
		}

		doBlock('categories', $cat_rows);

		$boards = file($eblah['boards'] . '/bdindex.db');
		$moderators = array();
		$board_rows = array();
		foreach ($boards as $i => $board)
		{
			$data = explode('/', rtrim($board));

			$row = array(
				'name' => substr($data[3], 0, 255),
				'description' => substr($data[1], 0, 255),
				'board_order' => $i + 1,
				'temp_cat_id' => isset($board_cats[trim($data[0])]) ? $board_cats[trim($data[0])] : 1,
				//'count_posts' => !empty($data[9]),
				'count_posts' => 0,
				'temp_id' => $data[0],
				'member_groups' => '-1,0',
			);

			$board_rows[] = $row;

			$mods = explode('|', $data[2]);
			foreach ($mods as $mod)
			{
				if (trim($mod) != '' && $mod[0] != '(')
					$moderators[$data[0]][] = $mod;
			}
		}

		doBlock('boards', $board_rows);

		$result = convert_query("
			SELECT id_cat, temp_id
			FROM {$to_prefix}categories
			WHERE temp_id != ''");
		while ($row = convert_fetch_assoc($result))
		{
			convert_query("
				UPDATE {$to_prefix}boards
				SET id_cat = $row[id_cat]
				WHERE temp_cat_id = '$row[temp_id]'");
		}
		convert_free_result($result);

		foreach ($moderators as $boardid => $names)
		{
			$result = convert_query("
				SELECT id_board
				FROM {$to_prefix}boards
				WHERE temp_id = '$boardid'
				LIMIT 1");
			list ($id_board) = convert_fetch_row($result);
			convert_free_result($result);

			/*!!! CONVERT THIS FROM MYSQL SPECIFIC QUERY!!! */
			convert_query("
				INSERT INTO {$to_prefix}moderators
					(id_board, id_member)
				SELECT $id_board, id_member
				FROM {$to_prefix}members
				WHERE member_name IN ('" . implode("', '", addslashes_recursive($names)) . "')
				LIMIT " . count($names));
		}

		alterDatabase('categories', 'remove column', 'temp_id');
		alterDatabase('boards', 'remove column', 'temp_cat_id');
		alterDatabase('membergroups', 'remove column', 'temp_id');
	}

	function convertStep6()
	{
		global $to_prefix, $eblah;

		echo 'Converting mark read data...';

		if ($_GET['substep'] == 0 && !empty($_SESSION['purge']))
		{
			convert_query("
				TRUNCATE {$to_prefix}log_boards");
			convert_query("
				TRUNCATE {$to_prefix}log_mark_read");
			convert_query("
				TRUNCATE {$to_prefix}log_topics");
		}
		if ($_GET['substep'] == 0)
		{
			alterDatabase('log_topics', 'remove column', 'temp_id');
			alterDatabase('log_topics', 'remove index', 'primary');
			alterDatabase('log_topics', 'add column', array(
				'name' => 'temp_id',
				'type' => 'int',
				'size' => 10,
				'default' => 0,
			));
		}

		alterDatabase('log_topics', 'add index', array(
			'name' => 'temp_id_idx',
			'columns' => array('temp_id'),
		));



		$result = convert_query("
			SELECT id_board, temp_id
			FROM {$to_prefix}boards");
		$boards = array();
		while ($row = convert_fetch_assoc($result))
			$boards[$row['temp_id']] = $row['id_board'];
		convert_free_result($result);

		$file_n = 0;
		$dir = dir($eblah['members']);
		$mark_read_block = array();
		$boards_block = array();
		$topics_block = array();
		while ($entry = $dir->read())
		{
			if ($_GET['substep'] < 0)
				break;
			if ($file_n++ < $_GET['substep'])
				continue;
			if (strrchr($entry, '.') != '.log')
				continue;

			if (!is_numeric(substr($entry, 0, -4)))
			{
				$result = convert_query("
					SELECT id_member
					FROM {$to_prefix}members
					WHERE member_name = '" . substr($entry, 0, -4) . "'
					LIMIT 1");
				list ($id_member) = convert_fetch_row($result);
				convert_free_result($result);
			}
			else
				$id_member = substr($entry, 0, -4);

			$logData = file($eblah['members'] . '/' . $entry);
			foreach ($logData as $log)
			{
				$parts = array_pad(explode('|', $log), 2, '');
				if (trim($parts[0]) == '')
					continue;

				$row = array();
				$row['id_member'] = $id_member;

				if (is_numeric(trim($parts[0])) && trim($parts[0]) > 10000)
				{
					$row['temp_id'] = trim($parts[0]);
					$topics_block[] = $row;
				}
				else
				{
					// !!! This causes duplicates.
					/*if (trim($parts[0]) == 'AllBoards')
					{
						foreach ($boards as $id)
						{
							$row['id_board'] = $id;
							$mark_read_block[] = $row;
						}
					}
					else*/if (isset($boards[trim($parts[0])]))
					{
						$row['id_board'] = $boards[trim($parts[0])];
						$boards_block[] = $row;
					}
					elseif (substr(trim($parts[0]), 0, 8) == 'AllRead_' && isset($boards[substr(trim($parts[0]), 8)]))
					{
						$row['id_board'] = $boards[substr(trim($parts[0]), 8)];
						$mark_read_block[] = $row;
					}
				}
			}

			// Because of the way steps are done, we have to flush all of these at once, or none.
			if (count($mark_read_block) > 250 || count($boards_block) > 250 || count($topics_block) > 250)
			{
				doBlock('log_mark_read', $mark_read_block);
				doBlock('log_boards', $boards_block);
				doBlock('log_topics', $topics_block);

				pastTime($file_n);
			}
		}
		$dir->close();

		doBlock('log_mark_read', $mark_read_block);
		doBlock('log_boards', $boards_block);
		doBlock('log_topics', $topics_block);
	}

	function convertStep7()
	{
		global $to_prefix, $eblah;

		echo 'Converting topics (part 1)...';

		if ($_GET['substep'] == 0 && !empty($_SESSION['purge']))
		{
			convert_query("
				TRUNCATE {$to_prefix}topics");
		}
		if ($_GET['substep'] == 0)
		{
			alterDatabase('topics', 'remove column', 'temp_id');
			alterDatabase('topics', 'remove column', 'temp_subject');
			alterDatabase('topics', 'remove index', 'last_message');
			alterDatabase('topics', 'remove index', 'first_message');
			alterDatabase('topics', 'remove index', 'poll');
			convert_query("ALTER TABLE {$to_prefix}topics
				DROP PRIMARY KEY,
				CHANGE COLUMN id_topic id_topic mediumint(8) unsigned NOT NULL default 0");
			alterDatabase('topics', 'add column', array(
				'name' => 'temp_id',
				'type' => 'int',
				'size' => 10,
				'default' => 0,
			));
			alterDatabase('topics', 'add column', array(
				'name' => 'temp_subject',
				'type' => 'tinytext',
				'default' => '',
			));
		}


		pastTime(0);

		$stickies = array();
		if (file_exists($eblah['boards'] . '/Stick.txt'))
		{
			$stickyData = file($eblah['boards'] . '/Stick.txt');

			foreach ($stickyData as $line)
			{
				if (trim($line) != '')
					list (, $stickies[]) = explode('|', trim($line));
			}
		}

		$result = convert_query("
			SELECT id_board, temp_id
			FROM {$to_prefix}boards
			WHERE temp_id != ''");
		$boards = array();
		while ($row = convert_fetch_assoc($result))
			$boards[$row['temp_id']] = $row['id_board'];
		convert_free_result($result);

		$data_n = 0;
		$block = array();
		foreach ($boards as $boardname => $id_board)
		{
			if ($_GET['substep'] < 0)
				break;
			if (!file_exists($eblah['boards'] . '/' . $boardname . '.msg'))
				continue;

			$topicListing = file($eblah['boards'] . '/' . $boardname . '.msg');
			foreach ($topicListing as $topicData)
			{
				if ($data_n++ < $_GET['substep'])
					continue;

				$topicInfo = explode('|', rtrim($topicData));
				$temp_id = (int) $topicInfo[0];

				if (!file_exists($eblah['messages'] . '/' . $temp_id . '.txt'))
					continue;

				$block[] = array(
					'temp_id' => $temp_id,
					'temp_subject' => $topicInfo[1],
					'id_board' => (int) $id_board,
					'is_sticky' => (int) in_array($temp_id, $stickies),
					'locked' => (int) $topicInfo[6],
					'num_views' => (int) @implode('', @file($eblah['messages'] . '/' . $temp_id . '.view')),
				);

				if (count($block) > 100)
				{
					doBlock('topics', $block);
					pastTime($data_n);
				}
			}
		}


		doBlock('topics', $block);

		pastTime(-1);

		if ($_GET['substep'] >= -1)
		{
			convert_query("
				UPDATE {$to_prefix}topics
				SET temp_id = id_topic
				WHERE temp_id = 0");

			pastTime(-2);
		}
		if ($_GET['substep'] >= -2)
		{
			convert_query("
				ALTER TABLE {$to_prefix}topics
				ORDER BY id_topic, temp_id");

			pastTime(-3);
		}
		if ($_GET['substep'] >= -3)
		{
			convert_query("ALTER TABLE {$to_prefix}topics
				CHANGE COLUMN id_topic id_topic mediumint(8) unsigned NOT NULL default NULL auto_increment PRIMARY KEY");

			pastTime(-4);
		}
		if ($_GET['substep'] >= -4)
		{
			convert_query("
				ALTER TABLE {$to_prefix}topics
				ORDER BY id_topic");
		}
	}

	function convertStep8()
	{
		global $to_prefix, $eblah;

		if ($_GET['substep'] == 0)
			alterDatabase('boards', 'remove column', 'temp_id');

		echo 'Converting topics (part 2)...';

		while (true)
		{
			pastTime($_GET['substep']);

			$result = convert_query("
				SELECT id_topic, temp_id
				FROM {$to_prefix}topics
				WHERE temp_id != id_topic
				LIMIT $_GET[substep], 150");
			while ($row = convert_fetch_assoc($result))
			{
				convert_query("
					UPDATE {$to_prefix}log_topics
					SET id_topic = $row[id_topic]
					WHERE temp_id = $row[temp_id]");
			}

			$_GET['substep'] += 150;
			if (convert_num_rows($result) < 150)
				break;

			convert_free_result($result);
		}

		convert_query("
			DELETE FROM {$to_prefix}log_topics
			WHERE id_topic = 0 OR id_member = 0");

		alterDatabase('log_topics', 'remove column', 'temp_id');
		alterDatabase('log_topics', 'add index', array(
			'type' => 'primary',
			'columns' => array('id_member','id_topic'),
		));
	}

	function convertStep9()
	{
		global $to_prefix, $eblah;

		if ($_GET['substep'] == 0 && !empty($_SESSION['purge']))
		{
			convert_query("
				TRUNCATE {$to_prefix}log_notify");
		}

		echo 'Converting notifications...';

		while (true)
		{
			pastTime($_GET['substep']);

			$result = convert_query("
				SELECT id_topic, temp_id
				FROM {$to_prefix}topics
				WHERE temp_id != id_topic
				LIMIT $_GET[substep], 150");
			while ($row = convert_fetch_assoc($result))
			{
				if (!file_exists($eblah['messages'] . '/Mail/' . $row['temp_id'] . '.mail'))
					continue;

				$list = file($eblah['messages'] . '/Mail/' . $row['temp_id'] . '.mail');
				foreach ($list as $k => $v)
					$list[$k] = addslashes(htmlspecialchars(rtrim($v)));

				/*!!! CONVERT THIS FROM MYSQL SPECIFIC QUERY!!! */
				convert_query("
					INSERT INTO {$to_prefix}log_notify
						(id_topic, id_member)
					SELECT $row[id_topic], id_member
					FROM {$to_prefix}members
					WHERE member_name IN ('" . implode("', '", $list) . "')");
			}

			$_GET['substep'] += 150;
			if (convert_num_rows($result) < 150)
				break;

			convert_free_result($result);
		}
	}

	function convertStep10()
	{
		global $to_prefix, $eblah;

		echo 'Converting posts (part 1 - this may take some time)...';

		if ($_GET['substep'] == 0 && !empty($_SESSION['purge']))
		{
			convert_query("
				TRUNCATE {$to_prefix}messages");
			convert_query("
				TRUNCATE {$to_prefix}attachments");
		}
		if ($_GET['substep'] == 0)
		{
			// Remove the auto_incrementing so we know we get the right order.
			alterDatabase('messages', 'remove index', 'topic');
			alterDatabase('messages', 'remove index', 'id_board');
			alterDatabase('messages', 'remove index', 'id_member');

			convert_query("ALTER TABLE {$to_prefix}messages
				DROP PRIMARY KEY,
				CHANGE COLUMN id_msg id_msg int(10) unsigned NOT NULL default 0");

			if (isset($eblah['uploaddir']))
			{
				alterDatabase('messages', 'remove column', 'temp_filename');
				alterDatabase('messages', 'add column', array(
					'name' => 'temp_filename',
					'type' => 'tinytext',
					'default' => '',
				));
			}
		}


		$block = array();
		while (true)
		{
			$result = convert_query("
				SELECT id_topic, temp_id, id_board, temp_subject
				FROM {$to_prefix}topics
				WHERE temp_id != id_topic
				LIMIT $_GET[substep], 100");
			while ($topic = convert_fetch_assoc($result))
			{
				$messages = file($eblah['messages'] . '/' . $topic['temp_id'] . '.txt');
				if (empty($messages))
				{
					convert_query("
						DELETE FROM {$to_prefix}topics
						WHERE id_topic = $topic[id_topic]
						LIMIT 1");

					pastTime($_GET['substep']);
					continue;
				}

				foreach ($messages as $msgn => $message)
				{
					if (trim($message) == '')
						continue;

					$message = array_pad(explode('|', $message), 10, '');
					foreach ($message as $k => $v)
						$message[$k] = rtrim($v);

					$message[9] = explode('/', $message[9]);
					$message[9][0] = preg_replace("/[^0-9]/", "", $message[9][0]); #num only for mod time

					//convert [quote=id] to [quote author=name] in body
					if(preg_match('~\[quote=(\d+)\]~', $message[1], $matches)){

						$quote_id_member = $matches[1];

						$rs = convert_query("SELECT member_name FROM {$to_prefix}members 
							where id_member = $quote_id_member");
						$rs_name =  convert_fetch_assoc($rs);
						convert_free_result($rs);
						$new_quote = "[quote author=".$rs_name['member_name']."]";
						$body = preg_replace('~\[quote=\d+\]~', $new_quote, $message[1]);
					}
					else{
						$body = $message[1];
					}



					$row = array(
						'id_topic' => $topic['id_topic'],
						'id_board' => $topic['id_board'],
						'subject' => substr(($msgn == 0 ? '' : 'Re: ') . $topic['temp_subject'], 0, 255),
						'poster_name' => substr($message[0], 0, 255), //update this later with real name
						'body' => $body,
						'poster_ip' => substr($message[2], 0, 255),
						'poster_email' => substr(htmlspecialchars($message[3]), 0, 255),
						'poster_time' => $message[4],
						'smileys_enabled' => $message[5] == 0? 1:0,
						'modified_time' => empty($message[9][0])? 0 : $message[9][0],
						'modified_name' => isset($message[9][1]) ? substr(htmlspecialchars($message[9][1]), 0, 255) : '',
						'icon' => 'xx',
					);

					if (isset($eblah['uploaddir']))
						$row['temp_filename'] = $message[8];

					$block[] = $row;

					if (count($block) > 100){
						doBlock('messages', $block);
					}
				}

				doBlock('messages', $block);
				pastTime(++$_GET['substep']);
			}

			if (convert_num_rows($result) < 100)
				break;

			convert_free_result($result);
		}

		doBlock('messages', $block);
	}

	function convertStep11()
	{
		global $to_prefix, $eblah;

		echo 'Converting posts (part 2)...';

		if ($_GET['substep'] == 0)
		{
			convert_query("
				ALTER TABLE {$to_prefix}messages
				ORDER BY poster_time");
		}


		$request = convert_query("
			SELECT @msg := IFNULL(MAX(id_msg), 0)
			FROM {$to_prefix}messages");
		convert_free_result($request);

		while (true)
		{
			pastTime($_GET['substep']);

			convert_query("
				UPDATE {$to_prefix}messages
				SET id_msg = (@msg := @msg + 1)
				WHERE id_msg = 0
				LIMIT 150");

			$_GET['substep'] += 150;
			if (convert_affected_rows() < 150)
				break;
		}


		convert_query("ALTER TABLE {$to_prefix}messages
			CHANGE COLUMN id_msg id_msg int(10) unsigned NOT NULL default NULL auto_increment PRIMARY KEY");


	}

	function convertStep12()
	{
		global $to_prefix, $eblah;

		echo 'Converting posts (part 3)...';

		while (true)
		{
			pastTime($_GET['substep']);

			$result = convert_query("
				SELECT m.id_msg, mem.id_member, mem.member_name
				FROM {$to_prefix}messages AS m
					INNER JOIN {$to_prefix}members AS mem ON (mem.id_member  = m.poster_name)
				WHERE m.id_member = 0
				LIMIT 150");
			while ($row = convert_fetch_assoc($result))
			{
				convert_query("
					UPDATE {$to_prefix}messages
					SET poster_name = '$row[member_name]',id_member = $row[id_member]
					WHERE id_msg = $row[id_msg]
					LIMIT 1");
			}

			$_GET['substep'] += 150;

			if (convert_num_rows($result) < 150){
				break;
			}

			convert_free_result($result);
		}

		
	}
	function convertStep13()
	{
		global $to_prefix, $eblah;

		echo 'Converting posts (part 4)...';

		//lets get names for deleted members now
		if(!file_exists($eblah['members'] . '/' . 'OldMembers.txt')){
			echo "Couldn't find OldMembers.txt";
			return;
		}
		$OldMembers = file($eblah['members'] . '/' . 'OldMembers.txt');
		$OldMember = array();
		foreach ($OldMembers as $i => $v)
		{
			list($user_id, $user_name) = explode('|',$OldMembers[$i]);
			$user_id = trim($user_id);
			$user_name = addslashes(trim($user_name));
			$OldMember[$user_id] = $user_name;
			if(empty($user_id) || empty($user_name)){
				echo "something is empty: id: $user_id name: $user_name line: {$OldMembers[$i]}";
			}
		}
		unset($OldMembers);

		alterDatabase('messages', 'add index', array(
			'name' => 'poster_name_idx',
			'columns' => array('poster_name','id_member'),
		));


		while (true)
		{
			pastTime($_GET['substep']);

			$result = convert_query("SELECT poster_name 
				FROM {$to_prefix}messages
				WHERE id_member = 0
				LIMIT 150");
			while ($row = convert_fetch_assoc($result))
			{
				$user_id = $row['poster_name'];
				if(!array_key_exists($user_id,$OldMember)){
					echo "No user found with id $user_id";
					continue;
				}
				convert_query("UPDATE {$to_prefix}messages
					SET id_member = $user_id, poster_name = '{$OldMember[$user_id]}'
					WHERE poster_name = $user_id AND id_member = 0");	
			}

			$_GET['substep'] += 150;

			if (convert_num_rows($result) < 150){
				convert_free_result($result);
				break;
			}

			convert_free_result($result);

		}
		alterDatabase('messages', 'remove index', 'poster_name_idx');
		convert_query("
			ALTER TABLE {$to_prefix}messages
			ORDER BY poster_time");

	}

	function convertStep14()
	{
		global $to_prefix, $eblah;

		echo 'Converting attachments (if the mod is installed)...';

		if (!isset($eblah['uploaddir'])){
			echo "uploaddir not defined, not running Convert attachments";
			return;
		}

		$result = convert_query("
			SELECT value
			FROM {$to_prefix}settings
			WHERE variable = 'attachmentUploadDir'
			LIMIT 1");
		list ($attachmentUploadDir) = convert_fetch_row($result);
		convert_free_result($result);

		// Danger, Will Robinson!
		if ($eblah['uploaddir'] == $attachmentUploadDir){
			echo "eblah uploaddir and smf attachmentUploadDir are the same! Not converting.";
			return;
		}

		$result = convert_query("
			SELECT MAX(id_attach)
			FROM {$to_prefix}attachments");
		list ($id_attach) = convert_fetch_row($result);
		convert_free_result($result);

		$id_attach++;

		while (true)
		{
			pastTime($_GET['substep']);
			$attachments = array();

			$result = convert_query("
				SELECT id_msg, temp_filename
				FROM {$to_prefix}messages
				WHERE temp_filename != ''
				LIMIT $_GET[substep], 100");
			while ($row = convert_fetch_assoc($result))
			{
				$files = explode('/', $row['temp_filename']);
				foreach ($files as $file)
				{
					$file = trim($file);
					if ($file != '' && file_exists($eblah['uploaddir'] . '/' . $file))
					{

						$size = filesize($eblah['uploaddir'] . '/' . $file);
						$filename = getLegacyAttachmentFilename($file, $id_attach);
						$img_info = getimagesize($eblah['uploaddir'] . '/' . $file);
						$h = $img_info[0];
						$w = $img_info[1];
						$mime_type = $img_info['mime'];
						$fileext = strtolower(strrpos($file, '.') !== false ? substr($file, strrpos($file, '.') + 1) : '');
						//$file_hash = getAttachmentFilename($file, false, null, true);

						if (strlen($file) <= 255 && copy($eblah['uploaddir'] . '/' . $file , $filename))
						{
							$attachments[] = array($id_attach, $size, 0, $file, $row['id_msg'], $h, $w, $mime_type, $fileext);

							$id_attach++;
						}
						else{
							echo "Couldn't copy: {$eblah['uploaddir']}/$file to $filename";
						}
					}
					else{
						if($file != ''){
							echo $eblah['uploaddir'] . '/' . $file . " doesn't exist, so skipping!";
						}
					}
				}
			}

		if (!empty($attachments))
				convert_insert('attachments', array('id_attach', 'size', 'downloads', 'filename', 'id_msg', 'width', 'height', 'mime_type', 'fileext'), $attachments, 'replace');

			$_GET['substep'] += 100;
			if (convert_num_rows($result) < 100){
				convert_free_result($result);
				break;
			}

			convert_free_result($result);
		}

		alterDatabase('messages', 'remove column', 'temp_filename');
	}

	function convertStep15()
	{
		global $to_prefix, $eblah;

		echo 'Cleaning up (part 1)...';

		if ($_GET['substep'] <= 0)
		{
			alterDatabase('topics', 'remove column', 'temp_id');

			pastTime(1);
		}
		if ($_GET['substep'] <= 1)
		{
			alterDatabase('messages', 'add index', array(
				'type' => 'unique',
				'name' => 'topic',
				'columns' => array('id_topic', 'id_msg'),
			));
			alterDatabase('messages', 'add index', array(
				'type' => 'unique',
				'name' => 'id_board',
				'columns' => array('id_board', 'id_msg'),
			));
			alterDatabase('messages', 'add index', array(
				'type' => 'unique',
				'name' => 'id_member',
				'columns' => array('id_member', 'id_msg'),
			));

			pastTime(2);
		}
		if ($_GET['substep'] <= 2)
		{
			alterDatabase('topics', 'add index', array(
				'type' => 'unique',
				'name' => 'poll',
				'columns' => array('id_poll', 'id_topic'),
			));

			pastTime(3);
		}
		if ($_GET['substep'] <= 3)
		{
			alterDatabase('topics', 'remove column', 'temp_subject');

			pastTime(4);
		}
		if ($_GET['substep'] <= 4)
		{
			//alterDatabase('topics', 'add index', array(
			//	'type' => 'unique',
			//	'name' => 'id_board',
			//	'columns' => array('id_board', 'id_msg'),
			//));
			//what??
		}
	}

	function convertStep16()
	{
		global $to_prefix, $eblah;

		echo 'Cleaning up (part 2)...';

		while ($_GET['substep'] >= 0)
		{
			pastTime($_GET['substep']);

			$result = convert_query("
				SELECT t.id_topic, MIN(m.id_msg) AS id_first_msg, MAX(m.id_msg) AS id_last_msg
				FROM {$to_prefix}topics AS t
					INNER JOIN {$to_prefix}messages AS m ON (m.id_topic = t.id_topic)
				GROUP BY t.id_topic
				LIMIT $_GET[substep], 150");
			while ($row = convert_fetch_assoc($result))
			{
				$result2 = convert_query("
					SELECT id_member
					FROM {$to_prefix}messages
					WHERE id_msg = $row[id_last_msg]
					LIMIT 1");
				list ($row['id_member_updated']) = convert_fetch_row($result2);
				convert_free_result($result2);

				$result2 = convert_query("
					SELECT id_member
					FROM {$to_prefix}messages
					WHERE id_msg = $row[id_first_msg]
					LIMIT 1");
				list ($row['id_member_started']) = convert_fetch_row($result2);
				convert_free_result($result2);

				convert_query("
					UPDATE {$to_prefix}topics
					SET id_first_msg = '$row[id_first_msg]', id_last_msg = '$row[id_last_msg]',
						id_member_started = '$row[id_member_started]', id_member_updated = '$row[id_member_updated]'
					WHERE id_topic = $row[id_topic]
					LIMIT 1");
			}

			$_GET['substep'] += 150;
			if (convert_num_rows($result) < 150)
				break;

			convert_free_result($result);
		}

		if ($_GET['substep'] > -1)
		{
			alterDatabase('topics', 'add index', array(
				'type' => 'unique',
				'name' => 'last_message',
				'columns' => array('id_last_msg', 'id_board'),
			));

			pastTime(-2);
		}
		if ($_GET['substep'] > -2)
		{
			alterDatabase('topics', 'add index', array(
				'type' => 'unique',
				'name' => 'first_message',
				'columns' => array('id_first_msg','id_board'),
			));
		}
	}

	function fixRelativePath($path, $cwd_path)
	{
		// Fix the . at the start, clear any duplicate slashes, and fix any trailing slash...
		return addslashes(preg_replace(array('~^\.([/\\\]|$)~', '~[/]+~', '~[\\\]+~', '~[/\\\]$~'), array($cwd_path . '$1', '/', '\\', ''), $path));
	}

	function parse_time($field, $use_now = true)
	{
		$field = trim(str_replace(array(' um', ' de', ' en', ' la', ' om', ' at'), '', $field));

		if ($field == '')
			$field = $use_now ? time() : 0;
		elseif (strtotime($field) != -1)
			$field = strtotime($field);
		elseif (preg_match('~(\d\d)/(\d\d)/(\d\d)(.*?)(\d\d)\:(\d\d)\:(\d\d)~i', $field, $matches) != 0)
			$field = strtotime("$matches[5]:$matches[6]:$matches[7] $matches[1]/$matches[2]/$matches[3]");
		else
			$field = $use_now ? time() : 0;

		return $field;
	}

	function doBlock($table, &$block, $ignore = false)
	{
		global $to_prefix;

		if (empty($block))
			return;

		if ($table == 'members')
		{
			$block_names = array();
			foreach ($block as $i => $row)
				$block_names[$row['member_name']] = $i;


			$request = convert_query("
				SELECT member_name
				FROM {$to_prefix}members
				WHERE member_name IN ('" . implode("', '", array_keys($block_names)) . "')
				LIMIT " . count($block_names));
			while ($row = convert_fetch_assoc($request))
				unset($block[$block_names[$row['member_name']]]);

			convert_free_result($request);

			if (empty($block))
				return;

			unset($block_names);
		}

		$insert_block = array();
		foreach ($block as $row)
			$insert_block[] = '\'' . implode('\', \'', $row) . '\'';

		convert_insert($table, array_keys($block[0]), $block, 'insert');

		$block = array();
	}
}

?>
