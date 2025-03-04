<?php
/* Copyright (C) 2014 Florian Henry        <florian.henry@open-concept.pro>
 * Copyright (C) 2016 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2024 MDW                  <mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *       \file      htdocs/comm/mailing/advtargetemailing.php
 *       \ingroup   mailing
 *       \brief     Page to define emailing targets. Visible when option EMAILING_USE_ADVANCED_SELECTOR is on (need MAIN_FEATURES_LEVEL to 1 to see this option)
 *					@TODO This page needs a lot of works to be stable and understandable.
 */

if (!defined('NOSTYLECHECK')) {
	define('NOSTYLECHECK', '1');
}

// Load Dolibarr environment
require '../../main.inc.php';

require_once DOL_DOCUMENT_ROOT.'/comm/mailing/class/mailing.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/emailing.lib.php';
require_once DOL_DOCUMENT_ROOT.'/comm/mailing/class/advtargetemailing.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/mailing/class/html.formadvtargetemailing.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/modules/mailings/advthirdparties.modules.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array('mails', 'companies'));
if (isModEnabled('category')) {
	$langs->load("categories");
}

// Load variable for pagination
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page == -1) {
	$page = 0;
}     // If $page is not defined, or '' or -1
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (!$sortorder) {
	$sortorder = "ASC";
}
if (!$sortfield) {
	$sortfield = "email";
}

$id = GETPOSTINT('id');
$rowid = GETPOSTINT('rowid');
$action = GETPOST('action', 'aZ09');
$search_nom = GETPOST("search_nom");
$search_prenom = GETPOST("search_prenom");
$search_email = GETPOST("search_email");
$template_id = GETPOSTINT('template_id');

// Do we click on purge search criteria ?
if (GETPOST('button_removefilter_x', 'alpha')) {
	$search_nom = '';
	$search_prenom = '';
	$search_email = '';
}
$array_query = array();
'@phan-var-force array<string,int|string|string[]> $array_query';
$object = new Mailing($db);
$advTarget = new AdvanceTargetingMailing($db);

if (empty($template_id)) {
	$advTarget->fk_element = $id;
	$advTarget->type_element = 'mailing';
	$result = $advTarget->fetch_by_mailing();
} else {
	$result = $advTarget->fetch($template_id);
}

if ($result < 0) {
	setEventMessages($advTarget->error, $advTarget->errors, 'errors');
} else {
	if (!empty($advTarget->id)) {
		$array_query = json_decode($advTarget->filtervalue, true);
	}
}

// Security check
if (!$user->hasRight('mailing', 'lire') || (!getDolGlobalString('EXTERNAL_USERS_ARE_AUTHORIZED') && $user->socid > 0)) {
	accessforbidden();
}
//$result = restrictedArea($user, 'mailing');

$permissiontoread = $user->hasRight('mailing', 'lire');
$permissiontoadd = $user->hasRight('mailing', 'creer');


/*
 * Actions
 */

if ($action == 'loadfilter' && $permissiontoread) {
	if (!empty($template_id)) {
		$result = $advTarget->fetch($template_id);
		if ($result < 0) {
			setEventMessages($advTarget->error, $advTarget->errors, 'errors');
		} else {
			if (!empty($advTarget->id)) {
				$array_query = json_decode($advTarget->filtervalue, true);
			}
		}
	}
}

if ($action == 'add' && $permissiontoadd) {
	$user_contact_query = false;

	$array_query = array();

	// Get extra fields

	foreach ($_POST as $key => $value) {
		// print '$key='.$key.' $value='.$value.'<BR>';
		if (preg_match("/^options_.*(?<!_cnct)$/", $key)) {
			// Special case for start date come with 3 inputs day, month, year
			if (preg_match("/st_dt/", $key)) {
				$dtarr = array();
				$dtarr = explode('_', $key);
				if (!array_key_exists('options_'.$dtarr[1].'_st_dt', $array_query)) {
					$array_query['options_'.$dtarr[1].'_st_dt'] = dol_mktime(0, 0, 0, GETPOSTINT('options_'.$dtarr[1].'_st_dtmonth'), GETPOSTINT('options_'.$dtarr[1].'_st_dtday'), GETPOSTINT('options_'.$dtarr[1].'_st_dtyear'));
				}
			} elseif (preg_match("/end_dt/", $key)) {
				// Special case for end date come with 3 inputs day, month, year
				$dtarr = array();
				$dtarr = explode('_', $key);
				if (!array_key_exists('options_'.$dtarr[1].'_end_dt', $array_query)) {
					$array_query['options_'.$dtarr[1].'_end_dt'] = dol_mktime(0, 0, 0, GETPOSTINT('options_'.$dtarr[1].'_end_dtmonth'), GETPOSTINT('options_'.$dtarr[1].'_end_dtday'), GETPOSTINT('options_'.$dtarr[1].'_end_dtyear'));
				}
			} else {
				$array_query[$key] = GETPOST($key);
			}
		}
		if (preg_match("/^options_.*_cnct/", $key)) {
			$user_contact_query = true;
			// Special case for start date come with 3 inputs day, month, year
			if (preg_match("/st_dt/", $key)) {
				$dtarr = array();
				$dtarr = explode('_', $key);
				if (!array_key_exists('options_'.$dtarr[1].'_st_dt_cnct', $array_query)) {
					$array_query['options_'.$dtarr[1].'_st_dt_cnct'] = dol_mktime(0, 0, 0, GETPOSTINT('options_'.$dtarr[1].'_st_dtmonth_cnct'), GETPOSTINT('options_'.$dtarr[1].'_st_dtday_cnct'), GETPOSTINT('options_'.$dtarr[1].'_st_dtyear_cnct'));
				}
			} elseif (preg_match("/end_dt/", $key)) {
				// Special case for end date come with 3 inputs day, month, year
				$dtarr = array();
				$dtarr = explode('_', $key);
				if (!array_key_exists('options_'.$dtarr[1].'_end_dt_cnct', $array_query)) {
					$array_query['options_'.$dtarr[1].'_end_dt_cnct'] = dol_mktime(0, 0, 0, GETPOSTINT('options_'.$dtarr[1].'_end_dtmonth_cnct'), GETPOSTINT('options_'.$dtarr[1].'_end_dtday_cnct'), GETPOSTINT('options_'.$dtarr[1].'_end_dtyear_cnct'));
				}
			} else {
				$array_query[$key] = GETPOST($key);
			}
		}

		if (preg_match("/^cust_/", $key)) {
			$array_query[$key] = GETPOST($key);
		}

		if (preg_match("/^contact_/", $key)) {
			$array_query[$key] = GETPOST($key);

			$specials_date_key = array(
					'contact_update_st_dt',
					'contact_update_end_dt',
					'contact_create_st_dt',
					'contact_create_end_dt'
			);
			foreach ($specials_date_key as $date_key) {
				if ($key == $date_key) {
					$dt = GETPOST($date_key);
					if (!empty($dt)) {
						$array_query[$key] = dol_mktime(0, 0, 0, GETPOSTINT($date_key.'month'), GETPOSTINT($date_key.'day'), GETPOSTINT($date_key.'year'));
					} else {
						$array_query[$key] = '';
					}
				}
			}

			if (!empty($array_query[$key])) {
				$user_contact_query = true;
			}
		}

		if ($array_query['type_of_target'] == 2 || $array_query['type_of_target'] == 4) {
			$user_contact_query = true;
		}

		if (preg_match("/^type_of_target/", $key)) {
			$array_query[$key] = GETPOST($key);
		}
	}

	// if ($array_query ['type_of_target'] == 1 || $array_query ['type_of_target'] == 3) {
	$result = $advTarget->query_thirdparty($array_query);
	if ($result < 0) {
		setEventMessages($advTarget->error, $advTarget->errors, 'errors');
	}
	/*} else {
		$advTarget->thirdparty_lines = array ();
	}*/

	if ($user_contact_query && ($array_query['type_of_target'] == 1 || $array_query['type_of_target'] == 2 || $array_query['type_of_target'] == 4)) {
		$result = $advTarget->query_contact($array_query, 1);
		if ($result < 0) {
			setEventMessages($advTarget->error, $advTarget->errors, 'errors');
		}
		// If use contact but no result use artefact to so not use socid into add_to_target
		if (count($advTarget->contact_lines) == 0) {
			$advTarget->contact_lines = array(
					0
			);
		}
	} else {
		$advTarget->contact_lines = array();
	}

	$mailingadvthirdparties = null;
	if ((count($advTarget->thirdparty_lines) > 0) || (count($advTarget->contact_lines) > 0)) {
		// Add targets into database
		$mailingadvthirdparties = new mailing_advthirdparties($db);
		$result = $mailingadvthirdparties->add_to_target_spec($id, $advTarget->thirdparty_lines, $array_query['type_of_target'], $advTarget->contact_lines);
	} else {
		$result = 0;
	}

	if ($result > 0) {
		$query_temlate_id = '';
		if (!empty($template_id)) {
			$query_temlate_id = '&template_id='.$template_id;
		}
		setEventMessages($langs->trans("XTargetsAdded", $result), null, 'mesgs');
		header("Location: ".$_SERVER['PHP_SELF']."?id=".$id.$query_temlate_id);
		exit();
	}
	if ($result == 0) {
		setEventMessages($langs->trans("WarningNoEMailsAdded"), null, 'warnings');
	}
	if ($result < 0 && is_object($mailingadvthirdparties)) {
		setEventMessages($mailingadvthirdparties->error, $mailingadvthirdparties->errors, 'errors');
	}
}

if ($action == 'clear' && $permissiontoadd) {
	$mailingtargets = new MailingTargets($db);
	$mailingtargets->clear_target($id);

	header("Location: ".$_SERVER['PHP_SELF']."?id=".$id);
	exit();
}

if (($action == 'savefilter' || $action == 'createfilter') && $permissiontoadd) {
	$template_name = GETPOST('template_name');
	$error = 0;

	if ($action == 'createfilter' && empty($template_name) && $permissiontoadd) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('AdvTgtOrCreateNewFilter')), null, 'errors');
		$error++;
	}

	if (empty($error)) {
		$array_query = array();

		// Get extra fields
		foreach ($_POST as $key => $value) {
			if (preg_match("/^options_.*(?<!_cnct)$/", $key)) {
				// Special case for start date come with 3 inputs day, month, year
				if (preg_match("/st_dt/", $key)) {
					$dtarr = array();
					$dtarr = explode('_', $key);
					if (!array_key_exists('options_'.$dtarr[1].'_st_dt', $array_query)) {
						$array_query['options_'.$dtarr[1].'_st_dt'] = dol_mktime(0, 0, 0, GETPOSTINT('options_'.$dtarr[1].'_st_dtmonth'), GETPOSTINT('options_'.$dtarr[1].'_st_dtday'), GETPOSTINT('options_'.$dtarr[1].'_st_dtyear'));
					}
				} elseif (preg_match("/end_dt/", $key)) {
					// Special case for end date come with 3 inputs day, month, year
					$dtarr = array();
					$dtarr = explode('_', $key);
					if (!array_key_exists('options_'.$dtarr[1].'_end_dt', $array_query)) {
						$array_query['options_'.$dtarr[1].'_end_dt'] = dol_mktime(0, 0, 0, GETPOSTINT('options_'.$dtarr[1].'_end_dtmonth'), GETPOSTINT('options_'.$dtarr[1].'_end_dtday'), GETPOSTINT('options_'.$dtarr[1].'_end_dtyear'));
						// print $array_query['options_'.$dtarr[1].'_end_dt'];
						// 01/02/1013=1361228400
					}
				} else {
					$array_query[$key] = GETPOST($key);
				}
			}
			if (preg_match("/^options_.*_cnct/", $key)) {
				// Special case for start date come with 3 inputs day, month, year
				if (preg_match("/st_dt/", $key)) {
					$dtarr = array();
					$dtarr = explode('_', $key);
					if (!array_key_exists('options_'.$dtarr[1].'_st_dt_cnct', $array_query)) {
						$array_query['options_'.$dtarr[1].'_st_dt_cnct'] = dol_mktime(0, 0, 0, GETPOSTINT('options_'.$dtarr[1].'_st_dtmonth_cnct'), GETPOSTINT('options_'.$dtarr[1].'_st_dtday_cnct'), GETPOSTINT('options_'.$dtarr[1].'_st_dtyear_cnct'));
					}
				} elseif (preg_match("/end_dt/", $key)) {
					// Special case for end date come with 3 inputs day, month, year
					$dtarr = array();
					$dtarr = explode('_', $key);
					if (!array_key_exists('options_'.$dtarr[1].'_end_dt_cnct', $array_query)) {
						$array_query['options_'.$dtarr[1].'_end_dt_cnct'] = dol_mktime(0, 0, 0, GETPOSTINT('options_'.$dtarr[1].'_end_dtmonth_cnct'), GETPOSTINT('options_'.$dtarr[1].'_end_dtday_cnct'), GETPOSTINT('options_'.$dtarr[1].'_end_dtyear_cnct'));
						// print $array_query['cnct_options_'.$dtarr[1].'_end_dt'];
						// 01/02/1013=1361228400
					}
				} else {
					$array_query[$key] = GETPOST($key);
				}
			}

			if (preg_match("/^cust_/", $key)) {
				$array_query[$key] = GETPOST($key);
			}

			if (preg_match("/^contact_/", $key)) {
				$array_query[$key] = GETPOST($key);

				$specials_date_key = array(
						'contact_update_st_dt',
						'contact_update_end_dt',
						'contact_create_st_dt',
						'contact_create_end_dt'
				);
				foreach ($specials_date_key as $date_key) {
					if ($key == $date_key) {
						$dt = GETPOST($date_key);
						if (!empty($dt)) {
							$array_query[$key] = dol_mktime(0, 0, 0, GETPOSTINT($date_key.'month'), GETPOSTINT($date_key.'day'), GETPOSTINT($date_key.'year'));
						} else {
							$array_query[$key] = '';
						}
					}
				}
			}

			if (preg_match("/^type_of_target/", $key)) {
				$array_query[$key] = GETPOST($key);
			}
		}
		$advTarget->filtervalue = json_encode($array_query);

		if ($action == 'createfilter') {		// Test on permission already done
			$advTarget->name = $template_name;
			$result = $advTarget->create($user);
			if ($result < 0) {
				setEventMessages($advTarget->error, $advTarget->errors, 'errors');
			}
		} elseif ($action == 'savefilter') {	// Test on permission already done
			$result = $advTarget->update($user);
			if ($result < 0) {
				setEventMessages($advTarget->error, $advTarget->errors, 'errors');
			}
		}
		$template_id = $advTarget->id;
	}
}

if ($action == 'deletefilter' && $permissiontoadd) {
	$result = $advTarget->delete($user);
	if ($result < 0) {
		setEventMessages($advTarget->error, $advTarget->errors, 'errors');
	}
	header("Location: ".$_SERVER['PHP_SELF']."?id=".$id);
	exit();
}

if ($action == 'delete' && $permissiontoadd) {
	// Ici, rowid indique le destinataire et id le mailing
	$sql = "DELETE FROM ".MAIN_DB_PREFIX."mailing_cibles WHERE rowid = ".((int) $rowid);
	$resql = $db->query($sql);
	if ($resql) {
		if (!empty($id)) {
			$mailingtargets = new MailingTargets($db);
			$mailingtargets->update_nb($id);

			header("Location: ".$_SERVER['PHP_SELF']."?id=".$id);
			exit();
		} else {
			header("Location: liste.php");
			exit();
		}
	} else {
		dol_print_error($db);
	}
}

if (GETPOST("button_removefilter")) {
	$search_nom = '';
	$search_prenom = '';
	$search_email = '';
}


/*
 * View
 */

llxHeader('', $langs->trans("MailAdvTargetRecipients"));

$form = new Form($db);
$formadvtargetemaling = new FormAdvTargetEmailing($db);
$formcompany = new FormCompany($db);
$formother = new FormOther($db);

if ($object->fetch($id) >= 0) {
	$head = emailing_prepare_head($object);

	print dol_get_fiche_head($head, 'advtargets', $langs->trans("Mailing"), 0, 'email');

	print '<table class="border centpercent">';

	$linkback = '<a href="'.DOL_URL_ROOT.'/comm/mailing/liste.php">'.$langs->trans("BackToList").'</a>';

	print '<tr><td>'.$langs->trans("Ref").'</td>';
	print '<td colspan="3">';
	print $form->showrefnav($object, 'id', $linkback);
	print '</td></tr>';

	print '<tr><td>'.$langs->trans("MailTitle").'</td><td colspan="3">'.$object->title.'</td></tr>';

	print '<tr><td>'.$langs->trans("MailFrom").'</td><td colspan="3">'.dol_print_email($object->email_from, 0, 0, 0, 0, 1).'</td></tr>';

	// Errors to
	print '<tr><td>'.$langs->trans("MailErrorsTo").'</td><td colspan="3">'.dol_print_email($object->email_errorsto, 0, 0, 0, 0, 1);
	print '</td></tr>';

	// Status
	print '<tr><td>'.$langs->trans("Status").'</td><td colspan="3">'.$object->getLibStatut(4).'</td></tr>';

	// Nb of distinct emails
	print '<tr><td>';
	print $langs->trans("TotalNbOfDistinctRecipients");
	print '</td><td colspan="3">';
	$nbemail = ($object->nbemail ? $object->nbemail : '0');
	if (getDolGlobalString('MAILING_LIMIT_SENDBYWEB') && $conf->global->MAILING_LIMIT_SENDBYWEB < $nbemail) {
		$text = $langs->trans('LimitSendingEmailing', getDolGlobalString('MAILING_LIMIT_SENDBYWEB'));
		// @phan-suppress-next-line PhanPluginSuspiciousParamPosition
		print $form->textwithpicto($nbemail, $text, 1, 'warning');
	} else {
		print $nbemail;
	}
	print '</td></tr>';

	print '</table>';

	print "</div>";

	// Show email selectors
	if ($object->status == 0 && $user->hasRight('mailing', 'creer')) {
		// @phan-assert FormAdvTargetEmailing $formadvtargetemaling
		// @phan-assert AdvanceTargetingMailing $advTarget

		// @phan-assert array<string,int|string|string[] $array_query'
		include DOL_DOCUMENT_ROOT.'/core/tpl/advtarget.tpl.php';
	}
}

// End of page
llxFooter();
$db->close();
