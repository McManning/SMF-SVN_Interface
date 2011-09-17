<?php

/*

TODO:
	- Reorganize code a lot more.  Split up our display/backend functions and give backend functions better output values. 
	- Move the repos.txt flat file to SQL and add several more properties to better design (public properties, visibility 
		within WebSVN, etc)

SMF additions:
	
	A page that has the following:
		- Table that lists all the repos, next to it will tell the person if they have read or write access.
			Repos will be found by finding all folders that match /home/arcdev/svn/ * /
		
		- A user/password display. Will include an input box (two, to verify) 
			to change password across the system.
		
		- Links to various helpful things. (Websvn, tortoise download links, manuals, etc)
		
		- Could also have the following for smf admins:
			- Create Repo, destroy repo, add/remove user to repo, etc.

	HOLY SHIT, FOUND A REFERENCE:
		http://dev.simplemachines.org/smcfunctions.php
		HALLELUJAH
*/

// Some handy globals
/*
$modSettings['svnRoot'] = '/home/arcdev/svn/'; <-- mandatory
$modSettings['svnPublicUrl'] = 'svn://arcdev.org/'; <-- mandatory
PHASED OUT: $modSettings['svnReposList'] = '/home/arcdev/svn/repos.ini';
$modSettings['websvnUrl'] = 'http://arcdev.org/websvn/'; <-- mandatory.. ?
PHASED OUT: $modSettings['svnForumUrl'] = 'http://arcdev.org/forum/';
PHASED OUT: $modSettings['svnForumPath'] = '/home/arcdev/public_html/forum/';
*/

// Repo for new users to join when their accounts are added to WebSVN
// Comment out to disable auto join.
// $modSettings['svnAutoJoinRepo'] = 'sandbox';

//$ssi_layers = array('html', 'body');
//require_once($modSettings['svnForumPath'].'SSI.php');

require_once($sourcedir . '/Subs-Post.php'); // for sendpm()

/*
// No guests!
if ($context['user']['is_guest'])
{
	// TODO: Is this right? Doesn't SSI throw some content out already on inclusion?
	header('Location: '.$scripturl.'?action=login');
	die();
}
*/

/**
	@return true if the specific user is in the access list for the repo
*/
function HasSVNRepoWriteAccess($username, $repo)
{
	global $modSettings;
	
	$data = parse_ini_file($modSettings['svnRoot'] . $repo . '/conf/passwd');
	
	return isset( $data[$username] );
}

/**
	@return an html string containing all the Repo's members along with links to their SMF profiles
*/
function GetSVNRepoMemberList($repo)
{
	global $modSettings, $scripturl;
	
	$data = parse_ini_file($modSettings['svnRoot'] . $repo . '/conf/passwd');
	
	$users = '';
	foreach ($data as $user => $pass)
	{
		$id = GetSMFUserIDFromUsername($user);
		
		if (empty($id)) // No SMF account?! Blasphemy! 
			$users .= $user.', ';
		else
			$users .= '<a href="'.$scripturl.'?action=profile;u='.$id.'">'.$user.'</a>, ';
	}
	
	return $users;
}

/**
	Updates/adds account information for the specific user to the specified repo
	
	@param $remove if true, will delete this user from $repo
	@return true if nothing exploded
*/
function UpdateSVNRepoMember($repo, $user, $pass, $remove)
{
	global $modSettings;
	
	$passwd_file = $modSettings['svnRoot'] . $repo . '/conf/passwd';

	if (!file_exists($passwd_file))
		return false;
	
	// Grab all our users
	$data = parse_ini_file($passwd_file);

	if ($remove) // remove user
	{
		if (!isset($data[$user])) // TODO: Something other than returning false. Maybe split this function into Add/Remove
			return false;
		
		unset($data[$user]); 
	}
	else // add user
	{
		$data[$user] = $pass;	
	}
	
	// Regenerate users file with new data
	$fp = fopen($passwd_file, "w");
	fwrite($fp, "# Regenerated by PHP on " . date('Y-m-d') . "\n");
	fwrite($fp, "[users]\n");
	
	foreach ($data as $u => $p)
	{
		fwrite($fp, $u . "=" . $p . "\n");
	}
	
	fclose($fp);
	
	return true;
}

// TODO: Grab a function from within SMF that can do this!
function GetSMFUserIDFromUsername($username)
{
	global $smcFunc; //, $db_show_debug, $db_cache;

	//$db_show_debug = true;

	$request = $smcFunc['db_query']('', '
		SELECT id_member
		FROM {db_prefix}members
		WHERE member_name = {string:username}',
		array(
			'username' => $username,
		)
	);

	//print_r($db_cache);

	$row = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);

	return $row['id_member'];
}

// TODO: Grab a function from within SMF that can do this!
function GetSMFUsernameFromUserID($smf_id)
{
	global $smcFunc;

	$request = $smcFunc['db_query']('', '
		SELECT member_name
		FROM {db_prefix}members
		WHERE id_member = {int:id}',
		array(
			'id' => $smf_id,
		)
	);

	$row = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);

	return $row['member_name'];
}

/**
	Will return an array containing all repos viewable by the specific user, 
		along with permission info, links, etc.
	@param $user the username to search for when attempting to locate permissions
*/
function GetSVNRepos($user)
{
	global $modSettings;

	$data = parse_ini_file($modSettings['svnRoot'] . 'repos.ini');
	if ($data === FALSE)
		die("Failed to parse " . $modSettings['svnRoot'] . 'repos.ini');
	
	$repos = array();
	
	foreach ($data as $repo => $title)
	{
		$repos[] = array(
				'title' => $title,
				'id' => $repo,
				'path' => $modSettings['svnRoot'] . $repo,
				'url' => $modSettings['svnPublicUrl'] . $repo,
				'writable' => HasSVNRepoWriteAccess(strtolower($user), $repo),
			);
	}
	
	return $repos;
}

function DoesSVNRepoExist($repo)
{
	global $modSettings;
	$passwd_file = $modSettings['svnRoot'] . $repo . '/conf/passwd';

	return file_exists($passwd_file);
}

function CheckPostedPasswords()
{
	global $_POST;
	
	if ($_POST['pass'] != $_POST['pass2'])
		PrintBadAlert('The new passwords you entered do not match.');
	elseif (preg_match("/[^a-zA-Z0-9]/", $_POST['pass']))
		PrintBadAlert('Invalid characters in new password. Alphanumeric only please.');
	else
		return true;
		
	return false;
}

function PrintGoodAlert($msg)
{
	echo '
		<div class="windowbg" id="profile_success">
			<span>'.$msg.'</span>
		</div>
	';
}

function PrintBadAlert($msg)
{
	echo '
		<div class="windowbg" id="profile_error">
			<span>The following errors occurred when trying to submit changes:</span>
			<ul class="reset">
				<li>'.$msg.'</li>
			</ul>
		</div>
	';
}

function PrintSVNReposTable()
{
	global $context, $modSettings;
	
	$repos = GetSVNRepos($context['user']['username']);
	
	echo '
	<div class="generic_list">

		<div class="title_bar clear_right">
			<h3 class="titlebg">
				<span class="ie6_header floatleft">ArcDev Repos R\' Us</span>
			</h3>
		</div>

		<table class="table_grid" cellspacing="0" width="100%">
			<thead>
				<tr class="catbg">
					<th scope="col" class="first_th">Repository</th>
					<th scope="col">Your Access</th>
					<th scope="col">Members</th>
					<th scope="col" class="last_th">SVN Link</th>
				</tr>
			</thead>
			<tbody>
	';
	
	$two = '';
	foreach ($repos as $repo)
	{
		echo '	<tr class="windowbg'.$two.'">
					<td class="smalltext"><a href="'.$modSettings['websvnUrl'].'listing.php?repname='.$repo['title'].'" target="_BLANK">'.$repo['title'].'</a></td>
					<td class="smalltext">Read'.(($repo['writable'])?'/Write':'').'</td>
					<td class="smalltext">'.GetSVNRepoMemberList($repo['id']).'</td>
					<td class="smalltext"><a href="'.$repo['url'].'" target="_BLANK">'.$repo['id'].'</a></td>
				</tr>
		';

		$two = (empty($two))?'2':'';
	}
		
	echo '
			</tbody>
		</table>

	</div>
	<br class="clear">
	';
}

/**
	@param $isnew if true, will format the form for creating a new SVN account. Otherwise, formats for an account update.
*/
function PrintSVNAccountUpdateFrame($isnew)
{
	global $context, $scripturl;
	
	echo '<div class="cat_bar">
		<h3 class="catbg">
			<span class="ie6_header floatleft">'.(($isnew)?'Create an':'Update your').' SVN account</span>
		</h3>
	</div>
	';
	
	if ($isnew) // TODO: better message?
		echo '<p class="windowbg description">Looks like you haven\'t set up an SVN account password yet. 
				When you set up your SVN account, you will be given access specific repositories when you 
				join particular development teams.
				</p>';
	else
		echo '<p class="windowbg description">The account information you update below will be updated for
				all repositories you have read/write access to.</p>';
	echo '
	<div class="windowbg">
		<span class="topslice"><span></span></span>
		<div class="content">
	';
	
	echo '
	<form id="creator" name="creator" action="'.$scripturl.'?action=repoman" method="post" enctype="multipart/form-data">
		<dl>
			<dt>
				<strong>Your SVN Username</strong>
			</dt>
			<dd>
				'.strtolower($context['user']['username']).'
			</dd>
		</dl>
		<hr width="100%" size="1" class="hrcolor">
		<dl>
			<dt>
				<strong>New SVN Password</strong>
				<br>
				<span class="smalltext">
					<!--This is <strong>not</strong> the same as your password to ArcDev Forums.--> Any changes to your SVN password will not affect your forum login. 
					<br><br>
					For best security, you should use eight or more characters with a combination of letters and numbers. <strong>(No symbols)</strong>
				</span>
			</dt>
			<dd>
				<input type="password" name="pass" id="pass" size="20" value="" class="input_password">
			</dd>
			<dt>
				<strong>Verify SVN Password</strong>
			</dt>
			<dd>
				<input type="password" name="pass2" id="pass2" size="20" value="" class="input_password">
			</dd>
		</dl>
		<!--hr width="100%" size="1" class="hrcolor"-->

		<div class="righttext">
			<input type="submit" value="'.(($isnew)?'Create':'Update').' Account" class="button_submit">
			<input type="hidden" name="svnacc_action" value="'.(($isnew)?'create':'update').'">
		</div>

	</form>
	';

	echo '
		</div>
		<span class="botslice"><span></span></span>
	</div>
	';
}

/**
	If the user is a forum administrator, we assume they have access to manage repositories, therefore
	gets a listing of controls. 
	
	@todo: Maybe less clusterfucked? Slapped add/del user/repo all to the same form. Definitely bad practice, 
		but since only admins see it I don't really care. This should actually be something like:
		- A button under the repo list to add. Which goes to a new php page with add options.
		- Delete button next to each repo in the list. 
		- Options in the user profile editor that accesses two pages:
			- Add to repo: lists all repositories, and checkboxes of repos we want to add them to
			- Remove from repo: lists all repositories, checkboxes of repos we want to remove them from. 
*/
function PrintSVNAdminFrame()
{
	global $context, $modSettings, $scripturl;
	
	echo '
	<br/>
	<div class="cat_bar">
		<h3 class="catbg">
			<span class="ie6_header floatleft">Play God With Repositories</span>
		</h3>
	</div>

	<div class="windowbg">
		<span class="topslice"><span></span></span>
		<div class="content">
	';
	
	// Chuck it all in one form, let them do multiple things at once for laziness' sake
	echo '
	<form id="creator" name="creator" action="'.$scripturl.'?action=repoman" method="post" enctype="multipart/form-data">
		<fieldset>
			<legend>Create new repository</legend>
			<dl>
				<dt>
					<strong>Repo ID</strong><br>
					<span class="smalltext">
						Example: awesome_project
					</span>
				</dt>
				<dd>
					<input type="text" name="add_repo" id="add_repo" size="20" value="" class="input_text">
				</dd>
			</dl>
		</fieldset>
		<!--fieldset>
			<legend>Destroy repository</legend>
			<dl>
				<dt>
					<strong>Repo ID</strong>
				</dt>
				<dd>
					<input type="text" name="del_repo" id="del_repo" size="20" value="" class="input_text">
				</dd>
			</dl>
		</fieldset-->
		<fieldset>
			<legend>Add user to repository</legend>
			<dl>
				<dt>
					<strong>SMF User ID</strong><br>
					<span class="smalltext">
						This is the SMF profile ID number of the user. <br>
						For example, <a href="'.$scripturl.'?action=profile;u=3">index.php?action=profile;u=3</a>
						would be User ID 3. 
					</span>
				</dt>
				<dd>
					<input type="text" name="add_user_id" id="add_user_id" size="20" value="" class="input_text">
				</dd>
				<dt>
					<strong>Repo ID</strong>
				</dt>
				<dd>
					<input type="text" name="add_repo_id" id="add_repo_id" size="20" value="" class="input_text">
				</dd>
			</dl>
		</fieldset>
		<fieldset>
			<legend>Remove user from repository</legend>
			<dl>
				<dt>
					<strong>SMF User ID</strong><br>
					<span class="smalltext">
						This is the SMF profile ID number of the user. <br>
						For example, <a href="'.$scripturl.'?action=profile;u=3">index.php?action=profile;u=3</a>
						would be User ID 3. 
					</span>
				</dt>
				<dd>
					<input type="text" name="del_user_id" id="del_user_id" size="20" value="" class="input_text">
				</dd>
				<dt>
					<strong>Repo ID</strong>
				</dt>
				<dd>
					<input type="text" name="del_repo_id" id="del_repo_id" size="20" value="" class="input_text">
				</dd>
			</dl>
		</fieldset>
		
		<!--hr width="100%" size="1" class="hrcolor"-->

		<div class="righttext">
			<input type="submit" value="Submit Updates" class="button_submit">
			<input type="hidden" name="svnadmin_action" value="admin_update">
		</div>

	</form>
	';

	echo '
		</div>
		<span class="botslice"><span></span></span>
	</div>
	';
}

/**
	Adds a new account to SQL and WebSVN
	@return true if they've been added successfully
*/
function AddSVNAccount($user, $smf_id, $pass)
{
	global $smcFunc, $modSettings;

	// Shouldn't be possible if they already have an account
	if (HasSVNAccount($smf_id))
	{
		return false;
	}
	
	$smcFunc['db_insert']('insert',
		'{db_prefix}svn_members',
		array( 'id_member' => 'int', 'svn_passwd' => 'string' ),
		array( $smf_id, $pass ),
		array( 'id_member' )
	);

	// If we have a repo for them to auto join when added, do so. 
	if (isset($modSettings['svnAutoJoinRepo']))
	{
		if (UpdateSVNRepoMember($modSettings['svnAutoJoinRepo'], $user, $pass, false))
			NotifyUserOfRepoAdd($smf_id, $modSettings['svnAutoJoinRepo']);
		// else ... ?
	}
	
	return true;
}

/**
	Updates user SVN Password across SQL, WebSVN, and SVN Repos
*/
function UpdateSVNAccountPassword($user, $smf_id, $pass)
{
	global $smcFunc;

	// Update in SQL 
	$smcFunc['db_query']('', '
		UPDATE {db_prefix}svn_members
		SET svn_passwd = {string:passwd}
		WHERE id_member = {int:userid}',
		array(
			'userid' => $smf_id,
			'passwd' => $pass,
		)
	);

	// Update their data across all repos
	$repos = GetSVNRepos($user);
	
	foreach ($repos as $repo)
	{
		if ($repo['writable']) // if we have an account..
		{
			UpdateSVNRepoMember($repo['id'], $user, $pass, false); // TODO: if this fails... ?
		}
	}
}

function PurgeDeadSVNAccounts()
{
	// TODO: Wipe all accounts from SQL and SVN that were not forum members, or deleted forum members, etc.
}

/*

CREATE TABLE {$db_prefix}svn_members (
	id_member mediumint(8) unsigned NOT NULL default '0',
	svn_passwd varchar(80) NOT NULL default '',
	PRIMARY KEY (id_member)

) ENGINE=MYISAM;
*/

function GetSVNAccountPassword($smf_id)
{
	global $smcFunc;
	
	$request = $smcFunc['db_query']('', '
		SELECT svn_passwd
		FROM {db_prefix}svn_members
		WHERE id_member = {int:userid}',
		array(
			'userid' => $smf_id,
		)
	);

	$pass = $smcFunc['db_fetch_row']($request);
	$smcFunc['db_free_result']($request);

	return $pass[0];
}

function HasSVNAccount($smf_id)
{
	global $smcFunc;
	
	$request = $smcFunc['db_query']('', '
		SELECT id_member
		FROM {db_prefix}svn_members
		WHERE id_member = {int:userid}',
		array(
			'userid' => $smf_id,
		)
	);
	
	$exists = $smcFunc['db_num_rows']($request) > 0;
	$smcFunc['db_free_result']($request);
	
	return $exists;
}

function NotifyUserOfRepoRemove($smf_id, $repo)
{
	// TODO: This!
}

function SendPrivateMessageAsSVNAuto($smf_id, $title, $body)
{
	sendpm(	array(
				'to' => array($smf_id),
				'bcc' => array()
			), 
			$title, 
			$body, 
			false,
			array(
				'id' => 0,
				'name' => 'Automated Message from SVN',
				'username' => 'Automated Message from SVN'
			)
		);
}

function NotifyUserOfRepoAdd($smf_id, $repo)
{
	$title = 'You have been added to the SVN repo: ' . $repo;
	
	// TODO: better message
	$body = 'You have been granted write access to the repository [b]' 
			. $repo . '[/b] within our Subversion network. Visit [b]Repo Man[/b] for more information.';

	SendPrivateMessageAsSVNAuto($smf_id, $title, $body);
}

function GenerateRandomPassword()
{
	$words = array('nerd','arcdev','awesome','coolguy','cplusplus','python','steam','polymorphism','matrix');
	return $words[mt_rand(0, count($words)-1)] . mt_rand(100, 999);
}

/**
	@return true if the account has been created, false otherwise
*/
function ForceSVNAccountCreation($user, $smf_id)
{
	// give them a random password, add it, send them a pm telling them that they should probably change it
	$pass = GenerateRandomPassword();
	
	if (AddSVNAccount($user, $smf_id, $pass))
	{
		$title = 'An SVN account has been forced upon you';

		$body = 'Login credentials for SVN have been created for you. Your username is [b]' . $user 
				. '[/b] and your password is [b]' . $pass 
				. '[/b]. We highly suggest you visit [b]Repo Man[/b] and update your account password to something you will remember.';

		SendPrivateMessageAsSVNAuto($smf_id, $title, $body);
		
		return true;
	}
	else
	{
		return false;
	}
}

function HandleAdminUpdateAction()
{
	global $_POST;
	
	// TODO: FILTER THE SHIT OUT OF THESE POSTS, GOD DAMN.
	
	if (!empty($_POST['add_repo']))
	{
		/*if (!CreateSVNRepo($_POST['add_repo']))
			PrintBadAlert('Could not create repository.');
		else
			PrintGoodAlert('Created repository: ' . $_POST['add_repo']);*/
		PrintBadAlert('Add repo not implemented');
	}
	
	if (!empty($_POST['del_repo']))
	{
		/*if (!DeleteSVNRepo($_POST['del_repo']))
			PrintBadAlert('Could not delete repository. Repository does not exist!');
		else
			PrintGoodAlert('Deleted repository: ' . $_POST['del_repo']);*/
		PrintBadAlert('Delete repo not implemented');
	}
	
	if (!empty($_POST['add_user_id']))
	{
		if (empty($_POST['add_repo_id']))
		{
			PrintBadAlert('Add user requires a Repo ID!');
		}
		else
		{
			$id = intval($_POST['add_user_id']);
			$repo = $_POST['add_repo_id'];

			$user = GetSMFUsernameFromUserID($id);
			$pass = GetSVNAccountPassword($id);
			
			if (!isset($user))
			{
				PrintBadAlert('User ID ' . $id . ' does not exist.');
			}
			else
			{
				if (!DoesSVNRepoExist($repo))
				{
					PrintBadAlert('Repo ID ' . $repo . ' does not exist.');
				}
				else
				{
					if (empty($pass))
					{
						//PrintBadAlert($user . ' has not set up an SVN account.');
						if (ForceSVNAccountCreation(strtolower($user), $id))
							PrintGoodAlert('<strong>Notice:</strong> The user has not yet created an SVN account. A temporary password has been sent to them.');
						else
							PrintBadAlert('Failed to force account creation! Contact chase@arcdev.org <strong>immediately</strong>, dear god you\'ve doomed us all!');
					}

					// TODO: Less vague
					if (!UpdateSVNRepoMember($repo, strtolower($user), $pass, false))
					{
						PrintBadAlert('A vague error occured while adding ' . $user . ' to ' . $repo);
					}
					else
					{
						PrintGoodAlert('Added ' . $user . ' to ' . $repo);
						NotifyUserOfRepoAdd($id, $repo);
					}
				}
			}
		}
	}
	
	if (!empty($_POST['del_user_id']))
	{
		/*if (empty($_POST['del_repo_id']))
		{
			PrintBadAlert('Delete user requires a Repo ID!');
		}
		else
		{
			PrintGoodAlert('Tried to del user ' . $_POST['del_user_id'] . ' from ' . $_POST['del_repo_id']);
		}*/
		PrintBadAlert('Delete user not implemented');
	}
}


function template_main() // Inclusion point through SMFs plugin system
{
	global $context, $scripturl, $boardurl, $modSettings, $settings;

	echo '<p style="text-align:right">
		<a href="' . $scripturl . '?action=pm;sa=send;u=3"><img src="', $settings['images_url'], '/icons/notify_sm.gif" title="Spam me. Spam me hard."/>See any bugs? Don\'t hesitate to tell Chase!</a>
	</p>';
	
	if (isset($_REQUEST['svnacc_action']))
	{
		// Handle some requests
		if ($_REQUEST['svnacc_action'] == 'create') // they're trying to update account
		{
			if (CheckPostedPasswords()) // create account
			{
				if (AddSVNAccount(strtolower($context['user']['username']), $context['user']['id'], $_POST['pass']))
					PrintGoodAlert('Your account has been created');
				else
					PrintBadAlert('Failed to create account! Contact chase@arcdev.org <strong>immediately</strong>, dear god you\'ve doomed us all!');
			}
		}
		elseif ($_REQUEST['svnacc_action'] == 'update') // they're trying to change passwords
		{
			if (CheckPostedPasswords()) // update account
			{
				UpdateSVNAccountPassword(strtolower($context['user']['username']), $context['user']['id'], $_POST['pass']);
				
				PrintGoodAlert('Account has been updated.');
			}
		}
	}
	elseif (isset($_REQUEST['svnadmin_action']) && $_REQUEST['svnadmin_action'] == 'admin_update' 
			&& isset($context['user']['is_admin']) && $context['user']['id'] == 3) // they're trying to make administrative changes
	{
		HandleAdminUpdateAction();
	}
	
	PrintSVNReposTable();
	PrintSVNAccountUpdateFrame( !HasSVNAccount($context['user']['id']) );

	if (isset($context['user']['is_admin']) && $context['user']['id'] == 3) // Just me, till done.
		PrintSVNAdminFrame();

	echo '<br class="clear">';
}

//ssi_shutdown();

?>